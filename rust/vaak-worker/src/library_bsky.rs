//! Bluesky Library protocol chrome. No permission facts come from a browser.
//! The shared status painter owns normalized content; legacy content explicitly
//! rejects unresolved branches until the canonical PHP differential covers them.
use anyhow::{bail, Result};
use serde_json::Value;
use super::actions::{esc, query, compact_count, Counts, FediActionContext};
use super::card::CardFacts;

#[derive(Clone, Default)]
pub(crate) struct BskyFacts {
    pub enabled: bool,
    pub bookmark_status_id: String,
    pub bookmark_object_id: String,
    pub self_did: String,
    pub fedi_id: String,
    pub twin_counts: Counts,
    pub mapped_own:Option<super::own::OwnFacts>,
    pub like_record: String,
    pub repost_record: String,
    pub bookmarked: bool,
    pub confirmed_like: Option<bool>,
    pub confirmed_bookmark: Option<bool>,
}
/// Owner/session facts for the canonical cache-only bookmark/twin lookup.
/// Missing tables or unresolved engagement transport return PHP fallback errors.
pub(crate) async fn resolve(db:&tokio_postgres::Client,owner:i64,status:&Value,enabled:bool)->Result<Option<BskyFacts>>{
    resolve_with_bookmark_state(db,owner,status,enabled,None).await
}
/// Request state comes only from the authenticated PHP request provenance map,
/// never from the cached PostView or serialized status fields.
pub(crate) async fn resolve_with_bookmark_state(db:&tokio_postgres::Client,owner:i64,status:&Value,enabled:bool,request_bookmark:Option<bool>)->Result<Option<BskyFacts>>{
    use anyhow::Context;
    use sha2::{Digest,Sha256};
    if owner<1{bail!("invalid Bluesky facts owner");}
    let post=if status["post"].is_object(){&status["post"]}else{post_view(status)};
    if !post.is_object(){return Ok(None)}
    let uri=s(post,"uri").trim();let web=https_uri(uri)?;
    let self_did=db.query_opt("SELECT did FROM bsky_sessions WHERE owner_user_id::bigint=$1 LIMIT 1",&[&owner]).await?.map(|r|r.get::<_,String>(0)).unwrap_or_default();
    let crosspost=db.query_opt("SELECT note_id FROM bsky_crossposts WHERE bsky_uri=$1 LIMIT 1",&[&uri]).await?.map(|r|r.get::<_,String>(0));
    let link=db.query_opt("SELECT to_jsonb(l) FROM bsky_post_links l WHERE bsky_uri=$1 LIMIT 1",&[&uri]).await?.map(|r|r.get::<_,Value>(0));
    let linked=link.as_ref().map(|l|s(l,"fediverse_id").trim_end_matches('/')).unwrap_or("");
    let linked=if linked.is_empty(){link.as_ref().map(|l|s(l,"ap_object_id").trim_end_matches('/')).unwrap_or("")}else{linked};
    let mapped=crosspost.as_deref().filter(|v|!v.is_empty()).or_else(||if linked.contains("/notes/"){Some(linked)}else{None});
    let mut bookmark_status_id=format!("bsky:{:x}",Sha256::digest(uri.as_bytes()))[..37].to_owned();let mut bookmark_object_id=web;
    if let Some(note)=mapped{
        if let Some(row)=db.query_opt("SELECT local_id::bigint,published FROM masto_statuses WHERE note_id=$1 LIMIT 1",&[&note]).await?{
            let local_id:i64=row.get(0);let published:String=row.get(1);
            if local_id!=0{bookmark_status_id=crate::notif::snowflake_id(Some(&published),local_id,0);bookmark_object_id=note.to_owned();}
        }
    }
    let record_fedi=s(&post["record"],"fediverseId").trim();
    let fedi_id=if !record_fedi.is_empty(){record_fedi.to_owned()}else{link.as_ref().map(|l|s(l,"fediverse_id").to_owned()).unwrap_or_default()};
    let twin_counts=if fedi_id.contains("/notes/"){super::facts::local_counts(db,&fedi_id,&fedi_id).await.context("PHP fallback: Bluesky twin engagement facts")?}else{Counts::default()};
    let keys=vec![uri.to_owned(),https_uri(uri)?,bookmark_object_id.clone(),format!("{}/",bookmark_object_id.trim_end_matches('/'))];
    let like_row=db.query_opt("SELECT like_activity_id FROM masto_favourites WHERE owner_user_id::bigint=$1 AND (status_id=$2 OR object_id=ANY($3)) ORDER BY id DESC LIMIT 1",&[&owner,&bookmark_status_id,&keys]).await?;
    let cached=db.query_opt("SELECT post_json FROM bsky_favourite_cache WHERE owner_user_id::bigint=$1 AND favourite_uri=$2 LIMIT 1",&[&owner,&uri]).await?;
    let mut like_record=like_row.and_then(|r|r.get::<_,Option<String>>(0)).unwrap_or_default();
    if like_record.is_empty(){if let Some(row)=cached{let raw:String=row.get(0);let cached:Value=serde_json::from_str(&raw)?;let view=cached.get("post").unwrap_or(&cached);if s(view,"uri")==uri{like_record=s(&view["viewer"],"like").to_owned();}}}
    let mut repost_record=String::new();let mut like_undone=false;let mut repost_undone=false;
    let mut seen=std::collections::HashSet::new();
    for row in db.query("SELECT action_kind,confirmed_state::bigint,receipt_json FROM ap_action_queue WHERE owner_user_id::bigint=$1 AND platform='bsky' AND action_kind IN ('like','boost') AND target_key=ANY($2) AND status='succeeded' ORDER BY updated_at DESC,id DESC",&[&owner,&keys]).await?{
        let kind:String=row.get(0);let on:i64=row.get(1);let receipt:String=row.get::<_,Option<String>>(2).unwrap_or_default();let receipt:Value=serde_json::from_str(&receipt).unwrap_or(Value::Null);
        if !seen.insert(kind.clone()){continue;}
        if kind=="like"{like_undone=on==0;like_record=if on==0{String::new()}else{s(&receipt,"record_uri").into()};}else{repost_undone=on==0;repost_record=if on==0{String::new()}else{s(&receipt,"record_uri").into()};}
    }
    like_record=bound_record(&like_record,&self_did,"like").unwrap_or_default();repost_record=bound_record(&repost_record,&self_did,"repost").unwrap_or_default();
    for (kind,known,undone) in [("like",&like_record,like_undone),("repost",&repost_record,repost_undone)]{
        let raw=s(&post["viewer"],kind);
        if bound_record(raw,&self_did,kind).is_some()&&known.is_empty()&&!undone{bail!("PHP fallback: same-owner Bluesky {kind} needs current session/cache record evidence");}
    }
    let row=db.query_one("SELECT EXISTS(SELECT 1 FROM masto_bookmarks WHERE owner_user_id::bigint=$1 AND (status_id=$2 OR object_id=ANY($3))), EXISTS(SELECT 1 FROM bsky_bookmark_cache WHERE owner_user_id::bigint=$1 AND bookmark_uri=$4)",&[&owner,&bookmark_status_id,&keys,&uri]).await?;
    let ap_bookmarked:bool=row.get(0);let mut native_bookmarked:bool=row.get(1);
    let mut confirmed_bookmark=None;
    if let Some(row)=db.query_opt("SELECT confirmed_state::bigint FROM ap_action_queue WHERE owner_user_id::bigint=$1 AND platform='bsky' AND action_kind='bookmark' AND target_key=ANY($2) AND status='succeeded' ORDER BY updated_at DESC,id DESC LIMIT 1",&[&owner,&keys]).await?{native_bookmarked=row.get::<_,i64>(0)!=0;confirmed_bookmark=Some(native_bookmarked);}
    // Live authenticated AppView state outranks historical queue/cache state.
    // External true without durable proof must retain the entire PHP batch.
    if request_bookmark==Some(true)&&!native_bookmarked {bail!("PHP fallback: authenticated external Bluesky bookmark lacks durable current evidence");}
    if request_bookmark==Some(false){native_bookmarked=false;confirmed_bookmark=Some(false);}
    let bookmarked=ap_bookmarked||native_bookmarked;
    let mut mapped_own = None;
    if s(&post["author"], "did") == self_did
        && !self_did.is_empty()
        && fedi_id.contains("/notes/")
    {
        // A connected AT identity does not grant ownership of a mapped AP note.
        // Resolve that second identity independently from the current owner row.
        let actor = db.query_opt(
            "SELECT actor_id FROM ap_users WHERE id::bigint=$1 LIMIT 1", &[&owner],
        ).await?.map(|row| row.get::<_, String>(0)).unwrap_or_default();
        let note = fedi_id.trim_end_matches('/');
        if actor.is_empty() || !note.starts_with(&format!("{}/notes/", actor.trim_end_matches('/'))) {
            bail!("PHP fallback: mapped own Bluesky note requires current AP ownership");
        }
        let row = db.query_opt(
            "SELECT to_jsonb(s), EXISTS(SELECT 1 FROM masto_pins p WHERE p.status_local_id=s.local_id AND p.owner_user_id::bigint=$2) FROM masto_statuses s WHERE rtrim(s.note_id,'/')=$1 LIMIT 1",
            &[&note, &owner],
        ).await?.context("PHP fallback: mapped own note missing")?;
        let stored: Value = row.get(0);
        let id = n(&stored, "local_id");
        mapped_own = Some(super::own::OwnFacts {
            local_id: id,
            status_id: id.to_string(),
            plain: s(&stored, "content_text").into(),
            spoiler: s(&stored, "spoiler_text").into(),
            sensitive: yes(&stored, "sensitive")
                || !super::card::php_trim(s(&stored, "spoiler_text")).is_empty(),
            visibility: s(&stored, "visibility").into(),
            pinned: row.get(1),
            counts: twin_counts.clone(),
        });
    }

    Ok(Some(BskyFacts{enabled,bookmark_status_id,bookmark_object_id,self_did,fedi_id,twin_counts,mapped_own,like_record,repost_record,bookmarked,confirmed_like:if like_undone{Some(false)}else{None},confirmed_bookmark}))
}
fn bound_record(record:&str,did:&str,kind:&str)->Option<String>{
    if did.is_empty(){return None}let prefix=format!("at://{did}/app.bsky.feed.{kind}/");let key=record.strip_prefix(&prefix)?;
    if key.is_empty()||key.contains('/')||key.chars().any(char::is_whitespace){None}else{Some(record.into())}
}
fn s<'a>(v: &'a Value, k: &str) -> &'a str { v.get(k).and_then(Value::as_str).unwrap_or("") }
fn post_view(status:&Value)->&Value {status.get("bsky_post_view").or_else(||status.get("bsky_post").map(|p|p.get("post").unwrap_or(p))).unwrap_or(&Value::Null)}
fn n(v: &Value, k: &str) -> i64 { super::card::integer(v,k).max(0) }
fn yes(v:&Value,k:&str)->bool { super::card::boolean(v,k) }
fn count(n:i64)->String {if n>0 {format!("<span class=\"action-count\" aria-hidden=\"true\">{}</span>",compact_count(n))}else{String::new()}}
fn label_count(n:i64)->String{if n>0{format!(" ({})",compact_count(n))}else{String::new()}}
pub(crate) fn linked_body(post:&Value,view:&str)->Result<String>{
    let text=s(&post["record"],"text");
    let mut fixed=String::new();let mut from=0;
    for found in regex::Regex::new(r"www\.[^\s<]+").unwrap().find_iter(text){
        let preceding=text[..found.start()].chars().next_back();
        if preceding.is_some_and(|c|c.is_alphanumeric()||"_./:@".contains(c)){continue;}
        fixed.push_str(&text[from..found.start()]);fixed.push_str("https://");fixed.push_str(found.as_str());from=found.end();
    }
    fixed.push_str(&text[from..]);
    let html=super::body::linked_text(&fixed,view)?;
    Ok(super::body::facets(&html,&serde_json::json!({"bsky_post":post})))
}
fn legacy_media(post:&Value)->Result<(Vec<Value>,Option<Value>)>{
    let embed=&post["embed"];if !embed.is_object(){return Ok((Vec::new(),None));}
    let kind=s(embed,"$type");let mut media=Vec::new();
    let images=if kind.contains("images"){&embed["images"]}else if kind.contains("gallery"){&embed["items"]}else if kind.contains("recordWithMedia"){if embed["media"]["images"].is_array(){&embed["media"]["images"]}else{&embed["media"]["items"]}}else{&Value::Null};
    for image in images.as_array().into_iter().flatten(){let url=image.get("thumb").or_else(||image.get("thumbnail")).or_else(||image.get("fullsize")).and_then(Value::as_str).unwrap_or("");if url.starts_with("https://"){media.push(serde_json::json!({"url":url}));}}
    let video=if kind.contains("video"){embed}else if kind.contains("recordWithMedia")&&s(&embed["media"],"$type").contains("video"){&embed["media"]}else{&Value::Null};
    let url=video.get("playlist").or_else(||video.get("url")).and_then(Value::as_str).unwrap_or("");if url.starts_with("https://"){media.push(serde_json::json!({"url":url,"mediaType":"application/x-mpegURL","preview_url":s(video,"thumbnail")}));}
    let external=if kind.contains("external")||embed["external"].is_object(){&embed["external"]}else if kind.contains("recordWithMedia"){&embed["media"]["external"]}else{&Value::Null};
    let uri=s(external,"uri").trim();let card=if uri.starts_with("https://")||uri.starts_with("http://"){
        let host=reqwest::Url::parse(uri).ok().and_then(|u|u.host_str().map(str::to_owned)).unwrap_or_default().to_lowercase();let title=super::card::php_trim(s(external,"title"));let image=s(external,"thumb");
        Some(serde_json::json!({"url":uri,"title":if title.is_empty(){host.as_str()}else{title},"description":super::card::php_trim(s(external,"description")).chars().take(280).collect::<String>(),"image":if image.starts_with("http://")||image.starts_with("https://"){image}else{""},"provider_name":host,"status":"ok"}))
    }else{None};
    if media.is_empty()&&card.is_none()&&!kind.is_empty()&&!kind.contains("record"){bail!("unknown legacy embed requires PHP fallback");}
    Ok((media,card))
}
fn legacy_quote(post:&Value,facts:&CardFacts)->Result<Option<String>>{
    let embed=&post["embed"];let kind=s(embed,"$type");
    let raw=if kind.contains("recordWithMedia"){embed["record"].get("record").unwrap_or(&embed["record"])}else{&embed["record"]};
    let record=if raw["record"].is_object()&&(raw["record"]["author"].is_object()||raw["record"]["value"].is_object()||raw["record"]["text"].is_string()){&raw["record"]}else{raw};
    if !record.is_object(){return Ok(None)}let kind=s(record,"$type");
    if ["generatorView","listView","starterPack","labelerView"].iter().any(|k|kind.contains(k)){return Ok(None)}
    if kind.contains("Blocked"){
        // Blocked previews have no tombstone write. Preserve the canonical
        // unavailable quote chrome while the outer policy remains authoritative.
        let mut unavailable=post.clone();
        unavailable["embed"]=serde_json::json!({"$type":"app.bsky.embed.record#view","record":{"author":{"displayName":"Unavailable"},"value":{"text":"Quoted post is blocked"}}});
        return legacy_quote(&unavailable,facts);
    }
    if ["NotFound","Detached"].iter().any(|k|kind.contains(k)){bail!("quote tombstone effect requires PHP fallback");}
    let value=record.get("value").or_else(||record.get("record")).unwrap_or(&Value::Null);if !s(value,"$type").is_empty()&&!s(value,"$type").contains("feed.post"){return Ok(None)}
    let author=&record["author"];let handle=s(author,"handle");let mut text=super::card::php_trim(value.get("text").or_else(||record.get("text")).and_then(Value::as_str).unwrap_or("")).to_owned();
    let mut media=Vec::new();
    let embeds=record["embeds"].as_array().cloned().unwrap_or_else(||value.get("embed").filter(|v|v.is_object()).cloned().into_iter().collect());
    for embed in embeds{
        let kind=s(&embed,"$type");let images=if embed["items"].as_array().is_some_and(|a|!a.is_empty()){&embed["items"]}else{&embed["images"]};
        for image in images.as_array().into_iter().flatten(){let url=image.get("fullsize").or_else(||image.get("thumb")).or_else(||image.get("url")).or_else(||image.get("thumbnail")).and_then(Value::as_str).unwrap_or("");if url.starts_with("https://")&&media.len()<4{media.push(serde_json::json!({"url":url,"mediaType":"image/jpeg","preview_url":s(image,"thumb")}));}}
        let (mut video,card)=legacy_media(&serde_json::json!({"embed":embed}))?;video.retain(|v|s(v,"mediaType")=="application/x-mpegURL");for item in video{if media.len()<4{media.push(item)}}
        if text.is_empty(){text=if kind.contains("images")||kind.contains("gallery"){"📷 Image".into()}else if kind.contains("video"){"🎬 Video".into()}else if let Some(card)=card{if s(&card,"title").is_empty(){s(&card,"url").into()}else{s(&card,"title").into()}}else{String::new()};}
    }
    if text.is_empty()&&!media.is_empty(){text="📷 Image".into()}
    if text.is_empty()&&handle.is_empty()&&media.is_empty(){return Ok(None)}
    let uri=s(record,"uri");let url=if uri.is_empty(){"https://bsky.app/".into()}else if handle.is_empty(){https_uri(uri)?}else{format!("https://bsky.app/profile/{handle}/post/{}",uri.rsplit('/').next().unwrap_or(""))};
    let href=format!("?view=status&object={}&from=bluesky",query(&url));let open=url!="https://bsky.app/";
    let mut html=format!("<div class=\"quote-block{}\"{}><span class=\"qt-label\">Quoted</span>",if open{" quote-block--openable"}else{""},if open{format!(" data-href=\"{}\" role=\"link\" tabindex=\"0\" aria-label=\"Open quoted post\"",esc(&href))}else{String::new()});
    let display=if super::card::php_trim(s(author,"displayName")).is_empty(){"Bluesky user"}else{super::card::php_trim(s(author,"displayName"))};let acct=if handle.is_empty(){display.to_owned()}else{format!("@{handle}")};
    let acct=if acct.starts_with('@')||acct.starts_with("http"){acct}else{format!("@{acct}")};
    html.push_str(&format!("<div class=\"meta\" style=\"margin-top:.3rem\">{}</div>",esc(&acct)));
    if !text.is_empty(){html.push_str(&format!("<div style=\"margin-top:.25rem;white-space:pre-wrap\">{}</div>",linked_body(&serde_json::json!({"record":{"text":text}}),"bluesky")?));}
    if !media.is_empty(){html.push_str(&format!("<div class=\"quote-media\" style=\"margin-top:.45rem\">{}</div>",super::card::media_row(&media,facts.lazy_media)));}
    else if regex::Regex::new(r"https?://|www\.").unwrap().is_match(&text){bail!("legacy quote OG budget requires PHP fallback");}
    if open{html.push_str(&format!("<div class=\"meta\" style=\"margin-top:.35rem\"><a href=\"{}\">Open quoted</a> · <a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">Open original</a></div>",esc(&href),esc(&url)));}
    html.push_str("</div>");Ok(Some(html))
}
pub(crate) fn sensitive(post:&Value)->bool{
    let record=&post["record"];let record_labels=record.get("labels").filter(|v|!v.is_null()).or_else(||record.get("selfLabels")).unwrap_or(&Value::Null);
    [&post["labels"],record_labels].into_iter().any(|labels|{
        let labels=labels.get("values").filter(|v|v.is_array()).unwrap_or(labels);
        labels.as_array().into_iter().flatten().any(|label|{
            let val=label.as_str().unwrap_or(s(label,"val")).trim().to_ascii_lowercase();
            matches!(val.as_str(),"porn"|"sexual"|"nudity"|"graphic-media"|"sexual-cartoon"|"sexual-figurative"|"nsfw"|"self-harm"|"sensitive")
        })
    })
}
pub(crate) fn https_uri(uri:&str)->Result<String>{
    let tail=uri.strip_prefix("at://").ok_or_else(||anyhow::anyhow!("Bluesky AT identity required"))?;
    let (did,key)=tail.split_once("/app.bsky.feed.post/").ok_or_else(||anyhow::anyhow!("Bluesky post collection required"))?;
    if did.is_empty()||key.is_empty()||key.contains('/') {bail!("invalid Bluesky AT identity");}
    Ok(format!("https://bsky.app/profile/{did}/post/{key}"))
}
fn button(kind:&str,post:&Value,_facts:&CardFacts,b:&BskyFacts,object:&str,view:&str,on:bool,number:i64,legacy:bool,own:bool)->String{
    let uri=s(post,"uri");let cid=s(post,"cid");let viewer=&post["viewer"];
    let record=if kind=="like"{b.like_record.as_str()}else{b.repost_record.as_str()};
    let label=match kind {"like"=>if on{"Unlike"}else if legacy{"Like"}else{"Like on Bluesky"},"repost"=>if on{"Undo boost"}else{"Boost"},_=>if on{"Bookmark folders"}else{"Bookmark"}};
    let data=if kind=="bookmark"{format!(" data-status-id=\"{}\" data-object-id=\"{}\"",esc(&b.bookmark_status_id),esc(&b.bookmark_object_id))}else{format!(" data-record-uri=\"{}\"",esc(record))};
    let eng=if own{"data-own-eng"}else{"data-eng"};
    let extra=if kind=="bookmark"{format!(" data-bm-picker=\"{}\"{}",u8::from(on),if own{" data-own-eng=\"1\""}else{""})}else{format!(" {eng}=\"1\" data-eng-count=\"{number}\"")};
    let icon=match kind{"like"=>"ph-heart","repost"=>"ph-repeat",_=>"ph-bookmark-simple"};
    format!("<button type=\"button\" class=\"icon-btn bsky-action{}{}\" data-bsky-action=\"{kind}\" data-uri=\"{}\" data-cid=\"{}\"{data} data-object-ref=\"{}\" data-return-view=\"{}\"{extra} title=\"{label}\" aria-label=\"{label}{}\" aria-pressed=\"{}\"><i class=\"ph{} {icon}\" aria-hidden=\"true\"></i>{}</button>",if on{" on"}else{""},if number>0&&kind!="bookmark"{" has-count"}else{""},esc(uri),esc(cid),esc(object),esc(view),if kind=="bookmark"{String::new()}else{label_count(number)},if on{"true"}else{"false"},if on&&kind!="repost"{"-fill"}else{""},if kind=="bookmark"{String::new()}else{count(number)})
}
fn quote(target:&str,view:&str,number:i64,legacy:bool,own:bool)->String{
    format!("<a class=\"icon-btn{}\" href=\"?view={}&amp;compose=1&amp;quote_object={}{}\" {}=\"1\" data-eng-count=\"{number}\" title=\"Quote\" aria-label=\"Quote{}\"><i class=\"ph ph-quotes\" aria-hidden=\"true\"></i>{}</a>",if number>0{" has-count"}else{""},esc(view),query(target),if legacy{"&amp;feed=following"}else{""},if own{"data-own-eng"}else{"data-eng"},label_count(number),count(number))
}
pub(crate) fn normalized_attrs(status:&Value)->String{
    let uri=s(status,"uri");let post=post_view(status);let did=s(&post["author"],"did");
    format!(" data-bsky-uri=\"{}\" data-bsky-cid=\"{}\" data-rank-platform=\"bsky\" data-rank-key=\"{}\" data-rank-actor=\"{}\"",esc(uri),esc(s(post,"cid")),esc(uri),esc(did))
}
/// Complete action subtree for the enabled normalized Bluesky branch.
pub(crate) fn normalized_actions(status:&Value,facts:&CardFacts,show_open:bool)->Result<String>{
    let b=facts.bsky.as_ref().ok_or_else(||anyhow::anyhow!("current Bluesky session/bookmark facts missing"))?;
    if !b.enabled{bail!("disabled Bluesky requires canonical generic action branch");}
    let post=post_view(status);if !post.is_object(){bail!("authoritative full PostView missing");}
    let uri=s(post,"uri");let object=https_uri(uri)?;let actor=s(&status["account"],"uri");let view=&facts.return_view;
    let liked=b.confirmed_like.unwrap_or(facts.favourited||!b.like_record.is_empty());let bookmarked=facts.bookmarked||b.bookmarked;
    let mut html=String::from("<div class=\"tweet-actions\">");
    let mentions=if facts.reply_mentions.is_empty(){vec![s(&status["account"],"acct").to_owned()]}else{facts.reply_mentions.clone()};
    html.push_str(&format!("<a class=\"icon-btn\" href=\"?view={}&amp;compose=1&amp;reply_to={}&amp;to={}{}\" title=\"Reply\" aria-label=\"Reply\"><i class=\"ph ph-arrow-bend-up-left\" aria-hidden=\"true\"></i></a>",esc(view),query(&object),query(actor),super::actions::reply_suffix(&mentions,s(status,"spoiler_text"),yes(status,"sensitive"))));
    html.push_str(&quote(&object,view,n(post,"quoteCount").max(n(status,"quotes_count")),false,false));
    html.push_str(&button("repost",post,facts,b,&object,view,!b.repost_record.is_empty(),n(post,"repostCount").max(n(status,"reblogs_count")),false,false));
    html.push_str(&button("like",post,facts,b,&object,view,liked,n(post,"likeCount").max(n(status,"favourites_count")),false,false));
    html.push_str(&button("bookmark",post,facts,b,&object,view,bookmarked,0,false,false));
    let moderation=super::actions::render_moderation(&FediActionContext{owner_id:facts.owner_id,status_id:s(status,"id").into(),object_id:uri.into(),actor_id:actor.into(),remote_url:s(status,"url").into(),return_view:view.clone(),reply_mentions:mentions,spoiler:s(status,"spoiler_text").into(),sensitive:yes(status,"sensitive"),favourited:liked,bookmarked,boosted:false,counts:Counts::default(),csrf:facts.csrf.clone(),moderation:facts.moderation.clone()});
    if moderation.is_empty()||!uri.starts_with("https://"){html.push_str(&super::actions::read_only_overflow_mode(uri,view,&super::card::remote_object_href(uri,status.get("url"))?,show_open));}
    // PHP report-object transport only admits HTTP object targets, never AT URIs.
    html.push_str(&moderation.replace(&format!("&amp;report_object={}",query(uri)),""));
    html.push_str("</div>");Ok(html)
}

/// Legacy painter after the normalizer refuses malformed/missing DID identity.
pub(crate) fn render(item:&Value,facts:&CardFacts)->Result<String>{
    if facts.owner_id<1 || !matches!(facts.return_view.as_str(),"bookmarks"|"favourites"|"search"){bail!("invalid admitted owner/context");}
    let post=&item["post"];let author=&post["author"];let record=&post["record"];
    if !post.is_object()||!author.is_object()||!record.is_object(){bail!("full legacy PostView required");}
    // Canonical legacy media/quote helpers read PostView.embed, not raw blobs
    // in record.embed. Fully resolved parent views may accompany raw reply refs.
    if record.get("reply").is_some_and(|v|!v.is_null())&&!item["reply"]["parent"].is_object(){bail!("legacy thin reply needs canonical cached enrichment or PHP fallback");}
    if let Some(parent)=item.get("reply").and_then(|r|r.get("parent")).filter(|p|p.is_object()){
        let kind=s(parent,"$type");let handle=s(&parent["author"],"handle").trim();let display=super::card::php_trim(s(&parent["author"],"displayName"));
        if !kind.contains("Blocked")&&!kind.contains("NotFound")&&(handle.is_empty()||handle.starts_with("did:")||display.is_empty()||display=="Bluesky user"||super::card::php_trim(s(&parent["record"],"text")).is_empty()){bail!("PHP fallback: thin parent requires current cache/profile enrichment");}
    }
    let b=facts.bsky.as_ref().ok_or_else(||anyhow::anyhow!("current Bluesky action facts missing"))?;
    let did=s(author,"did");let handle=s(author,"handle");let display=if super::card::php_trim(s(author,"displayName")).is_empty(){if handle.is_empty(){"Bluesky user"}else{handle}}else{super::card::php_trim(s(author,"displayName"))};
    let own=!did.is_empty()&&did==b.self_did&&!s(post,"uri").is_empty();

    let actor=if !did.is_empty(){format!("https://bsky.app/profile/{did}")}else if !handle.is_empty(){format!("https://bsky.app/profile/{}",query(handle))}else{bail!("legacy cached author identity missing");};
    let uri=s(post,"uri");let key=uri.rsplit('/').next().unwrap_or("");let open=format!("https://bsky.app/profile/{handle}/post/{key}");let reply=if did.starts_with("did:"){https_uri(uri)?}else{open.clone()};
    let view="bluesky";let profile=format!("?view=remote_profile&actor={}&from={view}",query(&actor));let rank=if did.is_empty(){actor.as_str()}else{did};
    let reason=&item["reason"];let repost=reason.is_object()&&s(reason,"$type").contains("reasonRepost");
    let mut html=format!("<article class=\"tweet tweet-bsky{}\" data-bsky-uri=\"{}\" data-bsky-cid=\"{}\" data-rank-platform=\"bsky\" data-rank-key=\"{}\" data-rank-actor=\"{}\">",if repost{" tweet-boost"}else{""},esc(uri),esc(s(post,"cid")),esc(uri),esc(rank));
    if repost{
        let by=&reason["by"];let by_did=s(by,"did").trim();let handle=by.get("handle").or_else(||by.get("displayName")).and_then(Value::as_str).unwrap_or("someone");
        let label=if !b.self_did.is_empty()&&by_did==b.self_did{"You boosted".into()}else{
            let display=super::card::php_trim(s(by,"displayName"));let text=format!("{}@{}",if display.is_empty(){String::new()}else{format!("{display} · ")},handle.trim_start_matches('@'));
            let profile=if !by_did.is_empty(){format!("https://bsky.app/profile/{by_did}")}else{format!("https://bsky.app/profile/{}",query(handle.trim_start_matches('@')))};
            format!("<a href=\"/vaak/?view=remote_profile&amp;actor={}&amp;from={}\" style=\"color:inherit;text-decoration:none\">{}</a> boosted",query(&profile),query(&facts.return_view),esc(&text))
        };
        html.push_str(&format!("<div class=\"meta meta-row\" style=\"color:var(--primary)\"><i class=\"ph ph-repeat\" aria-hidden=\"true\"></i> {label}{} </div>",if s(reason,"indexedAt").trim().is_empty(){String::new()}else{format!(" · {}",esc(&super::card::relative_time(s(reason,"indexedAt").trim(),facts.now)))}).replace(" </div>","</div>"));
    }
    if !s(item,"_vaak_feed_source").is_empty(){html.push_str("<div class=\"meta\" style=\"margin:0 0 .35rem\">☁ From saved feed</div>");}
    html.push_str("<div class=\"tweet-hd\">");
    let av=s(author,"avatar");if !av.is_empty(){html.push_str(&format!("<a href=\"{}\" style=\"text-decoration:none\"><img class=\"tweet-av\" src=\"{}\" alt=\"\" width=\"40\" height=\"40\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\" data-profile-hover-actor=\"{}\" ></a>",esc(&profile),esc(av),esc(&actor)));}
    html.push_str(&format!("<div class=\"tweet-hd-main\"><div><a class=\"who\" href=\"{}\" style=\"color:inherit;text-decoration:none\">{}</a>",esc(&profile),esc(display)));
    if !handle.is_empty(){html.push_str(&format!("<a class=\"meta\" href=\"{}\" style=\"color:var(--muted);text-decoration:none\"> @{}</a>",esc(&profile),esc(handle)));}
    let created=if repost&&!s(reason,"indexedAt").trim().is_empty(){s(reason,"indexedAt").trim()}else{post.get("indexedAt").and_then(Value::as_str).unwrap_or(s(record,"createdAt"))};if !created.is_empty(){html.push_str(&format!("<span class=\"meta\"> · {}</span>",esc(&super::card::relative_time(created,facts.now))));}
    let quote_html=legacy_quote(post,facts)?;
    if quote_html.is_some(){html.push_str(&format!("<span class=\"tag\" title=\"{}\">quote</span>",if super::card::php_trim(s(record,"text")).is_empty()&&!repost{"Quote post"}else{"Quote with commentary"}));}
    html.push_str("</div></div></div>");
    if let Some(parent)=item.get("reply").and_then(|r|r.get("parent")).filter(|p|p.is_object()){
        let kind=s(parent,"$type");let (text,url)=if kind.contains("Blocked")||kind.contains("NotFound"){("Unavailable".into(),"https://bsky.app/".into())}else{
            let handle=s(&parent["author"],"handle");let text=super::card::php_trim(s(&parent["record"],"text"));let label=if !text.is_empty(){text.chars().take(120).collect::<String>()}else if !handle.is_empty(){format!("@{handle}")}else{super::card::php_trim(s(&parent["author"],"displayName")).to_owned()};
            let uri=s(parent,"uri");let url=if uri.is_empty(){"https://bsky.app/".into()}else if handle.is_empty(){https_uri(uri)?}else{format!("https://bsky.app/profile/{handle}/post/{}",uri.rsplit('/').next().unwrap_or(""))};(label,url)
        };
        html.push_str(&format!("<div class=\"meta\" style=\"margin:.35rem 0 .5rem\">↩ reply to {}</div>",if url=="https://bsky.app/"{esc(&text)}else{format!("<a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">{}</a>",esc(&url),esc(&text))}));
    }
    let text=super::card::php_trim(s(record,"text"));if !text.is_empty(){html.push_str(&format!("<div class=\"body feed-body\">{}</div>",linked_body(post,view)?));}
    if let Some(quote)=quote_html{html.push_str(&quote);}
    let (media,card)=legacy_media(post)?;let media_html=super::card::media_row(&media,facts.lazy_media);
    if !media_html.is_empty(){html.push_str(&if sensitive(post){super::card::cw_gate("",true,&media_html,facts.auto_unblur_sensitive)}else{media_html});}
    if let Some(card)=card{html.push_str(&super::card::link_preview(&card)?);}
    let liked=b.confirmed_like.unwrap_or(facts.favourited||!b.like_record.is_empty());let bm=facts.bookmarked||b.bookmarked;
    let mentions=if facts.reply_mentions.is_empty(){vec![handle.into()]}else{facts.reply_mentions.clone()};
    html.push_str(&format!("<div class=\"tweet-actions\"><a class=\"icon-btn\" href=\"?view={view}&amp;compose=1&amp;reply_to={}&amp;feed=following{}\" title=\"Reply\" aria-label=\"Reply\"><i class=\"ph ph-arrow-bend-up-left\" aria-hidden=\"true\"></i></a>",query(&reply),super::actions::reply_suffix(&mentions,"",false)));
    if own{html.push_str(&button("repost",post,facts,b,&open,view,!b.repost_record.is_empty(),n(post,"repostCount").max(b.twin_counts.reblogs),true,true));}
    html.push_str(&quote(&reply,view,n(post,"quoteCount").max(if own{b.twin_counts.quotes}else{0}),true,own));
    if !own{html.push_str(&button("repost",post,facts,b,&open,view,!b.repost_record.is_empty(),n(post,"repostCount"),true,false));}
    html.push_str(&button("like",post,facts,b,&open,view,liked,n(post,"likeCount").max(if own{b.twin_counts.favourites}else{0}),true,own));
    html.push_str(&button("bookmark",post,facts,b,&open,view,bm,0,true,own));
    if own && b.fedi_id.contains("/notes/") {
        let mapped = b.mapped_own.as_ref().ok_or_else(|| {
            anyhow::anyhow!("PHP fallback: current mapped own note facts missing")
        })?;
        // Only presentation changes. The current owner, session and note facts
        // remain the admitted pair. PHP deliberately uses bluesky on this branch.
        let mut mapped_facts = facts.clone();
        mapped_facts.return_view = view.into();
        // admin_own_post_overflow(note, view) passes an empty edit payload. The
        // modal recovers the current note by ID; keep its exact blank attributes.
        let mut menu = mapped.clone();
        menu.plain.clear();
        menu.spoiler.clear();
        menu.sensitive = false;
        let complete = super::own::render(&b.fedi_id, &facts.owner_actor, &mapped_facts, &menu)?;
        // Reuse the canonically compared AP delete/overflow tail, excluding the
        // AP engagement row because the legacy card already painted AT actions.
        let marker = " data-vaak-ajax-delete=\"1\"";
        let start = complete.find(marker).and_then(|at| complete[..at].rfind("<form "))
            .ok_or_else(|| anyhow::anyhow!("PHP fallback: mapped delete control unavailable"))?;
        let tail = complete[start..].strip_suffix("</div>")
            .ok_or_else(|| anyhow::anyhow!("mapped own chrome boundary"))?;
        html.push_str(tail);
        html.push_str("</div></article>");
        return Ok(html);
    }
    if own{
        html.push_str(&format!("<form method=\"post\" action=\"?view={view}\" style=\"display:inline\" data-vaak-ajax-delete=\"1\" data-vaak-delete-confirm=\"Delete this Bluesky post{}?\"><input type=\"hidden\" name=\"csrf\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"delete_bsky_post\"><input type=\"hidden\" name=\"return_view\" value=\"{view}\"><input type=\"hidden\" name=\"bsky_uri\" value=\"{}\"><button class=\"icon-btn\" type=\"submit\" title=\"Delete post\" aria-label=\"Delete post\" style=\"color:var(--danger)\"><i class=\"ph ph-trash\" aria-hidden=\"true\"></i></button></form><details class=\"post-action-menu\"><summary class=\"icon-btn\" title=\"More actions\" aria-label=\"More actions\">⋯</summary><div class=\"post-action-menu__body\"><a class=\"menu-action\" href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">Open</a></div></details></div></article>",if b.fedi_id.is_empty(){""}else{" (and federate Delete for the VAAK twin if mapped)"},esc(&facts.csrf),esc(uri),esc(&open)));
        return Ok(html);
    }
    if !b.fedi_id.is_empty(){html.push_str(&format!("<a class=\"meta\" href=\"{}\" style=\"margin-left:.25rem\">AP copy</a>",esc(&b.fedi_id)));}
    let mut moderation=facts.moderation.clone();moderation.is_admin=false;
    html.push_str(&super::actions::render_moderation(&FediActionContext{owner_id:facts.owner_id,status_id:b.bookmark_status_id.clone(),object_id:open.clone(),actor_id:actor,remote_url:open,return_view:view.into(),reply_mentions:mentions,spoiler:String::new(),sensitive:false,favourited:liked,bookmarked:bm,boosted:false,counts:Counts::default(),csrf:facts.csrf.clone(),moderation}));
    html.push_str("</div></article>");Ok(html)
}

#[cfg(test)]
mod tests {
    use super::*;
    fn facts(capture:&Value)->CardFacts {
        let ctx=&capture["context"];let post=&capture["status"]["post"];let uri=s(post,"uri");
        // Fixture facts are derived from actual PHP's captured SQL snapshot.
        // Runtime resolution belongs to library_facts and cannot use this path.
        use sha2::{Digest,Sha256};
        CardFacts { current_action_counts:None, bsky:Some(BskyFacts{mapped_own:ctx.get("mapped_own").filter(|v|v.is_object()).map(|v|super::super::own::OwnFacts{local_id:n(v,"local_id"),status_id:n(v,"local_id").to_string(),plain:s(v,"content_text").into(),spoiler:s(v,"spoiler_text").into(),sensitive:yes(v,"sensitive"),visibility:s(v,"visibility").into(),pinned:yes(v,"pinned"),counts:Counts::default()}),fedi_id:s(&post["record"],"fediverseId").into(),enabled:true,bookmark_status_id:format!("bsky:{:x}",Sha256::digest(uri.as_bytes()))[..37].into(),bookmark_object_id:https_uri(uri).unwrap(),self_did:s(&ctx["bsky_session"],"did").into(),..Default::default()}),owner_id:n(ctx,"owner_id"),owner_actor:s(ctx,"owner_actor").into(),return_view:s(ctx,"return_view").into(),auto_unblur_sensitive:yes(&ctx["profile"],"auto_unblur_sensitive"),resolved_avatar:s(ctx,"resolved_author_avatar").into(),csrf:s(ctx,"csrf_token").into(),favourited:false,bookmarked:false,boosted:false,own:None,rss_row:None,rss_quote_row:None,rss_quoted:false,emoji_by_actor:Default::default(),reply_mentions:Vec::new(),moderation:Default::default(),poll_row:None,ask_row:None,now:chrono::DateTime::from_timestamp(n(ctx,"rendered_at"),0).unwrap(),lazy_media:false }
    }
    fn tokens(html:&str)->Vec<String>{
        let whitespace=regex::Regex::new(r"[ \t\r\n]+").unwrap();
        regex::Regex::new(r"<[^>]+>|[^<]+").unwrap().find_iter(html).map(|m|{let value=super::super::card::php_trim(m.as_str());if value.starts_with('<'){whitespace.replace_all(value," ").into_owned()}else{value.to_owned()}}).filter(|s|!s.is_empty()).collect()
    }
    #[test]
    fn canonical_connected_actions_and_legacy_card_match(){
        let file=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/library-bsky-enabled-reference-capture.php");
        let php=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/php-policy-test");
        let result=std::process::Command::new(php).arg(file).output().unwrap();
        assert!(result.status.success(),"{}",String::from_utf8_lossy(&result.stderr));
        let captures:Value=serde_json::from_slice(&result.stdout).unwrap();
        let mut exported=serde_json::Map::new();
        for name in captures["captures"].as_object().unwrap().keys().filter(|n|n.starts_with("legacy_")||matches!(n.as_str(),"bsky_normalized"|"bsky_sensitive"|"bsky_fallback")){
            let capture=&captures["captures"][name];let facts=facts(capture);let reference=s(capture,"html");
            let legacy=name=="bsky_fallback"||name.starts_with("legacy_");
            if yes(capture,"native_fallback_required"){assert!(render(&capture["status"],&facts).is_err(),"unresolved mapped owner/deleted note must retain canonical PHP");continue;}
            let native=if legacy{render(&capture["status"],&facts).unwrap()}else{super::super::card::render(&capture["normalized_status"],&facts).unwrap()};
            let expected=reference.to_owned();
            assert_eq!(tokens(&native),tokens(&expected),"actual canonical PHP Bluesky {name}");
            let mut native_capture=capture.clone();native_capture["html"]=Value::String(native);exported.insert(name.clone(),native_capture);
        }
        if let Ok(path)=std::env::var("VAAK_LIBRARY_BSKY_NATIVE_CAPTURE_PATH"){std::fs::write(path,serde_json::to_vec_pretty(&serde_json::json!({"captures":exported,"promotion_allowed":false})).unwrap()).unwrap();}
    }
    #[tokio::test]
    #[ignore = "requires disposable PostgreSQL socket VAAK_LIBRARY_BSKY_FACTS_TEST_DB"]
    async fn owner_bound_facts_on_actual_postgres()->Result<()> {
        let url=std::env::var("VAAK_LIBRARY_BSKY_FACTS_TEST_DB")?;
        if !url.contains("/tmp/vaak-bsky-facts-pg"){bail!("test requires isolated fixture socket");}
        let root=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin");
        let output=std::process::Command::new(root.join("php-policy-test")).arg(root.join("library-bsky-bookmark-facts-reference.php")).output()?;
        if !output.status.success(){bail!("actual PHP bookmark reference failed: {}",String::from_utf8_lossy(&output.stderr));}
        let reference:Value=serde_json::from_slice(&output.stdout)?;
        let db=crate::db::connect(&url).await?;
        for width in ["INTEGER","BIGINT"]{
            db.batch_execute(&format!("CREATE SCHEMA bsky_facts_fixture;SET search_path TO bsky_facts_fixture;
CREATE TABLE bsky_sessions(owner_user_id {width},did TEXT);
CREATE TABLE bsky_crossposts(note_id TEXT,bsky_uri TEXT,created_at TEXT);
CREATE TABLE bsky_post_links(bsky_uri TEXT,fediverse_id TEXT,ap_object_id TEXT);
CREATE TABLE masto_statuses(local_id {width},note_id TEXT,published TEXT,content_text TEXT DEFAULT '',spoiler_text TEXT DEFAULT '',sensitive {width} DEFAULT 0,visibility TEXT DEFAULT 'public');
CREATE TABLE masto_pins(owner_user_id {width},status_local_id {width});
CREATE TABLE ap_users(id {width},actor_id TEXT);
CREATE TABLE masto_favourites(id {width},owner_user_id {width},status_id TEXT,object_id TEXT,like_activity_id TEXT);
CREATE TABLE masto_reblogs(owner_user_id {width},status_id TEXT,object_id TEXT);
CREATE TABLE masto_bookmarks(owner_user_id {width},status_id TEXT,object_id TEXT);
CREATE TABLE bsky_favourite_cache(owner_user_id {width},favourite_uri TEXT,post_json TEXT);
CREATE TABLE bsky_bookmark_cache(owner_user_id {width},bookmark_uri TEXT);
CREATE TABLE ap_action_queue(owner_user_id {width},platform TEXT,action_kind TEXT,target_key TEXT,status TEXT,confirmed_state {width},receipt_json TEXT,id BIGSERIAL,updated_at TEXT DEFAULT '2026-10-10T12:00:00Z');
CREATE TABLE events(type TEXT,object_id TEXT,actor_id TEXT,quote_object TEXT,summary TEXT);
CREATE TABLE quote_authorizations(deleted_at TEXT,quoted_note_id TEXT);
CREATE TABLE bsky_posts(bsky_uri TEXT,like_count {width},repost_count {width},quote_count {width});
INSERT INTO bsky_sessions VALUES(1,'did:plc:owner-one'),(2,'did:plc:owner-two');")).await?;
            let uri="at://did:plc:author/app.bsky.feed.post/key";
            let like="at://did:plc:owner-one/app.bsky.feed.like/current-like";
            let boost="at://did:plc:owner-one/app.bsky.feed.repost/current-boost";
            let item=serde_json::json!({"post":{"uri":uri,"author":{"did":"did:plc:author"},"record":{"text":"public"},"viewer":{"like":like,"repost":boost}}});
            let other=resolve(&db,2,&item,true).await?.unwrap();assert_eq!(other.self_did,"did:plc:owner-two");assert!(other.like_record.is_empty()&&other.repost_record.is_empty());
            let uncached=serde_json::json!({"post":{"uri":"at://did:plc:author/app.bsky.feed.post/unmapped","author":{"did":"did:plc:author"},"record":{"text":"public"}}});
            let unmapped=resolve(&db,2,&uncached,true).await?.unwrap();assert_eq!(unmapped.bookmark_status_id,s(&reference["unmapped"],"status_id"));assert_eq!(unmapped.bookmark_object_id,s(&reference["unmapped"],"object_id"));
            assert!(resolve(&db,1,&item,true).await.is_err(),"same DID alone is no freshness proof");
            let favourite=serde_json::json!({"post":{"uri":uri,"viewer":{"like":like}}}).to_string();
            db.execute("INSERT INTO bsky_favourite_cache VALUES(1,$1,$2)",&[&uri,&favourite]).await?;
            db.execute("INSERT INTO ap_action_queue(owner_user_id,platform,action_kind,target_key,status,confirmed_state,receipt_json) VALUES(1,'bsky','boost',$1,'succeeded',1,$2)",&[&uri,&serde_json::json!({"record_uri":boost}).to_string()]).await?;
            let own=resolve(&db,1,&item,true).await?.unwrap();assert_eq!(own.like_record,like);assert_eq!(own.repost_record,boost);
            db.execute("INSERT INTO bsky_bookmark_cache VALUES(1,$1)",&[&uri]).await?;
            assert!(resolve(&db,1,&item,true).await?.unwrap().bookmarked);assert!(!resolve(&db,2,&item,true).await?.unwrap().bookmarked);
            db.execute("INSERT INTO ap_action_queue(owner_user_id,platform,action_kind,target_key,status,confirmed_state,receipt_json) VALUES(1,'bsky','bookmark',$1,'succeeded',0,'{}')",&[&uri]).await?;
            assert!(!resolve(&db,1,&item,true).await?.unwrap().bookmarked,"current unbookmark wins owner cache");
            // Current AP save is an independent PHP bookmark source, even when
            // the native network has a successful unbookmark and a warm cache.
            db.execute("INSERT INTO masto_bookmarks VALUES(1,'independent',$1)",&[&uri]).await?;
            assert!(resolve(&db,1,&item,true).await?.unwrap().bookmarked,"native off preserves independently current AP save");
            assert!(resolve_with_bookmark_state(&db,1,&item,true,Some(false)).await?.unwrap().bookmarked,"live native false preserves AP save");
            db.execute("DELETE FROM masto_bookmarks WHERE owner_user_id=1",&[]).await?;
            assert!(!resolve_with_bookmark_state(&db,1,&item,true,Some(false)).await?.unwrap().bookmarked,"live native false suppresses warm native cache");
            assert!(resolve_with_bookmark_state(&db,1,&item,true,Some(true)).await.is_err(),"external current true cannot be erased by historical undo");
            let foreign_bookmark=serde_json::json!({"post":{"uri":"at://did:plc:author/app.bsky.feed.post/unmapped","author":{"did":"did:plc:author"},"record":{"text":"public"},"viewer":{"bookmarked":true}},"_vaak_bookmark_owner":2});
            assert!(!resolve(&db,2,&foreign_bookmark,true).await?.unwrap().bookmarked,"global viewer/forged owner marker never authorizes current bookmark");
            assert!(resolve_with_bookmark_state(&db,2,&foreign_bookmark,true,Some(true)).await.is_err(),"authenticated unknown true falls back whole batch");
            assert!(!resolve_with_bookmark_state(&db,2,&foreign_bookmark,true,Some(false)).await?.unwrap().bookmarked);

            db.execute("INSERT INTO ap_action_queue(owner_user_id,platform,action_kind,target_key,status,confirmed_state,receipt_json) VALUES(1,'bsky','like',$1,'succeeded',0,'{}')",&[&uri]).await?;
            db.execute("UPDATE ap_action_queue SET confirmed_state=0,receipt_json='{}' WHERE action_kind='boost'",&[]).await?;
            let undone=resolve(&db,1,&item,true).await?.unwrap();assert!(undone.like_record.is_empty()&&undone.repost_record.is_empty(),"current undo wins warm global and owner cache");
            let note="https://mkultra.monster/users/owner-one/notes/7";
            db.execute("INSERT INTO bsky_crossposts VALUES($1,$2,'2026-10-10T12:00:00Z')",&[&note,&uri]).await?;
            db.execute("INSERT INTO masto_statuses(local_id,note_id,published) VALUES(7,$1,'2026-10-10T12:00:00Z')",&[&note]).await?;
            let mapped=resolve(&db,1,&item,false).await?.unwrap();assert_eq!(mapped.bookmark_status_id,s(&reference["mapped"],"status_id"));assert_eq!(mapped.bookmark_object_id,s(&reference["mapped"],"object_id"));assert!(!mapped.enabled);
            db.execute("INSERT INTO ap_users VALUES(1,'https://mkultra.monster/users/owner-one')",&[]).await?;
            db.execute("INSERT INTO masto_pins VALUES(1,7)",&[]).await?;
            let mut mapped_own=serde_json::json!({"post":{"uri":uri,"author":{"did":"did:plc:owner-one"},"record":{"text":"","fediverseId":note}}});
            let current=resolve(&db,1,&mapped_own,true).await?.unwrap();let own=current.mapped_own.unwrap();assert_eq!(own.local_id,7);assert!(own.pinned);
            mapped_own["post"]["record"]["fediverseId"]=serde_json::json!("https://mkultra.monster/users/owner-two/notes/9");
            assert!(resolve(&db,1,&mapped_own,true).await.is_err(),"connected DID does not grant another AP owner's menu");
            mapped_own["post"]["record"]["fediverseId"]=serde_json::json!("https://mkultra.monster/users/owner-one/notes/deleted");
            assert!(resolve(&db,1,&mapped_own,true).await.is_err(),"deleted mapped note cannot render stale delete/edit/pin");
            db.batch_execute("DROP SCHEMA bsky_facts_fixture CASCADE;SET search_path TO public;").await?;
        }
        Ok(())
    }
}
