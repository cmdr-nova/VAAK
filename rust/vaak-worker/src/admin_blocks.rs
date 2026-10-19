//! Server blocks read page copied from ap_block_list, actor_display_name /
//! actor_handle and the ap-admin Blocks branch. PHP retains CSRF injection and
//! every block/unblock action and its federation. Actor rows that PHP labels
//! from a local profile render the PHP page instead. Rows with no cached actor
//! or a placeholder username get PHP's URL-derived label and the same async
//! actor warm PHP queues for them. Spaces stand where the PHP template has
//! whitespace.
use super::*;
use crate::library::card::php_trim;
#[derive(Debug,Clone,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct Block {id:i64,scope:String,value:String,kind:String,reason:Option<String>,created_at:String}
pub(super) struct Row {block:Block,who:String,handle:String,pub(super) warm:Option<String>}
pub(super) const MAX_ROWS:usize=1000;
fn local_host(actor:&str)->bool{
 let host=actor.strip_prefix("https://").unwrap_or("").split(['/', '?','#']).next().unwrap_or("").rsplit('@').next().unwrap_or("").split(':').next().unwrap_or("").to_ascii_lowercase();
 matches!(host.as_str(),"mkultra.monster"|"www.mkultra.monster"|"vaak.monster"|"www.vaak.monster")
}
pub(super) async fn collect(db:&tokio_postgres::Client,cfg:&crate::config::Config)->Result<Vec<Row>>{
 let mut rows=Vec::new();
 for row in db.query("SELECT jsonb_build_object('id',id::bigint,'scope',scope::text,'value',value::text,'kind',kind::text,'reason',reason::text,'created_at',created_at::text) FROM ap_blocks ORDER BY created_at DESC, id DESC LIMIT 1001",&[]).await?{
  let block:Block=serde_json::from_value(row.get(0))?;
  let (mut who,mut handle,mut warm)=(block.value.clone(),String::new(),None);
  if block.scope=="actor"&&!block.value.is_empty(){
   let actor=block.value.trim_end_matches('/');
   if !actor.starts_with("https://")||local_host(actor){bail!("PHP-only actor label");}
   let cached=crate::profile_identity::lookup_actor(db,cfg,actor).await?;
   if cached.as_ref().is_none_or(|r|crate::profile_identity::placeholder(r["username"].as_str().unwrap_or(""))){warm=Some(actor.to_string());}
   let label=crate::profile_identity::label(actor,cached.as_ref());
   handle=label["handle"].as_str().unwrap_or("").to_string();
   let display=php_trim(label["display_name"].as_str().unwrap_or("")).to_string();
   let name=if display.is_empty()||crate::profile_identity::placeholder(&display){handle.clone()}else{display};
   if !empty(&name){who=name;}
  }else if !matches!(block.scope.as_str(),"actor"|"domain"){bail!("unproven block scope");}
  rows.push(Row{block,who,handle,warm});
 }
 if rows.len()>MAX_ROWS{bail!("block rows beyond bridge bound");}
 Ok(rows)
}
fn row(h:&mut String,row:&Row,now:i64)->Result<()>{
 let b=&row.block;let actor=b.scope=="actor";let reason=php_trim(b.reason.as_deref().unwrap_or(""));
 let id_tag=format!("#{}",b.id);
 let blob=[b.value.as_str(),row.who.as_str(),row.handle.as_str(),b.kind.as_str(),b.scope.as_str(),reason,id_tag.as_str()].into_iter().filter(|s|!empty(s)).collect::<Vec<_>>().join(" ");
 h.push_str(&format!(r#"<article class="tweet blocks-row" data-search="{}"> <div class="tweet-hd"> <div style="min-width:0"> <span class="who" style="overflow-wrap:anywhere">{}</span> "#,esc(&php_trim(&blob).to_ascii_lowercase()),esc(&row.who)));
 if !row.handle.is_empty()&&row.handle!=row.who{h.push_str(&format!(r#"<span class="meta"> {}</span> "#,esc(&row.handle)));}
 else if b.scope=="domain"{h.push_str(r#"<span class="meta"> server</span> "#);}
 h.push_str(&format!(r#"<span class="meta"> · {} · {}</span> </div> <div class="meta">#{}</div> </div> "#,esc(&b.kind),esc(&relative(&b.created_at,now)?),b.id));
 if actor&&!b.value.is_empty()&&row.who!=b.value{h.push_str(&format!(r#"<div class="mono meta" style="margin-top:.25rem;overflow-wrap:anywhere">{}</div> "#,esc(&b.value)));}
 if !reason.is_empty(){h.push_str(&format!(r#"<div class="body meta">{}</div> "#,esc(reason)));}
 h.push_str(&format!(r#"<div class="tweet-actions"> <form method="post" action="?view=blocks" style="display:inline"> <input type="hidden" name="action" value="unblock"> <input type="hidden" name="id" value="{}"> <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Remove</button> </form> "#,b.id));
 if actor{h.push_str(&format!(r#"<a href="?view=remote_profile&amp;actor={}&amp;from=blocks">Profile</a> <a href="{}" target="_blank" rel="noopener noreferrer">Open</a> "#,urlencode(&b.value),esc(&b.value)));}
 else{h.push_str(&format!(r#"<a href="https://{}/" target="_blank" rel="noopener noreferrer">Open host</a> "#,esc(&b.value)));}
 h.push_str("</div> </article> ");Ok(())
}
fn section(h:&mut String,rows:&[&Row],now:i64,key:&str,title:&str,note:&str,placeholder:&str,aria:&str,none:&str,no_match:&str)->Result<()>{
 h.push_str(&format!(r#"<details class="blocks-section"{} data-blocks-section="{key}"> <summary> <span class="who">{title}</span> <span class="tag" data-blocks-count>{}</span> <span class="meta">{note}</span> </summary> <div class="blocks-section-body"> <div class="blocks-section-search"> <input type="search" data-blocks-filter placeholder="{placeholder}" aria-label="{aria}"{}> <span class="meta" data-blocks-visible>{} shown</span> </div> "#,
  if rows.len()<=80{" open"}else{""},rows.len(),if rows.is_empty(){" disabled"}else{""},rows.len()));
 if rows.is_empty(){h.push_str(&format!(r#"<div class="empty">{none}</div> "#));}
 else{h.push_str(r#"<div class="blocks-section-list" data-blocks-list> "#);for r in rows{row(h,r,now)?;}h.push_str(&format!(r#"</div> <div class="empty blocks-section-empty">{no_match}</div> "#));}
 h.push_str("</div> </details> ");Ok(())
}
const SCRIPT:&str=r#"<script> (function () { document.querySelectorAll('[data-blocks-section]').forEach((section) => { const input = section.querySelector('[data-blocks-filter]'); const list = section.querySelector('[data-blocks-list]'); const visibleEl = section.querySelector('[data-blocks-visible]'); if (!input || !list) return; const rows = Array.from(list.querySelectorAll('.blocks-row')); const apply = () => { const q = String(input.value || '').trim().toLowerCase(); let shown = 0; rows.forEach((row) => { const hay = row.getAttribute('data-search') || ''; const ok = !q || hay.includes(q); row.hidden = !ok; if (ok) shown += 1; }); list.classList.toggle('is-empty', shown === 0); if (visibleEl) { visibleEl.textContent = shown + ' shown'; } }; input.addEventListener('input', apply); input.addEventListener('search', apply); }); })(); </script>"#;
pub(super) fn paint(rows:Vec<Row>,r:&Request)->Result<(String,usize,Value)>{
 let (domains,actors):(Vec<&Row>,Vec<&Row>)=rows.iter().partition(|row|row.block.scope=="domain");
 let mut h=format!(r#"<div class="meta" style="margin-bottom:1rem"> <b>Server-wide controls</b>: <b>Block / Suspend</b> fully reject inbound federation (HTTP 403), stop outbound delivery, and for actor blocks also send ActivityPub <code>Block</code> from local accounts so remotes learn; domain blocks stay local reject (no mass fan-out); <b>Global mute</b> only hides their content locally (they can still federate). Personal mutes/blocks live under <a href="?view=profile">Profile</a>. </div> <form class="composer" method="post" action="?view=blocks" style="margin-bottom:1rem"> <input type="hidden" name="action" value="block_add"> <div class="meta" style="margin-bottom:.5rem"> Mute, block, or suspend a <b>domain</b> (<code>example.com</code>) or <b>user</b> (<code>@user@host</code> / actor URL). </div> <input name="target" type="text" required placeholder="example.com  or  @user@instance  or  https://…/users/…"> <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin:.5rem 0"> <label class="meta" style="display:flex;gap:.35rem;align-items:center;color:var(--text)"> <input type="radio" name="kind" value="block" checked> Block </label> <label class="meta" style="display:flex;gap:.35rem;align-items:center;color:var(--text)"> <input type="radio" name="kind" value="suspend"> Suspend </label> <label class="meta" style="display:flex;gap:.35rem;align-items:center;color:var(--text)"> <input type="radio" name="kind" value="mute"> Global mute </label> </div> <input name="reason" type="text" maxlength="500" placeholder="Optional note (spam, harassment, …)"> <div class="composer-actions"> <span class="meta">{} users · {} servers · block/suspend = 403; mute = hide only</span> <button class="btn btn-primary" type="submit">Add control</button> </div> </form> "#,actors.len(),domains.len());
 section(&mut h,&actors,r.now,"actors","Blocked users","server-wide actor controls","Search users, handles, URLs, notes…","Search blocked users","No server-wide user controls yet.","No users match this search.")?;
 section(&mut h,&domains,r.now,"domains","Blocked servers","server-wide domain controls — click to expand","Search domains, notes…","Search blocked servers","No server-wide domain controls yet.","No servers match this search.")?;
 h.push_str(SCRIPT);
 let blocks:Vec<&Block>=rows.iter().map(|row|&row.block).collect();
 Ok((h,rows.len(),serde_json::to_value(blocks)?))
}
