//! Local mkultra profile tab HTML fill (0.7.18).
//!
//! Parity with PHP `ap_local_profile_tab_page` + lean Home paint, so own and
//! peer local Profiles use the same action bar as Home.

use anyhow::{Context, Result};
use serde_json::{json, Value};
use tokio_postgres::Client;

use crate::config::Config;
use crate::db;
use crate::notif_embed::paint_lean_feed_card_opts;
use crate::self_thread::{
    missing_self_reply_parent_uris, paint_feed_units, plan_feed_paint_units,
};

const DEFAULT_AVATAR: &str = "https://mkultra.monster/img/avatar/local-default.webp";
const LOCAL_ACTOR_PREFIX: &str = "https://mkultra.monster/users/";

fn urlencoding_encode(s: &str) -> String {
    let mut out = String::with_capacity(s.len() * 2);
    for b in s.bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(b as char)
            }
            _ => out.push_str(&format!("%{b:02X}")),
        }
    }
    out
}

#[derive(Debug, Clone)]
pub struct ProfileHtmlReport {
    pub html: String,
    pub count: usize,
    pub has_more: bool,
    pub next_offset: usize,
    pub source: String,
}

/// Parse `https://mkultra.monster/users/{username}` → lowercase username.
fn parse_local_actor(actor_url: &str) -> Option<String> {
    let trimmed = actor_url.trim().trim_end_matches('/');
    let rest = trimmed.strip_prefix(LOCAL_ACTOR_PREFIX)?;
    if rest.is_empty() || rest.contains('/') {
        return None;
    }
    let key = rest.to_ascii_lowercase();
    if !key
        .chars()
        .all(|c| c.is_ascii_alphanumeric() || c == '_')
    {
        return None;
    }
    Some(key)
}

fn format_published(raw: &str) -> String {
    let t = raw.trim();
    if t.is_empty() {
        return chrono::Utc::now()
            .format("%Y-%m-%dT%H:%M:%S.000Z")
            .to_string();
    }
    if let Ok(dt) = chrono::DateTime::parse_from_rfc3339(t) {
        return dt
            .with_timezone(&chrono::Utc)
            .format("%Y-%m-%dT%H:%M:%S.000Z")
            .to_string();
    }
    // PG timestamp without TZ → treat as UTC.
    let normalized = t.replace(' ', "T");
    if let Ok(dt) = chrono::NaiveDateTime::parse_from_str(&normalized, "%Y-%m-%dT%H:%M:%S%.f") {
        return dt.format("%Y-%m-%dT%H:%M:%S.000Z").to_string();
    }
    if let Ok(dt) = chrono::NaiveDateTime::parse_from_str(&normalized, "%Y-%m-%dT%H:%M:%S") {
        return dt.format("%Y-%m-%dT%H:%M:%S.000Z").to_string();
    }
    format!("{}Z", normalized.trim_end_matches('Z'))
}

fn empty_account(actor_url: &str, username: &str, display: &str, avatar: &str) -> Value {
    let av = if avatar.starts_with("https://") {
        avatar
    } else {
        DEFAULT_AVATAR
    };
    json!({
        "id": actor_url,
        "username": username,
        "acct": username,
        "display_name": display,
        "locked": false,
        "bot": false,
        "discoverable": false,
        "group": false,
        "created_at": chrono::Utc::now().format("%Y-%m-%dT%H:%M:%S.000Z").to_string(),
        "note": "",
        "url": actor_url,
        "uri": actor_url,
        "avatar": av,
        "avatar_static": av,
        "header": "",
        "header_static": "",
        "followers_count": 0,
        "following_count": 0,
        "statuses_count": 0,
        "last_status_at": Value::Null,
        "emojis": [],
        "fields": [],
    })
}

/// Mastodon-style video attachments need an image poster; browsers ignore an
/// MP4 used directly as `<video poster>`. Keep profile tabs in parity with the
/// Home hydrate path.
fn guess_video_preview(url: &str) -> Option<String> {
    let re = regex::Regex::new(
        r"(?i)^(https://.+)/original/([^/?#]+)\.(mp4|m4v|mov|webm)([?#].*)?$",
    ).ok()?;
    let c = re.captures(url)?;
    Some(format!("{}/small/{}.png", &c[1], &c[2]))
}

fn media_from_raw_create(raw: &str) -> Vec<Value> {
    let Ok(decoded) = serde_json::from_str::<Value>(raw) else {
        return Vec::new();
    };
    let obj = decoded
        .get("object")
        .filter(|v| v.is_object())
        .cloned()
        .unwrap_or(decoded);
    let atts = match obj.get("attachment") {
        Some(Value::Array(a)) => a.clone(),
        Some(Value::Object(o)) => vec![Value::Object(o.clone())],
        _ => return Vec::new(),
    };
    let mut out = Vec::new();
    for att in atts {
        if out.len() >= 4 {
            break;
        }
        let url = att
            .get("url")
            .and_then(|v| {
                v.as_str()
                    .map(str::to_string)
                    .or_else(|| v.get("href").and_then(|h| h.as_str()).map(str::to_string))
            })
            .unwrap_or_default();
        if !url.starts_with("https://") {
            continue;
        }
        let mt = att
            .get("mediaType")
            .and_then(|v| v.as_str())
            .unwrap_or("")
            .to_ascii_lowercase();
        let atype = att.get("type").and_then(|v| v.as_str()).unwrap_or("");
        let kind = if mt.starts_with("video/") || atype.eq_ignore_ascii_case("Video") {
            "video"
        } else if mt.starts_with("audio/") || atype.eq_ignore_ascii_case("Audio") {
            "audio"
        } else {
            "image"
        };
        let preview = if kind == "video" {
            guess_video_preview(&url)
        } else {
            Some(url.clone())
        };
        out.push(json!({
            "id": url,
            "type": kind,
            "url": url,
            "preview_url": preview.unwrap_or_default(),
            "remote_url": Value::Null,
            "preview_remote_url": Value::Null,
            "text_url": Value::Null,
            "description": Value::Null,
            "blurhash": Value::Null,
        }));
    }
    out
}

struct OutboxPaintRow {
    id: String,
    published: String,
    content: String,
    raw_create_json: String,
    in_reply_to: String,
    local_id: Option<i64>,
    spoiler_text: String,
    sensitive: bool,
    visibility: String,
    content_text: String,
    pinned: bool,
    ask_actor: String,
    ask_question: String,
    ask_answer: String,
    quote_object: String,
}

/// Quote metadata lives in the canonical raw Create payload, not in
/// `masto_statuses`; keep profile rendering in parity with timeline rendering.
fn quote_target_from_raw_create(raw: &str) -> String {
    let Ok(value) = serde_json::from_str::<Value>(raw) else {
        return String::new();
    };
    let object = value.get("object").unwrap_or(&value);
    for key in ["quote", "quoteUri", "quoteUrl", "_misskey_quote"] {
        let Some(candidate) = object.get(key) else { continue };
        let target = candidate
            .as_str()
            .map(str::to_string)
            .or_else(|| candidate.get("id").and_then(Value::as_str).map(str::to_string))
            .or_else(|| candidate.get("url").and_then(Value::as_str).map(str::to_string))
            .unwrap_or_default();
        if target.starts_with("https://") || target.starts_with("http://") || target.starts_with("at://") {
            return target.trim_end_matches('/').to_string();
        }
    }
    String::new()
}

struct AnnouncePaintRow {
    id: i64,
    actor_id: String,
    object_id: String,
    created_at: String,
    summary: String,
}

async fn load_viewer_actor(db: &Client, owner_id: i64) -> Result<String> {
    let row = db
        .query_opt(
            "SELECT COALESCE(NULLIF(trim(actor_id), ''), ''), COALESCE(username, '')
             FROM ap_users WHERE id = $1 LIMIT 1",
            &[&owner_id],
        )
        .await
        .context("select viewer ap_users")?;
    let Some(row) = row else {
        return Ok(String::new());
    };
    let actor_id: String = row.get(0);
    if actor_id.starts_with("https://") {
        return Ok(actor_id.trim_end_matches('/').to_string());
    }
    let username: String = row.get(1);
    if username.is_empty() {
        return Ok(String::new());
    }
    Ok(format!(
        "{}{}",
        LOCAL_ACTOR_PREFIX,
        username.to_ascii_lowercase()
    ))
}

async fn load_profile_account(db: &Client, username: &str, actor_url: &str) -> Value {
    let mut display = username.to_string();
    let mut avatar = DEFAULT_AVATAR.to_string();
    if let Ok(Some(row)) = db
        .query_opt(
            "SELECT COALESCE(name, ''), COALESCE(icon_url, '')
             FROM actor_profile WHERE lower(actor_key) = lower($1) LIMIT 1",
            &[&username],
        )
        .await
    {
        let name: String = row.get(0);
        let icon: String = row.get(1);
        if !name.trim().is_empty() {
            display = name.trim().to_string();
        }
        if icon.starts_with("https://") {
            avatar = icon;
        }
    }
    empty_account(actor_url, username, &display, &avatar)
}

/// Resolve local quote targets in one bounded query so profile quote cards
/// show the quoted post body/media instead of only a transport URL.
async fn hydrate_local_quote_targets(db: &Client, statuses: &mut [Value]) -> Result<()> {
    let mut targets = Vec::new();
    for st in statuses.iter() {
        if let Some(uri) = st
            .get("quote")
            .and_then(|q| q.get("quoted_status"))
            .and_then(|q| q.get("uri"))
            .and_then(|v| v.as_str())
            .filter(|u| !u.trim().is_empty())
        {
            targets.push(uri.trim_end_matches('/').to_string());
        }
    }
    targets.sort();
    targets.dedup();
    if targets.is_empty() {
        return Ok(());
    }
    let bsky_targets: Vec<String> = targets
        .iter()
        .filter(|target| !target.starts_with(LOCAL_ACTOR_PREFIX))
        .cloned()
        .collect();
    let bsky_quotes = crate::home_hydrate_ranked::fetch_bsky_map_for_targets(db, &bsky_targets)
        .await
        .unwrap_or_default();
    let local_targets: Vec<String> = targets
        .iter()
        .filter(|target| target.starts_with(LOCAL_ACTOR_PREFIX))
        .cloned()
        .collect();
    let rows = db.query(
        "SELECT id, COALESCE(content, ''), COALESCE(raw_create_json, '') FROM outbox_notes WHERE id = ANY($1)",
        &[&local_targets],
    ).await.context("select local quote targets")?;
    let mut by_target = std::collections::HashMap::new();
    for row in rows {
        let id: String = row.get(0);
        let id = id.trim_end_matches('/').to_string();
        let username = id.strip_prefix(LOCAL_ACTOR_PREFIX)
            .and_then(|rest| rest.split('/').next()).unwrap_or("quoted");
        let media = media_from_raw_create(&row.get::<_, String>(2));
        by_target.insert(id.clone(), json!({
            "id": id,
            "uri": id,
            "url": id,
            "content": row.get::<_, String>(1),
            "account": empty_account(&format!("{LOCAL_ACTOR_PREFIX}{username}"), username, username, DEFAULT_AVATAR),
            "media_attachments": media,
        }));
    }
    for (target, bsky) in bsky_quotes {
        let mut quoted = crate::home_hydrate_ranked::materialize_bsky(&bsky);
        // Keep the canonical at:// URI for identity/actions, but make the
        // quote card itself resolve inside VAAK rather than opening Bluesky.
        let internal_url = format!(
            "?view=status&object={}&from=home",
            urlencoding_encode(&target)
        );
        quoted["url"] = json!(internal_url.clone());
        if let Some(card) = quoted.get_mut("card").filter(|v| v.is_object()) {
            card["url"] = json!(internal_url);
        }
        by_target.insert(target, quoted);
    }
    for st in statuses.iter_mut() {
        let target = st.get("quote").and_then(|q| q.get("quoted_status"))
            .and_then(|q| q.get("uri")).and_then(|v| v.as_str()).unwrap_or("")
            .trim_end_matches('/');
        if let Some(quoted) = by_target.get(target) {
            st["quote"]["quoted_status"] = quoted.clone();
            st["vaak_quote_preview"] = quoted.clone();
        }
    }
    Ok(())
}

async fn fetch_outbox_tab(
    db: &Client,
    prefix: &str,
    tab: &str,
    limit: i64,
    offset: i64,
) -> Result<Vec<OutboxPaintRow>> {
    let like = format!("{prefix}%");
    // Mastodon semantics: Posts = originals + self-replies; Replies = replies to others.
    let self_notes_like = format!("{prefix}notes/%");
    let rows = match tab {
        "media" => {
            db.query(
                "SELECT id, COALESCE(published::text, ''), COALESCE(content, ''),
                        COALESCE(raw_create_json, ''), COALESCE(in_reply_to, '')
                 FROM outbox_notes
                 WHERE id LIKE $1 AND raw_create_json LIKE '%\"attachment\"%'
                 ORDER BY published DESC
                 LIMIT $2 OFFSET $3",
                &[&like, &limit, &offset],
            )
            .await
            .context("select outbox media tab")?
        }
        "replies" => {
            db.query(
                "SELECT id, COALESCE(published::text, ''), COALESCE(content, ''),
                        COALESCE(raw_create_json, ''), COALESCE(in_reply_to, '')
                 FROM outbox_notes
                 WHERE id LIKE $1
                   AND in_reply_to IS NOT NULL AND btrim(in_reply_to) <> ''
                   AND in_reply_to NOT LIKE $2
                 ORDER BY published DESC
                 LIMIT $3 OFFSET $4",
                &[&like, &self_notes_like, &limit, &offset],
            )
            .await
            .context("select outbox replies tab")?
        }
        _ => {
            db.query(
                "SELECT id, COALESCE(published::text, ''), COALESCE(content, ''),
                        COALESCE(raw_create_json, ''), COALESCE(in_reply_to, '')
                 FROM outbox_notes
                 WHERE id LIKE $1
                   AND (
                     in_reply_to IS NULL OR btrim(in_reply_to) = ''
                     OR in_reply_to LIKE $2
                   )
                 ORDER BY published DESC
                 LIMIT $3 OFFSET $4",
                &[&like, &self_notes_like, &limit, &offset],
            )
            .await
            .context("select outbox posts tab")?
        }
    };

    let mut out = Vec::with_capacity(rows.len());
    let mut note_ids = Vec::new();
    for row in &rows {
        let id: String = row.get(0);
        note_ids.push(id.trim_end_matches('/').to_string());
        note_ids.push(format!("{}/", id.trim_end_matches('/')));
        out.push(OutboxPaintRow {
            id,
            published: row.get(1),
            content: row.get(2),
            raw_create_json: row.get(3),
            in_reply_to: row.get(4),
            local_id: None,
            spoiler_text: String::new(),
            sensitive: false,
            visibility: "public".into(),
            content_text: String::new(),
            pinned: false,
            ask_actor: String::new(),
            ask_question: String::new(),
            ask_answer: String::new(),
            quote_object: String::new(),
        });
    }
    if note_ids.is_empty() {
        return Ok(out);
    }

    // PHP profile parity: resolve structured Ask context from the same
    // mentions/ap_asks records used by the canonical Ask card renderer.
    let mut asks: std::collections::HashMap<String, (String, String, String)> = std::collections::HashMap::new();
    if let Ok(rows) = db.query(
        "SELECT object_id, COALESCE(ask_actor,''), COALESCE(ask_question,''), COALESCE(ask_answer,'')
         FROM mentions WHERE object_id = ANY($1) AND deleted_at IS NULL AND ask_question <> '' ORDER BY id DESC",
        &[&note_ids],
    ).await {
        for row in rows {
            let key: String = row.get::<_, String>(0).trim_end_matches('/').to_string();
            asks.entry(key).or_insert((row.get(1), row.get(2), row.get(3)));
        }
    }
    if let Ok(rows) = db.query(
        "SELECT answer_note_id, COALESCE(asker_actor,''), COALESCE(question,'')
         FROM ap_asks WHERE answer_note_id = ANY($1) ORDER BY id DESC",
        &[&note_ids],
    ).await {
        for row in rows {
            let key: String = row.get::<_, String>(0).trim_end_matches('/').to_string();
            asks.entry(key).or_insert((row.get(1), row.get(2), String::new()));
        }
    }
    for row in &mut out {
        if let Some((actor, question, answer)) = asks.get(row.id.trim_end_matches('/')) {
            row.ask_actor = actor.clone();
            row.ask_question = question.clone();
            row.ask_answer = answer.clone();
        }
    }
    note_ids.sort();
    note_ids.dedup();

    let masto = db
        .query(
            "SELECT note_id, local_id, COALESCE(spoiler_text, ''), COALESCE(sensitive, 0),
                    COALESCE(visibility, 'public'), COALESCE(content_text, '')
             FROM masto_statuses
             WHERE note_id = ANY($1)",
            &[&note_ids],
        )
        .await
        .context("select masto_statuses for profile")?;

    let mut by_note: std::collections::HashMap<String, (i64, String, bool, String, String)> =
        std::collections::HashMap::new();
    let mut local_ids: Vec<i64> = Vec::new();
    for row in masto {
        let note_id: String = row.get(0);
        let local_id: i64 = row.get(1);
        // masto_statuses.sensitive is bigint (SQLite legacy), not int4.
        let sens_i: i64 = row.get(3);
        let key = note_id.trim_end_matches('/').to_string();
        by_note.insert(
            key,
            (
                local_id,
                row.get(2),
                sens_i != 0,
                row.get(4),
                row.get(5),
            ),
        );
        local_ids.push(local_id);
    }

    let mut pinned: std::collections::HashSet<i64> = std::collections::HashSet::new();
    if !local_ids.is_empty() {
        if let Ok(prows) = db
            .query(
                "SELECT status_local_id FROM masto_pins WHERE status_local_id = ANY($1)",
                &[&local_ids],
            )
            .await
        {
            for prow in prows {
                let lid: i64 = prow.get(0);
                pinned.insert(lid);
            }
        }
    }

    for row in &mut out {
        let key = row.id.trim_end_matches('/').to_string();
        if let Some((lid, spoiler, sens, vis, ctext)) = by_note.get(&key) {
            row.local_id = Some(*lid);
            row.spoiler_text = spoiler.clone();
            row.sensitive = *sens;
            row.visibility = vis.clone();
            row.content_text = ctext.clone();
            row.quote_object = quote_target_from_raw_create(&row.raw_create_json);
            row.pinned = pinned.contains(lid);
        }
    }
    Ok(out)
}

async fn fetch_boosts_tab(
    db: &Client,
    actor_url: &str,
    limit: i64,
    offset: i64,
) -> Result<Vec<AnnouncePaintRow>> {
    let actor_slash = format!("{actor_url}/");
    let rows = db
        .query(
            "SELECT id, COALESCE(actor_id, ''), COALESCE(object_id, ''),
                    COALESCE(created_at::text, ''), COALESCE(summary, '')
             FROM (
               SELECT DISTINCT ON (object_id) *
               FROM events
               WHERE type = 'Announce' AND (actor_id = $1 OR actor_id = $2)
               ORDER BY object_id,
                 CASE WHEN action_taken = 'boost_ok' THEN 0 ELSE 1 END,
                 created_at DESC, id DESC
             ) AS profile_events
             ORDER BY created_at DESC, id DESC
             LIMIT $3 OFFSET $4",
            &[&actor_url, &actor_slash, &limit, &offset],
        )
        .await
        .context("select profile Announce events")?;
    Ok(rows
        .into_iter()
        .map(|row| AnnouncePaintRow {
            id: row.get(0),
            actor_id: row.get(1),
            object_id: row.get(2),
            created_at: row.get(3),
            summary: row.get(4),
        })
        .collect())
}

fn materialize_outbox_status(row: &OutboxPaintRow, account: &Value) -> Value {
    let created = format_published(&row.published);
    let content = if !row.content.trim().is_empty() {
        row.content.clone()
    } else if !row.content_text.trim().is_empty() {
        format!("<p>{}</p>", html_escape_text(&row.content_text))
    } else {
        String::new()
    };
    let sid = row
        .local_id
        .map(|n| n.to_string())
        .unwrap_or_else(|| row.id.clone());
    let media = media_from_raw_create(&row.raw_create_json);
    let mut st = json!({
        "id": sid,
        "created_at": created,
        "in_reply_to_id": Value::Null,
        "in_reply_to_account_id": Value::Null,
        "sensitive": row.sensitive || !row.spoiler_text.trim().is_empty(),
        "spoiler_text": row.spoiler_text,
        "visibility": if row.visibility.is_empty() { "public" } else { row.visibility.as_str() },
        "language": Value::Null,
        "uri": row.id.trim_end_matches('/'),
        "url": row.id.trim_end_matches('/'),
        "replies_count": 0,
        "reblogs_count": 0,
        "favourites_count": 0,
        "edited_at": Value::Null,
        "favourited": false,
        "reblogged": false,
        "muted": false,
        "bookmarked": false,
        "pinned": row.pinned,
        "content": content,
        "reblog": Value::Null,
        "application": Value::Null,
        "account": account,
        "media_attachments": media,
        "mentions": [],
        "tags": [],
        "emojis": [],
        "card": Value::Null,
        "poll": Value::Null,
    });
    if !row.ask_question.trim().is_empty() {
        st["vaak_ask"] = json!({
            "ask_actor": row.ask_actor,
            "ask_question": row.ask_question,
            "ask_answer": row.ask_answer,
        });
    }
    if !row.quote_object.trim().is_empty() {
        let target = row.quote_object.trim().trim_end_matches('/');
        let quoted_account = empty_account(target, "quoted", "Quoted post", DEFAULT_AVATAR);
        let quoted = json!({
            "id": target,
            "uri": target,
            "url": target,
            "content": "",
            "account": quoted_account,
            "media_attachments": [],
            "card": {"url": target, "title": "Quoted post", "description": "Open quoted post", "provider_name": "VAAK", "type": "link"}
        });
        st["vaak_quote_preview"] = quoted.clone();
        st["quote"] = json!({"state": "accepted", "quoted_status": quoted, "quoted_status_id": target});
        st["quote_approval"] = json!({"automatic": ["public"], "manual": [], "current_user": "automatic"});
    }
    let parent = row.in_reply_to.trim().trim_end_matches('/');
    if !parent.is_empty() && parent.starts_with("https://") {
        st["vaak_in_reply_to_url"] = json!(parent);
        if let Some((child_actor, _)) = row.id.rsplit_once("/notes/") {
            if let Some((parent_actor, _)) = parent.rsplit_once("/notes/") {
                if child_actor == parent_actor {
                    st["vaak_self_thread"] = json!(true);
                    st["in_reply_to_id"] = json!(parent);
                    if let Some(acct_id) = account.get("id").cloned() {
                        st["in_reply_to_account_id"] = acct_id;
                    }
                }
            }
        }
    }
    st
}

fn html_escape_text(s: &str) -> String {
    s.replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
}

fn materialize_announce_status(
    ann: &AnnouncePaintRow,
    booster: &Value,
    inner: Option<Value>,
) -> Value {
    let created = format_published(&ann.created_at);
    let object_id = ann.object_id.trim_end_matches('/');
    let mut inner_st = inner.unwrap_or_else(|| {
        let plain = ann.summary.trim();
        let content = if plain.is_empty() || plain.eq_ignore_ascii_case("(boost)") {
            String::new()
        } else {
            format!("<p>{}</p>", html_escape_text(plain))
        };
        let thin_acct = {
            let username = object_id
                .rsplit('/')
                .nth(1)
                .or_else(|| object_id.rsplit('/').next())
                .unwrap_or("unknown");
            // Prefer /users/{name}/notes/… username.
            let uname = if let Some(rest) = object_id.strip_prefix(LOCAL_ACTOR_PREFIX) {
                rest.split('/').next().unwrap_or(username)
            } else {
                username
            };
            empty_account(object_id, uname, uname, DEFAULT_AVATAR)
        };
        json!({
            "id": format!("boost-inner:{}", ann.id),
            "created_at": created,
            "sensitive": false,
            "spoiler_text": "",
            "visibility": "public",
            "uri": object_id,
            "url": object_id,
            "content": content,
            "account": thin_acct,
            "media_attachments": [],
            "reblog": Value::Null,
            "favourited": false,
            "reblogged": false,
            "bookmarked": false,
            "pinned": false,
        })
    });
    inner_st["reblog"] = Value::Null;
    if object_id.starts_with("https://") {
        inner_st["uri"] = json!(object_id);
        inner_st["url"] = json!(object_id);
    }
    let uri = if object_id.is_empty() {
        format!("{}#announce-{}", ann.actor_id.trim_end_matches('/'), ann.id)
    } else {
        format!("{object_id}#announce-{}", ann.id)
    };
    json!({
        "id": format!("announce:{}", ann.id),
        "created_at": created,
        "sensitive": false,
        "spoiler_text": "",
        "visibility": "public",
        "uri": uri,
        "url": if object_id.is_empty() { ann.actor_id.trim_end_matches('/').to_string() } else { object_id.to_string() },
        "content": "",
        "account": booster,
        "media_attachments": [],
        "reblog": inner_st,
        "favourited": false,
        "reblogged": false,
        "bookmarked": false,
        "pinned": false,
        "vaak_announce_event_id": ann.id,
    })
}

async fn hydrate_announce_inners(
    db: &Client,
    anns: &[AnnouncePaintRow],
) -> Result<std::collections::HashMap<String, Value>> {
    let mut map = std::collections::HashMap::new();
    let mut note_ids = Vec::new();
    for a in anns {
        let oid = a.object_id.trim_end_matches('/');
        if oid.starts_with(LOCAL_ACTOR_PREFIX) && oid.contains("/notes/") {
            note_ids.push(oid.to_string());
            note_ids.push(format!("{oid}/"));
        }
    }
    if note_ids.is_empty() {
        return Ok(map);
    }
    note_ids.sort();
    note_ids.dedup();
    let rows = db
        .query(
            "SELECT id, COALESCE(published::text, ''), COALESCE(content, ''),
                    COALESCE(raw_create_json, ''), COALESCE(in_reply_to, '')
             FROM outbox_notes WHERE id = ANY($1)",
            &[&note_ids],
        )
        .await
        .context("select announce inner outbox")?;
    // Batch masto for local_id when present.
    let mut paint_rows = Vec::new();
    for row in rows {
        paint_rows.push(OutboxPaintRow {
            id: row.get(0),
            published: row.get(1),
            content: row.get(2),
            raw_create_json: row.get(3),
            in_reply_to: row.get(4),
            local_id: None,
            spoiler_text: String::new(),
            sensitive: false,
            visibility: "public".into(),
            content_text: String::new(),
            pinned: false,
            ask_actor: String::new(),
            ask_question: String::new(),
            ask_answer: String::new(),
            quote_object: String::new(),
        });
    }
    let mut variants = Vec::new();
    for r in &paint_rows {
        variants.push(r.id.trim_end_matches('/').to_string());
        variants.push(format!("{}/", r.id.trim_end_matches('/')));
    }
    if !variants.is_empty() {
        if let Ok(masto) = db
            .query(
                "SELECT note_id, local_id, COALESCE(spoiler_text, ''), COALESCE(sensitive, 0),
                        COALESCE(visibility, 'public'), COALESCE(content_text, '')
                 FROM masto_statuses WHERE note_id = ANY($1)",
                &[&variants],
            )
            .await
        {
            let mut by_note = std::collections::HashMap::new();
            for m in masto {
                let note_id: String = m.get(0);
                let sens_i: i64 = m.get(3);
                by_note.insert(
                    note_id.trim_end_matches('/').to_string(),
                    (
                        m.get::<_, i64>(1),
                        m.get::<_, String>(2),
                        sens_i != 0,
                        m.get::<_, String>(4),
                        m.get::<_, String>(5),
                    ),
                );
            }
            for r in &mut paint_rows {
                let key = r.id.trim_end_matches('/').to_string();
                if let Some((lid, spoiler, sens, vis, ctext)) = by_note.get(&key) {
                    r.local_id = Some(*lid);
                    r.spoiler_text = spoiler.clone();
                    r.sensitive = *sens;
                    r.visibility = vis.clone();
                    r.content_text = ctext.clone();
                }
            }
        }
    }
    for r in &paint_rows {
        let key = r.id.trim_end_matches('/').to_string();
        let username = key
            .strip_prefix(LOCAL_ACTOR_PREFIX)
            .and_then(|rest| rest.split('/').next())
            .unwrap_or("unknown");
        let account = empty_account(
            &format!("{LOCAL_ACTOR_PREFIX}{username}"),
            username,
            username,
            DEFAULT_AVATAR,
        );
        map.insert(key, materialize_outbox_status(r, &account));
    }
    Ok(map)
}

/// Build local profile tab HTML (posts / replies / media / boosts).
pub async fn profile_html_fill(
    cfg: &Config,
    viewer_owner_id: i64,
    actor_url: &str,
    tab: &str,
    limit: i64,
    offset: i64,
) -> Result<Option<ProfileHtmlReport>> {
    let Some(username) = parse_local_actor(actor_url) else {
        return Ok(None);
    };
    let actor = format!("{LOCAL_ACTOR_PREFIX}{username}");
    let mut tab = tab.trim().to_ascii_lowercase();
    if !matches!(tab.as_str(), "posts" | "replies" | "boosts" | "media") {
        tab = "posts".into();
    }
    let limit = limit.clamp(1, 50);
    let offset = offset.max(0);
    let fetch_n = limit + 1;

    let db = db::connect(&cfg.database_url).await?;
    let viewer_actor = if viewer_owner_id > 0 {
        load_viewer_actor(&db, viewer_owner_id).await?
    } else {
        String::new()
    };
    let account = load_profile_account(&db, &username, &actor).await;
    let prefix = format!("{actor}/");

    let mut statuses: Vec<Value> = Vec::new();
    let mut has_more = false;

    if tab == "boosts" {
        let mut rows = fetch_boosts_tab(&db, &actor, fetch_n, offset).await?;
        if rows.len() as i64 > limit {
            has_more = true;
            rows.truncate(limit as usize);
        }
        let inners = hydrate_announce_inners(&db, &rows).await?;
        for ann in &rows {
            let oid = ann.object_id.trim_end_matches('/');
            let inner = inners.get(oid).cloned();
            statuses.push(materialize_announce_status(ann, &account, inner));
        }
    } else {
        let mut rows = fetch_outbox_tab(&db, &prefix, &tab, fetch_n, offset).await?;
        if rows.len() as i64 > limit {
            has_more = true;
            rows.truncate(limit as usize);
        }
        for row in &rows {
            statuses.push(materialize_outbox_status(row, &account));
        }
    }

    if statuses.is_empty() {
        return Ok(None);
    }

    let _ = hydrate_local_quote_targets(&db, &mut statuses).await;

    // Live fav/boost/bookmark from masto_* (same as Home lean / Ice Cubes, 0.7.19).
    if viewer_owner_id > 0 {
        let _ = crate::interaction_flags::apply_to_statuses(&db, viewer_owner_id, &mut statuses).await;
        if let Ok(moderation) =
            crate::hidden::load_viewer_moderation(&db, viewer_owner_id).await
        {
            crate::notif_embed::stamp_viewer_moderation(&mut statuses, &moderation);
        }
    }
    // Cached OG/YouTube cards (PHP `ap_link_preview_card_for_status_text` parity).
    let _ = crate::link_preview::attach_cached_cards(&db, &mut statuses).await;

    // Self-thread: nest reply under parent when both are local own-notes.
    let mut extra_parents = std::collections::HashMap::new();
    if tab == "posts" {
        let need = missing_self_reply_parent_uris(&statuses);
        if !need.is_empty() {
            if let Ok(fetched) = fetch_outbox_statuses_by_uris(&db, &need).await {
                extra_parents = fetched;
            }
        }
        // Point in_reply_to_id at parent status id when parent is present.
        crate::home_hydrate_ranked::link_outbox_reply_ids(&mut statuses);
    }
    let units = plan_feed_paint_units(&statuses, &extra_parents);
    let paint = |st: &Value, from: &str, viewer: &str| paint_lean_feed_card_opts(st, from, viewer);
    let (html, painted) = paint_feed_units(&units, "remote_profile", &viewer_actor, &paint);
    if painted == 0 {
        return Ok(None);
    }

    Ok(Some(ProfileHtmlReport {
        html,
        count: painted,
        has_more,
        next_offset: (offset as usize) + painted,
        source: "axum-profile-html".into(),
    }))
}

/// Load local outbox notes by URI for self-thread parent hydrate.
pub async fn fetch_outbox_statuses_by_uris(
    db: &Client,
    uris: &[String],
) -> Result<std::collections::HashMap<String, Value>> {
    let mut map = std::collections::HashMap::new();
    if uris.is_empty() {
        return Ok(map);
    }
    let mut variants = Vec::new();
    for u in uris {
        let base = u.trim().trim_end_matches('/');
        if base.is_empty() {
            continue;
        }
        variants.push(base.to_string());
        variants.push(format!("{base}/"));
    }
    variants.sort();
    variants.dedup();
    if variants.is_empty() {
        return Ok(map);
    }
    let rows = db
        .query(
            "SELECT id, COALESCE(published::text, ''), COALESCE(content, ''),
                    COALESCE(raw_create_json, ''), COALESCE(in_reply_to, '')
             FROM outbox_notes WHERE id = ANY($1)",
            &[&variants],
        )
        .await
        .context("select outbox parents for self-thread")?;
    let mut paint_rows = Vec::new();
    for row in rows {
        paint_rows.push(OutboxPaintRow {
            id: row.get(0),
            published: row.get(1),
            content: row.get(2),
            raw_create_json: row.get(3),
            in_reply_to: row.get(4),
            local_id: None,
            spoiler_text: String::new(),
            sensitive: false,
            visibility: "public".into(),
            content_text: String::new(),
            pinned: false,
            ask_actor: String::new(),
            ask_question: String::new(),
            ask_answer: String::new(),
            quote_object: String::new(),
        });
    }
    let mut note_ids = Vec::new();
    for r in &paint_rows {
        note_ids.push(r.id.trim_end_matches('/').to_string());
        note_ids.push(format!("{}/", r.id.trim_end_matches('/')));
    }
    if !note_ids.is_empty() {
        if let Ok(masto) = db
            .query(
                "SELECT note_id, local_id, COALESCE(spoiler_text, ''), COALESCE(sensitive, 0),
                        COALESCE(visibility, 'public'), COALESCE(content_text, '')
                 FROM masto_statuses WHERE note_id = ANY($1)",
                &[&note_ids],
            )
            .await
        {
            let mut by_note = std::collections::HashMap::new();
            for m in masto {
                let note_id: String = m.get(0);
                let sens_i: i64 = m.get(3);
                by_note.insert(
                    note_id.trim_end_matches('/').to_string(),
                    (
                        m.get::<_, i64>(1),
                        m.get::<_, String>(2),
                        sens_i != 0,
                        m.get::<_, String>(4),
                        m.get::<_, String>(5),
                    ),
                );
            }
            for r in &mut paint_rows {
                let key = r.id.trim_end_matches('/').to_string();
                if let Some((lid, spoiler, sens, vis, ctext)) = by_note.get(&key) {
                    r.local_id = Some(*lid);
                    r.spoiler_text = spoiler.clone();
                    r.sensitive = *sens;
                    r.visibility = vis.clone();
                    r.content_text = ctext.clone();
                }
            }
        }
    }
    for r in &paint_rows {
        let key = r.id.trim_end_matches('/').to_string();
        let username = key
            .strip_prefix(LOCAL_ACTOR_PREFIX)
            .and_then(|rest| rest.split('/').next())
            .unwrap_or("unknown");
        let actor = format!("{LOCAL_ACTOR_PREFIX}{username}");
        let account = load_profile_account(db, username, &actor).await;
        map.insert(key, materialize_outbox_status(r, &account));
    }
    Ok(map)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn quote_target_reads_nested_quote_url() {
        let raw = r#"{"object":{"quote":{"url":"https://example.test/posts/42/"}}}"#;
        assert_eq!(quote_target_from_raw_create(raw), "https://example.test/posts/42");
    }

    #[test]
    fn parses_local_actor() {
        assert_eq!(
            parse_local_actor("https://mkultra.monster/users/cmdr_nova"),
            Some("cmdr_nova".into())
        );
        assert_eq!(
            parse_local_actor("https://mkultra.monster/users/Valerie/"),
            Some("valerie".into())
        );
        assert_eq!(
            parse_local_actor("https://example.com/users/x"),
            None
        );
        assert_eq!(
            parse_local_actor("https://mkultra.monster/users/x/notes/1"),
            None
        );
    }

    #[test]
    fn materialize_uses_local_id_and_content() {
        let account = empty_account(
            "https://mkultra.monster/users/cmdr_nova",
            "cmdr_nova",
            "Nova",
            DEFAULT_AVATAR,
        );
        let row = OutboxPaintRow {
            id: "https://mkultra.monster/users/cmdr_nova/notes/n1".into(),
            published: "2026-10-05 12:00:00".into(),
            content: "<p>hello</p>".into(),
            raw_create_json: String::new(),
            in_reply_to: String::new(),
            local_id: Some(12345),
            spoiler_text: "cw".into(),
            sensitive: true,
            visibility: "public".into(),
            content_text: "fallback".into(),
            pinned: true,
            ask_actor: String::new(),
            ask_question: String::new(),
            ask_answer: String::new(),
            quote_object: String::new(),
        };
        let st = materialize_outbox_status(&row, &account);
        assert_eq!(st["id"], json!("12345"));
        assert_eq!(st["content"], json!("<p>hello</p>"));
        assert_eq!(st["spoiler_text"], json!("cw"));
        assert_eq!(st["pinned"], json!(true));
        assert_eq!(st["sensitive"], json!(true));
    }

    #[test]
    fn profile_video_attachment_gets_image_poster() {
        let raw = r#"{"object":{"attachment":{"type":"Video","mediaType":"video/mp4","url":"https://files.example/original/clip.mp4"}}}"#;
        let media = media_from_raw_create(raw);
        assert_eq!(media[0]["type"], json!("video"));
        assert_eq!(media[0]["preview_url"], json!("https://files.example/small/clip.png"));
    }

    #[test]
    fn profile_media_parser_keeps_four_valid_attachments_after_bad_urls() {
        let raw = r#"{"object":{"attachment":[
          {"type":"Image","mediaType":"image/jpeg","url":"http://bad.example/a.jpg"},
          {"type":"Image","mediaType":"image/jpeg","url":"https://files.example/a.jpg"},
          {"type":"Image","mediaType":"image/jpeg","url":"https://files.example/b.jpg"},
          {"type":"Image","mediaType":"image/jpeg","url":"https://files.example/c.jpg"},
          {"type":"Image","mediaType":"image/jpeg","url":"https://files.example/d.jpg"},
          {"type":"Image","mediaType":"image/jpeg","url":"https://files.example/e.jpg"}
        ]}}"#;
        let media = media_from_raw_create(raw);
        assert_eq!(media.len(), 4);
        assert_eq!(media[0]["url"], json!("https://files.example/a.jpg"));
        assert_eq!(media[3]["url"], json!("https://files.example/d.jpg"));
    }
}
