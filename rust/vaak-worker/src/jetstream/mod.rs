//! Read-only Jetstream spool scanner + cursor/wanted-dids + thin-media DB check.

use anyhow::{Context, Result};
use serde::Serialize;
use serde_json::Value;
use std::collections::HashMap;
use std::path::{Path, PathBuf};
use tokio::fs;
use tokio::io::{AsyncBufReadExt, BufReader};

use crate::config::Config;
use crate::redis_util;

#[derive(Debug, Default, Serialize)]
pub struct SpoolReport {
    pub state_dir: String,
    pub files_scanned: usize,
    pub lines_ok: usize,
    pub lines_bad: usize,
    pub by_collection: HashMap<String, usize>,
    pub thin_media_candidates: Vec<String>,
    pub thin_media_db: Vec<String>,
    pub sample_dids: Vec<String>,
    pub cursors: HashMap<String, String>,
    pub wanted_dids_count: usize,
    pub incoming_count: usize,
    pub processing_count: usize,
    pub source: &'static str,
    pub note: &'static str,
}

pub async fn report_once(cfg: &Config, max_files: usize, max_thin: usize) -> Result<SpoolReport> {
    let incoming = cfg.jetstream_state.join("incoming");
    let processing = cfg.jetstream_state.join("processing");
    let mut files = list_jsonl(&incoming).await?;
    let incoming_count = files.len();
    let mut processing_files = list_jsonl(&processing).await?;
    let processing_count = processing_files.len();
    files.append(&mut processing_files);
    files.sort();
    if files.len() > max_files {
        files.truncate(max_files);
    }

    let mut report = SpoolReport {
        state_dir: cfg.jetstream_state.display().to_string(),
        incoming_count,
        processing_count,
        source: "vaak-worker-shadow",
        note: "Read-only scan. Python spooler + PHP ingest remain live owners.",
        ..SpoolReport::default()
    };

    report.cursors = read_cursors(&cfg.jetstream_state).await?;
    report.wanted_dids_count =
        count_wanted_dids(&cfg.jetstream_state.join("wanted-dids.json")).await?;

    let mut did_set = std::collections::BTreeSet::new();

    for path in files {
        report.files_scanned += 1;
        let file = fs::File::open(&path)
            .await
            .with_context(|| format!("open {}", path.display()))?;
        let mut lines = BufReader::new(file).lines();
        while let Some(line) = lines.next_line().await? {
            let line = line.trim();
            if line.is_empty() {
                continue;
            }
            let Ok(v) = serde_json::from_str::<Value>(line) else {
                report.lines_bad += 1;
                continue;
            };
            report.lines_ok += 1;

            let collection = v
                .pointer("/commit/collection")
                .and_then(|x| x.as_str())
                .or_else(|| v.get("collection").and_then(|x| x.as_str()))
                .unwrap_or("unknown")
                .to_string();
            *report.by_collection.entry(collection.clone()).or_insert(0) += 1;

            if let Some(did) = v
                .pointer("/did")
                .and_then(|x| x.as_str())
                .or_else(|| v.pointer("/commit/repo").and_then(|x| x.as_str()))
            {
                if did.starts_with("did:") && did_set.len() < 12 {
                    did_set.insert(did.to_string());
                }
            }

            if report.thin_media_candidates.len() < max_thin {
                if let Some(uri) = thin_media_uri(&v) {
                    report.thin_media_candidates.push(uri);
                }
            }
        }
    }

    report.sample_dids = did_set.into_iter().collect();

    if let Ok(db) = crate::db::connect(&cfg.database_url).await {
        match find_thin_media_db(&db, max_thin).await {
            Ok(uris) => report.thin_media_db = uris,
            Err(e) => tracing::warn!(error = %e, "thin media DB scan skipped"),
        }
    }

    if let Ok(mut redis) = redis_util::connect(&cfg.redis_url).await {
        let key = "vaak:shadow:jetstream:last_scan";
        let payload = serde_json::to_value(&report)?;
        let _ = redis_util::json_set(&mut redis, key, &payload, 600).await;
    }

    Ok(report)
}

pub async fn scan_once(cfg: &Config, max_files: usize, max_thin: usize) -> Result<()> {
    let report = report_once(cfg, max_files, max_thin).await?;
    println!("{}", serde_json::to_string_pretty(&report)?);
    Ok(())
}

async fn read_cursors(state_dir: &Path) -> Result<HashMap<String, String>> {
    let mut out = HashMap::new();
    if !state_dir.is_dir() {
        return Ok(out);
    }
    let mut rd = fs::read_dir(state_dir).await?;
    while let Some(entry) = rd.next_entry().await? {
        let name = entry.file_name();
        let name = name.to_string_lossy();
        if !name.starts_with("cursor") {
            continue;
        }
        let path = entry.path();
        if !path.is_file() {
            continue;
        }
        let raw = fs::read_to_string(&path).await.unwrap_or_default();
        out.insert(name.to_string(), raw.trim().to_string());
    }
    Ok(out)
}

async fn count_wanted_dids(path: &Path) -> Result<usize> {
    if !path.is_file() {
        return Ok(0);
    }
    let raw = fs::read_to_string(path).await?;
    let v: Value = serde_json::from_str(&raw).unwrap_or(Value::Null);
    Ok(match v {
        Value::Array(a) => a.len(),
        Value::Object(o) => o
            .get("dids")
            .and_then(|x| x.as_array())
            .map(|a| a.len())
            .unwrap_or(o.len()),
        _ => 0,
    })
}

async fn find_thin_media_db(db: &tokio_postgres::Client, limit: usize) -> Result<Vec<String>> {
    let limit = limit.clamp(1, 50) as i64;
    // embed_json media-ish, raw_json missing fullsize/playlist (AppView view URLs).
    let rows = db
        .query(
            "SELECT bsky_uri FROM bsky_posts
             WHERE embed_json IS NOT NULL
               AND (
                 embed_json ILIKE '%images%'
                 OR embed_json ILIKE '%video%'
                 OR embed_json ILIKE '%recordWithMedia%'
               )
               AND (
                 raw_json IS NULL
                 OR (
                   raw_json NOT ILIKE '%fullsize%'
                   AND raw_json NOT ILIKE '%playlist%'
                 )
               )
             ORDER BY indexed_at DESC NULLS LAST
             LIMIT $1",
            &[&limit],
        )
        .await
        .context("thin media db")?;
    let mut out = Vec::new();
    for row in rows {
        let uri: String = row.get(0);
        out.push(uri);
    }
    Ok(out)
}

async fn list_jsonl(dir: &Path) -> Result<Vec<PathBuf>> {
    let mut out = Vec::new();
    if !dir.is_dir() {
        return Ok(out);
    }
    let mut rd = fs::read_dir(dir).await?;
    while let Some(entry) = rd.next_entry().await? {
        let path = entry.path();
        if path.extension().and_then(|s| s.to_str()) == Some("jsonl") {
            out.push(path);
        }
    }
    Ok(out)
}

/// Heuristic: post commit with embed that looks media-ish but lacks view URLs.
fn thin_media_uri(event: &Value) -> Option<String> {
    let collection = event.pointer("/commit/collection")?.as_str()?;
    if collection != "app.bsky.feed.post" {
        return None;
    }
    let record = event.pointer("/commit/record")?;
    let embed = record.get("embed")?;
    let embed_type = embed.get("$type").and_then(|v| v.as_str()).unwrap_or("");
    let looks_media = embed_type.contains("images")
        || embed_type.contains("video")
        || embed_type.contains("recordWithMedia");
    if !looks_media {
        return None;
    }
    let did = event.get("did")?.as_str()?;
    let rkey = event.pointer("/commit/rkey")?.as_str()?;
    Some(format!("at://{did}/app.bsky.feed.post/{rkey}"))
}
