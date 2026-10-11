//! Default-off native Admin read bodies. PHP owns sessions, shell and all mutations.
use anyhow::{bail, Result};
use axum::{body::Bytes,http::{HeaderMap,StatusCode},response::{IntoResponse,Response}};
use serde::{Deserialize,Serialize};
use serde_json::{json,Value};
use crate::library::actions::{esc,query};
#[derive(Clone,Debug,Deserialize,Serialize,PartialEq)]
#[serde(rename_all="snake_case")]
enum View {Invites,Approvals,Users}
#[derive(Clone,Debug,Deserialize,Serialize,PartialEq)]
#[serde(rename_all="snake_case")]
enum Mode {Full,Shell}
#[derive(Clone,Debug,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
struct Request {protocol_version:u8,owner_id:i64,csrf:String,view:View,mode:Mode,offset:i64,approvals_enabled:bool,now:i64,php_timezone:String,#[serde(default)]search:Option<String>}
#[derive(Debug,Serialize)]
struct Binding<'a>{owner_id:i64,view:&'a View,mode:&'a Mode,offset:i64,approvals_enabled:bool,#[serde(skip_serializing_if="Option::is_none")]search:Option<&'a str>}
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
 if r.view==View::Invites&&r.offset!=0{bail!("invite selector");}
 if r.view==View::Users{if r.offset<1||r.search.as_ref().is_none_or(|s|s.len()>4096||crate::library::card::php_trim(s)!=s){bail!("users selector");}}else if r.search.is_some(){bail!("unexpected users selector");}
 Ok(r)
}
async fn admin(db:&tokio_postgres::Client,owner:i64)->Result<()> {
 let allowed:bool=db.query_one("SELECT EXISTS(SELECT 1 FROM ap_users WHERE id::bigint=$1 AND is_admin=1 AND disabled_at IS NULL)",&[&owner]).await?.get(0);
 if !allowed{bail!("current active administrator required");}Ok(())
}
fn empty(s:&str)->bool{s.is_empty()||s=="0"}
fn relative(s:&str,now:i64)->Result<String>{
 if s.is_empty(){return Ok(String::new());}
 let timestamp=chrono::DateTime::parse_from_rfc3339(s).map(|v|v.timestamp()).or_else(|_|chrono::NaiveDateTime::parse_from_str(s,"%Y-%m-%d %H:%M:%S").map(|v|v.and_utc().timestamp()));
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
 let db=crate::db::connect(&cfg.database_url).await?;
 db.batch_execute("BEGIN READ ONLY ISOLATION LEVEL READ COMMITTED").await?;
 admin(&db,r.owner_id).await?;
 let configured=std::env::var("VAAK_REGISTRATION_APPROVALS").ok().as_deref()==Some("1");
 if r.approvals_enabled!=configured{bail!("approval mode mismatch");}
 enum Collected { Invites(Vec<Invite>), Applications(Vec<Application>), Users(users::Collected) }
 let collected=match r.view{
  View::Invites=>{
   let mut rows=Vec::new();for row in db.query("SELECT jsonb_build_object('id',id::bigint,'code',code,'created_at',created_at,'used_at',used_at,'note',note) FROM ap_invite_codes ORDER BY created_at DESC,id DESC LIMIT 100",&[]).await?{rows.push(serde_json::from_value::<Invite>(row.get(0))?);}
   Collected::Invites(rows)
  },
  View::Users=>Collected::Users(users::collect(&db,r).await?),
  View::Approvals=>{
   let mut rows=Vec::new();for row in db.query("SELECT jsonb_build_object('id',id::bigint,'username',username,'email',email,'status',status,'created_at',created_at,'decided_at',decided_at) FROM ap_registration_applications ORDER BY id DESC LIMIT 100 OFFSET $1",&[&r.offset]).await?{rows.push(serde_json::from_value::<Application>(row.get(0))?);}
   Collected::Applications(rows)
  }
 };
 admin(&db,r.owner_id).await?;
 db.batch_execute("COMMIT").await?;admin(&db,r.owner_id).await?;
 // The final painter is synchronous: no I/O follows the final fresh role admission.
 let (html,count,rows)=match collected{
  Collected::Invites(rows)=>(invites(&rows,r.now)?,rows.len(),serde_json::to_value(rows)?),
  Collected::Applications(rows)=>(applications(&rows,r),rows.len(),serde_json::to_value(rows)?),
  Collected::Users(rows)=>users::paint(rows,r)?,
 };
 if html.len()>1024*1024 || serde_json::to_vec(&rows)?.len()>512*1024 { bail!("admin response bound"); }

 Ok(json!({"protocol_version":1,"ok":true,"request_binding":Binding{owner_id:r.owner_id,view:&r.view,mode:&r.mode,offset:r.offset,approvals_enabled:r.approvals_enabled,search:r.search.as_deref()},"source_count":count,"rows":rows,"html":html}))
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
#[cfg(test)]mod tests{
 use super::*;
 #[test]fn strict_admin_read_envelope(){let good=json!({"protocol_version":1,"owner_id":1,"csrf":"session","view":"invites","mode":"full","offset":0,"approvals_enabled":false,"now":100,"php_timezone":"UTC"});assert!(decode(&serde_json::to_vec(&good).unwrap()).is_ok());for(k,v)in[("owner_id",json!(0)),("is_admin",json!(true)),("view",json!("users")),("offset",json!(1)),("csrf",json!("")),("rows",json!([]))]{let mut bad=good.clone();bad[k]=v;assert!(decode(&serde_json::to_vec(&bad).unwrap()).is_err());}}
 #[test]fn no_application_hash_model(){assert!(serde_json::from_value::<Application>(json!({"id":1,"username":"x","email":null,"status":"pending","created_at":"now","decided_at":null,"password_hash":"forbidden"})).is_err());}
}
