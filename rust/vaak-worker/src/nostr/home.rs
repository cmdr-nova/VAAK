//! Home reads for linked Nostr follows: ranked keys for `ranked_warm` and
//! rows for `home_hydrate_ranked`. Both return nothing until the tables
//! exist, the owner linked an npub with "Show in Home" on, and VAAK_NOSTR
//! is not 0.

use std::collections::{HashMap, HashSet};

use anyhow::{Context, Result};
use serde_json::{json, Value};
use tokio_postgres::Client;

use super::render::NostrRow;

/// How far back Home looks for Nostr notes (storage keeps longer).
const HOME_WINDOW_SECS: i64 = 7 * 86_400;
const HOME_MAX_NOTES: i64 = 600;

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct NostrKey {
    pub id: String,
    pub sort: i64,
}

struct Candidate {
    id: String,
    pubkey: String,
    created_at: i64,
    is_reply: bool,
    reply_pubkeys: Vec<String>,
    reply_to: String,
    content: String,
}

/// A reply stays when everyone it answers is followed (or the author
/// themself): by its p tags, or else by the stored parent note's author.
fn reply_in_graph(c: &Candidate, follows: &HashSet<String>, parent_author: Option<&String>) -> bool {
    if !c.is_reply {
        return true;
    }
    let ok = |pk: &String| pk == &c.pubkey || follows.contains(pk);
    if !c.reply_pubkeys.is_empty() {
        return c.reply_pubkeys.iter().all(ok);
    }
    parent_author.is_some_and(ok)
}

/// Newest-first Home keys for one owner. `muted` filters on note text the
/// same way Home filters fediverse posts.
pub async fn fetch_keys<F: Fn(&str) -> bool + Sync>(db: &Client, owner: i64, muted: F) -> Result<Vec<NostrKey>> {
    let since = chrono::Utc::now().timestamp() - HOME_WINDOW_SECS;
    select_keys(db, owner, since, i64::MAX, HOME_MAX_NOTES, muted).await
}

/// Keys for Home older pages: notes at or before `at_most` (unix seconds),
/// newest first. Storage retention bounds how far back this reaches. Mutes
/// are applied when the page is hydrated, like every other source.
pub async fn older_keys(db: &Client, owner: i64, at_most: i64, limit: i64) -> Result<Vec<NostrKey>> {
    select_keys(db, owner, 0, at_most, limit, |_: &str| false).await
}

async fn select_keys<F: Fn(&str) -> bool + Sync>(
    db: &Client,
    owner: i64,
    since: i64,
    at_most: i64,
    limit: i64,
    muted: F,
) -> Result<Vec<NostrKey>> {
    if super::sync::disabled_by_env() || owner < 1 || !super::sync::tables_ready(db).await {
        return Ok(Vec::new());
    }
    let link = db
        .query_opt("SELECT show_in_home FROM nostr_links WHERE owner_user_id = $1", &[&owner])
        .await
        .context("nostr link for home")?;
    if !link.is_some_and(|r| r.get::<_, bool>(0)) {
        return Ok(Vec::new());
    }
    let follows: HashSet<String> = db
        .query("SELECT pubkey FROM nostr_follows WHERE owner_user_id = $1", &[&owner])
        .await
        .context("nostr follows for home")?
        .iter()
        .map(|r| r.get(0))
        .collect();
    if follows.is_empty() {
        return Ok(Vec::new());
    }
    let rows = db
        .query(
            "SELECT e.id, e.pubkey, e.created_at, e.is_reply, e.reply_pubkeys, e.reply_to, left(e.content, 4000)
             FROM nostr_events e
             WHERE e.pubkey IN (SELECT pubkey FROM nostr_follows WHERE owner_user_id = $1)
               AND e.kind = 1 AND e.created_at >= $2 AND e.created_at <= $3
             ORDER BY e.created_at DESC, e.id DESC
             LIMIT $4",
            &[&owner, &since, &at_most, &limit],
        )
        .await
        .context("nostr notes for home")?;
    let candidates: Vec<Candidate> = rows
        .iter()
        .map(|r| Candidate {
            id: r.get(0),
            pubkey: r.get(1),
            created_at: r.get(2),
            is_reply: r.get(3),
            reply_pubkeys: r.get(4),
            reply_to: r.get(5),
            content: r.get(6),
        })
        .collect();
    let parents: Vec<String> = candidates
        .iter()
        .filter(|c| c.is_reply && c.reply_pubkeys.is_empty() && !c.reply_to.is_empty())
        .map(|c| c.reply_to.clone())
        .collect();
    let parent_authors: HashMap<String, String> = if parents.is_empty() {
        HashMap::new()
    } else {
        db.query("SELECT id, pubkey FROM nostr_events WHERE id = ANY($1)", &[&parents])
            .await
            .context("nostr reply parents")?
            .iter()
            .map(|r| (r.get(0), r.get(1)))
            .collect()
    };
    Ok(candidates
        .into_iter()
        .filter(|c| reply_in_graph(c, &follows, parent_authors.get(&c.reply_to)))
        .filter(|c| !muted(&c.content))
        .map(|c| NostrKey { id: c.id, sort: c.created_at })
        .collect())
}

/// Interleave Nostr keys into a ranked Home list by `sort` (newest first),
/// the same placement rule Bluesky keys use.
pub fn merge_ranked(ranked: Vec<Value>, keys: &[NostrKey]) -> Vec<Value> {
    let seen: HashSet<&str> = ranked
        .iter()
        .filter(|r| r.get("k").and_then(Value::as_str) == Some("nostr"))
        .filter_map(|r| r.get("id").and_then(Value::as_str))
        .collect();
    let queued: Vec<Value> = keys
        .iter()
        .filter(|k| !seen.contains(k.id.as_str()))
        .map(|k| json!({"k": "nostr", "id": k.id, "s": "nostr", "sort": k.sort}))
        .collect();
    interleave(ranked, queued)
}

/// Merge `queued` (newest first) into `ranked` by `sort`, newest first.
pub fn interleave(ranked: Vec<Value>, queued: Vec<Value>) -> Vec<Value> {
    if queued.is_empty() {
        return ranked;
    }
    let sort_of = |v: &Value| v.get("sort").and_then(Value::as_i64).unwrap_or(0);
    let mut out = Vec::with_capacity(ranked.len() + queued.len());
    let (mut fi, mut qi) = (0usize, 0usize);
    while fi < ranked.len() && qi < queued.len() {
        if sort_of(&queued[qi]) >= sort_of(&ranked[fi]) {
            out.push(queued[qi].clone());
            qi += 1;
        } else {
            out.push(ranked[fi].clone());
            fi += 1;
        }
    }
    out.extend(queued[qi..].iter().cloned());
    out.extend(ranked[fi..].iter().cloned());
    out
}

/// Stored note + author profile for each id, for hydration.
pub async fn fetch_rows(db: &Client, ids: &[String]) -> Result<HashMap<String, NostrRow>> {
    if ids.is_empty() || super::sync::disabled_by_env() || !super::sync::tables_ready(db).await {
        return Ok(HashMap::new());
    }
    let rows = db
        .query(
            "SELECT e.id, e.pubkey, e.created_at, e.content, e.tags,
                    COALESCE(p.name, ''), COALESCE(p.display_name, ''), COALESCE(p.picture, ''), COALESCE(p.nip05, '')
             FROM nostr_events e LEFT JOIN nostr_profiles p ON p.pubkey = e.pubkey
             WHERE e.id = ANY($1)",
            &[&ids],
        )
        .await
        .context("nostr notes for hydrate")?;
    Ok(rows
        .iter()
        .map(|r| {
            let row = NostrRow {
                id: r.get(0),
                pubkey: r.get(1),
                created_at: r.get(2),
                content: r.get(3),
                tags: r.get(4),
                name: r.get(5),
                display_name: r.get(6),
                picture: r.get(7),
                nip05: r.get(8),
            };
            (row.id.clone(), row)
        })
        .collect())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn cand(pubkey: &str, is_reply: bool, pks: &[&str], parent: &str) -> Candidate {
        Candidate {
            id: "x".into(),
            pubkey: pubkey.into(),
            created_at: 1,
            is_reply,
            reply_pubkeys: pks.iter().map(|s| s.to_string()).collect(),
            reply_to: parent.into(),
            content: String::new(),
        }
    }

    #[test]
    fn replies_stay_inside_the_follow_graph() {
        let follows: HashSet<String> = ["alice", "bob"].iter().map(|s| s.to_string()).collect();
        assert!(reply_in_graph(&cand("alice", false, &[], ""), &follows, None));
        assert!(reply_in_graph(&cand("alice", true, &["bob"], "p"), &follows, None));
        assert!(reply_in_graph(&cand("alice", true, &["alice"], "p"), &follows, None));
        assert!(!reply_in_graph(&cand("alice", true, &["bob", "stranger"], "p"), &follows, None));
        let bob = "bob".to_string();
        let stranger = "stranger".to_string();
        assert!(reply_in_graph(&cand("alice", true, &[], "p"), &follows, Some(&bob)));
        assert!(!reply_in_graph(&cand("alice", true, &[], "p"), &follows, Some(&stranger)));
        assert!(!reply_in_graph(&cand("alice", true, &[], "p"), &follows, None));
    }

    #[test]
    fn merge_interleaves_by_sort_and_skips_duplicates() {
        let ranked = vec![
            json!({"k": "event", "id": "1", "sort": 300}),
            json!({"k": "nostr", "id": "n0", "sort": 250}),
            json!({"k": "bsky", "id": "at://x", "sort": 100}),
        ];
        let keys = vec![
            NostrKey { id: "n1".into(), sort: 400 },
            NostrKey { id: "n0".into(), sort: 250 },
            NostrKey { id: "n2".into(), sort: 200 },
            NostrKey { id: "n3".into(), sort: 50 },
        ];
        let ids: Vec<String> = merge_ranked(ranked, &keys)
            .iter()
            .map(|v| v["id"].as_str().unwrap().to_string())
            .collect();
        assert_eq!(ids, vec!["n1", "1", "n0", "n2", "at://x", "n3"]);
        assert!(merge_ranked(vec![], &[]).is_empty());
    }
}
