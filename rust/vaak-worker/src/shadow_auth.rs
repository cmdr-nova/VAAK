//! Shared-secret gate for the loopback shadow read routes (`/shadow/*`,
//! `/api/v1/*`). Loopback binding alone does not stop other local processes
//! from reading per-owner data, so PHP sends `X-VAAK-Shadow-Token`.
//!
//! Resolution must match `api/ap-shadow-http.php`:
//!   1. `VAAK_SHADOW_HTTP_TOKEN` (32+ chars) when set;
//!   2. otherwise a value derived from `VAAK_SECRET`;
//!   3. otherwise none, and the gated routes answer 503 (`/healthz` stays open).

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;

use axum::extract::{Request, State};
use axum::http::{HeaderMap, StatusCode};
use axum::middleware::Next;
use axum::response::{IntoResponse, Response};
use sha2::{Digest, Sha256};

pub(crate) const HEADER: &str = "x-vaak-shadow-token";

static UNCONFIGURED_LOGGED: AtomicBool = AtomicBool::new(false);

/// Pure resolution step (see module docs).
pub(crate) fn token_from(explicit: Option<&str>, secret: Option<&str>) -> Option<String> {
    let explicit = explicit.map(str::trim).unwrap_or("");
    if !explicit.is_empty() {
        // A short explicit token is a misconfiguration; do not fall back.
        return (explicit.len() >= 32).then(|| explicit.to_string());
    }
    let secret = secret.map(str::trim).unwrap_or("");
    if secret.is_empty() {
        return None;
    }
    let mut h = Sha256::new();
    h.update(b"vaak-shadow-http\0");
    h.update(secret.as_bytes());
    Some(hex::encode(h.finalize()))
}

/// Reads the process environment (already seeded from `VAAK_ENV_FILE`).
pub(crate) fn resolve() -> Option<Arc<str>> {
    let explicit = std::env::var("VAAK_SHADOW_HTTP_TOKEN").ok();
    let secret = std::env::var("VAAK_SECRET").ok();
    token_from(explicit.as_deref(), secret.as_deref()).map(Arc::from)
}

pub(crate) fn ct_eq(a: &str, b: &str) -> bool {
    a.len() == b.len() && a.bytes().zip(b.bytes()).fold(0u8, |d, (x, y)| d | (x ^ y)) == 0
}

pub(crate) fn authorized(headers: &HeaderMap, expected: &str) -> bool {
    let supplied = headers.get(HEADER).and_then(|v| v.to_str().ok()).unwrap_or("");
    ct_eq(supplied, expected)
}

pub(crate) async fn require(
    State(token): State<Option<Arc<str>>>,
    req: Request,
    next: Next,
) -> Response {
    let Some(expected) = token.as_deref() else {
        if !UNCONFIGURED_LOGGED.swap(true, Ordering::Relaxed) {
            tracing::warn!(
                "shadow HTTP token not configured (set VAAK_SHADOW_HTTP_TOKEN or VAAK_SECRET); refusing /shadow/* and /api/v1/*"
            );
        }
        return (StatusCode::SERVICE_UNAVAILABLE, "shadow token not configured").into_response();
    };
    if !authorized(req.headers(), expected) {
        return StatusCode::UNAUTHORIZED.into_response();
    }
    next.run(req).await
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::routing::get;
    use axum::Router;

    #[test]
    fn explicit_token_wins_and_must_be_long() {
        let long = "a".repeat(32);
        assert_eq!(token_from(Some(&long), Some("s")).as_deref(), Some(long.as_str()));
        assert_eq!(token_from(Some("short"), Some("s")), None);
        assert_eq!(token_from(Some("  "), None), None);
        assert_eq!(token_from(None, Some("")), None);
    }

    #[test]
    fn derived_token_matches_php() {
        // php -r 'echo hash("sha256", "vaak-shadow-http\0" . "test-secret");'
        assert_eq!(
            token_from(None, Some("test-secret")).as_deref(),
            Some("2727b4d62885db40784c45aef7619d611d602d58f1f9b00ba50129dcaad8b2fd")
        );
        assert_ne!(token_from(None, Some("test-secret")), token_from(None, Some("other-secret")));
    }

    #[test]
    fn constant_time_compare() {
        assert!(ct_eq("abc", "abc"));
        assert!(!ct_eq("abc", "abd"));
        assert!(!ct_eq("abc", "abcd"));
        assert!(!ct_eq("", "x"));
    }

    async fn serve(token: Option<Arc<str>>) -> String {
        let gated = Router::new()
            .route("/shadow/x", get(|| async { "secret" }))
            .route_layer(axum::middleware::from_fn_with_state(token, require));
        let app = Router::new().route("/healthz", get(|| async { "ok" })).merge(gated);
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move { axum::serve(listener, app).await.unwrap() });
        format!("http://{addr}")
    }

    #[tokio::test]
    async fn gate_requires_matching_header() {
        let token = "t".repeat(40);
        let base = serve(Some(Arc::from(token.as_str()))).await;
        let c = reqwest::Client::new();
        assert_eq!(c.get(format!("{base}/healthz")).send().await.unwrap().status(), 200);
        assert_eq!(c.get(format!("{base}/shadow/x")).send().await.unwrap().status(), 401);
        let wrong = c.get(format!("{base}/shadow/x")).header(HEADER, "nope").send().await.unwrap();
        assert_eq!(wrong.status(), 401);
        let ok = c.get(format!("{base}/shadow/x")).header(HEADER, &token).send().await.unwrap();
        assert_eq!(ok.status(), 200);
        assert_eq!(ok.text().await.unwrap(), "secret");
    }

    #[tokio::test]
    async fn unconfigured_refuses_gated_routes_only() {
        let base = serve(None).await;
        let c = reqwest::Client::new();
        assert_eq!(c.get(format!("{base}/healthz")).send().await.unwrap().status(), 200);
        let r = c.get(format!("{base}/shadow/x")).header(HEADER, "anything").send().await.unwrap();
        assert_eq!(r.status(), 503);
    }
}
