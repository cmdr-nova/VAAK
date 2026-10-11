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
   html.push_str(&format!("<div id=\"timeline-items\" data-view=\"remote_profile\" data-actor=\"{}\" data-tab=\"{}\" data-offset=\"{}\" data-limit=\"12\" data-has-more=\"{}\" data-newest=\"0\">",h(actor),h(tab),body.page().next_offset(),u8::from(body.page().has_more())));
   for(_,card)in body.cards(){html.push_str(card);}
   html.push_str(&format!("</div><div id=\"timeline-status\" class=\"meta\" style=\"padding:.75rem 0;text-align:center\">{}</div><div id=\"timeline-sentinel\" aria-hidden=\"true\" style=\"height:1px\"></div><button type=\"button\" class=\"feed-top-btn\" id=\"feed-top-btn\" title=\"Back to top\" aria-label=\"Back to top\">↑</button></div>",if body.page().has_more(){"Scroll for more…"}else{"End of profile"}));Ok(html)
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
impl AuthenticatedProfileSession {
    pub(crate) fn from_php_session(owner:i64,csrf:String,actor_key:String,source_actor:String,lazy_media:bool,budget:i64)->Result<Self>{
        if actor_key.is_empty()||actor_key.len()>80||!actor_key.bytes().all(|b|b.is_ascii_lowercase()||b.is_ascii_digit()||b==b'_'){bail!("invalid canonical local profile key")}
        let canonical_actor=format!("https://mkultra.monster/users/{actor_key}");
        let source_actor=source_actor.trim().trim_end_matches('/').to_owned();
        let actor_pattern=regex::Regex::new(r"^https://mkultra\.monster/users/([A-Za-z0-9_]+)$").unwrap();
        let captured=actor_pattern.captures(&source_actor).context("invalid original local profile actor")?;
        if captured[1].to_ascii_lowercase()!=actor_key{bail!("profile actor/key binding differs")}
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
        let pattern=regex::Regex::new(r"^https://mkultra\.monster/users/([A-Za-z0-9_]+)$").unwrap();
        let captured=pattern.captures(&source_actor).context("invalid original guest profile actor")?;
        if captured[1].to_ascii_lowercase()!=actor_key{bail!("guest profile actor/key binding differs")}
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
  for(status,key,profile,masto)in &self.dependencies{
   let mut checked=vec![status.clone()];crate::audience::filter_profile_statuses(db,self.owner,crate::audience::Surface::Direct,&mut checked).await?;if checked.len()!=1{bail!("raw prepared prefix audience changed after later source preparation")}
   let current_profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[key]).await?.map(|row|row.get::<_,Value>(0));
   let note=status["uri"].as_str().context("raw prefix lost source identity")?;let current_masto=db.query_opt("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id IN ($1,$2) LIMIT 1",&[&note,&format!("{}/",note.trim_end_matches('/'))]).await?.map(|row|row.get::<_,Value>(0));if current_profile.as_ref()!=Some(profile)||&current_masto!=masto{bail!("raw prepared prefix current presentation changed after later source preparation")}
  }
  for(product,painted)in &self.quotes{product.require_current(db).await?;if *painted{require_current_profile_card(db,self.owner,product.status().context("raw quote prefix lost current child")?).await?;}}
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
 let mut children=Vec::new();for proof in proofs{for(product,painted)in &proof.quotes{product.require_current(db).await?;if *painted{children.push(product.status().context("raw final prefix quote lost child")?);}}}
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
pub(crate) async fn admit_guest_raw_with_dns(db:&Client,session:&GuestProfileSession,selection:crate::profile_source::SelectedProfilePage,dns:&dyn crate::direct_preview::PreviewDns)->Result<GuestRawProfileCards>{
    let (selected_rows,page)=selection.into_indexed_parts();page.require_current_ledger(db).await?;page.require_target(&session.actor_key,&session.source_actor)?;
    if root_content_blocked_for(db,0,&session.canonical_actor).await?{bail!("PHP fallback: raw guest profile root blocked")}
    let target=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&session.actor_key]).await?.map(|row|row.get::<_,Value>(0)).context("PHP fallback: raw profile target unavailable")?;
    let mut cards=Vec::new();let mut dependencies=Vec::new();let mut quote_products:Vec<(crate::profile_materialize::ProjectedQuoteTarget,bool)>=Vec::new();let mut mapping_changes=Vec::new();
    for (index,raw) in selected_rows{
        let note=raw.get("id").and_then(Value::as_str).context("raw profile source is not an outbox note")?;
        let captured=regex::Regex::new(r"^https://mkultra\.monster/users/([A-Za-z0-9_]+)/notes/").unwrap();
        let source_key=captured.captures(note).context("PHP fallback: raw note author shape")?[1].to_owned();
        let actor=format!("https://mkultra.monster/users/{source_key}");let key=source_key.to_ascii_lowercase();
        let profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&key]).await?.map(|row|row.get::<_,Value>(0)).context("PHP fallback: raw profile author unavailable")?;
        let status=serde_json::json!({"id":"","uri":note,"url":note,"visibility":raw.get("visibility").cloned().unwrap_or(serde_json::json!("public")),"content":raw.get("content").cloned().unwrap_or(serde_json::json!("")),"account":{"uri":actor,"url":actor,"acct":key},"media_attachments":[]});
        let mut checked=vec![status.clone()];crate::audience::filter_profile_statuses(db,0,crate::audience::Surface::Direct,&mut checked).await?;
        if checked.is_empty(){continue}
        let plain=crate::library::body::html_to_plain(raw.get("content").and_then(Value::as_str).unwrap_or(""));
        let decoded:Value=serde_json::from_str(raw.get("raw_create_json").and_then(Value::as_str).unwrap_or("")).unwrap_or(Value::Null);
        let object=decoded.get("object").unwrap_or(&decoded);
        let quote_target=raw_scalar_quote_target(object,&plain)?;
        let masto=db.query_opt("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id IN ($1,$2) LIMIT 1",&[&note,&format!("{}/",note.trim_end_matches('/'))]).await?.map(|row|row.get::<_,Value>(0));
        let stage=std::sync::Arc::new(RawStageGrant{proof:RawProfileProof{owner:0,owner_snapshot:None,key:session.actor_key.clone(),actor:session.canonical_actor.clone(),target:target.clone(),dependencies:vec![(status.clone(),key.clone(),profile.clone(),masto.clone())],quotes:vec![],mapping_changes:vec![]},page:page.clone()});
        stage.proof.require_current(db,&page).await?;let scoped_warm=session.warm_context().map(|context|context.bound(stage.clone()));let mut scoped_session=session.clone();if let Some(context)=&scoped_warm{scoped_session=scoped_session.with_warm_context(context.clone());}
        let mut facts=crate::profile_guest_facts::resolve_with_context(db,&status,session.lazy_media,Some(&plain),scoped_warm.as_deref()).await?;
        stage.proof.require_current(db,&page).await?;
        // The raw guest dialect exposes no engagement actions/counts.
        facts.current_action_counts=None;
        if facts.owner_id!=0||!facts.owner_actor.is_empty()||!facts.csrf.is_empty()||facts.own.is_some(){bail!("raw guest requires isolated public facts")}
        let attachments=match object.get("attachment"){Some(Value::Array(items))=>items.clone(),Some(item)if item.is_object()=>vec![item.clone()],_=>Vec::new()};
        let urls=attachments.iter().filter_map(|item|item.get("url").and_then(Value::as_str).or_else(||item.get("url").and_then(|url|url.get("href")).and_then(Value::as_str))).map(str::to_owned).collect::<Vec<_>>();
        let previews=db.query("SELECT public_url,preview_url FROM masto_media WHERE public_url=ANY($1) AND preview_url IS NOT NULL",&[&urls]).await?.into_iter().map(|row|(row.get::<_,String>(0),row.get::<_,String>(1))).collect();
        let md5:String=db.query_one("SELECT md5($1::text)",&[&note]).await?.get(0);
        let mut raw_facts=crate::profile_raw::RawProfileFacts{card:facts,actor_key:key.clone(),actor:actor.clone(),display_name:profile.get("name").and_then(Value::as_str).unwrap_or(&key).into(),note_dom_id:md5,masto_row:masto.clone(),status_id:String::new(),own_note:false,guest:true,quote_html:String::new(),link_card:None,media_preview_by_url:previews};
        if let Some(target)=quote_target.as_deref(){let(html,product,changes)=prepare_raw_quote(db,&crate::profile_materialize::ProfileViewer::Guest(&scoped_session),&page,index,&raw,&status,target,&raw_facts.card,dns).await?;for change in &changes{for(previous,_)in &mut quote_products{previous.reconcile_account_changes(change)?;}}mapping_changes.extend(changes);let painted=!html.is_empty();raw_facts.quote_html=html;quote_products.push((product,painted));}
        if crate::profile_raw::root_preview_allowed(&raw,&raw_facts){raw_facts.link_card=crate::direct_preview::resolve_cache_only_guarded(db,dns,&crate::profile_raw::current_plain(&raw,&raw_facts),raw_facts.card.now,Some(stage.as_ref())).await?;}
        dependencies.push((status,key,profile,masto));cards.push((index,AdmittedRawProfileStatus{status:raw,facts:raw_facts}));
    }
    for (product,painted) in &quote_products{product.require_current(db).await?;if *painted{require_current_profile_card(db,0,product.status().context("raw quote lost current child")?).await?;}}
    for (status,key,profile,masto) in dependencies.clone() {
        let mut checked=vec![status.clone()];crate::audience::filter_profile_statuses(db,0,crate::audience::Surface::Direct,&mut checked).await?;
        if checked.is_empty(){bail!("PHP fallback: raw guest prefix audience changed")}
        let current_profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&key]).await?.map(|row|row.get::<_,Value>(0));
        let note=status["uri"].as_str().unwrap();let current_masto=db.query_opt("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id IN ($1,$2) LIMIT 1",&[&note,&format!("{}/",note.trim_end_matches('/'))]).await?.map(|row|row.get::<_,Value>(0));
        if current_profile.as_ref()!=Some(&profile)||current_masto!=masto{bail!("PHP fallback: raw current presentation source changed")}
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
 regex::Regex::new(r"(?:^|\n)\s*(?:↪|➡|→)?\s*QT(?:Create|Announce|Update|Note|QuotePost|\b)").unwrap().is_match(text)
 ||regex::Regex::new(r"(?s)^(.*?)(?:\n|^)RE:\s*(https://[^\s<>]+)\s*$").unwrap().is_match(text)
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
  let target=value.as_str().context("PHP fallback: embedded raw quote pack requires native assembly")?;
  if target.starts_with("https://"){
   if target.starts_with("https://bsky.app/"){bail!("PHP fallback: raw Bluesky quote normalization incomplete")}
   return Ok(Some(crate::library::card::php_trim(target).trim_end_matches('/').into()));
  }
 }
 if raw_quote_summary_present(plain)||raw_quote_tag_present(object)||object.get("quoteObject").is_some_and(|value|!value.is_null()){
  bail!("PHP fallback: raw summary/tag quote pack requires native assembly")
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
async fn prepare_raw_quote(db:&Client,viewer:&crate::profile_materialize::ProfileViewer<'_>,page:&ProfilePage,index:usize,raw:&Value,root:&Value,target:&str,facts:&crate::library::card::CardFacts,dns:&dyn crate::direct_preview::PreviewDns)->Result<(String,crate::profile_materialize::ProjectedQuoteTarget,Vec<crate::profile_materialize::AccountMappingChanges>)>{
 page.require_source_row(index,raw)?;page.require_current_ledger(db).await?;
 let owner=match viewer{crate::profile_materialize::ProfileViewer::Auth(session)=>session.owner_id(),crate::profile_materialize::ProfileViewer::Guest(_)=>0};
 let mut roots=vec![root.clone()];crate::audience::filter_profile_statuses(db,owner,crate::audience::Surface::Direct,&mut roots).await?;
 if roots.len()!=1{bail!("PHP fallback: raw quote parent changed before child assembly")}
 let mut product=crate::profile_materialize::materialize_quote_target(db,viewer,target).await?;let mut mapping_changes=Vec::new();
 product.require_current(db).await?;
 let child=product.status().context("PHP fallback: unavailable raw quote requires warming/stub assembly")?;
 let mut checked=vec![child.clone()];crate::audience::filter_profile_statuses(db,owner,crate::audience::Surface::Direct,&mut checked).await?;
 let allowed=checked.len()==1&&crate::direct_context::local_outbox_tree(db,child,0).await?&&crate::direct_context::current_tree(db,child,0).await?;
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
 let mut sources=Vec::new();let mut denied=Vec::new();
 for source in page.sources(){
  let crate::profile_materialize::ProfileSourceKind::Shared(status)=source.source() else{continue};
  let mut checked=vec![status.clone()];crate::audience::filter_profile_statuses(db,owner,crate::audience::Surface::Direct,&mut checked).await?;
  if checked.is_empty(){denied.push((source.source_index(),status.clone()));continue}
  if !crate::direct_context::local_outbox_tree(db,status,0).await?||!crate::direct_context::current_tree(db,status,0).await?{denied.push((source.source_index(),status.clone()));continue}
  sources.push((source.source_index(),status.clone()));
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
    let mut cards=Vec::new();let mut dependencies=Vec::new();let mut quote_products:Vec<(crate::profile_materialize::ProjectedQuoteTarget,bool)>=Vec::new();let mut mapping_changes=Vec::new();
    for (index,raw) in selected_rows{
        let note=raw.get("id").and_then(Value::as_str).context("raw profile source is not an outbox note")?;
        let captured=regex::Regex::new(r"^https://mkultra\.monster/users/([A-Za-z0-9_]+)/notes/").unwrap();
        let source_key=captured.captures(note).context("PHP fallback: raw note author shape")?[1].to_owned();
        let actor=format!("https://mkultra.monster/users/{source_key}");let key=source_key.to_ascii_lowercase();
        let profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&key]).await?.map(|row|row.get::<_,Value>(0)).context("PHP fallback: raw profile author unavailable")?;
        let status=serde_json::json!({"id":"","uri":note,"url":note,"visibility":raw.get("visibility").cloned().unwrap_or(serde_json::json!("public")),"content":raw.get("content").cloned().unwrap_or(serde_json::json!("")),"account":{"uri":actor,"url":actor,"acct":key},"media_attachments":[]});
        let mut checked=vec![status.clone()];crate::audience::filter_profile_statuses(db,session.owner,crate::audience::Surface::Direct,&mut checked).await?;
        if checked.is_empty(){continue}
        let plain=crate::library::body::html_to_plain(raw.get("content").and_then(Value::as_str).unwrap_or(""));
        let decoded:Value=serde_json::from_str(raw.get("raw_create_json").and_then(Value::as_str).unwrap_or("")).unwrap_or(Value::Null);
        let object=decoded.get("object").unwrap_or(&decoded);
        let quote_target=raw_scalar_quote_target(object,&plain)?;
        let masto=db.query_opt("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id IN ($1,$2) LIMIT 1",&[&note,&format!("{}/",note.trim_end_matches('/'))]).await?.map(|row|row.get::<_,Value>(0));
        let stage=std::sync::Arc::new(RawStageGrant{proof:RawProfileProof{owner:session.owner,owner_snapshot:Some(owner_snapshot.clone()),key:session.actor_key.clone(),actor:session.canonical_actor.clone(),target:target.clone(),dependencies:vec![(status.clone(),key.clone(),profile.clone(),masto.clone())],quotes:vec![],mapping_changes:vec![]},page:page.clone()});
        stage.proof.require_current(db,&page).await?;let mut scoped_session=session.clone();if let Some(context)=session.warm_context(){scoped_session=scoped_session.with_warm_context(context.bound(stage.clone()));}
        let mut raw_facts=crate::profile_raw_facts::resolve_authenticated(db,&scoped_session,&raw,&actor,&key,&profile).await?;
        stage.proof.require_current(db,&page).await?;
        if let Some(target)=quote_target.as_deref(){let(html,product,changes)=prepare_raw_quote(db,&crate::profile_materialize::ProfileViewer::Auth(&scoped_session),&page,index,&raw,&status,target,&raw_facts.card,dns).await?;for change in &changes{for(previous,_)in &mut quote_products{previous.reconcile_account_changes(change)?;}}mapping_changes.extend(changes);let painted=!html.is_empty();raw_facts.quote_html=html;quote_products.push((product,painted));}
        if crate::profile_raw::root_preview_allowed(&raw,&raw_facts){raw_facts.link_card=crate::direct_preview::resolve_cache_only_guarded(db,dns,&crate::profile_raw::current_plain(&raw,&raw_facts),raw_facts.card.now,Some(stage.as_ref())).await?;}
        dependencies.push((status,key,profile,masto));cards.push((index,AdmittedRawProfileStatus{status:raw,facts:raw_facts}));
    }
    for (product,painted) in &quote_products{product.require_current(db).await?;if *painted{require_current_profile_card(db,session.owner,product.status().context("raw quote lost current child")?).await?;}}
    for (status,key,profile,masto) in dependencies.clone() {
        let mut checked=vec![status.clone()];crate::audience::filter_profile_statuses(db,session.owner,crate::audience::Surface::Direct,&mut checked).await?;
        if checked.is_empty(){bail!("PHP fallback: authenticated raw prefix audience changed")}
        let current_profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&key]).await?.map(|row|row.get::<_,Value>(0));
        let note=status["uri"].as_str().unwrap();let current_masto=db.query_opt("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id IN ($1,$2) LIMIT 1",&[&note,&format!("{}/",note.trim_end_matches('/'))]).await?.map(|row|row.get::<_,Value>(0));
        if current_profile.as_ref()!=Some(&profile)||current_masto!=masto{bail!("PHP fallback: raw current presentation source changed")}
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
  if let Some(proof)=proof{for(product,painted)in &proof.quotes{if *painted{let initial=remaining.context("PHP fallback: raw quote requires ordered quote state capability")?;let mut state=crate::profile_quote_state::QuotePreviewState::new(initial);state.apply_plain_quote(product.status().context("raw quote lost budget source")?)?;*remaining=Some(state.remaining());*attempts+=state.attempts();}}}
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
 current.require_current(db).await?;let product=crate::profile_materialize::materialize_quote_target(db,viewer,&target).await?;product.require_current(db).await?;if product.status().is_some(){bail!("PHP fallback: pending child recovery needs exact painter-stage projection")}
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
 let mut projection=crate::profile_materialize::materialize(db,viewer,selection).await?;
 #[cfg(test)] profile_perf_stage("materialize",0,timer);
 #[cfg(test)] let timer=std::time::Instant::now();
 let grant=admit_projection_sources(db,viewer,&projection).await?;
 #[cfg(test)] profile_perf_stage("projection_admission",0,timer);
 let page=projection.page().clone();let mut graphs=crate::profile_materialize::FollowGraphPresentation::new(warm.map(|context|context.cfg()));
 let indices=projection.sources().iter().map(|source|source.source_index()).collect::<Vec<_>>();let mut frames:Vec<PreparedProfileFrame>=Vec::new();let mut remaining=initial;let mut quote_remaining=quote_initial;let mut quote_attempts=0;let mut pending_quotes:Vec<crate::profile_materialize::ProjectedQuoteTarget>=Vec::new();
 for index in indices{
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
