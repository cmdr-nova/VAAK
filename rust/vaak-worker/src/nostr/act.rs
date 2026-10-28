//! Likes, replies and follow-list edits from Home cards.
//!
//! VAAK never holds a Nostr private key. The browser's signer extension
//! (NIP-07) signs. `prepare` builds the unsigned event and parks it in Redis
//! for ten minutes; `publish` takes the signed copy, checks that it is that
//! exact event from the linked key, and relays it. The only free field is a
//! reply's text, so a page script cannot get anything else relayed.
//!
//! A follow or unfollow replaces the whole kind 3 list, so `prepare` starts
//! from the newest list the relays hold, refuses one that looks older or
//! shorter than the last sync saw, and `publish` keeps the replaced list in
//! nostr_contact_backups.

use std::collections::HashSet;
use std::time::Duration;

use anyhow::{Context, Result};
use futures_util::StreamExt;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use tokio::time::Instant;
use tokio_postgres::Client;

use super::event::{self, Event};
use super::relay::{self, Query};
use crate::config::Config;

const PENDING_TTL_SECS: u64 = 600;
const CLOCK_SLACK_SECS: i64 = 600;
const MAX_REPLY_CHARS: usize = 8000;
const MAX_PUBLISH_RELAYS: usize = 8;
const RELAY_DEADLINE: Duration = Duration::from_secs(12);
const BACKUPS_KEPT: i64 = 20;

/// What `prepare` promised the browser it would relay.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct Pending {
    pub purpose: String,
    pub target: String,
    pub kind: i64,
    pub tags: Vec<Vec<String>>,
    /// None: the user writes it (replies).
    pub content: Option<String>,
    /// The kind 3 list a follow edit starts from.
    pub base: Option<Event>,
    pub relays: Vec<String>,
}

fn refuse(message: &str) -> Result<Value> {
    Ok(json!({"ok": false, "error": message}))
}

fn pending_key(owner: i64, nonce: &str) -> String {
    format!("vaak:nostr:pending:{owner}:{nonce}")
}

fn tag(parts: &[&str]) -> Vec<String> {
    parts.iter().map(|s| s.to_string()).collect()
}

/// `e` tag with the relay hint only when there is one.
fn e_tag(id: &str, relay: &str, marker: Option<&str>) -> Vec<String> {
    let mut t = vec!["e".to_string(), id.to_string(), relay.to_string()];
    if let Some(m) = marker {
        t.push(m.to_string());
    } else if relay.is_empty() {
        t.pop();
    }
    t
}

/// NIP-10 marked tags for a reply to `parent`: the thread root (the
/// parent's root, or the parent itself) and the parent as "reply", then a
/// `p` for the parent's author and everyone it tagged, minus `me`.
pub fn reply_tags(parent_id: &str, parent_author: &str, parent_tags: &[Vec<String>], relay: &str, me: &str) -> Vec<Vec<String>> {
    let mut root = None;
    let mut first_e = None;
    for t in parent_tags.iter().filter(|t| t.first().map(String::as_str) == Some("e")) {
        let Some(id) = t.get(1).filter(|id| event::is_hex64(id)) else { continue };
        if t.get(3).map(String::as_str) == Some("root") {
            root = Some((id.clone(), t.get(2).cloned().unwrap_or_default()));
        }
        first_e.get_or_insert_with(|| (id.clone(), t.get(2).cloned().unwrap_or_default()));
    }
    // Old positional replies: the first e tag is the root.
    let root = root.or(first_e);
    let mut tags = Vec::new();
    match root {
        Some((root_id, root_relay)) if root_id != parent_id => {
            let hint = if super::relay::normalize_relay_url(&root_relay).is_some() { root_relay } else { String::new() };
            tags.push(e_tag(&root_id, &hint, Some("root")));
            tags.push(e_tag(parent_id, relay, Some("reply")));
        }
        _ => tags.push(e_tag(parent_id, relay, Some("root"))),
    }
    let mut seen = HashSet::new();
    let mut people = vec![parent_author.to_string()];
    people.extend(
        parent_tags
            .iter()
            .filter(|t| t.first().map(String::as_str) == Some("p"))
            .filter_map(|t| t.get(1).map(|p| p.to_ascii_lowercase())),
    );
    for p in people {
        if event::is_hex64(&p) && p != me && seen.insert(p.clone()) {
            tags.push(vec!["p".into(), p]);
        }
    }
    tags
}

/// The base list's tags with `who` added (at the end) or every `p` for it
/// removed. Other tags stay as they were, in order.
pub fn edit_follow_tags(base: &[Vec<String>], who: &str, add: bool) -> Result<Vec<Vec<String>>, &'static str> {
    let is_who = |t: &Vec<String>| t.first().map(String::as_str) == Some("p") && t.get(1).is_some_and(|p| p.eq_ignore_ascii_case(who));
    let present = base.iter().any(is_who);
    match (add, present) {
        (true, true) => Err("You already follow them on Nostr."),
        (false, false) => Err("You don't follow them on Nostr."),
        (true, false) => {
            let mut out = base.to_vec();
            out.push(tag(&["p", who]));
            Ok(out)
        }
        (false, true) => Ok(base.iter().filter(|t| !is_who(t)).cloned().collect()),
    }
}

/// A fetched list is refused when it is older than the last one the sync
/// stored, or far shorter (a relay holding a stale copy).
pub fn base_list_problem(base: &Event, stored_count: i64, stored_at: i64) -> Option<String> {
    if base.created_at < stored_at {
        return Some("Relays only returned an older copy of your follow list. Try again in a minute so no follows get lost.".into());
    }
    let have = base.follow_list().len() as i64;
    if stored_count >= 20 && have * 10 < stored_count * 9 {
        return Some(format!(
            "Relays returned a follow list with {have} people, but VAAK last saw {stored_count}. Not changing it, so no follows get lost."
        ));
    }
    None
}

/// The signed event must be the prepared one, from the linked key, signed
/// recently. Returns the user's reply text check too.
pub fn check_signed(ev: &Event, pending: &Pending, linked: &str, now: i64) -> Result<(), String> {
    ev.verify().map_err(|e| format!("The signature didn't check out ({e})."))?;
    if ev.pubkey != linked {
        return Err("Your signer used a different key than the npub linked in Settings > Profile.".into());
    }
    if ev.kind != pending.kind || ev.tags != pending.tags {
        return Err("The signed event isn't the one VAAK prepared.".into());
    }
    match &pending.content {
        Some(c) if &ev.content != c => return Err("The signed event isn't the one VAAK prepared.".into()),
        None if ev.content.trim().is_empty() => return Err("Write something first.".into()),
        None if ev.content.chars().count() > MAX_REPLY_CHARS => return Err("That reply is too long.".into()),
        _ => {}
    }
    if (ev.created_at - now).abs() > CLOCK_SLACK_SECS {
        return Err("The signed event's time is off by more than ten minutes. Check your computer's clock.".into());
    }
    Ok(())
}

async fn linked_pubkey(db: &Client, owner: i64) -> Result<Option<(String, i64, i64)>> {
    Ok(db
        .query_opt("SELECT pubkey, follow_count::bigint, follow_list_at FROM nostr_links WHERE owner_user_id = $1", &[&owner])
        .await
        .context("nostr link")?
        .map(|r| (r.get(0), r.get(1), r.get(2)))
        .filter(|(pk, _, _): &(String, i64, i64)| event::is_hex64(pk)))
}

async fn write_relays_of(db: &Client, pubkeys: &[String]) -> Vec<String> {
    let mut out = Vec::new();
    if let Ok(rows) = db.query("SELECT write_relays FROM nostr_profiles WHERE pubkey = ANY($1)", &[&pubkeys]).await {
        for row in rows {
            let list: Value = row.get(0);
            for url in list.as_array().into_iter().flatten().filter_map(Value::as_str).filter_map(relay::normalize_relay_url) {
                if !out.contains(&url) {
                    out.push(url);
                }
            }
        }
    }
    out
}

fn merge_relays(lists: &[&[String]]) -> Vec<String> {
    let mut out: Vec<String> = Vec::new();
    for list in lists {
        for url in list.iter() {
            if !out.contains(url) {
                out.push(url.clone());
            }
        }
    }
    out.truncate(MAX_PUBLISH_RELAYS);
    out
}

/// Newest verified kind 3 for `me` across `relays`, and how many answered.
async fn newest_contacts(me: &str, relays: &[String]) -> (Option<Event>, usize) {
    let deadline = Instant::now() + RELAY_DEADLINE;
    let query = vec![Query::new(vec![me.to_string()], vec![3], None, 4)];
    let results: Vec<_> = futures_util::stream::iter(relays.iter().cloned().map(|url| {
        let query = query.clone();
        async move { relay::fetch(&url, &query, deadline).await }
    }))
    .buffer_unordered(6)
    .collect()
    .await;
    let mut answered = 0;
    let mut events = Vec::new();
    for res in results.into_iter().flatten() {
        answered += usize::from(res.completed > 0);
        events.extend(res.events.into_iter().filter(|e| e.pubkey == me && e.kind == 3 && e.verify().is_ok()));
    }
    let newest = relay::newest_by_author_kind(events).remove(&(me.to_string(), 3));
    (newest, answered)
}

pub async fn prepare(cfg: &Config, owner: i64, purpose: &str, target: &str) -> Result<Value> {
    if super::sync::disabled_by_env() {
        return refuse("Nostr is turned off on this server.");
    }
    let db = crate::db::connect(&cfg.database_url).await?;
    if !super::sync::tables_ready(&db).await {
        return refuse("Nostr isn't set up on this server yet.");
    }
    let Some((me, stored_count, stored_at)) = linked_pubkey(&db, owner).await? else {
        return refuse("Link your npub in Settings > Profile first.");
    };
    let target = target.trim().to_ascii_lowercase();
    if !event::is_hex64(&target) {
        return refuse("That isn't a Nostr note or person.");
    }
    let s = super::sync::Settings::from_env(30);
    let mut pending = match purpose {
        "like" | "reply" => {
            let Some(row) = db
                .query_opt("SELECT pubkey, relay, tags FROM nostr_events WHERE id = $1", &[&target])
                .await
                .context("nostr note for action")?
            else {
                return refuse("That note is no longer stored here.");
            };
            let author: String = row.get(0);
            let relay_hint: String = row.get(1);
            let relay_hint = if relay::normalize_relay_url(&relay_hint).is_some() { relay_hint } else { String::new() };
            let note_tags: Vec<Vec<String>> = serde_json::from_value(row.get::<_, Value>(2)).unwrap_or_default();
            let mine = write_relays_of(&db, std::slice::from_ref(&me)).await;
            let theirs = write_relays_of(&db, std::slice::from_ref(&author)).await;
            let relays = merge_relays(&[&mine, &theirs, &s.fallback_relays]);
            if purpose == "reply" {
                Pending {
                    purpose: "reply".into(),
                    target: target.clone(),
                    kind: 1,
                    tags: reply_tags(&target, &author, &note_tags, &relay_hint, &me),
                    content: None,
                    base: None,
                    relays,
                }
            } else {
                let liked: Option<String> = db
                    .query_opt("SELECT reaction_id FROM nostr_reactions WHERE owner_user_id = $1 AND note_id = $2", &[&owner, &target])
                    .await
                    .context("nostr reaction")?
                    .map(|r| r.get(0));
                match liked {
                    // NIP-09: ask relays to drop our own reaction.
                    Some(reaction) => Pending {
                        purpose: "unlike".into(),
                        target: target.clone(),
                        kind: 5,
                        tags: vec![tag(&["e", &reaction]), tag(&["k", "7"])],
                        content: Some(String::new()),
                        base: None,
                        relays,
                    },
                    None => Pending {
                        purpose: "like".into(),
                        target: target.clone(),
                        kind: 7,
                        tags: vec![e_tag(&target, &relay_hint, None), tag(&["p", &author]), tag(&["k", "1"])],
                        content: Some("+".into()),
                        base: None,
                        relays,
                    },
                }
            }
        }
        "follow" | "unfollow" => {
            if target == me {
                return refuse("That's you.");
            }
            let mine = write_relays_of(&db, std::slice::from_ref(&me)).await;
            let read_from = merge_relays(&[&mine, &s.index_relays]);
            let (base, answered) = newest_contacts(&me, &read_from).await;
            let Some(base) = base else {
                return refuse(if answered == 0 {
                    "No relay answered. Try again in a minute."
                } else {
                    "Couldn't find your current follow list on any relay, so VAAK won't risk replacing it."
                });
            };
            if let Some(problem) = base_list_problem(&base, stored_count, stored_at) {
                return refuse(&problem);
            }
            let tags = match edit_follow_tags(&base.tags, &target, purpose == "follow") {
                Ok(tags) => tags,
                Err(e) => return refuse(e),
            };
            Pending {
                purpose: purpose.into(),
                target: target.clone(),
                kind: 3,
                tags,
                content: Some(base.content.clone()),
                base: Some(base),
                relays: merge_relays(&[&mine, &s.index_relays, &s.fallback_relays]),
            }
        }
        _ => return refuse("Unknown Nostr action."),
    };
    if pending.relays.is_empty() {
        pending.relays = s.fallback_relays.clone();
    }
    let nonce = uuid::Uuid::new_v4().simple().to_string();
    let mut redis = crate::redis_util::connect(&cfg.redis_url).await?;
    let _: () = redis::cmd("SET")
        .arg(pending_key(owner, &nonce))
        .arg(serde_json::to_string(&pending)?)
        .arg("EX")
        .arg(PENDING_TTL_SECS)
        .query_async(&mut redis)
        .await
        .context("nostr pending")?;
    Ok(json!({
        "ok": true,
        "nonce": nonce,
        "pubkey": me,
        "purpose": pending.purpose,
        "needs_content": pending.content.is_none(),
        "template": {
            "kind": pending.kind,
            "tags": pending.tags,
            "content": pending.content.clone().unwrap_or_default(),
        },
    }))
}

pub async fn publish(cfg: &Config, owner: i64, nonce: &str, signed: &Value) -> Result<Value> {
    if super::sync::disabled_by_env() {
        return refuse("Nostr is turned off on this server.");
    }
    let nonce: String = nonce.chars().filter(char::is_ascii_hexdigit).take(32).collect();
    let mut redis = crate::redis_util::connect(&cfg.redis_url).await?;
    let raw: Option<String> = redis::cmd("GETDEL")
        .arg(pending_key(owner, &nonce))
        .query_async(&mut redis)
        .await
        .context("nostr pending read")?;
    let Some(pending) = raw.and_then(|r| serde_json::from_str::<Pending>(&r).ok()) else {
        return refuse("That action expired. Try again.");
    };
    let Ok(ev) = serde_json::from_value::<Event>(signed.clone()) else {
        return refuse("Your signer didn't return a signed event.");
    };
    let db = crate::db::connect(&cfg.database_url).await?;
    let Some((me, _, _)) = linked_pubkey(&db, owner).await? else {
        return refuse("Link your npub in Settings > Profile first.");
    };
    let now = chrono::Utc::now().timestamp();
    if let Err(e) = check_signed(&ev, &pending, &me, now) {
        return refuse(&e);
    }
    if let Some(base) = &pending.base {
        db.execute(
            "INSERT INTO nostr_contact_backups (owner_user_id, event_id, event_json, follow_count, backed_up_at)
             VALUES ($1, $2, $3, $4, $5)",
            &[&owner, &base.id, &serde_json::to_value(base)?, &(base.follow_list().len() as i32), &now],
        )
        .await
        .context("nostr follow list backup")?;
        db.execute(
            "DELETE FROM nostr_contact_backups WHERE owner_user_id = $1 AND id NOT IN (
                 SELECT id FROM nostr_contact_backups WHERE owner_user_id = $1 ORDER BY id DESC LIMIT $2)",
            &[&owner, &BACKUPS_KEPT],
        )
        .await
        .context("nostr follow list backup prune")?;
    }
    let deadline = Instant::now() + RELAY_DEADLINE;
    let results: Vec<(String, Result<(bool, String)>)> = futures_util::stream::iter(pending.relays.iter().cloned().map(|url| {
        let ev = ev.clone();
        async move {
            let res = relay::publish(&url, &ev, deadline).await;
            (url, res)
        }
    }))
    .buffer_unordered(6)
    .collect()
    .await;
    let mut accepted = 0usize;
    let mut problems = Vec::new();
    for (url, res) in &results {
        match res {
            Ok((true, _)) => accepted += 1,
            Ok((false, msg)) => problems.push(format!("{url}: {msg}")),
            Err(e) => problems.push(format!("{url}: {e:#}")),
        }
    }
    tracing::info!(owner, purpose = %pending.purpose, accepted, asked = results.len(), "nostr publish");
    if accepted == 0 {
        tracing::warn!(owner, "nostr publish refused everywhere: {}", problems.join(" | "));
        return Ok(json!({
            "ok": false,
            "error": "No relay accepted it. Try again in a minute.",
            "asked": results.len(),
        }));
    }
    match pending.purpose.as_str() {
        "like" => {
            db.execute(
                "INSERT INTO nostr_reactions (owner_user_id, note_id, reaction_id, created_at) VALUES ($1, $2, $3, $4)
                 ON CONFLICT (owner_user_id, note_id) DO UPDATE SET reaction_id = EXCLUDED.reaction_id, created_at = EXCLUDED.created_at",
                &[&owner, &pending.target, &ev.id, &ev.created_at],
            )
            .await?;
        }
        "unlike" => {
            db.execute("DELETE FROM nostr_reactions WHERE owner_user_id = $1 AND note_id = $2", &[&owner, &pending.target])
                .await?;
        }
        "follow" | "unfollow" => {
            let list = ev.follow_list();
            let tx_sql = if pending.purpose == "follow" {
                "INSERT INTO nostr_follows (owner_user_id, pubkey) VALUES ($1, $2) ON CONFLICT DO NOTHING"
            } else {
                "DELETE FROM nostr_follows WHERE owner_user_id = $1 AND pubkey = $2"
            };
            db.execute(tx_sql, &[&owner, &pending.target]).await?;
            db.execute(
                "UPDATE nostr_links SET follow_count = $2, follow_list_at = $3 WHERE owner_user_id = $1",
                &[&owner, &(list.len() as i32), &ev.created_at],
            )
            .await?;
        }
        _ => {}
    }
    Ok(json!({
        "ok": true,
        "purpose": pending.purpose,
        "accepted": accepted,
        "asked": results.len(),
        "note": if pending.purpose == "reply" { Value::String(super::bech32::note(&ev.id)) } else { Value::Null },
    }))
}

/// Viewer state on painted Nostr cards: `vaak_nostr_liked` and
/// `vaak_nostr_following`. Read at paint time so a cached page still shows
/// a like or unfollow made a moment ago.
pub async fn stamp_viewer_flags(db: &Client, owner: i64, statuses: &mut [Value]) {
    let ids: Vec<String> = statuses
        .iter()
        .filter(|s| super::render::is_nostr_status(s))
        .filter_map(|s| s.get("vaak_nostr_event_id").and_then(Value::as_str).map(str::to_owned))
        .collect();
    if ids.is_empty() || owner < 1 || !super::sync::tables_ready(db).await {
        return;
    }
    let authors: Vec<String> = statuses
        .iter()
        .filter_map(|s| s.get("vaak_nostr_pubkey").and_then(Value::as_str).map(str::to_owned))
        .collect();
    let liked: HashSet<String> = match db
        .query("SELECT note_id FROM nostr_reactions WHERE owner_user_id = $1 AND note_id = ANY($2)", &[&owner, &ids])
        .await
    {
        Ok(rows) => rows.iter().map(|r| r.get(0)).collect(),
        Err(_) => return,
    };
    let following: HashSet<String> = db
        .query("SELECT pubkey FROM nostr_follows WHERE owner_user_id = $1 AND pubkey = ANY($2)", &[&owner, &authors])
        .await
        .map(|rows| rows.iter().map(|r| r.get(0)).collect())
        .unwrap_or_default();
    for st in statuses.iter_mut().filter(|s| super::render::is_nostr_status(s)) {
        let id = st.get("vaak_nostr_event_id").and_then(Value::as_str).unwrap_or("").to_string();
        let pk = st.get("vaak_nostr_pubkey").and_then(Value::as_str).unwrap_or("").to_string();
        st["vaak_nostr_liked"] = json!(liked.contains(&id));
        st["vaak_nostr_following"] = json!(following.contains(&pk));
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::nostr::event::tests::signed;

    const A: &str = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
    const B: &str = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb";
    const C: &str = "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc";
    const R: &str = "1111111111111111111111111111111111111111111111111111111111111111";
    const P: &str = "2222222222222222222222222222222222222222222222222222222222222222";

    #[test]
    fn reply_to_a_top_level_note_marks_it_root() {
        let tags = reply_tags(P, A, &[], "wss://nos.lol", C);
        assert_eq!(tags, vec![tag(&["e", P, "wss://nos.lol", "root"]), tag(&["p", A])]);
    }

    #[test]
    fn reply_in_a_thread_keeps_the_root_and_tags_everyone_but_me() {
        let parent_tags = vec![tag(&["e", R, "wss://relay.damus.io", "root"]), tag(&["p", B]), tag(&["p", C]), tag(&["p", A])];
        let tags = reply_tags(P, A, &parent_tags, "", C);
        assert_eq!(
            tags,
            vec![
                tag(&["e", R, "wss://relay.damus.io", "root"]),
                tag(&["e", P, "", "reply"]),
                tag(&["p", A]),
                tag(&["p", B]),
            ]
        );
    }

    #[test]
    fn positional_parent_uses_first_e_as_root_and_drops_bad_hints() {
        let parent_tags = vec![tag(&["e", R, "javascript:x"]), tag(&["e", B])];
        let tags = reply_tags(P, A, &parent_tags, "", C);
        assert_eq!(tags[0], tag(&["e", R, "", "root"]));
        assert_eq!(tags[1], tag(&["e", P, "", "reply"]));
    }

    #[test]
    fn follow_edit_appends_or_removes_only_that_key() {
        let base = vec![tag(&["p", A, "wss://x.example", "alice"]), tag(&["t", "nostr"]), tag(&["p", B])];
        let added = edit_follow_tags(&base, C, true).unwrap();
        assert_eq!(added.len(), 4);
        assert_eq!(&added[..3], &base[..]);
        assert_eq!(added[3], tag(&["p", C]));
        let removed = edit_follow_tags(&base, A, false).unwrap();
        assert_eq!(removed, vec![tag(&["t", "nostr"]), tag(&["p", B])]);
        assert!(edit_follow_tags(&base, B, true).is_err());
        assert!(edit_follow_tags(&base, C, false).is_err());
    }

    #[test]
    fn stale_or_short_follow_lists_are_refused() {
        let p_tags = |n: usize| (0..n).map(|i| tag(&["p", &format!("{i:064x}")])).collect::<Vec<_>>();
        let full = signed(1, 1000, 3, p_tags(100), "");
        assert_eq!(base_list_problem(&full, 100, 1000), None);
        assert!(base_list_problem(&full, 100, 1001).unwrap().contains("older"));
        let short = signed(1, 2000, 3, p_tags(80), "");
        assert!(base_list_problem(&short, 100, 1000).unwrap().contains("80"));
        let few = signed(1, 2000, 3, p_tags(3), "");
        assert_eq!(base_list_problem(&few, 10, 1000), None, "small lists can shrink");
    }

    fn pending_like(ev_tags: Vec<Vec<String>>) -> Pending {
        Pending {
            purpose: "like".into(),
            target: P.into(),
            kind: 7,
            tags: ev_tags,
            content: Some("+".into()),
            base: None,
            relays: vec![],
        }
    }

    #[test]
    fn only_the_prepared_event_from_the_linked_key_passes() {
        let tags = vec![tag(&["e", P]), tag(&["p", A]), tag(&["k", "1"])];
        let ev = signed(7, 5000, 7, tags.clone(), "+");
        let me = ev.pubkey.clone();
        assert_eq!(check_signed(&ev, &pending_like(tags.clone()), &me, 5000), Ok(()));
        assert!(check_signed(&ev, &pending_like(tags.clone()), A, 5000).unwrap_err().contains("different key"));
        let other = signed(7, 5000, 7, vec![tag(&["p", B])], "+");
        assert!(check_signed(&other, &pending_like(tags.clone()), &me, 5000).unwrap_err().contains("prepared"));
        let text = signed(7, 5000, 7, tags.clone(), "spam");
        assert!(check_signed(&text, &pending_like(tags.clone()), &me, 5000).is_err());
        assert!(check_signed(&ev, &pending_like(tags.clone()), &me, 9000).unwrap_err().contains("clock"));
        let mut forged = ev.clone();
        forged.content = "-".into();
        assert!(check_signed(&forged, &pending_like(tags), &me, 5000).unwrap_err().contains("signature"));
    }

    #[test]
    fn replies_need_text() {
        let tags = vec![tag(&["e", P, "", "root"]), tag(&["p", A])];
        let mut pending = pending_like(tags.clone());
        pending.kind = 1;
        pending.content = None;
        let empty = signed(7, 5000, 1, tags.clone(), "  ");
        assert!(check_signed(&empty, &pending, &empty.pubkey, 5000).is_err());
        let ok = signed(7, 5000, 1, tags, "hello");
        assert_eq!(check_signed(&ok, &pending, &ok.pubkey, 5000), Ok(()));
    }
}
