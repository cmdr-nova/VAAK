//! Read-only Jetstream spool scanner + thin-embed repair candidate finder.

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
    pub sample_dids: Vec<String>,
    pub source: &'static str,
    pub note: &'static str,
}

pub async fn scan_once(cfg: &Config, max_files: usize, max_thin: usize) -> Result<()> {
    let incoming = cfg.jetstream_state.join("incoming");
    let processing = cfg.jetstream_state.join("processing");
    let mut files = list_jsonl(&incoming).await?;
    files.extend(list_jsonl(&processing).await?);
    files.sort();
    if files.len() > max_files {
        files.truncate(max_files);
    }

    let mut report = SpoolReport {
        state_dir: cfg.jetstream_state.display().to_string(),
        source: "vaak-worker-shadow",
        note: "Read-only scan. Python spooler + PHP ingest remain live owners.",
        ..SpoolReport::default()
    };

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

    // Publish a short shadow summary for soak dashboards.
    if let Ok(mut redis) = redis_util::connect(&cfg.redis_url).await {
        let key = "vaak:shadow:jetstream:last_scan";
        let payload = serde_json::to_value(&report)?;
        let _ = redis_util::json_set(&mut redis, key, &payload, 600).await;
    }

    println!("{}", serde_json::to_string_pretty(&report)?);
    Ok(())
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
    // Jetstream commit records usually have blobs, not AppView view URLs.
    let did = event.get("did")?.as_str()?;
    let rkey = event.pointer("/commit/rkey")?.as_str()?;
    Some(format!("at://{did}/app.bsky.feed.post/{rkey}"))
}
