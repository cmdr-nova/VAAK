//! Wafrn ActivityPub ↔ Bluesky cross-post dedupe and text ask cards.
//!
//! Wafrn (https://github.com/gabboman/wafrn) links the copies itself:
//! - `packages/backend/utils/activitypub/userToJSONLD.ts` adds
//!   `at://${user.bskyDid}` to `alsoKnownAs`.
//! - `packages/backend/atproto/utils/postToAtproto.ts` sets the Bluesky
//!   record field `fediverseId` to `${frontendUrl}/fediverse/post/${post.id}`
//!   and writes asks as `Name asked: question\n\nanswer`.
//! - `bskyUri` / `bskyCid` stay on the Wafrn row (`sendPostBsky.ts`).
//!   `postToJSONLD.ts` does not publish them on the Note.
//!
//! The ActivityPub copy is the one that stays. Lookups are request-time and
//! indexed. A missing `wafrn_bsky_actors` table leaves the page unchanged.

use std::collections::{HashMap, HashSet};
use std::sync::OnceLock;

use anyhow::Result;
use serde_json::{json, Value};
use tokio_postgres::Client;

#[derive(Clone)]
struct Post {
    id: String,
    network: String,
    object_id: String,
    fediverse_id: String,
    actor_id: String,
    author_did: String,
    created_at: i64,
    text: String,
}

struct ApPost {
    object: String,
    actor: String,
    created_at: i64,
    text: String,
}

struct Plan {
    drop: Vec<String>,
    learn: Vec<(String, String)>,
}

pub fn post_identity(raw: &str) -> String {
    let trimmed = raw.trim().trim_end_matches('/');
    if trimmed.is_empty() {
        return String::new();
    }
    let https = if let Some(rest) = trimmed.strip_prefix("http://") {
        format!("https://{rest}")
    } else {
        trimmed.to_string()
    };
    let Some(rest) = https.strip_prefix("https://") else {
        return https;
    };
    let (host_raw, path) = match rest.find('/') {
        Some(index) => (&rest[..index], &rest[index..]),
        None => (rest, ""),
    };
    if host_raw.is_empty() {
        return https;
    }
    let path = path.trim_end_matches('/');
    let path = activity_path().replace_all(path, "/fediverse/post/");
    format!("https://{}{path}", host_raw.to_ascii_lowercase())
}

pub fn normalize_text(text: &str) -> String {
    let plain = plain_text(text);
    let plain = ask_footer().replace(&plain, "");
    let stripped = asked_prefix().replace(&plain, "");
    let stripped = note_asked_prefix().replace(stripped.as_ref(), "");
    whitespace()
        .replace_all(stripped.as_ref(), "")
        .into_owned()
}

pub fn parse_text_ask(text: &str) -> Option<(String, String, String)> {
    let plain = plain_text(text);
    let caps = text_ask().captures(&plain)?;
    let name = caps.get(1)?.as_str().trim();
    let question = caps.get(2)?.as_str().trim();
    let answer = caps.get(3)?.as_str().trim();
    if name.is_empty() || question.is_empty() || answer.is_empty() {
        return None;
    }
    Some((name.to_string(), question.to_string(), answer.to_string()))
}

pub fn text_ask_for_linked(text: &str, linked: bool) -> Option<Value> {
    if !linked {
        return None;
    }
    let (name, question, answer) = parse_text_ask(text)?;
    Some(json!({
        "question": question,
        "asker_label": name,
        "answer": answer,
        "asker_actor": "",
    }))
}

pub fn dids_from_also_known_as(aka: &Value) -> Vec<String> {
    let mut items: Vec<&Value> = Vec::new();
    if let Some(list) = aka.as_array() {
        items.extend(list.iter());
    } else if aka.is_string() {
        items.push(aka);
    } else {
        return Vec::new();
    }
    let mut out = HashSet::new();
    for item in items {
        let text = if let Some(text) = item.as_str() {
            text.to_string()
        } else {
            item.get("id")
                .or_else(|| item.get("href"))
                .and_then(Value::as_str)
                .unwrap_or("")
                .to_string()
        };
        if let Some(caps) = did_pattern().captures(&text) {
            out.insert(caps.get(1).unwrap().as_str().to_string());
        }
    }
    let mut list: Vec<String> = out.into_iter().collect();
    list.sort();
    list
}

fn plan(posts: &[Post], did_to_actors: &HashMap<String, Vec<String>>) -> Plan {
    let mut ap = Vec::new();
    let mut identities = HashSet::new();
    for post in posts {
        if post.network != "ap" {
            continue;
        }
        let object = post_identity(&post.object_id);
        if object.is_empty() || !identities.insert(object.clone()) {
            continue;
        }
        ap.push(ApPost {
            object,
            actor: post_identity(&post.actor_id),
            created_at: post.created_at,
            text: normalize_text(&post.text),
        });
    }
    let mut actors_by_did: HashMap<String, HashSet<String>> = HashMap::new();
    for (did, actors) in did_to_actors {
        for actor in actors {
            let id = post_identity(actor);
            if !id.is_empty() {
                actors_by_did.entry(did.clone()).or_default().insert(id);
            }
        }
    }
    let mut drop = Vec::new();
    let mut dropped = HashSet::new();
    let mut learn = Vec::new();
    let mut learned = HashSet::new();
    for post in posts {
        if post.network != "bsky" || post.id.is_empty() || dropped.contains(&post.id) {
            continue;
        }
        let fedi = post_identity(&post.fediverse_id);
        if !fedi.is_empty() {
            if !identities.contains(&fedi) {
                continue;
            }
            drop.push(post.id.clone());
            dropped.insert(post.id.clone());
            if !post.author_did.starts_with("did:") {
                continue;
            }
            for row in &ap {
                if row.object != fedi || row.actor.is_empty() {
                    continue;
                }
                let key = format!("{}\n{}", row.actor, post.author_did);
                if learned.insert(key) {
                    learn.push((row.actor.clone(), post.author_did.clone()));
                }
            }
            continue;
        }
        let Some(linked) = actors_by_did.get(&post.author_did) else {
            continue;
        };
        if post.created_at <= 0 {
            continue;
        }
        let norm = normalize_text(&post.text);
        if norm.is_empty() {
            continue;
        }
        let mut matches = 0usize;
        for row in &ap {
            if row.actor.is_empty() || !linked.contains(&row.actor) {
                continue;
            }
            if row.created_at <= 0 || (row.created_at - post.created_at).abs() > 600 {
                continue;
            }
            if row.text.is_empty() || row.text != norm {
                continue;
            }
            matches += 1;
            if matches > 1 {
                break;
            }
        }
        if matches == 1 {
            drop.push(post.id.clone());
            dropped.insert(post.id.clone());
        }
    }
    Plan { drop, learn }
}

pub fn replay(input: &Value) -> Value {
    match input.get("op").and_then(Value::as_str).unwrap_or("") {
        "plan" => {
            let posts = posts_from_json(input.get("posts"));
            let links = actors_from_json(input.get("did_to_actors"));
            let planned = plan(&posts, &links);
            json!({
                "drop": planned.drop,
                "learn": planned.learn.into_iter().map(|(actor_id, bsky_did)| json!({
                    "actor_id": actor_id,
                    "bsky_did": bsky_did,
                })).collect::<Vec<_>>(),
            })
        }
        "ask" => json!({
            "card": text_ask_for_linked(
                input.get("text").and_then(Value::as_str).unwrap_or(""),
                input.get("linked").and_then(Value::as_bool).unwrap_or(false),
            ),
        }),
        "identity" => json!({
            "identity": post_identity(input.get("value").and_then(Value::as_str).unwrap_or("")),
        }),
        "normalize" => json!({
            "text": normalize_text(input.get("value").and_then(Value::as_str).unwrap_or("")),
        }),
        "dids" => json!({
            "dids": dids_from_also_known_as(input.get("also_known_as").unwrap_or(&Value::Null)),
        }),
        "twin_match" => json!({
            "match": twin_texts_match(
                &twin_text(input.get("bsky").and_then(Value::as_str).unwrap_or("")),
                &twin_text(input.get("fedi").and_then(Value::as_str).unwrap_or("")),
            ),
        }),
        _ => json!({"error": "unknown op"}),
    }
}

/// Drop Wafrn Bluesky twins from a hydrated timeline page. Other Bluesky
/// posts stay. Ask-card text is not written here; that is paint-only.
pub async fn filter_statuses(db: &Client, statuses: &mut Vec<Value>) -> Result<()> {
    let mut posts: Vec<(usize, Post)> = Vec::new();
    for (index, status) in statuses.iter().enumerate() {
        if let Some(post) = post_from_status(status) {
            posts.push((index, post));
        }
    }
    let bsky_at: Vec<usize> = posts
        .iter()
        .enumerate()
        .filter(|(_, (_, post))| post.network == "bsky")
        .map(|(index, _)| index)
        .collect();
    if bsky_at.is_empty() {
        return Ok(());
    }
    let uris: Vec<String> = bsky_at.iter().map(|pi| posts[*pi].1.id.clone()).collect();
    let links = match lookup_links(db, &uris).await {
        Ok(links) => links,
        Err(_) => return Ok(()),
    };
    for pi in &bsky_at {
        if posts[*pi].1.fediverse_id.trim().is_empty() {
            if let Some(fedi) = links.get(&posts[*pi].1.id) {
                posts[*pi].1.fediverse_id = fedi.clone();
            }
        }
    }
    let dids: Vec<String> = bsky_at
        .iter()
        .filter_map(|pi| {
            let did = posts[*pi].1.author_did.as_str();
            did.starts_with("did:").then(|| did.to_string())
        })
        .collect();
    let actors: Vec<String> = posts
        .iter()
        .filter(|(_, post)| post.network == "ap" && !post.actor_id.is_empty())
        .map(|(_, post)| post.actor_id.clone())
        .collect();
    let did_to_actors = match lookup_actor_links(db, &dids, &actors).await {
        Ok(map) => map,
        Err(err) if missing_actors_table(&err) => HashMap::new(),
        Err(_) => return Ok(()),
    };
    let page_posts: Vec<Post> = posts.iter().map(|(_, post)| post.clone()).collect();
    let planned = plan(&page_posts, &did_to_actors);
    let mut drop: HashSet<String> = planned.drop.iter().cloned().collect();
    let undecided: Vec<usize> = bsky_at
        .iter()
        .copied()
        .filter(|pi| {
            let post = &posts[*pi].1;
            !drop.contains(&post.id)
                && post_identity(&post.fediverse_id).is_empty()
                && did_to_actors.contains_key(&post.author_did)
                && post.created_at > 0
        })
        .collect();
    if !undecided.is_empty() {
        if let Ok(extra) = lookup_fallback_events(db, &undecided, &posts, &did_to_actors).await {
            if !extra.is_empty() {
                let mut combined = page_posts.clone();
                combined.extend(extra);
                for id in plan(&combined, &did_to_actors).drop {
                    drop.insert(id);
                }
            }
        }
    }
    let _ = remember_pairs(db, &planned.learn).await;
    if drop.is_empty() {
        return Ok(());
    }
    let skip: HashSet<usize> = posts
        .iter()
        .filter(|(_, post)| post.network == "bsky" && drop.contains(&post.id))
        .map(|(index, _)| *index)
        .collect();
    let mut kept = Vec::with_capacity(statuses.len());
    for (index, status) in statuses.drain(..).enumerate() {
        if !skip.contains(&index) {
            kept.push(status);
        }
    }
    *statuses = kept;
    Ok(())
}

/// Bridgy Fed serves a bridged Bluesky post to the fediverse as
/// `https://bsky.brid.gy/convert/ap/at://{did}/app.bsky.feed.post/{rkey}`.
pub fn bridgy_convert_at_uri(object: &str) -> Option<String> {
    let caps = bridgy_convert().captures(object.trim())?;
    let did = caps[1].replace("%3A", ":").replace("%3a", ":");
    Some(format!("at://{did}/app.bsky.feed.post/{}", &caps[2]))
}

/// Indexes of Bridgy Fed copies whose post is already in `ids` natively:
/// the Bluesky post itself, or a fediverse note it links to
/// (`bsky_post_links`, e.g. a Wafrn dual-publish). The native copy stays,
/// like PHP `admin_is_bridgy_convert_object_id`; a repeated copy keeps the first.
pub fn bridgy_twin_drops(ids: &[String], links: &HashMap<String, String>) -> HashSet<usize> {
    let native: HashSet<String> = ids
        .iter()
        .filter(|id| bridgy_convert_at_uri(id).is_none())
        .map(|id| post_identity(id))
        .filter(|id| !id.is_empty())
        .collect();
    let mut seen = HashSet::new();
    let mut drop = HashSet::new();
    for (index, id) in ids.iter().enumerate() {
        let Some(at) = bridgy_convert_at_uri(id) else {
            continue;
        };
        let linked = links
            .get(&at)
            .map(|fedi| post_identity(fedi))
            .is_some_and(|fedi| native.contains(&fedi));
        if native.contains(&at) || linked || !seen.insert(at) {
            drop.insert(index);
        }
    }
    drop
}

/// `bsky_post_links` fediverse ids for the Bluesky posts behind Bridgy copies.
pub async fn bridgy_links(db: &Client, ids: &[String]) -> Result<HashMap<String, String>> {
    let uris: Vec<String> = ids
        .iter()
        .filter_map(|id| bridgy_convert_at_uri(id))
        .collect::<HashSet<_>>()
        .into_iter()
        .collect();
    lookup_links(db, &uris).await
}

/// Drop Bridgy Fed copies of posts already on the page natively.
pub async fn filter_bridgy_twins(db: &Client, statuses: &mut Vec<Value>) -> Result<()> {
    let ids: Vec<String> = statuses.iter().map(|status| json_str(status, "uri")).collect();
    if !ids.iter().any(|id| bridgy_convert_at_uri(id).is_some()) {
        return Ok(());
    }
    let links = bridgy_links(db, &ids).await.unwrap_or_default();
    let drop = bridgy_twin_drops(&ids, &links);
    let mut index = 0;
    statuses.retain(|_| {
        index += 1;
        !drop.contains(&(index - 1))
    });
    Ok(())
}

/// Paint-only. Sets `vaak_ask` for a linked Wafrn Bluesky ask and leaves
/// `content` alone, so Mastodon JSON from `view_hydrate` stays unchanged.
pub async fn stamp_text_asks(db: &Client, statuses: &mut [Value]) -> Result<()> {
    let mut pending: Vec<(usize, String, String, String, String)> = Vec::new();
    for (index, status) in statuses.iter().enumerate() {
        if !is_bsky(status) {
            continue;
        }
        let Some((name, question, answer)) = parse_text_ask(status_text(status)) else {
            continue;
        };
        let did = status_did(status);
        if !did.starts_with("did:") {
            continue;
        }
        pending.push((index, did, name, question, answer));
    }
    if pending.is_empty() {
        return Ok(());
    }
    let dids: Vec<String> = pending.iter().map(|(_, did, _, _, _)| did.clone()).collect();
    let linked = match linked_dids(db, &dids).await {
        Ok(set) => set,
        Err(err) if missing_actors_table(&err) => return Ok(()),
        Err(_) => return Ok(()),
    };
    for (index, did, name, question, answer) in pending {
        if !linked.contains(&did) {
            continue;
        }
        statuses[index]["vaak_ask"] = json!({
            "ask_question": question,
            "asker_display": name,
            "ask_answer": answer,
            "ask_actor": "",
        });
    }
    Ok(())
}

/// Paint-only. Sets `vaak_fedi_twin` on Bluesky posts whose record or
/// `bsky_post_links` row names a fediverse post. `filter_statuses` only sees
/// one page, so a twin split across infinite-scroll pages or live polls
/// reaches the browser; the card carries the fediverse id as an alias and
/// the browser's seen-card check drops whichever copy arrives second.
pub async fn stamp_fedi_twins(db: &Client, statuses: &mut [Value]) -> Result<()> {
    let mut pending: Vec<(usize, String)> = Vec::new();
    for (index, status) in statuses.iter_mut().enumerate() {
        if !is_bsky(status) {
            continue;
        }
        let uri = json_str(status, "uri").trim().trim_end_matches('/').to_string();
        let direct = post_identity(&json_str(status, "fediverse_id"));
        if !direct.is_empty() {
            status["vaak_fedi_twin"] = json!(direct);
        } else if uri.starts_with("at://") {
            pending.push((index, uri));
        }
    }
    if pending.is_empty() {
        return Ok(());
    }
    let uris: Vec<String> = pending.iter().map(|(_, uri)| uri.clone()).collect();
    let links = lookup_links(db, &uris).await?;
    for (index, uri) in pending {
        let fedi = links.get(&uri).map(|fedi| post_identity(fedi)).unwrap_or_default();
        if fedi.starts_with("https://") {
            statuses[index]["vaak_fedi_twin"] = json!(fedi);
        }
    }
    Ok(())
}

/// The fediverse post a Bluesky post says it mirrors: `bsky_post_links`, or
/// the cached record's `fediverseId` when the link row is not written yet.
pub async fn bsky_fediverse_id(db: &Client, uri: &str) -> Result<String> {
    let uri = uri.trim();
    if !uri.starts_with("at://") {
        return Ok(String::new());
    }
    let links = lookup_links(db, &[uri.to_string()]).await?;
    if let Some(fedi) = links.get(uri) {
        let fedi = post_identity(fedi);
        if fedi.starts_with("https://") {
            return Ok(fedi);
        }
    }
    let row = db
        .query_opt("SELECT raw_json FROM bsky_posts WHERE bsky_uri = $1 LIMIT 1", &[&uri])
        .await?;
    let raw: Option<String> = row.and_then(|row| row.get(0));
    let fedi = raw
        .and_then(|raw| serde_json::from_str::<Value>(&raw).ok())
        .and_then(|raw| {
            raw.pointer("/record/fediverseId")
                .and_then(Value::as_str)
                .map(post_identity)
        })
        .unwrap_or_default();
    Ok(if fedi.starts_with("https://") { fedi } else { String::new() })
}

/// Fediverse actors whose posts a Bluesky twin from `did` duplicates: the
/// learned Wafrn link for the DID, plus whoever VAAK saw create `fedi`.
pub async fn twin_fedi_actors(db: &Client, did: &str, fedi: &str) -> Result<Vec<String>> {
    let mut out: HashSet<String> = HashSet::new();
    if did.starts_with("did:") {
        match db
            .query("SELECT actor_id FROM wafrn_bsky_actors WHERE bsky_did = $1", &[&did])
            .await
        {
            Ok(rows) => {
                for row in rows {
                    let actor: String = row.get(0);
                    out.insert(post_identity(&actor));
                }
            }
            Err(err) => {
                let err = anyhow::Error::from(err);
                if !missing_actors_table(&err) {
                    return Err(err);
                }
            }
        }
    }
    if fedi.starts_with("https://") {
        let slash = format!("{fedi}/");
        let rows = db
            .query(
                "SELECT DISTINCT actor_id FROM events
                 WHERE object_id IN ($1, $2) AND type = 'Create'
                 LIMIT 4",
                &[&fedi, &slash],
            )
            .await?;
        for row in rows {
            let actor: Option<String> = row.get(0);
            out.insert(post_identity(&actor.unwrap_or_default()));
        }
    }
    let mut list: Vec<String> = out.into_iter().filter(|a| a.starts_with("https://")).collect();
    list.sort();
    Ok(list)
}

fn posts_from_json(value: Option<&Value>) -> Vec<Post> {
    value
        .and_then(Value::as_array)
        .map(|rows| {
            rows.iter()
                .filter_map(|row| {
                    Some(Post {
                        id: json_str(row, "id"),
                        network: json_str(row, "network"),
                        object_id: json_str(row, "object_id"),
                        fediverse_id: json_str(row, "fediverse_id"),
                        actor_id: json_str(row, "actor_id"),
                        author_did: json_str(row, "author_did"),
                        created_at: row.get("created_at").and_then(Value::as_i64).unwrap_or(0),
                        text: json_str(row, "text"),
                    })
                })
                .collect()
        })
        .unwrap_or_default()
}

fn actors_from_json(value: Option<&Value>) -> HashMap<String, Vec<String>> {
    let mut out = HashMap::new();
    let Some(map) = value.and_then(Value::as_object) else {
        return out;
    };
    for (did, actors) in map {
        let list = actors
            .as_array()
            .map(|rows| {
                rows.iter()
                    .filter_map(Value::as_str)
                    .map(str::to_string)
                    .collect()
            })
            .unwrap_or_default();
        out.insert(did.clone(), list);
    }
    out
}

fn post_from_status(status: &Value) -> Option<Post> {
    let source = json_str(status, "source");
    let sid = json_str(status, "id");
    if source == "rss" || sid.starts_with("rss:") {
        return None;
    }
    let uri = json_str(status, "uri").trim().trim_end_matches('/').to_string();
    if is_bsky(status) {
        if !uri.starts_with("at://") {
            return None;
        }
        return Some(Post {
            id: uri,
            network: "bsky".into(),
            object_id: String::new(),
            fediverse_id: json_str(status, "fediverse_id"),
            actor_id: String::new(),
            author_did: status_did(status),
            created_at: crate::timeline::status_created_ts(status),
            text: status_text(status).to_string(),
        });
    }
    if !uri.starts_with("http") {
        return None;
    }
    let account = status.get("account");
    let actor = account
        .and_then(|row| row.get("uri").and_then(Value::as_str))
        .filter(|uri| !uri.is_empty())
        .or_else(|| account.and_then(|row| row.get("url").and_then(Value::as_str)))
        .unwrap_or("")
        .to_string();
    Some(Post {
        id: format!("ap:{uri}"),
        network: "ap".into(),
        object_id: uri,
        fediverse_id: String::new(),
        actor_id: actor,
        author_did: String::new(),
        created_at: crate::timeline::status_created_ts(status),
        text: status_text(status).to_string(),
    })
}

fn is_bsky(status: &Value) -> bool {
    json_str(status, "source") == "bluesky"
        || json_str(status, "uri").starts_with("at://")
        || json_str(status, "id").starts_with("bsky:")
}

fn status_text(status: &Value) -> &str {
    status
        .get("content")
        .and_then(Value::as_str)
        .unwrap_or("")
}

fn status_did(status: &Value) -> String {
    let direct = json_str(status, "author_did");
    if direct.starts_with("did:") {
        return direct;
    }
    let id = status
        .get("account")
        .and_then(|row| row.get("id"))
        .and_then(Value::as_str)
        .unwrap_or("");
    if id.starts_with("did:") {
        return id.to_string();
    }
    let uri = json_str(status, "uri");
    if let Some(rest) = uri.trim().strip_prefix("at://") {
        let did = rest.split('/').next().unwrap_or("");
        if did.starts_with("did:") {
            return did.to_string();
        }
    }
    String::new()
}

fn json_str(value: &Value, key: &str) -> String {
    value
        .get(key)
        .and_then(Value::as_str)
        .unwrap_or("")
        .to_string()
}

async fn lookup_links(db: &Client, uris: &[String]) -> Result<HashMap<String, String>> {
    let mut out = HashMap::new();
    if uris.is_empty() {
        return Ok(out);
    }
    for chunk in uris.chunks(200) {
        let keys = chunk.to_vec();
        let rows = db
            .query(
                "SELECT bsky_uri, COALESCE(fediverse_id, '') FROM bsky_post_links WHERE bsky_uri = ANY($1)",
                &[&keys],
            )
            .await?;
        for row in rows {
            let uri: String = row.get(0);
            let fedi: String = row.get(1);
            if !fedi.trim().is_empty() {
                out.insert(uri, fedi);
            }
        }
    }
    Ok(out)
}

async fn lookup_actor_links(
    db: &Client,
    dids: &[String],
    actors: &[String],
) -> Result<HashMap<String, Vec<String>>> {
    let dids: Vec<String> = dids
        .iter()
        .filter(|did| did.starts_with("did:"))
        .cloned()
        .collect::<HashSet<_>>()
        .into_iter()
        .collect();
    let actors: Vec<String> = actors
        .iter()
        .map(|actor| post_identity(actor))
        .filter(|actor| !actor.is_empty())
        .collect::<HashSet<_>>()
        .into_iter()
        .collect();
    if dids.is_empty() && actors.is_empty() {
        return Ok(HashMap::new());
    }
    let rows = db
        .query(
            "SELECT actor_id, bsky_did FROM wafrn_bsky_actors
             WHERE bsky_did = ANY($1) OR actor_id = ANY($2)",
            &[&dids, &actors],
        )
        .await?;
    let mut map: HashMap<String, Vec<String>> = HashMap::new();
    for row in rows {
        let actor: String = row.get(0);
        let did: String = row.get(1);
        if !actor.is_empty() && !did.is_empty() {
            map.entry(did).or_default().push(actor);
        }
    }
    Ok(map)
}

async fn lookup_fallback_events(
    db: &Client,
    indexes: &[usize],
    posts: &[(usize, Post)],
    did_to_actors: &HashMap<String, Vec<String>>,
) -> Result<Vec<Post>> {
    let mut actors = HashSet::new();
    let mut min_ts = i64::MAX;
    let mut max_ts = 0i64;
    for pi in indexes {
        let post = &posts[*pi].1;
        if let Some(linked) = did_to_actors.get(&post.author_did) {
            for actor in linked {
                let id = post_identity(actor);
                if id.is_empty() {
                    continue;
                }
                actors.insert(id.clone());
                actors.insert(format!("{id}/"));
            }
        }
        if post.created_at > 0 {
            min_ts = min_ts.min(post.created_at - 600);
            max_ts = max_ts.max(post.created_at + 600);
        }
    }
    if actors.is_empty() || min_ts > max_ts {
        return Ok(Vec::new());
    }
    let from = chrono::DateTime::from_timestamp(min_ts, 0)
        .map(|dt| dt.format("%Y-%m-%dT%H:%M:%S").to_string())
        .unwrap_or_default();
    let to = chrono::DateTime::from_timestamp(max_ts, 0)
        .map(|dt| format!("{}~", dt.format("%Y-%m-%dT%H:%M:%S")))
        .unwrap_or_default();
    let actor_ids: Vec<String> = actors.into_iter().collect();
    let rows = db
        .query(
            "SELECT actor_id, object_id, COALESCE(summary, ''), created_at FROM events
             WHERE actor_id = ANY($1)
               AND created_at >= $2 AND created_at <= $3
               AND type IN ('Create', 'Announce', 'Quote', 'QuotePost')
               AND action_taken IN ('log', 'local_observe')
             ORDER BY created_at DESC
             LIMIT 64",
            &[&actor_ids, &from, &to],
        )
        .await?;
    if rows.len() >= 64 {
        return Ok(Vec::new());
    }
    let mut extra = Vec::new();
    for row in rows {
        let actor: String = row.get(0);
        let object: String = row.get(1);
        let summary: String = row.get(2);
        let created: String = row.get(3);
        if object.is_empty() {
            continue;
        }
        extra.push(Post {
            id: format!("fallback:{object}"),
            network: "ap".into(),
            object_id: object,
            fediverse_id: String::new(),
            actor_id: actor,
            author_did: String::new(),
            created_at: crate::timeline::status_created_ts(&json!({"created_at": created})),
            text: summary,
        });
    }
    Ok(extra)
}

async fn linked_dids(db: &Client, dids: &[String]) -> Result<HashSet<String>> {
    let keys: Vec<String> = dids
        .iter()
        .filter(|did| did.starts_with("did:"))
        .cloned()
        .collect::<HashSet<_>>()
        .into_iter()
        .collect();
    if keys.is_empty() {
        return Ok(HashSet::new());
    }
    let rows = db
        .query(
            "SELECT DISTINCT bsky_did FROM wafrn_bsky_actors WHERE bsky_did = ANY($1)",
            &[&keys],
        )
        .await?;
    Ok(rows.into_iter().map(|row| row.get(0)).collect())
}

async fn remember_pairs(db: &Client, pairs: &[(String, String)]) -> Result<()> {
    if pairs.is_empty() {
        return Ok(());
    }
    let now = chrono::Utc::now().to_rfc3339();
    for (actor, did) in pairs {
        let actor = post_identity(actor);
        if actor.is_empty() || !did.starts_with("did:") {
            continue;
        }
        db.execute(
            "INSERT INTO wafrn_bsky_actors (actor_id, bsky_did, updated_at)
             VALUES ($1, $2, $3)
             ON CONFLICT (actor_id, bsky_did) DO NOTHING",
            &[&actor, did, &now],
        )
        .await?;
    }
    Ok(())
}

/// Notification side of the cross-post dedupe. A Wafrn user with a linked
/// Bluesky account who mentions or replies to an owner reaches VAAK twice:
/// once as an ActivityPub Note and once through the Bluesky notification
/// poll. The fediverse copy stays, same as the timeline. A Bluesky mention
/// row is a twin when
/// - its record's `fediverseId` (`bsky_post_links`) names a post the owner
///   also got as a fediverse mention, or
/// - its DID is a learned Wafrn actor link (`wafrn_bsky_actors`) and that
///   actor sent the owner a mention within 10 minutes with the same text
///   once mentions and links are taken out.
///
/// The check reads the database, not the page, so a twin that sits on another
/// page or arrives later still hides the Bluesky copy. A failed read keeps
/// every row.
pub async fn twin_mention_ids(db: &Client, owner: i64, ids: &[i64]) -> Result<HashSet<i64>> {
    let mut out = HashSet::new();
    if owner < 1 || ids.is_empty() {
        return Ok(out);
    }
    let keys: Vec<i64> = ids.iter().copied().collect::<HashSet<_>>().into_iter().collect();
    let rows = db
        .query(
            "SELECT id::bigint, COALESCE(activity_id, ''), COALESCE(content, ''), COALESCE(created_at, '')
             FROM mentions
             WHERE owner_user_id = $1::bigint AND id = ANY($2) AND deleted_at IS NULL
               AND activity_id LIKE 'at://%'
               AND COALESCE(activity_type, '') IN ('Create', 'Quote')",
            &[&owner, &keys],
        )
        .await?;
    let mut cands: Vec<TwinCandidate> = Vec::new();
    for row in rows {
        let uri: String = row.get(1);
        let uri = uri.trim().to_string();
        if !uri.contains("/app.bsky.feed.post/") {
            continue;
        }
        let did = uri
            .strip_prefix("at://")
            .and_then(|rest| rest.split('/').next())
            .unwrap_or("")
            .to_string();
        let created: String = row.get(3);
        cands.push(TwinCandidate {
            id: row.get(0),
            uri,
            did,
            text: row.get(2),
            created_at: crate::timeline::status_created_ts(&json!({ "created_at": created })),
        });
    }
    if cands.is_empty() {
        return Ok(out);
    }

    let uris: Vec<String> = cands.iter().map(|cand| cand.uri.clone()).collect();
    let links = lookup_links(db, &uris).await?;
    let mut fedi_of: HashMap<i64, String> = HashMap::new();
    for cand in &cands {
        if let Some(fedi) = links.get(&cand.uri).map(|fedi| post_identity(fedi)) {
            if fedi.starts_with("https://") && !fedi.starts_with("https://bsky.app/") {
                fedi_of.insert(cand.id, fedi);
            }
        }
    }
    if !fedi_of.is_empty() {
        let mut objects: Vec<String> = Vec::new();
        for fedi in fedi_of.values() {
            objects.push(fedi.clone());
            objects.push(format!("{fedi}/"));
        }
        let rows = db
            .query(
                "SELECT object_id FROM mentions
                 WHERE owner_user_id = $1::bigint AND deleted_at IS NULL
                   AND object_id = ANY($2)
                   AND COALESCE(activity_id, '') NOT LIKE 'at://%'",
                &[&owner, &objects],
            )
            .await?;
        let seen: HashSet<String> = rows
            .into_iter()
            .map(|row| post_identity(&row.get::<_, Option<String>>(0).unwrap_or_default()))
            .collect();
        for (id, fedi) in &fedi_of {
            if seen.contains(fedi) {
                out.insert(*id);
            }
        }
    }

    let undecided: Vec<&TwinCandidate> = cands
        .iter()
        .filter(|cand| {
            !out.contains(&cand.id)
                && !fedi_of.contains_key(&cand.id)
                && cand.did.starts_with("did:")
                && cand.created_at > 0
        })
        .collect();
    if undecided.is_empty() {
        return Ok(out);
    }
    let dids: Vec<String> = undecided.iter().map(|cand| cand.did.clone()).collect();
    let did_to_actors = match lookup_actor_links(db, &dids, &[]).await {
        Ok(map) => map,
        Err(err) if missing_actors_table(&err) => return Ok(out),
        Err(err) => return Err(err),
    };
    let mut actors: HashSet<String> = HashSet::new();
    let mut min_ts = i64::MAX;
    let mut max_ts = 0i64;
    for cand in &undecided {
        let Some(linked) = did_to_actors.get(&cand.did) else {
            continue;
        };
        for actor in linked {
            let id = post_identity(actor);
            if !id.is_empty() {
                actors.insert(format!("{id}/"));
                actors.insert(id);
            }
        }
        min_ts = min_ts.min(cand.created_at - TWIN_WINDOW_SECS);
        max_ts = max_ts.max(cand.created_at + TWIN_WINDOW_SECS);
    }
    if actors.is_empty() || min_ts > max_ts {
        return Ok(out);
    }
    let from = chrono::DateTime::from_timestamp(min_ts, 0)
        .map(|dt| dt.format("%Y-%m-%dT%H:%M:%S").to_string())
        .unwrap_or_default();
    let to = chrono::DateTime::from_timestamp(max_ts, 0)
        .map(|dt| format!("{}~", dt.format("%Y-%m-%dT%H:%M:%S")))
        .unwrap_or_default();
    let actor_ids: Vec<String> = actors.into_iter().collect();
    let rows = db
        .query(
            "SELECT actor_id, COALESCE(content, ''), COALESCE(created_at, '') FROM mentions
             WHERE owner_user_id = $1::bigint AND deleted_at IS NULL
               AND actor_id = ANY($2)
               AND created_at >= $3 AND created_at <= $4
               AND COALESCE(activity_id, '') NOT LIKE 'at://%'
             ORDER BY id DESC
             LIMIT 64",
            &[&owner, &actor_ids, &from, &to],
        )
        .await?;
    let fedi_rows: Vec<(String, i64, String)> = rows
        .into_iter()
        .map(|row| {
            let actor: String = row.get::<_, Option<String>>(0).unwrap_or_default();
            let created: String = row.get(2);
            (
                post_identity(&actor),
                crate::timeline::status_created_ts(&json!({ "created_at": created })),
                twin_text(&row.get::<_, String>(1)),
            )
        })
        .collect();
    for cand in undecided {
        let Some(linked) = did_to_actors.get(&cand.did) else {
            continue;
        };
        let linked: HashSet<String> = linked.iter().map(|actor| post_identity(actor)).collect();
        let text = twin_text(&cand.text);
        let hit = fedi_rows.iter().any(|(actor, created, fedi_text)| {
            linked.contains(actor)
                && *created > 0
                && (created - cand.created_at).abs() <= TWIN_WINDOW_SECS
                && twin_texts_match(&text, fedi_text)
        });
        if hit {
            out.insert(cand.id);
        }
    }
    Ok(out)
}

/// Drop Bluesky copies of Wafrn mentions from a notification list. Items are
/// Mastodon notification entities (`id` is the notification snowflake) or
/// page candidates; only mention rows can be twins.
pub async fn drop_twin_notifications(db: &Client, owner: i64, items: &mut Vec<Value>) -> Result<()> {
    let mut ids: Vec<(usize, i64)> = Vec::new();
    for (index, item) in items.iter().enumerate() {
        if let Some(id) = notification_mention_db_id(item) {
            ids.push((index, id));
        }
    }
    if ids.is_empty() {
        return Ok(());
    }
    let keys: Vec<i64> = ids.iter().map(|(_, id)| *id).collect();
    let twins = twin_mention_ids(db, owner, &keys).await?;
    if twins.is_empty() {
        return Ok(());
    }
    let skip: HashSet<usize> = ids
        .into_iter()
        .filter(|(_, id)| twins.contains(id))
        .map(|(index, _)| index)
        .collect();
    let mut kept = Vec::with_capacity(items.len());
    for (index, item) in items.drain(..).enumerate() {
        if !skip.contains(&index) {
            kept.push(item);
        }
    }
    *items = kept;
    Ok(())
}

/// The `mentions.id` behind a notification whose status is the notifier's
/// own post. Favourites and boosts point at our post and never twin here.
pub fn notification_mention_db_id(item: &Value) -> Option<i64> {
    let kind = item.get("type").and_then(Value::as_str).unwrap_or("");
    if !matches!(kind, "mention" | "quote" | "status") {
        return None;
    }
    if let Some(row) = item.get("row").filter(|row| row.is_object()) {
        if let Some(id) = row.get("id").and_then(|id| id.as_i64().or_else(|| id.as_str().and_then(|s| s.parse().ok()))) {
            return (id > 0).then_some(id);
        }
    }
    let raw = item.get("id").and_then(Value::as_str).unwrap_or("");
    let parsed = crate::notif_page::decode_notif_id(raw)?;
    (parsed.kind == "mention" && parsed.ver != 1 && parsed.db_id > 0).then_some(parsed.db_id)
}

const TWIN_WINDOW_SECS: i64 = 600;

struct TwinCandidate {
    id: i64,
    uri: String,
    did: String,
    text: String,
    created_at: i64,
}

/// Mention text with the parts the two copies never share removed: the
/// fediverse Note wraps `@user@host` mentions, links, and tags in anchors,
/// the Bluesky row may carry a `↪ QT <url>` line, and each side prints links
/// its own way.
pub fn twin_text(raw: &str) -> String {
    let raw = anchor_element().replace_all(raw, " ");
    let plain = plain_text(&raw);
    let plain = quote_line().replace_all(&plain, " ");
    let plain = mention_token().replace_all(&plain, " ");
    let plain = link_token().replace_all(&plain, " ");
    let plain = bare_domain().replace_all(&plain, " ");
    normalize_text(&plain).to_lowercase()
}

/// Same text, or (Bluesky's 300-character cut) one copy starts with the other.
pub fn twin_texts_match(a: &str, b: &str) -> bool {
    if a.is_empty() || b.is_empty() {
        return false;
    }
    if a == b {
        return true;
    }
    let trim = |s: &str| s.trim_end_matches(['…', '.']).to_string();
    let (a, b) = (trim(a), trim(b));
    let shorter = a.chars().count().min(b.chars().count());
    shorter >= 24 && (a.starts_with(&b) || b.starts_with(&a))
}

fn quote_line() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"(?m)^\s*↪ QT \S*\s*$").unwrap())
}

fn mention_token() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"(?:^|\s)[@#][\w.\-]+(?:@[\w.\-]+)?").unwrap())
}

fn anchor_element() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"(?is)<a\b[^>]*>.*?</a>").unwrap())
}

/// `example.com/path` as Mastodon and Bluesky print a link (same pattern
/// as PHP `ap_wafrn_twin_text`).
fn bare_domain() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"(?:^|\s)[\w\-]+(?:\.[\w\-]+)+(?:/\S*)?").unwrap())
}

fn link_token() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"(?i)https?://\S+").unwrap())
}

fn missing_actors_table(err: &anyhow::Error) -> bool {
    let message = err.to_string();
    message.contains("42P01") || message.contains("wafrn_bsky_actors")
}

fn plain_text(text: &str) -> String {
    let breaks = line_break().replace_all(text, "\n");
    let paras = paragraph_break().replace_all(breaks.as_ref(), "\n\n");
    let stripped = tags().replace_all(paras.as_ref(), "");
    decode_entities(stripped.as_ref()).trim().to_string()
}

fn decode_entities(input: &str) -> String {
    let mut out = String::with_capacity(input.len());
    let bytes = input.as_bytes();
    let mut index = 0;
    while index < bytes.len() {
        if bytes[index] != b'&' {
            let next = input[index..].chars().next().unwrap();
            out.push(next);
            index += next.len_utf8();
            continue;
        }
        let rest = &input[index..];
        let Some(end) = rest.find(';') else {
            out.push('&');
            index += 1;
            continue;
        };
        let token = &rest[1..end];
        let decoded = if let Some(num) = token.strip_prefix("#x").or_else(|| token.strip_prefix("#X")) {
            u32::from_str_radix(num, 16)
                .ok()
                .and_then(char::from_u32)
        } else if let Some(num) = token.strip_prefix('#') {
            num.parse::<u32>().ok().and_then(char::from_u32)
        } else {
            match token {
                "amp" => Some('&'),
                "lt" => Some('<'),
                "gt" => Some('>'),
                "quot" => Some('"'),
                "apos" => Some('\''),
                "nbsp" => Some('\u{00A0}'),
                _ => None,
            }
        };
        if let Some(ch) = decoded {
            out.push(ch);
            index += end + 1;
        } else {
            out.push('&');
            index += 1;
        }
    }
    out
}

fn activity_path() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"(?i)/fediverse/activity/post/").unwrap())
}

fn asked_prefix() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"(?u)\A.{1,240}? asked:[ \t]*").unwrap())
}

/// Wafrn's ActivityPub Note opens with `<p>anonymous asked </p>` or
/// `<p>@user@host asked </p>` (no colon), where Bluesky says "Name asked:".
fn note_asked_prefix() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"(?i)\A(?:anonymous|@\S{1,240}?)\s+asked").unwrap())
}

/// Wafrn appends this to federated asks only, never to the Bluesky copy.
fn ask_footer() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| {
        regex::Regex::new(r"(?is)\s*To properly see this ask,\s*check the post in the original instance\.?\s*\z")
            .unwrap()
    })
}

fn whitespace() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"\s+").unwrap())
}

fn text_ask() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| {
        regex::Regex::new(r"(?s)\A([^\n]{1,240}?)[ \t]+asked:[ \t]*([^\n]*)\n[ \t]*\n(.+)\z")
            .unwrap()
    })
}

fn bridgy_convert() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| {
        regex::Regex::new(
            r"(?i)^https?://bsky\.brid\.gy/convert/ap/at(?::|%3A)(?://|%2F%2F)(did(?::|%3A)[A-Za-z0-9._:%-]+?)(?:/|%2F)app\.bsky\.feed\.post(?:/|%2F)([A-Za-z0-9]+)/?(?:[?#].*)?$",
        )
        .unwrap()
    })
}

fn did_pattern() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"(did:(?:plc|web):[A-Za-z0-9._:%-]+)").unwrap())
}

fn line_break() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"(?i)<br\s*/?>").unwrap())
}

fn paragraph_break() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"(?is)</p>\s*<p[^>]*>").unwrap())
}

fn tags() -> &'static regex::Regex {
    static RE: OnceLock<regex::Regex> = OnceLock::new();
    RE.get_or_init(|| regex::Regex::new(r"<[^>]+>").unwrap())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn notification_mention_ids_come_from_mention_snowflakes_only() {
        let mention = crate::notif::snowflake_id(Some("2026-11-04T15:53:00Z"), 4242, 5);
        let follow = crate::notif::snowflake_id(Some("2026-11-04T15:53:00Z"), 4242, 6);
        assert_eq!(notification_mention_db_id(&json!({"id": mention, "type": "mention"})), Some(4242));
        assert_eq!(notification_mention_db_id(&json!({"id": mention, "type": "quote"})), Some(4242));
        assert_eq!(notification_mention_db_id(&json!({"id": mention, "type": "favourite"})), None);
        assert_eq!(notification_mention_db_id(&json!({"id": follow, "type": "mention"})), None);
        assert_eq!(notification_mention_db_id(&json!({"id": "12345", "type": "mention"})), Some(12345));
    }

    #[test]
    fn twin_text_ignores_the_parts_each_network_adds() {
        let bsky = twin_text("Uh, I wish to poke the horns.");
        let fedi = twin_text(
            "<p><span class=\"h-card\"><a href=\"https://mkultra.monster/users/cmdr_nova\">@<span>cmdr_nova</span></a></span></p><p>Uh, I wish to poke the horns.</p>",
        );
        assert_eq!(bsky, fedi);
        assert!(twin_texts_match(&bsky, &fedi));
        assert!(!twin_texts_match(&twin_text("see you tomorrow"), &twin_text("see you today")));
    }

    #[test]
    fn fixture_matches_php_cases() {
        let raw = include_str!("../../../api/fixtures/wafrn-crosspost.json");
        let file: Value = serde_json::from_str(raw).expect("fixture");
        let cases = file["cases"].as_array().expect("cases");
        assert!(cases.len() >= 16, "fixture covers linkage, fallback, and ask cards");
        for case in cases {
            let id = case["id"].as_str().unwrap_or("");
            let output = replay(case);
            let expect = case.get("expect").cloned().unwrap_or(Value::Null);
            assert_eq!(output, expect, "{id}");
        }
    }

    #[test]
    fn bridgy_convert_ids_map_to_the_bluesky_post() {
        let at = "at://did:plc:abc123/app.bsky.feed.post/3kxyz";
        for id in [
            "https://bsky.brid.gy/convert/ap/at://did:plc:abc123/app.bsky.feed.post/3kxyz",
            "https://bsky.brid.gy/convert/ap/at://did:plc:abc123/app.bsky.feed.post/3kxyz/",
            "https://bsky.brid.gy/convert/ap/at%3A%2F%2Fdid%3Aplc%3Aabc123%2Fapp.bsky.feed.post%2F3kxyz",
        ] {
            assert_eq!(bridgy_convert_at_uri(id).as_deref(), Some(at), "{id}");
        }
        for id in [
            at,
            "https://bsky.brid.gy/ap/did:plc:abc123",
            "https://fed.brid.gy/convert/ap/https://example.com/post",
            "https://example.com/notes/1",
        ] {
            assert!(bridgy_convert_at_uri(id).is_none(), "{id}");
        }
    }

    #[test]
    fn bridgy_twins_collapse_onto_the_native_copy() {
        let at = "at://did:plc:abc123/app.bsky.feed.post/3kxyz".to_string();
        let convert =
            "https://bsky.brid.gy/convert/ap/at://did:plc:abc123/app.bsky.feed.post/3kxyz".to_string();
        let wafrn = "https://app.wafrn.net/fediverse/post/42".to_string();
        let links = HashMap::from([(at.clone(), wafrn.clone())]);
        // Native Bluesky copy on the page (connected account follows the DID).
        assert_eq!(bridgy_twin_drops(&[convert.clone(), at.clone()], &links), HashSet::from([0]));
        // Native fediverse note the Bluesky post links to (Wafrn dual-publish).
        assert_eq!(bridgy_twin_drops(&[wafrn.clone(), convert.clone()], &links), HashSet::from([1]));
        // Wafrn's activity-path spelling still matches through post_identity.
        let activity = "https://app.wafrn.net/fediverse/activity/post/42".to_string();
        assert_eq!(bridgy_twin_drops(&[convert.clone(), activity], &links), HashSet::from([0]));
        // Only the Bridgy copy is on the page: it stays, a repeat does not.
        assert_eq!(bridgy_twin_drops(&[convert.clone(), convert.clone()], &HashMap::new()), HashSet::from([1]));
        assert!(bridgy_twin_drops(&[convert, wafrn], &HashMap::new()).is_empty());
    }
}
