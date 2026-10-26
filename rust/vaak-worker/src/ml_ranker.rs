//! Small, explainable Home ranker model.
//!
//! Shadow only. It learns account affinity and hashtag affinity from the
//! local interaction log and stores a compact model in Redis. The live Home
//! order is untouched. Each nightly run holds out the newest positive
//! interactions, scores the model against a replay of the live signal
//! formula, and writes a one-row scorecard.

use std::collections::{HashMap, HashSet};

use anyhow::{Context, Result};
use chrono::{Datelike, Utc};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use tokio_postgres::Client;

use crate::{config::Config, db, redis_util};

const MODEL_TTL_SECS: u64 = 7 * 86400;
const MODEL_VERSION: u32 = 2;
const HISTORY_DAYS: i64 = 180;
const SIGNAL_LOOKBACK_DAYS: i64 = 90;
const SIGNAL_ROW_LIMIT: i64 = 10_000;
/// `load_signal_actor_weights` in ranked_warm.rs, the live Home path.
const LIVE_SIGNAL_ROWS: usize = 2_000;
const LOOKUP_KEY_LIMIT: usize = 1_500;
const LOOKUP_CHUNK: usize = 80;

// Every shadow weight lives here. Likes are the strongest explicit signal,
// bookmarks next, and dwell stays below both even at its longest bucket.
const WEIGHT_FAVOURITE: f64 = 1.35;
const WEIGHT_BOOST: f64 = 0.90;
const WEIGHT_REPLY: f64 = 0.85;
const WEIGHT_BOOKMARK: f64 = 0.80;
const WEIGHT_CLICK: f64 = 0.35;
const WEIGHT_DWELL: f64 = 0.25;
const WEIGHT_IMPRESSION: f64 = -0.05;
const WEIGHT_OTHER: f64 = 0.10;
/// Share of a like's weight added to each hashtag on that post.
const HASHTAG_SHARE_FAVOURITE: f64 = 0.55;
/// Bookmarks are explicit saves, weaker tag evidence than a like.
const HASHTAG_SHARE_BOOKMARK: f64 = 0.35;
const HASHTAG_SHARE_DWELL: f64 = 0.0;
const MAX_TAGS_PER_POST: usize = 8;
const HOLDOUT_DAYS: i64 = 3;
const ACTOR_CLAMP: (f64, f64) = (-8.0, 32.0);
const TAG_CLAMP: (f64, f64) = (-4.0, 16.0);
const MIN_ABS_WEIGHT: f64 = 0.05;
const LIVE_ACTOR_CLAMP: (f64, f64) = (-3.0, 24.0);
const LIVE_HALF_LIFE_SECS: f64 = 14.0 * 86_400.0;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ShadowModel {
    pub version: u32,
    pub owner_user_id: i64,
    pub trained_at: i64,
    pub sample_count: i64,
    pub positive_count: i64,
    pub negative_count: i64,
    #[serde(default)]
    pub rows_seen: i64,
    #[serde(default)]
    pub holdout_count: i64,
    #[serde(default)]
    pub holdout_days: i64,
    pub actor_weights: HashMap<String, f64>,
    #[serde(default)]
    pub hashtag_weights: HashMap<String, f64>,
    pub signal_weights: HashMap<String, f64>,
}

#[derive(Debug, Clone)]
struct TrainSignal {
    kind: String,
    stored_weight: f64,
    created_at: i64,
    actor: String,
    hashtags: Vec<String>,
}

#[derive(Debug, Clone)]
struct Fit {
    actors: HashMap<String, f64>,
    tags: HashMap<String, f64>,
    kinds: HashMap<String, f64>,
    positive: i64,
    negative: i64,
    trained: i64,
    holdout: i64,
}

#[derive(Debug, Clone, Serialize)]
struct RankScore {
    hit_rate_at_10: f64,
    recall_at_10: f64,
    ndcg_at_10: f64,
    hit_rate_at_20: f64,
    recall_at_20: f64,
    ndcg_at_20: f64,
    mrr: f64,
}

#[derive(Debug, Clone, Serialize)]
struct Scorecard {
    /// Kept on the row. Left out of the journal JSON: CodeQL treats an
    /// account id in a log line as sensitive, and the column already has it.
    #[serde(skip_serializing)]
    owner_user_id: i64,
    run_on: String,
    model_version: u32,
    holdout_days: i64,
    rows_seen: i64,
    training_samples: i64,
    holdout_positive_rows: i64,
    candidate_actors: usize,
    candidate_hashtags: usize,
    top_k: usize,
    /// Full-history actor overlap. Same comparison the version-1 job logged.
    top_k_overlap: usize,
    top_k_jaccard: f64,
    actors: FamilyScore,
    hashtags: FamilyScore,
    weights: Value,
    note: String,
}

#[derive(Debug, Clone, Serialize)]
struct FamilyScore {
    relevant: usize,
    shadow: Option<RankScore>,
    live: Option<RankScore>,
    shadow_minus_live_ndcg_at_20: Option<f64>,
}

struct RankMetrics {
    hit_rate: f64,
    recall: f64,
    ndcg: f64,
    mrr: f64,
}

fn canonical_signal_kind(kind: &str) -> &str {
    match kind {
        "like" => "favourite",
        other => other,
    }
}

/// Version-1 actor value. Stored weight is ignored, which is how overlap@20
/// was computed before the scorecard.
fn legacy_signal_value(kind: &str) -> f64 {
    match canonical_signal_kind(kind) {
        "favourite" => WEIGHT_FAVOURITE,
        "boost" | "reblog" | "quote" => WEIGHT_BOOST,
        "reply" => WEIGHT_REPLY,
        "bookmark" => WEIGHT_BOOKMARK,
        "click" => WEIGHT_CLICK,
        "dwell" => WEIGHT_DWELL,
        "impression" => WEIGHT_IMPRESSION,
        _ => WEIGHT_OTHER,
    }
}

fn dwell_bucket_scale(stored_weight: f64) -> f64 {
    match stored_weight.round() as i64 {
        1 => 0.6,
        3 => 1.5,
        4 => 2.0,
        _ => 1.0,
    }
}

fn shadow_contribution(kind: &str, stored_weight: f64) -> f64 {
    let canonical = canonical_signal_kind(kind);
    let magnitude = match canonical {
        "favourite" => WEIGHT_FAVOURITE,
        "boost" | "reblog" | "quote" => WEIGHT_BOOST,
        "reply" => WEIGHT_REPLY,
        "bookmark" => WEIGHT_BOOKMARK,
        "click" => WEIGHT_CLICK,
        "dwell" => WEIGHT_DWELL * dwell_bucket_scale(stored_weight),
        "impression" => WEIGHT_IMPRESSION.abs(),
        _ => WEIGHT_OTHER,
    };
    let signed = if canonical == "impression" {
        -magnitude
    } else {
        magnitude
    };
    if stored_weight < 0.0 {
        -signed.abs()
    } else {
        signed
    }
}

fn hashtag_share(kind: &str) -> f64 {
    match canonical_signal_kind(kind) {
        "favourite" => HASHTAG_SHARE_FAVOURITE,
        "bookmark" => HASHTAG_SHARE_BOOKMARK,
        "dwell" => HASHTAG_SHARE_DWELL,
        _ => 0.0,
    }
}

fn legacy_heuristic_multiplier(kind: &str) -> f64 {
    match canonical_signal_kind(kind) {
        "favourite" => 1.4,
        "boost" | "reblog" => 1.2,
        "reply" => 1.0,
        "bookmark" => 1.1,
        "click" => 0.30,
        "dwell" => 0.12,
        "impression" => -0.03,
        _ => 0.5,
    }
}

/// Live Home signal multiplier from `load_signal_actor_weights`.
/// Likes are stored as `like` and canonicalized to `favourite` before this
/// runs, so `favourite` is the same 1.4 the live table gives `like`.
/// `reblog` and `quote` stay at 0.5, matching that table.
fn live_multiplier(kind: &str) -> f64 {
    match canonical_signal_kind(kind) {
        "favourite" => 1.4,
        "boost" => 1.2,
        "reply" => 1.0,
        "bookmark" => 1.1,
        "follow" => 0.6,
        "impression" => -0.03,
        "click" => 0.30,
        "dwell" => 0.12,
        _ => 0.5,
    }
}

fn is_holdout_label(kind: &str, stored_weight: f64) -> bool {
    if stored_weight <= 0.0 {
        return false;
    }
    matches!(
        canonical_signal_kind(kind),
        "favourite" | "bookmark" | "dwell" | "boost" | "reblog" | "quote" | "reply"
    )
}

fn label_grade(kind: &str, stored_weight: f64) -> f64 {
    if !is_holdout_label(kind, stored_weight) {
        return 0.0;
    }
    match canonical_signal_kind(kind) {
        "favourite" => 3.0,
        "bookmark" | "boost" | "reblog" | "quote" | "reply" => 2.0,
        "dwell" => 1.0,
        _ => 0.0,
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

fn strip_tags_lower(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    let mut in_tag = false;
    for ch in text.chars() {
        match ch {
            '<' => in_tag = true,
            '>' => in_tag = false,
            _ if !in_tag => out.push(ch),
            _ => {}
        }
    }
    out.to_ascii_lowercase()
}

/// Same token rules as the live favourite-tag extractor: a `#` run of 2–64
/// ASCII letters, digits, or underscores, lowercased, duplicates dropped.
fn extract_hashtags(text: &str) -> Vec<String> {
    let plain = strip_tags_lower(text);
    let mut tags = Vec::new();
    let mut seen = HashSet::new();
    let bytes = plain.as_bytes();
    let mut i = 0usize;
    while i < bytes.len() {
        if bytes[i] == b'#' {
            let start = i + 1;
            let mut end = start;
            while end < bytes.len() {
                let c = bytes[end];
                if c.is_ascii_alphanumeric() || c == b'_' {
                    end += 1;
                } else {
                    break;
                }
            }
            if end > start {
                let tag = plain[start..end].to_string();
                if (2..=64).contains(&tag.len()) && seen.insert(tag.clone()) {
                    tags.push(tag);
                }
                i = end;
                continue;
            }
        }
        i += 1;
    }
    tags
}

fn normalize_tag(raw: &str) -> Option<String> {
    let tag = raw.trim().trim_start_matches('#').to_ascii_lowercase();
    if (2..=64).contains(&tag.len()) && tag.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_') {
        Some(tag)
    } else {
        None
    }
}

fn cap_tags(tags: impl IntoIterator<Item = String>) -> Vec<String> {
    let mut seen = HashSet::new();
    let mut out = Vec::new();
    for tag in tags {
        if seen.insert(tag.clone()) {
            out.push(tag);
        }
        if out.len() == MAX_TAGS_PER_POST {
            break;
        }
    }
    out
}

fn hashtags_in_meta(meta: &Value) -> Vec<String> {
    let mut tags = Vec::new();
    if let Some(arr) = meta.get("hashtags").and_then(Value::as_array) {
        for value in arr {
            if let Some(text) = value.as_str() {
                if let Some(tag) = normalize_tag(text) {
                    tags.push(tag);
                }
            }
        }
    }
    if let Some(text) = meta.get("text").and_then(Value::as_str) {
        tags.extend(extract_hashtags(text));
    }
    cap_tags(tags)
}

fn content_keys(target_key: &str, meta: &Value) -> Vec<String> {
    let mut keys = Vec::new();
    let mut push = |raw: &str| {
        let key = raw.trim().trim_end_matches('/').to_string();
        if !key.is_empty() && !keys.iter().any(|existing| existing == &key) {
            keys.push(key);
        }
    };
    push(target_key);
    for field in ["object_id", "uri", "target_key"] {
        if let Some(value) = meta.get(field).and_then(Value::as_str) {
            push(value);
        }
    }
    keys
}

fn tags_for_keys(keys: &[String], texts: &HashMap<String, String>, meta_tags: Vec<String>) -> Vec<String> {
    let mut tags = meta_tags;
    for key in keys {
        if let Some(text) = texts.get(key) {
            tags.extend(extract_hashtags(text));
        }
    }
    cap_tags(tags)
}

fn parse_ts(raw: &str) -> i64 {
    if raw.is_empty() {
        return 0;
    }
    chrono::DateTime::parse_from_rfc3339(raw)
        .map(|dt| dt.timestamp())
        .unwrap_or(0)
}

fn nth_sunday_midnight_utc(year: i32, month: u32, n: u32) -> Option<i64> {
    let first = chrono::NaiveDate::from_ymd_opt(year, month, 1)?;
    let days_until_sunday = (7 - first.weekday().num_days_from_sunday()) % 7;
    let day = 1 + days_until_sunday + (n - 1) * 7;
    let date = chrono::NaiveDate::from_ymd_opt(year, month, day)?;
    Some(date.and_hms_opt(0, 0, 0)?.and_utc().timestamp())
}

/// US Eastern offset in seconds, including the March/November daylight boundary.
fn us_eastern_offset_secs(unix: i64) -> i32 {
    let Some(dt) = chrono::DateTime::from_timestamp(unix, 0) else {
        return -5 * 3600;
    };
    let year = dt.year();
    let Some(start) = nth_sunday_midnight_utc(year, 3, 2) else {
        return -5 * 3600;
    };
    let Some(end) = nth_sunday_midnight_utc(year, 11, 1) else {
        return -5 * 3600;
    };
    let start = start + 7 * 3600;
    let end = end + 6 * 3600;
    if unix >= start && unix < end {
        -4 * 3600
    } else {
        -5 * 3600
    }
}

fn eastern_calendar_date(unix: i64) -> String {
    let local = unix + i64::from(us_eastern_offset_secs(unix));
    chrono::DateTime::from_timestamp(local, 0)
        .map(|dt| dt.format("%Y-%m-%d").to_string())
        .unwrap_or_else(|| "1970-01-01".into())
}

fn round4(value: f64) -> f64 {
    if !value.is_finite() {
        return 0.0;
    }
    (value * 10_000.0).round() / 10_000.0
}

fn is_held_out(signal: &TrainSignal, cutoff: i64) -> bool {
    signal.created_at >= cutoff && is_holdout_label(&signal.kind, signal.stored_weight)
}

fn fit_shadow(signals: &[TrainSignal], cutoff: i64) -> Fit {
    let mut actors = HashMap::<String, f64>::new();
    let mut tags = HashMap::<String, f64>::new();
    let mut kinds = HashMap::<String, f64>::new();
    let mut positive = 0i64;
    let mut negative = 0i64;
    let mut trained = 0i64;
    let mut holdout = 0i64;
    for signal in signals {
        if is_held_out(signal, cutoff) {
            holdout += 1;
            continue;
        }
        trained += 1;
        let value = shadow_contribution(&signal.kind, signal.stored_weight);
        if value > 0.0 {
            positive += 1;
        } else {
            negative += 1;
        }
        *kinds.entry(signal.kind.clone()).or_insert(0.0) += value;
        if !signal.actor.is_empty() {
            let entry = actors.entry(signal.actor.clone()).or_insert(0.0);
            *entry = (*entry + value).clamp(ACTOR_CLAMP.0, ACTOR_CLAMP.1);
        }
        let share = hashtag_share(&signal.kind);
        if value > 0.0 && share > 0.0 {
            for tag in signal.hashtags.iter().take(MAX_TAGS_PER_POST) {
                let entry = tags.entry(tag.clone()).or_insert(0.0);
                *entry = (*entry + value * share).clamp(TAG_CLAMP.0, TAG_CLAMP.1);
            }
        }
    }
    actors.retain(|_, score| score.abs() >= MIN_ABS_WEIGHT);
    tags.retain(|_, score| score.abs() >= MIN_ABS_WEIGHT);
    Fit {
        actors,
        tags,
        kinds,
        positive,
        negative,
        trained,
        holdout,
    }
}

fn legacy_actor_weights(signals: &[TrainSignal]) -> HashMap<String, f64> {
    let mut actors = HashMap::<String, f64>::new();
    for signal in signals {
        let value = legacy_signal_value(&signal.kind);
        if signal.actor.is_empty() {
            continue;
        }
        let entry = actors.entry(signal.actor.clone()).or_insert(0.0);
        *entry = (*entry + value).clamp(ACTOR_CLAMP.0, ACTOR_CLAMP.1);
    }
    actors.retain(|_, score| score.abs() >= MIN_ABS_WEIGHT);
    actors
}

fn legacy_heuristic_weights(signals: &[TrainSignal]) -> HashMap<String, f64> {
    let mut actors = HashMap::<String, f64>::new();
    for signal in signals {
        if signal.actor.is_empty() {
            continue;
        }
        let delta = signal.stored_weight * legacy_heuristic_multiplier(&signal.kind);
        *actors.entry(signal.actor.clone()).or_insert(0.0) += delta;
    }
    actors
}

fn live_actor_scores(signals: &[TrainSignal], now: i64, cutoff: i64, row_limit: usize) -> HashMap<String, f64> {
    let mut actors = HashMap::<String, f64>::new();
    for signal in signals.iter().take(row_limit) {
        if is_held_out(signal, cutoff) || signal.actor.is_empty() || signal.stored_weight <= 0.0 {
            continue;
        }
        let age = (now - signal.created_at).max(0) as f64;
        let decay = (-age / LIVE_HALF_LIFE_SECS).exp();
        let delta = signal.stored_weight * live_multiplier(&signal.kind) * decay;
        let entry = actors.entry(signal.actor.clone()).or_insert(0.0);
        *entry = (*entry + delta).clamp(LIVE_ACTOR_CLAMP.0, LIVE_ACTOR_CLAMP.1);
    }
    actors
}

fn live_tag_counts(signals: &[TrainSignal], cutoff: i64) -> HashMap<String, f64> {
    let mut tags = HashMap::<String, f64>::new();
    for signal in signals {
        if is_held_out(signal, cutoff) || signal.stored_weight <= 0.0 {
            continue;
        }
        if canonical_signal_kind(&signal.kind) != "favourite" {
            continue;
        }
        for tag in signal.hashtags.iter().take(MAX_TAGS_PER_POST) {
            let entry = tags.entry(tag.clone()).or_insert(0.0);
            *entry = (*entry + 1.0).min(32.0);
        }
    }
    tags
}

fn actor_grades(signals: &[TrainSignal], cutoff: i64) -> HashMap<String, f64> {
    let mut grades = HashMap::<String, f64>::new();
    for signal in signals {
        if !is_held_out(signal, cutoff) || signal.actor.is_empty() {
            continue;
        }
        let grade = label_grade(&signal.kind, signal.stored_weight);
        if grade <= 0.0 {
            continue;
        }
        let entry = grades.entry(signal.actor.clone()).or_insert(0.0);
        if grade > *entry {
            *entry = grade;
        }
    }
    grades
}

fn tag_grades(signals: &[TrainSignal], cutoff: i64) -> HashMap<String, f64> {
    let mut grades = HashMap::<String, f64>::new();
    for signal in signals {
        if !is_held_out(signal, cutoff) || canonical_signal_kind(&signal.kind) != "favourite" {
            continue;
        }
        for tag in &signal.hashtags {
            grades.insert(tag.clone(), 1.0);
        }
    }
    grades
}

fn ranked_keys(scores: &HashMap<String, f64>, extra: impl IntoIterator<Item = String>) -> Vec<String> {
    let mut keys: Vec<String> = scores.keys().cloned().chain(extra).collect();
    keys.sort();
    keys.dedup();
    keys.sort_by(|a, b| {
        let left = scores.get(a).copied().unwrap_or(0.0);
        let right = scores.get(b).copied().unwrap_or(0.0);
        right.total_cmp(&left).then_with(|| a.cmp(b))
    });
    keys
}

fn overlap_lists(left: &[String], right: &[String]) -> (usize, f64) {
    let overlap = left.iter().filter(|item| right.contains(item)).count();
    let union = left.iter().chain(right.iter()).collect::<HashSet<_>>().len();
    let jaccard = if union == 0 { 0.0 } else { overlap as f64 / union as f64 };
    (overlap, jaccard)
}

fn legacy_overlap(signals: &[TrainSignal], k: usize) -> (usize, f64, usize) {
    let model = legacy_actor_weights(signals);
    let heuristic = legacy_heuristic_weights(signals);
    let mut universe: Vec<String> = model.keys().chain(heuristic.keys()).cloned().collect();
    universe.sort();
    universe.dedup();
    let rank = |scores: &HashMap<String, f64>| -> Vec<String> {
        let mut keys = universe.clone();
        keys.sort_by(|a, b| {
            let left = scores.get(a).copied().unwrap_or(0.0);
            let right = scores.get(b).copied().unwrap_or(0.0);
            right.total_cmp(&left).then_with(|| a.cmp(b))
        });
        keys.truncate(k.clamp(1, 100));
        keys
    };
    let (overlap, jaccard) = overlap_lists(&rank(&model), &rank(&heuristic));
    (overlap, jaccard, universe.len())
}

fn metrics_at(ranking: &[String], grades: &HashMap<String, f64>, k: usize) -> RankMetrics {
    let relevant = grades.values().filter(|grade| **grade > 0.0).count();
    if relevant == 0 {
        return RankMetrics {
            hit_rate: 0.0,
            recall: 0.0,
            ndcg: 0.0,
            mrr: 0.0,
        };
    }
    let k = k.max(1);
    let mut hits = 0usize;
    let mut dcg = 0.0;
    let mut first_rank = None;
    for (index, item) in ranking.iter().enumerate() {
        let grade = grades.get(item).copied().unwrap_or(0.0);
        if grade <= 0.0 {
            continue;
        }
        if first_rank.is_none() {
            first_rank = Some(index + 1);
        }
        if index < k {
            hits += 1;
            dcg += grade / ((index as f64) + 2.0).log2();
        }
    }
    let mut ideal: Vec<f64> = grades.values().copied().filter(|grade| *grade > 0.0).collect();
    ideal.sort_by(|a, b| b.total_cmp(a));
    ideal.truncate(k);
    let idcg: f64 = ideal
        .iter()
        .enumerate()
        .map(|(index, grade)| grade / ((index as f64) + 2.0).log2())
        .sum();
    RankMetrics {
        hit_rate: if hits > 0 { 1.0 } else { 0.0 },
        recall: hits as f64 / relevant as f64,
        ndcg: if idcg == 0.0 { 0.0 } else { dcg / idcg },
        mrr: first_rank.map(|rank| 1.0 / rank as f64).unwrap_or(0.0),
    }
}

fn score_side(scores: &HashMap<String, f64>, grades: &HashMap<String, f64>) -> Option<RankScore> {
    if grades.values().all(|grade| *grade <= 0.0) {
        return None;
    }
    let ranking = ranked_keys(scores, grades.keys().cloned());
    let at_10 = metrics_at(&ranking, grades, 10);
    let at_20 = metrics_at(&ranking, grades, 20);
    Some(RankScore {
        hit_rate_at_10: round4(at_10.hit_rate),
        recall_at_10: round4(at_10.recall),
        ndcg_at_10: round4(at_10.ndcg),
        hit_rate_at_20: round4(at_20.hit_rate),
        recall_at_20: round4(at_20.recall),
        ndcg_at_20: round4(at_20.ndcg),
        mrr: round4(at_20.mrr),
    })
}

fn family(shadow_scores: &HashMap<String, f64>, live_scores: &HashMap<String, f64>, grades: &HashMap<String, f64>) -> FamilyScore {
    let shadow = score_side(shadow_scores, grades);
    let live = score_side(live_scores, grades);
    let delta = match (&shadow, &live) {
        (Some(shadow), Some(live)) => Some(round4(shadow.ndcg_at_20 - live.ndcg_at_20)),
        _ => None,
    };
    FamilyScore {
        relevant: grades.values().filter(|grade| **grade > 0.0).count(),
        shadow,
        live,
        shadow_minus_live_ndcg_at_20: delta,
    }
}

fn build_scorecard(owner_user_id: i64, signals: &[TrainSignal], now: i64, overlap_k: usize) -> (ShadowModel, Scorecard) {
    let cutoff = now - HOLDOUT_DAYS * 86_400;
    let fit = fit_shadow(signals, cutoff);
    let (overlap, jaccard, candidates) = legacy_overlap(signals, overlap_k);
    let live_actors = live_actor_scores(signals, now, cutoff, LIVE_SIGNAL_ROWS);
    let live_tags = live_tag_counts(signals, cutoff);
    let actor_labels = actor_grades(signals, cutoff);
    let tag_labels = tag_grades(signals, cutoff);
    let actors = family(&fit.actors, &live_actors, &actor_labels);
    let hashtags = family(&fit.tags, &live_tags, &tag_labels);
    let model = ShadowModel {
        version: MODEL_VERSION,
        owner_user_id,
        trained_at: now,
        sample_count: fit.trained,
        positive_count: fit.positive,
        negative_count: fit.negative,
        rows_seen: signals.len() as i64,
        holdout_count: fit.holdout,
        holdout_days: HOLDOUT_DAYS,
        actor_weights: fit.actors.clone(),
        hashtag_weights: fit.tags.clone(),
        signal_weights: fit.kinds.clone(),
    };
    let card = Scorecard {
        owner_user_id,
        run_on: eastern_calendar_date(now),
        model_version: MODEL_VERSION,
        holdout_days: HOLDOUT_DAYS,
        rows_seen: signals.len() as i64,
        training_samples: fit.trained,
        holdout_positive_rows: fit.holdout,
        candidate_actors: candidates,
        candidate_hashtags: fit.tags.len(),
        top_k: overlap_k.clamp(1, 100),
        top_k_overlap: overlap,
        top_k_jaccard: round4(jaccard),
        actors,
        hashtags,
        weights: json!({
            "favourite": WEIGHT_FAVOURITE,
            "bookmark": WEIGHT_BOOKMARK,
            "dwell": WEIGHT_DWELL,
            "hashtag_share_favourite": HASHTAG_SHARE_FAVOURITE,
            "hashtag_share_bookmark": HASHTAG_SHARE_BOOKMARK,
        }),
        note: "shadow scorecard; live Home order unchanged. overlap@20 is the full-history actor comparison. holdout metrics withhold the newest positive interactions from both the shadow model and the live signal replay.".into(),
    };
    (model, card)
}

fn fmt_side(label: &str, score: &Option<RankScore>) -> String {
    match score {
        Some(score) => format!(
            "  {label:<14} hit@20={:.3} recall@20={:.3} ndcg@20={:.3} mrr={:.3}",
            score.hit_rate_at_20, score.recall_at_20, score.ndcg_at_20, score.mrr
        ),
        None => format!("  {label:<14} (no held-out labels)"),
    }
}

fn human_report(card: &Scorecard) -> String {
    let actor_delta = card
        .actors
        .shadow_minus_live_ndcg_at_20
        .map(|delta| format!(" ndcg_delta={delta:.3}"))
        .unwrap_or_default();
    let tag_delta = card
        .hashtags
        .shadow_minus_live_ndcg_at_20
        .map(|delta| format!(" ndcg_delta={delta:.3}"))
        .unwrap_or_default();
    format!(
        "ml-shadow scorecard day={} model={} holdout_days={}\n  train={} holdout_positives={} actors={} tags={}\n  overlap@{}={} jaccard={:.3}\n{}\n{}{actor_delta}\n{}\n{}{tag_delta}",
        card.run_on,
        card.model_version,
        card.holdout_days,
        card.training_samples,
        card.holdout_positive_rows,
        card.candidate_actors,
        card.candidate_hashtags,
        card.top_k,
        card.top_k_overlap,
        card.top_k_jaccard,
        fmt_side("actors shadow", &card.actors.shadow),
        fmt_side("actors live", &card.actors.live),
        fmt_side("tags shadow", &card.hashtags.shadow),
        fmt_side("tags live", &card.hashtags.live),
    )
}

fn print_report(card: &Scorecard) {
    println!("{}", human_report(card));
    match serde_json::to_string(card) {
        Ok(line) => println!("ml-shadow-scorecard {line}"),
        Err(err) => eprintln!("ml-shadow scorecard json skipped: {err}"),
    }
}

fn row_weight(row: &tokio_postgres::Row) -> f64 {
    row.try_get::<_, Option<f64>>(1)
        .ok()
        .flatten()
        .or_else(|| {
            row.try_get::<_, Option<i32>>(1)
                .ok()
                .flatten()
                .map(|value| value as f64)
        })
        .or_else(|| {
            row.try_get::<_, Option<f32>>(1)
                .ok()
                .flatten()
                .map(|value| value as f64)
        })
        .unwrap_or(1.0)
}

fn store_text(out: &mut HashMap<String, String>, key: String, text: String) {
    let text: String = text.chars().take(4_000).collect();
    if text.trim().is_empty() {
        return;
    }
    out.entry(key)
        .and_modify(|existing| {
            if text.len() > existing.len() {
                *existing = text.clone();
            }
        })
        .or_insert(text);
}

async fn lookup_texts(db: &Client, keys: &[String]) -> HashMap<String, String> {
    let mut out = HashMap::new();
    if keys.is_empty() {
        return out;
    }
    let limited: Vec<String> = keys.iter().take(LOOKUP_KEY_LIMIT).cloned().collect();
    for chunk in limited.chunks(LOOKUP_CHUNK) {
        let owned = chunk.to_vec();
        if let Ok(rows) = db
            .query(
                "SELECT bsky_uri, text FROM bsky_posts WHERE bsky_uri = ANY($1::text[])",
                &[&owned],
            )
            .await
        {
            for row in rows {
                let Some(key) = row.try_get::<_, Option<String>>(0).ok().flatten() else {
                    continue;
                };
                let text = row.try_get::<_, Option<String>>(1).ok().flatten().unwrap_or_default();
                store_text(&mut out, key, text);
            }
        }
        if let Ok(rows) = db
            .query(
                "SELECT id, content FROM outbox_notes WHERE id = ANY($1::text[])",
                &[&owned],
            )
            .await
        {
            for row in rows {
                let Some(key) = row.try_get::<_, Option<String>>(0).ok().flatten() else {
                    continue;
                };
                let text = row.try_get::<_, Option<String>>(1).ok().flatten().unwrap_or_default();
                store_text(&mut out, key, text);
            }
        }
        if let Ok(rows) = db
            .query(
                "SELECT object_id, summary FROM events WHERE object_id = ANY($1::text[])",
                &[&owned],
            )
            .await
        {
            for row in rows {
                let Some(key) = row.try_get::<_, Option<String>>(0).ok().flatten() else {
                    continue;
                };
                let text = row.try_get::<_, Option<String>>(1).ok().flatten().unwrap_or_default();
                store_text(&mut out, key, text);
            }
        }
        if let Ok(rows) = db
            .query(
                "SELECT note_id, content_text FROM masto_statuses WHERE note_id = ANY($1::text[])",
                &[&owned],
            )
            .await
        {
            for row in rows {
                let Some(key) = row.try_get::<_, Option<String>>(0).ok().flatten() else {
                    continue;
                };
                let text = row.try_get::<_, Option<String>>(1).ok().flatten().unwrap_or_default();
                store_text(&mut out, key, text);
            }
        }
    }
    let numeric: Vec<String> = limited
        .iter()
        .filter(|key| {
            key.parse::<i64>()
                .ok()
                .is_some_and(|id| id > 0 && id < 2_000_000)
        })
        .cloned()
        .collect();
    for chunk in numeric.chunks(LOOKUP_CHUNK) {
        let owned = chunk.to_vec();
        if let Ok(rows) = db
            .query(
                "SELECT local_id::text, content_text FROM masto_statuses WHERE local_id::text = ANY($1::text[])",
                &[&owned],
            )
            .await
        {
            for row in rows {
                let Some(key) = row.try_get::<_, Option<String>>(0).ok().flatten() else {
                    continue;
                };
                let text = row.try_get::<_, Option<String>>(1).ok().flatten().unwrap_or_default();
                store_text(&mut out, key, text);
            }
        }
    }
    out
}

async fn load_signals(db: &Client, owner_user_id: i64) -> Result<Vec<TrainSignal>> {
    let since = (Utc::now() - chrono::Duration::days(SIGNAL_LOOKBACK_DAYS)).to_rfc3339();
    let rows = db
        .query(
            "SELECT signal_type, weight, metadata_json, created_at, target_key
             FROM ap_user_signals
             WHERE owner_user_id = $1 AND created_at >= $2
             ORDER BY id DESC
             LIMIT $3",
            &[&owner_user_id, &since, &SIGNAL_ROW_LIMIT],
        )
        .await
        .context("load ranker training signals")?;
    let mut pending = Vec::with_capacity(rows.len());
    let mut lookup = Vec::new();
    for row in &rows {
        let kind = row
            .try_get::<_, Option<String>>(0)
            .ok()
            .flatten()
            .unwrap_or_default()
            .to_ascii_lowercase();
        let stored_weight = row_weight(row);
        let raw = row
            .try_get::<_, Option<String>>(2)
            .ok()
            .flatten()
            .unwrap_or_else(|| "{}".into());
        let meta: Value = serde_json::from_str(&raw).unwrap_or_else(|_| json!({}));
        let created_at = parse_ts(
            &row.try_get::<_, Option<String>>(3)
                .ok()
                .flatten()
                .unwrap_or_default(),
        );
        let target_key = row
            .try_get::<_, Option<String>>(4)
            .ok()
            .flatten()
            .unwrap_or_default();
        let canonical = canonical_signal_kind(&kind).to_string();
        let wants_text = stored_weight > 0.0
            && matches!(canonical.as_str(), "favourite" | "bookmark")
            && lookup.len() < LOOKUP_KEY_LIMIT;
        let keys = content_keys(&target_key, &meta);
        if wants_text {
            for key in &keys {
                if !lookup.iter().any(|existing| existing == key) {
                    lookup.push(key.clone());
                }
            }
        }
        pending.push((canonical, stored_weight, created_at, actor_from_metadata(&meta), hashtags_in_meta(&meta), keys));
    }
    let texts = lookup_texts(db, &lookup).await;
    let signals = pending
        .into_iter()
        .map(|(kind, stored_weight, created_at, actor, meta_tags, keys)| {
            let hashtags = if stored_weight > 0.0 && matches!(kind.as_str(), "favourite" | "bookmark") {
                tags_for_keys(&keys, &texts, meta_tags)
            } else {
                meta_tags
            };
            TrainSignal {
                kind,
                stored_weight,
                created_at,
                actor,
                hashtags,
            }
        })
        .collect();
    Ok(signals)
}

async fn save_model(cfg: &Config, model: &ShadowModel) -> Result<()> {
    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let key = format!("vaak:ml:ranker:v1:{}", model.owner_user_id);
    redis_util::json_set(
        &mut redis,
        &key,
        &serde_json::to_value(model)?,
        MODEL_TTL_SECS,
    )
    .await?;
    Ok(())
}

async fn save_history(db: &Client, card: &Scorecard) -> Result<()> {
    let summary = serde_json::to_string(card).context("encode scorecard")?;
    let created_at = Utc::now().to_rfc3339();
    let model_version = card.model_version as i32;
    db.execute(
        "INSERT INTO ap_ml_shadow_scorecard
            (owner_user_id, run_on, model_version, summary_json, created_at)
         VALUES ($1, $2, $3, $4, $5)
         ON CONFLICT (owner_user_id, run_on)
         DO UPDATE SET model_version = EXCLUDED.model_version,
                       summary_json = EXCLUDED.summary_json,
                       created_at = EXCLUDED.created_at",
        &[
            &card.owner_user_id,
            &card.run_on,
            &model_version,
            &summary,
            &created_at,
        ],
    )
    .await
    .context("store shadow scorecard")?;
    let cutoff_unix = Utc::now().timestamp() - HISTORY_DAYS * 86_400;
    let cutoff = eastern_calendar_date(cutoff_unix);
    db.execute(
        "DELETE FROM ap_ml_shadow_scorecard WHERE owner_user_id = $1 AND run_on < $2",
        &[&card.owner_user_id, &cutoff],
    )
    .await
    .context("prune shadow scorecard history")?;
    Ok(())
}

async fn evaluate(cfg: &Config, owner_user_id: i64, persist: bool, overlap_k: usize) -> Result<Scorecard> {
    let db = db::connect(&cfg.database_url).await?;
    let signals = load_signals(&db, owner_user_id).await?;
    let now = Utc::now().timestamp();
    let (model, card) = build_scorecard(owner_user_id, &signals, now, overlap_k);
    if persist {
        save_model(cfg, &model).await?;
        if let Err(err) = save_history(&db, &card).await {
            eprintln!("ml-shadow scorecard history skipped: {err:#}");
        }
    }
    Ok(card)
}

pub async fn run(cfg: &Config, owner_user_id: i64) -> Result<()> {
    let card = evaluate(cfg, owner_user_id, true, 20).await?;
    print_report(&card);
    Ok(())
}

/// Compare the shadow model with the current heuristic actor ranking and
/// print the holdout scorecard. This does not retrain or change Home order.
pub async fn compare(cfg: &Config, owner_user_id: i64, limit: usize) -> Result<()> {
    let mut redis = redis_util::connect(&cfg.redis_url).await?;
    let key = format!("vaak:ml:ranker:v1:{owner_user_id}");
    let Some(raw) = redis_util::json_get(&mut redis, &key).await? else {
        anyhow::bail!("shadow model is not trained for owner {owner_user_id}");
    };
    let _model: ShadowModel = serde_json::from_value(raw).context("decode shadow model")?;
    let card = evaluate(cfg, owner_user_id, false, limit).await?;
    print_report(&card);
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn sig(kind: &str, weight: f64, at: i64, actor: &str, tags: &[&str]) -> TrainSignal {
        TrainSignal {
            kind: canonical_signal_kind(kind).to_string(),
            stored_weight: weight,
            created_at: at,
            actor: actor.trim_end_matches('/').to_string(),
            hashtags: tags.iter().map(|tag| (*tag).to_string()).collect(),
        }
    }

    #[test]
    fn hashtag_extraction_matches_liked_post_text() {
        assert_eq!(
            extract_hashtags("Hello #Fediverse and #Art!"),
            vec!["fediverse", "art"]
        );
        assert_eq!(extract_hashtags("<b>#Code</b> #code"), vec!["code"]);
        assert_eq!(extract_hashtags("#a #ok_tag"), vec!["ok_tag"]);
        assert!(extract_hashtags("no tags here").is_empty());
        let keys = vec!["at://did:plc:abc/app.bsky.feed.post/1".to_string()];
        let mut texts = HashMap::new();
        texts.insert(keys[0].clone(), "saved #Cats and #cats #Dogs".to_string());
        let tags = tags_for_keys(&keys, &texts, vec!["art".into()]);
        assert_eq!(tags, vec!["art", "cats", "dogs"]);
    }

    #[test]
    fn signal_weights_stay_in_one_place() {
        assert_eq!(shadow_contribution("like", 1.0), WEIGHT_FAVOURITE);
        assert_eq!(shadow_contribution("favourite", 1.0), 1.35);
        assert_eq!(shadow_contribution("bookmark", 1.0), 0.80);
        assert!((shadow_contribution("dwell", 1.0) - 0.15).abs() < 1e-9);
        assert!((shadow_contribution("dwell", 2.0) - 0.25).abs() < 1e-9);
        assert!((shadow_contribution("dwell", 4.0) - 0.50).abs() < 1e-9);
        assert!(shadow_contribution("dwell", 4.0) < shadow_contribution("bookmark", 1.0));
        assert!(shadow_contribution("bookmark", 1.0) < shadow_contribution("like", 1.0));
        assert_eq!(shadow_contribution("impression", 1.0), -0.05);
        assert_eq!(shadow_contribution("like", -1.0), -1.35);
        assert_eq!(legacy_signal_value("dwell"), 0.25);
        assert_eq!(hashtag_share("like"), HASHTAG_SHARE_FAVOURITE);
        assert_eq!(hashtag_share("bookmark"), HASHTAG_SHARE_BOOKMARK);
        assert_eq!(hashtag_share("dwell"), 0.0);
    }

    #[test]
    fn training_learns_accounts_and_liked_hashtags_without_the_holdout() {
        let now = 1_700_000_000i64;
        let cutoff = now - HOLDOUT_DAYS * 86_400;
        let old_at = cutoff - 86_400;
        let new_at = cutoff + 3_600;
        let signals = vec![
            sig("like", 1.0, new_at, "https://new.example/users/b", &["dogs"]),
            sig("like", 1.0, old_at, "https://old.example/users/a", &["cats", "art"]),
            sig("bookmark", 1.0, old_at, "https://old.example/users/a", &["cats"]),
            sig("dwell", 4.0, old_at, "https://old.example/users/c", &["nope"]),
            sig("impression", 1.0, old_at, "https://old.example/users/a", &[]),
        ];
        let fit = fit_shadow(&signals, cutoff);
        assert!(fit.actors.contains_key("https://old.example/users/a"));
        assert!(!fit.actors.contains_key("https://new.example/users/b"));
        assert!(fit.tags.contains_key("cats"));
        assert!(fit.tags.contains_key("art"));
        assert!(!fit.tags.contains_key("dogs"));
        assert!(!fit.tags.contains_key("nope"));
        let expected_a = 1.35 + 0.8 - 0.05;
        assert!((fit.actors["https://old.example/users/a"] - expected_a).abs() < 1e-9);
        let cats = 1.35 * HASHTAG_SHARE_FAVOURITE + 0.8 * HASHTAG_SHARE_BOOKMARK;
        assert!((fit.tags["cats"] - cats).abs() < 1e-9);
        assert!((fit.actors["https://old.example/users/c"] - 0.5).abs() < 1e-9);
        assert_eq!(fit.holdout, 1);
        assert_eq!(actor_grades(&signals, cutoff)["https://new.example/users/b"], 3.0);
        assert_eq!(tag_grades(&signals, cutoff).get("dogs"), Some(&1.0));
        assert!(!tag_grades(&signals, cutoff).contains_key("cats"));
    }

    #[test]
    fn ranking_metrics_hit_recall_ndcg_and_mrr() {
        let mut scores = HashMap::new();
        scores.insert("a".into(), 5.0);
        scores.insert("b".into(), 1.0);
        let mut grades = HashMap::new();
        grades.insert("a".into(), 3.0);
        let perfect = score_side(&scores, &grades).unwrap();
        assert_eq!(perfect.hit_rate_at_20, 1.0);
        assert_eq!(perfect.recall_at_20, 1.0);
        assert_eq!(perfect.ndcg_at_20, 1.0);
        assert_eq!(perfect.mrr, 1.0);

        let mut missed = HashMap::new();
        missed.insert("b".into(), 9.0);
        missed.insert("a".into(), 5.0);
        missed.insert("c".into(), 0.0);
        let mut two = HashMap::new();
        two.insert("a".into(), 3.0);
        two.insert("c".into(), 2.0);
        let ranking = ranked_keys(&missed, two.keys().cloned());
        assert_eq!(ranking, vec!["b".to_string(), "a".to_string(), "c".to_string()]);
        let at_1 = metrics_at(&ranking, &two, 1);
        let at_2 = metrics_at(&ranking, &two, 2);
        assert_eq!(at_1.hit_rate, 0.0);
        assert_eq!(at_1.recall, 0.0);
        assert!((at_1.mrr - 0.5).abs() < 1e-9);
        assert_eq!(at_2.hit_rate, 1.0);
        assert!((at_2.recall - 0.5).abs() < 1e-9);
        let log3 = 3.0_f64.log2();
        let dcg = 3.0 / log3;
        let idcg = 3.0 + 2.0 / log3;
        assert!((at_2.ndcg - dcg / idcg).abs() < 1e-9);
        assert!(score_side(&scores, &HashMap::new()).is_none());
    }

    #[test]
    fn legacy_overlap_keeps_the_version_one_account_comparison() {
        let signals = vec![
            sig("like", 1.0, 0, "https://example/a", &[]),
            sig("like", 1.0, 0, "https://example/a", &[]),
            sig("bookmark", 1.0, 0, "https://example/b", &[]),
            sig("follow", 10.0, 0, "https://example/d", &[]),
        ];
        let (overlap, jaccard, _) = legacy_overlap(&signals, 2);
        assert_eq!(overlap, 1);
        assert!((jaccard - 1.0 / 3.0).abs() < 1e-9);
        let (all, jaccard_all, _) = legacy_overlap(&signals, 20);
        assert_eq!(all, 3);
        assert!((jaccard_all - 1.0).abs() < 1e-9);
    }

    #[test]
    fn live_replay_drops_holdout_labels_and_respects_the_row_cap() {
        let now = 1_700_000_000i64;
        let cutoff = now - HOLDOUT_DAYS * 86_400;
        let signals = vec![
            sig("like", 1.0, cutoff + 60, "https://example/new", &["dogs"]),
            sig("impression", 1.0, now - 10, "https://example/noise", &[]),
            sig("like", 1.0, now - 30 * 86_400, "https://example/old", &["cats"]),
        ];
        let live = live_actor_scores(&signals, now, cutoff, 1);
        assert!(live.contains_key("https://example/noise") || live.is_empty() || !live.contains_key("https://example/old"));
        assert!(!live.contains_key("https://example/old"));
        assert!(!live.contains_key("https://example/new"));
        let wider = live_actor_scores(&signals, now, cutoff, 3);
        assert!(wider.contains_key("https://example/old"));
        assert!(!wider.contains_key("https://example/new"));
        let age = (30 * 86_400) as f64;
        let expected = (1.0 * 1.4 * (-age / LIVE_HALF_LIFE_SECS).exp()).clamp(-3.0, 24.0);
        assert!((wider["https://example/old"] - expected).abs() < 1e-9);
        assert!(live_tag_counts(&signals, cutoff).contains_key("cats"));
        assert!(!live_tag_counts(&signals, cutoff).contains_key("dogs"));
    }

    #[test]
    fn scorecard_holds_out_a_recent_like_and_keeps_overlap() {
        let now = 1_700_000_000i64;
        let cutoff = now - HOLDOUT_DAYS * 86_400;
        let signals = vec![
            sig("like", 1.0, cutoff + 60, "https://example/new", &["dogs"]),
            sig("like", 1.0, cutoff - 86_400, "https://example/old", &["cats"]),
            sig("bookmark", 1.0, cutoff - 86_400, "https://example/old", &["cats"]),
        ];
        let (model, card) = build_scorecard(7, &signals, now, 20);
        assert_eq!(model.version, 2);
        assert!(!model.actor_weights.contains_key("https://example/new"));
        assert!(model.actor_weights.contains_key("https://example/old"));
        assert!(model.hashtag_weights.contains_key("cats"));
        assert!(!model.hashtag_weights.contains_key("dogs"));
        assert_eq!(card.holdout_positive_rows, 1);
        assert_eq!(card.top_k_overlap, 2);
        let shadow = card.actors.shadow.expect("actor metrics");
        assert!((shadow.mrr - 0.5).abs() < 1e-9);
        assert_eq!(shadow.hit_rate_at_20, 1.0);
        assert_eq!(card.hashtags.relevant, 1);
        assert!(card.note.contains("live Home order unchanged"));
    }

    #[test]
    fn eastern_calendar_date_follows_us_daylight_time() {
        let summer = chrono::DateTime::parse_from_rfc3339("2026-07-04T16:00:00Z")
            .unwrap()
            .timestamp();
        assert_eq!(us_eastern_offset_secs(summer), -4 * 3600);
        assert_eq!(eastern_calendar_date(summer), "2026-07-04");
        let winter = chrono::DateTime::parse_from_rfc3339("2026-01-15T04:30:00Z")
            .unwrap()
            .timestamp();
        assert_eq!(us_eastern_offset_secs(winter), -5 * 3600);
        assert_eq!(eastern_calendar_date(winter), "2026-01-14");
        let before_end = chrono::DateTime::parse_from_rfc3339("2026-11-01T05:30:00Z")
            .unwrap()
            .timestamp();
        let after_end = chrono::DateTime::parse_from_rfc3339("2026-11-01T06:30:00Z")
            .unwrap()
            .timestamp();
        assert_eq!(us_eastern_offset_secs(before_end), -4 * 3600);
        assert_eq!(us_eastern_offset_secs(after_end), -5 * 3600);
        let before_start = chrono::DateTime::parse_from_rfc3339("2026-03-08T06:30:00Z")
            .unwrap()
            .timestamp();
        let after_start = chrono::DateTime::parse_from_rfc3339("2026-03-08T07:30:00Z")
            .unwrap()
            .timestamp();
        assert_eq!(us_eastern_offset_secs(before_start), -5 * 3600);
        assert_eq!(us_eastern_offset_secs(after_start), -4 * 3600);
    }
}
