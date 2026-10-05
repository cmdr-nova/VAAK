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
    /// Absolute unix seconds cursor for Home live-poll (`/api/v1/timelines/home/since`).
    pub since_ts: Option<i64>,
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
    /// Mentions nested-card HTML fragment (`/shadow/notif-embed`).
    pub uri: Option<String>,
    pub status_id: Option<String>,
    pub hide_header: Option<String>,
    pub favourited: Option<String>,
    pub reblogged: Option<String>,
    pub bookmarked: Option<String>,
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
        // Mentions nested status HTML fragments (PHP writes `vaak:notif-embed:v1:*`).
        .route("/shadow/notif-embed", get(shadow_notif_embed))
        // Mentions fill HTML from warm Redis list (0.7.1).
        .route("/shadow/mentions-html", get(shadow_mentions_html))
        // Home fill HTML from hydrate Redis (0.7.4).
        .route("/shadow/home-html", get(shadow_home_html))
        // Mastodon-shaped Home: hydrated status JSON from vaak:timeline:v1 (slice 4).
        .route("/api/v1/timelines/home", get(shadow_home_masto))
        // Home live-poll: ranked head + hydrate filtered by since_ts (0.6.74).
        .route("/api/v1/timelines/home/since", get(shadow_home_since))
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
            "/shadow/notif-embed",
            "/shadow/mentions-html",
            "/shadow/home-html",
            "/api/v1/timelines/home",
            "/api/v1/timelines/home/since",
            "/api/v1/notifications"
        ],
    }))
}

fn embed_html_response(html: String, source: &'static str) -> axum::response::Response {
    let mut headers = axum::http::HeaderMap::new();
    headers.insert(
        axum::http::header::CONTENT_TYPE,
        axum::http::HeaderValue::from_static("text/html; charset=utf-8"),
    );
    headers.insert(
        axum::http::header::CACHE_CONTROL,
        axum::http::HeaderValue::from_static("no-store"),
    );
    headers.insert(
        axum::http::HeaderName::from_static("x-vaak-embed-source"),
        axum::http::HeaderValue::from_static(source),
    );
    (StatusCode::OK, headers, html).into_response()
}

/// Serve Mentions nested-card HTML from Redis (`vaak:notif-embed:v1:*`).
/// Key parity with PHP `admin_notif_try_embed_status_card` (0.6.89).
/// On miss (0.6.91): resolve status from Mentions envelopes and lean-paint in Rust.
async fn shadow_notif_embed(
    State(state): State<AppState>,
    Query(q): Query<OwnerQuery>,
) -> impl IntoResponse {
    let owner = q.owner_id.filter(|v| *v > 0).unwrap_or(state.cfg.default_owner_id);
    let uri = q
        .uri
        .as_deref()
        .map(str::trim)
        .unwrap_or("")
        .trim_end_matches('/')
        .to_string();
    if uri.is_empty() {
        return (
            StatusCode::BAD_REQUEST,
            Json(serde_json::json!({"error": "uri required"})),
        )
            .into_response();
    }
    let hide = truthy(q.hide_header.as_deref());
    let fav = truthy(q.favourited.as_deref());
    let reblog = truthy(q.reblogged.as_deref());
    let bookmarked = truthy(q.bookmarked.as_deref());
    let sid = q.status_id.as_deref().unwrap_or("").trim();
    let key = crate::notif_embed::frag_key(owner, &uri, hide, fav, reblog, bookmarked, sid);

    let mut redis = match crate::redis_util::connect(&state.cfg.redis_url).await {
        Ok(r) => r,
        Err(e) => {
            return (
                StatusCode::INTERNAL_SERVER_ERROR,
                Json(serde_json::json!({"error": e.to_string()})),
            )
                .into_response();
        }
    };

    match redis_util_get_string(&mut redis, &key).await {
        Ok(Some(html)) if !html.is_empty() => return embed_html_response(html, "redis"),
        Ok(_) => {}
        Err(e) => {
            return (
                StatusCode::INTERNAL_SERVER_ERROR,
                Json(serde_json::json!({"error": e.to_string()})),
            )
                .into_response();
        }
    }

    // Miss → lean paint from Mentions envelope status JSON.
    let status = match crate::notif_embed::find_status_in_notif_envelopes(&mut redis, owner, &uri)
        .await
    {
        Ok(v) => v,
        Err(e) => {
            return (
                StatusCode::INTERNAL_SERVER_ERROR,
                Json(serde_json::json!({"error": e.to_string()})),
            )
                .into_response();
        }
    };
    let Some(status) = status else {
        return (
            StatusCode::NOT_FOUND,
            Json(serde_json::json!({
                "error": "notif embed cache miss",
                "key": key,
                "source": "vaak-worker-shadow"
            })),
        )
            .into_response();
    };

    let html = crate::notif_embed::paint_lean_embed(&status, hide);
    if html.is_empty() {
        return (
            StatusCode::NOT_FOUND,
            Json(serde_json::json!({
                "error": "notif embed paint empty",
                "key": key,
                "source": "vaak-worker-paint"
            })),
        )
            .into_response();
    }
    let _ = crate::notif_embed::set_embed_html(&mut redis, &key, &html).await;
    embed_html_response(html, "paint")
}

/// Home fill HTML (0.7.4). Cache miss → 404 so PHP keeps card paint.
async fn shadow_home_html(
    State(state): State<AppState>,
    Query(q): Query<OwnerQuery>,
) -> impl IntoResponse {
    let owner = q.owner_id.filter(|v| *v > 0).unwrap_or(state.cfg.default_owner_id);
    let limit = q.limit.unwrap_or(15).clamp(1, 40);
    match crate::home_html::home_html_fill(&state.cfg, owner, limit).await {
        Ok(Some(report)) => {
            let mut headers = axum::http::HeaderMap::new();
            headers.insert(
                axum::http::header::CONTENT_TYPE,
                axum::http::HeaderValue::from_static("text/html; charset=utf-8"),
            );
            headers.insert(
                axum::http::header::CACHE_CONTROL,
                axum::http::HeaderValue::from_static("no-store"),
            );
            headers.insert(
                axum::http::HeaderName::from_static("x-has-more"),
                axum::http::HeaderValue::from_static(if report.has_more { "1" } else { "0" }),
            );
            if let Ok(v) = axum::http::HeaderValue::from_str(&report.next_offset.to_string()) {
                headers.insert(axum::http::HeaderName::from_static("x-next-offset"), v);
            }
            if let Ok(v) = axum::http::HeaderValue::from_str(&report.source) {
                headers.insert(axum::http::HeaderName::from_static("x-vaak-tl-source"), v);
            }
            headers.insert(
                axum::http::HeaderName::from_static("x-vaak-home-html"),
                axum::http::HeaderValue::from_static("1"),
            );
            headers.insert(
                axum::http::HeaderName::from_static("x-tl-cache"),
                axum::http::HeaderValue::from_static("axum-home-html"),
            );
            if let Ok(v) = axum::http::HeaderValue::from_str(&report.count.to_string()) {
                headers.insert(axum::http::HeaderName::from_static("x-vaak-tl-count"), v);
            }
            if let Ok(v) = axum::http::HeaderValue::from_str(&report.hydrate_key) {
                headers.insert(axum::http::HeaderName::from_static("x-vaak-tl-key"), v);
            }
            (StatusCode::OK, headers, report.html).into_response()
        }
        Ok(None) => (
            StatusCode::NOT_FOUND,
            Json(serde_json::json!({
                "error": "home html cache miss",
                "source": "vaak-worker-shadow"
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

/// Mentions fill HTML (0.7.1). Cache miss → 404 so PHP keeps the old paint path.
async fn shadow_mentions_html(
    State(state): State<AppState>,
    Query(q): Query<OwnerQuery>,
) -> impl IntoResponse {
    let owner = q.owner_id.filter(|v| *v > 0).unwrap_or(state.cfg.default_owner_id);
    let limit = q.limit.unwrap_or(12).clamp(1, 60);
    let types = parse_types(q.types.as_deref());
    match crate::mentions_html::mentions_html_fill(
        &state.cfg,
        owner,
        limit,
        &types,
        q.max_id.as_deref(),
    )
    .await
    {
        Ok(Some(report)) => {
            let mut headers = axum::http::HeaderMap::new();
            headers.insert(
                axum::http::header::CONTENT_TYPE,
                axum::http::HeaderValue::from_static("text/html; charset=utf-8"),
            );
            headers.insert(
                axum::http::header::CACHE_CONTROL,
                axum::http::HeaderValue::from_static("no-store"),
            );
            headers.insert(
                axum::http::HeaderName::from_static("x-has-more"),
                axum::http::HeaderValue::from_static(if report.has_more { "1" } else { "0" }),
            );
            if let Ok(v) = axum::http::HeaderValue::from_str(&report.next_max_id) {
                headers.insert(axum::http::HeaderName::from_static("x-next-max-id"), v);
            }
            if let Ok(v) = axum::http::HeaderValue::from_str(&report.tip_id) {
                headers.insert(axum::http::HeaderName::from_static("x-notif-tip-id"), v);
            }
            if let Ok(v) = axum::http::HeaderValue::from_str(&report.source) {
                headers.insert(axum::http::HeaderName::from_static("x-vaak-notif-source"), v);
            }
            headers.insert(
                axum::http::HeaderName::from_static("x-vaak-mentions-html"),
                axum::http::HeaderValue::from_static("1"),
            );
            if let Ok(v) = axum::http::HeaderValue::from_str(&report.count.to_string()) {
                headers.insert(axum::http::HeaderName::from_static("x-vaak-notif-count"), v);
            }
            if let Ok(v) = axum::http::HeaderValue::from_str(&report.redis_key) {
                headers.insert(axum::http::HeaderName::from_static("x-vaak-notif-key"), v);
            }
            (StatusCode::OK, headers, report.html).into_response()
        }
        Ok(None) => (
            StatusCode::NOT_FOUND,
            Json(serde_json::json!({
                "error": "mentions html cache miss",
                "source": "vaak-worker-shadow"
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

async fn redis_util_get_string(
    redis: &mut redis::aio::MultiplexedConnection,
    key: &str,
) -> anyhow::Result<Option<String>> {
    use redis::AsyncCommands;
    let v: Option<String> = redis.get(key).await?;
    Ok(v)
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

/// Home live-poll: statuses newer than `since_ts` from ranked + hydrate (404 on miss).
///
/// 200 + `[]` means confirmed empty (warm caches, nothing newer) — PHP must not
/// fall through to the multi-chunk PG `admin_tl_fetch_newer` path.
async fn shadow_home_since(
    State(state): State<AppState>,
    Query(q): Query<OwnerQuery>,
) -> impl IntoResponse {
    let owner = q.owner_id.filter(|v| *v > 0).unwrap_or(state.cfg.default_owner_id);
    let limit = q.limit.unwrap_or(20).clamp(1, 40) as usize;
    let since_ts = q.since_ts.unwrap_or(0);
    if since_ts <= 0 {
        return (
            StatusCode::BAD_REQUEST,
            Json(serde_json::json!({
                "error": "since_ts required",
                "mode": "shadow",
            })),
        )
            .into_response();
    }
    match crate::timeline::home_since(&state.cfg, owner, since_ts, limit).await {
        Ok(report) if report.cache_hit => {
            let mut resp = (StatusCode::OK, Json(report.items)).into_response();
            resp.headers_mut().insert(
                axum::http::HeaderName::from_static("x-vaak-tl-cache"),
                axum::http::HeaderValue::from_static("axum-ranked-since"),
            );
            if let Ok(val) = axum::http::HeaderValue::from_str(&report.newest_ts.to_string()) {
                resp.headers_mut().insert(
                    axum::http::HeaderName::from_static("x-newest"),
                    val,
                );
            }
            if let Ok(val) = axum::http::HeaderValue::from_str(&report.n.to_string()) {
                resp.headers_mut().insert(
                    axum::http::HeaderName::from_static("x-new-count"),
                    val,
                );
            }
            resp
        }
        Ok(report) => (
            StatusCode::NOT_FOUND,
            Json(serde_json::json!({
                "error": "home since cache miss",
                "ranked_ok": report.ranked_ok,
                "hydrate_ok": report.hydrate_ok,
                "ranked_key": report.ranked_key,
                "hydrate_key": report.hydrate_key,
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
