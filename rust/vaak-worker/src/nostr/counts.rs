//! Trending posts for linked users: recent notes from their Nostr follows,
//! with engagement counts.
//!
//! `refresh` runs inside nostr-sync. It picks the most active recent notes
//! (by reposts and replies VAAK already stores) that have not been counted
//! for 30 minutes, at most VAAK_NOSTR_COUNT_NOTES (default 60) per run, and
//! asks the fallback relays once for kind 7 reactions and kind 6 reposts
//! that tag them. Each key counts once per note; "-" reactions don't count.
//! Results go to `nostr_note_counts`. One REQ per relay per run, so the
//! extra network and CPU (signature checks) stay small.
//!
//! `trending` is read on demand for one viewer (Trending posts sidebar):
//! the same candidates restricted to the viewer's follows, with mutes,
//! hides, blocks, moderation lists, manual downranks and muted words
//! applied, as statuses carrying favourites/reblogs/replies counts that the
//! PHP ranking mixes with fediverse and Bluesky posts.

use std::collections::{HashMap, HashSet};

use anyhow::{Context, Result};
use serde_json::{json, Value};
use tokio::time::Instant;
use tokio_postgres::Client;

use super::relay::Query;
use super::sync::Settings;

/// Same window as fediverse/Bluesky trending posts (AP_MASTO_TREND_STATUS_WINDOW_HOURS).
pub const WINDOW_SECS: i64 = 48 * 3600;
const RECOUNT_SECS: i64 = 30 * 60;
const KEEP_SECS: i64 = 7 * 86_400;
const MAX_EVENTS: i64 = 5000;

pub async fn ready(db: &Client) -> bool {
    db.query_one("SELECT to_regclass('nostr_note_counts') IS NOT NULL", &[])
        .await
        .map(|r| r.get::<_, bool>(0))
        .unwrap_or(false)
}

fn notes_per_run() -> i64 {
    std::env::var("VAAK_NOSTR_COUNT_NOTES")
        .ok()
        .and_then(|v| v.trim().parse::<i64>().ok())
        .map(|n| n.clamp(0, 200))
        .unwrap_or(60)
}

/// Engagement SQL shared by the pick and the read: in-graph reposts and
/// replies VAAK stores, for note `e`.
const LOCAL_REPOSTS: &str = "(SELECT COUNT(DISTINCT r.pubkey) FROM nostr_events r WHERE r.kind = 6 AND r.reply_to = e.id)";
const LOCAL_REPLIES: &str = "(SELECT COUNT(DISTINCT r.pubkey) FROM nostr_events r WHERE r.kind = 1 AND r.reply_to = e.id AND r.pubkey <> e.pubkey)";

#[derive(Debug, Default)]
pub struct Report {
    pub notes: usize,
    pub relays_ok: usize,
    pub reactions: usize,
}

impl Report {
    pub fn summary(&self) -> String {
        format!("counts={}/{}r{}", self.notes, self.relays_ok, self.reactions)
    }
}

/// Count reactions and reposts for this run's notes (see module docs).
pub async fn refresh(db: &Client, s: &Settings, now: i64, deadline: Instant) -> Result<Report> {
    let mut report = Report::default();
    let per_run = notes_per_run();
    if per_run == 0 || !ready(db).await {
        return Ok(report);
    }
    db.execute("DELETE FROM nostr_note_counts WHERE counted_at < $1", &[&(now - KEEP_SECS)]).await?;
    let since = now - WINDOW_SECS;
    let rows = db
        .query(
            &format!(
                "SELECT e.id, e.created_at FROM nostr_events e
                 LEFT JOIN nostr_note_counts c ON c.note_id = e.id
                 WHERE e.kind = 1 AND NOT e.is_reply AND e.created_at >= $1
                   AND COALESCE(c.counted_at, 0) < $2
                   AND e.pubkey IN (SELECT f.pubkey FROM nostr_follows f
                                    JOIN nostr_links l ON l.owner_user_id = f.owner_user_id AND l.show_in_home)
                 ORDER BY {LOCAL_REPOSTS} + {LOCAL_REPLIES} DESC, COALESCE(c.likes, 0) DESC, e.created_at DESC
                 LIMIT $3"
            ),
            &[&since, &(now - RECOUNT_SECS), &per_run],
        )
        .await
        .context("nostr notes to count")?;
    if rows.is_empty() || Instant::now() >= deadline {
        return Ok(report);
    }
    let ids: Vec<String> = rows.iter().map(|r| r.get::<_, String>(0)).collect();
    let oldest = rows.iter().map(|r| r.get::<_, i64>(1)).min().unwrap_or(since);
    let query = Query::tagging_notes(ids.clone(), vec![6, 7], oldest, MAX_EVENTS);
    let jobs = s.fallback_relays.iter().take(3).map(|r| (r.clone(), vec![query.clone()])).collect();
    let mut all = Vec::new();
    for (_, res) in super::sync::fan_out(db, s, jobs, deadline).await {
        if let Ok(res) = res {
            report.relays_ok += usize::from(res.completed > 0);
            all.extend(res.events);
        }
    }
    if report.relays_ok == 0 {
        // Nothing answered: keep old counts and try again next run.
        return Ok(report);
    }
    let mut rejected = 0usize;
    let events = super::sync::verified(all, now, &mut rejected);
    report.reactions = events.len();
    let counts = tally(&ids, &events);
    for id in &ids {
        let (likes, reposts) = counts.get(id).copied().unwrap_or((0, 0));
        db.execute(
            "INSERT INTO nostr_note_counts (note_id, likes, reposts, counted_at) VALUES ($1, $2, $3, $4)
             ON CONFLICT (note_id) DO UPDATE SET likes = GREATEST(nostr_note_counts.likes, EXCLUDED.likes),
               reposts = GREATEST(nostr_note_counts.reposts, EXCLUDED.reposts), counted_at = EXCLUDED.counted_at",
            &[id, &(likes as i32), &(reposts as i32), &now],
        )
        .await
        .context("store nostr note counts")?;
    }
    report.notes = ids.len();
    Ok(report)
}

/// Likes and reposts per note: distinct keys, "-" reactions left out. A
/// reaction counts for the note its last `e` tag names (NIP-25); a repost
/// for the note its `e` tag names.
pub fn tally(ids: &[String], events: &[super::event::Event]) -> HashMap<String, (usize, usize)> {
    let wanted: HashSet<&str> = ids.iter().map(String::as_str).collect();
    let mut likes: HashMap<String, HashSet<String>> = HashMap::new();
    let mut reposts: HashMap<String, HashSet<String>> = HashMap::new();
    for ev in events {
        match ev.kind {
            7 if ev.content.trim() != "-" => {
                if let Some(target) = ev.reaction_target().filter(|t| wanted.contains(t.as_str())) {
                    likes.entry(target).or_default().insert(ev.pubkey.clone());
                }
            }
            6 => {
                if let Some((target, _)) = ev.repost_target().filter(|(t, _)| wanted.contains(t.as_str())) {
                    reposts.entry(target).or_default().insert(ev.pubkey.clone());
                }
            }
            _ => {}
        }
    }
    ids.iter()
        .map(|id| (id.clone(), (likes.get(id).map_or(0, HashSet::len), reposts.get(id).map_or(0, HashSet::len))))
        .collect()
}

/// Keys this viewer downranked by hand (`nostr:<hex>` rows).
async fn downranked_keys(db: &Client, owner: i64) -> HashSet<String> {
    db.query("SELECT actor_id FROM ap_deprioritized_actors WHERE owner_user_id = $1 AND actor_id LIKE 'nostr:%'", &[&owner])
        .await
        .map(|rows| {
            rows.iter()
                .filter_map(|r| r.get::<_, Option<String>>(0))
                .filter_map(|a| a.strip_prefix("nostr:").map(str::to_ascii_lowercase))
                .filter(|k| super::event::is_hex64(k))
                .collect()
        })
        .unwrap_or_default()
}

/// Up to `limit` recent notes from `owner`'s Nostr follows, most engaged
/// first, as statuses with counts set. Empty when the viewer has no linked
/// npub or "Show in Home" is off.
pub async fn trending(db: &Client, owner: i64, limit: i64) -> Result<Vec<Value>> {
    if !super::search::enabled_for(db, owner).await {
        return Ok(Vec::new());
    }
    let since = chrono::Utc::now().timestamp() - WINDOW_SECS;
    let (likes_sql, relay_reposts_sql, join) = if ready(db).await {
        ("COALESCE(c.likes, 0)", "COALESCE(c.reposts, 0)", "LEFT JOIN nostr_note_counts c ON c.note_id = e.id")
    } else {
        ("0", "0", "")
    };
    let rows = db
        .query(
            &format!(
                "SELECT e.id, e.pubkey, e.content, {likes_sql}::bigint, GREATEST({relay_reposts_sql}, {LOCAL_REPOSTS})::bigint, {LOCAL_REPLIES}::bigint
                 FROM nostr_events e {join}
                 WHERE e.kind = 1 AND NOT e.is_reply AND e.created_at >= $2
                   AND e.pubkey IN (SELECT pubkey FROM nostr_follows WHERE owner_user_id = $1)
                 ORDER BY {likes_sql} + 2 * (GREATEST({relay_reposts_sql}, {LOCAL_REPOSTS}) + {LOCAL_REPLIES}) DESC, e.created_at DESC
                 LIMIT 200"
            ),
            &[&owner, &since],
        )
        .await
        .context("nostr trending candidates")?;
    let mut hidden = super::moderation::hidden_keys(db, owner).await;
    hidden.extend(downranked_keys(db, owner).await);
    let muted = crate::ranked_warm::load_muted_phrases(db, owner).await;
    let mut picked: Vec<(String, i64, i64, i64)> = Vec::new();
    let mut per_author: HashMap<String, usize> = HashMap::new();
    for r in &rows {
        let pubkey: String = r.get(1);
        let content: String = r.get(2);
        let (likes, reposts, replies): (i64, i64, i64) = (r.get(3), r.get(4), r.get(5));
        if likes + reposts + replies == 0
            || hidden.contains(&pubkey)
            || crate::ranked_warm::text_matches_muted(&content, &muted)
        {
            continue;
        }
        // At most two per author, like the fediverse list.
        let n = per_author.entry(pubkey).or_default();
        if *n >= 2 {
            continue;
        }
        *n += 1;
        picked.push((r.get(0), likes, reposts, replies));
        if picked.len() as i64 >= limit {
            break;
        }
    }
    let ids: Vec<String> = picked.iter().map(|p| p.0.clone()).collect();
    let mut cards = super::share::cards(db, &ids).await;
    let mut out = Vec::new();
    for (id, likes, reposts, replies) in picked {
        let Some(mut st) = cards.remove(&id) else { continue };
        st["favourites_count"] = json!(likes);
        st["reblogs_count"] = json!(reposts);
        st["replies_count"] = json!(replies);
        out.push(st);
    }
    super::moderation::filter_statuses(db, owner, &mut out).await;
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::super::event::tests::signed;
    use super::*;
    use serde_json::json;

    fn tags(list: &[&[&str]]) -> Vec<Vec<String>> {
        list.iter().map(|t| t.iter().map(|s| s.to_string()).collect()).collect()
    }

    /// Runs where a disposable fixture database with the Nostr migrations
    /// is configured (NOSTR_E2E_DB); skipped otherwise.
    #[tokio::test]
    async fn search_and_trending_respect_viewer_scope() {
        let Ok(url) = std::env::var("NOSTR_E2E_DB") else { return };
        let db = crate::db::connect(&url).await.unwrap();
        let (ids, more) = super::super::search::note_ids(&db, 1, "cozy games", 0, 20).await.unwrap();
        assert_eq!(ids, vec!["1".repeat(64), "2".repeat(64), "6".repeat(64)], "muted word, hidden key and unfollowed key left out");
        assert!(!more);
        let (ids, _) = super::super::search::note_ids(&db, 1, "#gaming", 0, 20).await.unwrap();
        assert_eq!(ids, vec!["1".repeat(64), "2".repeat(64), "6".repeat(64)], "t tag counts, #gamingnews alone does not");
        let (ids, more) = super::super::search::note_ids(&db, 1, "cozy", 0, 1).await.unwrap();
        assert_eq!((ids.len(), more), (1, true));
        let (ids, _) = super::super::search::note_ids(&db, 1, "50%", 0, 20).await.unwrap();
        assert_eq!(ids, vec!["8".repeat(64)]);
        let (ids, _) = super::super::search::note_ids(&db, 1, "5_%", 0, 20).await.unwrap();
        assert!(ids.is_empty(), "LIKE wildcards are literal");
        assert!(super::super::search::note_ids(&db, 2, "cozy", 0, 20).await.unwrap().0.is_empty(), "Show in Home off");
        assert!(super::super::search::note_ids(&db, 3, "cozy", 0, 20).await.unwrap().0.is_empty(), "no npub linked");
        let (cards, _) = super::super::search::statuses(&db, 1, "cozy games", 0, 20).await.unwrap();
        assert_eq!(cards.len(), 3);
        let top = trending(&db, 1, 10).await.unwrap();
        let got: Vec<(String, i64, i64, i64)> = top
            .iter()
            .map(|st| (
                st["id"].as_str().unwrap().to_string(),
                st["favourites_count"].as_i64().unwrap(),
                st["reblogs_count"].as_i64().unwrap(),
                st["replies_count"].as_i64().unwrap(),
            ))
            .collect();
        assert_eq!(got, vec![
            (format!("nostr:{}", "2".repeat(64)), 7, 1, 1),
            (format!("nostr:{}", "1".repeat(64)), 0, 1, 0),
        ]);
        assert!(trending(&db, 2, 10).await.unwrap().is_empty());

        // refresh: one REQ to a fake relay, counted per key, stored.
        use futures_util::{SinkExt, StreamExt};
        let note1 = "1".repeat(64);
        let events = vec![
            signed(11, 1_700_000_100, 7, tags(&[&["e", &note1]]), "+"),
            signed(11, 1_700_000_101, 7, tags(&[&["e", &note1]]), "🤙"),
            signed(12, 1_700_000_102, 7, tags(&[&["e", &note1]]), "+"),
            signed(13, 1_700_000_103, 7, tags(&[&["e", &note1]]), "-"),
            signed(14, 1_700_000_104, 6, tags(&[&["e", &note1]]), ""),
        ];
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        tokio::spawn(async move {
            while let Ok((tcp, _)) = listener.accept().await {
                let events = events.clone();
                tokio::spawn(async move {
                    let mut ws = tokio_tungstenite::accept_async(tcp).await.unwrap();
                    while let Some(Ok(msg)) = ws.next().await {
                        let Ok(text) = msg.into_text() else { continue };
                        let v: Value = serde_json::from_str(text.as_str()).unwrap_or(Value::Null);
                        if v[0] == "REQ" {
                            let sub = v[1].as_str().unwrap().to_string();
                            assert_eq!(v[2]["kinds"], json!([6, 7]));
                            for ev in &events {
                                ws.send(json!(["EVENT", sub, ev]).to_string().into()).await.unwrap();
                            }
                            ws.send(json!(["EOSE", sub]).to_string().into()).await.unwrap();
                        }
                    }
                });
            }
        });
        std::env::set_var("VAAK_NOSTR_TEST_RELAY_BASE", format!("ws://127.0.0.1:{port}"));
        let mut s = Settings::from_env(20);
        s.fallback_relays = vec!["wss://relay.example".into()];
        let now = chrono::Utc::now().timestamp();
        let report = refresh(&db, &s, now, Instant::now() + std::time::Duration::from_secs(10)).await.unwrap();
        assert_eq!(report.relays_ok, 1);
        let row = db.query_one("SELECT likes, reposts FROM nostr_note_counts WHERE note_id = $1", &[&note1]).await.unwrap();
        assert_eq!((row.get::<_, i32>(0), row.get::<_, i32>(1)), (2, 1));
        // Counted notes wait 30 minutes before the next count.
        let again = refresh(&db, &s, now + 60, Instant::now() + std::time::Duration::from_secs(10)).await.unwrap();
        assert_eq!(again.notes, 0);
    }

    #[test]
    fn tally_counts_each_key_once_and_skips_dislikes() {
        let note = "a".repeat(64);
        let other = "b".repeat(64);
        let ids = vec![note.clone(), other.clone()];
        let like1 = signed(1, 100, 7, tags(&[&["e", &note]]), "+");
        let like1_again = signed(1, 101, 7, tags(&[&["e", &note]]), "🔥");
        let like2 = signed(2, 102, 7, tags(&[&["e", &other], &["e", &note]]), "+");
        let dislike = signed(3, 103, 7, tags(&[&["e", &note]]), "-");
        let repost = signed(4, 104, 6, tags(&[&["e", &note]]), "");
        let stray = signed(5, 105, 7, tags(&[&["e", &"c".repeat(64)]]), "+");
        let counts = tally(&ids, &[like1, like1_again, like2, dislike, repost, stray]);
        assert_eq!(counts[&note], (2, 1));
        assert_eq!(counts[&other], (0, 0));
    }
}
