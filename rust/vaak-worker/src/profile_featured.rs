//! Native featured-account presentation. Selection and current metadata are
//! retained independently of header rows; no account card grants post access.
use anyhow::{bail,Result};
use serde_json::Value;
use tokio_postgres::Client;
use super::AdmittedProfileHeader;
use serde::Deserialize;
use std::path::PathBuf;
use tokio::io::AsyncReadExt;
#[derive(Deserialize)] #[serde(deny_unknown_fields)] struct MediaReply{protocol_version:u8,actor:String,avatar:Option<String>,cache_receipts:Vec<CacheReceipt>}
#[derive(Deserialize)] #[serde(deny_unknown_fields)] struct CacheReceipt{table:String,before:Vec<Value>,after:Vec<Value>}
async fn retained_media(db:&Client,header:&AdmittedProfileHeader,snapshots:&mut [Snapshot],actor:&str)->Result<Option<String>>{
 header.require_current(db).await?;for s in snapshots.iter(){s.current(db).await?;}
 let php=std::env::var_os("VAAK_PHP_BIN").map(PathBuf::from).unwrap_or_else(||"/usr/bin/php".into());let root=std::env::var_os("VAAK_API_ROOT").map(PathBuf::from).unwrap_or_else(||"/srv/mkultra/html/api".into());let helper=root.join("ap-profile-featured-media.php");if !helper.is_file(){bail!("retained Featured media helper unavailable")}
 let mut child=tokio::process::Command::new(php).arg(helper).arg(actor).stdin(std::process::Stdio::null()).stdout(std::process::Stdio::piped()).stderr(std::process::Stdio::null()).kill_on_drop(true).spawn()?;let stdout=child.stdout.take().ok_or_else(||anyhow::anyhow!("missing helper stdout"))?;
 let mut bytes=Vec::new();let result=tokio::time::timeout(std::time::Duration::from_secs(15),async{stdout.take(64*1024+1).read_to_end(&mut bytes).await?;let status=child.wait().await?;if !status.success()||bytes.len()>64*1024{bail!("retained media helper failed/output bound")}Ok::<_,anyhow::Error>(())}).await;result.map_err(|_|anyhow::anyhow!("retained media helper timeout"))??;
 let reply:MediaReply=serde_json::from_slice(&bytes)?;if reply.protocol_version!=1||reply.actor!=actor||reply.avatar.as_ref().is_some_and(|url|url.len()>2048)||reply.cache_receipts.len()!=2{bail!("retained media helper binding differs")}
 // Only this exact requested actor cache can advance. Every other retained
 // snapshot remains immutable, and fresh SQL must match the returned receipt.
 for (table,sql) in [("remote_actors","SELECT to_jsonb(a) FROM remote_actors a WHERE actor_id=$1 OR actor_id=$1||'/' LIMIT 1"),("remote_media_cache","SELECT to_jsonb(m) FROM remote_media_cache m WHERE actor_id=$1 AND kind='avatar' LIMIT 1")]{
  let receipts=reply.cache_receipts.iter().filter(|r|r.table==table).collect::<Vec<_>>();if receipts.len()!=1{bail!("unknown/duplicate media cache receipt")}let receipt=receipts[0];let current=Snapshot::read(db,sql,actor).await?;if current.rows!=receipt.after{bail!("media cache receipt no longer current")}
  for snapshot in snapshots.iter_mut().filter(|s|s.sql==sql&&s.key==actor){if snapshot.rows!=receipt.before{bail!("media cache receipt differs from retained before row")}snapshot.rows=receipt.after.clone();}
 }
 header.require_current(db).await?;for s in snapshots.iter(){s.current(db).await?;}Ok(reply.avatar)
}
#[derive(Clone)]
struct Snapshot{sql:&'static str,key:String,rows:Vec<Value>}
impl Snapshot{async fn read(db:&Client,sql:&'static str,key:&str)->Result<Self>{Ok(Self{sql,key:key.into(),rows:db.query(sql,&[&key]).await?.into_iter().map(|r|r.get(0)).collect()})}async fn current(&self,db:&Client)->Result<()>{if Self::read(db,self.sql,&self.key).await?.rows!=self.rows{bail!("featured selection or metadata changed")}Ok(())}}
struct FeaturedCard{ordinal:usize,actor:String,acct:String,name:String,avatar:String}
pub(crate) struct FeaturedContent{snapshots:Vec<Snapshot>,count:usize,cards:Vec<FeaturedCard>,from:String}
fn text<'a>(v:&'a Value,key:&str)->&'a str{v.get(key).and_then(Value::as_str).unwrap_or("")}
impl FeaturedContent{
 pub(crate) fn source_count(&self)->usize{self.count}
 pub(crate) fn card_ordinals(&self)->Vec<usize>{self.cards.iter().map(|c|c.ordinal).collect()}
 pub(crate) fn displayed_count(&self)->usize{self.cards.len()}
 pub(super) async fn require_current(&self,db:&Client)->Result<()>{for snapshot in &self.snapshots{snapshot.current(db).await?;}Ok(())}
 pub(super) fn render(&self)->String{
  let h=crate::library::actions::esc;let fallback="https://mkultra.monster/img/avatar/default.webp";
  if self.cards.is_empty(){return "<div class=\"empty\">No featured accounts.</div>".into()}
  let mut html="<ul class=\"featured-accounts\" aria-label=\"Featured accounts\">".to_owned();
  for c in &self.cards{let href=crate::library::card::profile_href(&c.actor,if self.from.is_empty(){"home"}else{&self.from});
   html.push_str(&format!("<li class=\"featured-account\"><a class=\"featured-account-link\" href=\"{}\"><img class=\"featured-av\" src=\"{}\" alt=\"\" width=\"48\" height=\"48\" loading=\"lazy\" referrerpolicy=\"no-referrer\" onerror=\"this.onerror=null;this.src='{fallback}'\"><span class=\"featured-meta\"><span class=\"featured-name\">{}</span>",h(&href),h(&c.avatar),h(&c.name)));
   if !c.acct.is_empty(){html.push_str(&format!("<span class=\"featured-acct\">@{}</span>",h(&c.acct)));}html.push_str("</span></a></li>");
  }html.push_str("</ul>");html
 }
}
pub(super) async fn prepare(db:&Client,header:&AdmittedProfileHeader,from:&str)->Result<FeaturedContent>{
 header.require_current(db).await?;let key=header.facts().actor_key();
 let owner=Snapshot::read(db,"SELECT to_jsonb(u) FROM ap_users u WHERE actor_key=$1 AND disabled_at IS NULL LIMIT 1",key).await?;
 let owner_id=match owner.rows.first(){None=>0,Some(row)=>row["id"].as_i64().filter(|id|*id>0).ok_or_else(||anyhow::anyhow!("invalid current Featured owner id"))?}.to_string();
 let selected=Snapshot::read(db,"SELECT to_jsonb(f) FROM ap_featured_accounts f WHERE owner_user_id::bigint=$1::text::bigint ORDER BY position ASC,id ASC LIMIT 40",&owner_id).await?;
 let count=selected.rows.len();let rows=selected.rows.clone();let mut snapshots=vec![owner,selected];let mut cards=Vec::new();
 for(ordinal,row)in rows.iter().enumerate(){
  header.require_current(db).await?;for snapshot in &snapshots{snapshot.current(db).await?;}
  // ap_featured_cards strips trailing slashes but preserves leading bytes.
  let actor=text(row,"target_actor_id").trim_end_matches('/');if actor.is_empty()||!actor.starts_with("https://"){continue;}
  let canonical=crate::library::card::php_trim(actor).trim_end_matches('/');
  let local=canonical.strip_prefix("https://mkultra.monster/users/").filter(|key|!key.is_empty()&&key.bytes().all(|b|b.is_ascii_alphanumeric()||b==b'_'));
  let(acct,name,avatar)=if let Some(key)=local{
   let key=key.to_ascii_lowercase();let profile=Snapshot::read(db,"SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&key).await?;let row=profile.rows.first().unwrap_or(&Value::Null);let name=crate::library::card::php_trim(text(row,"name"));let name=if name.is_empty(){key.clone()}else{name.into()};let avatar=crate::profile_identity::local_avatar(text(row,"icon_url"));snapshots.push(profile);(format!("{key}@mkultra.monster"),name,avatar)
  }else{
   let meta=Snapshot::read(db,"SELECT to_jsonb(a) FROM remote_actors a WHERE actor_id=$1 OR actor_id=$1||'/' LIMIT 1",canonical).await?;let row=meta.rows.first().unwrap_or(&Value::Null);let mut user=text(row,"username").to_owned();let mut host=text(row,"host").to_owned();if host.is_empty(){host=reqwest::Url::parse(canonical).ok().and_then(|u|u.host_str().map(str::to_ascii_lowercase)).unwrap_or_default();}
   if user.is_empty(){if let Some(c)=regex::Regex::new(r"/(?:users|@)([^/]+)/?$").unwrap().captures(canonical){user=crate::hidden::raw_percent_decode(&c[1]);}}
   let acct=if !user.is_empty()&&!host.is_empty(){format!("{user}@{host}")}else{canonical.into()};let original_icon=text(row,"icon_source_url").to_owned();let display=crate::library::card::php_trim(text(row,"display_name"));let name=if !display.is_empty(){display.into()}else if !user.is_empty(){user}else{acct.clone()};
   let media=Snapshot::read(db,"SELECT to_jsonb(m) FROM remote_media_cache m WHERE actor_id=$1 AND kind='avatar' LIMIT 1",canonical).await?;let avatar=media.rows.first().map(|r|text(r,"public_url")).filter(|v|!v.is_empty()&&*v!="0").map(str::to_owned);
   // The real PHP helper synchronously resolves actor/media on a cold cache.
   // A queue intent cannot substitute for that returned public avatar.
   snapshots.extend([meta,media]);let avatar=match avatar{Some(url)=>url,None=>{let value=retained_media(db,header,&mut snapshots,canonical).await?;value.filter(|v|!v.is_empty()).or_else(||(!original_icon.is_empty()&&original_icon!="0").then_some(original_icon)).unwrap_or_else(||"https://mkultra.monster/img/avatar/default.webp".into())}};(acct,name,avatar)
  };
  cards.push(FeaturedCard{ordinal,actor:canonical.into(),acct,name,avatar});
 }
 let content=FeaturedContent{snapshots,count,cards,from:from.chars().filter(|c|c.is_ascii_lowercase()||*c=='_').collect()};header.require_current(db).await?;content.require_current(db).await?;Ok(content)
}
