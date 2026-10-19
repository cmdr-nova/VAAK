//! Search surface projection. Ranked Search hits reuse the same Projector
//! builders as profiles and Library (ap_masto_status_from_row/_from_event),
//! one shared request memo like PHP's. Search keeps its own admission and
//! downranking in search_collect; nothing here grants audience.
use super::*;

/// Search applies writers inline, like the PHP builders do. There is no
/// enclosing selection window to re-check, so the grant is always current.
struct SearchGrant;
impl crate::profile_enrichment::CurrentEnrichmentGrant for SearchGrant {
    fn accept_preview_write(&self,_:&crate::profile_preview_fetch::PreviewCacheWrite)->Result<()>{Ok(())}
    fn require_current<'a>(&'a self,_:&'a Client)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{Box::pin(async{Ok(())})}
}

pub(crate) enum SearchHit<'a> { Status(&'a Value), Event(&'a Value) }

/// One request-wide projector, so account and row lookups are shared across
/// hits the way PHP's request memos are.
pub(crate) struct SearchProjector<'a> { projector:Projector<'a>, graphs:FollowGraphPresentation }

impl<'a> SearchProjector<'a> {
    pub(crate) fn new(db:&'a Client,owner:i64,cfg:Option<&crate::config::Config>)->Self{
        Self{projector:Projector{db,owner,dependencies:vec![],static_counts:vec![],effects:vec![],source_index:0,account_memo:Default::default(),bare_memo:Default::default(),cache:vec![],graphs:vec![],path:vec![]},graphs:FollowGraphPresentation::new(cfg)}
    }

    /// Build one hit and run its side effects before the next hit is built,
    /// matching the inline writers in the PHP builders.
    pub(crate) async fn project(&mut self,hit:SearchHit<'_>,warm:Option<&crate::profile_enrichment::WarmContext>,dns:&dyn crate::direct_preview::PreviewDns)->Result<Option<Value>>{
        let p=&mut self.projector;p.source_index+=1;p.path.clear();
        let status=match hit{
            SearchHit::Status(row)=>{
                let outbox=p.rows("SELECT to_jsonb(n) FROM outbox_notes n WHERE id=$1::jsonb->>'note'",json!({"note":s(row,"note_id")})).await?;
                Some(p.local_status(row,outbox.first().unwrap_or(&Value::Null),true).await?)
            }
            SearchHit::Event(row)=>p.event_status(row,false,"").await?,
        };
        let Some(status)=status else{self.discard();return Ok(None)};
        self.finish(status,warm,dns).await.map(Some)
    }

    /// ap_masto_account_from_user for an ap_users row, keyed by actor_key.
    pub(crate) async fn local_account(&mut self,actor_key:&str,warm:Option<&crate::profile_enrichment::WarmContext>,dns:&dyn crate::direct_preview::PreviewDns)->Result<Value>{
        let p=&mut self.projector;p.source_index+=1;p.path.clear();
        let account=p.local_account(&format!("https://mkultra.monster/users/{actor_key}/notes/search")).await?;
        let mut wrapped=self.finish(json!({"account":account}),warm,dns).await?;Ok(wrapped["account"].take())
    }

    /// ap_masto_remote_account($actor, false).
    pub(crate) async fn remote_account(&mut self,actor:&str,warm:Option<&crate::profile_enrichment::WarmContext>,dns:&dyn crate::direct_preview::PreviewDns)->Result<Value>{
        let p=&mut self.projector;p.source_index+=1;p.path.clear();
        let account=p.remote_account(actor).await?;
        let mut wrapped=self.finish(json!({"account":account}),warm,dns).await?;Ok(wrapped["account"].take())
    }

    fn discard(&mut self){let p=&mut self.projector;p.effects.clear();p.cache.clear();p.graphs.clear();}

    async fn finish(&mut self,mut status:Value,warm:Option<&crate::profile_enrichment::WarmContext>,dns:&dyn crate::direct_preview::PreviewDns)->Result<Value>{
        let p=&mut self.projector;
        let effects=std::mem::take(&mut p.effects);let cache=std::mem::take(&mut p.cache);let graphs=std::mem::take(&mut p.graphs);
        let grant=SearchGrant;
        for effect in &effects{
            match effect.effect(){
                ProjectionEffect::RememberAccount{..}=>{
                    let mut scratch=Vec::new();effects::apply(p.db,&mut scratch,effect,&effects::Grant::Search,&status).await?;
                    // Later hits must read the mapping this writer just stored.
                    p.dependencies.retain(|d|!d.sql.contains("masto_account_actors"));
                }
                ProjectionEffect::PostMediaTouch{source_url}=>{post_media_touch(p.db,source_url).await?;p.dependencies.retain(|d|d.sql!=effects::POST_MEDIA);}
                ProjectionEffect::PostMediaWarm{source_url}=>{let warm=warm.context("PHP fallback: Search post-media transport unavailable")?;post_media::enqueue_search(p.db,warm.cfg(),source_url,warm.transport(),&grant).await?;}
                ProjectionEffect::ActorWarm(actor)=>{warm.context("PHP fallback: Search actor warm transport unavailable")?.actor_with_grant(p.db,actor,&grant).await?;}
                ProjectionEffect::MediaWarm(actor)=>{warm.context("PHP fallback: Search media warm transport unavailable")?.media_with_grant(p.db,actor,&grant).await?;}
            }
        }
        for task in &graphs{for kind in ["followers","following"]{
            let(key,value,write,_)=self.graphs.load(p.db,kind,&task.actor,&grant).await?;
            let count=graph_array_count(&value);self.graphs.retain(key,value,write).await;
            apply_graph_count(&mut status,&task.actor,kind,count);
        }}
        for task in &cache{
            let Some(_)=crate::direct_preview::extract_url(&task.text)? else{continue};
            let card=crate::direct_preview::resolve_cache_only_guarded(p.db,dns,&task.text,chrono::Utc::now(),Some(&grant)).await?;
            if task.require_hit&&card.is_none(){
                let preview=warm.and_then(|w|w.preview_context()).context("PHP fallback: Search cold link warm transport absent")?;
                let bridge=CompletionQuoteGrant{db:p.db,grant:&grant};preview.warm_text(&task.text,&bridge).await?;
            }
            let mut node=&mut status;for part in &task.path{node=node.get_mut(part).context("Search projection cache path changed")?;}
            node["card"]=card.as_ref().map(masto_preview_card).unwrap_or(Value::Null);
        }
        Ok(status)
    }
}

/// ap_remote_post_media_touch: best-effort last_used_at refresh on a cache hit.
pub(crate) async fn post_media_touch(db:&Client,source_url:&str)->Result<()>{
    let now=chrono::Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Secs,false);
    let _=db.execute("UPDATE remote_post_media_cache SET last_used_at=$1 WHERE source_url=$2",&[&now,&source_url]).await;Ok(())
}

/// ap_remote_post_media_resolve for Search-built Bluesky media: a cached URL on
/// a hit, otherwise the source URL with one background warm request queued.
pub(crate) async fn post_media_resolve(db:&Client,source_url:&str,warm:Option<&crate::profile_enrichment::WarmContext>)->Result<String>{
    let Some(url)=crate::profile_enrichment::sanitize_profile_url(source_url)? else{return Ok(String::new())};
    let cached=Dependency::read(db,effects::POST_MEDIA,&json!({"source_url":url})).await?;
    if let Some(public)=cached.first().map(|row|s(row,"public_url")).filter(|u|!u.is_empty()&&*u!="0"){
        post_media_touch(db,&url).await?;return Ok(public.to_owned())
    }
    let warm=warm.context("PHP fallback: Search post-media transport unavailable")?;
    post_media::enqueue_search(db,warm.cfg(),&url,warm.transport(),&SearchGrant).await?;Ok(url)
}
