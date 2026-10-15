//! Resolve native painter facts from current DB state, never cached role bits.
use anyhow::{Context, Result};
use serde_json::Value;
use tokio_postgres::Client;
use super::{actions::{Counts, ModerationContext}, card::CardFacts};

/// Presentation only: implementations are constructed by authenticated server
/// bridges. This interface cannot grant audience access or construct a token.
pub(crate) trait PresentationSession {
    fn warm_context(&self)->Option<&crate::profile_enrichment::WarmContext>{None}
    fn csrf(&self) -> &str;
    fn return_view(&self) -> &str;
    fn bsky_enabled(&self) -> bool;
    fn lazy_media(&self) -> bool;
    fn bsky_bookmark_state(&self, status: &Value) -> Option<bool>;
}

/// Enrichment is bound to one currently admitted source by the assembler.
/// This wrapper carries presentation preferences only; the WarmContext's
/// private current-source grant controls every actor/media effect.
pub(crate) struct BoundPresentationSession<'a,T:PresentationSession>{pub(crate) session:&'a T,pub(crate) warm:std::sync::Arc<crate::profile_enrichment::WarmContext>}
impl<T:PresentationSession> PresentationSession for BoundPresentationSession<'_,T>{
 fn warm_context(&self)->Option<&crate::profile_enrichment::WarmContext>{Some(&self.warm)}
 fn csrf(&self)->&str{self.session.csrf()}
 fn return_view(&self)->&str{self.session.return_view()}
 fn bsky_enabled(&self)->bool{self.session.bsky_enabled()}
 fn lazy_media(&self)->bool{self.session.lazy_media()}
 fn bsky_bookmark_state(&self,status:&Value)->Option<bool>{self.session.bsky_bookmark_state(status)}
}

/// PHP `ap_event_quote_target_count_sql`.
const QUOTE_TARGET_COUNT_SQL: &str = "SELECT count(*) FROM event_quote_targets WHERE quote_target=$1 AND object_id IS DISTINCT FROM $2 AND object_id IS DISTINCT FROM $3";

fn s<'a>(v: &'a Value, key: &str) -> &'a str { v.get(key).and_then(Value::as_str).unwrap_or("") }
fn n(v: &Value, key: &str) -> i64 { v.get(key).and_then(Value::as_i64).or_else(|| s(v,key).parse().ok()).unwrap_or(0) }
fn flag(v: &Value, key: &str) -> bool { v.get(key).and_then(Value::as_bool).unwrap_or_else(|| n(v,key) != 0) }

/// Own action bars preserve numeric source IDs. Canonical recovery for an
/// opaque/missing ID uses the current local publication time, never row ID.
fn own_status_id(status:&Value,stored:&Value)->Result<String> {
    let incoming=match status.get("id").unwrap_or(&Value::Null) {
        Value::String(value)=>value.clone(),Value::Number(value)=>value.to_string(),
        Value::Bool(true)=>"1".into(),_=>String::new(),
    };
    let incoming=super::card::php_trim(&incoming);
    if !incoming.is_empty()&&incoming.bytes().all(|byte|byte.is_ascii_digit()){return Ok(incoming.into())}
    let published=s(stored,"published");
    let seconds=chrono::DateTime::parse_from_rfc3339(published).map(|date|date.timestamp())
        .or_else(|_|chrono::NaiveDateTime::parse_from_str(published,"%Y-%m-%d %H:%M:%S").map(|date|date.and_utc().timestamp()))
        .context("PHP fallback: canonical own action ID requires a supported publication timestamp")?;
    let id=seconds.checked_mul(1_000_000_000).and_then(|base|base.checked_add(n(stored,"local_id")%100_000_000))
        .context("PHP fallback: canonical own action ID overflow")?;
    Ok(id.to_string())
}
fn aliases_match(a: &str, b: &str) -> bool { let aliases = crate::hidden::actor_aliases(a); crate::hidden::actor_aliases(b).iter().any(|b| aliases.contains(b)) }
fn host(actor: &str) -> String { reqwest::Url::parse(actor).ok().and_then(|u| u.host_str().map(str::to_owned)).unwrap_or_default().to_lowercase() }

/// PHP's list order determines the selected row when actor/domain rules overlap.
pub(crate) fn personal_block<'a>(rows: &'a [Value], actor: &str) -> Option<&'a Value> {
    let host = host(actor);
    rows.iter().find(|row| match s(row,"scope") {
        "actor" => aliases_match(actor,s(row,"value")),
        "domain" => { let value = s(row,"value").to_lowercase(); !value.is_empty() && (host == value || host.ends_with(&format!(".{value}"))) }, _ => false,
    })
}

fn reply_seeds(status: &Value, owner_actor: &str, owner_key: &str) -> Vec<String> {
    let mut candidates = vec![(super::card::account_actor_ref(&status["account"]),s(&status["account"],"acct").to_owned())];
    for mention in status.get("mentions").and_then(Value::as_array).into_iter().flatten() {
        if let Some(text) = mention.as_str() { candidates.push((String::new(), text.into())); }
        else { candidates.push((mention.get("url").or_else(||mention.get("uri")).and_then(Value::as_str).unwrap_or("").to_owned(),s(mention,"acct").to_owned())); }
    }
    let mut out = Vec::new();
    for (actor,hint) in candidates {
        if actor.trim_end_matches('/') == owner_actor.trim_end_matches('/') { continue; }
        let value = super::actions::reply_mention_handle(&actor,&hint,owner_actor);
        let lower = value.to_lowercase();
        if value.is_empty() || lower == owner_key.to_lowercase() || lower == format!("{}@mkultra.monster",owner_key.to_lowercase()) { continue; }
        if !out.iter().any(|v: &String| v.eq_ignore_ascii_case(&value)) { out.push(value); }
    }
    out
}

/// Match PHP's current trusted directory plus additive DB overrides. Missing
/// directory IO is a fallback boundary, not permission to omit Ask actions.
pub(crate) async fn wafrn_host_known(db: &Client, actor: &str) -> Result<bool> {
    let mut raw = match std::env::var("VAAK_WAFRN_FRIEND_HOSTS").ok().filter(|s| !s.trim().is_empty()) {
        Some(raw) => raw,
        None => {
            let runtime=std::path::Path::new("/var/lib/mkultra/ap/wafrn-friend-hosts.txt");
            let bundled=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/deploy/wafrn-friend-hosts.txt");
            let path=if runtime.is_file() {runtime} else {&bundled};
            if path.is_file() { std::fs::read_to_string(path).context("PHP fallback: Wafrn directory unreadable")? } else { "waffles.baeddel.social".into() }
        }
    };
    for row in db.query("SELECT host FROM ap_wafrn_friend_servers WHERE COALESCE(enabled,0)::bigint=1 ORDER BY host ASC",&[]).await? { raw.push(' '); raw.push_str(&row.get::<_,String>(0)); }
    let blocks=db.query("SELECT to_jsonb(b) FROM ap_blocks b WHERE scope='domain' AND kind IN ('block','suspend')",&[]).await?.iter().map(|r|r.get::<_,Value>(0)).collect::<Vec<_>>();
    let mut included=Vec::new();
    for value in raw.split(|c:char| c.is_whitespace() || c==',').filter(|v|!v.is_empty()) {
        let reference=if value.starts_with("https://") {value.to_owned()} else {format!("https://{value}")};
        let Ok(url)=reqwest::Url::parse(&reference) else {continue}; let Some(hostname)=url.host_str() else {continue};
        let valid=hostname.len()<=253 && hostname.trim_end_matches('.').split('.').all(|label|!label.is_empty()&&label.len()<=63&&!label.starts_with('-')&&!label.ends_with('-')&&label.bytes().all(|b|b.is_ascii_alphanumeric()||b==b'-'));
        if hostname=="mkultra.monster" || !valid {continue;}
        if personal_block(&blocks,&reference).is_some() {continue;}
        let path=url.path().trim_matches('/');let inbox=format!("https://{hostname}/{}",if path.is_empty(){"fediverse/sharedInbox"}else{path});
        if !included.contains(&inbox){included.push(inbox);} if included.len() >= 40 {break;}
    }
    Ok(included.iter().any(|inbox|host(inbox)==host(actor)))
}

pub(crate) async fn local_counts(db: &Client, note: &str, sid: &str) -> Result<Counts> {
    local_counts_mode(db,note,sid,true).await
}
pub(crate) async fn local_public_counts(db:&Client,note:&str,sid:&str)->Result<Counts>{
    local_counts_mode(db,note,sid,false).await
}
async fn local_counts_mode(db:&Client,note:&str,sid:&str,include_bookmarks:bool)->Result<Counts>{
    let note = super::card::php_trim(note).trim_end_matches('/');
    if note.is_empty() || !note.contains("/notes/") { return Ok(Counts::default()); }
    let sid = super::card::php_trim(sid);
    let sid = if sid.is_empty() {note} else {sid};
    let slash = format!("{note}/"); let keys = vec![note.to_owned(),slash];
    let bookmark_count=if include_bookmarks {"(SELECT count(*) FROM masto_bookmarks WHERE status_id=$2 OR object_id=ANY($1))"} else {"0::bigint"};
    let query=format!("SELECT
      (SELECT count(DISTINCT rtrim(actor_id,'/')) FROM events WHERE type='Like' AND object_id=ANY($1)) +
      (SELECT count(*) FROM masto_favourites WHERE status_id=$2 OR object_id=ANY($1)),
      (SELECT count(DISTINCT rtrim(actor_id,'/')) FROM events WHERE type='Announce' AND object_id=ANY($1)) +
      (SELECT count(*) FROM masto_reblogs WHERE status_id=$2 OR object_id=ANY($1)),
      GREATEST((SELECT count(*) FROM events WHERE rtrim(quote_object,'/')=$3),
        (SELECT count(*) FROM quote_authorizations WHERE deleted_at IS NULL AND quoted_note_id=ANY($1))),
      {bookmark_count}");
    let row = match db.query_one(&query, &[&keys, &sid, &note]).await {
        Ok(row) => row,
        Err(error) if error.code() == Some(&tokio_postgres::error::SqlState::UNDEFINED_COLUMN) => {
            // PHP treats events.quote_object as optional on older schemas.
            // Retain authorization/summary sources and every mandatory count;
            // another missing required column still errors on the retry.
            let without_event_quotes = query.replace(
                "(SELECT count(*) FROM events WHERE rtrim(quote_object,'/')=$3)",
                "0::bigint",
            );
            db.query_one(&without_event_quotes, &[&keys, &sid]).await
                .context("current local Library engagement without optional event quotes")?
        }
        Err(error) => return Err(error).context("current local Library engagement"),
    };
    let mut counts = Counts { favourites:row.get(0), reblogs:row.get(1), quotes:row.get(2), bookmarks:row.get(3) };
    if counts.quotes == 0 {
        // PHP ap_event_quote_target_count_sql. The summary LIKE scan is gone;
        // ingest and deploy/remote/migrate.php fill event_quote_targets.
        match db.query_one(QUOTE_TARGET_COUNT_SQL, &[&note, &keys[0], &keys[1]]).await {
            Ok(row) => counts.quotes = row.get(0),
            Err(error)
                if error.code() == Some(&tokio_postgres::error::SqlState::UNDEFINED_TABLE)
                    || error.code() == Some(&tokio_postgres::error::SqlState::UNDEFINED_COLUMN) => {}
            Err(error) => return Err(error).context("indexed quote targets"),
        }
    }
    if let Some(row) = db.query_opt("SELECT p.like_count::bigint,p.repost_count::bigint,p.quote_count::bigint FROM bsky_crossposts c JOIN bsky_posts p ON p.bsky_uri=c.bsky_uri WHERE c.note_id=ANY($1) ORDER BY c.created_at DESC NULLS LAST LIMIT 1", &[&keys]).await? {
        counts.favourites += row.get::<_,i64>(0).max(0); counts.reblogs += row.get::<_,i64>(1).max(0); counts.quotes += row.get::<_,i64>(2).max(0);
    }
    Ok(counts)
}

pub(crate) async fn emoji_maps(db:&Client,status:&Value)->Result<std::collections::HashMap<String,std::collections::HashMap<String,String>>> {
    fn collect(value:&Value,actors:&mut Vec<String>){
        match value {
            Value::Object(object)=>{if let Some(account)=object.get("account"){let actor=s(account,"uri");if !actor.is_empty()&&!actors.iter().any(|a|a==actor){actors.push(actor.into());}}for child in object.values().filter(|v|v.is_object()||v.is_array()){collect(child,actors);}},
            Value::Array(items)=>for child in items{collect(child,actors)},_=>{}
        }
    }
    let mut actors=Vec::new();collect(status,&mut actors);let mut maps=std::collections::HashMap::new();
    for actor in actors {let rows=db.query("SELECT shortcode,url FROM remote_custom_emojis WHERE host=$1",&[&host(&actor)]).await?;let mut map=std::collections::HashMap::new();for row in rows {let code:String=row.get(0);let url:String=row.get(1);if !code.is_empty()&&url.starts_with("https://"){map.insert(code,url);}}maps.insert(actor,map);}
    Ok(maps)
}

pub(crate) async fn avatar(db:&Client,status:&Value,actor:&str)->Result<String>{avatar_with_context(db,status,actor,None).await}
pub(crate) async fn avatar_with_context(db:&Client,status:&Value,actor:&str,warm:Option<&crate::profile_enrichment::WarmContext>)->Result<String> {
    #[cfg(test)] let _timing=crate::search_context::selection::fixture_performance::span("avatar_facts",1);
    if let Some(url)=warm.and_then(|context|context.cached_avatar(actor)){return Ok(url)}
    let result=avatar_current(db,status,actor,warm).await?;
    if let Some(context)=warm{context.cache_avatar(actor,&result)}
    Ok(result)
}
async fn avatar_current(db:&Client,status:&Value,actor:&str,warm:Option<&crate::profile_enrichment::WarmContext>)->Result<String>{
    if matches!(s(status,"source"),"rss"|"bluesky") || status.get("bsky_post").is_some_and(Value::is_object) {
        let direct=s(&status["account"],"avatar");
        if direct.starts_with("https://")||direct.starts_with("http://"){return Ok(direct.into());}
    }
    if matches!(actor,"https://waffles.baeddel.social/fediverse/blog/admin"|"https://app.wafrn.net/fediverse/blog/admin"){return Ok("https://mkultra.monster/img/avatar/wafrn-approvals.webp".into());}
    if let Some(capture)=regex::Regex::new(r"^https://mkultra\.monster/users/([A-Za-z0-9_]+)$").unwrap().captures(actor){
        let key=capture[1].to_lowercase();let row=db.query_opt("SELECT icon_url FROM actor_profile WHERE lower(actor_key)=$1 LIMIT 1",&[&key]).await?;
        let icon=row.and_then(|row|row.get::<_,Option<String>>(0)).unwrap_or_default();
        return Ok(if reqwest::Url::parse(&icon).is_ok_and(|url|url.scheme()=="https"){icon}else{"https://mkultra.monster/img/avatar/local-default.webp".into()});
    }
    if !actor.starts_with("https://"){return Ok("https://mkultra.monster/img/avatar/default.webp".into());}
    let mut candidates=vec![actor.to_owned()];candidates.extend(crate::hidden::actor_aliases(actor).into_iter().filter(|a|a!=actor));
    for candidate in candidates {
        if let Some(row)=db.query_opt("SELECT public_url FROM remote_media_cache WHERE rtrim(actor_id,'/')=$1 AND kind='avatar' LIMIT 1",&[&candidate]).await? {
            let url:String=row.get(0);if reqwest::Url::parse(&url).is_ok_and(|u|u.scheme()=="https"){return Ok(url);}
        }
    }
    if let Some(context)=warm{
        context.actor_checked(db,actor).await?;
        let row=crate::profile_identity::lookup_actor(db,context.cfg(),actor).await?;
        if let Some(icon)=row.as_ref().map(|row|s(row,"icon_source_url")).filter(|icon|!icon.is_empty()){
            context.media_checked(db,actor).await?;
            if let Some(source)=crate::profile_enrichment::sanitize_profile_url(icon)?{return Ok(source)}
        }
        return Ok("https://mkultra.monster/img/avatar/default.webp".into())
    }
    // The PHP painter enqueues actor/media warm jobs on a cold avatar. Native
    // read-only sources cannot silently lose those effects or hotlink a stale
    // status account snapshot; use the complete canonical fallback branch.
    anyhow::bail!("PHP fallback: cold remote avatar requires authoritative alias lookup and async actor/media warming")
}

pub(crate) async fn ask_identity_with_context(db:&Client,ask:Value,warm:Option<&crate::profile_enrichment::WarmContext>)->Result<Value>{
    if let Some(context)=warm{crate::profile_identity::ask_identity(db,context,ask).await}else{ask_identity(db,ask).await}
}
pub(crate) async fn ask_identity(db:&Client,mut ask:Value)->Result<Value>{
    let actor=super::card::php_trim(s(&ask,"asker_actor")).trim_end_matches('/').to_owned();
    let fallback="https://mkultra.monster/img/avatar/default.webp";
    let (display,handle,avatar)=if !actor.starts_with("https://") {
        // An anonymous DB row must never regain identity from a stale snapshot.
        ask["asker_actor"]=serde_json::json!("");("Anonymous".into(),String::new(),fallback.into())
    }else if let Some(row)=db.query_opt("SELECT u.actor_key,p.name,p.icon_url FROM ap_users u LEFT JOIN actor_profile p ON p.actor_key=u.actor_key WHERE $2 AND u.disabled_at IS NULL AND (rtrim(u.actor_id,'/')=$1 OR lower(u.actor_key)=lower($3)) LIMIT 1",&[&actor,&actor.starts_with("https://mkultra.monster/users/"),&crate::hidden::raw_percent_decode(actor.rsplit('/').next().unwrap_or(""))]).await?{
        let key:String=row.get(0);let name=row.get::<_,Option<String>>(1).unwrap_or_default();let icon=row.get::<_,Option<String>>(2).unwrap_or_default();
        let display=if name.is_empty(){if key.is_empty(){"Local user".into()}else{key.clone()}}else{name};
        (super::card::php_trim(&display).to_owned(),if key.is_empty(){String::new()}else{format!("@{key}@mkultra.monster")},if icon.starts_with("https://"){icon}else{"https://mkultra.monster/img/avatar/local-default.webp".into()})
    }else{
        let row=db.query_opt("SELECT to_jsonb(a) FROM remote_actors a WHERE actor_id=$1 OR actor_id=$2 LIMIT 1",&[&actor,&format!("{actor}/")]).await?.context("PHP fallback: Ask identity needs canonical async actor warming")?;
        let row:Value=row.get(0);let username=super::card::php_trim(super::card::php_trim(s(&row,"username")).trim_start_matches('@').split('@').next().unwrap_or(""));
        if username.is_empty()||username=="user"||username.to_lowercase().starts_with("did:")||username.to_lowercase().starts_with("did%3a")||(username.len()>=6&&username.bytes().all(|b|b.is_ascii_digit())){anyhow::bail!("PHP fallback: Ask placeholder identity needs canonical async actor warming");}
        let actor_host=if s(&row,"host").is_empty(){host(&actor)}else{s(&row,"host").to_lowercase()};
        let display=super::card::php_trim(if super::card::php_trim(s(&row,"display_name")).is_empty(){username}else{s(&row,"display_name")}).to_owned();
        let handle=if actor_host.ends_with("brid.gy")&&username.contains('.') {format!("@{username}")}else if actor_host.is_empty(){format!("@{username}")}else{format!("@{username}@{actor_host}")};
        let special=matches!(actor.as_str(),"https://waffles.baeddel.social/fediverse/blog/admin"|"https://app.wafrn.net/fediverse/blog/admin");
        let mut icon=if special{"https://mkultra.monster/img/avatar/wafrn-approvals.webp".into()}else{s(&row,"icon_source_url").to_owned()};
        if icon.is_empty(){if let Some(row)=db.query_opt("SELECT public_url FROM remote_media_cache WHERE actor_id=$1 AND kind='avatar' LIMIT 1",&[&actor]).await?{icon=row.get(0);}}
        (display,handle,if icon.starts_with("https://"){icon}else{fallback.into()})
    };
    ask["asker_display"]=serde_json::json!(display);ask["asker_handle"]=serde_json::json!(handle);ask["asker_avatar"]=serde_json::json!(avatar);
    // Shared Library painter calls ap_ask_card_html_from_row(row,false). The
    // answer remains in the normal body, never duplicated inside this card.
    ask["_body_answer"]=ask.get("answer").or_else(||ask.get("ask_answer")).cloned().unwrap_or(Value::Null);
    ask["answer"]=serde_json::json!("");
    Ok(ask)
}

pub(crate) async fn resolve(db: &Client, owner_id: i64, owner_actor: &str, status: &Value, session: &impl PresentationSession, auto_unblur: bool) -> Result<CardFacts> {
    #[cfg(test)] let _timing=crate::search_context::selection::fixture_performance::span("card_facts",1);
    let owner = db.query_one("SELECT actor_key,COALESCE(is_admin,0)::bigint FROM ap_users WHERE id::bigint=$1 AND disabled_at IS NULL", &[&owner_id]).await?;
    let key: String = owner.get(0); let admin = owner.get::<_,i64>(1) != 0;
    let st = status.get("reblog").filter(|v| v.is_object() && s(status,"content").is_empty()).unwrap_or(status);
    let actor_ref=super::card::account_actor_ref(&st["account"]);
    let actor=actor_ref.trim_end_matches('/'); let uri = s(st,"uri").trim_end_matches('/');
    let local = regex::Regex::new(r"^https://mkultra\.monster/users/[A-Za-z0-9_]+(?:/|$)").unwrap().is_match(actor);
    let mut moderation = ModerationContext { is_self: actor == owner_actor.trim_end_matches('/'), is_local: local, is_admin:admin, ..Default::default() };
    if !moderation.is_self && host(actor)=="bsky.app" {
        if let Some(row)=db.query_opt("SELECT did,handle FROM bsky_sessions WHERE owner_user_id::bigint=$1 LIMIT 1",&[&owner_id]).await? {
            let path=reqwest::Url::parse(actor).ok().map(|url|url.path().trim_matches('/').strip_prefix("profile/").unwrap_or("").to_owned()).unwrap_or_default();
            let did:String=row.get(0);let handle:String=row.get(1);
            moderation.is_self=!path.is_empty()&&(path.eq_ignore_ascii_case(&did)||path.eq_ignore_ascii_case(&handle));
        }
    }
    moderation.is_local_admin = db.query_one("SELECT EXISTS(SELECT 1 FROM ap_users WHERE (rtrim(actor_id,'/')=$1 OR actor_key=$1) AND COALESCE(is_admin,0)::bigint=1)",&[&actor]).await?.get(0);
    let rows = db.query("SELECT to_jsonb(b) FROM ap_user_blocks b WHERE owner_user_id::bigint=$1 ORDER BY created_at DESC,id DESC", &[&owner_id]).await?.iter().map(|r| r.get::<_,Value>(0)).collect::<Vec<_>>();
    moderation.personal_block_id = personal_block(&rows,actor).map(|row| n(row,"id"));
    for (table,target) in [("ap_mutes", &mut moderation.muted),("ap_deprioritized_actors", &mut moderation.deprioritized)] {
        let rows = db.query(&format!("SELECT actor_id FROM {table} WHERE owner_user_id::bigint=$1"), &[&owner_id]).await?;
        *target = rows.iter().any(|r| if table == "ap_deprioritized_actors" { actor == r.get::<_,String>(0).trim_end_matches('/') } else { aliases_match(actor,&r.get::<_,String>(0)) });
    }
    if admin && !local {
        for row in db.query("SELECT to_jsonb(b) FROM ap_blocks b WHERE scope='actor' ORDER BY id DESC",&[]).await? {
            let row:Value=row.get(0); if aliases_match(actor,s(&row,"value")) { moderation.global_kind=s(&row,"kind").into(); moderation.global_id=n(&row,"id"); break; }
        }
    }
    moderation.is_wafrn = wafrn_host_known(db,actor).await?;
    let poll_row = db.query_opt("SELECT to_jsonb(p) FROM masto_polls p WHERE rtrim(note_id,'/')=$1 LIMIT 1", &[&uri]).await?.map(|r| r.get(0));
    let local_ask=db.query_opt("SELECT to_jsonb(a) FROM ap_asks a WHERE rtrim(answer_note_id,'/')=$1 ORDER BY id DESC LIMIT 1", &[&uri]).await?.map(|r|r.get::<_,Value>(0));
    let ask_row=if let Some(mut ask)=local_ask {
        // ap_asks has no answer column. A local answer is the current stored
        // note body, never an old status.vaak_ask.answer override.
        let current=db.query_opt("SELECT content_text FROM masto_statuses WHERE rtrim(note_id,'/')=$1 LIMIT 1",&[&uri]).await?.context("PHP fallback: local Ask answer requires current canonical note normalization")?;
        let answer:String=current.get(0);
        if super::card::php_trim(&answer).is_empty()||matches!(answer.as_str(),"(quote)"|"(media)"|"(poll)"){anyhow::bail!("PHP fallback: local Ask placeholder answer needs canonical source recovery");}
        ask["answer"]=serde_json::json!(answer);Some(ask_identity_with_context(db,ask,session.warm_context()).await?)
    }else if let Some(row)=db.query_opt("SELECT ask_actor,ask_question,ask_answer FROM mentions WHERE rtrim(object_id,'/')=$1 AND deleted_at IS NULL AND ask_question<>'' ORDER BY id DESC LIMIT 1",&[&uri]).await? {
        Some(ask_identity_with_context(db,serde_json::json!({"asker_actor":row.get::<_,Option<String>>(0).unwrap_or_default(),"question":row.get::<_,String>(1),"answer":row.get::<_,Option<String>>(2).unwrap_or_default()}),session.warm_context()).await?)
    }else if st.get("vaak_ask").is_some_and(Value::is_object) {
        anyhow::bail!("PHP fallback: compact Ask source needs canonical current parse/normalization");
    }else{None};
    let mut own = None;
    if uri.starts_with(&format!("{}/notes/", owner_actor.trim_end_matches('/'))) {
        let row = db.query_opt("SELECT to_jsonb(s), EXISTS(SELECT 1 FROM masto_pins p WHERE p.status_local_id=s.local_id AND p.owner_user_id::bigint=$2) FROM masto_statuses s WHERE rtrim(s.note_id,'/')=$1 LIMIT 1", &[&uri,&owner_id]).await?.context("PHP fallback: own status row missing")?;
        let stored:Value = row.get(0); let id=n(&stored,"local_id");
        let status_id=own_status_id(st,&stored)?;
        own=Some(super::own::OwnFacts { local_id:id,status_id:status_id.clone(),plain:s(&stored,"content_text").into(),spoiler:s(&stored,"spoiler_text").into(),sensitive:flag(&stored,"sensitive")||!super::card::php_trim(s(&stored,"spoiler_text")).is_empty(),visibility:s(&stored,"visibility").into(),pinned:row.get(1),counts:local_counts(db,uri,&status_id).await? });
        moderation.is_self=true;
    }
    let rss_quote_row=if let Some(quote)=st.get("quote").and_then(|q|q.get("quoted_status")).filter(|q|q.is_object()) {
        let refs=[s(quote,"url"),s(quote,"uri"),s(quote,"id")].iter().map(|s|s.trim_end_matches('/').to_owned()).filter(|s|s.starts_with("https://")).collect::<Vec<_>>();
        let synthetic=s(quote,"id").strip_prefix("rss:").and_then(|v|v.parse::<i64>().ok()).filter(|id|*id>0);
        if let Some(id)=synthetic {
            db.query_opt("SELECT to_jsonb(i)||jsonb_build_object('feed_title',f.title,'feed_favicon',f.favicon_url,'feed_site_url',f.site_url,'feed_url',f.feed_url,'owner_user_id',f.owner_user_id,'feed_id',f.id) FROM rss_items i JOIN rss_feeds f ON f.id=i.feed_id WHERE i.id::bigint=$1 AND f.owner_user_id::bigint=$2 LIMIT 1",&[&id,&owner_id]).await?.map(|r|r.get::<_,Value>(0))
        }else         if refs.is_empty(){None}else{db.query_opt("SELECT to_jsonb(i)||jsonb_build_object('feed_title',f.title,'feed_favicon',f.favicon_url,'feed_site_url',f.site_url,'feed_url',f.feed_url,'owner_user_id',f.owner_user_id,'feed_id',f.id) FROM rss_items i JOIN rss_feeds f ON f.id=i.feed_id WHERE rtrim(i.mirror_note_id,'/')=ANY($1) AND f.owner_user_id::bigint=$2 LIMIT 1",&[&refs,&owner_id]).await?.map(|r|r.get::<_,Value>(0))}
    }else{None};
    let rss_quoted=if let Some(id)=s(st,"id").strip_prefix("rss:"){db.query_one("SELECT EXISTS(SELECT 1 FROM masto_reblogs WHERE owner_user_id::bigint=$1 AND status_id=$2)",&[&owner_id,&format!("rss-quote:{id}")]).await?.get(0)}else{false};
    // PHP generic cards refresh engagement for note-shaped object URLs even
    // when the viewer does not own that note. Counts grant no audience access.
    let sid=super::card::scalar_string(st,"id");
    let sid=super::card::php_trim(&sid);
    let current_action_counts=if let Some(own)=own.as_ref(){Some(own.counts.clone())}else if uri.contains("/notes/"){Some(local_counts(db,uri,if !sid.is_empty()&&sid.bytes().all(|byte|byte.is_ascii_digit()){sid}else{""}).await?)}else{None};
    Ok(CardFacts { current_action_counts, bsky:super::bsky::resolve_with_bookmark_state(db,owner_id,st,session.bsky_enabled(),session.bsky_bookmark_state(st)).await?, owner_id,owner_actor:owner_actor.into(),return_view:session.return_view().into(),auto_unblur_sensitive:auto_unblur,resolved_avatar:avatar_with_context(db,st,actor,session.warm_context()).await?,csrf:session.csrf().into(),favourited:flag(st,"favourited"),bookmarked:flag(st,"bookmarked"),boosted:flag(st,"reblogged"),moderation,poll_row,ask_row,now:chrono::Utc::now(),lazy_media:session.lazy_media(),own,reply_mentions:reply_seeds(st,owner_actor,&key),rss_row:None,rss_quote_row,rss_quoted,emoji_by_actor:emoji_maps(db,status).await? })
}

#[cfg(test)] mod tests {
    use super::*;
    #[tokio::test]
    #[ignore = "requires independent genuine helper capture and disposable PostgreSQL"]
    async fn genuine_production_core_helpers_match_actual_php() {
        let path=std::env::var("VAAK_GENUINE_CORE_CAPTURE").expect("independent PHP capture path");
        let reference:Value=serde_json::from_slice(&std::fs::read(path).unwrap()).unwrap();
        let url=std::env::var("LIBRARY_FIXTURE_DB").expect("disposable PostgreSQL URL");
        assert!(url.contains("/tmp/"),"disposable Unix socket required");
        let db=crate::db::connect(&url).await.unwrap();
        let mut output=Vec::new(); let mut differences=Vec::new();
        for width in ["INTEGER","BIGINT"] {
            let schema=format!("direct_native_core_sql_{}",width.to_ascii_lowercase());
            db.batch_execute(&format!("DROP SCHEMA IF EXISTS {schema} CASCADE;CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            let cases=reference["captures"].as_array().unwrap().iter().filter(|case|s(case,"width")==width).collect::<Vec<_>>();
            let declarations=cases[0]["current_schema"].as_object().unwrap();
            for (table,columns) in declarations {
                assert!(table.bytes().all(|b|b.is_ascii_alphanumeric()||b==b'_'));
                let definitions=columns.as_array().unwrap().iter().map(|column|{
                    let name=s(column,"name");let kind=s(column,"type");
                    assert!(name.bytes().all(|b|b.is_ascii_alphanumeric()||b==b'_'));
                    assert!(matches!(kind,"INTEGER"|"BIGINT"|"TEXT"),"actual schema type {kind}");
                    format!("{name} {kind}")
                }).collect::<Vec<_>>().join(",");
                db.batch_execute(&format!("CREATE TABLE {table}({definitions})")).await.unwrap();
            }
            for case in cases {
                for (table,rows) in case["current_tables"].as_object().unwrap() {
                    db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();
                    for row in rows.as_array().unwrap() { db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap(); }
                }
                let source=&case["source"]; let name=s(case,"case");
                let actual=if case["php"].get("favourites").is_some() {
                    serde_json::to_value(local_counts(&db,s(source,"note_id"),s(source,"status_id")).await.unwrap()).unwrap()
                } else if name.starts_with("ask_identity_") {
                    let row=ask_identity(&db,serde_json::json!({"asker_actor":s(source,"actor")})).await.unwrap();
                    serde_json::json!({"display_name":row["asker_display"],"handle":row["asker_handle"],"avatar":row["asker_avatar"]})
                } else {continue};
                if actual!=case["php"] {differences.push(format!("{width}/{name}: native={actual} php={}",case["php"]));}
                output.push(serde_json::json!({"width":width,"case":name,"source":source,"php":case["php"],"native":actual}));
            }
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }
        std::fs::write("/tmp/vaak-remote-direct-core-sql-native-results.json",serde_json::to_vec(&output).unwrap()).unwrap();
        assert_eq!(output.len(),28,"20 nonzero/edge count and eight current Ask identity captures");
        assert!(differences.is_empty(),"{}",differences.join("\n"));
    }
    #[tokio::test]
    #[ignore = "requires genuine optional-quote PHP captures and owned PostgreSQL"]
    async fn optional_event_quote_column_matches_genuine_php() {
        let path=std::env::var("LIBRARY_OPTIONAL_QUOTE_CAPTURE").expect("actual PHP capture path");
        let reference:Value=serde_json::from_slice(&std::fs::read(path).unwrap()).unwrap();
        let url=std::env::var("LIBRARY_FIXTURE_DB").expect("owned PostgreSQL URL");
        assert!(url.contains("/tmp/"));
        let db=crate::db::connect(&url).await.unwrap();
        let mut output=Vec::new();
        for (index,case) in reference["captures"].as_array().unwrap().iter().enumerate() {
            let schema=format!("library_optional_quote_native_{}_{}",std::process::id(),index);
            db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for (table,columns) in case["current_schema"].as_object().unwrap() {
                assert!(table.bytes().all(|b|b.is_ascii_alphanumeric()||b==b'_'));
                let definitions=columns.as_array().unwrap().iter().map(|column| {
                    let name=s(column,"name");let kind=s(column,"type");
                    assert!(name.bytes().all(|b|b.is_ascii_alphanumeric()||b==b'_'));
                    assert!(matches!(kind,"INTEGER"|"BIGINT"|"TEXT"));format!("{name} {kind}")
                }).collect::<Vec<_>>().join(",");
                db.batch_execute(&format!("CREATE TABLE {table}({definitions})")).await.unwrap();
            }
            for (table,rows) in case["current_tables"].as_object().unwrap() {
                for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}
            }
            let source=&case["source"];
            let actual=serde_json::to_value(local_counts(&db,s(source,"note_id"),s(source,"status_id")).await.unwrap()).unwrap();
            output.push(serde_json::json!({"width":case["width"],"case":case["case"],"php":case["php"],"native":actual}));
            // Missing mandatory action state must remain an error, even after
            // the optional-column retry; no zero-facts authority is fabricated.
            db.batch_execute("ALTER TABLE masto_favourites RENAME COLUMN status_id TO unavailable_status_id").await.unwrap();
            let required_failed=local_counts(&db,s(source,"note_id"),s(source,"status_id")).await.is_err();
            db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
            assert_eq!(actual,case["php"],"{}/{}",case["width"],case["case"]);
            assert!(required_failed,"required count column became optional");
        }
        let output_path=std::env::var("LIBRARY_OPTIONAL_QUOTE_OUTPUT").expect("owned output path");
        std::fs::write(output_path,serde_json::to_vec_pretty(&output).unwrap()).unwrap();
        assert_eq!(output.len(),12);
    }

    #[test]
    fn quote_counts_read_indexed_targets() {
        let sql = QUOTE_TARGET_COUNT_SQL.to_ascii_lowercase();
        assert!(!sql.contains("like"), "{QUOTE_TARGET_COUNT_SQL}");
        assert!(sql.contains("event_quote_targets"));
        assert!(sql.contains("quote_target=$1"));
        assert!(sql.contains("is distinct from $2"));
        assert!(sql.contains("is distinct from $3"));
    }
    #[test] fn own_action_ids_match_actual_php_snowflake_and_preserve_source() {
        let root=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../..");
        let code=r#"require $argv[1].'/api/bin/migration-reference-functions.php';reference_load($argv[1].'/api/ap-masto-entities.php',['ap_masto_snowflake_id']);echo json_encode([ap_masto_snowflake_id('2026-10-10T12:00:00Z',9,0),ap_masto_snowflake_id('2026-10-10T14:00:00+02:00',100000009,0)]);"#;
        let result=std::process::Command::new(root.join("api/bin/php-policy-test")).args(["-r",code]).arg(&root).output().unwrap();
        assert!(result.status.success(),"{}",String::from_utf8_lossy(&result.stderr));
        let expected:Value=serde_json::from_slice(&result.stdout).unwrap();
        let stored=serde_json::json!({"local_id":9,"published":"2026-10-10T12:00:00Z"});
        for id in [Value::Null,serde_json::json!("opaque"),serde_json::json!("")] {assert_eq!(own_status_id(&serde_json::json!({"id":id}),&stored).unwrap(),expected[0].as_str().unwrap());}
        assert_eq!(own_status_id(&serde_json::json!({"id":"0009"}),&stored).unwrap(),"0009");
        assert_eq!(own_status_id(&serde_json::json!({"id":9}),&stored).unwrap(),"9");
        let stored=serde_json::json!({"local_id":100000009,"published":"2026-10-10T14:00:00+02:00"});
        assert_eq!(own_status_id(&serde_json::json!({"id":"opaque"}),&stored).unwrap(),expected[1].as_str().unwrap());
        for published in ["","malformed"] {assert!(own_status_id(&serde_json::json!({"id":"opaque"}),&serde_json::json!({"local_id":9,"published":published})).is_err());}
    }
    #[test] fn block_domains_include_subdomains_and_actor_aliases() {
        let rows = vec![serde_json::json!({"id":7,"scope":"domain","value":"remote.test"})];
        assert!(personal_block(&rows,"https://sub.remote.test/users/a").is_some()); assert!(personal_block(&rows,"https://evilremote.test/users/a").is_none());
        let rows = vec![serde_json::json!({"id":8,"scope":"actor","value":"https://remote.test/@Alice"})]; assert!(personal_block(&rows,"https://remote.test/users/Alice/").is_some()); assert!(personal_block(&rows,"https://remote.test/users/alice/").is_none());
    }
    #[test] fn reply_seeds_include_participants_and_drop_self_duplicates() {
        let st=serde_json::json!({"account":{"uri":"https://remote.test/users/a","acct":"a@remote.test"},"mentions":[{"acct":"nova@mkultra.monster","url":"https://mkultra.monster/users/nova"},{"acct":"B@remote.test"},{"acct":"b@remote.test"}]});
        assert_eq!(reply_seeds(&st,"https://mkultra.monster/users/nova","nova"), vec!["a@remote.test","B@remote.test"]);
    }
}
