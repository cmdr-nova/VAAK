//! Moderation report list copied from ap_reports_list, ap_report_priority_score,
//! actor_handle, admin_global_actor_control, admin_is_local_admin_actor,
//! admin_status_href and the ap-admin Moderation branch. PHP retains the account
//! moderation card, filter tabs, the report composer, the nav badge, CSRF
//! injection and every report and block action. Bluesky-shaped or non-https
//! reporter/target refs, AT URIs and report rows PHP labels from a local actor
//! row render the PHP list instead. Uncached actors get PHP's URL-derived handle
//! and the same async actor warm PHP queues for them. Spaces stand where the PHP
//! template has whitespace.
use super::*;
use crate::library::card::php_trim;
use std::collections::HashMap;
#[derive(Debug,Clone,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct Report {id:i64,direction:String,state:String,reporter_actor_id:String,target_actor_id:Option<String>,status_uris_json:Option<String>,comment:Option<String>,about_us:Option<String>,admin_notes:Option<String>,created_at:String}
struct Actor {uri:String,control:Option<String>,admin:bool}
pub(super) struct Row {report:Report,priority:i64,reporter:Option<Actor>,target:Option<Actor>,reporter_handle:String,target_handle:String,uris:Vec<String>}
pub(super) struct Collected {pub(super) rows:Vec<Row>,pub(super) warm:Vec<String>}
pub(super) const LIMIT:i64=80;
pub(super) const FILTERS:[&str;5]=["open","about_us","outbound","closed","all"];
fn host(actor:&str)->String{actor.strip_prefix("https://").unwrap_or("").split(['/', '?','#']).next().unwrap_or("").rsplit('@').next().unwrap_or("").split(':').next().unwrap_or("").to_ascii_lowercase()}
fn local_host(actor:&str)->bool{matches!(host(actor).as_str(),"mkultra.monster"|"www.mkultra.monster"|"vaak.monster"|"www.vaak.monster")}
/// ap_actor_moderation_aliases needs Bluesky cache lookups for these shapes.
fn bluesky_shaped(actor:&str)->bool{
 static DID:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"(?i)/(?:ap/)?did:plc:[a-z0-9]+").unwrap());
 static PROFILE:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"(?i)^https://bsky\.app/profile/[^/?#]+$").unwrap());
 actor.starts_with("did:")||PROFILE.is_match(actor)||DID.is_match(actor)
}
/// strtotime for the three proven created_at shapes; anything else is PHP-only.
fn timestamp(s:&str)->Result<i64>{
 chrono::DateTime::parse_from_rfc3339(s).map(|v|v.timestamp()).or_else(|_|chrono::NaiveDateTime::parse_from_str(s,"%Y-%m-%d %H:%M:%S").map(|v|v.and_utc().timestamp()))
  .or_else(|_|chrono::DateTime::parse_from_str(s,"%Y-%m-%d %H:%M:%S%.f%#z").map(|v|v.timestamp())).map_err(|_|anyhow::anyhow!("unproven PHP date format"))
}
fn where_clause(filter:&str)->&'static str{
 match filter{
  "all"=>"TRUE",
  "about_us"=>"about_us = 1",
  "outbound"=>"direction = 'out'",
  "closed"=>"state IN ('dismissed', 'ignored')",
  _=>"direction = 'in' OR (state = 'open' AND direction = 'local' AND reporter_actor_id LIKE 'https://mkultra.monster/users/%') OR (state = 'open' AND direction = 'out' AND reporter_actor_id LIKE 'https://mkultra.monster/users/%')",
 }
}
/// actor_handle($actorId) with no username hint: ap_remote_actor_label, cache only.
async fn handle(db:&tokio_postgres::Client,cfg:&crate::config::Config,raw:&str,memo:&mut HashMap<String,String>,warm:&mut Vec<String>)->Result<String>{
 let actor=raw.trim_end_matches('/');
 if let Some(h)=memo.get(actor){return Ok(h.clone());}
 if !actor.starts_with("https://")||php_trim(actor)!=actor||bluesky_shaped(actor){bail!("PHP-only report actor label");}
 let cached=crate::profile_identity::lookup_actor(db,cfg,actor).await?;
 // PHP ignores remote_actors rows for unfederated local accounts; only the uncached case is proven.
 if local_host(actor)&&cached.is_some(){bail!("PHP-only local actor label");}
 if cached.as_ref().is_none_or(|r|crate::profile_identity::placeholder(r["username"].as_str().unwrap_or(""))){if !warm.iter().any(|w|w==actor){warm.push(actor.to_string());}}
 let h=crate::profile_identity::label(actor,cached.as_ref())["handle"].as_str().unwrap_or("").to_string();
 memo.insert(actor.to_string(),h.clone());Ok(h)
}
pub(super) async fn collect(db:&tokio_postgres::Client,cfg:&crate::config::Config,owner:i64,filter:&str,now:i64)->Result<Collected>{
 let sql=format!("SELECT jsonb_build_object('id',id::bigint,'direction',direction::text,'state',state::text,'reporter_actor_id',reporter_actor_id::text,'target_actor_id',target_actor_id::text,'status_uris_json',status_uris_json::text,'comment',comment::text,'about_us',about_us::text,'admin_notes',admin_notes::text,'created_at',created_at::text) FROM ap_reports WHERE {} ORDER BY id DESC LIMIT {LIMIT}",where_clause(filter));
 let mut reports=Vec::new();
 for row in db.query(&sql,&[]).await?{reports.push(serde_json::from_value::<Report>(row.get(0))?);}
 // admin_global_actor_control: actor rows keyed by rtrim(value,'/'); the later row in
 // ap_block_list order wins for one value. Looked-up keys are never Bluesky-shaped
 // (handle() refuses them), so alias expansion adds nothing such a key can match.
 let mut controls:HashMap<String,String>=HashMap::new();
 for row in db.query("SELECT value::text,kind::text FROM ap_blocks WHERE scope='actor' ORDER BY created_at DESC, id DESC",&[]).await?{
  let value:Option<String>=row.get(0);let kind:Option<String>=row.get(1);
  let value=value.unwrap_or_default();let key=value.trim_end_matches('/');if key.is_empty(){continue;}
  controls.insert(key.to_string(),kind.unwrap_or_default());
 }
 // admin_is_local_admin_actor: the page owner's actor plus every is_admin = 1 account.
 let owner_actor=super::settings_accounts::owner(db,owner).await?.actor_id;
 let mut admins:Vec<String>=Vec::new();
 if owner_actor.starts_with("https://"){admins.push(owner_actor);}
 for row in db.query("SELECT actor_id::text,actor_key::text FROM ap_users WHERE is_admin = 1",&[]).await?{
  for i in 0..2{let v:Option<String>=row.get(i);let v=php_trim(&v.unwrap_or_default()).trim_end_matches('/').to_string();if !v.is_empty(){admins.push(v);}}
 }
 let (mut memo,mut warm)=(HashMap::new(),Vec::new());
 let mut rows=Vec::new();
 for report in reports{
  // ap_report_priority_score
  let mut priority=if empty(report.about_us.as_deref().unwrap_or("")){0}else{5};
  priority+=if report.direction=="in"{2}else{1};
  let decoded:Option<Value>=serde_json::from_str(report.status_uris_json.as_deref().unwrap_or("[]")).ok();
  let mut uris=Vec::new();
  match decoded{
   Some(Value::Array(items))=>{priority+=items.len().min(3) as i64;if report.status_uris_json.as_deref().is_some_and(|s|!s.is_empty()){for item in items{if let Value::String(s)=item{if !s.is_empty(){uris.push(s);}}}}},
   Some(Value::Object(_))=>bail!("unproven status uri map"),
   _=>{},
  }
  if !report.created_at.is_empty(){let created=timestamp(&report.created_at)?;priority+=(3-(now-created).max(0)/86400).max(0);}
  let reporter_handle=if report.reporter_actor_id.is_empty(){"?".into()}else{handle(db,cfg,&report.reporter_actor_id,&mut memo,&mut warm).await?};
  let target=report.target_actor_id.clone().unwrap_or_default();
  let target_handle=if target.is_empty(){"unknown target".into()}else{handle(db,cfg,&target,&mut memo,&mut warm).await?};
  let actor=|uri:&str|->Option<Actor>{
   if uri.is_empty()||!uri.starts_with("https://"){return None;}
   let key=uri.trim_end_matches('/');let trimmed=php_trim(uri).trim_end_matches('/');
   Some(Actor{uri:uri.to_string(),control:controls.get(key).cloned(),admin:!trimmed.is_empty()&&admins.iter().any(|a|a==trimmed)})
  };
  let (reporter,target)=(actor(&report.reporter_actor_id),actor(&target));
  for su in &uris{let s=php_trim(su);if s.starts_with("at://")||s.starts_with("bsky:"){bail!("PHP-only AT URI link");}}
  rows.push(Row{report,priority,reporter,target,reporter_handle,target_handle,uris});
 }
 rows.sort_by(|a,b|b.priority.cmp(&a.priority).then(b.report.id.cmp(&a.report.id)));
 Ok(Collected{rows,warm})
}
/// admin_status_href($objectUrl, 'moderation'); AT URIs were refused in collect.
fn status_href(raw:&str)->String{
 static ANNOUNCE:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"#announce-\d+$").unwrap());
 let mut url=php_trim(raw).to_string();
 let stripped=ANNOUNCE.replace(&url,"").to_string();
 if stripped!=url{url=stripped.trim_end_matches('/').to_string();}
 if url.is_empty()||!url.starts_with("https://"){return "#".into();}
 format!("?view=status&object={}&from=moderation",query(&url))
}
fn row(h:&mut String,row:&Row,r:&Request,filter:&str){
 let rep=&row.report;let id=rep.id;let csrf=esc(&r.csrf);let f=esc(filter);
 let about=!empty(rep.about_us.as_deref().unwrap_or(""));
 let local_filed=matches!(rep.direction.as_str(),"out"|"local")&&rep.reporter_actor_id.starts_with("https://mkultra.monster/users/");
 let label=if rep.direction=="in"{"Inbound Flag"}else if rep.direction=="local"{"Local report for review"}else if local_filed{"Local user report"}else{"Outbound Flag"};
 let can_close=rep.state=="open"&&(rep.direction=="in"||local_filed);
 let target=rep.target_actor_id.as_deref().unwrap_or("");
 h.push_str(&format!(r#"<article class="tweet" style="{}"> <details class="report-detail"> <summary style="cursor:pointer;list-style:none"> <div class="tweet-hd"> <div class="tweet-hd-main"> <div><span class="who">{}</span> <span class="tag">{}</span> "#,if about{"border-color:var(--primary)"}else{""},esc(label),esc(&rep.state)));
 if about{h.push_str(r#"<span class="tag">about you</span> "#);}
 h.push_str(&format!(r#"<span class="tag" title="Triage hint only; never auto-moderates">priority {}</span> <span class="meta"> · {}</span> </div> <div class="meta">From {} → {}</div> </div> <span class="btn btn-ghost" style="padding:.25rem .6rem">Open report</span> </div> </summary> <div class="meta" style="margin:.65rem 0 .25rem">Report #{id} · reported account: <span class="mono" style="overflow-wrap:anywhere">{}</span></div> "#,
  row.priority,esc(&relative(&rep.created_at,r.now).unwrap_or_default()),esc(&row.reporter_handle),esc(&row.target_handle),esc(if target.is_empty(){"not provided"}else{target})));
 if rep.reporter_actor_id.starts_with("https://"){h.push_str(&format!(r#"<div class="meta" style="margin:.25rem 0 .5rem;overflow-wrap:anywhere">Reporter actor ID: <a href="{0}" target="_blank" rel="noopener noreferrer">{0}</a></div> "#,esc(&rep.reporter_actor_id)));}
 let comment=php_trim(rep.comment.as_deref().unwrap_or(""));
 if !comment.is_empty(){h.push_str(&format!(r#"<div class="body" style="margin:.5rem 0;white-space:pre-wrap">{}</div> "#,esc(comment)));}
 else{h.push_str(r#"<div class="meta" style="margin:.5rem 0">No report explanation was provided.</div> "#);}
 if !row.uris.is_empty(){
  h.push_str(r#"<div class="meta" style="margin:.5rem 0">Referenced posts:</div> <ul style="margin:.25rem 0 .75rem 1.1rem;padding:0"> "#);
  for su in row.uris.iter().take(12){h.push_str(&format!(r#"<li class="mono" style="margin:.25rem 0;word-break:break-all"><a href="{}">{1}</a> · <a href="{1}" target="_blank" rel="noopener noreferrer">open original URL</a></li> "#,esc(&status_href(su)),esc(su)));}
  h.push_str("</ul> ");
 }else{h.push_str(r#"<div class="meta" style="margin:.5rem 0">No posts were attached to this report.</div> "#);}
 h.push_str(r#"<section style="margin:.75rem 0;padding:.7rem;border:1px solid var(--border);border-radius:10px;background:var(--panel-2)"> <div class="meta" style="font-weight:700;margin-bottom:.35rem">Private admin notes</div> "#);
 let notes=php_trim(rep.admin_notes.as_deref().unwrap_or(""));
 if !notes.is_empty(){h.push_str(&format!(r#"<div class="mono" style="white-space:pre-wrap;overflow-wrap:anywhere;margin-bottom:.65rem">{}</div> "#,esc(notes)));}
 else{h.push_str(r#"<div class="meta" style="margin-bottom:.65rem">No admin notes yet. Use this to record why you took an action.</div> "#);}
 h.push_str(&format!(r#"<form method="post" action="?view=moderation&amp;filter={f}"> <input type="hidden" name="csrf" value="{csrf}"><input type="hidden" name="action" value="report_note"><input type="hidden" name="report_id" value="{id}"><input type="hidden" name="filter" value="{f}"> <textarea name="admin_note" maxlength="5000" required placeholder="Why was this report ignored, dismissed, or acted on? (visible only to admins)" style="min-height:4rem;width:100%"></textarea> <button class="btn btn-ghost" type="submit" style="margin-top:.4rem">Save private note</button> </form> </section> <div class="tweet-actions" style="flex-wrap:wrap;gap:.55rem;margin-top:.65rem"> "#));
 for (actor_label,actor) in [("Reporter",&row.reporter),("Reported account",&row.target)]{
  let Some(actor)=actor else{continue};
  h.push_str(&format!(r#"<a class="btn btn-ghost" href="?view=remote_profile&amp;actor={}&amp;from=moderation">Inspect {actor_label}</a> "#,urlencode(&actor.uri)));
  if actor.admin{h.push_str(r#"<span class="tag">Admin account · server-wide controls unavailable</span> "#);}
  else if let Some(kind)=&actor.control{h.push_str(&format!(r#"<span class="tag">Existing server {} for {actor_label}</span> "#,esc(kind)));}
  else{
   let lower=actor_label.to_ascii_lowercase();
   for (kind,control_label) in [("mute","Global mute"),("block","Server-wide block")]{
    let confirm=if kind=="mute"{format!("Apply a server-wide mute to this {lower}?")}else{format!("Block this {lower} server-wide? This rejects their federation with HTTP 403.")};
    h.push_str(&format!(r#"<form method="post" action="?view=moderation&amp;filter={f}" style="display:inline" onsubmit="return confirm({})"> <input type="hidden" name="csrf" value="{csrf}"><input type="hidden" name="action" value="block_actor"><input type="hidden" name="return_view" value="moderation"><input type="hidden" name="report_id" value="{id}"><input type="hidden" name="kind" value="{kind}"><input type="hidden" name="actor_id" value="{}"> <button class="btn btn-ghost" type="submit"{}>{control_label} {actor_label}</button> </form> "#,
     esc(&serde_json::to_string(&confirm).unwrap_or_default()),esc(&actor.uri),if kind=="block"{r#" style="color:var(--danger)""#}else{""}));
   }
  }
 }
 if can_close{
  for (action,button) in [("report_ignore","Ignore"),("report_dismiss","Dismiss")]{
   h.push_str(&format!(r#"<form method="post" action="?view=moderation&amp;filter={f}" style="display:inline"><input type="hidden" name="csrf" value="{csrf}"><input type="hidden" name="action" value="{action}"><input type="hidden" name="report_id" value="{id}"><input type="hidden" name="filter" value="{f}"><button class="btn {}" type="submit">{button}</button></form> "#,if action=="report_ignore"{"btn-primary"}else{"btn-ghost"}));
  }
 }else if matches!(rep.state.as_str(),"ignored"|"dismissed")&&(rep.direction=="in"||local_filed){
  h.push_str(&format!(r#"<form method="post" action="?view=moderation&amp;filter={f}" style="display:inline"><input type="hidden" name="csrf" value="{csrf}"><input type="hidden" name="action" value="report_reopen"><input type="hidden" name="report_id" value="{id}"><input type="hidden" name="filter" value="{f}"><button class="btn btn-primary" type="submit">{}</button></form> "#,if rep.state=="ignored"{"Un-ignore / reopen"}else{"Reopen report"}));
 }
 h.push_str("</div> </details> </article> ");
}
pub(super) fn paint(c:Collected,r:&Request)->Result<(String,usize,Value)>{
 let filter=r.filter.as_deref().unwrap_or("open");
 for row in &c.rows{relative(&row.report.created_at,r.now)?;}
 let mut h=String::new();
 if c.rows.is_empty(){h.push_str(r#"<div class="empty">No reports in this filter.</div>"#);}
 for one in &c.rows{row(&mut h,one,r,filter);}
 let out:Vec<Value>=c.rows.iter().map(|row|{let rep=&row.report;json!({"id":rep.id,"direction":rep.direction,"state":rep.state,"reporter_actor_id":rep.reporter_actor_id,"target_actor_id":rep.target_actor_id,"priority_score":row.priority,"created_at":rep.created_at})}).collect();
 Ok((h,out.len(),Value::Array(out)))
}
