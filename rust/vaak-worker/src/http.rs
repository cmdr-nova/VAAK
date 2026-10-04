//! Localhost-only Axum shadow HTTP (wave 2–3). Does not replace PHP routes.

use std::net::SocketAddr;
use std::sync::Arc;

use anyhow::{Context, Result};
use axum::extract::{Query, State};
use axum::http::StatusCode;
use axum::response::IntoResponse;
use axum::routing::get;
use axum::{Json, Router};
use serde::Deserialize;

use crate::config::Config;

#[derive(Clone)]
struct AppState {
    cfg: Arc<Config>,
}

#[derive(Debug, Deserialize)]
pub struct OwnerQuery {
    pub owner_id: Option<i64>,
    pub since_secs: Option<i64>,
    pub limit: Option<i64>,
    /// Accepts true/false/1/0/yes/no (string form in query).
    pub compare: Option<String>,
    pub fetch: Option<String>,
    pub live: Option<String>,
    /// Comma-separated Mastodon notification types (empty = all).
    pub types: Option<String>,
    pub max_id: Option<String>,
    pub since_id: Option<String>,
    /// Ice Cubes pull-to-refresh uses min_id (same exclusive lower bound as since_id).
    pub min_id: Option<String>,
}

fn truthy(raw: Option<&str>) -> bool {
    matches!(
        raw.map(str::trim).map(str::to_ascii_lowercase).as_deref(),
        Some("1") | Some("true") | Some("yes") | Some("on")
    )
}

fn json_result<T: serde::Serialize>(report: Result<T>) -> axum::response::Response {
    match report {
        Ok(v) => match serde_json::to_value(v) {
            Ok(body) => (StatusCode::OK, Json(body)).into_response(),
            Err(e) => (
                StatusCode::INTERNAL_SERVER_ERROR,
                Json(serde_json::json!({"error": e.to_string()})),
            )
                .into_response(),
        },
        Err(e) => (
            StatusCode::INTERNAL_SERVER_ERROR,
            Json(serde_json::json!({"error": e.to_string()})),
        )
            .into_response(),
    }
}

pub async fn serve(cfg: Config, bind: SocketAddr) -> Result<()> {
    // Safety: shadow HTTP must stay loopback unless explicitly overridden.
    if !bind.ip().is_loopback() {
        anyhow::bail!("refuse non-loopback bind {bind} (shadow HTTP is localhost-only)");
    }

    let state = AppState {
        cfg: Arc::new(cfg),
    };
    let app = Router::new()
        .route("/healthz", get(healthz))
        .route("/shadow/notif", get(shadow_notif))
        .route("/shadow/notifications", get(shadow_notifications))
        .route("/shadow/ranked-newer", get(shadow_ranked))
        .route("/shadow/action-queue", get(shadow_action_queue))
        .route("/shadow/jetstream", get(shadow_jetstream))
        .route("/shadow/thin-media", get(shadow_thin_media))
        .route("/shadow/timelines/home", get(shadow_home))
        // Mastodon-shaped Home: hydrated status JSON from vaak:timeline:v1 (slice 4).
        .route("/api/v1/timelines/home", get(shadow_home_masto))
        .route("/api/v1/notifications", get(shadow_notifications_masto))
        .with_state(state);

    tracing::info!(%bind, "vaak-worker shadow HTTP listening");
    let listener = tokio::net::TcpListener::bind(bind)
        .await
        .with_context(|| format!("bind {bind}"))?;
    axum::serve(listener, app).await.context("axum serve")?;
    Ok(())
}

async fn healthz(State(state): State<AppState>) -> impl IntoResponse {
    let _ = std::mem::size_of::<vaak_types::NormalizedStatusProjection>();
    Json(serde_json::json!({
        "ok": true,
        "service": "vaak-worker-shadow",
        "owner_default": state.cfg.default_owner_id,
        "mode": "shadow",
        "freeze_contract": "vaak_types::NormalizedStatusProjection",
        "routes": [
            "/healthz",
            "/shadow/notif",
            "/shadow/notifications",
            "/shadow/ranked-newer",
            "/shadow/action-queue",
            "/shadow/jetstream",
            "/shadow/thin-media",
            "/shadow/timelines/home",
            "/api/v1/timelines/home",
            "/api/v1/notifications"
        ],
    }))
}

async fn shadow_notif(
    State(state): State<AppState>,
    Query(q): Query<OwnerQuery>,
) -> impl IntoResponse {
    let owner = q.owner_id.filter(|v| *v > 0).unwrap_or(state.cfg.default_owner_id);
    let compare = truthy(q.compare.as_deref());
    let live = truthy(q.live.as_deref());
    match crate::notif::compute_and_cache(&state.cfg, owner, compare, live).await {
        Ok(body) => (StatusCode::OK, Json(body)).into_response(),
        Err(e) => (
            StatusCode::INTERNAL_SERVER_ERROR,
            Json(serde_json::json!({"error": e.to_string()})),
        )
            .into_response(),
    }
}

async fn shadow_ranked(
    State(state): State<AppState>,
    Query(q): Query<OwnerQuery>,
) -> impl IntoResponse {
    let owner = q.owner_id.filter(|v| *v > 0).unwrap_or(state.cfg.default_owner_id);
    let since = q.since_secs.unwrap_or(900);
    let limit = q.limit.unwrap_or(20);
    json_result(crate::ranked::fetch_report(&state.cfg, owner, since, limit).await)
}

async fn shadow_action_queue(
    State(state): State<AppState>,
    Query(q): Query<OwnerQuery>,
) -> impl IntoResponse {
    let limit = q.limit.unwrap_or(25);
    json_result(crate::action_queue::report(&state.cfg, limit).await)
}

async fn shadow_jetstream(State(state): State<AppState>) -> impl IntoResponse {
    json_result(crate::jetstream::report_once(&state.cfg, 10, 15).await)
}

async fn shadow_thin_media(
    State(state): State<AppState>,
    Query(q): Query<OwnerQuery>,
) -> impl IntoResponse {
    let limit = q.limit.unwrap_or(20).clamp(1, 40) as usize;
    let fetch = truthy(q.fetch.as_deref());
    json_result(crate::thin_media::dry_run(&state.cfg, limit, fetch).await)
}

async fn shadow_home(
    State(state): State<AppState>,
    Query(q): Query<OwnerQuery>,
) -> impl IntoResponse {
    let owner = q.owner_id.filter(|v| *v > 0).unwrap_or(state.cfg.default_owner_id);
    let limit = q.limit.unwrap_or(20).clamp(1, 40) as usize;
    json_result(crate::timeline::home_shadow(&state.cfg, owner, limit).await)
}

/// Mastodon-shaped Home statuses from PHP hydrate Redis (404 on miss).
async fn shadow_home_masto(
    State(state): State<AppState>,
    Query(q): Query<OwnerQuery>,
) -> impl IntoResponse {
    let owner = q.owner_id.filter(|v| *v > 0).unwrap_or(state.cfg.default_owner_id);
    let limit = q.limit.unwrap_or(40).clamp(1, 80) as usize;
    // Head polls only (Ice Cubes cache); scroll pages are not cached by PHP.
    if q.max_id.as_deref().map(str::trim).filter(|s| !s.is_empty()).is_some() {
        return (
            StatusCode::NOT_FOUND,
            Json(serde_json::json!({
                "error": "home hydrate cache is head-only (no max_id)",
                "mode": "shadow",
            })),
        )
            .into_response();
    }
    let since = q
        .since_id
        .as_deref()
        .map(str::trim)
        .filter(|s| !s.is_empty())
        .or_else(|| {
            q.min_id
                .as_deref()
                .map(str::trim)
                .filter(|s| !s.is_empty())
        });
    match crate::timeline::home_hydrate(&state.cfg, owner, limit, since).await {
        Ok(report) if report.cache_hit => {
            let mut resp = (StatusCode::OK, Json(report.items)).into_response();
            if let Some(link) = report.link {
                if let Ok(val) = axum::http::HeaderValue::from_str(&link) {
                    resp.headers_mut().insert(axum::http::header::LINK, val);
                }
            }
            resp.headers_mut().insert(
                axum::http::HeaderName::from_static("x-vaak-tl-cache"),
                axum::http::HeaderValue::from_static("axum-shadow"),
            );
            resp
        }
        Ok(report) => (
            StatusCode::NOT_FOUND,
            Json(serde_json::json!({
                "error": "home hydrate cache miss",
                "redis_key": report.redis_key,
                "note": report.note,
                "mode": "shadow",
            })),
        )
            .into_response(),
        Err(e) => (
            StatusCode::INTERNAL_SERVER_ERROR,
            Json(serde_json::json!({"error": e.to_string()})),
        )
            .into_response(),
    }
}

fn parse_types(raw: Option<&str>) -> Vec<String> {
    let Some(raw) = raw.map(str::trim).filter(|s| !s.is_empty()) else {
        return vec![];
    };
    raw.split(',')
        .map(str::trim)
        .filter(|s| !s.is_empty())
        .map(str::to_ascii_lowercase)
        .collect()
}

async fn shadow_notifications(
    State(state): State<AppState>,
    Query(q): Query<OwnerQuery>,
) -> impl IntoResponse {
    let owner = q.owner_id.filter(|v| *v > 0).unwrap_or(state.cfg.default_owner_id);
    let limit = q.limit.unwrap_or(30);
    let types = parse_types(q.types.as_deref());
    json_result(
        crate::notif_list::notifications_shadow(
            &state.cfg,
            owner,
            limit,
            &types,
            q.max_id.as_deref(),
            q.since_id.as_deref(),
        )
        .await,
    )
}

/// Mastodon-shaped body: bare notification array when cache hits (Ice Cubes-friendly).
async fn shadow_notifications_masto(
    State(state): State<AppState>,
    Query(q): Query<OwnerQuery>,
) -> impl IntoResponse {
    let owner = q.owner_id.filter(|v| *v > 0).unwrap_or(state.cfg.default_owner_id);
    let limit = q.limit.unwrap_or(30);
    let types = parse_types(q.types.as_deref());
    match crate::notif_list::notifications_shadow(
        &state.cfg,
        owner,
        limit,
        &types,
        q.max_id.as_deref(),
        q.since_id.as_deref(),
    )
    .await
    {
        Ok(report) if report.cache_hit => (StatusCode::OK, Json(report.items)).into_response(),
        Ok(report) => (
            StatusCode::NOT_FOUND,
            Json(serde_json::json!({
                "error": "notif list cache miss",
                "redis_key": report.redis_key,
                "note": report.note,
                "mode": "shadow",
            })),
        )
            .into_response(),
        Err(e) => (
            StatusCode::INTERNAL_SERVER_ERROR,
            Json(serde_json::json!({"error": e.to_string()})),
        )
            .into_response(),
    }
}
