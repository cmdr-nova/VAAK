//! Policies read page copied from ap_instance_doc_get, ap_instance_rules_list and
//! the ap-admin Policies branch. PHP retains the editors' saves, CSRF injection
//! and the built-in default texts: a missing document or an empty rule list
//! renders the PHP page instead. Spaces stand where the PHP template has
//! whitespace; textarea bodies are copied exactly.
use super::*;
use crate::library::card::php_trim;
#[derive(Debug,Clone,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct Doc {doc_key:String,title:String,body:String,updated_at:Option<String>}
#[derive(Debug,Clone,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct Rule {id:i64,text:String}
pub(super) struct Collected {privacy:Doc,conduct:Doc,rules:Vec<Rule>}
async fn doc(db:&tokio_postgres::Client,key:&str)->Result<Doc>{
 let Some(row)=db.query_opt("SELECT jsonb_build_object('doc_key',doc_key::text,'title',COALESCE(title::text,''),'body',COALESCE(body::text,''),'updated_at',updated_at::text) FROM ap_instance_docs WHERE doc_key=$1",&[&key]).await? else {bail!("PHP default document")};
 let mut d:Doc=serde_json::from_value(row.get(0))?;
 if empty(&d.title){d.title=if key=="conduct"{"Code of Conduct"}else{"Privacy Policy"}.into();}
 Ok(d)
}
pub(super) async fn collect(db:&tokio_postgres::Client)->Result<Collected>{
 let privacy=doc(db,"privacy").await?;let conduct=doc(db,"conduct").await?;
 let mut rules=Vec::new();
 for row in db.query("SELECT jsonb_build_object('id',id::bigint,'text',COALESCE(text::text,'')) FROM ap_instance_rules ORDER BY sort_order ASC, id ASC LIMIT 1001",&[]).await?{
  let mut rule:Rule=serde_json::from_value(row.get(0))?;let text=php_trim(&rule.text);if text.is_empty(){continue;}rule.text=text.to_string();rules.push(rule);
 }
 if rules.is_empty(){bail!("PHP default rules");}
 if rules.len()>100{bail!("rule rows beyond bridge bound");}
 Ok(Collected{privacy,conduct,rules})
}
fn updated(d:&Doc)->String{match d.updated_at.as_deref(){Some(u) if !empty(u)=>format!("Updated {}",esc(u)),_=>"Not saved yet".into()}}
pub(super) fn paint(c:Collected)->Result<(String,usize,Value)>{
 let rules_text=c.rules.iter().map(|r|r.text.as_str()).collect::<Vec<_>>().join("\n");
 let h=format!(r#"<div class="meta" style="margin-bottom:1rem"> Public pages (VAAK-branded): <a href="/vaak/privacy/" target="_blank" rel="noopener">/vaak/privacy/</a> · <a href="/vaak/conduct/" target="_blank" rel="noopener">/vaak/conduct/</a>. Rules also appear in Mastodon-compatible apps via the instance API. Site blog privacy remains at <a href="/pages/privacy/" target="_blank" rel="noopener">/pages/privacy/</a>. </div> <form class="composer" method="post" action="?view=policies" style="margin-bottom:1.25rem"> <input type="hidden" name="action" value="policies_save_privacy"> <div class="meta" style="margin-bottom:.45rem"><strong>Privacy Policy</strong></div> <input name="title" type="text" maxlength="120" value="{}" placeholder="Title"> <textarea name="body" rows="12" placeholder="Privacy policy body (plain text; blank lines = paragraphs)" style="width:100%;margin-top:.55rem;min-height:12rem">{}</textarea> <div class="composer-actions"> <span class="meta">{}</span> <button class="btn btn-primary" type="submit">Save privacy</button> </div> </form> <form class="composer" method="post" action="?view=policies" style="margin-bottom:1.25rem"> <input type="hidden" name="action" value="policies_save_conduct"> <div class="meta" style="margin-bottom:.45rem"><strong>Code of Conduct</strong> (intro text above the numbered rules)</div> <input name="title" type="text" maxlength="120" value="{}" placeholder="Title"> <textarea name="body" rows="8" placeholder="Code of conduct intro" style="width:100%;margin-top:.55rem;min-height:8rem">{}</textarea> <div class="composer-actions"> <span class="meta">{}</span> <button class="btn btn-primary" type="submit">Save code of conduct</button> </div> </form> <form class="composer" method="post" action="?view=policies"> <input type="hidden" name="action" value="policies_save_rules"> <div class="meta" style="margin-bottom:.45rem"><strong>Server rules</strong> — one rule per line (shown numbered on /vaak/conduct/ and in apps)</div> <textarea name="rules_text" rows="10" placeholder="One rule per line" style="width:100%;min-height:10rem;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.85rem">{}</textarea> <div class="composer-actions"> <span class="meta">{} rules</span> <button class="btn btn-primary" type="submit">Save rules</button> </div> </form> "#,
  esc(&c.privacy.title),esc(&c.privacy.body),updated(&c.privacy),esc(&c.conduct.title),esc(&c.conduct.body),updated(&c.conduct),esc(&rules_text),c.rules.len());
 let count=c.rules.len();
 Ok((h,count,serde_json::to_value(c.rules)?))
}
