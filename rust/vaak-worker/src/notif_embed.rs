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

fn parse_acct_mention(after: &str) -> Option<(&str, &str)> {
    // user@host where host has a dot TLD
    let user_end = after
        .find(|c: char| !(c.is_ascii_alphanumeric() || c == '_'))?;
    if user_end == 0 {
        return None;
    }
    if after.as_bytes().get(user_end) != Some(&b'@') {
        return None;
    }
    let user = &after[..user_end];
    let host_part = &after[user_end + 1..];
    let host_len = host_part
        .chars()
        .take_while(|c| c.is_ascii_alphanumeric() || *c == '.' || *c == '-')
        .count();
    if host_len < 3 {
        return None;
    }
    let host = &host_part[..host_len];
    if !host.contains('.') || host.starts_with('.') || host.ends_with('.') {
        return None;
    }
    // Require a letter TLD-ish (≥2 alpha at end)
    let tld = host.rsplit('.').next().unwrap_or("");
    if tld.len() < 2 || !tld.chars().all(|c| c.is_ascii_alphabetic()) {
        return None;
    }
    Some((user, host))
}

fn parse_dotted_handle(after: &str) -> Option<&str> {
    // Bluesky / Bridgy: alice.bsky.social or custom.domain — must contain a dot.
    let mut len = 0usize;
    let mut has_dot = false;
    for (i, c) in after.char_indices() {
        if c.is_ascii_alphanumeric() || c == '-' {
            len = i + c.len_utf8();
        } else if c == '.' {
            has_dot = true;
            len = i + 1;
        } else {
            break;
        }
    }
    if !has_dot || len < 3 {
        return None;
    }
    // Trim trailing dots
    let mut handle = &after[..len];
    while handle.ends_with('.') {
        handle = &handle[..handle.len() - 1];
    }
    if !handle.contains('.') || handle.len() < 3 {
        return None;
    }
    // Must not be followed by more handle chars
    let next = after[len..].chars().next();
    if let Some(c) = next {
        if c.is_ascii_alphanumeric() || c == '_' || c == '.' || c == '@' {
            return None;
        }
    }
    // First char alphanumeric
    let first = handle.chars().next()?;
    if !first.is_ascii_alphanumeric() {
        return None;
    }
    Some(handle)
}

fn parse_bare_handle(after: &str) -> Option<&str> {
    let mut len = 0usize;
    for (i, c) in after.char_indices() {
        if c.is_ascii_alphanumeric() || c == '_' {
            len = i + c.len_utf8();
        } else {
            break;
        }
    }
    if len < 2 || len > 32 {
        return None;
    }
    let next = after[len..].chars().next();
    if let Some(c) = next {
        if c.is_ascii_alphanumeric() || c == '_' || c == '.' || c == '@' {
            return None;
        }
    }
    Some(&after[..len])
}

fn parse_hashtag_token(after: &str) -> Option<&str> {
    let mut len = 0usize;
    let mut chars = 0usize;
    for (i, c) in after.char_indices() {
        if c.is_alphanumeric() || c == '_' {
            len = i + c.len_utf8();
            chars += 1;
            if chars > 100 {
                break;
            }
        } else {
            break;
        }
    }
    if chars == 0 {
        return None;
    }
    Some(&after[..len])
}

/// Linkify bare URLs, @mentions, and #hashtags in a text segment.
/// Parity target: PHP `admin_linkify_body_html` (lean — no DB mention resolution).
fn linkify_text_segment(text: &str, from: &str) -> String {
    if !text.contains("http://")
        && !text.contains("https://")
        && !text.contains("www.")
        && !text.contains('@')
        && !text.contains('#')
    {
        return text.to_string();
    }
    let from_q = if from.is_empty() { "home" } else { from };
    let mut out = String::with_capacity(text.len() + 64);
    let mut i = 0usize;
    while i < text.len() {
        let rest = &text[i..];
        // Find earliest hit among URL / @ / #
        let mut cand: Option<(usize, LinkifyKind)> = None;
        let consider = |cand: &mut Option<(usize, LinkifyKind)>, at: usize, kind: LinkifyKind| {
            if cand.as_ref().map(|(c, _)| at < *c).unwrap_or(true) {
                *cand = Some((at, kind));
            }
        };

        for (pat, needs_https) in [("https://", false), ("http://", false), ("www.", true)] {
            if let Some(p) = rest.find(pat) {
                if needs_https {
                    let prev = rest.get(..p).unwrap_or("");
                    if prev.ends_with("https://") || prev.ends_with("http://") {
                        continue;
                    }
                }
                consider(&mut cand, p, LinkifyKind::Url { needs_https });
            }
        }

        let bytes = rest.as_bytes();
        let mut ai = 0usize;
        while ai < bytes.len() {
            if bytes[ai] == b'@' {
                let prev_ok = ai == 0 || {
                    let c = rest[..ai].chars().next_back().unwrap_or(' ');
                    !(c.is_ascii_alphanumeric() || c == '_' || c == '@' || c == '/')
                };
                if prev_ok {
                    let after = &rest[ai + 1..];
                    if let Some((user, host)) = parse_acct_mention(after) {
                        let len = 1 + user.len() + 1 + host.len();
                        consider(
                            &mut cand,
                            ai,
                            LinkifyKind::Mention {
                                raw: rest[ai..ai + len].to_string(),
                                href_kind: MentionHref::Acct {
                                    user: user.to_string(),
                                    host: host.to_string(),
                                },
                            },
                        );
                        break;
                    }
                    if let Some(handle) = parse_dotted_handle(after) {
                        let len = 1 + handle.len();
                        consider(
                            &mut cand,
                            ai,
                            LinkifyKind::Mention {
                                raw: rest[ai..ai + len].to_string(),
                                href_kind: MentionHref::BskyHandle {
                                    handle: handle.to_string(),
                                },
                            },
                        );
                        break;
                    }
                    if let Some(user) = parse_bare_handle(after) {
                        let len = 1 + user.len();
                        consider(
                            &mut cand,
                            ai,
                            LinkifyKind::Mention {
                                raw: rest[ai..ai + len].to_string(),
                                href_kind: MentionHref::Bare {
                                    user: user.to_string(),
                                },
                            },
                        );
                        break;
                    }
                }
            }
            if bytes[ai] == b'#' {
                let prev_ok = ai == 0 || {
                    let c = rest[..ai].chars().next_back().unwrap_or(' ');
                    !(c.is_ascii_alphanumeric() || c == '_' || c == '&' || c == '/' || c == '%')
                };
                if prev_ok {
                    let after = &rest[ai + 1..];
                    if let Some(tag) = parse_hashtag_token(after) {
                        let len = 1 + tag.len();
                        consider(
                            &mut cand,
                            ai,
                            LinkifyKind::Hashtag {
                                raw: rest[ai..ai + len].to_string(),
                                tag: tag.to_string(),
                            },
                        );
                        break;
                    }
                }
            }
            ai += 1;
        }

        let Some((rel, kind)) = cand else {
            out.push_str(rest);
            break;
        };
        out.push_str(&rest[..rel]);
        let after = &rest[rel..];
        match kind {
            LinkifyKind::Url { needs_https } => {
                let end = after
                    .find(|c: char| c.is_whitespace() || c == '<' || c == '>' || c == '"')
                    .unwrap_or(after.len());
                let (core, trail) = strip_trailing_url_punct(&after[..end]);
                let mut href = if needs_https {
                    format!("https://{core}")
                } else {
                    core.to_string()
                };
                href = html_entity_decode_basic(&href);
                if href.contains("...") || href.contains('…') || !href.starts_with("http") {
                    out.push_str(&after[..core.len()]);
                    out.push_str(trail);
                    i += rel + core.len() + trail.len();
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
                i += rel + core.len() + trail.len();
            }
            LinkifyKind::Mention { raw, href_kind } => {
                let href = match href_kind {
                    MentionHref::Acct { user, host } => {
                        let actor = format!("https://{host}/users/{user}");
                        format!(
                            "?view=remote_profile&actor={}&from={}",
                            urlencoding_encode(&actor),
                            urlencoding_encode(from_q)
                        )
                    }
                    MentionHref::BskyHandle { handle } => {
                        let actor = format!("https://bsky.app/profile/{}", handle);
                        format!(
                            "?view=remote_profile&actor={}&from={}",
                            urlencoding_encode(&actor),
                            urlencoding_encode(from_q)
                        )
                    }
                    MentionHref::Bare { user } => {
                        format!(
                            "?view=search&q={}&type=accounts&resolve=1",
                            urlencoding_encode(&format!("@{user}"))
                        )
                    }
                };
                out.push_str(&format!(
                    "<a class=\"mention\" href=\"{}\">{}</a>",
                    esc(&href),
                    esc(&raw)
                ));
                i += rel + raw.len();
            }
            LinkifyKind::Hashtag { raw, tag } => {
                let href = format!(
                    "?view=search&q={}&type=statuses",
                    urlencoding_encode(&format!("#{tag}"))
                );
                out.push_str(&format!(
                    "<a class=\"hashtag\" href=\"{}\">{}</a>",
                    esc(&href),
                    esc(&raw)
                ));
                i += rel + raw.len();
            }
        }
    }
    out
}

#[derive(Clone)]
enum MentionHref {
    Acct { user: String, host: String },
    BskyHandle { handle: String },
    Bare { user: String },
}

#[derive(Clone)]
enum LinkifyKind {
    Url { needs_https: bool },
    Mention { raw: String, href_kind: MentionHref },
    Hashtag { raw: String, tag: String },
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

fn is_local_uri(uri: &str) -> bool {
    uri.trim()
        .to_ascii_lowercase()
        .starts_with("https://mkultra.monster/")
}

fn json_flag(st: &Value, key: &str) -> bool {
    match st.get(key) {
        Some(Value::Bool(b)) => *b,
        Some(Value::Number(n)) => n.as_i64().unwrap_or(0) != 0,
        Some(Value::String(s)) => matches!(s.as_str(), "1" | "true" | "True" | "yes"),
        _ => false,
    }
}

fn json_count(st: &Value, key: &str) -> i64 {
    st.get(key)
        .and_then(|v| v.as_i64())
        .or_else(|| {
            st.get(key)
                .and_then(|v| v.as_u64())
                .map(|u| u as i64)
        })
        .or_else(|| {
            st.get(key)
                .and_then(|v| v.as_f64())
                .map(|f| f as i64)
        })
        .or_else(|| {
            st.get(key)
                .and_then(|v| v.as_str())
                .and_then(|s| s.parse().ok())
        })
        .unwrap_or(0)
        .max(0)
}

/// PHP `admin_format_compact_count` parity.
fn format_compact_count(n: i64) -> String {
    if n < 1 {
        return String::new();
    }
    if n < 1000 {
        return n.to_string();
    }
    if n < 10_000 {
        let tenths = n / 100;
        let whole = tenths / 10;
        let frac = tenths % 10;
        if frac > 0 {
            format!("{whole}.{frac}k")
        } else {
            format!("{whole}k")
        }
    } else if n < 1_000_000 {
        format!("{}k", n / 1000)
    } else {
        let tenths = n / 100_000;
        let whole = tenths / 10;
        let frac = tenths % 10;
        if frac > 0 {
            format!("{whole}.{frac}M")
        } else {
            format!("{whole}M")
        }
    }
}

fn action_count_html(n: i64) -> String {
    let label = format_compact_count(n);
    if label.is_empty() {
        String::new()
    } else {
        format!("<span class=\"action-count\" aria-hidden=\"true\">{}</span>", esc(&label))
    }
}

fn reply_cw_query(spoiler: &str, sensitive: bool) -> String {
    let spoiler = spoiler.trim();
    if spoiler.is_empty() && !sensitive {
        return String::new();
    }
    let mut q = String::new();
    if !spoiler.is_empty() {
        let clipped: String = spoiler.chars().take(500).collect();
        q.push_str(&format!("&cw={}", urlencoding_encode(&clipped)));
    }
    if sensitive || !spoiler.is_empty() {
        q.push_str("&sensitive=1");
    }
    q
}

fn bsky_https_object_ref(st: &Value, uri: &str) -> String {
    let url = st.get("url").and_then(|v| v.as_str()).unwrap_or("").trim();
    if url.starts_with("https://bsky.app/") {
        return url.trim_end_matches('/').to_string();
    }
    if uri.starts_with("https://bsky.app/") {
        return uri.trim_end_matches('/').to_string();
    }
    if let Some(rest) = uri.strip_prefix("at://") {
        let parts: Vec<&str> = rest.split('/').collect();
        // at://did:plc:…/app.bsky.feed.post/rkey
        if parts.len() >= 3 {
            let actor = parts[0];
            let rkey = parts[parts.len() - 1];
            if !actor.is_empty() && !rkey.is_empty() {
                return format!("https://bsky.app/profile/{actor}/post/{rkey}");
            }
        }
    }
    uri.trim_end_matches('/').to_string()
}

fn bsky_at_uri(st: &Value, uri: &str) -> String {
    if uri.starts_with("at://") {
        return uri.to_string();
    }
    st.get("uri")
        .and_then(|v| v.as_str())
        .filter(|u| u.starts_with("at://"))
        .unwrap_or("")
        .to_string()
}

fn remote_object_href(uri: &str, url_hint: &str) -> String {
    let hint = url_hint.trim();
    if hint.starts_with("https://") && !hint.contains("bridgy") {
        return hint.to_string();
    }
    uri.to_string()
}

fn interaction_form(
    action_base: &str,
    action: &str,
    return_view: &str,
    status_id: &str,
    object_id: &str,
    target_actor: &str,
    btn_class: &str,
    title: &str,
    aria: &str,
    icon: &str,
    count_html: &str,
    extra_attrs: &str,
) -> String {
    let mut fields = format!(
        "<input type=\"hidden\" name=\"action\" value=\"{a}\">\
         <input type=\"hidden\" name=\"return_view\" value=\"{rv}\">\
         <input type=\"hidden\" name=\"status_id\" value=\"{sid}\">\
         <input type=\"hidden\" name=\"object_id\" value=\"{oid}\">",
        a = esc(action),
        rv = esc(return_view),
        sid = esc(status_id),
        oid = esc(object_id),
    );
    if !target_actor.is_empty() {
        fields.push_str(&format!(
            "<input type=\"hidden\" name=\"target_actor\" value=\"{}\">",
            esc(target_actor)
        ));
    }
    format!(
        "<form method=\"post\" action=\"{base}\" style=\"display:inline\">{fields}\
         <button class=\"{cls}\" type=\"submit\" title=\"{title}\" aria-label=\"{aria}\"{extra}>{icon}{count}</button></form>",
        base = esc(action_base),
        fields = fields,
        cls = btn_class,
        title = esc(title),
        aria = esc(aria),
        extra = extra_attrs,
        icon = icon,
        count = count_html,
    )
}

/// Home timeline action bar — PHP `admin_render_masto_status_card` tweet-actions parity.
/// Mentions nests keep Open-only; Home gets RSS / Bluesky / Fediverse chrome.
fn paint_lean_timeline_actions(status: &Value, from: &str) -> String {
    let from_q = if from.is_empty() { "home" } else { from };
    let account = status.get("account").cloned().unwrap_or(Value::Null);
    let acct = account
        .get("acct")
        .and_then(|v| v.as_str())
        .unwrap_or("?");
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
    let url_hint = status.get("url").and_then(|v| v.as_str()).unwrap_or("");
    let sid = status
        .get("id")
        .and_then(|v| v.as_str())
        .map(|s| s.to_string())
        .or_else(|| status.get("id").and_then(|v| v.as_i64()).map(|n| n.to_string()))
        .unwrap_or_default();
    let fav = json_flag(status, "favourited");
    let boosted = json_flag(status, "reblogged");
    let bm = json_flag(status, "bookmarked");
    let spoiler = status
        .get("spoiler_text")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .trim();
    let sensitive = json_flag(status, "sensitive") || !spoiler.is_empty();
    let fav_n = json_count(status, "favourites_count");
    let rb_n = json_count(status, "reblogs_count");
    let qt_n = json_count(status, "quotes_count");
    let rss = is_rss(status);
    let bsky = is_bsky(status, acct, uri);
    let local = !rss && !bsky && is_local_uri(uri);
    let action_base = format!("?view={}", urlencoding_encode(from_q));
    let cw_q = reply_cw_query(spoiler, sensitive);

    let mut actions = String::new();

    if rss {
        let rss_item = status
            .get("vaak_rss_item_id")
            .and_then(|v| v.as_i64())
            .unwrap_or(0);
        let rss_feed = status
            .get("vaak_rss_feed_id")
            .and_then(|v| v.as_i64())
            .unwrap_or(0);
        let fav_cls = if fav { "icon-btn on" } else { "icon-btn" };
        let fav_icon = if fav {
            "<i class=\"ph-fill ph-heart\" aria-hidden=\"true\"></i>"
        } else {
            "<i class=\"ph ph-heart\" aria-hidden=\"true\"></i>"
        };
        actions.push_str(&interaction_form(
            &action_base,
            if fav {
                "unfavourite_status"
            } else {
                "favourite_status"
            },
            from_q,
            &sid,
            &sid,
            "",
            fav_cls,
            if fav { "Unlike" } else { "Like (VAAK only)" },
            if fav { "Unlike" } else { "Like" },
            fav_icon,
            "",
            "",
        ));
        if rss_item > 0 {
            let q_cls = if json_flag(status, "vaak_rss_quoted") {
                "icon-btn on"
            } else {
                "icon-btn"
            };
            actions.push_str(&format!(
                "<form method=\"post\" action=\"{base}\" style=\"display:inline\">\
                 <input type=\"hidden\" name=\"action\" value=\"rss_quote\">\
                 <input type=\"hidden\" name=\"return_view\" value=\"{rv}\">\
                 <input type=\"hidden\" name=\"item_id\" value=\"{item}\">\
                 <button class=\"{cls}\" type=\"submit\" title=\"Quote boost (VAAK only)\" aria-label=\"Quote boost\"><i class=\"ph ph-quotes\" aria-hidden=\"true\"></i></button></form>",
                base = esc(&action_base),
                rv = esc(from_q),
                item = rss_item,
                cls = q_cls,
            ));
        }
        let boost_cls = if boosted { "icon-btn on" } else { "icon-btn" };
        actions.push_str(&interaction_form(
            &action_base,
            if boosted {
                "unreblog_status"
            } else {
                "reblog_status"
            },
            from_q,
            &sid,
            &sid,
            "",
            boost_cls,
            if boosted {
                "Undo boost (VAAK only)"
            } else {
                "Boost (VAAK only)"
            },
            if boosted { "Undo boost" } else { "Boost" },
            "<i class=\"ph ph-repeat\" aria-hidden=\"true\"></i>",
            "",
            &format!(
                " aria-pressed=\"{}\"",
                if boosted { "true" } else { "false" }
            ),
        ));
        let bm_cls = if bm { "icon-btn on" } else { "icon-btn" };
        let bm_icon = if bm {
            "<i class=\"ph-fill ph-bookmark-simple\" aria-hidden=\"true\"></i>"
        } else {
            "<i class=\"ph ph-bookmark-simple\" aria-hidden=\"true\"></i>"
        };
        actions.push_str(&interaction_form(
            &action_base,
            if bm {
                "unbookmark_status"
            } else {
                "bookmark_status"
            },
            from_q,
            &sid,
            &sid,
            "",
            bm_cls,
            if bm { "Bookmark folders" } else { "Bookmark" },
            if bm { "Bookmark folders" } else { "Bookmark" },
            bm_icon,
            "",
            &format!(" data-bm-picker=\"{}\"", if bm { "1" } else { "0" }),
        ));
        let mut overflow = String::new();
        if uri.starts_with("http://") || uri.starts_with("https://") {
            overflow.push_str(&format!(
                "<a class=\"menu-action\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">Open</a>",
                esc(uri)
            ));
        }
        if rss_feed > 0 {
            overflow.push_str(&format!(
                "<form method=\"post\" action=\"?view={rv}\" onsubmit=\"return confirm('Remove this RSS feed from your Home mix?');\">\
                 <input type=\"hidden\" name=\"action\" value=\"rss_remove_feed\">\
                 <input type=\"hidden\" name=\"return_view\" value=\"{rv}\">\
                 <input type=\"hidden\" name=\"feed_id\" value=\"{fid}\">\
                 <button class=\"menu-action\" type=\"submit\" style=\"color:var(--danger)\">Remove feed</button></form>",
                rv = esc(from_q),
                fid = rss_feed,
            ));
        }
        if !overflow.is_empty() {
            actions.push_str(&format!(
                "<details class=\"post-action-menu\"><summary class=\"icon-btn\" title=\"More actions\" aria-label=\"More actions\">⋯</summary>\
                 <div class=\"post-action-menu__body\">{overflow}</div></details>"
            ));
        }
    } else {
        // Reply (Fediverse + Bluesky + local)
        if !uri.is_empty() {
            let reply_target = if bsky {
                bsky_https_object_ref(status, uri)
            } else {
                uri.to_string()
            };
            let mut reply_href = format!(
                "?view={}&compose=1&reply_to={}",
                urlencoding_encode(from_q),
                urlencoding_encode(&reply_target)
            );
            if !local && actor_ref.starts_with("https://") {
                reply_href.push_str(&format!("&to={}", urlencoding_encode(actor_ref)));
            }
            reply_href.push_str(&cw_q);
            actions.push_str(&format!(
                "<a class=\"icon-btn\" href=\"{}\" title=\"Reply\" aria-label=\"Reply\"><i class=\"ph ph-arrow-bend-up-left\" aria-hidden=\"true\"></i></a>",
                esc(&reply_href)
            ));
        }

        if bsky && !uri.is_empty() {
            let at = bsky_at_uri(status, uri);
            let object_ref = bsky_https_object_ref(status, uri);
            let cid = status
                .get("bsky_cid")
                .and_then(|v| v.as_str())
                .unwrap_or("");
            let like_rec = status
                .get("vaak_bsky_like_record")
                .and_then(|v| v.as_str())
                .unwrap_or("");
            let repost_rec = status
                .get("vaak_bsky_repost_record")
                .and_then(|v| v.as_str())
                .unwrap_or("");
            let reposted = boosted || !repost_rec.is_empty();
            let liked = fav || !like_rec.is_empty();
            let bm_sid = if sid.is_empty() && !at.is_empty() {
                let mut hasher = Sha256::new();
                hasher.update(at.as_bytes());
                let digest = hex::encode(hasher.finalize());
                format!("bsky:{}", &digest[..32.min(digest.len())])
            } else {
                sid.clone()
            };
            let bm_oid = if object_ref.is_empty() {
                uri
            } else {
                object_ref.as_str()
            };

            let qt_cls = if qt_n > 0 {
                "icon-btn has-count"
            } else {
                "icon-btn"
            };
            actions.push_str(&format!(
                "<a class=\"{cls}\" href=\"?view={view}&amp;compose=1&amp;quote_object={qo}\" data-eng=\"1\" data-eng-count=\"{n}\" title=\"Quote\" aria-label=\"Quote\"><i class=\"ph ph-quotes\" aria-hidden=\"true\"></i>{count}</a>",
                cls = qt_cls,
                view = urlencoding_encode(from_q),
                qo = urlencoding_encode(&object_ref),
                n = qt_n,
                count = action_count_html(qt_n),
            ));
            if !at.is_empty() {
                let rb_cls = format!(
                    "icon-btn bsky-action{}{}",
                    if reposted { " on" } else { "" },
                    if rb_n > 0 { " has-count" } else { "" }
                );
                actions.push_str(&format!(
                    "<button type=\"button\" class=\"{cls}\" data-bsky-action=\"repost\" data-uri=\"{uri}\" data-cid=\"{cid}\" data-record-uri=\"{rec}\" data-object-ref=\"{oref}\" data-return-view=\"{rv}\" data-eng=\"1\" data-eng-count=\"{n}\" title=\"{title}\" aria-label=\"{aria}\" aria-pressed=\"{pressed}\"><i class=\"ph ph-repeat\" aria-hidden=\"true\"></i>{count}</button>",
                    cls = rb_cls,
                    uri = esc(&at),
                    cid = esc(cid),
                    rec = esc(repost_rec),
                    oref = esc(&object_ref),
                    rv = esc(from_q),
                    n = rb_n,
                    title = if reposted { "Undo boost" } else { "Boost" },
                    aria = if reposted { "Undo boost" } else { "Boost" },
                    pressed = if reposted { "true" } else { "false" },
                    count = action_count_html(rb_n),
                ));
            }
            let like_cls = format!(
                "icon-btn bsky-action{}{}",
                if liked { " on" } else { "" },
                if fav_n > 0 { " has-count" } else { "" }
            );
            let like_icon = if liked {
                "<i class=\"ph-fill ph-heart\" aria-hidden=\"true\"></i>"
            } else {
                "<i class=\"ph ph-heart\" aria-hidden=\"true\"></i>"
            };
            actions.push_str(&format!(
                "<button type=\"button\" class=\"{cls}\" data-bsky-action=\"like\" data-uri=\"{uri}\" data-cid=\"{cid}\" data-record-uri=\"{rec}\" data-object-ref=\"{oref}\" data-return-view=\"{rv}\" data-eng=\"1\" data-eng-count=\"{n}\" title=\"{title}\" aria-label=\"{aria}\" aria-pressed=\"{pressed}\">{icon}{count}</button>",
                cls = like_cls,
                uri = esc(&at),
                cid = esc(cid),
                rec = esc(like_rec),
                oref = esc(&object_ref),
                rv = esc(from_q),
                n = fav_n,
                title = if liked { "Unlike" } else { "Like on Bluesky" },
                aria = if liked { "Unlike" } else { "Like on Bluesky" },
                pressed = if liked { "true" } else { "false" },
                icon = like_icon,
                count = action_count_html(fav_n),
            ));
            let bm_cls = format!("icon-btn bsky-action{}", if bm { " on" } else { "" });
            let bm_icon = if bm {
                "<i class=\"ph-fill ph-bookmark-simple\" aria-hidden=\"true\"></i>"
            } else {
                "<i class=\"ph ph-bookmark-simple\" aria-hidden=\"true\"></i>"
            };
            actions.push_str(&format!(
                "<button type=\"button\" class=\"{cls}\" data-bsky-action=\"bookmark\" data-uri=\"{uri}\" data-cid=\"{cid}\" data-status-id=\"{sid}\" data-object-id=\"{oid}\" data-object-ref=\"{oref}\" data-return-view=\"{rv}\" data-bm-picker=\"{pick}\" title=\"{title}\" aria-label=\"{aria}\" aria-pressed=\"{pressed}\">{icon}</button>",
                cls = bm_cls,
                uri = esc(&at),
                cid = esc(cid),
                sid = esc(&bm_sid),
                oid = esc(bm_oid),
                oref = esc(&object_ref),
                rv = esc(from_q),
                pick = if bm { "1" } else { "0" },
                title = if bm { "Bookmark folders" } else { "Bookmark" },
                aria = if bm { "Bookmark folders" } else { "Bookmark" },
                pressed = if bm { "true" } else { "false" },
                icon = bm_icon,
            ));
        } else if !sid.is_empty() && !uri.is_empty() {
            // Fediverse / local (PHP remote branch; local skips Bite)
            let qt_cls = if qt_n > 0 {
                "icon-btn has-count"
            } else {
                "icon-btn"
            };
            actions.push_str(&format!(
                "<a class=\"{cls}\" href=\"?view={view}&amp;compose=1&amp;quote_object={qo}&amp;quote_status_id={qs}\" data-eng=\"1\" data-eng-count=\"{n}\" title=\"Quote\" aria-label=\"Quote\"><i class=\"ph ph-quotes\" aria-hidden=\"true\"></i>{count}</a>",
                cls = qt_cls,
                view = urlencoding_encode(from_q),
                qo = urlencoding_encode(uri),
                qs = urlencoding_encode(&sid),
                n = qt_n,
                count = action_count_html(qt_n),
            ));
            let boost_cls = format!(
                "icon-btn{}{}",
                if boosted { " on" } else { "" },
                if rb_n > 0 { " has-count" } else { "" }
            );
            actions.push_str(&interaction_form(
                &action_base,
                if boosted {
                    "unreblog_status"
                } else {
                    "reblog_status"
                },
                from_q,
                &sid,
                uri,
                actor_ref,
                &boost_cls,
                if boosted { "Undo boost" } else { "Boost" },
                if boosted { "Undo boost" } else { "Boost" },
                "<i class=\"ph ph-repeat\" aria-hidden=\"true\"></i>",
                &action_count_html(rb_n),
                &format!(" data-eng=\"1\" data-eng-count=\"{rb_n}\""),
            ));
            if !local {
                actions.push_str(&format!(
                    "<form method=\"post\" action=\"{base}\" style=\"display:inline\" onsubmit=\"return confirm('Bite this post?');\">\
                     <input type=\"hidden\" name=\"action\" value=\"bite_remote\">\
                     <input type=\"hidden\" name=\"return_view\" value=\"{rv}\">\
                     <input type=\"hidden\" name=\"bite_kind\" value=\"post\">\
                     <input type=\"hidden\" name=\"target\" value=\"{target}\">\
                     <button class=\"icon-btn\" type=\"submit\" title=\"Bite (Wafrn)\" aria-label=\"Bite\"><i class=\"ph ph-tooth\" aria-hidden=\"true\"></i></button></form>",
                    base = esc(&action_base),
                    rv = esc(from_q),
                    target = esc(uri),
                ));
            }
            let fav_cls = format!(
                "icon-btn{}{}",
                if fav { " on" } else { "" },
                if fav_n > 0 { " has-count" } else { "" }
            );
            let fav_icon = if fav {
                "<i class=\"ph-fill ph-heart\" aria-hidden=\"true\"></i>"
            } else {
                "<i class=\"ph ph-heart\" aria-hidden=\"true\"></i>"
            };
            actions.push_str(&interaction_form(
                &action_base,
                if fav {
                    "unfavourite_status"
                } else {
                    "favourite_status"
                },
                from_q,
                &sid,
                uri,
                actor_ref,
                &fav_cls,
                if fav { "Unlike" } else { "Like" },
                if fav { "Unlike" } else { "Like" },
                fav_icon,
                &action_count_html(fav_n),
                &format!(" data-eng=\"1\" data-eng-count=\"{fav_n}\""),
            ));
            let bm_cls = if bm { "icon-btn on" } else { "icon-btn" };
            let bm_icon = if bm {
                "<i class=\"ph-fill ph-bookmark-simple\" aria-hidden=\"true\"></i>"
            } else {
                "<i class=\"ph ph-bookmark-simple\" aria-hidden=\"true\"></i>"
            };
            actions.push_str(&interaction_form(
                &action_base,
                if bm {
                    "unbookmark_status"
                } else {
                    "bookmark_status"
                },
                from_q,
                &sid,
                uri,
                "",
                bm_cls,
                if bm { "Bookmark folders" } else { "Bookmark" },
                if bm { "Bookmark folders" } else { "Bookmark" },
                bm_icon,
                "",
                &format!(" data-bm-picker=\"{}\"", if bm { "1" } else { "0" }),
            ));
        }

        if !uri.is_empty() && !bsky {
            let remote = remote_object_href(uri, url_hint);
            if remote.starts_with("http://") || remote.starts_with("https://") {
                actions.push_str(&format!(
                    "<a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\" class=\"meta\" title=\"Open on remote instance\">Remote</a>",
                    esc(&remote)
                ));
            }
        }
    }

    if actions.is_empty() {
        // Fallback Open when we lack ids (thin shells).
        if !uri.is_empty() {
            let open = format!(
                "?view=status&object={}&from={}",
                urlencoding_encode(uri),
                urlencoding_encode(from_q)
            );
            return format!(
                "<div class=\"tweet-actions\"><a class=\"btn btn-ghost\" href=\"{}\" style=\"padding:.25rem .7rem;font-size:.8rem\">Open</a></div>",
                esc(&open)
            );
        }
        return String::new();
    }
    format!("<div class=\"tweet-actions\">{actions}</div>")
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

    // Home timeline: full PHP-parity action bar. Mentions nests stay Open-only.
    if from == "home" {
        inner.push_str(&paint_lean_timeline_actions(status, from));
    } else if !uri.is_empty() {
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
    fn linkifies_plain_handles_and_hashtags() {
        let out = linkify_text_segment(
            "hi @alice@mastodon.social and @bob.bsky.social see #fediverse",
            "home",
        );
        assert!(out.contains("class=\"mention\"") && out.contains("@alice@mastodon.social"), "{out}");
        assert!(out.contains("view=remote_profile") && out.contains("mastodon.social"), "{out}");
        assert!(
            out.contains("@bob.bsky.social")
                && (out.contains("bsky.app/profile") || out.contains("bsky.app%2Fprofile")),
            "{out}"
        );
        assert!(out.contains("class=\"hashtag\"") && out.contains("#fediverse"), "{out}");
        assert!(out.contains("view=search") && out.contains("type=statuses"), "{out}");
    }

    #[test]
    fn home_feed_card_paints_fedi_action_bar() {
        let st = json!({
            "id": "12345",
            "uri": "https://mastodon.social/users/x/statuses/1",
            "url": "https://mastodon.social/@x/1",
            "content": "<p>hello @bob@example.com #hi</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "favourited": false,
            "reblogged": false,
            "bookmarked": false,
            "favourites_count": 3,
            "reblogs_count": 1,
            "quotes_count": 0,
            "account": {
                "acct": "x@mastodon.social",
                "display_name": "X",
                "avatar": "https://example.com/a.png",
                "uri": "https://mastodon.social/users/x"
            },
            "media_attachments": []
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("icon-btn"), "expected action icons: {html}");
        assert!(html.contains("favourite_status") || html.contains("ph-heart"), "{html}");
        assert!(html.contains("reblog_status") || html.contains("ph-repeat"), "{html}");
        assert!(html.contains("bite_remote") && html.contains("ph-tooth"), "{html}");
        assert!(html.contains("bookmark_status") || html.contains("ph-bookmark"), "{html}");
        assert!(html.contains("compose=1") && html.contains("reply_to="), "{html}");
        assert!(!html.contains(">Open</a></div>"), "Home should not be Open-only: {html}");
    }

    #[test]
    fn home_feed_card_paints_bsky_action_bar() {
        let st = json!({
            "id": "bsky:abc",
            "uri": "at://did:plc:test/app.bsky.feed.post/rkey1",
            "url": "https://bsky.app/profile/did:plc:test/post/rkey1",
            "content": "<p>hello @alice.bsky.social</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "source": "bluesky",
            "bsky_cid": "cid123",
            "favourited": true,
            "reblogged": false,
            "bookmarked": false,
            "favourites_count": 2,
            "reblogs_count": 0,
            "sensitive": true,
            "spoiler_text": "Sensitive content",
            "account": {
                "acct": "test.bsky.social",
                "display_name": "Test",
                "avatar": "https://example.com/a.png",
                "uri": "https://bsky.app/profile/test.bsky.social"
            },
            "media_attachments": []
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("bsky-action"), "{html}");
        assert!(html.contains("data-bsky-action=\"like\""), "{html}");
        assert!(html.contains("data-bsky-action=\"repost\""), "{html}");
        assert!(html.contains("data-bsky-action=\"bookmark\""), "{html}");
        assert!(html.contains("cw-gate"), "Bluesky sensitive must gate: {html}");
        assert!(html.contains("class=\"mention\"") && html.contains("@alice.bsky.social"), "{html}");
    }

    #[test]
    fn home_feed_card_paints_rss_local_actions() {
        let st = json!({
            "id": "rss:9",
            "uri": "https://example.com/feed/post",
            "content": "<p>rss item</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "source": "rss",
            "vaak_rss_item_id": 9,
            "vaak_rss_feed_id": 3,
            "favourited": false,
            "reblogged": false,
            "bookmarked": false,
            "account": {
                "acct": "example.com",
                "display_name": "Example",
                "avatar": "https://example.com/a.png",
                "uri": "https://example.com/"
            },
            "media_attachments": []
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("rss_quote") || html.contains("ph-quotes"), "{html}");
        assert!(html.contains("reblog_status") || html.contains("Boost (VAAK only)"), "{html}");
        assert!(html.contains("favourite_status") || html.contains("ph-heart"), "{html}");
        assert!(!html.contains("bite_remote"), "RSS must not Bite: {html}");
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
