//! Mentions / Ice Cubes notification **list** warm (M1) + Axum shadow read (M3).
//!
//! Orchestrates PHP `api/bin/notif-list-warm.php`, which materializes full
//! Mastodon notification JSON into existing Redis keys
//! `vaak:notifications:v1:{owner}:{hash}`. Entity hydrate stays in PHP for now
//! so the freeze contract matches Mentions paint + Ice Cubes; Rust owns the
//! loop, multi-owner cadence, and (M3) localhost Axum JSON read of warm Redis.
//! End state: native hydrate + Axum live route, then drop PHP materializer.

use std::path::PathBuf;
use std::process::Stdio;
use std::time::{Duration, Instant};

use anyhow::{bail, Context, Result};
use serde::Serialize;
use serde_json::Value;
use sha2::{Digest, Sha256};
use tokio::process::Command;

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

/// Warm one owner's common Mentions / Ice Cubes list cache keys via PHP.
pub async fn warm_owner(owner_user_id: i64, limit: i64) -> Result<String> {
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
    // Peer-auth DSN when unset (matches other CLI smokes).
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
    Ok(format!("owner={owner_user_id} ms={ms}\n{stdout}"))
}

pub async fn run_once(cfg: &Config, owner_user_id: i64, limit: i64) -> Result<()> {
    let _ = cfg; // reserved for future native hydrate
    let body = warm_owner(owner_user_id, limit).await?;
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
            match warm_owner(owner, limit).await {
                Ok(body) => {
                    let summary = body.lines().last().unwrap_or("ok");
                    tracing::info!(owner, %summary, "notif-list warm ok");
                }
                Err(e) => tracing::error!(owner, error = %e, "notif-list warm failed"),
            }
        }
        tokio::time::sleep(interval).await;
    }
}
