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
    pub mutation_owner: &'static str,
    pub mutation_enabled: bool,
}

/// Canonical persisted profile/settings fields. Keep this list aligned with
/// PHP's `ap_profile_save()` contract before a Rust settings editor is ever
/// considered. Credentials, sessions, integrations, and local browser theme
/// state are intentionally excluded.
pub const CANONICAL_FIELDS: &[&str] = &[
    "name", "summary", "attachment_json", "icon_url", "image_url",
    "manually_approves", "discoverable", "indexable", "collection_consent",
    "vanity_verified", "auto_follow_back", "anti_ai_marker",
    "auto_unblur_sensitive", "auto_delete_posts_7d", "automated",
    "reply_policy", "quote_policy", "forum_signature", "profile_badges",
    "hide_profile_replies", "hide_profile_boosts", "algorithm_enabled",
    "downranking_enabled", "asks_enabled", "webmentions_enabled", "updated_at",
];

pub async fn project(cfg: &crate::config::Config, owner_id: i64) -> Result<SettingsProjection> {
    if owner_id < 1 {
        anyhow::bail!("owner_id must be positive");
    }
    let db = crate::db::connect(&cfg.database_url).await.context("connect settings projection")?;
    let row = db
        .query_opt(
            "SELECT u.actor_key, p.name, p.summary, p.attachment_json, p.icon_url, p.image_url,
                    p.manually_approves::integer, p.discoverable::integer, p.indexable::integer, p.collection_consent::integer,
                    p.vanity_verified::integer, p.auto_follow_back::integer, p.anti_ai_marker::integer,
                    p.auto_unblur_sensitive::integer, p.auto_delete_posts_7d::integer, p.automated::integer,
                    p.reply_policy, p.quote_policy, p.forum_signature, p.profile_badges,
                    p.hide_profile_replies::integer, p.hide_profile_boosts::integer, p.algorithm_enabled::integer,
                    p.downranking_enabled::integer, p.asks_enabled::integer, p.webmentions_enabled::integer, p.updated_at
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
        "manually_approves": row.try_get::<_, i32>(6).unwrap_or(0) != 0,
        "discoverable": row.try_get::<_, i32>(7).unwrap_or(1) != 0,
        "indexable": row.try_get::<_, i32>(8).unwrap_or(1) != 0,
        "collection_consent": row.try_get::<_, i32>(9).unwrap_or(1) != 0,
        "vanity_verified": row.try_get::<_, i32>(10).unwrap_or(0) != 0,
        "auto_follow_back": row.try_get::<_, i32>(11).unwrap_or(0) != 0,
        "anti_ai_marker": row.try_get::<_, i32>(12).unwrap_or(0) != 0,
        "auto_unblur_sensitive": row.try_get::<_, i32>(13).unwrap_or(0) != 0,
        "auto_delete_posts_7d": row.try_get::<_, i32>(14).unwrap_or(0) != 0,
        "automated": row.try_get::<_, i32>(15).unwrap_or(0) != 0,
        "reply_policy": row.try_get::<_, String>(16).unwrap_or_else(|_| "anyone".into()),
        "quote_policy": row.try_get::<_, String>(17).unwrap_or_else(|_| "anyone".into()),
        "forum_signature": row.try_get::<_, String>(18).unwrap_or_default(),
        "profile_badges": row.try_get::<_, String>(19).unwrap_or_else(|_| "[]".into()),
        "hide_profile_replies": row.try_get::<_, i32>(20).unwrap_or(0) != 0,
        "hide_profile_boosts": row.try_get::<_, i32>(21).unwrap_or(0) != 0,
        "algorithm_enabled": row.try_get::<_, i32>(22).unwrap_or(1) != 0,
        "downranking_enabled": row.try_get::<_, i32>(23).unwrap_or(1) != 0,
        "asks_enabled": row.try_get::<_, i32>(24).unwrap_or(1) != 0,
        "webmentions_enabled": row.try_get::<_, i32>(25).unwrap_or(1) != 0,
        "updated_at": row.try_get::<_, String>(26).unwrap_or_default(),
    });
    Ok(SettingsProjection {
        owner_id,
        actor_key,
        fields,
        source: "vaak-worker-shadow",
        note: "Read-only settings projection; PHP remains renderer, CSRF, and mutation owner.",
        mutation_owner: "php",
        mutation_enabled: false,
    })
}

#[cfg(test)]
mod tests {
    use super::CANONICAL_FIELDS;
    use serde_json::Value;

    #[test]
    fn settings_contract_matches_fixture_and_excludes_sensitive_fields() {
        let fixture: Value = serde_json::from_str(include_str!("../fixtures/settings/profile-fields.json")).unwrap();
        let fields = fixture["fields"].as_array().unwrap();
        let names: Vec<&str> = fields.iter().map(|v| v.as_str().unwrap()).collect();
        assert_eq!(names, CANONICAL_FIELDS);
        for forbidden in ["email", "password", "password_hash", "api_key", "session_token", "totp_secret"] {
            assert!(!names.contains(&forbidden));
        }
    }
}
