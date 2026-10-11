#!/usr/bin/env python3
"""Emit an independent CLI importing actual private Direct modules.
Only a declared frozen clock, public DNS answers and disposable SQL transport
are supplied. It never rewrites source statuses or PHP expected output.
"""
import json,pathlib,re,subprocess,sys
root=pathlib.Path(__file__).resolve().parents[2];src=root/'rust/vaak-worker/src'
if len(sys.argv)>1 and sys.argv[1]=='--prepare-runtime':
 runtime=pathlib.Path('/tmp/vaak-direct-independent-runtime');(runtime/'src').mkdir(parents=True,exist_ok=True)
 manifest=(src.parent/'Cargo.toml').read_text().replace('name = "vaak-worker"','name = "remote-direct-independent"').replace('path = "../vaak-types"','path = '+json.dumps(str(root/'rust/vaak-types')))
 (runtime/'Cargo.toml').write_text(manifest);(runtime/'src/main.rs').write_bytes(subprocess.check_output([sys.executable,__file__]))
 print(runtime);raise SystemExit()

for name in re.findall(r'^mod (\w+);',(src/'main.rs').read_text(),re.M):
 if name in ('library','direct_context'):continue
 path=src/(name+'.rs');path=path if path.exists() else src/name/'mod.rs'
 print('#[path='+json.dumps(str(path))+'] mod '+name+';')
lib=(src/'library.rs').read_text();lib=re.sub(r'^//!','//',lib,flags=re.M);lib=re.sub(r'#\[path = "([^"]+)"\]',lambda m:'#[path = '+json.dumps(str(src/m[1]))+']',lib)
print('mod library { '+lib+'\n}')
context=(src/'direct_context.rs').read_text();context=re.sub(r'^//!','//',context,flags=re.M)
print('mod direct_context { '+context)
print(r'''
 struct IndependentDns<'a>{expected:Option<&'a str>,count:std::sync::atomic::AtomicUsize}
 impl crate::direct_preview::PreviewDns for IndependentDns<'_> {fn ipv4<'a>(&'a self,host:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<std::net::Ipv4Addr>>>+Send+'a>>{Box::pin(async move{self.count.fetch_add(1,std::sync::atomic::Ordering::Relaxed);if let Some(expected)=self.expected{if host!=expected{bail!("actual normalized DNS hostname mismatch: {host} != {expected}")}}Ok(vec![std::net::Ipv4Addr::new(8,8,8,8)])})}}
 struct MutationDns<'a>{db:&'a Client,action:&'a str,uri:&'a str,count:std::sync::atomic::AtomicUsize}
 impl crate::direct_preview::PreviewDns for MutationDns<'_>{fn ipv4<'a>(&'a self,_host:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<std::net::Ipv4Addr>>>+Send+'a>>{Box::pin(async move{self.count.fetch_add(1,std::sync::atomic::Ordering::Relaxed);match self.action{"follower"=>{self.db.batch_execute("DELETE FROM followers").await?;},"recipient"=>{self.db.execute("UPDATE outbox_notes SET to_json='[]',cc_json='[]' WHERE id=$1",&[&self.uri]).await?;},"source"=>{self.db.execute("DELETE FROM masto_statuses WHERE note_id=$1",&[&self.uri]).await?;},"owner"=>{self.db.batch_execute("UPDATE ap_users SET disabled_at='fixture-disabled' WHERE id=1").await?;},_=>bail!("unknown independent mutation")};Ok(vec![std::net::Ipv4Addr::new(8,8,8,8)])})}}
 pub(crate) async fn independent_run(db:&Client,row:&Value)->Result<Value>{
  let options=&row["context"];let view=row["view"].as_str().unwrap_or("status");
  let route=options["route"].as_str().unwrap_or(if view=="remote_profile"{"remote_profile"}else{"status"});
  let origin=options["return_view"].as_str().unwrap_or(view);
  let session=DirectSession::from_php_session(options["owner"].as_i64().unwrap_or(1),options["csrf"].as_str().unwrap_or("fixture-csrf-token").into(),if route=="status"{DirectRoute::Status}else{DirectRoute::RemoteProfile},origin.into(),options["focused"].as_bool().unwrap_or(view=="status"),options["show_open"].as_bool().unwrap_or(true),options["hide_header"].as_bool().unwrap_or(false),options["lazy_media"].as_bool().unwrap_or(false),options["get_actor"].as_str().unwrap_or("").into())?.with_status_preview_budget(row["initial_status_budget"].as_i64().unwrap_or(3))?;
  let sources=row.get("review_sources").and_then(Value::as_array).map(|v|v.clone()).unwrap_or_else(||vec![row["source"].clone()]);let original=sources.clone();
  let dns=IndependentDns{expected:row["review_dns_expected_host"].as_str(),count:std::sync::atomic::AtomicUsize::new(0)};let mutation=MutationDns{db,action:row["review_mutation"].as_str().unwrap_or(""),uri:row["source"]["uri"].as_str().unwrap_or(""),count:std::sync::atomic::AtomicUsize::new(0)};let result=if mutation.action.is_empty(){admit_for_render_with_dns(db,&session,sources,&dns).await}else{admit_for_render_with_dns(db,&session,sources,&mutation).await};if dns.expected.is_some(){assert!(dns.count.load(std::sync::atomic::Ordering::Relaxed)>0,"normalized YouTube DNS branch was not exercised");assert!(result.is_ok(),"normalized YouTube DNS control did not succeed");}let dns_count=mutation.count.load(std::sync::atomic::Ordering::Relaxed);
  match result {Err(error)=>Ok(serde_json::json!({"fallback":error.to_string(),"output_count":0,"original_sources":original,"mutation_dns_count":dns_count})),Ok(mut cards)=>{
   let final_budget=cards.final_status_preview_budget();let budget_changed=cards.status_preview_budget_changed();let mut rendered=Vec::new();for card in cards.iter_mut(){card.facts.now=chrono::DateTime::from_timestamp(options["rendered_at"].as_i64().or_else(||row["rendered_at"].as_i64()).unwrap_or_else(||chrono::DateTime::parse_from_rfc3339(row["source"]["created_at"].as_str().unwrap()).unwrap().timestamp()),0).unwrap();match crate::direct_render::render(card){Ok(html)=>rendered.push(serde_json::json!({"source_index":card.source_index(),"html":html})),Err(error)=>return Ok(serde_json::json!({"fallback":error.to_string(),"output_count":0,"original_sources":original,"mutation_dns_count":dns_count}))}}
   Ok(serde_json::json!({"cards":rendered,"output_count":rendered.len(),"status_link_preview_budget":final_budget,"status_preview_budget_changed":budget_changed,"original_sources":original,"mutation_dns_count":dns_count}))}}
 }
}
async fn restore(db:&tokio_postgres::Client,table:&str,rows:&serde_json::Value)->anyhow::Result<()>{
 db.batch_execute(&format!("DELETE FROM {table}")).await?;for row in rows.as_array().into_iter().flatten(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await?;}Ok(())
}
#[tokio::main] async fn main()->anyhow::Result<()>{
 use anyhow::{Context,bail};use serde_json::{Value,json};
 let url=std::env::var("LIBRARY_FIXTURE_DB")?;if !url.contains("/tmp/"){bail!("disposable local PostgreSQL required")}
 let db=crate::db::connect(&url).await?;db.batch_execute("SET synchronous_commit=off").await?;
 let production=std::env::var("DIRECT_PRODUCTION_REFERENCE").ok();
 let primary:Value=serde_json::from_slice(&std::fs::read(production.as_deref().unwrap_or("/tmp/vaak-remote-direct-card-reference.json"))?)?;
 let policy:Value=serde_json::from_slice(&std::fs::read(std::env::var("DIRECT_POLICY_REFERENCE").unwrap_or("/tmp/vaak-remote-direct-policy-complete-schema-independent.json".into()))?)?;
 let preview:Value=serde_json::from_slice(&std::fs::read(std::env::var("DIRECT_PREVIEW_REFERENCE").unwrap_or("/tmp/vaak-remote-direct-root-preview-complete-helpers-reference.json".into()))?)?;
 let batch:Value=serde_json::from_slice(&std::fs::read(std::env::var("DIRECT_BATCH_REFERENCE").unwrap_or("/tmp/vaak-remote-direct-batch-current-reference.json".into()))?)?;
 let nested:Value=serde_json::from_slice(&std::fs::read(std::env::var("DIRECT_NESTED_REFERENCE").unwrap_or("/tmp/vaak-remote-direct-nested-saved-quote-reference.json".into()))?)?;
 let shape:Value=serde_json::from_slice(&std::fs::read(std::env::var("DIRECT_SHAPE_REFERENCE").unwrap_or("/tmp/vaak-remote-direct-source-shape-reference.json".into()))?)?;
 let mut results=Vec::new();let selected=std::env::var("DIRECT_REVIEW_CORPORA").unwrap_or_default();
 for width in ["INTEGER","BIGINT"]{
 let schema=format!("direct_independent_{}",width.to_ascii_lowercase());db.batch_execute(&format!("DROP SCHEMA IF EXISTS {schema} CASCADE;CREATE SCHEMA {schema};SET search_path={schema}")).await?;
 // Accurate-role bootstrap SQL declared by the PHP independent fixture.
 let tables=if production.is_some(){primary["bootstrap_tables"].as_object()}else{policy["bootstrap_tables"].as_object()}.context("bootstrap tables")?;
 for (table,snapshot) in tables{let defs=snapshot["columns"].as_array().unwrap().iter().map(|c|format!("{} {}",c["name"].as_str().unwrap(),if c["type"].as_str().unwrap().to_ascii_uppercase().contains("INT")||(table=="actor_profile"&&c["name"]=="indexable"){width}else{"TEXT"})).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await?;restore(&db,table,&snapshot["rows"]).await?;}
 db.batch_execute("ALTER TABLE events ADD COLUMN IF NOT EXISTS quote_object TEXT;ALTER TABLE mentions ADD COLUMN IF NOT EXISTS actor_id TEXT;CREATE TABLE IF NOT EXISTS ap_user_blocks(id BIGINT,owner_user_id BIGINT,scope TEXT,value TEXT,created_at TEXT);CREATE TABLE IF NOT EXISTS ap_mutes(owner_user_id BIGINT,actor_id TEXT);CREATE TABLE IF NOT EXISTS ap_muted_words(owner_user_id BIGINT,phrase TEXT);CREATE TABLE IF NOT EXISTS ap_deprioritized_actors(owner_user_id BIGINT,actor_id TEXT);CREATE TABLE IF NOT EXISTS ap_blocks(id BIGINT,scope TEXT,value TEXT,kind TEXT);CREATE TABLE IF NOT EXISTS ap_wafrn_friend_servers(host TEXT,enabled BIGINT);CREATE TABLE IF NOT EXISTS ap_asks(id BIGINT,answer_note_id TEXT,question TEXT,asker_actor TEXT);CREATE TABLE IF NOT EXISTS bsky_sessions(owner_user_id BIGINT,did TEXT,handle TEXT);CREATE TABLE IF NOT EXISTS quote_authorizations(deleted_at TEXT,quoted_note_id TEXT);CREATE TABLE IF NOT EXISTS bsky_crossposts(note_id TEXT,bsky_uri TEXT,created_at TEXT);ALTER TABLE bsky_posts ADD COLUMN IF NOT EXISTS like_count BIGINT DEFAULT 0;ALTER TABLE bsky_posts ADD COLUMN IF NOT EXISTS repost_count BIGINT DEFAULT 0;ALTER TABLE bsky_posts ADD COLUMN IF NOT EXISTS quote_count BIGINT DEFAULT 0;CREATE TABLE IF NOT EXISTS remote_media_cache(actor_id TEXT,kind TEXT,public_url TEXT);").await?;
 for (corpus,reference) in [("primary",&primary),("policy",&policy),("preview",&preview),("batch",&batch),("nested",&nested),("shape",&shape)]{if !selected.is_empty()&&!selected.split(',').any(|c|c==corpus){continue}for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width&&r.get("source").is_some()){
 if let Ok(cases)=std::env::var("DIRECT_REVIEW_CASES"){if !cases.split(',').any(|c|Some(c)==row["case"].as_str()){continue}}
 for (table,snapshot) in tables{restore(&db,table,&snapshot["rows"]).await?;}
 if let Some(current)=row["current_tables"].as_object(){for (table,rows) in current{restore(&db,table,rows).await?;}}
 else{let c=&row["authoritative_context"];for(table,field)in[("masto_favourites","saved_favourites"),("masto_bookmarks","saved_bookmarks"),("masto_reblogs","saved_reblogs"),("masto_statuses","local_status_rows"),("masto_polls","poll_rows"),("events","audience_events"),("outbox_notes","audience_outbox"),("following","following"),("bsky_posts","search_bsky_rows")]{restore(&db,table,&c[field]).await?;}
 db.execute("UPDATE actor_profile SET auto_unblur_sensitive=$1",&[&if crate::library::card::boolean(&c["profile"],"auto_unblur_sensitive"){"1"}else{"0"}]).await?;}
 if production.is_none(){restore(&db,"ap_user_blocks",&row["current_tables"]["fixture_blocks"]).await?;restore(&db,"ap_mutes",&row["current_tables"]["fixture_mutes"]).await?;restore(&db,"ap_muted_words",&row["current_tables"]["fixture_words"]).await?;
 let server=row["current_tables"]["fixture_server_blocks"].as_array().map(|v|v.iter().enumerate().map(|(i,x)|{let mut x=x.clone();x["id"]=json!(i+1);x}).collect::<Vec<_>>()).unwrap_or_default();restore(&db,"ap_blocks",&json!(server)).await?;}
 if production.is_none(){db.batch_execute("DELETE FROM remote_media_cache").await?;
 fn actors(v:&Value,out:&mut Vec<String>){if let Some(a)=v["account"]["uri"].as_str(){if !out.iter().any(|x|x==a){out.push(a.into());}}for key in ["reblog","quoted_status"]{if v[key].is_object(){actors(&v[key],out)}}if v["quote"]["quoted_status"].is_object(){actors(&v["quote"]["quoted_status"],out)}}
 let mut refs=Vec::new();actors(&row["source"],&mut refs);for actor in refs{if !actor.starts_with("https://mkultra.monster/users/"){let avatar=row["authoritative_context"]["resolved_author_avatar"].as_str().unwrap_or("https://mkultra.monster/img/avatar/default.webp");db.execute("INSERT INTO remote_media_cache(actor_id,kind,public_url) VALUES($1,'avatar',$2)",&[&actor,&avatar]).await?;}}}
 let result=direct_context::independent_run(&db,row).await?;results.push(json!({"corpus":corpus,"width":width,"case":row["case"],"mode":row["mode"],"view":row["view"],"initial_status_budget":row["initial_status_budget"],"initial_status_budget_present":row["initial_status_budget_present"],"initial_status_budget_type":row["initial_status_budget_type"],"lazy":row["context"]["lazy_media"],"source":row["source"],"review_sources":row["review_sources"],"php":row["php"],"direct_authority_allowed":row["direct_authority_allowed"],"root_content_blocked":row["root_content_blocked"],"result":result}));if results.len()%100==0{println!("independent Direct captures {}",results.len());}
 if corpus=="policy"&&(row["case"]=="local_private_follower"||row["case"]=="local_direct_recipient"){
 let actions:Vec<&str>=if row["case"]=="local_private_follower"{vec!["follower","source","owner"]}else{vec!["recipient"]};
 for action in actions{for(table,rows)in row["current_tables"].as_object().unwrap(){restore(&db,table,rows).await?;}let mut control=row.clone();control["source"]["content"]=json!("Mutation URL https://article.test/native-mutation");control["review_mutation"]=json!(action);control["initial_status_budget"]=json!(3);let uri=control["source"]["uri"].as_str().unwrap();db.execute("UPDATE masto_statuses SET content_text='Mutation URL https://article.test/native-mutation' WHERE note_id=$1",&[&uri]).await?;db.execute("UPDATE outbox_notes SET content='Mutation URL https://article.test/native-mutation' WHERE id=$1",&[&uri]).await?;db.batch_execute("DELETE FROM link_preview_cards;INSERT INTO link_preview_cards(url,title,description,type,status,expires_at) VALUES('https://article.test/native-mutation','Warm mutation card','Declared cache','link','ok','2099-01-01T00:00:00Z')").await?;let result=direct_context::independent_run(&db,&control).await?;assert!(result["mutation_dns_count"].as_u64().unwrap_or(0)>0,"mutation hook was not exercised");assert_eq!(result["output_count"],0,"late live authority withdrawal exposed native HTML");results.push(json!({"corpus":"mutation","width":width,"case":action,"source":control["source"],"result":result,"note":"Declared public DNS answer transport mutates live SQL; no permission or CardFacts injection"}));}
 }
 if corpus=="policy"&&row["case"]=="local_missing_row_cached"{
 if let Ok(path)=std::env::var("DIRECT_MIXED_LATE_DNS_SOURCE"){
  let genuine:Value=serde_json::from_str(&std::fs::read_to_string(path)?)?;
  let first=genuine["captures"].as_array().unwrap().iter().find(|r|r["source"]["uri"]=="https://remote.test/users/alice/statuses/123").context("actual earlier allowed source")?["source"].clone();
  let mut control=row.clone();control["review_sources"]=json!([first, row["source"].clone()]);control["review_mutation"]=json!("owner");
  let result=direct_context::independent_run(&db,&control).await?;
  assert!(result["mutation_dns_count"].as_u64().unwrap_or(0)>0,"final omitted slot DNS hook not reached");
  assert_eq!(result["output_count"],0,"earlier allowed card escaped owner withdrawal in final omitted slot");
  assert!(result["fallback"].is_string(),"inactive final owner must reject entire batch");
  results.push(json!({"corpus":"late_omitted_mutation","width":width,"case":"earlier_allowed_final_missing_cached_owner","source":row["source"],"review_sources":control["review_sources"],"result":result,"note":"Actual unchanged public remote source precedes actual missing local source; final cached DNS withdraws current owner before batch publication"}));
 }}

 }}db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await?;
 }std::fs::write(std::env::var("DIRECT_REVIEW_OUTPUT").unwrap_or("/tmp/vaak-remote-direct-independent-native.json".into()),serde_json::to_vec(&results)?)?;println!("independent actual Direct module captures {}",results.len());Ok(())
}
''')
