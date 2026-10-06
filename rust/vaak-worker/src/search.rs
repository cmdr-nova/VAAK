//! Read-only indexed Search projection.
//!
//! This intentionally stops before Mastodon account/status serialization.  It
//! mirrors PHP's ap_search_fts query construction and ordering, applies the
//! viewer's current block/mute sets to event/mention candidates, and leaves
//! account resolution and remote URL redirects on PHP until those fixtures
//! have exact parity.

use anyhow::{Context, Result};
use serde::Serialize;
use tokio_postgres::Client;

use crate::hidden::HiddenSets;

#[derive(Debug, Serialize)]
pub struct SearchRow {
    pub source: String,
    pub source_pk: i64,
    pub object_id: Option<String>,
    pub created_at: String,
    pub rank: f64,
}

#[derive(Debug, Serialize)]
pub struct SearchResults {
    pub owner_id: i64,
    pub actor_key: String,
    pub query: String,
    pub query_type: String,
    pub normalized_query: Option<String>,
    pub rows: Vec<SearchRow>,
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
                COALESCE(text,''), COALESCE(author_did,'')
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
        if hidden.is_hidden(&author_did) { continue; }
        let created_at: String = row.get(1);
        let rank = terms.iter().map(|term| lower.matches(term).count() as f64).sum();
        out.push(SearchRow {
            source: "bsky_post".into(), source_pk: 0, object_id: Some(uri),
            created_at, rank,
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
            normalized_query: None, rows: Vec::new(), privacy_filtered: false,
            accounts: Vec::new(), hashtags: Vec::new(), redirect_url: Some(query.trim().to_string()),
            fallback: "php", source: "vaak-worker-shadow", mutation_enabled: false,
        });
    }
    let hidden = crate::hidden::load_hidden_sets(&db, owner_id).await.unwrap_or_else(|_| HiddenSets::default());
    if kind == "accounts" {
        let accounts = account_rows(&db, &hidden, query, limit).await?;
        return Ok(SearchResults { owner_id, actor_key, query: query.to_string(), query_type: kind,
            normalized_query: None, rows: Vec::new(), accounts, hashtags: Vec::new(), redirect_url: None,
            privacy_filtered: true, fallback: "php", source: "vaak-worker-shadow", mutation_enabled: false });
    }
    let match_query = if kind == "hashtags" {
        tag_match_query(tag.filter(|v| !v.trim().is_empty()).unwrap_or(query))
    } else {
        text_match_query(query)
    };
    let Some(match_query) = match_query else {
        return Ok(SearchResults { owner_id, actor_key, query: query.to_string(), query_type: kind,
            normalized_query: None, rows: Vec::new(), accounts: Vec::new(), hashtags: Vec::new(), redirect_url: None, privacy_filtered: true,
            fallback: "php", source: "vaak-worker-shadow", mutation_enabled: false });
    };
    let tsquery = postgres_tsquery(&match_query);
    let candidates = db.query(
        "SELECT source, source_pk, object_id, created_at,
                ts_rank_cd(body_tsv, to_tsquery('simple', $1))::double precision AS rank
         FROM ap_search_docs
         WHERE body_tsv @@ to_tsquery('simple', $1)
         ORDER BY rank DESC, created_at DESC
         LIMIT $2",
        &[&tsquery, &(limit * 3).min(240)],
    ).await.context("query search FTS")?;
    let mut rows = Vec::with_capacity(candidates.len());
    for row in candidates {
        let source: String = row.get(0);
        let source_pk: i64 = row.get(1);
        let (actor, target) = candidate_actor(&db, &source, source_pk).await.unwrap_or_default();
        if hidden.is_hidden(&actor) || (source == "event" && !target.is_empty() && hidden.is_hidden(&target)) {
            continue;
        }
        rows.push(SearchRow {
            source, source_pk, object_id: row.get(2), created_at: row.get(3), rank: row.get(4),
        });
    }
    rows.truncate(limit as usize);
    if kind == "text" {
        rows.extend(bsky_rows(&db, &hidden, query, limit).await.unwrap_or_default());
        rows.sort_by(|a, b| b.rank.partial_cmp(&a.rank).unwrap_or(std::cmp::Ordering::Equal).then_with(|| b.created_at.cmp(&a.created_at)));
        rows.truncate(limit as usize);
    }
    let hashtags = if kind == "hashtags" { hashtag_rows(&db, owner_id, tag.unwrap_or(query), limit).await.unwrap_or_default() } else { Vec::new() };
    Ok(SearchResults { owner_id, actor_key, query: query.to_string(), query_type: kind,
        normalized_query: Some(match_query), rows, accounts: Vec::new(), hashtags, redirect_url: None, privacy_filtered: true,
        fallback: "php", source: "vaak-worker-shadow", mutation_enabled: false })
}

#[cfg(test)]
mod tests {
    use super::{tag_match_query, text_match_query};

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
}
