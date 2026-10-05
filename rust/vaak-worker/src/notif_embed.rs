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
    // Collapse common entities enough for body plain.
    out.replace("&nbsp;", " ")
        .replace("&amp;", "&")
        .replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&quot;", "\"")
        .replace("&#39;", "'")
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
    if !plain.is_empty() {
        body_inner.push_str(&format!(
            "<div class=\"body feed-body\" style=\"white-space:pre-wrap\">{}</div>",
            esc(&plain)
        ));
    }
    body_inner.push_str(&media_row_html(status));

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
    // so Home timeline items stay `<article class="tweet">` peers.
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
        article
    } else {
        format!(
            "<div class=\"tweet-boost\">{boost_header}{article}</div>",
            boost_header = boost_header,
            article = article
        )
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
    fn paint_hides_header_and_escapes() {
        let st = json!({
            "uri": "https://example.com/users/x/notes/1",
            "content": "<p>hi <b>there</b> & stuff</p>",
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
        assert!(html.contains("hi there &amp; stuff") || html.contains("hi there & stuff"));
        assert!(!html.contains("<script>"));
        assert!(html.contains("Open"));
        assert!(html.contains("view=status"));
    }
}
