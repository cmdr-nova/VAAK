//! Network-wide trending Nostr notes for the trends service
//! (`trends_service.rs`). The ids and counts come from nostr.wine; the notes
//! and their authors' profiles are fetched from the fallback/index relays
//! and signature checked here, so nothing unverified reaches a card.
//! Keys an admin blocked are left out; per-viewer mutes are applied by PHP
//! when the sidebar is painted.

use std::collections::{HashMap, HashSet};

use anyhow::Result;
use serde_json::{json, Value};
use tokio::time::{Duration, Instant};
use tokio_postgres::Client;

use super::relay::{newest_by_author_kind, Query};
use super::render::{materialize, NostrRow};
use super::sync::{fan_out, verified, Settings};

const RUN_SECS: u64 = 45;

async fn blocked_keys(db: &Client) -> HashSet<String> {
    let ready = db
        .query_one("SELECT to_regclass('public.nostr_blocks') IS NOT NULL", &[])
        .await
        .map(|r| r.get::<_, bool>(0))
        .unwrap_or(false);
    if !ready {
        return HashSet::new();
    }
    match db.query("SELECT pubkey FROM nostr_blocks", &[]).await {
        Ok(rows) => rows.iter().map(|r| r.get::<_, String>(0)).collect(),
        Err(_) => HashSet::new(),
    }
}

/// `rows` = (note id, likes, reposts, replies) in nostr.wine's order.
pub async fn trending_cards(db: &Client, rows: &[(String, i64, i64, i64)], now: i64) -> Result<Vec<Value>> {
    if rows.is_empty() {
        return Ok(Vec::new());
    }
    let s = Settings::from_env(RUN_SECS);
    let deadline = Instant::now() + Duration::from_secs(RUN_SECS);
    let ids: Vec<String> = rows.iter().map(|r| r.0.clone()).collect();
    let jobs = s.fallback_relays.iter().take(3).map(|r| (r.clone(), vec![Query::by_ids(ids.clone(), vec![1])])).collect();
    let mut found = Vec::new();
    for (_, res) in fan_out(db, &s, jobs, deadline).await {
        if let Ok(res) = res {
            found.extend(res.events);
        }
    }
    let mut rejected = 0usize;
    let notes: HashMap<String, super::event::Event> = verified(found, now, &mut rejected)
        .into_iter()
        .filter(|ev| ev.kind == 1 && !ev.reply_info().0)
        .map(|ev| (ev.id.clone(), ev))
        .collect();
    if rejected > 0 {
        tracing::warn!(rejected, "trending nostr notes failed verification");
    }
    let blocked = blocked_keys(db).await;
    let authors: Vec<String> = notes
        .values()
        .map(|ev| ev.pubkey.clone())
        .filter(|pk| !blocked.contains(pk))
        .collect::<HashSet<_>>()
        .into_iter()
        .collect();
    let mut profiles = HashMap::new();
    if !authors.is_empty() && Instant::now() < deadline {
        let q = Query::new(authors.clone(), vec![0], None, authors.len() as i64 * 2);
        let relays = if s.index_relays.is_empty() { &s.fallback_relays } else { &s.index_relays };
        let jobs = relays.iter().take(2).map(|r| (r.clone(), vec![q.clone()])).collect();
        let mut got = Vec::new();
        for (_, res) in fan_out(db, &s, jobs, deadline).await {
            if let Ok(res) = res {
                got.extend(res.events);
            }
        }
        let mut rej = 0usize;
        for ((pk, _), ev) in newest_by_author_kind(verified(got, now, &mut rej)) {
            profiles.insert(pk, super::event::parse_profile(&ev.content));
        }
    }
    let mut out = Vec::new();
    for (id, likes, reposts, replies) in rows {
        let Some(ev) = notes.get(id) else { continue };
        if blocked.contains(&ev.pubkey) {
            continue;
        }
        let p = profiles.get(&ev.pubkey).cloned().unwrap_or_default();
        let row = NostrRow {
            id: ev.id.clone(),
            pubkey: ev.pubkey.clone(),
            created_at: ev.created_at,
            content: ev.content.clone(),
            tags: json!(ev.tags),
            name: p.name,
            display_name: p.display_name,
            picture: p.picture,
            nip05: p.nip05,
            reposted: None,
        };
        let mut st = materialize(&row);
        st["favourites_count"] = json!(likes);
        st["reblogs_count"] = json!(reposts);
        st["replies_count"] = json!(replies);
        st["vaak_trend_source"] = json!("nostr.wine");
        out.push(st);
    }
    Ok(out)
}
