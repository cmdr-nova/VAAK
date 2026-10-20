//! Security page reads copied from ap_oauth_tokens_list (with ap_db_cmdr_nova_user_id),
//! ap_auth_2fa_enabled and the security view's two-factor and OAuth token sections.
//! Only token metadata and 2FA presence are read: never token or refresh hashes,
//! client secrets, TOTP secrets or recovery codes. Open to every active account,
//! never admin-gated; the operator purge button keeps its cmdr_nova + admin rule.
//! PHP retains the setup and recovery-code states (request-only secrets), every
//! action and CSRF. Spaces stand where the PHP template has whitespace.
use super::*;
#[derive(Debug,Clone,Deserialize)]
#[serde(deny_unknown_fields)]
struct TokenRow {id:i64,scopes:Option<String>,created_at:Option<String>,expires_at:Option<String>,revoked_at:Option<String>,client_name:Option<String>}
pub(super) struct Tokens {rows:Vec<TokenRow>,operator:bool}
pub(super) const MAX_TOKENS:usize=40;
pub(super) async fn tokens(db:&tokio_postgres::Client,owner:i64,now:i64,viewer_admin:bool)->Result<Tokens>{
 let key=db.query_one("SELECT COALESCE(actor_key::text,'cmdr_nova') FROM ap_users WHERE id::bigint=$1",&[&owner]).await?.get::<_,String>(0);
 // ap_db_cmdr_nova_user_id: the cmdr_nova row, else 1.
 let cmdr=db.query_opt("SELECT id::bigint FROM ap_users WHERE actor_key='cmdr_nova' LIMIT 1",&[]).await?.map(|r|r.get::<_,i64>(0)).filter(|id|*id>=1).unwrap_or(1);
 // gmdate('c') cutoff compared as text, exactly as PHP binds it.
 let cutoff=chrono::DateTime::from_timestamp(now-7*86400,0).ok_or_else(||anyhow::anyhow!("date range"))?.format("%Y-%m-%dT%H:%M:%S+00:00").to_string();
 let owner_sql=if owner==cmdr{"(t.user_id::bigint=$2 OR t.user_id IS NULL)"}else{"t.user_id::bigint=$2"};
 let sql=format!("SELECT jsonb_build_object('id',t.id::bigint,'scopes',t.scopes::text,'created_at',t.created_at::text,'expires_at',t.expires_at::text,'revoked_at',t.revoked_at::text,'client_name',a.client_name::text) FROM oauth_tokens t LEFT JOIN oauth_apps a ON a.client_id=t.client_id WHERE (t.revoked_at IS NULL OR t.revoked_at>=$1) AND {owner_sql} ORDER BY t.id DESC LIMIT {MAX_TOKENS}");
 let mut rows=Vec::new();
 for row in db.query(&sql,&[&cutoff,&owner]).await?{rows.push(serde_json::from_value::<TokenRow>(row.get(0))?);}
 Ok(Tokens{rows,operator:key=="cmdr_nova"&&viewer_admin})
}
/// strtotime for gmdate('c') and offset-bearing Postgres text. A zoneless date would
/// follow PHP's default timezone, so it and anything else is PHP's to judge.
fn timestamp(s:&str)->Result<i64>{
 chrono::DateTime::parse_from_rfc3339(s).map(|v|v.timestamp())
  .or_else(|_|chrono::DateTime::parse_from_str(s,"%Y-%m-%d %H:%M:%S%.f%#z").map(|v|v.timestamp()))
  .map_err(|_|anyhow::anyhow!("unproven PHP date format"))
}
pub(super) fn paint_tokens(t:Tokens,r:&Request)->Result<(String,usize,Value)>{
 let revoked=t.rows.iter().filter(|x|!empty(x.revoked_at.as_deref().unwrap_or(""))).count();
 let mut h=String::from(r#"<div class="tweet" style="margin-top:.5rem"> <div class="who">Your OAuth tokens</div> <div class="body meta" style="margin-top:.5rem"> Bearer tokens are stored hashed. Revoke if a phone is lost or an app looks suspicious. Revoked rows auto-delete after <b>7 days</b>. </div> "#);
 if t.operator{h.push_str(&format!(r#"<div class="tweet-actions" style="margin-top:.75rem"> <form method="post" action="?view=security" style="display:inline" onsubmit="return confirm('Delete all revoked tokens from the database now? Active sessions stay.');"> <input type="hidden" name="action" value="purge_oauth_tokens"> <input type="hidden" name="purge_mode" value="revoked"> <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem"> Purge revoked now{} </button> </form> </div> "#,if revoked>0{format!(" ({revoked})")}else{String::new()}));}
 h.push_str("</div> ");
 let mut rows=Vec::new();
 if t.rows.is_empty(){h.push_str(r#"<div class="empty">No tokens yet. Authorize an app once to create one.</div>"#);}
 for x in &t.rows{
  let is_revoked=!empty(x.revoked_at.as_deref().unwrap_or(""));
  let exp=x.expires_at.as_deref().unwrap_or("");
  let expired=!empty(exp)&&timestamp(exp)?<r.now;
  let status=if is_revoked{"revoked"}else if expired{"expired"}else{"active"};
  h.push_str(&format!(r#"<article class="tweet"> <div class="who">{} <span class="meta">#{}</span></div> <div class="body meta" style="margin-top:.35rem"> scopes <code>{}</code><br> created {} · expires {} "#,
   esc(x.client_name.as_deref().unwrap_or("unknown app")),x.id,esc(x.scopes.as_deref().unwrap_or("")),esc(x.created_at.as_deref().unwrap_or("")),esc(x.expires_at.as_deref().unwrap_or("n/a"))));
  if is_revoked{h.push_str(&format!("<br>revoked {} ",esc(x.revoked_at.as_deref().unwrap_or(""))));}
  h.push_str(&format!(r#"</div> <div class="tags" style="margin-top:.5rem"> <span class="tag">{status}</span> "#));
  if status=="active"{h.push_str(&format!(r#"<form method="post" action="?view=security" style="display:inline" onsubmit="return confirm('Revoke this token? The app will need to re-authorize.');"> <input type="hidden" name="action" value="revoke_oauth_token"> <input type="hidden" name="token_id" value="{}"> <button class="btn" type="submit">Revoke</button> </form> "#,x.id));}
  h.push_str("</div> </article> ");
  rows.push(json!({"id":x.id,"status":status}));
 }
 mojibake_guard(&h)?;
 Ok((h,rows.len(),Value::Array(rows)))
}
pub(super) async fn two_factor(db:&tokio_postgres::Client,owner:i64)->Result<bool>{
 // PHP trim() set, minus NUL (PostgreSQL text cannot hold it). The secret itself is never selected.
 Ok(db.query_opt(r"SELECT btrim(COALESCE(secret_enc::text,''),E' \t\n\r\x0B')<>'' AND btrim(COALESCE(enabled_at::text,''),E' \t\n\r\x0B')<>'' FROM ap_user_2fa WHERE user_id::bigint=$1 LIMIT 1",&[&owner]).await?.is_some_and(|r|r.get::<_,bool>(0)))
}
pub(super) fn paint_two_factor(enabled:bool,r:&Request)->Result<(String,usize,Value)>{
 let csrf=esc(&r.csrf);
 let h=if enabled{format!(r#"<div class="tweet" style="margin-bottom:1.25rem"> <div class="who">Two-factor authentication <span class="tag">enabled</span></div> <div class="body meta" style="margin-top:.5rem">Web logins require an authenticator code or a one-time recovery code.</div> <form method="post" action="?view=security" style="margin-top:.75rem" autocomplete="off"> <input type="hidden" name="action" value="2fa_disable"> <input type="hidden" name="csrf" value="{csrf}"> <input type="password" name="password" required autocomplete="current-password" placeholder="Current password"> <input name="code" required inputmode="numeric" autocomplete="one-time-code" placeholder="Authenticator or recovery code" style="margin-top:.5rem"> <button class="btn btn-ghost" type="submit" style="margin-top:.65rem">Disable 2FA</button> </form> </div>"#)}
 else{format!(r#"<div class="tweet" style="margin-bottom:1.25rem"> <div class="who">Two-factor authentication</div> <div class="body meta" style="margin-top:.5rem">Protect web logins with a TOTP authenticator app. API and Ice Cubes tokens remain independent.</div> <form method="post" action="?view=security" style="margin-top:.75rem"> <input type="hidden" name="action" value="2fa_begin_setup"> <input type="hidden" name="csrf" value="{csrf}"> <button class="btn btn-primary" type="submit">Set up 2FA</button> </form> </div>"#)};
 Ok((h,1,json!([{"panel":"two_factor","enabled":enabled}])))
}
/// h() runs ap_fix_utf8 on every value it prints; text it would repair stays PHP.
fn mojibake_guard(h:&str)->Result<()>{
 static RE:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"â.|Ã.|Â.|ðŸ").unwrap());
 if RE.is_match(h){bail!("PHP would repair printed text");}Ok(())
}
#[cfg(test)]mod tests{
 use super::*;
 #[test]fn stored_token_dates_parse_or_refuse(){
  let t=timestamp("2026-10-20T00:00:00+00:00").unwrap();
  for s in["2026-10-20 00:00:00+00","2026-10-20 02:00:00.5+02","2026-10-20T00:00:00Z"]{assert_eq!(timestamp(s).unwrap(),t,"{s}");}
  for s in["2026-10-20 00:00:00","next week",""]{assert!(timestamp(s).is_err(),"{s}");}
 }
}
