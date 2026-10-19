//! ap_masto_bsky_trend_status for ranked Search hits, including the author
//! enrichment and ap_normalize_status passes it runs. Shapes that would make
//! PHP queue an actor refresh, or rewrite quote or Ask bodies, stay on PHP.
use anyhow::{bail,Context,Result};
use serde_json::{json,Value};
use std::collections::HashMap;
use tokio_postgres::Client;

macro_rules! re{($pattern:literal)=>{{static RE:std::sync::OnceLock<regex::Regex>=std::sync::OnceLock::new();RE.get_or_init(||regex::Regex::new($pattern).unwrap())}}}

fn s<'a>(v:&'a Value,k:&str)->&'a str{v.get(k).and_then(Value::as_str).unwrap_or("")}
fn trim(v:&str)->&str{crate::library::card::php_trim(v)}
/// PHP `$a[$k] ?? ''` cast to string, for keys whose values are scalars.
fn scalar(v:&Value,k:&str)->Result<String>{
    match v.get(k){None|Some(Value::Null)=>Ok(String::new()),Some(Value::String(t))=>Ok(t.clone()),Some(Value::Bool(b))=>Ok(if *b{"1".into()}else{String::new()}),
        Some(Value::Number(n))=>{if n.is_f64(){bail!("PHP fallback: Bluesky float scalar cast")}Ok(n.to_string())},_=>bail!("PHP fallback: Bluesky array scalar cast")}
}
/// PHP `(a ?? '') ?: (b ?? '')` over two string scalars.
fn first_truthy(a:String,b:String)->String{if a.is_empty()||a=="0"{b}else{a}}

fn rawurlencode(v:&str)->String{crate::library::actions::query(v)}
fn rawurldecode(v:&str)->String{
    let bytes=v.as_bytes();let mut out=Vec::with_capacity(bytes.len());let mut i=0;
    while i<bytes.len(){if bytes[i]==b'%'&&i+2<bytes.len(){if let Ok(n)=u8::from_str_radix(std::str::from_utf8(&bytes[i+1..i+3]).unwrap_or("zz"),16){out.push(n);i+=3;continue}}out.push(bytes[i]);i+=1;}
    String::from_utf8_lossy(&out).into_owned()
}
/// ap_bsky_actor_profile_url.
pub(super) fn actor_profile_url(handle_or_did:&str)->String{
    let mut h=trim(handle_or_did).trim_start_matches('@').to_owned();
    if h.is_empty(){return "https://bsky.app/".into()}
    if h.starts_with("did%3A")||h.starts_with("did%3a"){h=rawurldecode(&h)}
    let path=if h.starts_with("did:"){h}else{rawurlencode(&h)};
    format!("https://bsky.app/profile/{path}")
}
/// ap_bsky_https_url_from_at_uri for an at:// post URI.
fn https_url_from_at_uri(uri:&str,handle:&str)->Result<String>{
    let uri=trim(uri);
    let post=re!(r"^at://([^/]+)/app\.bsky\.feed\.post/([^/\s?]+)");
    let Some(c)=post.captures(uri)else{bail!("PHP fallback: Bluesky Search URI is not a post")};
    let mut actor=if handle.is_empty(){c[1].to_owned()}else{handle.to_owned()};
    if actor.starts_with("did%3A")||actor.starts_with("did%3a"){actor=rawurldecode(&actor)}
    let path=if actor.starts_with("did:"){actor}else{rawurlencode(&actor)};
    Ok(format!("https://bsky.app/profile/{path}/post/{}",rawurlencode(&c[2])))
}
fn cid_ok(cid:&str)->bool{let cid=trim(cid);(20..=128).contains(&cid.len())&&cid.bytes().all(|b|b.is_ascii_alphanumeric())}
/// ap_bsky_avatar_public_url.
fn avatar_public_url(avatar:&Value,did:&str)->Result<Option<String>>{
    let did=trim(did);
    match avatar{
        Value::String(raw)=>{
            let raw=trim(raw);if raw.is_empty(){return Ok(None)}
            if raw.starts_with("https://"){return crate::profile_enrichment::sanitize_profile_url(raw)}
            if did.starts_with("did:")&&!did.contains('%')&&cid_ok(raw){return Ok(Some(format!("https://cdn.bsky.app/img/avatar/plain/{did}/{raw}@jpeg")))}
            Ok(None)
        }
        Value::Object(_)=>{
            let mime=first_truthy(scalar(avatar,"mimeType")?,String::new());
            let mime=if avatar.get("mimeType").is_some_and(|v|!v.is_null()){mime}else{scalar(avatar,"mime")?}.to_ascii_lowercase();
            let ext=if mime.contains("png"){"png"}else if mime.contains("webp"){"webp"}else if mime.contains("gif"){"gif"}else{"jpeg"};
            let mut cid=match avatar.get("ref"){Some(r@Value::Object(_))=>trim(&if r.get("$link").is_some_and(|v|!v.is_null()){scalar(r,"$link")?}else{scalar(r,"link")?}).to_owned(),Some(Value::String(r))=>trim(r).to_owned(),_=>String::new()};
            if cid.is_empty(){cid=trim(&if avatar.get("cid").is_some_and(|v|!v.is_null()){scalar(avatar,"cid")?}else{scalar(avatar,"$link")?}).to_owned();}
            if !did.starts_with("did:")||did.contains('%')||!cid_ok(&cid){return Ok(None)}
            Ok(Some(format!("https://cdn.bsky.app/img/avatar/plain/{did}/{cid}@{ext}")))
        }
        _=>Ok(None),
    }
}
/// PHP `nl2br($text, false)`.
fn nl2br(text:&str)->String{
    let mut out=String::with_capacity(text.len()+8);let mut chars=text.chars().peekable();
    while let Some(c)=chars.next(){
        if c=='\r'||c=='\n'{out.push_str("<br>");out.push(c);if let Some(&n)=chars.peek(){if (n=='\r'||n=='\n')&&n!=c{out.push(n);chars.next();}}}
        else{out.push(c)}
    }
    out
}
fn esc(text:&str)->String{crate::library::actions::esc(text)}

struct Animated{url:String,preview:String,kind:&'static str,media_type:Option<&'static str>}
/// ap_masto_animated_media_from_url.
fn animated(url:&str,preview:&str)->Animated{
    let url=trim(url).to_owned();let mut preview=trim(preview).to_owned();let lower=url.to_ascii_lowercase();
    let parsed=reqwest::Url::parse(&url).ok();
    let raw_path=parsed.as_ref().map(|u|u.path().to_owned()).unwrap_or_default();let path=raw_path.to_ascii_lowercase();
    let query=parsed.as_ref().and_then(|u|u.query().map(str::to_owned)).unwrap_or_default();
    if lower.contains("klipy.com")&&!query.is_empty(){
        if let Some(m)=re!(r"(?i)(?:^|&)mp4=([^&]+)").captures(&query){
            let dir=re!(r"/[^/]+$").replace(&raw_path,"/").into_owned();let dir=if dir.is_empty(){"/".to_owned()}else{dir};
            let scheme=parsed.as_ref().map(|u|u.scheme().to_owned()).unwrap_or_else(||"https".into());
            let host=parsed.as_ref().and_then(|u|u.host_str().map(str::to_owned)).unwrap_or_else(||"static.klipy.com".into());
            let id=rawurldecode(&m[1]);
            if !id.is_empty(){
                if preview.is_empty()||!preview.starts_with("https://"){preview=url.clone()}
                return Animated{url:format!("{scheme}://{host}{dir}{}.mp4",rawurlencode(&id)),preview,kind:"gifv",media_type:Some("video/mp4")};
            }
        }
    }
    let mut play=url.clone();let mut kind="image";let mut media_type=None;
    if path.ends_with(".gif")||lower.contains("tenor.com")||lower.contains("giphy.com")||lower.contains("klipy.com"){
        kind="gifv";media_type=Some("image/gif");
        if path.ends_with(".gif"){
            let sibling=re!(r"(?i)\.gif$").replace(&url,".mp4").into_owned();
            if sibling!=url&&lower.contains("klipy.com"){play=re!(r"\?.*$").replace(&sibling,"").into_owned();media_type=Some("video/mp4");if preview.is_empty(){preview=url.clone()}}
        }
    }
    Animated{preview:if preview.starts_with("https://"){preview}else{url.clone()},url:play,kind,media_type}
}
fn url_looks_like_media(url:&str)->bool{
    let url=trim(url);if url.is_empty()||!re!(r"(?i)^https?://").is_match(url){return false}
    re!(r"(?i)\.(gif|png|jpe?g|webp|mp4|webm)(\?|#|$)").is_match(url)
        ||re!(r"(?i)https?://(?:(?:media|static)\.)?(?:tenor\.com|giphy\.com|klipy\.com|cdn\.bsky\.app)/").is_match(url)
}

pub(super) struct BskyBuilder<'a>{
    db:&'a Client,owner:i64,cfg:Option<&'a crate::config::Config>,warm:Option<&'a crate::profile_enrichment::WarmContext>,
    flat:HashMap<String,Option<Value>>,profiles:HashMap<String,Option<Value>>,authors:HashMap<String,Option<Value>>,avatars:HashMap<String,String>,
}
impl<'a> BskyBuilder<'a>{
    pub(super) fn new(db:&'a Client,owner:i64,cfg:Option<&'a crate::config::Config>,warm:Option<&'a crate::profile_enrichment::WarmContext>)->Self{
        Self{db,owner,cfg,warm,flat:HashMap::new(),profiles:HashMap::new(),authors:HashMap::new(),avatars:HashMap::new()}
    }
    async fn redis_json(&self,key:&str)->Option<Value>{
        let cfg=self.cfg?;crate::profile_header::cache::read_json(cfg,key).await.filter(|v|v.is_object()||v.is_array())
    }
    /// ap_bsky_actor_profile_cache_get(did, owner): Redis first, then rows.
    async fn profile_cache(&mut self,did:&str)->Result<Option<Value>>{
        if let Some(hit)=self.profiles.get(did){return Ok(hit.clone())}
        let keys=vec![did.to_owned(),format!("https://bsky.app/profile/{did}"),format!("https://bsky.app/profile/{}",rawurlencode(did))];
        let mut unique:Vec<String>=Vec::new();for k in keys{if !k.is_empty()&&!unique.contains(&k){unique.push(k)}}
        use sha2::Digest;
        let redis_key=format!("vaak:profile:v1:bsky:{}{}",if self.owner>0{format!("{}:",self.owner)}else{"public:".into()},hex::encode(sha2::Sha256::digest(unique.join("\n").as_bytes())));
        let mut out=self.redis_json(&redis_key).await;
        if out.is_none(){
            let mut row=None;
            for key in &unique{if let Some(hit)=self.db.query_opt("SELECT to_jsonb(p) FROM bsky_actor_profiles p WHERE actor_ref=$1 LIMIT 1",&[key]).await?{row=Some(hit.get::<_,Value>(0));break}}
            if row.is_none(){row=self.db.query_opt("SELECT to_jsonb(p) FROM bsky_actor_profiles p WHERE did=$1 ORDER BY updated_at DESC LIMIT 1",&[&did]).await?.map(|r|r.get::<_,Value>(0));}
            if let Some(row)=row{if let Ok(profile)=serde_json::from_str::<Value>(s(&row,"profile_json")){if profile.is_object()||profile.is_array(){out=Some(json!({"profile":profile}))}}}
        }
        self.profiles.insert(did.into(),out.clone());Ok(out)
    }
    /// ap_bsky_enrich_author_view. Returns None when PHP would still queue a
    /// refresh for a thin author; that writer stays on PHP for now.
    async fn enrich(&mut self,mut author:Value)->Result<Value>{
        let did=trim(&scalar(&author,"did")?).to_owned();let mut handle=trim(&scalar(&author,"handle")?).to_owned();let mut display=trim(&scalar(&author,"displayName")?).to_owned();
        let mut avatar=avatar_public_url(author.get("avatar").unwrap_or(&Value::Null),&did)?.unwrap_or_default();
        author["avatar"]=json!(avatar);
        let mut thin=handle.is_empty()||handle.starts_with("did:");
        let needs=|thin:bool,display:&str,avatar:&str|thin||display.is_empty()||avatar.is_empty();
        if !needs(thin,&display,&avatar)||did.is_empty()||!did.starts_with("did:"){return Ok(author)}
        // 0) flat DID Redis written by the Rust actor warmer.
        if !self.flat.contains_key(&did){let value=self.redis_json(&format!("vaak:actor:v1:bsky:{did}")).await;self.flat.insert(did.clone(),value);}
        if let Some(flat)=self.flat.get(&did).cloned().flatten().filter(|f|f.as_object().is_some_and(|m|!m.is_empty())||f.as_array().is_some_and(|a|!a.is_empty())){
            let ch=trim(&scalar(&flat,"handle")?).to_owned();let cdn=trim(&scalar(&flat,"displayName")?).to_owned();let cav=trim(&scalar(&flat,"avatar")?).to_owned();
            if thin&&!ch.is_empty()&&!ch.starts_with("did:"){author["handle"]=json!(ch);handle=ch;thin=false;}
            if display.is_empty()&&!cdn.is_empty(){author["displayName"]=json!(cdn);display=cdn;}
            if avatar.is_empty()&&!cav.is_empty(){let norm=avatar_public_url(&json!(cav),s(&author,"did"))?.unwrap_or_default();if !norm.is_empty(){author["avatar"]=json!(norm);avatar=norm;}}
            if !needs(thin,&display,&avatar){return Ok(author)}
        }
        // 1) durable profile cache.
        if let Some(cached)=self.profile_cache(&did).await?{
            let profile=cached.get("profile").filter(|p|p.is_object()).cloned().unwrap_or(Value::Null);
            if profile.as_object().is_some_and(|m|!m.is_empty()){
                let ch=trim(&scalar(&profile,"handle")?).to_owned();let cdn=trim(&scalar(&profile,"displayName")?).to_owned();let cav=avatar_public_url(profile.get("avatar").unwrap_or(&Value::Null),&did)?.unwrap_or_default();
                if thin&&!ch.is_empty()&&!ch.starts_with("did:"){author["handle"]=json!(ch);handle=ch;thin=false;}
                if display.is_empty()&&!cdn.is_empty(){author["displayName"]=json!(cdn);display=cdn;}
                if avatar.is_empty()&&!cav.is_empty(){author["avatar"]=json!(cav);avatar=cav;}
            }
        }
        // 2) denormalized columns on a prior post by this DID.
        if needs(thin,&display,&avatar){
            if !self.authors.contains_key(&did){
                let row=self.db.query_opt("SELECT jsonb_build_object('author_handle',author_handle,'author_display',author_display,'author_avatar',author_avatar) FROM bsky_posts WHERE author_did=$1 AND author_handle IS NOT NULL AND author_handle<>'' AND author_handle NOT LIKE 'did:%' ORDER BY indexed_at DESC LIMIT 1",&[&did]).await?;
                self.authors.insert(did.clone(),row.map(|r|r.get::<_,Value>(0)));
            }
            if let Some(row)=self.authors.get(&did).cloned().flatten(){
                let ph=trim(&scalar(&row,"author_handle")?).to_owned();let pd=trim(&scalar(&row,"author_display")?).to_owned();let pa=avatar_public_url(row.get("author_avatar").unwrap_or(&Value::Null),&did)?.unwrap_or_default();
                if thin&&!ph.is_empty(){author["handle"]=json!(ph);handle=ph;thin=false;}
                if display.is_empty()&&!pd.is_empty(){author["displayName"]=json!(pd);display=pd;}
                if avatar.is_empty()&&!pa.is_empty(){author["avatar"]=json!(pa);avatar=pa;}
            }
            if avatar.is_empty(){
                if !self.avatars.contains_key(&did){
                    let row=self.db.query_opt("SELECT author_avatar FROM bsky_posts WHERE author_did=$1 AND author_avatar IS NOT NULL AND author_avatar<>'' ORDER BY indexed_at DESC LIMIT 1",&[&did]).await?;
                    self.avatars.insert(did.clone(),row.and_then(|r|r.get::<_,Option<String>>(0)).map(|v|trim(&v).to_owned()).unwrap_or_default());
                }
                let pa=avatar_public_url(&json!(self.avatars[&did]),&did)?.unwrap_or_default();
                if !pa.is_empty(){author["avatar"]=json!(pa);avatar=pa;}
            }
        }
        let _=&handle;
        if thin||avatar.is_empty(){bail!("PHP fallback: thin Bluesky author queues a profile refresh")}
        Ok(author)
    }
    async fn media(&mut self,post:&Value,status_id:&str)->Result<Vec<Value>>{
        let embed=if post.get("embed").is_some_and(Value::is_object){&post["embed"]}else if post["record"].get("embed").is_some_and(Value::is_object){&post["record"]["embed"]}else{return Ok(vec![])};
        let kind=scalar(embed,"$type")?.to_ascii_lowercase();
        let mut sources=vec![embed];if kind.contains("recordwithmedia")&&embed.get("media").is_some_and(Value::is_object){sources.push(&embed["media"]);}
        let mut out:Vec<Value>=Vec::new();
        for source in sources{
            if !source.is_object(){continue}
            let list=if source.get("items").and_then(Value::as_array).is_some_and(|a|!a.is_empty()){source["items"].as_array().cloned().unwrap_or_default()}else if let Some(a)=source.get("images").and_then(Value::as_array){a.clone()}else if source.get("images").is_some_and(Value::is_object){bail!("PHP fallback: Bluesky keyed image map")}else{vec![]};
            for image in &list{
                if !image.is_object(){continue}
                let pick=|keys:&[&str]|->Result<String>{for k in keys{if image.get(*k).is_some_and(|v|!v.is_null()){return scalar(image,k)}}Ok(String::new())};
                let url=trim(&pick(&["fullsize","url","thumb","thumbnail"])?).to_owned();
                if !url.starts_with("https://"){continue}
                let display=crate::profile_materialize::post_media_resolve(self.db,&url,self.warm).await?;
                let preview_raw=trim(&{let p=pick(&["thumb","thumbnail"])?;if image.get("thumb").is_some_and(|v|!v.is_null())||image.get("thumbnail").is_some_and(|v|!v.is_null()){p}else{url.clone()}}).to_owned();
                let preview=crate::profile_materialize::post_media_resolve(self.db,&preview_raw,self.warm).await?;
                let anim=animated(&display,&preview);
                let description=if image.get("alt").is_some_and(|v|!v.is_null()){scalar(image,"alt")?}else if anim.kind=="gifv"{"GIF".into()}else{String::new()};
                let n=out.len()+1;
                out.push(json!({"id":format!("{status_id}#media-{n}"),"type":anim.kind,"url":anim.url,"preview_url":anim.preview,"remote_url":url,"text_url":url,"description":description,"meta":null,"blurhash":null,"mediaType":anim.media_type}));
                if out.len()>=4{return Ok(out)}
            }
            let video=if source.get("video").is_some_and(Value::is_object){&source["video"]}else{source};
            let playlist=trim(&if video.get("playlist").is_some_and(|v|!v.is_null()){scalar(video,"playlist")?}else{scalar(video,"url")?}).to_owned();
            let src_kind=if source.get("$type").is_some_and(|v|!v.is_null()){scalar(source,"$type")?.to_ascii_lowercase()}else{kind.clone()};
            if !playlist.is_empty()&&playlist.starts_with("https://")&&(src_kind.contains("video")||source.get("playlist").is_some_and(|v|!v.is_null())||source.get("video").is_some_and(|v|!v.is_null())){
                let preview=trim(&if video.get("thumbnail").is_some_and(|v|!v.is_null()){scalar(video,"thumbnail")?}else{scalar(video,"thumb")?}).to_owned();
                let n=out.len()+1;
                out.push(json!({"id":format!("{status_id}#media-{n}"),"type":"video","url":playlist,"preview_url":if preview.starts_with("https://"){json!(preview)}else{Value::Null},"remote_url":playlist,"text_url":playlist,"description":"","meta":null,"blurhash":null}));
                if out.len()>=4{return Ok(out)}
            }
            if out.is_empty()||src_kind.contains("external")||source.get("external").is_some_and(|v|!v.is_null()){
                if let Some(ext)=source.get("external").filter(|v|v.is_object()){
                    let uri=trim(&scalar(ext,"uri")?).to_owned();
                    if !uri.is_empty()&&uri.starts_with("https://")&&url_looks_like_media(&uri){
                        let thumb=if ext.get("thumb").is_some_and(|v|v.is_object()||v.is_array()){String::new()}else{trim(&scalar(ext,"thumb")?).to_owned()};
                        let anim=animated(&uri,&thumb);
                        let title=if ext.get("title").is_some_and(|v|!v.is_null()){scalar(ext,"title")?}else if ext.get("description").is_some_and(|v|!v.is_null()){scalar(ext,"description")?}else{"GIF".into()};
                        let title=trim(&title);let description=if title.is_empty()||title=="0"{"GIF"}else{title};
                        let n=out.len()+1;
                        out.push(json!({"id":format!("{status_id}#media-{n}"),"type":if anim.kind=="image"{"gifv"}else{anim.kind},"url":anim.url,"preview_url":anim.preview,"remote_url":uri,"text_url":uri,"description":description,"meta":null,"blurhash":null,"mediaType":anim.media_type.unwrap_or("image/gif")}));
                        if out.len()>=4{return Ok(out)}
                    }
                }
            }
        }
        Ok(out)
    }
    /// One ranked Bluesky hit; `row_raw` is the decoded bsky_posts.raw_json of
    /// the same row, which ap_normalize_status re-reads for labels.
    pub(super) async fn status(&mut self,post:&Value,row_raw:&Value)->Result<Option<Value>>{
        let mut post=post.clone();
        let uri=trim(&scalar(&post,"uri")?).to_owned();
        let author=if post.get("author").is_some_and(Value::is_object){post["author"].clone()}else{json!({})};
        let author=if author.as_object().is_some_and(|m|!m.is_empty()){let a=self.enrich(author).await?;post["author"]=a.clone();a}else{author};
        let did=trim(&scalar(&author,"did")?).to_owned();
        if uri.is_empty()||did.is_empty(){return Ok(None)}
        let handle=trim(&scalar(&author,"handle")?).to_owned();
        let url=https_url_from_at_uri(&uri,&handle)?;
        let text=trim(&first_truthy(scalar(&post["record"],"text")?,scalar(&post,"text")?)).to_owned();
        let media=self.media(&post,&uri).await?;
        let mut display=trim(&scalar(&author,"displayName")?).to_owned();if display.is_empty(){display=if handle.is_empty(){did.clone()}else{handle.clone()};}
        let created=first_truthy(scalar(&post["record"],"createdAt")?,scalar(&post,"indexedAt")?);
        if created.is_empty()||created=="0"{bail!("PHP fallback: Bluesky Search hit needs request-clock created_at")}
        let name=if handle.is_empty(){did.clone()}else{handle.clone()};
        let avatar=scalar(&author,"avatar")?;
        let mut status=json!({"id":uri,"url":url,"uri":uri,"content":nl2br(&esc(&text)),"created_at":created,"visibility":"public","language":"en",
            "account":{"id":did,"url":actor_profile_url(&name),"username":name,"acct":name,"display_name":display,"avatar":avatar,"avatar_static":avatar,"uri":actor_profile_url(&did)},
            "reblogs_count":crate::library::card::integer(&post,"repostCount"),"favourites_count":crate::library::card::integer(&post,"likeCount"),"replies_count":crate::library::card::integer(&post,"replyCount"),
            "media_attachments":media,"reblog":null,"quote":null,"source":"bluesky","author_did":did,"trend_actors":[did],"bsky_post":post,"bsky_cid":scalar(&post,"cid")?,
            "sensitive":false,"spoiler_text":"","in_reply_to_id":null,"in_reply_to_account_id":null});
        if let Some(parent)=post["record"].get("reply").and_then(|r|r.get("parent")).filter(|p|p.is_object()){
            let parent_uri=trim(&scalar(parent,"uri")?).to_owned();
            if !parent_uri.is_empty()&&parent_uri!="0"&&parent_uri.starts_with("at://"){
                status["in_reply_to_id"]=json!(parent_uri);
                status["vaak_in_reply_to_url"]=json!(match https_url_from_at_uri(&parent_uri,""){Ok(u)=>u,Err(_)=>bail!("PHP fallback: Bluesky reply parent is not a post URI")});
            }
        }
        self.normalize(&mut status,row_raw).await?;
        Ok(Some(status))
    }
    /// ap_normalize_status for this escaped-text shape.
    async fn normalize(&mut self,status:&mut Value,row_raw:&Value)->Result<()>{
        let content=s(status,"content").to_owned();
        let plain=trim(&crate::home_hydrate_ranked::html_entity_decode(&crate::search_collect::strip_tags(&content)?)).to_owned();
        // attach_ask: a stored Ask answer, or Wafrn's compact "asked" text.
        let uri=s(status,"uri").trim_end_matches('/').to_owned();
        if self.db.query_opt("SELECT 1 FROM ap_asks WHERE (answer_note_id=$1 OR answer_note_id=$2) AND COALESCE(question,'')<>'' LIMIT 1",&[&uri,&format!("{uri}/")]).await.ok().flatten().is_some(){bail!("PHP fallback: Bluesky Search hit carries an Ask")}
        if re!(r"(?is)\sasked").is_match(&plain){bail!("PHP fallback: Bluesky Search text may parse as an Ask")}
        // bsky sensitivity: labels from the cached row, else the nested post.
        let source=if re!(r"^at://[^/]+/app\.bsky\.feed\.post/[A-Za-z0-9]+").is_match(trim(&uri))&&row_raw.is_object(){
            self.require_item_author(row_raw).await?;row_raw.clone()
        }else{status["bsky_post"].clone()};
        if source.is_object()&&crate::library::bsky::sensitive(&source){
            status["sensitive"]=json!(true);if trim(s(status,"spoiler_text")).is_empty(){status["spoiler_text"]=json!("Sensitive content");}status["vaak_bsky_sensitive"]=json!(true);
        }
        // scrub quote pollution: raw QT / AS2 bodies are rewritten by PHP.
        if plain.contains("↪ QT")||plain.contains("↪QT")||re!(r"(?:↪|➡|→)\s*QT").is_match(&plain)||plain.contains("RE: https://")
            ||plain.contains("QuoteAuthorization")||plain.contains("interactionPolicy")||plain.starts_with('{')||plain.starts_with('['){bail!("PHP fallback: Bluesky Search body needs quote scrubbing")}
        Ok(())
    }
    /// ap_bsky_post_item_by_uri enriches the cached author too; a thin one
    /// would queue the same refresh writer.
    async fn require_item_author(&mut self,raw:&Value)->Result<()>{
        let Some(author)=raw.get("author").filter(|a|a.is_object()) else{return Ok(())};
        let uri=s(raw,"uri");let row=self.db.query_opt("SELECT jsonb_build_object('h',author_handle,'d',author_display,'a',author_avatar,'did',author_did) FROM bsky_posts WHERE bsky_uri=$1",&[&uri]).await?.map(|r|r.get::<_,Value>(0)).unwrap_or(Value::Null);
        let mut author=author.clone();
        for(col,key)in[("h","handle"),("d","displayName"),("a","avatar")]{let v=trim(&scalar(&row,col)?).to_owned();if !v.is_empty()&&trim(&scalar(&author,key)?).is_empty(){author[key]=json!(v);}}
        if trim(&scalar(&author,"did")?).is_empty()&&!trim(&scalar(&row,"did")?).is_empty(){author["did"]=row["did"].clone();}
        if author.as_object().is_some_and(|m|!m.is_empty()){self.enrich(author).await.context("PHP fallback: cached Bluesky item author is thin")?;}
        Ok(())
    }
}

#[cfg(test)]
mod tests{
    use super::*;
    #[test]fn php_string_helpers(){
        assert_eq!(nl2br("a\nb\r\nc\n\rd\re"),"a<br>\nb<br>\r\nc<br>\n\rd<br>\re");
        assert_eq!(nl2br("a\n\nb"),"a<br>\n<br>\nb");
        assert_eq!(actor_profile_url("@alice.bsky.social"),"https://bsky.app/profile/alice.bsky.social");
        assert_eq!(actor_profile_url("did:plc:x"),"https://bsky.app/profile/did:plc:x");
        assert_eq!(https_url_from_at_uri("at://did:plc:a/app.bsky.feed.post/3k1","a.b").unwrap(),"https://bsky.app/profile/a.b/post/3k1");
        assert_eq!(avatar_public_url(&json!("bafkreiabcdefghijklmnopqrstuv"),"did:plc:a").unwrap().unwrap(),"https://cdn.bsky.app/img/avatar/plain/did:plc:a/bafkreiabcdefghijklmnopqrstuv@jpeg");
        assert_eq!(avatar_public_url(&json!({"ref":{"$link":"bafkreiabcdefghijklmnopqrstuv"},"mimeType":"image/png"}),"did:plc:a").unwrap().unwrap(),"https://cdn.bsky.app/img/avatar/plain/did:plc:a/bafkreiabcdefghijklmnopqrstuv@png");
        let a=animated("https://media.tenor.com/x/y.gif","");assert_eq!((a.kind,a.url.as_str(),a.preview.as_str()),("gifv","https://media.tenor.com/x/y.gif","https://media.tenor.com/x/y.gif"));
    }
}
