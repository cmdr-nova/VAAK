//! Thin-media repair dry-run (never writes bsky_posts / Redis warm queues).

use anyhow::{Context, Result};
use serde::Serialize;
use serde_json::Value;

use crate::config::Config;

#[derive(Debug, Serialize)]
pub struct ThinRepairReport {
    pub candidates: usize,
    pub would_repair: usize,
    pub already_ok: usize,
    pub fetch_failed: usize,
    pub skipped_no_fetch: usize,
    pub sample: Vec<ThinCandidate>,
    pub fetched: bool,
    pub source: &'static str,
    pub note: &'static str,
}

#[derive(Debug, Serialize)]
pub struct ThinCandidate {
    pub uri: String,
    pub reason: String,
    pub verdict: String,
    pub appview_has_fullsize: Option<bool>,
    pub appview_has_playlist: Option<bool>,
}

pub async fn dry_run(cfg: &Config, limit: usize, fetch: bool) -> Result<ThinRepairReport> {
    let db = crate::db::connect(&cfg.database_url).await?;
    let limit = limit.clamp(1, 40);
    // Prefer posts that look media-ish but lack AppView view URLs (fullsize/playlist).
    let rows = db
        .query(
            "SELECT bsky_uri, embed_json, raw_json, text
             FROM bsky_posts
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
            &[&(limit as i64)],
        )
        .await
        .context("select thin candidates")?;

    let client = if fetch {
        Some(
            reqwest::Client::builder()
                .timeout(std::time::Duration::from_secs(8))
                .user_agent("vaak-worker-shadow/0.1 (thin-media dry-run)")
                .build()
                .context("build reqwest")?,
        )
    } else {
        None
    };

    let mut report = ThinRepairReport {
        candidates: 0,
        would_repair: 0,
        already_ok: 0,
        fetch_failed: 0,
        skipped_no_fetch: 0,
        sample: Vec::new(),
        fetched: fetch,
        source: "vaak-worker-shadow",
        note: "Dry-run only. Does not upsert bsky_posts or enqueue warm jobs.",
    };

    for row in rows {
        let uri: String = row.get(0);
        let embed_json: Option<String> = row.try_get(1).ok().flatten();
        let raw_json: Option<String> = row.try_get(2).ok().flatten();

        let reason = classify_thin(embed_json.as_deref(), raw_json.as_deref());
        if reason == "ok" {
            report.already_ok += 1;
            continue;
        }
        report.candidates += 1;

        let mut cand = ThinCandidate {
            uri: uri.clone(),
            reason: reason.clone(),
            verdict: "candidate".into(),
            appview_has_fullsize: None,
            appview_has_playlist: None,
        };

        if let Some(ref http) = client {
            match fetch_appview_media(http, &cfg.bsky_public_api, &uri).await {
                Ok((fullsize, playlist)) => {
                    cand.appview_has_fullsize = Some(fullsize);
                    cand.appview_has_playlist = Some(playlist);
                    if fullsize || playlist {
                        cand.verdict = "would_repair".into();
                        report.would_repair += 1;
                    } else {
                        cand.verdict = "appview_still_thin".into();
                        report.fetch_failed += 1;
                    }
                }
                Err(e) => {
                    cand.verdict = format!("fetch_failed:{e}");
                    report.fetch_failed += 1;
                }
            }
        } else {
            cand.verdict = "would_enqueue_or_fetch".into();
            report.skipped_no_fetch += 1;
        }

        if report.sample.len() < 25 {
            report.sample.push(cand);
        }
    }

    // Shadow summary for soak dashboards.
    if let Ok(mut redis) = crate::redis_util::connect(&cfg.redis_url).await {
        let key = "vaak:shadow:thin_media:last_dry_run";
        let payload = serde_json::to_value(&report)?;
        let _ = crate::redis_util::json_set(&mut redis, key, &payload, 600).await;
    }

    Ok(report)
}

pub async fn run(cfg: &Config, limit: usize, fetch: bool) -> Result<()> {
    let report = dry_run(cfg, limit, fetch).await?;
    println!("{}", serde_json::to_string_pretty(&report)?);
    Ok(())
}

fn classify_thin(embed_json: Option<&str>, raw_json: Option<&str>) -> String {
    let embed = embed_json.unwrap_or("");
    let raw = raw_json.unwrap_or("");
    let looks_media = embed.to_ascii_lowercase().contains("images")
        || embed.to_ascii_lowercase().contains("video")
        || embed.to_ascii_lowercase().contains("recordwithmedia")
        || embed.to_ascii_lowercase().contains("gallery");
    if !looks_media {
        return "ok".into();
    }
    let has_view = raw.to_ascii_lowercase().contains("fullsize")
        || raw.to_ascii_lowercase().contains("playlist")
        || embed.to_ascii_lowercase().contains("fullsize")
        || embed.to_ascii_lowercase().contains("playlist");
    if has_view {
        "ok".into()
    } else {
        "missing_view_urls".into()
    }
}

async fn fetch_appview_media(
    client: &reqwest::Client,
    api_base: &str,
    uri: &str,
) -> Result<(bool, bool)> {
    let base = api_base.trim_end_matches('/');
    let url = format!("{base}/xrpc/app.bsky.feed.getPosts");
    let resp = client
        .get(&url)
        .query(&[("uris", uri)])
        .send()
        .await
        .context("appview getPosts")?;
    if !resp.status().is_success() {
        anyhow::bail!("HTTP {}", resp.status());
    }
    let v: Value = resp.json().await.context("decode getPosts")?;
    let post = v
        .pointer("/posts/0")
        .cloned()
        .unwrap_or(Value::Null);
    let s = post.to_string().to_ascii_lowercase();
    Ok((s.contains("fullsize"), s.contains("playlist")))
}
