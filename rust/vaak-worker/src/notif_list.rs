//! Mentions / Ice Cubes notification **list** warm + Axum shadow read.
//!
//! Warm path (10.5 / 0.6.66): native Rust materialize from
//! `ap_notification_projection` → Redis `vaak:notifications:v4:{owner}:{hash}`.
//! When the projection window is thin/empty, spawn temporary PHP
//! `bin/notif-projection-fill.php` (local PG hydrate → projection + Redis)
//! then retry native materialize. HTTP Mentions still falls back to PHP
//! hydrate on total miss. Quiet accounts with empty `vaak-worker-projection`
//! / `vaak-worker-live` envelopes skip-fresh / quiet_restamp (0.6.52).
//! Axum `:8787` reads the same Redis keys (M3/M5).
//!
//! Destination: replace the PHP fill bridge with Rust-native hydrate.

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

fn projection_fill_enabled() -> bool {
    match std::env::var("VAAK_NOTIF_PROJECTION_FILL") {
        Ok(v) if !v.trim().is_empty() => {
            !matches!(v.trim().to_ascii_lowercase().as_str(), "0" | "false" | "off" | "no")
        }
        _ => true,
    }
}

fn projection_fill_cooldown_secs() -> i64 {
    std::env::var("VAAK_NOTIF_PROJECTION_FILL_COOLDOWN_SECS")
        .ok()
        .and_then(|s| s.parse().ok())
        .unwrap_or(180)
        .clamp(60, 1800)
}

fn projection_fill_min_rows() -> i64 {
    std::env::var("VAAK_NOTIF_PROJECTION_FILL_MIN_ROWS")
        .ok()
        .and_then(|s| s.parse().ok())
        .unwrap_or(40)
        .clamp(1, 80)
}

fn projection_fill_paths() -> (PathBuf, PathBuf) {
    let php_bin = PathBuf::from(
        std::env::var("VAAK_PHP_BIN").unwrap_or_else(|_| "/usr/bin/php".to_string()),
    );
    let api_root = PathBuf::from(
        std::env::var("VAAK_API_ROOT").unwrap_or_else(|_| "/srv/mkultra/html/api".to_string()),
    );
    (php_bin, api_root.join("bin/notif-projection-fill.php"))
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

fn projection_cutoff() -> String {
    // Match PHP gmdate('c') / stored updated_at (`…+00:00`), not `…Z` — text compare.
    (Utc::now() - ChronoDuration::seconds(900))
        .format("%Y-%m-%dT%H:%M:%S+00:00")
        .to_string()
}

async fn projection_recent_count(db: &Client, owner_user_id: i64) -> Result<i64> {
    let cutoff = projection_cutoff();
    let row = db
        .query_one(
            "SELECT COUNT(*)::bigint FROM ap_notification_projection
             WHERE owner_user_id = $1 AND updated_at >= $2",
            &[&owner_user_id, &cutoff],
        )
        .await
        .context("projection count")?;
    Ok(row.get::<_, i64>(0))
}

/// Spawn temporary PHP hydrate to fill `ap_notification_projection` for an owner.
/// Rate-limited via Redis so a failing fill cannot stampede every loop tick.
async fn maybe_fill_projection(
    redis: &mut redis::aio::MultiplexedConnection,
    owner_user_id: i64,
    limit: i64,
    force: bool,
) -> Result<Option<String>> {
    if !projection_fill_enabled() {
        return Ok(None);
    }
    let cooldown_key = format!("vaak:notif:proj-fill:cd:{owner_user_id}");
    let lock_name = format!("notif-proj-fill:{owner_user_id}");
    let holder = format!("vaak-worker-{}", std::process::id());
    let cooldown = projection_fill_cooldown_secs();

    let cooling: Option<String> = redis::cmd("GET")
        .arg(&cooldown_key)
        .query_async(redis)
        .await
        .unwrap_or(None);
    if cooling.is_some() && !force {
        return Ok(Some(format!(
            "owner={owner_user_id} fill=skip_cooldown cooldown={cooldown}"
        )));
    }

    if !redis_util::lock(redis, &lock_name, 120, &holder).await? {
        return Ok(Some(format!("owner={owner_user_id} fill=skip_locked")));
    }

    let (php_bin, script) = projection_fill_paths();
    if !script.is_file() {
        let _ = redis_util::unlock(redis, &lock_name, &holder).await;
        bail!("notif-projection-fill missing: {}", script.display());
    }

    let t0 = Instant::now();
    let mut cmd = Command::new(&php_bin);
    cmd.arg(&script)
        .arg(format!("--owner-id={owner_user_id}"))
        .arg(format!("--limit={}", limit.clamp(40, 80)))
        .env("VAAK_NOTIF_LIST_WARM", "1")
        .env("AP_DB_DSN", "pgsql:dbname=novalandia")
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .kill_on_drop(true);
    if let Ok(dsn) = std::env::var("AP_DB_DSN") {
        cmd.env("AP_DB_DSN", dsn);
    }

    let output = cmd
        .output()
        .await
        .with_context(|| format!("spawn {} {}", php_bin.display(), script.display()));
    let _ = redis_util::unlock(redis, &lock_name, &holder).await;
    // Always arm cooldown after an attempt so failures cannot loop every tick.
    let _: Result<(), _> = redis::cmd("SET")
        .arg(&cooldown_key)
        .arg("1")
        .arg("EX")
        .arg(cooldown)
        .query_async(redis)
        .await;

    let output = output?;
    let ms = t0.elapsed().as_millis();
    let stdout = String::from_utf8_lossy(&output.stdout);
    let stderr = String::from_utf8_lossy(&output.stderr);
    if !output.status.success() {
        tracing::warn!(
            owner = owner_user_id,
            ms,
            status = ?output.status.code(),
            %stderr,
            "notif projection fill failed"
        );
        return Ok(Some(format!(
            "owner={owner_user_id} fill=fail ms={ms} status={}",
            output.status.code().unwrap_or(-1)
        )));
    }
    tracing::info!(owner = owner_user_id, ms, %stdout, "notif projection fill ok");
    Ok(Some(format!(
        "owner={owner_user_id} fill=ok ms={ms} bytes={}",
        stdout.len()
    )))
}

/// Projection provenance is private: reject legacy bodies before accepting a window.
fn projection_item(mut item: Value) -> Option<Value> {
    if item.get("_vaak_reply_preview_version").and_then(Value::as_i64) != Some(3) {
        return None;
    }
    item.as_object_mut()?.remove("_vaak_reply_preview_version");
    Some(item)
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
    let cutoff = projection_cutoff();
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
    let mut validated = Vec::with_capacity(rows.len());
    for row in rows {
        let raw: String = row.get(0);
        let Ok(item) = serde_json::from_str::<Value>(&raw) else {
            return Ok(None);
        };
        let Some(item) = projection_item(item) else { return Ok(None) };
        validated.push(item);
    }
    let mut out: Vec<Value> = Vec::new();
    for item in validated {
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
    // Thin/empty window (incl. scanned=0): soft-skip unless fill bridge ran.
    Ok(None)
}

/// PHP `ap_notification_list_epoch`: bumped by every list invalidation. `None`
/// when Redis cannot answer, which disables the guard rather than the warm.
async fn list_epoch(redis: &mut redis::aio::MultiplexedConnection, owner_user_id: i64) -> Option<String> {
    let keys = [
        "vaak:notifications:epoch:v1:server".to_string(),
        format!("vaak:notifications:epoch:v1:{owner_user_id}"),
    ];
    let vals: Vec<Option<String>> = redis::cmd("MGET").arg(&keys).query_async(redis).await.ok()?;
    let part = |i: usize| vals.get(i).cloned().flatten().unwrap_or_else(|| "0".into());
    Some(format!("{}:{}", part(0), part(1)))
}

/// Newest-first snowflake order (length, then lexicographic), as PHP compares ids.
fn snowflake_newer(a: &str, b: &str) -> bool {
    a.len() > b.len() || (a.len() == b.len() && a > b)
}

/// The badge's newest notification when a healthy-looking projection lacks it
/// and would paint an older head. That projection was written by a hydrate
/// that started before the notification arrived, and it stays "recent" for
/// 15 minutes, so the list and the badge disagree until it ages out.
async fn projection_missing_badge_head(db: &Client, owner_user_id: i64) -> Result<Option<String>> {
    let state = notif::compute_unread(db, owner_user_id, 80).await?;
    let head = state.latest_id;
    if head.is_empty() || head == "0" {
        return Ok(None);
    }
    let rows = db
        .query(
            "SELECT notification_id FROM ap_notification_projection
             WHERE owner_user_id = $1 AND updated_at >= $2",
            &[&owner_user_id, &projection_cutoff()],
        )
        .await
        .context("projection ids")?;
    let ids: Vec<String> = rows
        .iter()
        .map(|r| r.get::<_, String>(0).chars().filter(|c| c.is_ascii_digit()).collect())
        .collect();
    Ok(projection_lacks_head(&head, &ids).then_some(head))
}

fn projection_lacks_head(head: &str, ids: &[String]) -> bool {
    !ids.is_empty()
        && !ids.iter().any(|id| id == head)
        && ids.iter().all(|id| snowflake_newer(head, id))
}

async fn write_list_envelope(
    redis: &mut redis::aio::MultiplexedConnection,
    key: &str,
    items: Vec<Value>,
) -> Result<()> {
    // Carry the newest notification id alongside the list. The unread badge
    // uses this watermark to avoid announcing a row before the list cache can
    // serve it, keeping badge and notification arrival ordered.
    let latest_id = items
        .iter()
        .filter_map(|item| item.get("id").and_then(|v| v.as_str()))
        .filter(|id| id.chars().all(|c| c.is_ascii_digit()))
        .max_by(|a, b| {
            a.len()
                .cmp(&b.len())
                .then_with(|| a.cmp(b))
        })
        .unwrap_or("");
    let payload = json!({
        "ts": Utc::now().timestamp(),
        "latest_id": latest_id,
        "items": items,
        "source": "vaak-worker-projection",
    });
    redis_util::json_set(redis, key, &payload, 600).await
}

/// Warm one owner: projection→Redis; fill thin projection via PHP bridge when needed.
pub async fn warm_owner(cfg: &Config, owner_user_id: i64, limit: i64) -> Result<String> {
    let limit = limit.clamp(1, 80);
    let started = Instant::now();
    let native = env_flag_default_true("VAAK_NOTIF_NATIVE_PROJECTION");
    let refresh = refresh_secs();

    if !native {
        return Ok(format!(
            "owner={owner_user_id} ms={} mode=skipped flag=VAAK_NOTIF_NATIVE_PROJECTION=0 php_bridge=retired",
            started.elapsed().as_millis()
        ));
    }

    let db = crate::db::connect(&cfg.database_url).await?;
    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let mut lines: Vec<String> = Vec::new();
    let mut php_fill: u32 = 0;

    // Before any projection read: a list invalidation after this point means
    // what we read may predate the newest notification, so do not publish it.
    let epoch = list_epoch(&mut redis, owner_user_id).await;

    // 0.6.66: if recent projection is thin, bootstrap from local PG via PHP once
    // (cooldown-gated), then continue with native Redis materialize.
    let proj_count = projection_recent_count(&db, owner_user_id).await.unwrap_or(0);
    let min_rows = projection_fill_min_rows();

    // A full projection missing the badge's newest notification is stale (the
    // badge shows a follow the list cannot). Refill once per head id, past the
    // cooldown, and rebuild every page instead of keeping "fresh" stale ones.
    let mut heal = false;
    if proj_count >= min_rows {
        match projection_missing_badge_head(&db, owner_user_id).await {
            Ok(Some(head)) => {
                let heal_key = format!("vaak:notif:proj-heal:{owner_user_id}");
                let prior: Option<String> = redis::cmd("GET")
                    .arg(&heal_key)
                    .query_async(&mut redis)
                    .await
                    .unwrap_or(None);
                if prior.as_deref() != Some(head.as_str()) {
                    let _: Result<(), _> = redis::cmd("SET")
                        .arg(&heal_key)
                        .arg(&head)
                        .arg("EX")
                        .arg(3600)
                        .query_async(&mut redis)
                        .await;
                    heal = true;
                    lines.push(format!("owner={owner_user_id} heal=stale_projection head={head}"));
                }
            }
            Ok(None) => {}
            Err(e) => {
                tracing::warn!(owner = owner_user_id, error = %e, "notif projection head check failed");
            }
        }
    }

    if proj_count < min_rows || heal {
        match maybe_fill_projection(&mut redis, owner_user_id, limit.max(40), heal).await {
            Ok(Some(line)) => {
                if line.contains("fill=ok") {
                    php_fill = 1;
                }
                lines.push(line);
            }
            Ok(None) => {}
            Err(e) => {
                tracing::warn!(owner = owner_user_id, error = %e, "notif projection fill error");
                lines.push(format!("owner={owner_user_id} fill=error err={e}"));
            }
        }
    } else {
        lines.push(format!(
            "owner={owner_user_id} fill=skip_fresh_proj rows={proj_count} min={min_rows}"
        ));
    }

    let jobs = warm_jobs(limit);
    let mut skip_fresh = 0u32;
    let mut native_ok = 0u32;
    let mut skipped_incomplete = 0u32;
    let mut all40_items: Option<Vec<Value>> = None;

    for job in &jobs {
        let want = expand_types(&job.types);
        let key = notifications_list_redis_key(owner_user_id, job.limit, None, None, &want);

        if epoch.is_some() && list_epoch(&mut redis, owner_user_id).await != epoch {
            lines.push(format!(
                "owner={owner_user_id} job={} stop=invalidated_during_warm",
                job.label
            ));
            break;
        }

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
            if age <= refresh && !heal {
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
                if epoch.is_some() && list_epoch(&mut redis, owner_user_id).await != epoch {
                    lines.push(format!(
                        "owner={owner_user_id} job={} stop=invalidated_during_warm",
                        job.label
                    ));
                    break;
                }
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
                // projection instead of leaving Mentions cold every refresh.
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
                // 0.6.63: PHP bridge retired — soft-skip thin/incomplete windows.
                // Mentions HTML/API still hydrate on request-path miss.
                skipped_incomplete += 1;
                lines.push(format!(
                    "owner={owner_user_id} job={} skipped=incomplete ms={}",
                    job.label,
                    t0.elapsed().as_millis()
                ));
            }
        }
    }

    let proj_after = projection_recent_count(&db, owner_user_id)
        .await
        .unwrap_or(proj_count);
    let total_ms = started.elapsed().as_millis();
    let summary = format!(
        "owner={owner_user_id} warm_ok={} skip_fresh={skip_fresh} native={native_ok} php={php_fill} skipped_incomplete={skipped_incomplete} proj_rows={proj_count}->{proj_after} total_ms={total_ms} refresh_secs={refresh}",
        skip_fresh + native_ok,
    );
    let mut out = lines.join("\n");
    if !out.is_empty() {
        out.push('\n');
    }
    out.push_str(&summary);
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
        "vaak:notifications:v4:{owner_user_id}:{}",
        hex::encode(hasher.finalize())
    )
}

struct NotificationEnvelope {
    redis_key: String,
    cache_hit: bool,
    cache_ts: Option<i64>,
    source: String,
    items: Vec<Value>,
}

/// Raw Redis envelope. No audience SQL — callers filter after they know this
/// head can fill the page.
async fn read_notification_envelope(
    redis: &mut redis::aio::MultiplexedConnection,
    owner_user_id: i64,
    limit: i64,
    types: &[String],
    max_id: Option<&str>,
    since_id: Option<&str>,
) -> Result<NotificationEnvelope> {
    let redis_key = notifications_list_redis_key(owner_user_id, limit, max_id, since_id, types);
    let cached = redis_util::json_get(redis, &redis_key).await?;
    if let Some(payload) = cached {
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
        return Ok(NotificationEnvelope {
            redis_key,
            cache_hit: true,
            cache_ts: payload.get("ts").and_then(|v| v.as_i64()),
            source,
            items,
        });
    }
    Ok(NotificationEnvelope {
        redis_key,
        cache_hit: false,
        cache_ts: None,
        source: "miss".into(),
        items: vec![],
    })
}

fn hit_report(
    owner_user_id: i64,
    limit: i64,
    types: Vec<String>,
    env: &NotificationEnvelope,
    now: i64,
    items: Vec<Value>,
) -> NotificationsShadowReport {
    NotificationsShadowReport {
        owner_user_id,
        limit,
        types,
        redis_key: env.redis_key.clone(),
        cache_hit: true,
        cache_ts: env.cache_ts,
        cache_age_secs: env.cache_ts.map(|t| (now - t).max(0)),
        source: if env.source.is_empty() {
            "redis".into()
        } else {
            env.source.clone()
        },
        count: items.len(),
        items,
        mode: "shadow",
        note: "Axum reads warm Redis list only; PHP remains live /api/v1/notifications until cutover.",
    }
}

fn miss_report(
    owner_user_id: i64,
    limit: i64,
    types: Vec<String>,
    redis_key: String,
) -> NotificationsShadowReport {
    NotificationsShadowReport {
        owner_user_id,
        limit,
        types,
        redis_key,
        cache_hit: false,
        cache_ts: None,
        cache_age_secs: None,
        source: "miss".into(),
        count: 0,
        items: vec![],
        mode: "shadow",
        note: "Cache miss — run notif-list warm or open Mentions in PHP; shadow does not hydrate.",
    }
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
    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let env = read_notification_envelope(
        &mut redis,
        owner_user_id,
        limit,
        &want,
        max_id,
        since_id,
    )
    .await?;
    let now = chrono::Utc::now().timestamp();
    if env.cache_hit {
        let mut items = env.items.clone();
        let db = crate::db::connect(&cfg.database_url).await?;
        crate::audience::filter_notifications(&db, owner_user_id, &mut items).await?;
        return Ok(hit_report(owner_user_id, limit, want, &env, now, items));
    }
    Ok(miss_report(owner_user_id, limit, want, env.redis_key))
}

pub(crate) fn notif_id_digits(item: &Value) -> String {
    match item.get("id") {
        Some(Value::String(s)) => s.chars().filter(|c| c.is_ascii_digit()).collect(),
        Some(Value::Number(n)) => n.to_string(),
        _ => String::new(),
    }
}

/// Newest-first rows strictly after the cursor row.
/// `None` when that id is not in the envelope.
pub(crate) fn items_after_cursor(items: &[Value], cursor_digits: &str) -> Option<Vec<Value>> {
    if cursor_digits.is_empty() {
        return None;
    }
    let mut seen = false;
    let mut tail = Vec::new();
    for item in items {
        if !seen {
            if notif_id_digits(item) == cursor_digits {
                seen = true;
            }
            continue;
        }
        tail.push(item.clone());
    }
    if seen { Some(tail) } else { None }
}

/// Full pages only. A shorter tail is a miss: the warm head may have ended
/// while older notifications still exist, and a short 200 would stop Ice Cubes.
pub(crate) fn full_page_after_cursor(
    items: &[Value],
    cursor_digits: &str,
    limit: i64,
) -> Option<Vec<Value>> {
    let tail = items_after_cursor(items, cursor_digits)?;
    if (tail.len() as i64) < limit.clamp(1, 80) {
        return None;
    }
    Some(tail)
}

/// Deepest warm head first. notif-list writes all40, then all30, plus filtered
/// lists at the worker limit.
pub(crate) fn warm_head_limits(limit: i64) -> Vec<i64> {
    let mut limits = vec![40_i64, 30, limit.clamp(1, 80), 20];
    limits.sort_by(|a, b| b.cmp(a));
    limits.dedup();
    limits
}

fn cursor_digits(raw: Option<&str>) -> Option<String> {
    let digits: String = raw
        .unwrap_or("")
        .chars()
        .filter(|c| c.is_ascii_digit())
        .collect();
    if digits.is_empty() { None } else { Some(digits) }
}

fn tag_source(source: &str, suffix: &str) -> String {
    let base = if source.is_empty() { "redis" } else { source };
    format!("{base}:{suffix}")
}

fn truncate_page(report: &mut NotificationsShadowReport, limit: i64) {
    let limit = limit.clamp(1, 80) as usize;
    if report.items.len() > limit {
        report.items.truncate(limit);
        report.count = report.items.len();
    }
}

/// One warm Redis head, still unfiltered.
pub(crate) struct WarmHead {
    pub limit: i64,
    pub items: Vec<Value>,
}

/// Which single head to filter, and how to slice it afterwards.
pub(crate) struct PagePlan {
    pub index: usize,
    pub suffix: &'static str,
    pub cursor: Option<String>,
    pub require_full: bool,
}

/// Pick at most one warm head. Raw selection does no audience SQL.
///
/// `max_id`: largest head that contains the cursor. A short raw tail is a miss
/// immediately — smaller heads are prefixes of the same list. No cursor: the
/// first head whose limit differs from the request and whose raw page is long
/// enough. A later head is not a fallback when this one filters short.
pub(crate) fn plan_warm_heads(
    heads: &[WarmHead],
    requested: i64,
    max_id: Option<&str>,
) -> Option<PagePlan> {
    let requested = requested.clamp(1, 80);
    if let Some(cursor) = cursor_digits(max_id) {
        for (index, head) in heads.iter().enumerate() {
            if head.items.is_empty() {
                continue;
            }
            match items_after_cursor(&head.items, &cursor) {
                None => continue,
                Some(tail) if (tail.len() as i64) < requested => return None,
                Some(_) => {
                    return Some(PagePlan {
                        index,
                        suffix: "max-slice",
                        cursor: Some(cursor),
                        require_full: true,
                    });
                }
            }
        }
        return None;
    }
    for (index, head) in heads.iter().enumerate() {
        if head.limit == requested {
            continue;
        }
        if head.items.len() >= requested as usize {
            return Some(PagePlan {
                index,
                suffix: "head-slice",
                cursor: None,
                require_full: true,
            });
        }
    }
    None
}

/// Apply a filtered head to the plan. A removed cursor or a short page is a
/// miss so Ice Cubes does not stop pagination on the warm cap.
pub(crate) fn page_after_filter(
    items: &[Value],
    plan: &PagePlan,
    limit: i64,
) -> Option<Vec<Value>> {
    let limit = limit.clamp(1, 80);
    let mut page = if let Some(cursor) = plan.cursor.as_deref() {
        let tail = items_after_cursor(items, cursor)?;
        if plan.require_full && (tail.len() as i64) < limit {
            return None;
        }
        tail
    } else {
        if plan.require_full && (items.len() as i64) < limit {
            return None;
        }
        items.to_vec()
    };
    let cap = limit as usize;
    if page.len() > cap {
        page.truncate(cap);
    }
    Some(page)
}

/// Exact warm key, then one filtered slice of a raw warm head.
///
/// `since_id` / `min_id` pulls stay a miss so pull-to-refresh still reads PHP.
/// A short scroll tail stays a miss so a client does not treat the warm cap as
/// the end of the notifications list. Audience SQL runs on the exact key, or
/// on the single head `plan_warm_heads` selects — not on every probed head.
pub async fn notifications_shadow_resolved(
    cfg: &Config,
    owner_user_id: i64,
    limit: i64,
    types: &[String],
    max_id: Option<&str>,
    since_id: Option<&str>,
) -> Result<NotificationsShadowReport> {
    let limit = limit.clamp(1, 80);
    let mut report =
        notifications_shadow(cfg, owner_user_id, limit, types, max_id, since_id).await?;
    if report.cache_hit {
        truncate_page(&mut report, limit);
        return Ok(report);
    }
    // Newer-than cursor is not a slice of the head.
    if cursor_digits(since_id).is_some() {
        return Ok(report);
    }
    let want = expand_types(types);
    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let mut heads: Vec<(NotificationEnvelope, i64)> = Vec::new();
    let mut planned = Vec::new();
    for alt in warm_head_limits(limit) {
        if cursor_digits(max_id).is_none() && alt == limit {
            continue;
        }
        let env =
            read_notification_envelope(&mut redis, owner_user_id, alt, &want, None, None).await?;
        if !env.cache_hit {
            continue;
        }
        planned.push(WarmHead {
            limit: alt,
            items: env.items.clone(),
        });
        heads.push((env, alt));
    }
    let Some(plan) = plan_warm_heads(&planned, limit, max_id) else {
        return Ok(report);
    };
    let (env, head_limit) = &heads[plan.index];
    let mut items = planned[plan.index].items.clone();
    let db = crate::db::connect(&cfg.database_url).await?;
    crate::audience::filter_notifications(&db, owner_user_id, &mut items).await?;
    let Some(page) = page_after_filter(&items, &plan, limit) else {
        return Ok(report);
    };
    let now = chrono::Utc::now().timestamp();
    let mut sliced = hit_report(owner_user_id, *head_limit, want, env, now, page);
    sliced.source = tag_source(&sliced.source, plan.suffix);
    Ok(sliced)
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

#[cfg(test)]
mod reply_preview_projection_regression {
    #[test]
    fn projection_requires_integer_provenance_and_preserves_authored_html() {
        let original = serde_json::json!({"id":"1", "status":{"content":"<p>↩ Authored arrow</p><p>Authored continuation</p>"}});
        assert!(super::projection_item(original.clone()).is_none());
        for invalid in [serde_json::json!("3"), serde_json::json!(2), serde_json::json!(1), serde_json::json!(3.0), serde_json::json!(null)] {
            let mut item = original.clone(); item["_vaak_reply_preview_version"] = invalid;
            assert!(super::projection_item(item).is_none());
        }
        let mut fresh = original.clone(); fresh["_vaak_reply_preview_version"] = serde_json::json!(3);
        assert_eq!(super::projection_item(fresh), Some(original));
    }
}

#[cfg(test)]
mod stale_projection_head_tests {
    use super::projection_lacks_head;

    fn ids(v: &[&str]) -> Vec<String> {
        v.iter().map(|s| s.to_string()).collect()
    }

    #[test]
    fn newer_badge_head_missing_from_projection_is_stale() {
        // Follow (type 6) landed after the projection's newest like.
        let proj = ids(&["1792000000500000042", "1791999990500000041"]);
        assert!(projection_lacks_head("1792000100600000007", &proj));
    }

    #[test]
    fn head_already_in_projection_or_older_is_not_stale() {
        let proj = ids(&["1792000100600000007", "1792000000500000042"]);
        assert!(!projection_lacks_head("1792000100600000007", &proj));
        // Older than the projection head: filtered by the list on purpose.
        assert!(!projection_lacks_head("1791000000500000001", &proj));
        // Shorter id compares older, not lexicographically.
        assert!(!projection_lacks_head("999999999", &proj));
        // Empty projection is the thin-fill path, not a heal.
        assert!(!projection_lacks_head("1792000100600000007", &[]));
    }
}

#[cfg(test)]
mod warm_page_plan_tests {
    use super::{
        full_page_after_cursor, items_after_cursor, notif_id_digits, page_after_filter,
        plan_warm_heads, PagePlan, WarmHead,
    };
    use serde_json::{json, Value};
    use std::collections::HashSet;

    fn item(id: i64) -> Value {
        json!({"id": id.to_string(), "type": "favourite"})
    }

    fn head(limit: i64, ids: &[i64]) -> WarmHead {
        WarmHead {
            limit,
            items: ids.iter().copied().map(item).collect(),
        }
    }

    fn ids(items: &[Value]) -> Vec<String> {
        items.iter().map(notif_id_digits).collect()
    }

    fn drop_ids(items: &[Value], drop: &HashSet<i64>) -> Vec<Value> {
        items
            .iter()
            .filter(|it| match notif_id_digits(it).parse::<i64>() {
                Ok(n) => !drop.contains(&n),
                Err(_) => true,
            })
            .cloned()
            .collect()
    }

    /// Previous resolver: filter every probed head until one fills the page.
    fn old_filtered_page(
        heads: &[WarmHead],
        requested: i64,
        max_id: Option<&str>,
        drop: &HashSet<i64>,
    ) -> Option<(usize, Vec<String>)> {
        let mut filters = 0usize;
        if let Some(cursor) = max_id {
            for head in heads {
                filters += 1;
                let filtered = drop_ids(&head.items, drop);
                if filtered.is_empty() {
                    continue;
                }
                if let Some(mut tail) = full_page_after_cursor(&filtered, cursor, requested) {
                    let cap = requested.clamp(1, 80) as usize;
                    if tail.len() > cap {
                        tail.truncate(cap);
                    }
                    return Some((filters, ids(&tail)));
                }
                if items_after_cursor(&filtered, cursor).is_some() {
                    return None;
                }
            }
            return None;
        }
        for head in heads {
            if head.limit == requested {
                continue;
            }
            filters += 1;
            let filtered = drop_ids(&head.items, drop);
            if (filtered.len() as i64) >= requested {
                let mut page = filtered;
                page.truncate(requested.clamp(1, 80) as usize);
                return Some((filters, ids(&page)));
            }
        }
        let _ = filters;
        None
    }

    fn new_filtered_page(
        heads: &[WarmHead],
        requested: i64,
        max_id: Option<&str>,
        drop: &HashSet<i64>,
    ) -> Option<(usize, Vec<String>)> {
        let plan: PagePlan = plan_warm_heads(heads, requested, max_id)?;
        let filtered = drop_ids(&heads[plan.index].items, drop);
        let page = page_after_filter(&filtered, &plan, requested)?;
        Some((1, ids(&page)))
    }

    #[test]
    fn prefix_heads_keep_the_same_page_with_one_filter() {
        let all: Vec<i64> = (1..=40).rev().collect();
        let heads = vec![
            head(40, &all),
            head(30, &all[..30]),
            head(20, &all[..20]),
            head(12, &all[..12]),
        ];
        let drop = HashSet::from([39_i64, 37, 11]);
        let old = old_filtered_page(&heads, 12, Some("30"), &drop).expect("old page");
        let new = new_filtered_page(&heads, 12, Some("30"), &drop).expect("new page");
        assert_eq!(new.1, old.1);
        assert_eq!(new.0, 1);
        assert!(old.0 >= 1);
        let plan = plan_warm_heads(&heads, 12, Some("30")).unwrap();
        assert_eq!(plan.index, 0);
        assert_eq!(plan.suffix, "max-slice");
    }

    #[test]
    fn probed_heads_that_lack_the_cursor_are_not_filtered() {
        let heads = vec![
            head(40, &(51..=90).rev().collect::<Vec<_>>()),
            head(30, &(21..=50).rev().collect::<Vec<_>>()),
            head(20, &(1..=20).rev().collect::<Vec<_>>()),
            head(12, &(1..=12).rev().collect::<Vec<_>>()),
        ];
        let drop = HashSet::new();
        let old = old_filtered_page(&heads, 3, Some("25"), &drop).unwrap();
        let new = new_filtered_page(&heads, 3, Some("25"), &drop).unwrap();
        assert_eq!(new.1, old.1);
        assert_eq!(old.0, 2, "the old scan filtered the head that missed the cursor");
        assert_eq!(new.0, 1);
        assert_eq!(plan_warm_heads(&heads, 3, Some("25")).unwrap().index, 1);
        assert_eq!(new.1, vec!["24", "23", "22"]);
    }

    #[test]
    fn short_filtered_tail_stays_a_miss() {
        let heads = vec![head(40, &(1..=40).rev().collect::<Vec<_>>())];
        let drop: HashSet<i64> = (1..=20).collect();
        let plan = plan_warm_heads(&heads, 12, Some("30")).unwrap();
        let filtered = drop_ids(&heads[0].items, &drop);
        assert!(page_after_filter(&filtered, &plan, 12).is_none());
        assert!(old_filtered_page(&heads, 12, Some("30"), &drop).is_none());
        assert!(new_filtered_page(&heads, 12, Some("30"), &drop).is_none());
    }

    #[test]
    fn removing_the_cursor_misses_instead_of_reading_the_next_head() {
        let heads = vec![
            head(40, &(1..=40).rev().collect::<Vec<_>>()),
            head(20, &(16..=35).rev().collect::<Vec<_>>()),
        ];
        let plan = plan_warm_heads(&heads, 5, Some("30")).unwrap();
        assert_eq!(plan.index, 0);
        let filtered: Vec<Value> = heads[0]
            .items
            .iter()
            .filter(|it| notif_id_digits(it) != "30")
            .cloned()
            .collect();
        assert!(page_after_filter(&filtered, &plan, 5).is_none());
        let older = full_page_after_cursor(&heads[1].items, "30", 5).unwrap();
        assert_eq!(
            ids(&older[..5]),
            vec!["29", "28", "27", "26", "25"]
        );
    }

    #[test]
    fn head_slice_does_not_fall_through_a_short_filter() {
        let heads = vec![
            head(40, &(1..=40).rev().collect::<Vec<_>>()),
            head(20, &(100..=119).rev().collect::<Vec<_>>()),
        ];
        let drop: HashSet<i64> = (1..=30).collect();
        assert!(new_filtered_page(&heads, 12, None, &drop).is_none());
        let old = old_filtered_page(&heads, 12, None, &drop).unwrap();
        assert_eq!(old.0, 2);
        assert_eq!(old.1.len(), 12);
    }

    #[test]
    fn exact_short_raw_tail_does_not_scan_a_smaller_prefix() {
        let all: Vec<i64> = (1..=40).rev().collect();
        let heads = vec![head(40, &all), head(30, &all[..30])];
        assert!(plan_warm_heads(&heads, 12, Some("10")).is_none());
        assert!(new_filtered_page(&heads, 12, Some("10"), &HashSet::new()).is_none());
        assert!(old_filtered_page(&heads, 12, Some("10"), &HashSet::new()).is_none());
    }
}
