//! Native local profile chrome and secondary-tab presentation.
//! PHP oracle: ap-admin.php remote_profile preprocessing and header branch.
//! Facts are private server projections, never request-supplied authority.
#[path="profile_header_cache.rs"]
pub(crate) mod cache;
#[path="profile_secondary.rs"]
mod secondary;
use anyhow::{bail,Context,Result};
use serde_json::{json,Value};
use std::collections::HashMap;
use tokio_postgres::Client;
use crate::library::{actions::{self,ModerationContext},card,facts::{self,PresentationSession}};
use crate::profile_context::{AuthenticatedProfileSession,GuestProfileSession};
fn s<'a>(v:&'a Value,k:&str)->&'a str{v.get(k).and_then(Value::as_str).unwrap_or("")}
fn h(v:&str)->String{actions::esc(v)}
fn q(v:&str)->String{actions::query(v)}
fn form_query(v:&str)->String{q(v).replace("%20","+").replace('~',"%7E")}
#[derive(Clone,Default)]
pub(crate) struct HeaderRequest{pub(crate) tab:String,pub(crate) from:String,pub(crate) post:String,pub(crate) page:i64}
const PROFILE_BLOG_PAGE:i64=40;
const PROFILE_BLOG_PAGE_SQL:&str="SELECT to_jsonb(b) FROM vaak_blog_posts b WHERE actor_key=$1 AND status='published' ORDER BY COALESCE(published_at,updated_at) DESC,id DESC LIMIT 40 OFFSET $2";
fn blog_list_offset(page:i64)->i64{(page.max(1)-1).saturating_mul(PROFILE_BLOG_PAGE)}
#[derive(Clone,Default)]
struct HeaderCounts{posts:i64,replies:i64,boosts:i64,media:i64,asks:i64,pinned:i64,featured:i64,blog:i64,following:i64,followers:i64}
pub(crate) struct HeaderFacts{
 actor:String,key:String,profile:Value,current_profile:Value,owner:i64,owner_actor:String,csrf:String,guest:bool,is_own:bool,
 requested_tab:String,effective_tab:String,from:String,blog_slug:String,tab_href:String,
 following:bool,follows_you:bool,requested:bool,locked:bool,post_subscription:bool,
 moderation:ModerationContext,counts:HeaderCounts,name:String,bio:String,avatar:Option<String>,banner:Option<String>,fallback_avatar:String,
 emoji:HashMap<String,String>,fields:Vec<Value>,badges:Vec<String>,sl:Option<Value>,wow:Option<Value>,bridge:Option<Value>,bsky_handle:String,
 pin_rows:Vec<Value>,featured_rows:Vec<Value>,blog_rows:Vec<Value>,blog_post:Option<Value>,blog_offset:i64,
 dependencies:Value,
}
impl HeaderFacts{
 pub(crate) fn actor(&self)->&str{&self.actor}
 pub(crate) fn actor_key(&self)->&str{&self.key}
 pub(crate) fn effective_tab(&self)->&str{&self.effective_tab}
 pub(crate) fn timeline_tab_total(&self, tab:&str)->i64{match tab{"replies"=>self.counts.replies,"boosts"=>self.counts.boosts,"media"=>self.counts.media,"posts"=>self.counts.posts,_=>0}}
 pub(crate) fn requested_tab(&self)->&str{&self.requested_tab}
 pub(crate) fn raw_skip_timeline_preload(&self)->bool{matches!(self.requested_tab.as_str(),"posts"|"replies"|"boosts"|"media")}
 pub(crate) fn blog_slug(&self)->&str{&self.blog_slug}
 pub(crate) fn tab_href(&self)->&str{&self.tab_href}
 pub(crate) fn profile_snapshot(&self)->&Value{&self.current_profile}
 pub(crate) fn pin_rows(&self)->&[Value]{&self.pin_rows}
 pub(crate) fn featured_rows(&self)->&[Value]{&self.featured_rows}
 pub(crate) fn apply_featured_presentation_count(&mut self,count:usize)->Result<()>{if self.requested_tab!="featured"||count>40{bail!("invalid native featured count projection")}self.counts.featured=count as i64;self.effective_tab=if count==0{"posts"}else{"featured"}.into();Ok(())}
 pub(crate) fn blog_rows(&self)->&[Value]{&self.blog_rows}
 pub(crate) fn blog_post(&self)->Option<&Value>{self.blog_post.as_ref()}
}
fn selection(request:&HeaderRequest,profile:&Value,counts:&HeaderCounts,has_blog_post:bool)->(String,String,String,String){
 let requested=card::php_trim(&request.tab).to_ascii_lowercase();let requested=if requested.is_empty(){"posts".into()}else{requested};
 let mut tab=requested.clone();
 if !matches!(tab.as_str(),"pinned"|"posts"|"replies"|"boosts"|"media"|"asks"|"featured"|"blog")
  ||(tab=="replies"&&card::boolean(profile,"hide_profile_replies"))||(tab=="boosts"&&card::boolean(profile,"hide_profile_boosts"))
  ||(tab=="pinned"&&counts.pinned<1)||(tab=="featured"&&counts.featured<1)||(tab=="blog"&&counts.blog<1&&!has_blog_post){tab="posts".into();}
 let from=request.from.chars().filter(|c|c.is_ascii_lowercase()||*c=='_').collect();
 let slug=card::php_trim(&request.post).to_ascii_lowercase().chars().filter(|c|c.is_ascii_lowercase()||c.is_ascii_digit()||matches!(c,'_'|'-')).collect();
 (requested,tab,from,slug)
}
async fn exists_table(db:&Client,table:&str)->Result<bool>{Ok(db.query_one("SELECT to_regclass($1)::text IS NOT NULL",&[&table]).await?.get(0))}
async fn optional_rows(db:&Client,table:&str,sql:&str,args:&[&(dyn tokio_postgres::types::ToSql+Sync)])->Result<Vec<Value>>{
 if !exists_table(db,table).await?{return Ok(vec![])}Ok(db.query(sql,args).await?.iter().map(|r|r.get(0)).collect())
}
async fn supporter_awarded(db:&Client,key:&str)->Result<bool>{
 if !exists_table(db,"ap_supporter_codes").await?{return Ok(false)}
 Ok(db.query_one("SELECT EXISTS(SELECT 1 FROM ap_supporter_codes c JOIN ap_users u ON u.id=c.used_by WHERE u.actor_key=$1 AND u.disabled_at IS NULL AND c.used_at IS NOT NULL)",&[&key]).await?.get(0))
}
async fn authorized_badges(db:&Client,key:&str,raw:&Value)->Result<Vec<String>>{
 let mut badges=normalize_selected_badges(raw,false);
 let admin:bool=db.query_one("SELECT EXISTS(SELECT 1 FROM ap_users WHERE actor_key=$1 AND is_admin<>0 AND disabled_at IS NULL)",&[&key]).await?.get(0);
 if admin{badges.insert(0,"admin".into());}
 if supporter_awarded(db,key).await?{badges.insert(0,"supporter".into());}Ok(badges)
}
async fn header_dependencies(db:&Client,key:&str,actor:&str,owner:i64,viewer:&str,target:i64,blocked:bool)->Result<Value>{
 let aliases=vec![actor.trim_end_matches('/').to_owned(),format!("{}/",actor.trim_end_matches('/')),viewer.trim_end_matches('/').to_owned(),format!("{}/",viewer.trim_end_matches('/'))];
 let owners=vec![owner.to_string(),target.to_string()];let mut result=serde_json::Map::new();
 for (table,condition) in [
  ("ap_users","j->>'id'=ANY($2) OR lower(j->>'actor_key')=$3 OR lower(j->>'username')=$3"),
  ("following","j->>'owner_actor_id'=ANY($1)"),("followers","j->>'owner_actor_id'=ANY($1)"),
  ("ap_follow_requests","j->>'owner_actor_id'=ANY($1) OR j->>'actor_id'=ANY($1)"),
  ("ap_post_subscriptions","j->>'owner_user_id'=ANY($2)"),("ap_user_blocks","j->>'owner_user_id'=ANY($2)"),
  ("ap_mutes","j->>'owner_user_id'=ANY($2)"),("ap_deprioritized_actors","j->>'owner_user_id'=ANY($2)"),
  ("remote_custom_emojis","j->>'host'='mkultra.monster'"),
  ("ap_supporter_codes","j->>'used_by'=ANY($2) AND j->>'used_at' IS NOT NULL"),
  ("ap_sl_links","j->>'owner_user_id'=ANY($2)"),("ap_wow_links","j->>'owner_user_id'=ANY($2)"),
  ("phyrian_bridge_links","j->>'owner_user_id'=ANY($2)"),("bsky_sessions","j->>'owner_user_id'=ANY($2) OR j->>'owner_user_id' IN(SELECT id::text FROM ap_users WHERE lower(actor_key)=$3 OR lower(username)=$3)"),
  ("bsky_graph_sync","(j->>'owner_user_id'=ANY($2) OR j->>'owner_user_id' IN(SELECT id::text FROM ap_users WHERE lower(actor_key)=$3 OR lower(username)=$3)) AND j->>'kind'='meta' AND j->>'target_did'='graph_sync_v1'"),
 ]{
  if blocked&&!matches!(table,"ap_users"|"ap_user_blocks"|"ap_mutes"|"ap_deprioritized_actors"){continue}
  if !exists_table(db,table).await?{result.insert(table.into(),Value::Null);continue;}
  // Bind both arrays explicitly even where a scoped predicate needs only one.
  // The grant dependency remains the complete current row digest. Restrict
  // conversion to the same two account IDs first so its existing partial
  // used_by index can avoid scanning every issued reward. Preserve the bound
  // text-array contract used by the other dependency predicates.
  let scoped=if table=="ap_supporter_codes"{" WHERE used_by=ANY($2::text[]::bigint[]) AND used_at IS NOT NULL"}else{""};
  let sql=format!("SELECT jsonb_build_object('count',COUNT(*),'digest',md5(COALESCE(string_agg(j::text,'' ORDER BY j::text),''))) FROM (SELECT to_jsonb(t) j FROM {table} t{scoped}) q WHERE ($1::text[] IS NOT NULL AND $2::text[] IS NOT NULL AND $3::text IS NOT NULL) AND ({condition})");
  result.insert(table.into(),db.query_one(&sql,&[&aliases,&owners,&key]).await?.get(0));
 }
 result.insert("key".into(),json!(key));Ok(Value::Object(result))
}
pub(crate) async fn resolve_authenticated(db:&Client,session:&AuthenticatedProfileSession,request:&HeaderRequest)->Result<HeaderFacts>{
 let owner=session.owner_id();if owner<1||session.csrf().is_empty(){bail!("authenticated header session required")}
 let row=db.query_opt("SELECT actor_id,COALESCE(is_admin,0)::bigint FROM ap_users WHERE id::bigint=$1 AND disabled_at IS NULL",&[&owner]).await?.context("inactive header owner")?;
 resolve_local(db,session.actor_key(),session.source_actor(),owner,row.get::<_,String>(0),row.get::<_,i64>(1)!=0,session.csrf(),false,request,session.warm_context()).await
}
pub(crate) async fn resolve_guest(db:&Client,session:&GuestProfileSession,request:&HeaderRequest)->Result<HeaderFacts>{
 resolve_local(db,session.actor_key(),session.source_actor(),0,String::new(),false,"",true,request,session.warm_context()).await
}
async fn resolve_local(db:&Client,key:&str,actor:&str,owner:i64,owner_actor:String,is_admin:bool,csrf:&str,guest:bool,request:&HeaderRequest,warm:Option<&crate::profile_enrichment::WarmContext>)->Result<HeaderFacts>{
 let target=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE lower(actor_key)=$1 LIMIT 1",&[&key]).await?.map(|r|r.get::<_,Value>(0)).unwrap_or(Value::Null);
 let target_id=card::integer(&target,"id");let canonical=format!("https://mkultra.monster/users/{key}");let slash=format!("{actor}/");
 let current_profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&key]).await?.map(|r|r.get::<_,Value>(0)).unwrap_or(Value::Null);
 let dependencies=header_dependencies(db,key,actor,owner,&owner_actor,target_id,false).await?;
 let profile=cache::profile(warm.map(|w|w.cfg()),key,&current_profile).await;
 let is_own=actor.trim_end_matches('/')==owner_actor.trim_end_matches('/')&&!guest;
 let cfg=warm.map(|w|w.cfg());let mut counts=HeaderCounts::default();let prefix=format!("{canonical}/%");
 let total=cache::count(db,cfg,&format!("vaak:profile:outbox-count:v2:{key}"),60,"SELECT COUNT(*) FROM outbox_notes WHERE id LIKE $1 AND COALESCE(visibility, 'public') = 'public'",&[&prefix]).await?;
 if total>0{counts.replies=cache::count(db,cfg,&format!("vaak:profile:reply-count:v2:{key}"),60,"SELECT COUNT(*) FROM outbox_notes WHERE id LIKE $1 AND COALESCE(visibility, 'public') = 'public' AND TRIM(COALESCE(in_reply_to, '')) <> ''",&[&prefix]).await?;}
 counts.posts=(total-counts.replies).max(0);counts.media=cache::count(db,cfg,&format!("vaak:profile:media-count:v2:{key}"),60,"SELECT COUNT(*) FROM outbox_notes WHERE id LIKE $1 AND COALESCE(visibility, 'public') = 'public' AND raw_create_json LIKE '%\"attachment\"%'",&[&prefix]).await?;
 if target_id>0&&!card::boolean(&profile,"hide_profile_boosts"){counts.boosts=cache::count(db,cfg,&format!("vaak:profile:boost-count:v1:{target_id}"),60,"SELECT COUNT(*) FROM masto_reblogs WHERE owner_user_id::bigint=$1",&[&target_id]).await?;}
 let pin_rows=db.query("SELECT to_jsonb(s) FROM masto_pins p INNER JOIN masto_statuses s ON s.local_id=p.status_local_id WHERE p.owner_user_id::bigint=$1 AND s.note_id LIKE $2 ORDER BY p.pinned_at DESC LIMIT 5",&[&target_id,&format!("{canonical}/notes/%")]).await?.iter().map(|r|r.get::<_,Value>(0)).collect::<Vec<_>>();counts.pinned=pin_rows.len()as i64;
 counts.asks=db.query_one("SELECT COUNT(*) FROM ap_asks WHERE owner_user_id::bigint=$1 AND answered::bigint=1 AND answer_note_id IS NOT NULL AND answer_note_id<>''",&[&target_id]).await?.get(0);
 let featured_rows=optional_rows(db,"ap_featured_accounts","SELECT to_jsonb(f) FROM ap_featured_accounts f WHERE owner_user_id::bigint=$1 ORDER BY position ASC,id ASC LIMIT 40",&[&target_id]).await?;counts.featured=featured_rows.len()as i64;
 let blog_table=exists_table(db,"vaak_blog_posts").await?;counts.blog=if blog_table{cache::count(db,cfg,&format!("vaak:profile:blog-count:v1:{key}:published"),60,"SELECT COUNT(*) FROM vaak_blog_posts WHERE actor_key=$1 AND status='published'",&[&key]).await?}else{0};
 let raw_slug=card::php_trim(&request.post).to_ascii_lowercase().chars().filter(|c|c.is_ascii_lowercase()||c.is_ascii_digit()||matches!(c,'_'|'-')).collect::<String>();
 let blog_post=if raw_slug.is_empty(){None}else{optional_rows(db,"vaak_blog_posts","SELECT to_jsonb(b) FROM vaak_blog_posts b WHERE actor_key=$1 AND slug=$2 AND status='published' LIMIT 1",&[&key,&raw_slug]).await?.into_iter().next()};
 let(requested_tab,effective_tab,from,blog_slug)=selection(request,&profile,&counts,blog_post.is_some());
 if effective_tab=="asks"{counts.asks=counts.asks.min(200);}
 let blog_offset=if requested_tab=="blog"&&blog_slug.is_empty(){blog_list_offset(request.page)}else{0};
 let blog_rows=if requested_tab=="blog"&&blog_slug.is_empty(){optional_rows(db,"vaak_blog_posts",PROFILE_BLOG_PAGE_SQL,&[&key,&blog_offset]).await?}else{vec![]};
 let mut following=false;let mut follows_you=false;let mut requested=false;let mut post_subscription=false;
 if !guest{
  let viewer=owner_actor.trim_end_matches('/');let viewers=vec![viewer.to_owned(),format!("{viewer}/")];let targets=vec![actor.to_owned(),slash];
  following=db.query_one("SELECT EXISTS(SELECT 1 FROM following WHERE owner_actor_id=ANY($1) AND actor_id=ANY($2))",&[&viewers,&targets]).await?.get(0);
  follows_you=db.query_one("SELECT EXISTS(SELECT 1 FROM followers WHERE owner_actor_id=ANY($1) AND actor_id=ANY($2))",&[&viewers,&targets]).await?.get(0);
  if !following&&exists_table(db,"ap_follow_requests").await?{requested=db.query_one("SELECT EXISTS(SELECT 1 FROM ap_follow_requests WHERE owner_actor_id=ANY($1) AND actor_id=ANY($2) AND status='pending')",&[&targets,&viewers]).await?.get(0);}
  if !is_own&&exists_table(db,"ap_post_subscriptions").await?{post_subscription=db.query_one("SELECT EXISTS(SELECT 1 FROM ap_post_subscriptions WHERE owner_user_id::bigint=$1 AND target_actor_id=ANY($2))",&[&owner,&targets]).await?.get(0);}
 }
 let targets=vec![canonical.clone(),format!("{canonical}/")];
 counts.following=cache::count(db,cfg,&cache::graph_key("following",&canonical),cache::graph_ttl(),"SELECT COUNT(*) FROM following WHERE owner_actor_id=ANY($1)",&[&targets]).await?;counts.followers=cache::count(db,cfg,&cache::graph_key("followers",&canonical),cache::graph_ttl(),"SELECT COUNT(*) FROM followers WHERE owner_actor_id=ANY($1)",&[&targets]).await?;
 let mut moderation=ModerationContext{is_self:is_own,is_local:true,is_local_admin:is_own||card::boolean(&target,"is_admin"),is_admin,..Default::default()};
 if !guest{
  let rows=db.query("SELECT to_jsonb(b) FROM ap_user_blocks b WHERE owner_user_id::bigint=$1 ORDER BY created_at DESC,id DESC",&[&owner]).await?.iter().map(|r|r.get::<_,Value>(0)).collect::<Vec<_>>();moderation.personal_block_id=facts::personal_block(&rows,actor).map(|r|card::integer(r,"id"));
  for(table,target)in[("ap_mutes",&mut moderation.muted),("ap_deprioritized_actors",&mut moderation.deprioritized)]{*target=db.query(&format!("SELECT actor_id FROM {table} WHERE owner_user_id::bigint=$1"),&[&owner]).await?.iter().any(|r|{let a:String=r.get(0);if table=="ap_mutes"{crate::hidden::actor_aliases(&a).iter().any(|a|crate::hidden::actor_aliases(actor).contains(a))}else{a.trim_end_matches('/')==actor.trim_end_matches('/')}});}
 }
 let mut name=card::php_trim(s(&profile,"name")).to_owned();if name.is_empty(){name=key.into();}if card::boolean(&profile,"vanity_verified"){name.push_str(" ✓");}if card::boolean(&profile,"automated"){name.push_str(" 🤖");}
 let avatar=sanitized_url(s(&profile,"icon_url"));let banner=sanitized_url(s(&profile,"image_url")).or_else(||avatar.clone());
 let fields=profile["attachment"].as_array().cloned().unwrap_or_default();
 let badges=authorized_badges(db,key,&profile["profile_badges"]).await?;
 let mut emoji=HashMap::new();for row in db.query("SELECT shortcode,url FROM remote_custom_emojis WHERE host='mkultra.monster'",&[]).await?{let k:String=row.get(0);let u:String=row.get(1);if !k.is_empty()&&u.starts_with("https://"){emoji.insert(k,u);}}
 let mut links=Vec::new();for(table,alias,extra)in[("ap_sl_links","l",""),("ap_wow_links","l",""),("phyrian_bridge_links","l"," AND l.status='verified' AND l.unlinked_at IS NULL")]{let sql=format!("SELECT to_jsonb({alias}) FROM {table} {alias} INNER JOIN ap_users u ON u.id=l.owner_user_id WHERE u.actor_key=$1 AND u.disabled_at IS NULL{extra} LIMIT 1");links.push(optional_rows(db,table,&sql,&[&key]).await?.into_iter().next());}
 let tab_href=format!("/vaak/users/{}{}tab=",q(key),if from.is_empty(){"?".into()}else{format!("?from={}&",form_query(&from))});
 let combined=cache::combined(db,key,counts.followers,counts.following,warm.is_some()).await?;counts.followers=combined.followers;counts.following=combined.following;
 let locked=card::boolean(&profile,"manually_approves");let bio=crate::library::body::html_to_plain(s(&profile,"summary"));
 let fallback_avatar=facts::avatar(db,&Value::Null,actor).await?;
 anyhow::ensure!(dependencies==header_dependencies(db,key,actor,owner,&owner_actor,target_id,false).await?,"header dependencies changed during enrichment");
 Ok(HeaderFacts{dependencies,actor:actor.into(),key:key.into(),profile,current_profile,owner,owner_actor,csrf:csrf.into(),guest,is_own,requested_tab,effective_tab,from,blog_slug,tab_href,following,follows_you,requested,locked,post_subscription,moderation,counts,name,bio,avatar,banner,fallback_avatar,emoji,fields,badges,sl:links[0].take(),wow:links[1].take(),bridge:links[2].take(),bsky_handle:combined.handle,pin_rows,featured_rows,blog_rows,blog_post,blog_offset})
}
pub(crate) struct BlockedHeaderFacts{actions:HeaderFacts,personal:bool,server:bool}
impl BlockedHeaderFacts{pub(crate) async fn require_actions_current(&self,db:&Client)->Result<()>{self.actions.require_secondary_current(db).await}}
pub(crate) async fn resolve_blocked_authenticated(db:&Client,session:&AuthenticatedProfileSession,request:&HeaderRequest)->Result<BlockedHeaderFacts>{
 let owner=session.owner_id();if owner<1||session.csrf().is_empty(){bail!("blocked authenticated identity required")}
 let row=db.query_opt("SELECT actor_id,COALESCE(is_admin,0)::bigint FROM ap_users WHERE id::bigint=$1 AND disabled_at IS NULL",&[&owner]).await?.context("inactive blocked header owner")?;
 resolve_blocked_local(db,session.actor_key(),session.source_actor(),owner,row.get(0),row.get::<_,i64>(1)!=0,session.csrf(),false,request).await
}
pub(crate) async fn resolve_blocked_guest(db:&Client,session:&GuestProfileSession,request:&HeaderRequest)->Result<BlockedHeaderFacts>{resolve_blocked_local(db,session.actor_key(),session.source_actor(),0,String::new(),false,"",true,request).await}
async fn resolve_blocked_local(db:&Client,key:&str,actor:&str,owner:i64,owner_actor:String,is_admin:bool,csrf:&str,guest:bool,request:&HeaderRequest)->Result<BlockedHeaderFacts>{
 let target=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE lower(actor_key)=$1 LIMIT 1",&[&key]).await?.map(|r|r.get::<_,Value>(0)).unwrap_or(Value::Null);
 let dependencies=header_dependencies(db,key,actor,owner,&owner_actor,card::integer(&target,"id"),true).await?;
 let personal_rows=db.query("SELECT to_jsonb(b) FROM ap_user_blocks b WHERE owner_user_id::bigint=$1 ORDER BY created_at DESC,id DESC",&[&owner]).await?.iter().map(|r|r.get::<_,Value>(0)).collect::<Vec<_>>();
 let personal_row=facts::personal_block(&personal_rows,actor);let personal=personal_row.is_some();
 let server_rows=db.query("SELECT to_jsonb(b) FROM ap_blocks b WHERE COALESCE(kind,'block')<>'mute'",&[]).await?.iter().map(|r|r.get::<_,Value>(0)).collect::<Vec<_>>();let server=facts::personal_block(&server_rows,actor).is_some();if !personal&&!server{bail!("current denied root required")}
 let is_own=!guest&&actor.trim_end_matches('/')==owner_actor.trim_end_matches('/');let mut moderation=ModerationContext{is_self:is_own,is_local:true,is_local_admin:is_own||card::boolean(&target,"is_admin"),is_admin,personal_block_id:personal_row.map(|r|card::integer(r,"id")),..Default::default()};
 if !guest{for(table,flag)in[("ap_mutes",&mut moderation.muted),("ap_deprioritized_actors",&mut moderation.deprioritized)]{*flag=db.query(&format!("SELECT actor_id FROM {table} WHERE owner_user_id::bigint=$1"),&[&owner]).await?.iter().any(|r|{let a:String=r.get(0);if table=="ap_mutes"{crate::hidden::actor_aliases(&a).iter().any(|a|crate::hidden::actor_aliases(actor).contains(a))}else{a.trim_end_matches('/')==actor.trim_end_matches('/')}});}}
 let from=request.from.chars().filter(|c|c.is_ascii_lowercase()||*c=='_').collect();
 // Cache-only identity; denied roots never enter profile/emoji/count/preview or warming helpers.
 let avatar=facts::avatar(db,&Value::Null,actor).await?;
 anyhow::ensure!(dependencies==header_dependencies(db,key,actor,owner,&owner_actor,card::integer(&target,"id"),true).await?,"blocked action dependencies changed during presentation");
 let actions=HeaderFacts{dependencies,actor:actor.into(),key:key.into(),profile:Value::Null,current_profile:Value::Null,owner,owner_actor,csrf:csrf.into(),guest,is_own,requested_tab:String::new(),effective_tab:String::new(),from,blog_slug:String::new(),tab_href:String::new(),following:false,follows_you:false,requested:false,locked:false,post_subscription:false,moderation,counts:HeaderCounts::default(),name:String::new(),bio:String::new(),avatar:None,banner:None,fallback_avatar:avatar,emoji:HashMap::new(),fields:vec![],badges:vec![],sl:None,wow:None,bridge:None,bsky_handle:String::new(),pin_rows:vec![],featured_rows:vec![],blog_rows:vec![],blog_post:None,blog_offset:0};
 Ok(BlockedHeaderFacts{actions,personal,server})
}
pub(crate) fn render_blocked(token:&crate::profile_context::AdmittedBlockedProfileHeader)->Result<String>{render_blocked_core(token.facts())}
fn render_blocked_core(blocked:&BlockedHeaderFacts)->Result<String>{let f=&blocked.actions;let handle=format!("@{}@mkultra.monster",f.key);let mut out=String::new();if !f.guest{let(view,label,soft)=back(&f.from);out.push_str(&format!("<div class=\"page-back\"><a class=\"btn btn-ghost\" href=\"?view={view}\"{}>{}</a><a class=\"btn btn-ghost\" href=\"?view=remote_profile&amp;actor={}&amp;from={}\" title=\"Reload local profile\">↻ Refresh</a></div>",if soft.is_empty(){String::new()}else{format!(" data-vaak-soft-nav=\"{soft}\"")},h(label),form_query(&f.actor),form_query(&f.from)));}
 out.push_str(&format!("<article class=\"tweet\"><div class=\"tweet-hd\" style=\"align-items:center;gap:.75rem\"><img class=\"tweet-av\" src=\"{}\" alt=\"\" width=\"40\" height=\"40\" data-profile-hover-actor=\"{}\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\" onerror=\"this.onerror=null;this.src='https://mkultra.monster/img/avatar/default.webp'\"><div><div class=\"who\">{}</div><div class=\"meta\">{}</div></div></div><div class=\"tweet-actions\" style=\"flex-wrap:wrap;align-items:center;margin-top:.75rem\">",h(&f.fallback_avatar),h(&f.actor),h(&handle),h(&handle)));
 if blocked.personal{out.push_str("<span class=\"tag\" style=\"color:var(--danger)\">blocked for me</span>");}if blocked.server{out.push_str("<span class=\"tag\" style=\"color:var(--danger)\">blocked server-wide</span>");}out.push_str(&local_menu(f));out.push_str("</div><div class=\"empty\" style=\"margin-top:1rem\">");out.push_str(if blocked.server&&!blocked.personal{"This account is <b>blocked server-wide</b>. Their profile and posts are hidden for everyone on VAAK."}else{"You blocked this account. Their profile and posts are hidden for you.\n                Use ⋯ → Unblock for me if you want to see them again."});out.push_str("</div></article>");Ok(out)
}
pub(crate) fn render_blog(token:&crate::profile_context::AdmittedProfileHeader)->Result<String>{secondary::blog(token.facts())}
impl HeaderFacts{
 pub(crate) async fn require_secondary_current(&self,db:&Client)->Result<()>{
  let target=self.current_target_snapshot(db).await?;
  anyhow::ensure!(self.dependencies==header_dependencies(db,&self.key,&self.actor,self.owner,&self.owner_actor,card::integer(&target,"id"),self.requested_tab.is_empty()).await?,"header durable dependencies changed before publication");
  if self.requested_tab=="blog"&&self.blog_slug.is_empty(){let rows=optional_rows(db,"vaak_blog_posts",PROFILE_BLOG_PAGE_SQL,&[&self.key,&self.blog_offset]).await?;if rows!=self.blog_rows{bail!("published blog selection changed before publication")}}
  if !self.blog_slug.is_empty(){let row=optional_rows(db,"vaak_blog_posts","SELECT to_jsonb(b) FROM vaak_blog_posts b WHERE actor_key=$1 AND slug=$2 AND status='published' LIMIT 1",&[&self.key,&self.blog_slug]).await?.into_iter().next();if row!=self.blog_post{bail!("published blog detail changed before publication")}}
  if self.requested_tab=="featured"{let rows=optional_rows(db,"ap_featured_accounts","SELECT to_jsonb(f) FROM ap_featured_accounts f WHERE owner_user_id::bigint=$1 ORDER BY position ASC,id ASC LIMIT 40",&[&card::integer(&self.current_target_snapshot(db).await?,"id")]).await?;if rows!=self.featured_rows{bail!("featured selection changed before publication")}}
  Ok(())
 }
 async fn current_target_snapshot(&self,db:&Client)->Result<Value>{Ok(db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE lower(actor_key)=$1 LIMIT 1",&[&self.key]).await?.map(|r|r.get::<_,Value>(0)).unwrap_or(Value::Null))}
}
fn profile_defaults(key:&str)->Value{
 let summary=if key=="cmdr_nova"{"<p>Local ActivityPub presence for mkultra.monster.</p><p>VAAK profile: <a href=\"https://mkultra.monster/users/cmdr_nova\" rel=\"nofollow noopener noreferrer\">@cmdr_nova@mkultra.monster</a>. Site bridge handle: <code>@val3r1e@mkultra.monster</code> (Bridgy Fed).</p>"}else{"<p>On mkultra.monster.</p>"};
 let mut attachment=vec![];if key=="cmdr_nova"{attachment.push(json!({"type":"PropertyValue","name":"Also","value":"<a href=\"https://mkultra.monster/users/cmdr_nova\" rel=\"me\">@cmdr_nova@mkultra.monster</a>"}));}attachment.push(json!({"type":"PropertyValue","name":"Site","value":"<a href=\"https://mkultra.monster/\" rel=\"me\">mkultra.monster</a>"}));
 json!({"actor_key":key,"name":key,"summary":summary,"attachment":attachment,"icon_url":null,"image_url":null,"manually_approves":false,"discoverable":true,"indexable":true,"collection_consent":true,"vanity_verified":false,"auto_follow_back":false,"anti_ai_marker":false,"auto_unblur_sensitive":false,"auto_delete_posts_7d":false,"automated":false,"reply_policy":"anyone","quote_policy":"anyone","forum_signature":"","profile_badges":[],"hide_profile_replies":false,"hide_profile_boosts":false,"algorithm_enabled":true,"downranking_enabled":true,"asks_enabled":true,"webmentions_enabled":true,"updated_at":null})
}
fn sanitized_url(raw:&str)->Option<String>{crate::profile_identity::sanitized_profile_url(raw)}
fn normalize_badges(value:&Value)->Vec<String>{normalize_selected_badges(value,true)}
pub(crate) fn normalize_selected_badges(value:&Value,reserved:bool)->Vec<String>{let decoded=if let Some(raw)=value.as_str(){serde_json::from_str::<Value>(raw).unwrap_or(Value::Null)}else{value.clone()};let mut result=Vec::new();if reserved&&decoded.as_array().is_some_and(|v|v.iter().any(|b|b.as_str().is_some_and(|s|card::php_trim(s)=="supporter"))){result.push("supporter".to_string());}if reserved&&decoded.as_array().is_some_and(|v|v.iter().any(|b|b.as_str().is_some_and(|s|card::php_trim(s)=="admin"))){result.push("admin".to_string());}let limit=6+result.len();for v in decoded.as_array().into_iter().flatten(){let k=card::php_trim(v.as_str().unwrap_or(""));if (reserved||!matches!(k,"admin"|"supporter"))&&BADGES.iter().any(|b|b.0==k)&&!result.iter().any(|v|v==k){result.push(k.into());}if result.len()==limit{break;}}result}
fn hidden(name:&str,value:&str)->String{format!("<input type=\"hidden\" name=\"{name}\" value=\"{}\">",h(value))}
fn post_form(f:&HeaderFacts,action:&str,extra:&str,attrs:&str,button:&str)->String{
 format!("<form method=\"post\" action=\"?view=remote_profile&amp;actor={}&amp;from={}\" style=\"display:inline\"{attrs}>{}{}{}{}{}{extra}{button}</form>",form_query(&f.actor),form_query(&f.from),hidden("action",action),hidden("return_view","remote_profile"),hidden("return_actor",&f.actor),hidden("return_from",&f.from),if action=="bite_remote"{String::new()}else{hidden("actor_id",&f.actor)})
}
fn menu_form(f:&HeaderFacts,action:&str,field:&str,value:&str,label:&str,title:&str)->String{
 format!("<form method=\"post\" action=\"?view=remote_profile\">{}{}{}{}{}{}<button class=\"menu-action\" type=\"submit\"{}>{label}</button></form>",hidden("csrf",&f.csrf),hidden("action",action),hidden("return_view","remote_profile"),hidden("return_from",&f.from),hidden("return_actor",&f.actor),hidden(field,value),if title.is_empty(){String::new()}else{format!(" title=\"{}\"",h(title))})
}
fn local_menu(f:&HeaderFacts)->String{
 if f.guest||f.is_own{return String::new();}let mut menu=String::new();let m=&f.moderation;
 if m.personal_block_id.is_none(){menu.push_str(&format!("<a class=\"menu-action\" href=\"?view=dms&amp;peer={}\">DM</a>",q(&f.actor)));}
 let from=if f.from.is_empty(){"remote_profile"}else{&f.from};menu.push_str(&format!("<a class=\"menu-action\" href=\"?view=ask&amp;target_actor={}&amp;from={}\">Ask</a>",q(&f.actor),q(from)));
 if m.is_local_admin{menu.push_str("<span class=\"menu-action menu-action-disabled\" aria-disabled=\"true\" title=\"Administrators cannot be blocked\">Admin account — cannot be blocked</span>");}
 else if let Some(id)=m.personal_block_id{menu.push_str(&menu_form(f,"user_block_remove","id",&id.to_string(),"Unblock","Hide from your timelines only"));}
 else{menu.push_str(&menu_form(f,"user_block_add","target",&f.actor,"Block","Hide from your timelines only"));}
 menu.push_str(&menu_form(f,if m.muted{"unmute_remote"}else{"mute_remote"},"actor_id",&f.actor,if m.muted{"Unmute"}else{"Mute"},"Hide from your Home / Federated / Notifications"));
 menu.push_str(&menu_form(f,if m.deprioritized{"undeprioritize_remote"}else{"deprioritize_remote"},"actor_id",&f.actor,if m.deprioritized{"Stop deprioritizing"}else{"Deprioritize on Home"},""));
 menu.push_str(&format!("<a class=\"menu-action\" href=\"?view=report&amp;report_target={}&amp;from={}\">Report user</a>",q(&f.actor),q(from)));
 if m.is_admin{menu.push_str(&format!("<div class=\"menu-action-sep\" role=\"separator\" aria-hidden=\"true\"></div><a class=\"menu-action\" href=\"/vaak/?view=moderation&amp;actor={}&amp;from=remote_profile\">Open in moderation panel</a>",q(&f.actor)));}
 format!("<details class=\"post-action-menu\"><summary class=\"icon-btn\" title=\"More actions\" aria-label=\"More actions\">⋯</summary><div class=\"post-action-menu__body\">{menu}</div></details>")
}
fn back(from:&str)->(&str,&str,&str){match from{"mentions"=>("mentions","← Notifications","mentions"),"search"=>("search","← Search","search"),"followers"=>("followers","← Followers","followers"),"home"=>("home","← Home","home"),"feed"=>("feed","← Federated","feed"),"blocks"=>("blocks","← Blocks & mutes",""),"about"=>("about","← About VAAK",""),_=>("following","← Following","following")}}
fn guest_follow(f:&HeaderFacts)->String{
 let mut out=String::from("<div class=\"remote-profile-guest-follow\"><button type=\"button\" class=\"btn btn-primary\" id=\"rp-guest-follow-toggle\" aria-expanded=\"false\" aria-controls=\"rp-guest-follow-panel\">Follow</button><div class=\"guest-follow-panel\" id=\"rp-guest-follow-panel\" hidden><form id=\"rp-guest-follow-form\" action=\"#\" method=\"get\"><label for=\"rp-guest-follow-handle\">Follow from your fediverse account</label><div class=\"guest-follow-row\"><input id=\"rp-guest-follow-handle\" name=\"handle\" type=\"text\" inputmode=\"email\" autocomplete=\"username\" spellcheck=\"false\" placeholder=\"@you@your.instance\" required><button type=\"submit\">Go</button></div><p class=\"guest-follow-hint\" id=\"rp-guest-follow-hint\">Opens your instance’s follow dialog (Mastodon, Akkoma, GoToSocial, etc.).</p></form>");
 if !f.bsky_handle.is_empty(){out.push_str(&format!("<form class=\"guest-follow-bsky\" action=\"https://bsky.app/profile/{}\" method=\"get\" target=\"_blank\" rel=\"noopener noreferrer\"><label for=\"rp-guest-bsky-handle\">Follow on Bluesky</label><div class=\"guest-follow-row\"><input id=\"rp-guest-bsky-handle\" type=\"text\" value=\"@{}\" readonly><button type=\"submit\">Open</button></div><p class=\"guest-follow-hint\">Opens this profile in Bluesky so you can follow it there.</p></form>",q(&f.bsky_handle),h(&f.bsky_handle)));}
 out.push_str("</div></div><script>");
 out.push_str(&include_str!("profile_guest_follow.js").replace("__NATIVE_ACTOR_JSON__",&serde_json::to_string(&format!("https://mkultra.monster/users/{}",f.key)).unwrap()));out.push_str("</script>");out
}
pub(crate) fn fields_html(fields:&[Value])->String{
 let mut rows=String::new();for field in fields{
  let name=card::php_trim(s(field,"name"));let raw=s(field,"value");
  if name.is_empty()||card::php_trim(&regex::Regex::new(r"(?s)<[^>]*>").unwrap().replace_all(raw,"")).is_empty(){continue;}
  let value=field_html(&crate::home_hydrate_ranked::html_entity_decode(raw));if value.is_empty(){continue;}
  let verified=if card::boolean(field,"verified_at"){s(field,"verified_at")}else{""};
  rows.push_str(&format!("<div class=\"remote-profile-field\"><span class=\"meta\" style=\"display:block;font-size:.75rem;margin:0 0 .15rem\">{}{} </span><div style=\"overflow:hidden;text-overflow:ellipsis;white-space:nowrap\">{value}</div></div>",h(name),if verified.is_empty(){String::new()}else{format!(" <span class=\"field-verified\" title=\"Verified via rel=me · {}\" aria-label=\"Verified\">✓</span>",h(verified))}));
 }if rows.is_empty(){String::new()}else{format!("<div class=\"remote-profile-fields\" aria-label=\"Profile fields\">{rows}</div>")}
}
pub(crate) fn parity_markdown(raw: &str) -> String { secondary::markdown(raw) }
pub(crate) fn parity_field_html(raw: &str) -> String { field_html(raw) }

fn field_html(input:&str)->String{
 // PHP strip_tags tracks attribute quotes; an unterminated quoted tag consumes the remaining input.
 let mut complete=input.len();let bytes=input.as_bytes();let mut pos=0;while pos<bytes.len(){if bytes[pos]!=b'<'{pos+=1;continue}let begin=pos;pos+=1;let mut quote=None;while pos<bytes.len(){let c=bytes[pos];if let Some(q)=quote{if c==q{quote=None}}else if c==b'\''||c==b'"'{quote=Some(c)}else if c==b'>'{break}pos+=1;}if pos==bytes.len(){complete=begin;break}pos+=1;}
 let input=&input[..complete];
 let comments=regex::Regex::new(r"(?s)<!--.*?-->").unwrap().replace_all(input,"");
 let tag=regex::Regex::new(r"(?is)<[^>]*>").unwrap();let mut out=tag.replace_all(&comments,|cap:&regex::Captures|{
  let Some(a)=regex::Regex::new(r"(?is)^<(\/?)a(?:\s([^>]*))?>$").unwrap().captures(&cap[0])else{return String::new();};
  if &a[1]=="/"{return "</a>".into();}let attrs=a.get(2).map(|v|v.as_str()).unwrap_or("");
  let href=regex::Regex::new(r#"(?i)href\s*=\s*(?:"(https?://[^"']+)"|'(https?://[^"']+)')"#).unwrap().captures(attrs).map(|c|c.get(1).or_else(||c.get(2)).unwrap().as_str().to_owned())
   .or_else(||regex::Regex::new(r"(?i)href\s*=\s*(https?://[^\s>]+)").unwrap().captures(attrs).map(|c|c[1].to_owned())).unwrap_or_default();
  let href=crate::home_hydrate_ranked::html_entity_decode(card::php_trim(&href));
  if href.to_ascii_lowercase().starts_with("https://"){format!("<a href=\"{}\" rel=\"nofollow noopener noreferrer\">",h(&href))}else{"<a>".into()}
 }).into_owned();
 out=regex::Regex::new(r"(?is)<a>(.*?)</a>").unwrap().replace_all(&out,"$1").into_owned();
 out.replace("rel=\"nofollow noopener noreferrer\">","rel=\"nofollow noopener noreferrer\" target=\"_blank\">")
}
fn world_html(f:&HeaderFacts)->String{
 let sl=f.sl.as_ref().filter(|v|card::boolean(v,"sl_username"));let wow=f.wow.as_ref().filter(|v|card::boolean(v,"armory_url"));if sl.is_none()&&wow.is_none(){return String::new();}
 let mut out=String::from("<div class=\"profile-world-links\" style=\"display:flex;gap:.5rem;align-items:center;margin:.65rem 0 0;flex-wrap:wrap\">");
 if let Some(sl)=sl{let name=s(sl,"sl_username");let agent=card::php_trim(s(sl,"sl_agent_id")).to_ascii_lowercase();let url=if regex::Regex::new(r"^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$").unwrap().is_match(&agent){format!("secondlife:///app/agent/{agent}/about")}else{"https://secondlife.com/".into()};out.push_str(&format!("<a href=\"{}\" rel=\"noopener noreferrer me\" title=\"Open {} in Second Life\" aria-label=\"Open {} in Second Life\"><img src=\"/vaak/second-life.svg\" alt=\"Second Life\" width=\"43\" height=\"34\" loading=\"lazy\"></a><span class=\"meta\">{}</span>",h(&url),h(name),h(name),h(name)));}
 if let Some(wow)=wow{let name=wow.get("character_name").and_then(Value::as_str).unwrap_or("World of Warcraft character");let realm=card::php_trim(s(wow,"realm"));let label=if realm.is_empty(){name.into()}else{format!("{name} · {realm}")};let portrait=card::php_trim(s(wow,"portrait_url"));let url=h(s(wow,"armory_url"));if portrait.is_empty(){out.push_str(&format!("<a class=\"btn btn-ghost\" href=\"{url}\" target=\"_blank\" rel=\"noopener noreferrer\">WoW Armory</a>"));}else{out.push_str(&format!("<a href=\"{url}\" target=\"_blank\" rel=\"noopener noreferrer\" title=\"Open {} Armory profile\" aria-label=\"Open {} Armory profile\"><img src=\"{}\" alt=\"World of Warcraft\" width=\"43\" height=\"34\" loading=\"lazy\" referrerpolicy=\"no-referrer\" style=\"object-fit:cover;border-radius:8px\"></a>",h(&label),h(&label),h(portrait)));}out.push_str(&format!("<span class=\"meta\">{}</span>",h(&label)));}
 out.push_str("</div>");out
}
fn tabs_html(f:&HeaderFacts)->String{
 let c=&f.counts;let tabs=[("pinned","Pinned",c.pinned,c.pinned>0),("posts","Posts",c.posts,true),("replies","Replies",c.replies,!card::boolean(&f.profile,"hide_profile_replies")),("boosts","Boosts",c.boosts,!card::boolean(&f.profile,"hide_profile_boosts")),("media","Media",c.media,true),("featured","Featured",c.featured,c.featured>0),("blog","Blog",c.blog,c.blog>0||f.blog_post.is_some())];
 let mut out=String::from("<nav class=\"remote-profile-tabs\" aria-label=\"Profile posts\"><button type=\"button\" class=\"remote-profile-tabs-scroll-btn\" data-profile-tabs-scroll=\"left\" aria-label=\"Scroll profile tabs left\">&lt;</button><div class=\"remote-profile-tabs-scroll\">");
 for(key,label,count,visible)in tabs{if visible{let active=f.effective_tab==key;out.push_str(&format!("<a href=\"{}{}\" class=\"{}\" {}>{label}<span class=\"tab-count\">{count}</span></a>",h(&f.tab_href),q(key),if active{"is-active"}else{""},if active{"aria-current=\"page\""}else{""}));}}
 out.push_str("</div><button type=\"button\" class=\"remote-profile-tabs-scroll-btn\" data-profile-tabs-scroll=\"right\" aria-label=\"Scroll profile tabs right\">&gt;</button></nav>");out
}
fn render_core(f:&HeaderFacts)->Result<String>{
 let mut out=String::new();if !f.guest{let(view,label,soft)=back(&f.from);out.push_str(&format!("<div class=\"page-back\"><a class=\"btn btn-ghost\" href=\"?view={view}\"{}>{}</a><a class=\"btn btn-ghost\" href=\"?view=remote_profile&amp;actor={}&amp;from={}\" title=\"Reload local profile\">↻ Refresh</a></div>",if soft.is_empty(){String::new()}else{format!(" data-vaak-soft-nav=\"{soft}\"")},h(label),form_query(&f.actor),form_query(&f.from)));}
 if let Some(banner)=&f.banner{out.push_str(&format!("<div style=\"height:120px;border-radius:12px;overflow:hidden;margin-bottom:.75rem;background:#111\"><img src=\"{}\" alt=\"\" style=\"width:100%;height:100%;object-fit:cover\" loading=\"lazy\" referrerpolicy=\"no-referrer\"></div>",h(banner)));}
 out.push_str("<article class=\"tweet\"><div class=\"tweet-hd remote-profile-hd\"><div class=\"remote-profile-hd-main\">");
 if let Some(avatar)=&f.avatar{out.push_str(&format!("<img src=\"{}\" alt=\"\" width=\"64\" height=\"64\" style=\"border-radius:50%;object-fit:cover\" loading=\"lazy\" referrerpolicy=\"no-referrer\" onerror=\"this.onerror=null;this.src='https://mkultra.monster/img/avatar/default.webp'\">",h(avatar)));}
 else{out.push_str(&format!("<img class=\"tweet-av\" src=\"{}\" alt=\"\" width=\"40\" height=\"40\" data-profile-hover-actor=\"{}\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\" onerror=\"this.onerror=null;this.src='https://mkultra.monster/img/avatar/default.webp'\">",h(&f.fallback_avatar),h(&f.actor)));}
 let fallback_name=format!("@{}@mkultra.monster",f.key);let display_name=if f.name.is_empty()||f.name=="0"{&fallback_name}else{&f.name};
 out.push_str(&format!("<div style=\"min-width:0\"><div class=\"who\">{}",crate::library::body::emojis(display_name,&f.emoji,true)));
 if let Some(bridge)=f.bridge.as_ref().filter(|v|card::boolean(v,"avatar_name")){let name=h(s(bridge,"avatar_name"));let strain=card::php_trim(s(bridge,"strain_snapshot"));let title=format!("Linked NovaLandia OpenSim avatar: {name}{}",if strain.is_empty(){String::new()}else{format!(" · {strain}")});out.push_str(&format!(" <span class=\"resonant-badge\" title=\"{}\" aria-label=\"Resonant — linked OpenSim avatar\">◈ Resonant</span>",h(&title)));}
 out.push_str(&format!("</div><div class=\"meta\">{} <span class=\"tag\" style=\"margin-left:.35rem\" title=\"Account on this instance\">local</span>",h(&format!("@{}@mkultra.monster",f.key))));
 if f.is_own{out.push_str("<span class=\"tag\" style=\"margin-left:.35rem\" title=\"Signed-in account\">you</span>");}out.push_str("</div>");if f.is_own{out.push_str("<div class=\"meta\" style=\"margin-top:.25rem\">This is you · <a href=\"/vaak/?view=profile\">Edit profile</a></div>");}out.push_str("</div></div></div>");
 if f.guest{out.push_str(&guest_follow(f));}
 out.push_str(&if f.bio.is_empty(){"<div class=\"meta\" style=\"margin-top:.75rem\">No bio set.</div>".into()}else{format!("<div class=\"body\" style=\"margin-top:.75rem;white-space:pre-wrap\">{}</div>",h(&f.bio))});out.push_str(&world_html(f));out.push_str(&fields_html(&f.fields));
 if !f.badges.is_empty(){out.push_str("<div class=\"profile-badges\" aria-label=\"Profile badges\" style=\"display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.65rem\">");for key in &f.badges{if let Some((_,label,emoji))=BADGES.iter().find(|b|b.0==key){out.push_str(&format!("<span class=\"tag\" title=\"{}\">{} {}</span>",h(label),h(emoji),h(label)));}}out.push_str("</div>");}
 let c=&f.counts;let title=if f.bsky_handle.is_empty(){""}else{" title=\"Includes ActivityPub and connected Bluesky counts\""};out.push_str(&format!("<div class=\"remote-profile-stats\" aria-label=\"Profile stats\"><div><span class=\"n\">{}</span><span class=\"l\">Posts</span></div>",c.posts));
 for(label,n,key)in[("Following",c.following,"following"),("Followers",c.followers,"followers")]{out.push_str(&format!("<a href=\"{}\"{}{title}><span class=\"n\">{n}</span><span class=\"l\">{label}</span></a>",if f.is_own{format!("?view={key}")}else{format!("/users/{}/{key}",q(&f.key))},if f.is_own{""}else{" target=\"_blank\" rel=\"noopener noreferrer\""}));}out.push_str("</div><div class=\"tweet-actions remote-profile-actions\" style=\"flex-wrap:wrap;align-items:center;gap:.45rem\">");
 if !f.guest&&!f.is_own{
  out.push_str("<span class=\"remote-profile-follow-inline\">");let(action,attrs,button)=if f.following{("unfollow_remote"," onsubmit=\"return confirm('Unfollow this account?');\"","<button class=\"btn btn-following\" type=\"submit\" title=\"Following — click to unfollow\">Following</button>".into())}else if f.requested{("unfollow_remote"," data-follow-locked=\"1\" onsubmit=\"return confirm('Cancel this follow request?');\"","<button class=\"btn btn-following\" type=\"submit\" title=\"Requested — click to cancel\">Requested</button>".into())}else{("follow_remote",if f.locked{" data-follow-locked=\"1\""}else{" data-follow-locked=\"0\""},format!("<button class=\"btn btn-primary\" type=\"submit\">{}</button>",if f.follows_you{"Follow back"}else{"Follow"}))};out.push_str(&post_form(f,action,"",attrs,&button));
  out.push_str(&format!("<span id=\"remote-profile-rel-state\" data-following=\"{}\" data-followed-by=\"{}\">",u8::from(f.following),u8::from(f.follows_you)));if f.following&&f.follows_you{out.push_str("<span class=\"tag\" title=\"You follow each other\">mutuals</span>");}else if f.follows_you{out.push_str("<span class=\"tag\" title=\"This account follows you\">follows you</span>");}out.push_str("</span></span>");
 }
 if f.moderation.muted{out.push_str("<span class=\"tag\" title=\"Hidden from your timelines &amp; notifications\">muted for me</span>");}
 if !f.guest&&!f.is_own{out.push_str(&post_form(f,if f.post_subscription{"unsubscribe_posts"}else{"subscribe_posts"},"","",if f.post_subscription{"<button class=\"btn btn-ghost\" type=\"submit\" title=\"Stop notifications for their posts\">Unsubscribe</button>"}else{"<button class=\"btn btn-ghost\" type=\"submit\" title=\"Get a notification when they post\">Subscribe</button>"}));out.push_str(&format!("<a class=\"btn btn-ghost\" href=\"?view=outbox&amp;compose=1&amp;mention={}&amp;return_view=remote_profile&amp;return_actor={}\" title=\"Start a post mentioning this account\">Mention</a>",form_query(&format!("@{}",f.key)),form_query(&f.actor)));
  let extra=format!("{}{}",hidden("bite_kind","user"),hidden("target",&f.actor));out.push_str(&post_form(f,"bite_remote",&extra," onsubmit=\"return confirm('Bite this account? (Wafrn-compatible 🦷)');\"","<button class=\"btn btn-ghost\" type=\"submit\" title=\"Wafrn-compatible bite\"><i class=\"ph ph-tooth\" aria-hidden=\"true\"></i> Bite</button>"));out.push_str(&local_menu(f));
 }out.push_str("</div></article>");out.push_str(&tabs_html(f));Ok(out)
}
pub(crate) fn render(token:&crate::profile_context::AdmittedProfileHeader)->Result<String>{render_core(token.facts())}
/// Tab placement shared with PHP `ap_outbox_note_is_reply`.
/// A reply needs `in_reply_to`. Hashtags and top-level mentions stay posts.
/// Hashtags or mentions on a real reply do not move it to Posts.
/// Only public notes (NULL visibility counts as public) appear on those tabs.
pub(crate) fn profile_reply_split(row:&Value)->Value{
 let reply=profile_note_is_reply(row);let public=profile_note_on_public_profile(row);
 json!({"reply":reply,"public_profile":public,"posts":public&&!reply,"replies":public&&reply})
}
fn profile_note_is_reply(row:&Value)->bool{
 let raw=match row.get("in_reply_to"){Some(Value::String(text))=>text.as_str(),Some(Value::Null)|None=>"",_=>return false};
 !card::php_trim(raw).is_empty()
}
fn profile_note_on_public_profile(row:&Value)->bool{
 match row.get("visibility"){None|Some(Value::Null)=>true,Some(Value::String(text))=>text=="public",_=>false}
}
#[cfg(test)]
#[path="profile_header_cache_fixture.rs"]
mod cache_fixture;
#[cfg(test)]
mod tests{
 use super::*;
 fn blank_facts()->HeaderFacts{
  HeaderFacts{
   actor:String::new(),key:String::new(),profile:Value::Null,current_profile:Value::Null,owner:0,owner_actor:String::new(),csrf:String::new(),guest:false,is_own:false,
   requested_tab:String::new(),effective_tab:String::new(),from:String::new(),blog_slug:String::new(),tab_href:String::new(),
   following:false,follows_you:false,requested:false,locked:false,post_subscription:false,
   moderation:ModerationContext{is_self:false,is_local:false,is_local_admin:false,is_wafrn:false,is_admin:false,personal_block_id:None,muted:false,deprioritized:false,global_kind:String::new(),global_id:0},
   counts:HeaderCounts::default(),name:String::new(),bio:String::new(),avatar:None,banner:None,fallback_avatar:String::new(),
   emoji:HashMap::new(),fields:vec![],badges:vec![],sl:None,wow:None,bridge:None,bsky_handle:String::new(),
   pin_rows:vec![],featured_rows:vec![],blog_rows:vec![],blog_post:None,blog_offset:0,dependencies:Value::Null,
  }
 }
 #[test]
 fn public_profile_counts_only_real_replies(){
  let hashtag=json!({"in_reply_to":null,"visibility":"public","content":"<p><a class=\"mention hashtag\" href=\"https://mkultra.monster/tags/flux\">#flux</a></p>","raw_create_json":"{\"tag\":[{\"type\":\"Hashtag\"}]}"});
  let mention=json!({"in_reply_to":"","visibility":"public","content":"<p><a class=\"mention\" href=\"https://mkultra.monster/users/ada\">@ada</a></p>","raw_create_json":"{\"type\":\"Mention\"} {\"type\": \"Mention\"}"});
  let reply=json!({"in_reply_to":" https://mkultra.monster/users/ada/notes/parent ","visibility":"public","content":"A plain reply"});
  let reply_hashtag=json!({"in_reply_to":"https://mkultra.monster/users/ada/notes/parent","visibility":"public","content":"<p><a class=\"mention hashtag\" href=\"https://mkultra.monster/tags/flux\">#flux</a></p>","raw_create_json":"{\"tag\":[{\"type\":\"Hashtag\"},{\"type\": \"Hashtag\"}]}"});
  let reply_mention=json!({"in_reply_to":"https://mkultra.monster/users/ada/notes/parent","visibility":"public","content":"<p><a class=\"mention\" href=\"https://mkultra.monster/users/ada\">@ada</a> <a class='mention'>@bea</a></p>","raw_create_json":"{\"type\":\"Mention\"} {\"type\": \"Mention\"}"});
  let local=json!({"in_reply_to":null,"visibility":"local","content":"<p><a class=\"mention hashtag\">#local</a> <a class=\"mention\">@ada</a></p>","raw_create_json":"{\"type\":\"Mention\"}"});
  assert_eq!(profile_reply_split(&hashtag),json!({"reply":false,"public_profile":true,"posts":true,"replies":false}));
  assert_eq!(profile_reply_split(&mention),json!({"reply":false,"public_profile":true,"posts":true,"replies":false}));
  assert_eq!(profile_reply_split(&reply),json!({"reply":true,"public_profile":true,"posts":false,"replies":true}));
  assert_eq!(profile_reply_split(&reply_hashtag),json!({"reply":true,"public_profile":true,"posts":false,"replies":true}));
  assert_eq!(profile_reply_split(&reply_mention),json!({"reply":true,"public_profile":true,"posts":false,"replies":true}));
  assert_eq!(profile_reply_split(&local),json!({"reply":false,"public_profile":false,"posts":false,"replies":false}));
  assert_eq!(profile_reply_split(&json!({"in_reply_to":"https://mkultra.monster/users/ada/notes/parent","visibility":"private"})),json!({"reply":true,"public_profile":false,"posts":false,"replies":false}));
  assert_eq!(profile_reply_split(&json!({})),json!({"reply":false,"public_profile":true,"posts":true,"replies":false}));
  let source=include_str!("profile_header.rs");
  let counts=source.split("pub(crate) fn profile_reply_split").next().unwrap();
  assert!(counts.contains("vaak:profile:reply-count:v2:"));
  assert!(counts.contains("vaak:profile:outbox-count:v2:"));
  assert!(counts.contains("COALESCE(visibility, 'public') = 'public'"));
  assert!(counts.contains("TRIM(COALESCE(in_reply_to, '')) <> ''"));
  assert!(!counts.contains("reply-count:v1"));
  assert!(!counts.contains("\"type\":\"Mention\""));
  assert!(!counts.contains("class=\"%mention"));
  assert!(!counts.contains("class=''%mention"));
 }
 #[test]
 fn media_count_and_blog_pages_stay_on_the_first_row(){
  let source=include_str!("profile_header.rs");
  assert!(source.contains("vaak:profile:media-count:v2:"));
  assert!(!source.contains(&format!("media-count:v{}:", 1)));
  assert!(source.contains("COALESCE(visibility, 'public') = 'public' AND raw_create_json LIKE"));
  assert!(source.contains("LIMIT 40 OFFSET $2"));
  assert!(!source.contains(&format!("{} DESC LIMIT 40\"", "id")));
 }
 #[test]
 fn second_life_icon_uses_shipped_svg(){
  let icon="<img src=\"/vaak/second-life.svg\" alt=\"Second Life\" width=\"43\" height=\"34\" loading=\"lazy\">";
  let asset=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../vaak/second-life.svg");
  assert!(asset.is_file(),"shipped Second Life icon missing");
  let mut linked=blank_facts();
  linked.sl=Some(json!({"sl_username":"Ada Resident","sl_agent_id":"AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA"}));
  let html=world_html(&linked);
  assert!(html.contains(icon),"{html}");
  assert!(html.contains("secondlife:///app/agent/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/about"));
  assert!(!html.contains("second-life.jpg")&&!html.contains("second-life.png"));
  let mut fallback=blank_facts();
  fallback.sl=Some(json!({"sl_username":"Bad Id","sl_agent_id":"not-a-uuid"}));
  let html=world_html(&fallback);
  assert!(html.contains(icon));
  assert!(html.contains("https://secondlife.com/"));
  assert!(!html.contains("second-life.jpg"));
 }
 #[tokio::test]
 #[ignore="requires genuine PHP header current SQL corpus and isolated PostgreSQL"]
 async fn actual_local_header_pg_replay()->Result<()>{
  let url=std::env::var("PROFILE_FIXTURE_DB")?;anyhow::ensure!(url.contains("/tmp/"),"isolated socket required");
  let reference:Value=serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_HEADER_REFERENCE")?)?)?;
  let db=crate::db::connect(&url).await?;let mut output=Vec::new();let mut mutation_controls=Vec::new();
  for width in ["INTEGER","BIGINT"]{
   let schema=format!("profile_header_native_{}_{}",width.to_ascii_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await?;
   let result:Result<()>=async{
    for(table,columns)in reference["current_schema_by_width"][width].as_object().context("schema absent")?{let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",s(c,"column_name"),s(c,"data_type"))).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await?;}
    if let Some(rows)=reference["current_constraints_by_width"][width].as_array(){for row in rows{db.batch_execute(&format!("ALTER TABLE {} ADD {}",s(row,"table_name"),s(row,"definition"))).await?;}}
    for capture in reference["captures"].as_array().unwrap().iter().filter(|c|c["width"]==width){
     for(table,rows)in capture["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await?;for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await?;}}
     let _count_file=if !capture["bsky_count_file_input"].is_null(){Some(cache_fixture::CountFileFixture::start(&capture["bsky_count_file_input"])?)}else{None};
     let warm=std::env::var("PROFILE_HEADER_WARM").as_deref()==Ok("1");
     struct NoProcesses;impl crate::profile_warm::WarmTransport for NoProcesses{fn actor_process<'a>(&'a self,_:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=bool>+Send+'a>>{Box::pin(async{panic!("local header must not warm remote actors")})}fn media_process(&self)->std::pin::Pin<Box<dyn std::future::Future<Output=bool>+Send+'_>>{Box::pin(async{panic!("local header must not warm remote media")})}}
     let mut cfg=crate::config::Config{database_url:String::new(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:1,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};let redis_fixture=if !capture["redis_transport_input"].is_null(){let f=cache_fixture::RedisFixture::start(&capture["redis_transport_input"]).await?;cfg.redis_url=f.url.clone();Some(f)}else{None};let warm_context=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg,std::sync::Arc::new(NoProcesses)));
     let ctx=&capture["context"];let input=&capture["request"];let request=HeaderRequest{tab:s(input,"tab").into(),from:s(input,"from").into(),post:s(input,"post").into(),page:input.get("page").and_then(Value::as_i64).unwrap_or(0)};let owner=card::integer(ctx,"owner");
     if card::boolean(ctx,"blocked"){
      let result=if owner>0{let mut session=AuthenticatedProfileSession::from_php_session(owner,s(ctx,"csrf").into(),s(ctx,"key").into(),s(ctx,"actor").into(),false,0)?;if warm{session=session.with_warm_context(warm_context.clone());}resolve_blocked_authenticated(&db,&session,&request).await}else{let mut session=GuestProfileSession::from_php_guest_profile(s(ctx,"key").into(),s(ctx,"actor").into(),false,0)?;if warm{session=session.with_warm_context(warm_context.clone());}resolve_blocked_guest(&db,&session,&request).await};
      match result{Ok(f)=>output.push(json!({"width":width,"case":capture["case"],"html":render_blocked_core(&f)?,"blocked":true})),Err(e)=>output.push(json!({"width":width,"case":capture["case"],"error":format!("{e:#}"),"html":""}))}if let Some(fixture)=&redis_fixture{if let Some(row)=output.last_mut(){row["redis_transport_effects"]=json!(*fixture.effects.lock().unwrap());}}continue;
     }
     let resolved=if owner>0{let mut session=AuthenticatedProfileSession::from_php_session(owner,s(ctx,"csrf").into(),s(ctx,"key").into(),s(ctx,"actor").into(),false,0)?;if warm{session=session.with_warm_context(warm_context.clone());}resolve_authenticated(&db,&session,&request).await}else{let mut session=GuestProfileSession::from_php_guest_profile(s(ctx,"key").into(),s(ctx,"actor").into(),false,0)?;if warm{session=session.with_warm_context(warm_context.clone());}resolve_guest(&db,&session,&request).await};
     match resolved{Ok(f)=>{f.require_secondary_current(&db).await?;
      if std::env::var("PROFILE_HEADER_MUTATIONS").as_deref()==Ok("1"){
       for table in ["following","followers","ap_follow_requests","ap_post_subscriptions","ap_mutes","ap_deprioritized_actors","bsky_sessions","bsky_graph_sync","ap_sl_links","ap_wow_links","phyrian_bridge_links","remote_custom_emojis","vaak_blog_posts"]{
        if !exists_table(&db,table).await?{continue}db.batch_execute(&format!("BEGIN;DELETE FROM {table}")).await?;
        let changed=(table=="vaak_blog_posts"&&((f.requested_tab=="blog"&&!f.blog_rows.is_empty())||f.blog_post.is_some()))||header_dependencies(&db,&f.key,&f.actor,f.owner,&f.owner_actor,card::integer(&f.current_target_snapshot(&db).await?,"id"),false).await?!=f.dependencies;
        let denied=f.require_secondary_current(&db).await.is_err();db.batch_execute("ROLLBACK").await?;
        if changed{anyhow::ensure!(denied,"late {table} mutation accepted");mutation_controls.push(json!({"width":width,"case":capture["case"],"table":table,"denied":true}));}
       }
      }
      output.push(json!({"width":width,"case":capture["case"],"html":render_core(&f)?,"secondary_html":if f.effective_tab=="blog"{Some(secondary::blog(&f)?)}else{None},"requested_tab":f.requested_tab,"effective_tab":f.effective_tab,"blog_slug":f.blog_slug,"tab_href":f.tab_href,"counts":{"posts":f.counts.posts,"following":f.counts.following,"followers":f.counts.followers,"pinned":f.counts.pinned,"featured":f.counts.featured,"blog":f.counts.blog,"asks":f.counts.asks},"scope":"actual native current SQL header facts + pure fragment; no admission or full page assertion"}));},Err(e)=>output.push(json!({"width":width,"case":capture["case"],"error":format!("{e:#}"),"html":""}))}
     if let Some(fixture)=&redis_fixture{if let Some(row)=output.last_mut(){row["redis_transport_effects"]=json!(*fixture.effects.lock().unwrap());}}
     if exists_table(&db,"bsky_actor_refresh_queue").await?{let queue=db.query("SELECT to_jsonb(q) FROM bsky_actor_refresh_queue q ORDER BY owner_user_id,actor_ref",&[]).await?.iter().map(|r|r.get::<_,Value>(0)).collect::<Vec<_>>();if let Some(row)=output.last_mut(){row["after_queue"]=json!(queue);}}
    }Ok(())
   }.await;db.batch_execute("SET search_path=public").await?;db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await?;result?;
  }
  std::fs::write(std::env::var("PROFILE_HEADER_NATIVE_OUTPUT")?,serde_json::to_vec(&json!({"captures":output,"late_dependency_mutation_controls":mutation_controls}))?)?;Ok(())
 }
}

pub(crate) const BADGES:&[(&str,&str,&str)]=&[
 ("admin","Admin","🛡️"),
 ("supporter","Supporter","💜"),
 ("rainbow","Rainbow pride","🏳️‍🌈"),
 ("trans","Trans pride","🏳️‍⚧️"),
 ("bi","Bisexual pride","🩷💜💙"),
 ("nonbinary","Non-binary pride","💛🤍💜🖤"),
 ("lesbian","Lesbian pride","🧡🤍🩷"),
 ("gay","Gay pride","💚💙"),
 ("pansexual","Pansexual pride","🩷💛💙"),
 ("asexual","Asexual pride","🖤🩶🤍💜"),
 ("aromantic","Aromantic pride","💚🤍🩶🖤"),
 ("genderfluid","Genderfluid pride","🩷🤍💜🖤💙"),
 ("genderqueer","Genderqueer pride","💜🤍💚"),
 ("intersex","Intersex pride","💛💜"),
 ("polyamory","Polyamory pride","💙❤️🖤"),
 ("queer","Queer pride","🏳️‍🌈"),
 ("usa","United States","🇺🇸"),
 ("uk","United Kingdom","🇬🇧"),
 ("canada","Canada","🇨🇦"),
 ("ireland","Ireland","🇮🇪"),
 ("germany","Germany","🇩🇪"),
 ("france","France","🇫🇷"),
 ("netherlands","Netherlands","🇳🇱"),
 ("ukraine","Ukraine","🇺🇦"),
 ("japan","Japan","🇯🇵"),
 ("australia","Australia","🇦🇺"),
 ("new_zealand","New Zealand","🇳🇿"),
 ("brazil","Brazil","🇧🇷"),
 ("mexico","Mexico","🇲🇽"),
 ("india","India","🇮🇳"),
 ("south_africa","South Africa","🇿🇦"),
 ("pirate","Pirate","🏴‍☠️"),
 ("space","Space nerd","🚀"),
 ("cat","Cat person","🐈‍⬛"),
 ("dog","Dog person","🐕"),
 ("coffee","Coffee enthusiast","☕"),
 ("tea","Tea enthusiast","🫖"),
 ("bookworm","Bookworm","📚"),
 ("gamer","Gamer","🎮"),
 ("music","Music lover","🎵"),
 ("artist","Artist","🎨"),
 ("programmer","Programmer","💻"),
 ("plant_parent","Plant parent","🪴"),
 ("night_owl","Night owl","🦉"),
 ("wizard","Wizard","🧙"),
 ("dragon","Dragon friend","🐉"),
 ("dice","Dice goblin","🎲"),
 ("minecraft","Minecraft builder","⛏️"),
];

#[cfg(test)]
mod supporter_tests {
    use super::*;
    #[test]
    #[ignore = "requires explicitly generated PHP Supporter badge corpus"]
    fn supporter_badges_php_corpus() {
        let path=std::env::var("SUPPORTER_BADGE_REFERENCE").expect("actual PHP corpus required");
        let data:Value=serde_json::from_slice(&std::fs::read(path).unwrap()).unwrap();
        for row in data["cases"].as_array().unwrap() {
            let expected:Vec<String>=serde_json::from_value(row["expected"].clone()).unwrap();
            assert_eq!(normalize_badges(&row["input"]),expected,"{}",row["input"]);
        }
        let b=BADGES.iter().find(|b|b.0=="supporter").unwrap();
        assert_eq!(b.1,data["supporter"]["label"].as_str().unwrap());
        assert_eq!(b.2,data["supporter"]["emoji"].as_str().unwrap());
    }
    #[test]
    fn supporter_badges_preserve_six_ordinary_slots() {
        assert_eq!(normalize_badges(&json!(["cat","dog","coffee","tea","bookworm","gamer","music"," supporter "])),
            vec!["supporter","cat","dog","coffee","tea","bookworm","gamer"]);
        assert_eq!(normalize_badges(&json!([null,7,"unknown"," cat ","cat",{},"supporter"])),vec!["supporter","cat"]);
        assert!(normalize_badges(&json!("malformed json")).is_empty());
    }
    #[tokio::test]
    #[ignore = "requires isolated local PostgreSQL fixture"]
    async fn supporter_current_sql_grants() -> Result<()> {
        let url=std::env::var("SUPPORTER_TEST_DATABASE_URL")?;
        let(db,connection)=tokio_postgres::connect(&url,tokio_postgres::NoTls).await?;
        let connection_task=tokio::spawn(async move {connection.await});
        for width in ["SERIAL","BIGSERIAL"] {
            let schema=format!("supporter_native_{}_{}",std::process::id(),width.to_ascii_lowercase());
            db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path TO {schema};CREATE TABLE ap_users(id {width} PRIMARY KEY,username TEXT,actor_key TEXT,disabled_at TEXT,is_admin INTEGER NOT NULL DEFAULT 0);INSERT INTO ap_users(username,actor_key)VALUES('paid','paid'),('unpaid','unpaid');")).await?;
            assert!(!supporter_awarded(&db,"paid").await?);
            db.batch_execute("CREATE TABLE ap_supporter_codes(code_hash TEXT,used_by BIGINT,used_at TEXT);INSERT INTO ap_supporter_codes VALUES('fixture',1,'now');").await?;
            assert!(supporter_awarded(&db,"paid").await?);
            let plain=json!(["cat","dog","coffee","tea","bookworm","gamer"]);
            assert_eq!(authorized_badges(&db,"paid",&plain).await?,vec!["supporter","cat","dog","coffee","tea","bookworm","gamer"]);
            assert_eq!(authorized_badges(&db,"paid",&Value::Null).await?,vec!["supporter"]);
            assert_eq!(authorized_badges(&db,"unpaid",&json!([" supporter ","cat"])).await?,vec!["cat"]);
            assert!(!supporter_awarded(&db,"unpaid").await?);
            let forged=json!(["admin","supporter","cat","dog","coffee","tea","bookworm","gamer"]);
            assert_eq!(authorized_badges(&db,"unpaid",&forged).await?,vec!["cat","dog","coffee","tea","bookworm","gamer"]);
            db.batch_execute("UPDATE ap_users SET is_admin=1 WHERE actor_key='unpaid'").await?;
            assert_eq!(authorized_badges(&db,"unpaid",&forged).await?,vec!["admin","cat","dog","coffee","tea","bookworm","gamer"]);
            let role_before=header_dependencies(&db,"unpaid","https://mkultra.monster/users/unpaid",1,"https://mkultra.monster/users/paid",2,false).await?;
            db.batch_execute("UPDATE ap_users SET is_admin=0 WHERE actor_key='unpaid'").await?;
            assert_eq!(authorized_badges(&db,"unpaid",&forged).await?,vec!["cat","dog","coffee","tea","bookworm","gamer"]);
            assert_ne!(role_before,header_dependencies(&db,"unpaid","https://mkultra.monster/users/unpaid",1,"https://mkultra.monster/users/paid",2,false).await?,"late role withdrawal invalidates header admission");
            let before=header_dependencies(&db,"paid","https://mkultra.monster/users/paid",2,"https://mkultra.monster/users/unpaid",1,false).await?;
            db.batch_execute("UPDATE ap_supporter_codes SET used_by=NULL,used_at=NULL").await?;
            assert!(!supporter_awarded(&db,"paid").await?);
            let after=header_dependencies(&db,"paid","https://mkultra.monster/users/paid",2,"https://mkultra.monster/users/unpaid",1,false).await?;
            assert_ne!(before,after,"late grant withdrawal must invalidate header admission");
            assert_eq!(authorized_badges(&db,"paid",&json!(["supporter","cat"])).await?,vec!["cat"]);
            db.batch_execute("UPDATE ap_supporter_codes SET used_by=1,used_at='now'").await?;
            let active=header_dependencies(&db,"paid","https://mkultra.monster/users/paid",2,"https://mkultra.monster/users/unpaid",1,false).await?;
            db.batch_execute("UPDATE ap_users SET disabled_at='now' WHERE id=1").await?;
            assert!(!supporter_awarded(&db,"paid").await?);
            assert_ne!(active,header_dependencies(&db,"paid","https://mkultra.monster/users/paid",2,"https://mkultra.monster/users/unpaid",1,false).await?,"subject disablement invalidates other viewer admission");
            db.batch_execute(&format!("SET search_path TO public;DROP SCHEMA {schema} CASCADE")).await?;
        }
        drop(db);connection_task.await??;Ok(())
    }

}
