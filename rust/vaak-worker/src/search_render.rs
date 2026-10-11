//! Search presentation components. No route or caller currently promotes them.
//! Raw entities and cached relationship bits are not admission proofs.
use crate::library::actions::{esc,query};
use serde_json::Value;

fn text<'a>(v:&'a Value,k:&str)->&'a str {v.get(k).and_then(Value::as_str).unwrap_or("")}
fn form_query(s:&str)->String {query(s).replace("%20","+").replace('~',"%7E")}

// Only current server relationship/owner/profile resolvers may supply these
// presentation facts. No Deserialize, HTTP constructor or cached permission bit.
struct AccountFacts {
 is_me:bool, following:bool, profile_href:String, open_label:String,
 emoji:std::collections::HashMap<String,String>,
}

fn account(acc:&Value,facts:&AccountFacts)->String {
 let uri=text(acc,"uri").trim_end_matches('/');let url=text(acc,"url").trim_end_matches('/');
 let mut target=if uri.is_empty(){url.to_owned()}else{uri.to_owned()};let mut acct=text(acc,"acct").to_owned();
 let local=regex::Regex::new(r"^https://mkultra\.monster/users/([A-Za-z0-9_]+)$").unwrap().captures(&target).map(|m|m[1].to_ascii_lowercase());
 if let Some(key)=&local {target=format!("https://mkultra.monster/users/{}",query(key));acct=acc.get("username").and_then(Value::as_str).unwrap_or(key).into();}
 let address=local.as_ref().map(|key|format!("https://mkultra.monster/vaak/users/{}",query(key))).unwrap_or_else(||if target.is_empty(){url.into()}else{target.clone()});
 let display=if crate::library::card::boolean(acc,"display_name"){text(acc,"display_name")}else{text(acc,"username")};
 let mut out=format!("<article class=\"tweet\"><div class=\"tweet-hd\"><div class=\"who\">{} <span class=\"meta\">@{}</span>",crate::library::body::emojis(display,&facts.emoji,true),esc(&acct));
 if facts.is_me {out.push_str(" <span class=\"tag\">me</span>");}else if facts.following {out.push_str(" <span class=\"tag\" title=\"You follow them\">following</span>");}
 out.push_str(&format!("</div></div><div class=\"mono\">{}</div><div class=\"tweet-actions\">",esc(&address)));
 if facts.is_me {out.push_str(&format!("<span class=\"tag\">me</span> <a href=\"{}\">Your profile</a>",esc(&facts.profile_href)));}
 else if !target.is_empty(){
  out.push_str(&format!("<a href=\"{}\">Profile</a> ",esc(&facts.profile_href)));
  if facts.following {out.push_str("<span class=\"tag\">following</span> ");}else {out.push_str(&format!("<form method=\"post\" action=\"?view=search\" style=\"display:inline\"><input type=\"hidden\" name=\"action\" value=\"follow_remote\"><input type=\"hidden\" name=\"return_view\" value=\"search\"><input type=\"hidden\" name=\"actor_id\" value=\"{}\"><button class=\"btn btn-primary\" type=\"submit\" style=\"padding:.35rem .9rem;font-size:.85rem\">Follow</button></form> ",esc(&target)));}
  out.push_str(&format!("<a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">{}</a>",esc(if url.is_empty(){&target}else{url}),esc(&facts.open_label)));
 }
 out.push_str("</div></article>");out
}

/// Runtime component entry: source and current facts are inseparably bound by
/// the server-only resolver. Admission is a separate Search context boundary.
pub(crate) fn render_account(resolved:&crate::search_components::ResolvedSearchAccount)->String {
 account(resolved.account(),&AccountFacts {is_me:resolved.is_me(),following:resolved.following(),
  profile_href:resolved.profile_href().into(),open_label:resolved.open_label().into(),emoji:resolved.emoji().clone()})
}
pub(crate) fn render_tag(resolved:&crate::search_components::ResolvedSearchTag)->String {hashtag(resolved.tag())}

fn hashtag(tag:&Value)->String {
 let name=text(tag,"name");if name.is_empty(){return String::new()}
 let following=crate::library::card::boolean(tag,"following");
 let mut out=format!("<article class=\"tweet\"><div class=\"tweet-hd\"><div class=\"who\">#{}</div>",esc(name));
 if following {out.push_str("<span class=\"tag\">following</span>");}
 out.push_str(&format!("</div><div class=\"tweet-actions\"><a href=\"?view=search&amp;q={}&amp;type=statuses\" data-vaak-soft-nav=\"search\" data-search-q=\"{}\" data-search-type=\"statuses\">View posts</a> ",esc(&form_query(&format!("#{name}"))),esc(&format!("#{name}"))));
 if following {out.push_str("<a class=\"btn btn-ghost\" href=\"?view=tags\" style=\"padding:.35rem .9rem;font-size:.85rem\">Manage</a>");}
 else {out.push_str(&format!("<form method=\"post\" action=\"?view=tags\" style=\"display:inline\"><input type=\"hidden\" name=\"action\" value=\"follow_tag\"><input type=\"hidden\" name=\"return_view\" value=\"tags\"><input type=\"hidden\" name=\"tag\" value=\"{}\"><button class=\"btn btn-primary\" type=\"submit\" style=\"padding:.35rem .9rem;font-size:.85rem\">Follow</button></form>",esc(name)));}
 out.push_str("</div></article>");out
}

fn summary(q:&str,counts:[usize;3],more:[bool;3])->String {
 fn label(n:usize,more:bool,one:&str,many:&str)->String {format!("{n}{} {}",if more{"+"}else{""},if n==1&&!more{one}else{many})}
 format!("<div class=\"meta search-results-meta\" style=\"margin-bottom:.75rem\">Results for <b>{}</b> — {} · {} · {}</div>",esc(q),label(counts[0],more[0],"account","accounts"),label(counts[1],more[1],"tag","tags"),label(counts[2],more[2],"post","posts"))
}

#[cfg(test)]
mod tests {
 use super::*;
 #[test]
 fn actual_php_account_hashtag_and_summary_components_match(){
  let root=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../..");
  let result=std::process::Command::new(root.join("api/bin/php-policy-test")).arg(root.join("api/bin/search-render-reference-capture.php")).output().unwrap();
  assert!(result.status.success(),"actual PHP capture failed: {} {}",String::from_utf8_lossy(&result.stderr),String::from_utf8_lossy(&result.stdout));
  let corpus:Value=serde_json::from_slice(&result.stdout).unwrap();let mut checked=0;
  for (name,case) in corpus["captures"].as_object().unwrap(){
   let actual=match text(case,"kind") {
    "hashtag"=>hashtag(&case["input"]),
    "account"=>{let facts=&case["facts"];account(&case["input"],&AccountFacts {is_me:facts["is_me"].as_bool().unwrap(),following:facts["following"].as_bool().unwrap(),profile_href:text(facts,"profile_href").into(),open_label:text(facts,"open_label").into(),emoji:facts["emoji_map"].as_object().map(|map|map.iter().map(|(key,value)|(key.clone(),value.as_str().unwrap().into())).collect()).unwrap_or_default()})},
    "summary"=>{let input=&case["input"];summary(text(input,"q"),std::array::from_fn(|i|input["counts"][i].as_u64().unwrap() as usize),std::array::from_fn(|i|input["more"][i].as_bool().unwrap()))},
    _=>continue,
   };
   assert_eq!(actual,text(case,"html"),"{name}");checked+=1;
  }
  assert_eq!(checked,14,"mandatory captured cases");
 }
}
