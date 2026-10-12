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
    "downranking_enabled", "asks_enabled", "webmentions_enabled", "updated_at", "trending_enabled",
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
                    p.downranking_enabled::integer, p.asks_enabled::integer, p.webmentions_enabled::integer, p.updated_at,
                    COALESCE((to_jsonb(p)->>'trending_enabled')::integer, 1)
             FROM ap_users u
             JOIN actor_profile p ON p.actor_key = u.actor_key
             WHERE u.id::bigint = $1 AND u.disabled_at IS NULL",
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
        "trending_enabled": row.try_get::<_, i32>(27).unwrap_or(1) != 0,
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

#[cfg(test)]
mod trending_display_tests {
    #[tokio::test]
    #[ignore = "requires owned PostgreSQL and actual PHP Settings captures"]
    async fn genuine_trending_preference_projection_both_widths() {
        let input:serde_json::Value=serde_json::from_slice(&std::fs::read(std::env::var("TREND_SETTINGS_CAPTURE").unwrap()).unwrap()).unwrap();
        let url=std::env::var("TREND_SETTINGS_DB").unwrap();assert!(url.contains("/tmp/"));
        let db=crate::db::connect(&url).await.unwrap();let before=std::env::var("TREND_SETTINGS_EXPECT_BEFORE").ok().as_deref()==Some("1");let mut results=Vec::new();let mut errors=Vec::new();
        for width in ["INTEGER","BIGINT"] {
            let schema=format!("trending_projection_{}_{}",std::process::id(),width.to_ascii_lowercase());
            db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema};CREATE TABLE ap_users(id {width},actor_key TEXT,disabled_at TEXT)")).await.unwrap();
            let mut cfg=crate::config::Config{database_url:format!("{url} options=-csearch_path={schema}"),redis_url:"redis://127.0.0.1/0".into(),redis_queue_url:"redis://127.0.0.1/1".into(),jetstream_state:"/tmp/trending-projection-unused".into(),default_owner_id:1,bsky_public_api:"http://127.0.0.1/unused".into(),notif_cache_dir:"/tmp/trending-projection-unused".into(),phyrian_mutation_token:None,phyrian_bridge_url:"http://127.0.0.1/unused".into(),phyrian_bridge_secret:None};
            cfg.database_url=format!("{url} options=-csearch_path={schema}");
            for case in input["cases"].as_array().unwrap() {
                let label=case["case"].as_str().unwrap();let columns=input["schema"].as_array().unwrap().iter().filter(|c|label!="absent"||c["name"]!="trending_enabled").map(|c|{let name=c["name"].as_str().unwrap();let kind=c["type"].as_str().unwrap();assert!(name.bytes().all(|b|b.is_ascii_alphanumeric()||b==b'_'));assert!(matches!(kind,"INTEGER"|"TEXT"),"actual schema type {kind}");format!("{name} {kind}")}).collect::<Vec<_>>().join(",");
                db.batch_execute(&format!("DROP TABLE IF EXISTS actor_profile;CREATE TABLE actor_profile({columns});DELETE FROM ap_users;INSERT INTO ap_users VALUES(1,'cmdr_nova',NULL),(2,'fixturepeer',NULL),(3,'disabledowner','disabled')")).await.unwrap();
                db.execute("INSERT INTO actor_profile SELECT * FROM jsonb_populate_record(NULL::actor_profile,$1)",&[&case["row"]]).await.unwrap();
                let mut peer=case["row"].clone();peer["actor_key"]=serde_json::json!("fixturepeer");if label!="absent"{peer["trending_enabled"]=serde_json::json!(0);}
                db.execute("INSERT INTO actor_profile SELECT * FROM jsonb_populate_record(NULL::actor_profile,$1)",&[&peer]).await.unwrap();
                let actual=super::project(&cfg,1).await;
                if before&&width=="INTEGER" {
                    let err=actual.as_ref().err().map(|e|format!("{e:#}")).unwrap_or_default();let ok=actual.is_err();if !ok{errors.push(format!("original INTEGER unexpectedly succeeded {label}"));}results.push(serde_json::json!({"width":width,"case":label,"expected_original_failure":ok,"actual_error":err}));continue;
                }
                match actual {Ok(v)=>{let ok=v.fields["trending_enabled"]==case["expected"]&&v.owner_id==1&&v.actor_key=="cmdr_nova";if !ok{errors.push(format!("PHP differential {width} {label}"));}results.push(serde_json::json!({"width":width,"case":label,"actual":v.fields["trending_enabled"],"expectedPHP":case["expected"],"matched":ok}));},Err(e)=>errors.push(format!("projection {width} {label}: {e:#}"))}
                let peer=super::project(&cfg,2).await;let expected=label=="absent";let ok=peer.as_ref().map(|v|v.owner_id==2&&v.actor_key=="fixturepeer"&&v.fields["trending_enabled"]==serde_json::json!(expected)).unwrap_or(false);if !ok{errors.push(format!("other owner {width} {label}"));}results.push(serde_json::json!({"width":width,"case":label,"other_owner_isolated":ok}));
                for id in [0,3,99] {let ok=super::project(&cfg,id).await.is_err();if !ok{errors.push(format!("invalid owner {width} {label} {id}"));}results.push(serde_json::json!({"width":width,"case":label,"denied_owner":id,"passed":ok}));}
            }
            db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }
        std::fs::write(std::env::var("TREND_SETTINGS_OUTPUT").unwrap(),serde_json::to_vec_pretty(&serde_json::json!({"results":results,"errors":errors,"before_mode":before})).unwrap()).unwrap();assert!(errors.is_empty(),"{errors:?}");
    }
}
