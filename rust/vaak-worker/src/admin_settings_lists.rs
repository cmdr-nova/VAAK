//! Settings personal lists copied from ap_deprioritized_list, ap_muted_words_list,
//! ap_user_blocks_list and the profile view's Deprioritized / Muted words / Your
//! blocks sections. Every row is scoped to the session owner; the page is open to
//! every active account, never admin-gated. PHP retains Muted accounts (actor
//! labels, avatars, emoji), the filter script, CSRF injection and every action.
//! Spaces stand where the PHP template has whitespace.
use super::*;
#[derive(Debug,Clone,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct Deprioritized {actor_id:String,created_at:String}
#[derive(Debug,Clone,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct MutedWord {id:i64,phrase:String,created_at:String,note:Option<String>}
#[derive(Debug,Clone,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct UserBlock {id:i64,scope:String,value:String,kind:String,reason:Option<String>,created_at:String}
pub(super) struct Collected {deprioritized:Vec<Deprioritized>,words:Vec<MutedWord>,blocks:Vec<UserBlock>}
pub(super) const MAX_ROWS:usize=1000;
async fn rows<T:serde::de::DeserializeOwned>(db:&tokio_postgres::Client,sql:&str,owner:i64)->Result<Vec<T>>{
 let mut out=Vec::new();
 for row in db.query(sql,&[&owner]).await?{out.push(serde_json::from_value::<T>(row.get(0))?);}
 if out.len()>MAX_ROWS{bail!("personal list beyond bridge bound");}
 Ok(out)
}
pub(super) async fn collect(db:&tokio_postgres::Client,owner:i64)->Result<Collected>{
 Ok(Collected{
  deprioritized:rows(db,"SELECT jsonb_build_object('actor_id',actor_id::text,'created_at',created_at::text) FROM ap_deprioritized_actors WHERE owner_user_id::bigint=$1 ORDER BY created_at DESC, actor_id DESC LIMIT 1001",owner).await?,
  words:rows(db,"SELECT jsonb_build_object('id',id::bigint,'phrase',phrase::text,'created_at',created_at::text,'note',note::text) FROM ap_muted_words WHERE owner_user_id::bigint=$1 ORDER BY created_at DESC, id DESC LIMIT 1001",owner).await?,
  blocks:rows(db,"SELECT jsonb_build_object('id',id::bigint,'scope',scope::text,'value',value::text,'kind',kind::text,'reason',reason::text,'created_at',created_at::text) FROM ap_user_blocks WHERE owner_user_id::bigint=$1 ORDER BY created_at DESC, id DESC LIMIT 1001",owner).await?,
 })
}
const SUMMARY:&str=r#"<summary style="cursor:pointer;font-size:1rem;font-weight:650;margin:"#;
const REMOVE:&str=r#"<button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">"#;
pub(super) fn paint(c:Collected,r:&Request)->Result<(String,usize,Value)>{
 let mut h=format!(r#"<details class="profile-list-section">{SUMMARY}1.25rem 0 .5rem">Deprioritized on Home</summary> <div class="meta" style="margin-bottom:.75rem"> Soft-rank these accounts lower on <b>Home</b> only (⋯ → Deprioritize). They still appear on Federated, Local, and notifications. Mute/block still fully hide. </div> "#);
 if c.deprioritized.is_empty(){h.push_str(r#"<div class="empty" style="margin-bottom:1.25rem">Nobody deprioritized yet — use ⋯ on a post.</div> "#);}
 for d in &c.deprioritized{
  let actor=esc(d.actor_id.trim_end_matches('/'));
  h.push_str(&format!(r#"<article class="tweet"> <div class="tweet-hd"> <div> <span class="who">{actor}</span> <span class="meta"> · {}</span> </div> </div> <div class="tweet-actions"> <form method="post" action="?view=profile" style="display:inline"> <input type="hidden" name="csrf" value="{}"> <input type="hidden" name="action" value="undeprioritize_remote"> <input type="hidden" name="return_view" value="profile"> <input type="hidden" name="actor_id" value="{actor}"> {REMOVE}Stop deprioritizing</button> </form> </div> </article> "#,esc(&relative(&d.created_at,r.now)?),esc(&r.csrf)));
 }
 h.push_str(&format!(r#"</details> <details class="profile-list-section">{SUMMARY}1.25rem 0 .5rem">Muted words</summary> <div class="meta" style="margin-bottom:.75rem"> Hide posts on your Home &amp; Federated when text/CW contains a phrase (case-insensitive substring). </div> <form class="composer" method="post" action="?view=profile" style="margin-bottom:1rem"> <input type="hidden" name="action" value="muted_word_add"> <input name="phrase" type="text" required maxlength="200" placeholder="word or phrase to hide…" autocomplete="off"> <input name="note" type="text" maxlength="300" placeholder="Optional note"> <div class="composer-actions"> <span class="meta">{} phrase{}</span> <button class="btn btn-primary" type="submit">Mute phrase</button> </div> </form> "#,c.words.len(),if c.words.len()==1{""}else{"s"}));
 if c.words.is_empty(){h.push_str(r#"<div class="empty" style="margin-bottom:1.25rem">No muted words yet.</div> "#);}
 for w in &c.words{
  h.push_str(&format!(r#"<article class="tweet"> <div class="tweet-hd"> <div> <span class="who">“{}”</span> <span class="meta"> · {}</span> </div> <div class="meta">#{}</div> </div> "#,esc(&w.phrase),esc(&relative(&w.created_at,r.now)?),w.id));
  if let Some(note)=w.note.as_deref().filter(|s|!empty(s)){h.push_str(&format!(r#"<div class="body meta">{}</div> "#,esc(note)));}
  h.push_str(&format!(r#"<div class="tweet-actions"> <form method="post" action="?view=profile" style="display:inline"> <input type="hidden" name="action" value="muted_word_remove"> <input type="hidden" name="id" value="{}"> {REMOVE}Remove</button> </form> </div> </article> "#,w.id));
 }
 h.push_str(&format!(r#"</details> <details class="profile-list-section">{SUMMARY}2rem 0 .5rem">Your blocks</summary> <div class="meta" style="margin-bottom:.75rem"> Personal blocks hide that account’s <b>profile and posts</b> from you (timelines, search, profile pages). Mutes only filter timelines/notifications. "#));
 if r.viewer_admin==Some(true){h.push_str(r#"Server-wide enforcement: <a href="?view=blocks">Admin → Server blocks</a>. "#);}
 h.push_str(&format!(r#"</div> <form class="composer" method="post" action="?view=profile" style="margin-bottom:1rem"> <input type="hidden" name="action" value="user_block_add"> <input name="target" type="text" required placeholder="example.com  or  @user@instance  or  https://…/users/…"> <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin:.5rem 0"> <label class="meta" style="display:flex;gap:.35rem;align-items:center;color:var(--text)"> <input type="radio" name="kind" value="block" checked> Block </label> <label class="meta" style="display:flex;gap:.35rem;align-items:center;color:var(--text)"> <input type="radio" name="kind" value="suspend"> Suspend </label> </div> <input name="reason" type="text" maxlength="500" placeholder="Optional note"> <div class="composer-actions"> <span class="meta">{} personal</span> <button class="btn btn-primary" type="submit">Add personal block</button> </div> </form> "#,c.blocks.len()));
 if c.blocks.is_empty(){h.push_str(r#"<div class="empty" style="margin-bottom:1.25rem">No personal blocks yet.</div> "#);}
 for b in &c.blocks{
  h.push_str(&format!(r#"<article class="tweet"> <div class="tweet-hd"> <div> <span class="who">{}</span> <span class="meta"> · {} · {} · {}</span> </div> <div class="meta">#{}</div> </div> "#,esc(&b.value),esc(&b.scope),esc(&b.kind),esc(&relative(&b.created_at,r.now)?),b.id));
  if let Some(reason)=b.reason.as_deref().filter(|s|!empty(s)){h.push_str(&format!(r#"<div class="body meta">{}</div> "#,esc(reason)));}
  h.push_str(&format!(r#"<div class="tweet-actions"> <form method="post" action="?view=profile" style="display:inline"> <input type="hidden" name="action" value="user_block_remove"> <input type="hidden" name="id" value="{}"> {REMOVE}Remove</button> </form> "#,b.id));
  if b.scope=="actor"{h.push_str(&format!(r#"<a href="?view=remote_profile&amp;actor={}&amp;from=profile">Profile</a> "#,urlencode(&b.value)));}
  h.push_str("</div> </article> ");
 }
 h.push_str("</details>");
 // One closed row shape for the bridge: list, id, value, note, scope, kind, created_at.
 let mut out=Vec::new();
 for d in &c.deprioritized{out.push(json!({"list":"deprioritized","id":null,"value":d.actor_id,"note":null,"scope":null,"kind":null,"created_at":d.created_at}));}
 for w in &c.words{out.push(json!({"list":"muted_words","id":w.id,"value":w.phrase,"note":w.note,"scope":null,"kind":null,"created_at":w.created_at}));}
 for b in &c.blocks{out.push(json!({"list":"user_blocks","id":b.id,"value":b.value,"note":b.reason,"scope":b.scope,"kind":b.kind,"created_at":b.created_at}));}
 Ok((h,out.len(),Value::Array(out)))
}
