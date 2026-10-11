//! Explicit-open admission, separate from discovery and saved-list moderation.
//! PHP's authenticated server session is the sole future construction boundary.
use anyhow::{bail,Context,Result};
use serde_json::Value;
use tokio_postgres::Client;

#[derive(Clone,Copy)]
pub(crate) enum DirectRoute {Status,RemoteProfile}
/// Presentation intent only. No role, recipient, mute or permission decision.
#[derive(Clone)]
pub(crate) struct DirectPresentation {focused:bool,show_open:bool,hide_header:bool,profile_actor:String,root_preview:Option<Value>,pending_quote:Option<std::sync::Arc<crate::profile_quote_warm::PendingQuotePresentation>>,pending_quote_suppressed:bool}
impl DirectPresentation {
 pub(crate) fn pending_quote_suppressed(&self)->bool{self.pending_quote_suppressed}
 pub(crate) fn pending_quote(&self)->Option<&crate::profile_quote_warm::PendingQuotePresentation>{self.pending_quote.as_deref()}
 pub(crate) fn root_preview(&self)->Option<&Value>{self.root_preview.as_ref()}
 pub(crate) fn focused(&self)->bool{self.focused}
 pub(crate) fn show_open(&self)->bool{self.show_open}
 pub(crate) fn hide_header(&self)->bool{self.hide_header}
 pub(crate) fn profile_actor(&self)->&str{&self.profile_actor}
}
#[derive(Clone)]
pub(crate) struct DirectSession {route:DirectRoute,owner:i64,csrf:String,return_view:String,lazy_media:bool,status_preview_budget:i64,quote_preview_budget:Option<i64>,warm_context:Option<std::sync::Arc<crate::profile_enrichment::WarmContext>>,profile_bsky:Option<crate::profile_bsky::ProfileBskyPresentation>,presentation:DirectPresentation}
impl DirectSession {
 pub(crate) fn from_php_session(owner:i64,csrf:String,route:DirectRoute,return_view:String,focused:bool,show_open:bool,hide_header:bool,lazy_media:bool,profile_actor:String)->Result<Self>{
  if owner<1||csrf.is_empty(){bail!("PHP fallback: guest uses canonical legacy profile provider")}
  if return_view.is_empty()||return_view.len()>64||!return_view.bytes().all(|b|b.is_ascii_lowercase()||b==b'_'){bail!("invalid canonical direct action origin")}
  if focused&&!matches!(route,DirectRoute::Status){bail!("invalid canonical direct focus")}
  if matches!(route,DirectRoute::RemoteProfile)&&return_view!="remote_profile"{bail!("profile painter requires canonical direct return context")}
  if !profile_actor.is_empty(){let url=reqwest::Url::parse(&profile_actor).context("invalid profile presentation origin")?;if profile_actor.len()>2048||!matches!(url.scheme(),"https"|"http")||!url.username().is_empty()||url.password().is_some()||url.fragment().is_some(){bail!("invalid profile presentation origin")}}
  Ok(Self{route,owner,csrf,return_view,lazy_media,status_preview_budget:3,quote_preview_budget:None,warm_context:None,profile_bsky:None,presentation:DirectPresentation{focused,show_open,hide_header,profile_actor,root_preview:None,pending_quote:None,pending_quote_suppressed:false}})
 }
 pub(crate) fn with_suppressed_pending_quote(mut self)->Self{self.presentation.pending_quote_suppressed=true;self}
 pub(crate) fn with_profile_bsky(mut self,context:crate::profile_bsky::ProfileBskyPresentation)->Result<Self>{if !matches!(self.route,DirectRoute::RemoteProfile)||context.owner()!=self.owner{bail!("profile protocol capability requires bound owner/profile route")}self.profile_bsky=Some(context);Ok(self)}
 pub(crate) fn profile_bsky(&self)->Option<&crate::profile_bsky::ProfileBskyPresentation>{self.profile_bsky.as_ref()}
 pub(crate) fn with_pending_quote(mut self,quote:std::sync::Arc<crate::profile_quote_warm::PendingQuotePresentation>)->Self{self.presentation.pending_quote=Some(quote);self}
 pub(crate) fn with_warm_context(mut self,context:std::sync::Arc<crate::profile_enrichment::WarmContext>)->Self{self.warm_context=Some(context);self}
 pub(crate) fn with_quote_preview_budget(mut self,budget:i64)->Self{self.quote_preview_budget=Some(budget);self}
 pub(crate) fn status_preview_budget(&self)->i64{self.status_preview_budget}
 pub(crate) fn quote_preview_budget(&self)->Option<i64>{self.quote_preview_budget}
 pub(crate) fn with_status_preview_budget(mut self,budget:i64)->Result<Self>{if !(0..=100).contains(&budget){bail!("invalid trusted preview budget")}self.status_preview_budget=budget;Ok(self)}
}
impl crate::library::facts::PresentationSession for DirectSession {
 fn warm_context(&self)->Option<&crate::profile_enrichment::WarmContext>{self.warm_context.as_deref()}
 fn csrf(&self)->&str{&self.csrf}
 fn return_view(&self)->&str{&self.return_view}
 fn bsky_enabled(&self)->bool{self.profile_bsky.as_ref().is_some_and(|context|context.enabled())}
 fn lazy_media(&self)->bool{self.lazy_media}
 fn bsky_bookmark_state(&self,status:&Value)->Option<bool>{self.profile_bsky.as_ref().and_then(|context|context.bookmark(status))}
}
/// Final source and current facts bound together; no JSON constructor.
pub(crate) struct AdmittedDirectStatus {source_index:usize,status:Value,facts:crate::library::card::CardFacts,presentation:DirectPresentation}
impl AdmittedDirectStatus {
 pub(crate) fn source_index(&self)->usize{self.source_index}
 pub(crate) fn status(&self)->&Value{&self.status}
 pub(crate) fn facts(&self)->&crate::library::card::CardFacts{&self.facts}
 pub(crate) fn presentation(&self)->&DirectPresentation{&self.presentation}
}
fn text<'a>(v:&'a Value,key:&str)->&'a str{v.get(key).and_then(Value::as_str).unwrap_or("")}
fn children(value:&Value)->Vec<(&str,&Value)>{["reblog","quote","vaak_quote_preview"].iter().filter_map(|key|value.get(*key).filter(|v|v.is_object()).map(|v|(*key,v.get("quoted_status").or_else(||v.get("status")).filter(|v|v.is_object()).unwrap_or(v)))).collect()}
pub(crate) fn local_outbox_tree<'a>(db:&'a Client,status:&'a Value,depth:usize)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<bool>>+Send+'a>>{
 Box::pin(async move{
  if depth>16{return Ok(false)}
  let uri=crate::library::card::php_trim(status.get("uri").or_else(||status.get("url")).and_then(Value::as_str).unwrap_or("")).split('#').next().unwrap_or("").trim_end_matches('/');
  // Local durable audience proof precedes ordinary preview effects. A live
  // remote-style event cannot replace the canonical local outbox grant.
  if regex::Regex::new(r"^https://mkultra\.monster/users/[^/]+/notes/").unwrap().is_match(uri)&&!db.query_one("SELECT EXISTS(SELECT 1 FROM outbox_notes WHERE id IN ($1,$2))",&[&uri,&format!("{uri}/")]).await?.get::<_,bool>(0){return Ok(false)}
  for (_,child) in children(status){if !local_outbox_tree(db,child,depth+1).await?{return Ok(false)}}
  Ok(true)
 })
}
pub(crate) fn current_tree<'a>(db:&'a Client,status:&'a Value,depth:usize)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<bool>>+Send+'a>>{
 Box::pin(async move{
  if depth>16{return Ok(false)}
  let uri=crate::library::card::php_trim(status.get("uri").or_else(||status.get("url")).and_then(Value::as_str).unwrap_or("")).split('#').next().unwrap_or("").trim_end_matches('/');
  if uri.is_empty(){return Ok(matches!(text(status,"state"),"pending"|"accepted"|"fallback")&&["account","content","text","media","media_attachments"].iter().all(|key|status.get(*key).is_none()))}
  if regex::Regex::new(r"^https://mkultra\.monster/users/[^/]+/notes/").unwrap().is_match(uri){
   if !db.query_one("SELECT EXISTS(SELECT 1 FROM masto_statuses WHERE rtrim(note_id,'/')=$1)",&[&uri]).await?.get::<_,bool>(0){return Ok(false)}
   // Canonical audience requires local outbox proof even if remote-style
   // event metadata exists or a cached current Mastodon row still survives.
   if !db.query_one("SELECT EXISTS(SELECT 1 FROM outbox_notes WHERE id IN ($1,$2))",&[&uri,&format!("{uri}/")]).await?.get::<_,bool>(0){return Ok(false)}
  }else if let Some(row)=db.query_opt("SELECT type,action_taken FROM events WHERE object_id IN ($1,$2) AND (type IN ('Create','Update','Delete','Tombstone') OR (type IN ('Quote','QuotePost') AND NOT EXISTS(SELECT 1 FROM events note_source WHERE note_source.object_id IN ($1,$2) AND note_source.type IN ('Create','Update')))) ORDER BY id DESC LIMIT 1",&[&uri,&format!("{uri}/")]).await?{
   let kind:String=row.get(0);let action:String=row.get::<_,Option<String>>(1).unwrap_or_default();if action=="deleted"||matches!(kind.as_str(),"Delete"|"Tombstone"){return Ok(false)}
  }else if let Some(row)=db.query_opt("SELECT deleted_at::text FROM mentions WHERE object_id IN ($1,$2) ORDER BY id DESC LIMIT 1",&[&uri,&format!("{uri}/")]).await?{
   if row.get::<_,Option<String>>(0).is_some_and(|value|!value.is_empty()&&value!="0"){return Ok(false)}
  }else{return Ok(false)}
  for (_,child) in children(status){if !current_tree(db,child,depth+1).await?{return Ok(false)}}Ok(true)
 })
}
fn contains_quote(status:&Value,depth:usize)->bool {depth>16||status.get("quote").is_some()||status.get("vaak_quote_preview").is_some()||children(status).iter().any(|(_,child)|contains_quote(child,depth+1))}
/// Same durable predicates as local_outbox_tree/current_tree, read together at
/// a profile stage boundary. Values and authority results are never memoized.
pub(crate) async fn profile_trees_current(db:&Client,statuses:&[&Value])->Result<bool>{
 fn collect(status:&Value,depth:usize,current:bool,items:&mut std::collections::BTreeMap<String,(bool,bool)>)->bool{
  if depth>16{return false}
  let uri=crate::library::card::php_trim(status.get("uri").or_else(||status.get("url")).and_then(Value::as_str).unwrap_or("")).split('#').next().unwrap_or("").trim_end_matches('/');
  let mut child_current=current;
  if uri.is_empty(){if current&&!(matches!(text(status,"state"),"pending"|"accepted"|"fallback")&&["account","content","text","media","media_attachments"].iter().all(|key|status.get(*key).is_none())){return false}child_current=false;}
  else{let local=regex::Regex::new(r"^https://mkultra\.monster/users/[^/]+/notes/").unwrap().is_match(uri);let entry=items.entry(uri.into()).or_insert((local,false));entry.1|=current;}
  children(status).iter().all(|(_,child)|collect(child,depth+1,child_current,items))
 }
 let mut items=std::collections::BTreeMap::new();for status in statuses{if !collect(status,0,true,&mut items){return Ok(false)}}
 if items.is_empty(){return Ok(true)}
 let input=Value::Array(items.iter().map(|(uri,(local,current))|serde_json::json!({"uri":uri,"local":local,"current":current})).collect());
 let rows=db.query("SELECT CASE WHEN (i->>'local')::boolean THEN EXISTS(SELECT 1 FROM outbox_notes WHERE id IN (i->>'uri',(i->>'uri')||'/')) AND (NOT (i->>'current')::boolean OR EXISTS(SELECT 1 FROM masto_statuses WHERE rtrim(note_id,'/')=i->>'uri')) WHEN NOT (i->>'current')::boolean THEN true WHEN e.type IS NOT NULL THEN COALESCE(e.action_taken,'')<>'deleted' AND e.type NOT IN ('Delete','Tombstone') WHEN m.id IS NOT NULL THEN COALESCE(m.deleted_at::text,'') IN ('','0') ELSE false END FROM jsonb_array_elements($1::jsonb) i LEFT JOIN LATERAL (SELECT type,action_taken FROM events WHERE object_id IN (i->>'uri',(i->>'uri')||'/') AND (type IN ('Create','Update','Delete','Tombstone') OR (type IN ('Quote','QuotePost') AND NOT EXISTS(SELECT 1 FROM events note_source WHERE note_source.object_id IN (i->>'uri',(i->>'uri')||'/') AND note_source.type IN ('Create','Update')))) ORDER BY id DESC LIMIT 1) e ON true LEFT JOIN LATERAL (SELECT id,deleted_at FROM mentions WHERE object_id IN (i->>'uri',(i->>'uri')||'/') ORDER BY id DESC LIMIT 1) m ON true",&[&input]).await?;
 Ok(rows.len()==items.len()&&rows.iter().all(|row|row.get::<_,bool>(0)))
}
fn supported_tree(status:&Value,depth:usize)->Result<()> {supported_tree_mode(status,depth,false,false)}
fn supported_tree_mode(status:&Value,depth:usize,plain_quote_effects:bool,pending_quote:bool)->Result<()> {
 if depth>16{bail!("PHP fallback: direct source depth")}
 // PHP null-coalesces uri to url; the shared native painter requires the
 // normalized string shape. Preserve the canonical request for this shape.
 if status.get("uri").is_some_and(|uri|!uri.is_string()){bail!("PHP fallback: direct source URI normalization")}
 let uri=text(status,"uri");let id=crate::library::card::scalar_string(status,"id");
 if !uri.is_empty()&&!uri.starts_with("https://"){bail!("PHP fallback: direct object URI lacks supported durable HTTPS identity")}
 if matches!(text(status,"source"),"rss"|"bluesky")||id.starts_with("rss:")||id.starts_with("bsky:")||uri.starts_with("at://")||uri.contains("bsky.app/")||["post","bsky_post","bsky_post_view"].iter().any(|key|status.get(*key).is_some_and(Value::is_object)){bail!("PHP fallback: direct protocol normalization not yet certified")}
 if status.get("vaak_quote_preview").is_some(){bail!("PHP fallback: compact direct preview normalization")}
 if status.get("record").is_some_and(Value::is_object)||status.get("vaak_bsky_text").is_some_and(|value|value.as_str().is_none_or(|text|!text.is_empty()))||status.get("vaak_bsky_facets").is_some_and(|value|value.as_array().is_none_or(|facets|!facets.is_empty())){bail!("PHP fallback: direct facet bundle normalization")}
 for (field,child) in children(status){
  if field=="quote" {
   if !child.get("account").is_some_and(Value::is_object){if pending_quote&&depth==0{continue}bail!("PHP fallback: pending or late direct quote recovery")}
   let plain=crate::library::body::scrub_as2(&crate::library::body::html_to_plain(text(child,"content")));
   if child.get("media_attachments").and_then(Value::as_array).is_none_or(Vec::is_empty)&&!child.get("card").is_some_and(Value::is_object)&&!crate::library::card::php_trim(&plain).is_empty()&&!plain_quote_effects{bail!("PHP fallback: direct dynamic quote preview effects")}
  }
  supported_tree_mode(child,depth+1,plain_quote_effects,false)?;
 }Ok(())
}
async fn status_root_content_blocked(db:&Client,owner:i64,status:&Value)->Result<bool>{
 // Canonical corrected status controller uses the original wrapper author,
 // never the displayed boost child or the profile presentation-origin actor.
 let account=&status["account"];
 let uri=crate::library::card::scalar_string(account,"uri");
 let url=crate::library::card::scalar_string(account,"url");
 let actor=if uri.is_empty()||uri=="0"{url.as_str()}else{uri.as_str()}.trim_end_matches('/');
 if actor.is_empty(){return Ok(false)}
 let rows=db.query("SELECT scope,value FROM ap_blocks WHERE COALESCE(kind,'block')<>'mute' UNION ALL SELECT scope,value FROM ap_user_blocks WHERE owner_user_id=$1::bigint",&[&owner]).await?.iter().map(|row|serde_json::json!({"scope":row.get::<_,String>(0),"value":row.get::<_,String>(1)})).collect::<Vec<_>>();
 Ok(crate::library::facts::personal_block(&rows,actor).is_some())
}
/// Current source/audience proof plus the corrected Status caller root block.
/// Profile target/header admission remains a separate future producer gate;
/// presentation origin cannot serve as profile authority.

pub(crate) struct DirectBatch {cards:Vec<AdmittedDirectStatus>,status_preview_budget:i64,status_preview_budget_changed:bool,quote_state:Option<crate::profile_quote_state::QuotePreviewState>}
impl DirectBatch {#[cfg(test)] pub(crate) fn set_render_clock_for_fixture(&mut self,clock:chrono::DateTime<chrono::Utc>){for card in &mut self.cards{card.facts.now=clock;}} pub(crate) fn quote_state(&self)->Option<&crate::profile_quote_state::QuotePreviewState>{self.quote_state.as_ref()} pub(crate) fn final_status_preview_budget(&self)->i64{self.status_preview_budget} pub(crate) fn status_preview_budget_changed(&self)->bool{self.status_preview_budget_changed}}
impl std::ops::Deref for DirectBatch {type Target=[AdmittedDirectStatus];fn deref(&self)->&Self::Target{&self.cards}}
impl std::ops::DerefMut for DirectBatch {fn deref_mut(&mut self)->&mut Self::Target{&mut self.cards}}
pub(crate) async fn admit_for_render(db:&Client,session:&DirectSession,statuses:Vec<Value>)->Result<DirectBatch>{admit_for_render_with_dns(db,session,statuses,&crate::direct_preview::SystemDns).await}
async fn filter_direct_session(db:&Client,session:&DirectSession,statuses:&mut Vec<Value>)->Result<()>{
 if matches!(session.route,DirectRoute::RemoteProfile){crate::audience::filter_profile_statuses(db,session.owner,crate::audience::Surface::Direct,statuses).await}else{crate::audience::filter_statuses(db,session.owner,crate::audience::Surface::Direct,statuses).await}
}
pub(crate) async fn admit_for_render_with_dns(db:&Client,session:&DirectSession,statuses:Vec<Value>,dns:&dyn crate::direct_preview::PreviewDns)->Result<DirectBatch>{
 if let Some(context)=session.profile_bsky(){context.require_current(db).await?;}
 if statuses.len()>100{bail!("direct card batch exceeds canonical bound")}
 let owner=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.context("inactive direct owner")?;
 let actor:String=owner.get(0);let key:String=owner.get(1);
 let profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1 LIMIT 1",&[&key]).await?.map(|row|row.get::<_,Value>(0)).unwrap_or(Value::Null);
 let auto_unblur=crate::library::card::boolean(&profile,"auto_unblur_sensitive");
 let mut output=Vec::new();let mut budget=session.status_preview_budget;let mut quote_state=session.quote_preview_budget.map(crate::profile_quote_state::QuotePreviewState::new);
 if statuses.iter().map(|v|v.to_string().len()).sum::<usize>()>4*1024*1024{bail!("direct batch source size")}
 for (source_index,status) in statuses.into_iter().enumerate(){
  if matches!(session.route,DirectRoute::Status)&&status_root_content_blocked(db,session.owner,&status).await?{continue}
  supported_tree_mode(&status,0,quote_state.is_some(),(session.presentation.pending_quote.is_some()||session.presentation.pending_quote_suppressed))?;
  if !local_outbox_tree(db,&status,0).await?{continue}
  if status.get("reblog").is_some_and(Value::is_object)&&!text(&status,"content").is_empty()&&(text(&status,"content")=="0"||crate::library::card::php_trim(&crate::library::body::html_to_plain(text(&status,"content"))).is_empty()){bail!("PHP fallback: boost wrapper empty normalization")}
  let original_displayed=if text(&status,"content").is_empty(){status.get("reblog").filter(|v|v.is_object()).unwrap_or(&status)}else{&status};
  if original_displayed.get("content").is_some_and(|v|!v.is_null()&&!v.is_string()){bail!("PHP fallback: non-string direct preview input")}
  let original_content=original_displayed.get("content").filter(|v|!v.is_null()).and_then(Value::as_str).map(str::to_owned);
  let source=if text(&status,"content").is_empty(){status.get("reblog").filter(|v|v.is_object()).unwrap_or(&status)}else{&status};
  if crate::library::source::thin(source){bail!("PHP fallback: direct thin source hydration")}
  let mut checked=vec![status];filter_direct_session(db,session,&mut checked).await?;let Some(mut status)=checked.pop()else{continue};
  if !current_tree(db,&status,0).await?{crate::direct_preview::resolve_late_omitted(db,dns,&status,original_content.as_deref(),&actor,&session.return_view,&mut budget).await?;continue}
  // Saved-origin quote chrome retains PHP's nested Library policy. Until that
  // separate branch is measured, preserve the entire canonical request.
  if matches!(session.return_view.as_str(),"bookmarks"|"favourites")&&contains_quote(&status,0){bail!("PHP fallback: saved-origin direct quote policy")}
  crate::interaction_flags::apply_to_statuses(db,session.owner,std::slice::from_mut(&mut status)).await?;
  let mut facts=crate::library::facts::resolve(db,session.owner,&actor,&status,session,auto_unblur).await?;
  #[cfg(test)] if let Some(clock)=session.warm_context.as_deref().and_then(|warm|warm.fixture_clock()){facts.now=clock;}
  // The genuine painter resolves boost state from the incoming displayed SID
  // before own-action canonicalization. PHP prefetch queries numeric SIDs only;
  // the broad API object-alias flag is not this card-stage decision.
  let flag_source=if text(&status,"content").is_empty(){status.get("reblog").filter(|v|v.is_object()).unwrap_or(&status)}else{&status};
  let incoming_sid=crate::library::card::scalar_string(flag_source,"id");
  let exact_boost=if incoming_sid.is_empty(){false}else{db.query_opt("SELECT 1 FROM masto_reblogs WHERE owner_user_id=$1::bigint AND status_id=$2 LIMIT 1",&[&session.owner,&incoming_sid]).await?.is_some()};
  if exact_boost&&!incoming_sid.bytes().all(|byte|byte.is_ascii_digit()){bail!("PHP fallback: opaque boost SID requires actual caller prefetch lifecycle")}
  facts.boosted=exact_boost;
  if facts.rss_quote_row.is_some(){bail!("PHP fallback: direct RSS quote source binding")}
  let inner=text(&status,"content").is_empty()&&status.get("reblog").is_some_and(Value::is_object);
  let displayed=if inner{status.get_mut("reblog").unwrap()}else{&mut status};
  if let Some(ask)=facts.ask_row.as_ref(){displayed["vaak_ask"]=ask.clone();if let Some(answer)=ask.get("_body_answer").and_then(Value::as_str).filter(|value|!crate::library::card::php_trim(value).is_empty()){displayed["content"]=Value::String(crate::library::actions::esc(crate::library::card::php_trim(answer)));}else if text(displayed,"content").contains("ask-container"){bail!("PHP fallback: direct Ask body normalization")}}
  if let Some(own)=facts.own.as_mut(){
   if text(displayed,"uri").ends_with('/'){bail!("PHP fallback: direct own trailing-slash lookup")}
   let current=if own.visibility.is_empty(){"public"}else{&own.visibility};if displayed.get("visibility").and_then(Value::as_str).unwrap_or("public")!=current{bail!("PHP fallback: direct own visibility action conflict")}
   if !own.plain.is_empty()&&!matches!(own.plain.as_str(),"(quote)"|"(media)"|"(poll)"){displayed["content"]=Value::String(crate::library::actions::esc(&own.plain));}
   own.spoiler=crate::library::card::php_trim(&own.spoiler).into();
   if session.return_view=="search"{displayed["spoiler_text"]=Value::String(own.spoiler.clone());displayed["sensitive"]=Value::Bool(own.sensitive);}
  }
  if !current_tree(db,&status,0).await?{
   if !local_outbox_tree(db,&status,0).await?{continue}
   let mut current=vec![status];filter_direct_session(db,session,&mut current).await?;
   if let Some(status)=current.pop(){crate::direct_preview::resolve_late_omitted(db,dns,&status,original_content.as_deref(),&actor,&session.return_view,&mut budget).await?;}continue
  }
  if matches!(session.route,DirectRoute::Status)&&status_root_content_blocked(db,session.owner,&status).await?{continue}
  let mut checked=vec![status];filter_direct_session(db,session,&mut checked).await?;
  if let Some(mut status)=checked.pop(){let current=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.context("direct owner changed")?;if current.get::<_,String>(0)!=actor||current.get::<_,String>(1)!=key{bail!("direct identity changed during assembly")}
   if let Some(state)=quote_state.as_mut(){if session.presentation.pending_quote_suppressed{}else if let Some(pending)=session.presentation.pending_quote(){state.apply_pending_text(pending.preview_text())?;}else{state.prepare_status_with_context(db,dns,&mut status,facts.now,session.warm_context.as_deref()).await?;}}
   let mut presentation=session.presentation.clone();presentation.root_preview=crate::direct_preview::resolve(db,dns,&status,original_content.as_deref(),&facts,&mut budget).await?;
   // DNS/cache reads can suspend assembly. PHP also applies its final gate
   // after preview resolution; a late denial retains the consumed budget.
   if !current_tree(db,&status,0).await?{continue}
   if matches!(session.route,DirectRoute::Status)&&status_root_content_blocked(db,session.owner,&status).await?{continue}
   let mut final_checked=vec![status];filter_direct_session(db,session,&mut final_checked).await?;
   let Some(status)=final_checked.pop()else{continue};
   let final_owner=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.context("direct owner changed after preview")?;
   if final_owner.get::<_,String>(0)!=actor||final_owner.get::<_,String>(1)!=key{bail!("direct identity changed after preview")}
   output.push(AdmittedDirectStatus{source_index,status,facts,presentation});}
 }
 let final_owner=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.context("direct owner changed before batch publication")?;
 if final_owner.get::<_,String>(0)!=actor||final_owner.get::<_,String>(1)!=key{bail!("direct identity changed before batch publication")}
 if let Some(context)=session.profile_bsky(){context.require_current(db).await?;}
 Ok(DirectBatch{cards:output,status_preview_budget:budget,status_preview_budget_changed:budget!=session.status_preview_budget,quote_state})
}

#[cfg(test)]
mod tests {
 use super::*;
 #[test] fn nullable_uri_requires_php_normalization(){
  for uri in ["rss:synthetic","http://remote.test/one","urn:status:one"]{assert!(supported_tree(&serde_json::json!({"uri":uri,"content":"complete body"}),0).is_err());}
  for uri in [Value::Null,serde_json::json!(0),serde_json::json!({"href":"https://remote.test/one"})]{let status=serde_json::json!({"uri":uri,"url":"https://remote.test/one","content":"complete body"});assert!(supported_tree(&status,0).unwrap_err().to_string().contains("URI normalization"));let wrapped=serde_json::json!({"uri":"https://remote.test/wrapper","content":"","reblog":status});assert!(supported_tree(&wrapped,0).is_err());}
  assert!(supported_tree(&serde_json::json!({"uri":"https://remote.test/one","content":"complete body"}),0).is_ok());
 }
 #[test] fn direct_context_is_intent_not_audience(){
  assert!(DirectSession::from_php_session(0,"csrf".into(),DirectRoute::RemoteProfile,"remote_profile".into(),false,true,false,false,"".into()).is_err());
  assert!(DirectSession::from_php_session(1,"csrf".into(),DirectRoute::RemoteProfile,"home".into(),true,true,false,false,"".into()).is_err());
  for origin in ["home","search","bookmarks","favourites","status","remote_profile","outbox","mentions"]{let session=DirectSession::from_php_session(1,"csrf".into(),DirectRoute::Status,origin.into(),false,false,true,true,"".into()).unwrap();assert!(!session.presentation.focused());assert!(session.presentation.hide_header());assert_eq!(session.return_view,origin);}
 }
 async fn insert_rows(db:&Client,table:&str,rows:&Value)->Result<()> {
  db.batch_execute(&format!("DELETE FROM {table}")).await?;
  for row in rows.as_array().into_iter().flatten(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await?;}Ok(())
 }
 /// Actual PHP captures plus isolated live PostgreSQL, never expected HTML
 /// synthesized from native source. This opt-in replay is not a production API.
 #[tokio::test]
 #[ignore="requires disposable /tmp PostgreSQL and actual PHP capture artifact"]
 async fn actual_php_direct_reference_replay()->Result<()> {
  let url=std::env::var("LIBRARY_FIXTURE_DB")?;if !url.contains("/tmp/"){bail!("isolated PostgreSQL socket required")}
  let db=crate::db::connect(&url).await?;db.batch_execute("SET synchronous_commit=off").await?;
  let reference:Value=serde_json::from_slice(&std::fs::read(std::env::var("DIRECT_PHP_REFERENCE")?)?)?;
  let preview_reference=reference.get("bootstrap_tables").is_none();
  let bootstrap=if preview_reference{serde_json::from_slice::<Value>(&std::fs::read("/tmp/vaak-remote-direct-card-reference.json")?)?}else{reference.clone()};
  let mut output=Vec::new();
  struct DeclaredFixtureDns;
  impl crate::direct_preview::PreviewDns for DeclaredFixtureDns {fn ipv4<'a>(&'a self,_host:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<std::net::Ipv4Addr>>>+Send+'a>>{Box::pin(async{Ok(vec![std::net::Ipv4Addr::new(8,8,8,8)])})}}
  for width in ["INTEGER","BIGINT"] {
   let schema=format!("direct_native_{}",width.to_ascii_lowercase());db.batch_execute(&format!("DROP SCHEMA IF EXISTS {schema} CASCADE;CREATE SCHEMA {schema};SET search_path={schema}")).await?;
   for (table,snapshot) in bootstrap["bootstrap_tables"].as_object().context("reference schema missing")? {
    let columns=snapshot["columns"].as_array().unwrap().iter().map(|column|{let name=column["name"].as_str().unwrap();let kind=column["type"].as_str().unwrap();format!("{name} {}",if kind.to_ascii_uppercase().contains("INT")||(table=="actor_profile"&&name=="indexable"){width}else{"TEXT"})}).collect::<Vec<_>>().join(",");
    db.batch_execute(&format!("CREATE TABLE {table}({columns})")).await?;insert_rows(&db,table,&snapshot["rows"]).await?;
   }
   db.batch_execute("ALTER TABLE ap_users ADD is_admin BIGINT DEFAULT 0;ALTER TABLE events ADD quote_object TEXT;ALTER TABLE mentions ADD actor_id TEXT;CREATE TABLE ap_user_blocks(id BIGINT,owner_user_id BIGINT,scope TEXT,value TEXT,created_at TEXT);CREATE TABLE ap_mutes(owner_user_id BIGINT,actor_id TEXT);CREATE TABLE ap_muted_words(owner_user_id BIGINT,phrase TEXT);CREATE TABLE ap_deprioritized_actors(owner_user_id BIGINT,actor_id TEXT);CREATE TABLE ap_blocks(id BIGINT,scope TEXT,value TEXT,kind TEXT);CREATE TABLE ap_wafrn_friend_servers(host TEXT,enabled BIGINT);CREATE TABLE ap_asks(id BIGINT,answer_note_id TEXT,question TEXT,asker_actor TEXT);CREATE TABLE bsky_sessions(owner_user_id BIGINT,did TEXT,handle TEXT);CREATE TABLE quote_authorizations(deleted_at TEXT,quoted_note_id TEXT);CREATE TABLE bsky_crossposts(note_id TEXT,bsky_uri TEXT,created_at TEXT);ALTER TABLE bsky_posts ADD like_count BIGINT DEFAULT 0;ALTER TABLE bsky_posts ADD repost_count BIGINT DEFAULT 0;ALTER TABLE bsky_posts ADD quote_count BIGINT DEFAULT 0;CREATE TABLE remote_media_cache(actor_id TEXT,kind TEXT,public_url TEXT);").await?;
   for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width&&r.get("source").is_some()){
    let mapped;
    let context=if preview_reference{let tables=&row["current_tables"];mapped=serde_json::json!({"saved_favourites":tables["masto_favourites"],"saved_bookmarks":tables["masto_bookmarks"],"saved_reblogs":tables["masto_reblogs"],"local_status_rows":tables["masto_statuses"],"poll_rows":tables["masto_polls"],"audience_events":tables["events"],"audience_outbox":tables["outbox_notes"],"following":tables["following"],"search_bsky_rows":tables["bsky_posts"],"profile":tables["actor_profile"][0],"resolved_author_avatar":"https://mkultra.monster/img/avatar/default.webp"});&mapped}else{&row["authoritative_context"]};
    if preview_reference{insert_rows(&db,"link_preview_cards",&row["current_tables"]["link_preview_cards"]).await?;}
    for (table,field) in [("masto_favourites","saved_favourites"),("masto_bookmarks","saved_bookmarks"),("masto_reblogs","saved_reblogs"),("masto_statuses","local_status_rows"),("masto_polls","poll_rows"),("events","audience_events"),("outbox_notes","audience_outbox"),("following","following"),("bsky_posts","search_bsky_rows")]{insert_rows(&db,table,&context[field]).await?;}
    db.execute("UPDATE actor_profile SET auto_unblur_sensitive=$1",&[&if crate::library::card::boolean(&context["profile"],"auto_unblur_sensitive"){"1"}else{"0"}]).await?;
    // The actual PHP fixture supplies its warm canonical avatar transport.
    // Provision identical declared cache evidence, not input/account rewrites.
    db.batch_execute("DELETE FROM remote_media_cache").await?;
    fn actors(value:&Value,out:&mut Vec<String>){if let Some(actor)=value.get("account").and_then(|a|a.get("uri")).and_then(Value::as_str){if !out.iter().any(|v|v==actor){out.push(actor.into());}}for (_,child) in children(value){actors(child,out)}}
    let mut refs=Vec::new();actors(&row["source"],&mut refs);
    for actor in refs {if !actor.starts_with("https://mkultra.monster/users/"){db.execute("INSERT INTO remote_media_cache VALUES($1,'avatar',$2)",&[&actor,&context["resolved_author_avatar"].as_str().unwrap_or("https://mkultra.monster/img/avatar/default.webp")]).await?;}}
    let preview_options;
    let options=if preview_reference{preview_options=serde_json::json!({"owner":1,"csrf":bootstrap["captures"][0]["context"]["csrf"],"route":if row["view"]=="remote_profile"{"remote_profile"}else{"status"},"return_view":row["view"],"focused":row["view"]=="status","show_open":true,"hide_header":false,"lazy_media":false,"get_actor":"","rendered_at":row["rendered_at"].as_i64().unwrap_or(chrono::DateTime::parse_from_rfc3339(row["source"]["created_at"].as_str().unwrap())?.timestamp())});&preview_options}else{&row["context"]};let session=DirectSession::from_php_session(options["owner"].as_i64().unwrap(),options["csrf"].as_str().unwrap_or("").into(),if options["route"]=="status"{DirectRoute::Status}else{DirectRoute::RemoteProfile},options["return_view"].as_str().unwrap().into(),options["focused"].as_bool().unwrap_or(false),options["show_open"].as_bool().unwrap_or(true),options["hide_header"].as_bool().unwrap_or(false),options["lazy_media"].as_bool().unwrap_or(false),options["get_actor"].as_str().unwrap_or("").into())?.with_status_preview_budget(row["initial_status_budget"].as_i64().unwrap_or(3))?;
    let result=match admit_for_render_with_dns(&db,&session,vec![row["source"].clone()],&DeclaredFixtureDns).await {
     Ok(mut cards)=>{let final_budget=cards.final_status_preview_budget();if let Some(card)=cards.first_mut(){card.facts.now=chrono::DateTime::from_timestamp(options["rendered_at"].as_i64().unwrap(),0).unwrap();match crate::direct_render::render(card){Ok(html)=>serde_json::json!({"native":true,"html":html,"status_link_preview_budget":final_budget}),Err(error)=>serde_json::json!({"fallback":error.to_string()})}}else{serde_json::json!({"denied":true,"status_link_preview_budget":final_budget})}},
     Err(error)=>serde_json::json!({"fallback":error.to_string()}),
    };
    output.push(serde_json::json!({"width":width,"case":row["case"],"mode":row.get("mode").unwrap_or(&row["view"]),"initial_status_budget":session.status_preview_budget,"lazy":options["lazy_media"],"result":result,"php":row["php"]}));
   }
   db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await?;
  }
  std::fs::write(if preview_reference{"/tmp/vaak-direct-preview-native-captures.json"}else{"/tmp/vaak-direct-native-captures.json"},serde_json::to_vec(&output)?)?;assert!(!output.is_empty());Ok(())
 }
}
