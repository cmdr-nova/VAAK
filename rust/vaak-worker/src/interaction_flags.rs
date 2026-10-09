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

fn at_record_did(uri: &str) -> Option<&str> {
    let rest = uri.trim().strip_prefix("at://")?;
    let did = rest.split('/').next().unwrap_or("");
    if did.starts_with("did:") {
        Some(did)
    } else {
        None
    }
}

/// Keep a baked Bluesky like/repost record only when this owner still has the
/// action and the record URI belongs to their DID. Shared raw_json viewer
/// state is the ingesting account's, so another local account must not undo it.
fn keep_bsky_record(uri: &str, active: bool, viewer_did: Option<&str>) -> bool {
    if !active {
        return false;
    }
    match (viewer_did, at_record_did(uri)) {
        (Some(viewer), Some(record)) => viewer == record,
        _ => false,
    }
}

fn scrub_bsky_record(st: &mut Value, key: &str, active: bool, viewer_did: Option<&str>) {
    let keep = st
        .get(key)
        .and_then(|v| v.as_str())
        .map(|uri| keep_bsky_record(uri, active, viewer_did))
        .unwrap_or(false);
    if !keep {
        if let Some(obj) = st.as_object_mut() {
            obj.remove(key);
        }
    }
}

fn apply_one(
    st: &mut Value,
    fav: &HashSet<String>,
    bm: &HashSet<String>,
    rb: &HashSet<String>,
    viewer_did: Option<&str>,
) {
    let mut sids = HashSet::new();
    let mut oids = HashSet::new();
    collect_keys(st, &mut sids, &mut oids);
    let favourited = sids.iter().any(|k| fav.contains(k)) || oids.iter().any(|k| fav.contains(k));
    let bookmarked = sids.iter().any(|k| bm.contains(k)) || oids.iter().any(|k| bm.contains(k));
    let reblogged = sids.iter().any(|k| rb.contains(k)) || oids.iter().any(|k| rb.contains(k));
    // Authoritative for this owner. Warm envelopes bake another account's
    // Bluesky viewer.like / a boost row as true and never undo it.
    st["favourited"] = json!(favourited);
    st["bookmarked"] = json!(bookmarked);
    st["reblogged"] = json!(reblogged);
    scrub_bsky_record(st, "vaak_bsky_like_record", favourited, viewer_did);
    scrub_bsky_record(st, "vaak_bsky_repost_record", reblogged, viewer_did);
    if let Some(inner) = st.get_mut("reblog").filter(|v| v.is_object()) {
        // Nested original keeps its own viewer flags (PHP applies recursively).
        let mut nested = inner.clone();
        apply_one(&mut nested, fav, bm, rb, viewer_did);
        *inner = nested;
    }
}

async fn load_viewer_did(db: &Client, owner: i64) -> Option<String> {
    if owner < 1 {
        return None;
    }
    let row = db
        .query_opt(
            "SELECT did FROM bsky_sessions WHERE owner_user_id = $1",
            &[&owner],
        )
        .await
        .ok()??;
    let did: String = row.try_get(0).ok()?;
    if did.starts_with("did:") {
        Some(did)
    } else {
        None
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

/// Overlay this owner's fav/boost/bookmark state onto Mastodon-shaped statuses.
///
/// Always writes true and false. A miss is not "leave the envelope alone":
/// hydrate bakes boost cards and the ingesting Bluesky account's viewer
/// flags as true, and that stuck state survived logout and account switch.
pub async fn apply_to_statuses(db: &Client, owner_user_id: i64, statuses: &mut [Value]) -> Result<()> {
    if statuses.is_empty() {
        return Ok(());
    }
    let viewer_did = load_viewer_did(db, owner_user_id).await;
    let (fav, bm, rb) = if owner_user_id > 0 {
        let mut status_ids = HashSet::new();
        let mut object_ids = HashSet::new();
        for st in statuses.iter() {
            if st.is_object() {
                collect_keys(st, &mut status_ids, &mut object_ids);
            }
        }
        if status_ids.is_empty() && object_ids.is_empty() {
            (HashSet::new(), HashSet::new(), HashSet::new())
        } else {
            let sid_list: Vec<String> = status_ids.into_iter().collect();
            let oid_list: Vec<String> = object_ids.into_iter().collect();
            load_hits(db, owner_user_id, &sid_list, &oid_list).await?
        }
    } else {
        (HashSet::new(), HashSet::new(), HashSet::new())
    };
    let did = viewer_did.as_deref();
    for st in statuses.iter_mut() {
        if st.is_object() {
            apply_one(st, &fav, &bm, &rb, did);
        }
    }
    Ok(())
}

/// Same overlay for self-thread parents keyed by URI.
pub async fn apply_to_status_map(
    db: &Client,
    owner_user_id: i64,
    statuses: &mut std::collections::HashMap<String, Value>,
) -> Result<()> {
    if statuses.is_empty() {
        return Ok(());
    }
    let keys: Vec<String> = statuses.keys().cloned().collect();
    let mut vals = Vec::with_capacity(keys.len());
    for key in &keys {
        if let Some(value) = statuses.get(key) {
            vals.push(value.clone());
        }
    }
    apply_to_statuses(db, owner_user_id, &mut vals).await?;
    for (key, value) in keys.into_iter().zip(vals) {
        statuses.insert(key, value);
    }
    Ok(())
}

/// Convenience: connect and apply (Home HTML / profile HTML paint paths).
pub async fn apply_to_statuses_with_cfg(
    database_url: &str,
    owner_user_id: i64,
    statuses: &mut [Value],
) -> Result<()> {
    if statuses.is_empty() {
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
        apply_one(&mut st, &fav, &bm, &rb, None);
        assert_eq!(st["favourited"], json!(true));
        assert_eq!(st["bookmarked"], json!(false));
        assert_eq!(st["reblogged"], json!(false));
    }

    #[test]
    fn apply_one_clears_baked_flags_and_foreign_bsky_records() {
        let mut st = json!({
            "id": "announce-wrapper",
            "uri": "https://mkultra.monster/users/cmdr_nova/announces/1",
            "favourited": true,
            "bookmarked": true,
            "reblogged": true,
            "vaak_bsky_like_record": "at://did:plc:other/app.bsky.feed.like/abc",
            "vaak_bsky_repost_record": "at://did:plc:other/app.bsky.feed.repost/abc",
            "reblog": {
                "id": "1791",
                "uri": "https://example.com/users/a/statuses/1",
                "favourited": true,
                "reblogged": true,
                "bookmarked": false
            }
        });
        apply_one(
            &mut st,
            &HashSet::new(),
            &HashSet::new(),
            &HashSet::new(),
            Some("did:plc:me"),
        );
        assert_eq!(st["favourited"], json!(false));
        assert_eq!(st["bookmarked"], json!(false));
        assert_eq!(st["reblogged"], json!(false));
        assert!(st.get("vaak_bsky_like_record").is_none());
        assert!(st.get("vaak_bsky_repost_record").is_none());
        assert_eq!(st["reblog"]["favourited"], json!(false));
        assert_eq!(st["reblog"]["reblogged"], json!(false));
    }

    #[test]
    fn apply_one_keeps_own_bsky_like_record_only_while_favourited() {
        let uri = "at://did:plc:author/app.bsky.feed.post/xyz";
        let mut st = json!({
            "id": "bsky:abc",
            "uri": uri,
            "url": "https://bsky.app/profile/did:plc:author/post/xyz",
            "favourited": false,
            "reblogged": false,
            "bookmarked": false,
            "vaak_bsky_like_record": "at://did:plc:me/app.bsky.feed.like/abc"
        });
        let mut fav = HashSet::new();
        push_key(&mut fav, "bsky:abc");
        apply_one(&mut st, &fav, &HashSet::new(), &HashSet::new(), Some("did:plc:me"));
        assert_eq!(st["favourited"], json!(true));
        assert_eq!(
            st["vaak_bsky_like_record"],
            json!("at://did:plc:me/app.bsky.feed.like/abc")
        );

        let mut cleared = st.clone();
        apply_one(
            &mut cleared,
            &HashSet::new(),
            &HashSet::new(),
            &HashSet::new(),
            Some("did:plc:me"),
        );
        assert_eq!(cleared["favourited"], json!(false));
        assert!(cleared.get("vaak_bsky_like_record").is_none());
    }
}
