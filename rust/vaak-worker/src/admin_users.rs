//! Users read page copied from actual ap_auth_users_count/list and ap-admin Users branch.
//! PHP retains authentication, CSRF injection and all ban/unban mutations.
use super::*;
#[derive(Debug,Clone,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct User {
 id:i64,username:String,email:Option<String>,actor_key:String,actor_id:String,is_admin:i64,
 created_at:String,updated_at:String,disabled_at:Option<String>,profile_name:Option<String>,icon_url:Option<String>,
}
pub(super) struct Collected {rows:Vec<User>,total:i64,page:i64,pages:i64}
pub(super) async fn collect(db:&tokio_postgres::Client,r:&Request)->Result<Collected>{
 let search=r.search.as_deref().ok_or_else(||anyhow::anyhow!("users search binding missing"))?;
 // The four PHP LOWER/LIKE predicates deliberately preserve '%' and '_' as wildcards.
 const FILTER:&str=" WHERE ($1='' OR LOWER(COALESCE(u.username,'')) LIKE LOWER($2) OR LOWER(COALESCE(u.email,'')) LIKE LOWER($2) OR LOWER(COALESCE(u.actor_key,'')) LIKE LOWER($2) OR LOWER(COALESCE(p.name,'')) LIKE LOWER($2))";
 let needle=format!("%{search}%");
 let total:i64=db.query_one(&format!("SELECT COUNT(*) FROM ap_users u LEFT JOIN actor_profile p ON p.actor_key=u.actor_key{FILTER}"),&[&search,&needle]).await?.get(0);
 let pages=(total/20+i64::from(total%20!=0)).max(1);let page=r.offset.min(pages);let offset=(page-1).checked_mul(20).ok_or_else(||anyhow::anyhow!("users page range"))?;
 let sql=format!("SELECT jsonb_build_object('id',u.id::bigint,'username',u.username,'email',u.email,'actor_key',u.actor_key,'actor_id',u.actor_id,'is_admin',u.is_admin::bigint,'created_at',u.created_at,'updated_at',u.updated_at,'disabled_at',u.disabled_at,'profile_name',p.name,'icon_url',p.icon_url) FROM ap_users u LEFT JOIN actor_profile p ON p.actor_key=u.actor_key{FILTER} ORDER BY u.created_at DESC,u.id DESC LIMIT 20 OFFSET $3");
 let mut rows=vec![];for row in db.query(&sql,&[&search,&needle,&offset]).await?{rows.push(serde_json::from_value(row.get(0))?);}
 Ok(Collected{rows,total,page,pages})
}
pub(super) fn paint(c:Collected,r:&Request)->Result<(String,usize,Value)>{
 let search=r.search.as_deref().ok_or_else(||anyhow::anyhow!("users search missing"))?;
 let mut h=String::from(r#"<div class="meta" style="margin-bottom:1rem">Local VAAK accounts. Banning disables web login, rejects future API authentication, and revokes the user’s OAuth tokens. The operator account cannot be banned from this page.</div><form method="get" action="" style="display:flex;gap:.5rem;align-items:center;margin-bottom:1rem"><input type="hidden" name="view" value="users">"#);
 h.push_str(&format!(r#"<input type="search" name="q" value="{}" placeholder="Search username, email, or display name" aria-label="Search users" style="flex:1;min-width:0"><button class="btn btn-ghost" type="submit">Search</button>"#,esc(search)));
 if !search.is_empty(){h.push_str(r#"<a class="btn btn-ghost" href="?view=users">Clear</a>"#);}
 h.push_str(&format!(r#"</form><div class="meta" style="margin-bottom:.75rem">{} account{}{} </div>"#,c.total,if c.total==1{""}else{"s"},if search.is_empty(){String::new()}else{format!(" matching “{}”",esc(search))}));
 if c.rows.is_empty(){h.push_str(r#"<div class="empty">No local users found.</div>"#);}else{
  h.push_str(r#"<div style="display:flex;flex-direction:column;gap:.7rem">"#);
  for row in &c.rows{
   let name=row.profile_name.as_deref().filter(|s|!empty(s)).unwrap_or(&row.username);let disabled=row.disabled_at.as_deref().is_some_and(|s|!empty(s));let operator=row.actor_key=="cmdr_nova"||row.is_admin!=0;
   h.push_str(r#"<article class="tweet"><div class="tweet-hd"><div style="display:flex;align-items:center;gap:.65rem;min-width:0">"#);
   if let Some(icon)=row.icon_url.as_deref().filter(|s|!empty(s)){h.push_str(&format!(r#"<img src="{}" alt="" width="42" height="42" style="width:42px;height:42px;border-radius:50%;object-fit:cover;border:1px solid var(--border);flex:0 0 auto" loading="lazy" referrerpolicy="no-referrer">"#,esc(icon)));}
   h.push_str(&format!(r#"<div style="min-width:0"><div class="who" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{}</div><div class="meta">@{}@mkultra.monster</div></div></div><div class="meta" style="text-align:right">{}</div></div><div class="meta" style="margin-top:.55rem">Joined {}"#,esc(name),esc(&row.username),if operator{r#"<span class="tag">operator</span>"#}else if disabled{r#"<span class="tag" style="color:var(--danger)">banned</span>"#}else{r#"<span class="tag">active</span>"#},esc(&relative(&row.created_at,r.now)?)));
   if disabled{h.push_str(&format!(" · banned {}",esc(&relative(row.disabled_at.as_deref().unwrap(),r.now)?)));}
   h.push_str(&format!(r#"</div><div class="tweet-actions" style="flex-wrap:wrap"><a href="/users/{}" target="_blank" rel="noopener noreferrer">View profile</a>"#,esc(&query(&row.actor_key))));
   if !operator{
    h.push_str(&format!(r#"<form method="post" action="?view=users" style="display:inline" onsubmit="return confirm('{} this local user?');"><input type="hidden" name="action" value="{}"><input type="hidden" name="user_id" value="{}"><input type="hidden" name="return_view" value="users"><input type="hidden" name="users_q" value="{}"><input type="hidden" name="users_page" value="{}"><button class="btn {}" type="submit" style="padding:.25rem .7rem;font-size:.8rem;{}">{}</button></form>"#,if disabled{"Unban"}else{"Ban"},if disabled{"user_unban"}else{"user_ban"},row.id,esc(search),c.page,if disabled{"btn-primary"}else{"btn-ghost"},if disabled{""}else{"color:var(--danger)"},if disabled{"Unban user"}else{"Ban user"}));
   }
   h.push_str("</div></article>");
  }
  h.push_str("</div>");
  if c.pages>1{
   h.push_str(r#"<nav class="composer-actions" aria-label="Users pages" style="margin-top:1rem;justify-content:center;gap:.5rem">"#);let q=if search.is_empty(){String::new()}else{format!("&amp;q={}",query(search))};
   if c.page>1{h.push_str(&format!(r#"<a class="btn btn-ghost" href="?view=users&amp;page={}{}">Previous</a>"#,c.page-1,q));}
   h.push_str(&format!(r#"<span class="meta">Page {} of {}</span>"#,c.page,c.pages));
   if c.page<c.pages{h.push_str(&format!(r#"<a class="btn btn-ghost" href="?view=users&amp;page={}{}">Next</a>"#,c.page+1,q));}h.push_str("</nav>");
  }
 }
 Ok((h,c.rows.len(),serde_json::to_value(c.rows)?))
}
