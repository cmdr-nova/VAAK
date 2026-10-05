//! Mentions fill HTML from warm Redis notification envelopes (0.7.1).
//!
//! Serves the soft-nav / hard-shell fill partial without PHP
//! `admin_render_notification_stream` (~11s p50 on prod). Nested cards reuse
//! `notif_embed::paint_lean_embed` in-process (no per-card HTTP).

use anyhow::Result;
use serde_json::Value;

use crate::config::Config;
use crate::notif_embed::{self, paint_lean_embed};
use crate::notif_list::notifications_shadow;

#[derive(Debug, Clone)]
pub struct MentionsHtmlReport {
    pub html: String,
    pub next_max_id: String,
    pub has_more: bool,
    pub tip_id: String,
    pub count: usize,
    pub source: String,
    pub redis_key: String,
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
    out.replace("&nbsp;", " ")
        .replace("&amp;", "&")
        .replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&quot;", "\"")
        .replace("&#39;", "'")
}

fn relative_time(created_at: &str) -> String {
    use chrono::{DateTime, Utc};
    let Ok(dt) = DateTime::parse_from_rfc3339(created_at) else {
        return String::new();
    };
    let secs = (Utc::now() - dt.with_timezone(&Utc)).num_seconds().max(0);
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

fn type_label(n_type: &str, status_uri: &str) -> String {
    match n_type {
        "follow" => "👤 followed you".into(),
        "favourite" => "★ liked your post".into(),
        "reblog" => "🔁 boosted your post".into(),
        "mention" => "＠ mentioned you".into(),
        "quote" => "💬 quoted your post".into(),
        "poll" => "📊 poll ended".into(),
        "update" => "✏️ edited a post you liked".into(),
        "status" => "✉ posted".into(),
        "phyrian_imprint" => "◈ offered you a Phyrian imprint".into(),
        "phyrian_resonance" => "◈ wants to exchange Phyrian resonance".into(),
        "bite" => {
            if status_uri.contains("/bites-received/") || status_uri.is_empty() {
                "🦷 bit you".into()
            } else {
                "🦷 bit your post".into()
            }
        }
        other => other.to_string(),
    }
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

fn digits_only(id: &str) -> String {
    id.chars().filter(|c| c.is_ascii_digit()).collect()
}

fn paint_notif_card(n: &Value) -> String {
    let n_type = n.get("type").and_then(|v| v.as_str()).unwrap_or("mention");
    let account = n.get("account").cloned().unwrap_or(Value::Null);
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
    let created = n.get("created_at").and_then(|v| v.as_str()).unwrap_or("");
    let when = relative_time(created);
    let status = n.get("status").filter(|v| v.is_object());
    let status_uri = status
        .and_then(|st| {
            st.get("uri")
                .and_then(|v| v.as_str())
                .or_else(|| st.get("url").and_then(|v| v.as_str()))
        })
        .unwrap_or("")
        .trim_end_matches('/');
    let label = type_label(n_type, status_uri);

    let profile_href = if actor_ref.starts_with("https://") {
        format!(
            "?view=remote_profile&actor={}&from=mentions",
            urlencoding_encode(actor_ref)
        )
    } else {
        String::new()
    };

    let av_src = if avatar.starts_with("https://") {
        avatar
    } else {
        "https://mkultra.monster/img/avatar/default.jpg"
    };
    let av_img = format!(
        "<img class=\"tweet-av\" src=\"{}\" alt=\"\" width=\"40\" height=\"40\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\">",
        esc(av_src)
    );
    let av_block = if !profile_href.is_empty() {
        format!(
            "<a href=\"{}\" title=\"Open profile\" style=\"text-decoration:none\">{}</a>",
            esc(&profile_href),
            av_img
        )
    } else {
        av_img
    };
    let who = if !profile_href.is_empty() {
        format!(
            "<a class=\"who\" href=\"{h}\" style=\"color:inherit;text-decoration:none\">{d}</a><a class=\"meta\" href=\"{h}\" style=\"color:var(--muted);text-decoration:none\"> @{a}</a>",
            h = esc(&profile_href),
            d = esc(display),
            a = esc(acct)
        )
    } else {
        format!("<span class=\"who\">{}</span>", esc(acct))
    };
    let when_html = if when.is_empty() {
        String::new()
    } else {
        format!("<span class=\"meta\"> · {}</span>", esc(&when))
    };

    let mut body = String::new();

    // Phyrian Accept/Deny (no CSRF on these forms in PHP today).
    if n_type == "phyrian_imprint" || n_type == "phyrian_resonance" {
        let req_id = n
            .get("phyrian_request_id")
            .and_then(|v| v.as_i64())
            .unwrap_or(0);
        let kind = if n_type == "phyrian_imprint" {
            "imprint"
        } else {
            "resonance exchange"
        };
        body.push_str(&format!(
            "<div class=\"meta\" style=\"margin:.45rem 0 .65rem\">Pending Phyrian {} request. <a href=\"?view=phyrian\">Open Phyrian hub</a></div>",
            esc(kind)
        ));
        if req_id > 0 {
            body.push_str(&format!(
                "<div class=\"composer-actions\" style=\"justify-content:flex-start;gap:.5rem;flex-wrap:wrap\">\
                 <form method=\"post\" action=\"?view=mentions\" style=\"display:inline\">\
                 <input type=\"hidden\" name=\"action\" value=\"phyrian_accept\">\
                 <input type=\"hidden\" name=\"request_id\" value=\"{req_id}\">\
                 <input type=\"hidden\" name=\"return_view\" value=\"mentions\">\
                 <button class=\"btn btn-primary\" type=\"submit\">Accept</button></form>\
                 <form method=\"post\" action=\"?view=mentions\" style=\"display:inline\">\
                 <input type=\"hidden\" name=\"action\" value=\"phyrian_deny\">\
                 <input type=\"hidden\" name=\"request_id\" value=\"{req_id}\">\
                 <input type=\"hidden\" name=\"return_view\" value=\"mentions\">\
                 <button class=\"btn btn-ghost\" type=\"submit\">Deny</button></form></div>"
            ));
        }
    } else if let Some(st) = status {
        let hide_header = matches!(n_type, "mention" | "quote");
        // Prefer nest card for types that carry a status.
        if matches!(
            n_type,
            "mention" | "quote" | "favourite" | "reblog" | "update" | "poll" | "status" | "bite"
        ) && !status_uri.is_empty()
        {
            body.push_str(&paint_lean_embed(st, hide_header));
        } else {
            let plain = strip_tags(st.get("content").and_then(|v| v.as_str()).unwrap_or(""))
                .trim()
                .to_string();
            let mut snip = plain;
            if snip.chars().count() > 280 {
                snip = snip.chars().take(280).collect::<String>() + "…";
            }
            if !snip.is_empty() {
                body.push_str(&format!(
                    "<div class=\"body feed-body notification-post\">{}</div>",
                    esc(&snip)
                ));
            }
        }
    }

    let mut actions = String::new();
    if !profile_href.is_empty() {
        actions.push_str(&format!(
            "<a class=\"btn btn-ghost\" href=\"{}\" style=\"padding:.25rem .7rem;font-size:.8rem\">Profile</a>",
            esc(&profile_href)
        ));
    }
    if matches!(n_type, "mention" | "quote") && !status_uri.is_empty() {
        actions.push_str(&format!(
            "<a class=\"icon-btn\" href=\"?view=mentions&amp;compose=1&amp;reply_to={}&amp;to={}\" title=\"Reply\" aria-label=\"Reply\"><i class=\"ph ph-arrow-bend-up-left\" aria-hidden=\"true\"></i></a>",
            urlencoding_encode(status_uri),
            urlencoding_encode(actor_ref)
        ));
    }
    if !actions.is_empty() {
        body.push_str(&format!(
            "<div class=\"tweet-actions\">{actions}</div>"
        ));
    }

    format!(
        "<article class=\"tweet tweet-notif tweet-notif-{ty}\">\
         <div class=\"tweet-hd\">{av}<div class=\"tweet-hd-main\"><div>{who}{when}</div>\
         <div class=\"meta meta-row\" style=\"color:var(--primary)\">{label}</div></div></div>\
         {body}</article>",
        ty = esc(n_type),
        av = av_block,
        who = who,
        when = when_html,
        label = esc(&label),
        body = body
    )
}

/// Build Mentions fill HTML from the warm Redis list (404-equivalent via Result miss).
pub async fn mentions_html_fill(
    cfg: &Config,
    owner_user_id: i64,
    limit: i64,
    types: &[String],
    max_id: Option<&str>,
) -> Result<Option<MentionsHtmlReport>> {
    let limit = limit.clamp(1, 60);
    let mut report = notifications_shadow(cfg, owner_user_id, limit, types, max_id, None).await?;
    // notif-list warms all30/all40; Mentions UI asks for 12. On head miss, reuse a
    // larger warm envelope and slice — avoids falling back to PHP hydrate (~11s).
    if !report.cache_hit && max_id.is_none() {
        for alt in [30_i64, 40, 20] {
            if alt == limit {
                continue;
            }
            let alt_report =
                notifications_shadow(cfg, owner_user_id, alt, types, None, None).await?;
            if alt_report.cache_hit && !alt_report.items.is_empty() {
                report = alt_report;
                break;
            }
        }
    }
    if !report.cache_hit {
        return Ok(None);
    }
    if report.items.len() > limit as usize {
        report.items.truncate(limit as usize);
        report.count = report.items.len();
    }

    // Also write nest fragment cache while painting so PHP/Axum embed path stays warm.
    let mut redis = crate::redis_util::connect(&cfg.redis_url).await.ok();

    let mut html = String::with_capacity(report.items.len() * 800);
    let mut tip_id = String::new();
    let mut next_max_id = String::new();

    for item in &report.items {
        if !item.is_object() {
            continue;
        }
        let raw_id = item.get("id").and_then(|v| v.as_str()).unwrap_or("");
        let digits = digits_only(raw_id);
        if tip_id.is_empty()
            && !digits.is_empty()
            && !raw_id.starts_with("phyrian")
            && !item
                .get("type")
                .and_then(|v| v.as_str())
                .unwrap_or("")
                .starts_with("phyrian_")
        {
            tip_id = digits.clone();
        }
        if !digits.is_empty() && !raw_id.starts_with("phyrian") {
            next_max_id = digits;
        }

        // Warm nest fragment key when status present (hide_header for mention/quote).
        if let Some(st) = item.get("status").filter(|v| v.is_object()) {
            let n_type = item.get("type").and_then(|v| v.as_str()).unwrap_or("");
            let hide = matches!(n_type, "mention" | "quote");
            let uri = st
                .get("uri")
                .and_then(|v| v.as_str())
                .or_else(|| st.get("url").and_then(|v| v.as_str()))
                .unwrap_or("")
                .trim_end_matches('/');
            let sid = st.get("id").and_then(|v| v.as_str()).unwrap_or("");
            let fav = st
                .get("favourited")
                .and_then(|v| v.as_bool())
                .unwrap_or(false);
            let reblog = st
                .get("reblogged")
                .and_then(|v| v.as_bool())
                .unwrap_or(false);
            let bookmarked = st
                .get("bookmarked")
                .and_then(|v| v.as_bool())
                .unwrap_or(false);
            if !uri.is_empty() {
                let key =
                    notif_embed::frag_key(owner_user_id, uri, hide, fav, reblog, bookmarked, sid);
                let frag = paint_lean_embed(st, hide);
                if let Some(ref mut r) = redis {
                    let _ = notif_embed::set_embed_html(r, &key, &frag).await;
                }
            }
        }

        html.push_str(&paint_notif_card(item));
    }

    let has_more = report.count as i64 >= limit && !next_max_id.is_empty();
    Ok(Some(MentionsHtmlReport {
        html,
        next_max_id,
        has_more,
        tip_id,
        count: report.count,
        source: if report.source.is_empty() {
            "axum-mentions-html".into()
        } else {
            format!("axum-mentions-html:{}", report.source)
        },
        redis_key: report.redis_key,
    }))
}

