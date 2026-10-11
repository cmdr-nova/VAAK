//! Authenticated Library page context from current server state.
//! No HTTP request may deserialize this type or provide permission decisions.

use anyhow::{Context, Result};
use serde_json::Value;
use tokio_postgres::Client;

/// Only the canonical PHP session bridge may construct this context. It has no
/// deserializer or public constructor and must never be populated from browser or untrusted HTTP JSON, cached role
/// flags, or an unauthenticated route. The gated internal PHP envelope is
/// authenticated separately and the active owner is rechecked in PostgreSQL.
pub(crate) struct TrustedSession {
    owner_id: i64,
    csrf: String,
    return_view: String,
    bsky_enabled: bool,
    lazy_media: bool,
    bsky_bookmarks: std::collections::HashMap<String,bool>,
}

impl TrustedSession {
    pub(super) fn from_php_session(owner_id: i64, csrf: String, return_view: String, bsky_enabled: bool) -> Result<Self> {
        if owner_id < 1 || csrf.is_empty() || !matches!(return_view.as_str(), "bookmarks" | "favourites") {
            anyhow::bail!("invalid canonical PHP Library session");
        }
        Ok(Self { owner_id, csrf, return_view, bsky_enabled, lazy_media:true, bsky_bookmarks:Default::default() })
    }
    pub(super) fn with_lazy_media(mut self, lazy_media:bool)->Self { self.lazy_media=lazy_media; self }
    pub(super) fn lazy_media(&self)->bool { self.lazy_media }
    pub(super) async fn with_bsky_bookmarks(mut self, db:&Client, states:Vec<(String,String,bool)>)->Result<Self>{
        if states.len()>100 {anyhow::bail!("bookmark provenance limit");}
        if states.is_empty(){return Ok(self)}
        let did=db.query_opt("SELECT did FROM bsky_sessions WHERE owner_user_id::bigint=$1 LIMIT 1",&[&self.owner_id]).await?.map(|r|r.get::<_,String>(0)).unwrap_or_default();
        for (uri,source_did,state) in states {
            if did.is_empty()||source_did!=did||!regex::Regex::new(r"^at://did:[^\s/?#]+/app\.bsky\.feed\.post/[^\s/?#]+$").unwrap().is_match(&uri)||uri.len()>2048||uri.chars().any(char::is_whitespace)||self.bsky_bookmarks.insert(uri,state).is_some(){anyhow::bail!("invalid current-owner bookmark provenance");}
        }
        Ok(self)
    }
    pub(super) fn bsky_bookmark_state(&self,status:&Value)->Option<bool>{
        let post=status.get("post").or_else(||status.get("bsky_post_view")).or_else(||status.get("bsky_post").map(|p|p.get("post").unwrap_or(p)))?;
        self.bsky_bookmarks.get(post.get("uri")?.as_str()?).copied()
    }
    pub(super) fn csrf(&self) -> &str { &self.csrf }
    pub(super) fn return_view(&self) -> &str { &self.return_view }
    pub(super) fn bsky_enabled(&self) -> bool {self.bsky_enabled}
}

impl super::facts::PresentationSession for TrustedSession {
    fn csrf(&self)->&str {TrustedSession::csrf(self)}
    fn return_view(&self)->&str {TrustedSession::return_view(self)}
    fn bsky_enabled(&self)->bool {TrustedSession::bsky_enabled(self)}
    fn lazy_media(&self)->bool {TrustedSession::lazy_media(self)}
    fn bsky_bookmark_state(&self,status:&Value)->Option<bool> {TrustedSession::bsky_bookmark_state(self,status)}
}

/// Proof of final source hydration and current audience/moderation admission.
/// A renderer cannot pair a different source snapshot with admitted facts.
pub(crate) struct AdmittedStatus {
    source_index: usize,
    status: Value,
    facts: super::card::CardFacts,
    root_preview: Option<Option<Value>>,
}
impl AdmittedStatus {
    #[cfg(test)] pub(crate) fn set_render_clock(&mut self,clock:chrono::DateTime<chrono::Utc>){self.facts.now=clock;}
    pub(crate) fn source_index(&self)->usize {self.source_index}
    pub(super) fn root_preview(&self)->Option<&Option<Value>> {self.root_preview.as_ref()}
    pub(super) fn status(&self) -> &Value { &self.status }
    pub(super) fn facts(&self) -> &super::card::CardFacts { &self.facts }
}

/// The shared PHP painter reads the displayed incoming SID before the own
/// action bar replaces it with the current local action SID. Library/Search
/// do not run Direct's numeric-only flags prefetch. Object aliases belong to
/// other interaction projections and cannot establish this button state.
pub(crate) fn incoming_ap_boost_sid(status: &Value) -> Option<String> {
    let displayed=status.get("reblog").filter(|value|value.is_object()&&status.get("content").and_then(Value::as_str).unwrap_or("").is_empty()).unwrap_or(status);
    let uri=displayed.get("uri").and_then(Value::as_str).unwrap_or("");
    if is_rss(displayed)||uri.starts_with("at://")||displayed.get("post").is_some_and(Value::is_object)||displayed.get("bsky_post").is_some_and(Value::is_object){return None}
    Some(super::card::scalar_string(displayed,"id"))
}

pub(crate) fn require_unambiguous_boost_selection(status:&Value)->Result<()> {
    if status.get("reblog").is_some_and(Value::is_object) {
        let content=status.get("content").and_then(Value::as_str).context("PHP fallback: non-string boost wrapper content")?;
        if content=="0"||(!content.is_empty()&&super::body::html_to_plain(content).is_empty()) {
            anyhow::bail!("PHP fallback: ambiguous PHP boost wrapper selection");
        }
    }
    Ok(())
}

pub(crate) fn require_canonical_source_ids(status:&Value,depth:usize)->Result<()> {
    if depth>16{anyhow::bail!("PHP fallback: source identity depth")}
    if status.get("uri").is_some_and(|uri|!uri.is_string()){
        anyhow::bail!("PHP fallback: source URI normalization");
    }
    if status.get("id").is_some_and(|id|!id.is_null()&&!id.is_string()&&id.as_i64().is_none()){
        anyhow::bail!("PHP fallback: source identity scalar normalization");
    }
    for field in ["reblog","quote","vaak_quote_preview"] {
        if let Some(value)=status.get(field).filter(|value|value.is_object()) {
            let child=value.get("quoted_status").or_else(||value.get("status")).filter(|value|value.is_object()).unwrap_or(value);
            require_canonical_source_ids(child,depth+1)?;
        }
    }
    Ok(())
}
pub(crate) fn require_ap_preview_protocol(status:&Value,depth:usize)->Result<()> {
    if depth>16{anyhow::bail!("PHP fallback: preview protocol depth")}
    let uri=status.get("uri").and_then(Value::as_str).unwrap_or("");
    let id=super::card::scalar_string(status,"id");
    if !uri.is_empty()&&!uri.starts_with("https://"){anyhow::bail!("PHP fallback: root-preview object URI lacks supported durable HTTPS identity");}
    if status.get("record").is_some_and(Value::is_object)||status.get("vaak_bsky_text").is_some_and(|value|value.as_str().is_none_or(|text|!text.is_empty()))||status.get("vaak_bsky_facets").is_some_and(|value|value.as_array().is_none_or(|facets|!facets.is_empty())){
        anyhow::bail!("PHP fallback: root-preview facet bundle normalization not certified");
    }
    if is_rss(status)||id.starts_with("bsky:")||uri.starts_with("at://")||uri.contains("bsky.app/")||matches!(status.get("source").and_then(Value::as_str),Some("rss"|"bluesky"))||["post","bsky_post","bsky_post_view"].iter().any(|key|status.get(*key).is_some_and(Value::is_object)){
        anyhow::bail!("PHP fallback: protocol-specific root preview not certified");
    }
    for field in ["reblog","quote","vaak_quote_preview"] {
        if let Some(value)=status.get(field).filter(|value|value.is_object()) {
            let child=value.get("quoted_status").or_else(||value.get("status")).filter(|value|value.is_object()).unwrap_or(value);
            require_ap_preview_protocol(child,depth+1)?;
        }
    }
    Ok(())
}

pub(crate) async fn exact_incoming_boost(db:&Client,owner:i64,sid:&str)->Result<bool>{
    if sid.is_empty(){return Ok(false)}
    Ok(db.query_opt("SELECT 1 FROM masto_reblogs WHERE owner_user_id=$1::bigint AND status_id=$2 LIMIT 1",&[&owner,&sid]).await?.is_some())
}

/// Called by the default-off authenticated internal PHP batch bridge. Any
/// unresolved branch returns whole-page canonical PHP fallback without a
/// dropped feature or partial HTML.
pub(crate) async fn admit_for_render(db: &Client, session: &TrustedSession, statuses: Vec<Value>) -> Result<Vec<AdmittedStatus>> {
    admit_for_render_impl(db,session,statuses,None).await.map(|value|value.0)
}
pub(crate) async fn admit_for_render_with_preview(db:&Client,session:&TrustedSession,statuses:Vec<Value>,budget:i64,dns:&dyn crate::direct_preview::PreviewDns)->Result<(Vec<AdmittedStatus>,i64)> {
    admit_for_render_impl(db,session,statuses,Some((budget,dns))).await
}
async fn admit_for_render_impl(db:&Client,session:&TrustedSession,statuses:Vec<Value>,preview:Option<(i64,&dyn crate::direct_preview::PreviewDns)>)->Result<(Vec<AdmittedStatus>,i64)> {
    let identity=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner_id]).await?.context("inactive Library painter owner")?;
    let actor:String=identity.get(0);let actor_key:String=identity.get(1);
    let mut budget=preview.map(|value|value.0).unwrap_or(0);
    let mut bound=Vec::new();let mut source_indices=Vec::new();let mut originals=std::collections::HashMap::new();
    for (source_index,status) in statuses.into_iter().enumerate() {
        if preview.is_some(){require_canonical_source_ids(&status,0)?;require_ap_preview_protocol(&status,0)?;}
        require_unambiguous_boost_selection(&status)?;
        let displayed=status.get("reblog").filter(|value|value.is_object()&&status.get("content").and_then(Value::as_str).unwrap_or("").is_empty()).unwrap_or(&status);
        if preview.is_some()&&displayed.get("content").is_some_and(|value|!value.is_null()&&!value.is_string()){anyhow::bail!("PHP fallback: non-string Library preview source")}
        originals.insert(source_index,displayed.get("content").and_then(Value::as_str).map(str::to_owned));
        if let Some(status)=bind_rss_sources(db,session.owner_id,status,0).await?{source_indices.push(source_index);bound.push(status);}
    }
    // Source hydration may freshen metadata. Keep the canonical presentation
    // snapshot separately so a conflicting own menu is never certified.
    let mut source_visibility=std::collections::HashMap::new();
    for source in &bound {
        let displayed=source.get("reblog").filter(|value|value.is_object()&&source.get("content").and_then(Value::as_str).unwrap_or("").is_empty()).unwrap_or(source);
        if let Some(uri)=displayed.get("uri").and_then(Value::as_str){
            let visibility=displayed.get("visibility").and_then(Value::as_str).unwrap_or("public").to_owned();
            if source_visibility.insert(uri.trim_end_matches('/').to_owned(),visibility.clone()).is_some_and(|previous|previous!=visibility){anyhow::bail!("PHP fallback: conflicting source visibility snapshots");}
        }
    }
    let incoming_sids=bound.iter().map(incoming_ap_boost_sid).collect::<Vec<_>>();
    let hydrated = super::source::hydrate(db, bound).await?;
    if hydrated.len()!=incoming_sids.len()||hydrated.iter().zip(&incoming_sids).any(|(status,incoming)|incoming.is_some()&&incoming_ap_boost_sid(status)!=*incoming){
        anyhow::bail!("PHP fallback: hydration changed the incoming Library boost SID");
    }
    // Genuine Library ingress rejects current hidden/deleted sources before
    // the ordinary painter, with no root-preview budget effects (late210).
    let page = admit_indexed(db,session.owner_id,source_indices.into_iter().zip(hydrated).collect()).await?;
    let mut cards = Vec::new();
    for (source_index,mut status) in page.source_indices.into_iter().zip(page.statuses) {
        let fresh_rss = rss_id(&status).and_then(|id| page.rss_rows.get(&id)).cloned();
        if let Some(row) = fresh_rss.as_ref() {
            status = super::rss::normalize(row,session.owner_id)?;
            crate::interaction_flags::apply_to_statuses(db,session.owner_id,std::slice::from_mut(&mut status)).await?;
        }
        let mut facts = super::facts::resolve(db, session.owner_id, &page.owner_actor, &status, session, page.moderation.auto_unblur_sensitive).await?;
        if let Some(sid)=incoming_ap_boost_sid(&status){facts.boosted=exact_incoming_boost(db,session.owner_id,&sid).await?;}
        facts.rss_row=fresh_rss;
        let inner=status.get("reblog").is_some_and(Value::is_object)&&status.get("content").and_then(Value::as_str).unwrap_or("").is_empty();
        let displayed=if inner{status.get_mut("reblog").unwrap()}else{&mut status};
        if let Some(ask) = facts.ask_row.as_ref() {
            // Current stored questions and resolved identity replace cached Ask
            // metadata before the last source and policy decision.
            displayed["vaak_ask"] = ask.clone();
            let answer = ask.get("_body_answer").and_then(Value::as_str);
            if let Some(answer) = answer.filter(|value| !super::card::php_trim(value).is_empty()) {
                displayed["content"] = serde_json::json!(super::actions::esc(super::card::php_trim(answer)));
            } else if displayed.get("content").and_then(Value::as_str)
                .is_some_and(|value| value.contains("ask-container")) {
                anyhow::bail!("PHP fallback: Ask representation stripping requires canonical source/body normalization");
            }
            let words_only = crate::hidden::HiddenSets {
                muted_phrases: crate::hidden::load_library_hidden_sets(db, session.owner_id).await?.muted_phrases,
                ..Default::default()
            };
            // The component answer is intentionally empty because the shared
            // painter renders the current answer in the post body.
            let text = format!("{}\n{}",
                ask.get("question").and_then(Value::as_str).unwrap_or(""),
                ask.get("_body_answer").or_else(|| ask.get("answer"))
                    .and_then(Value::as_str).unwrap_or(""));
            if crate::hidden::library_status_hidden(
                &serde_json::json!({"content": super::actions::esc(&text)}), &words_only) {
                if preview.is_some(){anyhow::bail!("PHP fallback: current Ask word omission preview state not certified");}
                continue;
            }
        }
        // The PHP painter prefers current stored local plaintext and CW. Those
        // additions must themselves pass muted-word/audience policy before paint.
        if let Some(own) = facts.own.as_ref() {
            let raw_uri=displayed.get("uri").and_then(Value::as_str).unwrap_or("");
            if raw_uri.ends_with('/'){anyhow::bail!("PHP fallback: own trailing-slash presentation requires canonical lookup");}
            let uri=raw_uri.trim_end_matches('/');
            let snapshot=source_visibility.get(uri).map(String::as_str).unwrap_or("public");
            let current=if own.visibility.is_empty(){"public"}else{own.visibility.as_str()};
            if snapshot!=current{anyhow::bail!("PHP fallback: own cached/current visibility presentation conflicts");}
            if !own.plain.is_empty() && !matches!(own.plain.as_str(), "(quote)" | "(media)" | "(poll)") {
                displayed["content"] = serde_json::json!(super::actions::esc(&own.plain));
            }
            displayed["spoiler_text"] = serde_json::json!(own.spoiler);
            displayed["sensitive"] = serde_json::json!(own.sensitive);
            displayed["visibility"] = serde_json::json!(own.visibility);
        }
        if let Some(row)=facts.rss_quote_row.as_ref(){
            // Subscription metadata is owner-proven and current, but fresh
            // title/summary must still pass the viewer's muted-word settings.
            let words_only=crate::hidden::HiddenSets {muted_phrases:crate::hidden::load_library_hidden_sets(db,session.owner_id).await?.muted_phrases,..Default::default()};
            let text=format!("{}\n{}\n{}",row.get("title").and_then(Value::as_str).unwrap_or(""),row.get("summary_text").and_then(Value::as_str).unwrap_or(""),row.get("feed_title").and_then(Value::as_str).unwrap_or(""));
            if crate::hidden::library_status_hidden(&serde_json::json!({"content":super::actions::esc(&text)}),&words_only){if preview.is_some(){anyhow::bail!("PHP fallback: RSS quote word omission preview state not certified");}continue;}
        }
        let final_page = admit_indexed(db,session.owner_id,vec![(source_index,status)]).await?;
        if preview.is_some()&&final_page.statuses.is_empty(){anyhow::bail!("PHP fallback: Library pre-preview final omission state not certified");}
        if let Some(status) = final_page.statuses.into_iter().next() {
            // RSS does not use AS2 actor-domain/audience filtering, but its
            // freshly normalized title/summary still obey saved muted words.
            if is_rss(&status) {
                let words_only=crate::hidden::HiddenSets { muted_phrases:crate::hidden::load_library_hidden_sets(db,session.owner_id).await?.muted_phrases,..Default::default() };
                if crate::hidden::library_status_hidden(&status,&words_only) {if preview.is_some(){anyhow::bail!("PHP fallback: RSS word omission preview state not certified");}continue;}
            }
            let root_preview=if let Some((_,dns))=preview {
                require_ap_preview_protocol(&status,0)?;
                    let card=crate::direct_preview::resolve(db,dns,&status,originals.get(&source_index).and_then(|value|value.as_deref()),&facts,&mut budget).await?;
                    let rechecked=admit_indexed(db,session.owner_id,vec![(source_index,status.clone())]).await?;
                    if rechecked.statuses.is_empty(){continue}
                    Some(card)
            }else{None};
            cards.push(AdmittedStatus { source_index,status,facts,root_preview });
        }
    }
    let final_owner=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&session.owner_id]).await?.context("Library painter owner changed")?;
    if final_owner.get::<_,String>(0)!=actor||final_owner.get::<_,String>(1)!=actor_key{anyhow::bail!("Library identity changed during assembly")}
    Ok((cards,budget))
}

// Every synthetic subscription source, including a quote/reblog/compact child,
// is private owner data until a current joined row proves otherwise. A denied
// child denies its containing card rather than deleting one visible feature.
async fn bind_rss_sources(db: &Client, owner: i64, mut value: Value, depth: usize) -> Result<Option<Value>> {
    if depth > 128 {
        anyhow::bail!("PHP fallback: RSS source nesting exceeds supported JSON limit");
    }
    if value.is_object() && is_rss(&value) {
        let Some(id) = rss_id(&value) else { return Ok(None); };
        let row = db.query_opt(
            "SELECT to_jsonb(i) || jsonb_build_object(
               'feed_title', f.title, 'feed_favicon', f.favicon_url,
               'feed_site_url', f.site_url, 'feed_url', f.feed_url,
               'owner_user_id', f.owner_user_id, 'feed_id', f.id)
             FROM rss_items i JOIN rss_feeds f ON f.id = i.feed_id
             WHERE i.id::bigint = $1 AND f.owner_user_id::bigint = $2",
            &[&id, &owner],
        ).await?;
        let Some(row) = row else { return Ok(None); };
        let row: Value = row.get(0);
        return Ok(Some(super::rss::normalize(&row, owner)?));
    }
    match &mut value {
        Value::Object(map) => {
            for child in map.values_mut() {
                let Some(fresh) = Box::pin(bind_rss_sources(db, owner, child.clone(), depth + 1)).await?
                    else { return Ok(None); };
                *child = fresh;
            }
        }
        Value::Array(list) => {
            for child in list {
                let Some(fresh) = Box::pin(bind_rss_sources(db, owner, child.clone(), depth + 1)).await?
                    else { return Ok(None); };
                *child = fresh;
            }
        }
        _ => {}
    }
    Ok(Some(value))
}

pub struct AdmittedLibraryPage {
    owner_id: i64,
    owner_actor: String,
    statuses: Vec<Value>,
    source_indices: Vec<usize>,
    moderation: crate::hidden::ViewerModeration,
    /// Fresh owner-joined RSS rows; dedicated rendering must use these rows,
    /// never the cached status's article URL/body/author snapshot.
    rss_rows: std::collections::HashMap<i64, Value>,
}

impl AdmittedLibraryPage {
    pub fn owner_id(&self) -> i64 { self.owner_id }
    pub fn owner_actor(&self) -> &str { &self.owner_actor }
    pub fn statuses(&self) -> &[Value] { &self.statuses }
    pub fn auto_unblur_sensitive(&self) -> bool { self.moderation.auto_unblur_sensitive }
    pub fn rss_row(&self, item_id: i64) -> Option<&Value> { self.rss_rows.get(&item_id) }
}

fn rss_id(status: &Value) -> Option<i64> {
    if let Some(id) = status.get("id").and_then(Value::as_str).filter(|id| id.starts_with("rss:"))
        .and_then(|id| super::card::php_trim(id).strip_prefix("rss:")) {
        // A synthetic ID is authoritative: malformed IDs cannot be rescued by
        // a different cached item's metadata, or by Rust's signed-number parser.
        return (!id.is_empty() && id.bytes().all(|byte| byte.is_ascii_digit()))
            .then(|| id.parse::<i64>().ok()).flatten().filter(|id| *id > 0);
    }
    status.get("vaak_rss_item_id").and_then(Value::as_i64).filter(|id| *id > 0)
}

fn is_rss(status: &Value) -> bool {
    status.get("id").and_then(Value::as_str).is_some_and(|id| id.starts_with("rss:"))
        || status.get("source").and_then(Value::as_str) == Some("rss")
        || status.get("vaak_rss_item_id").is_some()
}

/// A complete saved snapshot cannot revive a known withdrawn note. Unknown
/// public sources keep the canonical audience/moderation behavior. Interaction
/// Undo rows (Like/Announce) do not delete the represented original note.
fn not_deleted<'a>(db:&'a Client,status:&'a Value,depth:usize)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<bool>>+Send+'a>> {
    Box::pin(async move {
        if depth>16{return Ok(false)}
        let field=if status.get("uri").is_some_and(|value|!value.is_null()){"uri"}else{"url"};
        let uri=super::card::scalar_string(status,field);
        let uri=super::card::php_trim(&uri).split('#').next().unwrap_or("").trim_end_matches('/');
        if !uri.is_empty(){
            let slash=format!("{uri}/");
            if let Some(row)=db.query_opt("SELECT type,action_taken FROM events WHERE object_id IN ($1,$2) AND (type IN ('Create','Update','Delete','Tombstone') OR (type IN ('Quote','QuotePost') AND NOT EXISTS (SELECT 1 FROM events note_source WHERE note_source.object_id IN ($1,$2) AND note_source.type IN ('Create','Update')))) ORDER BY id DESC LIMIT 1",&[&uri,&slash]).await? {
                let kind:String=row.try_get(0)?;let action:String=row.try_get::<_,Option<String>>(1)?.unwrap_or_default();
                if action=="deleted"||matches!(kind.as_str(),"Delete"|"Tombstone"){return Ok(false)}
            }else if let Some(row)=db.query_opt("SELECT deleted_at::text FROM mentions WHERE object_id IN ($1,$2) ORDER BY id DESC LIMIT 1",&[&uri,&slash]).await? {
                if row.try_get::<_,Option<String>>(0)?.is_some_and(|value|!value.is_empty()&&value!="0"){return Ok(false)}
            }
        }
        for field in ["reblog","quote","vaak_quote_preview"] {
            if let Some(child)=status.get(field).filter(|value|value.is_object()){
                let child=child.get("quoted_status").filter(|value|value.is_object()).or_else(||child.get("status").filter(|value|value.is_object())).unwrap_or(child);
                if !not_deleted(db,child,depth+1).await?{return Ok(false)}
            }
        }
        Ok(true)
    })
}

/// Resolve permission and viewer settings immediately before native rendering.
/// The statuses come from server-owned saved-row normalization, not the browser.
/// Hidden cards retain their saved DB rows and source-row pagination position.
pub async fn admit(db: &Client, authenticated_owner: i64, statuses: Vec<Value>) -> Result<AdmittedLibraryPage> {
    admit_indexed(db,authenticated_owner,statuses.into_iter().enumerate().collect()).await
}
async fn admit_indexed(db:&Client,authenticated_owner:i64,indexed:Vec<(usize,Value)>)->Result<AdmittedLibraryPage> {
    if authenticated_owner < 1 { anyhow::bail!("Library admission requires an authenticated owner"); }
    let owner = db.query_opt("SELECT actor_id FROM ap_users WHERE id::bigint = $1 AND disabled_at IS NULL", &[&authenticated_owner])
        .await.context("resolve active Library owner")?.context("inactive Library owner")?;
    let owner_actor: String = owner.try_get(0)?;
    let mut bound=Vec::new();
    for (index,status) in indexed {if let Some(status)=bind_rss_sources(db,authenticated_owner,status,0).await?{bound.push((index,status));}}
    let (mut source_indices,mut statuses):(Vec<_>,Vec<_>)=bound.into_iter().unzip();
    let hidden = crate::hidden::load_library_hidden_sets(db, authenticated_owner).await?;
    let moderation = crate::hidden::load_viewer_moderation(db, authenticated_owner).await?;
    let mut rss_ids: Vec<i64> = statuses.iter().filter_map(rss_id).collect();
    rss_ids.sort(); rss_ids.dedup();
    let mut rss_rows = std::collections::HashMap::<i64, Value>::new();
    if !rss_ids.is_empty() {
        for row in db.query(
            "SELECT i.id::bigint, to_jsonb(i) || jsonb_build_object(
               'feed_title', f.title, 'feed_favicon', f.favicon_url,
               'feed_site_url', f.site_url, 'feed_url', f.feed_url,
               'owner_user_id', f.owner_user_id, 'feed_id', f.id)
             FROM rss_items i JOIN rss_feeds f ON f.id = i.feed_id
             WHERE i.id::bigint = ANY($1::bigint[]) AND f.owner_user_id::bigint = $2",
            &[&rss_ids, &authenticated_owner],
        ).await.context("recheck Library RSS owner")? {
            rss_rows.insert(row.get(0), row.get(1));
        }
    }
    let mut live=Vec::new();
    for (index,status) in source_indices.into_iter().zip(statuses) {
        if is_rss(&status){if rss_id(&status).is_some_and(|id|rss_rows.contains_key(&id)){live.push((index,status));}}
        else if not_deleted(db,&status,0).await?{live.push((index,status));}
    }
    let non_rss=live.iter().filter(|(_,status)|!is_rss(status)).map(|(_,status)|status.clone()).collect::<Vec<_>>();
    let decisions=crate::audience::admission_mask(db,authenticated_owner,crate::audience::Surface::Library,&non_rss).await?;
    let mut decisions=decisions.into_iter();
    let admitted=live.into_iter().filter(|(_,status)|is_rss(status)||(decisions.next().unwrap_or(false)&&!crate::hidden::library_status_hidden(status,&hidden))).collect::<Vec<_>>();
    (source_indices,statuses)=admitted.into_iter().unzip();
    crate::interaction_flags::apply_to_statuses(db, authenticated_owner, &mut statuses).await?;
    Ok(AdmittedLibraryPage { owner_id: authenticated_owner, owner_actor, statuses, source_indices, moderation, rss_rows })
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn ordinary_preview_unproved_protocol_shapes_preserve_php_fallback() {
        let plain=serde_json::json!({"id":"123","uri":"https://remote.example/notes/123","content":"plain"});
        assert!(require_canonical_source_ids(&plain,0).is_ok());
        assert!(require_ap_preview_protocol(&plain,0).is_ok());
        for (field,value) in [
            ("uri",serde_json::json!("rss:synthetic")),
            ("uri",serde_json::json!("http://remote.example/notes/123")),
            ("uri",serde_json::json!("urn:note:123")),
            ("id",serde_json::json!("bsky:123")),
            ("record",serde_json::json!({})),
            ("vaak_bsky_text",serde_json::json!("https://youtu.be/abcdef12345")),
            ("vaak_bsky_text",serde_json::json!(null)),
            ("vaak_bsky_facets",serde_json::json!([{}])),
            ("vaak_bsky_facets",serde_json::json!("malformed")),
        ] {
            let mut unsupported=plain.clone();unsupported[field]=value;
            assert!(require_ap_preview_protocol(&unsupported,0).is_err(),"{field}");
            for wrapper in ["reblog","quote","vaak_quote_preview"] {
                let mut parent=plain.clone();
                parent[wrapper]=if wrapper=="reblog"{unsupported.clone()}else{serde_json::json!({"quoted_status":unsupported})};
                assert!(require_ap_preview_protocol(&parent,0).is_err(),"nested {wrapper}/{field}");
            }
        }
        for (field,value) in [("uri",serde_json::json!(null)),("uri",serde_json::json!(42)),("id",serde_json::json!(1.0)),("id",serde_json::json!([]))] {
            let mut unsupported=plain.clone();unsupported[field]=value;
            assert!(require_canonical_source_ids(&unsupported,0).is_err(),"{field}");
        }
        let mut empty=plain.clone();empty["vaak_bsky_text"]=serde_json::json!("");empty["vaak_bsky_facets"]=serde_json::json!([]);
        assert!(require_ap_preview_protocol(&empty,0).is_ok());
    }
    #[test]
    fn incoming_boost_sid_uses_displayed_source_without_canonicalizing() {
        let source=serde_json::json!({"id":"outer","content":"","reblog":{"id":"saved:opaque","uri":"https://mkultra.monster/users/fixture/notes/456","content":"body"}});
        assert_eq!(incoming_ap_boost_sid(&source),Some("saved:opaque".into()));
        let mut nonempty=source.clone();nonempty["content"]=serde_json::json!("outer body");
        assert_eq!(incoming_ap_boost_sid(&nonempty),Some("outer".into()));
        assert_eq!(incoming_ap_boost_sid(&serde_json::json!({"id":"rss:1","source":"rss"})),None);
        assert_eq!(incoming_ap_boost_sid(&serde_json::json!({"id":"bsky:x","uri":"at://did:plc:x/app.bsky.feed.post/y"})),None);
        for content in ["0","<p></p>"," "] {
            let mut ambiguous=source.clone();ambiguous["content"]=serde_json::json!(content);
            assert!(require_unambiguous_boost_selection(&ambiguous).is_err());
        }
        assert!(require_unambiguous_boost_selection(&source).is_ok());
    }
    #[tokio::test]
    #[ignore="requires immutable actual PHP boost captures, schema inventory and isolated PostgreSQL"]
    async fn actual_library_search_boost_context_replay()->Result<()> {
        let url=std::env::var("LIBRARY_FIXTURE_DB")?;
        anyhow::ensure!(url.contains("/tmp/"),"isolated Unix socket required");
        let db=crate::db::connect(&url).await?;
        let reference:Value=serde_json::from_slice(&std::fs::read(std::env::var("LIBRARY_SEARCH_BOOST_REFERENCE")?)?)?;
        let schema_reference:Value=serde_json::from_slice(&std::fs::read(std::env::var("LIBRARY_SEARCH_BOOST_SCHEMA")?)?)?;
        let mut output=Vec::new();let mut preference_checks=Vec::new();
        for width in ["INTEGER","BIGINT"] {
            let schema=format!("library_search_boost_native_{}_{}",width.to_ascii_lowercase(),std::process::id());
            db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema};SET synchronous_commit=off")).await?;
            for (table,columns) in schema_reference[width].as_object().context("actual schema width missing")? {
                let definitions=columns.as_array().context("columns missing")?.iter().map(|column|format!("{} {}",column["column_name"].as_str().unwrap(),column["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");
                db.batch_execute(&format!("CREATE TABLE {table}({definitions})")).await?;
            }
            for row in reference["captures"].as_array().context("actual captures missing")?.iter().filter(|row|row["width"]==width&&row["caller"]!="raw_explicit_prefetch") {
                for (table,rows) in row["current_tables"].as_object().unwrap() {
                    db.batch_execute(&format!("DELETE FROM {table}")).await?;
                    for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await?;}
                }
                let owner=row["owner"].as_i64().unwrap();let source=row["source"].clone();
                let incoming=incoming_ap_boost_sid(&source);
                let caller=row["caller"].as_str().unwrap();
                let expects_card=!row["php"]["boost_forms"].as_array().context("actual forms missing")?.is_empty();
                let (status,facts,html)=if caller.starts_with("library") {
                    let session=TrustedSession::from_php_session(owner,row["context"]["csrf"].as_str().unwrap().into(),if caller=="library_favourites"{"favourites"}else{"bookmarks"}.into(),false)?.with_lazy_media(false);
                    let mut cards=admit_for_render(&db,&session,vec![source.clone()]).await?;
                    anyhow::ensure!(cards.len()==usize::from(expects_card),"actual Library admission disagreement");
                    let mut card=cards.remove(0);card.facts.now=chrono::DateTime::from_timestamp(row["context"]["clock"].as_i64().unwrap(),0).context("captured clock invalid")?;
                    let html=super::super::card::render_admitted(&card)?;
                    (card.status,card.facts,html)
                }else{
                    let session=crate::search_context::SearchSession::from_php_session(owner,row["context"]["csrf"].as_str().unwrap().into(),false)?;
                    let mut cards=crate::search_context::admit_for_render(&db,&session,vec![source.clone()]).await?;
                    anyhow::ensure!(cards.len()==usize::from(expects_card),"actual Search admission disagreement");
                    if cards.is_empty(){output.push(serde_json::json!({"width":width,"owner":owner,"shape":row["shape"],"state":row["state"],"caller":caller,"original_source":source,"incoming_sid":incoming,"admitted":false,"html":""}));continue;}
                    let card=cards.remove(0);let mut facts=card.facts().clone();facts.now=chrono::DateTime::from_timestamp(row["context"]["clock"].as_i64().unwrap(),0).context("captured clock invalid")?;
                    let html=super::super::card::render(card.status(),&facts)?;
                    (card.status().clone(),facts,html)
                };
                output.push(serde_json::json!({"width":width,"owner":owner,"shape":row["shape"],"state":row["state"],"caller":caller,"original_source":source,"incoming_sid":incoming,"admitted":true,"admitted_sid":incoming_ap_boost_sid(&status),"old_alias_boosted":status["reblogged"],"boosted":facts.boosted,"own_action_sid":facts.own.as_ref().map(|own|own.status_id.clone()),"html":html}));
            }
            // A separate preference-reader probe supplies the second profile;
            // the immutable card cohort above is never rewritten to add it.
            db.batch_execute("INSERT INTO actor_profile SELECT * FROM jsonb_populate_record(NULL::actor_profile,(SELECT to_jsonb(p)||jsonb_build_object('actor_key','other') FROM actor_profile p WHERE actor_key='fixture')) WHERE NOT EXISTS(SELECT 1 FROM actor_profile WHERE actor_key='other')").await?;
            for kind in ["INTEGER","BIGINT","TEXT"] {
                db.batch_execute(&format!("ALTER TABLE actor_profile ALTER COLUMN auto_unblur_sensitive TYPE {kind} USING auto_unblur_sensitive::{kind}")).await?;
                for (literal,expected) in [("'0'",false),("'1'",true),("NULL",false)] {
                    db.batch_execute(&format!("UPDATE actor_profile SET auto_unblur_sensitive={literal}")).await?;
                    for owner in [1,2] {
                        let value=crate::hidden::load_viewer_moderation(&db,owner).await?.auto_unblur_sensitive;
                        anyhow::ensure!(value==expected,"actual Library preference reader failed {kind}/{literal}/{owner}");
                        preference_checks.push(serde_json::json!({"width":width,"type":kind,"literal":literal,"owner":owner,"value":value}));
                    }
                }
                if kind=="TEXT" {
                    db.batch_execute("UPDATE actor_profile SET auto_unblur_sensitive='malformed'").await?;
                    anyhow::ensure!(crate::hidden::load_viewer_moderation(&db,1).await.is_err(),"malformed preference must fail whole native request");
                    preference_checks.push(serde_json::json!({"width":width,"type":kind,"malformed":"error"}));
                }
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await?;
        }
        std::fs::write(std::env::var("LIBRARY_SEARCH_BOOST_NATIVE_OUTPUT")?,serde_json::to_vec(&output)?)?;
        std::fs::write(format!("{}.preferences.json",std::env::var("LIBRARY_SEARCH_BOOST_NATIVE_OUTPUT")?),serde_json::to_vec(&preference_checks)?)?;
        anyhow::ensure!(output.len()==300,"expected 300 actual caller captures");
        Ok(())
    }
    #[test]
    fn malformed_synthetic_rss_cannot_fall_through_as_fediverse() {
        for value in [serde_json::json!({"id":"rss:bad"}), serde_json::json!({"id":"rss:0"}), serde_json::json!({"source":"rss"}), serde_json::json!({"vaak_rss_item_id":null})] {
            assert!(is_rss(&value)); assert_eq!(rss_id(&value), None);
        }
        assert_eq!(rss_id(&serde_json::json!({"id":"rss:11"})), Some(11));
    }
}
