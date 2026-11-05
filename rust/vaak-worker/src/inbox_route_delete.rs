//! Rust applies spooled-kind Deletes itself (cutover F3 switch).
//!
//! With `VAAK_INBOX_ROUTE_DELETE=1`, a `Delete`/`Tombstone` that the front
//! door would spool (signer is the actor) is routed here instead of by
//! `inbox-spool-drain.php`, when every guard holds. It does what the Delete
//! branch of `ap_route_verified_activity` does for the case the F3 shadow
//! measured, and writes the spool row as already `done` with the same result,
//! so the 7-day audit trail and the `result` counts stay as they were.
//!
//! Left to PHP (the row is spooled as before) whenever:
//! - a live mention matches one of the ids. That branch also clears every
//!   notification cache and unread count, which stay PHP's;
//! - the actor's host is a Bridgy Fed host, or any actor-scope block names
//!   the actor's host (PHP resolves block aliases Rust does not);
//! - the object id's host is domain-blocked (PHP rejects those);
//! - the schema is not the one this was written for, or anything fails.
//!
//! What it writes, per owned id (`ap_inbox_object_owned_by_actor`), in one
//! transaction:
//! - `ap_notification_projection` rows for the status (`…_invalidate_status`)
//! - `direct_messages.deleted_at` (`ap_dm_soft_delete_by_object`)
//! - `events.action_taken = 'deleted'` (`ap_events_mark_object_deleted`), and
//!   when that hit rows, `ap_search_docs` for the object
//!   (`ap_search_fts_delete_object`) and the projection again
//! - the `events` row `ap_metrics_record` writes for the Delete
//! - the `ap_inbox_spool` row, `done`
//!
//! After commit: `pg_notify('vaak_timeline_events')` (`ap_timeline_notify`),
//! `ap_inbox_host_seen` and `ap_fanout_host_health` (the drain's host notes),
//! and the ap-inbox.log lines when `VAAK_AP_INBOX_LOG` names the file.

use std::collections::HashMap;
use std::io::Write as _;
use std::sync::Mutex;
use std::time::{Duration, Instant};

use anyhow::Result;
use serde_json::Value;
use tokio_postgres::Client;

/// `LOCAL_ACTOR` in ap-inbox.php (`CMDR_NOVA_ACTOR`).
const LOCAL_ACTOR: &str = "https://mkultra.monster/users/cmdr_nova";
const BLOCKS_TTL: Duration = Duration::from_secs(60);
/// `ap_inbox_host_seen_touch(..., 300)`; also how often one host's health
/// row is touched.
const HOST_NOTE_SECS: u64 = 300;

pub(crate) fn enabled() -> bool {
    std::env::var("VAAK_INBOX_ROUTE_DELETE").is_ok_and(|v| v.trim() == "1")
}

/// What the router would have returned, or why Rust left the row to PHP.
#[derive(Debug, PartialEq, Eq)]
pub(crate) enum Routed {
    Done { spool_id: i64, result: &'static str },
    Defer(&'static str),
}

fn is_bridgy_host(host: &str) -> bool {
    host == "brid.gy" || host.ends_with(".brid.gy")
}

/// True when some actor block's value mentions `host` (a URL or handle on
/// it). Over-matches on purpose: those rows go to PHP's alias check.
fn actor_block_names_host(values: &[String], host: &str) -> bool {
    !host.is_empty() && values.iter().any(|v| v.contains(host))
}

/// Actor-scope block values (lowercased), cached like the domain list.
type ValueCache = Option<(Instant, Vec<String>)>;
static ACTOR_BLOCKS: Mutex<ValueCache> = Mutex::new(None);

async fn actor_block_values(db: &Client) -> Result<Vec<String>> {
    if let Some((at, list)) = ACTOR_BLOCKS.lock().ok().and_then(|b| b.clone()) {
        if at.elapsed() < BLOCKS_TTL {
            return Ok(list);
        }
    }
    let list: Vec<String> = db
        .query("SELECT lower(value::text) FROM ap_blocks WHERE scope = 'actor' AND COALESCE(kind, 'block') <> 'mute'", &[])
        .await?
        .iter()
        .map(|row| row.get(0))
        .collect();
    if let Ok(mut cache) = ACTOR_BLOCKS.lock() {
        *cache = Some((Instant::now(), list.clone()));
    }
    Ok(list)
}

/// The columns written here, all TEXT (or integer) as ap-db.php creates
/// them. Checked once; any other shape turns the switch off for the process.
const SCHEMA: &[(&str, &str, &str)] = &[
    ("events", "created_at", "text"),
    ("events", "type", "text"),
    ("events", "actor_id", "text"),
    ("events", "object_id", "text"),
    ("events", "target_actor", "text"),
    ("events", "host", "text"),
    ("events", "action_taken", "text"),
    ("events", "spoiler_text", "text"),
    ("events", "visibility", "text"),
    ("direct_messages", "deleted_at", "text"),
    ("direct_messages", "object_id", "text"),
    ("ap_notification_projection", "status_id", "text"),
    ("ap_inbox_host_seen", "last_seen_at", "text"),
    ("ap_fanout_host_health", "updated_at", "text"),
];

static SCHEMA_OK: Mutex<Option<bool>> = Mutex::new(None);

async fn schema_ok(db: &Client) -> bool {
    if let Some(ok) = SCHEMA_OK.lock().ok().and_then(|g| *g) {
        return ok;
    }
    let rows = match db
        .query(
            "SELECT table_name::text, column_name::text, data_type::text FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name IN ('events', 'direct_messages', 'ap_notification_projection', 'ap_inbox_host_seen', 'ap_fanout_host_health')",
            &[],
        )
        .await
    {
        Ok(rows) => rows,
        Err(_) => return false,
    };
    let have: HashMap<(String, String), String> =
        rows.iter().map(|r| ((r.get::<_, String>(0), r.get::<_, String>(1)), r.get::<_, String>(2))).collect();
    let mut ok = true;
    for (t, c, want) in SCHEMA {
        match have.get(&(t.to_string(), c.to_string())) {
            Some(ty) if ty == want || (*want == "text" && ty == "character varying") => {}
            other => {
                tracing::warn!(table = t, column = c, found = ?other, "inbox Delete routing off: unexpected schema");
                ok = false;
            }
        }
    }
    // ap_search_fts_available(): the table may be absent on small installs.
    let fts = db
        .query_one("SELECT to_regclass('ap_search_docs') IS NOT NULL", &[])
        .await
        .map(|r| r.get::<_, bool>(0))
        .unwrap_or(false);
    FTS.lock().map(|mut g| *g = Some(fts)).ok();
    if let Ok(mut g) = SCHEMA_OK.lock() {
        *g = Some(ok);
    }
    ok
}

static FTS: Mutex<Option<bool>> = Mutex::new(None);

fn fts_available() -> bool {
    FTS.lock().ok().and_then(|g| *g).unwrap_or(false)
}

/// Hosts noted recently (`ap_inbox_host_seen` / host health).
static HOST_NOTED: Mutex<Option<HashMap<String, Instant>>> = Mutex::new(None);

fn host_note_due(host: &str) -> bool {
    let Ok(mut g) = HOST_NOTED.lock() else { return true };
    let map = g.get_or_insert_with(HashMap::new);
    if map.len() > 20_000 {
        map.clear();
    }
    match map.get(host) {
        Some(at) if at.elapsed() < Duration::from_secs(HOST_NOTE_SECS) => false,
        _ => {
            map.insert(host.to_string(), Instant::now());
            true
        }
    }
}

/// `preg_replace('/[^A-Za-z]/', '', $type)`, else `Activity`.
fn seen_type(kind: &str) -> String {
    let t: String = kind.chars().filter(|c| c.is_ascii_alphabetic()).collect();
    if t.is_empty() || t.len() > 32 {
        "Activity".to_string()
    } else {
        t
    }
}

/// `ap_short`: whitespace runs become one space; over 120 bytes is cut
/// (here on a char boundary) and gets `…`.
fn short(s: &str) -> String {
    let flat: String = s.split_whitespace().collect::<Vec<_>>().join(" ");
    if flat.len() <= 120 {
        return flat;
    }
    let mut end = 120;
    while !flat.is_char_boundary(end) {
        end -= 1;
    }
    format!("{}…", &flat[..end])
}

fn inbox_log(lines: &[String]) {
    let Some(path) = std::env::var("VAAK_AP_INBOX_LOG").ok().filter(|p| !p.trim().is_empty()) else {
        for l in lines {
            tracing::info!(target: "ap_inbox", "{l}");
        }
        return;
    };
    let at = crate::fanout_delivery::iso(now_unix());
    if let Ok(mut f) = std::fs::OpenOptions::new().create(true).append(true).open(path) {
        for l in lines {
            let _ = writeln!(f, "[{at}] {l}");
        }
    }
}

fn now_unix() -> i64 {
    std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|d| d.as_secs() as i64).unwrap_or(0)
}

/// Everything the Delete needs from the body.
struct Plan {
    actor: String,
    object_id: Option<String>,
    ids: Vec<String>,
}

fn plan(doc: &Value) -> Option<Plan> {
    let (actor, ids) = crate::inbox_route_shadow::delete_targets(doc)?;
    let object_id = doc.get("object").and_then(crate::inbox_route_shadow::as_id);
    Some(Plan { actor, object_id, ids })
}

/// Route the Delete in `body`, or say why PHP should.
#[allow(clippy::too_many_arguments)]
pub(crate) async fn route(
    db: &mut Client,
    blocked_domains: &[String],
    ip: &str,
    key_id: &str,
    path: &str,
    sig_path: &str,
    kind: &str,
    body: &[u8],
) -> Routed {
    let Ok(doc) = serde_json::from_slice::<Value>(body) else { return Routed::Defer("body") };
    let Some(plan) = plan(&doc) else { return Routed::Defer("not_delete") };
    let actor_host = crate::inbox_accept::host_of(&plan.actor).unwrap_or_default();
    if actor_host.is_empty() || is_bridgy_host(&actor_host) {
        return Routed::Defer("actor_host");
    }
    // ap_route_verified_activity rejects an object on a blocked host first.
    if let Some(oid) = plan.object_id.as_deref().filter(|o| o.starts_with("https://")) {
        if let Some(h) = crate::inbox_accept::host_of(oid) {
            if crate::inbox_accept::host_blocked(&h, blocked_domains) {
                return Routed::Defer("object_blocked");
            }
        }
    }
    if !schema_ok(db).await {
        return Routed::Defer("schema");
    }
    match actor_block_values(db).await {
        Ok(values) if !actor_block_names_host(&values, &actor_host) => {}
        Ok(_) => return Routed::Defer("actor_block"),
        Err(_) => return Routed::Defer("db"),
    }
    match apply(db, &plan, ip, key_id, path, sig_path, kind, body).await {
        Ok(Some((spool_id, result, events))) => {
            after_commit(db, &plan, key_id, kind, spool_id, result, events, body.len()).await;
            Routed::Done { spool_id, result }
        }
        Ok(None) => Routed::Defer("mention"),
        Err(e) => {
            tracing::warn!(error = %e, "inbox Delete routing failed; spooling for PHP");
            Routed::Defer("error")
        }
    }
}

/// The transaction. None when a live mention matches (PHP's branch).
#[allow(clippy::too_many_arguments)]
async fn apply(
    db: &mut Client,
    plan: &Plan,
    ip: &str,
    key_id: &str,
    path: &str,
    sig_path: &str,
    kind: &str,
    body: &[u8],
) -> Result<Option<(i64, &'static str, i64)>> {
    let text = std::str::from_utf8(body)?;
    let tx = db.transaction().await?;
    let now = crate::fanout_delivery::iso(now_unix());
    for did in &plan.ids {
        let m = did.trim_end_matches('/');
        if m.starts_with("https://") {
            let slash = format!("{m}/");
            let pattern = crate::inbox_route_shadow::like_suffix_pattern(m);
            let hit: bool = tx
                .query_one(
                    "SELECT EXISTS (SELECT 1 FROM mentions WHERE deleted_at IS NULL
                       AND (object_id = $1 OR object_id = $2 OR object_id LIKE $3 ESCAPE E'\\\\'))",
                    &[&m, &slash, &pattern],
                )
                .await?
                .get(0);
            if hit {
                tx.rollback().await?;
                return Ok(None);
            }
        }
    }
    let mut events: i64 = 0;
    for did in &plan.ids {
        // ap_notification_projection_invalidate_status: rtrim(trim($id), '/').
        let status = did.trim().trim_end_matches('/');
        if !status.is_empty() {
            let slash = format!("{status}/");
            tx.execute("DELETE FROM ap_notification_projection WHERE status_id = $1 OR status_id = $2", &[&status, &slash]).await?;
        }
        // ap_dm_soft_delete_by_object: the id exactly as sent.
        tx.execute("UPDATE direct_messages SET deleted_at = $1 WHERE object_id = $2 AND deleted_at IS NULL", &[&now, did]).await?;
        // ap_events_mark_object_deleted.
        if status.starts_with("https://") {
            let slash = format!("{status}/");
            let n = tx
                .execute(
                    "UPDATE events SET action_taken = 'deleted'
                     WHERE (object_id = $1 OR object_id = $2)
                       AND type IN ('Create', 'Announce', 'Update', 'Quote', 'QuotePost')
                       AND action_taken IN ('log', 'local_observe', 'local_fav_update', 'compose')",
                    &[&status, &slash],
                )
                .await? as i64;
            if n > 0 {
                if fts_available() {
                    tx.execute("DELETE FROM ap_search_docs WHERE object_id = $1 OR object_id = $2", &[&status, &slash]).await?;
                }
                tx.execute("DELETE FROM ap_notification_projection WHERE status_id = $1 OR status_id = $2", &[&status, &slash]).await?;
            }
            events += n;
        }
    }
    let (result, action, target): (&'static str, &str, Option<&str>) =
        if events > 0 { ("local_status_delete", "local_status_delete", Some(LOCAL_ACTOR)) } else { ("delete_noop", "log", None) };
    // ap_metrics_record('Delete', $actorId, $objectId, …): no summary, media,
    // reply or CW; host is the actor's, lowercased.
    let actor = (!plan.actor.is_empty()).then_some(plan.actor.as_str());
    let host = actor.and_then(crate::inbox_accept::host_of);
    let bytes = body.len() as i64;
    tx.execute(
        "INSERT INTO events (created_at, type, actor_id, object_id, target_actor, host, bytes, action_taken, summary, media_urls, in_reply_to, spoiler_text, sensitive, visibility)
         VALUES ($1, 'Delete', $2, $3, $4, $5, $6::bigint, $7, NULL, NULL, NULL, '', 0, 'public')",
        &[&now, &actor, &plan.object_id, &target, &host, &bytes, &action],
    )
    .await?;
    let spool_id: i64 = tx
        .query_one(
            "INSERT INTO ap_inbox_spool (received_at, client_ip, key_id, request_path, signature_path, activity_type, body,
                                         state, attempts, claimed_at, finished_at, result)
             VALUES (now(), $1, $2, $3, $4, $5, $6, 'done', 1, now(), now(), $7) RETURNING id",
            &[&ip, &key_id, &path, &sig_path, &kind, &text, &result],
        )
        .await?
        .get(0);
    tx.commit().await?;
    Ok(Some((spool_id, result, events)))
}

#[allow(clippy::too_many_arguments)]
async fn after_commit(db: &Client, plan: &Plan, key_id: &str, kind: &str, spool_id: i64, result: &str, events: i64, bytes: usize) {
    let now = crate::fanout_delivery::iso(now_unix());
    // ap_timeline_notify: rtrim'd actor and object.
    let payload = serde_json::json!({
        "type": "Delete",
        "actor": plan.actor.trim_end_matches('/'),
        "object": plan.object_id.as_deref().unwrap_or("").trim_end_matches('/'),
        "at": now,
    })
    .to_string();
    let _ = db.execute("SELECT pg_notify('vaak_timeline_events', $1)", &[&payload]).await;
    let key_host = crate::inbox_accept::host_of(key_id).unwrap_or_default();
    if !key_host.is_empty() && key_host != "mkultra.monster" && host_note_due(&key_host) {
        let seen = seen_type(kind);
        let cutoff = crate::fanout_delivery::iso(now_unix() - HOST_NOTE_SECS as i64);
        let _ = db
            .execute(
                "INSERT INTO ap_inbox_host_seen (host, last_seen_at, last_type) VALUES ($1, $2, $3)
                 ON CONFLICT(host) DO UPDATE SET last_seen_at = excluded.last_seen_at, last_type = excluded.last_type
                 WHERE ap_inbox_host_seen.last_seen_at <= $4",
                &[&key_host, &now, &seen, &cutoff],
            )
            .await;
        let h = crate::fanout_delivery::host_normalize(&key_host);
        if !h.is_empty() && !crate::fanout_delivery::is_bluesky_host(&h) {
            let _ = db
                .execute(
                    "UPDATE ap_fanout_host_health
                     SET last_success_at = $1, streak_started_at = NULL, consecutive_terminal_failures = 0, updated_at = $1
                     WHERE host = $2 AND (streak_started_at IS NOT NULL OR consecutive_terminal_failures > 0)",
                    &[&now, &h],
                )
                .await;
        }
    }
    let object = short(plan.object_id.as_deref().unwrap_or(""));
    let mut lines = vec![format!(
        "accepted via=rust_route id={spool_id} type={} bytes={bytes} keyId={}",
        seen_type(kind),
        short(key_id)
    )];
    lines.push(if result == "delete_noop" {
        format!("delete_noop object={object}")
    } else {
        format!("{result} mentions=0 events={events} object={object}")
    });
    inbox_log(&lines);
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn bridgy_and_blocked_hosts_go_to_php() {
        assert!(is_bridgy_host("bsky.brid.gy") && is_bridgy_host("brid.gy") && !is_bridgy_host("notbrid.gy"));
        let blocks = vec!["https://bad.test/users/x".to_string(), "@troll@other.test".to_string(), "did:plc:abc".to_string()];
        assert!(actor_block_names_host(&blocks, "bad.test"));
        assert!(actor_block_names_host(&blocks, "other.test"));
        assert!(!actor_block_names_host(&blocks, "fine.test"));
        assert!(!actor_block_names_host(&blocks, ""));
    }

    #[test]
    fn plan_keeps_raw_object_id() {
        let d = json!({"type": "Delete", "actor": "https://m.test/users/a", "object": {"type": "Tombstone", "id": "https://m.test/users/a/statuses/1/"}});
        let p = plan(&d).unwrap();
        assert_eq!(p.object_id.as_deref(), Some("https://m.test/users/a/statuses/1/"));
        assert_eq!(p.ids, vec!["https://m.test/users/a/statuses/1/"]);
        assert!(plan(&json!({"type": "Like"})).is_none());
    }

    #[test]
    fn seen_type_and_short() {
        assert_eq!(seen_type("Delete"), "Delete");
        assert_eq!(seen_type("x-1"), "x");
        assert_eq!(seen_type("123"), "Activity");
        assert_eq!(short("a  b\nc"), "a b c");
        let long = "é".repeat(100);
        assert!(short(&long).ends_with('…') && short(&long).len() <= 124);
    }
}
