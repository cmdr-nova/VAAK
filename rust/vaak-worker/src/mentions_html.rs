//! Mentions fill HTML from warm Redis notification envelopes (0.7.1+).
//!
//! Serves the soft-nav / hard-shell fill partial without PHP
//! `admin_render_notification_stream` (~11s p50 on prod). Nested cards reuse
//! `notif_embed::paint_lean_embed` in-process (no per-card HTTP).
//! 0.7.3: like/boost avatar-stack grouping (presentation-only).
//! Scroll reuses the warm head past `max_id`. A short tail stays a miss so
//! PHP can read older rows the head does not hold.

use std::collections::HashSet;

use anyhow::Result;
use serde_json::Value;

use crate::config::Config;
use crate::notif_embed::{self, paint_lean_embed_from};


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

fn status_uri_of(n: &Value) -> String {
    n.get("status")
        .and_then(|st| {
            st.get("uri")
                .and_then(|v| v.as_str())
                .or_else(|| st.get("url").and_then(|v| v.as_str()))
        })
        .unwrap_or("")
        .trim()
        .trim_end_matches('/')
        .to_string()
}

/// Presentation group key for like/boost stacks (parity with PHP
/// `admin_notification_group_key`). Twin Fedi/Bluesky URI canonicalization
/// is best-effort on the trimmed uri/url already present in the envelope.
fn notification_group_key(row: &Value) -> Option<String> {
    let n_type = row.get("type").and_then(|v| v.as_str()).unwrap_or("");
    if n_type != "favourite" && n_type != "reblog" {
        return None;
    }
    let status_key = status_uri_of(row);
    if status_key.is_empty() {
        return None;
    }
    Some(format!("{n_type}:{status_key}"))
}

fn account_identity_key(acct: &Value) -> String {
    let ref_uri = acct
        .get("uri")
        .and_then(|v| v.as_str())
        .or_else(|| acct.get("url").and_then(|v| v.as_str()))
        .unwrap_or("")
        .trim()
        .trim_end_matches('/');
    if ref_uri.is_empty() {
        if let Some(id) = acct.get("id").and_then(|v| v.as_str()) {
            return format!("id:{id}");
        }
        return "anon".into();
    }
    // Local VAAK actors collapse by username path.
    if let Some(rest) = ref_uri.strip_prefix("https://mkultra.monster/users/") {
        let user = rest.split('/').next().unwrap_or(rest);
        return format!("vaak:{}", user.to_ascii_lowercase());
    }
    if let Some(rest) = ref_uri.strip_prefix("https://mkultra.monster/@") {
        let user = rest.split('/').next().unwrap_or(rest);
        return format!("vaak:{}", user.to_ascii_lowercase());
    }
    // Bluesky profile / DID — same handle/DID share a key (no DB twin lookup).
    if let Some(rest) = ref_uri.strip_prefix("https://bsky.app/profile/") {
        let handle = rest.split('/').next().unwrap_or(rest);
        return format!("bsky:{}", handle.to_ascii_lowercase());
    }
    if ref_uri.starts_with("did:") {
        return format!("bsky:{}", ref_uri.to_ascii_lowercase());
    }
    ref_uri.to_ascii_lowercase()
}

fn account_is_local(acct: &Value) -> bool {
    let ref_uri = acct
        .get("uri")
        .and_then(|v| v.as_str())
        .or_else(|| acct.get("url").and_then(|v| v.as_str()))
        .unwrap_or("");
    ref_uri.starts_with("https://mkultra.monster/users/")
        || ref_uri.starts_with("https://mkultra.monster/@")
}

struct GroupedNotif {
    row: Value,
    count: usize,
    accounts: Vec<Value>,
}

/// Collapse repeated favourite/reblog rows for the same status. Presentation
/// only — does not change unread IDs or pagination tips (computed on raw items).
#[cfg(test)]
fn group_notification_rows(rows: &[Value]) -> Vec<GroupedNotif> {
    group_notification_rows_with_facts(rows, &GroupingFacts::default())
}

fn group_notification_rows_with_facts(rows: &[Value], facts: &GroupingFacts) -> Vec<GroupedNotif> {
    use std::collections::HashMap;
    let mut out: Vec<GroupedNotif> = Vec::new();
    let mut index: HashMap<String, usize> = HashMap::new();
    let mut seen_idents: HashMap<usize, HashMap<String, bool>> = HashMap::new();

    for row in rows {
        if !row.is_object() {
            continue;
        }
        let acct = row.get("account").cloned().unwrap_or(Value::Null);
        let ident = if acct.is_object() {
            resolved_identity(&acct, facts)
        } else {
            format!("anon:{}", out.len())
        };
        let group_key = resolved_group_key(row, facts);
        let map_key = group_key
            .clone()
            .unwrap_or_else(|| format!("single:{}", row.get("id").and_then(|v| v.as_str()).unwrap_or("x")));

        if group_key.is_none() || !index.contains_key(&map_key) {
            let mut accounts = Vec::new();
            if acct.is_object() {
                accounts.push(acct);
            }
            let idx = out.len();
            index.insert(map_key, idx);
            let mut idents = HashMap::new();
            idents.insert(ident, true);
            seen_idents.insert(idx, idents);
            out.push(GroupedNotif {
                row: row.clone(),
                count: 1,
                accounts,
            });
            continue;
        }
        let idx = *index.get(&map_key).expect("index");
        let idents = seen_idents.entry(idx).or_default();
        if !idents.contains_key(&ident) {
            out[idx].count += 1;
            idents.insert(ident, true);
            if acct.is_object() && out[idx].accounts.len() < 6 {
                out[idx].accounts.push(acct);
            }
        } else if acct.is_object() && account_is_local(&acct) {
            // Prefer local VAAK face over Bluesky twin for the same identity.
            for existing in out[idx].accounts.iter_mut() {
                if resolved_identity(existing, facts) == ident && !account_is_local(existing) {
                    *existing = acct.clone();
                    if let Some(obj) = out[idx].row.as_object_mut() {
                        obj.insert("account".into(), acct);
                    }
                    break;
                }
            }
        }
    }
    out
}

fn hover_attr_for_account(acct: &Value) -> String {
    let uri = acct.get("uri").and_then(|v| v.as_str()).unwrap_or("");
    let url = acct.get("url").and_then(|v| v.as_str()).unwrap_or("");
    let actor = follow_target(uri, url);
    if actor.starts_with("https://") {
        format!(" data-profile-hover-actor=\"{}\"", esc(&actor))
    } else {
        String::new()
    }
}

fn avatar_img_html(acct: &Value) -> String {
    let avatar = acct
        .get("avatar")
        .and_then(|v| v.as_str())
        .or_else(|| acct.get("avatar_static").and_then(|v| v.as_str()))
        .unwrap_or("");
    let av_src = if avatar.starts_with("https://") {
        avatar
    } else {
        "https://mkultra.monster/img/avatar/default.webp"
    };
    format!(
        "<img class=\"tweet-av\" src=\"{}\" alt=\"\" width=\"40\" height=\"40\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\"{} onerror=\"this.onerror=null;this.src='https://mkultra.monster/img/avatar/default.webp'\">",
        esc(av_src),
        hover_attr_for_account(acct)
    )
}

fn profile_href_for(acct: &Value) -> String {
    let actor_ref = acct
        .get("uri")
        .and_then(|v| v.as_str())
        .or_else(|| acct.get("url").and_then(|v| v.as_str()))
        .unwrap_or("");
    if actor_ref.starts_with("https://") {
        format!(
            "?view=remote_profile&actor={}&from=mentions",
            urlencoding_encode(actor_ref)
        )
    } else {
        String::new()
    }
}

fn paint_grouped_card(g: &GroupedNotif, viewer_actor: &str) -> String {
    let n_type = g.row.get("type").and_then(|v| v.as_str()).unwrap_or("favourite");
    let label = if n_type == "reblog" {
        "boosted your post"
    } else {
        "liked your post"
    };
    let created = g.row.get("created_at").and_then(|v| v.as_str()).unwrap_or("");
    let when = relative_time(created);
    let mut stack = String::from(
        "<div class=\"notification-avatar-stack\" style=\"display:flex;align-items:center;min-width:3rem\">",
    );
    for acct in g.accounts.iter().take(6) {
        let href = profile_href_for(acct);
        let img = avatar_img_html(acct);
        if href.is_empty() {
            stack.push_str(&img);
        } else {
            stack.push_str(&format!(
                "<a href=\"{}\" aria-label=\"Open profile\" style=\"margin-right:-.35rem\">{}</a>",
                esc(&href),
                img
            ));
        }
    }
    stack.push_str("</div>");

    let mut body = String::new();
    if let Some(st) = g.row.get("status").filter(|v| v.is_object()) {
        let uri = status_uri_of(&g.row);
        if !uri.is_empty() {
            body.push_str(&paint_lean_embed_from(st, false, "mentions", viewer_actor, true));
        }
    }

    format!(
        "<article class=\"tweet tweet-notif tweet-notif-grouped\">\
         <div class=\"tweet-hd\" style=\"align-items:center\">{stack}\
         <div class=\"tweet-hd-main\"><div class=\"meta\" style=\"color:var(--primary)\">{count} people {label}</div>\
         <div class=\"meta\">{when}</div></div></div>{body}</article>",
        stack = stack,
        count = g.count,
        label = esc(label),
        when = esc(&when),
        body = body
    )
}

fn follow_key(raw: &str) -> String {
    raw.trim().trim_end_matches('/').to_ascii_lowercase()
}

fn percent_decode(s: &str) -> String {
    let bytes = s.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] == b'%' && i + 2 < bytes.len() {
            if let Ok(v) = u8::from_str_radix(
                std::str::from_utf8(&bytes[i + 1..i + 3]).unwrap_or(""),
                16,
            ) {
                out.push(v);
                i += 3;
                continue;
            }
        }
        out.push(bytes[i]);
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// `https://host/@user` web profile, with nothing after the handle.
fn is_web_at_profile(url: &str) -> bool {
    let key = url.trim().trim_end_matches('/');
    let Some(rest) = key.strip_prefix("https://") else {
        return false;
    };
    let Some((_, path)) = rest.split_once('/') else {
        return false;
    };
    let Some(user) = path.strip_prefix('@') else {
        return false;
    };
    !user.is_empty() && !user.contains('/')
}

/// PHP `admin_account_actor_ref`: prefer the ActivityPub actor, and turn a
/// `/@user` web URL into `/users/user` before posting follow_remote.
fn follow_target(uri: &str, url: &str) -> String {
    let uri = uri.trim().trim_end_matches('/');
    let url = url.trim().trim_end_matches('/');
    if uri.starts_with("https://") && !is_web_at_profile(uri) {
        return uri.to_string();
    }
    if url.starts_with("https://") {
        if let Some(users) = at_profile_to_users(url) {
            return users;
        }
        return url.to_string();
    }
    if let Some(users) = at_profile_to_users(uri) {
        return users;
    }
    if !uri.is_empty() {
        uri.to_string()
    } else {
        url.to_string()
    }
}

fn at_profile_to_users(url: &str) -> Option<String> {
    if !is_web_at_profile(url) {
        return None;
    }
    let key = url.trim().trim_end_matches('/');
    let rest = key.strip_prefix("https://")?;
    let (host, path) = rest.split_once('/')?;
    let user = percent_decode(path.strip_prefix('@')?);
    if host.is_empty() || user.is_empty() || user.contains('/') {
        return None;
    }
    Some(format!("https://{host}/users/{}", urlencoding_encode(&user)))
}

fn follow_aliases(key: &str) -> Vec<String> {
    let mut out = Vec::new();
    if let Some(did) = key.strip_prefix("did:") {
        if !did.is_empty() && !did.contains('/') {
            out.push(format!("https://bsky.app/profile/{key}"));
        }
    }
    let Some(rest) = key.strip_prefix("https://") else {
        return out;
    };
    let Some((host, path)) = rest.split_once('/') else {
        return out;
    };
    if host == "bsky.app" {
        if let Some(ident) = path.strip_prefix("profile/") {
            let ident = percent_decode(ident);
            if ident.starts_with("did:") && !ident.contains('/') {
                out.push(ident);
            }
        }
    }
    let user = if let Some(user) = path.strip_prefix('@') {
        Some(percent_decode(user))
    } else if let Some(user) = path.strip_prefix("users/") {
        Some(percent_decode(user))
    } else {
        None
    };
    if let Some(user) = user {
        // Numeric segments are Mastodon snowflakes, not handles.
        if !user.is_empty()
            && !user.contains('/')
            && !user.chars().all(|c| c.is_ascii_digit())
        {
            let user = urlencoding_encode(&user).to_ascii_lowercase();
            if path.starts_with('@') {
                out.push(format!("https://{host}/users/{user}"));
            } else {
                out.push(format!("https://{host}/@{user}"));
            }
        }
    }
    out
}

fn remember_follow(set: &mut HashSet<String>, raw: &str) {
    let key = follow_key(raw);
    if key.is_empty() {
        return;
    }
    let aliases = follow_aliases(&key);
    set.insert(key);
    for alias in aliases {
        set.insert(alias);
    }
}

fn already_following(set: &HashSet<String>, actor: &str) -> bool {
    let key = follow_key(actor);
    if key.is_empty() {
        return false;
    }
    if set.contains(&key) {
        return true;
    }
    follow_aliases(&key).iter().any(|alias| set.contains(alias))
}

fn same_actor(a: &str, b: &str) -> bool {
    let a = follow_key(a);
    let b = follow_key(b);
    if a.is_empty() || b.is_empty() {
        return false;
    }
    a == b || follow_aliases(&a).iter().any(|alias| alias == &b)
}

/// Actor ids this viewer already follows. Empty on a database miss so the
/// Follow back button still renders; a repeat follow is a no-op server-side.
async fn load_viewer_follows(
    cfg: &Config,
    owner_user_id: i64,
    viewer_actor: &str,
) -> HashSet<String> {
    let mut set = HashSet::new();
    let Ok(db) = crate::db::connect(&cfg.database_url).await else {
        return set;
    };
    let owner = viewer_actor.trim().trim_end_matches('/').to_string();
    if owner.starts_with("https://") {
        let owner_slash = format!("{owner}/");
        if let Ok(rows) = db
            .query(
                "SELECT actor_id FROM following WHERE owner_actor_id = $1 OR owner_actor_id = $2",
                &[&owner, &owner_slash],
            )
            .await
        {
            for row in rows {
                let aid: String = row
                    .try_get::<_, Option<String>>(0)
                    .ok()
                    .flatten()
                    .unwrap_or_default();
                remember_follow(&mut set, &aid);
            }
        }
    }
    if owner_user_id > 0 {
        if let Ok(rows) = db
            .query(
                "SELECT target_did FROM bsky_graph_sync WHERE owner_user_id = $1 AND kind = 'follow'",
                &[&owner_user_id],
            )
            .await
        {
            for row in rows {
                let did: String = row
                    .try_get::<_, Option<String>>(0)
                    .ok()
                    .flatten()
                    .unwrap_or_default();
                remember_follow(&mut set, did.trim());
            }
        }
        // Handle URLs are optional. A missing table must not hide Follow back.
        if let Ok(rows) = db
            .query(
                "SELECT actor_ref FROM bsky_actor_viewers WHERE owner_user_id = $1 AND COALESCE(following_uri, '') <> ''",
                &[&owner_user_id],
            )
            .await
        {
            for row in rows {
                let actor_ref: String = row
                    .try_get::<_, Option<String>>(0)
                    .ok()
                    .flatten()
                    .unwrap_or_default();
                remember_follow(&mut set, &actor_ref);
            }
        }
    }
    set
}

fn paint_notif_card(n: &Value, viewer_actor: &str) -> String {
    paint_notif_card_follows(n, viewer_actor, &HashSet::new())
}

fn paint_notif_card_follows(n: &Value, viewer_actor: &str, following: &HashSet<String>) -> String {
    let n_type = n.get("type").and_then(|v| v.as_str()).unwrap_or("mention");
    let account = n.get("account").cloned().unwrap_or(Value::Null);
    let mut account = account;
    if account_is_local(&account) {
        let acct = account.get("acct").and_then(Value::as_str).unwrap_or("");
        if !acct.is_empty() && !acct.contains('@') { account["acct"] = Value::from(format!("{acct}@mkultra.monster")); }
    }
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
    let account_uri = account.get("uri").and_then(|v| v.as_str()).unwrap_or("");
    let account_url = account.get("url").and_then(|v| v.as_str()).unwrap_or("");
    let actor_ref = if !account_uri.is_empty() {
        account_uri
    } else {
        account_url
    };
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
        "https://mkultra.monster/img/avatar/default.webp"
    };
    let hover = hover_attr_for_account(&account);
    let av_img = format!(
        "<img class=\"tweet-av\" src=\"{}\" alt=\"\" width=\"40\" height=\"40\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\"{} onerror=\"this.onerror=null;this.src='https://mkultra.monster/img/avatar/default.webp'\">",
        esc(av_src),
        hover
    );
    let av_block = if !profile_href.is_empty() {
        format!(
            "<a href=\"{}\" aria-label=\"Open profile\" style=\"text-decoration:none\"{hover}>{}</a>",
            esc(&profile_href),
            av_img
        )
    } else {
        av_img
    };
    let who = if !profile_href.is_empty() {
        format!(
            "<a class=\"who\" href=\"{h}\" style=\"color:inherit;text-decoration:none\"{hover}>{d}</a><a class=\"meta\" href=\"{h}\" style=\"color:var(--muted);text-decoration:none\"{hover}> @{a}</a>",
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
            body.push_str(&paint_lean_embed_from(st, hide_header, "mentions", viewer_actor, true));
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
    // New follower rows: follow them back without leaving Notifications.
    // Same queued follow_remote form as the PHP Mentions fallback (Fediverse and Bluesky).
    if n_type == "follow" {
        let actor = follow_target(account_uri, account_url);
        let viewer = viewer_actor.trim().trim_end_matches('/');
        if actor.starts_with("https://")
            && !same_actor(&actor, viewer)
            && !already_following(following, &actor)
        {
            actions.push_str(&format!(
                "<form method=\"post\" action=\"?view=mentions\" style=\"display:inline\">\
                 <input type=\"hidden\" name=\"action\" value=\"follow_remote\">\
                 <input type=\"hidden\" name=\"return_view\" value=\"mentions\">\
                 <input type=\"hidden\" name=\"actor_id\" value=\"{actor}\">\
                 <button class=\"btn btn-ghost\" type=\"submit\" style=\"padding:.25rem .7rem;font-size:.8rem\">Follow back</button></form>",
                actor = esc(&actor),
            ));
        }
    }
    // Status notifications paint their complete interaction bar inside the
    // shared lean embed above. Keeping a second reply-only bar here caused
    // Rust Notifications to lose quote/boost/favourite/bookmark parity and
    // produced duplicate reply buttons when the embed was present.
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

// HTML-only page facts. JSON notification clients retain notif_list's resolver.
struct HtmlPage {
    items: Vec<Value>,
    primary_count: usize,
    next_max_id: String,
    source: String,
    redis_key: String,
}

fn raw_page(payload: Value, key: String, source_suffix: &str, limit: usize, cursor: Option<&str>, from_head: bool) -> Result<Option<HtmlPage>> {
    let Some(raw) = payload.get("items").and_then(Value::as_array) else { return Ok(None); };
    // Malformed envelopes are not authoritative quiet-account pages.
    if raw.iter().any(|n| !n.is_object() || n.get("id").and_then(Value::as_str).is_none()) { return Ok(None); }
    let mut items = if from_head {
        if let Some(cursor) = cursor {
            let Some(tail) = crate::notif_list::full_page_after_cursor(raw, &digits_only(cursor), limit as i64) else { return Ok(None); };
            tail
        } else {
            if raw.len() < limit { return Ok(None); }
            raw.clone()
        }
    } else { raw.clone() };
    items.truncate(limit);
    let primary_count = items.len();
    let next_max_id = items.iter().rev().find_map(|n| {
        let raw = n.get("id")?.as_str()?;
        let id = digits_only(raw);
        (!raw.starts_with("phyrian") && !id.is_empty()).then_some(id)
    }).unwrap_or_default();
    let source = payload.get("source").and_then(Value::as_str).filter(|s| !s.is_empty()).unwrap_or("redis");
    Ok(Some(HtmlPage { items, primary_count, next_max_id, source: format!("{source}:{source_suffix}"), redis_key: key }))
}

async fn html_page_selected(cfg: &Config, owner: i64, limit: i64, types: &[String], max_id: Option<&str>) -> Result<Option<HtmlPage>> {
    let mut redis = crate::redis_util::connect(&cfg.redis_url).await?;
    let key = crate::notif_list::notifications_list_redis_key(owner, limit, max_id, None, types);
    if let Some(payload) = crate::redis_util::json_get(&mut redis, &key).await? {
        return raw_page(payload, key, "exact-raw", limit as usize, max_id, false);
    }
    // Select source consumption before policy admission. Never refill a selected
    // raw page from older visible rows after a notifier becomes hidden.
    for alt in crate::notif_list::warm_head_limits(limit) {
        if max_id.is_none() && alt == limit { continue; }
        let key = crate::notif_list::notifications_list_redis_key(owner, alt, None, None, types);
        if let Some(payload) = crate::redis_util::json_get(&mut redis, &key).await? {
            if let Some(page) = raw_page(payload, key, if max_id.is_some() { "max-raw" } else { "head-raw" }, limit as usize, max_id, true)? { return Ok(Some(page)); }
        }
    }
    Ok(None)
}

#[derive(Default)]
struct GroupingFacts {
    identities: std::collections::HashMap<String, String>,
    statuses: std::collections::HashMap<String, String>,
}

fn account_ref(acct: &Value) -> String {
    acct.get("uri").and_then(Value::as_str).or_else(|| acct.get("url").and_then(Value::as_str)).unwrap_or("").trim().trim_end_matches('/').to_string()
}
fn resolved_identity(acct: &Value, facts: &GroupingFacts) -> String {
    facts.identities.get(&account_ref(acct)).cloned().unwrap_or_else(|| account_identity_key(acct))
}
fn resolved_group_key(n: &Value, facts: &GroupingFacts) -> Option<String> {
    let kind = n.get("type").and_then(Value::as_str).unwrap_or("");
    if !matches!(kind, "favourite" | "reblog") { return None; }
    let uri = status_uri_of(n);
    if uri.is_empty() { return None; }
    Some(format!("{kind}:{}", facts.statuses.get(&uri).unwrap_or(&uri)))
}
fn unique_fact(map: &mut std::collections::HashMap<String, String>, key: String, value: String) -> Result<()> {
    if let Some(old) = map.insert(key, value.clone()) { anyhow::ensure!(old == value, "ambiguous notification grouping fact"); }
    Ok(())
}
fn bsky_profile_ref(raw: &str) -> Option<String> {
    if raw.starts_with("did:") { return Some(raw.to_string()); }
    raw.strip_prefix("https://bsky.app/profile/").map(|s| percent_decode(s.split('/').next().unwrap_or(s)))
}
async fn grouping_facts(db: &tokio_postgres::Client, items: &[Value]) -> Result<GroupingFacts> {
    let mut facts = GroupingFacts::default();
    let refs: Vec<String> = items.iter().filter_map(|n| n.get("account").map(account_ref)).collect();
    let local: Vec<String> = refs.iter().filter(|s| s.starts_with("https://mkultra.monster/users/")).cloned().collect();
    if !local.is_empty() {
        for row in db.query("SELECT rtrim(actor_id, '/'), id::text FROM ap_users WHERE rtrim(actor_id, '/') = ANY($1)", &[&local]).await? {
            unique_fact(&mut facts.identities, row.get(0), format!("vaak:{}", row.get::<_, String>(1)))?;
        }
    }
    let bsky: Vec<String> = refs.iter().filter_map(|s| bsky_profile_ref(s)).collect();
    if !bsky.is_empty() {
        let handles: Vec<String> = bsky.iter().map(|s| s.to_ascii_lowercase()).collect();
        let rows = db.query("SELECT owner_user_id::text, did, lower(handle) FROM bsky_sessions WHERE did = ANY($1) OR lower(handle) = ANY($2)", &[&bsky, &handles]).await?;
        for reference in &refs {
            let Some(probe) = bsky_profile_ref(reference) else { continue; };
            for row in &rows {
                let did: String = row.get(1); let handle: String = row.get(2);
                if (probe.starts_with("did:") && probe == did) || (!probe.starts_with("did:") && probe.to_ascii_lowercase() == handle) {
                    unique_fact(&mut facts.identities, reference.clone(), format!("vaak:{}", row.get::<_, String>(0)))?;
                }
            }
        }
    }
    let mut probes = std::collections::HashMap::<String, String>::new();
    for item in items {
        if notification_group_key(item).is_none() { continue; }
        let uri = status_uri_of(item);
        if uri.starts_with("at://did:") { probes.insert(uri.clone(), uri); }
        else if let Some(rest) = uri.strip_prefix("https://bsky.app/profile/") {
            let fields: Vec<_> = rest.split('/').collect();
            // Handle-to-DID resolution may require producer/network facts not in
            // this bounded renderer. Preserve whole PHP recovery for that case.
            anyhow::ensure!(fields.len() == 3 && fields[0].starts_with("did:") && fields[1] == "post", "unsupported notification status grouping alias");
            let at = format!("at://{}/app.bsky.feed.post/{}", fields[0], fields[2]);
            probes.insert(uri, at);
        } else if uri.starts_with("at://") {
            anyhow::bail!("unsupported notification status grouping repo");
        }
    }
    if !probes.is_empty() {
        let ats: Vec<String> = probes.values().cloned().collect();
        let cross = db.query("SELECT bsky_uri, note_id FROM bsky_crossposts WHERE bsky_uri = ANY($1)", &[&ats]).await?;
        let links = db.query("SELECT bsky_uri, COALESCE(fediverse_id, ap_object_id, '') FROM bsky_post_links WHERE bsky_uri = ANY($1)", &[&ats]).await?;
        let mut mapped = std::collections::HashMap::new();
        for row in cross { let note: String = row.get(1); if note.starts_with("https://") { unique_fact(&mut mapped, row.get(0), note.trim_end_matches('/').to_string())?; } }
        for row in links {
            let at: String = row.get(0); let note: String = row.get(1);
            if !mapped.contains_key(&at) && note.starts_with("https://") && note.contains("/notes/") { unique_fact(&mut mapped, at, note.trim_end_matches('/').to_string())?; }
        }
        for (uri, at) in probes { if let Some(note) = mapped.get(&at) { facts.statuses.insert(uri, note.clone()); } }
    }
    Ok(facts)
}

async fn active_viewer_actor(db: &tokio_postgres::Client, owner: i64) -> Result<String> {
    let row = db.query_opt("SELECT actor_id FROM ap_users WHERE id::bigint=$1 AND disabled_at IS NULL", &[&owner]).await?.ok_or_else(|| anyhow::anyhow!("inactive notification owner"))?;
    let actor: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
    anyhow::ensure!(actor.starts_with("https://"), "notification owner actor missing");
    Ok(actor.trim_end_matches('/').to_string())
}

async fn require_active_owner(db: &tokio_postgres::Client, owner: i64) -> Result<()> {
    let active: bool = db.query_one("SELECT EXISTS(SELECT 1 FROM ap_users WHERE id::bigint=$1 AND disabled_at IS NULL)", &[&owner]).await?.get(0);
    anyhow::ensure!(active, "notification owner no longer active");
    Ok(())
}

// Canonical grouping never grants visibility to an original or mapped source.
// Recheck both source forms after all other awaited enrichment/cache writes.
async fn final_page_admission(db: &tokio_postgres::Client, owner: i64, items: &mut Vec<Value>, facts: &GroupingFacts) -> Result<()> {
    require_active_owner(db, owner).await?;
    let mut checks = Vec::new();
    let mut required = vec![1_usize; items.len()];
    for (index, item) in items.iter().enumerate() {
        let mut original = item.clone(); original["_html_source_index"] = Value::from(index); original["_html_original"] = Value::Bool(true); checks.push(original);
        let uri = status_uri_of(item);
        if let Some(canonical) = facts.statuses.get(&uri).filter(|key| key.as_str() != uri.as_str()) {
            let mut canonical_item = item.clone(); canonical_item["_html_source_index"] = Value::from(index); canonical_item["_html_original"] = Value::Bool(false);
            if let Some(status) = canonical_item.get_mut("status") { status["uri"] = Value::from(canonical.clone()); status["url"] = Value::from(canonical.clone()); }
            checks.push(canonical_item); required[index] += 1;
        }
    }
    crate::audience::filter_notifications(db, owner, &mut checks).await?;
    let mut granted = vec![0_usize; items.len()];
    let mut refreshed = std::collections::HashMap::new();
    for mut item in checks {
        let index = item["_html_source_index"].as_u64().ok_or_else(|| anyhow::anyhow!("notification source index lost"))? as usize;
        anyhow::ensure!(index < items.len(), "notification source index changed");
        item.as_object_mut().ok_or_else(|| anyhow::anyhow!("invalid admitted notification"))?.remove("_html_source_index");
        granted[index] += 1;
        let original = item.get("_html_original").and_then(Value::as_bool) == Some(true);
        item.as_object_mut().unwrap().remove("_html_original");
        if original { refreshed.insert(index, item); }
    }
    *items = (0..items.len()).filter(|index| granted[*index] == required[*index]).filter_map(|index| refreshed.remove(&index)).collect();
    Ok(())
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
    let Some(mut report) = html_page_selected(cfg, owner_user_id, limit, types, max_id).await? else { return Ok(None); };
    let policy_db = crate::db::connect(&cfg.database_url).await?;
    require_active_owner(&policy_db, owner_user_id).await?;
    // A pending first-All Phyrian offer still needs the actual PHP producer.
    if max_id.is_none() && types.is_empty() {
        let pending: bool = policy_db.query_one("SELECT EXISTS(SELECT 1 FROM phyrian_requests WHERE to_owner_id::bigint=$1 AND status='pending')", &[&owner_user_id]).await?.get(0);
        if pending { return Ok(None); }
    }
    crate::audience::filter_notifications(&policy_db, owner_user_id, &mut report.items).await?;

    // Replies and quote-boosts need the signed-in actor or the ⋯ menu is omitted.
    let viewer_actor = active_viewer_actor(&policy_db, owner_user_id).await?;
    if let Ok(db) = crate::db::connect(&cfg.database_url).await {
        if let Ok(moderation) = crate::hidden::load_viewer_moderation(&db, owner_user_id).await {
            for item in report.items.iter_mut() {
                if let Some(st) = item.get_mut("status") {
                    notif_embed::stamp_viewer_moderation_tree(st, &moderation);
                }
            }
        }
        // Notification envelopes do not carry this viewer's fav/boost/bookmark.
        let mut nested = Vec::new();
        let mut nested_at = Vec::new();
        for (idx, item) in report.items.iter().enumerate() {
            if let Some(st) = item.get("status").filter(|v| v.is_object()) {
                nested.push(st.clone());
                nested_at.push(idx);
            }
        }
        if !nested.is_empty() {
            let _ = crate::interaction_flags::apply_to_statuses(
                &db,
                owner_user_id,
                &mut nested,
            )
            .await;
            let _ = crate::home_hydrate_ranked::attach_bsky_link_facets(&db, &mut nested).await;
            let _ = crate::home_hydrate_ranked::attach_ask_identities(&db, &mut nested).await;
            for (idx, st) in nested_at.into_iter().zip(nested) {
                if let Some(slot) = report.items[idx].get_mut("status") {
                    *slot = st;
                }
            }
        }
    }

    // Also write nest fragment cache while painting so PHP/Axum embed path stays warm.
    let mut redis = crate::redis_util::connect(&cfg.redis_url).await.ok();

    let mut html = String::with_capacity(report.items.len() * 800);
    let next_max_id = report.next_max_id.clone();

    // Fragment warming uses only initially admitted rows. The response is
    // granted again below after the awaited writes; raw consumption is separate.
    for item in &report.items {
        if !item.is_object() {
            continue;
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
                let frag = paint_lean_embed_from(st, hide, "mentions", &viewer_actor, true);
                if let Some(ref mut r) = redis {
                    let _ = notif_embed::set_embed_html(r, &key, &frag).await;
                }
            }
        }
    }

    // All enrichment and Redis writes have finished. Facts are presentation-only;
    // the last awaited operation grants both original and canonical sources.
    let following = load_viewer_follows(cfg, owner_user_id, &viewer_actor).await;
    anyhow::ensure!(active_viewer_actor(&policy_db, owner_user_id).await? == viewer_actor, "notification owner actor changed");
    let facts = grouping_facts(&policy_db, &report.items).await?;
    final_page_admission(&policy_db, owner_user_id, &mut report.items, &facts).await?;
    let tip_id = report.items.iter().find_map(|item| {
        let raw = item.get("id")?.as_str()?;
        let kind = item.get("type").and_then(Value::as_str).unwrap_or("");
        let digits = digits_only(raw);
        (!raw.starts_with("phyrian") && !kind.starts_with("phyrian_") && !digits.is_empty()).then_some(digits)
    }).unwrap_or_default();

    let grouped_rows = group_notification_rows_with_facts(&report.items, &facts);
    // The current grouped embed uses the lean card painter. Genuine PHP
    // captures require CSRF-bearing action forms and the local delete row ID.
    // Recover this whole fragment until that complete card contract is native.
    if grouped_rows.iter().any(|grouped| grouped.count > 1 && grouped.row.get("status").is_some_and(Value::is_object)) {
        return Ok(None);
    }
    // Like/boost avatar-stack grouping (0.7.3) — parity with PHP stream.
    for grouped in grouped_rows {
        if grouped.count > 1 {
            html.push_str(&paint_grouped_card(&grouped, &viewer_actor));
        } else {
            html.push_str(&paint_notif_card_follows(
                &grouped.row,
                &viewer_actor,
                &following,
            ));
        }
    }

    let has_more = report.primary_count as i64 >= limit && !next_max_id.is_empty();
    Ok(Some(MentionsHtmlReport {
        html,
        next_max_id,
        has_more,
        tip_id,
        count: report.items.len(),
        source: if report.source.is_empty() {
            "axum-mentions-html".into()
        } else {
            format!("axum-mentions-html:{}", report.source)
        },
        redis_key: report.redis_key,
    }))
}

#[cfg(test)]
#[path = "mentions_html_tests.rs"]
mod tests;
