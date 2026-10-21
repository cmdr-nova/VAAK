//! Shadow notification unread badge (parity with PHP ap_masto_notifications_unread_state).

use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use tokio_postgres::Client;

use crate::config::Config;
use crate::hidden;
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
    let mut hidden = hidden::load_hidden_sets(db, owner_user_id).await?;
    hidden::expand_bsky_identities(db, &mut hidden).await;
    let subscriptions = hidden::load_post_subscriptions(db, owner_user_id).await?;

    let mut ids: Vec<String> = Vec::new();
    let mut mention_kept = 0_i64;
    let mut mention_skipped = 0_i64;
    let mut follow_kept = 0_i64;
    let mut hidden_skipped = 0_i64;
    let mut update_kept = 0_i64;

    let mention_rows = db
        .query(
            "SELECT id::bigint, created_at, activity_id, activity_type, type,
                    object_id, owner_actor_id, actor_id, content, in_reply_to
             FROM mentions
             WHERE owner_user_id = $1::bigint AND deleted_at IS NULL
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
                match hidden::is_favourited(db, owner_user_id, &object_id).await? {
                    true => {
                        update_kept += 1;
                        "update"
                    }
                    false => {
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

    // PHP retains legacy follow events when the current follower set is empty.
    // Bluesky follows are independent of ActivityPub follower membership.
    let follower_rows = db.query(
        "SELECT actor_id FROM followers WHERE owner_actor_id = $1 OR owner_actor_id = $2",
        &[&owner_actor_trim, &format!("{owner_actor_trim}/")],
    ).await.context("select current followers")?;
    let mut followers = std::collections::HashSet::new();
    for row in follower_rows {
        let actor: Option<String> = row.try_get(0)?;
        if let Some(actor) = actor {
            let actor = actor.trim().trim_end_matches('/');
            if !actor.is_empty() { followers.insert(actor.to_string()); }
        }
    }

    // Linked VAAK user -> Bluesky account (PHP ap_bsky_twin_local_actor_id).
    let twin_rows = db.query(
        "SELECT s.handle, s.did, u.actor_id FROM bsky_sessions s JOIN ap_users u ON u.id = s.owner_user_id",
        &[],
    ).await.context("select linked bluesky accounts")?;
    let mut twins = std::collections::HashMap::new();
    for row in twin_rows {
        let local: Option<String> = row.try_get(2)?;
        let local = local.unwrap_or_default().trim_end_matches('/').to_string();
        if local.is_empty() { continue; }
        for col in 0..2 {
            let key: Option<String> = row.try_get(col)?;
            let key = key.unwrap_or_default().trim().to_lowercase();
            if !key.is_empty() { twins.insert(key, local.clone()); }
        }
    }

    let follow_rows = db
        .query(
            "SELECT id::bigint, created_at, actor_id, action_taken FROM events
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
        let action: String = row.try_get::<_, Option<String>>(3)?.unwrap_or_default();
        if action == "local_accept_followback" && !actor_id.is_empty()
            && !followers.is_empty() && !followers.contains(&actor_id) {
            continue;
        }
        // A linked VAAK user's Bluesky twin following too is not news.
        if action == "bsky_follow" {
            if let Some(local) = bsky_twin_local_actor(&actor_id, &twins) {
                if followers.contains(local) {
                    continue;
                }
            }
        }
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
/// Local actor whose connected Bluesky account is `actor_id`
/// (`https://bsky.app/profile/{handle|did}`), keyed by lowercase handle/DID.
fn bsky_twin_local_actor<'a>(
    actor_id: &str,
    twins: &'a std::collections::HashMap<String, String>,
) -> Option<&'a String> {
    let rest = actor_id.trim_end_matches('/').strip_prefix("https://bsky.app/profile/")?;
    if rest.is_empty() || rest.contains(['/', '?', '#']) {
        return None;
    }
    let decoded = rest.replace("%3A", ":").replace("%3a", ":");
    twins.get(&decoded.trim_start_matches('@').to_lowercase())
}

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
             WHERE owner_user_id = $1::bigint AND timeline = 'notifications'
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
    let row = db.query_opt(
        "SELECT actor_id FROM ap_users WHERE id = $1::bigint AND disabled_at IS NULL LIMIT 1",
        &[&owner_user_id],
    ).await.context("select current notification owner")?
        .context("notification owner missing or disabled")?;
    let actor: Option<String> = row.try_get(0)?;
    let actor = actor.context("notification owner actor missing")?;
    anyhow::ensure!(actor.starts_with("https://"), "notification owner actor invalid");
    Ok(actor.trim_end_matches('/').to_string())
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
    // Publish the current eligible scan independently of list page size or warmth.
    // Recheck owner and marker before either cache is changed.
    load_owner_actor_id(&db, owner_user_id).await?;
    anyhow::ensure!(load_last_read_id(&db, owner_user_id).await? == state.last_read_id,
        "notification read marker changed during scan");
    let raw_state = state.clone();
    let shadow_payload = unread_cache_payload(&raw_state);
    let shadow_key = shadow_redis_key(owner_user_id, raw_state.scan, &raw_state.last_read_id);
    redis_util::json_set(&mut redis, &shadow_key, &shadow_payload, 120).await?;

    let live_key = live_redis_key(owner_user_id, state.scan, &state.last_read_id);
    if live {
        let payload = unread_cache_payload(&state);
        redis_util::json_set(&mut redis, &live_key, &payload, 45).await?;
        write_file_cache(cfg, owner_user_id, &state.last_read_id, &payload)?;
        tracing::info!(%live_key, count = state.count, "notif live cache written");
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
    let name = format!("notif_unread_{owner_user_id}_{hash12}.json");
    // owner_user_id is an i64, so this name cannot leave the cache directory.
    // Rejecting ".." is the path-traversal check CodeQL recognizes.
    if name.contains("..") {
        return Ok(());
    }
    let path = dir.join(name);
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
            "SELECT id::bigint FROM ap_users
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
    use super::{snowflake_id, snowflake_is_unread};

    #[test]
    fn bluesky_twin_maps_handle_and_did_to_local_actor() {
        let mut twins = std::collections::HashMap::new();
        twins.insert("valerie.bsky.mkultra.monster".to_string(), "https://mkultra.monster/users/cmdr_nova".to_string());
        twins.insert("did:plc:abc".to_string(), "https://mkultra.monster/users/cmdr_nova".to_string());
        let local = "https://mkultra.monster/users/cmdr_nova";
        assert_eq!(super::bsky_twin_local_actor("https://bsky.app/profile/Valerie.bsky.mkultra.monster", &twins).map(String::as_str), Some(local));
        assert_eq!(super::bsky_twin_local_actor("https://bsky.app/profile/did%3Aplc%3Aabc/", &twins).map(String::as_str), Some(local));
        assert!(super::bsky_twin_local_actor("https://bsky.app/profile/someone.bsky.social", &twins).is_none());
        assert!(super::bsky_twin_local_actor("https://mkultra.monster/users/cmdr_nova", &twins).is_none());
    }

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
    fn unread_marker_compares_large_decimal_ids_without_float_loss() {
        assert!(snowflake_is_unread("1000000000000000001", "1000000000000000000"));
        assert!(!snowflake_is_unread("1000000000000000000", "1000000000000000000"));
        assert!(!snowflake_is_unread("999999999999999999", "1000000000000000000"));
        assert!(snowflake_is_unread("42", "0"));
        assert!(!snowflake_is_unread("0", "0"));
    }
}
