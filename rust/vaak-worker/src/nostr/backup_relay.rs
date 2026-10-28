//! `vaak-worker nostr-relay`: a small NIP-01 relay over `nostr_archive`.
//!
//! Anyone can read; only keys linked to an active VAAK account can write,
//! and only the kinds the archive keeps. It listens on loopback behind
//! Caddy (which terminates TLS and sets X-Forwarded-For) and runs as its own
//! systemd unit, so a busy client can't slow the app's HTTP worker.
//!
//! Live subscriptions see events written through this process. Events the
//! sync pulls in show up on the next REQ.
//!
//! Limits: connections in total and per address, subscriptions and filters
//! per connection, results per filter, message size, and a message rate.
//! NIP-11 is served to a plain GET with `Accept: application/nostr+json`.

use std::collections::{HashMap, HashSet};
use std::net::{IpAddr, SocketAddr};
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use anyhow::{Context, Result};
use futures_util::{SinkExt, StreamExt};
use serde_json::{json, Value};
use tokio::io::AsyncWriteExt;
use tokio::net::{TcpListener, TcpStream};
use tokio::sync::{broadcast, RwLock};
use tokio::time::Instant;
use tokio_postgres::types::ToSql;
use tokio_postgres::Client;
use tokio_tungstenite::tungstenite::protocol::WebSocketConfig;
use tokio_tungstenite::tungstenite::Message;

use super::archive::{self, Stored};
use super::event::{self, Event};
use crate::config::Config;

const MAX_CONNECTIONS: usize = 200;
const MAX_CONNECTIONS_PER_IP: usize = 12;
const MAX_SUBSCRIPTIONS: usize = 20;
const MAX_FILTERS: usize = 10;
const MAX_LIMIT: i64 = 500;
const DEFAULT_LIMIT: i64 = 300;
const MAX_FILTER_VALUES: usize = 500;
const MAX_MESSAGE_BYTES: usize = 512 * 1024;
/// Messages a connection may send per `RATE_WINDOW`.
const RATE_MAX: usize = 60;
const RATE_WINDOW: Duration = Duration::from_secs(10);
const IDLE_TIMEOUT: Duration = Duration::from_secs(600);
const PING_EVERY: Duration = Duration::from_secs(60);
const HEAD_TIMEOUT: Duration = Duration::from_secs(10);
const ALLOWLIST_TTL: Duration = Duration::from_secs(60);
const FUTURE_SLACK_SECS: i64 = 900;

struct Shared {
    database_url: String,
    db: RwLock<Option<Arc<Client>>>,
    allow: RwLock<(HashSet<String>, Option<Instant>)>,
    per_ip: Mutex<HashMap<IpAddr, usize>>,
    total: AtomicUsize,
    live: broadcast::Sender<Arc<Event>>,
    info: String,
}

impl Shared {
    /// The shared Postgres connection, reopened if it dropped. tokio-postgres
    /// pipelines concurrent queries over one connection.
    async fn db(&self) -> Result<Arc<Client>> {
        if let Some(c) = self.db.read().await.as_ref().filter(|c| !c.is_closed()) {
            return Ok(c.clone());
        }
        let mut slot = self.db.write().await;
        if let Some(c) = slot.as_ref().filter(|c| !c.is_closed()) {
            return Ok(c.clone());
        }
        let c = Arc::new(crate::db::connect(&self.database_url).await?);
        *slot = Some(c.clone());
        Ok(c)
    }

    async fn linked(&self) -> Result<HashSet<String>> {
        {
            let a = self.allow.read().await;
            if a.1.is_some_and(|at| at.elapsed() < ALLOWLIST_TTL) {
                return Ok(a.0.clone());
            }
        }
        let keys = archive::linked_keys(&*self.db().await?).await?;
        *self.allow.write().await = (keys.clone(), Some(Instant::now()));
        Ok(keys)
    }
}

/// Holds one slot of the per-address and total connection counts.
struct Slot {
    shared: Arc<Shared>,
    ip: IpAddr,
}

impl Drop for Slot {
    fn drop(&mut self) {
        self.shared.total.fetch_sub(1, Ordering::Relaxed);
        if let Ok(mut m) = self.shared.per_ip.lock() {
            if let Some(n) = m.get_mut(&self.ip) {
                *n = n.saturating_sub(1);
                if *n == 0 {
                    m.remove(&self.ip);
                }
            }
        }
    }
}

fn take_slot(shared: &Arc<Shared>, ip: IpAddr) -> Option<Slot> {
    let mut m = shared.per_ip.lock().ok()?;
    let n = m.entry(ip).or_default();
    if *n >= MAX_CONNECTIONS_PER_IP || shared.total.load(Ordering::Relaxed) >= MAX_CONNECTIONS {
        if *n == 0 {
            m.remove(&ip);
        }
        return None;
    }
    *n += 1;
    shared.total.fetch_add(1, Ordering::Relaxed);
    Some(Slot {
        shared: shared.clone(),
        ip,
    })
}

pub fn info_document() -> Value {
    let env = |k: &str| {
        std::env::var(k)
            .ok()
            .map(|v| v.trim().to_string())
            .filter(|v| !v.is_empty())
    };
    let mut doc = json!({
        "name": env("VAAK_NOSTR_RELAY_NAME").unwrap_or_else(|| "VAAK backup relay".into()),
        "description": env("VAAK_NOSTR_RELAY_DESCRIPTION").unwrap_or_else(|| {
            "Backup of the npubs linked to VAAK accounts: profiles, follow and relay lists, notes, reposts and long-form posts. Anyone can read; only linked npubs can write.".into()
        }),
        "supported_nips": [1, 9, 11],
        "software": "https://github.com/cmdr-nova/VAAK",
        "version": env!("CARGO_PKG_VERSION"),
        "limitation": {
            "max_message_length": MAX_MESSAGE_BYTES,
            "max_subscriptions": MAX_SUBSCRIPTIONS,
            "max_filters": MAX_FILTERS,
            "max_limit": MAX_LIMIT,
            "default_limit": DEFAULT_LIMIT,
            "max_event_tags": 2000,
            "max_content_length": event::MAX_CONTENT_BYTES,
            "auth_required": false,
            "payment_required": false,
            "restricted_writes": true,
        },
        "retention": [{"kinds": archive::KINDS}],
    });
    if let Some(c) = env("VAAK_NOSTR_RELAY_CONTACT") {
        doc["contact"] = json!(c);
    }
    if let Some(pk) = env("VAAK_NOSTR_RELAY_PUBKEY").filter(|p| event::is_hex64(p)) {
        doc["pubkey"] = json!(pk);
    }
    doc
}

pub async fn serve(cfg: &Config, bind: SocketAddr) -> Result<()> {
    if super::sync::disabled_by_env() {
        println!("nostr-relay skipped=disabled");
        return Ok(());
    }
    let (live, _) = broadcast::channel(256);
    let shared = Arc::new(Shared {
        database_url: cfg.database_url.clone(),
        db: RwLock::new(None),
        allow: RwLock::new((HashSet::new(), None)),
        per_ip: Mutex::new(HashMap::new()),
        total: AtomicUsize::new(0),
        live,
        info: info_document().to_string(),
    });
    {
        let db = shared.db().await?;
        if !archive::ready(&*db).await {
            anyhow::bail!(
                "nostr_archive is missing; run the 20261028_nostr_archive migration first"
            );
        }
    }
    let listener = TcpListener::bind(bind)
        .await
        .with_context(|| format!("bind {bind}"))?;
    tracing::info!(%bind, "nostr backup relay listening");
    loop {
        let (stream, peer) = match listener.accept().await {
            Ok(x) => x,
            Err(e) => {
                tracing::warn!(error = %e, "nostr relay accept failed");
                tokio::time::sleep(Duration::from_millis(200)).await;
                continue;
            }
        };
        let shared = shared.clone();
        tokio::spawn(async move {
            if let Err(e) = handle(shared, stream, peer).await {
                tracing::debug!(error = %format!("{e:#}"), "nostr relay connection ended");
            }
        });
    }
}

/// The request head, read with `peek` so the websocket handshake still sees
/// all of it.
async fn peek_head(stream: &TcpStream) -> Result<String> {
    let mut buf = vec![0u8; 8192];
    let until = Instant::now() + HEAD_TIMEOUT;
    loop {
        let n = tokio::time::timeout_at(until, stream.peek(&mut buf))
            .await
            .context("head timeout")??;
        if n == 0 {
            anyhow::bail!("closed");
        }
        if let Some(end) = buf[..n].windows(4).position(|w| w == b"\r\n\r\n") {
            return Ok(String::from_utf8_lossy(&buf[..end]).into_owned());
        }
        if n == buf.len() {
            anyhow::bail!("head too large");
        }
        tokio::time::sleep(Duration::from_millis(20)).await;
    }
}

pub struct Head {
    pub method: String,
    pub path: String,
    pub headers: HashMap<String, String>,
}

pub fn parse_head(raw: &str) -> Head {
    let mut lines = raw.split("\r\n");
    let mut first = lines.next().unwrap_or("").split(' ');
    let method = first.next().unwrap_or("").to_string();
    let path = first.next().unwrap_or("").to_string();
    let mut headers = HashMap::new();
    for line in lines {
        if let Some((k, v)) = line.split_once(':') {
            headers.insert(k.trim().to_ascii_lowercase(), v.trim().to_string());
        }
    }
    Head {
        method,
        path,
        headers,
    }
}

/// The client's address: Caddy's X-Forwarded-For when the peer is loopback
/// (the proxy), else the peer itself.
pub fn client_ip(peer: SocketAddr, head: &Head) -> IpAddr {
    if peer.ip().is_loopback() {
        if let Some(ip) = head
            .headers
            .get("x-forwarded-for")
            .and_then(|v| v.split(',').next())
            .and_then(|v| v.trim().parse::<IpAddr>().ok())
        {
            return ip;
        }
    }
    peer.ip()
}

async fn respond(
    mut stream: TcpStream,
    status: &str,
    content_type: &str,
    body: &str,
) -> Result<()> {
    let head = format!(
        "HTTP/1.1 {status}\r\nContent-Type: {content_type}\r\nContent-Length: {}\r\nAccess-Control-Allow-Origin: *\r\n\
         Access-Control-Allow-Headers: *\r\nAccess-Control-Allow-Methods: GET, OPTIONS\r\nConnection: close\r\n\r\n",
        body.len()
    );
    stream.write_all(head.as_bytes()).await?;
    stream.write_all(body.as_bytes()).await?;
    stream.shutdown().await.ok();
    Ok(())
}

async fn handle(shared: Arc<Shared>, stream: TcpStream, peer: SocketAddr) -> Result<()> {
    let head = parse_head(&peek_head(&stream).await?);
    let upgrade = head
        .headers
        .get("upgrade")
        .is_some_and(|v| v.eq_ignore_ascii_case("websocket"));
    if !upgrade {
        // Drain the head so the response isn't sent over unread bytes.
        let mut sink = vec![0u8; 8192];
        let _ = stream.try_read(&mut sink);
        return match head.method.as_str() {
            "OPTIONS" => respond(stream, "204 No Content", "text/plain", "").await,
            "GET" | "HEAD" if head.path == "/healthz" => {
                respond(stream, "200 OK", "application/json", "{\"ok\":true}").await
            }
            "GET" | "HEAD"
                if head
                    .headers
                    .get("accept")
                    .is_some_and(|a| a.contains("application/nostr+json")) =>
            {
                respond(stream, "200 OK", "application/nostr+json", &shared.info).await
            }
            "GET" | "HEAD" => {
                respond(
                    stream,
                    "200 OK",
                    "text/plain; charset=utf-8",
                    "VAAK backup relay. Connect with a Nostr client.\n",
                )
                .await
            }
            _ => respond(stream, "405 Method Not Allowed", "text/plain", "").await,
        };
    }
    let ip = client_ip(peer, &head);
    let Some(_slot) = take_slot(&shared, ip) else {
        return respond(
            stream,
            "429 Too Many Requests",
            "text/plain",
            "Too many connections.\n",
        )
        .await;
    };
    let config = WebSocketConfig::default()
        .max_message_size(Some(MAX_MESSAGE_BYTES))
        .max_frame_size(Some(MAX_MESSAGE_BYTES));
    let ws = tokio_tungstenite::accept_async_with_config(stream, Some(config))
        .await
        .context("websocket handshake")?;
    session(shared, ws).await
}

/// One REQ filter (NIP-01).
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Filter {
    pub ids: Vec<String>,
    pub authors: Vec<String>,
    pub kinds: Vec<i64>,
    pub since: Option<i64>,
    pub until: Option<i64>,
    pub limit: i64,
    /// Single-letter tag filters (`#e`, `#p`, `#t`, `#d` ...).
    pub tags: Vec<(String, Vec<String>)>,
}

fn strings(v: &Value, what: &str, hex: bool) -> Result<Vec<String>, String> {
    let list = v.as_array().ok_or(format!("{what} must be a list"))?;
    if list.len() > MAX_FILTER_VALUES {
        return Err(format!("too many {what}"));
    }
    let mut out = Vec::with_capacity(list.len());
    for item in list {
        let s = item.as_str().ok_or(format!("{what} must be strings"))?;
        if hex && !event::is_hex64(s) {
            return Err(format!("{what} must be 64-character lowercase hex"));
        }
        out.push(s.to_string());
    }
    Ok(out)
}

impl Filter {
    pub fn parse(v: &Value) -> Result<Self, String> {
        let obj = v.as_object().ok_or("a filter must be an object")?;
        let mut f = Filter {
            limit: DEFAULT_LIMIT,
            ..Default::default()
        };
        for (key, val) in obj {
            match key.as_str() {
                "ids" => f.ids = strings(val, "ids", true)?,
                "authors" => f.authors = strings(val, "authors", true)?,
                "kinds" => {
                    let list = val.as_array().ok_or("kinds must be a list")?;
                    if list.len() > MAX_FILTER_VALUES {
                        return Err("too many kinds".into());
                    }
                    f.kinds = list
                        .iter()
                        .map(|k| {
                            k.as_i64()
                                .filter(|k| (0..=65_535).contains(k))
                                .ok_or("bad kind")
                        })
                        .collect::<Result<_, _>>()?;
                }
                "since" => f.since = Some(val.as_i64().ok_or("since must be a number")?),
                "until" => f.until = Some(val.as_i64().ok_or("until must be a number")?),
                "limit" => {
                    f.limit = val
                        .as_i64()
                        .ok_or("limit must be a number")?
                        .clamp(0, MAX_LIMIT)
                }
                k if k.len() == 2
                    && k.starts_with('#')
                    && k.as_bytes()[1].is_ascii_alphabetic() =>
                {
                    f.tags
                        .push((k[1..].to_string(), strings(val, "tag values", false)?));
                }
                // Unknown fields (search, NIP-50 and the like) are ignored.
                _ => {}
            }
        }
        Ok(f)
    }

    pub fn matches(&self, ev: &Event) -> bool {
        (self.ids.is_empty() || self.ids.contains(&ev.id))
            && (self.authors.is_empty() || self.authors.contains(&ev.pubkey))
            && (self.kinds.is_empty() || self.kinds.contains(&ev.kind))
            && self.since.is_none_or(|s| ev.created_at >= s)
            && self.until.is_none_or(|u| ev.created_at <= u)
            && self.tags.iter().all(|(name, values)| {
                ev.tags.iter().any(|t| {
                    t.first() == Some(name) && t.get(1).is_some_and(|v| values.contains(v))
                })
            })
    }

    /// A filter that can match nothing (an empty list), so no query runs.
    fn is_empty_match(&self) -> bool {
        self.limit == 0 || self.tags.iter().any(|(_, v)| v.is_empty())
    }

    /// SQL and parameters for the stored events this filter matches, newest
    /// first. Older copies of replaceable events are left out.
    pub fn sql(&self) -> (String, Vec<Box<dyn ToSql + Sync + Send>>) {
        let mut sql = String::from("SELECT event_json FROM nostr_archive WHERE NOT superseded");
        let mut params: Vec<Box<dyn ToSql + Sync + Send>> = Vec::new();
        let mut push = |sql: &mut String, cond: &str, p: Box<dyn ToSql + Sync + Send>| {
            params.push(p);
            sql.push_str(&cond.replace('?', &format!("${}", params.len())));
        };
        if !self.ids.is_empty() {
            push(&mut sql, " AND id = ANY(?)", Box::new(self.ids.clone()));
        }
        if !self.authors.is_empty() {
            push(
                &mut sql,
                " AND pubkey = ANY(?)",
                Box::new(self.authors.clone()),
            );
        }
        if !self.kinds.is_empty() {
            push(
                &mut sql,
                " AND kind = ANY(?)",
                Box::new(self.kinds.iter().map(|k| *k as i32).collect::<Vec<i32>>()),
            );
        }
        if let Some(s) = self.since {
            push(&mut sql, " AND created_at >= ?", Box::new(s));
        }
        if let Some(u) = self.until {
            push(&mut sql, " AND created_at <= ?", Box::new(u));
        }
        for (name, values) in &self.tags {
            let wanted: Vec<Value> = values.iter().map(|v| json!([[name, v]])).collect();
            push(&mut sql, " AND tags @> ANY(?::jsonb[])", Box::new(wanted));
        }
        push(
            &mut sql,
            " ORDER BY created_at DESC, id ASC LIMIT ?",
            Box::new(self.limit),
        );
        (sql, params)
    }
}

/// What one client message asks for.
#[derive(Debug, PartialEq)]
pub enum Incoming {
    Event(Box<Event>),
    Req(String, Vec<Filter>),
    Close(String),
    /// Answer with OK false for this id (unparseable event).
    BadEvent(String, String),
    /// Answer with CLOSED for this subscription.
    BadReq(String, String),
    Notice(String),
}

pub fn parse_incoming(text: &str) -> Incoming {
    let Ok(Value::Array(parts)) = serde_json::from_str::<Value>(text) else {
        return Incoming::Notice("error: messages must be JSON arrays".into());
    };
    match parts.first().and_then(Value::as_str) {
        Some("EVENT") => {
            let raw = parts.get(1).cloned().unwrap_or(Value::Null);
            let id = raw
                .get("id")
                .and_then(Value::as_str)
                .unwrap_or("")
                .chars()
                .take(64)
                .collect::<String>();
            match serde_json::from_value::<Event>(raw) {
                Ok(ev) => Incoming::Event(Box::new(ev)),
                Err(_) => Incoming::BadEvent(id, "invalid: not a Nostr event".into()),
            }
        }
        Some("REQ") => {
            let Some(sub) = parts
                .get(1)
                .and_then(Value::as_str)
                .filter(|s| !s.is_empty() && s.len() <= 64)
            else {
                return Incoming::Notice(
                    "error: REQ needs a subscription id of 1 to 64 characters".into(),
                );
            };
            let raw = &parts[2..];
            if raw.is_empty() {
                return Incoming::BadReq(
                    sub.into(),
                    "invalid: REQ needs at least one filter".into(),
                );
            }
            if raw.len() > MAX_FILTERS {
                return Incoming::BadReq(
                    sub.into(),
                    format!("invalid: at most {MAX_FILTERS} filters"),
                );
            }
            match raw.iter().map(Filter::parse).collect::<Result<Vec<_>, _>>() {
                Ok(filters) => Incoming::Req(sub.into(), filters),
                Err(e) => Incoming::BadReq(sub.into(), format!("invalid: {e}")),
            }
        }
        Some("CLOSE") => match parts.get(1).and_then(Value::as_str) {
            Some(sub) => Incoming::Close(sub.into()),
            None => Incoming::Notice("error: CLOSE needs a subscription id".into()),
        },
        Some(other) => Incoming::Notice(format!(
            "error: {} is not supported here",
            other.chars().take(20).collect::<String>()
        )),
        None => Incoming::Notice("error: unknown message".into()),
    }
}

/// OK message for one EVENT: (accepted, message), and whether to hand it
/// to live subscribers.
async fn accept_event(shared: &Shared, ev: &Event) -> (bool, String, bool) {
    let now = chrono::Utc::now().timestamp();
    if let Err(e) = ev.verify() {
        return (false, format!("invalid: {e}"), false);
    }
    if ev.created_at > now + FUTURE_SLACK_SECS {
        return (
            false,
            "invalid: created_at is too far in the future".into(),
            false,
        );
    }
    let linked = match shared.linked().await {
        Ok(l) => l,
        Err(e) => {
            tracing::warn!(error = %format!("{e:#}"), "nostr relay allowlist failed");
            return (false, "error: try again later".into(), false);
        }
    };
    if let Some(why) = archive::refusal(ev, &linked) {
        return (false, why.into(), false);
    }
    let db = match shared.db().await {
        Ok(db) => db,
        Err(_) => return (false, "error: try again later".into(), false),
    };
    match archive::store(&*db, ev, "relay", now).await {
        Ok(Stored::New) => (true, String::new(), true),
        Ok(Stored::Old) => (true, String::new(), false),
        Ok(Stored::Duplicate) => (true, "duplicate: already have it".into(), false),
        Ok(Stored::Deleted) => (false, "blocked: its author deleted it".into(), false),
        Err(e) => {
            tracing::warn!(error = %format!("{e:#}"), "nostr relay store failed");
            (false, "error: could not save it".into(), false)
        }
    }
}

async fn query(shared: &Shared, filters: &[Filter]) -> Result<Vec<String>> {
    let db = shared.db().await?;
    let mut seen = HashSet::new();
    let mut out = Vec::new();
    for f in filters.iter().filter(|f| !f.is_empty_match()) {
        let (sql, params) = f.sql();
        let refs: Vec<&(dyn ToSql + Sync)> = params
            .iter()
            .map(|p| p.as_ref() as &(dyn ToSql + Sync))
            .collect();
        for row in db.query(&sql, &refs).await.context("nostr relay query")? {
            let raw: String = row.get(0);
            let id = raw.get(7..71).unwrap_or("").to_string();
            if seen.insert(id) {
                out.push(raw);
            }
        }
    }
    Ok(out)
}

async fn session<S>(shared: Arc<Shared>, ws: tokio_tungstenite::WebSocketStream<S>) -> Result<()>
where
    S: tokio::io::AsyncRead + tokio::io::AsyncWrite + Unpin,
{
    let (mut tx, mut rx) = ws.split();
    let mut live = shared.live.subscribe();
    let mut subs: HashMap<String, Vec<Filter>> = HashMap::new();
    let mut recent: std::collections::VecDeque<Instant> = std::collections::VecDeque::new();
    let mut ping = tokio::time::interval(PING_EVERY);
    ping.tick().await;
    let mut last_seen = Instant::now();
    loop {
        tokio::select! {
            msg = rx.next() => {
                let Some(msg) = msg else { return Ok(()) };
                let text = match msg? {
                    Message::Text(t) => t.to_string(),
                    Message::Close(_) => return Ok(()),
                    _ => { last_seen = Instant::now(); continue }
                };
                last_seen = Instant::now();
                recent.push_back(last_seen);
                while recent.front().is_some_and(|t| t.elapsed() > RATE_WINDOW) {
                    recent.pop_front();
                }
                if recent.len() > RATE_MAX {
                    let _ = tx.send(Message::text(json!(["NOTICE", "error: slow down"]).to_string())).await;
                    let _ = tx.close().await;
                    return Ok(());
                }
                match parse_incoming(&text) {
                    Incoming::Event(ev) => {
                        let (ok, message, fresh) = accept_event(&shared, &ev).await;
                        tx.send(Message::text(json!(["OK", ev.id, ok, message]).to_string())).await?;
                        if fresh {
                            let _ = shared.live.send(Arc::new(*ev));
                        }
                    }
                    Incoming::BadEvent(id, message) => {
                        tx.send(Message::text(json!(["OK", id, false, message]).to_string())).await?;
                    }
                    Incoming::Req(sub, filters) => {
                        if !subs.contains_key(&sub) && subs.len() >= MAX_SUBSCRIPTIONS {
                            tx.send(Message::text(json!(["CLOSED", sub, format!("error: at most {MAX_SUBSCRIPTIONS} subscriptions")]).to_string())).await?;
                            continue;
                        }
                        match query(&shared, &filters).await {
                            Ok(events) => {
                                let sub_json = serde_json::to_string(&sub)?;
                                for raw in events {
                                    tx.feed(Message::text(format!("[\"EVENT\",{sub_json},{raw}]"))).await?;
                                }
                                tx.send(Message::text(json!(["EOSE", sub]).to_string())).await?;
                                subs.insert(sub, filters);
                            }
                            Err(e) => {
                                tracing::warn!(error = %format!("{e:#}"), "nostr relay REQ failed");
                                tx.send(Message::text(json!(["CLOSED", sub, "error: try again later"]).to_string())).await?;
                            }
                        }
                    }
                    Incoming::BadReq(sub, message) => {
                        tx.send(Message::text(json!(["CLOSED", sub, message]).to_string())).await?;
                    }
                    Incoming::Close(sub) => {
                        subs.remove(&sub);
                    }
                    Incoming::Notice(message) => {
                        tx.send(Message::text(json!(["NOTICE", message]).to_string())).await?;
                    }
                }
            }
            ev = live.recv() => {
                let ev = match ev {
                    Ok(ev) => ev,
                    Err(broadcast::error::RecvError::Lagged(_)) => continue,
                    Err(broadcast::error::RecvError::Closed) => return Ok(()),
                };
                for (sub, filters) in &subs {
                    if filters.iter().any(|f| f.matches(&ev)) {
                        tx.send(Message::text(json!(["EVENT", sub, *ev]).to_string())).await?;
                    }
                }
            }
            _ = ping.tick() => {
                if last_seen.elapsed() > IDLE_TIMEOUT {
                    let _ = tx.close().await;
                    return Ok(());
                }
                tx.send(Message::Ping(Vec::new().into())).await?;
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::super::event::tests::signed;
    use super::*;

    #[test]
    fn filters_parse_cap_and_match() {
        let ev = signed(
            2,
            1_000,
            1,
            vec![
                vec!["t".into(), "nostr".into()],
                vec!["p".into(), "a".repeat(64)],
            ],
            "hi",
        );
        let f = Filter::parse(&json!({"authors": [ev.pubkey], "kinds": [1, 6], "since": 999, "limit": 99999, "#t": ["nostr", "x"], "search": "ignored"})).unwrap();
        assert_eq!(f.limit, MAX_LIMIT);
        assert!(f.matches(&ev));
        assert!(!Filter::parse(&json!({"#t": ["other"]}))
            .unwrap()
            .matches(&ev));
        assert!(!Filter::parse(&json!({"until": 999})).unwrap().matches(&ev));
        assert!(Filter::parse(&json!({"ids": ["short"]})).is_err());
        assert!(Filter::parse(&json!({"kinds": [-1]})).is_err());
        assert!(Filter::parse(&json!([])).is_err());
        assert_eq!(Filter::parse(&json!({})).unwrap().limit, DEFAULT_LIMIT);
        assert!(Filter::parse(&json!({"limit": 0}))
            .unwrap()
            .is_empty_match());
        assert!(Filter::parse(&json!({"#e": []})).unwrap().is_empty_match());
    }

    #[test]
    fn filter_sql_numbers_parameters_in_order() {
        let f = Filter::parse(
            &json!({"authors": ["b".repeat(64)], "kinds": [0], "until": 5, "#p": ["c"]}),
        )
        .unwrap();
        let (sql, params) = f.sql();
        assert_eq!(
            sql,
            "SELECT event_json FROM nostr_archive WHERE NOT superseded AND pubkey = ANY($1) AND kind = ANY($2) \
             AND created_at <= $3 AND tags @> ANY($4::jsonb[]) ORDER BY created_at DESC, id ASC LIMIT $5"
        );
        assert_eq!(params.len(), 5);
    }

    #[test]
    fn client_messages() {
        let ev = signed(3, 1, 1, vec![], "x");
        assert_eq!(
            parse_incoming(&json!(["EVENT", ev]).to_string()),
            Incoming::Event(Box::new(ev.clone()))
        );
        assert!(
            matches!(parse_incoming(r#"["EVENT",{"id":"abc"}]"#), Incoming::BadEvent(id, _) if id == "abc")
        );
        assert!(
            matches!(parse_incoming(r#"["REQ","s1",{"kinds":[1]},{"limit":2}]"#), Incoming::Req(s, f) if s == "s1" && f.len() == 2)
        );
        assert!(matches!(
            parse_incoming(r#"["REQ","s1"]"#),
            Incoming::BadReq(..)
        ));
        let many = format!("[\"REQ\",\"s\",{}]", ["{}"; MAX_FILTERS + 1].join(","));
        assert!(matches!(parse_incoming(&many), Incoming::BadReq(..)));
        assert!(matches!(
            parse_incoming(r#"["REQ","",{}]"#),
            Incoming::Notice(_)
        ));
        assert_eq!(
            parse_incoming(r#"["CLOSE","s1"]"#),
            Incoming::Close("s1".into())
        );
        assert!(matches!(
            parse_incoming(r#"["AUTH","x"]"#),
            Incoming::Notice(_)
        ));
        assert!(matches!(parse_incoming("nope"), Incoming::Notice(_)));
    }

    #[test]
    fn forwarded_address_only_from_the_local_proxy() {
        let head = parse_head("GET / HTTP/1.1\r\nHost: relay.example\r\nX-Forwarded-For: 203.0.113.9, 10.0.0.1\r\nUpgrade: websocket");
        assert_eq!(head.method, "GET");
        assert_eq!(head.headers["upgrade"], "websocket");
        assert_eq!(
            client_ip("127.0.0.1:5000".parse().unwrap(), &head),
            "203.0.113.9".parse::<IpAddr>().unwrap()
        );
        assert_eq!(
            client_ip("198.51.100.4:5000".parse().unwrap(), &head),
            "198.51.100.4".parse::<IpAddr>().unwrap()
        );
    }

    #[test]
    fn info_document_says_writes_are_restricted() {
        let doc = info_document();
        assert_eq!(doc["limitation"]["restricted_writes"], true);
        assert_eq!(doc["supported_nips"], json!([1, 9, 11]));
    }
}
