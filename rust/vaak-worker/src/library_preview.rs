//! Shared Library/Search root preview order from ap-admin.php:18020-18108.
//! The caller supplies a currently bound source grant and server-owned transport;
//! neither cache metadata nor a returned preview establishes audience access.
use anyhow::Result;
use serde_json::Value;
use tokio_postgres::Client;
fn text<'a>(value:&'a Value,key:&str)->&'a str{value.get(key).and_then(Value::as_str).unwrap_or("")}
/// Cache-only resolver guards each actual DNS/cache await. Its pure branches
/// perform no IO and remain subject to enclosing final source publication proof.
pub(crate) async fn resolve_cached_current(db:&Client,dns:&dyn crate::direct_preview::PreviewDns,status:&Value,original_content:Option<&str>,facts:&super::card::CardFacts,budget:&mut i64,grant:&dyn crate::profile_enrichment::CurrentEnrichmentGrant)->Result<Option<Value>>{
 crate::direct_preview::resolve_guarded(db,dns,status,original_content,facts,budget,Some(grant)).await
}

pub(crate) async fn resolve(db:&Client,dns:&dyn crate::direct_preview::PreviewDns,status:&Value,original_content:Option<&str>,facts:&super::card::CardFacts,budget:&mut i64,fetch:&crate::profile_preview_fetch::PreviewFetchContext,grant:&dyn crate::profile_quote_warm::CurrentQuoteGrant)->Result<Option<Value>>{
 grant.require_current().await?;
 let st=if text(status,"content").is_empty(){status.get("reblog").filter(|value|value.is_object()).unwrap_or(status)}else{status};
 let note=if text(st,"uri").is_empty(){text(st,"url")}else{text(st,"uri")};
 if st.get("media_attachments").and_then(Value::as_array).is_some_and(|media|!media.is_empty())||facts.poll_row.as_ref().is_some_and(|row|!super::card::poll_html(row,note,facts).is_empty()){return Ok(None)}
 let quoted=st.get("quote").and_then(|quote|quote.get("quoted_status")).filter(|value|value.is_object());
 let quote_url=quoted.map(|quote|if text(quote,"uri").starts_with("https://"){text(quote,"uri")}else{text(quote,"url")}).unwrap_or("");
 let duplicate=|card:&Value|super::card::status_urls_equivalent(text(card,"url"),quote_url);
 if let Some(card)=st.get("card").filter(|card|card.is_object()&&!duplicate(card)){
  if ["url","title","description","provider_name","provider_url","type"].iter().any(|key|card.get(*key).is_some_and(|value|!value.is_null()&&!value.is_string())){anyhow::bail!("PHP fallback: supplied root preview scalar normalization")}
  let mut card=card.clone();card["status"]=serde_json::json!("ok");
  if !super::card::link_preview(&card)?.is_empty(){return Ok(Some(card))}
 }
 let plain=crate::direct_preview::preview_plain(st,facts.ask_row.as_ref().map(|ask|text(ask,"_body_answer")));
 let linked=if plain.is_empty(){String::new()}else{super::card::linked_body_html(&plain,st,facts)?};
 if let Some(card)=crate::direct_preview::youtube_from_linked_html(&linked)?{return Ok(Some(card))}
 if plain.is_empty(){return Ok(None)}
 // PHP extracts from the original incoming HTML even when current own/Ask
 // body facts replace its visible text. A missing URL still spends one attempt.
 let source_text=original_content.unwrap_or(&plain);
 let mut card=fetch.resolve_text(db,dns,source_text,false,facts.now,grant).await?;
 if card.is_none()&&*budget>0{*budget-=1;card=fetch.resolve_text(db,dns,source_text,true,facts.now,grant).await?;}
 if card.is_none(){fetch.warm_text(source_text,grant).await?;}
 grant.require_current().await?;
 Ok(card.filter(|card|!duplicate(card)))
}

#[cfg(test)]mod caller_faults{
 use super::*;use anyhow::Context;use std::{sync::Arc,future::Future,pin::Pin,net::Ipv4Addr};
 struct NoWorkers;impl crate::profile_warm::WarmTransport for NoWorkers{
  fn actor_process<'a>(&'a self,_:&'a str)->Pin<Box<dyn Future<Output=bool>+Send+'a>>{Box::pin(async{false})}
  fn media_process(&self)->Pin<Box<dyn Future<Output=bool>+Send+'_>>{Box::pin(async{false})}
 }
 struct MutationDns{url:String,mutation:&'static str,calls:std::sync::atomic::AtomicUsize}
 impl crate::direct_preview::PreviewDns for MutationDns{
  fn ipv4<'a>(&'a self,_:&'a str)->Pin<Box<dyn Future<Output=Result<Vec<Ipv4Addr>>>+Send+'a>>{Box::pin(async move{
   self.calls.fetch_add(1,std::sync::atomic::Ordering::SeqCst);let db=crate::db::connect(&self.url).await?;
   match self.mutation{
    "bookmark_removed"=>{db.execute("DELETE FROM masto_bookmarks WHERE owner_user_id::bigint=1",&[]).await?;},
    "owner_revoked"=>{db.execute("UPDATE ap_users SET disabled_at='2026-10-11' WHERE id::bigint=1",&[]).await?;},
    "network_window_shift"=>{db.execute("INSERT INTO masto_bookmarks(id,owner_user_id,status_id,object_id,created_at) VALUES(9999,1,'late-window-row','https://remote.test/late-window','2027-01-01')",&[]).await?;},
    "network_cursor_sentinel"=>{db.execute("INSERT INTO masto_bookmarks(id,owner_user_id,status_id,object_id,created_at) SELECT 10000+n,1,'late-cursor-'||n,'https://remote.test/late-cursor/'||n,'2025-01-01' FROM generate_series(1,10) n",&[]).await?;},
    "bsky_cache_removed"=>{db.execute("DELETE FROM bsky_bookmark_cache WHERE owner_user_id::bigint=1",&[]).await?;},
    "bsky_disconnected"=>{db.execute("DELETE FROM bsky_sessions WHERE owner_user_id::bigint=1",&[]).await?;},
    "bsky_reconnected"=>{db.execute("UPDATE bsky_sessions SET did='did:plc:replacement' WHERE owner_user_id::bigint=1",&[]).await?;},
    _=>anyhow::bail!("unknown server-owned mutation"),
   };Ok(vec![Ipv4Addr::new(8,8,8,8)])
  })}
 }
 #[tokio::test]
 #[ignore="owned PostgreSQL genuine saved producer corpus; explicit fixture paths required"]
 async fn actual_saved_caller_dns_withdrawal()->Result<()>{
  let baseline:Value=serde_json::from_slice(&std::fs::read(std::env::var("VAAK_SAVED_FAULT_REFERENCE")?)?)?;
  let request_prefix=std::env::var("VAAK_SAVED_FAULT_REQUEST_PREFIX")?;
  let dburl=std::env::var("VAAK_SAVED_FAULT_DB")?;let admin=crate::db::connect(&dburl).await?;let mut results=Vec::new();
  let mutations=if std::env::var("VAAK_SAVED_NETWORK_FAULTS").ok().as_deref()==Some("1"){vec!["bookmark_removed","bsky_cache_removed","bsky_disconnected","bsky_reconnected","owner_revoked","network_window_shift","network_cursor_sentinel"]}else{vec!["bookmark_removed","bsky_cache_removed","bsky_disconnected","bsky_reconnected"]};
  for width in ["INTEGER","BIGINT"]{for mutation in mutations.iter().copied(){
   let bsky=mutation.starts_with("bsky_");let case=if bsky{"saved_bsky"}else{"saved_ap"};
   let snapshot=baseline["captures"].as_array().unwrap().iter().find(|row|row["width"]==width&&row["case"]==case).context("actual saved fixture capture")?;
   let schema=format!("saved_fault_{}_{}_{}",width.to_ascii_lowercase(),std::process::id(),mutation);
   admin.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await?;
   let url=format!("{dburl} options=-csearch_path={schema}");
   let run:Result<Value>=async{
    for (table,columns) in snapshot["current_schema"].as_object().unwrap(){
     anyhow::ensure!(table.bytes().all(|byte|byte.is_ascii_alphanumeric()||byte==b'_'),"fixture table identifier");
     let mut definitions=Vec::new();for col in columns.as_array().unwrap(){let name=col["column_name"].as_str().unwrap();let kind=col["data_type"].as_str().unwrap();anyhow::ensure!(name.bytes().all(|byte|byte.is_ascii_alphanumeric()||byte==b'_')&&matches!(kind,"text"|"integer"|"bigint"),"fixture column grammar");definitions.push(format!("{name} {kind}"));}
     admin.batch_execute(&format!("CREATE TABLE {table}({})",definitions.join(","))).await?;
     if let Some(rows)=snapshot["after_tables"].get(table).and_then(Value::as_array){for row in rows{admin.execute(&format!("INSERT INTO {table} SELECT * FROM json_populate_record(NULL::{table},$1::json)"),&[row]).await?;}}
    }
    admin.batch_execute("INSERT INTO bsky_sessions(owner_user_id,handle,did,pds_host,access_jwt_enc,refresh_jwt_enc,connected_at,updated_at) VALUES(1,'viewer.test','did:plc:viewer','https://pds.test','fixture-not-credentials','fixture-not-credentials','2026-10-10','2026-10-10')").await?;
    let lines=std::fs::read_to_string(format!("{request_prefix}-{}-requests.jsonl",width.to_ascii_lowercase()))?;
    let request=lines.lines().map(|line|serde_json::from_str::<Value>(line).unwrap()).find(|request|{
     let body=&request["request"];if bsky{body["statuses"][0]["post"].is_object()&&body["statuses"].as_array().is_some_and(|items|items.len()==1)}else{body["statuses"][0]["id"]=="saved-ap-123"&&body["saved_sources"][0]["folder_id"]==0&&body["statuses"].as_array().is_some_and(|items|items.len()==1)}
    }).context("actual native private batch")?;
    let mut body=request["request"].clone();body.as_object_mut().unwrap().remove("_fixture_path");
    // Distinct fault-only source URL, retained in the test artifact. This
    // invokes actual eligible DNS; it does not substitute a preview result.
    if bsky{body["statuses"][0]["post"]["record"]["text"]=serde_json::json!("Saved public text https://withdrawal-preview.test/page");}
    else{body["statuses"][0]["content"]=serde_json::json!("<p>Saved public text https://withdrawal-preview.test/page</p>");}
    let dns=MutationDns{url:url.clone(),mutation,calls:0.into()};let clock=chrono::DateTime::from_timestamp(1791673200,0).unwrap();let deps=crate::direct_preview::PreviewDependencies{dns:&dns,render_clock:Some(clock)};
    let cfg=crate::config::Config{database_url:url.clone(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:1,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
    let warm=Arc::new(crate::profile_enrichment::WarmContext::new(cfg,Arc::new(NoWorkers)).with_fixture_clock(clock));
    let mut headers=axum::http::HeaderMap::new();headers.insert("X-VAAK-Library-Token","saved-fault-private-token-0000000001".parse()?);
    let response=crate::library::bridge::fixture_request_with_warm(&url,"saved-fault-private-token-0000000001",headers,axum::body::Bytes::from(serde_json::to_vec(&body)?),&deps,warm.clone()).await;
    anyhow::ensure!(response.status()==axum::http::StatusCode::UNPROCESSABLE_ENTITY,"mutation did not reject native publication");
    anyhow::ensure!(response.headers().get(axum::http::header::CACHE_CONTROL).and_then(|value|value.to_str().ok())==Some("private, no-store"),"private failure cache policy");
    let reply:Value=serde_json::from_slice(&axum::body::to_bytes(response.into_body(),1024).await?)?;
    anyhow::ensure!(reply==serde_json::json!({"fallback":true})&&dns.calls.load(std::sync::atomic::Ordering::SeqCst)==1,"actual DNS/reply path");
    anyhow::ensure!(warm.requested_actors().is_empty()&&warm.requested_media().is_empty(),"unrelated enrichment effect");
    Ok(serde_json::json!({"width":width,"mutation":mutation,"request":body,"reply":reply,"native_status":422,"dns_calls":1,"actor_warm":[],"media_warm":[],"scope":"actual private handler + server-owned DNS-time current SQL revocation; declared source text extension/session/worker delivery; ordinary PHP fallback paint is outside this fault gate"}))
   }.await;
   admin.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await?;results.push(run?);
  }}
  std::fs::write(std::env::var("VAAK_SAVED_FAULT_OUTPUT")?,serde_json::to_vec_pretty(&results)?)?;Ok(())
 }
}
