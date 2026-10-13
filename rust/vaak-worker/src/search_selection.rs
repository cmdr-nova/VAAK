//! Enclosing actual PHP Search window, separate from source/audience admission.
use anyhow::{bail,Context,Result};
use serde_json::Value;
use sha2::{Digest,Sha256};
use tokio_postgres::Client;
#[path="search_final_facts.rs"]
pub(crate) mod final_facts;
#[cfg(test)]
#[path="search_perf_fixture.rs"]
pub(crate) mod fixture_performance;

#[derive(serde::Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct SearchCollectorDescriptor {
 pub(crate) normalized_query:String,pub(crate) resolve:bool,
 pub(crate) cache_location:String,pub(crate) cache_sha256:String,
 pub(crate) offset:usize,pub(crate) limit:usize,
 pub(crate) selected_ordinals:Vec<usize>,
}

#[derive(PartialEq,Eq)]
struct FileIdentity {length:u64,modified:std::time::SystemTime,#[cfg(unix)] device:u64,#[cfg(unix)] inode:u64}
impl FileIdentity {
 fn of(metadata:&std::fs::Metadata)->Result<Self>{
  #[cfg(unix)] use std::os::unix::fs::MetadataExt;
  Ok(Self{length:metadata.len(),modified:metadata.modified()?,#[cfg(unix)] device:metadata.dev(),#[cfg(unix)] inode:metadata.ino()})
 }
}
pub(crate) struct SearchWindowGrant {
 owner:i64,actor:String,key:String,viewer_profile:Value,raw_scope:final_facts::Scope,raw_snapshot:Value,path:std::path::PathBuf,bytes:Vec<u8>,
 preview_snapshot:std::sync::Mutex<Option<Value>>,candidates:Vec<Value>,selected:Vec<usize>,offset:usize,limit:usize,identity:FileIdentity,
}
/// Invocation-scoped result of original ordered predicates followed by one
/// combined raw SQL snapshot and a synchronous file check. SQL dependencies
/// share that final statement snapshot; the filesystem is checked separately.
/// The proof is used only synchronously, never retained across later IO.
pub(crate) struct FreshSearchWindow<'a>{window:&'a SearchWindowGrant}
impl FreshSearchWindow<'_>{
 pub(crate) fn require_bound_source(&self,association:&std::sync::Arc<SearchWindowGrant>,owner:i64,actor:&str,key:&str,index:usize,source:&Value)->Result<()>{
  if !std::ptr::eq(self.window,std::sync::Arc::as_ref(association)){bail!("different Search final window capability")}
  self.window.require_owner_binding(owner,actor,key)?;
  self.window.require_source_binding(index,source)
 }
}
impl SearchWindowGrant {
 pub(crate) async fn load(db:&Client,owner:i64,descriptor:SearchCollectorDescriptor,sources:&[Value])->Result<Self>{
  if owner<1||descriptor.normalized_query.is_empty()||descriptor.normalized_query.len()>4096
   ||descriptor.offset>160||!(1..=40).contains(&descriptor.limit)
   ||descriptor.selected_ordinals.len()!=sources.len()||sources.len()>descriptor.limit
   ||descriptor.cache_sha256.len()!=64||!descriptor.cache_sha256.bytes().all(|b|b.is_ascii_digit()||(b'a'..=b'f').contains(&b)){bail!("invalid Search collector descriptor")}
  // These are the two actual admin_search_run locations. No supplied path is
  // accepted. PHP supplies its exact mb_strtolower query bytes, preserving its
  // Unicode semantics; the owner/resolve key is independently recomputed.
  let directory=match descriptor.cache_location.as_str(){"primary"=>std::path::PathBuf::from("/var/lib/mkultra/ap/search-cache"),"temporary"=>std::env::temp_dir().join("vaak-search-cache"),_=>bail!("invalid Search cache location")};
  let key_hex=format!("{:x}",Sha256::digest(format!("mf3|{owner}|{}|{}|121|80",descriptor.normalized_query,if descriptor.resolve{"1"}else{"0"}).as_bytes()));
  let path=directory.join(format!("s_{key_hex}.json"));
  let (bytes,identity)=Self::read(&path).await?;
  if format!("{:x}",Sha256::digest(&bytes))!=descriptor.cache_sha256{bail!("Search collector file differs from actual producer")}
  let cached:Value=serde_json::from_slice(&bytes)?;
  if cached["have_statuses"]!=Value::Bool(true){bail!("Search collector has no status window")}
  let candidates=cached["statuses"].as_array().context("Search cache status list")?.clone();
  // Actual PHP sanitizer currently caps this ranked producer at 40. The
  // cache-key fetch121 remains exact PHP arithmetic, not wider source authority.
  if candidates.len()>40||candidates.iter().any(|value|!value.is_object()){bail!("Search collector candidate bound")}
  let row=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&owner]).await?.context("inactive Search collector owner")?;
  let actor:String=row.get(0);let key:String=row.get(1);
  // Complete viewer Settings snapshot, including absence. This is immutable
  // presentation provenance; every IO/final invocation still reads current DB.
  let viewer_profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1 LIMIT 1",&[&key]).await?.map(|row|row.get::<_,Value>(0)).unwrap_or(Value::Null);
  let raw_scope=final_facts::Scope::new(owner,&key,&actor,&candidates,&[],&[],false);
  let raw_snapshot=raw_scope.read(db).await?;
  let grant=Self{owner,actor,key,viewer_profile,raw_scope,raw_snapshot,path,bytes,preview_snapshot:std::sync::Mutex::new(None),candidates,selected:descriptor.selected_ordinals,offset:descriptor.offset,limit:descriptor.limit,identity};
  for (index,source) in sources.iter().enumerate(){grant.require_source_binding(index,source)?}
  grant.require_current(db).await?;Ok(grant)
 }
 async fn read(path:&std::path::Path)->Result<(Vec<u8>,FileIdentity)>{
  let before=tokio::fs::symlink_metadata(path).await?;
  if !before.is_file()||before.file_type().is_symlink()||before.len()>2*1024*1024{bail!("Search cache file type/size")}
  use tokio::io::AsyncReadExt;
  let file=tokio::fs::File::open(path).await?;
  if FileIdentity::of(&file.metadata().await?)?!=FileIdentity::of(&before)?{bail!("Search cache replaced before read")}
  let mut bytes=Vec::new();file.take(2*1024*1024+1).read_to_end(&mut bytes).await?;
  let after=tokio::fs::symlink_metadata(path).await?;
  if !after.is_file()||after.file_type().is_symlink()||bytes.len()>2*1024*1024||before.len()!=after.len()||before.modified()?!=after.modified()?{bail!("Search cache changed during read")}
  #[cfg(unix)] {use std::os::unix::fs::MetadataExt;if before.dev()!=after.dev()||before.ino()!=after.ino(){bail!("Search cache replaced during read")}}
  Ok((bytes,FileIdentity::of(&after)?))
 }
 async fn require_file_current(&self)->Result<()>{let (bytes,identity)=Self::read(&self.path).await?;if bytes!=self.bytes||identity!=self.identity{bail!("Search collector file replaced/changed")}Ok(())}

 pub(crate) fn owner_id(&self)->i64{self.owner}
 pub(crate) fn viewer_profile(&self)->&Value{&self.viewer_profile}
 /// Actual status action facts must use the same retained owner role as the
 /// initial/final raw witness. INTEGER/BIGINT NULL coalesces to zero.
 pub(crate) fn require_painted_owner_role(&self,facts:&crate::library::card::CardFacts)->Result<()>{
  if facts.owner_id!=self.owner{bail!("Search painted status owner differs from collector")}
  let users=self.raw_snapshot["users"].as_array().context("Search retained owner rows")?;
  let owners=users.iter().filter(|row|row["id"].as_i64()==Some(self.owner)).collect::<Vec<_>>();
  if owners.len()!=1{bail!("Search retained owner role ambiguous")}
  let value=&owners[0]["is_admin"];
  let expected=if value.is_null(){false}else{value.as_i64().context("Search retained INTEGER/BIGINT admin role")?!=0};
  if facts.moderation.is_admin!=expected{bail!("Search painted status admin role differs from retained owner facts")}Ok(())
 }

 pub(crate) fn require_owner_binding(&self,owner:i64,actor:&str,key:&str)->Result<()>{if owner!=self.owner||actor!=self.actor||key!=self.key{bail!("Search source owner identity differs from producer capability")}Ok(())}
 pub(crate) async fn refresh_current<'a>(&'a self,db:&Client)->Result<FreshSearchWindow<'a>>{self.require_current(db).await?;Ok(FreshSearchWindow{window:self})}
 pub(crate) async fn refresh_current_with_components<'a>(&'a self,db:&Client,components:Option<&crate::search_components::admission::ComponentsGrant>)->Result<FreshSearchWindow<'a>>{self.require_current_with_components(db,components,true).await?;Ok(FreshSearchWindow{window:self})}
 pub(crate) fn require_source_binding(&self,index:usize,source:&Value)->Result<()>{
  let ordinal=*self.selected.get(index).context("Search source not in selected producer page")?;
  if self.candidates.get(ordinal)!=Some(source){bail!("Search supplied source differs from exact selected producer entry")}
  Ok(())
 }
 pub(crate) async fn bind_preview_write_url(&self,db:&Client,index:usize,source:&Value,url:&str)->Result<()>{
  if self.preview_snapshot.lock().map_err(|_|anyhow::anyhow!("Search preview journal poisoned"))?.is_none(){bail!("Search preview writer lacks actual root extraction dependency")}
  // This URL comes only from the authorized native cache writer after actual
  // OG parsing. Re-extracting it as article text truncates Unicode canonical URLs.
  self.bind_preview_exact_url(db,index,source,url).await
 }
 pub(crate) async fn bind_preview_text(&self,db:&Client,index:usize,source:&Value,text:&str)->Result<()>{
  self.require_source_binding(index,source)?;self.require_current(db).await?;
  let Some(url)=crate::direct_preview::extract_url(text)?else{return Ok(())};
  self.bind_preview_exact_url(db,index,source,&url).await
 }
 async fn bind_preview_exact_url(&self,db:&Client,index:usize,source:&Value,url:&str)->Result<()>{
  self.require_source_binding(index,source)?;self.require_current(db).await?;
  let url=crate::direct_preview::normalize(url)?;if url.is_empty(){return Ok(())}
  let previous=self.preview_snapshot.lock().map_err(|_|anyhow::anyhow!("Search preview journal poisoned"))?.clone();
  let mut urls=previous.as_ref().and_then(|v|v["urls"].as_array()).into_iter().flatten().filter_map(Value::as_str).map(str::to_owned).collect::<Vec<_>>();
  if urls.contains(&url){return Ok(())}if urls.len()>=80{bail!("Search preview exact URL scope exceeded")}urls.push(url);urls.sort();
  self.require_file_current().await?;
  let (raw,_,preview)=self.raw_scope.read_with_previews(db,None,&urls,false).await?;
  final_facts::Scope::require_same(&self.raw_snapshot,&raw,Some(&self.viewer_profile))?;
  if let Some(previous)=previous{
   if previous["shape"]!=preview["shape"]{bail!("Search preview shape changed during dependency capture")}
   for existing in previous["urls"].as_array().context("Search preview URL journal")?{
    if Self::preview_rows(&previous,existing.as_str().context("Search preview URL")?)!=Self::preview_rows(&preview,existing.as_str().unwrap()){bail!("Search retained preview rows changed during capture")}
   }
  }
  let rows=preview["rows"].as_array().context("Search preview row witness")?;
  for url in &urls{if rows.iter().filter(|row|row["url"].as_str()==Some(url.as_str())).count()>1{bail!("PHP fallback: ambiguous legacy preview cache rows")}}
  self.require_file_current_sync()?;
  *self.preview_snapshot.lock().map_err(|_|anyhow::anyhow!("Search preview journal poisoned"))?=Some(preview);Ok(())
 }
 fn preview_rows(snapshot:&Value,url:&str)->Vec<Value>{snapshot["rows"].as_array().into_iter().flatten().filter(|row|row["url"].as_str()==Some(url)).cloned().collect()}
 pub(crate) fn accept_preview_write(&self,write:&crate::profile_preview_fetch::PreviewCacheWrite)->Result<()>{
  let mut retained=self.preview_snapshot.lock().map_err(|_|anyhow::anyhow!("Search preview journal poisoned"))?;
  let snapshot=retained.as_mut().context("Search preview writer lacks retained dependency")?;
  if !snapshot["urls"].as_array().context("Search preview URL journal")?.iter().any(|url|url.as_str()==Some(write.url())){bail!("Search preview receipt URL outside selected native dependency")}
  if Self::preview_rows(snapshot,write.url()).as_slice()!=write.before(){bail!("Search preview writer before rows differ from retained snapshot")}
  if write.after().len()!=1||write.after()[0]["url"].as_str()!=Some(write.url()){bail!("Search preview writer after rows invalid")}
  let rows=snapshot["rows"].as_array_mut().context("Search preview row journal")?;rows.retain(|row|row["url"].as_str()!=Some(write.url()));rows.extend_from_slice(write.after());rows.sort_by(|a,b|a["url"].as_str().cmp(&b["url"].as_str()));Ok(())
 }
 pub(crate) async fn require_current(&self,db:&Client)->Result<()>{self.require_current_with_components(db,None,false).await}
 async fn require_current_with_components(&self,db:&Client,components:Option<&crate::search_components::admission::ComponentsGrant>,_terminal:bool)->Result<()>{
  #[cfg(test)] let _timing=fixture_performance::span("collector_refresh",self.candidates.len());
  self.require_file_current().await?;
  let row=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&self.owner]).await?.context("Search collector owner withdrawn")?;
  if row.get::<_,String>(0)!=self.actor||row.get::<_,String>(1)!=self.key{bail!("Search collector owner changed")}
  let viewer_profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1 LIMIT 1",&[&self.key]).await?.map(|row|row.get::<_,Value>(0)).unwrap_or(Value::Null);
  if viewer_profile!=self.viewer_profile{bail!("Search viewer Settings changed")}
  #[cfg(test)] if _terminal{final_facts::boundary_fixture::at(db,"after_viewer_before_components").await?;}
  // Component checks precede the final complete status/source predicate;
  // they never add a new SQL await after status publication admission.
  if let Some(components)=components{components.require_ordered_current(db).await?;}
  #[cfg(test)] if _terminal{final_facts::boundary_fixture::at(db,"after_components_before_status").await?;}
  let mut filtered=Vec::new();
  for (ordinal,allowed) in super::selected_current_mask(db,self.owner,&self.actor,&self.key,&self.candidates).await?.into_iter().enumerate(){if allowed{filtered.push(ordinal)}}
  let selected=filtered.into_iter().skip(self.offset).take(self.limit).collect::<Vec<_>>();
  if selected!=self.selected{bail!("Search current filtered producer window changed")}
  self.require_file_current().await?;
  #[cfg(test)] if _terminal{final_facts::boundary_fixture::at(db,"after_status_before_final_raw").await?;}
  // This is the last async operation: one SQL statement reads every scoped
  // dependency together. No previous allow survives a changed raw witness.
  let preview=self.preview_snapshot.lock().map_err(|_|anyhow::anyhow!("Search preview journal poisoned"))?.clone();
  let raw_after=if let Some(preview)=preview{
   let urls=preview["urls"].as_array().context("Search preview URL journal")?.iter().map(|v|v.as_str().map(str::to_owned).context("Search preview URL")).collect::<Result<Vec<_>>>()?;
   let (raw,component_after,preview_after)=self.raw_scope.read_with_previews(db,components.map(|c|c.raw_binding().0),&urls,_terminal).await?;
   if let Some(components)=components{final_facts::Scope::require_same(components.raw_binding().1,component_after.as_ref().context("Search component final witness")?,None)?;}
   if preview_after!=preview{bail!("Search preview dependency changed before publication")}raw
  }else if let Some(components)=components{
   let (scope,before)=components.raw_binding();
   let (status_after,component_after)=self.raw_scope.read_pair(db,scope).await?;
   final_facts::Scope::require_same(before,&component_after,None)?;
   status_after
  }else{self.raw_scope.read(db).await?};
  final_facts::Scope::require_same(&self.raw_snapshot,&raw_after,Some(&self.viewer_profile))?;
  self.require_file_current_sync()?;
  Ok(())
 }
 fn require_file_current_sync(&self)->Result<()>{
  let before=std::fs::symlink_metadata(&self.path)?;
  if !before.is_file()||before.file_type().is_symlink()||FileIdentity::of(&before)?!=self.identity{bail!("Search collector file changed before final synchronous read")}
  let mut file=std::fs::File::open(&self.path)?;
  if FileIdentity::of(&file.metadata()?)?!=self.identity{bail!("Search collector file replaced before final synchronous read")}
  use std::io::Read;let mut bytes=Vec::new();file.by_ref().take(2*1024*1024+1).read_to_end(&mut bytes)?;
  let after=std::fs::symlink_metadata(&self.path)?;
  if !after.is_file()||after.file_type().is_symlink()||FileIdentity::of(&after)?!=self.identity||bytes!=self.bytes{bail!("Search collector file changed during final synchronous read")}
  Ok(())
 }
}


/// Fresh bounded source rows shared only within one predicate invocation. No
/// allowed decision or row snapshot is reused across any enrichment await.
pub(super) struct CurrentSourceRows {rows:Value,pub(super) indexability:std::collections::HashMap<String,(i64,i64)>}
impl CurrentSourceRows {
 fn refs(status:&Value,uris:&mut std::collections::HashSet<String>,depth:usize){
  if depth>16{return}
  let uri=super::trim(status.get("uri").or_else(||status.get("url")).and_then(Value::as_str).unwrap_or("")).split('#').next().unwrap_or("").trim_end_matches('/');
  if !uri.is_empty(){uris.insert(uri.into());}
  if let Some(raw)=status.get("bsky_post"){let post=raw.get("post").filter(|value|value.is_object()).unwrap_or(raw);let at=super::text(post,"uri");if !at.is_empty(){uris.insert(at.into());}}
  for (field,child) in super::children(status){if !super::descriptor(field,child){Self::refs(child,uris,depth+1)}}
 }
 pub(super) async fn load(db:&Client,statuses:&[Value])->Result<Self>{
  #[cfg(test)] let _timing=fixture_performance::span("source_rows_load_two_SQL",statuses.len());
  let mut uris=std::collections::HashSet::new();for status in statuses{Self::refs(status,&mut uris,0)}
  let uris=uris.into_iter().collect::<Vec<_>>();
  const SQL:&str="SELECT COALESCE(jsonb_object_agg(q.uri,jsonb_build_object('bsky',EXISTS(SELECT 1 FROM bsky_posts b WHERE b.bsky_uri=q.uri),'local',EXISTS(SELECT 1 FROM masto_statuses s WHERE rtrim(s.note_id,'/')=q.uri),'event',to_jsonb(e),'mention',to_jsonb(m))),'{}'::jsonb) FROM unnest($1::text[]) q(uri) LEFT JOIN LATERAL (SELECT actor_id,type,action_taken FROM events ev WHERE ev.object_id IN(q.uri,q.uri||'/') AND (ev.type IN('Create','Update','Delete','Tombstone') OR (ev.type IN('Quote','QuotePost') AND NOT EXISTS(SELECT 1 FROM events note_source WHERE note_source.object_id IN(q.uri,q.uri||'/') AND note_source.type IN('Create','Update')))) ORDER BY id DESC LIMIT 1) e ON true LEFT JOIN LATERAL (SELECT mention_source.actor_id,mention_source.deleted_at::text FROM mentions mention_source WHERE mention_source.object_id IN(q.uri,q.uri||'/') ORDER BY mention_source.id DESC LIMIT 1) m ON true";
  let rows:Value=db.query_one(SQL,&[&uris]).await?.get(0);
  let mut authors=std::collections::HashSet::new();
  fn collect_authors(status:&Value,authors:&mut std::collections::HashSet<String>,depth:usize){
   if depth>16{return}
   let actor=super::canonical_actor(status["account"].get("uri").or_else(||status["account"].get("url")).and_then(Value::as_str).unwrap_or(""));
   if !actor.is_empty(){authors.insert(actor);}
   let uri=super::trim(status.get("uri").or_else(||status.get("url")).and_then(Value::as_str).unwrap_or("")).split('#').next().unwrap_or("").trim_end_matches('/');
   if super::local_note_uri(uri){authors.insert(uri.split("/notes/").next().unwrap_or("").into());}
   for (field,child) in super::children(status){if !super::descriptor(field,child){collect_authors(child,authors,depth+1)}}
  }
  for status in statuses{collect_authors(status,&mut authors,0)}
  if let Some(objects)=rows.as_object(){for source in objects.values(){for field in ["event","mention"]{if let Some(actor)=source[field]["actor_id"].as_str(){authors.insert(super::canonical_actor(actor));}}}}
  let authors=authors.into_iter().collect::<Vec<_>>();
  let mut indexability=std::collections::HashMap::new();
  for row in db.query("SELECT rtrim(u.actor_id,'/'),u.id::bigint,COALESCE(p.indexable,1)::bigint FROM ap_users u LEFT JOIN actor_profile p ON p.actor_key=u.actor_key WHERE rtrim(u.actor_id,'/')=ANY($1::text[])",&[&authors]).await?{indexability.entry(row.get::<_,String>(0)).or_insert((row.get(1),row.get(2)));}
  Ok(Self{rows,indexability})
 }
 pub(super) fn tree_allowed(&self,status:&Value,viewer_owner:i64,identity:&super::SearchIdentity,hidden:&crate::hidden::HiddenSets,depth:usize)->bool{
  if depth>16||super::status_hidden(status,identity,hidden){return false}
  let uri=super::trim(status.get("uri").or_else(||status.get("url")).and_then(Value::as_str).unwrap_or("")).split('#').next().unwrap_or("").trim_end_matches('/');
  let mut author=super::canonical_actor(status["account"].get("uri").or_else(||status["account"].get("url")).and_then(Value::as_str).unwrap_or(""));
  let raw=status.get("bsky_post");let post=raw.map(|value|value.get("post").filter(|value|value.is_object()).unwrap_or(value));
  let at=post.map(|value|super::text(value,"uri")).filter(|uri|!uri.is_empty()).unwrap_or(if uri.starts_with("at://"){uri}else{""});
  if !at.is_empty(){if self.rows[at]["bsky"]!=Value::Bool(true){return false}}
  else if super::local_note_uri(uri){
   if self.rows[uri]["local"]!=Value::Bool(true){return false}author=uri.split("/notes/").next().unwrap_or("").into();
  }else if !uri.is_empty(){
   let event=&self.rows[uri]["event"];let mention=&self.rows[uri]["mention"];
   if event.is_object(){let kind=super::text(event,"type");let action=super::text(event,"action_taken");if action=="deleted"||matches!(kind,"Delete"|"Tombstone"){return false}if let Some(actor)=event["actor_id"].as_str(){author=super::canonical_actor(actor)}}
   else if mention.is_object(){if mention["deleted_at"].as_str().is_some_and(|value|!value.is_empty()){return false}if let Some(actor)=mention["actor_id"].as_str(){author=super::canonical_actor(actor)}}
   else{return false}
  }else{return false}
  if !author.is_empty(){if let Some((owner,indexable))=self.indexability.get(&author){if *owner!=viewer_owner&&*indexable==0{return false}}}
  super::children(status).into_iter().all(|(field,child)|super::descriptor(field,child)||self.tree_allowed(child,viewer_owner,identity,hidden,depth+1))
 }
}

#[cfg(test)]
mod differential_tests {
 use super::*;
 #[tokio::test]
 #[ignore="owned actual Search producer PostgreSQL corpus; explicit fixture inputs required"]
 async fn actual_search_fresh_batch_predicate_equivalence()->Result<()> {
  let reference:Value=serde_json::from_slice(&std::fs::read(std::env::var("VAAK_SEARCH_BATCH_REFERENCE")?)?)?;
  let dburl=std::env::var("VAAK_SEARCH_BATCH_DB")?;let admin=crate::db::connect(&dburl).await?;let mut results=Vec::new();let mut identity_results=Vec::new();
  for capture in reference["captures"].as_array().context("actual captures")?{
   let width=capture["width"].as_str().context("width")?;anyhow::ensure!(matches!(width,"INTEGER"|"BIGINT"));
   let schema=format!("search_batch_{}_{}",width.to_ascii_lowercase(),std::process::id());
   admin.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await?;
   let phase:Result<()>=async{
    for (table,columns) in capture["current_schema"].as_object().context("actual schema")?{
     anyhow::ensure!(table.bytes().all(|b|b.is_ascii_alphanumeric()||b==b'_'));
     let mut definitions=Vec::new();for column in columns.as_array().context("columns")?{let name=column["column_name"].as_str().context("column")?;let kind=column["data_type"].as_str().context("type")?;anyhow::ensure!(name.bytes().all(|b|b.is_ascii_alphanumeric()||b==b'_')&&matches!(kind,"text"|"integer"|"bigint"));definitions.push(format!("{name} {kind}"));}
     admin.batch_execute(&format!("CREATE TABLE {table}({})",definitions.join(","))).await?;
     for row in capture["before_tables"][table].as_array().context("actual rows")?{admin.execute(&format!("INSERT INTO {table} SELECT * FROM json_populate_record(NULL::{table},$1::json)"),&[row]).await?;}
    }
    let sources=capture["collector_calls"][0]["bundle"]["statuses"].as_array().context("actual selected page")?;
    for case in ["unchanged","empty_uri","missing_local","own_indexable_zero","foreign_indexable_zero","foreign_indexable_one","foreign_private","foreign_recipient","missing_child","private_child"]{
     admin.batch_execute("BEGIN").await?;let mut statuses=sources.clone();let uri=statuses[0]["uri"].as_str().context("actual URI")?.to_owned();
     if case.starts_with("foreign_"){
      let foreign=uri.replace("/users/fixture/","/users/other/");statuses[0]["uri"]=Value::String(foreign.clone());statuses[0]["url"]=Value::String(foreign.clone());statuses[0]["account"]["uri"]=Value::String("https://mkultra.monster/users/other".into());statuses[0]["account"]["id"]=Value::String("2".into());statuses[0]["account"]["acct"]=Value::String("other".into());statuses[0]["account"]["username"]=Value::String("other".into());
      admin.execute("UPDATE masto_statuses SET note_id=$1 WHERE note_id=$2",&[&foreign,&uri]).await?;admin.execute("UPDATE outbox_notes SET id=$1 WHERE id=$2",&[&foreign,&uri]).await?;
      let indexable=if case=="foreign_indexable_zero"{0_i64}else{1};admin.execute("INSERT INTO actor_profile(actor_key,indexable) VALUES('other',$1::bigint)",&[&indexable]).await?;
      if matches!(case,"foreign_private"|"foreign_recipient"){
       statuses[0]["visibility"]=Value::String("direct".into());admin.execute("UPDATE masto_statuses SET visibility='direct' WHERE note_id=$1",&[&foreign]).await?;
       let recipients=if case=="foreign_recipient"{"[\"https://mkultra.monster/users/fixture\"]"}else{"[]"};admin.execute("UPDATE outbox_notes SET visibility='direct',to_json=$1,cc_json='[]' WHERE id=$2",&[&recipients,&foreign]).await?;
      }
     }
     match case{
      "empty_uri"=>{statuses[0]["uri"]=Value::String(String::new());statuses[0]["url"]=Value::String(String::new());},
      "missing_local"=>{admin.execute("DELETE FROM masto_statuses WHERE note_id=$1",&[&uri]).await?;},
      "own_indexable_zero"=>{admin.execute("UPDATE actor_profile SET indexable=0 WHERE actor_key='fixture'",&[]).await?;},
      "missing_child"=>{let mut child=statuses[0].clone();child["uri"]=Value::String("https://unknown.test/missing".into());child["url"]=child["uri"].clone();statuses[0]["quote"]=serde_json::json!({"quoted_status":child});},
      "private_child"=>{let mut child=statuses[0].clone();child["visibility"]=Value::String("direct".into());child["uri"]=Value::String("https://unknown.test/private".into());child["url"]=child["uri"].clone();statuses[0]["quote"]=serde_json::json!({"quoted_status":child});},_=>{},
     }
     let mut hidden=crate::hidden::load_hidden_sets(&admin,1).await?;hidden.muted_phrases.clear();let identity=super::super::SearchIdentity{refs:crate::hidden::actor_aliases("https://mkultra.monster/users/fixture").into_iter().map(|value|value.to_ascii_lowercase()).collect()};
     let audience=crate::audience::admission_mask(&admin,1,crate::audience::Surface::Search,&statuses).await?;let mut original=Vec::new();for (status,allowed) in statuses.iter().zip(audience){original.push(allowed&&super::super::current_tree(&admin,1,status,&identity,&hidden,0).await?);}
     let batched=super::super::selected_current_mask(&admin,1,"https://mkultra.monster/users/fixture","fixture",&statuses).await?;
     anyhow::ensure!(original==batched,"fresh batch differs width={width} case={case}");
     let expected=!matches!(case,"empty_uri"|"missing_local"|"foreign_indexable_zero"|"foreign_private"|"missing_child"|"private_child");
     anyhow::ensure!(batched.first()==Some(&expected),"actual current predicate outcome width={width} case={case} expected={expected}");
     results.push(serde_json::json!({"width":width,"case":case,"original":original,"batched":batched,"scope":"actual producer SQL snapshots; controlled current SQL/source extensions; original native recursive predicate versus batched fresh predicate, not a new PHP feature closure"}));admin.batch_execute("ROLLBACK").await?;
    }
    // Separate typed identity binding: actual initial SQL constructs the
    // opaque bridge session, then owner identity changes before context paint.
    admin.batch_execute("BEGIN").await?;
    let initial=admin.query_one("SELECT actor_id,actor_key FROM ap_users WHERE id::bigint=1",&[]).await?;
    let session=super::super::SearchSession::from_php_session(1,"canonical-fixture".into(),false)?.with_bridge_identity(initial.get(0),initial.get(1));
    admin.execute("UPDATE ap_users SET actor_key='new-current-key' WHERE id::bigint=1",&[]).await?;
    let outcome=super::super::admit_for_render_with_preview(&admin,&session,Vec::new(),0,&crate::direct_preview::SystemDns).await;
    anyhow::ensure!(outcome.is_err(),"initial bridge identity changed before assembly accepted");
    identity_results.push(serde_json::json!({"width":width,"outcome":"denied","scope":"actual owner SQL followed by identity replacement; opaque session final binding, empty source diagnostic only"}));
    admin.batch_execute("ROLLBACK").await?;Ok(())
   }.await;
   admin.batch_execute(&format!("ROLLBACK;SET search_path=public;DROP SCHEMA {schema} CASCADE")).await?;phase?;
  }
  let output=std::env::var("VAAK_SEARCH_BATCH_OUTPUT")?;std::fs::write(format!("{output}-bridge-identity2.json"),serde_json::to_vec_pretty(&identity_results)?)?;std::fs::write(output,serde_json::to_vec_pretty(&results)?)?;Ok(())
 }
}

#[cfg(test)] mod caller_withdrawal_tests {
 use super::*;use std::{sync::{Arc,atomic::{AtomicUsize,Ordering}},future::Future,pin::Pin,net::Ipv4Addr};
 struct NoWorkers;
 impl crate::profile_warm::WarmTransport for NoWorkers{
  fn actor_process<'a>(&'a self,_:&'a str)->Pin<Box<dyn Future<Output=bool>+Send+'a>>{Box::pin(async{false})}
  fn media_process(&self)->Pin<Box<dyn Future<Output=bool>+Send+'_>>{Box::pin(async{false})}
 }
 struct MutationDns{url:String,uri:String,path:std::path::PathBuf,mutation:&'static str,calls:AtomicUsize}
 impl crate::direct_preview::PreviewDns for MutationDns{
  fn ipv4<'a>(&'a self,_:&'a str)->Pin<Box<dyn Future<Output=Result<Vec<Ipv4Addr>>>+Send+'a>>{Box::pin(async move{
   self.calls.fetch_add(1,Ordering::SeqCst);let db=crate::db::connect(&self.url).await?;
   match self.mutation{
    "source_deleted"=>{db.execute("DELETE FROM masto_statuses WHERE note_id=$1",&[&self.uri]).await?;},
    "owner_identity"=>{db.execute("UPDATE ap_users SET actor_key='withdrawn' WHERE id::bigint=1",&[]).await?;},
    "cache_replaced"=>{let bytes=std::fs::read(&self.path)?;let replacement=self.path.with_extension("replacement");std::fs::write(&replacement,bytes)?;std::fs::rename(replacement,&self.path)?;},
    "cache_source_deleted"|"cache_owner_identity"=>{},
    "window_reordered"=>{let mut cached:Value=serde_json::from_slice(&std::fs::read(&self.path)?)?;cached["statuses"].as_array_mut().context("candidates")?.swap(0,1);std::fs::write(&self.path,serde_json::to_vec(&cached)?)?;},
    _=>bail!("unknown owned mutation"),
   }Ok(vec![Ipv4Addr::new(8,8,8,8)])
  })}
 }
 #[tokio::test]
 #[ignore="owned PostgreSQL actual Search producer capture; explicit paths required"]
 async fn actual_search_caller_dns_withdrawal()->Result<()>{
  let reference:Value=serde_json::from_slice(&std::fs::read(std::env::var("VAAK_SEARCH_FAULT_REFERENCE")?)?)?;
  let prefix=std::env::var("VAAK_SEARCH_FAULT_REQUEST_PREFIX")?;let dburl=std::env::var("VAAK_SEARCH_FAULT_DB")?;let admin=crate::db::connect(&dburl).await?;
  let original_cache=std::fs::read(std::env::var("VAAK_SEARCH_FAULT_CACHE")?)?;let mut results=Vec::new();
  for capture in reference["captures"].as_array().context("captures")?{
   let width=capture["width"].as_str().context("width")?;anyhow::ensure!(matches!(width,"INTEGER"|"BIGINT"));
   let request_text=std::fs::read_to_string(format!("{prefix}-{}-requests.jsonl",width.to_ascii_lowercase()))?;
   let original:Value=serde_json::from_str(request_text.lines().next().context("actual private request")?)?;
   for mutation in ["source_deleted","owner_identity","cache_replaced","window_reordered","cache_source_deleted","cache_owner_identity"]{
    let schema=format!("search_fault_{}_{}_{mutation}",width.to_ascii_lowercase(),std::process::id());admin.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await?;
    let url=format!("{dburl} options=-csearch_path={schema}");
    let query=format!("search-fault-{}-{mutation}-{}",width.to_ascii_lowercase(),std::process::id());
    let key=format!("{:x}",Sha256::digest(format!("mf3|1|{query}|0|121|80").as_bytes()));let path=std::path::Path::new("/tmp/vaak-search-cache").join(format!("s_{key}.json"));
    let run:Result<Value>=async{
     for(table,columns)in capture["current_schema"].as_object().context("schema")?{
      anyhow::ensure!(table.bytes().all(|b|b.is_ascii_alphanumeric()||b==b'_'));let mut definitions=Vec::new();
      for column in columns.as_array().context("columns")?{let name=column["column_name"].as_str().context("column")?;let kind=column["data_type"].as_str().context("type")?;anyhow::ensure!(name.bytes().all(|b|b.is_ascii_alphanumeric()||b==b'_')&&matches!(kind,"integer"|"bigint"|"text"));definitions.push(format!("{name} {kind}"));}
      admin.batch_execute(&format!("CREATE TABLE {table}({})",definitions.join(","))).await?;
      for row in capture["before_tables"][table].as_array().context("rows")?{admin.execute(&format!("INSERT INTO {table} SELECT * FROM json_populate_record(NULL::{table},$1::json)"),&[row]).await?;}
     }
     let mut body=original["request"].clone();body.as_object_mut().context("body")?.remove("_fixture_path");
     let mut cache:Value=serde_json::from_slice(&original_cache)?;
     // Distinct recorded fault-only URL extension; actual producer identities,
     // candidate ordering and current SQL retained. No card result is supplied.
     body["statuses"][0]["content"]=serde_json::json!("<p>Actual Search source text https://withdrawal-preview.test/page</p>");cache["statuses"][0]=body["statuses"][0].clone();
     let bytes=serde_json::to_vec(&cache)?;std::fs::write(&path,&bytes)?;
     body["collector"]["normalized_query"]=Value::String(query.clone());body["collector"]["cache_location"]=Value::String("temporary".into());body["collector"]["cache_sha256"]=Value::String(format!("{:x}",Sha256::digest(&bytes)));
     let uri=body["statuses"][0]["uri"].as_str().context("URI")?.to_owned();
     if mutation.starts_with("cache_")&&mutation!="cache_replaced"{
      // A fixture-owned volatile SELECT function mutates actual current SQL
      // during the real preview-cache query; it supplies no preview result.
      admin.execute("INSERT INTO link_preview_cards(url,title,description) VALUES('https://withdrawal-preview.test/page','Actual cache fixture','cache query withdrawal')",&[]).await?;
      let effect=if mutation=="cache_source_deleted"{format!("DELETE FROM masto_statuses WHERE note_id='{}';",uri.replace("'","''"))}else{"UPDATE ap_users SET actor_key='withdrawn-at-cache' WHERE id::bigint=1;".into()};
      admin.batch_execute(&format!("ALTER TABLE link_preview_cards RENAME TO actual_preview_cache;CREATE FUNCTION withdraw_during_cache() RETURNS boolean LANGUAGE plpgsql VOLATILE AS $$BEGIN {effect} RETURN true;END$$;CREATE VIEW link_preview_cards AS SELECT c.* FROM actual_preview_cache c WHERE withdraw_during_cache();")).await?;
     }
     let dns=MutationDns{url:url.clone(),uri,path:path.clone(),mutation,calls:0.into()};
     let clock=chrono::DateTime::from_timestamp(1791673200,0).context("clock")?;let deps=crate::direct_preview::PreviewDependencies{dns:&dns,render_clock:Some(clock)};
     let cfg=crate::config::Config{database_url:url.clone(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:Default::default(),default_owner_id:1,bsky_public_api:String::new(),notif_cache_dir:Default::default(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
     let warm=Arc::new(crate::profile_enrichment::WarmContext::new(cfg,Arc::new(NoWorkers)).with_fixture_clock(clock));let mut headers=axum::http::HeaderMap::new();headers.insert("x-vaak-search-token","search-fault-private-token-0000000001".parse()?);
     let response=crate::search_bridge::fixture_request_with_warm(&url,"search-fault-private-token-0000000001",headers,axum::body::Bytes::from(serde_json::to_vec(&body)?),&deps,warm.clone()).await;
     anyhow::ensure!(response.status()==axum::http::StatusCode::UNPROCESSABLE_ENTITY,"native publication not denied");anyhow::ensure!(response.headers().get(axum::http::header::CACHE_CONTROL).and_then(|v|v.to_str().ok())==Some("private, no-store"));
     let reply:Value=serde_json::from_slice(&axum::body::to_bytes(response.into_body(),1024).await?)?;anyhow::ensure!(reply==serde_json::json!({"fallback":true})&&dns.calls.load(Ordering::SeqCst)==1,"actual DNS path");anyhow::ensure!(warm.requested_actors().is_empty()&&warm.requested_media().is_empty(),"unexpected effects");
     Ok(serde_json::json!({"width":width,"mutation":mutation,"native_status":422,"dns_calls":1,"reply":reply,"request":body,"actor_warm":[],"media_warm":[],"scope":"actual current producer/caller SQL with recorded URL/query fault extension; no PHP fallback painting, credential/network or whole feature acceptance"}))
    }.await;
    let _=std::fs::remove_file(path);admin.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await?;results.push(run?);
   }
  }
  std::fs::write(std::env::var("VAAK_SEARCH_FAULT_OUTPUT")?,serde_json::to_vec_pretty(&results)?)?;Ok(())
 }
}

#[cfg(test)]
mod status_admin_aba_fixture { include!("search_status_admin_aba_fixture.rs"); }
