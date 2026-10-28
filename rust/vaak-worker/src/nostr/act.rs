//! Likes, replies, reposts, quotes, deletes, follow-list and mute-list
//! edits, and reports from Home cards.
//!
//! VAAK never holds a Nostr private key. The browser's signer extension
//! (NIP-07) signs. `prepare` builds the unsigned event and parks it in Redis
//! for ten minutes; `publish` takes the signed copy, checks that it is that
//! exact event from the linked key, and relays it. The only free field is the
//! text of a reply or quote (a quote must end with its `nostr:note1…`
//! reference), so a page script cannot get anything else relayed.
//!
//! A follow or unfollow replaces the whole kind 3 list, so `prepare` starts
//! from the newest list the relays hold, refuses one that looks older or
//! shorter than the last sync saw, and `publish` keeps the replaced list in
//! nostr_contact_backups. A mute or unmute edits the NIP-51 mute list
//! (kind 10000) the same way, keeping its encrypted private part untouched.
//! A report is a NIP-56 kind 1984 naming the note and its author.

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
const REPOST_KIND: i32 = 6;
const QUOTE_KIND: i32 = 1;
const MAX_PUBLISH_RELAYS: usize = 8;
const RELAY_DEADLINE: Duration = Duration::from_secs(12);
const BACKUPS_KEPT: i64 = 20;
const MUTE_LIST_KIND: i64 = 10000;
/// NIP-56 report types.
pub const REPORT_TYPES: [&str; 7] = ["nudity", "malware", "profanity", "illegal", "spam", "impersonation", "other"];

/// What `prepare` promised the browser it would relay.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct Pending {
    pub purpose: String,
    pub target: String,
    pub kind: i64,
    pub tags: Vec<Vec<String>>,
    /// None: the user writes it (replies, quotes).
    pub content: Option<String>,
    /// Text the user's content must end with (a quote's note reference).
    #[serde(default)]
    pub suffix: Option<String>,
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
    edit_p_tags(base, who, add, "You already follow them on Nostr.", "You don't follow them on Nostr.")
}

pub fn edit_mute_tags(base: &[Vec<String>], who: &str, add: bool) -> Result<Vec<Vec<String>>, &'static str> {
    edit_p_tags(base, who, add, "You already muted them on Nostr.", "They aren't on your Nostr mute list.")
}

fn edit_p_tags(base: &[Vec<String>], who: &str, add: bool, already: &'static str, missing: &'static str) -> Result<Vec<Vec<String>>, &'static str> {
    let is_who = |t: &Vec<String>| t.first().map(String::as_str) == Some("p") && t.get(1).is_some_and(|p| p.eq_ignore_ascii_case(who));
    let present = base.iter().any(is_who);
    match (add, present) {
        (true, true) => Err(already),
        (false, false) => Err(missing),
        (true, false) => {
            let mut out = base.to_vec();
            out.push(tag(&["p", who]));
            Ok(out)
        }
        (false, true) => Ok(base.iter().filter(|t| !is_who(t)).cloned().collect()),
    }
}

/// NIP-56 tags: the note and its author, each with the report type.
pub fn report_tags(note: &str, author: &str, reason: &str) -> Vec<Vec<String>> {
    vec![tag(&["e", note, reason]), tag(&["p", author, reason])]
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

/// NIP-18 kind 6 tags. The e tag needs a relay URL: the note's own, else
/// the first relay the repost goes to.
pub fn repost_tags(note: &str, author: &str, hint: &str, relays: &[String]) -> Vec<Vec<String>> {
    let relay = if hint.is_empty() { relays.first().map(String::as_str).unwrap_or("") } else { hint };
    vec![e_tag(note, relay, None), tag(&["p", author])]
}

/// NIP-18 quote tags: `q` with the relay and author, and a `p` so the
/// author is told (not when quoting yourself).
pub fn quote_tags(note: &str, author: &str, hint: &str, me: &str) -> Vec<Vec<String>> {
    let mut tags = vec![tag(&["q", note, hint, author])];
    if author != me {
        tags.push(tag(&["p", author]));
    }
    tags
}

/// What a quote's text ends with: NIP-21 reference to the quoted note.
pub fn quote_suffix(note: &str) -> String {
    format!("\n\nnostr:{}", super::bech32::note(note))
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
        None if ev.content.chars().count() > MAX_REPLY_CHARS => return Err("That's too long.".into()),
        None => {
            let text = match &pending.suffix {
                Some(suffix) => ev
                    .content
                    .strip_suffix(suffix.as_str())
                    .ok_or("The signed quote is missing its link to the quoted note.")?,
                None => ev.content.as_str(),
            };
            if text.trim().is_empty() {
                return Err("Write something first.".into());
            }
        }
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

/// Newest verified replaceable list (kind 3 or 10000) for `me` across
/// `relays`, and how many answered.
async fn newest_list(me: &str, kind: i64, relays: &[String]) -> (Option<Event>, usize) {
    let deadline = Instant::now() + RELAY_DEADLINE;
    let query = vec![Query::new(vec![me.to_string()], vec![kind], None, 4)];
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
        events.extend(res.events.into_iter().filter(|e| e.pubkey == me && e.kind == kind && e.verify().is_ok()));
    }
    let newest = relay::newest_by_author_kind(events).remove(&(me.to_string(), kind));
    (newest, answered)
}

/// A VAAK post going to Nostr too (purpose "post"). PHP builds it from the
/// stored post (api/ap-nostr.php `ap_nostr_post_note`); the browser only signs.
#[derive(Debug, Clone, Default, Deserialize)]
pub struct NewNote {
    pub content: String,
    pub tags: Vec<Vec<String>>,
}

/// Tags a crossposted note may carry: hashtags, a content warning, media
/// metadata, and the NIP-48 link back to the VAAK post.
fn note_tags_ok(tags: &[Vec<String>]) -> bool {
    tags.len() <= 40
        && tags.iter().all(|t| {
            matches!(t.first().map(String::as_str), Some("t" | "content-warning" | "imeta" | "proxy"))
                && t.len() >= 2
                && t.len() <= 8
                && t.iter().all(|v| v.chars().count() <= 2000)
        })
}

pub async fn prepare(cfg: &Config, owner: i64, purpose: &str, target: &str, reason: &str, note: Option<&NewNote>) -> Result<Value> {
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
    if purpose != "post" && !event::is_hex64(&target) {
        return refuse("That isn't a Nostr note or person.");
    }
    let mut s = super::sync::Settings::from_env(30);
    super::relay_book::apply(&db, &mut s, chrono::Utc::now().timestamp()).await?;
    let mut pending = match purpose {
        "post" => {
            let Some(note) = note.filter(|n| !n.content.trim().is_empty()) else {
                return refuse("There's nothing to post.");
            };
            if note.content.chars().count() > MAX_REPLY_CHARS || !note_tags_ok(&note.tags) {
                return refuse("That post can't go to Nostr.");
            }
            let mine = write_relays_of(&db, std::slice::from_ref(&me)).await;
            Pending {
                purpose: "post".into(),
                target: String::new(),
                kind: 1,
                tags: note.tags.clone(),
                content: Some(note.content.clone()),
                suffix: None,
                base: None,
                relays: merge_relays(&[&mine, &s.fallback_relays]),
            }
        }
        "like" | "reply" | "repost" | "quote" | "report" => {
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
            if purpose == "report" {
                if !REPORT_TYPES.contains(&reason) {
                    return refuse("Pick a reason for the report.");
                }
                if author == me {
                    return refuse("That's your own note.");
                }
                Pending {
                    purpose: "report".into(),
                    target: target.clone(),
                    kind: 1984,
                    tags: report_tags(&target, &author, reason),
                    content: Some(String::new()),
                    suffix: None,
                    base: None,
                    relays,
                }
            } else if purpose == "repost" {
                let shared: Option<String> = db
                    .query_opt(
                        "SELECT event_id FROM nostr_shares WHERE owner_user_id = $1 AND note_id = $2 AND kind = $3",
                        &[&owner, &target, &REPOST_KIND],
                    )
                    .await
                    .context("nostr repost")?
                    .map(|r| r.get(0));
                match shared {
                    Some(repost) => Pending {
                        purpose: "unrepost".into(),
                        target: target.clone(),
                        kind: 5,
                        tags: vec![tag(&["e", &repost]), tag(&["k", "6"])],
                        content: Some(String::new()),
                        suffix: None,
                        base: None,
                        relays,
                    },
                    // NIP-18: the e tag must carry a relay URL.
                    None => Pending {
                        purpose: "repost".into(),
                        target: target.clone(),
                        kind: 6,
                        tags: repost_tags(&target, &author, &relay_hint, &relays),
                        content: Some(String::new()),
                        suffix: None,
                        base: None,
                        relays,
                    },
                }
            } else if purpose == "quote" {
                Pending {
                    purpose: "quote".into(),
                    target: target.clone(),
                    kind: 1,
                    tags: quote_tags(&target, &author, &relay_hint, &me),
                    content: None,
                    suffix: Some(quote_suffix(&target)),
                    base: None,
                    relays,
                }
            } else if purpose == "reply" {
                Pending {
                    purpose: "reply".into(),
                    target: target.clone(),
                    kind: 1,
                    tags: reply_tags(&target, &author, &note_tags, &relay_hint, &me),
                    content: None,
                    suffix: None,
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
                        suffix: None,
                        base: None,
                        relays,
                    },
                    None => Pending {
                        purpose: "like".into(),
                        target: target.clone(),
                        kind: 7,
                        tags: vec![e_tag(&target, &relay_hint, None), tag(&["p", &author]), tag(&["k", "1"])],
                        content: Some("+".into()),
                        suffix: None,
                        base: None,
                        relays,
                    },
                }
            }
        }
        "delete" => {
            let own = db
                .query_opt("SELECT kind FROM nostr_events WHERE id = $1 AND pubkey = $2", &[&target, &me])
                .await
                .context("nostr own note")?;
            let Some(row) = own else {
                return refuse("You can only delete your own Nostr notes.");
            };
            let kind: i32 = row.get(0);
            let mine = write_relays_of(&db, std::slice::from_ref(&me)).await;
            Pending {
                purpose: "delete".into(),
                target: target.clone(),
                kind: 5,
                tags: vec![tag(&["e", &target]), tag(&["k", &kind.to_string()])],
                content: Some(String::new()),
                suffix: None,
                base: None,
                relays: merge_relays(&[&mine, &s.fallback_relays]),
            }
        }
        "follow" | "unfollow" => {
            if target == me {
                return refuse("That's you.");
            }
            let mine = write_relays_of(&db, std::slice::from_ref(&me)).await;
            let read_from = super::relay_book::without_skipped(merge_relays(&[&mine, &s.index_relays]), &s.skip);
            let (base, answered) = newest_list(&me, 3, &read_from).await;
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
                suffix: None,
                base: Some(base),
                relays: merge_relays(&[&mine, &s.index_relays, &s.fallback_relays]),
            }
        }
        "mute" | "unmute" => {
            if target == me {
                return refuse("That's you.");
            }
            if !super::sync::moderation_ready(&db).await {
                return refuse("Nostr muting isn't set up on this server yet.");
            }
            let stored_at: i64 = db
                .query_opt("SELECT list_at FROM nostr_mute_lists WHERE owner_user_id = $1", &[&owner])
                .await
                .context("nostr mute list stamp")?
                .map(|r| r.get(0))
                .unwrap_or(0);
            let mine = write_relays_of(&db, std::slice::from_ref(&me)).await;
            let read_from = super::relay_book::without_skipped(merge_relays(&[&mine, &s.index_relays]), &s.skip);
            let (base, answered) = newest_list(&me, MUTE_LIST_KIND, &read_from).await;
            if answered == 0 {
                return refuse("No relay answered. Try again in a minute.");
            }
            // No list yet starts an empty one; a list VAAK saw before must
            // turn up again, so nothing on it gets lost.
            let (base_tags, base_content) = match &base {
                Some(b) if b.created_at < stored_at => {
                    return refuse("Relays only returned an older copy of your mute list. Try again in a minute so no mutes get lost.");
                }
                Some(b) => (b.tags.clone(), b.content.clone()),
                None if stored_at > 0 => {
                    return refuse("Couldn't find your current mute list on any relay, so VAAK won't risk replacing it.");
                }
                None => (Vec::new(), String::new()),
            };
            let tags = match edit_mute_tags(&base_tags, &target, purpose == "mute") {
                Ok(tags) => tags,
                Err(e) => return refuse(e),
            };
            Pending {
                purpose: purpose.into(),
                target: target.clone(),
                kind: MUTE_LIST_KIND,
                tags,
                content: Some(base_content),
                suffix: None,
                base,
                relays: merge_relays(&[&mine, &s.index_relays, &s.fallback_relays]),
            }
        }
        _ => return refuse("Unknown Nostr action."),
    };
    // Relays an admin removed are never published to.
    pending.relays = super::relay_book::without_skipped(std::mem::take(&mut pending.relays), &s.skip);
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
        "suffix": pending.suffix.clone().unwrap_or_default(),
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
    super::archive::keep_own(&db, &ev).await;
    if let Some(base) = pending.base.as_ref().filter(|b| b.kind == 3) {
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
    let mut accepted_by = String::new();
    let mut problems = Vec::new();
    for (url, res) in &results {
        match res {
            Ok((true, _)) => {
                accepted += 1;
                if accepted_by.is_empty() {
                    accepted_by = url.clone();
                }
            }
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
        "repost" | "quote" => {
            let kind = if pending.purpose == "repost" { REPOST_KIND } else { QUOTE_KIND };
            db.execute(
                "INSERT INTO nostr_shares (owner_user_id, event_id, note_id, kind, created_at) VALUES ($1, $2, $3, $4, $5)
                 ON CONFLICT (owner_user_id, event_id) DO NOTHING",
                &[&owner, &ev.id, &pending.target, &kind, &ev.created_at],
            )
            .await?;
            if pending.purpose == "quote" {
                // The quote is the owner's own note; VAAK shows it like a boost.
                let (is_reply, parent, pks) = ev.reply_info();
                let tags = serde_json::to_value(&ev.tags).unwrap_or(Value::Array(Vec::new()));
                db.execute(
                    "INSERT INTO nostr_events (id, pubkey, kind, created_at, content, tags, is_reply, reply_pubkeys, reply_to, relay, ingested_at)
                     VALUES ($1, $2, 1, $3, $4, $5, $6, $7, $8, $9, $10) ON CONFLICT (id) DO NOTHING",
                    &[&ev.id, &ev.pubkey, &ev.created_at, &ev.content, &tags, &is_reply, &pks, &parent, &accepted_by, &now],
                )
                .await?;
            }
            let shown = if pending.purpose == "repost" { pending.target.as_str() } else { ev.id.as_str() };
            super::share::mirror_boost(&db, owner, shown, pending.purpose == "quote", now).await?;
        }
        "unrepost" => {
            db.execute(
                "DELETE FROM nostr_shares WHERE owner_user_id = $1 AND note_id = $2 AND kind = $3",
                &[&owner, &pending.target, &REPOST_KIND],
            )
            .await?;
            // Un-reposting your own quote leaves the quote itself on VAAK.
            let own_quote = db
                .query_opt(
                    "SELECT 1 FROM nostr_shares WHERE owner_user_id = $1 AND event_id = $2 AND kind = $3",
                    &[&owner, &pending.target, &QUOTE_KIND],
                )
                .await?
                .is_some();
            if !own_quote {
                super::share::unmirror_boost(&db, owner, &pending.target).await?;
            }
        }
        "delete" => {
            db.execute("DELETE FROM nostr_shares WHERE owner_user_id = $1 AND event_id = $2", &[&owner, &pending.target])
                .await?;
            db.execute("DELETE FROM nostr_events WHERE id = $1 AND pubkey = $2", &[&pending.target, &me]).await?;
            super::share::unmirror_boost(&db, owner, &pending.target).await?;
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
        "mute" | "unmute" => {
            super::sync::store_mute_list(&db, owner, &ev).await?;
        }
        _ => {}
    }
    Ok(json!({
        "ok": true,
        "purpose": pending.purpose,
        "accepted": accepted,
        "asked": results.len(),
        "note": if matches!(pending.purpose.as_str(), "reply" | "quote" | "post") { Value::String(super::bech32::note(&ev.id)) } else { Value::Null },
    }))
}

/// Viewer state on painted Nostr cards (boost wrappers included):
/// `vaak_nostr_liked`, `vaak_nostr_reposted`, `vaak_nostr_following` and
/// `vaak_nostr_mine`. Read at paint time so a cached page still shows a
/// like, repost or unfollow made a moment ago.
pub async fn stamp_viewer_flags(db: &Client, owner: i64, statuses: &mut [Value]) {
    fn cards(statuses: &mut [Value]) -> Vec<&mut Value> {
        statuses
            .iter_mut()
            .map(|s| if s.get("reblog").is_some_and(Value::is_object) { &mut s["reblog"] } else { s })
            .filter(|s| super::render::is_nostr_status(s))
            .collect()
    }
    let field = |s: &Value, key: &str| s.get(key).and_then(Value::as_str).unwrap_or("").to_string();
    let (ids, authors): (Vec<String>, Vec<String>) = cards(statuses)
        .iter()
        .map(|s| (field(s, "vaak_nostr_event_id"), field(s, "vaak_nostr_pubkey")))
        .unzip();
    if ids.is_empty() || owner < 1 || !super::sync::tables_ready(db).await {
        return;
    }
    let set = |rows: Result<Vec<tokio_postgres::Row>, tokio_postgres::Error>| -> HashSet<String> {
        rows.map(|rows| rows.iter().map(|r| r.get(0)).collect()).unwrap_or_default()
    };
    let liked = set(db.query("SELECT note_id FROM nostr_reactions WHERE owner_user_id = $1 AND note_id = ANY($2)", &[&owner, &ids]).await);
    let reposted = set(db
        .query(
            "SELECT note_id FROM nostr_shares WHERE owner_user_id = $1 AND kind = $2 AND note_id = ANY($3)",
            &[&owner, &REPOST_KIND, &ids],
        )
        .await);
    let following = set(db.query("SELECT pubkey FROM nostr_follows WHERE owner_user_id = $1 AND pubkey = ANY($2)", &[&owner, &authors]).await);
    let me = linked_pubkey(db, owner).await.ok().flatten().map(|(pk, _, _)| pk).unwrap_or_default();
    for st in cards(statuses) {
        let id = field(st, "vaak_nostr_event_id");
        let pk = field(st, "vaak_nostr_pubkey");
        st["vaak_nostr_liked"] = json!(liked.contains(&id));
        st["vaak_nostr_reposted"] = json!(reposted.contains(&id));
        st["vaak_nostr_following"] = json!(following.contains(&pk));
        st["vaak_nostr_mine"] = json!(!me.is_empty() && pk == me);
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
    fn mute_edits_keep_other_tags_and_reports_name_the_type() {
        let base = vec![tag(&["p", A]), tag(&["t", "nsfw"]), tag(&["word", "crypto"])];
        let added = edit_mute_tags(&base, B, true).unwrap();
        assert_eq!(added, vec![tag(&["p", A]), tag(&["t", "nsfw"]), tag(&["word", "crypto"]), tag(&["p", B])]);
        assert_eq!(edit_mute_tags(&added, B, false).unwrap(), base);
        assert_eq!(edit_mute_tags(&base, A, true), Err("You already muted them on Nostr."));
        assert_eq!(edit_mute_tags(&base, B, false), Err("They aren't on your Nostr mute list."));
        assert_eq!(report_tags(P, A, "spam"), vec![tag(&["e", P, "spam"]), tag(&["p", A, "spam"])]);
        assert!(REPORT_TYPES.contains(&"other") && !REPORT_TYPES.contains(&""));
    }

    #[test]
    fn crossposts_carry_only_note_tags() {
        assert!(note_tags_ok(&[tag(&["t", "art"]), tag(&["content-warning", "spoilers"]), tag(&["imeta", "url https://x/a.png", "m image/png"]), tag(&["proxy", "https://mkultra.monster/users/a/notes/1", "activitypub"])]));
        assert!(note_tags_ok(&[]));
        assert!(!note_tags_ok(&[tag(&["p", A])]), "no tagging people through a crosspost");
        assert!(!note_tags_ok(&[tag(&["e", P])]));
        assert!(!note_tags_ok(&[tag(&["t"])]));
    }

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
            suffix: None,
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

    #[test]
    fn quotes_must_keep_their_reference_and_have_text() {
        let tags = quote_tags(P, A, "wss://nos.lol", C);
        assert_eq!(tags, vec![tag(&["q", P, "wss://nos.lol", A]), tag(&["p", A])]);
        assert_eq!(quote_tags(P, C, "", C), vec![tag(&["q", P, "", C])], "no p tag when quoting yourself");
        let mut pending = pending_like(tags.clone());
        pending.kind = 1;
        pending.content = None;
        let suffix = quote_suffix(P);
        assert!(suffix.starts_with("\n\nnostr:note1"));
        pending.suffix = Some(suffix.clone());
        let ok = signed(7, 5000, 1, tags.clone(), &format!("so true{suffix}"));
        assert_eq!(check_signed(&ok, &pending, &ok.pubkey, 5000), Ok(()));
        let bare = signed(7, 5000, 1, tags.clone(), "so true");
        assert!(check_signed(&bare, &pending, &bare.pubkey, 5000).unwrap_err().contains("link"));
        let empty = signed(7, 5000, 1, tags, &format!("  {suffix}"));
        assert!(check_signed(&empty, &pending, &empty.pubkey, 5000).unwrap_err().contains("Write"));
    }

    #[test]
    fn reposts_always_name_a_relay() {
        let relays = vec!["wss://nos.lol".to_string()];
        assert_eq!(repost_tags(P, A, "wss://relay.example", &relays)[0], tag(&["e", P, "wss://relay.example"]));
        assert_eq!(repost_tags(P, A, "", &relays), vec![tag(&["e", P, "wss://nos.lol"]), tag(&["p", A])]);
    }
}
