//! Shadow routing of spooled inbox Deletes (Rust cutover F3).
//!
//! With `VAAK_INBOX_ROUTE_SHADOW=1`, a `Delete` or `Tombstone` the front door
//! spools gets the result Rust predicts `ap_route_verified_activity` will
//! return for it, stored in `ap_inbox_spool.rust_result` with the row. The
//! prediction is made before the row exists, so the PHP drain cannot have
//! applied it yet. `inbox-spool-drain.php` compares it with its own `result`
//! (`vaak:inbox_route_shadow:stats:v1`). Rust writes nothing else.
//!
//! The PHP branch it mirrors: the ids are the object id, a Tombstone's own id
//! and a nested object's `id`/`object`; each counts only when its host is the
//! actor's host and not ours (`ap_inbox_object_owned_by_actor`). Live mention
//! rows (`ap_mention_soft_delete`) and timeline events
//! (`ap_events_mark_object_deleted`) decide the result:
//! `local_status_delete` when an event matches, else `local_mention_delete`
//! when a mention does, else `delete_noop`.

use std::time::Duration;

use serde_json::Value;
use tokio_postgres::Client;

/// The prediction is dropped (the row is spooled without one) past this.
const PREDICT_TIMEOUT: Duration = Duration::from_millis(400);

const LOCAL_HOSTS: [&str; 4] = ["mkultra.monster", "www.mkultra.monster", "vaak.monster", "www.vaak.monster"];

pub(crate) fn enabled() -> bool {
    std::env::var("VAAK_INBOX_ROUTE_SHADOW").is_ok_and(|v| v.trim() == "1")
}

/// `ap_as_id`: a non-empty string, a one-element list of one, or an
/// object's `id`, else its `href`.
pub(crate) fn as_id(v: &Value) -> Option<String> {
    match v {
        Value::String(s) if !s.is_empty() => Some(s.clone()),
        Value::Array(list) if list.len() == 1 => as_id(&list[0]),
        Value::Object(o) => match (o.get("id"), o.get("href")) {
            (Some(Value::String(id)), _) => Some(id.clone()),
            (_, Some(Value::String(href))) => Some(href.clone()),
            _ => None,
        },
        _ => None,
    }
}

/// The actor and the owned ids of a Delete/Tombstone, in PHP's order, or None
/// for any other activity (no prediction).
pub(crate) fn delete_targets(doc: &Value) -> Option<(String, Vec<String>)> {
    let kind = doc.get("type")?.as_str()?;
    let tombstone = kind.eq_ignore_ascii_case("Tombstone");
    if kind != "Delete" && !tombstone {
        return None;
    }
    let actor = doc.get("actor").and_then(as_id);
    let mut ids: Vec<String> = Vec::new();
    let mut push = |id: Option<String>| {
        if let Some(id) = id.filter(|s| !s.is_empty()) {
            if !ids.contains(&id) {
                ids.push(id);
            }
        }
    };
    let object = doc.get("object");
    push(object.and_then(as_id));
    if tombstone {
        push(as_id(doc));
    }
    if let Some(obj @ Value::Object(_)) = object {
        push(obj.get("id").and_then(as_id).or_else(|| obj.get("object").and_then(as_id)));
    }
    let actor = actor.unwrap_or_default();
    let owned = ids.into_iter().filter(|id| owned_by_actor(id, &actor)).collect();
    Some((actor, owned))
}

/// `ap_inbox_object_owned_by_actor`.
fn owned_by_actor(object_id: &str, actor: &str) -> bool {
    if actor.is_empty() || !object_id.starts_with("https://") {
        return false;
    }
    let (Some(oh), Some(ah)) = (crate::inbox_accept::host_of(object_id), crate::inbox_accept::host_of(actor)) else {
        return false;
    };
    oh == ah && !LOCAL_HOSTS.contains(&oh.as_str())
}

/// `LIKE` pattern for `id#…` with PHP's `addcslashes($id, '\\%_')`.
pub(crate) fn like_suffix_pattern(id: &str) -> String {
    let mut out = String::with_capacity(id.len() + 2);
    for c in id.chars() {
        if matches!(c, '\\' | '%' | '_') {
            out.push('\\');
        }
        out.push(c);
    }
    out.push_str("#%");
    out
}

fn outcome(events: bool, mentions: bool) -> &'static str {
    if events {
        "local_status_delete"
    } else if mentions {
        "local_mention_delete"
    } else {
        "delete_noop"
    }
}

async fn query(db: &Client, ids: &[String]) -> anyhow::Result<&'static str> {
    let (mut events, mut mentions) = (false, false);
    for did in ids {
        let m = did.trim_end_matches('/');
        if !mentions && !m.is_empty() && m.starts_with("https://") {
            let slash = format!("{m}/");
            let pattern = like_suffix_pattern(m);
            let row = db
                .query_one(
                    "SELECT EXISTS (SELECT 1 FROM mentions WHERE deleted_at IS NULL
                       AND (object_id = $1 OR object_id = $2 OR object_id LIKE $3 ESCAPE E'\\\\'))",
                    &[&m, &slash, &pattern],
                )
                .await?;
            mentions = row.get(0);
        }
        let e = did.trim().trim_end_matches('/');
        if !events && !e.is_empty() && e.starts_with("https://") {
            let slash = format!("{e}/");
            let row = db
                .query_one(
                    "SELECT EXISTS (SELECT 1 FROM events WHERE (object_id = $1 OR object_id = $2)
                       AND type IN ('Create', 'Announce', 'Update', 'Quote', 'QuotePost')
                       AND action_taken IN ('log', 'local_observe', 'local_fav_update', 'compose'))",
                    &[&e, &slash],
                )
                .await?;
            events = row.get(0);
        }
    }
    Ok(outcome(events, mentions))
}

/// The predicted router result for this body, or None when it is not a
/// Delete/Tombstone or the lookup fails or is slow.
pub(crate) async fn predict(db: &Client, body: &[u8]) -> Option<&'static str> {
    let doc: Value = serde_json::from_slice(body).ok()?;
    let (_, ids) = delete_targets(&doc)?;
    if ids.is_empty() {
        return Some("delete_noop");
    }
    match tokio::time::timeout(PREDICT_TIMEOUT, query(db, &ids)).await {
        Ok(Ok(r)) => Some(r),
        Ok(Err(e)) => {
            tracing::warn!(error = %e, "inbox route shadow lookup failed");
            None
        }
        Err(_) => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn delete_of_own_note_is_owned() {
        let d = json!({"type": "Delete", "actor": "https://m.test/users/a", "object": "https://m.test/users/a/statuses/1"});
        let (actor, ids) = delete_targets(&d).unwrap();
        assert_eq!(actor, "https://m.test/users/a");
        assert_eq!(ids, vec!["https://m.test/users/a/statuses/1"]);
    }

    #[test]
    fn foreign_and_local_objects_are_skipped() {
        let foreign = json!({"type": "Delete", "actor": "https://m.test/users/a", "object": "https://other.test/notes/1"});
        assert_eq!(delete_targets(&foreign).unwrap().1, Vec::<String>::new());
        let ours = json!({"type": "Delete", "actor": "https://mkultra.monster/users/a", "object": "https://mkultra.monster/notes/1"});
        assert_eq!(delete_targets(&ours).unwrap().1, Vec::<String>::new());
        let no_actor = json!({"type": "Delete", "object": "https://m.test/notes/1"});
        assert_eq!(delete_targets(&no_actor).unwrap().1, Vec::<String>::new());
    }

    #[test]
    fn tombstone_object_and_nested_ids() {
        let d = json!({"type": "Delete", "actor": "https://M.test/users/a",
            "object": {"type": "Tombstone", "id": "https://m.test/users/a/statuses/2"}});
        assert_eq!(delete_targets(&d).unwrap().1, vec!["https://m.test/users/a/statuses/2"]);
        let t = json!({"type": "tombstone", "id": "https://m.test/t/1", "actor": "https://m.test/users/a",
            "object": {"object": "https://m.test/n/3"}});
        // ap_as_id(object) is null (no id/href); own id; nested object.
        assert_eq!(delete_targets(&t).unwrap().1, vec!["https://m.test/t/1", "https://m.test/n/3"]);
        let listed = json!({"type": "Delete", "actor": ["https://m.test/users/a"], "object": ["https://m.test/n/4"]});
        assert_eq!(delete_targets(&listed).unwrap().1, vec!["https://m.test/n/4"]);
    }

    #[test]
    fn only_deletes_and_tombstones() {
        assert!(delete_targets(&json!({"type": "Create", "actor": "https://m.test/a", "object": "https://m.test/n"})).is_none());
        assert!(delete_targets(&json!({"type": "Update", "object": {"type": "Tombstone"}})).is_none());
    }

    #[test]
    fn like_pattern_escapes_wildcards() {
        assert_eq!(like_suffix_pattern("https://m.test/a_b%c\\d"), "https://m.test/a\\_b\\%c\\\\d#%");
    }

    #[test]
    fn outcome_order() {
        assert_eq!(outcome(true, true), "local_status_delete");
        assert_eq!(outcome(false, true), "local_mention_delete");
        assert_eq!(outcome(false, false), "delete_noop");
    }
}
