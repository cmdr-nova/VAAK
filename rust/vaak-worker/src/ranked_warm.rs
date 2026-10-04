//! Home / Local / Federated **ranked** warm (loading-plan slice 2).
//!
//! Orchestrates PHP `api/bin/ranked-warm.php`, which calls
//! `admin_tl_lean_ranked_warm` into existing Redis keys
//! `vaak:timeline:ranked:v2:{sha256(logical)}` + owner index.
//! Interim bridge — native Rust ranked rebuild replaces this, then PHP drops.

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
        script: api_root.join("bin/ranked-warm.php"),
    }
}

pub async fn warm_owner(owner_user_id: i64, views: &str) -> Result<String> {
    let paths = warm_paths();
    if !paths.script.is_file() {
        bail!("ranked-warm script missing: {}", paths.script.display());
    }
    let views = if views.trim().is_empty() {
        "home,local,feed"
    } else {
        views
    };
    let started = Instant::now();
    let mut cmd = Command::new(&paths.php_bin);
    cmd.arg(&paths.script)
        .arg(format!("--owner-id={owner_user_id}"))
        .arg(format!("--views={views}"))
        .env("VAAK_RANKED_WARM", "1")
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
            "ranked-warm owner={owner_user_id} exit={} ms={ms} stderr={stderr} stdout={stdout}",
            output.status.code().unwrap_or(-1)
        );
    }
    if !stderr.is_empty() {
        tracing::warn!(owner = owner_user_id, %stderr, "ranked-warm stderr");
    }
    Ok(format!("owner={owner_user_id} ms={ms}\n{stdout}"))
}

pub async fn run_once(cfg: &Config, owner_user_id: i64, views: &str) -> Result<()> {
    let _ = cfg;
    let body = warm_owner(owner_user_id, views).await?;
    println!("{body}");
    Ok(())
}

pub async fn run_loop(
    cfg: &Config,
    owner_user_id: i64,
    interval_secs: u64,
    views: &str,
) -> Result<()> {
    let interval = Duration::from_secs(interval_secs.max(30));
    let views_owned = views.to_string();
    loop {
        let owners: Vec<i64> = if owner_user_id > 0 {
            vec![owner_user_id]
        } else {
            match crate::db::connect(&cfg.database_url).await {
                Ok(db) => match notif::list_local_owner_ids(&db).await {
                    Ok(ids) if !ids.is_empty() => ids,
                    Ok(_) => {
                        tracing::warn!("ranked-warm loop: no local ap_users; sleeping");
                        vec![]
                    }
                    Err(e) => {
                        tracing::error!(error = %e, "ranked-warm loop: list owners failed");
                        vec![]
                    }
                },
                Err(e) => {
                    tracing::error!(error = %e, "ranked-warm loop: db connect failed");
                    vec![]
                }
            }
        };
        for owner in owners {
            match warm_owner(owner, &views_owned).await {
                Ok(body) => {
                    let summary = body.lines().last().unwrap_or("ok");
                    tracing::info!(owner, %summary, "ranked-warm ok");
                }
                Err(e) => tracing::error!(owner, error = %e, "ranked-warm failed"),
            }
        }
        tokio::time::sleep(interval).await;
    }
}
