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
        r"(?i)^https://[^/]+/(?:users/[^/]+|@[^/]+)/(?:statuses|posts)/",
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
    let profile_surface = from_q.contains("profile");

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
            let target = if profile_surface {
                " target=\"_blank\" rel=\"noopener noreferrer\""
            } else {
                ""
            };
            return format!("<a class=\"status-link\" href=\"{}\"{}>", esc(&new_href), target);
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
                    let target = if from_q.contains("profile") {
                        " target=\"_blank\" rel=\"noopener noreferrer\""
                    } else {
                        ""
                    };
                    format!("<a class=\"status-link\" href=\"{}\"{}>{label}</a>", esc(&new_href), target)
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

/// Tighten Mastodon HTML breaks for feed bodies.
///
/// Collapse runs of 3+ `<br>` down to two (paragraph split marker).
fn collapse_html_breaks(html: &str) -> String {
    let mut out = html.to_string();
    for _ in 0..4 {
        let next = lazy_regex_replace_all(r"(?i)(<br\s*/?>\s*){3,}", &out, "<br><br>");
        if next == out {
            break;
        }
        out = next;
    }
    out
}

fn is_blank_html_segment(seg: &str) -> bool {
    let t = lazy_regex_replace_all(r"(?i)<br\s*/?>", seg, " ");
    let t = lazy_regex_replace_all(r"(?i)&nbsp;", &t, " ");
    strip_tags(&t).trim().is_empty()
}

fn is_void_html_tag(name: &str) -> bool {
    matches!(
        name,
        "br" | "hr" | "img" | "input" | "meta" | "source" | "wbr" | "area" | "col" | "embed" | "track"
    )
}

fn html_tag_name(tag: &str) -> String {
    let lower = tag.to_ascii_lowercase();
    let body = if lower.starts_with("</") {
        &lower[2..]
    } else if lower.starts_with('<') {
        &lower[1..]
    } else {
        return String::new();
    };
    body.chars()
        .take_while(|c| c.is_ascii_alphanumeric())
        .collect()
}

fn match_leading_br(s: &str) -> Option<usize> {
    let lower_prefix: String = s.chars().take(12).collect::<String>().to_ascii_lowercase();
    if !lower_prefix.starts_with("<br") {
        return None;
    }
    let bytes = s.as_bytes();
    if bytes.len() < 3 || !s[..3].eq_ignore_ascii_case("<br") {
        return None;
    }
    let mut i = 3usize;
    while i < bytes.len() && bytes[i].is_ascii_whitespace() {
        i += 1;
    }
    if i < bytes.len() && bytes[i] == b'/' {
        i += 1;
    }
    if i >= bytes.len() || bytes[i] != b'>' {
        return None;
    }
    i += 1;
    while i < bytes.len() && bytes[i].is_ascii_whitespace() {
        i += 1;
    }
    Some(i)
}

fn match_leading_double_br(s: &str) -> Option<usize> {
    let mut matched = 0usize;
    let mut rest = s;
    let mut count = 0usize;
    while let Some(n) = match_leading_br(rest) {
        matched += n;
        rest = &rest[n..];
        count += 1;
        if count >= 2 {
            while let Some(n2) = match_leading_br(rest) {
                matched += n2;
                rest = &rest[n2..];
            }
            return Some(matched);
        }
    }
    None
}

/// Split `inner` on double-`<br>` only at nesting depth 0 (not inside `<a>`/`<span>`/…).
fn split_on_top_level_double_br(inner: &str) -> Vec<String> {
    let mut parts: Vec<String> = Vec::new();
    let mut cur = String::new();
    let mut i = 0usize;
    let mut depth: i32 = 0;
    while i < inner.len() {
        if inner.as_bytes()[i] == b'<' {
            if depth == 0 {
                if let Some(n) = match_leading_double_br(&inner[i..]) {
                    let piece = cur.trim();
                    if !piece.is_empty() && !is_blank_html_segment(piece) {
                        parts.push(piece.to_string());
                    }
                    cur.clear();
                    i += n;
                    continue;
                }
            }
            if let Some(end_rel) = inner[i..].find('>') {
                let tag_end = i + end_rel + 1;
                let tag = &inner[i..tag_end];
                let lower = tag.to_ascii_lowercase();
                let name = html_tag_name(tag);
                if lower.starts_with("</") {
                    if !is_void_html_tag(&name) {
                        depth = (depth - 1).max(0);
                    }
                } else {
                    let self_closing = lower.trim_end().ends_with("/>") || is_void_html_tag(&name);
                    if !name.is_empty() && !self_closing {
                        depth += 1;
                    }
                }
                cur.push_str(tag);
                i = tag_end;
                continue;
            }
        }
        let ch = inner[i..].chars().next().unwrap_or('\0');
        cur.push(ch);
        i += ch.len_utf8();
    }
    let piece = cur.trim();
    if !piece.is_empty() && !is_blank_html_segment(piece) {
        parts.push(piece.to_string());
    }
    parts
}

fn rewrite_p_inner_double_breaks(open_p: &str, inner: &str) -> String {
    let parts = split_on_top_level_double_br(inner);
    if parts.len() <= 1 {
        let mut out = String::with_capacity(open_p.len() + inner.len() + 4);
        out.push_str(open_p);
        out.push_str(inner);
        out.push_str("</p>");
        return out;
    }
    let mut out = String::with_capacity(inner.len() + 16);
    for part in parts {
        out.push_str("<p>");
        out.push_str(&part);
        out.push_str("</p>");
    }
    out
}

/// Force VAAK paragraph shape across remote software dialects.
///
/// Mastodon often emits one `<p>` with top-level `<br><br>` for paragraphs.
/// Convert those safe double-breaks into sibling `<p>` tags. Never split inside
/// nested tags (`<a>`, `<span>`, …) or across sibling block boundaries.
fn normalize_feed_paragraphs(html: &str) -> String {
    let mut html = collapse_html_breaks(html.trim());
    html = lazy_regex_replace_all(r"(?i)<p(?:\s[^>]*)?>\s*(?:<br\s*/?>\s*)*</p>", &html, "");

    let mut out = String::with_capacity(html.len() + 16);
    let mut i = 0usize;
    while i < html.len() {
        if html.as_bytes()[i] == b'<' {
            if let Some(end_rel) = html[i..].find('>') {
                let tag_end = i + end_rel + 1;
                let tag = &html[i..tag_end];
                let lower = tag.to_ascii_lowercase();
                if lower.starts_with("<p>") || lower.starts_with("<p ") {
                    let open = tag;
                    let mut j = tag_end;
                    let mut d: i32 = 1;
                    let mut found = None;
                    while j < html.len() {
                        if html.as_bytes()[j] != b'<' {
                            j += html[j..].chars().next().map(|c| c.len_utf8()).unwrap_or(1);
                            continue;
                        }
                        let Some(er) = html[j..].find('>') else {
                            break;
                        };
                        let te = j + er + 1;
                        let t = &html[j..te];
                        let tl = t.to_ascii_lowercase();
                        if tl.starts_with("</p") {
                            d -= 1;
                            if d == 0 {
                                found = Some((tag_end, j, te));
                                break;
                            }
                        } else if tl.starts_with("<p>") || tl.starts_with("<p ") {
                            d += 1;
                        }
                        j = te;
                    }
                    if let Some((inner_start, inner_end, after)) = found {
                        let inner = &html[inner_start..inner_end];
                        out.push_str(&rewrite_p_inner_double_breaks(open, inner));
                        i = after;
                        continue;
                    }
                }
                out.push_str(tag);
                i = tag_end;
                continue;
            }
        }
        let ch = html[i..].chars().next().unwrap_or('\0');
        out.push(ch);
        i += ch.len_utf8();
    }

    if !out.to_ascii_lowercase().contains("<p") {
        let parts = split_on_top_level_double_br(&out);
        if parts.len() > 1 {
            let mut wrapped = String::new();
            for part in parts {
                wrapped.push_str("<p>");
                wrapped.push_str(&part);
                wrapped.push_str("</p>");
            }
            return wrapped;
        }
    }
    out
}

/// Prepare Mastodon/AP content HTML for lean feed paint: normalize paragraphs,
/// rewrite mention/hashtag/ext anchors in-app, and linkify bare URLs.
fn prepare_feed_body_html(html: &str, from: &str) -> String {
    let collapsed = normalize_feed_paragraphs(html);
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

fn youtube_id_ok(id: &str) -> bool {
    let id = id.trim_end_matches(|c: char| !(c.is_ascii_alphanumeric() || c == '_' || c == '-'));
    id.len() >= 6
        && id.len() <= 20
        && id
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '-')
}

/// Extract a YouTube video id from watch / youtu.be / shorts / embed URLs.
fn youtube_id_from_url(url: &str) -> Option<String> {
    let url = url.trim();
    if url.is_empty() {
        return None;
    }
    let lower = url.to_ascii_lowercase();
    if let Some(pos) = lower.find("youtu.be/") {
        let rest = &url[pos + "youtu.be/".len()..];
        let id = rest.split(['?', '#', '/']).next().unwrap_or("");
        if youtube_id_ok(id) {
            return Some(id.to_string());
        }
    }
    if let Some(pos) = lower.find("youtube.com/shorts/") {
        let rest = &url[pos + "youtube.com/shorts/".len()..];
        let id = rest.split(['?', '#', '/']).next().unwrap_or("");
        if youtube_id_ok(id) {
            return Some(id.to_string());
        }
    }
    if let Some(pos) = lower
        .find("youtube.com/embed/")
        .or_else(|| lower.find("youtube-nocookie.com/embed/"))
    {
        let marker_len = if lower[pos..].starts_with("youtube-nocookie.com/embed/") {
            "youtube-nocookie.com/embed/".len()
        } else {
            "youtube.com/embed/".len()
        };
        let rest = &url[pos + marker_len..];
        let id = rest.split(['?', '#', '/']).next().unwrap_or("");
        if youtube_id_ok(id) {
            return Some(id.to_string());
        }
    }
    if lower.contains("youtube.com/watch") {
        if let Some(vpos) = lower.find("v=") {
            let rest = &url[vpos + 2..];
            let id = rest.split(['&', '#', '/', '?']).next().unwrap_or("");
            if youtube_id_ok(id) {
                return Some(id.to_string());
            }
        }
    }
    None
}

fn first_https_urls_from_text(text_or_html: &str) -> Vec<String> {
    let plain = strip_tags(text_or_html)
        .replace("&amp;", "&")
        .replace("&quot;", "\"")
        .replace("&#39;", "'");
    let mut out = Vec::new();
    let bytes = plain.as_str();
    let mut search_from = 0usize;
    while let Some(rel) = bytes[search_from..].find("https://") {
        let start = search_from + rel;
        let tail = &bytes[start..];
        let end_rel = tail
            .find(|c: char| c.is_whitespace() || "<>\"'".contains(c))
            .unwrap_or(tail.len());
        let mut url = tail[..end_rel].trim_end_matches(|c: char| ".,);]!?'\"".contains(c));
        if let Some(second) = url[8..].find("https://") {
            url = &url[..8 + second];
        }
        url = url.trim_end_matches(|c: char| ".,);]!?'\"/".contains(c));
        if url.starts_with("https://") && !out.iter().any(|u| u == url) {
            out.push(url.to_string());
        }
        search_from = start + end_rel.max(1);
        if search_from >= bytes.len() {
            break;
        }
    }
    out
}

fn youtube_watch_url(id: &str) -> String {
    format!("https://www.youtube.com/watch?v={id}")
}

fn youtube_thumb_url(id: &str) -> String {
    format!("https://i.ytimg.com/vi/{id}/hqdefault.jpg")
}

/// Resolve YouTube preview data from status.card and/or body URLs.
fn youtube_preview_from_status(st: &Value) -> Option<(String, String, String, String)> {
    let card = st.get("card").filter(|v| v.is_object());
    if let Some(card) = card {
        let curl = card.get("url").and_then(|v| v.as_str()).unwrap_or("");
        if let Some(id) = youtube_id_from_url(curl) {
            let title = card
                .get("title")
                .and_then(|v| v.as_str())
                .unwrap_or("")
                .trim();
            let image = card
                .get("image")
                .and_then(|v| v.as_str())
                .filter(|s| s.starts_with("https://"))
                .unwrap_or("")
                .to_string();
            let title = if title.is_empty() {
                "YouTube video".to_string()
            } else {
                title.to_string()
            };
            let image = if image.is_empty() {
                youtube_thumb_url(&id)
            } else {
                image
            };
            return Some((id.clone(), youtube_watch_url(&id), title, image));
        }
    }
    let content = st.get("content").and_then(|v| v.as_str()).unwrap_or("");
    for url in first_https_urls_from_text(content) {
        if let Some(id) = youtube_id_from_url(&url) {
            return Some((
                id.clone(),
                youtube_watch_url(&id),
                "YouTube video".to_string(),
                youtube_thumb_url(&id),
            ));
        }
    }
    None
}

/// Full-width 16:9 click-to-play YouTube card (PHP `ap_link_preview_html` parity + wide layout).
fn paint_youtube_link_card(id: &str, url: &str, title: &str, image: &str) -> String {
    let title = if title.trim().is_empty() {
        "YouTube video"
    } else {
        title.trim()
    };
    let image = if image.starts_with("https://") {
        image
    } else {
        // fallback path constructed by caller usually; keep safe
        return String::new();
    };
    format!(
        "<div class=\"link-card youtube-link-card youtube-link-card--wide\" data-youtube-id=\"{id}\" data-youtube-url=\"{url}\">\
         <button type=\"button\" class=\"youtube-link-card__play\" data-youtube-play aria-label=\"Play YouTube video\">\
         <img src=\"{img}\" alt=\"\" loading=\"lazy\" referrerpolicy=\"no-referrer\">\
         <span class=\"youtube-link-card__play-icon\" aria-hidden=\"true\">▶</span></button>\
         <div class=\"link-card__body\">\
         <div class=\"link-card__provider\">YouTube</div>\
         <div class=\"link-card__title\">{title}</div>\
         <a class=\"youtube-link-card__open\" href=\"{url}\" target=\"_blank\" rel=\"nofollow noopener noreferrer\">Open on YouTube</a>\
         </div></div>",
        id = esc(id),
        url = esc(url),
        img = esc(image),
        title = esc(title),
    )
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
    if let Some(id) = youtube_id_from_url(url) {
        let title = card.get("title").and_then(|v| v.as_str()).unwrap_or("");
        let image = card
            .get("image")
            .and_then(|v| v.as_str())
            .filter(|s| s.starts_with("https://"))
            .map(|s| s.to_string())
            .unwrap_or_else(|| youtube_thumb_url(&id));
        return paint_youtube_link_card(&id, &youtube_watch_url(&id), title, &image);
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

fn paint_collection_card(card: &Value) -> String {
    let kind = card
        .get("vaak_collection_kind")
        .and_then(|v| v.as_str())
        .unwrap_or("");
    if kind.is_empty() {
        return String::new();
    }
    let title = card.get("title").and_then(|v| v.as_str()).unwrap_or("Collection");
    let desc = card.get("description").and_then(|v| v.as_str()).unwrap_or("");
    let provider = card.get("provider_name").and_then(|v| v.as_str()).unwrap_or("VAAK collection");
    format!(
        "<div class=\"link-card bsky-collection-card\" role=\"group\"><div class=\"link-card__body\"><div class=\"link-card__provider\">{}</div><div class=\"link-card__title\">{}</div><div class=\"link-card__desc\">{}</div></div></div>",
        esc(provider), esc(title), esc(desc)
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

/// Normalize Mastodon/Wafrn quote envelopes to the actual quoted status.
///
/// Different ActivityPub servers expose the nested object as either
/// `quote.quoted_status`, `quote.status`, or directly as `quote`.  PHP's
/// entity layer unwraps these before painting; keeping the same rule here
/// prevents blank quote cards and preserves attachment media when a server
/// puts it on the outer envelope.
fn quote_preview_status<'a>(quote: &'a Value) -> &'a Value {
    quote
        .get("quoted_status")
        .filter(|v| v.is_object())
        .or_else(|| quote.get("status").filter(|v| v.is_object()))
        .unwrap_or(quote)
}

fn normalized_quote_preview(quote: &Value) -> Value {
    let mut nested = quote_preview_status(quote).clone();
    // Some Wafrn/Mastodon bridges keep attachment previews on the envelope,
    // not on quoted_status.  PHP merges those before entity serialization;
    // do the same so quote media is not silently lost in Axum cards.
    let nested_empty = nested
        .get("media_attachments")
        .and_then(|v| v.as_array())
        .map(|a| a.is_empty())
        .unwrap_or(true);
    if nested_empty {
        if let Some(media) = quote.get("media_attachments").filter(|v| v.is_array()) {
            if !media.as_array().map(|a| a.is_empty()).unwrap_or(true) {
                nested["media_attachments"] = media.clone();
            }
        }
    }
    nested
}

fn paint_status_link_card(st: &Value) -> String {
    if status_has_media(st) {
        return String::new();
    }
    let painted_quote = quote_url_for_dedupe(st);
    let has_structured_quote = st.get("quote").filter(|v| v.is_object()).is_some()
        || st.get("vaak_quote_preview").filter(|v| v.is_object()).is_some();
    if let Some(card) = st.get("card").filter(|v| v.is_object()) {
        if card.get("vaak_collection_kind").is_some() {
            return paint_collection_card(card);
        }
        let card_url = card.get("url").and_then(|v| v.as_str()).unwrap_or("");
        let suppress_status_card = (has_structured_quote && looks_like_status_url(card_url))
            || (!painted_quote.is_empty()
                && (urls_loosely_equivalent(card_url, &painted_quote)
                    // Bridgy/AppView may expose a different permalink for the
                    // same quoted status. Once a structured quote owns a status
                    // URL, do not paint a second generic status card beneath it.
                    || looks_like_status_url(card_url)));
        if !card_url.is_empty() && suppress_status_card
        {
            // Fall through — body may still have a YouTube URL to preview.
        } else {
            let painted = paint_link_card_html(card);
            if !painted.is_empty() {
                return painted;
            }
        }
    }
    // Hydrate often leaves card=null; PHP looked up link_preview_cards at paint.
    // Synthesize a usable YouTube click-to-play card from the body URL.
    if let Some((id, url, title, image)) = youtube_preview_from_status(st) {
        if !painted_quote.is_empty() && urls_loosely_equivalent(&url, &painted_quote) {
            return String::new();
        }
        return paint_youtube_link_card(&id, &url, &title, &image);
    }
    String::new()
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

/// AT post URI for Bluesky actions.
///
/// Mention rows usually store the public `https://bsky.app/...` permalink on
/// `uri` and keep `at://` on `vaak_bsky_uri` (the mentions.activity_id). DID
/// web URLs convert locally. Handle web URLs stay unresolved here; the boost
/// button still paints with `data-object-ref` and the click handler resolves
/// the record.
fn bsky_at_uri(st: &Value, uri: &str) -> String {
    fn post_at(raw: &str) -> Option<String> {
        let s = raw.trim().trim_end_matches('/');
        if s.is_empty() {
            return None;
        }
        if s.starts_with("at://") && s.contains("/app.bsky.feed.post/") {
            let cut = s.split(['?', '#']).next().unwrap_or(s);
            return Some(cut.to_string());
        }
        let web = s.strip_prefix("https://bsky.app/profile/")?;
        let mut parts = web.split('/');
        let actor = parts.next().unwrap_or("");
        let kind = parts.next().unwrap_or("");
        let rkey = parts.next().unwrap_or("");
        if kind != "post" || !actor.starts_with("did:") || rkey.is_empty() {
            return None;
        }
        let rkey = rkey.split(['?', '#']).next().unwrap_or(rkey);
        Some(format!("at://{actor}/app.bsky.feed.post/{rkey}"))
    }
    let extra = [
        st.get("vaak_bsky_uri").and_then(|v| v.as_str()),
        st.get("bsky_uri").and_then(|v| v.as_str()),
        st.get("uri").and_then(|v| v.as_str()),
        st.get("url").and_then(|v| v.as_str()),
    ];
    if let Some(at) = post_at(uri) {
        return at;
    }
    for candidate in extra.into_iter().flatten() {
        if let Some(at) = post_at(candidate) {
            return at;
        }
    }
    String::new()
}

/// Render reply context even when the parent was not included in the current
/// timeline page.  The API still carries the canonical parent URL in
/// `vaak_in_reply_to_url`; relying only on `in_reply_to_id` made those posts
/// look like standalone posts in the native Rust timeline.
fn paint_reply_context(status: &Value, from: &str) -> String {
    if status.get("vaak_ask").is_some() {
        return String::new();
    }
    let mut parent = status
        .get("vaak_in_reply_to_url")
        .and_then(|v| v.as_str())
        .or_else(|| status.get("in_reply_to").and_then(|v| v.as_str()))
        .unwrap_or("")
        .trim_end_matches('/')
        .to_string();
    if parent.is_empty() {
        if let Some(candidate) = status.get("in_reply_to_id").and_then(|v| v.as_str()) {
            let candidate = candidate.trim().trim_end_matches('/');
            if candidate.starts_with("https://") || candidate.starts_with("at://") {
                parent = candidate.to_string();
            }
        }
    }
    if parent.starts_with("at://") {
        let parts: Vec<&str> = parent[5..].split('/').collect();
        if parts.len() >= 3 && !parts[0].is_empty() && !parts[2].is_empty() {
            parent = format!(
                "https://bsky.app/profile/{}/post/{}",
                parts[0], parts[2]
            );
        }
    }
    if !parent.starts_with("https://") {
        return String::new();
    }
    let cached_parent_label = status
        .get("vaak_reply_parent_handle")
        .and_then(|v| v.as_str())
        .or_else(|| status.get("vaak_reply_parent_display").and_then(|v| v.as_str()))
        .unwrap_or("")
        .trim();
    // A thin Jetstream row can carry the parent DID as its temporary handle.
    // Never render that implementation identifier to users; the hydration
    // pass will replace it with the cached actor handle when available.
    let cached_parent_label = if cached_parent_label.trim_start_matches('@').starts_with("did:") {
        ""
    } else {
        cached_parent_label
    };
    let parent_label = if !cached_parent_label.is_empty() {
        cached_parent_label
    } else if let Some(rest) = parent.strip_prefix("https://bsky.app/profile/") {
        let candidate = rest.split('/').next().unwrap_or("").trim();
        if candidate.starts_with("did:") { "" } else { candidate }
    } else if let Some(pos) = parent.find("/users/") {
        parent[pos + 7..].split('/').next().unwrap_or("").trim()
    } else {
        ""
    };
    let return_view = if from.is_empty() { "home" } else { from };
    let href = format!(
        "?view=status&object={}&from={}",
        urlencoding_encode(&parent),
        urlencoding_encode(return_view)
    );
    let parent_label = parent_label.trim_start_matches('@');
    let parent_label = if parent_label.starts_with("did:") { "" } else { parent_label };
    let label = if parent_label.is_empty() {
        "the parent post".to_string()
    } else {
        format!("@{}", esc(parent_label))
    };
    format!(
        "<div class=\"meta reply-context\" style=\"margin:.35rem 0 .5rem\">↩ in reply to <a href=\"{}\">{}</a></div>",
        esc(&href),
        label
    )
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

/// Standard base64 (no padding crate) for edit data-* attrs.
fn b64_encode(s: &str) -> String {
    const TABLE: &[u8] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    let bytes = s.as_bytes();
    let mut out = String::with_capacity((bytes.len() + 2) / 3 * 4);
    let mut i = 0;
    while i + 3 <= bytes.len() {
        let n = ((bytes[i] as u32) << 16) | ((bytes[i + 1] as u32) << 8) | (bytes[i + 2] as u32);
        out.push(TABLE[((n >> 18) & 63) as usize] as char);
        out.push(TABLE[((n >> 12) & 63) as usize] as char);
        out.push(TABLE[((n >> 6) & 63) as usize] as char);
        out.push(TABLE[(n & 63) as usize] as char);
        i += 3;
    }
    match bytes.len() - i {
        1 => {
            let n = (bytes[i] as u32) << 16;
            out.push(TABLE[((n >> 18) & 63) as usize] as char);
            out.push(TABLE[((n >> 12) & 63) as usize] as char);
            out.push('=');
            out.push('=');
        }
        2 => {
            let n = ((bytes[i] as u32) << 16) | ((bytes[i + 1] as u32) << 8);
            out.push(TABLE[((n >> 18) & 63) as usize] as char);
            out.push(TABLE[((n >> 12) & 63) as usize] as char);
            out.push(TABLE[((n >> 6) & 63) as usize] as char);
            out.push('=');
        }
        _ => {}
    }
    out
}

fn is_own_note(uri: &str, viewer_actor: &str) -> bool {
    let viewer = viewer_actor.trim().trim_end_matches('/');
    if viewer.is_empty() || uri.is_empty() {
        return false;
    }
    let prefix = format!("{viewer}/notes/");
    uri.starts_with(&prefix)
}

fn status_account_actor(status: &Value) -> String {
    let account = status.get("account").unwrap_or(&Value::Null);
    account
        .get("uri")
        .and_then(|v| v.as_str())
        .or_else(|| account.get("url").and_then(|v| v.as_str()))
        .unwrap_or("")
        .trim()
        .trim_end_matches('/')
        .to_string()
}

/// Build the PHP-compatible reply mention seed from the parent author and any
/// structured participants already present on the status. Handles are
/// normalized and deduplicated so repeated replies do not grow duplicate
/// @mentions in the composer.
fn reply_mention_query(status: &Value, viewer_actor: &str) -> String {
    // Compare against the viewer's actual account key, not the whole actor URL
    // (a substring check would incorrectly drop unrelated handles).
    let viewer = viewer_actor.trim().trim_end_matches('/');
    let viewer_key = viewer
        .rsplit_once("/users/")
        .map(|(_, name)| name)
        .or_else(|| viewer.rsplit_once("/profile/").map(|(_, name)| name))
        .unwrap_or(viewer)
        .trim_matches('/')
        .to_ascii_lowercase();
    let viewer_keys = [viewer_key.clone(), format!("{viewer_key}@mkultra.monster")];
    let mut handles: Vec<String> = Vec::new();
    let mut seen = std::collections::HashSet::new();
    let mut push = |raw: &str| {
        let mut h = raw.trim().trim_start_matches('@').to_string();
        if h.is_empty() { return; }
        if h.ends_with("@bsky.app") {
            h.truncate(h.len().saturating_sub("@bsky.app".len()));
        }
        let key = h.to_ascii_lowercase();
        if key.is_empty()
            || key.starts_with("did:")
            || viewer_keys.iter().any(|candidate| candidate == &key)
            || !seen.insert(key)
        {
            return;
        }
        handles.push(h);
    };
    // A hydrated Bluesky reply can expose the parent handle separately. Put
    // that first so the direct recipient is always preserved, then add the
    // status author and any remaining chain participants.
    if let Some(parent) = status.get("vaak_reply_parent_handle").and_then(|v| v.as_str()) { push(parent); }
    if let Some(account) = status.get("account") {
        if let Some(acct) = account.get("acct").and_then(|v| v.as_str()) { push(acct); }
    }
    if let Some(mentions) = status.get("mentions").and_then(|v| v.as_array()) {
        for mention in mentions {
            if let Some(acct) = mention.get("acct").and_then(|v| v.as_str()) { push(acct); }
            else if let Some(username) = mention.get("username").and_then(|v| v.as_str()) { push(username); }
        }
    }
    if handles.is_empty() { return String::new(); }
    format!("&mention={}", urlencoding_encode(&handles.join(",")))
}

/// Stamp mute/block flags onto statuses (and nested reblogs) for lean ⋯ menus.
pub fn stamp_viewer_moderation(statuses: &mut [Value], moderation: &crate::hidden::ViewerModeration) {
    for st in statuses.iter_mut() {
        stamp_viewer_moderation_one(st, moderation);
        if let Some(reblog) = st.get_mut("reblog").filter(|v| v.is_object()) {
            stamp_viewer_moderation_one(reblog, moderation);
        }
    }
}

/// Stamp the status the notification card paints, plus a nested reblog or quote.
pub fn stamp_viewer_moderation_tree(
    status: &mut Value,
    moderation: &crate::hidden::ViewerModeration,
) {
    stamp_viewer_moderation_one(status, moderation);
    if let Some(reblog) = status.get_mut("reblog").filter(|v| v.is_object()) {
        stamp_viewer_moderation_one(reblog, moderation);
    }
    stamp_nested_quote(status.get_mut("quote"), moderation);
    stamp_nested_quote(status.get_mut("vaak_quote_preview"), moderation);
}

fn stamp_nested_quote(
    quote: Option<&mut Value>,
    moderation: &crate::hidden::ViewerModeration,
) {
    let Some(quote) = quote.filter(|v| v.is_object()) else {
        return;
    };
    if quote.get("quoted_status").is_some() {
        if let Some(quoted) = quote.get_mut("quoted_status").filter(|v| v.is_object()) {
            stamp_viewer_moderation_one(quoted, moderation);
        }
        return;
    }
    if quote.get("account").is_some() || quote.get("uri").is_some() || quote.get("url").is_some() {
        stamp_viewer_moderation_one(quote, moderation);
    }
}

fn stamp_viewer_moderation_one(status: &mut Value, moderation: &crate::hidden::ViewerModeration) {
    let actor = status_account_actor(status);
    if actor.is_empty() || !actor.starts_with("https://") {
        return;
    }
    let obj = match status.as_object_mut() {
        Some(o) => o,
        None => return,
    };
    obj.insert(
        "_vaak_viewer_muted".to_string(),
        Value::Bool(moderation.is_muted(&actor)),
    );
    obj.insert(
        "_vaak_viewer_deprioritized".to_string(),
        Value::Bool(moderation.is_deprioritized(&actor)),
    );
    if moderation.is_self_bsky(&actor) {
        obj.insert("_vaak_viewer_self".to_string(), Value::Bool(true));
    }
    if let Some(id) = moderation.block_id(&actor) {
        obj.insert("_vaak_viewer_blocked".to_string(), Value::Bool(true));
        obj.insert("_vaak_viewer_block_id".to_string(), Value::from(id));
    } else {
        obj.insert("_vaak_viewer_blocked".to_string(), Value::Bool(false));
        obj.insert("_vaak_viewer_block_id".to_string(), Value::from(0));
    }
}

/// Personal Block / Mute / Report overflow — PHP `block_quick_actions` (user items).
fn paint_moderation_overflow(
    from_q: &str,
    actor_ref: &str,
    object_id: &str,
    viewer_actor: &str,
    status: &Value,
) -> String {
    let actor = actor_ref.trim().trim_end_matches('/');
    let viewer = viewer_actor.trim().trim_end_matches('/');
    if actor.is_empty() || !actor.starts_with("https://") {
        return String::new();
    }
    // Guests and self: no personal moderation chrome.
    // Deprioritize is one of those actions — it is never offered on your own
    // posts, including a linked Bluesky profile stamped `_vaak_viewer_self`.
    if viewer.is_empty() || actor.eq_ignore_ascii_case(viewer) || json_flag(status, "_vaak_viewer_self")
    {
        return String::new();
    }
    // Own note URL as belt-and-suspenders (boost of self, etc.).
    if is_own_note(object_id, viewer) {
        return String::new();
    }

    let muted = json_flag(status, "_vaak_viewer_muted");
    let deprioritized = json_flag(status, "_vaak_viewer_deprioritized");
    let blocked = json_flag(status, "_vaak_viewer_blocked");
    let block_id = status
        .get("_vaak_viewer_block_id")
        .and_then(|v| v.as_i64())
        .unwrap_or(0);
    let object_raw = object_id.trim().trim_end_matches('/');
    let object = if object_raw.starts_with("https://") {
        object_raw
    } else {
        status
            .get("url")
            .and_then(|v| v.as_str())
            .filter(|u| u.starts_with("https://"))
            .unwrap_or(object_raw)
            .trim_end_matches('/')
    };
    let action_base = format!("/vaak/?view={}", urlencoding_encode(from_q));
    let is_bsky_object = object.to_ascii_lowercase().contains("bsky.app");

    let mut menu = String::new();
    // Direct messages are an ActivityPub/Fediverse action. Keep the Rust
    // timeline overflow in parity with PHP, while never routing Bluesky
    // actors through the Fediverse DM composer.
    let is_bsky_actor = actor.to_ascii_lowercase().contains("bsky.app")
        || actor.to_ascii_lowercase().starts_with("https://bsky.")
        || actor.to_ascii_lowercase().starts_with("at://");
    if !blocked && !is_bsky_actor {
        menu.push_str(&format!(
            "<a class=\"menu-action\" href=\"?view=dms&amp;peer={}\">DM</a>",
            esc(&urlencoding_encode(actor))
        ));
    }
    if object.starts_with("https://") {
        let open = format!(
            "?view=status&object={}&from={}",
            urlencoding_encode(object),
            urlencoding_encode(from_q)
        );
        menu.push_str(&format!(
            "<a class=\"menu-action\" href=\"{}\">Open</a>",
            esc(&open)
        ));
        let remote = remote_object_href(object, "");
        if remote.starts_with("http://") || remote.starts_with("https://") {
        menu.push_str(&format!(
            "<a class=\"menu-action\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">{}</a>",
                esc(&remote),
                if is_bsky_object {
                    "Open on Bluesky"
                } else {
                    "Remote"
            }
        ));
        let copy_href = format!(
            "https://vaak.monster/vaak/?view=status&object={}&from={}",
            urlencoding_encode(object),
            urlencoding_encode(from_q)
        );
        menu.push_str(&format!(
            "<button type=\"button\" class=\"menu-action\" data-copy-value=\"{}\" title=\"Copy link to post\">Copy link</button>",
            esc(&copy_href)
        ));
    }
    }

    // Block for me
    if blocked && block_id > 0 {
        menu.push_str(&format!(
            "<form method=\"post\" action=\"{base}\">\
             <input type=\"hidden\" name=\"action\" value=\"user_block_remove\">\
             <input type=\"hidden\" name=\"return_view\" value=\"{rv}\">\
             <input type=\"hidden\" name=\"return_from\" value=\"{rv}\">\
             <input type=\"hidden\" name=\"return_actor\" value=\"{actor}\">\
             <input type=\"hidden\" name=\"id\" value=\"{id}\">\
             <button class=\"menu-action\" type=\"submit\" title=\"Hide from your timelines only\">Unblock</button></form>",
            base = esc(&action_base),
            rv = esc(from_q),
            actor = esc(actor),
            id = block_id,
        ));
    } else {
        menu.push_str(&format!(
            "<form method=\"post\" action=\"{base}\">\
             <input type=\"hidden\" name=\"action\" value=\"user_block_add\">\
             <input type=\"hidden\" name=\"return_view\" value=\"{rv}\">\
             <input type=\"hidden\" name=\"return_from\" value=\"{rv}\">\
             <input type=\"hidden\" name=\"return_actor\" value=\"{actor}\">\
             <input type=\"hidden\" name=\"target\" value=\"{actor}\">\
             <button class=\"menu-action\" type=\"submit\" title=\"Hide from your timelines only\">Block</button></form>",
            base = esc(&action_base),
            rv = esc(from_q),
            actor = esc(actor),
        ));
    }

    // Mute for me
    menu.push_str(&format!(
        "<form method=\"post\" action=\"{base}\">\
         <input type=\"hidden\" name=\"action\" value=\"{action}\">\
         <input type=\"hidden\" name=\"return_view\" value=\"{rv}\">\
         <input type=\"hidden\" name=\"return_from\" value=\"{rv}\">\
         <input type=\"hidden\" name=\"return_actor\" value=\"{actor}\">\
         <input type=\"hidden\" name=\"actor_id\" value=\"{actor}\">\
         <button class=\"menu-action\" type=\"submit\" title=\"Hide from your Home / Federated / Notifications\">{label}</button></form>",
        base = esc(&action_base),
        action = if muted { "unmute_remote" } else { "mute_remote" },
        rv = esc(from_q),
        actor = esc(actor),
        label = if muted { "Unmute" } else { "Mute" },
    ));

    // Home soft-rank. Still visible on Local, Federated, and notifications.
    // Own posts already returned above, and PHP rejects a self target.
    menu.push_str(&format!(
        "<form method=\"post\" action=\"{base}\">\
         <input type=\"hidden\" name=\"action\" value=\"{action}\">\
         <input type=\"hidden\" name=\"return_view\" value=\"{rv}\">\
         <input type=\"hidden\" name=\"return_from\" value=\"{rv}\">\
         <input type=\"hidden\" name=\"return_actor\" value=\"{actor}\">\
         <input type=\"hidden\" name=\"actor_id\" value=\"{actor}\">\
         <button class=\"menu-action\" type=\"submit\" title=\"Soft-rank on Home only. Still shows on Federated, Local, and notifications\">{label}</button></form>",
        base = esc(&action_base),
        action = if deprioritized {
            "undeprioritize_remote"
        } else {
            "deprioritize_remote"
        },
        rv = esc(from_q),
        actor = esc(actor),
        label = if deprioritized {
            "Stop deprioritizing"
        } else {
            "Deprioritize on Home"
        },
    ));

    // Report user (composer prefilled with actor + optional post)
    let mut report_q = format!(
        "?view=report&report_target={}",
        urlencoding_encode(actor)
    );
    if object.starts_with("https://") {
        report_q.push_str("&report_object=");
        report_q.push_str(&urlencoding_encode(object));
    }
    if !from_q.is_empty() {
        report_q.push_str("&from=");
        report_q.push_str(&urlencoding_encode(from_q));
    }
    menu.push_str(&format!(
        "<a class=\"menu-action\" href=\"{}\">Report user</a>",
        esc(&report_q)
    ));

    format!(
        "<details class=\"post-action-menu\"><summary class=\"icon-btn\" title=\"More actions\" aria-label=\"More actions\">⋯</summary>\
         <div class=\"post-action-menu__body\">{menu}</div></details>"
    )
}

/// Resolve `masto_statuses.local_id` for Delete forms.
/// Profile Axum paints the real DB PK as status `id`; Home/Local hydrate paints
/// Mastodon snowflakes. Never send a snowflake as `local_id` — PHP
/// `ap_delete_local_status` would miss and skip the `note_id` fallback when
/// the posted value is &gt; 0. Digit ids below the snowflake floor (&lt; 2e6,
/// matching PHP `ap_masto_*`) are treated as real PKs; otherwise `0` so PHP
/// resolves via `note_id`.
fn own_post_local_id(status: &Value, sid: &str) -> String {
    if let Some(n) = status
        .get("vaak_local_id")
        .and_then(|v| v.as_i64())
        .filter(|&n| n > 0)
    {
        return n.to_string();
    }
    if let Some(n) = status
        .get("vaak_local_id")
        .and_then(|v| v.as_str())
        .and_then(|s| s.parse::<i64>().ok())
        .filter(|&n| n > 0)
    {
        return n.to_string();
    }
    if !sid.is_empty() && sid.chars().all(|c| c.is_ascii_digit()) {
        if let Ok(n) = sid.parse::<u64>() {
            if n > 0 && n < 2_000_000 {
                return sid.to_string();
            }
        }
    }
    "0".to_string()
}

/// Own-post Delete + overflow (Open/Edit/Pin/Note) — PHP `admin_own_post_action_bar` tail.
fn paint_own_post_controls(status: &Value, from_q: &str, uri: &str, sid: &str) -> String {
    let spoiler = status
        .get("spoiler_text")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .trim();
    let sensitive = json_flag(status, "sensitive") || !spoiler.is_empty();
    let pinned = json_flag(status, "pinned");
    let content_html = status.get("content").and_then(|v| v.as_str()).unwrap_or("");
    let plain = strip_tags(content_html).trim().to_string();
    let local_id = own_post_local_id(status, sid);
    // Absolute /vaak/ — relative ?view= from some soft-nav paths can miss the admin POST.
    let action_base = format!("/vaak/?view={}", urlencoding_encode(from_q));

    let mut out = String::new();
    // Confirm + AJAX fade handled in capture-phase JS (data-vaak-ajax-delete).
    out.push_str(&format!(
        "<form method=\"post\" action=\"{base}\" style=\"display:inline\" data-vaak-ajax-delete=\"1\">\
         <input type=\"hidden\" name=\"action\" value=\"delete_status\">\
         <input type=\"hidden\" name=\"return_view\" value=\"{rv}\">\
         <input type=\"hidden\" name=\"local_id\" value=\"{lid}\">\
         <input type=\"hidden\" name=\"note_id\" value=\"{nid}\">\
         <button class=\"icon-btn\" type=\"submit\" title=\"Delete post\" aria-label=\"Delete post\" style=\"color:var(--danger)\">\
         <i class=\"ph ph-trash\" aria-hidden=\"true\"></i></button></form>",
        base = esc(&action_base),
        rv = esc(from_q),
        lid = esc(&local_id),
        nid = esc(uri),
    ));

    let open_href = format!(
        "?view=status&object={}&from={}",
        urlencoding_encode(uri),
        urlencoding_encode(from_q)
    );
    let edit_href = format!(
        "?view={}&compose=1&edit_note={}",
        urlencoding_encode(from_q),
        urlencoding_encode(uri)
    );
    let pin_action = if pinned { "unpin_status" } else { "pin_status" };
    let pin_label = if pinned {
        "Unpin from profile"
    } else {
        "Pin to profile"
    };
    let pin_title = if pinned {
        "Remove this post from your profile pins"
    } else {
        "Pin on your HTML profile (up to 5; Bluesky uses the newest)"
    };
    let pin_cls = if pinned {
        "menu-action on"
    } else {
        "menu-action"
    };

    let mut menu = String::new();
    menu.push_str(&format!(
        "<a class=\"menu-action\" href=\"{}\">Open</a>",
        esc(&open_href)
    ));
    menu.push_str(&format!(
        "<a class=\"menu-action js-edit-post\" href=\"{href}\" data-note-id=\"{nid}\" \
         data-return-view=\"{rv}\" data-content-b64=\"{cb}\" data-spoiler-b64=\"{sb}\" data-sensitive=\"{sens}\">Edit</a>",
        href = esc(&edit_href),
        nid = esc(uri),
        rv = esc(from_q),
        cb = esc(&b64_encode(&plain)),
        sb = esc(&b64_encode(spoiler)),
        sens = if sensitive { "1" } else { "0" },
    ));
    menu.push_str(&format!(
        "<form method=\"post\" action=\"{base}\" style=\"display:inline\">\
         <input type=\"hidden\" name=\"action\" value=\"{act}\">\
         <input type=\"hidden\" name=\"return_view\" value=\"{rv}\">\
         <input type=\"hidden\" name=\"note_id\" value=\"{nid}\">\
         <button class=\"{cls}\" type=\"submit\" title=\"{title}\">{label}</button></form>",
        base = esc(&action_base),
        act = pin_action,
        rv = esc(from_q),
        nid = esc(uri),
        cls = pin_cls,
        title = esc(pin_title),
        label = esc(pin_label),
    ));
    menu.push_str(&format!(
        "<a class=\"menu-action\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">Note</a>",
        esc(uri)
    ));
    out.push_str(&format!(
        "<details class=\"post-action-menu\"><summary class=\"icon-btn\" title=\"More actions\" aria-label=\"More actions\">⋯</summary>\
         <div class=\"post-action-menu__body\">{menu}</div></details>"
    ));
    out
}

/// Timeline action bar — PHP `admin_render_masto_status_card` / own-post bar parity.
/// Home, profiles, outbox, and Notifications (replies and quote-boosts) get the
/// full bar, including the overflow menu when the viewer actor is known.
fn paint_lean_timeline_actions(status: &Value, from: &str, viewer_actor: &str) -> String {
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
    // A boost card can arrive as an Announce/reblog wrapper.  Interactions
    // must target the underlying status, not the synthetic wrapper ID/URI.
    // This is especially important for boosting an already-boosted post.
    let interaction = status
        .get("reblog")
        .filter(|v| v.is_object())
        .unwrap_or(status);
    let interaction_uri = interaction
        .get("uri")
        .and_then(|v| v.as_str())
        .or_else(|| interaction.get("url").and_then(|v| v.as_str()))
        .unwrap_or("")
        .trim_end_matches('/');
    let interaction_sid = interaction
        .get("id")
        .and_then(|v| v.as_str())
        .map(|s| s.to_string())
        .or_else(|| interaction.get("id").and_then(|v| v.as_i64()).map(|n| n.to_string()))
        .unwrap_or_else(|| sid.clone());
    let interaction_actor_ref = interaction
        .get("account")
        .and_then(|a| a.get("uri").and_then(|v| v.as_str()))
        .or_else(|| interaction.get("account").and_then(|a| a.get("url").and_then(|v| v.as_str())))
        .unwrap_or(actor_ref);
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
    let is_own = is_own_note(uri, viewer_actor);
    let eng_attr = if is_own { "data-own-eng" } else { "data-eng" };
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
        // Keep RSS actions in the same interaction order as other timeline
        // cards: Favourite immediately precedes Bookmark.
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
                bsky_https_object_ref(status, interaction_uri)
            } else {
                interaction_uri.to_string()
            };
            let mut reply_href = format!(
                "?view={}&compose=1&reply_to={}",
                urlencoding_encode(from_q),
                urlencoding_encode(&reply_target)
            );
            if !local && actor_ref.starts_with("https://") {
                reply_href.push_str(&format!("&to={}", urlencoding_encode(actor_ref)));
            }
            reply_href.push_str(&reply_mention_query(status, viewer_actor));
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
            // https://bsky.app permalinks are enough: the click handler
            // accepts data-object-ref without an at:// and resolves the CID.
            let can_boost = !at.is_empty() || object_ref.starts_with("https://bsky.app/");
            if can_boost {
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
        } else if !interaction_sid.is_empty() && !interaction_uri.is_empty() {
            // Fediverse / local (PHP remote branch; local skips Bite)
            let qt_cls = if qt_n > 0 {
                "icon-btn has-count"
            } else {
                "icon-btn"
            };
            actions.push_str(&format!(
                "<a class=\"{cls}\" href=\"?view={view}&amp;compose=1&amp;quote_object={qo}&amp;quote_status_id={qs}\" {eng}=\"1\" data-eng-count=\"{n}\" title=\"Quote\" aria-label=\"Quote\"><i class=\"ph ph-quotes\" aria-hidden=\"true\"></i>{count}</a>",
                cls = qt_cls,
                view = urlencoding_encode(from_q),
                qo = urlencoding_encode(interaction_uri),
                qs = urlencoding_encode(&interaction_sid),
                eng = eng_attr,
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
                &interaction_sid,
                interaction_uri,
                interaction_actor_ref,
                &boost_cls,
                if boosted { "Undo boost" } else { "Boost" },
                if boosted { "Undo boost" } else { "Boost" },
                "<i class=\"ph ph-repeat\" aria-hidden=\"true\"></i>",
                &action_count_html(rb_n),
                &format!(" {eng_attr}=\"1\" data-eng-count=\"{rb_n}\""),
            ));
            // Bite is Fediverse-remote only — never on own/local notes.
            if !local && !is_own {
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
                &interaction_sid,
                interaction_uri,
                actor_ref,
                &fav_cls,
                if fav { "Unlike" } else { "Like" },
                if fav { "Unlike" } else { "Like" },
                fav_icon,
                &action_count_html(fav_n),
                &format!(" {eng_attr}=\"1\" data-eng-count=\"{fav_n}\""),
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
                &interaction_sid,
                interaction_uri,
                "",
                bm_cls,
                if bm { "Bookmark folders" } else { "Bookmark" },
                if bm { "Bookmark folders" } else { "Bookmark" },
                bm_icon,
                "",
                &format!(
                    " {eng_attr}=\"1\" data-bm-picker=\"{}\"",
                    if bm { "1" } else { "0" }
                ),
            ));
        }

        if is_own && !uri.is_empty() {
            actions.push_str(&paint_own_post_controls(status, from_q, uri, &sid));
        } else if !uri.is_empty() {
            // Personal Block / Mute / Report (+ Open / Remote) — PHP block_quick_actions.
            let mod_menu =
                paint_moderation_overflow(from_q, actor_ref, uri, viewer_actor, status);
            if !mod_menu.is_empty() {
                actions.push_str(&mod_menu);
            } else if !bsky {
                // Guest / thin shells: keep a Remote link when we have no ⋯ menu.
                let remote = remote_object_href(uri, url_hint);
                if remote.starts_with("http://") || remote.starts_with("https://") {
                    actions.push_str(&format!(
                        "<a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\" class=\"meta\" title=\"Open on remote instance\">Remote</a>",
                        esc(&remote)
                    ));
                }
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

/// True when a URL can be used as an HTML `<video poster>` (image, not the video).
fn is_image_poster_url(url: &str) -> bool {
    let url = url.trim();
    if !url.starts_with("https://") {
        return false;
    }
    let path = url
        .split(['?', '#'])
        .next()
        .unwrap_or(url)
        .to_ascii_lowercase();
    if [".mp4", ".webm", ".mov", ".m4v", ".m3u8", ".mp3", ".m4a", ".aac", ".ogg", ".wav", ".flac"]
        .iter()
        .any(|ext| path.ends_with(ext))
    {
        return false;
    }
    if [".png", ".jpg", ".jpeg", ".gif", ".webp", ".avif"]
        .iter()
        .any(|ext| path.ends_with(ext))
    {
        return true;
    }
    // Bluesky CDN thumbs /playlist sibling thumbnail.jpg often omit sniffable
    // Content-Type but are still usable posters.
    url.contains("cdn.bsky.app/")
        || url.contains("/img/")
        || url.contains("thumbnail")
        || url.contains("/small/")
}

/// Mastodon-family hosts keep a still under `/small/*.png` next to `/original/*.mp4`.
fn guess_video_poster_url(url: &str) -> String {
    let url = url.trim();
    if !url.starts_with("https://") {
        return String::new();
    }
    // Same idea as PHP admin_guess_video_poster_url.
    let re = match regex::Regex::new(
        r"(?i)^(https://.+)/original/([^/?#]+)\.(mp4|m4v|mov|webm)([?#].*)?$",
    ) {
        Ok(re) => re,
        Err(_) => return String::new(),
    };
    if let Some(c) = re.captures(url) {
        return format!("{}/small/{}.png", &c[1], &c[2]);
    }
    // Bluesky HLS playlist → sibling thumbnail.jpg
    if url.contains("video.bsky.app/") {
        if let Some(idx) = url.find("/playlist.m3u8") {
            return format!("{}thumbnail.jpg", &url[..idx + 1]);
        }
    }
    String::new()
}

fn video_poster_attr(url: &str, preview: &str) -> String {
    let mut poster = preview.trim().to_string();
    if !is_image_poster_url(&poster) {
        poster = guess_video_poster_url(url);
    }
    if is_image_poster_url(&poster) {
        format!(" poster=\"{}\"", esc(&poster))
    } else {
        String::new()
    }
}

/// URL-only media projections (Home/Local/Federated hydrate) do not keep MIME.
/// Voice notes named `voice-note-*.webm` are audio, not video squares.
pub(crate) fn media_kind_from_url(url: &str) -> &'static str {
    let path = url
        .split(['?', '#'])
        .next()
        .unwrap_or(url)
        .trim()
        .to_ascii_lowercase();
    if path.is_empty() {
        return "unknown";
    }
    let file = path.rsplit('/').next().unwrap_or(&path);
    if file.starts_with("voice-note") {
        return "audio";
    }
    if [".png", ".jpg", ".jpeg", ".gif", ".webp", ".avif"]
        .iter()
        .any(|ext| path.ends_with(ext))
    {
        return "unknown";
    }
    if [".mp3", ".m4a", ".aac", ".ogg", ".oga", ".wav", ".flac", ".opus"]
        .iter()
        .any(|ext| path.ends_with(ext))
    {
        return "audio";
    }
    if [".mp4", ".webm", ".mov", ".m4v", ".m3u8"]
        .iter()
        .any(|ext| path.ends_with(ext))
    {
        return "video";
    }
    if path.contains("/audio/") || path.contains("/voice/") {
        return "audio";
    }
    "unknown"
}

fn attachment_paint_kind(att: &Value, url: &str) -> &'static str {
    let sniffed = media_kind_from_url(url);
    if sniffed == "audio" {
        return "audio";
    }
    let declared = att
        .get("type")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .to_ascii_lowercase();
    let mime = att
        .get("mime")
        .or_else(|| att.get("mediaType"))
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .to_ascii_lowercase();
    if mime.starts_with("audio/") || declared == "audio" {
        return "audio";
    }
    if declared == "gifv" {
        return "gifv";
    }
    if declared == "video" || sniffed == "video" {
        return "video";
    }
    "image"
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
        let atype = attachment_paint_kind(att, url);
        let preview = att
            .get("preview_url")
            .and_then(|v| v.as_str())
            .unwrap_or("");
        if atype == "video" || atype == "gifv" {
            let poster = video_poster_attr(url, preview);
            let is_hls = url
                .split(['?', '#'])
                .next()
                .unwrap_or(url)
                .to_ascii_lowercase()
                .ends_with(".m3u8")
                || url.contains("video.bsky.app/");
            let source = if is_hls {
                format!(" data-hls-src=\"{}\"", esc(url))
            } else {
                format!(" src=\"{}\"", esc(url))
            };
            // preload=metadata helps progressive MP4s show a first frame when
            // no image poster is available (PHP parity).
            cells.push(format!(
                "<video class=\"media-video\"{} controls loop playsinline preload=\"metadata\"{} referrerpolicy=\"no-referrer\"></video>",
                source,
                poster
            ));
        } else if atype == "audio" {
            cells.push(format!(
                "<div class=\"media-audio-card\" role=\"group\" aria-label=\"Audio post\"><img class=\"media-audio-art\" src=\"/api/assets/audio-post-default.jpg\" alt=\"\" loading=\"lazy\" decoding=\"async\"><audio class=\"media-audio\" src=\"{}\" controls preload=\"metadata\"></audio></div>",
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
    // `media-count-N` matches PHP timeline CSS (grid + single-video min-height).
    format!(
        "<div class=\"media-row media-count-{n}\">{}</div>",
        cells.join("")
    )
}

/// PHP `admin_poll_block_html` markup. Options come from `status.poll`, filled
/// at paint time from `masto_polls` so a warm hydrate envelope can stay stale.
fn poll_block_html(st: &Value, from: &str) -> String {
    let Some(poll) = st.get("poll").filter(|v| v.is_object()) else {
        return String::new();
    };
    let Some(options) = poll.get("options").and_then(|v| v.as_array()) else {
        return String::new();
    };
    let mut opts: Vec<(String, i64)> = Vec::new();
    for opt in options {
        let title = opt
            .get("title")
            .and_then(|v| v.as_str())
            .or_else(|| opt.get("name").and_then(|v| v.as_str()))
            .unwrap_or("")
            .trim();
        if title.is_empty() {
            continue;
        }
        let votes = opt
            .get("votes_count")
            .and_then(|v| v.as_i64())
            .unwrap_or(0);
        opts.push((title.to_string(), votes));
    }
    if opts.is_empty() {
        return String::new();
    }
    let poll_id = poll
        .get("id")
        .and_then(|v| v.as_i64())
        .or_else(|| {
            poll.get("id")
                .and_then(|v| v.as_str())
                .and_then(|s| s.parse::<i64>().ok())
        })
        .unwrap_or(0);
    let expired = json_flag(poll, "expired");
    let voted = json_flag(poll, "voted");
    let multiple = json_flag(poll, "multiple");
    let mut votes_count = poll
        .get("votes_count")
        .and_then(|v| v.as_i64())
        .unwrap_or(0);
    if votes_count <= 0 {
        votes_count = opts.iter().map(|(_, n)| *n).sum();
    }
    let voters_count = poll
        .get("voters_count")
        .and_then(|v| v.as_i64())
        .unwrap_or(0);
    let note_id = st
        .get("uri")
        .and_then(|v| v.as_str())
        .or_else(|| st.get("url").and_then(|v| v.as_str()))
        .unwrap_or("")
        .trim_end_matches('/');
    let show_results = expired || voted || poll_id <= 0;
    let can_vote = poll_id > 0 && !expired && !voted && note_id.starts_with("https://");
    let return_view = {
        let raw = from.trim();
        if raw.is_empty() { "home" } else { raw }
    };
    let label = if expired {
        "Poll · closed"
    } else if voted {
        "Poll · voted"
    } else {
        "Poll"
    };
    let mut html = format!(
        "<div class=\"poll-block\" data-note-id=\"{}\"{}>",
        esc(note_id),
        if poll_id > 0 {
            format!(" data-poll-id=\"{poll_id}\"")
        } else {
            String::new()
        }
    );
    html.push_str(&format!("<span class=\"poll-label\">{}</span>", esc(label)));
    if can_vote {
        let input_type = if multiple { "checkbox" } else { "radio" };
        html.push_str(&format!(
            "<form method=\"post\" action=\"?view={view}\" class=\"poll-vote-form\">\
             <input type=\"hidden\" name=\"action\" value=\"poll_vote\">\
             <input type=\"hidden\" name=\"poll_id\" value=\"{poll_id}\">\
             <input type=\"hidden\" name=\"note_id\" value=\"{note}\">\
             <input type=\"hidden\" name=\"return_view\" value=\"{view}\">",
            view = esc(return_view),
            note = esc(note_id),
        ));
        for (i, (title, _)) in opts.iter().enumerate() {
            html.push_str(&format!(
                "<label class=\"poll-opt poll-opt--vote\"><input type=\"{input_type}\" name=\"choices[]\" value=\"{i}\"><span>{}</span></label>",
                esc(title)
            ));
        }
        html.push_str(
            "<button class=\"btn btn-ghost\" type=\"submit\" style=\"margin-top:.4rem;padding:.3rem .7rem;font-size:.82rem\">Vote</button></form>",
        );
    } else {
        for (title, votes) in &opts {
            let pct = if show_results && votes_count > 0 {
                ((*votes as f64 / votes_count as f64) * 100.0).round() as i64
            } else {
                0
            };
            html.push_str(&format!(
                "<div class=\"poll-opt{}\"><div class=\"poll-opt-bar\" style=\"width:{pct}%\"></div><span class=\"poll-opt-text\">{}</span>",
                if show_results { " poll-opt--result" } else { "" },
                esc(title)
            ));
            if show_results {
                html.push_str(&format!(
                    "<span class=\"poll-opt-count\">{pct}% · {votes}</span>"
                ));
            }
            html.push_str("</div>");
        }
    }
    let mut meta = Vec::new();
    if show_results {
        meta.push(format!(
            "{votes_count} vote{}",
            if votes_count == 1 { "" } else { "s" }
        ));
        if voters_count > 0 {
            meta.push(format!(
                "{voters_count} voter{}",
                if voters_count == 1 { "" } else { "s" }
            ));
        }
    }
    if let Some(when) = poll.get("expires_label").and_then(|v| v.as_str()) {
        let when = when.trim();
        if !when.is_empty() {
            meta.push(when.to_string());
        }
    }
    if !meta.is_empty() {
        html.push_str(&format!(
            "<div class=\"poll-meta\">{}</div>",
            esc(&meta.join(" · "))
        ));
    }
    html.push_str("</div>");
    html
}

/// Render the canonical VAAK/Wafrn Ask wire fragment as one timeline card.
/// Ask answers are ordinary Notes whose content starts with an asker/`asked`
/// paragraph and a blockquote; without this normalization the lean painter
/// flattens the question and answer into one undifferentiated paragraph.
fn ask_card_parts(content: &str) -> Option<(String, String)> {
    let re = regex::Regex::new(
        r#"(?is)^\s*<p[^>]*>(.*?)\s*<a[^>]*>asked</a>\s*</p>\s*<blockquote[^>]*>(.*?)</blockquote>\s*(.*)$"#,
    ).ok()?;
    let (header, question, answer) = if let Some(caps) = re.captures(content.trim()) {
        let header = strip_tags(caps.get(1)?.as_str()).trim().to_string();
        let question = html_entity_decode_basic(strip_tags(caps.get(2)?.as_str()).trim());
        let answer = html_entity_decode_basic(&strip_tags(caps.get(3).map(|m| m.as_str()).unwrap_or(""))).trim().to_string();
        (header, question, answer)
    } else {
        // Wafrn's federated form is often plain text after ActivityPub
        // normalization: "@asker@host asked\n\nquestion\n\nanswer".
        let plain = html_entity_decode_basic(&strip_tags(content));
        let compact = regex::Regex::new(r"(?is)^\s*(.{1,240}?)\s+asked\s*\n+(.+)$").ok()?;
        let caps = compact.captures(plain.trim())?;
        let header = caps.get(1)?.as_str().trim().to_string();
        let anonymous = header.trim().eq_ignore_ascii_case("anonymous");
        if !header.contains('@') && !anonymous { return None; }
        let rest = caps.get(2)?.as_str().trim();
        let (question, answer) = if let Some((q, a)) = rest.split_once("\n\n") {
            (q.trim().to_string(), a.trim().to_string())
        } else {
            (rest.to_string(), String::new())
        };
        (header, question, answer)
    };
    if header.is_empty() || question.is_empty() {
        return None;
    }
    let card = format!(
        "<div class=\"ask-container\" style=\"margin:.35rem 0 .65rem;padding:.7rem .8rem;border:1px solid var(--primary,#ff70c7);border-radius:8px;background:var(--primary-dim,rgba(255,112,199,.12))\"><div class=\"ask-body\"><div class=\"ask-label\" style=\"font-weight:700;margin-bottom:.4rem;color:var(--primary,#ff70c7)\">{}</div><blockquote class=\"ask-text\" style=\"margin:0;padding:.15rem 0 .15rem .75rem;border-left:3px solid var(--primary,#ff70c7);white-space:pre-wrap\">{}</blockquote></div></div>",
        header,
        esc(&question).replace('\n', "<br>")
    );
    Some((card, answer))
}

fn ask_card_html(content: &str) -> Option<String> {
    ask_card_parts(content).map(|(card, _)| card)
}

fn ask_card_from_status(status: &Value) -> Option<(String, String)> {
    let ask = status.get("vaak_ask")?.as_object()?;
    let question = ask.get("ask_question")?.as_str()?.trim();
    if question.is_empty() { return None; }
    let actor = ask.get("ask_actor").and_then(|v| v.as_str()).unwrap_or("");
    let label = actor.rsplit('/').next().filter(|s| !s.is_empty()).unwrap_or("Someone");
    let answer = ask.get("ask_answer").and_then(|v| v.as_str()).unwrap_or("").trim();
    let label_html = if actor.starts_with("https://") {
        format!("<a class=\"ask-label\" href=\"{}\" style=\"display:inline-block;font-weight:700;margin-bottom:.4rem;color:var(--primary,#ff70c7);text-decoration:none\">{} asked</a>", esc(actor), esc(label))
    } else {
        format!("<div class=\"ask-label\" style=\"font-weight:700;margin-bottom:.4rem;color:var(--primary,#ff70c7)\">{} asked</div>", esc(label))
    };
    Some((format!(
        "<div class=\"ask-container\" style=\"margin:.35rem 0 .65rem;padding:.7rem .8rem;border:1px solid var(--primary,#ff70c7);border-radius:8px;background:var(--primary-dim,rgba(255,112,199,.12))\"><div class=\"ask-body\">{}<blockquote class=\"ask-text\" style=\"margin:0;padding:.15rem 0 .15rem .75rem;border-left:3px solid var(--primary,#ff70c7);white-space:pre-wrap\">{}</blockquote></div></div>",
        label_html, esc(question).replace('\n', "<br>")
    ), answer.to_string()))
}

fn ask_answer_html(answer: &str) -> String {
    let answer = answer.trim();
    if answer.is_empty() {
        String::new()
    } else {
        format!(
            "<hr class=\"ask-divider\" style=\"margin:.7rem 0;border:0;border-top:1px solid var(--primary,#ff70c7)\"><div class=\"ask-answer ask-answer--standalone\" style=\"margin:.7rem 0 .35rem;padding:.15rem 0 .15rem .8rem;border-left:3px solid var(--primary,#ff70c7);white-space:pre-wrap\">{}</div>",
            esc(answer).replace('\n', "<br>")
        )
    }
}

/// Drop the Ice Cubes parent teaser (`↩` + up to 140 chars, then a blank line)
/// from a Mentions reply. The card already has "in reply to @user".
fn strip_mentions_reply_bake(content: &str) -> String {
    let trimmed = content.trim();
    if trimmed.is_empty() {
        return String::new();
    }
    let plain = strip_tags(trimmed);
    if !plain.trim().starts_with('↩') {
        return trimmed.to_string();
    }
    if let Some(rest) = drop_leading_reply_bake_paragraph(trimmed) {
        return rest;
    }
    if let Some(idx) = plain.find("\n\n") {
        let rest = plain[idx..].trim();
        if !rest.is_empty() {
            return rest.to_string();
        }
    }
    trimmed.to_string()
}

/// `<p>↩ parent…</p>` then the reply. Leave the content alone when nothing
/// remains, so a post that is only the teaser does not become a blank card.
fn drop_leading_reply_bake_paragraph(html: &str) -> Option<String> {
    let trim = html.trim_start();
    let lower = trim.to_ascii_lowercase();
    if !lower.starts_with("<p") {
        return None;
    }
    let close = lower.find("</p>")?;
    let first_plain = strip_tags(&trim[..close]);
    if !first_plain.trim().starts_with('↩') {
        return None;
    }
    let rest = trim[close + 4..].trim();
    if rest.is_empty() {
        return None;
    }
    Some(rest.to_string())
}

/// Lean Mentions nest HTML — classes match PHP `notif-status-embed` chrome.
/// Full post actions (reply, quote, boost, like, bookmark). Overflow needs a
/// viewer actor; [`paint_lean_embed_from`] is the path that has one.
pub fn paint_lean_embed(status: &Value, hide_header: bool) -> String {
    paint_lean_embed_from(status, hide_header, "mentions", "")
}

/// Lean status card HTML with `from=` deep-link context (Mentions nest or Home fill).
pub fn paint_lean_embed_from(
    status: &Value,
    hide_header: bool,
    from: &str,
    viewer_actor: &str,
) -> String {
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
    let raw_content = status.get("content").and_then(|v| v.as_str()).unwrap_or("");
    // Mentions nests already show "in reply to @user". The API still bakes a
    // parent teaser into content for Ice Cubes; do not paint that twice.
    let baked_content = if hide_header && from == "mentions" {
        strip_mentions_reply_bake(raw_content)
    } else {
        raw_content.to_string()
    };
    let content_html = baked_content.as_str();
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
    let visibility = status
        .get("visibility")
        .and_then(|v| v.as_str())
        .unwrap_or("public");
    let rss = status
        .get("source")
        .and_then(|v| v.as_str())
        .map(|v| v.eq_ignore_ascii_case("rss"))
        .unwrap_or(false);
    let hover_meta = if rss {
        format!(
            " data-profile-hover-source=\"rss\" data-profile-hover-label=\"{}\"",
            esc(if display.is_empty() { acct } else { display })
        )
    } else {
        String::new()
    };

    let mut article_classes = String::from("tweet");
    if hide_header {
        article_classes.push_str(" tweet-embed-nohd");
    }
    if bsky {
        article_classes.push_str(" tweet-bsky");
    }

    // Stable identity attrs for client newer=1 / scroll dedupe (PHP parity:
    // data-rss-item on shared cards). Lean paint historically omitted these, so
    // PHP-rendered newer polls of the same RSS item slipped past textContent keys.
    let mut article_attrs = String::new();
    let rss_item_id = status
        .get("vaak_rss_item_id")
        .and_then(|v| v.as_i64())
        .filter(|n| *n > 0)
        .or_else(|| {
            status
                .get("id")
                .and_then(|v| v.as_str())
                .and_then(|id| id.strip_prefix("rss:")?.parse::<i64>().ok())
                .filter(|n| *n > 0)
        });
    if let Some(rid) = rss_item_id {
        article_attrs.push_str(&format!(
            " data-rss-item=\"{rid}\" data-timeline-key=\"rss:{rid}\""
        ));
    } else if bsky {
        // data-bsky-uri is an at:// fallback for the click handler. HTTPS
        // permalinks stay on the button's data-object-ref, not this attribute.
        let at = bsky_at_uri(status, uri);
        if at.starts_with("at://") {
            article_attrs.push_str(&format!(" data-bsky-uri=\"{}\"", esc(&at)));
        }
        if uri.starts_with("at://") {
            article_attrs.push_str(&format!(" data-timeline-key=\"bsky:{}\"", esc(uri)));
        } else if let Some(sid) = status.get("id").and_then(|v| v.as_str()).filter(|s| !s.is_empty())
        {
            article_attrs.push_str(&format!(" data-timeline-key=\"{}\"", esc(sid)));
        } else if at.starts_with("at://") {
            article_attrs.push_str(&format!(" data-timeline-key=\"bsky:{}\"", esc(&at)));
        }
    } else if let Some(sid) = status.get("id").and_then(|v| v.as_str()).filter(|s| !s.is_empty())
    {
        article_attrs.push_str(&format!(" data-timeline-key=\"{}\"", esc(sid)));
    }

    let mut inner = String::new();

    if !hide_header {
        let av = if avatar.starts_with("https://") {
            avatar
        } else {
            "https://mkultra.monster/img/avatar/default.webp"
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
            "<img class=\"tweet-av\" src=\"{}\" alt=\"\" width=\"40\" height=\"40\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\" data-profile-hover-actor=\"{}\"{}>",
            esc(av),
            esc(actor_ref),
            hover_meta
        );
        let av_block = if !profile_href.is_empty() {
            format!(
                "<a href=\"{}\" data-profile-hover-actor=\"{}\"{} style=\"text-decoration:none\">{}</a>",
                esc(&profile_href),
                esc(actor_ref),
                hover_meta,
                av_img
            )
        } else {
            av_img
        };
        let who = if !profile_href.is_empty() {
            format!(
                "<a class=\"who\" href=\"{}\" data-profile-hover-actor=\"{}\"{} style=\"color:inherit;text-decoration:none\">{}</a><a class=\"meta\" href=\"{}\" data-profile-hover-actor=\"{}\"{} style=\"color:var(--muted);text-decoration:none\"> @{}</a>",
                esc(&profile_href),
                esc(actor_ref),
                hover_meta,
                esc(display),
                esc(&profile_href),
                esc(actor_ref),
                hover_meta,
                esc(acct)
            )
        } else {
            format!(
                "<span class=\"who\">{}</span><span class=\"meta\"> @{}</span>",
                esc(display),
                esc(acct)
            )
        };
        // Network source tags (Bluesky / RSS) omitted — they only crowded the header.
        let when_html = if when.is_empty() {
            String::new()
        } else if !uri.is_empty() {
            let status_href = format!(
                "?view=status&object={}&from={}",
                urlencoding_encode(uri),
                urlencoding_encode(from_q)
            );
            format!(
                "<a class=\"meta tweet-time\" href=\"{}\" title=\"Open post\"> · {}</a>",
                esc(&status_href),
                esc(&when)
            )
        } else {
            format!("<span class=\"meta\"> · {}</span>", esc(&when))
        };
        inner.push_str(&format!(
            "<div class=\"tweet-hd\">{av}<div class=\"tweet-hd-main tweet-hd-main--fedi\"><div>{who}{when}</div></div></div>",
            av = av_block,
            who = who,
            when = when_html
        ));
    } else {
        // Nest chips: keep audience only (no Bluesky/RSS network badges).
        let mut chips = String::new();
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
    // Keep reply-shaped cards visibly threaded even when their parent was
    // hydrated on a different page (or is remote and cache-only).
    body_inner.push_str(&paint_reply_context(status, from));
    // Prefer original status HTML (mentions/hashtags/links + correct entities).
    // strip_tags+esc dropped <a> and double-encoded &#039; / &quot; into visible codes (0.7.13).
    // 0.7.14: rewrite anchors in-app, linkify bare URLs, tighten breaks, paint OG cards.
    let content_trim = content_html.trim();
    let poll_html = poll_block_html(status, from);
    // Local poll posts store the sentinel "(poll)" when the question text is empty.
    let poll_sentinel = !poll_html.is_empty() && plain.trim() == "(poll)";
    if poll_sentinel {
        // The poll block below is the post.
    } else if let Some((ask_html, answer)) = ask_card_from_status(status)
        .or_else(|| ask_card_parts(content_trim))
    {
        body_inner.push_str(&ask_html);
        body_inner.push_str(&ask_answer_html(&answer));
    } else if content_looks_like_html(content_trim) {
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
    body_inner.push_str(&poll_html);
    body_inner.push_str(&paint_status_link_card(status));

    // Match PHP's degraded-card contract: a queued/partially hydrated object
    // must never collapse into an empty article. Media-only posts remain
    // paintable above; this message is only used when no body, media, quote,
    // or link card is available yet.
    if body_inner.trim().is_empty() && json_flag(status, "vaak_degraded") {
        let reason = status
            .get("vaak_degraded_reason")
            .and_then(|v| v.as_str())
            .unwrap_or("empty_shell");
        let message = match reason {
            "announce_only" | "boost_original_missing" => "Loading boosted post…",
            "half_parsed" => "This post only partially parsed on VAAK.",
            _ => "Loading post…",
        };
        body_inner.push_str(&format!(
            "<div class=\"body feed-body meta vaak-degraded\"><span class=\"vaak-degraded-status\">{}</span></div>",
            esc(message)
        ));
    }

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
    if let Some(q_raw) = status
        .get("quote")
        .filter(|v| v.is_object())
        .or_else(|| status.get("vaak_quote_preview").filter(|v| v.is_object()))
    {
        let q = normalized_quote_preview(q_raw);
        // Quote-boosts in Notifications keep the same action row as the outer
        // reply, including overflow. Downgrading the nest to Open-only dropped
        // Boost / Block / Mute / Report on scrolled Fediverse quotes.
        let quote_from = if from.is_empty() { "mentions" } else { from };
        let q_html = paint_lean_embed_from(&q, false, quote_from, viewer_actor);
        inner.push_str(&format!(
            "<div class=\"quote-block\" style=\"margin-top:.55rem;background:transparent;border:0;padding:0;border-radius:0;color:inherit\">{q_html}</div>"
        ));
    }

    // Full action bar on Home / Local / Federated / profiles / outbox and
    // Notifications. PHP's notification cards expose the same interaction
    // set as timeline cards: reply, quote, boost, favourite, and bookmark.
    if matches!(from, "home" | "local" | "feed" | "remote_profile" | "outbox" | "mentions") {
        inner.push_str(&paint_lean_timeline_actions(status, from, viewer_actor));
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
        "<div class=\"{wrap}\"><article class=\"{ac}\"{attrs}>{inner}</article></div>",
        wrap = wrap_class,
        ac = article_classes,
        attrs = article_attrs,
        inner = inner
    )
}

/// Lean Home feed card: boost chrome + status body (no notif-embed wrap).
#[allow(dead_code)] // public helper; call sites use opts with from/viewer
pub fn paint_lean_feed_card(status: &Value) -> String {
    paint_lean_feed_card_opts(status, "home", "")
}

/// Lean feed card with `from=` + viewer actor (own-post Delete/Edit/Pin overflow).
pub fn paint_lean_feed_card_opts(status: &Value, from: &str, viewer_actor: &str) -> String {
    let from_q = if from.is_empty() { "home" } else { from };
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
            let booster_ref = booster
                .get("uri")
                .and_then(|v| v.as_str())
                .or_else(|| booster.get("url").and_then(|v| v.as_str()))
                .unwrap_or("");
            let booster_label = if booster_ref.starts_with("https://") {
                format!(
                    "<a href=\"?view=remote_profile&amp;actor={}&amp;from={}\" style=\"color:inherit;text-decoration:none\">{}</a>",
                    urlencoding_encode(booster_ref),
                    urlencoding_encode(from_q),
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
                "<div class=\"meta meta-row\" style=\"color:var(--primary)\"><i class=\"ph ph-repeat\" aria-hidden=\"true\"></i> {booster_label} boosted{when_bit}</div>"
            );
            st = inner;
        }
    }
    // Feed cards use the embed painter with header, then unwrap the outer embed div
    // so timeline items stay `<article class="tweet">` peers (matching PHP).
    // Boost chrome must live *inside* that article with class tweet-boost — wrapping
    // in a div breaks `.timeline-feed #timeline-items > article.tweet` separators.
    let painted = paint_lean_embed_from(&st, false, from_q, viewer_actor);
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
            "id": "1",
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
        assert!(html.contains("title=\"Reply\""), "mention embed must keep reply: {html}");
        assert!(html.contains("reblog_status"), "mention embed must keep boost: {html}");
        assert!(html.contains("view=status") || html.contains("quote_object="), "{html}");
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
        assert!(
            html.contains("class=\"meta tweet-time\"") && html.contains("title=\"Open post\""),
            "timestamp must deep-link to the status: {html}"
        );
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
    fn profile_status_links_open_in_new_tab_but_home_links_stay_in_app() {
        let body = "<p>See <a href=\"https://mkultra.monster/users/x/statuses/42\">this post</a></p>";
        let profile = prepare_feed_body_html(body, "remote_profile");
        assert!(profile.contains("class=\"status-link\"") && profile.contains("target=\"_blank\""), "{profile}");
        let home = prepare_feed_body_html(body, "home");
        assert!(home.contains("class=\"status-link\"") && !home.contains("target=\"_blank\""), "{home}");
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
        let html = paint_lean_feed_card_opts(
            &st,
            "home",
            "https://mkultra.monster/users/viewer",
        );
        assert!(html.contains("icon-btn"), "expected action icons: {html}");
        assert!(html.contains("favourite_status") || html.contains("ph-heart"), "{html}");
        assert!(html.contains("reblog_status") || html.contains("ph-repeat"), "{html}");
        assert!(html.contains("bite_remote") && html.contains("ph-tooth"), "{html}");
        assert!(html.contains("bookmark_status") || html.contains("ph-bookmark"), "{html}");
        assert!(html.contains("compose=1") && html.contains("reply_to="), "{html}");
        assert!(html.contains("view=dms&amp;peer="), "Fediverse overflow should offer DM: {html}");
        assert!(!html.contains(">Open</a></div>"), "Home should not be Open-only: {html}");
    }

    #[test]
    fn mentions_reply_drops_baked_parent_preview() {
        let st = json!({
            "id": "1791368209200001230",
            "uri": "https://gts.ghp.social/users/ghp/statuses/01M4AXV8J1JSAQ2FT16GVHR6B3",
            "url": "https://gts.ghp.social/users/ghp/statuses/01M4AXV8J1JSAQ2FT16GVHR6B3",
            "content": "<p>↩ i don&#039;t know what &quot;snac&quot; is but hey at least its posts kind of display properly on here</p>\n<p><span class=\"h-card\"><a class=\"mention\" href=\"https://mkultra.monster/users/cmdr_nova\">@<span>cmdr_nova</span></a></span>&quot;A simple, minimalistic ActivityPub instance written in portable C&quot;</p>",
            "created_at": "2026-10-07T10:14:00.000Z",
            "vaak_in_reply_to_url": "https://mkultra.monster/users/cmdr_nova/notes/a3784809cd039060",
            "account": {
                "acct": "ghp@gts.ghp.social",
                "display_name": "Gerold",
                "uri": "https://gts.ghp.social/users/ghp",
                "avatar": "https://example.com/a.png"
            },
            "media_attachments": []
        });
        let html = paint_lean_embed_from(
            &st,
            true,
            "mentions",
            "https://mkultra.monster/users/cmdr_nova",
        );
        assert!(html.contains("in reply to"), "{html}");
        assert!(html.contains("@cmdr_nova"), "{html}");
        assert!(
            html.contains("A simple, minimalistic ActivityPub instance"),
            "the reply body must stay: {html}"
        );
        assert!(
            !html.contains("i don") && !html.contains("display properly"),
            "baked parent preview must not be painted beside in-reply-to: {html}"
        );
        let home = paint_lean_embed_from(&st, false, "home", "");
        assert!(
            home.contains("display properly"),
            "Home must keep the teaser; only Mentions nests strip it: {home}"
        );
    }

    #[test]
    fn reply_seed_includes_chain_participants_once_and_skips_viewer() {
        let st = json!({
            "account": {"acct": "author@remote.example"},
            "vaak_reply_parent_handle": "parent.example",
            "mentions": [
                {"acct": "author@remote.example"},
                {"acct": "parent.example"},
                {"acct": "viewer@mkultra.monster"},
                {"acct": "alice.bsky.social@bsky.app"}
            ]
        });
        let query = reply_mention_query(&st, "https://mkultra.monster/users/viewer");
        assert_eq!(
            query,
            "&mention=parent.example%2Cauthor%40remote.example%2Calice.bsky.social",
            "reply seeds must be stable, deduplicated, and viewer-safe"
        );
    }

    #[test]
    fn boosted_card_targets_underlying_status_and_keeps_reply_context() {
        let st = json!({
            "id": "announce-wrapper",
            "uri": "https://remote.example/announce/99",
            "content": "",
            "created_at": "2026-10-05T05:00:00.000Z",
            "reblogged": false,
            "account": {"acct": "booster@remote.example", "display_name": "Booster", "uri": "https://remote.example/users/booster"},
            "reblog": {
                "id": "12345",
                "uri": "https://origin.example/users/author/statuses/12345",
                "content": "<p>reply-shaped post</p>",
                "created_at": "2026-10-05T04:00:00.000Z",
                "in_reply_to_id": "https://origin.example/users/author/statuses/1",
                "vaak_in_reply_to_url": "https://origin.example/users/author/statuses/1",
                "account": {"acct": "author@origin.example", "display_name": "Author", "uri": "https://origin.example/users/author"},
                "media_attachments": []
            }
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("reply-context") && html.contains("in reply to"), "{html}");
        assert!(html.contains("name=\"object_id\" value=\"https://origin.example/users/author/statuses/12345\""), "boost must target original object: {html}");
        assert!(!html.contains("value=\"https://remote.example/announce/99\""), "wrapper URI leaked into action: {html}");
    }

    #[test]
    fn bsky_reply_context_converts_at_uri() {
        let st = json!({
            "id": "bsky:reply",
            "uri": "at://did:plc:child/app.bsky.feed.post/child",
            "content": "<p>reply</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "source": "bluesky",
            "vaak_in_reply_to_url": "at://did:plc:parent/app.bsky.feed.post/parent",
            "account": {"acct": "child.bsky.social", "display_name": "Child", "uri": "https://bsky.app/profile/child.bsky.social"},
            "media_attachments": []
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("in reply to"), "{html}");
        assert!(html.contains("bsky.app%2Fprofile%2Fdid%3Aplc%3Aparent%2Fpost%2Fparent"), "{html}");
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
        let favourite_pos = html.find("value=\"favourite_status\"").expect("RSS favourite action");
        let bookmark_pos = html.find("value=\"bookmark_status\"").expect("RSS bookmark action");
        assert!(favourite_pos < bookmark_pos, "RSS Favourite must precede Bookmark: {html}");
        assert!(!html.contains("bite_remote"), "RSS must not Bite: {html}");
        assert!(
            html.contains("data-rss-item=\"9\""),
            "RSS cards need data-rss-item for client dedupe: {html}"
        );
        assert!(
            html.contains("data-timeline-key=\"rss:9\""),
            "RSS cards need data-timeline-key: {html}"
        );
    }

    #[test]
    fn video_poster_guesses_mastodon_small_png() {
        let mp4 = "https://files.mastodon.social/media_attachments/files/1/2/original/abc.mp4";
        let poster = guess_video_poster_url(mp4);
        assert_eq!(
            poster,
            "https://files.mastodon.social/media_attachments/files/1/2/small/abc.png"
        );
        assert!(is_image_poster_url(&poster));
        assert!(!is_image_poster_url(mp4));

        let st = json!({
            "id": "1",
            "uri": "https://example.com/users/x/statuses/1",
            "content": "<p>clip</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "account": {
                "acct": "x",
                "display_name": "X",
                "avatar": "https://example.com/a.png",
                "uri": "https://example.com/users/x"
            },
            "media_attachments": [{
                "id": "m1",
                "type": "video",
                "url": mp4,
                "preview_url": mp4
            }]
        });
        let html = paint_lean_feed_card(&st);
        assert!(
            html.contains("poster=\"https://files.mastodon.social/media_attachments/files/1/2/small/abc.png\""),
            "{html}"
        );
        assert!(
            !html.contains("poster=\"https://files.mastodon.social/media_attachments/files/1/2/original/abc.mp4\""),
            "must not use mp4 as poster: {html}"
        );
    }

    #[test]
    fn bluesky_hls_video_uses_lazy_hls_source() {
        let playlist = "https://video.bsky.app/watch/did:plc:test/3abc/playlist.m3u8";
        let st = json!({
            "id": "bsky:test-video",
            "uri": "at://did:plc:test/app.bsky.feed.post/3abc",
            "content": "<p>video</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "account": {"acct": "test.bsky.social", "display_name": "Test", "avatar": "https://example.com/a.png", "uri": "https://bsky.app/profile/test.bsky.social"},
            "media_attachments": [{"type": "video", "url": playlist, "preview_url": "https://video.bsky.app/watch/did:plc:test/3abc/thumbnail.jpg"}]
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("data-hls-src=\"https://video.bsky.app/watch/did:plc:test/3abc/playlist.m3u8\""), "{html}");
        assert!(!html.contains(" src=\"https://video.bsky.app/watch/did:plc:test/3abc/playlist.m3u8\""), "HLS must be attached by hls.js: {html}");
    }

    #[test]
    fn collapse_consecutive_breaks() {
        // Double <br> inside one <p> → real sibling paragraphs (VAAK shape).
        let para = prepare_feed_body_html("<p>one<br />\n<br />\ntwo</p>", "home");
        let lower = para.to_ascii_lowercase();
        assert!(
            lower.matches("<p>").count() >= 2,
            "double br should become separate p tags: {para}"
        );
        assert!(
            !lower.contains("<br><br>") && !lower.contains("<br /><br"),
            "should not leave double br: {para}"
        );
        assert!(para.contains("one") && para.contains("two"), "{para}");

        let tight = prepare_feed_body_html("<p>one<br /><br /><br />two</p>", "home");
        let lower_t = tight.to_ascii_lowercase();
        assert!(
            lower_t.matches("<p>").count() >= 2,
            "3+ breaks should still become paragraphs: {tight}"
        );
        assert!(tight.contains("one") && tight.contains("two"), "{tight}");

        // Soft single <br> stays inside one paragraph.
        let soft = prepare_feed_body_html("<p>one<br />two</p>", "home");
        let soft_l = soft.to_ascii_lowercase();
        assert_eq!(soft_l.matches("<p>").count(), 1, "soft br stays one p: {soft}");
        assert!(soft_l.contains("<br"), "soft br preserved: {soft}");
    }

    #[test]
    fn strips_empty_paragraph_spacers() {
        let html = prepare_feed_body_html("<p>one</p><p></p><p><br></p><p>two</p>", "home");
        let lower = html.to_ascii_lowercase();
        assert!(!lower.contains("<p></p>"), "empty p removed: {html}");
        assert!(html.contains("one") && html.contains("two"), "{html}");
    }

    #[test]
    fn does_not_explode_brbr_inside_anchor() {
        let html = prepare_feed_body_html(
            "<p>hello <a href=\"https://x.test/1\">title<br><br>subtitle</a> end</p>",
            "home",
        );
        assert!(
            html.contains("href=\"https://x.test/1\"") && html.contains("</a>"),
            "anchor preserved: {html}"
        );
        assert!(
            html.contains("title<br><br>subtitle") || html.contains("title<br /><br />subtitle"),
            "double br inside anchor kept (not split): {html}"
        );
        // Must not close </p> before </a>.
        let a_at = html.find("<a ").expect("a");
        let close_a = html.find("</a>").expect("/a");
        let mid = &html[a_at..close_a];
        assert!(
            !mid.to_ascii_lowercase().contains("</p>"),
            "must not split inside anchor: {html}"
        );
        assert_eq!(
            html.to_ascii_lowercase().matches("<p>").count()
                + html.to_ascii_lowercase().matches("<p ").count(),
            html.to_ascii_lowercase().matches("</p>").count(),
            "balanced p tags: {html}"
        );
    }

    #[test]
    fn does_not_explode_sibling_paragraphs() {
        let html = prepare_feed_body_html("<p>a</p><p>b<br><br>c</p>", "home");
        assert_eq!(
            html.to_ascii_lowercase().matches("<p>").count(),
            html.to_ascii_lowercase().matches("</p>").count(),
            "{html}"
        );
        // Second paragraph splits safely; first stays intact.
        assert!(html.contains("<p>a</p>"), "{html}");
        assert!(html.contains("<p>b</p>") && html.contains("<p>c</p>"), "{html}");
        assert!(!html.contains("<p>b<p>"), "no nested p smash: {html}");
    }

    #[test]
    fn splits_p_with_attributes() {
        let html = prepare_feed_body_html("<p dir=\"auto\">one<br><br>two</p>", "home");
        assert!(
            html.to_ascii_lowercase().matches("<p>").count()
                + html.to_ascii_lowercase().matches("<p ").count()
                >= 2,
            "{html}"
        );
        assert!(html.contains("one") && html.contains("two"), "{html}");
        assert!(!html.contains("<p dir=\"auto\">one<p>"), "{html}");
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
    fn paints_full_width_youtube_from_body_url_without_card() {
        let st = json!({
            "id": "1",
            "uri": "https://mkultra.monster/users/cmdr_nova/notes/abc",
            "content": "<p>watch https://youtu.be/z6hGTNMgl28?si=Rg2HeQfcZQFT7Z5B</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "account": {
                "acct": "cmdr_nova",
                "display_name": "Nova",
                "avatar": "https://mkultra.monster/img/avatar/local-default.webp",
                "uri": "https://mkultra.monster/users/cmdr_nova"
            },
            "media_attachments": [],
            "card": null
        });
        let html = paint_lean_feed_card(&st);
        assert!(
            html.contains("youtube-link-card--wide") && html.contains("data-youtube-id=\"z6hGTNMgl28\""),
            "youtube wide card: {html}"
        );
        assert!(html.contains("data-youtube-play"), "click-to-play: {html}");
        assert!(
            html.contains("i.ytimg.com/vi/z6hGTNMgl28/hqdefault.jpg"),
            "thumb: {html}"
        );
        assert!(html.contains("Open on YouTube"), "{html}");
    }

    #[test]
    fn paints_youtube_from_card_with_title() {
        let st = json!({
            "id": "1",
            "uri": "https://mkultra.monster/users/cmdr_nova/notes/abc",
            "content": "<p>ep</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "account": {
                "acct": "cmdr_nova",
                "display_name": "Nova",
                "avatar": "https://mkultra.monster/img/avatar/local-default.webp",
                "uri": "https://mkultra.monster/users/cmdr_nova"
            },
            "media_attachments": [],
            "card": {
                "url": "https://www.youtube.com/watch?v=z6hGTNMgl28",
                "title": "The World is Empty",
                "provider_name": "YouTube",
                "image": "https://i.ytimg.com/vi/z6hGTNMgl28/hqdefault.jpg"
            }
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("The World is Empty"), "{html}");
        assert!(html.contains("youtube-link-card--wide"), "{html}");
        assert!(!html.contains("class=\"link-card__media\""), "must not use side thumb: {html}");
    }

    #[test]
    fn paints_starter_pack_as_inline_collection_card() {
        let st = json!({
            "id": "bsky:starter",
            "uri": "at://did:plc:test/app.bsky.feed.post/starter",
            "content": "",
            "account": {"acct": "creator.bsky.social", "display_name": "Creator"},
            "card": {
                "url": "",
                "title": "Bluesky Starter Pack",
                "description": "A starter pack shared from Bluesky",
                "provider_name": "VAAK collection",
                "vaak_collection_kind": "starter_pack"
            },
            "media_attachments": []
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("bsky-collection-card"), "{html}");
        assert!(html.contains("Bluesky Starter Pack"), "{html}");
        assert!(!html.contains("Bluesky post"), "{html}");
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
                "media_attachments": [{"type":"image","url":"https://example.com/outer.jpg","preview_url":"https://example.com/outer.jpg"}],
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
        assert!(html.contains("nested"), "quoted_status envelope must render its nested body: {html}");
        assert!(html.contains("outer.jpg"), "envelope media must survive quote normalization: {html}");
        assert!(!html.contains("class=\"link-card\""), "should dedupe OG card: {html}");
    }

    #[test]
    fn suppresses_alternate_status_card_under_structured_quote() {
        assert!(looks_like_status_url("https://bridge.example/users/y/statuses/99"));
        let mut st = json!({
            "id": "2", "uri": "https://example.com/users/x/statuses/2",
            "content": "<p>qt</p>", "created_at": "2026-10-05T05:00:00.000Z",
            "account": {"acct":"x","display_name":"X","avatar":"https://example.com/a.png","uri":"https://example.com/users/x"},
            "media_attachments": [],
            "card": {"url":"https://bridge.example/users/y/statuses/99","title":"quoted"},
            "quote": {"quoted_status": {"url":"https://origin.example/@y/99","content":"<p>nested</p>","account":{"acct":"y","display_name":"Y","avatar":"https://example.com/a.png"},"media_attachments":[]}}
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("quote-block"), "{html}");
        assert!(!html.contains("class=\"link-card\""), "alternate status card should be suppressed: {html}");
        st["card"]["url"] = json!("https://example.org/article");
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("class=\"link-card\""), "unrelated article card should remain: {html}");
    }

    #[test]
    fn remote_profile_gets_icon_btn_actions() {
        let st = json!({
            "id": "99",
            "uri": "https://mastodon.social/users/x/statuses/1",
            "url": "https://mastodon.social/@x/1",
            "content": "<p>profile post</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "favourited": false,
            "reblogged": false,
            "bookmarked": false,
            "account": {
                "acct": "x@mastodon.social",
                "display_name": "X",
                "avatar": "https://example.com/a.png",
                "uri": "https://mastodon.social/users/x"
            },
            "media_attachments": []
        });
        let html = paint_lean_feed_card_opts(&st, "remote_profile", "");
        assert!(html.contains("icon-btn"), "remote_profile must get actions: {html}");
        assert!(html.contains("favourite_status") || html.contains("ph-heart"), "{html}");
        assert!(html.contains("view=remote_profile"), "{html}");
        assert!(html.contains("data-profile-hover-actor=\"https://mastodon.social/users/x\""), "hover actor missing: {html}");
        assert!(!html.contains(">Open</a></div>"), "must not be Open-only: {html}");
    }

    #[test]
    fn remote_note_gets_block_mute_report_overflow() {
        let st = json!({
            "id": "99",
            "uri": "https://mastodon.social/users/x/statuses/99",
            "url": "https://mastodon.social/users/x/statuses/99",
            "content": "<p>hi</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "favourited": false,
            "reblogged": false,
            "bookmarked": false,
            "_vaak_viewer_muted": false,
            "_vaak_viewer_blocked": false,
            "_vaak_viewer_block_id": 0,
            "account": {
                "acct": "x@mastodon.social",
                "display_name": "X",
                "avatar": "https://example.com/a.png",
                "uri": "https://mastodon.social/users/x"
            },
            "media_attachments": []
        });
        let html = paint_lean_feed_card_opts(
            &st,
            "home",
            "https://mkultra.monster/users/cmdr_nova",
        );
        assert!(html.contains("post-action-menu"), "⋯ menu: {html}");
        assert!(html.contains("user_block_add") || html.contains("Block for me"), "{html}");
        assert!(html.contains("mute_remote") || html.contains("Mute for me"), "{html}");
        assert!(
            html.contains("view=report")
                && html.contains("report_target=")
                && html.contains("report_object="),
            "Report with post ref: {html}"
        );
        assert!(
            html.contains("deprioritize_remote") && html.contains("Deprioritize on Home"),
            "Deprioritize on Home: {html}"
        );
        assert!(!html.contains("delete_status"), "must not Delete peer: {html}");
    }

    #[test]
    fn deprioritized_actor_offers_stop_and_own_posts_do_not() {
        let mut st = json!({
            "id": "99",
            "uri": "https://mastodon.social/users/x/statuses/99",
            "url": "https://mastodon.social/users/x/statuses/99",
            "content": "<p>hi</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "favourited": false,
            "reblogged": false,
            "bookmarked": false,
            "_vaak_viewer_muted": false,
            "_vaak_viewer_blocked": false,
            "_vaak_viewer_deprioritized": true,
            "account": {
                "acct": "x@mastodon.social",
                "display_name": "X",
                "avatar": "https://example.com/a.png",
                "uri": "https://mastodon.social/users/x"
            },
            "media_attachments": []
        });
        let html = paint_lean_feed_card_opts(
            &st,
            "feed",
            "https://mkultra.monster/users/cmdr_nova",
        );
        assert!(html.contains("undeprioritize_remote"), "{html}");
        assert!(html.contains("Stop deprioritizing"), "{html}");
        assert!(
            !html.contains("value=\"deprioritize_remote\""),
            "stop form must not also add: {html}"
        );
        st["_vaak_viewer_self"] = json!(true);
        let html = paint_lean_feed_card_opts(
            &st,
            "local",
            "https://mkultra.monster/users/cmdr_nova",
        );
        assert!(!html.contains("deprioritize_remote"), "own bluesky/self: {html}");
        assert!(!html.contains("undeprioritize_remote"), "own bluesky/self: {html}");
    }

    #[test]
    fn audio_only_and_poll_cards_render_on_timelines() {
        let audio = json!({
            "id": "a1",
            "uri": "https://mkultra.monster/users/cmdr_nova/notes/audio1",
            "url": "https://mkultra.monster/users/cmdr_nova/notes/audio1",
            "content": "<p></p>",
            "created_at": "2026-10-07T12:00:00.000Z",
            "account": {
                "acct": "cmdr_nova",
                "display_name": "Nova",
                "avatar": "https://mkultra.monster/img/avatar/local-default.webp",
                "uri": "https://mkultra.monster/users/cmdr_nova"
            },
            "media_attachments": [{
                "type": "image",
                "url": "https://media.example/mkultra/media/2026/10/07/clip.mp3",
                "preview_url": "https://media.example/mkultra/media/2026/10/07/clip.mp3"
            }]
        });
        let html = paint_lean_feed_card_opts(&audio, "local", "");
        assert!(html.contains("media-audio-card"), "{html}");
        assert!(html.contains("audio-post-default.jpg"), "{html}");
        assert!(html.contains("class=\"media-audio\""), "{html}");
        assert!(!html.contains("media-lightbox-trigger"), "mp3 must not paint as an image: {html}");

        let poll = json!({
            "id": "p1",
            "uri": "https://mkultra.monster/users/cmdr_nova/notes/poll1",
            "url": "https://mkultra.monster/users/cmdr_nova/notes/poll1",
            "content": "<p>(poll)</p>",
            "created_at": "2026-10-07T12:00:00.000Z",
            "account": {
                "acct": "cmdr_nova",
                "display_name": "Nova",
                "avatar": "https://mkultra.monster/img/avatar/local-default.webp",
                "uri": "https://mkultra.monster/users/cmdr_nova"
            },
            "media_attachments": [],
            "poll": {
                "id": "4",
                "expired": false,
                "multiple": false,
                "votes_count": 4,
                "voters_count": 3,
                "voted": false,
                "expires_label": "ends Oct 8, 2026 · 02:39 UTC",
                "options": [
                    {"title": "ew what the hell", "votes_count": 2},
                    {"title": "i love napkins", "votes_count": 2}
                ]
            }
        });
        let html = paint_lean_feed_card_opts(
            &poll,
            "home",
            "https://mkultra.monster/users/someone",
        );
        assert!(html.contains("poll-block"), "{html}");
        assert!(html.contains("poll_vote"), "{html}");
        assert!(html.contains("ew what the hell"), "{html}");
        assert!(html.contains("i love napkins"), "{html}");
        assert!(!html.contains("(poll)"), "sentinel text should stay hidden: {html}");
        assert!(
            html.contains("Deprioritize on Home"),
            "another person's poll keeps the overflow action: {html}"
        );
    }

    #[test]
    fn own_note_with_viewer_gets_delete_edit_pin() {
        let st = json!({
            "id": "4242",
            "uri": "https://mkultra.monster/users/cmdr_nova/notes/abc",
            "url": "https://mkultra.monster/users/cmdr_nova/notes/abc",
            "content": "<p>my post</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "spoiler_text": "",
            "sensitive": false,
            "pinned": false,
            "favourited": false,
            "reblogged": false,
            "bookmarked": false,
            "account": {
                "acct": "cmdr_nova",
                "display_name": "Nova",
                "avatar": "https://mkultra.monster/img/avatar/default.webp",
                "uri": "https://mkultra.monster/users/cmdr_nova"
            },
            "media_attachments": []
        });
        let html = paint_lean_feed_card_opts(
            &st,
            "home",
            "https://mkultra.monster/users/cmdr_nova",
        );
        assert!(html.contains("delete_status"), "own note Delete: {html}");
        assert!(
            html.contains("name=\"local_id\" value=\"4242\""),
            "profile-style small id is real local_id: {html}"
        );
        assert!(html.contains("js-edit-post") && html.contains("edit_note="), "Edit: {html}");
        assert!(html.contains("pin_status") || html.contains("Pin to profile"), "Pin: {html}");
        assert!(html.contains("post-action-menu"), "overflow: {html}");
        assert!(!html.contains("deprioritize_remote"), "own post must not deprioritize: {html}");
        assert!(!html.contains("undeprioritize_remote"), "own post must not deprioritize: {html}");
        assert!(!html.contains("bite_remote"), "own must not Bite: {html}");
        assert!(html.contains("data-own-eng") || html.contains("ph-heart"), "{html}");
        assert!(
            html.contains("action=\"/vaak/?view=home\""),
            "absolute /vaak/ delete action: {html}"
        );
    }

    #[test]
    fn timeline_snowflake_id_does_not_become_local_id() {
        let st = json!({
            "id": "1791191756040454296",
            "uri": "https://mkultra.monster/users/cmdr_nova/notes/abc",
            "url": "https://mkultra.monster/users/cmdr_nova/notes/abc",
            "content": "<p></p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "account": {
                "acct": "cmdr_nova",
                "display_name": "Nova",
                "avatar": "https://mkultra.monster/img/avatar/local-default.webp",
                "uri": "https://mkultra.monster/users/cmdr_nova"
            },
            "media_attachments": []
        });
        let html = paint_lean_feed_card_opts(
            &st,
            "local",
            "https://mkultra.monster/users/cmdr_nova",
        );
        assert!(html.contains("delete_status"), "own note Delete: {html}");
        assert!(
            html.contains("name=\"local_id\" value=\"0\""),
            "snowflake must not be posted as local_id: {html}"
        );
        assert!(
            !html.contains("name=\"local_id\" value=\"1791191756040454296\""),
            "must not send snowflake: {html}"
        );
        assert!(
            html.contains("name=\"note_id\" value=\"https://mkultra.monster/users/cmdr_nova/notes/abc\""),
            "note_id present for PHP resolve: {html}"
        );
    }

    #[test]
    fn other_local_note_skips_delete_when_viewer_differs() {
        let st = json!({
            "id": "55",
            "uri": "https://mkultra.monster/users/valerie/notes/xyz",
            "url": "https://mkultra.monster/users/valerie/notes/xyz",
            "content": "<p>peer post</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "favourited": false,
            "reblogged": false,
            "bookmarked": false,
            "account": {
                "acct": "valerie",
                "display_name": "Valerie",
                "avatar": "https://mkultra.monster/img/avatar/default.webp",
                "uri": "https://mkultra.monster/users/valerie"
            },
            "media_attachments": []
        });
        let html = paint_lean_feed_card_opts(
            &st,
            "remote_profile",
            "https://mkultra.monster/users/cmdr_nova",
        );
        assert!(html.contains("icon-btn"), "still has actions: {html}");
        assert!(!html.contains("delete_status"), "must not Delete peer note: {html}");
        assert!(!html.contains("js-edit-post"), "must not Edit peer note: {html}");
        assert!(!html.contains("pin_status"), "must not Pin peer note: {html}");
        assert!(!html.contains("bite_remote"), "local peer skips Bite: {html}");
    }

    #[test]
    fn media_only_cw_posts_keep_media_inside_the_cw_gate() {
        let st = json!({
            "id": "media-only-1",
            "uri": "https://example.com/users/a/statuses/1",
            "content": "",
            "created_at": "2026-10-05T05:00:00.000Z",
            "sensitive": true,
            "spoiler_text": "Gallery",
            "account": {
                "acct": "a@example.com",
                "display_name": "A",
                "avatar": "https://example.com/a.png",
                "uri": "https://example.com/users/a"
            },
            "media_attachments": [{
                "type": "image",
                "url": "https://example.com/media/photo.jpg",
                "preview_url": "https://example.com/media/photo.jpg"
            }]
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("cw-gate"), "media-only post should be gated: {html}");
        assert!(html.contains("photo.jpg"), "media must remain inside the gate: {html}");
    }

    #[test]
    fn queued_degraded_cards_never_render_as_empty_articles() {
        let st = json!({
            "id": "queued-1",
            "uri": "https://remote.example/users/a/statuses/1",
            "content": "",
            "created_at": "2026-10-05T05:00:00.000Z",
            "vaak_degraded": true,
            "vaak_degraded_reason": "empty_shell",
            "account": {"acct": "a@remote.example", "display_name": "A", "uri": "https://remote.example/users/a"},
            "media_attachments": []
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("vaak-degraded-status"), "queued status needs a visible state: {html}");
        assert!(html.contains("Loading post"), "queued status needs a loading label: {html}");
    }

    #[test]
    fn timeline_ask_uses_structured_php_context() {
        let st = json!({
            "id": "ask-1",
            "uri": "https://mkultra.monster/users/cmdr_nova/notes/ask-1",
            "content": "<p>legacy flattened ask</p>",
            "created_at": "2026-10-05T05:00:00.000Z",
            "account": {"acct":"cmdr_nova","display_name":"Cmdr Nova","avatar":"https://example.com/a.png","uri":"https://mkultra.monster/users/cmdr_nova"},
            "media_attachments": [],
            "vaak_ask": {"ask_actor":"https://example.com/users/asker","ask_question":"What is VAAK?","ask_answer":"A social wire."}
        });
        let html = paint_lean_feed_card(&st);
        assert!(html.contains("ask-container"), "{html}");
        assert!(html.contains("What is VAAK?"), "{html}");
        assert!(html.contains("A social wire."), "{html}");
        assert!(html.contains("ask-divider"), "{html}");
        assert!(html.find("ask-container").unwrap() < html.find("ask-answer").unwrap(), "{html}");
        assert!(!html.contains("legacy flattened ask"), "{html}");
    }

    #[test]
    fn timeline_ask_parses_plain_wafrn_compact_form() {
        let st = json!({
            "uri": "https://app.wafrn.net/fediverse/post/ask",
            "content": "@asker@example.test asked\n\nWhat is VAAK?\n\nA social bridge.",
            "account": {"acct": "wafrn@example.test", "display_name": "Wafrn User"}
        });
        let html = paint_lean_embed(&st, false);
        assert!(html.contains("ask-container"), "{html}");
        assert!(html.contains("What is VAAK?"), "{html}");
        assert!(html.contains("A social bridge."), "{html}");
        assert!(html.contains("ask-divider"), "{html}");
        assert!(html.find("ask-container").unwrap() < html.find("ask-answer").unwrap(), "{html}");
    }

    #[test]
    fn timeline_ask_parses_anonymous_wafrn_compact_form() {
        let st = json!({
            "uri": "https://app.wafrn.net/fediverse/post/anonymous-ask",
            "content": "anonymous asked\n\nthoughts on crows and ravens?\n\ngive them shiny stuff",
            "account": {"acct": "admin@app.wafrn.net", "display_name": "Wafrn"}
        });
        let html = paint_lean_embed(&st, false);
        assert!(html.contains("ask-container"), "{html}");
        assert!(html.contains("thoughts on crows and ravens?"), "{html}");
        assert!(html.contains("give them shiny stuff"), "{html}");
        assert!(html.find("ask-container").unwrap() < html.find("ask-answer").unwrap(), "{html}");
    }

}
