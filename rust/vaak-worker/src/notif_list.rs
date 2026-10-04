//! Mentions / Ice Cubes notification **list** warm (M1).
//!
//! Orchestrates PHP `api/bin/notif-list-warm.php`, which materializes full
//! Mastodon notification JSON into existing Redis keys
//! `vaak:notifications:v1:{owner}:{hash}`. Entity hydrate stays in PHP so the
//! freeze contract matches Mentions paint + Ice Cubes; Rust owns the loop,
//! multi-owner cadence, and live cutover flag.

use std::path::PathBuf;
use std::process::Stdio;
use std::time::{Duration, Instant};

use anyhow::{bail, Context, Result};
use tokio::process::Command;

use crate::config::Config;
use crate::notif;

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
