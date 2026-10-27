//! Complete local first-paint inner profile fragment, shared by full and shell
//! callers. Outer navigation/bootstrap remains the current PHP controller.
use super::*;
use anyhow::Context;
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct FullRequest {
 protocol_version:u8,viewer:Viewer,actor_key:String,actor:String,tab:String,
 from:String,post:String,#[serde(default)]page:i64,shell:bool,lazy_media:bool,status_preview_budget:i64,
 quote_preview_budget:i64,#[serde(default)]bsky_budget_deadline:Option<f64>,
}
#[derive(Serialize)]
struct FullBinding<'a>{actor_key:&'a str,actor:&'a str,tab:&'a str,from:&'a str,post:&'a str,shell:bool}
#[derive(Serialize)]
struct FragmentMetadata{kind:&'static str,header_bytes:usize,content_bytes:usize,card_ordinals:Vec<usize>}
#[derive(Serialize)]
struct SourcePage{source_count:usize,next_offset:i64,has_more:bool}
#[derive(Serialize)]
struct FullReply<'a>{protocol_version:u8,ok:bool,request_binding:FullBinding<'a>,source_count:usize,effective_tab:String,html:String,fragment:FragmentMetadata,source_page:SourcePage,status_preview_state:crate::direct_preview::PreviewState,quote_preview_state:QuoteState}
fn decode_full(bytes:&[u8])->Result<FullRequest>{
 if bytes.is_empty()||bytes.len()>MAX_BYTES{bail!("full profile request size")}
 let request:FullRequest=serde_json::from_slice(bytes)?;
 if !matches!(request.tab.as_str(),"posts"|"replies"|"boosts"|"media"|"pinned"|"asks"|"featured"|"blog")||request.protocol_version!=1||request.actor.len()>2048||request.actor_key.len()>80||request.tab.len()>32||request.from.len()>80||request.post.len()>256||!(0..=10_000).contains(&request.page)||!(0..=100).contains(&request.status_preview_budget)||request.bsky_budget_deadline.is_some_and(|value|!value.is_finite()){bail!("full profile request envelope")}
 if let Viewer::Authenticated{owner_id,csrf,..}=&request.viewer{if *owner_id<1||csrf.is_empty()||csrf.len()>4096{bail!("invalid full profile viewer")}}
 Ok(request)
}
async fn paint_full(cfg:&crate::config::Config,db:&tokio_postgres::Client,request:&FullRequest,deps:&crate::direct_preview::PreviewDependencies<'_>)->Result<Vec<u8>>{
 let warm=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg.clone(),std::sync::Arc::new(crate::profile_warm::ProcessWarmTransport::configured())).with_preview_context(std::sync::Arc::new(crate::profile_preview_fetch::PreviewFetchContext::new(std::sync::Arc::new(crate::profile_preview_transport::ProcessPreviewTransport::configured(cfg.clone()))))).with_quote_context(std::sync::Arc::new(crate::profile_quote_warm::QuoteWarmContext::new(std::sync::Arc::new(crate::profile_quote_warm::ProcessQuoteTransport::configured(cfg.clone()))).with_bsky_budget_deadline(request.bsky_budget_deadline))));
 paint_full_with_warm(db,request,deps,warm,None).await
}
async fn paint_full_with_warm(db:&tokio_postgres::Client,request:&FullRequest,deps:&crate::direct_preview::PreviewDependencies<'_>,warm:std::sync::Arc<crate::profile_enrichment::WarmContext>,clock:Option<chrono::DateTime<chrono::Utc>>)->Result<Vec<u8>>{
 super::ensure_budget()?;
 let guest;let authenticated;let session_started=std::time::Instant::now();
 let viewer=match &request.viewer{
  Viewer::Guest{}=>{guest=crate::profile_context::GuestProfileSession::from_php_guest_profile(request.actor_key.clone(),request.actor.clone(),request.lazy_media,request.status_preview_budget)?.with_quote_preview_budget(request.quote_preview_budget).with_warm_context(warm);crate::profile_materialize::ProfileViewer::Guest(&guest)},
  Viewer::Authenticated{owner_id,csrf,bsky}=>{let mut session=crate::profile_context::AuthenticatedProfileSession::from_php_session(*owner_id,csrf.clone(),request.actor_key.clone(),request.actor.clone(),request.lazy_media,request.status_preview_budget)?.with_quote_preview_budget(request.quote_preview_budget).with_warm_context(warm);if let Some(bsky)=bsky{session=session.with_php_bsky(db,bsky.clone()).await?}authenticated=session;crate::profile_materialize::ProfileViewer::Auth(&authenticated)}
 };
 super::record_stage(super::Stage::Session,session_started);
 let header_request=crate::profile_header::HeaderRequest{tab:request.tab.clone(),from:request.from.clone(),post:request.post.clone(),page:request.page};
 let mut prepared=crate::profile_context::prepare_local_profile_first_paint(db,&viewer,&header_request,deps.dns).await?;
 #[cfg(test)] if let (Some(clock),crate::profile_context::LocalProfileFirstPaint::Timeline{body,..}|crate::profile_context::LocalProfileFirstPaint::Secondary{body,..})=(clock,&mut prepared){body.set_render_clock_for_fixture(clock)?;}
 #[cfg(not(test))] let _=clock;
 let(source_count,status_remaining,quote_remaining,status_attempts,quote_attempts,effective_tab)=match &prepared{
  crate::profile_context::LocalProfileFirstPaint::Timeline{header,body,..}|crate::profile_context::LocalProfileFirstPaint::Secondary{header,body,..}=>{let(_,quote,attempts)=body.quote_state();(body.page().source_count(),body.status_state().remaining(),quote.context("full profile quote state missing")?,body.status_state().attempts(),attempts,header.facts().effective_tab().to_owned())},
  crate::profile_context::LocalProfileFirstPaint::Featured{header,content}=>(content.source_count(),request.status_preview_budget,request.quote_preview_budget,0,0,header.facts().effective_tab().to_owned()),
  crate::profile_context::LocalProfileFirstPaint::Blog(header)=>(0,request.status_preview_budget,request.quote_preview_budget,0,0,header.facts().effective_tab().to_owned()),
  crate::profile_context::LocalProfileFirstPaint::Blocked(_)=>(0,request.status_preview_budget,request.quote_preview_budget,0,0,request.tab.clone()),
 };
 let render_started=std::time::Instant::now();
 let html=prepared.render()?;
 let(kind,header_bytes,card_ordinals,source_page)=match &prepared{
  crate::profile_context::LocalProfileFirstPaint::Timeline{header,body,..}=>("timeline",crate::profile_header::render(header)?.len(),body.cards().iter().map(|(ordinal,_)|*ordinal).collect(),SourcePage{source_count,next_offset:body.page().next_offset(),has_more:body.page().has_more()}),
  crate::profile_context::LocalProfileFirstPaint::Secondary{header,body,..}=>("secondary",crate::profile_header::render(header)?.len(),body.cards().iter().map(|(ordinal,_)|*ordinal).collect(),SourcePage{source_count,next_offset:body.page().next_offset(),has_more:false}),
  crate::profile_context::LocalProfileFirstPaint::Featured{header,content}=>("featured",crate::profile_header::render(header)?.len(),content.card_ordinals(),SourcePage{source_count,next_offset:source_count as i64,has_more:false}),
  crate::profile_context::LocalProfileFirstPaint::Blog(header)=>("blog",crate::profile_header::render(header)?.len(),Vec::new(),SourcePage{source_count:0,next_offset:0,has_more:false}),
  crate::profile_context::LocalProfileFirstPaint::Blocked(_)=>("blocked",html.len(),Vec::new(),SourcePage{source_count:0,next_offset:0,has_more:false}),
 };
 if html.is_empty()||header_bytes==0||header_bytes>html.len(){bail!("incomplete full profile fragment")}
 let fragment=FragmentMetadata{kind,header_bytes,content_bytes:html.len()-header_bytes,card_ordinals};super::record_stage(super::Stage::Render,render_started);

 let reply=FullReply{protocol_version:1,ok:true,request_binding:FullBinding{actor_key:&request.actor_key,actor:&request.actor,tab:&request.tab,from:&request.from,post:&request.post,shell:request.shell},source_count,effective_tab,html,fragment,source_page,status_preview_state:crate::direct_preview::PreviewState::from_remaining(request.status_preview_budget,status_remaining,source_count)?,quote_preview_state:QuoteState{initial:request.quote_preview_budget,remaining:quote_remaining,attempts:quote_attempts}};
 debug_assert_eq!(status_attempts,reply.status_preview_state.attempts());
 let serialize_started=std::time::Instant::now();let bytes=serde_json::to_vec(&reply)?;super::record_stage(super::Stage::Serialize,serialize_started);if bytes.len()>MAX_REPLY{bail!("full profile reply size")}
 super::timed(super::Stage::Publish,prepared.require_current(db)).await?;Ok(bytes)
}
pub(crate) async fn render_full(cfg:&crate::config::Config,headers:HeaderMap,bytes:Bytes)->Response{
 if super::enabled(){super::prewarm_complete_profile();}
 let response=super::with_production_budget(async{
  let Some(secret)=secret()else{return StatusCode::NOT_FOUND.into_response()};if !authorized(&headers,&secret){return StatusCode::UNAUTHORIZED.into_response()}
  let started=std::time::Instant::now();
  let request=match decode_full(&bytes){Ok(request)=>request,Err(_)=>{super::log_fallback("full","",0,"unknown","",0,"request",started);return(StatusCode::BAD_REQUEST,axum::Json(json!({"fallback":true,"reason":"request"}))).into_response()}};
  let(owner,viewer)=match &request.viewer{Viewer::Guest{}=>(0,"guest"),Viewer::Authenticated{owner_id,..}=>(*owner_id,"authenticated")};
  let result:Result<Vec<u8>>=async{let db=super::timed(super::Stage::Connect,crate::db::connect(&cfg.database_url)).await?;super::ensure_budget()?;paint_full(cfg,&db,&request,&crate::direct_preview::PreviewDependencies::production()).await}.await;
  match result{Ok(bytes)=>{super::log_complete("full",&request.actor,owner,viewer,&request.tab,request.page,started);(StatusCode::OK,[(axum::http::header::CONTENT_TYPE,"application/json")],bytes).into_response()},Err(error)=>{let reason=super::fallback_reason(&error);super::log_fallback("full",&request.actor,owner,viewer,&request.tab,request.page,reason,started);(StatusCode::UNPROCESSABLE_ENTITY,axum::Json(json!({"fallback":true,"reason":reason}))).into_response()}}
 }).await;
 no_store(response).await
}

#[cfg(test)]
pub(crate) async fn fixture_request(cfg:&crate::config::Config,secret:&str,headers:HeaderMap,bytes:Bytes,deps:&crate::direct_preview::PreviewDependencies<'_>,warm:std::sync::Arc<crate::profile_enrichment::WarmContext>,clock:chrono::DateTime<chrono::Utc>)->Response{
 std::fs::write("/tmp/vaak-profile-full-last-request.json",&bytes).unwrap();
 let response=async{
  if !authorized(&headers,secret){return StatusCode::UNAUTHORIZED.into_response()}
  let request=match decode_full(&bytes){Ok(value)=>value,Err(_)=>return(StatusCode::BAD_REQUEST,axum::Json(json!({"fallback":true}))).into_response()};
  let result:Result<Vec<u8>>=async{let db=crate::db::connect(&cfg.database_url).await?;paint_full_with_warm(&db,&request,deps,warm,Some(clock)).await}.await;
  match result{Ok(bytes)=>{std::fs::write("/tmp/vaak-profile-full-last-reply.json",&bytes).unwrap();(StatusCode::OK,[(axum::http::header::CONTENT_TYPE,"application/json")],bytes).into_response()},Err(error)=>{eprintln!("fixture full error: {error:#}");(StatusCode::UNPROCESSABLE_ENTITY,axum::Json(json!({"fallback":true}))).into_response()}}
 }.await;no_store(response).await
}
