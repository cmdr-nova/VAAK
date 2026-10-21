//! Default-off native Settings > Profile save (S4a), copied from
//! ap_auth_user_set_email, ap_profile_save / ap_profile_save_unlocked and the
//! rel=me helpers in api/ap-db.php. PHP keeps the session, CSRF, avatar and
//! header uploads, the cache and export work after a save, the follower
//! Update, the Bluesky sync and the notice.
//!
//! Every input these copies cannot prove they read the way PHP does (mojibake
//! repair, HTML entities, unusual URLs or emails) answers 422 before any write,
//! so PHP saves instead. The email update is idempotent, so a fallback after it
//! only sets the same email again.
use anyhow::{bail, Context, Result};
use axum::{body::Bytes,http::{HeaderMap,StatusCode},response::{IntoResponse,Response}};
use regex::bytes::Regex as BytesRegex;
use serde::Deserialize;
use serde_json::{json,Value};
use std::sync::LazyLock;
use std::time::Duration;
use crate::library::card::php_trim;
use crate::profile_preview_fetch::PreviewFetchTransport;

#[derive(Debug,Deserialize)]
#[serde(deny_unknown_fields)]
struct Request {protocol_version:u8,owner_id:i64,actor_key:String,email:String,fields:Fields}
#[derive(Debug,Deserialize)]
#[serde(deny_unknown_fields)]
struct Fields {
 name:String,summary:String,attachment:Vec<Field>,icon_url:String,image_url:String,
 manually_approves:bool,discoverable:bool,indexable:bool,collection_consent:bool,vanity_verified:bool,
 auto_follow_back:bool,anti_ai_marker:bool,auto_unblur_sensitive:bool,auto_delete_posts_7d:bool,automated:bool,
 reply_policy:String,quote_policy:String,hide_profile_replies:bool,hide_profile_boosts:bool,algorithm_enabled:bool,
 trending_enabled:bool,downranking_enabled:bool,asks_enabled:bool,webmentions_enabled:bool,forum_signature:String,
 profile_badges:Vec<Value>,
}
#[derive(Debug,Deserialize)]
#[serde(deny_unknown_fields)]
struct Field {name:String,value:String}
/// One stored profile field: PropertyValue name, value HTML and the rel=me time.
#[derive(Debug,Clone,PartialEq)]
struct Item {name:String,value:String,verified_at:Option<String>}
/// The canonical row ap_profile_save_unlocked builds before its upsert.
#[derive(Debug)]
struct Plan {name:String,summary:String,attachment:Vec<Item>,icon_url:Option<String>,image_url:Option<String>,forum_signature:String}

fn enabled()->bool{std::env::var("VAAK_SETTINGS_PROFILE_SAVE_NATIVE").ok().as_deref()==Some("1")}
fn decode(bytes:&[u8])->Result<Request>{
 if bytes.is_empty()||bytes.len()>65536{bail!("profile save envelope size");}
 let r:Request=serde_json::from_slice(bytes)?;
 if r.protocol_version!=1||r.owner_id<1||r.actor_key.is_empty()||r.actor_key.len()>200||r.fields.attachment.len()>8||r.fields.profile_badges.len()>200{bail!("profile save envelope");}
 Ok(r)
}
pub(crate) async fn save_route(cfg:&crate::config::Config,headers:HeaderMap,bytes:Bytes)->Response{
 if !crate::admin_read::token_ok(&headers){return StatusCode::UNAUTHORIZED.into_response();}
 if !enabled(){return StatusCode::NOT_FOUND.into_response();}
 let request=match decode(&bytes){Ok(r)=>r,Err(_)=>return StatusCode::BAD_REQUEST.into_response()};
 match save(cfg,&request).await{Ok(reply)=>(StatusCode::OK,axum::Json(reply)).into_response(),Err(e)=>{tracing::info!(error=%e,"native profile save fell back to PHP");(StatusCode::UNPROCESSABLE_ENTITY,axum::Json(json!({"fallback":true}))).into_response()}}
}
fn reply(r:&Request,error:Option<&str>,email_saved:bool,trends_changed:bool,algorithm_changed:bool,after_save:bool)->Value{
 json!({"protocol_version":1,"ok":error.is_none(),"error":error,"email_saved":email_saved,"trends_changed":trends_changed,"algorithm_changed":algorithm_changed,"after_save":after_save,"request_binding":{"owner_id":r.owner_id,"actor_key":r.actor_key}})
}

async fn save(cfg:&crate::config::Config,r:&Request)->Result<Value>{
 let db=crate::db::connect(&cfg.database_url).await?;
 let account=db.query_opt("SELECT actor_key::text FROM ap_users WHERE id::bigint=$1 AND disabled_at IS NULL",&[&r.owner_id]).await?;
 if account.map(|row|row.get::<_,String>(0)).as_deref()!=Some(r.actor_key.as_str()){bail!("current active account required");}
 // ap_auth_user_set_email: the address is checked before any profile work, as
 // PHP returns its error without touching the profile.
 let email=php_trim(&r.email);
 if !email.is_empty(){
  if !email_valid(email)?{return Ok(reply(r,Some("Enter a valid email address."),false,false,false,false));}
  if email_taken(&db,email,r.owner_id).await?{return Ok(reply(r,Some("That email is already used by another account."),false,false,false,false));}
 }
 if db.query_opt("SELECT 1 FROM actor_profile WHERE actor_key=$1",&[&r.actor_key]).await?.is_none(){bail!("PHP fallback: no stored profile row");}
 let planned=plan(&r.fields)?;
 let planned=match planned{Ok(p)=>{
  let transport=crate::profile_preview_transport::ProcessPreviewTransport::configured(cfg.clone());
  let mut p=p;
  tokio::time::timeout(Duration::from_secs(45),verify_attachments(&mut p.attachment,&r.actor_key,&transport)).await.context("PHP fallback: rel=me checks over budget")??;
  Ok(p)
 },Err(e)=>Err(e)};
 let now=chrono::Utc::now().format("%Y-%m-%dT%H:%M:%S+00:00").to_string();
 if email.is_empty(){
  db.execute("UPDATE ap_users SET email=NULL,updated_at=$1 WHERE id::bigint=$2",&[&now,&r.owner_id]).await?;
 }else{
  let updated=db.execute("UPDATE ap_users SET email=$1,updated_at=$2 WHERE id::bigint=$3 AND NOT EXISTS (SELECT 1 FROM ap_users o WHERE lower(o.email)=lower($1) AND o.id::bigint<>$3 AND o.disabled_at IS NULL)",&[&email,&now,&r.owner_id]).await;
  match updated{
   Ok(0)=>return Ok(reply(r,Some("That email is already used by another account."),false,false,false,false)),
   Ok(_)=>{},
   Err(e) if e.code()==Some(&tokio_postgres::error::SqlState::UNIQUE_VIOLATION)=>return Ok(reply(r,Some("Could not save email."),false,false,false,false)),
   Err(e)=>return Err(e.into()),
  }
 }
 let plan=match planned{Ok(p)=>p,Err(message)=>return Ok(reply(r,Some(message),true,false,false,false))};
 let (trends,algorithm)=write(&db,r,&plan).await?;
 // The row is committed: from here nothing may answer 422, so a failed
 // after-save step only hands that work back to PHP.
 let after=match after_save(cfg,&db,&r.actor_key,trends,algorithm).await{Ok(done)=>done,Err(e)=>{tracing::info!(error=%e,"native profile after-save left to PHP");false}};
 Ok(reply(r,None,true,trends,algorithm,after))
}
pub(crate) fn redis_enabled()->bool{std::env::var("VAAK_REDIS_ENABLED").map(|v|!matches!(php_trim(&v).to_ascii_lowercase().as_str(),"0"|"false"|"off"|"no")).unwrap_or(true)}
fn sha256(s:&str)->String{use sha2::Digest;hex::encode(sha2::Sha256::digest(s.as_bytes()))}
/// PHP rawurlencode.
fn rawurlencode(s:&str)->String{s.bytes().map(|b|if b.is_ascii_alphanumeric()||matches!(b,b'-'|b'_'|b'.'|b'~'){char::from(b).to_string()}else{format!("%{b:02X}")}).collect()}
/// ap_profile_after_save (S4b): the profile cache delete, the trends epoch,
/// admin_tl_cache_clear_owner and the Home hydrate keys when the algorithm
/// switch changed, the homepage export for cmdr_nova and the public actor
/// document. Ok(false) leaves all of it to PHP (Redis off, or export text the
/// copy cannot prove); every step is idempotent, so PHP may redo a part.
async fn after_save(cfg:&crate::config::Config,db:&tokio_postgres::Client,actor_key:&str,trends:bool,algorithm:bool)->Result<bool>{
 if !redis_enabled(){return Ok(false);}
 let export=if actor_key=="cmdr_nova"{match export_json(db).await?{Some(json)=>Some(json),None=>return Ok(false)}}else{None};
 let mut cache=tokio::time::timeout(Duration::from_millis(250),crate::redis_util::connect(&cfg.redis_url)).await.context("redis connect timeout")??;
 let _:i64=redis::cmd("DEL").arg(format!("vaak:profile:v1:{}",sha256(php_trim(actor_key)))).query_async(&mut cache).await?;
 let owner=if trends||algorithm{db.query_opt("SELECT id::bigint FROM ap_users WHERE actor_key=$1",&[&actor_key]).await?.map(|row|row.get::<_,i64>(0)).unwrap_or(0)}else{0};
 if trends&&owner>0{
  let key=format!("vaak:trends:policy-epoch:v1:{owner}");
  let _:i64=redis::cmd("INCR").arg(&key).query_async(&mut cache).await?;
  let _:i64=redis::cmd("EXPIRE").arg(&key).arg(86400*30).query_async(&mut cache).await?;
 }
 if algorithm&&owner>0{
  let index=format!("vaak:timeline:owner-index:v1:{owner}");
  let dir=std::env::var("VAAK_ADMIN_TL_CACHE_DIR").unwrap_or_else(|_|"/var/lib/mkultra/ap/admin-tl-cache".into());
  let keys=match crate::redis_util::json_get(&mut cache,&index).await?{Some(Value::Array(keys))=>keys,Some(Value::Object(map))=>map.into_iter().map(|(_,v)|v).collect(),_=>Vec::new()};
  for key in keys.iter().filter_map(Value::as_str).filter(|k|!k.is_empty()){
   let _:i64=redis::cmd("DEL").arg(format!("vaak:timeline:ranked:v2:{}",sha256(key))).query_async(&mut cache).await?;
   let safe=key.chars().filter(|c|c.is_ascii_lowercase()||c.is_ascii_digit()||*c=='_').collect::<String>();
   let safe=if safe.is_empty()||safe=="0"{"tl".to_owned()}else{safe};
   let _=std::fs::remove_file(std::path::Path::new(&dir).join(format!("tl_{safe}.json")));
  }
  let _:i64=redis::cmd("DEL").arg(&index).query_async(&mut cache).await?;
  crate::redis_util::delete_pattern(&mut cache,&format!("vaak:fragment:tl:v1:{owner}:*")).await?;
  let mut del=redis::cmd("DEL");
  for limit in [15,40,50,80,160,240,20,30]{del.arg(crate::timeline::home_timeline_redis_key(owner,limit,None));}
  let _:i64=del.query_async(&mut cache).await?;
 }
 if let Some(json)=export{
  // Best effort, as PHP's @mkdir/@file_put_contents: a directory the worker
  // cannot write must not hand the rest of the after-save work back to PHP,
  // which would only fail the same write again.
  let root=std::env::var_os("VAAK_API_ROOT").map(std::path::PathBuf::from).unwrap_or_else(||"/srv/mkultra/html/api".into());
  let dir=root.parent().context("site root")?.join("assets/data");
  if let Err(e)=std::fs::create_dir_all(&dir).and_then(|_|std::fs::write(dir.join("ap-cmdr-nova.json"),json)){
   tracing::info!(error=%e,"native profile homepage snapshot not written");
  }
 }
 let doc=format!("https://mkultra.monster/users/{}",rawurlencode(&php_trim(actor_key).to_ascii_lowercase()));
 let _:i64=redis::cmd("DEL").arg(format!("vaak:apdoc:v1:{}",sha256(php_trim(&doc).trim_end_matches('/')))).query_async(&mut cache).await?;
 Ok(true)
}
/// ap_profile_export_public_cache payload for the homepage, from the row just
/// written. None when ap_fix_utf8 could repair the stored name or bio.
async fn export_json(db:&tokio_postgres::Client)->Result<Option<String>>{
 let row=db.query_one("SELECT name::text,summary::text,icon_url::text,image_url::text,updated_at::text FROM actor_profile WHERE actor_key='cmdr_nova'",&[]).await?;
 let (name,summary):(String,String)=(row.get(0),row.get(1));
 if fix_utf8(&name).is_err()||fix_utf8(&summary).is_err(){return Ok(None);}
 let url=|v:Option<String>|v.filter(|v|!v.is_empty()).map(|v|json_string(&v)).unwrap_or_else(||"null".into());
 let updated=row.get::<_,Option<String>>(4).map(|v|json_string(&v)).context("export updated_at")?;
 Ok(Some(format!("{{\n    \"name\": {},\n    \"summary\": {},\n    \"icon_url\": {},\n    \"image_url\": {},\n    \"url\": \"https://mkultra.monster/users/cmdr_nova\",\n    \"preferredUsername\": \"cmdr_nova\",\n    \"updated_at\": {}\n}}\n",json_string(&name),json_string(&summary),url(row.get(2)),url(row.get(3)),updated)))
}
async fn email_taken(db:&tokio_postgres::Client,email:&str,owner:i64)->Result<bool>{
 Ok(db.query_opt("SELECT 1 FROM ap_users WHERE lower(email)=lower($1) AND id::bigint<>$2 AND disabled_at IS NULL LIMIT 1",&[&email,&owner]).await?.is_some())
}
/// The locked half of ap_profile_save: same advisory lock key as PHP's session
/// lock, the existing preferences, account-derived badges and the upsert.
/// Returns whether the trends preferences and the algorithm switch changed.
async fn write(db:&tokio_postgres::Client,r:&Request,p:&Plan)->Result<(bool,bool)>{
 let f=&r.fields;
 db.batch_execute("BEGIN ISOLATION LEVEL READ COMMITTED").await?;
 let result=async{
  db.execute("SELECT pg_advisory_xact_lock(hashtext($1))",&[&format!("vaak:profile-save:{}",php_trim(&r.actor_key).to_ascii_lowercase())]).await?;
  let Some(existing)=db.query_opt("SELECT COALESCE(algorithm_enabled,0)<>0,COALESCE(trending_enabled,1)<>0,COALESCE(downranking_enabled,0)<>0,COALESCE(reply_policy,'')::text,COALESCE(quote_policy,'')::text FROM actor_profile WHERE actor_key=$1",&[&r.actor_key]).await? else {bail!("PHP fallback: no stored profile row")};
  let (prev_algorithm,prev_trending,prev_downranking):(bool,bool,bool)=(existing.get(0),existing.get(1),existing.get(2));
  let policy=|asked:&str,stored:String|->String{if POLICIES.contains(&asked){asked.into()}else if POLICIES.contains(&stored.as_str()){stored}else{"anyone".into()}};
  let reply_policy=policy(&f.reply_policy,existing.get(3));let quote_policy=policy(&f.quote_policy,existing.get(4));
  let badges=authorized_badges(db,&f.profile_badges,&r.actor_key).await?;
  let int=|b:bool|->i32{i32::from(b)};
  let attachment=attachment_json(&p.attachment);let badges=json_list(&badges);
  let now=chrono::Utc::now().format("%Y-%m-%dT%H:%M:%S+00:00").to_string();
  db.execute("INSERT INTO actor_profile (actor_key, name, summary, attachment_json, icon_url, image_url, manually_approves, discoverable, indexable, collection_consent, vanity_verified, auto_follow_back, anti_ai_marker, auto_unblur_sensitive, auto_delete_posts_7d, automated, reply_policy, quote_policy, forum_signature, profile_badges, hide_profile_replies, hide_profile_boosts, algorithm_enabled, downranking_enabled, asks_enabled, webmentions_enabled, trending_enabled, updated_at)
   VALUES ($1::text,$2::text,$3::text,$4::text,$5::text,$6::text,$7::int4,$8::int4,$9::int4,$10::int4,$11::int4,$12::int4,$13::int4,$14::int4,$15::int4,$16::int4,$17::text,$18::text,$19::text,$20::text,$21::int4,$22::int4,$23::int4,$24::int4,$25::int4,$26::int4,$27::int4,$28::text)
   ON CONFLICT(actor_key) DO UPDATE SET name=excluded.name,summary=excluded.summary,attachment_json=excluded.attachment_json,icon_url=excluded.icon_url,image_url=excluded.image_url,manually_approves=excluded.manually_approves,discoverable=excluded.discoverable,indexable=excluded.indexable,collection_consent=excluded.collection_consent,vanity_verified=excluded.vanity_verified,auto_follow_back=excluded.auto_follow_back,anti_ai_marker=excluded.anti_ai_marker,auto_unblur_sensitive=excluded.auto_unblur_sensitive,auto_delete_posts_7d=excluded.auto_delete_posts_7d,automated=excluded.automated,reply_policy=excluded.reply_policy,quote_policy=excluded.quote_policy,forum_signature=excluded.forum_signature,profile_badges=excluded.profile_badges,hide_profile_replies=excluded.hide_profile_replies,hide_profile_boosts=excluded.hide_profile_boosts,algorithm_enabled=excluded.algorithm_enabled,trending_enabled=excluded.trending_enabled,downranking_enabled=excluded.downranking_enabled,asks_enabled=excluded.asks_enabled,webmentions_enabled=excluded.webmentions_enabled,updated_at=excluded.updated_at",
   &[&r.actor_key,&p.name,&p.summary,&attachment,&p.icon_url,&p.image_url,&int(f.manually_approves),&int(f.discoverable),&int(f.indexable),&int(f.collection_consent),&int(f.vanity_verified),&int(f.auto_follow_back),&int(f.anti_ai_marker),&int(f.auto_unblur_sensitive),&int(f.auto_delete_posts_7d),&int(f.automated),&reply_policy,&quote_policy,&p.forum_signature,&badges,&int(f.hide_profile_replies),&int(f.hide_profile_boosts),&int(f.algorithm_enabled),&int(f.downranking_enabled),&int(f.asks_enabled),&int(f.webmentions_enabled),&int(f.trending_enabled),&now]).await?;
  Ok::<_,anyhow::Error>((f.trending_enabled!=prev_trending||f.downranking_enabled!=prev_downranking,prev_algorithm!=f.algorithm_enabled))
 }.await;
 match result{
  Ok(changed)=>{db.batch_execute("COMMIT").await?;Ok(changed)},
  Err(e)=>{let _=db.batch_execute("ROLLBACK").await;Err(e)},
 }
}
const POLICIES:[&str;3]=["anyone","followers","nobody"];
/// ap_profile_authorized_badges: chosen flair first filtered to the selectable
/// catalog, then Admin and Supporter from current account authority.
async fn authorized_badges(db:&tokio_postgres::Client,chosen:&[Value],actor_key:&str)->Result<Vec<String>>{
 let mut out=crate::profile_header::normalize_selected_badges(&Value::Array(chosen.to_vec()),false);
 if db.query_opt("SELECT 1 FROM ap_users WHERE actor_key=$1 AND is_admin<>0 AND disabled_at IS NULL LIMIT 1",&[&actor_key]).await?.is_some(){out.insert(0,"admin".into());}
 let present:bool=db.query_one("SELECT to_regclass('ap_supporter_codes') IS NOT NULL",&[]).await?.get(0);
 if present&&db.query_opt("SELECT 1 FROM ap_supporter_codes c JOIN ap_users u ON u.id=c.used_by WHERE u.actor_key=$1 AND u.disabled_at IS NULL AND c.used_at IS NOT NULL LIMIT 1",&[&actor_key]).await?.is_some(){out.insert(0,"supporter".into());}
 Ok(out)
}

/// The validation half of ap_profile_save_unlocked, before any fetch or write.
/// Inner Err is the message PHP shows; outer Err falls back to PHP.
fn plan(f:&Fields)->Result<std::result::Result<Plan,&'static str>>{
 let name=php_trim(fix_utf8(&f.name)?);
 if name.is_empty()||name.chars().count()>100{return Ok(Err("Display name required (max 100 chars)."));}
 let summary_in=php_trim(fix_utf8(&f.summary)?);
 if summary_in.is_empty(){return Ok(Err("Bio / summary required."));}
 if String::from_utf8(strip_tags(summary_in.as_bytes(),None))?.chars().count()>2000{return Ok(Err("Bio too long (max ~2000 chars of text)."));}
 let summary=if !summary_in.contains('<'){plain_bio_to_html(summary_in)?}else{
  let kept=strip_tags(summary_in.as_bytes(),Some("<p><br><br/><a><code><strong><em><b><i><ul><ol><li>"));
  normalize_summary_html(&String::from_utf8(safe_links(&kept,"nofollow noopener noreferrer"))?)?
 };
 if summary.is_empty(){return Ok(Err("Bio / summary required."));}
 let forum_signature=php_trim(fix_utf8(&f.forum_signature)?);
 if forum_signature.chars().count()>500||forum_signature.bytes().any(|b|matches!(b,0x00..=0x08|0x0B|0x0C|0x0E..=0x1F|0x7F)){return Ok(Err("Forum signature must be plain text with at most 500 characters."));}
 let mut attachment=Vec::new();
 for field in f.attachment.iter().take(8){
  let label=php_trim(fix_utf8(&field.name)?);let mut value=php_trim(fix_utf8(&field.value)?).to_owned();
  if label.is_empty()||value.is_empty()||label.chars().count()>64||value.chars().count()>500{continue;}
  if let Some(url)=coerce_https_url(&value)?{if !value.contains('<'){value=url;}}
  static PLAIN_URL:LazyLock<BytesRegex>=LazyLock::new(||BytesRegex::new(r"(?-u)^https://[^\s<]+\z").unwrap());
  let html=if PLAIN_URL.is_match(value.as_bytes()){let safe=htmlspecialchars(&value);format!(r#"<a href="{safe}" rel="me">{safe}</a>"#)}
   else if value.contains("<a "){String::from_utf8(safe_links(&strip_tags(value.as_bytes(),Some("<a>")),"me"))?}
   else{htmlspecialchars(&value)};
  attachment.push(Item{name:label.into(),value:html,verified_at:None});
 }
 let icon_url=sanitize_https_url(&f.icon_url)?;let image_url=sanitize_https_url(&f.image_url)?;
 if !f.icon_url.is_empty()&&icon_url.is_none(){return Ok(Err("Avatar URL must be https://"));}
 if !f.image_url.is_empty()&&image_url.is_none(){return Ok(Err("Header URL must be https://"));}
 Ok(Ok(Plan{name:name.into(),summary,attachment,icon_url,image_url,forum_signature:forum_signature.into()}))
}

/// ap_fix_utf8 on text that is already valid UTF-8 changes it only when its
/// mojibake pattern (â. Ã. Â. ðŸ) matches, so that text goes to PHP.
fn fix_utf8(s:&str)->Result<&str>{
 if s.contains("ðŸ"){bail!("PHP fallback: possible mojibake repair");}
 let mut chars=s.chars().peekable();
 while let Some(c)=chars.next(){if matches!(c,'â'|'Ã'|'Â')&&chars.peek().is_some(){bail!("PHP fallback: possible mojibake repair");}}
 Ok(s)
}
fn php_isspace(b:u8)->bool{matches!(b,b' '|b'\t'|b'\n'|0x0B|0x0C|b'\r')}
/// PHP php_strip_tags_ex (ext/standard/string.c) with allow_tag_spaces off:
/// the same states, quote and depth tracking, comment, <? and <!DOCTYPE rules.
pub(crate) fn strip_tags(buf:&[u8],allow:Option<&str>)->Vec<u8>{
 let allow=allow.map(|a|a.to_ascii_lowercase());
 let end=buf.len();let at=|i:usize|->u8{if i<end{buf[i]}else{0}};
 let mut out=Vec::with_capacity(end);let mut tbuf:Vec<u8>=Vec::new();
 let (mut br,mut depth,mut in_q,mut state,mut lc,mut is_xml)=(0i64,0i64,0u8,0u8,0u8,false);
 let mut p=0usize;
 while p<end{
  let c=buf[p];
  match state{
   0=>match c{
    0=>{},
    b'<'=>{if in_q==0{if php_isspace(at(p+1)){out.push(c);}else{lc=b'<';state=1;if allow.is_some(){tbuf.push(b'<');}}}},
    b'>'=>{if depth>0{depth-=1;}else if in_q==0{out.push(c);}},
    _=>out.push(c),
   },
   1=>{
    let mut reg=false;
    match c{
     0=>{},
     b'<'=>{if in_q==0{if php_isspace(at(p+1)){reg=true;}else{depth+=1;}}},
     b'>'=>{
      if depth>0{depth-=1;}else if in_q==0{
       lc=b'>';
       if !(is_xml&&p>=1&&buf[p-1]==b'-'){
        in_q=0;state=0;is_xml=false;
        if let Some(set)=&allow{tbuf.push(b'>');if tag_find(&tbuf,set.as_bytes()){out.extend_from_slice(&tbuf);}tbuf.clear();}
       }
      }
     },
     b'"'|b'\''=>{if p!=0&&(in_q==0||c==in_q){in_q=if in_q!=0{0}else{c};}reg=true;},
     b'!'=>{if p>=1&&buf[p-1]==b'<'{state=3;lc=c;}else{reg=true;}},
     b'?'=>{if p>=1&&buf[p-1]==b'<'{br=0;state=2;}else{reg=true;}},
     _=>reg=true,
    }
    if reg&&allow.is_some(){tbuf.push(c);}
   },
   2=>match c{
    b'('=>{if lc!=b'"'&&lc!=b'\''{lc=b'(';br+=1;}},
    b')'=>{if lc!=b'"'&&lc!=b'\''{lc=b')';br-=1;}},
    b'>'=>{if depth>0{depth-=1;}else if in_q==0&&br==0&&p>=1&&lc!=b'"'&&buf[p-1]==b'?'{in_q=0;state=0;tbuf.clear();}},
    b'"'|b'\''=>{if p>=1&&buf[p-1]!=b'\\'{if lc==c{lc=0;}else if lc!=b'\\'{lc=c;}
     if p!=0&&(in_q==0||c==in_q){in_q=if in_q!=0{0}else{c};}}},
    b'l'|b'L' if p>4&&buf[p-1].eq_ignore_ascii_case(&b'm')&&buf[p-2].eq_ignore_ascii_case(&b'x')&&buf[p-3]==b'?'&&buf[p-4]==b'<'=>{state=1;is_xml=true;},
    _=>{},
   },
   3=>match c{
    b'>'=>{if depth>0{depth-=1;}else if in_q==0{in_q=0;state=0;tbuf.clear();}},
    b'"'|b'\''=>{if p!=0&&buf[p-1]!=b'\\'&&(in_q==0||c==in_q){in_q=if in_q!=0{0}else{c};}},
    b'-'=>{if p>=2&&buf[p-1]==b'-'&&buf[p-2]==b'!'{state=4;}},
    b'E'|b'e' if p>6&&buf[p-6..p].eq_ignore_ascii_case(b"doctyp")=>{state=1;},
    _=>{},
   },
   _=>{if c==b'>'&&in_q==0&&p>=2&&buf[p-1]==b'-'&&buf[p-2]==b'-'{in_q=0;state=0;tbuf.clear();}},
  }
  p+=1;
 }
 out
}
/// PHP php_tag_find: <a href=…> and </a> both normalize to <a>, then a
/// substring search of the allow list.
fn tag_find(tag:&[u8],set:&[u8])->bool{
 if tag.is_empty(){return false;}
 let mut norm=Vec::new();let mut started=false;let mut t=0;
 while t<tag.len(){
  let c=tag[t].to_ascii_lowercase();
  match c{
   b'<'=>norm.push(c),
   b'>'=>break,
   _=>if !php_isspace(c){started=true;if c!=b'/'||(tag[t-1]!=b'<'&&tag.get(t+1)!=Some(&b'>')){norm.push(c);}}else if started{break;},
  }
  t+=1;
 }
 norm.push(b'>');
 set.windows(norm.len()).any(|w|w==norm.as_slice())
}
/// The `<a\s+([^>]*?)>` callback in ap_profile_save_unlocked: keep only an
/// https href and set rel, or drop every attribute.
fn safe_links(html:&[u8],rel:&str)->Vec<u8>{
 static OPEN:LazyLock<BytesRegex>=LazyLock::new(||BytesRegex::new(r"(?i-u)<a\s+([^>]*?)>").unwrap());
 OPEN.replace_all(html,|c:&regex::bytes::Captures|->Vec<u8>{
  match quoted_href(&c[1]){
   Some(href) if href.starts_with(b"https://")=>{let href=String::from_utf8_lossy(href);format!(r#"<a href="{}" rel="{rel}">"#,htmlspecialchars(&href)).into_bytes()},
   _=>b"<a>".to_vec(),
  }
 }).into_owned()
}
/// `href\s*=\s*(['"])(https?:\/\/[^'"]+)\1` (case-insensitive, no word
/// boundary): the leftmost href whose quoted value starts with http(s)://.
fn quoted_href(attrs:&[u8])->Option<&[u8]>{
 (0..attrs.len()).find_map(|i|{
  let v=quoted_value(attrs,i,b"href",false,true)?;
  let lower=v.to_ascii_lowercase();
  (lower.starts_with(b"https://")||lower.starts_with(b"http://")).then_some(v).filter(|v|v.len()>if lower.starts_with(b"https://"){8}else{7})
 })
}
/// One attempt of `NAME\s*=\s*(['"])([^'"]*)\1` at byte i. `boundary` adds the
/// leading \b; `plus` needs at least one value byte.
fn quoted_value<'a>(s:&'a [u8],i:usize,name:&[u8],boundary:bool,plus:bool)->Option<&'a [u8]>{
 let word=|b:u8|b.is_ascii_alphanumeric()||b==b'_';
 if boundary&&i>0&&word(s[i-1]){return None;}
 if s.len()<i+name.len()||!s[i..i+name.len()].eq_ignore_ascii_case(name){return None;}
 let mut j=i+name.len();while j<s.len()&&php_isspace(s[j]){j+=1;}
 if s.get(j)!=Some(&b'='){return None;}j+=1;while j<s.len()&&php_isspace(s[j]){j+=1;}
 let q=*s.get(j).filter(|q|matches!(q,b'"'|b'\''))?;let start=j+1;let mut k=start;
 while k<s.len()&&!matches!(s[k],b'"'|b'\''){k+=1;}
 if s.get(k)!=Some(&q)||(plus&&k==start){return None;}
 Some(&s[start..k])
}
fn htmlspecialchars(s:&str)->String{
 let mut out=String::with_capacity(s.len());
 for c in s.chars(){match c{'&'=>out.push_str("&amp;"),'"'=>out.push_str("&quot;"),'\''=>out.push_str("&#039;"),'<'=>out.push_str("&lt;"),'>'=>out.push_str("&gt;"),c=>out.push(c)}}
 out
}
/// ap_profile_plain_bio_to_html.
fn plain_bio_to_html(plain:&str)->Result<String>{
 static RUNS:LazyLock<regex::Regex>=LazyLock::new(||regex::Regex::new(r"\n{3,}").unwrap());
 static SPLIT:LazyLock<regex::Regex>=LazyLock::new(||regex::Regex::new(r"\n{2,}").unwrap());
 let plain=fix_utf8(plain)?.replace("\r\n","\n").replace('\r',"\n");
 let plain=RUNS.replace_all(&plain,"\n\n");let plain=php_trim(&plain);
 if plain.is_empty(){return Ok(String::new());}
 let blocks=SPLIT.split(plain).map(php_trim).filter(|p|!p.is_empty()).map(|p|format!("<p>{}</p>",htmlspecialchars(p).replace('\n',"<br>\n"))).collect::<Vec<_>>();
 Ok(blocks.join("\n"))
}
/// ap_profile_normalize_summary_html.
fn normalize_summary_html(html:&str)->Result<String>{
 static RULES:LazyLock<Vec<(BytesRegex,&'static [u8])>>=LazyLock::new(||[
  (r"(?i-u)<br\s*/?>",&b"<br>"[..]),(r"(?i-u)</p>\s*(?:<br>\s*)+</p>",b"</p>"),(r"(?i-u)</p>\s*(?:<br>\s*)+<p\b",b"</p><p"),
  (r"(?i-u)(?:<br>\s*){3,}",b"<br>\n<br>\n"),(r"(?i-u)<p>\s*(?:<br>\s*)*</p>",b""),
 ].into_iter().map(|(p,r)|(BytesRegex::new(p).unwrap(),r)).collect());
 let html=php_trim(fix_utf8(html)?);
 if html.is_empty(){return Ok(String::new());}
 let mut bytes=html.as_bytes().to_vec();
 for (rule,with) in RULES.iter(){bytes=rule.replace_all(&bytes,regex::bytes::NoExpand(with)).into_owned();}
 Ok(php_trim(&String::from_utf8(bytes)?).to_owned())
}

/// The part of PHP parse_url these copies rely on, for `https://` URLs only:
/// a plain host of letters, digits, dots, hyphens and underscores, then path,
/// query and fragment. Ports, user info, brackets, control bytes or an empty
/// host fall back to PHP. Returns (host, path, query).
fn parse_https(url:&str)->Result<(&str,&str,Option<&str>)>{
 if url.len()<8||!url.as_bytes()[..8].eq_ignore_ascii_case(b"https://"){bail!("PHP fallback: not an https URL");}
 let rest=&url[8..];
 if rest.bytes().any(|b|b<0x20||b==0x7f){bail!("PHP fallback: URL control byte");}
 let cut=rest.find(['/','?','#']).unwrap_or(rest.len());let (host,tail)=rest.split_at(cut);
 if host.is_empty()||host=="0"||!host.bytes().all(|b|b.is_ascii_alphanumeric()||matches!(b,b'.'|b'-'|b'_')){bail!("PHP fallback: unusual URL host");}
 let tail=tail.split('#').next().unwrap_or("");
 Ok(match tail.split_once('?'){Some((path,query))=>(host,path,Some(query)),None=>(host,tail,None)})
}
/// ap_profile_normalize_verify_url (callers pass https URLs).
fn normalize_verify_url(url:&str)->Result<String>{
 let url=php_trim(url).trim_end_matches(['.',',',')',';',']','!','?','\'','"']);
 let (host,path,query)=parse_https(url)?;
 Ok(format!("https://{}{}{}",host.to_ascii_lowercase(),path,query.filter(|q|!q.is_empty()).map(|q|format!("?{q}")).unwrap_or_default()))
}
/// html_entity_decode(…, ENT_QUOTES | ENT_HTML5, 'UTF-8') for the entities
/// htmlspecialchars writes and plain numeric references. Any other named
/// entity, or a numeric one outside printable ranges, goes to PHP. As in PHP an
/// `&` without a closing `;` stays literal.
fn entity_decode(s:&str)->Result<String>{
 let b=s.as_bytes();let mut out=String::with_capacity(s.len());let mut i=0;let mut copied=0;
 while let Some(off)=s[i..].find('&'){
  let amp=i+off;let mut j=amp+1;
  let decoded=if b.get(j)==Some(&b'#'){
   j+=1;let hex=matches!(b.get(j),Some(b'x'|b'X'));if hex{j+=1;}
   let start=j;while j<b.len()&&(if hex{b[j].is_ascii_hexdigit()}else{b[j].is_ascii_digit()}){j+=1;}
   if j==start||b.get(j)!=Some(&b';')||j-start>8{bail!("PHP fallback: unusual numeric entity");}
   let cp=u32::from_str_radix(&s[start..j],if hex{16}else{10})?;
   if !(matches!(cp,0x09|0x0A|0x0C|0x20..=0x7E|0xA0..=0xD7FF)){bail!("PHP fallback: unusual numeric entity");}
   Some(char::from_u32(cp).context("entity code point")?.to_string())
  }else{
   let start=j;while j<b.len()&&b[j].is_ascii_alphanumeric(){j+=1;}
   if j>start&&b.get(j)==Some(&b';'){
    Some(match &s[start..j]{"amp"=>"&","lt"=>"<","gt"=>">","quot"=>"\"","apos"=>"'",_=>bail!("PHP fallback: named entity outside the proven set")}.to_owned())
   }else{None}
  };
  match decoded{Some(text)=>{out.push_str(&s[copied..amp]);out.push_str(&text);i=j+1;copied=i;},None=>{i=amp+1;}}
 }
 out.push_str(&s[copied..]);Ok(out)
}
/// ap_profile_coerce_https_url.
fn coerce_https_url(value:&str)->Result<Option<String>>{
 let raw=php_trim(value);
 if raw.is_empty(){return Ok(None);}
 let bytes=raw.as_bytes();
 let href=(0..bytes.len()).find_map(|i|quoted_value(bytes,i,b"href",false,true).filter(|v|v.len()>8&&v[..8].eq_ignore_ascii_case(b"https://")));
 if let Some(href)=href{return Ok(Some(normalize_verify_url(std::str::from_utf8(href)?)?));}
 let decoded=entity_decode(&String::from_utf8(strip_tags(bytes,None))?)?;
 let plain=php_trim(&decoded);
 if plain.is_empty(){return Ok(None);}
 static URL:LazyLock<BytesRegex>=LazyLock::new(||BytesRegex::new(r#"(?i-u)^https://[^\s<>"']+\z"#).unwrap());
 static BARE:LazyLock<BytesRegex>=LazyLock::new(||BytesRegex::new(r"(?i-u)^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}(/[^\s]*)?\z").unwrap());
 if URL.is_match(plain.as_bytes()){return Ok(Some(normalize_verify_url(plain)?));}
 if plain.contains(['@',' ']){return Ok(None);}
 if BARE.is_match(plain.as_bytes()){return Ok(Some(normalize_verify_url(&format!("https://{plain}"))?));}
 Ok(None)
}
/// ap_profile_sanitize_https_url for avatar and header URLs. IP literal hosts
/// go to PHP and its filter_var ranges.
fn sanitize_https_url(raw:&str)->Result<Option<String>>{
 let url=php_trim(raw);
 if url.is_empty()||!url.starts_with("https://")||url.len()>800{return Ok(None);}
 let rest=&url[8..];
 if rest[..rest.find('/').unwrap_or(rest.len())].contains('@'){return Ok(None);}
 if url.bytes().any(|b|b<0x20||b==0x7f){return Ok(None);}
 let (host,_,_)=parse_https(url)?;let host=host.to_ascii_lowercase();
 if matches!(host.as_str(),"localhost"|"127.0.0.1"){return Ok(None);}
 if host.bytes().all(|b|b.is_ascii_digit()||b==b'.'){bail!("PHP fallback: IP literal media host");}
 Ok(Some(url.into()))
}
/// FILTER_VALIDATE_EMAIL accepts every dot-atom address of this shape; anything
/// else (quoted local parts, IP domains, unusual characters) goes to PHP, as
/// does an address PHP would refuse for length.
fn email_valid(email:&str)->Result<bool>{
 static SHAPE:LazyLock<regex::Regex>=LazyLock::new(||regex::Regex::new(r"(?i-u)^[a-z0-9!#$%&'*+/=?^_`{|}~-]+(\.[a-z0-9!#$%&'*+/=?^_`{|}~-]+)*@([a-z0-9]+(-+[a-z0-9]+)*\.)+[a-z][a-z0-9]*(-+[a-z0-9]+)*\z").unwrap());
 if email.len()>200{return Ok(false);}
 let Some((local,domain))=email.split_once('@') else {bail!("PHP fallback: unproven email")};
 if SHAPE.is_match(email)&&local.len()<=64&&domain.split('.').all(|l|l.len()<64){return Ok(true);}
 bail!("PHP fallback: unproven email")
}

/// ap_profile_identity_urls.
fn identity_urls(actor_key:&str)->Vec<String>{
 let key=actor_key.to_ascii_lowercase().bytes().filter(|b|b.is_ascii_lowercase()||b.is_ascii_digit()||*b==b'_').map(char::from).collect::<String>();
 if key.is_empty(){return Vec::new();}
 ["https://mkultra.monster/users/","https://mkultra.monster/vaak/users/","https://mkultra.monster/@","https://vaak.monster/users/","https://vaak.monster/vaak/users/","https://www.vaak.monster/users/"].iter().map(|p|format!("{p}{key}")).collect()
}
/// ap_profile_urls_match. `b` is always an identity URL; `a` starts with https://.
fn urls_match(a:&str,b:&str)->Result<bool>{
 let norm=|u:&str|->Result<String>{
  let u=php_trim(u).to_ascii_lowercase();
  let (host,path,query)=parse_https(&u)?;
  let host=host.strip_prefix("www.").unwrap_or(host);
  let path=if path.is_empty()||path=="/"{""}else{path.trim_end_matches('/')};
  Ok(format!("{host}{path}{}",query.filter(|q|!q.is_empty()).map(|q|format!("?{q}")).unwrap_or_default()))
 };
 Ok(norm(a)?==norm(b)?)
}
struct Always;
impl crate::profile_quote_warm::CurrentQuoteGrant for Always{fn require_current(&self)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'_>>{Box::pin(async{Ok(())})}}
/// ap_profile_verify_attachments with ap_profile_verify_rel_me_url: the
/// guarded, pinned link preview GET, then a rel=me backlink to an identity URL.
async fn verify_attachments(items:&mut [Item],actor_key:&str,transport:&crate::profile_preview_transport::ProcessPreviewTransport)->Result<()>{
 let identity=identity_urls(actor_key);
 for item in items.iter_mut(){
  let Some(url)=coerce_https_url(&item.value)? else {continue};
  let Some(page)=coerce_https_url(&url)? else {continue};
  let html=transport.http_get(&page,5,524288,&Always).await.ok().flatten();
  let Some(html)=html.filter(|h|!h.is_empty()) else {continue};
  if has_rel_me(&html,&identity).await?{item.verified_at=Some(chrono::Utc::now().format("%Y-%m-%dT%H:%M:%S.000Z").to_string());}
 }
 Ok(())
}
/// The rel=me scan of ap_profile_html_has_rel_me. A link that does not name an
/// identity URL itself gets one HEAD (ap_profile_url_redirects_to, pinned here)
/// whose Location is checked against every identity URL.
async fn has_rel_me(html:&str,targets:&[String])->Result<bool>{
 if html.is_empty()||targets.is_empty(){return Ok(false);}
 for href in rel_me_hrefs(html)?{
  for target in targets{if urls_match(&href,target)?{return Ok(true);}}
  if let Some(location)=redirect_location(&href).await{
   if location.to_ascii_lowercase().starts_with("https://"){for target in targets{if urls_match(&location,target)?{return Ok(true);}}}
  }
 }
 Ok(false)
}
/// Absolute https hrefs of every <a>/<link> whose rel names "me", in page order.
fn rel_me_hrefs(html:&str)->Result<Vec<String>>{
 static TAG:LazyLock<BytesRegex>=LazyLock::new(||BytesRegex::new(r"(?i-u)<(a|link)\b([^>]*)>").unwrap());
 let mut out=Vec::new();
 for c in TAG.captures_iter(html.as_bytes()){
  let attrs=&c[2];
  let Some(rel)=(0..attrs.len()).find_map(|i|quoted_value(attrs,i,b"rel",true,false)) else {continue};
  let rel=String::from_utf8_lossy(rel).to_ascii_lowercase();
  if !php_trim(&rel).split(|c:char|php_isspace(c as u8)&&c.is_ascii()).any(|r|r=="me"){continue;}
  let Some(href)=(0..attrs.len()).find_map(|i|quoted_value(attrs,i,b"href",true,true)) else {continue};
  let raw=std::str::from_utf8(href)?;
  if raw.contains('\u{FFFD}'){bail!("PHP fallback: rel=me href with undecodable bytes");}
  let href=entity_decode(php_trim(raw))?;
  if href.is_empty()||!href.to_ascii_lowercase().starts_with("https://"){continue;}
  out.push(href);
 }
 Ok(out)
}
/// One-hop HEAD like ap_profile_url_redirects_to: link preview eligibility
/// first, no redirects followed, the first Location header.
async fn redirect_location(url:&str)->Option<String>{
 let dns=crate::direct_preview::SystemDns;
 let vetted=crate::direct_preview::eligible_addrs(url,&dns).await.ok().flatten()?;
 let parsed=reqwest::Url::parse(url).ok()?;let host=parsed.host_str()?.to_ascii_lowercase();
 let port=parsed.port_or_known_default().unwrap_or(443);
 if port!=443{return None;}
 let pinned=vetted.iter().map(|ip|std::net::SocketAddr::new(*ip,port)).collect::<Vec<_>>();
 let client=reqwest::Client::builder().redirect(reqwest::redirect::Policy::none()).https_only(true).timeout(Duration::from_secs(4)).connect_timeout(Duration::from_secs(3)).user_agent("mkultra.monster-rel-me-verify/1.0 (+https://mkultra.monster)").resolve_to_addrs(&host,&pinned).build().ok()?;
 let response=client.head(url).send().await.ok()?;
 let location=response.headers().get(reqwest::header::LOCATION)?.to_str().ok()?;
 let location=location.split(|c:char|php_isspace(c as u8)&&c.is_ascii()).find(|s|!s.is_empty())?;
 Some(location.to_owned())
}

/// json_encode with JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE.
pub(crate) fn json_string(s:&str)->String{
 let mut out=String::from("\"");
 for c in s.chars(){match c{
  '"'=>out.push_str("\\\""),'\\'=>out.push_str("\\\\"),'\u{8}'=>out.push_str("\\b"),'\u{c}'=>out.push_str("\\f"),'\n'=>out.push_str("\\n"),'\r'=>out.push_str("\\r"),'\t'=>out.push_str("\\t"),
  '\u{2028}'=>out.push_str("\\u2028"),'\u{2029}'=>out.push_str("\\u2029"),
  c if (c as u32)<0x20=>out.push_str(&format!("\\u{:04x}",c as u32)),c=>out.push(c),
 }}
 out.push('"');out
}
fn json_list(items:&[String])->String{format!("[{}]",items.iter().map(|s|json_string(s)).collect::<Vec<_>>().join(","))}
fn attachment_json(items:&[Item])->String{
 format!("[{}]",items.iter().map(|i|format!(r#"{{"type":"PropertyValue","name":{},"value":{}{}}}"#,json_string(&i.name),json_string(&i.value),i.verified_at.as_ref().map(|v|format!(r#","verified_at":{}"#,json_string(v))).unwrap_or_default())).collect::<Vec<_>>().join(","))
}

#[cfg(test)]
mod tests{
 use super::*;
 #[test]fn strip_tags_keeps_allowed_tags_only(){
  let allow=Some("<p><br><br/><a><code><strong><em><b><i><ul><ol><li>");
  for (input,want) in [("<p>Hi <span class=\"x\">there</span></p>","<p>Hi there</p>"),("a<br/>b<BR>c","a<br/>b<BR>c"),("x < y","x < y"),("<!-- c -->ok","ok"),("<?php echo 1; ?>ok","ok"),("<a href=\"x>y\">t</a>","<a href=\"xy\">t</a>"),("<script>alert(1)</script>","alert(1)"),("tail <b","tail "),("a\0b","ab")]{
   assert_eq!(String::from_utf8(strip_tags(input.as_bytes(),allow)).unwrap(),want,"{input}");
  }
  assert_eq!(String::from_utf8(strip_tags(b"<p>one</p> <em>two</em>",None)).unwrap(),"one two");
 }
 #[test]fn links_keep_only_https_hrefs(){
  assert_eq!(String::from_utf8(safe_links(br#"<a href="https://x.test/a?b=1&c" onclick="z">"#,"me")).unwrap(),r#"<a href="https://x.test/a?b=1&amp;c" rel="me">"#);
  assert_eq!(String::from_utf8(safe_links(br#"<a href='http://x.test/'>"#,"me")).unwrap(),"<a>");
  assert_eq!(String::from_utf8(safe_links(br#"<A  HREF="HTTPS://x.test/">"#,"me")).unwrap(),"<a>");
  assert_eq!(String::from_utf8(safe_links(br#"<a data-href="https://x.test/">"#,"me")).unwrap(),r#"<a href="https://x.test/" rel="me">"#);
 }
 #[test]fn plain_bios_become_paragraphs(){
  assert_eq!(plain_bio_to_html("one\r\ntwo\n\n\n\nthree & <four>").unwrap(),"<p>one<br>\ntwo</p>\n<p>three &amp; &lt;four&gt;</p>");
  assert_eq!(normalize_summary_html("<p>a</p><br><br><p>b</p><br/><br /><br>c<p> </p>").unwrap(),"<p>a</p><p>b</p><br>\n<br>\nc");
 }
 #[test]fn mojibake_goes_to_php(){
  assert!(fix_utf8("Itâ€™s").is_err());assert!(fix_utf8("ðŸ˜€").is_err());assert!(fix_utf8("plain café").is_ok());assert!(fix_utf8("ends Â").is_ok());
 }
 #[test]fn urls_are_coerced_like_php(){
  assert_eq!(coerce_https_url("mkultra.monster").unwrap().as_deref(),Some("https://mkultra.monster"));
  assert_eq!(coerce_https_url("HTTPS://Example.COM/Path?q=1#frag").unwrap().as_deref(),Some("https://example.com/Path?q=1"));
  assert_eq!(coerce_https_url(r#"<a href="https://x.test/u/me" rel="me">me</a>"#).unwrap().as_deref(),Some("https://x.test/u/me"));
  assert_eq!(coerce_https_url("@me@x.test").unwrap(),None);
  assert_eq!(coerce_https_url("https://x.test/a).").unwrap().as_deref(),Some("https://x.test/a"));
  assert!(coerce_https_url("https://user@x.test/").is_err());
  assert_eq!(coerce_https_url("x.test/?a=1&amp;b=2").unwrap().as_deref(),Some("https://x.test/?a=1&b=2"));
  assert_eq!(coerce_https_url("AT&T &amp; co").unwrap(),None);
  assert!(coerce_https_url("x.test/&hellip;").is_err());
  assert_eq!(entity_decode("&lt;3 &#039;q&#x27; &amp;amp; & &T").unwrap(),"<3 'q' &amp; & &T");
 }
 #[test]fn media_urls_are_sanitized_like_php(){
  assert_eq!(sanitize_https_url(" https://mkultra.monster/img/profile/avatar-1.png ").unwrap().as_deref(),Some("https://mkultra.monster/img/profile/avatar-1.png"));
  for bad in ["http://x.test/a.png","https://a@b.test/","https://localhost/a.png","https://x.test/a\u{1}"]{assert_eq!(sanitize_https_url(bad).unwrap(),None,"{bad}");}
  assert!(sanitize_https_url("https://10.0.0.1/a.png").is_err());
 }
 #[test]fn emails_outside_the_proven_shape_go_to_php(){
  assert!(email_valid("val@mkultra.monster").unwrap());assert!(email_valid("a.b+c@sub.example-x.co").unwrap());
  assert!(!email_valid(&format!("{}@x.test","a".repeat(200))).unwrap());
  for odd in ["no-at","\"q\"@x.test","a@[127.0.0.1]","a..b@x.test","a@x"]{assert!(email_valid(odd).is_err(),"{odd}");}
 }
 #[test]fn rel_me_scan_reads_rel_and_href_like_php(){
  let html=r#"<link rel="me" href="https://mkultra.monster/@val"><a href="/rel" rel="me"><a rel='nofollow me' href='https://www.mkultra.monster/users/val/'><abbr rel="me" href="https://x.test/"><a xrel="me" href="https://y.test/">"#;
  assert_eq!(rel_me_hrefs(html).unwrap(),["https://mkultra.monster/@val","https://www.mkultra.monster/users/val/"]);
  let ids=identity_urls("Val");
  assert!(urls_match("https://www.mkultra.monster/users/val/",&ids[0]).unwrap());
  assert!(!urls_match("https://mkultra.monster/users/val?x=1",&ids[0]).unwrap());
  assert_eq!(rel_me_hrefs(r#"<a rel="me" href="https://x.test/?a&amp;b">"#).unwrap(),["https://x.test/?a&b"]);
  assert!(rel_me_hrefs(r#"<a rel="me" href="https://x.test/&nbsp;">"#).is_err());
 }
 #[test]fn json_matches_php_flags(){
  assert_eq!(attachment_json(&[Item{name:"Site".into(),value:"<a href=\"https://x.test/\">\u{2028}é/</a>".into(),verified_at:Some("2026-10-21T00:00:00.000Z".into())}]),r#"[{"type":"PropertyValue","name":"Site","value":"<a href=\"https://x.test/\">\u2028é/</a>","verified_at":"2026-10-21T00:00:00.000Z"}]"#);
  assert_eq!(json_list(&[]),"[]");
 }
 #[test]fn envelope_is_closed(){
  let fields=json!({"name":"n","summary":"s","attachment":[],"icon_url":"","image_url":"","manually_approves":false,"discoverable":true,"indexable":true,"collection_consent":true,"vanity_verified":false,"auto_follow_back":false,"anti_ai_marker":false,"auto_unblur_sensitive":false,"auto_delete_posts_7d":false,"automated":false,"reply_policy":"anyone","quote_policy":"anyone","hide_profile_replies":false,"hide_profile_boosts":false,"algorithm_enabled":true,"trending_enabled":true,"downranking_enabled":true,"asks_enabled":true,"webmentions_enabled":true,"forum_signature":"","profile_badges":[]});
  let good=json!({"protocol_version":1,"owner_id":2,"actor_key":"val","email":"","fields":fields});
  assert!(decode(&serde_json::to_vec(&good).unwrap()).is_ok());
  for (k,v) in [("owner_id",json!(0)),("actor_key",json!("")),("protocol_version",json!(2)),("is_admin",json!(true))]{let mut bad=good.clone();bad[k]=v;assert!(decode(&serde_json::to_vec(&bad).unwrap()).is_err(),"{k}");}
  let mut extra=good.clone();extra["fields"]["profile_badges_extra"]=json!([]);assert!(decode(&serde_json::to_vec(&extra).unwrap()).is_err());
  let mut many=good.clone();many["fields"]["attachment"]=json!(vec![json!({"name":"a","value":"b"});9]);assert!(decode(&serde_json::to_vec(&many).unwrap()).is_err());
 }
 /// Byte-for-byte comparison with genuine PHP over the corpus written by
 /// api/bin/settings-profile-save-native-review.php --corpus.
 #[test]#[ignore="requires the genuine PHP corpus"]fn actual_php_helper_corpus(){
  let corpus:Value=serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_SAVE_CORPUS").unwrap_or_else(|_|"/tmp/vaak-profile-save-corpus.json".into())).unwrap()).unwrap();
  let mut compared=0;
  for case in corpus["cases"].as_array().unwrap(){
   let input=case["input"].as_str().unwrap();let want=&case["php"];
   let got:Option<Value>=match case["kind"].as_str().unwrap(){
    "strip_allow"=>Some(json!(String::from_utf8_lossy(&strip_tags(input.as_bytes(),Some("<p><br><br/><a><code><strong><em><b><i><ul><ol><li>"))))),
    "strip_a"=>Some(json!(String::from_utf8_lossy(&strip_tags(input.as_bytes(),Some("<a>"))))),
    "strip_none"=>Some(json!(String::from_utf8_lossy(&strip_tags(input.as_bytes(),None)))),
    "links"=>Some(json!(String::from_utf8_lossy(&safe_links(input.as_bytes(),"nofollow noopener noreferrer")))),
    "plain_bio"=>plain_bio_to_html(input).ok().map(|v|json!(v)),
    "normalize"=>normalize_summary_html(input).ok().map(|v|json!(v)),
    "coerce"=>coerce_https_url(input).ok().map(|v|json!(v)),
    "sanitize"=>sanitize_https_url(input).ok().map(|v|json!(v)),
    "email"=>email_valid(php_trim(input)).ok().map(|v|json!(v)),
    "rel_me"=>rel_me_hrefs(input).ok().map(|hrefs|{let ids=identity_urls("val");json!(hrefs.iter().map(|h|ids.iter().map(|t|urls_match(h,t)).collect::<Result<Vec<_>>>().map(|m|m.contains(&true))).collect::<Result<Vec<_>>>().map(|m|m.contains(&true)).ok())}),
    other=>panic!("unknown corpus kind {other}"),
   };
   if let Some(got)=got{if got!=Value::Null||case["kind"]!="rel_me"{assert_eq!(&got,want,"{} {:?}",case["kind"],input);compared+=1;}}
  }
  eprintln!("compared {compared} of {} PHP corpus answers",corpus["cases"].as_array().unwrap().len());
  assert!(compared*10>=corpus["cases"].as_array().unwrap().len()*8,"too many corpus fallbacks: {compared}");
 }
}
