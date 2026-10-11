//! Private direct-card preview state. DNS is a transport dependency; answers
//! still pass the native eligibility predicate. No network fetch or warm job
//! occurs here: branches requiring either preserve the whole PHP request.
use anyhow::{bail,Context,Result};
use serde_json::{json,Value};
use std::{collections::BTreeMap,net::Ipv4Addr,pin::Pin,future::Future};
use tokio_postgres::Client;

pub(crate) trait PreviewDns:Sync {
 fn ipv4<'a>(&'a self,host:&'a str)->Pin<Box<dyn Future<Output=Result<Vec<Ipv4Addr>>>+Send+'a>>;
}
pub(crate) struct SystemDns;
impl PreviewDns for SystemDns {
 fn ipv4<'a>(&'a self,host:&'a str)->Pin<Box<dyn Future<Output=Result<Vec<Ipv4Addr>>>+Send+'a>>{Box::pin(async move{
  let answers=tokio::time::timeout(std::time::Duration::from_secs(2),tokio::net::lookup_host((host,443))).await.context("PHP fallback: preview DNS timeout")??;
  let ips=answers.filter_map(|address|match address.ip(){std::net::IpAddr::V4(ip)=>Some(ip),_=>None}).collect::<Vec<_>>();
  if ips.is_empty(){bail!("PHP fallback: unresolved IPv4 preview eligibility")};Ok(ips)
 })}
}
fn s<'a>(v:&'a Value,k:&str)->&'a str{v.get(k).and_then(Value::as_str).unwrap_or("")}
fn strip_tags(input:&str)->Result<String>{
 // Keep the supported PHP strip_tags grammar explicit. Ambiguous malformed
 // tags are not fed through a browser parser that would invent another URL.
 let tags=regex::Regex::new(r"(?s)<!--.*?-->|<[^>]*>").unwrap();
 let plain=tags.replace_all(input,"");
 if plain.contains(['<','>']){bail!("PHP fallback: ambiguous preview HTML")}
 Ok(crate::home_hydrate_ranked::html_entity_decode(&plain))
}
fn decode_form(value:&str)->Result<String>{
 let bytes=value.as_bytes();let mut out=Vec::new();let mut index=0;
 while index<bytes.len(){match bytes[index]{b'+'=>out.push(b' '),b'%' if index+2<bytes.len()=>{let digits=std::str::from_utf8(&bytes[index+1..index+3])?;if let Ok(byte)=u8::from_str_radix(digits,16){out.push(byte);index+=2;}else{out.push(bytes[index]);}},byte=>out.push(byte)}index+=1;}
 Ok(String::from_utf8(out).context("PHP fallback: non UTF8 preview query")?)
}
fn query(input:&str)->Result<BTreeMap<String,String>>{
 if input.split('&').count()>64{bail!("PHP fallback: preview query parameter bound")}
 let mut values=BTreeMap::new();
 for pair in input.split('&').filter(|pair|!pair.is_empty()){
  let (key,value)=pair.split_once('=').unwrap_or((pair,""));let key=decode_form(key)?.trim_start_matches(' ').replace(['.',' '],"_");
  if key.contains(['[',']','\0'])||key.bytes().any(|b|b<32)||key.parse::<i64>().is_ok(){bail!("PHP fallback: compound preview query")}
  if !key.is_empty(){values.insert(key,decode_form(value)?);}
 }Ok(values)
}
pub(crate) fn normalize(input:&str)->Result<String>{
 let input=crate::library::card::php_trim(input);
 let short=regex::Regex::new(r"(?i)^https?://(?:www\.)?(?:youtu\.be/|youtube\.com/shorts/)([A-Za-z0-9_-]{6,})").unwrap();
 if let Some(cap)=short.captures(input){return Ok(format!("https://www.youtube.com/watch?v={}",&cap[1]))}
 let watch=regex::Regex::new(r"(?i)^https?://(?:www\.)?youtube\.com/watch\?([^#]+)").unwrap();
 if let Some(cap)=watch.captures(input){if let Some(id)=query(&cap[1])?.get("v").filter(|id|!id.is_empty()&&*id!="0"){return Ok(format!("https://www.youtube.com/watch?v={id}"))}}
 Ok(input.to_owned())
}

pub(crate) fn youtube_from_linked_html(html:&str)->Result<Option<Value>>{
 let hrefs=regex::Regex::new(r#"(?i)href="(https?://[^"]*)""#).unwrap();
 let id_pattern=regex::Regex::new(r"^https://www\.youtube\.com/watch\?v=([A-Za-z0-9_-]{6,})").unwrap();
 for href in hrefs.captures_iter(html){
  let url=crate::home_hydrate_ranked::html_entity_decode(&href[1]);
  if !url.starts_with("http")||url.contains("...")||url.contains('…'){continue}
  let normalized=normalize(&url)?;
  if let Some(capture)=id_pattern.captures(&normalized){
   let id=&capture[1];
   return Ok(Some(serde_json::json!({"url":format!("https://www.youtube.com/watch?v={id}"),"title":"YouTube video","description":null,"image":format!("https://i.ytimg.com/vi/{id}/hqdefault.jpg"),"provider_name":"YouTube","provider_url":"https://www.youtube.com/","type":"video","status":"ok"})));
  }
 }
 Ok(None)
}
fn canonical(input:&str)->Result<String>{
 // PHP preserves the original path and drops credentials, ports and fragment.
 // URL crate serialization would normalize dot paths and add a root slash.
 let pattern=regex::Regex::new(r"(?i)^(https?)://([^/?#]+)([^?#]*)(?:\?([^#]*))?(?:#.*)?$").unwrap();
 let Some(cap)=pattern.captures(input)else{bail!("PHP fallback: preview URL parse")};
 let authority=cap[2].rsplit('@').next().unwrap_or("");
 let (host,port)=authority.split_once(':').map(|(h,p)|(h,Some(p))).unwrap_or((authority,None));
 if let Some(port)=port {if !port.is_empty(){let port=port.strip_prefix('+').unwrap_or(port);if port.is_empty()||!port.bytes().all(|b|b.is_ascii_digit())||port.parse::<u16>().is_err(){bail!("PHP fallback: preview URL port grammar")}}}
 let host=host.to_ascii_lowercase();
 let host=host.strip_prefix("www.").unwrap_or(&host);
 if !regex::Regex::new(r"^[a-z0-9.-]+$").unwrap().is_match(host){bail!("PHP fallback: preview hostname grammar")}
 let path=&cap[3];let path=if path=="/"{path}else{path.trim_end_matches('/')};
 let mut values=query(cap.get(4).map(|c|c.as_str()).unwrap_or(""))?;
 values.retain(|key,_|{let key=key.to_ascii_lowercase();!key.starts_with("utm_")&&!matches!(key.as_str(),"fbclid"|"gclid"|"mc_cid"|"mc_eid"|"ref"|"ref_src")});
 let form=|value:&str|crate::library::actions::query(value).replace("%20","+").replace('~',"%7E");
 let encoded=values.into_iter().map(|(key,value)|format!("{}={}",form(&key),form(&value))).collect::<Vec<_>>().join("&");
 Ok(format!("{}://{host}{path}{}",cap[1].to_ascii_lowercase(),if encoded.is_empty(){String::new()}else{format!("?{encoded}")}))
}
pub(crate) fn extract_url(input:&str)->Result<Option<String>>{
 if input.len()>256*1024{bail!("PHP fallback: preview input size")}
 let plain=strip_tags(input)?;
 let urls=regex::Regex::new(r#"(?iu)https://[^\s<>"']+"#).unwrap();
 for found in urls.find_iter(&plain){
  let mut raw=found.as_str();
  if raw.starts_with("https://"){raw=&raw[..raw.bytes().take_while(|b|(0x21..=0x7e).contains(b)).count()];}
  raw=raw.trim_end_matches(|c:char|".,);]!?\"'".contains(c));
  let lower=raw.to_ascii_lowercase();if let Some(next)=regex::Regex::new(r"https?://").unwrap().find(&lower[8..]){raw=&raw[..8+next.start()];}
  raw=raw.trim_end_matches(|c:char|".,);]!?\"'/".contains(c));
  if raw.starts_with("https://mkultra.monster/users/cmdr_nova/notes/"){continue}
  let path=raw.split('?').next().unwrap_or("").split('#').next().unwrap_or("");
  if regex::Regex::new(r"(?i)\.(jpe?g|png|gif|webp|mp4|webm|mov|m4v|mp3|ogg|wav|pdf)$").unwrap().is_match(path){continue}
  return Ok(Some(canonical(&normalize(raw)?)?));
 }Ok(None)
}
pub(crate) fn public_ipv4(ip:Ipv4Addr)->Result<bool>{
 let [a,b,_,_]=ip.octets();
 // PHP FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE (IPv4).
 if a==0||a==10||a==127||a>=240||(a==169&&b==254)||(a==172&&(16..=31).contains(&b))||(a==192&&b==168){return Ok(false)}
 Ok(true)
}
pub(crate) async fn eligible(url:&str,dns:&dyn PreviewDns)->Result<bool>{
 if url.len()>2000{return Ok(false)}let parsed=reqwest::Url::parse(url)?;let host=parsed.host_str().context("preview hostname missing")?;
 if host=="localhost"||host.ends_with(".localhost")||host.ends_with(".local"){return Ok(false)}
 let answers=if let Ok(ip)=host.parse::<Ipv4Addr>(){vec![ip]}else{dns.ipv4(host).await?};
 if answers.is_empty(){bail!("PHP fallback: unknown preview DNS eligibility")}
 for ip in answers{if !public_ipv4(ip)?{return Ok(false)}}Ok(true)
}
/// Raw profile/quoted helpers use cache-only lookup: eligibility still precedes
/// SQL, but a miss never fetches, warms, or consumes either preview budget.
pub(crate) async fn resolve_cache_only(db:&Client,dns:&dyn PreviewDns,text:&str,now:chrono::DateTime<chrono::Utc>)->Result<Option<Value>>{
 resolve_cache_only_guarded(db,dns,text,now,None).await
}
struct GuardedCacheDns<'a>{db:&'a Client,dns:&'a dyn PreviewDns,grant:&'a dyn crate::profile_enrichment::CurrentEnrichmentGrant}
impl PreviewDns for GuardedCacheDns<'_>{fn ipv4<'a>(&'a self,host:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<Vec<Ipv4Addr>>>+Send+'a>>{Box::pin(async move{self.grant.require_current(self.db).await?;let result=self.dns.ipv4(host).await?;self.grant.require_current(self.db).await?;Ok(result)})}}
pub(crate) async fn resolve_cache_only_guarded(db:&Client,dns:&dyn PreviewDns,text:&str,now:chrono::DateTime<chrono::Utc>,grant:Option<&dyn crate::profile_enrichment::CurrentEnrichmentGrant>)->Result<Option<Value>>{
 if let Some(grant)=grant{grant.require_current(db).await?;}

 let Some(url)=extract_url(text)?else{return Ok(None)};
 let key=normalize(&url)?;
 let allowed=if let Some(grant)=grant{eligible(&key,&GuardedCacheDns{db,dns,grant}).await?}else{eligible(&key,dns).await?};if !allowed{return Ok(None)}
 if let Some(grant)=grant{grant.require_current(db).await?;}
 let row=db.query_opt("SELECT to_jsonb(c) FROM link_preview_cards c WHERE url=$1 LIMIT 1",&[&key]).await?;
 if let Some(grant)=grant{grant.require_current(db).await?;}
 if let Some(row)=row{
  let row:Value=row.get(0);let expiry=s(&row,"expires_at");
  let expired=if expiry.is_empty(){false}else{
   chrono::DateTime::parse_from_rfc3339(expiry).or_else(|_|chrono::NaiveDateTime::parse_from_str(expiry,"%Y-%m-%d %H:%M:%S").map(|v|v.and_utc().fixed_offset())).context("PHP fallback: cache-only preview expiry grammar")?.timestamp()<now.timestamp()
  };
  if !expired {
   if row.get("status").and_then(Value::as_str).unwrap_or("ok")!="ok"{return Ok(None)}
   let mut card=serde_json::Map::new();
   card.insert("url".into(),Value::String(s(&row,"url").into()));
   for key in ["title","description","image","provider_name","provider_url"]{
    let value=row.get(key).unwrap_or(&Value::Null);
    if !value.is_null()&&!value.is_string(){bail!("PHP fallback: cache-only preview scalar type")}
    card.insert(key.into(),value.clone());
   }
   card.insert("type".into(),Value::String(row.get("type").and_then(Value::as_str).unwrap_or("link").into()));
   card.insert("status".into(),Value::String("ok".into()));
   return Ok(Some(Value::Object(card)))
  }
 }
 youtube_from_linked_html(&format!("<a href=\"{}\"></a>",crate::library::actions::esc(&key)))
}
pub(crate) fn masto_card(row:&Value)->Option<Value>{
 if row.get("status").and_then(Value::as_str).unwrap_or("ok")!="ok"||s(row,"url").is_empty(){return None}
 let kind=row.get("type").and_then(Value::as_str).unwrap_or("link");let kind=if matches!(kind,"link"|"photo"|"video"|"rich"){kind}else{"link"};
 Some(json!({"url":s(row,"url"),"title":s(row,"title"),"description":s(row,"description"),"image":row.get("image").filter(|v|v.is_string()).cloned().unwrap_or(Value::Null),"provider_name":s(row,"provider_name"),"provider_url":s(row,"provider_url"),"type":kind,"status":"ok","width":if kind=="video"{480}else{0},"height":if kind=="video"{270}else{0}}))
}
/// Resolves one admitted root in PHP painter order. Returned state is private
/// to an all-or-nothing batch; an unsupported branch publishes no budget change.
// Presentation-only preview dependencies. This carries no admitted status,
// action context, grant, or capability to publish source HTML.
struct PreviewContext {plain:String,linked_html:String,poll_suppressed:bool,now:chrono::DateTime<chrono::Utc>}

pub(crate) fn preview_plain(st:&Value,answer:Option<&str>)->String{
 let quoted=st.get("quote").and_then(|v|v.get("quoted_status")).filter(|v|v.is_object());
 let quoted_plain=quoted.map(|q|crate::library::body::scrub_as2(&crate::library::body::html_to_plain(s(q,"content"))));
 let mut plain=crate::library::body::strip_raw_quotes(&crate::library::body::scrub_as2(&crate::library::body::html_to_plain(s(st,"content"))),quoted_plain.as_deref());
 if let Some(answer)=answer.filter(|answer|!crate::library::card::php_trim(answer).is_empty()){plain=crate::library::card::php_trim(answer).into();}
 if regex::Regex::new(r"(?i)^\((?:attachment|media|poll|quote|boost)\)$").unwrap().is_match(crate::library::card::php_trim(&plain)){plain.clear();}
 plain
}

pub(crate) async fn resolve(db:&Client,dns:&dyn PreviewDns,status:&Value,original_content:Option<&str>,facts:&crate::library::card::CardFacts,budget:&mut i64)->Result<Option<Value>>{
 resolve_guarded(db,dns,status,original_content,facts,budget,None).await
}

/// Guard actual DNS/cache awaits; pure preview branches use enclosing final admission.
pub(crate) async fn resolve_guarded(db:&Client,dns:&dyn PreviewDns,status:&Value,original_content:Option<&str>,facts:&crate::library::card::CardFacts,budget:&mut i64,grant:Option<&dyn crate::profile_enrichment::CurrentEnrichmentGrant>)->Result<Option<Value>>{
 let st=if s(status,"content").is_empty(){status.get("reblog").filter(|v|v.is_object()).unwrap_or(status)}else{status};
 let media=st.get("media_attachments").and_then(Value::as_array).is_some_and(|v|!v.is_empty());
 let note=if s(st,"uri").is_empty(){s(st,"url")}else{s(st,"uri")};
 if media||facts.poll_row.as_ref().is_some_and(|row|!crate::library::card::poll_html(row,note,facts).is_empty()){return Ok(None)}
 let plain=preview_plain(st,facts.ask_row.as_ref().map(|ask|s(ask,"_body_answer")));
 let linked_html=if plain.is_empty(){String::new()}else{crate::library::card::linked_body_html(&plain,st,facts)?};
 resolve_prepared(db,dns,st,original_content,&PreviewContext{plain,linked_html,poll_suppressed:false,now:facts.now},budget,grant).await
}

pub(crate) async fn resolve_late_omitted(db:&Client,dns:&dyn PreviewDns,status:&Value,original_content:Option<&str>,owner_actor:&str,return_view:&str,budget:&mut i64)->Result<()> {
 // Unknown nested recovery and Ask identity/body pipelines stay PHP-owned.
 // No CardFacts or action-bearing/admitted token is created for denied rows.
 if ["reblog","quote","vaak_quote_preview","vaak_ask"].iter().any(|field|status.get(*field).is_some_and(|value|!value.is_null())){bail!("PHP fallback: omitted nested/Ask preview presentation")}
 let uri=crate::library::card::php_trim(if s(status,"uri").is_empty(){s(status,"url")}else{s(status,"uri")}).trim_end_matches('/');
 if db.query_one("SELECT EXISTS(SELECT 1 FROM ap_asks WHERE answer_note_id IN ($1,$2)) OR EXISTS(SELECT 1 FROM mentions WHERE object_id IN ($1,$2) AND deleted_at IS NULL AND ask_question<>'')",&[&uri,&format!("{uri}/")]).await?.get::<_,bool>(0){bail!("PHP fallback: omitted current Ask preview presentation")}
 if let Some(media)=status.get("media_attachments").and_then(Value::as_array){if media.iter().any(|item|!s(item,"url").starts_with("https://")){bail!("PHP fallback: omitted media normalization")}}
 let poll=db.query_opt("SELECT to_jsonb(p) FROM masto_polls p WHERE note_id IN ($1,$2) LIMIT 1",&[&uri,&format!("{uri}/")]).await?.map(|row|row.get::<_,Value>(0));
 let poll_suppressed=poll.as_ref().is_some_and(crate::library::card::poll_has_options);
 let mut display=status.clone();
 if uri.starts_with(&format!("{}/notes/",owner_actor.trim_end_matches('/'))){
  if let Some(row)=db.query_opt("SELECT content_text FROM masto_statuses WHERE note_id IN ($1,$2) LIMIT 1",&[&uri,&format!("{uri}/")]).await?{
   let current:String=row.get(0);if !current.is_empty()&&!matches!(current.as_str(),"(quote)"|"(media)"|"(poll)"){display["content"]=Value::String(crate::library::actions::esc(&current));}
  }
 }
 let plain=preview_plain(&display,None);
 if regex::Regex::new(r"(?:↪|➡|→)\s*QT").unwrap().is_match(&plain){bail!("PHP fallback: omitted raw quote recovery")}
 let emojis=crate::library::facts::emoji_maps(db,&display).await?;
 let actor=crate::library::card::account_actor_ref(&display["account"]);let emojis=emojis.get(&actor).cloned().unwrap_or_default();
 let linked_html=if plain.is_empty(){String::new()}else{crate::library::card::linked_body_presentation(&plain,&display,return_view,owner_actor,&emojis)?};
 let _=resolve_prepared(db,dns,&display,original_content,&PreviewContext{plain,linked_html,poll_suppressed,now:chrono::Utc::now()},budget,None).await?;
 Ok(())
}

async fn resolve_prepared(db:&Client,dns:&dyn PreviewDns,st:&Value,original_content:Option<&str>,context:&PreviewContext,budget:&mut i64,grant:Option<&dyn crate::profile_enrichment::CurrentEnrichmentGrant>)->Result<Option<Value>>{
 if context.poll_suppressed||st.get("media_attachments").and_then(Value::as_array).is_some_and(|media|!media.is_empty()){return Ok(None)}
 let quoted=st.get("quote").and_then(|v|v.get("quoted_status")).filter(|v|v.is_object());
 let quoted_url=quoted.map(|q|if s(q,"uri").starts_with("https://"){s(q,"uri")}else{s(q,"url")}).unwrap_or("");
 let duplicate=|card:&Value|crate::library::card::status_urls_equivalent(s(card,"url"),quoted_url);
 if let Some(card)=st.get("card").filter(|v|v.is_object()&&!duplicate(v)){
  if ["url","title","description","provider_name","provider_url","type"].iter().any(|key|card.get(*key).is_some_and(|v|!v.is_null()&&!v.is_string())){bail!("PHP fallback: supplied preview scalar normalization")}
  let mut card=card.clone();card["status"]=Value::String("ok".into());
  if !crate::library::card::link_preview(&card)?.is_empty(){return Ok(Some(card))}
 }
 let plain=&context.plain;
 if plain.is_empty(){return Ok(None)}
 // PHP's deterministic YouTube branch examines the current linked body before
 // ordinary cache selection. The latter still uses original cached content.
 if let Some(card)=youtube_from_linked_html(&context.linked_html)?{return Ok(Some(card))}
 let Some(url)=extract_url(original_content.unwrap_or(plain))? else {
  // The first cache-only helper returned null. The bounded fetch attempt
  // repeats extraction and also returns null, without DNS/HTTP/warm effects.
  if *budget>0{*budget-=1;}return Ok(None)
 };
 // A denied URL still reaches PHP's warm helper. Native cannot launch that
 // effect, so preserve the complete PHP path rather than silently omit it.
 let key=normalize(&url)?;
 // for_url normalizes again before eligibility (not just cache lookup).
 // In particular the extracted YouTube canonical key loses www, while the
 // helper restores it; DNS must inspect the hostname actually used by PHP.
 if let Some(grant)=grant{grant.require_current(db).await?;}
 let eligibility=eligible(&key,dns).await;
 if let Some(grant)=grant{grant.require_current(db).await?;}
 if !eligibility?{bail!("PHP fallback: rejected URL warm branch")}
 if let Some(grant)=grant{grant.require_current(db).await?;}
 let row=db.query_opt("SELECT to_jsonb(c) FROM link_preview_cards c WHERE url=$1 LIMIT 1",&[&key]).await;
 if let Some(grant)=grant{grant.require_current(db).await?;}
 if let Some(row)=row?{
  let row:Value=row.get(0);let expiry=s(&row,"expires_at");
  if !expiry.is_empty(){let expiry=chrono::DateTime::parse_from_rfc3339(expiry).or_else(|_|chrono::NaiveDateTime::parse_from_str(expiry,"%Y-%m-%d %H:%M:%S").map(|v|v.and_utc().fixed_offset())).context("PHP fallback: preview expiry grammar")?;
   if expiry.timestamp()<context.now.timestamp(){bail!("PHP fallback: expired preview fetch/warm")}}
  if let Some(card)=masto_card(&row){return Ok(if duplicate(&card){None}else{Some(card)})}
 }
 bail!("PHP fallback: cold preview fetch/warm")
}

#[cfg(test)]
mod tests {
 use super::*;
 #[tokio::test]
 #[ignore="opt-in disposable PostgreSQL actual PHP cache-only preview differential"]
 async fn actual_profile_cache_only_preview_replay(){
  let connection=std::env::var("LIBRARY_FIXTURE_DB").expect("disposable PG");assert!(connection.contains("/tmp/"));
  let input:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-preview-cache-reference.json").unwrap()).unwrap();
  let db=crate::db::connect(&connection).await.unwrap();let mut output=Vec::new();
  struct PublicDns;impl PreviewDns for PublicDns{fn ipv4<'a>(&'a self,_:&'a str)->Pin<Box<dyn Future<Output=Result<Vec<Ipv4Addr>>>+Send+'a>>{Box::pin(async{Ok(vec![Ipv4Addr::new(8,8,8,8)])})}}
  for width in ["INTEGER","BIGINT"]{
   let schema=format!("profile_cache_{}_{}",width.to_lowercase(),std::process::id());
   db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path TO {schema}")).await.unwrap();
   let columns=input["current_schema_by_width"][width]["link_preview_cards"].as_array().unwrap();
   let definitions=columns.iter().map(|column|format!("{} {}",column["column_name"].as_str().unwrap(),column["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");
   db.batch_execute(&format!("CREATE TABLE link_preview_cards({definitions})")).await.unwrap();
   for capture in input["captures"].as_array().unwrap().iter().filter(|row|row["width"]==width){
    db.batch_execute("TRUNCATE link_preview_cards").await.unwrap();
    for row in capture["current_tables"]["link_preview_cards"].as_array().unwrap(){let raw=serde_json::to_string(row).unwrap();db.execute("INSERT INTO link_preview_cards SELECT * FROM jsonb_populate_record(NULL::link_preview_cards,$1::text::jsonb)",&[&raw]).await.unwrap();}
    let now=chrono::DateTime::from_timestamp(capture["rendered_at"].as_i64().unwrap(),0).unwrap();
    let card=resolve_cache_only(&db,&PublicDns,capture["source_text"].as_str().unwrap(),now).await.unwrap();
    assert_eq!(serde_json::to_value(&card).unwrap(),capture["php"]["raw_card"],"{width} {:?}",capture["case"]);
    output.push(json!({"width":width,"case":capture["case"],"card":card}));
   }
   db.batch_execute(&format!("SET search_path TO public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
  }
  assert_eq!(output.len(),16);std::fs::write("/tmp/vaak-profile-cache-only-native.json",serde_json::to_vec_pretty(&output).unwrap()).unwrap();
 }
 #[test]
 fn actual_php_selector_controls()->Result<()> {
  use std::io::Write;
  let cases=["plain no url", "0", "<p>https://article.test/path</p>", "HTTPS://WWW.ARTICLE.TEST/path/", "https://article.test/path?utm_source=x&b=2&a=hello+world", "https://article.test/path?a=first&a=last", "https://article.test/path?a=%7E&z=x%20y", "https://article.test/", "https://article.test/#frag", "https://article.test/path///#frag", "https://article.test/p?x=1&amp;y=2", "<a href='https://ignored.test'>https://shown.test/x</a>", "https://article.test/a😀", "https://article.test/ahttps://tracker.test/x", "https://article.test/ahttp://tracker.test/x", "https://article.test/a.,);]!", "https://article.test/a.jpg https://article.test/b", "https://article.test/a.png?x=1 https://article.test/b", "https://mkultra.monster/users/cmdr_nova/notes/one https://article.test/b", "https://mkultra.monster/users/other/notes/one", "https://youtu.be/abcdefghi", "https://www.youtube.com/shorts/abcdefghi", "https://youtube.com/watch?v=abcdefghi&extra=1", "https://article.test:443/a", "https://article.test:/a", "https://article.test:0/a", "https://article.test:65535/a", "https://article.test:+443/a", "https://article.test:+0/a", "https://article.test:foo/a", "https://article.test:65536/a", "https://article.test:99999/a", "https://article.test:-443/a", "https://article.test:+/a", "https://user:pass@article.test/a", "https://article.test/p?q=a.b&key.dot=a", "https://article.test/p?q=%2F%3F%26", "<!-- https://hidden.test -->https://article.test/a", "<p title='safe'>https://article.test/a</p>", "&#104;ttps://article.test/a", "https://article.test/p?ref=x&REF_SRC=y&FBCLID=z&ok=1", "https://article.test/p?blank&equals=", "https://article.test/p?%20key%20=x"];
  let root=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../..");
  let mut process=std::process::Command::new(root.join("api/bin/php-policy-test")).current_dir(&root).args(["-r","require 'api/ap-link-preview.php';$v=json_decode(stream_get_contents(STDIN),true);echo json_encode(array_map(fn($s)=>ap_link_preview_extract_url($s),$v),JSON_THROW_ON_ERROR);"]).stdin(std::process::Stdio::piped()).stdout(std::process::Stdio::piped()).spawn()?;
  process.stdin.take().unwrap().write_all(&serde_json::to_vec(cases.as_slice())?)?;
  let result=process.wait_with_output()?;assert!(result.status.success());let expected:Vec<Option<String>>=serde_json::from_slice(&result.stdout)?;
  for (input,expected) in cases.iter().zip(expected){match extract_url(input){Ok(actual)=>assert_eq!(actual,expected,"actual PHP selector {input}"),Err(_) if [":foo/",":65536/",":99999/",":-443/",":+/"].iter().any(|port|input.contains(port))=>assert_eq!(expected,None,"actual PHP invalid port {input}"),Err(error)=>return Err(error)}}
  for input in ["https://article.test:foo/a", "https://article.test:65536/a", "https://article.test:99999/a", "https://article.test:-443/a", "https://article.test:p/a", "https://article.test/p?2=one&10=two", "https://article.test/p?a[]=one", "https://article.test/p?a=%FF", "<p title='>'>https://article.test/a", "https://[::1]/x"]{assert!(extract_url(input).is_err(),"uncertified selector {input}");}
  assert!(extract_url(&format!("https://article.test/p?{}",(0..65).map(|i|format!("k{i}=x")).collect::<Vec<_>>().join("&"))).is_err());
  Ok(())
 }
 #[test] fn actual_php_ipv4_eligibility()->Result<()> {
  let ips=["0.1.2.3","10.0.0.1","127.0.0.1","169.254.1.1","172.16.0.1","172.31.255.255","172.32.0.1","192.168.1.1","240.0.0.1","255.255.255.255","8.8.8.8","100.64.0.1","192.0.0.1","192.0.2.1","198.18.0.1","198.51.100.1","203.0.113.1","224.0.0.1","239.255.255.255"];
  use std::io::Write;
  let root=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../..");
  let mut process=std::process::Command::new(root.join("api/bin/php-policy-test")).args(["-r","$v=json_decode(stream_get_contents(STDIN),true);echo json_encode(array_map(fn($s)=>filter_var($s,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false,$v),JSON_THROW_ON_ERROR);"]).stdin(std::process::Stdio::piped()).stdout(std::process::Stdio::piped()).spawn()?;
  process.stdin.take().unwrap().write_all(&serde_json::to_vec(ips.as_slice())?)?;let result=process.wait_with_output()?;assert!(result.status.success());let expected:Vec<bool>=serde_json::from_slice(&result.stdout)?;
  for (ip,expected) in ips.iter().zip(expected){assert_eq!(public_ipv4(ip.parse()?)?,expected,"actual PHP IPv4 {ip}");}Ok(())
 }
}

/// Only actual resolver consumption can produce the authenticated reply state.
#[derive(serde::Serialize)]
pub(crate) struct PreviewState {initial:i64,remaining:i64,attempts:usize}
impl PreviewState {
 pub(crate) fn attempts(&self)->usize{self.attempts}
 pub(crate) fn initial(&self)->i64{self.initial}
 pub(crate) fn remaining(&self)->i64{self.remaining}
 pub(crate) fn from_remaining(initial:i64,remaining:i64,source_count:usize)->Result<Self>{
  let attempts=if initial>0 {
   if remaining<0||remaining>initial{bail!("invalid private preview state")}
   usize::try_from(initial-remaining)?
  }else{if remaining!=initial{bail!("invalid nonpositive preview state")}0};
  if attempts>source_count{bail!("preview consumption exceeds source bound")}
  Ok(Self{initial,remaining,attempts})
 }
}

/// Real transport defaults; fixture substitutions are available only in test
/// crates and carry no source/action/audience decisions.
pub(crate) struct PreviewDependencies<'a> {
 pub(crate) dns:&'a dyn PreviewDns,
 #[cfg(test)] pub(crate) render_clock:Option<chrono::DateTime<chrono::Utc>>,
}
impl PreviewDependencies<'static> {
 pub(crate) fn production()->Self {Self{dns:&SystemDns,#[cfg(test)]render_clock:None}}
}
