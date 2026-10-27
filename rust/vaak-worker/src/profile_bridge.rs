//! Private, default-off complete local profile page contract. Request fields
//! select a current SQL page; they never supply status JSON or source grants.
use anyhow::{bail,Result};
#[path="profile_full_bridge.rs"]
mod full;
pub(crate) use full::render_full;
#[cfg(test)] pub(crate) use full::fixture_request as fixture_full_request;
use axum::{body::Bytes,http::{HeaderMap,StatusCode},response::{IntoResponse,Response}};
use serde::{Deserialize,Serialize};
use serde_json::json;
const MAX_BYTES:usize=64*1024;const MAX_REPLY:usize=8*1024*1024;
tokio::task_local! { static PROFILE_DEADLINE: std::time::Instant; }
/// Production complete-profile requests carry a hard budget. Fixture and unit
/// callers leave the task-local unset, so existing tests stay unlimited.
pub(crate) fn ensure_budget()->Result<()>{
 match PROFILE_DEADLINE.try_with(|deadline| std::time::Instant::now()<*deadline) {
  Ok(true)|Err(_)=>Ok(()),
  Ok(false)=>bail!("profile_fallback_code=budget"),
 }
}
/// Production ceiling for one complete-profile request. A warmed 50-card paint
/// is a few hundred milliseconds, and the database work sits on top of that,
/// so 250ms refused real pages. `VAAK_PROFILE_COMPLETE_BUDGET_MS` overrides
/// this (clamped to 50–5000). The PHP caller timeout stays above the default.
fn complete_budget()->std::time::Duration{
 let ms=std::env::var("VAAK_PROFILE_COMPLETE_BUDGET_MS").ok().and_then(|raw|raw.trim().parse::<u64>().ok()).unwrap_or(1_000).clamp(50,5_000);
 std::time::Duration::from_millis(ms)
}
/// Static regexes compile on first use. Doing that inside `PROFILE_DEADLINE`
/// spends the budget on process warmup. Later requests reuse the same OnceLocks.
pub(crate) fn prewarm_complete_profile(){
 static ONCE:std::sync::OnceLock<()>=std::sync::OnceLock::new();
 ONCE.get_or_init(||{
  crate::profile_raw::prewarm_static_regexes();
  crate::profile_context::prewarm_complete_profile_regexes();
  crate::library::facts::prewarm_profile_regexes();
  crate::profile_header::prewarm_static_regexes();
 });
}
pub(crate) async fn with_production_budget<T>(fut:impl std::future::Future<Output=T>)->T{
 PROFILE_DEADLINE.scope(std::time::Instant::now()+complete_budget(),fut).await
}
/// Stable reason code for the 422 body and the worker log. The error Display
/// is not logged: it can carry SQL or post text.
pub(crate) fn fallback_reason(err:&anyhow::Error)->&'static str{
 let text=format!("{err:#}");
 if text.contains("profile_fallback_code=budget"){"budget"}
 else if text.contains("associative quote tag"){"quote_tag_order"}
 else if text.contains("raw Bluesky quote"){"bluesky_quote"}
 else if text.contains("remote lookup candidate"){"remote_quote"}
 else if text.contains("unavailable raw quote"){"unavailable_quote"}
 else if text.contains("summary/tag quote")||text.contains("embedded raw quote"){"quote_dialect"}
 else if text.contains("event QT"){"event_quote"}
 else if text.contains("remote account web URL")||text.contains("bridged account web URL"){"bridged_account"}
 else if text.contains("cold remote avatar"){"cold_avatar"}
 else if text.contains("cached remote reply"){"remote_reply"}
 else if text.contains("raw profile target unavailable")||text.contains("raw profile author unavailable"){"author_missing"}
 else if text.contains("profile request")||text.contains("profile selector")||text.contains("profile first paint")||text.contains("profile append")||text.contains("invalid authenticated")||text.contains("full profile request"){"request"}
 else{"unclassified"}
}
fn fallback_body(reason:&str)->serde_json::Value{json!({"fallback":true,"reason":reason})}
pub(crate) fn log_fallback(route:&str,profile_target:&str,owner:i64,viewer:&str,tab:&str,offset:i64,reason:&str,started:std::time::Instant){
 tracing::warn!(target:"vaak.profile.complete",profile_target,owner,viewer,tab,offset,route,reason,elapsed_ms=started.elapsed().as_millis() as u64,"complete profile fallback");
}
#[derive(Deserialize)]
#[serde(tag="kind",rename_all="snake_case",deny_unknown_fields)]
enum Viewer{Authenticated{owner_id:i64,csrf:String,#[serde(default)] bsky:Option<crate::profile_bsky::PhpProfileBsky>},Guest{}}
#[derive(Deserialize,Serialize,Clone,PartialEq,Debug)]
#[serde(rename_all="snake_case")]
enum Mode{FirstPaint,Append}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Request{protocol_version:u8,viewer:Viewer,actor_key:String,actor:String,tab:String,mode:Mode,offset:i64,limit:i64,lazy_media:bool,status_preview_budget:i64,quote_preview_budget:i64,#[serde(default)] bsky_budget_deadline:Option<f64>}
#[derive(Serialize)]
struct Binding<'a>{actor_key:&'a str,actor:&'a str,tab:&'a str,mode:&'a Mode,offset:i64,limit:i64}
#[derive(Serialize)]
struct Page{source_count:usize,next_offset:i64,has_more:bool}
#[derive(Serialize)]
struct Card{source_index:usize,html:String}
#[derive(Serialize)]
struct QuoteState{initial:i64,remaining:i64,attempts:usize}
#[derive(Serialize)]
struct Reply<'a>{protocol_version:u8,ok:bool,request_binding:Binding<'a>,page:Page,cards:Vec<Card>,status_preview_state:crate::direct_preview::PreviewState,quote_preview_state:QuoteState}
fn secret()->Option<String>{if std::env::var("VAAK_PROFILE_COMPLETE_NATIVE").ok().as_deref()!=Some("1"){return None}std::env::var("VAAK_PROFILE_COMPLETE_SECRET").ok().filter(|secret|secret.len()>=32)}
pub(crate) fn enabled()->bool{secret().is_some()}
pub(crate) async fn no_store(mut response:Response)->Response{response.headers_mut().insert(axum::http::header::CACHE_CONTROL,axum::http::HeaderValue::from_static("no-store"));response}
fn authorized(headers:&HeaderMap,secret:&str)->bool{let supplied=headers.get("x-vaak-profile-token").and_then(|header|header.to_str().ok()).unwrap_or("");supplied.len()==secret.len()&&supplied.bytes().zip(secret.bytes()).fold(0u8,|diff,(a,b)|diff|(a^b))==0}
fn decode(bytes:&[u8])->Result<Request>{
 if bytes.is_empty()||bytes.len()>MAX_BYTES{bail!("profile request size")}
 let request:Request=serde_json::from_slice(bytes)?;
 if request.protocol_version!=1||!matches!(request.tab.as_str(),"posts"|"replies"|"boosts"|"media")||request.offset<0||request.actor.len()>2048||request.actor_key.len()>80||!(0..=100).contains(&request.status_preview_budget){bail!("profile selector envelope")}
 if request.bsky_budget_deadline.is_some_and(|deadline|!deadline.is_finite()){bail!("invalid private Bluesky request deadline")}
 match request.mode{Mode::FirstPaint if request.offset!=0||request.limit!=12=>bail!("profile first paint page"),Mode::Append if !(10..=50).contains(&request.limit)=>bail!("profile append page"),_=>{}}
 if let Viewer::Authenticated{owner_id,csrf,..}=&request.viewer{if *owner_id<1||csrf.is_empty()||csrf.len()>4096{bail!("invalid authenticated profile session")}}
 Ok(request)
}
async fn paint(cfg:&crate::config::Config,db:&tokio_postgres::Client,request:&Request,deps:&crate::direct_preview::PreviewDependencies<'_>,warm_override:Option<std::sync::Arc<crate::profile_enrichment::WarmContext>>)->Result<Vec<u8>>{
 ensure_budget()?;
 let warm=warm_override.unwrap_or_else(||std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg.clone(),std::sync::Arc::new(crate::profile_warm::ProcessWarmTransport::configured())).with_preview_context(std::sync::Arc::new(crate::profile_preview_fetch::PreviewFetchContext::new(std::sync::Arc::new(crate::profile_preview_transport::ProcessPreviewTransport::configured(cfg.clone()))))).with_quote_context(std::sync::Arc::new(crate::profile_quote_warm::QuoteWarmContext::new(std::sync::Arc::new(crate::profile_quote_warm::ProcessQuoteTransport::configured(cfg.clone()))).with_bsky_budget_deadline(request.bsky_budget_deadline)))));
 let guest;let authenticated;
 let viewer=match &request.viewer{
  Viewer::Guest{}=>{guest=crate::profile_context::GuestProfileSession::from_php_guest_profile(request.actor_key.clone(),request.actor.clone(),request.lazy_media,request.status_preview_budget)?.with_quote_preview_budget(request.quote_preview_budget).with_warm_context(warm);crate::profile_materialize::ProfileViewer::Guest(&guest)},
  Viewer::Authenticated{owner_id,csrf,bsky}=>{let mut session=crate::profile_context::AuthenticatedProfileSession::from_php_session(*owner_id,csrf.clone(),request.actor_key.clone(),request.actor.clone(),request.lazy_media,request.status_preview_budget)?.with_quote_preview_budget(request.quote_preview_budget).with_warm_context(warm);if let Some(bsky)=bsky{session=session.with_php_bsky(db,bsky.clone()).await?;}authenticated=session;crate::profile_materialize::ProfileViewer::Auth(&authenticated)}
 };
 let selected=crate::profile_source::select(db,&request.actor_key,&request.actor,&request.tab,request.limit,request.offset).await?;
 let mut prepared=crate::profile_context::prepare_ordered_profile_page(db,&viewer,selected,deps.dns).await?;
 #[cfg(test)] if let Some(clock)=deps.render_clock{prepared.set_render_clock_for_fixture(clock)?;}
 let(initial,remaining,attempts)=prepared.quote_state();let state=prepared.status_state();
 let reply=Reply{protocol_version:1,ok:true,request_binding:Binding{actor_key:&request.actor_key,actor:&request.actor,tab:&request.tab,mode:&request.mode,offset:request.offset,limit:request.limit},page:Page{source_count:prepared.page().source_count(),next_offset:prepared.page().next_offset(),has_more:prepared.page().has_more()},cards:prepared.cards().iter().map(|(source_index,html)|Card{source_index:*source_index,html:html.clone()}).collect(),status_preview_state:crate::direct_preview::PreviewState::from_remaining(state.initial(),state.remaining(),prepared.page().source_count())?,quote_preview_state:QuoteState{initial:initial.ok_or_else(||anyhow::anyhow!("missing quote state"))?,remaining:remaining.ok_or_else(||anyhow::anyhow!("missing quote state"))?,attempts}};
 let bytes=serde_json::to_vec(&reply)?;if bytes.len()>MAX_REPLY{bail!("profile reply size")}
 prepared.require_current(db).await?;Ok(bytes)
}
pub(crate) async fn render(cfg:&crate::config::Config,headers:HeaderMap,bytes:Bytes)->Response{
 if enabled(){prewarm_complete_profile();}
 with_production_budget(render_request(cfg,secret().as_deref(),headers,bytes,&crate::direct_preview::PreviewDependencies::production(),None)).await
}
async fn render_request(cfg:&crate::config::Config,secret:Option<&str>,headers:HeaderMap,bytes:Bytes,deps:&crate::direct_preview::PreviewDependencies<'_>,warm:Option<std::sync::Arc<crate::profile_enrichment::WarmContext>>)->Response{
 let mut response=render_request_inner(cfg,secret,headers,bytes,deps,warm).await;response.headers_mut().insert(axum::http::header::CACHE_CONTROL,axum::http::HeaderValue::from_static("no-store"));response
}
fn viewer_log(viewer:&Viewer)->(i64,&'static str){match viewer{Viewer::Guest{}=>(0,"guest"),Viewer::Authenticated{owner_id,..}=>(*owner_id,"authenticated")}}
async fn render_request_inner(cfg:&crate::config::Config,secret:Option<&str>,headers:HeaderMap,bytes:Bytes,deps:&crate::direct_preview::PreviewDependencies<'_>,warm:Option<std::sync::Arc<crate::profile_enrichment::WarmContext>>)->Response{
 let Some(secret)=secret else{return StatusCode::NOT_FOUND.into_response()};if !authorized(&headers,secret){return StatusCode::UNAUTHORIZED.into_response()}
 let started=std::time::Instant::now();
 let request=match decode(&bytes){Ok(request)=>request,Err(_)=>{log_fallback("page","",0,"unknown","",0,"request",started);return(StatusCode::BAD_REQUEST,axum::Json(fallback_body("request"))).into_response()}};
 let(owner,viewer)=viewer_log(&request.viewer);
 let result:Result<Vec<u8>>=async{let db=crate::db::connect(&cfg.database_url).await?;ensure_budget()?;paint(cfg,&db,&request,deps,warm).await}.await;
 match result{Ok(bytes)=>(StatusCode::OK,[(axum::http::header::CONTENT_TYPE,"application/json")],bytes).into_response(),Err(error)=>{let reason=fallback_reason(&error);log_fallback("page",&request.actor,owner,viewer,&request.tab,request.offset,reason,started);(StatusCode::UNPROCESSABLE_ENTITY,axum::Json(fallback_body(reason))).into_response()}}
}
#[cfg(test)]
pub(crate) async fn fixture_request(cfg:&crate::config::Config,secret:&str,headers:HeaderMap,bytes:Bytes,deps:&crate::direct_preview::PreviewDependencies<'_>,warm:std::sync::Arc<crate::profile_enrichment::WarmContext>)->Response{render_request(cfg,Some(secret),headers,bytes,deps,Some(warm)).await}
#[cfg(test)]
mod tests{
 use super::*;
 #[test]fn fallback_reason_codes_hide_source_text(){
  let budget=anyhow::anyhow!("profile_fallback_code=budget");assert_eq!(fallback_reason(&budget),"budget");
  assert_eq!(fallback_reason(&anyhow::anyhow!("PHP fallback: associative quote tag order requires raw decoding")),"quote_tag_order");
  assert_eq!(fallback_reason(&anyhow::anyhow!("PHP fallback: raw Bluesky quote normalization incomplete")),"bluesky_quote");
  assert_eq!(fallback_reason(&anyhow::anyhow!("PHP fallback: raw quote remote lookup candidate/event parity incomplete")),"remote_quote");
  assert_eq!(fallback_reason(&anyhow::anyhow!("PHP fallback: unavailable raw quote requires warming/stub assembly")),"unavailable_quote");
  assert_eq!(fallback_reason(&anyhow::anyhow!("PHP fallback: cold remote avatar requires authoritative alias lookup")),"cold_avatar");
  assert_eq!(fallback_reason(&anyhow::anyhow!("PHP fallback: raw profile author unavailable")),"author_missing");
  assert_eq!(fallback_reason(&anyhow::anyhow!("database rejected a private note body")),"unclassified");
  assert_eq!(fallback_body("budget"),json!({"fallback":true,"reason":"budget"}));
 }
 #[test]fn strict_viewer_and_selector_envelope(){
  let valid=json!({"protocol_version":1,"viewer":{"kind":"guest"},"actor_key":"fixture","actor":"https://mkultra.monster/users/fixture","tab":"posts","mode":"append","offset":0,"limit":10,"lazy_media":false,"status_preview_budget":0,"quote_preview_budget":0});assert!(decode(&serde_json::to_vec(&valid).unwrap()).is_ok());
  for(field,value)in [("protocol_version",json!(2)),("limit",json!(9)),("offset",json!(-1)),("tab",json!("unexpected")),("statuses",json!([]))]{let mut invalid=valid.clone();invalid[field]=value;assert!(decode(&serde_json::to_vec(&invalid).unwrap()).is_err());}
  for viewer in [json!({"kind":"authenticated","owner_id":0,"csrf":"session"}),json!({"kind":"guest","owner_id":1}),json!({"kind":"authenticated","owner_id":1,"csrf":""})]{let mut invalid=valid.clone();invalid["viewer"]=viewer;assert!(decode(&serde_json::to_vec(&invalid).unwrap()).is_err());}
 }
}
