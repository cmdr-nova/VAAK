//! Private local-profile selection-to-presentation boundary. A projection is
//! derived from the selected SQL ledger, never accepted from a browser or PHP
//! status snapshot. This stage performs no HTTP, DNS, warming or rendering.
use anyhow::{bail,Context,Result};
use serde_json::{json,Value};
use tokio_postgres::Client;
use crate::profile_context::{AuthenticatedProfileSession,GuestProfileSession,ProfilePage};

pub(crate) enum ProfileViewer<'a> { Auth(&'a AuthenticatedProfileSession), Guest(&'a GuestProfileSession) }
impl ProfileViewer<'_> {
    fn key(&self)->&str{match self{Self::Auth(v)=>v.actor_key(),Self::Guest(v)=>v.actor_key()}}
    fn target(&self)->&str{match self{Self::Auth(v)=>v.canonical_actor(),Self::Guest(v)=>v.canonical_actor()}}
    fn source_actor(&self)->&str{match self{Self::Auth(v)=>v.source_actor(),Self::Guest(v)=>v.source_actor()}}
    fn owner(&self)->i64{match self{Self::Auth(v)=>v.owner_id(),Self::Guest(_)=>0}}
}
pub(crate) enum ProfileSourceKind { Shared(Value), Raw(Value), Omitted(NativeProjectionOmission) }
pub(crate) struct NativeProjectionOmission {reason:ProjectionOmissionReason}
enum ProjectionOmissionReason {CurrentEventNormalizer}
impl NativeProjectionOmission {fn current_event()->Self{Self{reason:ProjectionOmissionReason::CurrentEventNormalizer}}}
/// These describe real projector helper calls. They have no authority to run
/// before the owning context grants the corresponding source ordinal.
#[derive(Clone,PartialEq)]
pub(crate) enum ProjectionEffect { ActorWarm(String), MediaWarm(String), RememberAccount{account_id:String,actor:String}, PostMediaTouch{source_url:String}, PostMediaWarm{source_url:String} }
#[derive(Clone,PartialEq)]
pub(crate) struct DeferredProjectionEffect {source_index:usize,effect:ProjectionEffect}
impl DeferredProjectionEffect {
    pub(crate) fn source_index(&self)->usize{self.source_index}
    pub(crate) fn effect(&self)->&ProjectionEffect{&self.effect}
}
pub(crate) struct MaterializedSource { source_index:usize, original:Value, source:ProfileSourceKind,cache_complete:bool }
impl MaterializedSource {
    pub(crate) fn source_index(&self)->usize{self.source_index}
    pub(crate) fn original(&self)->&Value{&self.original}
    pub(crate) fn source(&self)->&ProfileSourceKind{&self.source}
    pub(crate) fn into_source(self)->Result<(usize,ProfileSourceKind)>{if !self.cache_complete{bail!("PHP fallback: native source projection cache phase unfinished")};Ok((self.source_index,self.source))}
}
struct DeferredFollowGraph {source_index:usize,actor:String}
/// Request-local full-row follow-list presentation memo. Configuration comes
/// from the server assembler; these cached arrays are never audience grants.
pub(crate) struct FollowGraphPresentation {cfg:Option<crate::config::Config>,memo:std::collections::HashMap<String,Value>}
impl FollowGraphPresentation {
    pub(crate) fn new(cfg:Option<&crate::config::Config>)->Self{Self{cfg:cfg.cloned(),memo:Default::default()}}
    async fn load(&self,db:&Client,kind:&str,actor:&str,guard:&dyn crate::profile_enrichment::CurrentEnrichmentGrant)->Result<(String,Value,bool,Option<Dependency>)>{
        use sha2::{Digest,Sha256};
        let key=format!("vaak:follow-graph:v1:{kind}:{}",hex::encode(Sha256::digest(actor.trim_end_matches('/').as_bytes())));
        if let Some(value)=self.memo.get(&key){return Ok((key,value.clone(),false,None))}
        if let Some(cfg)=&self.cfg{guard.require_current(db).await?;let cached=crate::profile_header::cache::read_json(cfg,&key).await;guard.require_current(db).await?;if let Some(value)=cached.filter(|v|v.is_array()||v.is_object()){return Ok((key,value,false,None))}}
        let sql=match kind{
            "followers"=>"SELECT to_jsonb(f) FROM followers f WHERE owner_actor_id IN($1::jsonb->>'actor',($1::jsonb->>'actor')||'/') ORDER BY followed_at DESC",
            "following"=>"SELECT to_jsonb(f) FROM following f WHERE owner_actor_id IN($1::jsonb->>'actor',($1::jsonb->>'actor')||'/') ORDER BY followed_at DESC",
            _=>bail!("invalid private graph list kind")
        };
        let parameters=json!({"actor":actor.trim_end_matches('/')});guard.require_current(db).await?;let rows=Dependency::read(db,sql,&parameters).await?;guard.require_current(db).await?;
        Ok((key,Value::Array(rows.clone()),true,Some(Dependency::new(sql,parameters,rows))))
    }
    async fn retain(&mut self,key:String,value:Value,write:bool){
        if write{if let Some(cfg)=&self.cfg{
            let ttl=std::env::var("VAAK_REDIS_RELSET_TTL").ok().and_then(|v|{let v=crate::library::card::php_trim(&v);if !v.is_empty()&&v.bytes().all(|b|b.is_ascii_digit()){Some(v.parse::<u64>().unwrap_or(u64::MAX))}else{None}}).unwrap_or(300).clamp(60,900);
            crate::profile_header::cache::write_json(cfg,&key,&value,ttl).await;
        }}self.memo.insert(key,value);
    }
}
fn graph_array_count(value:&Value)->usize{match value{Value::Array(v)=>v.len(),Value::Object(v)=>v.len(),_=>0}}
fn apply_graph_count(status:&mut Value,actor:&str,kind:&str,count:usize){
    if s(&status["account"],"uri").trim_end_matches('/')==actor.trim_end_matches('/'){
        status["account"][format!("{kind}_count")]=json!(count);
    }
    if status["reblog"].is_object(){apply_graph_count(&mut status["reblog"],actor,kind,count)}
    if status["quote"]["quoted_status"].is_object(){apply_graph_count(&mut status["quote"]["quoted_status"],actor,kind,count)}
}
struct DeferredCacheProjection {source_index:usize,path:Vec<String>,text:String,require_hit:bool}
#[derive(Clone)]
struct StaticCountDependency {path:std::path::PathBuf,bytes:Option<Vec<u8>>}
impl StaticCountDependency {
    fn require_current(&self)->Result<()>{if std::fs::read(&self.path).ok()!=self.bytes{bail!("PHP fallback: static site count dependency changed before publication")}Ok(())}
}
fn static_count_integer(value:&Value)->Result<i64>{
    let value=if let Value::String(raw)=value{
        let raw=raw.trim_start_matches([' ','\t','\n','\r','\x0b','\x0c']);
        let numeric=regex::Regex::new(r"^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?").unwrap().find(raw).map(|m|m.as_str()).unwrap_or("");
        if let Ok(integer)=numeric.parse::<i64>(){return Ok(integer.max(0))}
        if numeric.is_empty(){return Ok(0)}
        let number=numeric.parse::<f64>().context("PHP fallback: static numeric count exceeds native parser")?;
        if !number.is_finite()||number>=i64::MAX as f64||number<i64::MIN as f64{bail!("PHP fallback: static numeric count exceeds native range")}
        return Ok((number as i64).max(0));
    }else{value.clone()};
    if let Value::Number(n)=&value{if n.as_i64().is_none(){let n=n.as_f64().context("static numeric count missing float")?;if !n.is_finite()||n>=i64::MAX as f64||n<i64::MIN as f64{bail!("PHP fallback: static numeric count exceeds native range")}}}
    Ok(crate::library::card::integer(&json!({"count":value}),"count").max(0))
}
fn static_count_snapshot(api_root:&std::path::Path)->Result<(i64,StaticCountDependency)>{
    let path=api_root.parent().context("server API root has no parent")?.join("assets/data/site-counts.json");
    let bytes=std::fs::read(&path).ok();
    let counts=bytes.as_ref().and_then(|b|serde_json::from_slice::<Value>(b).ok()).filter(|v|v.is_object()||v.is_array()).unwrap_or(Value::Null);
    let blog=static_count_integer(&counts["blog_posts"])?;let notes=static_count_integer(&counts["notes"])?;
    Ok((blog.checked_add(notes).context("PHP fallback: static count sum exceeds native range")?,StaticCountDependency{path,bytes}))
}
#[derive(Clone)]
struct Dependency {sql:&'static str,key:Value,rows:Vec<Value>,plans:std::sync::Arc<tokio::sync::Mutex<Vec<DependencyPlan>>>}
struct DependencyPlan {sql:String,backend:i32,schemas:Vec<String>,statement:tokio_postgres::Statement}
impl Dependency {
    fn new(sql:&'static str,key:Value,rows:Vec<Value>)->Self{Self{sql,key,rows,plans:Default::default()}}
    async fn read(db:&Client,sql:&'static str,key:&Value)->Result<Vec<Value>>{
        let rows=if sql.contains("$1"){db.query(sql,&[key]).await?}else{db.query(sql,&[]).await?};
        Ok(rows.into_iter().map(|r|r.get(0)).collect())
    }
}
/// One fresh statement per guard boundary; keys are bound values and SQL is
/// exclusively projector-owned static text. Every original full-row snapshot
/// remains required, including duplicate reads with conflicting expectations.
async fn require_dependencies(db:&Client,dependencies:&[Dependency],message:&'static str)->Result<()>{
    if dependencies.is_empty(){return Ok(())}
    let mut unique:Vec<&Dependency>=vec![];
    let mut indexes=std::collections::HashMap::<(&'static str,String),Vec<usize>>::new();
    for dependency in dependencies{
        let candidates=indexes.entry((dependency.sql,serde_json::to_string(&dependency.key)?)).or_default();
        // Key equality alone never merges conflicting before snapshots. Keep
        // exact JSON key/full ordered row comparisons for candidate duplicates.
        if !candidates.iter().any(|&index|unique[index].key==dependency.key&&unique[index].rows==dependency.rows){candidates.push(unique.len());unique.push(dependency)}
    }
    let keys=json!(unique.iter().map(|d|&d.key).collect::<Vec<_>>());
    let sql=unique.iter().enumerate().map(|(index,d)|{
        let sql=d.sql.replace("$1",&format!("($1::jsonb->{index})"));
        format!("SELECT {index}::bigint AS source_index,COALESCE(jsonb_agg(snapshot.value),'[]'::jsonb) AS rows FROM ({sql}) AS snapshot(value)")
    }).collect::<Vec<_>>().join(" UNION ALL ");
    // Plans belong to retained request snapshots, not a process/global cache.
    // Fresh connection/schema identity prevents a Statement from crossing a
    // fixture search_path or database connection boundary. Results are never
    // retained here; every guard still executes its exact bound SQL.
    let identity=db.query_one("SELECT pg_backend_pid(),current_schemas(true)",&[]).await?;
    let backend:i32=identity.get(0);let schemas:Vec<String>=identity.get(1);
    let statement={let mut plans=unique[0].plans.lock().await;
        if let Some(plan)=plans.iter().find(|p|p.sql==sql&&p.backend==backend&&p.schemas==schemas){plan.statement.clone()}
        else{let statement=db.prepare(&sql).await?;if plans.len()>=16{plans.remove(0);}plans.push(DependencyPlan{sql:sql.clone(),backend,schemas,statement:statement.clone()});statement}
    };
    let rows=if sql.contains("$1"){db.query(&statement,&[&keys]).await?}else{db.query(&statement,&[]).await?};
    if rows.len()!=unique.len(){bail!("{message}")}
    let mut seen=std::collections::HashSet::new();
    for row in rows{let index:usize=row.get::<_,i64>(0).try_into().context("native dependency index invalid")?;if index>=unique.len()||!seen.insert(index){bail!("{message}")}
        let actual:Value=row.get(1);if actual!=json!(unique[index].rows){bail!("{message}")}}
    Ok(())
}
enum CompletionAudience<'a> {
    Source(&'a crate::profile_context::AdmittedProjectionSources,usize),
    Quote(&'a crate::profile_context::AdmittedQuoteProjection,&'a str),
}
struct CompletionGrant<'a> {audience:CompletionAudience<'a>,status:&'a Value,page:Option<&'a ProfilePage>,dependencies:&'a [Dependency],static_counts:&'a [StaticCountDependency]}
impl crate::profile_enrichment::CurrentEnrichmentGrant for CompletionGrant<'_> {
    fn require_current<'a>(&'a self,db:&'a Client)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>> {
        Box::pin(async move {
            for d in self.static_counts{d.require_current()?;}
            if let Some(page)=self.page{match self.audience{CompletionAudience::Source(grant,_)=>grant.require_same_page(page)?,CompletionAudience::Quote(_,_)=>page.require_current_ledger(db).await?}}
            require_dependencies(db,self.dependencies,"PHP fallback: projection changed before enrichment dependency").await?;
            match self.audience {
                CompletionAudience::Source(grant,index)=>grant.require_current(db,index,self.status).await?,
                CompletionAudience::Quote(grant,target)=>grant.require_current(db,target,self.status).await?,
            }
            for d in self.static_counts{d.require_current()?;}
            Ok(())
        })
    }
}
struct CompletionQuoteGrant<'a>{db:&'a Client,grant:&'a dyn crate::profile_enrichment::CurrentEnrichmentGrant}
impl crate::profile_quote_warm::CurrentQuoteGrant for CompletionQuoteGrant<'_>{fn require_current(&self)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'_>>{Box::pin(self.grant.require_current(self.db))}}
enum OwnedCompletionAudience {Source(crate::profile_context::AdmittedProjectionSources,usize),Quote(crate::profile_context::AdmittedQuoteProjection,String)}
#[derive(Clone)]
pub(crate) struct PreviewWriteJournal(std::sync::Arc<std::sync::Mutex<Vec<crate::profile_preview_fetch::PreviewCacheWrite>>>);
impl PreviewWriteJournal{
    pub(crate) fn drain_changes(&self)->Result<AccountMappingChanges>{let writes=std::mem::take(&mut *self.0.lock().map_err(|_|anyhow::anyhow!("preview receipt journal poisoned"))?);Ok(effects::preview_changes(&writes))}
}
struct OwnedCompletionGrant {audience:OwnedCompletionAudience,status:Value,page:Option<ProfilePage>,dependencies:std::sync::Mutex<Vec<Dependency>>,static_counts:Vec<StaticCountDependency>,writes:PreviewWriteJournal}
impl OwnedCompletionGrant{
    fn new(audience:OwnedCompletionAudience,status:Value,page:Option<ProfilePage>,dependencies:Vec<Dependency>,static_counts:Vec<StaticCountDependency>)->(std::sync::Arc<dyn crate::profile_enrichment::CurrentEnrichmentGrant>,PreviewWriteJournal){
        let writes=PreviewWriteJournal(std::sync::Arc::new(std::sync::Mutex::new(vec![])));let grant=Self{audience,status,page,dependencies:std::sync::Mutex::new(dependencies),static_counts,writes:writes.clone()};(std::sync::Arc::new(grant),writes)
    }
}
impl crate::profile_enrichment::CurrentEnrichmentGrant for OwnedCompletionGrant {
    fn accept_preview_write(&self,write:&crate::profile_preview_fetch::PreviewCacheWrite)->Result<()>{
        let mut dependencies=self.dependencies.lock().map_err(|_|anyhow::anyhow!("preview projection snapshots poisoned"))?;
        effects::reconcile_preview_write(&mut dependencies,write)?;self.writes.0.lock().map_err(|_|anyhow::anyhow!("preview receipt journal poisoned"))?.push(write.clone());Ok(())
    }
    fn require_current<'a>(&'a self,db:&'a Client)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>> {
        Box::pin(async move {let audience=match &self.audience{OwnedCompletionAudience::Source(grant,index)=>CompletionAudience::Source(grant,*index),OwnedCompletionAudience::Quote(grant,target)=>CompletionAudience::Quote(grant,target)};
            let dependencies=self.dependencies.lock().map_err(|_|anyhow::anyhow!("preview projection snapshots poisoned"))?.clone();
            let guard=CompletionGrant{audience,status:&self.status,page:self.page.as_ref(),dependencies:&dependencies,static_counts:&self.static_counts};crate::profile_enrichment::CurrentEnrichmentGrant::require_current(&guard,db).await})
    }
}
pub(crate) struct MaterializedProfilePage {page:ProfilePage,sources:Vec<MaterializedSource>,dependencies:Vec<Dependency>,static_counts:Vec<StaticCountDependency>,target:String,effects:Vec<DeferredProjectionEffect>,cache:Vec<DeferredCacheProjection>,graphs:Vec<DeferredFollowGraph>}
impl MaterializedProfilePage {
    pub(crate) fn page(&self)->&ProfilePage{&self.page}
    pub(crate) fn sources(&self)->&[MaterializedSource]{&self.sources}
    pub(crate) fn target_actor(&self)->&str{&self.target}
    pub(crate) fn effects(&self)->&[DeferredProjectionEffect]{&self.effects}
    pub(crate) fn source_current_grant_owned(&self,grant:crate::profile_context::AdmittedProjectionSources,index:usize,status:Value)->std::sync::Arc<dyn crate::profile_enrichment::CurrentEnrichmentGrant>{self.source_current_grant_with_preview_receipts(grant,index,status).0}
    pub(crate) fn source_current_grant_with_preview_receipts(&self,grant:crate::profile_context::AdmittedProjectionSources,index:usize,status:Value)->(std::sync::Arc<dyn crate::profile_enrichment::CurrentEnrichmentGrant>,PreviewWriteJournal){
        OwnedCompletionGrant::new(OwnedCompletionAudience::Source(grant,index),status,Some(self.page.clone()),self.dependencies.clone(),self.static_counts.clone())
    }
    pub(crate) fn effect_current_grant<'a>(&'a self,status:&'a Value,grant:&'a crate::profile_context::AdmittedProjectionSources,index:usize)->impl crate::profile_enrichment::CurrentEnrichmentGrant+'a {
        CompletionGrant{audience:CompletionAudience::Source(grant,index),status,page:Some(&self.page),dependencies:&self.dependencies,static_counts:&self.static_counts}
    }
    pub(crate) async fn complete_cache(&mut self,db:&Client,dns:&dyn crate::direct_preview::PreviewDns,grant:&crate::profile_context::AdmittedProjectionSources)->Result<()>{
        let indices=self.sources.iter().map(|source|source.source_index).collect::<Vec<_>>();
        for index in indices{self.complete_source_cache(db,dns,grant,index).await?;}Ok(())
    }
    pub(crate) async fn complete_source_cache(&mut self,db:&Client,dns:&dyn crate::direct_preview::PreviewDns,grant:&crate::profile_context::AdmittedProjectionSources,source_index:usize)->Result<()>{
        self.complete_source_presentation(db,dns,grant,source_index,&mut FollowGraphPresentation::new(None)).await
    }
    pub(crate) async fn complete_source_presentation(&mut self,db:&Client,dns:&dyn crate::direct_preview::PreviewDns,grant:&crate::profile_context::AdmittedProjectionSources,source_index:usize,graphs:&mut FollowGraphPresentation)->Result<()>{
        self.complete_source_presentation_with_preview(db,dns,grant,source_index,graphs,None).await
    }
    pub(crate) async fn complete_source_presentation_with_preview(&mut self,db:&Client,dns:&dyn crate::direct_preview::PreviewDns,grant:&crate::profile_context::AdmittedProjectionSources,source_index:usize,graphs:&mut FollowGraphPresentation,preview:Option<&crate::profile_preview_fetch::PreviewFetchContext>)->Result<()>{
        self.require_current(db).await?;
        let bound_dependencies=self.dependencies.clone();let bound_static=self.static_counts.clone();
        let mut found=false;
        for source in &mut self.sources{
            if source.source_index!=source_index{continue}
            found=true;
            let ProfileSourceKind::Shared(status)=&mut source.source else{if source.cache_complete{continue}bail!("deferred preview bound to nonshared source")};
            let allowed=grant.disposition(source.source_index,status).context("PHP fallback: source cache grant differs from native ordinal/status")?;
            if source.cache_complete{continue}
            let admission_status=status.clone();
            let guard=CompletionGrant{audience:CompletionAudience::Source(grant,source.source_index),status:&admission_status,page:Some(&self.page),dependencies:&bound_dependencies,static_counts:&bound_static};
            // Validate against the exact pre-completion tree once. Filling a
            // nested presentation card must not turn a later path into a new
            // source or a new capability.
            if allowed{
                for task in self.graphs.iter().filter(|t|t.source_index==source.source_index){
                    for kind in ["followers","following"]{
                        grant.require_current(db,source.source_index,&admission_status).await?;
                        let(key,value,write,dependency)=graphs.load(db,kind,&task.actor,&guard).await?;
                        grant.require_current(db,source.source_index,&admission_status).await?;
                        if let Some(dependency)=dependency{self.dependencies.push(dependency)}
                        let count=graph_array_count(&value);graphs.retain(key,value,write).await;
                        grant.require_current(db,source.source_index,&admission_status).await?;
                        apply_graph_count(status,&task.actor,kind,count);
                    }
                }
                for task in self.cache.iter().filter(|t|t.source_index==source.source_index){
                    grant.require_current(db,source.source_index,&admission_status).await?;
                    let Some(url)=crate::direct_preview::extract_url(&task.text)?else{continue};
                    let key=crate::direct_preview::normalize(&url)?;
                    let sql="SELECT to_jsonb(c) FROM link_preview_cards c WHERE url=$1::jsonb->>'url' LIMIT 1";
                    let key=json!({"url":key});let rows=Dependency::read(db,sql,&key).await?;
                    grant.require_current(db,source.source_index,&admission_status).await?;
                    let card=crate::direct_preview::resolve_cache_only_guarded(db,dns,&task.text,chrono::Utc::now(),Some(&guard)).await?;
                    grant.require_current(db,source.source_index,&admission_status).await?;
                    if Dependency::read(db,sql,&key).await?!=rows{bail!("PHP fallback: projection cache changed during DNS eligibility")}
                    self.dependencies.push(Dependency::new(sql,key,rows));
                    if task.require_hit&&card.is_none(){let preview=preview.context("PHP fallback: cold remote link preview warm capability absent")?;let bridge=CompletionQuoteGrant{db,grant:&guard};preview.warm_text(&task.text,&bridge).await?;}
                    let mut node=&mut *status;
                    for part in &task.path{node=node.get_mut(part).context("deferred preview path lost source tree")?;}
                    node["card"]=card.as_ref().map(masto_preview_card).unwrap_or(Value::Null);
                }
            }
            // Denied sources perform no DNS/cache helper and remain denied
            // source trees for the context's final audience omission.
            source.cache_complete=true;
        }
        if !found{bail!("PHP fallback: source cache ordinal absent from native selected page")}
        self.require_current(db).await
    }
    pub(crate) async fn require_current(&self,db:&Client)->Result<()>{
        self.page.require_current_ledger(db).await?;
        for d in &self.static_counts{d.require_current()?;}
        require_dependencies(db,&self.dependencies,"PHP fallback: profile projection dependency changed before publication").await?;
        // A source can change while the dependency reads await. Recheck the
        // entire selected ledger, including every omitted final ordinal.
        for d in &self.static_counts{d.require_current()?;}
        self.page.require_current_ledger(db).await
    }
    pub(crate) fn into_parts(self)->(ProfilePage,Vec<MaterializedSource>,ProjectionLedger,Vec<DeferredProjectionEffect>){
        (self.page,self.sources,ProjectionLedger{dependencies:self.dependencies,static_counts:self.static_counts},self.effects)
    }
}
pub(crate) struct ProjectionLedger {dependencies:Vec<Dependency>,static_counts:Vec<StaticCountDependency>}
impl ProjectionLedger {
    pub(crate) async fn require_current(&self,db:&Client)->Result<()>{
        for d in &self.static_counts{d.require_current()?;}
        require_dependencies(db,&self.dependencies,"PHP fallback: profile projection dependency changed before publication").await?;
        for d in &self.static_counts{d.require_current()?;}
        Ok(())
    }
}
/// A current lookup product for a quote selected from a lawful raw root.
/// Absence is retained as SQL dependencies; it never becomes a cached grant.
pub(crate) struct ProjectedQuoteTarget {requested:String,status:Option<Value>,ledger:ProjectionLedger,effects:Vec<DeferredProjectionEffect>,cache:Vec<DeferredCacheProjection>,graphs:Vec<DeferredFollowGraph>,cache_complete:bool}
impl ProjectedQuoteTarget {
    pub(crate) fn requested_target(&self)->&str{&self.requested}
    pub(crate) fn status(&self)->Option<&Value>{self.status.as_ref()}
    pub(crate) fn effects(&self)->&[DeferredProjectionEffect]{&self.effects}
    pub(crate) async fn require_current(&self,db:&Client)->Result<()>{self.ledger.require_current(db).await}
    pub(crate) fn quote_current_grant_owned(&self,grant:crate::profile_context::AdmittedQuoteProjection,status:Value)->std::sync::Arc<dyn crate::profile_enrichment::CurrentEnrichmentGrant>{self.quote_current_grant_with_preview_receipts(grant,status).0}
    pub(crate) fn quote_current_grant_with_preview_receipts(&self,grant:crate::profile_context::AdmittedQuoteProjection,status:Value)->(std::sync::Arc<dyn crate::profile_enrichment::CurrentEnrichmentGrant>,PreviewWriteJournal){
        OwnedCompletionGrant::new(OwnedCompletionAudience::Quote(grant,self.requested.clone()),status,None,self.ledger.dependencies.clone(),self.ledger.static_counts.clone())
    }
    pub(crate) fn effect_current_grant<'a>(&'a self,status:&'a Value,grant:&'a crate::profile_context::AdmittedQuoteProjection)->impl crate::profile_enrichment::CurrentEnrichmentGrant+'a {
        CompletionGrant{audience:CompletionAudience::Quote(grant,&self.requested),status,page:None,dependencies:&self.ledger.dependencies,static_counts:&self.ledger.static_counts}
    }
    pub(crate) async fn complete_cache(&mut self,db:&Client,dns:&dyn crate::direct_preview::PreviewDns,grant:&crate::profile_context::AdmittedQuoteProjection)->Result<()>{
        self.complete_presentation(db,dns,grant,&mut FollowGraphPresentation::new(None)).await
    }
    pub(crate) async fn complete_presentation(&mut self,db:&Client,dns:&dyn crate::direct_preview::PreviewDns,grant:&crate::profile_context::AdmittedQuoteProjection,graphs:&mut FollowGraphPresentation)->Result<()>{
        self.complete_presentation_with_preview(db,dns,grant,graphs,None).await
    }
    pub(crate) async fn complete_presentation_with_preview(&mut self,db:&Client,dns:&dyn crate::direct_preview::PreviewDns,grant:&crate::profile_context::AdmittedQuoteProjection,graphs:&mut FollowGraphPresentation,preview:Option<&crate::profile_preview_fetch::PreviewFetchContext>)->Result<()>{
        let status=self.status.as_ref().context("PHP fallback: missing quote projection has no cache authority")?;
        if !grant.permits(&self.requested,status){bail!("PHP fallback: quote cache grant differs from current projected target")}
        let admission_status=status.clone();
        self.require_current(db).await?;if self.cache_complete{return Ok(())}
        let bound_dependencies=self.ledger.dependencies.clone();let bound_static=self.ledger.static_counts.clone();
        let guard=CompletionGrant{audience:CompletionAudience::Quote(grant,&self.requested),status:&admission_status,page:None,dependencies:&bound_dependencies,static_counts:&bound_static};
        if let Some(status)=self.status.as_mut(){
            for task in &self.graphs{
                for kind in ["followers","following"]{
                    grant.require_current(db,&self.requested,&admission_status).await?;
                    let(key,value,write,dependency)=graphs.load(db,kind,&task.actor,&guard).await?;
                    grant.require_current(db,&self.requested,&admission_status).await?;
                    if let Some(dependency)=dependency{self.ledger.dependencies.push(dependency)}
                    let count=graph_array_count(&value);graphs.retain(key,value,write).await;
                    grant.require_current(db,&self.requested,&admission_status).await?;
                    apply_graph_count(status,&task.actor,kind,count);
                }
            }
            for task in &self.cache{
                grant.require_current(db,&self.requested,&admission_status).await?;
                let Some(url)=crate::direct_preview::extract_url(&task.text)?else{continue};let key=json!({"url":crate::direct_preview::normalize(&url)?});
                let sql="SELECT to_jsonb(c) FROM link_preview_cards c WHERE url=$1::jsonb->>'url' LIMIT 1";
                let rows=Dependency::read(db,sql,&key).await?;grant.require_current(db,&self.requested,&admission_status).await?;
                let card=crate::direct_preview::resolve_cache_only_guarded(db,dns,&task.text,chrono::Utc::now(),Some(&guard)).await?;
                grant.require_current(db,&self.requested,&admission_status).await?;
                if Dependency::read(db,sql,&key).await?!=rows{bail!("PHP fallback: quote projection cache changed during DNS eligibility")}
                if task.require_hit&&card.is_none(){let preview=preview.context("PHP fallback: cold remote quote link preview warm capability absent")?;let bridge=CompletionQuoteGrant{db,grant:&guard};preview.warm_text(&task.text,&bridge).await?;}
                self.ledger.dependencies.push(Dependency::new(sql,key,rows));let mut node=&mut *status;for part in &task.path{node=node.get_mut(part).context("quote preview lost current source path")?;}node["card"]=card.as_ref().map(masto_preview_card).unwrap_or(Value::Null);
            }
        }
        self.cache_complete=true;self.require_current(db).await
    }
    pub(crate) fn into_parts(self)->Result<(String,Option<Value>,ProjectionLedger,Vec<DeferredProjectionEffect>)>{
        if !self.cache_complete{bail!("PHP fallback: native quote cache phase unfinished")};Ok((self.requested,self.status,self.ledger,self.effects))
    }
}
pub(crate) async fn materialize_quote_target(db:&Client,viewer:&ProfileViewer<'_>,target:&str)->Result<ProjectedQuoteTarget>{
    let requested=crate::library::card::php_trim(target).trim_end_matches('/').to_owned();
    let mut projector=Projector{db,owner:viewer.owner(),dependencies:vec![],static_counts:vec![],effects:vec![],source_index:0,account_memo:Default::default(),bare_memo:Default::default(),cache:vec![],graphs:vec![],path:vec![]};
    let mut status=None;
    if requested.starts_with("https://"){
        // Native local target branch. Remote alias/event/mention branches stay
        // explicit until their actual lookup helper comparisons are complete.
        if local_key(&requested).is_none(){bail!("PHP fallback: raw quote remote lookup candidate/event parity incomplete")}
        let locals=projector.rows("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') LIMIT 1",json!({"note":requested})).await?;
        if let Some(local)=locals.first(){
            let notes=projector.rows("SELECT to_jsonb(n) FROM outbox_notes n WHERE id=$1::jsonb->>'note'",json!({"note":s(local,"note_id")})).await?;
            status=Some(projector.local_status(local,notes.first().unwrap_or(&Value::Null),false).await?);
        }else{
            let asks=projector.rows("SELECT to_jsonb(m) FROM mentions m WHERE object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') AND deleted_at IS NULL AND ask_question<>'' ORDER BY id DESC LIMIT 1",json!({"note":requested})).await?;
            let events=projector.rows("SELECT to_jsonb(e) FROM events e WHERE object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') ORDER BY id DESC LIMIT 1",json!({"note":requested})).await?;
            let mentions=projector.rows("SELECT to_jsonb(m) FROM mentions m WHERE object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",json!({"note":requested})).await?;
            if !asks.is_empty(){bail!("PHP fallback: raw quote Ask mention projection incomplete")}
            if let Some(event)=events.first(){
                if let Some(mut projected)=projector.event_status(event,false,"").await?{
                    if projected["reblog"].is_object(){projected=projected["reblog"].clone();}
                    if let Some(map)=projected.as_object_mut(){map.remove("quote");}status=Some(projected);
                }
            }else if !mentions.is_empty(){bail!("PHP fallback: raw quote mention projection incomplete")}
        }
    }
    let cache_complete=projector.cache.is_empty()&&projector.graphs.is_empty();let result=ProjectedQuoteTarget{requested,status,ledger:ProjectionLedger{dependencies:projector.dependencies,static_counts:projector.static_counts},effects:projector.effects,cache:projector.cache,graphs:projector.graphs,cache_complete};
    result.require_current(db).await?;Ok(result)
}
struct Projector<'a>{db:&'a Client,owner:i64,dependencies:Vec<Dependency>,static_counts:Vec<StaticCountDependency>,effects:Vec<DeferredProjectionEffect>,source_index:usize,account_memo:std::collections::HashMap<String,Value>,bare_memo:std::collections::HashMap<String,Option<Value>>,cache:Vec<DeferredCacheProjection>,graphs:Vec<DeferredFollowGraph>,path:Vec<String>}
impl Projector<'_> {
    async fn rows(&mut self,sql:&'static str,key:Value)->Result<Vec<Value>>{
        // Projection is read-only until the separately admitted effect stage.
        // Keep the first exact snapshot for identical request-local lookups;
        // concurrent changes remain visible to fresh admission/final checks.
        if let Some(snapshot)=self.dependencies.iter().find(|d|d.sql==sql&&d.key==key){return Ok(snapshot.rows.clone())}
        let rows=Dependency::read(self.db,sql,&key).await?;
        self.dependencies.push(Dependency::new(sql,key,rows.clone()));Ok(rows)
    }
    fn effect(&mut self,effect:ProjectionEffect){self.effects.push(DeferredProjectionEffect{source_index:self.source_index,effect});}
}
fn s<'a>(v:&'a Value,key:&str)->&'a str{v.get(key).and_then(Value::as_str).unwrap_or("")}
fn i(v:&Value,key:&str)->i64{v.get(key).and_then(Value::as_i64).or_else(||s(v,key).parse().ok()).unwrap_or(0)}
fn truth(v:&Value)->bool{match v{Value::Null=>false,Value::Bool(b)=>*b,Value::Number(n)=>n.as_f64()!=Some(0.0),Value::String(s)=>!s.is_empty()&&s!="0",Value::Array(a)=>!a.is_empty(),Value::Object(a)=>!a.is_empty()}}
fn local_key(url:&str)->Option<String>{
    let rest=url.trim_end_matches('/').strip_prefix("https://mkultra.monster/users/")?;
    let key=rest.split('/').next()?;
    (!key.is_empty()&&key.bytes().all(|b|b.is_ascii_alphanumeric()||b==b'_')).then(||key.to_ascii_lowercase())
}
fn imported_bsky(row:&Value)->bool{
    serde_json::from_str::<Value>(s(row,"raw_create_json")).ok().is_some_and(|r|r["object"]["vaakOrigin"]=="bluesky")
}
/// Selection membership is already fixed by the SQL provider. In particular,
/// an underscore in its LIKE prefix remains a wildcard; source author identity
/// comes from this row's URL, independently of the requested target profile.
pub(crate) async fn materialize(db:&Client,viewer:&ProfileViewer<'_>,selection:crate::profile_source::SelectedProfilePage)->Result<MaterializedProfilePage>{
    if selection.actor_key()!=viewer.key()||selection.source_actor()!=viewer.source_actor(){bail!("PHP fallback: selected profile request differs from trusted target")}
    selection.page().require_current_ledger(db).await?;
    let pinned=selection.tab()=="pinned";
    let answered_asks=selection.tab()=="asks";
    let (rows,page)=selection.into_indexed_parts();
    let mut projector=Projector{db,owner:viewer.owner(),dependencies:Vec::new(),static_counts:Vec::new(),effects:Vec::new(),source_index:0,account_memo:std::collections::HashMap::new(),bare_memo:Default::default(),cache:Vec::new(),graphs:Vec::new(),path:Vec::new()};
    let mut sources=Vec::with_capacity(rows.len());
    for (source_index,row) in rows{
        projector.source_index=source_index;
        let source=if pinned{
            let note=s(&row,"note_id").trim_end_matches('/');
            local_key(note).context("selected pinned source lacks local author identity")?;
            let outbox=projector.rows("SELECT to_jsonb(n) FROM outbox_notes n WHERE id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') LIMIT 1",json!({"note":note})).await?;
            // Missing outbox still produces the actual Masto presentation.
            // Its empty dependency cannot become a local audience grant.
            ProfileSourceKind::Shared(projector.local_status(&row,outbox.first().unwrap_or(&Value::Null),true).await?)
        }else if row.get("type").is_some(){
            if s(&row,"type")!="Announce"{bail!("selected profile event is not Announce")}
            match projector.event_status(&row,true,viewer.source_actor()).await?{Some(status)=>ProfileSourceKind::Shared(status),None=>ProfileSourceKind::Omitted(NativeProjectionOmission::current_event())}
        }else{
            let id=s(&row,"id").trim_end_matches('/');
            let _author=local_key(id).context("selected profile row lacks local author identity")?;
            if answered_asks||matches!(viewer,ProfileViewer::Guest(_))||imported_bsky(&row){ProfileSourceKind::Raw(row.clone())}
            else{
                let masto=projector.rows("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') LIMIT 1",json!({"note":id})).await?;
                match masto.first(){None=>ProfileSourceKind::Raw(row.clone()),Some(masto)=>ProfileSourceKind::Shared(projector.local_status(masto,&row,true).await?)}
            }
        };
        let cache_complete=!projector.cache.iter().any(|t|t.source_index==source_index)&&!projector.graphs.iter().any(|t|t.source_index==source_index);
        sources.push(MaterializedSource{source_index,original:row,source,cache_complete});
    }
    let out=MaterializedProfilePage{page,sources,dependencies:projector.dependencies,static_counts:projector.static_counts,target:viewer.target().to_owned(),effects:projector.effects,cache:projector.cache,graphs:projector.graphs};
    out.require_current(db).await?;Ok(out)
}

fn format_time(raw:&str)->Result<String>{
    let dt=chrono::DateTime::parse_from_rfc3339(raw).or_else(|_|chrono::DateTime::parse_from_rfc3339(&raw.replacen(' ',"T",1)));
    let dt=dt.context("PHP fallback: profile time needs PHP date parser")?;
    Ok(dt.format("%Y-%m-%dT%H:%M:%S.000Z").to_string())
}
fn snowflake(raw:&str,id:i64,kind:i64)->Result<String>{
    let dt=chrono::DateTime::parse_from_rfc3339(raw).context("PHP fallback: profile snowflake date needs PHP parser")?;
    Ok((dt.timestamp()*1_000_000_000+(kind%10)*100_000_000+id%100_000_000).to_string())
}
fn strip_html(raw:&str)->String{crate::home_hydrate_ranked::html_entity_decode(&regex::Regex::new("<[^>]*>").unwrap().replace_all(raw,""))}
fn normalized_html(raw:&str)->String{
    let mut html=crate::library::card::php_trim(raw).to_owned();
    for(pattern,replacement)in [(r"(?i)</p>(?-u:\s)*<br(?-u:\s)*/?>(?-u:\s)*<p","</p><p"),(r"(?i)<br(?-u:\s)*/?>(?-u:\s)*(?:<br(?-u:\s)*/?>(?-u:\s)*)+","</p><p>"),(r"(?i)<p>(?-u:\s)*(?:<br(?-u:\s)*/?>(?-u:\s)*)*</p>",""),(r"(?i)<p>(?-u:\s)*</p>","")]{html=regex::Regex::new(pattern).unwrap().replace_all(&html,replacement).into_owned();}
    crate::library::card::php_trim(&html).to_owned()
}
fn plain_html(text:&str)->Result<String>{
    let text=text.replace("\r\n","\n").replace('\r',"\n");let text=crate::library::card::php_trim(&text);
    let url_pattern=regex::Regex::new(r#"https://[^\s<>"']+"#).unwrap();
    Ok(regex::Regex::new(r"\n{2,}").unwrap().split(text).filter_map(|p|{let p=crate::library::card::php_trim(p);if p.is_empty(){return None}let escaped=crate::library::actions::esc(p);let escaped=url_pattern.replace_all(&escaped,|c:&regex::Captures|{let url=c[0].trim_end_matches(['.',',',')',';',']','!','?','\'', '"']);let trail=&c[0][url.len()..];let href=crate::library::actions::esc(url);format!("<a href=\"{href}\" target=\"_blank\" rel=\"nofollow noopener noreferrer\">{href}</a>{trail}")});Some(format!("<p>{}</p>",escaped.replace('\n',"<br>\n")))}).collect::<Vec<_>>().join("\n"))
}
fn local_profile_defaults(key:&str)->Value{
 let primary=key=="cmdr_nova";let summary=if primary{"<p>Local ActivityPub presence for mkultra.monster.</p><p>VAAK profile: <a href=\"https://mkultra.monster/users/cmdr_nova\" rel=\"nofollow noopener noreferrer\">@cmdr_nova@mkultra.monster</a>. Site bridge handle: <code>@val3r1e@mkultra.monster</code> (Bridgy Fed).</p>"}else{"<p>On mkultra.monster.</p>"};
 let mut atts=vec![];if primary{atts.push(json!({"type":"PropertyValue","name":"Also","value":"<a href=\"https://mkultra.monster/users/cmdr_nova\" rel=\"me\">@cmdr_nova@mkultra.monster</a>"}));}atts.push(json!({"type":"PropertyValue","name":"Site","value":"<a href=\"https://mkultra.monster/\" rel=\"me\">mkultra.monster</a>"}));
 json!({"actor_key":key,"name":key,"summary":summary,"attachment_json":serde_json::to_string(&atts).unwrap(),"icon_url":null,"image_url":null,"manually_approves":false,"discoverable":true,"indexable":true,"vanity_verified":false})
}
fn profile_source_fields(profile:&Value)->Result<Vec<Value>>{
 let atts:Value=serde_json::from_str(s(profile,"attachment_json")).unwrap_or(json!([]));let mut fields=vec![];
 for att in atts.as_array().into_iter().flatten().take(8){let name=crate::library::card::php_trim(s(att,"name"));let raw=s(att,"value");if name.is_empty()||crate::library::card::php_trim(&strip_html(raw)).is_empty(){continue}
 let url=regex::Regex::new(r#"(?i)href\s*=\s*["'](https://[^"']+)["']"#).unwrap().captures(raw).map(|c|c[1].to_owned()).or_else(||raw.starts_with("https://").then(||raw.to_owned()));let plain=url.unwrap_or_else(||crate::library::card::php_trim(&crate::home_hydrate_ranked::html_entity_decode(&strip_html(raw))).to_owned());
 fields.push(json!({"name":name,"value":plain,"verified_at":att.get("verified_at").filter(|v|v.is_string()&&truth(v)).cloned().unwrap_or(Value::Null)}));}Ok(fields)
}
fn profile_fields(profile:&Value)->Result<Vec<Value>>{
    let atts:Value=serde_json::from_str(s(profile,"attachment_json")).unwrap_or(json!([]));let mut fields=Vec::new();
    for att in atts.as_array().into_iter().flatten().take(8){
        let name=crate::library::card::php_trim(s(att,"name"));let raw=s(att,"value");
        if name.is_empty()||crate::library::card::php_trim(&strip_html(raw)).is_empty(){continue}
        let url=regex::Regex::new(r#"(?i)href\s*=\s*["'](https://[^"']+)["']"#).unwrap().captures(raw).map(|c|c[1].to_owned()).or_else(||raw.starts_with("https://").then(||raw.to_owned()));
        let verified=att.get("verified_at").filter(|v|v.is_string()&&truth(v)).cloned().unwrap_or(Value::Null);
        let value=if let Some(url)=url{
            let parsed=reqwest::Url::parse(&url).context("PHP fallback: profile field URL needs PHP coercion")?;
            let mut visible=parsed.host_str().unwrap_or("").to_owned();if parsed.path()!="/"{visible.push_str(parsed.path());}if let Some(q)=parsed.query(){visible.push('?');visible.push_str(q);}
            format!("<a href=\"{}\" rel=\"{}nofollow noopener noreferrer\" target=\"_blank\"><span class=\"invisible\">https://</span><span class=\"\">{}</span><span class=\"invisible\"></span></a>",crate::library::actions::esc(&url),if verified.is_null(){""}else{"me "},crate::library::actions::esc(&visible))
        }else if raw.contains('<'){raw.into()}else{crate::library::actions::esc(raw)};
        fields.push(json!({"name":name,"value":value,"verified_at":verified}));
    }Ok(fields)
}
impl Projector<'_>{
    async fn remote_account_media(&mut self,actor:&str,override_peer:bool)->Result<(Option<String>,Option<String>)>{
        // The remote account helper tries structural avatar aliases in order.
        let mut aliases=vec![actor.to_owned()];if let Some(c)=regex::Regex::new(r"^(https://[^/]+)/@([^/?#]+)$").unwrap().captures(actor){aliases.push(format!("{}/users/{}",&c[1],&c[2]));}else if let Some(c)=regex::Regex::new(r"^(https://[^/]+)/users/([^/?#]+)$").unwrap().captures(actor){aliases.push(format!("{}/@{}",&c[1],&c[2]));}
        let mut avatar=None;let mut header=None;let mut warm_actor=actor.to_owned();
        let override_avatar:Option<String>=if override_peer&&matches!(actor,"https://waffles.baeddel.social/fediverse/blog/admin"|"https://app.wafrn.net/fediverse/blog/admin"){Some("https://mkultra.monster/img/avatar/wafrn-approvals.webp".into())}else{None};
        if let Some(value)=override_avatar{avatar=Some(value.clone());header=Some(value)}else{
            for alias in &aliases{
                let media=self.rows("SELECT to_jsonb(m) FROM remote_media_cache m WHERE actor_id=$1::jsonb->>'actor' AND kind IN('avatar','header') ORDER BY kind",json!({"actor":alias})).await?;
                for media in media{match s(&media,"kind"){"avatar" if avatar.is_none()=>{avatar=Some(s(&media,"public_url").to_owned());warm_actor=alias.clone()},"header" if header.is_none()=>{header=Some(s(&media,"public_url").to_owned());warm_actor=alias.clone()},_=>()}}
                if avatar.is_some()&&header.is_some(){break}
            }
            if avatar.is_none()||header.is_none(){
                for alias in &aliases{
                    let actors=self.rows("SELECT to_jsonb(a) FROM remote_actors a WHERE actor_id IN($1::jsonb->>'actor',($1::jsonb->>'actor')||'/') LIMIT 1",json!({"actor":alias})).await?;
                    if let Some(meta)=actors.first(){
                        if avatar.is_none()&&truth(&meta["icon_source_url"]){avatar=crate::profile_enrichment::sanitize_profile_url(s(meta,"icon_source_url"))?;warm_actor=alias.clone();}
                        if header.is_none()&&truth(&meta["image_source_url"]){header=crate::profile_enrichment::sanitize_profile_url(s(meta,"image_source_url"))?;warm_actor=alias.clone();}
                        if avatar.is_some()&&(header.is_some()||truth(&meta["icon_source_url"])){break}
                    }
                }
                if header.is_none()&&avatar.is_some(){header=avatar.clone()}
                self.effect(ProjectionEffect::MediaWarm(warm_actor));
            }
        }
        Ok((avatar,header))
    }
    async fn remote_account(&mut self,raw_actor:&str)->Result<Value>{
        let actor=crate::library::card::php_trim(raw_actor).trim_end_matches('/');
        if let Some(account)=self.account_memo.get(actor){return Ok(account.clone())}
        let rows=self.rows("SELECT to_jsonb(a) FROM remote_actors a WHERE actor_id IN($1::jsonb->>'actor',($1::jsonb->>'actor')||'/') LIMIT 1",json!({"actor":actor})).await?;
        let row=rows.first();let label=crate::profile_identity::label(actor,row);
        if row.is_none_or(|r|crate::profile_identity::placeholder(s(r,"username"))){self.effect(ProjectionEffect::ActorWarm(actor.into()));}
        let username=s(&label,"username");let host=s(&label,"host");let mut acct=s(&label,"acct").to_owned();
        let(mut avatar,mut header)=self.remote_account_media(actor,true).await?;
        let canonical=effects::aliases(self.db,&mut self.dependencies,actor).await?.first().map(|alias|alias.trim_end_matches('/').to_owned()).unwrap_or_else(||actor.into());
        if canonical!=actor{
            let(next_avatar,next_header)=self.remote_account_media(&canonical,false).await?;
            if next_avatar.as_ref().is_some_and(|u|!u.is_empty()&&u!="0"){avatar=next_avatar;}
            if next_header.as_ref().is_some_and(|u|!u.is_empty()&&u!="0"){header=next_header;}else if avatar.as_ref().is_some_and(|u|!u.is_empty()&&u!="https://mkultra.monster/img/avatar/default.webp"){header=avatar.clone();}
        }
        let avatar=crate::profile_enrichment::sanitize_profile_url(avatar.as_deref().unwrap_or(""))?.unwrap_or_else(||"https://mkultra.monster/img/avatar/default.webp".into());
        let header=crate::profile_enrichment::sanitize_profile_url(header.as_deref().unwrap_or(""))?.unwrap_or_else(||avatar.clone());

        let id=self.remote_account_id(actor).await?;
        self.effect(ProjectionEffect::RememberAccount{account_id:id.clone(),actor:canonical.clone()});
        let mut web=canonical.clone();
        if host.ends_with("brid.gy")||host=="bsky.app"{bail!("PHP fallback: remote account web URL needs protocol/alias metadata")}
        if !host.is_empty()&&!username.is_empty()&&username!="user"&&!crate::profile_identity::placeholder(username){web=format!("https://{host}/@{}",entities::encode(username))}
        if host=="bsky.app"{acct=username.into()}
        let account=json!({"id":id,"username":username,"acct":acct,"display_name":label["display_name"],"locked":false,"bot":false,"discoverable":true,"group":false,"created_at":"2020-01-01T00:00:00.000Z","note":"","url":web,"uri":canonical,"avatar":avatar,"avatar_static":avatar,"header":header,"header_static":header,"followers_count":0,"following_count":0,"statuses_count":0,"last_status_at":null,"emojis":[],"fields":[]});
        self.account_memo.insert(actor.into(),account.clone());Ok(account)
    }
    async fn event_status(&mut self,row:&Value,profile_boost:bool,profile_actor:&str)->Result<Option<Value>>{
        let actor=s(row,"actor_id");let event_id=i(row,"id");if actor.is_empty()||event_id<=0{return Ok(None)}
        if self.event_hidden(row).await?{return Ok(None)}
        let owner=self.rows("SELECT to_jsonb(u) FROM ap_users u WHERE id=($1::jsonb->>'owner')::bigint AND disabled_at IS NULL LIMIT 1",json!({"owner":self.owner})).await?;
        let owner_actor=owner.first().map(|u|s(u,"actor_id").trim_end_matches('/')).unwrap_or("");
        if !owner_actor.is_empty()&&actor.trim_end_matches('/')==owner_actor&&(!profile_boost||s(row,"type")!="Announce"||profile_actor.trim_end_matches('/')!=owner_actor){return Ok(None)}
        let account=self.remote_account(actor).await?;let object=s(row,"object_id");let url=if object.is_empty(){actor}else{object};
        let mut text=entities::strip_machine_quote_html(&crate::home_hydrate_ranked::html_entity_decode(s(row,"summary")));if text.contains('<'){text=crate::library::body::html_to_plain(&text)}
        if matches!(crate::library::card::php_trim(&text),"(attachment)"|"(media)"|"(poll)"|"(quote)"|"(boost)")&&truth(&row["media_urls"])&&s(row,"media_urls")!="[]"{text.clear()}
        if text.contains("↪"){bail!("PHP fallback: event QT source splitter unfinished")}text=entities::clean_event_text(&text);
        let spoiler=crate::library::card::php_trim(s(row,"spoiler_text"));
        let media_raw:Value=serde_json::from_str(s(row,"media_urls")).unwrap_or(Value::Null);if media_raw.is_object(){bail!("PHP fallback: event object media source order incomplete")}
        let mut media=vec![];
        let created=format_time(s(row,"created_at"))?;let sid=snowflake(&created,event_id,1)?;
        for raw in media_raw.as_array().into_iter().flatten(){let Some(raw)=raw.as_str()else{continue};let Some(clean)=crate::profile_enrichment::sanitize_profile_url(raw)?else{continue};let path=reqwest::Url::parse(&clean).ok().map(|url|url.path().to_owned()).unwrap_or_default();let ext=path.rsplit('/').next().unwrap_or("").rsplit_once('.').map(|(_,ext)|ext.to_ascii_lowercase()).unwrap_or_default();
            if !matches!(ext.as_str(),"mp4"|"webm"|"mov"|"m4v"|"m3u8"){
                let cached=self.rows(effects::POST_MEDIA,json!({"source_url":clean})).await?;
                let display=if let Some(display)=cached.first().map(|r|s(r,"public_url")).filter(|u|!u.is_empty()&&*u!="0"){
                    self.effect(ProjectionEffect::PostMediaTouch{source_url:clean.clone()});display.to_owned()
                }else{self.effect(ProjectionEffect::PostMediaWarm{source_url:clean.clone()});clean.clone()};
                media.push(json!({"id":format!("{sid}{}",media.len()+1),"type":"image","url":display,"preview_url":display,"remote_url":clean,"preview_remote_url":null,"text_url":null,"meta":null,"description":null,"blurhash":null}));if media.len()>=4{break}continue
            }
            let poster=regex::Regex::new(r"(?i)^(https://.+)/original/([^/?#]+)\.(mp4|m4v|mov|webm)([?#].*)?$").unwrap().captures(&clean).map(|c|format!("{}/small/{}.png",&c[1],&c[2]));
            media.push(json!({"id":format!("{sid}{}",media.len()+1),"type":"video","url":clean,"preview_url":poster,"remote_url":clean,"preview_remote_url":null,"text_url":null,"meta":null,"description":null,"blurhash":null}));if media.len()>=4{break}
        }
        let(reply_id,reply_account,reply_actor,reply_url)=self.event_reply_projection(s(row,"in_reply_to")).await?;
        let mut pack=if text.contains('@'){self.canonical_pack_with_actors(&text,&reply_actor.iter().cloned().collect::<Vec<_>>()).await?}else{entities::event_pack(&text)};
        if let (Some(account),Some(actor))=(&reply_account,&reply_actor){if !pack["mentions"].as_array().unwrap().iter().any(|m|s(m,"id")==account){let mention=self.mention_from_actor(actor).await?;pack["mentions"].as_array_mut().unwrap().insert(0,mention);}}
        let mut inner=json!({"id":sid,"created_at":created,"in_reply_to_id":reply_id,"in_reply_to_account_id":reply_account,"sensitive":truth(&row["sensitive"])||!spoiler.is_empty(),"spoiler_text":spoiler,"visibility":row.get("visibility").and_then(Value::as_str).unwrap_or("public"),"language":"en","uri":url,"url":url,"replies_count":0,"reblogs_count":0,"favourites_count":0,"edited_at":null,"favourited":false,"reblogged":false,"muted":false,"bookmarked":false,"pinned":false,"content":pack["content"],"reblog":null,"application":null,"account":account,"media_attachments":media,"mentions":pack["mentions"],"tags":pack["tags"],"emojis":[],"card":null,"poll":null,"quote":null,"quote_approval":{"automatic":["public"],"manual":[],"current_user":"automatic"}});
        // The actual normalizer marks Create/Update cards without visible
        // content/media as degraded. If from_event refuses the no-CW empty
        // shell it rebuilds the smaller degraded shape with raw created time.
        if !s(row,"type").eq_ignore_ascii_case("Announce")&&s(&inner,"content").is_empty()&&media.is_empty(){
            if spoiler.is_empty()&&!truth(&row["sensitive"]){
                inner=json!({"id":inner["id"],"uri":url.trim_end_matches('/'),"url":url.trim_end_matches('/'),"content":"","created_at":s(row,"created_at"),"sensitive":truth(&row["sensitive"]),"spoiler_text":s(row,"spoiler_text"),"visibility":row.get("visibility").and_then(Value::as_str).unwrap_or("public"),"account":inner["account"],"media_attachments":[],"reblog":null,"quote":null,"vaak_degraded":true,"vaak_degraded_reason":"empty_shell"});
            }else{inner["vaak_degraded"]=json!(true);inner["vaak_degraded_reason"]=json!("empty_shell");}
        }
        if !reply_url.is_empty(){inner["vaak_in_reply_to_url"]=json!(reply_url);}
        let mut ids=std::collections::HashSet::new();if let Some(mentions)=inner.get_mut("mentions").and_then(Value::as_array_mut){mentions.retain(|m|!s(m,"id").is_empty()&&ids.insert(s(m,"id").to_owned()));}
        let card_text=s(row,"summary");if media.is_empty()&&crate::direct_preview::extract_url(card_text)?.is_some(){let mut path=self.path.clone();if s(row,"type").eq_ignore_ascii_case("Announce"){path.push("reblog".into())}self.cache.push(DeferredCacheProjection{source_index:self.source_index,path,text:card_text.into(),require_hit:true});}
        if s(row,"type").eq_ignore_ascii_case("Announce"){
            let target=s(row,"target_actor").trim_end_matches('/');
            let creates=self.rows("SELECT to_jsonb(e) FROM events e WHERE type='Create' AND object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') AND COALESCE(action_taken,'')<>'deleted' ORDER BY id DESC LIMIT 1",json!({"note":object.trim_end_matches('/')})).await?;
            let original=if target.starts_with("https://"){Some(target.to_owned())}else if let Some(key)=local_key(object){Some(format!("https://mkultra.monster/users/{key}"))}else{creates.first().map(|r|s(r,"actor_id").trim_end_matches('/').to_owned()).filter(|s|!s.is_empty()).or_else(||actor_from_object(object))};
            if let Some(original)=original.filter(|s|s.trim_end_matches('/')!=actor.trim_end_matches('/')){let account_actor=s(&inner["account"],"uri").trim_end_matches('/');if account_actor.is_empty()||account_actor==actor.trim_end_matches('/'){inner["account"]=if local_key(&original).is_some(){self.local_account(&original).await?}else{self.remote_account(&original).await?};}}
            let id_creates=self.rows("SELECT to_jsonb(e) FROM events e WHERE type='Create' AND object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') ORDER BY id DESC LIMIT 1",json!({"note":object.trim_end_matches('/')})).await?;
            let inner_id=if let Some(create)=id_creates.first(){snowflake(&format_time(s(create,"created_at"))?,i(create,"id"),1)?}else if local_key(object).is_some(){let locals=self.rows("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') LIMIT 1",json!({"note":object.trim_end_matches('/')})).await?;if let Some(local)=locals.first(){snowflake(&format_time(s(local,"published"))?,i(local,"local_id"),0)?}else{snowflake(&created,crc32(object.trim_end_matches('/').as_bytes()) as i64%100_000_000,8)?}}else{snowflake(&created,crc32(object.trim_end_matches('/').as_bytes()) as i64%100_000_000,8)?};
            inner["id"]=json!(inner_id);inner["uri"]=json!(if object.is_empty(){url}else{object});inner["url"]=inner["uri"].clone();
            if (crate::library::card::php_trim(&text).is_empty()||crate::library::card::php_trim(&text).eq_ignore_ascii_case("(boost)"))&&media.is_empty()&&!truth(&inner["vaak_ask"])&&!truth(&inner["quote"]){inner["vaak_degraded"]=json!(true);inner["vaak_degraded_reason"]=json!("announce_only");inner["content"]=json!("");}
            self.interaction_flags(&mut inner).await?;
            let mut status=json!({"id":sid,"created_at":created,"in_reply_to_id":null,"in_reply_to_account_id":null,"sensitive":false,"spoiler_text":"","visibility":inner["visibility"],"language":null,"uri":format!("{url}#announce-{event_id}"),"url":url,"replies_count":0,"reblogs_count":0,"favourites_count":0,"quotes_count":0,"edited_at":null,"favourited":false,"reblogged":false,"muted":false,"bookmarked":false,"pinned":false,"content":"","reblog":inner,"application":null,"account":account,"media_attachments":[],"mentions":[],"tags":[],"emojis":[],"card":null,"poll":null,"vaak_announce_event_id":event_id});
            self.interaction_flags(&mut status).await?;Ok(Some(status))
        }else{if inner.get("in_reply_to_id").is_some(){self.interaction_flags(&mut inner).await?;}Ok(Some(inner))}
    }
    async fn event_reply_projection(&mut self,raw:&str)->Result<(Option<String>,Option<String>,Option<String>,String)>{
        let url=raw.trim_end_matches('/').to_owned();let mut id=None;let mut account=None;let mut parent_actor=None;
        if !url.is_empty()&&url.starts_with("https://"){
            let lookup=crate::library::card::php_trim(&url).trim_end_matches('/');
            let local=self.rows("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id=$1::jsonb->>'note'",json!({"note":lookup})).await?;
            let events_sql="SELECT to_jsonb(e) FROM events e WHERE object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') AND COALESCE(action_taken,'')<>'deleted' ORDER BY CASE type WHEN 'Create' THEN 0 WHEN 'Update' THEN 1 WHEN 'Announce' THEN 10 WHEN 'Like' THEN 11 ELSE 5 END ASC,id DESC LIMIT 1";
            let mut cached_event=None;
            if let Some(local)=local.first(){id=Some(snowflake(&format_time(s(local,"published"))?,i(local,"local_id"),0)?);account=Some("1".into());}
            else{
                let events=self.rows(events_sql,json!({"note":lookup})).await?;cached_event=events.first().cloned();
                if let Some(event)=events.first().filter(|r|i(r,"id")>0){id=Some(if s(event,"created_at").is_empty(){(1_000_000+i(event,"id")).to_string()}else{snowflake(&format_time(s(event,"created_at"))?,i(event,"id"),1)?});if !s(event,"actor_id").is_empty(){account=Some(self.remote_account_id(s(event,"actor_id")).await?);}}
                else{let mentions=self.rows("SELECT to_jsonb(m) FROM mentions m WHERE object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') AND deleted_at IS NULL LIMIT 1",json!({"note":lookup})).await?;
                    if let Some(mention)=mentions.first(){id=Some(if s(mention,"created_at").is_empty(){(2_000_000+i(mention,"id")).to_string()}else{snowflake(&format_time(s(mention,"created_at"))?,i(mention,"id"),2)?});if !s(mention,"actor_id").is_empty(){account=Some(self.remote_account_id(s(mention,"actor_id")).await?);}}
                }
            }
            if id.is_some(){if cached_event.is_none(){cached_event=self.rows(events_sql,json!({"note":lookup})).await?.first().cloned();}if let Some(event)=cached_event.as_ref().filter(|r|!s(r,"actor_id").is_empty()){parent_actor=Some(s(event,"actor_id").trim_end_matches('/').to_owned());}}
            if parent_actor.is_none(){parent_actor=actor_from_object(lookup);}
            if account.is_none(){if let Some(actor)=parent_actor.as_ref(){account=Some(self.remote_account_id(actor).await?);}}
            if id.is_none(){id=Some((900_000_000+crc32(url.as_bytes()) as i64%99_999_999).to_string());}
        }
        Ok((id,account,parent_actor,url))
    }
    async fn primary_public_count(&mut self,api_root:&std::path::Path)->Result<usize>{
        let (static_count,dependency)=static_count_snapshot(api_root)?;self.static_counts.push(dependency);
        let counts=self.rows("SELECT jsonb_build_object('c',COUNT(*)) FROM outbox_notes WHERE COALESCE(kind,'compose')='compose' AND COALESCE(visibility,'public')='public'",json!({})).await?;
        let compose:usize=i(counts.first().context("compose count snapshot absent")?,"c").try_into().context("PHP fallback: public compose count exceeds native range")?;
        let static_count:usize=static_count.try_into().context("PHP fallback: static count exceeds native range")?;
        static_count.checked_add(compose).context("PHP fallback: compose/static count exceeds native range")
    }
    async fn local_account(&mut self,note:&str)->Result<Value>{
        let key=local_key(note).context("local projected status has no author")?;
        let user=self.rows("SELECT to_jsonb(u) FROM ap_users u WHERE lower(actor_key)=lower($1::jsonb->>'key') AND disabled_at IS NULL LIMIT 1",json!({"key":key})).await?;
        let profile_only=user.is_empty();let empty_user=Value::Null;let user=user.first().unwrap_or(&empty_user);
        let profile=self.rows("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1::jsonb->>'key'",json!({"key":key})).await?;
        let defaults=local_profile_defaults(&key);let profile=profile.first().unwrap_or(&defaults);
        let actor=if s(user,"actor_id").is_empty(){format!("https://mkultra.monster/users/{key}")}else{s(user,"actor_id").trim_end_matches('/').to_owned()};
        let followers=self.rows("SELECT jsonb_build_object('c',COUNT(*)) FROM followers WHERE owner_actor_id IN($1::jsonb->>'actor',($1::jsonb->>'actor')||'/')",json!({"actor":actor})).await?;
        let following=self.rows("SELECT jsonb_build_object('c',COUNT(*)) FROM following WHERE owner_actor_id IN($1::jsonb->>'actor',($1::jsonb->>'actor')||'/')",json!({"actor":actor})).await?;
        let mut count=self.rows("SELECT to_jsonb(n) FROM outbox_notes n WHERE id LIKE $1::jsonb->>'prefix' ORDER BY published DESC LIMIT 200",json!({"prefix":format!("https://mkultra.monster/users/{key}/%") })).await?.len();
        if key=="cmdr_nova"{
            // Same server-owned root as the private process warm transport.
            // A browser cannot select the path or supply these counts.
            let api_root=std::env::var_os("VAAK_API_ROOT").map(std::path::PathBuf::from).unwrap_or_else(||"/srv/mkultra/html/api".into());
            count=self.primary_public_count(&api_root).await?;
        }
        let username=if user.get("username").is_some(){s(user,"username")}else{&key};
        let mut display=crate::library::card::php_trim(s(profile,"name")).to_owned();if display.is_empty(){display=key.clone()};if truth(&profile["vanity_verified"]){display.push_str(" ✓")}
        let avatar=crate::profile_enrichment::sanitize_profile_url(s(profile,"icon_url"))?.unwrap_or("https://mkultra.monster/img/avatar/local-default.webp".into());
        let header=if truth(&profile["image_url"]){s(profile,"image_url")}else{"https://mkultra.monster/img/gifs/waves.gif"};
        self.graphs.push(DeferredFollowGraph{source_index:self.source_index,actor:actor.clone()});
        let account_id=if profile_only&&key!="cmdr_nova"{let mut uid=self.owner;if uid<1{let users=self.rows("SELECT to_jsonb(u) FROM ap_users u WHERE lower(actor_key)=$1::jsonb->>'key' OR actor_id IN($1::jsonb->>'actor',($1::jsonb->>'actor')||'/') LIMIT 1",json!({"key":key,"actor":actor})).await?;uid=users.first().map(|r|i(r,"id")).unwrap_or(0)}if uid>0{uid.to_string()}else{String::new()}}else if key=="cmdr_nova"{"1".into()}else if i(user,"id")>0{i(user,"id").to_string()}else{"1".into()};
        let mut account=json!({"id":account_id,"username":username,"acct":username,"display_name":display,"locked":truth(&profile["manually_approves"]),"bot":false,"discoverable":truth(&profile["discoverable"]),"indexable":profile.get("indexable").map(truth).unwrap_or(true),"group":false,"created_at":"2018-01-01T00:00:00.000Z","note":s(profile,"summary"),"url":format!("https://vaak.monster/users/{key}"),"uri":actor,"avatar":avatar,"avatar_static":avatar,"header":header,"header_static":header,"followers_count":i(followers.first().context("follower count absent")?,"c"),"following_count":i(following.first().context("following count absent")?,"c"),"statuses_count":count,"last_status_at":null,"emojis":[],"fields":profile_fields(profile)?});
        if profile_only{account["source"]=json!({"privacy":"public","sensitive":false,"language":"en","note":strip_html(s(profile,"summary")),"fields":profile_source_fields(profile)?});}
        Ok(account)
    }
    async fn local_status(&mut self,row:&Value,outbox:&Value,attach_quote:bool)->Result<Value>{
        let note=s(row,"note_id");if note.is_empty(){bail!("PHP fallback: Mastodon source note identity missing")}
        let account=self.local_account(note).await?;let local=i(row,"local_id");let published=format_time(s(row,"published"))?;let sid=snowflake(&published,local,0)?;
        let mut text=s(row,"content_text");if matches!(text,"(quote)"|"(media)"|"(poll)"){text=""}
        let mut content=if !s(outbox,"content").is_empty()&&!crate::library::card::php_trim(&strip_html(s(outbox,"content"))).is_empty(){normalized_html(s(outbox,"content"))}else{plain_html(text)?};
        let plain_for_entities=if !text.is_empty(){text.to_owned()}else{crate::library::card::php_trim(&crate::home_hydrate_ranked::html_entity_decode(&strip_html(&content))).to_owned()};
        let mut mentions=json!([]);let mut tags=json!([]);
        if !plain_for_entities.is_empty()&&(plain_for_entities.contains('@')||plain_for_entities.contains('#')){
            if !s(outbox,"in_reply_to").is_empty()||row.get("in_reply_to_local_id").is_some_and(|v|!v.is_null()){bail!("PHP fallback: canonical reply-parent entity resolver unfinished")}
            let pack=self.canonical_pack(&plain_for_entities).await?;mentions=pack["mentions"].clone();tags=pack["tags"].clone();
            let markup=content.contains("class=\"u-url mention\"")||content.contains("class='u-url mention'")||content.contains("class=\"mention\"");
            if !markup&&!s(&pack,"content").is_empty(){content=s(&pack,"content").into()}
            else if !content.is_empty()&&content.contains('<'){let(html,found)=entities::html_tags(&content);content=html;if !found.is_empty(){tags=json!(found)}}
            else if !s(&pack,"content").is_empty(){content=s(&pack,"content").into()}
        }else if !content.is_empty()&&content.contains('#'){let(html,found)=entities::html_tags(&content);content=html;tags=json!(found)}
        let raw:Value=serde_json::from_str(s(outbox,"raw_create_json")).unwrap_or(Value::Null);
        let quote_target=if attach_quote{quote_url(&raw)}else{None};
        let asks=self.rows("SELECT to_jsonb(a) FROM ap_asks a WHERE answer_note_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') ORDER BY id DESC LIMIT 1",json!({"note":note})).await?;
        let mut reply_id=Value::Null;let mut reply_account=Value::Null;
        if row.get("in_reply_to_local_id").is_some_and(|v|!v.is_null()){
            let parents=self.rows("SELECT to_jsonb(s) FROM masto_statuses s WHERE local_id=($1::jsonb->>'id')::bigint LIMIT 1",json!({"id":i(row,"in_reply_to_local_id")})).await?;
            if let Some(parent)=parents.first(){reply_id=json!(snowflake(&format_time(s(parent,"published"))?,i(parent,"local_id"),0)?);reply_account=self.local_account(s(parent,"note_id")).await?["id"].clone();}
        }
        if reply_id.is_null(){
            let parent=s(outbox,"in_reply_to").trim_end_matches('/');
            if parent.starts_with("https://"){
                let local_parent=self.rows("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') LIMIT 1",json!({"note":parent})).await?;
                if let Some(parent)=local_parent.first(){reply_id=json!(snowflake(&format_time(s(parent,"published"))?,i(parent,"local_id"),0)?);reply_account=self.local_account(s(parent,"note_id")).await?["id"].clone();}
                else{
                    let events=self.rows("SELECT to_jsonb(e) FROM events e WHERE object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') ORDER BY id DESC",json!({"note":parent})).await?;
                    let mentions=self.rows("SELECT to_jsonb(m) FROM mentions m WHERE object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') AND deleted_at IS NULL ORDER BY id DESC",json!({"note":parent})).await?;
                    if !events.is_empty()||!mentions.is_empty(){bail!("PHP fallback: cached remote reply needs native event/mention normalization")}
                    reply_id=json!((900_000_000+crc32(parent.as_bytes()) as i64%99_999_999).to_string());
                    if let Some(actor)=actor_from_object(parent){reply_account=json!(self.remote_account_id(&actor).await?);}
                }
            }
        }
        let media=self.rows("SELECT to_jsonb(m) FROM masto_media m WHERE status_local_id=($1::jsonb->>'id')::bigint ORDER BY local_id ASC",json!({"id":local})).await?.iter().map(media_entity).collect::<Vec<_>>();
        let pins=self.rows("SELECT to_jsonb(p) FROM masto_pins p WHERE status_local_id=($1::jsonb->>'id')::bigint AND owner_user_id=($1::jsonb->>'owner')::bigint",json!({"id":local,"owner":self.owner})).await?;
        let polls=self.rows("SELECT to_jsonb(p) FROM masto_polls p WHERE status_local_id=($1::jsonb->>'id')::bigint LIMIT 1",json!({"id":local})).await?;
        let poll=if let Some(poll)=polls.first(){
            let owner=self.rows("SELECT to_jsonb(u) FROM ap_users u WHERE id=($1::jsonb->>'owner')::bigint AND disabled_at IS NULL LIMIT 1",json!({"owner":self.owner})).await?;
            let owner=owner.first().context("PHP fallback: local poll projection owner inactive")?;
            poll_entity(poll,s(owner,"actor_id"))?
        }else{Value::Null};
        if media.is_empty(){let cache_text=if !text.is_empty(){text}else{&content};if crate::direct_preview::extract_url(cache_text)?.is_some(){self.cache.push(DeferredCacheProjection{source_index:self.source_index,path:self.path.clone(),text:cache_text.into(),require_hit:false});}}
        let mut status=json!({"id":sid,"created_at":published,"in_reply_to_id":reply_id,"in_reply_to_account_id":reply_account,"sensitive":truth(&row["sensitive"]),"spoiler_text":s(row,"spoiler_text"),"visibility":if s(row,"visibility")=="local"{"unlisted"}else if row.get("visibility").is_none(){"public"}else{s(row,"visibility")},"language":"en","uri":note,"url":note,"replies_count":0,"reblogs_count":0,"favourites_count":0,"quotes_count":0,"edited_at":if truth(&row["edited_at"]){json!(format_time(s(row,"edited_at"))?)}else{Value::Null},"favourited":false,"reblogged":false,"muted":false,"bookmarked":false,"pinned":!pins.is_empty(),"content":if content.is_empty(){"<p></p>"}else{&content},"reblog":null,"application":{"name":"mkultra.monster","website":"https://mkultra.monster"},"account":account,"media_attachments":media,"mentions":mentions,"tags":tags,"emojis":[],"card":null,"poll":null,"quote_approval":{"automatic":["public"],"manual":[],"current_user":"automatic"}});
        status["poll"]=poll;
        if let Some(target)=quote_target{
            let author=s(&raw["object"],"attributedTo").trim_end_matches('/');
            let child=self.rows("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') LIMIT 1",json!({"note":target})).await?;
            let authorized=truth(&raw["object"]["quoteAuthorization"])||(!author.is_empty()&&format!("{target}/").starts_with(&format!("{author}/notes/")))||!child.is_empty();
            let quote=if !authorized{json!({"state":"pending","quoted_status":null,"quoted_status_id":null})}else if let Some(child)=child.first(){
                let notes=self.rows("SELECT to_jsonb(n) FROM outbox_notes n WHERE id=$1::jsonb->>'note'",json!({"note":s(child,"note_id")})).await?;
                let child_outbox=notes.first().context("PHP fallback: quote local Mastodon row has no durable outbox")?;
                let original_path=self.path.clone();self.path.extend(["quote".into(),"quoted_status".into()]);
                let projected=Box::pin(self.local_status(child,child_outbox,false)).await?;self.path=original_path;json!({"state":"accepted","quoted_status_id":projected["id"],"quoted_status":projected})
            }else{
                let mentions=self.rows("SELECT to_jsonb(m) FROM mentions m WHERE object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') AND deleted_at IS NULL AND ask_question<>'' ORDER BY id DESC LIMIT 1",json!({"note":target})).await?;
                if !mentions.is_empty(){bail!("PHP fallback: quote mention Ask source normalization incomplete")}
                let events=self.rows("SELECT to_jsonb(e) FROM events e WHERE object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') ORDER BY id DESC LIMIT 1",json!({"note":target})).await?;
                let projected=if let Some(event)=events.first(){
                    let owners=self.rows("SELECT to_jsonb(u) FROM ap_users u WHERE id=($1::jsonb->>'owner')::bigint AND disabled_at IS NULL LIMIT 1",json!({"owner":self.owner})).await?;
                    let suppressed=owners.first().is_some_and(|u|!s(u,"actor_id").is_empty()&&s(u,"actor_id").trim_end_matches('/')==s(event,"actor_id").trim_end_matches('/'));
                    if suppressed{None}else{Box::pin(self.event_status(event,false,"")).await?.map(|mut projected|{if projected["reblog"].is_object(){projected=projected["reblog"].clone();}if let Some(map)=projected.as_object_mut(){map.remove("quote");}projected})}
                }else{None};
                match projected{Some(projected)=>json!({"state":"accepted","quoted_status_id":projected["id"],"quoted_status":projected}),None=>json!({"state":"pending","quoted_status":null,"quoted_status_id":null})}
            };
            status["quote"]=quote;
        }
        if let Some(ask)=asks.first().filter(|a|!crate::library::card::php_trim(s(a,"question")).is_empty()){
            let meta=json!({"question":crate::library::card::php_trim(s(ask,"question")),"asker_actor":s(ask,"asker_actor"),"asker_label":"","answer":""});
            status["vaak_ask"]=meta.clone();
            if !s(&status,"content").contains("ask-container"){
                let identity=self.ask_identity_projection(meta).await?;
                let card=crate::library::card::ask_html(&identity)?;
                if !card.is_empty(){let plain=strip_html(s(&status,"content"));status["content"]=json!(if !s(&status,"content").is_empty()&&!regex::Regex::new(r"(?i)\basked\b").unwrap().is_match(&plain){format!("{card}{}",s(&status,"content"))}else{card});}
            }
        }
        self.interaction_flags(&mut status).await?;Ok(status)
    }
    async fn ask_identity_projection(&mut self,mut ask:Value)->Result<Value>{
        let actor=crate::library::card::php_trim(s(&ask,"asker_actor")).trim_end_matches('/').to_owned();
        let fallback="https://mkultra.monster/img/avatar/default.webp";
        let (display,handle,avatar)=if !actor.starts_with("https://"){
            ask["asker_actor"]=json!("");("Anonymous".into(),String::new(),fallback.into())
        }else{
            let locals=if local_key(&actor).is_some(){self.rows("SELECT to_jsonb(u) FROM ap_users u WHERE disabled_at IS NULL AND (actor_id IN($1::jsonb->>'actor',($1::jsonb->>'actor')||'/') OR lower(actor_key)=lower($1::jsonb->>'key')) LIMIT 1",json!({"actor":actor,"key":local_key(&actor)})).await?}else{Vec::new()};
            if let Some(local)=locals.first(){
                let key=s(local,"actor_key");let profiles=self.rows("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1::jsonb->>'key'",json!({"key":key})).await?;
                let profile=profiles.first().context("PHP fallback: Ask local defaults need canonical profile metadata")?;
                let name=if truth(&profile["name"]){s(profile,"name")}else if key.is_empty(){"Local user"}else{key};
                (crate::library::card::php_trim(name).to_owned(),if key.is_empty(){String::new()}else{format!("@{key}@mkultra.monster")},crate::profile_identity::local_avatar(s(profile,"icon_url")))
            }else{
                let rows=self.rows("SELECT to_jsonb(a) FROM remote_actors a WHERE actor_id IN($1::jsonb->>'actor',($1::jsonb->>'actor')||'/') LIMIT 1",json!({"actor":actor})).await?;
                let row=rows.first();if row.is_none_or(|r|crate::profile_identity::placeholder(s(r,"username"))){self.effect(ProjectionEffect::ActorWarm(actor.clone()));}
                let label=crate::profile_identity::label(&actor,row);
                let mut icon=if matches!(actor.as_str(),"https://waffles.baeddel.social/fediverse/blog/admin"|"https://app.wafrn.net/fediverse/blog/admin"){"https://mkultra.monster/img/avatar/wafrn-approvals.webp".into()}else{row.map(|r|s(r,"icon_source_url").to_owned()).unwrap_or_default()};
                if icon.is_empty(){let media=self.rows("SELECT to_jsonb(m) FROM remote_media_cache m WHERE actor_id=$1::jsonb->>'actor' AND kind='avatar' LIMIT 1",json!({"actor":actor})).await?;if let Some(media)=media.first(){icon=s(media,"public_url").into();}}
                (crate::library::card::php_trim(s(&label,"display_name")).to_owned(),s(&label,"handle").to_owned(),if icon.starts_with("https://"){icon}else{fallback.into()})
            }
        };
        ask["asker_display"]=json!(display);ask["asker_handle"]=json!(handle);ask["asker_avatar"]=json!(avatar);Ok(ask)
    }
    async fn remote_account_id(&mut self,actor:&str)->Result<String>{
        let rows=self.rows("SELECT to_jsonb(a) FROM remote_actors a WHERE actor_id IN($1::jsonb->>'actor',($1::jsonb->>'actor')||'/') LIMIT 1",json!({"actor":actor})).await?;
        let mut host=reqwest::Url::parse(actor)?.host_str().unwrap_or("").to_ascii_lowercase();let mut username=String::new();
        if let Some(row)=rows.first(){username=crate::library::card::php_trim(s(row,"username")).into();if !s(row,"host").is_empty(){host=crate::library::card::php_trim(s(row,"host")).to_ascii_lowercase();}}
        if username.is_empty(){if let Some(c)=regex::Regex::new(r"(?i)/(?:users|@)([^/]+)/?$").unwrap().captures(actor){let name=crate::hidden::raw_percent_decode(&c[1]);if !name.is_empty()&&!name.bytes().all(|b|b.is_ascii_digit()){username=name;}}}
        let key=if !username.is_empty()&&!host.is_empty()&&host!="unknown"{Some(format!("{}@{host}",username.to_lowercase()))}else{None};
        let seed=key.map(|k|format!("acct:{k}")).unwrap_or_else(||actor.into());Ok((300_000_000+crc32(seed.as_bytes()) as i64%699_999_999).to_string())
    }
    async fn interaction_flags(&mut self,status:&mut Value)->Result<()>{
        let sid=s(status,"id").to_owned();let object=s(status,"uri").to_owned();let canonical=object.trim_end_matches('/');
        // PHP's object lookup keys include aliases beyond a trailing slash.
        // The current local source has exactly its canonical note IRI; no
        // client-provided alias is accepted into this projection.
        for(table,flag)in [("masto_favourites","favourited"),("masto_bookmarks","bookmarked"),("masto_reblogs","reblogged")]{
            let sql=match table{"masto_favourites"=>"SELECT to_jsonb(f) FROM masto_favourites f WHERE owner_user_id=($1::jsonb->>'owner')::bigint AND (status_id=$1::jsonb->>'sid' OR object_id IN($1::jsonb->>'object',($1::jsonb->>'object')||'/')) ORDER BY to_jsonb(f)::text","masto_bookmarks"=>"SELECT to_jsonb(f) FROM masto_bookmarks f WHERE owner_user_id=($1::jsonb->>'owner')::bigint AND (status_id=$1::jsonb->>'sid' OR object_id IN($1::jsonb->>'object',($1::jsonb->>'object')||'/')) ORDER BY to_jsonb(f)::text",_=>"SELECT to_jsonb(f) FROM masto_reblogs f WHERE owner_user_id=($1::jsonb->>'owner')::bigint AND status_id=$1::jsonb->>'sid' ORDER BY to_jsonb(f)::text"};
            status[flag]=json!(!self.rows(sql,json!({"owner":self.owner,"sid":sid,"object":canonical})).await?.is_empty());
        }Ok(())
    }
}
fn media_entity(row:&Value)->Value{
    let mut meta=json!({});let width=i(row,"width");let height=i(row,"height");
    if s(row,"media_type")=="image"&&width!=0&&height!=0{let size=json!({"width":width,"height":height,"size":format!("{width}x{height}"),"aspect":if height>0{width as f64/height as f64}else{1.0}});meta=json!({"original":size,"small":size});}
    json!({"id":i(row,"local_id").to_string(),"type":if row.get("media_type").is_none(){"unknown"}else{s(row,"media_type")},"url":s(row,"public_url"),"preview_url":if truth(&row["preview_url"]){s(row,"preview_url")}else{s(row,"public_url")},"remote_url":null,"preview_remote_url":null,"text_url":null,"meta":meta,"description":row["description"],"blurhash":row["blurhash"]})
}
fn masto_preview_card(card:&Value)->Value{
    if !card.is_object()||card.get("status").filter(|v|!v.is_null()).is_some_and(|v|v!="ok")||s(card,"url").is_empty(){return Value::Null}
    let kind=match s(card,"type"){"link"|"photo"|"video"|"rich"=>s(card,"type"),_=>"link"};
    json!({"url":s(card,"url"),"title":s(card,"title"),"description":s(card,"description"),"type":kind,"author_name":"","author_url":"","provider_name":s(card,"provider_name"),"provider_url":s(card,"provider_url"),"html":"","width":if kind=="video"{480}else{0},"height":if kind=="video"{270}else{0},"image":card.get("image").filter(|v|v.is_string()).cloned().unwrap_or(Value::Null),"embed_url":"","blurhash":null,"language":null,"published_at":null,"authors":[]})
}
fn crc32(bytes:&[u8])->u32{let mut crc=u32::MAX;for byte in bytes{crc^=*byte as u32;for _ in 0..8{crc=(crc>>1)^if crc&1==1{0xedb88320}else{0};}}!crc}
fn actor_from_object(raw:&str)->Option<String>{
    for pattern in [r"(?i)^(https://[^/]+)/users/([^/]+)/(?:statuses|notes|objects)/",r"(?i)^(https://[^/]+)/@([^/]+)/(?:statuses/)?[A-Za-z0-9_-]+",r"(?i)^(https://[^/]+)/ap/users/([^/]+)/(?:statuses|notes)/"]{
        if let Some(c)=regex::Regex::new(pattern).unwrap().captures(raw){let name=crate::hidden::raw_percent_decode(&c[2]);let encoded: String=name.bytes().map(|b|if b.is_ascii_alphanumeric()||b"-_.~".contains(&b){(b as char).to_string()}else{format!("%{b:02X}")}).collect();let ap=if pattern.contains("/ap/"){"/ap"}else{""};return Some(format!("{}{ap}/users/{encoded}",&c[1]));}
    }None
}
fn quote_url(raw:&Value)->Option<String>{
    for key in ["quote","quoteUri","quoteUrl","_misskey_quote"]{
        let v=&raw["object"][key];let url=v.as_str().or_else(||["id","url","href"].iter().find_map(|k|v.get(k).and_then(Value::as_str)));
        if let Some(url)=url.filter(|v|v.starts_with("https://")){return Some(url.trim_end_matches('/').into())}
    }None
}
fn poll_entity(row:&Value,owner_actor:&str)->Result<Value>{
    let options:Value=serde_json::from_str(s(row,"options_json")).unwrap_or(json!([]));let options=options.as_array().into_iter().flatten().filter(|v|v.is_object()).map(|v|json!({"title":s(v,"title"),"votes_count":i(v,"votes_count")})).collect::<Vec<_>>();
    let expires=s(row,"expires_at");let expiry=if expires.is_empty(){Value::Null}else{json!(format_time(expires)?)};
    let expired=chrono::DateTime::parse_from_rfc3339(expires).ok().is_some_and(|dt|dt<=chrono::Utc::now());
    let voters:Value=serde_json::from_str(s(row,"voters_json")).unwrap_or(json!([]));let voted=voters.as_array().is_some_and(|a|a.iter().any(|v|v.as_str().is_some_and(|s|s==owner_actor||s==format!("{owner_actor}/"))));
    Ok(json!({"id":i(row,"local_id").to_string(),"expires_at":expiry,"expired":expired,"multiple":truth(&row["multiple"]),"votes_count":i(row,"votes_count"),"voters_count":i(row,"voters_count"),"voted":voted,"own_votes":if voted{json!([])}else{Value::Null},"options":options}))
}

#[path="profile_materialize_remote_source.rs"]
mod remote_source;
pub(crate) use remote_source::{RemoteApSourceSession,SelectedRemoteApPage,select_remote_ap,MaterializedRemoteApPage,MaterializedRemoteApSource,RemoteApEventBranch,materialize_remote_ap};

#[path="profile_materialize_post_media.rs"]
mod post_media;
pub(crate) use post_media::PostMediaWarmEffects;

#[path="profile_materialize_surface.rs"]
mod surface;
pub(crate) use surface::{CanonicalProjection,project_library_search_source};

#[path="profile_materialize_entities.rs"]
mod entities;

#[path="profile_materialize_moderation.rs"]
mod moderation;

#[path="profile_materialize_effects.rs"]
mod effects;
pub(crate) use effects::AccountMappingChanges;

#[cfg(test)]
mod tests {
    struct RecordedPreviewWarm{calls:std::sync::Mutex<Vec<String>>}
    impl crate::profile_preview_fetch::PreviewFetchTransport for RecordedPreviewWarm{
      fn http_get<'a>(&'a self,_:&'a str,_:u64,_:usize,_:&'a dyn crate::profile_quote_warm::CurrentQuoteGrant)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Option<String>>>+Send+'a>>{Box::pin(async{bail!("cache-only event must never fetch")})}
      fn warm<'a>(&'a self,url:&'a str,grant:&'a dyn crate::profile_quote_warm::CurrentQuoteGrant)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<bool>>+Send+'a>>{Box::pin(async move{grant.require_current().await?;self.calls.lock().unwrap().push(url.into());Ok(false)})}
    }

    use super::*;
    #[tokio::test]
    #[ignore="request-owned prepared dependency plans across fresh SQL and schema boundaries"]
    async fn actual_dependency_plan_schema_binding(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let a=format!("projection_plan_a_{}",std::process::id());let b=format!("projection_plan_b_{}",std::process::id());
        for(schema,text)in [(&a,"first"),(&b,"second")]{db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema};CREATE TABLE plan_rows(id bigint,body text);INSERT INTO plan_rows VALUES(1,'{text}')")).await.unwrap();}
        const SQL:&str="SELECT to_jsonb(r) FROM plan_rows r WHERE id=($1::jsonb->>'id')::bigint ORDER BY id LIMIT 1";
        db.batch_execute(&format!("SET search_path={a}")).await.unwrap();let first=Dependency::new(SQL,json!({"id":1}),Dependency::read(&db,SQL,&json!({"id":1})).await.unwrap());
        for _ in 0..3{require_dependencies(&db,std::slice::from_ref(&first),"withdrawn").await.unwrap();}
        assert_eq!(first.plans.lock().await.len(),1);
        db.batch_execute(&format!("SET search_path={b}")).await.unwrap();let mut second=first.clone();second.rows=Dependency::read(&db,SQL,&second.key).await.unwrap();assert_ne!(first.rows,second.rows);
        require_dependencies(&db,std::slice::from_ref(&second),"withdrawn").await.unwrap();assert_eq!(first.plans.lock().await.len(),2);
        db.batch_execute("UPDATE plan_rows SET body='withdrawn'").await.unwrap();assert!(require_dependencies(&db,std::slice::from_ref(&second),"withdrawn").await.is_err());
        db.batch_execute(&format!("SET search_path={a}")).await.unwrap();require_dependencies(&db,std::slice::from_ref(&first),"withdrawn").await.unwrap();
        let mut absent=first.clone();absent.key=json!({"id":2});absent.rows=vec![];require_dependencies(&db,std::slice::from_ref(&absent),"withdrawn").await.unwrap();assert_eq!(first.plans.lock().await.len(),2);
        let mut conflicting=first.clone();conflicting.rows[0]["body"]=json!("conflicting snapshot");
        assert!(require_dependencies(&db,&[first.clone(),conflicting],"withdrawn").await.is_err());
        require_dependencies(&db,&[first.clone(),first.clone()],"withdrawn").await.unwrap();
        db.batch_execute(&format!("DROP SCHEMA {a} CASCADE;DROP SCHEMA {b} CASCADE")).await.unwrap();
    }
    #[tokio::test]
    #[ignore = "actual cold raw PHP quote lookup projection in disposable PostgreSQL"]
    async fn actual_raw_quote_lookup_projection(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-raw-card-cold-production-media-reference.json").unwrap()).unwrap();let mut matched=0;
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_quote_projector_{}_{}",width.to_ascii_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width&&s(r,"case").starts_with("quote_")){
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let lookup=&row["php"]["effects"]["fixture_status_lookup_requests"]["value"][0];assert_eq!(lookup["depth"],0);assert_eq!(lookup["allow_fetch"],false);
                let actor=s(&row["context"],"actor");let key=s(&row["context"],"actor_key");let guest=GuestProfileSession::from_php_guest_profile(key.into(),actor.into(),false,0).unwrap();
                let product=materialize_quote_target(&db,&ProfileViewer::Guest(&guest),s(lookup,"url")).await.unwrap();product.require_current(&db).await.unwrap();assert_eq!(product.status(),lookup.get("returned_source"),"{width} {:?}",row["case"]);matched+=1;
                db.batch_execute("UPDATE masto_statuses SET content_text=COALESCE(content_text,'')||' withdrawn';UPDATE events SET summary=COALESCE(summary,'')||' withdrawn'").await.unwrap();assert!(product.require_current(&db).await.is_err());
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(matched,8);
    }
    #[tokio::test]
    #[ignore = "opt-in disposable PostgreSQL actual PHP source-to-projection oracle"]
    async fn actual_selected_projection_and_dependency_mutations(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-shared-card-genuine-lookup-reference.json").unwrap()).unwrap();
        let db=crate::db::connect(&url).await.unwrap();let mut matched=0;let mut mutations=0;
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_projection_binding_{}_{}",width.to_ascii_lowercase(),std::process::id());
            db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let definitions=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({definitions})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width){
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for value in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[value]).await.unwrap();}}
                let actor=row["caller_provenance"]["actor"].as_str().unwrap();let key=actor.rsplit('/').next().unwrap();let tab=row["caller_provenance"]["tab"].as_str().unwrap();
                let session=AuthenticatedProfileSession::from_php_session(i(&row["context"],"owner"),"fixture-csrf-token".into(),key.into(),actor.into(),false,0).unwrap();
                let selected=crate::profile_source::select(&db,key,actor,tab,10,0).await.unwrap();let original=row["caller_provenance"]["actual_append_selected"].clone();
                assert_eq!(selected.rows(),original["items"].as_array().unwrap());
                let page=materialize(&db,&ProfileViewer::Auth(&session),selected).await.unwrap();page.require_current(&db).await.unwrap();
                assert_eq!(page.page().source_count(),original["items"].as_array().unwrap().len());assert_eq!(page.page().next_offset(),original["next_offset"].as_i64().unwrap());assert_eq!(page.page().has_more(),original["has_more"].as_bool().unwrap());
                let index=row["source_index"].as_u64().unwrap() as usize;let source=page.sources().iter().find(|s|s.source_index()==index).unwrap();
                match source.source(){ProfileSourceKind::Shared(status)=>assert_eq!(status,&row["source"],"{width} {:?}",row["case"]),ProfileSourceKind::Raw(_)|ProfileSourceKind::Omitted(_)=>panic!("reference current SQL shared caller became raw/omitted")};matched+=1;
                // Full-tree dependencies and the complete original selected
                // ledger must invalidate even a presentation-only change.
                if row["case"]=="baseline_posts_owner1"{
                    db.batch_execute("UPDATE actor_profile SET summary=COALESCE(summary,'')||' changed'").await.unwrap();assert!(page.require_current(&db).await.is_err());mutations+=1;
                }else if row["case"]=="poll_posts_owner1"{
                    db.batch_execute("UPDATE masto_polls SET votes_count=COALESCE(votes_count,0)+1").await.unwrap();assert!(page.require_current(&db).await.is_err());mutations+=1;
                }else if row["case"]=="ask_current_posts_owner1"{
                    db.batch_execute("UPDATE ap_asks SET question=COALESCE(question,'')||' changed'").await.unwrap();assert!(page.require_current(&db).await.is_err());mutations+=1;
                }else if row["case"]=="quote_child_cw_posts_owner1"{
                    db.batch_execute("UPDATE masto_statuses SET spoiler_text=COALESCE(spoiler_text,'')||' changed'").await.unwrap();assert!(page.require_current(&db).await.is_err());mutations+=1;
                }else if row["case"]=="baseline_media_owner1"{
                    let last=page.sources().last().unwrap().original()["id"].as_str().unwrap();db.execute("UPDATE outbox_notes SET content=COALESCE(content,'')||' changed' WHERE id=$1",&[&last]).await.unwrap();assert!(page.require_current(&db).await.is_err());mutations+=1;
                }
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }
        assert_eq!(matched,52);assert_eq!(mutations,10);println!("52 exact current SQL projections and ten source/dependency invalidations passed");
    }
    #[test]
    #[ignore = "actual PHP static-count helper fixture"]
    fn actual_static_counts_and_file_withdrawal(){
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-static-count-reference.json").unwrap()).unwrap();
        let root=std::env::temp_dir().join(format!("profile_static_counts_native_{}",std::process::id()));std::fs::create_dir_all(root.join("assets/data")).unwrap();
        let path=root.join("assets/data/site-counts.json");
        for row in reference["captures"].as_array().unwrap(){
            let _=std::fs::remove_file(&path);if let Some(bytes)=row["bytes"].as_str(){std::fs::write(&path,bytes).unwrap();}
            let (count,dependency)=static_count_snapshot(&root.join("api")).unwrap();
            assert_eq!(count,i(&row["counts"],"blog_posts")+i(&row["counts"],"notes"),"{:?}",row["case"]);dependency.require_current().unwrap();
            std::fs::write(&path,"withdrawn").unwrap();assert!(dependency.require_current().is_err());
        }
        std::fs::remove_dir_all(root).unwrap();println!("13 actual PHP static count helpers and file-byte invalidations passed");
    }
    #[test]
    #[ignore = "actual PHP pure projection helper fixture"]
    fn actual_projection_pure_helpers(){
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-projection-helper-reference.json").unwrap()).unwrap();
        for row in reference["captures"].as_array().unwrap(){let output=match s(row,"helper"){"plain"=>json!(plain_html(s(row,"input")).unwrap()),"normal"=>json!(normalized_html(s(row,"input"))),"card"=>masto_preview_card(&row["input"]),_=>panic!("unknown pure helper")};assert_eq!(output,row["expected"],"{row}");}
    }
    #[tokio::test]
    #[ignore = "actual PHP compose count in disposable local PostgreSQL"]
    async fn actual_primary_compose_count_dependency(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-static-count-reference.json").unwrap()).unwrap();
        let schema=format!("profile_primary_count_native_{}",std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema};CREATE TABLE outbox_notes(id TEXT,kind TEXT,visibility TEXT)")).await.unwrap();
        let case=&reference["compose"][0];assert!(case.is_object(),"capture compose PHP helper with local PostgreSQL driver");
        for row in case["rows"].as_array().unwrap(){db.execute("INSERT INTO outbox_notes SELECT * FROM jsonb_populate_record(NULL::outbox_notes,$1)",&[row]).await.unwrap();}
        let root=std::env::temp_dir().join(format!("profile_primary_count_native_{}",std::process::id()));std::fs::create_dir_all(root.join("assets/data")).unwrap();std::fs::write(root.join("assets/data/site-counts.json"),"{\"blog_posts\":7,\"notes\":9}").unwrap();
        let mut projector=Projector{db:&db,owner:1,dependencies:vec![],static_counts:vec![],effects:vec![],source_index:0,account_memo:Default::default(),bare_memo:Default::default(),cache:vec![],graphs:vec![],path:vec![]};
        assert_eq!(projector.primary_public_count(&root.join("api")).await.unwrap(),16+case["count"].as_u64().unwrap() as usize);
        let ledger=ProjectionLedger{dependencies:projector.dependencies,static_counts:projector.static_counts};ledger.require_current(&db).await.unwrap();
        db.batch_execute("UPDATE outbox_notes SET visibility='private' WHERE id='owner-two'").await.unwrap();assert!(ledger.require_current(&db).await.is_err());
        std::fs::remove_dir_all(root).unwrap();db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
    }
    #[tokio::test]
    #[ignore="actual canonical selected local hashtag status projection in disposable SQL"]
    async fn actual_canonical_local_hashtags(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-canonical-content-reference.json").unwrap()).unwrap();let mut keys=std::collections::HashSet::new();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_canonical_hashtags_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width&&s(r,"case").starts_with("tag")&&i(r,"owner")>0){
                assert!(keys.insert((width.to_owned(),s(row,"case").to_owned())));
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let actor=s(row,"actor");let session=AuthenticatedProfileSession::from_php_session(i(row,"owner"),"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap();let selected=crate::profile_source::select(&db,"fixture",actor,"posts",10,0).await.unwrap();assert_eq!(json!(selected.rows()),row["page"]["items"]);
                let page=materialize(&db,&ProfileViewer::Auth(&session),selected).await.unwrap();let index=i(row,"source_index") as usize;let source=page.sources().iter().find(|s|s.source_index()==index).unwrap();let ProfileSourceKind::Shared(status)=source.source()else{panic!("reachable actual local canonical projection must remain shared")};assert_eq!(status,&row["source"],"{width} {}",row["case"]);
                page.require_current(&db).await.unwrap();db.batch_execute("UPDATE masto_statuses SET content_text=COALESCE(content_text,'')||' withdrawn'").await.unwrap();assert!(page.require_current(&db).await.is_err());
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(keys.len(),24);
    }
    #[tokio::test]
    #[ignore="actual canonical selected local hashtag status projection in disposable SQL"]
    async fn actual_canonical_local_mentions(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-canonical-content-reference.json").unwrap()).unwrap();let mut keys=std::collections::HashSet::new();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_canonical_mentions_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width&&!s(r,"case").starts_with("tag")&&i(r,"owner")>0){
                assert!(keys.insert((width.to_owned(),s(row,"case").to_owned())));
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let actor=s(row,"actor");let session=AuthenticatedProfileSession::from_php_session(i(row,"owner"),"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap();let selected=crate::profile_source::select(&db,"fixture",actor,"posts",10,0).await.unwrap();assert_eq!(json!(selected.rows()),row["page"]["items"]);
                let page=materialize(&db,&ProfileViewer::Auth(&session),selected).await.unwrap();let index=i(row,"source_index") as usize;let source=page.sources().iter().find(|s|s.source_index()==index).unwrap();let ProfileSourceKind::Shared(status)=source.source()else{panic!("reachable actual local canonical projection must remain shared")};assert_eq!(status,&row["source"],"{width} {}",row["case"]);
                page.require_current(&db).await.unwrap();db.batch_execute("UPDATE masto_statuses SET content_text=COALESCE(content_text,'')||' withdrawn'").await.unwrap();assert!(page.require_current(&db).await.is_err());
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(keys.len(),24);
    }
    #[tokio::test]
    #[ignore="actual PHP local account missing profile/user default projection in disposable SQL"]
    async fn actual_local_account_missing_defaults(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-local-account-default-reference.json").unwrap()).unwrap();let mut keys=std::collections::HashSet::new();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_account_defaults_native_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width){
                assert!(keys.insert((width.to_owned(),s(row,"case").to_owned())));
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let actor=s(row,"actor");let owner=i(row,"owner");let guest=GuestProfileSession::from_php_guest_profile("fixture".into(),actor.into(),false,0).unwrap();let auth=if owner>0{Some(AuthenticatedProfileSession::from_php_session(owner,"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap())}else{None};let viewer=auth.as_ref().map(ProfileViewer::Auth).unwrap_or(ProfileViewer::Guest(&guest));let selected=crate::profile_source::select(&db,"fixture",actor,"posts",10,0).await.unwrap();assert_eq!(json!(selected.rows()),row["page"]["items"]);
                if owner==0{let note=s(&row["page"]["items"][i(row,"source_index") as usize],"id");let quote=materialize_quote_target(&db,&viewer,note).await.unwrap();assert_eq!(quote.status().unwrap(),&row["source"],"actual guest current quote projection {}",row["case"]);quote.require_current(&db).await.unwrap();db.batch_execute("UPDATE masto_statuses SET content_text=COALESCE(content_text,'')||' withdrawn'").await.unwrap();assert!(quote.require_current(&db).await.is_err());continue}
                let page=materialize(&db,&viewer,selected).await.unwrap();let index=i(row,"source_index") as usize;let source=page.sources().iter().find(|s|s.source_index()==index).unwrap();let ProfileSourceKind::Shared(status)=source.source()else{panic!("reachable actual local canonical projection must remain shared")};assert_eq!(status,&row["source"],"{width} {}",row["case"]);
                page.require_current(&db).await.unwrap();db.batch_execute("UPDATE masto_statuses SET content_text=COALESCE(content_text,'')||' withdrawn'").await.unwrap();assert!(page.require_current(&db).await.is_err());
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(keys.len(),24);
    }
    #[tokio::test]
    #[ignore="actual canonical selected local hashtag status projection in disposable SQL"]
    async fn actual_canonical_local_mentions_preprocessing(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-canonical-content-extended-reference.json").unwrap()).unwrap();let mut keys=std::collections::HashSet::new();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_canonical_mentions_extended_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width&&!s(r,"case").starts_with("tag")&&i(r,"owner")>0){
                assert!(keys.insert((width.to_owned(),s(row,"case").to_owned())));
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let actor=s(row,"actor");let session=AuthenticatedProfileSession::from_php_session(i(row,"owner"),"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap();let selected=crate::profile_source::select(&db,"fixture",actor,"posts",10,0).await.unwrap();assert_eq!(json!(selected.rows()),row["page"]["items"]);
                let page=materialize(&db,&ProfileViewer::Auth(&session),selected).await.unwrap();let index=i(row,"source_index") as usize;let source=page.sources().iter().find(|s|s.source_index()==index).unwrap();let ProfileSourceKind::Shared(status)=source.source()else{panic!("reachable actual local canonical projection must remain shared")};assert_eq!(status,&row["source"],"{width} {}",row["case"]);
                page.require_current(&db).await.unwrap();db.batch_execute("UPDATE masto_statuses SET content_text=COALESCE(content_text,'')||' withdrawn'").await.unwrap();assert!(page.require_current(&db).await.is_err());
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(keys.len(),48);
    }
    #[tokio::test]
    #[ignore="actual selected event normalizer moderation/null effects in disposable SQL"]
    async fn actual_event_normalizer_omissions(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-event-moderation-reference.json").unwrap()).unwrap();let mut matched=0;let mut omissions=0;let mut keys=std::collections::HashSet::new();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_event_moderation_native_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width){
                assert!(keys.insert((width.to_owned(),s(row,"case").to_owned())));
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let actor=s(row,"actor");let owner=i(row,"owner");let guest=GuestProfileSession::from_php_guest_profile("fixture".into(),actor.into(),false,0).unwrap();let auth=if owner>0{Some(AuthenticatedProfileSession::from_php_session(owner,"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap())}else{None};let viewer=auth.as_ref().map(ProfileViewer::Auth).unwrap_or(ProfileViewer::Guest(&guest));
                let selected=crate::profile_source::select(&db,"fixture",actor,"boosts",10,0).await.unwrap();assert_eq!(json!(selected.rows()),row["page"]["items"]);let page=materialize(&db,&viewer,selected).await.unwrap();assert_eq!(page.page().source_count(),1);assert_eq!(page.page().next_offset(),i(&row["page"],"next_offset"));assert_eq!(page.page().has_more(),row["page"]["has_more"].as_bool().unwrap());assert_eq!(page.sources()[0].source_index(),0);
                if row["statuses"][0].is_null(){assert!(matches!(page.sources()[0].source(),ProfileSourceKind::Omitted(_)),"{width} {}",row["case"]);assert!(page.effects().is_empty());assert!(page.cache.is_empty());assert!(page.graphs.is_empty());for effects in row["effects"].as_object().unwrap().values(){assert!(effects.as_array().unwrap().is_empty())}omissions+=1;
                }else{let ProfileSourceKind::Shared(status)=page.sources()[0].source()else{panic!("expected actual current normalizer status")};assert_eq!(status,&row["statuses"][0],"{width} {}",row["case"]);}
                page.require_current(&db).await.unwrap();db.batch_execute("UPDATE events SET summary=COALESCE(summary,'')||' withdrawn' WHERE type='Announce'").await.unwrap();assert!(page.require_current(&db).await.is_err());matched+=1;
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(matched,60);assert_eq!(keys.len(),60);assert_eq!(omissions,36);
    }
    #[tokio::test]
    #[ignore="actual selected event normalizer moderation/null effects in disposable SQL"]
    async fn actual_event_canonical_entities(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-event-entities-reference.json").unwrap()).unwrap();let mut matched=0;let mut keys=std::collections::HashSet::new();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_event_entities_native_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width){
                assert!(keys.insert((width.to_owned(),s(row,"case").to_owned())));
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let actor=s(row,"actor");let owner=i(row,"owner");let guest=GuestProfileSession::from_php_guest_profile("fixture".into(),actor.into(),false,0).unwrap();let auth=if owner>0{Some(AuthenticatedProfileSession::from_php_session(owner,"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap())}else{None};let viewer=auth.as_ref().map(ProfileViewer::Auth).unwrap_or(ProfileViewer::Guest(&guest));
                let selected=crate::profile_source::select(&db,"fixture",actor,"boosts",10,0).await.unwrap();assert_eq!(json!(selected.rows()),row["page"]["items"]);let mut page=materialize(&db,&viewer,selected).await.unwrap();assert_eq!(page.page().source_count(),1);assert_eq!(page.page().next_offset(),i(&row["page"],"next_offset"));assert_eq!(page.page().has_more(),row["page"]["has_more"].as_bool().unwrap());assert_eq!(page.sources()[0].source_index(),0);
                struct PublicDns;impl crate::direct_preview::PreviewDns for PublicDns{fn ipv4<'a>(&'a self,_:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<std::net::Ipv4Addr>>>+Send+'a>>{Box::pin(async{Ok(vec![std::net::Ipv4Addr::new(8,8,8,8)])})}}
                let grant=crate::profile_context::admit_projection_sources(&db,&viewer,&page).await.unwrap();let transport=std::sync::Arc::new(RecordedPreviewWarm{calls:Default::default()});let preview=crate::profile_preview_fetch::PreviewFetchContext::new(transport.clone());let mut graphs=FollowGraphPresentation::new(None);for index in page.sources().iter().map(|s|s.source_index()).collect::<Vec<_>>(){page.complete_source_presentation_with_preview(&db,&PublicDns,&grant,index,&mut graphs,Some(&preview)).await.unwrap();}
                let ProfileSourceKind::Shared(status)=page.sources()[0].source()else{panic!("expected actual current canonical event status")};assert_eq!(status,&row["statuses"][0],"{width} {}",row["case"]);
                page.require_current(&db).await.unwrap();db.batch_execute("UPDATE events SET summary=COALESCE(summary,'')||' withdrawn' WHERE type='Announce'").await.unwrap();assert!(page.require_current(&db).await.is_err());matched+=1;
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(matched,72);assert_eq!(keys.len(),72);
    }
    #[tokio::test]
    #[ignore="actual selected event normalizer moderation/null effects in disposable SQL"]
    async fn actual_event_video_media(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-event-video-reference.json").unwrap()).unwrap();let mut matched=0;let mut keys=std::collections::HashSet::new();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_event_video_native_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width){
                assert!(keys.insert((width.to_owned(),s(row,"case").to_owned())));
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let actor=s(row,"actor");let owner=i(row,"owner");let guest=GuestProfileSession::from_php_guest_profile("fixture".into(),actor.into(),false,0).unwrap();let auth=if owner>0{Some(AuthenticatedProfileSession::from_php_session(owner,"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap())}else{None};let viewer=auth.as_ref().map(ProfileViewer::Auth).unwrap_or(ProfileViewer::Guest(&guest));
                let selected=crate::profile_source::select(&db,"fixture",actor,"boosts",10,0).await.unwrap();assert_eq!(json!(selected.rows()),row["page"]["items"]);let mut page=materialize(&db,&viewer,selected).await.unwrap();assert_eq!(page.page().source_count(),1);assert_eq!(page.page().next_offset(),i(&row["page"],"next_offset"));assert_eq!(page.page().has_more(),row["page"]["has_more"].as_bool().unwrap());assert_eq!(page.sources()[0].source_index(),0);
                struct PublicDns;impl crate::direct_preview::PreviewDns for PublicDns{fn ipv4<'a>(&'a self,_:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<std::net::Ipv4Addr>>>+Send+'a>>{Box::pin(async{Ok(vec![std::net::Ipv4Addr::new(8,8,8,8)])})}}
                let grant=crate::profile_context::admit_projection_sources(&db,&viewer,&page).await.unwrap();let transport=std::sync::Arc::new(RecordedPreviewWarm{calls:Default::default()});let preview=crate::profile_preview_fetch::PreviewFetchContext::new(transport.clone());let mut graphs=FollowGraphPresentation::new(None);for index in page.sources().iter().map(|s|s.source_index()).collect::<Vec<_>>(){page.complete_source_presentation_with_preview(&db,&PublicDns,&grant,index,&mut graphs,Some(&preview)).await.unwrap();}
                let ProfileSourceKind::Shared(status)=page.sources()[0].source()else{panic!("expected actual current canonical event status")};assert_eq!(status,&row["statuses"][0],"{width} {}",row["case"]);
                page.require_current(&db).await.unwrap();db.batch_execute("UPDATE events SET summary=COALESCE(summary,'')||' withdrawn' WHERE type='Announce'").await.unwrap();assert!(page.require_current(&db).await.is_err());matched+=1;
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(matched,108);assert_eq!(keys.len(),108);
    }
    #[tokio::test]
    #[ignore="actual selected Announce current-parent and synthetic reply projection in disposable SQL"]
    async fn actual_event_reply_projection(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-event-reply-reference.json").unwrap()).unwrap();let mut matched=0;let mut keys=std::collections::HashSet::new();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_event_reply_native_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width){
                assert!(keys.insert((width.to_owned(),s(row,"case").to_owned())));
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let actor=s(row,"actor");let owner=i(row,"owner");let guest=GuestProfileSession::from_php_guest_profile("fixture".into(),actor.into(),false,0).unwrap();let auth=if owner>0{Some(AuthenticatedProfileSession::from_php_session(owner,"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap())}else{None};let viewer=auth.as_ref().map(ProfileViewer::Auth).unwrap_or(ProfileViewer::Guest(&guest));
                let selected=crate::profile_source::select(&db,"fixture",actor,"boosts",10,0).await.unwrap();assert_eq!(json!(selected.rows()),row["page"]["items"]);let mut page=materialize(&db,&viewer,selected).await.unwrap();assert_eq!(page.page().source_count(),1);assert_eq!(page.page().next_offset(),i(&row["page"],"next_offset"));assert_eq!(page.page().has_more(),row["page"]["has_more"].as_bool().unwrap());assert_eq!(page.sources()[0].source_index(),0);
                struct PublicDns;impl crate::direct_preview::PreviewDns for PublicDns{fn ipv4<'a>(&'a self,_:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<std::net::Ipv4Addr>>>+Send+'a>>{Box::pin(async{Ok(vec![std::net::Ipv4Addr::new(8,8,8,8)])})}}
                let grant=crate::profile_context::admit_projection_sources(&db,&viewer,&page).await.unwrap();let transport=std::sync::Arc::new(RecordedPreviewWarm{calls:Default::default()});let preview=crate::profile_preview_fetch::PreviewFetchContext::new(transport.clone());let mut graphs=FollowGraphPresentation::new(None);for index in page.sources().iter().map(|s|s.source_index()).collect::<Vec<_>>(){page.complete_source_presentation_with_preview(&db,&PublicDns,&grant,index,&mut graphs,Some(&preview)).await.unwrap();}
                let ProfileSourceKind::Shared(status)=page.sources()[0].source()else{panic!("expected actual current canonical event status")};assert_eq!(status,&row["statuses"][0],"{width} {}",row["case"]);
                page.require_current(&db).await.unwrap();db.batch_execute("UPDATE events SET summary=COALESCE(summary,'')||' withdrawn' WHERE type='Announce'").await.unwrap();assert!(page.require_current(&db).await.is_err());matched+=1;
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(matched,54);assert_eq!(keys.len(),54);
    }
    #[tokio::test]
    #[ignore="actual selected Announce reply-parent canonical mentions and glued-name projection in disposable SQL"]
    async fn actual_event_reply_mentions_projection(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-event-reply-mentions-reference.json").unwrap()).unwrap();let mut matched=0;let mut keys=std::collections::HashSet::new();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_event_reply_mentions_native_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width){
                assert!(keys.insert((width.to_owned(),s(row,"case").to_owned())));
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let actor=s(row,"actor");let owner=i(row,"owner");let guest=GuestProfileSession::from_php_guest_profile("fixture".into(),actor.into(),false,0).unwrap();let auth=if owner>0{Some(AuthenticatedProfileSession::from_php_session(owner,"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap())}else{None};let viewer=auth.as_ref().map(ProfileViewer::Auth).unwrap_or(ProfileViewer::Guest(&guest));
                let selected=crate::profile_source::select(&db,"fixture",actor,"boosts",10,0).await.unwrap();assert_eq!(json!(selected.rows()),row["page"]["items"]);let mut page=materialize(&db,&viewer,selected).await.unwrap();assert_eq!(page.page().source_count(),1);assert_eq!(page.page().next_offset(),i(&row["page"],"next_offset"));assert_eq!(page.page().has_more(),row["page"]["has_more"].as_bool().unwrap());assert_eq!(page.sources()[0].source_index(),0);
                struct PublicDns;impl crate::direct_preview::PreviewDns for PublicDns{fn ipv4<'a>(&'a self,_:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<std::net::Ipv4Addr>>>+Send+'a>>{Box::pin(async{Ok(vec![std::net::Ipv4Addr::new(8,8,8,8)])})}}
                let grant=crate::profile_context::admit_projection_sources(&db,&viewer,&page).await.unwrap();let transport=std::sync::Arc::new(RecordedPreviewWarm{calls:Default::default()});let preview=crate::profile_preview_fetch::PreviewFetchContext::new(transport.clone());let mut graphs=FollowGraphPresentation::new(None);for index in page.sources().iter().map(|s|s.source_index()).collect::<Vec<_>>(){page.complete_source_presentation_with_preview(&db,&PublicDns,&grant,index,&mut graphs,Some(&preview)).await.unwrap();}
                let ProfileSourceKind::Shared(status)=page.sources()[0].source()else{panic!("expected actual current canonical event status")};assert_eq!(status,&row["statuses"][0],"{width} {}",row["case"]);
                page.require_current(&db).await.unwrap();db.batch_execute("UPDATE events SET summary=COALESCE(summary,'')||' withdrawn' WHERE type='Announce'").await.unwrap();assert!(page.require_current(&db).await.is_err());matched+=1;
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(matched,90);assert_eq!(keys.len(),90);
    }
    #[tokio::test]
    #[ignore="actual selected event normalizer cached images and exact authorized cache-touch receipts in disposable SQL"]
    async fn actual_event_cached_image_media_and_touch(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-event-image-reference.json").unwrap()).unwrap();let mut matched=0;let mut keys=std::collections::HashSet::new();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_event_image_native_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width){
                assert!(keys.insert((width.to_owned(),s(row,"case").to_owned())));
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let actor=s(row,"actor");let owner=i(row,"owner");let guest=GuestProfileSession::from_php_guest_profile("fixture".into(),actor.into(),false,0).unwrap();let auth=if owner>0{Some(AuthenticatedProfileSession::from_php_session(owner,"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap())}else{None};let viewer=auth.as_ref().map(ProfileViewer::Auth).unwrap_or(ProfileViewer::Guest(&guest));
                let selected=crate::profile_source::select(&db,"fixture",actor,"boosts",10,0).await.unwrap();assert_eq!(json!(selected.rows()),row["page"]["items"]);let mut page=materialize(&db,&viewer,selected).await.unwrap();assert_eq!(page.page().source_count(),1);assert_eq!(page.page().next_offset(),i(&row["page"],"next_offset"));assert_eq!(page.page().has_more(),row["page"]["has_more"].as_bool().unwrap());assert_eq!(page.sources()[0].source_index(),0);
                struct PublicDns;impl crate::direct_preview::PreviewDns for PublicDns{fn ipv4<'a>(&'a self,_:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<std::net::Ipv4Addr>>>+Send+'a>>{Box::pin(async{Ok(vec![std::net::Ipv4Addr::new(8,8,8,8)])})}}
                let grant=crate::profile_context::admit_projection_sources(&db,&viewer,&page).await.unwrap();let original=match page.sources()[0].source(){ProfileSourceKind::Shared(s)=>s.clone(),_=>panic!("actual shared image")};
                let effects=page.effects().iter().filter(|e|matches!(e.effect(),ProjectionEffect::PostMediaTouch{..})).cloned().collect::<Vec<_>>();
                let images=row["statuses"][0]["reblog"]["media_attachments"].as_array().unwrap().iter().filter(|m|m["type"]=="image").count();assert_eq!(effects.len(),images);
                if let Some(effect)=effects.first(){let mut tampered=effect.clone();tampered.effect=ProjectionEffect::PostMediaTouch{source_url:"https://media.test/unbound.jpg".into()};assert!(page.apply_source_effect(&db,&tampered,&original,&grant).await.is_err());let mut wrong=original.clone();wrong["content"]=json!("unbound body");assert!(page.apply_source_effect(&db,effect,&wrong,&grant).await.is_err());}
                for effect in effects{page.apply_source_effect(&db,&effect,&original,&grant).await.unwrap();}
                let actual=db.query("SELECT to_jsonb(m) FROM remote_post_media_cache m ORDER BY id",&[]).await.unwrap().into_iter().map(|r|r.get::<_,Value>(0)).collect::<Vec<_>>();
                for mut media_row in actual{let expected=row["after_tables"]["remote_post_media_cache"].as_array().unwrap().iter().find(|r|r["id"]==media_row["id"]).unwrap();let initial=row["current_tables"]["remote_post_media_cache"].as_array().unwrap().iter().find(|r|r["id"]==media_row["id"]).unwrap();
                    if expected["last_used_at"]!=initial["last_used_at"]{assert_ne!(media_row["last_used_at"],initial["last_used_at"]);assert!(chrono::DateTime::parse_from_rfc3339(s(&media_row,"last_used_at")).is_ok());media_row["last_used_at"]=expected["last_used_at"].clone();}assert_eq!(&media_row,expected,"exact cached image row except authorized clock");
                }
                let transport=std::sync::Arc::new(RecordedPreviewWarm{calls:Default::default()});let preview=crate::profile_preview_fetch::PreviewFetchContext::new(transport.clone());let mut graphs=FollowGraphPresentation::new(None);for index in page.sources().iter().map(|s|s.source_index()).collect::<Vec<_>>(){page.complete_source_presentation_with_preview(&db,&PublicDns,&grant,index,&mut graphs,Some(&preview)).await.unwrap();}
                let ProfileSourceKind::Shared(status)=page.sources()[0].source()else{panic!("expected actual current canonical event status")};assert_eq!(status,&row["statuses"][0],"{width} {}",row["case"]);
                page.require_current(&db).await.unwrap();db.batch_execute("UPDATE events SET summary=COALESCE(summary,'')||' withdrawn' WHERE type='Announce'").await.unwrap();assert!(page.require_current(&db).await.is_err());matched+=1;
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(matched,36);assert_eq!(keys.len(),36);
    }
    #[tokio::test]
    #[ignore="actual native preview SQL write receipts over current projected source grants"]
    async fn actual_preview_writer_projection_receipts(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-event-preview-intent-reference.json").unwrap()).unwrap();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_preview_writer_projection_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            // Column-only source fixture snapshots omit constraints; production preview storage owns UNIQUE(url).
            db.batch_execute("ALTER TABLE link_preview_cards ADD UNIQUE(url)").await.unwrap();
            let row=reference["captures"].as_array().unwrap().iter().find(|r|r["width"]==width&&r["case"]=="re_prefix_owner2").unwrap();
            for(table,rows)in row["current_tables"].as_object().unwrap(){for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
            let actor=s(row,"actor");let session=AuthenticatedProfileSession::from_php_session(2,"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap();let viewer=ProfileViewer::Auth(&session);let selected=crate::profile_source::select(&db,"fixture",actor,"boosts",10,0).await.unwrap();let mut page=materialize(&db,&viewer,selected).await.unwrap();
            let original=match page.sources()[0].source(){ProfileSourceKind::Shared(s)=>s.clone(),_=>panic!("actual selected public boost")};let admission=crate::profile_context::admit_projection_sources(&db,&viewer,&page).await.unwrap();
            struct Dns;impl crate::direct_preview::PreviewDns for Dns{fn ipv4<'a>(&'a self,_:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<std::net::Ipv4Addr>>>+Send+'a>>{Box::pin(async{Ok(vec![std::net::Ipv4Addr::new(8,8,8,8)])})}}
            let warm=crate::profile_preview_fetch::PreviewFetchContext::new(std::sync::Arc::new(RecordedPreviewWarm{calls:Default::default()}));let mut graphs=FollowGraphPresentation::new(None);page.complete_source_presentation_with_preview(&db,&Dns,&admission,0,&mut graphs,Some(&warm)).await.unwrap();
            let (current,journal)=page.source_current_grant_with_preview_receipts(admission,0,original);current.require_current(&db).await.unwrap();
            struct Bridge<'a>{db:&'a Client,current:&'a dyn crate::profile_enrichment::CurrentEnrichmentGrant}
            impl crate::profile_quote_warm::CurrentQuoteGrant for Bridge<'_>{fn require_current(&self)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'_>>{Box::pin(self.current.require_current(self.db))}fn accept_preview_write(&self,w:&crate::profile_preview_fetch::PreviewCacheWrite)->Result<()>{self.current.accept_preview_write(w)}}
            struct Transport;impl crate::profile_preview_fetch::PreviewFetchTransport for Transport{fn http_get<'a>(&'a self,_:&'a str,_:u64,_:usize,grant:&'a dyn crate::profile_quote_warm::CurrentQuoteGrant)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Option<String>>>+Send+'a>>{Box::pin(async move{grant.require_current().await?;Ok(Some("<html><title>Receipt test</title></html>".into()))})}fn warm<'a>(&'a self,_:&'a str,_:&'a dyn crate::profile_quote_warm::CurrentQuoteGrant)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<bool>>+Send+'a>>{Box::pin(async{Ok(false)})}}
            let preview=crate::profile_preview_fetch::PreviewFetchContext::new(std::sync::Arc::new(Transport));let bound=Bridge{db:&db,current:current.as_ref()};let card=preview.for_url(&db,&Dns,"https://remote.test/123",true,chrono::Utc::now(),&bound).await.unwrap();assert!(card.is_some());current.require_current(&db).await.unwrap();assert!(page.require_current(&db).await.is_err(),"own write needs explicit retained product receipt reconciliation");
            let changes=journal.drain_changes().unwrap();page.reconcile_account_changes(&changes).unwrap();page.require_current(&db).await.unwrap();page.reconcile_account_changes(&changes).unwrap();
            db.execute("UPDATE link_preview_cards SET title='unrelated concurrent edit' WHERE url='https://remote.test/123'",&[]).await.unwrap();assert!(current.require_current(&db).await.is_err());assert!(page.require_current(&db).await.is_err());
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }
    }
    #[tokio::test]
    #[ignore="actual selected event normalizer moderation/null effects in disposable SQL"]
    async fn actual_event_remote_link_intents(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-event-preview-intent-reference.json").unwrap()).unwrap();let mut matched=0;let mut keys=std::collections::HashSet::new();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_event_preview_intents_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width){
                assert!(keys.insert((width.to_owned(),s(row,"case").to_owned())));
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let actor=s(row,"actor");let owner=i(row,"owner");let guest=GuestProfileSession::from_php_guest_profile("fixture".into(),actor.into(),false,0).unwrap();let auth=if owner>0{Some(AuthenticatedProfileSession::from_php_session(owner,"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap())}else{None};let viewer=auth.as_ref().map(ProfileViewer::Auth).unwrap_or(ProfileViewer::Guest(&guest));
                let selected=crate::profile_source::select(&db,"fixture",actor,"boosts",10,0).await.unwrap();assert_eq!(json!(selected.rows()),row["page"]["items"]);let mut page=materialize(&db,&viewer,selected).await.unwrap();assert_eq!(page.page().source_count(),1);assert_eq!(page.page().next_offset(),i(&row["page"],"next_offset"));assert_eq!(page.page().has_more(),row["page"]["has_more"].as_bool().unwrap());assert_eq!(page.sources()[0].source_index(),0);
                struct PublicDns;impl crate::direct_preview::PreviewDns for PublicDns{fn ipv4<'a>(&'a self,_:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<std::net::Ipv4Addr>>>+Send+'a>>{Box::pin(async{Ok(vec![std::net::Ipv4Addr::new(8,8,8,8)])})}}
                let grant=crate::profile_context::admit_projection_sources(&db,&viewer,&page).await.unwrap();let transport=std::sync::Arc::new(RecordedPreviewWarm{calls:Default::default()});let preview=crate::profile_preview_fetch::PreviewFetchContext::new(transport.clone());let mut graphs=FollowGraphPresentation::new(None);for index in page.sources().iter().map(|s|s.source_index()).collect::<Vec<_>>(){page.complete_source_presentation_with_preview(&db,&PublicDns,&grant,index,&mut graphs,Some(&preview)).await.unwrap();}
                let ProfileSourceKind::Shared(status)=page.sources()[0].source()else{panic!("expected actual current canonical event status")};assert_eq!(status,&row["statuses"][0],"{width} {}",row["case"]);
                assert_eq!(json!(transport.calls.lock().unwrap().clone()),row["effects"]["preview_warm_requests"],"{width} {}",row["case"]);assert_eq!(row["effects"]["preview_http_requests"],json!([]));
                page.require_current(&db).await.unwrap();db.batch_execute("UPDATE events SET summary=COALESCE(summary,'')||' withdrawn' WHERE type='Announce'").await.unwrap();assert!(page.require_current(&db).await.is_err());matched+=1;
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(matched,72);assert_eq!(keys.len(),72);
    }
    #[tokio::test]
    #[ignore="actual selected event normalizer moderation/null effects in disposable SQL"]
    async fn actual_event_html_summary(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-event-html-reference.json").unwrap()).unwrap();let mut matched=0;let mut keys=std::collections::HashSet::new();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_event_html_native_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width){
                assert!(keys.insert((width.to_owned(),s(row,"case").to_owned())));
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let actor=s(row,"actor");let owner=i(row,"owner");let guest=GuestProfileSession::from_php_guest_profile("fixture".into(),actor.into(),false,0).unwrap();let auth=if owner>0{Some(AuthenticatedProfileSession::from_php_session(owner,"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap())}else{None};let viewer=auth.as_ref().map(ProfileViewer::Auth).unwrap_or(ProfileViewer::Guest(&guest));
                let selected=crate::profile_source::select(&db,"fixture",actor,"boosts",10,0).await.unwrap();assert_eq!(json!(selected.rows()),row["page"]["items"]);let mut page=materialize(&db,&viewer,selected).await.unwrap();assert_eq!(page.page().source_count(),1);assert_eq!(page.page().next_offset(),i(&row["page"],"next_offset"));assert_eq!(page.page().has_more(),row["page"]["has_more"].as_bool().unwrap());assert_eq!(page.sources()[0].source_index(),0);
                struct PublicDns;impl crate::direct_preview::PreviewDns for PublicDns{fn ipv4<'a>(&'a self,_:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<std::net::Ipv4Addr>>>+Send+'a>>{Box::pin(async{Ok(vec![std::net::Ipv4Addr::new(8,8,8,8)])})}}
                let grant=crate::profile_context::admit_projection_sources(&db,&viewer,&page).await.unwrap();let transport=std::sync::Arc::new(RecordedPreviewWarm{calls:Default::default()});let preview=crate::profile_preview_fetch::PreviewFetchContext::new(transport.clone());let mut graphs=FollowGraphPresentation::new(None);for index in page.sources().iter().map(|s|s.source_index()).collect::<Vec<_>>(){page.complete_source_presentation_with_preview(&db,&PublicDns,&grant,index,&mut graphs,Some(&preview)).await.unwrap();}
                let ProfileSourceKind::Shared(status)=page.sources()[0].source()else{panic!("expected actual current canonical event status")};assert_eq!(status,&row["statuses"][0],"{width} {}",row["case"]);
                page.require_current(&db).await.unwrap();db.batch_execute("UPDATE events SET summary=COALESCE(summary,'')||' withdrawn' WHERE type='Announce'").await.unwrap();assert!(page.require_current(&db).await.is_err());matched+=1;
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(matched,96);assert_eq!(keys.len(),96);
    }
    #[tokio::test]
    #[ignore="actual projected event normalizer withdrawal inside first preview DNS"]
    async fn actual_event_normalizer_withdrawal_during_dns(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-event-entities-reference.json").unwrap()).unwrap();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_normalizer_dns_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            let row=reference["captures"].as_array().unwrap().iter().find(|r|r["width"]==width&&r["case"]=="boundaries_owner2").unwrap();
            for(table,rows)in row["current_tables"].as_object().unwrap(){for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
            // A second genuinely selected SQL source tests that the first
            // dependency withdrawal stops the next source's preview DNS too.
            let mut second=row["page"]["items"][0].clone();second["id"]=json!(i(&second,"id")+1000);second["object_id"]=json!(format!("{}/distinct-control",s(&second,"object_id")));db.execute("INSERT INTO events SELECT * FROM jsonb_populate_record(NULL::events,$1)",&[&second]).await.unwrap();
            let mut create=row["current_tables"]["events"].as_array().unwrap().iter().find(|event|s(event,"type")=="Create"&&s(event,"object_id").trim_end_matches('/')==s(&row["page"]["items"][0],"object_id").trim_end_matches('/')).expect("actual public underlying Create").clone();create["id"]=json!(i(&create,"id")+2000);create["object_id"]=second["object_id"].clone();db.execute("INSERT INTO events SELECT * FROM jsonb_populate_record(NULL::events,$1)",&[&create]).await.unwrap();
            let actor=s(row,"actor");let session=AuthenticatedProfileSession::from_php_session(2,"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap();let viewer=ProfileViewer::Auth(&session);let selected=crate::profile_source::select(&db,"fixture",actor,"boosts",10,0).await.unwrap();assert_eq!(selected.rows().len(),2);
            let mut page=materialize(&db,&viewer,selected).await.unwrap();assert_eq!(page.cache.len(),2);assert!(!page.effects().is_empty());let grant=crate::profile_context::admit_projection_sources(&db,&viewer,&page).await.unwrap();
            let first=page.sources()[0].source_index();let ProfileSourceKind::Shared(status)=page.sources()[0].source()else{panic!("native actual event source")};let status=status.clone();assert_eq!(grant.disposition(first,&status),Some(true));
            struct WithdrawingDns<'a>{db:&'a Client,actor:&'a str,calls:std::sync::atomic::AtomicUsize}
            impl crate::direct_preview::PreviewDns for WithdrawingDns<'_>{fn ipv4<'a>(&'a self,_:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<std::net::Ipv4Addr>>>+Send+'a>>{Box::pin(async move{assert_eq!(self.calls.fetch_add(1,std::sync::atomic::Ordering::SeqCst),0,"no second DNS after normalizer withdrawal");self.db.execute("INSERT INTO ap_blocks(id,scope,value,kind,created_at) VALUES(990,'actor',$1,'mute','2026-10-10T15:00:00Z')",&[&self.actor]).await?;
                // A post-DNS cache SQL read would now fail with undefined table;
                // the guarded dependency check must reject before that read.
                self.db.batch_execute("DROP TABLE link_preview_cards").await?;Ok(vec![std::net::Ipv4Addr::new(8,8,8,8)])})}}
            let dns=WithdrawingDns{db:&db,actor,calls:std::sync::atomic::AtomicUsize::new(0)};let error=page.complete_cache(&db,&dns,&grant).await.err().expect("current normalizer dependency withdrawal rejects complete prefix");assert!(error.to_string().contains("projection changed before enrichment dependency"),"{error:#}");assert_eq!(dns.calls.load(std::sync::atomic::Ordering::SeqCst),1);
            // Explicit-profile Direct audience remains lawful: rejection came
            // from the actual normalizer dependency, not a fabricated denial.
            grant.require_current(&db,first,&status).await.unwrap();assert!(page.sources().iter().all(|s|!s.cache_complete));assert!(page.require_current(&db).await.is_err());db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }
    }
    #[tokio::test]
    #[ignore="actual PHP selected pin normalization and current admission in disposable SQL"]
    async fn actual_secondary_pinned_projection(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-header-secondary-selector-reference.json").unwrap()).unwrap();let mut matched=0;let mut keys=std::collections::HashSet::new();
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("profile_pinned_projection_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let definitions=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({definitions})")).await.unwrap();}
            for row in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width&&s(r,"case").starts_with("pins_")){
                assert!(keys.insert((width.to_owned(),s(row,"case").to_owned())));
                for(table,rows)in row["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let actor=s(&row["context"],"actor");let key=s(&row["context"],"key");let owner=i(&row["context"],"owner");
                let guest=GuestProfileSession::from_php_guest_profile(key.into(),actor.into(),false,0).unwrap();let auth=if owner>0{Some(AuthenticatedProfileSession::from_php_session(owner,"fixture-csrf-token".into(),key.into(),actor.into(),false,0).unwrap())}else{None};let viewer=auth.as_ref().map(ProfileViewer::Auth).unwrap_or(ProfileViewer::Guest(&guest));
                let selected=crate::profile_source::select_secondary(&db,key,actor,"pinned").await.unwrap();assert_eq!(json!(selected.rows()),row["selected_secondary"]["pinned_rows"]);
                let page=materialize(&db,&viewer,selected).await.unwrap();assert_eq!(page.page().source_count(),1);assert_eq!(page.sources().len(),1);let source=&page.sources()[0];assert_eq!(source.source_index(),0);let ProfileSourceKind::Shared(status)=source.source()else{panic!("actual pins are Masto-normalized even for guest")};assert_eq!(status,&row["selected_secondary"]["pinned_statuses"][0],"{width} {}",row["case"]);
                let grant=crate::profile_context::admit_projection_sources(&db,&viewer,&page).await.unwrap();let allowed=!s(row,"case").starts_with("pins_missing_outbox");assert_eq!(grant.disposition(0,status),Some(allowed),"{width} {}",row["case"]);
                page.require_current(&db).await.unwrap();db.batch_execute("UPDATE masto_statuses SET content_text=COALESCE(content_text,'')||' withdrawn'").await.unwrap();assert!(page.require_current(&db).await.is_err());matched+=1;
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(matched,24);assert_eq!(keys.len(),24);
    }
    #[test]fn selected_like_author_is_not_target_author(){
        assert_eq!(local_key("https://mkultra.monster/users/aXb/notes/1"),Some("axb".into()));
        assert_ne!(local_key("https://mkultra.monster/users/aXb/notes/1"),Some("a_b".into()));
        assert!(local_key("https://mkultra.monster/users/a-b/notes/1").is_none());
        assert!(local_key("https://mkultra.monster:443/users/a_b/notes/1").is_none());
    }
    #[test]fn raw_import_branch_uses_actual_create_metadata(){
        assert!(imported_bsky(&json!({"raw_create_json":r#"{"object":{"vaakOrigin":"bluesky"}}"#})));
        assert!(!imported_bsky(&json!({"source":"bluesky","vaakOrigin":"bluesky"})));
    }
}

#[path="profile_remote_header.rs"]
mod remote_header;
pub(crate) use remote_header::{RemoteApHeaderSession,RemoteHeaderRequest,RemoteHeaderFacts,resolve_remote_header};
