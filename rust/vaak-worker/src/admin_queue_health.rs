//! Queue health database reads copied from the ap-admin `queue_health` data block
//! and its Queue classes and Federation delivery dependencies cards. Admin only.
//! PHP keeps the readiness checks, Redis counters, path timings and slow routes,
//! which it measures itself, and fills the page's totals grid from these rows.
//! Spaces stand where the PHP template has whitespace.
use super::*;
struct Def {name:&'static str,table:&'static str,state:&'static str,queued:&'static str,active:&'static str,success:&'static str,failed:&'static str,time:&'static str,active_time:&'static str,created:&'static str}
/// The PHP $queueDefs list, in its order.
const DEFS:[Def;6]=[
 Def{name:"User actions",table:"ap_action_queue",state:"status",queued:"pending",active:"processing",success:"succeeded",failed:"failed",time:"next_attempt_at",active_time:"claimed_at",created:"created_at"},
 Def{name:"Federation delivery",table:"ap_publish_delivery_queue",state:"status",queued:"pending",active:"processing",success:"succeeded",failed:"failed",time:"next_attempt_at",active_time:"claimed_at",created:"created_at"},
 Def{name:"Federation fan-out",table:"ap_fanout_delivery_queue",state:"status",queued:"pending",active:"processing",success:"succeeded",failed:"failed",time:"next_attempt_at",active_time:"claimed_at",created:"created_at"},
 Def{name:"Remote media warming",table:"ap_media_warm_queue",state:"status",queued:"pending",active:"processing",success:"succeeded",failed:"failed",time:"next_attempt_at",active_time:"claimed_at",created:"created_at"},
 Def{name:"Scheduled posts",table:"ap_post_queue",state:"state",queued:"pending",active:"publishing",success:"published",failed:"failed",time:"scheduled_at",active_time:"claimed_at",created:"created_at"},
 Def{name:"Actor/profile refresh",table:"bsky_actor_refresh_queue",state:"status",queued:"pending",active:"processing",success:"succeeded",failed:"failed",time:"next_attempt_at",active_time:"locked_at",created:"queued_at"},
];
pub(super) struct Queue {name:&'static str,ok:bool,queued:i64,active:i64,failed:i64,retries:i64,active_since:String,oldest:String,next_due:String,last_success:String,oldest_failed:String}
pub(super) struct Dependency {activity:String,children:i64,succeeded:i64,open:i64,failed:i64,attempts:i64}
pub(super) struct Collected {queues:Vec<Queue>,deps:Vec<Dependency>}
async fn exists(db:&tokio_postgres::Client,table:&str)->Result<bool>{Ok(db.query_one("SELECT to_regclass($1) IS NOT NULL",&[&table]).await?.get(0))}
fn text(row:&tokio_postgres::Row,i:usize)->String{crate::library::card::php_trim(&row.get::<_,Option<String>>(i).unwrap_or_default()).to_string()}
pub(super) async fn collect(db:&tokio_postgres::Client)->Result<Collected>{
 let mut queues=Vec::new();
 for d in &DEFS{
  // A missing table is PHP's "unavailable" row; any other query error stays PHP's.
  if !exists(db,d.table).await?{queues.push(Queue{name:d.name,ok:false,queued:0,active:0,failed:0,retries:0,active_since:String::new(),oldest:String::new(),next_due:String::new(),last_success:String::new(),oldest_failed:String::new()});continue;}
  let (s,q,a,ok,f,t,c)=(d.state,d.queued,d.active,d.success,d.failed,d.time,d.created);
  let sql=format!("SELECT COUNT(*) FILTER (WHERE {s} IN ('{q}'))::bigint, COUNT(*) FILTER (WHERE {s} IN ('{a}'))::bigint, COUNT(*) FILTER (WHERE {s} IN ('{f}'))::bigint, COALESCE(SUM(attempts),0)::bigint, \
   MIN(CASE WHEN {s} IN ('{a}') THEN {at} END)::text, MIN(CASE WHEN {s} IN ('{q}','{a}') THEN COALESCE({t},{c}) END)::text, MIN(CASE WHEN {s} IN ('{q}') THEN {t} END)::text, \
   MAX(CASE WHEN {s} IN ('{ok}') THEN COALESCE({t},{c}) END)::text, MIN(CASE WHEN {s} IN ('{f}') THEN COALESCE({t},{c}) END)::text FROM {table}",at=d.active_time,table=d.table);
  let r=db.query_one(&sql,&[]).await?;
  queues.push(Queue{name:d.name,ok:true,queued:r.get(0),active:r.get(1),failed:r.get(2),retries:r.get(3),active_since:text(&r,4),oldest:text(&r,5),next_due:text(&r,6),last_success:text(&r,7),oldest_failed:text(&r,8)});
 }
 let mut deps=Vec::new();
 if exists(db,"ap_fanout_delivery_queue").await?{
  // Ties on the latest update break by activity key, in PHP's query too.
  for r in db.query("SELECT activity_key::text, COUNT(*)::bigint, COUNT(*) FILTER (WHERE status='succeeded')::bigint, COUNT(*) FILTER (WHERE status='pending')::bigint, COUNT(*) FILTER (WHERE status='processing')::bigint, COUNT(*) FILTER (WHERE status='failed')::bigint, COALESCE(SUM(attempts),0)::bigint \
   FROM ap_fanout_delivery_queue GROUP BY activity_key ORDER BY MAX(updated_at) DESC, activity_key ASC LIMIT 25",&[]).await?{
   deps.push(Dependency{activity:r.get::<_,Option<String>>(0).unwrap_or_default(),children:r.get(1),succeeded:r.get(2),open:r.get::<_,i64>(3)+r.get::<_,i64>(4),failed:r.get(5),attempts:r.get(6)});
  }
 }
 Ok(Collected{queues,deps})
}
const TH:&str="padding:.35rem .4rem;border-bottom:1px solid var(--border)";
const TD:&str="padding:.45rem .4rem;border-bottom:1px solid color-mix(in srgb, var(--border) 70%, transparent)";
fn when(ok:bool,s:&str,now:i64)->Result<String>{Ok(if !ok||s.is_empty(){"—".into()}else{esc(&relative(s,now)?)})}
pub(super) fn paint(c:Collected,r:&Request)->Result<(String,usize,Value)>{
 let mut h=String::from(r#"<div class="side-card" style="margin-top:1rem"> <h3>Queue classes</h3> <div style="overflow-x:auto"> <table style="width:100%;border-collapse:collapse;font-size:.88rem"> <thead><tr class="meta" style="text-align:left"> "#);
 for th in["Queue","Queued","Active","Failed","Attempts","Active since","Oldest pending","Next retry","Last success","Oldest failure"]{h.push_str(&format!(r#"<th style="{TH}">{th}</th> "#));}
 h.push_str("</tr></thead> <tbody> ");
 for q in &c.queues{
  let oldest=if !q.ok{"unavailable".to_string()}else if q.oldest.is_empty(){"—".into()}else{esc(&relative(&q.oldest,r.now)?)};
  h.push_str(&format!(r#"<tr> <td style="{TD}">{}</td> <td style="{TD}">{}</td> <td style="{TD}">{}</td> <td style="{TD}{}">{}</td> <td style="{TD}">{}</td> <td class="meta" style="{TD}">{}</td> <td class="meta" style="{TD}">{}</td> <td class="meta" style="{TD}">{}</td> <td class="meta" style="{TD}">{}</td> <td class="meta" style="{TD}">{}</td> </tr> "#,
   esc(q.name),q.queued,q.active,if q.failed>0{";color:var(--danger)"}else{""},q.failed,q.retries,when(q.ok,&q.active_since,r.now)?,oldest,when(q.ok,&q.next_due,r.now)?,when(q.ok,&q.last_success,r.now)?,when(q.ok,&q.oldest_failed,r.now)?));
 }
 h.push_str("</tbody> </table> </div> </div> ");
 if !c.deps.is_empty(){
  h.push_str(r#"<div class="side-card" style="margin-top:1rem"> <h3>Federation delivery dependencies</h3> <div class="meta" style="margin-bottom:.6rem">Each parent activity is shown with its durable per-inbox child deliveries.</div> <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.88rem"> <thead><tr class="meta" style="text-align:left"> "#);
  for th in["Activity","Children","Done","Open","Failed","Attempts"]{h.push_str(&format!(r#"<th style="{TH}">{th}</th> "#));}
  h.push_str("</tr></thead><tbody> ");
  for d in &c.deps{
   let a=esc(&d.activity);
   h.push_str(&format!(r#"<tr> <td class="meta" style="{TD};max-width:20rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{a}">{a}</td> <td style="{TD}">{}</td> <td style="{TD}">{}</td> <td style="{TD}">{}</td> <td style="{TD}{}">{}</td> <td style="{TD}">{}</td> </tr> "#,
    d.children,d.succeeded,d.open,if d.failed>0{";color:var(--danger)"}else{""},d.failed,d.attempts));
  }
  h.push_str("</tbody> </table></div> </div>");
 }
 static MOJIBAKE:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"â.|Ã.|Â.|ðŸ").unwrap());
 if MOJIBAKE.is_match(&h){bail!("PHP would repair printed text");}
 let rows:Vec<Value>=c.queues.iter().map(|q|json!({"name":q.name,"ok":q.ok,"queued":q.queued,"active":q.active,"failed":q.failed,"retries":q.retries})).collect();
 Ok((h,rows.len(),Value::Array(rows)))
}
