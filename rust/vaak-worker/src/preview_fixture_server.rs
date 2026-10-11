//! Opt-in disposable loopback transport for independent genuine PHP replay.
//! Clock, DNS and opt-in warming delivery are substituted; real handlers own all parsing,
//! authentication, source admission, action facts, painting and reply state.
use axum::{body::Bytes,extract::State,http::{HeaderMap,StatusCode},response::{IntoResponse,Response}};
use std::{future::Future,net::Ipv4Addr,pin::Pin,sync::Arc};

struct DeclaredDns;
impl crate::direct_preview::PreviewDns for DeclaredDns {
 fn ipv4<'a>(&'a self,_host:&'a str)->Pin<Box<dyn Future<Output=anyhow::Result<Vec<Ipv4Addr>>>+Send+'a>> {
  Box::pin(async{Ok(vec![Ipv4Addr::new(8,8,8,8)])})
 }
}
struct RecordedWarmTransport;
impl crate::profile_warm::WarmTransport for RecordedWarmTransport {
 fn actor_process<'a>(&'a self,_actor:&'a str)->Pin<Box<dyn Future<Output=bool>+Send+'a>>{Box::pin(async{false})}
 fn media_process(&self)->Pin<Box<dyn Future<Output=bool>+Send+'_>>{Box::pin(async{false})}
}
struct FixtureState {database_url:String,secret:String,clock_path:String,effects_path:Option<String>}
async fn request(state:Arc<FixtureState>,headers:HeaderMap,bytes:Bytes,search:bool)->Response {
 let clock=std::fs::read(&state.clock_path).ok().and_then(|data|serde_json::from_slice::<serde_json::Value>(&data).ok()).and_then(|value|value.get("clock").and_then(serde_json::Value::as_i64));
 let Some(clock)=clock.and_then(|clock|chrono::DateTime::from_timestamp(clock,0))else{return StatusCode::BAD_REQUEST.into_response()};
 let deps=crate::direct_preview::PreviewDependencies{dns:&DeclaredDns,render_clock:Some(clock)};
 if let Some(path)=&state.effects_path {
  // This ignored fixture uses dead loopback Redis endpoints and records only
  // external worker delivery. Actual durable media enqueue remains current SQL.
  let cfg=crate::config::Config{database_url:state.database_url.clone(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:1,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
  let warm=Arc::new(crate::profile_enrichment::WarmContext::new(cfg,Arc::new(RecordedWarmTransport)).with_fixture_clock(clock));
  let response=if search{crate::search_bridge::fixture_request_with_warm(&state.database_url,&state.secret,headers,bytes,&deps,warm.clone()).await}
  else{crate::library::bridge::fixture_request_with_warm(&state.database_url,&state.secret,headers,bytes,&deps,warm.clone()).await};
  let effects=serde_json::json!({"actor_warm":warm.requested_actors(),"media_warm":warm.requested_media(),"scope":"actual helper intents and durable media SQL; external process delivery recorded"});
  if std::fs::write(path,serde_json::to_vec(&effects).unwrap()).is_err(){return StatusCode::INTERNAL_SERVER_ERROR.into_response()}
  return response;
 }
 if search{crate::search_bridge::fixture_request(&state.database_url,&state.secret,headers,bytes,&deps).await}
 else{crate::library::bridge::fixture_request(&state.database_url,&state.secret,headers,bytes,&deps).await}
}
#[derive(serde::Deserialize)]
struct LibraryDataQuery{owner_id:i64,offset:Option<i64>,limit:Option<i64>,folder_id:Option<i64>,library_kind:Option<String>}
async fn library_data(State(state):State<Arc<FixtureState>>,axum::extract::Query(query):axum::extract::Query<LibraryDataQuery>)->Response{
 // Actual structured producer, selected only by this explicit disposable
 // fixture configuration. The renderer independently enforces current grants.
 let cfg=crate::config::Config{database_url:state.database_url.clone(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:1,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
 if query.library_kind.as_deref()==Some("favourites"){
  return match crate::library::favourites(&cfg,query.owner_id,query.offset.unwrap_or(0),query.limit.unwrap_or(20)).await{
   Ok(value)=>axum::Json(value).into_response(),Err(error)=>{eprintln!("Actual fixture favourite data: {error}");StatusCode::INTERNAL_SERVER_ERROR.into_response()}
  }
 }
 if query.library_kind.as_deref()!=Some("bookmarks"){return StatusCode::BAD_REQUEST.into_response()}
 match crate::library::bookmarks(&cfg,query.owner_id,query.offset.unwrap_or(0),query.limit.unwrap_or(20),query.folder_id.filter(|id|*id>0)).await{
  Ok(value)=>axum::Json(value).into_response(),Err(error)=>{eprintln!("Actual fixture Library data: {error}");StatusCode::INTERNAL_SERVER_ERROR.into_response()}
 }
}
async fn library(State(state):State<Arc<FixtureState>>,headers:HeaderMap,bytes:Bytes)->Response {request(state,headers,bytes,false).await}
async fn search(State(state):State<Arc<FixtureState>>,headers:HeaderMap,bytes:Bytes)->Response {request(state,headers,bytes,true).await}

#[tokio::test]
#[ignore="disposable actual PHP HTTP replay server; explicit fixture DB/secret required"]
async fn serve_genuine_preview_fixture()->anyhow::Result<()> {
 let secret=std::env::var("VAAK_PREVIEW_FIXTURE_SECRET")?;
 anyhow::ensure!(secret.len()>=32,"fixture secret must meet production producer bound");
 let mut servers=Vec::new();
 for (width,port) in [("integer",18874),("bigint",18875)] {
  let database_url=std::env::var(format!("VAAK_PREVIEW_FIXTURE_DB_{}",width.to_ascii_uppercase()))?;
  let state=Arc::new(FixtureState{database_url,secret:secret.clone(),clock_path:format!("/tmp/vaak-root-preview-fixture-clock-{width}.json"),effects_path:std::env::var("VAAK_PREVIEW_FIXTURE_WARM_EFFECTS_PREFIX").ok().map(|prefix|format!("{prefix}-{width}.json"))});
  let router=axum::Router::new().route("/shadow/library-data",axum::routing::get(library_data)).route("/internal/library/render",axum::routing::post(library)).route("/internal/search/render",axum::routing::post(search)).with_state(state).layer(axum::extract::DefaultBodyLimit::max(2*1024*1024)).layer(axum::middleware::map_response(crate::profile_bridge::no_store));
  let listener=tokio::net::TcpListener::bind(("127.0.0.1",port)).await?;
  servers.push(tokio::spawn(async move{axum::serve(listener,router).await}));
 }
 eprintln!("Genuine preview fixture ready on loopback 18874/18875; clock JSON requires integer clock.");
 for server in servers{server.await??;}
 Ok(())
}
