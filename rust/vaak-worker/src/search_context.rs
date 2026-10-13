//! Search-specific actor policy. Neither Library word filters nor profile mute
//! exceptions describe discovery. Private render tokens bind the final current
//! source/facts after freshness, indexing and durable audience checks.
use serde_json::Value;
#[path="search_selection.rs"]
pub(crate) mod selection;
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
fn account_hidden(account:&Value,identity:&SearchIdentity,hidden:&HiddenSets)->bool {account_hidden_with_sources(account,identity,hidden,&[])}
fn account_hidden_with_sources(account:&Value,identity:&SearchIdentity,hidden:&HiddenSets,sources:&[String])->bool {
 let mut refs=Vec::new();for field in ["uri","url","id"]{refs.extend(actor_refs(text(account,field)))}
 let acct=trim(text(account,"acct")).trim_start_matches('@');
 let mut host=String::new();
 static PATTERN:OnceLock<regex::Regex>=OnceLock::new();
 if let Some(m)=PATTERN.get_or_init(||regex::Regex::new(r"^([^@\s]+)@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})$").unwrap()).captures(acct){
  let username=m[1].to_ascii_lowercase();host=m[2].to_ascii_lowercase();
  if matches!(host.as_str(),"vaak.monster"|"www.vaak.monster"){refs.push(format!("https://mkultra.monster/users/{username}"));host="mkultra.monster".into();}
 }
 if identity.is_self(&refs,acct){return false}
 refs.extend(sources.iter().flat_map(|source|actor_refs(source)));
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
pub(crate) struct SearchSession {#[cfg(test)] fixture_clock:Option<chrono::DateTime<chrono::Utc>>,bridge_identity:Option<(String,String)>,owner:i64,csrf:String,bsky_enabled:bool,bsky_did:String,bookmarks:std::collections::HashMap<String,bool>,warm:Option<std::sync::Arc<crate::profile_enrichment::WarmContext>>,collector:Option<std::sync::Arc<selection::SearchWindowGrant>>,components:Option<std::sync::Arc<crate::search_components::admission::ComponentsGrant>>}
impl SearchSession {
 #[cfg(test)] pub(crate) fn with_fixture_clock(mut self,clock:Option<chrono::DateTime<chrono::Utc>>)->Self{self.fixture_clock=clock;self}
 pub(crate) fn with_components(mut self,components:Option<std::sync::Arc<crate::search_components::admission::ComponentsGrant>>)->Self{self.components=components;self}
 pub(crate) fn with_bridge_identity(mut self,actor:String,key:String)->Self{self.bridge_identity=Some((actor,key));self}
 pub(crate) fn owner_id(&self)->i64{self.owner}
 pub(crate) fn bsky_did(&self)->&str{&self.bsky_did}
 pub(crate) fn warm_context(&self)->Option<&crate::profile_enrichment::WarmContext>{self.warm.as_deref()}
 pub(crate) fn collector(&self)->Option<&std::sync::Arc<selection::SearchWindowGrant>>{self.collector.as_ref()}
 pub(crate) async fn with_collector(mut self,db:&Client,descriptor:Option<selection::SearchCollectorDescriptor>,sources:&[Value])->Result<Self>{
  if let Some(descriptor)=descriptor{self.collector=Some(std::sync::Arc::new(selection::SearchWindowGrant::load(db,self.owner,descriptor,sources).await?));}
  Ok(self)
 }

 pub(crate) fn with_warm_context(mut self,warm:Option<std::sync::Arc<crate::profile_enrichment::WarmContext>>)->Self{self.warm=warm;self}
 pub(crate) fn from_php_session(owner:i64,csrf:String,bsky_enabled:bool)->Result<Self>{
  if owner<1||csrf.is_empty(){bail!("invalid canonical Search session")}
  Ok(Self{#[cfg(test)] fixture_clock:None,bridge_identity:None,owner,csrf,bsky_enabled,bsky_did:String::new(),bookmarks:Default::default(),warm:None,collector:None,components:None})
 }
 pub(crate) async fn with_native_bookmarks(mut self,db:&Client,states:Vec<(String,String,bool)>)->Result<Self>{
  if states.len()>121{bail!("Search provenance exceeds canonical window")}
  let did=crate::library::context::current_bsky_did(db,self.owner).await?;
  self.bsky_did=did.clone();
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
pub(super) fn local_note_uri(uri:&str)->bool {
 static PATTERN:OnceLock<regex::Regex>=OnceLock::new();
 PATTERN.get_or_init(||regex::Regex::new(r"^https://mkultra\.monster/users/[^/]+/notes/").unwrap()).is_match(uri)
}
fn current_tree<'a>(db:&'a Client,owner:i64,status:&'a Value,identity:&'a SearchIdentity,hidden:&'a HiddenSets,depth:usize)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<bool>>+Send+'a>>{
 Box::pin(async move{
  if depth>16||status_hidden(status,identity,hidden){return Ok(false)}
  let uri=trim(status.get("uri").or_else(||status.get("url")).and_then(Value::as_str).unwrap_or("")).split('#').next().unwrap_or("").trim_end_matches('/');
  let mut author=canonical_actor(status["account"].get("uri").or_else(||status["account"].get("url")).and_then(Value::as_str).unwrap_or(""));
  let raw=status.get("bsky_post");let post=raw.map(|value|value.get("post").filter(|v|v.is_object()).unwrap_or(value));
  let at=post.map(|value|text(value,"uri")).filter(|uri|!uri.is_empty()).unwrap_or(if uri.starts_with("at://"){uri}else{""});
  if !at.is_empty(){if !db.query_one("SELECT EXISTS(SELECT 1 FROM bsky_posts WHERE bsky_uri=$1)",&[&at]).await?.get::<_,bool>(0){return Ok(false)}}
  else if local_note_uri(uri){
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

/// Surface-specific source authority used before and after shared projector IO.
/// Search deliberately uses its existing mute/self policy, not profile policy.
pub(crate) async fn require_selected_current(db:&Client,owner:i64,actor:&str,key:&str,status:&Value)->Result<()> {
 if !selected_current_allowed(db,owner,actor,key,status).await?{bail!("selected Search source withdrawn")}
 Ok(())
}
pub(crate) async fn selected_current_allowed(db:&Client,owner:i64,actor:&str,key:&str,status:&Value)->Result<bool> {
 Ok(selected_current_mask(db,owner,actor,key,std::slice::from_ref(status)).await?.first()==Some(&true))
}
pub(crate) async fn selected_current_mask(db:&Client,owner:i64,actor:&str,key:&str,statuses:&[Value])->Result<Vec<bool>> {
 #[cfg(test)] let _timing=selection::fixture_performance::span("selected_policy_mask",statuses.len());
 let mut identity=SearchIdentity{refs:crate::hidden::actor_aliases(actor).iter().map(|value|value.to_ascii_lowercase()).collect()};
 for value in [format!("acct:{}",key.to_ascii_lowercase()),format!("acct:{}@mkultra.monster",key.to_ascii_lowercase()),format!("acct:{}@vaak.monster",key.to_ascii_lowercase()),format!("https://mkultra.monster/users/{}",key.to_ascii_lowercase()),format!("https://vaak.monster/users/{}",key.to_ascii_lowercase()),format!("https://www.vaak.monster/users/{}",key.to_ascii_lowercase())]{identity.refs.insert(value);}
 let mut hidden=crate::hidden::load_search_hidden_sets(db,owner).await?;hidden.muted_phrases.clear();
 let sources=selection::CurrentSourceRows::load(db,statuses).await?;
 let audience=crate::audience::admission_mask(db,owner,crate::audience::Surface::Search,statuses).await?;
 Ok(statuses.iter().zip(audience).map(|(status,allowed)|allowed&&sources.tree_allowed(status,owner,&identity,&hidden,0)).collect())
}

/// Fresh actor policy for actual account collector rows. Account discovery does
/// not apply the status/tag indexing predicate or word-mute predicate in PHP.
pub(crate) async fn require_component_accounts(db:&Client,owner:i64,actor:&str,key:&str,accounts:&[Value],source_refs:&[Vec<String>])->Result<()> {
 if accounts.len()!=source_refs.len(){bail!("Search account policy source count differs")}
 let mut identity=SearchIdentity{refs:crate::hidden::actor_aliases(actor).iter().map(|v|v.to_ascii_lowercase()).collect()};
 for value in [format!("acct:{}",key.to_ascii_lowercase()),format!("acct:{}@mkultra.monster",key.to_ascii_lowercase()),format!("acct:{}@vaak.monster",key.to_ascii_lowercase())]{identity.refs.insert(value);}
 let mut hidden=crate::hidden::load_search_hidden_sets(db,owner).await?;hidden.muted_phrases.clear();
 if accounts.iter().zip(source_refs).any(|(account,sources)|account_hidden_with_sources(account,&identity,&hidden,sources)){bail!("Search account policy changed")}
 Ok(())
}

/// Actual hashtag corpus: outbox rows use indexing/actor/audience policy but
/// do not require the separate masto_statuses projection. Event/mention rows
/// use the complete ordinary Search status current-source predicate.
pub(crate) async fn current_tag_contributions(db:&Client,owner:i64,actor:&str,key:&str,rows:&[(bool,Value)])->Result<Vec<bool>> {
 let statuses:Vec<Value>=rows.iter().map(|(_,status)|status.clone()).collect();
 let ordinary=selected_current_mask(db,owner,actor,key,&statuses).await?;
 let mut identity=SearchIdentity{refs:crate::hidden::actor_aliases(actor).iter().map(|v|v.to_ascii_lowercase()).collect()};
 for value in [format!("acct:{}",key.to_ascii_lowercase()),format!("acct:{}@mkultra.monster",key.to_ascii_lowercase()),format!("acct:{}@vaak.monster",key.to_ascii_lowercase())]{identity.refs.insert(value);}
 let mut hidden=crate::hidden::load_search_hidden_sets(db,owner).await?;hidden.muted_phrases.clear();
 let sources=selection::CurrentSourceRows::load(db,&statuses).await?;
 let audience=crate::audience::admission_mask(db,owner,crate::audience::Surface::Search,&statuses).await?;
 Ok(rows.iter().enumerate().map(|(i,(outbox,status))|if *outbox {
   let author=canonical_actor(text(&status["account"],"uri"));
   let indexed=sources.indexability.get(&author).is_none_or(|(id,index)|*id==owner||*index!=0);
   indexed && !status_hidden(status,&identity,&hidden) && audience[i]
 }else{ordinary[i]}).collect())
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
 let bsky_source=statuses.iter().any(crate::library::context::has_bsky_source);
 if bsky_source{crate::library::context::require_bsky_did(db,session.owner,&session.bsky_did).await?;}
 let mut budget=preview.map(|value|value.0).unwrap_or(0);
 if statuses.len()>40{bail!("Search page bound exceeds canonical limit")}
 let row=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.context("inactive Search owner")?;
 let actor:String=row.try_get(0)?;let key:String=row.try_get(1)?;
 let mut identity=SearchIdentity{refs:crate::hidden::actor_aliases(&actor).iter().map(|value|value.to_ascii_lowercase()).collect()};
 for value in [format!("acct:{}",key.to_ascii_lowercase()),format!("acct:{}@mkultra.monster",key.to_ascii_lowercase()),format!("acct:{}@vaak.monster",key.to_ascii_lowercase()),format!("https://mkultra.monster/users/{}",key.to_ascii_lowercase()),format!("https://vaak.monster/users/{}",key.to_ascii_lowercase()),format!("https://www.vaak.monster/users/{}",key.to_ascii_lowercase())]{identity.refs.insert(value);}
 let mut hidden=crate::hidden::load_search_hidden_sets(db,session.owner).await?;hidden.muted_phrases.clear();
 let profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1 LIMIT 1",&[&key]).await?.map(|row|row.get::<_,Value>(0)).unwrap_or(Value::Null);
 let standalone_scope=if session.collector().is_none(){Some(selection::final_facts::Scope::new(session.owner,&key,&actor,&statuses,&[],&[],false))}else{None};
 let standalone_snapshot=if let Some(scope)=&standalone_scope{Some(scope.read(db).await?)}else{None};
 if session.collector().is_some_and(|collector|collector.viewer_profile()!=&profile){bail!("Search viewer Settings changed before assembly")}
 let auto_unblur=crate::library::card::boolean(&profile,"auto_unblur_sensitive");
 let mut output=Vec::new();let mut source_grants=Vec::new();
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
  // Known current audience denial is a lawful omitted source slot, not a
  // reason to resolve its actor/Ask/media facts. Preserve collector counts and
  // cursor arithmetic while ensuring enrichment cannot precede this gate.
  if crate::audience::admission_mask(db,session.owner,crate::audience::Surface::Search,std::slice::from_ref(&status)).await?.first()!=Some(&true){continue}
  crate::interaction_flags::apply_to_statuses(db,session.owner,std::slice::from_mut(&mut status)).await?;
  let source_grant=if session.warm.is_some(){Some(std::sync::Arc::new(crate::library::context::SelectedLibrarySearchSource::search(db,session,status.clone(),source_index,&actor,&key).await?))}else{None};
  #[cfg(test)] selection::final_facts::boundary_fixture::at(db,"before_status_card_facts").await?;
  let mut facts=if let (Some(warm),Some(selected))=(&session.warm,&source_grant){
   let bound=crate::library::facts::BoundPresentationSession{session,warm:warm.bound(selected.clone())};
   crate::library::facts::resolve(db,session.owner,&actor,&status,&bound,auto_unblur).await?
  }else{crate::library::facts::resolve(db,session.owner,&actor,&status,session,auto_unblur).await?};
  #[cfg(test)] selection::final_facts::boundary_fixture::at(db,"after_status_card_facts").await?;
  #[cfg(test)] if let Some(clock)=session.fixture_clock{facts.now=clock;}
  if let Some(sid)=crate::library::context::incoming_ap_boost_sid(&status){facts.boosted=crate::library::context::exact_incoming_boost(db,session.owner,&sid).await?;}
  if facts.rss_quote_row.is_some(){bail!("PHP fallback: fresh RSS quote needs complete Search source binding")}
  let inner=status.get("reblog").is_some_and(Value::is_object)&&text(&status,"content").is_empty();
  let displayed=if inner{status.get_mut("reblog").unwrap()}else{&mut status};
  if let Some(own)=facts.own.as_ref(){if !own.plain.is_empty()&&!matches!(own.plain.as_str(),"(quote)"|"(media)"|"(poll)"){displayed["content"]=Value::String(crate::library::actions::esc(&own.plain))}displayed["spoiler_text"]=Value::String(own.spoiler.clone());displayed["sensitive"]=Value::Bool(own.sensitive);}
  if let Some(own)=facts.own.as_mut(){let raw=text(displayed,"uri");let exact=db.query_opt("SELECT local_id::bigint FROM masto_statuses WHERE note_id=$1 OR note_id=$2 LIMIT 1",&[&raw,&format!("{}/",raw.trim_end_matches('/'))]).await?;own.local_id=exact.map(|row|row.get::<_,i64>(0)).unwrap_or(0);}
  if let Some(ask)=facts.ask_row.as_mut(){displayed["vaak_ask"]=ask.clone();let plain=crate::library::body::html_to_plain(text(displayed,"content"));let body=crate::library::body::ask_plain_body(&plain,original_content.as_deref().unwrap_or(""),ask.get("_body_answer").and_then(Value::as_str).unwrap_or(""),!crate::library::card::ask_html(ask)?.is_empty());displayed["content"]=Value::String(crate::library::actions::esc(&body));ask["_body_answer"]=Value::String(body);}
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
     let card=if let Some(grant)=&source_grant{
      if let Some(fetch)=session.warm.as_ref().and_then(|warm|warm.preview_context()){
       struct PreviewGrant<'a>{db:&'a Client,source:&'a crate::library::context::SelectedLibrarySearchSource}
       impl crate::profile_quote_warm::CurrentQuoteGrant for PreviewGrant<'_>{
        fn require_current(&self)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'_>>{Box::pin(async move{self.source.require_current(self.db).await})}
        fn bind_preview_text<'a>(&'a self,text:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{Box::pin(async move{self.source.bind_search_preview_text(self.db,text).await})}
        fn bind_preview_write_url<'a>(&'a self,url:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{Box::pin(async move{self.source.bind_search_preview_write_url(self.db,url).await})}
        #[cfg(test)] fn fixture_after_preview_write<'a>(&'a self,db:&'a Client,fetch:&'a crate::profile_preview_fetch::PreviewFetchContext,now:chrono::DateTime<chrono::Utc>)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{Box::pin(async move{if std::env::var("VAAK_SEARCH_PREVIEW_TERMINAL_CASE").as_deref()==Ok("outside_selected_receipt"){crate::profile_preview_fetch::fixture_reject_outside_receipt(db,fetch,now,self).await?;}Ok(())})}
        fn accept_preview_write(&self,write:&crate::profile_preview_fetch::PreviewCacheWrite)->Result<()>{crate::profile_enrichment::CurrentEnrichmentGrant::accept_preview_write(self.source,write)}
       }
       crate::library::preview::resolve(db,dns,&status,original_content.as_deref(),&facts,&mut budget,fetch,&PreviewGrant{db,source:grant.as_ref()}).await?
      }else{crate::library::preview::resolve_cached_current(db,dns,&status,original_content.as_deref(),&facts,&mut budget,grant.as_ref()).await?}
     }else{crate::direct_preview::resolve(db,dns,&status,original_content.as_deref(),&facts,&mut budget).await?};
     if !current_tree(db,session.owner,&status,&identity,&hidden,0).await?{continue}
     let mut final_checked=vec![status.clone()];crate::audience::filter_statuses(db,session.owner,crate::audience::Surface::Search,&mut final_checked).await?;
     if final_checked.is_empty(){continue}
     Some(card)
   }else{None};
   output.push(AdmittedSearchStatus{source_index,status,facts,root_preview});if let Some(grant)=source_grant{source_grants.push(grant)}}
 }
 let final_owner=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.context("Search owner changed before publication")?;
 if final_owner.get::<_,String>(0)!=actor||final_owner.get::<_,String>(1)!=key{bail!("Search identity changed before publication")}
 if let Some((bridge_actor,bridge_key))=&session.bridge_identity{if &actor!=bridge_actor||&key!=bridge_key{bail!("Search bridge identity changed during assembly")}}
 if bsky_source{crate::library::context::require_bsky_did(db,session.owner,&session.bsky_did).await?;}
 let current_profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1 LIMIT 1",&[&key]).await?.map(|row|row.get::<_,Value>(0)).unwrap_or(Value::Null);
 if current_profile!=profile{bail!("Search viewer Settings changed before publication")}
 if let Some(collector)=session.collector(){
  // Owner and DID SQL awaits above precede this one fresh complete window.
  // All selected opaque sources then bind synchronously to the same capability;
  // no permission result is retained or reused across an enrichment await.
  let final_window=collector.refresh_current_with_components(db,session.components.as_deref()).await?;
  for grant in source_grants{grant.require_fresh_search_window(&final_window,&session.bsky_did)?;}
 }else{
  if let Some(components)=&session.components{components.require_ordered_current(db).await?;}
  #[cfg(test)] selection::final_facts::boundary_fixture::at(db,"after_components_before_status").await?;
  for grant in source_grants{grant.require_current(db).await?;}
  #[cfg(test)] selection::final_facts::boundary_fixture::at(db,"after_status_before_final_raw").await?;
  let scope=standalone_scope.as_ref().context("missing Search final raw source scope")?;
  let after=if let Some(components)=&session.components{
   let (component_scope,before)=components.raw_binding();let (after,component_after)=scope.read_pair(db,component_scope).await?;
   selection::final_facts::Scope::require_same(before,&component_after,None)?;after
  }else{scope.read(db).await?};
  selection::final_facts::Scope::require_same(standalone_snapshot.as_ref().context("missing Search raw source snapshot")?,&after,Some(&profile))?;
 }
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
