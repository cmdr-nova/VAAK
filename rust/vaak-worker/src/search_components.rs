//! Current server-owned Search component facts. These are presentation tokens,
//! not source/audience admission. No browser JSON constructor or Deserialize.
use anyhow::{bail, Context, Result};
use serde_json::Value;
use std::collections::{HashMap, HashSet};
use tokio_postgres::Client;
#[path = "search_query.rs"]
mod canonical_query;

pub(crate) struct ResolvedSearchAccount {
    account: Value,
    is_me: bool,
    following: bool,
    profile_href: String,
    open_label: String,
    emoji: HashMap<String, String>,
}
impl ResolvedSearchAccount {
    pub(crate) fn account(&self) -> &Value { &self.account }
    pub(crate) fn is_me(&self) -> bool { self.is_me }
    pub(crate) fn following(&self) -> bool { self.following }
    pub(crate) fn profile_href(&self) -> &str { &self.profile_href }
    pub(crate) fn open_label(&self) -> &str { &self.open_label }
    pub(crate) fn emoji(&self) -> &HashMap<String,String> { &self.emoji }
}
pub(crate) struct ResolvedSearchTag { tag: Value }
impl ResolvedSearchTag { pub(crate) fn tag(&self) -> &Value { &self.tag } }
fn text<'a>(v:&'a Value,k:&str)->&'a str { v.get(k).and_then(Value::as_str).unwrap_or("") }
fn trim(s:&str)->&str { s.trim_matches([' ','\t','\n','\r','\0','\u{b}']) }
fn root(s:&str)->&str { trim(s).trim_end_matches('/') }
fn form(s:&str)->String { crate::library::actions::query(s).replace("%20","+").replace('~',"%7E") }
fn crc32(s:&str)->u32 {
    let mut n=0xffffffffu32;
    for b in s.bytes() { n^=b as u32; for _ in 0..8 { n=(n>>1)^if n&1!=0 {0xedb88320}else{0}; } }
    !n
}
fn syntactic(actor:&str)->HashSet<String> {
    crate::hidden::actor_aliases(actor).into_iter().map(|s|root(&s).to_owned()).collect()
}

fn pattern(value: &'static str, cache: &'static std::sync::OnceLock<regex::Regex>) -> &'static regex::Regex {
    cache.get_or_init(|| regex::Regex::new(value).expect("constant Search component regex"))
}
fn local_key(actor:&str)->Option<String> {
    static LOCAL:std::sync::OnceLock<regex::Regex>=std::sync::OnceLock::new();
    pattern(r"^https://mkultra\.monster/users/([A-Za-z0-9_]+)$",&LOCAL).captures(actor).map(|m|m[1].to_ascii_lowercase())
}
fn placeholder(user:&str)->bool {
    let user=trim(user).trim_start_matches('@');
    user.is_empty()||user=="user"||user.to_ascii_lowercase().starts_with("did:")
        ||user.to_ascii_lowercase().starts_with("did%3a")
        ||(user.len()>=6&&user.bytes().all(|b|b.is_ascii_digit()))
}

/// One fresh component context per server batch. It owns no admission proof.
/// Recreate after mutations; never cache this context across requests or owners.
pub(crate) struct ComponentContext {
    owner:Option<i64>, owner_actor:String, viewer:String,
    remote:HashMap<String,(String,String)>, following:HashSet<String>, relationship_actors:HashSet<String>,
    following_ids:HashSet<String>, mapped_ids:HashMap<String,String>,
    local_ids:HashMap<String,String>,
    emoji:HashMap<String,HashMap<String,String>>,
    allowed_accounts:HashSet<String>,
}
impl ComponentContext {
    /// `accounts` are the complete current server source entities for this
    /// batch. Browser-supplied identity or relationship booleans are not inputs.
    pub(crate) async fn load(db:&Client,owner:Option<i64>,accounts:&[Value])->Result<Self> {
        if accounts.len()>100 {bail!("Search component batch limit")}
        let (owner_actor,viewer)=if let Some(owner)=owner {
            let row=db.query_opt("SELECT actor_id,username,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&owner]).await?.context("inactive Search component owner")?;
            let actor:String=row.try_get(0)?;let username:String=row.try_get(1)?;let key:String=row.try_get(2)?;
            let name=if trim(&username).is_empty()||trim(&username)=="guest" {trim(&key)}else{trim(&username)};
            (root(&actor).to_owned(),if name=="__guest__" {String::new()}else{name.into()})
        }else{(String::new(),String::new())};
        let mut context=Self {owner,owner_actor,viewer,remote:HashMap::new(),following:HashSet::new(),relationship_actors:HashSet::new(),following_ids:HashSet::new(),mapped_ids:HashMap::new(),local_ids:HashMap::new(),emoji:HashMap::new(),allowed_accounts:HashSet::new()};
        let mut actor_keys=HashSet::new();let mut numeric_ids=HashSet::new();let mut hosts=HashSet::new();
        for account in accounts {
            if !account.is_object(){bail!("malformed Search account")}
            context.allowed_accounts.insert(account.to_string());
            for key in ["uri","url"] {
                let actor=root(text(account,key));if actor.is_empty(){continue}
                actor_keys.insert(actor.to_owned());actor_keys.insert(format!("{actor}/"));
                if let Ok(url)=reqwest::Url::parse(actor){if let Some(host)=url.host_str(){hosts.insert(host.to_ascii_lowercase());}}
            }
            let id=text(account,"id");if !id.is_empty(){numeric_ids.insert(id.to_owned());}
        }
        let mut followed=Vec::new();let mut graph=Vec::new();
        if let Some(owner)=owner {
            for row in db.query("SELECT actor_id,host FROM following WHERE owner_actor_id=$1 OR owner_actor_id=$2",&[&context.owner_actor,&format!("{}/",context.owner_actor)]).await? {
                let actor:String=row.try_get(0)?;actor_keys.insert(root(&actor).into());actor_keys.insert(format!("{}/",root(&actor)));followed.push((actor,row.try_get::<_,Option<String>>(1)?));
            }
            for row in db.query("SELECT target_did FROM bsky_graph_sync WHERE owner_user_id=$1::bigint AND kind='follow'",&[&owner]).await? {let did:String=row.try_get(0)?;if trim(&did).starts_with("did:"){graph.push(trim(&did).to_owned());}}

        }
        let ids:Vec<String>=numeric_ids.into_iter().collect();
        for row in db.query("SELECT account_id,actor_id FROM masto_account_actors WHERE account_id=ANY($1)",&[&ids]).await? {
            let id:String=row.try_get(0)?;let actor:String=row.try_get(1)?;actor_keys.insert(root(&actor).into());actor_keys.insert(format!("{}/",root(&actor)));context.mapped_ids.insert(id,actor);
        }
        let numeric:Vec<i64>=ids.iter().filter_map(|id|id.parse().ok()).collect();
        for row in db.query("SELECT id::bigint,actor_id,actor_key FROM ap_users WHERE disabled_at IS NULL AND (id=ANY($1::bigint[]) OR ($2 AND actor_key='cmdr_nova'))",&[&numeric,&ids.iter().any(|id|id=="1")]).await? {
            let id:i64=row.try_get(0)?;let actor:String=row.try_get(1)?;let key:String=row.try_get(2)?;if id!=1{context.local_ids.insert(id.to_string(),actor.clone());}if key=="cmdr_nova"{context.local_ids.insert("1".into(),actor);}
        }
        let actors:Vec<String>=actor_keys.into_iter().collect();
        for row in db.query("SELECT actor_id,username,host FROM remote_actors WHERE actor_id=ANY($1)",&[&actors]).await? {let actor:String=row.try_get(0)?;let user:Option<String>=row.try_get(1)?;let host:Option<String>=row.try_get(2)?;context.remote.insert(root(&actor).into(),(user.unwrap_or_default(),host.unwrap_or_default()));}
        for (actor,host) in followed {context.relationship_actors.insert(root(&actor).into());context.following.extend(context.following_aliases(&actor,host.as_deref()));context.following_ids.insert(context.remote_id(&actor));}
        let profile_keys:Vec<String>=graph.iter().flat_map(|did|[did.clone(),format!("https://bsky.app/profile/{did}"),format!("https://bsky.app/profile/{}",crate::library::actions::query(did))]).collect();
        let mut profiles:HashMap<String,(u8,String)>=HashMap::new();
        for row in db.query("SELECT actor_ref,did,profile_json FROM bsky_actor_profiles WHERE actor_ref=ANY($1) OR did=ANY($2) ORDER BY updated_at DESC",&[&profile_keys,&graph]).await? {
            let actor:String=row.try_get(0)?;let did:String=row.try_get(1)?;let json:String=row.try_get(2)?;
            let priority=if actor==did {0}else if actor==format!("https://bsky.app/profile/{did}"){1}else if actor==format!("https://bsky.app/profile/{}",crate::library::actions::query(&did)){2}else{3};
            if profiles.get(&did).is_none_or(|(previous,_)|priority<*previous){profiles.insert(did,(priority,json));}

        }
        for did in graph {
            context.following.extend(syntactic(&did));context.relationship_actors.insert(format!("https://bsky.app/profile/{did}"));context.relationship_actors.insert(format!("https://bsky.app/profile/{}",crate::library::actions::query(&did)));
            if let Some((_,json))=profiles.get(&did) {let profile=serde_json::from_str::<Value>(json).ok().filter(Value::is_object);let handle=profile.as_ref().map(|p|trim(text(p,"handle")).to_ascii_lowercase()).unwrap_or_default();if !handle.is_empty()&&!handle.starts_with("did:"){let actor=format!("https://bsky.app/profile/{handle}");context.following_ids.insert(context.remote_id(&actor));context.relationship_actors.insert(actor.clone());context.relationship_actors.insert(format!("https://bsky.app/profile/{}",crate::library::actions::query(&handle)));context.following.insert(actor);context.following.insert(format!("https://bsky.app/profile/{}",crate::library::actions::query(&handle)));}}
        }
        let hosts:Vec<String>=hosts.into_iter().collect();
        for row in db.query("SELECT host,shortcode,url FROM remote_custom_emojis WHERE host=ANY($1)",&[&hosts]).await? {let host:String=row.try_get(0)?;let code:String=row.try_get(1)?;let url:String=row.try_get(2)?;if url.starts_with("https://"){context.emoji.entry(host).or_default().insert(code,url);}}
        Ok(context)
    }
    fn following_aliases(&self,actor:&str,row_host:Option<&str>)->HashSet<String> {
        let mut out=syntactic(actor);out.insert(root(actor).into());
        let mut host=row_host.filter(|h|!h.is_empty()).map(str::to_ascii_lowercase).unwrap_or_else(||reqwest::Url::parse(actor).ok().and_then(|u|u.host_str().map(str::to_ascii_lowercase)).unwrap_or_default());
        let mut user=String::new();
        if let Some((u,h))=self.remote.get(root(actor)) {
            let u=trim(u);if !u.is_empty()&&!u.bytes().all(|b|b.is_ascii_digit()){user=u.into();if !trim(h).is_empty(){host=trim(h).to_ascii_lowercase();}}
        }
        if user.is_empty(){static PATH:std::sync::OnceLock<regex::Regex>=std::sync::OnceLock::new();if let Some(c)=pattern(r"(?i)/(?:users/|@)([^/?#]+)/?$",&PATH).captures(actor){user=crate::hidden::path_segment_forms(&c[1]).0;}}
        if !user.is_empty()&&!user.bytes().all(|b|b.is_ascii_digit())&&!user.contains(['/', '?', '#']) {
            let (decoded,encoded)=crate::hidden::path_segment_forms(&user);
            for form in [decoded,encoded] {out.insert(format!("https://{host}/@{form}"));out.insert(format!("https://{host}/users/{form}"));}
        }
        out
    }
    fn acct_key(&self,actor:&str)->Option<String> {
        let actor=root(actor);let url=reqwest::Url::parse(actor).ok()?;if url.scheme()!="https"{return None}
        let mut host=url.host_str().unwrap_or("").to_ascii_lowercase();let mut user=String::new();
        if let Some((name,h))=self.remote.get(actor){user=trim(name).into();if !trim(h).is_empty(){host=trim(h).to_ascii_lowercase();}}
        if user.is_empty(){static PATH:std::sync::OnceLock<regex::Regex>=std::sync::OnceLock::new();if let Some(c)=pattern(r"(?i)/(?:users|@)([^/]+)/?$",&PATH).captures(actor){let decoded=crate::hidden::raw_percent_decode(&c[1]);if !decoded.bytes().all(|b|b.is_ascii_digit())&&!placeholder(&decoded){user=decoded;}}}
        if placeholder(&user)||host.is_empty()||host=="unknown"{None}else{Some(format!("{}@{host}",canonical_query::lowercase(&user)))}
    }
    fn account_id_aliases(&self,actor:&str)->Vec<String> {
        // This differs from the UI syntactic aliases. The actual Mastodon ID
        // helper probes ap_masto_actor_id_aliases, whose legacy path regex has
        // no slash after `users`; an uncached /users/name therefore retains a
        // raw-URI CRC seed, while /@name can use the acct seed.
        let mut out=vec![root(actor).to_owned()];
        let Some(url)=reqwest::Url::parse(actor).ok() else{return out};
        let mut host=url.host_str().unwrap_or("").to_ascii_lowercase();
        let mut user=self.remote.get(root(actor)).map(|(u,h)|{if !trim(h).is_empty(){host=trim(h).to_ascii_lowercase();}trim(u).to_owned()}).unwrap_or_default();
        if user.is_empty(){static PATH:std::sync::OnceLock<regex::Regex>=std::sync::OnceLock::new();if let Some(c)=pattern(r"(?i)/(?:users|ap/users|@)([^/]+)/?$",&PATH).captures(actor){let part=crate::hidden::raw_percent_decode(&c[1]).trim_start_matches('@').to_owned();if !part.bytes().all(|b|b.is_ascii_digit()){user=part;}}}
        if !user.is_empty(){for (actor,(name,h)) in &self.remote {if trim(h).eq_ignore_ascii_case(&host)&&canonical_query::lowercase(trim(name))==canonical_query::lowercase(&user){out.push(actor.clone());}}out.push(format!("https://{host}/users/{}",crate::library::actions::query(&user)));out.push(format!("https://{host}/@{}",crate::library::actions::query(&user)));}
        out
    }
    fn remote_id(&self,actor:&str)->String {let key=self.acct_key(actor).or_else(||self.account_id_aliases(actor).iter().find_map(|alias|self.acct_key(alias)));let seed=key.map(|key|format!("acct:{key}")).unwrap_or_else(||root(actor).into());(300000000u64+(crc32(&seed)as u64%699999999)).to_string()}
    fn aliases(&self,actor:&str)->HashSet<String> {let mut out=syntactic(actor);out.insert(root(actor).into());if let Some(key)=self.acct_key(actor){if let Some((u,h))=key.split_once('@'){out.insert(format!("https://{h}/@{u}"));out.insert(format!("https://{h}/users/{u}"));out.insert(format!("https://{h}/users/{}",crate::library::actions::query(u)));}}out}
    fn follows(&self,refs:&[&str],id:&str,is_me:bool)->Result<bool> {
        if self.owner.is_none(){if !is_me&&!id.is_empty()&&id!="0"{bail!("guest numeric relationship requires canonical PHP session boundary")}return Ok(false)}
        for actor in refs {
            if self.aliases(actor).iter().any(|s|self.following.contains(s)){return Ok(true)}
            if let Ok(url)=reqwest::Url::parse(trim(actor)){if url.host_str().is_some_and(|h|h.eq_ignore_ascii_case("bsky.app")){let part=crate::hidden::raw_percent_decode(url.path().strip_prefix("/profile/").unwrap_or("").split('/').next().unwrap_or(""));if !part.is_empty()&&!part.starts_with("did:"){bail!("unresolved Bluesky relationship requires canonical PHP identity resolution")}}}
        }
        if !is_me&&!id.is_empty()&&id!="0" {
            if self.following_ids.contains(id){return Ok(true)}
            if let Some(actor)=self.local_ids.get(id).or_else(||self.mapped_ids.get(id)){return Ok(self.account_id_aliases(actor).iter().any(|s|self.relationship_actors.contains(s)))}
            bail!("unresolved numeric remote account relationship")
        }
        Ok(false)
    }
    pub(crate) fn resolve_account(&self,input:&Value)->Result<ResolvedSearchAccount> {
        if !self.allowed_accounts.contains(&input.to_string()){bail!("Search account was not bound to current component batch")}
        let mut account=input.clone();let uri=text(&account,"uri").trim_end_matches('/').to_owned();let url=text(&account,"url").trim_end_matches('/').to_owned();let mut target=if uri.is_empty(){url.clone()}else{uri.clone()};let local=local_key(&target);
        if let Some(key)=&local {account["acct"]=Value::String(if input.get("username").is_some_and(|value|!value.is_null()){crate::library::card::scalar_string(input,"username")}else{key.clone()});target=format!("https://mkultra.monster/users/{}",crate::library::actions::query(key));}
        let acct=text(&account,"acct");let own=|s:&str|!self.owner_actor.is_empty()&&(s==self.owner_actor||s.starts_with(&format!("{}/",self.owner_actor)));
        let is_me=own(&target)||own(&uri)||own(&url)||(!acct.is_empty()&&(acct.eq_ignore_ascii_case(&self.viewer)||acct.eq_ignore_ascii_case(&format!("{}@mkultra.monster",self.viewer))));
        let following=self.follows(&[&target,&url,&uri],text(&account,"id"),is_me)?;
        let href=local.map(|key|format!("/vaak/users/{}?from=search",crate::library::actions::query(&key))).unwrap_or_else(||format!("?view=remote_profile&actor={}&from=search",form(&target)));
        static LABEL:std::sync::OnceLock<regex::Regex>=std::sync::OnceLock::new();let local_label=pattern(r"^https://mkultra\.monster/(?:users/[A-Za-z0-9_]+(?:/|$)|@[A-Za-z0-9_]+$)",&LABEL).is_match(root(&target));
        let host=reqwest::Url::parse(&target).ok().and_then(|u|u.host_str().map(str::to_ascii_lowercase)).unwrap_or_default();let emoji=self.emoji.get(&host).cloned().unwrap_or_default();
        Ok(ResolvedSearchAccount{account,is_me,following,profile_href:href,open_label:if local_label{"Open web profile"}else{"Open remote"}.into(),emoji})
    }
    pub(crate) fn resolve_tag(&self,input:&Value)->Result<ResolvedSearchTag> {
        if !input.is_object(){bail!("malformed Search hashtag")}
        // The collector supplies the canonical following snapshot; this painter
        // does not re-read or override it with a separate DB state.
        Ok(ResolvedSearchTag{tag:input.clone()})
    }
}

// Single-entity conveniences are fixture-only. Runtime producers must load one
// context for their whole batch to avoid owner-graph reads for every card.
#[cfg(test)]
pub(crate) async fn resolve_account(db:&Client,owner:Option<i64>,account:&Value)->Result<ResolvedSearchAccount>{ComponentContext::load(db,owner,&[account.clone()]).await?.resolve_account(account)}
