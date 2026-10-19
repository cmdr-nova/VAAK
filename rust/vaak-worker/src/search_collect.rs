//! Native admin_search_run: the ranked status window and its short cache,
//! hashtag counts, local and cached remote accounts, current admission,
//! downranking and the private collector descriptors the render bridge binds.
//! A shape PHP still owns (mention hits, a partial index, network account
//! lookups) answers 422 so PHP collects the whole request, or flags only the
//! account section so PHP fills that part alone.
use anyhow::{bail,Context,Result};
use axum::{body::Bytes,http::{HeaderMap,StatusCode},response::{IntoResponse,Response}};
use serde::Deserialize;
use serde_json::{json,Map,Value};
use sha2::{Digest,Sha256};
use std::collections::{HashMap,HashSet};
use std::sync::OnceLock;
use tokio_postgres::Client;

#[path="search_collect_bsky.rs"]
mod bsky;
#[path="search_collect_downrank.rs"]
mod downrank;

const MAX_BYTES:usize=64*1024;
const STATUS_FETCH:usize=121;
const TAG_FETCH:usize=80;
const SANITIZED_CAP:usize=40;
const PRIMARY_CACHE:&str="/var/lib/mkultra/ap/search-cache";

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Request {
    protocol_version:u8,
    owner_id:i64,
    query:String,
    /// PHP mb_strtolower($query), so the cache key bytes match PHP's.
    query_lower:String,
    section:String,
    resolve:bool,
    limit:i64,
    offset:i64,
    cache_ttl:i64,
}

fn s<'a>(v:&'a Value,k:&str)->&'a str{v.get(k).and_then(Value::as_str).unwrap_or("")}
fn trim(v:&str)->&str{crate::library::card::php_trim(v)}
fn lower(v:&str)->String{crate::search::php_lowercase(v)}
/// PHP empty() negated, for decoded JSON.
fn truthy(v:&Value)->bool{
    match v{Value::Null=>false,Value::Bool(b)=>*b,Value::Number(n)=>n.as_f64().is_some_and(|f|f!=0.0),Value::String(t)=>!(t.is_empty()||t=="0"),Value::Array(a)=>!a.is_empty(),Value::Object(m)=>!m.is_empty()}
}
/// PHP `(string) $value`, for the id and URI fields Search keys on.
fn string(v:Option<&Value>)->String{
    match v{Some(Value::String(t))=>t.clone(),Some(Value::Number(n))=>n.to_string(),Some(Value::Bool(true))=>"1".into(),_=>String::new()}
}
fn chars_prefix(v:&str,n:usize)->String{v.chars().take(n).collect()}

/// PHP strip_tags for the generated HTML Search reads back. A stray angle
/// bracket would need PHP's tag state machine.
pub(crate) fn strip_tags(html:&str)->Result<String>{
    static TAG:OnceLock<regex::Regex>=OnceLock::new();
    let out=TAG.get_or_init(||regex::Regex::new(r"(?s)<!--.*?-->|<[^<>]*>").unwrap()).replace_all(html,"").into_owned();
    if out.contains('<')||out.contains('>'){bail!("PHP fallback: Search text has an unmatched angle bracket")}
    Ok(out)
}

/// ap_masto_url_looks_like_status.
pub(crate) fn url_looks_like_status(url:&str)->bool{
    let url=trim(url).trim_end_matches('/');
    if url.is_empty()||!url.starts_with("https://"){return false}
    let path=php_url_path(url);
    if path.is_empty()||path=="/"{return false}
    static PROFILE:OnceLock<regex::Regex>=OnceLock::new();
    static POST:OnceLock<regex::Regex>=OnceLock::new();
    if PROFILE.get_or_init(||regex::Regex::new(r"(?i)^/(@|users/)[^/]+/?$").unwrap()).is_match(&path){return false}
    POST.get_or_init(||regex::Regex::new(r"(?i)/(?:statuses|notes|objects|notice|post|posts|p)/|/@(?:[^/]+)/[A-Za-z0-9_-]+").unwrap()).is_match(&path)
}
/// parse_url($url, PHP_URL_PATH) for an https:// URL: the part after the
/// authority, before any query or fragment.
fn php_url_path(url:&str)->String{
    let rest=&url["https://".len()..];
    let after=rest.find(['/','?','#']).map(|i|&rest[i..]).unwrap_or("");
    if !after.starts_with('/'){return String::new()}
    after.split(['?','#']).next().unwrap_or("").to_owned()
}

/// ap_masto_normalize_tag_name.
fn normalize_tag_name(name:&str)->String{
    let name=lower(trim(name).trim_start_matches('#'));
    static KEEP:OnceLock<regex::Regex>=OnceLock::new();
    let keep=KEEP.get_or_init(||regex::Regex::new(r"[^\p{L}\p{N}_]").unwrap());
    chars_prefix(&keep.replace_all(&name,""),100)
}

/// PHP crc32() on 64-bit builds.
fn crc32(s:&str)->u32{
    let mut n=0xffffffffu32;
    for b in s.bytes(){n^=b as u32;for _ in 0..8{n=(n>>1)^if n&1!=0{0xedb88320}else{0};}}
    !n
}
fn rawurlencode(v:&str)->String{crate::library::actions::query(v)}

/// ap_masto_tag_entity($name) with the default history.
fn tag_entity(name:&str,following:bool,now:i64)->Value{
    let name=normalize_tag_name(name);
    let id=(100000u64+(crc32(&name) as u64%800000000)).to_string();
    let day=now.div_euclid(86400)*86400;
    let history:Vec<Value>=(0..7).map(|i|json!({"day":(day-i*86400).to_string(),"uses":"0","accounts":"0"})).collect();
    json!({"id":id,"name":name,"url":format!("https://mkultra.monster/tags/{}",rawurlencode(&name)),"history":history,"following":following,"featuring":false})
}

fn enabled_secret()->Option<String>{
    if std::env::var("VAAK_SEARCH_NATIVE_COLLECT").ok().as_deref()!=Some("1"){return None}
    std::env::var("VAAK_SEARCH_NATIVE_SECRET").ok().filter(|value|value.len()>=32)
}
pub(crate) fn enabled()->bool{enabled_secret().is_some()}
fn authorized(headers:&HeaderMap,expected:&str)->bool{
    let supplied=headers.get("x-vaak-search-token").and_then(|value|value.to_str().ok()).unwrap_or("");
    supplied.len()==expected.len()&&supplied.bytes().zip(expected.bytes()).fold(0u8,|diff,(a,b)|diff|(a^b))==0
}
fn fallback()->Response{(StatusCode::UNPROCESSABLE_ENTITY,axum::Json(json!({"fallback":true}))).into_response()}
fn no_store(mut response:Response)->Response{
    response.headers_mut().insert(axum::http::header::CACHE_CONTROL,axum::http::HeaderValue::from_static("private, no-store"));response
}

pub(crate) async fn collect(cfg:&crate::config::Config,headers:HeaderMap,bytes:Bytes)->Response{
    let Some(secret)=enabled_secret() else{return StatusCode::NOT_FOUND.into_response()};
    if !authorized(&headers,&secret){return StatusCode::UNAUTHORIZED.into_response()}
    if bytes.len()>MAX_BYTES{return StatusCode::PAYLOAD_TOO_LARGE.into_response()}
    let request:Request=match serde_json::from_slice(&bytes){Ok(r)=>r,Err(_)=>return no_store((StatusCode::BAD_REQUEST,axum::Json(json!({"fallback":true,"error":"invalid request"}))).into_response())};
    if request.protocol_version!=1||request.owner_id<1||request.query.len()>4096||request.query_lower.len()>4096*4||request.section.len()>64||!(1..=3600).contains(&request.cache_ttl){
        return no_store((StatusCode::BAD_REQUEST,axum::Json(json!({"fallback":true,"error":"invalid request"}))).into_response())
    }
    let started=std::time::Instant::now();
    let warm=crate::library::bridge::native_warm(cfg,None);
    let deps=crate::direct_preview::PreviewDependencies::production();
    let result:Result<Vec<u8>>=async{
        let db=crate::db::connect(&cfg.database_url).await?;
        let reply=run(&db,Some(cfg),Some(&warm),deps.dns,&request,&cache_dir()).await?;
        Ok(serde_json::to_vec(&reply)?)
    }.await;
    let response=match result{
        Ok(body)=>{tracing::debug!(ms=started.elapsed().as_millis() as u64,"Search native collect");(StatusCode::OK,[(axum::http::header::CONTENT_TYPE,"application/json")],body).into_response()}
        Err(error)=>{tracing::info!(reason=%format!("{error:#}"),"Search collect PHP fallback");fallback()}
    };
    no_store(response)
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct ResolveRequest {protocol_version:u8,url:String}

/// ap_masto_mention_target_object_id.
fn mention_target_object_id(raw:&str)->String{
    let id=raw.trim_end_matches('/');
    static INTERACTION:OnceLock<regex::Regex>=OnceLock::new();
    if let Some(m)=INTERACTION.get_or_init(||regex::Regex::new(r"(?i)^(https://.+?)#(like|reblog|update|quote|bite|webmention)-[a-z0-9]+$").unwrap()).captures(id){return m[1].trim_end_matches('/').to_owned()}
    let id=trim(id);
    static ANNOUNCE:OnceLock<regex::Regex>=OnceLock::new();
    let stripped=ANNOUNCE.get_or_init(||regex::Regex::new(r"#announce-[0-9]+$").unwrap()).replace(id,"");
    if stripped==id{id.to_owned()}else{stripped.trim_end_matches('/').to_owned()}
}

/// Stored part of ap_masto_resolve_pasted_status_url. Ok(None) is PHP's null;
/// an uncached post needs PHP's network fetch.
async fn resolve_cached(db:&Client,raw:&str)->Result<Option<String>>{
    let url=mention_target_object_id(trim(raw));
    if url.is_empty()||!url.starts_with("https://")||!url_looks_like_status(&url){return Ok(None)}
    let object=trim(&url).trim_end_matches('/').to_owned();
    if !object.is_empty(){
        let event=db.query_opt("SELECT object_id FROM events WHERE (object_id = $1 OR object_id = $2) AND COALESCE(action_taken, '') != 'deleted' ORDER BY CASE type WHEN 'Create' THEN 0 WHEN 'Update' THEN 1 WHEN 'Announce' THEN 10 WHEN 'Like' THEN 11 ELSE 5 END ASC, id DESC LIMIT 1",&[&object,&format!("{object}/")]).await?;
        if let Some(found)=event.and_then(|r|r.get::<_,Option<String>>(0)).filter(|o|!o.is_empty()&&o!="0"){return Ok(Some(found.trim_end_matches('/').to_owned()))}
    }
    let local=db.query_opt("SELECT note_id FROM masto_statuses WHERE note_id = $1",&[&url]).await?;
    if let Some(found)=local.and_then(|r|r.get::<_,Option<String>>(0)).filter(|o|!o.is_empty()&&o!="0"){return Ok(Some(found.trim_end_matches('/').to_owned()))}
    bail!("PHP fallback: pasted post is not stored yet")
}

pub(crate) async fn resolve(cfg:&crate::config::Config,headers:HeaderMap,bytes:Bytes)->Response{
    let Some(secret)=enabled_secret() else{return StatusCode::NOT_FOUND.into_response()};
    if !authorized(&headers,&secret){return StatusCode::UNAUTHORIZED.into_response()}
    let request=match serde_json::from_slice::<ResolveRequest>(&bytes){Ok(r) if r.protocol_version==1&&r.url.len()<=4096=>r,_=>return no_store(StatusCode::BAD_REQUEST.into_response())};
    let result=async{let db=crate::db::connect(&cfg.database_url).await?;resolve_cached(&db,&request.url).await}.await;
    no_store(match result{
        Ok(object)=>axum::Json(json!({"protocol_version":1,"object":object})).into_response(),
        Err(error)=>{tracing::debug!(reason=%format!("{error:#}"),"Search resolve PHP fallback");fallback()}
    })
}

/// The admin_search_run cache directory as this process sees it.
fn cache_dir()->std::path::PathBuf{
    static PRIMARY:OnceLock<bool>=OnceLock::new();
    let primary=*PRIMARY.get_or_init(||{
        let dir=std::path::Path::new(PRIMARY_CACHE);
        if !dir.is_dir(){return false}
        let probe=dir.join(format!(".probe.{}",std::process::id()));
        let ok=std::fs::write(&probe,b"").is_ok();let _=std::fs::remove_file(&probe);ok
    });
    if primary{PRIMARY_CACHE.into()}else{std::env::temp_dir().join("vaak-search-cache")}
}

struct Owner{id:i64,actor:String,key:String}

/// One sanitized window, built in PHP's order (ap_masto_search_sanitize_statuses).
#[derive(Default)]
struct Sanitized{out:Vec<Value>,ids:HashSet<String>,uris:HashSet<String>}
impl Sanitized{
    fn push(&mut self,status:&Value){
        let Some(map)=status.as_object() else{return};
        let mut st=if map.get("reblog").is_some_and(|r|truthy(r)&&r.is_object()){status["reblog"].clone()}else{status.clone()};
        let Some(obj)=st.as_object_mut() else{return};
        obj.remove("reblog");obj.insert("reblog".into(),Value::Null);
        let id=string(st.get("id"));
        if id.is_empty()||self.ids.contains(&id){return}
        let raw_uri=match st.get("uri"){Some(v) if !v.is_null()=>string(Some(v)),_=>string(st.get("url"))};
        let mut uri=raw_uri.trim_end_matches('/').to_owned();
        if !uri.is_empty()&&uri.contains('#'){
            uri=uri.split('#').next().unwrap_or("").trim_end_matches('/').to_owned();
            st["uri"]=json!(uri);
            if truthy(&st["url"])&&string(st.get("url")).contains('#'){st["url"]=json!(uri);}
        }
        if !uri.is_empty()&&self.uris.contains(&uri){return}
        self.ids.insert(id);if !uri.is_empty(){self.uris.insert(uri);}
        if truthy(&st["media_attachments"]){
            if let Some(list)=st["media_attachments"].as_array_mut(){for m in list.iter_mut(){if let Some(o)=m.as_object_mut(){o.insert("meta".into(),Value::Null);}}}
            else if st["media_attachments"].is_object(){return self.php_only()}
        }
        if st.get("quotes_count").is_none(){st["quotes_count"]=json!(0);}
        if !st["account"].is_object(){return}
        for key in ["fields","emojis"]{if !(st["account"][key].is_array()||st["account"][key].is_object()){st["account"][key]=json!([]);}}
        if !st["account"]["note"].is_string(){st["account"]["note"]=json!("");}
        for key in ["tags","mentions","emojis","media_attachments"]{if !(st[key].is_array()||st[key].is_object()){st[key]=json!([]);}}
        if self.out.len()<SANITIZED_CAP{self.out.push(st);}
    }
    /// A keyed media map is not a shape the builders produce; keep it visible.
    fn php_only(&mut self){self.out.push(json!({"__php_only":true}));}
    fn full(&self)->bool{self.out.len()>=SANITIZED_CAP}
    fn finish(self)->Result<Vec<Value>>{
        if self.out.iter().any(|v|v.get("__php_only").is_some()){bail!("PHP fallback: Search status has a keyed media map")}
        Ok(self.out)
    }
}

async fn search_meta(db:&Client,key:&str)->Result<Option<String>>{
    Ok(db.query_opt("SELECT value FROM ap_search_meta WHERE key=$1",&[&key]).await?.and_then(|r|r.get::<_,Option<String>>(0)))
}

/// ap_masto_search_statuses($q, 121) then the sanitizer, stopping as soon as
/// the sanitized window is full. The kept window is the same; later hits only
/// cost builder IO and the optional Bluesky LIKE scan.
async fn statuses(db:&Client,owner:&Owner,raw_q:&str,cfg:Option<&crate::config::Config>,warm:Option<&crate::profile_enrichment::WarmContext>,dns:&dyn crate::direct_preview::PreviewDns)->Result<Vec<Value>>{
    let q=trim(raw_q);
    if q.is_empty()||q.chars().count()<2{return Ok(vec![])}
    let q=chars_prefix(q,200);
    let q_lower=lower(&q);
    let tag=if q_lower.starts_with('#'){normalize_tag_name(&q)}else{String::new()};
    // ap_search_fts_query runs the backfill and recent-event index writers
    // first. Native Search only reads an index those writers have finished.
    if search_meta(db,"backfill_phase").await?.as_deref()!=Some("done"){bail!("PHP fallback: Search index backfill not done")}
    let latest=db.query_opt("SELECT id FROM events WHERE type IN ('Create','Announce','Update','Quote','QuotePost') AND action_taken IN ('log','local_observe','compose','local_fav_update') AND summary IS NOT NULL AND summary <> '' ORDER BY id DESC LIMIT 1",&[]).await?.map(|r|r.get::<_,i64>(0)).unwrap_or(0);
    if latest>0&&db.query_opt("SELECT 1 FROM ap_search_docs WHERE source='event' AND source_pk=$1",&[&latest]).await?.is_none(){bail!("PHP fallback: newest event not indexed yet")}

    let hits=crate::search::ranked_candidates(db,owner.id,&q,&tag,(STATUS_FETCH*3).max(40) as i64).await?;
    let mut ids:HashMap<&str,Vec<i64>>=HashMap::new();let mut bsky_uris:Vec<String>=Vec::new();
    for hit in &hits{
        if hit.source_pk>0&&matches!(hit.source.as_str(),"status"|"mention"|"event"){ids.entry(hit.source.as_str()).or_default().push(hit.source_pk);}
        if hit.source=="bsky_post"{let oid=trim(hit.object_id.as_deref().unwrap_or(""));if oid.starts_with("at://"){bsky_uris.push(oid.to_owned());}}
    }
    let load=|sql:&'static str,key:&'static str,list:Vec<i64>|async move{
        let mut map=HashMap::new();
        if list.is_empty(){return Ok::<_,anyhow::Error>(map)}
        for row in db.query(sql,&[&list]).await?{let v:Value=row.get(0);map.insert(crate::library::card::integer(&v,key),v);}
        Ok(map)
    };
    let status_rows=load("SELECT to_jsonb(s) FROM masto_statuses s WHERE local_id=ANY($1)","local_id",ids.remove("status").unwrap_or_default()).await?;
    let mention_rows=load("SELECT to_jsonb(m) FROM mentions m WHERE id=ANY($1) AND deleted_at IS NULL","id",ids.remove("mention").unwrap_or_default()).await?;
    let event_rows=load("SELECT to_jsonb(e) FROM events e WHERE id=ANY($1)","id",ids.remove("event").unwrap_or_default()).await?;
    // Announce/Like hits remap to the Note's own Create or Update.
    let mut objects:Vec<String>=Vec::new();
    for row in event_rows.values(){
        let kind=s(row,"type").to_ascii_lowercase();let object=s(row,"object_id").trim_end_matches('/');
        if !object.is_empty()&&kind!="create"&&kind!="update"{objects.push(object.into());objects.push(format!("{object}/"));}
    }
    let mut canon:HashMap<String,Value>=HashMap::new();
    if !objects.is_empty(){
        for row in db.query("SELECT to_jsonb(e) FROM events e WHERE object_id=ANY($1) AND type IN ('Create','Update') AND COALESCE(action_taken,'')!='deleted' ORDER BY CASE type WHEN 'Create' THEN 0 ELSE 1 END ASC, id DESC",&[&objects]).await?{
            let v:Value=row.get(0);let oid=s(&v,"object_id").trim_end_matches('/').to_owned();
            if !oid.is_empty(){canon.entry(oid).or_insert(v);}
        }
    }
    let mut bsky_rows:HashMap<String,String>=HashMap::new();
    if !bsky_uris.is_empty(){
        for row in db.query("SELECT bsky_uri, raw_json FROM bsky_posts WHERE bsky_uri=ANY($1) AND raw_json IS NOT NULL",&[&bsky_uris]).await?{
            let uri:String=row.get(0);if !uri.is_empty(){bsky_rows.insert(uri,row.get::<_,Option<String>>(1).unwrap_or_default());}
        }
    }
    let tag_re=if tag.is_empty(){None}else{Some(regex::Regex::new(&format!(r"(?i)#{}\b",regex::escape(&tag)))?)};
    let mut projector=crate::profile_materialize::SearchProjector::new(db,owner.id,cfg);
    let mut bsky=bsky::BskyBuilder::new(db,owner.id,cfg,warm);
    let mut seen:HashSet<String>=HashSet::new();let mut built=0usize;let mut window=Sanitized::default();
    let mut push=|status:Value,window:&mut Sanitized,built:&mut usize|{
        if *built>=STATUS_FETCH{return}
        let id=string(status.get("id"));
        if id.is_empty()||!seen.insert(id){return}
        *built+=1;window.push(&status);
    };
    for hit in &hits{
        match hit.source.as_str(){
            "status" if hit.source_pk>0=>{
                if let Some(row)=status_rows.get(&hit.source_pk){
                    if let Some(st)=projector.project(crate::profile_materialize::SearchHit::Status(row),warm,dns).await?{push(st,&mut window,&mut built);}
                }
            }
            "mention" if hit.source_pk>0=>{
                if mention_rows.contains_key(&hit.source_pk){bail!("PHP fallback: Search mention hit")}
            }
            "event" if hit.source_pk>0=>{
                if let Some(row)=event_rows.get(&hit.source_pk){
                    if let Some(re)=&tag_re{if !re.is_match(&crate::html_entities::decode(s(row,"summary"))){continue}}
                    let kind=s(row,"type").to_ascii_lowercase();let object=s(row,"object_id").trim_end_matches('/');
                    let row=if !object.is_empty()&&kind!="create"&&kind!="update"{canon.get(object).unwrap_or(row)}else{row};
                    if let Some(st)=projector.project(crate::profile_materialize::SearchHit::Event(row),warm,dns).await?{push(st,&mut window,&mut built);}
                }
            }
            "bsky_post"=>{
                let oid=trim(hit.object_id.as_deref().unwrap_or(""));
                if let Some(raw)=bsky_rows.get(oid){
                    if let Some(st)=bsky_status(&mut bsky,raw,tag_re.as_ref()).await?{push(st,&mut window,&mut built);}
                }
            }
            _=>{}
        }
        if built>=STATUS_FETCH||window.full(){break}
    }
    // LIKE scan only when the index under-filled the window.
    if built<STATUS_FETCH&&!window.full(){
        let needle=format!("%{}%",q_lower.replace('%',"\\%").replace('_',"\\_"));
        let since=chrono::DateTime::from_timestamp(chrono::Utc::now().timestamp()-30*86400,0).context("Search LIKE window")?.format("%Y-%m-%dT%H:%M:%S+00:00").to_string();
        let cap=40i64.max(((STATUS_FETCH-built)*3) as i64);
        let rows=db.query("SELECT raw_json FROM bsky_posts WHERE published_at >= $1 AND text IS NOT NULL AND lower(text) LIKE $2 ESCAPE E'\\\\' ORDER BY published_at DESC LIMIT $3",&[&since,&needle,&cap]).await?;
        for row in rows{
            let raw=row.get::<_,Option<String>>(0).unwrap_or_default();
            if let Some(st)=bsky_status(&mut bsky,&raw,tag_re.as_ref()).await?{push(st,&mut window,&mut built);}
            if built>=STATUS_FETCH||window.full(){break}
        }
    }
    window.finish()
}

/// One bsky_posts.raw_json row through ap_masto_bsky_trend_status.
async fn bsky_status(builder:&mut bsky::BskyBuilder<'_>,raw_json:&str,tag:Option<&regex::Regex>)->Result<Option<Value>>{
    let raw:Value=serde_json::from_str(raw_json).unwrap_or(Value::Null);
    let post=if raw.get("post").is_some_and(|p|p.is_object()||p.is_array()){&raw["post"]}else{&raw};
    if !(post.is_object()||post.is_array()){return Ok(None)}
    if post.is_array(){bail!("PHP fallback: Bluesky Search row is a list")}
    let record_text=match post["record"].get("text"){Some(Value::String(t))=>t.clone(),Some(Value::Null)|None=>String::new(),_=>bail!("PHP fallback: Bluesky record text is not a string")};
    let text=if record_text.is_empty()||record_text=="0"{match post.get("text"){Some(Value::String(t))=>t.clone(),Some(Value::Null)|None=>String::new(),_=>bail!("PHP fallback: Bluesky text is not a string")}}else{record_text};
    if let Some(re)=tag{if !re.is_match(&text){return Ok(None)}}
    builder.status(post,&raw).await
}

/// ap_masto_search_hashtags($q, 80).
async fn hashtags(db:&Client,owner:&Owner,q:&str)->Result<Vec<Value>>{
    let names=crate::search_components::admission::ordered_tag_names(db,owner.id,&owner.actor,&owner.key,q).await?;
    let names:Vec<String>=names.into_iter().take(TAG_FETCH).map(|n|normalize_tag_name(&n)).collect();
    let followed:HashSet<String>=if names.is_empty(){HashSet::new()}else{
        db.query("SELECT name FROM masto_followed_tags WHERE owner_user_id=$1::bigint AND name=ANY($2::text[])",&[&owner.id,&names]).await?.into_iter().map(|r|r.get::<_,String>(0)).collect()
    };
    let now=chrono::Utc::now().timestamp();
    Ok(names.iter().map(|n|tag_entity(n,followed.contains(n),now)).collect())
}

fn levenshtein(a:&str,b:&str)->usize{
    let (a,b)=(a.as_bytes(),b.as_bytes());let mut prev:Vec<usize>=(0..=b.len()).collect();
    for (i,ca) in a.iter().enumerate(){
        let mut cur=vec![i+1;b.len()+1];
        for (j,cb) in b.iter().enumerate(){cur[j+1]=(prev[j]+usize::from(ca!=cb)).min(prev[j+1]+1).min(cur[j]+1);}
        prev=cur;
    }
    prev[b.len()]
}
fn host_is_ours(host:&str)->bool{matches!(host.to_ascii_lowercase().as_str(),"mkultra.monster"|"www.mkultra.monster"|"vaak.monster"|"www.vaak.monster")}
fn host_is_our_typo(host:&str)->bool{
    let host=trim(host).to_ascii_lowercase();
    if host.is_empty()||host_is_ours(&host){return false}
    ["mkultra.monster","vaak.monster"].iter().any(|ours|host.len().abs_diff(ours.len())<=2&&levenshtein(&host,ours)<=2)
}

struct Accounts{rows:Vec<Value>,more:bool,sources:Vec<String>}

/// admin_search_account_results. Ok(None) leaves the section to PHP.
async fn accounts(db:&Client,owner:&Owner,raw_q:&str,resolve:bool,limit:usize,offset:usize,warm:Option<&crate::profile_enrichment::WarmContext>,dns:&dyn crate::direct_preview::PreviewDns,cfg:Option<&crate::config::Config>)->Result<Option<Accounts>>{
    let stop=80usize.min(offset+limit+1);let scan=80usize.min(stop.max(limit*4)) as i64;
    let q=trim(raw_q);
    if q.is_empty(){return Ok(Some(Accounts{rows:vec![],more:false,sources:vec![]}))}
    let q=chars_prefix(q,200);
    static HANDLE:OnceLock<regex::Regex>=OnceLock::new();
    static URL:OnceLock<regex::Regex>=OnceLock::new();
    static PATH:OnceLock<regex::Regex>=OnceLock::new();
    static WWW:OnceLock<regex::Regex>=OnceLock::new();
    static HOST:OnceLock<regex::Regex>=OnceLock::new();
    let (mut handle,mut host,mut is_host)=(None::<String>,None::<String>,false);
    if let Some(m)=HANDLE.get_or_init(||regex::Regex::new(r"^@?([A-Za-z0-9_.\-]+)@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})$").unwrap()).captures(&q){
        handle=Some(m[1].into());host=Some(m[2].to_ascii_lowercase());
    }else if let Some(m)=URL.get_or_init(||regex::Regex::new(r"(?i)^https?://([^/]+)(/[^?#]*)?").unwrap()).captures(&q){
        host=Some(m[1].to_ascii_lowercase());let path=m.get(2).map(|p|p.as_str()).unwrap_or("");
        if let Some(pm)=PATH.get_or_init(||regex::Regex::new(r"^/(?:users|@|vaak/users)/([A-Za-z0-9_]+)/?$").unwrap()).captures(path){handle=Some(pm[1].into());}
        else if path.is_empty()||path=="/"{is_host=true;}
    }else{
        let cand=q.trim_start_matches('@');let cand=WWW.get_or_init(||regex::Regex::new(r"(?i)^www\.").unwrap()).replace(cand,"").into_owned();
        if HOST.get_or_init(||regex::Regex::new(r"^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$").unwrap()).is_match(&cand)&&!cand.contains('@'){host=Some(cand.to_ascii_lowercase());is_host=true;}
    }
    let policy=crate::search_context::SearchAccountPolicy::load(db,owner.id,&owner.actor,&owner.key).await?;
    let mut collector=AccountCollector{db,policy,stop,out:vec![],seen:HashSet::new(),sources:HashMap::new(),projector:crate::profile_materialize::SearchProjector::new(db,owner.id,cfg),warm,dns};
    let handle=handle.filter(|h|!h.is_empty());let host=host.filter(|h|!h.is_empty());
    let done=|c:AccountCollector|{let more=c.out.len()>offset+limit;let rows:Vec<Value>=c.out.into_iter().skip(offset).take(limit).collect();
        let sources=rows.iter().map(|a|c.sources.get(&a.to_string()).cloned().unwrap_or_else(||{let v=match a.get("uri"){Some(v) if !v.is_null()=>string(Some(v)),_=>string(a.get("url"))};v.trim_end_matches('/').to_owned()})).collect();
        Some(Accounts{rows,more,sources})};
    if let (Some(h),Some(hs))=(&handle,&host){
        if host_is_ours(hs){collector.local(h).await?;return Ok(done(collector))}
        if host_is_our_typo(hs){collector.local(h).await?;if !collector.out.is_empty(){return Ok(done(collector))}}
    }
    if is_host&&host.as_deref().is_some_and(host_is_ours){
        for row in db.query("SELECT actor_key FROM ap_users WHERE disabled_at IS NULL ORDER BY id ASC LIMIT $1",&[&scan]).await?{collector.local(&row.get::<_,Option<String>>(0).unwrap_or_default()).await?;}
        return Ok(done(collector))
    }
    // Remote host listings and WebFinger resolution reach the network.
    if is_host&&host.is_some(){return Ok(None)}
    if handle.is_some()&&host.is_some()&&resolve{return Ok(None)}
    let needle=lower(q.trim_start_matches('@'));
    if needle.is_empty(){return Ok(Some(Accounts{rows:vec![],more:false,sources:vec![]}))}
    for row in db.query("SELECT actor_key FROM ap_users WHERE disabled_at IS NULL AND (strpos(lower(actor_key), $1) > 0 OR strpos(lower(COALESCE(username, '')), $1) > 0) ORDER BY id ASC LIMIT $2",&[&needle,&scan]).await?{
        collector.local(&row.get::<_,Option<String>>(0).unwrap_or_default()).await?;
    }
    if collector.out.len()>=stop{return Ok(done(collector))}
    static LOCAL:OnceLock<regex::Regex>=OnceLock::new();
    static LOCAL_KEY:OnceLock<regex::Regex>=OnceLock::new();
    for row in db.query("SELECT actor_id FROM remote_actors WHERE lower(COALESCE(host, '')) NOT IN ('mkultra.monster', 'www.mkultra.monster', 'vaak.monster', 'www.vaak.monster') AND (strpos(lower(COALESCE(username, '')), $1) > 0 OR strpos(lower(COALESCE(display_name, '')), $1) > 0) ORDER BY updated_at DESC NULLS LAST LIMIT $2",&[&needle,&scan]).await?{
        let actor=row.get::<_,Option<String>>(0).unwrap_or_default();let actor=actor.trim_end_matches('/');
        if actor.is_empty(){continue}
        if LOCAL.get_or_init(||regex::Regex::new(r"^https://mkultra\.monster/users/[A-Za-z0-9_]+(?:/|$)").unwrap()).is_match(actor){
            if let Some(m)=LOCAL_KEY.get_or_init(||regex::Regex::new(r"/users/([A-Za-z0-9_]+)$").unwrap()).captures(actor){collector.local(&m[1]).await?;}
            continue;
        }
        let account=match collector.projector.remote_account(actor,warm,dns).await{
            Ok(a)=>a,
            Err(e) if format!("{e:#}").contains("PHP fallback")=>return Ok(None),
            Err(e)=>return Err(e),
        };
        collector.sources.entry(account.to_string()).or_insert_with(||actor.to_owned());
        collector.push(account);
    }
    Ok(done(collector))
}

struct AccountCollector<'a>{
    db:&'a Client,policy:crate::search_context::SearchAccountPolicy,stop:usize,out:Vec<Value>,seen:HashSet<String>,sources:HashMap<String,String>,
    projector:crate::profile_materialize::SearchProjector<'a>,warm:Option<&'a crate::profile_enrichment::WarmContext>,dns:&'a dyn crate::direct_preview::PreviewDns,
}
impl AccountCollector<'_>{
    fn push(&mut self,account:Value){
        if self.out.len()>=self.stop{return}
        let uri=string(account.get("uri"));let uri=uri.trim_end_matches('/');
        let host=reqwest::Url::parse(uri).ok().and_then(|u|u.host_str().map(str::to_ascii_lowercase)).unwrap_or_default();
        if host=="vaak.monster"||host=="www.vaak.monster"{return}
        let id=string(account.get("id"));let key=if uri.is_empty(){id}else{uri.to_owned()};
        if key.is_empty()||!self.seen.insert(key){return}
        if self.policy.hidden(&account){return}
        self.out.push(account);
    }
    /// admin_search_local_account.
    async fn local(&mut self,raw:&str)->Result<()>{
        let key:String=raw.replace('-',"_").chars().filter(|c|c.is_ascii_lowercase()||c.is_ascii_digit()||*c=='_').collect();
        if key.is_empty(){return Ok(())}
        static USER:OnceLock<regex::Regex>=OnceLock::new();
        let row=if USER.get_or_init(||regex::Regex::new(r"^[a-z][a-z0-9_]{1,29}$").unwrap()).is_match(&key){
            self.db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE lower(username)=$1 AND disabled_at IS NULL LIMIT 1",&[&key]).await?.map(|r|r.get::<_,Value>(0))
        }else{None};
        let Some(row)=row else{
            if key=="cmdr_nova"{bail!("PHP fallback: primary account row missing")}
            return Ok(())
        };
        let actor_key=match row.get("actor_key"){Some(Value::String(k))=>k.clone(),Some(Value::Null)|None=>s(&row,"username").to_owned(),_=>bail!("PHP fallback: account key type")};
        let actor_key=trim(&actor_key).to_ascii_lowercase();
        if actor_key.is_empty()||!actor_key.bytes().all(|b|b.is_ascii_alphanumeric()||b==b'_'){bail!("PHP fallback: local account key shape")}
        let account=self.projector.local_account(&actor_key,self.warm,self.dns).await?;
        if string(account.get("id")).is_empty(){return Ok(())}
        self.push(account);Ok(())
    }
}

/// The whole admin_search_run bundle, or an error for PHP to take over.
async fn run(db:&Client,cfg:Option<&crate::config::Config>,warm:Option<&crate::profile_enrichment::WarmContext>,dns:&dyn crate::direct_preview::PreviewDns,request:&Request,cache_dir:&std::path::Path)->Result<Value>{
    let limit=request.limit.clamp(1,40) as usize;let offset=request.offset.clamp(0,160) as usize;
    let no_more=json!({"accounts":false,"hashtags":false,"statuses":false});
    let empty=json!({"accounts":[],"hashtags":[],"statuses":[],"has_more":no_more});
    let sq=trim(&request.query).to_owned();
    if sq.is_empty(){return Ok(json!({"protocol_version":1,"bundle":empty,"accounts_php":false}))}
    if sq.starts_with("https://")&&url_looks_like_status(&sq){return Ok(json!({"protocol_version":1,"bundle":empty,"accounts_php":false}))}
    // Every native needle uses this lowercase, so it has to agree with PHP's.
    if request.query_lower!=lower(&sq){bail!("PHP fallback: query lowercase differs")}
    let row=db.query_opt("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&request.owner_id]).await?.context("inactive Search collect owner")?;
    let owner=Owner{id:request.owner_id,actor:row.get(0),key:row.get(1)};
    let stype:String=request.section.chars().filter(|c|c.is_ascii_lowercase()).collect();
    let need_statuses=stype.is_empty()||stype=="statuses";let need_tags=stype.is_empty()||stype=="hashtags";let need_accounts=stype.is_empty()||stype=="accounts";

    let key=hex::encode(Sha256::digest(format!("mf3|{}|{}|{}|{STATUS_FETCH}|{TAG_FETCH}",owner.id,request.query_lower,if request.resolve{"1"}else{"0"}).as_bytes()));
    {use std::os::unix::fs::DirBuilderExt;let _=std::fs::DirBuilder::new().recursive(true).mode(0o770).create(cache_dir);}
    let path=cache_dir.join(format!("s_{key}.json"));
    let allow_read=offset>0||sq.starts_with('#');
    let mut bundle_statuses:Vec<Value>=vec![];let mut bundle_tags:Vec<Value>=vec![];
    let (mut have_statuses,mut have_tags)=(false,false);
    let mut cached:Option<Value>=None;
    if allow_read{
        if let Ok(meta)=std::fs::metadata(&path){
            let age=meta.modified().ok().and_then(|m|m.duration_since(std::time::UNIX_EPOCH).ok()).map(|d|chrono::Utc::now().timestamp()-d.as_secs() as i64);
            if meta.is_file()&&age.is_some_and(|a|a<request.cache_ttl){
                let decoded:Value=std::fs::read(&path).ok().and_then(|b|serde_json::from_slice(&b).ok()).unwrap_or(Value::Null);
                let listish=|v:&Value|v.is_array()||v.is_object();
                if decoded.is_object()&&listish(&decoded["hashtags"])&&listish(&decoded["statuses"]){
                    if !decoded["statuses"].is_array(){bail!("PHP fallback: cached Search statuses are keyed")}
                    have_statuses=truthy(&decoded["have_statuses"]);
                    if have_statuses{bundle_statuses=decoded["statuses"].as_array().cloned().unwrap_or_default();}
                    cached=Some(decoded);
                }
            }
        }
    }
    let mut dirty=false;
    if need_statuses&&!have_statuses{bundle_statuses=statuses(db,&owner,&sq,cfg,warm,dns).await?;have_statuses=true;dirty=true;}
    if need_tags&&!have_tags{bundle_tags=hashtags(db,&owner,&sq).await?;have_tags=true;dirty=true;}
    // Accounts come before the cache write here (PHP writes first); an
    // account section PHP has to finish never needs a second status pass.
    let account_section=if need_accounts{accounts(db,&owner,&sq,request.resolve,limit,offset,warm,dns,cfg).await?}else{Some(Accounts{rows:vec![],more:false,sources:vec![]})};
    if dirty{
        let mut payload=Map::new();
        payload.insert("accounts".into(),json!([]));
        payload.insert("hashtags".into(),if have_tags{json!(bundle_tags)}else{json!([])});
        payload.insert("statuses".into(),if have_statuses{json!(bundle_statuses)}else{json!([])});
        payload.insert("have_statuses".into(),json!(have_statuses));
        payload.insert("have_tags".into(),json!(have_tags));
        if let Some(c)=&cached{
            if !have_tags&&truthy(&c["have_tags"])&&(c["hashtags"].is_array()||c["hashtags"].is_object()){payload.insert("hashtags".into(),c["hashtags"].clone());payload.insert("have_tags".into(),json!(true));}
            if !have_statuses&&truthy(&c["have_statuses"])&&(c["statuses"].is_array()||c["statuses"].is_object()){payload.insert("statuses".into(),c["statuses"].clone());payload.insert("have_statuses".into(),json!(true));}
        }
        let tmp=path.with_file_name(format!("s_{key}.json.{}.tmp",std::process::id()));
        if std::fs::write(&tmp,serde_json::to_vec(&Value::Object(payload))?).is_ok(){let _=std::fs::rename(&tmp,&path);}
    }

    let mut statuses_out=vec![];let mut st_more=false;
    if need_statuses{
        let mask=crate::search_context::selected_current_mask(db,owner.id,&owner.actor,&owner.key,&bundle_statuses).await?;
        let filtered:Vec<Value>=bundle_statuses.iter().zip(mask).filter(|(_,ok)|*ok).map(|(v,_)|v.clone()).take(STATUS_FETCH).collect();
        let filtered=downrank::apply(db,owner.id,filtered).await?;
        st_more=filtered.len()>offset+limit;
        statuses_out=filtered.into_iter().skip(offset).take(limit).collect();
    }
    let mut tags_out=vec![];let mut tag_more=false;
    if need_tags{tag_more=bundle_tags.len()>offset+limit;tags_out=bundle_tags.iter().skip(offset).take(limit).cloned().collect();}

    let collector=if need_statuses&&!statuses_out.is_empty(){collector_descriptor(&path,cache_dir,&statuses_out,request,offset,limit)}else{Value::Null};
    let (accounts_php,account_rows,account_more,account_sources)=match account_section{
        Some(a)=>(false,a.rows,a.more,a.sources),
        None=>(true,vec![],false,vec![]),
    };
    let bundle=json!({
        "_native_collector":collector,
        "_native_components":{"query":sq,"section":stype,"offset":offset,"limit":limit,"account_sources":account_sources},
        "accounts":account_rows,"hashtags":tags_out,"statuses":statuses_out,
        "has_more":{"accounts":account_more,"hashtags":tag_more,"statuses":st_more},
    });
    Ok(json!({"protocol_version":1,"bundle":bundle,"accounts_php":accounts_php}))
}

/// The selected page's ordinals in the cache file the render bridge rereads.
fn collector_descriptor(path:&std::path::Path,cache_dir:&std::path::Path,selected:&[Value],request:&Request,offset:usize,limit:usize)->Value{
    let Ok(meta)=std::fs::symlink_metadata(path) else{return Value::Null};
    if !meta.is_file()||meta.file_type().is_symlink()||meta.len()>2*1024*1024{return Value::Null}
    let Ok(bytes)=std::fs::read(path) else{return Value::Null};
    if bytes.len()>2*1024*1024{return Value::Null}
    let cache:Value=serde_json::from_slice(&bytes).unwrap_or(Value::Null);
    let Some(candidates)=cache["statuses"].as_array().filter(|_|truthy(&cache["have_statuses"])) else{return Value::Null};
    if candidates.len()>STATUS_FETCH{return Value::Null}
    let mut ordinals=Vec::new();let mut after:Option<usize>=None;
    for status in selected{
        let found=candidates.iter().enumerate().find(|(i,c)|after.is_none_or(|a|*i>a)&&*c==status).map(|(i,_)|i);
        let Some(found)=found else{return Value::Null};
        ordinals.push(found);after=Some(found);
    }
    json!({"normalized_query":request.query_lower,"resolve":request.resolve,
        "cache_location":if cache_dir==std::path::Path::new(PRIMARY_CACHE){"primary"}else{"temporary"},
        "cache_sha256":hex::encode(Sha256::digest(&bytes)),"offset":offset,"limit":limit,"selected_ordinals":ordinals})
}

#[cfg(test)]
mod oracle{
    use super::*;
    use std::{future::Future,pin::Pin,sync::Arc};
    struct NoWorkers;
    impl crate::profile_warm::WarmTransport for NoWorkers{
        fn actor_process<'a>(&'a self,_:&'a str)->Pin<Box<dyn Future<Output=bool>+Send+'a>>{Box::pin(async{false})}
        fn media_process(&self)->Pin<Box<dyn Future<Output=bool>+Send+'_>>{Box::pin(async{false})}
    }
    /// Runs the native collector over the PHP oracle's cases on a copy of the
    /// same fixture database: SEARCH_ORACLE_DATABASE_URL, SEARCH_ORACLE_REDIS,
    /// SEARCH_ORACLE_CASES (JSON list) and SEARCH_ORACLE_OUT.
    #[tokio::test]
    #[ignore]
    async fn php_oracle_dump()->Result<()>{
        let url=std::env::var("SEARCH_ORACLE_DATABASE_URL")?;let redis=std::env::var("SEARCH_ORACLE_REDIS")?;
        let cases:Vec<Value>=serde_json::from_str(&std::env::var("SEARCH_ORACLE_CASES")?)?;
        let cfg=crate::config::Config{database_url:url.clone(),redis_url:redis.clone(),redis_queue_url:redis,jetstream_state:Default::default(),default_owner_id:1,bsky_public_api:String::new(),notif_cache_dir:Default::default(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
        let warm=crate::profile_enrichment::WarmContext::new(cfg.clone(),Arc::new(NoWorkers));
        let db=crate::db::connect(&url).await?;
        let dir=std::env::temp_dir().join("vaak-search-cache-native-oracle");std::fs::create_dir_all(&dir)?;
        let mut out=Vec::new();
        for case in cases{
            if !truthy(&case["keep_cache"]){for e in std::fs::read_dir(&dir)?{let p=e?.path();if p.file_name().is_some_and(|n|n.to_string_lossy().starts_with("s_")){std::fs::remove_file(p)?;}}}
            let q=s(&case,"q").to_owned();
            let request=Request{protocol_version:1,owner_id:case["owner"].as_i64().unwrap_or(1),query_lower:lower(trim(&q)),query:q,section:s(&case,"type").into(),resolve:truthy(&case["resolve"]),limit:case["limit"].as_i64().unwrap_or(20),offset:case["offset"].as_i64().unwrap_or(0),cache_ttl:45};
            let started=std::time::Instant::now();
            let result=run(&db,Some(&cfg),Some(&warm),crate::direct_preview::PreviewDependencies::production().dns,&request,&dir).await;
            let ms=started.elapsed().as_millis() as u64;
            let mut caches=Map::new();
            for e in std::fs::read_dir(&dir)?{let p=e?.path();let name=p.file_name().unwrap().to_string_lossy().to_string();if name.starts_with("s_")&&name.ends_with(".json"){caches.insert(name,serde_json::from_slice(&std::fs::read(&p)?)?);}}
            out.push(match result{Ok(reply)=>json!({"case":case,"reply":reply,"cache":caches,"ms":ms}),Err(e)=>json!({"case":case,"fallback":format!("{e:#}"),"cache":caches,"ms":ms})});
        }
        std::fs::write(std::env::var("SEARCH_ORACLE_OUT")?,serde_json::to_vec_pretty(&out)?)?;
        let urls:Vec<String>=serde_json::from_str(&std::env::var("SEARCH_ORACLE_RESOLVE").unwrap_or_else(|_|"[]".into()))?;
        let mut resolved=Vec::new();
        for url in urls{resolved.push(match resolve_cached(&db,&url).await{Ok(o)=>json!({"url":url,"object":o}),Err(e)=>json!({"url":url,"fallback":format!("{e:#}")})});}
        std::fs::write(format!("{}.resolve.json",std::env::var("SEARCH_ORACLE_OUT")?),serde_json::to_vec_pretty(&resolved)?)?;Ok(())
    }
}

#[cfg(test)]
mod tests{
    use super::*;
    #[test]fn php_helpers(){
        assert!(url_looks_like_status("https://mastodon.social/@alice/1234"));
        assert!(url_looks_like_status("https://mkultra.monster/users/cmdr_nova/notes/abc"));
        assert!(!url_looks_like_status("https://mastodon.social/@alice"));
        assert!(!url_looks_like_status("https://mastodon.social/users/alice/"));
        assert!(!url_looks_like_status("https://mastodon.social"));
        assert_eq!(php_url_path("https://h.test/a/b?x#y"),"/a/b");
        assert_eq!(normalize_tag_name("#RustLang!"),"rustlang");
        assert_eq!(crc32("123456789"),3_421_780_262);
        assert!(host_is_our_typo("mkutlra.monster"));assert!(!host_is_our_typo("mkultra.monster"));assert!(!host_is_our_typo("example.com"));
        assert_eq!(strip_tags("a<br>\nb<!-- c -->").unwrap(),"a\nb");
        assert!(strip_tags("a < b").is_err());
        assert_eq!(mention_target_object_id("https://h.test/users/a/statuses/1#like-abc/"),"https://h.test/users/a/statuses/1");
        assert_eq!(mention_target_object_id("https://h.test/users/a/statuses/1/#announce-12"),"https://h.test/users/a/statuses/1");
        assert_eq!(mention_target_object_id("https://h.test/@a/1/"),"https://h.test/@a/1");
        let tag=tag_entity("rustlang",true,86400*3+5);assert_eq!(tag["history"][0]["day"],"259200");assert_eq!(tag["url"],"https://mkultra.monster/tags/rustlang");
    }
    #[test]fn sanitizer_matches_php(){
        let mut w=Sanitized::default();
        w.push(&json!({"id":"1","uri":"https://h/a#announce-1","url":"https://h/a#x","account":{"id":"a"},"media_attachments":[{"meta":{}}]}));
        w.push(&json!({"id":"2","uri":"https://h/a/","account":{"id":"a"}}));
        w.push(&json!({"id":"3","reblog":{"id":"4","uri":"https://h/b","account":{"id":"b","note":null}},"account":{"id":"c"}}));
        w.push(&json!({"id":"5","uri":"https://h/c"}));
        let out=w.finish().unwrap();
        assert_eq!(out.len(),2);
        assert_eq!(out[0]["uri"],"https://h/a");assert_eq!(out[0]["url"],"https://h/a");assert_eq!(out[0]["media_attachments"][0]["meta"],Value::Null);assert_eq!(out[0]["quotes_count"],0);
        assert_eq!(out[1]["id"],"4");assert_eq!(out[1]["account"]["note"],"");assert_eq!(out[1]["reblog"],Value::Null);
    }
}
