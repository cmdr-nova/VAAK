//! Default-off native admin actions (A10), one action family per step, each
//! behind its own switch. A10a is the Home downranking vocabulary:
//! downrank_term_add, downrank_term_delete, downrank_signal_clear and
//! downrank_term_preview, copied from their ap-admin.php handlers,
//! admin_home_downrank_audit, ap_downrank_phrase_matches_text and
//! ap_trends_policy_epoch_bump(null). A10b is the instance policies:
//! policies_save_privacy, policies_save_conduct and policies_save_rules, copied
//! from ap_instance_doc_save and ap_instance_rules_save_from_text. A10c is
//! moderation reports: report_note, report_dismiss, report_ignore and
//! report_reopen, copied from ap_report_set_state and
//! ap_report_append_admin_note. A10d is user_ban and user_unban, copied from
//! ap_auth_user_set_disabled. A10e is invite_create and registration_open /
//! registration_close, copied from ap_auth_invite_create and
//! ap_registration_set_accepting; deciding applications (account provisioning
//! and the approval mail) stays PHP.
//!
//! PHP keeps the session, CSRF, the admin-only gate, the notice text and the
//! page. The worker checks again inside its write transaction that the account
//! is an active admin, holding that row so a ban cannot interleave. Anything
//! these copies cannot prove they read the way PHP does answers 422 before any
//! write, so PHP runs the action instead.
use anyhow::{bail, Result};
use axum::{body::Bytes,http::{HeaderMap,StatusCode},response::{IntoResponse,Response}};
use serde::Deserialize;
use serde_json::{json,Value};
use std::time::Duration;
use crate::admin_read::downranked::{phrase_matches,squash};
use crate::library::card::php_trim;
use crate::settings_profile_save::{json_string,strip_tags};

#[derive(Debug,Deserialize)]
#[serde(deny_unknown_fields)]
struct Request {protocol_version:u8,owner_id:i64,actor_key:String,#[serde(flatten)]action:Action}
#[derive(Debug,Deserialize)]
#[serde(tag="action",content="fields",deny_unknown_fields)]
enum Action {
 #[serde(rename="downrank_term_add")]TermAdd(TermAdd),
 #[serde(rename="downrank_term_delete")]TermDelete(TermDelete),
 #[serde(rename="downrank_signal_clear")]SignalClear(SignalClear),
 #[serde(rename="downrank_term_preview")]TermPreview(TermPreview),
 #[serde(rename="policies_save_privacy")]SavePrivacy(DocSave),
 #[serde(rename="policies_save_conduct")]SaveConduct(DocSave),
 #[serde(rename="policies_save_rules")]SaveRules(RulesSave),
 #[serde(rename="report_note")]ReportNote(Report),
 #[serde(rename="report_dismiss")]ReportDismiss(Report),
 #[serde(rename="report_ignore")]ReportIgnore(Report),
 #[serde(rename="report_reopen")]ReportReopen(Report),
 #[serde(rename="user_ban")]UserBan(UserTarget),
 #[serde(rename="user_unban")]UserUnban(UserTarget),
 #[serde(rename="invite_create")]InviteCreate(Invite),
 #[serde(rename="registration_open")]RegistrationOpen(NoFields),
 #[serde(rename="registration_close")]RegistrationClose(NoFields),
}
#[derive(Debug,Deserialize)]#[serde(deny_unknown_fields)]struct TermAdd {phrase:String,category:String}
#[derive(Debug,Deserialize)]#[serde(deny_unknown_fields)]struct TermDelete {term_id:i64}
#[derive(Debug,Deserialize)]#[serde(deny_unknown_fields)]struct SignalClear {actor_id:String}
#[derive(Debug,Deserialize)]#[serde(deny_unknown_fields)]struct TermPreview {preview_phrase:String}
#[derive(Debug,Deserialize)]#[serde(deny_unknown_fields)]struct DocSave {title:String,body:String}
#[derive(Debug,Deserialize)]#[serde(deny_unknown_fields)]struct RulesSave {rules_text:String}
#[derive(Debug,Deserialize)]#[serde(deny_unknown_fields)]struct Report {report_id:i64,admin_note:String,admin_handle:String}
#[derive(Debug,Deserialize)]#[serde(deny_unknown_fields)]struct UserTarget {user_id:i64}
#[derive(Debug,Deserialize)]#[serde(deny_unknown_fields)]struct Invite {note:String}
#[derive(Debug,Deserialize)]#[serde(deny_unknown_fields)]struct NoFields {}
impl Action {
 fn name(&self)->&'static str{match self{Action::TermAdd(_)=>"downrank_term_add",Action::TermDelete(_)=>"downrank_term_delete",Action::SignalClear(_)=>"downrank_signal_clear",Action::TermPreview(_)=>"downrank_term_preview",
  Action::SavePrivacy(_)=>"policies_save_privacy",Action::SaveConduct(_)=>"policies_save_conduct",Action::SaveRules(_)=>"policies_save_rules",
  Action::ReportNote(_)=>"report_note",Action::ReportDismiss(_)=>"report_dismiss",Action::ReportIgnore(_)=>"report_ignore",Action::ReportReopen(_)=>"report_reopen",
  Action::UserBan(_)=>"user_ban",Action::UserUnban(_)=>"user_unban",
  Action::InviteCreate(_)=>"invite_create",Action::RegistrationOpen(_)=>"registration_open",Action::RegistrationClose(_)=>"registration_close"}}
 fn family_enabled(&self)->bool{
  let switch=match self{Action::TermAdd(_)|Action::TermDelete(_)|Action::SignalClear(_)|Action::TermPreview(_)=>"VAAK_ADMIN_DOWNRANKING_ACTIONS_NATIVE",
   Action::SavePrivacy(_)|Action::SaveConduct(_)|Action::SaveRules(_)=>"VAAK_ADMIN_POLICIES_ACTIONS_NATIVE",
   Action::ReportNote(_)|Action::ReportDismiss(_)|Action::ReportIgnore(_)|Action::ReportReopen(_)=>"VAAK_ADMIN_REPORTS_ACTIONS_NATIVE",
   Action::UserBan(_)|Action::UserUnban(_)=>"VAAK_ADMIN_USERS_ACTIONS_NATIVE",
   Action::InviteCreate(_)|Action::RegistrationOpen(_)|Action::RegistrationClose(_)=>"VAAK_ADMIN_INVITES_ACTIONS_NATIVE"};
  std::env::var(switch).ok().as_deref()==Some("1")
 }
}

fn decode(bytes:&[u8])->Result<Request>{
 if bytes.is_empty()||bytes.len()>524288{bail!("admin action envelope size");}
 let r:Request=serde_json::from_slice(bytes)?;
 if r.protocol_version!=1||r.owner_id<1||r.actor_key.is_empty()||r.actor_key.len()>200{bail!("admin action envelope");}
 Ok(r)
}
pub(crate) async fn action_route(cfg:&crate::config::Config,headers:HeaderMap,bytes:Bytes)->Response{
 if !crate::admin_read::token_ok(&headers){return StatusCode::UNAUTHORIZED.into_response();}
 let request=match decode(&bytes){Ok(r)=>r,Err(_)=>return StatusCode::BAD_REQUEST.into_response()};
 if !request.action.family_enabled(){return StatusCode::NOT_FOUND.into_response();}
 match run(cfg,&request).await{Ok(reply)=>(StatusCode::OK,axum::Json(reply)).into_response(),Err(e)=>{tracing::info!(error=%e,action=request.action.name(),"native admin action fell back to PHP");(StatusCode::UNPROCESSABLE_ENTITY,axum::Json(json!({"fallback":true}))).into_response()}}
}
/// The reply: a closed outcome PHP turns into its notice or error, the preview
/// for downrank_term_preview, the saved rule count for policies_save_rules, the
/// new code for invite_create, and whether PHP still has to bump the server
/// trends epoch (only after a signal clear whose bump did not happen here).
fn reply(r:&Request,outcome:&str,preview:Value,php_epoch_bump:bool)->Value{reply_full(r,outcome,preview,php_epoch_bump,None,None)}
fn reply_count(r:&Request,outcome:&str,preview:Value,php_epoch_bump:bool,count:Option<usize>)->Value{reply_full(r,outcome,preview,php_epoch_bump,count,None)}
fn reply_full(r:&Request,outcome:&str,preview:Value,php_epoch_bump:bool,count:Option<usize>,code:Option<&str>)->Value{
 json!({"protocol_version":1,"ok":true,"outcome":outcome,"preview":preview,"php_epoch_bump":php_epoch_bump,"count":count,"code":code,
  "request_binding":{"owner_id":r.owner_id,"actor_key":r.actor_key,"action":r.action.name()}})
}
fn now_c()->String{chrono::Utc::now().format("%Y-%m-%dT%H:%M:%S+00:00").to_string()}
/// trim(preg_replace('/\s+/u', ' ', $s)). A NUL or U+180E (PCRE2 \s under UCP,
/// not Unicode White_Space) is unproven.
pub(crate) fn normalize_phrase(s:&str)->Result<String>{
 if s.contains(['\0','\u{180E}']){bail!("PHP fallback: unproven whitespace");}
 Ok(php_trim(&squash(s)).to_string())
}
/// substr($s, 0, $n) as PHP passes it to PostgreSQL: a cut inside a character
/// makes PHP's audit insert fail quietly, which this copy does not reproduce.
fn byte_prefix(s:&str,n:usize)->Result<&str>{
 if s.len()<=n{return Ok(s);}
 if !s.is_char_boundary(n){bail!("PHP fallback: audit text cut inside a character");}
 Ok(&s[..n])
}

async fn run(cfg:&crate::config::Config,r:&Request)->Result<Value>{
 let mut db=crate::db::connect(&cfg.database_url).await?;
 match &r.action{
  Action::TermAdd(a)=>{
   let phrase=normalize_phrase(&a.phrase)?;
   let stripped:String=a.category.chars().filter(|c|c.is_ascii_alphanumeric()||matches!(c,'_'|'-')).collect();
   let category=if ["protected_class","sexualized_harassment","custom"].contains(&stripped.as_str()){stripped}else{"custom".into()};
   let n=phrase.chars().count();
   if phrase.is_empty()||!(2..=120).contains(&n){return Ok(reply(r,"bad_phrase",Value::Null,false));}
   let audit_phrase=byte_prefix(&phrase,120)?;
   let tx=admin_tx(&mut db,r).await?;
   let now=now_c();
   tx.execute("INSERT INTO ap_home_downrank_terms (phrase, category, enabled, created_at, updated_at) VALUES ($1, $2, 1, $3, $4) ON CONFLICT (phrase) DO UPDATE SET category = EXCLUDED.category, enabled = 1, updated_at = EXCLUDED.updated_at",&[&phrase,&category,&now,&now]).await?;
   audit(&tx,r,"term_saved","",0,audit_phrase,&format!(r#"{{"category":{}}}"#,json_string(&category))).await?;
   tx.commit().await?;
   Ok(reply(r,"term_saved",Value::Null,false))
  },
  Action::TermDelete(a)=>{
   if a.term_id<1{return Ok(reply(r,"invalid_term",Value::Null,false));}
   let tx=admin_tx(&mut db,r).await?;
   // PHP binds the id as text; past int4 an INTEGER column raises, which PHP
   // reports as a failed update.
   if a.term_id>i64::from(i32::MAX){
    let kind:String=tx.query_one("SELECT format_type(atttypid, atttypmod) FROM pg_attribute WHERE attrelid = 'ap_home_downrank_terms'::regclass AND attname = 'id'",&[]).await?.get(0);
    if kind!="bigint"{bail!("PHP fallback: term id past int4");}
   }
   let old=tx.query_opt("SELECT phrase::text, category::text FROM ap_home_downrank_terms WHERE id::bigint = $1",&[&a.term_id]).await?;
   let (phrase,category)=old.map(|row|(row.get::<_,Option<String>>(0).unwrap_or_default(),row.get::<_,Option<String>>(1).unwrap_or_default())).unwrap_or_default();
   tx.execute("DELETE FROM ap_home_downrank_terms WHERE id::bigint = $1",&[&a.term_id]).await?;
   audit(&tx,r,"term_removed","",a.term_id,byte_prefix(&phrase,120)?,&format!(r#"{{"category":{}}}"#,json_string(&category))).await?;
   tx.commit().await?;
   Ok(reply(r,"term_removed",Value::Null,false))
  },
  Action::SignalClear(a)=>{
   let actor=php_trim(&a.actor_id).trim_end_matches('/');
   if actor.is_empty(){return Ok(reply(r,"missing_actor",Value::Null,false));}
   let audit_actor=byte_prefix(actor,2048)?;
   let tx=admin_tx(&mut db,r).await?;
   let rows=tx.execute("DELETE FROM ap_home_suppression WHERE actor_id = $1",&[&actor]).await?;
   audit(&tx,r,"signal_cleared",audit_actor,0,"",&format!(r#"{{"rows":{rows}}}"#)).await?;
   tx.commit().await?;
   // The rows are gone: from here nothing may answer 422, so a failed bump
   // only hands it back to PHP.
   let bumped=match epoch_bump_server(cfg).await{Ok(done)=>done,Err(e)=>{tracing::info!(error=%e,"downranking epoch bump left to PHP");false}};
   Ok(reply(r,"signal_cleared",Value::Null,!bumped))
  },
  Action::TermPreview(a)=>{
   let phrase=normalize_phrase(&a.preview_phrase)?;
   if phrase.is_empty()||phrase.chars().count()>120{
    return Ok(reply(r,"bad_preview_phrase",json!({"phrase":phrase,"count":0,"samples":[]}),false));
   }
   let audit_phrase=byte_prefix(&phrase,120)?.to_string();
   // ap_downrank_phrase_matches_text strips tags from the phrase, then squashes.
   let wanted=String::from_utf8(strip_tags(phrase.as_bytes(),None))?;
   let wanted=normalize_phrase(&wanted)?;
   let tx=admin_tx(&mut db,r).await?;
   let since=(chrono::Utc::now()-chrono::Duration::days(30)).format("%Y-%m-%dT%H:%M:%S+00:00").to_string();
   let rows=tx.query("SELECT actor_id::text, host::text, summary::text, content::text, created_at::text FROM events WHERE type = 'Create' AND action_taken IN ('log','local_observe') AND visibility IN ('public','unlisted') AND created_at >= $1 ORDER BY created_at DESC, id DESC LIMIT 2000",&[&since]).await?;
   let (mut count,mut samples)=(0i64,Vec::new());
   for row in &rows{
    let text=format!("{}\n{}",row.get::<_,Option<String>>(2).unwrap_or_default(),row.get::<_,Option<String>>(3).unwrap_or_default());
    let text=String::from_utf8(strip_tags(text.as_bytes(),None))?;
    if text.contains(['\0','\u{180E}']){bail!("PHP fallback: unproven text");}
    if wanted.is_empty()||!phrase_matches(&text,&wanted){continue;}
    count+=1;
    if samples.len()<8{samples.push(json!({"actor_id":row.get::<_,Option<String>>(0).unwrap_or_default(),"host":row.get::<_,Option<String>>(1).unwrap_or_default(),"created_at":row.get::<_,Option<String>>(4).unwrap_or_default()}));}
   }
   audit(&tx,r,"term_previewed","",0,&audit_phrase,&format!(r#"{{"matches":{count}}}"#)).await?;
   tx.commit().await?;
   Ok(reply(r,"previewed",json!({"phrase":phrase,"count":count,"samples":samples}),false))
  },
  Action::SavePrivacy(d)|Action::SaveConduct(d)=>{
   let key=if matches!(r.action,Action::SaveConduct(_)){"conduct"}else{"privacy"};
   let title=php_trim(&d.title);
   let title=if title.is_empty(){if key=="conduct"{"Code of Conduct"}else{"Privacy Policy"}}else{title};
   if title.chars().count()>120{return Ok(reply(r,"title_too_long",Value::Null,false));}
   if d.body.chars().count()>100000{return Ok(reply(r,"body_too_long",Value::Null,false));}
   if d.body.contains('\0')||title.contains('\0'){bail!("PHP fallback: NUL in policy text");}
   let tx=admin_tx(&mut db,r).await?;
   tx.execute("INSERT INTO ap_instance_docs (doc_key, title, body, updated_at) VALUES ($1, $2, $3, $4) ON CONFLICT(doc_key) DO UPDATE SET title = excluded.title, body = excluded.body, updated_at = excluded.updated_at",&[&key,&title,&d.body,&now_c()]).await?;
   tx.commit().await?;
   Ok(reply(r,"doc_saved",Value::Null,false))
  },
  Action::SaveRules(d)=>{
   let rules=match parse_rules(&d.rules_text)?{Ok(rules)=>rules,Err(outcome)=>return Ok(reply(r,outcome,Value::Null,false))};
   let tx=admin_tx(&mut db,r).await?;
   tx.execute("DELETE FROM ap_instance_rules",&[]).await?;
   let now=now_c();
   for (i,text) in rules.iter().enumerate(){
    tx.execute("INSERT INTO ap_instance_rules (sort_order, text, updated_at) VALUES ($1::bigint, $2, $3)",&[&(i as i64+1),text,&now]).await?;
   }
   tx.commit().await?;
   Ok(reply_count(r,"rules_saved",Value::Null,false,Some(rules.len())))
  },
  Action::ReportNote(a)=>{
   let note=report_note_text(&a.admin_note)?;
   if a.report_id<=0||note.is_empty(){return Ok(reply(r,"note_empty",Value::Null,false));}
   let tx=admin_tx(&mut db,r).await?;
   report_handle_check(&tx,r,&a.admin_handle).await?;
   report_id_check(&tx,a.report_id).await?;
   let outcome=if append_report_note(&tx,a.report_id,&note,&a.admin_handle).await?{"note_saved"}else{"note_report_not_found"};
   tx.commit().await?;
   Ok(reply(r,outcome,Value::Null,false))
  },
  Action::ReportDismiss(a)|Action::ReportIgnore(a)|Action::ReportReopen(a)=>{
   let state=match r.action{Action::ReportIgnore(_)=>"ignored",Action::ReportReopen(_)=>"open",_=>"dismissed"};
   let note=report_note_text(&a.admin_note)?;
   let tx=admin_tx(&mut db,r).await?;
   report_handle_check(&tx,r,&a.admin_handle).await?;
   report_id_check(&tx,a.report_id).await?;
   let rows=tx.execute("UPDATE ap_reports SET state = $1, updated_at = $2 WHERE id::bigint = $3",&[&state,&now_c(),&a.report_id]).await?;
   if rows<1{return Ok(reply(r,"report_not_found",Value::Null,false));}
   // PHP appends the note as a second step after the state change; the row
   // was just updated, so only a failed query (a 422 here) can stop it.
   if !note.is_empty()&&!append_report_note(&tx,a.report_id,&note,&a.admin_handle).await?{bail!("report vanished inside the transaction");}
   tx.commit().await?;
   Ok(reply(r,"state_set",Value::Null,false))
  },
  Action::UserBan(a)|Action::UserUnban(a)=>{
   let disable=matches!(r.action,Action::UserBan(_));
   if a.user_id<1{return Ok(reply(r,"invalid_user",Value::Null,false));}
   let tx=admin_tx(&mut db,r).await?;
   int4_guard(&tx,"ap_users",a.user_id).await?;
   // The target row is locked so its role cannot change between the check and the update.
   let Some(row)=tx.query_opt("SELECT actor_key::text, COALESCE(is_admin,0)<>0 FROM ap_users WHERE id::bigint = $1 LIMIT 1 FOR UPDATE",&[&a.user_id]).await? else {
    return Ok(reply(r,"user_not_found",Value::Null,false));
   };
   if row.get::<_,Option<String>>(0).as_deref()==Some("cmdr_nova")||row.get::<_,bool>(1){return Ok(reply(r,"protected_user",Value::Null,false));}
   let now=now_c();
   let when=disable.then(||now.clone());
   tx.execute("UPDATE ap_users SET disabled_at = $1, updated_at = $2 WHERE id::bigint = $3",&[&when,&now,&a.user_id]).await?;
   if disable{
    // Cut off API clients immediately as well as blocking future web logins.
    tx.execute("UPDATE oauth_tokens SET revoked_at = $1 WHERE user_id::bigint = $2 AND revoked_at IS NULL",&[&now,&a.user_id]).await?;
   }
   tx.commit().await?;
   Ok(reply(r,if disable{"banned"}else{"unbanned"},Value::Null,false))
  },
  Action::InviteCreate(a)=>{
   // ap-admin.php trims the note; ap_auth_invite_create keeps 200 characters, NULL when empty.
   if a.note.contains('\0'){bail!("PHP fallback: NUL in invite note");}
   let note=php_trim(&a.note);
   let note:Option<String>=(!note.is_empty()).then(||note.chars().take(200).collect());
   let code={use rand::RngCore;let mut b=[0u8;6];rand::rngs::OsRng.fill_bytes(&mut b);format!("{}-{}",hex::encode_upper(&b[..4]),hex::encode_upper(&b[4..]))};
   let tx=admin_tx(&mut db,r).await?;
   tx.execute("INSERT INTO ap_invite_codes (code, created_by, created_at, expires_at, note) VALUES ($1, $2::bigint, $3, NULL, $4)",&[&code,&r.owner_id,&now_c(),&note]).await?;
   tx.commit().await?;
   Ok(reply_full(r,"invite_created",Value::Null,false,None,Some(&code)))
  },
  Action::RegistrationOpen(_)|Action::RegistrationClose(_)=>{
   let accepting=matches!(r.action,Action::RegistrationOpen(_));
   // ap_registration_approvals_schema may create or alter the applications
   // table; anything short of its finished shape stays in PHP.
   let ready:bool=db.query_one("SELECT to_regclass('ap_registration_control') IS NOT NULL AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='ap_registration_applications' AND column_name='supporter_hash')",&[]).await?.get(0);
   if !ready{bail!("PHP fallback: registration schema not finished");}
   // PHP's order: the table lock, then the admin row, so the two never deadlock.
   let tx=db.transaction().await?;
   tx.batch_execute("LOCK TABLE ap_users, ap_registration_applications IN SHARE ROW EXCLUSIVE MODE").await?;
   let admin=tx.query_opt("SELECT actor_key::text FROM ap_users WHERE id::bigint = $1 AND is_admin = 1 AND disabled_at IS NULL FOR UPDATE",&[&r.owner_id]).await?;
   if admin.and_then(|row|row.get::<_,Option<String>>(0)).as_deref()!=Some(r.actor_key.as_str()){bail!("active admin required");}
   let rows=tx.execute("UPDATE ap_registration_control SET accepting=$1, updated_at=$2, updated_by=$3::bigint WHERE id=1",&[&i32::from(accepting),&now_c(),&r.owner_id]).await?;
   if rows!=1{return Ok(reply(r,"registration_not_ready",Value::Null,false));}
   tx.commit().await?;
   Ok(reply(r,if accepting{"registration_opened"}else{"registration_closed"},Value::Null,false))
  },
 }
}
/// trim($note) and mb_substr($note, 0, 5000).
fn report_note_text(raw:&str)->Result<String>{
 if raw.contains('\0'){bail!("PHP fallback: NUL in report note");}
 Ok(php_trim(raw).chars().take(5000).collect())
}
/// The note's signature is PHP's $vaakHandle: the account's trimmed username
/// (or actor key) as @name@mkultra.monster. The worker derives it from the
/// account row it holds and accepts only the same value.
async fn report_handle_check(tx:&tokio_postgres::Transaction<'_>,r:&Request,handle:&str)->Result<()>{
 let row=tx.query_one("SELECT username::text FROM ap_users WHERE id::bigint=$1",&[&r.owner_id]).await?;
 let name=row.get::<_,Option<String>>(0).unwrap_or_default();
 let name=php_trim(&name);
 let name=if name.is_empty(){php_trim(&r.actor_key)}else{name};
 let want=if name.is_empty(){String::new()}else{format!("@{name}@mkultra.monster")};
 if handle!=want{bail!("PHP fallback: admin handle differs from the account");}
 Ok(())
}
async fn report_id_check(tx:&tokio_postgres::Transaction<'_>,id:i64)->Result<()>{int4_guard(tx,"ap_reports",id).await}
/// PHP binds an id as text; past int4 an INTEGER id column raises, which PHP
/// reports its own way (or not at all), so those ids stay in PHP.
pub(crate) async fn int4_guard(tx:&tokio_postgres::Transaction<'_>,table:&'static str,id:i64)->Result<()>{
 if id>i64::from(i32::MAX){
  let kind:String=tx.query_one("SELECT format_type(atttypid, atttypmod) FROM pg_attribute WHERE attrelid = $1::text::regclass AND attname = 'id'",&[&table]).await?.get(0);
  if kind!="bigint"{bail!("PHP fallback: {table} id past int4");}
 }
 Ok(())
}
/// ap_report_append_admin_note after its checks: false when the report is gone.
async fn append_report_note(tx:&tokio_postgres::Transaction<'_>,id:i64,note:&str,handle:&str)->Result<bool>{
 let Some(row)=tx.query_opt("SELECT admin_notes::text FROM ap_reports WHERE id::bigint = $1",&[&id]).await? else {return Ok(false)};
 let existing=row.get::<_,Option<String>>(0).unwrap_or_default();
 let admin=php_trim(handle);
 let admin=if admin.is_empty(){"admin"}else{admin};
 let entry=format!("[{} UTC · {admin}]\n{note}",chrono::Utc::now().format("%Y-%m-%d %H:%M:%S"));
 let existing=php_trim(&existing);
 let mut combined=if existing.is_empty(){entry}else{format!("{existing}\n\n{entry}")};
 if combined.len()>50000{
  // substr($combined, -50000) counts bytes; a cut inside a character makes
  // PHP's update fail, which it reports as its own error.
  let at=combined.len()-50000;
  if !combined.is_char_boundary(at){bail!("PHP fallback: note history cut inside a character");}
  combined=combined[at..].to_string();
 }
 tx.execute("UPDATE ap_reports SET admin_notes = $1, updated_at = $2 WHERE id::bigint = $3",&[&combined,&now_c(),&id]).await?;
 Ok(true)
}
/// ap_instance_rules_save_from_text's parse: split on PCRE2's \R, trim each
/// line, drop a leading "1." or "1)" list marker, skip empty lines, at most 40
/// rules of at most 500 characters, checked in order as PHP does.
fn parse_rules(raw:&str)->Result<std::result::Result<Vec<String>,&'static str>>{
 if raw.contains('\0'){bail!("PHP fallback: NUL in rules");}
 static MARKER:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"^[0-9]+[.)][ \t\n\x0B\x0C\r]+").unwrap());
 let mut rules=Vec::new();
 let normalized=raw.replace("\r\n","\n");
 for line in normalized.split(['\n','\r','\u{0B}','\u{0C}','\u{85}','\u{2028}','\u{2029}']){
  let line=php_trim(line);
  let line=MARKER.replace(line,"");
  if line.is_empty(){continue;}
  if line.chars().count()>500{return Ok(Err("rule_too_long"));}
  rules.push(line.into_owned());
  if rules.len()>40{return Ok(Err("too_many_rules"));}
 }
 if rules.is_empty(){return Ok(Err("no_rules"));}
 Ok(Ok(rules))
}
/// The write transaction, holding the acting account's row: it must still be
/// the active admin PHP checked, with the actor key PHP sent.
async fn admin_tx<'a>(db:&'a mut tokio_postgres::Client,r:&Request)->Result<tokio_postgres::Transaction<'a>>{
 let tx=db.transaction().await?;
 let row=tx.query_opt("SELECT actor_key::text FROM ap_users WHERE id::bigint=$1 AND COALESCE(is_admin,0)<>0 AND disabled_at IS NULL FOR SHARE",&[&r.owner_id]).await?;
 if row.and_then(|row|row.get::<_,Option<String>>(0)).as_deref()!=Some(r.actor_key.as_str()){bail!("active admin required");}
 Ok(tx)
}
/// admin_home_downrank_audit. PHP writes it as a separate statement and only
/// logs a failure; here it shares the transaction, so a failure rolls the
/// action back and PHP runs it instead.
async fn audit(tx:&tokio_postgres::Transaction<'_>,r:&Request,action:&str,actor:&str,term_id:i64,phrase:&str,details:&str)->Result<()>{
 tx.execute("INSERT INTO ap_home_downrank_audit (admin_user_id, admin_actor, action, actor_id, term_id, phrase, details_json, created_at) VALUES ($1::bigint, $2, $3, $4, $5::bigint, $6, $7, $8)",
  &[&r.owner_id,&r.actor_key,&action,&actor,&term_id.max(0),&phrase,&details,&now_c()]).await?;
 Ok(())
}
/// ap_trends_policy_epoch_bump(null). Ok(false) when Redis is off, as PHP's
/// own bump then does nothing either.
async fn epoch_bump_server(cfg:&crate::config::Config)->Result<bool>{
 if !crate::settings_profile_save::redis_enabled(){return Ok(false);}
 let mut cache=tokio::time::timeout(Duration::from_millis(250),crate::redis_util::connect(&cfg.redis_url)).await??;
 let key="vaak:trends:policy-epoch:v1:server";
 let _:i64=redis::cmd("INCR").arg(key).query_async(&mut cache).await?;
 let _:i64=redis::cmd("EXPIRE").arg(key).arg(86400*30).query_async(&mut cache).await?;
 Ok(true)
}

#[cfg(test)]
mod tests{
 use super::*;
 fn env(action:&str,fields:Value)->Value{json!({"protocol_version":1,"owner_id":1,"actor_key":"cmdr_nova","action":action,"fields":fields})}
 #[test]fn envelope_is_closed(){
  for (a,f) in [("downrank_term_add",json!({"phrase":"x","category":"custom"})),("downrank_term_delete",json!({"term_id":3})),("downrank_signal_clear",json!({"actor_id":"https://a.example/u"})),("downrank_term_preview",json!({"preview_phrase":"x"}))]{
   assert!(decode(&serde_json::to_vec(&env(a,f.clone())).unwrap()).is_ok(),"{a}");
   let mut extra=f.clone();extra["is_admin"]=json!(true);assert!(decode(&serde_json::to_vec(&env(a,extra)).unwrap()).is_err(),"{a} extra field");
   let mut top=env(a,f.clone());top["csrf"]=json!("x");assert!(decode(&serde_json::to_vec(&top).unwrap()).is_err(),"{a} extra key");
  }
  assert!(decode(&serde_json::to_vec(&env("relay_remove",json!({"relay_id":2}))).unwrap()).is_err());
  assert!(decode(&serde_json::to_vec(&env("user_ban",json!({"user_id":2}))).unwrap()).is_ok());
  assert!(decode(&serde_json::to_vec(&env("downrank_term_delete",json!({"term_id":"3"}))).unwrap()).is_err());
  let mut zero=env("downrank_term_delete",json!({"term_id":3}));zero["owner_id"]=json!(0);assert!(decode(&serde_json::to_vec(&zero).unwrap()).is_err());
 }
 #[test]fn rules_parse_like_php(){
  assert_eq!(parse_rules("1. Be kind\r\n\r\n2) No spam \u{2028}  3.no space\n12.  \t Twelve\n1.").unwrap().unwrap(),vec!["Be kind","No spam","3.no space","Twelve","1."]);
  assert_eq!(parse_rules(" \n \r ").unwrap().unwrap_err(),"no_rules");
  assert_eq!(parse_rules(&"r\n".repeat(41)).unwrap().unwrap_err(),"too_many_rules");
  assert_eq!(parse_rules(&format!("ok\n{}\n","é".repeat(501))).unwrap().unwrap_err(),"rule_too_long");
  assert_eq!(parse_rules(&"é".repeat(500)).unwrap().unwrap().len(),1);
  assert!(parse_rules("a\0b").is_err());
 }
 #[test]fn phrase_normalizing_matches_php(){
  assert_eq!(normalize_phrase("  a \t\n b  ").unwrap(),"a b");
  assert_eq!(normalize_phrase("a\u{00A0}\u{3000}b").unwrap(),"a b");
  assert!(normalize_phrase("a\u{180E}b").is_err());
  assert_eq!(byte_prefix("abc",2).unwrap(),"ab");
  assert!(byte_prefix("aé",2).is_err());
 }
}
