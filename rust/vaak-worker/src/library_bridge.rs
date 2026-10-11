//! Inactive authenticated server-to-server batch painter. No browser endpoint.
use axum::{
    body::Bytes,
    http::{HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    Json,
};
use serde::Deserialize;
use serde_json::{json, Value};
use std::collections::HashSet;

const MAX_BYTES: usize = 2 * 1024 * 1024;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Batch {
    protocol_version: u8,
    status_preview_budget: i64,
    owner_id: i64,
    csrf: String,
    return_view: String,
    bsky_enabled: bool,
    // Older authenticated mixed-bookmark producers always deferred media.
    #[serde(default = "legacy_lazy_media")]
    lazy_media: bool,
    #[serde(default)]
    bsky_bookmarks: Vec<BookmarkState>,
    statuses: Vec<Value>,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct BookmarkState {
    uri: String,
    did: String,
    bookmarked: bool,
}

fn legacy_lazy_media() -> bool { true }

fn enabled_secret() -> Option<String> {
    if std::env::var("VAAK_LIBRARY_NATIVE_RENDER").ok().as_deref() != Some("1") {
        return None;
    }
    std::env::var("VAAK_LIBRARY_NATIVE_SECRET").ok().filter(|s| s.len() >= 32)
}

pub(crate) fn enabled() -> bool {
    enabled_secret().is_some()
}

fn authorize(headers: &HeaderMap, expected: &str) -> bool {
    // Equal length and all-byte comparison avoids early mismatch timing.
    let supplied = headers.get("x-vaak-library-token")
        .and_then(|v| v.to_str().ok()).unwrap_or("");
    supplied.len() == expected.len()
        && supplied.bytes().zip(expected.bytes()).fold(0u8, |d, (a, b)| d | (a ^ b)) == 0
}

fn represented_uris(value: &Value, output: &mut HashSet<String>) {
    match value {
        Value::Object(map) => {
            if let Some(uri) = map.get("uri").and_then(Value::as_str) {
                output.insert(uri.into());
            }
            for child in map.values() {
                represented_uris(child, output);
            }
        }
        Value::Array(list) => {
            for child in list {
                represented_uris(child, output);
            }
        }
        _ => {}
    }
}

fn decode(bytes: &[u8]) -> anyhow::Result<Batch> {
    if bytes.len() > MAX_BYTES {
        anyhow::bail!("batch body limit");
    }
    let batch: Batch = serde_json::from_slice(bytes)?;
    if batch.protocol_version!=1 || batch.bsky_bookmarks.len() > 100 || batch.statuses.len() > 100
        || batch.csrf.len() > 4096 || batch.csrf.is_empty() || batch.owner_id < 1
        || !matches!(batch.return_view.as_str(), "bookmarks" | "favourites") {
        anyhow::bail!("invalid canonical batch session/limit");
    }
    if batch.statuses.iter().any(|s| !s.is_object()) {
        anyhow::bail!("invalid status source");
    }
    let mut represented = HashSet::new();
    for status in &batch.statuses {
        represented_uris(status, &mut represented);
    }
    let exact = regex::Regex::new(r"^at://did:[^\s/?#]+/app\.bsky\.feed\.post/[^\s/?#]+$").unwrap();
    let mut seen = HashSet::new();
    for state in &batch.bsky_bookmarks {
        if state.uri.len() > 2048 || !exact.is_match(&state.uri)
            || !represented.contains(&state.uri) || !seen.insert(&state.uri) {
            anyhow::bail!("invalid represented bookmark source URI");
        }
    }
    Ok(batch)
}

pub(crate) async fn render(cfg: &crate::config::Config, headers: HeaderMap, bytes: Bytes) -> Response {
    let secret=enabled_secret();
    render_request(&cfg.database_url,secret.as_deref(),headers,bytes,&crate::direct_preview::PreviewDependencies::production()).await
}
async fn render_request(database_url:&str,secret:Option<&str>,headers:HeaderMap,bytes:Bytes,deps:&crate::direct_preview::PreviewDependencies<'_>)->Response {
    let Some(secret)=secret else{return StatusCode::NOT_FOUND.into_response()};
    if !authorize(&headers, &secret) {
        return StatusCode::UNAUTHORIZED.into_response();
    }
    let batch = match decode(&bytes) {
        Ok(batch) => batch,
        Err(_) => return (StatusCode::BAD_REQUEST,
            Json(json!({"fallback": true, "error": "invalid batch"}))).into_response(),
    };
    let result: anyhow::Result<Vec<u8>> = async {
        let source_count=batch.statuses.len();let initial=batch.status_preview_budget;
        let db = crate::db::connect(database_url).await?;
        let states = batch.bsky_bookmarks.into_iter().map(|s| (s.uri, s.did, s.bookmarked)).collect();
        let session = super::context::TrustedSession::from_php_session(
            batch.owner_id, batch.csrf, batch.return_view, batch.bsky_enabled,
        )?.with_lazy_media(batch.lazy_media).with_bsky_bookmarks(&db, states).await?;
        let (mut statuses,remaining)=super::context::admit_for_render_with_preview(&db,&session,batch.statuses,initial,deps.dns).await?;
        #[cfg(test)] if let Some(clock)=deps.render_clock{for token in &mut statuses{token.set_render_clock(clock);}}
        let cards=statuses.iter().map(|token|super::card::render_admitted(token).map(|html|json!({"source_index":token.source_index(),"html":html}))).collect::<anyhow::Result<Vec<_>>>()?;
        let state=crate::direct_preview::PreviewState::from_remaining(initial,remaining,source_count)?;
        let bytes=serde_json::to_vec(&json!({"ok":true,"protocol_version":1,"source_count":source_count,"cards":cards,"status_preview_state":state}))?;
        if bytes.len()>8*1024*1024{anyhow::bail!("Library reply limit")}
        Ok(bytes)
    }.await;
    match result {
        Ok(bytes) => (StatusCode::OK,[(axum::http::header::CONTENT_TYPE,"application/json")],bytes).into_response(),
        Err(_error) => {
            tracing::debug!("Library whole-batch PHP fallback");
            (StatusCode::UNPROCESSABLE_ENTITY, Json(json!({"fallback": true}))).into_response()
        }
    }
}

#[cfg(test)]
pub(crate) async fn fixture_request(database_url:&str,secret:&str,headers:HeaderMap,bytes:Bytes,deps:&crate::direct_preview::PreviewDependencies<'_>)->Response {
    render_request(database_url,Some(secret),headers,bytes,deps).await
}

#[cfg(test)] mod tests{
 use super::*;
 #[test]fn preview_protocol_and_scalar_budgets_are_mandatory(){
  let mut value=json!({"protocol_version":1,"status_preview_budget":-4,"owner_id":1,"csrf":"canonical","return_view":"bookmarks","bsky_enabled":false,"statuses":[]});
  assert_eq!(decode(value.to_string().as_bytes()).unwrap().status_preview_budget,-4);
  for key in ["protocol_version","status_preview_budget"]{let mut missing=value.clone();missing.as_object_mut().unwrap().remove(key);assert!(decode(missing.to_string().as_bytes()).is_err());}
  value["protocol_version"]=json!(2);assert!(decode(value.to_string().as_bytes()).is_err());value["protocol_version"]=json!(1);
  for invalid in [json!(null),json!("3"),json!(3.0)]{value["status_preview_budget"]=invalid;assert!(decode(value.to_string().as_bytes()).is_err());}
 }
 #[test]fn internal_header_only_and_constant_length(){let mut h=HeaderMap::new();assert!(!authorize(&h,"secret"));h.insert("authorization","secret".parse().unwrap());assert!(!authorize(&h,"secret"));h.insert("x-vaak-library-token","secret".parse().unwrap());assert!(authorize(&h,"secret"));assert!(!authorize(&h,"secreT"));}
 #[test]fn provenance_requires_exact_represented_post_uri(){
  let valid="at://did:plc:a/app.bsky.feed.post/key";
  let mut value=json!({"protocol_version":1,"status_preview_budget":3,"owner_id":1,"csrf":"canonical","return_view":"bookmarks","bsky_enabled":true,"statuses":[{"post":{"uri":valid}}],"bsky_bookmarks":[{"uri":valid,"did":"did:plc:viewer","bookmarked":true}]});
  assert!(decode(value.to_string().as_bytes()).is_ok());
  for bad in ["at://did:/app.bsky.feed.post/key","at://did:plc:a/app.bsky.feed.post/","at://did:plc:a/app.bsky.feed.post/key/extra","at://did:plc:a/app.bsky.feed.post/key?q=1","at://did:plc:a/app.bsky.feed.post/other"]{value["bsky_bookmarks"][0]["uri"]=json!(bad);assert!(decode(value.to_string().as_bytes()).is_err());}
 }
 #[test]fn caller_media_context_is_strict_and_legacy_compatible(){
  let mut value=json!({"protocol_version":1,"status_preview_budget":3,"owner_id":1,"csrf":"canonical","return_view":"favourites","bsky_enabled":false,"statuses":[]});
  assert!(decode(value.to_string().as_bytes()).unwrap().lazy_media);
  for setting in [true,false]{value["lazy_media"]=json!(setting);assert_eq!(decode(value.to_string().as_bytes()).unwrap().lazy_media,setting);}
  for malformed in [json!("false"),json!(0),json!(null)]{value["lazy_media"]=malformed;assert!(decode(value.to_string().as_bytes()).is_err());}
 }
 #[test]fn strict_session_and_body_limits(){let mut v=json!({"protocol_version":1,"status_preview_budget":3,"owner_id":1,"csrf":"canonical","return_view":"bookmarks","bsky_enabled":false,"statuses":[]});assert!(decode(v.to_string().as_bytes()).is_ok());v["is_admin"]=json!(true);assert!(decode(v.to_string().as_bytes()).is_err());v.as_object_mut().unwrap().remove("is_admin");v["owner_id"]=json!(0);assert!(decode(v.to_string().as_bytes()).is_err());assert!(decode(&vec![b' ';MAX_BYTES+1]).is_err());v["owner_id"]=json!(1);v["statuses"]=json!(vec![json!({});101]);assert!(decode(v.to_string().as_bytes()).is_err());}
}
