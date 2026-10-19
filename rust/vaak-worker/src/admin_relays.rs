//! Relays read page copied from actual ap_relays_list and the ap-admin Relays branch.
//! PHP retains authentication, the session CSRF value and all relay mutations.
//! Spaces stand where the PHP template has whitespace, so the two match after
//! collapsing whitespace runs.
use super::*;
#[derive(Debug,Clone,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct Relay {id:i64,inbox_url:String,state:String,follow_activity_id:Option<String>,created_at:String,updated_at:String}
pub(super) async fn collect(db:&tokio_postgres::Client)->Result<Vec<Relay>>{
 // PHP lists every relay; past the bridge's 100-row bound the PHP page renders instead.
 let mut rows=Vec::new();
 for row in db.query("SELECT jsonb_build_object('id',id::bigint,'inbox_url',inbox_url::text,'state',state::text,'follow_activity_id',follow_activity_id::text,'created_at',created_at::text,'updated_at',updated_at::text) FROM ap_relays ORDER BY id ASC LIMIT 101",&[]).await?{rows.push(serde_json::from_value::<Relay>(row.get(0))?);}
 if rows.len()>100{bail!("relay rows beyond bridge bound");}
 Ok(rows)
}
fn form(action:&str,id:i64,csrf:&str,class:&str,label:&str,confirm:bool)->String{
 format!(r#"<form method="post" action="?view=relays" style="display:inline"{}> <input type="hidden" name="action" value="{action}"> <input type="hidden" name="relay_id" value="{id}"> <input type="hidden" name="csrf" value="{}"> <button class="btn {class}" type="submit" style="padding:.25rem .7rem;font-size:.8rem">{label}</button> </form>"#,if confirm{r#" onsubmit="return confirm('Remove this relay?');""#}else{""},esc(csrf))
}
pub(super) fn paint(rows:Vec<Relay>,r:&Request)->Result<(String,usize,Value)>{
 let (mut accepted,mut pending,mut rejected,mut idle)=(0,0,0,0);
 for row in &rows{match row.state.as_str(){"accepted"=>accepted+=1,"pending"=>pending+=1,"rejected"=>rejected+=1,_=>idle+=1}}
 let mut h=format!(r#"<div class="tweet" style="margin-bottom:1rem"> <div class="who">ActivityPub relays</div> <div class="body meta" style="margin-top:.45rem">Relays receive public Follow subscriptions from VAAK. A relay remains pending until it accepts the subscription.</div> <div class="tags" style="margin-top:.7rem;display:flex;gap:.4rem;flex-wrap:wrap"> <span class="tag">total {}</span> <span class="tag">accepted {accepted}</span> <span class="tag">pending {pending}</span> <span class="tag">idle {idle}</span> "#,rows.len());
 if rejected>0{h.push_str(&format!(r#"<span class="tag">rejected {rejected}</span> "#));}
 h.push_str(&format!(r#"</div> </div> <form class="composer" method="post" action="?view=relays" style="margin-bottom:1.25rem"> <input type="hidden" name="action" value="relay_add"> <input type="hidden" name="csrf" value="{}"> <input type="url" name="inbox_url" required placeholder="https://relay.example/inbox" autocomplete="off"> <div class="composer-actions"> <span class="meta"></span> <button class="btn btn-primary" type="submit">Add relay</button> </div> </form> "#,esc(&r.csrf)));
 if rows.is_empty(){h.push_str(r#"<div class="empty">No relays yet.</div> "#);}
 for row in &rows{
  let badge=match row.state.as_str(){"accepted"|"pending"|"rejected"=>row.state.as_str(),_=>"idle"};
  h.push_str(&format!(r#"<article class="tweet"> <div class="who"> {} <span class="tag" style="margin-left:.35rem">{}</span> </div> <div class="body meta relay-meta" style="margin-top:.35rem"> "#,esc(&row.inbox_url),esc(badge)));
  if let Some(follow)=row.follow_activity_id.as_deref().filter(|s|!empty(s)){h.push_str(&format!(r#"follow id <code style="font-size:.75rem;word-break:break-all">{}</code><br> "#,esc(follow)));}
  h.push_str(&format!(r#"updated {} </div> <div class="tweet-actions"> "#,esc(&row.updated_at)));
  match row.state.as_str(){
   "accepted"=>h.push_str(&form("relay_disable",row.id,&r.csrf,"btn-ghost","Disable",false)),
   "pending"=>{h.push_str(&form("relay_enable",row.id,&r.csrf,"btn-ghost","Retry Enable",false));h.push(' ');h.push_str(&form("relay_disable",row.id,&r.csrf,"btn-ghost","Cancel",false));}
   _=>h.push_str(&form("relay_enable",row.id,&r.csrf,"btn-primary","Enable",false)),
  }
  h.push(' ');h.push_str(&form("relay_remove",row.id,&r.csrf,"btn-ghost","Remove",true));
  h.push_str(" </div> </article> ");
 }
 Ok((h,rows.len(),serde_json::to_value(rows)?))
}
