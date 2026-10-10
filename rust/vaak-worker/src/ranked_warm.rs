//! Home / Local / Federated **ranked** warm (loading-plan slice 2 → native).
//!
//! - **Home:** rebuilds `vaak:timeline:ranked:v2:{sha256(logical)}` + owner
//!   index from Postgres (follow events, own outbox, FoF/cold-start
//!   recommendations, favourite/toxicity nudges when algorithm on, Bluesky
//!   merge, RSS spacing). Source `vaak-worker-native`. Flag
//!   `VAAK_RANKED_NATIVE_HOME=1` (default on; `0` skips Home warm).
//! - After a non-empty Home/Local/Federated ranked write, fire-and-forget Rust
//!   ranked→hydrate (`home_hydrate_ranked`) so Axum HTML hits `vaak:timeline:v1:*`
//!   (Home 0.7.15; Local/Federated 0.7.20). Cooldown ~60s; disable with
//!   `VAAK_HOME_HYDRATE_WARM=0` / `VAAK_PUBLIC_HYDRATE_WARM=0`.
//! - **Local / Federated:** outbox+local boosts / firehose events with v13 key
//!   parity. Flag `VAAK_RANKED_NATIVE_LOCAL_FEED=1` (default on; `0` skips).
//! - Empty timelines soft-skip (no Redis write). PHP `bin/ranked-warm.php` is
//!   retired; HTML soft-nav still uses in-process `admin_tl_lean_ranked_warm`.
//!
//! Cache key parity with PHP `admin_tl_cache_key` (v13).

use std::collections::{HashMap, HashSet};
use std::time::{Duration, Instant};

use anyhow::{bail, Context, Result};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use tokio_postgres::Client;

use crate::config::Config;
use crate::hidden;
use crate::home_hydrate_ranked;
use crate::notif;
use crate::redis_util;

const CACHE_VERSION: &str = "v13";
// Home mix guardrails. Followed accounts on both networks all contribute
// their recent posts (PER_ACTOR_CAP). RSS stays a smaller later-page share.
// Ranking, hashtags, the federated floor, and blocks still apply.
const HOME_RSS_MAX_RATIO: f64 = 0.28;
const HOME_FOLLOWED_TAG_CAP: usize = 72;
const HOME_TTL_SECS: u64 = 600;
/// When a follow graph has fewer than this many posts from the last two days,
/// fill the rest with recent public federated posts.
const HOME_FLOOR_MIN_FRESH: usize = 40;
const HOME_FLOOR_FRESH_SECS: i64 = 48 * 3600;
const HOME_FLOOR_TARGET: usize = 80;
const HOME_FLOOR_PER_ACTOR: usize = 2;
const HOME_FLOOR_PER_HOST: usize = 3;
/// Own outbox rows older than this are history, not a Home head.
const HOME_OWN_MAX_AGE_SECS: i64 = 7 * 24 * 3600;
/// Deep Home scroll head (0.7.25). Was 160 — scrolling past ~100–200 hit End of timeline.
/// Local and Federated only. Home uses HOME_MAX_TIMELINE so followed posts are not sampled away.
const MAX_TIMELINE: usize = 400;
/// Every followed account's recent posts, plus hashtags, the floor, and own notes.
/// Fan-out uses the same ceiling so a new post does not chop the follow set back down.
pub(crate) const HOME_MAX_TIMELINE: usize = 16_000;
const PER_ACTOR_CAP: usize = 25;

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

/// After ranked Home write: materialize hydrate from ranked (RSS/Bluesky/fedi).
async fn maybe_spawn_home_hydrate_warm(
    redis: &mut redis::aio::MultiplexedConnection,
    cfg: &Config,
    owner_user_id: i64,
) -> String {
    home_hydrate_ranked::maybe_warm_after_ranked(redis, cfg, owner_user_id).await
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

/// Weights the Home rebuild already computed. Live fan-out reads this so a
/// new post is inserted with the same author bonus the next rebuild would use.
pub(crate) fn home_rank_weights_key(owner: i64) -> String {
    format!("vaak:home:rank-weights:v1:{}", owner.max(0))
}

/// Author/tag nudge shared by the Home rebuild and the live fan-out insert.
/// Clamped to the same −300..=1800 seconds as the favourite pass.
pub(crate) fn affinity_bonus(actor_weight: f64, tag_count: i32) -> i64 {
    let author_bonus = if actor_weight > 0.0 {
        (600.0 * (1.0 + actor_weight).log2()).round() as i64
    } else {
        (60.0 * actor_weight).round() as i64
    };
    let author_bonus = author_bonus.max(-300);
    let tag_bonus = if tag_count > 0 {
        (300.0 * (1.0 + tag_count as f64).log2()).round() as i64
    } else {
        0
    };
    (author_bonus + tag_bonus).clamp(-300, 1800)
}

/// Recommendation freshness bump. Capped at 30 minutes so a cold
/// recommendation cannot sit above a follow from the last half hour.
pub(crate) fn recommendation_bump(score: f64) -> i64 {
    ((1800.0 * (1.0 + score.max(0.0)).log2()).round() as i64).min(1800)
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

async fn load_followed_tags(db: &Client, owner: i64) -> Vec<String> {
    if owner < 1 {
        return Vec::new();
    }
    let rows = db
        .query(
            "SELECT name FROM masto_followed_tags
             WHERE owner_user_id = $1 ORDER BY followed_at DESC LIMIT 128",
            &[&owner],
        )
        .await
        .unwrap_or_default();
    let mut tags = Vec::new();
    let mut seen = HashSet::new();
    for row in rows {
        let tag: String = row
            .try_get::<_, Option<String>>(0)
            .ok()
            .flatten()
            .unwrap_or_default()
            .trim()
            .trim_start_matches('#')
            .to_ascii_lowercase();
        if !tag.is_empty() && seen.insert(tag.clone()) {
            tags.push(tag);
        }
    }
    tags
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
        }
    }
    if !self_actor.is_empty() {
        let s = self_actor.trim_end_matches('/');
        if seen.insert(s.to_string()) {
            actor_ids.push(s.to_string());
        }
    }
    if actor_ids.is_empty() {
        return Ok(Vec::new());
    }

    let mut items = Vec::new();
    let mut per_actor: HashMap<String, usize> = HashMap::new();
    let per_actor_limit = PER_ACTOR_CAP as i64;
    // One global LIMIT across a chunk kept the loudest accounts and dropped
    // everyone else. Each follow gets its own recent page instead.
    for chunk in actor_ids.chunks(80) {
        let rows = db
            .query(
                "SELECT e.id, e.type, e.actor_id, e.summary, e.media_urls, e.created_at,
                        e.visibility, e.object_id, e.target_actor
                 FROM unnest($1::text[]) AS f(actor_id)
                 JOIN LATERAL (
                   SELECT id, type, actor_id, summary, media_urls, created_at, visibility,
                          COALESCE(object_id, '') AS object_id,
                          COALESCE(target_actor, '') AS target_actor
                   FROM events
                   WHERE actor_id IN (f.actor_id, f.actor_id || '/')
                     AND type = ANY(ARRAY['Create','Announce','Quote','QuotePost'])
                     AND action_taken = ANY(ARRAY['log','local_observe'])
                     AND (
                       type <> 'Announce'
                       OR COALESCE(summary, '') <> ''
                       OR COALESCE(media_urls, '') NOT IN ('', '[]')
                       OR (
                         COALESCE(object_id, '') <> ''
                         AND EXISTS (
                           SELECT 1 FROM events original
                           WHERE original.type = 'Create'
                             AND original.action_taken = ANY(ARRAY['log','local_observe'])
                             AND original.object_id = events.object_id
                         )
                       )
                     )
                   ORDER BY created_at DESC, id DESC
                   LIMIT $2
                 ) e ON true",
                &[&chunk, &per_actor_limit],
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
            // A boost is still hidden when the boosted author is blocked or
            // muted, even if the booster themselves is followed. PHP applies
            // this rule to the underlying object; do the same before ranking.
            if event_type.eq_ignore_ascii_case("announce")
                && !target_actor.is_empty()
                && hidden.is_hidden(&target_actor)
            {
                continue;
            }
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

/// Add recent cached Fediverse events matching the user's followed hashtags.
/// This is deliberately bounded and cache-only; it never performs remote work.
async fn fetch_followed_tag_events(
    db: &Client,
    tags: &[String],
    hidden: &hidden::HiddenSets,
) -> Result<Vec<TimelineItem>> {
    if tags.is_empty() {
        return Ok(Vec::new());
    }
    let since = (chrono::Utc::now() - chrono::Duration::days(14)).to_rfc3339();
    let rows = db
        .query(
            "SELECT id, type, actor_id, summary, media_urls, created_at, visibility,
                    COALESCE(object_id, ''), COALESCE(target_actor, '')
             FROM events
             WHERE type = ANY(ARRAY['Create','Announce','Quote','QuotePost'])
               AND action_taken = ANY(ARRAY['log','local_observe'])
               AND created_at >= $1
               AND (
                 type <> 'Announce'
                 OR COALESCE(summary, '') <> ''
                 OR COALESCE(media_urls, '') NOT IN ('', '[]')
                 OR (
                   COALESCE(object_id, '') <> ''
                   AND EXISTS (
                     SELECT 1 FROM events original
                     WHERE original.type = 'Create'
                       AND original.action_taken = ANY(ARRAY['log','local_observe'])
                       AND original.object_id = events.object_id
                   )
                 )
               )
             ORDER BY created_at DESC, id DESC
             LIMIT 600",
            &[&since],
        )
        .await
        .context("select followed hashtag events")?;
    let wanted: HashSet<&str> = tags.iter().map(String::as_str).collect();
    let mut out = Vec::new();
    for row in rows {
        if out.len() >= HOME_FOLLOWED_TAG_CAP {
            break;
        }
        let id: i64 = row.get(0);
        let event_type: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        let actor = row
            .try_get::<_, Option<String>>(2)?
            .unwrap_or_default()
            .trim_end_matches('/')
            .to_string();
        if actor.is_empty() || hidden.is_hidden(&actor) {
            continue;
        }
        let summary = row.try_get::<_, Option<String>>(3)?.unwrap_or_default();
        if !extract_hashtags(&summary)
            .iter()
            .any(|tag| wanted.contains(tag.as_str()))
        {
            continue;
        }
        let media = row.try_get::<_, Option<String>>(4)?.unwrap_or_default();
        let created = row.try_get::<_, Option<String>>(5)?.unwrap_or_default();
        let visibility = row.try_get::<_, Option<String>>(6)?.unwrap_or_default();
        if !matches!(visibility.as_str(), "public" | "unlisted" | "") {
            continue;
        }
        let object_id = row
            .try_get::<_, Option<String>>(7)?
            .unwrap_or_default()
            .trim_end_matches('/')
            .to_string();
        let target_actor = row
            .try_get::<_, Option<String>>(8)?
            .unwrap_or_default()
            .trim_end_matches('/')
            .to_string();
        if event_type.eq_ignore_ascii_case("announce")
            && !target_actor.is_empty()
            && hidden.is_hidden(&target_actor)
        {
            continue;
        }
        let pref = if event_type.eq_ignore_ascii_case("announce") && !target_actor.is_empty() {
            target_actor
        } else {
            actor.clone()
        };
        out.push(TimelineItem::event(
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
        let _ = media; // Media is hydrated from the canonical event row later.
    }
    Ok(out)
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
    let own_cutoff = chrono::Utc::now().timestamp() - HOME_OWN_MAX_AGE_SECS;
    items.retain(|item| item.sort >= own_cutoff);
    Ok(items)
}

/// Recent public posts from other servers. Used when this account's follows
/// have not posted enough in the last two days, so Home does not freeze on
/// one local account or on the viewer's own old notes.
async fn fetch_federated_floor(
    db: &Client,
    hidden: &hidden::HiddenSets,
) -> Result<Vec<TimelineItem>> {
    let since = (chrono::Utc::now() - chrono::Duration::hours(48)).to_rfc3339();
    let rows = db
        .query(
            "SELECT id, actor_id, COALESCE(object_id,''), COALESCE(summary,''),
                    created_at, COALESCE(visibility,'public'), COALESCE(host,'')
             FROM events
             WHERE type = 'Create'
               AND action_taken = ANY(ARRAY['log','local_observe'])
               AND created_at >= $1
               AND visibility = ANY(ARRAY['public','unlisted'])
               AND actor_id NOT LIKE 'https://mkultra.monster/users/%'
             ORDER BY created_at DESC, id DESC
             LIMIT 500",
            &[&since],
        )
        .await
        .context("select federated home floor")?;
    let mut out = Vec::new();
    let mut per_actor: HashMap<String, usize> = HashMap::new();
    let mut per_host: HashMap<String, usize> = HashMap::new();
    for row in rows {
        if out.len() >= HOME_FLOOR_TARGET {
            break;
        }
        let (id, actor, object_id, summary, created, visibility, host) = parse_reco_row(&row)?;
        if actor.is_empty() || hidden.is_hidden(&actor) {
            continue;
        }
        let host = if host.is_empty() {
            host_of_actor(&actor)
        } else {
            host.to_ascii_lowercase()
        };
        if *per_actor.get(&actor).unwrap_or(&0) >= HOME_FLOOR_PER_ACTOR {
            continue;
        }
        if !host.is_empty() && *per_host.get(&host).unwrap_or(&0) >= HOME_FLOOR_PER_HOST {
            continue;
        }
        *per_actor.entry(actor.clone()).or_insert(0) += 1;
        if !host.is_empty() {
            *per_host.entry(host).or_insert(0) += 1;
        }
        out.push(TimelineItem::event(
            id.to_string(),
            parse_ts(&created),
            actor.clone(),
            actor,
            summary,
            visibility,
            object_id,
            "Create".into(),
            "federated",
        ));
    }
    Ok(out)
}

fn rank_from_timeline(items: Vec<TimelineItem>) -> Vec<Value> {
    rank_from_timeline_capped(items, Some(MAX_TIMELINE))
}

fn rank_from_timeline_capped(mut items: Vec<TimelineItem>, max_items: Option<usize>) -> Vec<Value> {
    items.sort_by(|a, b| b.sort.cmp(&a.sort));
    if let Some(max_items) = max_items {
        if items.len() > max_items {
            items.truncate(max_items);
        }
    }
    // Quote-boost dual publish: same actor Create/Quote + Announce of one
    // object_id → keep the Create/Quote card, drop the self-Announce.
    let mut create_keys: HashSet<String> = HashSet::new();
    for it in &items {
        if it.kind != "event" {
            continue;
        }
        let et = it.event_type.to_ascii_lowercase();
        if !matches!(et.as_str(), "create" | "quote" | "quotepost" | "update") {
            continue;
        }
        let actor = it.actor_id.trim_end_matches('/');
        let oid = it.object_id.trim_end_matches('/');
        if actor.is_empty() || oid.is_empty() {
            continue;
        }
        create_keys.insert(format!("{actor}\n{oid}"));
        if let Some(parent) = oid.strip_suffix("/QuotePost") {
            let parent = parent.trim_end_matches('/');
            if !parent.is_empty() {
                create_keys.insert(format!("{actor}\n{parent}"));
            }
        }
    }
    let mut out = Vec::new();
    let mut seen = HashSet::new();
    for it in items {
        if it.kind == "event"
            && it.event_type.eq_ignore_ascii_case("announce")
            && !create_keys.is_empty()
        {
            let actor = it.actor_id.trim_end_matches('/');
            let oid = it.object_id.trim_end_matches('/');
            if !actor.is_empty() && !oid.is_empty() {
                let key = format!("{actor}\n{oid}");
                if create_keys.contains(&key) {
                    continue;
                }
                if let Some(parent) = oid.strip_suffix("/QuotePost") {
                    let parent = parent.trim_end_matches('/');
                    if !parent.is_empty()
                        && create_keys.contains(&format!("{actor}\n{parent}"))
                    {
                        continue;
                    }
                }
            }
        }
        let dedupe = format!("{}:{}", it.kind, it.id.trim_end_matches('/'));
        if !seen.insert(dedupe) {
            continue;
        }
        let mut entry = serde_json::Map::new();
        entry.insert("k".into(), json!(it.kind));
        entry.insert("id".into(), json!(it.id));
        entry.insert("s".into(), json!(it.source));
        // `sort` is the placement the poller and the live insert both read.
        // `o` is the object identity used to collapse event/outbox/Bluesky twins.
        entry.insert("sort".into(), json!(it.sort));
        let object = it.object_id.trim().trim_end_matches('/');
        if !object.is_empty() {
            entry.insert("o".into(), json!(object));
        }
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

struct BskyKey {
    uri: String,
    fedi: String,
    author_did: String,
    author_handle: String,
    text: String,
    /// Top-level external URL or YouTube id. Empty for quotes and plain text.
    external: String,
    indexed_at: i64,
    sort: i64,
}

/// Hint for `bsky_external_repeat_token`. Only a top-level `embed.external`
/// counts: `embed.record` (and `recordWithMedia`, whose type contains
/// `embed.record`) must stay so a quoted YouTube URL does not drop the comment.
/// The CASE looks at the type prefix; the substring then reads the full embed.
const BSKY_EXTERNAL_HINT_SQL: &str = r#"COALESCE(CASE
  WHEN left(COALESCE(p.embed_json, ''), 180) LIKE '%embed.external%'
   AND left(COALESCE(p.embed_json, ''), 180) NOT LIKE '%embed.record%'
  THEN COALESCE(
    substring(p.embed_json from '(?i)youtube\.com/watch\?v=([A-Za-z0-9_-]{6,20})'),
    substring(p.embed_json from '(?i)youtu\.be/([A-Za-z0-9_-]{6,20})'),
    substring(p.embed_json from '(?i)youtube\.com/shorts/([A-Za-z0-9_-]{6,20})'),
    substring(p.embed_json from '(?i)youtube\.com/embed/([A-Za-z0-9_-]{6,20})'),
    substring(p.embed_json from '(?i)youtube-nocookie\.com/embed/([A-Za-z0-9_-]{6,20})'),
    substring(p.embed_json from '"uri"[[:space:]]*:[[:space:]]*"(https?://[^"]+)"')
  )
  ELSE ''
END, '')"#;

fn bsky_key_from_row(row: &tokio_postgres::Row) -> Result<Option<BskyKey>> {
    let uri: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
    if !uri.starts_with("at://") {
        return Ok(None);
    }
    let fedi: String = row
        .try_get::<_, Option<String>>(1)?
        .unwrap_or_default()
        .trim()
        .trim_end_matches('/')
        .to_string();
    let author_did: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
    let author_handle: String = row.try_get::<_, Option<String>>(3)?.unwrap_or_default();
    let text: String = row.try_get::<_, Option<String>>(4)?.unwrap_or_default();
    let indexed_raw: String = row.try_get::<_, Option<String>>(5)?.unwrap_or_default();
    let external: String = row.try_get::<_, Option<String>>(6)?.unwrap_or_default();
    Ok(Some(BskyKey {
        uri,
        fedi,
        author_did,
        author_handle,
        text,
        external,
        indexed_at: parse_ts(&indexed_raw),
        sort: 0,
    }))
}

async fn fetch_bsky_keys(
    db: &Client,
    owner: i64,
    exclude_did: Option<&str>,
) -> Result<Vec<BskyKey>> {
    let per_actor = PER_ACTOR_CAP as i64;
    // Home Bluesky is the owner's follow graph. bsky_post_observations also
    // records suggested posts, and those were taking the recent slots on
    // accounts with a smaller follow list. Read the newest posts per followed
    // DID. There is no global LIMIT: a 120-row cap dropped the rest of the graph.
    let rows = if let Some(did) = exclude_did.filter(|d| d.starts_with("did:")) {
        let sql = format!(
            "SELECT p.bsky_uri, COALESCE(l.fediverse_id, ''), COALESCE(p.author_did, ''),
                    COALESCE(p.author_handle, ''), COALESCE(p.text, ''),
                    COALESCE(p.indexed_at::text, ''), {BSKY_EXTERNAL_HINT_SQL}
             FROM bsky_graph_sync g
             JOIN LATERAL (
               SELECT bsky_uri, author_did, author_handle, text, indexed_at, updated_at, embed_json
               FROM bsky_posts
               WHERE author_did = g.target_did
                 AND text IS NOT NULL
               ORDER BY indexed_at DESC
               LIMIT $3
             ) p ON true
             LEFT JOIN bsky_post_links l ON l.bsky_uri = p.bsky_uri
             WHERE g.owner_user_id = $1
               AND g.kind = 'follow'
               AND g.target_did <> $2
             ORDER BY p.indexed_at DESC, p.updated_at DESC"
        );
        db.query(&sql, &[&owner, &did, &per_actor]).await
    } else {
        let sql = format!(
            "SELECT p.bsky_uri, COALESCE(l.fediverse_id, ''), COALESCE(p.author_did, ''),
                    COALESCE(p.author_handle, ''), COALESCE(p.text, ''),
                    COALESCE(p.indexed_at::text, ''), {BSKY_EXTERNAL_HINT_SQL}
             FROM bsky_graph_sync g
             JOIN LATERAL (
               SELECT bsky_uri, author_did, author_handle, text, indexed_at, updated_at, embed_json
               FROM bsky_posts
               WHERE author_did = g.target_did
                 AND text IS NOT NULL
               ORDER BY indexed_at DESC
               LIMIT $2
             ) p ON true
             LEFT JOIN bsky_post_links l ON l.bsky_uri = p.bsky_uri
             WHERE g.owner_user_id = $1
               AND g.kind = 'follow'
             ORDER BY p.indexed_at DESC, p.updated_at DESC"
        );
        db.query(&sql, &[&owner, &per_actor]).await
    }
    .context("select bsky home rank keys")?;
    let mut out = Vec::new();
    for row in rows {
        if let Some(key) = bsky_key_from_row(&row)? {
            out.push(key);
        }
    }
    Ok(out)
}

async fn fetch_bsky_followed_tag_keys(
    db: &Client,
    owner: i64,
    exclude_did: Option<&str>,
    tags: &[String],
) -> Result<Vec<BskyKey>> {
    if owner < 1 || tags.is_empty() {
        return Ok(Vec::new());
    }
    let sql = format!(
        "SELECT p.bsky_uri, COALESCE(l.fediverse_id, ''), COALESCE(p.author_did, ''),
                COALESCE(p.author_handle, ''), COALESCE(p.text, ''),
                COALESCE(p.indexed_at::text, ''), {BSKY_EXTERNAL_HINT_SQL}
         FROM bsky_posts p
         LEFT JOIN bsky_post_links l ON l.bsky_uri = p.bsky_uri
         WHERE EXISTS (
           SELECT 1 FROM bsky_post_observations o
           WHERE o.bsky_uri = p.bsky_uri AND o.owner_user_id = $1
         )
           AND p.text IS NOT NULL
           AND p.indexed_at::timestamptz >= NOW() - INTERVAL '14 days'
         ORDER BY p.indexed_at DESC, p.updated_at DESC
         LIMIT 400"
    );
    let rows = db
        .query(&sql, &[&owner])
        .await
        .context("select bsky followed hashtag keys")?;
    let wanted: HashSet<&str> = tags.iter().map(String::as_str).collect();
    let mut out = Vec::new();
    for row in rows {
        let Some(key) = bsky_key_from_row(&row)? else {
            continue;
        };
        if exclude_did.is_some_and(|excluded| key.author_did == excluded)
            || !extract_hashtags(&key.text)
                .iter()
                .any(|tag| wanted.contains(tag.as_str()))
        {
            continue;
        }
        out.push(key);
    }
    Ok(out)
}

fn bsky_actor_weight(key: &BskyKey, actor_weights: &HashMap<String, f64>) -> f64 {
    let mut actor = actor_weights.get(&key.author_did).copied().unwrap_or(0.0);
    if !key.author_handle.is_empty() {
        actor = actor.max(
            actor_weights
                .get(&format!("https://bsky.app/profile/{}", key.author_handle))
                .copied()
                .unwrap_or(0.0),
        );
    }
    if key.author_did.starts_with("did:") {
        actor = actor.max(
            actor_weights
                .get(&format!("https://bsky.app/profile/{}", key.author_did))
                .copied()
                .unwrap_or(0.0),
        );
    }
    actor
}

fn bsky_tag_count(key: &BskyKey, tag_weights: &HashMap<String, i32>) -> i32 {
    extract_hashtags(&key.text)
        .iter()
        .map(|tag| *tag_weights.get(tag).unwrap_or(&0))
        .max()
        .unwrap_or(0)
}

/// Indexed time plus the same capped author/tag bonus follows get.
/// Empty weights leave the SQL `indexed_at` order alone.
fn stamp_bsky_sort(
    keys: &mut [BskyKey],
    actor_weights: &HashMap<String, f64>,
    tag_weights: &HashMap<String, i32>,
) {
    let rank = !actor_weights.is_empty() || !tag_weights.is_empty();
    let now = chrono::Utc::now().timestamp();
    for key in keys.iter_mut() {
        let created = key.indexed_at;
        if !rank || created <= 0 {
            key.sort = created.max(0);
            continue;
        }
        let count = bsky_actor_weight(key, actor_weights);
        let tag_count = bsky_tag_count(key, tag_weights);
        if (count == 0.0 && tag_count < 1) || created < (now - 172800) || created > (now + 300) {
            key.sort = created;
            continue;
        }
        key.sort = created + affinity_bonus(count, tag_count);
    }
    if rank {
        keys.sort_by(|a, b| b.sort.cmp(&a.sort).then(b.indexed_at.cmp(&a.indexed_at)));
    }
}

/// Drop a later post when the same author already contributed the same
/// external link. Call after `stamp_bsky_sort` so the higher-ranked copy stays.
/// The SQL per-author LIMIT can still spend a slot on the duplicate; that is
/// cheaper than letting Home paint the same video twice.
fn collapse_repeated_external_posts(keys: &mut Vec<BskyKey>) {
    let mut seen = HashSet::new();
    keys.retain(|key| {
        let Some(token) =
            home_hydrate_ranked::bsky_external_repeat_token(&key.author_did, &key.external)
        else {
            return true;
        };
        seen.insert(token)
    });
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

fn ranked_row_sort(row: &Value) -> i64 {
    row.get("sort").and_then(|v| v.as_i64()).unwrap_or(0)
}

fn merge_bsky_ranked(
    ranked: Vec<Value>,
    bsky: &[BskyKey],
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
        if let Some(object) = row.get("o").and_then(|v| v.as_str()) {
            let object = object.trim().trim_end_matches('/');
            if !object.is_empty() {
                seen_fedi.insert(object.to_string());
            }
        }
    }
    let mut queued: Vec<Value> = Vec::new();
    for key in bsky {
        if seen_bsky.contains(&key.uri) {
            continue;
        }
        if !key.fedi.is_empty() && seen_fedi.contains(&key.fedi) {
            continue;
        }
        if bsky_author_hidden(hidden, &key.author_did) {
            continue;
        }
        let mut entry = json!({
            "k": "bsky",
            "id": key.uri,
            "s": "bluesky",
            "sort": key.sort,
        });
        if !key.fedi.is_empty() {
            entry["o"] = json!(key.fedi);
        }
        queued.push(entry);
        seen_bsky.insert(key.uri.clone());
        if !key.fedi.is_empty() {
            seen_fedi.insert(key.fedi.clone());
        }
    }
    if queued.is_empty() {
        return ranked;
    }
    if ranked.is_empty() {
        return queued;
    }
    // Higher placement sort wins. Both follow graphs are kept; a share cap
    // used to drop Bluesky once it passed ~60% of the list.
    let mut out = Vec::with_capacity(ranked.len() + queued.len());
    let mut fi = 0usize;
    let mut bi = 0usize;
    while fi < ranked.len() && bi < queued.len() {
        if ranked_row_sort(&queued[bi]) >= ranked_row_sort(&ranked[fi]) {
            out.push(queued[bi].clone());
            bi += 1;
        } else {
            out.push(ranked[fi].clone());
            fi += 1;
        }
    }
    out.extend(queued.iter().skip(bi).cloned());
    out.extend(ranked.iter().skip(fi).cloned());
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
        if (*rss_emitted + 1) as f64 / (tail_emitted + *rss_emitted + 1) as f64 > HOME_RSS_MAX_RATIO {
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
        return queued.into_iter().take(24).collect();
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

async fn write_home_rank_weights(
    redis: &mut redis::aio::MultiplexedConnection,
    owner: i64,
    algorithm_on: bool,
    actor_weights: &HashMap<String, f64>,
    tag_weights: &HashMap<String, i32>,
    deprioritized: &HashSet<String>,
) -> Result<()> {
    let mut deprioritized_actors: Vec<_> = deprioritized.iter().cloned().collect();
    deprioritized_actors.sort();
    let payload = json!({
        "algorithm": algorithm_on,
        "actors": serde_json::to_value(actor_weights).unwrap_or_else(|_| json!({})),
        "tags": serde_json::to_value(tag_weights).unwrap_or_else(|_| json!({})),
        "deprioritized": deprioritized_actors,
    });
    redis_util::json_set(
        redis,
        &home_rank_weights_key(owner),
        &payload,
        HOME_TTL_SECS,
    )
    .await?;
    Ok(())
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
               AND COALESCE(visibility, 'public') = 'public'
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
               AND COALESCE(visibility, 'public') = 'public'
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

/// Letters, numbers, and underscores keep going as one token.
/// Hyphens and apostrophes still separate words, matching PHP
/// `admin_home_phrase_matches_text`.
fn phrase_char_continues_word(ch: char) -> bool {
    ch.is_alphabetic() || ch.is_numeric() || ch == '_'
}

fn char_eq_fold(a: char, b: char) -> bool {
    a == b || a.eq_ignore_ascii_case(&b) || a.to_lowercase().eq(b.to_lowercase())
}

fn strip_tags_keep_case(text: &str) -> String {
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
    out
}

/// Drop a complete hashtag token. The `#` has to start a token, same as PHP.
fn strip_hashtags_for_downrank(text: &str) -> String {
    let chars: Vec<char> = text.chars().collect();
    let mut out = String::new();
    let mut i = 0usize;
    while i < chars.len() {
        if chars[i] == '#' {
            let boundary = i == 0 || !phrase_char_continues_word(chars[i - 1]);
            if boundary {
                let mut j = i + 1;
                while j < chars.len() && phrase_char_continues_word(chars[j]) {
                    j += 1;
                }
                if j > i + 1 {
                    out.push(' ');
                    i = j;
                    continue;
                }
            }
        }
        out.push(chars[i]);
        i += 1;
    }
    out
}

fn phrase_matches_text(text: &str, phrase: &str) -> bool {
    let phrase = {
        let p = strip_tags_keep_case(phrase);
        p.split_whitespace().collect::<Vec<_>>().join(" ")
    };
    if phrase.is_empty() {
        return false;
    }
    // The regex crate cannot compile look-around, and the old fallback was
    // `contains`, which treated a listed word as a hit inside a longer word.
    // Scan for the whole phrase with token boundaries instead.
    let phrase_chars: Vec<char> = phrase.chars().collect();
    let search_chars: Vec<char> = strip_hashtags_for_downrank(&strip_tags_keep_case(text))
        .chars()
        .collect();
    let n = phrase_chars.len();
    if n == 0 || n > search_chars.len() {
        return false;
    }
    for i in 0..=search_chars.len() - n {
        let window = &search_chars[i..i + n];
        if !window
            .iter()
            .zip(phrase_chars.iter())
            .all(|(got, want)| char_eq_fold(*got, *want))
        {
            continue;
        }
        let before_ok = i == 0 || !phrase_char_continues_word(search_chars[i - 1]);
        let after = i + n;
        let after_ok = after == search_chars.len() || !phrase_char_continues_word(search_chars[after]);
        if before_ok && after_ok {
            return true;
        }
    }
    false
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

async fn load_deprioritized_actors(db: &Client, owner: i64) -> HashSet<String> {
    if owner < 1 {
        return HashSet::new();
    }
    let rows = db
        .query(
            "SELECT actor_id FROM ap_deprioritized_actors WHERE owner_user_id = $1",
            &[&owner],
        )
        .await
        .unwrap_or_default();
    rows.into_iter()
        .filter_map(|row| row.try_get::<_, Option<String>>(0).ok().flatten())
        .map(|actor| actor.trim().trim_end_matches('/').to_ascii_lowercase())
        .filter(|actor| !actor.is_empty())
        .collect()
}

/// PHP parity: Home-only Deprioritize shifts an author's sort timestamp back
/// six hours. It remains a soft rank adjustment; Local/Federated are untouched.
fn apply_deprioritized_rank(timeline: &mut [TimelineItem], actors: &HashSet<String>) {
    if actors.is_empty() {
        return;
    }
    const PENALTY_SECS: i64 = 6 * 3600;
    for item in timeline.iter_mut() {
        let actor = item
            .pref_actor
            .as_str()
            .trim()
            .trim_end_matches('/')
            .to_ascii_lowercase();
        if !actor.is_empty() && actors.contains(&actor) {
            item.sort -= PENALTY_SECS;
        }
    }
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

/// Local mkultra.monster accounts (and admin cmdr_nova) never enter temporary
/// Home downranking — mirrors PHP `admin_home_downrank_actor_exempt`.
fn downrank_actor_exempt(actor: &str) -> bool {
    let actor = actor.trim().trim_end_matches('/');
    if actor.is_empty() {
        return true;
    }
    let lower = actor.to_ascii_lowercase();
    if lower.starts_with("https://mkultra.monster/users/")
        || lower.starts_with("https://mkultra.monster/@")
    {
        return true;
    }
    if lower == "cmdr_nova" || lower == "cmdr_nova@mkultra.monster" {
        return true;
    }
    // Path/handle forms even if host shape drifts.
    let path = lower.rsplit('/').next().unwrap_or("");
    path == "cmdr_nova" || path == "@cmdr_nova"
}

async fn record_suppression(
    db: &Client,
    owner: i64,
    actor: &str,
    categories: &[String],
    object_id: &str,
) -> Result<bool> {
    let actor = actor.trim().trim_end_matches('/');
    if owner < 1 || actor.is_empty() || categories.is_empty() {
        return Ok(false);
    }
    if downrank_actor_exempt(actor) {
        return Ok(false);
    }
    let now = chrono::Utc::now().timestamp();
    let existing = db
        .query_opt(
            "SELECT score, suppressed_until, categories_json, seen_object_ids_json, last_object_id
             FROM ap_home_suppression WHERE owner_user_id = $1 AND actor_id = $2",
            &[&owner, &actor],
        )
        .await
        .ok()
        .flatten();
    let (mut score, previous_until, mut all_cats, mut seen_objects) = if let Some(row) = existing {
        let score: i32 = row.try_get::<_, Option<i32>>(0)?.unwrap_or(0).max(0);
        let until_s: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        let cats_raw: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
        let cats: Vec<String> = serde_json::from_str(&cats_raw).unwrap_or_default();
        let seen_raw: String = row.try_get::<_, Option<String>>(3)?.unwrap_or_default();
        let mut seen: Vec<String> = serde_json::from_str(&seen_raw).unwrap_or_default();
        if seen.is_empty() {
            let last_object: String = row.try_get::<_, Option<String>>(4)?.unwrap_or_default();
            let last_object = last_object.trim().trim_end_matches('/').to_string();
            if !last_object.is_empty() {
                seen.push(last_object);
            }
        }
        (score, parse_ts(&until_s), cats, seen)
    } else {
        (0, 0, Vec::new(), Vec::new())
    };
    let object = object_id
        .trim()
        .trim_end_matches('/')
        .chars()
        .take(2048)
        .collect::<String>();
    if !object.is_empty() && seen_objects.iter().any(|seen| seen == &object) {
        return Ok(false);
    }
    if !object.is_empty() {
        seen_objects.push(object.clone());
        seen_objects.sort();
        seen_objects.dedup();
        if seen_objects.len() > 256 {
            let keep_from = seen_objects.len() - 256;
            seen_objects = seen_objects.split_off(keep_from);
        }
    }
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
    let seen_json = serde_json::to_string(&seen_objects).unwrap_or_else(|_| "[]".into());
    let until_s = chrono::DateTime::from_timestamp(until, 0)
        .map(|dt| dt.to_rfc3339())
        .unwrap_or_default();
    let now_s = chrono::Utc::now().to_rfc3339();
    let _ = db
        .execute(
            "INSERT INTO ap_home_suppression
             (owner_user_id, actor_id, score, categories_json, suppressed_until, last_object_id, seen_object_ids_json, updated_at)
             VALUES ($1,$2,$3,$4,$5,$6,$7,$8)
             ON CONFLICT (owner_user_id, actor_id) DO UPDATE SET
               score = EXCLUDED.score,
               categories_json = EXCLUDED.categories_json,
               suppressed_until = EXCLUDED.suppressed_until,
               last_object_id = EXCLUDED.last_object_id,
               seen_object_ids_json = EXCLUDED.seen_object_ids_json,
               updated_at = EXCLUDED.updated_at",
            &[
                &owner,
                &actor,
                &score,
                &cats_json,
                &until_s,
                &object,
                &seen_json,
                &now_s,
            ],
        )
        .await;
    Ok(true)
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
        let actor = actor.trim().trim_end_matches('/').to_string();
        if actor.is_empty() || downrank_actor_exempt(&actor) {
            continue;
        }
        let cats = toxicity_categories(&item.summary, &terms);
        if !cats.is_empty() {
            let object = if !item.object_id.is_empty() {
                item.object_id.clone()
            } else {
                item.id.clone()
            };
            let counted = record_suppression(db, owner, &actor, &cats, &object)
                .await
                .unwrap_or(false);
            if counted {
                let score = states
                    .get(&actor)
                    .map(|(s, _, _)| (*s + 1).min(8))
                    .unwrap_or(1);
                states.insert(actor.clone(), (score, now + 3600, cats));
            }
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
    // Keep more of the durable interaction history available to ranking while
    // retaining a hard row bound so a very active account cannot make rebuilds
    // unbounded. The database retention window is longer than the old 45-day
    // native read window.
    let since = chrono::Utc::now() - chrono::Duration::days(90);
    let since_s = since.to_rfc3339();
    let rows = db
        .query(
            "SELECT signal_type, weight, metadata_json, created_at
             FROM ap_user_signals
             WHERE owner_user_id = $1 AND created_at >= $2
             ORDER BY id DESC LIMIT 2000",
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
        sort += recommendation_bump(score);
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
        let bonus = affinity_bonus(count, tag_count);
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
    let followed_tags = load_followed_tags(&db, owner_user_id).await;

    let mut timeline = fetch_home_events(&db, &ap_following, &actor_id, &hidden).await?;
    timeline.extend(fetch_followed_tag_events(&db, &followed_tags, &hidden).await?);
    timeline.extend(fetch_own_outbox(&db, &actor_id).await?);
    if algorithm_on {
        let fresh_cutoff = chrono::Utc::now().timestamp() - HOME_FLOOR_FRESH_SECS;
        let fresh_follows = timeline
            .iter()
            .filter(|item| item.source == "fediverse" && item.sort >= fresh_cutoff)
            .count();
        if fresh_follows < HOME_FLOOR_MIN_FRESH {
            match fetch_federated_floor(&db, &hidden).await {
                Ok(floor) => timeline.extend(floor),
                Err(e) => tracing::warn!(
                    owner = owner_user_id,
                    error = %format!("{e:#}"),
                    "native home federated floor skipped"
                ),
            }
        }
    }
    let deprioritized = load_deprioritized_actors(&db, owner_user_id).await;
    apply_deprioritized_rank(&mut timeline, &deprioritized);

    let mut actor_weights = HashMap::new();
    let mut tag_weights = HashMap::new();
    if algorithm_on {
        // Match PHP order: recommendations → toxicity → favourite.
        actor_weights = load_favourite_actor_weights(&db, owner_user_id).await;
        for (actor, weight) in load_signal_actor_weights(&db, owner_user_id).await {
            let entry = actor_weights.entry(actor).or_insert(0.0);
            *entry = (*entry + weight).min(64.0);
        }
        tag_weights = load_favourite_tag_weights(&db, owner_user_id).await;
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

    let mut ranked = rank_from_timeline_capped(timeline, None);

    let own_did = load_own_did(&db, owner_user_id).await?;
    let mut bsky = fetch_bsky_keys(&db, owner_user_id, own_did.as_deref()).await?;
    if !followed_tags.is_empty() {
        let mut existing: HashSet<String> = bsky.iter().map(|row| row.uri.clone()).collect();
        for row in fetch_bsky_followed_tag_keys(
            &db,
            owner_user_id,
            own_did.as_deref(),
            &followed_tags,
        )
        .await?
        {
            if existing.insert(row.uri.clone()) {
                bsky.push(row);
            }
        }
    }
    // Algorithm-off leaves both maps empty, so this keeps indexed_at order.
    stamp_bsky_sort(&mut bsky, &actor_weights, &tag_weights);
    collapse_repeated_external_posts(&mut bsky);
    ranked = merge_bsky_ranked(ranked, &bsky, &hidden);

    if algorithm_on {
        match fetch_rss_keys(&db, owner_user_id, 32).await {
            Ok(rss) => ranked = queue_rss_after_first_page(ranked, &rss, 5),
            Err(e) => tracing::warn!(
                owner = owner_user_id,
                error = %format!("{e:#}"),
                "native home rss merge skipped"
            ),
        }
    }
    if ranked.len() > HOME_MAX_TIMELINE {
        ranked.truncate(HOME_MAX_TIMELINE);
    }

    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    if let Err(e) = write_home_rank_weights(
        &mut redis,
        owner_user_id,
        algorithm_on,
        &actor_weights,
        &tag_weights,
        &deprioritized,
    )
    .await
    {
        tracing::warn!(
            owner = owner_user_id,
            error = %format!("{e:#}"),
            "native home rank-weight cache skipped"
        );
    }

    if ranked.is_empty() {
        // Parity with Local/Federated: empty accounts skip without PHP fallback.
        let ms = started.elapsed().as_millis();
        return Ok(format!(
            "owner={owner_user_id} view=home key={logical} ranked=0 ms={ms} source=vaak-worker-native skipped=empty algo={} downrank={}",
            if algorithm_on { "on" } else { "off" },
            if downranking_on { "on" } else { "off" },
        ));
    }

    write_ranked_cache(
        &mut redis,
        owner_user_id,
        &logical,
        &ranked,
        "vaak-worker-native",
    )
    .await?;

    let hydrate = maybe_spawn_home_hydrate_warm(&mut redis, cfg, owner_user_id).await;

    let ms = started.elapsed().as_millis();
    let counts = source_counts(&ranked);
    Ok(format!(
        "owner={owner_user_id} view=home key={logical} ranked={} ms={ms} source=vaak-worker-native algo={} downrank={} counts={counts} {hydrate}",
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
    let hydrate =
        home_hydrate_ranked::maybe_warm_after_ranked_view(&mut redis, cfg, owner_user_id, "local")
            .await;
    let ms = started.elapsed().as_millis();
    let counts = source_counts(&ranked);
    Ok(format!(
        "owner={owner_user_id} view=local key={logical} ranked={} ms={ms} source=vaak-worker-native counts={counts} {hydrate}",
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
    let hydrate =
        home_hydrate_ranked::maybe_warm_after_ranked_view(&mut redis, cfg, owner_user_id, "feed")
            .await;
    let ms = started.elapsed().as_millis();
    let counts = source_counts(&ranked);
    Ok(format!(
        "owner={owner_user_id} view=feed key={logical} ranked={} ms={ms} source=vaak-worker-native counts={counts} {hydrate}",
        ranked.len()
    ))
}

pub async fn warm_owner(cfg: &Config, owner_user_id: i64, views: &str) -> Result<String> {
    let wanted = parse_views(views);
    let mut lines = Vec::new();

    for view in &wanted {
        match *view {
            "home" if native_home_enabled() => {
                lines.push(warm_home_native(cfg, owner_user_id).await?)
            }
            "home" => lines.push(format!(
                "owner={owner_user_id} view=home skipped=flag-off flag=VAAK_RANKED_NATIVE_HOME"
            )),
            "local" if native_local_feed_enabled() => {
                lines.push(warm_local_native(cfg, owner_user_id).await?)
            }
            "local" => lines.push(format!(
                "owner={owner_user_id} view=local skipped=flag-off flag=VAAK_RANKED_NATIVE_LOCAL_FEED"
            )),
            "feed" if native_local_feed_enabled() => {
                lines.push(warm_feed_native(cfg, owner_user_id).await?)
            }
            "feed" => lines.push(format!(
                "owner={owner_user_id} view=feed skipped=flag-off flag=VAAK_RANKED_NATIVE_LOCAL_FEED"
            )),
            _ => {}
        }
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
        assert!(!phrase_matches_text("this is retardation", "retard"));
        assert!(!phrase_matches_text("killing yourself now", "kill yourself"));
        assert!(!phrase_matches_text("classic passage and a cocktail", "ass"));
        assert!(!phrase_matches_text("classic passage and a cocktail", "cock"));
        assert!(!phrase_matches_text("grape and therapist and transparent", "rape"));
        assert!(!phrase_matches_text("grape and therapist and transparent", "trans"));
        assert!(phrase_matches_text("trans rights", "trans"));
        assert!(phrase_matches_text("Cat.", "cat"));
        assert!(!phrase_matches_text("bobcat and category", "cat"));
        assert!(!phrase_matches_text("cat2 and cat_video", "cat"));
        assert!(phrase_matches_text("<b>Retard</b>", "retard"));
    }

    #[test]
    fn deprioritize_is_home_only_six_hour_soft_penalty() {
        let actor = "https://remote.example/users/loud".to_string();
        let mut timeline = vec![TimelineItem::event(
            "1".into(),
            100_000,
            actor.clone(),
            actor.clone(),
            "hello".into(),
            "public".into(),
            "https://remote.example/notes/1".into(),
            "Create".into(),
            "home",
        )];
        let mut set = HashSet::new();
        set.insert(actor);
        apply_deprioritized_rank(&mut timeline, &set);
        assert_eq!(timeline[0].sort, 100_000 - 6 * 3600);
    }

    #[test]
    fn downrank_skips_local_and_admin() {
        assert!(downrank_actor_exempt("https://mkultra.monster/users/cmdr_nova"));
        assert!(downrank_actor_exempt("https://mkultra.monster/users/alice/"));
        assert!(downrank_actor_exempt("https://mkultra.monster/@bob"));
        assert!(downrank_actor_exempt("cmdr_nova@mkultra.monster"));
        assert!(!downrank_actor_exempt("https://infosec.exchange/users/geeknik"));
        assert!(!downrank_actor_exempt("https://example.com/users/cmdr_nova_fan"));
    }

    #[test]
    fn toxicity_matches_php_builtins_and_admin_terms_case_insensitively() {
        let builtins = toxicity_categories("This is an AI SLOP rant: GO FUCK YOURSELF", &[]);
        assert!(builtins.contains(&"ai_slogan".to_string()));
        assert!(builtins.contains(&"direct_abuse".to_string()));

        let manual = toxicity_categories(
            "Please stop using My Added Phrase here",
            &[("my added phrase".into(), "custom".into())],
        );
        assert_eq!(manual, vec!["custom".to_string()]);
    }

    fn bsky_key(uri: &str, did: &str, indexed_at: i64, sort: i64) -> BskyKey {
        BskyKey {
            uri: uri.into(),
            fedi: String::new(),
            author_did: did.into(),
            author_handle: String::new(),
            text: String::new(),
            external: String::new(),
            indexed_at,
            sort,
        }
    }

    #[test]
    fn recommendation_bump_caps_at_thirty_minutes() {
        assert_eq!(recommendation_bump(0.0), 0);
        assert_eq!(recommendation_bump(1.0), 1800);
        assert_eq!(recommendation_bump(64.0), 1800);
        assert_eq!(recommendation_bump(10_000.0), 1800);
    }

    #[test]
    fn rank_rows_keep_sort_and_object() {
        let items = vec![TimelineItem::event(
            "42".into(),
            1_700_000_000,
            "https://example.com/users/a".into(),
            "https://example.com/users/a".into(),
            "hello".into(),
            "public".into(),
            "https://example.com/notes/1".into(),
            "Create".into(),
            "fediverse",
        )];
        let ranked = rank_from_timeline(items);
        assert_eq!(ranked[0]["sort"], json!(1_700_000_000));
        assert_eq!(ranked[0]["o"], json!("https://example.com/notes/1"));
        assert_eq!(ranked[0]["k"], json!("event"));
    }

    #[test]
    fn bluesky_fresh_post_outranks_stale_favourite() {
        let now = chrono::Utc::now().timestamp();
        let mut keys = vec![
            bsky_key("at://did:high/app.bsky.feed.post/old", "did:high", now - 6 * 3600, 0),
            bsky_key("at://did:low/app.bsky.feed.post/new", "did:low", now - 60, 0),
        ];
        let mut actors = HashMap::new();
        actors.insert("did:high".into(), 64.0);
        actors.insert("did:low".into(), 1.0);
        stamp_bsky_sort(&mut keys, &actors, &HashMap::new());
        assert_eq!(keys[0].author_did, "did:low");
        assert!(keys[0].sort > keys[1].sort);
        assert!(keys[0].sort - (now - 60) <= 1800);
    }

    #[test]
    fn merge_bsky_keeps_every_followed_post() {
        let hidden = hidden::HiddenSets::default();
        let fresh = merge_bsky_ranked(
            vec![json!({"k":"event","id":"1","s":"fediverse","sort": 1_700_000_100})],
            &[bsky_key("at://did:x/app.bsky.feed.post/1", "did:x", 1_700_000_000, 1_700_000_000)],
            &hidden,
        );
        assert_eq!(fresh[0]["k"], json!("event"));

        let lead = merge_bsky_ranked(
            vec![json!({"k":"event","id":"1","s":"fediverse","sort": 1_700_000_100})],
            &[bsky_key("at://did:x/app.bsky.feed.post/1", "did:x", 1_700_000_200, 1_700_000_200)],
            &hidden,
        );
        assert_eq!(lead[0]["k"], json!("bsky"));
        assert_eq!(lead.len(), 2);

        let fedi: Vec<Value> = (0..2)
            .map(|i| json!({"k":"event","id": format!("{i}"), "s":"fediverse","sort": 1_000 - i}))
            .collect();
        let bsky: Vec<BskyKey> = (0..8)
            .map(|i| bsky_key(&format!("at://did:x/app.bsky.feed.post/{i}"), "did:x", 2_000 - i, 2_000 - i))
            .collect();
        let merged = merge_bsky_ranked(fedi, &bsky, &hidden);
        let bsky_n = merged
            .iter()
            .filter(|row| row.get("k").and_then(|v| v.as_str()) == Some("bsky"))
            .count();
        assert_eq!(merged.len(), 10);
        assert_eq!(bsky_n, 8, "followed Bluesky posts must stay in the mix");
        assert!(merged[..8].iter().all(|row| row["k"] == json!("bsky")));

        let only = merge_bsky_ranked(
            Vec::new(),
            &[bsky_key("at://did:x/app.bsky.feed.post/only", "did:x", 50, 50)],
            &hidden,
        );
        assert_eq!(only.len(), 1);
    }

    #[test]
    fn collapse_repeated_external_keeps_the_higher_ranked_copy() {
        let mut first = bsky_key("at://did:rosa/app.bsky.feed.post/a", "did:rosa", 200, 200);
        first.external = "7t1U1fcEODc".into();
        let mut second = bsky_key("at://did:rosa/app.bsky.feed.post/b", "did:rosa", 199, 199);
        second.external = "https://www.youtube.com/watch?v=7t1U1fcEODc&is=abc".into();
        let mut other_video = bsky_key("at://did:rosa/app.bsky.feed.post/c", "did:rosa", 198, 198);
        other_video.external = "JyECrGp-Sw8".into();
        let mut other_author = bsky_key("at://did:other/app.bsky.feed.post/d", "did:other", 197, 197);
        other_author.external = "7t1U1fcEODc".into();
        let quote = bsky_key("at://did:rosa/app.bsky.feed.post/q", "did:rosa", 196, 196);
        let mut keys = vec![first, second, other_video, other_author, quote];
        collapse_repeated_external_posts(&mut keys);
        let uris: Vec<&str> = keys.iter().map(|key| key.uri.as_str()).collect();
        assert_eq!(
            uris,
            vec![
                "at://did:rosa/app.bsky.feed.post/a",
                "at://did:rosa/app.bsky.feed.post/c",
                "at://did:other/app.bsky.feed.post/d",
                "at://did:rosa/app.bsky.feed.post/q",
            ]
        );
    }

    #[test]
    fn merge_bsky_drops_fediverse_twin() {
        let hidden = hidden::HiddenSets::default();
        let mut twin = bsky_key("at://did:x/app.bsky.feed.post/1", "did:x", 50, 50);
        twin.fedi = "https://example.com/notes/1".into();
        let merged = merge_bsky_ranked(
            vec![json!({
                "k":"event",
                "id":"9",
                "s":"fediverse",
                "sort": 40,
                "o":"https://example.com/notes/1"
            })],
            &[twin],
            &hidden,
        );
        assert_eq!(merged.len(), 1);
        assert_eq!(merged[0]["k"], json!("event"));
    }

    #[test]
    fn merge_does_not_interleave_week_old_posts_into_a_fresh_bluesky_head() {
        let hidden = hidden::HiddenSets::default();
        let now = chrono::Utc::now().timestamp();
        let fedi = vec![json!({
            "k": "event",
            "id": "old",
            "s": "fediverse",
            "sort": now - 13 * 24 * 3600
        })];
        let bsky: Vec<BskyKey> = (0..4)
            .map(|i| {
                bsky_key(
                    &format!("at://did:x/app.bsky.feed.post/{i}"),
                    "did:x",
                    now - i * 60,
                    now - i * 60,
                )
            })
            .collect();
        let merged = merge_bsky_ranked(fedi, &bsky, &hidden);
        assert_eq!(merged.len(), 5);
        assert!(merged[..4].iter().all(|row| row["k"] == json!("bsky")));
        assert_eq!(merged[4]["id"], json!("old"));
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
