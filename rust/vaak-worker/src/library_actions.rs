//! Native Library action markup with an explicit authenticated-owner context.
//! Writes continue through the existing PHP handlers. This context is resolved
//! from authoritative owner state, never accepted from an untrusted status.

use anyhow::{bail, Result};
use serde::Serialize;

#[derive(Debug, Clone, Default, Serialize)]
pub struct Counts {
    pub favourites: i64,
    pub reblogs: i64,
    pub quotes: i64,
    pub bookmarks: i64,
}

#[derive(Debug, Clone, Default, Serialize)]
pub struct ModerationContext {
    pub is_self: bool,
    pub is_local: bool,
    pub is_local_admin: bool,
    pub is_wafrn: bool,
    pub is_admin: bool,
    pub personal_block_id: Option<i64>,
    pub muted: bool,
    pub deprioritized: bool,
    pub global_kind: String,
    pub global_id: i64,
}

#[derive(Debug, Clone, Serialize)]
pub struct FediActionContext {
    pub owner_id: i64,
    pub status_id: String,
    pub object_id: String,
    pub actor_id: String,
    pub remote_url: String,
    pub return_view: String,
    /// Authoritative canonical mention seeds, including other reply participants.
    pub reply_mentions: Vec<String>,
    pub spoiler: String,
    pub sensitive: bool,
    pub favourited: bool,
    pub bookmarked: bool,
    pub boosted: bool,
    pub counts: Counts,
    pub csrf: String,
    pub moderation: ModerationContext,
}

pub fn esc(s: &str) -> String {
    s.replace('&', "&amp;").replace('<', "&lt;").replace('>', "&gt;").replace('"', "&quot;").replace('\'', "&#039;")
}

/// PHP rawurlencode (RFC 3986), including uppercase hex and UTF-8 bytes.
pub fn query(s: &str) -> String {
    let mut out = String::new();
    for byte in s.as_bytes() {
        if byte.is_ascii_alphanumeric() || b"-_.~".contains(byte) { out.push(*byte as char); }
        else { out.push_str(&format!("%{byte:02X}")); }
    }
    out
}

pub fn compact_count(n: i64) -> String {
    if n < 1 { String::new() }
    else if n < 1000 { n.to_string() }
    else if n < 10000 { let tenth = n / 100; if tenth % 10 > 0 { format!("{}.{}k", tenth / 10, tenth % 10) } else { format!("{}k", tenth / 10) } }
    else if n < 1000000 { format!("{}k", n / 1000) }
    else { let tenth = n / 100000; if tenth % 10 > 0 { format!("{}.{}M", tenth / 10, tenth % 10) } else { format!("{}M", tenth / 10) } }
}

fn count_html(n: i64) -> String {
    let label = compact_count(n);
    if label.is_empty() { String::new() } else { format!("<span class=\"action-count\" aria-hidden=\"true\">{label}</span>") }
}

fn hidden(name: &str, value: &str) -> String {
    format!("<input type=\"hidden\" name=\"{name}\" value=\"{}\">", esc(value))
}

/// `#announce-{eventId}` names the boost wrapper, not the original post.
pub(crate) fn strip_announce_fragment(value: &str) -> &str {
    let value = value.trim();
    let Some(hash) = value.rfind('#') else { return value };
    let Some(rest) = value[hash + 1..].strip_prefix("announce-") else { return value };
    if !rest.is_empty() && rest.bytes().all(|byte| byte.is_ascii_digit()) { &value[..hash] } else { value }
}

fn open_target(object:&str)->String {
    let trimmed = object.trim();
    let stripped = strip_announce_fragment(trimmed);
    let object = if stripped.len() != trimmed.len() { stripped.trim_end_matches('/') } else { stripped };
    object.strip_prefix("at://").and_then(|rest|rest.split_once("/app.bsky.feed.post/"))
        .map(|(did,key)|format!("https://bsky.app/profile/{did}/post/{key}")).unwrap_or_else(||object.to_owned())
}
fn status_href(object: &str, view: &str) -> String {
    format!("?view=status&object={}&from={}", query(&open_target(object)), query(view))
}

/// Canonical in-app URL for the overflow Copy link. Unsafe schemes are omitted.
pub(crate) fn copy_link_button(object: &str, view: &str) -> String {
    let object = strip_announce_fragment(object.trim());
    if object.is_empty() { return String::new(); }
    let lower = object.to_ascii_lowercase();
    if lower.starts_with("javascript:") || lower.starts_with("data:") || lower.starts_with("vbscript:") {
        return String::new();
    }
    let target = open_target(object);
    if !target.starts_with("https://") || target == "https://bsky.app/" { return String::new(); }
    let view = if view.is_empty() { "home" } else { view };
    let href = format!("https://mkultra.monster/vaak/{}", status_href(object, view));
    format!("<button type=\"button\" class=\"menu-action\" data-copy-value=\"{}\" title=\"Copy link to post\">Copy link</button>", esc(&href))
}

pub fn reply_suffix(mentions: &[String], spoiler: &str, sensitive: bool) -> String {
    let mut out = String::new();
    let seeds: Vec<&str> = mentions.iter().map(|s| s.trim().trim_start_matches('@')).filter(|s| !s.is_empty()).collect();
    if !seeds.is_empty() { out.push_str(&format!("&mention={}", query(&seeds.join(",")))); }
    let spoiler = super::card::php_trim(spoiler);
    if !spoiler.is_empty() { out.push_str(&format!("&cw={}", query(&spoiler.chars().take(500).collect::<String>()))); }
    if sensitive || !spoiler.is_empty() { out.push_str("&sensitive=1"); }
    out
}

/// Cache-independent fallback used after server-derived reply participants.
/// The page assembler supplies cached actor handles when the canonical PHP
/// actor label resolver would override the URL path fallback.
pub(crate) fn reply_mention_handle(actor: &str, hint: &str, owner_actor: &str) -> String {
    let actor = actor.trim_end_matches('/');
    let hint = hint.trim().trim_start_matches('@');
    let mut handle = if !hint.is_empty() && hint != "?" {
        if actor.starts_with("https://mkultra.monster/users/") { hint.strip_suffix("@mkultra.monster").unwrap_or(hint).to_owned() }
        else { hint.to_owned() }
    } else {
        if actor.is_empty() || actor == owner_actor.trim_end_matches('/') || actor.starts_with("https://mkultra.monster/users/") { return String::new(); }
        let Ok(url) = reqwest::Url::parse(actor) else { return actor.to_owned(); };
        let host = url.host_str().unwrap_or("");
        let name = url.path().trim_end_matches('/').rsplit('/').next().unwrap_or("");
        if host == "bsky.app" && url.path().starts_with("/profile/") && !crate::hidden::raw_percent_decode(name).starts_with("did:") { crate::hidden::raw_percent_decode(name) }
        else if name.is_empty() { host.to_owned() } else { format!("{name}@{host}") }
    };
    if let Some(bsky) = handle.strip_suffix("@bsky.app").filter(|name| name.contains('.')) { handle = bsky.to_lowercase(); }
    handle
}

/// Full canonical remote Fediverse action branch. Canonical PHP forms omit
/// inline CSRF for its JS-mediated engagement actions; PHP's existing submit
/// interception supplies it and the handler rejects missing tokens. Privacy
/// and moderation menu forms explicitly carry the authenticated CSRF token.
pub fn render_fedi_actions(ctx: &FediActionContext, authenticated_owner: i64) -> Result<String> {
    if authenticated_owner < 1 || ctx.owner_id != authenticated_owner { bail!("Library action owner mismatch"); }
    if !matches!(ctx.return_view.as_str(), "bookmarks" | "favourites" | "search") { bail!("unsupported admitted return context"); }
    render_actions_core(ctx,None,true)
}

pub(crate) fn render_direct_actions(ctx:&FediActionContext,owner:i64,_show_open:bool,profile_actor:&str)->Result<String>{
    if owner<1||ctx.owner_id!=owner{bail!("direct action owner mismatch")}
    if ctx.return_view.is_empty()||ctx.return_view.len()>64||!ctx.return_view.bytes().all(|b|b.is_ascii_lowercase()||b==b'_'){bail!("invalid direct action origin")}
    render_actions_core(ctx,Some(profile_actor),false)
}

fn render_actions_core(ctx:&FediActionContext,direct:Option<&str>,_show_open:bool)->Result<String>{
    if ctx.moderation.is_self { bail!("own posts require the canonical own-post action branch"); }
    let view = esc(&ctx.return_view);
    let mut action_base = format!("?view={view}");
    if let Some(profile_actor)=direct {
        if ctx.return_view=="status" {action_base.push_str(&format!("&amp;object={}&amp;from=search",query(&ctx.object_id)));}
        else if ctx.return_view=="remote_profile" {let actor=if profile_actor.is_empty(){ctx.actor_id.as_str()}else{profile_actor};if !actor.is_empty(){action_base.push_str(&format!("&amp;actor={}",query(actor)));}}
    }
    let uri = &ctx.object_id;
    let sid = &ctx.status_id;
    let actor = &ctx.actor_id;
    let mut html = String::from("<div class=\"tweet-actions\">");
    if !uri.is_empty() {
        let actor_to = if actor.is_empty() { String::new() } else { format!("&amp;to={}", query(actor)) };
        html.push_str(&format!("<a class=\"icon-btn\" href=\"?view={view}&amp;compose=1&amp;reply_to={}{}{}\" title=\"Reply\" aria-label=\"Reply\"><i class=\"ph ph-arrow-bend-up-left\" aria-hidden=\"true\"></i></a>", query(&open_target(uri)), actor_to, reply_suffix(&ctx.reply_mentions, &ctx.spoiler, ctx.sensitive)));
    }
    if !sid.is_empty() && !uri.is_empty() {
        let qt = ctx.counts.quotes;
        html.push_str(&format!("<a class=\"icon-btn{}\" href=\"?view={view}&amp;compose=1&amp;quote_object={}&amp;quote_status_id={}\" data-eng=\"1\" data-eng-count=\"{qt}\" title=\"Quote\" aria-label=\"Quote{}\"><i class=\"ph ph-quotes\" aria-hidden=\"true\"></i>{}</a>", if qt > 0 { " has-count" } else { "" }, query(uri), query(sid), if qt > 0 { format!(" ({})", compact_count(qt)) } else { String::new() }, count_html(qt)));
        html.push_str(&engagement_form(ctx, &action_base, "boost"));
        html.push_str(&format!("<form method=\"post\" action=\"{action_base}\" style=\"display:inline\" onsubmit=\"return confirm('Bite this post?');\">{}{}{}{}<button class=\"icon-btn\" type=\"submit\" title=\"Bite (Wafrn)\" aria-label=\"Bite\"><i class=\"ph ph-tooth\" aria-hidden=\"true\"></i></button></form>", hidden("action", "bite_remote"), hidden("return_view", &ctx.return_view), hidden("bite_kind", "post"), hidden("target", uri)));
        html.push_str(&engagement_form(ctx, &action_base, "favourite"));
        let label = if ctx.bookmarked { "Bookmark folders" } else { "Bookmark" };
        html.push_str(&format!("<form method=\"post\" action=\"{action_base}\" style=\"display:inline\">{}{}{}{}<button class=\"icon-btn{}\" type=\"submit\" title=\"{label}\" aria-label=\"{label}\" data-bm-picker=\"{}\"><i class=\"ph{} ph-bookmark-simple\" aria-hidden=\"true\"></i></button></form>", hidden("action", if ctx.bookmarked { "unbookmark_status" } else { "bookmark_status" }), hidden("return_view", &ctx.return_view), hidden("status_id", sid), hidden("object_id", uri), if ctx.bookmarked { " on" } else { "" }, u8::from(ctx.bookmarked), if ctx.bookmarked { "-fill" } else { "" }));
    }
    let protocol_open = if direct.is_none() { Some(_show_open) } else { None };
    html.push_str(&render_moderation_with_protocol_navigation(ctx, &ctx.return_view, protocol_open));
    html.push_str("</div>");
    Ok(html)
}

fn engagement_form(ctx: &FediActionContext, action_base: &str, kind: &str) -> String {
    let (on, action, label, icon, count) = if kind == "boost" {
        (ctx.boosted, if ctx.boosted { "unreblog_status" } else { "reblog_status" }, if ctx.boosted { "Undo boost" } else { "Boost" }, "ph-repeat", ctx.counts.reblogs)
    } else {
        (ctx.favourited, if ctx.favourited { "unfavourite_status" } else { "favourite_status" }, if ctx.favourited { "Unlike" } else { "Like" }, "ph-heart", ctx.counts.favourites)
    };
    format!("<form method=\"post\" action=\"{action_base}\" style=\"display:inline\">{}{}{}{}{}<button class=\"icon-btn{}{}\" type=\"submit\" data-eng=\"1\" data-eng-count=\"{count}\" title=\"{label}\" aria-label=\"{label}{}\"><i class=\"ph{} {icon}\" aria-hidden=\"true\"></i>{}</button></form>", hidden("action", action), hidden("return_view", &ctx.return_view), hidden("status_id", &ctx.status_id), hidden("object_id", &ctx.object_id), hidden("target_actor", &ctx.actor_id), if on { " on" } else { "" }, if count > 0 { " has-count" } else { "" }, if count > 0 { format!(" ({})", compact_count(count)) } else { String::new() }, if on && kind == "favourite" { "-fill" } else { "" }, count_html(count))
}

fn moderation_form(ctx: &FediActionContext, action: &str, field: &str, value: &str, label: &str, title: &str, return_from:&str) -> String {
    format!("<form method=\"post\" action=\"?view={}\">{}{}{}{}{}{}<button class=\"menu-action\" type=\"submit\"{}>{label}</button></form>", esc(&ctx.return_view), hidden("csrf", &ctx.csrf), hidden("action", action), hidden("return_view", &ctx.return_view), hidden("return_from", return_from), hidden("return_actor", ctx.actor_id.trim_end_matches('/')), hidden(field, value), if title.is_empty() { String::new() } else { format!(" title=\"{}\"", esc(title)) })
}

pub(crate) fn render_moderation(ctx: &FediActionContext) -> String {render_moderation_with_from(ctx,&ctx.return_view)}

pub(crate) fn render_moderation_with_from(ctx: &FediActionContext,return_from:&str) -> String {
    render_moderation_with_protocol_navigation(ctx, return_from, None)
}

pub(crate) fn render_moderation_with_protocol_navigation(ctx: &FediActionContext,return_from:&str,protocol_open:Option<bool>) -> String {
    let link_from=if return_from.is_empty(){&ctx.return_view}else{return_from};
    let actor = ctx.actor_id.trim_end_matches('/');
    let state = &ctx.moderation;
    if state.is_self { return String::new(); }
    let mut menu = String::new();
    let lower = actor.to_lowercase();
    let fedi = actor.starts_with("https://") && !lower.contains("bsky.app") && !lower.starts_with("https://bsky.");
    if fedi && state.personal_block_id.is_none() { menu.push_str(&format!("<a class=\"menu-action\" href=\"?view=dms&amp;peer={}\">DM</a>", query(actor))); }
    if state.is_local || state.is_wafrn { menu.push_str(&format!("<a class=\"menu-action\" href=\"?view=ask&amp;target_actor={}&amp;from={}\">Ask</a>", query(actor), query(link_from))); }
    if ctx.object_id.starts_with("https://") {
        menu.push_str(&format!("<a class=\"menu-action\" href=\"{}\">Open</a><a class=\"menu-action\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">{}</a>", esc(&status_href(&ctx.object_id, &ctx.return_view)), esc(if ctx.remote_url.starts_with("https://"){&ctx.remote_url}else{&ctx.object_id}), if ctx.object_id.to_lowercase().contains("bsky.app") { "Open on Bluesky" } else { "Remote" }));
        menu.push_str(&copy_link_button(&ctx.object_id, &ctx.return_view));
    }
    if !ctx.object_id.is_empty() && !ctx.object_id.starts_with("https://") {
        if let Some(show_open) = protocol_open {
            if show_open { menu.push_str(&format!("<a class=\"menu-action\" href=\"{}\">Open</a>", esc(&status_href(&ctx.object_id, &ctx.return_view)))); }
            if ctx.remote_url.starts_with("https://") || ctx.remote_url.starts_with("http://") { menu.push_str(&format!("<a class=\"menu-action\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">Remote</a>", esc(&ctx.remote_url))); }
            menu.push_str(&copy_link_button(&ctx.object_id, &ctx.return_view));
        }
    }
    if actor.starts_with("https://") {
        if state.is_local_admin {
            menu.push_str("<span class=\"menu-action menu-action-disabled\" aria-disabled=\"true\" title=\"Administrators cannot be blocked\">Admin account — cannot be blocked</span>");
        } else if let Some(id) = state.personal_block_id {
            menu.push_str(&moderation_form(ctx, "user_block_remove", "id", &id.to_string(), "Unblock", "Hide from your timelines only",return_from));
        } else {
            menu.push_str(&moderation_form(ctx, "user_block_add", "target", actor, "Block", "Hide from your timelines only",return_from));
        }
        menu.push_str(&moderation_form(ctx, if state.muted { "unmute_remote" } else { "mute_remote" }, "actor_id", actor, if state.muted { "Unmute" } else { "Mute" }, "Hide from your Home / Federated / Notifications",return_from));
        menu.push_str(&moderation_form(ctx, if state.deprioritized { "undeprioritize_remote" } else { "deprioritize_remote" }, "actor_id", actor, if state.deprioritized { "Stop deprioritizing" } else { "Deprioritize on Home" }, "",return_from));
        menu.push_str(&format!("<a class=\"menu-action\" href=\"?view=report&amp;report_target={}{}&amp;from={}\">Report user</a>", query(actor), if ctx.object_id.starts_with("http") { format!("&amp;report_object={}",query(&ctx.object_id)) } else { String::new() }, query(link_from)));
    }
    if state.is_admin && !actor.is_empty() {
        if !menu.is_empty() { menu.push_str("<div class=\"menu-action-sep\" role=\"separator\" aria-hidden=\"true\"></div>"); }
        if !state.is_local {
            for kind in ["mute", "block"] {
                let active = state.global_kind == kind || (kind == "block" && state.global_kind == "suspend");
                if active {
                    menu.push_str(&format!("<form method=\"post\" action=\"?view=blocks\">{}{}{}<button class=\"menu-action\" type=\"submit\">Remove user {kind} (server)</button></form>", hidden("csrf", &ctx.csrf), hidden("action", "unblock"), hidden("id", &state.global_id.to_string())));
                } else {
                    let confirm = if kind == "mute" { "return confirm('Mute this user for everyone on Vaak?');".to_owned() } else {
                        let message = if lower.contains("bsky.app/profile/") || lower.starts_with("did:") {
                            "Block this Bluesky user server-wide on VAAK? Everyone on this instance will stop seeing them. This also blocks them for you personally (and syncs that personal block to your Bluesky account if connected). Other VAAK users’ Bluesky blocklists are left alone."
                        } else {
                            "Block this user server-wide? VAAK will reject their federation (HTTP 403) and stop delivering to them."
                        };
                        format!("return confirm({});", esc(&serde_json::to_string(message).unwrap()))
                    };
                    menu.push_str(&format!("<form method=\"post\" action=\"?view=blocks\" onsubmit=\"{confirm}\">{}{}{}{}<button class=\"menu-action{}\" type=\"submit\">{} this user (server)</button></form>", hidden("csrf", &ctx.csrf), hidden("action", "block_actor"), hidden("kind", kind), hidden("actor_id", actor), if kind == "block" { " danger" } else { "" }, if kind == "block" { "Block" } else { "Mute" }));
                }
            }
        }
        menu.push_str(&format!("<a class=\"menu-action\" href=\"/vaak/?view=moderation&amp;actor={}&amp;from={}\">Open in moderation panel</a>", query(actor), query(&ctx.return_view)));
    }
    if menu.is_empty() { String::new() } else { if menu.is_empty(){return String::new()}
 format!("<details class=\"post-action-menu\"><summary class=\"icon-btn\" title=\"More actions\" aria-label=\"More actions\">⋯</summary><div class=\"post-action-menu__body\">{menu}</div></details>") }
}

#[cfg(test)]
mod tests {
    #[test]
    fn reply_fallback_matches_corrected_actual_php_helper() {
        let file = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/migration-library-component-capture.php");
        let output = std::process::Command::new(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/php-policy-test")).arg(file).output().unwrap();
        assert!(output.status.success(), "{}", String::from_utf8_lossy(&output.stderr));
        let reference: serde_json::Value = serde_json::from_slice(&output.stdout).unwrap();
        for fixture in reference["reply"].as_array().unwrap() {
            let result = super::reply_mention_handle(fixture["actor"].as_str().unwrap(),fixture["hint"].as_str().unwrap(),"https://mkultra.monster/users/fixture");
            assert_eq!(format!("@{result}"),fixture["handle"].as_str().unwrap(),"actual PHP corrected reply fallback {:?}",fixture);
        }
    }
    use super::*;
    fn context() -> FediActionContext {
        FediActionContext { owner_id: 1, status_id: "123".into(), object_id: "https://remote.test/users/alice/statuses/123".into(), actor_id: "https://remote.test/users/alice".into(), remote_url: "https://remote.test/@alice/123".into(), return_view: "bookmarks".into(), reply_mentions: vec!["alice@remote.test".into()], spoiler: "".into(), sensitive: false, favourited: false, bookmarked: false, boosted: false, counts: Counts { favourites: 3, reblogs: 2, ..Default::default() }, csrf: "fixture-csrf-token".into(), moderation: ModerationContext::default() }
    }
    #[test]
    fn protocol_navigation_shares_one_moderation_menu() {
        let mut ctx = context();
        ctx.object_id = "at://did:plc:fixture/app.bsky.feed.post/example".into();
        ctx.actor_id = "https://bsky.app/profile/dame.is".into();
        ctx.remote_url = "https://bsky.app/profile/dame.is/post/example".into();
        for view in ["home", "status"] {
            ctx.return_view = view.into();
            for show_open in [true, false] {
                let html = render_moderation_with_protocol_navigation(&ctx, view, Some(show_open));
                assert_eq!(html.matches("<details class=\"post-action-menu\">").count(), 1);
                assert_eq!(html.contains(">Open</a>"), show_open);
                assert!(html.contains(">Remote</a>"));
                for action in ["user_block_add", "mute_remote", "deprioritize_remote"] {
                    assert!(html.contains(&format!("name=\"action\" value=\"{action}\"")));
                }
                assert!(html.contains("name=\"csrf\" value=\"fixture-csrf-token\""));
            }
        }
        ctx.remote_url = "javascript:alert(1)".into();
        assert!(!render_moderation_with_protocol_navigation(&ctx, "home", Some(true)).contains("javascript:"));
        ctx.moderation.is_self = true;
        assert!(render_moderation_with_protocol_navigation(&ctx, "home", Some(true)).is_empty());
    }
    #[test]
    fn copy_link_uses_the_original_post_and_skips_unsafe_targets() {
        let mut ctx = context();
        ctx.object_id = "https://remote.test/users/alice/statuses/123#announce-9".into();
        let html = render_fedi_actions(&ctx, 1).unwrap();
        assert!(html.contains(">Copy link</button>"));
        assert!(html.contains("data-copy-value=\"https://mkultra.monster/vaak/?view=status&amp;object=https%3A%2F%2Fremote.test%2Fusers%2Falice%2Fstatuses%2F123&amp;from=bookmarks\""));
        assert!(html.contains("<a class=\"menu-action\" href=\"?view=status&amp;object=https%3A%2F%2Fremote.test%2Fusers%2Falice%2Fstatuses%2F123&amp;from=bookmarks\">Open</a>"));
        ctx.object_id = "javascript:alert(1)".into();
        ctx.actor_id = "https://bsky.app/profile/dame.is".into();
        let html = render_moderation_with_protocol_navigation(&ctx, "home", Some(true));
        assert!(!html.contains("Copy link"));
        assert!(!html.contains("javascript:"));
    }
    #[test]
    fn owner_and_view_are_bound_before_action_paint() {
        assert!(render_fedi_actions(&context(), 2).is_err());
        assert!(render_fedi_actions(&context(), 0).is_err());
        let mut ctx = context(); ctx.return_view = "home\" onclick=\"bad".into();
        assert!(render_fedi_actions(&ctx, 1).is_err());
    }
    #[test]
    fn toggles_preserve_interaction_and_privacy_action_targets() {
        let mut ctx = context(); ctx.favourited = true; ctx.bookmarked = true; ctx.boosted = true;
        ctx.moderation.personal_block_id = Some(7); ctx.moderation.muted = true; ctx.moderation.deprioritized = true;
        let html = render_fedi_actions(&ctx, 1).unwrap();
        for action in ["unfavourite_status", "unbookmark_status", "unreblog_status", "user_block_remove", "unmute_remote", "undeprioritize_remote", "bite_remote"] { assert!(html.contains(&hidden("action", action))); }
        assert!(html.contains(&hidden("status_id", "123"))); assert!(html.contains(&hidden("id", "7")));
        assert!(!html.contains(">DM</a>")); assert!(html.contains("data-bm-picker=\"1\""));
    }
    #[test]
    fn local_admin_and_self_privacy_rules_are_preserved() {
        let mut ctx = context(); ctx.moderation.is_local_admin = true;
        let html = render_fedi_actions(&ctx, 1).unwrap();
        assert!(!html.contains("user_block_add")); assert!(html.contains("Admin account — cannot be blocked"));
        ctx.moderation.is_self = true; assert!(render_fedi_actions(&ctx, 1).is_err());
    }
    #[test]
    fn reply_cw_and_count_format_match_php() {
        assert_eq!(reply_suffix(&[" @a@one ".into(), "@b@two".into()], " warning ", false), "&mention=a%40one%2Cb%40two&cw=warning&sensitive=1");
        for (n, expected) in [(0, ""), (999, "999"), (1000, "1k"), (1999, "1.9k"), (10999, "10k"), (1000000, "1M"), (1999999, "1.9M")] { assert_eq!(compact_count(n), expected); }
    }
    #[test]
    fn plain_action_tree_matches_actual_complete_php_card() {
        let reference = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/migration-card-reference-capture.php");
        let output = std::process::Command::new(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/php-policy-test")).arg(reference).output().expect("PHP required for canonical action comparison");
        assert!(output.status.success(), "{}", String::from_utf8_lossy(&output.stderr));
        let reference: serde_json::Value = serde_json::from_slice(&output.stdout).unwrap();
        let html = reference["captures"]["plain"]["html"].as_str().unwrap();
        let capture = &reference["captures"]["plain"];
        let canonical = &capture["context"]["canonical_remote_link"];
        assert_eq!(canonical["object"], capture["status"]["uri"]);
        assert_eq!(canonical["url_hint"], capture["status"]["url"]);
        // The complete card caller canonicalizes this action-component input.
        // Use the genuine PHP helper capture, never a link parsed from HTML.
        let mut ctx = context();
        assert_eq!(ctx.object_id, canonical["object"].as_str().unwrap());
        ctx.remote_url = canonical["href"].as_str().unwrap().to_owned();
        let begin = html.find("<div class=\"tweet-actions\">").unwrap();
        let end = html.rfind("</article>").unwrap();
        let php_actions = html[begin..end].trim();
        let tokenize = |s: &str| regex::Regex::new(r"<[^>]+>|[^<]+").unwrap().find_iter(s).map(|m| m.as_str().trim().to_owned()).filter(|s| !s.is_empty()).collect::<Vec<_>>();
        assert_eq!(tokenize(&render_fedi_actions(&ctx, 1).unwrap()), tokenize(php_actions));
    }
}

/// Public navigation only; never delegates to authenticated moderation forms.
pub(crate) fn read_only_overflow(uri:&str,view:&str,remote:&str)->String{read_only_overflow_mode(uri,view,remote,true)}
pub(crate) fn read_only_overflow_mode(uri:&str,view:&str,remote:&str,show_open:bool)->String{
 if uri.is_empty(){return String::new()}let mut menu=if show_open{format!("<a class=\"menu-action\" href=\"{}\">Open</a>",esc(&status_href(uri,view)))}else{String::new()};
 if remote.starts_with("https://")||remote.starts_with("http://"){menu.push_str(&format!("<a class=\"menu-action\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">Remote</a>",esc(remote)));}
 menu.push_str(&copy_link_button(uri,view));
 if menu.is_empty(){return String::new()}
 format!("<details class=\"post-action-menu\"><summary class=\"icon-btn\" title=\"More actions\" aria-label=\"More actions\">⋯</summary><div class=\"post-action-menu__body\">{menu}</div></details>")
}
