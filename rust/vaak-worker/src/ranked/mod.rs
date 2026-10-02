//! Shadow Home newer-poll candidate fetch (parity helper for PHP admin_tl_fetch_newer).

use anyhow::{Context, Result};
use chrono::{Duration, Utc};
use serde::Serialize;
use tokio_postgres::Client;

use crate::config::Config;

#[derive(Debug, Serialize)]
pub struct NewerReport {
    pub owner_user_id: i64,
    pub since_ts: i64,
    pub since_at: String,
    pub limit: i64,
    pub fetch_ms: u128,
    pub counts: NewerCounts,
    pub sample: Vec<NewerItem>,
    pub source: &'static str,
}

#[derive(Debug, Default, Serialize)]
pub struct NewerCounts {
    pub events: usize,
    pub local: usize,
    pub outbox: usize,
    pub bsky: usize,
    pub boosts: usize,
    pub total: usize,
}

#[derive(Debug, Serialize)]
pub struct NewerItem {
    pub kind: String,
    pub sort: i64,
    pub id: String,
}

pub async fn fetch_newer_home(
    db: &Client,
    owner_user_id: i64,
    since_ts: i64,
    limit: i64,
) -> Result<NewerReport> {
    let limit = limit.clamp(1, 40);
    let since_ts = since_ts.max(0);
    let since_at = chrono::DateTime::from_timestamp(since_ts.saturating_sub(1), 0)
        .unwrap_or_else(|| Utc::now())
        .to_rfc3339();
    let t0 = std::time::Instant::now();

    let owner_actor = "https://mkultra.monster/users/cmdr_nova".to_string();
    let _ = owner_user_id;

    let mut items: Vec<NewerItem> = Vec::new();

    // Followed actor Creates/Announces since cursor (bounded).
    let follow_rows = db
        .query(
            "SELECT e.id, e.created_at, e.type
             FROM events e
             JOIN following f
               ON rtrim(f.actor_id, '/') = rtrim(e.actor_id, '/')
             WHERE f.owner_actor_id = $1
               AND e.type IN ('Create', 'Announce', 'Quote', 'QuotePost')
               AND e.action_taken IN ('log', 'local_observe')
               AND e.created_at > $2
             ORDER BY e.created_at DESC, e.id DESC
             LIMIT $3",
            &[&owner_actor, &since_at, &limit],
        )
        .await
        .context("newer follow events")?;
    for row in &follow_rows {
        let id: i64 = row.get(0);
        let created: String = row.try_get::<_, String>(1).unwrap_or_default();
        let sort = parse_ts(&created).unwrap_or(0);
        if sort <= since_ts {
            continue;
        }
        items.push(NewerItem {
            kind: "event".into(),
            sort,
            id: id.to_string(),
        });
    }

    // Local peers.
    let local_rows = db
        .query(
            "SELECT id, created_at FROM events
             WHERE type IN ('Create', 'Quote', 'QuotePost')
               AND action_taken IN ('log', 'local_observe')
               AND actor_id LIKE 'https://mkultra.monster/users/%'
               AND created_at > $1
             ORDER BY created_at DESC, id DESC
             LIMIT $2",
            &[&since_at, &limit],
        )
        .await
        .context("newer local events")?;
    for row in &local_rows {
        let id: i64 = row.get(0);
        let created: String = row.try_get::<_, String>(1).unwrap_or_default();
        let sort = parse_ts(&created).unwrap_or(0);
        if sort <= since_ts {
            continue;
        }
        items.push(NewerItem {
            kind: "local".into(),
            sort,
            id: id.to_string(),
        });
    }

    // Own outbox notes.
    let prefix = format!("{owner_actor}/notes/%");
    let outbox_rows = db
        .query(
            "SELECT id, published FROM outbox_notes
             WHERE id LIKE $1 AND published > $2
             ORDER BY published DESC
             LIMIT $3",
            &[&prefix, &since_at, &limit],
        )
        .await
        .context("newer outbox")?;
    for row in &outbox_rows {
        let id: String = row.get(0);
        let published: String = row.try_get::<_, String>(1).unwrap_or_default();
        let sort = parse_ts(&published).unwrap_or(0);
        if sort <= since_ts {
            continue;
        }
        items.push(NewerItem {
            kind: "outbox".into(),
            sort,
            id,
        });
    }

    // Bluesky observations for this owner.
    let bsky_rows = db
        .query(
            "SELECT p.bsky_uri, p.indexed_at
             FROM bsky_posts p
             JOIN bsky_post_observations o ON o.bsky_uri = p.bsky_uri
             WHERE o.owner_user_id = $1
               AND p.indexed_at > $2
             ORDER BY p.indexed_at DESC
             LIMIT $3",
            &[&owner_user_id, &since_at, &limit],
        )
        .await
        .context("newer bsky")?;
    for row in &bsky_rows {
        let uri: String = row.get(0);
        let indexed: String = row.try_get::<_, String>(1).unwrap_or_default();
        let sort = parse_ts(&indexed).unwrap_or(0);
        if sort <= since_ts {
            continue;
        }
        items.push(NewerItem {
            kind: "bsky".into(),
            sort,
            id: uri,
        });
    }

    items.sort_by(|a, b| b.sort.cmp(&a.sort));
    items.dedup_by(|a, b| a.kind == b.kind && a.id == b.id);
    let sample: Vec<_> = items.into_iter().take(limit as usize).collect();
    let mut sample_counts = NewerCounts {
        total: sample.len(),
        ..NewerCounts::default()
    };
    for it in &sample {
        match it.kind.as_str() {
            "event" => sample_counts.events += 1,
            "local" => sample_counts.local += 1,
            "outbox" => sample_counts.outbox += 1,
            "bsky" => sample_counts.bsky += 1,
            "boost" => sample_counts.boosts += 1,
            _ => {}
        }
    }

    Ok(NewerReport {
        owner_user_id,
        since_ts,
        since_at,
        limit,
        fetch_ms: t0.elapsed().as_millis(),
        counts: sample_counts,
        sample,
        source: "vaak-worker-shadow",
    })
}

fn parse_ts(s: &str) -> Option<i64> {
    if let Ok(dt) = chrono::DateTime::parse_from_rfc3339(s) {
        return Some(dt.timestamp());
    }
    // Postgres often returns "2026-10-02 23:30:04.267634+02"
    if let Ok(dt) = chrono::DateTime::parse_from_str(s, "%Y-%m-%d %H:%M:%S%.f%z") {
        return Some(dt.timestamp());
    }
    if let Ok(dt) = chrono::DateTime::parse_from_str(s, "%Y-%m-%d %H:%M:%S%z") {
        return Some(dt.timestamp());
    }
    None
}

pub async fn fetch_report(
    cfg: &Config,
    owner_user_id: i64,
    since_secs: i64,
    limit: i64,
) -> Result<NewerReport> {
    let db = crate::db::connect(&cfg.database_url).await?;
    let since_ts = (Utc::now() - Duration::seconds(since_secs.max(60))).timestamp();
    fetch_newer_home(&db, owner_user_id, since_ts, limit).await
}

pub async fn run(cfg: &Config, owner_user_id: i64, since_secs: i64, limit: i64) -> Result<()> {
    let report = fetch_report(cfg, owner_user_id, since_secs, limit).await?;
    println!("{}", serde_json::to_string_pretty(&report)?);
    Ok(())
}
