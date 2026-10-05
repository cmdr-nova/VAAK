//! Account-switch prep (0.7.5): warm the target owner's Home ranked + badge
//! before the 303 landing, so Axum Home HTML (0.7.4) can hit.

use std::time::Instant;

use anyhow::Result;
use serde_json::{json, Value};

use crate::config::Config;
use crate::notif;
use crate::ranked_warm;
use crate::redis_util;
use crate::timeline;

#[derive(Debug, Clone)]
pub struct AccountSwitchPrepReport {
    pub owner_user_id: i64,
    pub views: String,
    pub hydrate_hit: bool,
    pub hydrate_n: usize,
    pub hydrate_age_secs: Option<i64>,
    pub ranked_warmed: bool,
    pub ranked_line: String,
    pub unread_count: i64,
    pub hydrate_spawn: String,
    pub ms: u128,
    pub source: &'static str,
}

/// Prep caches for a freshly switched-to owner.
///
/// - Hydrate hit → skip ranked rebuild (Home HTML ready).
/// - Hydrate miss → native Home ranked warm (also kicks hydrate spawn) + force
///   hydrate spawn so the 303 landing can race toward 0.7.4 HTML.
/// - Always refresh live unread badge for the Mentions chrome.
pub async fn prep(cfg: &Config, owner_user_id: i64, views: &str) -> Result<AccountSwitchPrepReport> {
    let owner_user_id = owner_user_id.max(0);
    let views = {
        let v = views.trim().to_ascii_lowercase();
        if v.is_empty() {
            "home".to_string()
        } else {
            v
        }
    };
    let started = Instant::now();
    let mut report = AccountSwitchPrepReport {
        owner_user_id,
        views: views.clone(),
        hydrate_hit: false,
        hydrate_n: 0,
        hydrate_age_secs: None,
        ranked_warmed: false,
        ranked_line: String::new(),
        unread_count: 0,
        hydrate_spawn: "none".into(),
        ms: 0,
        source: "vaak-worker-account-switch",
    };
    if owner_user_id < 1 {
        report.ms = started.elapsed().as_millis();
        report.hydrate_spawn = "skip_owner".into();
        return Ok(report);
    }

    let hydrate = timeline::home_hydrate(cfg, owner_user_id, 15, None).await?;
    report.hydrate_hit = hydrate.cache_hit;
    report.hydrate_n = hydrate.n;
    report.hydrate_age_secs = hydrate.age_secs;

    let need_local = views.contains("local");
    let need_feed = views.contains("feed") || views.contains("federated");
    let need_home = views.contains("home") || (!need_local && !need_feed);

    // Rebuild ranked when hydrate is cold, or when landing on Local/Federated.
    let should_warm_ranked = !hydrate.cache_hit || need_local || need_feed;
    if should_warm_ranked {
        let mut warm_views = Vec::new();
        if need_home || !hydrate.cache_hit {
            warm_views.push("home");
        }
        if need_local {
            warm_views.push("local");
        }
        if need_feed {
            warm_views.push("feed");
        }
        let warm_spec = warm_views.join(",");
        match ranked_warm::warm_owner(cfg, owner_user_id, &warm_spec).await {
            Ok(line) => {
                report.ranked_warmed = true;
                report.ranked_line = line;
            }
            Err(e) => {
                report.ranked_line = format!("ranked_error:{e:#}");
                tracing::warn!(owner = owner_user_id, error = %format!("{e:#}"), "account-switch ranked warm failed");
            }
        }
    } else {
        report.ranked_line = "ranked=skip_hydrate_hit".into();
    }

    if !hydrate.cache_hit {
        report.hydrate_spawn = force_spawn_home_hydrate(cfg, owner_user_id).await;
    } else {
        report.hydrate_spawn = "skip_hydrate_hit".into();
    }

    // Live badge for the Mentions chrome on the landing page.
    match notif::compute_and_cache(cfg, owner_user_id, false, true).await {
        Ok(body) => {
            report.unread_count = body.get("count").and_then(|v| v.as_i64()).unwrap_or(0);
        }
        Err(e) => {
            tracing::warn!(owner = owner_user_id, error = %format!("{e:#}"), "account-switch unread warm failed");
        }
    }

    report.ms = started.elapsed().as_millis();
    Ok(report)
}

async fn force_spawn_home_hydrate(cfg: &Config, owner_user_id: i64) -> String {
    // Clear ranked-warm cooldown so switch always kicks a hydrate rebuild.
    if let Ok(mut redis) = redis_util::connect(&cfg.redis_url).await {
        let cooldown_key = format!("vaak:home:hydrate-warm:cd:{owner_user_id}");
        let _: Result<(), _> = redis::cmd("DEL")
            .arg(&cooldown_key)
            .query_async(&mut redis)
            .await;
    }
    let php = std::env::var("VAAK_PHP_BIN").unwrap_or_else(|_| "/usr/bin/php".into());
    let api_root = std::env::var("VAAK_API_ROOT").unwrap_or_else(|_| "/srv/mkultra/html/api".into());
    let script = std::path::PathBuf::from(&api_root).join("bin/home-timeline-warm.php");
    if !script.is_file() {
        return "hydrate=skip_missing_script".into();
    }
    match std::process::Command::new(&php)
        .arg(&script)
        .arg(format!("--owner-id={owner_user_id}"))
        .arg("--limits=15,40")
        .stdin(std::process::Stdio::null())
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .spawn()
    {
        Ok(_) => "hydrate=spawned".into(),
        Err(e) => format!("hydrate=spawn_err:{e}"),
    }
}

pub fn report_json(r: &AccountSwitchPrepReport) -> Value {
    json!({
        "owner_user_id": r.owner_user_id,
        "views": r.views,
        "hydrate_hit": r.hydrate_hit,
        "hydrate_n": r.hydrate_n,
        "hydrate_age_secs": r.hydrate_age_secs,
        "ranked_warmed": r.ranked_warmed,
        "ranked_line": r.ranked_line,
        "unread_count": r.unread_count,
        "hydrate_spawn": r.hydrate_spawn,
        "ms": r.ms,
        "source": r.source,
    })
}
