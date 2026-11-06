//! Rust consumer for `ap_fanout_delivery_queue` (cutover step D2).
//!
//! The same state machine as `api/ap-fanout-delivery-worker.php`: stale
//! lease reclaim, one atomic claim per row, the Bluesky guard, suspended-host
//! and circuit checks (`ap_fanout_worker_outcome`), a signed POST built like
//! `ap_deliver_signed_json`, and the same retry delays, terminal limits and
//! host-health writes (`ap_fanout_host_note_success` / `_note_terminal`).
//! It also re-queues the daily probe for suspended hosts
//! (`ap_fanout_host_requeue_probe`).
//!
//! Off unless `VAAK_FANOUT_DELIVERY_RUST=1`. While it runs it keeps the Redis
//! heartbeat `vaak:fanout_delivery:rust:v1` fresh, and PHP stops starting its
//! own worker. If the heartbeat goes stale PHP starts it again. Claims are
//! atomic (`status='pending'` guard), so both workers can never send one row.
//!
//! Hosts are worked in parallel, one row at a time per host, so a slow inbox
//! holds up only its own host and the circuit sees failures in order.

use std::collections::HashMap;
use std::net::{IpAddr, SocketAddr};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use anyhow::Result;
use serde_json::Value;
use tokio_postgres::Client;

use crate::ap_sign::{body_digest, host_and_target, http_date, Signer};

pub(crate) const HEARTBEAT_KEY: &str = "vaak:fanout_delivery:rust:v1";
const HEARTBEAT_TTL: u64 = 30;
const STATS_KEY: &str = "vaak:fanout_delivery_rust:stats:v1";
const STATS_TTL: i64 = 14 * 86_400;
/// PHP claims 40 per run; Rust takes the same per pass.
const BATCH: i64 = 40;
const HOST_PARALLEL: usize = 8;
const DELIVERY_TIMEOUT: Duration = Duration::from_millis(2_500);
/// PHP gave Bridgy Fed 5 s inline ("Bridgy often ACKs immediately even when
/// its media pipeline is busy"); keep that when its rows come through here.
const BRIDGY_TIMEOUT: Duration = Duration::from_secs(5);
/// Rows one host may send in a single pass. A slow host otherwise holds the
/// pass (and the next reply or Bridgy row behind it) for 40 x 2.5 s.
const PER_HOST_PER_PASS: usize = 4;
/// Set next to the heartbeat while VAAK_FANOUT_SYNC_RUST=1 (cutover D3):
/// PHP then queues the deliveries it used to send inline.
pub(crate) const SYNC_HEARTBEAT_KEY: &str = "vaak:fanout_delivery:rust_sync:v1";
/// Set next to the heartbeat while VAAK_FANOUT_ACTIONS_RUST=1 (cutover A1):
/// PHP then queues the likes, unlikes and unboosts it sent from the action
/// queue instead of sending them itself.
pub(crate) const ACTIONS_HEARTBEAT_KEY: &str = "vaak:fanout_delivery:rust_actions:v1";
const IDLE_POLL: Duration = Duration::from_secs(2);
/// PHP `ap_redis_queue_push('fanout-delivery', …)` on the queue database.
const WAKE_QUEUE: &str = "fanout-delivery";
const PROBE_EVERY: Duration = Duration::from_secs(60);
const HOST_CACHE_SECS: u64 = 60;
const SNIPPET: usize = 180;

pub(crate) fn enabled() -> bool {
    std::env::var("VAAK_FANOUT_DELIVERY_RUST").is_ok_and(|v| v.trim() == "1")
}

/// Cutover D3: PHP stops sending fan-out inline and queues it for this worker.
fn actions_enabled() -> bool {
    std::env::var("VAAK_FANOUT_ACTIONS_RUST").is_ok_and(|v| v.trim() == "1")
}

fn sync_enabled() -> bool {
    std::env::var("VAAK_FANOUT_SYNC_RUST").is_ok_and(|v| v.trim() == "1")
}

fn is_bridgy_host(host: &str) -> bool {
    let h = host.trim_end_matches('.').to_ascii_lowercase();
    h == "brid.gy" || h.ends_with(".brid.gy")
}

fn timeout_for(host: &str) -> Duration {
    if is_bridgy_host(host) { BRIDGY_TIMEOUT } else { DELIVERY_TIMEOUT }
}

/// `gmdate('c', $unix)`.
pub(crate) fn iso(unix: i64) -> String {
    chrono::DateTime::from_timestamp(unix, 0).unwrap_or_default().format("%Y-%m-%dT%H:%M:%S+00:00").to_string()
}

/// `strtotime` for the ISO strings this queue stores.
fn unix_of(value: Option<&str>) -> Option<i64> {
    let value = value?.trim();
    if value.is_empty() {
        return None;
    }
    chrono::DateTime::parse_from_rfc3339(value)
        .map(|d| d.timestamp())
        .ok()
        .or_else(|| chrono::NaiveDateTime::parse_from_str(value, "%Y-%m-%d %H:%M:%S").ok().map(|d| d.and_utc().timestamp()))
}

/// An INTEGER or BIGINT column (CI fixtures use both).
fn int_col(row: &tokio_postgres::Row, i: usize) -> Option<i64> {
    row.try_get::<_, Option<i64>>(i).ok().flatten().or_else(|| row.try_get::<_, Option<i32>>(i).ok().flatten().map(i64::from))
}

fn now_unix() -> i64 {
    chrono::Utc::now().timestamp()
}

// ---- ap-fanout-host.php, pure parts -------------------------------------

/// `ap_fanout_host_normalize`.
pub(crate) fn host_normalize(raw: &str) -> String {
    let mut raw = raw.trim().to_string();
    if raw.is_empty() || raw == "*" {
        return String::new();
    }
    if raw.contains("://") {
        raw = crate::inbox_accept::host_of(&raw).unwrap_or_default();
    }
    let raw = raw.trim_end_matches('.').to_ascii_lowercase();
    if raw.is_empty() || !raw.contains('.') || !raw.bytes().all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'.' || b == b'-') {
        return String::new();
    }
    raw
}

/// `ap_fanout_host_is_bluesky_host`.
pub(crate) fn is_bluesky_host(host: &str) -> bool {
    let host = host_normalize(host);
    !host.is_empty() && (host == "bsky.app" || host.ends_with(".bsky.app") || host == "bsky.social" || host.ends_with(".bsky.social"))
}

/// `ap_fanout_live_rejection_status`: `\bHTTP\s+(401|403|429|500)\b`.
pub(crate) fn live_rejection_status(error: &str) -> Option<u16> {
    let bytes = error.as_bytes();
    let mut i = 0;
    while let Some(pos) = error[i..].find("HTTP") {
        let start = i + pos;
        i = start + 4;
        let word_start = start == 0 || !(bytes[start - 1].is_ascii_alphanumeric() || bytes[start - 1] == b'_');
        if !word_start {
            continue;
        }
        let rest = &error[start + 4..];
        let trimmed = rest.trim_start_matches(|c: char| c.is_whitespace());
        if trimmed.len() == rest.len() {
            continue;
        }
        for code in ["401", "403", "429", "500"] {
            if let Some(after) = trimmed.strip_prefix(code) {
                if after.chars().next().is_none_or(|c| !(c.is_alphanumeric() || c == '_')) {
                    return code.parse().ok();
                }
            }
        }
    }
    None
}

/// `ap_fanout_counts_as_dead_failure`.
pub(crate) fn counts_as_dead_failure(error: &str) -> bool {
    if live_rejection_status(error).is_some() {
        return false;
    }
    let lower = error.trim().to_lowercase();
    if lower.is_empty() {
        return false;
    }
    !["circuit is open", "suspended remote host", "lease expired", "signing key", "activity signature", "activity json", "curl is required", "invalid https inbox", "bluesky activity"]
        .iter()
        .any(|needle| lower.contains(needle))
}

/// `ap_fanout_activity_is_bluesky`.
pub(crate) fn activity_is_bluesky(doc: &Value) -> bool {
    let actor = match doc.get("actor") {
        Some(Value::String(s)) => s.clone(),
        Some(Value::Object(map)) => map.get("id").and_then(Value::as_str).unwrap_or_default().to_string(),
        _ => String::new(),
    };
    if actor.starts_with("did:") || actor.contains("bsky.app/") || actor.contains("bsky.social/") {
        return true;
    }
    let host = host_normalize(&actor);
    !host.is_empty() && is_bluesky_host(&host)
}

fn dead_days() -> i64 {
    config_int("VAAK_FANOUT_DEAD_DAYS", 7, 1, 90)
}

fn dead_failures() -> i64 {
    config_int("VAAK_FANOUT_DEAD_FAILURES", 20, 1, 100_000)
}

fn config_int(name: &str, default: i64, min: i64, max: i64) -> i64 {
    match std::env::var(name) {
        Ok(raw) if !raw.trim().is_empty() && raw.trim().bytes().all(|b| b.is_ascii_digit()) => raw.trim().parse::<i64>().map(|v| v.clamp(min, max)).unwrap_or(default),
        _ => default,
    }
}

/// `ap_fanout_host_suspended`.
pub(crate) fn host_suspended(last_success: Option<i64>, streak_started: Option<i64>, consecutive: i64, last_live: Option<i64>, now: i64, days: i64, need: i64) -> bool {
    let Some(streak) = streak_started else { return false };
    if consecutive < need {
        return false;
    }
    let cutoff = now - days * 86_400;
    if streak > cutoff {
        return false;
    }
    if last_success.is_some_and(|t| t > cutoff) || last_live.is_some_and(|t| t > cutoff) {
        return false;
    }
    true
}

fn probe_due(last_probe: Option<i64>, now: i64) -> bool {
    last_probe.is_none_or(|t| now - t >= 86_400)
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) struct Outcome {
    pub try_http: bool,
    /// "succeeded", "pending" or "failed".
    pub status: &'static str,
    pub terminal: bool,
    pub record_terminal: bool,
}

/// `ap_fanout_worker_outcome`.
pub(crate) fn worker_outcome(suspended: bool, probe: bool, circuit_open: bool, http_ok: bool, attempts: i64) -> Outcome {
    if suspended && !probe {
        return Outcome { try_http: false, status: "failed", terminal: true, record_terminal: false };
    }
    let try_http = !(circuit_open && !probe);
    if !try_http {
        let terminal = attempts + 1 >= 12;
        return Outcome { try_http: false, status: if terminal { "failed" } else { "pending" }, terminal, record_terminal: terminal };
    }
    if http_ok {
        return Outcome { try_http: true, status: "succeeded", terminal: false, record_terminal: false };
    }
    let limit = if suspended { 1 } else { 12 };
    let terminal = attempts + 1 >= limit;
    Outcome { try_http: true, status: if terminal { "failed" } else { "pending" }, terminal, record_terminal: terminal }
}

/// The worker's retry delay: `min(3600, 15 * 2^min(8, attempts - 1))`. An
/// open circuit also waits at least until it closes (and at least 60 s), but
/// never less than the backoff, so rows skipped by an open circuit spread
/// their 12 attempts over the same hours as real retries instead of using
/// them all up within about an hour.
pub(crate) fn retry_delay(circuit_until: i64, now: i64, attempts: i64) -> i64 {
    let exp = (attempts - 1).clamp(0, 8) as u32;
    let backoff = (15 * 2_i64.pow(exp)).min(3600);
    if circuit_until > now {
        return (circuit_until - now).max(60).max(backoff);
    }
    backoff
}

/// The status code of a delivery error written by `report`
/// ("HTTP 410 ..."), or None for a transport failure.
pub(crate) fn error_http_status(error: &str) -> Option<u16> {
    let rest = error.trim_start().strip_prefix("HTTP ")?;
    let code: String = rest.chars().take_while(|c| c.is_ascii_digit()).collect();
    if code.len() != 3 {
        return None;
    }
    code.parse().ok()
}

/// The host answered with a 4xx about this one inbox or activity (a deleted
/// account, a stale key record, a rejected activity). The host itself is up,
/// so this must not open its circuit: one dead follower inbox would
/// otherwise hold back every other delivery to that server. 408 and 429 are
/// the host asking us to slow down, so they still count.
pub(crate) fn host_answered(error: &str) -> bool {
    matches!(error_http_status(error), Some(s) if (400..500).contains(&s) && s != 408 && s != 429)
}

/// 404 or 410: the inbox is gone. Retrying cannot help.
pub(crate) fn inbox_gone(error: &str) -> bool {
    matches!(error_http_status(error), Some(404 | 410))
}

#[derive(Debug, Clone, Default)]
struct HostRow {
    last_success_at: Option<String>,
    streak_started_at: Option<String>,
    consecutive: i64,
    last_live_at: Option<String>,
    last_probe_at: Option<String>,
}

/// `ap_fanout_host_after_terminal_failure`, returning the new row.
#[derive(Debug, Clone, PartialEq)]
pub(crate) struct AfterTerminal {
    pub streak_started_at: Option<i64>,
    pub consecutive: i64,
    pub last_live_at: Option<i64>,
    pub last_live_status: Option<i64>,
}

pub(crate) fn after_terminal_failure(streak: Option<i64>, consecutive: i64, live_at: Option<i64>, live_status: Option<i64>, error: &str, now: i64) -> AfterTerminal {
    let mut out = AfterTerminal { streak_started_at: streak, consecutive, last_live_at: live_at, last_live_status: live_status };
    if counts_as_dead_failure(error) {
        if out.consecutive <= 0 || out.streak_started_at.is_none() {
            out.streak_started_at = Some(now);
        }
        out.consecutive += 1;
    } else if let Some(code) = live_rejection_status(error) {
        out.last_live_at = Some(now);
        out.last_live_status = Some(code as i64);
    }
    out
}

// ---- runtime state -------------------------------------------------------

struct Circuit {
    failures: u32,
    open_until: i64,
}

/// Signers by (key path, keyId), with when each was loaded.
type SignerCache = HashMap<(String, String), (Arc<Signer>, Instant)>;

struct State {
    db: Client,
    signers: Mutex<SignerCache>,
    circuits: Mutex<HashMap<String, Circuit>>,
    hosts: tokio::sync::Mutex<(HashMap<String, HostRow>, Option<Instant>)>,
    log_path: Option<String>,
}

impl State {
    fn circuit_open_until(&self, host: &str) -> i64 {
        self.circuits.lock().map(|c| c.get(host).map(|c| c.open_until).unwrap_or(0)).unwrap_or(0)
    }

    /// `ap_fanout_circuit_failure` / `_success`: open for 300 s at 3 failures.
    fn circuit_note(&self, host: &str, ok: bool) {
        let Ok(mut circuits) = self.circuits.lock() else { return };
        if ok {
            circuits.remove(host);
            return;
        }
        let entry = circuits.entry(host.to_string()).or_insert(Circuit { failures: 0, open_until: 0 });
        entry.failures += 1;
        entry.open_until = if entry.failures >= 3 { now_unix() + 300 } else { 0 };
    }

    fn signer(&self, path: &str, key_id: &str) -> std::result::Result<Arc<Signer>, &'static str> {
        let key = (path.to_string(), key_id.to_string());
        if let Ok(cache) = self.signers.lock() {
            if let Some((signer, at)) = cache.get(&key) {
                if at.elapsed() < Duration::from_secs(600) {
                    return Ok(signer.clone());
                }
            }
        }
        let pem = match std::fs::read_to_string(path) {
            Ok(pem) if !pem.is_empty() => pem,
            _ => return Err("Signing key is unavailable"),
        };
        let signer = Arc::new(Signer::from_pem(&pem, key_id).map_err(|_| "Signing key could not be opened")?);
        if let Ok(mut cache) = self.signers.lock() {
            cache.insert(key, (signer.clone(), Instant::now()));
        }
        Ok(signer)
    }

    /// `ap_fanout_host_cache`: hosts with a dead streak, refreshed every 60 s.
    async fn host_cache(&self) -> HashMap<String, HostRow> {
        let mut guard = self.hosts.lock().await;
        if guard.1.is_some_and(|at| at.elapsed() < Duration::from_secs(HOST_CACHE_SECS)) {
            return guard.0.clone();
        }
        let mut map = HashMap::new();
        if let Ok(rows) = self
            .db
            .query(
                "SELECT host, last_success_at, streak_started_at, consecutive_terminal_failures, last_live_at, last_probe_at
                 FROM ap_fanout_host_health WHERE host <> '*' AND consecutive_terminal_failures > 0",
                &[],
            )
            .await
        {
            for row in rows {
                let host = host_normalize(&row.get::<_, String>(0));
                if host.is_empty() {
                    continue;
                }
                map.insert(
                    host,
                    HostRow {
                        last_success_at: row.get(1),
                        streak_started_at: row.get(2),
                        consecutive: int_col(&row, 3).unwrap_or(0),
                        last_live_at: row.get(4),
                        last_probe_at: row.get(5),
                    },
                );
            }
        }
        *guard = (map.clone(), Some(Instant::now()));
        map
    }

    fn suspended_in(cache: &HashMap<String, HostRow>, host: &str, now: i64) -> bool {
        let host = host_normalize(host);
        if host.is_empty() || is_bluesky_host(&host) {
            return false;
        }
        let Some(row) = cache.get(&host) else { return false };
        host_suspended(
            unix_of(row.last_success_at.as_deref()),
            unix_of(row.streak_started_at.as_deref()),
            row.consecutive,
            unix_of(row.last_live_at.as_deref()),
            now,
            dead_days(),
            dead_failures(),
        )
    }

    /// `ap_fanout_host_note_success`.
    async fn note_success(&self, host: &str) {
        let host = host_normalize(host);
        if host.is_empty() || is_bluesky_host(&host) {
            return;
        }
        let mut guard = self.hosts.lock().await;
        if !guard.0.contains_key(&host) {
            return;
        }
        let at = iso(now_unix());
        if let Err(e) = self
            .db
            .execute(
                "UPDATE ap_fanout_host_health SET last_success_at = $1, streak_started_at = NULL, consecutive_terminal_failures = 0, updated_at = $1 WHERE host = $2",
                &[&at, &host],
            )
            .await
        {
            tracing::warn!(error = %e, "fanout host success update failed");
        }
        guard.0.remove(&host);
    }

    /// `ap_fanout_host_note_terminal`.
    async fn note_terminal(&self, host: &str, error: &str) {
        let host = host_normalize(host);
        if host.is_empty() || is_bluesky_host(&host) {
            return;
        }
        let prior = match self
            .db
            .query_opt(
                "SELECT last_success_at, streak_started_at, consecutive_terminal_failures, last_live_status, last_live_at, last_probe_at
                 FROM ap_fanout_host_health WHERE host = $1",
                &[&host],
            )
            .await
        {
            Ok(row) => row,
            Err(e) => {
                tracing::warn!(error = %e, "fanout host terminal read failed");
                return;
            }
        };
        let text = |i: usize| prior.as_ref().and_then(|r| r.get::<_, Option<String>>(i));
        let (success, streak, live_at, probe_at) = (text(0), text(1), text(4), text(5));
        let consecutive = prior.as_ref().and_then(|r| int_col(r, 2)).unwrap_or(0);
        let live_status = prior.as_ref().and_then(|r| int_col(r, 3));
        let now = now_unix();
        let next = after_terminal_failure(unix_of(streak.as_deref()), consecutive, unix_of(live_at.as_deref()), live_status, error, now);
        let at = iso(now);
        let streak_iso = next.streak_started_at.map(iso);
        let live_iso = next.last_live_at.map(iso);
        let live_status = next.last_live_status;
        let error_cut = cut_bytes(error, 500);
        let consecutive_n = next.consecutive;
        if let Err(e) = self
            .db
            .execute(
                "INSERT INTO ap_fanout_host_health
                    (host, last_success_at, streak_started_at, consecutive_terminal_failures, last_failure_at, last_error, last_live_status, last_live_at, updated_at)
                 VALUES ($1,$2,$3,$4::bigint,$5,$6,$7::bigint,$8,$5)
                 ON CONFLICT(host) DO UPDATE SET
                    last_success_at = excluded.last_success_at,
                    streak_started_at = excluded.streak_started_at,
                    consecutive_terminal_failures = excluded.consecutive_terminal_failures,
                    last_failure_at = excluded.last_failure_at,
                    last_error = excluded.last_error,
                    last_live_status = excluded.last_live_status,
                    last_live_at = excluded.last_live_at,
                    updated_at = excluded.updated_at",
                &[&host, &success, &streak_iso, &consecutive_n, &at, &error_cut, &live_status, &live_iso],
            )
            .await
        {
            tracing::warn!(error = %e, "fanout host terminal write failed");
            return;
        }
        let mut guard = self.hosts.lock().await;
        if next.consecutive > 0 {
            guard.0.insert(
                host,
                HostRow { last_success_at: success, streak_started_at: streak_iso, consecutive: next.consecutive, last_live_at: live_iso, last_probe_at: probe_at },
            );
        } else {
            guard.0.remove(&host);
        }
    }

    /// `ap_fanout_host_requeue_probe`: at most one due probe per pass.
    async fn requeue_probe(&self) {
        let now = now_unix();
        let cache = self.host_cache().await;
        let mut due: Vec<(String, String)> = cache
            .iter()
            .filter(|(host, row)| Self::suspended_in(&cache, host, now) && probe_due(unix_of(row.last_probe_at.as_deref()), now))
            .map(|(host, row)| (row.last_probe_at.clone().unwrap_or_default(), host.clone()))
            .collect();
        due.sort();
        for (prev, host) in due {
            let Ok(Some(row)) = self
                .db
                .query_opt("SELECT probe_inbox_url, probe_activity_json, probe_key_id, probe_priv_path FROM ap_fanout_host_health WHERE host = $1", &[&host])
                .await
            else {
                continue;
            };
            let field = |i: usize| row.get::<_, Option<String>>(i).unwrap_or_default();
            let (inbox, json, key_id, priv_path) = (field(0), field(1), field(2), field(3));
            if inbox.is_empty() || json.is_empty() || key_id.is_empty() || priv_path.is_empty() {
                continue;
            }
            match self.enqueue_probe(&host, &prev, &inbox, &json, &key_id, &priv_path, now).await {
                Ok(true) => return,
                Ok(false) => {}
                Err(e) => tracing::warn!(error = %e, "fanout probe requeue failed"),
            }
        }
    }

    /// `ap_fanout_host_enqueue_probe` without the PHP kick.
    #[allow(clippy::too_many_arguments)]
    async fn enqueue_probe(&self, host: &str, prev: &str, inbox: &str, json: &str, key_id: &str, priv_path: &str, now: i64) -> Result<bool> {
        let Ok(doc) = serde_json::from_str::<Value>(json) else { return Ok(false) };
        if !doc.is_object() || activity_is_bluesky(&doc) || is_bluesky_host(host) {
            return Ok(false);
        }
        let at = iso(now);
        let prev: Option<&str> = (!prev.is_empty()).then_some(prev);
        let claimed = self
            .db
            .execute(
                "UPDATE ap_fanout_host_health
                 SET last_probe_at = $1, probe_inbox_url = $2, probe_activity_json = $3, probe_key_id = $4, probe_priv_path = $5, updated_at = $1
                 WHERE host = $6 AND (last_probe_at IS NULL OR last_probe_at = $7)",
                &[&at, &inbox, &json, &key_id, &priv_path, &host, &prev],
            )
            .await?;
        if claimed != 1 {
            return Ok(false);
        }
        if let Some(row) = self.hosts.lock().await.0.get_mut(host) {
            row.last_probe_at = Some(at.clone());
        }
        let activity_key = match doc.get("id").and_then(Value::as_str) {
            Some(id) if !id.is_empty() => id.to_string(),
            _ => {
                use sha2::Digest as _;
                format!("sha256:{}", hex::encode(sha2::Sha256::digest(json.as_bytes())))
            }
        };
        let picked = self
            .db
            .query(
                "SELECT id, activity_json FROM ap_fanout_delivery_queue
                 WHERE inbox_url = $1 AND status <> 'processing'
                 ORDER BY CASE status WHEN 'pending' THEN 0 WHEN 'failed' THEN 1 ELSE 2 END, id DESC
                 LIMIT 20",
                &[&inbox],
            )
            .await?;
        let reuse = picked.iter().find_map(|row| {
            let body: String = row.get(1);
            let doc = serde_json::from_str::<Value>(&body).unwrap_or(Value::Null);
            (!activity_is_bluesky(&doc)).then(|| int_col(row, 0)).flatten().filter(|id| *id > 0)
        });
        let mut reused = false;
        if let Some(id) = reuse {
            reused = self
                .db
                .execute(
                    "UPDATE ap_fanout_delivery_queue
                     SET status = 'pending', attempts = 0, next_attempt_at = $1, claimed_at = NULL, last_error = 'host-probe', updated_at = $1
                     WHERE id = $2::bigint AND status <> 'processing'",
                    &[&at, &id],
                )
                .await?
                == 1;
        }
        if !reused {
            self.db
                .execute(
                    "INSERT INTO ap_fanout_delivery_queue
                        (activity_key,inbox_url,activity_json,key_id,priv_path,status,priority,attempts,next_attempt_at,last_error,created_at,updated_at)
                     VALUES ($1,$2,$3,$4,$5,'pending',1,0,$6,'host-probe',$6,$6)
                     ON CONFLICT(activity_key,inbox_url) DO NOTHING",
                    &[&activity_key, &inbox, &json, &key_id, &priv_path, &at],
                )
                .await?;
        }
        Ok(true)
    }

    /// `ap_delivery_observe`: failures and follow events go to ap-delivery.log.
    fn observe(&self, inbox: &str, kind: &str, status: u16, ok: bool, body: &str) {
        let follow = ["follow", "accept", "reject"].contains(&kind.trim().to_ascii_lowercase().as_str());
        if ok && !follow {
            return;
        }
        let host = crate::inbox_accept::host_of(inbox).unwrap_or_else(|| "-".into());
        let kind: String = kind.chars().filter(|c| c.is_ascii_alphabetic()).collect();
        let kind = if kind.is_empty() || kind.len() > 32 { "Activity".to_string() } else { kind };
        let path = reqwest::Url::parse(inbox).map(|u| u.path().to_string()).unwrap_or_default();
        let path_ok = path.starts_with('/') && path.len() <= 81 && path.bytes().all(|b| b.is_ascii_alphanumeric() || b"._~%!$&'()*+,;=:@/-".contains(&b));
        let mut line = format!("deliver host={host} type={kind} status={status} ok={} via=rust", if ok { 1 } else { 0 });
        if path_ok {
            line.push_str(&format!(" path={path}"));
        }
        if !ok {
            let snippet = scrub(body, 120);
            if !snippet.is_empty() {
                line.push_str(&format!(" body={snippet}"));
            }
        }
        match &self.log_path {
            Some(path) => append_log(path, &line),
            None => tracing::info!(target: "fanout_delivery", "{line}"),
        }
    }
}

fn append_log(path: &str, line: &str) {
    use std::io::Write as _;
    if std::fs::metadata(path).map(|m| m.len() >= 262_144).unwrap_or(false) {
        let rotated = format!("{path}.1");
        let _ = std::fs::remove_file(&rotated);
        let _ = std::fs::rename(path, &rotated);
    }
    if let Ok(mut f) = std::fs::OpenOptions::new().create(true).append(true).open(path) {
        let _ = writeln!(f, "[{}] {line}", iso(now_unix()));
    }
}

/// `ap_delivery_scrub`: no keys, signatures or tokens in the log.
fn scrub(text: &str, limit: usize) -> String {
    if text.to_ascii_uppercase().contains("PRIVATE KEY") || text.contains("-----BEGIN") {
        return String::new();
    }
    let mut out = Vec::new();
    for word in text.split_whitespace() {
        let lower = word.to_ascii_lowercase();
        if ["signature", "keyid", "digest", "authorization", "bearer"].iter().any(|k| lower.contains(k)) {
            continue;
        }
        out.push(word);
    }
    cut_bytes(&out.join(" "), limit)
}

/// `substr($s, 0, $n)` without splitting a UTF-8 character.
fn cut_bytes(s: &str, n: usize) -> String {
    if s.len() <= n {
        return s.to_string();
    }
    let mut end = n;
    while !s.is_char_boundary(end) {
        end -= 1;
    }
    s[..end].to_string()
}

/// `ap_short($s, 180)`: every run of ASCII whitespace becomes one space.
fn short(s: &str, n: usize) -> String {
    let mut collapsed = String::with_capacity(s.len());
    let mut in_space = false;
    for c in s.chars() {
        if matches!(c, ' ' | '\t' | '\n' | '\r' | '\x0b' | '\x0c') {
            if !in_space {
                collapsed.push(' ');
            }
            in_space = true;
        } else {
            collapsed.push(c);
            in_space = false;
        }
    }
    if collapsed.len() <= n {
        return collapsed;
    }
    format!("{}…", cut_bytes(&collapsed, n))
}

struct Sent {
    ok: bool,
    error: String,
}

fn user_agent() -> String {
    std::env::var("VAAK_HTTP_USER_AGENT").unwrap_or_else(|_| "VAAK (mkultra.monster; +https://github.com/cmdr-nova/VAAK)".to_string())
}

/// `ap_deliver_signed_json`: the same body transform, signed headers and
/// limits (HTTP/1.1, IPv4, pinned to the checked addresses, no redirects).
async fn deliver(state: &State, inbox: &str, activity: &Value, key_id: &str, priv_path: &str) -> Sent {
    let kind = activity.get("type").and_then(Value::as_str).unwrap_or_default().to_string();
    let fail = |error: &str, observed: &str| {
        state.observe(inbox, &kind, 0, false, observed);
        Sent { ok: false, error: error.to_string() }
    };
    let Some((host, target)) = host_and_target(inbox) else {
        return fail("Invalid HTTPS inbox URL", "invalid_inbox");
    };
    let Some(addrs) = crate::inbox_key_fetch::public_addrs(&host).await else {
        return fail("Inbox host is unavailable or not public", "host_unavailable");
    };
    let v4: Vec<SocketAddr> = addrs.iter().filter(|ip| matches!(ip, IpAddr::V4(_))).map(|ip| SocketAddr::new(*ip, 443)).collect();
    let doc = crate::ap_public_doc::pleroma_document_with(activity, false);
    let Ok(body) = serde_json::to_vec(&doc) else {
        return fail("Activity JSON encoding failed", "encode_failed");
    };
    let signer = match state.signer(priv_path, key_id) {
        Ok(s) => s,
        Err(msg) => return fail(msg, if msg.contains("unavailable") { "key_unavailable" } else { "key_unreadable" }),
    };
    let date = http_date(now_unix());
    let digest = body_digest(&body);
    let Ok(signature) = signer.post_signature(&host, &target, &date, &digest) else {
        return fail("Activity signature generation failed", "sign_failed");
    };
    if v4.is_empty() {
        // curl with CURL_IPRESOLVE_V4 finds no address either.
        return report(state, inbox, &kind, 0, None, "Could not resolve host");
    }
    let client = match reqwest::Client::builder()
        .redirect(reqwest::redirect::Policy::none())
        .https_only(true)
        .http1_only()
        .timeout(timeout_for(&host))
        .connect_timeout(timeout_for(&host).max(Duration::from_secs(1)))
        .resolve_to_addrs(&host.to_ascii_lowercase(), &v4)
        .build()
    {
        Ok(c) => c,
        Err(e) => return report(state, inbox, &kind, 0, None, &e.to_string()),
    };
    let sent = client
        .post(inbox)
        .header("Host", &host)
        .header("Date", &date)
        .header("Digest", &digest)
        .header("Content-Type", "application/activity+json")
        .header("Accept", r#"application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams""#)
        .header("User-Agent", user_agent())
        .header("Signature", signature)
        .body(body)
        .send()
        .await;
    match sent {
        Ok(resp) => {
            let status = resp.status().as_u16();
            let text = if (200..300).contains(&status) { String::new() } else { read_capped(resp).await };
            report(state, inbox, &kind, status, Some(&text), "")
        }
        Err(e) => {
            let err = if e.is_timeout() { "Operation timed out".to_string() } else { error_chain(&e) };
            report(state, inbox, &kind, 0, None, &err)
        }
    }
}

fn error_chain(e: &reqwest::Error) -> String {
    let mut out = e.to_string();
    let mut source = std::error::Error::source(e);
    while let Some(inner) = source {
        out = inner.to_string();
        source = inner.source();
    }
    out
}

async fn read_capped(mut resp: reqwest::Response) -> String {
    let mut buf = Vec::new();
    while let Ok(Some(chunk)) = resp.chunk().await {
        buf.extend_from_slice(&chunk);
        if buf.len() > 4096 {
            break;
        }
    }
    String::from_utf8_lossy(&buf).into_owned()
}

/// The tail of `ap_deliver_signed_json`: the error text PHP stores, which
/// decides live versus dead for the host.
fn report(state: &State, inbox: &str, kind: &str, status: u16, body: Option<&str>, transport: &str) -> Sent {
    let ok = (200..300).contains(&status);
    let mut error = String::new();
    if !ok {
        let line = if status > 0 { format!("HTTP {status}") } else { "No HTTP response from inbox".to_string() };
        let snippet = body.map(|b| short(b, SNIPPET)).unwrap_or_default();
        let detail = if transport.is_empty() { String::new() } else { format!(" {transport}") };
        error = format!("{line} {snippet}{detail}").trim().to_string();
    }
    let mut observed = body.unwrap_or_default().to_string();
    if !transport.is_empty() && !ok {
        observed = format!("{observed} {transport}").trim().to_string();
    }
    state.observe(inbox, kind, status, ok, &observed);
    Sent { ok, error }
}

#[derive(Debug, Clone)]
struct Row {
    id: i64,
    inbox_url: String,
    activity_json: String,
    key_id: String,
    priv_path: String,
    attempts: i64,
    last_error: Option<String>,
}

#[derive(Default)]
struct Tally {
    claimed: i64,
    succeeded: i64,
    retried: i64,
    failed: i64,
}

/// One row, exactly as the PHP loop body.
async fn process(state: &State, row: Row, tally: &Mutex<Tally>) -> Result<()> {
    let now = iso(now_unix());
    let claimed = state
        .db
        .execute("UPDATE ap_fanout_delivery_queue SET status='processing', claimed_at=$1, updated_at=$1 WHERE id=$2::bigint AND status='pending'", &[&now, &row.id])
        .await?;
    if claimed != 1 {
        return Ok(());
    }
    bump(tally, |t| t.claimed += 1);
    let activity: Option<Value> = serde_json::from_str::<Value>(&row.activity_json).ok().filter(Value::is_object);
    let mut host = host_normalize(&row.inbox_url);
    if host.is_empty() {
        host = crate::inbox_accept::host_of(&row.inbox_url).unwrap_or_else(|| "unknown".into());
    }
    let probe = row.last_error.as_deref().unwrap_or_default().starts_with("host-probe");
    let bluesky_activity = activity.as_ref().is_some_and(activity_is_bluesky);
    if bluesky_activity && !is_bluesky_host(&host) {
        state
            .db
            .execute(
                "UPDATE ap_fanout_delivery_queue SET status='failed', attempts=attempts+1, claimed_at=NULL, last_error=$1, updated_at=$2 WHERE id=$3::bigint",
                &[&"Bluesky activity is not delivered", &iso(now_unix()), &row.id],
            )
            .await?;
        bump(tally, |t| t.failed += 1);
        return Ok(());
    }
    let cache = state.host_cache().await;
    let suspended = State::suspended_in(&cache, &host, now_unix());
    let circuit_until = state.circuit_open_until(&host);
    let circuit_open = circuit_until > now_unix();
    let plan = worker_outcome(suspended, probe, circuit_open, false, row.attempts);
    let mut ok = false;
    let mut delivery_error = String::new();
    if plan.try_http {
        match &activity {
            Some(doc) => {
                let sent = deliver(state, &row.inbox_url, doc, &row.key_id, &row.priv_path).await;
                ok = sent.ok;
                delivery_error = sent.error;
            }
            None => ok = false,
        }
        state.circuit_note(&host, ok || host_answered(&delivery_error));
        if ok {
            state.note_success(&host).await;
        }
    }
    let mut outcome = worker_outcome(suspended, probe, circuit_open, ok, row.attempts);
    if plan.try_http && !ok && inbox_gone(&delivery_error) {
        outcome = Outcome { try_http: true, status: "failed", terminal: true, record_terminal: true };
    }
    if outcome.status == "succeeded" {
        state
            .db
            .execute("UPDATE ap_fanout_delivery_queue SET status='succeeded', claimed_at=NULL, last_error=NULL, updated_at=$1 WHERE id=$2::bigint", &[&iso(now_unix()), &row.id])
            .await?;
        bump(tally, |t| t.succeeded += 1);
        return Ok(());
    }
    let attempts = row.attempts + 1;
    let now = now_unix();
    let delay = retry_delay(circuit_until, now, attempts);
    let detail = if suspended && !probe {
        "Suspended remote host".to_string()
    } else if !delivery_error.trim().is_empty() {
        delivery_error.trim().to_string()
    } else if circuit_until > now {
        "Remote host circuit is open".to_string()
    } else {
        "Remote inbox delivery failed".to_string()
    };
    if outcome.terminal && outcome.record_terminal {
        state.note_terminal(&host, &detail).await;
    }
    let status = if outcome.terminal { "failed" } else { "pending" };
        state
        .db
        .execute(
            "UPDATE ap_fanout_delivery_queue SET status=$1, attempts=$2::bigint, next_attempt_at=$3, claimed_at=NULL, last_error=$4, updated_at=$5 WHERE id=$6::bigint",
            &[&status, &attempts, &iso(now + delay), &cut_bytes(&detail, 500), &iso(now), &row.id],
        )
        .await?;
    bump(tally, |t| if outcome.terminal { t.failed += 1 } else { t.retried += 1 });
    Ok(())
}

fn bump(tally: &Mutex<Tally>, f: impl FnOnce(&mut Tally)) {
    if let Ok(mut t) = tally.lock() {
        f(&mut t);
    }
}

/// One pass: reclaim stale leases, then work up to BATCH due rows. Returns
/// how many due rows it selected, including ones left for the next pass.
async fn pass(state: &Arc<State>) -> Result<i64> {
    let now = now_unix();
    let stale = iso(now - 600);
    state
        .db
        .execute(
            "UPDATE ap_fanout_delivery_queue SET status='pending', claimed_at=NULL, attempts=attempts+1, last_error='Worker lease expired', updated_at=$1 WHERE status='processing' AND claimed_at < $2",
            &[&iso(now), &stale],
        )
        .await?;
    let rows = state
        .db
        .query(
            "SELECT id, inbox_url, activity_json, key_id, priv_path, attempts, last_error FROM ap_fanout_delivery_queue
             WHERE status='pending' AND next_attempt_at <= $1 ORDER BY priority, next_attempt_at, id LIMIT $2::bigint",
            &[&iso(now), &BATCH],
        )
        .await?;
    let selected = rows.len() as i64;
    let mut by_host: Vec<(String, Vec<Row>)> = Vec::new();
    for r in rows {
        let row = Row {
            id: int_col(&r, 0).unwrap_or(0),
            inbox_url: r.get(1),
            activity_json: r.get(2),
            key_id: r.get(3),
            priv_path: r.get(4),
            attempts: int_col(&r, 5).unwrap_or(0),
            last_error: r.get(6),
        };
        let host = crate::inbox_accept::host_of(&row.inbox_url).unwrap_or_default();
        // Rows past the cap stay pending (they are claimed in process()) and
        // come first in the next pass.
        match by_host.iter_mut().find(|(h, _)| *h == host) {
            Some((_, list)) if list.len() < PER_HOST_PER_PASS => list.push(row),
            Some(_) => {}
            None => by_host.push((host, vec![row])),
        }
    }
    let tally = Arc::new(Mutex::new(Tally::default()));
    let gate = Arc::new(tokio::sync::Semaphore::new(HOST_PARALLEL));
    let mut tasks = tokio::task::JoinSet::new();
    for (_, list) in by_host {
        let (state, tally, gate) = (state.clone(), tally.clone(), gate.clone());
        tasks.spawn(async move {
            let Ok(_permit) = gate.acquire_owned().await else { return };
            for row in list {
                let id = row.id;
                if let Err(e) = process(&state, row, &tally).await {
                    tracing::warn!(error = %e, id, "fanout delivery row failed; its lease will expire");
                }
            }
        });
    }
    while tasks.join_next().await.is_some() {}
    let t = tally.lock().map(|t| (t.claimed, t.succeeded, t.retried, t.failed)).unwrap_or_default();
    if t.0 > 0 {
        tracing::info!(claimed = t.0, succeeded = t.1, retried = t.2, failed = t.3, "fanout_delivery");
    }
    STATS.add(t);
    Ok(selected)
}

/// Counts waiting to be written to Redis by the main loop.
struct PendingStats(Mutex<(i64, i64, i64, i64)>);

impl PendingStats {
    fn add(&self, t: (i64, i64, i64, i64)) {
        if let Ok(mut s) = self.0.lock() {
            s.0 += t.0;
            s.1 += t.1;
            s.2 += t.2;
            s.3 += t.3;
        }
    }

    fn take(&self) -> (i64, i64, i64, i64) {
        self.0.lock().map(|mut s| std::mem::take(&mut *s)).unwrap_or_default()
    }
}

static STATS: PendingStats = PendingStats(Mutex::new((0, 0, 0, 0)));

/// Wait up to IDLE_POLL for PHP's wake-up push, then drop the other pushes:
/// one pass serves them all. Nothing else pops this list while Rust runs.
async fn idle(wake: &mut Option<redis::aio::MultiplexedConnection>) {
    let Some(conn) = wake.as_mut() else {
        tokio::time::sleep(IDLE_POLL).await;
        return;
    };
    match crate::redis_util::queue_brpop(conn, WAKE_QUEUE, IDLE_POLL.as_secs_f64()).await {
        Ok(Some(_)) => {
            let key = format!("vaak:queue:{WAKE_QUEUE}");
            let _: std::result::Result<(), _> = redis::cmd("DEL").arg(&key).query_async(conn).await;
        }
        Ok(None) => {}
        Err(e) => {
            tracing::warn!(error = %e, "fanout delivery wake queue unavailable; polling");
            *wake = None;
            tokio::time::sleep(IDLE_POLL).await;
        }
    }
}

pub async fn run(cfg: Arc<crate::config::Config>) {
    loop {
        if let Err(e) = work(&cfg).await {
            tracing::error!(error = %e, "fanout delivery worker stopped; retrying");
            tokio::time::sleep(Duration::from_secs(5)).await;
        }
    }
}

async fn work(cfg: &crate::config::Config) -> Result<()> {
    let db = crate::db::connect(&cfg.database_url).await?;
    let mut redis = crate::redis_util::connect(&cfg.redis_url).await?;
    let log_path = std::env::var("VAAK_AP_DELIVERY_LOG").ok().filter(|p| !p.trim().is_empty());
    let state = Arc::new(State {
        db,
        signers: Mutex::new(HashMap::new()),
        circuits: Mutex::new(HashMap::new()),
        hosts: tokio::sync::Mutex::new((HashMap::new(), None)),
        log_path,
    });
    let sync = sync_enabled();
    if !sync {
        // Turning D3 off takes effect at once, not after the TTL.
        let _: std::result::Result<(), _> = redis::cmd("DEL").arg(SYNC_HEARTBEAT_KEY).query_async(&mut redis).await;
    }
    let mut wake = crate::redis_util::connect(&cfg.redis_queue_url).await.ok();
    let actions = actions_enabled();
    if !actions {
        let _: std::result::Result<(), _> = redis::cmd("DEL").arg(ACTIONS_HEARTBEAT_KEY).query_async(&mut redis).await;
    }
    tracing::info!(sync, actions, "fanout delivery worker running");
    let mut last_probe: Option<Instant> = None;
    loop {
        let _: std::result::Result<(), _> = redis::cmd("SET").arg(HEARTBEAT_KEY).arg(now_unix()).arg("EX").arg(HEARTBEAT_TTL).query_async(&mut redis).await;
        if actions {
            let _: std::result::Result<(), _> = redis::cmd("SET").arg(ACTIONS_HEARTBEAT_KEY).arg(now_unix()).arg("EX").arg(HEARTBEAT_TTL).query_async(&mut redis).await;
        }
        if sync {
            let _: std::result::Result<(), _> = redis::cmd("SET").arg(SYNC_HEARTBEAT_KEY).arg(now_unix()).arg("EX").arg(HEARTBEAT_TTL).query_async(&mut redis).await;
        }
        if last_probe.is_none_or(|at| at.elapsed() >= PROBE_EVERY) {
            state.requeue_probe().await;
            last_probe = Some(Instant::now());
        }
        let selected = pass(&state).await?;
        let (c, s, r, f) = STATS.take();
        if c + s + r + f > 0 {
            let _: std::result::Result<(), _> = redis::pipe()
                .hincr(STATS_KEY, "claimed", c)
                .hincr(STATS_KEY, "succeeded", s)
                .hincr(STATS_KEY, "retried", r)
                .hincr(STATS_KEY, "failed", f)
                .expire(STATS_KEY, STATS_TTL)
                .query_async(&mut redis)
                .await;
        }
        if selected < BATCH {
            idle(&mut wake).await;
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn bridgy_rows_get_the_longer_timeout() {
        assert_eq!(timeout_for("bsky.brid.gy"), BRIDGY_TIMEOUT);
        assert_eq!(timeout_for("BRID.GY"), BRIDGY_TIMEOUT);
        assert_eq!(timeout_for("fed.brid.gy."), BRIDGY_TIMEOUT);
        assert_eq!(timeout_for("notbrid.gy"), DELIVERY_TIMEOUT);
        assert_eq!(timeout_for("mastodon.social"), DELIVERY_TIMEOUT);
    }

    #[test]
    fn iso_is_php_gmdate_c() {
        assert_eq!(iso(1_793_689_200), "2026-11-03T07:00:00+00:00");
        assert_eq!(unix_of(Some("2026-11-03T07:00:00+00:00")), Some(1_793_689_200));
        assert_eq!(unix_of(Some("")), None);
        assert_eq!(unix_of(None), None);
    }

    #[test]
    fn hosts_normalize_like_php() {
        assert_eq!(host_normalize("https://Mastodon.Social/inbox"), "mastodon.social");
        assert_eq!(host_normalize("example.org."), "example.org");
        assert_eq!(host_normalize("*"), "");
        assert_eq!(host_normalize("localhost"), "");
        assert_eq!(host_normalize("bad_host.org"), "");
        assert!(is_bluesky_host("https://bsky.app/x"));
        assert!(is_bluesky_host("pds.bsky.social"));
        assert!(!is_bluesky_host("notbsky.app"));
    }

    #[test]
    fn live_and_dead_errors_match_php() {
        assert_eq!(live_rejection_status("HTTP 401 Unauthorized"), Some(401));
        assert_eq!(live_rejection_status("HTTP 429"), Some(429));
        assert_eq!(live_rejection_status("HTTP 404 Not Found"), None);
        assert_eq!(live_rejection_status("HTTP 5000"), None);
        assert_eq!(live_rejection_status("XHTTP 401"), None);
        assert_eq!(live_rejection_status("HTTP401"), None);
        assert!(!counts_as_dead_failure("HTTP 500 oops"));
        assert!(!counts_as_dead_failure("Remote host circuit is open"));
        assert!(!counts_as_dead_failure("Signing key is unavailable"));
        assert!(!counts_as_dead_failure(""));
        assert!(counts_as_dead_failure("No HTTP response from inbox Could not resolve host"));
        assert!(counts_as_dead_failure("HTTP 404 gone"));
    }

    #[test]
    fn outcomes_match_php() {
        let o = worker_outcome(true, false, false, false, 0);
        assert_eq!((o.try_http, o.status, o.terminal, o.record_terminal), (false, "failed", true, false));
        let o = worker_outcome(false, false, true, false, 3);
        assert_eq!((o.try_http, o.status, o.terminal), (false, "pending", false));
        let o = worker_outcome(false, false, true, false, 11);
        assert_eq!((o.status, o.terminal, o.record_terminal), ("failed", true, true));
        let o = worker_outcome(false, true, true, true, 0);
        assert_eq!((o.try_http, o.status), (true, "succeeded"));
        let o = worker_outcome(true, true, false, false, 0);
        assert_eq!((o.status, o.terminal), ("failed", true));
        let o = worker_outcome(false, false, false, false, 10);
        assert_eq!((o.status, o.terminal), ("pending", false));
    }

    #[test]
    fn retry_delays_match_php() {
        assert_eq!(retry_delay(0, 1000, 1), 15);
        assert_eq!(retry_delay(0, 1000, 2), 30);
        assert_eq!(retry_delay(0, 1000, 9), 3600);
        assert_eq!(retry_delay(0, 1000, 20), 3600);
        assert_eq!(retry_delay(1030, 1000, 1), 60);
        assert_eq!(retry_delay(1200, 1000, 1), 200);
        // An open circuit never shortens the backoff.
        assert_eq!(retry_delay(1200, 1000, 6), 480);
        assert_eq!(retry_delay(1200, 1000, 12), 3600);
    }

    #[test]
    fn answered_4xx_does_not_trip_circuit() {
        assert_eq!(error_http_status("HTTP 410 {\"error\":\"Gone\"}"), Some(410));
        assert_eq!(error_http_status("No HTTP response from inbox Operation timed out"), None);
        assert!(host_answered("HTTP 410 {\"error\":\"Gone\"}"));
        assert!(host_answered("HTTP 401 {\"error\":\"Verification failed\"}"));
        assert!(host_answered("HTTP 400 {\"error\":\"Bad Request\"}"));
        assert!(!host_answered("HTTP 429 slow down"));
        assert!(!host_answered("HTTP 408"));
        assert!(!host_answered("HTTP 500 Internal Server Error"));
        assert!(!host_answered("HTTP 502 <html>"));
        assert!(!host_answered("No HTTP response from inbox Connection timed out after 2500 milliseconds"));
        assert!(inbox_gone("HTTP 410 {\"error\":\"Gone\"}"));
        assert!(inbox_gone("HTTP 404"));
        assert!(!inbox_gone("HTTP 401"));
    }

    #[test]
    fn suspension_matches_php() {
        let now = 100 * 86_400;
        let old = now - 8 * 86_400;
        assert!(host_suspended(None, Some(old), 20, None, now, 7, 20));
        assert!(!host_suspended(None, Some(old), 19, None, now, 7, 20));
        assert!(!host_suspended(Some(now - 3600), Some(old), 50, None, now, 7, 20));
        assert!(!host_suspended(None, Some(old), 50, Some(now - 3600), now, 7, 20));
        assert!(!host_suspended(None, Some(now - 3600), 50, None, now, 7, 20));
        assert!(!host_suspended(None, None, 50, None, now, 7, 20));
    }

    #[test]
    fn terminal_failures_update_like_php() {
        let a = after_terminal_failure(None, 0, None, None, "No HTTP response from inbox", 500);
        assert_eq!(a, AfterTerminal { streak_started_at: Some(500), consecutive: 1, last_live_at: None, last_live_status: None });
        let b = after_terminal_failure(Some(500), 1, None, None, "HTTP 404", 900);
        assert_eq!((b.streak_started_at, b.consecutive), (Some(500), 2));
        let c = after_terminal_failure(Some(500), 2, None, None, "HTTP 403 Forbidden", 900);
        assert_eq!(c, AfterTerminal { streak_started_at: Some(500), consecutive: 2, last_live_at: Some(900), last_live_status: Some(403) });
        let d = after_terminal_failure(Some(500), 2, None, None, "Remote host circuit is open", 900);
        assert_eq!((d.consecutive, d.last_live_at), (2, None));
    }

    #[test]
    fn bluesky_activities_are_detected() {
        assert!(activity_is_bluesky(&serde_json::json!({"actor": "did:plc:abc"})));
        assert!(activity_is_bluesky(&serde_json::json!({"actor": {"id": "https://bsky.app/profile/x"}})));
        assert!(!activity_is_bluesky(&serde_json::json!({"actor": "https://mkultra.monster/users/cmdr_nova"})));
    }

    #[test]
    fn error_text_keeps_php_shape() {
        assert_eq!(short("  a \n b  ", 180), " a b ");
        assert_eq!(short(&"x".repeat(200), 180), format!("{}…", "x".repeat(180)));
        assert_eq!(cut_bytes("héllo", 2), "h");
        assert_eq!(scrub("bad keyId=\"x\" signature=\"y\" body", 120), "bad body");
        assert_eq!(scrub("-----BEGIN RSA PRIVATE KEY----- x", 120), "");
    }
}
