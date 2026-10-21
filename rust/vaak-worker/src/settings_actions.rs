//! Default-off native Settings actions (S7), one family per step, each behind
//! its own switch. S7a is muted words: muted_word_add and muted_word_remove,
//! copied from ap_muted_word_add, ap_muted_word_remove, ap_muted_words_cache_clear
//! and the admin_tl_cache_clear their ap-admin.php handlers run on success.
//! S7c is revoke_oauth_token from Settings > Security, copied from
//! ap_oauth_token_revoke_by_id with the owner's scope.
//!
//! Settings are never admin-gated: the worker requires the signed-in account to
//! be active, holding its row for the write. PHP keeps the session, CSRF, the
//! notice text and the page. Anything these copies cannot prove they read the
//! way PHP does answers 422 before any write, so PHP runs the action instead.
//! After a commit nothing answers 422: cache work that did not happen here is
//! named in php_cache for PHP to redo (every step is idempotent).
use anyhow::{bail, Context, Result};
use axum::{body::Bytes,http::{HeaderMap,StatusCode},response::{IntoResponse,Response}};
use serde::Deserialize;
use serde_json::{json,Value};
use std::time::Duration;
use crate::library::card::php_trim;

#[derive(Debug,Deserialize)]
#[serde(deny_unknown_fields)]
struct Request {protocol_version:u8,owner_id:i64,actor_key:String,#[serde(flatten)]action:Action}
#[derive(Debug,Deserialize)]
#[serde(tag="action",content="fields",deny_unknown_fields)]
enum Action {
 #[serde(rename="muted_word_add")]MutedWordAdd(MutedWordAdd),
 #[serde(rename="muted_word_remove")]MutedWordRemove(MutedWordRemove),
 #[serde(rename="revoke_oauth_token")]TokenRevoke(TokenRevoke),
}
#[derive(Debug,Deserialize)]#[serde(deny_unknown_fields)]struct MutedWordAdd {phrase:String,note:String}
#[derive(Debug,Deserialize)]#[serde(deny_unknown_fields)]struct MutedWordRemove {id:i64}
#[derive(Debug,Deserialize)]#[serde(deny_unknown_fields)]struct TokenRevoke {token_id:i64}
impl Action {
 fn name(&self)->&'static str{match self{Action::MutedWordAdd(_)=>"muted_word_add",Action::MutedWordRemove(_)=>"muted_word_remove",Action::TokenRevoke(_)=>"revoke_oauth_token"}}
 fn family_enabled(&self)->bool{
  let switch=match self{Action::MutedWordAdd(_)|Action::MutedWordRemove(_)=>"VAAK_SETTINGS_MUTED_WORDS_ACTIONS_NATIVE",
   Action::TokenRevoke(_)=>"VAAK_SETTINGS_TOKENS_ACTIONS_NATIVE"};
  std::env::var(switch).ok().as_deref()==Some("1")
 }
}
/// What PHP still has to run after the reply: "full" is ap_muted_words_cache_clear
/// for the owner plus admin_tl_cache_clear, "global" is admin_tl_cache_clear,
/// "owner" is ap_muted_words_cache_clear alone.
#[derive(Clone,Copy,PartialEq,Debug)]
enum Cache {Done,Full,Global,Owner}
impl Cache{fn php(self)->Value{match self{Cache::Done=>Value::Null,Cache::Full=>json!("full"),Cache::Global=>json!("global"),Cache::Owner=>json!("owner")}}}

fn decode(bytes:&[u8])->Result<Request>{
 if bytes.is_empty()||bytes.len()>65536{bail!("settings action envelope size");}
 let r:Request=serde_json::from_slice(bytes)?;
 if r.protocol_version!=1||r.owner_id<1||r.actor_key.is_empty()||r.actor_key.len()>200{bail!("settings action envelope");}
 Ok(r)
}
pub(crate) async fn action_route(cfg:&crate::config::Config,headers:HeaderMap,bytes:Bytes)->Response{
 if !crate::admin_read::token_ok(&headers){return StatusCode::UNAUTHORIZED.into_response();}
 let request=match decode(&bytes){Ok(r)=>r,Err(_)=>return StatusCode::BAD_REQUEST.into_response()};
 if !request.action.family_enabled(){return StatusCode::NOT_FOUND.into_response();}
 match run(cfg,&request).await{Ok(reply)=>(StatusCode::OK,axum::Json(reply)).into_response(),Err(e)=>{tracing::info!(error=%e,action=request.action.name(),"native settings action fell back to PHP");(StatusCode::UNPROCESSABLE_ENTITY,axum::Json(json!({"fallback":true}))).into_response()}}
}
/// A closed outcome PHP turns into its notice or error, the phrase the notice
/// quotes, the new or existing row id, and the cache work left to PHP.
fn reply(r:&Request,outcome:&str,phrase:Option<&str>,id:Option<i64>,cache:Cache)->Value{
 json!({"protocol_version":1,"ok":true,"outcome":outcome,"phrase":phrase,"id":id,"php_cache":cache.php(),
  "request_binding":{"owner_id":r.owner_id,"actor_key":r.actor_key,"action":r.action.name()}})
}
fn now_c()->String{chrono::Utc::now().format("%Y-%m-%dT%H:%M:%S+00:00").to_string()}
/// mb_strtolower for the duplicate check. Characters whose lowercase PHP 8.3
/// and this copy could map differently (a multi-character mapping such as
/// U+0130, or the contextual final sigma) are left to PHP.
fn lower(s:&str)->Result<String>{
 if s.chars().any(|c|c=='\u{3A3}'||c.to_lowercase().count()>1){bail!("PHP fallback: special-cased lowercase");}
 Ok(crate::search::php_lowercase(s))
}

async fn run(cfg:&crate::config::Config,r:&Request)->Result<Value>{
 let mut db=crate::db::connect(&cfg.database_url).await?;
 match &r.action{
  Action::MutedWordAdd(a)=>{
   let phrase=crate::admin_actions::normalize_phrase(&a.phrase)?;
   if a.note.contains('\0'){bail!("PHP fallback: NUL in note");}
   let note=php_trim(&a.note);
   if phrase.is_empty(){return Ok(reply(r,"phrase_empty",None,None,Cache::Done));}
   let n=phrase.chars().count();
   if n<2{return Ok(reply(r,"phrase_short",None,None,Cache::Done));}
   if n>200{return Ok(reply(r,"phrase_long",None,None,Cache::Done));}
   if note.chars().count()>300{return Ok(reply(r,"note_long",None,None,Cache::Done));}
   let norm=lower(&phrase)?;
   let tx=owner_tx(&mut db,r).await?;
   // ap_muted_words_list order: the first case-insensitive match wins.
   for row in tx.query("SELECT id::bigint, phrase::text FROM ap_muted_words WHERE owner_user_id::bigint = $1 ORDER BY created_at DESC, id DESC",&[&r.owner_id]).await?{
    let existing:String=row.get(1);
    if lower(&existing)?==norm{
     let id:i64=row.get(0);
     tx.commit().await?;
     // ap_muted_word_add returns before its own cache clear; the handler still clears every timeline.
     let cache=clear(cfg,&db,r.owner_id,false).await;
     return Ok(reply(r,"already",Some(&existing),Some(id),cache));
    }
   }
   let note_value=(!note.is_empty()).then_some(note);
   let id:i64=tx.query_one("INSERT INTO ap_muted_words (owner_user_id, phrase, created_at, note) VALUES ($1::bigint, $2, $3, $4) RETURNING id::bigint",&[&r.owner_id,&phrase,&now_c(),&note_value]).await?.get(0);
   tx.commit().await?;
   let cache=clear(cfg,&db,r.owner_id,true).await;
   Ok(reply(r,"added",Some(&phrase),Some(id),cache))
  },
  Action::MutedWordRemove(a)=>{
   if a.id<1{return Ok(reply(r,"missing_id",None,None,Cache::Done));}
   let tx=owner_tx(&mut db,r).await?;
   crate::admin_actions::int4_guard(&tx,"ap_muted_words",a.id).await?;
   let removed=tx.execute("DELETE FROM ap_muted_words WHERE id::bigint = $1 AND owner_user_id::bigint = $2",&[&a.id,&r.owner_id]).await?;
   tx.commit().await?;
   if removed<1{
    // PHP clears the owner's muted-word caches even when nothing matched.
    return Ok(reply(r,"not_found",None,None,Cache::Owner));
   }
   let cache=clear(cfg,&db,r.owner_id,true).await;
   Ok(reply(r,"removed",None,Some(a.id),cache))
  },
  Action::TokenRevoke(a)=>{
   // The handler short-circuits on a non-positive id before the helper runs.
   if a.token_id<1{return Ok(reply(r,"not_revoked",None,None,Cache::Done));}
   let tx=owner_tx(&mut db,r).await?;
   crate::admin_actions::int4_guard(&tx,"oauth_tokens",a.token_id).await?;
   // ap_db_cmdr_nova_user_id: the cmdr_nova row's id, else 1. Legacy rows with
   // no user_id belong to that account.
   // PHP's LIMIT 1 has no order, so two such rows are unproven.
   let rows=tx.query("SELECT id::bigint FROM ap_users WHERE actor_key = 'cmdr_nova' LIMIT 2",&[]).await?;
   if rows.len()>1{bail!("PHP fallback: two cmdr_nova rows");}
   let cmdr=rows.first().map(|row|row.get::<_,i64>(0)).filter(|id|*id>0).unwrap_or(1);
   let sql=if r.owner_id==cmdr{"UPDATE oauth_tokens SET revoked_at = $1 WHERE id::bigint = $2 AND revoked_at IS NULL AND (user_id::bigint = $3 OR user_id IS NULL)"}
    else{"UPDATE oauth_tokens SET revoked_at = $1 WHERE id::bigint = $2 AND revoked_at IS NULL AND user_id::bigint = $3"};
   let revoked=tx.execute(sql,&[&now_c(),&a.token_id,&r.owner_id]).await?;
   tx.commit().await?;
   Ok(reply(r,if revoked>0{"revoked"}else{"not_revoked"},None,(revoked>0).then_some(a.token_id),Cache::Done))
  },
 }
}
/// The write transaction, holding the signed-in account's row: it must still be
/// active, with the actor key PHP sent. No admin check: Settings are personal.
async fn owner_tx<'a>(db:&'a mut tokio_postgres::Client,r:&Request)->Result<tokio_postgres::Transaction<'a>>{
 let tx=db.transaction().await?;
 let row=tx.query_opt("SELECT actor_key::text FROM ap_users WHERE id::bigint=$1 AND disabled_at IS NULL FOR SHARE",&[&r.owner_id]).await?;
 if row.and_then(|row|row.get::<_,Option<String>>(0)).as_deref()!=Some(r.actor_key.as_str()){bail!("current active account required");}
 Ok(tx)
}
/// The cache work after a committed change; what did not happen here goes back to PHP.
async fn clear(cfg:&crate::config::Config,db:&tokio_postgres::Client,owner:i64,muted:bool)->Cache{
 let left=if muted{Cache::Full}else{Cache::Global};
 match tokio::time::timeout(Duration::from_secs(5),clear_inner(cfg,db,owner,muted)).await{
  Ok(Ok(()))=>Cache::Done,
  Ok(Err(e))=>{tracing::info!(error=%e,"native settings cache clear left to PHP");left},
  Err(_)=>{tracing::info!("native settings cache clear timed out; left to PHP");left},
 }
}
/// ap_muted_words_cache_clear($owner) when `muted`, then admin_tl_cache_clear().
/// The owner-index walk in admin_tl_cache_clear_owner only removes keys and
/// files the global clear removes too, so only its fragment pattern is kept.
/// Every check runs before the first Redis write, so a refusal leaves all of
/// it to PHP rather than half of it.
async fn clear_inner(cfg:&crate::config::Config,db:&tokio_postgres::Client,owner:i64,muted:bool)->Result<()>{
 if !crate::settings_profile_save::redis_enabled(){bail!("Redis disabled");}
 let refill=if muted{Some(phrases_json(db,owner).await?)}else{None};
 let dir=std::path::PathBuf::from(std::env::var("VAAK_ADMIN_TL_CACHE_DIR").unwrap_or_else(|_|"/var/lib/mkultra/ap/admin-tl-cache".into()));
 // admin_tl_cache_dir falls back to the temp dir when this one is not usable;
 // that case is PHP's.
 if !std::fs::metadata(&dir).map(|m|m.is_dir()).unwrap_or(false){bail!("timeline cache dir missing");}
 let mut cache=tokio::time::timeout(Duration::from_millis(250),crate::redis_util::connect(&cfg.redis_url)).await.context("redis connect timeout")??;
 if muted{
  // ap_muted_words_phrases_cached(owner, true) deletes the key and, finding it
  // gone, stores the fresh list again.
  let _:()=redis::cmd("SET").arg(format!("vaak:moderation:muted-words:{owner}")).arg(refill.as_deref().unwrap_or("[]")).arg("EX").arg(relset_ttl()).query_async(&mut cache).await?;
  crate::redis_util::delete_pattern(&mut cache,&format!("vaak:fragment:tl:v1:{owner}:*")).await?;
  let key=format!("vaak:trends:policy-epoch:v1:{owner}");
  let _:i64=redis::cmd("INCR").arg(&key).query_async(&mut cache).await?;
  let _:i64=redis::cmd("EXPIRE").arg(&key).arg(86400*30).query_async(&mut cache).await?;
 }
 for pattern in ["vaak:timeline:ranked:v1:*","vaak:timeline:ranked:v2:*","vaak:timeline:owner-index:v1:*"]{crate::redis_util::delete_pattern(&mut cache,pattern).await?;}
 for entry in std::fs::read_dir(&dir)?{
  let entry=entry?;let name=entry.file_name();let name=name.to_string_lossy();
  if name.starts_with("tl_")&&name.ends_with(".json"){let _=std::fs::remove_file(entry.path());}
 }
 Ok(())
}

/// The list ap_muted_words_phrases_cached stores: each phrase mb_strtolower'd
/// and trimmed, empties dropped, first occurrence kept, newest first, encoded
/// with JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE.
async fn phrases_json(db:&tokio_postgres::Client,owner:i64)->Result<String>{
 let mut out:Vec<String>=Vec::new();
 for row in db.query("SELECT phrase::text FROM ap_muted_words WHERE owner_user_id::bigint = $1 ORDER BY created_at DESC, id DESC",&[&owner]).await?{
  let phrase:String=row.get(0);
  // json_encode still escapes U+2028 and U+2029 under JSON_UNESCAPED_UNICODE.
  if phrase.contains(['\u{2028}','\u{2029}']){bail!("PHP fallback: line separator in a phrase");}
  let p=lower(php_trim(&phrase))?;
  if !p.is_empty()&&!out.contains(&p){out.push(p);}
 }
 Ok(serde_json::to_string(&out)?)
}
/// ap_redis_relset_ttl, read from the worker's own env.
fn relset_ttl()->i64{
 std::env::var("VAAK_REDIS_RELSET_TTL").ok().map(|v|php_trim(&v).to_string()).filter(|v|!v.is_empty()&&v.bytes().all(|b|b.is_ascii_digit())).map(|v|v.parse::<i64>().unwrap_or(i64::MAX).clamp(60,900)).unwrap_or(300)
}

#[cfg(test)]
mod tests{
 use super::*;
 fn env(action:&str,fields:Value)->Value{json!({"protocol_version":1,"owner_id":2,"actor_key":"someone","action":action,"fields":fields})}
 #[test]fn envelope_is_closed(){
  assert!(decode(&serde_json::to_vec(&env("muted_word_add",json!({"phrase":"x","note":""}))).unwrap()).is_ok());
  assert!(decode(&serde_json::to_vec(&env("muted_word_remove",json!({"id":3}))).unwrap()).is_ok());
  assert!(decode(&serde_json::to_vec(&env("revoke_oauth_token",json!({"token_id":3}))).unwrap()).is_ok());
  assert!(decode(&serde_json::to_vec(&env("revoke_oauth_token",json!({"id":3}))).unwrap()).is_err());
  for bad in[env("muted_word_add",json!({"phrase":"x"})),env("muted_word_add",json!({"phrase":"x","note":"","owner":1})),env("muted_word_remove",json!({"id":"3"})),env("user_ban",json!({"user_id":1})),json!({"protocol_version":1,"owner_id":0,"actor_key":"a","action":"muted_word_remove","fields":{"id":1}})]{
   assert!(decode(&serde_json::to_vec(&bad).unwrap()).is_err(),"{bad}");
  }
 }
 #[test]fn lowercase_leaves_special_cases_to_php(){
  assert_eq!(lower("ÉTÉ Spoilers").unwrap(),"été spoilers");
  assert_eq!(lower("🙂 cats").unwrap(),"🙂 cats");
  assert!(lower("İstanbul").is_err());
  assert!(lower("ΟΔΟΣ").is_err());
 }
}
