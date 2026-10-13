//! Request-private source binding for actual authenticated PHP Search sections.
//! Original display snapshots stay unchanged; withdrawn sources/policy fall back.
use anyhow::{bail,Context,Result};
use serde_json::{json,Value};
use tokio_postgres::Client;
use std::collections::HashMap;

#[derive(serde::Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct ComponentCollectorDescriptor {
    pub(crate) query:String,pub(crate) section:String,pub(crate) offset:usize,pub(crate) limit:usize,
    #[serde(default)] pub(crate) account_sources:Vec<String>,
}
pub(crate) struct ComponentsGrant {
    owner:i64,actor:String,key:String,accounts:Vec<Value>,tags:Vec<Value>,
    descriptor:ComponentCollectorDescriptor,account_html:Vec<String>,viewer_profile:Value,raw_scope:crate::search_context::selection::final_facts::Scope,raw_snapshot:Value,component_profile_dependency:bool,component_graph_snapshot:Value,
}
impl ComponentsGrant {
 pub(crate) async fn load(db:&Client,owner:i64,actor:String,key:String,accounts:&[Value],tags:&[Value],descriptor:ComponentCollectorDescriptor)->Result<Self>{
  if descriptor.query.is_empty()||descriptor.query.len()>4096||descriptor.offset>160||!(1..=40).contains(&descriptor.limit)
   ||!matches!(descriptor.section.as_str(),""|"accounts"|"hashtags"|"statuses")
   ||accounts.len()>descriptor.limit||tags.len()>descriptor.limit||descriptor.account_sources.len()!=accounts.len()
   ||(!matches!(descriptor.section.as_str(),""|"accounts")&&!accounts.is_empty())
   ||(!matches!(descriptor.section.as_str(),""|"hashtags")&&!tags.is_empty()) {bail!("invalid Search component collector")}
  let context=super::ComponentContext::load_bound(db,Some(owner),accounts,&descriptor.account_sources).await?;
  let component_profile_dependency=context.bsky_profile_dependency();
  let component_graph_snapshot=context.bsky_graph_snapshot().clone();
  let proof_refs=descriptor.account_sources.iter().flat_map(|provider|context.source_policy_refs(provider)).collect::<Vec<_>>();
  let mut raw_scope=crate::search_context::selection::final_facts::Scope::new(owner,&key,&actor,&[],accounts,&proof_refs,!tags.is_empty());
  raw_scope.bind_alias_queries(context.raw_alias_queries(&descriptor.account_sources));
  raw_scope.bind_component_profile_dependency(component_profile_dependency,&component_graph_snapshot);
  let raw_snapshot=raw_scope.read(db).await?;
  let account_html=accounts.iter().enumerate().map(|(index,account)|{context.require_source(account,&descriptor.account_sources[index])?;Ok(crate::search_render::render_account(&context.resolve_account(account)?))}).collect::<Result<Vec<_>>>()?;
  let viewer_profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1 LIMIT 1",&[&key]).await?.map(|r|r.get::<_,Value>(0)).unwrap_or(Value::Null);
  let grant=Self{owner,actor,key,viewer_profile,raw_scope,raw_snapshot,component_profile_dependency,component_graph_snapshot,accounts:accounts.to_vec(),tags:tags.to_vec(),descriptor,account_html};
  grant.require_current(db).await?;Ok(grant)
 }
 pub(crate) fn final_scope(&self,statuses:&[Value])->crate::search_context::selection::final_facts::Scope{
  let mut scope=crate::search_context::selection::final_facts::Scope::new(self.owner,&self.key,&self.actor,statuses,&self.accounts,&self.descriptor.account_sources,!self.tags.is_empty());
  scope.bind_component_profile_dependency(self.component_profile_dependency,&self.component_graph_snapshot);scope
 }
 pub(crate) fn raw_binding(&self)->(&crate::search_context::selection::final_facts::Scope,&Value){(&self.raw_scope,&self.raw_snapshot)}
 // Bind the bytes actually painted by the bridge's second context, not only
 // the first/final contexts. This equality is synchronous after final proof.
 pub(crate) fn require_painted_accounts(&self,painted:&[String])->Result<()>{
  if painted!=self.account_html.as_slice(){bail!("Search painted account facts differ from retained component grant")}Ok(())
 }

 pub(crate) async fn require_current(&self,db:&Client)->Result<()> {
  self.require_ordered_current(db).await?;
  let after=self.raw_scope.read(db).await?;
  crate::search_context::selection::final_facts::Scope::require_same(&self.raw_snapshot,&after,Some(&self.viewer_profile))
 }
 pub(crate) async fn require_ordered_current(&self,db:&Client)->Result<()> {
  if !self.tags.is_empty(){
   let current=current_tags(db,self.owner,&self.actor,&self.key,&self.descriptor).await?;
   let names:Vec<(&str,bool)>=self.tags.iter().map(|tag|(super::text(tag,"name"),crate::library::card::boolean(tag,"following"))).collect();
   if names!=current.iter().map(|(name,follow)|(name.as_str(),*follow)).collect::<Vec<_>>(){bail!("Search hashtag contributions/window/follow state changed")}
  }
  let context=super::ComponentContext::load_bound(db,Some(self.owner),&self.accounts,&self.descriptor.account_sources).await?;
  // Fresh owner actor/key binding, rather than a cached username/is_me bit.
  if context.owner_actor!=super::root(&self.actor){bail!("Search component owner actor changed")}
  let owner=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&self.owner]).await?.context("Search component owner withdrawn")?;
  if owner.get::<_,String>(0)!=self.actor||owner.get::<_,String>(1)!=self.key{bail!("Search component owner identity changed")}
  for (index,account) in self.accounts.iter().enumerate(){context.require_source(account,&self.descriptor.account_sources[index])?;}
  let source_refs=self.descriptor.account_sources.iter().map(|provider|context.source_policy_refs(provider)).collect::<Vec<_>>();
  crate::search_context::require_component_accounts(db,self.owner,&self.actor,&self.key,&self.accounts,&source_refs).await?;
  for (index,account) in self.accounts.iter().enumerate(){
   context.require_source(account,&self.descriptor.account_sources[index])?;
   if crate::search_render::render_account(&context.resolve_account(account)?)!=self.account_html[index]{bail!("Search account relationship/presentation changed")}
  }
  Ok(())
 }
}

async fn current_tags(db:&Client,owner:i64,actor:&str,key:&str,descriptor:&ComponentCollectorDescriptor)->Result<Vec<(String,bool)>> {
 static TAG:std::sync::OnceLock<regex::Regex>=std::sync::OnceLock::new();
 let tag=TAG.get_or_init(||regex::Regex::new(r"#([\p{L}\p{N}_]{1,100})").unwrap());
 let query=super::canonical_query::lowercase(super::trim(&descriptor.query).trim_start_matches('#'));
 let mut sources=Vec::new();let mut texts=Vec::new();
 for (outbox,sql,field,decode) in [
  (true,"SELECT to_jsonb(q) FROM (SELECT id,content,visibility FROM outbox_notes ORDER BY published DESC LIMIT 400) q","content",false),
  (false,"SELECT to_jsonb(q) FROM (SELECT id,object_id,actor_id,visibility,type,action_taken,summary FROM events WHERE summary IS NOT NULL AND summary != '' ORDER BY id DESC LIMIT 600) q","summary",true),
  (false,"SELECT to_jsonb(q) FROM (SELECT id,object_id,actor_id,deleted_at,content FROM mentions WHERE deleted_at IS NULL AND content IS NOT NULL ORDER BY id DESC LIMIT 300) q","content",false),
 ] {
  for row in db.query(sql,&[]).await? {
   let value:Value=row.try_get(0)?;let text=super::text(&value,field);let text=if decode{crate::html_entities::decode(text)}else{text.into()};
   if !tag.captures_iter(&text).any(|m|query.is_empty()||super::canonical_query::lowercase(&m[1]).contains(&query)){continue}
   let uri=super::text(&value,if outbox{"id"}else{"object_id"});
   let author=if outbox{uri.split("/notes/").next().unwrap_or("")}else{super::text(&value,"actor_id")};
   if uri.is_empty()||author.is_empty()||!super::text(&value,"deleted_at").is_empty()||super::text(&value,"action_taken")=="deleted"{continue}
   sources.push((outbox,json!({"uri":uri,"visibility":value.get("visibility").and_then(Value::as_str).unwrap_or("public"),"account":{"uri":author}})));texts.push(text);
  }
 }
 let allowed=crate::search_context::current_tag_contributions(db,owner,actor,key,&sources).await?;
 let mut counts:HashMap<String,(usize,usize)>=HashMap::new();let mut sequence=0;
 for (text,allowed) in texts.iter().zip(allowed){if !allowed{continue}for m in tag.captures_iter(text){let name=super::canonical_query::lowercase(&m[1]);let entry=counts.entry(name).or_insert_with(||{let order=sequence;sequence+=1;(0,order)});entry.0+=1;}}
 let mut ordered=counts.into_iter().filter(|(name,_)|query.is_empty()||name.contains(&query)).collect::<Vec<_>>();
 ordered.sort_by(|a,b|b.1.0.cmp(&a.1.0).then(a.1.1.cmp(&b.1.1)));
 static CHAR:std::sync::OnceLock<regex::Regex>=std::sync::OnceLock::new();let valid=CHAR.get_or_init(||regex::Regex::new(r"^[\p{L}\p{N}_]$").unwrap());
 let names=ordered.into_iter().take(80).skip(descriptor.offset).take(descriptor.limit).map(|(name,_)|name.chars().filter(|c|valid.is_match(&c.to_string())).take(100).collect::<String>()).collect::<Vec<_>>();
 let shape=crate::search_context::selection::final_facts::followed_tag_shape(db).await?;
 let followed=if crate::search_context::selection::final_facts::tag_shape_supported(&shape){db.query("SELECT name FROM masto_followed_tags WHERE owner_user_id=$1::bigint AND name=ANY($2::text[])",&[&owner,&names]).await?.into_iter().map(|r|r.get::<_,String>(0)).collect::<std::collections::HashSet<_>>()}else{std::collections::HashSet::new()};
 Ok(names.into_iter().map(|name|{let follow=followed.contains(&name);(name,follow)}).collect())
}

#[cfg(test)]
mod actual_fixture {
 use super::*;
 fn complete_dom_equal(root:&std::path::Path,left:&Value,right:&Value)->Result<bool>{
  let script="import importlib.util,json,sys; s=importlib.util.spec_from_file_location('original',sys.argv[1]);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);a,b=json.load(sys.stdin);sys.exit(0 if m.Tokens(a).tokens==m.Tokens(b).tokens else 1)";
  let mut child=std::process::Command::new("python3").args(["-B","-c",script]).arg(root.join("api/bin/search-native-acceptance-review.py")).stdin(std::process::Stdio::piped()).spawn()?;
  use std::io::Write;child.stdin.take().context("DOM comparator input")?.write_all(&serde_json::to_vec(&[left,right])?)?;
  Ok(child.wait()?.success())
 }

 use axum::{body::Bytes,http::HeaderMap,response::Response,extract::State};
 use std::sync::{Arc,Mutex};
 struct MixedStop{key:String,receipt:String,cancel:tokio::sync::watch::Sender<bool>,shutdown:Mutex<Option<tokio::sync::oneshot::Sender<()>>>,active:std::sync::atomic::AtomicUsize,listener:Mutex<Option<std::net::SocketAddr>>}
 struct ActiveRender(Arc<MixedStop>);impl Drop for ActiveRender{fn drop(&mut self){self.0.active.fetch_sub(1,std::sync::atomic::Ordering::SeqCst);}}
 async fn stop_handler(State(state):State<Fixture>,headers:HeaderMap)->Response{
  let Some(stop)=state.stop else{return Response::builder().status(404).body(axum::body::Body::empty()).unwrap()};
  if headers.get("X-Fixture-Stop-Key").and_then(|v|v.to_str().ok())!=Some(stop.key.as_str()){return Response::builder().status(403).body(axum::body::Body::empty()).unwrap()}
  let _=stop.cancel.send(true);if let Some(sender)=stop.shutdown.lock().unwrap().take(){let _=sender.send(());}
  let drained=tokio::time::timeout(std::time::Duration::from_secs(3),async{
   let address=(*stop.listener.lock().unwrap()).context("owned native listener address")?;
   loop{if stop.active.load(std::sync::atomic::Ordering::SeqCst)==0&&tokio::net::TcpListener::bind(address).await.is_ok(){break}tokio::time::sleep(std::time::Duration::from_millis(10)).await;}Ok::<(),anyhow::Error>(())
  }).await.is_ok_and(|result|result.is_ok());
  if drained{
   let receipt=json!({"key":stop.key,"active_renderers":0,"listener_shutdown_requested":true,"listener_closed":true});
   if (std::fs::OpenOptions::new().write(true).create_new(true).open(&stop.receipt).and_then(|mut file|{use std::io::Write;file.write_all(&serde_json::to_vec(&receipt).unwrap())})).is_err(){return Response::builder().status(500).body(axum::body::Body::empty()).unwrap()}
  }
  Response::builder().status(if drained{200}else{503}).body(axum::body::Body::from(format!("{{\"active_renderers_zero\":{drained}}}"))).unwrap()
 }
 struct OwnedTask(tokio::task::AbortHandle);impl Drop for OwnedTask{fn drop(&mut self){self.0.abort();}}
 #[derive(Clone)] struct Fixture {database:String,receipts:Arc<Mutex<Vec<Value>>>,fault:Option<String>,boundary:Option<Arc<FinalBoundaryMutation>>,cancel:Option<tokio::sync::watch::Receiver<bool>>,stop:Option<Arc<MixedStop>>}
 struct FinalBoundaryMutation{phase:String,case:String,schema:String,root:std::path::PathBuf,hits:std::sync::atomic::AtomicUsize,armed:std::sync::atomic::AtomicBool,settings:Mutex<Option<Value>>}
 impl crate::search_context::selection::final_facts::boundary_fixture::Hook for FinalBoundaryMutation{
  fn at<'a>(&'a self,db:&'a Client,phase:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{
   Box::pin(async move{
    if self.case=="preview_shape_aba"&&phase=="after_status_before_final_raw"&&self.armed.load(std::sync::atomic::Ordering::SeqCst){
     anyhow::ensure!(self.schema.starts_with("search_components_collector_"),"owned preview fixture only");
     db.batch_execute("ALTER TABLE link_preview_cards RENAME COLUMN url TO preview_owned_url").await?;return Ok(())
    }
    if phase!=self.phase||!self.armed.load(std::sync::atomic::Ordering::SeqCst)||self.hits.fetch_add(1,std::sync::atomic::Ordering::SeqCst)>0{return Ok(())}
    anyhow::ensure!((self.schema.starts_with("search_components_collector_")||self.schema.starts_with("search_settings_")),"owned boundary schema only");
    match self.case.as_str(){
     "unchanged"=>{},
     "preview_edit"=>db.batch_execute("UPDATE link_preview_cards SET title='Terminal changed preview' WHERE url='https://component-await.test/card'").await?,
     "preview_shape_aba"=>{
      let oid:i64=db.query_one("SELECT 'link_preview_cards'::regclass::oid::bigint",&[]).await?.get(0);
      db.batch_execute("ALTER TABLE link_preview_cards RENAME COLUMN preview_owned_url TO url;UPDATE link_preview_cards SET title='Terminal changed preview' WHERE url='https://component-await.test/card'").await?;
      let restored_oid:i64=db.query_one("SELECT 'link_preview_cards'::regclass::oid::bigint",&[]).await?.get(0);anyhow::ensure!(oid==restored_oid,"same original cache OID restored");
     },
     "nourl_source_deleted"|"nourl_query_error"=>{
      let before:Vec<Value>=db.query("SELECT to_jsonb(e) FROM events e WHERE object_id='https://jforo.com/users/pg/statuses/117426503498331076' ORDER BY id",&[]).await?.into_iter().map(|row|row.get(0)).collect();
      anyhow::ensure!(!before.is_empty(),"genuine Open source must exist before withdrawal");
      let profile_oid:i64=db.query_one("SELECT 'actor_profile'::regclass::oid::bigint",&[]).await?.get(0);
      if self.case=="nourl_source_deleted"{db.batch_execute("DELETE FROM events WHERE object_id='https://jforo.com/users/pg/statuses/117426503498331076';DELETE FROM mentions WHERE object_id='https://jforo.com/users/pg/statuses/117426503498331076';DELETE FROM masto_statuses WHERE note_id='https://jforo.com/users/pg/statuses/117426503498331076'").await?;}else{db.batch_execute("ALTER TABLE actor_profile RENAME TO nourl_owned_profile_fault").await?;}
      let after:Vec<Value>=db.query("SELECT to_jsonb(e) FROM events e WHERE object_id='https://jforo.com/users/pg/statuses/117426503498331076' ORDER BY id",&[]).await?.into_iter().map(|row|row.get(0)).collect();
      let profile_present:bool=db.query_one("SELECT to_regclass('actor_profile') IS NOT NULL",&[]).await?.get(0);
      anyhow::ensure!(if self.case=="nourl_source_deleted"{after.is_empty()}else{!profile_present},"actual committed withdrawal");
      let config=self.settings.lock().unwrap().clone().context("owned raw mutation receipt path")?;use std::io::Write;
      let mut file=std::fs::OpenOptions::new().write(true).create_new(true).open(config["receipt_path"].as_str().context("owned raw receipt")?)?;
      file.write_all(&serde_json::to_vec_pretty(&json!({"case":self.case,"phase":phase,"before_source":before,"after_source":after,"profile_oid_before":profile_oid,"profile_present_after":profile_present,"direct_SQL_fixture_mutation_not_user_action":true}))?)?;
     },
     "canonical_source_withdrawn"=>db.batch_execute("DELETE FROM outbox_notes WHERE id='https://mkultra.monster/users/fixture/notes/456';DELETE FROM masto_statuses WHERE local_id=456").await?,
     "preview_table_removed"=>db.batch_execute("DROP TABLE link_preview_cards").await?,

     "restored"=>db.batch_execute("BEGIN;DELETE FROM remote_actors WHERE actor_id='https://alias-collector.test/users/raw_peer';ROLLBACK;").await?,
     "provider_deleted"=>db.batch_execute("DELETE FROM remote_actors WHERE actor_id='https://alias-collector.test/users/raw_peer'").await?,
     "raw_block"|"raw_mute"=>{
      let mode=if self.case=="raw_block"{"remote_alias_block_action"}else{"remote_alias_mute_action"};
      let output=tokio::process::Command::new(self.root.join("api/bin/php-policy-test")).args(["-d","extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so"]).arg(self.root.join("api/bin/search-component-action-fixture.php")).args([&self.schema,mode]).output().await?;
      anyhow::ensure!(output.status.success(),"actual boundary moderation action: {}",String::from_utf8_lossy(&output.stderr));
      let receipt:Value=serde_json::from_slice(&output.stdout)?;anyhow::ensure!(receipt["ok"]==true,"actual boundary moderation receipt");
     },
     "follow_action_post"=>{
      let config=self.settings.lock().unwrap().clone().context("owned distinct-session follow action")?;
      let own=&config["ownership"];let oid=own["oid"].as_str().context("owned OID")?.parse::<i64>()?;
      let token=own["token"].as_str().context("owned UUID")?;
      anyhow::ensure!(oid>0&&own["schema"].as_str()==Some(self.schema.as_str())&&own["owner"].as_str()==Some("postgres"),"exact owned action namespace");
      anyhow::ensure!(regex::Regex::new(r"^search_settings_final_boundary_(integer|bigint)_[0-9]+_[0-2]$")?.is_match(&self.schema)&&regex::Regex::new(r"^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$")?.is_match(token),"owned schema/UUID formats");
      // The actual bridge client is an ordinary fresh reader. Ownership is
      // checked in this single read; do not replace serving with an RR snapshot.
      let identity=db.query_one("SELECT n.nspname,n.oid::bigint,pg_get_userbyid(n.nspowner),obj_description(n.oid,'pg_namespace'),current_schema(),current_setting('search_path'),current_user FROM pg_namespace n WHERE n.nspname=$1 AND n.oid=$2::bigint::oid",&[&self.schema,&oid]).await?;
      anyhow::ensure!(identity.get::<_,String>(0)==self.schema&&identity.get::<_,i64>(1)==oid&&identity.get::<_,String>(2)=="postgres"&&identity.get::<_,Option<String>>(3).as_deref()==Some(token)&&identity.get::<_,String>(4)==self.schema&&identity.get::<_,String>(5)==self.schema&&identity.get::<_,String>(6)=="postgres","exact schema/search_path/OID/owner/UUID/current_user ownership");
      let family=config["family"].as_str().context("finite follow family")?;anyhow::ensure!(matches!(family,"accounts"|"hashtags"));
      let action_sql=if family=="accounts"{"SELECT to_jsonb(f) FROM following f WHERE owner_actor_id='https://mkultra.monster/users/article_fixture' AND actor_id='https://jforo.com/users/pg' ORDER BY owner_actor_id,actor_id,followed_at,host"}else{"SELECT to_jsonb(t) FROM masto_followed_tags t WHERE owner_user_id=201 AND name='games'"};
      let source_sql="SELECT jsonb_build_object('remote',COALESCE((SELECT jsonb_agg(to_jsonb(r) ORDER BY actor_id) FROM remote_actors r WHERE actor_id='https://jforo.com/users/pg'),'[]'::jsonb),'events',COALESCE((SELECT jsonb_agg(to_jsonb(e) ORDER BY id) FROM events e WHERE object_id='https://jforo.com/users/pg/statuses/117426503498331076'),'[]'::jsonb))";
      let before:Vec<Value>=db.query(action_sql,&[]).await?.into_iter().map(|r|r.get(0)).collect();anyhow::ensure!(!before.is_empty(),"followed baseline required");
      let source_before:Value=db.query_one(source_sql,&[]).await?.get(0);anyhow::ensure!(source_before["events"].as_array().is_some_and(|v|!v.is_empty())&&source_before["remote"].as_array().is_some_and(|v|!v.is_empty()),"genuine Open source and cached actor prerequisite");
      let fields:HashMap<String,String>=serde_json::from_value(config["fields"].clone())?;
      anyhow::ensure!(fields.get("action").map(String::as_str)==Some(if family=="accounts"{"unfollow_remote"}else{"unfollow_tag"})&&fields.get("csrf").is_some_and(|v|!v.is_empty()),"actual CSRF follow dispatcher");
      let url=reqwest::Url::parse(config["url"].as_str().context("owned writer URL")?)?;anyhow::ensure!(url.scheme()=="http"&&url.host_str()==Some("127.0.0.1"),"loopback writer only");
      let response=reqwest::Client::builder().redirect(reqwest::redirect::Policy::none()).timeout(std::time::Duration::from_millis(2400)).build()?.post(url).header("Cookie",config["cookie"].as_str().context("distinct writer cookie")?).form(&fields).send().await?;
      let status=response.status().as_u16();let body=response.bytes().await?;anyhow::ensure!(status==200,"actual follow POST status");
      let after:Vec<Value>=db.query(action_sql,&[]).await?.into_iter().map(|r|r.get(0)).collect();anyhow::ensure!(after.is_empty(),"actual committed follow withdrawal required, queued-only operation is insufficient");
      let source_after:Value=db.query_one(source_sql,&[]).await?.get(0);anyhow::ensure!(source_before==source_after,"selected source survives follow withdrawal");
      let receipt=json!({"phase":phase,"family":family,"request":config,"before_action_rows":before,"after_action_rows":after,"source_before":source_before,"source_after":source_after,"actual_POST_status":status,"actual_POST_body":String::from_utf8_lossy(&body),"remote_Undo_delivery_acceptance_claimed":false});
      use std::io::Write;let mut f=std::fs::OpenOptions::new().write(true).create_new(true).open(config["receipt_path"].as_str().context("owned committed follow receipt")?)?;f.write_all(&serde_json::to_vec_pretty(&receipt)?)?;
     },
     "settings_post"=>{
      let config=self.settings.lock().unwrap().clone().context("genuine same-owner second-session Settings POST configuration required")?;
      let url=reqwest::Url::parse(config["url"].as_str().context("Settings URL")?)?;
      anyhow::ensure!(url.scheme()=="http"&&url.host_str()==Some("127.0.0.1"),"owned second SAPI only");
      let owner=config["owner"].as_i64().unwrap_or(1);let key=config["key"].as_str().unwrap_or("fixture");
      let owner_before:Value=db.query_one("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint",&[&owner]).await?.get(0);
      let profile_before:Value=db.query_one("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&key]).await?.get(0);
      let fields:HashMap<String,String>=serde_json::from_value(config["fields"].clone())?;
      anyhow::ensure!(fields.get("action").map(String::as_str)==Some("save_profile")&&fields.get("csrf").is_some_and(|value|!value.is_empty()),"actual CSRF-bound Settings action");
      let response=reqwest::Client::builder().redirect(reqwest::redirect::Policy::none()).build()?.post(url).header("Cookie",config["cookie"].as_str().context("second authenticated session cookie")?).form(&fields).send().await?;
      anyhow::ensure!(response.status().is_success(),"actual Settings POST refused");let post_status=response.status().as_u16();let post_body=response.bytes().await?;
      let owner_after:Value=db.query_one("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint",&[&owner]).await?.get(0);
      let profile_after:Value=db.query_one("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&key]).await?.get(0);
      let receipt_path=config["receipt_path"].as_str().context("owned Settings raw receipt path")?;
      let receipt=json!({"scope":"actual second-session save_profile POST; full independently committed rows", "phase":phase,"owner":owner,"key":key,"request":config,"owner_before":owner_before,"owner_after":owner_after,"profile_before":profile_before,"profile_after":profile_after,"post_status":post_status,"post_body":String::from_utf8_lossy(&post_body)});
      use std::io::Write;let mut file=std::fs::OpenOptions::new().write(true).create_new(true).open(receipt_path)?;file.write_all(&serde_json::to_vec_pretty(&receipt)?)?;
      anyhow::ensure!(owner_before==owner_after&&profile_before!=profile_after,"genuine viewer Settings withdrawal must be isolated from owner changes");

     },_=>bail!("unknown final boundary action")
    }
    Ok(())
   })
  }
 }
 struct MutationDns<'a>{database:&'a str,sql:Option<&'a str>,hits:&'a std::sync::atomic::AtomicUsize}
 impl crate::direct_preview::PreviewDns for MutationDns<'_>{
  fn ipv4<'a>(&'a self,host:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<std::net::Ipv4Addr>>>+Send+'a>>{
   Box::pin(async move{anyhow::ensure!(host=="component-await.test","unexpected fixture DNS");let count=self.hits.fetch_add(1,std::sync::atomic::Ordering::SeqCst);if count==0 {if let Some(sql)=self.sql{let db=crate::db::connect(self.database).await?;db.batch_execute(sql).await?;}}Ok(vec![std::net::Ipv4Addr::new(93,184,216,34)])})
  }
 }
 // The original collector oracle declares only HTTP-null and warm-record
 // primitives. This server-only recorder preserves those exact boundaries;
 // production uses ProcessPreviewTransport instead of this test capability.
 struct OriginalPreviewTransport{calls:Arc<Mutex<Vec<Value>>>}
 impl crate::profile_preview_fetch::PreviewFetchTransport for OriginalPreviewTransport{
  fn http_get<'a>(&'a self,url:&'a str,timeout:u64,max_bytes:usize,grant:&'a dyn crate::profile_quote_warm::CurrentQuoteGrant)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Option<String>>>+Send+'a>>{Box::pin(async move{grant.require_current().await?;let effect=std::env::var("SEARCH_PREVIEW_EFFECT_CASE").unwrap_or_default();
   let body=if url=="https://component-await.test/card"&&matches!(effect.as_str(),"ordinary"|"canonical"){
    let canonical=if effect=="canonical"{"https://component-await.test/Éclair"}else{url};
    Some(format!("<!doctype html><html><head><meta property=\"og:title\" content=\"Actual owned preview\"><meta property=\"og:description\" content=\"Original collector cold OG response\"><meta property=\"og:url\" content=\"{canonical}\"><meta property=\"og:site_name\" content=\"Owned preview\"></head></html>"))
   }else{None};
   self.calls.lock().unwrap().push(json!({"kind":if body.is_some(){"http_body"}else{"http_null"},"url":url,"timeout":timeout,"max_bytes":max_bytes}));grant.require_current().await?;Ok(body)})}
  fn warm<'a>(&'a self,url:&'a str,grant:&'a dyn crate::profile_quote_warm::CurrentQuoteGrant)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<bool>>+Send+'a>>{Box::pin(async move{grant.require_current().await?;self.calls.lock().unwrap().push(json!({"kind":"warm_record","url":url}));grant.require_current().await?;Ok(false)})}
 }
 fn original_configured_warm(database:&str,calls:Arc<Mutex<Vec<Value>>>)->Arc<crate::profile_enrichment::WarmContext>{
  let cfg=crate::config::Config{database_url:database.into(),redis_url:std::env::var("VAAK_REDIS_URL").unwrap_or_else(|_|"redis://127.0.0.1:1/0".into()),redis_queue_url:std::env::var("VAAK_REDIS_QUEUE_URL").unwrap_or_else(|_|"redis://127.0.0.1:1/1".into()),jetstream_state:Default::default(),default_owner_id:1,bsky_public_api:String::new(),notif_cache_dir:Default::default(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
  Arc::new(crate::profile_enrichment::WarmContext::new(cfg,Arc::new(crate::profile_warm::ProcessWarmTransport::configured())).with_preview_context(Arc::new(crate::profile_preview_fetch::PreviewFetchContext::new(Arc::new(OriginalPreviewTransport{calls})))) )
 }
 async fn handler(State(state):State<Fixture>,headers:HeaderMap,body:Bytes)->Response {
  let _active=state.stop.as_ref().map(|stop|{stop.active.fetch_add(1,std::sync::atomic::Ordering::SeqCst);ActiveRender(stop.clone())});
  let request:Value=serde_json::from_slice(&body).unwrap();
  let started=std::time::Instant::now();
  let hits=std::sync::atomic::AtomicUsize::new(0);
  let dns=MutationDns{database:&state.database,sql:state.fault.as_deref(),hits:&hits};
  let clock=std::env::var("VAAK_SEARCH_CROSSCALLER_CLOCK").ok().map(|value|chrono::DateTime::parse_from_rfc3339(&value).map(|stamp|stamp.with_timezone(&chrono::Utc))).transpose().unwrap().unwrap_or_else(||chrono::DateTime::from_timestamp(1791673200,0).unwrap());let deps=crate::direct_preview::PreviewDependencies{dns:&dns,render_clock:Some(clock)};
  let preview_calls=Arc::new(Mutex::new(Vec::<Value>::new()));
  let warm=if std::env::var("VAAK_SEARCH_CONFIGURED_WARM_FIXTURE").as_deref()==Ok("1"){Some(original_configured_warm(&state.database,preview_calls.clone()))}else{None};
  let render=async{
   if let Some(warm)=warm{crate::search_bridge::fixture_request_with_warm(&state.database,"independent-component-collector-fixture-secret-0001",headers,body,&deps,warm).await}else{crate::search_bridge::fixture_request(&state.database,"independent-component-collector-fixture-secret-0001",headers,body,&deps).await}
  };
  let guarded=async{if let Some(hook)=&state.boundary{crate::search_context::selection::final_facts::boundary_fixture::run(hook.clone(),render).await}else{render.await}};
  let metrics=crate::search_context::selection::fixture_performance::new();
  let mut performance_report=crate::search_context::selection::fixture_performance::RequestReport::new(metrics.clone(),request["statuses"].as_array().map(Vec::len),started);
  let guarded=crate::search_context::selection::fixture_performance::run(metrics.clone(),guarded);
  let response=if let Some(mut cancel)=state.cancel.clone(){tokio::select!{
   response=guarded=>response,
   _=cancel.changed()=>{performance_report.cancelled();Response::builder().status(503).body(axum::body::Body::from("{\"fallback\":\"owned fixture supervisor cancelled in-flight request\"}")).unwrap()},
  }}else{guarded.await};
  let (parts,body)=response.into_parts();let bytes=axum::body::to_bytes(body,8*1024*1024).await.unwrap();let reply:Value=serde_json::from_slice(&bytes).unwrap();
  let native_html_bytes=reply["statuses"].as_array().map(|rows|rows.iter().map(|r|r["html"].as_str().unwrap_or("").len()).sum::<usize>()).unwrap_or(0)+reply["accounts"].as_array().map(|rows|rows.iter().map(|r|r.as_str().unwrap_or("").len()).sum::<usize>()).unwrap_or(0);
  let response=Response::from_parts(parts,axum::body::Body::from(bytes));

  state.receipts.lock().unwrap().push(json!({"request":request,"status":response.status().as_u16(),"elapsed_seconds":started.elapsed().as_secs_f64(),"native_html_bytes":native_html_bytes,"boundary_hits":state.boundary.as_ref().map(|h|h.hits.load(std::sync::atomic::Ordering::SeqCst)),"status_preview_state":reply["status_preview_state"],"preview_dns_calls":hits.load(std::sync::atomic::Ordering::SeqCst),"preview_transport_calls":preview_calls.lock().unwrap().clone()}));performance_report.complete(response.status().as_u16());response
 }
 mod batch_owned_namespace_guard{include!("search_batch_owned_namespace_fixture.rs");}
 #[tokio::test]
 #[ignore="external reviewed fixture owner/OID/UUID lifecycle and actual PHP constructor DTOs required"]
 async fn actual_fresh_full_search_policy_differential_external_owned()->Result<()>{
  let input_path=std::env::var("VAAK_SEARCH_BATCH_DIFFERENTIAL_INPUT")?;let output_path=std::env::var("VAAK_SEARCH_BATCH_DIFFERENTIAL_OUTPUT")?;
  let bytes=std::fs::read(&input_path)?;anyhow::ensure!(bytes.len()<=2*1024*1024,"bounded actual input");let input:Value=serde_json::from_slice(&bytes)?;
  let namespace=input["schema"].as_str().context("owned schema")?;anyhow::ensure!(regex::Regex::new(r"^search_settings_final_boundary_(integer|bigint)_[0-9]+_[0-2]$")?.is_match(namespace),"exact fixture namespace");
  let oid=input["oid"].as_str().context("owned OID")?.parse::<i64>()?;let token=input["token"].as_str().context("owned UUID")?;
  let database=format!("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options='-c search_path={namespace}'");let db=crate::db::connect(&database).await?;
  db.batch_execute("BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;SET LOCAL statement_timeout='5s';SET LOCAL lock_timeout='1s'").await?;
  let result=async{
   batch_owned_namespace_guard::require(&db,namespace,oid).await?;
   let row=db.query_one("SELECT pg_get_userbyid(nspowner),obj_description(oid,'pg_namespace') FROM pg_namespace WHERE oid=$1::bigint::oid",&[&oid]).await?;
   anyhow::ensure!(row.get::<_,String>(0)=="postgres"&&row.get::<_,Option<String>>(1).as_deref()==Some(token),"owner/UUID differs");
   let owner=input["owner"].as_i64().context("actual owner")?;let statuses=input["statuses"].as_array().context("actual PHP DTOs")?;let label=input["label"].as_str().context("finite case label")?;let mut results=serde_json::Map::new();
   if input["audience"].as_bool().unwrap_or(true){results.insert("audience".into(),crate::audience::batch_differential::run(&db,owner,statuses,label,input["expect_conflict"].as_bool().unwrap_or(false)).await?);}
   if input["hidden"].as_bool().unwrap_or(true){let actors=statuses.iter().filter_map(|s|s["account"]["uri"].as_str().map(str::to_owned)).collect::<Vec<_>>();results.insert("hidden".into(),crate::hidden::batch_differential::run(&db,owner,statuses,&actors,label,input["expected_hidden_error"].as_str()).await?);}
   if input["scope"].as_bool().unwrap_or(false){let actor=input["actor"].as_str().context("current actor")?;let key=input["key"].as_str().context("current key")?;let scope=crate::search_context::selection::final_facts::Scope::new(owner,key,actor,statuses,&[],&[],false);let timing=crate::search_context::selection::fixture_performance::DifferentialTimer::new("external_owned_scope_read",statuses.len());let scope_result=scope.read(&db).await;timing.finish(&scope_result);let snapshot=scope_result?;if let Some(before)=input.get("scope_before"){anyhow::ensure!(crate::search_context::selection::final_facts::Scope::require_same(before,&snapshot,None).is_err(),"prior raw witness must withdraw");}results.insert("scope_snapshot".into(),snapshot);}
   Ok::<_,anyhow::Error>(Value::Object(results))
  }.await;
  db.batch_execute("ROLLBACK").await?;let results=result?;use std::io::Write;let mut output=std::fs::OpenOptions::new().write(true).create_new(true).open(&output_path)?;
  output.write_all(&serde_json::to_vec_pretty(&json!({"input_sha256":format!("{:x}",<sha2::Sha256 as sha2::Digest>::digest(&bytes)),"schema":namespace,"oid":oid,"label":input["label"],"results":results,"read_only_repeatable_read":true,"no_native_HTML_or_authority_grant":true}))?)?;Ok(())
 }

 #[tokio::test]
 #[ignore="owned PostgreSQL socket and genuine original PHP producer receipt required"]
 async fn actual_component_collector_to_native_complete_php_callers()->Result<()> {
  let baseline=std::env::var("VAAK_SEARCH_COMPONENT_REFERENCE")?;
  let output=std::env::var("VAAK_SEARCH_COMPONENT_OUTPUT")?;
  let expected:Value=serde_json::from_slice(&std::fs::read(&baseline)?)?;
  let root=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../..");
  let suffix=std::process::id().to_string();let receipts=Arc::new(Mutex::new(Vec::<Value>::new()));let mut servers=Vec::new();let mut urls=Vec::new();
  for width in ["INTEGER","BIGINT"] {
   let schema=format!("search_components_collector_{}_{}",width.to_ascii_lowercase(),suffix);
   let database=format!("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options='-c search_path={schema}'");
   let state=Fixture{database,receipts:receipts.clone(),fault:None,boundary:None,cancel:None,stop:None};
   let app=axum::Router::new().route("/internal/search/render",axum::routing::post(handler)).with_state(state).layer(axum::extract::DefaultBodyLimit::max(2*1024*1024));
   let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await?;let address=listener.local_addr()?;
   servers.push(tokio::spawn(async move{axum::serve(listener,app).await.unwrap()}));urls.push(format!("http://{address}/internal/search/render"));
  }
  let mut actual_captures=Vec::new();
  for width in ["INTEGER","BIGINT"] {for max_remote in [false,true] {
   let native_path=format!("{output}.php-native-{}-max{}.json",width.to_ascii_lowercase(),max_remote as u8);
   let result=tokio::process::Command::new(root.join("api/bin/php-policy-test"))
    .args(["-d","extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so","-d","extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so"])
    .arg(root.join("api/bin/search-components-collector-reference.php")).arg(&native_path)
    .env("LIBRARY_FIXTURE_SOCKET","/tmp/vaak-library-facts-pg-socket").env("SEARCH_COMPONENT_SCHEMA_SUFFIX",&suffix).env("SEARCH_COMPONENT_MAX_REMOTE",if max_remote{"1"}else{""}).env("ROOT_NATIVE_ACCEPT","1").env("ROOT_SAVED_WIDTH_FILTER",width)
    .env("SEARCH_COMPONENT_URL_INTEGER",&urls[0]).env("SEARCH_COMPONENT_URL_BIGINT",&urls[1]).output().await?;
   std::fs::write(format!("{output}.php-{}.stdout",width.to_ascii_lowercase()),&result.stdout)?;std::fs::write(format!("{output}.php-{}.stderr",width.to_ascii_lowercase()),&result.stderr)?;
   if !result.status.success(){for server in &servers {server.abort();}bail!("actual PHP native caller failed: {}",String::from_utf8_lossy(&result.stderr))}
   let actual:Value=serde_json::from_slice(&std::fs::read(&native_path)?)?;
   actual_captures.extend(actual["captures"].as_array().context("native PHP replies")?.iter().cloned());
  }
  }
  for server in servers {server.abort();}
  let records=receipts.lock().unwrap().clone();std::fs::write(format!("{output}.requests.json"),serde_json::to_vec_pretty(&records)?)?;
  let before=expected["captures"].as_array().context("original PHP references")?;let after=&actual_captures;
  anyhow::ensure!(before.len()==22&&after.len()==before.len(),"mandatory actual collector coverage");
  let mut checks=0;
  for (old,new) in before.iter().zip(after){
   for field in ["width","case","query","type","offset","limit","owner","entries"] {anyhow::ensure!(old[field]==new[field],"actual complete PHP {field} differs {} {}",old["width"],old["case"]);checks+=1;}
   for field in ["accounts","hashtags","statuses","has_more"]{anyhow::ensure!(old["window"][field]==new["window"][field],"actual collector {field} differs");checks+=1;}
  }
  anyhow::ensure!(records.len()==66&&records.iter().all(|r|r["status"]==200),"all 66 actual native calls must succeed; no PHP fallback accepted");
  let db=crate::db::connect("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres").await?;
  let cleanup=db.query("SELECT schema_name FROM information_schema.schemata WHERE schema_name=ANY($1::text[])",&[&vec![format!("search_components_collector_integer_{suffix}"),format!("search_components_collector_bigint_{suffix}")]]).await?;
  anyhow::ensure!(cleanup.is_empty(),"owned PHP schemas not cleaned");
  std::fs::write(&output,serde_json::to_vec_pretty(&json!({"checks":checks,"native_calls":records.len(),"php_fallbacks":0,"cleanup_owned_schemas":0,"expected_source":"original f8463cd PHP producer/callers separate-process receipt, unchanged expected DOM/actions/cursors","scope":"account/hashtag collector plus full/soft shared body and actual append; authenticated session/upstream bootstrap/Redis/worker transport declared"}))?)?;
  Ok(())
 }
 #[tokio::test]
 #[ignore="owned PostgreSQL and genuine mixed PHP producer/preview transport required"]
 async fn actual_mixed_component_preview_await_withdrawals()->Result<()> {run_mixed_preview(false,false).await}
 #[tokio::test]
 #[ignore="new genuine original cold constructor PHP baseline and owned PG required"]
 async fn actual_cold_component_preview_await_withdrawals()->Result<()> {run_mixed_preview(true,false).await}
 #[tokio::test]
 #[ignore="owned PG and unchanged genuine warmed mixed producer required"]
 async fn actual_warmed_component_complete_dom_without_dns()->Result<()> {run_mixed_preview(false,true).await}
 #[tokio::test]
 #[ignore="fresh supported-cache original successful PHP producer/effects and owned PG required"]
 async fn actual_successful_cold_preview_complete_dom_and_cache_effects()->Result<()>{
  anyhow::ensure!(matches!(std::env::var("SEARCH_PREVIEW_EFFECT_CASE").as_deref(),Ok("ordinary"|"canonical")),"choose one declared actual OG effect primitive");run_mixed_preview(true,false).await
 }
 #[tokio::test]
 #[ignore="genuine supported-cache original collector and exact terminal cache selector fixture required"]
 async fn actual_cold_preview_terminal_cache_changes()->Result<()>{
  anyhow::ensure!(matches!(std::env::var("VAAK_SEARCH_PREVIEW_TERMINAL_CASE").as_deref(),Ok("unchanged"|"preview_edit"|"preview_shape_aba"|"preview_table_removed"|"canonical_source_withdrawn"|"outside_selected_receipt")),"choose one finite terminal preview case");anyhow::ensure!(matches!(std::env::var("SEARCH_PREVIEW_EFFECT_CASE").as_deref(),Ok("ordinary"|"canonical")),"supported original successful cache producer required");run_mixed_preview(true,false).await
 }
 async fn run_mixed_preview(cold:bool,warm_only:bool)->Result<()> {
  let output=std::env::var("VAAK_SEARCH_MIXED_COMPONENT_OUTPUT")?;
  let reference=std::env::var(if cold{"VAAK_SEARCH_COLD_COMPONENT_REFERENCE"}else{"VAAK_SEARCH_MIXED_COMPONENT_REFERENCE"})?;
  let baseline:Value=serde_json::from_slice(&std::fs::read(reference)?)?;
  let root=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../..");let suffix=std::process::id().to_string();let mut results=Vec::new();
  for width in ["INTEGER","BIGINT"] {
   for (name,sql,expected) in [
    ("unchanged",None,200u16),
    ("remote_block",Some("INSERT INTO ap_user_blocks(id,owner_user_id,scope,value) VALUES(71,1,'actor','https://collector-remote.test/users/cached')"),422),
    ("remote_mute",Some("INSERT INTO ap_mutes(owner_user_id,actor_id) VALUES(1,'https://collector-remote.test/users/cached')"),422),
    ("server_domain_block",Some("INSERT INTO ap_blocks(id,scope,value,kind) VALUES(71,'domain','collector-remote.test','block')"),422),
    ("server_actor_mute",Some("INSERT INTO ap_blocks(id,scope,value,kind) VALUES(71,'actor','https://collector-remote.test/users/cached','mute')"),422),
    ("remote_source_deleted",Some("DELETE FROM remote_actors WHERE actor_id='https://collector-remote.test/users/cached'"),422),
    ("remote_mapping_changed",Some("UPDATE masto_account_actors SET actor_id='https://different.test/users/peer'"),422),
    ("remote_follow_withdrawn",Some("DELETE FROM following WHERE actor_id='https://collector-remote.test/users/cached'"),422),
    ("owner_disabled",Some("UPDATE ap_users SET disabled_at='fixture-disabled' WHERE id=1"),422),
    ("owner_identity_changed",Some("UPDATE ap_users SET actor_key='changed-fixture' WHERE id=1"),422),
    ("owner_role_changed_authority_withdrawn",Some("UPDATE ap_users SET is_admin=1 WHERE id=1"),422),
    ("viewer_setting_changed",Some("UPDATE actor_profile SET auto_unblur_sensitive=CASE WHEN COALESCE(auto_unblur_sensitive,'0')='0' THEN '1' ELSE '0' END WHERE actor_key='fixture'"),422),
    ("viewer_profile_removed",Some("DELETE FROM actor_profile WHERE actor_key='fixture'"),422),
    ("mandatory_viewer_profile_query_failure",Some("ALTER TABLE actor_profile RENAME TO unavailable_actor_profile"),422),
    ("own_word_mute_preserved",Some("INSERT INTO ap_muted_words(id,owner_user_id,phrase) VALUES(71,1,'fixture')"),200),
    ("alias_unchanged",None,200),
    ("alias_raw_provider_block",Some("INSERT INTO ap_user_blocks(id,owner_user_id,scope,value) VALUES(71,1,'actor','https://alias-collector.test/users/raw_peer')"),422),
    ("alias_raw_provider_mute",Some("INSERT INTO ap_mutes(owner_user_id,actor_id) VALUES(1,'https://alias-collector.test/users/raw_peer')"),422),
    ("mandatory_server_query_failure",Some("ALTER TABLE ap_blocks RENAME TO unavailable_ap_blocks"),422),
    ("mandatory_personal_query_failure",Some("ALTER TABLE ap_user_blocks RENAME TO unavailable_ap_user_blocks"),422),
    ("mandatory_alias_query_failure",Some("INSERT INTO ap_mutes(owner_user_id,actor_id) VALUES(1,'https://bsky.app/profile/did:plc:query-failure');ALTER TABLE bsky_actor_profiles RENAME TO unavailable_bsky_profiles"),422),
   ] {
    if warm_only&&name!="unchanged"{continue}
    let effect=std::env::var("SEARCH_PREVIEW_EFFECT_CASE").unwrap_or_default();if !effect.is_empty()&&name!="unchanged"{continue}
    let terminal=std::env::var("VAAK_SEARCH_PREVIEW_TERMINAL_CASE").unwrap_or_default();if !terminal.is_empty()&&name!="unchanged"{continue}
    let expected=if !terminal.is_empty()&&terminal!="unchanged"{422}else{expected};
    let alias=name.starts_with("alias_");
    let schema=format!("search_components_collector_{}_{}",width.to_ascii_lowercase(),suffix);let database=format!("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options='-c search_path={schema}'");
    let boundary=if terminal.is_empty(){None}else{Some(Arc::new(FinalBoundaryMutation{phase:"after_preview_selector_before_final_sql".into(),case:terminal.clone(),schema:schema.clone(),root:root.clone(),hits:std::sync::atomic::AtomicUsize::new(0),armed:std::sync::atomic::AtomicBool::new(true),settings:Mutex::new(None)}))};
    let artifact=format!("{output}.{}-{name}.php.json",width.to_ascii_lowercase());
    let (cancel_tx,cancel_rx)=tokio::sync::watch::channel(false);let (shutdown_tx,shutdown_rx)=tokio::sync::oneshot::channel::<()>();
    let stop=Arc::new(MixedStop{key:format!("owned-mixed-stop-{suffix}-{width}-{name}"),receipt:format!("{artifact}.stop.json"),cancel:cancel_tx.clone(),shutdown:Mutex::new(Some(shutdown_tx)),active:std::sync::atomic::AtomicUsize::new(0),listener:Mutex::new(None)});
    let receipts=Arc::new(Mutex::new(Vec::<Value>::new()));let state=Fixture{database,receipts:receipts.clone(),fault:sql.map(str::to_owned),boundary,cancel:Some(cancel_rx),stop:Some(stop.clone())};
    let app=axum::Router::new().route("/internal/search/render",axum::routing::post(handler)).route("/internal/search/fixture-stop",axum::routing::post(stop_handler)).with_state(state).layer(axum::extract::DefaultBodyLimit::max(2*1024*1024));
    let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await?;let address=listener.local_addr()?;*stop.listener.lock().unwrap()=Some(address);let mut server=tokio::spawn(async move{axum::serve(listener,app).with_graceful_shutdown(async{let _=shutdown_rx.await;}).await});let _owned_server=OwnedTask(server.abort_handle());
    let mut command=tokio::process::Command::new(root.join("api/bin/php-policy-test"));command.args(["-d","extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so","-d","extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so"]).arg(if !effect.is_empty(){std::path::PathBuf::from(std::env::var("VAAK_SEARCH_SUCCESS_PHP_TOOL")?)}else{root.join(if cold{"api/bin/search-components-cold-collector-reference.php"}else{"api/bin/search-components-mixed-collector-reference.php"})}).arg(&artifact)
      .env("LIBRARY_FIXTURE_SOCKET","/tmp/vaak-library-facts-pg-socket").env("SEARCH_COMPONENT_SCHEMA_SUFFIX",&suffix).env("SEARCH_COMPONENT_MIXED_ALIAS",if alias{"1"}else{"0"}).env("ROOT_NATIVE_ACCEPT","1").env("ROOT_SAVED_WIDTH_FILTER",width).env(format!("SEARCH_COMPONENT_URL_{width}"),format!("http://{address}/internal/search/render")).env("SEARCH_COMPONENT_STOP_URL",format!("http://{address}/internal/search/fixture-stop")).env("SEARCH_COMPONENT_STOP_KEY",&stop.key).env("SEARCH_COMPONENT_STOP_RECEIPT",&stop.receipt);
    let stdout_file=std::fs::OpenOptions::new().write(true).create_new(true).open(format!("{artifact}.stdout"))?;
    let stderr_file=std::fs::OpenOptions::new().write(true).create_new(true).open(format!("{artifact}.stderr"))?;
    command.stdout(std::process::Stdio::from(stdout_file)).stderr(std::process::Stdio::from(stderr_file)).kill_on_drop(true);
    let mut child=command.spawn()?;let child_id=child.id().context("owned PHP child PID")?;std::fs::write(format!("{artifact}.child-owned.json"),serde_json::to_vec_pretty(&json!({"pid":child_id,"native_pid":std::process::id(),"exact_schema":schema,"bootstrap_schemas":[format!("root_preview_callers_integer_{child_id}"),format!("root_preview_callers_bigint_{child_id}")],"listener":address.to_string(),"stop_receipt":stop.receipt,"stop_key":stop.key}))?)?;
    let first=tokio::time::timeout(std::time::Duration::from_secs(45),child.wait()).await;
    let timed_out=first.is_err();
    // Stop accepting and cancel every owned in-flight render before any forced PHP cleanup/drop.
    let _=cancel_tx.send(true);if let Some(sender)=stop.shutdown.lock().unwrap().take(){let _=sender.send(());}
    let server_join=tokio::time::timeout(std::time::Duration::from_secs(5),&mut server).await;
    let server_timed_out=server_join.is_err();let server_clean=matches!(server_join,Ok(Ok(Ok(()))));if server_timed_out{server.abort();let _=tokio::time::timeout(std::time::Duration::from_secs(2),&mut server).await;}
    if server_clean&&stop.active.load(std::sync::atomic::Ordering::SeqCst)==0{
     if !std::path::Path::new(&stop.receipt).exists(){use std::io::Write;let mut proof=std::fs::OpenOptions::new().write(true).create_new(true).open(&stop.receipt)?;proof.write_all(&serde_json::to_vec(&json!({"key":stop.key,"active_renderers":0,"listener_shutdown_requested":true,"listener_closed":true}))?)?;}
    }
    let mut forced_kill=false;
    let status=match first{Ok(status)=>status?,Err(_)=>match tokio::time::timeout(std::time::Duration::from_secs(12),child.wait()).await{
     Ok(status)=>status?,Err(_)=>{
      forced_kill=true;let mut term=tokio::process::Command::new("/bin/kill");term.args(["-TERM",&child_id.to_string()]).kill_on_drop(true);let _=tokio::time::timeout(std::time::Duration::from_secs(2),term.status()).await;
      match tokio::time::timeout(std::time::Duration::from_secs(2),child.wait()).await{Ok(status)=>status?,Err(_)=>{child.start_kill()?;tokio::time::timeout(std::time::Duration::from_secs(5),child.wait()).await.context("owned PHP child must reap")??}}
     }
    }};
    let port_released=tokio::net::TcpListener::bind(address).await.is_ok();
    let records=receipts.lock().unwrap().clone();std::fs::write(format!("{artifact}.native.json"),serde_json::to_vec_pretty(&records)?)?;
    let inventory_db=tokio::time::timeout(std::time::Duration::from_secs(5),crate::db::connect("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres")).await.context("owned inventory connection bound")??;
    inventory_db.batch_execute("SET statement_timeout='5s';SET lock_timeout='1s'").await?;
    let names=vec![schema.clone(),format!("root_preview_callers_integer_{child_id}"),format!("root_preview_callers_bigint_{child_id}")];
    let remaining=inventory_db.query("SELECT to_jsonb(n) FROM (SELECT nspname,oid::text,pg_get_userbyid(nspowner) AS owner,obj_description(oid,'pg_namespace') AS marker FROM pg_namespace WHERE nspname=ANY($1::text[]) ORDER BY nspname) n",&[&names]).await?.into_iter().map(|r|r.get::<_,Value>(0)).collect::<Vec<_>>();
    std::fs::write(format!("{artifact}.supervision.json"),serde_json::to_vec_pretty(&json!({"owned_child_pid":child_id,"child_reaped":true,"timed_out":timed_out,"forced_kill":forced_kill,"server_drained":server_clean,"port_released":port_released,"exact_namespace_inventory":remaining,"fixture_child_limit_seconds":45,"php_cleanup_grace_seconds":12,"original_native_deadline_ms":3000}))?)?;
    let php=std::process::Output{status,stdout:std::fs::read(format!("{artifact}.stdout"))?,stderr:std::fs::read(format!("{artifact}.stderr"))?};
    anyhow::ensure!(!timed_out&&server_clean&&port_released&&remaining.is_empty(),"owned fixture timeout/cleanup failure retained; never accepted as parity");
    anyhow::ensure!(php.status.success(),"actual mixed PHP failed {width}/{name}: {}",String::from_utf8_lossy(&php.stderr));
    anyhow::ensure!(records.len()==1&&records[0]["status"]==expected&&(if warm_only{records[0]["preview_dns_calls"].as_u64()==Some(0)}else{records[0]["preview_dns_calls"].as_u64().unwrap_or(0)>0}),"mandatory real preview-await {width}/{name} native outcome");
    let actual:Value=serde_json::from_slice(&std::fs::read(&artifact)?)?;let capture=&actual["captures"][0];let html=capture["html"].as_str().unwrap();
    // The old request is not refreshed into a new role grant. A steady non-admin
    // positive remains independently checked, alongside the genuine PHP fallback.
    if matches!(name,"unchanged"|"owner_role_changed_authority_withdrawn") {
     for token in ["name=\"action\" value=\"block_actor\"","name=\"action\" value=\"block_domain\"","Open in moderation panel"] {
      anyhow::ensure!(!html.contains(token),"non-admin PHP/current request must not emit server moderation actions {width}/{name}");
     }
     if name=="owner_role_changed_authority_withdrawn"{anyhow::ensure!(records[0]["native_html_bytes"]==0,"changed role must refuse all old-grant native HTML");}
    }
    if !terminal.is_empty(){
     anyhow::ensure!(records[0]["boundary_hits"]==if terminal=="outside_selected_receipt"{0}else{1},"exact selected terminal/actual writer boundary required");
     if expected==422{anyhow::ensure!(records[0]["native_html_bytes"]==0,"terminal changed cache must refuse all native HTML");}
     if matches!(terminal.as_str(),"preview_edit"|"preview_shape_aba"){anyhow::ensure!(html.contains("Terminal changed preview"),"actual fresh PHP fallback must read committed preview row");}
    }

    if matches!(terminal.as_str(),"canonical_source_withdrawn"|"outside_selected_receipt"){
     let original=baseline["captures"].as_array().unwrap().iter().find(|c|c["width"]==width).context("original canonical width")?;
     for row in original["after_tables"]["link_preview_cards"].as_array().context("original cache rows")?{anyhow::ensure!(capture["after_tables"]["link_preview_cards"].as_array().unwrap().contains(row),"canonical actual writer effect retained byte exact");}
     if terminal=="canonical_source_withdrawn"{anyhow::ensure!(!html.contains("fixture https://component-await.test/card"),"fresh PHP must omit withdrawn original source");}
    }
    if matches!(name,"remote_block"|"remote_mute"|"server_domain_block"|"server_actor_mute"|"remote_source_deleted"|"remote_mapping_changed"|"alias_raw_provider_block"|"alias_raw_provider_mute") {
     anyhow::ensure!(!html.contains(if alias{"@fixture_peer@alias-collector.test</span>"}else{"@fixture_peer@collector-remote.test</span>"}),"actual PHP fallback must omit withdrawn peer {width}/{name}");
     anyhow::ensure!(html.contains("@fixture</span>"),"unrelated own account must remain visible {width}/{name}");
    }
    if expected==200 {
     let original=baseline["captures"].as_array().unwrap().iter().find(|c|c["width"]==width&&c["alias_source"].as_bool().unwrap_or(false)==alias).context("original mixed width")?;
     anyhow::ensure!(complete_dom_equal(&root,&capture["html"],&original["html"])?,"complete positive mixed native/PHP DOM differs {width}/{name}");
     if !effect.is_empty(){
      anyhow::ensure!(records[0]["preview_transport_calls"].as_array().context("preview transport receipts")?.iter().any(|v|v["kind"]=="http_body"),"actual non-null primitive must execute");
      anyhow::ensure!(capture["after_tables"]["link_preview_cards"]==original["after_tables"]["link_preview_cards"],"complete original PHP preview cache effects must match including canonical URL/scalars/timestamps");
      anyhow::ensure!(capture["preview_budget"]==original["preview_budget"],"actual original preview budget consumption must match");
     }

    }
    anyhow::ensure!(capture["window"]["accounts"].as_array().unwrap().len()==2&&capture["window"]["statuses"].as_array().unwrap().len()==1,"raw producer slots retained");
    results.push(json!({"width":width,"case":name,"native_status":expected,"preview_dns_calls":records[0]["preview_dns_calls"],"boundaries":"genuine actual PHP mixed collector/shared body; DNS IPv4/late SQL/action delivery explicitly supplied offline; no full authenticated public route claim"}));
   }
  }
  let db=crate::db::connect("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres").await?;let cleanup=db.query("SELECT schema_name FROM information_schema.schemata WHERE schema_name=ANY($1::text[])",&[&vec![format!("search_components_collector_integer_{suffix}"),format!("search_components_collector_bigint_{suffix}")]]).await?;anyhow::ensure!(cleanup.is_empty(),"owned mixed schemas remain");
  std::fs::write(output,serde_json::to_vec_pretty(&json!({"controls":results,"cleanup_owned_schemas":0,"whole_search_parity":false,"bsky_disconnect_protocol":"uncovered; AP-only sources carry no Bsky connected permission"}))?)?;Ok(())
 }

 #[tokio::test]
 #[ignore="owned PG, original mixed producer and genuine second-session Settings configuration required"]
 async fn exact_final_ordered_boundaries_refuse_and_restore()->Result<()>{
  let output=std::env::var("VAAK_SEARCH_FINAL_BOUNDARY_OUTPUT")?;
  let baseline:Value=serde_json::from_slice(&std::fs::read(std::env::var("VAAK_SEARCH_MIXED_COMPONENT_REFERENCE")?)?)?;

  let root=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../..");let suffix=std::process::id().to_string();let mut results=Vec::new();
  for width in ["INTEGER","BIGINT"]{for phase in ["after_viewer_before_components","after_components_before_status","after_status_before_final_raw"]{for case in ["unchanged","restored","provider_deleted","raw_block","raw_mute"]{
   let schema=format!("search_components_collector_{}_{}",width.to_ascii_lowercase(),suffix);let database=format!("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options='-c search_path={schema}'");
   let hook=Arc::new(FinalBoundaryMutation{phase:phase.into(),case:case.into(),schema: schema.clone(),root:root.clone(),hits:std::sync::atomic::AtomicUsize::new(0),armed:std::sync::atomic::AtomicBool::new(true),settings:Mutex::new(None)});
   let receipts=Arc::new(Mutex::new(Vec::<Value>::new()));let state=Fixture{database,receipts:receipts.clone(),fault:None,boundary:Some(hook.clone()),cancel:None,stop:None};
   let app=axum::Router::new().route("/internal/search/render",axum::routing::post(handler)).with_state(state).layer(axum::extract::DefaultBodyLimit::max(2*1024*1024));
   let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await?;let address=listener.local_addr()?;let server=tokio::spawn(async move{axum::serve(listener,app).await.unwrap()});
   let artifact=format!("{output}.{}-{phase}-{case}.php.json",width.to_ascii_lowercase());
   let php=tokio::process::Command::new(root.join("api/bin/php-policy-test")).args(["-d","extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so","-d","extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so"]).arg(root.join("api/bin/search-components-mixed-collector-reference.php")).arg(&artifact)
    .env("LIBRARY_FIXTURE_SOCKET","/tmp/vaak-library-facts-pg-socket").env("SEARCH_COMPONENT_SCHEMA_SUFFIX",&suffix).env("SEARCH_COMPONENT_MIXED_ALIAS","1").env("SEARCH_COMPONENT_BOUNDARY_ACTION_SHAPE","1").env("ROOT_NATIVE_ACCEPT","1").env("ROOT_SAVED_WIDTH_FILTER",width).env(format!("SEARCH_COMPONENT_URL_{width}"),format!("http://{address}/internal/search/render")).output().await?;
   server.abort();std::fs::write(format!("{artifact}.stderr"),&php.stderr)?;let records=receipts.lock().unwrap().clone();std::fs::write(format!("{artifact}.native.json"),serde_json::to_vec_pretty(&records)?)?;
   anyhow::ensure!(php.status.success(),"actual boundary caller failed {width}/{phase}/{case}: {}",String::from_utf8_lossy(&php.stderr));
   let expected=if matches!(case,"unchanged"|"restored"){200}else{422};
   anyhow::ensure!(hook.hits.load(std::sync::atomic::Ordering::SeqCst)==1&&records.len()==1&&records[0]["status"]==expected,"mandatory exact boundary outcome {width}/{phase}/{case}");
   if expected==422{anyhow::ensure!(records[0]["native_html_bytes"]==0,"native refusal emitted HTML")}
   let actual:Value=serde_json::from_slice(&std::fs::read(&artifact)?)?;let capture=&actual["captures"][0];
   anyhow::ensure!(capture["window"]["accounts"].as_array().unwrap().len()==2&&capture["window"]["statuses"].as_array().unwrap().len()==1,"boundary raw cursor/source consumption changed");
   if matches!(case,"provider_deleted"|"raw_block"|"raw_mute"){
    let html=capture["html"].as_str().unwrap();anyhow::ensure!(!html.contains("@fixture_peer@alias-collector.test</span>")&&html.contains("@fixture</span>"),"fresh PHP fallback did not preserve unrelated own / omit withdrawn provider");
   }
   if expected==200{let original=baseline["captures"].as_array().unwrap().iter().find(|c|c["width"]==width&&c["alias_source"]==true).context("genuine original boundary reference")?;anyhow::ensure!(complete_dom_equal(&root,&capture["html"],&original["html"])?,"unchanged/restored complete PHP/native HTML differs")}
   results.push(json!({"width":width,"phase":phase,"case":case,"native_status":expected,"native_html_bytes":records[0]["native_html_bytes"],"raw_accounts":2,"raw_statuses":1}));
  }}}
  let db=crate::db::connect("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres").await?;
  let cleanup=db.query("SELECT schema_name FROM information_schema.schemata WHERE schema_name=ANY($1::text[])",&[&vec![format!("search_components_collector_integer_{suffix}"),format!("search_components_collector_bigint_{suffix}")]]).await?;anyhow::ensure!(cleanup.is_empty(),"owned final boundary schemas not cleaned");
  std::fs::write(output,serde_json::to_vec_pretty(&json!({"controls":results,"scope":"actual mixed producer/caller, exact final await callbacks; moderation dispatcher delivery/session seam explicit; Settings actual second-SAPI POST requires genuine configured session","cleanup_owned_schemas":0,"whole_search_parity":false}))?)?;Ok(())
 }

 async fn runtime_command(stdin:&mut tokio::process::ChildStdin,reader:&mut tokio::io::BufReader<tokio::process::ChildStdout>,request:Value)->Result<Value>{
  use tokio::io::{AsyncWriteExt,AsyncBufReadExt};let mut bytes=serde_json::to_vec(&request)?;bytes.push(b'\n');stdin.write_all(&bytes).await?;stdin.flush().await?;
  let mut line=String::new();reader.read_line(&mut line).await?;Ok(serde_json::from_str(&line)?)
 }
 #[tokio::test]
 #[ignore="owned PG and pinned full PHP Settings/runtime script required"]
 async fn real_settings_post_at_each_final_ordered_boundary()->Result<()>{
  use tokio::io::AsyncBufReadExt;
  let tool=std::env::var("VAAK_SEARCH_SETTINGS_RUNTIME_TOOL")?;let output=std::env::var("VAAK_SEARCH_SETTINGS_FINAL_OUTPUT")?;
  // Each prescribed case launches as a separate native process with the same
  // trusted startup TMPDIR as its SAPI. No global environment changes in tests.
  let width_filter=std::env::var("VAAK_SEARCH_SETTINGS_WIDTH").ok();let phase_filter=std::env::var("VAAK_SEARCH_SETTINGS_PHASE").ok();
  anyhow::ensure!(width_filter.is_some()==phase_filter.is_some(),"both Settings case filters required together");
  if let Some(width)=width_filter.as_deref(){anyhow::ensure!(matches!(width,"INTEGER"|"BIGINT"),"invalid Settings width filter")}
  if let Some(phase)=phase_filter.as_deref(){anyhow::ensure!(matches!(phase,"after_viewer_before_components"|"after_components_before_status"|"after_status_before_final_raw"),"invalid Settings phase filter")}

  let root=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../..");let suffix=std::process::id();let mut results=Vec::new();let mut schemas=Vec::new();
  for (width_index,width) in ["INTEGER","BIGINT"].iter().enumerate(){for (phase_index,phase) in ["after_viewer_before_components","after_components_before_status","after_status_before_final_raw"].iter().enumerate(){
   if width_filter.as_deref().is_some_and(|selected|selected!=*width)||phase_filter.as_deref().is_some_and(|selected|selected!=*phase){continue}
   let schema=format!("search_settings_final_boundary_{}_{}_{}",width.to_ascii_lowercase(),suffix,phase_index);schemas.push(schema.clone());let database=format!("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres application_name={schema} options='-c search_path={schema}'");
   let negative=std::env::var("VAAK_SEARCH_NOURL_NEGATIVE_CASE").unwrap_or_else(|_|"settings_post".into());anyhow::ensure!(matches!(negative.as_str(),"settings_post"|"nourl_source_deleted"|"nourl_query_error"),"one finite noURL negative");if negative!="settings_post"{anyhow::ensure!(std::env::var("VAAK_SEARCH_NOURL_FIXTURE").as_deref()==Ok("1"),"noURL-only controls require genuine input variant");}
   let hook=Arc::new(FinalBoundaryMutation{phase:(*phase).into(),case:negative.clone(),schema:schema.clone(),root:root.clone(),hits:std::sync::atomic::AtomicUsize::new(0),armed:std::sync::atomic::AtomicBool::new(false),settings:Mutex::new(None)});
   let base=23080+width_index*40+phase_index*10;let directory=format!("{output}.{}-{phase}",width.to_ascii_lowercase());std::fs::create_dir_all(&directory)?;
   let (cancel_tx,cancel_rx)=tokio::sync::watch::channel(false);let (shutdown_tx,shutdown_rx)=tokio::sync::oneshot::channel();
   let stop=Arc::new(MixedStop{key:format!("settings-stop-{schema}"),receipt:format!("{directory}/native-stop.json"),cancel:cancel_tx.clone(),shutdown:Mutex::new(Some(shutdown_tx)),active:std::sync::atomic::AtomicUsize::new(0),listener:Mutex::new(None)});
   let receipts=Arc::new(Mutex::new(Vec::<Value>::new()));let state=Fixture{database,receipts:receipts.clone(),fault:None,boundary:Some(hook.clone()),cancel:Some(cancel_rx),stop:Some(stop.clone())};
   let app=axum::Router::new().route("/internal/search/render",axum::routing::post(handler)).route("/internal/search/stop",axum::routing::post(stop_handler)).with_state(state).layer(axum::extract::DefaultBodyLimit::max(2*1024*1024));
   let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await?;let address=listener.local_addr()?;*stop.listener.lock().unwrap()=Some(address);
   let mut server=tokio::spawn(async move{axum::serve(listener,app).with_graceful_shutdown(async{let _=shutdown_rx.await;}).await});
   let _owned_server=OwnedTask(server.abort_handle());
   let mut child=tokio::process::Command::new("python3").args([tool.as_str(),schema.as_str(),*width,&base.to_string(),&directory]).env("SEARCH_SETTINGS_NATIVE_URL",format!("http://{address}/internal/search/render")).env("SEARCH_SETTINGS_NATIVE_STOP_URL",format!("http://{address}/internal/search/stop")).env("SEARCH_CROSS_STOP_RECEIPT",&stop.receipt).env("SEARCH_CROSS_STOP_KEY",&stop.key).kill_on_drop(true).stdin(std::process::Stdio::piped()).stdout(std::process::Stdio::piped()).stderr(std::process::Stdio::from(std::fs::File::create(format!("{directory}/runtime.stderr"))?)).spawn()?;
   let mut stdin=child.stdin.take().context("runtime stdin")?;let mut reader=tokio::io::BufReader::new(child.stdout.take().context("runtime stdout")?);let run=async{
    let mut ready_line=String::new();tokio::time::timeout(std::time::Duration::from_secs(30),reader.read_line(&mut ready_line)).await??;let ready:Value=serde_json::from_str(&ready_line)?;anyhow::ensure!(ready["ready"]==true,"actual runtime bootstrap");
    let opened=runtime_command(&mut stdin,&mut reader,json!({"command":"open"})).await?;anyhow::ensure!(opened["source"]["type"]=="Create","actual Open producer source");
    runtime_command(&mut stdin,&mut reader,json!({"command":"sql","case":"sensitive_no_spoiler"})).await?;
    let form=json!({"action":"save_profile","name":"Actual Search Settings fixture","summary":"Actual dispatcher preference withdrawal","email":"","discoverable":"1","indexable":"1","collection_consent":"1","auto_unblur_sensitive":"1"});
    runtime_command(&mut stdin,&mut reader,json!({"command":"post","path":"/vaak/?view=profile","fields":form,"port":base+3})).await?;
    let writer=runtime_command(&mut stdin,&mut reader,json!({"command":"writer_session"})).await?;
    anyhow::ensure!(writer["cookie"].as_str()!=Some(format!("vaak_sess={}",ready["session_cookie"].as_str().unwrap()).as_str()),"Settings writer must have a separate legitimate same-owner session");
    *hook.settings.lock().unwrap()=Some(writer);
    let baseline=runtime_command(&mut stdin,&mut reader,json!({"command":"get","path":"/vaak/?view=search&q=games&type=statuses"})).await?;
    let baseline_records=receipts.lock().unwrap().clone();std::fs::write(format!("{directory}/baseline-native-receipts.json"),serde_json::to_vec_pretty(&baseline_records)?)?;anyhow::ensure!(baseline_records.len()==1&&baseline_records[0]["status"]==200,"mandatory genuine baseline native200");
    if std::env::var("VAAK_SEARCH_NOURL_FIXTURE").as_deref()==Ok("1"){
     let selected=baseline_records[0]["request"]["statuses"].as_array().context("actual noURL Settings producer")?;anyhow::ensure!(!selected.is_empty(),"nonempty actual noURL source");
     for status in selected{anyhow::ensure!(crate::direct_preview::extract_url(status["content"].as_str().context("actual source text")?)?.is_none()&&status["media_attachments"].as_array().is_none_or(Vec::is_empty)&&status["card"].is_null(),"actual noURL/media/card branch prerequisite");}
     anyhow::ensure!(baseline_records[0]["preview_dns_calls"]==0&&baseline_records[0]["preview_transport_calls"].as_array().is_some_and(Vec::is_empty),"noURL must do no DNS/HTTP/warm preview transport");
    }
    hook.armed.store(true,std::sync::atomic::Ordering::SeqCst);
    let changed=runtime_command(&mut stdin,&mut reader,json!({"command":"get","path":"/vaak/?view=search&q=games&type=statuses"})).await?;
    let records=receipts.lock().unwrap().clone();std::fs::write(format!("{directory}/withdrawal-native-receipts.json"),serde_json::to_vec_pretty(&records)?)?;anyhow::ensure!(records.len()==2&&records[1]["status"]==422&&records[1]["native_html_bytes"]==0&&hook.hits.load(std::sync::atomic::Ordering::SeqCst)==1,"actual final Settings withdrawal must refuse zero HTML");
    let before_html=std::fs::read_to_string(baseline["body_file"].as_str().unwrap())?;let after_html=std::fs::read_to_string(changed["body_file"].as_str().unwrap())?;
    if negative=="settings_post"{anyhow::ensure!(!before_html.contains("<details class=\"cw-gate\">")&&after_html.contains("<details class=\"cw-gate\">"),"fresh PHP fallback must apply the actual saved sensitive preference");}else if negative=="nourl_source_deleted"{anyhow::ensure!(!after_html.contains("games sensitive noURL fixture"),"fresh PHP must omit withdrawn genuine source");}
    std::fs::write(format!("{directory}/native-receipts.json"),serde_json::to_vec_pretty(&records)?)?;
    results.push(json!({"width":width,"phase":phase,"native_baseline":200,"native_withdrawal":422,"native_html_bytes":0,"separate_same_owner_session":true,"actual_save_profile":negative=="settings_post","fresh_php_sensitive_gate":negative=="settings_post","noURL_negative_case":negative}));Ok::<_,anyhow::Error>(())
   }.await;
   // Drain the exact native listener before allowing the runtime's guarded DROP.
   // This runs even when bootstrap or a preserved control assertion failed.
   let _=cancel_tx.send(true);if let Some(sender)=stop.shutdown.lock().unwrap().take(){let _=sender.send(());}
   let drained=tokio::time::timeout(std::time::Duration::from_secs(5),&mut server).await;
   let listener_released=tokio::net::TcpListener::bind(address).await.is_ok();
   let fully_drained=matches!(&drained,Ok(Ok(Ok(()))))&&stop.active.load(std::sync::atomic::Ordering::SeqCst)==0&&listener_released;
   if drained.is_err(){server.abort();let _=server.await;}
   let receipt_result=if fully_drained&&!std::path::Path::new(&stop.receipt).exists(){
    use std::io::Write;std::fs::OpenOptions::new().create_new(true).write(true).open(&stop.receipt).and_then(|mut file|file.write_all(&serde_json::to_vec(&json!({"key":stop.key,"active_renderers":0,"listener_closed":true})).unwrap()))
   }else{Ok(())};
   use tokio::io::AsyncWriteExt;
   let stop_runtime=async{stdin.write_all(b"{\"command\":\"stop\"}\n").await?;stdin.flush().await?;Ok::<_,anyhow::Error>(())};
   let _=tokio::time::timeout(std::time::Duration::from_secs(5),stop_runtime).await;drop(stdin);
   let waited=tokio::time::timeout(std::time::Duration::from_secs(25),child.wait()).await;
   let status=if waited.is_err(){child.start_kill()?;tokio::time::timeout(std::time::Duration::from_secs(5),child.wait()).await??}else{waited.unwrap()?};
   std::fs::write(format!("{directory}/listener-cleanup.json"),serde_json::to_vec_pretty(&json!({"drained":fully_drained,"listener_released":listener_released,"runtime_reaped":true,"runtime_exit":status.code()}))?)?;
   receipt_result?;anyhow::ensure!(fully_drained&&status.success(),"owned Settings runtime/native cleanup failed; retain namespace on missing drain proof");run?;
  }}
  let db=crate::db::connect("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres").await?;let cleanup=db.query("SELECT schema_name FROM information_schema.schemata WHERE schema_name=ANY($1::text[])",&[&schemas]).await?;anyhow::ensure!(cleanup.is_empty(),"owned Settings boundary schemas remain");
  std::fs::write(output,serde_json::to_vec_pretty(&json!({"controls":results,"cleanup_owned_schemas":0,"scope":"actual PHP full Search and save_profile second-SAPI POST/CSRF; exact final boundary callbacks; pinned offline network/fixed clock adapters explicit","whole_search_parity":false}))?)?;Ok(())
 }

 #[tokio::test]
 #[ignore="actual public Search full/soft/append SAPI, genuine producers, owned PG required"]
 async fn real_public_search_crosscaller_and_max40()->Result<()>{
  use tokio::io::{AsyncBufReadExt,AsyncWriteExt};
  let width=std::env::var("VAAK_SEARCH_CROSS_WIDTH")?;anyhow::ensure!(matches!(width.as_str(),"INTEGER"|"BIGINT"),"one owned width");
  let mode=std::env::var("VAAK_SEARCH_CROSS_MODE")?;anyhow::ensure!(matches!(mode.as_str(),"local"|"remote"),"one original producer mode");
  let tool=std::env::var("VAAK_SEARCH_CROSS_RUNTIME")?;let output=std::env::var("VAAK_SEARCH_CROSS_OUTPUT")?;let compare=std::env::var("VAAK_SEARCH_CROSS_COMPARE")?;
  let schema=format!("search_settings_final_boundary_{}_{}_0",width.to_ascii_lowercase(),std::process::id());let database=format!("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options='-c search_path={schema}'");
  let (cancel_tx,cancel_rx)=tokio::sync::watch::channel(false);let (shutdown_tx,shutdown_rx)=tokio::sync::oneshot::channel();let stop=Arc::new(MixedStop{key:format!("crosscaller-stop-{}",std::process::id()),receipt:format!("{output}.stop.json"),cancel:cancel_tx.clone(),shutdown:Mutex::new(Some(shutdown_tx)),active:std::sync::atomic::AtomicUsize::new(0),listener:Mutex::new(None)});let records=Arc::new(Mutex::new(Vec::<Value>::new()));let state=Fixture{database,receipts:records.clone(),fault:None,boundary:None,cancel:Some(cancel_rx),stop:Some(stop.clone())};
  let app=axum::Router::new().route("/internal/search/render",axum::routing::post(handler)).with_state(state).layer(axum::extract::DefaultBodyLimit::max(2*1024*1024));let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await?;let address=listener.local_addr()?;*stop.listener.lock().unwrap()=Some(address);
  let mut server=tokio::spawn(async move{axum::serve(listener,app).with_graceful_shutdown(async{let _=shutdown_rx.await;}).await});
  let base=if width=="INTEGER"{23080}else{23120};let directory=format!("{output}.runtime");std::fs::create_dir_all(&directory)?;
  let mut child=tokio::process::Command::new("python3").args([tool.as_str(),schema.as_str(),width.as_str(),&base.to_string(),&directory]).env("SEARCH_SETTINGS_NATIVE_URL",format!("http://{address}/internal/search/render")).env("SEARCH_CROSS_STOP_RECEIPT",&stop.receipt).env("SEARCH_CROSS_STOP_KEY",&stop.key).stdin(std::process::Stdio::piped()).stdout(std::process::Stdio::piped()).stderr(std::process::Stdio::from(std::fs::OpenOptions::new().create_new(true).write(true).open(format!("{directory}/runtime.stderr"))?)).spawn()?;
  let mut stdin=child.stdin.take().context("owned runtime stdin")?;let mut reader=tokio::io::BufReader::new(child.stdout.take().context("owned runtime stdout")?);
  let run=async{
   let mut line=String::new();tokio::time::timeout(std::time::Duration::from_secs(30),reader.read_line(&mut line)).await??;let ready:Value=serde_json::from_str(&line)?;anyhow::ensure!(ready["ready"]==true,"owned actual SAPI bootstrap");
   let prepared=tokio::time::timeout(std::time::Duration::from_secs(90),runtime_command(&mut stdin,&mut reader,json!({"command":"crosscaller_prepare","mode":mode}))).await??;anyhow::ensure!(prepared["actual_posts"]==41&&prepared["actual_policy_rows"].as_u64().unwrap_or(0)>=48,"genuine producer/policy prerequisites");let query=prepared["query"].as_str().context("actual query")?;
   let mut pairs=Vec::new();
   for (label,path,count) in [
    ("full",format!("/vaak/?view=search&q={query}"),20usize),
    ("soft",format!("/vaak/?view=search&q={query}&partial=1&shell=1"),20),
    ("append_first",format!("/vaak/?ajax=search_page&q={query}&section=statuses&offset=0&limit=10"),10),
    ("append_next",format!("/vaak/?ajax=search_page&q={query}&section=statuses&offset=10&limit=10"),10),
    ("append_max40",format!("/vaak/?ajax=search_page&q={query}&section=statuses&offset=0&limit=40"),40),
   ]{
    let before_original=runtime_command(&mut stdin,&mut reader,json!({"command":"snapshot"})).await?;let previous=records.lock().unwrap().len();let original=runtime_command(&mut stdin,&mut reader,json!({"command":"get","path":path,"original":true})).await?;anyhow::ensure!(original["status"]==200&&records.lock().unwrap().len()==previous,"actual original caller, native disabled");let after_original=runtime_command(&mut stdin,&mut reader,json!({"command":"snapshot"})).await?;let before_native=runtime_command(&mut stdin,&mut reader,json!({"command":"snapshot"})).await?;
    let native=runtime_command(&mut stdin,&mut reader,json!({"command":"get","path":path})).await?;anyhow::ensure!(native["status"]==200,"actual candidate caller status");let after_native=runtime_command(&mut stdin,&mut reader,json!({"command":"snapshot"})).await?;let receipts=records.lock().unwrap().clone();std::fs::write(format!("{directory}/{label}-native-raw.json"),serde_json::to_vec_pretty(&receipts)?)?;
    anyhow::ensure!(receipts.len()==previous+1&&receipts.last().unwrap()["status"]==200&&receipts.last().unwrap()["elapsed_seconds"].as_f64().unwrap_or(9.0)<3.0,"mandatory original3s native200; fallback never accepted");
    let actual=receipts.last().unwrap();anyhow::ensure!(actual["request"]["statuses"].as_array().context("actual selected source count")?.len()==count,"original source window cardinality");
    if std::env::var("VAAK_SEARCH_NOURL_BUDGET_VARIANT").as_deref()==Ok("1")&&label!="append_next"{
     let selected=actual["request"]["statuses"].as_array().unwrap();anyhow::ensure!(selected.len()>=4,"four actual budget sources");
     for status in &selected[..3]{anyhow::ensure!(crate::direct_preview::extract_url(status["content"].as_str().context("actual source text")?)?.is_none(),"first three genuine sources must be noURL");}
     anyhow::ensure!(crate::direct_preview::extract_url(selected[3]["content"].as_str().context("actual fourth source")?)?.as_deref()==Some("https://component-await.test/card"),"fourth genuine ordered source must be actual URL");
     anyhow::ensure!(actual["status_preview_state"]["initial"]==3&&actual["status_preview_state"]["remaining"]==0&&actual["status_preview_state"]["attempts"]==3,"same original nullURL attempt budget before actual URL");
    }
    let bridge=runtime_command(&mut stdin,&mut reader,json!({"command":"bridge_results"})).await?;anyhow::ensure!(bridge["rows"].as_array().context("actual bridge receipts")?.len()==previous+1&&bridge["rows"].as_array().unwrap().last().unwrap()["accepted"]==true,"actual PHP bridge accepted native, no fallback");
    let check_path=format!("{directory}/{label}-dom-review.json");let status=tokio::time::timeout(std::time::Duration::from_secs(15),tokio::process::Command::new("python3").args([compare.as_str(),original["body_file"].as_str().unwrap(),native["body_file"].as_str().unwrap(),label,&check_path,before_original["snapshot_file"].as_str().unwrap(),after_original["snapshot_file"].as_str().unwrap(),before_native["snapshot_file"].as_str().unwrap(),after_native["snapshot_file"].as_str().unwrap()]).status()).await??;anyhow::ensure!(status.success(),"actual Search protected DOM/action/CSRF/cursor mismatch retained");
    pairs.push(json!({"case":label,"original":original,"native":native,"bridge":bridge,"native_record":actual,"dom_review":check_path,"before_original":before_original,"after_original":after_original,"before_native":before_native,"after_native":after_native}));
   }
   std::fs::write(&output,serde_json::to_vec_pretty(&json!({"width":width,"mode":mode,"pairs":pairs,"actual_public_callers":true,"whole_search_parity":false,"optimized_artifact":cfg!(not(debug_assertions)),"whole_search_performance_acceptance":false}))?)?;Ok::<_,anyhow::Error>(())
  }.await;
  let _=cancel_tx.send(true);if let Some(sender)=stop.shutdown.lock().unwrap().take(){let _=sender.send(());}let drained=tokio::time::timeout(std::time::Duration::from_secs(5),&mut server).await;let listener_released=tokio::net::TcpListener::bind(address).await.is_ok();if drained.is_err(){server.abort();let _=server.await;}let fully_drained=matches!(&drained,Ok(Ok(Ok(()))))&&stop.active.load(std::sync::atomic::Ordering::SeqCst)==0&&listener_released;if fully_drained{std::fs::write(&stop.receipt,serde_json::to_vec_pretty(&json!({"key":stop.key,"active_renderers":0,"listener_closed":true}))?)?;}
  let stop=async{stdin.write_all(b"{\"command\":\"stop\"}\n").await?;stdin.flush().await?;Ok::<_,anyhow::Error>(())};let _=tokio::time::timeout(std::time::Duration::from_secs(5),stop).await;
  let child_result=tokio::time::timeout(std::time::Duration::from_secs(25),child.wait()).await;let reaped=if child_result.is_err(){child.start_kill()?;tokio::time::timeout(std::time::Duration::from_secs(5),child.wait()).await??}else{child_result.unwrap()?};
  std::fs::write(format!("{output}.listener-cleanup.json"),serde_json::to_vec_pretty(&json!({"drained":fully_drained,"listener_released":listener_released,"runtime_reaped":true,"runtime_exit":reaped.code()}))?)?;
  anyhow::ensure!(fully_drained&&listener_released&&reaped.success(),"owned public caller cleanup failure retained");run?;Ok(())
 }

 include!("search_php_follow_race_fixture.rs");

}

#[cfg(test)]
mod account_paint_aba_fixture { include!("search_account_paint_aba_fixture.rs"); }
