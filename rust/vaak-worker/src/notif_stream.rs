//! Notification nudge stream (`GET /vaak/notif-stream?t=<token>`).
//!
//! Idea from Wafrn's notification socket: instead of every open tab asking
//! PHP for the badge every 12 seconds, the tab holds one Server-Sent Events
//! connection here and asks PHP only when something changed. An idle
//! connection is a parked tokio task, not a PHP-FPM worker.
//!
//! The stream never carries notification content. It sends `event: notif`
//! when the owner's notification fingerprint (newest mention row, read
//! marker, unread DMs, pending Phyrian requests) moves, and the page then
//! runs its normal `?ajax=notif_unread` poll. Several tabs of one owner share
//! one fingerprint query per [`FP_TTL`].
//!
//! Auth: the page gets a random token from PHP, which stores
//! `vaak:notif_stream:token:v1:<sha256(token)>` -> owner id in Redis for 12
//! hours. No PHP secret is shared with the worker. An unknown token is a 401
//! and the page keeps polling.

use std::collections::HashMap;
use std::convert::Infallible;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, LazyLock, Mutex};
use std::time::{Duration, Instant};

use anyhow::Result;
use axum::extract::{Query, State};
use axum::http::StatusCode;
use axum::response::sse::{Event, KeepAlive, Sse};
use axum::response::{IntoResponse, Response};
use futures_util::stream::{self, Stream};
use serde::Deserialize;
use sha2::{Digest as _, Sha256};

use crate::config::Config;

/// How often a connection re-checks its owner's fingerprint.
const TICK: Duration = Duration::from_secs(4);
/// Tabs of one owner share a fingerprint read for this long.
const FP_TTL: Duration = Duration::from_millis(3500);
/// Streams end after this long; the browser reconnects and the token is
/// checked again.
const MAX_LIFETIME: Duration = Duration::from_secs(600);
const MAX_STREAMS: usize = 400;
const MAX_STREAMS_PER_OWNER: usize = 8;

pub fn token_key(token: &str) -> String {
    format!("vaak:notif_stream:token:v1:{}", hex::encode(Sha256::digest(token.trim().as_bytes())))
}

#[derive(Debug, Deserialize)]
pub struct StreamQuery {
    t: Option<String>,
}

static OPEN: AtomicUsize = AtomicUsize::new(0);
static PER_OWNER: LazyLock<Mutex<HashMap<i64, usize>>> = LazyLock::new(Default::default);
static FINGERPRINTS: LazyLock<Mutex<HashMap<i64, (Instant, String)>>> = LazyLock::new(Default::default);

/// Holds one stream slot; releasing it when the stream is dropped (the
/// client went away or the lifetime ran out).
struct Slot {
    owner: i64,
}

impl Slot {
    fn claim(owner: i64) -> Option<Slot> {
        let mut per = PER_OWNER.lock().ok()?;
        let mine = per.entry(owner).or_insert(0);
        if *mine >= MAX_STREAMS_PER_OWNER {
            return None;
        }
        if OPEN.fetch_add(1, Ordering::SeqCst) >= MAX_STREAMS {
            OPEN.fetch_sub(1, Ordering::SeqCst);
            return None;
        }
        *mine += 1;
        Some(Slot { owner })
    }
}

impl Drop for Slot {
    fn drop(&mut self) {
        OPEN.fetch_sub(1, Ordering::SeqCst);
        if let Ok(mut per) = PER_OWNER.lock() {
            if let Some(n) = per.get_mut(&self.owner) {
                *n = n.saturating_sub(1);
                if *n == 0 {
                    per.remove(&self.owner);
                }
            }
        }
    }
}

async fn redis_conn(url: &str) -> Result<redis::aio::ConnectionManager> {
    static CONN: tokio::sync::OnceCell<redis::aio::ConnectionManager> = tokio::sync::OnceCell::const_new();
    let conn = CONN.get_or_try_init(|| async { redis::aio::ConnectionManager::new(redis::Client::open(url)?).await }).await?;
    Ok(conn.clone())
}

async fn owner_for_token(cfg: &Config, token: &str) -> Result<Option<i64>> {
    if token.len() < 32 || token.len() > 128 || !token.bytes().all(|b| b.is_ascii_hexdigit()) {
        return Ok(None);
    }
    let mut redis = redis_conn(&cfg.redis_url).await?;
    let raw: Option<String> = redis::cmd("GET").arg(token_key(token)).query_async(&mut redis).await?;
    Ok(raw.and_then(|v| v.trim().parse::<i64>().ok()).filter(|id| *id > 0))
}

/// One string that changes whenever the badge could change.
async fn read_fingerprint(cfg: &Config, owner: i64) -> Result<String> {
    let db = crate::db::timeline_reused(&cfg.database_url).await?;
    let row = db
        .query_one(
            "SELECT
                (SELECT COALESCE(max(id), 0)::bigint FROM mentions
                  WHERE owner_user_id = $1::bigint AND deleted_at IS NULL),
                (SELECT COALESCE(max(last_read_id::text), '') FROM masto_markers
                  WHERE owner_user_id = $1::bigint AND timeline = 'notifications'),
                (SELECT count(*)::bigint FROM direct_messages
                  WHERE owner_user_id = $1::bigint AND direction = 'in' AND read_at IS NULL AND deleted_at IS NULL),
                (SELECT COALESCE(max(id), 0)::bigint FROM direct_messages
                  WHERE owner_user_id = $1::bigint AND direction = 'in' AND read_at IS NULL AND deleted_at IS NULL)",
            &[&owner],
        )
        .await?;
    let mention: i64 = row.get(0);
    let marker: String = row.get(1);
    let dm_count: i64 = row.get(2);
    let dm_max: i64 = row.get(3);
    // Created lazily by PHP, so it can be missing on a fresh install.
    let phyrian: i64 = match db
        .query_one(
            "SELECT CASE WHEN to_regclass('public.phyrian_requests') IS NULL THEN 0
                         ELSE (SELECT count(*)::bigint FROM phyrian_requests WHERE to_owner_id = $1::bigint AND status = 'pending') END",
            &[&owner],
        )
        .await
    {
        Ok(row) => row.get(0),
        Err(_) => 0,
    };
    Ok(format!("{mention}:{marker}:{dm_count}:{dm_max}:{phyrian}"))
}

async fn fingerprint(cfg: &Config, owner: i64) -> Option<String> {
    if let Some((at, fp)) = FINGERPRINTS.lock().ok().and_then(|m| m.get(&owner).cloned()) {
        if at.elapsed() < FP_TTL {
            return Some(fp);
        }
    }
    match read_fingerprint(cfg, owner).await {
        Ok(fp) => {
            if let Ok(mut map) = FINGERPRINTS.lock() {
                map.retain(|_, (at, _)| at.elapsed() < Duration::from_secs(60));
                map.insert(owner, (Instant::now(), fp.clone()));
            }
            Some(fp)
        }
        Err(e) => {
            tracing::warn!(error = %e, owner, "notif stream fingerprint failed");
            None
        }
    }
}

/// What a stream sends next given the last fingerprint it saw.
pub(crate) fn next_event(last: Option<&str>, now: Option<&str>) -> Option<&'static str> {
    match (last, now) {
        (_, None) => None,
        (None, Some(_)) => Some("ready"),
        (Some(a), Some(b)) if a != b => Some("notif"),
        _ => None,
    }
}

struct StreamState {
    cfg: Arc<Config>,
    owner: i64,
    last: Option<String>,
    started: Instant,
    _slot: Slot,
}

fn nudges(state: StreamState) -> impl Stream<Item = Result<Event, Infallible>> {
    stream::unfold(state, |mut st| async move {
        loop {
            if st.started.elapsed() >= MAX_LIFETIME {
                return None;
            }
            if st.last.is_some() {
                tokio::time::sleep(TICK).await;
            }
            let now = fingerprint(&st.cfg, st.owner).await;
            let kind = next_event(st.last.as_deref(), now.as_deref());
            if now.is_some() {
                st.last = now;
            }
            if let Some(kind) = kind {
                return Some((Ok(Event::default().event(kind).data("1")), st));
            }
        }
    })
}

pub async fn handle(State(state): State<crate::http::AppState>, Query(q): Query<StreamQuery>) -> Response {
    let cfg = state.cfg.clone();
    let token = q.t.unwrap_or_default();
    let owner = match owner_for_token(&cfg, &token).await {
        Ok(Some(owner)) => owner,
        Ok(None) => return (StatusCode::UNAUTHORIZED, [("cache-control", "no-store")], "unknown token").into_response(),
        Err(e) => {
            tracing::warn!(error = %e, "notif stream token lookup failed");
            return (StatusCode::SERVICE_UNAVAILABLE, [("cache-control", "no-store")], "unavailable").into_response();
        }
    };
    let Some(slot) = Slot::claim(owner) else {
        return (StatusCode::TOO_MANY_REQUESTS, [("cache-control", "no-store"), ("retry-after", "60")], "too many streams").into_response();
    };
    let state = StreamState { cfg, owner, last: None, started: Instant::now(), _slot: slot };
    let sse = Sse::new(nudges(state)).keep_alive(KeepAlive::new().interval(Duration::from_secs(25)).text("hb"));
    (
        [("cache-control", "no-store"), ("x-accel-buffering", "no"), ("x-content-type-options", "nosniff")],
        sse,
    )
        .into_response()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn first_read_is_ready_then_only_changes_nudge() {
        assert_eq!(next_event(None, Some("1:0:0:0:0")), Some("ready"));
        assert_eq!(next_event(Some("1:0:0:0:0"), Some("1:0:0:0:0")), None);
        assert_eq!(next_event(Some("1:0:0:0:0"), Some("2:0:0:0:0")), Some("notif"));
        assert_eq!(next_event(Some("1:0:0:0:0"), None), None, "a failed read never nudges");
        assert_eq!(next_event(None, None), None);
    }

    #[test]
    fn token_key_hashes_and_trims() {
        assert_eq!(token_key("abc"), token_key(" abc\n"));
        assert!(token_key("abc").starts_with("vaak:notif_stream:token:v1:"));
        assert!(!token_key("abc").contains("abc"));
    }

    #[test]
    fn slots_cap_per_owner_and_release_on_drop() {
        let owner = -4242;
        let held: Vec<Slot> = (0..MAX_STREAMS_PER_OWNER).filter_map(|_| Slot::claim(owner)).collect();
        assert_eq!(held.len(), MAX_STREAMS_PER_OWNER);
        assert!(Slot::claim(owner).is_none());
        drop(held);
        assert!(Slot::claim(owner).is_some());
        assert!(PER_OWNER.lock().unwrap().get(&owner).is_none());
    }
}
