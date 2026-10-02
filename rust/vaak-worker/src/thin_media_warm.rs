//! Live thin-media warm queue (Rust primary; PHP falls back to nohup).
//!
//! Queue name matches PHP `ap_redis_queue_push('bsky_post_warm', …)` →
//! Redis DB1 key `vaak:queue:bsky_post_warm`.

use anyhow::{Context, Result};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use sha2::{Digest, Sha256};

use crate::config::Config;
use crate::redis_util;

pub const QUEUE_NAME: &str = "bsky_post_warm";

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct WarmJob {
    pub uri: String,
    pub owner: i64,
    #[serde(default)]
    pub ts: i64,
    #[serde(default)]
    pub source: String,
}

#[derive(Debug, Serialize)]
pub struct WarmOnceReport {
    pub enqueued: usize,
    pub processed: usize,
    pub repaired: usize,
    pub failed: usize,
    pub queue_depth: i64,
    pub sample: Vec<String>,
    pub source: &'static str,
}

/// Enqueue one at:// URI onto the shared warm queue (deduped via Redis lock).
pub async fn enqueue(cfg: &Config, uri: &str, owner: i64, source: &str) -> Result<bool> {
    let uri = uri.trim();
    if !uri.starts_with("at://") {
        return Ok(false);
    }
    let mut q = redis_util::connect(&cfg.redis_queue_url).await?;
    let lock_name = format!("bsky-post-warm:{}", sha256_hex(uri));
    let holder = format!("vaak-worker:{}", std::process::id());
    if !redis_util::lock(&mut q, &lock_name, 45, &holder).await? {
        return Ok(false);
    }
    let job = WarmJob {
        uri: uri.to_string(),
        owner: owner.max(0),
        ts: chrono::Utc::now().timestamp(),
        source: source.to_string(),
    };
    let item = serde_json::to_string(&job)?;
    redis_util::queue_push(&mut q, QUEUE_NAME, &item).await?;
    Ok(true)
}

/// Scan thin posts and enqueue them (live cutover path).
pub async fn enqueue_thin_scan(cfg: &Config, owner: i64, limit: usize) -> Result<WarmOnceReport> {
    let db = crate::db::connect(&cfg.database_url).await?;
    let limit = limit.clamp(1, 40) as i64;
    let rows = db
        .query(
            "SELECT bsky_uri FROM bsky_posts
             WHERE embed_json IS NOT NULL
               AND (
                 embed_json ILIKE '%images%'
                 OR embed_json ILIKE '%video%'
                 OR embed_json ILIKE '%recordWithMedia%'
                 OR embed_json ILIKE '%gallery%'
               )
               AND (
                 raw_json IS NULL
                 OR (
                   raw_json NOT ILIKE '%fullsize%'
                   AND raw_json NOT ILIKE '%playlist%'
                 )
               )
               AND embed_json NOT ILIKE '%fullsize%'
               AND embed_json NOT ILIKE '%playlist%'
             ORDER BY indexed_at DESC NULLS LAST
             LIMIT $1",
            &[&limit],
        )
        .await
        .context("select thin for enqueue")?;

    let mut report = WarmOnceReport {
        enqueued: 0,
        processed: 0,
        repaired: 0,
        failed: 0,
        queue_depth: 0,
        sample: Vec::new(),
        source: "vaak-worker-live",
    };
    for row in rows {
        let uri: String = row.get(0);
        match enqueue(cfg, &uri, owner, "thin-scan").await {
            Ok(true) => {
                report.enqueued += 1;
                if report.sample.len() < 12 {
                    report.sample.push(uri);
                }
            }
            Ok(false) => {}
            Err(e) => {
                report.failed += 1;
                tracing::warn!(error = %e, %uri, "enqueue failed");
            }
        }
    }
    if let Ok(mut q) = redis_util::connect(&cfg.redis_queue_url).await {
        report.queue_depth = redis_util::queue_llen(&mut q, QUEUE_NAME).await.unwrap_or(0);
    }
    Ok(report)
}

/// Process one job: AppView getPosts → upsert embed/raw into bsky_posts.
pub async fn process_job(cfg: &Config, job: &WarmJob) -> Result<bool> {
    let client = reqwest::Client::builder()
        .timeout(std::time::Duration::from_secs(10))
        .user_agent("vaak-worker-live/0.1 (thin-media-warm)")
        .build()?;
    let base = cfg.bsky_public_api.trim_end_matches('/');
    let url = format!("{base}/xrpc/app.bsky.feed.getPosts");
    let resp = client
        .get(&url)
        .query(&[("uris", job.uri.as_str())])
        .send()
        .await
        .context("getPosts")?;
    if !resp.status().is_success() {
        anyhow::bail!("HTTP {}", resp.status());
    }
    let v: Value = resp.json().await.context("decode getPosts")?;
    let post = v
        .pointer("/posts/0")
        .cloned()
        .ok_or_else(|| anyhow::anyhow!("empty posts"))?;
    upsert_from_appview(cfg, &job.uri, job.owner, &post).await
}

async fn upsert_from_appview(
    cfg: &Config,
    uri: &str,
    owner: i64,
    post: &Value,
) -> Result<bool> {
    let embed = post.get("embed").cloned().unwrap_or(Value::Null);
    let labels = post.get("labels").cloned().unwrap_or(Value::Null);
    let author = post.get("author").cloned().unwrap_or(Value::Null);
    let record = post.get("record").cloned().unwrap_or(Value::Null);
    let text = record
        .get("text")
        .and_then(|t| t.as_str())
        .unwrap_or("")
        .to_string();
    let cid = post.get("cid").and_then(|c| c.as_str()).unwrap_or("");
    let author_did = author
        .get("did")
        .and_then(|d| d.as_str())
        .unwrap_or("")
        .to_string();
    if author_did.is_empty() {
        anyhow::bail!("missing author did");
    }
    let author_handle = author
        .get("handle")
        .and_then(|h| h.as_str())
        .map(|s| s.to_string());
    let author_display = author
        .get("displayName")
        .and_then(|h| h.as_str())
        .map(|s| s.to_string());
    let author_avatar = author
        .get("avatar")
        .and_then(|h| h.as_str())
        .map(|s| s.to_string());
    let indexed_at = post
        .get("indexedAt")
        .and_then(|t| t.as_str())
        .or_else(|| record.get("createdAt").and_then(|t| t.as_str()))
        .unwrap_or("")
        .to_string();
    let published_at = record
        .get("createdAt")
        .and_then(|t| t.as_str())
        .unwrap_or(indexed_at.as_str())
        .to_string();

    let mut raw = serde_json::json!({
        "uri": uri,
        "cid": cid,
        "author": {
            "did": author_did,
            "handle": author_handle.clone().unwrap_or_default(),
            "displayName": author_display.clone().unwrap_or_default(),
            "avatar": author_avatar.clone().unwrap_or_default(),
        },
        "record": record,
        "embed": embed,
        "indexedAt": indexed_at,
        "likeCount": post.get("likeCount").and_then(|n| n.as_i64()).unwrap_or(0),
        "repostCount": post.get("repostCount").and_then(|n| n.as_i64()).unwrap_or(0),
        "replyCount": post.get("replyCount").and_then(|n| n.as_i64()).unwrap_or(0),
        "quoteCount": post.get("quoteCount").and_then(|n| n.as_i64()).unwrap_or(0),
        "labels": labels,
    });
    if let Some(viewer) = post.get("viewer") {
        raw["viewer"] = viewer.clone();
    }

    let embed_s = if embed.is_null() {
        None
    } else {
        Some(serde_json::to_string(&embed)?)
    };
    let raw_s = serde_json::to_string(&raw)?;
    let now = chrono::Utc::now().to_rfc3339();
    let owner_opt = if owner > 0 { Some(owner) } else { None };

    let db = crate::db::connect(&cfg.database_url).await?;
    let n = db
        .execute(
            "INSERT INTO bsky_posts (
                bsky_uri, bsky_cid, author_did, author_handle, author_display, author_avatar,
                indexed_at, published_at, text, embed_json, reply_parent, reply_root,
                like_count, repost_count, reply_count, quote_count, reason_json, raw_json,
                owner_user_id, seen_at, updated_at
             ) VALUES (
                $1,$2,$3,$4,$5,$6,$7,$8,$9,$10,NULL,NULL,
                $11,$12,$13,$14,NULL,$15,$16,$17,$17
             )
             ON CONFLICT (bsky_uri) DO UPDATE SET
               bsky_cid = COALESCE(EXCLUDED.bsky_cid, bsky_posts.bsky_cid),
               author_did = EXCLUDED.author_did,
               author_handle = COALESCE(EXCLUDED.author_handle, bsky_posts.author_handle),
               author_display = COALESCE(EXCLUDED.author_display, bsky_posts.author_display),
               author_avatar = COALESCE(EXCLUDED.author_avatar, bsky_posts.author_avatar),
               indexed_at = COALESCE(EXCLUDED.indexed_at, bsky_posts.indexed_at),
               published_at = COALESCE(EXCLUDED.published_at, bsky_posts.published_at),
               text = EXCLUDED.text,
               embed_json = COALESCE(EXCLUDED.embed_json, bsky_posts.embed_json),
               like_count = EXCLUDED.like_count,
               repost_count = EXCLUDED.repost_count,
               reply_count = EXCLUDED.reply_count,
               quote_count = EXCLUDED.quote_count,
               raw_json = COALESCE(EXCLUDED.raw_json, bsky_posts.raw_json),
               owner_user_id = COALESCE(EXCLUDED.owner_user_id, bsky_posts.owner_user_id),
               seen_at = EXCLUDED.seen_at,
               updated_at = EXCLUDED.updated_at",
            &[
                &uri,
                &Some(cid.to_string()),
                &author_did,
                &author_handle,
                &author_display,
                &author_avatar,
                &indexed_at,
                &published_at,
                &text,
                &embed_s,
                &(post.get("likeCount").and_then(|n| n.as_i64()).unwrap_or(0) as i32),
                &(post.get("repostCount").and_then(|n| n.as_i64()).unwrap_or(0) as i32),
                &(post.get("replyCount").and_then(|n| n.as_i64()).unwrap_or(0) as i32),
                &(post.get("quoteCount").and_then(|n| n.as_i64()).unwrap_or(0) as i32),
                &raw_s,
                &owner_opt,
                &now,
            ],
        )
        .await
        .context("upsert bsky_posts")?;

    let repaired = !embed.is_null()
        && (raw_s.to_ascii_lowercase().contains("fullsize")
            || raw_s.to_ascii_lowercase().contains("playlist")
            || embed_s
                .as_deref()
                .map(|s| {
                    let l = s.to_ascii_lowercase();
                    l.contains("fullsize") || l.contains("playlist")
                })
                .unwrap_or(false));
    tracing::info!(%uri, n, repaired, "thin-media warm upsert");
    Ok(repaired)
}

pub async fn run_worker_loop(cfg: &Config, scan_interval_secs: u64) -> Result<()> {
    let mut q = redis_util::connect(&cfg.redis_queue_url).await?;
    let mut last_scan = std::time::Instant::now()
        - std::time::Duration::from_secs(scan_interval_secs.max(60));
    loop {
        // Periodic thin scan → enqueue.
        if last_scan.elapsed() >= std::time::Duration::from_secs(scan_interval_secs.max(60)) {
            match enqueue_thin_scan(cfg, cfg.default_owner_id, 15).await {
                Ok(r) => tracing::info!(
                    enqueued = r.enqueued,
                    depth = r.queue_depth,
                    "thin-media scan enqueue"
                ),
                Err(e) => tracing::warn!(error = %e, "thin-media scan failed"),
            }
            last_scan = std::time::Instant::now();
        }

        match redis_util::queue_brpop(&mut q, QUEUE_NAME, 5.0).await {
            Ok(Some(item)) => {
                let job: WarmJob = match serde_json::from_str(&item) {
                    Ok(j) => j,
                    Err(_) => {
                        // Plain at:// URI from older/simple pushers.
                        if item.starts_with("at://") {
                            WarmJob {
                                uri: item,
                                owner: cfg.default_owner_id,
                                ts: chrono::Utc::now().timestamp(),
                                source: "plain".into(),
                            }
                        } else {
                            tracing::warn!(%item, "bad warm job");
                            continue;
                        }
                    }
                };
                match process_job(cfg, &job).await {
                    Ok(repaired) => tracing::info!(uri = %job.uri, repaired, "warm ok"),
                    Err(e) => tracing::warn!(uri = %job.uri, error = %e, "warm failed"),
                }
            }
            Ok(None) => {}
            Err(e) => {
                tracing::error!(error = %e, "warm BRPOP failed");
                tokio::time::sleep(std::time::Duration::from_secs(2)).await;
                q = redis_util::connect(&cfg.redis_queue_url).await?;
            }
        }
    }
}

pub async fn run_enqueue_cli(cfg: &Config, owner: i64, limit: usize) -> Result<()> {
    let report = enqueue_thin_scan(cfg, owner, limit).await?;
    println!("{}", serde_json::to_string_pretty(&report)?);
    Ok(())
}

fn sha256_hex(s: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(s.as_bytes());
    hex::encode(hasher.finalize())
}
