//! Settings account sections copied from the profile view: Pending follower
//! requests (ap_follow_requests_list), Featured accounts (ap_featured_cards) and
//! Muted accounts (ap_mutes_list, admin_avatar_img, actor_display_name_html,
//! actor_handle). Rows are the session owner's own; any active account may use
//! them. PHP keeps CSRF injection and every action. Rows PHP would answer with
//! a synchronous fetch or a media/emoji warm (uncached Featured avatar, stale
//! or missing muted avatar, display names with emoji shortcodes, local or
//! Bluesky actors) render the PHP section instead. Uncached muted actors get
//! PHP's URL label and the same async actor warm. Spaces stand where the PHP
//! template has whitespace.
use super::*;
use crate::library::card::php_trim;
const REMOTE_FALLBACK:&str="https://mkultra.monster/img/avatar/default.webp";
const FEATURED_MAX:usize=12;
pub(super) const MAX_ROWS:usize=1000;
static USER_SEGMENT:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"/(?:users|@)([^/]+)/?$").expect("user segment pattern"));
#[derive(Debug,Clone,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct FollowRequest {id:i64,actor_id:String,username:Option<String>}
#[derive(Debug,Clone,Serialize)]
pub(super) struct Featured {id:i64,actor_id:String,acct:String,display_name:String,avatar:String}
#[derive(Debug,Clone,Serialize)]
pub(super) struct Mute {actor_id:String,created_at:String,#[serde(skip)]avatar:String,#[serde(skip)]who:String,#[serde(skip)]handle:String,#[serde(skip)]pub(super) warm:Option<String>,#[serde(skip)]pub(super) media_warm:Option<String>}
pub(super) struct Owner {pub(super) actor_id:String,actor_key:String}
pub(super) async fn owner(db:&tokio_postgres::Client,id:i64)->Result<Owner>{
 let row=db.query_one("SELECT actor_id::text,actor_key::text FROM ap_users WHERE id::bigint=$1",&[&id]).await?;
 let key:Option<String>=row.get(1);let key=key.unwrap_or_else(||"cmdr_nova".into());
 // $vaakActorId = rtrim($vaakUser['actor_id'] ?? 'https://mkultra.monster/users/'.$vaakActorKey, '/')
 let actor:Option<String>=row.get(0);let actor=actor.unwrap_or_else(||format!("https://mkultra.monster/users/{key}")).trim_end_matches('/').to_string();
 Ok(Owner{actor_id:actor,actor_key:key})
}
fn host(actor:&str)->String{actor.strip_prefix("https://").unwrap_or("").split(['/', '?','#']).next().unwrap_or("").rsplit('@').next().unwrap_or("").split(':').next().unwrap_or("").to_ascii_lowercase()}
fn local_host(actor:&str)->bool{matches!(host(actor).as_str(),"mkultra.monster"|"www.mkultra.monster"|"vaak.monster"|"www.vaak.monster")}
pub(super) async fn follow_requests(db:&tokio_postgres::Client,owner:&Owner)->Result<Vec<FollowRequest>>{
 let mut rows=Vec::new();
 for row in db.query("SELECT jsonb_build_object('id',id::bigint,'actor_id',actor_id::text,'username',username::text) FROM ap_follow_requests WHERE owner_actor_id=$1 AND status='pending' ORDER BY created_at ASC, id ASC LIMIT 1001",&[&owner.actor_id]).await?{rows.push(serde_json::from_value::<FollowRequest>(row.get(0))?);}
 if rows.len()>MAX_ROWS{bail!("follow requests beyond bridge bound");}
 Ok(rows)
}
pub(super) fn paint_follow_requests(rows:Vec<FollowRequest>,r:&Request)->Result<(String,usize,Value)>{
 let mut h=String::new();
 if !rows.is_empty(){
  h.push_str(r#"<div class="composer" style="margin:0 0 1rem"> <h3 style="margin-top:0">Pending follower requests</h3> <div class="meta" style="margin-bottom:.65rem">Approve requests to add the account to your followers, or reject them without accepting the follow.</div> "#);
  for f in &rows{
   let name=php_trim(f.username.as_deref().unwrap_or(""));let label=if name.is_empty(){f.actor_id.clone()}else{format!("@{name}")};
   let form=|decision:&str,class:&str,text:&str|format!(r#"<form method="post" action="?view=profile" style="display:inline"> <input type="hidden" name="csrf" value="{}"> <input type="hidden" name="action" value="follow_request_decide"><input type="hidden" name="id" value="{}"><input type="hidden" name="decision" value="{decision}"> <button class="{class}" type="submit">{text}</button> </form>"#,esc(&r.csrf),f.id);
   h.push_str(&format!(r#"<div class="row" style="justify-content:space-between;gap:.75rem;align-items:center;margin:.55rem 0"> <a href="?view=remote_profile&amp;actor={}">{}</a> <span style="display:flex;gap:.45rem;flex-wrap:wrap"> {} {} </span> </div> "#,esc(&query(&f.actor_id)),esc(&label),form("approve","btn btn-primary","Approve"),form("reject","btn","Reject")));
  }
  h.push_str("</div>");
 }
 Ok((h,rows.len(),serde_json::to_value(rows)?))
}
pub(super) async fn featured(db:&tokio_postgres::Client,cfg:&crate::config::Config,owner:i64)->Result<Vec<Featured>>{
 let mut out=Vec::new();
 for row in db.query("SELECT id::bigint,target_actor_id::text FROM ap_featured_accounts WHERE owner_user_id::bigint=$1 ORDER BY position ASC, id ASC LIMIT 40",&[&owner]).await?{
  let id:i64=row.get(0);let raw:Option<String>=row.get(1);let actor=raw.unwrap_or_default().trim_end_matches('/').to_string();
  if actor.is_empty()||!actor.starts_with("https://"){continue;}
  let local=actor.strip_prefix("https://mkultra.monster/users/").filter(|k|!k.is_empty()&&k.bytes().all(|b|b.is_ascii_alphanumeric()||b==b'_')).map(str::to_ascii_lowercase);
  let (acct,display,avatar)=if let Some(key)=local{
   let Some(p)=db.query_opt("SELECT name::text,icon_url::text FROM actor_profile WHERE actor_key=$1",&[&key]).await? else {bail!("PHP default local profile")};
   let name:Option<String>=p.get(0);let icon:Option<String>=p.get(1);
   let display=php_trim(name.as_deref().unwrap_or(&key)).to_string();
   (format!("{key}@mkultra.monster"),if display.is_empty(){key.clone()}else{display},crate::profile_identity::local_avatar(icon.as_deref().unwrap_or("")))
  }else{
   let meta=crate::profile_identity::lookup_actor(db,cfg,&actor).await?;
   let get=|k:&str|meta.as_ref().map(|m|match &m[k]{Value::String(s)=>s.clone(),Value::Null=>String::new(),v=>v.to_string()}).unwrap_or_default();
   let mut user=get("username");let mut host_name=get("host");let mut display=php_trim(&get("display_name")).to_string();
   if host_name.is_empty(){host_name=host(&actor);}
   // preg_match('#/(?:users|@)([^/]+)/?$#') then rawurldecode, exactly as ap_featured_card_for_actor.
   if user.is_empty(){if let Some(m)=USER_SEGMENT.captures(&actor){user=crate::hidden::raw_percent_decode(&m[1]);}}
   let acct=if !user.is_empty()&&!host_name.is_empty(){format!("{user}@{host_name}")}else{actor.clone()};
   if display.is_empty(){display=if user.is_empty(){acct.clone()}else{user.clone()};}
   let cached=db.query_opt("SELECT public_url::text FROM remote_media_cache WHERE actor_id=$1 AND kind='avatar'",&[&actor]).await?;
   let Some(url)=cached.and_then(|c|c.get::<_,Option<String>>(0)).filter(|u|!u.is_empty()&&u!="0") else {bail!("PHP would ensure this Featured avatar")};
   (acct,display,url)
  };
  out.push(Featured{id,actor_id:actor,acct,display_name:display,avatar});
 }
 Ok(out)
}
pub(super) fn paint_featured(cards:Vec<Featured>,owner:&Owner)->Result<(String,usize,Value)>{
 let mut h=format!(r#"<div class="composer" style="margin-top:1.25rem"> <div class="meta" style="margin-bottom:.75rem"> <b style="color:var(--primary)">Featured accounts</b><br> Every VAAK account can curate this list — it shows on <b>your</b> public profile’s <b>Featured</b> tab (Mastodon-style endorsements). Max {FEATURED_MAX} · we fetch their avatar when you add them. · <a href="/users/{}?tab=featured" target="_blank" rel="noopener">Preview</a> </div> "#,esc(&query(&owner.actor_key)));
 if cards.is_empty(){h.push_str(r#"<div class="meta" style="margin-bottom:.75rem">No featured accounts yet.</div> "#);}
 else{
  h.push_str(r#"<div style="display:flex;flex-direction:column;gap:.65rem;margin-bottom:1rem"> "#);
  let mv=|id:i64,dir:&str,title:&str,arrow:&str|format!(r#"<form method="post" action="?view=profile" style="display:inline"> <input type="hidden" name="action" value="featured_move"> <input type="hidden" name="featured_id" value="{id}"> <input type="hidden" name="dir" value="{dir}"> <button class="btn btn-ghost" type="submit" style="padding:.25rem .65rem;font-size:.8rem" title="{title}">{arrow}</button> </form> "#);
  for (i,c) in cards.iter().enumerate(){
   h.push_str(&format!(r#"<div style="display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;padding:.45rem .55rem;border:1px solid var(--border);border-radius:12px;background:#0e0e0e"> <img src="{}" alt="" width="40" height="40" style="border-radius:50%;object-fit:cover;border:1px solid var(--border);flex:0 0 auto" loading="lazy" referrerpolicy="no-referrer" onerror="this.src='{REMOTE_FALLBACK}'"> <div style="flex:1 1 10rem;min-width:0"> <div class="who" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{}</div> <div class="meta">@{}</div> </div> <div style="display:flex;gap:.35rem;flex-wrap:wrap"> "#,esc(&c.avatar),esc(&c.display_name),esc(&c.acct)));
   if i>0{h.push_str(&mv(c.id,"up","Move up","↑"));}
   if i+1<cards.len(){h.push_str(&mv(c.id,"down","Move down","↓"));}
   h.push_str(&format!(r#"<form method="post" action="?view=profile" style="display:inline" onsubmit="return confirm('Remove from Featured?');"> <input type="hidden" name="action" value="featured_remove"> <input type="hidden" name="featured_id" value="{}"> <button class="btn btn-ghost" type="submit" style="padding:.25rem .65rem;font-size:.8rem;color:var(--danger)">Remove</button> </form> </div> </div> "#,c.id));
  }
  h.push_str("</div> ");
 }
 if cards.len()<FEATURED_MAX{h.push_str(&format!(r#"<form method="post" action="?view=profile"> <input type="hidden" name="action" value="featured_add"> <label for="featured-ref">Add account</label> <input id="featured-ref" type="text" name="featured_ref" required maxlength="400" placeholder="@user@instance or https://…/users/…"> <div class="composer-actions"> <span class="meta">{} / {FEATURED_MAX}</span> <button class="btn btn-primary" type="submit">Feature</button> </div> </form> "#,cards.len()));}
 else{h.push_str(&format!(r#"<div class="meta">Limit reached ({FEATURED_MAX}). Remove one to add another.</div> "#));}
 h.push_str("</div>");
 Ok((h,cards.len(),serde_json::to_value(cards)?))
}
fn epoch(s:&str)->Result<i64>{
 chrono::DateTime::parse_from_rfc3339(s).map(|v|v.timestamp())
  .or_else(|_|chrono::NaiveDateTime::parse_from_str(s,"%Y-%m-%d %H:%M:%S").map(|v|v.and_utc().timestamp()))
  .or_else(|_|chrono::DateTime::parse_from_str(s,"%Y-%m-%d %H:%M:%S%.f%#z").map(|v|v.timestamp()))
  .map_err(|_|anyhow::anyhow!("unproven PHP date format"))
}
/// ap_feature_enabled('remote_media_warm', true)
fn media_warm_enabled()->bool{std::env::var("VAAK_FEATURE_REMOTE_MEDIA_WARM").ok().map(|v|php_trim(&v).to_ascii_lowercase()).filter(|v|!v.is_empty()).is_none_or(|v|!matches!(v.as_str(),"0"|"false"|"off"|"no"))}
pub(super) async fn mutes(db:&tokio_postgres::Client,cfg:&crate::config::Config,owner:i64,now:i64)->Result<Vec<Mute>>{
 let mut out=Vec::new();
 for row in db.query("SELECT actor_id::text,created_at::text FROM ap_mutes WHERE owner_user_id::bigint=$1 ORDER BY created_at DESC, actor_id DESC LIMIT 1001",&[&owner]).await?{
  let raw:Option<String>=row.get(0);let created:Option<String>=row.get(1);let raw=raw.unwrap_or_default();
  let actor=raw.trim_end_matches('/').to_string();
  if raw.is_empty()||php_trim(&raw)!=raw||!actor.starts_with("https://")||local_host(&actor){bail!("PHP-only muted actor");}
  let mut media_warm=None;
  let avatar=match actor.as_str(){
   "https://waffles.baeddel.social/fediverse/blog/admin"|"https://app.wafrn.net/fediverse/blog/admin"=>"https://mkultra.monster/img/avatar/wafrn-approvals.webp".to_string(),
   _=>{
    let Some(c)=db.query_opt("SELECT public_url::text,fetched_at::text FROM remote_media_cache WHERE actor_id=$1 AND kind='avatar'",&[&actor]).await? else {bail!("PHP would refresh this avatar")};
    let url:Option<String>=c.get(0);let fetched:Option<String>=c.get(1);
    let Some(url)=url.filter(|u|!u.is_empty()&&u!="0").and_then(|u|crate::profile_identity::sanitized_profile_url(&u)) else {bail!("PHP would refresh this avatar")};
    let fetched=fetched.unwrap_or_default();let at=if fetched.is_empty(){0}else{epoch(&fetched)?};
    // admin_actor_avatar_url still paints a stale cached avatar and queues ap_remote_media_warm_async.
    if at>0&&at<now-7*86400&&media_warm_enabled(){media_warm=Some(actor.clone());}
    url
   }
  };
  let cached=crate::profile_identity::lookup_actor(db,cfg,&actor).await?;
  let warm=cached.as_ref().is_none_or(|r|crate::profile_identity::placeholder(r["username"].as_str().unwrap_or(""))).then(||actor.clone());
  let label=crate::profile_identity::label(&actor,cached.as_ref());
  let handle=label["handle"].as_str().unwrap_or("").to_string();
  let display=php_trim(label["display_name"].as_str().unwrap_or("")).to_string();
  let who=if display.is_empty()||crate::profile_identity::placeholder(&display){handle.clone()}else{display};
  if who.contains(':'){bail!("PHP would look up custom emoji");}
  out.push(Mute{actor_id:raw,created_at:created.unwrap_or_default(),avatar,who,handle,warm,media_warm});
 }
 if out.len()>MAX_ROWS{bail!("mutes beyond bridge bound");}
 Ok(out)
}
pub(super) fn paint_mutes(rows:Vec<Mute>,r:&Request)->Result<(String,usize,Value)>{
 let mut h=format!(r#"<details class="profile-list-section"><summary style="cursor:pointer;font-size:1rem;font-weight:650;margin:1.25rem 0 .5rem">Muted accounts</summary> <div class="meta" style="margin-bottom:.75rem"> Hide from <b>your</b> Home / Federated / Notifications. Follow stays. Also available on remote profiles. </div> <form class="composer" method="post" action="?view=profile" style="margin-bottom:1rem"> <input type="hidden" name="action" value="mute_remote"> <input type="hidden" name="return_view" value="profile"> <input name="actor_id" type="text" required placeholder="@user@instance  or  https://…/users/…"> <div class="composer-actions"> <span class="meta">{} muted</span> <button class="btn btn-primary" type="submit">Mute</button> </div> </form> "#,rows.len());
 if rows.is_empty(){h.push_str(r#"<div class="empty" style="margin-bottom:1.25rem">No mutes yet.</div> "#);}
 for m in &rows{
  let trimmed=m.actor_id.trim_end_matches('/');let actor=esc(&m.actor_id);
  h.push_str(&format!(r#"<article class="tweet"> <div class="tweet-hd"> <img class="tweet-av" src="{}" alt="" width="40" height="40"  data-profile-hover-actor="{}" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='{REMOTE_FALLBACK}'"> <div class="tweet-hd-main"> <div> <span class="who">{}</span> <span class="meta"> {}</span> <span class="meta"> · {}</span> </div> </div> </div> <div class="mono">{actor}</div> <div class="tweet-actions"> <a href="?view=remote_profile&amp;actor={}&amp;from=profile">Profile</a> <a href="{actor}" target="_blank" rel="noopener noreferrer">Open remote</a> <form method="post" action="?view=profile" style="display:inline"> <input type="hidden" name="action" value="unmute_remote"> <input type="hidden" name="return_view" value="profile"> <input type="hidden" name="actor_id" value="{actor}"> <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Unmute</button> </form> </div> </article> "#,
   esc(&m.avatar),esc(trimmed),esc(&m.who),esc(&m.handle),esc(&relative(&m.created_at,r.now)?),urlencode(&m.actor_id)));
 }
 h.push_str("</details>");
 Ok((h,rows.len(),serde_json::to_value(&rows)?))
}
