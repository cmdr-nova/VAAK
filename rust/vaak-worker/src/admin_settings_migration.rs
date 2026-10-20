//! Import / Export page reads copied from vaak_actor_key, ap_alias_list,
//! ap_move_get, ap_move_active_target, ap_move_cooldown_remaining_seconds and the
//! import_export view's alias list and Move followers sections. Rows are scoped to
//! the session owner's actor key; the page is open to every active account, never
//! admin-gated. PHP retains the export downloads, CSV import, the follow import job
//! (a state file, not a table), every alias and Move action and CSRF injection.
//! Spaces stand where the PHP template has whitespace.
use super::*;
#[derive(Debug,Clone,Deserialize)]
#[serde(deny_unknown_fields)]
pub(super) struct Alias {id:i64,alias_actor_id:Option<String>,alias_acct:Option<String>,direction:Option<String>,verified_at:Option<String>,created_at:Option<String>}
#[derive(Debug,Clone,Deserialize)]
#[serde(deny_unknown_fields)]
pub(super) struct Move {moved_to_actor_id:Option<String>,moved_to_acct:Option<String>,moved_at:Option<String>,cleared_at:Option<String>}
pub(super) const MAX_ALIASES:usize=1000;
const COOLDOWN_DAYS:i64=30;
/// vaak_actor_key: the page's actor key (NULL reads as cmdr_nova), lowercased and
/// reduced to [a-z0-9_]. An empty result takes PHP's other fallbacks, so it stays PHP.
async fn actor_key(db:&tokio_postgres::Client,owner:i64)->Result<String>{
 let raw=db.query_one("SELECT COALESCE(actor_key::text,'cmdr_nova') FROM ap_users WHERE id::bigint=$1",&[&owner]).await?.get::<_,String>(0);
 let key:String=crate::library::card::php_trim(&raw).to_ascii_lowercase().chars().filter(|c|c.is_ascii_lowercase()||c.is_ascii_digit()||*c=='_').collect();
 if key.is_empty(){bail!("actor key needs PHP's fallbacks");}
 Ok(key)
}
pub(super) async fn aliases(db:&tokio_postgres::Client,owner:i64)->Result<Vec<Alias>>{
 let key=actor_key(db,owner).await?;
 let mut rows=Vec::new();
 for row in db.query("SELECT jsonb_build_object('id',id::bigint,'alias_actor_id',alias_actor_id::text,'alias_acct',alias_acct::text,'direction',direction::text,'verified_at',verified_at::text,'created_at',created_at::text) FROM account_aliases WHERE actor_key=$1 ORDER BY direction ASC,created_at DESC LIMIT 1001",&[&key]).await?{rows.push(serde_json::from_value::<Alias>(row.get(0))?);}
 if rows.len()>MAX_ALIASES{bail!("alias list bound");}
 // PHP leaves equal (direction, created_at) rows in whatever order the plan returns.
 if rows.windows(2).any(|w|w[0].direction==w[1].direction&&w[0].created_at==w[1].created_at){bail!("alias order tie");}
 Ok(rows)
}
pub(super) fn paint_aliases(rows:Vec<Alias>)->Result<(String,usize,Value)>{
 let mut h=String::new();
 if rows.is_empty(){h.push_str(r#"<div class="empty">No aliases yet.</div>"#);}
 let mut out=Vec::new();
 for a in &rows{
  let actor=a.alias_actor_id.as_deref().unwrap_or("");
  let acct=a.alias_acct.as_deref().unwrap_or("");
  let who=if empty(acct){actor}else{acct};
  let verified=!empty(a.verified_at.as_deref().unwrap_or(""));
  h.push_str(&format!(r#"<article class="tweet"> <div class="tweet-hd"> <div class="tweet-hd-main"> <div> <span class="who">{}</span> <span class="tag">{}</span> "#,esc(who),esc(a.direction.as_deref().unwrap_or(""))));
  if verified{h.push_str(r#"<span class="tag">verified</span> "#);}
  h.push_str(&format!(r#"</div> <div class="meta">{}</div> </div> </div> <div class="tweet-actions"> <form method="post" action="?view=import_export" style="display:inline"> <input type="hidden" name="action" value="alias_verify"> <input type="hidden" name="alias_id" value="{id}"> <button class="btn btn-ghost" type="submit" style="padding:.35rem .9rem;font-size:.85rem">Verify remote</button> </form> <form method="post" action="?view=import_export" style="display:inline" onsubmit="return confirm('Remove this alias?');"> <input type="hidden" name="action" value="alias_remove"> <input type="hidden" name="alias_id" value="{id}"> <button class="btn btn-ghost" type="submit" style="padding:.35rem .9rem;font-size:.85rem;color:var(--danger)">Remove</button> </form> </div> </article> "#,esc(actor),id=a.id));
  out.push(json!({"id":a.id,"verified":verified}));
 }
 mojibake_guard(&h)?;
 Ok((h,out.len(),Value::Array(out)))
}
pub(super) async fn move_row(db:&tokio_postgres::Client,owner:i64)->Result<Option<Move>>{
 let key=actor_key(db,owner).await?;
 Ok(match db.query_opt("SELECT jsonb_build_object('moved_to_actor_id',moved_to_actor_id::text,'moved_to_acct',moved_to_acct::text,'moved_at',moved_at::text,'cleared_at',cleared_at::text) FROM ap_account_move WHERE actor_key=$1",&[&key]).await?{
  Some(row)=>Some(serde_json::from_value::<Move>(row.get(0))?),None=>None})
}
/// new DateTimeImmutable for gmdate('c'), offset-bearing Postgres text and a zoneless
/// "Y-m-d H:i:s" (read in PHP's default zone, which decode() only admits as UTC).
/// Anything else, which PHP may parse or turn into a zero cooldown, stays PHP.
fn moved_at(s:&str)->Result<i64>{
 chrono::DateTime::parse_from_rfc3339(s).map(|v|v.timestamp())
  .or_else(|_|chrono::DateTime::parse_from_str(s,"%Y-%m-%d %H:%M:%S%.f%#z").map(|v|v.timestamp()))
  .or_else(|_|chrono::NaiveDateTime::parse_from_str(s,"%Y-%m-%d %H:%M:%S").map(|v|v.and_utc().timestamp()))
  .map_err(|_|anyhow::anyhow!("unproven PHP date format"))
}
pub(super) fn paint_move(m:Option<Move>,r:&Request)->Result<(String,usize,Value)>{
 let target=m.as_ref().map(|m|m.moved_to_actor_id.as_deref().unwrap_or("").trim_end_matches('/')).unwrap_or("");
 let active=!target.is_empty()&&m.as_ref().is_some_and(|m|empty(m.cleared_at.as_deref().unwrap_or("")));
 let mut h=String::new();
 if active{
  let m=m.as_ref().expect("active move has a row");
  h.push_str(&format!(r#"<article class="tweet" style="margin-bottom:1rem;border-color:var(--danger)"> <div class="tweet-hd"><div class="who">Active Move</div></div> <div class="body"> <code>movedTo</code> → {} "#,esc(target)));
  let acct=m.moved_to_acct.as_deref().unwrap_or("");
  if !empty(acct){h.push_str(&format!("({}) ",esc(acct)));}
  h.push_str(&format!(r#"<div class="meta">Since {}</div> </div> <form class="composer" method="post" action="?view=import_export" style="margin-top:.75rem" onsubmit="return confirm('Clear movedTo and re-push profile?');"> <input type="hidden" name="action" value="move_clear"> <input name="confirm" type="text" required placeholder="Type CLEAR to confirm" autocomplete="off"> <div class="composer-actions"> <button class="btn btn-ghost" type="submit" style="color:var(--danger)">Clear movedTo</button> </div> </form> </article>"#,esc(m.moved_at.as_deref().unwrap_or(""))));
 }else{
  let at=m.as_ref().and_then(|m|m.moved_at.as_deref()).unwrap_or("");
  let cooldown=if empty(at){0}else{(moved_at(at)?+COOLDOWN_DAYS*86400-r.now).max(0)};
  h.push_str(r#"<form class="composer" method="post" action="?view=import_export" style="margin-bottom:1rem" onsubmit="return confirm('This publishes movedTo and notifies followers. Continue?');"> <input type="hidden" name="action" value="move_out"> <div class="meta" style="margin-bottom:.5rem"> <b>Move out</b> — leave <em>this</em> account (we are the <b>old</b> account). Followers are pointed at the new account below. </div> <input name="target" type="text" required placeholder="New account @user@host or https://…"> <input name="confirm" type="text" required placeholder="Type MOVE to confirm" autocomplete="off" style="margin-top:.5rem"> "#);
  // ceil(seconds / 86400) for a positive cooldown.
  if cooldown>0{h.push_str(&format!(r#"<div class="meta" style="margin-top:.5rem;color:var(--danger)">Cooldown remaining ~{} days</div> "#,(cooldown+86399)/86400));}
  h.push_str(r#"<div class="composer-actions"> <button class="btn btn-ghost" type="submit" style="color:var(--danger)">Move out</button> </div> </form>"#);
 }
 mojibake_guard(&h)?;
 Ok((h,1,json!([{"panel":"move","state":if active{"active"}else{"none"}}])))
}
/// h() runs ap_fix_utf8 on every value it prints; text it would repair stays PHP.
fn mojibake_guard(h:&str)->Result<()>{
 static RE:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"â.|Ã.|Â.|ðŸ").unwrap());
 if RE.is_match(h){bail!("PHP would repair printed text");}Ok(())
}
#[cfg(test)]mod tests{
 use super::*;
 #[test]fn moved_at_formats(){
  let t=moved_at("2026-10-20T00:00:00+00:00").unwrap();
  for s in["2026-10-20 00:00:00+00","2026-10-20 02:00:00.5+02","2026-10-20 00:00:00"]{assert_eq!(moved_at(s).unwrap(),t,"{s}");}
  for s in["yesterday","2026-10-20","2026-10-20T00:00:00"]{assert!(moved_at(s).is_err(),"{s}");}
 }
}
