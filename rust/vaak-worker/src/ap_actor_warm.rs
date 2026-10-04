//! Fediverse (ActivityPub) actor-warm queue (loading-plan slice 6).
//!
//! Queue name matches PHP `ap_redis_queue_push('ap_actor_warm', …)` →
//! Redis DB1 key `vaak:queue:ap_actor_warm`.
//! Flat cache (Redis DB0): `vaak:actor:v1:ap:{sha256(actor_id)}` TTL ~2700s.
//!
//! Signed AS2 fetch stays in PHP (`ap-actor-warm.php` → `ap_remote_actor_ensure`).
//! This worker drains the queue, spawns that script, and periodically backfills
//! flat Redis from existing `remote_actors` rows (no HTTP).

use std::path::PathBuf;
use std::process::Stdio;
use std::sync::Arc;
use std::time::{Duration, Instant};

use anyhow::{bail, Context, Result};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use tokio::process::Command;
use tokio::sync::Semaphore;

use crate::config::Config;
use crate::redis_util;

pub const QUEUE_NAME: &str = "ap_actor_warm";
pub const FLAT_KEY_PREFIX: &str = "vaak:actor:v1:ap:";
pub const FLAT_TTL_SECS: u64 = 2700;
const MAX_CONCURRENT_PHP: usize = 3;
const BATCH_SIZE: usize = 8;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct WarmJob {
    #[serde(default)]
    pub actor_id: String,
    /// Alternate field some pushers may use.
    #[serde(default)]
    pub actor: String,
    #[serde(default)]
    pub ts: i64,
    #[serde(default)]
    pub source: String,
}

impl WarmJob {
    fn actor_ref(&self) -> String {
        let a = self.actor_id.trim();
        if !a.is_empty() {
            return a.trim_end_matches('/').to_string();
        }
        self.actor.trim().trim_end_matches('/').to_string()
    }
}

#[derive(Debug, Serialize)]
pub struct WarmOnceReport {
    pub enqueued: usize,
    pub backfilled: usize,
    pub processed: usize,
    pub warmed: usize,
    pub failed: usize,
    pub queue_depth: i64,
    pub sample: Vec<String>,
    pub source: &'static str,
}

#[derive(Debug, Clone)]
struct WarmPaths {
    php_bin: PathBuf,
    script: PathBuf,
}

fn warm_paths() -> WarmPaths {
    let php_bin = PathBuf::from(
        std::env::var("VAAK_PHP_BIN").unwrap_or_else(|_| "/usr/bin/php".to_string()),
    );
    let api_root = PathBuf::from(
        std::env::var("VAAK_API_ROOT").unwrap_or_else(|_| "/srv/mkultra/html/api".to_string()),
    );
    WarmPaths {
        php_bin,
        script: api_root.join("ap-actor-warm.php"),
    }
}

pub fn flat_redis_key(actor_id: &str) -> String {
    let normalized = actor_id.trim().trim_end_matches('/').to_lowercase();
    let mut hasher = Sha256::new();
    hasher.update(normalized.as_bytes());
    format!("{FLAT_KEY_PREFIX}{}", hex::encode(hasher.finalize()))
}

fn normalize_actor_url(raw: &str) -> Option<String> {
    let s = raw.trim().trim_end_matches('/');
    if s.is_empty() {
        return None;
    }
    let lower = s.to_lowercase();
    if !lower.starts_with("https://") {
        return None;
    }
    // Reject obvious non-actor URLs (status objects, etc.) — warm script also validates.
    Some(s.to_string())
}

fn parse_job(item: &str) -> Option<WarmJob> {
    if let Ok(j) = serde_json::from_str::<WarmJob>(item) {
        if normalize_actor_url(&j.actor_ref()).is_some() {
            return Some(WarmJob {
                actor_id: normalize_actor_url(&j.actor_ref()).unwrap_or_default(),
                actor: String::new(),
                ts: if j.ts > 0 {
                    j.ts
                } else {
                    chrono::Utc::now().timestamp()
                },
                source: if j.source.is_empty() {
                    "queue".into()
                } else {
                    j.source
                },
            });
        }
    }
    if let Some(actor_id) = normalize_actor_url(item) {
        return Some(WarmJob {
            actor_id,
            actor: String::new(),
            ts: chrono::Utc::now().timestamp(),
            source: "plain".into(),
        });
    }
    None
}

/// Enqueue one actor onto the shared warm queue (deduped via Redis lock).
pub async fn enqueue(cfg: &Config, actor_id: &str, source: &str) -> Result<bool> {
    let Some(actor_id) = normalize_actor_url(actor_id) else {
        return Ok(false);
    };
    let mut q = redis_util::connect(&cfg.redis_queue_url).await?;
    let lock_name = format!("ap-actor-warm:{}", {
        let mut hasher = Sha256::new();
        hasher.update(actor_id.as_bytes());
        hex::encode(hasher.finalize())
    });
    let holder = format!("vaak-worker:{}", std::process::id());
    if !redis_util::lock(&mut q, &lock_name, 45, &holder).await? {
        return Ok(false);
    }
    let job = WarmJob {
        actor_id: actor_id.clone(),
        actor: String::new(),
        ts: chrono::Utc::now().timestamp(),
        source: source.to_string(),
    };
    let item = serde_json::to_string(&job)?;
    redis_util::queue_push(&mut q, QUEUE_NAME, &item).await?;
    Ok(true)
}

/// Write flat Redis from existing `remote_actors` rows (no HTTP).
/// Thin usernames (placeholders) are also enqueued for PHP signed fetch.
pub async fn backfill_flat_from_pg(cfg: &Config, limit: usize) -> Result<WarmOnceReport> {
    let db = crate::db::connect(&cfg.database_url).await?;
    let limit = limit.clamp(1, 200) as i64;
    let mut report = WarmOnceReport {
        enqueued: 0,
        backfilled: 0,
        processed: 0,
        warmed: 0,
        failed: 0,
        queue_depth: 0,
        sample: Vec::new(),
        source: "vaak-worker-ap-actor-pg",
    };

    let exists: bool = db
        .query_one(
            "SELECT EXISTS (
               SELECT 1 FROM information_schema.tables
               WHERE table_schema = current_schema()
                 AND table_name = 'remote_actors'
             )",
            &[],
        )
        .await
        .map(|r| r.get(0))
        .unwrap_or(false);
    if !exists {
        return Ok(report);
    }

    let rows = db
        .query(
            "SELECT actor_id, username, display_name, host, icon_source_url, image_source_url, updated_at
             FROM remote_actors
             WHERE actor_id LIKE 'https://%'
             ORDER BY updated_at DESC NULLS LAST
             LIMIT $1",
            &[&limit],
        )
        .await
        .context("select remote_actors for flat backfill")?;

    let mut cache = redis_util::connect(&cfg.redis_url).await?;
    for row in rows {
        report.processed += 1;
        let actor_id: String = row.get::<_, String>(0).trim_end_matches('/').to_string();
        if normalize_actor_url(&actor_id).is_none() {
            continue;
        }
        let username: Option<String> = row.get(1);
        let display_name: Option<String> = row.get(2);
        let host: Option<String> = row.get(3);
        let icon: Option<String> = row.get(4);
        let image: Option<String> = row.get(5);
        let updated_at: Option<String> = row.get(6);

        let flat = json!({
            "actor_id": actor_id,
            "username": username,
            "display_name": display_name,
            "host": host,
            "icon_source_url": icon,
            "image_source_url": image,
            "updated_at": updated_at,
        });
        match redis_util::json_set(&mut cache, &flat_redis_key(&actor_id), &flat, FLAT_TTL_SECS)
            .await
        {
            Ok(()) => {
                report.backfilled += 1;
                if report.sample.len() < 12 {
                    report.sample.push(actor_id.clone());
                }
            }
            Err(e) => {
                report.failed += 1;
                tracing::warn!(%actor_id, error = %e, "ap-actor-warm flat backfill failed");
            }
        }

        // Thin / placeholder → enqueue PHP warm for signed refresh.
        let thin = username
            .as_deref()
            .map(|u| {
                let u = u.trim().trim_start_matches('@');
                u.is_empty()
                    || u.eq_ignore_ascii_case("user")
                    || u.to_ascii_lowercase().starts_with("did:")
                    || u.chars().all(|c| c.is_ascii_digit()) && u.len() >= 6
            })
            .unwrap_or(true);
        if thin {
            match enqueue(cfg, &actor_id, "pg-thin").await {
                Ok(true) => report.enqueued += 1,
                Ok(false) => {}
                Err(e) => {
                    report.failed += 1;
                    tracing::warn!(%actor_id, error = %e, "ap-actor-warm thin enqueue failed");
                }
            }
        }
    }

    if let Ok(mut q) = redis_util::connect(&cfg.redis_queue_url).await {
        report.queue_depth = redis_util::queue_llen(&mut q, QUEUE_NAME).await.unwrap_or(0);
    }
    Ok(report)
}

async fn spawn_php_warm(paths: &WarmPaths, actor_id: &str) -> Result<()> {
    if !paths.script.is_file() {
        bail!("ap-actor-warm script missing: {}", paths.script.display());
    }
    let mut cmd = Command::new(&paths.php_bin);
    cmd.arg(&paths.script)
        .arg(actor_id)
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .kill_on_drop(true);
    if std::env::var_os("AP_DB_DSN").is_none() {
        cmd.env("AP_DB_DSN", "pgsql:dbname=novalandia");
    }
    let output = cmd
        .output()
        .await
        .with_context(|| format!("spawn {} {}", paths.php_bin.display(), paths.script.display()))?;
    let stderr = String::from_utf8_lossy(&output.stderr).trim().to_string();
    if !output.status.success() {
        bail!(
            "ap-actor-warm exit={} stderr={stderr}",
            output.status.code().unwrap_or(-1)
        );
    }
    if !stderr.is_empty() {
        tracing::warn!(%actor_id, %stderr, "ap-actor-warm stderr");
    }
    Ok(())
}

/// Process a batch by spawning PHP signed fetchers (bounded concurrency).
pub async fn process_batch(jobs: &[WarmJob]) -> Result<(usize, usize)> {
    if jobs.is_empty() {
        return Ok((0, 0));
    }
    let paths = warm_paths();
    let mut seen = std::collections::HashSet::new();
    let mut actors: Vec<String> = Vec::new();
    for job in jobs {
        let Some(a) = normalize_actor_url(&job.actor_ref()) else {
            continue;
        };
        if seen.insert(a.clone()) {
            actors.push(a);
        }
    }
    if actors.is_empty() {
        return Ok((0, 0));
    }

    let sem = Arc::new(Semaphore::new(MAX_CONCURRENT_PHP));
    let mut handles = Vec::with_capacity(actors.len());
    for actor_id in actors {
        let permit = sem.clone().acquire_owned().await?;
        let paths = paths.clone();
        handles.push(tokio::spawn(async move {
            let _permit = permit;
            let started = Instant::now();
            let res = spawn_php_warm(&paths, &actor_id).await;
            let ms = started.elapsed().as_millis();
            (actor_id, res, ms)
        }));
    }

    let mut warmed = 0usize;
    let mut failed = 0usize;
    for h in handles {
        match h.await {
            Ok((actor_id, Ok(()), ms)) => {
                warmed += 1;
                tracing::info!(%actor_id, ms, "ap-actor-warm ok");
            }
            Ok((actor_id, Err(e), ms)) => {
                failed += 1;
                tracing::warn!(%actor_id, ms, error = %e, "ap-actor-warm failed");
            }
            Err(e) => {
                failed += 1;
                tracing::warn!(error = %e, "ap-actor-warm join failed");
            }
        }
    }
    Ok((warmed, failed))
}

pub async fn run_worker_loop(cfg: &Config, pg_scan_interval_secs: u64) -> Result<()> {
    let mut q = redis_util::connect(&cfg.redis_queue_url).await?;
    let mut last_pg = Instant::now() - Duration::from_secs(pg_scan_interval_secs.max(60));
    loop {
        if last_pg.elapsed() >= Duration::from_secs(pg_scan_interval_secs.max(60)) {
            match backfill_flat_from_pg(cfg, 40).await {
                Ok(r) => {
                    if r.backfilled > 0 || r.enqueued > 0 {
                        tracing::info!(
                            backfilled = r.backfilled,
                            enqueued = r.enqueued,
                            depth = r.queue_depth,
                            "ap-actor-warm pg backfill"
                        );
                    }
                }
                Err(e) => tracing::warn!(error = %e, "ap-actor-warm pg scan failed"),
            }
            last_pg = Instant::now();
        }

        let mut batch: Vec<WarmJob> = Vec::with_capacity(BATCH_SIZE);
        match redis_util::queue_brpop(&mut q, QUEUE_NAME, 5.0).await {
            Ok(Some(item)) => {
                if let Some(job) = parse_job(&item) {
                    batch.push(job);
                } else {
                    tracing::warn!(%item, "bad ap-actor-warm job");
                }
            }
            Ok(None) => {}
            Err(e) => {
                tracing::error!(error = %e, "ap-actor-warm BRPOP failed");
                tokio::time::sleep(Duration::from_secs(2)).await;
                q = redis_util::connect(&cfg.redis_queue_url).await?;
                continue;
            }
        }
        while batch.len() < BATCH_SIZE {
            match redis_util::queue_rpop(&mut q, QUEUE_NAME).await {
                Ok(Some(item)) => {
                    if let Some(job) = parse_job(&item) {
                        batch.push(job);
                    }
                }
                _ => break,
            }
        }
        if batch.is_empty() {
            continue;
        }
        match process_batch(&batch).await {
            Ok((warmed, failed)) => tracing::info!(
                n = batch.len(),
                warmed,
                failed,
                "ap-actor-warm batch"
            ),
            Err(e) => tracing::warn!(error = %e, "ap-actor-warm batch failed"),
        }
    }
}

pub async fn run_backfill_cli(cfg: &Config, limit: usize) -> Result<()> {
    let report = backfill_flat_from_pg(cfg, limit).await?;
    println!("{}", serde_json::to_string_pretty(&report)?);
    Ok(())
}

pub async fn run_once_cli(cfg: &Config, actors: &[String]) -> Result<()> {
    let _ = cfg;
    let jobs: Vec<WarmJob> = actors
        .iter()
        .filter_map(|a| {
            normalize_actor_url(a).map(|actor_id| WarmJob {
                actor_id,
                actor: String::new(),
                ts: chrono::Utc::now().timestamp(),
                source: "cli".into(),
            })
        })
        .collect();
    let (warmed, failed) = process_batch(&jobs).await?;
    println!(
        "{}",
        serde_json::to_string_pretty(&json!({
            "warmed": warmed,
            "failed": failed,
            "n": jobs.len(),
            "source": "vaak-worker-ap-actor-once",
        }))?
    );
    Ok(())
}

/// Smoke: write/read one flat key from a Value (tests key shape).
#[allow(dead_code)]
pub fn flat_from_value(v: &Value) -> Option<Value> {
    let actor_id = v.get("actor_id")?.as_str()?;
    Some(json!({
        "actor_id": actor_id.trim_end_matches('/'),
        "username": v.get("username").cloned().unwrap_or(Value::Null),
        "display_name": v.get("display_name").cloned().unwrap_or(Value::Null),
        "host": v.get("host").cloned().unwrap_or(Value::Null),
        "icon_source_url": v.get("icon_source_url").cloned().unwrap_or(Value::Null),
        "image_source_url": v.get("image_source_url").cloned().unwrap_or(Value::Null),
        "updated_at": v.get("updated_at").cloned().unwrap_or(Value::Null),
    }))
}
