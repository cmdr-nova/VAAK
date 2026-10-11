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
  if descriptor.source_index!=index||!matches!(descriptor.producer_kind.as_str(),"bookmarks_mixed"|"bookmarks_fedi"|"bookmarks_bsky"|"bookmarks_fedi_data"|"bookmarks_bsky_data")
   ||(view!="bookmarks")||descriptor.producer_kind!=first.producer_kind
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
 window:Vec<(String,String)>,folder_uris:Vec<String>,row:Value,network_rows:Option<Vec<Value>>,
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
  Ok(Self{owner,folder,offset,limit,kind:kind.into(),reference:reference.into(),window,folder_uris,row,network_rows:None})
 }
 /// Exact PHP network SQL page, retaining raw ordinal before hydration.
 pub(crate) async fn network(db:&Client,session:&super::context::TrustedSession,folder:i64,offset:i64,limit:i64,ordinal:usize,kind:&str,reference:&str)->Result<Self>{
  if !matches!(kind,"fedi"|"bsky")||reference.is_empty()||reference.len()>2048||folder<0||offset<0||!(10..=40).contains(&limit){anyhow::bail!("invalid saved network source descriptor")}
  if ordinal>=limit as usize{anyhow::bail!("saved network sentinel is not an emitted source")}
  let owner=session.owner_id();let rows=Self::network_rows(db,owner,folder,offset,limit,kind).await?;
  let keys=rows.iter().map(|row|Ok((kind.to_owned(),if kind=="fedi"{row["id"].as_i64().context("network saved row ID")?.to_string()}else{row["bookmark_uri"].as_str().context("network saved URI")?.to_owned()}))).collect::<Result<Vec<_>>>()?;
  if keys.get(ordinal).map(|key|(key.0.as_str(),key.1.as_str()))!=Some((kind,reference)){anyhow::bail!("saved network source not at current raw ordinal")}
  let row=rows.get(ordinal).context("network source row")?.clone();
  Ok(Self{owner,folder,offset,limit,kind:kind.into(),reference:reference.into(),window:keys,folder_uris:Vec::new(),row,network_rows:Some(rows)})
 }
 async fn network_rows(db:&Client,owner:i64,folder:i64,offset:i64,limit:i64,kind:&str)->Result<Vec<Value>>{
  let sql=if kind=="fedi"{r#"SELECT to_jsonb(b) FROM masto_bookmarks b WHERE b.owner_user_id::bigint=$1
   AND b.status_id NOT LIKE 'bsky:%' AND b.object_id NOT LIKE 'at://%' AND b.object_id NOT LIKE '%bsky.app/%'
   AND ($2::bigint=0 OR EXISTS(SELECT 1 FROM vaak_bookmark_folder_items fi WHERE fi.folder_id::bigint=$2 AND fi.owner_user_id=b.owner_user_id AND (fi.status_id=b.status_id OR fi.status_id=b.object_id)))
   ORDER BY b.created_at DESC,b.id DESC OFFSET $3 LIMIT $4"#}else{r#"SELECT to_jsonb(c) FROM bsky_bookmark_cache c WHERE c.owner_user_id::bigint=$1
   AND ($2::bigint=0 OR EXISTS(SELECT 1 FROM vaak_bookmark_folder_items fi LEFT JOIN masto_bookmarks mb ON mb.owner_user_id=c.owner_user_id AND mb.status_id=fi.status_id WHERE fi.folder_id::bigint=$2 AND fi.owner_user_id=c.owner_user_id AND (fi.status_id=c.bookmark_uri OR mb.object_id=c.bookmark_uri)))
   ORDER BY c.bookmarked_at DESC,c.bookmark_uri DESC OFFSET $3 LIMIT $4"#};
  Ok(db.query(sql,&[&owner,&folder,&offset,&(limit+1)]).await?.into_iter().map(|row|row.get(0)).collect())
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
  Ok(db.query(super::mixed::MIXED_KEYS_SQL,&[&owner,&folder,uris,&offset,&(limit+1)]).await?.into_iter().take(limit as usize).map(|row|(row.get(0),row.get(1))).collect())
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
  if let Some(rows)=&self.network_rows{if Self::network_rows(db,self.owner,self.folder,self.offset,self.limit,&self.kind).await?.as_slice()!=rows.as_slice(){anyhow::bail!("saved network current raw window changed")}return Ok(())}
  let uris=Self::folder_uris(db,self.owner,self.folder).await?;
  if uris!=self.folder_uris||Self::window(db,self.owner,self.folder,self.offset,self.limit,&uris).await?!=self.window||Self::row(db,self.owner,&self.kind,&self.reference).await?.as_ref()!=Some(&self.row){anyhow::bail!("saved owner/folder/window/source association changed")}
  Ok(())
 }
}
