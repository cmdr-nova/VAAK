//! AP stats page copied from the ap-admin `stats` data block and template: the
//! firehose totals and 24h breakdowns over `events`, the instance-wide account,
//! post, follow and mention counts, and the per-user table. Admin only (role()
//! checks before and after the READ ONLY transaction). Every query that PHP wraps
//! in a catch is an error here, so PHP renders whatever partial page it would.
//! Spaces stand where the PHP template has whitespace.
use super::*;
use std::collections::HashMap;
pub(super) struct Bar {label:String,count:i64}
pub(super) struct UserRow {id:i64,username:String,is_admin:bool,disabled:bool,posts:i64,followers:i64,following:i64,mentions:i64}
pub(super) struct Collected {total24:i64,total7:i64,types:Vec<Bar>,actions:Vec<Bar>,hosts:Vec<Bar>,users:i64,users_active:i64,posts:i64,followers:i64,following:i64,mentions:i64,rows:Vec<UserRow>}
pub(super) const MAX_USERS:usize=1000;
fn since(now:i64,secs:i64)->Result<String>{
 // gmdate('c') compared as text, exactly as PHP binds it.
 Ok(chrono::DateTime::from_timestamp(now-secs,0).ok_or_else(||anyhow::anyhow!("date range"))?.format("%Y-%m-%dT%H:%M:%S+00:00").to_string())
}
async fn count(db:&tokio_postgres::Client,sql:&str)->Result<i64>{Ok(db.query_one(sql,&[]).await?.get::<_,i64>(0))}
/// GROUP BY breakdowns break count ties by label so the order is stable; the PHP
/// queries carry the same tiebreak.
async fn bars(db:&tokio_postgres::Client,column:&str,extra:&str,since24:&str)->Result<Vec<Bar>>{
 let sql=format!("SELECT {column}::text,COUNT(*)::bigint AS c FROM events WHERE created_at>=$1{extra} GROUP BY {column} ORDER BY c DESC,{column} ASC LIMIT 12");
 Ok(db.query(&sql,&[&since24]).await?.into_iter().map(|r|Bar{label:r.get::<_,Option<String>>(0).unwrap_or_default(),count:r.get(1)}).collect())
}
/// A PHP array keyed by a normalised string or int keeps whichever duplicate the
/// unordered GROUP BY returns last; two source rows on one key stay PHP's.
fn keyed<K:std::hash::Hash+Eq>(rows:impl IntoIterator<Item=(K,i64)>)->Result<HashMap<K,i64>>{
 let mut out=HashMap::new();
 for(k,c)in rows{if out.insert(k,c).is_some(){bail!("PHP keeps an unordered duplicate");}}
 Ok(out)
}
pub(super) async fn collect(db:&tokio_postgres::Client,now:i64)->Result<Collected>{
 let (since24,since7)=(since(now,86400)?,since(now,7*86400)?);
 let total7=db.query_one("SELECT COUNT(*)::bigint FROM events WHERE created_at>=$1",&[&since7]).await?.get::<_,i64>(0);
 let total24=db.query_one("SELECT COUNT(*)::bigint FROM events WHERE created_at>=$1",&[&since24]).await?.get::<_,i64>(0);
 let types=bars(db,"type","",&since24).await?;
 let actions=bars(db,"action_taken","",&since24).await?;
 let hosts=bars(db,"host"," AND host IS NOT NULL",&since24).await?;
 let users=count(db,"SELECT COUNT(*)::bigint FROM ap_users").await?;
 let users_active=count(db,"SELECT COUNT(*)::bigint FROM ap_users WHERE disabled_at IS NULL").await?;
 let posts=count(db,"SELECT COUNT(*)::bigint FROM outbox_notes").await?;
 let followers=count(db,"SELECT COUNT(*)::bigint FROM followers").await?;
 let following=count(db,"SELECT COUNT(*)::bigint FROM following").await?;
 // PHP retries without deleted_at on a schema that lacks it; that schema stays PHP's.
 let mentions=count(db,"SELECT COUNT(*)::bigint FROM mentions WHERE deleted_at IS NULL").await?;
 let accounts=db.query("SELECT id::bigint,username::text,actor_key::text,COALESCE(is_admin,0)<>0,disabled_at IS NOT NULL FROM ap_users ORDER BY id ASC LIMIT 1001",&[]).await?;
 if accounts.len()>MAX_USERS{bail!("user table bound");}
 let post_by_user=keyed(db.query("SELECT split_part(id::text,'/',5),COUNT(*)::bigint FROM outbox_notes WHERE id LIKE 'https://mkultra.monster/users/%' GROUP BY 1",&[]).await?
  .into_iter().map(|r|(r.get::<_,Option<String>>(0).unwrap_or_default().to_ascii_lowercase(),r.get::<_,i64>(1))).filter(|(u,_)|!u.is_empty()))?;
 let by_actor=|rows:Vec<tokio_postgres::Row>|keyed(rows.into_iter().map(|r|(r.get::<_,Option<String>>(0).unwrap_or_default().trim_end_matches('/').to_string(),r.get::<_,i64>(1))));
 let followers_by=by_actor(db.query("SELECT owner_actor_id::text,COUNT(*)::bigint FROM followers GROUP BY owner_actor_id",&[]).await?)?;
 let following_by=by_actor(db.query("SELECT owner_actor_id::text,COUNT(*)::bigint FROM following GROUP BY owner_actor_id",&[]).await?)?;
 // (int) of the owner id: NULL reads as 0, as PHP casts it.
 let mentions_by=keyed(db.query("SELECT owner_user_id::bigint,COUNT(*)::bigint FROM mentions WHERE deleted_at IS NULL GROUP BY owner_user_id",&[]).await?
  .into_iter().map(|r|(r.get::<_,Option<i64>>(0).unwrap_or(0),r.get::<_,i64>(1))))?;
 let mut rows=Vec::new();
 for a in accounts{
  let (id,username,actor_key)=(a.get::<_,i64>(0),a.get::<_,Option<String>>(1),a.get::<_,Option<String>>(2));
  let key=actor_key.clone().or_else(||username.clone()).unwrap_or_default();
  let actor_id=if key.is_empty(){String::new()}else{format!("https://mkultra.monster/users/{key}")};
  rows.push(UserRow{id,username:username.unwrap_or_else(||key.clone()),is_admin:a.get(3),disabled:a.get(4),
   posts:post_by_user.get(&key.to_ascii_lowercase()).copied().unwrap_or(0),followers:followers_by.get(&actor_id).copied().unwrap_or(0),
   following:following_by.get(&actor_id).copied().unwrap_or(0),mentions:mentions_by.get(&id).copied().unwrap_or(0)});
 }
 Ok(Collected{total24,total7,types,actions,hosts,users,users_active,posts,followers,following,mentions,rows})
}
const CELL:&str="padding:.4rem;border-bottom:1px solid color-mix(in srgb, var(--border) 70%, transparent)";
const TH:&str="padding:.35rem .4rem;border-bottom:1px solid var(--border)";
pub(super) fn paint(c:Collected)->Result<(String,usize,Value)>{
 let mut h=String::from(r#"<div class="meta" style="margin-bottom:.85rem"> Instance-wide ActivityPub stats across <b>all local users</b>, plus the shared federation firehose. </div> <div class="stat-grid"> "#);
 for(n,l)in[(c.users_active,"Active local users"),(c.users,"Local accounts"),(c.posts,"Local posts"),(c.followers,"Followers (all users)"),(c.following,"Following (all users)"),(c.mentions,"Open mentions (all)"),(c.total24,"Firehose events (24h)"),(c.total7,"Firehose events (7d)")]{
  h.push_str(&format!(r#"<div class="stat"><div class="n">{n}</div><div class="l">{l}</div></div> "#));
 }
 h.push_str(r#"</div> <div class="side-card" style="margin-top:1rem"> <h3>Local users</h3> "#);
 if c.rows.is_empty(){h.push_str(r#"<div class="empty">No local users found.</div> "#);}
 else{
  h.push_str(r#"<div style="overflow-x:auto"> <table style="width:100%;border-collapse:collapse;font-size:.88rem"> <thead> <tr class="meta" style="text-align:left"> "#);
  for th in["User","Posts","Followers","Following","Mentions","Flags"]{h.push_str(&format!(r#"<th style="{TH}">{th}</th> "#));}
  h.push_str("</tr> </thead> <tbody> ");
  for u in &c.rows{
   let flags:Vec<&str>=[(u.is_admin,"admin"),(u.disabled,"disabled")].into_iter().filter_map(|(on,f)|on.then_some(f)).collect();
   h.push_str(&format!(r#"<tr> <td style="{CELL};overflow-wrap:anywhere"> <span class="who">@{}</span> </td> <td style="{CELL}">{}</td> <td style="{CELL}">{}</td> <td style="{CELL}">{}</td> <td style="{CELL}">{}</td> <td style="{CELL}" class="meta"> {} </td> </tr> "#,
    esc(&u.username),u.posts,u.followers,u.following,u.mentions,if flags.is_empty(){"—".to_string()}else{esc(&flags.join(" · "))}));
  }
  h.push_str("</tbody> </table> </div> ");
 }
 h.push_str(r#"</div> <div class="side-card"> <h3>Activity types (24h firehose)</h3> "#);
 if c.types.is_empty(){h.push_str(r#"<div class="empty">No events in the last 24h.</div> "#);}
 let bar=|h:&mut String,b:&Bar|h.push_str(&format!(r#"<div class="bar-row"><span>{}</span><span>{}</span></div> "#,esc(&b.label),b.count));
 for b in &c.types{bar(&mut h,b);}
 h.push_str(r#"</div> <div class="side-card"> <h3>Actions (24h firehose)</h3> "#);
 for b in &c.actions{bar(&mut h,b);}
 h.push_str(r#"</div> <div class="side-card"> <h3>Top hosts (24h firehose)</h3> "#);
 for b in &c.hosts{bar(&mut h,b);}
 h.push_str("</div>");
 // h() runs ap_fix_utf8 on every value it prints; text it would repair stays PHP.
 static MOJIBAKE:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"â.|Ã.|Â.|ðŸ").unwrap());
 if MOJIBAKE.is_match(&h){bail!("PHP would repair printed text");}
 let rows:Vec<Value>=c.rows.iter().map(|u|json!({"id":u.id,"username":u.username,"posts":u.posts,"followers":u.followers,"following":u.following,"mentions":u.mentions,"is_admin":u.is_admin,"disabled":u.disabled})).collect();
 Ok((h,rows.len(),Value::Array(rows)))
}
