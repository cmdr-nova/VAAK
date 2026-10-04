//! Home / Local / Federated **ranked** warm (loading-plan slice 2 → native Home).
//!
//! - **Home (native):** rebuilds `vaak:timeline:ranked:v2:{sha256(logical)}` +
//!   owner index from Postgres (follow events, own outbox, Bluesky merge, RSS
//!   spacing). Source `vaak-worker-native`. Flag `VAAK_RANKED_NATIVE_HOME=1`.
//! - **Local / Federated:** still orchestrates PHP `api/bin/ranked-warm.php`
//!   until those views are ported.
//!
//! Cache key parity with PHP `admin_tl_cache_key` (v13).

use std::collections::{HashMap, HashSet};
use std::path::PathBuf;
use std::process::Stdio;
use std::time::{Duration, Instant};

use anyhow::{bail, Context, Result};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use tokio::process::Command;
use tokio_postgres::Client;

use crate::config::Config;
use crate::hidden;
use crate::notif;
use crate::redis_util;

const CACHE_VERSION: &str = "v13";
const HOME_TTL_SECS: u64 = 600;
const MAX_TIMELINE: usize = 160;
const PER_ACTOR_CAP: usize = 25;

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

fn env_flag_default_true(name: &str) -> bool {
    match std::env::var(name) {
        Ok(v) if !v.trim().is_empty() => {
            !matches!(v.trim().to_ascii_lowercase().as_str(), "0" | "false" | "off" | "no")
        }
        _ => true,
    }
}

fn native_home_enabled() -> bool {
    env_flag_default_true("VAAK_RANKED_NATIVE_HOME")
}

fn parse_views(views: &str) -> Vec<&'static str> {
    let mut out = Vec::new();
    let raw = if views.trim().is_empty() {
        "home,local,feed"
    } else {
        views
    };
    for part in raw.split(',') {
        match part.trim().to_ascii_lowercase().as_str() {
            "home" if !out.contains(&"home") => out.push("home"),
            "local" if !out.contains(&"local") => out.push("local"),
            "feed" if !out.contains(&"feed") => out.push("feed"),
            _ => {}
        }
    }
    if out.is_empty() {
        out.extend(["home", "local", "feed"]);
    }
    out
}

fn ranked_redis_key(logical: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(logical.as_bytes());
    format!(
        "vaak:timeline:ranked:v2:{}",
        hex::encode(hasher.finalize())
    )
}

fn owner_index_key(owner: i64) -> String {
    format!("vaak:timeline:owner-index:v1:{}", owner.max(0))
}

/// PHP `admin_tl_cache_key` for Home / Local / Federated.
fn cache_key_home(owner: i64, algorithm_on: bool, following_actor_ids: &[String]) -> String {
    let mut parts = following_actor_ids.to_vec();
    parts.sort();
    let joined = parts.join("|");
    let mut hasher = Sha256::new();
    hasher.update(joined.as_bytes());
    let fp = hex::encode(hasher.finalize());
    let algo = if algorithm_on { "on" } else { "off" };
    format!(
        "{CACHE_VERSION}_home_u{owner}_a{algo}_{}",
        &fp[..24.min(fp.len())]
    )
}

fn parse_ts(s: &str) -> i64 {
    if let Ok(dt) = chrono::DateTime::parse_from_rfc3339(s) {
        return dt.timestamp();
    }
    for fmt in [
        "%Y-%m-%d %H:%M:%S%.f%z",
        "%Y-%m-%dT%H:%M:%S%.f%z",
        "%Y-%m-%d %H:%M:%S%z",
        "%Y-%m-%d %H:%M:%S+00",
    ] {
        if let Ok(dt) = chrono::DateTime::parse_from_str(s, fmt) {
            return dt.timestamp();
        }
    }
    // "2026-10-04 16:20:15+00:00" sometimes without colon in offset
    if let Ok(dt) = chrono::NaiveDateTime::parse_from_str(&s.replace('T', " ")[..19.min(s.len())], "%Y-%m-%d %H:%M:%S") {
        return dt.and_utc().timestamp();
    }
    0
}

async fn load_owner(
    db: &Client,
    owner_user_id: i64,
) -> Result<(String, String)> {
    let row = db
        .query_opt(
            "SELECT actor_id, COALESCE(actor_key, username, '') FROM ap_users WHERE id = $1 LIMIT 1",
            &[&owner_user_id],
        )
        .await
        .context("select ap_users")?;
    let Some(row) = row else {
        bail!("owner {owner_user_id} missing");
    };
    let actor_id: String = row
        .try_get::<_, Option<String>>(0)?
        .unwrap_or_default()
        .trim_end_matches('/')
        .to_string();
    let actor_key: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
    if !actor_id.starts_with("https://") {
        bail!("owner {owner_user_id} has no actor_id");
    }
    Ok((actor_id, actor_key))
}

async fn load_algorithm_enabled(db: &Client, actor_key: &str) -> Result<bool> {
    if actor_key.is_empty() {
        return Ok(true);
    }
    let row = db
        .query_opt(
            "SELECT algorithm_enabled FROM actor_profile WHERE actor_key = $1 LIMIT 1",
            &[&actor_key],
        )
        .await
        .context("select actor_profile.algorithm_enabled")?;
    Ok(match row {
        Some(r) => {
            let v: Option<i32> = r.try_get(0).ok().flatten();
            // Missing column / null → default on (PHP parity).
            v.map(|n| n != 0).unwrap_or(true)
        }
        None => true,
    })
}

/// All following actor URLs for cache-key fingerprint (PHP `admin_tl_cache_key`
/// includes every non-empty actor_id, including Bluesky profile URLs).
async fn load_following_actor_ids(db: &Client, owner_actor_id: &str) -> Result<Vec<String>> {
    let rows = db
        .query(
            "SELECT actor_id FROM following WHERE owner_actor_id = $1 OR owner_actor_id = $2",
            &[&owner_actor_id, &format!("{owner_actor_id}/")],
        )
        .await
        .context("select following")?;
    let mut out = Vec::new();
    let mut seen = HashSet::new();
    for row in rows {
        let aid: String = row
            .try_get::<_, Option<String>>(0)?
            .unwrap_or_default()
            .trim_end_matches('/')
            .to_string();
        if aid.is_empty() {
            continue;
        }
        if seen.insert(aid.clone()) {
            out.push(aid);
        }
    }
    Ok(out)
}

/// AP-only follows used to seed Home events (skip Bluesky profile URLs).
fn ap_following_for_events(following: &[String]) -> Vec<String> {
    following
        .iter()
        .filter(|a| a.starts_with("https://") && !a.contains("bsky.app/"))
        .cloned()
        .collect()
}

#[derive(Debug, Clone)]
struct TimelineItem {
    kind: String,
    sort: i64,
    id: String,
    source: String,
    #[allow(dead_code)]
    actor_id: String,
}

fn is_empty_private_stub(visibility: &str, summary: &str, media_urls: &str) -> bool {
    let vis = visibility.trim().to_ascii_lowercase();
    if vis != "followers" && vis != "direct" && vis != "private" {
        return false;
    }
    summary.trim().is_empty() && media_urls.trim().is_empty()
}

async fn fetch_home_events(
    db: &Client,
    following: &[String],
    self_actor: &str,
    hidden: &hidden::HiddenSets,
) -> Result<Vec<TimelineItem>> {
    let mut actor_ids: Vec<String> = Vec::new();
    let mut seen = HashSet::new();
    for a in following {
        let a = a.trim_end_matches('/');
        if a.is_empty() || hidden.is_hidden(a) {
            continue;
        }
        if seen.insert(a.to_string()) {
            actor_ids.push(a.to_string());
            actor_ids.push(format!("{a}/"));
        }
    }
    if !self_actor.is_empty() {
        let s = self_actor.trim_end_matches('/');
        if seen.insert(s.to_string()) {
            actor_ids.push(s.to_string());
            actor_ids.push(format!("{s}/"));
        }
    }
    if actor_ids.is_empty() {
        return Ok(Vec::new());
    }

    let mut items = Vec::new();
    let mut per_actor: HashMap<String, usize> = HashMap::new();
    for chunk in actor_ids.chunks(400) {
        let rows = db
            .query(
                "SELECT id, type, actor_id, summary, media_urls, created_at, visibility
                 FROM events
                 WHERE type = ANY(ARRAY['Create','Announce','Quote','QuotePost'])
                   AND action_taken = ANY(ARRAY['log','local_observe'])
                   AND actor_id = ANY($1)
                 ORDER BY created_at DESC, id DESC
                 LIMIT 240",
                &[&chunk],
            )
            .await
            .context("select home follow events")?;
        for row in rows {
            let id: i64 = row.get(0);
            let actor: String = row
                .try_get::<_, Option<String>>(2)?
                .unwrap_or_default()
                .trim_end_matches('/')
                .to_string();
            if actor.is_empty() || hidden.is_hidden(&actor) {
                continue;
            }
            let summary: String = row.try_get::<_, Option<String>>(3)?.unwrap_or_default();
            let media: String = row.try_get::<_, Option<String>>(4)?.unwrap_or_default();
            let created: String = row.try_get::<_, Option<String>>(5)?.unwrap_or_default();
            let visibility: String = row.try_get::<_, Option<String>>(6)?.unwrap_or_default();
            if is_empty_private_stub(&visibility, &summary, &media) {
                continue;
            }
            let n = *per_actor.get(&actor).unwrap_or(&0);
            if n >= PER_ACTOR_CAP {
                continue;
            }
            per_actor.insert(actor.clone(), n + 1);
            items.push(TimelineItem {
                kind: "event".into(),
                sort: parse_ts(&created),
                id: id.to_string(),
                source: "fediverse".into(),
                actor_id: actor,
            });
        }
    }
    Ok(items)
}

async fn fetch_own_outbox(db: &Client, self_actor: &str) -> Result<Vec<TimelineItem>> {
    let self_actor = self_actor.trim_end_matches('/');
    if !self_actor.starts_with("https://mkultra.monster/users/") {
        return Ok(Vec::new());
    }
    let like = format!("{self_actor}/notes/%");
    let rows = db
        .query(
            "SELECT id, published FROM outbox_notes
             WHERE id LIKE $1
             ORDER BY published DESC
             LIMIT 40",
            &[&like],
        )
        .await
        .context("select own outbox")?;
    let mut items = Vec::new();
    for row in rows {
        let id: String = row
            .try_get::<_, Option<String>>(0)?
            .unwrap_or_default()
            .trim_end_matches('/')
            .to_string();
        if id.is_empty() {
            continue;
        }
        let published: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        items.push(TimelineItem {
            kind: "outbox".into(),
            sort: parse_ts(&published),
            id,
            source: "local".into(),
            actor_id: self_actor.to_string(),
        });
    }
    Ok(items)
}

fn rank_from_timeline(mut items: Vec<TimelineItem>) -> Vec<Value> {
    items.sort_by(|a, b| b.sort.cmp(&a.sort));
    if items.len() > MAX_TIMELINE {
        items.truncate(MAX_TIMELINE);
    }
    let mut out = Vec::new();
    let mut seen = HashSet::new();
    for it in items {
        let dedupe = format!("{}:{}", it.kind, it.id.trim_end_matches('/'));
        if !seen.insert(dedupe) {
            continue;
        }
        let mut entry = serde_json::Map::new();
        entry.insert("k".into(), json!(it.kind));
        entry.insert("id".into(), json!(it.id));
        entry.insert("s".into(), json!(it.source));
        out.push(Value::Object(entry));
    }
    out
}

async fn load_own_did(db: &Client, owner: i64) -> Result<Option<String>> {
    // Soft-fail if session table missing / empty.
    let exists: bool = db
        .query_one(
            "SELECT EXISTS (
               SELECT 1 FROM information_schema.tables
               WHERE table_schema = current_schema()
                 AND table_name = 'bsky_sessions'
             )",
            &[],
        )
        .await
        .map(|r| r.get(0))
        .unwrap_or(false);
    if !exists {
        return Ok(None);
    }
    let row = db
        .query_opt(
            "SELECT did FROM bsky_sessions WHERE owner_user_id = $1 LIMIT 1",
            &[&owner],
        )
        .await
        .ok()
        .flatten();
    Ok(row.and_then(|r| {
        r.try_get::<_, Option<String>>(0)
            .ok()
            .flatten()
            .filter(|d| d.starts_with("did:"))
    }))
}

async fn fetch_bsky_keys(
    db: &Client,
    owner: i64,
    exclude_did: Option<&str>,
    limit: i64,
) -> Result<Vec<(String, String, String)>> {
    // (uri, fediverse_id, author_did)
    let limit = limit.clamp(1, 120);
    let rows = if let Some(did) = exclude_did.filter(|d| d.starts_with("did:")) {
        db.query(
            "SELECT p.bsky_uri, COALESCE(l.fediverse_id, ''), COALESCE(p.author_did, '')
             FROM bsky_posts p
             LEFT JOIN bsky_post_links l ON l.bsky_uri = p.bsky_uri
             WHERE EXISTS (
               SELECT 1 FROM bsky_post_observations o
               WHERE o.bsky_uri = p.bsky_uri AND o.owner_user_id = $1
             )
               AND p.text IS NOT NULL
               AND p.author_did <> $2
             ORDER BY p.indexed_at DESC, p.updated_at DESC
             LIMIT $3",
            &[&owner, &did, &limit],
        )
        .await
    } else {
        db.query(
            "SELECT p.bsky_uri, COALESCE(l.fediverse_id, ''), COALESCE(p.author_did, '')
             FROM bsky_posts p
             LEFT JOIN bsky_post_links l ON l.bsky_uri = p.bsky_uri
             WHERE EXISTS (
               SELECT 1 FROM bsky_post_observations o
               WHERE o.bsky_uri = p.bsky_uri AND o.owner_user_id = $1
             )
               AND p.text IS NOT NULL
             ORDER BY p.indexed_at DESC, p.updated_at DESC
             LIMIT $2",
            &[&owner, &limit],
        )
        .await
    }
    .context("select bsky home rank keys")?;
    let mut out = Vec::new();
    for row in rows {
        let uri: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        if !uri.starts_with("at://") {
            continue;
        }
        let fedi: String = row
            .try_get::<_, Option<String>>(1)?
            .unwrap_or_default()
            .trim_end_matches('/')
            .to_string();
        let author: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
        out.push((uri, fedi, author));
    }
    Ok(out)
}

fn bsky_author_hidden(hidden: &hidden::HiddenSets, author_did: &str) -> bool {
    let did = author_did.trim();
    if did.is_empty() || !did.starts_with("did:") {
        return false;
    }
    // PHP ap_bsky_filter_hidden_authors checks DID + bsky.app/profile/{did}.
    if hidden.is_hidden(did) {
        return true;
    }
    if hidden.is_hidden(&format!("https://bsky.app/profile/{did}")) {
        return true;
    }
    false
}

fn flush_bsky(
    out: &mut Vec<Value>,
    queued: &[Value],
    qi: &mut usize,
    bsky_emitted: &mut usize,
    since_bsky: &mut usize,
) {
    while *qi < queued.len() {
        if *since_bsky < 1 && !out.is_empty() {
            break;
        }
        if !out.is_empty() && (*bsky_emitted + 1) as f64 / (out.len() + 1) as f64 > 0.50 {
            break;
        }
        out.push(queued[*qi].clone());
        *qi += 1;
        *bsky_emitted += 1;
        *since_bsky = 0;
    }
}

fn merge_bsky_ranked(
    ranked: Vec<Value>,
    bsky: &[(String, String, String)],
    hidden: &hidden::HiddenSets,
) -> Vec<Value> {
    let mut seen_fedi = HashSet::new();
    let mut seen_bsky = HashSet::new();
    for row in &ranked {
        let k = row.get("k").and_then(|v| v.as_str()).unwrap_or("");
        let id = row.get("id").and_then(|v| v.as_str()).unwrap_or("");
        if id.is_empty() {
            continue;
        }
        if k == "event" || k == "outbox" {
            seen_fedi.insert(id.trim_end_matches('/').to_string());
        } else if k == "bsky" {
            seen_bsky.insert(id.to_string());
        }
    }
    let mut queued: Vec<Value> = Vec::new();
    for (uri, fedi, author_did) in bsky {
        if seen_bsky.contains(uri) {
            continue;
        }
        if !fedi.is_empty()
            && (seen_fedi.contains(fedi) || seen_fedi.contains(&format!("{fedi}/")))
        {
            continue;
        }
        if bsky_author_hidden(hidden, author_did) {
            continue;
        }
        // Match PHP merge shape (k+id); s helps stage_meta source_counts.
        queued.push(json!({"k": "bsky", "id": uri, "s": "bluesky"}));
        seen_bsky.insert(uri.clone());
    }
    if queued.is_empty() {
        return ranked;
    }
    if ranked.is_empty() {
        return queued.into_iter().take(60).collect();
    }
    let mut out = Vec::new();
    let mut qi = 0usize;
    let mut bsky_emitted = 0usize;
    let mut since_bsky = 1usize;
    flush_bsky(
        &mut out,
        &queued,
        &mut qi,
        &mut bsky_emitted,
        &mut since_bsky,
    );
    for item in ranked {
        out.push(item);
        since_bsky += 1;
        flush_bsky(
            &mut out,
            &queued,
            &mut qi,
            &mut bsky_emitted,
            &mut since_bsky,
        );
    }
    out
}

async fn fetch_rss_keys(db: &Client, owner: i64, limit: i64) -> Result<Vec<String>> {
    let limit = limit.clamp(1, 24);
    let owner_i32 = i32::try_from(owner).unwrap_or(0);
    if owner_i32 < 1 {
        return Ok(Vec::new());
    }
    let exists: bool = db
        .query_one(
            "SELECT EXISTS (
               SELECT 1 FROM information_schema.tables
               WHERE table_schema = current_schema()
                 AND table_name = 'rss_items'
             )",
            &[],
        )
        .await
        .map(|r| r.get(0))
        .unwrap_or(false);
    if !exists {
        return Ok(Vec::new());
    }
    let rows = db
        .query(
            "SELECT i.id, i.feed_id, COALESCE(i.url, '')
             FROM rss_items i
             JOIN rss_feeds f ON f.id = i.feed_id
             WHERE f.owner_user_id = $1 AND f.enabled IS TRUE
               AND (i.published_at IS NULL OR i.published_at >= NOW() - INTERVAL '14 days')
             ORDER BY COALESCE(i.published_at, i.ingested_at) DESC, i.id DESC
             LIMIT 120",
            &[&owner_i32],
        )
        .await
        .with_context(|| format!("select rss home keys owner={owner}"))?;
    let mut by_feed: HashMap<i64, String> = HashMap::new();
    let mut seen_url = HashSet::new();
    for row in rows {
        let id: i64 = row.try_get(0).unwrap_or(0);
        let feed_id: i64 = row.try_get(1).unwrap_or(0);
        if id < 1 || feed_id < 1 {
            continue;
        }
        let url: String = row
            .try_get::<_, Option<String>>(2)?
            .unwrap_or_default()
            .trim_end_matches('/')
            .to_string();
        if by_feed.contains_key(&feed_id) {
            continue;
        }
        if !url.is_empty() && !seen_url.insert(url) {
            continue;
        }
        by_feed.insert(feed_id, id.to_string());
        if by_feed.len() as i64 >= limit {
            break;
        }
    }
    // Deterministic shuffle by owner+hour (PHP crc32 + mt_rand Fisher–Yates).
    let mut keys: Vec<String> = by_feed.into_values().collect();
    let seed_s = format!(
        "{}|{}",
        owner,
        chrono::Utc::now().format("%Y-%m-%d-%H")
    );
    let seed = php_crc32(seed_s.as_bytes()) as u64;
    // Approximate PHP mt_rand with a seeded LCG (order need not be bit-identical).
    let mut state = seed | 1;
    for i in (1..keys.len()).rev() {
        state = state.wrapping_mul(6364136223846793005).wrapping_add(1);
        let j = (state as usize) % (i + 1);
        keys.swap(i, j);
    }
    Ok(keys)
}

/// PHP `crc32()` (IEEE) for RSS shuffle seed parity.
fn php_crc32(data: &[u8]) -> u32 {
    let mut crc: u32 = 0xFFFF_FFFF;
    for &b in data {
        crc ^= u32::from(b);
        for _ in 0..8 {
            let mask = (!(crc & 1)).wrapping_add(1); // 0 or 0xFFFF_FFFF
            crc = (crc >> 1) ^ (0xEDB8_8320 & mask);
        }
    }
    !crc
}

fn flush_rss(
    out: &mut Vec<Value>,
    queued: &[Value],
    qi: &mut usize,
    rss_emitted: &mut usize,
    since_rss: &mut usize,
    tail_emitted: usize,
) {
    while *qi < queued.len() {
        if *since_rss < 2 && !out.is_empty() {
            break;
        }
        if (*rss_emitted + 1) as f64 / (tail_emitted + *rss_emitted + 1) as f64 > 0.22 {
            break;
        }
        out.push(queued[*qi].clone());
        *qi += 1;
        *rss_emitted += 1;
        *since_rss = 0;
    }
}

fn queue_rss_after_first_page(ranked: Vec<Value>, rss_ids: &[String], first_page: usize) -> Vec<Value> {
    if rss_ids.is_empty() {
        return ranked;
    }
    let mut seen = HashSet::new();
    for row in &ranked {
        if row.get("k").and_then(|v| v.as_str()) == Some("rss") {
            if let Some(id) = row.get("id").and_then(|v| v.as_str()) {
                seen.insert(id.to_string());
            }
        }
    }
    let queued: Vec<Value> = rss_ids
        .iter()
        .filter(|id| !id.is_empty() && *id != "0" && !seen.contains(*id))
        .map(|id| json!({"k": "rss", "id": id, "s": "rss"}))
        .collect();
    if queued.is_empty() {
        return ranked;
    }
    if ranked.is_empty() {
        return queued.into_iter().take(16).collect();
    }
    let first_page = first_page.min(ranked.len());
    let mut out: Vec<Value> = ranked[..first_page].to_vec();
    let mut qi = 0usize;
    let mut rss_emitted = 0usize;
    let mut since_rss = 2usize;
    let mut tail_emitted = 0usize;
    flush_rss(
        &mut out,
        &queued,
        &mut qi,
        &mut rss_emitted,
        &mut since_rss,
        tail_emitted,
    );
    for item in &ranked[first_page..] {
        out.push(item.clone());
        if item.get("k").and_then(|v| v.as_str()) == Some("rss") {
            rss_emitted += 1;
            since_rss = 0;
        } else {
            tail_emitted += 1;
            since_rss += 1;
        }
        flush_rss(
            &mut out,
            &queued,
            &mut qi,
            &mut rss_emitted,
            &mut since_rss,
            tail_emitted,
        );
    }
    out
}

fn source_counts(ranked: &[Value]) -> Value {
    let mut map = serde_json::Map::new();
    for entry in ranked {
        let source = entry
            .get("s")
            .and_then(|v| v.as_str())
            .or_else(|| entry.get("k").and_then(|v| v.as_str()))
            .unwrap_or("unknown");
        let n = map.get(source).and_then(|v| v.as_u64()).unwrap_or(0) + 1;
        map.insert(source.to_string(), json!(n));
    }
    Value::Object(map)
}

async fn write_ranked_cache(
    redis: &mut redis::aio::MultiplexedConnection,
    owner: i64,
    logical: &str,
    ranked: &[Value],
    source: &str,
) -> Result<()> {
    if ranked.is_empty() {
        bail!("refusing to write empty ranked cache");
    }
    let redis_key = ranked_redis_key(logical);
    let now = chrono::Utc::now().timestamp();
    let payload = json!({
        "ts": now,
        "warm_ts": now,
        "source": source,
        "ranked": ranked,
        "stage_meta": {
            "ranked_count": ranked.len(),
            "source_counts": source_counts(ranked),
        },
    });
    redis_util::json_set(redis, &redis_key, &payload, HOME_TTL_SECS).await?;

    let index_key = owner_index_key(owner);
    let mut keys: Vec<String> = match redis_util::json_get(redis, &index_key).await? {
        Some(Value::Array(arr)) => arr
            .into_iter()
            .filter_map(|v| v.as_str().map(str::to_string))
            .collect(),
        _ => Vec::new(),
    };
    if !keys.iter().any(|k| k == logical) {
        keys.push(logical.to_string());
    }
    if keys.len() > 32 {
        keys = keys.split_off(keys.len() - 32);
    }
    redis_util::json_set(redis, &index_key, &json!(keys), HOME_TTL_SECS).await?;
    Ok(())
}

/// Native Home ranked rebuild (no PHP).
pub async fn warm_home_native(cfg: &Config, owner_user_id: i64) -> Result<String> {
    let started = Instant::now();
    let db = crate::db::connect(&cfg.database_url).await?;
    let (actor_id, actor_key) = load_owner(&db, owner_user_id).await?;
    let algorithm_on = load_algorithm_enabled(&db, &actor_key).await?;
    let following = load_following_actor_ids(&db, &actor_id).await?;
    let logical = cache_key_home(owner_user_id, algorithm_on, &following);
    let hidden = hidden::load_hidden_sets(&db, owner_user_id).await?;
    let ap_following = ap_following_for_events(&following);

    let mut timeline = fetch_home_events(&db, &ap_following, &actor_id, &hidden).await?;
    timeline.extend(fetch_own_outbox(&db, &actor_id).await?);
    let mut ranked = rank_from_timeline(timeline);

    let own_did = load_own_did(&db, owner_user_id).await?;
    let bsky = fetch_bsky_keys(&db, owner_user_id, own_did.as_deref(), 80).await?;
    ranked = merge_bsky_ranked(ranked, &bsky, &hidden);

    if algorithm_on {
        match fetch_rss_keys(&db, owner_user_id, 24).await {
            Ok(rss) => ranked = queue_rss_after_first_page(ranked, &rss, 5),
            Err(e) => tracing::warn!(
                owner = owner_user_id,
                error = %format!("{e:#}"),
                "native home rss merge skipped"
            ),
        }
    }

    if ranked.is_empty() {
        bail!("native home ranked empty for owner={owner_user_id}");
    }

    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    write_ranked_cache(
        &mut redis,
        owner_user_id,
        &logical,
        &ranked,
        "vaak-worker-native",
    )
    .await?;

    let ms = started.elapsed().as_millis();
    let counts = source_counts(&ranked);
    Ok(format!(
        "owner={owner_user_id} view=home key={logical} ranked={} ms={ms} source=vaak-worker-native counts={counts}",
        ranked.len()
    ))
}

async fn warm_php_views(owner_user_id: i64, views: &str) -> Result<String> {
    let paths = warm_paths();
    if !paths.script.is_file() {
        bail!("ranked-warm script missing: {}", paths.script.display());
    }
    let views = if views.trim().is_empty() {
        "local,feed"
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
            "ranked-warm php owner={owner_user_id} exit={} ms={ms} stderr={stderr} stdout={stdout}",
            output.status.code().unwrap_or(-1)
        );
    }
    if !stderr.is_empty() {
        tracing::warn!(owner = owner_user_id, %stderr, "ranked-warm php stderr");
    }
    Ok(format!("owner={owner_user_id} php_ms={ms}\n{stdout}"))
}

pub async fn warm_owner(cfg: &Config, owner_user_id: i64, views: &str) -> Result<String> {
    let wanted = parse_views(views);
    let mut lines = Vec::new();
    let mut php_views = Vec::new();

    for view in &wanted {
        match *view {
            "home" if native_home_enabled() => match warm_home_native(cfg, owner_user_id).await {
                Ok(line) => lines.push(line),
                Err(e) => {
                    tracing::warn!(
                        owner = owner_user_id,
                        error = %e,
                        "native home ranked failed; falling back to PHP"
                    );
                    php_views.push("home");
                }
            },
            "home" => php_views.push("home"),
            "local" => php_views.push("local"),
            "feed" => php_views.push("feed"),
            _ => {}
        }
    }

    if !php_views.is_empty() {
        let joined = php_views.join(",");
        lines.push(warm_php_views(owner_user_id, &joined).await?);
    }

    Ok(lines.join("\n"))
}

pub async fn run_once(cfg: &Config, owner_user_id: i64, views: &str) -> Result<()> {
    let body = warm_owner(cfg, owner_user_id, views).await?;
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
            match warm_owner(cfg, owner, &views_owned).await {
                Ok(body) => {
                    for line in body.lines().filter(|l| !l.is_empty()) {
                        tracing::info!(owner, summary = %line, "ranked-warm ok");
                    }
                }
                Err(e) => tracing::error!(owner, error = %e, "ranked-warm failed"),
            }
        }
        tokio::time::sleep(interval).await;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn home_cache_key_shape() {
        let key = cache_key_home(1, true, &["https://example.com/users/a".into()]);
        assert!(key.starts_with("v13_home_u1_aon_"));
        assert_eq!(key.len(), "v13_home_u1_aon_".len() + 24);
    }

    #[test]
    fn home_cache_key_matches_php_hash_prefix() {
        // PHP: substr(hash('sha256', 'https://example.com/users/a'), 0, 24)
        let key = cache_key_home(1, true, &["https://example.com/users/a".into()]);
        let mut hasher = Sha256::new();
        hasher.update(b"https://example.com/users/a");
        let fp = hex::encode(hasher.finalize());
        assert_eq!(key, format!("v13_home_u1_aon_{}", &fp[..24]));
    }

    #[test]
    fn php_crc32_known_vector() {
        // PHP: sprintf('%u', crc32('1|2026-10-04-16')) → 3395297329
        assert_eq!(php_crc32(b"1|2026-10-04-16"), 3_395_297_329);
        assert_eq!(php_crc32(b"123456789"), 3_421_780_262);
    }
}
