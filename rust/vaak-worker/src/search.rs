//! Read-only indexed Search projection.
//!
//! This intentionally stops before Mastodon account/status serialization.  It
//! mirrors PHP's ap_search_fts query construction and ordering, applies the
//! viewer's current block/mute sets to event/mention candidates, and leaves
//! account resolution and remote URL redirects on PHP until those fixtures
//! have exact parity.

use anyhow::{Context, Result};
use serde::Serialize;
use serde_json::{json, Value};
use tokio_postgres::Client;

use crate::hidden::HiddenSets;

#[derive(Debug, Serialize)]
pub struct SearchRow {
    pub source: String,
    pub source_pk: i64,
    pub object_id: Option<String>,
    pub created_at: String,
    pub rank: f64,
    pub status: Option<Value>,
}

#[derive(Debug, Serialize)]
pub struct SearchResults {
    pub owner_id: i64,
    pub actor_key: String,
    pub query: String,
    pub query_type: String,
    pub normalized_query: Option<String>,
    pub rows: Vec<SearchRow>,
    pub statuses: Vec<Value>,
    pub accounts: Vec<SearchAccount>,
    pub hashtags: Vec<SearchHashtag>,
    pub redirect_url: Option<String>,
    pub privacy_filtered: bool,
    pub fallback: &'static str,
    pub source: &'static str,
    pub mutation_enabled: bool,
}

#[derive(Debug, Serialize)]
pub struct SearchAccount {
    pub actor_id: String,
    pub username: String,
    pub display_name: String,
    pub host: String,
    pub source: String,
}

#[derive(Debug, Serialize)]
pub struct SearchHashtag {
    pub name: String,
    pub uses: i64,
    pub following: bool,
}

/// Exact PHP ap_search_fts_match_query behavior for text queries.
pub fn text_match_query(q: &str) -> Option<String> {
    let q = q.trim();
    if q.chars().count() < 2 {
        return None;
    }
    let stripped: String = q
        .chars()
        .map(|c| match c {
            '"' | '\'' | '*' | '(' | ')' | ':' | '^' => ' ',
            _ => c,
        })
        .collect();
    let mut tokens = Vec::new();
    for part in stripped.split_whitespace() {
        let token: String = part
            .chars()
            .filter(|c| c.is_alphanumeric() || *c == '_')
            .flat_map(char::to_lowercase)
            .collect();
        if token.chars().count() < 2 || token.is_empty() {
            continue;
        }
        tokens.push(format!("{token}*"));
        if tokens.len() >= 8 {
            break;
        }
    }
    (!tokens.is_empty()).then(|| tokens.join(" AND "))
}

/// Exact PHP tag query behavior (`htag_NAME OR NAME`).
pub fn tag_match_query(tag: &str) -> Option<String> {
    let token: String = tag
        .chars()
        .filter(|c| c.is_alphanumeric() || *c == '_')
        .flat_map(char::to_lowercase)
        .collect();
    (!token.is_empty()).then(|| format!("htag_{token} OR {token}"))
}

fn postgres_tsquery(match_query: &str) -> String {
    match_query
        .split_whitespace()
        .map(|part| part.strip_suffix('*').map(|p| format!("{p}:*")).unwrap_or_else(|| part.to_string()))
        .collect::<Vec<_>>()
        .join(" ")
        .replace(" AND ", " & ")
        .replace(" OR ", " | ")
}

fn html_text(text: &str) -> String {
    let mut out = String::from("<p>");
    for c in text.trim().chars() {
        match c { '&' => out.push_str("&amp;"), '<' => out.push_str("&lt;"), '>' => out.push_str("&gt;"), '"' => out.push_str("&quot;"), '\'' => out.push_str("&#39;"), '\n' => out.push_str("<br>"), _ => out.push(c) }
    }
    if out == "<p>" { String::new() } else { out.push_str("</p>"); out }
}

fn status_id(created: &str, db_id: i64, kind: i64) -> String {
    let sec = chrono::DateTime::parse_from_rfc3339(created).map(|d| d.timestamp()).unwrap_or(0);
    format!("{}", sec.saturating_mul(1_000_000_000) + kind * 100_000_000 + db_id.rem_euclid(100_000_000))
}

fn account_json(actor: &str, username: &str, display: &str, host: &str, avatar: Option<&str>) -> Value {
    let av = avatar.filter(|v| v.starts_with("https://")).unwrap_or("https://mkultra.monster/img/avatar/default.webp");
    let acct = if host.is_empty() { username.to_string() } else { format!("{username}@{host}") };
    json!({"id": actor, "username": username, "acct": acct, "display_name": if display.is_empty() { username } else { display },
        "locked": false, "bot": false, "discoverable": false, "group": false,
        "created_at": "2018-01-01T00:00:00.000Z", "note": "", "url": actor, "uri": actor,
        "avatar": av, "avatar_static": av, "header": "", "header_static": "",
        "followers_count": 0, "following_count": 0, "statuses_count": 0, "last_status_at": null,
        "emojis": [], "fields": []})
}

fn status_json(id: String, created: &str, uri: &str, content: &str, account: Value) -> Value {
    json!({"id": id, "created_at": created, "in_reply_to_id": null, "in_reply_to_account_id": null,
        "sensitive": false, "spoiler_text": "", "visibility": "public", "language": null,
        "uri": uri, "url": uri, "replies_count": 0, "reblogs_count": 0, "favourites_count": 0,
        "edited_at": null, "favourited": false, "reblogged": false, "muted": false,
        "bookmarked": false, "pinned": false, "content": html_text(content), "reblog": null,
        "application": null, "account": account, "media_attachments": [], "mentions": [], "tags": [],
        "emojis": [], "card": null, "poll": null, "quote": null})
}

fn bsky_media(embed: &Value, status_id: &str) -> Vec<Value> {
    let mut out = Vec::new();
    let images = embed.get("images").and_then(Value::as_array)
        .or_else(|| embed.get("media").and_then(|v| v.get("images")).and_then(Value::as_array));
    if let Some(images) = images {
        for (i, image) in images.iter().take(4).enumerate() {
            let url = image.get("fullsize").and_then(Value::as_str)
                .or_else(|| image.get("thumb").and_then(Value::as_str)).unwrap_or("");
            if !url.starts_with("https://") { continue; }
            out.push(json!({"id": format!("{status_id}-{i}"), "type": "image", "url": url,
                "preview_url": image.get("thumb").and_then(Value::as_str).unwrap_or(url),
                "remote_url": url, "description": image.get("alt").and_then(Value::as_str).unwrap_or(""),
                "meta": null}));
        }
    }
    out
}

async fn hydrated_account(db: &Client, actor: &str, username: &str, host: &str) -> Value {
    if let Ok(Some(row)) = db.query_opt(
        "SELECT COALESCE(username,''), COALESCE(display_name,''), COALESCE(icon_source_url,'')
         FROM remote_actors WHERE actor_id = $1 LIMIT 1", &[&actor]
    ).await {
        let cached_user: String = row.get(0);
        let display: String = row.get(1);
        let avatar: String = row.get(2);
        return account_json(actor, if cached_user.is_empty() { username } else { &cached_user }, &display, host, Some(&avatar));
    }
    account_json(actor, username, username, host, None)
}

async fn serialize_candidate(db: &Client, source: &str, source_pk: i64, object_id: Option<&str>, created: &str) -> Option<Value> {
    match source {
        "event" => {
            let r = db.query_opt("SELECT actor_id, COALESCE(summary,''), COALESCE(object_id,''), COALESCE(host,'') FROM events WHERE id = $1", &[&source_pk]).await.ok()??;
            let actor: String = r.get(0); let text: String = r.get(1); let uri: String = r.get(2);
            let host: String = r.get(3);
            let username = actor.rsplit('/').next().unwrap_or("unknown");
            let account = hydrated_account(db, &actor, username, &host).await;
            Some(status_json(status_id(created, source_pk, 1), created, if uri.is_empty() { actor.as_str() } else { uri.as_str() }, &text, account))
        }
        "mention" => {
            let r = db.query_opt("SELECT actor_id, COALESCE(content,''), COALESCE(object_id,'') FROM mentions WHERE id = $1 AND deleted_at IS NULL", &[&source_pk]).await.ok()??;
            let actor: String = r.get(0); let text: String = r.get(1); let uri: String = r.get(2);
            let username = actor.rsplit('/').next().unwrap_or("unknown");
            let account = hydrated_account(db, &actor, username, "").await;
            Some(status_json(status_id(created, source_pk, 2), created, if uri.is_empty() { actor.as_str() } else { uri.as_str() }, &text, account))
        }
        "status" => {
            let r = db.query_opt("SELECT note_id, COALESCE(content_text,''), COALESCE(published,'') FROM masto_statuses WHERE local_id = $1", &[&source_pk]).await.ok()??;
            let uri: String = r.get(0); let text: String = r.get(1); let published: String = r.get(2);
            let actor = uri.split("/notes/").next().unwrap_or("https://mkultra.monster/users/cmdr_nova").to_string();
            let username = actor.rsplit('/').next().unwrap_or("cmdr_nova");
            let account = hydrated_account(db, &actor, username, "mkultra.monster").await;
            Some(status_json(status_id(&published, source_pk, 0), &published, &uri, &text, account))
        }
        "bsky_post" => {
            let uri = object_id.unwrap_or("");
            let r = db.query_opt("SELECT COALESCE(raw_json,''), COALESCE(author_did,''), COALESCE(author_handle,''), COALESCE(author_display,''), COALESCE(embed_json,''), COALESCE(reply_parent,'') FROM bsky_posts WHERE bsky_uri = $1", &[&uri]).await.ok()??;
            let raw: String = r.get(0); let did: String = r.get(1); let handle: String = r.get(2); let display: String = r.get(3); let embed: String = r.get(4); let reply_parent: String = r.get(5);
            let post = serde_json::from_str::<Value>(&raw).ok().and_then(|v| v.get("post").cloned().or(Some(v))).unwrap_or(Value::Null);
            let text = post.get("record").and_then(|v| v.get("text")).and_then(Value::as_str).or_else(|| post.get("text").and_then(Value::as_str)).unwrap_or("");
            let user = if handle.is_empty() { did.as_str() } else { handle.as_str() };
            let actor = format!("https://bsky.app/profile/{user}");
            let mut status = status_json(uri.to_string(), created, uri, text, account_json(&actor, user, &display, "bsky.app", None));
            if let Some(obj) = status.as_object_mut() {
                if !reply_parent.is_empty() {
                    obj.insert("in_reply_to_id".into(), json!(reply_parent));
                    obj.insert("vaak_in_reply_to_url".into(), json!(reply_parent));
                }
                if let Ok(embed_value) = serde_json::from_str::<Value>(&embed) {
                    let media = bsky_media(&embed_value, uri);
                    if !media.is_empty() { obj.insert("media_attachments".into(), Value::Array(media)); }
                    if let Some(external) = embed_value.get("external") {
                        if let Some(url) = external.get("uri").and_then(Value::as_str) {
                            obj.insert("card".into(), json!({"url": url, "title": external.get("title").and_then(Value::as_str).unwrap_or("Bluesky link"), "description": external.get("description").and_then(Value::as_str).unwrap_or(""), "type": "link", "provider_name": "Bluesky"}));
                        }
                    }
                }
            }
            Some(status)
        }
        _ => None,
    }
}

async fn candidate_actor(db: &Client, source: &str, source_pk: i64) -> Result<(String, String)> {
    match source {
        "event" => {
            let row = db.query_opt(
                "SELECT COALESCE(actor_id,''), COALESCE(target_actor,'') FROM events WHERE id = $1",
                &[&source_pk],
            ).await?;
            Ok(row.map(|r| (r.get(0), r.get(1))).unwrap_or_default())
        }
        "mention" => {
            let row = db.query_opt(
                "SELECT COALESCE(actor_id,'') FROM mentions WHERE id = $1 AND deleted_at IS NULL",
                &[&source_pk],
            ).await?;
            Ok(row.map(|r| (r.get(0), String::new())).unwrap_or_default())
        }
        // Local status rows are hydrated by the PHP serializer and have no
        // independent remote actor to apply here.
        _ => Ok((String::new(), String::new())),
    }
}

async fn bsky_rows(db: &Client, hidden: &HiddenSets, query: &str, limit: i64) -> Result<Vec<SearchRow>> {
    let terms: Vec<String> = query
        .split_whitespace()
        .map(|s| s.trim_matches(|c: char| !c.is_alphanumeric() && c != '_').to_ascii_lowercase())
        .filter(|s| s.len() >= 2)
        .take(8)
        .collect();
    if terms.is_empty() {
        return Ok(Vec::new());
    }
    // The durable Bluesky cache is shared by connected accounts. Pull a
    // bounded recent candidate set, then apply PHP's AND-token semantics in
    // Rust so one user's connected account cannot expand another's results.
    let rows = db.query(
        "SELECT bsky_uri, COALESCE(published_at, indexed_at, updated_at),
                COALESCE(text,''), COALESCE(author_did,''), COALESCE(author_handle,'')
         FROM bsky_posts
         WHERE lower(COALESCE(text,'')) LIKE $1
         ORDER BY COALESCE(published_at, indexed_at, updated_at) DESC
         LIMIT $2",
        &[&format!("%{}%", terms[0]), &(limit * 8).min(400)],
    ).await?;
    let mut out = Vec::new();
    for row in rows {
        let text: String = row.get(2);
        let lower = text.to_ascii_lowercase();
        if !terms.iter().all(|term| lower.contains(term)) {
            continue;
        }
        let uri: String = row.get(0);
        let author_did: String = row.get(3);
        let author_handle: String = row.get(4);
        let author_ref = if author_handle.is_empty() { author_did.clone() } else {
            format!("https://bsky.app/profile/{author_handle}")
        };
        if hidden.is_hidden(&author_ref) || hidden.is_hidden(&author_did) { continue; }
        let created_at: String = row.get(1);
        let rank = terms.iter().map(|term| lower.matches(term).count() as f64).sum();
        out.push(SearchRow {
            source: "bsky_post".into(), source_pk: 0, object_id: Some(uri),
            created_at, rank, status: None,
        });
        if out.len() >= limit as usize { break; }
    }
    Ok(out)
}

async fn account_rows(db: &Client, hidden: &HiddenSets, query: &str, limit: i64) -> Result<Vec<SearchAccount>> {
    let needle = query.trim().trim_start_matches('@').to_ascii_lowercase();
    if needle.is_empty() { return Ok(Vec::new()); }
    let like = format!("%{}%", needle.replace('%', "\\%").replace('_', "\\_"));
    let mut out = Vec::new();
    let mut seen = std::collections::HashSet::new();
    let local = db.query(
        "SELECT actor_id, username, username FROM ap_users
         WHERE disabled_at IS NULL AND (lower(username) LIKE $1 OR lower(actor_key) LIKE $1)
         ORDER BY username LIMIT $2", &[&like, &limit],
    ).await?;
    for row in local {
        let actor: String = row.get(0);
        if hidden.is_hidden(&actor) || !seen.insert(actor.clone()) { continue; }
        let username: String = row.get(1);
        out.push(SearchAccount { actor_id: actor, username: username.clone(), display_name: row.get(2), host: "mkultra.monster".into(), source: "fediverse".into() });
    }
    if out.len() < limit as usize {
        let remote = db.query(
            "SELECT actor_id, COALESCE(username,''), COALESCE(display_name,''), COALESCE(host,'')
             FROM remote_actors
             WHERE lower(COALESCE(username,'')) LIKE $1 OR lower(COALESCE(display_name,'')) LIKE $1
                OR lower(COALESCE(host,'')) LIKE $1 OR lower(actor_id) LIKE $1
             ORDER BY updated_at DESC LIMIT $2", &[&like, &(limit * 3).min(120)],
        ).await?;
        for row in remote {
            let actor: String = row.get(0);
            if hidden.is_hidden(&actor) || !seen.insert(actor.clone()) { continue; }
            let username: String = row.get(1);
            let display: String = row.get(2);
            let host: String = row.get(3);
            out.push(SearchAccount { actor_id: actor, username: username.clone(), display_name: if display.is_empty() { username } else { display }, host, source: "fediverse".into() });
            if out.len() >= limit as usize { break; }
        }
    }
    if out.len() < limit as usize {
        let bsky = db.query(
            "SELECT DISTINCT author_did, COALESCE(author_handle,''), COALESCE(author_display,'')
             FROM bsky_posts
             WHERE lower(COALESCE(author_handle,'')) LIKE $1 OR lower(COALESCE(author_display,'')) LIKE $1
             OR lower(author_did) LIKE $1 ORDER BY 2 LIMIT $2",
            &[&like, &(limit * 2).min(80)],
        ).await?;
        for row in bsky {
            let did: String = row.get(0);
            let handle: String = row.get(1);
            let display: String = row.get(2);
            let key = if handle.is_empty() { did.clone() } else { handle.clone() };
            let actor = format!("https://bsky.app/profile/{}", key);
            if hidden.is_hidden(&actor) || !seen.insert(actor.clone()) { continue; }
            out.push(SearchAccount { actor_id: actor, username: key.clone(), display_name: if display.is_empty() { key } else { display }, host: "bsky.app".into(), source: "bluesky".into() });
            if out.len() >= limit as usize { break; }
        }
    }
    Ok(out)
}

async fn hashtag_rows(db: &Client, owner_id: i64, query: &str, limit: i64) -> Result<Vec<SearchHashtag>> {
    let needle: String = query.trim().trim_start_matches('#').chars()
        .filter(|c| c.is_alphanumeric() || *c == '_').flat_map(char::to_lowercase).collect();
    if needle.is_empty() { return Ok(Vec::new()); }
    let like = format!("%#{}%", needle);
    let count = db.query_one(
        "SELECT COUNT(*) FROM ap_search_docs WHERE lower(body) LIKE $1", &[&like],
    ).await?.get::<_, i64>(0);
    let following = db.query_opt(
        "SELECT 1 FROM masto_followed_tags WHERE owner_user_id = $1 AND lower(name) = $2",
        &[&owner_id, &needle],
    ).await?.is_some();
    Ok(vec![SearchHashtag { name: needle, uses: count, following }].into_iter().take(limit as usize).collect())
}

pub async fn project(
    cfg: &crate::config::Config,
    owner_id: i64,
    query: &str,
    query_type: Option<&str>,
    tag: Option<&str>,
    limit: i64,
) -> Result<SearchResults> {
    if owner_id < 1 {
        anyhow::bail!("owner_id must be positive");
    }
    let db = crate::db::connect(&cfg.database_url).await.context("connect search")?;
    let owner = db.query_opt(
        "SELECT actor_key FROM ap_users WHERE id = $1 AND disabled_at IS NULL",
        &[&owner_id],
    ).await.context("load search owner")?;
    let Some(owner) = owner else { anyhow::bail!("search owner not found") };
    let actor_key: String = owner.get(0);
    let kind = query_type.unwrap_or("text").trim().to_ascii_lowercase();
    let limit = limit.clamp(1, 80);
    if !matches!(kind.as_str(), "text" | "hashtags" | "accounts" | "remote_url") {
        anyhow::bail!("unsupported search type")
    }
    if kind == "remote_url" || query.trim_start().starts_with("http://") || query.trim_start().starts_with("https://") {
        return Ok(SearchResults {
            owner_id, actor_key, query: query.to_string(), query_type: kind,
            normalized_query: None, rows: Vec::new(), statuses: Vec::new(), privacy_filtered: false,
            accounts: Vec::new(), hashtags: Vec::new(), redirect_url: Some(query.trim().to_string()),
            fallback: "php", source: "vaak-worker-shadow", mutation_enabled: false,
        });
    }
    // Search is fail-closed: a moderation-table/cache failure must not expose
    // a candidate that should have been hidden.
    let hidden = crate::hidden::load_hidden_sets(&db, owner_id).await.context("load search moderation")?;
    if kind == "accounts" {
        let accounts = account_rows(&db, &hidden, query, limit).await?;
        return Ok(SearchResults { owner_id, actor_key, query: query.to_string(), query_type: kind,
            normalized_query: None, rows: Vec::new(), statuses: Vec::new(), accounts, hashtags: Vec::new(), redirect_url: None,
            privacy_filtered: true, fallback: "php", source: "vaak-worker-shadow", mutation_enabled: false });
    }
    let match_query = if kind == "hashtags" {
        tag_match_query(tag.filter(|v| !v.trim().is_empty()).unwrap_or(query))
    } else {
        text_match_query(query)
    };
    let Some(match_query) = match_query else {
        return Ok(SearchResults { owner_id, actor_key, query: query.to_string(), query_type: kind,
            normalized_query: None, rows: Vec::new(), statuses: Vec::new(), accounts: Vec::new(), hashtags: Vec::new(), redirect_url: None, privacy_filtered: true,
            fallback: "php", source: "vaak-worker-shadow", mutation_enabled: false });
    };
    let tsquery = postgres_tsquery(&match_query);
    let candidates = db.query(
        "SELECT source, source_pk, object_id, created_at,
                ts_rank_cd(body_tsv, to_tsquery('simple', $1))::double precision AS rank
         FROM ap_search_docs
         WHERE body_tsv @@ to_tsquery('simple', $1)
           AND NOT EXISTS (SELECT 1 FROM ap_users u JOIN actor_profile p ON p.actor_key=u.actor_key
             WHERE COALESCE(p.indexable,1)=0 AND u.id<>$3
               AND (substr(ap_search_docs.object_id,1,length(rtrim(u.actor_id,'/') || '/notes/'))=rtrim(u.actor_id,'/') || '/notes/'
                 OR (ap_search_docs.source='event' AND EXISTS (SELECT 1 FROM events e WHERE e.id=ap_search_docs.source_pk AND rtrim(e.actor_id,'/')=rtrim(u.actor_id,'/')))))
         ORDER BY rank DESC, created_at DESC
         LIMIT $2",
        &[&tsquery, &(limit * 3).min(240), &owner_id],
    ).await.context("query search FTS")?;
    let mut rows = Vec::with_capacity(candidates.len());
    for row in candidates {
        let source: String = row.get(0);
        let source_pk: i64 = row.get(1);
        let (actor, target) = match candidate_actor(&db, &source, source_pk).await {
            Ok(v) => v,
            Err(_) => continue,
        };
        if hidden.is_hidden(&actor) || (source == "event" && !target.is_empty() && hidden.is_hidden(&target)) {
            continue;
        }
        rows.push(SearchRow {
            source, source_pk, object_id: row.get(2), created_at: row.get(3), rank: row.get(4),
            status: None,
        });
    }
    rows.truncate(limit as usize);
    if kind == "text" {
        rows.extend(bsky_rows(&db, &hidden, query, limit).await.unwrap_or_default());
        rows.sort_by(|a, b| b.rank.partial_cmp(&a.rank).unwrap_or(std::cmp::Ordering::Equal).then_with(|| b.created_at.cmp(&a.created_at)));
        rows.truncate(limit as usize);
    }
    let mut statuses = Vec::new();
    for row in &rows {
        if let Some(status) = serialize_candidate(&db, &row.source, row.source_pk, row.object_id.as_deref(), &row.created_at).await {
            statuses.push(status);
        }
    }
    crate::home_hydrate_ranked::refresh_local_accounts(&db, &mut statuses).await?;
    let hashtags = if kind == "hashtags" { hashtag_rows(&db, owner_id, tag.unwrap_or(query), limit).await.unwrap_or_default() } else { Vec::new() };
    Ok(SearchResults { owner_id, actor_key, query: query.to_string(), query_type: kind,
        normalized_query: Some(match_query), rows, statuses, accounts: Vec::new(), hashtags, redirect_url: None, privacy_filtered: true,
        fallback: "php", source: "vaak-worker-shadow", mutation_enabled: false })
}

#[cfg(test)]
mod tests {
    use super::{bsky_media, status_json, tag_match_query, text_match_query};
    use serde_json::json;

    #[test]
    fn text_matches_php_prefix_normalization() {
        assert_eq!(text_match_query("Hello, VAAK! #Rust"), Some("hello* AND vaak* AND rust*".into()));
        assert_eq!(text_match_query("a"), None);
        assert_eq!(text_match_query("one two three four five six seven eight nine"),
            Some("one* AND two* AND three* AND four* AND five* AND six* AND seven* AND eight*".into()));
    }

    #[test]
    fn tag_matches_php_marker_query() {
        assert_eq!(tag_match_query("#Rust"), Some("htag_rust OR rust".into()));
        assert_eq!(tag_match_query("!!!"), None);
    }

    #[test]
    fn serialized_search_status_has_mastodon_shape_and_media() {
        let status = status_json("1".into(), "2026-01-01T00:00:00.000Z", "https://example.test/p/1", "hello", json!({"id":"a"}));
        assert!(status.get("id").is_some());
        assert!(status.get("account").is_some());
        assert!(status.get("media_attachments").is_some());
        let media = bsky_media(&json!({"images":[{"fullsize":"https://cdn.test/a.jpg","alt":"a"}]}), "at://x");
        assert_eq!(media.len(), 1);
    }
}
