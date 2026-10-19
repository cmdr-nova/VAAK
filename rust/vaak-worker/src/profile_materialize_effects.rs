//! Native execution of the actual account-id remember helper. The effect is
//! bound to a projector-owned descriptor and an opaque current source grant.
use super::*;
pub(crate) struct AccountMappingChanges {changes:Vec<MappingChange>}
struct MappingChange {sql:&'static str,key:Value,before:Vec<Value>,after:Vec<Value>}
impl AccountMappingChanges {fn empty()->Self{Self{changes:vec![]}}}
fn reconcile(deps:&mut [Dependency],changes:&AccountMappingChanges)->Result<()>{
 for change in &changes.changes{for d in deps.iter_mut().filter(|d|d.sql==change.sql&&d.key==change.key){
  if d.rows==change.after{continue}
  if d.rows!=change.before{bail!("PHP fallback: mapping mutation receipt differs from retained before snapshot")}
  d.rows=change.after.clone();
 }}Ok(())
}
impl ProjectionLedger{pub(crate) fn reconcile_account_changes(&mut self,changes:&AccountMappingChanges)->Result<()>{reconcile(&mut self.dependencies,changes)}}
const MAP: &str="SELECT to_jsonb(m) FROM masto_account_actors m WHERE account_id=$1::jsonb->>'account_id' LIMIT 1";
pub(super) const POST_MEDIA: &str="SELECT to_jsonb(m) FROM remote_post_media_cache m WHERE source_url=$1::jsonb->>'source_url' LIMIT 1";
pub(super) const PREVIEW_CACHE: &str="SELECT to_jsonb(c) FROM link_preview_cards c WHERE url=$1::jsonb->>'url' LIMIT 1";
pub(super) fn preview_changes(writes:&[crate::profile_preview_fetch::PreviewCacheWrite])->AccountMappingChanges{AccountMappingChanges{changes:writes.iter().map(|w|MappingChange{sql:PREVIEW_CACHE,key:json!({"url":w.url()}),before:w.before().to_vec(),after:w.after().to_vec()}).collect()}}
pub(super) fn reconcile_preview_write(deps:&mut [Dependency],write:&crate::profile_preview_fetch::PreviewCacheWrite)->Result<()>{
 let key=json!({"url":write.url()});if !deps.iter().any(|d|d.sql==PREVIEW_CACHE&&d.key==key){bail!("PHP fallback: preview receipt URL lacks retained native cache snapshot")}
 reconcile(deps,&preview_changes(std::slice::from_ref(write)))
}
pub(super) enum Grant<'a>{Search,Surface(&'a crate::library::context::SelectedLibrarySearchSource),Source(&'a crate::profile_context::AdmittedProjectionSources,usize),Quote(&'a crate::profile_context::AdmittedQuoteProjection,&'a str)}
impl Grant<'_>{async fn current(&self,db:&Client,status:&Value)->Result<()>{match self{Self::Search=>Ok(()),Self::Surface(selected)=>selected.require_projected_current(db,status).await,Self::Source(grant,index)=>grant.require_current(db,*index,status).await,Self::Quote(grant,target)=>grant.require_current(db,target,status).await}}}
impl MaterializedProfilePage{
 pub(crate) fn reconcile_account_changes(&mut self,changes:&AccountMappingChanges)->Result<()>{reconcile(&mut self.dependencies,changes)}
 pub(crate) async fn apply_source_effect(&mut self,db:&Client,effect:&DeferredProjectionEffect,status:&Value,grant:&crate::profile_context::AdmittedProjectionSources)->Result<AccountMappingChanges>{
  if !self.effects.contains(effect){bail!("PHP fallback: projection effect differs from native source descriptor")}
  if grant.disposition(effect.source_index,status)!=Some(true){bail!("PHP fallback: account writer lacks exact admitted source")}
  self.require_current(db).await?;
  let changes=apply(db,&mut self.dependencies,effect,&Grant::Source(grant,effect.source_index),status).await?;
  self.require_current(db).await?;Ok(changes)
 }
}
impl ProjectedQuoteTarget{
 pub(crate) fn reconcile_account_changes(&mut self,changes:&AccountMappingChanges)->Result<()>{self.ledger.reconcile_account_changes(changes)}
 pub(crate) async fn apply_quote_effect(&mut self,db:&Client,effect:&DeferredProjectionEffect,status:&Value,grant:&crate::profile_context::AdmittedQuoteProjection)->Result<AccountMappingChanges>{
  if !self.effects.contains(effect)||!grant.permits(&self.requested,status){bail!("PHP fallback: quote account writer lacks exact native effect and grant")}
  self.require_current(db).await?;
  let changes=apply(db,&mut self.ledger.dependencies,effect,&Grant::Quote(grant,&self.requested),status).await?;
  self.require_current(db).await?;Ok(changes)
 }
}
async fn read(db:&Client,deps:&mut Vec<Dependency>,sql:&'static str,key:Value)->Result<Vec<Value>>{let rows=Dependency::read(db,sql,&key).await?;deps.push(Dependency::new(sql,key,rows.clone()));Ok(rows)}
pub(super) async fn aliases(db:&Client,deps:&mut Vec<Dependency>,actor:&str)->Result<Vec<String>>{
 let actor=crate::library::card::php_trim(actor).trim_end_matches('/');
 if actor.is_empty(){return Ok(vec![])}
 if !actor.starts_with("https://"){return Ok(vec![actor.into()])}
 let Some(mut host)=reqwest::Url::parse(actor).ok().and_then(|u|u.host_str().map(|s|s.to_ascii_lowercase()))else{return Ok(vec![actor.into(),format!("{actor}/")])};
 let path=re!(r"(?i)/(?:users|ap/users|@)([^/]+)/?$").captures(actor).map(|c|crate::hidden::raw_percent_decode(&c[1]).trim_start_matches('@').to_owned()).unwrap_or_default();
 let rows=read(db,deps,"SELECT to_jsonb(a) FROM remote_actors a WHERE actor_id IN($1::jsonb->>'actor',($1::jsonb->>'actor')||'/') LIMIT 1",json!({"actor":actor})).await?;
 let mut username=String::new();if let Some(row)=rows.first(){username=crate::library::card::php_trim(s(row,"username")).into();let h=crate::library::card::php_trim(s(row,"host")).to_ascii_lowercase();if !h.is_empty(){host=h}}
 if username.is_empty()&&!path.is_empty()&&!path.bytes().all(|b|b.is_ascii_digit()){username=path}
 let mut candidates=vec![actor.to_owned()];
 if !username.is_empty(){
  let encoded=reqwest::Url::parse("https://placeholder.test/").unwrap().join(&format!("users/{}",raw_encode(&username)))?.path().to_owned();
  candidates.push(format!("https://{host}{encoded}"));candidates.push(format!("https://{host}/@{}",raw_encode(&username)));
  for row in read(db,deps,"SELECT to_jsonb(a) FROM remote_actors a WHERE lower(COALESCE(host,''))=$1::jsonb->>'host' AND lower(COALESCE(username,''))=$1::jsonb->>'username'",json!({"host":host,"username":username.to_lowercase()})).await?{let a=s(&row,"actor_id").trim_end_matches('/');if !a.is_empty(){candidates.push(a.into())}}
  for row in read(db,deps,"SELECT to_jsonb(f) FROM following f WHERE lower(COALESCE(host,''))=$1::jsonb->>'host'",json!({"host":host})).await?{let a=s(&row,"actor_id").trim_end_matches('/');if let Some(c)=re!(r"(?i)/(?:users|@)([^/]+)/?$").captures(a){if crate::hidden::raw_percent_decode(&c[1]).to_lowercase()==username.to_lowercase(){candidates.push(a.into())}}}
 }
 let mut unique=Vec::<String>::new();for a in candidates{let a=a.trim_end_matches('/');if !a.is_empty()&&a.starts_with("https://")&&!unique.iter().any(|v|v==a){unique.push(a.into())}}
 if unique.len()>1{
  let rows=read(db,deps,"SELECT jsonb_build_object('aid',rtrim(actor_id,'/'),'c',COUNT(*)) FROM events WHERE type='Create' AND actor_id IN(SELECT jsonb_array_elements_text($1::jsonb->'actors')) GROUP BY rtrim(actor_id,'/')",json!({"actors":unique.iter().flat_map(|a|[a.clone(),format!("{a}/")]).collect::<Vec<_>>()})).await?;
  unique.sort_by_key(|a|std::cmp::Reverse(rows.iter().find(|r|s(r,"aid")==a).map(|r|i(r,"c")).unwrap_or(0)));
 }
 Ok(unique.into_iter().flat_map(|a|[a.clone(),format!("{a}/")]).collect())
}
fn raw_encode(text:&str)->String{let mut out=String::new();for b in text.bytes(){if b.is_ascii_alphanumeric()||b"-_.~".contains(&b){out.push(b as char)}else{out.push_str(&format!("%{b:02X}"))}}out}
async fn local(db:&Client,deps:&mut Vec<Dependency>,id:&str)->Result<bool>{
 if id.is_empty()||!id.bytes().all(|b|b.is_ascii_digit()){return Ok(false)}
 let numeric=id.parse::<i64>().unwrap_or(i64::MAX);
 let sql=if id=="1"{"SELECT to_jsonb(u) FROM ap_users u WHERE actor_key='cmdr_nova' AND disabled_at IS NULL LIMIT 1"}else{"SELECT to_jsonb(u) FROM ap_users u WHERE id=($1::jsonb->>'id')::bigint AND disabled_at IS NULL LIMIT 1"};
 if !read(db,deps,sql,json!({"id":numeric})).await?.is_empty(){return Ok(true)}
 if id=="1"{return Ok(false)}
 let rows=read(db,deps,MAP,json!({"account_id":id})).await?;
 let Some(key)=rows.first().and_then(|r|re!(r"^https://mkultra\.monster/users/([A-Za-z0-9_]+)$").captures(s(r,"actor_id").trim_end_matches('/')).map(|c|c[1].to_ascii_lowercase()))else{return Ok(false)};
 Ok(!read(db,deps,"SELECT to_jsonb(u) FROM ap_users u WHERE lower(actor_key)=$1::jsonb->>'key' AND disabled_at IS NULL LIMIT 1",json!({"key":key})).await?.is_empty())
}
pub(super) async fn apply(db:&Client,deps:&mut Vec<Dependency>,effect:&DeferredProjectionEffect,grant:&Grant<'_>,status:&Value)->Result<AccountMappingChanges>{
 if let ProjectionEffect::PostMediaTouch{source_url}=effect.effect(){return touch_post_media(db,deps,source_url,grant,status).await}
 let ProjectionEffect::RememberAccount{account_id,actor}=effect.effect()else{bail!("native projection writer accepts only stored RememberAccount/PostMediaTouch")};
 let id=crate::library::card::php_trim(account_id);let actor=crate::library::card::php_trim(actor).trim_end_matches('/');if id.is_empty()||actor.is_empty(){return Ok(AccountMappingChanges::empty())}
 grant.current(db,status).await?;
 // Mapping snapshots are refreshed only for this exact id. Future lookup
 // query shapes must define their own scoped writer reconciliation first.
 if deps.iter().any(|d|d.sql.contains("masto_account_actors")&&d.sql!=MAP){bail!("PHP fallback: mapping writer cannot reconcile unrelated mapping dependency")}
 let mut new_deps=vec![];if local(db,&mut new_deps,id).await?{deps.extend(new_deps);return Ok(AccountMappingChanges::empty())}
 let aliases=aliases(db,&mut new_deps,actor).await?;let canonical=aliases.first().map(|a|a.trim_end_matches('/')).unwrap_or(actor);
 require_dependencies(db,&new_deps,"PHP fallback: mapping alias dependency changed before writer").await?;
 let mut roots=Vec::new();for a in aliases.iter().map(|a|a.trim_end_matches('/')).chain(std::iter::once(canonical)){if !a.is_empty()&&(a.starts_with("https://")||a==canonical)&&!roots.contains(&a){roots.push(a)}}
 let mut changes=AccountMappingChanges::empty();
 for alias in roots{
  grant.current(db,status).await?;
  let deleted=db.query("DELETE FROM masto_account_actors m WHERE actor_id IN($1,$2) AND account_id<>$3 RETURNING to_jsonb(m)",&[&alias,&format!("{alias}/"),&id]).await?;
  for row in deleted{let before:Value=row.get(0);let delta=AccountMappingChanges{changes:vec![MappingChange{sql:MAP,key:json!({"account_id":s(&before,"account_id")}),before:vec![before],after:vec![]}]};reconcile(deps,&delta)?;reconcile(&mut new_deps,&delta)?;changes.changes.extend(delta.changes);}
 }
 grant.current(db,status).await?;
 let key=json!({"account_id":id});let before=Dependency::read(db,MAP,&key).await?;
 // Every retained lookup for the written id must agree before replacing it.
 for d in deps.iter().chain(new_deps.iter()).filter(|d|d.sql==MAP&&s(&d.key,"account_id")==id){if d.rows!=before{bail!("PHP fallback: mapping changed before authorized upsert")}}
 grant.current(db,status).await?;let now=chrono::Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Secs,false);
 let row=db.query_one("INSERT INTO masto_account_actors AS m(account_id,actor_id,updated_at) VALUES($1,$2,$3) ON CONFLICT(account_id) DO UPDATE SET actor_id=excluded.actor_id,updated_at=excluded.updated_at RETURNING to_jsonb(m)",&[&id,&canonical,&now]).await?;
 let after:Value=row.get(0);let delta=AccountMappingChanges{changes:vec![MappingChange{sql:MAP,key:json!({"account_id":id}),before,after:vec![after]}]};reconcile(deps,&delta)?;reconcile(&mut new_deps,&delta)?;changes.changes.extend(delta.changes);
 grant.current(db,status).await?;
 deps.extend(new_deps);Ok(changes)
}
async fn touch_post_media(db:&Client,deps:&mut Vec<Dependency>,source_url:&str,grant:&Grant<'_>,status:&Value)->Result<AccountMappingChanges>{
 grant.current(db,status).await?;
 let key=json!({"source_url":source_url});let before=Dependency::read(db,POST_MEDIA,&key).await?;
 grant.current(db,status).await?;
 if before.len()!=1||!truth(&before[0]["public_url"]){bail!("PHP fallback: cached post-media touch source disappeared")}
 let matching=deps.iter().filter(|d|d.sql==POST_MEDIA&&d.key==key).collect::<Vec<_>>();
 if matching.is_empty()||matching.iter().any(|d|d.rows!=before){bail!("PHP fallback: post-media touch differs from native projection snapshot")}
 let now=chrono::Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Secs,false);
 require_dependencies(db,deps,"PHP fallback: projection changed before cached post-media writer").await?;
 grant.current(db,status).await?;
 let returned=db.query("UPDATE remote_post_media_cache AS m SET last_used_at=$1 WHERE source_url=$2 RETURNING to_jsonb(m)",&[&now,&source_url]).await?;
 let after=returned.into_iter().map(|r|r.get::<_,Value>(0)).collect::<Vec<_>>();
 if after.len()!=1{bail!("PHP fallback: authorized post-media touch did not update exact row")}
 let changes=AccountMappingChanges{changes:vec![MappingChange{sql:POST_MEDIA,key,before,after}]};
 reconcile(deps,&changes)?;grant.current(db,status).await?;Ok(changes)
}
#[cfg(test)]
mod tests{
 use super::*;
 #[tokio::test]
 #[ignore="actual PHP account remember helper in disposable SQL"]
 async fn actual_account_writer_helpers_and_bound_sources(){
  let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
  let oracle:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-account-writer-reference.json").unwrap()).unwrap();
  let schema=format!("profile_account_helpers_{}",std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema};CREATE TABLE ap_users(id BIGINT,actor_key TEXT,disabled_at TEXT);CREATE TABLE remote_actors(actor_id TEXT,username TEXT,host TEXT);CREATE TABLE following(actor_id TEXT,host TEXT);CREATE TABLE events(actor_id TEXT,type TEXT);CREATE TABLE masto_account_actors(account_id TEXT PRIMARY KEY,actor_id TEXT UNIQUE,updated_at TEXT)")).await.unwrap();
  assert_eq!(oracle["captures"].as_array().unwrap().len(),8);
  for case in oracle["captures"].as_array().unwrap(){
   for(table,rows)in case["initial"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
   let mut deps=vec![];let actor=crate::library::card::php_trim(s(case,"actor")).trim_end_matches('/');let id=crate::library::card::php_trim(s(case,"id"));
   assert_eq!(local(&db,&mut deps,id).await.unwrap(),case["local"].as_bool().unwrap(),"local {}",case["case"]);
   assert_eq!(json!(aliases(&db,&mut deps,actor).await.unwrap()),case["aliases"],"aliases {}",case["case"]);
  }
  db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
  let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-shared-card-genuine-lookup-reference.json").unwrap()).unwrap();
  for width in ["INTEGER","BIGINT"]{
   let schema=format!("profile_account_native_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
   for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let definitions=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({definitions})")).await.unwrap();}
   db.batch_execute("ALTER TABLE masto_account_actors ADD PRIMARY KEY(account_id);ALTER TABLE masto_account_actors ADD UNIQUE(actor_id)").await.unwrap();
   let row=reference["captures"].as_array().unwrap().iter().find(|r|r["width"]==width&&s(r,"case")=="own_remote_boost_public_boosts_owner2").unwrap();
   for(table,rows)in row["current_tables"].as_object().unwrap(){for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
   db.batch_execute("DELETE FROM masto_account_actors").await.unwrap();assert_eq!(oracle["ordered"].as_array().unwrap().len(),1);for mapping in oracle["ordered"][0]["before"].as_array().unwrap(){db.execute("INSERT INTO masto_account_actors SELECT * FROM jsonb_populate_record(NULL::masto_account_actors,$1)",&[mapping]).await.unwrap();}
   let actor=s(&row["caller_provenance"],"actor");let key=actor.rsplit('/').next().unwrap();let session=AuthenticatedProfileSession::from_php_session(2,"fixture-csrf-token".into(),key.into(),actor.into(),false,0).unwrap();
   let selected=crate::profile_source::select(&db,key,actor,"boosts",10,0).await.unwrap();let mut page=materialize(&db,&ProfileViewer::Auth(&session),selected).await.unwrap();page.dependencies.push(Dependency::new(MAP,json!({"account_id":"old-native"}),Dependency::read(&db,MAP,&json!({"account_id":"old-native"})).await.unwrap()));let grant=crate::profile_context::admit_projection_sources(&db,&ProfileViewer::Auth(&session),&page).await.unwrap();
   let effect=page.effects().iter().find(|e|matches!(e.effect(),ProjectionEffect::RememberAccount{account_id,..}if account_id=="758938460")).unwrap().clone();let source=page.sources().iter().find(|s|s.source_index()==effect.source_index()).unwrap();let ProfileSourceKind::Shared(status)=source.source()else{panic!("native boost shared source")};let status=status.clone();
   let mut mismatch=effect.clone();mismatch.source_index+=500;assert!(page.apply_source_effect(&db,&mismatch,&status,&grant).await.is_err());
   let mut wrong_status=status.clone();wrong_status["content"]=json!("substituted");assert!(page.apply_source_effect(&db,&effect,&wrong_status,&grant).await.is_err());
   assert_eq!(db.query_one("SELECT COUNT(*) FROM masto_account_actors",&[]).await.unwrap().get::<_,i64>(0),1);
   let mut earlier=ProjectionLedger{dependencies:vec![Dependency::new(MAP,json!({"account_id":"old-native"}),Dependency::read(&db,MAP,&json!({"account_id":"old-native"})).await.unwrap())],static_counts:vec![]};
   let mut unrelated=ProjectionLedger{dependencies:vec![Dependency::new(MAP,json!({"account_id":"unrelated"}),vec![])],static_counts:vec![]};
   let changes=page.apply_source_effect(&db,&effect,&status,&grant).await.unwrap();page.require_current(&db).await.unwrap();
   assert!(earlier.require_current(&db).await.is_err());earlier.reconcile_account_changes(&changes).unwrap();earlier.require_current(&db).await.unwrap();unrelated.reconcile_account_changes(&changes).unwrap();unrelated.require_current(&db).await.unwrap();
   let mut changed=ProjectionLedger{dependencies:vec![Dependency::new(MAP,json!({"account_id":"old-native"}),vec![json!({"account_id":"old-native","actor_id":"https://remote.test/users/profile-boost-target/","updated_at":"concurrent change"})])],static_counts:vec![]};assert!(changed.reconcile_account_changes(&changes).is_err());
   let actual:Vec<Value>=db.query("SELECT jsonb_build_object('account_id',account_id,'actor_id',actor_id) FROM masto_account_actors ORDER BY account_id",&[]).await.unwrap().iter().map(|r|r.get(0)).collect();assert_eq!(json!(actual),oracle["ordered"][0]["after"]);
   db.batch_execute("UPDATE masto_account_actors SET actor_id=actor_id||'/changed' WHERE account_id='758938460'").await.unwrap();assert!(page.require_current(&db).await.is_err());db.batch_execute("UPDATE masto_account_actors SET actor_id='https://remote.test/users/profile-boost-target' WHERE account_id='758938460'").await.unwrap();page.require_current(&db).await.unwrap();
   // Only the writer's mapping snapshot changes; other dependencies still
   // withdraw the complete page after a real admitted mapping write.
   db.batch_execute("UPDATE events SET summary=COALESCE(summary,'')||' withdrawn' WHERE actor_id='https://remote.test/users/profile-boost-target'").await.unwrap();assert!(page.require_current(&db).await.is_err());
   db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
  }
 }
}
