//! Indexed keyset reads for Home, Local, and Federated older pages.
//!
//! Each query is a created_at + id range on an existing index (events partial
//! indexes, per-author bsky_posts, outbox published / primary key, reblog
//! owner or created_at). Callers walk windows with `timeline_cursor::collect_async`.
//! A row that fails a block, mute, empty-stub, or dedup check is returned with
//! `keep = false` so the cursor still advances.

use tokio_postgres::Client;
use tokio_postgres::types::ToSql;

use crate::hidden::HiddenSets;
use crate::timeline_cursor::{self, CursorRow, RawWindow, SourceBound, TlCursor};

#[derive(Clone, Debug)]
pub struct OlderRow {
    pub at: String,
    pub source: String,
    pub id: String,
    pub lookup: String,
    pub actor: String,
    pub keep: bool,
}

impl CursorRow for OlderRow {
    fn cursor(&self) -> TlCursor {
        TlCursor {
            at: self.at.clone(),
            source: self.source.clone(),
            id: self.id.clone(),
        }
    }
    fn is_older_than(&self, cursor: Option<&TlCursor>) -> bool {
        timeline_cursor::is_older(&self.at, &self.source, &self.id, cursor)
    }
    fn keep(&self) -> bool {
        self.keep
    }
}

pub async fn followed_actor_ids(db: &Client, owner_actor: &str) -> Vec<String> {
    let actor = owner_actor.trim().trim_end_matches('/').to_string();
    if actor.is_empty() {
        return Vec::new();
    }
    let slashed = format!("{actor}/");
    let Ok(rows) = db
        .query(
            "SELECT actor_id FROM following WHERE owner_actor_id = $1 OR owner_actor_id = $2",
            &[&actor, &slashed],
        )
        .await
    else {
        return Vec::new();
    };
    let mut out = Vec::new();
    for row in rows {
        let id: String = row.get(0);
        let id = id.trim().trim_end_matches('/').to_string();
        if id.starts_with("https://") && !id.contains("bsky.app/") {
            out.push(id);
        }
    }
    out.sort();
    out.dedup();
    out
}

pub async fn followed_bsky_dids(db: &Client, owner_user_id: i64) -> Vec<String> {
    if owner_user_id < 1 {
        return Vec::new();
    }
    let Ok(rows) = db
        .query(
            "SELECT target_did FROM bsky_graph_sync WHERE owner_user_id = $1 AND kind = 'follow'",
            &[&owner_user_id],
        )
        .await
    else {
        return Vec::new();
    };
    let mut out = Vec::new();
    for row in rows {
        let did: String = row.get(0);
        let did = did.trim().to_string();
        if did.starts_with("did:") {
            out.push(did);
        }
    }
    out.sort();
    out.dedup();
    out
}

pub async fn raw_window(
    db: &Client,
    view: &str,
    owner_user_id: i64,
    owner_actor: &str,
    actors: &[String],
    dids: &[String],
    hidden: &HiddenSets,
    cursor: Option<TlCursor>,
    fetch_limit: i64,
) -> RawWindow<OlderRow> {
    let fetch_limit = fetch_limit.clamp(1, 120);
    let view = match view {
        "local" => "local",
        "feed" => "feed",
        _ => "home",
    };
    let mut parts: Vec<Vec<OlderRow>> = Vec::new();
    let mut any_full = false;
    let pull = |rows: Vec<OlderRow>, full: bool, parts: &mut Vec<Vec<OlderRow>>, any_full: &mut bool| {
        if full {
            *any_full = true;
        }
        if !rows.is_empty() {
            parts.push(rows);
        }
    };

    if view == "feed" || view == "home" {
        let (rows, full) = if view == "feed" {
            events_firehose(db, &cursor, fetch_limit, hidden).await
        } else {
            events_followed(db, actors, &cursor, fetch_limit, hidden).await
        };
        pull(rows, full, &mut parts, &mut any_full);
    }
    if view == "local" || view == "home" || view == "feed" {
        let (rows, full) = outbox_page(db, view, owner_actor, &cursor, fetch_limit).await;
        pull(rows, full, &mut parts, &mut any_full);
    }
    if view == "local" || view == "home" || view == "feed" {
        let (rows, full) = reblog_page(db, view, owner_user_id, &cursor, fetch_limit).await;
        pull(rows, full, &mut parts, &mut any_full);
    }
    if view == "home" && !dids.is_empty() {
        let (rows, full) = bsky_page(db, dids, &cursor, fetch_limit, hidden).await;
        pull(rows, full, &mut parts, &mut any_full);
    }
    if view == "home" {
        let (rows, full) = nostr_page(db, owner_user_id, &cursor, fetch_limit).await;
        pull(rows, full, &mut parts, &mut any_full);
    }

    let mut rows = Vec::new();
    for part in parts {
        rows.extend(part);
    }
    sort_newest(&mut rows);
    let truncated = rows.len() > fetch_limit as usize;
    if truncated {
        rows.truncate(fetch_limit as usize);
    }
    RawWindow {
        rows,
        full: any_full || truncated,
    }
}

fn sort_newest(rows: &mut [OlderRow]) {
    rows.sort_by(|a, b| {
        let bc = b.cursor();
        let ac = a.cursor();
        if timeline_cursor::is_older(&a.at, &a.source, &a.id, Some(&bc)) {
            std::cmp::Ordering::Greater
        } else if timeline_cursor::is_older(&b.at, &b.source, &b.id, Some(&ac)) {
            std::cmp::Ordering::Less
        } else {
            std::cmp::Ordering::Equal
        }
    });
}

fn bound_of(cursor: &Option<TlCursor>, source: &str) -> SourceBound {
    timeline_cursor::source_bound(cursor.as_ref(), source)
}

fn predicate(bound: &SourceBound, at_col: &str, id_col: &str, n: i32) -> (String, i32) {
    // Same order as an OR keyset, written so a DESC (timestamp, id) index can
    // seek. The OR form is a filter and walks every newer row.
    match bound.op {
        "lt" => (format!("{at_col} < ${n}"), n + 1),
        "lte" => (format!("{at_col} <= ${n}"), n + 1),
        "lt_id" => (format!("({at_col}, {id_col}) < (${n}, ${})", n + 1), n + 2),
        _ => ("TRUE".to_string(), n),
    }
}

fn bind_bound<'a>(
    bound: &'a SourceBound,
    numeric_id: bool,
    id_i: &'a i64,
    limit: &'a i64,
    params: &mut Vec<&'a (dyn ToSql + Sync)>,
) {
    match bound.op {
        "lt" | "lte" => params.push(&bound.at),
        "lt_id" => {
            params.push(&bound.at);
            if numeric_id {
                params.push(id_i);
            } else {
                params.push(&bound.id);
            }
        }
        _ => {}
    }
    params.push(limit);
}

fn event_visible(summary: &str, media: &str, spoiler: &str, sensitive: bool, event_type: &str) -> bool {
    if event_type.eq_ignore_ascii_case("announce") {
        return true;
    }
    let media_empty = media.trim().is_empty() || media.trim() == "[]";
    let has_cw = sensitive || !spoiler.trim().is_empty();
    !(summary.trim().is_empty() && media_empty && !has_cw)
}

fn object_is_bsky(object_id: &str) -> bool {
    let object = object_id.trim().trim_end_matches('/').to_ascii_lowercase();
    object.starts_with("at://") || object.contains("bsky.app/") || object.contains("bsky.mkultra.monster/")
}

fn reblog_is_bsky(status_id: &str, object_id: &str) -> bool {
    let status = status_id.trim().to_ascii_lowercase();
    let object = object_id.trim().to_ascii_lowercase();
    status.starts_with("bsky-repost-")
        || object.starts_with("https://bsky.app/")
        || object.contains("bsky.mkultra.monster/")
        || object.starts_with("at://")
}

fn outbox_is_bsky_import(raw: &str) -> bool {
    let raw = raw.trim();
    if raw.is_empty() {
        return false;
    }
    let Ok(v) = serde_json::from_str::<serde_json::Value>(raw) else {
        return false;
    };
    v.get("object")
        .and_then(|o| o.get("vaakOrigin"))
        .and_then(|x| x.as_str())
        == Some("bluesky")
}

const EVENT_COLS: &str = "id::bigint AS id, COALESCE(created_at::text,'') AS created_at, COALESCE(type,'') AS type, \
     COALESCE(actor_id,'') AS actor_id, COALESCE(object_id,'') AS object_id, COALESCE(summary,'') AS summary, \
     COALESCE(media_urls,'') AS media_urls, COALESCE(spoiler_text,'') AS spoiler_text, \
     COALESCE(sensitive,0)::bigint AS sensitive";

fn map_event_row(row: &tokio_postgres::Row, hidden: &HiddenSets, feed: bool) -> Option<OlderRow> {
    let id: i64 = row.get(0);
    if id < 1 {
        return None;
    }
    let at: String = row.get(1);
    if at.is_empty() {
        return None;
    }
    let event_type: String = row.get(2);
    let actor: String = row.get(3);
    let object_id: String = row.get(4);
    let summary: String = row.get(5);
    let media: String = row.get(6);
    let spoiler: String = row.get(7);
    let sensitive = row.get::<_, i64>(8) != 0;
    let actor_key = actor.trim().trim_end_matches('/').to_string();
    let mut keep = event_visible(&summary, &media, &spoiler, sensitive, &event_type)
        && !hidden.is_hidden(&actor_key);
    if feed && object_is_bsky(&object_id) && !actor_key.starts_with("https://mkultra.monster/users/") {
        keep = false;
    }
    if feed && !matches!(
        event_type.to_ascii_lowercase().as_str(),
        "create" | "quote" | "quotepost"
    ) {
        keep = false;
    }
    Some(OlderRow {
        at,
        source: "event".into(),
        id: id.to_string(),
        lookup: id.to_string(),
        actor: actor_key,
        keep,
    })
}

async fn events_firehose(
    db: &Client,
    cursor: &Option<TlCursor>,
    fetch_limit: i64,
    hidden: &HiddenSets,
) -> (Vec<OlderRow>, bool) {
    let mut rows = Vec::new();
    let mut full = false;
    for (label, where_sql) in [
        (
            "create",
            "type = 'Create' AND action_taken IN ('log', 'local_observe')",
        ),
        (
            "quote-log",
            "type = 'Quote' AND action_taken = 'log'",
        ),
        (
            "quote-observe",
            "type = 'Quote' AND action_taken = 'local_observe'",
        ),
        (
            "quotepost-log",
            "type = 'QuotePost' AND action_taken = 'log'",
        ),
        (
            "quotepost-observe",
            "type = 'QuotePost' AND action_taken = 'local_observe'",
        ),
    ] {
        let bound = bound_of(cursor, "event");
        let (pred, next_n) = predicate(&bound, "created_at", "id", 1);
        let sql = format!(
            "SELECT {EVENT_COLS} FROM events WHERE {where_sql} AND ({pred}) ORDER BY created_at DESC, id DESC LIMIT ${next_n}"
        );
        // `none` uses TRUE and LIMIT $1. The predicate function returns TRUE
        // without consuming a placeholder, so the LIMIT index is 1.
        let sql = if bound.op == "none" {
            format!(
                "SELECT {EVENT_COLS} FROM events WHERE {where_sql} ORDER BY created_at DESC, id DESC LIMIT $1"
            )
        } else {
            sql
        };
        let id_i = bound.id.parse::<i64>().unwrap_or(0);
        let mut params: Vec<&(dyn ToSql + Sync)> = Vec::new();
        bind_bound(&bound, true, &id_i, &fetch_limit, &mut params);
        match db.query(&sql, &params).await {
            Ok(found) => {
                if found.len() as i64 >= fetch_limit {
                    full = true;
                }
                for row in found {
                    if let Some(mapped) = map_event_row(&row, hidden, true) {
                        rows.push(mapped);
                    }
                }
            }
            Err(e) => tracing::warn!(error = %e, kind = label, "federated older events"),
        }
    }
    (rows, full)
}

async fn events_followed(
    db: &Client,
    actors: &[String],
    cursor: &Option<TlCursor>,
    fetch_limit: i64,
    hidden: &HiddenSets,
) -> (Vec<OlderRow>, bool) {
    if actors.is_empty() {
        return (Vec::new(), false);
    }
    let mut rows = Vec::new();
    let mut full = false;
    for (label, type_sql) in [
        (
            "followed-create",
            "type IN ('Create', 'Announce') AND action_taken IN ('log', 'local_observe')",
        ),
        (
            "followed-quote-log",
            "type = 'Quote' AND action_taken = 'log'",
        ),
        (
            "followed-quote-observe",
            "type = 'Quote' AND action_taken = 'local_observe'",
        ),
        (
            "followed-quotepost-log",
            "type = 'QuotePost' AND action_taken = 'log'",
        ),
        (
            "followed-quotepost-observe",
            "type = 'QuotePost' AND action_taken = 'local_observe'",
        ),
    ] {
        let bound = bound_of(cursor, "event");
        let (pred, next_n) = predicate(&bound, "created_at", "id", 2);
        let limit_n = if bound.op == "none" { 2 } else { next_n };
        let pred_sql = if bound.op == "none" {
            String::new()
        } else {
            format!(" AND ({pred})")
        };
        let sql = format!(
            "SELECT e.* \
             FROM unnest($1::text[]) AS f(actor_id) \
             JOIN LATERAL ( \
               SELECT {EVENT_COLS} \
               FROM events \
               WHERE actor_id = f.actor_id AND {type_sql}{pred_sql} \
               ORDER BY created_at DESC, id DESC \
               LIMIT ${limit_n} \
             ) e ON true"
        );
        let id_i = bound.id.parse::<i64>().unwrap_or(0);
        let mut params: Vec<&(dyn ToSql + Sync)> = vec![&actors];
        bind_bound(&bound, true, &id_i, &fetch_limit, &mut params);
        match db.query(&sql, &params).await {
            Ok(found) => {
                if found.len() as i64 >= fetch_limit {
                    full = true;
                }
                for row in found {
                    if let Some(mapped) = map_event_row(&row, hidden, false) {
                        rows.push(mapped);
                    }
                }
            }
            Err(e) => tracing::warn!(error = %e, kind = label, "home older events"),
        }
    }
    (rows, full)
}

async fn outbox_page(
    db: &Client,
    view: &str,
    owner_actor: &str,
    cursor: &Option<TlCursor>,
    fetch_limit: i64,
) -> (Vec<OlderRow>, bool) {
    let bound = bound_of(cursor, "outbox");
    let (pred, next_n) = predicate(&bound, "published", "id", 1);
    let limit_n = if bound.op == "none" { 1 } else { next_n };
    let pred_sql = if bound.op == "none" {
        "TRUE".to_string()
    } else {
        pred
    };
    let sql = format!(
        "SELECT id, COALESCE(published::text,''), COALESCE(raw_create_json,'') \
         FROM outbox_notes WHERE ({pred_sql}) ORDER BY published DESC, id DESC LIMIT ${limit_n}"
    );
    let id_i = 0i64;
    let mut params: Vec<&(dyn ToSql + Sync)> = Vec::new();
    bind_bound(&bound, false, &id_i, &fetch_limit, &mut params);
    let found = match db.query(&sql, &params).await {
        Ok(rows) => rows,
        Err(e) => {
            tracing::warn!(error = %e, "older outbox");
            return (Vec::new(), false);
        }
    };
    let full = found.len() as i64 >= fetch_limit;
    let owner = owner_actor.trim().trim_end_matches('/').to_string();
    let mut rows = Vec::new();
    for row in found {
        let id: String = row.get(0);
        let at: String = row.get(1);
        let raw: String = row.get(2);
        if at.is_empty() || id.is_empty() {
            continue;
        }
        let local = id.starts_with("https://mkultra.monster/users/") && id.contains("/notes/");
        let own = !owner.is_empty() && (id.starts_with(&owner) || id.starts_with(&format!("{owner}/")));
        let mut keep = local && !outbox_is_bsky_import(&raw);
        if view != "local" {
            keep = keep && own;
        }
        rows.push(OlderRow {
            at,
            source: "outbox".into(),
            id: id.trim_end_matches('/').to_string(),
            lookup: id.trim_end_matches('/').to_string(),
            actor: String::new(),
            keep,
        });
    }
    (rows, full)
}

async fn reblog_page(
    db: &Client,
    view: &str,
    owner_user_id: i64,
    cursor: &Option<TlCursor>,
    fetch_limit: i64,
) -> (Vec<OlderRow>, bool) {
    let bound = bound_of(cursor, "boost");
    if view == "local" {
        let (pred, next_n) = predicate(&bound, "created_at", "id", 1);
        let limit_n = if bound.op == "none" { 2 } else { next_n };
        let pred_sql = if bound.op == "none" {
            String::new()
        } else {
            format!(" AND ({pred})")
        };
        let sql = format!(
            "SELECT r.id, r.status_id, r.created_at, r.owner_actor_id, r.object_id \
             FROM ap_users u \
             JOIN LATERAL ( \
               SELECT id::bigint, COALESCE(status_id,''), COALESCE(created_at::text,''), \
                      COALESCE(owner_actor_id,''), COALESCE(object_id,'') \
               FROM masto_reblogs \
               WHERE owner_user_id = u.id{pred_sql} \
               ORDER BY created_at DESC, id DESC \
               LIMIT ${limit_n} \
             ) r ON true"
        );
        // none: LIMIT $2 because $1 is unused... local none has no leading param.
        // Fix: for none, limit is $1. I set limit_n to 2 incorrectly for the no-pred case
        // when there is no $1 array. Recreate none SQL below if needed.
        let sql = if bound.op == "none" {
            "SELECT r.id, r.status_id, r.created_at, r.owner_actor_id, r.object_id \
             FROM ap_users u \
             JOIN LATERAL ( \
               SELECT id::bigint, COALESCE(status_id,''), COALESCE(created_at::text,''), \
                      COALESCE(owner_actor_id,''), COALESCE(object_id,'') \
               FROM masto_reblogs \
               WHERE owner_user_id = u.id \
               ORDER BY created_at DESC, id DESC \
               LIMIT $1 \
             ) r ON true"
                .to_string()
        } else {
            sql
        };
        let id_i = bound.id.parse::<i64>().unwrap_or(0);
        let mut params: Vec<&(dyn ToSql + Sync)> = Vec::new();
        bind_bound(&bound, true, &id_i, &fetch_limit, &mut params);
        return map_reblogs(db, &sql, &params, fetch_limit, true).await;
    }
    if owner_user_id < 1 {
        return (Vec::new(), false);
    }
    let (pred, next_n) = predicate(&bound, "created_at", "id", 2);
    let limit_n = if bound.op == "none" { 2 } else { next_n };
    let pred_sql = if bound.op == "none" {
        String::new()
    } else {
        format!(" AND ({pred})")
    };
    let sql = format!(
        "SELECT id::bigint, COALESCE(status_id,''), COALESCE(created_at::text,''), \
                COALESCE(owner_actor_id,''), COALESCE(object_id,'') \
         FROM masto_reblogs WHERE owner_user_id = $1{pred_sql} \
         ORDER BY created_at DESC, id DESC LIMIT ${limit_n}"
    );
    let id_i = bound.id.parse::<i64>().unwrap_or(0);
    let mut params: Vec<&(dyn ToSql + Sync)> = vec![&owner_user_id];
    bind_bound(&bound, true, &id_i, &fetch_limit, &mut params);
    map_reblogs(db, &sql, &params, fetch_limit, false).await
}

async fn map_reblogs(
    db: &Client,
    sql: &str,
    params: &[&(dyn ToSql + Sync)],
    fetch_limit: i64,
    local_only: bool,
) -> (Vec<OlderRow>, bool) {
    let found = match db.query(sql, params).await {
        Ok(rows) => rows,
        Err(e) => {
            tracing::warn!(error = %e, "older reblogs");
            return (Vec::new(), false);
        }
    };
    let full = found.len() as i64 >= fetch_limit;
    let mut rows = Vec::new();
    for row in found {
        let id: i64 = row.get(0);
        let status_id: String = row.get(1);
        let at: String = row.get(2);
        let owner_actor: String = row.get(3);
        let object_id: String = row.get(4);
        if id < 1 || at.is_empty() || status_id.is_empty() {
            continue;
        }
        let local = owner_actor.starts_with("https://mkultra.monster/users/");
        let mut keep = !reblog_is_bsky(&status_id, &object_id);
        if local_only {
            keep = keep && local;
        }
        rows.push(OlderRow {
            at,
            source: "boost".into(),
            id: id.to_string(),
            lookup: status_id,
            actor: owner_actor.trim_end_matches('/').to_string(),
            keep,
        });
    }
    (rows, full)
}

async fn bsky_page(
    db: &Client,
    dids: &[String],
    cursor: &Option<TlCursor>,
    fetch_limit: i64,
    hidden: &HiddenSets,
) -> (Vec<OlderRow>, bool) {
    let bound = bound_of(cursor, "bsky");
    let (pred, next_n) = predicate(&bound, "indexed_at", "bsky_uri", 2);
    let limit_n = if bound.op == "none" { 2 } else { next_n };
    let pred_sql = if bound.op == "none" {
        String::new()
    } else {
        format!(" AND ({pred})")
    };
    let sql = format!(
        "SELECT p.bsky_uri, p.indexed_at, p.author_did \
         FROM unnest($1::text[]) AS g(target_did) \
         JOIN LATERAL ( \
           SELECT bsky_uri, COALESCE(indexed_at::text,'') AS indexed_at, COALESCE(author_did,'') AS author_did \
           FROM bsky_posts \
           WHERE author_did = g.target_did AND text IS NOT NULL{pred_sql} \
           ORDER BY indexed_at DESC, bsky_uri DESC \
           LIMIT ${limit_n} \
         ) p ON true"
    );
    let id_i = 0i64;
    let mut params: Vec<&(dyn ToSql + Sync)> = vec![&dids];
    bind_bound(&bound, false, &id_i, &fetch_limit, &mut params);
    let found = match db.query(&sql, &params).await {
        Ok(rows) => rows,
        Err(e) => {
            tracing::warn!(error = %e, "older bsky");
            return (Vec::new(), false);
        }
    };
    let full = found.len() as i64 >= fetch_limit;
    let mut rows = Vec::new();
    for row in found {
        let uri: String = row.get(0);
        let at: String = row.get(1);
        let did: String = row.get(2);
        if uri.is_empty() || at.is_empty() {
            continue;
        }
        let actor = format!("https://bsky.app/profile/{did}");
        let keep = !hidden.is_hidden(&actor) && !hidden.is_hidden(&did);
        rows.push(OlderRow {
            at,
            source: "bsky".into(),
            id: uri.clone(),
            lookup: uri,
            actor: did,
            keep,
        });
    }
    (rows, full)
}

/// Same text form PHP `gmdate('c')` gives events, so cursors compare as strings.
pub fn nostr_at(created_at: i64) -> String {
    chrono::DateTime::from_timestamp(created_at, 0)
        .map(|t| t.format("%Y-%m-%dT%H:%M:%S+00:00").to_string())
        .unwrap_or_default()
}

/// Cursor time as unix seconds, rounded up so the SQL bound never drops a
/// row the string comparison would keep. None when the time is unreadable.
fn cursor_epoch_ceil(at: &str) -> Option<i64> {
    let at = at.trim();
    let parsed = chrono::DateTime::parse_from_rfc3339(at)
        .ok()
        .or_else(|| chrono::DateTime::parse_from_str(at, "%Y-%m-%d %H:%M:%S%.f%#z").ok())?;
    let secs = parsed.timestamp();
    Some(if parsed.timestamp_subsec_nanos() > 0 { secs + 1 } else { secs })
}

/// Linked Nostr follows (Settings > Profile npub). The warm head carries
/// these as ranked keys; older pages read them here so scrolling past the
/// head keeps them.
async fn nostr_page(
    db: &Client,
    owner_user_id: i64,
    cursor: &Option<TlCursor>,
    fetch_limit: i64,
) -> (Vec<OlderRow>, bool) {
    let at_most = match cursor {
        None => i64::MAX,
        Some(c) => match cursor_epoch_ceil(&c.at) {
            Some(secs) => secs,
            None => return (Vec::new(), false),
        },
    };
    let keys = match crate::nostr::home::older_keys(db, owner_user_id, at_most, fetch_limit).await {
        Ok(keys) => keys,
        Err(e) => {
            tracing::warn!(error = %format!("{e:#}"), "older nostr");
            return (Vec::new(), false);
        }
    };
    let full = keys.len() as i64 >= fetch_limit;
    let rows = keys
        .into_iter()
        .map(|k| OlderRow {
            at: nostr_at(k.sort),
            source: "nostr".into(),
            id: k.id.clone(),
            lookup: k.id,
            actor: String::new(),
            keep: true,
        })
        .filter(|row| !row.at.is_empty() && row.is_older_than(cursor.as_ref()))
        .collect();
    (rows, full)
}

#[cfg(test)]
mod nostr_tests {
    use super::*;

    #[test]
    fn nostr_rows_use_the_events_time_format() {
        assert_eq!(nostr_at(1_793_178_282), "2026-10-28T09:04:42+00:00");
    }

    #[test]
    fn cursor_bound_reads_php_and_postgres_times() {
        assert_eq!(cursor_epoch_ceil("2026-10-28T09:04:42+00:00"), Some(1_793_178_282));
        assert_eq!(cursor_epoch_ceil("2026-10-28T11:04:42+02:00"), Some(1_793_178_282));
        assert_eq!(cursor_epoch_ceil("2026-10-28 09:04:41.5+00"), Some(1_793_178_282));
        assert_eq!(cursor_epoch_ceil("not a time"), None);
    }

    #[test]
    fn same_second_nostr_rows_follow_the_cursor_order() {
        let cursor = TlCursor { at: nostr_at(100), source: "nostr".into(), id: "bb".into() };
        let row = |id: &str, at: i64| OlderRow {
            at: nostr_at(at),
            source: "nostr".into(),
            id: id.into(),
            lookup: id.into(),
            actor: String::new(),
            keep: true,
        };
        assert!(row("aa", 100).is_older_than(Some(&cursor)));
        assert!(!row("bb", 100).is_older_than(Some(&cursor)));
        assert!(!row("cc", 100).is_older_than(Some(&cursor)));
        assert!(row("zz", 99).is_older_than(Some(&cursor)));
        let event_cursor = TlCursor { at: nostr_at(100), source: "event".into(), id: "7".into() };
        assert!(row("zz", 100).is_older_than(Some(&event_cursor)), "nostr ranks after events at one time");
    }
}
