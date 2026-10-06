//! Read-only Admin health projection. PHP remains the renderer and write owner.

use anyhow::{Context, Result};
use serde::Serialize;
use tokio_postgres::Client;

#[derive(Debug, Serialize)]
pub struct QueueHealth {
    pub name: &'static str,
    pub queued: i64,
    pub active: i64,
    pub failed: i64,
    pub retries: i64,
    pub available: bool,
}

#[derive(Debug, Serialize)]
pub struct AdminHealthReport {
    pub database: bool,
    pub queues: Vec<QueueHealth>,
    pub open_reports: i64,
    pub active_downranked: i64,
    pub source: &'static str,
    pub note: &'static str,
}

async fn queue_counts(
    db: &Client,
    name: &'static str,
    table: &str,
    state: &str,
    queued: &str,
    active: &str,
    failed: &str,
) -> QueueHealth {
    // Table/column names are fixed constants at every call site; values are
    // never request-controlled. Missing optional tables remain unavailable.
    let sql = format!(
        "SELECT COUNT(*) FILTER (WHERE {state} = '{queued}')::bigint,
                COUNT(*) FILTER (WHERE {state} = '{active}')::bigint,
                COUNT(*) FILTER (WHERE {state} = '{failed}')::bigint,
                COALESCE(SUM(attempts), 0)::bigint FROM {table}"
    );
    match db.query_one(&sql, &[]).await {
        Ok(row) => QueueHealth {
            name,
            queued: row.get(0),
            active: row.get(1),
            failed: row.get(2),
            retries: row.get(3),
            available: true,
        },
        Err(_) => QueueHealth {
            name,
            queued: 0,
            active: 0,
            failed: 0,
            retries: 0,
            available: false,
        },
    }
}

pub async fn report(cfg: &crate::config::Config) -> Result<AdminHealthReport> {
    let db = crate::db::connect(&cfg.database_url).await.context("connect admin health")?;
    db.query_one("SELECT 1", &[]).await.context("admin health database")?;
    let queues = vec![
        queue_counts(&db, "User actions", "ap_action_queue", "status", "pending", "processing", "failed").await,
        queue_counts(&db, "Federation delivery", "ap_publish_delivery_queue", "status", "pending", "processing", "failed").await,
        queue_counts(&db, "Federation fan-out", "ap_fanout_delivery_queue", "status", "pending", "processing", "failed").await,
        queue_counts(&db, "Remote media warming", "ap_media_warm_queue", "status", "pending", "processing", "failed").await,
        queue_counts(&db, "Scheduled posts", "ap_post_queue", "state", "pending", "publishing", "failed").await,
        queue_counts(&db, "Actor/profile refresh", "bsky_actor_refresh_queue", "status", "pending", "processing", "failed").await,
    ];
    let open_reports = db
        .query_one("SELECT COUNT(*)::bigint FROM ap_reports WHERE state IN ('open','pending')", &[])
        .await
        .map(|row| row.get(0))
        .unwrap_or(0);
    let active_downranked = db
        .query_one("SELECT COUNT(*)::bigint FROM ap_home_suppression WHERE suppressed_until > NOW()", &[])
        .await
        .map(|row| row.get(0))
        .unwrap_or(0);
    Ok(AdminHealthReport {
        database: true,
        queues,
        open_reports,
        active_downranked,
        source: "vaak-worker-shadow",
        note: "Read-only Admin projection; PHP remains the renderer and moderation/write owner.",
    })
}
