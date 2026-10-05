//! Viewer interaction flags for Mastodon-shaped statuses (0.7.19).
//!
//! Axum Home/Profile lean paint and `/api/v1/timelines/home` read warm hydrate
//! envelopes that historically hard-coded favourited/reblogged/bookmarked to
//! false. PHP cards call `ap_masto_status_flags_prefetch` on every paint; this
//! module is the Rust parity so likes/boosts/bookmarks survive refresh and stay
//! consistent with Ice Cubes + Bluesky-synced `masto_*` rows.

use std::collections::HashSet;

use anyhow::{Context, Result};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use tokio_postgres::Client;

fn trim_slash(s: &str) -> &str {
    s.trim().trim_end_matches('/')
}

fn push_key(set: &mut HashSet<String>, raw: &str) {
    let t = raw.trim();
    if t.is_empty() {
        return;
    }
    set.insert(t.to_string());
    let trimmed = trim_slash(t);
    if !trimmed.is_empty() {
        set.insert(trimmed.to_string());
        set.insert(format!("{trimmed}/"));
    }
}

fn bsky_status_key(at_uri: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(at_uri.as_bytes());
    let digest = hex::encode(hasher.finalize());
    format!("bsky:{}", &digest[..32.min(digest.len())])
}

/// Collect status_id + object_id lookup keys for one status (and nested reblog).
fn collect_keys(st: &Value, status_ids: &mut HashSet<String>, object_ids: &mut HashSet<String>) {
    let id = st
        .get("id")
        .and_then(|v| v.as_str())
        .map(str::to_string)
        .or_else(|| st.get("id").and_then(|v| v.as_i64()).map(|n| n.to_string()))
        .unwrap_or_default();
    if !id.is_empty() {
        push_key(status_ids, &id);
        // Bluesky hydrate historically used at:// as id; masto_* rows use bsky:<hash>.
        if id.starts_with("at://") {
            push_key(status_ids, &bsky_status_key(&id));
            push_key(object_ids, &id);
        }
        if id.starts_with("bsky:") || id.starts_with("rss:") {
            push_key(object_ids, &id);
        }
    }

    for key in ["uri", "url"] {
        if let Some(v) = st.get(key).and_then(|v| v.as_str()) {
            push_key(object_ids, v);
            if v.starts_with("at://") {
                push_key(status_ids, &bsky_status_key(v));
                push_key(object_ids, v);
            }
        }
    }

    // Bluesky https permalink often stored as object_id in masto_favourites.
    if let Some(https) = st
        .get("url")
        .and_then(|v| v.as_str())
        .filter(|u| u.contains("bsky.app/"))
    {
        push_key(object_ids, https);
    }

    if let Some(inner) = st.get("reblog").filter(|v| v.is_object()) {
        collect_keys(inner, status_ids, object_ids);
    }
}

fn apply_one(st: &mut Value, fav: &HashSet<String>, bm: &HashSet<String>, rb: &HashSet<String>) {
    let mut sids = HashSet::new();
    let mut oids = HashSet::new();
    collect_keys(st, &mut sids, &mut oids);
    let favourited = sids.iter().any(|k| fav.contains(k)) || oids.iter().any(|k| fav.contains(k));
    let bookmarked = sids.iter().any(|k| bm.contains(k)) || oids.iter().any(|k| bm.contains(k));
    let reblogged = sids.iter().any(|k| rb.contains(k)) || oids.iter().any(|k| rb.contains(k));
    if favourited {
        st["favourited"] = json!(true);
    }
    if bookmarked {
        st["bookmarked"] = json!(true);
    }
    if reblogged {
        st["reblogged"] = json!(true);
    }
    if let Some(inner) = st.get_mut("reblog").filter(|v| v.is_object()) {
        // Nested original keeps its own viewer flags (PHP applies recursively).
        let mut nested = inner.clone();
        apply_one(&mut nested, fav, bm, rb);
        *inner = nested;
    }
}

async fn load_hits(
    db: &Client,
    owner: i64,
    status_ids: &[String],
    object_ids: &[String],
) -> Result<(HashSet<String>, HashSet<String>, HashSet<String>)> {
    let mut fav = HashSet::new();
    let mut bm = HashSet::new();
    let mut rb = HashSet::new();
    if owner < 1 {
        return Ok((fav, bm, rb));
    }

    if !status_ids.is_empty() {
        for row in db
            .query(
                "SELECT status_id FROM masto_favourites
                 WHERE owner_user_id = $1 AND status_id = ANY($2)",
                &[&owner, &status_ids],
            )
            .await
            .context("select masto_favourites by status_id")?
        {
            let sid: String = row.get(0);
            push_key(&mut fav, &sid);
        }
        for row in db
            .query(
                "SELECT status_id FROM masto_bookmarks
                 WHERE owner_user_id = $1 AND status_id = ANY($2)",
                &[&owner, &status_ids],
            )
            .await
            .context("select masto_bookmarks by status_id")?
        {
            let sid: String = row.get(0);
            push_key(&mut bm, &sid);
        }
        for row in db
            .query(
                "SELECT status_id FROM masto_reblogs
                 WHERE owner_user_id = $1 AND status_id = ANY($2)",
                &[&owner, &status_ids],
            )
            .await
            .context("select masto_reblogs by status_id")?
        {
            let sid: String = row.get(0);
            push_key(&mut rb, &sid);
        }
    }

    if !object_ids.is_empty() {
        for row in db
            .query(
                "SELECT object_id FROM masto_favourites
                 WHERE owner_user_id = $1 AND object_id = ANY($2)",
                &[&owner, &object_ids],
            )
            .await
            .context("select masto_favourites by object_id")?
        {
            let oid: Option<String> = row.get(0);
            if let Some(oid) = oid {
                push_key(&mut fav, &oid);
            }
        }
        for row in db
            .query(
                "SELECT object_id FROM masto_bookmarks
                 WHERE owner_user_id = $1 AND object_id = ANY($2)",
                &[&owner, &object_ids],
            )
            .await
            .context("select masto_bookmarks by object_id")?
        {
            let oid: Option<String> = row.get(0);
            if let Some(oid) = oid {
                push_key(&mut bm, &oid);
            }
        }
        for row in db
            .query(
                "SELECT object_id FROM masto_reblogs
                 WHERE owner_user_id = $1 AND object_id = ANY($2)",
                &[&owner, &object_ids],
            )
            .await
            .context("select masto_reblogs by object_id")?
        {
            let oid: Option<String> = row.get(0);
            if let Some(oid) = oid {
                push_key(&mut rb, &oid);
            }
        }
    }

    Ok((fav, bm, rb))
}

/// Overlay live viewer flags from masto_favourites / bookmarks / reblogs.
pub async fn apply_to_statuses(db: &Client, owner_user_id: i64, statuses: &mut [Value]) -> Result<()> {
    if owner_user_id < 1 || statuses.is_empty() {
        return Ok(());
    }
    let mut status_ids = HashSet::new();
    let mut object_ids = HashSet::new();
    for st in statuses.iter() {
        if st.is_object() {
            collect_keys(st, &mut status_ids, &mut object_ids);
        }
    }
    if status_ids.is_empty() && object_ids.is_empty() {
        return Ok(());
    }
    let sid_list: Vec<String> = status_ids.into_iter().collect();
    let oid_list: Vec<String> = object_ids.into_iter().collect();
    let (fav, bm, rb) = load_hits(db, owner_user_id, &sid_list, &oid_list).await?;
    if fav.is_empty() && bm.is_empty() && rb.is_empty() {
        return Ok(());
    }
    for st in statuses.iter_mut() {
        if st.is_object() {
            apply_one(st, &fav, &bm, &rb);
        }
    }
    Ok(())
}

/// Convenience: connect and apply (Home HTML / profile HTML paint paths).
pub async fn apply_to_statuses_with_cfg(
    database_url: &str,
    owner_user_id: i64,
    statuses: &mut [Value],
) -> Result<()> {
    if owner_user_id < 1 || statuses.is_empty() {
        return Ok(());
    }
    let db = crate::db::connect(database_url).await?;
    apply_to_statuses(&db, owner_user_id, statuses).await
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn bsky_at_uri_expands_hash_status_key() {
        let st = json!({
            "id": "at://did:plc:abc/app.bsky.feed.post/xyz",
            "uri": "at://did:plc:abc/app.bsky.feed.post/xyz",
            "url": "https://bsky.app/profile/did:plc:abc/post/xyz",
            "favourited": false
        });
        let mut sids = HashSet::new();
        let mut oids = HashSet::new();
        collect_keys(&st, &mut sids, &mut oids);
        assert!(sids.iter().any(|k| k.starts_with("bsky:")));
        assert!(oids.contains("https://bsky.app/profile/did:plc:abc/post/xyz"));
    }

    #[test]
    fn apply_one_marks_by_object_id() {
        let mut st = json!({
            "id": "1791",
            "uri": "https://example.com/users/a/statuses/1",
            "url": "https://example.com/users/a/statuses/1",
            "favourited": false,
            "bookmarked": false,
            "reblogged": false
        });
        let mut fav = HashSet::new();
        push_key(&mut fav, "https://example.com/users/a/statuses/1");
        let bm = HashSet::new();
        let rb = HashSet::new();
        apply_one(&mut st, &fav, &bm, &rb);
        assert_eq!(st["favourited"], json!(true));
        assert_eq!(st["bookmarked"], json!(false));
    }
}
