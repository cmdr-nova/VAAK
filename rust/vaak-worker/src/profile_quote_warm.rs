//! Server-owned equivalent of ap_quote_target_warm_async. Audience admission
//! belongs to the caller; cache hits, miss files and URL shape never grant it.
//! Signed AP fetching remains the existing ap-quote-target-warm.php process.
use anyhow::Result;
use serde::{Deserialize,Serialize};
use std::{future::Future,pin::Pin,path::PathBuf,io::{Read,Write,Seek,SeekFrom},process::Stdio,time::Duration};
use sha2::{Digest,Sha256};
use tokio_postgres::Client;
type GrantFuture<'a>=Pin<Box<dyn Future<Output=Result<()>>+Send+'a>>;
type EffectFuture<'a>=Pin<Box<dyn Future<Output=Result<bool>>+Send+'a>>;
/// Implemented only against current SQL source/owner/audience capabilities.
pub(crate) trait CurrentQuoteGrant:Sync{fn require_current(&self)->GrantFuture<'_>;
 fn bind_preview_text<'a>(&'a self,_text:&'a str)->GrantFuture<'a>{Box::pin(async{Ok(())})}
 fn bind_preview_write_url<'a>(&'a self,_url:&'a str)->GrantFuture<'a>{Box::pin(async{Ok(())})}
 #[cfg(test)] fn fixture_after_preview_write<'a>(&'a self,_db:&'a Client,_fetch:&'a crate::profile_preview_fetch::PreviewFetchContext,_now:chrono::DateTime<chrono::Utc>)->GrantFuture<'a>{Box::pin(async{Ok(())})}
 fn accept_preview_write(&self,_:&crate::profile_preview_fetch::PreviewCacheWrite)->Result<()>{anyhow::bail!("PHP fallback: preview SQL writer lacks receipt capability")}
 /// Remaining shared PHP-compatible request budget; absent means per-call timeout only.
 fn bsky_budget_remaining(&self)->Option<f64>{None}
}
pub(crate) trait QuoteWarmTransport:Sync{
 fn quote_process<'a>(&'a self,url:&'a str,grant:&'a dyn CurrentQuoteGrant)->EffectFuture<'a>;
 fn bsky_warm<'a>(&'a self,uri:&'a str,owner:i64,now:i64,grant:&'a dyn CurrentQuoteGrant)->EffectFuture<'a>;
 fn resolve_handle<'a>(&'a self,handle:&'a str,grant:&'a dyn CurrentQuoteGrant)->Pin<Box<dyn Future<Output=Result<Option<String>>>+Send+'a>>;
}
#[derive(Default,Debug,Clone,Serialize,Deserialize,PartialEq,Eq)]
pub(crate) struct QuoteWarmEffects{pub helper_requested:bool,pub unavailable:Option<String>,pub bsky_requested:bool,pub dispatched:bool,pub lock_stamped:bool}
fn normalized(raw:&str)->&str{crate::library::card::php_trim(raw).trim_end_matches('/')}
fn hash(raw:&str)->String{hex::encode(Sha256::digest(raw.as_bytes()))}
fn captures(pattern:&str,input:&str)->Option<Vec<String>>{regex::Regex::new(pattern).unwrap().captures(input).map(|c|c.iter().skip(1).map(|m|m.map(|v|v.as_str().to_owned()).unwrap_or_default()).collect())}
fn percent(raw:&str)->String{raw.bytes().map(|b|if b.is_ascii_alphanumeric()||b"-_.~".contains(&b){(b as char).to_string()}else{format!("%{b:02X}")}).collect()}
/// Exact PHP ap_object_url_lookup_candidates, including AP path variants.
pub(crate) fn lookup_candidates(raw:&str)->Vec<String>{
 let url=normalized(raw);if url.is_empty()||!url.starts_with("https://"){return vec![]}
 let mut out=vec![url.to_owned()];if let Some(base)=url.strip_suffix("/activity"){if !base.is_empty(){out.push(base.trim_end_matches('/').into())}}
 if let Some(c)=captures(r"^(https://[^/]+)/@([^/]+)/([A-Za-z0-9_-]+)$",url){let u=percent(&c[1]);for path in [format!("/users/{u}/statuses/{}",c[2]),format!("/ap/users/{u}/statuses/{}",c[2]),format!("/users/{u}/posts/{}",c[2]),format!("/ap/users/{u}/posts/{}",c[2]),format!("/users/{u}/notes/{}",c[2]),format!("/notes/{}",c[2])]{out.push(format!("{}{path}",c[0]))}}
 if let Some(c)=captures(r"^(https://[^/]+)/(?:ap/)?users/([^/]+)/(statuses|posts|notes)/([A-Za-z0-9_-]+)$",url){out.push(format!("{}/@{}/{}",c[0],crate::hidden::raw_percent_decode(&c[1]),c[3]));for kind in ["statuses","posts","notes"]{out.push(format!("{}/users/{}/{kind}/{}",c[0],c[1],c[3]));out.push(format!("{}/ap/users/{}/{kind}/{}",c[0],c[1],c[3]));}out.push(format!("{}/notes/{}",c[0],c[3]));}
 if let Some(c)=captures(r"(?i)^(https://[^\s]+/statuses/[^/]+)/QuotePost/?$",url){out.push(c[0].clone())}
 let mut seen=std::collections::HashSet::new();out.into_iter().map(|s|s.trim_end_matches('/').to_owned()).filter(|s|!s.is_empty()&&s.starts_with("https://")&&seen.insert(s.clone())).collect()
}
pub(crate) fn status_shape(raw:&str)->bool{
 let url=normalized(raw);if !url.starts_with("https://"){return false}
 // This PHP helper intentionally uses the broad Bridgy substring predicate.
 if url.starts_with("https://bsky.app/")||url.contains("bsky.brid.gy"){return true}
 let Ok(parsed)=reqwest::Url::parse(url)else{return false};let host=parsed.host_str().unwrap_or("").to_ascii_lowercase();let path=parsed.path();if host.is_empty()||path.is_empty()||path=="/"{return false}
 if (host=="bsky.app"||host.ends_with(".bsky.app"))&&captures(r"/profile/[^/]+/post/[A-Za-z0-9_-]+",path).is_some(){return true}
 if captures(r"(?:^|\.)(youtu\.be|youtube\.com|wikipedia\.org|bloomberg\.com|twitter\.com|x\.com|tiktok\.com|instagram\.com|facebook\.com|reddit\.com|twitch\.tv)$",&host).is_some(){return false}
 if captures(r"(?i)\.(?:jpe?g|png|gif|webp|mp4|webm|mp3|pdf|svg)(?:$|\?)",path).is_some(){return false}
 captures(r"(?i)/(?:statuses|posts|notes|objects|notice|activity|videos/watch|w)/[A-Za-z0-9_-]+",path).is_some()||captures(r"/@[^/]+/[0-9A-Za-z_-]+$",path).is_some()||captures(r"/post/[0-9]+",path).is_some()||(host.contains("threads.net")&&path.contains("/post/"))
}
pub(crate) fn direct_at_uri(raw:&str)->Option<String>{
 let url=crate::library::card::php_trim(raw);
 if url.starts_with("at://"){return captures(r"^at://[^/]+/app\.bsky\.feed\.post/[A-Za-z0-9]+",url).map(|_|url.into())}
 if let Some(c)=captures(r"(?i)(?:^|/)(?:convert/ap/)?at://(did:[^/\s]+)/app\.bsky\.feed\.post/([A-Za-z0-9]+)(?:[/?#]|$)",url){return Some(format!("at://{}/app.bsky.feed.post/{}",c[0],c[1]))}
 if let Some(c)=captures(r"(?i)at%3A%2F%2F(did%3A[^/\s]+)/app\.bsky\.feed\.post/([A-Za-z0-9]+)",url){return Some(format!("at://{}/app.bsky.feed.post/{}",crate::hidden::raw_percent_decode(&c[0]),c[1]))}
 let web=if url.starts_with("https://bsky.app/"){url.to_owned()}else if let Some(c)=captures(r"(?i)^https://bsky\.brid\.gy/r/(https://bsky\.app/.+)$",url){c[0].clone()}else{return None};
 let c=captures(r"(?i)^https://bsky\.app/profile/([^/]+)/post/([^/?#]+)",&web)?;let actor=crate::hidden::raw_percent_decode(&c[0]);let key=crate::hidden::raw_percent_decode(&c[1]);actor.starts_with("did:").then(||format!("at://{actor}/app.bsky.feed.post/{key}"))
}
struct BudgetBoundGrant<'a>{current:&'a dyn CurrentQuoteGrant,deadline:Option<f64>}
impl CurrentQuoteGrant for BudgetBoundGrant<'_>{fn require_current(&self)->GrantFuture<'_>{self.current.require_current()}fn bsky_budget_remaining(&self)->Option<f64>{self.deadline.map(|deadline|deadline-std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|v|v.as_secs_f64()).unwrap_or(0.0)).or_else(||self.current.bsky_budget_remaining())}}
pub(crate) struct QuoteWarmContext{temp_root:PathBuf,transport:std::sync::Arc<dyn QuoteWarmTransport+Send>,bsky_budget_deadline:Option<f64>}
/// Parent-admitted pending chrome, never a fabricated quoted status/account.
/// A caller must already have attempted the actual current child lookup and
/// ruled out an audience-denied child. Missing and deleted presentation alone
/// cannot establish that authorization.
pub(crate) struct PendingQuotePresentation{facts:crate::profile_raw::RawQuoteFacts,warm_needed:bool}
impl PendingQuotePresentation{
 pub(crate) fn render(&self,facts:&crate::library::card::CardFacts)->Result<String>{crate::profile_raw::render_quote(&self.facts,facts)}
 pub(crate) fn preview_text(&self)->&str{&self.facts.text}
 pub(crate) fn url(&self)->&str{&self.facts.url}
 pub(crate) fn warm_needed(&self)->bool{self.warm_needed}
}
impl QuoteWarmContext{
 pub(crate) fn new(transport:std::sync::Arc<dyn QuoteWarmTransport+Send>)->Self{Self{temp_root:std::env::temp_dir(),transport,bsky_budget_deadline:None}}
 pub(crate) fn with_bsky_budget_deadline(mut self,deadline:Option<f64>)->Self{self.bsky_budget_deadline=deadline;self}
 /// Exact final pending branch of ap-admin.php:17962 after real lookup,
 /// Bsky cache and summary/fallback quote options have already missed.
 pub(crate) async fn prepare_pending(&self,db:&Client,target:&str,now:i64,grant:&dyn CurrentQuoteGrant)->Result<PendingQuotePresentation>{
  grant.require_current().await?;anyhow::ensure!(!target.is_empty(),"pending quote chrome requires the actual nonempty target");let unavailable=self.unavailable(db,target,now,grant).await?;let denied=unavailable.is_some()||!status_shape(target);grant.require_current().await?;
  Ok(PendingQuotePresentation{facts:crate::profile_raw::RawQuoteFacts{acct:String::new(),text:if denied{"Quoted post unavailable".into()}else{String::new()},url:target.into(),media:vec![],card:None,mentions:vec![],spoiler:String::new(),sensitive:false,is_rss:false,article_url:String::new(),open_label:if denied{"Open original".into()}else{"Quoted post (not cached yet) — open".into()},open_external:denied},warm_needed:!denied})
 }
 pub(crate) async fn unavailable(&self,db:&Client,raw:&str,now:i64,grant:&dyn CurrentQuoteGrant)->Result<Option<String>>{
  grant.require_current().await?;let url=normalized(raw);if !url.starts_with("https://"){return Ok(None)}
  let miss=self.temp_root.join(format!("vaak-quote-warm-miss-{}",hash(url)));
  if let Ok(meta)=std::fs::metadata(&miss){if meta.is_file(){if let Ok(modified)=meta.modified(){let stamp=modified.duration_since(std::time::UNIX_EPOCH).map(|v|v.as_secs() as i64).unwrap_or(0);let age=now-stamp;if (0..1800).contains(&age){let raw=std::fs::read_to_string(&miss).unwrap_or_default();let reason=if matches!(crate::library::card::php_trim(&raw),"deleted"|"tombstone"){"deleted"}else{"miss"};grant.require_current().await?;return Ok(Some(reason.into()))}}}}
  let cands=lookup_candidates(url);let cands=cands.iter().flat_map(|s|[s.clone(),format!("{}/",s.trim_end_matches('/'))]).collect::<Vec<_>>();
  grant.require_current().await?;let deleted=db.query_opt("SELECT 1 FROM events WHERE type='Delete' AND object_id=ANY($1::text[]) LIMIT 1",&[&cands]).await;grant.require_current().await?;
  match deleted{Ok(Some(_))=>return Ok(Some("deleted".into())),Err(_)=>return Ok(None),Ok(None)=>{}}
  let deleted=db.query_opt("SELECT 1 FROM events WHERE type IN('Create','Update') AND COALESCE(action_taken,'')='deleted' AND object_id=ANY($1::text[]) LIMIT 1",&[&cands]).await;grant.require_current().await?;Ok(deleted.ok().flatten().map(|_|"deleted".into()))
 }
 async fn at_uri(&self,db:&Client,url:&str,grant:&dyn CurrentQuoteGrant)->Result<Option<String>>{
  if let Some(uri)=direct_at_uri(url){return Ok(Some(uri))}
  let web=if url.starts_with("https://bsky.app/"){url.to_owned()}else if let Some(c)=captures(r"(?i)^https://bsky\.brid\.gy/r/(https://bsky\.app/.+)$",url){c[0].clone()}else{return Ok(None)};
  let Some(c)=captures(r"(?i)^https://bsky\.app/profile/([^/]+)/post/([^/?#]+)",&web)else{return Ok(None)};let actor=crate::hidden::raw_percent_decode(&c[0]);let key=crate::hidden::raw_percent_decode(&c[1]);
  for(sql,params)in [("SELECT bsky_uri FROM bsky_crossposts WHERE bsky_uri LIKE $1 LIMIT 1",vec![format!("%/app.bsky.feed.post/{key}")]),("SELECT bsky_uri FROM bsky_posts WHERE bsky_uri LIKE $1 AND lower(author_handle)=lower($2) LIMIT 1",vec![format!("%/{key}"),actor.clone()]),("SELECT bsky_uri FROM bsky_posts WHERE bsky_uri LIKE $1 LIMIT 1",vec![format!("%/{key}")])]{grant.require_current().await?;let refs=params.iter().map(|v|v as &(dyn tokio_postgres::types::ToSql+Sync)).collect::<Vec<_>>();let row=db.query_opt(sql,&refs).await;grant.require_current().await?;if let Some(row)=row.ok().flatten(){if let Some(uri)=row.get::<_,Option<String>>(0).filter(|s|!s.is_empty()&&s!="0"){if sql.contains("crossposts")||uri.starts_with("at://"){return Ok(Some(uri))}}}}
  grant.require_current().await?;anyhow::ensure!(self.bsky_budget_deadline.is_none_or(f64::is_finite),"invalid server-bound Bluesky budget deadline");let bound=BudgetBoundGrant{current:grant,deadline:self.bsky_budget_deadline};let did=self.transport.resolve_handle(&actor,&bound).await?;grant.require_current().await?;Ok(did.filter(|s|s.starts_with("did:")).map(|s|format!("at://{s}/app.bsky.feed.post/{key}")))
 }
 pub(crate) async fn warm(&self,db:&Client,raw:&str,owner:i64,now:i64,grant:&dyn CurrentQuoteGrant)->Result<QuoteWarmEffects>{
  grant.require_current().await?;let mut effects=QuoteWarmEffects{helper_requested:true,..Default::default()};let url=normalized(raw);if !status_shape(url){return Ok(effects)}
  effects.unavailable=self.unavailable(db,url,now,grant).await?;if effects.unavailable.is_some(){return Ok(effects)}
  if let Some(uri)=self.at_uri(db,url,grant).await?{effects.bsky_requested=true;grant.require_current().await?;effects.dispatched=self.transport.bsky_warm(&uri,owner.max(0),now,grant).await?;grant.require_current().await?;return Ok(effects)}
  grant.require_current().await?;let path=self.temp_root.join(format!("vaak-quote-warm-{}.lock",hash(url)));let Ok(mut lock)=std::fs::OpenOptions::new().read(true).write(true).create(true).truncate(false).open(path)else{return Ok(effects)};if lock.try_lock().is_err(){return Ok(effects)}
  let mut stamp=String::new();let _=lock.read_to_string(&mut stamp);let prev=php_int(&stamp);if prev>0&&now-prev<90{return Ok(effects)}
  grant.require_current().await?;let _=lock.set_len(0);let _=lock.seek(SeekFrom::Start(0));let _=write!(lock,"{now}");let _=lock.flush();effects.lock_stamped=true;
  effects.dispatched=self.transport.quote_process(url,grant).await?;grant.require_current().await?;drop(lock);Ok(effects)
 }
}
pub(crate) fn php_int(raw:&str)->i64{let raw=crate::library::card::php_trim(raw);let Some(found)=regex::Regex::new(r"^[+-]?(?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:[eE][+-]?[0-9]+)?").unwrap().find(raw)else{return 0};let number=found.as_str();number.parse::<i64>().unwrap_or_else(|_|number.parse::<f64>().unwrap_or(0.0) as i64)}
/// Explicit private worker/queue boundary. No owner defaults or credentials
/// are taken from Config: handle resolution is the PHP helper's owner-zero
/// public resolver, and the queue receives the already admitted caller owner.
pub(crate) fn php_cli_binary()->PathBuf{use std::os::unix::fs::PermissionsExt;for path in ["/usr/bin/php8.3","/usr/bin/php","/usr/local/bin/php"]{if std::fs::metadata(path).is_ok_and(|m|m.is_file()&&m.permissions().mode()&0o111!=0){return path.into()}}"php".into()}
pub(crate) struct ProcessQuoteTransport{cfg:crate::config::Config,api_root:PathBuf,php:PathBuf,temp_root:PathBuf,#[cfg(test)]xrpc_fixture:Option<(std::net::SocketAddr,reqwest::Certificate)>}
impl ProcessQuoteTransport{
 pub(crate) fn configured(cfg:crate::config::Config)->Self{let mut php=std::env::var_os("VAAK_PHP_BIN").map(PathBuf::from).unwrap_or_else(php_cli_binary);if php.as_os_str().is_empty()||php.to_string_lossy().contains("php-fpm"){php=php_cli_binary()}Self{cfg,api_root:std::env::var_os("VAAK_API_ROOT").map(PathBuf::from).unwrap_or_else(||"/srv/mkultra/html/api".into()),php,temp_root:std::env::temp_dir(),#[cfg(test)]xrpc_fixture:None}}
 fn spawn(&self,script:&str,args:&[String],log:bool)->bool{let script=self.api_root.join(script);if !script.is_file(){return false}let mut cmd=tokio::process::Command::new("nohup");cmd.arg(&self.php).arg(script).args(args).stdin(Stdio::null());if log{if let Ok(file)=std::fs::OpenOptions::new().create(true).append(true).open(self.temp_root.join("vaak-quote-warm.log")){if let Ok(error)=file.try_clone(){cmd.stdout(Stdio::from(file)).stderr(Stdio::from(error));}else{return false}}else{return false}}else{cmd.stdout(Stdio::null()).stderr(Stdio::null());}match cmd.spawn(){Ok(mut child)=>{tokio::spawn(async move{let _=child.wait().await;});true},Err(_)=>false}}
}
impl ProcessQuoteTransport{
 async fn public_handle(&self,handle:&str,grant:&dyn CurrentQuoteGrant)->Result<Option<String>>{
  let handle=crate::library::card::php_trim(handle).trim_start_matches('@');if handle.is_empty(){return Ok(None)}
  let effects=crate::profile_preview_transport::ProcessPreviewTransport::configured(self.cfg.clone());
  for(method,key)in [("com.atproto.identity.resolveHandle","handle"),("app.bsky.actor.getProfile","actor")]{
   grant.require_current().await?;let remaining=grant.bsky_budget_remaining();if remaining.is_some_and(|left|left<=0.05){return Ok(None)}let timeout=remaining.map(|left|8.min(left.floor() as u64).max(1)).unwrap_or(8);
   let base=self.cfg.bsky_public_api.trim_end_matches('/');if !base.starts_with("https://"){return Ok(None)}let parsed=reqwest::Url::parse(base)?;let provider=format!("bluesky:{}",parsed.host_str().unwrap_or(base).to_ascii_lowercase());
   if !effects.circuit_allow(&provider,chrono::Utc::now().timestamp(),grant).await?{continue}
   let builder=reqwest::Client::builder().redirect(reqwest::redirect::Policy::none()).connect_timeout(Duration::from_secs(timeout.min(2))).user_agent("VAAK-Bluesky/1.0 (+https://mkultra.monster/vaak)");#[cfg(test)]let builder=if let Some((address,cert))=&self.xrpc_fixture{builder.no_proxy().resolve_to_addrs("public.test",&[*address]).add_root_certificate(cert.clone())}else{builder};let client=builder.build()?;
   let mut url=reqwest::Url::parse(&format!("{base}/xrpc/{method}"))?;url.query_pairs_mut().append_pair(key,handle);
   let started=std::time::Instant::now();let operation=async{
    for hop in 0..=3{grant.require_current().await?;let reply=client.get(url.clone()).header("Accept","application/json").send().await;grant.require_current().await?;let mut reply=match reply{Ok(reply)=>reply,Err(error)=>{#[cfg(test)]eprintln!("public handle HTTP transport: {error:?}");return Ok::<_,anyhow::Error>(None)}};
     let status=reply.status().as_u16();let location=reply.headers().get(reqwest::header::LOCATION).and_then(|v|v.to_str().ok()).map(str::to_owned);let mut bytes=Vec::new();loop{grant.require_current().await?;let chunk=reply.chunk().await;grant.require_current().await?;match chunk{Ok(Some(chunk))=>{anyhow::ensure!(bytes.len().saturating_add(chunk.len())<=65536,"public XRPC reply exceeds native bounded body; whole request fallback required");bytes.extend_from_slice(&chunk)},Ok(None)=>break,Err(_)=>return Ok(None)}}
     if matches!(status,301|302|303|307|308){if let Some(location)=location{if hop==3{return Ok(None)}url=url.join(&location)?;continue}}
     return Ok(Some((status,bytes)))
    }Ok(None)
   };
   grant.require_current().await?;let reply=tokio::time::timeout(Duration::from_secs(timeout),operation).await;grant.require_current().await?;
   effects.timing_named("bluesky.xrpc",started.elapsed().as_secs_f64()*1000.0,grant).await?;
   let reply=match reply{Ok(reply)=>reply?,Err(_)=>None};let failed=reply.as_ref().is_none_or(|(code,_)|*code>=500||*code==429);effects.circuit_result(&provider,failed,chrono::Utc::now().timestamp(),grant).await?;
   if let Some((status,bytes))=reply{if(200..300).contains(&status){if let Some(did)=serde_json::from_slice::<serde_json::Value>(&bytes).ok().and_then(|v|v["did"].as_str().map(str::to_owned)).filter(|v|!v.is_empty()&&v!="0"){return Ok(Some(did))}}}
  }Ok(None)
 }
}
impl QuoteWarmTransport for ProcessQuoteTransport{
 fn quote_process<'a>(&'a self,url:&'a str,grant:&'a dyn CurrentQuoteGrant)->EffectFuture<'a>{Box::pin(async move{grant.require_current().await?;let result=self.spawn("ap-quote-target-warm.php",&[url.into()],true);grant.require_current().await?;Ok(result)})}
 fn bsky_warm<'a>(&'a self,uri:&'a str,owner:i64,now:i64,grant:&'a dyn CurrentQuoteGrant)->EffectFuture<'a>{Box::pin(async move{
  grant.require_current().await?;let redis_enabled=std::env::var("VAAK_REDIS_ENABLED").map(|v|!matches!(crate::library::card::php_trim(&v).to_ascii_lowercase().as_str(),"0"|"false"|"off"|"no")).unwrap_or(true);let mut conn=if redis_enabled{tokio::time::timeout(Duration::from_millis(80),crate::redis_util::connect(&self.cfg.redis_queue_url)).await.ok().and_then(Result::ok)}else{None};grant.require_current().await?;
  // PHP's ap_redis_lock false includes unavailable Redis, even primary-off.
  let Some(ref mut conn)=conn else{return Ok(false)};grant.require_current().await?;let locked=tokio::time::timeout(Duration::from_millis(80),crate::redis_util::lock(conn,&format!("bsky-post-warm:{}",hash(uri)),45,&std::process::id().to_string())).await;grant.require_current().await?;if !matches!(locked,Ok(Ok(true))){return Ok(false)}
  let primary=std::env::var("VAAK_THIN_MEDIA_RUST_PRIMARY").map(|v|!matches!(crate::library::card::php_trim(&v).to_ascii_lowercase().as_str(),"0"|"false"|"off"|"no")).unwrap_or(true);
  if primary{let payload=serde_json::json!({"uri":uri,"owner":owner.max(0),"ts":now,"source":"php-enqueue"}).to_string();grant.require_current().await?;let pushed=tokio::time::timeout(Duration::from_millis(80),redis::cmd("LPUSH").arg("vaak:queue:bsky_post_warm").arg(&payload).query_async::<i64>(conn)).await;grant.require_current().await?;if matches!(pushed,Ok(Ok(_))){grant.require_current().await?;let expired=tokio::time::timeout(Duration::from_millis(80),redis::cmd("EXPIRE").arg("vaak:queue:bsky_post_warm").arg(86400).query_async::<i64>(conn)).await;grant.require_current().await?;if matches!(expired,Ok(Ok(_))){return Ok(true)}}}
  if !self.api_root.join("ap-bsky-post-warm.php").is_file(){return Ok(false)}grant.require_current().await?;let path=self.temp_root.join(format!("vaak-bsky-post-warm-{}.lock",hash(uri)));let _=std::fs::write(&path,now.to_string());let dispatched=self.spawn("ap-bsky-post-warm.php",&[format!("--uri={uri}"),format!("--owner={}",owner.max(0)),format!("--lock={}",path.display())],false);grant.require_current().await?;Ok(dispatched)
 })}
 fn resolve_handle<'a>(&'a self,handle:&'a str,grant:&'a dyn CurrentQuoteGrant)->Pin<Box<dyn Future<Output=Result<Option<String>>>+Send+'a>>{Box::pin(async move{self.public_handle(handle,grant).await})}
}
#[cfg(test)]mod tests{
 use super::*;
 struct Allowed;impl CurrentQuoteGrant for Allowed{fn require_current(&self)->GrantFuture<'_>{Box::pin(async{Ok(())})}}
 struct Recorded{scenario:serde_json::Value,effects:std::sync::Mutex<Vec<serde_json::Value>>}
 impl QuoteWarmTransport for Recorded{
  fn quote_process<'a>(&'a self,url:&'a str,grant:&'a dyn CurrentQuoteGrant)->EffectFuture<'a>{Box::pin(async move{grant.require_current().await?;self.effects.lock().unwrap().push(serde_json::json!({"kind":"quote_process","url":url}));Ok(true)})}
  fn bsky_warm<'a>(&'a self,uri:&'a str,owner:i64,now:i64,grant:&'a dyn CurrentQuoteGrant)->EffectFuture<'a>{Box::pin(async move{grant.require_current().await?;self.effects.lock().unwrap().push(serde_json::json!({"kind":"bsky_boundary","uri":uri,"owner":owner,"ts":now}));Ok(self.scenario["lock"].as_bool().unwrap_or(true))})}
  fn resolve_handle<'a>(&'a self,handle:&'a str,grant:&'a dyn CurrentQuoteGrant)->Pin<Box<dyn Future<Output=Result<Option<String>>>+Send+'a>>{Box::pin(async move{grant.require_current().await?;self.effects.lock().unwrap().push(serde_json::json!({"kind":"resolve_handle_boundary","handle":handle,"owner":0}));Ok(Some("did:plc:resolved".into()))})}
 }
 #[tokio::test]#[ignore="actual PHP cache resolver and disposable PostgreSQL"]async fn actual_quote_handle_cache_reference()->Result<()>{
  let reference:serde_json::Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-quote-handle-cache-reference.json")?)?;let(db,connection)=tokio_postgres::connect(&std::env::var("PROFILE_FIXTURE_DB").unwrap_or_else(|_|"host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres".into()),tokio_postgres::NoTls).await?;let task=tokio::spawn(async move{connection.await.unwrap()});let schema=format!("profile_quote_handle_{}",std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema};CREATE TABLE bsky_crossposts(bsky_uri TEXT);CREATE TABLE bsky_posts(bsky_uri TEXT,author_handle TEXT)")).await?;
  struct Resolver{calls:std::sync::Mutex<Vec<serde_json::Value>>}impl QuoteWarmTransport for Resolver{fn quote_process<'a>(&'a self,_:&'a str,_:&'a dyn CurrentQuoteGrant)->EffectFuture<'a>{Box::pin(async{panic!("cache lookup must not dispatch")})}fn bsky_warm<'a>(&'a self,_:&'a str,_:i64,_:i64,_:&'a dyn CurrentQuoteGrant)->EffectFuture<'a>{Box::pin(async{panic!("cache lookup must not enqueue")})}fn resolve_handle<'a>(&'a self,handle:&'a str,grant:&'a dyn CurrentQuoteGrant)->Pin<Box<dyn Future<Output=Result<Option<String>>>+Send+'a>>{Box::pin(async move{grant.require_current().await?;self.calls.lock().unwrap().push(serde_json::json!({"handle":handle,"owner":0}));Ok(Some("did:plc:network".into()))})}}
  let mut captures=Vec::new();for case in reference["captures"].as_array().unwrap(){db.batch_execute("DELETE FROM bsky_crossposts;DELETE FROM bsky_posts").await?;if let Some(uri)=case["crosspost"].as_str(){db.execute("INSERT INTO bsky_crossposts VALUES($1)",&[&uri]).await?;}if let Some(uri)=case["cached"].as_str(){db.execute("INSERT INTO bsky_posts VALUES($1,$2)",&[&uri,&case["author"].as_str()]).await?;}let transport=std::sync::Arc::new(Resolver{calls:Default::default()});let context=QuoteWarmContext::new(transport.clone());let result=context.at_uri(&db,case["url"].as_str().unwrap(),&Allowed).await?;assert_eq!(serde_json::to_value(&result)?,case["result"]);let calls=transport.calls.lock().unwrap().clone();assert_eq!(serde_json::to_value(&calls)?,case["resolve"]);captures.push(serde_json::json!({"case":case["case"],"result":result,"resolve":calls}));}db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await?;drop(db);task.abort();std::fs::write("/tmp/vaak-profile-quote-handle-cache-native.json",serde_json::to_vec_pretty(&serde_json::json!({"captures":captures}))?)?;assert_eq!(captures.len(),6);Ok(())
 }
 #[tokio::test]#[ignore="actual owned TLS and genuine PHP owner-zero XRPC oracle"]async fn actual_quote_handle_http_reference()->Result<()>{
  use tokio::io::{AsyncBufReadExt,AsyncReadExt,AsyncWriteExt,BufReader};
  let reference:serde_json::Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-quote-handle-http-reference.json")?)?;let port=reference["port"].as_u64().unwrap() as u16;let cert=reqwest::Certificate::from_pem(&std::fs::read(std::env::var("PROFILE_QUOTE_HANDLE_CERT")?)?)?;let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await?;let redis_port=listener.local_addr()?.port();
  let commands=std::sync::Arc::new(std::sync::Mutex::new(Vec::<Vec<String>>::new()));let open=std::sync::Arc::new(std::sync::atomic::AtomicBool::new(false));let recorder=commands.clone();let is_open=open.clone();
  let server=tokio::spawn(async move{loop{let Ok((stream,_))=listener.accept().await else{return};let recorder=recorder.clone();let is_open=is_open.clone();tokio::spawn(async move{let(read,mut write)=stream.into_split();let mut reader=BufReader::new(read);loop{let mut head=String::new();if reader.read_line(&mut head).await.unwrap_or(0)==0{break}let Ok(count)=head.trim().trim_start_matches('*').parse::<usize>()else{break};let mut args=Vec::new();for _ in 0..count{head.clear();if reader.read_line(&mut head).await.is_err(){return}let Ok(len)=head.trim().trim_start_matches('$').parse::<usize>()else{return};let mut bytes=vec![0;len+2];if reader.read_exact(&mut bytes).await.is_err(){return}args.push(String::from_utf8_lossy(&bytes[..len]).into_owned());}recorder.lock().unwrap().push(args.clone());let answer=if args[0]=="GET"&&is_open.load(std::sync::atomic::Ordering::SeqCst){let state=serde_json::json!({"open_until":chrono::Utc::now().timestamp()+3600,"failures":3}).to_string();format!("${}\r\n{state}\r\n",state.len())}else if matches!(args[0].as_str(),"GET"|"HGET"){"$-1\r\n".into()}else if args[0]=="HINCRBYFLOAT"{"$1\r\n1\r\n".into()}else if matches!(args[0].as_str(),"HINCRBY"|"HSET"|"LPUSH"|"EXPIRE"|"LTRIM"|"DEL"){":1\r\n".into()}else{"+OK\r\n".into()};if write.write_all(answer.as_bytes()).await.is_err(){return}}});}});
  struct Budget{deadline:Option<std::time::Instant>}impl CurrentQuoteGrant for Budget{fn require_current(&self)->GrantFuture<'_>{Box::pin(async{Ok(())})}fn bsky_budget_remaining(&self)->Option<f64>{self.deadline.map(|d|if d>std::time::Instant::now(){d.duration_since(std::time::Instant::now()).as_secs_f64()}else{-1.0})}}
  let mut captures=Vec::new();for case in reference["captures"].as_array().unwrap(){let cfg=crate::config::Config{database_url:String::new(),redis_url:format!("redis://127.0.0.1:{redis_port}/0"),redis_queue_url:String::new(),jetstream_state:PathBuf::new(),default_owner_id:999,bsky_public_api:format!("https://public.test:{port}"),notif_cache_dir:PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};let mut transport=ProcessQuoteTransport::configured(cfg);transport.xrpc_fixture=Some((std::net::SocketAddr::from(([127,0,0,1],port)),cert.clone()));std::env::set_var("VAAK_REDIS_ENABLED","1");open.store(case["circuit_open"]==true,std::sync::atomic::Ordering::SeqCst);commands.lock().unwrap().clear();let budget=Budget{deadline:case["budget"].as_f64().map(|left|if left>0.0{std::time::Instant::now()+Duration::from_secs_f64(left)}else{std::time::Instant::now()-Duration::from_secs(1)})};let result=transport.resolve_handle(case["handle"].as_str().unwrap(),&budget).await?;assert_eq!(serde_json::to_value(&result)?,case["result"],"{:?}",case["case"]);let calls=commands.lock().unwrap().clone();let mut effects=Vec::new();let mut measured=false;let circuit_key=format!("vaak:circuit:v1:{}",hash("bluesky:public.test"));for args in &calls{match args[0].as_str(){"GET" if args[1]==circuit_key=>{if !measured{effects.push(serde_json::json!({"kind":"allow","provider":"bluesky:public.test"}))}},"HINCRBY" if args[1]=="vaak:timing:v1:bluesky.xrpc"&&args[2]=="count"=>{measured=true;effects.push(serde_json::json!({"kind":"timing","name":"bluesky.xrpc"}))},"DEL" if args[1]==circuit_key=>{measured=false;effects.push(serde_json::json!({"kind":"success","provider":"bluesky:public.test"}))},"SET" if args[1]==circuit_key=>{measured=false;effects.push(serde_json::json!({"kind":"failure","provider":"bluesky:public.test"}))},_=>{}}}assert_eq!(serde_json::to_value(&effects)?,case["effects"],"actual HTTP/circuit/timing {:?}",case["case"]);captures.push(serde_json::json!({"case":case["case"],"result":result,"effects":effects,"redis_commands":calls}));}
  std::env::remove_var("VAAK_REDIS_ENABLED");server.abort();std::fs::write("/tmp/vaak-profile-quote-handle-http-native.json",serde_json::to_vec_pretty(&serde_json::json!({"captures":captures}))?)?;assert_eq!(captures.len(),16);Ok(())
 }
 #[tokio::test]#[ignore="actual SQL unavailable aliases and file locks in disposable PostgreSQL"]async fn actual_quote_warm_pg_lock_reference(){
  let dburl=std::env::var("PROFILE_FIXTURE_DB").unwrap_or_else(|_|"host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres".into());let(db,connection)=tokio_postgres::connect(&dburl,tokio_postgres::NoTls).await.unwrap();let task=tokio::spawn(async move{connection.await.unwrap()});let schema=format!("profile_quote_warm_{}",std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema};CREATE TABLE events(object_id TEXT,type TEXT,action_taken TEXT);CREATE TABLE bsky_crossposts(bsky_uri TEXT);CREATE TABLE bsky_posts(bsky_uri TEXT,author_handle TEXT)")).await.unwrap();
  let raw=std::fs::read_to_string(std::env::var("PROFILE_QUOTE_WARM_REFERENCE").unwrap_or_else(|_|"/tmp/vaak-profile-quote-warm-reference.json".into())).unwrap();let reference:serde_json::Value=serde_json::from_str(&raw).unwrap();let now=reference["clock"].as_i64().unwrap();let temp=std::env::temp_dir().join(format!("vaak-native-quote-helper-{}",std::process::id()));std::fs::create_dir(&temp).unwrap();let mut results=Vec::new();
  for capture in reference["captures"].as_array().unwrap(){for path in std::fs::read_dir(&temp).unwrap(){std::fs::remove_file(path.unwrap().path()).unwrap()}db.execute("DELETE FROM events",&[]).await.unwrap();let scenario=&capture["case"];let url=capture["url"].as_str().unwrap();let recorder=std::sync::Arc::new(Recorded{scenario:scenario.clone(),effects:Default::default()});let context=QuoteWarmContext{temp_root:temp.clone(),transport:recorder.clone(),bsky_budget_deadline:None};
   if let Some(prev)=scenario.get("previous"){let stamp=if let Some(s)=prev.as_str(){s.to_owned()}else{prev.to_string()};std::fs::write(temp.join(format!("vaak-quote-warm-{}.lock",hash(url))),stamp).unwrap()}
   if let Some(miss)=scenario["miss"].as_str(){let path=temp.join(format!("vaak-quote-warm-miss-{}",hash(url)));std::fs::write(&path,miss).unwrap();let file=std::fs::OpenOptions::new().write(true).open(path).unwrap();file.set_times(std::fs::FileTimes::new().set_modified(std::time::UNIX_EPOCH+Duration::from_secs((now-scenario["miss_age"].as_i64().unwrap()) as u64))).unwrap()}
   if let Some(object)=scenario["delete"].as_str(){db.execute("INSERT INTO events VALUES($1,'Delete','log')",&[&object]).await.unwrap();}if scenario["deleted_update"].as_bool()==Some(true){db.execute("INSERT INTO events VALUES($1,'Update','deleted')",&[&url]).await.unwrap();}
   let effects=context.warm(&db,url,scenario["owner"].as_i64().unwrap_or(2),now,&Allowed).await.unwrap();assert_eq!(serde_json::to_value(&effects.unavailable).unwrap(),capture["unavailable"],"{:?}",scenario["name"]);let lock=temp.join(format!("vaak-quote-warm-{}.lock",hash(url)));let stamp=std::fs::read_to_string(lock).ok();assert_eq!(serde_json::to_value(stamp).unwrap(),capture["lock_stamp"],"{:?}",scenario["name"]);
   let requests=recorder.effects.lock().unwrap().clone();let expected=capture["effects"].as_array().unwrap();let bsky=expected.iter().any(|e|e["kind"]=="redis_lock");assert_eq!(requests.iter().any(|r|r["kind"]=="bsky_boundary"),bsky,"{:?}",scenario["name"]);let expected_process=expected.iter().any(|e|e["kind"]=="process_record_only");if !bsky{assert_eq!(requests.iter().any(|r|r["kind"]=="quote_process"),expected_process,"{:?}",scenario["name"])}results.push(serde_json::json!({"case":scenario["name"],"effects":effects,"requests":requests,"actual_sql_and_file_lock":true}));
  }let output=std::env::var("PROFILE_QUOTE_WARM_NATIVE_OUTPUT").unwrap_or_else(|_|"/tmp/vaak-profile-quote-warm-native.json".into());std::fs::write(output,serde_json::to_vec_pretty(&serde_json::json!({"captures":results,"scope":"Actual PG and file marker/lock/cooldown replay. Queue/process/handle transports recorded, not delivery."})).unwrap()).unwrap();for path in std::fs::read_dir(&temp).unwrap(){std::fs::remove_file(path.unwrap().path()).unwrap()}std::fs::remove_dir(temp).unwrap();db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();drop(db);task.abort();assert_eq!(results.len(),18);
 }
 #[tokio::test]#[ignore="isolated RESP wire and owned PHP argv recorder"]async fn actual_quote_bsky_wire_process_reference()->Result<()>{
  use tokio::io::{AsyncBufReadExt,AsyncReadExt,AsyncWriteExt,BufReader};
  let reference:serde_json::Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-quote-warm-reference.json")?)?;let mut results=Vec::new();let temp=std::env::temp_dir().join(format!("vaak-quote-wire-{}",std::process::id()));std::fs::create_dir(&temp)?;
  std::fs::write(temp.join("ap-bsky-post-warm.php"),"<?php file_put_contents(__DIR__.'/process.json',json_encode($argv));")?;
  for capture in reference["captures"].as_array().unwrap().iter().filter(|c|c["case"]["name"].as_str().unwrap().starts_with("bsky_")){
   let scenario=&capture["case"];let primary=scenario["primary"].as_str().unwrap_or("1");std::env::set_var("VAAK_THIN_MEDIA_RUST_PRIMARY",primary);std::env::set_var("VAAK_REDIS_ENABLED","1");let lock=scenario["lock"].as_bool().unwrap_or(true);let push=scenario["push"].as_bool().unwrap_or(true);let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await?;let port=listener.local_addr()?.port();let commands=std::sync::Arc::new(std::sync::Mutex::new(Vec::<Vec<String>>::new()));let recorder=commands.clone();
   let server=tokio::spawn(async move{let Ok((stream,_))=listener.accept().await else{return};let(read,mut write)=stream.into_split();let mut reader=BufReader::new(read);loop{let mut head=String::new();if reader.read_line(&mut head).await.unwrap_or(0)==0{break}let Ok(count)=head.trim().trim_start_matches('*').parse::<usize>()else{break};let mut args=Vec::new();for _ in 0..count{head.clear();if reader.read_line(&mut head).await.is_err(){return}let Ok(len)=head.trim().trim_start_matches('$').parse::<usize>()else{return};let mut bytes=vec![0;len+2];if reader.read_exact(&mut bytes).await.is_err(){return}args.push(String::from_utf8_lossy(&bytes[..len]).into_owned());}let answer=match args[0].as_str(){"SET"=>if lock{"+OK\r\n"}else{"$-1\r\n"},"LPUSH"=>if push{":1\r\n"}else{"-ERR fixture push failure\r\n"},"EXPIRE"=>":1\r\n",_=>"+OK\r\n"};if matches!(args[0].as_str(),"SET"|"LPUSH"|"EXPIRE"){recorder.lock().unwrap().push(args)}if write.write_all(answer.as_bytes()).await.is_err(){return}}});
   let cfg=crate::config::Config{database_url:String::new(),redis_url:String::new(),redis_queue_url:format!("redis://127.0.0.1:{port}/1"),jetstream_state:PathBuf::new(),default_owner_id:999,bsky_public_api:String::new(),notif_cache_dir:PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};let transport=ProcessQuoteTransport{cfg,api_root:temp.clone(),php:"/usr/bin/php".into(),temp_root:temp.clone(),xrpc_fixture:None};let uri=direct_at_uri(capture["url"].as_str().unwrap()).unwrap();let owner=scenario["owner"].as_i64().unwrap_or(2);let expected=capture["effects"].as_array().unwrap();let expected_process=expected.iter().any(|e|e["kind"]=="process_record_only");let dispatched=transport.bsky_warm(&uri,owner,reference["clock"].as_i64().unwrap(),&Allowed).await?;assert_eq!(dispatched,expected_process||expected.iter().any(|e|e["kind"]=="redis_push"&&e["result"]==true),"{:?}",scenario["name"]);
   let calls=commands.lock().unwrap().clone();let actual=calls.iter().find(|a|a[0]=="SET").unwrap();let expected_lock=expected.iter().find(|e|e["kind"]=="redis_lock").unwrap();assert_eq!(actual[1],format!("vaak:lock:{}",expected_lock["key"].as_str().unwrap()));assert_eq!(&actual[3..],&["NX","EX","45"]);if let Some(expected_push)=expected.iter().find(|e|e["kind"]=="redis_push"){let actual=calls.iter().find(|a|a[0]=="LPUSH").unwrap();assert_eq!(actual[1],"vaak:queue:bsky_post_warm");assert_eq!(serde_json::from_str::<serde_json::Value>(&actual[2])?,expected_push["payload"]);}
   let mut argv=None;if expected_process{for _ in 0..40{if let Ok(raw)=std::fs::read(temp.join("process.json")){argv=Some(serde_json::from_slice::<serde_json::Value>(&raw)?);break}tokio::time::sleep(Duration::from_millis(20)).await;}let actual=argv.as_ref().unwrap().as_array().unwrap();assert_eq!(actual[1],format!("--uri={uri}"));assert_eq!(actual[2],format!("--owner={owner}"));assert_eq!(actual[3],format!("--lock={}/vaak-bsky-post-warm-{}.lock",temp.display(),hash(&uri)));std::fs::remove_file(temp.join("process.json"))?;}
   results.push(serde_json::json!({"case":scenario["name"],"redis_commands":calls,"actual_stub_worker_argv":argv,"dispatched":dispatched}));server.abort();
  }
  std::env::remove_var("VAAK_THIN_MEDIA_RUST_PRIMARY");std::env::remove_var("VAAK_REDIS_ENABLED");for path in std::fs::read_dir(&temp)?{std::fs::remove_file(path?.path())?}std::fs::remove_dir(temp)?;std::fs::write("/tmp/vaak-profile-quote-bsky-wire-native.json",serde_json::to_vec_pretty(&serde_json::json!({"captures":results,"scope":"Actual isolated RESP wire and PHP stub worker argv; does not execute the real AppView/signed fetch worker."}))?)?;assert_eq!(results.len(),5);Ok(())
 }
 #[test]#[ignore="requires explicit genuine PHP quote helper oracle"]fn actual_php_quote_helper_pure_reference(){let path=std::env::var("PROFILE_QUOTE_WARM_REFERENCE").unwrap_or_else(|_|"/tmp/vaak-profile-quote-warm-reference.json".into());let raw=std::fs::read_to_string(path).expect("explicit PHP helper oracle");let reference:serde_json::Value=serde_json::from_str(&raw).unwrap();let cases=reference["pure"].as_array().unwrap();for case in cases{let input=case["input"].as_str().unwrap();assert_eq!(status_shape(input),case["status_shape"].as_bool().unwrap(),"{input}");assert_eq!(lookup_candidates(input),case["aliases"].as_array().unwrap().iter().map(|v|v.as_str().unwrap().to_owned()).collect::<Vec<_>>(),"{input}");if !input.contains("profile/a/"){assert_eq!(direct_at_uri(input),case["at_uri"].as_str().map(str::to_owned),"{input}")}}assert_eq!(cases.len(),23);}
}
