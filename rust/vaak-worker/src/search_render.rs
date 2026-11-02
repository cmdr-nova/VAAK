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
 let display=if crate::library::card::boolean(acc,"display_name"){text(acc,"display_name")}else{text(acc,"username")};
 let open=if url.is_empty(){target.as_str()}else{url};
 let avatar=["avatar","avatar_static"].iter().filter_map(|k|acc.get(*k).and_then(Value::as_str)).map(|u|u.trim_matches(PHP_TRIM)).find(|u|u.starts_with("https://")).unwrap_or(DEFAULT_AVATAR);
 let bsky=target.contains("bsky.app/profile/")||open.contains("bsky.app/profile/")||target.starts_with("did:");
 let mut out=format!("<article class=\"search-account\"><a class=\"search-account-av\" href=\"{href}\" tabindex=\"-1\" aria-hidden=\"true\"><img src=\"{}\" alt=\"\" width=\"44\" height=\"44\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\" onerror=\"this.onerror=null;this.src='{DEFAULT_AVATAR}'\"><span class=\"search-net search-net-{}\" title=\"{}\"></span></a><div class=\"search-account-who\"><div class=\"search-account-name\"><a href=\"{href}\">{}{}</a>",
  esc(avatar),if bsky{"bsky"}else{"fedi"},if bsky{"Bluesky"}else{"Fediverse"},crate::library::body::emojis(display,&facts.emoji,true),
  if acc.get("bot")==Some(&Value::Bool(true)){" 🤖"}else{""},href=esc(&facts.profile_href));
 if facts.is_me {out.push_str(" <span class=\"tag\">me</span>");}
 let followers=followers_label(acc.get("followers_count"));
 out.push_str(&format!("</div><div class=\"search-account-handle\">@{}{}</div>",esc(&acct),if followers.is_empty(){String::new()}else{format!(" · {followers}")}));
 let bio=bio(acc);
 if !bio.is_empty(){out.push_str(&format!("<div class=\"search-account-bio\">{bio}</div>"));}
 out.push_str("</div><div class=\"search-account-act\">");
 if facts.is_me {out.push_str(&format!("<a class=\"btn btn-ghost btn-compact\" href=\"{}\">Your profile</a>",esc(&facts.profile_href)));}
 else if !target.is_empty(){
  let locked=acc.get("locked")==Some(&Value::Bool(true));
  out.push_str(&format!("<form method=\"post\" action=\"?view=search\"{}><input type=\"hidden\" name=\"action\" value=\"{}\"><input type=\"hidden\" name=\"return_view\" value=\"search\"><input type=\"hidden\" name=\"actor_id\" value=\"{}\">{}</form><a class=\"search-account-open\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\" title=\"{label}\" aria-label=\"{label}\">↗</a>",
   if locked{" data-follow-locked=\"1\""}else{""},if facts.following{"unfollow_remote"}else{"follow_remote"},esc(&target),
   if facts.following{"<button class=\"btn btn-ghost btn-compact btn-following\" type=\"submit\" title=\"Following — click to unfollow\">Following</button>"}else{"<button class=\"btn btn-primary btn-compact\" type=\"submit\">Follow</button>"},
   esc(open),label=esc(&facts.open_label)));
 }
 out.push_str("</div></article>");out
}

const DEFAULT_AVATAR:&str="https://mkultra.monster/img/avatar/default.webp";
/// PHP trim()'s default character set.
const PHP_TRIM:&[char]=&[' ','\t','\n','\r','\0','\x0B'];

/// admin_search_account_bio: tags become spaces, whitespace collapses, cut at
/// 200 characters. Entities stay as written; only < > " are escaped.
fn bio(acc:&Value)->String {
 let Some(note)=acc.get("note").and_then(Value::as_str) else {return String::new()};
 static TAGS:std::sync::OnceLock<regex::Regex>=std::sync::OnceLock::new();
 static WS:std::sync::OnceLock<regex::Regex>=std::sync::OnceLock::new();
 static ENT:std::sync::OnceLock<regex::Regex>=std::sync::OnceLock::new();
 let text=TAGS.get_or_init(||regex::Regex::new(r"<[^>]*>").unwrap()).replace_all(note," ");
 let text=WS.get_or_init(||regex::Regex::new(r"[ \t\r\n\x0C\x0B]+").unwrap()).replace_all(&text," ");
 let mut text=text.trim_matches(' ').to_owned();
 if text.chars().count()>200 {
  let cut:String=text.chars().take(200).collect();
  let cut=ENT.get_or_init(||regex::Regex::new(r"&[#A-Za-z0-9]*$").unwrap()).replace(&cut,"").trim_end_matches(' ').to_owned();
  text=format!("{cut}…");
 }
 text.replace('<',"&lt;").replace('>',"&gt;").replace('"',"&quot;")
}

/// admin_search_followers_label. Tenths are floored, never rounded.
fn followers_label(count:Option<&Value>)->String {
 let n:i64=match count {
  Some(Value::Number(n))=>n.as_i64().unwrap_or(0),
  Some(Value::String(s)) if !s.is_empty()&&s.bytes().all(|b|b.is_ascii_digit())=>s.parse().unwrap_or(0),
  _=>0,
 };
 if n<1 {return String::new()}
 let short=if n<1000 {n.to_string()} else {
  let (div,unit)=if n<1_000_000{(100,"k")}else{(100_000,"M")};let tenths=n/div;
  format!("{}{}{unit}",tenths/10,if tenths%10!=0{format!(".{}",tenths%10)}else{String::new()})
 };
 format!("{short}{}",if n==1{" follower"}else{" followers"})
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
 let mut out=format!("<div class=\"search-tag{}\"><a class=\"search-tag-link\" href=\"?view=search&amp;q={}&amp;type=statuses\" data-vaak-soft-nav=\"search\" data-search-q=\"{}\" data-search-type=\"statuses\">#{}</a>",
  if following{" is-following"}else{""},esc(&form_query(&format!("#{name}"))),esc(&format!("#{name}")),esc(name));
 if following {out.push_str("<a class=\"search-tag-state\" href=\"?view=tags\" title=\"You follow this tag. Manage followed tags\">following</a>");}
 else {out.push_str(&format!("<form method=\"post\" action=\"?view=tags\" data-search-tag-follow><input type=\"hidden\" name=\"action\" value=\"follow_tag\"><input type=\"hidden\" name=\"return_view\" value=\"tags\"><input type=\"hidden\" name=\"tag\" value=\"{n}\"><button class=\"search-tag-follow\" type=\"submit\" title=\"Follow #{n}\" aria-label=\"Follow #{n}\">+</button></form>",n=esc(name)));}
 out.push_str("</div>");out
}

/// Harness entry. Presentation only: account facts are the ones the PHP
/// painter already resolved. This does not admit or fetch anything.
pub(crate) fn parity_render(kind: &str, input: &Value, facts: Option<&Value>) -> Result<String, String> {
    match kind {
        "hashtag" => Ok(hashtag(input)),
        "summary" => {
            let counts = input.get("counts").and_then(Value::as_array).ok_or("summary counts")?;
            let more = input.get("more").and_then(Value::as_array).ok_or("summary more")?;
            if counts.len() != 3 || more.len() != 3 {
                return Err("summary counts/more must have length 3".into());
            }
            let mut count_arr = [0usize; 3];
            let mut more_arr = [false; 3];
            for i in 0..3 {
                count_arr[i] = counts[i].as_u64().ok_or("summary count")? as usize;
                more_arr[i] = more[i].as_bool().ok_or("summary more flag")?;
            }
            Ok(summary(text(input, "q"), count_arr, more_arr))
        }
        "account" => {
            let facts = facts.ok_or("account facts")?;
            let emoji = facts.get("emoji_map").and_then(Value::as_object).map(|map| {
                map.iter().filter_map(|(key, value)| value.as_str().map(|s| (key.clone(), s.to_string()))).collect()
            }).unwrap_or_default();
            Ok(account(input, &AccountFacts {
                is_me: facts.get("is_me").and_then(Value::as_bool).ok_or("is_me")?,
                following: facts.get("following").and_then(Value::as_bool).ok_or("following")?,
                profile_href: text(facts, "profile_href").into(),
                open_label: text(facts, "open_label").into(),
                emoji,
            }))
        }
        other => Err(format!("unsupported search render kind {other}")),
    }
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
  assert_eq!(checked,16,"mandatory captured cases");
 }
}
