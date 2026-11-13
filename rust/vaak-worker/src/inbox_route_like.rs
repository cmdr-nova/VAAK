//! Rust routes incoming likes (F5) and boosts (F5b) of local posts itself.
//!
//! With `VAAK_INBOX_ROUTE_LIKE=1`, a `Like` or `EmojiReact` the front door
//! would spool (signer is the actor) whose object is a local note is routed
//! here, like `inbox_route_undo` does for Undos. It mirrors what
//! `ap_route_verified_activity` does with it (the touches-local interaction
//! branch, `ap_local_observe`, `ap_mention_store`, `ap_metrics_record`):
//!
//! - the recipient is picked as `ap_inbox_recipient_owner` does (shared with
//!   the F5 shadow, `inbox_route_shadow::recipient_owner`);
//! - when the note is the recipient's and the liker is not the recipient,
//!   the `mentions` row keyed `note#like-hash` is stored with PHP's upsert,
//!   reusing the object id of a live row with the same activity id;
//! - the `Like`/`EmojiReact` events row (`local_observe`, target cmdr_nova,
//!   visibility from to/cc);
//! - the owner's notification caches are cleared
//!   (`ap_notification_projection_invalidate_owner`,
//!   `ap_masto_notifications_unread_invalidate`);
//! - the phone push stays PHP's: a job goes on the queue Redis list
//!   `vaak:queue:inbox_webpush`, which `inbox-spool-drain.php` sends.
//!
//! With `VAAK_INBOX_ROUTE_BOOST=1`, an `Announce` signed by its own actor
//! (not a relay or follow forward) of a local note goes the same way, plus
//! what `ap_metrics_record` does for a boost:
//!
//! - the events row's target is the note's author;
//! - only the first `Announce` events row per object is inserted (PHP's
//!   firehose dedupe); a later one only narrows its visibility label;
//! - a new row gets `ap_timeline_notify` and the timeline fan-out jobs
//!   (`home_followers`, and `public_feed` when public) on
//!   `vaak:queue:timeline_fanout`, which this worker already consumes;
//! - the push job is a `reblog`.
//!
//! Boosts defer as `fanout_off` when this worker's env turns the ingest
//! fan-out or its Rust queue off (PHP would then do it in-process).
//!
//! It defers to PHP (spooled as before) for anything else: an EmojiReact or
//! Misskey Like carrying `content` (PHP keeps it as the event text), an
//! embedded object, a non-public like of a private post, a local liker
//! (Bluesky twin cleanup), Bridgy and actor-block hosts, schema surprises
//! and errors.

use anyhow::Result;
use serde_json::Value;
use tokio_postgres::Client;

use crate::inbox_route_delete::Routed;
use crate::inbox_route_shadow::{as_id, Interaction};

const LOCAL_ACTOR: &str = "https://mkultra.monster/users/cmdr_nova";

/// Queue Redis list `inbox-spool-drain.php` sends Web Push jobs from.
pub(crate) const WEBPUSH_QUEUE: &str = "vaak:queue:inbox_webpush";

pub(crate) fn enabled() -> bool {
    std::env::var("VAAK_INBOX_ROUTE_LIKE").is_ok_and(|v| v.trim() == "1")
}

pub(crate) fn boost_enabled() -> bool {
    std::env::var("VAAK_INBOX_ROUTE_BOOST").is_ok_and(|v| v.trim() == "1")
}

fn env_on(names: &[&str]) -> bool {
    for name in names {
        if let Ok(v) = std::env::var(name) {
            if !v.trim().is_empty() {
                return !matches!(v.trim().to_ascii_lowercase().as_str(), "0" | "false" | "off" | "no");
            }
        }
    }
    true
}

/// `ap_timeline_fanout_ingest_enabled` && `ap_timeline_fanout_rust_enabled`.
fn fanout_queue_on() -> bool {
    env_on(&["VAAK_HOME_FANOUT_INGEST", "VAAK_FEATURE_HOME_FANOUT_INGEST"])
        && env_on(&["VAAK_TIMELINE_FANOUT_RUST", "VAAK_FEATURE_TIMELINE_FANOUT_RUST"])
}

/// The like or boost to route, or the reason it stays PHP's.
pub(crate) fn plan(doc: &Value) -> std::result::Result<Interaction, &'static str> {
    let it = crate::inbox_route_shadow::interaction(doc).ok_or("not_like")?;
    if it.kind != "like" && it.kind != "reblog" {
        return Err("not_like");
    }
    if !matches!(doc.get("object"), Some(Value::String(_))) {
        return Err("embedded");
    }
    // ap_activity_text_summary keeps `content` as the event text.
    if doc.get("content").is_some_and(|c| !c.is_null()) {
        return Err("content");
    }
    if it.actor.trim_end_matches('/').starts_with("https://mkultra.monster/") {
        return Err("local_actor");
    }
    Ok(it)
}

/// `ap_visibility_from_activity` for a like (no embedded object).
pub(crate) fn visibility(doc: &Value) -> &'static str {
    let ids = |field: &str| -> Vec<String> {
        let items: Vec<&Value> = match doc.get(field) {
            Some(Value::String(s)) if !s.is_empty() => vec![doc.get(field).unwrap_or(&Value::Null)],
            Some(Value::Array(list)) => list.iter().collect(),
            Some(Value::Object(o)) => o.values().collect(),
            _ => Vec::new(),
        };
        items
            .into_iter()
            .filter_map(|v| match v {
                Value::String(s) if !s.is_empty() => Some(s.clone()),
                Value::Array(_) | Value::Object(_) => as_id(v),
                _ => None,
            })
            .collect()
    };
    let public = |id: &String| {
        id == "https://www.w3.org/ns/activitystreams#Public" || id == "as:Public" || id == "Public" || id.ends_with("#Public")
    };
    if ids("to").iter().any(public) {
        "public"
    } else if ids("cc").iter().any(public) {
        "unlisted"
    } else {
        "private"
    }
}

/// `ap_announce_original_actor_from_activity` for a local note URL.
fn note_author(object: &str) -> String {
    let o = object.trim_end_matches('/');
    if o.starts_with(&format!("{LOCAL_ACTOR}/")) {
        return LOCAL_ACTOR.to_string();
    }
    let rest = o.strip_prefix("https://mkultra.monster/users/").unwrap_or("");
    let key = rest.split('/').next().unwrap_or("");
    format!("https://mkultra.monster/users/{key}")
}

/// A notification stored, for the push job and the cache clears.
struct Stored {
    owner: i64,
    object_id: String,
}

struct Applied {
    spool_id: i64,
    stored: Option<Stored>,
    /// New events row id (None when PHP's boost dedupe kept the old one).
    event_id: Option<i64>,
}

#[allow(clippy::too_many_arguments)]
pub(crate) async fn route(
    db: &mut Client,
    redis: &mut redis::aio::ConnectionManager,
    queue_url: &str,
    cache_dir: &std::path::Path,
    blocked_domains: &[String],
    ip: &str,
    key_id: &str,
    path: &str,
    sig_path: &str,
    kind: &str,
    body: &[u8],
) -> Routed {
    let Ok(doc) = serde_json::from_slice::<Value>(body) else { return Routed::Defer("body") };
    let it = match plan(&doc) {
        Ok(it) => it,
        Err(why) => return Routed::Defer(why),
    };
    if it.kind == "reblog" && !fanout_queue_on() {
        return Routed::Defer("fanout_off");
    }
    let actor_host = crate::inbox_accept::host_of(&it.actor).unwrap_or_default();
    if actor_host.is_empty() || crate::inbox_route_delete::is_bridgy_host(&actor_host) {
        return Routed::Defer("actor_host");
    }
    let _ = blocked_domains; // The object is ours; PHP's object-host block never applies.
    if !crate::inbox_route_delete::schema_ok(db).await {
        return Routed::Defer("schema");
    }
    match crate::inbox_route_delete::actor_block_values(db).await {
        Ok(values) if !crate::inbox_route_delete::actor_block_defers(&values, &it.actor, &actor_host) => {}
        Ok(_) => return Routed::Defer("actor_block"),
        Err(_) => return Routed::Defer("db"),
    }
    let vis = visibility(&doc);
    // ap_activity_is_public (to/cc/bto/bcc), not the visibility label.
    if !it.public {
        match crate::inbox_route_shadow::object_is_private(db, &it.object).await {
            Ok(false) => {}
            Ok(true) => return Routed::Defer("private_object"),
            Err(_) => return Routed::Defer("db"),
        }
    }
    let activity_id = as_id(&doc);
    match apply(db, redis, &it, &doc, activity_id.as_deref(), vis, ip, key_id, path, sig_path, kind, body).await {
        Ok(Applied { spool_id, stored, event_id }) => {
            if let Some(s) = &stored {
                crate::notif_invalidate::drop_owner_list_caches(redis, s.owner).await;
                crate::notif_invalidate::unread_owners(redis, cache_dir, &[s.owner]).await;
                let push = if it.kind == "reblog" { "reblog" } else { "favourite" };
                push_job(queue_url, push, &it.actor, s).await;
            }
            let notify = event_id.map(|_| kind);
            crate::inbox_route_delete::notes_after_commit(db, key_id, kind, notify, &it.actor, Some(it.object.as_str())).await;
            if let (Some(eid), true) = (event_id, it.kind == "reblog") {
                fanout_jobs(db, redis, queue_url, eid, kind, &it.actor, &it.object, vis).await;
            }
            let who = crate::inbox_route_delete::short(&it.actor);
            let n = stored.as_ref().map_or(0, |s| s.owner);
            crate::inbox_route_delete::log_routed(spool_id, kind, body.len(), key_id, format!("local_observe actor={who} owner={n}"));
            Routed::Done { spool_id, result: "local_observe" }
        }
        Err(e) => {
            tracing::warn!(error = %e, kind, "inbox Like/Announce routing failed; spooling for PHP");
            Routed::Defer("error")
        }
    }
}

/// `ap_timeline_fanout_followers_home` + `ap_timeline_fanout_public_local_feed`
/// for a new boost row: the queue jobs PHP would push (the Rust queue path).
#[allow(clippy::too_many_arguments)]
async fn fanout_jobs(
    db: &Client,
    redis: &mut redis::aio::ConnectionManager,
    queue_url: &str,
    event_id: i64,
    kind: &str,
    actor: &str,
    object: &str,
    vis: &str,
) {
    let actor = actor.trim().trim_end_matches('/');
    let oid = object.trim_end_matches('/');
    let slash_actor = format!("{actor}/");
    let slash_oid = format!("{oid}/");
    // Skip the Home fan-out of a boost whose booster also has a Create of it.
    let self_create = db
        .query_opt(
            "SELECT 1 FROM events WHERE actor_id IN ($1, $2) AND (object_id = $3 OR object_id = $4)
               AND type IN ('Create', 'Quote', 'QuotePost', 'Update') AND action_taken IN ('log', 'local_observe') LIMIT 1",
            &[&actor, &slash_actor, &oid, &slash_oid],
        )
        .await
        .map(|r| r.is_some())
        .unwrap_or(false);
    let ts = crate::inbox_route_delete::now_unix();
    let id = event_id.to_string();
    let mut jobs = Vec::new();
    if !self_create {
        jobs.push(serde_json::json!({"op": "home_followers", "k": "event", "id": id, "s": "fediverse", "actor": actor, "type": kind, "ts": ts}));
    }
    if vis == "public" {
        jobs.push(serde_json::json!({"op": "public_feed", "k": "event", "id": id, "s": "fediverse", "actor": actor, "type": kind, "visibility": vis, "ts": ts}));
    }
    if jobs.is_empty() {
        return;
    }
    let mut c = match crate::inbox_accept::redis_conn(queue_url).await {
        Ok(c) => c,
        Err(e) => {
            tracing::warn!(error = %e, "boost fan-out jobs not queued");
            return;
        }
    };
    let key = format!("vaak:queue:{}", crate::timeline_fanout::QUEUE_NAME);
    for job in &jobs {
        let r: redis::RedisResult<()> =
            redis::pipe().lpush(&key, job.to_string()).ignore().expire(&key, 86_400).ignore().query_async(&mut c).await;
        if let Err(e) = r {
            tracing::warn!(error = %e, "boost fan-out job not queued");
            return;
        }
        // ap_redis_metric_inc('queue_push') on the cache Redis.
        let _: redis::RedisResult<()> = redis::pipe()
            .incr("vaak:metric:queue_push", 1)
            .ignore()
            .expire("vaak:metric:queue_push", 604_800)
            .ignore()
            .query_async(redis)
            .await;
    }
}

/// `ap_webpush_notify_event('favourite'|'reblog', …)` is PHP's; hand it the job.
async fn push_job(queue_url: &str, push: &str, actor: &str, s: &Stored) {
    let job = serde_json::json!({"type": push, "actor": actor, "object_id": s.object_id, "owner_user_id": s.owner}).to_string();
    let mut c = match crate::inbox_accept::redis_conn(queue_url).await {
        Ok(c) => c,
        Err(e) => {
            tracing::warn!(error = %e, "like push job not queued");
            return;
        }
    };
    let r: redis::RedisResult<()> =
        redis::pipe().lpush(WEBPUSH_QUEUE, &job).ignore().expire(WEBPUSH_QUEUE, 86_400).ignore().query_async(&mut c).await;
    if let Err(e) = r {
        tracing::warn!(error = %e, "like push job not queued");
    }
}

#[allow(clippy::too_many_arguments)]
async fn apply(
    db: &mut Client,
    redis: &mut redis::aio::ConnectionManager,
    it: &Interaction,
    doc: &Value,
    activity_id: Option<&str>,
    vis: &str,
    ip: &str,
    key_id: &str,
    path: &str,
    sig_path: &str,
    kind: &str,
    body: &[u8],
) -> Result<Applied> {
    let text = std::str::from_utf8(body)?;
    let (owner, owner_actor) = crate::inbox_route_shadow::recipient_owner(db, it, path).await?;
    let actor = it.actor.trim_end_matches('/');
    let mention = actor != owner_actor && it.object.trim_end_matches('/').starts_with(&format!("{owner_actor}/notes/"));
    let now = crate::fanout_delivery::iso(crate::inbox_route_delete::now_unix());
    let tx = db.transaction().await?;
    let mut stored = None;
    if mention {
        let mut object_id = crate::inbox_route_undo::stable_interaction_id(&it.object, &it.actor, it.kind);
        if let Some(act) = activity_id.filter(|a| !a.trim().is_empty()) {
            let act = act.trim();
            if let Some(row) = tx
                .query_opt(
                    "SELECT object_id FROM mentions WHERE owner_user_id = $1 AND activity_id = $2 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1",
                    &[&owner, &act],
                )
                .await?
            {
                let existing: Option<String> = row.get(0);
                if let Some(e) = existing.map(|e| e.trim().to_string()).filter(|e| !e.is_empty()) {
                    object_id = e;
                }
            }
        }
        let mtype = doc.get("type").and_then(Value::as_str).unwrap_or(kind);
        tx.execute(
            "INSERT INTO mentions (owner_user_id, owner_actor_id, created_at, activity_id, object_id, actor_id, type, content, in_reply_to,
                                   deleted_at, media_urls, activity_type, spoiler_text, sensitive, ask_actor, ask_question, ask_answer)
             VALUES ($1, $2, $3, $4, $5, $6, $7, NULL, NULL, NULL, NULL, $7, '', 0, '', '', '')
             ON CONFLICT(owner_user_id, object_id) DO UPDATE SET
               owner_actor_id = COALESCE(excluded.owner_actor_id, mentions.owner_actor_id),
               created_at = CASE WHEN mentions.deleted_at IS NOT NULL THEN excluded.created_at ELSE mentions.created_at END,
               activity_id = excluded.activity_id,
               actor_id = COALESCE(excluded.actor_id, mentions.actor_id),
               content = excluded.content,
               media_urls = COALESCE(excluded.media_urls, mentions.media_urls),
               activity_type = COALESCE(excluded.activity_type, mentions.activity_type),
               ask_actor = COALESCE(excluded.ask_actor, mentions.ask_actor),
               ask_question = COALESCE(excluded.ask_question, mentions.ask_question),
               ask_answer = COALESCE(excluded.ask_answer, mentions.ask_answer),
               spoiler_text = CASE WHEN excluded.spoiler_text != '' THEN excluded.spoiler_text ELSE mentions.spoiler_text END,
               sensitive = CASE WHEN excluded.sensitive = 1 THEN 1 ELSE mentions.sensitive END,
               deleted_at = NULL",
            &[&owner, &owner_actor, &now, &activity_id, &object_id, &it.actor, &mtype],
        )
        .await?;
        // ap_notification_projection_invalidate_owner: epoch first, then rows.
        crate::notif_invalidate::bump_owner_epoch(redis, owner).await;
        tx.execute("DELETE FROM ap_notification_projection WHERE owner_user_id = $1", &[&owner]).await?;
        stored = Some(Stored { owner, object_id });
    }
    let host = crate::inbox_accept::host_of(&it.actor);
    let bytes = body.len() as i64;
    let target = if it.kind == "reblog" { note_author(&it.object) } else { LOCAL_ACTOR.to_string() };
    // ap_metrics_record's firehose dedupe: one Announce row per object.
    let existing = if it.kind == "reblog" {
        tx.query_opt("SELECT id, visibility FROM events WHERE type = $1 AND object_id = $2 LIMIT 1", &[&kind, &it.object]).await?
    } else {
        None
    };
    let event_id = match existing {
        Some(row) => {
            let id: i64 = row.get(0);
            let old: Option<String> = row.get(1);
            if vis != "public" && normalize_visibility(old.as_deref().unwrap_or("")) == "public" {
                tx.execute("UPDATE events SET visibility = $1 WHERE id = $2", &[&vis, &id]).await?;
            }
            None
        }
        None => {
            let row = tx
                .query_one(
                    "INSERT INTO events (created_at, type, actor_id, object_id, target_actor, host, bytes, action_taken, summary, media_urls, in_reply_to, spoiler_text, sensitive, visibility)
                     VALUES ($1, $2, $3, $4, $5, $6, $7::bigint, 'local_observe', NULL, NULL, NULL, '', 0, $8) RETURNING id",
                    &[&now, &kind, &it.actor, &it.object, &target, &host, &bytes, &vis],
                )
                .await?;
            Some(row.get::<_, i64>(0))
        }
    };
    let spool_id = crate::inbox_route_delete::insert_spool_done(&tx, ip, key_id, path, sig_path, kind, text, "local_observe").await?;
    tx.commit().await?;
    Ok(Applied { spool_id, stored, event_id })
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

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn like(extra: Value) -> Value {
        let mut d = json!({"type": "Like", "id": "https://m.test/users/a#likes/1", "actor": "https://m.test/users/a",
            "object": "https://mkultra.monster/users/cmdr_nova/notes/abc"});
        if let (Value::Object(d), Value::Object(e)) = (&mut d, extra) {
            d.extend(e);
        }
        d
    }

    #[test]
    fn plain_likes_are_routed() {
        assert!(plan(&like(json!({}))).is_ok());
        assert!(plan(&like(json!({"type": "EmojiReact"}))).is_ok());
    }

    #[test]
    fn other_shapes_stay_php() {
        assert_eq!(plan(&like(json!({"content": ":blobcat:"}))).unwrap_err(), "content");
        assert_eq!(plan(&like(json!({"object": {"id": "https://mkultra.monster/users/cmdr_nova/notes/abc"}}))).unwrap_err(), "embedded");
        assert_eq!(plan(&like(json!({"actor": "https://mkultra.monster/users/luna"}))).unwrap_err(), "local_actor");
        assert_eq!(plan(&like(json!({"type": "Announce", "object": {"object": "https://mkultra.monster/users/cmdr_nova/notes/abc"}}))).unwrap_err(), "not_like");
        assert_eq!(plan(&like(json!({"object": "https://m.test/notes/1"}))).unwrap_err(), "not_like");
    }

    #[test]
    fn plain_boosts_are_routed() {
        let it = plan(&like(json!({"type": "Announce"}))).unwrap();
        assert_eq!(it.kind, "reblog");
        assert_eq!(note_author("https://mkultra.monster/users/cmdr_nova/notes/abc"), LOCAL_ACTOR);
        assert_eq!(note_author("https://mkultra.monster/users/shana_flux/notes/x1/"), "https://mkultra.monster/users/shana_flux");
        assert_eq!(normalize_visibility("followers"), "private");
        assert_eq!(normalize_visibility(""), "public");
    }

    #[test]
    fn visibility_from_to_and_cc() {
        assert_eq!(visibility(&like(json!({}))), "private");
        assert_eq!(visibility(&like(json!({"to": "https://www.w3.org/ns/activitystreams#Public"}))), "public");
        assert_eq!(visibility(&like(json!({"to": ["https://m.test/x"], "cc": ["as:Public"]}))), "unlisted");
        assert_eq!(visibility(&like(json!({"to": [{"id": "https://x.test/c#Public"}]}))), "public");
    }
}
