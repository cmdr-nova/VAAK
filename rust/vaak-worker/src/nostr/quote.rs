//! Quoted Nostr notes VAAK has not stored yet.
//!
//! A quote only names the note it quotes (a NIP-18 `q` tag, or a bare
//! `nostr:note1…` / `nostr:nevent1…` reference in the text). The quoted note
//! arrives on its own only when a followed key happens to be its author, so
//! most quotes used to paint a plain "Quoted note" link instead of a card.
//!
//! Each sync run looks at recently stored quotes, remembers them in
//! `nostr_quote_links`, and asks for the missing notes by id on the `q`
//! tag's relay hint, the relay the quote came from and the fallback relays.
//! A note that never turns up is retried a few times and then left alone.
//! The link rows also keep a quoted note past retention and get its author a
//! profile, the way a reposted note is kept.

use std::collections::{HashMap, HashSet};

use anyhow::{Context, Result};
use serde_json::Value;
use tokio::time::Instant;
use tokio_postgres::Client;

use super::relay::{self, Query};
use super::sync::{self, Settings};

/// How far back quotes are looked at, and how many are read per run.
const WINDOW_SECS: i64 = 7 * 86_400;
const SCAN_ROWS: i64 = 400;
/// Most missing notes asked for per run, and relays asked for them.
const LOOKUPS_PER_RUN: usize = 40;
const RELAYS: usize = 4;
/// A note that didn't turn up is asked again after this long, and left
/// alone after this many tries (deleted, or on relays VAAK never reads).
const RETRY_SECS: i64 = 3600;
const MAX_TRIES: i32 = 5;

/// A note a stored quote points at stays, whoever wrote it.
pub const KEPT_SQL: &str =
    "EXISTS (SELECT 1 FROM nostr_quote_links q JOIN nostr_events n ON n.id = q.note_id WHERE q.quoted_id = e.id)";

pub async fn ready(db: &Client) -> bool {
    db.query_one("SELECT to_regclass('nostr_quote_links') IS NOT NULL", &[])
        .await
        .map(|r| r.get::<_, bool>(0))
        .unwrap_or(false)
}

#[derive(Default)]
pub struct Report {
    /// Quoted notes missing from storage that this run asked for.
    pub wanted: usize,
    /// How many of them came back.
    pub found: u64,
}

impl Report {
    pub fn summary(&self) -> String {
        if self.wanted == 0 {
            String::new()
        } else {
            format!("quotes={}/{} ", self.found, self.wanted)
        }
    }
}

/// One missing note: where to look for it, and who quoted it.
struct Want {
    relays: Vec<String>,
    quoters: HashSet<String>,
}

/// Fetch and store quoted notes Home has no copy of. Returns the report and
/// the authors of the quotes whose target arrived, so their Home pages are
/// rebuilt with the card in place of the link.
pub async fn warm(db: &Client, s: &Settings, now: i64, deadline: Instant) -> Result<(Report, HashSet<String>)> {
    let mut report = Report::default();
    if !ready(db).await {
        return Ok((report, HashSet::new()));
    }
    let rows = db
        .query(
            "SELECT e.id, e.pubkey, e.relay, e.content, e.tags
             FROM nostr_events e
             WHERE e.kind = 1 AND e.created_at >= $1
               AND (e.tags::text LIKE '%\"q\"%' OR e.content LIKE '%nostr:note1%' OR e.content LIKE '%nostr:nevent1%')
             ORDER BY e.created_at DESC
             LIMIT $2",
            &[&(now - WINDOW_SECS), &SCAN_ROWS],
        )
        .await
        .context("nostr quotes to resolve")?;
    let mut links: Vec<(String, String, String)> = Vec::new();
    let mut quoted_by: HashMap<String, (HashSet<String>, Vec<String>)> = HashMap::new();
    for row in &rows {
        let note: String = row.get(0);
        let author: String = row.get(1);
        let from: String = row.get(2);
        let content: String = row.get(3);
        let tags: Value = row.get(4);
        let Some(target) = super::render::quoted_note(&content, &tags) else { continue };
        if target == note {
            continue;
        }
        let hint = super::render::quote_hint(&tags);
        links.push((note, target.clone(), hint.clone()));
        let entry = quoted_by.entry(target).or_default();
        entry.0.insert(author);
        for url in [relay::normalize_relay_url(&hint), relay::normalize_relay_url(&from)].into_iter().flatten() {
            if !entry.1.contains(&url) && !s.skip.contains(&url) {
                entry.1.push(url);
            }
        }
    }
    if links.is_empty() {
        return Ok((report, HashSet::new()));
    }
    remember(db, &links).await?;

    let targets: Vec<String> = quoted_by.keys().cloned().collect();
    let stored: HashSet<String> = db
        .query("SELECT id FROM nostr_events WHERE id = ANY($1)", &[&targets])
        .await
        .context("stored quoted notes")?
        .iter()
        .map(|r| r.get::<_, String>(0))
        .collect();
    let have: Vec<String> = stored.iter().cloned().collect();
    db.execute("UPDATE nostr_quote_links SET found = TRUE, tries = 0 WHERE quoted_id = ANY($1) AND NOT found", &[&have])
        .await
        .context("mark quoted notes found")?;

    let missing: Vec<String> = targets.iter().filter(|id| !stored.contains(*id)).cloned().collect();
    if missing.is_empty() || Instant::now() >= deadline {
        return Ok((report, HashSet::new()));
    }
    let resting = resting(db, &missing, now).await?;
    let mut wanted: Vec<String> = missing.into_iter().filter(|id| !resting.contains(id)).collect();
    wanted.truncate(LOOKUPS_PER_RUN);
    if wanted.is_empty() {
        return Ok((report, HashSet::new()));
    }
    report.wanted = wanted.len();

    let mut ask: Vec<String> = Vec::new();
    for id in &wanted {
        for url in quoted_by.get(id).map(|w| w.1.clone()).unwrap_or_default() {
            if ask.len() < RELAYS.saturating_sub(1).max(1) && !ask.contains(&url) {
                ask.push(url);
            }
        }
    }
    for url in &s.fallback_relays {
        if ask.len() >= RELAYS {
            break;
        }
        if !ask.contains(url) {
            ask.push(url.clone());
        }
    }
    let query = vec![Query::by_ids(wanted.clone(), vec![1])];
    let jobs = ask.into_iter().map(|u| (u, query.clone())).collect();
    let mut found = Vec::new();
    let mut relay_of: HashMap<String, String> = HashMap::new();
    for (url, res) in sync::fan_out(db, s, jobs, deadline).await {
        if let Ok(res) = res {
            for ev in res.events {
                relay_of.entry(ev.id.clone()).or_insert_with(|| url.clone());
                found.push(ev);
            }
        }
    }
    let mut rejected = 0usize;
    let mut arrived: HashSet<String> = HashSet::new();
    let mut quoters: HashSet<String> = HashSet::new();
    for ev in sync::verified(found, now, &mut rejected) {
        if ev.kind != 1 || !wanted.contains(&ev.id) {
            continue;
        }
        let relay = relay_of.get(&ev.id).cloned().unwrap_or_default();
        report.found += sync::store_note(db, &ev, &relay, now).await?;
        if arrived.insert(ev.id.clone()) {
            if let Some(w) = quoted_by.get(&ev.id) {
                quoters.extend(w.0.iter().cloned());
            }
        }
    }
    let came: Vec<String> = arrived.iter().cloned().collect();
    let gone: Vec<String> = wanted.into_iter().filter(|id| !arrived.contains(id)).collect();
    db.execute("UPDATE nostr_quote_links SET found = TRUE, tries = 0 WHERE quoted_id = ANY($1)", &[&came])
        .await
        .context("mark fetched quoted notes")?;
    db.execute(
        "UPDATE nostr_quote_links SET tries = tries + 1, tried_at = $2 WHERE quoted_id = ANY($1) AND NOT found",
        &[&gone, &now],
    )
    .await
    .context("count quoted note misses")?;
    Ok((report, quoters))
}

/// Write down which note each quote points at, so the quoted note is kept
/// and its author gets a profile even when nobody follows them.
async fn remember(db: &Client, links: &[(String, String, String)]) -> Result<()> {
    let notes: Vec<String> = links.iter().map(|l| l.0.clone()).collect();
    let targets: Vec<String> = links.iter().map(|l| l.1.clone()).collect();
    let hints: Vec<String> = links.iter().map(|l| l.2.clone()).collect();
    db.execute(
        "INSERT INTO nostr_quote_links (note_id, quoted_id, hint)
         SELECT * FROM unnest($1::text[], $2::text[], $3::text[])
         ON CONFLICT (note_id) DO UPDATE SET quoted_id = EXCLUDED.quoted_id, hint = EXCLUDED.hint
         WHERE nostr_quote_links.quoted_id <> EXCLUDED.quoted_id",
        &[&notes, &targets, &hints],
    )
    .await
    .context("remember nostr quotes")?;
    Ok(())
}

/// Missing notes not due for another try: asked recently, or given up on.
async fn resting(db: &Client, ids: &[String], now: i64) -> Result<HashSet<String>> {
    let rows = db
        .query(
            "SELECT quoted_id FROM nostr_quote_links WHERE quoted_id = ANY($1)
             GROUP BY quoted_id HAVING MIN(tries) >= $2 OR MAX(tried_at) > $3",
            &[&ids, &MAX_TRIES, &(now - RETRY_SECS)],
        )
        .await
        .context("nostr quote lookups tried")?;
    Ok(rows.iter().map(|r| r.get::<_, String>(0)).collect())
}

/// Link rows whose quoting note is gone.
pub async fn cleanup(db: &Client) -> u64 {
    db.execute("DELETE FROM nostr_quote_links q WHERE NOT EXISTS (SELECT 1 FROM nostr_events e WHERE e.id = q.note_id)", &[])
        .await
        .unwrap_or(0)
}
