//! Settings AI alt-text panel copied from ap_auth_user_has_xai_key and the profile
//! view's AI alt-text section. Only the key's presence is read, never the encrypted
//! key; the API base URL and model are the owner's own optional, non-secret values.
//! Open to every active account, never admin-gated. PHP retains save_ai_key and
//! clear_ai_key. Spaces stand where the PHP template has whitespace.
use super::*;
use crate::library::card::php_trim;
pub(super) struct Collected {configured:bool,root:String,model:String}
pub(super) async fn collect(db:&tokio_postgres::Client,owner:i64)->Result<Collected>{
 let row=db.query_one("SELECT COALESCE(xai_api_key_enc::text,'')<>'',COALESCE(ai_api_root::text,''),COALESCE(ai_model::text,'') FROM ap_users WHERE id::bigint=$1 AND disabled_at IS NULL",&[&owner]).await?;
 Ok(Collected{configured:row.get(0),root:php_trim(&row.get::<_,String>(1)).to_string(),model:php_trim(&row.get::<_,String>(2)).to_string()})
}
pub(super) fn paint(c:Collected,r:&Request)->Result<(String,usize,Value)>{
 const MASK:&str="••••••••••••";
 let csrf=esc(&r.csrf);
 let mut h=format!(r#"<h2 style="font-size:1rem;margin:2rem 0 .5rem">AI alt-text settings</h2> <div class="meta" style="margin-bottom:.75rem"> Your OpenAI-compatible API key for image descriptions (Chat Completions vision). Stored encrypted <b>on your account only</b> — never shown again after save, never shared with other users. Status: <b>{}</b> "#,if c.configured{"configured"}else{"not configured"});
 if !c.configured{h.push_str("· add your own key to use AI describe ");}
 let (value,attrs,blur)=if c.configured{(MASK,r#" data-existing-mask="1" aria-label="Saved API key, masked. Focus to replace it.""#,MASK)}else{("",r#" required"#,"")};
 h.push_str(&format!(r#"</div> <form class="composer" method="post" action="?view=profile" style="margin-bottom:.75rem" autocomplete="off"> <input type="hidden" name="action" value="save_ai_key"> <input type="hidden" name="csrf" value="{csrf}"> <label class="meta" style="display:block;margin-bottom:.35rem">API key</label> <input name="ai_api_key" type="password" maxlength="500" placeholder="sk-… or provider key" autocomplete="new-password" style="margin-bottom:.65rem" value="{value}"{attrs} onfocus="if(this.dataset.existingMask){{this.value='';delete this.dataset.existingMask;}}" onblur="if(this.value===''){{this.value='{blur}';this.dataset.existingMask='1';}}"> <label class="meta" style="display:block;margin-bottom:.35rem">API base URL <span style="opacity:.7">(optional)</span></label> <input name="ai_api_root" type="url" maxlength="300" placeholder="https://api.openai.com/v1" value="{}" style="margin-bottom:.65rem"> <label class="meta" style="display:block;margin-bottom:.35rem">Model <span style="opacity:.7">(optional)</span></label> <input name="ai_model" type="text" maxlength="120" placeholder="gpt-4o-mini" value="{}" style="margin-bottom:.65rem"> <div class="composer-actions"> <span class="meta">Key replaces any existing personal key; blank base/model clears those fields</span> <button class="btn btn-primary" type="submit">Save</button> </div> </form> "#,esc(&c.root),esc(&c.model)));
 if c.configured{h.push_str(&format!(r#"<form method="post" action="?view=profile" style="margin-bottom:1.25rem" onsubmit="return confirm('Clear your personal AI API key?');"> <input type="hidden" name="action" value="clear_ai_key"> <input type="hidden" name="csrf" value="{csrf}"> <button class="btn btn-ghost" type="submit">Clear API key</button> </form>"#));}
 // h() runs ap_fix_utf8 on every value it prints; text it would repair stays PHP.
 static MOJIBAKE:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"â.|Ã.|Â.|ðŸ").unwrap());
 if MOJIBAKE.is_match(&h){bail!("PHP would repair printed text");}
 Ok((h,1,json!([{"panel":"ai_alt_text","configured":c.configured}])))
}
#[cfg(test)]mod tests{
 use super::*;
 fn req()->Request{serde_json::from_value(json!({"protocol_version":1,"owner_id":2,"csrf":"t<&>","view":"settings_ai","mode":"full","offset":0,"approvals_enabled":false,"now":100,"php_timezone":"UTC","viewer_admin":false})).unwrap()}
 #[test]fn presence_never_paints_a_key(){
  let (on,_,rows)=paint(Collected{configured:true,root:String::new(),model:"m".into()},&req()).unwrap();
  assert!(on.contains("Clear API key")&&on.contains("data-existing-mask")&&on.contains("t&lt;&amp;&gt;"));assert_eq!(rows,json!([{"panel":"ai_alt_text","configured":true}]));
  let (off,_,_)=paint(Collected{configured:false,root:String::new(),model:String::new()},&req()).unwrap();
  assert!(!off.contains("Clear API key")&&off.contains(r#"value="" required"#));
 }
}
