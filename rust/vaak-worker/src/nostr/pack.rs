//! Follow packs: NIP-51 "starter packs" (kind 39089) and media starter
//! packs (kind 39092), as Damus, following.space and others share them.
//!
//! Library > Collections opens one from a pasted link (`naddr1…`, a
//! following.space `/d/<id>?p=<key>` page, or any URL with an naddr in it).
//! `open` fetches the pack once, checks its signature, and reads member
//! names from stored profiles or one kind 0 query. The result is cached in
//! Redis for half an hour, so reloads and the follow step cost no relay
//! traffic. Nothing is written to the database until the user follows.
//!
//! Following a pack is one kind 3 edit (purpose "follow_pack" in `act`),
//! signed once by the user's extension or bunker. `act::prepare` only adds
//! keys that are in the cached pack.

use std::collections::{HashMap, HashSet};
use std::time::Duration;

use anyhow::{Context, Result};
use futures_util::StreamExt;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use tokio::time::Instant;

use super::event::{self, Event};
use super::relay::{self, Query};
use crate::config::Config;

pub const PACK_KIND: i64 = 39089;
pub const MEDIA_PACK_KIND: i64 = 39092;
/// Members shown and offered from one pack.
pub const MAX_MEMBERS: usize = 500;
/// Keys one "Follow" press may add to the follow list.
pub const MAX_FOLLOW_PICKS: usize = 150;
const CACHE_SECS: u64 = 1800;
const PACK_DEADLINE: Duration = Duration::from_secs(9);
const PROFILE_DEADLINE: Duration = Duration::from_secs(6);
const MAX_READ_RELAYS: usize = 8;
const PROFILE_CHUNK: usize = 150;

/// Where a pack lives: author, `d` tag, kind when the link says, and relay
/// hints from an naddr.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct PackRef {
    pub author: String,
    pub d: String,
    pub kind: Option<i64>,
    pub relays: Vec<String>,
}

/// A pack as fetched and checked.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
pub struct Pack {
    /// NIP-01 address `kind:pubkey:d`.
    pub addr: String,
    pub naddr: String,
    pub kind: i64,
    pub author: String,
    pub d: String,
    pub title: String,
    pub description: String,
    pub image: String,
    pub members: Vec<String>,
    /// Members past MAX_MEMBERS that were left out.
    pub dropped: usize,
    pub created_at: i64,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize, PartialEq)]
pub struct Person {
    pub name: String,
    pub picture: String,
    pub nip05: String,
}

/// What the cache holds: the pack and the names it was shown with.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Cached {
    pub pack: Pack,
    pub people: HashMap<String, Person>,
    pub fetched_at: i64,
}

pub fn is_pack_kind(kind: i64) -> bool {
    kind == PACK_KIND || kind == MEDIA_PACK_KIND
}

/// A pasted link or address as a pack reference. Takes `naddr1…` (bare,
/// `nostr:` or inside any URL), `kind:pubkey:d`, and following.space
/// `/d/<id>?p=<hex or npub>` pages.
pub fn parse_ref(input: &str) -> Option<PackRef> {
    let s = input.trim();
    if s.is_empty() || s.len() > 4000 {
        return None;
    }
    let lower = s.to_ascii_lowercase();
    if let Some(at) = lower.find("naddr1") {
        let tail: String = lower[at..].chars().take_while(|c| c.is_ascii_alphanumeric()).collect();
        return decode_naddr(&tail).filter(|r| r.kind.is_none_or(is_pack_kind));
    }
    if let Some(r) = parse_address(s) {
        return Some(r);
    }
    parse_following_space(s)
}

/// `39089:<hex>:<d>`.
fn parse_address(s: &str) -> Option<PackRef> {
    let mut parts = s.splitn(3, ':');
    let kind: i64 = parts.next()?.parse().ok()?;
    let author = parts.next()?.to_ascii_lowercase();
    let d = parts.next()?.to_string();
    (is_pack_kind(kind) && event::is_hex64(&author) && !d.is_empty() && d.len() <= 200).then_some(PackRef {
        author,
        d,
        kind: Some(kind),
        relays: Vec::new(),
    })
}

/// following.space links: `https://following.space/d/<id>?p=<key>`.
fn parse_following_space(s: &str) -> Option<PackRef> {
    let at = s.find("/d/")?;
    let rest = &s[at + 3..];
    let end = rest.find(['?', '#', '/']).unwrap_or(rest.len());
    let d = &rest[..end];
    if d.is_empty() || d.len() > 200 || !d.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '-' | '_' | '.' | '~')) {
        return None;
    }
    let query = rest[end..].split('#').next().unwrap_or("").trim_start_matches(['/', '?']);
    let p = query.split('&').find_map(|kv| kv.strip_prefix("p="))?;
    let author = pubkey_from(p)?;
    Some(PackRef { author, d: d.to_string(), kind: None, relays: Vec::new() })
}

fn pubkey_from(raw: &str) -> Option<String> {
    let raw = raw.trim().to_ascii_lowercase();
    if event::is_hex64(&raw) {
        return Some(raw);
    }
    match super::bech32::decode_entity(&raw) {
        Some((hrp, hex)) if hrp == "npub" || hrp == "nprofile" => Some(hex),
        _ => None,
    }
}

/// NIP-19 naddr: TLV 0 = d tag, 1 = relay, 2 = author, 3 = kind (u32 BE).
pub fn decode_naddr(input: &str) -> Option<PackRef> {
    let (hrp, bytes) = super::bech32::decode(input)?;
    if hrp != "naddr" {
        return None;
    }
    let (mut d, mut author, mut kind, mut relays) = (None, None, None, Vec::new());
    let mut i = 0usize;
    while i + 2 <= bytes.len() {
        let (t, len) = (bytes[i], bytes[i + 1] as usize);
        let value = bytes.get(i + 2..i + 2 + len)?;
        match t {
            0 => d = Some(String::from_utf8(value.to_vec()).ok()?),
            1 => {
                if let Some(url) = std::str::from_utf8(value).ok().and_then(relay::normalize_relay_url) {
                    if relays.len() < 3 && !relays.contains(&url) {
                        relays.push(url);
                    }
                }
            }
            2 if len == 32 => author = Some(hex::encode(value)),
            3 if len == 4 => kind = Some(i64::from(u32::from_be_bytes([value[0], value[1], value[2], value[3]]))),
            _ => {}
        }
        i += 2 + len;
    }
    let d = d.filter(|d| d.len() <= 200)?;
    Some(PackRef { author: author?, d, kind, relays })
}

/// NIP-19 naddr for a pack (no relay hints).
pub fn encode_naddr(kind: i64, author: &str, d: &str) -> String {
    let Ok(pk) = hex::decode(author) else { return String::new() };
    if pk.len() != 32 || d.len() > 255 {
        return String::new();
    }
    let mut tlv = vec![0u8, d.len() as u8];
    tlv.extend_from_slice(d.as_bytes());
    tlv.extend_from_slice(&[2, 32]);
    tlv.extend_from_slice(&pk);
    tlv.extend_from_slice(&[3, 4]);
    tlv.extend_from_slice(&(kind as u32).to_be_bytes());
    super::bech32::encode("naddr", &tlv)
}

fn first_tag(ev: &Event, name: &str) -> String {
    ev.tags
        .iter()
        .find(|t| t.first().map(String::as_str) == Some(name))
        .and_then(|t| t.get(1))
        .cloned()
        .unwrap_or_default()
}

fn clip(s: &str, max: usize) -> String {
    s.trim().chars().take(max).collect()
}

/// A checked pack event as a Pack. Members keep the author's order, once
/// each, at most MAX_MEMBERS.
pub fn pack_from_event(ev: &Event) -> Option<Pack> {
    if !is_pack_kind(ev.kind) {
        return None;
    }
    let d = first_tag(ev, "d");
    let mut seen = HashSet::new();
    let mut members = Vec::new();
    let mut dropped = 0usize;
    for t in ev.tags.iter().filter(|t| t.first().map(String::as_str) == Some("p")) {
        let Some(pk) = t.get(1).map(|p| p.to_ascii_lowercase()).filter(|p| event::is_hex64(p)) else { continue };
        if !seen.insert(pk.clone()) {
            continue;
        }
        if members.len() < MAX_MEMBERS {
            members.push(pk);
        } else {
            dropped += 1;
        }
    }
    let image = first_tag(ev, "image");
    let title = match clip(&first_tag(ev, "title"), 120) {
        t if t.is_empty() => clip(&first_tag(ev, "name"), 120),
        t => t,
    };
    Some(Pack {
        addr: format!("{}:{}:{}", ev.kind, ev.pubkey, d),
        naddr: encode_naddr(ev.kind, &ev.pubkey, &d),
        kind: ev.kind,
        author: ev.pubkey.clone(),
        d,
        title,
        description: clip(&first_tag(ev, "description"), 1000),
        image: if image.starts_with("https://") && image.len() <= 1000 { image } else { String::new() },
        members,
        dropped,
        created_at: ev.created_at,
    })
}

/// The newest event among `events` that is this pack, signed by its author.
pub fn newest_matching(events: Vec<Event>, r: &PackRef) -> Option<Event> {
    events
        .into_iter()
        .filter(|e| e.pubkey == r.author && is_pack_kind(e.kind) && r.kind.is_none_or(|k| k == e.kind))
        .filter(|e| first_tag(e, "d") == r.d)
        .filter(|e| e.verify().is_ok())
        .max_by(|a, b| a.created_at.cmp(&b.created_at).then(b.id.cmp(&a.id)))
}

fn cache_key(addr: &str) -> String {
    use sha2::{Digest, Sha256};
    format!("vaak:nostr:pack:v1:{}", hex::encode(&Sha256::digest(addr.as_bytes())[..16]))
}

fn ref_addr(r: &PackRef) -> String {
    format!("{}:{}:{}", r.kind.unwrap_or(PACK_KIND), r.author, r.d)
}

/// A pack from the cache, by its address (what `act` checks picks against).
pub async fn cached(cfg: &Config, addr: &str) -> Result<Option<Cached>> {
    let mut redis = crate::redis_util::connect(&cfg.redis_url).await?;
    let raw: Option<String> = redis::cmd("GET").arg(cache_key(addr)).query_async(&mut redis).await.context("nostr pack cache read")?;
    Ok(raw.and_then(|s| serde_json::from_str(&s).ok()))
}

async fn fetch_events(relays: &[String], queries: Vec<Query>, deadline: Instant) -> (Vec<Event>, usize) {
    let results: Vec<_> = futures_util::stream::iter(relays.iter().cloned().map(|url| {
        let queries = queries.clone();
        async move { relay::fetch(&url, &queries, deadline).await }
    }))
    .buffer_unordered(6)
    .collect()
    .await;
    let mut answered = 0;
    let mut events = Vec::new();
    for res in results.into_iter().flatten() {
        answered += usize::from(res.completed > 0);
        events.extend(res.events);
    }
    (events, answered)
}

fn push_unique(out: &mut Vec<String>, list: &[String]) {
    for url in list {
        if !out.contains(url) {
            out.push(url.clone());
        }
    }
}

/// Names for `keys`: stored profiles first, then one kind 0 query for the
/// rest. Nothing fetched is stored; following someone lets profile sync
/// pick them up the normal way.
async fn people(db: &tokio_postgres::Client, keys: &[String], relays: &[String]) -> HashMap<String, Person> {
    let mut out: HashMap<String, Person> = HashMap::new();
    if let Ok(rows) = db
        .query("SELECT pubkey, name, display_name, picture, nip05 FROM nostr_profiles WHERE pubkey = ANY($1) AND profile_at > 0", &[&keys])
        .await
    {
        for row in rows {
            let (name, display): (String, String) = (row.get(1), row.get(2));
            let name = if display.trim().is_empty() { name } else { display };
            out.insert(row.get(0), Person { name: clip(&name, 80), picture: row.get(3), nip05: clip(&row.get::<_, String>(4), 120) });
        }
    }
    let missing: Vec<String> = keys.iter().filter(|k| !out.contains_key(*k)).cloned().collect();
    if missing.is_empty() || relays.is_empty() {
        return out;
    }
    let queries: Vec<Query> = missing.chunks(PROFILE_CHUNK).map(|c| Query::new(c.to_vec(), vec![0], None, c.len() as i64)).collect();
    let (events, _) = fetch_events(relays, queries, Instant::now() + PROFILE_DEADLINE).await;
    let wanted: HashSet<&String> = missing.iter().collect();
    let events: Vec<Event> = events.into_iter().filter(|e| e.kind == 0 && wanted.contains(&e.pubkey) && e.verify().is_ok()).collect();
    for ((pk, _), ev) in relay::newest_by_author_kind(events) {
        let p = event::parse_profile(&ev.content);
        let name = if p.display_name.trim().is_empty() { p.name } else { p.display_name };
        out.insert(pk, Person { name: clip(&name, 80), picture: p.picture, nip05: clip(&p.nip05, 120) });
    }
    out
}

/// Library > Collections: the pack behind `link`, with names, and which
/// members the viewer already follows.
pub async fn open(cfg: &Config, owner: i64, link: &str, refresh: bool) -> Result<Value> {
    let refuse = |m: &str| Ok(json!({"ok": false, "error": m}));
    if super::sync::disabled_by_env() {
        return refuse("Nostr is turned off on this server.");
    }
    let Some(r) = parse_ref(link) else {
        return refuse("That doesn't look like a Nostr follow pack link. Paste the pack's link from Damus, following.space or njump (it has naddr1… in it).");
    };
    let db = crate::db::connect(&cfg.database_url).await?;
    if !super::sync::tables_ready(&db).await {
        return refuse("Nostr isn't set up on this server yet.");
    }
    let mut hit = if refresh { None } else { cached(cfg, &ref_addr(&r)).await.ok().flatten() };
    if hit.is_none() && r.kind.is_none() && !refresh {
        hit = cached(cfg, &format!("{MEDIA_PACK_KIND}:{}:{}", r.author, r.d)).await.ok().flatten();
    }
    let entry = match hit {
        Some(c) => c,
        None => {
            let mut s = super::sync::Settings::from_env(30);
            super::relay_book::apply(&db, &mut s, chrono::Utc::now().timestamp()).await?;
            let author_relays = super::act::write_relays_of(&db, std::slice::from_ref(&r.author)).await;
            let mut read_from = Vec::new();
            push_unique(&mut read_from, &r.relays);
            push_unique(&mut read_from, &author_relays);
            push_unique(&mut read_from, &s.index_relays);
            push_unique(&mut read_from, &s.fallback_relays);
            let mut read_from = super::relay_book::without_skipped(read_from, &s.skip);
            read_from.truncate(MAX_READ_RELAYS);
            let kinds = r.kind.map(|k| vec![k]).unwrap_or_else(|| vec![PACK_KIND, MEDIA_PACK_KIND]);
            let mut q = Query::new(vec![r.author.clone()], kinds, None, 10);
            q.filter["#d"] = json!([r.d]);
            let (events, answered) = fetch_events(&read_from, vec![q], Instant::now() + PACK_DEADLINE).await;
            let Some(pack) = newest_matching(events, &r).as_ref().and_then(pack_from_event) else {
                return refuse(if answered == 0 {
                    "No relay answered. Try again in a minute."
                } else {
                    "Couldn't find that follow pack on any relay VAAK asked. It may have been deleted."
                });
            };
            let mut keys = pack.members.clone();
            keys.push(pack.author.clone());
            let mut profile_relays = Vec::new();
            push_unique(&mut profile_relays, &s.index_relays);
            let mut profile_relays = super::relay_book::without_skipped(profile_relays, &s.skip);
            profile_relays.truncate(4);
            let people = people(&db, &keys, &profile_relays).await;
            let entry = Cached { pack, people, fetched_at: chrono::Utc::now().timestamp() };
            if let Ok(mut redis) = crate::redis_util::connect(&cfg.redis_url).await {
                let raw = serde_json::to_string(&entry)?;
                let _: redis::RedisResult<()> =
                    redis::cmd("SET").arg(cache_key(&entry.pack.addr)).arg(raw).arg("EX").arg(CACHE_SECS).query_async(&mut redis).await;
            }
            entry
        }
    };
    let me = super::act::linked_pubkey(&db, owner).await?.map(|(pk, _, _)| pk).unwrap_or_default();
    let following: HashSet<String> = if me.is_empty() {
        HashSet::new()
    } else {
        db.query("SELECT pubkey FROM nostr_follows WHERE owner_user_id = $1 AND pubkey = ANY($2)", &[&owner, &entry.pack.members])
            .await
            .context("nostr pack follows")?
            .into_iter()
            .map(|row| row.get(0))
            .collect()
    };
    Ok(view_json(&entry, &me, &following))
}

/// The JSON PHP paints the pack page from.
pub fn view_json(entry: &Cached, me: &str, following: &HashSet<String>) -> Value {
    let person = |pk: &str| {
        let p = entry.people.get(pk).cloned().unwrap_or_default();
        json!({
            "pubkey": pk,
            "npub": super::bech32::npub(pk),
            "name": p.name,
            "picture": if p.picture.starts_with("https://") { p.picture } else { String::new() },
            "nip05": p.nip05,
        })
    };
    let members: Vec<Value> = entry
        .pack
        .members
        .iter()
        .map(|pk| {
            let mut v = person(pk);
            v["following"] = json!(following.contains(pk));
            v["me"] = json!(pk == me);
            v
        })
        .collect();
    json!({
        "ok": true,
        "linked": !me.is_empty(),
        "max_picks": MAX_FOLLOW_PICKS,
        "fetched_at": entry.fetched_at,
        "pack": {
            "addr": entry.pack.addr,
            "naddr": entry.pack.naddr,
            "kind": entry.pack.kind,
            "title": entry.pack.title,
            "description": entry.pack.description,
            "image": entry.pack.image,
            "created_at": entry.pack.created_at,
            "dropped": entry.pack.dropped,
            "author": person(&entry.pack.author),
        },
        "members": members,
    })
}

/// The keys a "Follow" press may add: picked, in the pack, not the user,
/// and not followed already in `base`. Pack order, at most MAX_FOLLOW_PICKS.
pub fn follow_picks(pack: &Pack, picks: &[String], base: &[Vec<String>], me: &str) -> Result<Vec<String>, String> {
    let picked: HashSet<String> = picks.iter().map(|p| p.trim().to_ascii_lowercase()).collect();
    if picked.is_empty() {
        return Err("Pick at least one person to follow.".into());
    }
    let members: HashSet<&String> = pack.members.iter().collect();
    if picked.iter().any(|p| !members.contains(p)) {
        return Err("Someone you picked isn't in this pack anymore. Open the pack again.".into());
    }
    let already: HashSet<String> = base
        .iter()
        .filter(|t| t.first().map(String::as_str) == Some("p"))
        .filter_map(|t| t.get(1).map(|p| p.to_ascii_lowercase()))
        .collect();
    let adds: Vec<String> =
        pack.members.iter().filter(|pk| picked.contains(*pk) && pk.as_str() != me && !already.contains(*pk)).cloned().collect();
    if adds.is_empty() {
        return Err("You already follow everyone you picked.".into());
    }
    if adds.len() > MAX_FOLLOW_PICKS {
        return Err(format!("That's {} people. Pick {MAX_FOLLOW_PICKS} or fewer at a time.", adds.len()));
    }
    Ok(adds)
}

#[cfg(test)]
mod tests {
    use super::*;

    const PK: &str = "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d";

    #[test]
    fn naddr_round_trips() {
        let n = encode_naddr(PACK_KIND, PK, "abc-123");
        assert!(n.starts_with("naddr1"));
        let r = decode_naddr(&n).unwrap();
        assert_eq!(r, PackRef { author: PK.into(), d: "abc-123".into(), kind: Some(PACK_KIND), relays: vec![] });
    }

    #[test]
    fn other_kinds_are_refused() {
        // A long-form article address decodes, but it isn't a pack.
        let n = encode_naddr(30023, PK, "post");
        assert_eq!(decode_naddr(&n).unwrap().kind, Some(30023));
        assert!(parse_ref(&n).is_none());
    }

    #[test]
    fn parses_links() {
        let n = encode_naddr(PACK_KIND, PK, "x1");
        for link in [
            n.clone(),
            format!("nostr:{n}"),
            format!("https://njump.me/{n}"),
            format!("https://damus.io/{n}?ref=share"),
            format!("{PACK_KIND}:{PK}:x1"),
        ] {
            let r = parse_ref(&link).unwrap_or_else(|| panic!("{link}"));
            assert_eq!((r.author.as_str(), r.d.as_str()), (PK, "x1"), "{link}");
        }
        let fs = parse_ref(&format!("https://following.space/d/0b6e-41aa?p={PK}")).unwrap();
        assert_eq!((fs.author.as_str(), fs.d.as_str(), fs.kind), (PK, "0b6e-41aa", None));
        let npub = super::super::bech32::npub(PK);
        assert_eq!(parse_ref(&format!("https://following.space/d/abc?x=1&p={npub}")).unwrap().author, PK);
        assert!(parse_ref("https://following.space/d/abc").is_none());
        assert!(parse_ref("hello").is_none());
        assert!(parse_ref(&format!("1:{PK}:x")).is_none());
    }

    fn ev(tags: Vec<Vec<&str>>) -> Event {
        Event {
            id: "00".repeat(32),
            pubkey: PK.into(),
            created_at: 100,
            kind: PACK_KIND,
            tags: tags.into_iter().map(|t| t.into_iter().map(String::from).collect()).collect(),
            content: String::new(),
            sig: String::new(),
        }
    }

    #[test]
    fn pack_from_event_reads_tags() {
        let a = "a".repeat(64);
        let b = "B".repeat(64);
        let p = pack_from_event(&ev(vec![
            vec!["d", "x"],
            vec!["title", " Cool people "],
            vec!["image", "http://insecure/img.png"],
            vec!["p", &a],
            vec!["p", &b],
            vec!["p", &a],
            vec!["p", "nope"],
        ]))
        .unwrap();
        assert_eq!(p.title, "Cool people");
        assert_eq!(p.image, "");
        assert_eq!(p.members, vec![a.clone(), b.to_ascii_lowercase()]);
        assert_eq!(p.addr, format!("{PACK_KIND}:{PK}:x"));
        assert_eq!(decode_naddr(&p.naddr).unwrap().d, "x");
    }

    #[test]
    fn follow_picks_rules() {
        let a = "a".repeat(64);
        let b = "b".repeat(64);
        let c = "c".repeat(64);
        let pack = pack_from_event(&ev(vec![vec!["d", "x"], vec!["p", &a], vec!["p", &b], vec!["p", PK]])).unwrap();
        let base = vec![vec!["p".to_string(), b.clone()]];
        // Already followed and self are skipped; pack order kept.
        assert_eq!(follow_picks(&pack, &[b.clone(), a.to_uppercase(), PK.into()], &base, PK), Ok(vec![a.clone()]));
        assert!(follow_picks(&pack, &[c], &base, PK).unwrap_err().contains("isn't in this pack"));
        assert!(follow_picks(&pack, &[b.clone()], &base, PK).unwrap_err().contains("already follow"));
        assert!(follow_picks(&pack, &[], &base, PK).is_err());
    }
}
