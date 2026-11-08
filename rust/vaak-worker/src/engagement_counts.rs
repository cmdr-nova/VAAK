//! Engagement totals (likes, boosts, replies, quotes) for painted statuses.
//!
//! Before this pass each builder filled counts its own way: Bluesky cards
//! from the AppView snapshot in `bsky_posts` (without quotes), Fediverse and
//! local cards always 0, Nostr cards never painted theirs. The same post
//! could show numbers on one surface and none on another.
//!
//! Every painter runs `interaction_flags::apply_to_statuses`, which calls
//! [`apply`]. It adds up what VAAK knows per network and never lowers a
//! number a status already carries (Mastodon API statuses arrive with the
//! origin server's totals):
//!
//! - Bluesky: `bsky_posts` AppView counts. VAAK accounts' own Bluesky likes
//!   and reposts are part of the AppView count once it refreshes, so the
//!   local rows only raise it (max), never add to it.
//! - Fediverse: distinct remote actors from inbox `Like`/`Announce` events,
//!   plus distinct VAAK accounts from `masto_favourites`/`masto_reblogs`;
//!   replies from stored `Create` events and local notes; quotes from
//!   `event_quote_targets`.
//! - Nostr: relay-counted likes/reposts in `nostr_note_counts`, and replies
//!   and reposts VAAK has stored.
//!
//! A post that exists on two networks (Wafrn twins in `bsky_post_links`,
//! VAAK crossposts in `bsky_crossposts`) shows the sum of both copies.

use std::collections::{HashMap, HashSet};

use serde_json::{json, Value};
use tokio_postgres::Client;

use crate::interaction_flags::{collect_keys, expand_twins, push_key, trim_slash, Twins};

const LOCAL_ACTOR_PREFIX: &str = "https://mkultra.monster/";

#[derive(Debug, Default, Clone, Copy, PartialEq, Eq)]
pub(crate) struct Totals {
    pub favourites: i64,
    pub reblogs: i64,
    pub replies: i64,
    pub quotes: i64,
}

impl Totals {
    fn add(&mut self, other: Totals) {
        self.favourites += other.favourites;
        self.reblogs += other.reblogs;
        self.replies += other.replies;
        self.quotes += other.quotes;
    }
    fn max(&mut self, other: Totals) {
        self.favourites = self.favourites.max(other.favourites);
        self.reblogs = self.reblogs.max(other.reblogs);
        self.replies = self.replies.max(other.replies);
        self.quotes = self.quotes.max(other.quotes);
    }
}

/// Lookup keys for one status, split by network. All trimmed of a trailing
/// slash.
#[derive(Debug, Default)]
struct StatusKeys {
    at: HashSet<String>,
    bsky_links: HashSet<String>,
    fedi: HashSet<String>,
    nostr: Option<String>,
}

fn is_hex64(s: &str) -> bool {
    s.len() == 64 && s.bytes().all(|b| b.is_ascii_hexdigit())
}

fn nostr_id(st: &Value) -> Option<String> {
    let raw = st
        .get("vaak_nostr_event_id")
        .and_then(Value::as_str)
        .map(str::to_string)
        .or_else(|| {
            st.get("id")
                .and_then(Value::as_str)
                .and_then(|id| id.strip_prefix("nostr:"))
                .map(str::to_string)
        })?;
    let raw = raw.to_ascii_lowercase();
    is_hex64(&raw).then_some(raw)
}

fn keys_for(st: &Value, twins: &Twins) -> StatusKeys {
    let mut sids = HashSet::new();
    let mut oids = HashSet::new();
    collect_keys_shallow(st, &mut sids, &mut oids);
    expand_twins(twins, &mut sids, &mut oids);
    let mut keys = StatusKeys { nostr: nostr_id(st), ..StatusKeys::default() };
    for key in oids {
        let key = trim_slash(&key);
        if key.is_empty() {
            continue;
        }
        if key.starts_with("at://") {
            if key.contains("/app.bsky.feed.post/") {
                keys.at.insert(key.to_string());
            }
        } else if key.starts_with("https://bsky.app/") {
            keys.bsky_links.insert(key.to_string());
        } else if key.starts_with("https://") || key.starts_with("http://") {
            // Announce wrappers are VAAK's own ids, not the post.
            if key.contains("#announce-") || key.contains("/announces/") {
                continue;
            }
            keys.fedi.insert(key.to_string());
        }
    }
    keys
}

/// `collect_keys` without descending into a nested reblog: a boost wrapper's
/// counts belong to the boosted post, which gets its own pass.
fn collect_keys_shallow(st: &Value, sids: &mut HashSet<String>, oids: &mut HashSet<String>) {
    if st.get("reblog").is_some_and(Value::is_object) {
        let mut flat = st.clone();
        if let Some(obj) = flat.as_object_mut() {
            obj.remove("reblog");
        }
        collect_keys(&flat, sids, oids);
    } else {
        collect_keys(st, sids, oids);
    }
}

fn with_slash_variants<'a>(keys: impl Iterator<Item = &'a String>) -> Vec<String> {
    let mut out = HashSet::new();
    for key in keys {
        push_key(&mut out, key);
    }
    out.into_iter().collect()
}

/// Loaded rows, keyed by slash-trimmed id.
#[derive(Debug, Default)]
struct Loaded {
    bsky: HashMap<String, Totals>,
    /// Remote Fediverse actors from inbox events.
    fedi_remote: HashMap<String, Totals>,
    /// VAAK accounts' likes / boosts: key -> owner ids.
    local_likes: HashMap<String, HashSet<i64>>,
    local_boosts: HashMap<String, HashSet<i64>>,
    fedi_replies: HashMap<String, i64>,
    fedi_quotes: HashMap<String, i64>,
    nostr: HashMap<String, Totals>,
    /// note id -> at:// URI of VAAK's own Bluesky crosspost.
    crossposts: HashMap<String, Vec<String>>,
}

async fn load(db: &Client, at: &[String], fedi: &[String], local_objects: &[String], nostr: &[String]) -> Loaded {
    let bsky_q = async {
        if at.is_empty() {
            return Vec::new();
        }
        db.query(
            "SELECT bsky_uri, COALESCE(like_count,0)::bigint, COALESCE(repost_count,0)::bigint,
                    COALESCE(reply_count,0)::bigint, COALESCE(quote_count,0)::bigint
             FROM bsky_posts WHERE bsky_uri = ANY($1)",
            &[&at],
        )
        .await
        .unwrap_or_default()
    };
    let events_q = async {
        if fedi.is_empty() {
            return Vec::new();
        }
        // idx_events_object_key_id covers rtrim(object_id, '/').
        db.query(
            "SELECT rtrim(object_id,'/'), type, count(DISTINCT rtrim(actor_id,'/'))::bigint
             FROM events
             WHERE type IN ('Like','Announce') AND rtrim(object_id,'/') = ANY($1)
               AND actor_id IS NOT NULL AND actor_id NOT LIKE 'https://mkultra.monster/%'
             GROUP BY 1, 2",
            &[&fedi],
        )
        .await
        .unwrap_or_default()
    };
    let local_q = async {
        if local_objects.is_empty() {
            return Vec::new();
        }
        db.query(
            "SELECT rtrim(object_id,'/'), owner_user_id::bigint, 'f' FROM masto_favourites WHERE object_id = ANY($1)
             UNION ALL
             SELECT rtrim(object_id,'/'), owner_user_id::bigint, 'r' FROM masto_reblogs WHERE object_id = ANY($1)",
            &[&local_objects],
        )
        .await
        .unwrap_or_default()
    };
    let replies_q = async {
        if fedi.is_empty() {
            return Vec::new();
        }
        let with_slash = with_slash_variants(fedi.iter());
        db.query(
            "SELECT k, count(DISTINCT o)::bigint FROM (
                SELECT rtrim(in_reply_to,'/') AS k, rtrim(object_id,'/') AS o FROM events
                 WHERE type = 'Create' AND in_reply_to = ANY($1) AND object_id IS NOT NULL
                UNION ALL
                SELECT rtrim(in_reply_to,'/'), rtrim(id,'/') FROM outbox_notes
                 WHERE in_reply_to = ANY($1)
             ) x GROUP BY k",
            &[&with_slash],
        )
        .await
        .unwrap_or_default()
    };
    let quotes_q = async {
        if fedi.is_empty() {
            return Vec::new();
        }
        let with_slash = with_slash_variants(fedi.iter());
        db.query(
            "SELECT rtrim(quote_target,'/'), count(DISTINCT rtrim(object_id,'/'))::bigint
             FROM event_quote_targets WHERE quote_target = ANY($1)
               AND rtrim(object_id,'/') <> rtrim(quote_target,'/')
             GROUP BY 1",
            &[&with_slash],
        )
        .await
        .unwrap_or_default()
    };
    let nostr_q = async {
        if nostr.is_empty() {
            return (Vec::new(), Vec::new());
        }
        let relay = db
            .query(
                "SELECT note_id, likes::bigint, reposts::bigint FROM nostr_note_counts WHERE note_id = ANY($1)",
                &[&nostr],
            )
            .await
            .unwrap_or_default();
        // Replies from other keys and reposts VAAK stored itself.
        let local = db
            .query(
                "SELECT r.reply_to, r.kind::bigint, count(DISTINCT r.pubkey)::bigint
                 FROM nostr_events r JOIN nostr_events e ON e.id = r.reply_to
                 WHERE r.reply_to = ANY($1)
                   AND (r.kind = 6 OR (r.kind = 1 AND r.pubkey <> e.pubkey))
                 GROUP BY 1, 2",
                &[&nostr],
            )
            .await
            .unwrap_or_default();
        (relay, local)
    };
    let crosspost_q = async {
        if fedi.is_empty() {
            return Vec::new();
        }
        let with_slash = with_slash_variants(fedi.iter());
        db.query(
            "SELECT rtrim(note_id,'/'), bsky_uri FROM bsky_crossposts
             WHERE note_id = ANY($1) AND bsky_uri LIKE 'at://%'",
            &[&with_slash],
        )
        .await
        .unwrap_or_default()
    };
    let (bsky_rows, event_rows, local_rows, reply_rows, quote_rows, (nostr_relay, nostr_local), crosspost_rows) =
        tokio::join!(bsky_q, events_q, local_q, replies_q, quotes_q, nostr_q, crosspost_q);

    let mut out = Loaded::default();
    for row in bsky_rows {
        let uri: String = row.get(0);
        out.bsky.insert(
            trim_slash(&uri).to_string(),
            Totals { favourites: row.get(1), reblogs: row.get(2), replies: row.get(3), quotes: row.get(4) },
        );
    }
    for row in event_rows {
        let key: String = row.get(0);
        let kind: String = row.get(1);
        let n: i64 = row.get(2);
        let t = out.fedi_remote.entry(key).or_default();
        if kind == "Like" {
            t.favourites = t.favourites.max(n);
        } else {
            t.reblogs = t.reblogs.max(n);
        }
    }
    for row in local_rows {
        let key: Option<String> = row.get(0);
        let Some(key) = key else { continue };
        let owner: i64 = row.get(1);
        let kind: &str = row.get(2);
        let map = if kind == "f" { &mut out.local_likes } else { &mut out.local_boosts };
        map.entry(key).or_default().insert(owner);
    }
    for row in reply_rows {
        let key: Option<String> = row.get(0);
        if let Some(key) = key {
            out.fedi_replies.insert(key, row.get(1));
        }
    }
    for row in quote_rows {
        let key: String = row.get(0);
        out.fedi_quotes.insert(key, row.get(1));
    }
    for row in nostr_relay {
        let id: String = row.get(0);
        let t = out.nostr.entry(id).or_default();
        t.favourites = t.favourites.max(row.get(1));
        t.reblogs = t.reblogs.max(row.get(2));
    }
    for row in nostr_local {
        let id: String = row.get(0);
        let kind: i64 = row.get(1);
        let n: i64 = row.get(2);
        let t = out.nostr.entry(id).or_default();
        if kind == 6 {
            t.reblogs = t.reblogs.max(n);
        } else {
            t.replies = t.replies.max(n);
        }
    }
    for row in crosspost_rows {
        let note: String = row.get(0);
        let uri: String = row.get(1);
        let list = out.crossposts.entry(note).or_default();
        let uri = trim_slash(&uri).to_string();
        if !list.contains(&uri) {
            list.push(uri);
        }
    }
    out
}

fn owners_for<'a>(map: &'a HashMap<String, HashSet<i64>>, keys: impl Iterator<Item = &'a String>) -> usize {
    let mut owners = HashSet::new();
    for key in keys {
        if let Some(set) = map.get(key) {
            owners.extend(set.iter().copied());
        }
    }
    owners.len()
}

/// Totals for one status from loaded rows. Within a network the id forms of
/// one post (slash variants, Wafrn activity vs Note id) are the same post,
/// so they take the max; networks add up.
fn totals_for(keys: &StatusKeys, loaded: &Loaded) -> Totals {
    let mut at = keys.at.clone();
    for note in &keys.fedi {
        if let Some(list) = loaded.crossposts.get(note) {
            at.extend(list.iter().cloned());
        }
    }

    let mut bsky = Totals::default();
    for uri in &at {
        if let Some(t) = loaded.bsky.get(uri) {
            bsky.max(*t);
        }
    }
    // VAAK accounts' Bluesky likes/reposts: already inside the AppView count
    // once it refreshes, so they only raise it.
    let bsky_local_keys: Vec<&String> = at.iter().chain(keys.bsky_links.iter()).collect();
    bsky.favourites = bsky.favourites.max(owners_for(&loaded.local_likes, bsky_local_keys.iter().copied()) as i64);
    bsky.reblogs = bsky.reblogs.max(owners_for(&loaded.local_boosts, bsky_local_keys.iter().copied()) as i64);

    let mut fedi = Totals::default();
    for key in &keys.fedi {
        if let Some(t) = loaded.fedi_remote.get(key) {
            fedi.max(*t);
        }
        if let Some(n) = loaded.fedi_replies.get(key) {
            fedi.replies = fedi.replies.max(*n);
        }
        if let Some(n) = loaded.fedi_quotes.get(key) {
            fedi.quotes = fedi.quotes.max(*n);
        }
    }
    fedi.favourites += owners_for(&loaded.local_likes, keys.fedi.iter()) as i64;
    fedi.reblogs += owners_for(&loaded.local_boosts, keys.fedi.iter()) as i64;

    let mut total = bsky;
    total.add(fedi);
    if let Some(t) = keys.nostr.as_ref().and_then(|id| loaded.nostr.get(id)) {
        total.add(*t);
    }
    total
}

fn count_of(st: &Value, key: &str) -> i64 {
    match st.get(key) {
        Some(Value::Number(n)) => n.as_i64().unwrap_or(0),
        Some(Value::String(s)) => s.trim().parse().unwrap_or(0),
        _ => 0,
    }
    .max(0)
}

fn stamp(st: &mut Value, totals: Totals) {
    for (field, n) in [
        ("favourites_count", totals.favourites),
        ("reblogs_count", totals.reblogs),
        ("replies_count", totals.replies),
        ("quotes_count", totals.quotes),
    ] {
        let current = count_of(st, field);
        if n > current || st.get(field).is_none() {
            st[field] = json!(current.max(n));
        }
    }
}

/// Every status to count: each card, and the post inside a boost wrapper.
fn targets(statuses: &[Value]) -> Vec<(usize, bool)> {
    let mut out = Vec::new();
    for (i, st) in statuses.iter().enumerate() {
        if !st.is_object() {
            continue;
        }
        out.push((i, false));
        if st.get("reblog").is_some_and(Value::is_object) {
            out.push((i, true));
        }
    }
    out
}

/// Fill likes/boosts/replies/quotes on each status from every network VAAK
/// sees. Best effort: a failed query leaves that source out.
pub(crate) async fn apply(db: &Client, statuses: &mut [Value], twins: &Twins) {
    let targets = targets(statuses);
    if targets.is_empty() {
        return;
    }
    let keys: Vec<StatusKeys> = targets
        .iter()
        .map(|(i, nested)| {
            let st = &statuses[*i];
            let st = if *nested { &st["reblog"] } else { st };
            keys_for(st, twins)
        })
        .collect();
    let mut at = HashSet::new();
    let mut fedi = HashSet::new();
    let mut bsky_links = HashSet::new();
    let mut nostr = HashSet::new();
    for k in &keys {
        at.extend(k.at.iter().cloned());
        fedi.extend(k.fedi.iter().cloned());
        bsky_links.extend(k.bsky_links.iter().cloned());
        if let Some(id) = &k.nostr {
            nostr.insert(id.clone());
        }
    }
    let local_objects = with_slash_variants(at.iter().chain(fedi.iter()).chain(bsky_links.iter()));
    let at: Vec<String> = at.into_iter().collect();
    let fedi: Vec<String> = fedi.into_iter().collect();
    let nostr: Vec<String> = nostr.into_iter().collect();
    let mut loaded = load(db, &at, &fedi, &local_objects, &nostr).await;
    // Crosspost URIs not already requested.
    let extra: Vec<String> = loaded
        .crossposts
        .values()
        .flatten()
        .filter(|uri| !loaded.bsky.contains_key(*uri))
        .cloned()
        .collect::<HashSet<_>>()
        .into_iter()
        .collect();
    if !extra.is_empty() {
        if let Ok(rows) = db
            .query(
                "SELECT bsky_uri, COALESCE(like_count,0)::bigint, COALESCE(repost_count,0)::bigint,
                        COALESCE(reply_count,0)::bigint, COALESCE(quote_count,0)::bigint
                 FROM bsky_posts WHERE bsky_uri = ANY($1)",
                &[&extra],
            )
            .await
        {
            for row in rows {
                let uri: String = row.get(0);
                loaded.bsky.insert(
                    trim_slash(&uri).to_string(),
                    Totals { favourites: row.get(1), reblogs: row.get(2), replies: row.get(3), quotes: row.get(4) },
                );
            }
        }
    }
    for ((i, nested), k) in targets.iter().zip(keys.iter()) {
        let totals = totals_for(k, &loaded);
        let st = &mut statuses[*i];
        if *nested {
            stamp(&mut st["reblog"], totals);
        } else {
            stamp(st, totals);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn set(owners: &[i64]) -> HashSet<i64> {
        owners.iter().copied().collect()
    }

    #[test]
    fn bluesky_card_gets_appview_counts_including_quotes() {
        let st = json!({"id": "at://did:plc:a/app.bsky.feed.post/x", "uri": "at://did:plc:a/app.bsky.feed.post/x",
            "url": "https://bsky.app/profile/did:plc:a/post/x", "favourites_count": 0});
        let keys = keys_for(&st, &Twins::new());
        let mut loaded = Loaded::default();
        loaded.bsky.insert("at://did:plc:a/app.bsky.feed.post/x".into(), Totals { favourites: 5, reblogs: 2, replies: 1, quotes: 3 });
        let t = totals_for(&keys, &loaded);
        assert_eq!(t, Totals { favourites: 5, reblogs: 2, replies: 1, quotes: 3 });
    }

    #[test]
    fn own_bluesky_like_raises_but_does_not_double_the_appview_count() {
        let st = json!({"id": "at://did:plc:a/app.bsky.feed.post/x", "uri": "at://did:plc:a/app.bsky.feed.post/x"});
        let keys = keys_for(&st, &Twins::new());
        let mut loaded = Loaded::default();
        loaded.bsky.insert("at://did:plc:a/app.bsky.feed.post/x".into(), Totals { favourites: 4, ..Totals::default() });
        loaded.local_likes.insert("at://did:plc:a/app.bsky.feed.post/x".into(), set(&[1]));
        assert_eq!(totals_for(&keys, &loaded).favourites, 4);
        // AppView snapshot taken before anyone liked it.
        loaded.bsky.insert("at://did:plc:a/app.bsky.feed.post/x".into(), Totals::default());
        assert_eq!(totals_for(&keys, &loaded).favourites, 1);
    }

    #[test]
    fn fediverse_card_adds_remote_actors_and_vaak_accounts() {
        let st = json!({"id": "1791", "uri": "https://example.social/users/a/statuses/1"});
        let keys = keys_for(&st, &Twins::new());
        let mut loaded = Loaded::default();
        let key = "https://example.social/users/a/statuses/1".to_string();
        loaded.fedi_remote.insert(key.clone(), Totals { favourites: 0, reblogs: 2, ..Totals::default() });
        loaded.local_likes.insert(key.clone(), set(&[1, 3]));
        loaded.local_boosts.insert(key.clone(), set(&[1]));
        loaded.fedi_replies.insert(key.clone(), 4);
        loaded.fedi_quotes.insert(key, 1);
        let t = totals_for(&keys, &loaded);
        assert_eq!(t, Totals { favourites: 2, reblogs: 3, replies: 4, quotes: 1 });
    }

    #[test]
    fn twin_copies_add_up_across_networks() {
        let fedi = "https://waf.example/fediverse/post/abc";
        let at = "at://did:plc:w/app.bsky.feed.post/y";
        let mut twins = Twins::new();
        twins.insert(fedi.into(), vec![at.into()]);
        twins.insert(at.into(), vec![fedi.into()]);
        let mut loaded = Loaded::default();
        loaded.bsky.insert(at.into(), Totals { favourites: 3, ..Totals::default() });
        loaded.fedi_remote.insert(fedi.into(), Totals { favourites: 0, reblogs: 1, ..Totals::default() });
        loaded.local_likes.insert(fedi.into(), set(&[2]));
        let from_fedi = totals_for(&keys_for(&json!({"id": "9", "uri": fedi}), &twins), &loaded);
        let from_bsky = totals_for(&keys_for(&json!({"id": at, "uri": at}), &twins), &loaded);
        assert_eq!(from_fedi, from_bsky);
        assert_eq!(from_fedi.favourites, 4);
        assert_eq!(from_fedi.reblogs, 1);
    }

    #[test]
    fn crossposted_local_note_includes_its_bluesky_copy() {
        let note = "https://mkultra.monster/users/cmdr_nova/notes/abc";
        let at = "at://did:plc:me/app.bsky.feed.post/z";
        let keys = keys_for(&json!({"id": "1", "uri": note}), &Twins::new());
        let mut loaded = Loaded::default();
        loaded.crossposts.insert(note.into(), vec![at.into()]);
        loaded.bsky.insert(at.into(), Totals { favourites: 7, reblogs: 1, replies: 2, quotes: 0 });
        loaded.fedi_remote.insert(note.into(), Totals { favourites: 3, ..Totals::default() });
        let t = totals_for(&keys, &loaded);
        assert_eq!(t, Totals { favourites: 10, reblogs: 1, replies: 2, quotes: 0 });
    }

    #[test]
    fn nostr_card_reads_relay_counts() {
        let id = "a".repeat(64);
        let st = json!({"id": format!("nostr:{id}"), "source": "nostr", "vaak_nostr_event_id": id});
        let keys = keys_for(&st, &Twins::new());
        let mut loaded = Loaded::default();
        loaded.nostr.insert(id, Totals { favourites: 12, reblogs: 2, replies: 1, quotes: 0 });
        assert_eq!(totals_for(&keys, &loaded).favourites, 12);
    }

    #[test]
    fn stamp_never_lowers_a_count_the_status_already_has() {
        let mut st = json!({"favourites_count": 40, "reblogs_count": 0});
        stamp(&mut st, Totals { favourites: 3, reblogs: 2, replies: 0, quotes: 1 });
        assert_eq!(st["favourites_count"], json!(40));
        assert_eq!(st["reblogs_count"], json!(2));
        assert_eq!(st["replies_count"], json!(0));
        assert_eq!(st["quotes_count"], json!(1));
    }

    #[test]
    fn boost_wrapper_ids_are_not_counted_as_the_post() {
        let st = json!({"id": "w", "uri": "https://mkultra.monster/users/cmdr_nova/announces/5",
            "reblog": {"id": "1", "uri": "https://example.social/users/a/statuses/1"}});
        let keys = keys_for(&st, &Twins::new());
        assert!(keys.fedi.is_empty());
        assert_eq!(targets(std::slice::from_ref(&st)), vec![(0, false), (0, true)]);
    }
}
