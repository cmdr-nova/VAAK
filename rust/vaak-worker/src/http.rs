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
        .route("/shadow/ranked-newer", get(shadow_ranked))
        .route("/shadow/action-queue", get(shadow_action_queue))
        .route("/shadow/jetstream", get(shadow_jetstream))
        .route("/shadow/thin-media", get(shadow_thin_media))
        .route("/shadow/timelines/home", get(shadow_home))
        // Mastodon-shaped path alias (still shadow JSON, not a live cutover).
        .route("/api/v1/timelines/home", get(shadow_home))
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
            "/shadow/ranked-newer",
            "/shadow/action-queue",
            "/shadow/jetstream",
            "/shadow/thin-media",
            "/shadow/timelines/home",
            "/api/v1/timelines/home"
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
