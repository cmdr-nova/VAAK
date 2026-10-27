//! PHP profile/count caches and connected Bluesky graph presentation.
//! Private server dependencies; none of these rows grant source access.
use anyhow::{bail,Context,Result};
use serde_json::{json,Value};
use sha2::{Digest,Sha256};
use tokio_postgres::Client;
use crate::{config::Config,redis_util,library::card};
fn digest(v:&str)->String{hex::encode(Sha256::digest(v.as_bytes()))}
// PHP casts integer strings exactly before considering decimal/exponent forms.
// Unrepresentable JSON floats require the complete PHP fallback, never wrapping.
const COUNT_PREFIX_PATTERN:&str=r"^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?";
static COUNT_PREFIX:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(COUNT_PREFIX_PATTERN).unwrap());
static BSKY_HANDLE:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"(?i)^[a-z0-9][a-z0-9._:-]*$").unwrap());
fn count_integer(v:&Value)->Result<i64>{
 match v{
  Value::Number(n)=>{if let Some(i)=n.as_i64(){return Ok(i)}let f=n.as_f64().unwrap_or(0.0);if !f.is_finite()||f<(i64::MIN as f64)||f>=(i64::MAX as f64){bail!("PHP fallback: numeric count outside native integer range")}Ok(f as i64)},
  Value::String(raw)=>{let raw=raw.trim_start_matches([' ','\t','\n','\r','\u{b}','\u{c}']);let Some(m)=COUNT_PREFIX.find(raw)else{return Ok(0)};let value=m.as_str();if !value.contains(['.','e','E']){return Ok(value.parse::<i64>().unwrap_or(if value.starts_with('-'){i64::MIN}else{i64::MAX}))}Ok(value.parse::<f64>().unwrap_or(0.0)as i64)},
  _=>Ok(card::integer(&json!({"v":v}),"v")),
 }
}
// One multiplexed Redis connection shared by production complete-profile
// requests (it pipelines concurrent commands); a failed or timed-out command
// drops it so the next call reconnects. Fixtures keep a connection per call.
static SHARED:std::sync::Mutex<Option<(String,redis::aio::MultiplexedConnection)>>=std::sync::Mutex::new(None);
struct Conn{url:String,conn:redis::aio::MultiplexedConnection,shared:bool}
async fn connection(cfg:Option<&Config>)->Option<Conn>{
 if std::env::var("VAAK_REDIS_ENABLED").is_ok_and(|v|matches!(card::php_trim(&v).to_ascii_lowercase().as_str(),"0"|"false"|"off"|"no")){return None}let cfg=cfg?;let shared=crate::profile_bridge::in_production_request();
 if shared{if let Some(conn)=SHARED.lock().ok().and_then(|g|g.as_ref().filter(|(url,_)|*url==cfg.redis_url).map(|(_,c)|c.clone())){return Some(Conn{url:cfg.redis_url.clone(),conn,shared})}}
 let started=std::time::Instant::now();let conn=tokio::time::timeout(std::time::Duration::from_millis(80),redis_util::connect(&cfg.redis_url)).await;crate::profile_bridge::record_stage(crate::profile_bridge::Stage::HeaderRedis,started);let conn=conn.ok()?.ok()?;
 if shared{if let Ok(mut g)=SHARED.lock(){*g=Some((cfg.redis_url.clone(),conn.clone()));}}Some(Conn{url:cfg.redis_url.clone(),conn,shared})
}
impl Conn{
 fn forget(&self){if self.shared{if let Ok(mut g)=SHARED.lock(){if g.as_ref().is_some_and(|(url,_)|*url==self.url){*g=None;}}}}
 async fn get(&mut self,key:&str)->Option<Value>{let started=std::time::Instant::now();let r=tokio::time::timeout(std::time::Duration::from_millis(80),redis_util::json_get(&mut self.conn,key)).await;crate::profile_bridge::record_stage(crate::profile_bridge::Stage::HeaderRedis,started);match r{Ok(Ok(v))=>v,_=>{self.forget();None}}}
 async fn set(&mut self,key:&str,value:&Value,ttl:u64){let started=std::time::Instant::now();let r=tokio::time::timeout(std::time::Duration::from_millis(80),redis_util::json_set(&mut self.conn,key,value,ttl)).await;crate::profile_bridge::record_stage(crate::profile_bridge::Stage::HeaderRedis,started);if !matches!(r,Ok(Ok(()))){self.forget();}}
}
pub(crate) async fn read_json(cfg:&Config,key:&str)->Option<Value>{connection(Some(cfg)).await?.get(key).await}
pub(crate) async fn write_json(cfg:&Config,key:&str,value:&Value,ttl:u64){if let Some(mut conn)=connection(Some(cfg)).await{conn.set(key,value,ttl).await;}}
pub(super) async fn profile(cfg:Option<&Config>,key:&str,current:&Value)->Value{
 let cache_key=format!("vaak:profile:v1:{}",digest(card::php_trim(key)));let mut conn=connection(cfg).await;
 if let Some(c)=conn.as_mut(){if let Some(value)=c.get(&cache_key).await{if value.is_object()&&["actor_key","name","summary"].iter().all(|k|!value[*k].is_null()){return value}}}
 let mut profile=super::profile_defaults(key);
 if !current.is_null(){
  for k in ["actor_key","name","summary"]{profile[k]=json!(card::scalar_string(current,k));}profile["updated_at"]=current["updated_at"].clone();
  profile["attachment"]=serde_json::from_str::<Value>(super::s(current,"attachment_json")).ok().filter(|v|v.is_array()||v.is_object()).unwrap_or_else(||profile["attachment"].clone());
  for k in ["icon_url","image_url"]{profile[k]=if super::s(current,k).is_empty(){Value::Null}else{current[k].clone()};}
  for k in ["manually_approves","discoverable","indexable","collection_consent","vanity_verified","auto_follow_back","anti_ai_marker","auto_unblur_sensitive","auto_delete_posts_7d","automated","hide_profile_replies","hide_profile_boosts","algorithm_enabled","downranking_enabled","asks_enabled","webmentions_enabled"]{if current.get(k).is_some(){profile[k]=json!(card::boolean(current,k));}}
  for k in ["reply_policy","quote_policy"]{let v=super::s(current,k);profile[k]=json!(if matches!(v,"anyone"|"followers"|"nobody"){v}else{"anyone"});}
  profile["forum_signature"]=json!(card::php_trim(super::s(current,"forum_signature")));profile["profile_badges"]=json!(super::normalize_badges(&current["profile_badges"]));
 }
 if let Some(c)=conn.as_mut(){c.set(&cache_key,&profile,60).await;}profile
}
pub(super) async fn count(db:&Client,cfg:Option<&Config>,key:&str,ttl:u64,sql:&str,args:&[&(dyn tokio_postgres::types::ToSql+Sync)])->Result<i64>{
 let mut conn=connection(cfg).await;if let Some(c)=conn.as_mut(){if let Some(value)=c.get(key).await{if value.is_object()&&!value["count"].is_null(){return Ok(count_integer(&value["count"])?.max(0));}}}
 let count=db.query_one(sql,args).await?.get::<_,i64>(0).max(0);
 if let Some(c)=conn.as_mut(){c.set(key,&json!({"count":count}),ttl).await;}Ok(count)
}
pub(super) fn graph_key(kind:&str,actor:&str)->String{format!("vaak:follow-graph:v1:{kind}-count:{}",digest(actor.trim_end_matches('/')))}
pub(super) fn graph_ttl()->u64{std::env::var("VAAK_REDIS_RELSET_TTL").ok().and_then(|v|{let v=card::php_trim(&v);if !v.is_empty()&&v.bytes().all(|b|b.is_ascii_digit()){Some(v.parse::<u64>().unwrap_or(u64::MAX))}else{None}}).unwrap_or(300).clamp(60,900)}
pub(super) struct Combined{pub(super) handle:String,pub(super) followers:i64,pub(super) following:i64}
pub(super) async fn combined(db:&Client,key:&str,followers:i64,following:i64,enqueue:bool)->Result<Combined>{crate::profile_bridge::timed(crate::profile_bridge::Stage::HeaderBsky,combined_current(db,key,followers,following,enqueue)).await}
async fn combined_current(db:&Client,key:&str,followers:i64,following:i64,enqueue:bool)->Result<Combined>{
 let mut out=Combined{handle:String::new(),followers,following};if !super::exists_table(db,"bsky_sessions").await?{return Ok(out)}
 let Some(row)=db.query_opt("SELECT s.owner_user_id::bigint,s.handle FROM bsky_sessions s INNER JOIN ap_users u ON u.id=s.owner_user_id WHERE lower(u.actor_key)=$1 OR lower(u.username)=$1 LIMIT 1",&[&key]).await?else{return Ok(out)};
 let uid:i64=row.get(0);let handle=card::php_trim(&row.get::<_,Option<String>>(1).unwrap_or_default()).trim_start_matches('@').to_owned();let lower=handle.to_ascii_lowercase();if !lower.ends_with(".brid.gy"){out.handle=handle;}
 let mut graph=false;
 if uid>0&&super::exists_table(db,"bsky_graph_sync").await?{if let Some(row)=db.query_opt("SELECT bsky_uri FROM bsky_graph_sync WHERE owner_user_id::bigint=$1 AND kind='meta' AND target_did='graph_sync_v1' LIMIT 1",&[&uid]).await?{if let Ok(v)=serde_json::from_str::<Value>(&row.get::<_,Option<String>>(0).unwrap_or_default()){if v.is_object()||v.is_array(){let edge_f=count_integer(&v["followers"])?.max(0);let edge_g=count_integer(&v["following"])?.max(0);out.followers=out.followers.checked_add(v.get("profile_followers").map(count_integer).transpose()?.unwrap_or(edge_f).max(edge_f)).context("PHP fallback: combined follower count exceeds native integer")?;out.following=out.following.checked_add(v.get("profile_following").map(count_integer).transpose()?.unwrap_or(edge_g).max(edge_g)).context("PHP fallback: combined following count exceeds native integer")?;graph=true;}}}}
 if !graph&&!out.handle.is_empty(){let valid=BSKY_HANDLE.is_match(&out.handle);if valid{let path=std::env::temp_dir().join("vaak-bsky-counts").join(format!("{}.json",digest(&lower)));if let Ok(raw)=tokio::fs::read(path).await{if let Ok(v)=serde_json::from_slice::<Value>(&raw){if !v["followers"].is_null()&&!v["following"].is_null()&&card::boolean(&v,"ok"){out.followers=out.followers.checked_add(count_integer(&v["followers"])?).context("PHP fallback: cached combined follower count exceeds native integer")?;out.following=out.following.checked_add(count_integer(&v["following"])?).context("PHP fallback: cached combined following count exceeds native integer")?;}}}}}
 if enqueue&&uid>0{if let Err(error)=enqueue_follow_sync(db,uid).await{tracing::warn!(%error,"profile Bluesky follow sync enqueue failed");}}Ok(out)
}
async fn enqueue_follow_sync(db:&Client,uid:i64)->Result<()>{
 for table in ["bsky_actor_profiles","bsky_actor_viewers","bsky_actor_refresh_queue"]{if !super::exists_table(db,table).await?{return Ok(())}}
 let now=chrono::Utc::now();let actor="__vaak_sync__:follows";
 if let Some(row)=db.query_opt("SELECT status,queued_at FROM bsky_actor_refresh_queue WHERE owner_user_id::bigint=$1 AND actor_ref=$2 LIMIT 1",&[&uid,&actor]).await?{let status=row.get::<_,Option<String>>(0).unwrap_or_default();let at=row.get::<_,Option<String>>(1).and_then(|v|chrono::DateTime::parse_from_rfc3339(&v).ok().map(|t|t.timestamp()).or_else(||chrono::NaiveDateTime::parse_from_str(&v,"%Y-%m-%d %H:%M:%S").ok().map(|t|t.and_utc().timestamp())));if matches!(status.as_str(),"pending"|"processing")||(status=="succeeded"&&at.is_some_and(|t|t>now.timestamp()-600)){return Ok(())}}
 let time=now.to_rfc3339_opts(chrono::SecondsFormat::Secs,false);
 db.execute("INSERT INTO bsky_actor_refresh_queue(owner_user_id,actor_ref,status,queued_at,next_attempt_at,attempts) VALUES($1::bigint,$2,'pending',$3,$3,0) ON CONFLICT(owner_user_id,actor_ref) DO UPDATE SET status='pending',queued_at=excluded.queued_at,next_attempt_at=excluded.next_attempt_at,attempts=0,locked_at=NULL,last_error=NULL WHERE bsky_actor_refresh_queue.status IN ('succeeded','failed')",&[&uid,&actor,&time]).await?;Ok(())
}

#[cfg(test)]mod tests{use super::*;
 #[test]fn count_prefix_regex_is_compiled_once_and_matches_a_fresh_compile(){let fresh=regex::Regex::new(COUNT_PREFIX_PATTERN).unwrap();assert_eq!(COUNT_PREFIX.as_str(),fresh.as_str());assert!(std::ptr::eq(&*COUNT_PREFIX,&*COUNT_PREFIX));for raw in["","12","+12abc","-0012.5e3x",".5",".","1e",".e1","  7","abc","9223372036854775808","-9223372036854775809","1.9e400"]{assert_eq!(COUNT_PREFIX.find(raw).map(|m|m.as_str()),fresh.find(raw).map(|m|m.as_str()),"{raw}");}
  for(raw,expected)in[("12",12),("+12abc",12),("-0012.5e3x",-12500),(".5",0),("abc",0),("9223372036854775808",i64::MAX),("-9223372036854775809",i64::MIN)]{assert_eq!(count_integer(&json!(raw)).unwrap(),expected,"{raw}");}}
 #[test]fn actual_php_count_cast_components()->Result<()>{let script=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/profile-header-count-components-reference.php");let output=std::process::Command::new(script.parent().unwrap().join("php-policy-test")).arg(script).output()?;anyhow::ensure!(output.status.success(),"PHP count component failed");let data:Value=serde_json::from_slice(&output.stdout)?;for c in data["cases"].as_array().unwrap(){assert_eq!(count_integer(&c["input"])?,c["php_int"].as_i64().unwrap(),"{}",c["input"]);}assert!(count_integer(&json!(1e30)).is_err());assert!(i64::MAX.checked_add(1).is_none());Ok(())}
}
