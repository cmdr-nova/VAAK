//! Search surface projection. Ranked Search hits reuse the same Projector
//! builders as profiles and Library (ap_masto_status_from_row/_from_event),
//! one shared request memo like PHP's. Search keeps its own admission and
//! downranking in search_collect; nothing here grants audience.
use super::*;

/// Search applies writers inline, like the PHP builders do. There is no
/// enclosing selection window to re-check, so the grant is always current.
struct SearchGrant;
impl crate::profile_enrichment::CurrentEnrichmentGrant for SearchGrant {
    fn accept_preview_write(&self,_:&crate::profile_preview_fetch::PreviewCacheWrite)->Result<()>{Ok(())}
    fn require_current<'a>(&'a self,_:&'a Client)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{Box::pin(async{Ok(())})}
}

pub(crate) enum SearchHit<'a> { Status(&'a Value), Event(&'a Value), Mention(&'a Value) }

/// One request-wide projector, so account and row lookups are shared across
/// hits the way PHP's request memos are.
pub(crate) struct SearchProjector<'a> { projector:Projector<'a>, graphs:FollowGraphPresentation }

impl<'a> SearchProjector<'a> {
    pub(crate) fn new(db:&'a Client,owner:i64,cfg:Option<&crate::config::Config>)->Self{
        Self{projector:Projector{db,php_web_urls:true,owner,dependencies:vec![],static_counts:vec![],effects:vec![],source_index:0,account_memo:Default::default(),bare_memo:Default::default(),cache:vec![],graphs:vec![],path:vec![]},graphs:FollowGraphPresentation::new(cfg)}
    }

    /// Build one hit and run its side effects before the next hit is built,
    /// matching the inline writers in the PHP builders.
    pub(crate) async fn project(&mut self,hit:SearchHit<'_>,warm:Option<&crate::profile_enrichment::WarmContext>,dns:&dyn crate::direct_preview::PreviewDns)->Result<Option<Value>>{
        let p=&mut self.projector;p.source_index+=1;p.path.clear();
        let status=match hit{
            SearchHit::Status(row)=>{
                let outbox=p.rows("SELECT to_jsonb(n) FROM outbox_notes n WHERE id=$1::jsonb->>'note'",json!({"note":s(row,"note_id")})).await?;
                Some(p.local_status(row,outbox.first().unwrap_or(&Value::Null),true).await?)
            }
            SearchHit::Event(row)=>p.event_status(row,false,"").await?,
            SearchHit::Mention(row)=>Some(p.mention_status(row).await?),
        };
        let Some(status)=status else{self.discard();return Ok(None)};
        self.finish(status,warm,dns).await.map(Some)
    }

    /// ap_masto_account_from_user for an ap_users row, keyed by actor_key.
    pub(crate) async fn local_account(&mut self,actor_key:&str,warm:Option<&crate::profile_enrichment::WarmContext>,dns:&dyn crate::direct_preview::PreviewDns)->Result<Value>{
        let p=&mut self.projector;p.source_index+=1;p.path.clear();
        let account=p.local_account(&format!("https://mkultra.monster/users/{actor_key}/notes/search")).await?;
        let mut wrapped=self.finish(json!({"account":account}),warm,dns).await?;Ok(wrapped["account"].take())
    }

    /// ap_masto_remote_account($actor, false).
    pub(crate) async fn remote_account(&mut self,actor:&str,warm:Option<&crate::profile_enrichment::WarmContext>,dns:&dyn crate::direct_preview::PreviewDns)->Result<Value>{
        let p=&mut self.projector;p.source_index+=1;p.path.clear();
        let account=p.remote_account(actor).await?;
        let mut wrapped=self.finish(json!({"account":account}),warm,dns).await?;Ok(wrapped["account"].take())
    }

    fn discard(&mut self){let p=&mut self.projector;p.effects.clear();p.cache.clear();p.graphs.clear();}

    async fn finish(&mut self,mut status:Value,warm:Option<&crate::profile_enrichment::WarmContext>,dns:&dyn crate::direct_preview::PreviewDns)->Result<Value>{
        let p=&mut self.projector;
        let effects=std::mem::take(&mut p.effects);let cache=std::mem::take(&mut p.cache);let graphs=std::mem::take(&mut p.graphs);
        let grant=SearchGrant;
        for effect in &effects{
            match effect.effect(){
                ProjectionEffect::RememberAccount{..}=>{
                    let mut scratch=Vec::new();effects::apply(p.db,&mut scratch,effect,&effects::Grant::Search,&status).await?;
                    // Later hits must read the mapping this writer just stored.
                    p.dependencies.retain(|d|!d.sql.contains("masto_account_actors"));
                }
                ProjectionEffect::PostMediaTouch{source_url}=>{post_media_touch(p.db,source_url).await?;p.dependencies.retain(|d|d.sql!=effects::POST_MEDIA);}
                ProjectionEffect::PostMediaWarm{source_url}=>{let warm=warm.context("PHP fallback: Search post-media transport unavailable")?;post_media::enqueue_search(p.db,warm.cfg(),source_url,warm.transport(),&grant).await?;}
                ProjectionEffect::ActorWarm(actor)=>{warm.context("PHP fallback: Search actor warm transport unavailable")?.actor_with_grant(p.db,actor,&grant).await?;}
                ProjectionEffect::MediaWarm(actor)=>{warm.context("PHP fallback: Search media warm transport unavailable")?.media_with_grant(p.db,actor,&grant).await?;}
            }
        }
        for task in &graphs{for kind in ["followers","following"]{
            let(key,value,write,_)=self.graphs.load(p.db,kind,&task.actor,&grant).await?;
            let count=graph_array_count(&value);self.graphs.retain(key,value,write).await;
            apply_graph_count(&mut status,&task.actor,kind,count);
        }}
        for task in &cache{
            let Some(_)=crate::direct_preview::extract_url(&task.text)? else{continue};
            let card=crate::direct_preview::resolve_cache_only_guarded(p.db,dns,&task.text,chrono::Utc::now(),Some(&grant)).await?;
            if task.require_hit&&card.is_none(){
                let preview=warm.and_then(|w|w.preview_context()).context("PHP fallback: Search cold link warm transport absent")?;
                let bridge=CompletionQuoteGrant{db:p.db,grant:&grant};preview.warm_text(&task.text,&bridge).await?;
            }
            let mut node=&mut status;for part in &task.path{node=node.get_mut(part).context("Search projection cache path changed")?;}
            node["card"]=card.as_ref().map(masto_preview_card).unwrap_or(Value::Null);
        }
        Ok(status)
    }
}

/// ap_remote_post_media_touch: best-effort last_used_at refresh on a cache hit.
pub(crate) async fn post_media_touch(db:&Client,source_url:&str)->Result<()>{
    let now=chrono::Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Secs,false);
    let _=db.execute("UPDATE remote_post_media_cache SET last_used_at=$1 WHERE source_url=$2",&[&now,&source_url]).await;Ok(())
}

/// ap_remote_post_media_resolve for Search-built Bluesky media: a cached URL on
/// a hit, otherwise the source URL with one background warm request queued.
pub(crate) async fn post_media_resolve(db:&Client,source_url:&str,warm:Option<&crate::profile_enrichment::WarmContext>)->Result<String>{
    let Some(url)=crate::profile_enrichment::sanitize_profile_url(source_url)? else{return Ok(String::new())};
    let cached=Dependency::read(db,effects::POST_MEDIA,&json!({"source_url":url})).await?;
    if let Some(public)=cached.first().map(|row|s(row,"public_url")).filter(|u|!u.is_empty()&&*u!="0"){
        post_media_touch(db,&url).await?;return Ok(public.to_owned())
    }
    let warm=warm.context("PHP fallback: Search post-media transport unavailable")?;
    post_media::enqueue_search(db,warm.cfg(),&url,warm.transport(),&SearchGrant).await?;Ok(url)
}

/// ap_masto_clean_mention_text. CR line breaks stay on PHP: PCRE's atomic \R
/// would count a CRLF as one break where this port could count two.
fn clean_mention_text(raw:&str)->Result<String>{
    let mut text=re!(r#"(?i)<(?:span|p|div)\b[^>]*class="[^"]*\bquote-inline\b[^"]*"[^>]*>[\s\S]*?</(?:span|p|div)>"#).replace_all(raw,"").into_owned();
    if text.to_ascii_lowercase().contains("<blockquote")&&re!(r"(?i)RE:\s*https?://").is_match(&text){
        text=re!(r"(?i)<blockquote\b[^>]*>[\s\S]*?RE:\s*https?://[\s\S]*?</blockquote>").replace_all(&text,"").into_owned();
    }
    text=crate::home_hydrate_ranked::html_entity_decode(&text);
    text=crate::library::card::php_trim(&crate::search_collect::strip_tags(&text)?).to_owned();
    if text.contains('\r'){bail!("PHP fallback: mention text has CR line breaks")}
    text=re!(r"(?i)^↩\s*re:\s*https?://\S+\s*").replace_all(&text,"").into_owned();
    // (?<=\S)\R{2,}RE:… to the end: keep the \S that the lookbehind only peeked at.
    // \R is (?:\n|\x0B|\x0C|\x{85}|\x{2028}|\x{2029}) once CR is ruled out.
    text=re!(r"(?i)(\S)(?:\n|\x0B|\x0C|\x{85}|\x{2028}|\x{2029}){2,}RE:\s*https?://\S+[^\S\r\n]*(?:(?:\n|\x0B|\x0C|\x{85}|\x{2028}|\x{2029})[^\r\n]*)*$").replace_all(&text,"$1").into_owned();
    text=re!(r"(?i)^RE:\s*https?://\S+?(?:/notes/[a-f0-9]+|/\d+)").replace_all(&text,"").into_owned();
    text=re!(r"(?i)RE:\s*https?://\S+").replace_all(&text,"").into_owned();
    text=re!(r"^↪ QT(?:\s+@\S+:)?\s*").replace_all(&text,"").into_owned();
    text=re!(r"[ \t]+").replace_all(&text," ").into_owned();
    text=re!(r"\n{3,}").replace_all(&text,"\n\n").into_owned();
    Ok(crate::library::card::php_trim(&text).to_owned())
}

/// ap_masto_mention_target_object_id.
fn mention_target_object_id(raw:&str)->String{
    let object=raw.trim_end_matches('/');
    if let Some(c)=re!(r"(?i)^(https://.+?)#(like|reblog|update|quote|bite|webmention)-[a-z0-9]+$").captures(object){return c[1].trim_end_matches('/').to_owned()}
    let value=crate::library::card::php_trim(object);
    let stripped=re!(r"#announce-\d+$").replace(value,"");
    if stripped==value{value.to_owned()}else{stripped.trim_end_matches('/').to_owned()}
}

fn id_string(v:&Value)->String{match v{Value::String(s)=>s.clone(),Value::Number(n)=>n.to_string(),Value::Bool(true)=>"1".into(),_=>String::new()}}

impl Projector<'_>{
    /// ap_masto_status_from_mention for fediverse mention rows. Bluesky
    /// mentions (AppView media hydrate), Asks, quotes and reply-bake bodies
    /// stay on PHP, which also keeps ap_normalize_status a no-op here.
    async fn mention_status(&mut self,row:&Value)->Result<Value>{
        let mention_id=i(row,"id");let actor=s(row,"actor_id");let raw=s(row,"content");
        if mention_id<=0||actor.is_empty(){bail!("PHP fallback: mention row without id or actor")}
        let activity=s(row,"activity_id");let object_raw=s(row,"object_id");
        if activity.starts_with("at://")||actor.trim_end_matches('/').starts_with("https://bsky.app/")||object_raw.starts_with("https://bsky.app/")||object_raw.starts_with("bsky:")||object_raw.starts_with("at://"){bail!("PHP fallback: Bluesky mention Search hit")}
        if !crate::library::card::php_trim(s(row,"ask_question")).is_empty(){bail!("PHP fallback: Ask mention Search hit")}
        let text=clean_mention_text(raw)?;
        let qt=|t:&str|t.contains("↪ QT")||t.contains("↪QT")||re!(r"(?:^|\n)\s*RE:\s*https?://").is_match(t);
        if qt(raw)||qt(&text)||raw.to_ascii_lowercase().contains("quote-inline")||raw.contains("_misskey_quote"){bail!("PHP fallback: quote mention needs the quote assembler")}
        // ap_normalize_status passes: Ask attach, quote scrub and reply-bake strip.
        if re!(r"(?i)\sasked").is_match(raw)||re!(r"(?i)\sasked").is_match(&text)||text.contains('↩')||text.contains('↪')
            ||text.contains("QuoteAuthorization")||text.contains("interactionPolicy")||text.starts_with('{')||text.starts_with('[')
            ||re!(r"(?:➡|→)\s*QT").is_match(&text){bail!("PHP fallback: mention body needs the canonical normalizer")}
        let target=mention_target_object_id(object_raw);
        let url=if target.is_empty(){actor.to_owned()}else{target};
        let lookup=url.trim_end_matches('/');
        if lookup.starts_with("https://"){
            let asks=self.rows("SELECT jsonb_build_object('n',1) FROM ap_asks WHERE answer_note_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') AND COALESCE(question,'')<>'' LIMIT 1",json!({"note":lookup})).await?;
            let asked=self.rows("SELECT jsonb_build_object('n',1) FROM mentions WHERE object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') AND deleted_at IS NULL AND ask_question<>'' LIMIT 1",json!({"note":lookup})).await?;
            if !asks.is_empty()||!asked.is_empty(){bail!("PHP fallback: mention carries an Ask")}
        }
        let account=self.remote_account(actor).await?;
        let mut list=vec![];
        if truth(&row["media_urls"]){
            match serde_json::from_str::<Value>(s(row,"media_urls")){
                Ok(Value::Array(items))=>{for item in items{if let Some(u)=item.as_str(){if let Some(clean)=crate::profile_enrichment::sanitize_profile_url(u)?{list.push(clean)}}}}
                Ok(Value::Object(_))=>bail!("PHP fallback: keyed mention media map"),
                _=>{}
            }
        }
        let mut media=vec![];
        for (n,clean) in list.iter().take(4).enumerate(){
            let(play,preview,kind,media_type)=crate::search_collect::bsky::animated_media(clean,clean);
            let lower=clean.to_ascii_lowercase();
            let gif=kind=="gifv"||re!(r"(?i)\.gif(\?|#|$)").is_match(clean)||lower.contains("tenor.com")||lower.contains("giphy.com")||lower.contains("klipy.com");
            let kind=if kind=="gifv"||gif{"gifv"}else{kind};
            media.push(json!({"id":format!("{}{}",2_000_000+mention_id,n+1),"type":kind,"url":play,"preview_url":preview,"remote_url":clean,"preview_remote_url":null,"text_url":null,"meta":null,"description":if gif||kind=="gifv"{json!("GIF")}else{Value::Null},"blurhash":null,"mediaType":media_type.or(if kind=="gifv"{Some("image/gif")}else{None})}));
        }
        let mut in_reply=s(row,"in_reply_to").to_owned();
        if in_reply.is_empty(){if let Some(m)=re!(r"(?i)https://mkultra\.monster/users/[A-Za-z0-9_]+/notes/[a-f0-9]+").find(raw){in_reply=m.as_str().to_owned();}}
        if in_reply.starts_with("https://bsky.app/")||in_reply.starts_with("at://"){bail!("PHP fallback: mention replies to a Bluesky post")}
        let(mut reply_id,mut reply_account,mut parent_actor):(Option<String>,Option<String>,Option<String>)=(None,None,None);
        if !in_reply.is_empty(){
            in_reply=in_reply.trim_end_matches('/').to_owned();
            let local_parent=re!(r"^(https://mkultra\.monster/users/[A-Za-z0-9_]+)/notes/").captures(crate::library::card::php_trim(&in_reply).trim_end_matches('/')).map(|c|c[1].to_owned());
            if let Some(local_actor)=local_parent{
                let parents=self.rows("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id=$1::jsonb->>'note'",json!({"note":in_reply})).await?;
                if let Some(parent)=parents.first(){
                    if s(parent,"published").is_empty(){bail!("PHP fallback: reply parent without a published time")}
                    reply_id=Some(snowflake(&format_time(s(parent,"published"))?,i(parent,"local_id"),0)?);
                    reply_account=Some(id_string(&self.local_account(&in_reply).await?["id"]));
                    parent_actor=Some(local_actor);
                }
            }else{
                if let Some((sid,acc))=self.status_ref(&in_reply).await?{reply_id=Some(sid);reply_account=acc;}
                if parent_actor.is_none(){
                    parent_actor=actor_from_object(crate::library::card::php_trim(&in_reply).trim_end_matches('/'));
                    if reply_account.is_none(){if let Some(a)=parent_actor.clone(){reply_account=Some(self.remote_account_id(&a).await?);}}
                }
            }
        }
        let owner=self.rows("SELECT to_jsonb(u) FROM ap_users u WHERE id=($1::jsonb->>'owner')::bigint AND disabled_at IS NULL LIMIT 1",json!({"owner":self.owner})).await?;
        let session=owner.first().map(|u|s(u,"actor_id").trim_end_matches('/').to_owned()).filter(|a|a.starts_with("https://")).unwrap_or_default();
        let mut extra=vec![];if let Some(a)=parent_actor.as_ref().filter(|a|!a.is_empty()){extra.push(a.clone())}extra.push(session);
        let mut pack=self.canonical_pack_with_actors(&text,&extra).await?;
        if let (Some(acc),Some(pa))=(reply_account.clone(),parent_actor.clone()){
            let local=re!(r"^https://mkultra\.monster/users/[A-Za-z0-9_]+$").is_match(crate::library::card::php_trim(&pa).trim_end_matches('/'));
            let mentions=pack["mentions"].as_array_mut().context("mention pack without mentions")?;
            if local{
                let parent=self.local_account(&pa).await?;
                let pid=parent.get("id").map(id_string).unwrap_or(acc);
                let mut username=s(&parent,"username").to_owned();let mut acct=s(&parent,"acct").to_owned();
                let purl=if parent.get("url").is_some_and(|v|!v.is_null()){id_string(&parent["url"])}else{pa.clone()};
                if username.is_empty(){if let Some(c)=re!(r"/users/([A-Za-z0-9_]+)$").captures(&pa){username=c[1].to_owned();acct=c[1].to_owned();}}
                if !mentions.iter().any(|m|id_string(&m["id"])==pid){mentions.insert(0,json!({"id":pid,"username":username,"url":purl,"acct":acct}));}
            }else if !mentions.iter().any(|m|id_string(&m["id"])==acc){
                let m=self.mention_from_actor(&pa).await?;pack["mentions"].as_array_mut().unwrap().insert(0,m);
            }
        }
        let spoiler=crate::library::card::php_trim(s(row,"spoiler_text")).to_owned();
        let created_raw=s(row,"created_at");if created_raw.is_empty(){bail!("PHP fallback: mention without created_at")}
        let created=format_time(created_raw)?;
        let mut status=json!({"id":snowflake(&created,mention_id,2)?,"created_at":created,"in_reply_to_id":reply_id,"in_reply_to_account_id":reply_account,"in_reply_to":if in_reply.is_empty(){Value::Null}else{json!(in_reply)},
            "sensitive":truth(&row["sensitive"])||!spoiler.is_empty(),"spoiler_text":spoiler,"visibility":"public","language":"en","uri":url,"url":url,"replies_count":0,"reblogs_count":0,"favourites_count":0,"edited_at":null,
            "favourited":false,"reblogged":false,"muted":false,"bookmarked":false,"pinned":false,"content":pack["content"],"reblog":null,"application":null,"account":account,"media_attachments":media,
            "mentions":pack["mentions"],"tags":pack.get("tags").cloned().unwrap_or(json!([])),"emojis":[],"card":null,"poll":null,"quote":null,"quote_approval":{"automatic":["public"],"manual":[],"current_user":"automatic"}});
        if media.is_empty()&&crate::direct_preview::extract_url(raw)?.is_some(){self.cache.push(DeferredCacheProjection{source_index:self.source_index,path:self.path.clone(),text:raw.into(),require_hit:true});}
        self.interaction_flags(&mut status).await?;
        Ok(status)
    }
    /// ap_masto_resolve_object_url_to_status_ref.
    async fn status_ref(&mut self,raw:&str)->Result<Option<(String,Option<String>)>>{
        let url=crate::library::card::php_trim(raw).trim_end_matches('/').to_owned();
        if url.is_empty()||!url.starts_with("https://"){return Ok(None)}
        let local=self.rows("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id=$1::jsonb->>'note'",json!({"note":url})).await?;
        if let Some(local)=local.first(){if s(local,"published").is_empty(){bail!("PHP fallback: status without a published time")}return Ok(Some((snowflake(&format_time(s(local,"published"))?,i(local,"local_id"),0)?,Some("1".into()))))}
        let events=self.rows("SELECT to_jsonb(e) FROM events e WHERE object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') AND COALESCE(action_taken,'')<>'deleted' ORDER BY CASE type WHEN 'Create' THEN 0 WHEN 'Update' THEN 1 WHEN 'Announce' THEN 10 WHEN 'Like' THEN 11 ELSE 5 END ASC,id DESC LIMIT 1",json!({"note":url})).await?;
        if let Some(event)=events.first().filter(|e|i(e,"id")>0){
            let sid=if s(event,"created_at").is_empty(){(3_000_000+i(event,"id")).to_string()}else{snowflake(&format_time(s(event,"created_at"))?,i(event,"id"),1)?};
            let acc=if s(event,"actor_id").is_empty(){None}else{Some(self.remote_account_id(s(event,"actor_id")).await?)};
            return Ok(Some((sid,acc)))
        }
        let mentions=self.rows("SELECT to_jsonb(m) FROM mentions m WHERE object_id IN($1::jsonb->>'note',($1::jsonb->>'note')||'/') AND deleted_at IS NULL LIMIT 1",json!({"note":url})).await?;
        if let Some(m)=mentions.first(){
            let sid=if s(m,"created_at").is_empty(){(2_000_000+i(m,"id")).to_string()}else{snowflake(&format_time(s(m,"created_at"))?,i(m,"id"),2)?};
            let acc=if s(m,"actor_id").is_empty(){None}else{Some(self.remote_account_id(s(m,"actor_id")).await?)};
            return Ok(Some((sid,acc)))
        }
        Ok(None)
    }
}
