//! Public presentation facts for a separately admitted profile guest. No owner
//! role, CSRF, saved flags, preferences, session or poll voter list is read.
use anyhow::{Context,Result};
use serde_json::{Value,json};
use tokio_postgres::Client;
fn text<'a>(value:&'a Value,key:&str)->&'a str{value.get(key).and_then(Value::as_str).unwrap_or("")}
pub(crate) async fn resolve(db:&Client,status:&Value,lazy_media:bool)->Result<crate::library::card::CardFacts>{
    resolve_mode(db,status,lazy_media,None,None,false,None).await
}
pub(crate) async fn resolve_raw(db:&Client,status:&Value,lazy_media:bool,current_outbox_answer:&str)->Result<crate::library::card::CardFacts>{
    resolve_mode(db,status,lazy_media,Some(current_outbox_answer),None,false,None).await
}
pub(crate) async fn resolve_with_context(db:&Client,status:&Value,lazy_media:bool,current_outbox_answer:Option<&str>,warm:Option<&crate::profile_enrichment::WarmContext>)->Result<crate::library::card::CardFacts>{resolve_mode(db,status,lazy_media,current_outbox_answer,warm,false,None).await}
/// Guest raw profile cards discard engagement counts after admission. Skip the
/// events/masto/bsky count queries that made a refusal slower than PHP.
pub(crate) struct GuestFactMemo {
    pub polls: std::collections::HashMap<String, Value>,
    pub asks: std::collections::HashMap<String, Value>,
    pub mention_asks: std::collections::HashMap<String, Value>,
}
pub(crate) async fn resolve_public_raw(db:&Client,status:&Value,lazy_media:bool,current_outbox_answer:Option<&str>,warm:Option<&crate::profile_enrichment::WarmContext>,memo:Option<&GuestFactMemo>)->Result<crate::library::card::CardFacts>{resolve_mode(db,status,lazy_media,current_outbox_answer,warm,true,memo).await}
async fn resolve_mode(db:&Client,status:&Value,lazy_media:bool,current_outbox_answer:Option<&str>,warm:Option<&crate::profile_enrichment::WarmContext>,skip_public_counts:bool,memo:Option<&GuestFactMemo>)->Result<crate::library::card::CardFacts>{
    let displayed=status.get("reblog").filter(|child|child.is_object()&&text(status,"content").is_empty()).unwrap_or(status);
    let actor=crate::library::card::account_actor_ref(&displayed["account"]);
    let uri=text(displayed,"uri").trim_end_matches('/');
    let poll_row=if let Some(memo)=memo{memo.polls.get(uri).cloned()}else{db.query_opt("SELECT jsonb_build_object('note_id',note_id,'local_id',local_id,'multiple',multiple,'votes_count',votes_count,'voters_count',voters_count,'expires_at',expires_at,'options_json',options_json) FROM masto_polls WHERE rtrim(note_id,'/')=$1 LIMIT 1",&[&uri]).await?.map(|row|row.get::<_,Value>(0))};
    let local_ask=if let Some(memo)=memo{memo.asks.get(uri).cloned()}else{db.query_opt("SELECT jsonb_build_object('question',question,'asker_actor',asker_actor,'answer_note_id',answer_note_id) FROM ap_asks WHERE rtrim(answer_note_id,'/')=$1 ORDER BY id DESC LIMIT 1",&[&uri]).await?.map(|row|row.get::<_,Value>(0))};
    let ask_row=if let Some(mut ask)=local_ask {
        let current=db.query_opt("SELECT content_text FROM masto_statuses WHERE rtrim(note_id,'/')=$1 LIMIT 1",&[&uri]).await?;
        let answer=if let Some(current)=current {current.get::<_,String>(0)}else{current_outbox_answer.context("PHP fallback: guest Ask requires current shared note body")?.to_owned()};
        ask["answer"]=json!(answer);
        Some(crate::library::facts::ask_identity_with_context(db,ask,warm).await?)
    }else if let Some(memo)=memo{if let Some(ask)=memo.mention_asks.get(uri){Some(crate::library::facts::ask_identity_with_context(db,ask.clone(),warm).await?)}else if displayed.get("vaak_ask").is_some_and(Value::is_object){anyhow::bail!("PHP fallback: guest stale Ask snapshot needs current public metadata")}else{None}}else if let Some(row)=db.query_opt("SELECT ask_actor,ask_question,ask_answer FROM mentions WHERE rtrim(object_id,'/')=$1 AND deleted_at IS NULL AND ask_question<>'' ORDER BY id DESC LIMIT 1",&[&uri]).await?{
        Some(crate::library::facts::ask_identity_with_context(db,json!({"asker_actor":row.get::<_,Option<String>>(0).unwrap_or_default(),"question":row.get::<_,Option<String>>(1).unwrap_or_default(),"answer":row.get::<_,Option<String>>(2).unwrap_or_default()}),warm).await?)
    }else if displayed.get("vaak_ask").is_some_and(Value::is_object){anyhow::bail!("PHP fallback: guest stale Ask snapshot needs current public metadata")}else{None};
    let sid=crate::library::card::scalar_string(displayed,"id");
    let current_action_counts=if skip_public_counts||!uri.contains("/notes/"){None}else{Some(crate::library::facts::local_public_counts(db,uri,&sid).await?)};
    Ok(crate::library::card::CardFacts{bsky:None,owner_id:0,owner_actor:String::new(),return_view:"remote_profile".into(),auto_unblur_sensitive:false,resolved_avatar:crate::library::facts::avatar_with_context(db,displayed,&actor,warm).await?,csrf:String::new(),favourited:false,bookmarked:false,boosted:false,own:None,current_action_counts,rss_row:None,rss_quote_row:None,rss_quoted:false,emoji_by_actor:crate::library::facts::emoji_maps_with_context(db,status,warm).await?,reply_mentions:Vec::new(),moderation:Default::default(),poll_row,ask_row,now:chrono::Utc::now(),lazy_media})
}
