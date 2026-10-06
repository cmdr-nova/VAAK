//! Read-only Settings/Profile preference projection.
//!
//! Sensitive credentials and all writes stay outside this projection. PHP
//! remains the canonical renderer and owns profile saves, CSRF, password/2FA,
//! imports, integrations, and account-isolation checks.

use anyhow::{Context, Result};
use serde::Serialize;
use serde_json::Value;

#[derive(Debug, Serialize)]
pub struct SettingsProjection {
    pub owner_id: i64,
    pub actor_key: String,
    pub fields: Value,
    pub source: &'static str,
    pub note: &'static str,
}

pub async fn project(cfg: &crate::config::Config, owner_id: i64) -> Result<SettingsProjection> {
    if owner_id < 1 {
        anyhow::bail!("owner_id must be positive");
    }
    let db = crate::db::connect(&cfg.database_url).await.context("connect settings projection")?;
    let row = db
        .query_opt(
            "SELECT u.actor_key, p.name, p.summary, p.attachment_json, p.icon_url, p.image_url,
                    p.manually_approves, p.discoverable, p.indexable, p.collection_consent,
                    p.vanity_verified, p.auto_follow_back, p.anti_ai_marker,
                    p.auto_unblur_sensitive, p.auto_delete_posts_7d, p.automated,
                    p.reply_policy, p.quote_policy, p.forum_signature, p.profile_badges,
                    p.hide_profile_replies, p.hide_profile_boosts, p.algorithm_enabled,
                    p.downranking_enabled, p.asks_enabled, p.webmentions_enabled, p.updated_at
             FROM ap_users u
             JOIN actor_profile p ON p.actor_key = u.actor_key
             WHERE u.id = $1 AND u.disabled_at IS NULL",
            &[&owner_id],
        )
        .await
        .context("load settings projection")?;
    let Some(row) = row else { anyhow::bail!("settings owner not found") };
    let actor_key: String = row.try_get(0).context("settings actor key")?;
    // Deliberately expose only profile/preferences fields. No email, password,
    // API keys, session tokens, 2FA material, or integration secrets leave DB.
    let fields = serde_json::json!({
        "name": row.try_get::<_, String>(1).unwrap_or_default(),
        "summary": row.try_get::<_, String>(2).unwrap_or_default(),
        "attachment_json": row.try_get::<_, String>(3).unwrap_or_else(|_| "[]".into()),
        "icon_url": row.try_get::<_, Option<String>>(4).unwrap_or(None),
        "image_url": row.try_get::<_, Option<String>>(5).unwrap_or(None),
        "manually_approves": row.try_get::<_, i64>(6).unwrap_or(0) != 0,
        "discoverable": row.try_get::<_, i64>(7).unwrap_or(1) != 0,
        "indexable": row.try_get::<_, i64>(8).unwrap_or(1) != 0,
        "collection_consent": row.try_get::<_, i64>(9).unwrap_or(1) != 0,
        "vanity_verified": row.try_get::<_, i64>(10).unwrap_or(0) != 0,
        "auto_follow_back": row.try_get::<_, i64>(11).unwrap_or(0) != 0,
        "anti_ai_marker": row.try_get::<_, i64>(12).unwrap_or(0) != 0,
        "auto_unblur_sensitive": row.try_get::<_, i64>(13).unwrap_or(0) != 0,
        "auto_delete_posts_7d": row.try_get::<_, i64>(14).unwrap_or(0) != 0,
        "automated": row.try_get::<_, i64>(15).unwrap_or(0) != 0,
        "reply_policy": row.try_get::<_, String>(16).unwrap_or_else(|_| "anyone".into()),
        "quote_policy": row.try_get::<_, String>(17).unwrap_or_else(|_| "anyone".into()),
        "forum_signature": row.try_get::<_, String>(18).unwrap_or_default(),
        "profile_badges": row.try_get::<_, String>(19).unwrap_or_else(|_| "[]".into()),
        "hide_profile_replies": row.try_get::<_, i64>(20).unwrap_or(0) != 0,
        "hide_profile_boosts": row.try_get::<_, i64>(21).unwrap_or(0) != 0,
        "algorithm_enabled": row.try_get::<_, i64>(22).unwrap_or(1) != 0,
        "downranking_enabled": row.try_get::<_, i64>(23).unwrap_or(1) != 0,
        "asks_enabled": row.try_get::<_, i64>(24).unwrap_or(1) != 0,
        "webmentions_enabled": row.try_get::<_, i64>(25).unwrap_or(1) != 0,
        "updated_at": row.try_get::<_, String>(26).unwrap_or_default(),
    });
    Ok(SettingsProjection {
        owner_id,
        actor_key,
        fields,
        source: "vaak-worker-shadow",
        note: "Read-only settings projection; PHP remains renderer, CSRF, and mutation owner.",
    })
}
