//! Small, explainable Home ranker model.
//!
//! This is deliberately shadow-only for now. It learns per-actor affinity
//! from the durable interaction signal stream and stores a compact model in
//! Redis. The production heuristic ranker remains authoritative until the
//! model has enough data and passes offline comparisons.

use std::collections::HashMap;

use anyhow::{Context, Result};
use chrono::Utc;
use serde::{Deserialize, Serialize};
use serde_json::Value;
use tokio_postgres::Client;

use crate::{config::Config, db, redis_util};

const MODEL_TTL_SECS: u64 = 7 * 86400;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ShadowModel {
    pub version: u32,
    pub owner_user_id: i64,
    pub trained_at: i64,
    pub sample_count: i64,
    pub positive_count: i64,
    pub negative_count: i64,
    pub actor_weights: HashMap<String, f64>,
    pub signal_weights: HashMap<String, f64>,
}

fn signal_value(kind: &str) -> f64 {
    match kind {
        // VAAK calls likes "favourites". Keep the legacy `like` spelling as
        // an alias so imported/older signal rows receive identical weight.
        "favourite" | "like" => 1.35,
        "boost" | "reblog" | "quote" => 0.9,
        "reply" => 0.85,
        "bookmark" => 0.8,
        "click" => 0.35,
        "dwell" => 0.25,
        "impression" => -0.05,
        _ => 0.1,
    }
}

fn canonical_signal_kind(kind: &str) -> &str {
    match kind {
        // Favourites are VAAK's canonical name for likes.
        "like" => "favourite",
        other => other,
    }
}

fn actor_from_metadata(meta: &Value) -> String {
    for key in ["target_actor", "author_did", "actor_id"] {
        if let Some(actor) = meta.get(key).and_then(Value::as_str) {
            let actor = actor.trim().trim_end_matches('/');
            if !actor.is_empty() {
                return actor.to_string();
            }
        }
    }
    if let Some(handle) = meta.get("author_handle").and_then(Value::as_str) {
        let handle = handle.trim().trim_start_matches('@');
        if !handle.is_empty() {
            return format!("https://bsky.app/profile/{handle}");
        }
    }
    String::new()
}

pub async fn train_owner(cfg: &Config, owner_user_id: i64) -> Result<ShadowModel> {
    let db = db::connect(&cfg.database_url).await?;
    let model = train_from_signals(&db, owner_user_id).await?;
    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let key = format!("vaak:ml:ranker:v1:{owner_user_id}");
    redis_util::json_set(&mut redis, &key, &serde_json::to_value(&model)?, MODEL_TTL_SECS).await?;
    Ok(model)
}

async fn train_from_signals(db: &Client, owner_user_id: i64) -> Result<ShadowModel> {
    let since = (Utc::now() - chrono::Duration::days(90)).to_rfc3339();
    let rows = db
        .query(
            "SELECT signal_type, metadata_json
             FROM ap_user_signals
             WHERE owner_user_id = $1 AND created_at >= $2
             ORDER BY id DESC LIMIT 10000",
            &[&owner_user_id, &since],
        )
        .await
        .context("load ranker training signals")?;
    let mut actor_weights = HashMap::<String, f64>::new();
    let mut signal_weights = HashMap::<String, f64>::new();
    let mut positive_count = 0i64;
    let mut negative_count = 0i64;
    for row in &rows {
        let kind = row
            .try_get::<_, Option<String>>(0)
            .ok()
            .flatten()
            .unwrap_or_default()
            .to_ascii_lowercase();
        let canonical_kind = canonical_signal_kind(&kind);
        let value = signal_value(canonical_kind);
        if value > 0.0 {
            positive_count += 1;
        } else {
            negative_count += 1;
        }
        *signal_weights
            .entry(canonical_kind.to_string())
            .or_insert(0.0) += value;
        let raw = row
            .try_get::<_, Option<String>>(1)
            .ok()
            .flatten()
            .unwrap_or_else(|| "{}".into());
        let meta: Value = serde_json::from_str(&raw).unwrap_or_else(|_| serde_json::json!({}));
        let actor = actor_from_metadata(&meta);
        if !actor.is_empty() {
            let entry = actor_weights.entry(actor).or_insert(0.0);
            *entry = (*entry + value).clamp(-8.0, 32.0);
        }
    }
    actor_weights.retain(|_, score| score.abs() >= 0.05);
    ShadowModel {
        version: 1,
        owner_user_id,
        trained_at: Utc::now().timestamp(),
        sample_count: rows.len() as i64,
        positive_count,
        negative_count,
        actor_weights,
        signal_weights,
    }
    .pipe(Ok)
}

pub async fn run(cfg: &Config, owner_user_id: i64) -> Result<()> {
    let model = train_owner(cfg, owner_user_id).await?;
    println!("{}", serde_json::to_string_pretty(&model)?);
    Ok(())
}

/// Compare the shadow model's actor affinity ordering with the current
/// heuristic signal ordering. This intentionally evaluates only actor
/// affinity; source quotas, freshness, tags, and moderation remain separate.
pub async fn compare(cfg: &Config, owner_user_id: i64, limit: usize) -> Result<()> {
    let db = db::connect(&cfg.database_url).await?;
    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let key = format!("vaak:ml:ranker:v1:{owner_user_id}");
    let Some(raw) = redis_util::json_get(&mut redis, &key).await? else {
        anyhow::bail!("shadow model is not trained for owner {owner_user_id}");
    };
    let model: ShadowModel = serde_json::from_value(raw).context("decode shadow model")?;
    let current = current_heuristic_actor_weights(&db, owner_user_id).await?;
    let mut actors: Vec<String> = model
        .actor_weights
        .keys()
        .chain(current.keys())
        .cloned()
        .collect();
    actors.sort();
    actors.dedup();
    let score_model = |a: &String| model.actor_weights.get(a).copied().unwrap_or(0.0);
    let score_current = |a: &String| current.get(a).copied().unwrap_or(0.0);
    let mut ml_rank = actors.clone();
    let mut heuristic_rank = actors;
    ml_rank.sort_by(|a, b| score_model(b).total_cmp(&score_model(a)));
    heuristic_rank.sort_by(|a, b| score_current(b).total_cmp(&score_current(a)));
    let k = limit.clamp(1, 100);
    let ml_top: Vec<_> = ml_rank.iter().take(k).cloned().collect();
    let heuristic_top: Vec<_> = heuristic_rank.iter().take(k).cloned().collect();
    let overlap = ml_top.iter().filter(|a| heuristic_top.contains(a)).count();
    let union = ml_top
        .iter()
        .chain(heuristic_top.iter())
        .collect::<std::collections::HashSet<_>>()
        .len();
    println!(
        "{}",
        serde_json::to_string_pretty(&serde_json::json!({
            "owner_user_id": owner_user_id,
            "model_version": model.version,
            "training_samples": model.sample_count,
            "candidate_actors": ml_rank.len(),
            "top_k": k,
            "top_k_overlap": overlap,
            "top_k_jaccard": if union == 0 { 0.0 } else { overlap as f64 / union as f64 },
            "ml_top": ml_top,
            "heuristic_top": heuristic_top,
            "note": "actor-affinity shadow comparison; no production ranking change"
        }))?
    );
    Ok(())
}

async fn current_heuristic_actor_weights(
    db: &Client,
    owner_user_id: i64,
) -> Result<HashMap<String, f64>> {
    let since = (Utc::now() - chrono::Duration::days(90)).to_rfc3339();
    let rows = db
        .query(
            "SELECT signal_type, weight, metadata_json, created_at
             FROM ap_user_signals
             WHERE owner_user_id = $1 AND created_at >= $2
             ORDER BY id DESC LIMIT 10000",
            &[&owner_user_id, &since],
        )
        .await
        .context("load heuristic comparison signals")?;
    let mut out = HashMap::new();
    for row in rows {
        let kind = row
            .try_get::<_, Option<String>>(0)
            .ok()
            .flatten()
            .unwrap_or_default()
            .to_ascii_lowercase();
        let base = row
            .try_get::<_, Option<f64>>(1)
            .ok()
            .flatten()
            .unwrap_or(1.0);
        let multiplier = match canonical_signal_kind(&kind) {
            "favourite" => 1.4,
            "boost" | "reblog" => 1.2,
            "reply" => 1.0,
            "bookmark" => 1.1,
            "click" => 0.30,
            "dwell" => 0.12,
            "impression" => -0.03,
            _ => 0.5,
        };
        let raw = row
            .try_get::<_, Option<String>>(2)
            .ok()
            .flatten()
            .unwrap_or_else(|| "{}".into());
        let actor = actor_from_metadata(&serde_json::from_str(&raw).unwrap_or_else(|_| serde_json::json!({})));
        if !actor.is_empty() {
            *out.entry(actor).or_insert(0.0) += base * multiplier;
        }
    }
    Ok(out)
}

trait Pipe: Sized {
    fn pipe<T>(self, f: impl FnOnce(Self) -> T) -> T {
        f(self)
    }
}
impl<T> Pipe for T {}
