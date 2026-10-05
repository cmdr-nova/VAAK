//! Home hydrate from ranked IDs (Rust-native).
//!
//! Walks `vaak:timeline:ranked:v2:*` for an owner, materializes Mastodon-shaped
//! status JSON for rss / bsky / event / outbox (and Announce→reblog), then writes
//! PHP-compatible `vaak:timeline:v1:*` envelopes for limits 15/40/80.
//!
//! Replaces `bin/home-timeline-warm.php` → chronological `ap_masto_timeline_home_merged`
//! which dropped the ranked RSS/Bluesky mix (Home Axum HTML/API showed fedi-only).

use std::collections::HashMap;
use std::time::Instant;

use anyhow::{Context, Result};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use tokio_postgres::Client;

use crate::config::Config;
use crate::redis_util;
use crate::timeline;

const HOME_HYDRATE_TTL_SECS: u64 = 300;
const DEFAULT_AVATAR: &str = "https://mkultra.monster/img/avatar/default.jpg";
const DEFAULT_LIMITS: [i64; 3] = [15, 40, 80];

#[derive(Debug, Clone)]
pub struct WarmReport {
    pub owner_user_id: i64,
    pub ranked_n: usize,
    pub materialised_n: usize,
    pub stored: Vec<(i64, usize)>,
    pub kinds: HashMap<String, usize>,
    pub ms: u128,
    pub source: &'static str,
    pub note: String,
}

fn env_flag_default_true(name: &str) -> bool {
    match std::env::var(name) {
        Ok(v) if !v.trim().is_empty() => {
            !matches!(
                v.trim().to_ascii_lowercase().as_str(),
                "0" | "false" | "off" | "no"
            )
        }
        _ => true,
    }
}

fn esc(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#39;"),
            _ => out.push(c),
        }
    }
    out
}

fn plain_to_html(plain: &str) -> String {
    let t = plain.trim();
    if t.is_empty() {
        return String::new();
    }
    format!("<p>{}</p>", esc(t).replace('\n', "<br>"))
}

fn truncate_chars(s: &str, max: usize) -> String {
    let mut out = String::new();
    for (i, c) in s.chars().enumerate() {
        if i >= max {
            out.push('…');
            break;
        }
        out.push(c);
    }
    out
}

fn format_time(raw: &str) -> String {
    let raw = raw.trim();
    if raw.is_empty() {
        return chrono::Utc::now().format("%Y-%m-%dT%H:%M:%S.000Z").to_string();
    }
    if let Ok(dt) = chrono::DateTime::parse_from_rfc3339(raw) {
        return dt
            .with_timezone(&chrono::Utc)
            .format("%Y-%m-%dT%H:%M:%S.000Z")
            .to_string();
    }
    // Postgres timestamptz often comes as "2026-10-05 08:16:22+02"
    let cleaned = raw.replace(' ', "T");
    if let Ok(dt) = chrono::DateTime::parse_from_rfc3339(&cleaned) {
        return dt
            .with_timezone(&chrono::Utc)
            .format("%Y-%m-%dT%H:%M:%S.000Z")
            .to_string();
    }
    if let Ok(dt) = chrono::DateTime::parse_from_str(raw, "%Y-%m-%d %H:%M:%S%z") {
        return dt
            .with_timezone(&chrono::Utc)
            .format("%Y-%m-%dT%H:%M:%S.000Z")
            .to_string();
    }
    if let Ok(dt) = chrono::DateTime::parse_from_str(&format!("{raw}+00"), "%Y-%m-%d %H:%M:%S%z") {
        return dt
            .with_timezone(&chrono::Utc)
            .format("%Y-%m-%dT%H:%M:%S.000Z")
            .to_string();
    }
    raw.to_string()
}

fn snowflake_id(iso: &str, db_id: i64, type_code: i64) -> String {
    let t = chrono::DateTime::parse_from_rfc3339(&format_time(iso))
        .map(|d| d.timestamp())
        .or_else(|_| {
            chrono::DateTime::parse_from_str(iso, "%Y-%m-%d %H:%M:%S%z").map(|d| d.timestamp())
        })
        .unwrap_or_else(|_| chrono::Utc::now().timestamp());
    let id = t * 1_000_000_000 + ((type_code % 10) * 100_000_000) + (db_id % 100_000_000);
    id.to_string()
}

fn announce_inner_synth_id(iso: &str, object_id: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(object_id.trim_end_matches('/').as_bytes());
    let hex = hex::encode(hasher.finalize());
    let slot = i64::from_str_radix(&hex[..8], 16).unwrap_or(0).abs() % 100_000_000;
    snowflake_id(iso, slot, 8)
}

fn host_from_url(url: &str) -> String {
    let lower = url.trim();
    let rest = lower
        .strip_prefix("https://")
        .or_else(|| lower.strip_prefix("http://"))
        .unwrap_or("");
    let host = rest.split(['/', '?', '#']).next().unwrap_or("");
    let host = host.split('@').next_back().unwrap_or(host);
    let host = host.split(':').next().unwrap_or(host);
    host.to_ascii_lowercase()
}

fn favicon_for_url(url: &str) -> String {
    let host = host_from_url(url);
    let host = host.strip_prefix("www.").unwrap_or(&host);
    if host.is_empty()
        || !host
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '.' || c == '-')
    {
        return String::new();
    }
    format!("https://icons.duckduckgo.com/ip3/{host}.ico")
}

fn upgrade_rss_image(url: &str) -> String {
    let url = url.trim();
    if !url.to_ascii_lowercase().starts_with("https://") {
        return String::new();
    }
    if let Some(caps) = regex_lite_preview_redd(url) {
        return format!("https://i.redd.it/{}{}", caps.0, caps.1);
    }
    url.to_string()
}

fn regex_lite_preview_redd(url: &str) -> Option<(String, String)> {
    // preview.redd.it/<id>.<ext>?… → i.redd.it/<id>.<ext>
    let lower = url.to_ascii_lowercase();
    if !lower.contains("preview.redd.it/") {
        return None;
    }
    let after = url.split("preview.redd.it/").nth(1)?;
    let name = after.split(['?', '#']).next()?;
    let (id, ext) = name.split_once('.')?;
    if id.is_empty() || ext.is_empty() {
        return None;
    }
    if !id.chars().all(|c| c.is_ascii_alphanumeric()) {
        return None;
    }
    Some((id.to_string(), format!(".{ext}")))
}

fn rss_acct(feed_title: &str) -> String {
    let lower = feed_title.to_ascii_lowercase();
    let cleaned: String = lower
        .chars()
        .filter(|c| c.is_ascii_alphanumeric() || *c == '.' || *c == '_' || *c == '-')
        .collect();
    let trimmed = cleaned.chars().take(30).collect::<String>();
    if trimmed.is_empty() {
        "rss".into()
    } else {
        trimmed
    }
}

fn empty_account(id: &str, username: &str, acct: &str, display: &str, url: &str, avatar: &str) -> Value {
    let av = if avatar.starts_with("https://") {
        avatar
    } else {
        DEFAULT_AVATAR
    };
    json!({
        "id": id,
        "username": username,
        "acct": acct,
        "display_name": display,
        "locked": false,
        "bot": false,
        "discoverable": false,
        "group": false,
        "created_at": chrono::Utc::now().format("%Y-%m-%dT%H:%M:%S.000Z").to_string(),
        "note": "",
        "url": url,
        "uri": url,
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

fn base_status(
    id: &str,
    created_at: &str,
    content: &str,
    uri: &str,
    url: &str,
    account: Value,
    media: Vec<Value>,
    card: Option<Value>,
) -> Value {
    let mut st = json!({
        "id": id,
        "created_at": created_at,
        "in_reply_to_id": Value::Null,
        "in_reply_to_account_id": Value::Null,
        "sensitive": false,
        "spoiler_text": "",
        "visibility": "public",
        "language": Value::Null,
        "uri": uri,
        "url": url,
        "replies_count": 0,
        "reblogs_count": 0,
        "favourites_count": 0,
        "edited_at": Value::Null,
        "favourited": false,
        "reblogged": false,
        "muted": false,
        "bookmarked": false,
        "pinned": false,
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
    if let Some(c) = card {
        st["card"] = c;
    }
    st
}

fn media_from_urls(urls_json: &str, status_id: &str) -> Vec<Value> {
    let Ok(decoded) = serde_json::from_str::<Value>(urls_json) else {
        return Vec::new();
    };
    let Some(arr) = decoded.as_array() else {
        return Vec::new();
    };
    let mut out = Vec::new();
    for (i, u) in arr.iter().enumerate() {
        if out.len() >= 4 {
            break;
        }
        let clean = u.as_str().unwrap_or("").trim();
        if !clean.to_ascii_lowercase().starts_with("https://") {
            continue;
        }
        let path = clean
            .split(['?', '#'])
            .next()
            .unwrap_or(clean)
            .to_ascii_lowercase();
        let is_video = [".mp4", ".webm", ".mov", ".m4v", ".m3u8"]
            .iter()
            .any(|ext| path.ends_with(ext));
        let mtype = if is_video { "video" } else { "image" };
        out.push(json!({
            "id": format!("{status_id}{}", i + 1),
            "type": mtype,
            "url": clean,
            "preview_url": clean,
            "remote_url": clean,
            "description": Value::Null,
            "blurhash": Value::Null,
        }));
    }
    out
}

fn bsky_https_url(at_uri: &str, handle: &str) -> String {
    let actor = if !handle.is_empty() {
        handle
    } else if let Some(did) = at_uri.strip_prefix("at://") {
        did.split('/').next().unwrap_or(at_uri)
    } else {
        at_uri
    };
    let rkey = at_uri
        .rsplit('/')
        .next()
        .filter(|s| !s.is_empty() && *s != at_uri)
        .unwrap_or("");
    if rkey.is_empty() || at_uri.matches('/').count() < 3 {
        return format!("https://bsky.app/profile/{actor}");
    }
    format!("https://bsky.app/profile/{actor}/post/{rkey}")
}

fn bsky_media_and_card(embed_json: &str, status_id: &str) -> (Vec<Value>, Option<Value>) {
    let Ok(embed) = serde_json::from_str::<Value>(embed_json) else {
        return (Vec::new(), None);
    };
    if !embed.is_object() {
        return (Vec::new(), None);
    }
    let etype = embed
        .get("$type")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .to_ascii_lowercase();
    let mut sources = vec![&embed];
    if etype.contains("recordwithmedia") {
        if let Some(media) = embed.get("media") {
            sources.push(media);
        }
    }

    let mut media = Vec::new();
    let mut card = None;

    for source in sources {
        if media.len() >= 4 {
            break;
        }
        let image_list = source
            .get("items")
            .and_then(|v| v.as_array())
            .filter(|a| !a.is_empty())
            .or_else(|| source.get("images").and_then(|v| v.as_array()));
        if let Some(list) = image_list {
            for image in list {
                if media.len() >= 4 {
                    break;
                }
                let url = image
                    .get("fullsize")
                    .or_else(|| image.get("url"))
                    .or_else(|| image.get("thumb"))
                    .and_then(|v| v.as_str())
                    .unwrap_or("")
                    .trim();
                if !url.starts_with("https://") {
                    continue;
                }
                let preview = image
                    .get("thumb")
                    .or_else(|| image.get("thumbnail"))
                    .and_then(|v| v.as_str())
                    .unwrap_or(url);
                media.push(json!({
                    "id": format!("{status_id}#media-{}", media.len() + 1),
                    "type": "image",
                    "url": url,
                    "preview_url": preview,
                    "remote_url": url,
                    "description": image.get("alt").and_then(|v| v.as_str()).unwrap_or(""),
                    "blurhash": Value::Null,
                }));
            }
        }

        let video = source.get("video").unwrap_or(source);
        let playlist = video
            .get("playlist")
            .or_else(|| video.get("url"))
            .and_then(|v| v.as_str())
            .unwrap_or("")
            .trim();
        let src_type = source
            .get("$type")
            .and_then(|v| v.as_str())
            .unwrap_or(&etype)
            .to_ascii_lowercase();
        if playlist.starts_with("https://")
            && (src_type.contains("video")
                || source.get("playlist").is_some()
                || source.get("video").is_some())
        {
            let preview = video
                .get("thumbnail")
                .or_else(|| video.get("thumb"))
                .and_then(|v| v.as_str())
                .unwrap_or("");
            media.push(json!({
                "id": format!("{status_id}#media-{}", media.len() + 1),
                "type": "video",
                "url": playlist,
                "preview_url": if preview.starts_with("https://") { Value::String(preview.into()) } else { Value::Null },
                "remote_url": playlist,
                "description": "",
                "blurhash": Value::Null,
            }));
        }

        if let Some(ext) = source.get("external").filter(|v| v.is_object()) {
            let uri = ext.get("uri").and_then(|v| v.as_str()).unwrap_or("").trim();
            if !uri.starts_with("http") {
                continue;
            }
            let looks_media = regex::Regex::new(r"(?i)\.(gif|png|jpe?g|webp|mp4|webm)(\?|#|$)")
                .ok()
                .map(|re| re.is_match(uri))
                .unwrap_or(false)
                || uri.contains("tenor.com/")
                || uri.contains("giphy.com/")
                || uri.contains("klipy.com/");
            if looks_media {
                let thumb = ext.get("thumb").and_then(|v| v.as_str()).unwrap_or("");
                media.push(json!({
                    "id": format!("{status_id}#media-{}", media.len() + 1),
                    "type": "gifv",
                    "url": uri,
                    "preview_url": if thumb.starts_with("https://") { thumb } else { uri },
                    "remote_url": uri,
                    "description": ext.get("title").and_then(|v| v.as_str()).unwrap_or("GIF"),
                    "blurhash": Value::Null,
                }));
            } else if card.is_none() && media.is_empty() {
                let host = host_from_url(uri);
                let title = ext
                    .get("title")
                    .and_then(|v| v.as_str())
                    .map(str::trim)
                    .filter(|s| !s.is_empty())
                    .unwrap_or(&host);
                let desc = ext
                    .get("description")
                    .and_then(|v| v.as_str())
                    .unwrap_or("");
                let thumb = ext.get("thumb").and_then(|v| v.as_str()).unwrap_or("");
                card = Some(json!({
                    "url": uri,
                    "title": title,
                    "description": truncate_chars(desc, 280),
                    "image": if thumb.starts_with("https://") { Value::String(thumb.into()) } else { Value::Null },
                    "type": "link",
                    "provider_name": host,
                }));
            }
        }
    }

    (media, card)
}

fn ranked_redis_key(logical: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(logical.as_bytes());
    format!("vaak:timeline:ranked:v2:{}", hex::encode(hasher.finalize()))
}

fn pick_home_logical(index: Option<&Value>) -> Option<String> {
    let arr = index?.as_array()?;
    let mut first = None;
    for v in arr {
        let Some(s) = v.as_str() else { continue };
        if first.is_none() {
            first = Some(s.to_string());
        }
        if s.contains("home") {
            return Some(s.to_string());
        }
    }
    first
}

struct RankedEntry {
    kind: String,
    id: String,
}

struct RssRow {
    id: i64,
    feed_id: i64,
    url: String,
    title: String,
    summary_text: String,
    image_url: String,
    published_at: String,
    feed_title: String,
    feed_favicon: String,
    feed_site_url: String,
    feed_url: String,
}

struct BskyRow {
    uri: String,
    author_did: String,
    author_handle: String,
    author_display: String,
    author_avatar: String,
    indexed_at: String,
    published_at: String,
    text: String,
    embed_json: String,
    raw_json: String,
    like_count: i64,
    repost_count: i64,
    reply_count: i64,
}

const BSKY_SENSITIVE_LABELS: &[&str] = &[
    "porn",
    "sexual",
    "nudity",
    "graphic-media",
    "sexual-cartoon",
    "sexual-figurative",
    "nsfw",
    "self-harm",
    "sensitive",
];

fn bsky_label_vals_from_json(raw: &Value) -> Vec<String> {
    let mut out = Vec::new();
    let mut push_list = |labels: &Value| {
        let list = if let Some(values) = labels.get("values").and_then(|v| v.as_array()) {
            values.as_slice()
        } else if let Some(arr) = labels.as_array() {
            arr.as_slice()
        } else {
            return;
        };
        for lab in list {
            let val = if let Some(s) = lab.as_str() {
                s.to_ascii_lowercase()
            } else {
                lab.get("val")
                    .and_then(|v| v.as_str())
                    .unwrap_or("")
                    .to_ascii_lowercase()
            };
            let val = val.trim().to_string();
            if !val.is_empty() && !out.iter().any(|x| x == &val) {
                out.push(val);
            }
        }
    };
    if let Some(labels) = raw.get("labels") {
        push_list(labels);
    }
    if let Some(record) = raw.get("record") {
        if let Some(labels) = record
            .get("labels")
            .or_else(|| record.get("selfLabels"))
        {
            push_list(labels);
        }
    }
    out
}

fn bsky_is_sensitive(raw_json: &str) -> bool {
    let Ok(raw) = serde_json::from_str::<Value>(raw_json) else {
        return false;
    };
    let sens: std::collections::HashSet<&str> = BSKY_SENSITIVE_LABELS.iter().copied().collect();
    bsky_label_vals_from_json(&raw)
        .iter()
        .any(|v| sens.contains(v.as_str()))
}

fn bsky_cid_from_raw(raw_json: &str) -> String {
    serde_json::from_str::<Value>(raw_json)
        .ok()
        .and_then(|v| {
            v.get("cid")
                .and_then(|c| c.as_str())
                .map(str::to_string)
        })
        .unwrap_or_default()
}

fn bsky_viewer_flags(raw_json: &str) -> (bool, bool, bool, String, String) {
    let Ok(raw) = serde_json::from_str::<Value>(raw_json) else {
        return (false, false, false, String::new(), String::new());
    };
    let viewer = raw.get("viewer").cloned().unwrap_or(Value::Null);
    let like = viewer
        .get("like")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .to_string();
    let repost = viewer
        .get("repost")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .to_string();
    let bookmarked = viewer
        .get("bookmarked")
        .and_then(|v| v.as_bool())
        .unwrap_or(false)
        || viewer
            .get("bookmark")
            .and_then(|v| v.as_bool())
            .unwrap_or(false)
        || viewer.get("bookmark").and_then(|v| v.as_str()).is_some();
    (!like.is_empty(), !repost.is_empty(), bookmarked, like, repost)
}

#[derive(Clone)]
struct EventRow {
    id: i64,
    event_type: String,
    actor_id: String,
    object_id: String,
    summary: String,
    media_urls: String,
    created_at: String,
    sensitive: bool,
    spoiler_text: String,
    host: String,
    target_actor: String,
}

struct ActorRow {
    actor_id: String,
    username: String,
    display_name: String,
    host: String,
    icon: String,
}

struct OutboxRow {
    id: String,
    published: String,
    content: String,
}

fn materialize_rss(row: &RssRow) -> Value {
    let status_id = format!("rss:{}", row.id);
    let feed_title = if row.feed_title.trim().is_empty() {
        "RSS"
    } else {
        row.feed_title.trim()
    };
    let title = row.title.trim();
    let summary = row.summary_text.trim();
    let mut body_parts = Vec::new();
    if !title.is_empty() {
        body_parts.push(title.to_string());
    }
    if !summary.is_empty() && summary != title {
        body_parts.push(truncate_chars(summary, 500));
    }
    let plain = body_parts.join("\n\n");
    let content = plain_to_html(&plain);
    let image = upgrade_rss_image(&row.image_url);
    let url = row.url.trim();
    let media_forward = !image.is_empty() && summary.is_empty();
    let mut media = Vec::new();
    if media_forward {
        media.push(json!({
            "id": format!("{status_id}-img"),
            "type": "image",
            "url": image,
            "preview_url": image,
            "remote_url": image,
            "mediaType": "image/*",
            "description": Value::Null,
            "blurhash": Value::Null,
        }));
    }
    let card = if media.is_empty() && url.starts_with("http") {
        let host = host_from_url(url);
        Some(json!({
            "url": url,
            "title": if title.is_empty() { host.clone() } else { title.to_string() },
            "description": summary,
            "type": "link",
            "author_name": feed_title,
            "author_url": "",
            "provider_name": host,
            "provider_url": if host.is_empty() { String::new() } else { format!("https://{host}/") },
            "html": "",
            "width": 0,
            "height": 0,
            "image": if image.starts_with("https://") { Value::String(image.clone()) } else { Value::Null },
            "embed_url": "",
            "blurhash": Value::Null,
        }))
    } else {
        None
    };
    let link_fav = favicon_for_url(url);
    let avatar = if link_fav.starts_with("http") {
        link_fav
    } else if row.feed_favicon.starts_with("http") {
        row.feed_favicon.clone()
    } else {
        DEFAULT_AVATAR.into()
    };
    let acct = rss_acct(feed_title);
    let account_url = if !row.feed_site_url.is_empty() {
        row.feed_site_url.as_str()
    } else {
        row.feed_url.as_str()
    };
    let mut account = empty_account(
        &format!("rss-feed:{}", row.feed_id),
        &acct,
        feed_title,
        feed_title,
        account_url,
        &avatar,
    );
    account["bot"] = json!(true);
    account["note"] = json!("RSS feed");
    account["uri"] = json!(row.feed_url);
    let created = format_time(if row.published_at.is_empty() {
        ""
    } else {
        &row.published_at
    });
    let uri = if url.is_empty() { status_id.as_str() } else { url };
    let mut st = base_status(
        &status_id,
        &created,
        &content,
        uri,
        uri,
        account,
        media,
        card,
    );
    st["application"] = json!({"name": "RSS", "website": Value::Null});
    st["tags"] = json!([{"name": "RSS", "url": ""}]);
    st["vaak_rss_item_id"] = json!(row.id);
    st["vaak_rss_feed_id"] = json!(row.feed_id);
    st["source"] = json!("rss");
    st
}

fn materialize_bsky(row: &BskyRow) -> Value {
    let handle = row.author_handle.trim();
    let did = row.author_did.trim();
    let display = if row.author_display.trim().is_empty() {
        if handle.is_empty() {
            did
        } else {
            handle
        }
    } else {
        row.author_display.trim()
    };
    let acct = if handle.is_empty() { did } else { handle };
    let username = acct;
    let profile_url = if handle.is_empty() {
        format!("https://bsky.app/profile/{did}")
    } else {
        format!("https://bsky.app/profile/{handle}")
    };
    let https_url = bsky_https_url(&row.uri, handle);
    let created = format_time(if !row.published_at.is_empty() {
        &row.published_at
    } else {
        &row.indexed_at
    });
    let (media, card) = bsky_media_and_card(&row.embed_json, &row.uri);
    // Keep body as plain-ish HTML paragraphs; lean paint linkifies @/#/URLs.
    let content = plain_to_html(row.text.replace("\r\n", "\n").trim());
    let account = empty_account(did, username, acct, display, &profile_url, &row.author_avatar);
    // Canonical status_id matches PHP ap_masto_canonical_interaction_keys (bsky:<sha256[:32]>).
    // Keep uri as at:// for Bluesky actions; url as https permalink for object_id parity.
    let mut hasher = Sha256::new();
    hasher.update(row.uri.as_bytes());
    let bsky_sid = format!(
        "bsky:{}",
        &hex::encode(hasher.finalize())[..32]
    );
    let mut st = base_status(
        &bsky_sid,
        &created,
        &content,
        &row.uri,
        &https_url,
        account,
        media,
        card,
    );
    st["reblogs_count"] = json!(row.repost_count);
    st["favourites_count"] = json!(row.like_count);
    st["replies_count"] = json!(row.reply_count);
    st["source"] = json!("bluesky");
    st["author_did"] = json!(did);
    st["language"] = json!("en");
    let cid = bsky_cid_from_raw(&row.raw_json);
    if !cid.is_empty() {
        st["bsky_cid"] = json!(cid);
    }
    let (liked, reposted, bookmarked, like_rec, repost_rec) = bsky_viewer_flags(&row.raw_json);
    st["favourited"] = json!(liked);
    st["reblogged"] = json!(reposted);
    st["bookmarked"] = json!(bookmarked);
    if !like_rec.is_empty() {
        st["vaak_bsky_like_record"] = json!(like_rec);
    }
    if !repost_rec.is_empty() {
        st["vaak_bsky_repost_record"] = json!(repost_rec);
    }
    if bsky_is_sensitive(&row.raw_json) {
        st["sensitive"] = json!(true);
        if st
            .get("spoiler_text")
            .and_then(|v| v.as_str())
            .unwrap_or("")
            .trim()
            .is_empty()
        {
            st["spoiler_text"] = json!("Sensitive content");
        }
        st["vaak_bsky_sensitive"] = json!(true);
    }
    st
}

fn actor_to_account(actor: &ActorRow) -> Value {
    let host = if actor.host.is_empty() {
        host_from_url(&actor.actor_id)
    } else {
        actor.host.clone()
    };
    let username = if actor.username.is_empty() {
        actor
            .actor_id
            .rsplit('/')
            .next()
            .unwrap_or("unknown")
            .to_string()
    } else {
        actor.username.clone()
    };
    let acct = if host.is_empty() {
        username.clone()
    } else {
        format!("{username}@{host}")
    };
    let display = if actor.display_name.trim().is_empty() {
        username.as_str()
    } else {
        actor.display_name.trim()
    };
    empty_account(
        &actor.actor_id,
        &username,
        &acct,
        display,
        &actor.actor_id,
        &actor.icon,
    )
}

fn materialize_event_create(row: &EventRow, actor: Option<&ActorRow>) -> Value {
    let created = format_time(&row.created_at);
    let status_id = snowflake_id(&created, row.id, 1);
    let account = if let Some(a) = actor {
        actor_to_account(a)
    } else {
        let host = if row.host.is_empty() {
            host_from_url(&row.actor_id)
        } else {
            row.host.clone()
        };
        let username = row
            .actor_id
            .rsplit('/')
            .next()
            .unwrap_or("unknown")
            .to_string();
        let acct = if host.is_empty() {
            username.clone()
        } else {
            format!("{username}@{host}")
        };
        empty_account(
            &row.actor_id,
            &username,
            &acct,
            &username,
            &row.actor_id,
            DEFAULT_AVATAR,
        )
    };
    let mut text = html_entity_decode(&row.summary);
    if text.contains('<') {
        text = strip_tags_simple(&text);
    }
    if matches!(
        text.trim(),
        "(attachment)" | "(media)" | "(poll)" | "(quote)" | "(boost)"
    ) && !row.media_urls.is_empty()
        && row.media_urls != "[]"
    {
        text.clear();
    }
    let content = plain_to_html(text.trim());
    let media = media_from_urls(&row.media_urls, &status_id);
    let uri = if row.object_id.is_empty() {
        row.actor_id.as_str()
    } else {
        row.object_id.as_str()
    };
    let mut st = base_status(&status_id, &created, &content, uri, uri, account, media, None);
    st["sensitive"] = json!(row.sensitive || !row.spoiler_text.trim().is_empty());
    st["spoiler_text"] = json!(row.spoiler_text);
    st
}

fn materialize_outbox(row: &OutboxRow, owner_username: &str) -> Value {
    let created = format_time(&row.published);
    // Local note snowflake type 0 — use a stable hash slot from the note id.
    let mut hasher = Sha256::new();
    hasher.update(row.id.as_bytes());
    let hex = hex::encode(hasher.finalize());
    let slot = i64::from_str_radix(&hex[..8], 16).unwrap_or(0).abs() % 100_000_000;
    let status_id = snowflake_id(&created, slot, 0);
    let actor_url = row
        .id
        .rsplit_once("/notes/")
        .map(|(p, _)| p.to_string())
        .unwrap_or_else(|| format!("https://mkultra.monster/users/{owner_username}"));
    let username = actor_url
        .rsplit('/')
        .next()
        .unwrap_or(owner_username)
        .to_string();
    let account = empty_account(
        &actor_url,
        &username,
        &username,
        &username,
        &actor_url,
        DEFAULT_AVATAR,
    );
    base_status(
        &status_id,
        &created,
        &row.content,
        &row.id,
        &row.id,
        account,
        Vec::new(),
        None,
    )
}

fn strip_tags_simple(html: &str) -> String {
    let mut out = String::with_capacity(html.len());
    let mut in_tag = false;
    for c in html.chars() {
        match c {
            '<' => in_tag = true,
            '>' => in_tag = false,
            _ if !in_tag => out.push(c),
            _ => {}
        }
    }
    out.replace("&nbsp;", " ")
        .replace("&amp;", "&")
        .replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&quot;", "\"")
        .replace("&#39;", "'")
}

fn html_entity_decode(s: &str) -> String {
    s.replace("&amp;", "&")
        .replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&quot;", "\"")
        .replace("&#39;", "'")
        .replace("&nbsp;", " ")
}

fn link_header(statuses: &[Value], limit: i64) -> String {
    if statuses.is_empty() {
        return String::new();
    }
    let first = statuses[0]
        .get("id")
        .and_then(|v| v.as_str())
        .unwrap_or("");
    let last = statuses[statuses.len() - 1]
        .get("id")
        .and_then(|v| v.as_str())
        .unwrap_or("");
    let base = "https://mkultra.monster/api/v1/timelines/home";
    let mut parts = Vec::new();
    if !last.is_empty() {
        parts.push(format!(
            "<{base}?limit={limit}&max_id={last}>; rel=\"next\""
        ));
    }
    if !first.is_empty() {
        parts.push(format!(
            "<{base}?limit={limit}&min_id={first}>; rel=\"prev\""
        ));
    }
    parts.join(", ")
}

async fn load_ranked_head(
    redis: &mut redis::aio::MultiplexedConnection,
    owner: i64,
    want: usize,
) -> Result<(Vec<RankedEntry>, String)> {
    let index_key = format!("vaak:timeline:owner-index:v1:{owner}");
    let index = redis_util::json_get(redis, &index_key).await?;
    let logical = pick_home_logical(index.as_ref())
        .context("owner ranked index miss (no home logical)")?;
    let rk = ranked_redis_key(&logical);
    let env = redis_util::json_get(redis, &rk)
        .await?
        .context("ranked home envelope miss")?;
    let ranked = env
        .get("ranked")
        .and_then(|v| v.as_array())
        .cloned()
        .unwrap_or_default();
    let mut out = Vec::new();
    for it in ranked.into_iter().take(want) {
        let kind = it
            .get("k")
            .and_then(|v| v.as_str())
            .unwrap_or("")
            .to_string();
        let id = it
            .get("id")
            .map(|v| match v {
                Value::String(s) => s.clone(),
                Value::Number(n) => n.to_string(),
                _ => String::new(),
            })
            .unwrap_or_default();
        if kind.is_empty() || id.is_empty() {
            continue;
        }
        out.push(RankedEntry { kind, id });
    }
    Ok((out, logical))
}

async fn fetch_rss_map(db: &Client, ids: &[i64], owner: i64) -> Result<HashMap<i64, RssRow>> {
    let mut map = HashMap::new();
    if ids.is_empty() {
        return Ok(map);
    }
    // rss_feeds.owner_user_id is int4; rss_items.id is bigint.
    let owner_i32 = i32::try_from(owner).unwrap_or(0);
    let rows = db
        .query(
            "SELECT i.id, i.feed_id, COALESCE(i.url,''), COALESCE(i.title,''),
                    COALESCE(i.summary_text,''), COALESCE(i.image_url,''),
                    COALESCE(i.published_at::text, i.ingested_at::text, ''),
                    COALESCE(f.title,''), COALESCE(f.favicon_url,''),
                    COALESCE(f.site_url,''), COALESCE(f.feed_url,'')
             FROM rss_items i
             JOIN rss_feeds f ON f.id = i.feed_id
             WHERE i.id = ANY($1) AND f.owner_user_id = $2",
            &[&ids, &owner_i32],
        )
        .await
        .context("select rss_items for hydrate")?;
    for row in rows {
        let id: i64 = row.get(0);
        map.insert(
            id,
            RssRow {
                id,
                feed_id: row.get(1),
                url: row.get(2),
                title: row.get(3),
                summary_text: row.get(4),
                image_url: row.get(5),
                published_at: row.get(6),
                feed_title: row.get(7),
                feed_favicon: row.get(8),
                feed_site_url: row.get(9),
                feed_url: row.get(10),
            },
        );
    }
    Ok(map)
}

async fn fetch_bsky_map(db: &Client, uris: &[String]) -> Result<HashMap<String, BskyRow>> {
    let mut map = HashMap::new();
    if uris.is_empty() {
        return Ok(map);
    }
    let rows = db
        .query(
            "SELECT bsky_uri, COALESCE(author_did,''), COALESCE(author_handle,''),
                    COALESCE(author_display,''), COALESCE(author_avatar,''),
                    COALESCE(indexed_at::text,''), COALESCE(published_at::text,''),
                    COALESCE(text,''), COALESCE(embed_json,''), COALESCE(raw_json,''),
                    COALESCE(like_count,0), COALESCE(repost_count,0), COALESCE(reply_count,0)
             FROM bsky_posts WHERE bsky_uri = ANY($1)",
            &[&uris],
        )
        .await
        .context("select bsky_posts for hydrate")?;
    for row in rows {
        let uri: String = row.get(0);
        map.insert(
            uri.clone(),
            BskyRow {
                uri,
                author_did: row.get(1),
                author_handle: row.get(2),
                author_display: row.get(3),
                author_avatar: row.get(4),
                indexed_at: row.get(5),
                published_at: row.get(6),
                text: row.get(7),
                embed_json: row.get(8),
                raw_json: row.get(9),
                like_count: i64::from(row.get::<_, i32>(10)),
                repost_count: i64::from(row.get::<_, i32>(11)),
                reply_count: i64::from(row.get::<_, i32>(12)),
            },
        );
    }
    Ok(map)
}

async fn fetch_events_map(db: &Client, ids: &[i64]) -> Result<HashMap<i64, EventRow>> {
    let mut map = HashMap::new();
    if ids.is_empty() {
        return Ok(map);
    }
    let rows = db
        .query(
            "SELECT id, COALESCE(type,''), COALESCE(actor_id,''), COALESCE(object_id,''),
                    COALESCE(summary,''), COALESCE(media_urls,'[]'),
                    COALESCE(created_at::text,''), COALESCE(sensitive, 0),
                    COALESCE(spoiler_text,''), COALESCE(host,''), COALESCE(target_actor,'')
             FROM events WHERE id = ANY($1)",
            &[&ids],
        )
        .await
        .context("select events for hydrate")?;
    for row in rows {
        let id: i64 = row.get(0);
        // events.sensitive is bigint (0/1) on prod.
        let sensitive = row.try_get::<_, i64>(7).unwrap_or(0) != 0;
        map.insert(
            id,
            EventRow {
                id,
                event_type: row.get(1),
                actor_id: row.get(2),
                object_id: row.get(3),
                summary: row.get(4),
                media_urls: row.get(5),
                created_at: row.get(6),
                sensitive,
                spoiler_text: row.get(8),
                host: row.get(9),
                target_actor: row.get(10),
            },
        );
    }
    Ok(map)
}

async fn fetch_creates_by_object(
    db: &Client,
    object_ids: &[String],
) -> Result<HashMap<String, EventRow>> {
    let mut map = HashMap::new();
    if object_ids.is_empty() {
        return Ok(map);
    }
    // Match with and without trailing slash via OR on normalized set.
    let mut variants = Vec::new();
    for oid in object_ids {
        let base = oid.trim_end_matches('/').to_string();
        if base.is_empty() {
            continue;
        }
        variants.push(base.clone());
        variants.push(format!("{base}/"));
    }
    variants.sort();
    variants.dedup();
    let rows = db
        .query(
            "SELECT DISTINCT ON (rtrim(object_id, '/'))
                    id, COALESCE(type,''), COALESCE(actor_id,''), COALESCE(object_id,''),
                    COALESCE(summary,''), COALESCE(media_urls,'[]'),
                    COALESCE(created_at::text,''), COALESCE(sensitive, 0),
                    COALESCE(spoiler_text,''), COALESCE(host,''), COALESCE(target_actor,'')
             FROM events
             WHERE type = 'Create' AND object_id = ANY($1)
             ORDER BY rtrim(object_id, '/'), id DESC",
            &[&variants],
        )
        .await
        .context("select Create events for announce hydrate")?;
    for row in rows {
        let id: i64 = row.get(0);
        let sensitive = row.try_get::<_, i64>(7).unwrap_or(0) != 0;
        let object_id: String = row.get(3);
        let key = object_id.trim_end_matches('/').to_string();
        map.insert(
            key,
            EventRow {
                id,
                event_type: row.get(1),
                actor_id: row.get(2),
                object_id,
                summary: row.get(4),
                media_urls: row.get(5),
                created_at: row.get(6),
                sensitive,
                spoiler_text: row.get(8),
                host: row.get(9),
                target_actor: row.get(10),
            },
        );
    }
    Ok(map)
}

async fn fetch_actors_map(db: &Client, actor_ids: &[String]) -> Result<HashMap<String, ActorRow>> {
    let mut map = HashMap::new();
    if actor_ids.is_empty() {
        return Ok(map);
    }
    let mut variants = Vec::new();
    for a in actor_ids {
        let base = a.trim_end_matches('/').to_string();
        if base.is_empty() {
            continue;
        }
        variants.push(base.clone());
        variants.push(format!("{base}/"));
    }
    variants.sort();
    variants.dedup();
    let rows = db
        .query(
            "SELECT COALESCE(actor_id,''), COALESCE(username,''), COALESCE(display_name,''),
                    COALESCE(host,''), COALESCE(icon_source_url,'')
             FROM remote_actors WHERE actor_id = ANY($1)",
            &[&variants],
        )
        .await
        .context("select remote_actors for hydrate")?;
    for row in rows {
        let actor_id: String = row.get(0);
        let key = actor_id.trim_end_matches('/').to_string();
        map.insert(
            key,
            ActorRow {
                actor_id,
                username: row.get(1),
                display_name: row.get(2),
                host: row.get(3),
                icon: row.get(4),
            },
        );
    }
    Ok(map)
}

async fn fetch_outbox_map(db: &Client, ids: &[String]) -> Result<HashMap<String, OutboxRow>> {
    let mut map = HashMap::new();
    if ids.is_empty() {
        return Ok(map);
    }
    let mut variants = Vec::new();
    for id in ids {
        let base = id.trim_end_matches('/').to_string();
        if base.is_empty() {
            continue;
        }
        variants.push(base.clone());
        variants.push(format!("{base}/"));
    }
    variants.sort();
    variants.dedup();
    let rows = db
        .query(
            "SELECT id, COALESCE(published::text,''), COALESCE(content,'')
             FROM outbox_notes WHERE id = ANY($1)",
            &[&variants],
        )
        .await
        .context("select outbox_notes for hydrate")?;
    for row in rows {
        let id: String = row.get(0);
        let key = id.trim_end_matches('/').to_string();
        map.insert(
            key,
            OutboxRow {
                id,
                published: row.get(1),
                content: row.get(2),
            },
        );
    }
    Ok(map)
}

async fn load_owner_username(db: &Client, owner: i64) -> Result<String> {
    let row = db
        .query_opt(
            "SELECT COALESCE(username,'cmdr_nova') FROM ap_users WHERE id = $1",
            &[&owner],
        )
        .await?;
    Ok(row
        .map(|r| r.get::<_, String>(0))
        .unwrap_or_else(|| "cmdr_nova".into()))
}

fn materialize_announce(
    announce: &EventRow,
    booster: Option<&ActorRow>,
    create: Option<&EventRow>,
    actors: &HashMap<String, ActorRow>,
) -> Value {
    let created = format_time(&announce.created_at);
    let outer_id = snowflake_id(&created, announce.id, 1);
    let booster_acct = if let Some(a) = booster {
        actor_to_account(a)
    } else {
        let username = announce
            .actor_id
            .rsplit('/')
            .next()
            .unwrap_or("unknown")
            .to_string();
        empty_account(
            &announce.actor_id,
            &username,
            &username,
            &username,
            &announce.actor_id,
            DEFAULT_AVATAR,
        )
    };

    let mut inner = if let Some(crow) = create {
        let cactor = actors.get(crow.actor_id.trim_end_matches('/'));
        materialize_event_create(crow, cactor)
    } else {
        // Thin announce: use announce summary/media + target_actor when present.
        let mut thin = announce.clone();
        let orig = if !announce.target_actor.trim().is_empty() {
            announce.target_actor.trim_end_matches('/').to_string()
        } else {
            announce.actor_id.trim_end_matches('/').to_string()
        };
        thin.actor_id = orig.clone();
        let cactor = actors.get(orig.as_str());
        let mut st = materialize_event_create(&thin, cactor);
        // Use synth inner id so it never collides with outer announce id.
        let synth = announce_inner_synth_id(&created, &announce.object_id);
        st["id"] = json!(synth);
        if !announce.object_id.is_empty() {
            st["uri"] = json!(announce.object_id);
            st["url"] = json!(announce.object_id);
        }
        let plain = strip_tags_simple(st.get("content").and_then(|v| v.as_str()).unwrap_or(""))
            .trim()
            .to_string();
        let media_empty = st
            .get("media_attachments")
            .and_then(|v| v.as_array())
            .map(|a| a.is_empty())
            .unwrap_or(true);
        if plain.is_empty() || plain.eq_ignore_ascii_case("(boost)") {
            if media_empty {
                st["vaak_degraded"] = json!(true);
                st["vaak_degraded_reason"] = json!("announce_only");
                st["content"] = json!("");
            }
        }
        st
    };

    // Ensure inner id ≠ outer id.
    let inner_id = inner
        .get("id")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .to_string();
    if inner_id.is_empty() || inner_id == outer_id {
        let synth = announce_inner_synth_id(&created, &announce.object_id);
        inner["id"] = json!(synth);
    }
    if !announce.object_id.is_empty() {
        inner["uri"] = json!(announce.object_id.clone());
        inner["url"] = json!(announce.object_id.clone());
    }
    inner["reblog"] = Value::Null;

    let uri = if announce.object_id.is_empty() {
        format!("{}#announce-{}", announce.actor_id, announce.id)
    } else {
        format!("{}#announce-{}", announce.object_id, announce.id)
    };
    let url = if announce.object_id.is_empty() {
        announce.actor_id.clone()
    } else {
        announce.object_id.clone()
    };

    json!({
        "id": outer_id,
        "created_at": created,
        "in_reply_to_id": Value::Null,
        "in_reply_to_account_id": Value::Null,
        "sensitive": false,
        "spoiler_text": "",
        "visibility": "public",
        "language": Value::Null,
        "uri": uri,
        "url": url,
        "replies_count": 0,
        "reblogs_count": 0,
        "favourites_count": 0,
        "edited_at": Value::Null,
        "favourited": false,
        "reblogged": false,
        "muted": false,
        "bookmarked": false,
        "pinned": false,
        "content": "",
        "reblog": inner,
        "application": Value::Null,
        "account": booster_acct,
        "media_attachments": [],
        "mentions": [],
        "tags": [],
        "emojis": [],
        "card": Value::Null,
        "poll": Value::Null,
        "vaak_announce_event_id": announce.id,
    })
}

/// Materialize Mastodon Home statuses from the owner's ranked head and write
/// `vaak:timeline:v1:*` envelopes for each limit.
pub async fn warm_owner(cfg: &Config, owner_user_id: i64, limits: &[i64]) -> Result<WarmReport> {
    let started = Instant::now();
    let mut limits: Vec<i64> = limits
        .iter()
        .copied()
        .filter(|n| (1..=80).contains(n))
        .collect();
    if limits.is_empty() {
        limits = DEFAULT_LIMITS.to_vec();
    }
    limits.sort_unstable();
    limits.dedup();
    let want = *limits.iter().max().unwrap_or(&80) as usize;

    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let (head, _logical) = load_ranked_head(&mut redis, owner_user_id, want.max(80)).await?;
    let ranked_n = head.len();

    let mut rss_ids = Vec::new();
    let mut bsky_uris = Vec::new();
    let mut event_ids = Vec::new();
    let mut outbox_ids = Vec::new();
    for e in &head {
        match e.kind.as_str() {
            "rss" => {
                if let Ok(id) = e.id.parse::<i64>() {
                    if id > 0 {
                        rss_ids.push(id);
                    }
                }
            }
            "bsky" => bsky_uris.push(e.id.clone()),
            "event" => {
                if let Ok(id) = e.id.parse::<i64>() {
                    if id > 0 {
                        event_ids.push(id);
                    }
                }
            }
            "outbox" => outbox_ids.push(e.id.clone()),
            _ => {}
        }
    }
    rss_ids.sort_unstable();
    rss_ids.dedup();
    bsky_uris.sort();
    bsky_uris.dedup();
    event_ids.sort_unstable();
    event_ids.dedup();
    outbox_ids.sort();
    outbox_ids.dedup();

    let db = crate::db::connect(&cfg.database_url).await?;
    let owner_username = load_owner_username(&db, owner_user_id).await?;
    let rss_map = fetch_rss_map(&db, &rss_ids, owner_user_id).await?;
    let bsky_map = fetch_bsky_map(&db, &bsky_uris).await?;
    let events_map = fetch_events_map(&db, &event_ids).await?;

    // Announce → look up Create for object_id; collect all actor ids.
    let mut announce_oids = Vec::new();
    let mut actor_ids = Vec::new();
    for ev in events_map.values() {
        actor_ids.push(ev.actor_id.trim_end_matches('/').to_string());
        if !ev.target_actor.is_empty() {
            actor_ids.push(ev.target_actor.trim_end_matches('/').to_string());
        }
        if ev.event_type.eq_ignore_ascii_case("announce") && !ev.object_id.is_empty() {
            announce_oids.push(ev.object_id.trim_end_matches('/').to_string());
        }
    }
    announce_oids.sort();
    announce_oids.dedup();
    let creates_map = fetch_creates_by_object(&db, &announce_oids).await?;
    for crow in creates_map.values() {
        actor_ids.push(crow.actor_id.trim_end_matches('/').to_string());
    }
    actor_ids.sort();
    actor_ids.dedup();
    let actors_map = fetch_actors_map(&db, &actor_ids).await?;
    let outbox_map = fetch_outbox_map(&db, &outbox_ids).await?;

    let mut statuses = Vec::new();
    let mut kinds: HashMap<String, usize> = HashMap::new();
    for e in &head {
        let st = match e.kind.as_str() {
            "rss" => {
                let id = e.id.parse::<i64>().unwrap_or(0);
                rss_map.get(&id).map(materialize_rss)
            }
            "bsky" => bsky_map.get(&e.id).map(materialize_bsky),
            "event" => {
                let id = e.id.parse::<i64>().unwrap_or(0);
                events_map.get(&id).and_then(|ev| {
                    let actor = actors_map.get(ev.actor_id.trim_end_matches('/'));
                    if ev.event_type.eq_ignore_ascii_case("announce") {
                        let create = creates_map.get(ev.object_id.trim_end_matches('/'));
                        Some(materialize_announce(ev, actor, create, &actors_map))
                    } else {
                        // Skip empty private-ish stubs with no body/media/CW.
                        let plain = strip_tags_simple(&ev.summary);
                        let media_empty = ev.media_urls.is_empty() || ev.media_urls == "[]";
                        let has_cw = ev.sensitive || !ev.spoiler_text.trim().is_empty();
                        if plain.trim().is_empty() && media_empty && !has_cw {
                            return None;
                        }
                        Some(materialize_event_create(ev, actor))
                    }
                })
            }
            "outbox" => outbox_map
                .get(e.id.trim_end_matches('/'))
                .map(|row| materialize_outbox(row, &owner_username)),
            _ => None,
        };
        if let Some(st) = st {
            *kinds.entry(e.kind.clone()).or_insert(0) += 1;
            statuses.push(st);
        }
        if statuses.len() >= want {
            break;
        }
    }

    let mut stored = Vec::new();
    let now = chrono::Utc::now().timestamp();
    for &limit in &limits {
        let slice: Vec<Value> = statuses.iter().take(limit as usize).cloned().collect();
        let body = serde_json::to_string(&slice).unwrap_or_else(|_| "[]".into());
        let link = link_header(&slice, limit);
        let key = timeline::home_timeline_redis_key(owner_user_id, limit, None);
        let envelope = json!({
            "created_at": now,
            "body": body,
            "link": link,
        });
        redis_util::json_set(&mut redis, &key, &envelope, HOME_HYDRATE_TTL_SECS).await?;
        stored.push((limit, slice.len()));
    }

    let ms = started.elapsed().as_millis();
    let kinds_note = kinds
        .iter()
        .map(|(k, n)| format!("{k}:{n}"))
        .collect::<Vec<_>>()
        .join(",");
    Ok(WarmReport {
        owner_user_id,
        ranked_n,
        materialised_n: statuses.len(),
        stored,
        kinds,
        ms,
        source: "vaak-worker-home-hydrate-ranked",
        note: format!("ranked={ranked_n} materialised={} kinds={kinds_note}", statuses.len()),
    })
}

/// Fire-and-forget ranked→hydrate for an owner (cooldown via Redis).
/// Returns a short status token for ranked-warm log lines.
pub async fn maybe_warm_after_ranked(
    redis: &mut redis::aio::MultiplexedConnection,
    cfg: &Config,
    owner_user_id: i64,
) -> String {
    if !env_flag_default_true("VAAK_HOME_HYDRATE_WARM") {
        return "hydrate=skip_flag".into();
    }
    let cooldown = std::env::var("VAAK_HOME_HYDRATE_WARM_COOLDOWN_SECS")
        .ok()
        .and_then(|s| s.parse().ok())
        .unwrap_or(60)
        .clamp(30, 600);
    let cooldown_key = format!("vaak:home:hydrate-warm:cd:{owner_user_id}");
    let cooling: Option<String> = redis::cmd("GET")
        .arg(&cooldown_key)
        .query_async(redis)
        .await
        .unwrap_or(None);
    if cooling.is_some() {
        return format!("hydrate=skip_cooldown cooldown={cooldown}");
    }
    let _: Result<(), _> = redis::cmd("SET")
        .arg(&cooldown_key)
        .arg("1")
        .arg("EX")
        .arg(cooldown)
        .query_async(redis)
        .await;

    let cfg = cfg.clone();
    tokio::spawn(async move {
        let t0 = Instant::now();
        match warm_owner(&cfg, owner_user_id, &DEFAULT_LIMITS).await {
            Ok(report) => {
                tracing::info!(
                    owner = owner_user_id,
                    ms = report.ms,
                    n = report.materialised_n,
                    ranked = report.ranked_n,
                    note = %report.note,
                    "home hydrate ranked warm ok"
                );
            }
            Err(e) => {
                tracing::warn!(
                    owner = owner_user_id,
                    ms = t0.elapsed().as_millis(),
                    error = %format!("{e:#}"),
                    "home hydrate ranked warm failed"
                );
            }
        }
    });
    "hydrate=spawned_ranked".into()
}

/// CLI / account-switch: warm immediately (clears cooldown).
pub async fn warm_owner_now(cfg: &Config, owner_user_id: i64) -> Result<WarmReport> {
    if let Ok(mut redis) = redis_util::connect(&cfg.redis_url).await {
        let cooldown_key = format!("vaak:home:hydrate-warm:cd:{owner_user_id}");
        let _: Result<(), _> = redis::cmd("DEL")
            .arg(&cooldown_key)
            .query_async(&mut redis)
            .await;
    }
    warm_owner(cfg, owner_user_id, &DEFAULT_LIMITS).await
}

pub fn report_json(r: &WarmReport) -> Value {
    let stored: Vec<Value> = r
        .stored
        .iter()
        .map(|(lim, n)| json!({"limit": lim, "n": n}))
        .collect();
    json!({
        "owner_user_id": r.owner_user_id,
        "ranked_n": r.ranked_n,
        "materialised_n": r.materialised_n,
        "stored": stored,
        "kinds": r.kinds,
        "ms": r.ms,
        "source": r.source,
        "note": r.note,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn snowflake_stable() {
        let a = snowflake_id("2026-10-05T07:00:45.000Z", 690764, 1);
        let b = snowflake_id("2026-10-05T07:00:45.000Z", 690764, 1);
        assert_eq!(a, b);
        assert!(a.len() >= 16);
    }

    #[test]
    fn rss_acct_slug() {
        assert_eq!(rss_acct("LiminalSpace"), "liminalspace");
        assert_eq!(rss_acct("!!!"), "rss");
    }

    #[test]
    fn preview_redd_upgrade() {
        let u = "https://preview.redd.it/abc123.jpg?width=320";
        assert_eq!(upgrade_rss_image(u), "https://i.redd.it/abc123.jpg");
    }

    #[test]
    fn bsky_url_from_at() {
        let at = "at://did:plc:abc/app.bsky.feed.post/3mx4";
        assert_eq!(
            bsky_https_url(at, "gamingonlinux.com"),
            "https://bsky.app/profile/gamingonlinux.com/post/3mx4"
        );
    }

    #[test]
    fn bsky_sensitive_from_top_level_labels() {
        // PHP ap_bsky_sensitive_label_vals / PostView labels parity.
        let raw = r#"{"uri":"at://did:plc:x/app.bsky.feed.post/y","cid":"bafy","labels":[{"val":"porn","src":"did:plc:mod"}]}"#;
        assert!(bsky_is_sensitive(raw));
        let clean = r#"{"uri":"at://did:plc:x/app.bsky.feed.post/y","cid":"bafy","labels":[]}"#;
        assert!(!bsky_is_sensitive(clean));
        let self_lab = r#"{"record":{"selfLabels":{"values":[{"val":"nudity"}]}}}"#;
        assert!(bsky_is_sensitive(self_lab));
    }
}
