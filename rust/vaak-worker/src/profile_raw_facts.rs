//! Current-SQL facts for the actual authenticated raw-outbox profile dialect.
//! Source/audience admission and final publication checks are the caller's
//! responsibility. This module does not manufacture a Mastodon status or token.
//! Contract: ap-admin.php::admin_render_outbox_card and its own-action helpers.
use anyhow::{bail,Context,Result};
use serde_json::{json,Value};
use std::collections::HashMap;
use tokio_postgres::Client;
use crate::library::{facts::{self,PresentationSession},card::{self,CardFacts},actions::ModerationContext,own::OwnFacts};
use crate::profile_context::AuthenticatedProfileSession;
use crate::profile_raw::RawProfileFacts;
fn text<'a>(v:&'a Value,key:&str)->&'a str{v.get(key).and_then(Value::as_str).unwrap_or("")}
fn own_url(note:&str,actor:&str)->bool{let note=note.trim_end_matches('/');note==actor||note.starts_with(&format!("{actor}/"))}
fn snowflake(iso:&str,id:i64,kind:i64,now:chrono::DateTime<chrono::Utc>)->Result<String>{
 let seconds=chrono::DateTime::parse_from_rfc3339(iso).map(|v|v.timestamp())
  .or_else(|_|chrono::NaiveDateTime::parse_from_str(iso,"%Y-%m-%d %H:%M:%S").map(|v|v.and_utc().timestamp()))
  .or_else(|_|chrono::DateTime::parse_from_rfc2822(iso).map(|v|v.timestamp()));
 let seconds=if iso.is_empty(){now.timestamp()}else{seconds.context("PHP fallback: raw status publication timestamp grammar")?};
 Ok(seconds.checked_mul(1_000_000_000).and_then(|v|v.checked_add((kind%10)*100_000_000)).and_then(|v|v.checked_add(id%100_000_000)).context("raw action status ID overflow")?.to_string())
}
async fn canonical_own_sid(db:&Client,note:&str,now:chrono::DateTime<chrono::Utc>)->Result<String>{
 let note=card::php_trim(note).trim_end_matches('/');let slash=format!("{note}/");
 if let Some(row)=db.query_opt("SELECT to_jsonb(s) FROM masto_statuses s WHERE note_id=$1 OR note_id=$2 LIMIT 1",&[&note,&slash]).await?{
  let row:Value=row.get(0);let id=card::integer(&row,"local_id");if id!=0{return snowflake(text(&row,"published"),id,0,now);}
 }
 // Actual ap_event_by_object_id order, not latest arbitrary Announce/Like.
 if let Some(row)=db.query_opt("SELECT to_jsonb(e) FROM events e WHERE (object_id=$1 OR object_id=$2) AND COALESCE(action_taken,'')<>'deleted' ORDER BY CASE type WHEN 'Create' THEN 0 WHEN 'Update' THEN 1 WHEN 'Announce' THEN 10 WHEN 'Like' THEN 11 ELSE 5 END,id DESC LIMIT 1",&[&note,&slash]).await?{
  let row:Value=row.get(0);let id=card::integer(&row,"id");
  if id!=0&&matches!(text(&row,"type").to_ascii_lowercase().as_str(),"create"|"update"|""){
   let created=text(&row,"created_at");return if created.is_empty(){Ok((3_000_000+id).to_string())}else{snowflake(created,id,1,now)};
  }
 }
 // PHP returns the opaque object key; own action helper only accepts numeric
 // canonical recovery and leaves the engagement action ID empty otherwise.
 Ok(String::new())
}
async fn saved(db:&Client,owner:i64,table:&str,sid:&str,note:&str)->Result<bool>{
 if sid.is_empty(){return Ok(false);}
 let base=card::php_trim(note).trim_end_matches('/');let keys=vec![note.to_owned(),base.to_owned(),format!("{base}/")];
 Ok(db.query_one(&format!("SELECT EXISTS(SELECT 1 FROM {table} WHERE owner_user_id::bigint=$1 AND (status_id=$2 OR object_id=ANY($3)))"),&[&owner,&sid,&keys]).await?.get(0))
}
fn aliases(a:&str,b:&str)->bool{let aliases=crate::hidden::actor_aliases(a);crate::hidden::actor_aliases(b).iter().any(|b|aliases.contains(b))}
pub(crate) async fn resolve_authenticated(db:&Client,session:&AuthenticatedProfileSession,raw:&Value,actor:&str,key:&str,profile:&Value)->Result<RawProfileFacts>{
 let owner=session.owner_id();if owner<1||session.csrf().is_empty(){bail!("raw facts require an authenticated profile session")}
 let identity=db.query_opt("SELECT actor_id,actor_key,COALESCE(is_admin,0)::bigint FROM ap_users WHERE id::bigint=$1 AND disabled_at IS NULL",&[&owner]).await?.context("inactive raw facts owner")?;
 let owner_actor:String=identity.get(0);let owner_key:String=identity.get(1);let is_admin=identity.get::<_,i64>(2)!=0;
 let note=text(raw,"id");if note.is_empty(){bail!("raw note identity absent")}
 let parsed=regex::Regex::new(r"^https://mkultra\.monster/users/([A-Za-z0-9_]+)(?:/|$)").unwrap();
 let capture=parsed.captures(note).context("raw facts require a local selected note")?;
 if capture[1].to_ascii_lowercase()!=key||actor!=format!("https://mkultra.monster/users/{}",&capture[1]){bail!("raw displayed actor/key does not match note identity")}
 let now=chrono::Utc::now();let note_slash=format!("{}/",note.trim_end_matches('/'));
 // Fresh request body helper SELECT has these six fields. Caller-owned PHP
 // request map/memo is not a fresh source grant and is not borrowed here.
 let masto_row=db.query_opt("SELECT jsonb_build_object('note_id',note_id,'local_id',local_id,'content_text',content_text,'spoiler_text',spoiler_text,'sensitive',sensitive,'visibility',visibility) FROM masto_statuses WHERE note_id=$1 OR note_id=$2 LIMIT 1",&[&note,&note_slash]).await?.map(|r|r.get::<_,Value>(0));
 let status_id=if let Some(row)=&masto_row{let id=card::integer(row,"local_id");if id!=0{snowflake(text(raw,"published"),id,0,now)?}else{String::new()}}else{String::new()};
 let doc:Value=serde_json::from_str(text(raw,"raw_create_json")).unwrap_or(Value::Null);let obj=doc.get("object").unwrap_or(&doc);
 let imported=text(obj,"vaakOrigin")=="bluesky";let own_note=own_url(note,&owner_actor)&&!imported;
 let mut plain=crate::library::body::html_to_plain(text(raw,"content"));
 let mut spoiler=if card::boolean(obj,"summary"){card::php_trim(&crate::home_hydrate_ranked::html_entity_decode(&regex::Regex::new(r"(?s)<[^>]*>").unwrap().replace_all(text(obj,"summary"),""))).to_owned()}else{String::new()};
 let mut sensitive=card::boolean(obj,"sensitive")||!spoiler.is_empty();let mut vis=text(raw,"visibility").to_owned();if vis.is_empty(){vis="public".into();}
 if let Some(row)=&masto_row{
  let body=text(row,"content_text");if !body.is_empty()&&!matches!(body,"(quote)"|"(media)"|"(poll)"){plain=body.into();}
  if !row["spoiler_text"].is_null(){spoiler=card::php_trim(text(row,"spoiler_text")).into();}
  sensitive=card::boolean(row,"sensitive")||!spoiler.is_empty();
  if vis=="public"&&!text(row,"visibility").is_empty(){vis=text(row,"visibility").into();}
 }
 let action_sid=if own_note&&status_id.is_empty(){canonical_own_sid(db,note,now).await?}else{status_id.clone()};
 let favourited=saved(db,owner,"masto_favourites",if own_note{&action_sid}else{&status_id},note).await?;
 let bookmarked=saved(db,owner,"masto_bookmarks",if own_note{&action_sid}else{&status_id},note).await?;
 // PHP explicitly passes boosted using the original raw srow status ID;
 // canonical own recovery must not change this incoming-copy flag.
 let boosted=crate::library::context::exact_incoming_boost(db,owner,&status_id).await?;
 let own=if own_note{
  let id=masto_row.as_ref().map(|r|card::integer(r,"local_id")).unwrap_or(0);
  let pinned=if id>0{db.query_one("SELECT EXISTS(SELECT 1 FROM masto_pins WHERE status_local_id::bigint=$1 AND owner_user_id::bigint=$2)",&[&id,&owner]).await?.get(0)}else{false};
  Some(OwnFacts{local_id:id,status_id:action_sid.clone(),plain:if let Some(row)=&masto_row{text(row,"content_text").into()}else if plain=="(quote)"{String::new()}else{plain.clone()},spoiler:spoiler.clone(),sensitive,visibility:vis,pinned,counts:facts::local_counts(db,note,&action_sid).await?})
 }else{None};
 let mut moderation=ModerationContext{is_self:actor.trim_end_matches('/')==owner_actor.trim_end_matches('/')||own_url(note,&owner_actor),is_local:true,is_admin,..Default::default()};
 moderation.is_local_admin=actor.trim_end_matches('/')==owner_actor.trim_end_matches('/')||db.query_one("SELECT EXISTS(SELECT 1 FROM ap_users WHERE (rtrim(actor_id,'/')=$1 OR actor_key=$1) AND COALESCE(is_admin,0)::bigint=1)",&[&actor.trim_end_matches('/')]).await?.get(0);
 let blocks=db.query("SELECT to_jsonb(b) FROM ap_user_blocks b WHERE owner_user_id::bigint=$1 ORDER BY created_at DESC,id DESC",&[&owner]).await?.iter().map(|r|r.get::<_,Value>(0)).collect::<Vec<_>>();
 moderation.personal_block_id=facts::personal_block(&blocks,actor).map(|r|card::integer(r,"id"));
 for(table,target)in[("ap_mutes",&mut moderation.muted),("ap_deprioritized_actors",&mut moderation.deprioritized)]{
  *target=db.query(&format!("SELECT actor_id FROM {table} WHERE owner_user_id::bigint=$1"),&[&owner]).await?.iter().any(|r|{let v:String=r.get(0);if table=="ap_mutes"{aliases(actor,&v)}else{actor.trim_end_matches('/')==v.trim_end_matches('/')}});
 }
 let poll_row=db.query_opt("SELECT to_jsonb(p) FROM masto_polls p WHERE note_id=$1 OR note_id=$2 LIMIT 1",&[&note.trim_end_matches('/'),&note_slash]).await?.map(|r|r.get::<_,Value>(0));
 let ask_row=if let Some(row)=db.query_opt("SELECT to_jsonb(a) FROM ap_asks a WHERE answer_note_id=$1 OR answer_note_id=$2 ORDER BY id DESC LIMIT 1",&[&note.trim_end_matches('/'),&note_slash]).await?{Some(facts::ask_identity_with_context(db,row.get(0),session.warm_context()).await?)}else{None};
 // to_jsonb avoids INTEGER/BIGINT/boolean schema-width decoding assumptions.
 let auto=db.query_opt("SELECT to_jsonb(p)->'auto_unblur_sensitive' FROM actor_profile p WHERE actor_key=$1",&[&owner_key]).await?.map(|r|r.get::<_,Option<Value>>(0)).flatten().is_some_and(|v|card::boolean(&json!({"value":v}),"value"));
 let mut emoji=HashMap::new();for row in db.query("SELECT shortcode,url FROM remote_custom_emojis WHERE host='mkultra.monster'",&[]).await?{let code:String=row.get(0);let url:String=row.get(1);if !code.is_empty()&&url.starts_with("https://"){emoji.insert(code,url);}}
 let mut emoji_by_actor=HashMap::new();emoji_by_actor.insert(actor.to_owned(),emoji);
 let mut media_preview_by_url=HashMap::new();let atts=obj.get("attachment");let atts=match atts{Some(Value::Array(v))=>v.clone(),Some(v)if v.get("type").is_some_and(|v|!v.is_null())=>vec![v.clone()],_=>vec![]};
 for att in atts{let url=att.get("url").and_then(Value::as_str).or_else(||att.get("url").and_then(|v|v.get("href")).and_then(Value::as_str)).unwrap_or("");if url.starts_with("https://"){if let Some(row)=db.query_opt("SELECT preview_url FROM masto_media WHERE public_url=$1 AND preview_url IS NOT NULL LIMIT 1",&[&url]).await?{let preview:String=row.get(0);media_preview_by_url.insert(url.into(),preview);}}}
 let note_dom_id:String=db.query_one("SELECT md5($1)",&[&note]).await?.get(0);
 Ok(RawProfileFacts{card:CardFacts{bsky:None,owner_id:owner,owner_actor,return_view:session.return_view().into(),auto_unblur_sensitive:auto,resolved_avatar:facts::avatar_with_context(db,&Value::Null,actor,session.warm_context()).await?,csrf:session.csrf().into(),favourited,bookmarked,boosted,own,current_action_counts:None,rss_row:None,rss_quote_row:None,rss_quoted:false,emoji_by_actor,reply_mentions:vec![],moderation,poll_row,ask_row,now,lazy_media:session.lazy_media()},actor_key:key.into(),actor:actor.into(),display_name:card::php_trim(text(profile,"name")).into(),note_dom_id,masto_row,status_id,own_note,guest:false,quote_html:String::new(),link_card:None,media_preview_by_url})
}

#[cfg(test)]
mod tests{
 use super::*;
 struct WarmRecorder(std::sync::Mutex<Vec<String>>);
 impl crate::profile_warm::WarmTransport for WarmRecorder{
  fn actor_process<'a>(&'a self,actor:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=bool>+Send+'a>>{Box::pin(async move{self.0.lock().unwrap().push(actor.into());true})}
  fn media_process(&self)->std::pin::Pin<Box<dyn std::future::Future<Output=bool>+Send+'_>>{Box::pin(async move{panic!("local raw fixture must not warm media")})}
 }
 fn export(f:&RawProfileFacts)->Value{
  let own=f.card.own.as_ref().map(|o|json!({"local_id":o.local_id,"status_id":o.status_id,"plain":o.plain,"spoiler":o.spoiler,"sensitive":o.sensitive,"visibility":o.visibility,"pinned":o.pinned,"counts":o.counts}));
  json!({"owner_id":f.card.owner_id,"owner_actor":f.card.owner_actor,"return_view":f.card.return_view,"auto_unblur_sensitive":f.card.auto_unblur_sensitive,"resolved_avatar":f.card.resolved_avatar,"csrf":f.card.csrf,"favourited":f.card.favourited,"bookmarked":f.card.bookmarked,"boosted":f.card.boosted,"own":own,"lazy_media":f.card.lazy_media,"actor_key":f.actor_key,"actor":f.actor,"display_name":f.display_name,"note_dom_id":f.note_dom_id,"masto_row":f.masto_row,"status_id":f.status_id,"own_note":f.own_note,"guest":f.guest,"quote_html":f.quote_html,"link_card":f.link_card,"media_preview_by_url":f.media_preview_by_url,"emoji_by_actor":f.card.emoji_by_actor,"poll_row":f.card.poll_row,"ask_row":f.card.ask_row,"moderation":f.card.moderation})
 }
 #[tokio::test]
 #[ignore="requires genuine cold current PHP raw captures and isolated PostgreSQL"]
 async fn actual_authenticated_raw_facts_pg_replay()->Result<()>{
  let url=std::env::var("PROFILE_FIXTURE_DB")?;anyhow::ensure!(url.contains("/tmp/"),"isolated socket required");
  let reference:Value=serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_RAW_FACTS_REFERENCE")?)?)?;
  let db=crate::db::connect(&url).await?;let mut output=Vec::new();
  for width in ["INTEGER","BIGINT"]{
   let schema=format!("profile_raw_auth_facts_{}_{}",width.to_ascii_lowercase(),std::process::id());
   db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema};SET synchronous_commit=off")).await?;
   let result:Result<()>=async{
    for(table,columns)in reference["current_schema_by_width"][width].as_object().context("genuine schema absent")?{
     anyhow::ensure!(table.bytes().all(|b|b.is_ascii_lowercase()||b==b'_'),"fixture table grammar");
     let defs=columns.as_array().context("columns absent")?.iter().map(|c|format!("{} {}",text(c,"column_name"),text(c,"data_type"))).collect::<Vec<_>>().join(",");
     db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await?;
    }
    for capture in reference["captures"].as_array().context("captures absent")?.iter().filter(|c|c["width"]==width&&card::integer(&c["context"],"owner")>0){
     for(table,rows)in capture["current_tables"].as_object().context("current rows absent")?{db.batch_execute(&format!("DELETE FROM {table}")).await?;for row in rows.as_array().context("rows absent")?{db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await?;}}
     let ctx=&capture["context"];let owner=card::integer(ctx,"owner");let actor=text(ctx,"actor");let key=text(ctx,"actor_key");
     let mut session=AuthenticatedProfileSession::from_php_session(owner,text(ctx,"csrf").into(),key.into(),actor.into(),false,0)?;
     let recorder=std::sync::Arc::new(WarmRecorder(std::sync::Mutex::new(Vec::new())));
     let warm=std::env::var("PROFILE_RAW_FACTS_WARM").as_deref()==Ok("1");
     if warm{
      anyhow::ensure!(std::env::var("VAAK_AP_ACTOR_WARM_RUST_PRIMARY").as_deref()==Ok("0"),"explicit primary-off recorder required; no real workers");
      let cfg=crate::config::Config{database_url:String::new(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:1,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
      session=session.with_warm_context(std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg,recorder.clone())));
     }
     let raw=&capture["source"];let profile=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&key]).await?.context("actual target profile missing")?.get::<_,Value>(0);
     let resolved=resolve_authenticated(&db,&session,raw,actor,key,&profile).await;
     match resolved{
      Ok(mut facts)=>{
       // The clock is an explicit genuine fixture substitution only. No SQL
       // facts or expected HTML are copied into the native factory/painter.
       facts.card.now=chrono::DateTime::from_timestamp(card::integer(ctx,"rendered_at"),0).context("clock absent")?;
       let html=crate::profile_raw::render_facts_for_test(raw,&facts)?;
       let actor_warm_requests=recorder.0.lock().unwrap().clone();
       if warm{assert_eq!(json!(actor_warm_requests),capture["php"]["effects"]["fixture_actor_warm_requests"]["value"],"{} {width}",capture["case"]);}
       output.push(json!({"case":capture["case"],"width":width,"source":raw,"facts":export(&facts),"html":html,"actor_warm_requests":actor_warm_requests,"warm_context":warm,"scope":"real current PG auth presentation factory + pure raw card; no source admission/preview IO/HTTP claim"}));
      },
      Err(error)=>output.push(json!({"case":capture["case"],"width":width,"source":raw,"error":format!("{error:#}"),"html":""})),
     }
    }
    // A previously valid PHP session cannot supply stale owner identity after
    // current SQL disables the account. This factory remains presentation-only;
    // the runtime caller separately revalidates its source publication grant.
    let sample=reference["captures"].as_array().unwrap().iter().find(|c|c["width"]==width&&card::integer(&c["context"],"owner")==1).context("owner test source absent")?;
    let ctx=&sample["context"];let actor=text(ctx,"actor");let key=text(ctx,"actor_key");
    let session=AuthenticatedProfileSession::from_php_session(1,text(ctx,"csrf").into(),key.into(),actor.into(),false,0)?;
    db.execute("UPDATE ap_users SET disabled_at='2026-10-11 00:00:00' WHERE id::bigint=1",&[]).await?;
    let error=resolve_authenticated(&db,&session,&sample["source"],actor,key,&Value::Null).await.err().context("disabled owner accepted")?;
    anyhow::ensure!(format!("{error:#}").contains("inactive raw facts owner"),"unexpected disabled owner result: {error:#}");
    Ok(())
   }.await;
   db.batch_execute("SET search_path=public").await?;db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await?;result?;
  }
  std::fs::write(std::env::var("PROFILE_RAW_FACTS_NATIVE_OUTPUT")?,serde_json::to_vec(&json!({"captures":output}))?)?;Ok(())
 }
}
