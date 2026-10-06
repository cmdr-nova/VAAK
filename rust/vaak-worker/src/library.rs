//! Structured, read-only Library projections.
//! PHP still hydrates/render cards and owns all favourite mutations.

use anyhow::{Context, Result};
use serde::Serialize;
use serde_json::Value;

#[derive(Debug, Serialize)]
pub struct FavouritesProjection {
    pub owner_id: i64,
    pub fedi_rows: Vec<Value>,
    pub bsky_rows: Vec<Value>,
    pub total_fedi: i64,
    pub total_bsky: i64,
    pub offset: i64,
    pub limit: i64,
    pub has_more_fedi: bool,
    pub source: &'static str,
    pub note: &'static str,
}

#[derive(Debug, Serialize)]
pub struct BookmarksProjection {
    pub owner_id: i64,
    pub fedi_rows: Vec<Value>,
    pub bsky_rows: Vec<Value>,
    pub total_fedi: i64,
    pub total_bsky: i64,
    pub offset: i64,
    pub limit: i64,
    pub has_more_fedi: bool,
    pub source: &'static str,
    pub note: &'static str,
}

pub async fn favourites(cfg: &crate::config::Config, owner_id: i64, offset: i64, limit: i64) -> Result<FavouritesProjection> {
    if owner_id < 1 { anyhow::bail!("owner_id must be positive"); }
    let db = crate::db::connect(&cfg.database_url).await.context("connect favourites projection")?;
    let offset = offset.max(0);
    let limit = limit.clamp(10, 40);
    let fedi_rows = db.query(
        "SELECT id, owner_user_id, owner_actor_id, status_id, object_id, target_actor, like_activity_id, created_at
         FROM masto_favourites
         WHERE owner_user_id = $1
           AND status_id NOT LIKE 'bsky:%'
           AND object_id NOT LIKE 'at://%'
           AND object_id NOT LIKE '%bsky.app/%'
         ORDER BY created_at DESC, id DESC OFFSET $2 LIMIT $3",
        &[&owner_id, &offset, &limit],
    ).await.context("query fediverse favourites")?
      .into_iter().map(|r| serde_json::to_value(r_to_json(&r)).unwrap_or(Value::Null)).collect::<Vec<_>>();
    let total_fedi: i64 = db.query_one(
        "SELECT COUNT(*) FROM masto_favourites WHERE owner_user_id = $1 AND status_id NOT LIKE 'bsky:%' AND object_id NOT LIKE 'at://%' AND object_id NOT LIKE '%bsky.app/%'",
        &[&owner_id],
    ).await.context("count fediverse favourites")?.get(0);
    let bsky_rows = db.query(
        "SELECT post_json, favourited_at FROM bsky_favourite_cache WHERE owner_user_id = $1 ORDER BY favourited_at DESC, favourite_uri DESC OFFSET $2 LIMIT $3",
        &[&owner_id, &offset, &limit],
    ).await.unwrap_or_default().into_iter().filter_map(|r| {
        let raw: String = r.try_get(0).ok()?;
        let mut v: Value = serde_json::from_str(&raw).ok()?;
        if let Some(obj) = v.as_object_mut() { obj.insert("favourited_at".into(), Value::String(r.try_get(1).unwrap_or_default())); }
        Some(v)
    }).collect::<Vec<_>>();
    let total_bsky: i64 = db.query_opt("SELECT COUNT(*) FROM bsky_favourite_cache WHERE owner_user_id = $1", &[&owner_id]).await.ok().flatten().map(|r| r.get(0)).unwrap_or(0);
    Ok(FavouritesProjection { owner_id, fedi_rows, bsky_rows, total_fedi, total_bsky, offset, limit, has_more_fedi: offset + limit < total_fedi, source: "vaak-worker-shadow", note: "Read-only favourites projection; PHP remains the card renderer and favourite/unfavourite owner." })
}

pub async fn bookmarks(cfg: &crate::config::Config, owner_id: i64, offset: i64, limit: i64, folder_id: Option<i64>) -> Result<BookmarksProjection> {
    if owner_id < 1 { anyhow::bail!("owner_id must be positive"); }
    let db = crate::db::connect(&cfg.database_url).await.context("connect bookmarks projection")?;
    let offset = offset.max(0);
    let limit = limit.clamp(10, 40);
    let fedi_rows = db.query(
        "SELECT b.id, b.owner_user_id, b.owner_actor_id, b.status_id, b.object_id, b.created_at
         FROM masto_bookmarks b
         WHERE b.owner_user_id = $1
           AND b.status_id NOT LIKE 'bsky:%'
           AND b.object_id NOT LIKE 'at://%'
           AND b.object_id NOT LIKE '%bsky.app/%'
           AND ($4::bigint IS NULL OR EXISTS (
             SELECT 1 FROM vaak_bookmark_folder_items fi
             WHERE fi.folder_id = $4 AND fi.owner_user_id = $1
               AND (fi.status_id = b.status_id OR fi.status_id = b.object_id)
           ))
         ORDER BY b.created_at DESC, b.id DESC OFFSET $2 LIMIT $3",
        &[&owner_id, &offset, &limit, &folder_id],
    ).await.context("query fediverse bookmarks")?
      .into_iter().map(|r| serde_json::to_value(bookmark_row_to_json(&r)).unwrap_or(Value::Null)).collect::<Vec<_>>();
    let total_fedi: i64 = db.query_one(
        "SELECT COUNT(*) FROM masto_bookmarks b WHERE b.owner_user_id = $1 AND b.status_id NOT LIKE 'bsky:%' AND b.object_id NOT LIKE 'at://%' AND b.object_id NOT LIKE '%bsky.app/%'
         AND ($2::bigint IS NULL OR EXISTS (SELECT 1 FROM vaak_bookmark_folder_items fi WHERE fi.folder_id = $2 AND fi.owner_user_id = $1 AND (fi.status_id = b.status_id OR fi.status_id = b.object_id)))",
        &[&owner_id, &folder_id],
    ).await.context("count fediverse bookmarks")?.get(0);
    let bsky_rows = db.query(
        "SELECT c.post_json, c.bookmarked_at FROM bsky_bookmark_cache c WHERE c.owner_user_id = $1
         AND ($4::bigint IS NULL OR EXISTS (
           SELECT 1 FROM vaak_bookmark_folder_items fi
           LEFT JOIN masto_bookmarks mb ON mb.owner_user_id = c.owner_user_id AND mb.status_id = fi.status_id
           WHERE fi.folder_id = $4 AND fi.owner_user_id = $1
             AND (fi.status_id = c.bookmark_uri OR mb.object_id = c.bookmark_uri)
         ))
         ORDER BY c.bookmarked_at DESC, c.bookmark_uri DESC OFFSET $2 LIMIT $3",
        &[&owner_id, &offset, &limit, &folder_id],
    ).await.unwrap_or_default().into_iter().filter_map(|r| {
        let raw: String = r.try_get(0).ok()?;
        let mut v: Value = serde_json::from_str(&raw).ok()?;
        if let Some(obj) = v.as_object_mut() { obj.insert("bookmarked_at".into(), Value::String(r.try_get(1).unwrap_or_default())); }
        Some(v)
    }).collect::<Vec<_>>();
    let total_bsky: i64 = db.query_opt("SELECT COUNT(*) FROM bsky_bookmark_cache c WHERE c.owner_user_id = $1 AND ($2::bigint IS NULL OR EXISTS (SELECT 1 FROM vaak_bookmark_folder_items fi LEFT JOIN masto_bookmarks mb ON mb.owner_user_id = c.owner_user_id AND mb.status_id = fi.status_id WHERE fi.folder_id = $2 AND fi.owner_user_id = $1 AND (fi.status_id = c.bookmark_uri OR mb.object_id = c.bookmark_uri)))", &[&owner_id, &folder_id]).await.ok().flatten().map(|r| r.get(0)).unwrap_or(0);
    Ok(BookmarksProjection { owner_id, fedi_rows, bsky_rows, total_fedi, total_bsky, offset, limit, has_more_fedi: offset + limit < total_fedi, source: "vaak-worker-shadow", note: "Read-only bookmarks projection; PHP remains the folder and bookmark mutation owner." })
}

fn r_to_json(row: &tokio_postgres::Row) -> Value {
    serde_json::json!({
        "id": row.get::<_, i64>(0), "owner_user_id": row.get::<_, i64>(1),
        "owner_actor_id": row.get::<_, Option<String>>(2), "status_id": row.get::<_, String>(3),
        "object_id": row.get::<_, String>(4), "target_actor": row.get::<_, Option<String>>(5),
        "like_activity_id": row.get::<_, Option<String>>(6), "created_at": row.get::<_, String>(7)
    })
}

fn bookmark_row_to_json(row: &tokio_postgres::Row) -> Value {
    serde_json::json!({
        "id": row.get::<_, i64>(0), "owner_user_id": row.get::<_, i64>(1),
        "owner_actor_id": row.get::<_, Option<String>>(2), "status_id": row.get::<_, String>(3),
        "object_id": row.get::<_, String>(4), "created_at": row.get::<_, String>(5)
    })
}
