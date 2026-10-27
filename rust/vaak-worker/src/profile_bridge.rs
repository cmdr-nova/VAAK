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
/// Run once when the HTTP worker starts with the switch on: compile the
/// regexes and park two pooled database connections, so the first visitor
/// after a restart does not pay for them inside the budget.
pub(crate) async fn warm_on_start(database_url:String){
 if !enabled(){return}
 prewarm_complete_profile();
 let(first,second)=tokio::join!(crate::db::profile_reused(&database_url),crate::db::profile_reused(&database_url));
 for conn in [first,second]{match conn{Ok(conn)=>{if let Err(error)=conn.simple_query("SELECT 1").await{tracing::warn!(target:"vaak.profile.complete",error=%error,"profile warm-up query failed");}},Err(_)=>tracing::warn!(target:"vaak.profile.complete","profile warm-up connect failed")}}
}
pub(crate) async fn with_production_budget<T>(fut:impl std::future::Future<Output=T>)->T{
 PROFILE_DEADLINE.scope(std::time::Instant::now()+complete_budget(),PROFILE_STAGES.scope(std::cell::Cell::new(StageTimes::default()),fut)).await
}
/// True inside a production complete-profile request (fixtures run outside).
pub(crate) fn in_production_request()->bool{PROFILE_DEADLINE.try_with(|_|()).is_ok()}
/// True once the production deadline has passed. Unset (fixtures) is never late.
pub(crate) fn past_deadline()->bool{PROFILE_DEADLINE.try_with(|deadline|std::time::Instant::now()>=*deadline).unwrap_or(false)}
/// Wall time per stage of one complete-profile request, in microseconds. It is
/// carried as a task-local beside PROFILE_DEADLINE and only read for the log
/// line. Counts and timings only: no SQL, URL or post text is recorded.
/// Stages nest: materialize..proof sit inside cards; warm and dns sit inside
/// whichever stage awaited them. The header_* sub-stages split header time and
/// also accrue when recheck/publish rerun the header dependency checks.
#[derive(Default,Clone,Copy,Debug,PartialEq)]
pub(crate) struct StageTimes{connect_us:u64,session_us:u64,header_us:u64,source_us:u64,cards_us:u64,materialize_us:u64,effects_us:u64,presentation_us:u64,admission_us:u64,warm_us:u64,dns_us:u64,paint_us:u64,proof_us:u64,recheck_us:u64,render_us:u64,serialize_us:u64,publish_us:u64,header_deps_us:u64,header_probe_us:u64,header_redis_us:u64,header_counts_us:u64,header_bsky_us:u64,header_recheck_us:u64,cards:u64,warm_calls:u64,dns_lookups:u64,ledger_checks:u64,header_deps_runs:u64,header_probes:u64,header_redis_ops:u64,header_rechecks:u64,step:&'static str}
#[derive(Clone,Copy,Debug)]
pub(crate) enum Stage{Connect,Session,Header,Source,Cards,Materialize,Effects,Presentation,Admission,Warm,Dns,Paint,Proof,Recheck,Render,Serialize,Publish,HeaderDeps,HeaderProbe,HeaderRedis,HeaderCounts,HeaderBsky,HeaderRecheck}
#[derive(Clone,Copy,Debug)]
pub(crate) enum StageCount{Card,LedgerCheck}
tokio::task_local! { static PROFILE_STAGES: std::cell::Cell<StageTimes>; }
impl StageTimes{
 fn add(&mut self,stage:Stage,us:u64){let slot=match stage{Stage::Connect=>&mut self.connect_us,Stage::Session=>&mut self.session_us,Stage::Header=>&mut self.header_us,Stage::Source=>&mut self.source_us,Stage::Cards=>&mut self.cards_us,Stage::Materialize=>&mut self.materialize_us,Stage::Effects=>&mut self.effects_us,Stage::Presentation=>&mut self.presentation_us,Stage::Admission=>&mut self.admission_us,Stage::Warm=>{self.warm_calls+=1;&mut self.warm_us},Stage::Dns=>{self.dns_lookups+=1;&mut self.dns_us},Stage::Paint=>&mut self.paint_us,Stage::Proof=>&mut self.proof_us,Stage::Recheck=>&mut self.recheck_us,Stage::Render=>&mut self.render_us,Stage::Serialize=>&mut self.serialize_us,Stage::Publish=>&mut self.publish_us,Stage::HeaderDeps=>{self.header_deps_runs+=1;&mut self.header_deps_us},Stage::HeaderProbe=>{self.header_probes+=1;&mut self.header_probe_us},Stage::HeaderRedis=>{self.header_redis_ops+=1;&mut self.header_redis_us},Stage::HeaderCounts=>&mut self.header_counts_us,Stage::HeaderBsky=>&mut self.header_bsky_us,Stage::HeaderRecheck=>{self.header_rechecks+=1;&mut self.header_recheck_us}};*slot=slot.saturating_add(us);}
 #[cfg(test)] pub(crate) fn header_breakdown(&self)->serde_json::Value{json!({"header_ms":self.header_us as f64/1000.0,"deps_ms":self.header_deps_us as f64/1000.0,"deps_runs":self.header_deps_runs,"probe_ms":self.header_probe_us as f64/1000.0,"probes":self.header_probes,"redis_ms":self.header_redis_us as f64/1000.0,"redis_ops":self.header_redis_ops,"counts_ms":self.header_counts_us as f64/1000.0,"bsky_ms":self.header_bsky_us as f64/1000.0,"recheck_ms":self.header_recheck_us as f64/1000.0,"rechecks":self.header_rechecks})}
 fn count(&mut self,counter:StageCount){match counter{StageCount::Card=>self.cards+=1,StageCount::LedgerCheck=>self.ledger_checks+=1}}
}
fn ms(us:u64)->u64{us/1_000}
/// Add the time since `started` to one stage. A no-op outside a production request.
pub(crate) fn record_stage(stage:Stage,started:std::time::Instant){let us=started.elapsed().as_micros().min(u64::MAX as u128) as u64;let _=PROFILE_STAGES.try_with(|cell|{let mut times=cell.get();times.add(stage,us);cell.set(times)});}
/// Name the step a request is in now. Steps run in sequence, so after a
/// failure this is the step that failed: the log carries it beside the reason.
pub(crate) fn mark_step(step:&'static str){let _=PROFILE_STAGES.try_with(|cell|{let mut times=cell.get();times.step=step;cell.set(times)});}
pub(crate) fn count_stage(counter:StageCount){let _=PROFILE_STAGES.try_with(|cell|{let mut times=cell.get();times.count(counter);cell.set(times)});}
/// Time one awaited stage. It is recorded whether the future succeeds or fails.
pub(crate) async fn timed<T>(stage:Stage,fut:impl std::future::Future<Output=T>)->T{let started=std::time::Instant::now();let out=fut.await;record_stage(stage,started);out}
pub(crate) fn stage_times()->StageTimes{PROFILE_STAGES.try_with(std::cell::Cell::get).unwrap_or_default()}
/// Stable reason code for the 422 body and the worker log. The error Display
/// is not logged: it can carry SQL or post text. Codes after "request" name
/// families that used to land in "unclassified": typed Postgres and JSON errors
/// are found by downcast, not by their text.
pub(crate) fn fallback_reason(err:&anyhow::Error)->&'static str{
 let text=format!("{err:#}");
 let typed=|found:fn(&(dyn std::error::Error+'static))->bool|err.chain().any(found);
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
 else if text.contains("preview DNS")||text.contains("preview eligibility"){"dns"}
 else if text.contains("connect postgres"){"db_connect"}
 else if text.contains(" changed ")||text.contains("changed before")||text.contains("changed after")||text.contains("changed during")||text.ends_with(" changed"){"source_changed"}
 else if typed(|e|e.is::<tokio_postgres::Error>()){"db"}
 else if typed(|e|e.is::<serde_json::Error>()){"decode"}
 else if text.contains("reply size")||text.contains("incomplete full profile fragment"){"reply_size"}
 else if text.contains("quote state"){"quote_state"}
 else if text.contains("XRPC"){"bluesky_xrpc"}
 else if text.contains("PHP fallback:"){"unported"}
 else{"unclassified"}
}
/// VAAK_PROFILE_COMPLETE_LOG_ERRORS=1 also logs the error chain of an
/// unclassified fallback, cut to 600 bytes. Off by default: the text can carry
/// SQL or post text. Only for a diagnosis run.
fn log_error_detail(reason:&str,err:&anyhow::Error){
 if reason!="unclassified"||std::env::var("VAAK_PROFILE_COMPLETE_LOG_ERRORS").ok().as_deref()!=Some("1"){return}
 let mut detail=format!("{err:#}");if detail.len()>600{let mut end=600;while !detail.is_char_boundary(end){end-=1;}detail.truncate(end);}
 tracing::warn!(target:"vaak.profile.complete",step=stage_times().step,detail,"complete profile fallback detail");
}
pub(crate) fn fallback_reason_logged(err:&anyhow::Error)->&'static str{let reason=fallback_reason(err);log_error_detail(reason,err);reason}
fn fallback_body(reason:&str)->serde_json::Value{json!({"fallback":true,"reason":reason})}
pub(crate) fn log_fallback(route:&str,profile_target:&str,owner:i64,viewer:&str,tab:&str,offset:i64,reason:&str,started:std::time::Instant){
 let t=stage_times();
 tracing::warn!(target:"vaak.profile.complete",profile_target,owner,viewer,tab,offset,route,reason,step=t.step,elapsed_ms=started.elapsed().as_millis() as u64,over_budget=past_deadline(),connect_ms=ms(t.connect_us),session_ms=ms(t.session_us),header_ms=ms(t.header_us),source_ms=ms(t.source_us),cards_ms=ms(t.cards_us),materialize_ms=ms(t.materialize_us),effects_ms=ms(t.effects_us),presentation_ms=ms(t.presentation_us),admission_ms=ms(t.admission_us),warm_ms=ms(t.warm_us),dns_ms=ms(t.dns_us),paint_ms=ms(t.paint_us),proof_ms=ms(t.proof_us),recheck_ms=ms(t.recheck_us),render_ms=ms(t.render_us),serialize_ms=ms(t.serialize_us),publish_ms=ms(t.publish_us),header_deps_ms=ms(t.header_deps_us),header_probe_ms=ms(t.header_probe_us),header_redis_ms=ms(t.header_redis_us),header_counts_ms=ms(t.header_counts_us),header_bsky_ms=ms(t.header_bsky_us),header_recheck_ms=ms(t.header_recheck_us),cards=t.cards,warm_calls=t.warm_calls,dns_lookups=t.dns_lookups,ledger_checks=t.ledger_checks,header_deps_runs=t.header_deps_runs,header_probes=t.header_probes,header_redis_ops=t.header_redis_ops,header_rechecks=t.header_rechecks,"complete profile fallback");
}
pub(crate) fn log_complete(route:&str,profile_target:&str,owner:i64,viewer:&str,tab:&str,offset:i64,started:std::time::Instant){
 let t=stage_times();
 tracing::info!(target:"vaak.profile.complete",profile_target,owner,viewer,tab,offset,route,elapsed_ms=started.elapsed().as_millis() as u64,over_budget=past_deadline(),connect_ms=ms(t.connect_us),session_ms=ms(t.session_us),header_ms=ms(t.header_us),source_ms=ms(t.source_us),cards_ms=ms(t.cards_us),materialize_ms=ms(t.materialize_us),effects_ms=ms(t.effects_us),presentation_ms=ms(t.presentation_us),admission_ms=ms(t.admission_us),warm_ms=ms(t.warm_us),dns_ms=ms(t.dns_us),paint_ms=ms(t.paint_us),proof_ms=ms(t.proof_us),recheck_ms=ms(t.recheck_us),render_ms=ms(t.render_us),serialize_ms=ms(t.serialize_us),publish_ms=ms(t.publish_us),header_deps_ms=ms(t.header_deps_us),header_probe_ms=ms(t.header_probe_us),header_redis_ms=ms(t.header_redis_us),header_counts_ms=ms(t.header_counts_us),header_bsky_ms=ms(t.header_bsky_us),header_recheck_ms=ms(t.header_recheck_us),cards=t.cards,warm_calls=t.warm_calls,dns_lookups=t.dns_lookups,ledger_checks=t.ledger_checks,header_deps_runs=t.header_deps_runs,header_probes=t.header_probes,header_redis_ops=t.header_redis_ops,header_rechecks=t.header_rechecks,"complete profile rendered");
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
 let guest;let authenticated;let session_started=std::time::Instant::now();
 let viewer=match &request.viewer{
  Viewer::Guest{}=>{guest=crate::profile_context::GuestProfileSession::from_php_guest_profile(request.actor_key.clone(),request.actor.clone(),request.lazy_media,request.status_preview_budget)?.with_quote_preview_budget(request.quote_preview_budget).with_warm_context(warm);crate::profile_materialize::ProfileViewer::Guest(&guest)},
  Viewer::Authenticated{owner_id,csrf,bsky}=>{let mut session=crate::profile_context::AuthenticatedProfileSession::from_php_session(*owner_id,csrf.clone(),request.actor_key.clone(),request.actor.clone(),request.lazy_media,request.status_preview_budget)?.with_quote_preview_budget(request.quote_preview_budget).with_warm_context(warm);if let Some(bsky)=bsky{session=session.with_php_bsky(db,bsky.clone()).await?;}authenticated=session;crate::profile_materialize::ProfileViewer::Auth(&authenticated)}
 };
 record_stage(Stage::Session,session_started);
 let selected=timed(Stage::Source,crate::profile_source::select(db,&request.actor_key,&request.actor,&request.tab,request.limit,request.offset)).await?;
 let mut prepared=timed(Stage::Cards,crate::profile_context::prepare_ordered_profile_page(db,&viewer,selected,deps.dns)).await?;
 #[cfg(test)] if let Some(clock)=deps.render_clock{prepared.set_render_clock_for_fixture(clock)?;}
 let(initial,remaining,attempts)=prepared.quote_state();let state=prepared.status_state();
 let reply=Reply{protocol_version:1,ok:true,request_binding:Binding{actor_key:&request.actor_key,actor:&request.actor,tab:&request.tab,mode:&request.mode,offset:request.offset,limit:request.limit},page:Page{source_count:prepared.page().source_count(),next_offset:prepared.page().next_offset(),has_more:prepared.page().has_more()},cards:prepared.cards().iter().map(|(source_index,html)|Card{source_index:*source_index,html:html.clone()}).collect(),status_preview_state:crate::direct_preview::PreviewState::from_remaining(state.initial(),state.remaining(),prepared.page().source_count())?,quote_preview_state:QuoteState{initial:initial.ok_or_else(||anyhow::anyhow!("missing quote state"))?,remaining:remaining.ok_or_else(||anyhow::anyhow!("missing quote state"))?,attempts}};
 let serialize_started=std::time::Instant::now();let bytes=serde_json::to_vec(&reply)?;record_stage(Stage::Serialize,serialize_started);if bytes.len()>MAX_REPLY{bail!("profile reply size")}
 timed(Stage::Publish,prepared.require_current(db)).await?;Ok(bytes)
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
 let result:Result<Vec<u8>>=async{let db=timed(Stage::Connect,crate::db::profile_reused(&cfg.database_url)).await?;ensure_budget()?;paint(cfg,&db,&request,deps,warm).await}.await;
 match result{Ok(bytes)=>{log_complete("page",&request.actor,owner,viewer,&request.tab,request.offset,started);(StatusCode::OK,[(axum::http::header::CONTENT_TYPE,"application/json")],bytes).into_response()},Err(error)=>{let reason=fallback_reason_logged(&error);log_fallback("page",&request.actor,owner,viewer,&request.tab,request.offset,reason,started);(StatusCode::UNPROCESSABLE_ENTITY,axum::Json(fallback_body(reason))).into_response()}}
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
 }
 #[test]fn fallback_reason_names_former_unclassified_families(){
  // A budget marker wins over any wrapping context.
  assert_eq!(fallback_reason(&anyhow::anyhow!("profile_fallback_code=budget").context("PHP fallback: projection changed before enrichment dependency")),"budget");
  assert_eq!(fallback_reason(&anyhow::anyhow!("PHP fallback: preview DNS timeout")),"dns");
  assert_eq!(fallback_reason(&anyhow::anyhow!("PHP fallback: unresolved preview eligibility")),"dns");
  assert_eq!(fallback_reason(&anyhow::anyhow!("PHP fallback: unresolved IPv4 preview eligibility")),"dns");
  assert_eq!(fallback_reason(&anyhow::Error::new(std::io::Error::other("lookup")).context("PHP fallback: preview DNS lookup failed")),"dns");
  assert_eq!(fallback_reason(&anyhow::anyhow!("os error").context("connect postgres (redacted)")),"db_connect");
  for changed in ["PHP fallback: projection changed before enrichment dependency","PHP fallback: selected profile ledger changed before publication","header dependencies changed during enrichment","PHP fallback: raw profile target changed after later source preparation","PHP fallback: prepared profile source changed before publication","raw final prefix target changed","blocked profile target changed"]{assert_eq!(fallback_reason(&anyhow::anyhow!(changed)),"source_changed","{changed}");}
  let json=serde_json::from_str::<serde_json::Value>("{").unwrap_err();assert_eq!(fallback_reason(&anyhow::Error::new(json)),"decode");
  assert_eq!(fallback_reason(&anyhow::anyhow!("profile reply size")),"reply_size");assert_eq!(fallback_reason(&anyhow::anyhow!("incomplete full profile fragment")),"reply_size");
  assert_eq!(fallback_reason(&anyhow::anyhow!("full profile quote state missing")),"quote_state");
  assert_eq!(fallback_reason(&anyhow::anyhow!("public XRPC reply exceeds native bounded body; whole request fallback required")),"bluesky_xrpc");
  assert_eq!(fallback_reason(&anyhow::anyhow!("PHP fallback: cold remote link preview warm capability absent")),"unported");
  // Existing specific codes still win over the broader families.
  assert_eq!(fallback_reason(&anyhow::anyhow!("PHP fallback: cached remote reply changed")),"remote_reply");
 }
 #[tokio::test] async fn postgres_errors_are_db_not_unclassified(){
  // Runs where a disposable fixture database is configured; skipped otherwise.
  let Ok(url)=std::env::var("LIBRARY_FIXTURE_DB")else{return};let db=crate::db::connect(&url).await.unwrap();
  let error=db.query_one("SELECT * FROM vaak_profile_reason_missing_table",&[]).await.unwrap_err();assert_eq!(fallback_reason(&anyhow::Error::new(error)),"db");
 }
 #[tokio::test] async fn past_deadline_follows_the_request_deadline(){
  let early=PROFILE_DEADLINE.scope(std::time::Instant::now()+std::time::Duration::from_secs(60),async{past_deadline()}).await;assert!(!early);
  let late=PROFILE_DEADLINE.scope(std::time::Instant::now(),async{past_deadline()}).await;assert!(late);
  assert!(!past_deadline(),"fixtures without a deadline are never late");
 }
 #[tokio::test] async fn stage_clock_is_request_local_and_a_no_op_outside(){
  record_stage(Stage::Header,std::time::Instant::now());count_stage(StageCount::Card);assert_eq!(stage_times(),StageTimes::default());
  let times=with_production_budget(async{
   let started=std::time::Instant::now()-std::time::Duration::from_millis(5);record_stage(Stage::Header,started);record_stage(Stage::Header,started);
   timed(Stage::Dns,async{}).await;timed(Stage::Warm,async{}).await;for stage in[Stage::HeaderDeps,Stage::HeaderProbe,Stage::HeaderRedis,Stage::HeaderRecheck,Stage::HeaderCounts,Stage::HeaderBsky]{record_stage(stage,started);}count_stage(StageCount::Card);count_stage(StageCount::Card);count_stage(StageCount::LedgerCheck);
   let failed:Result<()>=timed(Stage::Publish,async{bail!("still timed")}).await;assert!(failed.is_err());
   mark_step("paint");mark_step("final_proof");assert_eq!(stage_times().step,"final_proof","the last step marked is the one that failed");
   stage_times()
  }).await;
  mark_step("paint");assert_eq!(stage_times().step,"","no step outside a request");
  assert!(times.header_us>=10_000,"{times:?}");assert!(ms(times.header_us)>=10);
  assert_eq!((times.cards,times.ledger_checks,times.dns_lookups,times.warm_calls),(2,1,1,1));assert_eq!((times.header_deps_runs,times.header_probes,times.header_redis_ops,times.header_rechecks),(1,1,1,1));assert!(times.header_counts_us>=5_000&&times.header_bsky_us>=5_000&&times.header_deps_us>=5_000);
  assert_eq!(times.connect_us,0);assert_eq!(stage_times(),StageTimes::default());
  assert_eq!(fallback_body("budget"),json!({"fallback":true,"reason":"budget"}));
 }
 #[test]fn strict_viewer_and_selector_envelope(){
  let valid=json!({"protocol_version":1,"viewer":{"kind":"guest"},"actor_key":"fixture","actor":"https://mkultra.monster/users/fixture","tab":"posts","mode":"append","offset":0,"limit":10,"lazy_media":false,"status_preview_budget":0,"quote_preview_budget":0});assert!(decode(&serde_json::to_vec(&valid).unwrap()).is_ok());
  for(field,value)in [("protocol_version",json!(2)),("limit",json!(9)),("offset",json!(-1)),("tab",json!("unexpected")),("statuses",json!([]))]{let mut invalid=valid.clone();invalid[field]=value;assert!(decode(&serde_json::to_vec(&invalid).unwrap()).is_err());}
  for viewer in [json!({"kind":"authenticated","owner_id":0,"csrf":"session"}),json!({"kind":"guest","owner_id":1}),json!({"kind":"authenticated","owner_id":1,"csrf":""})]{let mut invalid=valid.clone();invalid["viewer"]=viewer;assert!(decode(&serde_json::to_vec(&invalid).unwrap()).is_err());}
 }
}
