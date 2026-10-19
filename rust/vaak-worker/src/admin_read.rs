//! Default-off native Admin read bodies. PHP owns sessions, shell and all mutations.
use anyhow::{bail, Result};
use axum::{body::Bytes,http::{HeaderMap,StatusCode},response::{IntoResponse,Response}};
use serde::{Deserialize,Serialize};
use serde_json::{json,Value};
use crate::library::actions::{esc,query};
#[derive(Clone,Debug,Deserialize,Serialize,PartialEq)]
#[serde(rename_all="snake_case")]
enum View {Invites,Approvals,Users,Relays,Policies,Blocks,Moderation,SettingsLists,SettingsFollowRequests,SettingsFeatured,SettingsMutes}
impl View{fn settings(&self)->bool{matches!(self,View::SettingsLists|View::SettingsFollowRequests|View::SettingsFeatured|View::SettingsMutes)}}
#[derive(Clone,Debug,Deserialize,Serialize,PartialEq)]
#[serde(rename_all="snake_case")]
enum Mode {Full,Shell}
#[derive(Clone,Debug,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
struct Request {protocol_version:u8,owner_id:i64,csrf:String,view:View,mode:Mode,offset:i64,approvals_enabled:bool,now:i64,php_timezone:String,#[serde(default)]search:Option<String>,#[serde(default)]viewer_admin:Option<bool>,#[serde(default)]filter:Option<String>}
#[derive(Debug,Serialize)]
struct Binding<'a>{owner_id:i64,view:&'a View,mode:&'a Mode,offset:i64,approvals_enabled:bool,#[serde(skip_serializing_if="Option::is_none")]search:Option<&'a str>,#[serde(skip_serializing_if="Option::is_none")]viewer_admin:Option<bool>,#[serde(skip_serializing_if="Option::is_none")]filter:Option<&'a str>}
#[derive(Debug,Clone,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
struct Invite {id:i64,code:String,created_at:String,used_at:Option<String>,note:Option<String>}
#[derive(Debug,Clone,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
struct Application {id:i64,username:String,email:Option<String>,status:String,created_at:String,decided_at:Option<String>}
fn secret()->Option<String>{if std::env::var("VAAK_ADMIN_READ_NATIVE").ok().as_deref()!=Some("1"){return None;}std::env::var("VAAK_ADMIN_READ_SECRET").ok().filter(|s|s.len()>=32)}
pub(crate) fn enabled()->bool{secret().is_some()}
fn decode(bytes:&[u8])->Result<Request>{
 if bytes.is_empty()||bytes.len()>16384{bail!("admin envelope size");}
 let r:Request=serde_json::from_slice(bytes)?;
 if r.protocol_version!=1||r.owner_id<1||r.csrf.is_empty()||r.csrf.len()>4096||r.offset<0||r.offset>i64::MAX-100||r.now<0{bail!("admin envelope");}
 if r.php_timezone!="UTC" { bail!("unproven PHP timezone"); }
 if (matches!(r.view,View::Invites|View::Relays|View::Policies|View::Blocks|View::Moderation)||r.view.settings())&&r.offset!=0{bail!("invite selector");}
 if r.view==View::Users{if r.offset<1||r.search.as_ref().is_none_or(|s|s.len()>4096||crate::library::card::php_trim(s)!=s){bail!("users selector");}}else if r.search.is_some(){bail!("unexpected users selector");}
 if r.view.settings()!=r.viewer_admin.is_some(){bail!("viewer role selector");}
 if (r.view==View::Moderation)!=r.filter.is_some()||r.filter.as_deref().is_some_and(|f|!moderation::FILTERS.contains(&f)){bail!("moderation filter selector");}
 Ok(r)
}
/// Settings views admit any active account and must agree with the role PHP painted for;
/// every other view requires a current active administrator.
async fn role(db:&tokio_postgres::Client,r:&Request)->Result<()>{
 if !r.view.settings(){return admin(db,r.owner_id).await;}
 let Some(row)=db.query_opt("SELECT COALESCE(is_admin,0)<>0 FROM ap_users WHERE id::bigint=$1 AND disabled_at IS NULL",&[&r.owner_id]).await? else {bail!("current active account required")};
 if Some(row.get::<_,bool>(0))!=r.viewer_admin{bail!("viewer role changed");}Ok(())
}
async fn admin(db:&tokio_postgres::Client,owner:i64)->Result<()> {
 let allowed:bool=db.query_one("SELECT EXISTS(SELECT 1 FROM ap_users WHERE id::bigint=$1 AND is_admin=1 AND disabled_at IS NULL)",&[&owner]).await?.get(0);
 if !allowed{bail!("current active administrator required");}Ok(())
}
fn empty(s:&str)->bool{s.is_empty()||s=="0"}
fn relative(s:&str,now:i64)->Result<String>{
 if s.is_empty(){return Ok(String::new());}
 let timestamp=chrono::DateTime::parse_from_rfc3339(s).map(|v|v.timestamp()).or_else(|_|chrono::NaiveDateTime::parse_from_str(s,"%Y-%m-%d %H:%M:%S").map(|v|v.and_utc().timestamp()))
  // Postgres timestamptz text, e.g. "2026-10-19 12:00:00.123456+00"; strtotime drops the fraction.
  .or_else(|_|chrono::DateTime::parse_from_str(s,"%Y-%m-%d %H:%M:%S%.f%#z").map(|v|v.timestamp()));
 let t=match timestamp{Ok(t)=>t,Err(_)=>bail!("unproven PHP date format")};
 let d=now.checked_sub(t).ok_or_else(||anyhow::anyhow!("date range"))?;
 Ok(if d<60{format!("{d}s")}else if d<3600{format!("{}m",d/60)}else if d<86400{format!("{}h",d/3600)}else{format!("{}d",d/86400)})
}
fn urlencode(s:&str)->String{query(s).replace("%20","+").replace('~',"%7E")}
fn invites(rows:&[Invite],now:i64)->Result<String>{
 let mut h=format!(r#"<div class="meta" style="margin-bottom:1rem">Generate invite codes for new VAAK accounts. Registration: <a href="/vaak/?mode=register">/vaak/?mode=register</a>. Public HTML profiles + federation keys for invitees ship in a later slice.</div><form class="composer" method="post" action="?view=invites" style="margin-bottom:1.25rem"><input type="hidden" name="action" value="invite_create"><input name="note" type="text" maxlength="200" placeholder="Optional note (who it’s for)"><div class="composer-actions"><span class="meta">{} codes</span><button class="btn btn-primary" type="submit">Generate invite</button></div></form>"#,rows.len());
 if rows.is_empty(){h.push_str(r#"<div class="empty">No invites yet.</div>"#);}
 for row in rows{
  h.push_str(&format!(r#"<article class="tweet"><div class="tweet-hd"><div><span class="who mono">{}</span><span class="meta"> · {}</span>"#,esc(&row.code),esc(&relative(&row.created_at,now)?)));
  let used=row.used_at.as_deref().unwrap_or("");
  if !empty(used){h.push_str(&format!(r#"<span class="meta"> · used {}</span>"#,esc(&relative(used,now)?)));}else{h.push_str(r#"<span class="meta" style="color:var(--primary)"> · unused</span>"#);}
  h.push_str("</div></div>");let note=row.note.as_deref().unwrap_or("");if !empty(note){h.push_str(&format!(r#"<div class="body meta">{}</div>"#,esc(note)));}
  if empty(used){h.push_str(&format!(r#"<div class="tweet-actions"><a class="btn btn-ghost" href="/vaak/?mode=register&amp;invite={}" style="padding:.25rem .7rem;font-size:.8rem">Registration link</a></div>"#,urlencode(&row.code)));}
  h.push_str("</article>");
 }Ok(h)
}
fn applications(rows:&[Application],r:&Request)->String{
 let mut h=format!(r#"<p class="meta">{}</p>"#,if r.approvals_enabled{"Applications require approval before an account is created."}else{"Registration applications are closed. Invite registration continues as usual."});
 if rows.is_empty(){h.push_str(r#"<div class="empty">No applications.</div>"#);}
 for row in rows{
  h.push_str(&format!(r#"<article class="card"><strong>{}</strong><p>{}</p><p class="meta">{} · {}</p>"#,esc(&row.username),esc(row.email.as_deref().unwrap_or("")),esc(&row.created_at),esc(&row.status)));
  if row.status=="pending"&&r.approvals_enabled{h.push_str(&format!(r#"<form method="post" action="?view=approvals"><input type="hidden" name="csrf" value="{}"><input type="hidden" name="application_id" value="{}"><button class="btn" name="action" value="registration_approve">Approve</button><button class="btn" name="action" value="registration_reject">Reject</button></form>"#,esc(&r.csrf),row.id));}
  h.push_str("</article>");
 }
 h.push_str(r#"<nav aria-label="Application pages">"#);
 if r.offset>0{h.push_str(&format!(r#"<a class="btn" href="?view=approvals&amp;approval_offset={}">Previous</a>"#,(r.offset-100).max(0)));}
 if rows.len()==100{h.push_str(&format!(r#"<a class="btn" href="?view=approvals&amp;approval_offset={}">Next</a>"#,r.offset+100));}h.push_str("</nav>");h
}
async fn paint(cfg:&crate::config::Config,r:&Request)->Result<Value>{
 if r.view==View::Users&&std::env::var("VAAK_ADMIN_USERS_NATIVE").ok().as_deref()!=Some("1"){bail!("native Users disabled");}
 if r.view==View::Relays&&std::env::var("VAAK_ADMIN_RELAYS_NATIVE").ok().as_deref()!=Some("1"){bail!("native Relays disabled");}
 if r.view==View::Policies&&std::env::var("VAAK_ADMIN_POLICIES_NATIVE").ok().as_deref()!=Some("1"){bail!("native Policies disabled");}
 if r.view==View::Blocks&&std::env::var("VAAK_ADMIN_BLOCKS_NATIVE").ok().as_deref()!=Some("1"){bail!("native Blocks disabled");}
 if r.view==View::Moderation&&std::env::var("VAAK_ADMIN_MODERATION_NATIVE").ok().as_deref()!=Some("1"){bail!("native Moderation disabled");}
 if r.view==View::SettingsLists&&std::env::var("VAAK_SETTINGS_LISTS_NATIVE").ok().as_deref()!=Some("1"){bail!("native Settings lists disabled");}
 if matches!(r.view,View::SettingsFollowRequests|View::SettingsFeatured|View::SettingsMutes)&&std::env::var("VAAK_SETTINGS_ACCOUNTS_NATIVE").ok().as_deref()!=Some("1"){bail!("native Settings accounts disabled");}
 let db=crate::db::connect(&cfg.database_url).await?;
 db.batch_execute("BEGIN READ ONLY ISOLATION LEVEL READ COMMITTED").await?;
 role(&db,r).await?;
 let configured=std::env::var("VAAK_REGISTRATION_APPROVALS").ok().as_deref()==Some("1");
 if r.approvals_enabled!=configured{bail!("approval mode mismatch");}
 enum Collected { Invites(Vec<Invite>), Applications(Vec<Application>), Users(users::Collected), Relays(Vec<relays::Relay>), Policies(policies::Collected), Blocks(Vec<blocks::Row>), Moderation(moderation::Collected), SettingsLists(settings_lists::Collected), FollowRequests(Vec<settings_accounts::FollowRequest>), Featured(Vec<settings_accounts::Featured>,settings_accounts::Owner), Mutes(Vec<settings_accounts::Mute>) }
 let collected=match r.view{
  View::Invites=>{
   let mut rows=Vec::new();for row in db.query("SELECT jsonb_build_object('id',id::bigint,'code',code,'created_at',created_at,'used_at',used_at,'note',note) FROM ap_invite_codes ORDER BY created_at DESC,id DESC LIMIT 100",&[]).await?{rows.push(serde_json::from_value::<Invite>(row.get(0))?);}
   Collected::Invites(rows)
  },
  View::Users=>Collected::Users(users::collect(&db,r).await?),
  View::Relays=>Collected::Relays(relays::collect(&db).await?),
  View::Policies=>Collected::Policies(policies::collect(&db).await?),
  View::Blocks=>Collected::Blocks(blocks::collect(&db,cfg).await?),
  View::Moderation=>Collected::Moderation(moderation::collect(&db,cfg,r.owner_id,r.filter.as_deref().unwrap_or("open"),r.now).await?),
  View::SettingsLists=>Collected::SettingsLists(settings_lists::collect(&db,r.owner_id).await?),
  View::SettingsFollowRequests=>{let owner=settings_accounts::owner(&db,r.owner_id).await?;Collected::FollowRequests(settings_accounts::follow_requests(&db,&owner).await?)},
  View::SettingsFeatured=>{let owner=settings_accounts::owner(&db,r.owner_id).await?;Collected::Featured(settings_accounts::featured(&db,cfg,r.owner_id).await?,owner)},
  View::SettingsMutes=>Collected::Mutes(settings_accounts::mutes(&db,cfg,r.owner_id,r.now).await?),
  View::Approvals=>{
   let mut rows=Vec::new();for row in db.query("SELECT jsonb_build_object('id',id::bigint,'username',username,'email',email,'status',status,'created_at',created_at,'decided_at',decided_at) FROM ap_registration_applications ORDER BY id DESC LIMIT 100 OFFSET $1",&[&r.offset]).await?{rows.push(serde_json::from_value::<Application>(row.get(0))?);}
   Collected::Applications(rows)
  }
 };
 role(&db,r).await?;
 // ap_remote_actor_label queues the same warm for an uncached or placeholder actor.
 // Queued together so ten misses cost one 80 ms Redis budget, not ten.
 let warm:Vec<String>=match &collected{
  Collected::Blocks(rows)=>rows.iter().filter_map(|row|row.warm.clone()).collect(),
  Collected::Mutes(rows)=>rows.iter().filter_map(|row|row.warm.clone()).collect(),
  Collected::Moderation(c)=>c.warm.clone(),
  _=>Vec::new(),
 };
 let mut warms=tokio::task::JoinSet::new();
 for actor in warm{let cfg=cfg.clone();warms.spawn(async move{crate::profile_warm::actor_warm(&cfg,&actor,&crate::profile_warm::ProcessWarmTransport::configured()).await;});}
 while warms.join_next().await.is_some(){}
 db.batch_execute("COMMIT").await?;role(&db,r).await?;
 // The final painter is synchronous: no I/O follows the final fresh role admission.
 let (html,count,rows)=match collected{
  Collected::Invites(rows)=>(invites(&rows,r.now)?,rows.len(),serde_json::to_value(rows)?),
  Collected::Applications(rows)=>(applications(&rows,r),rows.len(),serde_json::to_value(rows)?),
  Collected::Users(rows)=>users::paint(rows,r)?,
  Collected::Relays(rows)=>relays::paint(rows,r)?,
  Collected::Policies(c)=>policies::paint(c)?,
  Collected::Blocks(rows)=>blocks::paint(rows,r)?,
  Collected::Moderation(c)=>moderation::paint(c,r)?,
  Collected::SettingsLists(c)=>settings_lists::paint(c,r)?,
  Collected::FollowRequests(rows)=>settings_accounts::paint_follow_requests(rows,r)?,
  Collected::Featured(cards,owner)=>settings_accounts::paint_featured(cards,&owner)?,
  Collected::Mutes(rows)=>settings_accounts::paint_mutes(rows,r)?,
 };
 if html.len()>1024*1024 || serde_json::to_vec(&rows)?.len()>512*1024 { bail!("admin response bound"); }

 Ok(json!({"protocol_version":1,"ok":true,"request_binding":Binding{owner_id:r.owner_id,view:&r.view,mode:&r.mode,offset:r.offset,approvals_enabled:r.approvals_enabled,search:r.search.as_deref(),viewer_admin:r.viewer_admin,filter:r.filter.as_deref()},"source_count":count,"rows":rows,"html":html}))
}
pub(crate) async fn render(cfg:&crate::config::Config,headers:HeaderMap,bytes:Bytes)->Response{
 let Some(secret)=secret()else{return StatusCode::NOT_FOUND.into_response();};
 let supplied=headers.get("x-vaak-admin-token").and_then(|h|h.to_str().ok()).unwrap_or("");
 if supplied.len()!=secret.len()||supplied.bytes().zip(secret.bytes()).fold(0u8,|d,(a,b)|d|(a^b))!=0{return StatusCode::UNAUTHORIZED.into_response();}
 let request=match decode(&bytes){Ok(r)=>r,Err(_)=>return StatusCode::BAD_REQUEST.into_response()};
 match paint(cfg,&request).await{Ok(reply)=>(StatusCode::OK,axum::Json(reply)).into_response(),Err(_)=>(StatusCode::UNPROCESSABLE_ENTITY,axum::Json(json!({"fallback":true}))).into_response()}
}
#[path="admin_users.rs"]
mod users;
#[path="admin_relays.rs"]
mod relays;
#[path="admin_policies.rs"]
mod policies;
#[path="admin_blocks.rs"]
mod blocks;
#[path="admin_moderation.rs"]
mod moderation;
#[path="admin_settings_lists.rs"]
mod settings_lists;
#[path="admin_settings_accounts.rs"]
mod settings_accounts;
#[cfg(test)]mod tests{
 use super::*;
 #[test]fn strict_admin_read_envelope(){let good=json!({"protocol_version":1,"owner_id":1,"csrf":"session","view":"invites","mode":"full","offset":0,"approvals_enabled":false,"now":100,"php_timezone":"UTC"});assert!(decode(&serde_json::to_vec(&good).unwrap()).is_ok());for(k,v)in[("owner_id",json!(0)),("is_admin",json!(true)),("view",json!("users")),("offset",json!(1)),("csrf",json!("")),("rows",json!([]))]{let mut bad=good.clone();bad[k]=v;assert!(decode(&serde_json::to_vec(&bad).unwrap()).is_err());}}
 #[test]fn relays_envelope_has_no_selectors(){let good=json!({"protocol_version":1,"owner_id":1,"csrf":"session","view":"relays","mode":"shell","offset":0,"approvals_enabled":false,"now":100,"php_timezone":"UTC"});assert!(decode(&serde_json::to_vec(&good).unwrap()).is_ok());for(k,v)in[("offset",json!(1)),("search",json!(""))]{let mut bad=good.clone();bad[k]=v;assert!(decode(&serde_json::to_vec(&bad).unwrap()).is_err());}}
 #[test]fn policies_envelope_has_no_selectors(){let good=json!({"protocol_version":1,"owner_id":1,"csrf":"session","view":"policies","mode":"full","offset":0,"approvals_enabled":false,"now":100,"php_timezone":"UTC"});assert!(decode(&serde_json::to_vec(&good).unwrap()).is_ok());for(k,v)in[("offset",json!(1)),("search",json!(""))]{let mut bad=good.clone();bad[k]=v;assert!(decode(&serde_json::to_vec(&bad).unwrap()).is_err());}}
 #[test]fn settings_lists_envelope_needs_viewer_role(){let good=json!({"protocol_version":1,"owner_id":2,"csrf":"session","view":"settings_lists","mode":"full","offset":0,"approvals_enabled":false,"now":100,"php_timezone":"UTC","viewer_admin":false});assert!(decode(&serde_json::to_vec(&good).unwrap()).is_ok());for(k,v)in[("offset",json!(1)),("search",json!("")),("viewer_admin",Value::Null)]{let mut bad=good.clone();bad[k]=v;assert!(decode(&serde_json::to_vec(&bad).unwrap()).is_err());}let mut admin_view=good.clone();admin_view["view"]=json!("blocks");assert!(decode(&serde_json::to_vec(&admin_view).unwrap()).is_err());}
 #[test]fn personal_row_models_are_closed(){assert!(serde_json::from_value::<settings_lists::UserBlock>(json!({"id":1,"scope":"actor","value":"x","kind":"block","reason":null,"created_at":"a","owner_user_id":2})).is_err());assert!(serde_json::from_value::<settings_lists::MutedWord>(json!({"id":1,"phrase":"x","created_at":"a","note":null,"owner_user_id":2})).is_err());}
 #[test]fn relative_accepts_postgres_timestamptz_text(){for(s,want)in[("2026-10-19 12:00:00.123456+00","10s"),("2026-10-19 12:00:00+00","10s"),("2026-10-19 14:00:00.5+02","10s"),("2026-10-19 12:00:00.999999+00:00","10s")]{assert_eq!(relative(s,chrono::DateTime::parse_from_rfc3339("2026-10-19T12:00:10Z").unwrap().timestamp()).unwrap(),want,"{s}");}assert!(relative("yesterday",0).is_err());}
 #[test]fn settings_account_views_need_viewer_role(){for view in["settings_follow_requests","settings_featured","settings_mutes"]{let good=json!({"protocol_version":1,"owner_id":2,"csrf":"session","view":view,"mode":"full","offset":0,"approvals_enabled":false,"now":100,"php_timezone":"UTC","viewer_admin":false});assert!(decode(&serde_json::to_vec(&good).unwrap()).is_ok());for(k,v)in[("offset",json!(1)),("viewer_admin",Value::Null),("search",json!(""))]{let mut bad=good.clone();bad[k]=v;assert!(decode(&serde_json::to_vec(&bad).unwrap()).is_err(),"{view} {k}");}}}
 #[test]fn moderation_envelope_needs_a_known_filter(){let good=json!({"protocol_version":1,"owner_id":1,"csrf":"session","view":"moderation","mode":"full","offset":0,"approvals_enabled":false,"now":100,"php_timezone":"UTC","filter":"open"});assert!(decode(&serde_json::to_vec(&good).unwrap()).is_ok());for(k,v)in[("offset",json!(1)),("filter",Value::Null),("filter",json!("bogus")),("viewer_admin",json!(true)),("search",json!(""))]{let mut bad=good.clone();bad[k]=v;assert!(decode(&serde_json::to_vec(&bad).unwrap()).is_err(),"{k}");}let mut other=good.clone();other["view"]=json!("blocks");assert!(decode(&serde_json::to_vec(&other).unwrap()).is_err());}
 #[test]fn report_row_model_is_closed(){assert!(serde_json::from_value::<moderation::Report>(json!({"id":1,"direction":"in","state":"open","reporter_actor_id":"https://r/u","target_actor_id":null,"status_uris_json":null,"comment":null,"about_us":"0","admin_notes":"","created_at":"a","activity_id":"x"})).is_err());}
 #[test]fn block_row_model_is_closed(){assert!(serde_json::from_value::<blocks::Block>(json!({"id":1,"scope":"domain","value":"x","kind":"block","reason":null,"created_at":"a","created_by":"admin"})).is_err());}
 #[test]fn relay_row_model_is_closed(){assert!(serde_json::from_value::<relays::Relay>(json!({"id":1,"inbox_url":"https://r/inbox","state":"idle","follow_activity_id":null,"created_at":"a","updated_at":"b","secret":"x"})).is_err());}
 #[test]fn no_application_hash_model(){assert!(serde_json::from_value::<Application>(json!({"id":1,"username":"x","email":null,"status":"pending","created_at":"now","decided_at":null,"password_hash":"forbidden"})).is_err());}
}
