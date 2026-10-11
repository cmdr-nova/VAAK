//! Opt-in disposable loopback transport for independent genuine PHP replay.
//! Only clock and DNS transport are substituted; real handlers own all parsing,
//! authentication, source admission, action facts, painting and reply state.
use axum::{body::Bytes,extract::State,http::{HeaderMap,StatusCode},response::{IntoResponse,Response}};
use std::{future::Future,net::Ipv4Addr,pin::Pin,sync::Arc};

struct DeclaredDns;
impl crate::direct_preview::PreviewDns for DeclaredDns {
 fn ipv4<'a>(&'a self,_host:&'a str)->Pin<Box<dyn Future<Output=anyhow::Result<Vec<Ipv4Addr>>>+Send+'a>> {
  Box::pin(async{Ok(vec![Ipv4Addr::new(8,8,8,8)])})
 }
}
struct FixtureState {database_url:String,secret:String,clock_path:String}
async fn request(state:Arc<FixtureState>,headers:HeaderMap,bytes:Bytes,search:bool)->Response {
 let clock=std::fs::read(&state.clock_path).ok().and_then(|data|serde_json::from_slice::<serde_json::Value>(&data).ok()).and_then(|value|value.get("clock").and_then(serde_json::Value::as_i64));
 let Some(clock)=clock.and_then(|clock|chrono::DateTime::from_timestamp(clock,0))else{return StatusCode::BAD_REQUEST.into_response()};
 let deps=crate::direct_preview::PreviewDependencies{dns:&DeclaredDns,render_clock:Some(clock)};
 if search{crate::search_bridge::fixture_request(&state.database_url,&state.secret,headers,bytes,&deps).await}
 else{crate::library::bridge::fixture_request(&state.database_url,&state.secret,headers,bytes,&deps).await}
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
  let state=Arc::new(FixtureState{database_url,secret:secret.clone(),clock_path:format!("/tmp/vaak-root-preview-fixture-clock-{width}.json")});
  let router=axum::Router::new().route("/internal/library/render",axum::routing::post(library)).route("/internal/search/render",axum::routing::post(search)).with_state(state).layer(axum::extract::DefaultBodyLimit::max(2*1024*1024));
  let listener=tokio::net::TcpListener::bind(("127.0.0.1",port)).await?;
  servers.push(tokio::spawn(async move{axum::serve(listener,router).await}));
 }
 eprintln!("Genuine preview fixture ready on loopback 18874/18875; clock JSON requires integer clock.");
 for server in servers{server.await??;}
 Ok(())
}
