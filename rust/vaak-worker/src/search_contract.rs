//! Read-only Search migration contract.
//!
//! PHP remains the canonical search implementation, including FTS queries,
//! remote URL resolution, account lookup, privacy filtering, and redirects.
//! Rust exposes only this content-free contract until result parity is proven.

use anyhow::{Context, Result};
use serde::Serialize;

#[derive(Debug, Serialize)]
pub struct SearchContract {
    pub owner_id: i64,
    pub actor_key: String,
    pub query_types: Vec<&'static str>,
    pub privacy_filters: Vec<&'static str>,
    pub preserves_url_state: bool,
    pub remote_resolution_owner: &'static str,
    pub mutation_enabled: bool,
    pub fallback: &'static str,
    pub source: &'static str,
    pub note: &'static str,
}

pub async fn project(cfg: &crate::config::Config, owner_id: i64) -> Result<SearchContract> {
    if owner_id < 1 {
        anyhow::bail!("owner_id must be positive");
    }
    let db = crate::db::connect(&cfg.database_url)
        .await
        .context("connect search contract")?;
    let row = db
        .query_opt(
            "SELECT actor_key FROM ap_users WHERE id = $1 AND disabled_at IS NULL",
            &[&owner_id],
        )
        .await
        .context("load search owner")?;
    let Some(row) = row else {
        anyhow::bail!("search owner not found");
    };
    let actor_key: String = row.try_get(0).context("search actor key")?;
    Ok(SearchContract {
        owner_id,
        actor_key,
        query_types: vec!["text", "hashtags", "accounts", "remote_url"],
        privacy_filters: vec!["indexable", "personal_blocks", "personal_mutes", "server_blocks", "server_mutes"],
        preserves_url_state: true,
        remote_resolution_owner: "php",
        mutation_enabled: false,
        fallback: "php",
        source: "vaak-worker-shadow",
        note: "Content-free Search contract; PHP remains query, privacy, remote-resolution, redirect, and mutation owner.",
    })
}
