//! ap_search_downrank_statuses: downranked posts keep their place in the
//! result set but move below the rest, the same rule Home and trends use.
use super::{string,trim};
use anyhow::Result;
use serde_json::Value;
use std::collections::{HashMap,HashSet};
use std::sync::OnceLock;
use tokio_postgres::Client;

fn rtrim_slash(v:&str)->&str{v.trim_end_matches('/')}
fn all_digits(v:&str)->bool{!v.is_empty()&&v.bytes().all(|b|b.is_ascii_digit())}

/// ap_search_canonical_actor.
fn canonical_actor(actor:&str)->String{
    let actor=rtrim_slash(trim(actor));
    static VAAK:OnceLock<regex::Regex>=OnceLock::new();
    VAAK.get_or_init(||regex::Regex::new(r"(?i)^https://(?:www\.)?vaak\.monster/(?:vaak/users/|users/|@)([A-Za-z0-9_]+)$").unwrap()).captures(actor)
        .map(|m|format!("https://mkultra.monster/users/{}",m[1].to_ascii_lowercase())).unwrap_or_else(||actor.to_owned())
}

/// PHP rawurldecode.
fn rawurldecode(v:&str)->String{
    let bytes=v.as_bytes();let mut out=Vec::with_capacity(bytes.len());let mut i=0;
    while i<bytes.len(){
        if bytes[i]==b'%'&&i+2<bytes.len(){if let Some(n)=std::str::from_utf8(&bytes[i+1..i+3]).ok().and_then(|h|u8::from_str_radix(h,16).ok()){out.push(n);i+=3;continue}}
        out.push(bytes[i]);i+=1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

#[derive(Default)]
struct Refs{memo:HashMap<String,Vec<String>>}
impl Refs{
    /// ap_actor_moderation_aliases, reading the Bluesky caches it reads.
    async fn moderation_aliases(&mut self,db:&Client,raw:&str)->Result<Vec<String>>{
        let actor=rtrim_slash(trim(raw)).to_owned();
        if actor.is_empty(){return Ok(vec![])}
        let mut out=vec![actor.clone()];let (mut did,mut handle)=(None::<String>,None::<String>);
        static PROFILE:OnceLock<regex::Regex>=OnceLock::new();
        static DID:OnceLock<regex::Regex>=OnceLock::new();
        if actor.starts_with("did:"){did=Some(actor.clone());}
        else if let Some(m)=PROFILE.get_or_init(||regex::Regex::new(r"(?i)^https://bsky\.app/profile/([^/?#]+)$").unwrap()).captures(&actor){
            let part=rawurldecode(&m[1]);
            if part.starts_with("did:"){did=Some(part)}else if !part.is_empty(){handle=Some(part.trim_start_matches('@').to_ascii_lowercase())}
        }else if let Some(m)=DID.get_or_init(||regex::Regex::new(r"(?i)/(?:ap/)?(did:plc:[a-z0-9]+)").unwrap()).captures(&actor){did=Some(m[1].to_owned());}
        if did.is_some()&&handle.is_none(){
            let d=did.clone().unwrap_or_default();
            let found=db.query_opt("SELECT lower(author_handle) AS h FROM bsky_posts WHERE author_did = $1 AND author_handle IS NOT NULL AND author_handle <> '' LIMIT 1",&[&d]).await?
                .and_then(|r|r.get::<_,Option<String>>(0)).map(|h|trim(&h).to_ascii_lowercase()).unwrap_or_default();
            if !found.is_empty(){handle=Some(found)}else{
                let profile=db.query_opt("SELECT profile_json FROM bsky_actor_profiles WHERE did = $1 OR actor_ref = $1 LIMIT 1",&[&d]).await?.and_then(|r|r.get::<_,Option<String>>(0)).unwrap_or_default();
                if let Ok(p)=serde_json::from_str::<Value>(&profile){let h=trim(&string(p.get("handle"))).to_ascii_lowercase();if p.is_object()&&!h.is_empty(){handle=Some(h)}}
            }
        }else if let (Some(h),None)=(handle.clone(),did.clone()){
            let found=db.query_opt("SELECT author_did FROM bsky_posts WHERE lower(author_handle) = $1 AND author_did IS NOT NULL AND author_did <> '' LIMIT 1",&[&h]).await?
                .and_then(|r|r.get::<_,Option<String>>(0)).map(|d|trim(&d).to_owned()).unwrap_or_default();
            if found.starts_with("did:"){did=Some(found)}else{
                let rows=db.query("SELECT did, actor_ref, profile_json FROM bsky_actor_profiles WHERE lower(actor_ref) IN ($1, $2) OR did = $1 LIMIT 8",&[&h,&format!("https://bsky.app/profile/{h}")]).await?;
                for row in rows{
                    let mut d=trim(&row.get::<_,Option<String>>(0).unwrap_or_default()).to_owned();
                    if !d.starts_with("did:"){
                        let p=serde_json::from_str::<Value>(&row.get::<_,Option<String>>(2).unwrap_or_default()).unwrap_or(Value::Null);
                        d=if p.is_object(){trim(&string(p.get("did"))).to_owned()}else{String::new()};
                        let ph=if p.is_object(){trim(&string(p.get("handle"))).to_ascii_lowercase()}else{String::new()};
                        if !ph.is_empty()&&ph!=h{continue}
                    }
                    if d.starts_with("did:"){did=Some(d);break}
                }
            }
        }
        if let Some(d)=did.filter(|d|d.starts_with("did:")){out.push(d.clone());out.push(format!("https://bsky.app/profile/{d}"));out.push(format!("https://bsky.app/profile/{}",crate::library::actions::query(&d)));}
        if let Some(h)=handle.filter(|h|!h.is_empty()){out.push(h.clone());out.push(format!("https://bsky.app/profile/{h}"));out.push(format!("https://bsky.app/profile/{}",crate::library::actions::query(&h)));}
        let mut unique=Vec::new();for v in out{let v=rtrim_slash(trim(&v)).to_owned();if !v.is_empty()&&!unique.contains(&v){unique.push(v)}}
        Ok(unique)
    }
    /// ap_masto_trend_actor_refs.
    async fn actor_refs(&mut self,db:&Client,actor:&str)->Result<Vec<String>>{
        if let Some(hit)=self.memo.get(actor){return Ok(hit.clone())}
        let canonical=canonical_actor(actor);let mut out:Vec<String>=Vec::new();
        for alias in self.moderation_aliases(db,&canonical).await?{
            for r in crate::hidden::actor_aliases(&alias){let r=rtrim_slash(&r).to_owned();if !r.is_empty()&&!all_digits(&r)&&!out.contains(&r){out.push(r)}}
        }
        self.memo.insert(actor.to_owned(),out.clone());Ok(out)
    }
}

/// PHP strtotime for the stored suppression timestamps; unknown text stays on PHP.
pub(super) fn strtotime(text:&str)->Result<i64>{
    let text=trim(text);if text.is_empty(){return Ok(0)}
    if let Ok(t)=chrono::DateTime::parse_from_rfc3339(text){return Ok(t.timestamp())}
    for format in ["%Y-%m-%d %H:%M:%S%:z","%Y-%m-%dT%H:%M:%S%z","%Y-%m-%d %H:%M:%S%z"]{if let Ok(t)=chrono::DateTime::parse_from_str(text,format){return Ok(t.timestamp())}}
    for format in ["%Y-%m-%d %H:%M:%S","%Y-%m-%dT%H:%M:%S"]{if let Ok(t)=chrono::NaiveDateTime::parse_from_str(text,format){return Ok(t.and_utc().timestamp())}}
    anyhow::bail!("PHP fallback: suppression timestamp needs strtotime")
}

/// ap_downrank_actor_exempt.
fn actor_exempt(actor:&str)->bool{
    let actor=rtrim_slash(trim(actor));if actor.is_empty(){return true}
    let lower=actor.to_ascii_lowercase();
    if lower.starts_with("https://mkultra.monster/users/")||lower.starts_with("https://mkultra.monster/@"){return true}
    static ADMIN:OnceLock<regex::Regex>=OnceLock::new();
    if ADMIN.get_or_init(||regex::Regex::new(r"(?i)/(?:users/|@)cmdr_nova$").unwrap()).is_match(actor){return true}
    lower=="cmdr_nova"||lower=="cmdr_nova@mkultra.monster"
}

/// `$a[$k] ?? $b[$k] ?? ...` over string-cast values.
fn first_set(values:&[Option<&Value>])->String{values.iter().find_map(|v|v.filter(|v|!v.is_null())).map(|v|string(Some(v))).unwrap_or_default()}

/// ap_downrank_status_text_matches.
fn text_matches(status:&Value,terms:&[(String,String)])->bool{
    let account=if status["account"].is_object(){&status["account"]}else{&Value::Null};
    let actor=first_set(&[account.get("uri"),account.get("url"),status.get("author_did")]);
    if actor_exempt(&actor){return false}
    let body=first_set(&[status.get("content"),status.get("text")]);
    let text=format!("{}\n{}",string(status.get("spoiler_text")),body);let text=trim(&text);
    !text.is_empty()&&!crate::ranked_warm::toxicity_categories(text,terms).is_empty()
}

async fn ref_set(db:&Client,owner:i64,refs:&mut Refs)->Result<HashSet<String>>{
    let mut set=HashSet::new();
    let enabled=db.query_opt("SELECT COALESCE(p.downranking_enabled, 1)::integer FROM ap_users u LEFT JOIN actor_profile p ON p.actor_key = u.actor_key WHERE u.id = $1::bigint",&[&owner]).await?
        .map(|r|r.get::<_,i32>(0)!=0).unwrap_or(false);
    if enabled{
        set.insert("__terms".to_owned());
        let now=chrono::Utc::now().timestamp();
        for row in db.query("SELECT actor_id, suppressed_until FROM ap_home_suppression WHERE owner_user_id = $1::bigint",&[&owner]).await?{
            let actor=row.get::<_,Option<String>>(0).unwrap_or_default();let actor=rtrim_slash(&actor);
            if actor.is_empty(){continue}
            if strtotime(&row.get::<_,Option<String>>(1).unwrap_or_default())?<=now{continue}
            for r in refs.actor_refs(db,actor).await?{let r=rtrim_slash(&r).to_ascii_lowercase();if !r.is_empty()&&!all_digits(&r){set.insert(r);}}
        }
    }
    for row in db.query("SELECT actor_id FROM ap_deprioritized_actors WHERE owner_user_id = $1::bigint",&[&owner]).await?{
        let actor=row.get::<_,Option<String>>(0).unwrap_or_default();
        for r in refs.actor_refs(db,&actor).await?{let r=rtrim_slash(&r);if !r.is_empty()&&!all_digits(r){set.insert(format!("manual:{r}"));}}
    }
    Ok(set)
}

/// ap_masto_trend_status_is_downranked.
async fn downranked(db:&Client,item:&Value,set:&HashSet<String>,terms:&[(String,String)],refs:&mut Refs)->Result<bool>{
    let mut raw:Vec<String>=Vec::new();
    // ap_masto_trend_item_actors
    let trend=match item.get("trend_actors"){Some(Value::Array(a))=>a.clone(),Some(Value::Null)|None=>vec![],Some(Value::Object(m))=>m.values().cloned().collect(),Some(other)=>vec![other.clone()]};
    for a in trend{if let Value::String(a)=a{if !trim(&a).is_empty(){raw.push(rtrim_slash(trim(&a)).to_owned())}}}
    if item["account"].is_object(){for k in ["id","url"]{let v=trim(&string(item["account"].get(k))).to_owned();if !v.is_empty(){raw.push(rtrim_slash(&v).to_owned())}}}
    if let Some(Value::String(d))=item.get("author_did"){raw.push(trim(d).to_owned())}
    let mut unique=Vec::new();for a in raw{if !a.is_empty()&&!unique.contains(&a){unique.push(a)}}let mut raw=unique;
    let pull=|account:&Value,raw:&mut Vec<String>|{if account.is_object(){for k in ["uri","url","id"]{raw.push(string(account.get(k)))}}};
    pull(&item["account"],&mut raw);
    for nest in ["reblog","quote"]{if item[nest].is_object(){pull(&item[nest]["account"],&mut raw);raw.push(string(item[nest].get("author_did")));}}
    raw.push(string(item.get("author_did")));
    if set.contains("__terms")&&text_matches(item,terms){return Ok(true)}
    for actor in raw{
        let actor=trim(&actor);if actor.is_empty()||all_digits(rtrim_slash(actor)){continue}
        for r in refs.actor_refs(db,actor).await?{
            if set.contains(&format!("manual:{}",rtrim_slash(&r))){return Ok(true)}
            let r=rtrim_slash(&r).to_ascii_lowercase();if !r.is_empty()&&set.contains(&r){return Ok(true)}
        }
    }
    Ok(false)
}

pub(super) async fn apply(db:&Client,owner:i64,statuses:Vec<Value>)->Result<Vec<Value>>{
    if owner<1||statuses.len()<2{return Ok(statuses)}
    let mut refs=Refs::default();
    let set=ref_set(db,owner,&mut refs).await?;
    if set.is_empty(){return Ok(statuses)}
    let terms=if set.contains("__terms"){crate::ranked_warm::load_downrank_terms(db).await?}else{vec![]};
    let (mut keep,mut down)=(Vec::new(),Vec::new());
    for status in statuses{if downranked(db,&status,&set,&terms,&mut refs).await?{down.push(status)}else{keep.push(status)}}
    keep.extend(down);Ok(keep)
}

#[cfg(test)]
mod tests{
    use super::*;
    #[test]fn php_helpers(){
        assert!(actor_exempt("https://mkultra.monster/users/alice/"));assert!(actor_exempt("https://x.test/@cmdr_nova"));assert!(!actor_exempt("https://x.test/foo/cmdr_nova"));assert!(actor_exempt(""));
        assert_eq!(canonical_actor("https://vaak.monster/@Juno_Flux/"),"https://mkultra.monster/users/juno_flux");
        assert_eq!(strtotime("2026-10-19T00:00:00+00:00").unwrap(),1792368000);assert_eq!(strtotime("2026-10-19 00:00:00").unwrap(),1792368000);assert!(strtotime("tomorrow").is_err());
        let terms=vec![("crypto giveaway".to_owned(),"custom".to_owned())];
        assert!(text_matches(&serde_json::json!({"account":{"uri":"https://x.test/users/a"},"content":"<p>Big Crypto Giveaway now</p>"}),&terms));
        assert!(!text_matches(&serde_json::json!({"account":{"uri":"https://mkultra.monster/users/a"},"content":"crypto giveaway"}),&terms));
        assert!(!text_matches(&serde_json::json!({"account":{"uri":"https://x.test/users/a"},"content":"#crypto giveaway"}),&terms));
    }
}
