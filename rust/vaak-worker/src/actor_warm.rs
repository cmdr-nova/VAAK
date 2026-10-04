//! Live Bluesky actor-warm queue (Rust primary; PHP LPUSHes thin authors).
//!
//! Queue name matches PHP `ap_redis_queue_push('bsky_actor_warm', …)` →
//! Redis DB1 key `vaak:queue:bsky_actor_warm`.
//! Flat cache (Redis DB0): `vaak:actor:v1:bsky:{did}` TTL ~2700s.

use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};

use crate::config::Config;
use crate::redis_util;

pub const QUEUE_NAME: &str = "bsky_actor_warm";
pub const FLAT_KEY_PREFIX: &str = "vaak:actor:v1:bsky:";
/// Midpoint of the 1800–3600s guidance for flat actor cache.
pub const FLAT_TTL_SECS: u64 = 2700;
const GET_PROFILES_BATCH: usize = 25;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct WarmJob {
    /// Bluesky DID (preferred) or handle / profile URL.
    #[serde(default)]
    pub did: String,
    /// Alternate field some pushers may use.
    #[serde(default)]
    pub actor: String,
    #[serde(default)]
    pub owner: i64,
    #[serde(default)]
    pub ts: i64,
    #[serde(default)]
    pub source: String,
}

impl WarmJob {
    fn actor_ref(&self) -> String {
        let a = self.did.trim();
        if !a.is_empty() {
            return a.to_string();
        }
        self.actor.trim().to_string()
    }
}

#[derive(Debug, Serialize)]
pub struct WarmOnceReport {
    pub enqueued: usize,
    pub processed: usize,
    pub warmed: usize,
    pub failed: usize,
    pub queue_depth: i64,
    pub sample: Vec<String>,
    pub source: &'static str,
}

pub fn flat_redis_key(did: &str) -> String {
    format!("{FLAT_KEY_PREFIX}{}", did.trim())
}

/// Normalize job text → DID or actor identifier for AppView.
fn normalize_actor_ref(raw: &str) -> Option<String> {
    let s = raw.trim();
    if s.is_empty() {
        return None;
    }
    if s.starts_with("did:") {
        return Some(s.to_string());
    }
    if let Some(rest) = s
        .strip_prefix("https://bsky.app/profile/")
        .or_else(|| s.strip_prefix("http://bsky.app/profile/"))
    {
        let ident = rest.split(['?', '#', '/']).next().unwrap_or("").trim();
        if ident.is_empty() {
            return None;
        }
        return Some(urlencoding_decode(ident));
    }
    // Bare handle
    if s.contains('.') && !s.contains(' ') && !s.contains('/') {
        return Some(s.trim_start_matches('@').to_string());
    }
    None
}

fn urlencoding_decode(s: &str) -> String {
    // DIDs in URLs are rarely percent-encoded beyond ':' → %3A; keep simple.
    s.replace("%3A", ":")
        .replace("%3a", ":")
        .replace("%40", "@")
}

/// Enqueue one actor onto the shared warm queue (deduped via Redis lock).
pub async fn enqueue(cfg: &Config, actor: &str, owner: i64, source: &str) -> Result<bool> {
    let Some(actor) = normalize_actor_ref(actor) else {
        return Ok(false);
    };
    let mut q = redis_util::connect(&cfg.redis_queue_url).await?;
    let lock_name = format!("bsky-actor-warm:{}", sha256_hex(&actor));
    let holder = format!("vaak-worker:{}", std::process::id());
    if !redis_util::lock(&mut q, &lock_name, 45, &holder).await? {
        return Ok(false);
    }
    let job = WarmJob {
        did: actor.clone(),
        actor: String::new(),
        owner: owner.max(0),
        ts: chrono::Utc::now().timestamp(),
        source: source.to_string(),
    };
    let item = serde_json::to_string(&job)?;
    redis_util::queue_push(&mut q, QUEUE_NAME, &item).await?;
    Ok(true)
}

/// Optionally pull pending real actor refs from Postgres refresh queue → Redis.
pub async fn enqueue_from_pg(cfg: &Config, limit: usize) -> Result<WarmOnceReport> {
    let db = crate::db::connect(&cfg.database_url).await?;
    let limit = limit.clamp(1, 40) as i64;
    // Table may be missing on fresh/dev DBs — soft-fail.
    let exists: bool = db
        .query_one(
            "SELECT EXISTS (
               SELECT 1 FROM information_schema.tables
               WHERE table_schema = current_schema()
                 AND table_name = 'bsky_actor_refresh_queue'
             )",
            &[],
        )
        .await
        .map(|r| r.get(0))
        .unwrap_or(false);
    let mut report = WarmOnceReport {
        enqueued: 0,
        processed: 0,
        warmed: 0,
        failed: 0,
        queue_depth: 0,
        sample: Vec::new(),
        source: "vaak-worker-actor-pg",
    };
    if !exists {
        return Ok(report);
    }
    let now = chrono::Utc::now().to_rfc3339();
    let rows = db
        .query(
            "SELECT owner_user_id, actor_ref FROM bsky_actor_refresh_queue
             WHERE status = 'pending'
               AND next_attempt_at <= $1
               AND actor_ref NOT LIKE '__vaak_%'
             ORDER BY queued_at
             LIMIT $2",
            &[&now, &limit],
        )
        .await
        .context("select bsky_actor_refresh_queue")?;

    for row in rows {
        let owner: i64 = row.get(0);
        let actor_ref: String = row.get(1);
        match enqueue(cfg, &actor_ref, owner, "pg-refresh-queue").await {
            Ok(true) => {
                report.enqueued += 1;
                if report.sample.len() < 12 {
                    report.sample.push(actor_ref);
                }
            }
            Ok(false) => {}
            Err(e) => {
                report.failed += 1;
                tracing::warn!(error = %e, %actor_ref, "actor-warm pg enqueue failed");
            }
        }
    }
    if let Ok(mut q) = redis_util::connect(&cfg.redis_queue_url).await {
        report.queue_depth = redis_util::queue_llen(&mut q, QUEUE_NAME).await.unwrap_or(0);
    }
    Ok(report)
}

fn parse_job(item: &str, default_owner: i64) -> Option<WarmJob> {
    if let Ok(j) = serde_json::from_str::<WarmJob>(item) {
        if !j.actor_ref().is_empty() {
            return Some(j);
        }
    }
    // Plain DID / handle / profile URL
    if let Some(actor) = normalize_actor_ref(item) {
        return Some(WarmJob {
            did: actor,
            actor: String::new(),
            owner: default_owner,
            ts: chrono::Utc::now().timestamp(),
            source: "plain".into(),
        });
    }
    None
}

/// Fetch profiles (batched getProfiles, single getProfile fallback) and persist.
pub async fn process_batch(cfg: &Config, jobs: &[WarmJob]) -> Result<(usize, usize)> {
    if jobs.is_empty() {
        return Ok((0, 0));
    }
    let mut actors: Vec<String> = Vec::new();
    let mut owner_by_actor: std::collections::HashMap<String, i64> =
        std::collections::HashMap::new();
    for job in jobs {
        let Some(a) = normalize_actor_ref(&job.actor_ref()) else {
            continue;
        };
        if !owner_by_actor.contains_key(&a) {
            owner_by_actor.insert(a.clone(), job.owner.max(0));
            actors.push(a);
        }
    }
    if actors.is_empty() {
        return Ok((0, 0));
    }

    let client = reqwest::Client::builder()
        .timeout(std::time::Duration::from_secs(12))
        .user_agent("vaak-worker-live/0.1 (actor-warm)")
        .build()?;
    let base = cfg.bsky_public_api.trim_end_matches('/');

    let mut warmed = 0usize;
    let mut failed = 0usize;

    for chunk in actors.chunks(GET_PROFILES_BATCH) {
        match fetch_profiles(&client, base, chunk).await {
            Ok(profiles) => {
                let mut seen_dids = std::collections::HashSet::new();
                let mut seen_handles = std::collections::HashSet::new();
                for profile in &profiles {
                    let did = profile
                        .get("did")
                        .and_then(|d| d.as_str())
                        .unwrap_or("")
                        .to_string();
                    if did.is_empty() {
                        failed += 1;
                        continue;
                    }
                    seen_dids.insert(did.clone());
                    if let Some(h) = profile.get("handle").and_then(|x| x.as_str()) {
                        if !h.is_empty() {
                            seen_handles.insert(h.to_string());
                        }
                    }
                    let owner = owner_by_actor
                        .get(&did)
                        .copied()
                        .or_else(|| {
                            profile
                                .get("handle")
                                .and_then(|h| h.as_str())
                                .and_then(|h| owner_by_actor.get(h).copied())
                        })
                        .unwrap_or(cfg.default_owner_id);
                    match upsert_and_cache(cfg, owner, profile).await {
                        Ok(()) => {
                            warmed += 1;
                            tracing::info!(%did, "actor-warm upsert");
                        }
                        Err(e) => {
                            failed += 1;
                            tracing::warn!(%did, error = %e, "actor-warm upsert failed");
                        }
                    }
                }
                for a in chunk {
                    let already = if a.starts_with("did:") {
                        seen_dids.contains(a)
                    } else {
                        seen_handles.contains(a)
                    };
                    if already {
                        continue;
                    }
                    match fetch_one_profile(&client, base, a).await {
                        Ok(Some(profile)) => {
                            let owner = owner_by_actor
                                .get(a)
                                .copied()
                                .unwrap_or(cfg.default_owner_id);
                            match upsert_and_cache(cfg, owner, &profile).await {
                                Ok(()) => warmed += 1,
                                Err(e) => {
                                    failed += 1;
                                    tracing::warn!(actor = %a, error = %e, "actor-warm single upsert failed");
                                }
                            }
                        }
                        Ok(None) => {
                            failed += 1;
                            tracing::warn!(actor = %a, "actor-warm empty profile");
                        }
                        Err(e) => {
                            failed += 1;
                            tracing::warn!(actor = %a, error = %e, "actor-warm getProfile failed");
                        }
                    }
                }
            }
            Err(e) => {
                tracing::warn!(error = %e, "getProfiles failed; falling back to getProfile");
                for a in chunk {
                    match fetch_one_profile(&client, base, a).await {
                        Ok(Some(profile)) => {
                            let owner = owner_by_actor
                                .get(a)
                                .copied()
                                .unwrap_or(cfg.default_owner_id);
                            match upsert_and_cache(cfg, owner, &profile).await {
                                Ok(()) => warmed += 1,
                                Err(err) => {
                                    failed += 1;
                                    tracing::warn!(actor = %a, error = %err, "actor-warm upsert failed");
                                }
                            }
                        }
                        Ok(None) => failed += 1,
                        Err(err) => {
                            failed += 1;
                            tracing::warn!(actor = %a, error = %err, "actor-warm getProfile failed");
                        }
                    }
                }
            }
        }
    }
    Ok((warmed, failed))
}

async fn fetch_profiles(
    client: &reqwest::Client,
    base: &str,
    actors: &[String],
) -> Result<Vec<Value>> {
    let url = format!("{base}/xrpc/app.bsky.actor.getProfiles");
    let mut req = client.get(&url);
    for a in actors {
        req = req.query(&[("actors", a.as_str())]);
    }
    let resp = req.send().await.context("getProfiles")?;
    if !resp.status().is_success() {
        anyhow::bail!("HTTP {}", resp.status());
    }
    let v: Value = resp.json().await.context("decode getProfiles")?;
    let profiles = v
        .get("profiles")
        .and_then(|p| p.as_array())
        .cloned()
        .unwrap_or_default();
    Ok(profiles)
}

async fn fetch_one_profile(
    client: &reqwest::Client,
    base: &str,
    actor: &str,
) -> Result<Option<Value>> {
    let url = format!("{base}/xrpc/app.bsky.actor.getProfile");
    let resp = client
        .get(&url)
        .query(&[("actor", actor)])
        .send()
        .await
        .context("getProfile")?;
    if resp.status().as_u16() == 400 || resp.status().as_u16() == 404 {
        return Ok(None);
    }
    if !resp.status().is_success() {
        anyhow::bail!("HTTP {}", resp.status());
    }
    let v: Value = resp.json().await.context("decode getProfile")?;
    if v.get("did").and_then(|d| d.as_str()).unwrap_or("").is_empty() {
        return Ok(None);
    }
    Ok(Some(v))
}

async fn upsert_and_cache(cfg: &Config, owner: i64, profile: &Value) -> Result<()> {
    let did = profile
        .get("did")
        .and_then(|d| d.as_str())
        .unwrap_or("")
        .trim()
        .to_string();
    if did.is_empty() {
        anyhow::bail!("missing did");
    }
    let handle = profile
        .get("handle")
        .and_then(|h| h.as_str())
        .unwrap_or("")
        .to_string();
    let display = profile
        .get("displayName")
        .and_then(|h| h.as_str())
        .unwrap_or("")
        .to_string();
    let avatar = profile
        .get("avatar")
        .and_then(|h| h.as_str())
        .unwrap_or("")
        .to_string();
    let now = chrono::Utc::now().to_rfc3339();

    // Strip viewer before durable public profile cache (owner-scoped elsewhere).
    let mut profile_store = profile.clone();
    if let Some(obj) = profile_store.as_object_mut() {
        obj.remove("viewer");
    }
    let profile_json = serde_json::to_string(&profile_store)?;

    let mut refs: Vec<String> = vec![did.clone(), format!("https://bsky.app/profile/{did}")];
    if !handle.is_empty() && !handle.starts_with("did:") {
        refs.push(format!("https://bsky.app/profile/{handle}"));
        refs.push(handle.clone());
    }

    let db = crate::db::connect(&cfg.database_url).await?;
    // Soft-skip if table missing.
    let exists: bool = db
        .query_one(
            "SELECT EXISTS (
               SELECT 1 FROM information_schema.tables
               WHERE table_schema = current_schema()
                 AND table_name = 'bsky_actor_profiles'
             )",
            &[],
        )
        .await
        .map(|r| r.get(0))
        .unwrap_or(false);
    if exists {
        for actor_ref in &refs {
            if actor_ref.is_empty() {
                continue;
            }
            db.execute(
                "INSERT INTO bsky_actor_profiles (actor_ref, did, profile_json, updated_at)
                 VALUES ($1, $2, $3, $4)
                 ON CONFLICT (actor_ref) DO UPDATE SET
                   did = excluded.did,
                   profile_json = excluded.profile_json,
                   updated_at = excluded.updated_at",
                &[actor_ref, &did, &profile_json, &now],
            )
            .await
            .with_context(|| format!("upsert bsky_actor_profiles {actor_ref}"))?;
        }

        // Mark matching PG refresh rows succeeded (best-effort).
        if owner > 0 {
            let did_url = format!("https://bsky.app/profile/{did}");
            let _ = db
                .execute(
                    "UPDATE bsky_actor_refresh_queue
                     SET status = 'succeeded', attempts = 0, locked_at = NULL, last_error = NULL
                     WHERE owner_user_id = $1
                       AND status IN ('pending', 'processing')
                       AND (actor_ref = $2 OR actor_ref = $3)",
                    &[&owner, &did, &did_url],
                )
                .await;
        }
    }

    let flat = json!({
        "did": did,
        "handle": handle,
        "displayName": display,
        "avatar": avatar,
        "updated_at": now,
    });
    let mut cache = redis_util::connect(&cfg.redis_url).await?;
    redis_util::json_set(&mut cache, &flat_redis_key(&did), &flat, FLAT_TTL_SECS).await?;
    Ok(())
}

pub async fn run_worker_loop(cfg: &Config, pg_scan_interval_secs: u64) -> Result<()> {
    let mut q = redis_util::connect(&cfg.redis_queue_url).await?;
    let mut last_pg = std::time::Instant::now()
        - std::time::Duration::from_secs(pg_scan_interval_secs.max(60));
    loop {
        if last_pg.elapsed() >= std::time::Duration::from_secs(pg_scan_interval_secs.max(60)) {
            match enqueue_from_pg(cfg, 20).await {
                Ok(r) => {
                    if r.enqueued > 0 {
                        tracing::info!(
                            enqueued = r.enqueued,
                            depth = r.queue_depth,
                            "actor-warm pg enqueue"
                        );
                    }
                }
                Err(e) => tracing::warn!(error = %e, "actor-warm pg scan failed"),
            }
            last_pg = std::time::Instant::now();
        }

        // Gather a small batch with short BRPOP windows for getProfiles efficiency.
        let mut batch: Vec<WarmJob> = Vec::with_capacity(GET_PROFILES_BATCH);
        match redis_util::queue_brpop(&mut q, QUEUE_NAME, 5.0).await {
            Ok(Some(item)) => {
                if let Some(job) = parse_job(&item, cfg.default_owner_id) {
                    batch.push(job);
                } else {
                    tracing::warn!(%item, "bad actor-warm job");
                }
            }
            Ok(None) => {}
            Err(e) => {
                tracing::error!(error = %e, "actor-warm BRPOP failed");
                tokio::time::sleep(std::time::Duration::from_secs(2)).await;
                q = redis_util::connect(&cfg.redis_queue_url).await?;
                continue;
            }
        }
        // Non-blocking drain up to batch size.
        while batch.len() < GET_PROFILES_BATCH {
            match redis_util::queue_rpop(&mut q, QUEUE_NAME).await {
                Ok(Some(item)) => {
                    if let Some(job) = parse_job(&item, cfg.default_owner_id) {
                        batch.push(job);
                    }
                }
                _ => break,
            }
        }
        if batch.is_empty() {
            continue;
        }
        match process_batch(cfg, &batch).await {
            Ok((warmed, failed)) => tracing::info!(
                n = batch.len(),
                warmed,
                failed,
                "actor-warm batch"
            ),
            Err(e) => tracing::warn!(error = %e, "actor-warm batch failed"),
        }
    }
}

pub async fn run_enqueue_cli(cfg: &Config, limit: usize) -> Result<()> {
    let report = enqueue_from_pg(cfg, limit).await?;
    println!("{}", serde_json::to_string_pretty(&report)?);
    Ok(())
}

pub async fn run_once_cli(cfg: &Config, actors: &[String], owner: i64) -> Result<()> {
    let jobs: Vec<WarmJob> = actors
        .iter()
        .filter_map(|a| {
            normalize_actor_ref(a).map(|did| WarmJob {
                did,
                actor: String::new(),
                owner,
                ts: chrono::Utc::now().timestamp(),
                source: "cli".into(),
            })
        })
        .collect();
    let (warmed, failed) = process_batch(cfg, &jobs).await?;
    println!(
        "{}",
        serde_json::to_string_pretty(&json!({
            "warmed": warmed,
            "failed": failed,
            "n": jobs.len(),
            "source": "vaak-worker-actor-once",
        }))?
    );
    Ok(())
}

fn sha256_hex(s: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(s.as_bytes());
    hex::encode(hasher.finalize())
}
