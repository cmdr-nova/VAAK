//! Surface-neutral current SQL projection. Library/Search selectors retain
//! their own audience and mute policy; profile grants are never substituted.
use super::*;
type Selected=crate::library::context::SelectedLibrarySearchSource;
pub(crate) struct CanonicalProjection {
    selected:std::sync::Arc<Selected>,status:Option<Value>,ledger:ProjectionLedger,
    effects:Vec<DeferredProjectionEffect>,cache:Vec<DeferredCacheProjection>,graphs:Vec<DeferredFollowGraph>,complete:bool,
}
struct SurfaceCompletionGrant {
    selected:std::sync::Arc<Selected>,projected:Option<Value>,dependencies:std::sync::Mutex<Vec<Dependency>>,
    static_counts:Vec<StaticCountDependency>,writes:PreviewWriteJournal,
}
impl crate::profile_enrichment::CurrentEnrichmentGrant for SurfaceCompletionGrant {
    fn accept_preview_write(&self,write:&crate::profile_preview_fetch::PreviewCacheWrite)->Result<()>{
        let mut dependencies=self.dependencies.lock().map_err(|_|anyhow::anyhow!("surface projection snapshot lock poisoned"))?;
        effects::reconcile_preview_write(&mut dependencies,write)?;
        self.writes.0.lock().map_err(|_|anyhow::anyhow!("surface preview journal poisoned"))?.push(write.clone());Ok(())
    }
    fn require_current<'a>(&'a self,db:&'a Client)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{
        Box::pin(async move{
            self.selected.require_current(db).await?;if let Some(status)=&self.projected{self.selected.require_projected_current(db,status).await?;}
            let dependencies=self.dependencies.lock().map_err(|_|anyhow::anyhow!("surface projection snapshot lock poisoned"))?.clone();
            for dependency in &self.static_counts{dependency.require_current()?;}
            require_dependencies(db,&dependencies,"PHP fallback: surface canonical projection changed").await?;
            self.selected.require_current(db).await?;if let Some(status)=&self.projected{self.selected.require_projected_current(db,status).await?;}Ok(())
        })
    }
}
impl CanonicalProjection {
    pub(crate) fn status(&self)->Option<&Value>{self.status.as_ref()}
    pub(crate) fn original_source(&self)->&Value{self.selected.source()}
    pub(crate) fn original_saved_sid(&self)->&str{self.selected.original_saved_sid()}
    pub(crate) fn effects(&self)->&[DeferredProjectionEffect]{&self.effects}
    pub(crate) async fn require_current(&self,db:&Client)->Result<()>{self.selected.require_current(db).await?;if let Some(status)=&self.status{self.selected.require_projected_current(db,status).await?;}self.ledger.require_current(db).await?;self.selected.require_current(db).await?;if let Some(status)=&self.status{self.selected.require_projected_current(db,status).await?;}Ok(())}
    pub(crate) fn current_grant_with_preview_receipts(&self)->(std::sync::Arc<dyn crate::profile_enrichment::CurrentEnrichmentGrant>,PreviewWriteJournal){
        let writes=PreviewWriteJournal(Default::default());let grant=SurfaceCompletionGrant{selected:self.selected.clone(),projected:self.status.clone(),dependencies:std::sync::Mutex::new(self.ledger.dependencies.clone()),static_counts:self.ledger.static_counts.clone(),writes:writes.clone()};(std::sync::Arc::new(grant),writes)
    }
    pub(crate) fn reconcile_account_changes(&mut self,changes:&AccountMappingChanges)->Result<()>{self.ledger.reconcile_account_changes(changes)}
    pub(crate) async fn apply_effect(&mut self,db:&Client,effect:&DeferredProjectionEffect)->Result<AccountMappingChanges>{
        if !self.effects.contains(effect){bail!("PHP fallback: surface writer descriptor differs from native projection")}
        self.require_current(db).await?;let status=self.status.as_ref().context("PHP fallback: omitted surface source cannot write")?;
        let changes=effects::apply(db,&mut self.ledger.dependencies,effect,&effects::Grant::Surface(&self.selected),status).await?;
        self.require_current(db).await?;Ok(changes)
    }

    /// Retain the actual canonical root URL cache row before a painter may
    /// perform a native cache write. No caller-provided URL adds authority.
    pub(crate) async fn capture_preview_dependency(&mut self,db:&Client)->Result<()>{
        self.require_current(db).await?;
        if let Some(status)=&self.status{if let Some(url)=crate::direct_preview::extract_url(s(status,"content"))?{
            let key=json!({"url":crate::direct_preview::normalize(&url)?});let rows=Dependency::read(db,effects::PREVIEW_CACHE,&key).await?;
            self.ledger.dependencies.push(Dependency::new(effects::PREVIEW_CACHE,key,rows));
        }}self.require_current(db).await
    }
    pub(crate) async fn complete_presentation(&mut self,db:&Client,dns:&dyn crate::direct_preview::PreviewDns,graphs:&mut FollowGraphPresentation,preview:Option<&crate::profile_preview_fetch::PreviewFetchContext>)->Result<()>{
        self.require_current(db).await?;if self.complete{return Ok(())}
        let(grant,_)=self.current_grant_with_preview_receipts();
        if let Some(status)=self.status.as_mut(){
            for task in &self.graphs{for kind in ["followers","following"]{
                let(key,value,write,dependency)=graphs.load(db,kind,&task.actor,grant.as_ref()).await?;
                if let Some(dependency)=dependency{self.ledger.dependencies.push(dependency)}
                let count=graph_array_count(&value);graphs.retain(key,value,write).await;grant.require_current(db).await?;
                apply_graph_count(status,&task.actor,kind,count);
            }}
            for task in &self.cache{
                grant.require_current(db).await?;let Some(url)=crate::direct_preview::extract_url(&task.text)?else{continue};
                let key=json!({"url":crate::direct_preview::normalize(&url)?});let rows=Dependency::read(db,effects::PREVIEW_CACHE,&key).await?;grant.require_current(db).await?;
                let card=crate::direct_preview::resolve_cache_only_guarded(db,dns,&task.text,chrono::Utc::now(),Some(grant.as_ref())).await?;
                if Dependency::read(db,effects::PREVIEW_CACHE,&key).await?!=rows{bail!("PHP fallback: surface cache changed during eligibility")}
                grant.require_current(db).await?;
                if task.require_hit&&card.is_none(){let preview=preview.context("PHP fallback: surface cold link warm transport absent")?;let bridge=CompletionQuoteGrant{db,grant:grant.as_ref()};preview.warm_text(&task.text,&bridge).await?;}
                self.ledger.dependencies.push(Dependency::new(effects::PREVIEW_CACHE,key,rows));let mut node=&mut *status;
                for part in &task.path{node=node.get_mut(part).context("surface canonical cache path changed")?;}node["card"]=card.as_ref().map(masto_preview_card).unwrap_or(Value::Null);
            }
        }
        self.complete=true;self.require_current(db).await
    }
    pub(crate) fn into_parts(self)->Result<(Value,ProjectionLedger)>{if !self.complete{bail!("PHP fallback: surface canonical presentation incomplete")};Ok((self.status.context("PHP fallback: surface canonical source omitted")?,self.ledger))}
}
pub(crate) async fn project_library_search_source(db:&Client,selected:Selected)->Result<CanonicalProjection>{
    selected.require_current(db).await?;let requested=crate::library::card::php_trim(selected.object_id()).trim_end_matches('/').to_owned();
    if !requested.starts_with("https://"){bail!("PHP fallback: surface canonical source protocol unsupported")}
    let mut projector=Projector{db,owner:selected.owner_id(),dependencies:vec![],static_counts:vec![],effects:vec![],source_index:0,account_memo:Default::default(),bare_memo:Default::default(),cache:vec![],graphs:vec![],path:vec![]};
    let rows=projector.rows("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') LIMIT 1",json!({"note":requested})).await?;
    let status=if let Some(row)=rows.first(){
        let notes=projector.rows("SELECT to_jsonb(n) FROM outbox_notes n WHERE id=$1::jsonb->>'note'",json!({"note":s(row,"note_id")})).await?;
        Some(projector.local_status(row,notes.first().unwrap_or(&Value::Null),false).await?)
    }else{
        let events=projector.rows("SELECT to_jsonb(e) FROM events e WHERE object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') ORDER BY id DESC LIMIT 1",json!({"note":requested})).await?;
        let Some(row)=events.first()else{bail!("PHP fallback: surface canonical Ask/mention/remote lookup unavailable")};
        projector.event_status(row,false,"").await?.map(|mut status|{if status["reblog"].is_object(){status=status["reblog"].clone();}if let Some(map)=status.as_object_mut(){map.remove("quote");}status})
    };
    if status.as_ref().is_some_and(|status|s(status,"uri").trim_end_matches('/')!=requested){bail!("PHP fallback: surface canonical SQL identity differs from selected source")}
    let complete=projector.cache.is_empty()&&projector.graphs.is_empty();
    let product=CanonicalProjection{selected:std::sync::Arc::new(selected),status,ledger:ProjectionLedger{dependencies:projector.dependencies,static_counts:projector.static_counts},effects:projector.effects,cache:projector.cache,graphs:projector.graphs,complete};product.require_current(db).await?;Ok(product)
}
