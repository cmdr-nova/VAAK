//! Privacy contract for DMs, Asks, and moderation-sensitive private surfaces.
//!
//! This endpoint intentionally contains no message, Ask, report, or actor
//! content. It is a client/parity contract only; PHP remains the authenticated
//! renderer, federation handler, CSRF boundary, and mutation owner.

use anyhow::{Context, Result};
use serde::Serialize;

#[derive(Debug, Serialize)]
pub struct PrivateSurfaceProjection {
    pub owner_id: i64,
    pub actor_key: String,
    pub policies: Policies,
    pub source: &'static str,
    pub note: &'static str,
}

#[derive(Debug, Serialize)]
pub struct Policies {
    pub dm_delivery_blocked_peer: bool,
    pub dm_deleted_hidden: bool,
    pub ask_anonymous_allowed: bool,
    pub ask_expiry_hours: i64,
    pub moderation_owner: &'static str,
    pub federation_owner: &'static str,
    pub mutation_enabled: bool,
}

pub async fn project(
    cfg: &crate::config::Config,
    owner_id: i64,
) -> Result<PrivateSurfaceProjection> {
    if owner_id < 1 {
        anyhow::bail!("owner_id must be positive");
    }
    let db = crate::db::connect(&cfg.database_url)
        .await
        .context("connect private-surface projection")?;
    let row = db
        .query_opt(
            "SELECT actor_key FROM ap_users WHERE id = $1 AND disabled_at IS NULL",
            &[&owner_id],
        )
        .await
        .context("load private-surface owner")?;
    let Some(row) = row else {
        anyhow::bail!("private-surface owner not found");
    };
    let actor_key: String = row.try_get(0).context("private-surface actor key")?;

    Ok(PrivateSurfaceProjection {
        owner_id,
        actor_key,
        policies: Policies {
            dm_delivery_blocked_peer: true,
            dm_deleted_hidden: true,
            ask_anonymous_allowed: false,
            ask_expiry_hours: 48,
            moderation_owner: "php",
            federation_owner: "php",
            mutation_enabled: false,
        },
        source: "vaak-worker-shadow",
        note: "Content-free privacy contract; PHP remains DM/Ask/moderation renderer, federation, and mutation owner.",
    })
}
