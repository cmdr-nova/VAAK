//! Disabled server-to-server Search presentation batch. PHP owns collection and cursors.
use axum::{body::Bytes,http::{HeaderMap,StatusCode},response::{IntoResponse,Response}};
use serde::{Deserialize,Serialize};
use serde_json::{json,Value};
use std::{collections::HashSet,sync::OnceLock};

const MAX_BYTES:usize=2*1024*1024;
const MAX_REPLY:usize=8*1024*1024;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Batch {
    protocol_version:u8,
    status_preview_budget:i64,
    #[serde(default)] bsky_budget_deadline:Option<f64>,
    owner_id:i64,
    csrf:String,
    bsky_enabled:bool,
    accounts:Vec<Value>,
    hashtags:Vec<Value>,
    statuses:Vec<Value>,
    #[serde(default)] bsky_bookmarks:Vec<BookmarkState>,
    #[serde(default)] collector:Option<crate::search_context::selection::SearchCollectorDescriptor>,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct BookmarkState {uri:String,did:String,bookmarked:bool}
#[derive(Serialize)]
struct SourceCounts {accounts:usize,hashtags:usize,statuses:usize}
#[derive(Serialize)]
struct StatusCard {source_index:usize,html:String}
#[derive(Serialize)]
struct Reply {protocol_version:u8,status_preview_state:crate::direct_preview::PreviewState,ok:bool,source_counts:SourceCounts,accounts:Vec<String>,hashtags:Vec<String>,statuses:Vec<StatusCard>}

fn enabled_secret()->Option<String>{
    if std::env::var("VAAK_SEARCH_NATIVE_RENDER").ok().as_deref()!=Some("1"){return None}
    std::env::var("VAAK_SEARCH_NATIVE_SECRET").ok().filter(|value|value.len()>=32)
}
pub(crate) fn enabled()->bool{enabled_secret().is_some()}
fn authorized(headers:&HeaderMap,expected:&str)->bool{
    let supplied=headers.get("x-vaak-search-token").and_then(|value|value.to_str().ok()).unwrap_or("");
    supplied.len()==expected.len()&&supplied.bytes().zip(expected.bytes()).fold(0u8,|diff,(a,b)|diff|(a^b))==0
}
fn represented_uris(value:&Value,uris:&mut HashSet<String>){
    match value{
        Value::Object(map)=>{if let Some(uri)=map.get("uri").and_then(Value::as_str){uris.insert(uri.into());}for child in map.values(){represented_uris(child,uris)}},
        Value::Array(values)=>for child in values{represented_uris(child,uris)},
        _=>{},
    }
}
fn decode(bytes:&[u8])->anyhow::Result<Batch>{
    if bytes.len()>MAX_BYTES{anyhow::bail!("Search body limit")}
    let batch:Batch=serde_json::from_slice(bytes)?;
    if batch.protocol_version!=1||batch.owner_id<1||batch.csrf.is_empty()||batch.csrf.len()>4096||batch.accounts.len()>40||batch.hashtags.len()>40||batch.statuses.len()>40||batch.accounts.len()+batch.hashtags.len()+batch.statuses.len()>120||batch.bsky_bookmarks.len()>121{anyhow::bail!("invalid Search session or source counts")}
    if batch.accounts.iter().chain(&batch.hashtags).chain(&batch.statuses).any(|value|!value.is_object()){anyhow::bail!("invalid Search source object")}
    let mut represented=HashSet::new();for status in &batch.statuses{represented_uris(status,&mut represented)}
    static POST:OnceLock<regex::Regex>=OnceLock::new();let post=POST.get_or_init(||regex::Regex::new(r"^at://did:[^\s/?#]+/app\.bsky\.feed\.post/[^\s/?#]+$").unwrap());
    let mut seen=HashSet::new();
    for state in &batch.bsky_bookmarks{if state.uri.len()>2048||state.did.len()>2048||state.did.is_empty()||!post.is_match(&state.uri)||!represented.contains(&state.uri)||!seen.insert(&state.uri){anyhow::bail!("invalid represented Search bookmark provenance")}}
    Ok(batch)
}

async fn paint(db:&tokio_postgres::Client,batch:Batch,deps:&crate::direct_preview::PreviewDependencies<'_>)->anyhow::Result<Reply>{
    paint_with_warm(db,batch,deps,None).await
}
async fn paint_with_warm(db:&tokio_postgres::Client,batch:Batch,deps:&crate::direct_preview::PreviewDependencies<'_>,warm:Option<std::sync::Arc<crate::profile_enrichment::WarmContext>>)->anyhow::Result<Reply>{
    let identity=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&batch.owner_id]).await?.ok_or_else(||anyhow::anyhow!("inactive Search bridge owner"))?;
    let actor:String=identity.try_get(0)?;let key:String=identity.try_get(1)?;
    let source_counts=SourceCounts{accounts:batch.accounts.len(),hashtags:batch.hashtags.len(),statuses:batch.statuses.len()};
    let states=batch.bsky_bookmarks.into_iter().map(|value|(value.uri,value.did,value.bookmarked)).collect();
    let session=crate::search_context::SearchSession::from_php_session(batch.owner_id,batch.csrf,batch.bsky_enabled)?.with_bridge_identity(actor,key).with_native_bookmarks(db,states).await?.with_collector(db,batch.collector,&batch.statuses).await?.with_warm_context(warm);
    // One bounded resolver per account section, including an empty section: it also
    // rechecks the current owner when no status card is present.
    let components=crate::search_components::ComponentContext::load(db,Some(batch.owner_id),&batch.accounts).await?;
    let accounts=batch.accounts.iter().map(|account|components.resolve_account(account).map(|token|crate::search_render::render_account(&token))).collect::<anyhow::Result<Vec<_>>>()?;
    let hashtags=batch.hashtags.iter().map(|tag|components.resolve_tag(tag).map(|token|crate::search_render::render_tag(&token))).collect::<anyhow::Result<Vec<_>>>()?;
    let (mut admitted,remaining)=crate::search_context::admit_for_render_with_preview(db,&session,batch.statuses,batch.status_preview_budget,deps.dns).await?;
    #[cfg(test)] if let Some(clock)=deps.render_clock{for token in &mut admitted{token.set_render_clock(clock);}}
    let statuses=admitted.iter().map(|token|crate::library::card::render_search_admitted(token).map(|html|StatusCard{source_index:token.source_index(),html})).collect::<anyhow::Result<Vec<_>>>()?;
    // The session binds the original bridge identity into context final checks;
    // painting and serialization after its fresh complete window contain no await.
    Ok(Reply{protocol_version:1,status_preview_state:crate::direct_preview::PreviewState::from_remaining(batch.status_preview_budget,remaining,source_counts.statuses)?,ok:true,source_counts,accounts,hashtags,statuses})
}

pub(crate) async fn render(cfg:&crate::config::Config,headers:HeaderMap,bytes:Bytes)->Response{
    render_request_with_warm(&cfg.database_url,enabled_secret().as_deref(),headers,bytes,&crate::direct_preview::PreviewDependencies::production(),None,Some(cfg)).await
}
async fn render_request(database_url:&str,secret:Option<&str>,headers:HeaderMap,bytes:Bytes)->Response{
    render_request_with_dependencies(database_url,secret,headers,bytes,&crate::direct_preview::PreviewDependencies::production()).await
}
async fn render_request_with_dependencies(database_url:&str,secret:Option<&str>,headers:HeaderMap,bytes:Bytes,deps:&crate::direct_preview::PreviewDependencies<'_>)->Response{
    render_request_with_warm(database_url,secret,headers,bytes,deps,None,None).await
}
async fn render_request_with_warm(database_url:&str,secret:Option<&str>,headers:HeaderMap,bytes:Bytes,deps:&crate::direct_preview::PreviewDependencies<'_>,warm:Option<std::sync::Arc<crate::profile_enrichment::WarmContext>>,cfg:Option<&crate::config::Config>)->Response{
    let mut response=render_request_with_warm_inner(database_url,secret,headers,bytes,deps,warm,cfg).await;
    response.headers_mut().insert(axum::http::header::CACHE_CONTROL,axum::http::HeaderValue::from_static("private, no-store"));response
}
async fn render_request_with_warm_inner(database_url:&str,secret:Option<&str>,headers:HeaderMap,bytes:Bytes,deps:&crate::direct_preview::PreviewDependencies<'_>,warm:Option<std::sync::Arc<crate::profile_enrichment::WarmContext>>,cfg:Option<&crate::config::Config>)->Response{
    let Some(secret)=secret else{return StatusCode::NOT_FOUND.into_response()};
    if !authorized(&headers,secret){return StatusCode::UNAUTHORIZED.into_response()}
    let batch=match decode(&bytes){Ok(batch)=>batch,Err(_)=>return (StatusCode::BAD_REQUEST,axum::Json(json!({"fallback":true,"error":"invalid batch"}))).into_response()};
    if batch.bsky_budget_deadline.is_some_and(|value|!value.is_finite()){return StatusCode::BAD_REQUEST.into_response()}
    if cfg.is_some()&&!batch.statuses.is_empty()&&batch.collector.is_none(){return (StatusCode::UNPROCESSABLE_ENTITY,axum::Json(json!({"fallback":true}))).into_response()}
    let warm=warm.or_else(||cfg.map(|cfg|crate::library::bridge::native_warm(cfg,batch.bsky_budget_deadline)));
    let result: anyhow::Result<Vec<u8>>=async{
        let db=crate::db::connect(database_url).await?;
        let reply=paint_with_warm(&db,batch,deps,warm).await?;
        let bytes=serde_json::to_vec(&reply)?;
        if bytes.len()>MAX_REPLY{anyhow::bail!("Search reply limit")}
        Ok(bytes)
    }.await;
    match result{
        Ok(bytes)=>(StatusCode::OK,[(axum::http::header::CONTENT_TYPE,"application/json")],bytes).into_response(),
        Err(_error)=>{#[cfg(test)]eprintln!("Search fixture fallback: {_error:#}");tracing::debug!("Search whole-batch PHP fallback");(StatusCode::UNPROCESSABLE_ENTITY,axum::Json(json!({"fallback":true}))).into_response()},
    }
}

#[cfg(test)]
pub(crate) async fn fixture_request(database_url:&str,secret:&str,headers:HeaderMap,bytes:Bytes,deps:&crate::direct_preview::PreviewDependencies<'_>)->Response {
    render_request_with_dependencies(database_url,Some(secret),headers,bytes,deps).await
}
#[cfg(test)]
pub(crate) async fn fixture_request_with_warm(database_url:&str,secret:&str,headers:HeaderMap,bytes:Bytes,deps:&crate::direct_preview::PreviewDependencies<'_>,warm:std::sync::Arc<crate::profile_enrichment::WarmContext>)->Response {
    render_request_with_warm(database_url,Some(secret),headers,bytes,deps,Some(warm),None).await
}

#[cfg(test)] mod tests{
    use super::*;
    fn batch()->Value{json!({"protocol_version":1,"status_preview_budget":3,"owner_id":1,"csrf":"canonical-session","bsky_enabled":false,"accounts":[],"hashtags":[],"statuses":[]})}
    #[test] fn strict_sources_session_and_utf8(){
        let mut value=batch();assert!(decode(value.to_string().as_bytes()).is_ok());
        for name in ["return_view","lazy_media","is_me"]{value[name]=json!(true);assert!(decode(value.to_string().as_bytes()).is_err());value.as_object_mut().unwrap().remove(name);}
        for field in ["accounts","hashtags","statuses"]{value[field]=json!(vec![json!({});40]);assert!(decode(value.to_string().as_bytes()).is_ok());value[field]=json!(vec![json!({});41]);assert!(decode(value.to_string().as_bytes()).is_err());value[field]=json!([null]);assert!(decode(value.to_string().as_bytes()).is_err());value[field]=json!([]);}
        value["owner_id"]=json!("1");assert!(decode(value.to_string().as_bytes()).is_err());value["owner_id"]=json!(0);assert!(decode(value.to_string().as_bytes()).is_err());
        assert!(decode(&[0xff]).is_err());assert!(decode(&vec![b' ';MAX_BYTES+1]).is_err());
    }
    #[test] fn recursive_provenance_is_exact_and_unique(){
        let uri="at://did:plc:author/app.bsky.feed.post/key";let mut value=batch();value["statuses"]=json!([{"quote":{"quoted_status":{"bsky_post":{"post":{"uri":uri}}}}}]);value["bsky_bookmarks"]=json!([{"uri":uri,"did":"did:plc:viewer","bookmarked":false}]);assert!(decode(value.to_string().as_bytes()).is_ok());
        for bad in ["at://did:/app.bsky.feed.post/key","at://did:plc:author/app.bsky.feed.post/key?q=1","at://did:plc:author/app.bsky.feed.post/other"]{value["bsky_bookmarks"][0]["uri"]=json!(bad);assert!(decode(value.to_string().as_bytes()).is_err());}value["bsky_bookmarks"][0]["uri"]=json!(uri);value["bsky_bookmarks"]=json!(vec![value["bsky_bookmarks"][0].clone();2]);assert!(decode(value.to_string().as_bytes()).is_err());value["bsky_bookmarks"]=json!(vec![json!({});122]);assert!(decode(value.to_string().as_bytes()).is_err());
    }
    #[tokio::test] async fn actual_http_disabled_header_decode_and_body_limits(){
        async fn handler(headers:HeaderMap,bytes:Bytes)->Response{render_request("invalid-fixture-database",Some("search-only-secret"),headers,bytes).await}
        async fn disabled(headers:HeaderMap,bytes:Bytes)->Response{render_request("invalid-fixture-database",None,headers,bytes).await}
        let app=axum::Router::new().route("/internal/search/render",axum::routing::post(handler)).route("/disabled",axum::routing::post(disabled)).layer(axum::extract::DefaultBodyLimit::max(MAX_BYTES));
        let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();let address=listener.local_addr().unwrap();let server=tokio::spawn(async move{axum::serve(listener,app).await.unwrap()});
        let client=reqwest::Client::builder().no_proxy().build().unwrap();let url=format!("http://{address}/internal/search/render");
        assert_eq!(client.post(format!("http://{address}/disabled")).send().await.unwrap().status(),404);
        for header in ["authorization","x-vaak-library-token"]{assert_eq!(client.post(&url).header(header,"search-only-secret").body("{}").send().await.unwrap().status(),401);}
        assert_eq!(client.post(&url).header("x-vaak-search-token","search-only-secreT").body("{}").send().await.unwrap().status(),401);
        assert_eq!(client.post(&url).header("x-vaak-search-token","search-only-secret").body("{}").send().await.unwrap().status(),400);
        assert_eq!(client.post(&url).header("x-vaak-search-token","search-only-secret").body(vec![b' ';MAX_BYTES+1]).send().await.unwrap().status(),413);
        let response=client.post(&url).header("x-vaak-search-token","search-only-secret").json(&batch()).send().await.unwrap();assert_eq!(response.status(),422);assert_eq!(response.json::<Value>().await.unwrap(),json!({"fallback":true}));server.abort();
    }
}
