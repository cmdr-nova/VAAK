//! Shadow Home timeline: ranked ID cache + hydrate-assisted Mastodon JSON.
//!
//! - `/shadow/timelines/home` — ranked ID report (diagnostic).
//! - `/api/v1/timelines/home` — Mastodon status array from PHP
//!   `vaak:timeline:v1:{sha256}` envelope (same key Ice Cubes already writes).

use anyhow::{Context, Result};
use serde::Serialize;
use serde_json::Value;
use sha2::{Digest, Sha256};

use crate::config::Config;
use crate::redis_util;

const HOME_PATH: &str = "/api/v1/timelines/home";
const PUBLIC_PATH: &str = "/api/v1/timelines/public";
/// Match PHP Home Redis TTL (0.6.67: 300s). Ice Cubes `cache_try` still uses 45s
/// for client freshness; Axum serves any present envelope until Redis expiry.
const HOME_TL_FRESH_SECS: i64 = 300;

#[derive(Debug, Serialize)]
pub struct HomeShadowReport {
    pub owner_user_id: i64,
    pub limit: usize,
    pub cache_key_logical: Option<String>,
    pub cache_key_redis: Option<String>,
    pub cache_ts: Option<i64>,
    pub ranked_total: usize,
    pub items: Vec<HomeShadowItem>,
    /// Hydrate envelope (`vaak:timeline:v1:*`) beside ranked IDs.
    pub hydrate: HomeHydrateInfo,
    pub source: &'static str,
    pub note: &'static str,
}

#[derive(Debug, Serialize)]
pub struct HomeHydrateInfo {
    pub redis_key: String,
    pub cache_hit: bool,
    pub age_secs: Option<i64>,
    pub fresh: bool,
    pub n_statuses: usize,
    pub first_id: Option<String>,
    pub note: &'static str,
}

#[derive(Debug, Serialize)]
pub struct HomeHydrateReport {
    pub owner_user_id: i64,
    pub limit: usize,
    pub since_id: Option<String>,
    pub redis_key: String,
    pub cache_hit: bool,
    pub age_secs: Option<i64>,
    pub fresh: bool,
    pub n: usize,
    pub items: Vec<Value>,
    pub link: Option<String>,
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

fn since_id_json(since_id: Option<&str>) -> String {
    match since_id.map(str::trim).filter(|s| !s.is_empty()) {
        Some(s) => {
            // Mirror PHP JSON_UNESCAPED_SLASHES string encoding for ids.
            let escaped = s.replace('\\', "\\\\").replace('"', "\\\"");
            format!("\"{escaped}\"")
        }
        None => "null".to_string(),
    }
}

/// PHP `ap_masto_timeline_cache_key` parity for Home head polls.
///
/// PHP `json_encode` keeps insertion order `u,p,l,s,x`. Default `serde_json::Map`
/// is a BTreeMap (sorted keys), so we format the payload string manually.
pub fn home_timeline_redis_key(owner: i64, limit: i64, since_id: Option<&str>) -> String {
    let s_json = since_id_json(since_id);
    let raw = format!(
        r#"{{"u":{owner},"p":"{HOME_PATH}","l":{limit},"s":{s_json},"x":[]}}"#
    );
    let mut hasher = Sha256::new();
    hasher.update(raw.as_bytes());
    format!("vaak:timeline:v1:{}", hex::encode(hasher.finalize()))
}

/// PHP `ap_masto_timeline_cache_key` for Local (`local=true`) / Federated public.
///
/// Local: `x={"local":"true"}` (PHP assoc array → JSON object).
/// Federated: `x=[]`.
pub fn public_timeline_redis_key(
    owner: i64,
    limit: i64,
    local: bool,
    since_id: Option<&str>,
) -> String {
    let s_json = since_id_json(since_id);
    let x_json = if local {
        r#"{"local":"true"}"#
    } else {
        "[]"
    };
    let raw = format!(
        r#"{{"u":{owner},"p":"{PUBLIC_PATH}","l":{limit},"s":{s_json},"x":{x_json}}}"#
    );
    let mut hasher = Sha256::new();
    hasher.update(raw.as_bytes());
    format!("vaak:timeline:v1:{}", hex::encode(hasher.finalize()))
}

/// Hydrate redis key for a timeline view (`home` / `local` / `feed`).
pub fn timeline_hydrate_redis_key(
    view: &str,
    owner: i64,
    limit: i64,
    since_id: Option<&str>,
) -> String {
    match view {
        "local" => public_timeline_redis_key(owner, limit, true, since_id),
        "feed" => public_timeline_redis_key(owner, limit, false, since_id),
        _ => home_timeline_redis_key(owner, limit, since_id),
    }
}

async fn read_hydrate_envelope(
    redis: &mut redis::aio::MultiplexedConnection,
    owner: i64,
    limit: i64,
    since_id: Option<&str>,
    view: &str,
) -> Result<HomeHydrateReport> {
    let limit = limit.clamp(1, 240);
    let redis_key = timeline_hydrate_redis_key(view, owner, limit, since_id);
    let envelope = redis_util::json_get(redis, &redis_key).await?;
    let source = match view {
        "local" => "vaak-worker-local-hydrate",
        "feed" => "vaak-worker-feed-hydrate",
        _ => "vaak-worker-home-hydrate",
    };
    let note = match view {
        "local" => "Mastodon Local JSON from vaak:timeline:v1 public?local=true envelope.",
        "feed" => "Mastodon Federated JSON from vaak:timeline:v1 public envelope.",
        _ => "Mastodon Home JSON from PHP vaak:timeline:v1 envelope; PHP remains Home owner.",
    };
    let mut report = HomeHydrateReport {
        owner_user_id: owner,
        limit: limit as usize,
        since_id: since_id
            .map(str::trim)
            .filter(|s| !s.is_empty())
            .map(str::to_string),
        redis_key,
        cache_hit: false,
        age_secs: None,
        fresh: false,
        n: 0,
        items: Vec::new(),
        link: None,
        source,
        note,
    };
    let Some(env) = envelope else {
        report.note = "hydrate cache miss (run bin/home-timeline-warm.php or wait for Ice Cubes head poll)";
        return Ok(report);
    };
    let created = env.get("created_at").and_then(|v| v.as_i64()).unwrap_or(0);
    let age = if created > 0 {
        Some(chrono::Utc::now().timestamp() - created)
    } else {
        None
    };
    report.age_secs = age;
    report.fresh = age.map(|a| a >= 0 && a < HOME_TL_FRESH_SECS).unwrap_or(false);
    report.link = env
        .get("link")
        .and_then(|v| v.as_str())
        .filter(|s| !s.is_empty())
        .map(str::to_string);

    let body = env.get("body").and_then(|v| v.as_str()).unwrap_or("");
    if body.is_empty() {
        report.note = "hydrate envelope present but body empty";
        return Ok(report);
    }
    let decoded: Value = match serde_json::from_str(body) {
        Ok(v) => v,
        Err(_) => {
            report.note = "hydrate body JSON decode failed";
            return Ok(report);
        }
    };
    let items = match decoded.as_array() {
        Some(arr) => arr.clone(),
        None => {
            report.note = "hydrate body is not a JSON array";
            return Ok(report);
        }
    };
    report.cache_hit = true;
    report.n = items.len();
    report.items = items;
    if report.fresh {
        report.note = "hydrate cache hit (fresh)";
    } else {
        report.note = "hydrate cache hit (stale vs freshness window; still served for shadow)";
    }
    Ok(report)
}

async fn read_home_hydrate(
    redis: &mut redis::aio::MultiplexedConnection,
    owner: i64,
    limit: i64,
    since_id: Option<&str>,
) -> Result<HomeHydrateReport> {
    read_hydrate_envelope(redis, owner, limit, since_id, "home").await
}

/// Warm envelope sizes we routinely write (Home HTML + Ice Cubes).
/// 50 = Ice Cubes (0.7.21); 160/240 = deep HTML scroll (0.7.25).
const HYDRATE_WARM_LIMITS: [i64; 6] = [15, 40, 50, 80, 160, 240];

/// Mastodon-shaped statuses from hydrate Redis for `home` / `local` / `feed`.
///
/// Exact-limit miss (head poll, no since_id): try a larger warm envelope and
/// slice — Ice Cubes `limit=50` must not fall through to a multi-second PHP
/// cold merge when an 80-head is already warm.
pub async fn view_hydrate(
    cfg: &Config,
    owner_user_id: i64,
    view: &str,
    limit: usize,
    since_id: Option<&str>,
) -> Result<HomeHydrateReport> {
    let view = match view {
        "local" | "feed" => view,
        _ => "home",
    };
    let limit = limit.clamp(1, 240);
    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let mut report =
        read_hydrate_envelope(&mut redis, owner_user_id, limit as i64, since_id, view).await?;

    // Head-only fallback: larger warm envelope → slice to requested limit.
    if !report.cache_hit && since_id.map(str::trim).filter(|s| !s.is_empty()).is_none() {
        let mut alts: Vec<i64> = HYDRATE_WARM_LIMITS
            .iter()
            .copied()
            .filter(|n| *n > limit as i64)
            .collect();
        // Prefer smallest covering head first.
        alts.sort_unstable();
        for alt in alts {
            let alt_report =
                read_hydrate_envelope(&mut redis, owner_user_id, alt, None, view).await?;
            if alt_report.cache_hit && !alt_report.items.is_empty() {
                let mut sliced = alt_report;
                if sliced.items.len() > limit {
                    sliced.items.truncate(limit);
                }
                sliced.limit = limit;
                sliced.n = sliced.items.len();
                sliced.note = "hydrate cache hit via larger warm envelope slice";
                // Re-key report to the requested limit for diagnostics.
                sliced.redis_key =
                    timeline_hydrate_redis_key(view, owner_user_id, limit as i64, None);
                report = sliced;
                break;
            }
        }
    }

    // Overlay live viewer flags — warm envelopes hard-code false (0.7.19).
    if report.cache_hit && !report.items.is_empty() {
        // Moderation changes must invalidate stale cached envelopes at read
        // time, not only when the fan-out worker happens to rewarm them.
        let moderation_db = crate::db::connect(&cfg.database_url).await?;
        let hidden = crate::hidden::load_hidden_sets(&moderation_db, owner_user_id)
            .await
            .unwrap_or_default();
        report.items.retain(|status| !crate::hidden::status_hidden(status, &hidden));
        report.n = report.items.len();
        let _ = crate::interaction_flags::apply_to_statuses_with_cfg(
            &cfg.database_url,
            owner_user_id,
            &mut report.items,
        )
        .await;
    }
    Ok(report)
}

/// Mastodon-shaped Home statuses from hydrate Redis (for `/api/v1/timelines/home`).
pub async fn home_hydrate(
    cfg: &Config,
    owner_user_id: i64,
    limit: usize,
    since_id: Option<&str>,
) -> Result<HomeHydrateReport> {
    view_hydrate(cfg, owner_user_id, "home", limit, since_id).await
}

/// Live-poll "newer than since_ts" from ranked head + hydrate envelope.
///
/// Ranked items carry no per-row timestamp; filter hydrate statuses by
/// `created_at` (unix). Requires both ranked Home key and a hydrate hit —
/// miss means PHP should fall back to `admin_tl_fetch_newer`.
#[derive(Debug, Serialize)]
pub struct HomeSinceReport {
    pub owner_user_id: i64,
    pub since_ts: i64,
    pub limit: usize,
    pub cache_hit: bool,
    pub ranked_ok: bool,
    pub hydrate_ok: bool,
    pub n: usize,
    pub newest_ts: i64,
    pub items: Vec<Value>,
    pub ranked_key: Option<String>,
    pub hydrate_key: Option<String>,
    pub source: &'static str,
    pub note: &'static str,
}

fn status_created_ts(st: &Value) -> i64 {
    let raw = st
        .get("created_at")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .trim();
    if raw.is_empty() {
        return 0;
    }
    if let Ok(dt) = chrono::DateTime::parse_from_rfc3339(raw) {
        return dt.timestamp();
    }
    // PHP sometimes emits "YYYY-MM-DDTHH:MM:SS.sssZ" — chrono handles that via RFC3339.
    // Fallback: try without fractional seconds.
    let trimmed = raw.trim_end_matches('Z');
    if let Ok(dt) = chrono::NaiveDateTime::parse_from_str(trimmed, "%Y-%m-%dT%H:%M:%S") {
        return dt.and_utc().timestamp();
    }
    0
}

pub async fn home_since(
    cfg: &Config,
    owner_user_id: i64,
    since_ts: i64,
    limit: usize,
) -> Result<HomeSinceReport> {
    let limit = limit.clamp(1, 40);
    let since_ts = since_ts.max(0);
    let mut report = HomeSinceReport {
        owner_user_id,
        since_ts,
        limit,
        cache_hit: false,
        ranked_ok: false,
        hydrate_ok: false,
        n: 0,
        newest_ts: since_ts,
        items: Vec::new(),
        ranked_key: None,
        hydrate_key: None,
        source: "vaak-worker-home-since",
        note: "ranked+hydrate since filter",
    };
    if since_ts <= 0 {
        report.note = "since_ts required";
        return Ok(report);
    }

    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let index_key = format!("vaak:timeline:owner-index:v1:{owner_user_id}");
    let index = redis_util::json_get(&mut redis, &index_key).await?;
    let Some(logical) = pick_home_logical_key(index.as_ref()) else {
        report.note = "owner ranked index miss (no home logical)";
        return Ok(report);
    };
    let rk = ranked_redis_key(&logical);
    report.ranked_key = Some(rk.clone());
    let ranked_env = redis_util::json_get(&mut redis, &rk).await?;
    let Some(ranked_env) = ranked_env else {
        report.note = "ranked home envelope miss";
        return Ok(report);
    };
    let ranked_n = ranked_env
        .get("ranked")
        .and_then(|v| v.as_array())
        .map(|a| a.len())
        .unwrap_or(0);
    if ranked_n == 0 {
        report.note = "ranked home empty";
        return Ok(report);
    }
    report.ranked_ok = true;

    // Prefer the larger hydrate head (Ice Cubes / warm job writes 15 and 40).
    let mut hydrate = read_home_hydrate(&mut redis, owner_user_id, 40, None).await?;
    if !hydrate.cache_hit {
        hydrate = read_home_hydrate(&mut redis, owner_user_id, 15, None).await?;
    }
    report.hydrate_key = Some(hydrate.redis_key.clone());
    if !hydrate.cache_hit {
        report.note = "hydrate miss (ranked ok; PHP may PG-fallback)";
        return Ok(report);
    }
    report.hydrate_ok = true;
    report.cache_hit = true;

    let mut newer: Vec<(i64, Value)> = Vec::new();
    for st in hydrate.items {
        let ts = status_created_ts(&st);
        if ts <= since_ts {
            continue;
        }
        newer.push((ts, st));
    }
    newer.sort_by(|a, b| b.0.cmp(&a.0));
    if let Some((ts, _)) = newer.first() {
        report.newest_ts = *ts;
    }
    report.items = newer
        .into_iter()
        .take(limit)
        .map(|(_, st)| st)
        .collect();
    report.n = report.items.len();
    if report.n == 0 {
        report.note = "ranked+hydrate hit; nothing newer than since_ts";
    } else {
        report.note = "ranked+hydrate since hit";
        let _ = crate::interaction_flags::apply_to_statuses_with_cfg(
            &cfg.database_url,
            owner_user_id,
            &mut report.items,
        )
        .await;
    }
    Ok(report)
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

    // Probe hydrate for the requested limit; also try 40 (Ice Cubes default head).
    let mut hydrate = read_home_hydrate(&mut redis, owner_user_id, limit as i64, None)
        .await
        .unwrap_or(HomeHydrateReport {
            owner_user_id,
            limit,
            since_id: None,
            redis_key: home_timeline_redis_key(owner_user_id, limit as i64, None),
            cache_hit: false,
            age_secs: None,
            fresh: false,
            n: 0,
            items: Vec::new(),
            link: None,
            source: "vaak-worker-home-hydrate",
            note: "hydrate lookup failed",
        });
    if !hydrate.cache_hit && limit != 40 {
        if let Ok(h40) = read_home_hydrate(&mut redis, owner_user_id, 40, None).await {
            if h40.cache_hit {
                hydrate = h40;
            }
        }
    }

    let mut report = HomeShadowReport {
        owner_user_id,
        limit,
        cache_key_logical: logical,
        cache_key_redis: redis_key,
        cache_ts: None,
        ranked_total: 0,
        items: Vec::new(),
        hydrate: HomeHydrateInfo {
            redis_key: hydrate.redis_key.clone(),
            cache_hit: hydrate.cache_hit,
            age_secs: hydrate.age_secs,
            fresh: hydrate.fresh,
            n_statuses: hydrate.n,
            first_id: hydrate
                .items
                .first()
                .and_then(|s| s.get("id"))
                .map(|v| match v {
                    Value::String(s) => s.clone(),
                    Value::Number(n) => n.to_string(),
                    _ => v.to_string(),
                }),
            note: hydrate.note,
        },
        source: "vaak-worker-shadow",
        note: "Ranked ID cache + hydrate envelope probe. PHP remains Home owner.",
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn home_timeline_key_matches_php_sha() {
        // PHP: hash('sha256', json_encode(['u'=>1,'p'=>'/api/v1/timelines/home','l'=>40,'s'=>null,'x'=>[]], JSON_UNESCAPED_SLASHES))
        let key = home_timeline_redis_key(1, 40, None);
        assert_eq!(
            key,
            "vaak:timeline:v1:1a447a10f787cb14d3ccad2ce2235aabc8f4eceaad3514e89621a9498ce82ae3"
        );
    }

    #[test]
    fn public_local_timeline_key_matches_php_sha() {
        let key = public_timeline_redis_key(1, 40, true, None);
        assert_eq!(
            key,
            "vaak:timeline:v1:a8b35a548a2ff8a676f190ba6a7c86952aef9377121adc0b9f57894a16c8432f"
        );
    }

    #[test]
    fn public_federated_timeline_key_matches_php_sha() {
        let key = public_timeline_redis_key(1, 40, false, None);
        assert_eq!(
            key,
            "vaak:timeline:v1:692c38c6d84ed90c708da63e5da55cd2e67fae9e340487501592a63ea92f15df"
        );
    }

    #[test]
    fn status_created_ts_parses_rfc3339_z() {
        let st = serde_json::json!({"created_at": "2026-10-05T01:57:08.000Z"});
        assert_eq!(status_created_ts(&st), 1791165428);
    }
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

