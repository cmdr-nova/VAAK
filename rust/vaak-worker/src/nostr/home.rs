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
    /// Author of the note the card shows (the reposted note's author for a
    /// repost) and its text, for downranking.
    pub author: String,
    pub text: String,
    /// The shown note's id (differs from `id` for a repost).
    pub note: String,
}

struct Candidate {
    id: String,
    pubkey: String,
    created_at: i64,
    is_reply: bool,
    reply_pubkeys: Vec<String>,
    reply_to: String,
    content: String,
    /// Kind 6 only: the reposted note's author and text, when stored.
    repost_of: Option<(String, String)>,
    is_repost: bool,
    /// The NIP-48 `proxy` tag's ActivityPub URL, when the note is a copy of
    /// a fediverse post (VAAK's composer crosspost, Mostr bridges).
    proxied_from: Option<String>,
}

impl Candidate {
    /// The note the card shows: itself, or the note it reposts.
    fn shown(&self) -> (&str, &str, &str) {
        match &self.repost_of {
            Some((pk, text)) => (self.reply_to.as_str(), pk.as_str(), text.as_str()),
            None => (self.id.as_str(), self.pubkey.as_str(), self.content.as_str()),
        }
    }
}

/// Drop what the owner hid, reposts whose note isn't stored, muted text,
/// and second copies of a note (a note and its reposts show once, newest).
fn pick(candidates: Vec<Candidate>, hidden: &HashSet<String>, muted: impl Fn(&str) -> bool) -> Vec<NostrKey> {
    let mut seen = HashSet::new();
    let mut out = Vec::new();
    for c in candidates {
        if c.is_repost && c.repost_of.is_none() {
            continue;
        }
        let (note, author, text) = c.shown();
        if hidden.contains(&c.pubkey) || hidden.contains(author) || muted(text) || !seen.insert(note.to_string()) {
            continue;
        }
        let (note, author, text) = (note.to_string(), author.to_string(), text.to_string());
        out.push(NostrKey { id: c.id, sort: c.created_at, author, text, note });
    }
    out
}

/// A Nostr copy of a fediverse post by the owner or someone they follow on
/// the fediverse: the original already reaches Home, so the copy would show
/// the same post twice. `covered` holds actor ids without a trailing slash.
fn proxied_from_covered(url: Option<&str>, covered: &HashSet<String>) -> bool {
    let Some(url) = url else { return false };
    covered.iter().any(|actor| url.len() > actor.len() + 1 && url.starts_with(actor.as_str()) && url.as_bytes()[actor.len()] == b'/')
}

/// A reply stays when everyone it answers is followed (or the author
/// themself): by its p tags, or else by the stored parent note's author.
fn reply_in_graph(c: &Candidate, follows: &HashSet<String>, parent_author: Option<&String>) -> bool {
    if !c.is_reply || c.is_repost {
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
    // A repost's reply_to is the note it reposts (nostr::sync).
    let rows = db
        .query(
            "SELECT e.id, e.pubkey, e.created_at, e.is_reply, e.reply_pubkeys, e.reply_to, left(e.content, 4000),
                    e.kind, t.pubkey, left(t.content, 4000),
                    (SELECT p->>1 FROM jsonb_array_elements(e.tags) p
                     WHERE e.kind = 1 AND p->>0 = 'proxy' AND p->>2 = 'activitypub' LIMIT 1)
             FROM nostr_events e
             LEFT JOIN nostr_events t ON e.kind = 6 AND t.id = e.reply_to AND t.kind = 1
             WHERE e.pubkey IN (SELECT pubkey FROM nostr_follows WHERE owner_user_id = $1)
               AND e.kind IN (1, 6) AND e.created_at >= $2 AND e.created_at <= $3
             ORDER BY e.created_at DESC, e.id DESC
             LIMIT $4",
            &[&owner, &since, &at_most, &limit],
        )
        .await
        .context("nostr notes for home")?;
    let candidates: Vec<Candidate> = rows
        .iter()
        .map(|r| {
            let is_repost = r.get::<_, i32>(7) == 6;
            let target: Option<String> = r.get(8);
            Candidate {
                id: r.get(0),
                pubkey: r.get(1),
                created_at: r.get(2),
                is_reply: r.get(3),
                reply_pubkeys: r.get(4),
                reply_to: r.get(5),
                content: r.get(6),
                repost_of: target.map(|pk| (pk, r.get::<_, Option<String>>(9).unwrap_or_default())),
                is_repost,
                proxied_from: r.get(10),
            }
        })
        .collect();
    let parents: Vec<String> = candidates
        .iter()
        .filter(|c| c.is_reply && !c.is_repost && c.reply_pubkeys.is_empty() && !c.reply_to.is_empty())
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
    let covered: HashSet<String> = if candidates.iter().any(|c| c.proxied_from.is_some()) {
        db.query(
            "SELECT rtrim(u.actor_id, '/') FROM ap_users u WHERE u.id::bigint = $1
             UNION SELECT rtrim(f.actor_id, '/') FROM following f JOIN ap_users u
               ON rtrim(f.owner_actor_id, '/') = rtrim(u.actor_id, '/')
             WHERE u.id::bigint = $1 AND COALESCE(f.follow_state, '') NOT IN ('pending', 'rejected')",
            &[&owner],
        )
        .await
        .context("fediverse follows for nostr dedupe")?
        .iter()
        .filter_map(|r| r.get::<_, Option<String>>(0))
        .filter(|a| !a.is_empty())
        .collect()
    } else {
        HashSet::new()
    };
    let hidden = super::moderation::hidden_keys(db, owner).await;
    let in_graph: Vec<Candidate> = candidates
        .into_iter()
        .filter(|c| reply_in_graph(c, &follows, parent_authors.get(&c.reply_to)))
        .filter(|c| !proxied_from_covered(c.proxied_from.as_deref(), &covered))
        .collect();
    Ok(pick(in_graph, &hidden, muted))
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

/// Stored note + author profile for each id, for hydration. A repost
/// comes back with the note it reposts attached.
pub async fn fetch_rows(db: &Client, ids: &[String]) -> Result<HashMap<String, NostrRow>> {
    if ids.is_empty() || super::sync::disabled_by_env() || !super::sync::tables_ready(db).await {
        return Ok(HashMap::new());
    }
    let mut out = load_rows(db, ids).await?;
    let targets: Vec<String> = out
        .values()
        .filter_map(|(row, kind, reply_to)| (*kind == 6 && !reply_to.is_empty() && row.reposted.is_none()).then(|| reply_to.clone()))
        .collect();
    let notes = if targets.is_empty() { HashMap::new() } else { load_rows(db, &targets).await? };
    for (row, kind, reply_to) in out.values_mut() {
        if *kind == 6 {
            row.reposted = notes.get(reply_to).filter(|(_, k, _)| *k == 1).map(|(n, _, _)| Box::new(n.clone()));
        }
    }
    Ok(out
        .into_iter()
        .filter(|(_, (row, kind, _))| *kind == 1 || row.reposted.is_some())
        .map(|(id, (row, _, _))| (id, row))
        .collect())
}

async fn load_rows(db: &Client, ids: &[String]) -> Result<HashMap<String, (NostrRow, i32, String)>> {
    let rows = db
        .query(
            "SELECT e.id, e.pubkey, e.created_at, e.content, e.tags,
                    COALESCE(p.name, ''), COALESCE(p.display_name, ''), COALESCE(p.picture, ''), COALESCE(p.nip05, ''),
                    e.kind, e.reply_to
             FROM nostr_events e LEFT JOIN nostr_profiles p ON p.pubkey = e.pubkey
             WHERE e.id = ANY($1) AND e.kind IN (1, 6)",
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
                reposted: None,
            };
            (row.id.clone(), (row, r.get(9), r.get(10)))
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
            repost_of: None,
            is_repost: false,
            proxied_from: None,
        }
    }

    fn key(id: &str, sort: i64) -> NostrKey {
        NostrKey { id: id.into(), sort, author: String::new(), text: String::new(), note: id.into() }
    }

    #[test]
    fn crossposts_of_followed_fediverse_posts_are_skipped() {
        let covered: HashSet<String> =
            ["https://mkultra.monster/users/cmdr_nova".to_string(), "https://m.example/users/a".to_string()].into();
        let skip = |u: &str| proxied_from_covered(Some(u), &covered);
        assert!(skip("https://mkultra.monster/users/cmdr_nova/notes/abc"));
        assert!(skip("https://m.example/users/a/statuses/1"));
        assert!(!skip("https://mkultra.monster/users/cmdr_nova_two/notes/abc"), "a lookalike actor is not covered");
        assert!(!skip("https://mkultra.monster/users/shana_flux/notes/1"), "unfollowed accounts still show");
        assert!(!skip("https://mkultra.monster/users/cmdr_nova/"), "the actor itself is not a post");
        assert!(!proxied_from_covered(None, &covered));
    }

    #[test]
    fn reposts_show_once_and_respect_hides_and_mutes() {
        let note = |id: &str, pk: &str, at: i64, text: &str| Candidate {
            id: id.into(),
            pubkey: pk.into(),
            created_at: at,
            content: text.into(),
            ..cand(pk, false, &[], "")
        };
        let repost = |id: &str, pk: &str, at: i64, target: &str, of: Option<(&str, &str)>| Candidate {
            id: id.into(),
            pubkey: pk.into(),
            created_at: at,
            reply_to: target.into(),
            repost_of: of.map(|(a, t)| (a.to_string(), t.to_string())),
            is_repost: true,
            ..cand(pk, false, &[], "")
        };
        let list = vec![
            repost("r1", "alice", 50, "n1", Some(("bob", "hello"))),
            note("n1", "bob", 40, "hello"),
            repost("r2", "alice", 39, "gone", None),
            repost("r3", "alice", 38, "n9", Some(("troll", "hi"))),
            repost("r4", "troll", 37, "n8", Some(("bob", "fine"))),
            repost("r5", "alice", 36, "n7", Some(("bob", "buy crypto"))),
            note("n2", "carol", 30, "plain"),
        ];
        let hidden: HashSet<String> = ["troll".to_string()].into();
        let keys = pick(list, &hidden, |t: &str| t.contains("crypto"));
        let ids: Vec<&str> = keys.iter().map(|k| k.id.as_str()).collect();
        assert_eq!(ids, vec!["r1", "n2"]);
        assert_eq!(keys[0].author, "bob");
        assert_eq!(keys[0].text, "hello");
        assert_eq!(keys[0].note, "n1");
        assert!(reply_in_graph(&repost("r", "alice", 1, "x", None), &HashSet::new(), None), "a repost is never a reply");
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
            key("n1", 400),
            key("n0", 250),
            key("n2", 200),
            key("n3", 50),
        ];
        let ids: Vec<String> = merge_ranked(ranked, &keys)
            .iter()
            .map(|v| v["id"].as_str().unwrap().to_string())
            .collect();
        assert_eq!(ids, vec!["n1", "1", "n0", "n2", "at://x", "n3"]);
        assert!(merge_ranked(vec![], &[]).is_empty());
    }
}
