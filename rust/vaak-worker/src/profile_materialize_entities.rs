//! Canonical Masto entity presentation helpers, kept separate from authority.
use super::*;
pub(super) fn encode(raw:&str)->String{let mut out=String::new();for b in raw.bytes(){if b.is_ascii_alphanumeric()||b"-_.~".contains(&b){out.push(b as char)}else{out.push_str(&format!("%{b:02X}"))}}out}
fn name(raw:&str)->String{let lowered=crate::library::card::php_trim(raw).trim_start_matches('#').to_lowercase();let allowed=regex::Regex::new(r"[^\p{L}\p{N}_]").unwrap();allowed.replace_all(&lowered,"").chars().take(100).collect()}
fn tag(raw:&str)->Value{let name=name(raw);json!({"url":format!("https://mkultra.monster/tags/{}",encode(&name)),"name":name})}
fn retain(tags:&mut Vec<Value>,value:Value){if s(&value,"name").is_empty(){return}if let Some(old)=tags.iter_mut().find(|old|old["name"]==value["name"]){*old=value}else{tags.push(value)}}
pub(super) fn escaped_tags(escaped:&str)->(String,Vec<Value>){
 let mut tags=vec![];let re=regex::Regex::new(r"(^|[^A-Za-z0-9_&/%])#([\p{L}\p{N}_]{1,100})").unwrap();
 let html=re.replace_all(escaped,|c:&regex::Captures|{let value=tag(&c[2]);if s(&value,"name").is_empty(){return c[0].into()}let url=crate::library::actions::esc(s(&value,"url"));retain(&mut tags,value);format!("{}<a href=\"{url}\" class=\"mention hashtag\" rel=\"tag\">#<span>{}</span></a>",&c[1],crate::library::actions::esc(&c[2]))}).into_owned();(html,tags)
}
fn protect(input:&str,re:&regex::Regex,hold:&mut Vec<(String,String)>,kind:&str)->String{re.replace_all(input,|c:&regex::Captures|{let key=format!("\u{1}{kind}{}\u{1}",hold.len());hold.push((key.clone(),c[0].into()));key}).into_owned()}
fn restore(mut input:String,hold:&[(String,String)])->String{let mut keys=hold.iter().collect::<Vec<_>>();keys.sort_by_key(|(key,_)|std::cmp::Reverse(key.len()));for(key,value)in keys{input=input.replace(key,value)}input}
pub(super) fn html_tags(html:&str)->(String,Vec<Value>){
 if html.is_empty()||!html.contains('#'){return(html.into(),vec![])}
 if html.contains("class=\"mention hashtag\"")||html.contains("class='mention hashtag'"){
  let mut tags=vec![];for c in regex::Regex::new(r#"(?i)https://mkultra\.monster/tags/([^"\s]+)"#).unwrap().captures_iter(html){retain(&mut tags,tag(&crate::hidden::raw_percent_decode(&c[1])))}
  if tags.is_empty(){let plain=crate::home_hydrate_ranked::html_entity_decode(&strip_html(html));for c in regex::Regex::new(r"#([\p{L}\p{N}_]{1,100})").unwrap().captures_iter(&plain){retain(&mut tags,tag(&c[1]))}}
  return(html.into(),tags)
 }
 let mut hold=vec![];let protected=protect(html,&regex::Regex::new(r"(?is)<a\b[^>]*>.*?</a>").unwrap(),&mut hold,"A");let protected=protect(&protected,&regex::Regex::new(r"(?i)https?://[^\s<]+").unwrap(),&mut hold,"U");let(html,tags)=escaped_tags(&protected);(restore(html,&hold),tags)
}
pub(super) fn plain_pack(raw:&str)->Result<Value>{
 let text=crate::home_hydrate_ranked::html_entity_decode(raw);
 if text.contains('@'){bail!("PHP fallback: canonical mention SQL resolver remains unfinished")}
 render_pack(&text,&[])
}
fn render_pack(text:&str,mentions:&[Value])->Result<Value>{
 let mut escaped=crate::library::actions::esc(text);let mut mention_hold=vec![];
 let mut sorted=mentions.to_vec();sorted.sort_by_key(|m|std::cmp::Reverse(s(m,"acct").len()));
 for m in &sorted{let acct=s(m,"acct");if let Some((user,host))=acct.split_once('@'){let user=if user.is_empty(){s(m,"username")}else{user};let link=format!("<span class=\"h-card\"><a href=\"{}\" class=\"u-url mention\">@<span>{}</span>@<span>{}</span></a></span>",crate::library::actions::esc(s(m,"url")),crate::library::actions::esc(user),crate::library::actions::esc(host));escaped=escaped.replace(&format!("@{}",crate::library::actions::esc(acct)),&link)}}
 let card_re=regex::Regex::new(r#"(?s)<span class="h-card">.*?</span>"#).unwrap();
 escaped=protect(&escaped,&card_re,&mut mention_hold,"M");
 let mut by_user:Vec<(String,Option<Value>)>=vec![];
 for m in &sorted{let key=s(m,"username").to_ascii_lowercase();if let Some((_,old))=by_user.iter_mut().find(|(u,_)|u==&key){if let Some(previous)=old{if s(previous,"acct").contains('@')&&!s(m,"acct").contains('@'){*old=Some(m.clone())}else if previous["url"]!=m["url"]{*old=None}}}else{by_user.push((key,Some(m.clone())))}}
 for(_,m)in by_user{let Some(m)=m else{continue};let uname=crate::library::actions::esc(s(&m,"username"));let re=regex::Regex::new(&format!(r"(^|[^A-Za-z0-9_/])@{}",regex::escape(&uname)))?;let link=format!("<span class=\"h-card\"><a href=\"{}\" class=\"u-url mention\">@<span>{uname}</span></a></span>",crate::library::actions::esc(s(&m,"url")));let original=escaped.clone();escaped=re.replace_all(&original,|c:&regex::Captures|{let end=c.get(0).unwrap().end();if original[end..].chars().next().is_some_and(|ch|ch.is_ascii_alphanumeric()||"_.@".contains(ch)){c[0].into()}else{format!("{}{link}",&c[1])}}).into_owned()}
 escaped=protect(&escaped,&card_re,&mut mention_hold,"M");
 let mut tag_hold=vec![];escaped=protect(&escaped,&regex::Regex::new(r"<[^>]+>").unwrap(),&mut tag_hold,"T");let mut hold=vec![];
 let escaped=regex::Regex::new(r"(?i)https?://[^\s<]+").unwrap().replace_all(&escaped,|c:&regex::Captures|{let trimmed=c[0].trim_end_matches(['.',',',')',';',']','!','?','\'', '"']);let trail=&c[0][trimmed.len()..];let href=crate::library::actions::esc(&crate::home_hydrate_ranked::html_entity_decode(trimmed));let key=format!("\u{1}U{}\u{1}",hold.len());hold.push((key.clone(),format!("<a href=\"{href}\" target=\"_blank\" rel=\"nofollow noopener noreferrer\">{trimmed}</a>{trail}")));key}).into_owned();
 let(html,tags)=escaped_tags(&escaped);let html=restore(restore(restore(html,&hold),&tag_hold),&mention_hold).replace("\r\n","\n").replace('\r',"\n");let blocks=regex::Regex::new(r"\n{2,}").unwrap().split(&html).filter_map(|part|{let part=crate::library::card::php_trim(part);if part.is_empty(){None}else{Some(format!("<p>{}</p>",part.replace('\n',"<br>\n")))}}).collect::<Vec<_>>();let html=if blocks.is_empty(){"<p></p>".into()}else{blocks.join("\n")};Ok(json!({"content":normalized_html(&html),"mentions":mentions,"tags":tags}))
}
pub(super) fn event_pack(text:&str)->Value {
 let(html,_)=escaped_tags(&crate::library::actions::esc(text));let mut tags=vec![];for c in regex::Regex::new(r"#([\p{L}\p{N}_]{1,100})").unwrap().captures_iter(text){retain(&mut tags,tag(&c[1]));}
 json!({"content":if html.is_empty(){String::new()}else{format!("<p>{}</p>",html.replace('\n',"<br />\n"))},"mentions":[],"tags":tags})
}
pub(super) fn clean_event_text(raw:&str)->String {
 let decoded=crate::home_hydrate_ranked::html_entity_decode(raw);let mut text=crate::library::card::php_trim(&strip_html(&decoded)).to_owned();
 text=regex::Regex::new(r"(?i)^RE:\s*https?://\S+?(?:/notes/[a-f0-9]+|/[0-9]+)").unwrap().replace(&text,"").into_owned();text=regex::Regex::new(r"(?i)RE:\s*https?://\S+").unwrap().replace_all(&text,"").into_owned();
 text=regex::Regex::new(r"^↪ QT(?:\s+@\S+:)?\s*").unwrap().replace(&text,"").into_owned();text=regex::Regex::new(r"[ \t]+").unwrap().replace_all(&text," ").into_owned();text=regex::Regex::new(r"\n{3,}").unwrap().replace_all(&text,"\n\n").into_owned();crate::library::card::php_trim(&text).to_owned()
}
impl Projector<'_> {
 pub(super) async fn mention_from_actor(&mut self,actor:&str)->Result<Value>{
  let actor=crate::library::card::php_trim(actor).trim_end_matches('/');
  if actor=="https://mkultra.monster/users/cmdr_nova"||actor.starts_with("https://mkultra.monster/users/cmdr_nova/"){return Ok(json!({"id":"1","username":"cmdr_nova","url":"https://mkultra.monster/users/cmdr_nova","acct":"cmdr_nova"}))}
  let account=self.remote_account(actor).await?;let uri=s(&account,"uri");let url=if uri.starts_with("https://"){uri}else if !actor.is_empty(){actor}else{s(&account,"url")};
  Ok(json!({"id":account["id"],"username":account["username"],"url":url,"acct":account["acct"]}))
 }
 async fn bare_mention(&mut self,user:&str)->Result<Option<Value>>{
  let needle=crate::library::card::php_trim(user).to_ascii_lowercase();if let Some(cached)=self.bare_memo.get(&needle){return Ok(cached.clone())}if needle=="cmdr_nova"{let value=Some(self.mention_from_actor("https://mkultra.monster/users/cmdr_nova").await?);self.bare_memo.insert(needle,value.clone());return Ok(value)}
  let rows=self.rows("SELECT to_jsonb(a) FROM remote_actors a WHERE lower(username)=$1::jsonb->>'username' ORDER BY updated_at DESC LIMIT 3",json!({"username":needle})).await?;
  let value=if let Some(row)=rows.first(){Some(self.mention_from_actor(s(row,"actor_id")).await?)}else{None};self.bare_memo.insert(needle,value.clone());Ok(value)
 }
 pub(super) async fn canonical_pack(&mut self,raw:&str)->Result<Value>{self.canonical_pack_with_actors(raw,&[]).await}
 pub(super) async fn canonical_pack_with_actors(&mut self,raw:&str,extra:&[String])->Result<Value>{
  let mut text=crate::home_hydrate_ranked::html_entity_decode(raw);
  text=regex::Regex::new(r"@([A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,})@bsky\.brid\.gy\b").unwrap().replace_all(&text,"@$1").into_owned();
  text=regex::Regex::new(r"(?i)@#([\p{L}\p{N}_]+)@(?:[a-z0-9.-]+\.[a-z]{2,})").unwrap().replace_all(&text,"#$1").into_owned();text=regex::Regex::new(r"@#([\p{L}\p{N}_]+)").unwrap().replace_all(&text,"#$1").into_owned();
  let mut mentions:Vec<Value>=vec![];for actor in extra{if !actor.is_empty(){let mention=self.mention_from_actor(actor).await?;if let Some(old)=mentions.iter_mut().find(|m|s(m,"acct").eq_ignore_ascii_case(s(&mention,"acct"))){*old=mention}else{mentions.push(mention)}}}
  for _ in 0..8{let previous=text.clone();text=regex::Regex::new(r"(@[\w]+@(?:[a-z0-9-]+\.)+[a-z]{2,})\s([a-z])").unwrap().replace_all(&previous,|c:&regex::Captures|{let tail=&previous[c.get(0).unwrap().end()..];if tail.chars().next().is_none_or(|ch|ch.is_whitespace()||ch=='@'||!(ch.is_alphanumeric()||ch=='_')){format!("{}{}",&c[1],&c[2])}else{c[0].into()}}).into_owned();if text==previous{break}}
  let previous=text.clone();text=regex::Regex::new(r"(^|[^\w@])(@[\w]+@(?:[a-z0-9-]+\.)+[a-z]{2,})").unwrap().replace_all(&previous,|c:&regex::Captures|{if previous[c.get(0).unwrap().end()..].chars().next().is_some_and(|ch|ch.is_uppercase()||ch.is_numeric()){format!("{}{} ",&c[1],&c[2])}else{c[0].into()}}).into_owned();
  let mut known:Vec<String>=mentions.iter().map(|m|crate::library::card::php_trim(s(m,"username")).to_owned()).filter(|u|!u.is_empty()&&regex::Regex::new(r"^\w+$").unwrap().is_match(u)).collect();known.sort_by_key(|u|std::cmp::Reverse(u.len()));known.dedup_by(|a,b|a.eq_ignore_ascii_case(b));
  for user in known{let previous=text.clone();let re=regex::Regex::new(&format!(r"(^|[^\w@])@({})",regex::escape(&user)))?;text=re.replace_all(&previous,|c:&regex::Captures|{if previous[c.get(0).unwrap().end()..].chars().next().is_some_and(|ch|ch.is_uppercase()||ch.is_numeric()){format!("{}@{} ",&c[1],&c[2])}else{c[0].into()}}).into_owned();}
  let previous=text.clone();text=regex::Regex::new(r"(^|[^A-Za-z0-9_])(@[A-Za-z0-9_]+@mkultra\.monster)([\p{L}\p{N}][\p{L}\p{N}_-]*)").unwrap().replace_all(&previous,|c:&regex::Captures|{if previous[c.get(0).unwrap().end()..].chars().next().is_none_or(|ch|ch.is_whitespace()||ch.is_ascii_punctuation()){format!("{}{} {}",&c[1],&c[2],&c[3])}else{c[0].into()}}).into_owned();
  let tokens=regex::Regex::new(r"(^|[^\w@])@([A-Za-z][\w]{1,40})").unwrap().captures_iter(&text).filter(|c|text[c.get(0).unwrap().end()..].chars().next().is_none_or(|ch|!(ch.is_alphanumeric()||"_@.".contains(ch)))).map(|c|(c.get(0).unwrap().start(),c.get(0).unwrap().end(),c[1].to_owned(),c[2].to_owned())).collect::<Vec<_>>();
  let mut replacements=vec![];for(start,end,prefix,token)in tokens{if self.bare_mention(&token).await?.is_some(){continue}let offsets=token.char_indices().map(|(i,_)|i).chain(std::iter::once(token.len())).collect::<Vec<_>>();for n in (2..offsets.len()-1).rev(){let split=offsets[n];if self.bare_mention(&token[..split]).await?.is_some(){replacements.push((start,end,format!("{prefix}@{} {}",&token[..split],&token[split..])));break}}}for(start,end,value)in replacements.into_iter().rev(){text.replace_range(start..end,&value)}
  for c in regex::Regex::new(r"(^|[^A-Za-z0-9_])@([A-Za-z0-9_]+)@([a-z0-9.-]+\.[a-z]{2,})").unwrap().captures_iter(&text){let acct=format!("{}@{}",&c[2],c[3].to_ascii_lowercase());if mentions.iter().any(|m|s(m,"acct").eq_ignore_ascii_case(&acct)){continue}let mut m=self.mention_from_actor(&format!("https://{}/users/{}",c[3].to_ascii_lowercase(),encode(&c[2]))).await?;m["acct"]=json!(acct);m["username"]=json!(&c[2]);mentions.push(m)}
  for c in regex::Regex::new(r"(^|[^A-Za-z0-9_@])@([A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,})").unwrap().captures_iter(&text){if text[c.get(0).unwrap().end()..].chars().next().is_some_and(|ch|ch.is_ascii_alphanumeric()||".@".contains(ch)){continue}let handle=c[2].to_ascii_lowercase();if mentions.iter().any(|m|s(m,"username").eq_ignore_ascii_case(&handle)||s(m,"acct").eq_ignore_ascii_case(&handle)){continue}if handle.ends_with(".brid.gy"){continue}
   let mut rows=self.rows("SELECT to_jsonb(a) FROM remote_actors a WHERE lower(username)=$1::jsonb->>'username' AND(host LIKE '%.brid.gy' OR host='brid.gy' OR actor_id LIKE 'https://bsky.brid.gy/%') ORDER BY updated_at DESC LIMIT 1",json!({"username":handle})).await?;
   if rows.is_empty(){rows=self.rows("SELECT to_jsonb(a) FROM remote_actors a WHERE lower(username)=$1::jsonb->>'username' ORDER BY updated_at DESC LIMIT 1",json!({"username":handle})).await?}if let Some(row)=rows.first(){let actor=s(row,"actor_id").trim_end_matches('/');if actor.starts_with("https://"){mentions.push(self.mention_from_actor(actor).await?)}}
  }
  for c in regex::Regex::new(r"(^|[^A-Za-z0-9_@])@([A-Za-z0-9_]{2,32})").unwrap().captures_iter(&text){let end=c.get(0).unwrap().end();if text[end..].chars().next().is_some_and(|ch|ch.is_ascii_alphanumeric()||"_@".contains(ch)){continue}let user=&c[2];if mentions.iter().any(|m|s(m,"username").eq_ignore_ascii_case(user)){continue}if let Some(m)=self.bare_mention(user).await?{if let Some(old)=mentions.iter_mut().find(|old|s(old,"acct").eq_ignore_ascii_case(s(&m,"acct"))){*old=m}else{mentions.push(m)}}}
  render_pack(&text,&mentions)
 }
}
#[cfg(test)]
mod tests{
 use super::*;
 #[test]fn actual_canonical_entity_helpers(){let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-entity-helper-reference.json").unwrap()).unwrap();let cases=reference["captures"].as_array().unwrap();assert_eq!(cases.len(),28);let mut counts=std::collections::HashMap::<String,usize>::new();for row in cases{*counts.entry(s(row,"helper").into()).or_default()+=1;let output=match s(row,"helper"){"plain_pack"=>plain_pack(s(row,"input")).unwrap(),"escaped_tags"=>{let(html,tags)=escaped_tags(s(row,"input"));json!([html,tags])},"html_tags"=>{let(html,tags)=html_tags(s(row,"input"));json!({"html":html,"tags":tags})},_=>panic!("unknown actual entity helper")};assert_eq!(output,row["expected"],"{} {:?}",row["helper"],row["input"]);}assert_eq!(counts.get("plain_pack"),Some(&11));assert_eq!(counts.get("escaped_tags"),Some(&11));assert_eq!(counts.get("html_tags"),Some(&6));}
}
