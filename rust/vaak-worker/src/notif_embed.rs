//! Mentions nested-card HTML fragments (`vaak:notif-embed:v1:*`).
//!
//! 0.6.89–0.6.90: Redis read + PHP lean paint.
//! 0.6.91: on miss, resolve status JSON from the owner's Mentions Redis
//! envelopes and paint a lean nest card in Rust (no poll/ask/link-preview).

use anyhow::Result;
use chrono::{DateTime, Utc};
use redis::AsyncCommands;
use serde_json::Value;
use sha2::{Digest, Sha256};

pub const EMBED_TTL_SECS: u64 = 180;

pub fn frag_key(
    owner_id: i64,
    uri: &str,
    hide_header: bool,
    favourited: bool,
    reblogged: bool,
    bookmarked: bool,
    status_id: &str,
) -> String {
    let uri = uri.trim().trim_end_matches('/');
    let flag_bits = format!(
        "{}{}{}",
        if favourited { "1" } else { "0" },
        if reblogged { "1" } else { "0" },
        if bookmarked { "1" } else { "0" }
    );
    let logical = format!(
        "{owner_id}|{uri}|h{}|f{flag_bits}|id{status_id}",
        if hide_header { "1" } else { "0" }
    );
    let mut hasher = Sha256::new();
    hasher.update(logical.as_bytes());
    format!("vaak:notif-embed:v1:{}", hex::encode(hasher.finalize()))
}

fn uri_eq(a: &str, b: &str) -> bool {
    a.trim().trim_end_matches('/') == b.trim().trim_end_matches('/')
}

/// Find a Mastodon-shaped status in the owner's warm Mentions Redis envelopes.
pub async fn find_status_in_notif_envelopes(
    redis: &mut redis::aio::MultiplexedConnection,
    owner_id: i64,
    uri: &str,
) -> Result<Option<Value>> {
    let pattern = format!("vaak:notifications:v1:{owner_id}:*");
    // Owners keep a handful of Mentions keys — KEYS is fine at this scale.
    let keys: Vec<String> = redis::cmd("KEYS")
        .arg(&pattern)
        .query_async(redis)
        .await
        .unwrap_or_default();
    for key in keys {
        if key.contains(":unread:") {
            continue;
        }
        let raw: Option<String> = redis.get(&key).await.ok().flatten();
        let Some(raw) = raw else { continue };
        let Ok(payload) = serde_json::from_str::<Value>(&raw) else {
            continue;
        };
        let items = payload
            .get("items")
            .and_then(|v| v.as_array())
            .cloned()
            .unwrap_or_default();
        for item in items {
            let Some(st) = item.get("status") else {
                continue;
            };
            if !st.is_object() {
                continue;
            }
            let st_uri = st
                .get("uri")
                .and_then(|v| v.as_str())
                .or_else(|| st.get("url").and_then(|v| v.as_str()))
                .unwrap_or("");
            if uri_eq(st_uri, uri) {
                return Ok(Some(st.clone()));
            }
            // Unwrap reblog wrapper when the outer uri is empty / mismatch.
            if let Some(inner) = st.get("reblog").filter(|v| v.is_object()) {
                let inner_uri = inner
                    .get("uri")
                    .and_then(|v| v.as_str())
                    .or_else(|| inner.get("url").and_then(|v| v.as_str()))
                    .unwrap_or("");
                if uri_eq(inner_uri, uri) {
                    return Ok(Some(inner.clone()));
                }
            }
        }
    }
    Ok(None)
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

fn strip_tags(html: &str) -> String {
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
    // Collapse common entities enough for body plain / empty checks.
    out.replace("&nbsp;", " ")
        .replace("&amp;", "&")
        .replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&quot;", "\"")
        .replace("&#39;", "'")
        .replace("&#039;", "'")
        .replace("&apos;", "'")
}

/// True when Mastodon/AP `content` looks like markup we should paint as HTML.
fn content_looks_like_html(s: &str) -> bool {
    let t = s.trim();
    t.contains('<') && (t.contains("</") || t.contains("/>") || t.contains("<p") || t.contains("<a "))
}

fn looks_like_status_url(url: &str) -> bool {
    let u = url.trim();
    if u.is_empty() {
        return false;
    }
    lazy_regex_is_match(
        r"(?i)^https://[^/]+/(?:users|@)[^/]+/(?:statuses|posts)/",
        u,
    ) || lazy_regex_is_match(r"(?i)^https://bsky\.app/profile/[^/]+/post/", u)
        || lazy_regex_is_match(r"(?i)^https://[^/]+/ap/(?:users|actors?)/[^/]+/statuses/", u)
        || lazy_regex_is_match(r"(?i)^https://[^/]+/@[^/]+/\d+", u)
}

fn urls_loosely_equivalent(a: &str, b: &str) -> bool {
    let na = a.trim().trim_end_matches('/').to_ascii_lowercase();
    let nb = b.trim().trim_end_matches('/').to_ascii_lowercase();
    if na.is_empty() || nb.is_empty() {
        return false;
    }
    if na == nb {
        return true;
    }
    // Same host + trailing numeric snowflake (GTS @handle/id ↔ /users/…/statuses/id).
    let snow = |u: &str| -> Option<String> {
        u.rsplit('/')
            .next()
            .filter(|s| !s.is_empty() && s.chars().all(|c| c.is_ascii_digit()))
            .map(|s| s.to_string())
    };
    match (snow(&na), snow(&nb)) {
        (Some(sa), Some(sb)) if sa == sb => {
            let ha = na.split('/').nth(2).unwrap_or("");
            let hb = nb.split('/').nth(2).unwrap_or("");
            !ha.is_empty() && ha == hb
        }
        _ => false,
    }
}

fn attr_value<'a>(tag: &'a str, name: &str) -> Option<&'a str> {
    let dq = format!("{name}=\"");
    if let Some(i) = tag.find(&dq) {
        let rest = &tag[i + dq.len()..];
        if let Some(end) = rest.find('"') {
            return Some(&rest[..end]);
        }
    }
    let sq = format!("{name}='");
    if let Some(i) = tag.find(&sq) {
        let rest = &tag[i + sq.len()..];
        if let Some(end) = rest.find('\'') {
            return Some(&rest[..end]);
        }
    }
    None
}

fn class_list_has(classes: &str, want: &str) -> bool {
    classes
        .split_whitespace()
        .any(|c| c.eq_ignore_ascii_case(want))
}

fn strip_trailing_url_punct(url: &str) -> (&str, &str) {
    let bytes = url.as_bytes();
    let mut end = bytes.len();
    while end > 0 {
        match bytes[end - 1] {
            b'.' | b',' | b';' | b':' | b'!' | b'?' | b')' | b']' | b'}' | b'\'' | b'"' => {
                end -= 1;
            }
            _ => break,
        }
    }
    if end == 0 || end == bytes.len() {
        (url, "")
    } else {
        (&url[..end], &url[end..])
    }
}

fn rewrite_anchor_open(tag: &str, from: &str) -> String {
    let href_raw = attr_value(tag, "href").unwrap_or("").trim();
    let href = html_entity_decode_basic(href_raw);
    let classes = attr_value(tag, "class").unwrap_or("");
    let is_mention = class_list_has(classes, "mention") && !class_list_has(classes, "hashtag");
    let is_hashtag = class_list_has(classes, "hashtag");
    let from_q = if from.is_empty() { "home" } else { from };

    if is_hashtag {
        let tag_name = percent_decode_basic(
            href
                .trim_end_matches('/')
                .rsplit('/')
                .next()
                .unwrap_or("")
                .trim_start_matches('#'),
        );
        let q = if tag_name.is_empty() {
            href.clone()
        } else {
            format!("#{tag_name}")
        };
        let new_href = format!(
            "?view=search&q={}&type=statuses",
            urlencoding_encode(&q)
        );
        return format!("<a class=\"hashtag\" href=\"{}\">", esc(&new_href));
    }

    if is_mention && href.starts_with("https://") {
        let new_href = format!(
            "?view=remote_profile&actor={}&from={}",
            urlencoding_encode(&href),
            urlencoding_encode(from_q)
        );
        return format!("<a class=\"mention\" href=\"{}\">", esc(&new_href));
    }

    if href.starts_with("https://") {
        if looks_like_status_url(&href) {
            let new_href = format!(
                "?view=status&object={}&from={}",
                urlencoding_encode(&href),
                urlencoding_encode(from_q)
            );
            return format!("<a class=\"status-link\" href=\"{}\">", esc(&new_href));
        }
        return format!(
            "<a class=\"ext-link\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer nofollow\">",
            esc(&href)
        );
    }

    if href.starts_with('?') || href.starts_with('/') {
        if is_mention {
            return format!("<a class=\"mention\" href=\"{}\">", esc(&href));
        }
        if is_hashtag {
            return format!("<a class=\"hashtag\" href=\"{}\">", esc(&href));
        }
        return format!("<a class=\"ext-link\" href=\"{}\">", esc(&href));
    }
    tag.to_string()
}

fn html_entity_decode_basic(s: &str) -> String {
    s.replace("&amp;", "&")
        .replace("&quot;", "\"")
        .replace("&#39;", "'")
        .replace("&#039;", "'")
        .replace("&apos;", "'")
        .replace("&lt;", "<")
        .replace("&gt;", ">")
}

/// Decode %XX sequences so hashtag path segments are not double-encoded in search q=.
fn percent_decode_basic(s: &str) -> String {
    let bytes = s.as_bytes();
    let mut out: Vec<u8> = Vec::with_capacity(bytes.len());
    let mut i = 0usize;
    while i < bytes.len() {
        if bytes[i] == b'%' && i + 2 < bytes.len() {
            let h1 = bytes[i + 1];
            let h2 = bytes[i + 2];
            let hex = |c: u8| -> Option<u8> {
                match c {
                    b'0'..=b'9' => Some(c - b'0'),
                    b'a'..=b'f' => Some(c - b'a' + 10),
                    b'A'..=b'F' => Some(c - b'A' + 10),
                    _ => None,
                }
            };
            if let (Some(a), Some(b)) = (hex(h1), hex(h2)) {
                out.push((a << 4) | b);
                i += 3;
                continue;
            }
        }
        out.push(bytes[i]);
        i += 1;
    }
    String::from_utf8(out).unwrap_or_else(|_| s.to_string())
}

fn linkify_text_segment(text: &str, from: &str) -> String {
    if !text.contains("http://") && !text.contains("https://") && !text.contains("www.") {
        return text.to_string();
    }
    let from_q = if from.is_empty() { "home" } else { from };
    let mut out = String::with_capacity(text.len() + 32);
    let bytes = text.as_bytes();
    let mut i = 0usize;
    while i < bytes.len() {
        let rest = &text[i..];
        let http = rest.find("https://");
        let http2 = rest.find("http://");
        let www = rest.find("www.");
        let mut cand: Option<(usize, bool)> = None;
        for (idx, needs) in [(http, false), (http2, false), (www, true)] {
            if let Some(p) = idx {
                if cand.map(|(c, _)| p < c).unwrap_or(true) {
                    if needs {
                        let abs = i + p;
                        if abs >= 8 {
                            let prev = &text[abs.saturating_sub(8)..abs];
                            if prev.ends_with("https://") || prev.ends_with("http://") {
                                continue;
                            }
                        }
                    }
                    cand = Some((p, needs));
                }
            }
        }
        let Some((rel, needs_https)) = cand else {
            out.push_str(rest);
            break;
        };
        out.push_str(&rest[..rel]);
        let after = &rest[rel..];
        let mut end = after
            .find(|c: char| c.is_whitespace() || c == '<' || c == '>' || c == '"')
            .unwrap_or(after.len());
        let (core, trail) = strip_trailing_url_punct(&after[..end]);
        end = core.len();
        let mut href = if needs_https {
            format!("https://{core}")
        } else {
            core.to_string()
        };
        href = html_entity_decode_basic(&href);
        if href.contains("...") || href.contains('…') || !href.starts_with("http") {
            out.push_str(&after[..end]);
            out.push_str(trail);
            i += rel + end + trail.len();
            continue;
        }
        let label = esc(core);
        let anchor = if looks_like_status_url(&href) {
            let new_href = format!(
                "?view=status&object={}&from={}",
                urlencoding_encode(&href),
                urlencoding_encode(from_q)
            );
            format!("<a class=\"status-link\" href=\"{}\">{label}</a>", esc(&new_href))
        } else {
            format!(
                "<a class=\"ext-link\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer nofollow\">{label}</a>",
                esc(&href)
            )
        };
        out.push_str(&anchor);
        out.push_str(trail);
        i += rel + end + trail.len();
    }
    out
}

/// Collapse consecutive breaks and tighten Mastodon HTML for feed bodies.
fn collapse_html_breaks(html: &str) -> String {
    let mut out = html.to_string();
    for _ in 0..4 {
        let next = lazy_regex_replace_all(r"(?i)(<br\s*/?>\s*){2,}", &out, "<br>");
        if next == out {
            break;
        }
        out = next;
    }
    out
}

/// Prepare Mastodon/AP content HTML for lean feed paint: tighten breaks,
/// rewrite mention/hashtag/ext anchors in-app, and linkify bare URLs.
fn prepare_feed_body_html(html: &str, from: &str) -> String {
    let collapsed = collapse_html_breaks(html.trim());
    let mut out = String::with_capacity(collapsed.len() + 64);
    let mut rest = collapsed.as_str();
    let mut in_anchor = false;
    while !rest.is_empty() {
        if let Some(start) = rest.find('<') {
            if start > 0 {
                let text = &rest[..start];
                if in_anchor {
                    out.push_str(text);
                } else {
                    out.push_str(&linkify_text_segment(text, from));
                }
            }
            if let Some(end_rel) = rest[start..].find('>') {
                let tag_end = start + end_rel + 1;
                let tag = &rest[start..tag_end];
                let lower = tag.to_ascii_lowercase();
                if lower.starts_with("<a ") || lower.starts_with("<a>") {
                    out.push_str(&rewrite_anchor_open(tag, from));
                    in_anchor = true;
                } else if lower.starts_with("</a") {
                    out.push_str("</a>");
                    in_anchor = false;
                } else {
                    out.push_str(tag);
                }
                rest = &rest[tag_end..];
            } else {
                if in_anchor {
                    out.push_str(rest);
                } else {
                    out.push_str(&linkify_text_segment(rest, from));
                }
                break;
            }
        } else {
            if in_anchor {
                out.push_str(rest);
            } else {
                out.push_str(&linkify_text_segment(rest, from));
            }
            break;
        }
    }
    out
}

fn paint_link_card_html(card: &Value) -> String {
    let url = card
        .get("url")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .trim();
    if !url.starts_with("https://") && !url.starts_with("http://") {
        return String::new();
    }
    let title = card.get("title").and_then(|v| v.as_str()).unwrap_or("").trim();
    let desc = card
        .get("description")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .trim();
    let provider = card
        .get("provider_name")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .trim();
    let image = card
        .get("image")
        .and_then(|v| v.as_str())
        .filter(|s| s.starts_with("https://"))
        .unwrap_or("");
    let desc_html = if desc.is_empty() {
        String::new()
    } else {
        format!("<div class=\"link-card__desc\">{}</div>", esc(desc))
    };
    let prov_html = if provider.is_empty() {
        String::new()
    } else {
        format!("<div class=\"link-card__provider\">{}</div>", esc(provider))
    };
    let img_html = if image.is_empty() {
        String::new()
    } else {
        format!(
            "<div class=\"link-card__media\"><img src=\"{}\" alt=\"\" loading=\"lazy\" referrerpolicy=\"no-referrer\"></div>",
            esc(image)
        )
    };
    let title_html = if title.is_empty() {
        esc(url)
    } else {
        esc(title)
    };
    format!(
        "<a class=\"link-card\" href=\"{url}\" target=\"_blank\" rel=\"nofollow noopener noreferrer\">{img}<div class=\"link-card__body\">{prov}<div class=\"link-card__title\">{title}</div>{desc}</div></a>",
        url = esc(url),
        img = img_html,
        prov = prov_html,
        title = title_html,
        desc = desc_html
    )
}

fn status_has_media(st: &Value) -> bool {
    st.get("media_attachments")
        .and_then(|v| v.as_array())
        .map(|a| !a.is_empty())
        .unwrap_or(false)
}

fn quote_url_for_dedupe(st: &Value) -> String {
    if let Some(q) = st
        .get("quote")
        .filter(|v| v.is_object())
        .or_else(|| st.get("vaak_quote_preview").filter(|v| v.is_object()))
    {
        if let Some(qs) = q.get("quoted_status").filter(|v| v.is_object()) {
            let u = qs
                .get("url")
                .and_then(|v| v.as_str())
                .or_else(|| qs.get("uri").and_then(|v| v.as_str()))
                .unwrap_or("");
            if !u.is_empty() {
                return u.to_string();
            }
        }
        for key in ["url", "uri", "quote_url"] {
            if let Some(u) = q.get(key).and_then(|v| v.as_str()).filter(|s| !s.is_empty()) {
                return u.to_string();
            }
        }
    }
    String::new()
}

fn paint_status_link_card(st: &Value) -> String {
    if status_has_media(st) {
        return String::new();
    }
    let Some(card) = st.get("card").filter(|v| v.is_object()) else {
        return String::new();
    };
    let card_url = card.get("url").and_then(|v| v.as_str()).unwrap_or("");
    let painted_quote = quote_url_for_dedupe(st);
    if !painted_quote.is_empty()
        && !card_url.is_empty()
        && urls_loosely_equivalent(card_url, &painted_quote)
    {
        return String::new();
    }
    paint_link_card_html(card)
}

/// Tiny helpers over the `regex` crate without threading Regex objects everywhere.
fn lazy_regex_is_match(pattern: &str, hay: &str) -> bool {
    regex::Regex::new(pattern)
        .map(|re| re.is_match(hay))
        .unwrap_or(false)
}

fn lazy_regex_replace_all(pattern: &str, hay: &str, rep: &str) -> String {
    match regex::Regex::new(pattern) {
        Ok(re) => re.replace_all(hay, rep).into_owned(),
        Err(_) => hay.to_string(),
    }
}

fn relative_time(created_at: &str) -> String {
    let Ok(dt) = DateTime::parse_from_rfc3339(created_at) else {
        return String::new();
    };
    let now = Utc::now();
    let secs = (now - dt.with_timezone(&Utc)).num_seconds().max(0);
    if secs < 60 {
        return "just now".into();
    }
    if secs < 3600 {
        return format!("{}m", secs / 60);
    }
    if secs < 86400 {
        return format!("{}h", secs / 3600);
    }
    if secs < 86400 * 30 {
        return format!("{}d", secs / 86400);
    }
    format!("{}mo", secs / (86400 * 30))
}

fn is_bsky(st: &Value, acct: &str, uri: &str) -> bool {
    let source = st.get("source").and_then(|v| v.as_str()).unwrap_or("");
    if source == "bluesky" || st.get("bsky_post").is_some() {
        return true;
    }
    if uri.starts_with("at://") || uri.contains("bsky.app/") {
        return true;
    }
    let sid = st.get("id").and_then(|v| v.as_str()).unwrap_or("");
    if sid.starts_with("bsky:") {
        return true;
    }
    acct.to_ascii_lowercase().ends_with("@bsky.app")
}

fn is_rss(st: &Value) -> bool {
    if st.get("vaak_rss_item_id").and_then(|v| v.as_i64()).unwrap_or(0) > 0 {
        return true;
    }
    let source = st.get("source").and_then(|v| v.as_str()).unwrap_or("");
    if source == "rss" {
        return true;
    }
    let sid = st.get("id").and_then(|v| v.as_str()).unwrap_or("");
    sid.starts_with("rss:")
}

fn media_row_html(st: &Value) -> String {
    let Some(atts) = st.get("media_attachments").and_then(|v| v.as_array()) else {
        return String::new();
    };
    let mut cells = Vec::new();
    for att in atts.iter().take(4) {
        let url = att
            .get("url")
            .and_then(|v| v.as_str())
            .or_else(|| att.get("preview_url").and_then(|v| v.as_str()))
            .unwrap_or("");
        if !url.starts_with("https://") {
            continue;
        }
        let atype = att
            .get("type")
            .and_then(|v| v.as_str())
            .unwrap_or("")
            .to_ascii_lowercase();
        let preview = att
            .get("preview_url")
            .and_then(|v| v.as_str())
            .unwrap_or("");
        if atype == "video" || atype == "gifv" {
            let poster = if preview.starts_with("https://") {
                format!(" poster=\"{}\"", esc(preview))
            } else {
                String::new()
            };
            cells.push(format!(
                "<video class=\"media-video\" src=\"{}\" controls loop playsinline preload=\"metadata\"{} referrerpolicy=\"no-referrer\"></video>",
                esc(url),
                poster
            ));
        } else if atype == "audio" {
            cells.push(format!(
                "<div class=\"media-audio-card\" role=\"group\" aria-label=\"Audio post\"><audio class=\"media-audio\" src=\"{}\" controls preload=\"metadata\"></audio></div>",
                esc(url)
            ));
        } else {
            cells.push(format!(
                "<button type=\"button\" class=\"media-lightbox-trigger\" data-full=\"{u}\" title=\"View image\"><img src=\"{u}\" alt=\"\" loading=\"lazy\" referrerpolicy=\"no-referrer\" decoding=\"async\"></button>",
                u = esc(url)
            ));
        }
    }
    if cells.is_empty() {
        return String::new();
    }
    let n = cells.len();
    format!(
        "<div class=\"media-row media-row--n{n}\">{}</div>",
        cells.join("")
    )
}

/// Lean Mentions nest HTML — classes match PHP `notif-status-embed` chrome.
pub fn paint_lean_embed(status: &Value, hide_header: bool) -> String {
    paint_lean_embed_from(status, hide_header, "mentions")
}

/// Lean status card HTML with `from=` deep-link context (Mentions nest or Home fill).
pub fn paint_lean_embed_from(status: &Value, hide_header: bool, from: &str) -> String {
    let account = status.get("account").cloned().unwrap_or(Value::Null);
    let acct = account
        .get("acct")
        .and_then(|v| v.as_str())
        .unwrap_or("?");
    let display = account
        .get("display_name")
        .and_then(|v| v.as_str())
        .filter(|s| !s.is_empty())
        .unwrap_or(acct);
    let avatar = account
        .get("avatar")
        .and_then(|v| v.as_str())
        .or_else(|| account.get("avatar_static").and_then(|v| v.as_str()))
        .unwrap_or("");
    let actor_ref = account
        .get("uri")
        .and_then(|v| v.as_str())
        .or_else(|| account.get("url").and_then(|v| v.as_str()))
        .unwrap_or("");
    let uri = status
        .get("uri")
        .and_then(|v| v.as_str())
        .or_else(|| status.get("url").and_then(|v| v.as_str()))
        .unwrap_or("")
        .trim_end_matches('/');
    let created = status
        .get("created_at")
        .and_then(|v| v.as_str())
        .unwrap_or("");
    let when = relative_time(created);
    let content_html = status.get("content").and_then(|v| v.as_str()).unwrap_or("");
    let plain = strip_tags(content_html).trim().to_string();
    let spoiler = status
        .get("spoiler_text")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .trim();
    let sensitive = status
        .get("sensitive")
        .and_then(|v| v.as_bool())
        .unwrap_or(false)
        || !spoiler.is_empty();
    let bsky = is_bsky(status, acct, uri);
    let rss = is_rss(status);
    let visibility = status
        .get("visibility")
        .and_then(|v| v.as_str())
        .unwrap_or("public");

    let mut article_classes = String::from("tweet");
    if hide_header {
        article_classes.push_str(" tweet-embed-nohd");
    }
    if bsky {
        article_classes.push_str(" tweet-bsky");
    }

    let mut inner = String::new();

    if !hide_header {
        let av = if avatar.starts_with("https://") {
            avatar
        } else {
            "https://mkultra.monster/img/avatar/default.jpg"
        };
        let from_q = if from.is_empty() { "mentions" } else { from };
        let profile_href = if actor_ref.starts_with("https://") {
            format!(
                "?view=remote_profile&actor={}&from={}",
                urlencoding_encode(actor_ref),
                urlencoding_encode(from_q)
            )
        } else {
            String::new()
        };
        let av_img = format!(
            "<img class=\"tweet-av\" src=\"{}\" alt=\"\" width=\"40\" height=\"40\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\">",
            esc(av)
        );
        let av_block = if !profile_href.is_empty() {
            format!(
                "<a href=\"{}\" style=\"text-decoration:none\">{}</a>",
                esc(&profile_href),
                av_img
            )
        } else {
            av_img
        };
        let who = if !profile_href.is_empty() {
            format!(
                "<a class=\"who\" href=\"{}\" style=\"color:inherit;text-decoration:none\">{}</a><a class=\"meta\" href=\"{}\" style=\"color:var(--muted);text-decoration:none\"> @{}</a>",
                esc(&profile_href),
                esc(display),
                esc(&profile_href),
                esc(acct)
            )
        } else {
            format!(
                "<span class=\"who\">{}</span><span class=\"meta\"> @{}</span>",
                esc(display),
                esc(acct)
            )
        };
        let mut tags = String::new();
        if bsky {
            tags.push_str("<span class=\"tag\" title=\"From Bluesky\">Bluesky</span>");
        }
        if rss {
            tags.push_str("<span class=\"tag\" title=\"From an RSS/Atom feed you added\">RSS</span>");
        }
        let when_html = if when.is_empty() {
            String::new()
        } else {
            format!("<span class=\"meta\"> · {}</span>", esc(&when))
        };
        inner.push_str(&format!(
            "<div class=\"tweet-hd\">{av}<div class=\"tweet-hd-main tweet-hd-main--fedi\"><div>{who}{when}{tags}</div></div></div>",
            av = av_block,
            who = who,
            when = when_html,
            tags = tags
        ));
    } else {
        let mut chips = String::new();
        if bsky {
            chips.push_str("<span class=\"tag\" title=\"From Bluesky\">Bluesky</span>");
        }
        if rss {
            chips.push_str("<span class=\"tag\" title=\"From an RSS/Atom feed you added\">RSS</span>");
        }
        if visibility != "public" {
            chips.push_str(&format!(
                "<span class=\"tag\" title=\"Audience\">{}</span>",
                esc(visibility)
            ));
        }
        if !chips.is_empty() {
            inner.push_str(&format!(
                "<div class=\"meta meta-row tweet-embed-chips\" style=\"margin:.15rem 0 .35rem\">{chips}</div>"
            ));
        }
    }

    let mut body_inner = String::new();
    // Prefer original status HTML (mentions/hashtags/links + correct entities).
    // strip_tags+esc dropped <a> and double-encoded &#039; / &quot; into visible codes (0.7.13).
    // 0.7.14: rewrite anchors in-app, linkify bare URLs, tighten breaks, paint OG cards.
    let content_trim = content_html.trim();
    if content_looks_like_html(content_trim) {
        let prepared = prepare_feed_body_html(content_trim, from);
        body_inner.push_str(&format!(
            "<div class=\"body feed-body feed-body--html\">{prepared}</div>"
        ));
    } else if !plain.is_empty() {
        let linked = linkify_text_segment(&esc(&plain), from);
        body_inner.push_str(&format!(
            "<div class=\"body feed-body\" style=\"white-space:pre-wrap\">{linked}</div>"
        ));
    }
    body_inner.push_str(&media_row_html(status));
    body_inner.push_str(&paint_status_link_card(status));

    if sensitive && !body_inner.is_empty() {
        let label = if spoiler.is_empty() {
            "Sensitive content".to_string()
        } else {
            spoiler.to_string()
        };
        inner.push_str(&format!(
            "<details class=\"cw-gate\"><summary class=\"cw-summary\">{}</summary><div class=\"cw-body\">{}</div></details>",
            esc(&label),
            body_inner
        ));
    } else {
        inner.push_str(&body_inner);
    }

    // Nested quote preview when present (lean — no link-card / poll / ask).
    if let Some(q) = status
        .get("quote")
        .filter(|v| v.is_object())
        .or_else(|| status.get("vaak_quote_preview").filter(|v| v.is_object()))
    {
        let q_html = paint_lean_embed_from(q, false, from);
        inner.push_str(&format!(
            "<div class=\"quote-block\" style=\"margin-top:.55rem\">{q_html}</div>"
        ));
    }

    if !uri.is_empty() {
        let from_q = if from.is_empty() { "mentions" } else { from };
        let open = format!(
            "?view=status&object={}&from={}",
            urlencoding_encode(uri),
            urlencoding_encode(from_q)
        );
        inner.push_str(&format!(
            "<div class=\"tweet-actions\"><a class=\"btn btn-ghost\" href=\"{}\" style=\"padding:.25rem .7rem;font-size:.8rem\">Open</a></div>",
            esc(&open)
        ));
    }

    let wrap_class = if hide_header {
        "notif-status-embed notif-status-embed--nohd"
    } else {
        "notif-status-embed"
    };
    format!(
        "<div class=\"{wrap}\"><article class=\"{ac}\">{inner}</article></div>",
        wrap = wrap_class,
        ac = article_classes,
        inner = inner
    )
}

/// Lean Home feed card: boost chrome + status body (no notif-embed wrap).
pub fn paint_lean_feed_card(status: &Value) -> String {
    let mut st = status.clone();
    let mut boost_header = String::new();
    let content_empty = {
        let c = st.get("content").and_then(|v| v.as_str()).unwrap_or("");
        strip_tags(c).trim().is_empty()
    };
    if content_empty {
        if let Some(inner) = st.get("reblog").filter(|v| v.is_object()).cloned() {
            let booster = st.get("account").cloned().unwrap_or(Value::Null);
            let booster_acct = booster
                .get("acct")
                .and_then(|v| v.as_str())
                .unwrap_or("Someone");
            let booster_name = booster
                .get("display_name")
                .and_then(|v| v.as_str())
                .filter(|s| !s.is_empty())
                .unwrap_or(booster_acct);
            let boost_when = st
                .get("created_at")
                .and_then(|v| v.as_str())
                .map(relative_time)
                .unwrap_or_default();
            let inner_uri = inner
                .get("uri")
                .and_then(|v| v.as_str())
                .or_else(|| inner.get("url").and_then(|v| v.as_str()))
                .unwrap_or("");
            let inner_acct = inner
                .get("account")
                .and_then(|a| a.get("acct"))
                .and_then(|v| v.as_str())
                .unwrap_or("");
            let boost_source = if is_rss(&inner) {
                "RSS"
            } else if is_bsky(&inner, inner_acct, inner_uri) {
                "Bluesky"
            } else {
                "Fediverse"
            };
            let booster_ref = booster
                .get("uri")
                .and_then(|v| v.as_str())
                .or_else(|| booster.get("url").and_then(|v| v.as_str()))
                .unwrap_or("");
            let booster_label = if booster_ref.starts_with("https://") {
                format!(
                    "<a href=\"?view=remote_profile&amp;actor={}&amp;from=home\" style=\"color:inherit;text-decoration:none\">{}</a>",
                    urlencoding_encode(booster_ref),
                    esc(booster_name)
                )
            } else {
                esc(booster_name)
            };
            let when_bit = if boost_when.is_empty() {
                String::new()
            } else {
                format!(" · {}", esc(&boost_when))
            };
            boost_header = format!(
                "<div class=\"meta meta-row\" style=\"color:var(--primary)\"><i class=\"ph ph-repeat\" aria-hidden=\"true\"></i> {booster_label} boosted{when_bit} <span class=\"tag\" style=\"margin-left:.35rem;color:var(--text)\" title=\"Boost source network\">{src}</span></div>",
                src = esc(boost_source)
            );
            st = inner;
        }
    }
    // Feed cards use the embed painter with header, then unwrap the outer embed div
    // so Home timeline items stay `<article class="tweet">` peers (matching PHP).
    // Boost chrome must live *inside* that article with class tweet-boost — wrapping
    // in a div breaks `.timeline-feed #timeline-items > article.tweet` separators.
    let painted = paint_lean_embed_from(&st, false, "home");
    let article = if let Some(start) = painted.find("<article") {
        if let Some(end) = painted.rfind("</article>") {
            painted[start..end + "</article>".len()].to_string()
        } else {
            painted
        }
    } else {
        painted
    };
    if boost_header.is_empty() {
        return article;
    }
    // Match PHP admin_render_masto_status_card: <article class="tweet tweet-boost">…
    let with_class = if let Some(idx) = article.find("class=\"tweet") {
        let mut s = String::with_capacity(article.len() + 16);
        s.push_str(&article[..idx]);
        s.push_str("class=\"tweet tweet-boost");
        s.push_str(&article[idx + "class=\"tweet".len()..]);
        s
    } else if let Some(gt) = article.find('>') {
        let mut s = String::with_capacity(article.len() + 32);
        s.push_str(&article[..gt]);
        s.push_str(" class=\"tweet tweet-boost\"");
        s.push_str(&article[gt..]);
        s
    } else {
        format!("<article class=\"tweet tweet-boost\">{article}</article>")
    };
    if let Some(gt) = with_class.find('>') {
        let mut out = String::with_capacity(with_class.len() + boost_header.len());
        out.push_str(&with_class[..=gt]);
        out.push_str(&boost_header);
        out.push_str(&with_class[gt + 1..]);
        out
    } else {
        format!("<article class=\"tweet tweet-boost\">{boost_header}{with_class}</article>")
    }
}

/// Minimal URL-encode for query values (uri / actor).
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

pub async fn set_embed_html(
    redis: &mut redis::aio::MultiplexedConnection,
    key: &str,
    html: &str,
) -> Result<()> {
    let _: () = redis::cmd("SET")
        .arg(key)
        .arg(html)
        .arg("EX")
        .arg(EMBED_TTL_SECS)
        .query_async(redis)
        .await?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn frag_key_parity_shape() {
        let k = frag_key(1, "https://example.com/a/", true, false, false, false, "");
        assert!(k.starts_with("vaak:notif-embed:v1:"));
        assert_eq!(k.len(), "vaak:notif-embed:v1:".len() + 64);
    }

    #[test]
    fn paint_hides_header_and_keeps_html_body() {
        let st = json!({
            "uri": "https://example.com/users/x/notes/1",
            "content": "<p>hi <b>there</b> &amp; stuff</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "account": {
                "acct": "x@example.com",
                "display_name": "X <script>",
                "avatar": "https://example.com/a.png",
                "uri": "https://example.com/users/x"
            },
            "media_attachments": []
        });
        let html = paint_lean_embed(&st, true);
        assert!(html.contains("notif-status-embed--nohd"));
        assert!(html.contains("tweet-embed-nohd"));
        // Status HTML is painted as HTML (links/markup), not strip+esc plain.
        assert!(html.contains("<b>there</b>"));
        assert!(html.contains("&amp; stuff") || html.contains("& stuff"));
        assert!(!html.contains("<script>"));
        assert!(html.contains("Open"));
        assert!(html.contains("view=status"));
    }

    #[test]
    fn paint_keeps_links_and_avoids_double_escaped_entities() {
        let st = json!({
            "id": "1",
            "uri": "https://example.com/users/x/statuses/1",
            "content": "<p>&quot;whatever you do, don&#039;t look&quot; — <a href=\"https://example.com/u/bob\" class=\"mention\">@bob</a></p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "account": {
                "acct": "x",
                "display_name": "X",
                "avatar": "https://example.com/a.png",
                "uri": "https://example.com/users/x"
            },
            "media_attachments": []
        });
        let html = paint_lean_feed_card(&st);
        assert!(
            html.contains("class=\"mention\"") && html.contains("@bob"),
            "mention link must survive: {html}"
        );
        assert!(
            !html.contains("&amp;quot;") && !html.contains("&amp;#039;"),
            "must not double-escape entities into visible codes: {html}"
        );
        assert!(html.contains("&quot;") || html.contains('\"') || html.contains("whatever"));
    }

    #[test]
    fn prepare_rewrites_mentions_hashtags_and_bare_urls() {
        let html = concat!(
            "<p><span class=\"h-card\"><a href=\"https://hachyderm.io/users/sigsegv\" class=\"u-url mention\">@<span>sigsegv</span></a></span> ",
            "see <a href=\"https://mkultra.monster/tags/rust\" class=\"mention hashtag\" rel=\"tag\">#<span>rust</span></a> ",
            "and https://example.com/path.</p>"
        );
        let out = prepare_feed_body_html(html, "home");
        assert!(out.contains("view=remote_profile"), "mention in-app: {out}");
        assert!(out.contains("view=search") && out.contains("rust"), "hashtag search: {out}");
        assert!(out.contains("class=\"ext-link\"") && out.contains("https://example.com/path"), "bare url: {out}");
        assert!(!out.contains("<br><br>") && !out.contains("<br /><br"), "{out}");
    }

    #[test]
    fn collapse_consecutive_breaks() {
        let html = "<p>one<br />\n<br />\ntwo</p>";
        let out = prepare_feed_body_html(html, "home");
        assert!(!out.to_ascii_lowercase().contains("<br><br>"), "{out}");
        assert!(!out.to_ascii_lowercase().contains("<br /><br"), "{out}");
        assert!(out.contains("one") && out.contains("two"), "{out}");
    }

    #[test]
    fn paints_link_card_when_no_media() {
        let st = json!({
            "id": "1",
            "uri": "https://example.com/users/x/statuses/1",
            "content": "<p>check https://mag.moe/1/</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "account": {
                "acct": "x",
                "display_name": "X",
                "avatar": "https://example.com/a.png",
                "uri": "https://example.com/users/x"
            },
            "media_attachments": [],
            "card": {
                "url": "https://mag.moe/1/",
                "title": "Mag art",
                "description": "A gallery",
                "provider_name": "mag.moe",
                "image": "https://mag.moe/thumb.jpg"
            }
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("class=\"link-card\""), "missing link-card: {html}");
        assert!(html.contains("Mag art"), "{html}");
        assert!(html.contains("feed-body--html"), "{html}");
        assert!(html.contains("class=\"ext-link\""), "bare url should linkify: {html}");
    }

    #[test]
    fn skips_link_card_when_quote_url_matches() {
        let st = json!({
            "id": "1",
            "uri": "https://example.com/users/x/statuses/1",
            "content": "<p>qt</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "account": {
                "acct": "x",
                "display_name": "X",
                "avatar": "https://example.com/a.png",
                "uri": "https://example.com/users/x"
            },
            "media_attachments": [],
            "card": {
                "url": "https://eigenmagic.net/@daedalus/117356806278610958",
                "title": "JP",
                "description": "",
                "provider_name": "eigenmagic.net"
            },
            "quote": {
                "quoted_status": {
                    "uri": "https://eigenmagic.net/@daedalus/117356806278610958",
                    "url": "https://eigenmagic.net/@daedalus/117356806278610958",
                    "content": "<p>nested</p>",
                    "account": {
                        "acct": "daedalus@eigenmagic.net",
                        "display_name": "JP",
                        "avatar": "https://example.com/a.png",
                        "uri": "https://eigenmagic.net/users/daedalus"
                    },
                    "media_attachments": []
                }
            }
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("quote-block"), "{html}");
        assert!(!html.contains("class=\"link-card\""), "should dedupe OG card: {html}");
    }

}
