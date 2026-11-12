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
//!
//! Cutover F5 adds incoming `Like`, `EmojiReact` and `Announce` of a local
//! note (`https://mkultra.monster/users/{key}/notes/…`). The prediction is the
//! router result (`local_observe`, or `local_private_interaction_skipped` for
//! a non-public interaction with a private object), followed for a
//! notification by ` {owner_user_id} {mention object_id}`: the row
//! `ap_local_observe` stores through `ap_mention_store`. The drain checks the
//! result and that row.

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

/// The predicted router result for this body, or None when it is neither a
/// Delete/Tombstone nor a like or boost of a local note, or the lookup fails
/// or is slow. `path` is the inbox path (a personal inbox picks the owner).
pub(crate) async fn predict(db: &Client, body: &[u8], path: &str) -> Option<String> {
    let doc: Value = serde_json::from_slice(body).ok()?;
    let looked_up = if let Some((_, ids)) = delete_targets(&doc) {
        if ids.is_empty() {
            return Some("delete_noop".to_string());
        }
        tokio::time::timeout(PREDICT_TIMEOUT, async { query(db, &ids).await.map(str::to_string) }).await
    } else {
        let it = interaction(&doc)?;
        tokio::time::timeout(PREDICT_TIMEOUT, predict_interaction(db, &it, path)).await
    };
    match looked_up {
        Ok(Ok(r)) => Some(r),
        Ok(Err(e)) => {
            tracing::warn!(error = %e, "inbox route shadow lookup failed");
            None
        }
        Err(_) => None,
    }
}

/// A like or boost of a local note, the part of `ap_route_verified_activity`
/// and `ap_local_observe` the F5 shadow predicts.
#[derive(Debug, PartialEq)]
pub(crate) struct Interaction {
    /// `like` (Like, EmojiReact) or `reblog` (Announce).
    pub kind: &'static str,
    pub actor: String,
    /// `ap_as_id(object)`, untrimmed, as PHP keys the row with it.
    pub object: String,
    /// `ap_activity_is_public`.
    pub public: bool,
    /// Local actors addressed in to/cc/bto/bcc/audience
    /// (`ap_inbox_recipient_owner`), in order, trailing slash trimmed.
    pub addressed: Vec<String>,
}

/// `https://mkultra.monster/users/{key}/notes/…` with a key PHP's
/// `/users/[A-Za-z0-9_]+` matches.
fn local_note(id: &str) -> bool {
    let Some(rest) = id.strip_prefix("https://mkultra.monster/users/") else { return false };
    let Some((key, note)) = rest.split_once('/') else { return false };
    !key.is_empty() && key.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_') && note.starts_with("notes/")
}

/// The interaction to predict, or None for anything else (left to PHP
/// without a prediction).
pub(crate) fn interaction(doc: &Value) -> Option<Interaction> {
    let kind = match doc.get("type")?.as_str()? {
        "Like" | "EmojiReact" => "like",
        "Announce" => "reblog",
        _ => return None,
    };
    let actor = doc.get("actor").and_then(as_id).filter(|a| a.starts_with("https://"))?;
    let object_v = doc.get("object")?;
    // A boost wrapping another activity (`object.object`) is keyed
    // differently by PHP; leave it unpredicted.
    if kind == "reblog" && object_v.get("object").is_some() {
        return None;
    }
    let object = as_id(object_v)?;
    if !local_note(object.trim_end_matches('/')) {
        return None;
    }
    let public = audience_values(doc).iter().any(|id| is_public_audience(id));
    let mut addressed = Vec::new();
    for field in ["to", "cc", "bto", "bcc", "audience"] {
        let vals: Vec<&Value> = match doc.get(field) {
            None | Some(Value::Null) => continue,
            Some(Value::Array(list)) => list.iter().collect(),
            Some(Value::Object(o)) => o.values().collect(),
            Some(v) => vec![v],
        };
        for v in vals {
            let id = match v {
                Value::String(s) => s.clone(),
                Value::Object(o) => o.get("id").map(php_string).unwrap_or_default(),
                _ => String::new(),
            };
            let id = id.trim_end_matches('/');
            let local = id
                .strip_prefix("https://mkultra.monster/users/")
                .is_some_and(|k| !k.is_empty() && k.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_'));
            if local {
                addressed.push(id.to_string());
            }
        }
    }
    Some(Interaction { kind, actor, object, public, addressed })
}

/// `(string) $v` for a JSON scalar.
fn php_string(v: &Value) -> String {
    match v {
        Value::String(s) => s.clone(),
        Value::Number(n) => n.to_string(),
        Value::Bool(true) => "1".to_string(),
        _ => String::new(),
    }
}

/// `ap_audience_values`: to/cc/bto/bcc of the activity and of an embedded
/// object.
fn audience_values(doc: &Value) -> Vec<String> {
    let mut out = Vec::new();
    let mut collect = |v: Option<&Value>| match v {
        Some(Value::String(s)) if !s.is_empty() => out.push(s.clone()),
        Some(Value::Array(list)) => push_items(&mut out, list.iter()),
        Some(Value::Object(o)) => push_items(&mut out, o.values()),
        _ => {}
    };
    for field in ["to", "cc", "bto", "bcc"] {
        collect(doc.get(field));
    }
    if let Some(obj @ Value::Object(_)) = doc.get("object") {
        for field in ["to", "cc", "bto", "bcc"] {
            collect(obj.get(field));
        }
    }
    out
}

fn push_items<'a>(out: &mut Vec<String>, items: impl Iterator<Item = &'a Value>) {
    for item in items {
        match item {
            Value::String(s) if !s.is_empty() => out.push(s.clone()),
            Value::Array(_) | Value::Object(_) => {
                if let Some(id) = as_id(item).filter(|s| !s.is_empty()) {
                    out.push(id);
                }
            }
            _ => {}
        }
    }
}

/// `ap_as_id_is_public_audience`.
fn is_public_audience(id: &str) -> bool {
    id == "https://www.w3.org/ns/activitystreams#Public" || id == "as:Public" || id == "Public" || id.ends_with("#Public")
}

/// The prediction string: the result, then ` {owner} {object_id}` when a
/// notification row is stored.
/// `local_observe {owner} {object_id}` for a notification row, else
/// `local_observe {owner}`: the recipient goes along so the drain checks only
/// that account's rows. One activity delivered to several local inboxes
/// gives one notification, to the note's owner, and the other recipients'
/// predictions must not count it against themselves.
fn interaction_outcome(private: bool, owner: i64, mention: Option<String>) -> String {
    if private {
        return "local_private_interaction_skipped".to_string();
    }
    match mention {
        Some(id) => format!("local_observe {owner} {id}"),
        None => format!("local_observe {owner}"),
    }
}

async fn predict_interaction(db: &Client, it: &Interaction, path: &str) -> anyhow::Result<String> {
    if !it.public && object_is_private(db, &it.object).await? {
        return Ok(interaction_outcome(true, 0, None));
    }
    let (owner, owner_actor) = recipient_owner(db, it, path).await?;
    let actor = it.actor.trim_end_matches('/');
    let ours = format!("{owner_actor}/notes/");
    let mention = (actor != owner_actor && it.object.trim_end_matches('/').starts_with(&ours))
        .then(|| crate::inbox_route_undo::stable_interaction_id(&it.object, &it.actor, it.kind));
    Ok(interaction_outcome(false, owner, mention))
}

/// `ap_local_object_is_private`.
pub(crate) async fn object_is_private(db: &Client, object: &str) -> anyhow::Result<bool> {
    let id = object.trim().trim_end_matches('/');
    let slash = format!("{id}/");
    let dm = db
        .query_opt("SELECT 1 FROM direct_messages WHERE (object_id = $1 OR object_id = $2) AND deleted_at IS NULL LIMIT 1", &[&id, &slash])
        .await?;
    if dm.is_some() {
        return Ok(true);
    }
    for sql in [
        "SELECT visibility::text FROM outbox_notes WHERE (id = $1 OR id = $2) LIMIT 1",
        "SELECT visibility::text FROM masto_statuses WHERE (note_id = $1 OR note_id = $2) LIMIT 1",
        "SELECT visibility::text FROM events WHERE (object_id = $1 OR object_id = $2) AND type IN ('Create', 'Update') LIMIT 1",
    ] {
        if let Some(row) = db.query_opt(sql, &[&id, &slash]).await? {
            let v: Option<String> = row.get(0);
            if normalize_visibility(v.as_deref().unwrap_or("")) != "public" {
                return Ok(true);
            }
        }
    }
    Ok(false)
}

/// `ap_normalize_visibility`.
fn normalize_visibility(v: &str) -> &'static str {
    match v.trim().to_ascii_lowercase().as_str() {
        "local" | "local_only" | "instance" | "instance_only" => "local",
        "unlisted" | "silent" | "silent_public" => "unlisted",
        "private" | "followers" | "followers_only" => "private",
        _ => "public",
    }
}

/// `ap_inbox_recipient_owner` as the drain runs it: the personal inbox's
/// user, else cmdr_nova, then local actors the activity addresses; the first
/// active account wins, else cmdr_nova's user id.
pub(crate) async fn recipient_owner(db: &Client, it: &Interaction, path: &str) -> anyhow::Result<(i64, String)> {
    const CMDR: &str = "https://mkultra.monster/users/cmdr_nova";
    let mut candidates: Vec<String> = Vec::new();
    match crate::inbox_accept::personal_inbox_user(path) {
        Some(user) if !user.is_empty() => {
            let row = db
                .query_opt("SELECT actor_key FROM ap_users WHERE lower(username) = $1 AND disabled_at IS NULL LIMIT 1", &[&user])
                .await?;
            let key: String = match row {
                Some(r) => r.get::<_, Option<String>>(0).unwrap_or_default(),
                None => String::new(),
            };
            let key: String = key.trim().to_ascii_lowercase().chars().filter(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || *c == '_').collect();
            candidates.push(if key.is_empty() { CMDR.to_string() } else { format!("https://mkultra.monster/users/{key}") });
        }
        _ => candidates.push(CMDR.to_string()),
    }
    candidates.extend(it.addressed.iter().cloned());
    let mut seen: Vec<&str> = Vec::new();
    for cand in &candidates {
        let cand = cand.trim_end_matches('/');
        if cand.is_empty() || seen.contains(&cand) {
            continue;
        }
        seen.push(cand);
        if !cand.starts_with("https://mkultra.monster/users/") {
            continue;
        }
        let key = cand.rsplit('/').next().unwrap_or("");
        let key = percent_decode(key);
        let slash = format!("{cand}/");
        let row = db
            .query_opt(
                "SELECT id::bigint, actor_id FROM ap_users
                 WHERE disabled_at IS NULL AND (actor_id = $1 OR actor_id = $2 OR lower(actor_key) = lower($3))
                 LIMIT 1",
                &[&cand, &slash, &key],
            )
            .await?;
        if let Some(row) = row {
            let id: i64 = row.get(0);
            if id > 0 {
                let actor: Option<String> = row.get(1);
                let actor = actor.filter(|a| !a.is_empty()).unwrap_or_else(|| cand.to_string());
                return Ok((id, actor.trim_end_matches('/').to_string()));
            }
        }
    }
    let row = db.query_opt("SELECT id::bigint FROM ap_users WHERE actor_key = 'cmdr_nova' LIMIT 1", &[]).await?;
    let id = row.map(|r| r.get::<_, i64>(0)).filter(|id| *id > 0).unwrap_or(1);
    Ok((id, CMDR.to_string()))
}

/// `rawurldecode` for a path segment.
fn percent_decode(s: &str) -> String {
    let b = s.as_bytes();
    let mut out = Vec::with_capacity(b.len());
    let mut i = 0;
    while i < b.len() {
        if b[i] == b'%' && i + 2 < b.len() {
            if let (Some(h), Some(l)) = ((b[i + 1] as char).to_digit(16), (b[i + 2] as char).to_digit(16)) {
                out.push((h * 16 + l) as u8);
                i += 3;
                continue;
            }
        }
        out.push(b[i]);
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
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
    fn likes_and_boosts_of_local_notes() {
        let like = json!({"type": "Like", "actor": "https://m.test/users/a",
            "object": "https://mkultra.monster/users/cmdr_nova/notes/abc"});
        let it = interaction(&like).unwrap();
        assert_eq!((it.kind, it.public, it.addressed.len()), ("like", false, 0));
        assert_eq!(it.object, "https://mkultra.monster/users/cmdr_nova/notes/abc");
        let react = json!({"type": "EmojiReact", "actor": ["https://m.test/users/a"],
            "object": {"id": "https://mkultra.monster/users/luna/notes/1"}, "content": ":blobcat:"});
        assert_eq!(interaction(&react).unwrap().kind, "like");
        let boost = json!({"type": "Announce", "actor": "https://m.test/users/a",
            "object": "https://mkultra.monster/users/cmdr_nova/notes/abc",
            "to": ["https://www.w3.org/ns/activitystreams#Public"],
            "cc": ["https://m.test/users/a/followers", {"id": "https://mkultra.monster/users/luna/"}]});
        let it = interaction(&boost).unwrap();
        assert_eq!((it.kind, it.public), ("reblog", true));
        assert_eq!(it.addressed, vec!["https://mkultra.monster/users/luna"]);
    }

    #[test]
    fn other_interactions_are_not_predicted() {
        for doc in [
            json!({"type": "Like", "actor": "https://m.test/users/a", "object": "https://m.test/notes/1"}),
            json!({"type": "Like", "actor": "https://m.test/users/a", "object": "https://mkultra.monster/users/cmdr_nova"}),
            json!({"type": "Like", "actor": "https://m.test/users/a", "object": "https://mkultra.monster/users/a-b/notes/1"}),
            json!({"type": "Like", "object": "https://mkultra.monster/users/cmdr_nova/notes/1"}),
            json!({"type": "Announce", "actor": "https://m.test/users/a",
                "object": {"id": "https://mkultra.monster/users/cmdr_nova/notes/1", "object": "https://m.test/n/2"}}),
            json!({"type": "Create", "actor": "https://m.test/users/a", "object": "https://mkultra.monster/users/cmdr_nova/notes/1"}),
        ] {
            assert!(interaction(&doc).is_none(), "{doc}");
        }
    }

    #[test]
    fn public_audience_includes_the_object() {
        let unlisted = json!({"type": "Announce", "actor": "https://m.test/users/a",
            "object": {"id": "https://mkultra.monster/users/cmdr_nova/notes/1", "cc": "as:Public"}});
        assert!(interaction(&unlisted).unwrap().public);
        let keyed = json!({"type": "Like", "actor": "https://m.test/users/a",
            "object": "https://mkultra.monster/users/cmdr_nova/notes/1", "to": {"0": "https://x.test/c#Public"}});
        assert!(interaction(&keyed).unwrap().public);
    }

    #[test]
    fn interaction_outcomes() {
        assert_eq!(interaction_outcome(true, 1, Some("x".into())), "local_private_interaction_skipped");
        assert_eq!(interaction_outcome(false, 7, None), "local_observe 7");
        assert_eq!(interaction_outcome(false, 3, Some("https://e/n#like-abc".into())), "local_observe 3 https://e/n#like-abc");
        assert_eq!(normalize_visibility(" Followers "), "private");
        assert_eq!(normalize_visibility(""), "public");
        assert_eq!(percent_decode("a%5Fb%zz%4"), "a_b%zz%4");
    }

    #[test]
    fn outcome_order() {
        assert_eq!(outcome(true, true), "local_status_delete");
        assert_eq!(outcome(false, true), "local_mention_delete");
        assert_eq!(outcome(false, false), "delete_noop");
    }
}
