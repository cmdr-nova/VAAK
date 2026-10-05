//! Mastodon-style self-thread grouping for lean Home/Local/Federated + profile paint.
//!
//! A reply to the author's own local note paints as one `article.tweet.tweet-thread`
//! with the parent ancestor above the tip (instead of a bare reply card).

use serde_json::Value;
use std::collections::{HashMap, HashSet};

const LOCAL_NOTES_MARKER: &str = "/notes/";
const LOCAL_ACTOR_PREFIX: &str = "https://mkultra.monster/users/";

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

/// Parent note URL when `st` is a local self-reply continuation.
pub fn self_reply_parent_uri(st: &Value) -> Option<String> {
    let child = status_object_uri(st);
    if child.is_empty() || !child.contains(LOCAL_NOTES_MARKER) {
        return None;
    }
    let parent = st
        .get("vaak_in_reply_to_url")
        .and_then(|v| v.as_str())
        .map(|s| s.trim().trim_end_matches('/').to_string())
        .filter(|s| !s.is_empty())
        .or_else(|| {
            st.get("in_reply_to_id")
                .and_then(|v| v.as_str())
                .map(|s| s.trim().trim_end_matches('/').to_string())
                .filter(|s| s.starts_with("https://") && s.contains(LOCAL_NOTES_MARKER))
        })?;
    let child_actor = child
        .rsplit_once(LOCAL_NOTES_MARKER)
        .map(|(p, _)| p)?;
    let parent_actor = parent
        .rsplit_once(LOCAL_NOTES_MARKER)
        .map(|(p, _)| p)?;
    if child_actor != parent_actor {
        return None;
    }
    if !child_actor.starts_with(LOCAL_ACTOR_PREFIX) {
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
    let parent_inner = article_inner_html(&parent_html).unwrap_or(parent_html);
    let tip_inner = article_inner_html(&tip_html).unwrap_or(tip_html);
    format!(
        "<article class=\"tweet tweet-thread\">\
<div class=\"tweet-thread-seg tweet-thread-seg--ancestor\">{parent_inner}</div>\
<div class=\"tweet-thread-rail\" aria-hidden=\"true\"></div>\
<div class=\"tweet-thread-seg tweet-thread-seg--tip\">{tip_inner}</div>\
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
