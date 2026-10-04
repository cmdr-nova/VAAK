//! Home / Local / Federated **ranked** warm (loading-plan slice 2 → native).
//!
//! - **Home (native):** rebuilds `vaak:timeline:ranked:v2:{sha256(logical)}` +
//!   owner index from Postgres (follow events, own outbox, FoF/cold-start
//!   recommendations, favourite/toxicity nudges when algorithm on, Bluesky
//!   merge, RSS spacing). Source `vaak-worker-native`. Flag
//!   `VAAK_RANKED_NATIVE_HOME=1`.
//! - **Local / Federated (native):** outbox+local boosts / firehose events with
//!   v13 key parity. Flag `VAAK_RANKED_NATIVE_LOCAL_FEED=1` (default on).
//! - PHP `api/bin/ranked-warm.php` remains only when a native flag is off or
//!   native Home rebuild fails (last-resort empty fallback).
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

fn native_local_feed_enabled() -> bool {
    env_flag_default_true("VAAK_RANKED_NATIVE_LOCAL_FEED")
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

/// PHP `admin_tl_cache_key` for Home.
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

/// PHP `admin_tl_cache_key('local')` — follow-set independent; algo mode `na`.
fn cache_key_local(owner: i64) -> String {
    let mut hasher = Sha256::new();
    hasher.update(b"");
    let fp = hex::encode(hasher.finalize());
    format!(
        "{CACHE_VERSION}_local_u{owner}_ana_{}",
        &fp[..24.min(fp.len())]
    )
}

/// PHP `admin_tl_cache_key('feed')` — following fingerprint; algo mode `na`.
fn cache_key_feed(owner: i64, following_actor_ids: &[String]) -> String {
    let mut parts = following_actor_ids.to_vec();
    parts.sort();
    let joined = parts.join("|");
    let mut hasher = Sha256::new();
    hasher.update(joined.as_bytes());
    let fp = hex::encode(hasher.finalize());
    format!(
        "{CACHE_VERSION}_feed_u{owner}_ana_{}",
        &fp[..24.min(fp.len())]
    )
}

fn outbox_is_bsky_import(raw_create_json: &str) -> bool {
    let raw = raw_create_json.trim();
    if raw.is_empty() {
        return false;
    }
    // Match PHP admin_outbox_is_bsky_import: object.vaakOrigin === 'bluesky'
    if let Ok(v) = serde_json::from_str::<Value>(raw) {
        return v
            .get("object")
            .and_then(|o| o.get("vaakOrigin"))
            .and_then(|x| x.as_str())
            == Some("bluesky");
    }
    false
}

fn reblog_is_bsky(status_id: &str, object_id: &str) -> bool {
    let status = status_id.trim().to_ascii_lowercase();
    let object = object_id.trim().to_ascii_lowercase();
    status.starts_with("bsky-repost-")
        || object.starts_with("https://bsky.app/")
        || object.contains("bsky.mkultra.monster/")
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
    actor_id: String,
    /// Author used for preference / suppression (Announce → target_actor).
    pref_actor: String,
    summary: String,
    visibility: String,
    object_id: String,
    #[allow(dead_code)]
    event_type: String,
}

impl TimelineItem {
    fn event(
        id: String,
        sort: i64,
        actor_id: String,
        pref_actor: String,
        summary: String,
        visibility: String,
        object_id: String,
        event_type: String,
        source: &str,
    ) -> Self {
        Self {
            kind: "event".into(),
            sort,
            id,
            source: source.into(),
            actor_id,
            pref_actor,
            summary,
            visibility,
            object_id,
            event_type,
        }
    }

    fn outbox(id: String, sort: i64, actor_id: String, summary: String) -> Self {
        Self {
            kind: "outbox".into(),
            sort,
            id: id.clone(),
            source: "local".into(),
            actor_id: actor_id.clone(),
            pref_actor: actor_id,
            summary,
            visibility: "public".into(),
            object_id: id,
            event_type: String::new(),
        }
    }

    fn boost(id: String, sort: i64, owner: String) -> Self {
        Self {
            kind: "boost".into(),
            sort,
            id,
            source: "boost".into(),
            actor_id: owner.clone(),
            pref_actor: owner,
            summary: String::new(),
            visibility: "public".into(),
            object_id: String::new(),
            event_type: String::new(),
        }
    }
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
                "SELECT id, type, actor_id, summary, media_urls, created_at, visibility,
                        COALESCE(object_id, ''), COALESCE(target_actor, '')
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
            let event_type: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
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
            let object_id: String = row
                .try_get::<_, Option<String>>(7)?
                .unwrap_or_default()
                .trim_end_matches('/')
                .to_string();
            let target_actor: String = row
                .try_get::<_, Option<String>>(8)?
                .unwrap_or_default()
                .trim_end_matches('/')
                .to_string();
            if is_empty_private_stub(&visibility, &summary, &media) {
                continue;
            }
            let n = *per_actor.get(&actor).unwrap_or(&0);
            if n >= PER_ACTOR_CAP {
                continue;
            }
            per_actor.insert(actor.clone(), n + 1);
            let pref = if event_type.eq_ignore_ascii_case("announce") && !target_actor.is_empty() {
                target_actor
            } else {
                actor.clone()
            };
            items.push(TimelineItem::event(
                id.to_string(),
                parse_ts(&created),
                actor,
                pref,
                summary,
                visibility,
                object_id,
                event_type,
                "fediverse",
            ));
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
            "SELECT id, published, COALESCE(content, '')
             FROM outbox_notes
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
        let content: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
        items.push(TimelineItem::outbox(
            id,
            parse_ts(&published),
            self_actor.to_string(),
            content,
        ));
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

/// Instance Local: public outbox notes + local masto_reblogs (skip Bluesky imports).
async fn fetch_local_timeline(db: &Client) -> Result<Vec<TimelineItem>> {
    let mut items = Vec::new();
    let note_rows = db
        .query(
            "SELECT id, published, COALESCE(raw_create_json, '')
             FROM outbox_notes
             WHERE id LIKE 'https://mkultra.monster/users/%/notes/%'
             ORDER BY published DESC
             LIMIT 160",
            &[],
        )
        .await
        .context("select local outbox_notes")?;
    for row in note_rows {
        let id: String = row
            .try_get::<_, Option<String>>(0)?
            .unwrap_or_default()
            .trim_end_matches('/')
            .to_string();
        if id.is_empty() {
            continue;
        }
        let published: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        let raw: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
        if outbox_is_bsky_import(&raw) {
            continue;
        }
        let actor = id
            .rsplit_once("/notes/")
            .map(|(prefix, _)| prefix.to_string())
            .unwrap_or_default();
        items.push(TimelineItem::outbox(
            id,
            parse_ts(&published),
            actor,
            String::new(),
        ));
    }

    let rb_rows = db
        .query(
            "SELECT status_id, COALESCE(object_id, ''), created_at, COALESCE(owner_actor_id, '')
             FROM masto_reblogs
             WHERE owner_actor_id LIKE 'https://mkultra.monster/users/%'
             ORDER BY created_at DESC
             LIMIT 80",
            &[],
        )
        .await
        .context("select local masto_reblogs")?;
    for row in rb_rows {
        let status_id: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let object_id: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        if status_id.trim().is_empty() || reblog_is_bsky(&status_id, &object_id) {
            continue;
        }
        let created: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
        let owner: String = row
            .try_get::<_, Option<String>>(3)?
            .unwrap_or_default()
            .trim_end_matches('/')
            .to_string();
        items.push(TimelineItem::boost(
            status_id,
            parse_ts(&created),
            owner,
        ));
    }
    Ok(items)
}

/// Federated firehose events (Create/Announce/Quote), owner mute/block filtered.
async fn fetch_federated_events(
    db: &Client,
    hidden: &hidden::HiddenSets,
) -> Result<Vec<TimelineItem>> {
    let rows = db
        .query(
            "SELECT id, actor_id, summary, media_urls, created_at, visibility
             FROM events
             WHERE type = ANY(ARRAY['Create','Announce','Quote','QuotePost'])
               AND action_taken = ANY(ARRAY['log','local_observe'])
             ORDER BY created_at DESC, id DESC
             LIMIT 160",
            &[],
        )
        .await
        .context("select federated events")?;
    let mut items = Vec::new();
    for row in rows {
        let id: i64 = row.get(0);
        let actor: String = row
            .try_get::<_, Option<String>>(1)?
            .unwrap_or_default()
            .trim_end_matches('/')
            .to_string();
        if actor.is_empty() || hidden.is_hidden(&actor) {
            continue;
        }
        let summary: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
        let media: String = row.try_get::<_, Option<String>>(3)?.unwrap_or_default();
        let created: String = row.try_get::<_, Option<String>>(4)?.unwrap_or_default();
        let visibility: String = row.try_get::<_, Option<String>>(5)?.unwrap_or_default();
        if is_empty_private_stub(&visibility, &summary, &media) {
            continue;
        }
        items.push(TimelineItem::event(
            id.to_string(),
            parse_ts(&created),
            actor.clone(),
            actor,
            summary,
            visibility,
            String::new(),
            String::new(),
            "fediverse",
        ));
    }
    Ok(items)
}

async fn load_downranking_enabled(db: &Client, actor_key: &str) -> Result<bool> {
    if actor_key.is_empty() {
        return Ok(true);
    }
    let row = db
        .query_opt(
            "SELECT downranking_enabled FROM actor_profile WHERE actor_key = $1 LIMIT 1",
            &[&actor_key],
        )
        .await
        .context("select actor_profile.downranking_enabled")?;
    Ok(match row {
        Some(r) => {
            let v: Option<i32> = r.try_get(0).ok().flatten();
            v.map(|n| n != 0).unwrap_or(true)
        }
        None => true,
    })
}

fn strip_tags_lower(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    let mut in_tag = false;
    for ch in text.chars() {
        match ch {
            '<' => in_tag = true,
            '>' => in_tag = false,
            _ if !in_tag => out.push(ch),
            _ => {}
        }
    }
    out.to_ascii_lowercase()
}

fn extract_hashtags(text: &str) -> Vec<String> {
    let plain = strip_tags_lower(text);
    let mut tags = Vec::new();
    let mut seen = HashSet::new();
    let bytes = plain.as_bytes();
    let mut i = 0usize;
    while i < bytes.len() {
        if bytes[i] == b'#' {
            let start = i + 1;
            let mut end = start;
            while end < bytes.len() {
                let c = bytes[end] as char;
                if c.is_ascii_alphanumeric() || c == '_' {
                    end += 1;
                } else {
                    break;
                }
            }
            if end > start {
                let tag = plain[start..end].to_string();
                if (2..=64).contains(&tag.len()) && seen.insert(tag.clone()) {
                    tags.push(tag);
                }
                i = end;
                continue;
            }
        }
        i += 1;
    }
    tags
}

fn phrase_matches_text(text: &str, phrase: &str) -> bool {
    let phrase = {
        let p = strip_tags_lower(phrase);
        let collapsed: String = p.split_whitespace().collect::<Vec<_>>().join(" ");
        collapsed
    };
    if phrase.is_empty() {
        return false;
    }
    // Drop hashtag tokens so admin terms don't fire on self-applied tags.
    let lowered = strip_tags_lower(text);
    let mut search = String::new();
    let mut chars = lowered.chars().peekable();
    while let Some(ch) = chars.next() {
        if ch == '#' {
            while let Some(c) = chars.peek() {
                if c.is_ascii_alphanumeric() || *c == '_' {
                    chars.next();
                } else {
                    break;
                }
            }
            search.push(' ');
            continue;
        }
        search.push(ch);
    }
    let Ok(re) = regex::RegexBuilder::new(&format!(
        r"(?i)(?<!\p{{L}}){}(?!\p{{L}})",
        regex::escape(&phrase)
    ))
    .build() else {
        return search.contains(&phrase);
    };
    re.is_match(&search)
}

fn toxicity_categories(text: &str, admin_terms: &[(String, String)]) -> Vec<String> {
    let lower = strip_tags_lower(text);
    if lower.trim().is_empty() {
        return Vec::new();
    }
    let mut matches = Vec::new();
    let builtins: &[(&str, &str)] = &[
        (
            "direct_abuse",
            r"(?i)\b(?:fuck\s+you|suck\s+my\s+dick|go\s+fuck\s+yourself)\b",
        ),
        ("ai_slogan", r"(?i)\b(?:slop|ai\s+trash|clanker)\b"),
        (
            "misogyny",
            r"(?i)\b(?:women\s+belong\s+in\s+the\s+kitchen|women\s+are\s+property|go\s+back\s+to\s+the\s+kitchen)\b",
        ),
    ];
    for (cat, pat) in builtins {
        if let Ok(re) = regex::Regex::new(pat) {
            if re.is_match(&lower) {
                matches.push((*cat).to_string());
            }
        }
    }
    for (phrase, category) in admin_terms {
        if phrase_matches_text(&lower, phrase) {
            matches.push(category.clone());
        }
    }
    matches.sort();
    matches.dedup();
    matches
}

async fn load_downrank_terms(db: &Client) -> Result<Vec<(String, String)>> {
    let rows = db
        .query(
            "SELECT phrase, category FROM ap_home_downrank_terms
             WHERE enabled = 1 ORDER BY id ASC LIMIT 500",
            &[],
        )
        .await
        .unwrap_or_default();
    let mut out = Vec::new();
    for row in rows {
        let phrase: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let phrase = phrase.split_whitespace().collect::<Vec<_>>().join(" ");
        if phrase.is_empty() || phrase.chars().count() > 120 {
            continue;
        }
        let category: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        let category: String = category
            .chars()
            .filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-')
            .collect();
        let category = if category.is_empty() {
            "custom".into()
        } else {
            category.to_ascii_lowercase()
        };
        out.push((phrase, category));
    }
    Ok(out)
}

async fn load_suppression_map(
    db: &Client,
    owner: i64,
) -> Result<HashMap<String, (i32, i64, Vec<String>)>> {
    let rows = db
        .query(
            "SELECT actor_id, score, categories_json, suppressed_until
             FROM ap_home_suppression WHERE owner_user_id = $1",
            &[&owner],
        )
        .await
        .unwrap_or_default();
    let mut out = HashMap::new();
    for row in rows {
        let actor: String = row
            .try_get::<_, Option<String>>(0)?
            .unwrap_or_default()
            .trim_end_matches('/')
            .to_string();
        if actor.is_empty() {
            continue;
        }
        let score: i32 = row.try_get::<_, Option<i32>>(1)?.unwrap_or(0).max(0);
        let cats_raw: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
        let cats: Vec<String> = serde_json::from_str(&cats_raw).unwrap_or_default();
        let until_s: String = row.try_get::<_, Option<String>>(3)?.unwrap_or_default();
        let until = parse_ts(&until_s);
        out.insert(actor, (score, until, cats));
    }
    Ok(out)
}

async fn record_suppression(
    db: &Client,
    owner: i64,
    actor: &str,
    categories: &[String],
    object_id: &str,
) -> Result<()> {
    if owner < 1 || actor.is_empty() || categories.is_empty() {
        return Ok(());
    }
    let now = chrono::Utc::now().timestamp();
    let existing = db
        .query_opt(
            "SELECT score, suppressed_until, categories_json
             FROM ap_home_suppression WHERE owner_user_id = $1 AND actor_id = $2",
            &[&owner, &actor],
        )
        .await
        .ok()
        .flatten();
    let (mut score, previous_until, mut all_cats) = if let Some(row) = existing {
        let score: i32 = row.try_get::<_, Option<i32>>(0)?.unwrap_or(0).max(0);
        let until_s: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        let cats_raw: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
        let cats: Vec<String> = serde_json::from_str(&cats_raw).unwrap_or_default();
        (score, parse_ts(&until_s), cats)
    } else {
        (0, 0, Vec::new())
    };
    score = (score + 1).min(8);
    let mut until = now + (3600 * score.max(1) as i64).min(7 * 86400);
    if previous_until > until {
        until = previous_until;
    }
    for c in categories {
        if !all_cats.iter().any(|x| x == c) {
            all_cats.push(c.clone());
        }
    }
    let cats_json = serde_json::to_string(&all_cats).unwrap_or_else(|_| "[]".into());
    let until_s = chrono::DateTime::from_timestamp(until, 0)
        .map(|dt| dt.to_rfc3339())
        .unwrap_or_default();
    let now_s = chrono::Utc::now().to_rfc3339();
    let object = object_id.chars().take(2048).collect::<String>();
    let _ = db
        .execute(
            "INSERT INTO ap_home_suppression
             (owner_user_id, actor_id, score, categories_json, suppressed_until, last_object_id, updated_at)
             VALUES ($1,$2,$3,$4,$5,$6,$7)
             ON CONFLICT (owner_user_id, actor_id) DO UPDATE SET
               score = EXCLUDED.score,
               categories_json = EXCLUDED.categories_json,
               suppressed_until = EXCLUDED.suppressed_until,
               last_object_id = EXCLUDED.last_object_id,
               updated_at = EXCLUDED.updated_at",
            &[
                &owner,
                &actor,
                &score,
                &cats_json,
                &until_s,
                &object,
                &now_s,
            ],
        )
        .await;
    Ok(())
}

async fn apply_toxicity_downrank(
    db: &Client,
    owner: i64,
    timeline: &mut [TimelineItem],
) -> Result<()> {
    if owner < 1 {
        return Ok(());
    }
    let terms = load_downrank_terms(db).await.unwrap_or_default();
    let mut states = load_suppression_map(db, owner).await.unwrap_or_default();
    let now = chrono::Utc::now().timestamp();
    for item in timeline.iter_mut() {
        // Empty visibility defaults to public (PHP `$row['visibility'] ?? 'public'`).
        let vis = {
            let v = item.visibility.trim().to_ascii_lowercase();
            if v.is_empty() {
                "public".to_string()
            } else {
                v
            }
        };
        if vis != "public" && vis != "unlisted" {
            continue;
        }
        let actor = if !item.pref_actor.is_empty() {
            item.pref_actor.clone()
        } else {
            item.actor_id.clone()
        };
        if actor.is_empty() {
            continue;
        }
        let cats = toxicity_categories(&item.summary, &terms);
        if !cats.is_empty() {
            let object = if !item.object_id.is_empty() {
                item.object_id.clone()
            } else {
                item.id.clone()
            };
            let _ = record_suppression(db, owner, &actor, &cats, &object).await;
            let score = states
                .get(&actor)
                .map(|(s, _, _)| (*s + 1).min(8))
                .unwrap_or(1);
            states.insert(actor.clone(), (score, now + 3600, cats));
        }
        if let Some((score, until, _)) = states.get(&actor) {
            if *until > now {
                let penalty = (1800 * (*score).max(1) as i64).min(12 * 3600);
                item.sort -= penalty;
            }
        }
    }
    Ok(())
}

async fn load_favourite_actor_weights(db: &Client, owner: i64) -> HashMap<String, f64> {
    if owner < 1 {
        return HashMap::new();
    }
    let rows = db
        .query(
            "SELECT target_actor AS actor_id, COUNT(*)::bigint AS favourite_count
             FROM masto_favourites
             WHERE owner_user_id = $1
               AND target_actor IS NOT NULL
               AND target_actor <> ''
             GROUP BY target_actor
             ORDER BY favourite_count DESC
             LIMIT 64",
            &[&owner],
        )
        .await
        .unwrap_or_default();
    let mut weights = HashMap::new();
    for row in rows {
        let actor: String = row
            .try_get::<_, Option<String>>(0)
            .ok()
            .flatten()
            .unwrap_or_default()
            .trim_end_matches('/')
            .to_string();
        let count: i64 = row.try_get::<_, Option<i64>>(1).ok().flatten().unwrap_or(0);
        if !actor.is_empty() && count > 0 {
            weights.insert(actor, (count as f64).min(64.0));
        }
    }
    weights
}

async fn load_signal_actor_weights(db: &Client, owner: i64) -> HashMap<String, f64> {
    if owner < 1 {
        return HashMap::new();
    }
    let since = chrono::Utc::now() - chrono::Duration::days(45);
    let since_s = since.to_rfc3339();
    let rows = db
        .query(
            "SELECT signal_type, weight, metadata_json, created_at
             FROM ap_user_signals
             WHERE owner_user_id = $1 AND created_at >= $2
             ORDER BY id DESC LIMIT 500",
            &[&owner, &since_s],
        )
        .await
        .unwrap_or_default();
    let kind_weight = |kind: &str| -> f64 {
        match kind {
            "like" => 1.4,
            "boost" => 1.2,
            "reply" => 1.0,
            "bookmark" => 1.1,
            "follow" => 0.6,
            "impression" => -0.03,
            "click" => 0.30,
            "dwell" => 0.12,
            _ => 0.5,
        }
    };
    let now = chrono::Utc::now().timestamp();
    let mut weights = HashMap::new();
    for row in rows {
        let base: f64 = row
            .try_get::<_, Option<f64>>(1)
            .ok()
            .flatten()
            .or_else(|| {
                row.try_get::<_, Option<i32>>(1)
                    .ok()
                    .flatten()
                    .map(|n| n as f64)
            })
            .unwrap_or(0.0);
        if base <= 0.0 {
            continue;
        }
        let kind: String = row
            .try_get::<_, Option<String>>(0)
            .ok()
            .flatten()
            .unwrap_or_default()
            .to_ascii_lowercase();
        let meta_raw: String = row
            .try_get::<_, Option<String>>(2)
            .ok()
            .flatten()
            .unwrap_or_else(|| "{}".into());
        let meta: Value = serde_json::from_str(&meta_raw).unwrap_or(json!({}));
        let mut actor = meta
            .get("target_actor")
            .and_then(|v| v.as_str())
            .unwrap_or("")
            .trim_end_matches('/')
            .to_string();
        if actor.is_empty() {
            actor = meta
                .get("author_did")
                .and_then(|v| v.as_str())
                .unwrap_or("")
                .trim_end_matches('/')
                .to_string();
        }
        if actor.is_empty() {
            if let Some(handle) = meta.get("author_handle").and_then(|v| v.as_str()) {
                let handle = handle.trim().trim_start_matches('@');
                if !handle.is_empty() {
                    actor = format!("https://bsky.app/profile/{handle}");
                }
            }
        }
        if actor.is_empty() {
            continue;
        }
        let created: String = row
            .try_get::<_, Option<String>>(3)
            .ok()
            .flatten()
            .unwrap_or_default();
        let age = (now - parse_ts(&created)).max(0) as f64;
        let decay = (-age / (14.0 * 86400.0)).exp();
        let delta = base * kind_weight(&kind) * decay;
        let entry = weights.entry(actor).or_insert(0.0);
        *entry = (*entry + delta).clamp(-3.0, 24.0);
    }
    weights
}

async fn load_favourite_tag_weights(db: &Client, owner: i64) -> HashMap<String, i32> {
    if owner < 1 {
        return HashMap::new();
    }
    let fav_rows = db
        .query(
            "SELECT status_id, object_id FROM masto_favourites
             WHERE owner_user_id = $1 ORDER BY created_at DESC LIMIT 200",
            &[&owner],
        )
        .await
        .unwrap_or_default();
    let mut object_ids = Vec::new();
    let mut status_ids: Vec<i32> = Vec::new();
    let mut seen_oid = HashSet::new();
    for row in fav_rows {
        let oid: String = row
            .try_get::<_, Option<String>>(1)
            .ok()
            .flatten()
            .unwrap_or_default()
            .trim_end_matches('/')
            .to_string();
        if !oid.is_empty() && seen_oid.insert(oid.clone()) {
            object_ids.push(oid);
        }
        if let Ok(Some(sid_s)) = row.try_get::<_, Option<String>>(0) {
            if let Ok(sid) = sid_s.parse::<i32>() {
                if sid > 0 && sid < 2_000_000 {
                    status_ids.push(sid);
                }
            }
        }
    }
    let mut texts = Vec::new();
    for chunk in object_ids.chunks(80) {
        if chunk.is_empty() {
            continue;
        }
        if let Ok(rows) = db
            .query(
                "SELECT summary FROM events WHERE object_id = ANY($1)",
                &[&chunk],
            )
            .await
        {
            for row in rows {
                let s: String = row
                    .try_get::<_, Option<String>>(0)
                    .ok()
                    .flatten()
                    .unwrap_or_default();
                if !s.is_empty() {
                    texts.push(s);
                }
            }
        }
        if let Ok(rows) = db
            .query(
                "SELECT content FROM outbox_notes WHERE id = ANY($1)",
                &[&chunk],
            )
            .await
        {
            for row in rows {
                let s: String = row
                    .try_get::<_, Option<String>>(0)
                    .ok()
                    .flatten()
                    .unwrap_or_default();
                if !s.is_empty() {
                    texts.push(s);
                }
            }
        }
    }
    if !status_ids.is_empty() {
        if let Ok(rows) = db
            .query(
                "SELECT content_text FROM masto_statuses WHERE local_id = ANY($1)",
                &[&status_ids],
            )
            .await
        {
            for row in rows {
                let s: String = row
                    .try_get::<_, Option<String>>(0)
                    .ok()
                    .flatten()
                    .unwrap_or_default();
                if !s.is_empty() {
                    texts.push(s);
                }
            }
        }
    }
    let mut weights = HashMap::new();
    for text in texts {
        for tag in extract_hashtags(&text) {
            let e = weights.entry(tag).or_insert(0);
            *e = (*e + 1).min(32);
        }
    }
    let mut pairs: Vec<_> = weights.into_iter().collect();
    pairs.sort_by(|a, b| b.1.cmp(&a.1));
    pairs.truncate(32);
    pairs.into_iter().collect()
}

fn host_of_actor(actor: &str) -> String {
    actor
        .strip_prefix("https://")
        .or_else(|| actor.strip_prefix("http://"))
        .and_then(|rest| rest.split('/').next())
        .unwrap_or("")
        .to_ascii_lowercase()
}

async fn load_muted_phrases(db: &Client, owner: i64) -> Vec<String> {
    if owner < 1 {
        return Vec::new();
    }
    let rows = db
        .query(
            "SELECT phrase FROM ap_muted_words WHERE owner_user_id = $1 LIMIT 200",
            &[&owner],
        )
        .await
        .unwrap_or_default();
    let mut out = Vec::new();
    for row in rows {
        let phrase: String = row
            .try_get::<_, Option<String>>(0)
            .ok()
            .flatten()
            .unwrap_or_default();
        let phrase = phrase.trim().to_ascii_lowercase();
        if !phrase.is_empty() {
            out.push(phrase);
        }
    }
    out
}

fn text_matches_muted(text: &str, phrases: &[String]) -> bool {
    if phrases.is_empty() {
        return false;
    }
    let lower = strip_tags_lower(text);
    if lower.trim().is_empty() {
        return false;
    }
    phrases.iter().any(|p| !p.is_empty() && lower.contains(p))
}

/// Friends-of-follows from cached Announces (PHP `admin_home_foaf_actor_weights`).
async fn load_foaf_actor_weights(
    db: &Client,
    owner: i64,
    owner_actor: &str,
    following: &[String],
    hidden: &hidden::HiddenSets,
) -> HashMap<String, f64> {
    if owner < 1 || following.is_empty() {
        return HashMap::new();
    }
    let owner_actor = owner_actor.trim_end_matches('/');
    let follows: HashSet<String> = following
        .iter()
        .filter(|a| a.starts_with("https://") && !a.contains("bsky.app/"))
        .map(|a| a.trim_end_matches('/').to_string())
        .collect();
    if follows.is_empty() {
        return HashMap::new();
    }
    let since = (chrono::Utc::now() - chrono::Duration::days(7)).to_rfc3339();
    let mut counts: HashMap<String, f64> = HashMap::new();
    let follow_list: Vec<String> = follows.iter().cloned().collect();
    for chunk in follow_list.chunks(40) {
        let mut actors = Vec::with_capacity(chunk.len() * 2);
        for aid in chunk {
            actors.push(aid.clone());
            actors.push(format!("{aid}/"));
        }
        let rows = match db
            .query(
                "SELECT target_actor, COUNT(*)::bigint AS c
                 FROM events
                 WHERE type = 'Announce'
                   AND action_taken = ANY(ARRAY['log','local_observe'])
                   AND created_at >= $1
                   AND actor_id = ANY($2)
                   AND target_actor IS NOT NULL AND target_actor <> ''
                 GROUP BY target_actor",
                &[&since, &actors],
            )
            .await
        {
            Ok(r) => r,
            Err(_) => continue,
        };
        for row in rows {
            let actor: String = row
                .try_get::<_, Option<String>>(0)
                .ok()
                .flatten()
                .unwrap_or_default()
                .trim_end_matches('/')
                .to_string();
            let c: i64 = row.try_get::<_, Option<i64>>(1).ok().flatten().unwrap_or(0);
            if c < 1
                || actor.is_empty()
                || !actor.starts_with("https://")
                || follows.contains(&actor)
                || actor == owner_actor
                || actor.contains("bsky.app/")
                || actor.contains("relay.fedi.buzz/")
                || hidden.is_hidden(&actor)
            {
                continue;
            }
            *counts.entry(actor).or_insert(0.0) += c as f64;
        }
    }
    let mut pairs: Vec<_> = counts.into_iter().collect();
    pairs.sort_by(|a, b| b.1.partial_cmp(&a.1).unwrap_or(std::cmp::Ordering::Equal));
    pairs.truncate(40);
    pairs
        .into_iter()
        .map(|(a, c)| (a, c.min(64.0)))
        .collect()
}

struct RecoState<'a> {
    timeline: &'a mut Vec<TimelineItem>,
    hidden: &'a hidden::HiddenSets,
    muted: &'a [String],
    seen_objects: HashSet<String>,
    seen_rec_actors: HashSet<String>,
    seen_hosts: HashMap<String, usize>,
    cold_start: bool,
    max_added: usize,
    added: usize,
}

impl RecoState<'_> {
    fn try_add(
        &mut self,
        row_id: i64,
        actor: String,
        object_id: String,
        summary: String,
        created: String,
        visibility: String,
        host: String,
        score: f64,
        budget: usize,
    ) -> bool {
        if self.added >= budget.min(self.max_added) {
            return false;
        }
        let object = {
            let o = object_id.trim_end_matches('/');
            if o.is_empty() {
                row_id.to_string()
            } else {
                o.to_string()
            }
        };
        if object.is_empty() || self.seen_objects.contains(&object) {
            return false;
        }
        if actor.is_empty() || self.hidden.is_hidden(&actor) {
            return false;
        }
        if text_matches_muted(&summary, self.muted) {
            return false;
        }
        if self.cold_start && self.seen_rec_actors.contains(&actor) {
            return false;
        }
        let host = if host.is_empty() {
            host_of_actor(&actor)
        } else {
            host.to_ascii_lowercase()
        };
        if self.cold_start && !host.is_empty() && *self.seen_hosts.get(&host).unwrap_or(&0) >= 2 {
            return false;
        }
        if !self.cold_start && score < 1.5 {
            return false;
        }
        let mut sort = parse_ts(&created);
        let bump = ((1800.0 * (1.0 + score.max(0.0)).log2()).round() as i64).min(12 * 3600);
        sort += bump;
        self.timeline.push(TimelineItem::event(
            row_id.to_string(),
            sort,
            actor.clone(),
            actor.clone(),
            summary,
            visibility,
            object.clone(),
            "Create".into(),
            "recommendation",
        ));
        self.seen_objects.insert(object);
        if self.cold_start {
            self.seen_rec_actors.insert(actor);
            if !host.is_empty() {
                *self.seen_hosts.entry(host).or_insert(0) += 1;
            }
        }
        self.added += 1;
        true
    }
}

fn parse_reco_row(row: &tokio_postgres::Row) -> Result<(i64, String, String, String, String, String, String)> {
    let id: i64 = row.get(0);
    let actor: String = row
        .try_get::<_, Option<String>>(1)?
        .unwrap_or_default()
        .trim_end_matches('/')
        .to_string();
    let object_id: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
    let summary: String = row.try_get::<_, Option<String>>(3)?.unwrap_or_default();
    let created: String = row.try_get::<_, Option<String>>(4)?.unwrap_or_default();
    let visibility: String = row.try_get::<_, Option<String>>(5)?.unwrap_or_default();
    let host: String = row.try_get::<_, Option<String>>(6)?.unwrap_or_default();
    Ok((id, actor, object_id, summary, created, visibility, host))
}

/// PHP `admin_home_cached_recommendation_items` — bounded FoF / preference fill.
async fn apply_cached_recommendations(
    db: &Client,
    owner: i64,
    owner_actor: &str,
    following: &[String],
    hidden: &hidden::HiddenSets,
    timeline: &mut Vec<TimelineItem>,
    actor_weights: &HashMap<String, f64>,
    tag_weights: &HashMap<String, i32>,
) -> Result<()> {
    if owner < 1 {
        return Ok(());
    }
    let cold_start = actor_weights.is_empty() && tag_weights.is_empty();
    let max_added: usize = if cold_start { 10 } else { 12 };
    let muted = load_muted_phrases(db, owner).await;
    let mut seen_objects: HashSet<String> = HashSet::new();
    for item in timeline.iter() {
        let oid = if !item.object_id.is_empty() {
            item.object_id.clone()
        } else {
            item.id.clone()
        };
        if !oid.is_empty() {
            seen_objects.insert(oid.trim_end_matches('/').to_string());
        }
    }
    let since = (chrono::Utc::now() - chrono::Duration::days(7)).to_rfc3339();
    let mut state = RecoState {
        timeline,
        hidden,
        muted: &muted,
        seen_objects,
        seen_rec_actors: HashSet::new(),
        seen_hosts: HashMap::new(),
        cold_start,
        max_added,
        added: 0,
    };

    if cold_start {
        let foaf = load_foaf_actor_weights(db, owner, owner_actor, following, hidden).await;
        let foaf_budget = max_added.min(4);
        if !foaf.is_empty() {
            let mut foaf_actors: Vec<_> = foaf.keys().cloned().collect();
            foaf_actors.sort_by(|a, b| {
                foaf.get(b)
                    .unwrap_or(&0.0)
                    .partial_cmp(foaf.get(a).unwrap_or(&0.0))
                    .unwrap_or(std::cmp::Ordering::Equal)
            });
            foaf_actors.truncate(24);
            let mut lookup = Vec::new();
            for fa in &foaf_actors {
                lookup.push(fa.clone());
                lookup.push(format!("{fa}/"));
            }
            if let Ok(rows) = db
                .query(
                    "SELECT id, actor_id, COALESCE(object_id,''), COALESCE(summary,''),
                            created_at, COALESCE(visibility,'public'), COALESCE(host,'')
                     FROM events
                     WHERE type = 'Create'
                       AND action_taken = ANY(ARRAY['log','local_observe'])
                       AND created_at >= $1
                       AND visibility = ANY(ARRAY['public','unlisted'])
                       AND actor_id = ANY($2)
                     ORDER BY created_at DESC, id DESC
                     LIMIT 80",
                    &[&since, &lookup],
                )
                .await
            {
                for row in rows {
                    if state.added >= foaf_budget {
                        break;
                    }
                    let (id, actor, object_id, summary, created, visibility, host) =
                        parse_reco_row(&row)?;
                    let score = 1.0 + foaf.get(&actor).copied().unwrap_or(0.0);
                    state.try_add(
                        id, actor, object_id, summary, created, visibility, host, score, foaf_budget,
                    );
                }
            }
        }
        for (local_only, limit) in [(true, 80i64), (false, 120i64)] {
            if state.added >= max_added {
                break;
            }
            let rows = if local_only {
                db.query(
                    "SELECT id, actor_id, COALESCE(object_id,''), COALESCE(summary,''),
                            created_at, COALESCE(visibility,'public'), COALESCE(host,'')
                     FROM events
                     WHERE type = 'Create'
                       AND action_taken = ANY(ARRAY['log','local_observe'])
                       AND created_at >= $1
                       AND visibility = ANY(ARRAY['public','unlisted'])
                       AND actor_id LIKE 'https://mkultra.monster/users/%'
                     ORDER BY created_at DESC, id DESC
                     LIMIT $2",
                    &[&since, &limit],
                )
                .await
            } else {
                db.query(
                    "SELECT id, actor_id, COALESCE(object_id,''), COALESCE(summary,''),
                            created_at, COALESCE(visibility,'public'), COALESCE(host,'')
                     FROM events
                     WHERE type = 'Create'
                       AND action_taken = ANY(ARRAY['log','local_observe'])
                       AND created_at >= $1
                       AND visibility = ANY(ARRAY['public','unlisted'])
                     ORDER BY created_at DESC, id DESC
                     LIMIT $2",
                    &[&since, &limit],
                )
                .await
            };
            if let Ok(rows) = rows {
                for row in rows {
                    if state.added >= max_added {
                        break;
                    }
                    let (id, actor, object_id, summary, created, visibility, host) =
                        parse_reco_row(&row)?;
                    state.try_add(
                        id, actor, object_id, summary, created, visibility, host, 1.0, max_added,
                    );
                }
            }
        }
    } else if let Ok(rows) = db
        .query(
            "SELECT id, actor_id, COALESCE(object_id,''), COALESCE(summary,''),
                    created_at, COALESCE(visibility,'public'), COALESCE(host,'')
             FROM events
             WHERE type = 'Create'
               AND action_taken = ANY(ARRAY['log','local_observe'])
               AND created_at >= $1
               AND visibility = ANY(ARRAY['public','unlisted'])
             ORDER BY created_at DESC, id DESC
             LIMIT 160",
            &[&since],
        )
        .await
    {
        for row in rows {
            if state.added >= max_added {
                break;
            }
            let (id, actor, object_id, summary, created, visibility, host) = parse_reco_row(&row)?;
            let mut score = actor_weights.get(&actor).copied().unwrap_or(0.0);
            for tag in extract_hashtags(&summary) {
                score += tag_weights.get(&tag).copied().unwrap_or(0) as f64 * 0.5;
            }
            state.try_add(
                id, actor, object_id, summary, created, visibility, host, score, max_added,
            );
        }
    }

    if state.added > 0 {
        tracing::debug!(
            owner,
            added = state.added,
            cold_start,
            "native home recommendations added"
        );
    }
    Ok(())
}

fn apply_favourite_rank(
    timeline: &mut Vec<TimelineItem>,
    actor_weights: &HashMap<String, f64>,
    tag_weights: &HashMap<String, i32>,
) {
    if actor_weights.is_empty() && tag_weights.is_empty() {
        return;
    }
    let now = chrono::Utc::now().timestamp();
    for item in timeline.iter_mut() {
        let actor = if !item.pref_actor.is_empty() {
            item.pref_actor.as_str()
        } else {
            item.actor_id.as_str()
        };
        let count = if actor.is_empty() {
            0.0
        } else {
            *actor_weights.get(actor).unwrap_or(&0.0)
        };
        let mut tag_count = 0i32;
        for tag in extract_hashtags(&item.summary) {
            tag_count = tag_count.max(*tag_weights.get(&tag).unwrap_or(&0));
        }
        let created = item.sort;
        if (count == 0.0 && tag_count < 1)
            || created < (now - 172800)
            || created > (now + 300)
        {
            continue;
        }
        let author_bonus = if count > 0.0 {
            (600.0 * (1.0 + count).log2()).round() as i64
        } else {
            (60.0 * count).round() as i64
        };
        let author_bonus = author_bonus.max(-300);
        let tag_bonus = if tag_count > 0 {
            (300.0 * (1.0 + tag_count as f64).log2()).round() as i64
        } else {
            0
        };
        let bonus = (author_bonus + tag_bonus).clamp(-300, 1800);
        if bonus != 0 {
            item.sort = created + bonus;
        }
    }
}

/// Native Home ranked rebuild (no PHP).
pub async fn warm_home_native(cfg: &Config, owner_user_id: i64) -> Result<String> {
    let started = Instant::now();
    let db = crate::db::connect(&cfg.database_url).await?;
    let (actor_id, actor_key) = load_owner(&db, owner_user_id).await?;
    let algorithm_on = load_algorithm_enabled(&db, &actor_key).await?;
    let downranking_on = load_downranking_enabled(&db, &actor_key).await?;
    let following = load_following_actor_ids(&db, &actor_id).await?;
    let logical = cache_key_home(owner_user_id, algorithm_on, &following);
    let hidden = hidden::load_hidden_sets(&db, owner_user_id).await?;
    let ap_following = ap_following_for_events(&following);

    let mut timeline = fetch_home_events(&db, &ap_following, &actor_id, &hidden).await?;
    timeline.extend(fetch_own_outbox(&db, &actor_id).await?);

    if algorithm_on {
        // Match PHP order: recommendations → toxicity → favourite.
        let mut actor_weights = load_favourite_actor_weights(&db, owner_user_id).await;
        for (actor, weight) in load_signal_actor_weights(&db, owner_user_id).await {
            let entry = actor_weights.entry(actor).or_insert(0.0);
            *entry = (*entry + weight).min(64.0);
        }
        let tag_weights = load_favourite_tag_weights(&db, owner_user_id).await;
        if let Err(e) = apply_cached_recommendations(
            &db,
            owner_user_id,
            &actor_id,
            &following,
            &hidden,
            &mut timeline,
            &actor_weights,
            &tag_weights,
        )
        .await
        {
            tracing::warn!(
                owner = owner_user_id,
                error = %format!("{e:#}"),
                "native home recommendations skipped"
            );
        }
        if downranking_on {
            if let Err(e) = apply_toxicity_downrank(&db, owner_user_id, &mut timeline).await {
                tracing::warn!(
                    owner = owner_user_id,
                    error = %format!("{e:#}"),
                    "native home toxicity downrank skipped"
                );
            }
        }
        apply_favourite_rank(&mut timeline, &actor_weights, &tag_weights);
    }

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
        "owner={owner_user_id} view=home key={logical} ranked={} ms={ms} source=vaak-worker-native algo={} downrank={} counts={counts}",
        ranked.len(),
        if algorithm_on { "on" } else { "off" },
        if downranking_on { "on" } else { "off" },
    ))
}

/// Native Local ranked rebuild (instance outbox + boosts).
pub async fn warm_local_native(cfg: &Config, owner_user_id: i64) -> Result<String> {
    let started = Instant::now();
    let db = crate::db::connect(&cfg.database_url).await?;
    // Touch owner so we fail fast on missing users (parity with PHP bind).
    let _ = load_owner(&db, owner_user_id).await?;
    let logical = cache_key_local(owner_user_id);
    let timeline = fetch_local_timeline(&db).await?;
    let ranked = rank_from_timeline(timeline);
    if ranked.is_empty() {
        // PHP lean warm returns without writing — do not fall back to PHP.
        let ms = started.elapsed().as_millis();
        return Ok(format!(
            "owner={owner_user_id} view=local key={logical} ranked=0 ms={ms} source=vaak-worker-native skipped=empty"
        ));
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
        "owner={owner_user_id} view=local key={logical} ranked={} ms={ms} source=vaak-worker-native counts={counts}",
        ranked.len()
    ))
}

/// Native Federated ranked rebuild (firehose events + mute/block filter).
pub async fn warm_feed_native(cfg: &Config, owner_user_id: i64) -> Result<String> {
    let started = Instant::now();
    let db = crate::db::connect(&cfg.database_url).await?;
    let (actor_id, _) = load_owner(&db, owner_user_id).await?;
    let following = load_following_actor_ids(&db, &actor_id).await?;
    let logical = cache_key_feed(owner_user_id, &following);
    let hidden = hidden::load_hidden_sets(&db, owner_user_id).await?;
    let timeline = fetch_federated_events(&db, &hidden).await?;
    let ranked = rank_from_timeline(timeline);
    if ranked.is_empty() {
        let ms = started.elapsed().as_millis();
        return Ok(format!(
            "owner={owner_user_id} view=feed key={logical} ranked=0 ms={ms} source=vaak-worker-native skipped=empty"
        ));
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
        "owner={owner_user_id} view=feed key={logical} ranked={} ms={ms} source=vaak-worker-native counts={counts}",
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
            "local" if native_local_feed_enabled() => {
                match warm_local_native(cfg, owner_user_id).await {
                    Ok(line) => lines.push(line),
                    Err(e) => {
                        tracing::warn!(
                            owner = owner_user_id,
                            error = %e,
                            "native local ranked failed; falling back to PHP"
                        );
                        php_views.push("local");
                    }
                }
            }
            "local" => php_views.push("local"),
            "feed" if native_local_feed_enabled() => {
                match warm_feed_native(cfg, owner_user_id).await {
                    Ok(line) => lines.push(line),
                    Err(e) => {
                        tracing::warn!(
                            owner = owner_user_id,
                            error = %e,
                            "native feed ranked failed; falling back to PHP"
                        );
                        php_views.push("feed");
                    }
                }
            }
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
    fn local_cache_key_empty_follow_set() {
        let key = cache_key_local(1);
        assert_eq!(key, "v13_local_u1_ana_e3b0c44298fc1c149afbf4c8");
    }

    #[test]
    fn feed_cache_key_includes_following_fingerprint() {
        let key = cache_key_feed(1, &["https://example.com/users/a".into()]);
        let mut hasher = Sha256::new();
        hasher.update(b"https://example.com/users/a");
        let fp = hex::encode(hasher.finalize());
        assert_eq!(key, format!("v13_feed_u1_ana_{}", &fp[..24]));
    }

    #[test]
    fn bsky_reblog_and_import_filters() {
        assert!(reblog_is_bsky("bsky-repost-abc", ""));
        assert!(reblog_is_bsky("x", "https://bsky.app/profile/x/post/1"));
        assert!(!reblog_is_bsky("123", "https://mastodon.social/users/a/statuses/1"));
        assert!(outbox_is_bsky_import(
            r#"{"object":{"vaakOrigin":"bluesky","content":"hi"}}"#
        ));
        assert!(!outbox_is_bsky_import(r#"{"object":{"content":"hi"}}"#));
    }

    #[test]
    fn hashtag_and_phrase_match() {
        assert_eq!(extract_hashtags("Hello #Fediverse and #Art!"), vec!["fediverse", "art"]);
        assert!(phrase_matches_text("you are a retard for this", "retard"));
        assert!(!phrase_matches_text("tagged #retard in bio", "retard"));
        assert!(phrase_matches_text("please kill yourself now", "kill yourself"));
    }

    #[test]
    fn favourite_bonus_bounds() {
        let mut items = vec![TimelineItem::event(
            "1".into(),
            chrono::Utc::now().timestamp() - 60,
            "https://example.com/u/a".into(),
            "https://example.com/u/a".into(),
            "hi #art".into(),
            "public".into(),
            String::new(),
            "Create".into(),
            "fediverse",
        )];
        let mut actors = HashMap::new();
        actors.insert("https://example.com/u/a".into(), 8.0);
        let mut tags = HashMap::new();
        tags.insert("art".into(), 4);
        let before = items[0].sort;
        apply_favourite_rank(&mut items, &actors, &tags);
        assert!(items[0].sort > before);
        assert!(items[0].sort - before <= 1800);
    }

    #[test]
    fn php_crc32_known_vector() {
        // PHP: sprintf('%u', crc32('1|2026-10-04-16')) → 3395297329
        assert_eq!(php_crc32(b"1|2026-10-04-16"), 3_395_297_329);
        assert_eq!(php_crc32(b"123456789"), 3_421_780_262);
    }
}
