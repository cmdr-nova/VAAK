//! Per-owner/view input marker for ranked-warm.
//!
//! The marker is a fingerprint of the rows a Home, Local, or Federated rebuild
//! reads. It is stored in Redis next to the ranked cache. The loop skips the
//! rebuild when the fingerprint matches the last build, the ranked key is
//! still there, and that build is younger than
//! [`max_age_secs`](crate::ranked_warm_marker::max_age_secs). Time-decay scores
//! (affinity, burst spread, own-post lift, suppression expiry) move on that
//! cap even when no row changed.
//!
//! Each lookup is an index probe or a `LIMIT 1` / `LIMIT n` on an index the
//! rebuild already uses. A miss or a failed read does not skip: the view is
//! rebuilt. `VAAK_RANKED_WARM_INPUT_MARKER=0` leaves the older table-stat
//! watermark in place.
//!
//! PHP `bin/ranked-warm.php` is retired and does not rebuild. The HTML cold
//! path `admin_tl_lean_ranked_warm` runs only when a page needs a cache this
//! loop did not fill, which is the same case that forces a rebuild here.

use std::collections::BTreeMap;

use anyhow::{Context, Result};
use serde_json::{json, Value};
use tokio_postgres::Client;

use crate::ranked_warm::{
    burst_spread_enabled, CACHE_VERSION, HOME_FLOOR_FRESH_SECS, HOME_FLOOR_MIN_FRESH,
    HOME_TTL_SECS, PER_ACTOR_CAP,
};
use crate::redis_util;

/// Bump when ranking math changes without an input-row change, so the next
/// tick rebuilds instead of serving the previous order.
pub const RANK_INPUT_VERSION: &str = "1";
const DEFAULT_MAX_AGE_SECS: u64 = 600;

/// `VAAK_RANKED_WARM_INPUT_MARKER`. Unset means on. `0`, `false`, `off`, `no` disable it.
pub fn enabled_from(raw: Option<&str>) -> bool {
    match raw.map(str::trim).filter(|v| !v.is_empty()) {
        None => true,
        Some(v) => !matches!(
            v.to_ascii_lowercase().as_str(),
            "0" | "false" | "off" | "no"
        ),
    }
}

pub fn enabled() -> bool {
    enabled_from(
        std::env::var("VAAK_RANKED_WARM_INPUT_MARKER")
            .ok()
            .as_deref(),
    )
}

/// `VAAK_RANKED_WARM_MAX_AGE_SECS`. Unset, blank, or unparseable uses 10 minutes.
/// `0` keeps a matching marker until the cache is gone (time-decay is not forced).
pub fn max_age_from(raw: Option<&str>) -> u64 {
    match raw.map(str::trim).filter(|v| !v.is_empty()) {
        None => DEFAULT_MAX_AGE_SECS,
        Some(v) => match v.parse::<i64>() {
            Ok(n) if n >= 0 => n as u64,
            _ => DEFAULT_MAX_AGE_SECS,
        },
    }
}

pub fn max_age_secs() -> u64 {
    max_age_from(
        std::env::var("VAAK_RANKED_WARM_MAX_AGE_SECS")
            .ok()
            .as_deref(),
    )
}

pub fn marker_ttl_secs(max_age: u64) -> u64 {
    HOME_TTL_SECS.max(max_age).saturating_add(120).max(60)
}

pub fn redis_key(owner: i64, view: &str) -> String {
    format!("vaak:timeline:ranked-input:v1:{}:{view}", owner.max(0))
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct StoredMarker {
    pub marker: String,
    pub logical: String,
    pub built_at: i64,
}

pub fn stored_value(marker: &str, logical: &str, built_at: i64) -> Value {
    json!({
        "marker": marker,
        "logical": logical,
        "built_at": built_at,
    })
}

pub fn parse_stored(value: &Value) -> Option<StoredMarker> {
    let marker = value.get("marker")?.as_str()?.to_string();
    if marker.is_empty() {
        return None;
    }
    let logical = value.get("logical")?.as_str()?.to_string();
    let built_at = value.get("built_at")?.as_i64()?;
    Some(StoredMarker {
        marker,
        logical,
        built_at,
    })
}

pub fn age_secs(built_at: i64, now: i64) -> u64 {
    now.saturating_sub(built_at).max(0) as u64
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum MarkerDecision {
    Skip,
    /// `cold` (no stored marker), `changed`, `cache_miss`, `stale`, `unavailable`.
    Rebuild(&'static str),
}

/// `None` is not used. A disabled flag is handled by the caller, which keeps
/// the table-stat watermark instead of calling this.
pub fn decide(
    previous: Option<&str>,
    next: Option<&str>,
    cache_present: bool,
    built_secs_ago: u64,
    max_age_secs: u64,
) -> MarkerDecision {
    let Some(next) = next.filter(|marker| !marker.is_empty()) else {
        return MarkerDecision::Rebuild("unavailable");
    };
    let Some(previous) = previous.filter(|marker| !marker.is_empty()) else {
        return MarkerDecision::Rebuild("cold");
    };
    if previous != next {
        return MarkerDecision::Rebuild("changed");
    }
    if !cache_present {
        return MarkerDecision::Rebuild("cache_miss");
    }
    if max_age_secs > 0 && built_secs_ago >= max_age_secs {
        return MarkerDecision::Rebuild("stale");
    }
    MarkerDecision::Skip
}

#[derive(Default, Clone, Debug)]
pub struct MarkerCounts {
    pub skipped: u64,
    pub rebuilt: u64,
    pub cold: u64,
    pub changed: u64,
    pub cache_miss: u64,
    pub stale: u64,
    pub unavailable: u64,
}

impl MarkerCounts {
    pub fn record_rebuild(&mut self, reason: &'static str) {
        self.rebuilt += 1;
        match reason {
            "cold" => self.cold += 1,
            "changed" => self.changed += 1,
            "cache_miss" => self.cache_miss += 1,
            "stale" => self.stale += 1,
            _ => self.unavailable += 1,
        }
    }
}

pub fn finish_marker(view: &str, mut parts: BTreeMap<String, String>) -> String {
    parts.insert("view".into(), view.to_string());
    parts.insert("v".into(), RANK_INPUT_VERSION.to_string());
    parts.insert("cache".into(), CACHE_VERSION.to_string());
    parts.insert(
        "burst".into(),
        if burst_spread_enabled() { "1" } else { "0" }.to_string(),
    );
    parts
        .into_iter()
        .map(|(key, value)| format!("{key}={value}"))
        .collect::<Vec<_>>()
        .join(";")
}

#[derive(Clone, Debug)]
pub struct SharedInputs {
    pub events_upd: i64,
    pub events_del: i64,
    pub feed_head: i64,
    pub local_note: String,
    pub local_boost: String,
    pub outbox_ins: i64,
    pub outbox_upd: i64,
    pub outbox_del: i64,
    pub reblog_ins: i64,
    pub reblog_upd: i64,
    pub reblog_del: i64,
    pub blocks: String,
    pub terms: String,
}

/// Tables whose insert/update/delete counters catch an edit or delete that
/// does not move the newest indexed row. One stats read per tick.
const STATS_SQL: &str =
    "SELECT relname, COALESCE(n_tup_ins, 0), COALESCE(n_tup_upd, 0), COALESCE(n_tup_del, 0)
     FROM pg_stat_user_tables
     WHERE schemaname = current_schema() AND relname = ANY($1::text[])";

/// Newest public timeline row. `idx_events_type_action_created`
/// (type, action_taken, created_at DESC, id DESC). Not the global max id:
/// likes and follows must not rebuild Federated.
const FEED_HEAD_SQL: &str = "SELECT id::bigint
     FROM events
     WHERE type = ANY(ARRAY['Create','Announce','Quote','QuotePost'])
       AND action_taken = ANY(ARRAY['log','local_observe'])
       AND COALESCE(visibility, 'public') = 'public'
     ORDER BY created_at DESC, id DESC
     LIMIT 1";

/// Same predicate as the Local outbox read. `idx_outbox_published` (published).
const LOCAL_NOTE_SQL: &str = "SELECT id || '|' || COALESCE(published, '')
     FROM outbox_notes
     WHERE id LIKE 'https://mkultra.monster/users/%/notes/%'
       AND COALESCE(visibility, 'public') = 'public'
     ORDER BY published DESC
     LIMIT 1";

/// Same local-boost predicate as the rebuild, including the Bluesky exclusion.
/// `idx_masto_reblogs_created` (created_at DESC).
const LOCAL_BOOST_SQL: &str = "SELECT status_id || '|' || COALESCE(created_at::text, '')
     FROM masto_reblogs
     WHERE owner_actor_id LIKE 'https://mkultra.monster/users/%'
       AND status_id NOT LIKE 'bsky-repost-%'
       AND COALESCE(object_id, '') NOT LIKE 'https://bsky.app/%'
       AND COALESCE(object_id, '') NOT LIKE '%bsky.mkultra.monster/%'
     ORDER BY created_at DESC
     LIMIT 1";

/// Instance blocks are small and apply to every owner. Primary key `id`.
const BLOCKS_SQL: &str =
    "SELECT COUNT(*)::bigint, COALESCE(MAX(id), 0)::bigint, COALESCE(MAX(updated_at), '')
     FROM ap_blocks";

/// Admin downrank terms. The table is capped (the rebuild reads at most 500).
/// `idx_ap_home_downrank_terms_enabled`.
const TERMS_SQL: &str = "SELECT COALESCE(MAX(id), 0)::bigint, COALESCE(MAX(updated_at), '')
     FROM ap_home_downrank_terms
     WHERE enabled = 1";

/// Owner-scoped rows that are point lookups. Indexes:
/// `idx_following_owner`, `actor_profile` primary key, `bsky_sessions` primary key,
/// `idx_ap_mutes_owner`, `idx_ap_user_blocks_owner`, `idx_ap_muted_words_owner`,
/// `idx_ap_deprioritized_owner`, `bsky_hide_dids` primary key,
/// `bsky_graph_sync` primary key `(owner_user_id, kind, target_did)`,
/// `idx_ap_user_signals_owner_time` `(owner_user_id, created_at DESC, id DESC)`,
/// `idx_reco_favourites_owner_object`, `masto_followed_tags` primary key,
/// `ap_home_suppression` primary key, `idx_bsky_post_observations_owner_time`.
const OWNER_LIGHT_SQL: &str = "SELECT
    (SELECT COUNT(*)::bigint FROM following WHERE owner_actor_id = $1 OR owner_actor_id = $2),
    COALESCE((SELECT md5(string_agg(rtrim(actor_id, '/'), ',' ORDER BY rtrim(actor_id, '/')))
              FROM following WHERE owner_actor_id = $1 OR owner_actor_id = $2), ''),
    COALESCE((SELECT algorithm_enabled::integer FROM actor_profile WHERE actor_key = $3 LIMIT 1), 1),
    COALESCE((SELECT downranking_enabled::integer FROM actor_profile WHERE actor_key = $3 LIMIT 1), 1),
    COALESCE((SELECT did FROM bsky_sessions WHERE owner_user_id = $4::bigint LIMIT 1), ''),
    (SELECT COUNT(*)::bigint FROM ap_mutes WHERE owner_user_id = $4::bigint),
    COALESCE((SELECT MAX(created_at) FROM ap_mutes WHERE owner_user_id = $4::bigint), ''),
    (SELECT COUNT(*)::bigint FROM ap_user_blocks WHERE owner_user_id = $4::bigint),
    COALESCE((SELECT MAX(id) FROM ap_user_blocks WHERE owner_user_id = $4::bigint), 0)::bigint,
    (SELECT COUNT(*)::bigint FROM ap_muted_words WHERE owner_user_id = $4::bigint),
    COALESCE((SELECT MAX(id) FROM ap_muted_words WHERE owner_user_id = $4::bigint), 0)::bigint,
    (SELECT COUNT(*)::bigint FROM ap_deprioritized_actors WHERE owner_user_id = $4::bigint),
    COALESCE((SELECT MAX(created_at) FROM ap_deprioritized_actors WHERE owner_user_id = $4::bigint), ''),
    (SELECT COUNT(*)::bigint FROM bsky_hide_dids WHERE owner_user_id = $4::bigint),
    COALESCE((SELECT MAX(updated_at) FROM bsky_hide_dids WHERE owner_user_id = $4::bigint), ''),
    (SELECT COUNT(*)::bigint FROM bsky_graph_sync WHERE owner_user_id = $4::bigint AND kind = 'follow'),
    COALESCE((SELECT md5(string_agg(target_did, ',' ORDER BY target_did))
              FROM bsky_graph_sync WHERE owner_user_id = $4::bigint AND kind = 'follow'), ''),
    COALESCE((SELECT id::bigint FROM ap_user_signals WHERE owner_user_id = $4::bigint
              ORDER BY created_at DESC, id DESC LIMIT 1), 0),
    COALESCE((SELECT MAX(id) FROM masto_favourites WHERE owner_user_id = $4::bigint), 0)::bigint,
    (SELECT COUNT(*)::bigint FROM masto_favourites WHERE owner_user_id = $4::bigint),
    (SELECT COUNT(*)::bigint FROM masto_followed_tags WHERE owner_user_id = $4::bigint),
    COALESCE((SELECT md5(string_agg(name, ',' ORDER BY name))
              FROM masto_followed_tags WHERE owner_user_id = $4::bigint), ''),
    (SELECT COUNT(*)::bigint FROM ap_home_suppression WHERE owner_user_id = $4::bigint),
    COALESCE((SELECT MAX(updated_at) FROM ap_home_suppression WHERE owner_user_id = $4::bigint), ''),
    COALESCE((SELECT COALESCE(last_seen_at, '') || '|' || COALESCE(bsky_uri, '')
              FROM bsky_post_observations WHERE owner_user_id = $4::bigint
              ORDER BY last_seen_at DESC LIMIT 1), '')";

/// Newest timeline id per followed actor, plus how many of those actors posted
/// inside the Home floor window. `idx_following_owner` lists the actors.
/// `idx_events_actor_type_action_created` (actor_id, type, action_taken,
/// created_at DESC, id DESC) serves each `LIMIT 1`. Bluesky profile URLs are
/// not event actors, matching `ap_following_for_events`.
const FOLLOW_EVENTS_SQL: &str = "WITH actors AS (
    SELECT rtrim(actor_id, '/') AS actor_id
    FROM following
    WHERE (owner_actor_id = $1 OR owner_actor_id = $2)
      AND actor_id LIKE 'https://%'
      AND position('bsky.app/' IN actor_id) = 0
    UNION
    SELECT rtrim($1::text, '/')
)
SELECT
    COALESCE((
        SELECT MAX(e.id)::bigint
        FROM actors f
        JOIN LATERAL (
            SELECT id FROM events
            WHERE actor_id IN (f.actor_id, f.actor_id || '/')
              AND type = ANY(ARRAY['Create','Announce','Quote','QuotePost'])
              AND action_taken = ANY(ARRAY['log','local_observe'])
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ) e ON true
    ), 0),
    COALESCE((
        SELECT COUNT(*)::bigint FROM (
            SELECT 1
            FROM actors f
            JOIN LATERAL (
                SELECT 1 FROM events
                WHERE actor_id IN (f.actor_id, f.actor_id || '/')
                  AND type = ANY(ARRAY['Create','Announce','Quote','QuotePost'])
                  AND action_taken = ANY(ARRAY['log','local_observe'])
                  AND created_at >= $3
                LIMIT 1
            ) hit ON true
            LIMIT $4
        ) fresh
    ), 0)";

/// The same 40 newest own notes the rebuild hashes. Primary key prefix on `id`.
const OWN_OUTBOX_SQL: &str = "SELECT COUNT(*)::bigint,
        COALESCE(md5(string_agg(md5(id || '|' || published || '|' || content), ',' ORDER BY published DESC, id)), '')
     FROM (
        SELECT id, published, content
        FROM outbox_notes
        WHERE id LIKE $1
        ORDER BY published DESC
        LIMIT 40
     ) own";

/// Newest `PER_ACTOR_CAP` posts per followed DID. `bsky_graph_sync` primary key
/// finds the follows. `idx_bsky_posts_author_idx` (author_did, indexed_at DESC)
/// serves the lateral limit. Link and crosspost joins are primary-key lookups
/// on `bsky_uri`, so a mirror mapping changes the fingerprint.
const BSKY_WINDOW_SQL: &str = "SELECT COALESCE(md5(string_agg(
        p.bsky_uri || '|' || COALESCE(p.indexed_at, '') || '|' || COALESCE(p.updated_at, '')
        || '|' || COALESCE(l.fediverse_id, '') || '|' || COALESCE(c.note_id, ''),
        ',' ORDER BY p.bsky_uri)), '')
     FROM bsky_graph_sync g
     JOIN LATERAL (
        SELECT bsky_uri, indexed_at, updated_at
        FROM bsky_posts
        WHERE author_did = g.target_did
          AND text IS NOT NULL
        ORDER BY indexed_at DESC
        LIMIT $3
     ) p ON true
     LEFT JOIN bsky_post_links l ON l.bsky_uri = p.bsky_uri
     LEFT JOIN bsky_crossposts c ON c.bsky_uri = p.bsky_uri
     WHERE g.owner_user_id = $1::bigint
       AND g.kind = 'follow'
       AND ($2 = '' OR g.target_did <> $2)";

/// `idx_rss_feeds_owner` (owner_user_id, enabled) and
/// `idx_rss_items_feed_pub` (feed_id, published_at DESC).
const RSS_SQL: &str = "SELECT COALESCE(md5(string_agg(
        i.id::text || '|' || COALESCE(i.published_at::text, '') || '|' || COALESCE(i.title, ''),
        ',' ORDER BY i.id)), '')
     FROM rss_feeds f
     JOIN LATERAL (
        SELECT id, published_at, title
        FROM rss_items
        WHERE feed_id = f.id
        ORDER BY published_at DESC NULLS LAST
        LIMIT 24
     ) i ON true
     WHERE f.owner_user_id = $1::bigint AND f.enabled";

const NOSTR_LINK_SQL: &str =
    "SELECT show_in_home, pubkey FROM nostr_links WHERE owner_user_id = $1::bigint";

/// `nostr_follows` primary key `(owner_user_id, pubkey)`.
const NOSTR_FOLLOWS_SQL: &str = "SELECT COALESCE(md5(string_agg(pubkey, ',' ORDER BY pubkey)), '')
     FROM nostr_follows WHERE owner_user_id = $1::bigint";

/// `idx_nostr_events_pubkey_created` (pubkey, created_at DESC).
const NOSTR_EVENTS_SQL: &str = "SELECT COALESCE(md5(string_agg(e.id, ',' ORDER BY e.id)), '')
     FROM nostr_follows f
     JOIN LATERAL (
        SELECT id FROM nostr_events
        WHERE pubkey = f.pubkey AND kind IN (1, 6)
        ORDER BY created_at DESC
        LIMIT 1
     ) e ON true
     WHERE f.owner_user_id = $1::bigint";

/// Primary key `(owner_user_id, note_id)`.
const NOSTR_REACTIONS_SQL: &str =
    "SELECT COALESCE(MAX(created_at), 0)::bigint FROM nostr_reactions WHERE owner_user_id = $1::bigint";

/// Primary key `(owner_user_id, event_id)`.
const NOSTR_SHARES_SQL: &str =
    "SELECT COALESCE(MAX(created_at), 0)::bigint FROM nostr_shares WHERE owner_user_id = $1::bigint";

/// Local accounts this owner follows. `ap_users` is the small local-user
/// table; `rtrim` matches the slash forms the Home boost query uses.
const LOCAL_FOLLOW_IDS_SQL: &str = "SELECT DISTINCT u.id::bigint
     FROM following f
     JOIN ap_users u ON rtrim(u.actor_id, '/') = rtrim(f.actor_id, '/')
     WHERE f.owner_actor_id = $1 OR f.owner_actor_id = $2";

/// Nostr boosts the Home merge reads. `idx_masto_reblogs_owner_created`
/// (owner_user_id, created_at DESC) for the owner's own boosts and any local
/// account they follow.
const NOSTR_BOOST_SQL: &str = "SELECT COALESCE(md5(string_agg(
        status_id || '|' || COALESCE(created_at::text, ''), ',' ORDER BY created_at DESC, status_id)), '')
     FROM (
        SELECT status_id, created_at
        FROM masto_reblogs
        WHERE status_id LIKE 'nostr:%'
          AND owner_user_id::bigint = ANY($1::bigint[])
        ORDER BY created_at DESC
        LIMIT 120
     ) boosts";

struct TableStat {
    ins: i64,
    upd: i64,
    del: i64,
}

struct OwnerLight {
    follow_count: i64,
    follow_hash: String,
    algo: i32,
    downrank: i32,
    did: String,
    mutes: String,
    user_blocks: String,
    words: String,
    deprioritized: String,
    hide: String,
    graph_count: i64,
    graph_hash: String,
    signal_id: i64,
    favourites: String,
    tag_count: i64,
    tag_hash: String,
    suppression: String,
    observation: String,
}

fn missing_relation(err: &tokio_postgres::Error) -> bool {
    let message = err.to_string();
    message.contains("42P01") || message.contains("does not exist")
}

fn stat_map(rows: &[tokio_postgres::Row]) -> BTreeMap<String, TableStat> {
    let mut out = BTreeMap::new();
    for row in rows {
        let name: String = row.get(0);
        out.insert(
            name,
            TableStat {
                ins: row.get(1),
                upd: row.get(2),
                del: row.get(3),
            },
        );
    }
    out
}

pub async fn load_shared(db: &Client) -> Result<SharedInputs> {
    let names = vec![
        "events".to_string(),
        "outbox_notes".to_string(),
        "masto_reblogs".to_string(),
    ];
    let rows = db
        .query(STATS_SQL, &[&names])
        .await
        .context("ranked-warm marker stats")?;
    let stats = stat_map(&rows);
    let events = stats
        .get("events")
        .context("ranked-warm marker missing events stats")?;
    let notes = stats
        .get("outbox_notes")
        .context("ranked-warm marker missing outbox stats")?;
    let reblogs = stats
        .get("masto_reblogs")
        .context("ranked-warm marker missing reblog stats")?;
    let feed_head: i64 = db
        .query_opt(FEED_HEAD_SQL, &[])
        .await
        .context("ranked-warm marker feed head")?
        .map(|row| row.get(0))
        .unwrap_or(0);
    let local_note: String = db
        .query_opt(LOCAL_NOTE_SQL, &[])
        .await
        .context("ranked-warm marker local note")?
        .and_then(|row| row.get::<_, Option<String>>(0))
        .unwrap_or_default();
    let local_boost: String = db
        .query_opt(LOCAL_BOOST_SQL, &[])
        .await
        .context("ranked-warm marker local boost")?
        .and_then(|row| row.get::<_, Option<String>>(0))
        .unwrap_or_default();
    let blocks_row = db
        .query_one(BLOCKS_SQL, &[])
        .await
        .context("ranked-warm marker blocks")?;
    let blocks = format!(
        "{}:{}:{}",
        blocks_row.get::<_, i64>(0),
        blocks_row.get::<_, i64>(1),
        blocks_row.get::<_, String>(2)
    );
    let terms_row = db
        .query_one(TERMS_SQL, &[])
        .await
        .context("ranked-warm marker terms")?;
    let terms = format!(
        "{}:{}",
        terms_row.get::<_, i64>(0),
        terms_row.get::<_, String>(1)
    );
    Ok(SharedInputs {
        events_upd: events.upd,
        events_del: events.del,
        feed_head,
        local_note,
        local_boost,
        outbox_ins: notes.ins,
        outbox_upd: notes.upd,
        outbox_del: notes.del,
        reblog_ins: reblogs.ins,
        reblog_upd: reblogs.upd,
        reblog_del: reblogs.del,
        blocks,
        terms,
    })
}

async fn load_owner_actor(db: &Client, owner: i64) -> Result<(String, String)> {
    let row = db
        .query_opt(
            "SELECT actor_id, COALESCE(actor_key, username, '') FROM ap_users WHERE id = $1::bigint LIMIT 1",
            &[&owner],
        )
        .await
        .context("ranked-warm marker owner")?
        .context("ranked-warm marker owner missing")?;
    let actor: String = row
        .get::<_, Option<String>>(0)
        .unwrap_or_default()
        .trim_end_matches('/')
        .to_string();
    let key: String = row.get::<_, Option<String>>(1).unwrap_or_default();
    if !actor.starts_with("https://") {
        anyhow::bail!("ranked-warm marker owner {owner} has no actor_id");
    }
    Ok((actor, key))
}

fn light_from_row(row: &tokio_postgres::Row) -> OwnerLight {
    OwnerLight {
        follow_count: row.get(0),
        follow_hash: row.get(1),
        algo: row.get(2),
        downrank: row.get(3),
        did: row.get(4),
        mutes: format!("{}:{}", row.get::<_, i64>(5), row.get::<_, String>(6)),
        user_blocks: format!("{}:{}", row.get::<_, i64>(7), row.get::<_, i64>(8)),
        words: format!("{}:{}", row.get::<_, i64>(9), row.get::<_, i64>(10)),
        deprioritized: format!("{}:{}", row.get::<_, i64>(11), row.get::<_, String>(12)),
        hide: format!("{}:{}", row.get::<_, i64>(13), row.get::<_, String>(14)),
        graph_count: row.get(15),
        graph_hash: row.get(16),
        signal_id: row.get(17),
        favourites: format!("{}:{}", row.get::<_, i64>(18), row.get::<_, i64>(19)),
        tag_count: row.get(20),
        tag_hash: row.get(21),
        suppression: format!("{}:{}", row.get::<_, i64>(22), row.get::<_, String>(23)),
        observation: row.get(24),
    }
}

async fn load_light(db: &Client, owner: i64, actor: &str, actor_key: &str) -> Result<OwnerLight> {
    let slashed = format!("{actor}/");
    let row = db
        .query_one(OWNER_LIGHT_SQL, &[&actor, &slashed, &actor_key, &owner])
        .await
        .context("ranked-warm marker owner rows")?;
    Ok(light_from_row(&row))
}

fn moderation_parts(
    shared: &SharedInputs,
    light: &OwnerLight,
    parts: &mut BTreeMap<String, String>,
) {
    parts.insert("mutes".into(), light.mutes.clone());
    parts.insert("user_blocks".into(), light.user_blocks.clone());
    parts.insert("words".into(), light.words.clone());
    parts.insert("hide".into(), light.hide.clone());
    parts.insert("blocks".into(), shared.blocks.clone());
    parts.insert(
        "follows".into(),
        format!("{}:{}", light.follow_count, light.follow_hash),
    );
}

async fn optional_aggregate(db: &Client, sql: &str, owner: i64) -> Result<String> {
    match db.query_one(sql, &[&owner]).await {
        Ok(row) => Ok(row.get::<_, Option<String>>(0).unwrap_or_default()),
        Err(err) if missing_relation(&err) => Ok("absent".into()),
        Err(err) => Err(err).context("ranked-warm marker optional aggregate"),
    }
}

async fn nostr_marker(db: &Client, owner: i64) -> Result<String> {
    let link = match db.query_opt(NOSTR_LINK_SQL, &[&owner]).await {
        Ok(Some(row)) => row,
        Ok(None) => return Ok("off".into()),
        Err(err) if missing_relation(&err) => return Ok("absent".into()),
        Err(err) => return Err(err).context("ranked-warm marker nostr link"),
    };
    let show: bool = link.get(0);
    let pubkey: String = link.get(1);
    if !show {
        return Ok(format!("off:{pubkey}"));
    }
    let follows = optional_aggregate(db, NOSTR_FOLLOWS_SQL, owner).await?;
    let events = optional_aggregate(db, NOSTR_EVENTS_SQL, owner).await?;
    let reactions: i64 = match db.query_one(NOSTR_REACTIONS_SQL, &[&owner]).await {
        Ok(row) => row.get(0),
        Err(err) if missing_relation(&err) => 0,
        Err(err) => return Err(err).context("ranked-warm marker nostr reactions"),
    };
    let shares: i64 = match db.query_one(NOSTR_SHARES_SQL, &[&owner]).await {
        Ok(row) => row.get(0),
        Err(err) if missing_relation(&err) => 0,
        Err(err) => return Err(err).context("ranked-warm marker nostr shares"),
    };
    Ok(format!(
        "on:{pubkey}:{follows}:{events}:{reactions}:{shares}"
    ))
}

async fn nostr_boost_marker(db: &Client, owner: i64, actor: &str) -> Result<String> {
    let slashed = format!("{actor}/");
    let mut ids = vec![owner];
    let rows = db
        .query(LOCAL_FOLLOW_IDS_SQL, &[&actor, &slashed])
        .await
        .context("ranked-warm marker local follows")?;
    for row in rows {
        let id: i64 = row.get(0);
        if id > 0 && !ids.contains(&id) {
            ids.push(id);
        }
    }
    let row = db
        .query_one(NOSTR_BOOST_SQL, &[&ids])
        .await
        .context("ranked-warm marker nostr boosts")?;
    Ok(row.get::<_, Option<String>>(0).unwrap_or_default())
}

async fn feed_picks_digest(
    redis: &mut redis::aio::MultiplexedConnection,
    owner: i64,
) -> Result<String> {
    let key = format!("vaak:bsky:feedpicks:v1:{owner}");
    let raw = redis_util::get_string(redis, &key)
        .await
        .context("ranked-warm marker feed picks")?;
    let Some(raw) = raw else {
        return Ok("0".into());
    };
    let mut hasher = sha2::Sha256::new();
    use sha2::Digest;
    hasher.update(raw.as_bytes());
    Ok(hex::encode(hasher.finalize())[..16].to_string())
}

fn home_floor_relevant(fresh_actors: i64, tag_count: i64) -> bool {
    tag_count > 0 || fresh_actors < HOME_FLOOR_MIN_FRESH as i64
}

/// Fingerprint for one view. `Err` means the read failed and the caller rebuilds.
pub async fn load_view_marker(
    db: &Client,
    redis: Option<&mut redis::aio::MultiplexedConnection>,
    owner: i64,
    view: &str,
    shared: &SharedInputs,
) -> Result<String> {
    let mut parts = BTreeMap::new();
    match view {
        "local" => {
            parts.insert("note".into(), shared.local_note.clone());
            parts.insert("boost".into(), shared.local_boost.clone());
            parts.insert(
                "notes_stat".into(),
                format!(
                    "{}:{}:{}",
                    shared.outbox_ins, shared.outbox_upd, shared.outbox_del
                ),
            );
            parts.insert(
                "reblog_stat".into(),
                format!(
                    "{}:{}:{}",
                    shared.reblog_ins, shared.reblog_upd, shared.reblog_del
                ),
            );
            return Ok(finish_marker(view, parts));
        }
        "home" | "feed" => {}
        _ => anyhow::bail!("ranked-warm marker unknown view"),
    }
    let (actor, actor_key) = load_owner_actor(db, owner).await?;
    let light = load_light(db, owner, &actor, &actor_key).await?;
    moderation_parts(shared, &light, &mut parts);
    parts.insert(
        "events_maint".into(),
        format!("{}:{}", shared.events_upd, shared.events_del),
    );
    if view == "feed" {
        parts.insert("head".into(), shared.feed_head.to_string());
        return Ok(finish_marker(view, parts));
    }
    let slashed = format!("{actor}/");
    let like = format!("{actor}/notes/%");
    let cutoff =
        (chrono::Utc::now() - chrono::Duration::seconds(HOME_FLOOR_FRESH_SECS)).to_rfc3339();
    let fresh_limit = HOME_FLOOR_MIN_FRESH as i64;
    let per_actor = PER_ACTOR_CAP as i64;
    let events = db
        .query_one(
            FOLLOW_EVENTS_SQL,
            &[&actor, &slashed, &cutoff, &fresh_limit],
        )
        .await
        .context("ranked-warm marker follow events")?;
    let follow_event_id: i64 = events.get(0);
    let fresh_actors: i64 = events.get(1);
    let own = db
        .query_one(OWN_OUTBOX_SQL, &[&like])
        .await
        .context("ranked-warm marker own notes")?;
    let bsky = db
        .query_one(BSKY_WINDOW_SQL, &[&owner, &light.did, &per_actor])
        .await
        .context("ranked-warm marker bluesky")?;
    let rss = optional_aggregate(db, RSS_SQL, owner).await?;
    let nostr = nostr_marker(db, owner).await?;
    let nostr_boosts = nostr_boost_marker(db, owner, &actor).await?;
    let picks = match redis {
        Some(conn) => feed_picks_digest(conn, owner).await?,
        None => anyhow::bail!("ranked-warm marker redis unavailable"),
    };
    parts.insert("algo".into(), light.algo.to_string());
    parts.insert("downrank".into(), light.downrank.to_string());
    parts.insert("did".into(), light.did.clone());
    parts.insert("events".into(), follow_event_id.to_string());
    parts.insert("fresh".into(), fresh_actors.to_string());
    if home_floor_relevant(fresh_actors, light.tag_count) {
        parts.insert("floor".into(), shared.feed_head.to_string());
    } else {
        parts.insert("floor".into(), "off".into());
    }
    parts.insert(
        "own".into(),
        format!("{}:{}", own.get::<_, i64>(0), own.get::<_, String>(1)),
    );
    parts.insert(
        "graph".into(),
        format!("{}:{}", light.graph_count, light.graph_hash),
    );
    parts.insert(
        "bsky".into(),
        bsky.get::<_, Option<String>>(0).unwrap_or_default(),
    );
    parts.insert("obs".into(), light.observation.clone());
    parts.insert("signals".into(), light.signal_id.to_string());
    parts.insert("favourites".into(), light.favourites.clone());
    parts.insert(
        "tags".into(),
        format!("{}:{}", light.tag_count, light.tag_hash),
    );
    parts.insert("deprioritized".into(), light.deprioritized.clone());
    parts.insert("suppression".into(), light.suppression.clone());
    parts.insert("terms".into(), shared.terms.clone());
    parts.insert("rss".into(), rss);
    parts.insert("nostr".into(), nostr);
    parts.insert("nostr_boosts".into(), nostr_boosts);
    parts.insert("picks".into(), picks);
    Ok(finish_marker(view, parts))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn marker_flag_defaults_on_and_zero_disables_it() {
        assert!(enabled_from(None));
        assert!(enabled_from(Some("")));
        assert!(enabled_from(Some("  ")));
        assert!(enabled_from(Some("1")));
        assert!(enabled_from(Some("yes")));
        assert!(!enabled_from(Some("0")));
        assert!(!enabled_from(Some("false")));
        assert!(!enabled_from(Some("OFF")));
        assert!(!enabled_from(Some("no")));
    }

    #[test]
    fn max_age_defaults_to_ten_minutes_and_zero_is_kept() {
        assert_eq!(max_age_from(None), 600);
        assert_eq!(max_age_from(Some("")), 600);
        assert_eq!(max_age_from(Some("nope")), 600);
        assert_eq!(max_age_from(Some("-1")), 600);
        assert_eq!(max_age_from(Some("0")), 0);
        assert_eq!(max_age_from(Some("90")), 90);
    }

    #[test]
    fn matching_fresh_marker_skips_and_every_other_case_rebuilds() {
        let marker = "v=1;cache=v13;events=10";
        assert_eq!(
            decide(Some(marker), Some(marker), true, 30, 600),
            MarkerDecision::Skip
        );
        assert_eq!(
            decide(Some(marker), Some(marker), true, 599, 600),
            MarkerDecision::Skip
        );
        assert_eq!(
            decide(Some(marker), Some(marker), true, 600, 600),
            MarkerDecision::Rebuild("stale")
        );
        assert_eq!(
            decide(Some(marker), Some(marker), true, u64::MAX, 0),
            MarkerDecision::Skip,
            "a zero cap does not force a time-decay rebuild"
        );
        assert_eq!(
            decide(Some(marker), Some("v=1;cache=v13;events=11"), true, 1, 600),
            MarkerDecision::Rebuild("changed")
        );
        assert_eq!(
            decide(Some(marker), Some(marker), false, 1, 600),
            MarkerDecision::Rebuild("cache_miss")
        );
        assert_eq!(
            decide(None, Some(marker), true, 1, 600),
            MarkerDecision::Rebuild("cold")
        );
        assert_eq!(
            decide(Some(marker), None, true, 1, 600),
            MarkerDecision::Rebuild("unavailable")
        );
        assert_eq!(
            decide(Some(""), Some(marker), true, 1, 600),
            MarkerDecision::Rebuild("cold")
        );
        assert_eq!(
            decide(Some(marker), Some(""), true, 1, 600),
            MarkerDecision::Rebuild("unavailable")
        );
    }

    #[test]
    fn marker_text_is_stable_and_one_input_changes_it() {
        let mut parts: BTreeMap<String, String> = BTreeMap::new();
        parts.insert("events".into(), "10".into());
        parts.insert("bsky".into(), "abc".into());
        let first = {
            let mut copy = parts.clone();
            copy.insert("view".into(), "home".into());
            copy.insert("v".into(), RANK_INPUT_VERSION.into());
            copy.insert("cache".into(), CACHE_VERSION.into());
            copy.insert("burst".into(), "1".into());
            copy.into_iter()
                .map(|(k, v)| format!("{k}={v}"))
                .collect::<Vec<_>>()
                .join(";")
        };
        let mut again = parts.clone();
        again.insert("bsky".into(), "abc".into());
        let second = {
            let mut copy = again.clone();
            copy.insert("view".into(), "home".into());
            copy.insert("v".into(), RANK_INPUT_VERSION.into());
            copy.insert("cache".into(), CACHE_VERSION.into());
            copy.insert("burst".into(), "1".into());
            copy.into_iter()
                .map(|(k, v)| format!("{k}={v}"))
                .collect::<Vec<_>>()
                .join(";")
        };
        assert_eq!(first, second);
        again.insert("events".into(), "11".into());
        let changed = {
            let mut copy = again;
            copy.insert("view".into(), "home".into());
            copy.insert("v".into(), RANK_INPUT_VERSION.into());
            copy.insert("cache".into(), CACHE_VERSION.into());
            copy.insert("burst".into(), "1".into());
            copy.into_iter()
                .map(|(k, v)| format!("{k}={v}"))
                .collect::<Vec<_>>()
                .join(";")
        };
        assert_ne!(first, changed);
        assert!(first.contains("cache=v13"));
        assert!(first.contains(&format!("v={RANK_INPUT_VERSION}")));
    }

    #[test]
    fn floor_tracks_followed_tags_and_a_thin_follow_graph() {
        assert!(home_floor_relevant(0, 0));
        assert!(home_floor_relevant(39, 0));
        assert!(!home_floor_relevant(40, 0));
        assert!(home_floor_relevant(40, 1));
    }

    #[test]
    fn stored_marker_round_trips_and_rejects_an_empty_fingerprint() {
        let value = stored_value("events=1", "v13_home_u1_aon_abc", 1_700_000_000);
        let parsed = parse_stored(&value).unwrap();
        assert_eq!(parsed.marker, "events=1");
        assert_eq!(parsed.logical, "v13_home_u1_aon_abc");
        assert_eq!(parsed.built_at, 1_700_000_000);
        assert_eq!(age_secs(1_700_000_000, 1_700_000_030), 30);
        assert_eq!(age_secs(1_700_000_100, 1_700_000_000), 0);
        assert!(parse_stored(&json!({"marker": "", "logical": "k", "built_at": 1})).is_none());
        assert_eq!(
            redis_key(14, "home"),
            "vaak:timeline:ranked-input:v1:14:home"
        );
    }

    #[test]
    fn marker_queries_use_the_existing_indexes() {
        assert!(STATS_SQL.contains("pg_stat_user_tables"));
        assert!(FEED_HEAD_SQL.contains("ORDER BY created_at DESC, id DESC"));
        assert!(FEED_HEAD_SQL.contains("LIMIT 1"));
        assert!(
            FEED_HEAD_SQL.contains("type = ANY(ARRAY['Create','Announce','Quote','QuotePost'])")
        );
        assert!(LOCAL_NOTE_SQL.contains("ORDER BY published DESC"));
        assert!(LOCAL_NOTE_SQL.contains("LIMIT 1"));
        assert!(LOCAL_NOTE_SQL.contains("id LIKE 'https://mkultra.monster/users/%/notes/%'"));
        assert!(LOCAL_BOOST_SQL.contains("ORDER BY created_at DESC"));
        assert!(LOCAL_BOOST_SQL.contains("LIMIT 1"));
        assert!(OWNER_LIGHT_SQL.contains("ORDER BY created_at DESC, id DESC LIMIT 1"));
        assert!(
            OWNER_LIGHT_SQL.contains("idx_ap_user_signals_owner_time")
                || OWNER_LIGHT_SQL.contains("owner_user_id = $4::bigint")
        );
        assert!(OWNER_LIGHT_SQL.contains("ORDER BY last_seen_at DESC LIMIT 1"));
        assert!(OWNER_LIGHT_SQL.contains("kind = 'follow'"));
        assert!(FOLLOW_EVENTS_SQL.contains("actor_id IN (f.actor_id, f.actor_id || '/')"));
        assert!(FOLLOW_EVENTS_SQL.contains("ORDER BY created_at DESC, id DESC"));
        assert!(FOLLOW_EVENTS_SQL.contains("LIMIT 1"));
        assert!(OWN_OUTBOX_SQL.contains("WHERE id LIKE $1"));
        assert!(OWN_OUTBOX_SQL.contains("ORDER BY published DESC"));
        assert!(OWN_OUTBOX_SQL.contains("LIMIT 40"));
        assert!(BSKY_WINDOW_SQL.contains("author_did = g.target_did"));
        assert!(BSKY_WINDOW_SQL.contains("ORDER BY indexed_at DESC"));
        assert!(BSKY_WINDOW_SQL.contains("LIMIT $3"));
        assert!(BSKY_WINDOW_SQL.contains("l.bsky_uri = p.bsky_uri"));
        assert!(BSKY_WINDOW_SQL.contains("c.bsky_uri = p.bsky_uri"));
        assert!(!BSKY_WINDOW_SQL.contains("MAX(updated_at) FROM bsky_posts"));
        assert!(RSS_SQL.contains("feed_id = f.id"));
        assert!(RSS_SQL.contains("ORDER BY published_at DESC NULLS LAST"));
        assert!(RSS_SQL.contains("owner_user_id = $1::bigint AND f.enabled"));
        assert!(NOSTR_EVENTS_SQL.contains("pubkey = f.pubkey"));
        assert!(NOSTR_EVENTS_SQL.contains("ORDER BY created_at DESC"));
        assert!(NOSTR_BOOST_SQL.contains("owner_user_id::bigint = ANY($1::bigint[])"));
        assert!(NOSTR_BOOST_SQL.contains("ORDER BY created_at DESC"));
        assert!(TERMS_SQL.contains("WHERE enabled = 1"));
    }
}
