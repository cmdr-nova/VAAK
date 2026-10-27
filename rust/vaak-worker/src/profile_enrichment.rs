//! Private server-owned profile enrichment dependencies. No request field can
//! construct this context or substitute an audience/source grant.
use std::sync::Arc;
pub(crate) struct WarmContext {
    cfg:crate::config::Config,
    #[cfg(test)] fixture_clock:Option<chrono::DateTime<chrono::Utc>>,
    transport:Arc<dyn crate::profile_warm::WarmTransport+Send>,
    requests:Arc<std::sync::Mutex<(Vec<String>,Vec<String>)>>,
    avatar_memo:Arc<std::sync::Mutex<std::collections::HashMap<String,String>>>,
    emoji_memo:Arc<std::sync::Mutex<std::collections::HashMap<String,std::collections::HashMap<String,String>>>>,
    current:Option<Arc<dyn CurrentEnrichmentGrant>>,
    quote:Option<Arc<crate::profile_quote_warm::QuoteWarmContext>>,
    quote_requests:Arc<std::sync::Mutex<Vec<String>>>,
    preview:Option<Arc<crate::profile_preview_fetch::PreviewFetchContext>>,
}
pub(crate) trait CurrentEnrichmentGrant:Send+Sync{fn accept_preview_write(&self,_:&crate::profile_preview_fetch::PreviewCacheWrite)->anyhow::Result<()>{anyhow::bail!("PHP fallback: projection preview writer lacks receipt capability")} fn require_current<'a>(&'a self,db:&'a tokio_postgres::Client)->std::pin::Pin<Box<dyn std::future::Future<Output=anyhow::Result<()>>+Send+'a>>;}
impl WarmContext {
    pub(crate) fn bound(&self,current:Arc<dyn CurrentEnrichmentGrant>)->Arc<Self>{Arc::new(Self{cfg:self.cfg.clone(),#[cfg(test)] fixture_clock:self.fixture_clock,transport:self.transport.clone(),requests:self.requests.clone(),avatar_memo:self.avatar_memo.clone(),emoji_memo:self.emoji_memo.clone(),current:Some(current),quote:self.quote.clone(),quote_requests:self.quote_requests.clone(),preview:self.preview.clone()})}
    pub(crate) fn accept_preview_write(&self,write:&crate::profile_preview_fetch::PreviewCacheWrite)->anyhow::Result<()>{self.current.as_ref().ok_or_else(||anyhow::anyhow!("PHP fallback: preview write lacks bound current profile"))?.accept_preview_write(write)}
    pub(crate) async fn check_current(&self,db:&tokio_postgres::Client)->anyhow::Result<()>{if let Some(grant)=&self.current{grant.require_current(db).await?;}Ok(())}
    pub(crate) async fn actor_checked(&self,db:&tokio_postgres::Client,actor:&str)->anyhow::Result<()>{self.check_current(db).await?;if let Some(grant)=&self.current{self.requests.lock().expect("warm request ledger").0.push(actor.into());crate::profile_warm::actor_warm_guarded(&self.cfg,db,actor,self.transport(),grant.as_ref()).await?;}else{self.actor(actor).await;}self.check_current(db).await}
    pub(crate) async fn media_checked(&self,db:&tokio_postgres::Client,actor:&str)->anyhow::Result<()>{self.check_current(db).await?;if let Some(grant)=&self.current{let enabled=std::env::var("VAAK_FEATURE_REMOTE_MEDIA_WARM").ok().map(|value|crate::library::card::php_trim(&value).to_ascii_lowercase()).filter(|value|!value.is_empty()).is_none_or(|value|!matches!(value.as_str(),"0"|"false"|"off"|"no"));if enabled{self.requests.lock().expect("warm request ledger").1.push(actor.into());crate::profile_warm::media_warm_guarded(&self.cfg,db,actor,self.transport(),grant.as_ref()).await?;}}else{self.media(db,actor).await;}self.check_current(db).await}

    pub(crate) async fn actor_with_grant(&self,db:&tokio_postgres::Client,actor:&str,grant:&dyn CurrentEnrichmentGrant)->anyhow::Result<()>{self.check_current(db).await?;grant.require_current(db).await?;self.requests.lock().expect("warm request ledger").0.push(actor.into());crate::profile_warm::actor_warm_guarded(&self.cfg,db,actor,self.transport(),grant).await?;self.check_current(db).await}
    pub(crate) async fn media_with_grant(&self,db:&tokio_postgres::Client,actor:&str,grant:&dyn CurrentEnrichmentGrant)->anyhow::Result<()>{self.check_current(db).await?;grant.require_current(db).await?;let enabled=std::env::var("VAAK_FEATURE_REMOTE_MEDIA_WARM").ok().map(|value|crate::library::card::php_trim(&value).to_ascii_lowercase()).filter(|value|!value.is_empty()).is_none_or(|value|!matches!(value.as_str(),"0"|"false"|"off"|"no"));if enabled{self.requests.lock().expect("warm request ledger").1.push(actor.into());crate::profile_warm::media_warm_guarded(&self.cfg,db,actor,self.transport(),grant).await?;}self.check_current(db).await}
    pub(crate) fn new(cfg:crate::config::Config,transport:Arc<dyn crate::profile_warm::WarmTransport+Send>)->Self{Self{cfg,#[cfg(test)] fixture_clock:None,transport,requests:Arc::new(std::sync::Mutex::new((Vec::new(),Vec::new()))),avatar_memo:Arc::new(std::sync::Mutex::new(std::collections::HashMap::new())),emoji_memo:Arc::new(std::sync::Mutex::new(std::collections::HashMap::new())),current:None,quote:None,quote_requests:Arc::new(std::sync::Mutex::new(Vec::new())),preview:None}}
    #[cfg(test)] pub(crate) fn with_fixture_clock(mut self,clock:chrono::DateTime<chrono::Utc>)->Self{self.fixture_clock=Some(clock);self}
    #[cfg(test)] pub(crate) fn fixture_clock(&self)->Option<chrono::DateTime<chrono::Utc>>{self.fixture_clock}
    pub(crate) fn with_preview_context(mut self,preview:Arc<crate::profile_preview_fetch::PreviewFetchContext>)->Self{self.preview=Some(preview);self}
    pub(crate) fn preview_context(&self)->Option<&crate::profile_preview_fetch::PreviewFetchContext>{self.preview.as_deref()}
    pub(crate) fn with_quote_context(mut self,quote:Arc<crate::profile_quote_warm::QuoteWarmContext>)->Self{self.quote=Some(quote);self}
    pub(crate) fn record_quote_request(&self,target:&str){self.quote_requests.lock().expect("quote request ledger").push(target.into());}
    pub(crate) fn requested_quotes(&self)->Vec<String>{self.quote_requests.lock().expect("quote request ledger").clone()}
    pub(crate) fn quote_context(&self)->Option<&crate::profile_quote_warm::QuoteWarmContext>{self.quote.as_deref()}
    pub(crate) fn cached_emoji_host(&self,host:&str)->Option<std::collections::HashMap<String,String>>{self.emoji_memo.lock().expect("emoji memo").get(host).cloned()}
    pub(crate) fn cache_emoji_host(&self,host:&str,map:std::collections::HashMap<String,String>){self.emoji_memo.lock().expect("emoji memo").insert(host.into(),map);}
    pub(crate) fn cached_avatar(&self,actor:&str)->Option<String>{self.avatar_memo.lock().expect("avatar memo").get(actor.trim_end_matches('/')).cloned()}
    pub(crate) fn cache_avatar(&self,actor:&str,url:&str){self.avatar_memo.lock().expect("avatar memo").insert(actor.trim_end_matches('/').into(),url.into());}
    pub(crate) fn cfg(&self)->&crate::config::Config{&self.cfg}
    pub(crate) fn transport(&self)->&dyn crate::profile_warm::WarmTransport{self.transport.as_ref()}
    pub(crate) fn requested_actors(&self)->Vec<String>{self.requests.lock().expect("warm request ledger").0.clone()}
    pub(crate) fn requested_media(&self)->Vec<String>{self.requests.lock().expect("warm request ledger").1.clone()}
    pub(crate) async fn actor(&self,actor:&str){self.requests.lock().expect("warm request ledger").0.push(actor.into());let _=crate::profile_warm::actor_warm(&self.cfg,actor,self.transport()).await;}
    pub(crate) async fn media(&self,db:&tokio_postgres::Client,actor:&str){
        let enabled=std::env::var("VAAK_FEATURE_REMOTE_MEDIA_WARM").ok().map(|v|crate::library::card::php_trim(&v).to_ascii_lowercase()).filter(|v|!v.is_empty()).is_none_or(|v|!matches!(v.as_str(),"0"|"false"|"off"|"no"));
        if enabled{self.requests.lock().expect("warm request ledger").1.push(actor.into());let _=crate::profile_warm::media_warm(&self.cfg,db,actor,self.transport()).await;}
    }
}

/// PHP profile URL sanitizer preserves original bytes; URL parsing is used
/// only for host/credential validation, never as the emitted identity.
pub(crate) fn sanitize_profile_url(raw:&str)->anyhow::Result<Option<String>>{
 let raw=crate::library::card::php_trim(raw);
 if !raw.starts_with("https://")||raw.len()>800||raw.bytes().any(|b|b<32||b==127){return Ok(None)}
 let authority=raw[8..].split(['/', '?','#']).next().unwrap_or("");
 if authority.contains('@'){return Ok(None)}
 let parsed=match reqwest::Url::parse(raw){Ok(url)=>url,Err(_)=>return Ok(None)};
 let Some(host)=parsed.host_str()else{return Ok(None)};
 if matches!(host,"localhost"|"127.0.0.1"|"::1"|"[::1]"){return Ok(None)}
 if host.starts_with('['){anyhow::bail!("PHP fallback: profile IPv6 literal sanitizer requires actual range parity")}
 if let Ok(ip)=host.parse::<std::net::Ipv4Addr>(){if !crate::direct_preview::public_ipv4(ip)?{return Ok(None)}}
 Ok(Some(raw.into()))
}
