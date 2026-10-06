//! Read-only projections for the authenticated user's Library/You pages.
//!
//! These queries intentionally do not mutate rows. PHP remains the renderer
//! and write owner until each page has passed a full parity review.

use anyhow::{Context, Result};
use serde::Serialize;
use serde_json::Value;
use tokio_postgres::Client;

#[derive(Debug, Serialize)]
pub struct YouProjection {
    pub kind: String,
    pub owner_id: i64,
    pub rows: Vec<Value>,
    pub secondary: Vec<Value>,
    pub source: &'static str,
    pub note: &'static str,
}

async fn rows(db: &Client, sql: &str, params: &[&(dyn tokio_postgres::types::ToSql + Sync)]) -> Result<Vec<Value>> {
    let row = db
        .query_one(
            &format!("SELECT COALESCE(json_agg(row_to_json(projected)), '[]'::json) FROM ({sql}) projected"),
            params,
        )
        .await
        .map_err(|e| anyhow::anyhow!("query You projection: {e}"))?;
    let value: Value = row.get(0);
    Ok(value.as_array().cloned().unwrap_or_default())
}

pub async fn project(cfg: &crate::config::Config, owner_id: i64, kind: &str, limit: i64) -> Result<YouProjection> {
    if owner_id < 1 {
        anyhow::bail!("owner_id must be positive");
    }
    let kind = kind.trim().to_ascii_lowercase();
    if !matches!(kind.as_str(), "blog" | "rss" | "queue" | "drafts") {
        anyhow::bail!("unsupported You projection kind");
    }
    let limit = limit.clamp(1, 200);
    let db = crate::db::connect(&cfg.database_url).await.context("connect You projection")?;
    let owner_i32 = i32::try_from(owner_id).context("owner_id exceeds PostgreSQL integer range")?;

    let (rows, secondary) = match kind.as_str() {
        "blog" => {
            let rows = rows(&db, "SELECT id, owner_user_id, actor_key, slug, title, category, tags_json, body_markdown, excerpt, status, note_id, canonical_url, published_at, created_at, updated_at FROM vaak_blog_posts WHERE owner_user_id = $1 ORDER BY CASE WHEN status = 'published' THEN 0 ELSE 1 END, COALESCE(published_at, updated_at) DESC, id DESC LIMIT $2", &[&owner_id, &limit]).await?;
            (rows, Vec::new())
        }
        "rss" => {
            let feeds = rows(&db, "SELECT f.id, f.owner_user_id, f.feed_url, f.site_url, f.title, f.favicon_url, f.last_fetched_at, f.last_error, f.enabled, f.created_at, (SELECT COUNT(*) FROM rss_items i WHERE i.feed_id = f.id) AS item_count FROM rss_feeds f WHERE f.owner_user_id = $1 ORDER BY f.created_at DESC, f.id DESC LIMIT $2", &[&owner_i32, &limit]).await?;
            let items = rows(&db, "SELECT i.id, i.feed_id, i.guid, i.url, i.title, i.summary_text, i.image_url, i.published_at, i.ingested_at, i.mirror_note_id FROM rss_items i JOIN rss_feeds f ON f.id = i.feed_id WHERE f.owner_user_id = $1 ORDER BY COALESCE(i.published_at, i.ingested_at) DESC, i.id DESC LIMIT $2", &[&owner_i32, &limit]).await?;
            (feeds, items)
        }
        "queue" => {
            let pending = rows(&db, "SELECT id, owner_user_id, created_at, updated_at, position, scheduled_at, state, content, spoiler_text, sensitive, in_reply_to, to_actor, quote_object, media_ids_json, attempts, claimed_at, last_error, published_note_id, published_at, visibility, owner_actor_id FROM ap_post_queue WHERE owner_user_id = $1 AND state IN ('pending', 'publishing', 'failed') ORDER BY CASE state WHEN 'failed' THEN 2 WHEN 'publishing' THEN 1 ELSE 0 END, position ASC, id ASC LIMIT $2", &[&owner_id, &limit]).await?;
            let published = rows(&db, "SELECT id, owner_user_id, created_at, updated_at, position, scheduled_at, state, content, spoiler_text, sensitive, in_reply_to, to_actor, quote_object, media_ids_json, attempts, claimed_at, last_error, published_note_id, published_at, visibility, owner_actor_id FROM ap_post_queue WHERE owner_user_id = $1 AND state = 'published' AND published_at IS NOT NULL ORDER BY published_at DESC, id DESC LIMIT $2", &[&owner_id, &limit]).await?;
            (pending, published)
        }
        "drafts" => {
            let drafts = rows(&db, "SELECT id, owner_user_id, created_at, updated_at, content, spoiler_text, sensitive, visibility, in_reply_to, to_actor, quote_object, media_ids_json FROM ap_drafts WHERE owner_user_id = $1 ORDER BY updated_at DESC, id DESC LIMIT $2", &[&owner_id, &limit]).await?;
            let blog_drafts = rows(&db, "SELECT id, owner_user_id, actor_key, slug, title, category, tags_json, body_markdown, excerpt, status, note_id, canonical_url, published_at, created_at, updated_at FROM vaak_blog_posts WHERE owner_user_id = $1 AND status = 'draft' ORDER BY updated_at DESC, id DESC LIMIT $2", &[&owner_id, &limit]).await?;
            (drafts, blog_drafts)
        }
        _ => unreachable!(),
    };

    Ok(YouProjection {
        kind,
        owner_id,
        rows,
        secondary,
        source: "vaak-worker-shadow",
        note: "Read-only You projection; PHP remains the renderer and mutation owner.",
    })
}
