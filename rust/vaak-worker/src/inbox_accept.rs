//! Rust front door for AP inbox POSTs (Rust cutover F2).
//!
//! With VAAK_INBOX_RUST_ACCEPT=1 Caddy sends inbox POSTs here. The common
//! delivery (a key PHP already fetched and verified, a signature that checks
//! out, a signer who is the activity's actor, a host that is not blocked) is
//! written to `ap_inbox_spool` and answered 202; api/bin/inbox-spool-drain.php
//! processes it with the same PHP routing as before. Every other request is
//! forwarded byte for byte to PHP on a loopback listener, so origin
//! recovery, relays, forwarded Creates, key fetches and rejections keep their
//! PHP behaviour. Rust never fetches a key and never routes an activity.

use std::collections::HashMap;
use std::sync::{LazyLock, Mutex};
use std::time::{Duration, Instant};

use anyhow::{Context, Result};
use axum::body::Bytes;
use axum::extract::State;
use axum::http::{HeaderMap, HeaderValue, StatusCode, Uri};
use axum::response::{IntoResponse, Response};
use serde_json::Value;
use sha2::{Digest as _, Sha256};
use tokio_postgres::Client;

use crate::inbox_sig_shadow::{claimed_key_id, verify_body, Record};

/// Same limits as PHP (AP_INBOX_MAX_BODY, AP_INBOX_RATE_MAX/WINDOW).
pub const MAX_BODY: usize = 262_144;
const RATE_MAX: i64 = 600;
const RATE_WINDOW: i64 = 3600;
const STATS_KEY: &str = "vaak:inbox_rust:stats:v1";
const SPOOL_QUEUE: &str = "inbox_spool";
const BLOCKS_TTL: Duration = Duration::from_secs(60);
const IDLE_MAX: usize = 4;

pub fn enabled() -> bool {
    std::env::var("VAAK_INBOX_RUST_ACCEPT").is_ok_and(|v| v.trim() == "1")
}

fn php_url() -> String {
    std::env::var("VAAK_INBOX_PHP_URL").unwrap_or_else(|_| "http://127.0.0.1:8796".to_string()).trim_end_matches('/').to_string()
}

/// Redis key PHP's ap_inbox_rust_key_mirror writes after a verified delivery.
pub fn key_mirror_key(key_id: &str) -> String {
    format!("vaak:inbox:key:v1:{}", hex::encode(Sha256::digest(key_id.trim().as_bytes())))
}

pub async fn handle(State(state): State<crate::http::AppState>, uri: Uri, headers: HeaderMap, body: Bytes) -> Response {
    let cfg = state.cfg.clone();
    let decision = fast_path(&cfg, &uri, &headers, &body).await;
    let field = match &decision {
        Ok(_) => "spooled".to_string(),
        Err(reason) => format!("fwd_{reason}"),
    };
    stat(&cfg, &field).await;
    match decision {
        Ok(_) => (
            StatusCode::ACCEPTED,
            [("content-type", "application/json"), ("cache-control", "no-store"), ("x-content-type-options", "nosniff")],
            r#"{"status":"accepted","action":"spooled"}"#,
        )
            .into_response(),
        Err(_) => forward(&uri, &headers, body).await,
    }
}

/// One reconnecting connection per Redis URL, shared by every request.
async fn redis_conn(url: &str) -> Result<redis::aio::ConnectionManager> {
    static CONNS: LazyLock<tokio::sync::Mutex<HashMap<String, redis::aio::ConnectionManager>>> = LazyLock::new(Default::default);
    let mut conns = CONNS.lock().await;
    if let Some(conn) = conns.get(url) {
        return Ok(conn.clone());
    }
    let conn = redis::aio::ConnectionManager::new(redis::Client::open(url)?).await?;
    conns.insert(url.to_string(), conn.clone());
    Ok(conn)
}

async fn stat(cfg: &crate::config::Config, field: &str) {
    let Ok(mut redis) = redis_conn(&cfg.redis_url).await else { return };
    let _: Result<(), _> = redis::pipe().hincr(STATS_KEY, field, 1).expire(STATS_KEY, 14 * 86400).query_async(&mut redis).await;
}

/// Lowercase header map with `ap_header` semantics: first non-empty value.
fn header_map(headers: &HeaderMap) -> HashMap<String, String> {
    let mut out = HashMap::new();
    for (name, value) in headers {
        let Ok(value) = value.to_str() else { continue };
        let value = value.trim();
        if !value.is_empty() {
            out.entry(name.as_str().to_ascii_lowercase()).or_insert_with(|| value.to_string());
        }
    }
    out
}

/// The client address Caddy saw: the right-most X-Forwarded-For entry that
/// is not loopback. Only used for rate admission and the spool row.
fn client_ip(headers: &HashMap<String, String>) -> String {
    headers
        .get("x-forwarded-for")
        .and_then(|xff| {
            xff.rsplit(',')
                .map(str::trim)
                .find(|ip| ip.parse::<std::net::IpAddr>().is_ok_and(|ip| !ip.is_loopback()))
                .map(str::to_string)
        })
        .unwrap_or_else(|| "unknown".to_string())
}

pub(crate) fn host_of(url: &str) -> Option<String> {
    let rest = url.split_once("://")?.1;
    let authority = rest.split(['/', '?', '#']).next()?;
    let host = authority.rsplit_once('@').map(|(_, h)| h).unwrap_or(authority);
    let host = if host.starts_with('[') { host } else { host.split(':').next().unwrap_or(host) };
    (!host.is_empty()).then(|| host.to_ascii_lowercase())
}

/// `ap_inbox_actor_matches_key`: same host, and the key's recorded owner is
/// the actor, or with no owner on record the keyId is the actor's own.
pub(crate) fn actor_matches_key(key_id: &str, actor: &str, owner: Option<&str>) -> bool {
    match (host_of(key_id), host_of(actor)) {
        (Some(k), Some(a)) if k == a => {}
        _ => return false,
    }
    let actor = actor.trim_end_matches('/');
    if let Some(owner) = owner.filter(|o| o.starts_with("https://")) {
        return owner.trim_end_matches('/') == actor;
    }
    let base = key_id.split('#').next().unwrap_or(key_id).trim_end_matches('/');
    base == actor || base.starts_with(&format!("{actor}/"))
}

/// `ap_is_blocked_host` against domain blocks (mutes do not refuse delivery).
pub(crate) fn host_blocked(host: &str, blocked: &[String]) -> bool {
    blocked.iter().any(|b| !b.is_empty() && (host == b || host.ends_with(&format!(".{b}"))))
}

/// Activity type and actor id, or None when PHP should look at the body.
pub(crate) fn activity_head(body: &[u8]) -> Option<(String, String)> {
    let doc: Value = serde_json::from_slice(body).ok()?;
    let kind = doc.get("type")?.as_str()?.to_string();
    let actor = match doc.get("actor")? {
        Value::String(id) => id.clone(),
        Value::Object(obj) => obj.get("id")?.as_str()?.to_string(),
        _ => return None,
    };
    (!kind.is_empty() && actor.starts_with("https://")).then_some((kind, actor))
}

async fn fast_path(cfg: &crate::config::Config, uri: &Uri, headers: &HeaderMap, body: &Bytes) -> Result<i64, &'static str> {
    if !enabled() {
        return Err("off");
    }
    if body.is_empty() || body.len() > MAX_BODY {
        return Err("body_size");
    }
    let map = header_map(headers);
    let key_id = claimed_key_id(&map).ok_or("no_signature")?;
    let ip = client_ip(&map);
    let mut redis = redis_conn(&cfg.redis_url).await.map_err(|_| "redis")?;

    let now = chrono::Utc::now().timestamp();
    let rate_key = format!("vaak:inbox_rust:rate:v1:{ip}:{}", now / RATE_WINDOW);
    let (count, _): (i64, bool) =
        redis::pipe().incr(&rate_key, 1).expire(&rate_key, RATE_WINDOW * 2).query_async(&mut redis).await.map_err(|_| "redis")?;
    if count > RATE_MAX {
        return Err("rate");
    }

    let mirrored: Option<String> = redis::cmd("GET").arg(key_mirror_key(&key_id)).query_async(&mut redis).await.map_err(|_| "redis")?;
    let mirrored: Value = mirrored.and_then(|raw| serde_json::from_str(&raw).ok()).ok_or("no_key")?;
    let pem = mirrored.get("pem").and_then(Value::as_str).ok_or("no_key")?.to_string();
    let owner = mirrored.get("owner").and_then(Value::as_str).map(str::to_string);

    let mut server = HashMap::new();
    server.insert("REQUEST_METHOD".to_string(), "POST".to_string());
    server.insert("REQUEST_URI".to_string(), uri.path_and_query().map(|p| p.as_str()).unwrap_or("/inbox").to_string());
    for (header, key) in [("host", "HTTP_HOST"), ("x-forwarded-host", "HTTP_X_FORWARDED_HOST")] {
        if let Some(v) = map.get(header) {
            server.insert(key.to_string(), v.clone());
        }
    }
    let rec = Record { now, headers: map, server, pem: Some(pem), ..Default::default() };
    let verdict = verify_body(&rec, body.to_vec());
    let sig_path = verdict.path.clone().filter(|_| verdict.ok).ok_or("signature")?;

    let key_host = host_of(&key_id).ok_or("signature")?;
    let db = conn(cfg).await.map_err(|_| "db")?;
    let blocked = blocked_domains(&db).await.map_err(|_| "db")?;
    if host_blocked(&key_host, &blocked) {
        return Err("blocked");
    }
    let (kind, actor) = activity_head(body).ok_or("body")?;
    if !actor_matches_key(&key_id, &actor, owner.as_deref()) {
        return Err("actor_mismatch");
    }
    // ap-user.php answers 404 for a personal inbox with no active local user;
    // leave those (and any odd user path) to PHP.
    if let Some(user) = personal_inbox_user(uri.path()) {
        let found = db
            .query_opt("SELECT 1 FROM ap_users WHERE lower(username) = $1 AND disabled_at IS NULL LIMIT 1", &[&user])
            .await
            .map_err(|_| "db")?;
        if found.is_none() {
            return Err("unknown_user");
        }
    }
    let id = spool_insert(&db, &ip, &key_id, uri.path(), &sig_path, &kind, body).await.map_err(|e| {
        tracing::warn!(error = %e, "inbox spool insert failed; forwarding to PHP");
        "spool"
    })?;
    idle_return(db);
    // The drain worker also scans for pending rows, so a lost wake-up only delays.
    if let Ok(mut queue) = redis_conn(&cfg.redis_queue_url).await {
        let key = format!("vaak:queue:{SPOOL_QUEUE}");
        let _: Result<(), _> = redis::pipe().lpush(&key, id).expire(&key, 86400).query_async(&mut queue).await;
    }
    Ok(id)
}

/// The username in `/users/{name}/inbox[/]`, lowercased, when it is one
/// `ap_local_user_by_username` would look up. `Some("")` for any other
/// personal inbox path, which then never matches a user.
pub(crate) fn personal_inbox_user(path: &str) -> Option<String> {
    let rest = path.strip_prefix("/users/")?;
    let name = rest.strip_suffix("/inbox/").or_else(|| rest.strip_suffix("/inbox"))?;
    let name = name.to_ascii_lowercase();
    let ok = name.len() >= 2
        && name.len() <= 30
        && name.starts_with(|c: char| c.is_ascii_lowercase())
        && name.bytes().all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'_');
    Some(if ok { name } else { String::new() })
}

async fn spool_insert(db: &Client, ip: &str, key_id: &str, path: &str, sig_path: &str, kind: &str, body: &[u8]) -> Result<i64> {
    let body = std::str::from_utf8(body).context("body is not UTF-8")?;
    let row = db
        .query_one(
            "INSERT INTO ap_inbox_spool (received_at, client_ip, key_id, request_path, signature_path, activity_type, body)
             VALUES (now(), $1, $2, $3, $4, $5, $6) RETURNING id",
            &[&ip, &key_id, &path, &sig_path, &kind, &body],
        )
        .await?;
    Ok(row.get(0))
}

/// Domain blocks and when they were read.
type BlockCache = Option<(Instant, Vec<String>)>;
static BLOCKS: Mutex<BlockCache> = Mutex::new(None);

async fn blocked_domains(db: &Client) -> Result<Vec<String>> {
    if let Some((at, list)) = BLOCKS.lock().ok().and_then(|b| b.clone()) {
        if at.elapsed() < BLOCKS_TTL {
            return Ok(list);
        }
    }
    let list: Vec<String> = db
        .query("SELECT lower(value::text) FROM ap_blocks WHERE scope = 'domain' AND COALESCE(kind, 'block') <> 'mute'", &[])
        .await?
        .iter()
        .map(|row| row.get(0))
        .collect();
    if let Ok(mut cache) = BLOCKS.lock() {
        *cache = Some((Instant::now(), list.clone()));
    }
    Ok(list)
}

static IDLE: Mutex<Vec<Client>> = Mutex::new(Vec::new());

async fn conn(cfg: &crate::config::Config) -> Result<Client> {
    let idle = IDLE.lock().ok().and_then(|mut idle| {
        idle.retain(|c| !c.is_closed());
        idle.pop()
    });
    match idle {
        Some(c) => Ok(c),
        None => crate::db::connect(&cfg.database_url).await,
    }
}

fn idle_return(db: Client) {
    if let Ok(mut idle) = IDLE.lock() {
        if idle.len() < IDLE_MAX && !db.is_closed() {
            idle.push(db);
        }
    }
}

static FORWARD: LazyLock<reqwest::Client> = LazyLock::new(|| {
    reqwest::Client::builder()
        .redirect(reqwest::redirect::Policy::none())
        .timeout(Duration::from_secs(60))
        .build()
        .expect("inbox forward client")
});

const HOP_BY_HOP: &[&str] = &["connection", "keep-alive", "proxy-connection", "transfer-encoding", "te", "trailer", "upgrade", "content-length"];

/// Hand the request to PHP unchanged and return PHP's answer.
async fn forward(uri: &Uri, headers: &HeaderMap, body: Bytes) -> Response {
    let url = format!("{}{}", php_url(), uri.path_and_query().map(|p| p.as_str()).unwrap_or("/inbox"));
    let mut req = FORWARD.post(&url).body(body);
    for (name, value) in headers {
        if !HOP_BY_HOP.contains(&name.as_str()) {
            req = req.header(name, value);
        }
    }
    match req.send().await {
        Ok(resp) => {
            let status = StatusCode::from_u16(resp.status().as_u16()).unwrap_or(StatusCode::BAD_GATEWAY);
            let mut out = HeaderMap::new();
            for (name, value) in resp.headers() {
                if !HOP_BY_HOP.contains(&name.as_str()) {
                    if let Ok(value) = HeaderValue::from_bytes(value.as_bytes()) {
                        out.append(name.clone(), value);
                    }
                }
            }
            let bytes = resp.bytes().await.unwrap_or_default();
            (status, out, bytes).into_response()
        }
        Err(e) => {
            tracing::error!(error = %e, "inbox forward to PHP failed");
            (StatusCode::SERVICE_UNAVAILABLE, [("content-type", "application/json"), ("retry-after", "60")], r#"{"error":"Service unavailable"}"#)
                .into_response()
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn actor_key_match_follows_php() {
        let actor = "https://example.com/users/alice";
        assert!(actor_matches_key("https://example.com/users/alice#main-key", actor, None));
        assert!(actor_matches_key("https://example.com/users/alice/main-key", actor, None));
        assert!(!actor_matches_key("https://example.com/users/bob#main-key", actor, None));
        assert!(!actor_matches_key("https://other.test/users/alice#main-key", actor, None));
        assert!(actor_matches_key("https://example.com/keys/1", actor, Some("https://example.com/users/alice")));
        assert!(!actor_matches_key("https://example.com/users/alice#main-key", actor, Some("https://example.com/users/bob")));
        assert!(!actor_matches_key("https://example.com/users/alicex#main-key", actor, None));
    }

    #[test]
    fn domain_blocks_cover_subdomains_only() {
        let blocked = vec!["bad.test".to_string()];
        assert!(host_blocked("bad.test", &blocked));
        assert!(host_blocked("a.bad.test", &blocked));
        assert!(!host_blocked("notbad.test", &blocked));
    }

    #[test]
    fn activity_head_needs_type_and_https_actor() {
        assert_eq!(
            activity_head(br#"{"type":"Like","actor":{"id":"https://a.test/u"}}"#),
            Some(("Like".to_string(), "https://a.test/u".to_string()))
        );
        assert_eq!(activity_head(br#"{"type":"Like"}"#), None);
        assert_eq!(activity_head(br#"{"type":"Like","actor":"http://a.test/u"}"#), None);
        assert_eq!(activity_head(b"not json"), None);
    }

    #[test]
    fn client_ip_skips_loopback_proxies() {
        let mut h = HashMap::new();
        h.insert("x-forwarded-for".to_string(), "203.0.113.9, 127.0.0.1".to_string());
        assert_eq!(client_ip(&h), "203.0.113.9");
        assert_eq!(client_ip(&HashMap::new()), "unknown");
    }

    #[test]
    fn personal_inbox_user_matches_php_pattern() {
        assert_eq!(personal_inbox_user("/inbox"), None);
        assert_eq!(personal_inbox_user("/users/Juno_Flux/inbox/").as_deref(), Some("juno_flux"));
        assert_eq!(personal_inbox_user("/users/x/inbox").as_deref(), Some(""));
        assert_eq!(personal_inbox_user("/users/9abc/inbox").as_deref(), Some(""));
        assert_eq!(personal_inbox_user("/users/a%2Fb/inbox").as_deref(), Some(""));
    }

    #[test]
    fn host_of_strips_port_and_userinfo() {
        assert_eq!(host_of("https://U@Example.COM:443/x#k").as_deref(), Some("example.com"));
        assert_eq!(host_of("nope"), None);
    }
}
