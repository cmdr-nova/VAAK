//! Private local-profile card batch. Source paging is independent of painted
//! cards; the established PHP caller still owns header and tab selection.
use anyhow::{bail,Context,Result};
use serde_json::Value;
use tokio_postgres::Client;
#[path="profile_featured.rs"] mod featured;

pub(crate) struct AdmittedProfileHeader {
    facts:crate::profile_header::HeaderFacts,owner:i64,key:String,canonical_actor:String,
    owner_snapshot:Option<Value>,target_snapshot:Value,
}
impl AdmittedProfileHeader {
    pub(crate) fn facts(&self)->&crate::profile_header::HeaderFacts{&self.facts}
    pub(crate) async fn require_current(&self,db:&Client)->Result<()>{
        if root_content_blocked_for(db,self.owner,&self.canonical_actor).await?{bail!("profile header root changed before publication")}
        let profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&self.key]).await?.map(|r|r.get::<_,Value>(0));
        if profile.unwrap_or(Value::Null)!=*self.facts.profile_snapshot(){bail!("profile header presentation identity changed")}
        let target=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE lower(actor_key)=$1 LIMIT 1",&[&self.key]).await?.map(|r|r.get::<_,Value>(0));
        if target.unwrap_or(Value::Null)!=self.target_snapshot{bail!("profile header target identity changed")}
        if self.owner>0{
            let owner=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint AND disabled_at IS NULL",&[&self.owner]).await?.map(|r|r.get::<_,Value>(0));
            if owner!=self.owner_snapshot{bail!("profile header owner identity changed")}
        }
        self.facts.require_secondary_current(db).await?;
        Ok(())
    }
}
pub(crate) async fn admit_header_authenticated(db:&Client,session:&AuthenticatedProfileSession,request:&crate::profile_header::HeaderRequest)->Result<AdmittedProfileHeader>{
    if root_content_blocked_for(db,session.owner,&session.canonical_actor).await?{bail!("current profile header blocked")}
    let owner=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.map(|r|r.get::<_,Value>(0)).context("inactive header owner")?;
    let target=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE lower(actor_key)=$1 LIMIT 1",&[&session.actor_key]).await?.map(|r|r.get::<_,Value>(0)).unwrap_or(Value::Null);
    let facts=crate::profile_header::resolve_authenticated(db,session,request).await?;
    let header=AdmittedProfileHeader{facts,owner:session.owner,key:session.actor_key.clone(),canonical_actor:session.canonical_actor.clone(),owner_snapshot:Some(owner),target_snapshot:target};
    header.require_current(db).await?;Ok(header)
}
pub(crate) async fn admit_header_guest(db:&Client,session:&GuestProfileSession,request:&crate::profile_header::HeaderRequest)->Result<AdmittedProfileHeader>{
    if root_content_blocked_for(db,0,&session.canonical_actor).await?{bail!("current guest header blocked")}
    let target=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE lower(actor_key)=$1 LIMIT 1",&[&session.actor_key]).await?.map(|r|r.get::<_,Value>(0)).unwrap_or(Value::Null);
    let facts=crate::profile_header::resolve_guest(db,session,request).await?;
    let header=AdmittedProfileHeader{facts,owner:0,key:session.actor_key.clone(),canonical_actor:session.canonical_actor.clone(),owner_snapshot:None,target_snapshot:target};
    header.require_current(db).await?;Ok(header)
}
/// Presentation-only denied-root capability. It cannot supply source/card
/// grants, and the opposite root predicate is checked again before rendering.
pub(crate) struct AdmittedBlockedProfileHeader {
 facts:crate::profile_header::BlockedHeaderFacts,owner:i64,key:String,actor:String,
 owner_snapshot:Option<Value>,target_snapshot:Value,
}
impl AdmittedBlockedProfileHeader {
 pub(crate) fn facts(&self)->&crate::profile_header::BlockedHeaderFacts{&self.facts}
 pub(crate) async fn require_current(&self,db:&Client)->Result<()>{
  if !root_content_blocked_for(db,self.owner,&self.actor).await?{bail!("blocked profile branch changed before publication")}
  let target=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE lower(actor_key)=$1 LIMIT 1",&[&self.key]).await?.map(|r|r.get::<_,Value>(0));
  if target.unwrap_or(Value::Null)!=self.target_snapshot{bail!("blocked profile target changed")}
  if self.owner>0{let owner=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint AND disabled_at IS NULL",&[&self.owner]).await?.map(|r|r.get::<_,Value>(0));if owner!=self.owner_snapshot{bail!("blocked profile active owner changed")}}
  self.facts.require_actions_current(db).await?;
  Ok(())
 }
}
pub(crate) async fn admit_blocked_header_authenticated(db:&Client,session:&AuthenticatedProfileSession,request:&crate::profile_header::HeaderRequest)->Result<AdmittedBlockedProfileHeader>{
 if !root_content_blocked_for(db,session.owner,&session.canonical_actor).await?{bail!("profile root is not blocked")}
 let owner=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.map(|r|r.get::<_,Value>(0)).context("inactive blocked-header owner")?;
 let target=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE lower(actor_key)=$1 LIMIT 1",&[&session.actor_key]).await?.map(|r|r.get::<_,Value>(0)).unwrap_or(Value::Null);
 let facts=crate::profile_header::resolve_blocked_authenticated(db,session,request).await?;
 let token=AdmittedBlockedProfileHeader{facts,owner:session.owner,key:session.actor_key.clone(),actor:session.canonical_actor.clone(),owner_snapshot:Some(owner),target_snapshot:target};token.require_current(db).await?;Ok(token)
}
pub(crate) async fn admit_blocked_header_guest(db:&Client,session:&GuestProfileSession,request:&crate::profile_header::HeaderRequest)->Result<AdmittedBlockedProfileHeader>{
 if !root_content_blocked_for(db,0,&session.canonical_actor).await?{bail!("guest profile root is not blocked")}
 let target=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE lower(actor_key)=$1 LIMIT 1",&[&session.actor_key]).await?.map(|r|r.get::<_,Value>(0)).unwrap_or(Value::Null);
 let facts=crate::profile_header::resolve_blocked_guest(db,session,request).await?;
 let token=AdmittedBlockedProfileHeader{facts,owner:0,key:session.actor_key.clone(),actor:session.canonical_actor.clone(),owner_snapshot:None,target_snapshot:target};token.require_current(db).await?;Ok(token)
}
/// Native first-paint composition. Header presentation never grants timeline
/// access: the ordered body is selected and admitted independently afterward.
/// The private full/shell bridge publishes this product only after final checks.
pub(crate) enum LocalProfileFirstPaint {
 Blocked(AdmittedBlockedProfileHeader),
 Timeline{header:AdmittedProfileHeader,body:OrderedProfilePage,actor:String,tab:String,featured_preload:Option<featured::FeaturedContent>},
 Secondary{header:AdmittedProfileHeader,body:OrderedProfilePage,tab:String},
 Blog(AdmittedProfileHeader),
 Featured{header:AdmittedProfileHeader,content:featured::FeaturedContent},
}
impl LocalProfileFirstPaint {
 pub(crate) async fn require_current(&self,db:&Client)->Result<()>{match self{
  Self::Blocked(header)=>header.require_current(db).await,
  Self::Timeline{header,body,featured_preload,..}=>{header.require_current(db).await?;if let Some(content)=featured_preload{content.require_current(db).await?;}body.require_current(db).await},
  Self::Secondary{header,body,..}=>{header.require_current(db).await?;body.require_current(db).await},
  Self::Blog(header)=>header.require_current(db).await,
  Self::Featured{header,content}=>{header.require_current(db).await?;content.require_current(db).await},
 }}
 pub(crate) fn render(&self)->Result<String>{match self{
  Self::Blocked(header)=>crate::profile_header::render_blocked(header),
  Self::Featured{header,content}=>Ok(format!("{}<div class=\"remote-profile-posts\">{}</div>",crate::profile_header::render(header)?,content.render())),
  Self::Blog(header)=>Ok(format!("{}<div class=\"remote-profile-posts\">{}</div>",crate::profile_header::render(header)?,crate::profile_header::render_blog(header)?)),
  Self::Secondary{header,body,tab}=>{let mut html=crate::profile_header::render(header)?;html.push_str("<div class=\"remote-profile-posts\">");if tab=="pinned"{if body.page().source_count()==0{html.push_str("<div class=\"empty\">No pinned posts.</div>");}else{html.push_str("<div class=\"profile-pinned-feed\" data-vaak-pinned-feed=\"1\">");for(_,card)in body.cards(){html.push_str(card);}html.push_str("</div>");}}else if body.selected_slots()==0{html.push_str("<div class=\"empty\">No answered Asks yet.</div>");}else{for(_,card)in body.cards(){html.push_str(card);}}html.push_str("</div>");Ok(html)},
  Self::Timeline{header,body,actor,tab,..}=>{
   let h=crate::library::actions::esc;let mut html=crate::profile_header::render(header)?;html.push_str("<div class=\"remote-profile-posts\">");
   if body.page().source_count()==0{html.push_str(&format!("<div class=\"empty\">No {} available.</div>",h(tab)));}
   let more=crate::profile_source::profile_page_has_more(body.page().has_more(),body.page().next_offset(),body.page().source_count() as i64,header.facts().timeline_tab_total(tab));
   html.push_str(&format!("<div id=\"timeline-items\" data-view=\"remote_profile\" data-actor=\"{}\" data-tab=\"{}\" data-offset=\"{}\" data-limit=\"12\" data-has-more=\"{}\" data-newest=\"0\">",h(actor),h(tab),body.page().next_offset(),u8::from(more)));
   for(_,card)in body.cards(){html.push_str(card);}
   html.push_str(&format!("</div><div id=\"timeline-status\" class=\"meta\" style=\"padding:.75rem 0;text-align:center\">{}</div><div id=\"timeline-sentinel\" aria-hidden=\"true\" style=\"height:1px\"></div><button type=\"button\" class=\"feed-top-btn\" id=\"feed-top-btn\" title=\"Back to top\" aria-label=\"Back to top\">↑</button></div>",crate::profile_source::profile_scroll_status(more)));Ok(html)
  }
 }}
}
pub(crate) async fn prepare_local_profile_first_paint(db:&Client,viewer:&crate::profile_materialize::ProfileViewer<'_>,request:&crate::profile_header::HeaderRequest,dns:&dyn crate::direct_preview::PreviewDns)->Result<LocalProfileFirstPaint>{
 let(owner,key,actor)=match viewer{crate::profile_materialize::ProfileViewer::Auth(session)=>(session.owner_id(),session.actor_key(),session.source_actor()),crate::profile_materialize::ProfileViewer::Guest(session)=>(0,session.actor_key(),session.source_actor())};
 let canonical=format!("https://mkultra.monster/users/{key}");
 if root_content_blocked_for(db,owner,&canonical).await?{return Ok(LocalProfileFirstPaint::Blocked(match viewer{crate::profile_materialize::ProfileViewer::Auth(session)=>admit_blocked_header_authenticated(db,session,request).await?,crate::profile_materialize::ProfileViewer::Guest(session)=>admit_blocked_header_guest(db,session,request).await?}));}
 let mut header=match viewer{crate::profile_materialize::ProfileViewer::Auth(session)=>admit_header_authenticated(db,session,request).await?,crate::profile_materialize::ProfileViewer::Guest(session)=>admit_header_guest(db,session,request).await?};
 let featured=if crate::library::card::php_trim(&request.tab).eq_ignore_ascii_case("featured"){let content=featured::prepare(db,&header,&request.from).await?;header.facts.apply_featured_presentation_count(content.displayed_count())?;Some(content)}else{None};
 let tab=header.facts().effective_tab().to_owned();
 let result=if tab=="featured"{LocalProfileFirstPaint::Featured{header,content:featured.context("missing native Featured selection")?}}else if tab=="blog"{LocalProfileFirstPaint::Blog(header)}else if matches!(tab.as_str(),"posts"|"replies"|"boosts"|"media"){
  let selection=crate::profile_source::select(db,key,actor,&tab,12,0).await?;let body=prepare_ordered_profile_page(db,viewer,selection,dns).await?;LocalProfileFirstPaint::Timeline{header,body,actor:actor.into(),tab,featured_preload:featured}
 }else if matches!(tab.as_str(),"pinned"|"asks"){let selection=crate::profile_source::select_secondary(db,key,actor,if tab=="pinned"{"pinned"}else{"asks"}).await?;let body=prepare_ordered_profile_page(db,viewer,selection,dns).await?;LocalProfileFirstPaint::Secondary{header,body,tab}}else{bail!("PHP fallback: full secondary profile content assembly incomplete")};
 result.require_current(db).await?;Ok(result)
}
#[derive(Clone,PartialEq)]
pub(crate) struct ProfilePage {
    offset:i64,
    selected_count:usize,
    has_more:bool,
    ledger:Option<crate::profile_source::SourceLedger>,
}
impl ProfilePage {
    pub(crate) fn from_selected_page(offset:i64,limit:i64,selected_count:usize,has_more:bool)->Result<Self>{
        if offset<0||!(1..=50).contains(&limit)||selected_count>limit as usize {bail!("invalid canonical profile source page")}
        offset.checked_add(selected_count as i64).context("profile cursor overflow")?;
        Ok(Self{offset,selected_count,has_more,ledger:None})
    }
    pub(crate) fn from_current_ledger(offset:i64,limit:i64,count:usize,more:bool,ledger:crate::profile_source::SourceLedger)->Result<Self>{
        let mut page=Self::from_selected_page(offset,limit,count,more)?;page.ledger=Some(ledger);Ok(page)
    }
    pub(crate) fn from_secondary_ledger(count:usize,ledger:crate::profile_source::SourceLedger)->Result<Self>{if count>200{bail!("invalid canonical secondary source bound")}Ok(Self{offset:0,selected_count:count,has_more:false,ledger:Some(ledger)})}
    pub(crate) fn require_source_row(&self,index:usize,row:&Value)->Result<()>{self.ledger.as_ref().context("PHP fallback: profile page lacks source binding")?.require_source_row(index,row)}
    pub(crate) fn require_target(&self,key:&str,actor:&str)->Result<()>{self.ledger.as_ref().context("PHP fallback: profile page lacks target binding")?.require_target(key,actor)}
    pub(crate) async fn require_current_ledger(&self,db:&Client)->Result<()>{
        self.ledger.as_ref().context("PHP fallback: profile page lacks current provider binding")?.require_current(db).await
    }
    pub(crate) fn next_offset(&self)->i64{self.offset+self.selected_count as i64}
    pub(crate) fn source_count(&self)->usize{self.selected_count}
    pub(crate) fn has_more(&self)->bool{self.has_more}
}
/// Only canonical shared-card sources enter this initial batch. Raw outbox and
/// imported dialects must retain PHP until their separate painters are ported.
pub(crate) struct ProfileCardSource {source_index:usize,status:Value}
impl ProfileCardSource {
    pub(crate) fn shared(source_index:usize,status:Value)->Result<Self>{
        if !status.is_object(){bail!("invalid shared profile source")}
        Ok(Self{source_index,status})
    }
}
/// Constructed only by the current raw-source admission pipeline. Pure facts
/// and source JSON have no public constructor or deserialization authority.
pub(crate) struct AdmittedRawProfileStatus {status:Value,facts:crate::profile_raw::RawProfileFacts}
impl AdmittedRawProfileStatus {
    pub(crate) fn status(&self)->&Value{&self.status}
    pub(crate) fn facts(&self)->&crate::profile_raw::RawProfileFacts{&self.facts}
}
/// Separate read-only capability; never produced by downgrading an inactive
/// authenticated owner or by deserializing a post permission flag.
pub(crate) struct AdmittedGuestProfileStatus {status:Value,facts:crate::library::card::CardFacts,root_preview:Option<Option<Value>>}
impl AdmittedGuestProfileStatus {
    pub(crate) fn status(&self)->&Value{&self.status}
    pub(crate) fn facts(&self)->&crate::library::card::CardFacts{&self.facts}
    pub(crate) fn root_preview(&self)->Option<&Option<Value>>{self.root_preview.as_ref()}
}
#[derive(Clone)]
pub(crate) struct AuthenticatedProfileSession {
    owner:i64,
    actor_key:String,
    canonical_actor:String,
    // Preserve case/trailing-slash semantics for the original Boosts provider.
    source_actor:String,
    direct:crate::direct_context::DirectSession,
}
fn local_profile_key(actor:&str)->Option<String>{
    static RE:std::sync::OnceLock<regex::Regex>=std::sync::OnceLock::new();
    RE.get_or_init(||regex::Regex::new(r"^https://mkultra\.monster/users/([A-Za-z0-9_]+)$").unwrap()).captures(actor).map(|capture|capture[1].to_owned())
}
impl AuthenticatedProfileSession {
    pub(crate) fn from_php_session(owner:i64,csrf:String,actor_key:String,source_actor:String,lazy_media:bool,budget:i64)->Result<Self>{
        if actor_key.is_empty()||actor_key.len()>80||!actor_key.bytes().all(|b|b.is_ascii_lowercase()||b.is_ascii_digit()||b==b'_'){bail!("invalid canonical local profile key")}
        let canonical_actor=format!("https://mkultra.monster/users/{actor_key}");
        let source_actor=source_actor.trim().trim_end_matches('/').to_owned();
        let captured=local_profile_key(&source_actor).context("invalid original local profile actor")?;
        if captured.to_ascii_lowercase()!=actor_key{bail!("profile actor/key binding differs")}
        let direct=crate::direct_context::DirectSession::from_php_session(owner,csrf,crate::direct_context::DirectRoute::RemoteProfile,"remote_profile".into(),false,true,false,lazy_media,source_actor.clone())?.with_status_preview_budget(budget)?;
        Ok(Self{owner,actor_key,canonical_actor,source_actor,direct})
    }
    pub(crate) fn with_quote_preview_budget(mut self,budget:i64)->Self{self.direct=self.direct.with_quote_preview_budget(budget);self}
    pub(crate) async fn with_php_bsky(mut self,db:&Client,input:crate::profile_bsky::PhpProfileBsky)->Result<Self>{let context=crate::profile_bsky::ProfileBskyPresentation::bind(db,self.owner,input).await?;self.direct=self.direct.with_profile_bsky(context)?;Ok(self)}
    pub(crate) fn with_warm_context(mut self,context:std::sync::Arc<crate::profile_enrichment::WarmContext>)->Self{self.direct=self.direct.with_warm_context(context);self}
    pub(crate) fn owner_id(&self)->i64{self.owner}
    pub(crate) fn warm_context(&self)->Option<&crate::profile_enrichment::WarmContext>{crate::library::facts::PresentationSession::warm_context(&self.direct)}
    pub(crate) fn actor_key(&self)->&str{&self.actor_key}
    pub(crate) fn canonical_actor(&self)->&str{&self.canonical_actor}
    pub(crate) fn source_actor(&self)->&str{&self.source_actor}
}
impl crate::library::facts::PresentationSession for AuthenticatedProfileSession {
    fn warm_context(&self)->Option<&crate::profile_enrichment::WarmContext>{crate::library::facts::PresentationSession::warm_context(&self.direct)}
    fn csrf(&self)->&str{crate::library::facts::PresentationSession::csrf(&self.direct)}
    fn return_view(&self)->&str{"remote_profile"}
    fn bsky_enabled(&self)->bool{crate::library::facts::PresentationSession::bsky_enabled(&self.direct)}
    fn lazy_media(&self)->bool{crate::library::facts::PresentationSession::lazy_media(&self.direct)}
    fn bsky_bookmark_state(&self,status:&Value)->Option<bool>{crate::library::facts::PresentationSession::bsky_bookmark_state(&self.direct,status)}
}
/// Created explicitly by the trusted public-profile caller, never by treating
/// an inactive authenticated owner as an anonymous reader.
#[derive(Clone)]
pub(crate) struct GuestProfileSession {actor_key:String,canonical_actor:String,source_actor:String,lazy_media:bool,status_preview_budget:i64,quote_preview_budget:Option<i64>,warm_context:Option<std::sync::Arc<crate::profile_enrichment::WarmContext>>}
impl GuestProfileSession {
    pub(crate) fn from_php_guest_profile(actor_key:String,source_actor:String,lazy_media:bool,status_preview_budget:i64)->Result<Self>{
        if !(0..=100).contains(&status_preview_budget){bail!("invalid trusted guest preview budget")}
        if actor_key.is_empty()||actor_key.len()>80||!actor_key.bytes().all(|b|b.is_ascii_lowercase()||b.is_ascii_digit()||b==b'_'){bail!("invalid canonical guest profile key")}
        let source_actor=source_actor.trim().trim_end_matches('/').to_owned();
        let captured=local_profile_key(&source_actor).context("invalid original guest profile actor")?;
        if captured.to_ascii_lowercase()!=actor_key{bail!("guest profile actor/key binding differs")}
        Ok(Self{canonical_actor:format!("https://mkultra.monster/users/{actor_key}"),actor_key,source_actor,lazy_media,status_preview_budget,quote_preview_budget:None,warm_context:None})
    }
    pub(crate) fn with_warm_context(mut self,context:std::sync::Arc<crate::profile_enrichment::WarmContext>)->Self{self.warm_context=Some(context);self}
    pub(crate) fn with_quote_preview_budget(mut self,budget:i64)->Self{self.quote_preview_budget=Some(budget);self}
    pub(crate) fn warm_context(&self)->Option<&crate::profile_enrichment::WarmContext>{self.warm_context.as_deref()}
    pub(crate) fn actor_key(&self)->&str{&self.actor_key}
    pub(crate) fn canonical_actor(&self)->&str{&self.canonical_actor}
    pub(crate) fn source_actor(&self)->&str{&self.source_actor}
}
type RawSourceDependency=(Value,String,Value,Option<Value>);
struct RawProfileProof {owner:i64,owner_snapshot:Option<Value>,key:String,actor:String,target:Value,dependencies:Vec<RawSourceDependency>,quotes:Vec<(crate::profile_materialize::ProjectedQuoteTarget,bool)>,mapping_changes:Vec<crate::profile_materialize::AccountMappingChanges>}
impl RawProfileProof {
 async fn require_current(&self,db:&Client,page:&ProfilePage)->Result<()>{
  require_projection_identity(db,self.owner,&self.owner_snapshot,&self.actor,page).await?;
  let current=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&self.key]).await?.map(|row|row.get::<_,Value>(0));if current.as_ref()!=Some(&self.target){bail!("raw profile target changed after later source preparation")}
  if !self.dependencies.is_empty(){
   let mut statuses=self.dependencies.iter().map(|dependency|dependency.0.clone()).collect::<Vec<_>>();crate::audience::filter_profile_statuses(db,self.owner,crate::audience::Surface::Direct,&mut statuses).await?;
   if statuses.len()!=self.dependencies.len(){bail!("raw prepared prefix audience changed after later source preparation")}
   let input=Value::Array(self.dependencies.iter().map(|(status,key,..)|serde_json::json!({"key":key,"note":status["uri"]})).collect());
   let rows=db.query("SELECT p.value,s.value FROM jsonb_array_elements($1::jsonb) WITH ORDINALITY i(value,n) LEFT JOIN LATERAL (SELECT to_jsonb(p) AS value FROM actor_profile p WHERE actor_key=i.value->>'key') p ON true LEFT JOIN LATERAL (SELECT to_jsonb(s) AS value FROM masto_statuses s WHERE note_id IN (i.value->>'note',rtrim(i.value->>'note','/')||'/') LIMIT 1) s ON true ORDER BY i.n",&[&input]).await?;
   if rows.len()!=self.dependencies.len(){bail!("raw prepared prefix source cardinality changed")}
   for(row,(_,_,profile,masto))in rows.iter().zip(&self.dependencies){if row.get::<_,Option<Value>>(0).as_ref()!=Some(profile)||&row.get::<_,Option<Value>>(1)!=masto{bail!("raw prepared prefix current presentation changed after later source preparation")}}
  }
  for(product,painted)in &self.quotes{product.require_current(db).await?;if *painted{if let Some(status)=product.status(){require_current_profile_card(db,self.owner,status).await?;}}}
  Ok(())
 }
}
async fn require_raw_profile_prefix(db:&Client,owner:i64,page:&ProfilePage,proofs:&[&RawProfileProof])->Result<()>{
 let Some(first)=proofs.first()else{return Ok(())};
 for proof in proofs{if proof.owner!=owner||proof.owner_snapshot!=first.owner_snapshot||proof.key!=first.key||proof.actor!=first.actor||proof.target!=first.target{bail!("raw final prefix identity bindings differ")}}
 require_projection_identity(db,owner,&first.owner_snapshot,&first.actor,page).await?;
 let current=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&first.key]).await?.map(|row|row.get::<_,Value>(0));if current.as_ref()!=Some(&first.target){bail!("raw final prefix target changed")}
 let dependencies=proofs.iter().flat_map(|proof|proof.dependencies.iter()).collect::<Vec<_>>();
 let mut statuses=dependencies.iter().map(|dependency|dependency.0.clone()).collect::<Vec<_>>();crate::audience::filter_profile_statuses(db,owner,crate::audience::Surface::Direct,&mut statuses).await?;
 if statuses.len()!=dependencies.len(){bail!("raw final prefix audience changed")}
 if !dependencies.is_empty(){
  let input=Value::Array(dependencies.iter().map(|(status,key,..)|serde_json::json!({"key":key,"note":status["uri"]})).collect());
  let rows=db.query("SELECT p.value,s.value FROM jsonb_array_elements($1::jsonb) WITH ORDINALITY i(value,n) LEFT JOIN LATERAL (SELECT to_jsonb(p) AS value FROM actor_profile p WHERE actor_key=i.value->>'key') p ON true LEFT JOIN LATERAL (SELECT to_jsonb(s) AS value FROM masto_statuses s WHERE note_id IN (i.value->>'note',rtrim(i.value->>'note','/')||'/') LIMIT 1) s ON true ORDER BY i.n",&[&input]).await?;
  if rows.len()!=dependencies.len(){bail!("raw final prefix source cardinality changed")}
  for(row,(_,_,profile,masto))in rows.iter().zip(dependencies){if row.get::<_,Option<Value>>(0).as_ref()!=Some(profile)||row.get::<_,Option<Value>>(1)!=*masto{bail!("raw final prefix presentation changed")}}
 }
 let mut children=Vec::new();for proof in proofs{for(product,painted)in &proof.quotes{product.require_current(db).await?;if *painted{if let Some(status)=product.status(){children.push(status);}}}}
 require_current_profile_cards(db,owner,&children).await
}
struct RawStageGrant{proof:RawProfileProof,page:ProfilePage}
impl crate::profile_enrichment::CurrentEnrichmentGrant for RawStageGrant{fn require_current<'a>(&'a self,db:&'a Client)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{Box::pin(async move{self.proof.require_current(db,&self.page).await})}}
pub(crate) struct GuestRawProfileCards {page:ProfilePage,cards:Vec<(usize,AdmittedRawProfileStatus)>,proof:RawProfileProof}
impl GuestRawProfileCards {
    pub(crate) fn page(&self)->&ProfilePage{&self.page}
    pub(crate) fn cards(&self)->impl Iterator<Item=(usize,&AdmittedRawProfileStatus)>{self.cards.iter().map(|(index,card)|(*index,card))}
    pub(crate) async fn require_current(&self,db:&Client)->Result<()>{self.proof.require_current(db,&self.page).await}
}
/// The raw source is selected by native SQL, not supplied as a purported
/// status/permission descriptor. Guests use public presentation facts only.
pub(crate) async fn admit_guest_raw(db:&Client,session:&GuestProfileSession,selection:crate::profile_source::SelectedProfilePage)->Result<GuestRawProfileCards>{
    admit_guest_raw_with_dns(db,session,selection,&crate::direct_preview::SystemDns).await
}
struct RawPageRow {index:usize,raw:Value,note:String,key:String,actor:String,profile:Value,masto:Option<Value>,digest:String,status:Value,plain:String,quote:Option<RawQuoteIntent>}
/// One profile/status/audience/media/poll/ask/Bluesky read for the whole page.
async fn load_guest_raw_page(db:&Client,selected_rows:Vec<(usize,Value)>)->Result<(Vec<RawPageRow>,std::collections::HashMap<String,String>,std::collections::HashMap<String,(String,String,String)>,crate::profile_guest_facts::GuestFactMemo)>{
 let mut kept=Vec::new();let mut specs=Vec::new();
 for (index,raw) in selected_rows{
  let note=raw.get("id").and_then(Value::as_str).context("raw profile source is not an outbox note")?.trim_end_matches('/').to_owned();
  let source_key=crate::direct_context::local_note_author(&note).context("PHP fallback: raw note author shape")?;
  let key=source_key.to_ascii_lowercase();
  specs.push(serde_json::json!({"note":note,"key":key}));
  kept.push((index,raw));
 }
 let input=Value::Array(specs);
 let looked=if kept.is_empty(){Vec::new()}else{db.query("SELECT i.value->>'note', i.value->>'key', p.value, s.value, md5(i.value->>'note') FROM jsonb_array_elements($1::jsonb) WITH ORDINALITY i(value,n) LEFT JOIN LATERAL (SELECT to_jsonb(p) AS value FROM actor_profile p WHERE actor_key=i.value->>'key') p ON true LEFT JOIN LATERAL (SELECT to_jsonb(s) AS value FROM masto_statuses s WHERE note_id IN (i.value->>'note', rtrim(i.value->>'note','/')||'/') LIMIT 1) s ON true ORDER BY i.n",&[&input]).await?};
 if looked.len()!=kept.len(){bail!("raw page lookup cardinality changed")}
 let mut statuses=Vec::new();let mut staged=Vec::new();
 for ((index,raw),row) in kept.into_iter().zip(looked){
  let note:String=row.get(0);let key:String=row.get(1);
  let profile=row.get::<_,Option<Value>>(2).context("PHP fallback: raw profile author unavailable")?;
  let masto:Option<Value>=row.get(3);let digest:String=row.get(4);
  let actor=format!("https://mkultra.monster/users/{key}");
  let status=serde_json::json!({"id":"","uri":note,"url":note,"visibility":raw.get("visibility").cloned().unwrap_or(serde_json::json!("public")),"content":raw.get("content").cloned().unwrap_or(serde_json::json!("")),"account":{"uri":actor,"url":actor,"acct":key},"media_attachments":[]});
  statuses.push(status.clone());
  staged.push((index,raw,note,key,actor,profile,masto,digest,status));
 }
 let mut allowed_statuses=statuses;crate::audience::filter_profile_statuses(db,0,crate::audience::Surface::Direct,&mut allowed_statuses).await?;
 let allowed:std::collections::HashSet<String>=allowed_statuses.iter().filter_map(|status|status.get("uri").and_then(Value::as_str).map(str::to_owned)).collect();
 let mut rows=Vec::new();let mut notes=Vec::new();let mut media_urls=Vec::new();let mut quote_keys=Vec::new();
 for (index,raw,note,key,actor,profile,masto,digest,status) in staged{
  if !allowed.contains(&note){continue}
  let plain=crate::library::body::html_to_plain(raw.get("content").and_then(Value::as_str).unwrap_or(""));
  let decoded:Value=serde_json::from_str(raw.get("raw_create_json").and_then(Value::as_str).unwrap_or("")).unwrap_or(Value::Null);
  let object=decoded.get("object").cloned().unwrap_or(decoded);
  let quote=raw_quote_intent(&object,&plain)?;
  if let Some(intent)=quote.as_ref(){if let Some(rkey)=bsky_rkey(&intent.url){quote_keys.push(rkey);}}
  if let Some(items)=object.get("attachment").and_then(Value::as_array){for item in items{if let Some(url)=item.get("url").and_then(Value::as_str).or_else(||item.get("url").and_then(|url|url.get("href")).and_then(Value::as_str)).filter(|url|url.starts_with("https://")){media_urls.push(url.to_owned());}}}
  else if let Some(item)=object.get("attachment").filter(|item|item.is_object()){if let Some(url)=item.get("url").and_then(Value::as_str).or_else(||item.get("url").and_then(|url|url.get("href")).and_then(Value::as_str)).filter(|url|url.starts_with("https://")){media_urls.push(url.to_owned());}}
  notes.push(note.clone());
  rows.push(RawPageRow{index,raw,note,key,actor,profile,masto,digest,status,plain,quote});
 }
 let media=if media_urls.is_empty(){std::collections::HashMap::new()}else{db.query("SELECT public_url,preview_url FROM masto_media WHERE public_url=ANY($1) AND preview_url IS NOT NULL",&[&media_urls]).await?.into_iter().map(|row|(row.get::<_,String>(0),row.get::<_,String>(1))).collect()};
 let mut polls=std::collections::HashMap::new();let mut asks=std::collections::HashMap::new();let mut mention_asks=std::collections::HashMap::new();
 if !notes.is_empty(){
  for row in db.query("SELECT DISTINCT ON (rtrim(note_id,'/')) rtrim(note_id,'/'), jsonb_build_object('note_id',note_id,'local_id',local_id,'multiple',multiple,'votes_count',votes_count,'voters_count',voters_count,'expires_at',expires_at,'options_json',options_json) FROM masto_polls WHERE rtrim(note_id,'/')=ANY($1::text[]) ORDER BY rtrim(note_id,'/'), local_id",&[&notes]).await?{polls.insert(row.get::<_,String>(0),row.get(1));}
  for row in db.query("SELECT DISTINCT ON (rtrim(answer_note_id,'/')) rtrim(answer_note_id,'/'), jsonb_build_object('question',question,'asker_actor',asker_actor,'answer_note_id',answer_note_id) FROM ap_asks WHERE rtrim(answer_note_id,'/')=ANY($1::text[]) ORDER BY rtrim(answer_note_id,'/'), id DESC",&[&notes]).await?{asks.insert(row.get::<_,String>(0),row.get(1));}
  for row in db.query("SELECT DISTINCT ON (rtrim(object_id,'/')) rtrim(object_id,'/'), COALESCE(ask_actor,''), COALESCE(ask_question,''), COALESCE(ask_answer,'') FROM mentions WHERE deleted_at IS NULL AND ask_question<>'' AND rtrim(object_id,'/')=ANY($1::text[]) ORDER BY rtrim(object_id,'/'), id DESC",&[&notes]).await?{mention_asks.insert(row.get::<_,String>(0),serde_json::json!({"asker_actor":row.get::<_,String>(1),"question":row.get::<_,String>(2),"answer":row.get::<_,String>(3)}));}
 }
 let mut bsky=std::collections::HashMap::new();
 if !quote_keys.is_empty(){
  let found=db.query("SELECT split_part(bsky_uri, '/app.bsky.feed.post/', 2), left(COALESCE(text,''),2000), COALESCE(author_handle,''), COALESCE(embed_json::text,'') FROM bsky_posts WHERE split_part(bsky_uri, '/app.bsky.feed.post/', 2)=ANY($1::text[])",&[&quote_keys]).await;
  match found{Ok(found)=>{for row in found{bsky.insert(row.get::<_,String>(0),(row.get::<_,String>(1),row.get::<_,String>(2),row.get::<_,String>(3)));}},Err(error) if error.code()==Some(&tokio_postgres::error::SqlState::UNDEFINED_TABLE)||error.code()==Some(&tokio_postgres::error::SqlState::UNDEFINED_COLUMN)=>{},Err(error)=>return Err(error.into())}
 }
 Ok((rows,media,bsky,crate::profile_guest_facts::GuestFactMemo{polls,asks,mention_asks}))
}
pub(crate) async fn admit_guest_raw_with_dns(db:&Client,session:&GuestProfileSession,selection:crate::profile_source::SelectedProfilePage,dns:&dyn crate::direct_preview::PreviewDns)->Result<GuestRawProfileCards>{
    let (selected_rows,page)=selection.into_indexed_parts();page.require_current_ledger(db).await?;page.require_target(&session.actor_key,&session.source_actor)?;
    if root_content_blocked_for(db,0,&session.canonical_actor).await?{bail!("PHP fallback: raw guest profile root blocked")}
    let target=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&session.actor_key]).await?.map(|row|row.get::<_,Value>(0)).context("PHP fallback: raw profile target unavailable")?;
    reject_unsupported_profile_quotes(&selected_rows.iter().map(|(_,row)|row.clone()).collect::<Vec<_>>())?;
    crate::profile_bridge::ensure_budget()?;
    let (rows,media,bsky,memo)=load_guest_raw_page(db,selected_rows).await?;
    if let Some(warm)=session.warm_context(){for row in &rows{if warm.cached_avatar(&row.actor).is_none(){let icon=row.profile.get("icon_url").and_then(Value::as_str).unwrap_or("");let url=if reqwest::Url::parse(icon).ok().is_some_and(|url|url.scheme()=="https"){icon.to_owned()}else{"https://mkultra.monster/img/avatar/local-default.webp".into()};warm.cache_avatar(&row.actor,&url);}}}
    let mut cards=Vec::new();let mut dependencies=Vec::new();let mut quote_products:Vec<(crate::profile_materialize::ProjectedQuoteTarget,bool)>=Vec::new();let mut mapping_changes=Vec::new();
    for row in rows{
        crate::profile_bridge::ensure_budget()?;
        let RawPageRow{index,raw,note:_,key,actor,profile,masto,digest,status,plain,quote:quote_intent}=row;
        let stage=std::sync::Arc::new(RawStageGrant{proof:RawProfileProof{owner:0,owner_snapshot:None,key:session.actor_key.clone(),actor:session.canonical_actor.clone(),target:target.clone(),dependencies:vec![(status.clone(),key.clone(),profile.clone(),masto.clone())],quotes:vec![],mapping_changes:vec![]},page:page.clone()});
        let scoped_warm=session.warm_context().map(|context|context.bound(stage.clone()));let mut scoped_session=session.clone();if let Some(context)=&scoped_warm{scoped_session=scoped_session.with_warm_context(context.clone());}
        let mut facts=crate::profile_guest_facts::resolve_public_raw(db,&status,session.lazy_media,Some(&plain),scoped_warm.as_deref(),Some(&memo)).await?;
        facts.current_action_counts=None;
        if facts.owner_id!=0||!facts.owner_actor.is_empty()||!facts.csrf.is_empty()||facts.own.is_some(){bail!("raw guest requires isolated public facts")}
        let previews=media.iter().filter(|(url,_)|{
            let decoded:Value=serde_json::from_str(raw.get("raw_create_json").and_then(Value::as_str).unwrap_or("")).unwrap_or(Value::Null);
            let object=decoded.get("object").unwrap_or(&decoded);
            let attachments=object.get("attachment").map(|item|if let Some(items)=item.as_array(){items.clone()}else{vec![item.clone()]}).unwrap_or_default();
            attachments.iter().any(|item|item.get("url").and_then(Value::as_str).or_else(||item.get("url").and_then(|url|url.get("href")).and_then(Value::as_str))==Some(url.as_str()))
        }).map(|(url,preview)|(url.clone(),preview.clone())).collect();
        let mut raw_facts=crate::profile_raw::RawProfileFacts{card:facts,actor_key:key.clone(),actor:actor.clone(),display_name:profile.get("name").and_then(Value::as_str).unwrap_or(&key).into(),note_dom_id:digest,masto_row:masto.clone(),status_id:String::new(),own_note:false,guest:true,quote_html:String::new(),link_card:None,media_preview_by_url:previews};
        if let Some(intent)=quote_intent.as_ref(){let(html,product,changes)=prepare_raw_quote(db,&crate::profile_materialize::ProfileViewer::Guest(&scoped_session),&page,index,&raw,&status,intent,&raw_facts.card,dns,Some(&bsky)).await?;for change in &changes{for(previous,_)in &mut quote_products{previous.reconcile_account_changes(change)?;}}mapping_changes.extend(changes);let painted=!html.is_empty()&&product.status().is_some();raw_facts.quote_html=html;quote_products.push((product,painted));}
        if crate::profile_raw::root_preview_allowed(&raw,&raw_facts){raw_facts.link_card=crate::direct_preview::resolve_cache_only_guarded(db,dns,&crate::profile_raw::current_plain(&raw,&raw_facts),raw_facts.card.now,Some(stage.as_ref())).await?;}
        dependencies.push((status,key,profile,masto));cards.push((index,AdmittedRawProfileStatus{status:raw,facts:raw_facts}));
    }
    page.require_current_ledger(db).await?;
    if root_content_blocked_for(db,0,&session.canonical_actor).await?{bail!("PHP fallback: raw guest root changed before publication")}
    let final_target=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&session.actor_key]).await?.map(|row|row.get::<_,Value>(0));
    if final_target.as_ref()!=Some(&target){bail!("PHP fallback: raw target identity changed before publication")}
    let proof=RawProfileProof{owner:0,owner_snapshot:None,key:session.actor_key.clone(),actor:session.canonical_actor.clone(),target,dependencies,quotes:quote_products,mapping_changes};let batch=GuestRawProfileCards{page,cards,proof};batch.require_current(db).await?;Ok(batch)
}
/// Match the actual summary split boundary; ordinary QTIP/inline QT text is
/// not a quoted source. Structured target resolution remains a separate step.
fn raw_quote_tag_present(object:&Value)->bool{
 let Some(tags)=object.get("tag") else{return false};
 let tags=tags.as_array().map(|a|a.iter().collect::<Vec<_>>()).unwrap_or_else(||vec![tags]);
 tags.into_iter().any(|tag|{
  let rel=tag.get("rel");let relations=rel.and_then(Value::as_array).map(|a|a.iter().collect::<Vec<_>>()).unwrap_or_else(||rel.into_iter().collect());
  relations.into_iter().any(|r|r.as_str().is_some_and(|r|r.contains("quote")))&&tag.get("href").and_then(Value::as_str).is_some_and(|url|url.starts_with("https://"))
 })
}
fn raw_quote_summary_present(plain:&str)->bool{
 let text=crate::library::card::php_trim(plain);
 fn compiled(pattern:&'static str)->regex::Regex{static CACHE:std::sync::OnceLock<std::sync::Mutex<std::collections::HashMap<&'static str,regex::Regex>>>=std::sync::OnceLock::new();CACHE.get_or_init(Default::default).lock().expect("quote summary").entry(pattern).or_insert_with(||regex::Regex::new(pattern).unwrap()).clone()}
 compiled(r"(?:^|\n)\s*(?:↪|➡|→)?\s*QT(?:Create|Announce|Update|Note|QuotePost|\b)").is_match(text)
 ||compiled(r"(?s)^(.*?)(?:\n|^)RE:\s*(https://[^\s<>]+)\s*$").is_match(text)
}
/// A private source grant for deferred projector cache/effects. Native source
/// values and original ordinals are bound together; a protocol payload cannot
/// construct this capability or transfer it to an unrelated source.
#[derive(Clone)]
pub(crate) struct AdmittedProjectionSources {sources:Vec<(usize,Value)>,denied:Vec<(usize,Value)>,owner:i64,owner_snapshot:Option<Value>,root_actor:String,page:ProfilePage}
/// A child-specific cache capability. Only the native profile admission
/// assembler may construct it after checking the parent and quoted source.
/// Keeping the requested identity binds alias recovery to its own admission.
#[derive(Clone)]
pub(crate) struct AdmittedQuoteProjection {requested:String,status:Option<Value>,owner:i64,owner_snapshot:Option<Value>,root_actor:String,page:ProfilePage,parent:Value}
impl AdmittedQuoteProjection {
 pub(crate) fn permits(&self,target:&str,status:&Value)->bool{self.requested==target&&self.status.as_ref()==Some(status)}
 pub(crate) async fn require_current(&self,db:&Client,target:&str,status:&Value)->Result<()>{
  if !self.permits(target,status){bail!("PHP fallback: quote cache capability does not match current target")}
  require_projection_identity(db,self.owner,&self.owner_snapshot,&self.root_actor,&self.page).await?;
  let mut parent=vec![self.parent.clone()];crate::audience::filter_profile_statuses(db,self.owner,crate::audience::Surface::Direct,&mut parent).await?;
  if parent.len()!=1{bail!("PHP fallback: quote parent audience changed before enrichment")}
  require_current_profile_card(db,self.owner,status).await
 }
}
async fn require_projection_identity(db:&Client,owner:i64,snapshot:&Option<Value>,actor:&str,page:&ProfilePage)->Result<()>{
 page.require_current_ledger(db).await?;
 if owner>0{
  // One current statement observes the same block rows and active viewer row;
  // no authority result is retained across the next enrichment await.
  let row=db.query_one("SELECT (SELECT COALESCE(jsonb_agg(jsonb_build_object('scope',b.scope,'value',b.value)),'[]'::jsonb) FROM (SELECT scope,value FROM ap_blocks WHERE COALESCE(kind,'block')<>'mute' UNION ALL SELECT scope,value FROM ap_user_blocks WHERE owner_user_id=$1::bigint) b), (SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint AND disabled_at IS NULL)",&[&owner]).await?;
  let blocks:Value=row.get(0);let blocks=blocks.as_array().context("invalid current profile block rows")?;
  if crate::library::facts::personal_block(blocks,actor).is_some(){bail!("PHP fallback: profile projection root changed before enrichment")}
  let current:Option<Value>=row.get(1);if &current!=snapshot{bail!("PHP fallback: profile projection viewer changed before enrichment")}
 }else if root_content_blocked_for(db,owner,actor).await?{bail!("PHP fallback: profile projection root changed before enrichment")}
 Ok(())
}
fn raw_scalar_quote_target(object:&Value,plain:&str)->Result<Option<String>>{
 for field in ["quote","quoteUri","quoteUrl","_misskey_quote"]{
  let Some(value)=object.get(field).filter(|value|!value.is_null())else{continue};
  let Some(target)=value.as_str()else{continue};
  if target.starts_with("https://")||target.starts_with("at://"){
   return Ok(Some(crate::library::card::php_trim(target).trim_end_matches('/').into()));
  }
 }
 // FEP object links identify a target only. Their payload does not supply
 // child presentation or permission: prepare_raw_quote still resolves the
 // current canonical source and rechecks its audience and source ledger.
  if let Some(target)=raw_quote_tag_target(object)?{
  return Ok(Some(crate::library::card::php_trim(target).trim_end_matches('/').into()));
 }
 // Summary-only QT text has no target URL. PHP paints the post without a
 // quote card; refusing the page here was the signed-out profile fallback.
 let _=plain;let _=raw_quote_tag_present(object);
 Ok(None)
}
fn raw_quote_tag_target(object:&Value)->Result<Option<&str>>{
 let Some(tags)=object.get("tag")else{return Ok(None)};
 // PHP accepts either one Link object or an associative/list tag collection.
 // Its isset(type/href) test wraps only non-null fields as a singleton.
 let tags=if let Some(tags)=tags.as_array(){tags.iter().collect::<Vec<_>>()}else if let Some(tags)=tags.as_object(){
  if ["type","href"].iter().any(|key|tags.get(*key).is_some_and(|value|!value.is_null())){vec![object.get("tag").unwrap()]}else{
   // Value object keys are sorted; PHP preserves insertion order. Multiple
   // associative tag objects need raw-order decoding before native takeover.
   if tags.values().filter(|tag|tag.is_object()).count()>1{bail!("PHP fallback: associative quote tag order requires raw decoding")}
   tags.values().collect()
  }
 }else{return Ok(None)};
 for tag in tags{
  if !tag.is_object(){continue}
  let rel=tag.get("rel");
  let rels=if let Some(rels)=rel.and_then(Value::as_array){rels.iter().collect::<Vec<_>>()}else if let Some(rels)=rel.and_then(Value::as_object){rels.values().collect()}else{rel.into_iter().collect()};
  let is_quote=rels.into_iter().any(|rel|rel.as_str().is_some_and(|rel|rel.contains("quote")));
  if is_quote{if let Some(href)=tag.get("href").and_then(Value::as_str).filter(|href|href.starts_with("https://")){return Ok(Some(href))}}
 }
 Ok(None)
}
fn raw_quote_facts(status:&Value,target:&str)->crate::profile_raw::RawQuoteFacts{
 let string=|key:&str|status.get(key).and_then(Value::as_str).unwrap_or("");
 let mut url=string("uri");if !url.starts_with("https://"){url=status.get("url").and_then(Value::as_str).unwrap_or(target)}
 let attachments=status.get("media_attachments").unwrap_or(&Value::Null);
 let attachments=attachments.as_array().map(|items|items.iter().collect::<Vec<_>>()).unwrap_or_else(||if attachments.is_object(){vec![attachments]}else{vec![]});
 let mut media=Vec::new();
 for item in attachments{
  let url=match item.get("url"){
   Some(Value::String(url))=>url.as_str(),
   Some(Value::Object(url))=>url.get("href").or_else(||url.get("url")).and_then(Value::as_str).unwrap_or(""),
   _=>item.get("preview_url").and_then(Value::as_str).unwrap_or("")
  };
  if !url.starts_with("https://"){continue}
  let media_type=item.get("mediaType").and_then(Value::as_str).map(str::to_owned).or_else(||match item.get("type").and_then(Value::as_str).unwrap_or("").to_ascii_lowercase().as_str(){"video"|"gifv"=>Some("video/mp4".into()),"audio"=>Some("audio/*".into()),"image"=>Some("image/jpeg".into()),_=>None});
  let preview=["preview_url","preview","thumbnail"].iter().find_map(|key|item.get(*key).and_then(Value::as_str).filter(|url|url.starts_with("https://"))).unwrap_or("");
  media.push(serde_json::json!({"url":url,"mediaType":media_type,"preview_url":preview}));if media.len()==4{break}
 }
 let acct=status.get("account").and_then(|account|account.get("acct")).and_then(Value::as_str).unwrap_or("");
 let spoiler=crate::library::card::php_trim(string("spoiler_text"));
 let sensitive=crate::library::card::boolean(status,"sensitive")||!spoiler.is_empty();
 crate::profile_raw::RawQuoteFacts{acct:if acct.is_empty(){String::new()}else{format!("@{}",acct.trim_start_matches('@'))},text:crate::library::card::php_trim(&crate::library::body::html_to_plain(string("content"))).into(),url:url.into(),media,card:status.get("card").filter(|card|card.is_object()).cloned(),mentions:status.get("mentions").and_then(Value::as_array).map(|items|items.iter().filter(|item|item.is_object()).cloned().collect()).unwrap_or_default(),spoiler:if spoiler.is_empty()&&sensitive{"Sensitive content".into()}else{spoiler.into()},sensitive,is_rss:false,article_url:String::new(),open_label:"Open quoted".into(),open_external:false}
}
struct RawQuoteIntent{url:String,embedded:Option<Value>,tombstone:bool}
fn quote_value_is_tombstone(value:&Value)->bool{value.get("type").and_then(Value::as_str).is_some_and(|kind|kind.eq_ignore_ascii_case("Tombstone"))}
fn embedded_https_id(value:&Value)->String{
 let inner=value.get("object").filter(|inner|inner.is_object()).unwrap_or(value);
 for source in [inner,value]{for key in ["id","url","href"]{if let Some(url)=source.get(key).and_then(Value::as_str).map(str::trim).filter(|url|url.starts_with("https://")||url.starts_with("at://")){let url=crate::library::card::php_trim(url).trim_end_matches('/');if !url.ends_with("/activity"){return url.into();}}}}
 String::new()
}
fn raw_quote_intent(object:&Value,_plain:&str)->Result<Option<RawQuoteIntent>>{
 for field in ["quote","quoteUri","quoteUrl","_misskey_quote"]{
  let Some(value)=object.get(field).filter(|value|!value.is_null())else{continue};
  if quote_value_is_tombstone(value){return Ok(Some(RawQuoteIntent{url:embedded_https_id(value),embedded:None,tombstone:true}))}
  if let Some(target)=value.as_str(){let target=crate::library::card::php_trim(target);if target.starts_with("https://")||target.starts_with("at://"){return Ok(Some(RawQuoteIntent{url:target.trim_end_matches('/').into(),embedded:None,tombstone:false}))}continue}
  if value.is_object(){return Ok(Some(RawQuoteIntent{url:embedded_https_id(value),embedded:Some(value.clone()),tombstone:false}))}
 }
 if let Some(target)=raw_quote_tag_target(object)?{return Ok(Some(RawQuoteIntent{url:crate::library::card::php_trim(target).trim_end_matches('/').into(),embedded:None,tombstone:false}))}
 if let Some(quoted)=object.get("quoteObject").filter(|value|value.is_object()){return Ok(Some(RawQuoteIntent{url:embedded_https_id(quoted),embedded:Some(quoted.clone()),tombstone:quote_value_is_tombstone(quoted)}))}
 Ok(None)
}
fn reject_unsupported_profile_quotes(rows:&[Value])->Result<()>{
 for raw in rows{
  crate::profile_bridge::ensure_budget()?;
  let Some(encoded)=raw.get("raw_create_json").and_then(Value::as_str)else{continue};
  let decoded:Value=serde_json::from_str(encoded).unwrap_or(Value::Null);
  let object=decoded.get("object").unwrap_or(&decoded);
  let plain=crate::library::body::html_to_plain(raw.get("content").and_then(Value::as_str).unwrap_or(""));
  raw_quote_intent(object,&plain)?;
 }
 Ok(())
}
fn stub_quote_facts(text:&str,url:&str,media:Vec<Value>,acct:&str)->crate::profile_raw::RawQuoteFacts{
 crate::profile_raw::RawQuoteFacts{acct:if acct.is_empty(){String::new()}else{format!("@{}",acct.trim_start_matches('@'))},text:text.into(),url:url.into(),media,card:None,mentions:Vec::new(),spoiler:String::new(),sensitive:false,is_rss:false,article_url:String::new(),open_label:"Open original".into(),open_external:true}
}
fn embedded_quote_media(value:&Value)->Vec<Value>{
 let attachments=value.get("attachment").or_else(||value.get("media_attachments")).unwrap_or(&Value::Null);
 let items=attachments.as_array().map(|items|items.iter().collect::<Vec<_>>()).unwrap_or_else(||if attachments.is_object(){vec![attachments]}else{vec![]});
 let mut media=Vec::new();
 for item in items{
  let url=match item.get("url"){Some(Value::String(url))=>url.as_str(),Some(Value::Object(url))=>url.get("href").or_else(||url.get("url")).and_then(Value::as_str).unwrap_or(""),_=>item.get("preview_url").and_then(Value::as_str).unwrap_or("")};
  if !url.starts_with("https://"){continue}
  let media_type=item.get("mediaType").and_then(Value::as_str).map(str::to_owned).or_else(||match item.get("type").and_then(Value::as_str).unwrap_or("").to_ascii_lowercase().as_str(){"video"|"gifv"=>Some("video/mp4".into()),"audio"=>Some("audio/*".into()),"image"=>Some("image/jpeg".into()),_=>None});
  let preview=["preview_url","preview","thumbnail"].iter().find_map(|key|item.get(*key).and_then(Value::as_str).filter(|url|url.starts_with("https://"))).unwrap_or("");
  media.push(serde_json::json!({"url":url,"mediaType":media_type,"preview_url":preview}));if media.len()==4{break}
 }
 media
}
/// Compile quote and actor patterns before a complete-profile budget starts.
pub(crate) fn prewarm_complete_profile_regexes(){
 let _=local_profile_key("https://mkultra.monster/users/fixture");
 let _=raw_quote_summary_present("QT note");
 let _=raw_quote_summary_present("hello\nRE: https://example.test/post");
 let _=bsky_rkey("https://bsky.app/profile/name.example/post/abc");
 let _=bsky_rkey("at://did:plc:abc/app.bsky.feed.post/xyz");
}
fn bsky_rkey(url:&str)->Option<String>{
 fn compiled(pattern:&'static str)->regex::Regex{static CACHE:std::sync::OnceLock<std::sync::Mutex<std::collections::HashMap<&'static str,regex::Regex>>>=std::sync::OnceLock::new();CACHE.get_or_init(Default::default).lock().expect("bsky rkey").entry(pattern).or_insert_with(||regex::Regex::new(pattern).unwrap()).clone()}
 if let Some(found)=compiled(r"(?i)app\.bsky\.feed\.post/([A-Za-z0-9]+)").captures(url){return Some(found[1].to_owned())}
 compiled(r"(?i)^https://bsky\.app/profile/[^/]+/post/([A-Za-z0-9]+)").captures(url).map(|found|found[1].to_owned())
}
fn bsky_embed_media(embed:&Value)->Vec<Value>{
 let mut media=Vec::new();
 fn walk(value:&Value,media:&mut Vec<Value>){
  if media.len()>=4{return}
  let kind=value.get("$type").and_then(Value::as_str).unwrap_or("").to_ascii_lowercase();
  if kind.contains("recordwithmedia"){if let Some(inner)=value.get("media"){walk(inner,media);}}
  let images=value.get("items").filter(|items|items.as_array().is_some_and(|items|!items.is_empty())).or_else(||value.get("images"));
  if let Some(images)=images.and_then(Value::as_array){for image in images{if media.len()>=4{break}let url=image.get("fullsize").or_else(||image.get("thumb")).or_else(||image.get("url")).and_then(Value::as_str).unwrap_or("");if !url.starts_with("https://"){continue}let thumb=image.get("thumb").or_else(||image.get("thumbnail")).and_then(Value::as_str).filter(|url|url.starts_with("https://")).unwrap_or(url);media.push(serde_json::json!({"url":url,"mediaType":"image/jpeg","preview_url":thumb}));}}
 }
 walk(embed,&mut media);media
}
/// Cache-only Bluesky quote. A missing table or row is a stub, never an AppView fetch.
async fn cached_bsky_quote_facts(db:&Client,url:&str,preloaded:Option<&std::collections::HashMap<String,(String,String,String)>>)->Result<Option<crate::profile_raw::RawQuoteFacts>>{
 let Some(rkey)=bsky_rkey(url)else{return Ok(None)};
 if let Some(preloaded)=preloaded{
  let Some((text,handle,embed_raw))=preloaded.get(&rkey)else{return Ok(None)};
  let embed:Value=serde_json::from_str(embed_raw).unwrap_or(Value::Null);
  let media=if embed.is_null(){Vec::new()}else{bsky_embed_media(&embed)};
  if crate::library::card::php_trim(text).is_empty()&&handle.is_empty()&&media.is_empty(){return Ok(None)}
  return Ok(Some(stub_quote_facts(&crate::library::card::php_trim(text),url,media,handle)));
 }
 let row=db.query_opt("SELECT left(COALESCE(text,''),2000), COALESCE(author_handle,''), COALESCE(embed_json::text,'') FROM bsky_posts WHERE split_part(bsky_uri, '/app.bsky.feed.post/', 2)=$1 LIMIT 1",&[&rkey]).await;
 let row=match row{Ok(row)=>row,Err(error) if error.code()==Some(&tokio_postgres::error::SqlState::UNDEFINED_TABLE)||error.code()==Some(&tokio_postgres::error::SqlState::UNDEFINED_COLUMN)=>return Ok(None),Err(error)=>return Err(error.into())};
 let Some(row)=row else{return Ok(None)};
 let text:String=row.get(0);let handle:String=row.get(1);let embed_raw:String=row.get(2);
 let embed:Value=serde_json::from_str(&embed_raw).unwrap_or(Value::Null);
 let media=if embed.is_null(){Vec::new()}else{bsky_embed_media(&embed)};
 if crate::library::card::php_trim(&text).is_empty()&&handle.is_empty()&&media.is_empty(){return Ok(None)}
 Ok(Some(stub_quote_facts(&crate::library::card::php_trim(&text),url,media,&handle)))
}
fn embedded_stub_facts(intent:&RawQuoteIntent)->crate::profile_raw::RawQuoteFacts{
 if intent.tombstone{return stub_quote_facts("Quoted post unavailable",&intent.url,Vec::new(),"")}
 let Some(embedded)=intent.embedded.as_ref()else{return stub_quote_facts("",&intent.url,Vec::new(),"")};
 let doc=embedded.get("object").filter(|inner|inner.is_object()).unwrap_or(embedded);
 if quote_value_is_tombstone(doc){return stub_quote_facts("Quoted post unavailable",&intent.url,Vec::new(),"")}
 let plain=crate::library::body::html_to_plain(doc.get("content").and_then(Value::as_str).unwrap_or(""));
 let text=crate::library::card::php_trim(&plain);
 let acct=doc.get("attributedTo").and_then(Value::as_str).unwrap_or("");
 stub_quote_facts(&text,&intent.url,embedded_quote_media(doc),acct)
}
async fn external_quote_facts(db:&Client,intent:&RawQuoteIntent,preloaded:Option<&std::collections::HashMap<String,(String,String,String)>>)->Result<crate::profile_raw::RawQuoteFacts>{
 if intent.tombstone{return Ok(stub_quote_facts("Quoted post unavailable",&intent.url,Vec::new(),""))}
 if crate::profile_materialize::quote_target_is_bluesky(&intent.url)||intent.url.starts_with("at://"){
  if let Some(cached)=cached_bsky_quote_facts(db,&intent.url,preloaded).await?{return Ok(cached)}
 }
 Ok(embedded_stub_facts(intent))
}
async fn prepare_raw_quote(db:&Client,viewer:&crate::profile_materialize::ProfileViewer<'_>,page:&ProfilePage,index:usize,raw:&Value,root:&Value,intent:&RawQuoteIntent,facts:&crate::library::card::CardFacts,dns:&dyn crate::direct_preview::PreviewDns,bsky:Option<&std::collections::HashMap<String,(String,String,String)>>)->Result<(String,crate::profile_materialize::ProjectedQuoteTarget,Vec<crate::profile_materialize::AccountMappingChanges>)>{
 page.require_source_row(index,raw)?;page.require_current_ledger(db).await?;
 crate::profile_bridge::ensure_budget()?;
 let owner=match viewer{crate::profile_materialize::ProfileViewer::Auth(session)=>session.owner_id(),crate::profile_materialize::ProfileViewer::Guest(_)=>0};
 let mut roots=vec![root.clone()];crate::audience::filter_profile_statuses(db,owner,crate::audience::Surface::Direct,&mut roots).await?;
 if roots.len()!=1{bail!("PHP fallback: raw quote parent changed before child assembly")}
 let target=intent.url.as_str();
 if intent.tombstone||crate::profile_materialize::local_key(target).is_none()||crate::profile_materialize::quote_target_is_bluesky(target){
  let quote=external_quote_facts(db,intent,bsky).await?;
  let html=crate::profile_raw::render_quote(&quote,facts)?;
  return Ok((html,crate::profile_materialize::ProjectedQuoteTarget::absent(intent.url.clone()),Vec::new()));
 }
 let mut product=crate::profile_materialize::materialize_quote_target(db,viewer,target).await?;let mut mapping_changes=Vec::new();
 product.require_current(db).await?;
 let Some(child)=product.status().cloned()else{
  let quote=external_quote_facts(db,intent,bsky).await?;
  let html=crate::profile_raw::render_quote(&quote,facts)?;
  return Ok((html,product,Vec::new()));
 };
 let mut checked=vec![child.clone()];crate::audience::filter_profile_statuses(db,owner,crate::audience::Surface::Direct,&mut checked).await?;
 let allowed=checked.len()==1&&crate::direct_context::local_outbox_tree(db,&child,0).await?&&crate::direct_context::current_tree(db,&child,0).await?;
 let root_actor=match viewer{crate::profile_materialize::ProfileViewer::Auth(session)=>session.canonical_actor(),crate::profile_materialize::ProfileViewer::Guest(session)=>session.canonical_actor()};
 let owner_snapshot=if owner>0{db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint AND disabled_at IS NULL",&[&owner]).await?.map(|row|row.get::<_,Value>(0))}else{None};
 let admission_status=child.clone();let grant=AdmittedQuoteProjection{requested:product.requested_target().into(),status:if allowed{Some(child.clone())}else{None},owner,owner_snapshot,root_actor:root_actor.into(),page:page.clone(),parent:root.clone()};
 if allowed{
  let warm=match viewer{crate::profile_materialize::ProfileViewer::Auth(session)=>session.warm_context(),crate::profile_materialize::ProfileViewer::Guest(session)=>session.warm_context()};
  let effects=product.effects().to_vec();for effect in &effects{
   grant.require_current(db,product.requested_target(),&admission_status).await?;
   match effect.effect(){
    crate::profile_materialize::ProjectionEffect::ActorWarm(actor)=>{let current=product.effect_current_grant(&admission_status,&grant);warm.context("PHP fallback: quoted actor effect transport unavailable")?.actor_with_grant(db,actor,&current).await?;},
    crate::profile_materialize::ProjectionEffect::MediaWarm(actor)=>{let current=product.effect_current_grant(&admission_status,&grant);warm.context("PHP fallback: quoted media effect transport unavailable")?.media_with_grant(db,actor,&current).await?;},
    crate::profile_materialize::ProjectionEffect::RememberAccount{..}|crate::profile_materialize::ProjectionEffect::PostMediaTouch{..}=>{mapping_changes.push(product.apply_quote_effect(db,effect,&admission_status,&grant).await?);}
    crate::profile_materialize::ProjectionEffect::PostMediaWarm{..}=>{let warm=warm.context("PHP fallback: quoted post-media transport unavailable")?;product.apply_quote_post_media_warm(db,effect,&admission_status,&grant,warm.cfg(),warm.transport()).await?;}
   }
   grant.require_current(db,product.requested_target(),&admission_status).await?;
  }
 }
 if allowed{product.complete_cache(db,dns,&grant).await?;}
 let html=if allowed{crate::profile_raw::render_quote(&raw_quote_facts(product.status().unwrap(),target),facts)?}else{String::new()};
 product.require_current(db).await?;page.require_current_ledger(db).await?;
 Ok((html,product,mapping_changes))
}
impl AdmittedProjectionSources {
 pub(crate) fn require_same_page(&self,page:&ProfilePage)->Result<()>{if &self.page!=page{bail!("PHP fallback: projection grant page binding differs")}Ok(())}
 pub(crate) fn permits(&self,index:usize,status:&Value)->bool{self.sources.iter().any(|(i,s)|*i==index&&s==status)}
 pub(crate) fn disposition(&self,index:usize,status:&Value)->Option<bool>{if self.permits(index,status){Some(true)}else if self.denied.iter().any(|(i,s)|*i==index&&s==status){Some(false)}else{None}}
 pub(crate) fn source_indices(&self)->impl Iterator<Item=usize>+'_ {self.sources.iter().map(|(i,_)|*i)}
 pub(crate) async fn require_current(&self,db:&Client,index:usize,status:&Value)->Result<()>{
  if !self.permits(index,status){bail!("PHP fallback: profile cache capability does not match current source")}
  require_projection_identity(db,self.owner,&self.owner_snapshot,&self.root_actor,&self.page).await?;
  require_current_profile_card(db,self.owner,status).await
 }
}
pub(crate) async fn admit_projection_sources(db:&Client,viewer:&crate::profile_materialize::ProfileViewer<'_>,page:&crate::profile_materialize::MaterializedProfilePage)->Result<AdmittedProjectionSources>{
 let(owner,key,actor,source_actor)=match viewer{
  crate::profile_materialize::ProfileViewer::Auth(session)=>{if db.query_opt("SELECT id FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner_id()]).await?.is_none(){bail!("inactive profile projection owner")}(session.owner_id(),session.actor_key(),session.canonical_actor(),session.source_actor())},
  crate::profile_materialize::ProfileViewer::Guest(session)=>(0,session.actor_key(),session.canonical_actor(),session.source_actor())
 };
 page.page().require_target(key,source_actor)?;page.require_current(db).await?;
 if root_content_blocked_for(db,owner,actor).await?{bail!("PHP fallback: profile projection root blocked")}
 let mut shared=Vec::new();
 for source in page.sources(){
  let crate::profile_materialize::ProfileSourceKind::Shared(status)=source.source() else{continue};
  shared.push((source.source_index(),status.clone()));
 }
 let mut tagged=Vec::with_capacity(shared.len());
 for (index,status) in &shared{let mut copy=status.clone();copy["__vaak_profile_ordinal"]=serde_json::json!(*index);tagged.push(copy);}
 crate::audience::filter_profile_statuses(db,owner,crate::audience::Surface::Direct,&mut tagged).await?;
 let allowed_audience:std::collections::HashSet<usize>=tagged.iter().filter_map(|status|status.get("__vaak_profile_ordinal").and_then(serde_json::Value::as_u64).map(|ordinal|ordinal as usize)).collect();
 let refs:Vec<&serde_json::Value>=shared.iter().map(|(_,status)|status).collect();
 let trees=crate::direct_context::profile_tree_mask(db,&refs).await?;
 let mut sources=Vec::new();let mut denied=Vec::new();
 for ((index,status),tree_ok) in shared.into_iter().zip(trees){
  if allowed_audience.contains(&index)&&tree_ok{sources.push((index,status));}else{denied.push((index,status));}
 }
 page.require_current(db).await?;let owner_snapshot=if owner>0{db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint AND disabled_at IS NULL",&[&owner]).await?.map(|row|row.get::<_,Value>(0))}else{None};
 Ok(AdmittedProjectionSources{sources,denied,owner,owner_snapshot,root_actor:actor.into(),page:page.page().clone()})
}
pub(crate) struct AuthenticatedRawProfileCards {page:ProfilePage,cards:Vec<(usize,AdmittedRawProfileStatus)>,proof:RawProfileProof}
impl AuthenticatedRawProfileCards {
    pub(crate) fn page(&self)->&ProfilePage{&self.page}
    pub(crate) fn cards(&self)->impl Iterator<Item=(usize,&AdmittedRawProfileStatus)>{self.cards.iter().map(|(index,card)|(*index,card))}
    pub(crate) async fn require_current(&self,db:&Client)->Result<()>{self.proof.require_current(db,&self.page).await}
}
pub(crate) async fn admit_authenticated_raw_with_dns(db:&Client,session:&AuthenticatedProfileSession,selection:crate::profile_source::SelectedProfilePage,dns:&dyn crate::direct_preview::PreviewDns)->Result<AuthenticatedRawProfileCards>{
    let (selected_rows,page)=selection.into_indexed_parts();page.require_current_ledger(db).await?;page.require_target(&session.actor_key,&session.source_actor)?;
    let owner=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.context("inactive raw profile owner")?;
    let owner_actor:String=owner.get(0);let owner_key:String=owner.get(1);
    let owner_snapshot=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.map(|row|row.get::<_,Value>(0)).context("inactive raw profile owner snapshot")?;
    if root_content_blocked_for(db,session.owner,&session.canonical_actor).await?{bail!("PHP fallback: authenticated raw profile root blocked")}
    let target=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&session.actor_key]).await?.map(|row|row.get::<_,Value>(0)).context("PHP fallback: raw profile target unavailable")?;
    reject_unsupported_profile_quotes(&selected_rows.iter().map(|(_,row)|row.clone()).collect::<Vec<_>>())?;
    let mut cards=Vec::new();let mut dependencies=Vec::new();let mut quote_products:Vec<(crate::profile_materialize::ProjectedQuoteTarget,bool)>=Vec::new();let mut mapping_changes=Vec::new();
    for (index,raw) in selected_rows{
        crate::profile_bridge::ensure_budget()?;
        let note=raw.get("id").and_then(Value::as_str).context("raw profile source is not an outbox note")?;
        let source_key=crate::direct_context::local_note_author(note).context("PHP fallback: raw note author shape")?;
        let actor=format!("https://mkultra.monster/users/{source_key}");let key=source_key.to_ascii_lowercase();
        let profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&key]).await?.map(|row|row.get::<_,Value>(0)).context("PHP fallback: raw profile author unavailable")?;
        let status=serde_json::json!({"id":"","uri":note,"url":note,"visibility":raw.get("visibility").cloned().unwrap_or(serde_json::json!("public")),"content":raw.get("content").cloned().unwrap_or(serde_json::json!("")),"account":{"uri":actor,"url":actor,"acct":key},"media_attachments":[]});
        let mut checked=vec![status.clone()];crate::audience::filter_profile_statuses(db,session.owner,crate::audience::Surface::Direct,&mut checked).await?;
        if checked.is_empty(){continue}
        let plain=crate::library::body::html_to_plain(raw.get("content").and_then(Value::as_str).unwrap_or(""));
        let decoded:Value=serde_json::from_str(raw.get("raw_create_json").and_then(Value::as_str).unwrap_or("")).unwrap_or(Value::Null);
        let object=decoded.get("object").unwrap_or(&decoded);
        let quote_intent=raw_quote_intent(object,&plain)?;
        let masto=db.query_opt("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id IN ($1,$2) LIMIT 1",&[&note,&format!("{}/",note.trim_end_matches('/'))]).await?.map(|row|row.get::<_,Value>(0));
        let stage=std::sync::Arc::new(RawStageGrant{proof:RawProfileProof{owner:session.owner,owner_snapshot:Some(owner_snapshot.clone()),key:session.actor_key.clone(),actor:session.canonical_actor.clone(),target:target.clone(),dependencies:vec![(status.clone(),key.clone(),profile.clone(),masto.clone())],quotes:vec![],mapping_changes:vec![]},page:page.clone()});
        crate::profile_bridge::ensure_budget()?;let mut scoped_session=session.clone();if let Some(context)=session.warm_context(){scoped_session=scoped_session.with_warm_context(context.bound(stage.clone()));}
        let mut raw_facts=crate::profile_raw_facts::resolve_authenticated(db,&scoped_session,&raw,&actor,&key,&profile).await?;
        if let Some(intent)=quote_intent.as_ref(){let(html,product,changes)=prepare_raw_quote(db,&crate::profile_materialize::ProfileViewer::Auth(&scoped_session),&page,index,&raw,&status,intent,&raw_facts.card,dns,None).await?;for change in &changes{for(previous,_)in &mut quote_products{previous.reconcile_account_changes(change)?;}}mapping_changes.extend(changes);let painted=!html.is_empty()&&product.status().is_some();raw_facts.quote_html=html;quote_products.push((product,painted));}
        if crate::profile_raw::root_preview_allowed(&raw,&raw_facts){raw_facts.link_card=crate::direct_preview::resolve_cache_only_guarded(db,dns,&crate::profile_raw::current_plain(&raw,&raw_facts),raw_facts.card.now,Some(stage.as_ref())).await?;}
        dependencies.push((status,key,profile,masto));cards.push((index,AdmittedRawProfileStatus{status:raw,facts:raw_facts}));
    }
    page.require_current_ledger(db).await?;
    if root_content_blocked_for(db,session.owner,&session.canonical_actor).await?{bail!("PHP fallback: authenticated raw root changed before publication")}
    let final_target=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&session.actor_key]).await?.map(|row|row.get::<_,Value>(0));
    if final_target.as_ref()!=Some(&target){bail!("PHP fallback: raw target identity changed before publication")}
    let final_owner=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.context("inactive raw profile owner before publication")?;
    if final_owner.get::<_,String>(0)!=owner_actor||final_owner.get::<_,String>(1)!=owner_key{bail!("raw profile owner changed before publication")}
    let proof=RawProfileProof{owner:session.owner,owner_snapshot:Some(owner_snapshot),key:session.actor_key.clone(),actor:session.canonical_actor.clone(),target,dependencies,quotes:quote_products,mapping_changes};let batch=AuthenticatedRawProfileCards{page,cards,proof};batch.require_current(db).await?;Ok(batch)
}
pub(crate) struct GuestProfileCards {page:ProfilePage,cards:Vec<(usize,AdmittedGuestProfileStatus)>,remaining_preview_budget:i64,quote_state:Option<crate::profile_quote_state::QuotePreviewState>}
impl GuestProfileCards {
    pub(crate) fn page(&self)->&ProfilePage{&self.page}
    pub(crate) fn remaining_preview_budget(&self)->i64{self.remaining_preview_budget}
    pub(crate) fn quote_state(&self)->Option<&crate::profile_quote_state::QuotePreviewState>{self.quote_state.as_ref()}
    pub(crate) fn cards(&self)->impl Iterator<Item=(usize,&AdmittedGuestProfileStatus)>{self.cards.iter().map(|(index,card)|(*index,card))}
}
pub(crate) async fn admit_guest_shared(db:&Client,session:&GuestProfileSession,page:ProfilePage,sources:Vec<ProfileCardSource>,dns:&dyn crate::direct_preview::PreviewDns)->Result<GuestProfileCards>{
    page.require_current_ledger(db).await?;
    page.require_target(&session.actor_key,&session.source_actor)?;
    let mut remaining_preview_budget=session.status_preview_budget;
    let mut quote_state=session.quote_preview_budget.map(crate::profile_quote_state::QuotePreviewState::new);
    let mut prior=None;
    let mut cards=Vec::new();
    if root_content_blocked_for(db,0,&session.canonical_actor).await?{bail!("PHP fallback: guest profile root blocked")}
    let target_snapshot=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&session.actor_key]).await?.map(|row|row.get::<_,Value>(0));
    for source in sources {
        if source.source_index>=page.selected_count||prior.is_some_and(|index|source.source_index<=index){bail!("invalid original guest source positions")}
        prior=Some(source.source_index);
        crate::library::context::require_ap_preview_protocol(&source.status,0)?;
        let original_content=source.status.get("reblog").filter(|child|child.is_object()&&source.status.get("content").and_then(Value::as_str)==Some("")).unwrap_or(&source.status).get("content").and_then(Value::as_str).map(str::to_owned);
        let mut checked=vec![source.status];
        crate::audience::filter_profile_statuses(db,0,crate::audience::Surface::Direct,&mut checked).await?;
        let Some(mut status)=checked.pop()else{continue};
        if !crate::direct_context::local_outbox_tree(db,&status,0).await?||!crate::direct_context::current_tree(db,&status,0).await?{continue}
        let facts=crate::profile_guest_facts::resolve_with_context(db,&status,session.lazy_media,None,session.warm_context.as_deref()).await?;
        let displayed=if status.get("reblog").is_some_and(Value::is_object)&&status.get("content").and_then(Value::as_str)==Some(""){status.get_mut("reblog").unwrap()}else{&mut status};
        if let Some(ask)=facts.ask_row.as_ref(){
            displayed["vaak_ask"]=ask.clone();
            if let Some(answer)=ask.get("_body_answer").and_then(Value::as_str).filter(|answer|!crate::library::card::php_trim(answer).is_empty()){displayed["content"]=Value::String(crate::library::actions::esc(crate::library::card::php_trim(answer)))}
        }
        if status.get("quote").is_some_and(Value::is_object)||status.get("reblog").and_then(|child|child.get("quote")).is_some_and(Value::is_object){quote_state.as_mut().context("PHP fallback: guest quote state capability unavailable")?.prepare_status(db,dns,&mut status,facts.now).await?;}
        let preview=crate::direct_preview::resolve(db,dns,&status,original_content.as_deref(),&facts,&mut remaining_preview_budget).await?;
        let mut final_checked=vec![status];
        crate::audience::filter_profile_statuses(db,0,crate::audience::Surface::Direct,&mut final_checked).await?;
        let Some(status)=final_checked.pop()else{continue};
        if !crate::direct_context::local_outbox_tree(db,&status,0).await?||!crate::direct_context::current_tree(db,&status,0).await?{continue}
        cards.push((source.source_index,AdmittedGuestProfileStatus{status,facts,root_preview:Some(preview)}));
    }
    // All cards, including an already prepared prefix, remain private until
    // the last source's public target identity and root policy are rechecked.
    let current_target=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&session.actor_key]).await?.map(|row|row.get::<_,Value>(0));
    if root_content_blocked_for(db,0,&session.canonical_actor).await?||current_target!=target_snapshot{bail!("PHP fallback: guest profile target changed before publication")}
    for (_,card) in &cards {require_current_profile_card(db,0,card.status()).await?;}
    page.require_current_ledger(db).await?;
    Ok(GuestProfileCards{page,cards,remaining_preview_budget,quote_state})
}
/// Revalidate the entire prepared prefix after the final source await. Failure
/// discards the private batch and its preview state instead of publishing a
/// prefix whose current grant changed while a later card was being prepared.
#[cfg(test)]
pub(crate) async fn require_current_profile_card_for_fixture(db:&Client,owner:i64,status:&Value)->Result<()>{require_current_profile_card(db,owner,status).await}
async fn require_current_profile_card(db:&Client,owner:i64,status:&Value)->Result<()> {
    require_current_profile_cards(db,owner,&[status]).await
}
async fn require_current_profile_cards(db:&Client,owner:i64,statuses:&[&Value])->Result<()> {
    let mut checked=statuses.iter().map(|status|(*status).clone()).collect::<Vec<_>>();
    crate::audience::filter_profile_statuses(db,owner,crate::audience::Surface::Direct,&mut checked).await?;
    if checked.len()!=statuses.len(){bail!("PHP fallback: prepared profile source changed before publication")}
    if !crate::direct_context::profile_trees_current(db,statuses).await? {
        bail!("PHP fallback: prepared profile source changed before publication")
    }
    Ok(())
}
pub(crate) struct ProfileCards {
    page:ProfilePage,
    source_positions:Vec<usize>,
    cards:crate::direct_context::DirectBatch,
}
impl ProfileCards {
    pub(crate) fn page(&self)->&ProfilePage{&self.page}
    pub(crate) fn cards(&self)->impl Iterator<Item=(usize,&crate::direct_context::AdmittedDirectStatus)> {
        self.cards.iter().map(|card|(self.source_positions[card.source_index()],card))
    }
    pub(crate) fn remaining_preview_budget(&self)->i64{self.cards.final_status_preview_budget()}
    pub(crate) fn quote_state(&self)->Option<&crate::profile_quote_state::QuotePreviewState>{self.cards.quote_state()}
    pub(crate) fn preview_budget_changed(&self)->bool{self.cards.status_preview_budget_changed()}
}
async fn root_content_blocked(db:&Client,session:&AuthenticatedProfileSession)->Result<bool>{
    root_content_blocked_for(db,session.owner,&session.canonical_actor).await
}
pub(crate) async fn root_content_blocked_for(db:&Client,owner:i64,actor:&str)->Result<bool>{
    // Profile ownership is the validated caller target, never the displayed
    // boost child. Mutes retain their established explicit-profile behavior.
    let rows=db.query("SELECT scope,value FROM ap_blocks WHERE COALESCE(kind,'block')<>'mute' UNION ALL SELECT scope,value FROM ap_user_blocks WHERE owner_user_id=$1::bigint",&[&owner]).await?.iter().map(|row|serde_json::json!({"scope":row.get::<_,String>(0),"value":row.get::<_,String>(1)})).collect::<Vec<_>>();
    Ok(crate::library::facts::personal_block(&rows,actor).is_some())
}
pub(crate) async fn admit_shared_with_dns(db:&Client,session:&AuthenticatedProfileSession,page:ProfilePage,sources:Vec<ProfileCardSource>,dns:&dyn crate::direct_preview::PreviewDns)->Result<ProfileCards>{
    page.require_current_ledger(db).await?;page.require_target(&session.actor_key,&session.source_actor)?;
    // Positions refer to the original provider rows, including normalized-null
    // and omitted rows. No URI hash matching can collapse duplicate entries.
    let mut prior=None;
    for source in &sources {
        if source.source_index>=page.selected_count||prior.is_some_and(|index|source.source_index<=index){bail!("invalid original profile source positions")}
        prior=Some(source.source_index);
    }
    if root_content_blocked(db,session).await?{bail!("PHP fallback: current profile root content block")}
    let target_snapshot=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&session.actor_key]).await?.map(|row|row.get::<_,Value>(0));
    let owner=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.context("inactive profile owner")?;
    let owner_actor:String=owner.get(0);let owner_key:String=owner.get(1);
    let source_positions=sources.iter().map(|source|source.source_index).collect();
    let cards=crate::direct_context::admit_for_render_with_dns(db,&session.direct,sources.into_iter().map(|source|source.status).collect(),dns).await?;
    if root_content_blocked(db,session).await?{bail!("PHP fallback: profile root changed before publication")}
    let current_target=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&session.actor_key]).await?.map(|row|row.get::<_,Value>(0));if current_target!=target_snapshot{bail!("PHP fallback: profile target changed before publication")}
    let final_owner=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner]).await?.context("inactive profile owner before publication")?;
    if final_owner.get::<_,String>(0)!=owner_actor||final_owner.get::<_,String>(1)!=owner_key{bail!("profile owner changed before publication")}
    for card in cards.iter() {require_current_profile_card(db,session.owner,card.status()).await?;}
    page.require_current_ledger(db).await?;
    Ok(ProfileCards{page,source_positions,cards})
}
enum PreparedProfileFrame {AuthenticatedShared(ProfileCards),GuestShared(GuestProfileCards),AuthenticatedRaw(AuthenticatedRawProfileCards),GuestRaw(GuestRawProfileCards)}
impl PreparedProfileFrame {
 fn reconcile_account_changes(&mut self,changes:&crate::profile_materialize::AccountMappingChanges)->Result<()>{let proof=match self{Self::AuthenticatedRaw(batch)=>Some(&mut batch.proof),Self::GuestRaw(batch)=>Some(&mut batch.proof),_=>None};if let Some(proof)=proof{for(product,_)in &mut proof.quotes{product.reconcile_account_changes(changes)?;}}Ok(())}
 fn account_changes(&self)->&[crate::profile_materialize::AccountMappingChanges]{match self{Self::AuthenticatedRaw(batch)=>&batch.proof.mapping_changes,Self::GuestRaw(batch)=>&batch.proof.mapping_changes,_=>&[]}}
 fn apply_raw_quote_state(&self,remaining:&mut Option<i64>,attempts:&mut usize)->Result<()>{
  let proof=match self{Self::AuthenticatedRaw(batch)=>Some(&batch.proof),Self::GuestRaw(batch)=>Some(&batch.proof),_=>None};
  if let Some(proof)=proof{for(product,painted)in &proof.quotes{if *painted{if let Some(status)=product.status(){let initial=remaining.context("PHP fallback: raw quote requires ordered quote state capability")?;let mut state=crate::profile_quote_state::QuotePreviewState::new(initial);state.apply_plain_quote(status)?;*remaining=Some(state.remaining());*attempts+=state.attempts();}}}}
  Ok(())
 }
 async fn require_current(&self,db:&Client,owner:i64)->Result<()>{match self{
  Self::AuthenticatedShared(batch)=>{batch.page().require_current_ledger(db).await?;for(_,card)in batch.cards(){require_current_profile_card(db,owner,card.status()).await?;}Ok(())},
  Self::GuestShared(batch)=>{batch.page().require_current_ledger(db).await?;for(_,card)in batch.cards(){require_current_profile_card(db,0,card.status()).await?;}Ok(())},
  Self::AuthenticatedRaw(batch)=>batch.require_current(db).await,Self::GuestRaw(batch)=>batch.require_current(db).await
 }}
 fn render(&self)->Result<Vec<(usize,String)>>{self.render_mode(false)}
 fn render_mode(&self,pinned:bool)->Result<Vec<(usize,String)>>{match self{
  Self::AuthenticatedShared(batch)=>batch.cards().map(|(index,card)|Ok((index,if pinned{crate::library::card::render_pinned_direct(card)?}else{crate::library::card::render_direct_admitted(card)?}))).collect(),
  Self::GuestShared(batch)=>batch.cards().map(|(index,card)|Ok((index,if pinned{crate::library::card::render_pinned_guest(card)?}else{crate::library::card::render_guest_profile_admitted(card)?}))).collect(),
  Self::AuthenticatedRaw(batch)=>batch.cards().map(|(index,card)|Ok((index,crate::profile_raw::render(card)?))).collect(),
  Self::GuestRaw(batch)=>batch.cards().map(|(index,card)|Ok((index,crate::profile_raw::render(card)?))).collect()
 }}
}
/// A complete source-ordered native page. Callers supply only a typed native
/// SQL selection and a server session; there is no status/facts payload seam.
pub(crate) struct OrderedProfilePage {pinned:bool,page:ProfilePage,cards:Vec<(usize,String)>,status_state:crate::direct_preview::PreviewState,quote_initial:Option<i64>,quote_remaining:Option<i64>,quote_attempts:usize,frames:Vec<PreparedProfileFrame>,projection:crate::profile_materialize::MaterializedProfilePage,owner:i64,pending_quotes:Vec<crate::profile_materialize::ProjectedQuoteTarget>,bsky:Option<crate::profile_bsky::ProfileBskyPresentation>}
impl OrderedProfilePage {
 #[cfg(test)] pub(crate) fn set_render_clock_for_fixture(&mut self,clock:chrono::DateTime<chrono::Utc>)->Result<()>{for frame in &mut self.frames{match frame{PreparedProfileFrame::AuthenticatedShared(batch)=>batch.cards.set_render_clock_for_fixture(clock),PreparedProfileFrame::GuestShared(batch)=>{for(_,card)in &mut batch.cards{card.facts.now=clock;}},PreparedProfileFrame::AuthenticatedRaw(batch)=>{for(_,card)in &mut batch.cards{card.facts.card.now=clock;}},PreparedProfileFrame::GuestRaw(batch)=>{for(_,card)in &mut batch.cards{card.facts.card.now=clock;}}}}self.cards.clear();for frame in &self.frames{self.cards.extend(frame.render_mode(self.pinned)?);}Ok(())}
 pub(crate) fn selected_slots(&self)->usize{self.projection.sources().len()}
 pub(crate) fn page(&self)->&ProfilePage{&self.page}
 pub(crate) fn cards(&self)->&[(usize,String)]{&self.cards}
 pub(crate) fn status_state(&self)->&crate::direct_preview::PreviewState{&self.status_state}
 pub(crate) fn quote_state(&self)->(Option<i64>,Option<i64>,usize){(self.quote_initial,self.quote_remaining,self.quote_attempts)}
 pub(crate) async fn require_current(&self,db:&Client)->Result<()>{
  if let Some(context)=&self.bsky{context.require_current(db).await?;}self.projection.require_current(db).await?;for quote in &self.pending_quotes{quote.require_current(db).await?;}
  let mut shared=Vec::new();
  let mut raw=Vec::new();
  for frame in &self.frames{match frame{
   PreparedProfileFrame::AuthenticatedShared(batch)=>{if batch.page()!=&self.page{bail!("prepared shared page binding changed")}shared.extend(batch.cards().map(|(_,card)|card.status()));},
   PreparedProfileFrame::GuestShared(batch)=>{if batch.page()!=&self.page{bail!("prepared guest page binding changed")}shared.extend(batch.cards().map(|(_,card)|card.status()));},
   PreparedProfileFrame::AuthenticatedRaw(batch)=>{if batch.page!=self.page{bail!("raw final prefix page differs")}raw.push(&batch.proof);},
   PreparedProfileFrame::GuestRaw(batch)=>{if batch.page!=self.page{bail!("guest raw final prefix page differs")}raw.push(&batch.proof);},
  }}
  require_raw_profile_prefix(db,self.owner,&self.page,&raw).await?;
  require_current_profile_cards(db,self.owner,&shared).await?;self.page.require_current_ledger(db).await
 }
}
struct PendingSourceQuoteGrant<'a>{db:&'a Client,current:std::sync::Arc<dyn crate::profile_enrichment::CurrentEnrichmentGrant>,product:&'a crate::profile_materialize::ProjectedQuoteTarget}
impl crate::profile_quote_warm::CurrentQuoteGrant for PendingSourceQuoteGrant<'_>{fn require_current(&self)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'_>>{Box::pin(async move{self.current.require_current(self.db).await?;self.product.require_current(self.db).await})}}
async fn prepare_shared_pending_quote(db:&Client,viewer:&crate::profile_materialize::ProfileViewer<'_>,original:&Value,status:&Value,current:std::sync::Arc<dyn crate::profile_enrichment::CurrentEnrichmentGrant>,warm:&crate::profile_enrichment::WarmContext)->Result<(Option<std::sync::Arc<crate::profile_quote_warm::PendingQuotePresentation>>,crate::profile_materialize::ProjectedQuoteTarget)>{
 let raw=original.get("raw_create_json").and_then(Value::as_str).context("PHP fallback: pending quote source dialect incomplete")?;let raw:Value=serde_json::from_str(raw)?;let object=raw.get("object").filter(|value|value.is_object()).unwrap_or(&raw);let target=raw_scalar_quote_target(object,status.get("content").and_then(Value::as_str).unwrap_or(""))?.context("PHP fallback: pending source has no bound quote target")?;
 current.require_current(db).await?;let product=crate::profile_materialize::materialize_quote_target(db,viewer,&target).await?;product.require_current(db).await?;if crate::profile_materialize::local_key(target.trim_end_matches('/')).is_none(){let grant=PendingSourceQuoteGrant{db,current,product:&product};let context=warm.quote_context().context("PHP fallback: pending quote warming transport unavailable")?;let now=chrono::Utc::now().timestamp();let presentation=context.prepare_pending(db,&target,now,&grant).await?;crate::profile_quote_warm::CurrentQuoteGrant::require_current(&grant).await?;return Ok((Some(std::sync::Arc::new(presentation)),product));}if product.status().is_some(){bail!("PHP fallback: pending child recovery needs exact painter-stage projection")}
 // A missing durable local note is a denial, not permission to fetch its old body.
 // The retained native lookup ledger binds this absence through final publication.
 let canonical=target.trim_end_matches('/');
 if canonical.starts_with("https://mkultra.monster/users/")&&canonical.contains("/notes/") {
  let slash=format!("{canonical}/");
  let available:bool=db.query_one("SELECT EXISTS(SELECT 1 FROM outbox_notes WHERE id IN ($1,$2))",&[&canonical,&slash]).await?.get(0);
  current.require_current(db).await?;product.require_current(db).await?;
  if !available{return Ok((None,product));}
 }
 let grant=PendingSourceQuoteGrant{db,current,product:&product};let context=warm.quote_context().context("PHP fallback: pending quote warming transport unavailable")?;let now=chrono::Utc::now().timestamp();let presentation=context.prepare_pending(db,&target,now,&grant).await?;if presentation.warm_needed(){let owner=match viewer{crate::profile_materialize::ProfileViewer::Auth(session)=>session.owner_id(),crate::profile_materialize::ProfileViewer::Guest(_)=>0};let effect=context.warm(db,&target,owner,now,&grant).await?;if effect.helper_requested{warm.record_quote_request(&target);}}crate::profile_quote_warm::CurrentQuoteGrant::require_current(&grant).await?;Ok((Some(std::sync::Arc::new(presentation)),product))
}
pub(crate) async fn prepare_ordered_profile_page(db:&Client,viewer:&crate::profile_materialize::ProfileViewer<'_>,selection:crate::profile_source::SelectedProfilePage,dns:&dyn crate::direct_preview::PreviewDns)->Result<OrderedProfilePage>{
 crate::audience::profile_reader_scope(prepare_ordered_profile_page_inner(db,viewer,selection,dns)).await
}
#[cfg(test)]
fn profile_perf_stage(stage:&str,index:usize,started:std::time::Instant){if std::env::var("PROFILE_PERF_STAGES").ok().as_deref()==Some("1"){eprintln!("PROFILE_STAGE {stage} {index} {}",started.elapsed().as_micros());}}
async fn prepare_ordered_profile_page_inner(db:&Client,viewer:&crate::profile_materialize::ProfileViewer<'_>,selection:crate::profile_source::SelectedProfilePage,dns:&dyn crate::direct_preview::PreviewDns)->Result<OrderedProfilePage>{
 let(owner,key,actor,warm,initial,quote_initial)=match viewer{
  crate::profile_materialize::ProfileViewer::Auth(session)=>(session.owner_id(),session.actor_key(),session.source_actor(),session.warm_context(),session.direct.status_preview_budget(),session.direct.quote_preview_budget()),
  crate::profile_materialize::ProfileViewer::Guest(session)=>(0,session.actor_key(),session.source_actor(),session.warm_context(),session.status_preview_budget,session.quote_preview_budget)
 };
 #[cfg(test)] let timer=std::time::Instant::now();
 let pinned=selection.tab()=="pinned";
 crate::profile_bridge::ensure_budget()?;
 reject_unsupported_profile_quotes(selection.rows())?;
 let mut projection=crate::profile_materialize::materialize(db,viewer,selection).await?;
 #[cfg(test)] profile_perf_stage("materialize",0,timer);
 #[cfg(test)] let timer=std::time::Instant::now();
 let grant=admit_projection_sources(db,viewer,&projection).await?;
 #[cfg(test)] profile_perf_stage("projection_admission",0,timer);
 let page=projection.page().clone();let mut graphs=crate::profile_materialize::FollowGraphPresentation::new(warm.map(|context|context.cfg()));
 let mut indices=projection.sources().iter().map(|source|source.source_index()).collect::<Vec<_>>();let mut frames:Vec<PreparedProfileFrame>=Vec::new();let mut remaining=initial;let mut quote_remaining=quote_initial;let mut quote_attempts=0;let mut pending_quotes:Vec<crate::profile_materialize::ProjectedQuoteTarget>=Vec::new();
 if !indices.is_empty()&&projection.sources().iter().all(|source|matches!(source.source(),crate::profile_materialize::ProfileSourceKind::Raw(_))&&source.presentation_ready()){
  crate::profile_bridge::ensure_budget()?;
  projection.require_current(db).await?;
  let rows=indices.iter().filter_map(|index|projection.sources().iter().find(|source|source.source_index()==*index).and_then(|source|match source.source(){crate::profile_materialize::ProfileSourceKind::Raw(raw)=>Some((*index,raw.clone())),_=>None})).collect::<Vec<_>>();
  let frame=match viewer{
   crate::profile_materialize::ProfileViewer::Guest(session)=>{let mut session=(*session).clone();session.status_preview_budget=remaining;session.quote_preview_budget=quote_remaining;let selection=crate::profile_source::SelectedProfilePage::raw_subset(page.clone(),rows,key,actor)?;PreparedProfileFrame::GuestRaw(admit_guest_raw_with_dns(db,&session,selection,dns).await?)},
   crate::profile_materialize::ProfileViewer::Auth(session)=>{let mut session=(*session).clone();session.direct=session.direct.with_status_preview_budget(remaining)?;if let Some(budget)=quote_remaining{session.direct=session.direct.with_quote_preview_budget(budget);}let selection=crate::profile_source::SelectedProfilePage::raw_subset(page.clone(),rows,key,actor)?;PreparedProfileFrame::AuthenticatedRaw(admit_authenticated_raw_with_dns(db,&session,selection,dns).await?)},
  };
  frame.apply_raw_quote_state(&mut quote_remaining,&mut quote_attempts)?;frames.push(frame);indices.clear();
 }
 for index in indices{
  crate::profile_bridge::ensure_budget()?;
  #[cfg(test)] let timer=std::time::Instant::now();
  let source=projection.sources().iter().find(|source|source.source_index()==index).context("profile source ordinal disappeared")?;
  let before=match source.source(){crate::profile_materialize::ProfileSourceKind::Shared(status)=>Some(status.clone()),_=>None};
  if let Some(status)=before.as_ref(){
   if grant.disposition(index,status)!=Some(true){continue}
   let effects=projection.effects().iter().filter(|effect|effect.source_index()==index).cloned().collect::<Vec<_>>();for effect in &effects{
    grant.require_current(db,index,status).await?;
    match effect.effect(){
     crate::profile_materialize::ProjectionEffect::ActorWarm(actor)=>{let current=projection.effect_current_grant(status,&grant,index);warm.context("PHP fallback: projector actor effect transport unavailable")?.actor_with_grant(db,actor,&current).await?;},
     crate::profile_materialize::ProjectionEffect::MediaWarm(actor)=>{let current=projection.effect_current_grant(status,&grant,index);warm.context("PHP fallback: projector media effect transport unavailable")?.media_with_grant(db,actor,&current).await?;},
     crate::profile_materialize::ProjectionEffect::RememberAccount{..}|crate::profile_materialize::ProjectionEffect::PostMediaTouch{..}=>{let changes=projection.apply_source_effect(db,effect,status,&grant).await?;for frame in &mut frames{frame.reconcile_account_changes(&changes)?;}for product in &mut pending_quotes{product.reconcile_account_changes(&changes)?;}}
     crate::profile_materialize::ProjectionEffect::PostMediaWarm{..}=>{let warm=warm.context("PHP fallback: post-media transport unavailable")?;projection.apply_source_post_media_warm(db,effect,status,&grant,warm.cfg(),warm.transport()).await?;}
    }
    grant.require_current(db,index,status).await?;
   }
  }
  #[cfg(test)] profile_perf_stage("source_effects",index,timer);
  #[cfg(test)] let timer=std::time::Instant::now();
  projection.complete_source_presentation_with_preview(db,dns,&grant,index,&mut graphs,warm.and_then(|warm|warm.preview_context())).await?;
  #[cfg(test)] profile_perf_stage("source_presentation",index,timer);
  #[cfg(test)] let timer=std::time::Instant::now();
  let source=projection.sources().iter().find(|source|source.source_index()==index).context("profile source ordinal disappeared after cache completion")?;
  let mut preview_writes=None;
  let frame=match(source.source(),viewer){
   (crate::profile_materialize::ProfileSourceKind::Omitted(_),_)=>continue,
   (crate::profile_materialize::ProfileSourceKind::Shared(status),crate::profile_materialize::ProfileViewer::Auth(session))=>{
    let mut session=(*session).clone();session.direct=session.direct.with_status_preview_budget(remaining)?;if let Some(budget)=quote_remaining{session.direct=session.direct.with_quote_preview_budget(budget)}
    if let Some(context)=warm{let(current,journal)=projection.source_current_grant_with_preview_receipts(grant.clone(),index,before.clone().context("shared original admission missing")?);preview_writes=Some(journal);session=session.with_warm_context(context.bound(current));}
    if status.get("quote").is_some_and(|quote|quote.is_object()&&!quote.get("quoted_status").is_some_and(Value::is_object)){
     let current=projection.source_current_grant_owned(grant.clone(),index,before.clone().context("pending original source missing")?);let(pending,product)=prepare_shared_pending_quote(db,viewer,source.original(),status,current,warm.context("pending quote context unavailable")?).await?;session.direct=match pending{Some(pending)=>session.direct.with_pending_quote(pending),None=>session.direct.with_suppressed_pending_quote()};pending_quotes.push(product);
    }
    let batch=admit_shared_with_dns(db,&session,page.clone(),vec![ProfileCardSource::shared(index,status.clone())?],dns).await?;remaining=batch.remaining_preview_budget();if let Some(state)=batch.quote_state(){quote_remaining=Some(state.remaining());quote_attempts+=state.attempts();}PreparedProfileFrame::AuthenticatedShared(batch)
   },
   (crate::profile_materialize::ProfileSourceKind::Shared(status),crate::profile_materialize::ProfileViewer::Guest(session))=>{
    let mut session=(*session).clone();session.status_preview_budget=remaining;session.quote_preview_budget=quote_remaining;
    if let Some(context)=warm{let(current,journal)=projection.source_current_grant_with_preview_receipts(grant.clone(),index,before.clone().context("shared original admission missing")?);preview_writes=Some(journal);session=session.with_warm_context(context.bound(current));}
    let batch=admit_guest_shared(db,&session,page.clone(),vec![ProfileCardSource::shared(index,status.clone())?],dns).await?;remaining=batch.remaining_preview_budget();if let Some(state)=batch.quote_state(){quote_remaining=Some(state.remaining());quote_attempts+=state.attempts();}PreparedProfileFrame::GuestShared(batch)
   },
   (crate::profile_materialize::ProfileSourceKind::Raw(raw),crate::profile_materialize::ProfileViewer::Auth(session))=>{
    let mut session=(*session).clone();session.direct=session.direct.with_status_preview_budget(remaining)?;if let Some(budget)=quote_remaining{session.direct=session.direct.with_quote_preview_budget(budget)}let selection=crate::profile_source::SelectedProfilePage::raw_subset(page.clone(),vec![(index,raw.clone())],key,actor)?;PreparedProfileFrame::AuthenticatedRaw(admit_authenticated_raw_with_dns(db,&session,selection,dns).await?)
   },
   (crate::profile_materialize::ProfileSourceKind::Raw(raw),crate::profile_materialize::ProfileViewer::Guest(session))=>{
    let mut session=(*session).clone();session.status_preview_budget=remaining;session.quote_preview_budget=quote_remaining;let selection=crate::profile_source::SelectedProfilePage::raw_subset(page.clone(),vec![(index,raw.clone())],key,actor)?;PreparedProfileFrame::GuestRaw(admit_guest_raw_with_dns(db,&session,selection,dns).await?)
   }
  };
  #[cfg(test)] profile_perf_stage("frame_admission",index,timer);
  if let Some(journal)=preview_writes{let changes=journal.drain_changes()?;projection.reconcile_account_changes(&changes)?;for previous in &mut frames{previous.reconcile_account_changes(&changes)?;}for product in &mut pending_quotes{product.reconcile_account_changes(&changes)?;}}
  // Previously prepared frames remain private until the last source. Their
  // final proof survives this frame's cache/fact/warming awaits.
  for changes in frame.account_changes(){projection.reconcile_account_changes(changes)?;for previous in &mut frames{previous.reconcile_account_changes(changes)?;}}frame.apply_raw_quote_state(&mut quote_remaining,&mut quote_attempts)?;frames.push(frame);
 }
 // Painting performs no async IO. Revalidate the complete private page once
 // after this synchronous paint, rather than reading identical snapshots both
 // immediately before and immediately after it.
 #[cfg(test)] let timer=std::time::Instant::now();
 let mut cards=Vec::new();for frame in &frames{cards.extend(frame.render_mode(pinned)?);}
 if cards.windows(2).any(|pair|pair[0].0>=pair[1].0){bail!("ordered profile publication lost source positions")}
 #[cfg(test)] profile_perf_stage("paint",0,timer);
 #[cfg(test)] let timer=std::time::Instant::now();
 let status_state=crate::direct_preview::PreviewState::from_remaining(initial,remaining,page.source_count())?;
 let bsky=match viewer{crate::profile_materialize::ProfileViewer::Auth(session)=>session.direct.profile_bsky().cloned(),crate::profile_materialize::ProfileViewer::Guest(_)=>None};let result=OrderedProfilePage{pinned,page,cards,status_state,quote_initial,quote_remaining,quote_attempts,frames,projection,owner,pending_quotes,bsky};result.require_current(db).await?;
 #[cfg(test)] profile_perf_stage("final_proof",0,timer);
 Ok(result)
}
#[cfg(test)]
mod tests {
 use super::*;
 #[test]
 fn actual_php_fep_tag_quote_targets()->Result<()>{
  let script=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/profile-tag-target-reference.php");
  let output=std::process::Command::new("php").arg(script).output()?;
  anyhow::ensure!(output.status.success(),"{}",String::from_utf8_lossy(&output.stderr));
  let reference:Value=serde_json::from_slice(&output.stdout)?;
  for row in reference["captures"].as_array().unwrap(){
   let expected=row["php_pack"]["url"].as_str().map(|url|crate::library::card::php_trim(url).trim_end_matches('/').to_owned());
   assert_eq!(raw_scalar_quote_target(&row["object"],row["plain"].as_str().unwrap())?,expected,"{}",row["case"]);
  }
  assert!(raw_scalar_quote_target(&serde_json::json!({"tag":{"z":{"rel":"quote","href":"https://first.test/n"},"a":{"rel":"quote","href":"https://second.test/n"}}}),"body").is_err());
  assert_eq!(raw_scalar_quote_target(&serde_json::json!({"tag":[{"rel":"quote","href":"https://bsky.app/profile/a/post/b"}]}),"body")?,Some("https://bsky.app/profile/a/post/b".into()));
  let bsky=raw_quote_intent(&serde_json::json!({"quote":"https://bsky.app/profile/luna.example/post/abc"}),"mirrored").unwrap().unwrap();
  assert_eq!(bsky.url,"https://bsky.app/profile/luna.example/post/abc");assert!(!bsky.tombstone);
  let bridgy=raw_quote_intent(&serde_json::json!({"quoteUrl":"https://bsky.brid.gy/convert/ap/at://did:plc:abc/app.bsky.feed.post/xyz"}),"").unwrap().unwrap();
  assert!(bridgy.url.contains("bsky.brid.gy"));
  let embedded=raw_quote_intent(&serde_json::json!({"quote":{"type":"Note","id":"https://remote.test/notes/1","content":"<p>Quoted</p>","attachment":[{"type":"Image","url":"https://cdn.test/a.jpg","mediaType":"image/jpeg"}]}}),"").unwrap().unwrap();
  assert!(embedded.embedded.is_some());assert_eq!(embedded.url,"https://remote.test/notes/1");
  let tomb=raw_quote_intent(&serde_json::json!({"quote":{"type":"Tombstone","id":"https://remote.test/notes/gone"}}),"").unwrap().unwrap();
  assert!(tomb.tombstone);
  assert_eq!(raw_quote_intent(&serde_json::json!({"quote":"https://remote.example/users/a/statuses/9"}),"pending").unwrap().unwrap().url,"https://remote.example/users/a/statuses/9");
  assert!(raw_quote_intent(&serde_json::json!({}),"\nQT: quoted without a url").unwrap().is_none());
  assert!(raw_quote_intent(&serde_json::json!({"tag":{"z":{"rel":"quote","href":"https://first.test/n"},"a":{"rel":"quote","href":"https://second.test/n"}}}),"body").is_err());
  Ok(())
 }
 #[tokio::test]
 #[ignore="actual PHP raw-card FEP target oracle with disposable PostgreSQL"]
 async fn actual_php_fep_tag_raw_profile_cards()->Result<()>{
  let input:Value=serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_TAG_REFERENCE")?)?)?;
  let connection=std::env::var("LIBRARY_FIXTURE_DB")?;anyhow::ensure!(connection.contains("/tmp/"),"owned fixture database required");
  let db=crate::db::connect(&connection).await?;let mut output=Vec::new();
  for width in ["INTEGER","BIGINT"]{
   let schema=format!("profile_tag_native_{}_{}",width.to_lowercase(),std::process::id());
   db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await?;
   let result:Result<()>=async{
    for(table,columns)in input["current_schema_by_width"][width].as_object().unwrap(){
     let defs=columns.as_array().unwrap().iter().map(|column|format!("{} {}",column["column_name"].as_str().unwrap(),column["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");
     db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await?;
    }
    for capture in input["captures"].as_array().unwrap().iter().filter(|capture|capture["width"]==width){
     for(table,rows)in capture["current_tables"].as_object().unwrap(){
      db.batch_execute(&format!("DELETE FROM {table}")).await?;
      for row in rows.as_array().unwrap(){let text=serde_json::to_string(row)?;db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1::text::jsonb)"),&[&text]).await?;}
     }
     let session=GuestProfileSession::from_php_guest_profile("fixture".into(),"https://mkultra.monster/users/fixture".into(),false,0)?;
     let selection=crate::profile_source::select(&db,"fixture","https://mkultra.monster/users/fixture","posts",1,0).await?;
     anyhow::ensure!(selection.rows().first()==Some(&capture["source"]),"actual first raw source binding");
     let mut batch=admit_guest_raw_with_dns(&db,&session,selection,&crate::direct_preview::SystemDns).await?;
     anyhow::ensure!(batch.cards.len()==1,"selected raw card must be prepared natively");
     batch.cards[0].1.facts.card.now=chrono::DateTime::from_timestamp(capture["context"]["rendered_at"].as_i64().unwrap(),0).unwrap();
     let html=crate::profile_raw::render(&batch.cards[0].1)?;batch.require_current(&db).await?;
     // Source encoding and child audience remain live after preparation.
     let note=capture["source"]["id"].as_str().unwrap();
     db.execute("UPDATE outbox_notes SET content=content||' withdrawn after preparation' WHERE id=$1",&[&note]).await?;
     anyhow::ensure!(batch.require_current(&db).await.is_err(),"edited parent must reject publication");
     output.push(serde_json::json!({"width":width,"case":capture["case"],"variant":capture["variant"],"html":html,"source_withdrawal_rejected":true}));
    }
    Ok(())
   }.await;
   db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await?;result?;
  }
  std::fs::write(std::env::var("PROFILE_TAG_NATIVE")?,serde_json::to_vec_pretty(&output)?)?;
  anyhow::ensure!(output.len()==32,"complete both-width fixture coverage");Ok(())
 }
 struct RejectDns;
 impl crate::direct_preview::PreviewDns for RejectDns{
  fn ipv4<'a>(&'a self,_:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<std::net::Ipv4Addr>>>+Send+'a>>{Box::pin(async{panic!("mismatched quote capability reached DNS")})}
 }
 #[tokio::test]
 #[ignore="opt-in actual current quote products reject target/body capability mismatch before DNS"]
 async fn actual_quote_projection_capability_mismatch(){
  let connection=std::env::var("LIBRARY_FIXTURE_DB").expect("disposable PG");assert!(connection.contains("/tmp/"));
  let input:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-raw-card-cold-production-media-guest-sql-reference.json").unwrap()).unwrap();let db=crate::db::connect(&connection).await.unwrap();let mut output=Vec::new();
  for width in ["INTEGER","BIGINT"]{
   let schema=format!("profile_quote_mismatch_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
   for(table,columns)in input["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
   let capture=input["captures"].as_array().unwrap().iter().find(|row|row["width"]==width&&row["case"]=="quote_child_cw_posts_owner0").unwrap();
   for(table,rows)in capture["current_tables"].as_object().unwrap(){for row in rows.as_array().unwrap(){let value=serde_json::to_string(row).unwrap();db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1::text::jsonb)"),&[&value]).await.unwrap();}}
   let session=GuestProfileSession::from_php_guest_profile("fixture".into(),"https://mkultra.monster/users/fixture".into(),false,0).unwrap();
   for case in ["wrong_target","wrong_body"]{
    let selected=crate::profile_source::select(&db,"fixture","https://mkultra.monster/users/fixture","posts",10,0).await.unwrap();let(_,page)=selected.into_parts();
    let mut product=crate::profile_materialize::materialize_quote_target(&db,&crate::profile_materialize::ProfileViewer::Guest(&session),"https://mkultra.monster/users/fixture/notes/profile-reference-2").await.unwrap();
    let mut status=product.status().unwrap().clone();if case=="wrong_body"{status["content"]=serde_json::json!("Unrelated purported quote body")}
    let grant=AdmittedQuoteProjection{requested:if case=="wrong_target"{"https://mkultra.monster/users/fixture/notes/unrelated".into()}else{product.requested_target().into()},status:Some(status),owner:0,owner_snapshot:None,root_actor:session.canonical_actor().into(),page,parent:capture["source"].clone()};
    let error=product.complete_cache(&db,&RejectDns,&grant).await.unwrap_err().to_string();assert!(error.contains("quote cache grant differs"),"{error}");output.push(serde_json::json!({"width":width,"case":case,"dns_calls":0,"rejected":true}));
   }
   db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
  }
  std::fs::write("/tmp/vaak-profile-quote-capability-mismatch4-native.json",serde_json::to_vec_pretty(&output).unwrap()).unwrap();assert_eq!(output.len(),4);
 }
 #[test]
 fn raw_quote_summary_matches_current_php_split(){
  let source=std::fs::read_to_string(concat!(env!("CARGO_MANIFEST_DIR"),"/../../api/ap-admin.php")).unwrap();
  let start=source.find("function admin_split_quote_summary(").unwrap();let end=start+source[start..].find("/** Media strip inside a quote-block").unwrap();
  let cases=["QTIP is ordinary", "Someone said QT today", "Arrow ↪ remains ordinary", "inline ↪ QT: ordinary", "QT: quoted", "↪ QT: quoted", "comment\nQT: quoted", "comment\n\n➡ QT @a: quoted", "QTCreate {broken}", "QTIP: ordinary", "RE: https://remote.test/post", "comment\nRE: https://remote.test/post", "RE: https://remote.test/post\ncomment", "comment\n→ QTNote quoted", "comment\nQTAnnounce quoted", "comment\nQTQuotePost quoted"];
  let script=format!("<?php function ap_text_looks_like_as2_json($s){{return false;}}\n{}\n$cases=json_decode($argv[1],true);foreach($cases as $s){{$out[]=admin_split_quote_summary($s)!==null;}}echo json_encode($out);",&source[start..end]);
  let path=std::env::temp_dir().join(format!("vaak-raw-quote-split-{}.php",std::process::id()));std::fs::write(&path,script).unwrap();
  let output=std::process::Command::new("php").arg(&path).arg(serde_json::to_string(&cases).unwrap()).output().unwrap();let _=std::fs::remove_file(path);assert!(output.status.success(),"{}",String::from_utf8_lossy(&output.stderr));
  let expected:Vec<bool>=serde_json::from_slice(&output.stdout).unwrap();for(case,expected)in cases.into_iter().zip(expected){assert_eq!(raw_quote_summary_present(case),expected,"{case}");}
 }
 #[test]
 fn profile_source_cursor_and_actor_binding_are_distinct(){
  let page=ProfilePage::from_selected_page(7,10,3,true).unwrap();
  assert_eq!((page.source_count(),page.next_offset(),page.has_more()),(3,10,true));
  let session=AuthenticatedProfileSession::from_php_session(1,"csrf".into(),"alice".into(),"https://mkultra.monster/users/Alice/".into(),true,0).unwrap();
  assert_eq!(session.source_actor(),"https://mkultra.monster/users/Alice");
  assert_eq!(session.canonical_actor,"https://mkultra.monster/users/alice");
  for actor in ["https://mkultra.monster:443/users/alice","https://mkultra.monster:8443/users/alice","https://u@mkultra.monster/users/alice","https://mkultra.monster/users/alice?x=1","https://mkultra.monster/users/alice#fragment","https://mkultra.monster/users/alice/notes/1"] {
   assert!(AuthenticatedProfileSession::from_php_session(1,"csrf".into(),"alice".into(),actor.into(),true,0).is_err());
  }
  for budget in [-1,101]{assert!(GuestProfileSession::from_php_guest_profile("alice".into(),"https://mkultra.monster/users/alice".into(),true,budget).is_err());}
  assert!(AuthenticatedProfileSession::from_php_session(0,"csrf".into(),"alice".into(),"https://mkultra.monster/users/alice".into(),true,0).is_err());
  assert!(AuthenticatedProfileSession::from_php_session(1,"csrf".into(),"alice".into(),"https://mkultra.monster/users/bob".into(),true,0).is_err());
 }
}
