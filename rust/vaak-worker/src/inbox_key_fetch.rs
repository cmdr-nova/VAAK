//! Rust fetch of an inbox sender's public key (cutover F2, item 2).
//!
//! Port of `ap_fetch_actor_pubkey` for the shared inbox: the same candidate
//! URLs (`ap_key_fetch_candidates`), PEM and owner extraction, fetch budgets
//! (the same Redis counters as `ap_inbox_fetch_budget_take`) and a negative
//! cache PHP also reads, so a dead key is fetched by one side only. The GET is
//! signed as the instance owner like `ap_signed_get_ex`, connects only to the
//! addresses the public-address check passed, follows no redirects and reads
//! at most 256 KiB. A found key is written to the same mirror PHP fills after
//! a verified delivery. Any failure leaves the delivery to PHP.

use std::net::{IpAddr, SocketAddr};
use std::time::Duration;

use anyhow::Result;
use serde_json::{json, Value};
use sha2::{Digest as _, Sha256};

use crate::ap_sign::{host_and_target, http_date, Signer};

/// AP_INBOX_KEY_FETCH_TIMEOUT, AP_INBOX_FETCH_BUDGET_WINDOW and the budgets.
const FETCH_TIMEOUT: Duration = Duration::from_secs(8);
const BUDGET_WINDOW: i64 = 600;
const MAX_PER_KEY: i64 = 6;
const MAX_PER_HOST: i64 = 240;
/// AP_INBOX_KEY_NEG_CACHE_TTL.
const NEG_TTL: i64 = 900;
/// AP_INBOX_RUST_KEY_MIRROR_TTL.
const MIRROR_TTL: i64 = 86_400;
const MAX_BYTES: usize = 262_144;
const THREADS_HOSTS: [&str; 4] = ["threads.net", "www.threads.net", "threads.com", "www.threads.com"];

pub fn enabled() -> bool {
    std::env::var("VAAK_INBOX_RUST_KEY_FETCH").is_ok_and(|v| v.trim() == "1")
}

/// Redis key of the negative cache entry PHP's ap_key_neg_cache_set also writes.
pub fn neg_key(key_id: &str) -> String {
    format!("vaak:inbox:keyneg:v1:{}", hex::encode(Sha256::digest(key_id.trim().as_bytes())))
}

/// `ap_redis_rate_count` key.
fn rate_key(bucket: &str) -> String {
    format!("vaak:rate:{}", hex::encode(Sha256::digest(bucket.trim().as_bytes())))
}

fn key_base(key_id: &str) -> &str {
    let key_id = key_id.trim();
    match key_id.split('#').next() {
        Some(base) if !base.is_empty() => base,
        _ => key_id,
    }
}

/// `ap_key_fetch_candidates`.
pub(crate) fn candidates(key_id: &str) -> Vec<String> {
    let base = key_base(key_id);
    let Some(rest) = base.strip_prefix("https://") else { return Vec::new() };
    let authority = rest.split(['/', '?']).next().unwrap_or("");
    let host = authority.split(':').next().unwrap_or("").to_ascii_lowercase();
    let after = &rest[authority.len()..];
    let (path, query) = match after.split_once('?') {
        Some((p, q)) if !q.is_empty() => (p, format!("?{q}")),
        Some((p, _)) => (p, String::new()),
        None => (after, String::new()),
    };
    let path = if path.is_empty() { "/" } else { path };
    let mut out: Vec<String> = Vec::new();
    let mut add = |url: String| {
        if !url.is_empty() && !out.contains(&url) {
            out.push(url);
        }
    };
    add(base.to_string());
    if !path.ends_with('/') {
        add(format!("https://{host}{path}/{query}"));
    }
    if THREADS_HOSTS.contains(&host.as_str()) {
        let norm = if path.ends_with('/') { path.to_string() } else { format!("{path}/") };
        for th in THREADS_HOSTS {
            add(format!("https://{th}{norm}{query}"));
        }
    }
    out
}

/// `ap_extract_pubkey_pem`.
pub(crate) fn extract_pem(doc: &Value) -> Option<String> {
    let pk = doc.get("publicKey");
    let mut pem = pk.and_then(|pk| pk.get("publicKeyPem")).or_else(|| pk.and_then(|pk| pk.get(0)).and_then(|k| k.get("publicKeyPem"))).cloned();
    if pem.as_ref().is_none_or(php_falsy) {
        pem = doc.get("publicKeyPem").cloned();
    }
    if pem.as_ref().is_none_or(php_falsy) {
        if let Some(list) = doc.get("assertionMethod").and_then(Value::as_array) {
            pem = list.iter().find_map(|am| am.get("publicKeyPem").cloned());
        }
    }
    let mut pem = pem?.as_str()?.to_string();
    if !pem.contains('\n') && pem.contains("BEGIN") {
        pem = pem.split_whitespace().collect::<Vec<_>>().join("\n");
        for (from, to) in [
            ("BEGIN\nPUBLIC\nKEY", "BEGIN PUBLIC KEY"),
            ("END\nPUBLIC\nKEY", "END PUBLIC KEY"),
            ("BEGIN\nRSA\nPUBLIC\nKEY", "BEGIN RSA PUBLIC KEY"),
            ("END\nRSA\nPUBLIC\nKEY", "END RSA PUBLIC KEY"),
        ] {
            pem = pem.replace(from, to);
        }
    }
    (pem.contains("BEGIN PUBLIC KEY") || pem.contains("BEGIN RSA PUBLIC KEY")).then_some(pem)
}

fn php_falsy(v: &Value) -> bool {
    match v {
        Value::Null => true,
        Value::Bool(b) => !b,
        Value::String(s) => s.is_empty() || s == "0",
        Value::Array(a) => a.is_empty(),
        Value::Number(n) => n.as_f64() == Some(0.0),
        Value::Object(_) => false,
    }
}

/// `ap_extract_pubkey_owner`.
pub(crate) fn extract_owner(doc: &Value) -> Option<String> {
    let https = |v: Option<&Value>| v.and_then(Value::as_str).filter(|s| s.starts_with("https://")).map(str::to_string);
    let mut pk = doc.get("publicKey");
    if let Some(list) = pk.and_then(Value::as_array) {
        pk = list.first();
    }
    let pk = pk.filter(|v| v.is_object() || v.is_array());
    https(pk.and_then(|pk| pk.get("owner"))).or_else(|| https(doc.get("owner"))).or_else(|| pk.and_then(|_| https(doc.get("id"))))
}

/// Why a fetch did not produce a key (the stat is `fwd_key_<reason>`).
#[derive(Debug, PartialEq, Eq)]
pub(crate) enum Miss {
    Disabled,
    Negative,
    Budget,
    NotPublic,
    Failed,
}

impl Miss {
    pub(crate) fn reason(&self) -> &'static str {
        match self {
            Miss::Disabled => "no_key",
            Miss::Negative => "key_negative",
            Miss::Budget => "key_budget",
            Miss::NotPublic => "key_not_public",
            Miss::Failed => "key_fetch_failed",
        }
    }
}

/// Every address `host` resolves to, when all of them are public
/// (`ap_net_host_public_ips`).
async fn public_addrs(host: &str) -> Option<Vec<IpAddr>> {
    let host = host.trim_matches(|c| c == '[' || c == ']' || c == '.').to_ascii_lowercase();
    if host.is_empty() || host == "localhost" || host.ends_with(".localhost") || host.ends_with(".local") || host.ends_with(".internal") {
        return None;
    }
    let ips: Vec<IpAddr> = if let Ok(ip) = host.parse::<IpAddr>() {
        vec![ip]
    } else {
        let found = tokio::time::timeout(Duration::from_secs(2), tokio::net::lookup_host((host.as_str(), 443))).await.ok()?.ok()?;
        let mut ips: Vec<IpAddr> = found.map(|a| a.ip()).collect();
        ips.dedup();
        ips
    };
    if ips.is_empty() || !ips.iter().all(|ip| crate::direct_preview::public_ip(*ip).unwrap_or(false)) {
        return None;
    }
    Some(ips)
}

/// Spend one unit of each budget; false once any is spent.
async fn budget_take(redis: &mut redis::aio::ConnectionManager, key_id: &str) -> Result<bool> {
    let base = key_base(key_id);
    let host = crate::inbox_accept::host_of(base).unwrap_or_default();
    for (bucket, max) in [(format!("inbox-fetch:key:{base}"), MAX_PER_KEY), (format!("inbox-fetch:keyhost:{host}"), MAX_PER_HOST)] {
        let key = rate_key(&bucket);
        let count: i64 = redis::cmd("INCR").arg(&key).query_async(redis).await?;
        if count == 1 {
            let _: () = redis::cmd("EXPIRE").arg(&key).arg(BUDGET_WINDOW).query_async(redis).await?;
        }
        if count > max {
            return Ok(false);
        }
    }
    Ok(true)
}

struct Fetched {
    status: u16,
    body: Option<Vec<u8>>,
}

/// `ap_signed_get_ex` with `ap_http_curl_get_ex`'s limits.
async fn signed_get(signer: &Signer, url: &str, addrs: &[IpAddr]) -> Result<Fetched> {
    let (host, target) = host_and_target(url).ok_or_else(|| anyhow::anyhow!("not https"))?;
    let date = http_date(chrono::Utc::now().timestamp());
    let signature = signer.get_signature(&host, &target, &date)?;
    let pinned: Vec<SocketAddr> = addrs.iter().map(|ip| SocketAddr::new(*ip, 443)).collect();
    let client = reqwest::Client::builder()
        .redirect(reqwest::redirect::Policy::none())
        .https_only(true)
        .timeout(FETCH_TIMEOUT)
        .connect_timeout(Duration::from_secs(3))
        .resolve_to_addrs(&host.to_ascii_lowercase(), &pinned)
        .build()?;
    let mut resp = client
        .get(url)
        .header("Accept", r#"application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams""#)
        .header("User-Agent", user_agent())
        .header("Date", &date)
        .header("Signature", signature)
        .send()
        .await?;
    let status = resp.status().as_u16();
    if !(200..300).contains(&status) {
        return Ok(Fetched { status, body: None });
    }
    let mut buf = Vec::new();
    while let Some(chunk) = resp.chunk().await? {
        buf.extend_from_slice(&chunk);
        if buf.len() > MAX_BYTES {
            return Ok(Fetched { status: 0, body: None });
        }
    }
    Ok(Fetched { status, body: (!buf.is_empty()).then_some(buf) })
}

fn user_agent() -> String {
    std::env::var("VAAK_HTTP_USER_AGENT").unwrap_or_else(|_| "VAAK-worker (inbox-key; mkultra.monster; +https://github.com/cmdr-nova/VAAK)".to_string())
}

fn signer() -> Option<Signer> {
    let path = std::env::var("VAAK_AP_SIGNING_KEY").unwrap_or_else(|_| "/etc/mkultra/ap-inbox/cmdr_nova_private.pem".to_string());
    let key_id = std::env::var("VAAK_AP_SIGNING_KEY_ID").unwrap_or_else(|_| "https://mkultra.monster/users/cmdr_nova#main-key".to_string());
    match Signer::load(&path, &key_id) {
        Ok(s) => Some(s),
        Err(e) => {
            tracing::warn!(error = %e, "inbox key fetch: no signing key");
            None
        }
    }
}

/// The key record for `key_id` (`{pem, owner, relay}`, as in the mirror),
/// fetched and mirrored, or why not.
pub(crate) async fn fetch(redis: &mut redis::aio::ConnectionManager, db: &tokio_postgres::Client, key_id: &str) -> std::result::Result<Value, Miss> {
    if !enabled() {
        return Err(Miss::Disabled);
    }
    let key_id = key_id.trim();
    if key_id.is_empty() || key_id.len() > 500 || !key_id.starts_with("https://") {
        return Err(Miss::Failed);
    }
    let neg: Option<String> = redis::cmd("GET").arg(neg_key(key_id)).query_async(redis).await.map_err(|_| Miss::Failed)?;
    if neg.is_some() {
        return Err(Miss::Negative);
    }
    if !budget_take(redis, key_id).await.map_err(|_| Miss::Failed)? {
        return Err(Miss::Budget);
    }
    let key_host = crate::inbox_accept::host_of(key_id).ok_or(Miss::Failed)?;
    if public_addrs(&key_host).await.is_none() {
        return Err(Miss::NotPublic);
    }
    let signer = signer().ok_or(Miss::Failed)?;

    let mut last_http: u16 = 0;
    let mut last_reason = "no_candidate".to_string();
    for url in candidates(key_id) {
        let Some(host) = crate::inbox_accept::host_of(&url) else { continue };
        let Some(addrs) = public_addrs(&host).await else { continue };
        let got = match signed_get(&signer, &url, &addrs).await {
            Ok(got) => got,
            Err(_) => {
                last_reason = "empty_or_transport".into();
                continue;
            }
        };
        if got.status > 0 {
            last_http = got.status;
        }
        let Some(body) = got.body else {
            last_reason = if got.status > 0 { format!("http_{}", got.status) } else { "empty_or_transport".into() };
            continue;
        };
        let Ok(doc) = serde_json::from_slice::<Value>(&body) else {
            last_reason = "bad_json".into();
            continue;
        };
        if !doc.is_object() {
            last_reason = "bad_json".into();
            continue;
        }
        if doc.get("success") == Some(&Value::Bool(false)) {
            let err = doc.get("error").and_then(Value::as_str).unwrap_or("error_envelope").to_ascii_lowercase();
            let slug: String = err.chars().map(|c| if c.is_ascii_lowercase() || c.is_ascii_digit() { c } else { '_' }).collect();
            last_reason = format!("meta_{}", collapse_underscores(&slug));
            continue;
        }
        let Some(pem) = extract_pem(&doc) else {
            last_reason = "no_publicKeyPem".into();
            continue;
        };
        let owner = extract_owner(&doc);
        let relay = relay_host(db, &key_host).await;
        let record = json!({"pem": pem, "owner": owner, "relay": relay});
        let _: std::result::Result<(), _> = redis::pipe()
            .set_ex(crate::inbox_accept::key_mirror_key(key_id), record.to_string(), MIRROR_TTL as u64)
            .del(neg_key(key_id))
            .query_async(redis)
            .await;
        tracing::info!(key_id = %short(key_id), via = %short(&url), http = got.status, "inbox key_fetch_ok");
        return Ok(record);
    }
    let neg = json!({"reason": last_reason, "http": last_http, "at": chrono::Utc::now().timestamp(), "keyId": key_id, "via": "rust"});
    let _: std::result::Result<(), _> = redis::cmd("SET").arg(neg_key(key_id)).arg(neg.to_string()).arg("EX").arg(NEG_TTL).query_async(redis).await;
    tracing::info!(key_id = %short(key_id), http = last_http, reason = %last_reason, "inbox key_fetch_fail");
    Err(Miss::Failed)
}

/// `ap_relay_is_accepted_key`: the key's host is an accepted relay's inbox host.
async fn relay_host(db: &tokio_postgres::Client, key_host: &str) -> bool {
    let Ok(rows) = db.query("SELECT inbox_url FROM ap_relays WHERE state = 'accepted'", &[]).await else { return false };
    rows.iter().any(|row| {
        let inbox: String = row.get(0);
        inbox.starts_with("https://") && crate::inbox_accept::host_of(&inbox).as_deref() == Some(key_host)
    })
}

/// `preg_replace('/[^a-z0-9]+/', '_', ...)` collapses each run to one "_".
fn collapse_underscores(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        if c == '_' && out.ends_with('_') {
            continue;
        }
        out.push(c);
    }
    out
}

fn short(s: &str) -> String {
    s.chars().take(120).collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn candidates_follow_php() {
        assert_eq!(candidates("https://m.test/users/a#main-key"), vec!["https://m.test/users/a", "https://m.test/users/a/"]);
        assert_eq!(candidates("https://m.test/users/a/"), vec!["https://m.test/users/a/"]);
        assert_eq!(candidates("https://m.test/key?id=1"), vec!["https://m.test/key?id=1", "https://m.test/key/?id=1"]);
        assert_eq!(
            candidates("https://threads.net/ap/users/1"),
            vec![
                "https://threads.net/ap/users/1",
                "https://threads.net/ap/users/1/",
                "https://www.threads.net/ap/users/1/",
                "https://threads.com/ap/users/1/",
                "https://www.threads.com/ap/users/1/",
            ]
        );
        assert!(candidates("http://m.test/users/a").is_empty());
    }

    #[test]
    fn pem_extraction_follows_php() {
        let pem = "-----BEGIN PUBLIC KEY-----\nAAAA\n-----END PUBLIC KEY-----\n";
        assert_eq!(extract_pem(&json!({"publicKey": {"publicKeyPem": pem}})).as_deref(), Some(pem));
        assert_eq!(extract_pem(&json!({"publicKey": [{"publicKeyPem": pem}]})).as_deref(), Some(pem));
        assert_eq!(extract_pem(&json!({"publicKeyPem": pem})).as_deref(), Some(pem));
        assert_eq!(extract_pem(&json!({"assertionMethod": [{"publicKeyPem": pem}]})).as_deref(), Some(pem));
        assert_eq!(
            extract_pem(&json!({"publicKeyPem": "-----BEGIN PUBLIC KEY----- AAAA BBBB -----END PUBLIC KEY-----"})).as_deref(),
            Some("-----BEGIN PUBLIC KEY-----\nAAAA\nBBBB\n-----END PUBLIC KEY-----")
        );
        assert_eq!(extract_pem(&json!({"publicKeyPem": "not a key"})), None);
        assert_eq!(extract_pem(&json!({})), None);
    }

    #[test]
    fn owner_extraction_follows_php() {
        assert_eq!(extract_owner(&json!({"publicKey": {"owner": "https://m.test/u"}})).as_deref(), Some("https://m.test/u"));
        assert_eq!(extract_owner(&json!({"owner": "https://m.test/u", "publicKeyPem": "x"})).as_deref(), Some("https://m.test/u"));
        assert_eq!(extract_owner(&json!({"id": "https://m.test/u", "publicKey": {"publicKeyPem": "x"}})).as_deref(), Some("https://m.test/u"));
        assert_eq!(extract_owner(&json!({"id": "https://m.test/u"})), None);
    }

    #[test]
    fn neg_and_rate_keys_match_php() {
        // hash('sha256', 'https://m.test/u#main-key') and 'vaak:rate:' . hash('sha256', $bucket)
        assert_eq!(neg_key(" https://m.test/u#main-key "), format!("vaak:inbox:keyneg:v1:{}", hex::encode(Sha256::digest(b"https://m.test/u#main-key"))));
        assert_eq!(rate_key("inbox-fetch:key:https://m.test/u"), format!("vaak:rate:{}", hex::encode(Sha256::digest(b"inbox-fetch:key:https://m.test/u"))));
    }
}
