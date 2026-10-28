//! Nostr notes VAAK users boost or quote, on VAAK.
//!
//! A boost or a quote is a `masto_reblogs` row like an RSS boost: it never
//! federates and has no stand-in account. `status_id` is `nostr:{note}`
//! (the boosted note, or the user's own quote note), `boost_status_id`
//! `nostr-boost:{note}:{owner}`. Local shows every row, Home shows the rows
//! of local accounts the viewer follows, and the profile Boosts tab shows
//! the account's own rows. The signed Nostr repost (kind 6) or quote note
//! is published first; `act::publish` records it in `nostr_shares`.

use std::collections::HashMap;

use anyhow::{Context, Result};
use serde_json::{json, Value};
use tokio_postgres::Client;

use super::event::is_hex64;

pub const LOCAL_ACTOR_PREFIX: &str = "https://mkultra.monster/users/";

/// The note id inside a `nostr:{id}` / `nostr-boost:{id}:{owner}` key.
pub fn key_note_id(key: &str) -> Option<String> {
    let key = key.trim();
    let rest = key.strip_prefix("nostr:").or_else(|| key.strip_prefix("nostr-boost:"))?;
    let id = rest.get(..64)?.to_ascii_lowercase();
    (is_hex64(&id) && matches!(rest.as_bytes().get(64), None | Some(b':'))).then_some(id)
}

/// `(status_id, boost_status_id, announce_activity_id)` for one owner's
/// boost of `note` on VAAK.
pub fn reblog_keys(note: &str, owner: i64, quote: bool) -> (String, String, String) {
    (
        format!("nostr:{note}"),
        format!("nostr-boost:{note}:{owner}"),
        format!("local:nostr-{}:{note}:{owner}", if quote { "quote" } else { "boost" }),
    )
}

/// Show the owner's boost (or quote, `note` being their quote note) on
/// VAAK. Same columns PHP `ap_masto_reblog_add` writes; never federates.
pub async fn mirror_boost(db: &Client, owner: i64, note: &str, quote: bool, now: i64) -> Result<()> {
    let actor: String = db
        .query_opt("SELECT COALESCE(actor_id, '') FROM ap_users WHERE id::bigint = $1", &[&owner])
        .await
        .context("boost owner actor")?
        .map(|r| r.get(0))
        .unwrap_or_default();
    if !actor.starts_with(LOCAL_ACTOR_PREFIX) {
        anyhow::bail!("owner {owner} has no local actor");
    }
    let (status_id, boost_id, announce) = reblog_keys(note, owner, quote);
    db.execute(
        "INSERT INTO masto_reblogs (owner_user_id, owner_actor_id, status_id, boost_status_id, object_id, target_actor, announce_activity_id, created_at)
         VALUES ($1, $2, $3, $4, $3, NULL, $5, $6)
         ON CONFLICT (owner_user_id, status_id) DO UPDATE SET
           boost_status_id = EXCLUDED.boost_status_id, announce_activity_id = EXCLUDED.announce_activity_id",
        &[&owner, &actor, &status_id, &boost_id, &announce, &crate::timeline_older::nostr_at(now)],
    )
    .await
    .context("nostr boost row")?;
    Ok(())
}

/// Take the owner's boost or quote of `note` off VAAK.
pub async fn unmirror_boost(db: &Client, owner: i64, note: &str) -> Result<()> {
    db.execute("DELETE FROM masto_reblogs WHERE owner_user_id::bigint = $1 AND status_id = $2", &[&owner, &format!("nostr:{note}")])
        .await
        .context("nostr unboost row")?;
    Ok(())
}

/// Painted Nostr cards by note id, with quoted notes attached.
pub async fn cards(db: &Client, ids: &[String]) -> HashMap<String, Value> {
    if ids.is_empty() {
        return HashMap::new();
    }
    let rows = super::home::fetch_rows(db, ids).await.unwrap_or_else(|e| {
        tracing::warn!(error = %format!("{e:#}"), "nostr boost cards skipped");
        HashMap::new()
    });
    let mut ids: Vec<String> = Vec::with_capacity(rows.len());
    let mut statuses: Vec<Value> = Vec::with_capacity(rows.len());
    for (id, row) in rows {
        ids.push(id);
        statuses.push(super::render::materialize(&row));
    }
    attach_quotes(db, &mut statuses).await;
    ids.into_iter().zip(statuses).collect()
}

fn quote_holder(st: &mut Value) -> Option<&mut Value> {
    if st.get("reblog").is_some_and(Value::is_object) {
        return st.get_mut("reblog");
    }
    Some(st)
}

/// Fill `quote` on Nostr cards whose note quotes another (NIP-18 `q` tag),
/// boost wrappers included. A quoted note VAAK has not stored becomes a
/// "Quoted note" link instead.
pub async fn attach_quotes(db: &Client, statuses: &mut [Value]) {
    let mut wanted = Vec::new();
    for st in statuses.iter_mut() {
        if let Some(q) = quote_holder(st).and_then(|s| s.get("vaak_nostr_quote_id")).and_then(Value::as_str) {
            if !wanted.iter().any(|w| w == q) {
                wanted.push(q.to_string());
            }
        }
    }
    if wanted.is_empty() {
        return;
    }
    let rows = super::home::fetch_rows(db, &wanted).await.unwrap_or_default();
    for st in statuses.iter_mut() {
        let Some(holder) = quote_holder(st) else { continue };
        let Some(q) = holder.get("vaak_nostr_quote_id").and_then(Value::as_str).map(str::to_owned) else { continue };
        if holder.get("quote").is_some_and(Value::is_object) {
            continue;
        }
        match rows.get(&q) {
            Some(row) => {
                let mut quoted = super::render::materialize(row);
                // One level only, like other quote cards.
                if let Some(obj) = quoted.as_object_mut() {
                    obj.remove("vaak_nostr_quote_id");
                }
                holder["quote"] = json!({"state": "accepted", "quoted_status": quoted, "quoted_status_id": format!("nostr:{q}")});
            }
            None => {
                let href = format!("https://njump.me/{}", super::bech32::note(&q));
                let content = holder.get("content").and_then(Value::as_str).unwrap_or("").to_string();
                holder["content"] = json!(format!(
                    "{content}<p><a href=\"{href}\" target=\"_blank\" rel=\"nofollow noopener noreferrer\">Quoted note</a></p>"
                ));
            }
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct BoostKey {
    pub status_id: String,
    pub sort: i64,
}

/// Newest-first Nostr boosts by local accounts `owner` follows, and the
/// owner's own when `include_owner`, at or before `at_most` (unix seconds).
pub async fn home_boost_keys(db: &Client, owner: i64, include_owner: bool, at_most: i64, limit: i64) -> Result<Vec<BoostKey>> {
    if owner < 1 || super::sync::disabled_by_env() {
        return Ok(Vec::new());
    }
    let rows = db
        .query(
            "SELECT r.status_id, EXTRACT(EPOCH FROM r.created_at::timestamptz)::bigint AS at
             FROM masto_reblogs r
             WHERE r.status_id LIKE 'nostr:%'
               AND r.owner_actor_id LIKE $4
               AND ((r.owner_user_id::bigint = $1 AND $2)
                    OR (r.owner_user_id::bigint <> $1 AND rtrim(r.owner_actor_id, '/') IN (
                        SELECT rtrim(f.actor_id, '/') FROM following f
                        JOIN ap_users u ON rtrim(f.owner_actor_id, '/') = rtrim(u.actor_id, '/')
                        WHERE u.id::bigint = $1)))
               AND EXTRACT(EPOCH FROM r.created_at::timestamptz) <= $3::double precision
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT $5",
            &[&owner, &include_owner, &(at_most as f64), &format!("{LOCAL_ACTOR_PREFIX}%"), &limit],
        )
        .await
        .context("nostr boosts for home")?;
    Ok(rows
        .iter()
        .map(|r| BoostKey { status_id: r.get(0), sort: r.get(1) })
        .filter(|k| key_note_id(&k.status_id).is_some())
        .collect())
}

/// Put VAAK boosts of Nostr notes into a ranked Home list by time. A note
/// already on Home by itself (followed on Nostr) is not repeated.
pub fn merge_home_boosts(ranked: Vec<Value>, keys: &[BoostKey]) -> Vec<Value> {
    let present: std::collections::HashSet<String> = ranked
        .iter()
        .filter_map(|r| {
            let id = r.get("id").and_then(Value::as_str)?;
            match r.get("k").and_then(Value::as_str)? {
                "nostr" => Some(id.to_string()),
                "boost" => key_note_id(id),
                _ => None,
            }
        })
        .collect();
    let mut seen = std::collections::HashSet::new();
    let queued: Vec<Value> = keys
        .iter()
        .filter(|k| key_note_id(&k.status_id).is_some_and(|id| !present.contains(&id) && seen.insert(id)))
        .map(|k| json!({"k": "boost", "id": k.status_id, "s": "boost", "sort": k.sort}))
        .collect();
    super::home::interleave(ranked, queued)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn keys_round_trip() {
        let id = "ab".repeat(32);
        let (status, boost, announce) = reblog_keys(&id, 7, false);
        assert_eq!(status, format!("nostr:{id}"));
        assert_eq!(key_note_id(&status).as_deref(), Some(id.as_str()));
        assert_eq!(key_note_id(&boost).as_deref(), Some(id.as_str()));
        assert_eq!(announce, format!("local:nostr-boost:{id}:7"));
        assert_eq!(reblog_keys(&id, 7, true).2, format!("local:nostr-quote:{id}:7"));
        assert!(key_note_id("nostr:xyz").is_none());
        assert!(key_note_id(&format!("nostr:{id}zz")).is_none());
        assert!(key_note_id(&format!("rss:{id}")).is_none());
    }

    #[test]
    fn home_boosts_skip_notes_already_shown() {
        let a = "aa".repeat(32);
        let b = "bb".repeat(32);
        let c = "cc".repeat(32);
        let ranked = vec![json!({"k": "event", "id": "1", "sort": 300}), json!({"k": "nostr", "id": a, "sort": 100})];
        let keys = vec![
            BoostKey { status_id: format!("nostr:{b}"), sort: 400 },
            BoostKey { status_id: format!("nostr:{a}"), sort: 350 },
            BoostKey { status_id: format!("nostr:{c}"), sort: 200 },
            BoostKey { status_id: format!("nostr:{c}"), sort: 150 },
        ];
        let out: Vec<String> = merge_home_boosts(ranked, &keys)
            .iter()
            .map(|v| format!("{}:{}", v["k"].as_str().unwrap(), v["id"].as_str().unwrap()))
            .collect();
        assert_eq!(out, vec![format!("boost:nostr:{b}"), "event:1".to_string(), format!("boost:nostr:{c}"), format!("nostr:{a}")]);
    }
}
