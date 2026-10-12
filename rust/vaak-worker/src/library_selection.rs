//! Actual saved producer association, independent of canonical public-note
//! admission. Only current owner SQL can construct this private capability.
use anyhow::{Context,Result};
use serde_json::Value;
use tokio_postgres::Client;

#[derive(serde::Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct SavedSourceDescriptor {
 pub(crate) source_index:usize,pub(crate) producer_kind:String,
 pub(crate) selected_kind:String,pub(crate) reference:String,
 pub(crate) selected_ordinal:usize,
 pub(crate) folder_id:i64,pub(crate) offset:i64,pub(crate) limit:i64,
}

/// Validate the entire enclosing batch before constructing any SQL capability
/// or permitting effects for an earlier item. Empty descriptors retain the
/// explicit unported/diagnostic caller boundary; a partial vector is invalid.
pub(crate) fn validate_descriptors(descriptors:&[SavedSourceDescriptor],source_count:usize,view:&str)->Result<()> {
 if descriptors.is_empty(){return Ok(())}
 if descriptors.len()!=source_count||!matches!(view,"bookmarks"|"favourites"){anyhow::bail!("incomplete saved producer/source vector")}
 let first=&descriptors[0];let mut previous=None;let mut keys=std::collections::HashSet::new();
 for (index,descriptor) in descriptors.iter().enumerate(){
  if descriptor.source_index!=index||!matches!(descriptor.producer_kind.as_str(),"bookmarks_mixed"|"bookmarks_fedi"|"bookmarks_bsky"|"bookmarks_fedi_data"|"bookmarks_bsky_data"|"favourites_fedi"|"favourites_fedi_data")
   ||((descriptor.producer_kind.starts_with("bookmarks_") && view!="bookmarks") || (descriptor.producer_kind.starts_with("favourites_") && view!="favourites"))||descriptor.producer_kind!=first.producer_kind
   ||(descriptor.folder_id,descriptor.offset,descriptor.limit)!=(first.folder_id,first.offset,first.limit)
   ||previous.is_some_and(|ordinal|descriptor.selected_ordinal<=ordinal)
   ||!keys.insert((descriptor.selected_kind.as_str(),descriptor.reference.as_str())) {
   anyhow::bail!("invalid saved producer batch order/window")
  }
  previous=Some(descriptor.selected_ordinal);
 }
 Ok(())
}

pub(crate) struct SavedEntryGrant {
 owner:i64,folder:i64,offset:i64,limit:i64,kind:String,reference:String,
 window:Vec<(String,String)>,folder_uris:Vec<String>,row:Value,network_rows:Option<Vec<Value>>,favourite:bool,
    canonical_queries:Option<std::sync::Arc<CanonicalRequestQueries>>,
}

/// Prepared SQL belongs to this one canonical request and its retained Client.
/// Keeping the Arc prevents its address from being recycled; another Client,
/// including a clone, is refused. Every query still executes against current
/// rows, and saved-window guards still compare the complete ordered JSON.
pub(super) struct CanonicalRequestQueries {
 db:std::sync::Arc<Client>,window:tokio_postgres::Statement,facts:tokio_postgres::Statement,
}
impl CanonicalRequestQueries {
 pub(super) async fn new(db:std::sync::Arc<Client>)->Result<std::sync::Arc<Self>>{
  let window=db.prepare(&SavedEntryGrant::network_rows_sql("fedi")).await?;
  let facts=db.prepare(super::context::CANONICAL_SAVED_LOCAL_FACTS_SQL).await?;
  Ok(std::sync::Arc::new(Self{db,window,facts}))
 }
 fn require_client(&self,db:&Client)->Result<()>{
  if !std::ptr::eq(self.db.as_ref(),db){anyhow::bail!("canonical prepared query Client changed")};Ok(())
 }
 pub(super) async fn source_facts(&self,db:&Client,local:i64)->Result<Option<tokio_postgres::Row>>{
  self.require_client(db)?;Ok(db.query_opt(&self.facts,&[&local]).await?)
 }
 async fn network_rows(&self,db:&Client,owner:i64,folder:i64,offset:i64,limit:i64)->Result<Vec<Value>>{
  self.require_client(db)?;
  Ok(db.query(&self.window,&[&owner,&folder,&offset,&(limit+1)]).await?.into_iter().map(|row|row.get(0)).collect())
 }
}
impl SavedEntryGrant {
 /// Pointer is transport provenance, not authority: independently rerun the
 /// actual mixed-page SQL and require this key in its selected current window.
 pub(crate) async fn mixed(db:&Client,session:&super::context::TrustedSession,folder:i64,offset:i64,limit:i64,ordinal:usize,kind:&str,reference:&str)->Result<Self>{
  if !matches!(kind,"fedi"|"bsky")||reference.is_empty()||reference.len()>2048||folder<0||offset<0||!(10..=40).contains(&limit){anyhow::bail!("invalid saved mixed source descriptor")}
  let owner=session.owner_id();
  let folder_uris=Self::folder_uris(db,owner,folder).await?;
  let window=Self::window(db,owner,folder,offset,limit,&folder_uris).await?;
  if window.get(ordinal).map(|key|(key.0.as_str(),key.1.as_str()))!=Some((kind,reference)){anyhow::bail!("saved entry not at current owner window ordinal")}
  let row=Self::row(db,owner,kind,reference).await?.context("saved owner entry withdrawn")?;
  Ok(Self{owner,folder,offset,limit,kind:kind.into(),reference:reference.into(),window,folder_uris,row,network_rows:None,favourite:false,canonical_queries:None})
 }
 /// Exact PHP network SQL page, retaining raw ordinal before hydration.
 pub(crate) async fn network(db:&Client,session:&super::context::TrustedSession,folder:i64,offset:i64,limit:i64,ordinal:usize,kind:&str,reference:&str)->Result<Self>{
  if !matches!(kind,"fedi"|"bsky")||reference.is_empty()||reference.len()>2048||folder<0||offset<0||!(10..=40).contains(&limit){anyhow::bail!("invalid saved network source descriptor")}
  if ordinal>=limit as usize{anyhow::bail!("saved network sentinel is not an emitted source")}
  let canonical_queries=if kind=="fedi"{session.canonical_queries().cloned()}else{None};
  let owner=session.owner_id();let rows=if let Some(queries)=&canonical_queries{queries.network_rows(db,owner,folder,offset,limit).await?}else{Self::network_rows(db,owner,folder,offset,limit,kind).await?};
  let keys=rows.iter().map(|row|Ok((kind.to_owned(),if kind=="fedi"{row["id"].as_i64().context("network saved row ID")?.to_string()}else{row["bookmark_uri"].as_str().context("network saved URI")?.to_owned()}))).collect::<Result<Vec<_>>>()?;
  if keys.get(ordinal).map(|key|(key.0.as_str(),key.1.as_str()))!=Some((kind,reference)){anyhow::bail!("saved network source not at current raw ordinal")}
  let row=rows.get(ordinal).context("network source row")?.clone();
  Ok(Self{owner,folder,offset,limit,kind:kind.into(),reference:reference.into(),window:keys,folder_uris:Vec::new(),row,network_rows:Some(rows),favourite:false,canonical_queries})
 }
 /// Favourite selection differs from bookmark SQL paging: retain the whole
 /// raw hydration window, including omitted rows and unpainted candidates.
 pub(crate) async fn favourite(db:&Client,session:&super::context::TrustedSession,offset:i64,limit:i64,ordinal:usize,reference:&str)->Result<Self>{
  if offset<0||!(10..=40).contains(&limit)||reference.is_empty()||reference.len()>32{anyhow::bail!("invalid favourite source window")}
  let owner=session.owner_id();let rows=Self::favourite_rows(db,owner,offset,limit).await?;
  let keys=rows.iter().map(|row|Ok(("fedi".to_owned(),row["id"].as_i64().context("favourite raw row ID")?.to_string()))).collect::<Result<Vec<_>>>()?;
  if keys.get(ordinal).map(|key|key.1.as_str())!=Some(reference){anyhow::bail!("favourite source is not at its current raw ordinal")}
  let row=rows.get(ordinal).context("favourite source row")?.clone();
  Ok(Self{owner,folder:0,offset,limit,kind:"fedi".into(),reference:reference.into(),window:keys,folder_uris:Vec::new(),row,network_rows:Some(rows),favourite:true,canonical_queries:None})
 }
 async fn favourite_rows(db:&Client,owner:i64,offset:i64,limit:i64)->Result<Vec<Value>>{
  let fetch=offset.saturating_add(limit).saturating_add(20).clamp(1,80)*2;
  Ok(db.query("SELECT to_jsonb(f) FROM masto_favourites f WHERE f.owner_user_id::bigint=$1 ORDER BY f.created_at DESC LIMIT $2",&[&owner,&fetch]).await?.into_iter().map(|row|row.get(0)).collect())
 }
 async fn network_rows(db:&Client,owner:i64,folder:i64,offset:i64,limit:i64,kind:&str)->Result<Vec<Value>>{
  let sql=Self::network_rows_sql(kind);
  Ok(db.query(&sql,&[&owner,&folder,&offset,&(limit+1)]).await?.into_iter().map(|row|row.get(0)).collect())
 }
 fn network_rows_sql(kind:&str)->String{
  let folder_match=super::folder_fedi_match_sql();
  if kind=="fedi"{format!(r#"SELECT to_jsonb(b) FROM masto_bookmarks b WHERE b.owner_user_id::bigint=$1
   AND b.status_id NOT LIKE 'bsky:%' AND b.object_id NOT LIKE 'at://%' AND b.object_id NOT LIKE '%bsky.app/%'
   AND ($2::bigint=0 OR EXISTS(SELECT 1 FROM vaak_bookmark_folder_items fi WHERE fi.folder_id::bigint=$2 AND fi.owner_user_id=b.owner_user_id AND {folder_match}))
   ORDER BY b.created_at DESC,b.id DESC OFFSET $3 LIMIT $4"#)}else{r#"SELECT to_jsonb(c) FROM bsky_bookmark_cache c WHERE c.owner_user_id::bigint=$1
   AND ($2::bigint=0 OR EXISTS(SELECT 1 FROM vaak_bookmark_folder_items fi LEFT JOIN masto_bookmarks mb ON mb.owner_user_id=c.owner_user_id AND mb.status_id=fi.status_id WHERE fi.folder_id::bigint=$2 AND fi.owner_user_id=c.owner_user_id AND (fi.status_id=c.bookmark_uri OR mb.object_id=c.bookmark_uri)))
   ORDER BY c.bookmarked_at DESC,c.bookmark_uri DESC OFFSET $3 LIMIT $4"#.to_owned()}
 }
 async fn folder_uris(db:&Client,owner:i64,folder:i64)->Result<Vec<String>>{
  if folder==0{return Ok(Vec::new())}
  let mut uris=Vec::new();
  for row in db.query("SELECT mb.object_id FROM vaak_bookmark_folder_items fi JOIN masto_bookmarks mb ON mb.owner_user_id=fi.owner_user_id AND mb.status_id=fi.status_id WHERE fi.folder_id::bigint=$1 AND fi.owner_user_id::bigint=$2 AND mb.status_id LIKE 'bsky:%'",&[&folder,&owner]).await?{
   if let Some(uri)=super::mixed::bsky_at_uri(&row.get::<_,Option<String>>(0).unwrap_or_default()){if !uris.contains(&uri){uris.push(uri)}}
  }
  uris.sort();Ok(uris)
 }
 async fn window(db:&Client,owner:i64,folder:i64,offset:i64,limit:i64,uris:&Vec<String>)->Result<Vec<(String,String)>>{
  let sql=super::mixed::mixed_keys_sql();
  Ok(db.query(&sql,&[&owner,&folder,uris,&offset,&(limit+1)]).await?.into_iter().take(limit as usize).map(|row|(row.get(0),row.get(1))).collect())
 }
 async fn row(db:&Client,owner:i64,kind:&str,reference:&str)->Result<Option<Value>>{
  if kind=="fedi"{
   let id=reference.parse::<i64>().context("saved row ID is not an integer")?;
   if id<1||id.to_string()!=reference{anyhow::bail!("noncanonical saved row ID")}
   Ok(db.query_opt("SELECT to_jsonb(b) FROM masto_bookmarks b WHERE b.owner_user_id::bigint=$1 AND b.id::bigint=$2",&[&owner,&id]).await?.map(|row|row.get(0)))
  }else{Ok(db.query_opt("SELECT to_jsonb(c) FROM bsky_bookmark_cache c WHERE c.owner_user_id::bigint=$1 AND c.bookmark_uri=$2",&[&owner,&reference]).await?.map(|row|row.get(0)))}
 }
 pub(crate) fn row_snapshot(&self)->&Value{&self.row}
 pub(crate) fn kind(&self)->&str{&self.kind}
 pub(crate) fn reference(&self)->&str{&self.reference}
 pub(crate) fn owner_id(&self)->i64{self.owner}
 pub(super) fn canonical_queries(&self)->Option<&std::sync::Arc<CanonicalRequestQueries>>{self.canonical_queries.as_ref()}
 pub(crate) fn emitted_network_count(&self)->Option<usize>{self.network_rows.as_ref().filter(|_|!self.favourite).map(|rows|rows.len().min(self.limit as usize))}
 pub(crate) fn require_source_binding(&self,status:&Value)->Result<()> {
  if self.kind=="bsky"{
   let post=status.get("post").or_else(||status.get("bsky_post_view")).or_else(||status.get("bsky_post").map(|value|value.get("post").unwrap_or(value)));
   if post.and_then(|post|post.get("uri")).and_then(Value::as_str)!=Some(self.reference.as_str()){anyhow::bail!("saved Bluesky source pointer changed")}
  }else{
   let sid=self.row.get("status_id").and_then(Value::as_str).unwrap_or("");
   if super::card::scalar_string(status,"id")!=sid{anyhow::bail!("saved incoming action SID differs from selected owner row")}
   let object=self.row.get("object_id").and_then(Value::as_str).unwrap_or("");
   if object.starts_with("https://"){
    let object=super::card::php_trim(object).trim_end_matches('/');
    let object=regex::Regex::new(r"#announce-[0-9]+$").unwrap().replace(object,"");
    let displayed=status.get("reblog").filter(|value|value.is_object()&&status.get("content").and_then(Value::as_str).unwrap_or("").is_empty()).unwrap_or(status);
    let uri=displayed.get("uri").or_else(||displayed.get("url")).and_then(Value::as_str).unwrap_or("").trim_end_matches('/');
    if uri!=object.trim_end_matches('/'){anyhow::bail!("PHP fallback: saved alias requires actual bound canonical lookup")}
   }
  }
  Ok(())
 }
 pub(crate) async fn require_current(&self,db:&Client)->Result<()> {
  if let Some(rows)=&self.network_rows{let current=if self.favourite{Self::favourite_rows(db,self.owner,self.offset,self.limit).await?}else if let Some(queries)=&self.canonical_queries{queries.network_rows(db,self.owner,self.folder,self.offset,self.limit).await?}else{Self::network_rows(db,self.owner,self.folder,self.offset,self.limit,&self.kind).await?};if current.as_slice()!=rows.as_slice(){anyhow::bail!("saved network current raw window changed")}return Ok(())}
  let uris=Self::folder_uris(db,self.owner,self.folder).await?;
  if uris!=self.folder_uris||Self::window(db,self.owner,self.folder,self.offset,self.limit,&uris).await?!=self.window||Self::row(db,self.owner,&self.kind,&self.reference).await?.as_ref()!=Some(&self.row){anyhow::bail!("saved owner/folder/window/source association changed")}
  Ok(())
 }
}
