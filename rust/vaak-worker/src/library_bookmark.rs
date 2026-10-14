//! Saved-row resolution for mixed-network Library items and cold URL stubs.
//!
//! PHP `ap_masto_interaction_row_to_status` paints a local row, a cached
//! ActivityPub Create/Update, a plain Bluesky PostView, or an https URL stub.
//! `at://` rows with no post are omitted. A quote with a cached target, a
//! compact preview, or a cold/deleted/non-status stub is painted here. Polls,
//! Asks, announce-only boosts, reply-parent chrome, and a QT line that is not
//! a quote card stay on the canonical PHP renderer.
//! Bookmarks and favourites share this projector; the saved flag differs.
use anyhow::{bail, Context, Result};
use serde_json::{json, Value};
use std::collections::HashMap;
use tokio_postgres::Client;

use super::actions::{esc, query};
use super::card::php_trim;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum BookmarkPath {
    Local,
    RemoteActivityPub,
    Bluesky,
    Cold,
}

/// PHP passes `'bookmarked'` or `'favourited'` into the same row projector.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum SavedFlag {
    Bookmarked,
    Favourited,
}

impl SavedFlag {
    pub(crate) fn field(self) -> &'static str {
        match self {
            Self::Bookmarked => "bookmarked",
            Self::Favourited => "favourited",
        }
    }
}

#[derive(Debug, Default)]
pub(crate) struct BookmarkCache {
    pub local_by_note: HashMap<String, Value>,
    pub local_by_id: HashMap<i64, Value>,
    pub events_by_object: HashMap<String, Value>,
    pub events_by_id: HashMap<i64, Value>,
    pub mentions_by_object: HashMap<String, Value>,
    pub mentions_by_id: HashMap<i64, Value>,
    pub actors: HashMap<String, Value>,
    pub profiles: HashMap<String, Value>,
    pub media_by_local: HashMap<i64, Vec<Value>>,
    /// Compact Bluesky quote previews keyed by at:// URI or https web URL.
    /// AppView misses are not fetched.
    pub bsky_by_uri: HashMap<String, Value>,
}

fn s<'a>(v: &'a Value, key: &str) -> &'a str {
    v.get(key).and_then(Value::as_str).unwrap_or("")
}

fn n(v: &Value, key: &str) -> i64 {
    match v.get(key) {
        Some(Value::Number(number)) => number.as_i64().unwrap_or(0),
        Some(Value::String(text)) => text.parse().unwrap_or(0),
        _ => 0,
    }
}

fn flag(v: &Value, key: &str) -> bool {
    match v.get(key) {
        Some(Value::Bool(value)) => *value,
        Some(Value::Number(value)) => value.as_i64().unwrap_or(0) != 0,
        Some(Value::String(value)) => !value.is_empty() && value != "0" && value != "false",
        _ => false,
    }
}

fn crc32(bytes: &[u8]) -> u32 {
    let mut crc = u32::MAX;
    for byte in bytes {
        crc ^= *byte as u32;
        for _ in 0..8 {
            crc = (crc >> 1) ^ if crc & 1 == 1 { 0xedb8_8320 } else { 0 };
        }
    }
    !crc
}

fn object_url(raw: &str) -> String {
    static RE: std::sync::OnceLock<regex::Regex> = std::sync::OnceLock::new();
    let raw = raw.trim().trim_end_matches('/');
    RE.get_or_init(|| regex::Regex::new(r"#announce-\d+$").unwrap())
        .replace(raw, "")
        .trim_end_matches('/')
        .to_owned()
}

fn format_time(iso: &str, now: chrono::DateTime<chrono::Utc>) -> String {
    let parsed = chrono::DateTime::parse_from_rfc3339(iso)
        .map(|date| date.with_timezone(&chrono::Utc))
        .or_else(|_| {
            chrono::NaiveDateTime::parse_from_str(iso, "%Y-%m-%d %H:%M:%S").map(|date| date.and_utc())
        })
        .unwrap_or(now);
    parsed.format("%Y-%m-%dT%H:%M:%S.000Z").to_string()
}

fn plain_html(text: &str) -> String {
    let text = text.replace("\r\n", "\n").replace('\r', "\n");
    let text = php_trim(&text);
    if text.is_empty() {
        return String::new();
    }
    let url_pattern = regex::Regex::new(r#"https://[^\s<>"']+"#).unwrap();
    regex::Regex::new(r"\n{2,}")
        .unwrap()
        .split(text)
        .filter_map(|part| {
            let part = php_trim(part);
            if part.is_empty() {
                return None;
            }
            let escaped = esc(part);
            let escaped = url_pattern.replace_all(&escaped, |captures: &regex::Captures| {
                let url = captures[0].trim_end_matches(['.', ',', ')', ';', ']', '!', '?', '\'', '"']);
                let trail = &captures[0][url.len()..];
                let href = esc(url);
                format!("<a href=\"{href}\" target=\"_blank\" rel=\"nofollow noopener noreferrer\">{href}</a>{trail}")
            });
            Some(format!("<p>{}</p>", escaped.replace('\n', "<br>\n")))
        })
        .collect::<Vec<_>>()
        .join("\n")
}

fn local_actor_key(url: &str) -> Option<String> {
    let rest = url.trim().trim_end_matches('/').strip_prefix("https://mkultra.monster/users/")?;
    let key = rest.split('/').next().unwrap_or("");
    if key.is_empty() || !key.bytes().all(|byte| byte.is_ascii_alphanumeric() || byte == b'_') {
        return None;
    }
    Some(key.to_owned())
}

fn remote_account_id(actor: &str, row: Option<&Value>) -> String {
    let actor = actor.trim().trim_end_matches('/');
    let mut host = reqwest::Url::parse(actor)
        .ok()
        .and_then(|url| url.host_str().map(|host| host.to_ascii_lowercase()))
        .unwrap_or_default();
    let mut username = String::new();
    if let Some(row) = row {
        let stored = php_trim(s(row, "username"));
        if !stored.is_empty() {
            username = stored.to_owned();
        }
        if !s(row, "host").is_empty() {
            host = php_trim(s(row, "host")).to_ascii_lowercase();
        }
    }
    if username.is_empty() {
        if let Some(captures) = regex::Regex::new(r"(?i)/(?:users|@)([^/]+)/?$").unwrap().captures(actor) {
            let name = crate::hidden::raw_percent_decode(&captures[1]);
            if !name.is_empty() && !name.bytes().all(|byte| byte.is_ascii_digit()) && !crate::profile_identity::placeholder(&name) {
                username = name;
            }
        }
    }
    let seed = if !username.is_empty() && !host.is_empty() && host != "unknown" && !crate::profile_identity::placeholder(&username) {
        format!("acct:{}@{host}", username.to_lowercase())
    } else {
        actor.to_owned()
    };
    (300_000_000 + (crc32(seed.as_bytes()) as i64 % 699_999_999)).to_string()
}

fn remote_account(actor: &str, actors: &HashMap<String, Value>) -> Value {
    let actor = actor.trim().trim_end_matches('/').to_owned();
    let row = actors.get(&actor);
    let label = crate::profile_identity::label(&actor, row);
    let username = s(&label, "username");
    let host = s(&label, "host");
    let mut acct = s(&label, "acct").to_owned();
    let avatar = "https://mkultra.monster/img/avatar/default.jpg";
    let mut web = actor.clone();
    if host == "bsky.app" && !username.is_empty() && username != "user" && !crate::profile_identity::placeholder(username) {
        acct = username.to_owned();
        web = format!("https://bsky.app/profile/{}", query(username));
    } else if !host.is_empty() && !username.is_empty() && username != "user" && !crate::profile_identity::placeholder(username) {
        web = format!("https://{host}/@{}", query(username));
    }
    json!({
        "id": remote_account_id(&actor, row),
        "username": username,
        "acct": acct,
        "display_name": label["display_name"],
        "locked": false,
        "bot": false,
        "discoverable": true,
        "group": false,
        "created_at": "2020-01-01T00:00:00.000Z",
        "note": "",
        "url": web,
        "uri": actor,
        "avatar": avatar,
        "avatar_static": avatar,
        "header": avatar,
        "header_static": avatar,
        "followers_count": 0,
        "following_count": 0,
        "statuses_count": 0,
        "last_status_at": null,
        "emojis": [],
        "fields": []
    })
}

fn local_account(actor_or_note: &str, profiles: &HashMap<String, Value>) -> Value {
    let key = local_actor_key(actor_or_note).unwrap_or_else(|| "user".into());
    let profile = profiles.get(&key.to_lowercase());
    let mut display = profile.map(|row| php_trim(s(row, "name")).to_owned()).unwrap_or_default();
    if display.is_empty() {
        display = key.clone();
    }
    let avatar = "https://mkultra.monster/img/avatar/local-default.webp";
    let actor = format!("https://mkultra.monster/users/{key}");
    json!({
        "id": "0",
        "username": key,
        "acct": key,
        "display_name": display,
        "locked": false,
        "bot": false,
        "url": format!("https://vaak.monster/users/{key}"),
        "uri": actor,
        "avatar": avatar,
        "avatar_static": avatar,
        "header": avatar,
        "header_static": avatar
    })
}

fn account_for(actor: &str, cache: &BookmarkCache) -> Value {
    if local_actor_key(actor).is_some() {
        local_account(actor, &cache.profiles)
    } else if actor.trim().starts_with("https://") {
        remote_account(actor, &cache.actors)
    } else {
        json!({"id":"0","username":"user","acct":"?","display_name":"user","uri":"","url":"","avatar":""})
    }
}

fn raw_has_quote(raw: &str) -> bool {
    let raw = raw.trim();
    if raw.is_empty() || raw == "null" {
        return false;
    }
    let Ok(value) = serde_json::from_str::<Value>(raw) else {
        return raw.contains("quote");
    };
    let object = value.get("object").unwrap_or(&value);
    ["quote", "quoteUri", "quoteUrl", "_misskey_quote"]
        .iter()
        .any(|key| object.get(*key).is_some_and(|value| !value.is_null()))
}

fn reply_present(row: &Value) -> bool {
    let reply = php_trim(s(row, "in_reply_to"));
    if !reply.is_empty() {
        return true;
    }
    match row.get("in_reply_to_local_id") {
        Some(Value::Null) | None => false,
        Some(Value::Number(value)) => value.as_i64().unwrap_or(0) != 0,
        Some(Value::String(value)) => !value.is_empty() && value != "0",
        Some(_) => true,
    }
}

fn unsupported_note(text: &str, row: &Value) -> Option<&'static str> {
    let text = php_trim(text);
    if text == "(quote)" || raw_has_quote(s(row, "raw_create_json")) {
        return Some("PHP fallback: quote bookmark needs canonical quote assembly");
    }
    if text.contains('↪') && !super::quote::saved_quote_supported(text) {
        return Some("PHP fallback: quote bookmark needs canonical quote assembly");
    }
    if text == "(poll)" || row.get("poll").is_some_and(Value::is_object) {
        return Some("PHP fallback: poll bookmark remains on the canonical renderer");
    }
    if text == "(ask)" || !php_trim(s(row, "ask_question")).is_empty() || row.get("vaak_ask").is_some_and(Value::is_object) {
        return Some("PHP fallback: Ask bookmark remains on the canonical renderer");
    }
    if reply_present(row) {
        return Some("PHP fallback: reply-parent bookmark chrome remains on the canonical renderer");
    }
    None
}

fn media_attachments(local_id: i64, cache: &BookmarkCache) -> Result<Vec<Value>> {
    let mut media = Vec::new();
    for row in cache.media_by_local.get(&local_id).into_iter().flatten() {
        let url = s(row, "public_url");
        let preview = s(row, "preview_url");
        if url.is_empty() && !preview.is_empty() {
            bail!("PHP fallback: preview-only bookmark media remains on the canonical renderer");
        }
        if url.is_empty() {
            continue;
        }
        media.push(json!({
            "id": n(row, "local_id").to_string(),
            "type": if s(row, "media_type").is_empty() { "unknown" } else { s(row, "media_type") },
            "url": url,
            "preview_url": if preview.is_empty() { url } else { preview },
            "remote_url": null,
            "description": row.get("description").cloned().unwrap_or(Value::Null),
            "blurhash": row.get("blurhash").cloned().unwrap_or(Value::Null)
        }));
        if media.len() == 4 {
            break;
        }
    }
    Ok(media)
}

fn lookup_quoted(url: &str, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>) -> Result<Option<Value>> {
    let key = object_url(url);
    if key.is_empty() {
        return Ok(None);
    }
    if cache.events_by_object.get(&key).is_some_and(deleted_event) {
        return Ok(None);
    }
    if let Some(local) = cache.local_by_note.get(&key) {
        return Ok(Some(from_local_row_depth(local, cache, now, 1)?));
    }
    if let Some(event) = cache.events_by_object.get(&key) {
        return Ok(Some(from_event_row_depth(event, cache, now, 1)?));
    }
    if let Some(mention) = cache.mentions_by_object.get(&key) {
        return Ok(Some(from_mention_row_depth(mention, cache, now, 1)?));
    }
    Ok(None)
}

fn apply_saved_quote(status: &mut Value, summary: &str, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>) -> Result<()> {
    if !super::quote::saved_quote_supported(summary) {
        return Ok(());
    }
    let url = super::quote::quote_target_url(summary).unwrap_or_default();
    let deleted = !url.is_empty() && cache.events_by_object.get(&object_url(&url)).is_some_and(deleted_event);
    let target = if deleted || url.is_empty() { None } else { lookup_quoted(&url, cache, now)? };
    let bsky = if target.is_none() && !deleted {
        cache.bsky_by_uri.get(&url).or_else(|| cache.bsky_by_uri.get(url.trim_end_matches('/')))
    } else {
        None
    };
    let saved = super::quote::assemble_saved_quote(summary, target.as_ref(), deleted, bsky)?;
    let html = plain_html(&saved.commentary);
    status["content"] = json!(html);
    status["quote"] = saved.quote;
    if !saved.quote_url.is_empty() {
        status["quote_url"] = json!(saved.quote_url);
    }
    Ok(())
}

fn from_local_row(row: &Value, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>) -> Result<Value> {
    from_local_row_depth(row, cache, now, 0)
}

fn from_local_row_depth(row: &Value, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>, depth: usize) -> Result<Value> {
    let note = object_url(s(row, "note_id"));
    if note.is_empty() {
        bail!("PHP fallback: local bookmark note identity missing");
    }
    let mut text = s(row, "content_text").to_owned();
    if matches!(text.as_str(), "(quote)" | "(media)" | "(poll)" | "(ask)") {
        text.clear();
    }
    let outbox = s(row, "outbox_content");
    let source_text = if php_trim(&crate::home_hydrate_ranked::html_entity_decode(&regex::Regex::new(r"<[^>]*>").unwrap().replace_all(outbox, ""))).is_empty() {
        text.as_str()
    } else {
        outbox
    };
    if let Some(reason) = unsupported_note(source_text, row).or_else(|| unsupported_note(s(row, "content_text"), row)) {
        if depth == 0 || !reason.contains("quote") {
            bail!(reason);
        }
    }
    let local_id = n(row, "local_id");
    let media = media_attachments(local_id, cache)?;
    if s(row, "content_text") == "(media)" && media.is_empty() {
        bail!("PHP fallback: media-only bookmark needs canonical media rows");
    }
    let created = format_time(s(row, "published"), now);
    let visibility = match s(row, "visibility") {
        "" => "public",
        "local" => "unlisted",
        other => other,
    };
    let content = if !outbox.is_empty() && outbox.contains('<') && !php_trim(&regex::Regex::new(r"<[^>]*>").unwrap().replace_all(outbox, "")).is_empty() {
        outbox.to_owned()
    } else {
        let html = plain_html(&text);
        if html.is_empty() { "<p></p>".into() } else { html }
    };
    let actor = local_actor_key(&note).map(|key| format!("https://mkultra.monster/users/{key}")).unwrap_or_default();
    let quote_summary = if super::quote::saved_quote_supported(source_text) { source_text.to_owned() } else if super::quote::saved_quote_supported(&text) { text.clone() } else { String::new() };
    let mut status = json!({
        "id": crate::notif::snowflake_id(Some(&created), local_id, 0),
        "created_at": created,
        "in_reply_to_id": null,
        "in_reply_to_account_id": null,
        "sensitive": flag(row, "sensitive"),
        "spoiler_text": s(row, "spoiler_text"),
        "visibility": visibility,
        "language": "en",
        "uri": note,
        "url": note,
        "replies_count": 0,
        "reblogs_count": 0,
        "favourites_count": 0,
        "edited_at": null,
        "favourited": false,
        "reblogged": false,
        "muted": false,
        "bookmarked": false,
        "pinned": false,
        "content": content,
        "reblog": null,
        "quote": null,
        "application": {"name": "mkultra.monster", "website": "https://mkultra.monster"},
        "account": if actor.is_empty() { account_for(&note, cache) } else { local_account(&actor, &cache.profiles) },
        "media_attachments": media,
        "mentions": [],
        "tags": [],
        "emojis": [],
        "card": null,
        "poll": null
    });
    if depth == 0 && !quote_summary.is_empty() {
        apply_saved_quote(&mut status, &quote_summary, cache, now)?;
    }
    Ok(status)
}

fn from_event_row(row: &Value, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>) -> Result<Value> {
    from_event_row_depth(row, cache, now, 0)
}

fn from_event_row_depth(row: &Value, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>, depth: usize) -> Result<Value> {
    let kind = s(row, "type");
    if kind.eq_ignore_ascii_case("Announce") {
        bail!("PHP fallback: announce bookmark chrome remains on the canonical renderer");
    }
    if matches!(kind.to_ascii_lowercase().as_str(), "quote" | "quotepost") {
        bail!("PHP fallback: quote bookmark needs canonical quote assembly");
    }
    let action = s(row, "action_taken");
    if action == "deleted" || matches!(kind, "Delete" | "Tombstone") {
        bail!("PHP fallback: deleted bookmark source cannot be revived from an older snapshot");
    }
    let text = s(row, "summary");
    if let Some(reason) = unsupported_note(text, row) {
        if depth == 0 || !reason.contains("quote") {
            bail!(reason);
        }
    }
    let object = object_url(s(row, "object_id"));
    if !object.starts_with("https://") {
        bail!("PHP fallback: non-HTTPS ActivityPub bookmark source");
    }
    let actor = s(row, "actor_id").trim_end_matches('/').to_owned();
    let created = format_time(s(row, "created_at"), now);
    let html = plain_html(text);
    let mut status = json!({
        "id": crate::notif::snowflake_id(Some(&created), n(row, "id"), 1),
        "created_at": created,
        "in_reply_to_id": null,
        "in_reply_to_account_id": null,
        "sensitive": flag(row, "sensitive"),
        "spoiler_text": s(row, "spoiler_text"),
        "visibility": if s(row, "visibility").is_empty() { "public" } else { s(row, "visibility") },
        "language": "en",
        "uri": object,
        "url": object,
        "replies_count": 0,
        "reblogs_count": 0,
        "favourites_count": 0,
        "edited_at": null,
        "favourited": false,
        "reblogged": false,
        "muted": false,
        "bookmarked": false,
        "pinned": false,
        "content": if html.is_empty() { "<p></p>".to_owned() } else { html },
        "reblog": null,
        "quote": null,
        "account": account_for(&actor, cache),
        "media_attachments": [],
        "mentions": [],
        "tags": [],
        "emojis": [],
        "card": null,
        "poll": null
    });
    if depth == 0 && super::quote::saved_quote_supported(text) {
        apply_saved_quote(&mut status, text, cache, now)?;
    }
    Ok(status)
}

fn from_mention_row(row: &Value, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>) -> Result<Value> {
    from_mention_row_depth(row, cache, now, 0)
}

fn from_mention_row_depth(row: &Value, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>, depth: usize) -> Result<Value> {
    if !s(row, "deleted_at").is_empty() && s(row, "deleted_at") != "0" {
        bail!("PHP fallback: deleted mention bookmark");
    }
    if !php_trim(s(row, "ask_question")).is_empty() {
        bail!("PHP fallback: Ask bookmark remains on the canonical renderer");
    }
    let object = object_url(s(row, "object_id"));
    if object.starts_with("at://") || object.starts_with("bsky:") {
        bail!("PHP fallback: Bluesky mention bookmark remains on the canonical renderer");
    }
    let text = s(row, "content");
    if let Some(reason) = unsupported_note(text, row) {
        if depth == 0 || !reason.contains("quote") {
            bail!(reason);
        }
    }
    if !object.starts_with("https://") {
        bail!("PHP fallback: non-HTTPS mention bookmark source");
    }
    let created = format_time(s(row, "created_at"), now);
    let html = plain_html(text);
    let mut status = json!({
        "id": crate::notif::snowflake_id(Some(&created), n(row, "id"), 2),
        "created_at": created,
        "in_reply_to_id": null,
        "sensitive": flag(row, "sensitive"),
        "spoiler_text": s(row, "spoiler_text"),
        "visibility": "public",
        "uri": object,
        "url": object,
        "content": if html.is_empty() { "<p></p>".to_owned() } else { html },
        "reblog": null,
        "quote": null,
        "account": account_for(s(row, "actor_id"), cache),
        "media_attachments": [],
        "bookmarked": false,
        "favourited": false
    });
    if depth == 0 && super::quote::saved_quote_supported(text) {
        apply_saved_quote(&mut status, text, cache, now)?;
    }
    Ok(status)
}

fn deleted_event(row: &Value) -> bool {
    s(row, "action_taken") == "deleted" || matches!(s(row, "type"), "Delete" | "Tombstone")
}

fn lookup_object(object: &str, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>) -> Result<Option<Value>> {
    let key = object_url(object);
    if let Some(local) = cache.local_by_note.get(&key) {
        return Ok(Some(from_local_row(local, cache, now)?));
    }
    if cache.mentions_by_object.get(&key).is_some_and(|row| !php_trim(s(row, "ask_question")).is_empty()) {
        bail!("PHP fallback: Ask bookmark remains on the canonical renderer");
    }
    if let Some(event) = cache.events_by_object.get(&key) {
        if deleted_event(event) {
            return Ok(None);
        }
        return Ok(Some(from_event_row(event, cache, now)?));
    }
    if let Some(mention) = cache.mentions_by_object.get(&key) {
        return Ok(Some(from_mention_row(mention, cache, now)?));
    }
    Ok(None)
}

fn parse_public_id(id: i64) -> Option<(&'static str, i64)> {
    if id <= 0 {
        return None;
    }
    if id < 2_000_000 {
        return Some(("local", id));
    }
    if id < 3_000_000 {
        return Some(("mention", id - 2_000_000));
    }
    if id < 4_000_000 {
        return Some(("event", id - 3_000_000));
    }
    if !(1_000_000_000_000_000_000..).contains(&id) {
        return None;
    }
    let db_id = id % 100_000_000;
    let type_num = (id % 1_000_000_000) / 100_000_000;
    let kind = match type_num {
        1 => "event",
        2 => "mention",
        4 | 8 => "announce",
        _ => "local",
    };
    Some((kind, db_id))
}

fn resolve_sid(status_id: i64, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>) -> Result<Option<Value>> {
    let Some((kind, db_id)) = parse_public_id(status_id) else {
        return Ok(None);
    };
    match kind {
        "announce" => bail!("PHP fallback: announce bookmark chrome remains on the canonical renderer"),
        "local" => {
            let Some(row) = cache.local_by_id.get(&db_id) else { return Ok(None); };
            if status_id >= 1_000_000_000_000_000_000 {
                let published = format_time(s(row, "published"), now);
                let secs = chrono::DateTime::parse_from_rfc3339(&published).map(|date| date.timestamp()).unwrap_or(0);
                let snow_secs = status_id / 1_000_000_000;
                if (secs - snow_secs).abs() > 180 {
                    return Ok(None);
                }
            }
            Ok(Some(from_local_row(row, cache, now)?))
        }
        "mention" => Ok(cache.mentions_by_id.get(&db_id).map(|row| from_mention_row(row, cache, now)).transpose()?),
        "event" => {
            let Some(row) = cache.events_by_id.get(&db_id) else { return Ok(None); };
            if deleted_event(row) {
                return Ok(None);
            }
            Ok(Some(from_event_row(row, cache, now)?))
        }
        _ => Ok(None),
    }
}

fn cold_activitypub_stub(row: &Value, object: &str, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>, flag: SavedFlag) -> Value {
    let actor = s(row, "target_actor").trim().trim_end_matches('/').to_owned();
    let account = if !actor.is_empty() {
        account_for(&actor, cache)
    } else {
        account_for(object, cache)
    };
    let href = esc(object);
    let status_id = s(row, "status_id");
    json!({
        "id": if status_id.is_empty() { "0" } else { status_id },
        "created_at": format_time(s(row, "created_at"), now),
        "in_reply_to_id": null,
        "in_reply_to_account_id": null,
        "sensitive": false,
        "spoiler_text": "",
        "visibility": "public",
        "language": null,
        "uri": object,
        "url": object,
        "replies_count": 0,
        "reblogs_count": 0,
        "favourites_count": 0,
        "edited_at": null,
        "favourited": flag == SavedFlag::Favourited,
        "reblogged": false,
        "muted": false,
        "bookmarked": flag == SavedFlag::Bookmarked,
        "pinned": false,
        "content": format!("<p><a href=\"{href}\">{href}</a></p>"),
        "reblog": null,
        "application": null,
        "account": account,
        "media_attachments": [],
        "mentions": [],
        "tags": [],
        "emojis": [],
        "card": null,
        "poll": null,
        "vaak_library_stub": true
    })
}

fn visible_body(status: &Value) -> bool {
    !super::source::thin(status)
}

/// PHP `ap_masto_interaction_row_to_status` for a bookmark row.
pub(crate) fn project_saved_bookmark(row: &Value, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>) -> Result<Option<Value>> {
    project_saved_interaction(row, cache, now, SavedFlag::Bookmarked)
}

/// PHP `ap_masto_interaction_row_to_status` for one saved flag.
/// `Ok(None)` omits the card (`at://` with no cached post, or a non-https object).
pub(crate) fn project_saved_interaction(row: &Value, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>, flag: SavedFlag) -> Result<Option<Value>> {
    let status_id_raw = s(row, "status_id").trim();
    if status_id_raw.starts_with("rss:") || s(row, "object_id").trim().starts_with("rss:") {
        bail!("PHP fallback: RSS bookmark remains on the canonical renderer");
    }
    let object = object_url(s(row, "object_id"));
    let sid = status_id_raw.parse::<i64>().unwrap_or(0);
    let mut status = if sid > 0 { resolve_sid(sid, cache, now)? } else { None };
    let sid_visible = status.as_ref().is_some_and(visible_body);
    if !sid_visible && object.starts_with("https://") {
        if let Some(fresh) = lookup_object(&object, cache, now)? {
            let mut source = json!({"id": status_id_raw});
            source[flag.field()] = json!(true);
            if status_id_raw.is_empty() {
                source["id"] = json!("0");
            }
            status = Some(super::source::preserve_saved(&source, fresh));
        }
    }
    if status.is_none() && object.starts_with("https://") {
        status = Some(cold_activitypub_stub(row, &object, cache, now, flag));
    }
    let Some(mut status) = status else { return Ok(None); };
    status[flag.field()] = json!(true);
    Ok(Some(status))
}

pub(crate) fn bookmark_path(status: &Value) -> BookmarkPath {
    if status.get("vaak_library_stub").and_then(Value::as_bool) == Some(true) || super::source::thin(status) {
        return BookmarkPath::Cold;
    }
    let uri = s(status, "uri");
    if uri.starts_with("at://") || s(status, "source") == "bluesky" || status.get("bsky_post").is_some_and(Value::is_object) {
        return BookmarkPath::Bluesky;
    }
    if uri.starts_with("https://mkultra.monster/users/") {
        return BookmarkPath::Local;
    }
    BookmarkPath::RemoteActivityPub
}

/// Cold https stubs PHP still paints. `at://` without a post is not a stub.
pub(crate) fn keep_cold_bookmark_stub(status: &Value) -> bool {
    if !super::source::thin(status) {
        return false;
    }
    if status.get("quote").or_else(|| status.get("reblog")).or_else(|| status.get("post")).is_some_and(Value::is_object) {
        return false;
    }
    let uri = if s(status, "uri").is_empty() { s(status, "url") } else { s(status, "uri") };
    if uri.starts_with("at://") {
        return false;
    }
    let plain = crate::home_hydrate_ranked::html_entity_decode(&regex::Regex::new(r"<[^>]*>").unwrap().replace_all(s(status, "content"), ""));
    if plain.contains('↪') {
        return false;
    }
    uri.starts_with("https://") || uri.starts_with("http://")
}

/// Plain bookmark-cache PostView. `viewer.bookmarked` is the saved flag.
pub(crate) fn project_bsky_bookmark(item: &Value) -> Result<Value> {
    project_bsky_saved(item, SavedFlag::Bookmarked)
}

/// Plain favourite-cache PostView. A non-empty `viewer.like` record is the like.
pub(crate) fn project_bsky_favourite(item: &Value) -> Result<Value> {
    project_bsky_saved(item, SavedFlag::Favourited)
}

/// Plain bookmark-cache or favourite-cache PostView, including a cold author
/// PHP's legacy card fills in. Structured embeds, replies, and reposts stay on PHP.
pub(crate) fn project_bsky_saved(item: &Value, flag: SavedFlag) -> Result<Value> {
    let post = &item["post"];
    let author = &post["author"];
    let record = &post["record"];
    let uri = s(post, "uri").trim();
    let did = s(author, "did").trim();
    if super::mixed::bsky_at_uri(uri).is_none() || did.is_empty() || !record.is_object() {
        bail!("PHP fallback: thin Bluesky shared author/PostView normalization");
    }
    if item.get("reason").is_some_and(|value| !value.is_null())
        || item.get("reply").is_some_and(|value| !value.is_null())
        || record.get("reply").is_some_and(|value| !value.is_null())
        || record.get("embed").is_some_and(|value| !value.is_null())
        || post.get("embed").is_some_and(|value| !value.is_null())
    {
        bail!("PHP fallback: Bluesky structured shared projection remains incomplete");
    }
    let text = php_trim(if s(record, "text").is_empty() { s(post, "text") } else { s(record, "text") });
    let mut handle = s(author, "handle").trim().to_owned();
    if handle.is_empty() {
        handle = did.to_owned();
    }
    let mut display = php_trim(s(author, "displayName")).to_owned();
    if display.is_empty() {
        display = if handle == did { "Bluesky user".into() } else { handle.clone() };
    }
    let created = if s(record, "createdAt").is_empty() { s(post, "indexedAt") } else { s(record, "createdAt") };
    if created.is_empty() {
        bail!("PHP fallback: Bluesky source needs effect-clock created_at");
    }
    let avatar = if s(author, "avatar").starts_with("http") {
        s(author, "avatar")
    } else {
        "https://mkultra.monster/img/avatar/default.webp"
    };
    let key = uri.rsplit('/').next().unwrap_or("");
    let account_url = format!("https://bsky.app/profile/{}", query(&handle));
    let sensitive = super::bsky::sensitive(post);
    let viewer = &post["viewer"];
    let bookmarked = viewer.get("bookmarked").and_then(Value::as_bool) == Some(true);
    // PHP treats only a non-empty like-record string as a like. Bookmark cards
    // keep the previous favourite bit off; the favourites view reads the record.
    let favourited = flag == SavedFlag::Favourited && !s(viewer, "like").is_empty();
    let mut status = json!({
        "id": uri,
        "url": format!("{account_url}/post/{}", query(key)),
        "uri": uri,
        "content": esc(text).replace('\n', "<br>\n"),
        "created_at": created,
        "visibility": "public",
        "language": "en",
        "account": {
            "id": did,
            "url": account_url,
            "username": handle,
            "acct": handle,
            "display_name": display,
            "avatar": avatar,
            "avatar_static": avatar,
            "uri": format!("https://bsky.app/profile/{did}")
        },
        "reblogs_count": super::card::integer(post, "repostCount"),
        "favourites_count": super::card::integer(post, "likeCount"),
        "replies_count": super::card::integer(post, "replyCount"),
        "media_attachments": [],
        "reblog": null,
        "quote": null,
        "source": "bluesky",
        "author_did": did,
        "bsky_post": post,
        "bsky_cid": s(post, "cid"),
        "sensitive": sensitive,
        "spoiler_text": if sensitive { "Sensitive content" } else { "" },
        "in_reply_to_id": null,
        "in_reply_to_account_id": null,
        "bookmarked": bookmarked,
        "favourited": favourited
    });
    if let Some(value) = item.get("_vaak_feed_source") {
        status["vaak_feed_source"] = value.clone();
    }
    Ok(status)
}

/// Cache lookup PHP runs before giving up and painting the URL stub.
/// A quote/announce/Ask hit is an error so the page stays on PHP.
/// A miss keeps the stub.
pub(crate) fn enrich_thin_bookmark(status: &Value, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>) -> Result<Option<Value>> {
    if !super::source::thin(status) {
        return Ok(None);
    }
    let object = object_url(if s(status, "uri").is_empty() { s(status, "url") } else { s(status, "uri") });
    if !object.starts_with("https://") {
        return Ok(None);
    }
    lookup_object(&object, cache, now)
}

pub(crate) async fn recover_thin_bookmarks(db: &Client, statuses: Vec<Value>) -> Result<Vec<Value>> {
    let objects: Vec<String> = statuses.iter().filter_map(thin_https_object).collect();
    if objects.is_empty() {
        return Ok(statuses);
    }
    let cache = load_bookmark_cache(db, &objects).await?;
    let now = chrono::Utc::now();
    let mut out = Vec::with_capacity(statuses.len());
    for status in statuses {
        if thin_https_object(&status).is_none() {
            out.push(status);
            continue;
        }
        match enrich_thin_bookmark(&status, &cache, now)? {
            Some(fresh) => out.push(super::source::preserve_saved(&status, fresh)),
            None => out.push(status),
        }
    }
    Ok(out)
}

fn thin_https_object(status: &Value) -> Option<String> {
    if !super::source::thin(status) {
        return None;
    }
    let object = object_url(if s(status, "uri").is_empty() { s(status, "url") } else { s(status, "uri") });
    object.starts_with("https://").then_some(object)
}

async fn optional_rows(db: &Client, sql: &str, params: &[&(dyn tokio_postgres::types::ToSql + Sync)]) -> Result<Vec<tokio_postgres::Row>> {
    match db.query(sql, params).await {
        Ok(rows) => Ok(rows),
        Err(error) if error.code() == Some(&tokio_postgres::error::SqlState::UNDEFINED_TABLE) => Ok(Vec::new()),
        Err(error) => Err(error).context("bookmark cache lookup"),
    }
}

fn remember_latest(map: &mut HashMap<String, Value>, key: String, row: Value) {
    if key.is_empty() {
        return;
    }
    if map.get(&key).is_some_and(|previous| n(previous, "id") >= n(&row, "id")) {
        return;
    }
    map.insert(key, row);
}

async fn load_bookmark_cache(db: &Client, objects: &[String]) -> Result<BookmarkCache> {
    let objects = objects.to_vec();
    let mut cache = BookmarkCache::default();
    for row in optional_rows(db, "SELECT to_jsonb(s) FROM masto_statuses s WHERE rtrim(s.note_id,'/') = ANY($1::text[])", &[&objects]).await? {
        let row: Value = row.get(0);
        let key = object_url(s(&row, "note_id"));
        let id = n(&row, "local_id");
        if !key.is_empty() {
            cache.local_by_note.insert(key, row.clone());
        }
        if id > 0 {
            cache.local_by_id.insert(id, row);
        }
    }
    let notes: Vec<String> = cache.local_by_note.keys().cloned().collect();
    if !notes.is_empty() {
        for row in optional_rows(
            db,
            "SELECT rtrim(id,'/'), content, in_reply_to, raw_create_json FROM outbox_notes WHERE rtrim(id,'/') = ANY($1::text[])",
            &[&notes],
        ).await? {
            let note: String = row.get(0);
            let content: Option<String> = row.get(1);
            let reply: Option<String> = row.get(2);
            let raw: Option<String> = row.get(3);
            let updated = cache.local_by_note.get_mut(&note).map(|local| {
                local["outbox_content"] = json!(content.unwrap_or_default());
                local["in_reply_to"] = json!(reply.unwrap_or_default());
                local["raw_create_json"] = json!(raw.unwrap_or_default());
                (n(local, "local_id"), local.clone())
            });
            if let Some((id, local)) = updated {
                if id > 0 {
                    cache.local_by_id.insert(id, local);
                }
            }
        }
        let ids: Vec<i64> = cache.local_by_id.keys().copied().collect();
        for row in optional_rows(db, "SELECT to_jsonb(m) FROM masto_media m WHERE status_local_id = ANY($1::bigint[]) ORDER BY local_id ASC", &[&ids]).await? {
            let row: Value = row.get(0);
            cache.media_by_local.entry(n(&row, "status_local_id")).or_default().push(row);
        }
        let keys: Vec<String> = cache.local_by_note.keys().filter_map(|note| local_actor_key(note).map(|key| key.to_lowercase())).collect();
        for row in optional_rows(db, "SELECT lower(actor_key), to_jsonb(p) FROM actor_profile p WHERE lower(actor_key) = ANY($1::text[])", &[&keys]).await? {
            let key: String = row.get(0);
            cache.profiles.insert(key, row.get(1));
        }
    }
    for row in optional_rows(
        db,
        "SELECT to_jsonb(e) FROM events e WHERE rtrim(split_part(object_id,'#',1),'/') = ANY($1::text[]) AND type IN ('Create','Update','Announce','Delete','Tombstone','Quote','QuotePost')",
        &[&objects],
    ).await? {
        let row: Value = row.get(0);
        let id = n(&row, "id");
        remember_latest(&mut cache.events_by_object, object_url(s(&row, "object_id")), row.clone());
        if id > 0 {
            cache.events_by_id.insert(id, row);
        }
    }
    for row in optional_rows(
        db,
        "SELECT to_jsonb(m) FROM mentions m WHERE deleted_at IS NULL AND rtrim(split_part(object_id,'#',1),'/') = ANY($1::text[])",
        &[&objects],
    ).await? {
        let row: Value = row.get(0);
        let id = n(&row, "id");
        remember_latest(&mut cache.mentions_by_object, object_url(s(&row, "object_id")), row.clone());
        if id > 0 {
            cache.mentions_by_id.insert(id, row);
        }
    }
    let mut actors = Vec::new();
    for row in cache.events_by_object.values().chain(cache.mentions_by_object.values()) {
        let actor = s(row, "actor_id").trim_end_matches('/').to_owned();
        if actor.starts_with("https://") && !actors.contains(&actor) {
            actors.push(actor);
        }
    }
    if !actors.is_empty() {
        for row in optional_rows(db, "SELECT to_jsonb(a) FROM remote_actors a WHERE rtrim(actor_id,'/') = ANY($1::text[])", &[&actors]).await? {
            let row: Value = row.get(0);
            let actor = s(&row, "actor_id").trim_end_matches('/').to_owned();
            if !actor.is_empty() {
                cache.actors.insert(actor, row);
            }
        }
    }
    Ok(cache)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::hidden::{ordinary_library_status_hidden, HiddenSets};
    use crate::library::card::{cw_gate, render, CardFacts};
    use crate::library::source::reject_thin_source;

    fn fixture() -> Value {
        serde_json::from_str(include_str!("../fixtures/library/bookmark-paths.json")).unwrap()
    }

    fn now() -> chrono::DateTime<chrono::Utc> {
        chrono::DateTime::parse_from_rfc3339("2024-07-01T00:00:00Z").unwrap().with_timezone(&chrono::Utc)
    }

    fn cache_from(local: Option<Value>, event: Option<Value>, actor: Option<Value>) -> BookmarkCache {
        let mut cache = BookmarkCache::default();
        if let Some(local) = local {
            cache.local_by_note.insert(object_url(s(&local, "note_id")), local.clone());
            cache.local_by_id.insert(n(&local, "local_id"), local);
        }
        if let Some(event) = event {
            cache.events_by_object.insert(object_url(s(&event, "object_id")), event.clone());
            cache.events_by_id.insert(n(&event, "id"), event);
        }
        if let Some(actor) = actor {
            cache.actors.insert(s(&actor, "actor_id").trim_end_matches('/').to_owned(), actor);
        }
        cache
    }

    fn facts(status: &Value, auto: bool) -> CardFacts {
        CardFacts {
            current_action_counts: None,
            bsky: None,
            owner_id: 1,
            owner_actor: "https://mkultra.monster/users/fixture".into(),
            return_view: "bookmarks".into(),
            auto_unblur_sensitive: auto,
            resolved_avatar: "https://mkultra.monster/img/avatar/default.webp".into(),
            csrf: "fixture-csrf-token".into(),
            favourited: false,
            bookmarked: status.get("bookmarked").and_then(Value::as_bool) == Some(true),
            boosted: false,
            own: None,
            rss_row: None,
            rss_quote_row: None,
            rss_quoted: false,
            emoji_by_actor: Default::default(),
            reply_mentions: Vec::new(),
            moderation: Default::default(),
            poll_row: None,
            ask_row: None,
            now: now(),
            lazy_media: true,
        }
    }

    #[test]
    fn fixture_covers_local_remote_bluesky_and_cold_bookmarks() {
        let fixture = fixture();
        let local_cache = cache_from(Some(fixture["local"]["local"].clone()), None, None);
        let local = project_saved_bookmark(&fixture["local"]["row"], &local_cache, now()).unwrap().unwrap();
        assert_eq!(bookmark_path(&local), BookmarkPath::Local);
        assert_eq!(local["visibility"], "unlisted");
        assert_eq!(local["bookmarked"], true);
        assert_ne!(local["id"], "42");
        assert_eq!(local["id"], crate::notif::snowflake_id(Some("2024-06-01T12:00:00.000Z"), 42, 0));
        assert!(local["content"].as_str().unwrap().contains("hello local bookmark"));
        assert!(local.get("vaak_library_stub").is_none());
        let local_html = render(&local, &facts(&local, false)).unwrap();
        assert!(local_html.contains("hello local bookmark"));
        assert!(local_html.contains("unbookmark_status"));
        assert!(local_html.contains("cw-gate"), "sensitive bookmarks stay behind the CW unless auto-unblur is on");
        let open_html = render(&local, &facts(&local, true)).unwrap();
        assert!(!open_html.contains("cw-gate"));
        assert_eq!(cw_gate("", true, "<div>body</div>", false).contains("cw-gate"), true);
        assert_eq!(cw_gate("", true, "<div>body</div>", true).contains("cw-gate"), false);

        let remote_cache = cache_from(None, Some(fixture["remote"]["event"].clone()), Some(fixture["remote"]["actor"].clone()));
        let remote = project_saved_bookmark(&fixture["remote"]["row"], &remote_cache, now()).unwrap().unwrap();
        assert_eq!(bookmark_path(&remote), BookmarkPath::RemoteActivityPub);
        assert_eq!(remote["id"], "77", "object lookup keeps the saved interaction id");
        assert_eq!(remote["bookmarked"], true);
        assert_eq!(remote["account"]["display_name"], "Alice Remote");
        assert_eq!(remote["account"]["acct"], "alice@remote.test");
        assert_eq!(remote["account"]["avatar"], "https://mkultra.monster/img/avatar/default.jpg");
        assert!(remote["content"].as_str().unwrap().contains("hello remote bookmark"));
        let remote_html = render(&remote, &facts(&remote, false)).unwrap();
        assert!(remote_html.contains("hello remote bookmark"));
        assert!(remote_html.contains("unbookmark_status"));

        let bsky = project_bsky_bookmark(&fixture["bluesky"]).unwrap();
        assert_eq!(bookmark_path(&bsky), BookmarkPath::Bluesky);
        assert_eq!(bsky["bookmarked"], true);
        assert!(bsky["content"].as_str().unwrap().contains("hello bluesky bookmark"));
        assert_eq!(bsky["account"]["avatar"], "https://cdn.example/alice.jpg");
        let bsky_html = render(&bsky, &facts(&bsky, false)).unwrap();
        assert!(bsky_html.contains("hello bluesky bookmark"));
        assert!(bsky_html.contains("unbookmark_status"));
        let mut unbooked = bsky.clone();
        unbooked["bookmarked"] = json!(false);
        assert!(render(&unbooked, &facts(&unbooked, false)).unwrap().contains("bookmark_status"));

        let cold_author = project_bsky_bookmark(&fixture["bluesky_cold"]).unwrap();
        assert_eq!(cold_author["account"]["display_name"], "cold.bsky.social");
        assert_eq!(cold_author["account"]["avatar"], "https://mkultra.monster/img/avatar/default.webp");
        assert!(cold_author["content"].as_str().unwrap().contains("cold bluesky body"));
        assert!(render(&cold_author, &facts(&cold_author, false)).unwrap().contains("cold bluesky body"));

        let cold = project_saved_bookmark(&fixture["cold"]["row"], &BookmarkCache::default(), now()).unwrap().unwrap();
        assert_eq!(bookmark_path(&cold), BookmarkPath::Cold);
        assert_eq!(cold["vaak_library_stub"], true);
        assert_eq!(cold["id"], "cold-1");
        assert_eq!(cold["sensitive"], false);
        assert_eq!(cold["bookmarked"], true);
        assert!(cold["content"].as_str().unwrap().contains("https://remote.test/users/alice/statuses/missing"));
        assert_eq!(cold["account"]["uri"], "https://remote.test/users/alice");
        let cold_html = render(&cold, &facts(&cold, false)).unwrap();
        assert!(cold_html.contains("https://remote.test/users/alice/statuses/missing"));
        assert!(cold_html.contains("unbookmark_status"));

        let bsky_stub = project_saved_bookmark(&fixture["cold_bsky_url"]["row"], &BookmarkCache::default(), now()).unwrap().unwrap();
        assert_eq!(bookmark_path(&bsky_stub), BookmarkPath::Cold);
        assert!(keep_cold_bookmark_stub(&bsky_stub));
        assert!(bsky_stub["content"].as_str().unwrap().contains("https://bsky.app/profile/did:plc:abc/post/xyz"));

        assert!(project_saved_bookmark(&fixture["at_only"]["row"], &BookmarkCache::default(), now()).unwrap().is_none());
        let mut http_row = fixture["cold"]["row"].clone();
        http_row["object_id"] = json!("http://remote.test/users/alice/statuses/1");
        assert!(project_saved_bookmark(&http_row, &BookmarkCache::default(), now()).unwrap().is_none());
    }

    #[test]
    fn cold_stubs_stay_on_saved_library_views() {
        let cold = project_saved_bookmark(&fixture()["cold"]["row"], &BookmarkCache::default(), now()).unwrap().unwrap();
        assert!(reject_thin_source(&cold, true).is_none(), "bookmarks and favourites keep the https stub");
        assert_eq!(
            reject_thin_source(&cold, false),
            Some("PHP fallback: thin AS2 source needs canonical cached/signed hydration (shared fetch budget 3)")
        );
        assert!(keep_cold_bookmark_stub(&cold));
        assert!(!keep_cold_bookmark_stub(&json!({"uri":"at://did:plc:abc/app.bsky.feed.post/xyz","content":"","account":{"acct":"?"}})));
        let mut quote = cold.clone();
        quote["quote"] = json!({"state":"pending"});
        assert!(!keep_cold_bookmark_stub(&quote));
    }

    #[test]
    fn cached_quote_announce_and_structured_bluesky_stay_on_php() {
        let fixture = fixture();
        let mut event = fixture["remote"]["event"].clone();
        event["summary"] = json!("hello ↪ QT there");
        let cache = cache_from(None, Some(event), None);
        let error = project_saved_bookmark(&fixture["remote"]["row"], &cache, now()).unwrap_err();
        assert!(error.to_string().contains("quote"), "{error}");

        let mut announce = fixture["remote"]["event"].clone();
        announce["type"] = json!("Announce");
        announce["summary"] = json!("");
        let cache = cache_from(None, Some(announce.clone()), None);
        let error = enrich_thin_bookmark(
            &cold_activitypub_stub(&fixture["remote"]["row"], "https://remote.test/users/alice/statuses/9", &cache, now(), SavedFlag::Bookmarked),
            &cache,
            now(),
        ).unwrap_err();
        assert!(error.to_string().contains("announce"), "{error}");

        let mut embed = fixture["bluesky"].clone();
        embed["post"]["embed"] = json!({"$type":"app.bsky.embed.images"});
        assert!(project_bsky_bookmark(&embed).unwrap_err().to_string().contains("structured"));
        let mut clock = fixture["bluesky"].clone();
        clock["post"]["record"]["createdAt"] = json!("");
        clock["post"]["indexedAt"] = json!("");
        assert!(project_bsky_bookmark(&clock).unwrap_err().to_string().contains("created_at"));

        let mut local = fixture["local"]["local"].clone();
        local["content_text"] = json!("(media)");
        let cache = cache_from(Some(local), None, None);
        assert!(project_saved_bookmark(&fixture["local"]["row"], &cache, now()).unwrap_err().to_string().contains("media"));
    }

    #[test]
    fn thin_cache_hit_keeps_the_saved_bookmark_id() {
        let fixture = fixture();
        let cache = cache_from(None, Some(fixture["remote"]["event"].clone()), Some(fixture["remote"]["actor"].clone()));
        let stub = cold_activitypub_stub(&fixture["remote"]["row"], "https://remote.test/users/alice/statuses/9", &BookmarkCache::default(), now(), SavedFlag::Bookmarked);
        let fresh = enrich_thin_bookmark(&stub, &cache, now()).unwrap().unwrap();
        let saved = super::super::source::preserve_saved(&stub, fresh);
        assert_eq!(saved["id"], "77");
        assert_eq!(saved["bookmarked"], true);
        assert!(saved.get("vaak_library_stub").is_none());
        assert!(saved["content"].as_str().unwrap().contains("hello remote bookmark"));
    }

    #[test]
    fn privacy_and_muted_words_still_apply_to_every_bookmark_path() {
        let fixture = fixture();
        let cold = project_saved_bookmark(&fixture["cold"]["row"], &BookmarkCache::default(), now()).unwrap().unwrap();
        let local_cache = cache_from(Some(fixture["local"]["local"].clone()), None, None);
        let local = project_saved_bookmark(&fixture["local"]["row"], &local_cache, now()).unwrap().unwrap();
        let remote_cache = cache_from(None, Some(fixture["remote"]["event"].clone()), Some(fixture["remote"]["actor"].clone()));
        let remote = project_saved_bookmark(&fixture["remote"]["row"], &remote_cache, now()).unwrap().unwrap();
        let bsky = project_bsky_bookmark(&fixture["bluesky"]).unwrap();
        let open = HiddenSets::default();
        for status in [&cold, &local, &remote, &bsky] {
            assert!(!ordinary_library_status_hidden(status, &open), "{status}");
        }

        let mut blocked = HiddenSets::default();
        blocked.user_blocked_actors.insert(fixture["privacy"]["blocked_actor"].as_str().unwrap().to_owned());
        assert!(ordinary_library_status_hidden(&cold, &blocked));
        assert!(ordinary_library_status_hidden(&remote, &blocked));
        assert!(!ordinary_library_status_hidden(&local, &blocked));

        let mut domain = HiddenSets::default();
        domain.user_blocked_domains.insert(fixture["privacy"]["blocked_domain"].as_str().unwrap().to_owned());
        let mut subdomain = cold.clone();
        subdomain["uri"] = json!("https://sub.remote.test/users/alice/statuses/missing");
        subdomain["url"] = subdomain["uri"].clone();
        subdomain["account"]["uri"] = json!("https://sub.remote.test/users/alice");
        assert!(ordinary_library_status_hidden(&subdomain, &domain));
        assert!(!ordinary_library_status_hidden(&local, &domain));

        let phrase = fixture["privacy"]["phrase"].as_str().unwrap();
        let mut words = HiddenSets::default();
        words.muted_phrases.push(phrase.into());
        let mut muttered = cold.clone();
        muttered["content"] = json!(format!("<p>see {phrase} please</p>"));
        assert!(ordinary_library_status_hidden(&muttered, &words));
        let own_note = fixture["privacy"]["own_note"].as_str().unwrap();
        let own_actor = fixture["privacy"]["own_actor"].as_str().unwrap();
        let mut own = muttered.clone();
        own["uri"] = json!(own_note);
        own["account"]["uri"] = json!(own_actor);
        assert!(ordinary_library_status_hidden(&own, &words));
        words.ordinary_owned_notes.insert(own_note.trim_end_matches('/').to_owned(), own_actor.trim_end_matches('/').to_owned());
        assert!(!ordinary_library_status_hidden(&own, &words), "own-note word exemption stays on the ordinary library surface");

        let mut bsky_hidden = HiddenSets::default();
        bsky_hidden.muted_actors.insert("did:plc:abc".into());
        assert!(ordinary_library_status_hidden(&bsky, &bsky_hidden));
    }
}
