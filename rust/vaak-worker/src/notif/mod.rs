//! Shadow notification unread badge (parity with PHP ap_masto_notifications_unread_state).

use anyhow::{Context, Result};
use serde::Serialize;
use sha2::{Digest, Sha256};
use tokio_postgres::Client;

use crate::config::Config;
use crate::redis_util;

#[derive(Debug, Clone, Serialize)]
pub struct UnreadState {
    pub count: i64,
    pub last_read_id: String,
    pub latest_unread_id: String,
    pub latest_id: String,
    pub scan: i64,
    pub owner_user_id: i64,
    pub source: &'static str,
}

pub async fn compute_unread(db: &Client, owner_user_id: i64, scan: i64) -> Result<UnreadState> {
    let scan = scan.clamp(1, 80);
    let last_read = load_last_read_id(db, owner_user_id).await?;
    let owner_actor = load_owner_actor_id(db, owner_user_id).await?;

    let mut ids: Vec<String> = Vec::new();

    let mention_rows = db
        .query(
            "SELECT id, created_at, activity_id
             FROM mentions
             WHERE owner_user_id = $1 AND deleted_at IS NULL
             ORDER BY id DESC
             LIMIT 250",
            &[&owner_user_id],
        )
        .await
        .context("select mentions")?;
    let mut seen_activity = std::collections::HashSet::new();
    for row in mention_rows {
        let activity_id: String = row
            .try_get::<_, Option<String>>(2)?
            .unwrap_or_default()
            .trim()
            .to_string();
        if !activity_id.is_empty() && !seen_activity.insert(activity_id) {
            continue;
        }
        let id: i64 = row.get(0);
        let created: Option<String> = row.try_get(1).ok().flatten();
        ids.push(notif_id_for_row(id, created.as_deref()));
    }

    let follow_rows = db
        .query(
            "SELECT id, created_at FROM events
             WHERE type = 'Follow'
               AND action_taken IN ('local_accept_followback', 'bsky_follow')
               AND (target_actor = $1 OR target_actor = $2)
             ORDER BY id DESC
             LIMIT 100",
            &[&owner_actor, &format!("{owner_actor}/")],
        )
        .await
        .context("select follow events")?;
    for row in follow_rows {
        let id: i64 = row.get(0);
        let created: Option<String> = row.try_get(1).ok().flatten();
        ids.push(notif_id_for_row(id, created.as_deref()));
    }

    // Newest-first by snowflake length then lexicographic (matches PHP).
    ids.sort_by(|a, b| b.len().cmp(&a.len()).then_with(|| b.cmp(a)));
    ids.truncate(scan as usize);

    let mut count = 0_i64;
    let mut latest_id = String::new();
    let mut latest_unread_id = String::new();
    for nid_raw in &ids {
        let nid: String = nid_raw.chars().filter(|c| c.is_ascii_digit()).collect();
        let nid = if nid.is_empty() {
            "0".to_string()
        } else {
            nid
        };
        if latest_id.is_empty() {
            latest_id = nid.clone();
        }
        let unread = if nid.len() == last_read.len() {
            nid.as_str() > last_read.as_str()
        } else {
            nid.len() > last_read.len()
        };
        if unread {
            count += 1;
            if latest_unread_id.is_empty() {
                latest_unread_id = nid;
            }
        }
    }

    Ok(UnreadState {
        count,
        last_read_id: last_read,
        latest_unread_id,
        latest_id,
        scan,
        owner_user_id,
        source: "vaak-worker-shadow",
    })
}

async fn load_last_read_id(db: &Client, owner_user_id: i64) -> Result<String> {
    let row = db
        .query_opt(
            "SELECT last_read_id FROM masto_markers
             WHERE owner_user_id = $1 AND timeline = 'notifications'
             LIMIT 1",
            &[&owner_user_id],
        )
        .await
        .context("select masto_markers")?;
    let Some(row) = row else {
        return Ok("0".to_string());
    };
    let last: Option<String> = row.try_get(0).ok().flatten();
    let last = last.unwrap_or_else(|| "0".to_string());
    let digits: String = last.chars().filter(|c| c.is_ascii_digit()).collect();
    Ok(if digits.is_empty() {
        "0".into()
    } else {
        digits
    })
}

async fn load_owner_actor_id(db: &Client, owner_user_id: i64) -> Result<String> {
    if let Ok(Some(row)) = db
        .query_opt(
            "SELECT actor_id FROM ap_users WHERE id = $1 LIMIT 1",
            &[&owner_user_id],
        )
        .await
    {
        let actor: Option<String> = row.try_get(0).ok().flatten();
        if let Some(actor) = actor {
            if actor.starts_with("https://") {
                return Ok(actor.trim_end_matches('/').to_string());
            }
        }
    }
    Ok("https://mkultra.monster/users/cmdr_nova".to_string())
}

fn notif_id_for_row(id: i64, created_at: Option<&str>) -> String {
    if let Some(ts) = created_at.and_then(parse_epoch_millis) {
        format!("{ts}{id:06}")
    } else {
        format!("{id}")
    }
}

fn parse_epoch_millis(s: &str) -> Option<i64> {
    if let Ok(secs) = chrono::DateTime::parse_from_rfc3339(s) {
        return Some(secs.timestamp_millis());
    }
    if let Ok(secs) = chrono::DateTime::parse_from_str(s, "%Y-%m-%d %H:%M:%S%.f%z") {
        return Some(secs.timestamp_millis());
    }
    if let Ok(secs) = chrono::DateTime::parse_from_str(s, "%Y-%m-%dT%H:%M:%S%.f%z") {
        return Some(secs.timestamp_millis());
    }
    None
}

fn live_redis_key(owner_user_id: i64, scan: i64, last_read: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(last_read.as_bytes());
    let hash = hex::encode(hasher.finalize());
    format!("vaak:notifications:v1:unread:{owner_user_id}:{scan}:{hash}")
}

fn shadow_redis_key(owner_user_id: i64, scan: i64, last_read: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(last_read.as_bytes());
    let hash = hex::encode(hasher.finalize());
    format!("vaak:shadow:notifications:v1:unread:{owner_user_id}:{scan}:{hash}")
}

pub async fn run_once(cfg: &Config, owner_user_id: i64, compare: bool) -> Result<UnreadState> {
    let db = crate::db::connect(&cfg.database_url).await?;
    let state = compute_unread(&db, owner_user_id, 80).await?;

    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let payload = serde_json::json!({
        "c": state.count,
        "u": state.latest_unread_id,
        "l": state.latest_id,
        "ts": chrono::Utc::now().timestamp(),
        "source": state.source,
    });
    let shadow_key = shadow_redis_key(owner_user_id, state.scan, &state.last_read_id);
    redis_util::json_set(&mut redis, &shadow_key, &payload, 120).await?;

    if compare {
        let live_key = live_redis_key(owner_user_id, state.scan, &state.last_read_id);
        let live = redis_util::json_get(&mut redis, &live_key).await?;
        tracing::info!(%shadow_key, live = ?live, "notif shadow compare");
        println!(
            "{}",
            serde_json::json!({
                "shadow": state,
                "shadow_redis_key": shadow_key,
                "live_redis_key": live_key,
                "live_cache": live,
            })
        );
    } else {
        println!("{}", serde_json::to_string_pretty(&state)?);
    }
    Ok(state)
}

pub async fn run_loop(cfg: &Config, owner_user_id: i64, interval_secs: u64, compare: bool) -> Result<()> {
    let interval = std::time::Duration::from_secs(interval_secs.max(5));
    loop {
        match run_once(cfg, owner_user_id, compare).await {
            Ok(state) => tracing::info!(count = state.count, "notif shadow ok"),
            Err(e) => tracing::error!(error = %e, "notif shadow failed"),
        }
        tokio::time::sleep(interval).await;
    }
}
