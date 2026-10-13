//! A request-private raw witness around existing Search predicates. No policy
//! decision is cached: the last SQL statement rereads all scoped dependencies
//! together, and equality plus presentation snapshot checks are synchronous.
use anyhow::{bail,Result};
use serde_json::Value;
use std::collections::HashSet;
use tokio_postgres::Client;

// Original PHP following helpers return false/empty for absent or legacy
// ownerless tables. Catalog errors and supported-table query errors remain fatal.
// The final raw statement also captures this exact shape, including absence.
const PREVIEW_SHAPE_SQL:&str="SELECT COALESCE(jsonb_agg(jsonb_build_object('relation',c.oid::bigint,'name',a.attname,'type',a.atttypid::bigint) ORDER BY a.attnum),'[]'::jsonb) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_attribute a ON a.attrelid=c.oid AND a.attnum>0 AND NOT a.attisdropped WHERE c.oid=to_regclass('link_preview_cards')";
const TAG_SHAPE_SQL:&str="SELECT COALESCE(jsonb_agg(jsonb_build_object('relation',c.oid::bigint,'name',a.attname,'type',a.atttypid::bigint) ORDER BY a.attnum),'[]'::jsonb) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_attribute a ON a.attrelid=c.oid AND a.attnum>0 AND NOT a.attisdropped WHERE c.oid=to_regclass('masto_followed_tags')";
pub(crate) async fn followed_tag_shape(db:&Client)->Result<Value>{Ok(db.query_one(TAG_SHAPE_SQL,&[]).await?.try_get(0)?)}
pub(crate) fn tag_shape_supported(shape:&Value)->bool{let names=shape.as_array().into_iter().flatten().filter_map(|v|v.get("name").and_then(Value::as_str)).collect::<HashSet<_>>();names.contains("owner_user_id")&&names.contains("name")}

pub(crate) struct Scope{owner:i64,key:String,uris:Vec<String>,actors:Vec<String>,providers:Vec<String>,alias_queries:Value,owner_refs:Vec<String>,ids:Vec<String>,hosts:Vec<String>,tags:bool,components:bool,statuses:bool,bsky:bool,component_profiles:bool,component_graph:Option<Value>}
impl Scope {
 pub(crate) fn new(owner:i64,key:&str,actor:&str,statuses:&[Value],accounts:&[Value],providers:&[String],tags:bool)->Self {
  // Preserve the original raw reference plus the two existing predicate
  // normalizers. This is a scoped dependency union, never an audience grant.
  fn uri_variants(s:&str,uris:&mut HashSet<String>,hosts:&mut HashSet<String>){
   for value in [s,crate::library::card::php_trim(s),s.trim()]{
    let value=value.split('#').next().unwrap_or("").trim_end_matches('/');
    if !value.is_empty(){uris.insert(value.into());if let Ok(u)=reqwest::Url::parse(value){if let Some(h)=u.host_str(){hosts.insert(h.to_ascii_lowercase());}}}
   }
  }
  // Audience traverses descriptor quotes too. Keep its exact bounded child
  // projection separately from CurrentSourceRows' descriptor-skipping walk.
  fn audience_walk(v:&Value,uris:&mut HashSet<String>,hosts:&mut HashSet<String>,depth:usize){
   if depth>16{return}
   if let Some(s)=v.get("uri").or_else(||v.get("url")).and_then(Value::as_str){uri_variants(s,uris,hosts);}
   if let Some(s)=v.get("vaak_quote_requested_uri").and_then(Value::as_str).filter(|s|s.starts_with("https://")){uris.extend(crate::home_hydrate_ranked::quote_object_candidates(s));}
   // Exact audience::children projection: reblog stays raw; only quotes unwrap.
   if let Some(child)=v.get("reblog").filter(|child|child.is_object()){audience_walk(child,uris,hosts,depth+1)}
   for field in ["quote","vaak_quote_preview"]{
    if let Some(child)=v.get(field).filter(|child|child.is_object()){
     let child=child.get("quoted_status").or_else(||child.get("status")).filter(|child|child.is_object()).unwrap_or(child);
     audience_walk(child,uris,hosts,depth+1);
    }
   }
  }
  fn walk(v:&Value,uris:&mut HashSet<String>,actors:&mut HashSet<String>,ids:&mut HashSet<String>,hosts:&mut HashSet<String>,depth:usize){
   if depth>16{return}
   for field in ["uri","url"]{if let Some(s)=v.get(field).and_then(Value::as_str){uri_variants(s,uris,hosts);}}
   if let Some(id)=v.get("id").and_then(Value::as_str){ids.insert(id.into());}
   if let Some(account)=v.get("account"){for field in ["uri","url"]{if let Some(s)=account.get(field).and_then(Value::as_str){actors.insert(s.trim_end_matches('/').into());actors.insert(super::super::canonical_actor(s));}}walk(account,uris,actors,ids,hosts,depth+1);}
   if let Some(s)=v.get("vaak_quote_requested_uri").and_then(Value::as_str){uris.extend(crate::home_hydrate_ranked::quote_object_candidates(s));}
   if let Some(raw)=v.get("bsky_post"){let post=raw.get("post").filter(|p|p.is_object()).unwrap_or(raw);if let Some(s)=post.get("uri").and_then(Value::as_str){uris.insert(s.into());}}
   for (field,child) in super::super::children(v){if !super::super::descriptor(field,child){walk(child,uris,actors,ids,hosts,depth+1)}}
  }
  let(mut uris,mut actors,mut ids,mut hosts)=(HashSet::new(),HashSet::new(),HashSet::new(),HashSet::new());
  for v in statuses{walk(v,&mut uris,&mut actors,&mut ids,&mut hosts,0);audience_walk(v,&mut uris,&mut hosts,0)}
  for v in accounts{walk(v,&mut uris,&mut actors,&mut ids,&mut hosts,0);for field in ["uri","url"]{if let Some(s)=v.get(field).and_then(Value::as_str){actors.insert(s.trim_end_matches('/').into());actors.insert(super::super::canonical_actor(s));}}}
  for provider in providers{actors.insert(provider.trim_end_matches('/').into());if let Ok(u)=reqwest::Url::parse(provider){if let Some(h)=u.host_str(){hosts.insert(h.to_ascii_lowercase());}}}
  for uri in &uris{if uri.contains("/notes/"){actors.insert(uri.split("/notes/").next().unwrap_or("").into());}}
  // Keep the distinct original helpers: audience Unicode trim and fragment
  // removal; component PHP trim and trailing slash; original raw witness.
  let owner_refs=HashSet::from([
   actor.trim_end_matches('/').to_owned(),
   actor.trim().split('#').next().unwrap_or("").trim_end_matches('/').to_owned(),
   actor.trim_matches([' ','\t','\n','\r','\0','\u{b}']).trim_end_matches('/').to_owned(),
  ]).into_iter().collect();
  Self{owner,key:key.into(),owner_refs,uris:uris.into_iter().collect(),actors:actors.into_iter().collect(),providers:providers.to_vec(),alias_queries:Value::Array(Vec::new()),ids:ids.into_iter().collect(),hosts:hosts.into_iter().collect(),tags,components:!accounts.is_empty(),component_profiles:!accounts.is_empty(),component_graph:None,statuses:!statuses.is_empty(),bsky:statuses.iter().any(crate::library::context::has_bsky_source)}
 }
 pub(crate) fn bind_alias_queries(&mut self,queries:Value){self.alias_queries=queries;}
 // Bound only from the retained initial server-owned ComponentContext. The
 // fresh full graph remains in every read even when profiles were unused.
 pub(crate) fn bind_component_profile_dependency(&mut self,needed:bool,graph:&Value){self.component_profiles=self.components&&needed;self.component_graph=self.components.then(||graph.clone());}
 fn require_component_graph(&self,returned:&Value)->Result<()>{
  if self.component_graph.as_ref().is_some_and(|graph|Some(graph)!=returned.get("graph")){bail!("Search initial component graph changed before raw witness")}
  Ok(())
 }
 fn sql(&self,tag_supported:bool)->String {
  // Remove unused original-reader branches entirely: a false WHERE cannot
  // make an unused table/column optional during PostgreSQL parse/prepare.
  let mut sql=SQL.to_owned();
  fn replace_cte(sql:&mut String,name:&str,next:&str,empty:&str){
   let start=sql.find(&format!(" {name} AS (")).expect("raw scope CTE");
   let end=sql[start..].find(&format!(" {next} AS (")).expect("next raw scope CTE")+start;
   sql.replace_range(start..end,&format!(" {name} AS ({empty}),\n"));
  }
  fn empty_field(sql:&mut String,field:&str,next:&str){
   let start=sql.find(&format!(" '{field}',")).expect("raw scope field");
   let end=sql[start..].find(&format!(" '{next}',")).expect("next raw scope field")+start;
   sql.replace_range(start..end,&format!(" '{field}','[]'::jsonb,\n"));
  }
  if !self.components{
   replace_cte(&mut sql,"graph","owner_follows","SELECT NULL::text target_did WHERE false");
   replace_cte(&mut sql,"mapped","actor_keys","SELECT NULL::text account_id,NULL::text actor_id WHERE false");
   replace_cte(&mut sql,"actor_keys","remote_cached","SELECT unnest($4::text[]) actor");
   replace_cte(&mut sql,"remote_cached","providers","SELECT NULL::text actor_id,NULL::text username,NULL::text host WHERE false");
   replace_cte(&mut sql,"providers","remote_aliases","SELECT NULL::text actor_id,NULL::text username,NULL::text host WHERE false");
   replace_cte(&mut sql,"remote_aliases","selected_hosts","SELECT NULL::text actor_id,NULL::text username,NULL::text host WHERE false");
   replace_cte(&mut sql,"selected_hosts","host_follows","SELECT NULL::text host WHERE false");
   replace_cte(&mut sql,"host_follows","families","SELECT NULL::text actor_id,NULL::text owner_actor_id WHERE false");
   replace_cte(&mut sql,"alias_counts","profile_rows","SELECT NULL::text actor,NULL::bigint count WHERE false");
   empty_field(&mut sql,"emoji","mutes");
  }
  if !self.component_profiles{
   let start=sql.find(" bsky_profiles AS (").expect("raw Bsky profile CTE");let end=sql[start..].find("\n SELECT jsonb_build_object(").expect("raw SELECT")+start;
   sql.replace_range(start..end," bsky_profiles AS (SELECT NULL::text actor_ref WHERE false)");
  }
  if !self.statuses&&!self.tags{
   replace_cte(&mut sql,"owner_follows","mapped",if self.components{"SELECT * FROM following WHERE rtrim(owner_actor_id,'/') =ANY($11::text[])"}else{"SELECT NULL::text actor_id,NULL::text owner_actor_id WHERE false"});
   replace_cte(&mut sql,"source_events","source_mentions","SELECT NULL::text actor_id,NULL::text object_id WHERE false");
   replace_cte(&mut sql,"source_mentions","author_keys","SELECT NULL::text actor_id,NULL::text object_id WHERE false");
   empty_field(&mut sql,"followers","graph");
   empty_field(&mut sql,"outbox","statuses");empty_field(&mut sql,"statuses","bsky_posts");empty_field(&mut sql,"bsky_posts","tag_outbox");
  }
  if !self.tags{
   replace_cte(&mut sql,"tag_outbox","tag_events","SELECT NULL::text id WHERE false");
   replace_cte(&mut sql,"tag_events","tag_mentions","SELECT NULL::text object_id,NULL::text actor_id WHERE false");
   replace_cte(&mut sql,"tag_mentions","source_events","SELECT NULL::text object_id,NULL::text actor_id WHERE false");
   let start=sql.find(" 'followed_tags',").expect("tag field");sql.truncate(start);sql.push_str(" 'followed_tags','[]'::jsonb)");
  }
  if self.tags {
   let start=sql.find(" 'followed_tags',").expect("tag field");
   if !tag_supported {sql.truncate(start);sql.push_str(" 'followed_tags','[]'::jsonb)");}
   let end=sql.rfind(')').expect("raw object close");
   sql.insert_str(end,&format!(", 'followed_tag_shape',({TAG_SHAPE_SQL})"));
  }
  if !self.bsky{empty_field(&mut sql,"sessions","emoji");}
  sql
 }
 pub(crate) async fn read(&self,db:&Client)->Result<Value>{
  #[cfg(test)] let _timing=super::fixture_performance::span("raw_scope_read",self.uris.len());
  let shape=if self.tags{Some(followed_tag_shape(db).await?)}else{None};
  let supported=shape.as_ref().map(tag_shape_supported).unwrap_or(false);
  #[cfg(test)] if shape.is_some(){boundary_fixture::at(db,"after_tag_selector_before_final_sql").await?;}
  let sql=self.sql(supported);let value:Value=db.query_one(&sql,&[&self.owner,&self.key,&self.uris,&self.actors,&self.ids,&self.hosts,&self.tags,&self.components,&self.providers,&self.alias_queries,&self.owner_refs]).await?.try_get(0)?;self.require_selected_tag_shape(shape.as_ref(),&value)?;self.require_component_graph(&value)?;Ok(value)
 }
 pub(crate) async fn read_pair(&self,db:&Client,other:&Scope)->Result<(Value,Value)>{
  #[cfg(test)] let _timing=super::fixture_performance::span("raw_scope_pair",self.uris.len());
  let shape=if self.tags||other.tags{Some(followed_tag_shape(db).await?)}else{None};
  let supported=shape.as_ref().map(tag_shape_supported).unwrap_or(false);
  #[cfg(test)] if shape.is_some(){boundary_fixture::at(db,"after_tag_selector_before_final_sql").await?;}
  let first=self.sql(supported);let mut second=other.sql(supported);for index in (1..=11).rev(){second=second.replace(&format!("${index}"),&format!("@PARAM{}@",index+11));}for index in 12..=22{second=second.replace(&format!("@PARAM{index}@"),&format!("${index}"));}
  let sql=format!("SELECT ({first}),({second})");
  let row=db.query_one(&sql,&[&self.owner,&self.key,&self.uris,&self.actors,&self.ids,&self.hosts,&self.tags,&self.components,&self.providers,&self.alias_queries,&self.owner_refs,&other.owner,&other.key,&other.uris,&other.actors,&other.ids,&other.hosts,&other.tags,&other.components,&other.providers,&other.alias_queries,&other.owner_refs]).await?;
  let first:Value=row.try_get(0)?;let second:Value=row.try_get(1)?;
  self.require_selected_tag_shape(shape.as_ref(),&first)?;other.require_selected_tag_shape(shape.as_ref(),&second)?;
  self.require_component_graph(&first)?;other.require_component_graph(&second)?;
  Ok((first,second))
 }
 fn require_selected_tag_shape(&self,selected:Option<&Value>,returned:&Value)->Result<()>{
  if self.tags&&selected!=returned.get("followed_tag_shape"){bail!("Search optional tag schema changed after branch selection")}
  Ok(())
 }
 // Additional preview dependencies share the same final SQL statement as
 // all existing source/policy/Settings facts. Catalog selectors are verified
 // synchronously against the returned shape; no allow survives selector IO.
 pub(crate) async fn read_with_previews(&self,db:&Client,other:Option<&Scope>,urls:&[String],_terminal:bool)->Result<(Value,Option<Value>,Value)>{
  #[cfg(test)] let _timing=super::fixture_performance::span("raw_scope_previews",self.uris.len());
  let tags=self.tags||other.is_some_and(|scope|scope.tags);
  let tag_shape=if tags{Some(followed_tag_shape(db).await?)}else{None};
  let supported=tag_shape.as_ref().map(tag_shape_supported).unwrap_or(false);
  let preview_shape:Value=db.query_one(PREVIEW_SHAPE_SQL,&[]).await?.try_get(0)?;
  let preview_supported=preview_shape.as_array().into_iter().flatten().any(|v|v["name"]=="url");
  #[cfg(test)] if tags{boundary_fixture::at(db,"after_tag_selector_before_final_sql").await?;}
  #[cfg(test)] if _terminal{boundary_fixture::at(db,"after_preview_selector_before_final_sql").await?;}
  let first=self.sql(supported);
  let mut params:Vec<&(dyn tokio_postgres::types::ToSql+Sync)>=vec![&self.owner,&self.key,&self.uris,&self.actors,&self.ids,&self.hosts,&self.tags,&self.components,&self.providers,&self.alias_queries,&self.owner_refs];
  let second=if let Some(other)=other{
   let mut second=other.sql(supported);for index in (1..=11).rev(){second=second.replace(&format!("${index}"),&format!("@PARAM{}@",index+11));}for index in 12..=22{second=second.replace(&format!("@PARAM{index}@"),&format!("${index}"));}
   params.extend([&other.owner as &(dyn tokio_postgres::types::ToSql+Sync),&other.key,&other.uris,&other.actors,&other.ids,&other.hosts,&other.tags,&other.components,&other.providers,&other.alias_queries,&other.owner_refs]);format!("({second})")
  }else{"NULL::jsonb".into()};
  let parameter=params.len()+1;params.push(&urls);
  let rows=if preview_supported{format!("COALESCE((SELECT jsonb_agg(to_jsonb(c) ORDER BY c.url COLLATE \"C\") FROM link_preview_cards c WHERE c.url=ANY(${parameter}::text[])),'[]'::jsonb)")}else{"'[]'::jsonb".into()};
  // Retain the URL parameter even for absent/ownerless tables. This also
  // provides a deterministic per-URL empty-row witness without broad scans.
  let preview=format!("jsonb_build_object('shape',({PREVIEW_SHAPE_SQL}),'urls',to_jsonb(${parameter}::text[]),'rows',{rows})");
  let row=db.query_one(&format!("SELECT ({first}),{second},{preview}"),&params).await?;
  let first:Value=row.try_get(0)?;let second:Option<Value>=row.try_get(1)?;let preview:Value=row.try_get(2)?;
  self.require_selected_tag_shape(tag_shape.as_ref(),&first)?;self.require_component_graph(&first)?;
  if let (Some(other),Some(second))=(other,second.as_ref()){other.require_selected_tag_shape(tag_shape.as_ref(),second)?;other.require_component_graph(second)?;}
  if preview["shape"]!=preview_shape{bail!("Search preview cache schema changed after branch selection")}
  Ok((first,second,preview))
 }
 pub(crate) fn require_same(before:&Value,after:&Value,viewer:Option<&Value>)->Result<()>{
  if before!=after{bail!("Search final raw dependencies changed during ordered validation")}
  if let Some(viewer)=viewer{if &after["viewer_profile"]!=viewer{bail!("Search final viewer Settings changed")}}
  Ok(())
 }
}
// Full raw rows preserve null/absence/missing-column semantics. Each array is
// canonically sorted for equality only; it does not reorder producer/painter.
// Public alias/follow discovery uses the original host scope; private rows are
// requester-only. Tag windows equal the existing collector's 400/600/300 bounds.
const SQL:&str=r#"WITH
 bind_scope AS (SELECT $1::bigint owner,$2::text key,$3::text[] uris,$4::text[] actors,$5::text[] ids,$6::text[] hosts,$7::boolean tags,$8::boolean components,$9::text[] providers,$10::jsonb alias_queries,$11::text[] owner_refs),
 viewer AS (SELECT * FROM ap_users WHERE id=$1::bigint),
 graph AS (SELECT * FROM bsky_graph_sync WHERE owner_user_id=$1::bigint AND kind='follow'),
 owner_follows AS (SELECT * FROM following WHERE rtrim(owner_actor_id,'/') =ANY($11::text[])),
 mapped AS (SELECT * FROM masto_account_actors WHERE account_id=ANY($5::text[])),
 actor_keys AS (SELECT unnest($4::text[]) actor UNION SELECT rtrim(actor_id,'/') FROM mapped UNION SELECT rtrim(actor_id,'/') FROM owner_follows),
 remote_cached AS (SELECT r.* FROM remote_actors r WHERE rtrim(r.actor_id,'/') IN(SELECT actor FROM actor_keys)),
 providers AS (SELECT r.* FROM remote_actors r WHERE rtrim(r.actor_id,'/')=ANY($9::text[])),
 remote_aliases AS (SELECT DISTINCT r.* FROM jsonb_to_recordset($10::jsonb) q(host text,username text,family jsonb) CROSS JOIN LATERAL (SELECT r.* FROM remote_actors r WHERE lower(COALESCE(r.host,''))=q.host AND lower(COALESCE(r.username,''))=q.username LIMIT 129) r),
 selected_hosts AS (SELECT DISTINCT host FROM jsonb_to_recordset($10::jsonb) q(host text,username text,family jsonb)),
 host_follows AS (SELECT DISTINCT f.* FROM selected_hosts h CROSS JOIN LATERAL (SELECT f.* FROM following f WHERE lower(COALESCE(f.host,''))=h.host LIMIT 1025) f),
 families AS (SELECT actor FROM actor_keys UNION SELECT rtrim(actor_id,'/') FROM remote_aliases),
 tag_outbox AS (SELECT * FROM outbox_notes WHERE $7 ORDER BY published DESC LIMIT 400),
 tag_events AS (SELECT * FROM events WHERE $7 AND summary IS NOT NULL AND summary!='' ORDER BY id DESC LIMIT 600),
 tag_mentions AS (SELECT * FROM mentions WHERE $7 AND deleted_at IS NULL AND content IS NOT NULL ORDER BY id DESC LIMIT 300),
 source_events AS (SELECT * FROM events WHERE rtrim(object_id,'/')=ANY($3::text[]) OR rtrim(object_id,'/') IN(SELECT rtrim(object_id,'/') FROM tag_events UNION SELECT rtrim(object_id,'/') FROM tag_mentions UNION SELECT rtrim(id,'/') FROM tag_outbox)),
 source_mentions AS (SELECT * FROM mentions WHERE rtrim(object_id,'/')=ANY($3::text[]) OR rtrim(object_id,'/') IN(SELECT rtrim(object_id,'/') FROM tag_events UNION SELECT rtrim(object_id,'/') FROM tag_mentions UNION SELECT rtrim(id,'/') FROM tag_outbox)),
 author_keys AS (SELECT actor FROM families UNION SELECT rtrim(actor_id,'/') FROM source_events UNION SELECT rtrim(actor_id,'/') FROM source_mentions UNION SELECT rtrim(actor_id,'/') FROM tag_events UNION SELECT rtrim(actor_id,'/') FROM tag_mentions UNION SELECT split_part(id,'/notes/',1) FROM tag_outbox),
 canonical_author_inputs AS (SELECT actor FROM author_keys UNION SELECT rtrim(btrim(actor_id,chr(32)||chr(9)||chr(10)||chr(13)||chr(11)),'/') FROM source_events UNION SELECT rtrim(btrim(actor_id,chr(32)||chr(9)||chr(10)||chr(13)||chr(11)),'/') FROM source_mentions UNION SELECT rtrim(btrim(actor_id,chr(32)||chr(9)||chr(10)||chr(13)||chr(11)),'/') FROM tag_events UNION SELECT rtrim(btrim(actor_id,chr(32)||chr(9)||chr(10)||chr(13)||chr(11)),'/') FROM tag_mentions),
 canonical_authors AS (SELECT CASE WHEN actor ~* '^https://(?:www\.)?vaak\.monster/(?:vaak/users/|users/|@)([A-Za-z0-9_]+)$' THEN 'https://mkultra.monster/users/'||lower(substring(actor FROM '(?i)^https://(?:www\.)?vaak\.monster/(?:vaak/users/|users/|@)([A-Za-z0-9_]+)$')) ELSE actor END actor FROM canonical_author_inputs),
 local_users AS (SELECT * FROM ap_users WHERE id=$1::bigint OR ($8 AND id::text=ANY($5::text[])) OR ($8 AND '1'=ANY($5::text[]) AND actor_key='cmdr_nova') OR rtrim(actor_id,'/') IN(SELECT actor FROM author_keys UNION SELECT actor FROM canonical_authors)),
 alias_counts AS (SELECT rtrim(actor_id,'/') actor,COUNT(*)::bigint count FROM events WHERE $8 AND type='Create' AND rtrim(actor_id,'/') IN(SELECT jsonb_array_elements_text(q.family) FROM jsonb_to_recordset($10::jsonb) q(host text,username text,family jsonb) UNION SELECT rtrim(actor_id,'/') FROM remote_aliases) GROUP BY rtrim(actor_id,'/')),
 profile_rows AS (SELECT p.* FROM actor_profile p WHERE actor_key=$2 OR actor_key IN(SELECT actor_key FROM local_users)),
 profile_keys AS (SELECT target_did AS did FROM graph),
 bsky_profiles AS (SELECT p.* FROM bsky_actor_profiles p WHERE did IN(SELECT did FROM profile_keys) OR actor_ref IN(SELECT actor FROM actor_keys) OR actor_ref IN(SELECT did FROM profile_keys) OR actor_ref IN(SELECT 'https://bsky.app/profile/'||did FROM profile_keys) OR actor_ref IN(SELECT 'https://bsky.app/profile/'||replace(did,':','%3A') FROM profile_keys))
 SELECT jsonb_build_object(
 'viewer_profile',(SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$2 LIMIT 1),
 'users',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM local_users r),
 'profiles',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM profile_rows r),
 'remote',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM remote_aliases r),
 'remote_cached',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM remote_cached r),
 'providers',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM providers r),
 'mapped',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM mapped r),
 'following',(SELECT COALESCE(jsonb_agg(r.j ORDER BY r.j::text),'[]') FROM (SELECT to_jsonb(r) j FROM owner_follows r UNION SELECT to_jsonb(r) FROM host_follows r) r),
 'followers',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM followers r WHERE rtrim(actor_id,'/') =ANY($11::text[])),
 'graph',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM graph r),
 'bsky_profiles',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM bsky_profiles r),
 'sessions',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM bsky_sessions r WHERE owner_user_id=$1::bigint),
 'emoji',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM remote_custom_emojis r WHERE host=ANY($6::text[])),
 'mutes',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM ap_mutes r WHERE owner_user_id=$1::bigint),
 'blocks',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM ap_user_blocks r WHERE owner_user_id=$1::bigint),
 'server',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM ap_blocks r),
 'alias_counts',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM alias_counts r),
 'events',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM source_events r),
 'mentions',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM source_mentions r),
 'outbox',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM outbox_notes r WHERE rtrim(id,'/')=ANY($3::text[]) OR id IN(SELECT object_id FROM tag_events UNION SELECT object_id FROM tag_mentions)),
 'statuses',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM masto_statuses r WHERE rtrim(note_id,'/')=ANY($3::text[]) OR note_id IN(SELECT object_id FROM tag_events UNION SELECT object_id FROM tag_mentions)),
 'bsky_posts',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM bsky_posts r WHERE bsky_uri=ANY($3::text[])),
 'tag_outbox',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM tag_outbox r),
 'tag_events',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM tag_events r),
 'tag_mentions',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM tag_mentions r),
 'followed_tags',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM masto_followed_tags r WHERE $7 AND (to_jsonb(r)->>'owner_user_id')::bigint=$1::bigint))"#;

#[cfg(test)]
pub(crate) mod boundary_fixture {
 use super::*;
 use std::sync::Arc;
 pub(crate) trait Hook:Send+Sync{
  fn at<'a>(&'a self,db:&'a Client,phase:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>;
 }
 tokio::task_local!{static HOOK:Arc<dyn Hook>;}
 pub(crate) async fn run<F:std::future::Future>(hook:Arc<dyn Hook>,future:F)->F::Output{HOOK.scope(hook,future).await}
 pub(crate) async fn at(db:&Client,phase:&str)->Result<()>{
  if let Ok(hook)=HOOK.try_with(Arc::clone){hook.at(db,phase).await?;}Ok(())
 }
}

#[cfg(test)]
mod normalized_owner_fixture {
 use super::*;
 use anyhow::Context;
 use serde_json::json;
 #[tokio::test]
 #[ignore="owned PG and pinned real PHP normalized-owner scope fixture required"]
 async fn original_relationship_predicates_and_terminal_witness_withdraw_together()->Result<()>{
  let tool=std::env::var("VAAK_SEARCH_NORMALIZED_VIEWER_TOOL")?;
  let output=std::env::var("VAAK_SEARCH_NORMALIZED_VIEWER_OUTPUT")?;
  let root=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../..");let suffix=std::process::id();let mut results=Vec::new();
  for width in ["INTEGER","BIGINT"]{
   let artifact=format!("{output}.{}.producer.json",width.to_ascii_lowercase());
   let captured=tokio::process::Command::new(root.join("api/bin/php-policy-test")).args(["-d","extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so",tool.as_str(),artifact.as_str()]).env("ROOT_SAVED_WIDTH_FILTER",width).env("LIBRARY_FIXTURE_SOCKET","/tmp/vaak-library-facts-pg-socket").env("SEARCH_COMPONENT_SCHEMA_SUFFIX",suffix.to_string()).env("SEARCH_COMPONENT_MIXED_ALIAS","0").env("SEARCH_NORMALIZED_VIEWER_CONTROLS","0").env("SEARCH_NORMALIZED_VIEWER_KEEP","1").output().await?;
   anyhow::ensure!(captured.status.success(),"real producer schema: {}",String::from_utf8_lossy(&captured.stderr));
   let schema=format!("search_components_collector_{}_{suffix}",width.to_ascii_lowercase());
   let db=crate::db::connect(&format!("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options='-c search_path={schema}'")).await?;
   let run=async{
   let capture:Value=serde_json::from_slice(&std::fs::read(&artifact)?)?;let account=capture["captures"][0]["window"]["accounts"][1].clone();anyhow::ensure!(account.is_object(),"actual original remote account");
    let base="https://mkultra.monster/users/fixture";
    for (shape,actor) in [("ascii",format!(" \t{base}/\n")),("unicode",format!("\u{a0}{base}/\u{2003}")),("fragment",format!(" {base}/#legacy \n"))]{
     for kind in ["follower","following","component_following"]{
      db.batch_execute("BEGIN;DELETE FROM followers;DELETE FROM following").await?;
      let case=async{
       db.execute("UPDATE ap_users SET actor_id=$1 WHERE id=1",&[&actor]).await?;
       let audience=actor.trim().split('#').next().unwrap_or("").trim_end_matches('/');
       let component=actor.trim_matches([' ','\t','\n','\r','\0','\u{b}']).trim_end_matches('/');
       let peer=if kind=="component_following"{account["uri"].as_str().context("real cached account URI")?}else{"https://normalized-relationship.test/users/peer"};
       let selected=if kind=="component_following"{component}else{audience};
       if kind=="follower"{db.execute("INSERT INTO followers(owner_actor_id,actor_id)VALUES($1,$2)",&[&peer,&selected]).await?;}else{db.execute("INSERT INTO following(owner_actor_id,actor_id,host)VALUES($1,$2,'normalized-relationship.test')",&[&selected,&peer]).await?;}
       let uri="https://normalized-relationship.test/notes/private";
       if kind!="component_following"{db.execute("INSERT INTO events(id,object_id,actor_id,type,action_taken,visibility)VALUES(987654,$1,$2,'Create','log','private')",&[&uri,&peer]).await?;}
       let statuses=if kind=="component_following"{Vec::new()}else{vec![json!({"id":"987654","uri":uri,"visibility":"private","account":{"uri":peer}})]};
       let accounts=if kind=="component_following"{vec![account.clone()]}else{Vec::new()};
       let scope=Scope::new(1,"fixture",&actor,&statuses,&accounts,&[],false);let before=scope.read(&db).await?;
       let original_allowed=if kind=="component_following"{crate::search_components::resolve_account(&db,Some(1),&account).await?.following()}else{crate::audience::admission_mask(&db,1,crate::audience::Surface::Search,&statuses).await?==vec![true]};
       anyhow::ensure!(original_allowed,"actual original normalized relationship predicate must grant");
       db.batch_execute("SAVEPOINT normalized_withdraw").await?;
       db.batch_execute(if kind=="follower"{"DELETE FROM followers"}else{"DELETE FROM following"}).await?;
       let withdrawn_allowed=if kind=="component_following"{crate::search_components::resolve_account(&db,Some(1),&account).await?.following()}else{crate::audience::admission_mask(&db,1,crate::audience::Surface::Search,&statuses).await?==vec![true]};
       anyhow::ensure!(!withdrawn_allowed&&scope.read(&db).await?!=before,"actual original denial and terminal raw withdrawal must agree");
       db.batch_execute("ROLLBACK TO SAVEPOINT normalized_withdraw").await?;anyhow::ensure!(scope.read(&db).await?==before,"terminal witness restoration");
       let restored_allowed=if kind=="component_following"{crate::search_components::resolve_account(&db,Some(1),&account).await?.following()}else{crate::audience::admission_mask(&db,1,crate::audience::Surface::Search,&statuses).await?==vec![true]};anyhow::ensure!(restored_allowed,"actual original restored relationship grant");
       results.push(json!({"width":width,"shape":shape,"relationship":kind,"actual_original_granted":true,"withdrawal_denied":true,"terminal_raw_changed":true,"restored":true}));Ok::<_,anyhow::Error>(())
      }.await;
      db.batch_execute("ROLLBACK").await?;case?;
     }
    }Ok::<_,anyhow::Error>(())
   }.await;
   db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await?;run?;
  }
  std::fs::write(output,serde_json::to_vec_pretty(&json!({"controls":results,"scope":"actual original Rust audience/component readers and bounded final raw proof; no whole Search caller acceptance"}))?)?;Ok(())
 }
}

#[cfg(test)]
mod optional_tag_shape_fixture {
 use super::*;
 #[test]
 fn exact_legacy_shape_contract(){
  assert!(!tag_shape_supported(&serde_json::json!([])));
  assert!(!tag_shape_supported(&serde_json::json!([{"name":"name"},{"name":"followed_at"}])));
  assert!(tag_shape_supported(&serde_json::json!([{"name":"owner_user_id"},{"name":"name"},{"name":"followed_at"}])));
 }
}

#[cfg(test)]
mod tag_selector_fixture {
 use super::*;
 use std::sync::{Arc,atomic::{AtomicUsize,Ordering}};
 struct Restore{hits:AtomicUsize}
 impl boundary_fixture::Hook for Restore{
  fn at<'a>(&'a self,db:&'a Client,phase:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{
   Box::pin(async move{if phase=="after_tag_selector_before_final_sql"&&self.hits.fetch_add(1,Ordering::SeqCst)==0{
    db.batch_execute("ALTER TABLE masto_followed_tags RENAME COLUMN legacy_owner TO owner_user_id;INSERT INTO masto_followed_tags(owner_user_id,name,followed_at)VALUES(1,'fixturetag','2026-10-12')").await?;
   }Ok(())})
  }
 }
 #[tokio::test]
 #[ignore="owned PG plus genuine original collector fixture required; explicit schema mutation controls"]
 async fn actual_tag_selector_shapes_and_restored_schema_withdrawal()->Result<()>{
  let tool=std::env::var("VAAK_SEARCH_NORMALIZED_VIEWER_TOOL")?;let output=std::env::var("VAAK_SEARCH_TAG_SELECTOR_OUTPUT")?;
  let root=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../..");let suffix=std::process::id();let mut receipts=Vec::new();
  for width in ["INTEGER","BIGINT"]{
   let artifact=format!("{output}.{}.producer.json",width.to_ascii_lowercase());
   let producer=tokio::process::Command::new(root.join("api/bin/php-policy-test")).args(["-d","extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so",tool.as_str(),artifact.as_str()]).env("ROOT_SAVED_WIDTH_FILTER",width).env("LIBRARY_FIXTURE_SOCKET","/tmp/vaak-library-facts-pg-socket").env("SEARCH_COMPONENT_SCHEMA_SUFFIX",suffix.to_string()).env("SEARCH_COMPONENT_MIXED_ALIAS","0").env("SEARCH_NORMALIZED_VIEWER_CONTROLS","0").env("SEARCH_NORMALIZED_VIEWER_KEEP","1").output().await?;
   anyhow::ensure!(producer.status.success(),"actual original producer bootstrap: {}",String::from_utf8_lossy(&producer.stderr));
   let schema=format!("search_components_collector_{}_{suffix}",width.to_ascii_lowercase());let db=crate::db::connect(&format!("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options='-c search_path={schema}'")).await?;
   let run=async{
    let actor:String=db.query_one("SELECT actor_id FROM ap_users WHERE id=1",&[]).await?.get(0);
    let tag=Scope::new(1,"fixture",&actor,&[],&[],&[],true);let other=Scope::new(1,"fixture",&actor,&[],&[],&[],false);
    for shape in ["absent","ownerless","supported_empty","supported_owned"]{
     db.batch_execute("BEGIN;DROP TABLE IF EXISTS masto_followed_tags").await?;
     let case=async{
      if shape=="ownerless"{db.batch_execute("CREATE TABLE masto_followed_tags(name TEXT PRIMARY KEY,followed_at TEXT NOT NULL);INSERT INTO masto_followed_tags VALUES('fixturetag','2026-10-12')").await?;}
      if shape.starts_with("supported"){db.batch_execute(&format!("CREATE TABLE masto_followed_tags(owner_user_id {width} NOT NULL,name TEXT NOT NULL,followed_at TEXT NOT NULL,PRIMARY KEY(owner_user_id,name))")).await?;}
      if shape=="supported_owned"{db.batch_execute("INSERT INTO masto_followed_tags VALUES(1,'fixturetag','2026-10-12')").await?;}
      let selected=followed_tag_shape(&db).await?;
      for path in ["single","paired_first","paired_second"]{
       let value=match path{"single"=>tag.read(&db).await?,"paired_first"=>tag.read_pair(&db,&other).await?.0,_=>other.read_pair(&db,&tag).await?.1};
       anyhow::ensure!(value["followed_tag_shape"]==selected,"complete selector shape retained");
       anyhow::ensure!(value["followed_tags"].as_array().map(Vec::len)==Some(if shape=="supported_owned"{1}else{0}),"original optional following shape contract");
       receipts.push(serde_json::json!({"width":width,"shape":shape,"path":path,"accepted":true}));
      }Ok::<_,anyhow::Error>(())
     }.await;db.batch_execute("ROLLBACK").await?;case?;
    }
    for path in ["single","paired_first","paired_second"]{
     db.batch_execute(&format!("BEGIN;DROP TABLE IF EXISTS masto_followed_tags;CREATE TABLE masto_followed_tags(owner_user_id {width} NOT NULL,name TEXT NOT NULL,followed_at TEXT NOT NULL,PRIMARY KEY(owner_user_id,name))")).await?;
     let case=async{
      let before=tag.read(&db).await?;let original_shape=before["followed_tag_shape"].clone();
      db.batch_execute("ALTER TABLE masto_followed_tags RENAME COLUMN owner_user_id TO legacy_owner").await?;
      let hook=Arc::new(Restore{hits:AtomicUsize::new(0)});
      let result=boundary_fixture::run(hook.clone(),async{match path{"single"=>tag.read(&db).await,"paired_first"=>tag.read_pair(&db,&other).await.map(|v|v.0),_=>other.read_pair(&db,&tag).await.map(|v|v.1)}}).await;
      anyhow::ensure!(result.is_err()&&hook.hits.load(Ordering::SeqCst)==1,"unsupported selector restored with new follow must refuse");
      let restored=tag.read(&db).await?;anyhow::ensure!(restored["followed_tag_shape"]==original_shape,"same OID complete supported shape restored");
      anyhow::ensure!(restored["followed_tags"].as_array().map(Vec::len)==Some(1),"new legitimate request sees inserted follow");
      receipts.push(serde_json::json!({"width":width,"path":path,"race_refused":true,"same_oid_restored":true,"fresh_follow_visible":true}));Ok::<_,anyhow::Error>(())
     }.await;db.batch_execute("ROLLBACK").await?;case?;
    }Ok::<_,anyhow::Error>(())
   }.await;db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await?;run?;
  }
  std::fs::write(output,serde_json::to_vec_pretty(&serde_json::json!({"controls":receipts,"scope":"actual original collector schema; explicit optional-tag shape and same-OID mutation controls; not whole caller/native performance acceptance"}))?)?;Ok(())
 }
}


#[cfg(test)]
mod optional_component_profiles_regression {
 use super::*;
 use serde_json::json;
 // Production SQL itself must keep the graph witness, yet not prepare an
 // absent profile table when the initial component context did not read it.
 #[test]
 fn unused_profiles_preserve_fresh_graph_and_terminal_refusal(){
  let account=json!({"id":"363409668","uri":"https://jforo.com/users/pg","url":"https://jforo.com/@pg","username":"pg"});
  let mut scope=Scope::new(201,"article_fixture","https://mkultra.monster/users/article_fixture",&[],&[account],&["https://jforo.com/users/pg".into()],false);
  scope.bind_component_profile_dependency(false,&json!([]));
  let sql=scope.sql(false);
  assert!(!sql.contains("FROM bsky_actor_profiles"));
  assert!(sql.contains("graph AS (SELECT * FROM bsky_graph_sync WHERE owner_user_id=$1::bigint AND kind='follow')"));
  assert!(sql.contains("'graph',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]') FROM graph r)"));
  assert!(sql.contains("FROM following WHERE rtrim(owner_actor_id,'/') =ANY($11::text[])"));
  let before=json!({"graph":[],"bsky_profiles":[],"viewer_profile":null});
  let after=json!({"graph":[{"owner_user_id":201,"kind":"follow","target_did":"did:plc:fixture-new-follow"}],"bsky_profiles":[],"viewer_profile":null});
  assert!(scope.require_component_graph(&before).is_ok());
  assert!(scope.require_component_graph(&after).is_err());
  assert!(Scope::require_same(&before,&after,None).is_err());
  assert!(Scope::require_same(&before,&before,None).is_ok());
 }
 #[test]
 fn initial_graph_witness_rejects_full_row_and_duplicate_changes(){
  let account=json!({"id":"363409668","uri":"https://jforo.com/users/pg"});
  let initial=json!([{ "owner_user_id":201,"kind":"follow","target_did":"not-a-did","updated_at":null }]);
  let mut scope=Scope::new(201,"article_fixture","https://mkultra.monster/users/article_fixture",&[],&[account],&[],false);
  scope.bind_component_profile_dependency(false,&initial);
  assert!(scope.require_component_graph(&json!({"graph":initial.clone()})).is_ok());
  assert!(scope.require_component_graph(&json!({"graph":[{ "owner_user_id":201,"kind":"follow","target_did":"not-a-did","updated_at":"new" }]})).is_err());
  assert!(scope.require_component_graph(&json!({"graph":[initial[0].clone(),initial[0].clone()]})).is_err());
  assert!(scope.require_component_graph(&json!({"graph":[]})).is_err());
 }
 #[test]
 fn required_profiles_and_uncoupled_callers_remain_fail_closed(){
  let account=json!({"id":"363409668","uri":"https://jforo.com/users/pg"});
  let mut scope=Scope::new(201,"article_fixture","https://mkultra.monster/users/article_fixture",&[],&[account],&[],false);
  assert!(scope.sql(false).contains("FROM bsky_actor_profiles"));
  scope.bind_component_profile_dependency(true,&json!([{ "target_did":"did:plc:fixture-follow" }]));
  assert!(scope.sql(false).contains("FROM bsky_actor_profiles"));
 }
}
