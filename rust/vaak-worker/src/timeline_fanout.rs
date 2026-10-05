//! Timeline ranked fan-out (0.6.73) — Rust primary via Redis queue.
//!
//! Queue: `vaak:queue:timeline_fanout` (Redis DB1). PHP enqueues; this worker
//! prepends into `vaak:timeline:ranked:v2:*`, invalidates Home hydrate, and
//! optionally warms ranked→hydrate envelopes for small Home fan-outs.
//!
//! Job `op` values:
//! - `home_followers` — AP Create/Announce/Quote* → local followers' Home
//! - `home_bsky` — new Bluesky post → observers' Home
//! - `public_feed` — public firehose → active owners' Federated (+ Local)
//! - `owner_status` — local compose/boost → one owner's views

use std::collections::{HashMap, HashSet};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use sha2::{Digest, Sha256};
use tokio_postgres::Client;

use crate::config::Config;
use crate::hidden;
use crate::home_hydrate_ranked;
use crate::redis_util;
use crate::timeline;

pub const QUEUE_NAME: &str = "timeline_fanout";
const RANKED_TTL_SECS: u64 = 600;
const MAX_RANKED: usize = 160;
const ACTIVE_OWNERS_CACHE_KEY: &str = "vaak:timeline:active-owners:v1";
const ACTIVE_OWNERS_TTL_SECS: u64 = 60;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FanoutJob {
    pub op: String,
    #[serde(default)]
    pub k: String,
    #[serde(default)]
    pub id: String,
    #[serde(default)]
    pub s: String,
    #[serde(default)]
    pub actor: String,
    #[serde(default)]
    pub type_name: String,
    #[serde(default, rename = "type")]
    pub type_alt: String,
    #[serde(default)]
    pub visibility: String,
    #[serde(default)]
    pub owner: i64,
    #[serde(default)]
    pub views: Vec<String>,
    #[serde(default)]
    pub hydrate: bool,
    #[serde(default)]
    pub ts: i64,
}

#[derive(Debug, Default)]
pub struct FanoutStats {
    pub touched_owners: usize,
    pub prepended_keys: usize,
    pub skipped: usize,
}

fn env_flag_default_true(name: &str) -> bool {
    match std::env::var(name) {
        Ok(v) if !v.trim().is_empty() => {
            !matches!(v.trim().to_ascii_lowercase().as_str(), "0" | "false" | "off" | "no")
        }
        _ => true,
    }
}

fn ingest_enabled() -> bool {
    env_flag_default_true("VAAK_HOME_FANOUT_INGEST")
}

fn max_recipients() -> usize {
    std::env::var("VAAK_HOME_FANOUT_INGEST_MAX")
        .ok()
        .and_then(|s| s.parse().ok())
        .unwrap_or(64)
        .clamp(1, 128)
}

fn rewarm_max() -> usize {
    std::env::var("VAAK_HOME_FANOUT_INGEST_REWARM_MAX")
        .ok()
        .and_then(|s| s.parse().ok())
        .unwrap_or(4)
        .clamp(0, 32)
}

fn sha256_hex(s: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(s.as_bytes());
    hex::encode(hasher.finalize())
}

fn ranked_redis_key(logical: &str) -> String {
    format!("vaak:timeline:ranked:v2:{}", sha256_hex(logical))
}

fn owner_index_key(owner: i64) -> String {
    format!("vaak:timeline:owner-index:v1:{}", owner.max(0))
}

/// Parity with PHP `ap_timeline_home_hydrate_redis_key` (null since, empty x).
fn home_hydrate_redis_key(owner: i64, limit: i64) -> String {
    timeline::home_timeline_redis_key(owner.max(0), limit, None)
}

fn norm_actor(s: &str) -> String {
    s.trim().trim_end_matches('/').to_string()
}

fn actor_is_local(actor: &str) -> bool {
    norm_actor(actor).starts_with("https://mkultra.monster/users/")
}

fn job_type(job: &FanoutJob) -> &str {
    if !job.type_name.is_empty() {
        &job.type_name
    } else {
        &job.type_alt
    }
}

fn now_unix() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0)
}

async fn invalidate_home_hydrate(
    cache: &mut redis::aio::MultiplexedConnection,
    owner: i64,
) -> Result<()> {
    let keys: Vec<String> = [15_i64, 40, 80, 20, 30]
        .into_iter()
        .map(|lim| home_hydrate_redis_key(owner, lim))
        .collect();
    if !keys.is_empty() {
        let mut cmd = redis::cmd("DEL");
        for k in &keys {
            cmd.arg(k);
        }
        let _: Result<i64, _> = cmd.query_async(cache).await;
    }
    Ok(())
}

async fn maybe_spawn_home_hydrate_warm(cfg: &Config, owner: i64) {
    if !env_flag_default_true("VAAK_HOME_HYDRATE_WARM") {
        return;
    }
    let cfg = cfg.clone();
    tokio::spawn(async move {
        match home_hydrate_ranked::warm_owner(&cfg, owner, &[15, 40, 80]).await {
            Ok(report) => tracing::debug!(
                owner,
                n = report.materialised_n,
                ms = report.ms,
                "fanout hydrate ranked warm ok"
            ),
            Err(e) => tracing::warn!(
                owner,
                error = %format!("{e:#}"),
                "fanout hydrate ranked warm failed"
            ),
        }
    });
}

async fn prepend_owner(
    cache: &mut redis::aio::MultiplexedConnection,
    owner: i64,
    entry_k: &str,
    entry_id: &str,
    entry_s: &str,
    views: &[&str],
) -> Result<usize> {
    let owner = owner.max(0);
    let entry_k = entry_k.trim();
    let entry_id = entry_id.trim().trim_end_matches('/');
    if owner < 1 || entry_k.is_empty() || entry_id.is_empty() {
        return Ok(0);
    }
    let want: HashSet<&str> = views
        .iter()
        .copied()
        .filter(|v| matches!(*v, "home" | "local" | "feed"))
        .collect();
    if want.is_empty() {
        return Ok(0);
    }

    let index_key = owner_index_key(owner);
    let logicals = match redis_util::json_get(cache, &index_key).await? {
        Some(Value::Array(arr)) => arr,
        _ => return Ok(0),
    };

    let dedupe = format!("{entry_k}:{entry_id}");
    let new_entry = json!({
        "k": entry_k,
        "id": entry_id,
        "s": if entry_s.is_empty() { entry_k } else { entry_s },
    });

    let prefix_home = format!("v13_home_u{owner}_");
    let prefix_local = format!("v13_local_u{owner}_");
    let prefix_feed = format!("v13_feed_u{owner}_");
    let mut touched = 0usize;

    for logical_v in logicals {
        let logical = match logical_v.as_str() {
            Some(s) if !s.is_empty() => s,
            _ => continue,
        };
        let view = if want.contains("home") && logical.starts_with(&prefix_home) {
            "home"
        } else if want.contains("local") && logical.starts_with(&prefix_local) {
            "local"
        } else if want.contains("feed") && logical.starts_with(&prefix_feed) {
            "feed"
        } else {
            continue;
        };
        let _ = view;

        let redis_key = ranked_redis_key(logical);
        let env = match redis_util::json_get(cache, &redis_key).await? {
            Some(v) => v,
            None => continue,
        };
        let old_ranked = match env.get("ranked").and_then(|r| r.as_array()) {
            Some(a) => a,
            None => continue,
        };

        let mut ranked = Vec::with_capacity(old_ranked.len().saturating_add(1).min(MAX_RANKED));
        let mut seen = HashSet::new();
        seen.insert(dedupe.clone());
        ranked.push(new_entry.clone());
        for row in old_ranked {
            let rk = row.get("k").and_then(|v| v.as_str()).unwrap_or("").trim();
            let rid = row
                .get("id")
                .and_then(|v| v.as_str())
                .unwrap_or("")
                .trim()
                .trim_end_matches('/');
            if rk.is_empty() || rid.is_empty() {
                continue;
            }
            let dk = format!("{rk}:{rid}");
            if !seen.insert(dk) {
                continue;
            }
            let mut item = json!({"k": rk, "id": rid});
            if let Some(s) = row.get("s").and_then(|v| v.as_str()).map(str::trim) {
                if !s.is_empty() {
                    item["s"] = json!(s);
                }
            }
            if row.get("t").and_then(|v| v.as_i64()).unwrap_or(0) != 0 {
                item["t"] = json!(1);
            }
            ranked.push(item);
            if ranked.len() >= MAX_RANKED {
                break;
            }
        }

        let mut source_counts: HashMap<String, i64> = HashMap::new();
        for row in &ranked {
            let source = row
                .get("s")
                .and_then(|v| v.as_str())
                .or_else(|| row.get("k").and_then(|v| v.as_str()))
                .unwrap_or("unknown");
            let source = if source.is_empty() { "unknown" } else { source };
            *source_counts.entry(source.to_string()).or_insert(0) += 1;
        }
        let mut sc_obj = Map::new();
        let mut keys: Vec<_> = source_counts.keys().cloned().collect();
        keys.sort();
        for k in keys {
            sc_obj.insert(k.clone(), json!(source_counts[&k]));
        }

        let now = now_unix();
        let payload = json!({
            "ts": now,
            "warm_ts": now,
            "source": env.get("source").and_then(|v| v.as_str()).unwrap_or("vaak-fanout-prepend"),
            "ranked": ranked,
            "stage_meta": {
                "ranked_count": ranked.len(),
                "source_counts": Value::Object(sc_obj),
                "fanout": "prepend",
                "fanout_src": "vaak-worker",
            },
        });
        redis_util::json_set(cache, &redis_key, &payload, RANKED_TTL_SECS).await?;
        touched += 1;
    }
    Ok(touched)
}

async fn local_follower_owner_ids(db: &Client, actor_id: &str, limit: i64) -> Result<Vec<i64>> {
    let actor = norm_actor(actor_id);
    if actor.is_empty() || !actor.starts_with("https://") {
        return Ok(vec![]);
    }
    let actor_slash = format!("{actor}/");
    let rows = db
        .query(
            "SELECT DISTINCT owner_actor_id FROM following
             WHERE actor_id = $1 OR actor_id = $2
             LIMIT $3",
            &[&actor, &actor_slash, &limit],
        )
        .await
        .context("select following for fanout")?;
    let mut owner_actors = Vec::new();
    for row in rows {
        let oa: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let oa = norm_actor(&oa);
        if oa.starts_with("https://mkultra.monster/users/") {
            owner_actors.push(oa);
        }
    }
    if owner_actors.is_empty() {
        return Ok(vec![]);
    }
    let mut ids = Vec::new();
    let mut seen = HashSet::new();
    for oa in owner_actors {
        let row = db
            .query_opt(
                "SELECT id FROM ap_users WHERE rtrim(actor_id, '/') = $1 LIMIT 1",
                &[&oa],
            )
            .await?;
        if let Some(r) = row {
            let uid: i64 = r.get(0);
            if uid > 0 && seen.insert(uid) {
                ids.push(uid);
            }
        }
        if ids.len() as i64 >= limit {
            break;
        }
    }
    Ok(ids)
}

async fn bsky_observer_ids(db: &Client, did: &str, limit: i64) -> Result<Vec<i64>> {
    let did = did.trim();
    if !did.starts_with("did:") {
        return Ok(vec![]);
    }
    let rows = db
        .query(
            "SELECT DISTINCT owner_user_id FROM (
                SELECT owner_user_id FROM bsky_graph_sync
                 WHERE kind = 'follow' AND target_did = $1
                UNION
                SELECT owner_user_id FROM bsky_sessions WHERE did = $1
             ) t
             WHERE owner_user_id IS NOT NULL AND owner_user_id > 0
             LIMIT $2",
            &[&did, &limit],
        )
        .await
        .context("select bsky observers")?;
    let mut ids = Vec::new();
    let mut seen = HashSet::new();
    for row in rows {
        let uid: i64 = row.get(0);
        if uid > 0 && seen.insert(uid) {
            ids.push(uid);
        }
    }
    Ok(ids)
}

async fn bsky_hidden_dids(db: &Client, owner: i64) -> Result<HashSet<String>> {
    let mut out = HashSet::new();
    let rows = db
        .query(
            "SELECT did FROM bsky_hide_dids WHERE owner_user_id = $1",
            &[&owner],
        )
        .await;
    let rows = match rows {
        Ok(r) => r,
        Err(_) => return Ok(out), // table may be mid-migrate
    };
    for row in rows {
        let d: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let d = d.trim().to_string();
        if !d.is_empty() {
            out.insert(d);
        }
    }
    Ok(out)
}

async fn active_owner_ids(
    db: &Client,
    cache: &mut redis::aio::MultiplexedConnection,
    limit: i64,
) -> Result<Vec<i64>> {
    if let Ok(Some(Value::Array(arr))) = redis_util::json_get(cache, ACTIVE_OWNERS_CACHE_KEY).await
    {
        let mut ids = Vec::new();
        for v in arr {
            if let Some(n) = v.as_i64() {
                if n > 0 {
                    ids.push(n);
                }
            }
            if ids.len() as i64 >= limit {
                break;
            }
        }
        if !ids.is_empty() {
            return Ok(ids);
        }
    }

    let rows = db
        .query("SELECT id FROM ap_users ORDER BY id ASC", &[])
        .await
        .context("select ap_users for active owners")?;
    let mut ids = Vec::new();
    for row in rows {
        let uid: i64 = row.get(0);
        if uid < 1 {
            continue;
        }
        let key = owner_index_key(uid);
        let exists: bool = redis::cmd("EXISTS")
            .arg(&key)
            .query_async(cache)
            .await
            .unwrap_or(0i64)
            > 0;
        if exists {
            ids.push(uid);
        }
        if ids.len() as i64 >= limit {
            break;
        }
    }
    if !ids.is_empty() {
        let arr: Vec<Value> = ids.iter().map(|i| json!(*i)).collect();
        let _ = redis_util::json_set(
            cache,
            ACTIVE_OWNERS_CACHE_KEY,
            &Value::Array(arr),
            ACTIVE_OWNERS_TTL_SECS,
        )
        .await;
    }
    Ok(ids)
}

async fn process_home_followers(
    cfg: &Config,
    db: &Client,
    cache: &mut redis::aio::MultiplexedConnection,
    job: &FanoutJob,
) -> Result<FanoutStats> {
    let mut stats = FanoutStats::default();
    let actor = norm_actor(&job.actor);
    let id = job.id.trim().trim_end_matches('/');
    let k = if job.k.is_empty() { "event" } else { job.k.as_str() };
    let s = if job.s.is_empty() { "fediverse" } else { job.s.as_str() };
    if actor.is_empty() || id.is_empty() {
        stats.skipped += 1;
        return Ok(stats);
    }
    let owners = local_follower_owner_ids(db, &actor, max_recipients() as i64).await?;
    if owners.is_empty() {
        return Ok(stats);
    }
    let do_rewarm = rewarm_max() > 0 && owners.len() <= rewarm_max();
    for owner in &owners {
        let hidden_sets = hidden::load_hidden_sets(db, *owner).await.unwrap_or_default();
        if hidden_sets.is_hidden(&actor) {
            stats.skipped += 1;
            continue;
        }
        let n = prepend_owner(cache, *owner, k, id, s, &["home"]).await?;
        if n > 0 {
            stats.touched_owners += 1;
            stats.prepended_keys += n;
        }
        invalidate_home_hydrate(cache, *owner).await?;
        if do_rewarm {
            maybe_spawn_home_hydrate_warm(cfg, *owner).await;
        }
    }
    tracing::info!(
        op = "home_followers",
        typ = %job_type(job),
        event = %id,
        actor = %actor,
        owners = owners.len(),
        touched = stats.touched_owners,
        "timeline fanout"
    );
    let _ = cfg;
    Ok(stats)
}

async fn process_home_bsky(
    cfg: &Config,
    db: &Client,
    cache: &mut redis::aio::MultiplexedConnection,
    job: &FanoutJob,
) -> Result<FanoutStats> {
    let mut stats = FanoutStats::default();
    let did = job.actor.trim();
    let uri = job.id.trim();
    let k = if job.k.is_empty() { "bsky" } else { job.k.as_str() };
    let s = if job.s.is_empty() { "bluesky" } else { job.s.as_str() };
    if !did.starts_with("did:") || !uri.starts_with("at://") {
        stats.skipped += 1;
        return Ok(stats);
    }
    let owners = bsky_observer_ids(db, did, max_recipients() as i64).await?;
    if owners.is_empty() {
        return Ok(stats);
    }
    let do_rewarm = rewarm_max() > 0 && owners.len() <= rewarm_max();
    let bsky_profile = format!("https://bsky.app/profile/{did}");
    for owner in &owners {
        let hide = bsky_hidden_dids(db, *owner).await.unwrap_or_default();
        if hide.contains(did) {
            stats.skipped += 1;
            continue;
        }
        let hidden_sets = hidden::load_hidden_sets(db, *owner).await.unwrap_or_default();
        if hidden_sets.is_hidden(&bsky_profile) {
            stats.skipped += 1;
            continue;
        }
        let n = prepend_owner(cache, *owner, k, uri, s, &["home"]).await?;
        if n > 0 {
            stats.touched_owners += 1;
            stats.prepended_keys += n;
        }
        invalidate_home_hydrate(cache, *owner).await?;
        if do_rewarm {
            maybe_spawn_home_hydrate_warm(cfg, *owner).await;
        }
    }
    tracing::info!(
        op = "home_bsky",
        uri = %uri,
        did = %did,
        owners = owners.len(),
        touched = stats.touched_owners,
        "timeline fanout"
    );
    Ok(stats)
}

async fn process_public_feed(
    cfg: &Config,
    db: &Client,
    cache: &mut redis::aio::MultiplexedConnection,
    job: &FanoutJob,
) -> Result<FanoutStats> {
    let mut stats = FanoutStats::default();
    let actor = norm_actor(&job.actor);
    let id = job.id.trim().trim_end_matches('/');
    let k = if job.k.is_empty() { "event" } else { job.k.as_str() };
    let s = if job.s.is_empty() { "fediverse" } else { job.s.as_str() };
    let vis = job.visibility.trim().to_ascii_lowercase();
    if actor.is_empty() || id.is_empty() || (!vis.is_empty() && vis != "public") {
        stats.skipped += 1;
        return Ok(stats);
    }
    let owners = active_owner_ids(db, cache, max_recipients() as i64).await?;
    if owners.is_empty() {
        return Ok(stats);
    }
    let mut views = vec!["feed"];
    if actor_is_local(&actor) {
        views.push("local");
    }
    for owner in &owners {
        let hidden_sets = hidden::load_hidden_sets(db, *owner).await.unwrap_or_default();
        if hidden_sets.is_hidden(&actor) {
            stats.skipped += 1;
            continue;
        }
        let n = prepend_owner(cache, *owner, k, id, s, &views).await?;
        if n > 0 {
            stats.touched_owners += 1;
            stats.prepended_keys += n;
        }
    }
    tracing::info!(
        op = "public_feed",
        typ = %job_type(job),
        event = %id,
        actor = %actor,
        views = %views.join("+"),
        owners = owners.len(),
        touched = stats.touched_owners,
        "timeline fanout"
    );
    let _ = cfg;
    Ok(stats)
}

async fn process_owner_status(
    cfg: &Config,
    cache: &mut redis::aio::MultiplexedConnection,
    job: &FanoutJob,
) -> Result<FanoutStats> {
    let mut stats = FanoutStats::default();
    let owner = job.owner;
    let id = job.id.trim().trim_end_matches('/');
    let k = job.k.trim();
    let s = if job.s.is_empty() { k } else { job.s.as_str() };
    if owner < 1 || k.is_empty() || id.is_empty() {
        stats.skipped += 1;
        return Ok(stats);
    }
    let views: Vec<&str> = if job.views.is_empty() {
        vec!["home", "local"]
    } else {
        job.views
            .iter()
            .map(|v| v.as_str())
            .filter(|v| matches!(*v, "home" | "local" | "feed"))
            .collect()
    };
    if views.is_empty() {
        stats.skipped += 1;
        return Ok(stats);
    }
    let n = prepend_owner(cache, owner, k, id, s, &views).await?;
    if n > 0 {
        stats.touched_owners = 1;
        stats.prepended_keys = n;
    }
    let wants_home = views.contains(&"home");
    if wants_home || job.hydrate {
        invalidate_home_hydrate(cache, owner).await?;
        maybe_spawn_home_hydrate_warm(cfg, owner).await;
    }
    tracing::info!(
        op = "owner_status",
        owner,
        k,
        id,
        views = %views.join("+"),
        touched_keys = n,
        "timeline fanout"
    );
    Ok(stats)
}

pub async fn process_job(cfg: &Config, job: &FanoutJob) -> Result<FanoutStats> {
    if !ingest_enabled() {
        return Ok(FanoutStats {
            skipped: 1,
            ..Default::default()
        });
    }
    let db = crate::db::connect(&cfg.database_url).await?;
    let mut cache = redis_util::connect(&cfg.redis_url).await?;
    match job.op.as_str() {
        "home_followers" => process_home_followers(cfg, &db, &mut cache, job).await,
        "home_bsky" => process_home_bsky(cfg, &db, &mut cache, job).await,
        "public_feed" => process_public_feed(cfg, &db, &mut cache, job).await,
        "owner_status" => process_owner_status(cfg, &mut cache, job).await,
        other => {
            tracing::warn!(op = %other, "unknown timeline fanout op");
            Ok(FanoutStats {
                skipped: 1,
                ..Default::default()
            })
        }
    }
}

pub async fn run_once_cli(cfg: &Config, raw_json: &str) -> Result<()> {
    let job: FanoutJob = serde_json::from_str(raw_json).context("parse fanout job")?;
    let stats = process_job(cfg, &job).await?;
    println!(
        "{}",
        serde_json::to_string_pretty(&json!({
            "ok": true,
            "op": job.op,
            "touched_owners": stats.touched_owners,
            "prepended_keys": stats.prepended_keys,
            "skipped": stats.skipped,
            "source": "vaak-worker",
        }))?
    );
    Ok(())
}

pub async fn run_worker_loop(cfg: &Config) -> Result<()> {
    tracing::info!(queue = QUEUE_NAME, "timeline-fanout worker starting");
    let mut q = redis_util::connect(&cfg.redis_queue_url).await?;
    let mut last_depth_log = Instant::now() - Duration::from_secs(60);
    loop {
        if last_depth_log.elapsed() >= Duration::from_secs(60) {
            let depth = redis_util::queue_llen(&mut q, QUEUE_NAME).await.unwrap_or(0);
            tracing::info!(depth, "timeline-fanout queue");
            last_depth_log = Instant::now();
        }
        match redis_util::queue_brpop(&mut q, QUEUE_NAME, 5.0).await {
            Ok(Some(item)) => {
                let job: FanoutJob = match serde_json::from_str(&item) {
                    Ok(j) => j,
                    Err(e) => {
                        tracing::warn!(error = %e, %item, "bad timeline fanout job");
                        continue;
                    }
                };
                match process_job(cfg, &job).await {
                    Ok(stats) => tracing::debug!(
                        op = %job.op,
                        touched = stats.touched_owners,
                        keys = stats.prepended_keys,
                        "fanout ok"
                    ),
                    Err(e) => tracing::warn!(op = %job.op, error = %e, "fanout failed"),
                }
            }
            Ok(None) => {}
            Err(e) => {
                tracing::warn!(error = %e, "timeline-fanout BRPOP failed; reconnecting");
                tokio::time::sleep(Duration::from_secs(2)).await;
                q = redis_util::connect(&cfg.redis_queue_url).await?;
            }
        }
    }
}
