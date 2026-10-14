//! Structured, read-only Library projections.
//! PHP still owns favourite and bookmark mutations. Native favourites,
//! bookmark cards, quote/preview cards, and bookmark-folder chrome stay
//! behind `VAAK_LIBRARY_NATIVE_RENDER`.

use anyhow::{Context, Result};
use serde::Serialize;
use serde_json::Value;

#[path = "library_mixed.rs"]
pub mod mixed;
#[path = "library_actions.rs"]
pub mod actions;
#[path = "library_context.rs"]
pub mod context;
#[path = "library_facts.rs"]
pub(crate) mod facts;
#[path = "library_bridge.rs"]
pub(crate) mod bridge;
#[path = "library_source.rs"]
pub(crate) mod source;
#[path = "library_bookmark.rs"]
pub(crate) mod bookmark;
#[path = "library_favourite.rs"]
pub(crate) mod favourite;
#[path = "library_own.rs"]
pub(crate) mod own;
#[path = "library_rss.rs"]
pub(crate) mod rss;
#[path = "library_bsky.rs"]
pub(crate) mod bsky;
#[path="library_bsky_normalize.rs"]
pub(crate) mod bsky_normalize;
#[path = "library_card.rs"]
pub(crate) mod card;
#[path = "library_body.rs"]
pub(crate) mod body;
#[path = "library_preview.rs"]
pub(crate) mod preview;
#[path = "library_quote.rs"]
pub(crate) mod quote;
#[path = "library_folders.rs"]
pub(crate) mod folders;
#[path = "library_selection.rs"]
pub(crate) mod selection;

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
    pub has_more_bsky: bool,
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
    pub has_more_bsky: bool,
    pub source: &'static str,
    pub note: &'static str,
}

pub async fn favourites(cfg: &crate::config::Config, owner_id: i64, offset: i64, limit: i64) -> Result<FavouritesProjection> {
    if owner_id < 1 { anyhow::bail!("owner_id must be positive"); }
    let db = crate::db::connect(&cfg.database_url).await.context("connect favourites projection")?;
    let offset = offset.max(0);
    let limit = limit.clamp(10, 40);
    // PHP first hydrates up to clamp(offset + limit + 20, 1, 80)
    // successful rows from twice that raw count, then excludes Bluesky and
    // slices. SQL OFFSET, protocol filtering or an added tie-break changes it.
    let raw_limit = (offset.saturating_add(limit).saturating_add(20)).clamp(1, 80) * 2;
    let fedi_rows = db.query(
        "SELECT id::bigint, owner_user_id::bigint, owner_actor_id, status_id, object_id, target_actor, like_activity_id, created_at
         FROM masto_favourites WHERE owner_user_id = $1::bigint
         ORDER BY created_at DESC LIMIT $2",
        &[&owner_id, &raw_limit],
    ).await.context("query exact raw favourite hydration window")?
      .into_iter().map(|r| r_to_json(&r)).collect::<Result<Vec<_>>>()?;
    let total_fedi: i64 = db.query_one(
        "SELECT COUNT(*) FROM masto_favourites WHERE owner_user_id = $1::bigint AND status_id NOT LIKE 'bsky:%' AND object_id NOT LIKE 'at://%' AND object_id NOT LIKE '%bsky.app/%'",
        &[&owner_id],
    ).await.context("count fediverse favourites")?.get(0);
    let bsky_rows = optional_cache_rows(&db,
        "SELECT post_json, favourited_at FROM bsky_favourite_cache WHERE owner_user_id = $1::bigint ORDER BY favourited_at DESC, favourite_uri DESC OFFSET $2 LIMIT $3",
        &[&owner_id, &offset, &limit],
    ).await?;
    let bsky_rows = cached_posts(bsky_rows, "favourited_at")?;
    let total_bsky: i64 = optional_cache_count(&db,"SELECT COUNT(*) FROM bsky_favourite_cache WHERE owner_user_id = $1::bigint", &[&owner_id]).await?;
    Ok(FavouritesProjection { owner_id, fedi_rows, bsky_rows, total_fedi, total_bsky, offset, limit, has_more_fedi: offset + limit < total_fedi, has_more_bsky: offset + limit < total_bsky, source: "vaak-worker-shadow", note: "Read-only favourites projection; PHP remains the card renderer and favourite/unfavourite owner." })
}

pub async fn bookmarks(cfg: &crate::config::Config, owner_id: i64, offset: i64, limit: i64, folder_id: Option<i64>) -> Result<BookmarksProjection> {
    if owner_id < 1 { anyhow::bail!("owner_id must be positive"); }
    let db = crate::db::connect(&cfg.database_url).await.context("connect bookmarks projection")?;
    let offset = offset.max(0);
    let limit = limit.clamp(10, 40);
    let folder_match = folder_fedi_match_sql();
    let fedi_sql = format!("SELECT b.id::bigint, b.owner_user_id::bigint, b.owner_actor_id, b.status_id, b.object_id, b.created_at
         FROM masto_bookmarks b
         WHERE b.owner_user_id = $1::bigint
           AND b.status_id NOT LIKE 'bsky:%'
           AND b.object_id NOT LIKE 'at://%'
           AND b.object_id NOT LIKE '%bsky.app/%'
           AND ($4::bigint IS NULL OR EXISTS (
             SELECT 1 FROM vaak_bookmark_folder_items fi
             WHERE fi.folder_id = $4::bigint AND fi.owner_user_id = $1::bigint
               AND {folder_match}
           ))
         ORDER BY b.created_at DESC, b.id DESC OFFSET $2 LIMIT $3");
    let fedi_rows = db.query(&fedi_sql,
        &[&owner_id, &offset, &limit, &folder_id],
    ).await.context("query fediverse bookmarks")?
      .into_iter().map(|r| bookmark_row_to_json(&r)).collect::<Result<Vec<_>>>()?;
    let count_sql = format!("SELECT COUNT(*) FROM masto_bookmarks b WHERE b.owner_user_id = $1::bigint AND b.status_id NOT LIKE 'bsky:%' AND b.object_id NOT LIKE 'at://%' AND b.object_id NOT LIKE '%bsky.app/%'
         AND ($2::bigint IS NULL OR EXISTS (SELECT 1 FROM vaak_bookmark_folder_items fi WHERE fi.folder_id = $2::bigint AND fi.owner_user_id = $1::bigint AND {folder_match}))");
    let total_fedi: i64 = db.query_one(&count_sql,
        &[&owner_id, &folder_id],
    ).await.context("count fediverse bookmarks")?.get(0);
    let bsky_rows = optional_cache_rows(&db,
        "SELECT c.post_json, c.bookmarked_at, c.bookmark_uri FROM bsky_bookmark_cache c WHERE c.owner_user_id = $1::bigint
         AND ($4::bigint IS NULL OR EXISTS (
           SELECT 1 FROM vaak_bookmark_folder_items fi
           LEFT JOIN masto_bookmarks mb ON mb.owner_user_id = c.owner_user_id AND mb.status_id = fi.status_id
           WHERE fi.folder_id = $4::bigint AND fi.owner_user_id = $1::bigint
             AND (fi.status_id = c.bookmark_uri OR mb.object_id = c.bookmark_uri)
         ))
         ORDER BY c.bookmarked_at DESC, c.bookmark_uri DESC OFFSET $2 LIMIT $3",
        &[&owner_id, &offset, &limit, &folder_id],
    ).await?;
    let bsky_rows = cached_bookmark_posts(bsky_rows,folder_id.unwrap_or(0),offset,limit)?;
    let total_bsky: i64 = optional_cache_count(&db,"SELECT COUNT(*) FROM bsky_bookmark_cache c WHERE c.owner_user_id = $1::bigint AND ($2::bigint IS NULL OR EXISTS (SELECT 1 FROM vaak_bookmark_folder_items fi LEFT JOIN masto_bookmarks mb ON mb.owner_user_id = c.owner_user_id AND mb.status_id = fi.status_id WHERE fi.folder_id = $2::bigint AND fi.owner_user_id = $1::bigint AND (fi.status_id = c.bookmark_uri OR mb.object_id = c.bookmark_uri)))", &[&owner_id, &folder_id]).await?;
    Ok(BookmarksProjection { owner_id, fedi_rows, bsky_rows, total_fedi, total_bsky, offset, limit, has_more_fedi: offset + limit < total_fedi, has_more_bsky: offset + limit < total_bsky, source: "vaak-worker-shadow", note: "Read-only bookmarks projection; PHP remains the folder and bookmark mutation owner." })
}

fn r_to_json(row: &tokio_postgres::Row) -> Result<Value> {
    Ok(serde_json::json!({
        "id": row.try_get::<_, i64>(0)?, "owner_user_id": row.try_get::<_, i64>(1)?,
        "owner_actor_id": row.try_get::<_, Option<String>>(2)?, "status_id": row.try_get::<_, String>(3)?,
        "object_id": row.try_get::<_, String>(4)?, "target_actor": row.try_get::<_, Option<String>>(5)?,
        "like_activity_id": row.try_get::<_, Option<String>>(6)?, "created_at": row.try_get::<_, String>(7)?
    }))
}

fn bookmark_row_to_json(row: &tokio_postgres::Row) -> Result<Value> {
    Ok(serde_json::json!({
        "id": row.try_get::<_, i64>(0)?, "owner_user_id": row.try_get::<_, i64>(1)?,
        "owner_actor_id": row.try_get::<_, Option<String>>(2)?, "status_id": row.try_get::<_, String>(3)?,
        "object_id": row.try_get::<_, String>(4)?, "created_at": row.try_get::<_, String>(5)?
    }))
}

// Missing optional protocol caches are valid during migrations. Other errors
// must reach the canonical PHP fallback instead of falsely reporting no saves.
async fn optional_cache_rows(
    db: &tokio_postgres::Client,
    sql: &str,
    params: &[&(dyn tokio_postgres::types::ToSql + Sync)],
) -> Result<Vec<tokio_postgres::Row>> {
    match db.query(sql, params).await {
        Ok(rows) => Ok(rows),
        Err(error) if error.code() == Some(&tokio_postgres::error::SqlState::UNDEFINED_TABLE) => Ok(Vec::new()),
        Err(error) => Err(error).context("query optional Library protocol cache"),
    }
}

async fn optional_cache_count(
    db: &tokio_postgres::Client,
    sql: &str,
    params: &[&(dyn tokio_postgres::types::ToSql + Sync)],
) -> Result<i64> {
    Ok(optional_cache_rows(db, sql, params).await?.first()
        .map(|row| row.try_get::<_, i64>(0)).transpose()?.unwrap_or(0))
}

fn cached_posts(rows: Vec<tokio_postgres::Row>, timestamp: &str) -> Result<Vec<Value>> {
    let mut posts = Vec::new();
    for row in rows {
        let raw: String = row.try_get(0).context("decode Library protocol post")?;
        // Preserve the existing malformed JSON skip; count/cursor semantics
        // remain based on source rows, not emitted cards.
        let Ok(mut post) = serde_json::from_str::<Value>(&raw) else { continue };
        let saved_at: String = row.try_get(1).context("decode Library protocol saved timestamp")?;
        if let Some(object) = post.as_object_mut() {
            object.insert(timestamp.into(), Value::String(saved_at));
        }
        posts.push(post);
    }
    Ok(posts)
}

// Preserve raw sidecar ordinals before malformed JSON omissions. These fields
// describe producer selection; only the private renderer's current SQL grant
// can admit a card or any effect from them.
fn cached_bookmark_posts(rows:Vec<tokio_postgres::Row>,folder:i64,offset:i64,limit:i64)->Result<Vec<Value>>{
 let mut posts=Vec::new();
 for (ordinal,row) in rows.into_iter().enumerate(){
  let raw:String=row.try_get(0).context("decode bookmark protocol post")?;
  let Ok(mut post)=serde_json::from_str::<Value>(&raw) else{continue};
  if let Some(object)=post.as_object_mut(){
   object.insert("bookmarked_at".into(),Value::String(row.try_get(1)?));
   object.insert("_native_source_binding".into(),serde_json::json!({"producer_kind":"bookmarks_bsky_data","selected_kind":"bsky","reference":row.try_get::<_,String>(2)?,"selected_ordinal":ordinal,"folder_id":folder,"offset":offset,"limit":limit}));
  }
  posts.push(post);
 }
 Ok(posts)
}

/// Cached identity association only; callers bind folder and saved-row owners.
pub(crate) fn folder_fedi_match_sql() -> &'static str { r#"(fi.status_id = b.status_id OR fi.status_id = b.object_id
 OR (fi.status_id LIKE 'https://%' AND rtrim(fi.status_id, '/') = rtrim(b.object_id, '/'))
 OR EXISTS (
   SELECT 1 FROM events folder_event
   WHERE (folder_event.object_id = rtrim(b.object_id, '/') OR folder_event.object_id = rtrim(b.object_id, '/') || '/')
     AND ((folder_event.id >= 0 AND folder_event.id < 1000000 AND fi.status_id = (3000000 + folder_event.id)::text)
       OR (CASE WHEN fi.status_id ~ '^[0-9]{18,19}$' THEN fi.status_id::numeric ELSE NULL END) >= 1000000000000000000
         AND mod(floor(((CASE WHEN fi.status_id ~ '^[0-9]{18,19}$' THEN fi.status_id::numeric ELSE NULL END) % 1000000000) / 100000000), 10) = 1
         AND mod((CASE WHEN fi.status_id ~ '^[0-9]{18,19}$' THEN fi.status_id::numeric ELSE NULL END), 100000000) = folder_event.id)
 )
 OR EXISTS (
   SELECT 1 FROM masto_statuses folder_local
   WHERE (folder_local.note_id = rtrim(b.object_id, '/') OR folder_local.note_id = rtrim(b.object_id, '/') || '/')
     AND folder_local.local_id > 0 AND folder_local.local_id < 2000000
     AND fi.status_id = folder_local.local_id::text
 ))"# }
