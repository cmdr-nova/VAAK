//! `vaak-worker nostr-sync`: refresh linked users' follow lists, their
//! follows' profiles and write relays, and the follows' recent notes.
//!
//! Read-only on Nostr: this never publishes an event. One run is bounded by
//! a deadline (`--budget-secs`), a relay cap and per-relay timeouts, and the
//! systemd timer (deploy/vaak-nostr-sync.timer) runs it as a oneshot so two
//! runs never overlap.
//!
//! Per run:
//!  1. Links due (`next_sync_at`): newest kind 3 / 0 / 10002 of the linked
//!     key from the index relays and the key's own write relays. A kind 3
//!     replaces that user's `nostr_follows` only if it is newer than the one
//!     stored. Rechecked hourly.
//!  2. Followed keys without fresh metadata (daily): kind 0 and kind 10002.
//!  3. Notes: kind 1 from each followed key's write relays (NIP-65 outbox),
//!     since that key's last good sync (at most 48 h back). Every event's id
//!     and signature are checked before it is stored.
//!  4. Clean-up: notes older than the retention window, notes and profiles
//!     nobody follows any more, follows of removed links. Notes a VAAK user
//!     boosted, quoted or bookmarked stay (and their authors' profiles).

use std::collections::{BTreeMap, HashMap, HashSet};
use std::time::Duration;

use anyhow::{Context, Result};
use futures_util::StreamExt;
use serde_json::Value;
use tokio::time::Instant;
use tokio_postgres::Client;

use super::event::{self, Event};
use super::relay::{self, Query};
use crate::config::Config;

const LINK_REFRESH_SECS: i64 = 3600;
const META_REFRESH_SECS: i64 = 86_400;
const NOTES_MAX_BACK_SECS: i64 = 48 * 3600;
/// Overlap with the previous sync so a late relay write is not missed.
const NOTES_OVERLAP_SECS: i64 = 900;
const FUTURE_SLACK_SECS: i64 = 900;
const AUTHORS_PER_QUERY: usize = 200;
const LINKS_PER_RUN: i64 = 20;
const META_AUTHORS_PER_RUN: i64 = 1500;
const RELAY_TIMEOUT: Duration = Duration::from_secs(25);

const DEFAULT_INDEX_RELAYS: &str = "wss://purplepag.es,wss://relay.damus.io,wss://relay.primal.net,wss://nos.lol";
const DEFAULT_FALLBACK_RELAYS: &str = "wss://relay.damus.io,wss://relay.primal.net,wss://nos.lol";

#[derive(Debug, Clone)]
pub struct Settings {
    /// Relays that keep kind 0 / 3 / 10002 for most keys.
    pub index_relays: Vec<String>,
    /// Where notes are read for keys with no usable kind 10002.
    pub fallback_relays: Vec<String>,
    pub retention_days: i64,
    pub max_relays: usize,
    pub concurrency: usize,
    pub budget: Duration,
}

fn relay_list_env(name: &str, default: &str) -> Vec<String> {
    let raw = std::env::var(name).unwrap_or_else(|_| default.to_string());
    let mut out: Vec<String> = Vec::new();
    for url in raw.split(',').filter_map(relay::normalize_relay_url) {
        if !out.contains(&url) {
            out.push(url);
        }
    }
    out
}

impl Settings {
    pub fn from_env(budget_secs: u64) -> Self {
        let num = |name: &str, default: i64, lo: i64, hi: i64| {
            std::env::var(name).ok().and_then(|v| v.trim().parse::<i64>().ok()).unwrap_or(default).clamp(lo, hi)
        };
        Self {
            index_relays: relay_list_env("VAAK_NOSTR_INDEX_RELAYS", DEFAULT_INDEX_RELAYS),
            fallback_relays: relay_list_env("VAAK_NOSTR_FALLBACK_RELAYS", DEFAULT_FALLBACK_RELAYS),
            retention_days: num("VAAK_NOSTR_RETENTION_DAYS", 30, 1, 90),
            max_relays: num("VAAK_NOSTR_MAX_RELAYS", 24, 1, 60) as usize,
            concurrency: num("VAAK_NOSTR_RELAY_CONCURRENCY", 6, 1, 16) as usize,
            budget: Duration::from_secs(budget_secs.clamp(10, 600)),
        }
    }
}

pub fn disabled_by_env() -> bool {
    std::env::var("VAAK_NOSTR").map(|v| v.trim() == "0").unwrap_or(false)
}

pub async fn tables_ready(db: &Client) -> bool {
    db.query_one("SELECT to_regclass('nostr_links') IS NOT NULL AND to_regclass('nostr_events') IS NOT NULL", &[])
        .await
        .map(|r| r.get::<_, bool>(0))
        .unwrap_or(false)
}

/// Which relays to ask for whose notes. Each author goes to at most
/// `per_author` of their own write relays, preferring relays many authors
/// share, so a few hundred follows collapse onto a few dozen connections.
/// Relays past `max_relays` (least shared first) are dropped and their
/// authors fall back to `fallback`.
pub fn plan_relays(
    authors: &[(String, Vec<String>)],
    fallback: &[String],
    max_relays: usize,
    per_author: usize,
) -> BTreeMap<String, Vec<String>> {
    let mut popularity: HashMap<&str, usize> = HashMap::new();
    for (_, relays) in authors {
        for r in relays {
            *popularity.entry(r.as_str()).or_default() += 1;
        }
    }
    let mut ranked: Vec<(&str, usize)> = popularity.iter().map(|(k, v)| (*k, *v)).collect();
    ranked.sort_by(|a, b| b.1.cmp(&a.1).then(a.0.cmp(b.0)));
    let fallback_n = fallback.len().min(max_relays);
    let room = max_relays.saturating_sub(fallback_n);
    let mut allowed: HashSet<&str> = ranked.iter().take(room).map(|(r, _)| *r).collect();
    allowed.extend(fallback.iter().take(fallback_n).map(String::as_str));
    let mut plan: BTreeMap<String, Vec<String>> = BTreeMap::new();
    for (author, relays) in authors {
        let mut picks: Vec<&str> = relays.iter().map(String::as_str).filter(|r| allowed.contains(r)).collect();
        picks.sort_by(|a, b| popularity[b].cmp(&popularity[a]).then(a.cmp(b)));
        picks.truncate(per_author);
        if picks.is_empty() {
            picks = fallback.iter().take(fallback_n.min(2)).map(String::as_str).collect();
        }
        for r in picks {
            plan.entry(r.to_string()).or_default().push(author.clone());
        }
    }
    plan
}

/// Run `queries` against several relays at once, `concurrency` at a time.
/// Returns (relay, result) pairs; a failed relay is an Err.
async fn fan_out(
    jobs: Vec<(String, Vec<Query>)>,
    concurrency: usize,
    run_deadline: Instant,
) -> Vec<(String, Result<relay::FetchResult>)> {
    futures_util::stream::iter(jobs.into_iter().map(|(url, queries)| async move {
        let deadline = (Instant::now() + RELAY_TIMEOUT).min(run_deadline);
        let res = relay::fetch(&url, &queries, deadline).await;
        match &res {
            Err(e) => tracing::warn!(relay = %url, "nostr relay failed: {e:#}"),
            Ok(r) if r.completed == 0 => tracing::warn!(
                relay = %url,
                events = r.events.len(),
                "nostr relay never finished a query: {}",
                r.notices.join(" | ")
            ),
            Ok(_) => {}
        }
        (url, res)
    }))
    .buffer_unordered(concurrency.max(1))
    .collect()
    .await
}

/// Verified events only; one copy per id.
fn verified(events: impl IntoIterator<Item = Event>, now: i64, rejected: &mut usize) -> Vec<Event> {
    let mut seen = HashSet::new();
    let mut out = Vec::new();
    for ev in events {
        if !seen.insert(ev.id.clone()) {
            continue;
        }
        if ev.created_at > now + FUTURE_SLACK_SECS || ev.verify().is_err() {
            *rejected += 1;
            continue;
        }
        out.push(ev);
    }
    out
}

fn chunk_queries(authors: &[String], kinds: &[i64], since: Option<i64>, per_author_limit: i64) -> Vec<Query> {
    authors
        .chunks(AUTHORS_PER_QUERY)
        .map(|chunk| {
            let limit = (chunk.len() as i64 * per_author_limit).clamp(50, 1000);
            Query::new(chunk.to_vec(), kinds.to_vec(), since, limit)
        })
        .collect()
}

async fn upsert_meta(db: &Client, pubkey: &str, profile: Option<&Event>, relays: Option<&Event>, fetched_at: i64) -> Result<()> {
    db.execute(
        "INSERT INTO nostr_profiles (pubkey, meta_fetched_at) VALUES ($1, $2)
         ON CONFLICT (pubkey) DO UPDATE SET meta_fetched_at = EXCLUDED.meta_fetched_at",
        &[&pubkey, &fetched_at],
    )
    .await
    .context("nostr profile touch")?;
    if let Some(ev) = profile {
        let p = event::parse_profile(&ev.content);
        db.execute(
            "UPDATE nostr_profiles SET name = $2, display_name = $3, picture = $4, nip05 = $5, profile_at = $6
             WHERE pubkey = $1 AND profile_at < $6",
            &[&pubkey, &p.name, &p.display_name, &p.picture, &p.nip05, &ev.created_at],
        )
        .await
        .context("nostr profile update")?;
    }
    if let Some(ev) = relays {
        let list = serde_json::to_value(ev.write_relays()).unwrap_or(Value::Array(Vec::new()));
        db.execute(
            "UPDATE nostr_profiles SET write_relays = $2, relays_at = $3 WHERE pubkey = $1 AND relays_at < $3",
            &[&pubkey, &list, &ev.created_at],
        )
        .await
        .context("nostr relays update")?;
    }
    Ok(())
}

struct LinkRow {
    owner: i64,
    pubkey: String,
    follow_list_at: i64,
    failures: i32,
}

async fn sync_links(db: &mut Client, s: &Settings, now: i64, deadline: Instant, log: &mut Vec<String>) -> Result<()> {
    let rows = db
        .query(
            "SELECT l.owner_user_id, l.pubkey, l.follow_list_at, l.failures
             FROM nostr_links l JOIN ap_users u ON u.id = l.owner_user_id
             WHERE l.next_sync_at <= $1 AND u.disabled_at IS NULL
             ORDER BY l.next_sync_at, l.owner_user_id LIMIT $2",
            &[&now, &LINKS_PER_RUN],
        )
        .await
        .context("nostr due links")?;
    let links: Vec<LinkRow> = rows
        .iter()
        .map(|r| LinkRow { owner: r.get(0), pubkey: r.get(1), follow_list_at: r.get(2), failures: r.get(3) })
        .filter(|l| event::is_hex64(&l.pubkey))
        .collect();
    if links.is_empty() {
        return Ok(());
    }
    let keys: Vec<String> = links.iter().map(|l| l.pubkey.clone()).collect::<HashSet<_>>().into_iter().collect();
    let mut relays = s.index_relays.clone();
    let known = db
        .query("SELECT write_relays FROM nostr_profiles WHERE pubkey = ANY($1)", &[&keys])
        .await
        .context("nostr link relays")?;
    for row in known {
        let list: Value = row.get(0);
        for r in list.as_array().into_iter().flatten().filter_map(Value::as_str).filter_map(relay::normalize_relay_url) {
            if !relays.contains(&r) {
                relays.push(r);
            }
        }
    }
    relays.truncate(8);
    let queries = vec![Query::new(keys.clone(), vec![0, 3, 10002], None, (keys.len() as i64 * 6).max(20))];
    let asked = relays.len();
    let jobs = relays.into_iter().map(|r| (r, queries.clone())).collect();
    let mut all = Vec::new();
    let mut answered = 0usize;
    for (_, res) in fan_out(jobs, s.concurrency, deadline).await {
        if let Ok(res) = res {
            answered += usize::from(res.completed > 0);
            all.extend(res.events);
        }
    }
    let mut rejected = 0usize;
    let newest = relay::newest_by_author_kind(verified(all, now, &mut rejected));
    for link in &links {
        upsert_meta(
            db,
            &link.pubkey,
            newest.get(&(link.pubkey.clone(), 0)),
            newest.get(&(link.pubkey.clone(), 10002)),
            now,
        )
        .await?;
        let mut error = String::new();
        let mut follows: Option<(Vec<String>, i64)> = None;
        match newest.get(&(link.pubkey.clone(), 3)) {
            Some(k3) if k3.created_at >= link.follow_list_at => follows = Some((k3.follow_list(), k3.created_at)),
            Some(_) => {}
            None if answered == 0 => error = "no relay answered".into(),
            None if link.follow_list_at == 0 => error = "no follow list found on public relays yet".into(),
            None => {}
        }
        let tx = db.transaction().await?;
        if let Some((list, at)) = &follows {
            tx.execute("DELETE FROM nostr_follows WHERE owner_user_id = $1", &[&link.owner]).await?;
            tx.execute(
                "INSERT INTO nostr_follows (owner_user_id, pubkey) SELECT $1, unnest($2::text[]) ON CONFLICT DO NOTHING",
                &[&link.owner, list],
            )
            .await?;
            tx.execute(
                "UPDATE nostr_links SET follow_count = $2, follow_list_at = $3 WHERE owner_user_id = $1",
                &[&link.owner, &(list.len() as i32), at],
            )
            .await?;
        }
        let failed = answered == 0;
        let failures = if failed { link.failures.saturating_add(1) } else { 0 };
        let next = if failed { now + (300i64 << failures.clamp(0, 4)).min(LINK_REFRESH_SECS) } else { now + LINK_REFRESH_SECS };
        tx.execute(
            "UPDATE nostr_links SET next_sync_at = $2, failures = $3, last_error = $4 WHERE owner_user_id = $1",
            &[&link.owner, &next, &failures, &error],
        )
        .await?;
        tx.commit().await?;
        log.push(format!(
            "link owner={} follows={} answered={answered}/{asked}{}",
            link.owner,
            follows.map(|(l, _)| l.len().to_string()).unwrap_or_else(|| "kept".into()),
            if error.is_empty() { String::new() } else { format!(" error=\"{error}\"") }
        ));
    }
    Ok(())
}

/// Keys whose kind 0 never turned up are asked again after this long
/// instead of waiting a whole day.
const META_MISS_RETRY_SECS: i64 = 2 * 3600;
/// Most relays a missing profile is chased on (its own write relays and the
/// relays its notes came from).
const META_CHASE_RELAYS: usize = 8;

/// Each key's own relays: its NIP-65 write relays plus the relays VAAK
/// stored its notes from, most notes first.
async fn own_relays(db: &Client, keys: &[String]) -> Result<Vec<(String, Vec<String>)>> {
    let rows = db
        .query(
            "SELECT k.pubkey,
                    COALESCE((SELECT write_relays FROM nostr_profiles p WHERE p.pubkey = k.pubkey), '[]'::jsonb),
                    COALESCE((SELECT array_agg(relay ORDER BY n DESC) FROM (
                        SELECT relay, COUNT(*) AS n FROM nostr_events e
                        WHERE e.pubkey = k.pubkey AND e.relay <> '' GROUP BY relay) r), '{}')
             FROM unnest($1::text[]) AS k(pubkey)",
            &[&keys],
        )
        .await
        .context("nostr own relays")?;
    Ok(rows
        .iter()
        .map(|r| {
            let listed: Value = r.get(1);
            let seen: Vec<String> = r.get(2);
            let mut relays: Vec<String> = Vec::new();
            let urls = listed.as_array().into_iter().flatten().filter_map(Value::as_str).map(str::to_string).chain(seen);
            for url in urls.filter_map(|u| relay::normalize_relay_url(&u)) {
                if !relays.contains(&url) {
                    relays.push(url);
                }
            }
            (r.get(0), relays)
        })
        .collect())
}

/// When a key is next due: tomorrow if its profile was found, sooner if not
/// (a relay timing out or a profile kept off the index relays).
fn meta_stamp(found: bool, now: i64) -> i64 {
    if found {
        now
    } else {
        now - META_REFRESH_SECS + META_MISS_RETRY_SECS
    }
}

async fn sync_followee_meta(db: &Client, s: &Settings, now: i64, deadline: Instant) -> Result<usize> {
    let stale = now - META_REFRESH_SECS;
    let rows = db
        .query(
            "SELECT f.pubkey FROM nostr_follows f
             JOIN nostr_links l ON l.owner_user_id = f.owner_user_id AND l.show_in_home
             LEFT JOIN nostr_profiles p ON p.pubkey = f.pubkey
             WHERE p.pubkey IS NULL OR p.meta_fetched_at < $1
             GROUP BY f.pubkey
             ORDER BY MIN(COALESCE(p.meta_fetched_at, 0)), f.pubkey
             LIMIT $2",
            &[&stale, &META_AUTHORS_PER_RUN],
        )
        .await
        .context("nostr stale profiles")?;
    let keys: Vec<String> = rows.iter().map(|r| r.get::<_, String>(0)).filter(|k| event::is_hex64(k)).collect();
    if keys.is_empty() {
        return Ok(0);
    }
    let queries = chunk_queries(&keys, &[0, 10002], None, 3);
    let jobs = s.index_relays.iter().map(|r| (r.clone(), queries.clone())).collect();
    let mut all = Vec::new();
    for (_, res) in fan_out(jobs, s.concurrency, deadline).await {
        if let Ok(res) = res {
            all.extend(res.events);
        }
    }
    let mut rejected = 0usize;
    let mut newest = relay::newest_by_author_kind(verified(all, now, &mut rejected));

    // Profiles the index relays do not carry: ask each key's own relays.
    let missing: Vec<String> = keys.iter().filter(|k| !newest.contains_key(&((*k).clone(), 0))).cloned().collect();
    if !missing.is_empty() && Instant::now() < deadline {
        let own = own_relays(db, &missing).await?;
        let plan = plan_relays(&own, &[], META_CHASE_RELAYS, 2);
        let jobs = plan
            .iter()
            .filter(|(url, _)| !s.index_relays.contains(url))
            .map(|(url, ks)| (url.clone(), chunk_queries(ks, &[0, 10002], None, 3)))
            .collect();
        let mut more = Vec::new();
        for (_, res) in fan_out(jobs, s.concurrency, deadline).await {
            if let Ok(res) = res {
                more.extend(res.events);
            }
        }
        for (key, ev) in relay::newest_by_author_kind(verified(more, now, &mut rejected)) {
            match newest.get(&key) {
                Some(have) if have.created_at >= ev.created_at => {}
                _ => {
                    newest.insert(key, ev);
                }
            }
        }
    }
    for key in &keys {
        let profile = newest.get(&(key.clone(), 0));
        upsert_meta(db, key, profile, newest.get(&(key.clone(), 10002)), meta_stamp(profile.is_some(), now)).await?;
    }
    Ok(keys.len())
}

struct NotesReport {
    authors: usize,
    relays: usize,
    relays_ok: usize,
    stored: u64,
    rejected: usize,
}

async fn sync_notes(db: &mut Client, s: &Settings, now: i64, deadline: Instant) -> Result<NotesReport> {
    let rows = db
        .query(
            "SELECT DISTINCT f.pubkey, COALESCE(p.write_relays, '[]'::jsonb), COALESCE(p.notes_synced_at, 0)
             FROM nostr_follows f
             JOIN nostr_links l ON l.owner_user_id = f.owner_user_id AND l.show_in_home
             JOIN ap_users u ON u.id = l.owner_user_id AND u.disabled_at IS NULL
             LEFT JOIN nostr_profiles p ON p.pubkey = f.pubkey",
            &[],
        )
        .await
        .context("nostr followed keys")?;
    let mut authors: Vec<(String, Vec<String>)> = Vec::new();
    let mut synced_at: HashMap<String, i64> = HashMap::new();
    for row in rows {
        let key: String = row.get(0);
        if !event::is_hex64(&key) {
            continue;
        }
        let list: Value = row.get(1);
        let relays = list.as_array().into_iter().flatten().filter_map(Value::as_str).filter_map(relay::normalize_relay_url).collect();
        synced_at.insert(key.clone(), row.get(2));
        authors.push((key, relays));
    }
    let mut report = NotesReport { authors: authors.len(), relays: 0, relays_ok: 0, stored: 0, rejected: 0 };
    if authors.is_empty() {
        return Ok(report);
    }
    let floor = now - NOTES_MAX_BACK_SECS;
    let plan = plan_relays(&authors, &s.fallback_relays, s.max_relays, 2);
    report.relays = plan.len();
    let jobs: Vec<(String, Vec<Query>)> = plan
        .iter()
        .map(|(url, keys)| {
            let since = keys
                .iter()
                .map(|k| synced_at.get(k).copied().unwrap_or(0))
                .min()
                .map(|t| (t - NOTES_OVERLAP_SECS).max(floor))
                .unwrap_or(floor);
            (url.clone(), chunk_queries(keys, &[1], Some(since), 5))
        })
        .collect();
    let mut events = Vec::new();
    let mut good_authors: HashSet<String> = HashSet::new();
    for (url, res) in fan_out(jobs, s.concurrency, deadline).await {
        match res {
            Ok(res) => {
                if res.completed > 0 {
                    report.relays_ok += 1;
                    good_authors.extend(plan.get(&url).into_iter().flatten().cloned());
                }
                events.extend(res.events.into_iter().map(|e| (url.clone(), e)));
            }
            Err(e) => tracing::debug!(relay = %url, error = %format!("{e:#}"), "nostr relay failed"),
        }
    }
    let keep_after = now - s.retention_days * 86_400;
    let mut relay_of: HashMap<String, String> = HashMap::new();
    let mut plain = Vec::with_capacity(events.len());
    for (url, ev) in events {
        relay_of.entry(ev.id.clone()).or_insert(url);
        plain.push(ev);
    }
    let good = verified(plain, now, &mut report.rejected);
    let tx = db.transaction().await?;
    let insert = tx
        .prepare(
            "INSERT INTO nostr_events (id, pubkey, kind, created_at, content, tags, is_reply, reply_pubkeys, reply_to, relay, ingested_at)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11) ON CONFLICT (id) DO NOTHING",
        )
        .await?;
    for ev in good.iter().filter(|e| e.kind == 1 && e.created_at >= keep_after) {
        let (is_reply, parent, pks) = ev.reply_info();
        let tags = serde_json::to_value(&ev.tags).unwrap_or(Value::Array(Vec::new()));
        let kind = ev.kind as i32;
        let relay = relay_of.get(&ev.id).cloned().unwrap_or_default();
        report.stored += tx
            .execute(&insert, &[&ev.id, &ev.pubkey, &kind, &ev.created_at, &ev.content, &tags, &is_reply, &pks, &parent, &relay, &now])
            .await?;
    }
    let good_list: Vec<String> = good_authors.into_iter().collect();
    if !good_list.is_empty() {
        tx.execute(
            "INSERT INTO nostr_profiles (pubkey, notes_synced_at) SELECT unnest($1::text[]), $2
             ON CONFLICT (pubkey) DO UPDATE SET notes_synced_at = EXCLUDED.notes_synced_at",
            &[&good_list, &now],
        )
        .await?;
    }
    tx.commit().await?;
    Ok(report)
}

/// Notes a VAAK user boosted, quoted (both their quote and the quoted
/// note) or bookmarked stay past retention and unfollows, like a boosted
/// remote post outlives media clean-up. `e` is the nostr_events row.
const KEPT_NOTE_SQL: &str = "EXISTS (SELECT 1 FROM masto_reblogs r WHERE r.status_id = 'nostr:' || e.id)
    OR EXISTS (SELECT 1 FROM masto_bookmarks b WHERE b.status_id = 'nostr:' || e.id)
    OR EXISTS (SELECT 1 FROM nostr_shares s WHERE s.event_id = e.id OR s.note_id = e.id)";

async fn shares_ready(db: &Client) -> bool {
    db.query_one("SELECT to_regclass('nostr_shares') IS NOT NULL", &[])
        .await
        .map(|r| r.get::<_, bool>(0))
        .unwrap_or(false)
}

async fn cleanup(db: &Client, s: &Settings, now: i64) -> Result<u64> {
    let cutoff = now - s.retention_days * 86_400;
    // Until the nostr_shares migration has run nothing can be shared yet.
    let keep = if shares_ready(db).await { format!(" AND NOT ({KEPT_NOTE_SQL})") } else { String::new() };
    let mut n = db.execute(&format!("DELETE FROM nostr_events e WHERE e.created_at < $1{keep}"), &[&cutoff]).await?;
    n += db
        .execute("DELETE FROM nostr_follows f WHERE NOT EXISTS (SELECT 1 FROM nostr_links l WHERE l.owner_user_id = f.owner_user_id)", &[])
        .await?;
    n += db
        .execute(
            &format!("DELETE FROM nostr_events e WHERE NOT EXISTS (SELECT 1 FROM nostr_follows f WHERE f.pubkey = e.pubkey){keep}"),
            &[],
        )
        .await?;
    // Shares of a removed link go with it; their VAAK boosts stay as rows
    // until the user undoes them, so keep the notes those rows show.
    n += db
        .execute(
            "DELETE FROM nostr_shares s WHERE NOT EXISTS (SELECT 1 FROM nostr_links l WHERE l.owner_user_id = s.owner_user_id)",
            &[],
        )
        .await
        .unwrap_or(0);
    // Added by a later migration; skip quietly until it has run.
    n += db
        .execute(
            "DELETE FROM nostr_reactions r WHERE r.created_at < $1
               OR NOT EXISTS (SELECT 1 FROM nostr_links l WHERE l.owner_user_id = r.owner_user_id)",
            &[&cutoff],
        )
        .await
        .unwrap_or(0);
    n += db
        .execute(
            "DELETE FROM nostr_profiles p WHERE NOT EXISTS (SELECT 1 FROM nostr_follows f WHERE f.pubkey = p.pubkey)
               AND NOT EXISTS (SELECT 1 FROM nostr_links l WHERE l.pubkey = p.pubkey)
               AND NOT EXISTS (SELECT 1 FROM nostr_events e WHERE e.pubkey = p.pubkey)",
            &[],
        )
        .await?;
    Ok(n)
}

pub async fn run_once(cfg: &Config, budget_secs: u64) -> Result<String> {
    if disabled_by_env() {
        return Ok("nostr-sync skipped=disabled".into());
    }
    let started = Instant::now();
    let s = Settings::from_env(budget_secs);
    let deadline = started + s.budget;
    let mut db = crate::db::connect(&cfg.database_url).await?;
    if !tables_ready(&db).await {
        return Ok("nostr-sync skipped=no-tables".into());
    }
    let now = chrono::Utc::now().timestamp();
    let mut log = Vec::new();
    sync_links(&mut db, &s, now, deadline, &mut log).await?;
    let meta = sync_followee_meta(&db, &s, now, deadline).await?;
    let notes = sync_notes(&mut db, &s, now, deadline).await?;
    db.execute(
        "UPDATE nostr_links SET synced_at = $1 WHERE show_in_home AND follow_list_at > 0",
        &[&now],
    )
    .await?;
    let cleaned = cleanup(&db, &s, now).await?;
    Ok(format!(
        "nostr-sync {}meta={meta} authors={} relays={}/{} stored={} rejected={} cleaned={cleaned} ms={}",
        if log.is_empty() { String::new() } else { format!("{} ", log.join(" ")) },
        notes.authors,
        notes.relays_ok,
        notes.relays,
        notes.stored,
        notes.rejected,
        started.elapsed().as_millis()
    ))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn a(name: &str, relays: &[&str]) -> (String, Vec<String>) {
        (name.to_string(), relays.iter().map(|r| r.to_string()).collect())
    }

    #[test]
    fn plan_prefers_shared_relays_and_caps_count() {
        let fallback = vec!["wss://fb1.example".to_string(), "wss://fb2.example".to_string()];
        let authors = vec![
            a("k1", &["wss://big.example", "wss://solo1.example"]),
            a("k2", &["wss://big.example", "wss://mid.example"]),
            a("k3", &["wss://mid.example", "wss://big.example", "wss://solo3.example"]),
            a("k4", &[]),
            a("k5", &["wss://solo5.example"]),
        ];
        let plan = plan_relays(&authors, &fallback, 4, 2);
        // Room for 2 shared relays (4 minus 2 fallback): big and mid.
        assert_eq!(plan.get("wss://big.example").unwrap(), &vec!["k1", "k2", "k3"]);
        assert_eq!(plan.get("wss://mid.example").unwrap(), &vec!["k2", "k3"]);
        assert!(!plan.contains_key("wss://solo1.example"));
        // No relays, or only relays cut by the cap: fallback.
        assert_eq!(plan.get("wss://fb1.example").unwrap(), &vec!["k4", "k5"]);
        assert!(plan.len() <= 4);
    }

    #[test]
    fn verified_drops_forgeries_duplicates_and_future_posts() {
        use super::super::event::tests::signed;
        let now = 1_800_000_000;
        let good = signed(3, now - 10, 1, vec![], "ok");
        let mut forged = signed(3, now - 10, 1, vec![], "forged");
        forged.content = "changed".into();
        let future = signed(3, now + FUTURE_SLACK_SECS + 5, 1, vec![], "later");
        let mut rejected = 0;
        let out = verified(vec![good.clone(), good.clone(), forged, future], now, &mut rejected);
        assert_eq!(out, vec![good]);
        assert_eq!(rejected, 2);
    }

    #[test]
    fn a_missing_profile_is_retried_within_hours() {
        let now = 1_800_000_000;
        assert_eq!(meta_stamp(true, now), now);
        let due = meta_stamp(false, now) + META_REFRESH_SECS;
        assert_eq!(due - now, META_MISS_RETRY_SECS);
    }

    #[test]
    fn queries_are_chunked() {
        let keys: Vec<String> = (0..450).map(|i| format!("{i:064x}")).collect();
        let qs = chunk_queries(&keys, &[1], Some(5), 5);
        assert_eq!(qs.len(), 3);
        assert_eq!(qs[0].authors.len(), AUTHORS_PER_QUERY);
        assert_eq!(qs[0].filter["limit"], 1000);
        assert_eq!(qs[2].filter["since"], 5);
    }
}
