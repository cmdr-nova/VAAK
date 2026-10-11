//! Search-specific actor policy. Neither Library word filters nor profile mute
//! exceptions describe discovery. Private render tokens bind the final current
//! source/facts after freshness, indexing and durable audience checks.
use serde_json::Value;
use std::collections::HashSet;
use crate::hidden::HiddenSets;
use std::sync::OnceLock;
use anyhow::{bail,Context,Result};
use tokio_postgres::Client;

fn text<'a>(v:&'a Value,key:&str)->&'a str {v.get(key).and_then(Value::as_str).unwrap_or("")}
fn trim(value:&str)->&str {crate::library::card::php_trim(value)}
fn canonical_actor(value:&str)->String {
 let value=trim(value).trim_end_matches('/');
 static PATTERN:OnceLock<regex::Regex>=OnceLock::new();
 PATTERN.get_or_init(||regex::Regex::new(r"(?i)^https://(?:www\.)?vaak\.monster/(?:vaak/users/|users/|@)([A-Za-z0-9_]+)$").unwrap()).captures(value)
  .map(|m|format!("https://mkultra.monster/users/{}",m[1].to_ascii_lowercase())).unwrap_or_else(||value.into())
}
fn actor_refs(value:&str)->Vec<String> {
 let value=trim(value).trim_end_matches('/');
 if value.is_empty()||value.bytes().all(|byte|byte.is_ascii_digit()){return Vec::new()}
 static PATTERN:OnceLock<regex::Regex>=OnceLock::new();
 let actor=value.starts_with("https://")||value.starts_with("http://")||value.starts_with("did:")
  ||PATTERN.get_or_init(||regex::Regex::new(r"^@?[A-Za-z0-9_.\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$").unwrap()).is_match(value);
 if !actor{return Vec::new()}
 let canonical=canonical_actor(value);let mut refs=vec![value.into()];
 if !canonical.is_empty()&&!canonical.eq_ignore_ascii_case(value){refs.push(canonical)}refs
}

/// Current server owner identity only. This type has no JSON deserializer.
struct SearchIdentity { refs:HashSet<String> }
impl SearchIdentity {
 fn is_self(&self,refs:&[String],acct:&str)->bool {
  refs.iter().any(|value|self.refs.contains(&value.trim_end_matches('/').to_ascii_lowercase()))
   ||{let acct=trim(acct).trim_start_matches('@').to_ascii_lowercase();!acct.is_empty()&&self.refs.contains(&format!("acct:{acct}"))}
 }
}
fn actor_hidden(actor:&str,identity:&SearchIdentity,hidden:&HiddenSets)->bool {
 let refs=actor_refs(actor);if identity.is_self(&refs,""){return false}
 refs.iter().any(|actor|hidden.is_hidden(actor))
}
fn account_hidden(account:&Value,identity:&SearchIdentity,hidden:&HiddenSets)->bool {
 let mut refs=Vec::new();for field in ["uri","url","id"]{refs.extend(actor_refs(text(account,field)))}
 let acct=trim(text(account,"acct")).trim_start_matches('@');
 let mut host=String::new();
 static PATTERN:OnceLock<regex::Regex>=OnceLock::new();
 if let Some(m)=PATTERN.get_or_init(||regex::Regex::new(r"^([^@\s]+)@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})$").unwrap()).captures(acct){
  let username=m[1].to_ascii_lowercase();host=m[2].to_ascii_lowercase();
  if matches!(host.as_str(),"vaak.monster"|"www.vaak.monster"){refs.push(format!("https://mkultra.monster/users/{username}"));host="mkultra.monster".into();}
 }
 if identity.is_self(&refs,acct){return false}
 refs.iter().any(|actor|actor_hidden(actor,identity,hidden))||(!host.is_empty()&&hidden.is_hidden(&format!("https://{host}")))
}
fn status_hidden(status:&Value,identity:&SearchIdentity,hidden:&HiddenSets)->bool {
 if status.get("account").is_some_and(|account|account_hidden(account,identity,hidden)){return true}
 for field in ["reblog","quote"] {
  let child=&status[field];
  if child.get("account").is_some_and(|account|account_hidden(account,identity,hidden))||actor_hidden(text(child,"author_did"),identity,hidden){return true}
 }
 actor_hidden(text(status,"author_did"),identity,hidden)
}

/// Server session bridge only; no HTTP deserializer or browser capabilities.
pub(crate) struct SearchSession {owner:i64,csrf:String,bsky_enabled:bool,bookmarks:std::collections::HashMap<String,bool>}
impl SearchSession {
 pub(crate) fn from_php_session(owner:i64,csrf:String,bsky_enabled:bool)->Result<Self>{
  if owner<1||csrf.is_empty(){bail!("invalid canonical Search session")}
  Ok(Self{owner,csrf,bsky_enabled,bookmarks:Default::default()})
 }
 pub(crate) async fn with_native_bookmarks(mut self,db:&Client,states:Vec<(String,String,bool)>)->Result<Self>{
  if states.len()>121{bail!("Search provenance exceeds canonical window")}
  if states.is_empty(){return Ok(self)}
  let did=db.query_opt("SELECT did FROM bsky_sessions WHERE owner_user_id=$1::bigint LIMIT 1",&[&self.owner]).await?.map(|row|row.get::<_,String>(0)).unwrap_or_default();
  static PATTERN:OnceLock<regex::Regex>=OnceLock::new();let pattern=PATTERN.get_or_init(||regex::Regex::new(r"^at://did:[^\s/?#]+/app\.bsky\.feed\.post/[^\s/?#]+$").unwrap());
  for (uri,source_did,state) in states{if did.is_empty()||source_did!=did||uri.len()>2048||!pattern.is_match(&uri)||self.bookmarks.insert(uri,state).is_some(){bail!("invalid current-owner Search bookmark provenance")}}
  Ok(self)
 }
}
impl crate::library::facts::PresentationSession for SearchSession {
 fn csrf(&self)->&str{&self.csrf}
 fn return_view(&self)->&str{"search"}
 fn bsky_enabled(&self)->bool{self.bsky_enabled}
 fn lazy_media(&self)->bool{false}
 fn bsky_bookmark_state(&self,status:&Value)->Option<bool>{let post=status.get("bsky_post_view").or_else(||status.get("bsky_post").map(|value|value.get("post").unwrap_or(value)))?;self.bookmarks.get(post.get("uri")?.as_str()?).copied()}
}

/// Only final fresh source/facts admission can create this exact bound pair.
pub(crate) struct AdmittedSearchStatus {source_index:usize,status:Value,facts:crate::library::card::CardFacts,root_preview:Option<Option<Value>>}
impl AdmittedSearchStatus {
 #[cfg(test)] pub(crate) fn set_render_clock(&mut self,clock:chrono::DateTime<chrono::Utc>){self.facts.now=clock;}
 pub(crate) fn root_preview(&self)->Option<&Option<Value>>{self.root_preview.as_ref()}
 pub(crate) fn source_index(&self)->usize{self.source_index}
 pub(crate) fn status(&self)->&Value{&self.status}
 pub(crate) fn facts(&self)->&crate::library::card::CardFacts{&self.facts}
}

fn children(status:&Value)->Vec<(&str,&Value)>{
 ["reblog","quote","vaak_quote_preview"].iter().filter_map(|field|status.get(*field).filter(|v|v.is_object()).map(|value|(*field,value.get("quoted_status").or_else(||value.get("status")).filter(|v|v.is_object()).unwrap_or(value)))).collect()
}
fn descriptor(field:&str,status:&Value)->bool{
 field=="quote"&&!status.get("account").is_some_and(Value::is_object)
  &&["uri","url","content","text","media","media_attachments"].iter().all(|key|status.get(*key).is_none())
  &&matches!(text(status,"state"),"pending"|"accepted"|"fallback")
}
fn current_tree<'a>(db:&'a Client,owner:i64,status:&'a Value,identity:&'a SearchIdentity,hidden:&'a HiddenSets,depth:usize)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<bool>>+Send+'a>>{
 Box::pin(async move{
  if depth>16||status_hidden(status,identity,hidden){return Ok(false)}
  let uri=trim(status.get("uri").or_else(||status.get("url")).and_then(Value::as_str).unwrap_or("")).split('#').next().unwrap_or("").trim_end_matches('/');
  let mut author=canonical_actor(status["account"].get("uri").or_else(||status["account"].get("url")).and_then(Value::as_str).unwrap_or(""));
  let raw=status.get("bsky_post");let post=raw.map(|value|value.get("post").filter(|v|v.is_object()).unwrap_or(value));
  let at=post.map(|value|text(value,"uri")).filter(|uri|!uri.is_empty()).unwrap_or(if uri.starts_with("at://"){uri}else{""});
  if !at.is_empty(){if !db.query_one("SELECT EXISTS(SELECT 1 FROM bsky_posts WHERE bsky_uri=$1)",&[&at]).await?.get::<_,bool>(0){return Ok(false)}}
  else if {static PATTERN:OnceLock<regex::Regex>=OnceLock::new();PATTERN.get_or_init(||regex::Regex::new(r"^https://mkultra\.monster/users/[^/]+/notes/").unwrap()).is_match(uri)}{
   if !db.query_one("SELECT EXISTS(SELECT 1 FROM masto_statuses WHERE rtrim(note_id,'/')=$1)",&[&uri]).await?.get::<_,bool>(0){return Ok(false)}
   author=uri.split("/notes/").next().unwrap_or("").into();
  }else if !uri.is_empty(){
   if let Some(row)=db.query_opt("SELECT actor_id,type,action_taken FROM events WHERE object_id IN ($1,$2) AND (type IN ('Create','Update','Delete','Tombstone') OR (type IN ('Quote','QuotePost') AND NOT EXISTS (SELECT 1 FROM events note_source WHERE note_source.object_id IN ($1,$2) AND note_source.type IN ('Create','Update')))) ORDER BY id DESC LIMIT 1",&[&uri,&format!("{uri}/")]).await?{
    let kind:String=row.try_get(1)?;let action:String=row.try_get::<_,Option<String>>(2)?.unwrap_or_default();if action=="deleted"||matches!(kind.as_str(),"Delete"|"Tombstone"){return Ok(false)}
    if let Some(value)=row.try_get::<_,Option<String>>(0)?{author=canonical_actor(&value)}
   }else if let Some(row)=db.query_opt("SELECT actor_id,deleted_at::text FROM mentions WHERE object_id IN ($1,$2) ORDER BY id DESC LIMIT 1",&[&uri,&format!("{uri}/")]).await?{
    if row.try_get::<_,Option<String>>(1)?.is_some_and(|value|!value.is_empty()){return Ok(false)}
    if let Some(value)=row.try_get::<_,Option<String>>(0)?{author=canonical_actor(&value)}
   }else{return Ok(false)}
  }else{return Ok(false)}
  if !author.is_empty(){if let Some(row)=db.query_opt("SELECT u.id::bigint,COALESCE(p.indexable,1)::bigint FROM ap_users u LEFT JOIN actor_profile p ON p.actor_key=u.actor_key WHERE rtrim(u.actor_id,'/')=$1 LIMIT 1",&[&author]).await?{if row.get::<_,i64>(0)!=owner&&row.get::<_,i64>(1)==0{return Ok(false)}}}
  for (field,child) in children(status){if !descriptor(field,child)&&!current_tree(db,owner,child,identity,hidden,depth+1).await?{return Ok(false)}}
  Ok(true)
 })
}

/// Input is canonical server-normalized Search source. Thin/unknown source IO
/// remains PHP; cached Library interaction-ID preservation is never applied.
pub(crate) async fn admit_for_render(db:&Client,session:&SearchSession,statuses:Vec<Value>)->Result<Vec<AdmittedSearchStatus>>{
 admit_for_render_impl(db,session,statuses,None).await.map(|value|value.0)
}
pub(crate) async fn admit_for_render_with_preview(db:&Client,session:&SearchSession,statuses:Vec<Value>,budget:i64,dns:&dyn crate::direct_preview::PreviewDns)->Result<(Vec<AdmittedSearchStatus>,i64)>{
 admit_for_render_impl(db,session,statuses,Some((budget,dns))).await
}
async fn admit_for_render_impl(db:&Client,session:&SearchSession,statuses:Vec<Value>,preview:Option<(i64,&dyn crate::direct_preview::PreviewDns)>)->Result<(Vec<AdmittedSearchStatus>,i64)>{
 let mut budget=preview.map(|value|value.0).unwrap_or(0);
 if statuses.len()>40{bail!("Search page bound exceeds canonical limit")}
 let row=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.context("inactive Search owner")?;
 let actor:String=row.try_get(0)?;let key:String=row.try_get(1)?;
 let mut identity=SearchIdentity{refs:crate::hidden::actor_aliases(&actor).iter().map(|value|value.to_ascii_lowercase()).collect()};
 for value in [format!("acct:{}",key.to_ascii_lowercase()),format!("acct:{}@mkultra.monster",key.to_ascii_lowercase()),format!("acct:{}@vaak.monster",key.to_ascii_lowercase()),format!("https://mkultra.monster/users/{}",key.to_ascii_lowercase()),format!("https://vaak.monster/users/{}",key.to_ascii_lowercase()),format!("https://www.vaak.monster/users/{}",key.to_ascii_lowercase())]{identity.refs.insert(value);}
 let mut hidden=crate::hidden::load_hidden_sets(db,session.owner).await?;hidden.muted_phrases.clear();
 let profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1 LIMIT 1",&[&key]).await?.map(|row|row.get::<_,Value>(0)).unwrap_or(Value::Null);
 let auto_unblur=crate::library::card::boolean(&profile,"auto_unblur_sensitive");
 let mut output=Vec::new();
 for (source_index,mut status) in statuses.into_iter().enumerate(){
  if preview.is_some(){crate::library::context::require_canonical_source_ids(&status,0)?;crate::library::context::require_ap_preview_protocol(&status,0)?;}
  crate::library::context::require_unambiguous_boost_selection(&status)?;
  let displayed_source=if text(&status,"content").is_empty(){status.get("reblog").filter(|value|value.is_object()).unwrap_or(&status)}else{&status};
  if preview.is_some()&&displayed_source.get("content").is_some_and(|value|!value.is_null()&&!value.is_string()){bail!("PHP fallback: non-string Search preview source")}
  let original_content=displayed_source.get("content").and_then(Value::as_str).map(str::to_owned);
  if crate::library::source::thin(displayed_source)||status.get("post").is_some_and(Value::is_object){bail!("PHP fallback: Search requires complete canonical normalization")}
  if !current_tree(db,session.owner,&status,&identity,&hidden,0).await?{
   if preview.is_some()&&!status_hidden(&status,&identity,&hidden){
    let grants=crate::audience::admission_mask(db,session.owner,crate::audience::Surface::Search,std::slice::from_ref(&status)).await?;
    if grants.first()==Some(&true){bail!("PHP fallback: Search late freshness preview state not certified")}
   }
   continue
  }
  crate::interaction_flags::apply_to_statuses(db,session.owner,std::slice::from_mut(&mut status)).await?;
  let mut facts=crate::library::facts::resolve(db,session.owner,&actor,&status,session,auto_unblur).await?;
  if let Some(sid)=crate::library::context::incoming_ap_boost_sid(&status){facts.boosted=crate::library::context::exact_incoming_boost(db,session.owner,&sid).await?;}
  if facts.rss_quote_row.is_some(){bail!("PHP fallback: fresh RSS quote needs complete Search source binding")}
  let inner=status.get("reblog").is_some_and(Value::is_object)&&text(&status,"content").is_empty();
  let displayed=if inner{status.get_mut("reblog").unwrap()}else{&mut status};
  if let Some(ask)=facts.ask_row.as_ref(){displayed["vaak_ask"]=ask.clone();if let Some(answer)=ask.get("_body_answer").and_then(Value::as_str).filter(|value|!trim(value).is_empty()){displayed["content"]=Value::String(crate::library::actions::esc(trim(answer)));}else if text(displayed,"content").contains("ask-container"){bail!("PHP fallback: Ask needs current canonical body normalization")}}
  if let Some(own)=facts.own.as_ref(){if text(displayed,"uri").ends_with('/'){bail!("PHP fallback: own trailing-slash presentation requires canonical lookup")}let snapshot=displayed.get("visibility").and_then(Value::as_str).unwrap_or("public");let current=if own.visibility.is_empty(){"public"}else{own.visibility.as_str()};if snapshot!=current{bail!("PHP fallback: own cached/current visibility presentation conflicts")}if !own.plain.is_empty()&&!matches!(own.plain.as_str(),"(quote)"|"(media)"|"(poll)"){displayed["content"]=Value::String(crate::library::actions::esc(&own.plain))}displayed["spoiler_text"]=Value::String(own.spoiler.clone());displayed["sensitive"]=Value::Bool(own.sensitive);displayed["visibility"]=Value::String(own.visibility.clone());}
  if !current_tree(db,session.owner,&status,&identity,&hidden,0).await?{
   if preview.is_some()&&!status_hidden(&status,&identity,&hidden){
    let grants=crate::audience::admission_mask(db,session.owner,crate::audience::Surface::Search,std::slice::from_ref(&status)).await?;
    if grants.first()==Some(&true){bail!("PHP fallback: Search late freshness preview state not certified")}
   }
   continue
  }
  let mut checked=vec![status];crate::audience::filter_statuses(db,session.owner,crate::audience::Surface::Search,&mut checked).await?;
  if let Some(status)=checked.pop(){let current=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.context("Search owner changed during assembly")?;if current.get::<_,String>(0)!=actor||current.get::<_,String>(1)!=key{bail!("Search identity changed during assembly")}let root_preview=if let Some((_,dns))=preview {
    crate::library::context::require_ap_preview_protocol(&status,0)?;
     let card=crate::direct_preview::resolve(db,dns,&status,original_content.as_deref(),&facts,&mut budget).await?;
     if !current_tree(db,session.owner,&status,&identity,&hidden,0).await?{continue}
     let mut final_checked=vec![status.clone()];crate::audience::filter_statuses(db,session.owner,crate::audience::Surface::Search,&mut final_checked).await?;
     if final_checked.is_empty(){continue}
     Some(card)
   }else{None};
   output.push(AdmittedSearchStatus{source_index,status,facts,root_preview})}
 }
 let final_owner=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.context("Search owner changed before publication")?;
 if final_owner.get::<_,String>(0)!=actor||final_owner.get::<_,String>(1)!=key{bail!("Search identity changed before publication")}
 Ok((output,budget))
}

#[cfg(test)]
mod tests {
 use super::*;
 #[test]
 fn actual_php_search_account_and_self_policy_match(){
  let root=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../..");
  let result=std::process::Command::new(root.join("api/bin/php-policy-test")).arg(root.join("api/bin/search-moderation-reference-capture.php")).output().unwrap();
  assert!(result.status.success(),"actual PHP policy failed: {} {}",String::from_utf8_lossy(&result.stderr),String::from_utf8_lossy(&result.stdout));
  let corpus:Value=serde_json::from_slice(&result.stdout).unwrap();let mut checked=0;
  for (name,case) in corpus["cases"].as_object().unwrap(){
   let identity=SearchIdentity{refs:case["identity"]["refs"].as_object().map(|refs|refs.keys().cloned().collect()).unwrap_or_default()};
   let mut hidden=HiddenSets::default();let rule=&case["rule"];let row=&rule["row"];let owner=case["owner"].as_i64().unwrap();
   match text(rule,"table") {
    "fixture_blocks" if owner==row["owner_user_id"].as_i64().unwrap()=>if text(row,"scope")=="domain"{hidden.user_blocked_domains.insert(text(row,"value").into());}else{hidden.user_blocked_actors.extend(crate::hidden::actor_aliases(text(row,"value")));},
    "fixture_mutes" if owner==row["owner_user_id"].as_i64().unwrap()=>hidden.muted_actors.extend(crate::hidden::actor_aliases(text(row,"actor_id"))),
    "fixture_server_blocks"=>if text(row,"scope")=="domain"{hidden.instance_blocked_domains.insert(text(row,"value").into());}else if text(row,"kind")=="mute"{hidden.instance_muted_actors.extend(crate::hidden::actor_aliases(text(row,"value")).iter().map(|actor|actor.to_ascii_lowercase()));}else{hidden.instance_blocked_actors.extend(crate::hidden::actor_aliases(text(row,"value")));},_=>{},
   }
   hidden.muted_phrases.push("word-mute".into());
   assert_eq!(account_hidden(&case["account"],&identity,&hidden),case["account_hidden"].as_bool().unwrap(),"account {name}");
   assert_eq!(status_hidden(&case["status"],&identity,&hidden),case["status_hidden"].as_bool().unwrap(),"status {name}");checked+=1;
   for (variant,item) in case["variants"].as_object().unwrap(){assert_eq!(status_hidden(&item["status"],&identity,&hidden),item["hidden"].as_bool().unwrap(),"{name} {variant}");}
  }
  assert_eq!(checked,324);
 }
}
