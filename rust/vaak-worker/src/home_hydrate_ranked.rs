//! Timeline hydrate from ranked IDs (Rust-native).
//!
//! Walks `vaak:timeline:ranked:v2:*` for an owner, materializes Mastodon-shaped
//! status JSON, then writes PHP-compatible `vaak:timeline:v1:*` envelopes for
//! limits 15/40/50/80/160/240 (0.7.25 deep Home scroll).
//!
//! Views:
//! - home: rss / bsky / event / outbox (Announce→reblog)
//! - local: outbox / boost (`masto_reblogs`)
//! - feed: event only
//!
//! Replaces `bin/home-timeline-warm.php` chronological merge which dropped the
//! ranked RSS/Bluesky mix on Home.

use std::collections::HashMap;
use std::time::Instant;

use anyhow::{bail, Context, Result};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use tokio_postgres::Client;

use crate::config::Config;
use crate::redis_util;
use crate::timeline;

const HOME_HYDRATE_TTL_SECS: u64 = 300;
/// Remote / unknown fallback — `default.jpg` 404s on disk; use webp.
const DEFAULT_AVATAR: &str = "https://mkultra.monster/img/avatar/default.webp";
/// Local mkultra accounts without actor_profile.icon_url.
const LOCAL_DEFAULT_AVATAR: &str = "https://mkultra.monster/img/avatar/local-default.webp";
const LOCAL_ACTOR_PREFIX: &str = "https://mkultra.monster/users/";
/// 50 = Ice Cubes (0.7.21); 160/240 = deep HTML scroll past the old 80-head (0.7.25).
const DEFAULT_LIMITS: [i64; 6] = [15, 40, 50, 80, 160, 240];
const MAX_HYDRATE_LIMIT: i64 = 240;

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

fn plain_to_html(plain: &str) -> String {
    let t = plain.trim();
    if t.is_empty() {
        return String::new();
    }
    format!("<p>{}</p>", esc(t).replace('\n', "<br>"))
}

/// Render Bluesky rich-text link facets without losing the visible label.
///
/// Bluesky permits a post to display a shortened label (for example
/// `bsky.app/profile/did:...`) while the facet carries the complete target
/// URI.  The old plain-text materializer discarded that facet, leaving a
/// truncated, non-clickable string in native Home cards.
fn bsky_text_to_html(plain: &str, raw_json: &str) -> String {
    let text = plain.trim();
    if text.is_empty() {
        return String::new();
    }
    let Ok(raw) = serde_json::from_str::<Value>(raw_json) else {
        return plain_to_html(text);
    };
    let facets = raw
        .get("record")
        .and_then(|v| v.get("facets"))
        .or_else(|| raw.get("facets"))
        .and_then(|v| v.as_array());
    let Some(facets) = facets else {
        return plain_to_html(text);
    };

    let bytes = text.as_bytes();
    let mut links: Vec<(usize, usize, String)> = Vec::new();
    for facet in facets {
        let start = facet
            .get("index")
            .and_then(|v| v.get("byteStart"))
            .and_then(|v| v.as_u64())
            .map(|n| n as usize);
        let end = facet
            .get("index")
            .and_then(|v| v.get("byteEnd"))
            .and_then(|v| v.as_u64())
            .map(|n| n as usize);
        let uri = facet
            .get("features")
            .and_then(|v| v.as_array())
            .and_then(|features| {
                features.iter().find_map(|feature| {
                    let kind = feature.get("$type").and_then(|v| v.as_str()).unwrap_or("");
                    if kind.ends_with("#link") {
                        feature.get("uri").and_then(|v| v.as_str())
                    } else {
                        None
                    }
                })
            });
        let (Some(start), Some(end), Some(uri)) = (start, end, uri) else {
            continue;
        };
        if start >= end || end > bytes.len() || !uri.starts_with("http") {
            continue;
        }
        if !text.is_char_boundary(start) || !text.is_char_boundary(end) {
            continue;
        }
        links.push((start, end, uri.to_string()));
    }
    if links.is_empty() {
        return plain_to_html(text);
    }
    links.sort_by_key(|(start, _, _)| *start);
    let mut out = String::new();
    let mut cursor = 0usize;
    for (start, end, uri) in links {
        if start < cursor {
            continue;
        }
        out.push_str(&esc(&text[cursor..start]).replace('\n', "<br>"));
        out.push_str(&format!(
            "<a class=\"ext-link\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer nofollow\">{}</a>",
            esc(&uri),
            esc(&text[start..end]).replace('\n', "<br>")
        ));
        cursor = end;
    }
    out.push_str(&esc(&text[cursor..]).replace('\n', "<br>"));
    format!("<p>{out}</p>")
}

/// Convert Bluesky mention facets into Mastodon-compatible mention objects.
/// The visible facet text is the best available handle until the DID has been
/// hydrated; retaining it still lets reply composers address the full chain.
fn bsky_mentions_from_raw(text: &str, raw_json: &str) -> Vec<Value> {
    let Ok(raw) = serde_json::from_str::<Value>(raw_json) else {
        return Vec::new();
    };
    let facets = raw
        .get("record")
        .and_then(|v| v.get("facets"))
        .or_else(|| raw.get("facets"))
        .and_then(|v| v.as_array());
    let Some(facets) = facets else { return Vec::new(); };
    let bytes = text.as_bytes();
    let mut out = Vec::new();
    let mut seen = std::collections::HashSet::new();
    for facet in facets {
        let start = facet.get("index").and_then(|v| v.get("byteStart")).and_then(Value::as_u64).map(|n| n as usize);
        let end = facet.get("index").and_then(|v| v.get("byteEnd")).and_then(Value::as_u64).map(|n| n as usize);
        let did = facet.get("features").and_then(Value::as_array).and_then(|features| {
            features.iter().find_map(|feature| {
                let kind = feature.get("$type").and_then(Value::as_str).unwrap_or("");
                if kind.ends_with("#mention") { feature.get("did").and_then(Value::as_str) } else { None }
            })
        });
        let (Some(start), Some(end), Some(did)) = (start, end, did) else { continue; };
        if start >= end || end > bytes.len() || !text.is_char_boundary(start) || !text.is_char_boundary(end) { continue; }
        let visible = text[start..end].trim().trim_start_matches('@').trim();
        if visible.is_empty() || !seen.insert(visible.to_ascii_lowercase()) { continue; }
        out.push(json!({
            "id": did,
            "username": visible,
            "acct": visible,
            "url": format!("https://bsky.app/profile/{did}"),
            "uri": format!("https://bsky.app/profile/{did}"),
        }));
    }
    out
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

/// Normalize Postgres `timestamptz::text` (e.g. `2026-10-05 08:43:15+02`) into
/// something chrono/RFC3339 can parse. Ice Cubes rejects non-ISO `created_at`.
fn normalize_pg_timestamptz(raw: &str) -> String {
    let mut s = raw.trim().replace(' ', "T");
    // "+02" / "-05" → "+02:00" / "-05:00"
    if s.len() >= 3 {
        let bytes = s.as_bytes();
        let n = bytes.len();
        if (bytes[n - 3] == b'+' || bytes[n - 3] == b'-')
            && bytes[n - 2].is_ascii_digit()
            && bytes[n - 1].is_ascii_digit()
        {
            s.push_str(":00");
        } else if n >= 5
            && (bytes[n - 5] == b'+' || bytes[n - 5] == b'-')
            && bytes[n - 4].is_ascii_digit()
            && bytes[n - 3].is_ascii_digit()
            && bytes[n - 2].is_ascii_digit()
            && bytes[n - 1].is_ascii_digit()
            && bytes[n - 3] != b':'
        {
            // "+0200" → "+02:00"
            let head = s[..n - 2].to_string();
            let tail = s[n - 2..].to_string();
            s = format!("{head}:{tail}");
        }
    }
    s
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
    let cleaned = normalize_pg_timestamptz(raw);
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
    if let Ok(dt) = chrono::DateTime::parse_from_str(&cleaned, "%Y-%m-%dT%H:%M:%S%z") {
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
    // Last resort: never emit a non-ISO timestamp (Ice Cubes decode fails hard).
    chrono::Utc::now()
        .format("%Y-%m-%dT%H:%M:%S.000Z")
        .to_string()
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
        "quote": Value::Null,
        "quote_approval": {
            "automatic": ["public"],
            "manual": [],
            "current_user": "automatic"
        },
    });
    if let Some(c) = card {
        st["card"] = c;
    }
    st
}

/// Mastodon `/original/*.mp4` → `/small/*.png` still (Ice Cubes + lean poster).
fn guess_masto_video_preview(url: &str) -> Option<String> {
    let re = regex::Regex::new(
        r"(?i)^(https://.+)/original/([^/?#]+)\.(mp4|m4v|mov|webm)([?#].*)?$",
    )
    .ok()?;
    let c = re.captures(url)?;
    Some(format!("{}/small/{}.png", &c[1], &c[2]))
}

fn media_from_urls(urls_json: &str, status_id: &str) -> Vec<Value> {
    let Ok(decoded) = serde_json::from_str::<Value>(urls_json) else {
        return Vec::new();
    };
    let Some(arr) = decoded.as_array() else {
        return Vec::new();
    };
    let mut out = Vec::new();
    for u in arr {
        if out.len() >= 4 {
            break;
        }
        let clean = u.as_str().unwrap_or("").trim();
        if !clean.to_ascii_lowercase().starts_with("https://") {
            continue;
        }
        let sniffed = crate::notif_embed::media_kind_from_url(clean);
        let is_audio = sniffed == "audio";
        let is_video = sniffed == "video";
        let mtype = if is_audio {
            "audio"
        } else if is_video {
            "video"
        } else {
            "image"
        };
        // Never set preview_url to the playable file itself — browsers ignore
        // non-image <video poster> and an mp3 is not an <img>.
        let preview = if is_video {
            guess_masto_video_preview(clean).unwrap_or_default()
        } else if is_audio {
            String::new()
        } else {
            clean.to_string()
        };
        // Attachment ids are local to the emitted list.  Do not leak gaps
        // when malformed/non-HTTPS URLs are skipped (PHP/Mastodon clients
        // expect stable 1..N attachment slots).
        out.push(json!({
            "id": format!("{status_id}{}", out.len() + 1),
            "type": mtype,
            "url": clean,
            "preview_url": if preview.starts_with("https://") {
                Value::String(preview)
            } else if is_video || is_audio {
                Value::Null
            } else {
                Value::String(clean.into())
            },
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

fn bsky_reply_parent_uri(raw_json: &str) -> String {
    let Ok(raw) = serde_json::from_str::<Value>(raw_json) else {
        return String::new();
    };
    raw.get("record")
        .and_then(|r| r.get("reply"))
        .and_then(|r| r.get("parent"))
        .and_then(|p| p.get("uri"))
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .trim_end_matches('/')
        .to_string()
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

    // Bluesky record embeds can point at graph lists or Starter Packs rather
    // than another post. Preserve that semantic type for the shared VAAK
    // painter instead of degrading it to a generic Bluesky-post card.
    if etype.contains("record#view") || etype.contains("embed.record") {
        let record = embed.get("record").unwrap_or(&Value::Null);
        let record_uri = record
            .get("uri")
            .and_then(|v| v.as_str())
            .or_else(|| record.get("record").and_then(|r| r.get("uri")).and_then(|v| v.as_str()))
            .unwrap_or("")
            .trim();
        let kind = if record_uri.contains("/app.bsky.graph.starterpack/") {
            Some("starter_pack")
        } else if record_uri.contains("/app.bsky.graph.list/") {
            Some("list")
        } else {
            None
        };
        if let Some(kind) = kind {
            card = Some(json!({
                "url": "",
                "title": if kind == "starter_pack" { "Bluesky Starter Pack" } else { "Bluesky List" },
                "description": if kind == "starter_pack" { "A starter pack shared from Bluesky" } else { "A list shared from Bluesky" },
                "provider_name": "VAAK collection",
                "vaak_collection_kind": kind,
                "vaak_collection_uri": record_uri,
            }));
        }
    }

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

/// Collect ranked logical keys for a view from the owner index.
///
/// - home: contains `_home_` or `home` (legacy index entries)
/// - local: contains `_local_`
/// - feed: contains `_feed_` (index may list several follow fingerprints)
fn collect_view_logicals(index: Option<&Value>, view: &str) -> Vec<String> {
    let Some(arr) = index.and_then(|v| v.as_array()) else {
        return Vec::new();
    };
    let mut out = Vec::new();
    let mut first = None;
    for v in arr {
        let Some(s) = v.as_str() else { continue };
        if first.is_none() {
            first = Some(s.to_string());
        }
        let hit = match view {
            "local" => s.contains("_local_"),
            "feed" => s.contains("_feed_"),
            _ => s.contains("_home_") || s.contains("home"),
        };
        if hit && !out.iter().any(|x| x == s) {
            out.push(s.to_string());
        }
    }
    // Home previously fell back to the first index entry.
    if out.is_empty() && (view == "home" || view.is_empty()) {
        if let Some(f) = first {
            out.push(f);
        }
    }
    out
}

#[allow(dead_code)]
fn pick_view_logical(index: Option<&Value>, view: &str) -> Option<String> {
    collect_view_logicals(index, view).into_iter().next()
}

#[allow(dead_code)]
fn pick_home_logical(index: Option<&Value>) -> Option<String> {
    pick_view_logical(index, "home")
}

fn normalize_view(view: &str) -> &'static str {
    match view.trim().to_ascii_lowercase().as_str() {
        "local" => "local",
        "feed" => "feed",
        _ => "home",
    }
}

fn reblog_is_bsky_native(status_id: &str, object_id: &str) -> bool {
    let sid = status_id.trim().to_ascii_lowercase();
    let oid = object_id.trim().to_ascii_lowercase();
    sid.starts_with("bsky-repost-")
        || oid.starts_with("https://bsky.app/")
        || oid.contains("bsky.mkultra.monster/")
}

fn reblog_is_rss_local(status_id: &str, object_id: &str) -> bool {
    let sid = status_id.trim().to_ascii_lowercase();
    let oid = object_id.trim().to_ascii_lowercase();
    sid.starts_with("rss:")
        || sid.starts_with("rss-boost:")
        || sid.starts_with("rss-quote:")
        || sid.starts_with("rss-quote-boost:")
        || sid.starts_with("local:rss-boost:")
        || sid.starts_with("local:rss-quote:")
        || oid.starts_with("rss:")
        || oid.starts_with("rss-boost:")
        || oid.starts_with("rss-quote:")
        || oid.starts_with("rss-quote-boost:")
}

fn rss_item_id_from_key(value: &str) -> Option<i64> {
    for marker in [
        "rss-quote-boost:",
        "rss-quote:",
        "rss-boost:",
        "local:rss-quote:",
        "local:rss-boost:",
        "rss:",
    ] {
        if let Some(pos) = value.to_ascii_lowercase().find(marker) {
            let digits = value[pos + marker.len()..]
                .chars()
                .take_while(|c| c.is_ascii_digit())
                .collect::<String>();
            if let Ok(id) = digits.parse::<i64>() {
                if id > 0 {
                    return Some(id);
                }
            }
        }
    }
    None
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

#[derive(Clone)]
pub(crate) struct BskyRow {
    pub(crate) uri: String,
    pub(crate) author_did: String,
    pub(crate) author_handle: String,
    pub(crate) author_display: String,
    pub(crate) author_avatar: String,
    pub(crate) indexed_at: String,
    pub(crate) published_at: String,
    pub(crate) text: String,
    pub(crate) embed_json: String,
    pub(crate) raw_json: String,
    pub(crate) like_count: i64,
    pub(crate) repost_count: i64,
    pub(crate) reply_count: i64,
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
    in_reply_to: String,
    ask_actor: String,
    ask_question: String,
    ask_answer: String,
}

struct ActorRow {
    actor_id: String,
    username: String,
    display_name: String,
    host: String,
    icon: String,
    emojis: Value,
}

struct OutboxRow {
    id: String,
    published: String,
    content: String,
    in_reply_to: String,
    /// JSON array of https media URLs (from masto_media / Create attachments).
    media_urls: String,
    sensitive: bool,
    spoiler_text: String,
    ask_actor: String,
    ask_question: String,
    ask_answer: String,
    quote_object: String,
}

/// Extract the canonical quoted-object URL from an ActivityPub/ActivityJSON
/// Create payload. Quote metadata is stored in `outbox_notes.raw_create_json`;
/// it is deliberately not duplicated in `masto_statuses`.
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

#[derive(Clone, Default)]
struct AskContext {
    actor: String,
    question: String,
    answer: String,
}

#[derive(Clone, Default)]
struct LocalProfile {
    display_name: String,
    icon_url: String,
}

#[derive(Clone)]
struct BoostRow {
    id: i64,
    #[allow(dead_code)]
    owner_user_id: i64,
    owner_actor_id: String,
    status_id: String,
    boost_status_id: String,
    object_id: String,
    target_actor: String,
    announce_activity_id: String,
    created_at: String,
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

pub(crate) fn materialize_bsky(row: &BskyRow) -> Value {
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
    let content = bsky_text_to_html(
        row.text.replace("\r\n", "\n").trim(),
        &row.raw_json,
    );
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
    st["mentions"] = json!(bsky_mentions_from_raw(row.text.trim(), &row.raw_json));
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
    let parent = bsky_reply_parent_uri(&row.raw_json);
    if !parent.is_empty() {
        st["vaak_in_reply_to_url"] = json!(parent);
    }
    // A valid Bluesky record may contain no text (for example a list/record
    // embed). Never paint that as an empty timeline card; retain a clickable
    // permalink as the minimum useful representation.
    if row.text.trim().is_empty()
        && st
            .get("media_attachments")
            .and_then(|v| v.as_array())
            .map(|a| a.is_empty())
            .unwrap_or(true)
        && st.get("card").map(|v| v.is_null()).unwrap_or(true)
    {
        st["card"] = json!({
            "url": https_url,
            "title": "Bluesky post",
            "description": "Open this post on Bluesky",
            "provider_name": "Bluesky",
            "type": "link"
        });
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
    let mut account = empty_account(
        &actor.actor_id,
        &username,
        &acct,
        display,
        &actor.actor_id,
        &actor.icon,
    );
    account["emojis"] = actor.emojis.clone();
    account
}

/// Extract Mastodon-compatible custom emoji metadata from a cached ActivityPub
/// actor document. PHP accepts both `tag: [{type: Emoji, name, icon}]` and the
/// older Wafrn/Misskey-style `attachment` form; keep this cache-only so a
/// timeline render never performs a remote fetch.
fn actor_emojis(profile: &Value) -> Value {
    let mut out = Vec::new();
    let sources = [profile.get("tag"), profile.get("attachment")];
    for source in sources.into_iter().flatten() {
        let items: Vec<&Value> = match source {
            Value::Array(items) => items.iter().collect(),
            Value::Object(_) => vec![source],
            _ => Vec::new(),
        };
        for item in items {
            if item.get("type").and_then(Value::as_str).map(|t| !t.eq_ignore_ascii_case("emoji")).unwrap_or(true) {
                continue;
            }
            let raw_name = item.get("name").and_then(Value::as_str).unwrap_or("").trim();
            let shortcode = raw_name.trim_matches(':').trim();
            let url = item
                .get("icon")
                .and_then(|v| v.get("url"))
                .and_then(Value::as_str)
                .or_else(|| item.get("image").and_then(Value::as_str))
                .unwrap_or("")
                .trim();
            if shortcode.is_empty() || !url.starts_with("https://") {
                continue;
            }
            if out.iter().any(|e: &Value| e.get("shortcode").and_then(Value::as_str) == Some(shortcode)) {
                continue;
            }
            out.push(json!({
                "shortcode": shortcode,
                "url": url,
                "static_url": url,
                "visible_in_picker": true,
            }));
        }
    }
    Value::Array(out)
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
    let (quote_commentary, quote_preview) = split_quote_summary(&text);
    if quote_preview.is_some() {
        text = quote_commentary;
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
    let parent = row.in_reply_to.trim().trim_end_matches('/');
    if !parent.is_empty() && (parent.starts_with("https://") || parent.starts_with("http://")) {
        st["vaak_in_reply_to_url"] = json!(parent);
        let parent_handle = reply_handle_from_object_url(parent);
        if !parent_handle.is_empty() {
            st["vaak_reply_parent_handle"] = json!(parent_handle);
        }
    }
    if !row.ask_question.trim().is_empty() {
        st["vaak_ask"] = json!({
            "ask_actor": row.ask_actor,
            "ask_question": row.ask_question,
            "ask_answer": row.ask_answer,
        });
    }
    if let Some(quote) = quote_preview {
        let quote_id = quote.get("id").cloned().unwrap_or(Value::Null);
        st["vaak_quote_preview"] = quote.clone();
        // Mastodon 4.4+/Ice Cubes contract. Keep the lean preview extension
        // for VAAK HTML paint, but expose the standard envelope to clients.
        st["quote"] = json!({
            "state": "accepted",
            "quoted_status": quote,
            "quoted_status_id": quote_id,
        });
        st["quote_approval"] = json!({
            "automatic": ["public"],
            "manual": [],
            "current_user": "automatic",
        });
    }
    st
}

/// PHP parity fallback for ActivityPub/Wafrn quote summaries. Native event
/// rows may only retain the enriched summary, so synthesize the same lean
/// quote status shape used by the shared painter instead of leaking `↪ QT` or
/// `RE:` markup into the post body.
fn split_quote_summary(summary: &str) -> (String, Option<Value>) {
    let raw = summary.trim();
    if raw.is_empty() {
        return (String::new(), None);
    }
    let re = regex::Regex::new(
        r"(?is)^(.*?)(?:\n\s*\n|\n)(?:↪|➡|→)\s*QT(?:Create|Announce|Update|Note|QuotePost)?\b\s*(?:@([^\s:]+)\s*)?:\s*(.*?)\s*$",
    )
    .ok();
    let captures = re.as_ref().and_then(|r| r.captures(raw));
    let (commentary, acct, quoted) = if let Some(c) = captures {
        (
            c.get(1).map(|m| m.as_str().trim()).unwrap_or(""),
            c.get(2).map(|m| m.as_str().trim()).unwrap_or(""),
            c.get(3).map(|m| m.as_str().trim()).unwrap_or(""),
        )
    } else {
        let re_url = regex::Regex::new(r"(?is)^(.*?)\n\s*RE:\s*(https://\S+)\s*$").ok();
        let Some(c) = re_url.as_ref().and_then(|r| r.captures(raw)) else {
            return (raw.to_string(), None);
        };
        (
            c.get(1).map(|m| m.as_str().trim()).unwrap_or(""),
            "",
            c.get(2).map(|m| m.as_str().trim()).unwrap_or(""),
        )
    };
    if quoted.is_empty() {
        return (commentary.to_string(), None);
    }
    let url = regex::Regex::new(r"https://[^\s<>]+")
        .ok()
        .and_then(|r| r.find(quoted).map(|m| m.as_str().trim_end_matches(['.', ',', ')']).to_string()))
        .unwrap_or_default();
    let acct = acct.trim_start_matches('@');
    let display = if acct.is_empty() { "Quoted post" } else { acct };
    let account_url = if url.is_empty() { String::new() } else { url.clone() };
    let quote_id = if url.is_empty() {
        let mut hasher = Sha256::new();
        hasher.update(quoted.as_bytes());
        format!("quote:{}", &hex::encode(hasher.finalize())[..24])
    } else {
        url.clone()
    };
    let account = json!({
        "id": if acct.is_empty() { "quote:unknown" } else { acct },
        "username": display,
        "acct": if acct.is_empty() { "quoted" } else { acct },
        "display_name": display,
        "avatar": DEFAULT_AVATAR,
        "avatar_static": DEFAULT_AVATAR,
        "url": account_url,
        "uri": if url.is_empty() { String::new() } else { url.clone() },
        "emojis": [],
        "fields": []
    });
    let mut preview = json!({
        "id": quote_id,
        "uri": if url.is_empty() { String::new() } else { url.clone() },
        "url": url,
        "created_at": "",
        "content": plain_to_html(quoted),
        "account": account,
        "media_attachments": [],
        "card": Value::Null,
        "sensitive": false,
        "spoiler_text": "",
        "emojis": []
    });
    if let Some(obj) = preview.as_object_mut() {
        obj.insert("source".into(), json!("quote-summary"));
    }
    (commentary.to_string(), Some(preview))
}

fn local_username_from_url(url: &str) -> Option<String> {
    let url = url.trim_end_matches('/');
    let rest = url.strip_prefix("https://mkultra.monster/users/")?;
    let key = rest.split('/').next().unwrap_or("").trim();
    if key.is_empty()
        || !key
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '_')
    {
        return None;
    }
    Some(key.to_ascii_lowercase())
}

fn reply_handle_from_object_url(url: &str) -> String {
    // Bluesky web URLs use /profile/{handle}/post/{rkey}; unlike ActivityPub
    // /statuses/ and /notes/ URLs the actor is not the URL prefix before a
    // status segment. Preserve the human handle here so local replies to a
    // Bluesky post render as replies (and seed the composer) instead of bare
    // standalone text or a DID.
    if let Some(rest) = url.strip_prefix("https://bsky.app/profile/") {
        let handle = rest.split('/').next().unwrap_or("").trim();
        if !handle.is_empty() && !handle.starts_with("did:") {
            return handle.to_string();
        }
    }
    let actor = url
        .split_once("/statuses/")
        .map(|(prefix, _)| prefix)
        .or_else(|| url.split_once("/notes/").map(|(prefix, _)| prefix))
        .unwrap_or("");
    if actor.is_empty() { return String::new(); }
    let username = actor.rsplit('/').next().unwrap_or("").trim();
    let host = host_from_url(actor);
    if username.is_empty() || host.is_empty() { return String::new(); }
    format!("{username}@{host}")
}

fn local_account_from_profile(
    actor_url: &str,
    username: &str,
    profiles: &HashMap<String, LocalProfile>,
) -> Value {
    let key = username.to_ascii_lowercase();
    let (display, avatar) = if let Some(p) = profiles.get(&key) {
        let d = if p.display_name.trim().is_empty() {
            username.to_string()
        } else {
            p.display_name.trim().to_string()
        };
        let a = if p.icon_url.starts_with("https://") {
            p.icon_url.clone()
        } else {
            LOCAL_DEFAULT_AVATAR.to_string()
        };
        (d, a)
    } else {
        (username.to_string(), LOCAL_DEFAULT_AVATAR.to_string())
    };
    empty_account(actor_url, username, username, &display, actor_url, &avatar)
}

fn materialize_outbox(
    row: &OutboxRow,
    owner_username: &str,
    profiles: &HashMap<String, LocalProfile>,
) -> Value {
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
    let account = local_account_from_profile(&actor_url, &username, profiles);
    let media = media_from_urls(&row.media_urls, &status_id);
    // Media-only compose stores empty <p></p> (and masto_statuses uses "(media)").
    // Without media_attachments lean Home painted a blank card (0.7.39).
    let mut content = row.content.clone();
    let plain = strip_tags_simple(&content);
    let plain_trim = plain.trim();
    if media.is_empty() {
        // keep content as-is
    } else if plain_trim.is_empty()
        || matches!(
            plain_trim,
            "(attachment)" | "(media)" | "(poll)" | "(quote)" | "(boost)"
        )
    {
        content.clear();
    }
    let mut st = base_status(
        &status_id,
        &created,
        &content,
        &row.id,
        &row.id,
        account,
        media,
        None,
    );
    st["sensitive"] = json!(row.sensitive || !row.spoiler_text.trim().is_empty());
    st["spoiler_text"] = json!(row.spoiler_text);
    if !row.ask_question.trim().is_empty() {
        st["vaak_ask"] = json!({
            "ask_actor": row.ask_actor,
            "ask_question": row.ask_question,
            "ask_answer": row.ask_answer,
        });
    }
    if !row.quote_object.trim().is_empty() {
        let target = row.quote_object.trim().trim_end_matches('/');
        let quoted_account = empty_account(
            target,
            "quoted",
            "Quoted post",
            LOCAL_DEFAULT_AVATAR,
            target,
            LOCAL_DEFAULT_AVATAR,
        );
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
    if !parent.is_empty() && (parent.starts_with("https://") || parent.starts_with("at://")) {
        st["vaak_in_reply_to_url"] = json!(parent);
        let parent_handle = reply_handle_from_object_url(parent);
        if !parent_handle.is_empty() {
            st["vaak_reply_parent_handle"] = json!(parent_handle);
        }
        if let Some((child_actor, _)) = row.id.rsplit_once("/notes/") {
            if let Some((parent_actor, _)) = parent.rsplit_once("/notes/") {
                if child_actor == parent_actor {
                    st["vaak_self_thread"] = json!(true);
                    // Paint/grouping key; snowflake parent id filled when parent
                    // is in the same hydrate batch (link_outbox_reply_ids).
                    st["in_reply_to_id"] = json!(parent);
                    if let Some(acct_id) = st
                        .get("account")
                        .and_then(|a| a.get("id"))
                        .cloned()
                    {
                        st["in_reply_to_account_id"] = acct_id;
                    }
                }
            }
        }
    }
    st
}

/// Fill local quote targets from outbox storage before painting Home/Local.
/// This keeps quote-boosts from degrading to a bare `quote_object` URL while
/// remaining cache/DB-only and bounded to the current page.
pub(crate) async fn hydrate_local_quote_targets(db: &Client, statuses: &mut [Value]) -> Result<()> {
    let mut targets = Vec::new();
    for st in statuses.iter() {
        if let Some(uri) = st.get("quote").and_then(|q| q.get("quoted_status"))
            .and_then(|q| q.get("uri")).and_then(|v| v.as_str())
            .filter(|u| !u.trim().is_empty()) {
            targets.push(uri.trim_end_matches('/').to_string());
        }
    }
    targets.sort();
    targets.dedup();
    if targets.is_empty() { return Ok(()); }
    let bsky_targets: Vec<String> = targets
        .iter()
        .filter(|target| !target.starts_with(LOCAL_ACTOR_PREFIX))
        .cloned()
        .collect();
    let bsky_quotes = fetch_bsky_map_for_targets(db, &bsky_targets)
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
    let mut by_target = HashMap::new();
    for row in rows {
        let id: String = row.get(0);
        let id = id.trim_end_matches('/').to_string();
        let username = id.strip_prefix(LOCAL_ACTOR_PREFIX)
            .and_then(|rest| rest.split('/').next()).unwrap_or("quoted");
        let media = media_from_urls(&serde_json::to_string(&attachment_urls_from_create_json(&row.get::<_, String>(2))).unwrap_or_else(|_| "[]".into()), &id);
        by_target.insert(id.clone(), json!({
            "id": id,
            "uri": id,
            "url": id,
            "content": row.get::<_, String>(1),
            "account": empty_account(&format!("{LOCAL_ACTOR_PREFIX}{username}"), username, username, username, &format!("{LOCAL_ACTOR_PREFIX}{username}"), LOCAL_DEFAULT_AVATAR),
            "media_attachments": media,
        }));
    }
    // Remote Fediverse quote targets are durable firehose Create events, not
    // Bluesky rows. Resolve them from the same event/actor caches used by the
    // timeline so a quote does not degrade to the synthetic “Quoted post”
    // placeholder when the original is already ingested locally.
    let fed_targets: Vec<String> = targets
        .iter()
        .filter(|target| {
            !target.starts_with(LOCAL_ACTOR_PREFIX)
                && !target.starts_with("at://")
                && !target.contains("bsky.app/")
        })
        .cloned()
        .collect();
    let fed_quotes = fetch_creates_by_object(db, &fed_targets)
        .await
        .unwrap_or_default();
    let fed_actor_ids: Vec<String> = fed_quotes.values().map(|row| row.actor_id.clone()).collect();
    let fed_actors = fetch_actors_map(db, &fed_actor_ids)
        .await
        .unwrap_or_default();
    for (target, event) in fed_quotes {
        let actor = fed_actors.get(event.actor_id.trim_end_matches('/'));
        let mut quoted = materialize_event_create(&event, actor);
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
    for (target, bsky) in bsky_quotes {
        let mut quoted = materialize_bsky(&bsky);
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

/// Pull https attachment URLs from a stored Create/Note JSON blob.
fn attachment_urls_from_create_json(raw: &str) -> Vec<String> {
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
            .and_then(|u| {
                u.as_str()
                    .map(|s| s.to_string())
                    .or_else(|| u.get("href").and_then(|h| h.as_str()).map(|s| s.to_string()))
            })
            .unwrap_or_default();
        let url = url.trim();
        if url.to_ascii_lowercase().starts_with("https://") {
            out.push(url.to_string());
        }
    }
    out
}

/// When parent + tip land in the same hydrate page, point in_reply_to_id at the
/// parent's status id (snowflake) instead of the note URL.
pub fn link_outbox_reply_ids(statuses: &mut [Value]) {
    let mut uri_to_id: HashMap<String, String> = HashMap::new();
    for st in statuses.iter() {
        let uri = st
            .get("uri")
            .and_then(|v| v.as_str())
            .or_else(|| st.get("url").and_then(|v| v.as_str()))
            .unwrap_or("")
            .trim()
            .trim_end_matches('/');
        let id = st.get("id").and_then(|v| v.as_str()).unwrap_or("");
        if !uri.is_empty() && !id.is_empty() {
            uri_to_id.insert(uri.to_string(), id.to_string());
        }
    }
    for st in statuses.iter_mut() {
        let parent = st
            .get("vaak_in_reply_to_url")
            .and_then(|v| v.as_str())
            .unwrap_or("")
            .trim()
            .trim_end_matches('/');
        if parent.is_empty() {
            continue;
        }
        if let Some(pid) = uri_to_id.get(parent) {
            st["in_reply_to_id"] = json!(pid);
        }
    }
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

fn link_header(statuses: &[Value], limit: i64, view: &str) -> String {
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
    let (base, extra) = match view {
        "local" => (
            "https://mkultra.monster/api/v1/timelines/public",
            "&local=true",
        ),
        "feed" => ("https://mkultra.monster/api/v1/timelines/public", ""),
        _ => ("https://mkultra.monster/api/v1/timelines/home", ""),
    };
    let mut parts = Vec::new();
    if !last.is_empty() {
        parts.push(format!(
            "<{base}?limit={limit}{extra}&max_id={last}>; rel=\"next\""
        ));
    }
    if !first.is_empty() {
        parts.push(format!(
            "<{base}?limit={limit}{extra}&min_id={first}>; rel=\"prev\""
        ));
    }
    parts.join(", ")
}

async fn load_ranked_head_for_view(
    redis: &mut redis::aio::MultiplexedConnection,
    owner: i64,
    want: usize,
    view: &str,
) -> Result<(Vec<RankedEntry>, String)> {
    let view = normalize_view(view);
    let index_key = format!("vaak:timeline:owner-index:v1:{owner}");
    let index = redis_util::json_get(redis, &index_key).await?;
    let logicals = collect_view_logicals(index.as_ref(), view);
    if logicals.is_empty() {
        bail!("owner ranked index miss (no {view} logical)");
    }

    // Owner index can list several follow-fingerprint keys. Prefer the deepest
    // envelope (then newest warm_ts) so hydrate/HTML deep scroll is not stuck
    // on a stale 160-item fan-out head while a fresh 400+ ranked warm exists.
    let mut best: Option<(String, Value, usize, i64)> = None;
    for logical in &logicals {
        let rk = ranked_redis_key(logical);
        let Some(env) = redis_util::json_get(redis, &rk).await? else {
            continue;
        };
        let n = env
            .get("ranked")
            .and_then(|v| v.as_array())
            .map(|a| a.len())
            .unwrap_or(0);
        if n == 0 {
            continue;
        }
        let ts = env
            .get("warm_ts")
            .or_else(|| env.get("ts"))
            .and_then(|v| v.as_i64())
            .unwrap_or(0);
        let replace = match &best {
            None => true,
            Some((_, _, bn, bts)) => n > *bn || (n == *bn && ts > *bts),
        };
        if replace {
            best = Some((logical.clone(), env, n, ts));
        }
    }
    let (logical, env, _n, _ts) = best
        .with_context(|| format!("ranked {view} envelope miss"))?;
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

#[allow(dead_code)]
async fn load_ranked_head(
    redis: &mut redis::aio::MultiplexedConnection,
    owner: i64,
    want: usize,
) -> Result<(Vec<RankedEntry>, String)> {
    load_ranked_head_for_view(redis, owner, want, "home").await
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

pub(crate) async fn fetch_bsky_map(db: &Client, uris: &[String]) -> Result<HashMap<String, BskyRow>> {
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
    // PHP parity: thin Jetstream/AppView rows can have empty denormalized
    // author columns while raw_json already contains the PostView author.
    // Patch those fields before the durable profile-cache fallback so a new
    // Bluesky card never paints as “Bluesky User” merely because actor-warm is
    // a few seconds behind ingestion.
    for post in map.values_mut() {
        if let Ok(raw) = serde_json::from_str::<Value>(&post.raw_json) {
            let author = raw.get("author")
                .or_else(|| raw.get("post").and_then(|p| p.get("author")));
            if let Some(author) = author {
                if post.author_handle.trim().is_empty() {
                    post.author_handle = author.get("handle").and_then(|v| v.as_str()).unwrap_or("").to_string();
                }
                if post.author_display.trim().is_empty() {
                    post.author_display = author.get("displayName").or_else(|| author.get("display_name"))
                        .and_then(|v| v.as_str()).unwrap_or("").to_string();
                }
                if post.author_avatar.trim().is_empty() {
                    post.author_avatar = author.get("avatar").and_then(|v| v.as_str()).unwrap_or("").to_string();
                }
            }
        }
    }
    // Jetstream can persist a thin post before its author view arrives. Use the
    // durable actor-profile cache as a second read source so cards do not paint
    // a DID/default avatar while actor-warm catches up.
    let missing: Vec<String> = map.values()
        .filter(|r| r.author_handle.trim().is_empty() || r.author_avatar.trim().is_empty())
        .map(|r| r.author_did.clone())
        .filter(|did| !did.trim().is_empty())
        .collect();
    if !missing.is_empty() {
        if let Ok(rows) = db.query(
            "SELECT did, profile_json FROM bsky_actor_profiles WHERE did = ANY($1) OR actor_ref = ANY($1)",
            &[&missing],
        ).await {
            for row in rows {
                let did: String = row.get(0);
                let raw: String = row.get(1);
                let Ok(profile) = serde_json::from_str::<Value>(&raw) else { continue; };
                let handle = profile.get("handle").and_then(|v| v.as_str()).unwrap_or("").to_string();
                let display = profile.get("displayName").or_else(|| profile.get("display_name")).and_then(|v| v.as_str()).unwrap_or("").to_string();
                let avatar = profile.get("avatar").and_then(|v| v.as_str()).unwrap_or("").to_string();
                for post in map.values_mut().filter(|p| p.author_did == did) {
                    if post.author_handle.trim().is_empty() { post.author_handle = handle.clone(); }
                    if post.author_display.trim().is_empty() { post.author_display = display.clone(); }
                    if post.author_avatar.trim().is_empty() { post.author_avatar = avatar.clone(); }
                }
            }
        }
    }
    Ok(map)
}

/// Resolve Bluesky DIDs to human handles from the durable actor-profile cache.
/// Jetstream may persist a reply before the parent post/profile is hydrated;
/// timeline chrome must never expose the DID in that window.
async fn fetch_bsky_profile_handles(db: &Client, dids: &[String]) -> Result<HashMap<String, String>> {
    let dids: Vec<String> = dids.iter()
        .map(|d| d.trim().to_string())
        .filter(|d| d.starts_with("did:"))
        .collect();
    if dids.is_empty() { return Ok(HashMap::new()); }
    let rows = db.query(
        "SELECT did, profile_json FROM bsky_actor_profiles WHERE did = ANY($1) OR actor_ref = ANY($1)",
        &[&dids],
    ).await?;
    let mut out = HashMap::new();
    for row in rows {
        let did: String = row.get(0);
        let raw: String = row.get(1);
        let Ok(profile) = serde_json::from_str::<Value>(&raw) else { continue; };
        let handle = profile.get("handle").and_then(|v| v.as_str()).unwrap_or("").trim();
        if !handle.is_empty() && !handle.starts_with("did:") {
            out.insert(did, handle.to_string());
        }
    }
    Ok(out)
}

pub(crate) async fn fetch_bsky_map_for_targets(
    db: &Client,
    targets: &[String],
) -> Result<HashMap<String, BskyRow>> {
    let direct: Vec<String> = targets
        .iter()
        .filter(|target| target.starts_with("at://"))
        .cloned()
        .collect();
    let canonical = fetch_bsky_map(db, &direct).await?;
    let mut out = HashMap::new();
    for target in &direct {
        if let Some(row) = canonical.get(target) {
            out.insert(target.clone(), row.clone());
        }
    }
    for target in targets
        .iter()
        .filter(|target| target.starts_with("https://bsky.app/profile/"))
    {
        let Some(rest) = target.strip_prefix("https://bsky.app/profile/") else {
            continue;
        };
        let Some((actor, rkey)) = rest.split_once("/post/") else {
            continue;
        };
        if actor.is_empty() || rkey.is_empty() {
            continue;
        }
        let suffix = format!("%/{rkey}");
        let row = db
            .query_opt(
                "SELECT bsky_uri FROM bsky_posts WHERE bsky_uri LIKE $1 AND lower(author_handle)=lower($2) LIMIT 1",
                &[&suffix, &actor],
            )
            .await?;
        let row = match row {
            Some(row) => Some(row),
            None => db
                .query_opt(
                    "SELECT bsky_uri FROM bsky_posts WHERE bsky_uri LIKE $1 LIMIT 1",
                    &[&suffix],
                )
                .await?,
        };
        let Some(row) = row else { continue };
        let uri: String = row.get(0);
        let fetched = fetch_bsky_map(db, std::slice::from_ref(&uri)).await?;
        if let Some(post) = fetched.get(&uri) {
            out.insert(target.clone(), post.clone());
        }
    }
    Ok(out)
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
                    COALESCE(spoiler_text,''), COALESCE(host,''), COALESCE(target_actor,''), COALESCE(in_reply_to,'')
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
                in_reply_to: row.get(11),
                ask_actor: String::new(),
                ask_question: String::new(),
                ask_answer: String::new(),
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
    // Compare normalized object IDs directly. Remote servers and our inbox
    // store disagree on trailing slashes, and profile quote envelopes must not
    // miss an otherwise exact cached Create because of that difference.
    let mut targets: Vec<String> = object_ids
        .iter()
        .map(|oid| oid.trim_end_matches('/').to_string())
        .filter(|oid| !oid.is_empty())
        .collect();
    targets.sort();
    targets.dedup();
    let rows = db
        .query(
            "SELECT DISTINCT ON (rtrim(object_id, '/'))
                    id, COALESCE(type,''), COALESCE(actor_id,''), COALESCE(object_id,''),
                    COALESCE(summary,''), COALESCE(media_urls,'[]'),
                    COALESCE(created_at::text,''), COALESCE(sensitive, 0),
                    COALESCE(spoiler_text,''), COALESCE(host,''), COALESCE(target_actor,''), COALESCE(in_reply_to,'')
             FROM events
             WHERE type = 'Create' AND rtrim(object_id, '/') = ANY($1)
             ORDER BY rtrim(object_id, '/'), id DESC",
            &[&targets],
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
                in_reply_to: row.get(11),
                ask_actor: String::new(),
                ask_question: String::new(),
                ask_answer: String::new(),
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
                    COALESCE(host,''), COALESCE(icon_source_url,''), COALESCE(profile_json,'')
             FROM remote_actors WHERE actor_id = ANY($1)",
            &[&variants],
        )
        .await
        .context("select remote_actors for hydrate")?;
    for row in rows {
        let actor_id: String = row.get(0);
        let key = actor_id.trim_end_matches('/').to_string();
        let mut username: String = row.get(1);
        let mut display_name: String = row.get(2);
        let mut icon: String = row.get(4);
        // Older remote_actors rows may retain a complete profile_json even
        // when the denormalized label/avatar columns are blank.
        let mut emojis = Value::Array(Vec::new());
        if let Ok(profile) = serde_json::from_str::<Value>(&row.get::<_, String>(5)) {
            if username.trim().is_empty() {
                username = profile.get("preferredUsername").or_else(|| profile.get("username"))
                    .and_then(|v| v.as_str()).unwrap_or("").to_string();
            }
            if display_name.trim().is_empty() {
                display_name = profile.get("name").or_else(|| profile.get("displayName"))
                    .and_then(|v| v.as_str()).unwrap_or("").to_string();
            }
            if icon.trim().is_empty() {
                icon = profile.get("icon").and_then(|v| v.get("url")).and_then(|v| v.as_str())
                    .or_else(|| profile.get("icon").and_then(|v| v.as_str())).unwrap_or("").to_string();
            }
            emojis = actor_emojis(&profile);
        }
        map.insert(
            key,
            ActorRow {
                actor_id,
                username,
                display_name,
                host: row.get(3),
                icon,
                emojis,
            },
        );
    }
    Ok(map)
}

async fn fetch_local_profiles_map(
    db: &Client,
    keys: &[String],
) -> Result<HashMap<String, LocalProfile>> {
    let mut map = HashMap::new();
    if keys.is_empty() {
        return Ok(map);
    }
    let mut keys: Vec<String> = keys
        .iter()
        .map(|k| k.trim().to_ascii_lowercase())
        .filter(|k| !k.is_empty())
        .collect();
    keys.sort();
    keys.dedup();
    let rows = db
        .query(
            "SELECT lower(actor_key), COALESCE(name, ''), COALESCE(icon_url, '')
             FROM actor_profile
             WHERE lower(actor_key) = ANY($1)",
            &[&keys],
        )
        .await
        .context("select actor_profile for local hydrate avatars")?;
    for row in rows {
        let key: String = row.get(0);
        map.insert(
            key,
            LocalProfile {
                display_name: row.get(1),
                icon_url: row.get(2),
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
            "SELECT o.id,
                    COALESCE(o.published::text,''),
                    COALESCE(o.content,''),
                    COALESCE(o.in_reply_to,''),
                    COALESCE(o.raw_create_json,''),
                    COALESCE(s.sensitive, 0),
                    COALESCE(s.spoiler_text, '')
             FROM outbox_notes o
             LEFT JOIN masto_statuses s
               ON s.note_id = o.id OR s.note_id = rtrim(o.id, '/') OR s.note_id = o.id || '/'
             WHERE o.id = ANY($1)",
            &[&variants],
        )
        .await
        .context("select outbox_notes for hydrate")?;

    // Batch media URLs by note id (masto_media via status_local_id).
    let mut media_by_note: HashMap<String, Vec<String>> = HashMap::new();
    if let Ok(media_rows) = db
        .query(
            "SELECT s.note_id, COALESCE(m.public_url, '')
             FROM masto_statuses s
             JOIN masto_media m ON m.status_local_id = s.local_id
             WHERE s.note_id = ANY($1)
             ORDER BY m.local_id ASC",
            &[&variants],
        )
        .await
    {
        for mrow in media_rows {
            let note: String = mrow.get(0);
            let url: String = mrow.get(1);
            let url = url.trim().to_string();
            if !url.to_ascii_lowercase().starts_with("https://") {
                continue;
            }
            let key = note.trim_end_matches('/').to_string();
            let slot = media_by_note.entry(key).or_default();
            if slot.len() < 4 && !slot.iter().any(|u| u == &url) {
                slot.push(url);
            }
        }
    }

    for row in rows {
        let id: String = row.get(0);
        let key = id.trim_end_matches('/').to_string();
        let raw_create: String = row.try_get(4).unwrap_or_default();
        let sensitive_i: i64 = row.try_get(5).unwrap_or_else(|_| {
            i64::from(row.try_get::<_, i32>(5).unwrap_or(0))
        });
        let spoiler: String = row.try_get(6).unwrap_or_default();
        let mut urls = media_by_note.remove(&key).unwrap_or_default();
        if urls.is_empty() {
            urls = attachment_urls_from_create_json(&raw_create);
        }
        let media_urls = serde_json::to_string(&urls).unwrap_or_else(|_| "[]".into());
        map.insert(
            key,
            OutboxRow {
                id,
                published: row.get(1),
                content: row.get(2),
                in_reply_to: row.get(3),
                media_urls,
                sensitive: sensitive_i != 0,
                spoiler_text: spoiler,
                ask_actor: String::new(),
                ask_question: String::new(),
                ask_answer: String::new(),
                quote_object: quote_target_from_raw_create(&raw_create),
            },
        );
    }
    Ok(map)
}

async fn fetch_ask_context_map(db: &Client, object_ids: &[String]) -> Result<HashMap<String, AskContext>> {
    let mut keys: Vec<String> = object_ids
        .iter()
        .map(|id| id.trim_end_matches('/').to_string())
        .filter(|id| !id.is_empty())
        .collect();
    keys.sort();
    keys.dedup();
    if keys.is_empty() {
        return Ok(HashMap::new());
    }
    let mut variants = keys.clone();
    variants.extend(keys.iter().map(|id| format!("{id}/")));
    variants.sort();
    variants.dedup();
    let mut out = HashMap::new();
    if let Ok(rows) = db.query(
        "SELECT object_id, COALESCE(ask_actor,''), COALESCE(ask_question,''), COALESCE(ask_answer,'')
         FROM mentions WHERE object_id = ANY($1) AND deleted_at IS NULL AND ask_question <> ''
         ORDER BY id DESC",
        &[&variants],
    ).await {
        for row in rows {
            let key: String = row.get::<_, String>(0).trim_end_matches('/').to_string();
            out.entry(key).or_insert(AskContext {
                actor: row.get(1), question: row.get(2), answer: row.get(3),
            });
        }
    }
    if let Ok(rows) = db.query(
        "SELECT answer_note_id, COALESCE(asker_actor,''), COALESCE(question,'')
         FROM ap_asks WHERE answer_note_id = ANY($1) ORDER BY id DESC",
        &[&variants],
    ).await {
        for row in rows {
            let key: String = row.get::<_, String>(0).trim_end_matches('/').to_string();
            out.entry(key).or_insert(AskContext {
                actor: row.get(1), question: row.get(2), answer: String::new(),
            });
        }
    }
    Ok(out)
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

/// Local ranked boost ids are `masto_reblogs.status_id` (sometimes boost_status_id).
/// Prefer rows for `owner_user_id` when duplicates exist (PHP parity).
async fn fetch_boosts_map(
    db: &Client,
    ids: &[String],
    owner_user_id: i64,
) -> Result<HashMap<String, BoostRow>> {
    let mut map = HashMap::new();
    if ids.is_empty() {
        return Ok(map);
    }
    let rows = db
        .query(
            "SELECT id, owner_user_id, COALESCE(owner_actor_id,''), COALESCE(status_id,''),
                    COALESCE(boost_status_id,''), COALESCE(object_id,''),
                    COALESCE(target_actor,''), COALESCE(announce_activity_id,''),
                    COALESCE(created_at::text,'')
             FROM masto_reblogs
             WHERE status_id = ANY($1) OR boost_status_id = ANY($1)
             ORDER BY CASE WHEN owner_user_id = $2 THEN 0 ELSE 1 END, id DESC",
            &[&ids, &owner_user_id],
        )
        .await
        .context("select masto_reblogs for local hydrate")?;
    for row in rows {
        let owner_uid = row.try_get::<_, i64>(1).unwrap_or_else(|_| {
            i64::from(row.try_get::<_, i32>(1).unwrap_or(0))
        });
        let rb_id = row.try_get::<_, i64>(0).unwrap_or_else(|_| {
            i64::from(row.try_get::<_, i32>(0).unwrap_or(0))
        });
        let rb = BoostRow {
            id: rb_id,
            owner_user_id: owner_uid,
            owner_actor_id: row.get(2),
            status_id: row.get(3),
            boost_status_id: row.get(4),
            object_id: row.get(5),
            target_actor: row.get(6),
            announce_activity_id: row.get(7),
            created_at: row.get(8),
        };
        // Index under both keys so ranked status_id or boost_status_id resolve.
        if !rb.status_id.is_empty() {
            map.entry(rb.status_id.clone()).or_insert_with(|| rb.clone());
        }
        if !rb.boost_status_id.is_empty() {
            map.entry(rb.boost_status_id.clone())
                .or_insert_with(|| rb.clone());
        }
    }
    Ok(map)
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

/// PHP `ap_masto_status_from_reblog` + thin Create fallback (Local boosts).
fn materialize_boost(
    rb: &BoostRow,
    create: Option<&EventRow>,
    rss: Option<&RssRow>,
    actors: &HashMap<String, ActorRow>,
    local_profiles: &HashMap<String, LocalProfile>,
) -> Option<Value> {
    if reblog_is_bsky_native(&rb.status_id, &rb.object_id) {
        return None;
    }
    let created = format_time(&rb.created_at);
    let outer_id = if !rb.boost_status_id.trim().is_empty() {
        rb.boost_status_id.clone()
    } else if rb.id > 0 {
        snowflake_id(&created, rb.id, 4)
    } else {
        return None;
    };

    let owner_actor = rb.owner_actor_id.trim_end_matches('/').to_string();
    let username = owner_actor
        .rsplit('/')
        .next()
        .filter(|s| !s.is_empty())
        .unwrap_or("unknown")
        .to_string();
    let booster_acct = if local_username_from_url(&owner_actor).is_some() {
        local_account_from_profile(&owner_actor, &username, local_profiles)
    } else {
        empty_account(
            &owner_actor,
            &username,
            &username,
            &username,
            &owner_actor,
            DEFAULT_AVATAR,
        )
    };

    let object_id = rb.object_id.trim_end_matches('/').to_string();
    if create.is_none() && rss.is_none() {
        // Keep unresolved local boosts out of visible timelines. The ranked
        // worker will include them after the original object is cached.
        return None;
    }
    let mut inner = if let Some(rss_row) = rss {
        materialize_rss(rss_row)
    } else if let Some(crow) = create {
        let cactor = actors.get(crow.actor_id.trim_end_matches('/'));
        materialize_event_create(crow, cactor)
    } else {
        // Thin degraded original (PHP stub when Create missing).
        let target = if !rb.target_actor.trim().is_empty() {
            rb.target_actor.trim_end_matches('/').to_string()
        } else {
            String::new()
        };
        let account = if !target.is_empty() {
            if let Some(a) = actors.get(target.as_str()) {
                actor_to_account(a)
            } else {
                let host = host_from_url(&target);
                let uname = target
                    .rsplit('/')
                    .next()
                    .unwrap_or("unknown")
                    .to_string();
                let acct = if host.is_empty() {
                    uname.clone()
                } else {
                    format!("{uname}@{host}")
                };
                empty_account(&target, &uname, &acct, &uname, &target, DEFAULT_AVATAR)
            }
        } else {
            empty_account("unknown", "unknown", "unknown", "unknown", "", DEFAULT_AVATAR)
        };
        let uri = if object_id.is_empty() {
            rb.status_id.as_str()
        } else {
            object_id.as_str()
        };
        let inner_id = if !rb.status_id.is_empty() {
            rb.status_id.clone()
        } else {
            announce_inner_synth_id(&created, uri)
        };
        let mut st = base_status(
            &inner_id,
            &created,
            "<p></p>",
            uri,
            uri,
            account,
            Vec::new(),
            None,
        );
        st["reblogged"] = json!(true);
        st["vaak_degraded"] = json!(true);
        st["vaak_degraded_reason"] = json!("boost_original_missing");
        st
    };

    // Unwrap nested boosts — wrapper always points at the original Note.
    if let Some(nested) = inner.get("reblog").filter(|v| v.is_object()).cloned() {
        inner = nested;
    }
    inner["reblogged"] = json!(true);
    inner["reblog"] = Value::Null;

    let announce_uri = rb.announce_activity_id.trim();
    let uri = if !announce_uri.is_empty() {
        announce_uri.to_string()
    } else {
        format!("{owner_actor}/announces/{outer_id}")
    };
    let url = inner
        .get("url")
        .and_then(|v| v.as_str())
        .or_else(|| inner.get("uri").and_then(|v| v.as_str()))
        .unwrap_or("")
        .to_string();

    Some(json!({
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
        "reblogs_count": 1,
        "favourites_count": 0,
        "edited_at": Value::Null,
        "favourited": false,
        "reblogged": true,
        "muted": false,
        "bookmarked": false,
        "pinned": false,
        "content": "",
        "reblog": inner,
        "application": {"name": "mkultra.monster", "website": "https://mkultra.monster"},
        "account": booster_acct,
        "media_attachments": [],
        "mentions": [],
        "tags": [],
        "emojis": [],
        "card": Value::Null,
        "poll": Value::Null,
        "vaak_boost_row_id": rb.id,
    }))
}

/// Materialize Mastodon statuses from a view's ranked head and write
/// `vaak:timeline:v1:*` envelopes for each limit.
pub async fn warm_view(
    cfg: &Config,
    owner_user_id: i64,
    view: &str,
    limits: &[i64],
) -> Result<WarmReport> {
    let view = normalize_view(view);
    let started = Instant::now();
    let mut limits: Vec<i64> = limits
        .iter()
        .copied()
        .filter(|n| (1..=MAX_HYDRATE_LIMIT).contains(n))
        .collect();
    if limits.is_empty() {
        limits = DEFAULT_LIMITS.to_vec();
    }
    limits.sort_unstable();
    limits.dedup();
    let want = *limits.iter().max().unwrap_or(&MAX_HYDRATE_LIMIT) as usize;

    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let (head, _logical) =
        load_ranked_head_for_view(&mut redis, owner_user_id, want.max(80), view).await?;
    let ranked_n = head.len();

    let mut rss_ids = Vec::new();
    let mut bsky_uris = Vec::new();
    let mut event_ids = Vec::new();
    let mut outbox_ids = Vec::new();
    let mut boost_ids = Vec::new();
    for e in &head {
        match (view, e.kind.as_str()) {
            ("home", "rss") => {
                if let Ok(id) = e.id.parse::<i64>() {
                    if id > 0 {
                        rss_ids.push(id);
                    }
                }
            }
            ("home", "bsky") => bsky_uris.push(e.id.clone()),
            ("home" | "local" | "feed", "event") => {
                if let Ok(id) = e.id.parse::<i64>() {
                    if id > 0 {
                        event_ids.push(id);
                    }
                }
            }
            ("home" | "local", "outbox") => outbox_ids.push(e.id.clone()),
            ("local", "boost") => boost_ids.push(e.id.clone()),
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
    boost_ids.sort();
    boost_ids.dedup();

    let db = crate::db::connect(&cfg.database_url).await?;
    let hidden = crate::hidden::load_hidden_sets(&db, owner_user_id).await?;
    let owner_username = load_owner_username(&db, owner_user_id).await?;
    let mut rss_map = fetch_rss_map(&db, &rss_ids, owner_user_id).await?;
    let bsky_map = fetch_bsky_map(&db, &bsky_uris).await?;
    // Reply cards need the parent's author label even when the parent itself
    // is not on this timeline page. Hydrate the already-cached parent records
    // in one bounded query (never a network fetch).
    let mut bsky_parent_uris = Vec::new();
    for row in bsky_map.values() {
        let parent = bsky_reply_parent_uri(&row.raw_json);
        if !parent.is_empty() {
            bsky_parent_uris.push(parent);
        }
    }
    bsky_parent_uris.sort();
    bsky_parent_uris.dedup();
    let bsky_parent_map = fetch_bsky_map(&db, &bsky_parent_uris).await?;
    let unresolved_parent_dids: Vec<String> = bsky_parent_uris.iter()
        .filter_map(|uri| {
            let did = uri.strip_prefix("at://")?.split('/').next()?.trim();
            if did.starts_with("did:") {
                let row_handle = bsky_parent_map.get(uri)
                    .map(|row| row.author_handle.trim())
                    .unwrap_or("");
                if row_handle.is_empty() || row_handle.starts_with("did:") {
                    return Some(did.to_string());
                }
            }
            None
        })
        .collect();
    let bsky_parent_profile_handles = fetch_bsky_profile_handles(&db, &unresolved_parent_dids)
        .await
        .unwrap_or_default();
    let mut events_map = fetch_events_map(&db, &event_ids).await?;
    let boosts_map = fetch_boosts_map(&db, &boost_ids, owner_user_id).await?;
    let mut rss_boost_ids = Vec::new();
    for rb in boosts_map.values() {
        for key in [&rb.status_id, &rb.object_id, &rb.boost_status_id] {
            if let Some(id) = rss_item_id_from_key(key) {
                rss_boost_ids.push(id);
                break;
            }
        }
    }
    rss_boost_ids.sort_unstable();
    rss_boost_ids.dedup();
    if !rss_boost_ids.is_empty() {
        rss_map.extend(fetch_rss_map(&db, &rss_boost_ids, owner_user_id).await?);
    }

    // Announce / boost → look up Create for object_id; collect all actor ids.
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
    for rb in boosts_map.values() {
        if !rb.owner_actor_id.is_empty() {
            actor_ids.push(rb.owner_actor_id.trim_end_matches('/').to_string());
        }
        if !rb.target_actor.is_empty() {
            actor_ids.push(rb.target_actor.trim_end_matches('/').to_string());
        }
        if !rb.object_id.is_empty() {
            announce_oids.push(rb.object_id.trim_end_matches('/').to_string());
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
    let mut outbox_map = fetch_outbox_map(&db, &outbox_ids).await?;
    let mut ask_ids: Vec<String> = events_map.values().map(|e| e.object_id.clone()).collect();
    ask_ids.extend(outbox_map.keys().cloned());
    let ask_map = fetch_ask_context_map(&db, &ask_ids).await?;
    for event in events_map.values_mut() {
        if let Some(ask) = ask_map.get(event.object_id.trim_end_matches('/')) {
            event.ask_actor = ask.actor.clone();
            event.ask_question = ask.question.clone();
            event.ask_answer = ask.answer.clone();
        }
    }
    for (id, note) in outbox_map.iter_mut() {
        if let Some(ask) = ask_map.get(id.trim_end_matches('/')) {
            note.ask_actor = ask.actor.clone();
            note.ask_question = ask.question.clone();
            note.ask_answer = ask.answer.clone();
        }
    }

    // Local outbox / boost authors → actor_profile icons (0.7.24).
    let mut local_keys: Vec<String> = Vec::new();
    for row in outbox_map.values() {
        if let Some((prefix, _)) = row.id.rsplit_once("/notes/") {
            if let Some(u) = local_username_from_url(prefix) {
                local_keys.push(u);
            }
        }
    }
    for rb in boosts_map.values() {
        if let Some(u) = local_username_from_url(&rb.owner_actor_id) {
            local_keys.push(u);
        }
    }
    if !owner_username.is_empty() {
        local_keys.push(owner_username.to_ascii_lowercase());
    }
    let local_profiles = fetch_local_profiles_map(&db, &local_keys).await?;

    let mut statuses = Vec::new();
    let mut kinds: HashMap<String, usize> = HashMap::new();
    for e in &head {
        let st = match (view, e.kind.as_str()) {
            ("home", "rss") => {
                let id = e.id.parse::<i64>().unwrap_or(0);
                rss_map.get(&id).map(materialize_rss)
            }
            ("home", "bsky") => bsky_map.get(&e.id).map(|row| {
                let mut st = materialize_bsky(row);
                if let Some(parent_uri) = st.get("vaak_in_reply_to_url").and_then(|v| v.as_str()).map(str::to_owned) {
                    if let Some(parent) = bsky_parent_map.get(&parent_uri) {
                        if !parent.author_handle.trim().is_empty()
                            && !parent.author_handle.trim().starts_with("did:") {
                            st["vaak_reply_parent_handle"] = json!(parent.author_handle.trim());
                        }
                        if !parent.author_display.trim().is_empty() {
                            st["vaak_reply_parent_display"] = json!(parent.author_display.trim());
                        }
                    }
                    if st.get("vaak_reply_parent_handle")
                        .and_then(|v| v.as_str())
                        .map(|h| h.is_empty() || h.starts_with("did:"))
                        .unwrap_or(true)
                    {
                        if let Some(did) = parent_uri.strip_prefix("at://")
                            .and_then(|rest| rest.split('/').next())
                        {
                            if let Some(handle) = bsky_parent_profile_handles.get(did) {
                                st["vaak_reply_parent_handle"] = json!(handle);
                            }
                        }
                    }
                }
                st
            }),
            ("home" | "feed", "event") => {
                let id = e.id.parse::<i64>().unwrap_or(0);
                events_map.get(&id).and_then(|ev| {
                    let actor = actors_map.get(ev.actor_id.trim_end_matches('/'));
                    if ev.event_type.eq_ignore_ascii_case("announce") {
                        let create = creates_map.get(ev.object_id.trim_end_matches('/'));
                        let summary_empty = strip_tags_simple(&ev.summary).trim().is_empty();
                        let media_empty = ev.media_urls.is_empty() || ev.media_urls == "[]";
                        if create.is_none() && summary_empty && media_empty {
                            // Do not expose a hollow boost while its original
                            // Create is still waiting on ingestion/hydration.
                            return None;
                        }
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
            ("home" | "local", "outbox") => outbox_map
                .get(e.id.trim_end_matches('/'))
                .map(|row| materialize_outbox(row, &owner_username, &local_profiles)),
            ("local", "boost") => boosts_map.get(&e.id).and_then(|rb| {
                let create = creates_map.get(rb.object_id.trim_end_matches('/'));
                let rss = rss_item_id_from_key(&rb.status_id)
                    .or_else(|| rss_item_id_from_key(&rb.object_id))
                    .or_else(|| rss_item_id_from_key(&rb.boost_status_id))
                    .and_then(|id| rss_map.get(&id));
                materialize_boost(rb, create, rss, &actors_map, &local_profiles)
            }),
            _ => None,
        };
        if let Some(st) = st {
            // Ranked caches can briefly contain an item from before a mute or
            // instance block changed. Filter again at hydration so stale
            // envelopes cannot leak blocked actors into the Rust timeline.
            if crate::hidden::status_hidden(&st, &hidden) {
                continue;
            }
            *kinds.entry(e.kind.clone()).or_insert(0) += 1;
            statuses.push(st);
        }
        if statuses.len() >= want {
            break;
        }
    }
    let _ = hydrate_local_quote_targets(&db, &mut statuses).await;
    link_outbox_reply_ids(&mut statuses);

    // Hydrated timelines are consumed directly by Mastodon-compatible clients
    // as well as the lean HTML painter. Resolve already-cached OG/YouTube cards
    // here so Local and Federated JSON have the same preview metadata as Home;
    // the HTML path still repeats this cheaply at paint time for cache misses.
    let _ = crate::link_preview::attach_cached_cards(&db, &mut statuses).await;

    let mut stored = Vec::new();
    let now = chrono::Utc::now().timestamp();
    for &limit in &limits {
        let slice: Vec<Value> = statuses.iter().take(limit as usize).cloned().collect();
        let body = serde_json::to_string(&slice).unwrap_or_else(|_| "[]".into());
        let link = link_header(&slice, limit, view);
        let key = timeline::timeline_hydrate_redis_key(view, owner_user_id, limit, None);
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
    let source = match view {
        "local" => "vaak-worker-local-hydrate-ranked",
        "feed" => "vaak-worker-feed-hydrate-ranked",
        _ => "vaak-worker-home-hydrate-ranked",
    };
    Ok(WarmReport {
        owner_user_id,
        ranked_n,
        materialised_n: statuses.len(),
        stored,
        kinds,
        ms,
        source,
        note: format!(
            "view={view} ranked={ranked_n} materialised={} kinds={kinds_note}",
            statuses.len()
        ),
    })
}

/// Home-only wrapper (account-switch / existing callers).
pub async fn warm_owner(cfg: &Config, owner_user_id: i64, limits: &[i64]) -> Result<WarmReport> {
    warm_view(cfg, owner_user_id, "home", limits).await
}

/// Fire-and-forget ranked→hydrate for a view (cooldown via Redis).
/// Returns a short status token for ranked-warm log lines.
pub async fn maybe_warm_after_ranked_view(
    redis: &mut redis::aio::MultiplexedConnection,
    cfg: &Config,
    owner_user_id: i64,
    view: &str,
) -> String {
    let view = normalize_view(view);
    let flag = match view {
        "local" | "feed" => "VAAK_PUBLIC_HYDRATE_WARM",
        _ => "VAAK_HOME_HYDRATE_WARM",
    };
    if !env_flag_default_true(flag) {
        return "hydrate=skip_flag".into();
    }
    let cooldown_env = match view {
        "local" | "feed" => "VAAK_PUBLIC_HYDRATE_WARM_COOLDOWN_SECS",
        _ => "VAAK_HOME_HYDRATE_WARM_COOLDOWN_SECS",
    };
    let cooldown = std::env::var(cooldown_env)
        .ok()
        .and_then(|s| s.parse().ok())
        .unwrap_or(60)
        .clamp(30, 600);
    let cooldown_key = format!("vaak:{view}:hydrate-warm:cd:{owner_user_id}");
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
    let view_owned = view.to_string();
    tokio::spawn(async move {
        let t0 = Instant::now();
        match warm_view(&cfg, owner_user_id, &view_owned, &DEFAULT_LIMITS).await {
            Ok(report) => {
                tracing::info!(
                    owner = owner_user_id,
                    view = %view_owned,
                    ms = report.ms,
                    n = report.materialised_n,
                    ranked = report.ranked_n,
                    note = %report.note,
                    "timeline hydrate ranked warm ok"
                );
            }
            Err(e) => {
                tracing::warn!(
                    owner = owner_user_id,
                    view = %view_owned,
                    ms = t0.elapsed().as_millis(),
                    error = %format!("{e:#}"),
                    "timeline hydrate ranked warm failed"
                );
            }
        }
    });
    "hydrate=spawned_ranked".into()
}

/// Fire-and-forget Home ranked→hydrate (cooldown via Redis).
pub async fn maybe_warm_after_ranked(
    redis: &mut redis::aio::MultiplexedConnection,
    cfg: &Config,
    owner_user_id: i64,
) -> String {
    maybe_warm_after_ranked_view(redis, cfg, owner_user_id, "home").await
}

/// CLI: warm a view immediately (clears cooldown).
pub async fn warm_view_now(cfg: &Config, owner_user_id: i64, view: &str) -> Result<WarmReport> {
    let view = normalize_view(view);
    if let Ok(mut redis) = redis_util::connect(&cfg.redis_url).await {
        let cooldown_key = format!("vaak:{view}:hydrate-warm:cd:{owner_user_id}");
        let _: Result<(), _> = redis::cmd("DEL")
            .arg(&cooldown_key)
            .query_async(&mut redis)
            .await;
    }
    warm_view(cfg, owner_user_id, view, &DEFAULT_LIMITS).await
}

/// CLI / account-switch: warm Home immediately (clears cooldown).
pub async fn warm_owner_now(cfg: &Config, owner_user_id: i64) -> Result<WarmReport> {
    warm_view_now(cfg, owner_user_id, "home").await
}

fn status_note_uri(st: &Value) -> String {
    st.get("uri")
        .and_then(|v| v.as_str())
        .or_else(|| st.get("url").and_then(|v| v.as_str()))
        .unwrap_or("")
        .trim()
        .trim_end_matches('/')
        .to_string()
}

fn collect_poll_uris(st: &Value, out: &mut Vec<String>) {
    let uri = status_note_uri(st);
    if uri.starts_with("https://") {
        out.push(uri);
    }
    if let Some(reblog) = st.get("reblog").filter(|v| v.is_object()) {
        collect_poll_uris(reblog, out);
    }
    if let Some(quoted) = st
        .get("quote")
        .and_then(|q| q.get("quoted_status"))
        .filter(|v| v.is_object())
    {
        collect_poll_uris(quoted, out);
    }
    if let Some(quoted) = st.get("vaak_quote_preview").filter(|v| v.is_object()) {
        collect_poll_uris(quoted, out);
    }
}

fn apply_saved_poll(st: &mut Value, polls: &HashMap<String, Value>) {
    let uri = status_note_uri(st);
    if let Some(poll) = polls.get(&uri) {
        if let Some(obj) = st.as_object_mut() {
            obj.insert("poll".to_string(), poll.clone());
        }
    }
    if let Some(reblog) = st.get_mut("reblog").filter(|v| v.is_object()) {
        apply_saved_poll(reblog, polls);
    }
    if let Some(quoted) = st
        .get_mut("quote")
        .and_then(|q| q.get_mut("quoted_status"))
        .filter(|v| v.is_object())
    {
        apply_saved_poll(quoted, polls);
    }
    if let Some(quoted) = st.get_mut("vaak_quote_preview").filter(|v| v.is_object()) {
        apply_saved_poll(quoted, polls);
    }
}

fn poll_expires_label(raw: &str) -> (bool, String) {
    let raw = raw.trim();
    if raw.is_empty() {
        return (false, String::new());
    }
    let when = chrono::DateTime::parse_from_rfc3339(raw)
        .ok()
        .map(|d| d.with_timezone(&chrono::Utc));
    let Some(when) = when else {
        return (false, String::new());
    };
    let expired = when <= chrono::Utc::now();
    use chrono::{Datelike, Timelike};
    const MONTHS: [&str; 12] = [
        "Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec",
    ];
    let month = MONTHS
        .get(when.month0() as usize)
        .copied()
        .unwrap_or("Jan");
    (
        expired,
        format!(
            "{} {month} {}, {} · {:02}:{:02} UTC",
            if expired { "ended" } else { "ends" },
            when.day(),
            when.year(),
            when.hour(),
            when.minute()
        ),
    )
}

fn poll_voter_hit(voters_json: &str, viewer_actor: &str) -> bool {
    let viewer = viewer_actor.trim().trim_end_matches('/').to_ascii_lowercase();
    if viewer.is_empty() {
        return false;
    }
    let Ok(value) = serde_json::from_str::<Value>(voters_json) else {
        return false;
    };
    let Some(voters) = value.as_array() else {
        return false;
    };
    voters.iter().any(|voter| {
        voter
            .as_str()
            .map(|s| s.trim().trim_end_matches('/').eq_ignore_ascii_case(&viewer))
            .unwrap_or(false)
    })
}

/// Overlay `masto_polls` onto already-hydrated statuses. Warm Redis envelopes
/// hard-code `poll: null`; Home, Local, Federated, and profiles paint from
/// those envelopes, so the options have to be attached at request time.
pub async fn attach_polls(db: &Client, statuses: &mut [Value], viewer_actor: &str) -> Result<()> {
    let mut uris = Vec::new();
    for st in statuses.iter() {
        collect_poll_uris(st, &mut uris);
    }
    uris.sort();
    uris.dedup();
    if uris.is_empty() {
        return Ok(());
    }
    let mut keys = uris.clone();
    keys.extend(uris.iter().map(|uri| format!("{uri}/")));
    let rows = db
        .query(
            "SELECT local_id::bigint,
                    rtrim(note_id, '/'),
                    COALESCE(multiple, 0)::text,
                    COALESCE(expires_at::text, ''),
                    COALESCE(options_json, '[]'),
                    COALESCE(votes_count, 0)::bigint,
                    COALESCE(voters_count, 0)::bigint,
                    COALESCE(voters_json, '[]')
             FROM masto_polls
             WHERE note_id = ANY($1)",
            &[&keys],
        )
        .await
        .context("select masto_polls for timeline cards")?;
    let mut polls: HashMap<String, Value> = HashMap::new();
    for row in rows {
        let local_id: i64 = row.try_get(0).unwrap_or(0);
        let note_id: String = row.try_get::<_, String>(1).unwrap_or_default();
        let note_id = note_id.trim().trim_end_matches('/').to_string();
        if note_id.is_empty() {
            continue;
        }
        let multiple_s: String = row.try_get(2).unwrap_or_default();
        let multiple = matches!(
            multiple_s.trim().to_ascii_lowercase().as_str(),
            "1" | "t" | "true"
        );
        let expires_at: String = row.try_get(3).unwrap_or_default();
        let options_json: String = row.try_get(4).unwrap_or_else(|_| "[]".into());
        let votes_count: i64 = row.try_get(5).unwrap_or(0);
        let voters_count: i64 = row.try_get(6).unwrap_or(0);
        let voters_json: String = row.try_get(7).unwrap_or_else(|_| "[]".into());
        let (expired, expires_label) = poll_expires_label(&expires_at);
        let options = serde_json::from_str::<Value>(&options_json).unwrap_or(Value::Null);
        polls.insert(
            note_id,
            json!({
                "id": local_id.to_string(),
                "expired": expired,
                "multiple": multiple,
                "votes_count": votes_count,
                "voters_count": voters_count,
                "voted": poll_voter_hit(&voters_json, viewer_actor),
                "expires_label": expires_label,
                "options": options,
            }),
        );
    }
    if polls.is_empty() {
        return Ok(());
    }
    for st in statuses.iter_mut() {
        apply_saved_poll(st, &polls);
    }
    Ok(())
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
    fn quote_target_reads_activitypub_quote_field() {
        let raw = r#"{"type":"Create","object":{"type":"Note","quote":"https://mkultra.monster/users/cmdr_nova/notes/quoted/"}}"#;
        assert_eq!(quote_target_from_raw_create(raw), "https://mkultra.monster/users/cmdr_nova/notes/quoted");
    }

    #[test]
    fn snowflake_stable() {
        let a = snowflake_id("2026-10-05T07:00:45.000Z", 690764, 1);
        let b = snowflake_id("2026-10-05T07:00:45.000Z", 690764, 1);
        assert_eq!(a, b);
        assert!(a.len() >= 16);
    }

    #[test]
    fn format_time_postgres_short_offset() {
        // rss_items.published_at::text often looks like this — Ice Cubes needs ISO.
        let out = format_time("2026-10-05 08:43:15+02");
        assert!(
            out.ends_with('Z') && out.contains('T'),
            "expected RFC3339 UTC, got {out}"
        );
        assert_eq!(out, "2026-10-05T06:43:15.000Z");
        let out2 = format_time("2026-10-05 08:43:15+0200");
        assert_eq!(out2, "2026-10-05T06:43:15.000Z");
        let out3 = format_time("2026-10-05T08:43:15.000Z");
        assert_eq!(out3, "2026-10-05T08:43:15.000Z");
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

    #[test]
    fn pick_view_logical_home_local_feed() {
        let index = json!([
            "v13_feed_u1_ana_aaa",
            "v13_local_u1_ana_bbb",
            "v13_home_u1_aon_ccc",
            "v13_home_u1_aon_ddd"
        ]);
        assert_eq!(
            pick_view_logical(Some(&index), "home").as_deref(),
            Some("v13_home_u1_aon_ccc")
        );
        assert_eq!(
            collect_view_logicals(Some(&index), "home"),
            vec![
                "v13_home_u1_aon_ccc".to_string(),
                "v13_home_u1_aon_ddd".to_string()
            ]
        );
        assert_eq!(
            pick_view_logical(Some(&index), "local").as_deref(),
            Some("v13_local_u1_ana_bbb")
        );
        assert_eq!(
            pick_view_logical(Some(&index), "feed").as_deref(),
            Some("v13_feed_u1_ana_aaa")
        );
    }

    #[test]
    fn reblog_skip_helpers() {
        assert!(reblog_is_bsky_native("bsky-repost-abc", "https://example.com/x"));
        assert!(reblog_is_bsky_native("x", "https://bsky.app/profile/a/post/b"));
        assert!(reblog_is_rss_local("rss-boost:1", ""));
        assert!(reblog_is_rss_local("local:rss-quote:1:2", ""));
        assert_eq!(rss_item_id_from_key("local:rss-boost:42:1"), Some(42));
        assert_eq!(rss_item_id_from_key("rss:7"), Some(7));
        assert!(!reblog_is_bsky_native("12345", "https://example.com/notes/1"));
    }

    #[test]
    fn shared_public_media_materializer_preserves_images_and_video_posters() {
        let urls = serde_json::json!([
            "https://cdn.example/media/photo.jpg?width=1200",
            "https://files.example/media/original/clip.mp4",
            "http://insecure.example/nope.jpg",
            "https://cdn.example/media/second.webp",
            "https://cdn.example/media/third.png",
            "https://cdn.example/media/fifth.jpg"
        ])
        .to_string();
        let media = media_from_urls(&urls, "status-1");
        // Local and Federated use this same materializer; keep PHP's four-item
        // attachment limit and never emit non-HTTPS media.
        assert_eq!(media.len(), 4);
        assert_eq!(media[0]["type"], "image");
        assert_eq!(media[0]["preview_url"], "https://cdn.example/media/photo.jpg?width=1200");
        assert_eq!(media[1]["type"], "video");
        assert_eq!(
            media[1]["preview_url"],
            "https://files.example/media/small/clip.png"
        );
        assert_eq!(media[3]["id"], "status-14");
    }

    #[test]
    fn shared_public_media_materializer_classifies_audio_and_voice_notes() {
        let urls = serde_json::json!([
            "https://cdn.example/media/voice.mp3?download=1",
            "https://cdn.example/media/voice-note-abc.webm",
            "https://cdn.example/media/clip.webm",
            "https://cdn.example/media/photo.jpg"
        ])
        .to_string();
        let media = media_from_urls(&urls, "status");
        assert_eq!(media[0]["type"], "audio");
        assert!(media[0]["preview_url"].is_null());
        assert_eq!(media[1]["type"], "audio");
        assert_eq!(media[2]["type"], "video");
        assert_eq!(media[3]["type"], "image");
    }

    #[test]
    fn shared_public_media_materializer_rejects_malformed_payloads() {
        assert!(media_from_urls("not-json", "status").is_empty());
        assert!(media_from_urls("{}", "status").is_empty());
        assert!(media_from_urls(r#"[null, 42, "ftp://example/file.jpg"]"#, "status").is_empty());
    }

    #[test]
    fn local_and_federated_event_cards_keep_media_and_cw_metadata() {
        let media_urls = serde_json::json!(["https://cdn.example/media/photo.jpg"]).to_string();
        let actor = ActorRow {
            actor_id: "https://mkultra.monster/users/test_account".into(),
            username: "test_account".into(),
            display_name: "Test Account".into(),
            host: "mkultra.monster".into(),
            icon: DEFAULT_AVATAR.into(),
            emojis: Value::Array(Vec::new()),
        };
        let local = EventRow {
            id: 41,
            event_type: "Create".into(),
            actor_id: actor.actor_id.clone(),
            object_id: "https://mkultra.monster/notes/41".into(),
            summary: "(media)".into(),
            media_urls: media_urls.clone(),
            created_at: "2026-10-05T12:00:00Z".into(),
            sensitive: false,
            spoiler_text: "Gallery".into(),
            host: "mkultra.monster".into(),
            target_actor: String::new(),
            in_reply_to: String::new(),
            ask_actor: String::new(),
            ask_question: String::new(),
            ask_answer: String::new(),
        };
        let mut federated = local.clone();
        federated.id = 42;
        federated.actor_id = "https://remote.example/users/test".into();
        federated.object_id = "https://remote.example/notes/42".into();
        federated.host = "remote.example".into();
        let local_status = materialize_event_create(&local, Some(&actor));
        let federated_status = materialize_event_create(&federated, None);
        for status in [local_status, federated_status] {
            assert_eq!(status["media_attachments"].as_array().map(Vec::len), Some(1));
            assert_eq!(status["spoiler_text"], json!("Gallery"));
            assert_eq!(status["content"], json!(""));
        }
    }

    #[test]
    fn quote_summary_becomes_structured_preview_and_keeps_commentary() {
        let (commentary, quote) = split_quote_summary(
            "My commentary\n\n↪ QT @alice@example.test: quoted words https://example.test/posts/9",
        );
        assert_eq!(commentary, "My commentary");
        let quote = quote.expect("quote preview");
        assert_eq!(quote["account"]["acct"], "alice@example.test");
        assert_eq!(quote["url"], "https://example.test/posts/9");
        assert!(quote["content"].as_str().unwrap_or("").contains("quoted words"));

        let row = EventRow {
            id: 901,
            event_type: "Create".into(),
            actor_id: "https://remote.example/users/author".into(),
            object_id: "https://remote.example/notes/901".into(),
            summary: "My commentary\n\n↪ QT @alice@example.test: quoted words https://example.test/posts/9".into(),
            media_urls: "[]".into(),
            created_at: "2026-10-05T12:00:00Z".into(),
            sensitive: false,
            spoiler_text: String::new(),
            host: "remote.example".into(),
            target_actor: String::new(),
            in_reply_to: String::new(),
            ask_actor: String::new(),
            ask_question: String::new(),
            ask_answer: String::new(),
        };
        let status = materialize_event_create(&row, None);
        assert_eq!(status["quote"]["state"], "accepted");
        assert_eq!(status["quote"]["quoted_status"]["url"], "https://example.test/posts/9");
        assert_eq!(status["content"], "<p>My commentary</p>");
    }

    #[test]
    fn quote_summary_supports_re_permalink_fallback() {
        let (commentary, quote) = split_quote_summary(
            "A reply\nRE: https://example.test/@alice/9",
        );
        assert_eq!(commentary, "A reply");
        assert_eq!(quote.expect("quote preview")["url"], "https://example.test/@alice/9");
    }

    #[test]
    fn cached_actor_emoji_metadata_matches_mastodon_shape() {
        let profile = serde_json::json!({
            "tag": [
                {"type":"Emoji", "name":":spark:", "icon":{"url":"https://cdn.example/spark.png"}},
                {"type":"Emoji", "name":"spark", "icon":{"url":"https://cdn.example/spark.png"}},
                {"type":"Hashtag", "name":"#ignored"},
                {"type":"Emoji", "name":":insecure:", "icon":{"url":"http://cdn.example/nope.png"}}
            ]
        });
        let emojis = actor_emojis(&profile);
        assert_eq!(emojis.as_array().map(Vec::len), Some(1));
        assert_eq!(emojis[0]["shortcode"], "spark");
        assert_eq!(emojis[0]["static_url"], "https://cdn.example/spark.png");
    }

    #[test]
    fn bsky_empty_record_gets_permalink_card_and_reply_parent() {
        let row = BskyRow {
            uri: "at://did:plc:child/app.bsky.feed.post/rkey".into(),
            author_did: "did:plc:child".into(),
            author_handle: "child.example".into(),
            author_display: "Child".into(),
            author_avatar: String::new(),
            indexed_at: "2026-10-06T01:00:00Z".into(),
            published_at: "2026-10-06T01:00:00Z".into(),
            text: String::new(),
            embed_json: String::new(),
            raw_json: r#"{"record":{"text":"","reply":{"parent":{"uri":"at://did:plc:parent/app.bsky.feed.post/parent"}}}}"#.into(),
            like_count: 0,
            repost_count: 0,
            reply_count: 0,
        };
        let status = materialize_bsky(&row);
        assert_eq!(status["vaak_in_reply_to_url"], "at://did:plc:parent/app.bsky.feed.post/parent");
        assert_eq!(status["card"]["title"], "Bluesky post");
        assert!(status["card"]["url"].as_str().unwrap_or("").contains("bsky.app/profile/child.example/post/rkey"));
    }

    #[test]
    fn hydrated_boost_is_hidden_when_underlying_actor_is_blocked() {
        let mut hidden = crate::hidden::HiddenSets::default();
        hidden.instance_blocked_actors.insert("https://blocked.example/users/x".into());
        let status = json!({
            "account": {"uri": "https://booster.example/users/y"},
            "reblog": {"account": {"uri": "https://blocked.example/users/x"}}
        });
        assert!(crate::hidden::status_hidden(&status, &hidden));
    }
}
