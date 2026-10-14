//! Bluesky profile chrome. PHP still loads the actor and performs every write.
//! This painter repeats the header, relationship row, and tabs. It does not
//! open a database or a socket.

use axum::body::Bytes;
use axum::http::{HeaderMap, StatusCode};
use axum::response::{IntoResponse, Response};
use serde_json::{json, Value};

use crate::library::actions::{esc, query};
use crate::profile_local::{emoji_html, field_html, php_urlencode};

const FALLBACK_AVATAR: &str = "https://mkultra.monster/img/avatar/default.webp";
const SERVER_BLOCK: &str = "Block this Bluesky user server-wide on VAAK? Everyone on this instance will stop seeing them. This also blocks them for you personally (and syncs that personal block to your Bluesky account if connected). Other VAAK users’ Bluesky blocklists are left alone.";

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

struct Field {
    name: String,
    value: String,
    verified: String,
}

struct Facts {
    actor: String,
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
    handle_text: Option<String>,
    bsky_handle: String,
    is_own: bool,
    following: bool,
    follows_you: bool,
    requested: bool,
    locked: bool,
    muted: bool,
    blocked_personal: bool,
    blocked_server: bool,
    personal_block_id: Option<i64>,
    deprioritized: bool,
    target_is_admin: bool,
    viewer_is_admin: bool,
    wafrn_known: bool,
    server_id: Option<i64>,
    server_kind: String,
    csrf: String,
    fields: Vec<Field>,
    item_posts: i64,
    item_replies: i64,
    item_boosts: i64,
    item_media: i64,
}

pub(crate) struct RemoteChrome {
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

fn host_of(actor: &str) -> &str {
    actor.split("://").nth(1).unwrap_or("").split('/').next().unwrap_or("")
}

fn normalize_username(username: &str) -> String {
    let trimmed = username.trim().trim_start_matches('@');
    let head = trimmed.split('@').next().unwrap_or("");
    head.trim().to_owned()
}

fn username_is_placeholder(username: &str) -> bool {
    let username = username.trim().trim_start_matches('@');
    if username.is_empty() || username == "user" { return true; }
    let lower = username.to_ascii_lowercase();
    if lower.starts_with("did:") || lower.starts_with("did%3a") { return true; }
    username.len() >= 6 && username.bytes().all(|byte| byte.is_ascii_digit())
}

fn actor_handle(actor: &str, username: &str) -> String {
    let host = host_of(actor);
    let user = if username.trim().is_empty() { String::new() } else { normalize_username(username) };
    if !user.is_empty() && !host.is_empty() && !username_is_placeholder(&user) {
        return format!("@{user}@{host}");
    }
    if !host.is_empty() {
        let base = actor.trim_end_matches('/').rsplit('/').next().unwrap_or("");
        if !base.is_empty() { format!("@{base}@{host}") } else { host.to_owned() }
    } else {
        actor.to_owned()
    }
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
    if f.wafrn_known {
        let ask_from = if f.from.is_empty() { "remote_profile" } else { f.from.as_str() };
        body.push_str(&format!(
            "<a class=\"menu-action\" href=\"?view=ask&amp;target_actor={}&amp;from={}\">Ask</a>",
            esc(&query(&f.actor)), esc(&query(ask_from))
        ));
    }
    let ask_from = if f.from.is_empty() { "remote_profile" } else { f.from.as_str() };
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
        let kind = f.server_kind.as_str();
        if kind == "mute" {
            let id = f.server_id.unwrap_or(0);
            body.push_str(&format!("<form method=\"post\" action=\"?view=blocks\">{csrf}<input type=\"hidden\" name=\"action\" value=\"unblock\"><input type=\"hidden\" name=\"id\" value=\"{id}\"><button class=\"menu-action\" type=\"submit\">Remove user mute (server)</button></form>"));
        } else {
            body.push_str(&format!("<form method=\"post\" action=\"?view=blocks\" onsubmit=\"return confirm('Mute this user for everyone on Vaak?');\">{csrf}<input type=\"hidden\" name=\"action\" value=\"block_actor\"><input type=\"hidden\" name=\"kind\" value=\"mute\">{}<button class=\"menu-action\" type=\"submit\">Mute this user (server)</button></form>", hidden("actor_id", &f.actor)));
        }
        if kind == "block" || kind == "suspend" {
            let id = f.server_id.unwrap_or(0);
            body.push_str(&format!("<form method=\"post\" action=\"?view=blocks\">{csrf}<input type=\"hidden\" name=\"action\" value=\"unblock\"><input type=\"hidden\" name=\"id\" value=\"{id}\"><button class=\"menu-action\" type=\"submit\">Remove user block (server)</button></form>"));
        } else {
            let confirm = esc(&format!("\"{SERVER_BLOCK}\""));
            body.push_str(&format!("<form method=\"post\" action=\"?view=blocks\" onsubmit=\"return confirm({confirm});\">{csrf}<input type=\"hidden\" name=\"action\" value=\"block_actor\"><input type=\"hidden\" name=\"kind\" value=\"block\">{}<button class=\"menu-action danger\" type=\"submit\">Block this user (server)</button></form>", hidden("actor_id", &f.actor)));
        }
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

fn open_link(f: &Facts) -> String {
    format!("<a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">Open on Bluesky</a>", esc(&f.actor))
}

fn open_links(w: &mut W, f: &Facts, menu_html: Option<&str>) {
    let link = open_link(f);
    if let Some(menu_html) = menu_html {
        w.text(menu_html);
        w.indent(62);
        w.text(&link);
        w.out.push('\n');
    } else {
        w.line(146, &link);
    }
    w.line(42, "</div>");
}

fn tabs(w: &mut W, f: &Facts) {
    let defs = [
        ("posts", "Posts", f.item_posts),
        ("replies", "Replies", f.item_replies),
        ("boosts", "Boosts", f.item_boosts),
        ("media", "Media", f.item_media),
    ];
    let prefix = if let Some(prefix) = &f.tab_href {
        prefix.clone()
    } else {
        format!("?view=remote_profile&actor={}&from={}&tab=", query(&f.actor), query(&f.from))
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
    w.line(14, &format!("<a class=\"btn btn-ghost\" href=\"{}&amp;refresh=1\" title=\"Queue a background profile and post refresh\">↻ Refresh profile</a>", profile_action(&f.actor, &f.from)));
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
    w.text(&format!("<div class=\"who\">{who}</div>"));
    w.out.push('\n');
    let handle = esc(&visible_handle(f));
    w.indent(18);
    w.text(&format!("<div class=\"meta\">{handle}"));
    w.indent(20);
    w.indent(20);
    w.text("<span class=\"tag\" style=\"margin-left:.35rem\" title=\"Bluesky / AT Protocol\">Bluesky</span>");
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
        w.line(14, "<div class=\"meta\" style=\"margin-top:.75rem\">No bio is cached yet; profile details are being refreshed in the background.</div>");
        w.indent(12);
    }
    let fields: Vec<&Field> = f.fields.iter().filter(|field| !field.name.trim().is_empty()).collect();
    if !fields.is_empty() {
        w.indent(48);
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
        w.line(48, "<div class=\"tweet-actions remote-profile-actions\" style=\"flex-wrap:wrap;align-items:center;gap:.45rem\">");
    } else {
        w.indent(72);
        w.line(12, "<div class=\"tweet-actions remote-profile-actions\" style=\"flex-wrap:wrap;align-items:center;gap:.45rem\">");
    }
    actions(&mut w, f);
    w.line(10, "</article>");
    w.line(10, "<nav class=\"remote-profile-tabs\" aria-label=\"Profile posts\">");
    tabs(&mut w, f);
    w.finish()
}

fn actions(w: &mut W, f: &Facts) {
    if !f.is_own {
        w.indent(14);
        w.line(16, "<span class=\"remote-profile-follow-inline\">");
        follow_form(w, f);
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
        if f.muted {
            w.indent(14);
            w.text("<span class=\"tag\" title=\"Hidden from your timelines &amp; notifications\">muted for me</span>");
        } else {
            w.indent(14);
        }
        if f.blocked_personal {
            w.indent(14);
            w.text("<span class=\"tag\" style=\"color:var(--danger)\" title=\"Personal block — hidden from your timelines only\">blocked for me</span>");
        } else {
            w.indent(14);
        }
        if f.blocked_server {
            w.indent(14);
            w.text("<span class=\"tag\" style=\"color:var(--danger)\" title=\"Server-wide block\">blocked server-wide</span>");
        } else {
            w.indent(14);
        }
        w.indent(58);
        let menu_html = menu(f);
        open_links(w, f, Some(&menu_html));
    } else {
        open_links(w, f, None);
    }
}

fn is_bsky_actor(actor: &str) -> bool {
    let lower = actor.to_ascii_lowercase();
    let Some(rest) = lower.strip_prefix("https://bsky.app/profile/") else { return false };
    !rest.is_empty() && !rest.contains('/') && !rest.contains('?') && !rest.contains('#')
}

fn visible_handle(f: &Facts) -> String {
    if let Some(text) = &f.handle_text {
        if !text.is_empty() { return text.clone(); }
    }
    if !f.bsky_handle.is_empty() { return format!("@{}", f.bsky_handle); }
    actor_handle(&f.actor, &f.username)
}

fn load(input: &Value) -> Result<Facts, String> {
    let actor = text(input, "actor").trim().trim_end_matches('/').to_owned();
    if !is_bsky_actor(&actor) { return Err("bluesky profile actor".into()); }
    let display = input.get("display_name").and_then(Value::as_str).unwrap_or("");
    let display_present = !display.is_empty() && display != "0";
    let fields = input.get("fields").and_then(Value::as_array).map(|rows| {
        rows.iter().filter_map(|row| {
            let name = text(row, "name").to_owned();
            let raw = text(row, "value");
            if name.trim().is_empty() || raw.trim().is_empty() { return None; }
            let value = field_html(raw);
            if value.trim().is_empty() { return None; }
            let verified = row.get("verified_at").and_then(Value::as_str).unwrap_or("").to_owned();
            Some(Field { name, value, verified })
        }).collect()
    }).unwrap_or_default();
    let emoji = input.get("emoji").and_then(Value::as_object).map(|map| {
        map.iter().filter_map(|(k, v)| v.as_str().map(|url| (k.clone(), url.to_owned()))).collect()
    }).unwrap_or_default();
    let server = input.get("server_control").and_then(Value::as_object);
    Ok(Facts {
        actor, from: text(input, "from").to_owned(), tab: text(input, "tab").to_owned(),
        tab_href: maybe_text(input, "tab_href"),
        display_name: display.to_owned(), display_present, username: text(input, "username").to_owned(),
        bio: text(input, "bio").to_owned(), avatar: maybe_text(input, "avatar"), banner: maybe_text(input, "banner"),
        fallback_avatar: maybe_text(input, "fallback_avatar").unwrap_or_else(|| FALLBACK_AVATAR.to_owned()),
        bare_src: maybe_text(input, "bare_src"),
        emoji, display_html: maybe_text(input, "display_html"), handle_text: maybe_text(input, "handle"),
        bsky_handle: text(input, "bsky_handle").to_owned(),
        is_own: flag(input, "is_own"), following: flag(input, "following"), follows_you: flag(input, "follows_you"),
        requested: flag(input, "requested"), locked: flag(input, "locked"), muted: flag(input, "muted"),
        blocked_personal: flag(input, "blocked_personal"), blocked_server: flag(input, "blocked_server"),
        personal_block_id: input.get("personal_block_id").and_then(Value::as_i64),
        deprioritized: flag(input, "deprioritized"), target_is_admin: flag(input, "target_is_admin"),
        viewer_is_admin: flag(input, "viewer_is_admin"), wafrn_known: flag(input, "wafrn_known"),
        server_id: server.and_then(|row| row.get("id")).and_then(Value::as_i64),
        server_kind: server.and_then(|row| row.get("kind")).and_then(Value::as_str).unwrap_or("").to_owned(),
        csrf: text(input, "csrf").to_owned(), fields,
        item_posts: num(input, "item_posts"), item_replies: num(input, "item_replies"),
        item_boosts: num(input, "item_boosts"), item_media: num(input, "item_media"),
    })
}

pub(crate) fn paint_bsky(input: &Value) -> Result<RemoteChrome, String> {
    let facts = load(input)?;
    let page_back = page_back(&facts);
    let header = header(&facts);
    let chrome = format!("{page_back}{header}");
    Ok(RemoteChrome { page_back, header, chrome, secondary: None })
}

pub(crate) async fn render(_cfg: &crate::config::Config, headers: HeaderMap, bytes: Bytes) -> Response {
    let Some(secret) = crate::profile_local::secret() else { return StatusCode::NOT_FOUND.into_response() };
    if !crate::profile_local::authorized(&headers, &secret) { return StatusCode::UNAUTHORIZED.into_response(); }
    if bytes.len() > 256 * 1024 { return (StatusCode::BAD_REQUEST, axum::Json(json!({"fallback": true}))).into_response(); }
    let input: Value = match serde_json::from_slice(&bytes) { Ok(value) => value, Err(_) => return (StatusCode::BAD_REQUEST, axum::Json(json!({"fallback": true}))).into_response() };
    if input.get("protocol_version").and_then(Value::as_u64) != Some(1) {
        return (StatusCode::BAD_REQUEST, axum::Json(json!({"fallback": true}))).into_response();
    }
    let painted = match paint_bsky(&input) { Ok(painted) => painted, Err(_) => return (StatusCode::UNPROCESSABLE_ENTITY, axum::Json(json!({"fallback": true}))).into_response() };
    let binding = json!({"actor": input.get("actor").and_then(Value::as_str).unwrap_or("").trim().trim_end_matches('/'), "tab": text(&input, "tab"), "from": text(&input, "from")});
    let body = json!({"ok": true, "protocol_version": 1, "request_binding": binding, "chrome": painted.chrome, "page_back": painted.page_back, "header": painted.header, "secondary": Value::Null});
    let bytes = match serde_json::to_vec(&body) { Ok(bytes) => bytes, Err(_) => return (StatusCode::UNPROCESSABLE_ENTITY, axum::Json(json!({"fallback": true}))).into_response() };
    if bytes.len() > 2 * 1024 * 1024 { return (StatusCode::UNPROCESSABLE_ENTITY, axum::Json(json!({"fallback": true}))).into_response(); }
    (StatusCode::OK, [(axum::http::header::CONTENT_TYPE, "application/json"), (axum::http::header::CACHE_CONTROL, "no-store")], bytes).into_response()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn capture() -> Value {
        let file = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/profile-bsky-chrome-capture.php");
        let output = std::process::Command::new(file.parent().unwrap().join("php-policy-test")).arg(&file).output().unwrap();
        assert!(output.status.success(), "{}", String::from_utf8_lossy(&output.stderr));
        serde_json::from_slice(&output.stdout).unwrap()
    }

    fn mismatch(actual: &str, expected: &str) -> String {
        let left = actual.as_bytes();
        let right = expected.as_bytes();
        let mut at = 0;
        while at < left.len() && at < right.len() && left[at] == right[at] { at += 1; }
        let start = at.saturating_sub(80);
        format!("at {at}\nrust {:?}\nphp  {:?}", &actual[start..actual.len().min(at + 160)], &expected[start..expected.len().min(at + 160)])
    }

    #[test]
    fn bsky_chrome_matches_php() {
        let captured = capture();
        let cases = captured["cases"].as_array().unwrap();
        let mut seen = Vec::new();
        for case in cases {
            let id = text(case, "id");
            seen.push(id.to_owned());
            let painted = paint_bsky(&case["input"]).unwrap_or_else(|err| panic!("{id}: {err}"));
            assert!(painted.secondary.is_none(), "{id} secondary");
            assert_eq!(format!("{}{}", painted.page_back, painted.header), painted.chrome);
            assert_eq!(painted.chrome, text(case, "chrome"), "{id} chrome {}", mismatch(&painted.chrome, text(case, "chrome")));
        }
        for id in ["peer", "own", "follow_back", "following", "cold", "fields_emoji", "muted_blocked", "admin_open", "admin_target", "admin_mute", "admin_block", "admin_suspend", "tabs", "handle_fallback", "wafrn"] {
            assert!(seen.iter().any(|seen| seen == id), "missing {id}");
        }
    }

    #[test]
    fn bsky_route_rejects_local_and_activitypub_actors() {
        assert!(paint_bsky(&json!({"actor": "https://mkultra.monster/users/ada", "bsky_handle": "ada.bsky.social"})).is_err());
        assert!(paint_bsky(&json!({"actor": "https://remote.example/users/ada", "bsky_handle": "ada.bsky.social"})).is_err());
        assert!(paint_bsky(&json!({"actor": "https://bsky.app/profile/alice.bsky.social/post/abc", "bsky_handle": "alice.bsky.social"})).is_err());
        assert!(paint_bsky(&json!({"actor": "https://bsky.app/profile/alice.bsky.social", "bsky_handle": "alice.bsky.social", "display_name": "Ada"})).is_ok());
    }
}
