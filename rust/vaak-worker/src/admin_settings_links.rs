//! Settings Second Life and World of Warcraft panels copied from ap_sl_link_for_user,
//! the profile view's pending-challenge query, ap_sl_profile_url, ap_wow_link_for_user
//! and the two panel templates. Rows are scoped to the session owner; the page is open
//! to every active account, never admin-gated. The challenge code hash is never read.
//! PHP retains the link, verify, resend and unlink actions and CSRF injection.
//! Spaces stand where the PHP template has whitespace.
use super::*;
pub(super) struct Sl {username:String,agent_id:String,image:String,verified_at:String}
pub(super) struct Pending {username:String,agent_id:String,expires_at:String}
pub(super) struct Wow {name:String,realm:String,armory:String,portrait:String}
pub(super) struct Collected {sl:Option<Sl>,pending:Option<Pending>,wow:Option<Wow>}
fn text(row:&tokio_postgres::Row,i:usize)->String{row.get::<_,Option<String>>(i).unwrap_or_default()}
/// A missing table (PHP catches and shows the unlinked panel) bails to PHP instead.
pub(super) async fn collect(db:&tokio_postgres::Client,owner:i64,now:i64)->Result<Collected>{
 let sl=db.query_opt("SELECT sl_username::text,sl_agent_id::text,sl_profile_image_url::text,verified_at::text FROM ap_sl_links WHERE owner_user_id::bigint=$1 LIMIT 1",&[&owner]).await?
  .map(|r|Sl{username:text(&r,0),agent_id:text(&r,1),image:text(&r,2),verified_at:text(&r,3)});
 // gmdate('c') compared as text, exactly as PHP binds it.
 let at=chrono::DateTime::from_timestamp(now,0).ok_or_else(||anyhow::anyhow!("date range"))?.format("%Y-%m-%dT%H:%M:%S+00:00").to_string();
 let pending=if sl.is_some(){None}else{db.query_opt("SELECT sl_username::text,sl_agent_id::text,expires_at::text FROM ap_sl_challenges WHERE owner_user_id::bigint=$1 AND expires_at>=$2 ORDER BY id DESC LIMIT 1",&[&owner,&at]).await?
  .map(|r|Pending{username:text(&r,0),agent_id:text(&r,1),expires_at:text(&r,2)})};
 let wow=db.query_opt("SELECT character_name::text,realm::text,armory_url::text,portrait_url::text FROM ap_wow_links WHERE owner_user_id::bigint=$1 LIMIT 1",&[&owner]).await?
  .map(|r|Wow{name:text(&r,0),realm:text(&r,1),armory:text(&r,2),portrait:text(&r,3)});
 Ok(Collected{sl,pending,wow})
}
fn sl_profile_url(agent:&str)->String{
 static RE:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"(?i)^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$").unwrap());
 let agent=crate::library::card::php_trim(agent).to_ascii_lowercase();
 if RE.is_match(&agent){format!("https://world.secondlife.com/resident/{agent}")}else{"https://secondlife.com/".into()}
}
pub(super) fn paint(c:Collected,r:&Request)->Result<(String,usize,Value)>{
 let mut h=String::from(r#"<div class="composer" style="margin-top:1.25rem"> <div class="meta" style="margin-bottom:.75rem"> <b style="color:var(--primary)">Second Life avatar</b><br> Prove you own an SL avatar the Primfeed way: we IM you a one-time code in-world. Use your <b>username</b> (account name like <code>first.last</code>), not your display name. </div> "#);
 let sl_state=if let Some(sl)=&c.sl{
  h.push_str(r#"<div style="display:flex;gap:.75rem;align-items:center;margin-bottom:.75rem;flex-wrap:wrap"> "#);
  if !empty(&sl.image){h.push_str(&format!(r#"<img src="{}" alt="" width="48" height="48" style="border-radius:10px;object-fit:cover;border:1px solid var(--border)" loading="lazy" referrerpolicy="no-referrer"> "#,esc(&sl.image)));}
  h.push_str(&format!(r#"<div> <div class="who">{}</div> <div class="meta"> Linked {} · <a href="{}" target="_blank" rel="noopener noreferrer">SL profile</a> · <span class="mono" style="font-size:.72rem">{}</span> </div> </div> </div> <form method="post" action="?view=profile" onsubmit="return confirm('Unlink this Second Life avatar from your VAAK account?');"> <input type="hidden" name="action" value="sl_link_unlink"> <div class="composer-actions"> <span class="meta">One avatar per VAAK account</span> <button class="btn btn-ghost" type="submit" style="color:var(--danger)">Unlink</button> </div> </form> "#,
   esc(&sl.username),esc(&relative(&sl.verified_at,r.now)?),esc(&sl_profile_url(&sl.agent_id)),esc(&sl.agent_id)));
  "linked"
 }else{
  if let Some(p)=&c.pending{
   h.push_str(&format!(r#"<div class="meta" style="margin-bottom:.75rem;color:var(--primary)"> Code sent for <code>{}</code> — check Second Life IMs, then enter it below (expires {}). </div> <form method="post" action="?view=profile" style="margin-bottom:1rem"> <input type="hidden" name="action" value="sl_link_verify"> <label for="sl-code">Verification code</label> <input id="sl-code" type="text" name="sl_code" required maxlength="16" autocomplete="one-time-code" placeholder="e.g. A7K3MQ" style="text-transform:uppercase;letter-spacing:.08em"> <div class="composer-actions"> <span class="meta">From the VAAK verifier object IM</span> <button class="btn btn-primary" type="submit">Confirm link</button> </div> </form> "#,
    esc(&p.username),esc(&relative(&p.expires_at,r.now)?)));
  }
  let (user,agent)=c.pending.as_ref().map(|p|(p.username.as_str(),p.agent_id.as_str())).unwrap_or(("",""));
  h.push_str(&format!(r#"<form method="post" action="?view=profile"> <input type="hidden" name="action" value="sl_link_start"> <label for="sl-username">Second Life username</label> <input id="sl-username" type="text" name="sl_username" required maxlength="64" placeholder="first.last or First Last" value="{}"> <details style="margin-top:.5rem"> <summary class="meta" style="cursor:pointer">Advanced: paste agent UUID</summary> <label for="sl-agent" class="meta">Agent UUID (skips Name2Key if set)</label> <input id="sl-agent" type="text" name="sl_agent_id" maxlength="36" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" value="{}"> </details> <div class="composer-actions"> <span class="meta">Requires object IMs enabled in SL preferences</span> <button class="btn btn-primary" type="submit">{}</button> </div> </form> "#,
   esc(user),esc(agent),if c.pending.is_some(){"Resend code"}else{"Send code in-world"}));
  if c.pending.is_some(){"pending"}else{"unlinked"}
 };
 h.push_str(r#"</div> <div class="composer" style="margin-top:1.25rem"> <div class="meta" style="margin-bottom:.75rem"> <b style="color:var(--primary)">World of Warcraft character</b><br> Link an official Armory character. VAAK stores the Armory URL and portrait so public profiles load quickly. </div> "#);
 let wow_state=if let Some(w)=&c.wow{
  h.push_str(&format!(r#"<div style="display:flex;gap:.75rem;align-items:center;margin-bottom:.75rem;flex-wrap:wrap"> <img src="{}" alt="" width="48" height="48" style="border-radius:10px;object-fit:cover;border:1px solid var(--border)" loading="lazy" referrerpolicy="no-referrer"> <div> <div class="who">{}</div> <div class="meta">{} · <a href="{}" target="_blank" rel="noopener noreferrer">Armory profile</a></div> </div> </div> <form method="post" action="?view=profile" onsubmit="return confirm('Unlink this World of Warcraft character?');"> <input type="hidden" name="action" value="wow_link_unlink"> <div class="composer-actions"> <span class="meta">One character per VAAK account</span> <button class="btn btn-ghost" type="submit" style="color:var(--danger)">Unlink</button> </div> </form> "#,
   esc(&w.portrait),esc(&w.name),esc(&w.realm),esc(&w.armory)));
  "linked"
 }else{
  h.push_str(r#"<form method="post" action="?view=profile"> <input type="hidden" name="action" value="wow_link_save"> <label for="wow-armory-url">Armory character URL</label> <input id="wow-armory-url" type="url" name="wow_armory_url" required maxlength="500" placeholder="https://worldofwarcraft.blizzard.com/en-us/worldsoul/us/armory/character/realm/name"> <label for="wow-portrait-url">Portrait URL <span class="meta">(optional override)</span></label> <input id="wow-portrait-url" type="url" name="wow_portrait_url" maxlength="500" placeholder="https://render.worldofwarcraft.com/…-avatar.jpg"> <div class="composer-actions"> <span class="meta">The portrait is discovered automatically from the public Armory page when possible.</span> <button class="btn btn-primary" type="submit">Link character</button> </div> </form> "#);
  "unlinked"
 };
 h.push_str("</div>");
 // h() runs ap_fix_utf8 on every value it prints; text it would repair stays PHP.
 static MOJIBAKE:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"â.|Ã.|Â.|ðŸ").unwrap());
 if MOJIBAKE.is_match(&h){bail!("PHP would repair printed text");}
 Ok((h,2,json!([{"panel":"second_life","state":sl_state},{"panel":"wow","state":wow_state}])))
}
#[cfg(test)]mod tests{
 use super::*;
 #[test]fn profile_url_needs_a_uuid(){
  assert_eq!(sl_profile_url(" 0F8FAD5B-D9CB-469F-A165-70867728950E "),"https://world.secondlife.com/resident/0f8fad5b-d9cb-469f-a165-70867728950e");
  for s in["","not-a-uuid","0f8fad5b-d9cb-469f-a165-70867728950"]{assert_eq!(sl_profile_url(s),"https://secondlife.com/");}
 }
}
