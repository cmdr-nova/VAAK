//! Shadow action-queue inspector (never claims jobs from PHP).

use anyhow::{Context, Result};
use serde::Serialize;

use crate::config::Config;

#[derive(Debug, Serialize)]
pub struct PendingAction {
    pub id: i64,
    pub owner_user_id: i64,
    pub platform: String,
    pub action_kind: String,
    pub target_key: String,
    pub desired_state: i32,
    pub status: String,
    pub attempts: i32,
    pub next_attempt_at: Option<String>,
    pub last_error: Option<String>,
    pub updated_at: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct ActionQueueReport {
    pub pending: usize,
    pub processing: usize,
    pub sample: Vec<PendingAction>,
    pub source: &'static str,
    pub note: &'static str,
}

pub async fn report(cfg: &Config, limit: i64) -> Result<ActionQueueReport> {
    let db = crate::db::connect(&cfg.database_url).await?;
    let limit = limit.clamp(1, 100);

    let pending: i64 = db
        .query_one(
            "SELECT COUNT(*)::bigint FROM ap_action_queue WHERE status = 'pending'",
            &[],
        )
        .await
        .context("count pending")?
        .get(0);
    let processing: i64 = db
        .query_one(
            "SELECT COUNT(*)::bigint FROM ap_action_queue WHERE status = 'processing'",
            &[],
        )
        .await
        .context("count processing")?
        .get(0);

    let rows = db
        .query(
            "SELECT id, owner_user_id, platform, action_kind, target_key, desired_state,
                    status, attempts, next_attempt_at, last_error, updated_at
             FROM ap_action_queue
             WHERE status IN ('pending', 'processing')
             ORDER BY next_attempt_at NULLS FIRST, id
             LIMIT $1",
            &[&limit],
        )
        .await
        .context("list action queue")?;

    let mut sample = Vec::new();
    for row in rows {
        sample.push(PendingAction {
            id: row.get(0),
            owner_user_id: row.get(1),
            platform: row.get(2),
            action_kind: row.get(3),
            target_key: row.get(4),
            desired_state: row.get(5),
            status: row.get(6),
            attempts: row.get(7),
            next_attempt_at: row.try_get::<_, Option<String>>(8).ok().flatten(),
            last_error: row.try_get::<_, Option<String>>(9).ok().flatten(),
            updated_at: row.try_get::<_, Option<String>>(10).ok().flatten(),
        });
    }

    Ok(ActionQueueReport {
        pending: pending as usize,
        processing: processing as usize,
        sample,
        source: "vaak-worker-shadow",
        note: "Shadow mode lists only; PHP remains the claim/execute owner.",
    })
}

pub async fn list_pending(cfg: &Config, limit: i64) -> Result<()> {
    let report = report(cfg, limit).await?;
    println!("{}", serde_json::to_string_pretty(&report)?);
    Ok(())
}
