//! Local profile chrome. PHP still loads the profile and performs every write.
//! This painter only repeats the header, tabs, relationship row, and the empty
//! Pins or Asks body. It does not open a database or a socket.

use axum::body::Bytes;
use axum::http::{HeaderMap, StatusCode};
use axum::response::{IntoResponse, Response};
use serde_json::{json, Value};

use crate::library::actions::{esc, query};

const FALLBACK_AVATAR: &str = "https://mkultra.monster/img/avatar/default.webp";

struct W {
    out: String,
    pending: String,
}

impl W {
    fn new() -> Self { Self { out: String::new(), pending: String::new() } }
    fn indent(&mut self, n: usize) { self.pending.extend(std::iter::repeat_n(' ', n)); }
    fn text(&mut self, value: &str) {
        if !self.pending.is_empty() {
            self.out.push_str(&self.pending);
            self.pending.clear();
        }
        self.out.push_str(value);
    }
    fn line(&mut self, n: usize, value: &str) {
        self.indent(n);
        self.text(value);
        self.out.push('\n');
    }
    fn finish(mut self) -> String {
        if !self.pending.is_empty() { self.out.push_str(&self.pending); }
        self.out
    }
}

#[derive(Clone)]
struct Field {
    name: String,
    value: String,
    verified: String,
}

struct Facts {
    actor: String,
    key: String,
    from: String,
    tab: String,
    tab_href: Option<String>,
    display_name: String,
    display_present: bool,
    username: String,
    bio: String,
    avatar: Option<String>,
    banner: Option<String>,
    fallback_avatar: String,
    bare_src: Option<String>,
    emoji: Vec<(String, String)>,
    display_html: Option<String>,
    is_own: bool,
    following: bool,
    follows_you: bool,
    requested: bool,
    locked: bool,
    muted: bool,
    blocked_personal: bool,
    blocked_server: bool,
    subscribed: bool,
    personal_block_id: Option<i64>,
    deprioritized: bool,
    target_is_admin: bool,
    viewer_is_admin: bool,
    csrf: String,
    bridge_name: String,
    bridge_strain: String,
    sl_name: String,
    sl_agent: String,
    wow_name: String,
    wow_realm: String,
    wow_url: String,
    wow_portrait: String,
    fields: Vec<Field>,
    badges: Vec<String>,
    stats_bsky: bool,
    posts_stat: i64,
    following_stat: i64,
    followers_stat: i64,
    item_posts: i64,
    item_replies: i64,
    item_boosts: i64,
    item_media: i64,
    item_asks: i64,
    outbox_total: i64,
    replies_total: i64,
    boost_total: Option<i64>,
    hide_replies: bool,
    hide_boosts: bool,
    bsky_posts: i64,
    pin_rows: i64,
    pin_count: i64,
    featured_rows: i64,
    featured_count: i64,
    blog_rows: i64,
    blog_count: i64,
    blog_open: bool,
    media_total: Option<i64>,
}

pub(crate) struct LocalChrome {
    pub page_back: String,
    pub header: String,
    pub chrome: String,
    pub secondary: Option<String>,
}

fn flag(value: &Value, key: &str) -> bool {
    match value.get(key) {
        Some(Value::Bool(bit)) => *bit,
        Some(Value::Number(n)) => n.as_i64().unwrap_or(0) != 0,
        Some(Value::String(text)) => !text.is_empty() && text != "0",
        _ => false,
    }
}

fn num(value: &Value, key: &str) -> i64 {
    match value.get(key) {
        Some(Value::Number(n)) => n.as_i64().unwrap_or(0),
        Some(Value::String(text)) => text.parse().unwrap_or(0),
        _ => 0,
    }
}

fn text<'a>(value: &'a Value, key: &str) -> &'a str {
    value.get(key).and_then(Value::as_str).unwrap_or("")
}

fn maybe_text(value: &Value, key: &str) -> Option<String> {
    match value.get(key) {
        Some(Value::String(text)) if !text.is_empty() => Some(text.clone()),
        _ => None,
    }
}

pub(crate) fn php_urlencode(value: &str) -> String {
    let mut encoded = String::new();
    for byte in value.bytes() {
        match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' => encoded.push(byte as char),
            b' ' => encoded.push('+'),
            _ => encoded.push_str(&format!("%{byte:02X}")),
        }
    }
    encoded
}

fn html_decode(value: &str) -> String {
    let mut out = String::new();
    let bytes = value.as_bytes();
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] != b'&' {
            out.push(bytes[i] as char);
            i += 1;
            continue;
        }
        let rest = &value[i..];
        let Some(end) = rest.find(';') else { out.push('&'); i += 1; continue; };
        let token = &rest[1..end];
        let ch = if let Some(hex) = token.strip_prefix("#x").or_else(|| token.strip_prefix("#X")) {
            u32::from_str_radix(hex, 16).ok().and_then(char::from_u32)
        } else if let Some(dec) = token.strip_prefix('#') {
            dec.parse::<u32>().ok().and_then(char::from_u32)
        } else {
            Some(match token {
                "amp" => '&', "lt" => '<', "gt" => '>', "quot" => '"', "apos" => '\'', "nbsp" => '\u{00a0}',
                _ => { out.push('&'); i += 1; continue; }
            })
        };
        if let Some(ch) = ch { out.push(ch); i += end + 1; } else { out.push('&'); i += 1; }
    }
    out
}

pub(crate) fn field_html(raw: &str) -> String {
    let decoded = html_decode(raw);
    let mut out = String::new();
    let chars: Vec<char> = decoded.chars().collect();
    let mut i = 0;
    while i < chars.len() {
        if chars[i] != '<' {
            out.push(chars[i]);
            i += 1;
            continue;
        }
        let Some(end) = chars[i..].iter().position(|ch| *ch == '>') else {
            out.extend(chars[i..].iter());
            break;
        };
        let tag: String = chars[i + 1..i + end].iter().collect();
        let name = tag.split_whitespace().next().unwrap_or("").trim_start_matches('/').to_ascii_lowercase();
        if name == "a" && !tag.starts_with('/') {
            let href = attr_href(&tag);
            if href.to_ascii_lowercase().starts_with("https://") {
                out.push_str("<a href=\"");
                out.push_str(&esc(href));
                out.push_str("\" rel=\"nofollow noopener noreferrer\" target=\"_blank\">");
            }
        } else if name == "a" {
            out.push_str("</a>");
        }
        i += end + 1;
    }
    out
}

fn attr_href(tag: &str) -> &str {
    let lower = tag.to_ascii_lowercase();
    let Some(at) = lower.find("href") else { return ""; };
    let rest = tag[at + 4..].trim_start();
    let rest = rest.strip_prefix('=').unwrap_or("").trim_start();
    if let Some(rest) = rest.strip_prefix('"').or_else(|| rest.strip_prefix('\'')) {
        let quote = if tag[at + 4..].contains('"') && rest_starts_double(tag, at) { '"' } else { '\'' };
        return rest.split(quote).next().unwrap_or("");
    }
    rest.split_whitespace().next().unwrap_or("").trim_end_matches('>')
}

fn rest_starts_double(tag: &str, at: usize) -> bool {
    tag[at + 4..].trim_start().trim_start_matches('=').trim_start().starts_with('"')
}

fn badge(key: &str) -> Option<(&'static str, &'static str)> {
    Some(match key {
        "admin" => ("Admin", "🛡️"),
        "supporter" => ("Supporter", "💜"),
        "rainbow" => ("Rainbow pride", "🏳️‍🌈"),
        "trans" => ("Trans pride", "🏳️‍⚧️"),
        "bi" => ("Bisexual pride", "🩷💜💙"),
        "nonbinary" => ("Non-binary pride", "💛🤍💜🖤"),
        "lesbian" => ("Lesbian pride", "🧡🤍🩷"),
        "gay" => ("Gay pride", "💚💙"),
        "pansexual" => ("Pansexual pride", "🩷💛💙"),
        "asexual" => ("Asexual pride", "🖤🩶🤍💜"),
        "aromantic" => ("Aromantic pride", "💚🤍🩶🖤"),
        "genderfluid" => ("Genderfluid pride", "🩷🤍💜🖤💙"),
        "genderqueer" => ("Genderqueer pride", "💜🤍💚"),
        "intersex" => ("Intersex pride", "💛💜"),
        "polyamory" => ("Polyamory pride", "💙❤️🖤"),
        "queer" => ("Queer pride", "🏳️‍🌈"),
        "usa" => ("United States", "🇺🇸"),
        "uk" => ("United Kingdom", "🇬🇧"),
        "canada" => ("Canada", "🇨🇦"),
        "ireland" => ("Ireland", "🇮🇪"),
        "germany" => ("Germany", "🇩🇪"),
        "france" => ("France", "🇫🇷"),
        "netherlands" => ("Netherlands", "🇳🇱"),
        "ukraine" => ("Ukraine", "🇺🇦"),
        "japan" => ("Japan", "🇯🇵"),
        "australia" => ("Australia", "🇦🇺"),
        "new_zealand" => ("New Zealand", "🇳🇿"),
        "brazil" => ("Brazil", "🇧🇷"),
        "mexico" => ("Mexico", "🇲🇽"),
        "india" => ("India", "🇮🇳"),
        "south_africa" => ("South Africa", "🇿🇦"),
        "pirate" => ("Pirate", "🏴‍☠️"),
        "space" => ("Space nerd", "🚀"),
        "cat" => ("Cat person", "🐈‍⬛"),
        "dog" => ("Dog person", "🐕"),
        "coffee" => ("Coffee enthusiast", "☕"),
        "tea" => ("Tea enthusiast", "🫖"),
        "bookworm" => ("Bookworm", "📚"),
        "gamer" => ("Gamer", "🎮"),
        "music" => ("Music lover", "🎵"),
        "artist" => ("Artist", "🎨"),
        "programmer" => ("Programmer", "💻"),
        "plant_parent" => ("Plant parent", "🪴"),
        "night_owl" => ("Night owl", "🦉"),
        "wizard" => ("Wizard", "🧙"),
        "dragon" => ("Dragon friend", "🐉"),
        "dice" => ("Dice goblin", "🎲"),
        "minecraft" => ("Minecraft builder", "⛏️"),
        _ => return None,
    })
}

pub(crate) fn emoji_html(text: &str, emoji: &[(String, String)]) -> String {
    if text.is_empty() || emoji.is_empty() || !text.contains(':') { return esc(text); }
    let mut out = String::new();
    let mut rest = text;
    while let Some(start) = rest.find(':') {
        let after = &rest[start + 1..];
        let Some(end) = after.find(':') else { out.push_str(&esc(rest)); return out; };
        let code = &after[..end];
        if code.is_empty() || code.len() > 80 || !code.chars().all(|ch| ch.is_ascii_alphanumeric() || ch == '_' || ch == '-') {
            out.push_str(&esc(&rest[..=start]));
            rest = &rest[start + 1..];
            continue;
        }
        out.push_str(&esc(&rest[..start]));
        if let Some((_, url)) = emoji.iter().find(|(name, _)| name == code) {
            if url.starts_with("https://") {
                let alt = esc(&format!(":{code}:"));
                out.push_str(&format!("<img class=\"custom-emoji\" src=\"{}\" alt=\"{alt}\" title=\"{alt}\" width=\"20\" height=\"20\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\">", esc(url)));
            } else {
                out.push_str(&esc(&format!(":{code}:")));
            }
        } else {
            out.push_str(&esc(&format!(":{code}:")));
        }
        rest = &after[end + 1..];
    }
    out.push_str(&esc(rest));
    out
}

fn actor_handle(actor: &str, username: &str) -> String {
    let host = actor.split("://").nth(1).unwrap_or("").split('/').next().unwrap_or("");
    if !username.is_empty() && !host.is_empty() { return format!("@{username}@{host}"); }
    let base = actor.trim_end_matches('/').rsplit('/').next().unwrap_or("");
    if !host.is_empty() && !base.is_empty() { format!("@{base}@{host}") } else { actor.to_owned() }
}

fn resonant(name: &str, strain: &str) -> String {
    if name.is_empty() { return String::new(); }
    let escaped_name = esc(name);
    let mut title = format!("Linked NovaLandia OpenSim avatar: {escaped_name}");
    if !strain.trim().is_empty() { title.push_str(" · "); title.push_str(strain.trim()); }
    format!(" <span class=\"resonant-badge\" title=\"{}\" aria-label=\"Resonant — linked OpenSim avatar\">◈ Resonant</span>", esc(&title))
}

fn sl_url(agent: &str) -> String {
    let id = agent.trim().to_ascii_lowercase();
    let ok = id.len() == 36 && id.as_bytes().iter().enumerate().all(|(i, byte)| {
        matches!(i, 8 | 13 | 18 | 23) && *byte == b'-' || !matches!(i, 8 | 13 | 18 | 23) && byte.is_ascii_hexdigit()
    });
    if ok { format!("secondlife:///app/agent/{}/about", query(&id)) } else { "https://secondlife.com/".into() }
}

fn back(from: &str) -> (&'static str, &'static str, &'static str) {
    match from {
        "mentions" => ("?view=mentions", "← Notifications", "mentions"),
        "search" => ("?view=search", "← Search", "search"),
        "followers" => ("?view=followers", "← Followers", "followers"),
        "home" => ("?view=home", "← Home", "home"),
        "feed" => ("?view=feed", "← Federated", "feed"),
        "blocks" => ("?view=blocks", "← Blocks & mutes", ""),
        "about" => ("?view=about", "← About VAAK", ""),
        _ => ("?view=following", "← Following", "following"),
    }
}

fn profile_action(actor: &str, from: &str) -> String {
    format!("?view=remote_profile&amp;actor={}&amp;from={}", php_urlencode(actor), php_urlencode(from))
}

fn hidden(name: &str, value: &str) -> String {
    format!("<input type=\"hidden\" name=\"{name}\" value=\"{}\">", esc(value))
}

fn report_href(actor: &str, from: &str) -> String {
    let from: String = from.chars().filter(|ch| ch.is_ascii_lowercase() || *ch == '_').collect();
    let mut href = format!("?view=report&report_target={}", php_urlencode(actor));
    if !from.is_empty() { href.push_str("&from="); href.push_str(&php_urlencode(&from)); }
    esc(&href)
}

fn menu(f: &Facts) -> String {
    let mut body = String::new();
    let fediverse = f.actor.starts_with("https://") && !f.actor.to_ascii_lowercase().contains("bsky.app");
    if fediverse && f.personal_block_id.is_none() {
        body.push_str(&format!("<a class=\"menu-action\" href=\"?view=dms&amp;peer={}\">DM</a>", esc(&query(&f.actor))));
    }
    let ask_from = if f.from.is_empty() { "remote_profile" } else { f.from.as_str() };
    body.push_str(&format!(
        "<a class=\"menu-action\" href=\"?view=ask&amp;target_actor={}&amp;from={}\">Ask</a>",
        esc(&query(&f.actor)), esc(&query(ask_from))
    ));
    let csrf = hidden("csrf", &f.csrf);
    let ret = format!("{}{}{}", hidden("return_view", "remote_profile"), hidden("return_from", &f.from), hidden("return_actor", &f.actor));
    if f.target_is_admin {
        body.push_str("<span class=\"menu-action menu-action-disabled\" aria-disabled=\"true\" title=\"Administrators cannot be blocked\">Admin account — cannot be blocked</span>");
    } else if let Some(id) = f.personal_block_id {
        body.push_str(&format!("<form method=\"post\" action=\"?view=remote_profile\">{csrf}<input type=\"hidden\" name=\"action\" value=\"user_block_remove\">{ret}<input type=\"hidden\" name=\"id\" value=\"{id}\"><button class=\"menu-action\" type=\"submit\" title=\"Hide from your timelines only\">Unblock</button></form>"));
    } else {
        body.push_str(&format!("<form method=\"post\" action=\"?view=remote_profile\">{csrf}<input type=\"hidden\" name=\"action\" value=\"user_block_add\">{ret}{}<button class=\"menu-action\" type=\"submit\" title=\"Hide from your timelines only\">Block</button></form>", hidden("target", &f.actor)));
    }
    let mute = if f.muted { "unmute_remote" } else { "mute_remote" };
    let mute_label = if f.muted { "Unmute" } else { "Mute" };
    body.push_str(&format!("<form method=\"post\" action=\"?view=remote_profile\">{csrf}<input type=\"hidden\" name=\"action\" value=\"{mute}\">{ret}{}<button class=\"menu-action\" type=\"submit\" title=\"Hide from your Home / Federated / Notifications\">{mute_label}</button></form>", hidden("actor_id", &f.actor)));
    let (dep_action, dep_label) = if f.deprioritized { ("undeprioritize_remote", "Stop deprioritizing") } else { ("deprioritize_remote", "Deprioritize on Home") };
    body.push_str(&format!("<form method=\"post\" action=\"?view=remote_profile\">{csrf}<input type=\"hidden\" name=\"action\" value=\"{dep_action}\">{ret}{}<button class=\"menu-action\" type=\"submit\">{dep_label}</button></form>", hidden("actor_id", &f.actor)));
    body.push_str(&format!("<a class=\"menu-action\" href=\"{}\">Report user</a>", report_href(&f.actor, ask_from)));
    if f.viewer_is_admin {
        body.push_str("<div class=\"menu-action-sep\" role=\"separator\" aria-hidden=\"true\"></div>");
        body.push_str(&format!("<a class=\"menu-action\" href=\"/vaak/?view=moderation&amp;actor={}&amp;from=remote_profile\">Open in moderation panel</a>", query(&f.actor)));
    }
    format!("<details class=\"post-action-menu\"><summary class=\"icon-btn\" title=\"More actions\" aria-label=\"More actions\">⋯</summary><div class=\"post-action-menu__body\">{body}</div></details>")
}

fn follow_form(w: &mut W, f: &Facts) {
    let action = profile_action(&f.actor, &f.from);
    w.indent(18);
    if f.following {
        w.line(20, &format!("<form method=\"post\" action=\"{action}\" style=\"display:inline\" onsubmit=\"return confirm('Unfollow this account?');\">"));
        form_fields(w, f, "unfollow_remote");
        w.line(22, "<button class=\"btn btn-following\" type=\"submit\" title=\"Following — click to unfollow\">Following</button>");
        w.line(20, "</form>");
        w.indent(18);
    } else if f.requested {
        w.line(20, &format!("<form method=\"post\" action=\"{action}\" style=\"display:inline\" data-follow-locked=\"1\" onsubmit=\"return confirm('Cancel this follow request?');\">"));
        form_fields(w, f, "unfollow_remote");
        w.line(22, "<button class=\"btn btn-following\" type=\"submit\" title=\"Requested — click to cancel\">Requested</button>");
        w.line(20, "</form>");
        w.indent(18);
    } else {
        let locked = if f.locked { "1" } else { "0" };
        w.line(20, &format!("<form method=\"post\" action=\"{action}\" style=\"display:inline\" data-follow-locked=\"{locked}\">"));
        form_fields(w, f, "follow_remote");
        let label = if f.follows_you && !f.following { "Follow back" } else { "Follow" };
        w.line(22, &format!("<button class=\"btn btn-primary\" type=\"submit\">{label}</button>"));
        w.line(20, "</form>");
        w.indent(18);
    }
}

fn form_fields(w: &mut W, f: &Facts, action: &str) {
    w.line(22, &format!("<input type=\"hidden\" name=\"action\" value=\"{action}\">"));
    w.line(22, "<input type=\"hidden\" name=\"return_view\" value=\"remote_profile\">");
    w.line(22, &hidden("return_actor", &f.actor));
    w.line(22, &hidden("return_from", &f.from));
    w.line(22, &hidden("actor_id", &f.actor));
}

fn post_form(w: &mut W, f: &Facts, action_name: &str, label: &str, title: &str) {
    w.line(18, &format!("<form method=\"post\" action=\"{}\" style=\"display:inline\">", profile_action(&f.actor, &f.from)));
    w.line(20, &format!("<input type=\"hidden\" name=\"action\" value=\"{action_name}\">"));
    w.line(20, "<input type=\"hidden\" name=\"return_view\" value=\"remote_profile\">");
    w.line(20, &hidden("return_actor", &f.actor));
    w.line(20, &hidden("return_from", &f.from));
    w.line(20, &hidden("actor_id", &f.actor));
    w.line(20, &format!("<button class=\"btn btn-ghost\" type=\"submit\" title=\"{title}\">{label}</button>"));
    w.line(18, "</form>");
}

fn tabs(w: &mut W, f: &Facts) {
    let mut posts = f.item_posts;
    if f.outbox_total != 0 { posts = posts.max(f.outbox_total); }
    let mut replies = f.item_replies;
    if f.replies_total != 0 && !f.hide_replies { replies = replies.max(f.replies_total); }
    let mut boosts = f.item_boosts;
    if let Some(total) = f.boost_total { if !f.hide_boosts { boosts = boosts.max(total); } }
    let mut media = f.item_media;
    if let Some(total) = f.media_total { media = media.max(total); }
    if f.bsky_posts > 0 { posts = posts.max(f.bsky_posts); }
    let mut defs: Vec<(&str, &str, i64)> = Vec::new();
    if f.pin_rows > 0 || f.pin_count > 0 {
        defs.push(("pinned", "Pinned", if f.pin_rows > 0 { f.pin_rows } else { f.pin_count }));
    }
    defs.push(("posts", "Posts", posts));
    if !f.hide_replies { defs.push(("replies", "Replies", replies)); }
    if !f.hide_boosts { defs.push(("boosts", "Boosts", boosts)); }
    defs.push(("media", "Media", media));
    if f.featured_rows > 0 || f.featured_count > 0 {
        defs.push(("featured", "Featured", if f.featured_rows > 0 { f.featured_rows } else { f.featured_count }));
    }
    if f.blog_count > 0 || f.blog_rows > 0 || f.blog_open {
        defs.push(("blog", "Blog", if f.blog_count > 0 { f.blog_count } else { f.blog_rows }));
    }
    let prefix = if let Some(prefix) = &f.tab_href {
        prefix.clone()
    } else if f.from.is_empty() {
        format!("/users/{}?tab=", query(&f.key))
    } else {
        format!("/users/{}?from={}&tab=", query(&f.key), query(&f.from))
    };
    w.indent(12);
    w.line(12, "<button type=\"button\" class=\"remote-profile-tabs-scroll-btn\" data-profile-tabs-scroll=\"left\" aria-label=\"Scroll profile tabs left\">&lt;</button>");
    w.line(12, "<div class=\"remote-profile-tabs-scroll\">");
    w.indent(12);
    for (i, (key, label, count)) in defs.iter().enumerate() {
        if i > 0 { w.indent(12); }
        w.indent(14);
        let href = esc(&format!("{prefix}{}", query(key)));
        let active = f.tab == *key;
        let class = if active { "class=\"is-active\" aria-current=\"page\"" } else { "class=\"\" " };
        w.line(14, &format!("<a href=\"{href}\" {class}>{label}<span class=\"tab-count\">{count}</span></a>"));
    }
    w.indent(12);
    w.line(12, "</div>");
    w.line(12, "<button type=\"button\" class=\"remote-profile-tabs-scroll-btn\" data-profile-tabs-scroll=\"right\" aria-label=\"Scroll profile tabs right\">&gt;</button>");
    w.line(10, "</nav>");
}

fn page_back(f: &Facts) -> String {
    let mut w = W::new();
    let (href, label, view) = back(&f.from);
    w.indent(10);
    w.line(10, "<div class=\"page-back\">");
    let soft = if view.is_empty() { String::new() } else { format!(" data-vaak-soft-nav=\"{}\"", esc(view)) };
    w.line(12, &format!("<a class=\"btn btn-ghost\" href=\"{href}\"{soft}>{}</a>", esc(label)));
    w.indent(12);
    w.line(14, &format!("<a class=\"btn btn-ghost\" href=\"{}\" title=\"Reload local profile\">↻ Refresh</a>", profile_action(&f.actor, &f.from)));
    w.indent(12);
    w.line(10, "</div>");
    w.indent(10);
    w.finish()
}

fn header(f: &Facts) -> String {
    let mut w = W::new();
    w.indent(10);
    if let Some(banner) = &f.banner {
        w.line(12, "<div style=\"height:120px;border-radius:12px;overflow:hidden;margin-bottom:.75rem;background:#111\">");
        w.line(14, &format!("<img src=\"{}\" alt=\"\" style=\"width:100%;height:100%;object-fit:cover\" loading=\"lazy\" referrerpolicy=\"no-referrer\">", esc(banner)));
        w.line(12, "</div>");
        w.indent(10);
    }
    w.line(10, "<article class=\"tweet\">");
    w.line(12, "<div class=\"tweet-hd remote-profile-hd\">");
    w.line(14, "<div class=\"remote-profile-hd-main\">");
    w.indent(16);
    if let Some(avatar) = &f.avatar {
        w.indent(18);
        w.indent(18);
        w.text(&format!("<img src=\"{}\" alt=\"\" width=\"64\" height=\"64\" style=\"border-radius:50%;object-fit:cover\" loading=\"lazy\" referrerpolicy=\"no-referrer\"", esc(avatar)));
        w.out.push('\n');
        w.line(23, &format!("onerror=\"this.onerror=null;this.src='{}'\">", esc(&f.fallback_avatar)));
        w.indent(16);
    } else {
        w.indent(18);
        let hover = format!(" data-profile-hover-actor=\"{}\" ", esc(&f.actor));
        let src = f.bare_src.as_deref().unwrap_or(f.fallback_avatar.as_str());
        w.text(&format!("<img class=\"tweet-av\" src=\"{}\" alt=\"\" width=\"40\" height=\"40\" {hover}loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\" onerror=\"this.onerror=null;this.src='{}'\">", esc(src), esc(&f.fallback_avatar)));
        w.indent(16);
    }
    w.line(16, "<div style=\"min-width:0\">");
    let who = if let Some(html) = &f.display_html {
        html.clone()
    } else if f.display_present {
        emoji_html(&f.display_name, &f.emoji)
    } else {
        esc(&actor_handle(&f.actor, ""))
    };
    w.indent(18);
    w.text(&format!("<div class=\"who\">{who}{}</div>", resonant(&f.bridge_name, &f.bridge_strain)));
    w.out.push('\n');
    let handle = actor_handle(&f.actor, &f.username);
    w.indent(18);
    w.text(&format!("<div class=\"meta\">{}", esc(&handle)));
    w.indent(20);
    w.text("<span class=\"tag\" style=\"margin-left:.35rem\" title=\"Account on this instance\">local</span>");
    w.indent(20);
    w.indent(20);
    if f.is_own {
        w.text("<span class=\"tag\" style=\"margin-left:.35rem\" title=\"Signed-in account\">you</span>");
    }
    w.line(18, "</div>");
    w.indent(18);
    if f.is_own {
        w.line(20, "<div class=\"meta\" style=\"margin-top:.25rem\">This is you · <a href=\"/vaak/?view=profile\">Edit profile</a></div>");
        w.indent(18);
    }
    w.line(16, "</div>");
    w.line(14, "</div>");
    w.indent(14);
    w.line(12, "</div>");
    w.indent(12);
    w.indent(12);
    if !f.bio.is_empty() {
        w.line(14, &format!("<div class=\"body\" style=\"margin-top:.75rem;white-space:pre-wrap\">{}</div>", esc(&f.bio)));
        w.indent(12);
    } else {
        w.line(14, "<div class=\"meta\" style=\"margin-top:.75rem\">No bio set.</div>");
        w.indent(12);
    }
    w.indent(12);
    w.indent(12);
    let has_sl = !f.sl_name.is_empty();
    let has_wow = !f.wow_url.is_empty();
    if has_sl || has_wow {
        w.line(14, "<div class=\"profile-world-links\" style=\"display:flex;gap:.5rem;align-items:center;margin:.65rem 0 0;flex-wrap:wrap\">");
        if has_sl {
            w.indent(16);
            w.indent(18);
            let href = sl_url(&f.sl_agent);
            let name = esc(&f.sl_name);
            w.line(18, &format!("<a href=\"{}\" rel=\"noopener noreferrer me\" title=\"Open {name} in Second Life\" aria-label=\"Open {name} in Second Life\">", esc(&href)));
            w.line(20, "<img src=\"/vaak/second-life.jpg\" alt=\"Second Life\" width=\"43\" height=\"34\" loading=\"lazy\">");
            w.line(18, "</a>");
            w.line(18, &format!("<span class=\"meta\">{name}</span>"));
            w.indent(16);
        }
        if has_wow {
            w.indent(16);
            w.indent(18);
            let label = if f.wow_realm.trim().is_empty() { f.wow_name.clone() } else { format!("{} · {}", f.wow_name, f.wow_realm.trim()) };
            let safe = esc(&label);
            if !f.wow_portrait.is_empty() {
                w.indent(18);
                w.line(20, &format!("<a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\" title=\"Open {safe} Armory profile\" aria-label=\"Open {safe} Armory profile\">", esc(&f.wow_url)));
                w.line(22, &format!("<img src=\"{}\" alt=\"World of Warcraft\" width=\"43\" height=\"34\" loading=\"lazy\" referrerpolicy=\"no-referrer\" style=\"object-fit:cover;border-radius:8px\">", esc(&f.wow_portrait)));
                w.line(20, "</a>");
                w.indent(18);
            } else {
                w.indent(18);
                w.line(20, &format!("<a class=\"btn btn-ghost\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">WoW Armory</a>", esc(&f.wow_url)));
                w.indent(18);
            }
            w.line(18, &format!("<span class=\"meta\">{safe}</span>"));
            w.indent(16);
        }
        w.line(14, "</div>");
        w.indent(12);
    }
    w.indent(12);
    w.indent(12);
    let fields: Vec<&Field> = f.fields.iter().filter(|field| !field.name.trim().is_empty() && !field.value.trim().is_empty()).collect();
    if !fields.is_empty() {
        w.line(14, "<div class=\"remote-profile-fields\" aria-label=\"Profile fields\">");
        w.indent(16);
        for (i, field) in fields.iter().enumerate() {
            if i > 0 { w.indent(16); }
            w.line(18, "<div class=\"remote-profile-field\">");
            let verified = if field.verified.is_empty() {
                String::new()
            } else {
                format!(" <span class=\"field-verified\" title=\"Verified via rel=me · {}\" aria-label=\"Verified\">✓</span>", esc(&field.verified))
            };
            w.line(20, &format!("<span class=\"meta\" style=\"display:block;font-size:.75rem;margin:0 0 .15rem\">{}{verified}</span>", esc(field.name.trim())));
            w.line(20, &format!("<div style=\"overflow:hidden;text-overflow:ellipsis;white-space:nowrap\">{}</div>", field.value));
            w.line(18, "</div>");
        }
        w.indent(16);
        w.line(14, "</div>");
        w.indent(12);
    }
    w.indent(12);
    let badges: Vec<_> = f.badges.iter().filter_map(|key| badge(key).map(|item| (key, item))).collect();
    if !badges.is_empty() {
        w.line(14, "<div class=\"profile-badges\" aria-label=\"Profile badges\" style=\"display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.65rem\">");
        w.indent(16);
        for (i, (_key, (label, emoji))) in badges.iter().enumerate() {
            if i > 0 { w.indent(16); }
            w.line(18, &format!("<span class=\"tag\" title=\"{}\">{emoji} {}</span>", esc(label), esc(label)));
        }
        w.indent(16);
        w.line(14, "</div>");
        w.indent(12);
    }
    w.indent(12);
    w.indent(14);
    let title = if f.stats_bsky { " title=\"Includes ActivityPub and connected Bluesky counts\"" } else { "" };
    let (following_href, followers_href, external) = if f.is_own {
        ("?view=following".to_owned(), "?view=followers".to_owned(), "")
    } else {
        (format!("/users/{}/following", query(&f.key)), format!("/users/{}/followers", query(&f.key)), " target=\"_blank\" rel=\"noopener noreferrer\"")
    };
    w.line(14, "<div class=\"remote-profile-stats\" aria-label=\"Profile stats\">");
    w.line(16, &format!("<div><span class=\"n\">{}</span><span class=\"l\">Posts</span></div>", f.posts_stat));
    w.line(16, &format!("<a href=\"{}\"{external}{title}><span class=\"n\">{}</span><span class=\"l\">Following</span></a>", esc(&following_href), f.following_stat));
    w.line(16, &format!("<a href=\"{}\"{external}{title}><span class=\"n\">{}</span><span class=\"l\">Followers</span></a>", esc(&followers_href), f.followers_stat));
    w.line(14, "</div>");
    w.indent(12);
    w.line(12, "<div class=\"tweet-actions remote-profile-actions\" style=\"flex-wrap:wrap;align-items:center;gap:.45rem\">");
    w.indent(14);
    if !f.is_own {
        w.line(16, "<span class=\"remote-profile-follow-inline\">");
        follow_form(&mut w, f);
        let following = if f.following { "1" } else { "0" };
        let followed = if f.follows_you { "1" } else { "0" };
        w.line(18, &format!("<span id=\"remote-profile-rel-state\" data-following=\"{following}\" data-followed-by=\"{followed}\">"));
        w.indent(20);
        if f.following && f.follows_you {
            w.line(22, "<span class=\"tag\" title=\"You follow each other\">mutuals</span>");
            w.indent(20);
        } else if f.follows_you {
            w.line(22, "<span class=\"tag\" title=\"This account follows you\">follows you</span>");
            w.indent(20);
        }
        w.line(18, "</span>");
        w.line(16, "</span>");
        w.indent(14);
    }
    w.indent(14);
    if f.muted {
        w.text("<span class=\"tag\" title=\"Hidden from your timelines &amp; notifications\">muted for me</span>");
    }
    w.indent(14);
    if f.blocked_personal {
        w.text("<span class=\"tag\" style=\"color:var(--danger)\" title=\"Personal block — hidden from your timelines only\">blocked for me</span>");
    }
    w.indent(14);
    if f.blocked_server {
        w.text("<span class=\"tag\" style=\"color:var(--danger)\" title=\"Server-wide block\">blocked server-wide</span>");
    }
    w.indent(14);
    if !f.is_own {
        w.indent(16);
        if f.subscribed {
            post_form(&mut w, f, "unsubscribe_posts", "Unsubscribe", "Stop notifications for their posts");
            w.indent(16);
        } else {
            post_form(&mut w, f, "subscribe_posts", "Subscribe", "Get a notification when they post");
            w.indent(16);
        }
        w.indent(14);
    }
    w.indent(14);
    w.indent(16);
    if !f.is_own {
        w.indent(18);
        w.indent(18);
        let mention = format!("@{}", f.username.trim_start_matches('@').trim_end_matches("@mkultra.monster"));
        if !f.username.is_empty() && f.username != "?" {
            w.text(&format!("<a class=\"btn btn-ghost\" href=\"?view=outbox&amp;compose=1&amp;mention={}&amp;return_view=remote_profile&amp;return_actor={}\" title=\"Start a post mentioning this account\">Mention</a>", php_urlencode(&mention), php_urlencode(&f.actor)));
        }
        w.indent(16);
    }
    w.indent(16);
    if !f.is_own {
        w.line(16, &format!("<form method=\"post\" action=\"{}\" style=\"display:inline\" onsubmit=\"return confirm('Bite this account? (Wafrn-compatible 🦷)');\">", profile_action(&f.actor, &f.from)));
        w.line(18, "<input type=\"hidden\" name=\"action\" value=\"bite_remote\">");
        w.line(18, "<input type=\"hidden\" name=\"return_view\" value=\"remote_profile\">");
        w.line(18, &hidden("return_actor", &f.actor));
        w.line(18, &hidden("return_from", &f.from));
        w.line(18, "<input type=\"hidden\" name=\"bite_kind\" value=\"user\">");
        w.line(18, &hidden("target", &f.actor));
        w.line(18, "<button class=\"btn btn-ghost\" type=\"submit\" title=\"Wafrn-compatible bite\"><i class=\"ph ph-tooth\" aria-hidden=\"true\"></i> Bite</button>");
        w.line(16, "</form>");
        w.indent(16);
    }
    w.indent(14);
    w.indent(14);
    if !f.is_own {
        w.indent(16);
        w.text(&menu(f));
        w.indent(14);
    }
    w.indent(14);
    w.indent(16);
    w.indent(14);
    w.line(12, "</div>");
    w.line(10, "</article>");
    w.line(10, "<nav class=\"remote-profile-tabs\" aria-label=\"Profile posts\">");
    tabs(&mut w, f);
    w.finish()
}

fn load(input: &Value) -> Result<Facts, String> {
    let actor = text(input, "actor").trim().trim_end_matches('/').to_owned();
    let key = text(input, "key").to_owned();
    if !actor.starts_with("https://mkultra.monster/users/") || key.is_empty()
        || !key.chars().all(|ch| ch.is_ascii_alphanumeric() || ch == '_')
        || actor != format!("https://mkultra.monster/users/{key}") {
        return Err("local profile actor".into());
    }
    let display = input.get("display_name").and_then(Value::as_str).unwrap_or("");
    let display_present = !display.is_empty() && display != "0";
    let fields = input.get("fields").and_then(Value::as_array).map(|rows| {
        rows.iter().filter_map(|row| {
            let name = text(row, "name").to_owned();
            let value = field_html(text(row, "value"));
            if name.trim().is_empty() || value.trim().is_empty() { return None; }
            let verified = row.get("verified_at").and_then(Value::as_str).unwrap_or("").to_owned();
            Some(Field { name, value, verified })
        }).collect()
    }).unwrap_or_default();
    let emoji = input.get("emoji").and_then(Value::as_object).map(|map| {
        map.iter().filter_map(|(k, v)| v.as_str().map(|url| (k.clone(), url.to_owned()))).collect()
    }).unwrap_or_default();
    let bridge = input.get("bridge").and_then(Value::as_object);
    let sl = input.get("sl").and_then(Value::as_object);
    let wow = input.get("wow").and_then(Value::as_object);
    let badges = input.get("badges").and_then(Value::as_array).map(|rows| rows.iter().filter_map(|row| row.as_str().map(str::to_owned)).collect()).unwrap_or_default();
    Ok(Facts {
        actor, key, from: text(input, "from").to_owned(), tab: text(input, "tab").to_owned(),
        tab_href: maybe_text(input, "tab_href"),
        display_name: display.to_owned(), display_present, username: text(input, "username").to_owned(),
        bio: text(input, "bio").to_owned(), avatar: maybe_text(input, "avatar"), banner: maybe_text(input, "banner"),
        fallback_avatar: maybe_text(input, "fallback_avatar").unwrap_or_else(|| FALLBACK_AVATAR.to_owned()),
        bare_src: maybe_text(input, "bare_src"),
        emoji, display_html: maybe_text(input, "display_html"),
        is_own: flag(input, "is_own"), following: flag(input, "following"), follows_you: flag(input, "follows_you"),
        requested: flag(input, "requested"), locked: flag(input, "locked"), muted: flag(input, "muted"),
        blocked_personal: flag(input, "blocked_personal"), blocked_server: flag(input, "blocked_server"),
        subscribed: flag(input, "subscribed"),
        personal_block_id: input.get("personal_block_id").and_then(Value::as_i64),
        deprioritized: flag(input, "deprioritized"), target_is_admin: flag(input, "target_is_admin"),
        viewer_is_admin: flag(input, "viewer_is_admin"), csrf: text(input, "csrf").to_owned(),
        bridge_name: bridge.and_then(|row| row.get("avatar_name")).and_then(Value::as_str).unwrap_or("").to_owned(),
        bridge_strain: bridge.and_then(|row| row.get("strain_snapshot")).and_then(Value::as_str).unwrap_or("").to_owned(),
        sl_name: sl.and_then(|row| row.get("sl_username")).and_then(Value::as_str).unwrap_or("").to_owned(),
        sl_agent: sl.and_then(|row| row.get("sl_agent_id")).and_then(Value::as_str).unwrap_or("").to_owned(),
        wow_name: wow.and_then(|row| row.get("character_name")).and_then(Value::as_str).unwrap_or("World of Warcraft character").to_owned(),
        wow_realm: wow.and_then(|row| row.get("realm")).and_then(Value::as_str).unwrap_or("").to_owned(),
        wow_url: wow.and_then(|row| row.get("armory_url")).and_then(Value::as_str).unwrap_or("").to_owned(),
        wow_portrait: wow.and_then(|row| row.get("portrait_url")).and_then(Value::as_str).unwrap_or("").trim().to_owned(),
        fields, badges, stats_bsky: flag(input, "stats_bsky"),
        posts_stat: num(input, "posts_stat"), following_stat: num(input, "following_stat"), followers_stat: num(input, "followers_stat"),
        item_posts: num(input, "item_posts"), item_replies: num(input, "item_replies"), item_boosts: num(input, "item_boosts"),
        item_media: num(input, "item_media"), item_asks: num(input, "item_asks"),
        outbox_total: num(input, "outbox_total"), replies_total: num(input, "replies_total"),
        boost_total: input.get("boost_total").map(|_| num(input, "boost_total")),
        hide_replies: flag(input, "hide_replies"), hide_boosts: flag(input, "hide_boosts"), bsky_posts: num(input, "bsky_posts"),
        pin_rows: num(input, "pin_rows"), pin_count: num(input, "pin_count"),
        featured_rows: num(input, "featured_rows"), featured_count: num(input, "featured_count"),
        blog_rows: num(input, "blog_rows"), blog_count: num(input, "blog_count"), blog_open: flag(input, "blog_open"),
        media_total: input.get("media_total").and_then(Value::as_i64),
    })
}

pub(crate) fn paint_local(input: &Value) -> Result<LocalChrome, String> {
    let facts = load(input)?;
    let page_back = page_back(&facts);
    let header = header(&facts);
    let chrome = format!("{page_back}{header}");
    let secondary = if facts.tab == "asks" && facts.item_asks == 0 {
        Some("                                    <div class=\"empty\">No answered Asks yet.</div>\n            ".to_owned())
    } else if facts.tab == "pinned" && facts.pin_rows == 0 && facts.pin_count > 0 {
        Some("                                    <div class=\"empty\">No pinned posts.</div>\n            ".to_owned())
    } else {
        None
    };
    Ok(LocalChrome { page_back, header, chrome, secondary })
}

pub(crate) fn secret() -> Option<String> {
    if std::env::var("VAAK_PROFILE_NATIVE_RENDER").ok().as_deref() != Some("1") { return None; }
    std::env::var("VAAK_PROFILE_NATIVE_SECRET").ok().filter(|value| value.len() >= 32)
}

pub(crate) fn enabled() -> bool { secret().is_some() }

pub(crate) fn authorized(headers: &HeaderMap, expected: &str) -> bool {
    let supplied = headers.get("x-vaak-profile-token").and_then(|value| value.to_str().ok()).unwrap_or("");
    supplied.len() == expected.len() && supplied.bytes().zip(expected.bytes()).fold(0u8, |diff, (a, b)| diff | (a ^ b)) == 0
}

pub(crate) async fn render(_cfg: &crate::config::Config, headers: HeaderMap, bytes: Bytes) -> Response {
    let Some(secret) = secret() else { return StatusCode::NOT_FOUND.into_response() };
    if !authorized(&headers, &secret) { return StatusCode::UNAUTHORIZED.into_response(); }
    if bytes.len() > 256 * 1024 { return (StatusCode::BAD_REQUEST, axum::Json(json!({"fallback": true}))).into_response(); }
    let input: Value = match serde_json::from_slice(&bytes) { Ok(value) => value, Err(_) => return (StatusCode::BAD_REQUEST, axum::Json(json!({"fallback": true}))).into_response() };
    if input.get("protocol_version").and_then(Value::as_u64) != Some(1) {
        return (StatusCode::BAD_REQUEST, axum::Json(json!({"fallback": true}))).into_response();
    }
    let painted = match paint_local(&input) { Ok(painted) => painted, Err(_) => return (StatusCode::UNPROCESSABLE_ENTITY, axum::Json(json!({"fallback": true}))).into_response() };
    let binding = json!({"actor": input.get("actor").and_then(Value::as_str).unwrap_or("").trim_end_matches('/'), "tab": text(&input, "tab"), "from": text(&input, "from")});
    let body = json!({"ok": true, "protocol_version": 1, "request_binding": binding, "chrome": painted.chrome, "page_back": painted.page_back, "header": painted.header, "secondary": painted.secondary});
    let bytes = match serde_json::to_vec(&body) { Ok(bytes) => bytes, Err(_) => return (StatusCode::UNPROCESSABLE_ENTITY, axum::Json(json!({"fallback": true}))).into_response() };
    if bytes.len() > 2 * 1024 * 1024 { return (StatusCode::UNPROCESSABLE_ENTITY, axum::Json(json!({"fallback": true}))).into_response(); }
    (StatusCode::OK, [(axum::http::header::CONTENT_TYPE, "application/json"), (axum::http::header::CACHE_CONTROL, "no-store")], bytes).into_response()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn capture() -> Value {
        let file = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/profile-local-chrome-capture.php");
        let output = std::process::Command::new(file.parent().unwrap().join("php-policy-test")).arg(&file).output().unwrap();
        assert!(output.status.success(), "{}", String::from_utf8_lossy(&output.stderr));
        serde_json::from_slice(&output.stdout).unwrap()
    }

    fn mismatch(actual: &str, expected: &str) -> String {
        let left = actual.as_bytes();
        let right = expected.as_bytes();
        let mut at = 0;
        while at < left.len() && at < right.len() && left[at] == right[at] { at += 1; }
        let start = at.saturating_sub(60);
        format!("at {at}\nrust {:?}\nphp  {:?}", &actual[start..actual.len().min(at + 120)], &expected[start..expected.len().min(at + 120)])
    }

    #[test]
    fn local_chrome_matches_php() {
        let captured = capture();
        let cases = captured["cases"].as_array().unwrap();
        let mut seen = Vec::new();
        for case in cases {
            let id = text(case, "id");
            seen.push(id.to_owned());
            let painted = paint_local(&case["input"]).unwrap_or_else(|err| panic!("{id}: {err}"));
            assert_eq!(painted.chrome, text(case, "chrome"), "{id} chrome {}", mismatch(&painted.chrome, text(case, "chrome")));
            let expected = case.get("secondary").and_then(Value::as_str);
            assert_eq!(painted.secondary.as_deref(), expected, "{id} secondary");
            assert_eq!(format!("{}{}", painted.page_back, painted.header), painted.chrome);
        }
        for id in ["peer", "own", "follow_back", "following", "requested", "locked", "muted_blocked", "admin_target", "badges_fields", "tabs", "asks_empty", "asks_filled", "pins_filled", "wow_text"] {
            assert!(seen.iter().any(|seen| seen == id), "missing {id}");
        }
    }
}
