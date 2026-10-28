//! The relays VAAK talks to, as Admin > Relays > Nostr shows them
//! (`nostr_relays`).
//!
//! The env lists (`VAAK_NOSTR_INDEX_RELAYS` / `FALLBACK_RELAYS`) seed the
//! table once per relay; after that the table decides which relays are
//! index and fallback relays. A relay an admin removed is never contacted
//! again, whether it comes from the env, a user's relay list or a note.
//! Every relay the sync asks gets its outcome recorded, so the admin page
//! can show a green or red dot. Until the migration runs, the env lists
//! apply unchanged and nothing is recorded.

use std::collections::HashSet;

use anyhow::{Context, Result};
use tokio_postgres::Client;

use super::sync::Settings;

pub async fn ready(db: &Client) -> bool {
    db.query_one("SELECT to_regclass('nostr_relays') IS NOT NULL", &[])
        .await
        .map(|r| r.get::<_, bool>(0))
        .unwrap_or(false)
}

/// Seed the env relays, then point `s` at the table's index and fallback
/// relays and its removed ones. Leaves `s` alone when the table is missing.
pub async fn apply(db: &Client, s: &mut Settings, now: i64) -> Result<()> {
    if !ready(db).await {
        return Ok(());
    }
    for (url, index, fallback) in seed_rows(&s.index_relays, &s.fallback_relays) {
        db.execute(
            "INSERT INTO nostr_relays (url, is_index, is_fallback, added_at) VALUES ($1, $2, $3, $4)
             ON CONFLICT (url) DO NOTHING",
            &[&url, &index, &fallback, &now],
        )
        .await
        .context("seed nostr relay")?;
    }
    let (env_index, env_fallback) = (s.index_relays.clone(), s.fallback_relays.clone());
    let rows = db
        .query("SELECT url, is_index, is_fallback, removed_at IS NOT NULL FROM nostr_relays ORDER BY added_at, url", &[])
        .await
        .context("nostr relay book")?;
    s.index_relays.clear();
    s.fallback_relays.clear();
    s.skip.clear();
    for r in rows {
        let url: String = r.get(0);
        if r.get::<_, bool>(3) {
            s.skip.insert(url);
            continue;
        }
        if r.get::<_, bool>(1) {
            s.index_relays.push(url.clone());
        }
        if r.get::<_, bool>(2) {
            s.fallback_relays.push(url);
        }
    }
    // A table with no index or no fallback relay left (older seeds marked
    // relays in both env lists as index only) would leave keys without a
    // relay list nowhere to read or publish: use the env list, minus
    // removed relays, until an admin flags some in Admin > Relays > Nostr.
    if s.index_relays.is_empty() {
        s.index_relays = without_skipped(env_index, &s.skip);
    }
    if s.fallback_relays.is_empty() {
        tracing::warn!("no fallback relays in nostr_relays; using VAAK_NOSTR_FALLBACK_RELAYS");
        s.fallback_relays = without_skipped(env_fallback, &s.skip);
    }
    Ok(())
}

/// The env relays with their roles: a relay in both lists is both.
fn seed_rows(index: &[String], fallback: &[String]) -> Vec<(String, bool, bool)> {
    let mut rows: Vec<(String, bool, bool)> = Vec::new();
    for url in index.iter().chain(fallback) {
        if !rows.iter().any(|(u, _, _)| u == url) {
            rows.push((url.clone(), index.contains(url), fallback.contains(url)));
        }
    }
    rows
}

/// What one relay did when asked.
#[derive(Debug, Clone, PartialEq)]
pub struct Outcome {
    pub url: String,
    pub ok: bool,
    pub error: String,
}

/// Record outcomes. Relays not in the table yet join it as listed-only.
pub async fn record(db: &Client, outcomes: &[Outcome], now: i64) {
    if outcomes.is_empty() || !ready(db).await {
        return;
    }
    for o in outcomes {
        let error: String = o.error.chars().take(300).collect();
        if let Err(e) = db
            .execute(
                "INSERT INTO nostr_relays (url, added_at, checked_at, ok, last_ok_at, last_error, fail_streak)
                 VALUES ($1, $2::bigint, $2::bigint, $3, CASE WHEN $3 THEN $2::bigint ELSE 0::bigint END, $4, CASE WHEN $3 THEN 0 ELSE 1 END)
                 ON CONFLICT (url) DO UPDATE SET
                   checked_at = $2::bigint, ok = $3,
                   last_ok_at = CASE WHEN $3 THEN $2::bigint ELSE nostr_relays.last_ok_at END,
                   last_error = $4,
                   fail_streak = CASE WHEN $3 THEN 0 ELSE nostr_relays.fail_streak + 1 END",
                &[&o.url, &now, &o.ok, &error],
            )
            .await
        {
            tracing::warn!(relay = %o.url, error = %format!("{e:#}"), "nostr relay status not saved");
        }
    }
}

/// Listed-only relays nobody has needed for 30 days leave the table;
/// index, fallback and removed relays stay.
pub async fn prune(db: &Client, now: i64) -> Result<u64> {
    if !ready(db).await {
        return Ok(0);
    }
    db.execute(
        "DELETE FROM nostr_relays WHERE NOT is_index AND NOT is_fallback AND removed_at IS NULL AND checked_at < $1",
        &[&(now - 30 * 86_400)],
    )
    .await
    .context("prune nostr relays")
}

/// Drop removed relays from a list, keeping order.
pub fn without_skipped(list: Vec<String>, skip: &HashSet<String>) -> Vec<String> {
    list.into_iter().filter(|u| !skip.contains(u)).collect()
}

/// A well-known key (the NIP-19 example, fiatjaf) whose profile most
/// relays hold; any answer that ends the query counts.
const PROBE_PUBKEY: &str = "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d";

/// Ask each relay for one profile, the way the sync would, and record the
/// result (Admin > Relays > Nostr "Check now").
pub async fn check(db: &Client, urls: &[String], now: i64) -> Vec<Outcome> {
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(10);
    let probe = vec![super::relay::Query::new(vec![PROBE_PUBKEY.to_string()], vec![0], None, 1)];
    let outcomes: Vec<Outcome> = futures_util::future::join_all(urls.iter().map(|url| {
        let probe = probe.clone();
        async move { outcome(url, &super::relay::fetch(url, &probe, deadline).await) }
    }))
    .await;
    record(db, &outcomes, now).await;
    outcomes
}

/// A relay counts as up when it finished at least one query.
pub fn outcome(url: &str, res: &Result<super::relay::FetchResult>) -> Outcome {
    match res {
        Err(e) => Outcome { url: url.to_string(), ok: false, error: format!("{e:#}") },
        Ok(r) if r.completed == 0 => Outcome {
            url: url.to_string(),
            ok: false,
            error: if r.notices.is_empty() { "never finished a query".into() } else { r.notices.join(" | ") },
        },
        Ok(_) => Outcome { url: url.to_string(), ok: true, error: String::new() },
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn removed_relays_drop_out_in_order() {
        let skip: HashSet<String> = ["wss://dead.example".to_string()].into();
        let list = vec!["wss://a.example".to_string(), "wss://dead.example".to_string(), "wss://b.example".to_string()];
        assert_eq!(without_skipped(list, &skip), vec!["wss://a.example", "wss://b.example"]);
    }

    #[test]
    fn relays_in_both_env_lists_seed_as_both() {
        let index = vec!["wss://idx.example".to_string(), "wss://both.example".to_string()];
        let fallback = vec!["wss://both.example".to_string(), "wss://fb.example".to_string()];
        assert_eq!(
            seed_rows(&index, &fallback),
            vec![
                ("wss://idx.example".to_string(), true, false),
                ("wss://both.example".to_string(), true, true),
                ("wss://fb.example".to_string(), false, true),
            ]
        );
    }

    #[test]
    fn a_relay_that_never_finishes_is_down() {
        let quiet = super::super::relay::FetchResult { events: Vec::new(), completed: 0, notices: vec!["rate-limited".into()] };
        let o = outcome("wss://x.example", &Ok(quiet));
        assert!(!o.ok);
        assert_eq!(o.error, "rate-limited");
        assert!(!outcome("wss://x.example", &Err(anyhow::anyhow!("503"))).ok);
        let fine = super::super::relay::FetchResult { events: Vec::new(), completed: 1, notices: Vec::new() };
        assert!(outcome("wss://x.example", &Ok(fine)).ok);
    }
}
