//! Shadow notification unread badge (parity with PHP ap_masto_notifications_unread_state).

use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use tokio_postgres::Client;

use crate::config::Config;
use crate::hidden::{self, HiddenSets};
use crate::redis_util;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct UnreadState {
    pub count: i64,
    pub last_read_id: String,
    pub latest_unread_id: String,
    pub latest_id: String,
    pub scan: i64,
    pub owner_user_id: i64,
    pub mention_kept: i64,
    pub mention_skipped: i64,
    pub follow_kept: i64,
    pub hidden_skipped: i64,
    pub update_kept: i64,
    pub source: String,
    /// Newest-first snowflakes considered for this scan. Not part of the cache JSON.
    #[serde(default, skip)]
    pub scanned_ids: Vec<String>,
}

pub async fn compute_unread(db: &Client, owner_user_id: i64, scan: i64) -> Result<UnreadState> {
    let scan = scan.clamp(1, 80);
    let last_read = load_last_read_id(db, owner_user_id).await?;
    let owner_actor = load_owner_actor_id(db, owner_user_id).await?;
    let owner_actor_trim = owner_actor.trim_end_matches('/').to_string();
    let hidden = hidden::load_hidden_sets(db, owner_user_id)
        .await
        .unwrap_or_else(|e| {
            tracing::warn!(error = %e, "hidden sets load failed; continuing without");
            HiddenSets::default()
        });
    let subscriptions = hidden::load_post_subscriptions(db, owner_user_id)
        .await
        .unwrap_or_default();

    let mut ids: Vec<String> = Vec::new();
    let mut mention_kept = 0_i64;
    let mut mention_skipped = 0_i64;
    let mut follow_kept = 0_i64;
    let mut hidden_skipped = 0_i64;
    let mut update_kept = 0_i64;

    let mention_rows = db
        .query(
            "SELECT id, created_at, activity_id, activity_type, type,
                    object_id, owner_actor_id, actor_id, content, in_reply_to
             FROM mentions
             WHERE owner_user_id = $1 AND deleted_at IS NULL
             ORDER BY id DESC
             LIMIT 250",
            &[&owner_user_id],
        )
        .await
        .context("select mentions")?;
    let mut seen_activity = std::collections::HashSet::new();
    for row in mention_rows {
        let activity_id: String = row
            .try_get::<_, Option<String>>(2)?
            .unwrap_or_default()
            .trim()
            .to_string();
        if !activity_id.is_empty() && !seen_activity.insert(activity_id.clone()) {
            mention_skipped += 1;
            continue;
        }

        let id: i64 = row.get(0);
        let created: Option<String> = row.try_get(1).ok().flatten();
        let activity_type: String = row.try_get::<_, Option<String>>(3)?.unwrap_or_default();
        let obj_type: String = row.try_get::<_, Option<String>>(4)?.unwrap_or_default();
        let object_id: String = row.try_get::<_, Option<String>>(5)?.unwrap_or_default();
        let owner_actor_id: String = row.try_get::<_, Option<String>>(6)?.unwrap_or_default();
        let actor_id: String = row.try_get::<_, Option<String>>(7)?.unwrap_or_default();
        let content: String = row.try_get::<_, Option<String>>(8)?.unwrap_or_default();
        let in_reply_to: String = row.try_get::<_, Option<String>>(9)?.unwrap_or_default();

        let our_prefix = {
            let o = owner_actor_id.trim_end_matches('/');
            if o.is_empty() {
                owner_actor_trim.as_str()
            } else {
                o
            }
        };

        let actor_trim = actor_id.trim_end_matches('/');
        if !actor_trim.is_empty() && hidden.is_hidden(actor_trim) {
            hidden_skipped += 1;
            mention_skipped += 1;
            continue;
        }

        let kind = mention_notif_type(
            &activity_type,
            &obj_type,
            &object_id,
            &actor_id,
            &content,
            &in_reply_to,
            our_prefix,
            &activity_id,
            &subscriptions,
        );

        let kind = match kind {
            Some(k) => k,
            None if activity_type.eq_ignore_ascii_case("update") => {
                // Update of a post we favourited (Note-like only).
                let obj = obj_type.to_ascii_lowercase();
                if !matches!(obj.as_str(), "note" | "article" | "page" | "question" | "") {
                    mention_skipped += 1;
                    continue;
                }
                if object_id.contains("/videos/") || object_id.contains("/w/") {
                    mention_skipped += 1;
                    continue;
                }
                let our_note = object_id.starts_with(&format!("{our_prefix}/notes/"))
                    || object_id.starts_with(&format!("{our_prefix}/statuses/"));
                if our_note {
                    mention_skipped += 1;
                    continue;
                }
                match hidden::is_favourited(db, owner_user_id, &object_id).await {
                    Ok(true) => {
                        update_kept += 1;
                        "update"
                    }
                    _ => {
                        mention_skipped += 1;
                        continue;
                    }
                }
            }
            None => {
                mention_skipped += 1;
                continue;
            }
        };
        let _ = kind;

        // Snowflake type 5 = mention/fav/boost/quote/update row in mentions table.
        ids.push(snowflake_id(created.as_deref(), id, 5));
        mention_kept += 1;
    }

    let follow_rows = db
        .query(
            "SELECT id, created_at, actor_id, action_taken FROM events
             WHERE type = 'Follow'
               AND action_taken IN ('local_accept_followback', 'bsky_follow')
               AND (target_actor = $1 OR target_actor = $2)
             ORDER BY id DESC
             LIMIT 100",
            &[&owner_actor_trim, &format!("{owner_actor_trim}/")],
        )
        .await
        .context("select follow events")?;
    for row in follow_rows {
        let id: i64 = row.get(0);
        let created: Option<String> = row.try_get(1).ok().flatten();
        let actor_id: String = row
            .try_get::<_, Option<String>>(2)?
            .unwrap_or_default()
            .trim_end_matches('/')
            .to_string();
        // Skip self.
        if !actor_id.is_empty() && actor_id == owner_actor_trim {
            continue;
        }
        if !actor_id.is_empty() && hidden.is_hidden(&actor_id) {
            hidden_skipped += 1;
            continue;
        }
        // Snowflake type 6 = follow event.
        ids.push(snowflake_id(created.as_deref(), id, 6));
        follow_kept += 1;
    }

    // Newest-first by snowflake length then lexicographic (matches PHP).
    ids.sort_by(|a, b| b.len().cmp(&a.len()).then_with(|| b.cmp(a)));
    ids.truncate(scan as usize);

    let mut count = 0_i64;
    let mut latest_id = String::new();
    let mut latest_unread_id = String::new();
    let mut scanned_ids = Vec::with_capacity(ids.len());
    for nid_raw in &ids {
        let nid = digits_only(nid_raw);
        scanned_ids.push(nid.clone());
        if latest_id.is_empty() {
            latest_id = nid.clone();
        }
        if snowflake_is_unread(&nid, &last_read) {
            count += 1;
            if latest_unread_id.is_empty() {
                latest_unread_id = nid;
            }
        }
    }

    Ok(UnreadState {
        count,
        last_read_id: last_read,
        latest_unread_id,
        latest_id,
        scan,
        owner_user_id,
        mention_kept,
        mention_skipped,
        follow_kept,
        hidden_skipped,
        update_kept,
        source: "vaak-worker-shadow".into(),
        scanned_ids,
    })
}

/// Thin port of PHP `ap_masto_mention_notif_type` — returns Some(kind) when the
/// row should count toward the badge. `update` returns None so the caller can
/// run the favourites lookup.
fn mention_notif_type(
    activity_type: &str,
    obj_type: &str,
    object_id: &str,
    actor_id: &str,
    content: &str,
    in_reply_to: &str,
    our_prefix: &str,
    activity_id: &str,
    subscriptions: &std::collections::HashSet<String>,
) -> Option<&'static str> {
    let activity = activity_type.to_ascii_lowercase();
    let obj = obj_type.to_ascii_lowercase();
    let actor = actor_id.trim_end_matches('/');
    let our = our_prefix.trim_end_matches('/');

    if object_id.contains("#webmention-") || activity == "webmention" {
        return Some("mention");
    }
    if !actor.is_empty() && actor == our {
        return None;
    }

    let is_bsky = activity_id.starts_with("at://")
        || actor.starts_with("https://bsky.app/")
        || object_id.starts_with("https://bsky.app/")
        || object_id.starts_with("bsky:");

    if is_bsky {
        if activity == "like" || obj == "like" {
            return Some("favourite");
        }
        if activity == "announce" || obj == "announce" {
            return Some("reblog");
        }
        if activity == "quote"
            || activity == "quotepost"
            || obj == "quote"
            || obj == "quotepost"
            || object_id.contains("#quote-")
            || content.contains('↪')
        {
            return Some("quote");
        }
        if activity == "create" || activity == "mention" || obj == "note" || !content.is_empty() {
            return Some("mention");
        }
        return None;
    }

    if activity == "like" || obj == "like" || activity == "emojireact" {
        return Some("favourite");
    }
    if activity == "bite" || obj == "bite" {
        return Some("bite");
    }
    if activity == "announce" || obj == "announce" {
        let our_note = object_id.starts_with(&format!("{our}/notes/"))
            || object_id.starts_with(&format!("{our}/statuses/"));
        return Some(if our_note { "reblog" } else { "mention" });
    }
    if activity == "quote"
        || activity == "quotepost"
        || obj == "quote"
        || obj == "quotepost"
        || object_id.contains("#quote-")
    {
        return Some("quote");
    }
    // Caller handles Update + favourite lookup.
    if activity == "update" {
        return None;
    }
    if matches!(
        obj.as_str(),
        "person" | "application" | "service" | "group"
    ) {
        return None;
    }
    if matches!(obj.as_str(), "note" | "article" | "page" | "question" | "") || !content.is_empty()
    {
        let reply = in_reply_to.trim_end_matches('/');
        if !reply.is_empty() && reply.starts_with(&format!("{our}/notes/")) {
            return Some("mention");
        }
        if hidden::content_addresses_local(content, our) {
            return Some("mention");
        }
        if !actor.is_empty() && subscriptions.contains(actor) {
            return Some("status");
        }
        return None;
    }
    None
}

/// PHP `ap_masto_snowflake_id`: seconds * 1e9 + (type%10)*1e8 + (dbId%1e8).
pub fn snowflake_id(created_at: Option<&str>, db_id: i64, type_num: i64) -> String {
    let secs = created_at
        .and_then(parse_epoch_secs)
        .unwrap_or_else(|| chrono::Utc::now().timestamp());
    let v = secs * 1_000_000_000 + (type_num.rem_euclid(10) * 100_000_000) + db_id.rem_euclid(100_000_000);
    v.to_string()
}

async fn load_last_read_id(db: &Client, owner_user_id: i64) -> Result<String> {
    let row = db
        .query_opt(
            "SELECT last_read_id FROM masto_markers
             WHERE owner_user_id = $1 AND timeline = 'notifications'
             LIMIT 1",
            &[&owner_user_id],
        )
        .await
        .context("select masto_markers")?;
    let Some(row) = row else {
        return Ok("0".to_string());
    };
    let last: Option<String> = row.try_get(0).ok().flatten();
    let last = last.unwrap_or_else(|| "0".to_string());
    let digits: String = last.chars().filter(|c| c.is_ascii_digit()).collect();
    Ok(if digits.is_empty() {
        "0".into()
    } else {
        digits
    })
}

async fn load_owner_actor_id(db: &Client, owner_user_id: i64) -> Result<String> {
    if let Ok(Some(row)) = db
        .query_opt(
            "SELECT actor_id FROM ap_users WHERE id = $1 LIMIT 1",
            &[&owner_user_id],
        )
        .await
    {
        let actor: Option<String> = row.try_get(0).ok().flatten();
        if let Some(actor) = actor {
            if actor.starts_with("https://") {
                return Ok(actor.trim_end_matches('/').to_string());
            }
        }
    }
    Ok("https://mkultra.monster/users/cmdr_nova".to_string())
}

fn parse_epoch_secs(s: &str) -> Option<i64> {
    if let Ok(dt) = chrono::DateTime::parse_from_rfc3339(s) {
        return Some(dt.timestamp());
    }
    if let Ok(dt) = chrono::DateTime::parse_from_str(s, "%Y-%m-%d %H:%M:%S%.f%z") {
        return Some(dt.timestamp());
    }
    if let Ok(dt) = chrono::DateTime::parse_from_str(s, "%Y-%m-%dT%H:%M:%S%.f%z") {
        return Some(dt.timestamp());
    }
    // PHP strtotime-friendly: "2026-10-02 18:52:59+00"
    if let Ok(dt) = chrono::DateTime::parse_from_str(s, "%Y-%m-%d %H:%M:%S%z") {
        return Some(dt.timestamp());
    }
    None
}

fn live_redis_key(owner_user_id: i64, scan: i64, last_read: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(last_read.as_bytes());
    let hash = hex::encode(hasher.finalize());
    format!("vaak:notifications:v1:unread:{owner_user_id}:{scan}:{hash}")
}

fn shadow_redis_key(owner_user_id: i64, scan: i64, last_read: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(last_read.as_bytes());
    let hash = hex::encode(hasher.finalize());
    format!("vaak:shadow:notifications:v1:unread:{owner_user_id}:{scan}:{hash}")
}

fn decimal_at_least(candidate: &str, watermark: &str) -> bool {
    let candidate = candidate.trim_start_matches('0');
    let watermark = watermark.trim_start_matches('0');
    if watermark.is_empty() {
        return true;
    }
    if candidate.is_empty() {
        return false;
    }
    candidate.len() > watermark.len()
        || (candidate.len() == watermark.len() && candidate >= watermark)
}

fn list_is_ready(list_latest: Option<&str>, badge_latest: &str) -> bool {
    if badge_latest.is_empty() {
        return true;
    }
    matches!(list_latest, Some(latest) if !latest.is_empty() && decimal_at_least(latest, badge_latest))
}

fn digits_only(raw: &str) -> String {
    let nid: String = raw.chars().filter(|c| c.is_ascii_digit()).collect();
    if nid.is_empty() {
        "0".into()
    } else {
        nid
    }
}

fn snowflake_is_unread(nid: &str, last_read: &str) -> bool {
    if nid.is_empty() || nid == "0" {
        return false;
    }
    if nid.len() == last_read.len() {
        nid > last_read
    } else {
        nid.len() > last_read.len()
    }
}

/// Unread ids the warmed Mentions list can already serve.
///
/// `ids` are newest-first. A missing list publishes nothing, so the nav badge
/// cannot get ahead of the page. Ids newer than `list_latest` are left out;
/// older unread ids still count.
fn clamp_published_unread(ids: &[String], last_read: &str, list_latest: Option<&str>) -> (i64, String) {
    let Some(list_latest) = list_latest.map(str::trim).filter(|s| !s.is_empty()) else {
        return (0, String::new());
    };
    let mut count = 0_i64;
    let mut latest_unread = String::new();
    for raw in ids {
        let nid = digits_only(raw);
        if !decimal_at_least(list_latest, &nid) {
            continue;
        }
        if snowflake_is_unread(&nid, last_read) {
            count += 1;
            if latest_unread.is_empty() {
                latest_unread = nid;
            }
        }
    }
    (count, latest_unread)
}

fn unread_cache_payload(state: &UnreadState) -> serde_json::Value {
    serde_json::json!({
        "c": state.count,
        "u": state.latest_unread_id,
        "l": state.latest_id,
        "ts": chrono::Utc::now().timestamp(),
        "source": state.source,
        "mention_kept": state.mention_kept,
        "mention_skipped": state.mention_skipped,
        "follow_kept": state.follow_kept,
        "hidden_skipped": state.hidden_skipped,
        "update_kept": state.update_kept,
    })
}

async fn list_watermark(
    redis: &mut redis::aio::MultiplexedConnection,
    owner_user_id: i64,
) -> Result<Option<String>> {
    let key = crate::notif_list::notifications_list_redis_key(
        owner_user_id,
        40,
        None,
        None,
        &[],
    );
    let Some(payload) = redis_util::json_get(redis, &key).await? else {
        return Ok(None);
    };
    let latest = payload
        .get("latest_id")
        .and_then(|v| v.as_str())
        .map(str::to_string)
        .or_else(|| {
            payload.get("items").and_then(|v| v.as_array()).and_then(|items| {
                items
                    .iter()
                    .filter_map(|item| item.get("id").and_then(|v| v.as_str()))
                    .filter(|id| id.chars().all(|c| c.is_ascii_digit()))
                    .max_by(|a, b| {
                        a.len().cmp(&b.len()).then_with(|| a.cmp(b))
                    })
                    .map(str::to_string)
            })
        });
    Ok(latest)
}

/// Compute + write Redis. When `live`, also writes the production notif key + file cache.
pub async fn compute_and_cache(
    cfg: &Config,
    owner_user_id: i64,
    compare: bool,
    live: bool,
) -> Result<serde_json::Value> {
    let db = crate::db::connect(&cfg.database_url).await?;
    let mut state = compute_unread(&db, owner_user_id, 80).await?;
    if live {
        state.source = "vaak-worker-live".into();
    }

    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    // Shadow keeps the raw scan. Live publish is clamped to the list watermark.
    let raw_state = state.clone();
    let shadow_payload = unread_cache_payload(&raw_state);
    let shadow_key = shadow_redis_key(owner_user_id, raw_state.scan, &raw_state.last_read_id);
    redis_util::json_set(&mut redis, &shadow_key, &shadow_payload, 120).await?;

    let live_key = live_redis_key(owner_user_id, state.scan, &state.last_read_id);
    let mut badge_deferred = false;
    let mut list_latest_dbg: Option<String> = None;
    if live {
        let list_latest = list_watermark(&mut redis, owner_user_id).await?;
        list_latest_dbg = list_latest.clone();
        if !list_is_ready(list_latest.as_deref(), &raw_state.latest_id) {
            let (count, unread) = clamp_published_unread(
                &raw_state.scanned_ids,
                &raw_state.last_read_id,
                list_latest.as_deref(),
            );
            state.count = count;
            state.latest_unread_id = unread;
            // Keep latest_id at the real tip. The published count is what clients paint.
            badge_deferred = true;
            tracing::info!(
                owner = owner_user_id,
                raw_count = raw_state.count,
                published = count,
                badge_latest = %raw_state.latest_id,
                list_latest = ?list_latest,
                "notif badge clamped until list cache reaches watermark"
            );
        }
        let payload = unread_cache_payload(&state);
        // Always overwrite. Skipping the write left the previous count in Redis
        // and the HTTP body still returned the unclamped count to PHP.
        redis_util::json_set(&mut redis, &live_key, &payload, 45).await?;
        write_file_cache(cfg, owner_user_id, &state.last_read_id, &payload)?;
        tracing::info!(%live_key, count = state.count, deferred = badge_deferred, "notif live cache written");
    }

    if compare {
        let live_cache = redis_util::json_get(&mut redis, &live_key).await?;
        tracing::info!(%shadow_key, live = ?live_cache, "notif compare");
        Ok(serde_json::json!({
            "shadow": raw_state,
            "live_mode": live,
            "shadow_redis_key": shadow_key,
            "live_redis_key": live_key,
            "live_cache": live_cache,
        }))
    } else {
        let mut v = serde_json::to_value(&state)?;
        if let Some(obj) = v.as_object_mut() {
            obj.insert("live_mode".into(), serde_json::json!(live));
            obj.insert("live_redis_key".into(), serde_json::json!(live_key));
            if badge_deferred {
                obj.insert("badge_deferred".into(), serde_json::json!(true));
                obj.insert("list_latest_id".into(), serde_json::json!(list_latest_dbg));
                obj.insert("raw_count".into(), serde_json::json!(raw_state.count));
            }
        }
        Ok(v)
    }
}

fn write_file_cache(
    cfg: &Config,
    owner_user_id: i64,
    last_read: &str,
    payload: &serde_json::Value,
) -> Result<()> {
    use sha1::{Digest, Sha1};
    let mut hasher = Sha1::new();
    hasher.update(last_read.as_bytes());
    let hash12 = hex::encode(hasher.finalize());
    let hash12 = &hash12[..12.min(hash12.len())];
    let dir = &cfg.notif_cache_dir;
    if !dir.is_dir() {
        return Ok(());
    }
    let path = dir.join(format!("notif_unread_{owner_user_id}_{hash12}.json"));
    let lean = serde_json::json!({
        "c": payload.get("c").cloned().unwrap_or(serde_json::json!(0)),
        "u": payload.get("u").cloned().unwrap_or(serde_json::json!("")),
        "l": payload.get("l").cloned().unwrap_or(serde_json::json!("")),
        "ts": payload.get("ts").cloned().unwrap_or(serde_json::json!(0)),
    });
    std::fs::write(&path, serde_json::to_vec(&lean)?)
        .with_context(|| format!("write {}", path.display()))?;
    Ok(())
}

pub async fn run_once(
    cfg: &Config,
    owner_user_id: i64,
    compare: bool,
    live: bool,
) -> Result<UnreadState> {
    let body = compute_and_cache(cfg, owner_user_id, compare, live).await?;
    println!("{}", serde_json::to_string_pretty(&body)?);
    if let Ok(state) = serde_json::from_value::<UnreadState>(body.clone()) {
        Ok(state)
    } else if let Some(shadow) = body.get("shadow") {
        Ok(serde_json::from_value(shadow.clone())?)
    } else {
        anyhow::bail!("unexpected notif payload shape");
    }
}

/// Local account ids for multi-user badge refresh (`disabled_at IS NULL`).
pub async fn list_local_owner_ids(db: &Client) -> Result<Vec<i64>> {
    let rows = db
        .query(
            "SELECT id FROM ap_users
             WHERE disabled_at IS NULL
             ORDER BY id ASC",
            &[],
        )
        .await
        .context("select ap_users for notif badge")?;
    Ok(rows.iter().map(|r| r.get::<_, i64>(0)).collect())
}

pub async fn run_loop(
    cfg: &Config,
    owner_user_id: i64,
    interval_secs: u64,
    compare: bool,
    live: bool,
) -> Result<()> {
    let interval = std::time::Duration::from_secs(interval_secs.max(5));
    // owner_user_id <= 0 → refresh every non-disabled local account each tick
    // (multi-user cutover). Positive id keeps the single-owner soak path.
    loop {
        let owners: Vec<i64> = if owner_user_id > 0 {
            vec![owner_user_id]
        } else {
            match crate::db::connect(&cfg.database_url).await {
                Ok(db) => match list_local_owner_ids(&db).await {
                    Ok(ids) if !ids.is_empty() => ids,
                    Ok(_) => {
                        tracing::warn!("notif loop: no local ap_users; sleeping");
                        vec![]
                    }
                    Err(e) => {
                        tracing::error!(error = %e, "notif loop: list owners failed");
                        vec![]
                    }
                },
                Err(e) => {
                    tracing::error!(error = %e, "notif loop: db connect failed");
                    vec![]
                }
            }
        };
        for owner in owners {
            match run_once(cfg, owner, compare, live).await {
                Ok(state) => tracing::info!(
                    owner,
                    count = state.count,
                    latest = %state.latest_id,
                    live,
                    "notif ok"
                ),
                Err(e) => tracing::error!(owner, error = %e, "notif failed"),
            }
        }
        tokio::time::sleep(interval).await;
    }
}

#[cfg(test)]
mod tests {
    use super::{clamp_published_unread, decimal_at_least, list_is_ready, snowflake_id};

    #[test]
    fn snowflake_matches_php_shape() {
        // seconds * 1e9 + type*1e8 + dbId
        // 2026-10-02T18:52:59Z ≈ use fixed: 1790967179 if that is the unix — we
        // just assert structure from a known seconds value via RFC3339 parse.
        let id = snowflake_id(Some("2026-10-02T18:52:59Z"), 1018, 5);
        let n: i64 = id.parse().unwrap();
        assert_eq!(n % 100_000_000, 1018);
        assert_eq!((n % 1_000_000_000) / 100_000_000, 5);
    }

    #[test]
    fn watermark_compares_large_decimal_ids_without_float_loss() {
        assert!(decimal_at_least("1000000000000000001", "1000000000000000000"));
        assert!(!decimal_at_least("999999999999999999", "1000000000000000000"));
        assert!(decimal_at_least("00042", "42"));
        assert!(decimal_at_least("42", ""));
        assert!(!list_is_ready(Some(""), "42"));
        assert!(!list_is_ready(None, "42"));
        assert!(list_is_ready(Some("42"), "42"));
        assert!(list_is_ready(None, ""));
    }

    #[test]
    fn badge_publish_stops_at_the_list_watermark() {
        let ids = vec![
            "1791371595600739639".to_string(),
            "1791368209500001230".to_string(),
            "1791359835500001227".to_string(),
        ];
        let last_read = "1791368209500001230";
        let (count, unread) = clamp_published_unread(&ids, last_read, None);
        assert_eq!(count, 0);
        assert_eq!(unread, "");
        let (count, unread) =
            clamp_published_unread(&ids, last_read, Some("1791368209500001230"));
        assert_eq!(count, 0, "follow ahead of the list must not badge");
        assert_eq!(unread, "");
        let (count, unread) =
            clamp_published_unread(&ids, last_read, Some("1791371595600739639"));
        assert_eq!(count, 1);
        assert_eq!(unread, "1791371595600739639");
        let (count, unread) = clamp_published_unread(
            &["300".into(), "200".into(), "100".into()],
            "50",
            Some("200"),
        );
        assert_eq!(count, 2);
        assert_eq!(unread, "200");
    }
}
