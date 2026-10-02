//! Shadow Home timeline from PHP ranked Redis cache (IDs + light enrich).

use anyhow::{Context, Result};
use serde::Serialize;
use serde_json::Value;
use sha2::{Digest, Sha256};

use crate::config::Config;
use crate::redis_util;

#[derive(Debug, Serialize)]
pub struct HomeShadowReport {
    pub owner_user_id: i64,
    pub limit: usize,
    pub cache_key_logical: Option<String>,
    pub cache_key_redis: Option<String>,
    pub cache_ts: Option<i64>,
    pub ranked_total: usize,
    pub items: Vec<HomeShadowItem>,
    pub source: &'static str,
    pub note: &'static str,
}

#[derive(Debug, Serialize)]
pub struct HomeShadowItem {
    pub kind: String,
    pub id: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub slot: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub preview: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub account_hint: Option<String>,
}

pub async fn home_shadow(cfg: &Config, owner_user_id: i64, limit: usize) -> Result<HomeShadowReport> {
    let limit = limit.clamp(1, 40);
    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let index_key = format!("vaak:timeline:owner-index:v1:{owner_user_id}");
    let index = redis_util::json_get(&mut redis, &index_key).await?;

    let logical = pick_home_logical_key(index.as_ref());
    let (redis_key, payload) = if let Some(ref logical) = logical {
        let rk = ranked_redis_key(logical);
        let payload = redis_util::json_get(&mut redis, &rk).await?;
        (Some(rk), payload)
    } else {
        // Fallback: first ranked key on this Redis (dev/soak).
        let keys: Vec<String> = redis::cmd("KEYS")
            .arg("vaak:timeline:ranked:v2:*")
            .query_async(&mut redis)
            .await
            .unwrap_or_default();
        if let Some(rk) = keys.into_iter().next() {
            let payload = redis_util::json_get(&mut redis, &rk).await?;
            (Some(rk), payload)
        } else {
            (None, None)
        }
    };

    let mut report = HomeShadowReport {
        owner_user_id,
        limit,
        cache_key_logical: logical,
        cache_key_redis: redis_key,
        cache_ts: None,
        ranked_total: 0,
        items: Vec::new(),
        source: "vaak-worker-shadow",
        note: "Reads PHP ranked ID cache only. No HTML hydrate; PHP remains Home owner.",
    };

    let db = crate::db::connect(&cfg.database_url).await.ok();

    let slice: Vec<(String, String, Option<String>)> = if let Some(payload) = payload {
        report.cache_ts = payload.get("ts").and_then(|v| v.as_i64());
        let ranked = payload
            .get("ranked")
            .and_then(|v| v.as_array())
            .cloned()
            .unwrap_or_default();
        report.ranked_total = ranked.len();
        ranked
            .into_iter()
            .take(limit)
            .map(|it| {
                let kind = it
                    .get("k")
                    .and_then(|v| v.as_str())
                    .unwrap_or("unknown")
                    .to_string();
                let id = it
                    .get("id")
                    .map(|v| match v {
                        Value::String(s) => s.clone(),
                        Value::Number(n) => n.to_string(),
                        _ => v.to_string(),
                    })
                    .unwrap_or_default();
                let slot = it
                    .get("s")
                    .and_then(|v| v.as_str())
                    .map(|s| s.to_string());
                (kind, id, slot)
            })
            .collect()
    } else if let Some(ref db) = db {
        // Cold ranked cache: fall back to recent newer-poll style candidates.
        report.note = "Ranked Redis cache cold; DB newer-candidate fallback. PHP remains Home owner.";
        cold_fallback_items(db, owner_user_id, limit).await.unwrap_or_default()
    } else {
        Vec::new()
    };

    if report.ranked_total == 0 {
        report.ranked_total = slice.len();
    }

    for (kind, id, slot) in slice {
        let (preview, account_hint) = if let Some(ref db) = db {
            enrich_item(db, &kind, &id).await.unwrap_or((None, None))
        } else {
            (None, None)
        };

        report.items.push(HomeShadowItem {
            kind,
            id,
            slot,
            preview,
            account_hint,
        });
    }

    Ok(report)
}

async fn cold_fallback_items(
    db: &tokio_postgres::Client,
    owner_user_id: i64,
    limit: usize,
) -> Result<Vec<(String, String, Option<String>)>> {
    let newer = crate::ranked::fetch_newer_home(
        db,
        owner_user_id,
        chrono::Utc::now().timestamp() - 3600,
        limit as i64,
    )
    .await?;
    Ok(newer
        .sample
        .into_iter()
        .map(|it| (it.kind, it.id, Some("cold_fallback".into())))
        .collect())
}

pub async fn run(cfg: &Config, owner_user_id: i64, limit: usize) -> Result<()> {
    let report = home_shadow(cfg, owner_user_id, limit).await?;
    println!("{}", serde_json::to_string_pretty(&report)?);
    Ok(())
}

fn pick_home_logical_key(index: Option<&Value>) -> Option<String> {
    let arr = index?.as_array()?;
    // Prefer a home key (v12_home_…); else first string.
    let mut first = None;
    for v in arr {
        let Some(s) = v.as_str() else { continue };
        if first.is_none() {
            first = Some(s.to_string());
        }
        if s.contains("home") {
            return Some(s.to_string());
        }
    }
    first
}

fn ranked_redis_key(logical: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(logical.as_bytes());
    format!("vaak:timeline:ranked:v2:{}", hex::encode(hasher.finalize()))
}

async fn enrich_item(
    db: &tokio_postgres::Client,
    kind: &str,
    id: &str,
) -> Result<(Option<String>, Option<String>)> {
    match kind {
        "bsky" => {
            let row = db
                .query_opt(
                    "SELECT left(text, 160), COALESCE(author_handle, author_did)
                     FROM bsky_posts WHERE bsky_uri = $1 LIMIT 1",
                    &[&id],
                )
                .await
                .context("enrich bsky")?;
            if let Some(row) = row {
                let text: Option<String> = row.try_get(0).ok().flatten();
                let handle: Option<String> = row.try_get(1).ok().flatten();
                return Ok((text, handle));
            }
        }
        "event" | "local" => {
            if let Ok(eid) = id.parse::<i64>() {
                let row = db
                    .query_opt(
                        "SELECT type, left(COALESCE(object_id, actor_id), 120), actor_id
                         FROM events WHERE id = $1 LIMIT 1",
                        &[&eid],
                    )
                    .await
                    .context("enrich event")?;
                if let Some(row) = row {
                    let typ: Option<String> = row.try_get(0).ok().flatten();
                    let obj: Option<String> = row.try_get(1).ok().flatten();
                    let actor: Option<String> = row.try_get(2).ok().flatten();
                    let preview = match (typ, obj) {
                        (Some(t), Some(o)) => Some(format!("{t} {o}")),
                        (Some(t), None) => Some(t),
                        _ => None,
                    };
                    return Ok((preview, actor));
                }
            }
        }
        "outbox" => {
            let row = db
                .query_opt(
                    "SELECT left(COALESCE(content, ''), 160) FROM outbox_notes WHERE id = $1 LIMIT 1",
                    &[&id],
                )
                .await
                .context("enrich outbox")?;
            if let Some(row) = row {
                let text: Option<String> = row.try_get(0).ok().flatten();
                return Ok((text, Some("cmdr_nova@mkultra.monster".into())));
            }
        }
        "rss" => {
            if let Ok(rid) = id.parse::<i64>() {
                let row = db
                    .query_opt(
                        "SELECT left(COALESCE(title, summary_text, ''), 160)
                         FROM rss_items WHERE id = $1 LIMIT 1",
                        &[&rid],
                    )
                    .await
                    .context("enrich rss")?;
                if let Some(row) = row {
                    let text: Option<String> = row.try_get(0).ok().flatten();
                    return Ok((text, Some("rss".into())));
                }
            }
        }
        _ => {}
    }
    Ok((None, None))
}
