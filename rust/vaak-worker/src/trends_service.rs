//! Trending service (`vaak-worker trends-service`, deploy/vaak-trends-service.service).
//!
//! Keeps trend data fresh in the background so page requests never compute
//! it. Pages only read finished Redis keys.
//!
//! Legs, each on its own interval:
//!
//! * **On VAAK** (`local`, default 300 s): runs `api/bin/trends-warm.php`,
//!   which recomputes the existing PHP trend caches (tags, links, posts) from
//!   what VAAK stores. Same lists as before, just never inside PHP-FPM. The
//!   child inherits this unit's CPU cap.
//! * **Bluesky** (`bsky`, 300 s): `app.bsky.unspecced.getTrends` on the
//!   public AppView, no auth. Bluesky's own network-wide trending topics.
//! * **Fediverse** (`mastodon`, 600 s): public `/api/v1/trends/tags` and
//!   `/api/v1/trends/statuses` from the largest Mastodon instances
//!   (`VAAK_TRENDS_MASTODON_INSTANCES`), merged by tag name and post URI.
//! * **Nostr** (`nostr`, 300 s): `api.nostr.wine/trending` ids + counts, the
//!   notes themselves fetched from the fallback relays and signature checked.
//!   Only runs while some VAAK user has a linked npub; PHP shows it only to
//!   those users.
//!
//! Network snapshots go to `vaak:trends:net:v1:*`. When one changes,
//! `vaak:trends:data-gen:v1` is bumped; the notification SSE stream turns
//! that into a `trends` event so open tabs refetch the sidebar module.
//! `vaak:trends:service:heartbeat:v1` tells PHP the service is alive, so
//! FPM stops scheduling its own after-request trend recomputes.
//!
//! Turn it off with `VAAK_TRENDS_SERVICE=0` (the unit then idles) or by
//! stopping the unit; PHP falls back to today's behavior by itself.

use std::collections::{BTreeMap, HashMap, HashSet};
use std::path::PathBuf;
use std::process::Stdio;
use std::time::Duration;

use anyhow::{bail, Context, Result};
use serde_json::{json, Value};
use sha2::{Digest as _, Sha256};
use tokio::process::Command;
use tokio::time::Instant;

use crate::config::Config;

pub const KEY_TAGS: &str = "vaak:trends:net:v1:tags";
pub const KEY_STATUSES: &str = "vaak:trends:net:v1:statuses";
pub const KEY_BSKY: &str = "vaak:trends:net:v1:bsky_topics";
pub const KEY_NOSTR: &str = "vaak:trends:net:v1:nostr";
pub const KEY_GEN: &str = "vaak:trends:data-gen:v1";
pub const KEY_HEARTBEAT: &str = "vaak:trends:service:heartbeat:v1";

/// Network snapshots outlive a few missed runs, then vanish (PHP hides the
/// view when nothing is there).
const SNAPSHOT_TTL_SECS: u64 = 3 * 3600;
const HEARTBEAT_TTL_SECS: u64 = 180;
const TICK: Duration = Duration::from_secs(20);
const HTTP_TIMEOUT: Duration = Duration::from_secs(12);
const MAX_BODY_BYTES: usize = 4 * 1024 * 1024;
const LOCAL_TIMEOUT: Duration = Duration::from_secs(240);
const KEEP_TAGS: usize = 40;
const KEEP_STATUSES: usize = 40;
const KEEP_TOPICS: usize = 15;
/// Same as AP_MASTO_TREND_STATUS_WINDOW_HOURS / GRAVITY in PHP.
const STATUS_WINDOW_SECS: i64 = 48 * 3600;
const STATUS_GRAVITY: f64 = 1.5;

/// Largest Mastodon instances with public trends, by active users. Override
/// with a comma list in VAAK_TRENDS_MASTODON_INSTANCES.
const DEFAULT_INSTANCES: &str = "mastodon.social,mstdn.social,mastodon.online,mas.to,mastodon.world,infosec.exchange,hachyderm.io,fosstodon.org,techhub.social,universeodon.com";
const USER_AGENT: &str = "VAAK-trends/1.0 (+https://mkultra.monster/vaak/)";

pub fn enabled() -> bool {
    std::env::var("VAAK_TRENDS_SERVICE").map(|v| v.trim() != "0").unwrap_or(true)
}

fn secs_env(name: &str, default: u64, lo: u64, hi: u64) -> Duration {
    let n = std::env::var(name).ok().and_then(|v| v.trim().parse::<u64>().ok()).unwrap_or(default);
    Duration::from_secs(n.clamp(lo, hi))
}

/// `0` in a leg's interval turns that leg off.
fn leg_interval(name: &str, default: u64) -> Option<Duration> {
    let raw = std::env::var(name).ok().and_then(|v| v.trim().parse::<u64>().ok()).unwrap_or(default);
    if raw == 0 {
        None
    } else {
        Some(secs_env(name, default, 60, 6 * 3600))
    }
}

pub fn instances_from(raw: &str) -> Vec<String> {
    let mut out: Vec<String> = Vec::new();
    for part in raw.split(',') {
        let host = part.trim().trim_start_matches("https://").trim_end_matches('/').to_ascii_lowercase();
        let ok = !host.is_empty()
            && host.len() <= 253
            && host.contains('.')
            && host.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'.' || b == b'-');
        if ok && !out.contains(&host) {
            out.push(host);
        }
    }
    out
}

fn instances() -> Vec<String> {
    instances_from(&std::env::var("VAAK_TRENDS_MASTODON_INSTANCES").unwrap_or_else(|_| DEFAULT_INSTANCES.to_string()))
}

struct Leg {
    name: &'static str,
    every: Option<Duration>,
    last: Option<Instant>,
}

impl Leg {
    fn due(&self, now: Instant) -> bool {
        match (self.every, self.last) {
            (None, _) => false,
            (Some(_), None) => true,
            (Some(every), Some(last)) => now.duration_since(last) >= every,
        }
    }
}

pub async fn run(cfg: &Config) -> Result<()> {
    if !enabled() {
        tracing::info!("trends-service: VAAK_TRENDS_SERVICE=0, idling");
        loop {
            tokio::time::sleep(Duration::from_secs(3600)).await;
        }
    }
    let http = reqwest::Client::builder()
        .timeout(HTTP_TIMEOUT)
        .connect_timeout(Duration::from_secs(6))
        .user_agent(USER_AGENT)
        .build()
        .context("trends http client")?;
    let mut legs = [
        Leg { name: "local", every: leg_interval("VAAK_TRENDS_LOCAL_SECS", 300), last: None },
        Leg { name: "bsky", every: leg_interval("VAAK_TRENDS_BSKY_SECS", 300), last: None },
        Leg { name: "mastodon", every: leg_interval("VAAK_TRENDS_MASTODON_SECS", 600), last: None },
        Leg { name: "nostr", every: leg_interval("VAAK_TRENDS_NOSTR_SECS", 300), last: None },
    ];
    tracing::info!(instances = ?instances(), "trends-service starting");
    loop {
        let mut redis = match crate::redis_util::connect(&cfg.redis_url).await {
            Ok(r) => r,
            Err(e) => {
                tracing::warn!(error = %format!("{e:#}"), "trends-service: redis unavailable");
                tokio::time::sleep(TICK).await;
                continue;
            }
        };
        let _: Result<(), _> = redis::cmd("SET")
            .arg(KEY_HEARTBEAT)
            .arg(chrono::Utc::now().timestamp())
            .arg("EX")
            .arg(HEARTBEAT_TTL_SECS)
            .query_async(&mut redis)
            .await;
        for leg in legs.iter_mut() {
            let now = Instant::now();
            if !leg.due(now) {
                continue;
            }
            leg.last = Some(now);
            let started = Instant::now();
            let res = match leg.name {
                "local" => run_local().await.map(|_| false),
                "bsky" => bsky_leg(cfg, &http, &mut redis).await,
                "mastodon" => mastodon_leg(&http, &mut redis).await,
                _ => nostr_leg(cfg, &http, &mut redis).await,
            };
            match res {
                Ok(changed) => {
                    tracing::info!(leg = leg.name, changed, ms = started.elapsed().as_millis() as u64, "trends-service leg done");
                    if changed {
                        let _: Result<i64, _> = redis::cmd("INCR").arg(KEY_GEN).query_async(&mut redis).await;
                    }
                }
                Err(e) => tracing::warn!(leg = leg.name, error = %format!("{e:#}"), "trends-service leg failed"),
            }
        }
        tokio::time::sleep(TICK).await;
    }
}

// ---------- On VAAK (existing PHP caches, recomputed off the request path)

async fn run_local() -> Result<()> {
    let php = PathBuf::from(std::env::var("VAAK_PHP_BIN").unwrap_or_else(|_| "/usr/bin/php".into()));
    let api = PathBuf::from(std::env::var("VAAK_API_ROOT").unwrap_or_else(|_| "/srv/mkultra/html/api".into()));
    let script = api.join("bin").join("trends-warm.php");
    if !script.is_file() {
        bail!("missing {}", script.display());
    }
    let mut cmd = Command::new(&php);
    cmd.arg(&script).stdout(Stdio::piped()).stderr(Stdio::piped()).kill_on_drop(true);
    if let Some(dir) = api.parent() {
        cmd.current_dir(dir);
    }
    if std::env::var_os("AP_DB_DSN").is_none() {
        cmd.env("AP_DB_DSN", "pgsql:dbname=novalandia");
    }
    let out = tokio::time::timeout(LOCAL_TIMEOUT, cmd.output())
        .await
        .context("trends-warm.php timed out")?
        .context("spawn trends-warm.php")?;
    let stderr = String::from_utf8_lossy(&out.stderr).trim().chars().take(600).collect::<String>();
    if !out.status.success() {
        bail!("trends-warm.php exit={} {stderr}", out.status.code().unwrap_or(-1));
    }
    if !stderr.is_empty() {
        tracing::warn!(%stderr, "trends-warm.php stderr");
    }
    Ok(())
}

// ---------- HTTP + snapshot helpers

async fn get_json(http: &reqwest::Client, url: &str) -> Result<Value> {
    let res = http.get(url).header("accept", "application/json").send().await.with_context(|| format!("GET {url}"))?;
    let status = res.status();
    if !status.is_success() {
        bail!("GET {url}: HTTP {status}");
    }
    if res.content_length().is_some_and(|n| n as usize > MAX_BODY_BYTES) {
        bail!("GET {url}: body too large");
    }
    let bytes = res.bytes().await.with_context(|| format!("read {url}"))?;
    if bytes.len() > MAX_BODY_BYTES {
        bail!("GET {url}: body too large");
    }
    serde_json::from_slice(&bytes).with_context(|| format!("parse {url}"))
}

fn items_hash(items: &[Value]) -> String {
    hex::encode(Sha256::digest(serde_json::to_string(items).unwrap_or_default().as_bytes()))
}

/// Write a snapshot; returns whether its items differ from the stored ones.
async fn store(redis: &mut redis::aio::MultiplexedConnection, key: &str, items: Vec<Value>, sources: Vec<String>) -> Result<bool> {
    let hash = items_hash(&items);
    let old = crate::redis_util::json_get(redis, key).await.ok().flatten();
    let same = old.as_ref().and_then(|v| v.get("hash")).and_then(Value::as_str) == Some(hash.as_str());
    let payload = json!({
        "generated_at": chrono::Utc::now().timestamp(),
        "hash": hash,
        "sources": sources,
        "items": items,
    });
    crate::redis_util::json_set(redis, key, &payload, SNAPSHOT_TTL_SECS).await?;
    Ok(!same)
}

fn s<'a>(v: &'a Value, k: &str) -> &'a str {
    v.get(k).and_then(Value::as_str).unwrap_or("")
}

fn n(v: &Value, k: &str) -> i64 {
    match v.get(k) {
        Some(Value::Number(x)) => x.as_i64().unwrap_or(0),
        Some(Value::String(x)) => x.trim().parse().unwrap_or(0),
        _ => 0,
    }
    .max(0)
}

fn https(url: &str) -> String {
    let u = url.trim();
    if u.starts_with("https://") && u.len() <= 2048 && !u.contains(char::is_whitespace) {
        u.to_string()
    } else {
        String::new()
    }
}

// ---------- Bluesky

/// `getTrends` -> slim topic rows. Relative `link`s are made absolute on bsky.app.
pub fn normalize_bsky_trends(body: &Value) -> Vec<Value> {
    let mut out = Vec::new();
    let mut seen = HashSet::new();
    for t in body.get("trends").and_then(Value::as_array).into_iter().flatten() {
        let topic = s(t, "topic").trim();
        let display = s(t, "displayName").trim();
        let display = if display.is_empty() { topic } else { display };
        if display.is_empty() || display.chars().count() > 120 || !seen.insert(display.to_lowercase()) {
            continue;
        }
        let link = s(t, "link").trim();
        let link = if link.starts_with('/') { format!("https://bsky.app{link}") } else { https(link) };
        out.push(json!({
            "topic": topic,
            "display_name": display,
            "post_count": n(t, "postCount"),
            "started_at": s(t, "startedAt"),
            "status": s(t, "status"),
            "category": s(t, "category"),
            "link": link,
        }));
        if out.len() >= KEEP_TOPICS {
            break;
        }
    }
    out
}

async fn bsky_leg(cfg: &Config, http: &reqwest::Client, redis: &mut redis::aio::MultiplexedConnection) -> Result<bool> {
    let base = cfg.bsky_public_api.trim_end_matches('/');
    let base = if base.starts_with("https://") { base } else { "https://public.api.bsky.app" };
    let body = get_json(http, &format!("{base}/xrpc/app.bsky.unspecced.getTrends?limit={KEEP_TOPICS}")).await?;
    let items = normalize_bsky_trends(&body);
    if items.is_empty() {
        bail!("getTrends returned no topics");
    }
    store(redis, KEY_BSKY, items, vec!["bsky.app".into()]).await
}

// ---------- Fediverse (Mastodon instance trends)

fn digit_only(name: &str) -> bool {
    !name.is_empty() && name.chars().all(|c| c.is_numeric() || c == '_')
}

/// Merge `/api/v1/trends/tags` lists by lowercased name. History is summed
/// per day; the score is the two newest days' accounts, nudged up for each extra
/// instance that lists the tag (wider spread = more trending).
pub fn merge_tags(lists: &[(String, Value)]) -> Vec<Value> {
    struct Acc {
        name: String,
        days: BTreeMap<i64, (i64, i64)>,
        hosts: Vec<String>,
    }
    let mut acc: HashMap<String, Acc> = HashMap::new();
    let mut order: Vec<String> = Vec::new();
    for (host, list) in lists {
        for tag in list.as_array().into_iter().flatten() {
            let name = s(tag, "name").trim().trim_start_matches('#').to_string();
            if name.is_empty() || name.chars().count() > 100 || digit_only(&name) {
                continue;
            }
            let key = name.to_lowercase();
            let entry = acc.entry(key.clone()).or_insert_with(|| {
                order.push(key.clone());
                Acc { name: name.clone(), days: BTreeMap::new(), hosts: Vec::new() }
            });
            if entry.hosts.contains(host) {
                continue;
            }
            entry.hosts.push(host.clone());
            for h in tag.get("history").and_then(Value::as_array).into_iter().flatten() {
                let day = n(h, "day");
                if day <= 0 {
                    continue;
                }
                let d = entry.days.entry(day).or_insert((0, 0));
                d.0 += n(h, "uses");
                d.1 += n(h, "accounts");
            }
        }
    }
    let mut scored: Vec<(f64, usize, Value)> = order
        .iter()
        .enumerate()
        .filter_map(|(i, key)| {
            let a = acc.get(key)?;
            let newest: Vec<(i64, (i64, i64))> = a.days.iter().rev().take(7).map(|(d, v)| (*d, *v)).collect();
            // People, not posts: one account spamming a tag counts once a day.
            let recent: i64 = newest.iter().take(2).map(|(_, v)| v.1).sum();
            let score = recent as f64 * (1.0 + 0.25 * (a.hosts.len().saturating_sub(1)) as f64);
            let history: Vec<Value> = newest
                .iter()
                .map(|(d, (uses, accounts))| json!({"day": d.to_string(), "uses": uses.to_string(), "accounts": accounts.to_string()}))
                .collect();
            Some((
                score,
                i,
                json!({
                    "name": a.name,
                    "url": "",
                    "history": history,
                    "vaak_trend_hosts": a.hosts,
                    "vaak_trend_source": "mastodon",
                }),
            ))
        })
        .collect();
    scored.sort_by(|a, b| b.0.partial_cmp(&a.0).unwrap_or(std::cmp::Ordering::Equal).then(a.1.cmp(&b.1)));
    scored.into_iter().take(KEEP_TAGS).map(|(_, _, v)| v).collect()
}

fn slim_media(list: Option<&Value>) -> Vec<Value> {
    list.and_then(Value::as_array)
        .into_iter()
        .flatten()
        .take(4)
        .map(|m| {
            json!({
                "type": s(m, "type"),
                "preview_url": https(s(m, "preview_url")),
                "url": https(s(m, "url")),
                "description": s(m, "description").chars().take(500).collect::<String>(),
            })
        })
        .collect()
}

/// Trim a remote Mastodon status to what the sidebar and its filters use.
/// `id` becomes the canonical URI (the instance's numeric id means nothing
/// here) and a local `acct` gains the instance host.
pub fn slim_status(st: &Value, host: &str) -> Option<Value> {
    let uri = https(s(st, "uri"));
    if uri.is_empty() || s(st, "visibility") != "public" || st.get("reblog").is_some_and(Value::is_object) {
        return None;
    }
    let acc = st.get("account")?;
    let acct_raw = s(acc, "acct").trim();
    if acct_raw.is_empty() {
        return None;
    }
    let acct = if acct_raw.contains('@') { acct_raw.to_string() } else { format!("{acct_raw}@{host}") };
    let account_url = https(s(acc, "url"));
    Some(json!({
        "id": uri,
        "uri": uri,
        "url": https(s(st, "url")),
        "created_at": s(st, "created_at"),
        "content": s(st, "content").chars().take(4000).collect::<String>(),
        "spoiler_text": s(st, "spoiler_text").chars().take(300).collect::<String>(),
        "sensitive": st.get("sensitive").and_then(Value::as_bool).unwrap_or(false),
        "language": s(st, "language"),
        "visibility": "public",
        "account": {
            "id": account_url,
            "acct": acct,
            "username": s(acc, "username"),
            "display_name": s(acc, "display_name").chars().take(120).collect::<String>(),
            "url": account_url,
            "uri": https(s(acc, "uri")),
            "avatar": https(s(acc, "avatar")),
            "bot": acc.get("bot").and_then(Value::as_bool).unwrap_or(false),
        },
        "media_attachments": slim_media(st.get("media_attachments")),
        "favourites_count": n(st, "favourites_count"),
        "reblogs_count": n(st, "reblogs_count"),
        "replies_count": n(st, "replies_count"),
        "vaak_trend_source": "mastodon",
        "vaak_trend_hosts": [host],
    }))
}

fn status_score(st: &Value, now: i64) -> Option<f64> {
    let eng = n(st, "favourites_count") + 2 * (n(st, "reblogs_count") + n(st, "replies_count"));
    let ts = chrono::DateTime::parse_from_rfc3339(s(st, "created_at")).map(|d| d.timestamp()).unwrap_or(now);
    let age = (now - ts).max(0);
    if age > STATUS_WINDOW_SECS {
        return None;
    }
    Some((eng as f64 + 1.0) / ((age as f64 / 3600.0) + 2.0).powf(STATUS_GRAVITY))
}

/// Merge `/api/v1/trends/statuses` lists by URI. Counts are each instance's
/// view of the post, so the highest one wins; posts older than the 48 h
/// window are dropped, the rest ordered by the PHP trending score.
pub fn merge_statuses(lists: &[(String, Value)], now: i64) -> Vec<Value> {
    let mut by_uri: HashMap<String, Value> = HashMap::new();
    let mut order: Vec<String> = Vec::new();
    for (host, list) in lists {
        for raw in list.as_array().into_iter().flatten() {
            let Some(st) = slim_status(raw, host) else { continue };
            let uri = s(&st, "uri").to_string();
            match by_uri.get_mut(&uri) {
                Some(have) => {
                    for k in ["favourites_count", "reblogs_count", "replies_count"] {
                        let v = n(have, k).max(n(&st, k));
                        have[k] = json!(v);
                    }
                    if let Some(hosts) = have.get_mut("vaak_trend_hosts").and_then(Value::as_array_mut) {
                        if !hosts.iter().any(|h| h.as_str() == Some(host)) {
                            hosts.push(json!(host));
                        }
                    }
                }
                None => {
                    order.push(uri.clone());
                    by_uri.insert(uri, st);
                }
            }
        }
    }
    let mut scored: Vec<(f64, usize, Value)> = order
        .into_iter()
        .enumerate()
        .filter_map(|(i, uri)| {
            let st = by_uri.remove(&uri)?;
            Some((status_score(&st, now)?, i, st))
        })
        .collect();
    scored.sort_by(|a, b| b.0.partial_cmp(&a.0).unwrap_or(std::cmp::Ordering::Equal).then(a.1.cmp(&b.1)));
    // At most two per author, like the VAAK list.
    let mut per_author: HashMap<String, usize> = HashMap::new();
    let mut out = Vec::new();
    for (_, _, st) in scored {
        let who = st.get("account").map(|a| s(a, "acct").to_lowercase()).unwrap_or_default();
        let c = per_author.entry(who).or_default();
        if *c >= 2 {
            continue;
        }
        *c += 1;
        out.push(st);
        if out.len() >= KEEP_STATUSES {
            break;
        }
    }
    out
}

async fn mastodon_leg(http: &reqwest::Client, redis: &mut redis::aio::MultiplexedConnection) -> Result<bool> {
    let hosts = instances();
    let fetches = hosts.iter().map(|host| async move {
        let tags = get_json(http, &format!("https://{host}/api/v1/trends/tags?limit=20")).await;
        let statuses = get_json(http, &format!("https://{host}/api/v1/trends/statuses?limit=40")).await;
        (host.clone(), tags, statuses)
    });
    let results = futures_util::future::join_all(fetches).await;
    let mut tag_lists = Vec::new();
    let mut status_lists = Vec::new();
    for (host, tags, statuses) in results {
        match tags {
            Ok(v) if v.is_array() => tag_lists.push((host.clone(), v)),
            Ok(_) => tracing::warn!(%host, "trends/tags: not a list"),
            Err(e) => tracing::warn!(%host, error = %format!("{e:#}"), "trends/tags failed"),
        }
        match statuses {
            Ok(v) if v.is_array() => status_lists.push((host, v)),
            Ok(_) => tracing::warn!(%host, "trends/statuses: not a list"),
            Err(e) => tracing::warn!(%host, error = %format!("{e:#}"), "trends/statuses failed"),
        }
    }
    if tag_lists.is_empty() && status_lists.is_empty() {
        bail!("no Mastodon instance answered");
    }
    let mut changed = false;
    if !tag_lists.is_empty() {
        let sources = tag_lists.iter().map(|(h, _)| h.clone()).collect();
        changed |= store(redis, KEY_TAGS, merge_tags(&tag_lists), sources).await?;
    }
    if !status_lists.is_empty() {
        let sources = status_lists.iter().map(|(h, _)| h.clone()).collect();
        let now = chrono::Utc::now().timestamp();
        changed |= store(redis, KEY_STATUSES, merge_statuses(&status_lists, now), sources).await?;
    }
    Ok(changed)
}

// ---------- Nostr (nostr.wine global trending)

/// `[{event_id, reactions, replies, reposts, ...}]` -> (id, likes, reposts, replies).
pub fn parse_nostr_wine(body: &Value) -> Vec<(String, i64, i64, i64)> {
    let mut seen = HashSet::new();
    body.as_array()
        .into_iter()
        .flatten()
        .filter_map(|row| {
            let id = s(row, "event_id").trim().to_ascii_lowercase();
            if !crate::nostr::event::is_hex64(&id) || !seen.insert(id.clone()) {
                return None;
            }
            Some((id, n(row, "reactions"), n(row, "reposts"), n(row, "replies")))
        })
        .collect()
}

async fn nostr_wanted(cfg: &Config) -> Result<bool> {
    if std::env::var("VAAK_NOSTR").map(|v| v.trim() == "0").unwrap_or(false) {
        return Ok(false);
    }
    let db = crate::db::connect(&cfg.database_url).await?;
    let row = db
        .query_one(
            "SELECT CASE WHEN to_regclass('public.nostr_links') IS NULL THEN 0
                         ELSE (SELECT count(*)::bigint FROM nostr_links WHERE show_in_home) END",
            &[],
        )
        .await?;
    Ok(row.get::<_, i64>(0) > 0)
}

async fn nostr_leg(cfg: &Config, http: &reqwest::Client, redis: &mut redis::aio::MultiplexedConnection) -> Result<bool> {
    if !nostr_wanted(cfg).await? {
        return Ok(false);
    }
    let body = get_json(http, "https://api.nostr.wine/trending?order=reactions&hours=6&limit=30").await?;
    let rows = parse_nostr_wine(&body);
    if rows.is_empty() {
        bail!("nostr.wine returned no notes");
    }
    let db = crate::db::connect(&cfg.database_url).await?;
    let statuses = crate::nostr::global::trending_cards(&db, &rows, chrono::Utc::now().timestamp()).await?;
    if statuses.is_empty() {
        bail!("no trending Nostr note could be fetched from relays");
    }
    store(redis, KEY_NOSTR, statuses, vec!["nostr.wine".into()]).await
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn instances_parse_and_dedupe() {
        assert_eq!(
            instances_from(" Mastodon.Social, https://mas.to/ ,mastodon.social,bad host, ,localhost"),
            vec!["mastodon.social".to_string(), "mas.to".to_string()]
        );
    }

    #[test]
    fn bsky_topics_slim_and_absolute_links() {
        let body = json!({"trends": [
            {"topic": "election", "displayName": "Election", "link": "/profile/trending.bsky.app/feed/1", "startedAt": "2026-11-04T01:00:00Z", "postCount": 1234, "status": "hot", "actors": []},
            {"topic": "election", "displayName": "election", "link": "/x", "postCount": 1},
            {"topic": "", "displayName": "", "link": "/y"},
            {"topic": "x", "displayName": "X", "link": "javascript:alert(1)", "postCount": "7"}
        ]});
        let out = normalize_bsky_trends(&body);
        assert_eq!(out.len(), 2, "duplicate and empty topics dropped");
        assert_eq!(out[0]["link"], "https://bsky.app/profile/trending.bsky.app/feed/1");
        assert_eq!(out[0]["post_count"], 1234);
        assert_eq!(out[1]["link"], "", "non-https link dropped");
        assert_eq!(out[1]["post_count"], 7);
    }

    fn tag(name: &str, days: &[(i64, i64, i64)]) -> Value {
        json!({"name": name, "history": days.iter().map(|(d, u, a)| json!({"day": d.to_string(), "uses": u.to_string(), "accounts": a.to_string()})).collect::<Vec<_>>()})
    }

    #[test]
    fn tags_merge_by_name_and_reward_spread() {
        let a = ("a.social".to_string(), json!([tag("Cats", &[(200, 10, 8), (100, 4, 4)]), tag("solo", &[(200, 30, 12)]), tag("2026", &[(200, 99, 99)])]));
        let b = ("b.social".to_string(), json!([tag("cats", &[(200, 5, 5)]), tag("cats", &[(200, 50, 50)])]));
        let out = merge_tags(&[a, b]);
        let names: Vec<&str> = out.iter().map(|t| t["name"].as_str().unwrap()).collect();
        assert_eq!(names, vec!["Cats", "solo"], "digit-only tag dropped, second listing per host ignored");
        assert_eq!(out[0]["vaak_trend_hosts"], json!(["a.social", "b.social"]));
        assert_eq!(out[0]["history"][0], json!({"day": "200", "uses": "15", "accounts": "13"}), "newest day first, summed");
    }

    fn status(uri: &str, acct: &str, created: &str, favs: i64) -> Value {
        json!({"id": "1", "uri": uri, "url": uri, "visibility": "public", "created_at": created, "content": "<p>hi</p>",
               "account": {"acct": acct, "username": acct, "url": "https://x/@a"}, "favourites_count": favs, "reblogs_count": 0, "replies_count": 0, "media_attachments": []})
    }

    #[test]
    fn statuses_merge_keep_max_counts_and_window() {
        let now = chrono::DateTime::parse_from_rfc3339("2026-11-04T12:00:00Z").unwrap().timestamp();
        let a = ("a.social".to_string(), json!([
            status("https://a.social/1", "alice", "2026-11-04T10:00:00Z", 5),
            status("https://a.social/old", "alice", "2026-11-01T10:00:00Z", 900),
            {"uri": "https://a.social/2", "visibility": "unlisted", "account": {"acct": "x"}}
        ]));
        let b = ("b.social".to_string(), json!([status("https://a.social/1", "alice@a.social", "2026-11-04T10:00:00Z", 40)]));
        let out = merge_statuses(&[a, b], now);
        assert_eq!(out.len(), 1, "old and unlisted posts dropped");
        assert_eq!(out[0]["id"], "https://a.social/1", "id is the URI");
        assert_eq!(out[0]["favourites_count"], 40, "highest count wins");
        assert_eq!(out[0]["account"]["acct"], "alice@a.social", "local acct gains its host");
        assert_eq!(out[0]["vaak_trend_hosts"], json!(["a.social", "b.social"]));
    }

    #[test]
    fn statuses_cap_two_per_author() {
        let now = chrono::DateTime::parse_from_rfc3339("2026-11-04T12:00:00Z").unwrap().timestamp();
        let list = json!([
            status("https://a.social/1", "alice", "2026-11-04T11:00:00Z", 9),
            status("https://a.social/2", "alice", "2026-11-04T11:00:00Z", 8),
            status("https://a.social/3", "alice", "2026-11-04T11:00:00Z", 7),
        ]);
        assert_eq!(merge_statuses(&[("a.social".into(), list)], now).len(), 2);
    }

    #[test]
    fn nostr_wine_rows_validated() {
        let id = "a".repeat(64);
        let body = json!([{"event_id": id, "reactions": 8, "replies": 5, "reposts": 6}, {"event_id": "nope"}, {"event_id": id}]);
        assert_eq!(parse_nostr_wine(&body), vec![(id, 8, 6, 5)]);
    }

    #[test]
    fn leg_due_logic() {
        let now = Instant::now();
        assert!(Leg { name: "x", every: Some(Duration::from_secs(60)), last: None }.due(now));
        assert!(!Leg { name: "x", every: None, last: None }.due(now));
        assert!(!Leg { name: "x", every: Some(Duration::from_secs(60)), last: Some(now) }.due(now));
    }
}
