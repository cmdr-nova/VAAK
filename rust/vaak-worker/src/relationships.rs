//! Owner-scoped, read-only Followers/Following projections.
//!
//! PHP still owns follow/unfollow, Bluesky graph sync, cache invalidation, and
//! rendering. This projection only combines the durable ActivityPub graph with
//! the existing Bluesky graph/event tables for parity comparison.

use anyhow::{Context, Result};
use serde::Serialize;
use serde_json::Value;
use std::collections::HashSet;
use tokio_postgres::Client;

#[derive(Debug, Serialize)]
pub struct RelationshipProjection {
    pub relationship: String,
    pub owner_id: i64,
    pub owner_actor_id: String,
    pub rows: Vec<Value>,
    pub total: usize,
    pub fedi_count: usize,
    pub bsky_count: usize,
    pub source: &'static str,
    pub note: &'static str,
}

async fn rows(db: &Client, sql: &str, params: &[&(dyn tokio_postgres::types::ToSql + Sync)]) -> Result<Vec<Value>> {
    let row = db
        .query_one(
            &format!("SELECT COALESCE(json_agg(row_to_json(projected)), '[]'::json) FROM ({sql}) projected"),
            params,
        )
        .await
        .map_err(|e| anyhow::anyhow!("query relationship projection: {e}"))?;
    let value: Value = row.get(0);
    Ok(value.as_array().cloned().unwrap_or_default())
}

fn actor_key(row: &Value) -> String {
    row.get("actor_id")
        .and_then(Value::as_str)
        .unwrap_or_default()
        .trim_end_matches('/')
        .to_ascii_lowercase()
}

pub async fn project(
    cfg: &crate::config::Config,
    owner_id: i64,
    relationship: &str,
    limit: i64,
) -> Result<RelationshipProjection> {
    if owner_id < 1 {
        anyhow::bail!("owner_id must be positive");
    }
    let relationship = relationship.trim().to_ascii_lowercase();
    if !matches!(relationship.as_str(), "followers" | "following") {
        anyhow::bail!("unsupported relationship projection");
    }
    let limit = limit.clamp(1, 500) as usize;
    let db = crate::db::connect(&cfg.database_url).await.context("connect relationship projection")?;
    let owner_row = db
        .query_opt("SELECT actor_id FROM ap_users WHERE id = $1 AND disabled_at IS NULL", &[&owner_id])
        .await
        .context("load relationship owner")?;
    let owner_actor_id: String = owner_row
        .and_then(|r| r.try_get(0).ok())
        .unwrap_or_default();
    let owner_actor_id = owner_actor_id.trim_end_matches('/').to_string();
    if owner_actor_id.is_empty() {
        anyhow::bail!("relationship owner not found");
    }

    let (mut fedi, mut bsky) = if relationship == "following" {
        let fedi = rows(&db, "SELECT actor_id, followed_at, host FROM following WHERE owner_actor_id = $1 OR owner_actor_id = $2 ORDER BY followed_at DESC", &[&owner_actor_id, &format!("{owner_actor_id}/")]).await?;
        let bsky = rows(&db, "SELECT 'https://bsky.app/profile/' || target_did AS actor_id, updated_at AS followed_at, 'bsky.app' AS host, target_did AS bsky_did, target_did AS username FROM bsky_graph_sync WHERE owner_user_id = $1 AND kind = 'follow' ORDER BY updated_at DESC", &[&owner_id]).await?;
        (fedi, bsky)
    } else {
        let fedi = rows(&db, "SELECT actor_id, followed_at, host, username FROM followers WHERE owner_actor_id = $1 OR owner_actor_id = $2 ORDER BY followed_at DESC", &[&owner_actor_id, &format!("{owner_actor_id}/")]).await?;
        let bsky = rows(&db, "SELECT actor_id, created_at AS followed_at, 'bsky.app' AS host FROM events WHERE type = 'Follow' AND action_taken = 'bsky_follow' AND (target_actor = $1 OR target_actor = $2) ORDER BY created_at DESC LIMIT 500", &[&owner_actor_id, &format!("{owner_actor_id}/")]).await?;
        (fedi, bsky)
    };

    // PHP's merge helper de-duplicates by actor identity while retaining the
    // first (newest) row. Normalize only for identity; preserve display fields.
    let mut seen = HashSet::new();
    let mut merged = Vec::with_capacity(fedi.len() + bsky.len());
    let mut fedi_count = 0;
    let mut bsky_count = 0;
    for row in fedi.drain(..).chain(bsky.drain(..)) {
        let key = actor_key(&row);
        if key.is_empty() || !seen.insert(key.clone()) {
            continue;
        }
        let is_bsky = row.get("host").and_then(Value::as_str) == Some("bsky.app") || key.contains("bsky.app");
        if is_bsky {
            bsky_count += 1;
        } else {
            fedi_count += 1;
        }
        merged.push(row);
    }
    let total = merged.len();
    merged.truncate(limit);
    Ok(RelationshipProjection {
        relationship,
        owner_id,
        owner_actor_id,
        rows: merged,
        total,
        fedi_count,
        bsky_count,
        source: "vaak-worker-shadow",
        note: "Read-only relationship projection; PHP remains the renderer and follow/sync mutation owner.",
    })
}
