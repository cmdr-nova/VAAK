//! Mastodon-style self-thread grouping for lean Home/Local/Federated + profile paint.
//!
//! A reply to the author's own local note paints as one `article.tweet.tweet-thread`
//! with the parent ancestor above the tip (instead of a bare reply card).

use serde_json::Value;
use std::collections::{HashMap, HashSet};

/// Status URI/url trimmed (note or remote object).
pub fn status_object_uri(st: &Value) -> String {
    st.get("uri")
        .and_then(|v| v.as_str())
        .or_else(|| st.get("url").and_then(|v| v.as_str()))
        .unwrap_or("")
        .trim()
        .trim_end_matches('/')
        .to_string()
}

/// Account URL for a status permalink that includes the author.
/// Misskey-style `https://host/notes/{id}` has no author in the path, so it
/// returns none instead of treating every note on that host as one person.
fn status_actor_url(uri: &str) -> Option<String> {
    let uri = uri.trim().trim_end_matches('/');
    for marker in ["/notes/", "/statuses/", "/objects/"] {
        if let Some((actor, _)) = uri.rsplit_once(marker) {
            if actor.starts_with("https://") && (actor.contains("/users/") || actor.contains("/@")) {
                return Some(actor.to_string());
            }
        }
    }
    None
}

/// DID of an `at://` Bluesky post. Other collections (lists, starter packs)
/// are not timeline posts, so they do not form a self-thread.
fn at_post_did(uri: &str) -> Option<String> {
    let rest = uri.trim().trim_end_matches('/').strip_prefix("at://")?;
    let (did, tail) = rest.split_once('/')?;
    if !did.starts_with("did:") || !tail.starts_with("app.bsky.feed.post/") {
        return None;
    }
    let rkey = &tail["app.bsky.feed.post/".len()..];
    if rkey.is_empty() || rkey.contains('/') {
        return None;
    }
    Some(did.to_string())
}

/// Handle from `https://bsky.app/profile/{handle}/post/{rkey}`.
fn bsky_https_post_handle(uri: &str) -> Option<String> {
    let uri = uri.trim().trim_end_matches('/');
    let rest = uri.strip_prefix("https://bsky.app/profile/")?;
    let (handle, tail) = rest.split_once("/post/")?;
    if handle.is_empty() || handle.contains('/') || tail.is_empty() || tail.contains('/') {
        return None;
    }
    Some(handle.to_string())
}

fn reply_parent_uri(st: &Value) -> Option<String> {
    st.get("vaak_in_reply_to_url")
        .and_then(|v| v.as_str())
        .map(|s| s.trim().trim_end_matches('/').to_string())
        .filter(|s| s.starts_with("https://") || s.starts_with("at://"))
        .or_else(|| {
            st.get("in_reply_to_id")
                .and_then(|v| v.as_str())
                .map(|s| s.trim().trim_end_matches('/').to_string())
                .filter(|s| s.starts_with("https://") || s.starts_with("at://"))
        })
}

fn bsky_child_did(st: &Value) -> Option<String> {
    st.get("author_did")
        .and_then(|v| v.as_str())
        .map(str::trim)
        .filter(|s| s.starts_with("did:"))
        .map(str::to_string)
        .or_else(|| at_post_did(&status_object_uri(st)))
}

fn bsky_child_handle(st: &Value) -> Option<String> {
    if let Some(handle) = bsky_https_post_handle(&status_object_uri(st)) {
        return Some(handle);
    }
    let bsky = st.get("source").and_then(|v| v.as_str()) == Some("bluesky")
        || bsky_child_did(st).is_some();
    if !bsky {
        return None;
    }
    st.get("account")
        .and_then(|a| a.get("acct"))
        .and_then(|v| v.as_str())
        .map(str::trim)
        .filter(|s| !s.is_empty() && !s.contains('@'))
        .map(str::to_string)
}

/// Parent URL when `st` continues the same author's own status.
pub fn self_reply_parent_uri(st: &Value) -> Option<String> {
    let parent = reply_parent_uri(st)?;
    if let Some(parent_did) = at_post_did(&parent) {
        let same = bsky_child_did(st)
            .as_deref()
            .is_some_and(|did| did == parent_did);
        return same.then_some(parent);
    }
    if let Some(parent_handle) = bsky_https_post_handle(&parent) {
        let same = bsky_child_handle(st)
            .as_deref()
            .is_some_and(|handle| handle.eq_ignore_ascii_case(&parent_handle));
        return same.then_some(parent);
    }
    let child_actor = status_actor_url(&status_object_uri(st))?;
    let parent_actor = status_actor_url(&parent)?;
    if child_actor != parent_actor {
        return None;
    }
    Some(parent)
}

pub enum FeedPaintUnit {
    Single(Value),
    /// Parent above tip (newest tip is the timeline entry).
    Thread { parent: Value, tip: Value },
}

/// Plan paint units: self-reply tips nest under their parent when the parent is
/// available in `statuses` or `extra_parents` (fetched by URI).
pub fn plan_feed_paint_units(
    statuses: &[Value],
    extra_parents: &HashMap<String, Value>,
) -> Vec<FeedPaintUnit> {
    let mut by_uri: HashMap<String, Value> = HashMap::new();
    for st in statuses {
        let uri = status_object_uri(st);
        if !uri.is_empty() {
            by_uri.entry(uri).or_insert_with(|| st.clone());
        }
    }
    for (uri, st) in extra_parents {
        let key = uri.trim().trim_end_matches('/');
        if !key.is_empty() {
            by_uri.entry(key.to_string()).or_insert_with(|| st.clone());
        }
    }

    let mut consumed: HashSet<String> = HashSet::new();
    let mut out: Vec<FeedPaintUnit> = Vec::with_capacity(statuses.len());

    for st in statuses {
        let uri = status_object_uri(st);
        if !uri.is_empty() && consumed.contains(&uri) {
            continue;
        }
        if let Some(parent_uri) = self_reply_parent_uri(st) {
            if let Some(parent) = by_uri.get(&parent_uri).cloned() {
                if !parent_uri.is_empty() {
                    consumed.insert(parent_uri);
                }
                if !uri.is_empty() {
                    consumed.insert(uri);
                }
                out.push(FeedPaintUnit::Thread {
                    parent,
                    tip: st.clone(),
                });
                continue;
            }
        }
        if !uri.is_empty() {
            consumed.insert(uri);
        }
        out.push(FeedPaintUnit::Single(st.clone()));
    }
    out
}

/// Collect parent note URIs for self-replies whose parent is not already in `statuses`.
pub fn missing_self_reply_parent_uris(statuses: &[Value]) -> Vec<String> {
    let present: HashSet<String> = statuses
        .iter()
        .map(status_object_uri)
        .filter(|u| !u.is_empty())
        .collect();
    let mut need = Vec::new();
    let mut seen = HashSet::new();
    for st in statuses {
        if let Some(p) = self_reply_parent_uri(st) {
            if !present.contains(&p) && seen.insert(p.clone()) {
                need.push(p);
            }
        }
    }
    need
}

fn article_inner_html(painted: &str) -> Option<String> {
    let trimmed = painted.trim_start();
    if !trimmed.starts_with("<article") {
        return None;
    }
    let start = trimmed.find('>')? + 1;
    let end = trimmed.rfind("</article>")?;
    if end < start {
        return None;
    }
    Some(trimmed[start..end].to_string())
}

fn article_open_head(painted: &str) -> &str {
    let trimmed = painted.trim_start();
    let Some(rest) = trimmed.strip_prefix("<article") else {
        return "";
    };
    match rest.find('>') {
        Some(end) => &rest[..end],
        None => "",
    }
}

/// Copy one already-escaped attribute off a painted `<article>` open tag.
fn copy_attr(head: &str, name: &str) -> Option<String> {
    let needle = format!("{name}=\"");
    let start = head.find(&needle)?;
    let val_start = start + needle.len();
    let rel_end = head[val_start..].find('"')?;
    Some(format!(
        " {name}=\"{}\"",
        &head[val_start..val_start + rel_end]
    ))
}

/// Identity attrs live on the outer `<article>`. Thread segments keep the
/// inner HTML only, so without this copy the parent id disappears and the
/// next page paints that same post again.
fn copied_identity_attrs(painted: &str) -> String {
    let head = article_open_head(painted);
    let mut out = String::new();
    for name in [
        "data-timeline-key",
        "data-bsky-uri",
        "data-rss-item",
        "data-note-id",
    ] {
        if let Some(attr) = copy_attr(head, name) {
            out.push_str(&attr);
        }
    }
    out
}

/// One timeline/profile card: parent ancestor + tip reply.
pub fn paint_lean_self_thread(
    parent: &Value,
    tip: &Value,
    from: &str,
    viewer_actor: &str,
    paint_card: &dyn Fn(&Value, &str, &str) -> String,
) -> String {
    let parent_html = paint_card(parent, from, viewer_actor);
    let tip_html = paint_card(tip, from, viewer_actor);
    let parent_inner = article_inner_html(&parent_html).unwrap_or_else(|| parent_html.clone());
    let tip_inner = article_inner_html(&tip_html).unwrap_or_else(|| tip_html.clone());
    let parent_attrs = copied_identity_attrs(&parent_html);
    let tip_attrs = copied_identity_attrs(&tip_html);
    // The outer card's id is the tip. The ancestor keeps its own id so a later
    // page that reaches the parent row is recognized as already on screen.
    let tip_key = copy_attr(article_open_head(&tip_html), "data-timeline-key").unwrap_or_default();
    format!(
        "<article class=\"tweet tweet-thread\"{tip_key}>\
<div class=\"tweet-thread-seg tweet-thread-seg--ancestor\"{parent_attrs}>{parent_inner}</div>\
<div class=\"tweet-thread-rail\" aria-hidden=\"true\"></div>\
<div class=\"tweet-thread-seg tweet-thread-seg--tip\"{tip_attrs}>{tip_inner}</div>\
</article>"
    )
}

pub fn paint_feed_units(
    units: &[FeedPaintUnit],
    from: &str,
    viewer_actor: &str,
    paint_card: &dyn Fn(&Value, &str, &str) -> String,
) -> (String, usize) {
    let mut html = String::with_capacity(units.len() * 1600);
    let mut painted = 0usize;
    for unit in units {
        match unit {
            FeedPaintUnit::Single(st) => {
                html.push_str(&paint_card(st, from, viewer_actor));
                painted += 1;
            }
            FeedPaintUnit::Thread { parent, tip } => {
                html.push_str(&paint_lean_self_thread(
                    parent,
                    tip,
                    from,
                    viewer_actor,
                    paint_card,
                ));
                painted += 1;
            }
        }
    }
    (html, painted)
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn note(id: &str, reply_to: Option<&str>) -> Value {
        let mut st = json!({
            "id": id,
            "uri": id,
            "url": id,
            "content": "<p>x</p>",
            "account": {"acct": "cmdr_nova", "uri": "https://mkultra.monster/users/cmdr_nova"},
        });
        if let Some(p) = reply_to {
            st["vaak_in_reply_to_url"] = json!(p);
            st["vaak_self_thread"] = json!(true);
        }
        st
    }

    #[test]
    fn detects_local_self_reply() {
        let parent = "https://mkultra.monster/users/cmdr_nova/notes/aaaa";
        let tip = note(
            "https://mkultra.monster/users/cmdr_nova/notes/bbbb",
            Some(parent),
        );
        assert_eq!(self_reply_parent_uri(&tip).as_deref(), Some(parent));
        let other = note(
            "https://mkultra.monster/users/cmdr_nova/notes/cccc",
            Some("https://example.com/users/x/statuses/1"),
        );
        assert!(self_reply_parent_uri(&other).is_none());
        let masto_parent = "https://mastodon.social/users/ada/statuses/1";
        let masto = note(
            "https://mastodon.social/users/ada/statuses/2",
            Some(masto_parent),
        );
        assert_eq!(self_reply_parent_uri(&masto).as_deref(), Some(masto_parent));
        let other_user = note(
            "https://mastodon.social/users/ada/statuses/2",
            Some("https://mastodon.social/users/bob/statuses/9"),
        );
        assert!(self_reply_parent_uri(&other_user).is_none());
        // Host-only note URLs do not identify the author.
        let misskey = note(
            "https://transfem.social/notes/child",
            Some("https://transfem.social/notes/parent"),
        );
        assert!(self_reply_parent_uri(&misskey).is_none());
    }

    #[test]
    fn thread_keeps_parent_and_tip_keys() {
        let parent = json!({"id": "parent-id"});
        let tip = json!({"id": "tip-id"});
        let paint = |st: &Value, _from: &str, _viewer: &str| {
            let id = st.get("id").and_then(|v| v.as_str()).unwrap_or("");
            format!(
                "<article class=\"tweet\" data-timeline-key=\"{id}\"><div class=\"tweet-body\">{id}</div></article>"
            )
        };
        let html = paint_lean_self_thread(&parent, &tip, "home", "", &paint);
        assert!(
            html.starts_with("<article class=\"tweet tweet-thread\" data-timeline-key=\"tip-id\">"),
            "{html}"
        );
        assert!(
            html.contains(
                "<div class=\"tweet-thread-seg tweet-thread-seg--ancestor\" data-timeline-key=\"parent-id\">"
            ),
            "{html}"
        );
        assert!(
            html.contains(
                "<div class=\"tweet-thread-seg tweet-thread-seg--tip\" data-timeline-key=\"tip-id\">"
            ),
            "{html}"
        );
        assert!(html.contains("<div class=\"tweet-body\">parent-id</div>"), "{html}");
        assert!(html.contains("<div class=\"tweet-body\">tip-id</div>"), "{html}");
    }

    fn bsky_note(uri: &str, did: &str, reply_to: Option<&str>) -> Value {
        let mut st = json!({
            "id": uri,
            "uri": uri,
            "url": uri,
            "author_did": did,
            "source": "bluesky",
            "content": "<p>x</p>",
            "account": {
                "acct": "edithcharles.bsky.social",
                "uri": format!("https://bsky.app/profile/{did}"),
            },
        });
        if let Some(parent) = reply_to {
            st["vaak_in_reply_to_url"] = json!(parent);
        }
        st
    }

    #[test]
    fn detects_bsky_self_reply_by_did() {
        let did = "did:plc:qnwrertmdznkkexzktkweuvb";
        let parent = "at://did:plc:qnwrertmdznkkexzktkweuvb/app.bsky.feed.post/3mxemkna5uk25";
        let tip = bsky_note(
            "at://did:plc:qnwrertmdznkkexzktkweuvb/app.bsky.feed.post/3mxemsceigc25",
            did,
            Some(parent),
        );
        assert_eq!(self_reply_parent_uri(&tip).as_deref(), Some(parent));
        let other = bsky_note(
            "at://did:plc:qnwrertmdznkkexzktkweuvb/app.bsky.feed.post/child",
            did,
            Some("at://did:plc:someoneelse/app.bsky.feed.post/parent"),
        );
        assert!(self_reply_parent_uri(&other).is_none());
        let list = bsky_note(
            "at://did:plc:qnwrertmdznkkexzktkweuvb/app.bsky.feed.post/child",
            did,
            Some("at://did:plc:qnwrertmdznkkexzktkweuvb/app.bsky.graph.list/xyz"),
        );
        assert!(self_reply_parent_uri(&list).is_none());
        let https_parent = "https://bsky.app/profile/edithcharles.bsky.social/post/3mxemkna5uk25";
        let https_tip = bsky_note(
            "at://did:plc:qnwrertmdznkkexzktkweuvb/app.bsky.feed.post/3mxemsceigc25",
            did,
            Some(https_parent),
        );
        assert_eq!(
            self_reply_parent_uri(&https_tip).as_deref(),
            Some(https_parent)
        );
    }

    #[test]
    fn groups_tip_with_parent_in_list() {
        let parent_uri = "https://mkultra.monster/users/cmdr_nova/notes/aaaa";
        let tip_uri = "https://mkultra.monster/users/cmdr_nova/notes/bbbb";
        let statuses = vec![
            note(tip_uri, Some(parent_uri)),
            note("https://mkultra.monster/users/cmdr_nova/notes/other", None),
            note(parent_uri, None),
        ];
        let units = plan_feed_paint_units(&statuses, &HashMap::new());
        assert_eq!(units.len(), 2);
        match &units[0] {
            FeedPaintUnit::Thread { parent, tip } => {
                assert_eq!(status_object_uri(parent), parent_uri);
                assert_eq!(status_object_uri(tip), tip_uri);
            }
            _ => panic!("expected thread first"),
        }
        match &units[1] {
            FeedPaintUnit::Single(st) => {
                assert!(status_object_uri(st).ends_with("/notes/other"));
            }
            _ => panic!("expected single"),
        }
    }

    #[test]
    fn uses_extra_parent_when_missing_from_list() {
        let parent_uri = "https://mkultra.monster/users/cmdr_nova/notes/aaaa";
        let tip_uri = "https://mkultra.monster/users/cmdr_nova/notes/bbbb";
        let statuses = vec![note(tip_uri, Some(parent_uri))];
        let mut extras = HashMap::new();
        extras.insert(parent_uri.to_string(), note(parent_uri, None));
        let units = plan_feed_paint_units(&statuses, &extras);
        assert_eq!(units.len(), 1);
        assert!(matches!(units[0], FeedPaintUnit::Thread { .. }));
    }
}
