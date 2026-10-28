//! Backup copy of linked users' own Nostr events (`nostr_archive`), served
//! by `vaak-worker nostr-relay` (see `backup_relay`).
//!
//! Only keys in `nostr_links` of an active user are kept, and only the kinds
//! in `KINDS`. Events arrive three ways: `pull` during nostr-sync (a one-time
//! backfill paged back with `until`, then a pull every `PULL_SECS`), VAAK's
//! own signed actions (`act::publish`), and clients writing to the relay.
//! Every event is id- and signature-checked by the caller before `store`.
//!
//! Nothing expires by age. Replaceable kinds keep the newest copy live and a
//! few older ones (`superseded`) for restores. A kind 5 from the author
//! removes what it names and blocks it from coming back. A key whose link
//! went away is purged after `UNLINKED_GRACE_SECS`.

use std::collections::{BTreeMap, HashSet};

use anyhow::{Context, Result};
use serde_json::Value;
use tokio::time::Instant;
use tokio_postgres::{Client, GenericClient};

use super::event::{self, Event};
use super::relay::{self, Query};
use super::sync::Settings;

/// 0 profile, 1 note, 3 follow list, 5 deletion, 6 repost, 10000 mute list,
/// 10002 relay list, 30023 long-form post.
pub const KINDS: &[i64] = &[0, 1, 3, 5, 6, 10000, 10002, 30023];
/// Older copies of a replaceable event kept for restores.
const OLD_COPIES_KEPT: i64 = 5;
/// How often a linked key's new events are pulled once its backfill is done.
const PULL_SECS: i64 = 900;
const PULL_OVERLAP_SECS: i64 = 900;
/// Events asked for per REQ (most relays cap near 500).
const PAGE_LIMIT: i64 = 500;
/// Relays asked per run: the index relays plus linked keys' own.
const PULL_RELAYS: usize = 10;
const OWN_RELAYS_PER_KEY: usize = 4;
pub const UNLINKED_GRACE_SECS: i64 = 30 * 86_400;

pub fn is_replaceable(kind: i64) -> bool {
    kind == 0 || kind == 3 || (10_000..20_000).contains(&kind)
}

pub fn is_addressable(kind: i64) -> bool {
    (30_000..40_000).contains(&kind)
}

pub fn d_tag(ev: &Event) -> String {
    if !is_addressable(ev.kind) {
        return String::new();
    }
    ev.tags
        .iter()
        .find(|t| t.first().map(String::as_str) == Some("d"))
        .and_then(|t| t.get(1))
        .cloned()
        .unwrap_or_default()
}

/// Ids a kind 5 deletes (`e` tags) and the addresses (`kind:pubkey:d` from
/// `a` tags) of its own author's addressable events.
fn deletion_targets(ev: &Event) -> (Vec<String>, Vec<(i32, String)>) {
    let mut ids = Vec::new();
    let mut addrs = Vec::new();
    for tag in &ev.tags {
        match (tag.first().map(String::as_str), tag.get(1)) {
            (Some("e"), Some(id)) => {
                let id = id.trim().to_ascii_lowercase();
                if event::is_hex64(&id) && !ids.contains(&id) {
                    ids.push(id);
                }
            }
            (Some("a"), Some(addr)) => {
                let mut parts = addr.splitn(3, ':');
                let (Some(kind), Some(pk), Some(d)) = (parts.next(), parts.next(), parts.next())
                else {
                    continue;
                };
                if pk == ev.pubkey {
                    if let Ok(kind) = kind.parse::<i32>() {
                        addrs.push((kind, d.to_string()));
                    }
                }
            }
            _ => {}
        }
    }
    (ids, addrs)
}

pub async fn ready(db: &impl GenericClient) -> bool {
    db.query_one("SELECT to_regclass('nostr_archive') IS NOT NULL AND to_regclass('nostr_archive_state') IS NOT NULL", &[])
        .await
        .map(|r| r.get::<_, bool>(0))
        .unwrap_or(false)
}

/// Public keys whose events are kept: linked by an active VAAK user.
pub async fn linked_keys(db: &impl GenericClient) -> Result<HashSet<String>> {
    let rows = db
        .query(
            "SELECT DISTINCT l.pubkey FROM nostr_links l JOIN ap_users u ON u.id = l.owner_user_id WHERE u.disabled_at IS NULL",
            &[],
        )
        .await
        .context("nostr archive linked keys")?;
    Ok(rows
        .iter()
        .map(|r| r.get::<_, String>(0))
        .filter(|k| event::is_hex64(k))
        .collect())
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Stored {
    /// New and current: a relay can hand it to live subscribers.
    New,
    /// New, but an older copy of a replaceable event (restore only).
    Old,
    Duplicate,
    /// Its author already deleted it.
    Deleted,
}

/// Keep one verified event of an archived kind. The caller has checked the
/// signature, the kind and that the author is linked.
pub async fn store(db: &impl GenericClient, ev: &Event, source: &str, now: i64) -> Result<Stored> {
    let kind = ev.kind as i32;
    let d = d_tag(ev);
    if ev.kind != 5 {
        let gone = db
            .query_one(
                "SELECT EXISTS (SELECT 1 FROM nostr_archive WHERE kind = 5 AND pubkey = $1 AND deletes @> ARRAY[$2::text])
                     OR ($3 AND EXISTS (SELECT 1 FROM nostr_archive WHERE kind = 5 AND pubkey = $1 AND created_at >= $5
                         AND tags @> jsonb_build_array(jsonb_build_array('a', $4::text))))",
                &[&ev.pubkey, &ev.id, &is_addressable(ev.kind), &format!("{}:{}:{}", ev.kind, ev.pubkey, d), &ev.created_at],
            )
            .await
            .context("nostr archive deleted check")?
            .get::<_, bool>(0);
        if gone {
            return Ok(Stored::Deleted);
        }
    }
    let (deletes, addrs) = if ev.kind == 5 {
        deletion_targets(ev)
    } else {
        (Vec::new(), Vec::new())
    };
    let tags = serde_json::to_value(&ev.tags).unwrap_or(Value::Array(Vec::new()));
    let raw = serde_json::to_string(ev).context("nostr archive event json")?;
    let inserted = db
        .execute(
            "INSERT INTO nostr_archive (id, pubkey, kind, created_at, d_tag, tags, event_json, deletes, source, received_at)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10) ON CONFLICT (id) DO NOTHING",
            &[&ev.id, &ev.pubkey, &kind, &ev.created_at, &d, &tags, &raw, &deletes, &source, &now],
        )
        .await
        .context("nostr archive insert")?;
    if inserted == 0 {
        return Ok(Stored::Duplicate);
    }
    if ev.kind == 5 {
        if !deletes.is_empty() {
            db.execute(
                "DELETE FROM nostr_archive WHERE pubkey = $1 AND kind <> 5 AND id = ANY($2)",
                &[&ev.pubkey, &deletes],
            )
            .await
            .context("nostr archive delete ids")?;
        }
        for (k, d) in &addrs {
            db.execute(
                "DELETE FROM nostr_archive WHERE pubkey = $1 AND kind = $2 AND d_tag = $3 AND created_at <= $4",
                &[&ev.pubkey, k, d, &ev.created_at],
            )
            .await
            .context("nostr archive delete address")?;
        }
        return Ok(Stored::New);
    }
    if !(is_replaceable(ev.kind) || is_addressable(ev.kind)) {
        return Ok(Stored::New);
    }
    // Newest wins, lowest id on a tie (NIP-01). Recomputed for the whole
    // group so two writers racing still leave exactly one live copy.
    db.execute(
        "UPDATE nostr_archive a SET superseded = (a.id <> w.id)
         FROM (SELECT id FROM nostr_archive WHERE pubkey = $1 AND kind = $2 AND d_tag = $3
               ORDER BY created_at DESC, id ASC LIMIT 1) w
         WHERE a.pubkey = $1 AND a.kind = $2 AND a.d_tag = $3",
        &[&ev.pubkey, &kind, &d],
    )
    .await
    .context("nostr archive supersede")?;
    db.execute(
        "DELETE FROM nostr_archive WHERE pubkey = $1 AND kind = $2 AND d_tag = $3 AND superseded AND id NOT IN (
             SELECT id FROM nostr_archive WHERE pubkey = $1 AND kind = $2 AND d_tag = $3 AND superseded
             ORDER BY created_at DESC, id ASC LIMIT $4)",
        &[&ev.pubkey, &kind, &d, &OLD_COPIES_KEPT],
    )
    .await
    .context("nostr archive prune old copies")?;
    let live = db
        .query_opt(
            "SELECT NOT superseded FROM nostr_archive WHERE id = $1",
            &[&ev.id],
        )
        .await
        .context("nostr archive live check")?
        .map(|r| r.get::<_, bool>(0))
        .unwrap_or(false);
    Ok(if live { Stored::New } else { Stored::Old })
}

/// Why an event is refused by the archive, or None when it is kept.
pub fn refusal(ev: &Event, linked: &HashSet<String>) -> Option<&'static str> {
    if !KINDS.contains(&ev.kind) {
        return Some("blocked: this relay keeps profiles, notes, reposts, long-form posts, deletions and follow/relay/mute lists only");
    }
    if !linked.contains(&ev.pubkey) {
        return Some("restricted: only npubs linked to a VAAK account can write here");
    }
    None
}

/// Store events from a trusted path (a pull, or VAAK's own signed action)
/// when they belong in the archive. Returns how many were new.
pub async fn keep_verified(
    db: &impl GenericClient,
    events: &[Event],
    linked: &HashSet<String>,
    source: &str,
    now: i64,
) -> Result<u64> {
    let mut n = 0;
    for ev in events {
        if refusal(ev, linked).is_some() {
            continue;
        }
        if matches!(store(db, ev, source, now).await?, Stored::New | Stored::Old) {
            n += 1;
        }
    }
    Ok(n)
}

/// One signed event VAAK just checked for a linked user (act::publish).
/// Best effort: a missing table or a failed write never blocks the action.
pub async fn keep_own(db: &Client, ev: &Event) {
    if !ready(db).await {
        return;
    }
    let now = chrono::Utc::now().timestamp();
    let linked: HashSet<String> = [ev.pubkey.clone()].into_iter().collect();
    if let Err(e) = keep_verified(db, std::slice::from_ref(ev), &linked, "vaak", now).await {
        tracing::warn!(error = %format!("{e:#}"), "nostr archive keep failed");
    }
}

#[derive(Debug, Default)]
pub struct PullReport {
    pub keys: usize,
    pub due: usize,
    pub relays_ok: usize,
    pub relays: usize,
    pub stored: u64,
    pub backfilling: usize,
    pub purged: u64,
}

impl PullReport {
    pub fn summary(&self) -> String {
        format!(
            "archive keys={} due={} relays={}/{} stored={} backfilling={} purged={}",
            self.keys,
            self.due,
            self.relays_ok,
            self.relays,
            self.stored,
            self.backfilling,
            self.purged
        )
    }
}

struct Due {
    pubkey: String,
    synced_at: i64,
    backfill_until: i64,
    backfill_done: bool,
}

/// The public URL of VAAK's own backup relay, which pulls must skip.
pub fn own_relay_url() -> Option<String> {
    std::env::var("VAAK_NOSTR_RELAY_URL")
        .ok()
        .and_then(|u| relay::normalize_relay_url(&u))
}

/// Relays to ask: the index relays, then each due key's own write relays,
/// most shared first, `PULL_RELAYS` at most.
fn pull_relays(
    index: &[String],
    own: &[(String, Vec<String>)],
    skip: &HashSet<String>,
) -> Vec<String> {
    let mut count: BTreeMap<&str, usize> = BTreeMap::new();
    for (_, relays) in own {
        for r in relays.iter().take(OWN_RELAYS_PER_KEY) {
            *count.entry(r.as_str()).or_default() += 1;
        }
    }
    let mut shared: Vec<(&str, usize)> = count.into_iter().collect();
    shared.sort_by(|a, b| b.1.cmp(&a.1).then(a.0.cmp(b.0)));
    let mut out: Vec<String> = Vec::new();
    for url in index
        .iter()
        .map(String::as_str)
        .chain(shared.into_iter().map(|(u, _)| u))
    {
        if out.len() >= PULL_RELAYS {
            break;
        }
        if !skip.contains(url) && !out.iter().any(|u| u == url) {
            out.push(url.to_string());
        }
    }
    out
}

/// The REQs for this run: one incremental read for keys already backfilled
/// (since their last good pull), and one page back in time per key still
/// backfilling.
fn pull_queries(due: &[Due], now: i64) -> Vec<Query> {
    let mut out = Vec::new();
    let caught_up: Vec<&Due> = due.iter().filter(|d| d.synced_at > 0).collect();
    if !caught_up.is_empty() {
        let since = caught_up.iter().map(|d| d.synced_at).min().unwrap_or(now) - PULL_OVERLAP_SECS;
        let authors = caught_up.iter().map(|d| d.pubkey.clone()).collect();
        out.push(Query::new(
            authors,
            KINDS.to_vec(),
            Some(since.max(0)),
            PAGE_LIMIT,
        ));
    }
    for d in due.iter().filter(|d| !d.backfill_done) {
        let until = if d.backfill_until > 0 {
            d.backfill_until
        } else {
            now
        };
        out.push(Query::new(vec![d.pubkey.clone()], KINDS.to_vec(), None, PAGE_LIMIT).until(until));
    }
    out
}

/// Where a key's backfill stands after a page. `older` holds, per relay
/// that answered, its oldest event for the key below the cursor (None when
/// it had nothing older). The cursor moves to the newest of those, so a
/// relay with a full page is never skipped past because a sparser relay
/// reached further back; it's done once no relay had anything older.
fn next_backfill(d: &Due, older: &[Option<i64>], now: i64) -> (i64, bool) {
    let cursor = if d.backfill_until > 0 {
        d.backfill_until
    } else {
        now
    };
    match older.iter().flatten().max() {
        Some(&t) if t < cursor => (t, false),
        _ => (cursor, true),
    }
}

/// nostr-sync step: pull linked keys' own events into the archive, then
/// purge keys unlinked for longer than the grace period.
pub async fn pull(db: &Client, s: &Settings, now: i64, deadline: Instant) -> Result<PullReport> {
    let mut report = PullReport::default();
    if !ready(db).await {
        return Ok(report);
    }
    let linked = linked_keys(db).await?;
    report.keys = linked.len();
    let keys: Vec<String> = linked.iter().cloned().collect();
    db.execute(
        "INSERT INTO nostr_archive_state (pubkey, linked_seen_at) SELECT unnest($1::text[]), $2
         ON CONFLICT (pubkey) DO UPDATE SET linked_seen_at = EXCLUDED.linked_seen_at",
        &[&keys, &now],
    )
    .await
    .context("nostr archive state")?;
    let cutoff = now - UNLINKED_GRACE_SECS;
    report.purged = db
        .execute(
            "DELETE FROM nostr_archive a WHERE NOT EXISTS (
                 SELECT 1 FROM nostr_archive_state s WHERE s.pubkey = a.pubkey AND s.linked_seen_at >= $1)",
            &[&cutoff],
        )
        .await
        .context("nostr archive purge")?;
    db.execute(
        "DELETE FROM nostr_archive_state WHERE linked_seen_at < $1",
        &[&cutoff],
    )
    .await?;

    let rows = db
        .query(
            "SELECT pubkey, synced_at, backfill_until, backfill_done FROM nostr_archive_state
             WHERE pubkey = ANY($1) AND next_pull_at <= $2 ORDER BY next_pull_at, pubkey LIMIT 50",
            &[&keys, &now],
        )
        .await
        .context("nostr archive due")?;
    let due: Vec<Due> = rows
        .iter()
        .map(|r| Due {
            pubkey: r.get(0),
            synced_at: r.get(1),
            backfill_until: r.get(2),
            backfill_done: r.get(3),
        })
        .collect();
    report.due = due.len();
    if due.is_empty() || Instant::now() >= deadline {
        return Ok(report);
    }
    let due_keys: Vec<String> = due.iter().map(|d| d.pubkey.clone()).collect();
    let mut skip = s.skip.clone();
    skip.extend(own_relay_url());
    let own = super::sync::own_relays(db, &due_keys).await?;
    let relays = pull_relays(&s.index_relays, &own, &skip);
    report.relays = relays.len();
    let queries = pull_queries(&due, now);
    let jobs = relays.into_iter().map(|r| (r, queries.clone())).collect();
    let mut answered: Vec<Vec<Event>> = Vec::new();
    for (_, res) in super::sync::fan_out(db, s, jobs, deadline).await {
        if let Ok(res) = res {
            if res.completed > 0 {
                report.relays_ok += 1;
                answered.push(res.events);
            }
        }
    }
    let mut rejected = 0usize;
    let mut good = super::sync::verified(answered.iter().flatten().cloned(), now, &mut rejected);
    // Deletions first, so a note and its kind 5 in the same page never
    // leave the note behind.
    good.sort_by_key(|e| (e.kind != 5, e.created_at));
    let wanted: HashSet<String> = due_keys.iter().cloned().collect();
    report.stored = keep_verified(db, &good, &wanted, "pull", now).await?;
    let good_ids: HashSet<&str> = good.iter().map(|e| e.id.as_str()).collect();
    // Nobody answered: leave every cursor where it was and try next run.
    if report.relays_ok == 0 {
        return Ok(report);
    }
    for d in &due {
        let (until, done) = if d.backfill_done {
            (d.backfill_until, true)
        } else {
            let cursor = if d.backfill_until > 0 {
                d.backfill_until
            } else {
                now
            };
            let older: Vec<Option<i64>> = answered
                .iter()
                .map(|evs| {
                    evs.iter()
                        .filter(|e| {
                            e.pubkey == d.pubkey
                                && e.created_at < cursor
                                && good_ids.contains(e.id.as_str())
                        })
                        .map(|e| e.created_at)
                        .min()
                })
                .collect();
            next_backfill(d, &older, now)
        };
        // The first page also starts the incremental pulls from now on.
        let synced = now;
        let next = if done { now + PULL_SECS } else { now };
        report.backfilling += usize::from(!done);
        db.execute(
            "UPDATE nostr_archive_state SET synced_at = $2, next_pull_at = $3, backfill_until = $4, backfill_done = $5 WHERE pubkey = $1",
            &[&d.pubkey, &synced, &next, &until, &done],
        )
        .await
        .context("nostr archive state update")?;
    }
    Ok(report)
}

#[cfg(test)]
mod tests {
    use super::super::event::tests::signed;
    use super::*;
    use serde_json::json;

    fn tags(list: &[&[&str]]) -> Vec<Vec<String>> {
        list.iter()
            .map(|t| t.iter().map(|s| s.to_string()).collect())
            .collect()
    }

    #[test]
    fn kind_classes() {
        assert!(
            is_replaceable(0)
                && is_replaceable(3)
                && is_replaceable(10002)
                && is_replaceable(10000)
        );
        assert!(!is_replaceable(1) && !is_replaceable(30023));
        assert!(is_addressable(30023) && !is_addressable(1));
        let long = signed(
            1,
            1,
            30023,
            tags(&[&["d", "my-post"], &["title", "Hi"]]),
            "body",
        );
        assert_eq!(d_tag(&long), "my-post");
        assert_eq!(d_tag(&signed(1, 1, 1, tags(&[&["d", "x"]]), "")), "");
    }

    #[test]
    fn deletions_name_ids_and_own_addresses_only() {
        let e1 = "e".repeat(64);
        let author = signed(4, 1, 1, vec![], "").pubkey;
        let other = "9".repeat(64);
        let del = signed(
            4,
            10,
            5,
            tags(&[
                &["e", &e1],
                &["e", "short"],
                &["e", &e1.to_uppercase()],
                &["a", &format!("30023:{author}:post")],
                &["a", &format!("30023:{other}:post")],
            ]),
            "",
        );
        let (ids, addrs) = deletion_targets(&del);
        assert_eq!(ids, vec![e1]);
        assert_eq!(addrs, vec![(30023, "post".to_string())]);
    }

    #[test]
    fn only_linked_keys_and_kept_kinds() {
        let ev = signed(5, 1, 1, vec![], "hi");
        let mut linked = HashSet::new();
        assert!(refusal(&ev, &linked).unwrap().starts_with("restricted:"));
        linked.insert(ev.pubkey.clone());
        assert_eq!(refusal(&ev, &linked), None);
        let reaction = signed(5, 1, 7, vec![], "+");
        assert!(refusal(&reaction, &linked).unwrap().starts_with("blocked:"));
    }

    #[test]
    fn backfill_pages_back_until_a_page_has_nothing_older() {
        let now = 1_800_000_000;
        let fresh = Due {
            pubkey: "a".repeat(64),
            synced_at: 0,
            backfill_until: 0,
            backfill_done: false,
        };
        assert_eq!(
            next_backfill(&fresh, &[Some(now - 500)], now),
            (now - 500, false)
        );
        assert_eq!(next_backfill(&fresh, &[None, None], now), (now, true));
        assert_eq!(next_backfill(&fresh, &[], now), (now, true));
        // A sparse relay reaching far back doesn't skip a full one's gap.
        assert_eq!(
            next_backfill(&fresh, &[Some(now - 90_000), Some(now - 600), None], now),
            (now - 600, false)
        );
        let mid = Due {
            backfill_until: now - 500,
            ..fresh
        };
        assert_eq!(
            next_backfill(&mid, &[Some(now - 9000)], now),
            (now - 9000, false)
        );
    }

    #[test]
    fn queries_split_incremental_and_backfill() {
        let now = 1_800_000_000;
        let due = vec![
            Due {
                pubkey: "a".repeat(64),
                synced_at: now - 1000,
                backfill_until: 5,
                backfill_done: true,
            },
            Due {
                pubkey: "b".repeat(64),
                synced_at: now - 3000,
                backfill_until: now - 86_400,
                backfill_done: false,
            },
            Due {
                pubkey: "c".repeat(64),
                synced_at: 0,
                backfill_until: 0,
                backfill_done: false,
            },
        ];
        let qs = pull_queries(&due, now);
        assert_eq!(qs.len(), 3);
        assert_eq!(qs[0].authors.len(), 2);
        assert_eq!(qs[0].filter["since"], now - 3000 - PULL_OVERLAP_SECS);
        assert!(qs[0].filter.get("until").is_none());
        assert_eq!(qs[1].filter["until"], now - 86_400);
        assert_eq!(qs[2].filter["until"], now);
        assert_eq!(qs[2].filter["kinds"], json!(KINDS));
    }

    #[test]
    fn relays_index_first_then_shared_own_skipping_ours() {
        let index = vec![
            "wss://nos.lol".to_string(),
            "wss://relay.damus.io".to_string(),
        ];
        let own = vec![
            (
                "k1".to_string(),
                vec![
                    "wss://a.example".to_string(),
                    "wss://nos.lol".to_string(),
                    "wss://backup.example".to_string(),
                ],
            ),
            (
                "k2".to_string(),
                vec!["wss://a.example".to_string(), "wss://b.example".to_string()],
            ),
        ];
        let skip: HashSet<String> = ["wss://backup.example".to_string()].into_iter().collect();
        assert_eq!(
            pull_relays(&index, &own, &skip),
            vec![
                "wss://nos.lol",
                "wss://relay.damus.io",
                "wss://a.example",
                "wss://b.example"
            ]
        );
    }
}
