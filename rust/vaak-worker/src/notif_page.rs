//! Database-backed Mastodon notification pages.
//!
//! The warm Redis head only covers the newest window. Older `max_id` pages and
//! `since_id` / `min_id` pulls read mentions, follow events, and polls with
//! indexed keyset bounds, then keep a page only after the same hides PHP
//! applies: deleted rows, undone follows, blocks and mutes, muted words,
//! missing local notes, and type filters. A short page is returned only when
//! the sources are exhausted or the scan cap says the client must continue.

use std::collections::{HashMap, HashSet};

use anyhow::{Context, Result};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use tokio_postgres::Client;

use crate::hidden;
use crate::notif;

const BATCH: i64 = 80;
const MAX_ROUNDS: usize = 8;
const SNOWFLAKE_V2: i64 = 1_000_000_000_000_000_000;

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct PageRow {
    pub id: String,
    pub sort: i64,
    pub hidden: bool,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct PageDecision {
    pub ids: Vec<String>,
    pub has_more: bool,
    pub next_cursor: Option<String>,
    pub need_more: bool,
    pub oldest_seen: Option<String>,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum OwnerGate {
    Unknown,
    Serve,
}

/// A local `ap_users` row is a real owner, including disabled accounts.
/// Only a missing row is unknown.
pub fn owner_gate(row_found: bool) -> OwnerGate {
    if row_found {
        OwnerGate::Serve
    } else {
        OwnerGate::Unknown
    }
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ParsedNotifId {
    pub kind: &'static str,
    pub db_id: i64,
    pub ver: u8,
    pub type_num: i64,
}

pub fn digits(raw: &str) -> String {
    raw.chars().filter(|c| c.is_ascii_digit()).collect()
}

pub fn id_cmp(a: &str, b: &str) -> std::cmp::Ordering {
    let a = digits(a);
    let b = digits(b);
    a.len().cmp(&b.len()).then_with(|| a.cmp(&b))
}

pub fn id_lt(a: &str, b: &str) -> bool {
    id_cmp(a, b) == std::cmp::Ordering::Less
}

pub fn id_gt(a: &str, b: &str) -> bool {
    id_cmp(a, b) == std::cmp::Ordering::Greater
}

/// PHP `ap_masto_parse_notification_id` for the paging seek.
pub fn decode_notif_id(raw: &str) -> Option<ParsedNotifId> {
    let digits = digits(raw);
    if digits.is_empty() {
        return None;
    }
    let id: i64 = digits.parse().ok()?;
    if id <= 0 {
        return None;
    }
    if id < 1_000_000 {
        return Some(ParsedNotifId { kind: "mention", db_id: id, ver: 0, type_num: 5 });
    }
    if id < 2_000_000 {
        return Some(ParsedNotifId { kind: "follow", db_id: id - 1_000_000, ver: 0, type_num: 6 });
    }
    if id < 3_000_000 {
        return Some(ParsedNotifId { kind: "poll", db_id: id - 2_000_000, ver: 0, type_num: 7 });
    }
    if id >= SNOWFLAKE_V2 {
        let db_id = id.rem_euclid(100_000_000);
        let type_num = id.rem_euclid(1_000_000_000) / 100_000_000;
        let kind = match type_num {
            5 => "mention",
            6 => "follow",
            7 => "poll",
            _ => return None,
        };
        return Some(ParsedNotifId { kind, db_id, ver: 2, type_num });
    }
    let db_id = id.rem_euclid(10_000);
    let type_num = id.rem_euclid(100_000) / 10_000;
    let kind = match type_num {
        5 => "mention",
        6 => "follow",
        7 => "poll",
        _ => return None,
    };
    Some(ParsedNotifId { kind, db_id, ver: 1, type_num })
}

/// Exclusive mention id bound for a v2 type-5 cursor (`id < db_id` on
/// `idx_mentions_owner_live_id`). Other cursors need a seek, and a follow
/// db id is never used as a mention id.
pub fn fast_mention_exclusive_id(max_id: &str) -> Option<i64> {
    let parsed = decode_notif_id(max_id)?;
    if parsed.ver == 2 && parsed.kind == "mention" && parsed.db_id > 0 {
        Some(parsed.db_id)
    } else {
        None
    }
}

pub fn fast_follow_exclusive_id(max_id: &str) -> Option<i64> {
    let parsed = decode_notif_id(max_id)?;
    if parsed.ver == 2 && parsed.kind == "follow" && parsed.db_id > 0 {
        Some(parsed.db_id)
    } else {
        None
    }
}

pub fn fast_poll_exclusive_id(max_id: &str) -> Option<i64> {
    let parsed = decode_notif_id(max_id)?;
    if parsed.ver == 2 && parsed.kind == "poll" && parsed.db_id > 0 {
        Some(parsed.db_id)
    } else {
        None
    }
}

/// Newest-first page after hides. `unfinished_watermark` is the newest sort
/// an unfinished source might still produce; rows at or below it wait.
/// `None` means every source is exhausted.
pub fn decide_page(
    rows: &[PageRow],
    limit: usize,
    max_id: Option<&str>,
    since_id: Option<&str>,
    unfinished_watermark: Option<i64>,
) -> PageDecision {
    let limit = limit.clamp(1, 80);
    let max_id = max_id.map(digits).filter(|s| !s.is_empty());
    let since_id = since_id.map(digits).filter(|s| !s.is_empty());
    let mut rows: Vec<&PageRow> = rows
        .iter()
        .filter(|row| {
            let id = digits(&row.id);
            if id.is_empty() {
                return false;
            }
            if let Some(max) = max_id.as_deref() {
                if !id_lt(&id, max) {
                    return false;
                }
            }
            true
        })
        .collect();
    rows.sort_by(|a, b| b.sort.cmp(&a.sort).then_with(|| id_cmp(&b.id, &a.id)));

    let mut visible = Vec::new();
    let mut oldest_seen: Option<String> = None;
    let mut stopped_by_since = false;
    let mut blocked = false;
    for row in &rows {
        let id = digits(&row.id);
        if let Some(since) = since_id.as_deref() {
            if !id_gt(&id, since) {
                stopped_by_since = true;
                break;
            }
        }
        if let Some(mark) = unfinished_watermark {
            if row.sort <= mark {
                blocked = true;
                break;
            }
        }
        oldest_seen = Some(id.clone());
        if row.hidden {
            continue;
        }
        visible.push(id);
        if visible.len() == limit + 1 {
            break;
        }
    }
    let has_extra = visible.len() > limit;
    if has_extra {
        visible.truncate(limit);
    }
    let need_more = !has_extra && visible.len() < limit && blocked && !stopped_by_since;
    let next_cursor = if has_extra { visible.last().cloned() } else { None };
    PageDecision {
        ids: visible,
        has_more: has_extra,
        next_cursor,
        need_more,
        oldest_seen,
    }
}

/// Scan cap: the page is not proven finished. Advance past the oldest row
/// already classified so a hidden run cannot repeat the same short page.
pub fn finalize_capped(mut decision: PageDecision, limit: usize) -> PageDecision {
    if !decision.need_more {
        return decision;
    }
    decision.need_more = false;
    decision.has_more = decision.next_cursor.is_some() || decision.oldest_seen.is_some();
    if decision.ids.len() >= limit {
        decision.next_cursor = decision.ids.last().cloned();
    } else {
        decision.next_cursor = decision.oldest_seen.clone().or_else(|| decision.ids.last().cloned());
    }
    if decision.next_cursor.is_none() {
        decision.has_more = false;
    }
    decision
}

pub fn want_types(types: &[String], exclude: &[String]) -> Vec<String> {
    const ALL: [&str; 9] = [
        "mention", "follow", "favourite", "reblog", "quote", "poll", "update", "bite", "status",
    ];
    let selected: HashSet<&str> = types.iter().map(|s| s.as_str()).collect();
    let dropped: HashSet<&str> = exclude.iter().map(|s| s.as_str()).collect();
    ALL.into_iter()
        .filter(|kind| types.is_empty() || selected.contains(kind))
        .filter(|kind| !dropped.contains(kind))
        .map(str::to_string)
        .collect()
}

#[derive(Clone, Copy, Debug)]
pub struct HideFlags<'a> {
    pub deleted: bool,
    pub undone_follow: bool,
    pub blocked: bool,
    pub missing_target: bool,
    pub type_name: &'a str,
    pub want: &'a [String],
    pub texts: &'a [&'a str],
    pub muted: &'a [String],
}

/// Hides that apply on every page, including pages older than the warm head.
pub fn notification_hidden(flags: HideFlags<'_>) -> bool {
    if flags.deleted || flags.undone_follow || flags.blocked || flags.missing_target {
        return true;
    }
    if !flags.type_name.is_empty() && !flags.want.iter().any(|kind| kind == flags.type_name) {
        return true;
    }
    hidden::texts_match_muted_phrases(flags.muted, flags.texts)
}

fn raw_query(value: &str) -> String {
    let mut out = String::new();
    for b in value.bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(b as char);
            }
            _ => out.push_str(&format!("%{b:02X}")),
        }
    }
    out
}

/// Mastodon Link header. `next` uses `max_id`; `prev` uses `min_id`.
/// `since_id` is never written. `types[]` and `exclude_types[]` keep empty brackets.
pub fn notification_link(
    origin: &str,
    first_id: Option<&str>,
    has_more: bool,
    next_cursor: Option<&str>,
    limit: i64,
    types: &[String],
    exclude: &[String],
) -> String {
    let origin = origin.trim().trim_end_matches('/');
    if origin.is_empty() {
        return String::new();
    }
    let mut extra = String::new();
    for kind in types {
        if !kind.is_empty() {
            extra.push_str(&format!("&types%5B%5D={}", raw_query(kind)));
        }
    }
    for kind in exclude {
        if !kind.is_empty() {
            extra.push_str(&format!("&exclude_types%5B%5D={}", raw_query(kind)));
        }
    }
    let limit = if limit == 0 { 40 } else { limit };
    let mut parts = Vec::new();
    if has_more {
        if let Some(cursor) = next_cursor.map(str::trim).filter(|s| !s.is_empty()) {
            parts.push(format!(
                "<{origin}/api/v1/notifications?limit={limit}&max_id={}{extra}>; rel=\"next\"",
                raw_query(cursor)
            ));
        }
    }
    if let Some(first) = first_id.map(str::trim).filter(|s| !s.is_empty()) {
        parts.push(format!(
            "<{origin}/api/v1/notifications?limit={limit}&min_id={}{extra}>; rel=\"prev\"",
            raw_query(first)
        ));
    }
    parts.join(", ")
}

pub fn public_origin() -> String {
    std::env::var("VAAK_PUBLIC_ORIGIN")
        .ok()
        .map(|value| value.trim().trim_end_matches('/').to_string())
        .filter(|value| value.starts_with("https://") || value.starts_with("http://"))
        .unwrap_or_else(|| "https://vaak.monster".to_string())
}

pub fn format_notif_time(raw: Option<&str>) -> String {
    let secs = raw
        .and_then(notif::parse_epoch_secs)
        .unwrap_or_else(|| chrono::Utc::now().timestamp());
    chrono::DateTime::<chrono::Utc>::from_timestamp(secs, 0)
        .map(|dt| dt.format("%Y-%m-%dT%H:%M:%S.000Z").to_string())
        .unwrap_or_else(|| "1970-01-01T00:00:00.000Z".to_string())
}

fn php_crc32(data: &[u8]) -> u32 {
    let mut crc: u32 = 0xFFFF_FFFF;
    for &b in data {
        crc ^= u32::from(b);
        for _ in 0..8 {
            let mask = (!(crc & 1)).wrapping_add(1);
            crc = (crc >> 1) ^ (0xEDB8_8320 & mask);
        }
    }
    !crc
}

pub fn remote_account_id(seed: &str) -> String {
    let n = u64::from(php_crc32(seed.as_bytes()));
    (300_000_000 + (n % 699_999_999)).to_string()
}

fn group_key(type_name: &str, actor: &str, group_ref: &str) -> String {
    let actor = {
        let trimmed = actor.trim().trim_end_matches('/');
        if trimmed.is_empty() { "-" } else { trimmed }
    };
    let mut hasher = Sha256::new();
    hasher.update(format!("{actor}|{group_ref}").as_bytes());
    let hexed = hex::encode(hasher.finalize());
    format!("{type_name}-{}", &hexed[..24])
}

#[derive(Clone, Debug)]
#[allow(dead_code)]
struct Cand {
    id: String,
    sort: i64,
    hidden: bool,
    kind: &'static str,
    db_id: i64,
    created: String,
    actor_id: String,
    type_name: String,
    object_id: String,
    content: String,
    activity_id: String,
    is_bsky: bool,
    owner_actor: String,
    status_local_id: i64,
}

struct SourceCursor {
    wanted: bool,
    done: bool,
    loaded_once: bool,
    /// Exclusive upper id for the next batch. `None` starts at the newest row.
    before: Option<i64>,
    oldest_sort: Option<i64>,
}

fn watermark(sources: &[SourceCursor]) -> Option<i64> {
    let mut mark: Option<i64> = None;
    for source in sources {
        if !source.wanted || source.done {
            continue;
        }
        if !source.loaded_once {
            return Some(i64::MAX);
        }
        let oldest = source.oldest_sort.unwrap_or(i64::MAX);
        mark = Some(mark.map(|current| current.max(oldest)).unwrap_or(oldest));
    }
    mark
}

fn sort_secs(raw: &str) -> i64 {
    notif::parse_epoch_secs(raw).unwrap_or(0)
}

pub enum PageOutcome {
    UnknownOwner,
    Page {
        items: Vec<Value>,
        has_more: bool,
        next_cursor: String,
    },
}

struct OwnerRow {
    user_id: i64,
    actor_id: String,
    actor_key: String,
    username: String,
    disabled: bool,
}

async fn load_owner(db: &Client, owner_user_id: i64) -> Result<Option<OwnerRow>> {
    let row = db
        .query_opt(
            "SELECT COALESCE(actor_id, ''), COALESCE(actor_key, ''), COALESCE(username, ''),
                    (disabled_at IS NOT NULL)
             FROM ap_users WHERE id = $1::bigint",
            &[&owner_user_id],
        )
        .await
        .context("select notification owner")?;
    let Some(row) = row else {
        return Ok(None);
    };
    let actor_id: String = row.get(0);
    let actor_key: String = row.get(1);
    let username: String = row.get(2);
    let disabled: bool = row.get(3);
    Ok(Some(OwnerRow {
        user_id: owner_user_id,
        actor_id: actor_id.trim().trim_end_matches('/').to_string(),
        actor_key,
        username,
        disabled,
    }))
}

async fn mention_before(db: &Client, owner: i64, max_id: Option<&str>) -> Result<Option<i64>> {
    let Some(max_id) = max_id.filter(|s| !digits(s).is_empty()) else {
        return Ok(None);
    };
    if let Some(bound) = fast_mention_exclusive_id(max_id) {
        return Ok(Some(bound));
    }
    let span = db
        .query_opt(
            "SELECT MIN(id)::bigint, MAX(id)::bigint FROM mentions
             WHERE owner_user_id = $1::bigint AND deleted_at IS NULL",
            &[&owner],
        )
        .await
        .context("mention id span")?;
    let Some(span) = span else {
        return Ok(Some(0));
    };
    let min_id: Option<i64> = span.get(0);
    let max_row: Option<i64> = span.get(1);
    let (Some(mut lo), Some(mut hi)) = (min_id, max_row) else {
        return Ok(Some(0));
    };
    let mut best: Option<i64> = None;
    let mut steps = 0;
    while lo <= hi && steps < 48 {
        steps += 1;
        let mid = lo + (hi - lo) / 2;
        let row = db
            .query_opt(
                "SELECT id::bigint, created_at FROM mentions
                 WHERE owner_user_id = $1::bigint AND deleted_at IS NULL AND id <= $2::bigint
                 ORDER BY id DESC LIMIT 1",
                &[&owner, &mid],
            )
            .await
            .context("mention seek")?;
        let Some(row) = row else {
            if mid == 0 || lo == hi {
                break;
            }
            hi = mid - 1;
            continue;
        };
        let id: i64 = row.get(0);
        let created: Option<String> = row.get(1);
        let snow = notif::snowflake_id(created.as_deref(), id, 5);
        if id_lt(&snow, max_id) {
            best = Some(id);
            lo = id.saturating_add(1);
        } else if id == 0 {
            break;
        } else {
            hi = id - 1;
        }
    }
    Ok(Some(best.unwrap_or(0).saturating_add(0).max(0)))
    // `id < exclusive`. best is the newest included id, so the bound is best+1
    // only when best itself must be included. Callers use `id < exclusive`
    // where exclusive is db_id for the fast path (the cursor row). For a seek,
    // the cursor row is not a mention id, and `best` is already strictly older,
    // so the exclusive bound is best+1 (id <= best).
}

fn exclusive_from_inclusive(best_inclusive: Option<i64>, fast: bool) -> Option<i64> {
    let Some(id) = best_inclusive else {
        return None;
    };
    if fast {
        Some(id)
    } else if id <= 0 {
        Some(0)
    } else {
        Some(id.saturating_add(1))
    }
}

/// Follows aimed at one owner. Same predicate as PHP
/// `ap_masto_notifications_follow_where`: accepted local follows and Bluesky
/// follows. Page and seek queries share it instead of each carrying a copy.
const FOLLOW_OWNER_WHERE: &str = "type = 'Follow'
    AND action_taken IN ('local_accept_followback', 'bsky_follow')
    AND (target_actor = $1 OR target_actor = $2)";

async fn follow_before(db: &Client, actor: &str, max_id: Option<&str>) -> Result<Option<i64>> {
    let Some(max_id) = max_id.filter(|s| !digits(s).is_empty()) else {
        return Ok(None);
    };
    if let Some(bound) = fast_follow_exclusive_id(max_id) {
        return Ok(Some(bound));
    }
    if actor.is_empty() {
        return Ok(Some(0));
    }
    let slash = format!("{actor}/");
    let span_sql = format!("SELECT MIN(id)::bigint, MAX(id)::bigint FROM events WHERE {FOLLOW_OWNER_WHERE}");
    let span = db
        .query_opt(&span_sql, &[&actor, &slash])
        .await
        .context("follow id span")?;
    let Some(span) = span else {
        return Ok(Some(0));
    };
    let min_id: Option<i64> = span.get(0);
    let max_row: Option<i64> = span.get(1);
    let (Some(mut lo), Some(mut hi)) = (min_id, max_row) else {
        return Ok(Some(0));
    };
    let mut best: Option<i64> = None;
    let mut steps = 0;
    while lo <= hi && steps < 48 {
        steps += 1;
        let mid = lo + (hi - lo) / 2;
        let seek_sql = format!(
            "SELECT id::bigint, created_at FROM events WHERE {FOLLOW_OWNER_WHERE} AND id <= $3::bigint ORDER BY id DESC LIMIT 1"
        );
        let row = db
            .query_opt(&seek_sql, &[&actor, &slash, &mid])
            .await
            .context("follow seek")?;
        let Some(row) = row else {
            if lo == hi {
                break;
            }
            hi = mid - 1;
            continue;
        };
        let id: i64 = row.get(0);
        let created: Option<String> = row.get(1);
        let snow = notif::snowflake_id(created.as_deref(), id, 6);
        if id_lt(&snow, max_id) {
            best = Some(id);
            lo = id.saturating_add(1);
        } else if id == 0 {
            break;
        } else {
            hi = id - 1;
        }
    }
    Ok(exclusive_from_inclusive(best.or(Some(0)), false))
}

fn is_bluesky(activity_id: &str, actor_id: &str, object_id: &str) -> bool {
    activity_id.starts_with("at://")
        || actor_id.starts_with("https://bsky.app/")
        || object_id.starts_with("https://bsky.app/")
        || object_id.starts_with("bsky:")
}

async fn load_mention_batch(db: &Client, owner: i64, before: Option<i64>) -> Result<Vec<tokio_postgres::Row>> {
    if let Some(before) = before {
        db.query(
            "SELECT id::bigint, created_at, activity_id, activity_type, type,
                    object_id, owner_actor_id, actor_id, content, in_reply_to
             FROM mentions
             WHERE owner_user_id = $1::bigint AND deleted_at IS NULL AND id < $2::bigint
             ORDER BY id DESC
             LIMIT 80",
            &[&owner, &before],
        )
        .await
        .context("mention page")
    } else {
        db.query(
            "SELECT id::bigint, created_at, activity_id, activity_type, type,
                    object_id, owner_actor_id, actor_id, content, in_reply_to
             FROM mentions
             WHERE owner_user_id = $1::bigint AND deleted_at IS NULL
             ORDER BY id DESC
             LIMIT 80",
            &[&owner],
        )
        .await
        .context("mention page")
    }
}

async fn load_follow_batch(db: &Client, actor: &str, before: Option<i64>) -> Result<Vec<tokio_postgres::Row>> {
    let slash = format!("{actor}/");
    let sql = if before.is_some() {
        format!(
            "SELECT id::bigint, created_at, actor_id, action_taken FROM events WHERE {FOLLOW_OWNER_WHERE} AND id < $3::bigint ORDER BY id DESC LIMIT 80"
        )
    } else {
        format!(
            "SELECT id::bigint, created_at, actor_id, action_taken FROM events WHERE {FOLLOW_OWNER_WHERE} ORDER BY id DESC LIMIT 80"
        )
    };
    if let Some(before) = before {
        db.query(&sql, &[&actor, &slash, &before]).await.context("follow page")
    } else {
        db.query(&sql, &[&actor, &slash]).await.context("follow page")
    }
}

fn note_account_seed(actor_id: &str, username: &str, host: &str) -> String {
    let username = username.trim().trim_start_matches('@');
    let host = host.trim().trim_end_matches('/');
    if !username.is_empty() && !host.is_empty() {
        format!("acct:{}@{}", username.to_lowercase(), host.to_lowercase())
    } else {
        actor_id.trim().trim_end_matches('/').to_string()
    }
}

fn bluesky_host(host: &str) -> bool {
    let host = host.to_ascii_lowercase();
    host == "bsky.app" || host.contains("bridgy")
}

fn account_json(id: &str, username: &str, acct: &str, display: &str, url: &str) -> Value {
    let username = if username.is_empty() { "user" } else { username };
    let acct = if acct.is_empty() { username } else { acct };
    let display = if display.is_empty() { username } else { display };
    let avatar = "https://mkultra.monster/img/avatar/default.webp";
    let header = "https://mkultra.monster/img/header/default.webp";
    json!({
        "id": id,
        "username": username,
        "acct": acct,
        "display_name": display,
        "locked": false,
        "bot": false,
        "discoverable": false,
        "created_at": "1970-01-01T00:00:00.000Z",
        "note": "",
        "url": url,
        "avatar": avatar,
        "avatar_static": avatar,
        "header": header,
        "header_static": header,
        "followers_count": 0,
        "following_count": 0,
        "statuses_count": 0,
        "last_status_at": null,
        "emojis": [],
        "fields": []
    })
}

fn status_json(id: &str, created: &str, content: &str, account: &Value, uri: &str) -> Value {
    let content = if content.contains('<') {
        content.to_string()
    } else if content.is_empty() {
        String::new()
    } else {
        format!("<p>{}</p>", content.replace('<', ""))
    };
    json!({
        "id": id,
        "created_at": created,
        "edited_at": null,
        "in_reply_to_id": null,
        "in_reply_to_account_id": null,
        "sensitive": false,
        "spoiler_text": "",
        "visibility": "public",
        "language": null,
        "uri": uri,
        "url": uri,
        "replies_count": 0,
        "reblogs_count": 0,
        "favourites_count": 0,
        "quotes_count": 0,
        "favourited": false,
        "reblogged": false,
        "bookmarked": false,
        "pinned": false,
        "content": content,
        "reblog": null,
        "media_attachments": [],
        "mentions": [],
        "tags": [],
        "emojis": [],
        "card": null,
        "poll": null,
        "account": account
    })
}

struct BuiltMaps {
    local: HashMap<String, (String, String, String)>,
    remote: HashMap<String, (String, String, String)>,
    status: HashMap<String, (i64, String, String, String)>,
}

async fn load_maps(db: &Client, actors: &[String], notes: &[String]) -> BuiltMaps {
    let mut local = HashMap::new();
    let mut remote = HashMap::new();
    let mut status = HashMap::new();
    let actors: Vec<String> = actors.iter().map(|a| a.trim().trim_end_matches('/').to_string()).filter(|a| !a.is_empty()).collect();
    if !actors.is_empty() {
        if let Ok(rows) = db
            .query(
                "SELECT id::bigint, rtrim(actor_id, '/'), COALESCE(actor_key, ''), COALESCE(username, '')
                 FROM ap_users WHERE rtrim(actor_id, '/') = ANY($1)",
                &[&actors],
            )
            .await
        {
            for row in rows {
                let id: i64 = row.get(0);
                let actor: String = row.get(1);
                let key: String = row.get(2);
                let username: String = row.get(3);
                local.insert(actor, (id.to_string(), key, username));
            }
        }
        if let Ok(rows) = db
            .query(
                "SELECT rtrim(actor_id, '/'), COALESCE(username, ''), COALESCE(host, ''), COALESCE(display_name, '')
                 FROM remote_actors WHERE rtrim(actor_id, '/') = ANY($1)",
                &[&actors],
            )
            .await
        {
            for row in rows {
                let actor: String = row.get(0);
                remote.insert(actor, (row.get(1), row.get(2), row.get(3)));
            }
        }
    }
    let notes: Vec<String> = notes.iter().map(|n| n.trim().trim_end_matches('/').to_string()).filter(|n| n.starts_with("https://")).collect();
    if !notes.is_empty() {
        if let Ok(rows) = db
            .query(
                "SELECT local_id::bigint, rtrim(note_id, '/'), COALESCE(published, ''), COALESCE(content_text, '')
                 FROM masto_statuses WHERE rtrim(note_id, '/') = ANY($1)",
                &[&notes],
            )
            .await
        {
            for row in rows {
                let local_id: i64 = row.get(0);
                let note: String = row.get(1);
                status.insert(note, (local_id, row.get(2), row.get(3), String::new()));
            }
        }
    }
    BuiltMaps { local, remote, status }
}

fn path_username(actor: &str) -> String {
    let actor = actor.trim().trim_end_matches('/');
    actor.rsplit('/').next().unwrap_or("").trim().to_string()
}

fn account_for(actor: &str, maps: &BuiltMaps, owner: &OwnerRow) -> Value {
    let actor = actor.trim().trim_end_matches('/');
    if actor.is_empty() {
        let username = if !owner.username.is_empty() { owner.username.as_str() } else { owner.actor_key.as_str() };
        return account_json("0", username, username, username, &owner.actor_id);
    }
    if let Some((id, key, username)) = maps.local.get(actor) {
        let name = if !username.is_empty() { username.as_str() } else { key.as_str() };
        return account_json(id, name, name, name, actor);
    }
    if actor.starts_with("https://mkultra.monster/users/") {
        let name = path_username(actor);
        if name == owner.actor_key || name == owner.username {
            let username = if !owner.username.is_empty() { owner.username.as_str() } else { name.as_str() };
            return account_json(&owner_id_fallback(owner), username, username, username, actor);
        }
    }
    if let Some((username, host, display)) = maps.remote.get(actor) {
        let acct = if bluesky_host(host) {
            username.trim().trim_start_matches('@').to_string()
        } else if !username.is_empty() && !host.is_empty() {
            format!("{}@{}", username.trim().trim_start_matches('@'), host)
        } else {
            path_username(actor)
        };
        let seed = note_account_seed(actor, username, host);
        let id = remote_account_id(&seed);
        return account_json(&id, username, &acct, display, actor);
    }
    let username = path_username(actor);
    let host = actor.split('/').nth(2).unwrap_or("");
    let acct = if bluesky_host(host) { username.clone() } else if host.is_empty() { username.clone() } else { format!("{username}@{host}") };
    let seed = if username.is_empty() { actor.to_string() } else { note_account_seed(actor, &username, host) };
    account_json(&remote_account_id(&seed), &username, &acct, &username, actor)
}

fn owner_id_fallback(owner: &OwnerRow) -> String {
    owner.user_id.to_string()
}

fn build_entity(cand: &Cand, maps: &BuiltMaps, owner: &OwnerRow, owner_account: &Value) -> Option<Value> {
    if cand.hidden {
        return None;
    }
    let created = format_notif_time(Some(&cand.created));
    if cand.kind == "follow" {
        let account = account_for(&cand.actor_id, maps, owner);
        return Some(json!({
            "id": cand.id,
            "type": "follow",
            "group_key": format!("ungrouped-{}", cand.id),
            "created_at": created,
            "account": account,
            "status": null
        }));
    }
    if cand.kind == "poll" {
        return Some(json!({
            "id": cand.id,
            "type": "poll",
            "group_key": format!("ungrouped-{}", cand.id),
            "created_at": created,
            "account": owner_account.clone(),
            "status": null
        }));
    }
    let target = notif::mention_target_object_id(&cand.object_id);
    let account = account_for(&cand.actor_id, maps, owner);
    let local_status = maps.status.get(target.trim_end_matches('/'));
    let needs_our_post = matches!(cand.type_name.as_str(), "favourite" | "reblog");
    let (status_id, status_body, status_uri, status_account) = if let Some((local_id, published, content, _)) = local_status {
        let sid = notif::snowflake_id(Some(published), *local_id, 0);
        (sid, content.clone(), target.clone(), owner_account.clone())
    } else if needs_our_post && !cand.is_bsky {
        return None;
    } else {
        let sid = notif::snowflake_id(Some(&cand.created), cand.db_id, 2);
        let body = if cand.content.is_empty() && cand.is_bsky {
            "Bluesky post".to_string()
        } else {
            cand.content.clone()
        };
        let uri = if !target.is_empty() { target.clone() } else { cand.object_id.clone() };
        (sid, body, uri, account.clone())
    };
    let status = status_json(&status_id, &created, &status_body, &status_account, &status_uri);
    let group_ref = status_id.clone();
    Some(json!({
        "id": cand.id,
        "type": cand.type_name,
        "group_key": group_key(&cand.type_name, &cand.actor_id, &group_ref),
        "created_at": created,
        "account": account,
        "status": status
    }))
}

/// Keyset page for one owner. Unknown owners do not scan notifications.
pub async fn load_notification_page(
    db: &Client,
    owner_user_id: i64,
    limit: i64,
    types: &[String],
    exclude: &[String],
    max_id: Option<&str>,
    since_id: Option<&str>,
) -> Result<PageOutcome> {
    let Some(owner) = load_owner(db, owner_user_id).await? else {
        return Ok(PageOutcome::UnknownOwner);
    };
    let _ = owner.disabled;
    let owner_user_id = owner.user_id;
    let want = want_types(types, exclude);
    if want.is_empty() {
        return Ok(PageOutcome::Page { items: vec![], has_more: false, next_cursor: String::new() });
    }
    let limit_usize = limit.clamp(1, 80) as usize;
    let mention_types = ["mention", "favourite", "reblog", "quote", "update", "bite", "status"];
    let want_mentions = want.iter().any(|kind| mention_types.contains(&kind.as_str()));
    let want_follow = want.iter().any(|kind| kind == "follow");
    let want_poll = want.iter().any(|kind| kind == "poll");
    let mention_fast = max_id.and_then(fast_mention_exclusive_id).is_some();
    let mention_bound = if want_mentions {
        let sought = mention_before(db, owner_user_id, max_id).await?;
        exclusive_from_inclusive(sought, mention_fast)
    } else {
        None
    };
    let follow_bound = if want_follow {
        follow_before(db, &owner.actor_id, max_id).await.unwrap_or(None)
    } else {
        None
    };

    let hidden_sets = hidden::load_hidden_sets(db, owner_user_id).await.unwrap_or_default();
    let subscriptions = hidden::load_post_subscriptions(db, owner_user_id).await.unwrap_or_default();
    let mut followers = HashSet::new();
    let mut twins: HashMap<String, String> = HashMap::new();
    if want_follow && !owner.actor_id.is_empty() {
        let slash = format!("{}/", owner.actor_id);
        if let Ok(rows) = db
            .query(
                "SELECT actor_id FROM followers WHERE owner_actor_id = $1 OR owner_actor_id = $2",
                &[&owner.actor_id, &slash],
            )
            .await
        {
            for row in rows {
                let actor: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
                let actor = actor.trim().trim_end_matches('/').to_string();
                if !actor.is_empty() {
                    followers.insert(actor);
                }
            }
        }
        if let Ok(rows) = db
            .query(
                "SELECT s.handle, s.did, u.actor_id
                 FROM bsky_sessions s JOIN ap_users u ON u.id = s.owner_user_id",
                &[],
            )
            .await
        {
            for row in rows {
                let local: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
                let local = local.trim_end_matches('/').to_string();
                if local.is_empty() {
                    continue;
                }
                for col in 0..2 {
                    let key: String = row.try_get::<_, Option<String>>(col)?.unwrap_or_default();
                    let key = key.trim().trim_start_matches('@').to_lowercase();
                    if !key.is_empty() {
                        twins.insert(key, local.clone());
                    }
                }
            }
        }
    }

    let mut sources = [
        SourceCursor { wanted: want_mentions, done: !want_mentions, loaded_once: false, before: mention_bound.filter(|_| max_id.is_some()), oldest_sort: None },
        SourceCursor { wanted: want_follow, done: !want_follow || owner.actor_id.is_empty(), loaded_once: false, before: follow_bound.filter(|_| max_id.is_some()), oldest_sort: None },
        SourceCursor { wanted: want_poll, done: !want_poll, loaded_once: false, before: None, oldest_sort: None },
    ];
    // A fast or sought bound applies to the first batch. `before: Some(0)` means
    // the seek found nothing older.
    if want_mentions && max_id.is_some() {
        if mention_bound == Some(0) {
            sources[0].done = true;
            sources[0].loaded_once = true;
        }
    }
    if want_follow && max_id.is_some() && follow_bound == Some(0) {
        sources[1].done = true;
        sources[1].loaded_once = true;
    }

    let mut cands: Vec<Cand> = Vec::new();
    let mut seen_activity = HashSet::new();
    let mut decision = PageDecision { ids: vec![], has_more: false, next_cursor: None, need_more: true, oldest_seen: None };
    for _round in 0..MAX_ROUNDS {
        let mut progress = false;
        if sources[0].wanted && !sources[0].done {
            let rows = load_mention_batch(db, owner_user_id, sources[0].before).await?;
            if (rows.len() as i64) < BATCH {
                sources[0].done = true;
            }
            if rows.is_empty() {
                sources[0].done = true;
            } else {
                let last_id: i64 = rows.last().map(|row| row.get(0)).unwrap_or(0);
                sources[0].before = Some(last_id);
                progress = true;
                let mut oldest = i64::MAX;
                let mut targets = Vec::new();
                for row in &rows {
                    let object_id: String = row.try_get::<_, Option<String>>(5)?.unwrap_or_default();
                    let target = notif::mention_target_object_id(&object_id);
                    if !target.is_empty() {
                        targets.push(target);
                    }
                }
                let missing = notif::missing_local_note_uris(db, &targets).await.unwrap_or_default();
                let texts = if hidden_sets.muted_phrases.is_empty() {
                    HashMap::new()
                } else {
                    status_texts(db, &targets).await.unwrap_or_default()
                };
                for row in rows {
                    let id: i64 = row.get(0);
                    let created: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
                    let activity_id: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
                    if !activity_id.is_empty() && !seen_activity.insert(activity_id.clone()) {
                        continue;
                    }
                    let activity_type: String = row.try_get::<_, Option<String>>(3)?.unwrap_or_default();
                    let obj_type: String = row.try_get::<_, Option<String>>(4)?.unwrap_or_default();
                    let object_id: String = row.try_get::<_, Option<String>>(5)?.unwrap_or_default();
                    let owner_actor: String = row.try_get::<_, Option<String>>(6)?.unwrap_or_default();
                    let actor_id: String = row.try_get::<_, Option<String>>(7)?.unwrap_or_default();
                    let content: String = row.try_get::<_, Option<String>>(8)?.unwrap_or_default();
                    let in_reply_to: String = row.try_get::<_, Option<String>>(9)?.unwrap_or_default();
                    let our = {
                        let trimmed = owner_actor.trim().trim_end_matches('/');
                        if trimmed.is_empty() { owner.actor_id.as_str() } else { trimmed }
                    };
                    let mut type_name = notif::mention_notif_type(
                        &activity_type,
                        &obj_type,
                        &object_id,
                        &actor_id,
                        &content,
                        &in_reply_to,
                        our,
                        &activity_id,
                        &subscriptions,
                    ).unwrap_or("").to_string();
                    if type_name.is_empty() && activity_type.eq_ignore_ascii_case("update") {
                        let target = notif::mention_target_object_id(&object_id);
                        if hidden::is_favourited(db, owner_user_id, &target).await.unwrap_or(false) {
                            type_name = "update".into();
                        }
                    }
                    let target = notif::mention_target_object_id(&object_id);
                    let (body, spoiler) = texts.get(target.trim_end_matches('/')).cloned().unwrap_or_default();
                    let hidden_row = notification_hidden(HideFlags {
                        deleted: false,
                        undone_follow: false,
                        blocked: hidden_sets.is_hidden(actor_id.trim_end_matches('/')),
                        missing_target: notif::is_local_vaak_note(&target) && missing.contains(target.trim_end_matches('/')),
                        type_name: &type_name,
                        want: &want,
                        texts: &[content.as_str(), body.as_str(), spoiler.as_str()],
                        muted: &hidden_sets.muted_phrases,
                    }) || type_name.is_empty();
                    let sort = sort_secs(&created);
                    oldest = oldest.min(sort);
                    let snow = notif::snowflake_id(Some(&created), id, 5);
                    let actor_trim = actor_id.trim_end_matches('/').to_string();
                    let bsky = is_bluesky(&activity_id, &actor_trim, &object_id);
                    cands.push(Cand {
                        id: snow,
                        sort,
                        hidden: hidden_row,
                        kind: "mention",
                        db_id: id,
                        created,
                        actor_id: actor_trim,
                        type_name,
                        object_id,
                        content,
                        activity_id,
                        is_bsky: bsky,
                        owner_actor: our.to_string(),
                        status_local_id: 0,
                    });
                }
                if oldest != i64::MAX {
                    sources[0].oldest_sort = Some(oldest);
                }
            }
            sources[0].loaded_once = true;
        }
        if sources[1].wanted && !sources[1].done {
            let rows = load_follow_batch(db, &owner.actor_id, sources[1].before).await?;
            if (rows.len() as i64) < BATCH {
                sources[1].done = true;
            }
            if rows.is_empty() {
                sources[1].done = true;
            } else {
                let last_id: i64 = rows.last().map(|row| row.get(0)).unwrap_or(0);
                sources[1].before = Some(last_id);
                progress = true;
                let mut oldest = i64::MAX;
                for row in rows {
                    let id: i64 = row.get(0);
                    let created: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
                    let actor_id: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
                    let actor_trim = actor_id.trim().trim_end_matches('/').to_string();
                    let action: String = row.try_get::<_, Option<String>>(3)?.unwrap_or_default();
                    let undone = action == "local_accept_followback"
                        && !actor_trim.is_empty()
                        && !followers.is_empty()
                        && !followers.contains(&actor_trim);
                    let twin_hidden = action == "bsky_follow"
                        && notif::bsky_twin_local_actor(&actor_trim, &twins)
                            .is_some_and(|local| followers.contains(local));
                    let self_follow = !actor_trim.is_empty() && actor_trim == owner.actor_id;
                    let hidden_row = notification_hidden(HideFlags {
                        deleted: false,
                        undone_follow: undone || twin_hidden || self_follow,
                        blocked: hidden_sets.is_hidden(&actor_trim),
                        missing_target: false,
                        type_name: "follow",
                        want: &want,
                        texts: &[],
                        muted: &hidden_sets.muted_phrases,
                    });
                    let sort = sort_secs(&created);
                    oldest = oldest.min(sort);
                    cands.push(Cand {
                        id: notif::snowflake_id(Some(&created), id, 6),
                        sort,
                        hidden: hidden_row,
                        kind: "follow",
                        db_id: id,
                        created,
                        actor_id: actor_trim,
                        type_name: "follow".into(),
                        object_id: String::new(),
                        content: String::new(),
                        activity_id: String::new(),
                        is_bsky: action == "bsky_follow",
                        owner_actor: owner.actor_id.clone(),
                        status_local_id: 0,
                    });
                }
                if oldest != i64::MAX {
                    sources[1].oldest_sort = Some(oldest);
                }
            }
            sources[1].loaded_once = true;
        }
        if sources[2].wanted && !sources[2].done {
            let now = chrono::Utc::now().format("%Y-%m-%dT%H:%M:%SZ").to_string();
            let rows = match load_poll_batch(db, &now, sources[2].before).await {
                Ok(rows) => rows,
                Err(err) => {
                    tracing::warn!(error = %err, "notification poll page skipped");
                    sources[2].done = true;
                    sources[2].loaded_once = true;
                    Vec::new()
                }
            };
            if (rows.len() as i64) < BATCH {
                sources[2].done = true;
            }
            if rows.is_empty() {
                sources[2].done = true;
            } else {
                progress = true;
                let mut oldest = i64::MAX;
                let mut last_local = 0_i64;
                for row in rows {
                    let local_id: i64 = row.get(0);
                    last_local = local_id;
                    let expires: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
                    let note_id: String = row.try_get::<_, Option<String>>(2)?.unwrap_or_default();
                    let voters: String = row.try_get::<_, Option<String>>(3)?.unwrap_or_default();
                    let me = owner.actor_id.as_str();
                    let ours = !me.is_empty() && note_id.trim_end_matches('/').starts_with(&format!("{me}/notes/"));
                    let voted = voters.split('"').any(|part| part.trim_end_matches('/') == me);
                    let sort = sort_secs(&expires);
                    oldest = oldest.min(sort);
                    if !ours && !voted {
                        continue;
                    }
                    cands.push(Cand {
                        id: notif::snowflake_id(Some(&expires), local_id, 7),
                        sort,
                        hidden: !want.iter().any(|kind| kind == "poll"),
                        kind: "poll",
                        db_id: local_id,
                        created: expires,
                        actor_id: owner.actor_id.clone(),
                        type_name: "poll".into(),
                        object_id: note_id,
                        content: String::new(),
                        activity_id: String::new(),
                        is_bsky: false,
                        owner_actor: owner.actor_id.clone(),
                        status_local_id: local_id,
                    });
                }
                sources[2].before = Some(last_local);
                if oldest != i64::MAX {
                    sources[2].oldest_sort = Some(oldest);
                }
            }
            sources[2].loaded_once = true;
        }
        let page_rows: Vec<PageRow> = cands.iter().map(|cand| PageRow { id: cand.id.clone(), sort: cand.sort, hidden: cand.hidden }).collect();
        let open = sources.iter().any(|source| source.wanted && !source.done);
        let slack_limit = (limit_usize + 8).min(80);
        let slack = decide_page(&page_rows, slack_limit, max_id, since_id, watermark(&sources));
        decision = decide_page(&page_rows, limit_usize, max_id, since_id, watermark(&sources));
        if !progress || slack.ids.len() >= slack_limit || (!slack.need_more && !open) {
            break;
        }
    }
    let open = sources.iter().any(|source| source.wanted && !source.done);
    if decision.need_more || (open && !decision.has_more && decision.ids.len() < limit_usize) {
        decision.need_more = true;
        decision = finalize_capped(decision, limit_usize);
    }
    let page_rows: Vec<PageRow> = cands.iter().map(|cand| PageRow { id: cand.id.clone(), sort: cand.sort, hidden: cand.hidden }).collect();
    let wide_mark = if open { watermark(&sources) } else { None };
    let wide = decide_page(&page_rows, 80, max_id, since_id, wide_mark);
    let mut chosen: Vec<&Cand> = Vec::new();
    for id in &wide.ids {
        if let Some(cand) = cands.iter().find(|cand| cand.id == *id && !cand.hidden) {
            chosen.push(cand);
        }
    }
    let actors: Vec<String> = chosen.iter().map(|cand| cand.actor_id.clone()).collect();
    let notes: Vec<String> = chosen.iter().map(|cand| notif::mention_target_object_id(&cand.object_id)).collect();
    let maps = load_maps(db, &actors, &notes).await;
    let owner_name = if !owner.username.is_empty() { owner.username.as_str() } else { owner.actor_key.as_str() };
    let owner_account = account_for(&owner.actor_id, &maps, &owner);
    let _ = owner_name;
    let mut items = Vec::new();
    for cand in chosen {
        if items.len() == limit_usize + 1 {
            break;
        }
        if let Some(entity) = build_entity(cand, &maps, &owner, &owner_account) {
            items.push(entity);
        }
    }
    let filled_extra = items.len() > limit_usize;
    if filled_extra {
        items.truncate(limit_usize);
    }
    let has_more = if filled_extra || (items.len() == limit_usize && (wide.has_more || open)) {
        true
    } else if items.len() < limit_usize && (open || wide.has_more || decision.has_more) {
        true
    } else {
        false
    };
    let next_cursor = if !has_more {
        String::new()
    } else if items.len() >= limit_usize {
        items.last().and_then(|item| item.get("id")).and_then(|v| v.as_str()).unwrap_or("").to_string()
    } else {
        wide.oldest_seen.clone().or_else(|| decision.oldest_seen.clone()).unwrap_or_default()
    };
    let _ = BATCH;
    Ok(PageOutcome::Page { items, has_more: has_more && !next_cursor.is_empty(), next_cursor })
}

async fn status_texts(db: &Client, uris: &[String]) -> Result<HashMap<String, (String, String)>> {
    let mut keys: Vec<String> = uris.iter().map(|uri| uri.trim().trim_end_matches('/').to_string()).filter(|uri| uri.starts_with("https://")).collect();
    keys.sort();
    keys.dedup();
    let mut out = HashMap::new();
    if keys.is_empty() {
        return Ok(out);
    }
    for row in db
        .query(
            "SELECT rtrim(note_id, '/'), COALESCE(content_text, ''), COALESCE(spoiler_text, '')
             FROM masto_statuses WHERE rtrim(note_id, '/') = ANY($1)",
            &[&keys],
        )
        .await
        .context("notification muted status text")?
    {
        out.insert(row.get(0), (row.get(1), row.get(2)));
    }
    Ok(out)
}

async fn load_poll_batch(db: &Client, now: &str, before: Option<i64>) -> Result<Vec<tokio_postgres::Row>> {
    if let Some(before) = before {
        db.query(
            "SELECT local_id::bigint, expires_at, note_id, voters_json
             FROM masto_polls
             WHERE expires_at <= $1 AND local_id < $2::bigint
             ORDER BY local_id DESC
             LIMIT 80",
            &[&now, &before],
        )
        .await
        .context("poll page")
    } else {
        db.query(
            "SELECT local_id::bigint, expires_at, note_id, voters_json
             FROM masto_polls
             WHERE expires_at <= $1
             ORDER BY local_id DESC
             LIMIT 80",
            &[&now],
        )
        .await
        .context("poll page")
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn row(id: i64, hidden: bool) -> PageRow {
        PageRow { id: id.to_string(), sort: id, hidden }
    }

    #[test]
    fn prod_cursors_seek_the_right_table() {
        let follow = decode_notif_id("1792974076601145032").unwrap();
        assert_eq!(follow.kind, "follow");
        assert_eq!(follow.db_id, 1_145_032);
        assert_eq!(follow.ver, 2);
        assert!(fast_mention_exclusive_id("1792974076601145032").is_none());
        assert_eq!(fast_follow_exclusive_id("1792974076601145032"), Some(1_145_032));

        assert_eq!(fast_mention_exclusive_id("1757029703500001153"), Some(1153));
        assert_eq!(fast_mention_exclusive_id("1792936901500001890"), Some(1890));
        assert!(fast_poll_exclusive_id("1792974076601145032").is_none());
        let favourite = decode_notif_id("1792971363500001916").unwrap();
        assert_eq!(favourite.kind, "mention");
        assert_eq!(favourite.db_id, 1916);
    }

    #[test]
    fn older_page_walks_past_the_warm_head() {
        let rows: Vec<PageRow> = (1..=120).rev().map(|id| row(id, false)).collect();
        let page = decide_page(&rows, 40, Some("80"), None, None);
        assert_eq!(page.ids.len(), 40);
        assert_eq!(page.ids.first().map(String::as_str), Some("79"));
        assert_eq!(page.ids.last().map(String::as_str), Some("40"));
        assert!(page.has_more);
        assert!(!page.need_more);
        let next = page.next_cursor.clone().unwrap();
        let page2 = decide_page(&rows, 40, Some(&next), None, None);
        assert_eq!(page2.ids.first().map(String::as_str), Some("39"));
        assert_eq!(page2.ids.last().map(String::as_str), Some("1"));
        assert!(!page2.has_more);
        let mut seen = page.ids.clone();
        seen.extend(page2.ids.iter().cloned());
        seen.sort();
        seen.dedup();
        assert_eq!(seen.len(), 79);
        assert!(!seen.iter().any(|id| id == "80" || id == "40" && false));
        assert!(seen.iter().any(|id| id == "40"));
        assert!(seen.iter().any(|id| id == "39"));
    }

    #[test]
    fn since_id_keeps_only_newer_rows_and_stops() {
        let rows: Vec<PageRow> = (1..=30).rev().map(|id| row(id, false)).collect();
        let page = decide_page(&rows, 10, None, Some("20"), None);
        assert_eq!(page.ids, (21..=30).rev().map(|id| id.to_string()).collect::<Vec<_>>());
        assert!(!page.has_more);
        assert!(page.next_cursor.is_none());
        let wide = decide_page(&rows, 5, None, Some("10"), None);
        assert_eq!(wide.ids.len(), 5);
        assert_eq!(wide.ids[0], "30");
        assert!(wide.has_more);
        assert_eq!(wide.next_cursor.as_deref(), Some("26"));
    }

    #[test]
    fn hidden_row_does_not_leave_a_short_page_or_a_skip() {
        // 41 raw rows, one hidden. Limit 40 keeps 40 visible and the hidden id
        // is neither a page member nor the next cursor.
        let rows: Vec<PageRow> = (1..=41).rev().map(|id| row(id, id == 20)).collect();
        let page = decide_page(&rows, 40, None, None, None);
        assert_eq!(page.ids.len(), 40);
        assert!(!page.ids.iter().any(|id| id == "20"));
        assert_eq!(page.ids.last().map(String::as_str), Some("1"));
        assert!(!page.has_more);

        let rows: Vec<PageRow> = (1..=50).rev().map(|id| row(id, id == 45)).collect();
        let page = decide_page(&rows, 40, None, None, None);
        assert_eq!(page.ids.len(), 40);
        assert!(!page.ids.iter().any(|id| id == "45"));
        assert!(page.has_more);
        let next = page.next_cursor.clone().unwrap();
        assert_ne!(next, "45");
        let page2 = decide_page(&rows, 40, Some(&next), None, None);
        let mut union = page.ids.clone();
        union.extend(page2.ids);
        assert!(!union.iter().any(|id| id == "45"));
        assert_eq!(union.len(), 49);
        let mut sorted = union.clone();
        sorted.sort();
        sorted.dedup();
        assert_eq!(sorted.len(), union.len());
    }

    #[test]
    fn unfinished_source_does_not_emit_rows_it_might_still_sort_ahead() {
        let rows = vec![row(100, false), row(90, false), row(10, false)];
        let waiting = decide_page(&rows, 2, None, None, Some(95));
        assert_eq!(waiting.ids, vec!["100".to_string()]);
        assert!(waiting.need_more);
        assert!(!waiting.has_more);
        let done = decide_page(&rows, 2, None, None, None);
        assert_eq!(done.ids, vec!["100".to_string(), "90".to_string()]);
        assert!(done.has_more);
    }

    #[test]
    fn capped_hidden_run_advances_past_the_oldest_examined_id() {
        let rows: Vec<PageRow> = (1..=5).rev().map(|id| row(id, id != 5)).collect();
        let mut decision = decide_page(&rows, 3, None, None, Some(1));
        assert!(decision.need_more);
        assert_eq!(decision.ids, vec!["5".to_string()]);
        decision = finalize_capped(decision, 3);
        assert!(decision.has_more);
        assert_eq!(decision.next_cursor.as_deref(), Some("2"));
        let next = decide_page(&rows, 3, decision.next_cursor.as_deref(), None, None);
        assert!(next.ids.is_empty());
        assert!(!next.ids.iter().any(|id| id == "5"));
    }

    #[test]
    fn link_header_preserves_filters_and_omits_next_at_the_end() {
        let link = notification_link(
            "https://vaak.monster",
            Some("200"),
            true,
            Some("100"),
            40,
            &["mention".into()],
            &["follow".into()],
        );
        assert!(link.contains("</api/v1/notifications?limit=40&max_id=100&types%5B%5D=mention&exclude_types%5B%5D=follow>; rel=\"next\"") || link.contains("limit=40&max_id=100&types%5B%5D=mention&exclude_types%5B%5D=follow>; rel=\"next\""));
        assert!(link.contains("min_id=200"));
        assert!(link.contains("rel=\"prev\""));
        assert!(!link.contains("since_id"));
        let end = notification_link("https://vaak.monster", Some("200"), false, Some("100"), 500, &[], &[]);
        assert!(!end.contains("rel=\"next\""));
        assert!(end.contains("limit=500"));
        assert!(end.contains("rel=\"prev\""));
        let empty = notification_link("https://vaak.monster", None, false, None, 40, &[], &[]);
        assert_eq!(empty, "");
        let continue_hidden = notification_link("https://vaak.monster", None, true, Some("15"), 40, &[], &[]);
        assert!(continue_hidden.contains("rel=\"next\""));
        assert!(!continue_hidden.contains("rel=\"prev\""));
    }

    #[test]
    fn disabled_owner_is_served_and_unknown_owner_is_not() {
        assert_eq!(owner_gate(true), OwnerGate::Serve);
        assert_eq!(owner_gate(false), OwnerGate::Unknown);
    }

    #[test]
    fn older_page_hides_deleted_muted_blocked_and_still_fills() {
        let want = want_types(&[], &["follow".into()]);
        let muted = vec!["spoiler".to_string()];
        assert!(notification_hidden(HideFlags {
            deleted: true,
            undone_follow: false,
            blocked: false,
            missing_target: false,
            type_name: "favourite",
            want: &want,
            texts: &["hello"],
            muted: &muted,
        }));
        assert!(notification_hidden(HideFlags {
            deleted: false,
            undone_follow: true,
            blocked: false,
            missing_target: false,
            type_name: "follow",
            want: &want_types(&[], &[]),
            texts: &[],
            muted: &[],
        }));
        assert!(notification_hidden(HideFlags {
            deleted: false,
            undone_follow: false,
            blocked: true,
            missing_target: false,
            type_name: "mention",
            want: &want,
            texts: &["hello"],
            muted: &muted,
        }));
        assert!(notification_hidden(HideFlags {
            deleted: false,
            undone_follow: false,
            blocked: false,
            missing_target: false,
            type_name: "favourite",
            want: &want,
            texts: &["a spoiler sits here"],
            muted: &muted,
        }));
        assert!(notification_hidden(HideFlags {
            deleted: false,
            undone_follow: false,
            blocked: false,
            missing_target: false,
            type_name: "follow",
            want: &want,
            texts: &[],
            muted: &[],
        }));
        assert!(!notification_hidden(HideFlags {
            deleted: false,
            undone_follow: false,
            blocked: false,
            missing_target: false,
            type_name: "favourite",
            want: &want,
            texts: &["plain"],
            muted: &muted,
        }));
        let rows = vec![
            row(90, false),
            row(80, true),
            row(70, true),
            row(60, false),
            row(50, false),
        ];
        let page = decide_page(&rows, 3, Some("100"), None, None);
        assert_eq!(page.ids, vec!["90".to_string(), "60".to_string(), "50".to_string()]);
        assert!(!page.has_more);
    }

    #[test]
    fn remote_account_id_matches_php_crc32_range() {
        let id: u64 = remote_account_id("acct:starrypuddles.bsky.social@bsky.app").parse().unwrap();
        assert!(id >= 300_000_000 && id < 300_000_000 + 699_999_999);
        assert_eq!(php_crc32(b"123456789"), 3_421_780_262);
    }

    #[test]
    fn empty_exclude_want_list_keeps_php_order() {
        let want = want_types(&[], &[]);
        assert_eq!(
            want,
            vec!["mention", "follow", "favourite", "reblog", "quote", "poll", "update", "bite", "status"]
        );
        let filtered = want_types(&["mention".into(), "follow".into()], &["follow".into()]);
        assert_eq!(filtered, vec!["mention".to_string()]);
    }
}
