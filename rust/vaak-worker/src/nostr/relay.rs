//! One-shot relay reads: connect, send REQs, collect events until EOSE.
//!
//! Relay URLs come from other people's kind 10002 events, so every connect
//! goes through the same public-address rule as PHP's outbound guard
//! (`direct_preview::public_ip`, a port of api/ap-net-guard.php): only
//! `wss://` on port 443, every resolved address must be public, and the TCP
//! connection goes to an address we checked (no second DNS lookup).

use std::collections::HashMap;
use std::net::SocketAddr;
use std::sync::{Arc, OnceLock};
use std::time::Duration;

use anyhow::{anyhow, bail, Context, Result};
use futures_util::{SinkExt, StreamExt};
use serde_json::{json, Value};
use tokio::io::{AsyncRead, AsyncWrite};
use tokio::net::TcpStream;
use tokio::time::{timeout_at, Instant};
use tokio_tungstenite::tungstenite::protocol::WebSocketConfig;
use tokio_tungstenite::tungstenite::client::IntoClientRequest;
use tokio_tungstenite::tungstenite::Message;

use super::event::Event;

// relay.damus.io answers 503 to some agents ("VAAK-Nostr/1.0", none at all,
// "VAAK-Nostr/1.0 (+https://…)") and 101 to this form, checked from prod.
const USER_AGENT: &str = "Mozilla/5.0 (compatible; VAAK-Nostr/1.0)";

/// Largest websocket message accepted from a relay.
const MAX_MESSAGE_BYTES: usize = 512 * 1024;
const CONNECT_TIMEOUT: Duration = Duration::from_secs(8);

/// `wss://host[/path]`, lowercase host, no port other than 443, no userinfo,
/// query or fragment, no IP literal. None when the URL is not usable.
pub fn normalize_relay_url(raw: &str) -> Option<String> {
    let raw = raw.trim();
    if raw.len() > 200 {
        return None;
    }
    let rest = raw.get(..6).filter(|p| p.eq_ignore_ascii_case("wss://")).map(|_| &raw[6..])?;
    if rest.contains(['?', '#', '@', '\\']) || rest.chars().any(char::is_whitespace) {
        return None;
    }
    let (authority, path) = match rest.find('/') {
        Some(i) => (&rest[..i], &rest[i..]),
        None => (rest, ""),
    };
    let host = match authority.rsplit_once(':') {
        Some((h, "443")) => h,
        Some(_) => return None,
        None => authority,
    };
    let host = host.trim_end_matches('.').to_ascii_lowercase();
    if host.is_empty()
        || host.len() > 120
        || !host.contains('.')
        || host.starts_with(['.', '-'])
        || !host.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'.' || b == b'-')
        || host.bytes().all(|b| b.is_ascii_digit() || b == b'.')
        || host == "localhost"
        || host.ends_with(".localhost")
        || host.ends_with(".local")
        || host.ends_with(".internal")
    {
        return None;
    }
    let path = path.trim_end_matches('/');
    if !path.bytes().all(|b| b.is_ascii_alphanumeric() || b"/_.-~".contains(&b)) || path.contains("..") {
        return None;
    }
    Some(format!("wss://{host}{path}"))
}

fn host_of(url: &str) -> &str {
    let rest = url.strip_prefix("wss://").unwrap_or(url);
    rest.split('/').next().unwrap_or("")
}

fn tls_config() -> Arc<rustls::ClientConfig> {
    static CONFIG: OnceLock<Arc<rustls::ClientConfig>> = OnceLock::new();
    CONFIG
        .get_or_init(|| {
            let mut roots = rustls::RootCertStore::empty();
            roots.extend(webpki_roots::TLS_SERVER_ROOTS.iter().cloned());
            let mut config = rustls::ClientConfig::builder_with_provider(Arc::new(rustls::crypto::ring::default_provider()))
                .with_safe_default_protocol_versions()
                .expect("rustls protocol versions")
                .with_root_certificates(roots)
                .with_no_client_auth();
            // Websocket upgrades are HTTP/1.1; offer it like curl --http1.1.
            config.alpn_protocols = vec![b"http/1.1".to_vec()];
            Arc::new(config)
        })
        .clone()
}

/// Resolve, refuse anything that is not a public address, connect.
async fn guarded_tcp(host: &str) -> Result<TcpStream> {
    let addrs: Vec<SocketAddr> = tokio::time::timeout(CONNECT_TIMEOUT, tokio::net::lookup_host((host, 443)))
        .await
        .map_err(|_| anyhow!("dns timeout"))?
        .context("dns")?
        .collect();
    if addrs.is_empty() {
        bail!("no address");
    }
    for addr in &addrs {
        if !crate::direct_preview::public_ip(addr.ip())? {
            bail!("refused non-public address");
        }
    }
    let mut last = anyhow!("connect failed");
    for addr in addrs.iter().take(3) {
        match tokio::time::timeout(CONNECT_TIMEOUT, TcpStream::connect(addr)).await {
            Ok(Ok(stream)) => return Ok(stream),
            Ok(Err(e)) => last = anyhow!("connect: {e}"),
            Err(_) => last = anyhow!("connect timeout"),
        }
    }
    Err(last)
}

fn ws_config() -> WebSocketConfig {
    WebSocketConfig::default()
        .max_message_size(Some(MAX_MESSAGE_BYTES))
        .max_frame_size(Some(MAX_MESSAGE_BYTES))
}

/// Debug builds only: `VAAK_NOSTR_TEST_RELAY_BASE=ws://127.0.0.1:PORT`
/// sends every relay to a local fake at `PORT/<host><path>`, without TLS or
/// the address guard. Release builds never read it.
fn test_relay_base() -> Option<String> {
    if !cfg!(debug_assertions) {
        return None;
    }
    std::env::var("VAAK_NOSTR_TEST_RELAY_BASE")
        .ok()
        .map(|s| s.trim().trim_end_matches('/').to_string())
        .filter(|s| s.starts_with("ws://127.0.0.1:"))
}

/// One REQ: its filter and the authors/kinds an answer must match.
#[derive(Debug, Clone)]
pub struct Query {
    pub filter: Value,
    pub authors: Vec<String>,
    pub kinds: Vec<i64>,
}

impl Query {
    pub fn new(authors: Vec<String>, kinds: Vec<i64>, since: Option<i64>, limit: i64) -> Self {
        let mut filter = json!({"authors": authors, "kinds": kinds, "limit": limit});
        if let Some(since) = since {
            filter["since"] = json!(since);
        }
        Self { filter, authors, kinds }
    }
}

#[derive(Debug, Default)]
pub struct FetchResult {
    pub events: Vec<Event>,
    /// REQs answered with EOSE (the rest timed out or were CLOSED).
    pub completed: usize,
    pub notices: Vec<String>,
}

/// Connect to one relay and run `queries` one after another on the same
/// socket. Returns what arrived before `deadline`. Events are not verified
/// here; the caller checks id and signature before storing.
/// Some relays sit behind CDNs that refuse a handshake with no User-Agent.
fn handshake_request(url: &str) -> Result<tokio_tungstenite::tungstenite::handshake::client::Request> {
    let mut request = url.into_client_request().context("websocket request")?;
    request.headers_mut().insert(
        tokio_tungstenite::tungstenite::http::header::USER_AGENT,
        tokio_tungstenite::tungstenite::http::HeaderValue::from_static(USER_AGENT),
    );
    request.headers_mut().insert(
        tokio_tungstenite::tungstenite::http::header::ACCEPT,
        tokio_tungstenite::tungstenite::http::HeaderValue::from_static("*/*"),
    );
    Ok(request)
}

pub async fn fetch(url: &str, queries: &[Query], deadline: Instant) -> Result<FetchResult> {
    let url = normalize_relay_url(url).ok_or_else(|| anyhow!("unusable relay url"))?;
    if let Some(base) = test_relay_base() {
        let target = format!("{base}/{}", url.trim_start_matches("wss://"));
        let tcp = TcpStream::connect(base.trim_start_matches("ws://").split('/').next().unwrap_or("")).await?;
        let (ws, _) = tokio_tungstenite::client_async_with_config(handshake_request(&target)?, tcp, Some(ws_config())).await?;
        return run_queries(ws, queries, deadline).await;
    }
    let host = host_of(&url).to_string();
    let tcp = timeout_at(deadline, guarded_tcp(&host)).await.map_err(|_| anyhow!("deadline"))??;
    let name = rustls::pki_types::ServerName::try_from(host.clone()).map_err(|_| anyhow!("bad tls name"))?;
    let tls = timeout_at(deadline, tokio_rustls::TlsConnector::from(tls_config()).connect(name, tcp))
        .await
        .map_err(|_| anyhow!("tls timeout"))?
        .context("tls")?;
    let (ws, _) = timeout_at(deadline, tokio_tungstenite::client_async_with_config(handshake_request(&url)?, tls, Some(ws_config())))
        .await
        .map_err(|_| anyhow!("websocket timeout"))?
        .context("websocket")?;
    run_queries(ws, queries, deadline).await
}

async fn run_queries<S>(
    mut ws: tokio_tungstenite::WebSocketStream<S>,
    queries: &[Query],
    deadline: Instant,
) -> Result<FetchResult>
where
    S: AsyncRead + AsyncWrite + Unpin,
{
    let mut out = FetchResult::default();
    for (n, query) in queries.iter().enumerate() {
        let sub = format!("vaak{n}");
        ws.send(Message::text(json!(["REQ", sub, query.filter]).to_string())).await?;
        let wanted_authors: std::collections::HashSet<&str> = query.authors.iter().map(String::as_str).collect();
        let mut done = false;
        while !done {
            let next = match timeout_at(deadline, ws.next()).await {
                Ok(Some(Ok(msg))) => msg,
                Ok(Some(Err(e))) => {
                    let _ = ws.close(None).await;
                    if out.completed > 0 || !out.events.is_empty() {
                        out.notices.push(format!("read error: {e}"));
                        return Ok(out);
                    }
                    return Err(e.into());
                }
                Ok(None) | Err(_) => {
                    let _ = ws.close(None).await;
                    return Ok(out);
                }
            };
            let text = match next {
                Message::Text(t) => t.to_string(),
                Message::Ping(p) => {
                    ws.send(Message::Pong(p)).await?;
                    continue;
                }
                Message::Close(_) => return Ok(out),
                _ => continue,
            };
            let Ok(Value::Array(parts)) = serde_json::from_str::<Value>(&text) else {
                continue;
            };
            match parts.first().and_then(Value::as_str) {
                Some("EVENT") if parts.get(1).and_then(Value::as_str) == Some(sub.as_str()) => {
                    let Some(raw) = parts.get(2) else { continue };
                    let Ok(ev) = serde_json::from_value::<Event>(raw.clone()) else {
                        continue;
                    };
                    if wanted_authors.contains(ev.pubkey.as_str()) && query.kinds.contains(&ev.kind) {
                        out.events.push(ev);
                    }
                }
                Some("EOSE") if parts.get(1).and_then(Value::as_str) == Some(sub.as_str()) => {
                    out.completed += 1;
                    done = true;
                }
                Some("CLOSED") if parts.get(1).and_then(Value::as_str) == Some(sub.as_str()) => {
                    out.notices.push(parts.get(2).and_then(Value::as_str).unwrap_or("closed").chars().take(120).collect());
                    done = true;
                }
                Some("NOTICE") => {
                    out.notices.push(parts.get(1).and_then(Value::as_str).unwrap_or("").chars().take(120).collect());
                }
                _ => {}
            }
        }
        let _ = ws.send(Message::text(json!(["CLOSE", sub]).to_string())).await;
    }
    let _ = ws.close(None).await;
    Ok(out)
}

/// Newest event per (pubkey, kind), for replaceable kinds (0, 3, 10002).
pub fn newest_by_author_kind(events: Vec<Event>) -> HashMap<(String, i64), Event> {
    let mut out: HashMap<(String, i64), Event> = HashMap::new();
    for ev in events {
        let key = (ev.pubkey.clone(), ev.kind);
        let newer = match out.get(&key) {
            Some(have) => ev.created_at > have.created_at || (ev.created_at == have.created_at && ev.id < have.id),
            None => true,
        };
        if newer {
            out.insert(key, ev);
        }
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn handshake_looks_like_http11_websocket_client() {
        assert_eq!(tls_config().alpn_protocols, vec![b"http/1.1".to_vec()]);
        let req = handshake_request("wss://relay.damus.io").unwrap();
        assert_eq!(req.headers()["user-agent"], USER_AGENT);
        assert_eq!(req.headers()["accept"], "*/*");
        assert_eq!(req.headers()["host"], "relay.damus.io");
    }

    #[test]
    fn relay_urls_are_normalized_and_guarded() {
        assert_eq!(normalize_relay_url("wss://Relay.Damus.IO/").as_deref(), Some("wss://relay.damus.io"));
        assert_eq!(normalize_relay_url(" WSS://nos.lol:443 ").as_deref(), Some("wss://nos.lol"));
        assert_eq!(normalize_relay_url("wss://relay.example.com/inbox/").as_deref(), Some("wss://relay.example.com/inbox"));
        for bad in [
            "ws://nos.lol",
            "https://nos.lol",
            "wss://nos.lol:7777",
            "wss://user@nos.lol",
            "wss://127.0.0.1",
            "wss://localhost",
            "wss://relay.local",
            "wss://nos.lol/?x=1",
            "wss://nos.lol/a/../b",
            "wss://singlelabel",
            "wss://bad_host.example",
            "",
        ] {
            assert_eq!(normalize_relay_url(bad), None, "{bad}");
        }
    }

    #[tokio::test]
    async fn guard_refuses_loopback_names() {
        assert!(guarded_tcp("localhost").await.is_err());
    }

    #[test]
    fn newest_replaceable_wins() {
        use super::super::event::tests::signed;
        let old = signed(1, 100, 3, vec![], "");
        let new = signed(1, 200, 3, vec![], "");
        let other_kind = signed(1, 50, 0, vec![], "");
        let map = newest_by_author_kind(vec![new.clone(), old, other_kind.clone()]);
        assert_eq!(map.get(&(new.pubkey.clone(), 3)), Some(&new));
        assert_eq!(map.get(&(new.pubkey.clone(), 0)), Some(&other_kind));
    }
}
