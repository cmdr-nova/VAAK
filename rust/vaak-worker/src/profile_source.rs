//! Current local-profile provider ledger. Every selected row, including rows
//! omitted by the painter, is bound before materialization and checked again
//! before the private batch can publish HTML or preview state.
use anyhow::{bail,Context,Result};
use serde_json::Value;
use tokio_postgres::Client;
pub(crate) struct SelectedProfilePage {
    rows:Vec<Value>,
    page:crate::profile_context::ProfilePage,
    actor_key:String,source_actor:String,tab:String,ordinals:Vec<usize>,
}
impl SelectedProfilePage {
    pub(crate) fn actor_key(&self)->&str{&self.actor_key}
    pub(crate) fn source_actor(&self)->&str{&self.source_actor}
    pub(crate) fn tab(&self)->&str{&self.tab}
    pub(crate) fn rows(&self)->&[Value]{&self.rows}
    pub(crate) fn page(&self)->&crate::profile_context::ProfilePage{&self.page}
    pub(crate) fn into_parts(self)->(Vec<Value>,crate::profile_context::ProfilePage){(self.rows,self.page)}
    pub(crate) fn into_indexed_parts(self)->(Vec<(usize,Value)>,crate::profile_context::ProfilePage){(self.ordinals.into_iter().zip(self.rows).collect(),self.page)}
    pub(crate) fn raw_subset(page:crate::profile_context::ProfilePage,rows:Vec<(usize,Value)>,key:&str,actor:&str)->Result<Self>{
        page.require_target(key,actor)?;let mut previous=None;
        for(index,row)in &rows{if previous.is_some_and(|p|*index<=p){bail!("raw subset ordinals are not increasing")}page.require_source_row(*index,row)?;if !row.get("id").is_some_and(Value::is_string)||row.get("type").is_some(){bail!("raw subset is not selected outbox source")}previous=Some(*index);}
        let(ordinals,rows)=rows.into_iter().unzip();Ok(Self{rows,page,actor_key:key.into(),source_actor:actor.into(),tab:"raw-subset".into(),ordinals})
    }
}
#[derive(Clone,PartialEq)]
pub(crate) struct SourceLedger {table:&'static str,rows:Vec<Value>,actor_key:String,source_actor:String,ordinals:Option<Vec<usize>>,secondary:Option<SecondaryLedger>}
#[derive(Clone,PartialEq)]
struct SecondaryLedger {kind:&'static str,owner:Option<Value>,selected:Vec<Value>,preload:Vec<Value>,missing:Vec<Value>}
async fn secondary_owner(db:&Client,key:&str,actor:&str)->Result<Option<Value>>{Ok(db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE lower(actor_key)=$1 OR actor_id=$2 OR actor_id=$3 LIMIT 1",&[&key,&actor,&format!("{actor}/")]).await?.map(|row|row.get(0)))}
async fn secondary_rows(db:&Client,key:&str,owner:i64,kind:&str)->Result<(Vec<Value>,Vec<Value>,Vec<Value>)>{
 if owner<1{return Ok((vec![],vec![],vec![]))}
 if kind=="pinned"{
  let prefix=format!("https://mkultra.monster/users/{key}/notes/%");let rows=db.query("SELECT to_jsonb(s) FROM masto_pins p INNER JOIN masto_statuses s ON s.local_id=p.status_local_id WHERE p.owner_user_id=$1::bigint AND s.note_id LIKE $2 ORDER BY p.pinned_at DESC LIMIT 5",&[&owner,&prefix]).await?.into_iter().map(|row|row.get(0)).collect();return Ok((rows,vec![],vec![]))
 }
 let asks:Vec<Value>=db.query("SELECT jsonb_build_object('answer_note_id',answer_note_id,'question',question,'asker_actor',asker_actor,'created_at',created_at) FROM ap_asks WHERE owner_user_id=$1::bigint AND answered=1 AND answer_note_id IS NOT NULL AND answer_note_id<>'' ORDER BY created_at DESC LIMIT 200",&[&owner]).await?.into_iter().map(|row|row.get(0)).collect();
 let prefix=format!("https://mkultra.monster/users/{key}/%");let preload:Vec<Value>=db.query("SELECT to_jsonb(n) FROM outbox_notes n WHERE id LIKE $1 ORDER BY published DESC LIMIT 80",&[&prefix]).await?.into_iter().map(|row|row.get(0)).collect();
 let known=preload.iter().filter_map(|row|row["id"].as_str()).map(|id|id.trim_end_matches('/')).collect::<std::collections::HashSet<_>>();
 let missing_ids=asks.iter().filter_map(|ask|ask["answer_note_id"].as_str()).map(|id|id.trim_end_matches('/')).filter(|id|!id.is_empty()&&!known.contains(id)).flat_map(|id|[id.to_owned(),format!("{id}/")]).collect::<Vec<_>>();
 let missing=if missing_ids.is_empty(){vec![]}else{db.query("SELECT to_jsonb(n) FROM outbox_notes n WHERE id=ANY($1)",&[&missing_ids]).await?.into_iter().map(|row|row.get(0)).collect()};Ok((asks,preload,missing))
}
impl SourceLedger {
    pub(crate) fn require_source_row(&self,index:usize,row:&Value)->Result<()>{let position=self.ordinals.as_ref().map(|ordinals|ordinals.iter().position(|ordinal|*ordinal==index)).unwrap_or(Some(index));if position.and_then(|position|self.rows.get(position))!=Some(row){bail!("PHP fallback: raw profile subset differs from original selected row")}Ok(())}
    pub(crate) fn require_target(&self,key:&str,actor:&str)->Result<()>{if self.actor_key!=key||self.source_actor!=actor{bail!("PHP fallback: profile selection target binding differs")}Ok(())}
    pub(crate) async fn require_current(&self,db:&Client)->Result<()> {
        if let Some(secondary)=&self.secondary{
         let owner=secondary_owner(db,&self.actor_key,&self.source_actor).await?;if owner!=secondary.owner{bail!("PHP fallback: secondary profile target changed")}
         let owner=owner.as_ref().and_then(|row|row["id"].as_i64()).unwrap_or(0);let(selected,preload,missing)=secondary_rows(db,&self.actor_key,owner,secondary.kind).await?;
         if selected!=secondary.selected||preload!=secondary.preload||missing!=secondary.missing{bail!("PHP fallback: secondary profile selection changed before publication")}
        }
        let current:Vec<Value>=if self.table=="outbox_notes"{let ids=self.rows.iter().map(|row|row["id"].as_str().context("selected outbox identity is not a string").map(str::to_owned)).collect::<Result<Vec<_>>>()?;db.query("SELECT to_jsonb(n) FROM outbox_notes n WHERE id=ANY($1)",&[&ids]).await?.into_iter().map(|row|row.get(0)).collect()}else{let field=if self.table=="masto_statuses"{"local_id"}else{"id"};let ids=self.rows.iter().map(|row|row[field].as_i64().context("selected integral source identity missing")).collect::<Result<Vec<_>>>()?;let sql=if self.table=="masto_statuses"{"SELECT to_jsonb(s) FROM masto_statuses s WHERE local_id=ANY($1::bigint[])"}else{"SELECT to_jsonb(e) FROM events e WHERE id=ANY($1::bigint[])"};db.query(sql,&[&ids]).await?.into_iter().map(|row|row.get(0)).collect()};
        for expected in &self.rows{if !current.iter().any(|row|row==expected){bail!("PHP fallback: selected profile ledger changed before publication")}}
        Ok(())
    }
}
/// Same rule as the public reply count: NULL and blank `in_reply_to` are posts.
pub(crate) const PROFILE_POSTS_REPLY_SQL: &str = "TRIM(COALESCE(in_reply_to, '')) = ''";
/// Any other `in_reply_to` is a reply, including whitespace-padded URLs.
pub(crate) const PROFILE_REPLIES_REPLY_SQL: &str = "TRIM(COALESCE(in_reply_to, '')) <> ''";
pub(crate) async fn select(db:&Client,key:&str,actor:&str,tab:&str,limit:i64,offset:i64)->Result<SelectedProfilePage>{
    let normalized_key=key.trim().to_ascii_lowercase();let key=normalized_key.as_str();
    if key.is_empty()||!key.bytes().all(|b|b.is_ascii_lowercase()||b.is_ascii_digit()||b==b'_'){bail!("invalid canonical profile source key")}
    if !(1..=50).contains(&limit)||offset<0{bail!("invalid canonical profile paging")}
    let canonical=format!("https://mkultra.monster/users/{key}");let requested=crate::library::card::php_trim(actor).trim_end_matches('/');let selected_actor=if requested.is_empty(){canonical.as_str()}else{requested}.to_owned();
    let table=if tab=="boosts"{"events"}else{"outbox_notes"};
    let mut rows:Vec<Value>=if tab=="boosts" {
        let canonical=format!("https://mkultra.monster/users/{key}");let requested=actor.trim().trim_end_matches('/');let actor=if requested.is_empty(){canonical.as_str()}else{requested};let slash=format!("{actor}/");
        db.query("SELECT to_jsonb(e) FROM (SELECT DISTINCT ON(object_id) * FROM events WHERE type='Announce' AND actor_id IN ($1,$2) ORDER BY object_id,CASE WHEN action_taken='boost_ok' THEN 0 ELSE 1 END,created_at DESC,id DESC) e ORDER BY created_at DESC,id DESC LIMIT $3 OFFSET $4",&[&actor,&slash,&(limit+1),&offset]).await?
    }else{
        let predicate=match tab{"posts"=>PROFILE_POSTS_REPLY_SQL,"replies"=>PROFILE_REPLIES_REPLY_SQL,"media"=>"raw_create_json LIKE '%\"attachment\"%'",_=>bail!("invalid canonical profile tab")};
        let prefix=format!("https://mkultra.monster/users/{key}/%");
        db.query(&format!("SELECT to_jsonb(n) FROM outbox_notes n WHERE id LIKE $1 AND COALESCE(visibility,'public')='public' AND {predicate} ORDER BY published DESC LIMIT $2 OFFSET $3"),&[&prefix,&(limit+1),&offset]).await?
    }.into_iter().map(|row|row.get(0)).collect();
    let more=rows.len()>limit as usize;rows.truncate(limit as usize);
    let page=crate::profile_context::ProfilePage::from_current_ledger(offset,limit,rows.len(),more,SourceLedger{table,rows:rows.clone(),actor_key:key.into(),source_actor:selected_actor.clone(),ordinals:None,secondary:None})?;
    Ok(SelectedProfilePage{ordinals:(0..rows.len()).collect(),rows,page,actor_key:key.into(),source_actor:selected_actor,tab:tab.into()})
}
/// Secondary membership does not grant access: pins retain private/orphan
/// Mastodon sources; Asks retain duplicate answer ordinals and missing rows.
pub(crate) async fn select_secondary(db:&Client,key:&str,actor:&str,kind:&'static str)->Result<SelectedProfilePage>{
 if !matches!(kind,"pinned"|"asks")||key.is_empty()||!key.bytes().all(|byte|byte.is_ascii_lowercase()||byte.is_ascii_digit()||byte==b'_'){bail!("invalid canonical secondary profile selection")}
 let actor=crate::library::card::php_trim(actor).trim_end_matches('/');let owner=secondary_owner(db,key,actor).await?;let owner_id=owner.as_ref().and_then(|row|row["id"].as_i64()).unwrap_or(0);
 let(selected,preload,missing)=secondary_rows(db,key,owner_id,kind).await?;let count=selected.len();
 let(rows,ordinals)=if kind=="pinned"{(selected.clone(),(0..selected.len()).collect::<Vec<_>>())}else{
  let mut by_note=std::collections::HashMap::new();for row in preload.iter().chain(&missing){if let Some(id)=row["id"].as_str(){if !id.is_empty(){by_note.insert(id.trim_end_matches('/'),row.clone());}}}
  let mut rows=Vec::new();let mut ordinals=Vec::new();for(index,ask)in selected.iter().enumerate(){if let Some(id)=ask["answer_note_id"].as_str(){if let Some(note)=by_note.get(id.trim_end_matches('/')){rows.push(note.clone());ordinals.push(index);}}}(rows,ordinals)
 };
 let ledger=SourceLedger{table:if kind=="pinned"{"masto_statuses"}else{"outbox_notes"},rows:rows.clone(),actor_key:key.into(),source_actor:actor.into(),ordinals:Some(ordinals.clone()),secondary:Some(SecondaryLedger{kind,owner,selected,preload,missing})};
 let page=crate::profile_context::ProfilePage::from_secondary_ledger(count,ledger)?;page.require_current_ledger(db).await?;Ok(SelectedProfilePage{rows,page,actor_key:key.into(),source_actor:actor.into(),tab:kind.into(),ordinals})
}

#[cfg(test)]
mod tests {
 use super::*;
 #[test]
 fn profile_reply_predicates_match_the_count_rule() {
  assert_eq!(PROFILE_POSTS_REPLY_SQL, "TRIM(COALESCE(in_reply_to, '')) = ''");
  assert_eq!(PROFILE_REPLIES_REPLY_SQL, "TRIM(COALESCE(in_reply_to, '')) <> ''");
 }
 #[tokio::test]
 #[ignore = "opt-in disposable PostgreSQL genuine Boost source ledger and omitted-row mutation replay"]
 async fn actual_profile_selected_ledger_replay(){
  let connection=std::env::var("LIBRARY_FIXTURE_DB").expect("disposable local PostgreSQL");assert!(connection.contains("/tmp/"));
  let input:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-boosts-reference.json").unwrap()).unwrap();
  let db=crate::db::connect(&connection).await.unwrap();let mut matched=0;let mut output=Vec::new();
  for width in ["INTEGER","BIGINT"] {
   let schema=format!("profile_selected_ledger_{}_{}",width.to_ascii_lowercase(),std::process::id());
   db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
   for (table,columns) in input["current_schema_by_width"][width].as_object().unwrap(){
    let definition=columns.as_array().unwrap().iter().map(|column|format!("{} {}",column["column_name"].as_str().unwrap(),column["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");
    db.batch_execute(&format!("CREATE TABLE {table}({definition})")).await.unwrap();
   }
   for captured in input["captures"].as_array().unwrap().iter().filter(|row|row["width"]==width){
    for(table,rows)in captured["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
    let selected=select(&db,captured["input"]["key"].as_str().unwrap(),captured["input"]["actor"].as_str().unwrap(),"boosts",captured["paging"]["limit"].as_i64().unwrap(),captured["paging"]["offset"].as_i64().unwrap()).await.unwrap();
    assert_eq!(Value::Array(selected.rows.clone()),captured["page"]["items"],"{width} {:?}",captured["input"]);
    assert_eq!(selected.page.next_offset(),captured["page"]["next_offset"].as_i64().unwrap());assert_eq!(selected.page.has_more(),captured["page"]["has_more"].as_bool().unwrap());
    output.push(serde_json::json!({"width":width,"state":captured["state"],"input":captured["input"],"paging":captured["paging"],"page":{"items":selected.rows,"has_more":selected.page.has_more(),"next_offset":selected.page.next_offset()}}));
    selected.page.require_current_ledger(&db).await.unwrap();
    if let Some(last)=selected.rows.last(){let id=last["id"].as_i64().unwrap();db.execute("UPDATE events SET summary=COALESCE(summary,'')||' withdrawn after later lookup' WHERE id=$1::bigint",&[&id]).await.unwrap();assert!(selected.page.require_current_ledger(&db).await.is_err(),"omitted-last source mutation must invalidate complete batch");}
    matched+=1;
   }
   db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
  }
  std::fs::write("/tmp/vaak-profile-selected-ledger-native.json",serde_json::to_vec(&output).unwrap()).unwrap();
  assert_eq!(matched,216);println!("216 genuine provider rows/pages and selected-row mutation bindings passed");
 }
}
#[cfg(test)]
#[path="profile_secondary_fixture.rs"]
mod secondary_fixture;
