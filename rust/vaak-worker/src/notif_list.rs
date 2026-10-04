//! Mentions / Ice Cubes notification **list** warm + Axum shadow read.
//!
//! Warm path (10.5): prefer native Rust materialize from
//! `ap_notification_projection` → Redis `vaak:notifications:v1:{owner}:{hash}`.
//! PHP `notif-list-warm.php` remains rare cold-start fallback when projection
//! cannot fill and no confirmed warm envelope exists. Quiet accounts that
//! already have an empty `vaak-worker-projection` / `vaak-worker-live`
//! envelope skip-fresh (0.6.52) so PHP is not respawned every tick.
//! Axum `:8787` reads the same Redis keys (M3/M5).

use std::path::PathBuf;
use std::process::Stdio;
use std::time::{Duration, Instant};

use anyhow::{bail, Context, Result};
use chrono::{Duration as ChronoDuration, Utc};
use serde::Serialize;
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use tokio::process::Command;
use tokio_postgres::Client;

use crate::config::Config;
use crate::notif;
use crate::redis_util;

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
        script: api_root.join("bin/notif-list-warm.php"),
    }
}

fn env_flag_default_true(name: &str) -> bool {
    match std::env::var(name) {
        Ok(v) if !v.trim().is_empty() => {
            !matches!(v.trim().to_ascii_lowercase().as_str(), "0" | "false" | "off" | "no")
        }
        _ => true,
    }
}

fn refresh_secs() -> i64 {
    std::env::var("VAAK_NOTIF_LIST_REFRESH_SECS")
        .ok()
        .and_then(|s| s.parse().ok())
        .unwrap_or(90)
        .clamp(15, 600)
}

struct WarmJob {
    label: &'static str,
    limit: i64,
    types: Vec<String>,
}

fn warm_jobs(limit: i64) -> Vec<WarmJob> {
    let limit = limit.clamp(1, 80);
    vec![
        WarmJob {
            label: "all40",
            limit: 40,
            types: vec![],
        },
        WarmJob {
            label: "all30",
            limit,
            types: vec![],
        },
        WarmJob {
            label: "mentions",
            limit,
            types: vec!["mention".into()],
        },
        WarmJob {
            label: "favourites",
            limit,
            types: vec!["favourite".into()],
        },
        WarmJob {
            label: "boosts_quotes",
            limit,
            types: vec!["reblog".into(), "quote".into()],
        },
    ]
}

async fn redis_list_age(
    redis: &mut redis::aio::MultiplexedConnection,
    key: &str,
) -> Result<Option<i64>> {
    let cached = redis_util::json_get(redis, key).await?;
    let Some(payload) = cached else {
        return Ok(None);
    };
    let items_empty = payload
        .get("items")
        .and_then(|v| v.as_array())
        .map(|a| a.is_empty())
        .unwrap_or(true);
    if items_empty {
        // 0.6.43: never treat *unknown* empty as fresh (thin-projection poison).
        // 0.6.52: empty envelopes from a confirmed warm (`vaak-worker-projection`
        // or `vaak-worker-live`) are authoritative quiet-account pages — skip
        // so we do not respawn PHP every tick.
        let source = payload
            .get("source")
            .and_then(|v| v.as_str())
            .unwrap_or("");
        if source != "vaak-worker-projection" && source != "vaak-worker-live" {
            return Ok(None);
        }
    }
    let ts = payload.get("ts").and_then(|v| v.as_i64()).unwrap_or(0);
    if ts < 1 {
        return Ok(None);
    }
    Ok(Some((Utc::now().timestamp() - ts).max(0)))
}

/// Read recent projection rows and filter to a Mentions page.
///
/// - Full pages (>= limit) always win.
/// - Partial/empty pages win only when the 80-row recent window is exhausted
///   (sparse filters like mentions/boosts can skip PHP hydrate).
/// - Thin windows (`scanned < 80`) may accept **non-empty** partials, but must
///   never return an empty `Some([])` — that poisons Redis and Mentions shows
///   "No notifications yet" while PHP hydrate is skipped.
async fn projection_page(
    db: &Client,
    owner_user_id: i64,
    want: &[String],
    limit: i64,
) -> Result<Option<Vec<Value>>> {
    // Match PHP gmdate('c') / stored updated_at (`…+00:00`), not `…Z` — text compare.
    let cutoff = (Utc::now() - ChronoDuration::seconds(900))
        .format("%Y-%m-%dT%H:%M:%S+00:00")
        .to_string();
    let rows = db
        .query(
            "SELECT payload_json FROM ap_notification_projection
             WHERE owner_user_id = $1 AND updated_at >= $2
             ORDER BY created_at DESC, notification_id DESC
             LIMIT 80",
            &[&owner_user_id, &cutoff],
        )
        .await
        .context("projection select")?;
    let scanned = rows.len();
    let want_set: std::collections::HashSet<&str> = want.iter().map(|s| s.as_str()).collect();
    let mut out: Vec<Value> = Vec::new();
    for row in rows {
        let raw: String = row.get(0);
        let Ok(item) = serde_json::from_str::<Value>(&raw) else {
            continue;
        };
        let typ = item.get("type").and_then(|v| v.as_str()).unwrap_or("");
        if typ.is_empty() || !want_set.contains(typ) {
            continue;
        }
        out.push(item);
        if out.len() as i64 >= limit {
            break;
        }
    }
    if (out.len() as i64) >= limit {
        out.truncate(limit as usize);
        return Ok(Some(out));
    }
    if scanned >= 80 {
        // Recent window exhausted — partial/empty is authoritative for this filter.
        return Ok(Some(out));
    }
    if !out.is_empty() {
        // Thin window with real hits: accept partial rather than waiting on PHP.
        return Ok(Some(out));
    }
    // Thin/empty window (incl. scanned=0): fall through to PHP hydrate.
    Ok(None)
}

async fn write_list_envelope(
    redis: &mut redis::aio::MultiplexedConnection,
    key: &str,
    items: Vec<Value>,
) -> Result<()> {
    let payload = json!({
        "ts": Utc::now().timestamp(),
        "items": items,
        "source": "vaak-worker-projection",
    });
    redis_util::json_set(redis, key, &payload, 600).await
}

/// PHP materializer fallback (full hydrate) when projection cannot fill pages.
async fn warm_owner_php(owner_user_id: i64, limit: i64) -> Result<String> {
    let paths = warm_paths();
    if !paths.script.is_file() {
        bail!("notif-list warm script missing: {}", paths.script.display());
    }
    let limit = limit.clamp(1, 80);
    let started = Instant::now();
    let mut cmd = Command::new(&paths.php_bin);
    cmd.arg(&paths.script)
        .arg(format!("--owner-id={owner_user_id}"))
        .arg(format!("--limit={limit}"))
        .env("VAAK_NOTIF_LIST_WARM", "1")
        .env("VAAK_FEATURE_BLUESKY_TAB", "1")
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
    let stdout = String::from_utf8_lossy(&output.stdout).trim().to_string();
    let stderr = String::from_utf8_lossy(&output.stderr).trim().to_string();
    let ms = started.elapsed().as_millis();
    if !output.status.success() {
        bail!(
            "notif-list-warm owner={owner_user_id} exit={} ms={ms} stderr={stderr} stdout={stdout}",
            output.status.code().unwrap_or(-1)
        );
    }
    if !stderr.is_empty() {
        tracing::warn!(owner = owner_user_id, %stderr, "notif-list-warm stderr");
    }
    Ok(format!("php_fallback ms={ms}\n{stdout}"))
}

/// Warm one owner: projection→Redis first; PHP spawn if any Mentions page is incomplete.
pub async fn warm_owner(cfg: &Config, owner_user_id: i64, limit: i64) -> Result<String> {
    let limit = limit.clamp(1, 80);
    let started = Instant::now();
    let native = env_flag_default_true("VAAK_NOTIF_NATIVE_PROJECTION");
    let refresh = refresh_secs();

    if !native {
        let body = warm_owner_php(owner_user_id, limit).await?;
        return Ok(format!(
            "owner={owner_user_id} ms={} mode=php_only\n{body}",
            started.elapsed().as_millis()
        ));
    }

    let db = crate::db::connect(&cfg.database_url).await?;
    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let jobs = warm_jobs(limit);
    let mut lines: Vec<String> = Vec::new();
    let mut skip_fresh = 0u32;
    let mut native_ok = 0u32;
    let mut need_php = false;
    let mut all40_items: Option<Vec<Value>> = None;

    for job in &jobs {
        let want = expand_types(&job.types);
        let key = notifications_list_redis_key(owner_user_id, job.limit, None, None, &want);

        // Derive all30 from a just-built all40 before skip-fresh.
        if job.label == "all30" {
            if let Some(ref items40) = all40_items {
                let items30: Vec<Value> = items40.iter().take(job.limit as usize).cloned().collect();
                let n = items30.len();
                write_list_envelope(&mut redis, &key, items30).await?;
                native_ok += 1;
                lines.push(format!(
                    "owner={owner_user_id} job=all30 derive=all40 items={n} ms=0"
                ));
                continue;
            }
        }

        if let Some(age) = redis_list_age(&mut redis, &key).await? {
            if age <= refresh {
                skip_fresh += 1;
                lines.push(format!(
                    "owner={owner_user_id} job={} skip_fresh age={age} refresh={refresh} ms=0",
                    job.label
                ));
                continue;
            }
        }

        let t0 = Instant::now();
        match projection_page(&db, owner_user_id, &want, job.limit).await? {
            Some(items) => {
                let n = items.len();
                if job.label == "all40" {
                    all40_items = Some(items.clone());
                }
                write_list_envelope(&mut redis, &key, items).await?;
                native_ok += 1;
                lines.push(format!(
                    "owner={owner_user_id} job={} projection items={n} ms={}",
                    job.label,
                    t0.elapsed().as_millis()
                ));
            }
            None => {
                // Quiet account: prior warm already confirmed empty. Re-stamp as
                // projection instead of spawning PHP every refresh window.
                if let Some(prior) = redis_util::json_get(&mut redis, &key).await? {
                    let prior_empty = prior
                        .get("items")
                        .and_then(|v| v.as_array())
                        .map(|a| a.is_empty())
                        .unwrap_or(true);
                    let prior_source = prior
                        .get("source")
                        .and_then(|v| v.as_str())
                        .unwrap_or("");
                    if prior_empty
                        && (prior_source == "vaak-worker-projection"
                            || prior_source == "vaak-worker-live")
                    {
                        if job.label == "all40" {
                            all40_items = Some(Vec::new());
                        }
                        write_list_envelope(&mut redis, &key, Vec::new()).await?;
                        native_ok += 1;
                        lines.push(format!(
                            "owner={owner_user_id} job={} quiet_restamp ms={}",
                            job.label,
                            t0.elapsed().as_millis()
                        ));
                        continue;
                    }
                }
                need_php = true;
                lines.push(format!(
                    "owner={owner_user_id} job={} projection_incomplete ms={}",
                    job.label,
                    t0.elapsed().as_millis()
                ));
            }
        }
    }

    let mut php_note = String::new();
    if need_php {
        match warm_owner_php(owner_user_id, limit).await {
            Ok(body) => {
                php_note = body;
                lines.push("php_fallback=1".into());
            }
            Err(e) => {
                tracing::error!(owner = owner_user_id, error = %e, "notif-list php fallback failed");
                lines.push(format!("php_fallback_error={e}"));
            }
        }
    }

    let total_ms = started.elapsed().as_millis();
    let summary = format!(
        "owner={owner_user_id} warm_ok={} skip_fresh={skip_fresh} native={native_ok} php={} total_ms={total_ms} refresh_secs={refresh}",
        skip_fresh + native_ok,
        if need_php { 1 } else { 0 },
    );
    let mut out = lines.join("\n");
    if !out.is_empty() {
        out.push('\n');
    }
    out.push_str(&summary);
    if !php_note.is_empty() {
        out.push('\n');
        out.push_str(&php_note);
    }
    Ok(out)
}

pub async fn run_once(cfg: &Config, owner_user_id: i64, limit: i64) -> Result<()> {
    let body = warm_owner(cfg, owner_user_id, limit).await?;
    println!("{body}");
    Ok(())
}

/// Mastodon-shaped notification list shadow (M3) — reads warm Redis only.
#[derive(Debug, Serialize)]
pub struct NotificationsShadowReport {
    pub owner_user_id: i64,
    pub limit: i64,
    pub types: Vec<String>,
    pub redis_key: String,
    pub cache_hit: bool,
    pub cache_ts: Option<i64>,
    pub cache_age_secs: Option<i64>,
    pub source: String,
    pub count: usize,
    pub items: Vec<Value>,
    pub mode: &'static str,
    pub note: &'static str,
}

fn default_want_types() -> Vec<String> {
    [
        "mention",
        "follow",
        "favourite",
        "reblog",
        "quote",
        "poll",
        "update",
        "bite",
        "status",
    ]
    .into_iter()
    .map(str::to_string)
    .collect()
}

fn expand_types(types: &[String]) -> Vec<String> {
    let all = default_want_types();
    if types.is_empty() {
        return all;
    }
    let set: std::collections::HashSet<&str> = types.iter().map(|s| s.as_str()).collect();
    all.into_iter().filter(|t| set.contains(t.as_str())).collect()
}

/// Mirror PHP `hash('sha256', json_encode([limit,max,since,types,exclude], JSON_UNESCAPED_SLASHES))`.
///
/// Key order must match PHP insertion order (`limit,max,since,types,exclude`). Default
/// `serde_json::Map` is a BTreeMap and alphabetizes keys, which breaks Redis parity.
pub fn notifications_list_redis_key(
    owner_user_id: i64,
    limit: i64,
    max_id: Option<&str>,
    since_id: Option<&str>,
    types: &[String],
) -> String {
    let want = expand_types(types);
    let max_json = max_id
        .map(|s| serde_json::to_string(s).unwrap_or_else(|_| "null".into()))
        .unwrap_or_else(|| "null".into());
    let since_json = since_id
        .map(|s| serde_json::to_string(s).unwrap_or_else(|_| "null".into()))
        .unwrap_or_else(|| "null".into());
    let types_json = serde_json::to_string(&want).unwrap_or_else(|_| "[]".into());
    // exclude is always [] for Mentions warm / shadow reads today.
    let encoded = format!(
        "{{\"limit\":{limit},\"max\":{max_json},\"since\":{since_json},\"types\":{types_json},\"exclude\":[]}}"
    );
    let mut hasher = Sha256::new();
    hasher.update(encoded.as_bytes());
    format!(
        "vaak:notifications:v1:{owner_user_id}:{}",
        hex::encode(hasher.finalize())
    )
}

pub async fn notifications_shadow(
    cfg: &Config,
    owner_user_id: i64,
    limit: i64,
    types: &[String],
    max_id: Option<&str>,
    since_id: Option<&str>,
) -> Result<NotificationsShadowReport> {
    let limit = limit.clamp(1, 80);
    let want = expand_types(types);
    let redis_key =
        notifications_list_redis_key(owner_user_id, limit, max_id, since_id, &want);
    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let cached = redis_util::json_get(&mut redis, &redis_key).await?;

    let now = chrono::Utc::now().timestamp();
    if let Some(payload) = cached {
        let ts = payload.get("ts").and_then(|v| v.as_i64());
        let items = payload
            .get("items")
            .and_then(|v| v.as_array())
            .cloned()
            .unwrap_or_default();
        let source = payload
            .get("source")
            .and_then(|v| v.as_str())
            .unwrap_or("")
            .to_string();
        return Ok(NotificationsShadowReport {
            owner_user_id,
            limit,
            types: want,
            redis_key,
            cache_hit: true,
            cache_ts: ts,
            cache_age_secs: ts.map(|t| (now - t).max(0)),
            source: if source.is_empty() {
                "redis".into()
            } else {
                source
            },
            count: items.len(),
            items,
            mode: "shadow",
            note: "Axum reads warm Redis list only; PHP remains live /api/v1/notifications until cutover.",
        });
    }

    Ok(NotificationsShadowReport {
        owner_user_id,
        limit,
        types: want,
        redis_key,
        cache_hit: false,
        cache_ts: None,
        cache_age_secs: None,
        source: "miss".into(),
        count: 0,
        items: vec![],
        mode: "shadow",
        note: "Cache miss — run notif-list warm or open Mentions in PHP; shadow does not hydrate.",
    })
}

pub async fn run_loop(cfg: &Config, owner_user_id: i64, interval_secs: u64, limit: i64) -> Result<()> {
    let interval = Duration::from_secs(interval_secs.max(15));
    loop {
        let owners: Vec<i64> = if owner_user_id > 0 {
            vec![owner_user_id]
        } else {
            match crate::db::connect(&cfg.database_url).await {
                Ok(db) => match notif::list_local_owner_ids(&db).await {
                    Ok(ids) if !ids.is_empty() => ids,
                    Ok(_) => {
                        tracing::warn!("notif-list loop: no local ap_users; sleeping");
                        vec![]
                    }
                    Err(e) => {
                        tracing::error!(error = %e, "notif-list loop: list owners failed");
                        vec![]
                    }
                },
                Err(e) => {
                    tracing::error!(error = %e, "notif-list loop: db connect failed");
                    vec![]
                }
            }
        };
        for owner in owners {
            match warm_owner(cfg, owner, limit).await {
                Ok(body) => {
                    // Prefer the Rust summary (`native=` / `php=`) over the PHP
                    // materializer trailer (`warm_fail=`), which is appended after
                    // fallback and used to mask php=1 in logs.
                    let summary = body
                        .lines()
                        .rev()
                        .find(|l| {
                            l.starts_with("owner=")
                                && l.contains("total_ms=")
                                && l.contains("native=")
                                && l.contains("php=")
                        })
                        .or_else(|| {
                            body.lines().rev().find(|l| {
                                l.starts_with("owner=") && l.contains("total_ms=")
                            })
                        })
                        .or_else(|| body.lines().last())
                        .unwrap_or("ok");
                    tracing::info!(owner, %summary, "notif-list warm ok");
                }
                Err(e) => tracing::error!(owner, error = %e, "notif-list warm failed"),
            }
        }
        tokio::time::sleep(interval).await;
    }
}
