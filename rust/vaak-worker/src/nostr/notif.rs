//! Nostr notifications: replies, mentions, reposts and likes aimed at a
//! linked npub, shown in the Notifications tab with the other networks.
//!
//! Read-only, like the rest of `nostr-sync`. Each run asks the user's write
//! relays and the fallback relays for kind 1 / 6 / 7 events that tag the
//! linked key (`#p`), since the last good read. What notifies:
//!  - a note from someone the user follows on Nostr (mention or reply);
//!  - a reply to one of the user's own notes, from anyone;
//!  - a repost or like of one of the user's notes, from anyone (Notifications
//!    groups repeated likes and reposts of one note like any other post's).
//!
//! Keys the user muted or hid, and admin-blocked keys, never notify. Kept
//! events are staged in `nostr_notifs` with their `mentions` row already
//! built, and `api/bin/nostr-notif-ingest.php` stores them through
//! `ap_mention_store` (badge, cache invalidation, push), so the PHP and Rust
//! notification lists both see them. Notes that notify, and the user's notes
//! they point at, are kept in `nostr_events` so Reply and Like work from the
//! notification card. Zaps (kind 9735) are not read yet.

use std::collections::{HashMap, HashSet};
use std::time::Duration;

use anyhow::{Context, Result};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use tokio::time::Instant;
use tokio_postgres::Client;

use super::bech32;
use super::event::{self, Event};
use super::relay::{self, Query};
use super::sync::{self, Settings};

const KINDS: [i64; 3] = [1, 6, 7];
/// How far back the first read for a newly linked key goes.
const FIRST_LOOKBACK_SECS: i64 = 3 * 86_400;
const OVERLAP_SECS: i64 = 900;
/// Only events this recent buzz the phone; older ones just show up.
const PUSH_WINDOW_SECS: i64 = 6 * 3600;
const RELAYS_PER_LINK: usize = 6;
const PER_LINK_LIMIT: i64 = 300;
const TARGET_LOOKUPS: usize = 200;
const INGEST_TIMEOUT: Duration = Duration::from_secs(90);
const VIEWER: &str = "https://njump.me/";

pub async fn ready(db: &Client) -> bool {
    db.query_one("SELECT to_regclass('nostr_notifs') IS NOT NULL AND to_regclass('nostr_notif_state') IS NOT NULL", &[])
        .await
        .map(|r| r.get::<_, bool>(0))
        .unwrap_or(false)
}

/// Why `ev` (which tags `me`) should notify, as the note it is about:
/// the replied-to, reposted or liked note ("" for a plain mention).
/// `None` when it shouldn't notify.
fn notifies(ev: &Event, me: &str, followed: bool, mine: &dyn Fn(&str) -> bool) -> Option<String> {
    if ev.pubkey == me {
        return None;
    }
    match ev.kind {
        1 => {
            let (is_reply, parent, _) = ev.reply_info();
            let parent = if is_reply { parent } else { String::new() };
            (followed || (!parent.is_empty() && mine(&parent))).then_some(parent)
        }
        6 => {
            let (target, _) = ev.repost_target()?;
            mine(&target).then_some(target)
        }
        // NIP-25: "-" is a dislike; it never notifies.
        7 if ev.content.trim() != "-" => {
            let target = ev.reaction_target()?;
            mine(&target).then_some(target)
        }
        _ => None,
    }
}

pub fn actor_url(pubkey: &str) -> String {
    format!("{VIEWER}{}", bech32::npub(pubkey))
}

pub fn note_url(id: &str) -> String {
    format!("{VIEWER}{}", bech32::note(id))
}

/// Same key as PHP `ap_mention_interaction_object_id`, so a like is stored once.
fn interaction_object_id(target: &str, actor: &str, kind: &str) -> String {
    let hash = hex::encode(Sha256::digest(format!("{actor}|{target}|{kind}").as_bytes()));
    format!("{target}#{kind}-{}", &hash[..12])
}

fn iso(ts: i64) -> String {
    chrono::DateTime::from_timestamp(ts, 0)
        .unwrap_or_else(chrono::Utc::now)
        .format("%Y-%m-%dT%H:%M:%S+00:00")
        .to_string()
}

/// The `mentions` row for a kept event. Likes and reposts carry the liked
/// note's text, like Bluesky ones do.
fn mention_row(ev: &Event, target: &str, target_text: &str) -> Value {
    let actor = actor_url(&ev.pubkey);
    let (activity, kind) = match ev.kind {
        6 => ("Announce", "reblog"),
        7 => ("Like", "like"),
        _ => ("Create", ""),
    };
    let (object_id, content, in_reply_to) = if kind.is_empty() {
        (note_url(&ev.id), ev.content.clone(), if target.is_empty() { String::new() } else { note_url(target) })
    } else {
        (interaction_object_id(&note_url(target), &actor, kind), target_text.to_string(), String::new())
    };
    json!({
        "activity_id": format!("nostr:{}", ev.id),
        "actor_id": actor,
        "object_id": object_id,
        "activity_type": activity,
        "type": if kind.is_empty() { "Note" } else { activity },
        "content": content.chars().take(2000).collect::<String>(),
        "in_reply_to": in_reply_to,
        "created_at": iso(ev.created_at),
    })
}

struct Link {
    owner: i64,
    pubkey: String,
    since_at: i64,
    relays: Vec<String>,
}

#[derive(Default)]
pub struct Report {
    pub owners: usize,
    pub staged: u64,
    pub ingest: String,
}

async fn store_note(tx: &tokio_postgres::Transaction<'_>, ev: &Event, relay: &str, now: i64) -> Result<u64> {
    let (is_reply, parent, pks) = ev.reply_info();
    let tags = serde_json::to_value(&ev.tags).unwrap_or(Value::Array(Vec::new()));
    let kind = ev.kind as i32;
    tx.execute(
        "INSERT INTO nostr_events (id, pubkey, kind, created_at, content, tags, is_reply, reply_pubkeys, reply_to, relay, ingested_at)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11) ON CONFLICT (id) DO NOTHING",
        &[&ev.id, &ev.pubkey, &kind, &ev.created_at, &ev.content, &tags, &is_reply, &pks, &parent, &relay, &now],
    )
    .await
    .context("nostr notif note")
}

pub async fn sync(db: &mut Client, s: &Settings, now: i64, deadline: Instant) -> Result<Report> {
    let mut report = Report::default();
    if !ready(db).await {
        report.ingest = "no-tables".into();
        return Ok(report);
    }
    let rows = db
        .query(
            "SELECT l.owner_user_id, l.pubkey, COALESCE(st.since_at, 0), COALESCE(p.write_relays, '[]'::jsonb)
             FROM nostr_links l JOIN ap_users u ON u.id = l.owner_user_id AND u.disabled_at IS NULL
             LEFT JOIN nostr_notif_state st ON st.owner_user_id = l.owner_user_id
             LEFT JOIN nostr_profiles p ON p.pubkey = l.pubkey",
            &[],
        )
        .await
        .context("nostr notif links")?;
    let links: Vec<Link> = rows
        .iter()
        .map(|r| {
            let listed: Value = r.get(3);
            let mut relays: Vec<String> = Vec::new();
            let own = listed.as_array().into_iter().flatten().filter_map(Value::as_str).filter_map(relay::normalize_relay_url);
            for url in own.chain(s.fallback_relays.iter().cloned()) {
                if !relays.contains(&url) && !s.skip.contains(&url) {
                    relays.push(url);
                }
            }
            relays.truncate(RELAYS_PER_LINK);
            Link { owner: r.get(0), pubkey: r.get(1), since_at: r.get(2), relays }
        })
        .filter(|l| event::is_hex64(&l.pubkey))
        .collect();
    report.owners = links.len();
    if links.is_empty() {
        return Ok(report);
    }

    // One `#p` query per linked key on each of its relays.
    let mut jobs: HashMap<String, Vec<Query>> = HashMap::new();
    for l in &links {
        let floor = now - FIRST_LOOKBACK_SECS;
        let since = if l.since_at > 0 { (l.since_at - OVERLAP_SECS).max(floor) } else { floor };
        for url in &l.relays {
            jobs.entry(url.clone()).or_default().push(Query::tagging(&l.pubkey, KINDS.to_vec(), since, PER_LINK_LIMIT));
        }
    }
    let mut answered: HashSet<String> = HashSet::new();
    let mut relay_of: HashMap<String, String> = HashMap::new();
    let mut raw = Vec::new();
    for (url, res) in sync::fan_out(db, s, jobs.into_iter().collect(), deadline).await {
        if let Ok(res) = res {
            if res.completed > 0 {
                answered.insert(url.clone());
            }
            for ev in res.events {
                relay_of.entry(ev.id.clone()).or_insert_with(|| url.clone());
                raw.push(ev);
            }
        }
    }
    let mut rejected = 0usize;
    let events = sync::verified(raw, now, &mut rejected);
    let tags_key = |ev: &Event, key: &str| ev.tags.iter().any(|t| t.first().map(String::as_str) == Some("p") && t.get(1).map(String::as_str) == Some(key));

    // Notes the events point at: stored ones first, the rest by id.
    let mut wanted: Vec<String> = Vec::new();
    for ev in &events {
        let target = match ev.kind {
            1 => Some(ev.reply_info()).filter(|r| r.0).map(|r| r.1),
            6 => ev.repost_target().map(|t| t.0),
            7 => ev.reaction_target(),
            _ => None,
        };
        if let Some(t) = target.filter(|t| !wanted.contains(t)) {
            wanted.push(t);
        }
    }
    let mut targets: HashMap<String, (String, String)> = HashMap::new();
    if !wanted.is_empty() {
        for r in db.query("SELECT id, pubkey, content FROM nostr_events WHERE id = ANY($1)", &[&wanted]).await.context("nostr notif targets")? {
            targets.insert(r.get(0), (r.get(1), r.get(2)));
        }
    }
    let linked: HashSet<&str> = links.iter().map(|l| l.pubkey.as_str()).collect();
    let mut fetched: Vec<Event> = Vec::new();
    let missing: Vec<String> = wanted.iter().filter(|id| !targets.contains_key(*id)).take(TARGET_LOOKUPS).cloned().collect();
    if !missing.is_empty() && Instant::now() < deadline {
        let mut ask: Vec<String> = Vec::new();
        for url in links.iter().flat_map(|l| l.relays.iter()) {
            if !ask.contains(url) && ask.len() < RELAYS_PER_LINK {
                ask.push(url.clone());
            }
        }
        let query = vec![Query::by_ids(missing.clone(), vec![1])];
        let mut found = Vec::new();
        for (_, res) in sync::fan_out(db, s, ask.into_iter().map(|u| (u, query.clone())).collect(), deadline).await {
            if let Ok(res) = res {
                found.extend(res.events);
            }
        }
        // Only the linked users' own notes matter (a reply to someone else
        // notifies only when its author is followed, target or not).
        for ev in sync::verified(found, now, &mut rejected) {
            if ev.kind == 1 && missing.contains(&ev.id) && linked.contains(ev.pubkey.as_str()) {
                targets.insert(ev.id.clone(), (ev.pubkey.clone(), ev.content.clone()));
                fetched.push(ev);
            }
        }
    }

    let mut staged: Vec<(i64, &Event, String, Value, bool)> = Vec::new();
    let mut keep_targets: HashSet<String> = HashSet::new();
    for l in &links {
        let mine_events: Vec<&Event> = events.iter().filter(|ev| tags_key(ev, &l.pubkey)).collect();
        if mine_events.is_empty() {
            continue;
        }
        let authors: Vec<String> = mine_events.iter().map(|ev| ev.pubkey.clone()).collect();
        let followed: HashSet<String> = db
            .query("SELECT pubkey FROM nostr_follows WHERE owner_user_id = $1 AND pubkey = ANY($2)", &[&l.owner, &authors])
            .await
            .context("nostr notif follows")?
            .iter()
            .map(|r| r.get(0))
            .collect();
        let hidden = super::moderation::hidden_keys(db, l.owner).await;
        let mine = |id: &str| targets.get(id).is_some_and(|(pk, _)| pk == &l.pubkey);
        for ev in mine_events {
            if hidden.contains(&ev.pubkey) {
                continue;
            }
            let Some(target) = notifies(ev, &l.pubkey, followed.contains(&ev.pubkey), &mine) else { continue };
            let text = targets.get(&target).map(|(_, c)| c.as_str()).unwrap_or("");
            let push = l.since_at > 0 && ev.created_at >= now - PUSH_WINDOW_SECS;
            if mine(&target) {
                keep_targets.insert(target.clone());
            }
            staged.push((l.owner, ev, target.clone(), mention_row(ev, &target, text), push));
        }
    }

    let tx = db.transaction().await?;
    for ev in fetched.iter().filter(|ev| keep_targets.contains(&ev.id)) {
        store_note(&tx, ev, relay_of.get(&ev.id).map(String::as_str).unwrap_or(""), now).await?;
    }
    for (owner, ev, target, row, push) in &staged {
        if ev.kind == 1 {
            store_note(&tx, ev, relay_of.get(&ev.id).map(String::as_str).unwrap_or(""), now).await?;
        }
        let kind = ev.kind as i32;
        report.staged += tx
            .execute(
                "INSERT INTO nostr_notifs (owner_user_id, event_id, kind, actor, target_id, created_at, mention, push)
                 VALUES ($1, $2, $3, $4, $5, $6, $7, $8) ON CONFLICT (owner_user_id, event_id) DO NOTHING",
                &[owner, &ev.id, &kind, &ev.pubkey, target, &ev.created_at, row, push],
            )
            .await
            .context("nostr notif stage")?;
    }
    // Move a key's window forward only when one of its relays answered.
    for l in links.iter().filter(|l| l.relays.iter().any(|r| answered.contains(r))) {
        tx.execute(
            "INSERT INTO nostr_notif_state (owner_user_id, since_at) VALUES ($1, $2)
             ON CONFLICT (owner_user_id) DO UPDATE SET since_at = EXCLUDED.since_at",
            &[&l.owner, &now],
        )
        .await?;
    }
    tx.commit().await?;

    if Instant::now() < deadline {
        fetch_missing_profiles(db, s, now, deadline).await?;
    }
    report.ingest = ingest(db).await;
    Ok(report)
}

/// Names and avatars for people who notified and aren't followed (followed
/// keys get theirs from the followee pass).
async fn fetch_missing_profiles(db: &Client, s: &Settings, now: i64, deadline: Instant) -> Result<()> {
    let keys: Vec<String> = db
        .query(
            "SELECT DISTINCT n.actor FROM nostr_notifs n LEFT JOIN nostr_profiles p ON p.pubkey = n.actor
             WHERE p.pubkey IS NULL LIMIT 200",
            &[],
        )
        .await
        .context("nostr notif profiles")?
        .iter()
        .map(|r| r.get::<_, String>(0))
        .filter(|k| event::is_hex64(k))
        .collect();
    if keys.is_empty() {
        return Ok(());
    }
    let query = vec![Query::new(keys.clone(), vec![0, 10002], None, (keys.len() as i64 * 3).max(20))];
    let jobs = s.index_relays.iter().map(|r| (r.clone(), query.clone())).collect();
    let mut all = Vec::new();
    for (_, res) in sync::fan_out(db, s, jobs, deadline).await {
        if let Ok(res) = res {
            all.extend(res.events);
        }
    }
    let mut rejected = 0usize;
    let newest = relay::newest_by_author_kind(sync::verified(all, now, &mut rejected));
    for key in &keys {
        sync::upsert_meta(db, key, newest.get(&(key.clone(), 0)), newest.get(&(key.clone(), 10002)), now).await?;
    }
    Ok(())
}

/// Hand staged rows to PHP (`ap_mention_store`). A failure leaves them
/// staged for the next run.
async fn ingest(db: &Client) -> String {
    let pending: i64 = match db.query_one("SELECT COUNT(*) FROM nostr_notifs WHERE ingested_at = 0", &[]).await {
        Ok(r) => r.get(0),
        Err(e) => return format!("error:{e}"),
    };
    if pending == 0 {
        return "0".into();
    }
    let php = std::env::var("VAAK_PHP_BIN").unwrap_or_else(|_| "/usr/bin/php".to_string());
    let root = std::env::var("VAAK_API_ROOT").unwrap_or_else(|_| "/srv/mkultra/html/api".to_string());
    let mut cmd = tokio::process::Command::new(php);
    cmd.arg(format!("{root}/bin/nostr-notif-ingest.php"))
        .env("AP_DB_DSN", std::env::var("AP_DB_DSN").unwrap_or_else(|_| "pgsql:dbname=novalandia".to_string()))
        .stdin(std::process::Stdio::null())
        .kill_on_drop(true);
    match tokio::time::timeout(INGEST_TIMEOUT, cmd.output()).await {
        Ok(Ok(out)) if out.status.success() => String::from_utf8_lossy(&out.stdout).lines().last().unwrap_or("").trim().to_string(),
        Ok(Ok(out)) => {
            let err = String::from_utf8_lossy(&out.stderr);
            tracing::warn!(pending, stderr = %err.trim(), "nostr notif ingest failed");
            format!("failed:{pending}")
        }
        Ok(Err(e)) => {
            tracing::warn!(pending, error = %e, "nostr notif ingest did not start");
            format!("failed:{pending}")
        }
        Err(_) => format!("timeout:{pending}"),
    }
}

/// Clean-up clauses for `nostr_events` (`e`): notes that notified, and the
/// user's notes they point at, stay while their notification row does.
pub const KEPT_SQL: &str =
    "EXISTS (SELECT 1 FROM nostr_notifs n WHERE n.event_id = e.id OR n.target_id = e.id)";

pub async fn cleanup(db: &Client, cutoff: i64) -> u64 {
    if !ready(db).await {
        return 0;
    }
    db.execute(
        "DELETE FROM nostr_notifs n WHERE n.created_at < $1
           OR NOT EXISTS (SELECT 1 FROM nostr_links l WHERE l.owner_user_id = n.owner_user_id)",
        &[&cutoff],
    )
    .await
    .unwrap_or(0)
        + db
            .execute("DELETE FROM nostr_notif_state st WHERE NOT EXISTS (SELECT 1 FROM nostr_links l WHERE l.owner_user_id = st.owner_user_id)", &[])
            .await
            .unwrap_or(0)
}

/// The hex key in a `https://njump.me/npub1…` actor URL.
pub fn actor_key(url: &str) -> Option<String> {
    let rest = url.trim_end_matches('/').strip_prefix(VIEWER)?;
    bech32::decode_entity(rest).filter(|(hrp, _)| hrp == "npub").map(|(_, hex)| hex)
}

/// The hex id in a `https://njump.me/note1…` URL (an interaction suffix is
/// already stripped by `notif::mention_target_object_id`).
pub fn note_key(url: &str) -> Option<String> {
    let rest = url.trim_end_matches('/').strip_prefix(VIEWER)?;
    bech32::decode_entity(rest).filter(|(hrp, _)| hrp == "note").map(|(_, hex)| hex)
}

/// Finish Nostr notification entities: drop ones from keys the owner muted
/// or hid since, paint the note like a Home card (links, media, quotes),
/// and show the notifier's Nostr name and avatar. Ids stay as built so
/// cursors and grouping don't move.
pub async fn dress_entities(db: &Client, owner: i64, entities: &mut Vec<Value>) {
    let is_nostr = |e: &Value| e.get("status").is_some_and(super::render::is_nostr_status);
    if !entities.iter().any(is_nostr) {
        return;
    }
    let actor_of = |e: &Value| e.get("account").and_then(|a| a.get("uri")).and_then(Value::as_str).and_then(actor_key);
    let hidden = super::moderation::hidden_keys(db, owner).await;
    entities.retain(|e| !is_nostr(e) || actor_of(e).is_none_or(|k| !hidden.contains(&k)));
    let ids: Vec<String> = entities
        .iter()
        .filter(|e| is_nostr(e))
        .filter_map(|e| e["status"].get("vaak_nostr_event_id").and_then(Value::as_str).map(str::to_string))
        .collect();
    let rows = super::home::fetch_rows(db, &ids).await.unwrap_or_default();
    let mut painted: Vec<Value> = ids.iter().filter_map(|id| rows.get(id)).map(super::render::materialize).collect();
    super::share::attach_quotes(db, &mut painted).await;
    let painted: HashMap<String, Value> = painted
        .into_iter()
        .filter_map(|v| Some((v.get("vaak_nostr_event_id")?.as_str()?.to_string(), v)))
        .collect();
    let actors: Vec<String> = entities.iter().filter(|e| is_nostr(e)).filter_map(actor_of).collect();
    let profiles: HashMap<String, Value> = match db
        .query(
            "SELECT pubkey, name, display_name, picture, nip05 FROM nostr_profiles WHERE pubkey = ANY($1)",
            &[&actors],
        )
        .await
    {
        Ok(rows) => rows
            .iter()
            .map(|r| {
                let row = super::render::NostrRow {
                    pubkey: r.get(0),
                    name: r.get(1),
                    display_name: r.get(2),
                    picture: r.get(3),
                    nip05: r.get(4),
                    ..Default::default()
                };
                (row.pubkey.clone(), super::render::account(&row))
            })
            .collect(),
        Err(_) => HashMap::new(),
    };
    for e in entities.iter_mut().filter(|e| is_nostr(e)) {
        // uri becomes nostr:npub… like Home's Nostr cards, so no in-app
        // fediverse profile link is painted for a Nostr key.
        if let Some(key) = actor_of(e) {
            match profiles.get(&key) {
                Some(acct) => overlay(&mut e["account"], acct, &["username", "acct", "display_name", "avatar", "avatar_static", "url", "uri", "note"]),
                None => e["account"]["uri"] = json!(format!("nostr:{}", bech32::npub(&key))),
            }
        }
        let id = e["status"].get("vaak_nostr_event_id").and_then(Value::as_str).unwrap_or("").to_string();
        if let Some(card) = painted.get(&id) {
            overlay(
                &mut e["status"],
                card,
                &[
                    "created_at", "content", "media_attachments", "sensitive", "spoiler_text", "url", "uri", "account",
                    "application", "vaak_nostr_pubkey", "vaak_nostr_quote_id", "quote", "card",
                ],
            );
        }
    }
}

fn overlay(into: &mut Value, from: &Value, keys: &[&str]) {
    for key in keys {
        if let Some(v) = from.get(*key) {
            into[*key] = v.clone();
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::nostr::event::tests::signed;

    fn tag(parts: &[&str]) -> Vec<String> {
        parts.iter().map(|s| s.to_string()).collect()
    }

    #[test]
    fn who_notifies() {
        let me = signed(1, 10, 1, vec![], "mine");
        let mine_note = me.id.clone();
        let other = "e".repeat(64);
        let p = tag(&["p", &me.pubkey]);
        let is_mine = |id: &str| id == mine_note;
        let mention = signed(2, 20, 1, vec![p.clone()], "hi @me");
        let reply = signed(2, 20, 1, vec![tag(&["e", &mine_note, "", "reply"]), p.clone()], "re");
        let thread_reply = signed(2, 20, 1, vec![tag(&["e", &other, "", "reply"]), p.clone()], "re other");
        // Followed: mentions and replies of any kind.
        assert_eq!(notifies(&mention, &me.pubkey, true, &is_mine), Some(String::new()));
        assert_eq!(notifies(&thread_reply, &me.pubkey, true, &is_mine), Some(other.clone()));
        // Not followed: only replies to my notes.
        assert_eq!(notifies(&mention, &me.pubkey, false, &is_mine), None);
        assert_eq!(notifies(&thread_reply, &me.pubkey, false, &is_mine), None);
        assert_eq!(notifies(&reply, &me.pubkey, false, &is_mine), Some(mine_note.clone()));
        // Likes and reposts: anyone, my notes only, no dislikes.
        let like = signed(2, 20, 7, vec![tag(&["e", &mine_note]), p.clone()], "+");
        let dislike = signed(2, 20, 7, vec![tag(&["e", &mine_note]), p.clone()], "-");
        let like_other = signed(2, 20, 7, vec![tag(&["e", &other]), p.clone()], "🔥");
        let repost = signed(2, 20, 6, vec![tag(&["e", &mine_note]), p.clone()], "");
        assert_eq!(notifies(&like, &me.pubkey, true, &is_mine), Some(mine_note.clone()));
        assert_eq!(notifies(&like, &me.pubkey, false, &is_mine), Some(mine_note.clone()));
        assert_eq!(notifies(&repost, &me.pubkey, false, &is_mine), Some(mine_note.clone()));
        assert_eq!(notifies(&like_other, &me.pubkey, false, &is_mine), None);
        assert_eq!(notifies(&dislike, &me.pubkey, true, &is_mine), None);
        assert_eq!(notifies(&like_other, &me.pubkey, true, &is_mine), None);
        assert_eq!(notifies(&repost, &me.pubkey, true, &is_mine), Some(mine_note.clone()));
        // My own events never notify me.
        let own_reply = signed(1, 30, 1, vec![tag(&["e", &mine_note, "", "reply"]), p], "self");
        assert_eq!(notifies(&own_reply, &me.pubkey, true, &is_mine), None);
    }

    #[test]
    fn mention_rows_match_the_php_shape() {
        let target = "a".repeat(64);
        let reply = signed(2, 1_800_000_000, 1, vec![tag(&["e", &target, "", "reply"])], "nice");
        let row = mention_row(&reply, &target, "");
        assert_eq!(row["activity_type"], "Create");
        assert_eq!(row["type"], "Note");
        assert_eq!(row["object_id"], note_url(&reply.id));
        assert_eq!(row["in_reply_to"], note_url(&target));
        assert_eq!(row["actor_id"], actor_url(&reply.pubkey));
        assert_eq!(row["activity_id"], format!("nostr:{}", reply.id));
        assert_eq!(row["created_at"], "2027-01-15T08:00:00+00:00");
        let like = signed(2, 1_800_000_000, 7, vec![tag(&["e", &target])], "+");
        let row = mention_row(&like, &target, "my note");
        assert_eq!(row["activity_type"], "Like");
        assert_eq!(row["content"], "my note");
        let object = row["object_id"].as_str().unwrap();
        assert!(object.starts_with(&format!("{}#like-", note_url(&target))));
        // sha256("actor|target|like")[..12], as in PHP.
        let expect = hex::encode(Sha256::digest(format!("{}|{}|like", actor_url(&like.pubkey), note_url(&target)).as_bytes()));
        assert!(object.ends_with(&expect[..12]));
        assert_eq!(note_key(&note_url(&target)), Some(target));
        assert_eq!(actor_key(&actor_url(&like.pubkey)), Some(like.pubkey.clone()));
        let repost = signed(2, 1, 6, vec![tag(&["e", &"b".repeat(64)])], "");
        assert_eq!(mention_row(&repost, &"b".repeat(64), "x")["type"], "Announce");
    }
}
