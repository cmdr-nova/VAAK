//! Rust routes Undo of Like/EmojiReact/Announce/Quote itself (cutover F4).
//!
//! With `VAAK_INBOX_ROUTE_UNDO=1`, an `Undo` the front door would spool
//! (signer is the actor) whose object is one of those activities is routed
//! here, like `inbox_route_delete` does for Deletes. It mirrors the Undo
//! branch of `ap_route_verified_activity`:
//!
//! - the undoer is the verified sender, unless the inner activity names a
//!   different actor (then nothing is undone, as in PHP);
//! - `ap_mention_soft_delete_interaction(who, target, kind, innerId)`: by
//!   the inner activity id, and by the stable `target#kind-hash` id or the
//!   actor's legacy `target#kind-…` rows;
//! - `ap_events_mark_interaction_undone` for Announce and Quote;
//! - `ap_metrics_record('Undo', …, 'local_undo_<kind>')`;
//! - when mention rows changed, the full notification invalidation
//!   (`notif_invalidate`).
//!
//! Undo of a Follow, of a bare activity URL, or of anything else stays PHP's,
//! as do the same host and block cases as Deletes.

use anyhow::Result;
use serde_json::Value;
use sha2::{Digest as _, Sha256};
use tokio_postgres::Client;

use crate::inbox_route_delete::Routed;
use crate::inbox_route_shadow::as_id;

const LOCAL_ACTOR: &str = "https://mkultra.monster/users/cmdr_nova";

pub(crate) fn enabled() -> bool {
    std::env::var("VAAK_INBOX_ROUTE_UNDO").is_ok_and(|v| v.trim() == "1")
}

/// What the Undo branch needs from the body.
#[derive(Debug, PartialEq, Eq)]
pub(crate) struct Plan {
    pub actor: String,
    /// `ap_as_id($activity['object'])`, for the metrics row.
    pub object_id: Option<String>,
    pub inner_type: String,
    /// `local_undo_<kind>`'s kind: like, reblog or quote.
    pub kind: &'static str,
    /// The undoer, or None when the inner activity names someone else.
    pub who: Option<String>,
    pub target: Option<String>,
    pub inner_id: Option<String>,
}

pub(crate) fn plan(doc: &Value) -> Option<Plan> {
    if doc.get("type")?.as_str()? != "Undo" {
        return None;
    }
    let actor = doc.get("actor").and_then(as_id)?;
    let inner = doc.get("object")?;
    let Value::Object(o) = inner else { return None };
    let inner_type = o.get("type").and_then(Value::as_str).unwrap_or("").to_string();
    let kind = match inner_type.as_str() {
        "Announce" => "reblog",
        "Like" | "EmojiReact" => "like",
        "Quote" | "QuotePost" => "quote",
        _ => return None,
    };
    let inner_actor = o.get("actor").and_then(as_id);
    let who = match inner_actor {
        None => Some(actor.clone()),
        Some(a) if a == actor => Some(actor.clone()),
        Some(_) => None,
    };
    Some(Plan {
        object_id: as_id(inner),
        inner_id: as_id(inner),
        target: o.get("object").and_then(as_id),
        actor,
        inner_type,
        kind,
        who,
    })
}

/// `ap_mention_interaction_object_id`.
pub(crate) fn stable_interaction_id(target: &str, actor: &str, kind: &str) -> String {
    let target = target.trim_end_matches('/');
    let actor = actor.trim_end_matches('/');
    let hash = hex::encode(Sha256::digest(format!("{actor}|{target}|{kind}").as_bytes()));
    format!("{target}#{kind}-{}", &hash[..12])
}

/// `ap_events_mark_interaction_undone`'s types.
fn undone_event_types(inner_type: &str) -> &'static [&'static str] {
    match inner_type {
        "Announce" => &["Announce"],
        "Quote" | "QuotePost" => &["Quote", "QuotePost"],
        _ => &[],
    }
}

#[allow(clippy::too_many_arguments)]
pub(crate) async fn route(
    db: &mut Client,
    redis: &mut redis::aio::ConnectionManager,
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
    let Some(plan) = plan(&doc) else { return Routed::Defer("not_undo") };
    let actor_host = crate::inbox_accept::host_of(&plan.actor).unwrap_or_default();
    if actor_host.is_empty() || crate::inbox_route_delete::is_bridgy_host(&actor_host) {
        return Routed::Defer("actor_host");
    }
    if let Some(oid) = plan.object_id.as_deref().filter(|o| o.starts_with("https://")) {
        if let Some(h) = crate::inbox_accept::host_of(oid) {
            if crate::inbox_accept::host_blocked(&h, blocked_domains) {
                return Routed::Defer("object_blocked");
            }
        }
    }
    if !crate::inbox_route_delete::schema_ok(db).await {
        return Routed::Defer("schema");
    }
    match crate::inbox_route_delete::actor_block_values(db).await {
        Ok(values) if !crate::inbox_route_delete::actor_block_defers(&values, &plan.actor, &actor_host) => {}
        Ok(_) => return Routed::Defer("actor_block"),
        Err(_) => return Routed::Defer("db"),
    }
    match apply(db, redis, &plan, ip, key_id, path, sig_path, kind, body).await {
        Ok((spool_id, result, touched, owners)) => {
            if touched > 0 {
                crate::notif_invalidate::drop_list_caches(redis).await;
                crate::notif_invalidate::unread_owners(redis, cache_dir, &owners).await;
            }
            crate::inbox_route_delete::notes_after_commit(db, key_id, kind, "Undo", &plan.actor, plan.object_id.as_deref()).await;
            let who = crate::inbox_route_delete::short(plan.who.as_deref().unwrap_or(""));
            crate::inbox_route_delete::log_routed(spool_id, kind, body.len(), key_id, format!("{result} actor={who} n={touched}"));
            Routed::Done { spool_id, result }
        }
        Err(e) => {
            tracing::warn!(error = %e, "inbox Undo routing failed; spooling for PHP");
            Routed::Defer("error")
        }
    }
}

fn result_for(kind: &str) -> &'static str {
    match kind {
        "reblog" => "local_undo_reblog",
        "quote" => "local_undo_quote",
        _ => "local_undo_like",
    }
}

#[allow(clippy::too_many_arguments)]
async fn apply(
    db: &mut Client,
    redis: &mut redis::aio::ConnectionManager,
    plan: &Plan,
    ip: &str,
    key_id: &str,
    path: &str,
    sig_path: &str,
    kind: &str,
    body: &[u8],
) -> Result<(i64, &'static str, i64, Vec<i64>)> {
    let text = std::str::from_utf8(body)?;
    let tx = db.transaction().await?;
    let now = crate::fanout_delivery::iso(crate::inbox_route_delete::now_unix());
    let mut touched: i64 = 0;
    let mut owners: Vec<i64> = Vec::new();
    let mut owner_rows = |rows: Vec<tokio_postgres::Row>| {
        for r in rows {
            if let Some(o) = r.get::<_, Option<i64>>(0) {
                owners.push(o);
            }
        }
    };
    if let Some(who) = plan.who.as_deref() {
        // ap_mention_soft_delete_interaction($who, $target ?: '', $kind, $innerId).
        let actor = who.trim_end_matches('/');
        let target = plan.target.as_deref().unwrap_or("").trim_end_matches('/');
        if let Some(act) = plan.inner_id.as_deref().filter(|a| !a.is_empty()) {
            owner_rows(
                tx.query("SELECT DISTINCT owner_user_id::bigint FROM mentions WHERE deleted_at IS NULL AND activity_id = $1", &[&act])
                    .await?,
            );
            touched += tx
                .execute("UPDATE mentions SET deleted_at = $1 WHERE deleted_at IS NULL AND activity_id = $2", &[&now, &act])
                .await? as i64;
        }
        if !actor.is_empty() && !target.is_empty() {
            let stable = stable_interaction_id(target, actor, plan.kind);
            let slash = format!("{actor}/");
            // Not escaped, as in PHP.
            let like = format!("{target}#{}-%", plan.kind);
            let filter = "deleted_at IS NULL AND (object_id = $1 OR ((actor_id = $2 OR actor_id = $3) AND object_id LIKE $4))";
            owner_rows(
                tx.query(&format!("SELECT DISTINCT owner_user_id::bigint FROM mentions WHERE {filter}"), &[&stable, &actor, &slash, &like])
                    .await?,
            );
            touched += tx
                .execute(&format!("UPDATE mentions SET deleted_at = $5 WHERE {filter}"), &[&stable, &actor, &slash, &like, &now])
                .await? as i64;
        }
        // ap_events_mark_interaction_undone($innerType, $who, $target, $innerId).
        let types = undone_event_types(&plan.inner_type);
        let ev_actor = who.trim().trim_end_matches('/');
        let ev_target = plan.target.as_deref().unwrap_or("").trim().trim_end_matches('/');
        let ev_act = plan.inner_id.as_deref().unwrap_or("").trim().trim_end_matches('/');
        if !types.is_empty() && !ev_actor.is_empty() && !(ev_target.is_empty() && ev_act.is_empty()) {
            let types: Vec<String> = types.iter().map(|t| t.to_string()).collect();
            let n = tx
                .execute(
                    "UPDATE events SET action_taken = 'deleted'
                     WHERE type = ANY($1) AND rtrim(actor_id, '/') = $2
                       AND (($3 <> '' AND rtrim(object_id, '/') = $3) OR ($4 <> '' AND rtrim(object_id, '/') = $4))
                       AND action_taken IN ('log', 'local_observe', 'local_fav_update')",
                    &[&types, &ev_actor, &ev_target, &ev_act],
                )
                .await? as i64;
            if n > 0 && !ev_target.is_empty() {
                let slash = format!("{ev_target}/");
                tx.execute("DELETE FROM ap_notification_projection WHERE status_id = $1 OR status_id = $2", &[&ev_target, &slash])
                    .await?;
            }
        }
    }
    let result = result_for(plan.kind);
    crate::inbox_route_delete::insert_metrics(&tx, &now, "Undo", Some(plan.actor.as_str()), plan.object_id.as_deref(), Some(LOCAL_ACTOR), body.len(), result)
        .await?;
    let spool_id = crate::inbox_route_delete::insert_spool_done(&tx, ip, key_id, path, sig_path, kind, text, result).await?;
    if touched > 0 {
        tx.execute("DELETE FROM ap_notification_projection", &[]).await?;
        crate::notif_invalidate::bump_server_epoch(redis).await;
    }
    tx.commit().await?;
    Ok((spool_id, result, touched, owners))
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn stable_id_matches_php() {
        // php: ap_mention_interaction_object_id('https://m.test/n/1/', 'https://a.test/u/x/', 'like')
        assert_eq!(stable_interaction_id("https://m.test/n/1/", "https://a.test/u/x/", "like"), "https://m.test/n/1#like-379e16d6d075");
    }

    #[test]
    fn undo_kinds() {
        let mk = |t: &str| json!({"type": "Undo", "actor": "https://a.test/u/x", "object": {"id": "https://a.test/l/1", "type": t, "actor": "https://a.test/u/x", "object": "https://mkultra.monster/n/1"}});
        assert_eq!(plan(&mk("Like")).unwrap().kind, "like");
        assert_eq!(plan(&mk("EmojiReact")).unwrap().kind, "like");
        assert_eq!(plan(&mk("Announce")).unwrap().kind, "reblog");
        assert_eq!(plan(&mk("QuotePost")).unwrap().kind, "quote");
        assert!(plan(&mk("Follow")).is_none());
        assert!(plan(&mk("Block")).is_none());
        let p = plan(&mk("Like")).unwrap();
        assert_eq!(p.object_id.as_deref(), Some("https://a.test/l/1"));
        assert_eq!(p.target.as_deref(), Some("https://mkultra.monster/n/1"));
        assert_eq!(p.who.as_deref(), Some("https://a.test/u/x"));
    }

    #[test]
    fn bare_and_foreign_inner_actor() {
        assert!(plan(&json!({"type": "Undo", "actor": "https://a.test/u/x", "object": "https://a.test/l/1"})).is_none());
        let p = plan(&json!({"type": "Undo", "actor": "https://a.test/u/x",
            "object": {"type": "Like", "actor": "https://a.test/u/other", "object": "https://m.test/n"}})).unwrap();
        assert_eq!(p.who, None);
        let p = plan(&json!({"type": "Undo", "actor": "https://a.test/u/x", "object": {"type": "Like", "object": "https://m.test/n"}})).unwrap();
        assert_eq!(p.who.as_deref(), Some("https://a.test/u/x"));
        assert_eq!(p.inner_id, None);
    }

    #[test]
    fn event_types() {
        assert_eq!(undone_event_types("Announce"), &["Announce"]);
        assert_eq!(undone_event_types("Quote"), &["Quote", "QuotePost"]);
        assert!(undone_event_types("Like").is_empty());
    }
}
