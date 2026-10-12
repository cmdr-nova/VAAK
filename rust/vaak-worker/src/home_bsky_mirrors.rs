//! Home-only fresh local Bluesky mirror provenance. No XRPC or permission cache.
use std::collections::{HashMap, HashSet};
use anyhow::Result;
use serde_json::Value;
use tokio_postgres::Client;
use sha2::{Digest,Sha256};

#[derive(Clone, Debug, Default)]
pub struct Mirror { pub mirror: bool, pub note_id: Option<String> }
fn text<'a>(v:&'a Value,k:&str)->&'a str { v.get(k).and_then(Value::as_str).unwrap_or("") }
fn clean(s:&str)->String { s.trim().trim_end_matches('/').to_owned() }
fn did(uri:&str)->Option<&str> { let (d,k)=uri.strip_prefix("at://")?.split_once("/app.bsky.feed.post/")?; if d.starts_with("did:")&&!k.is_empty()&&!k.contains(['/', '?', '#']) {Some(d)}else{None} }
fn record(p:&Value)->Value { let raw:Value=serde_json::from_str(text(p,"raw_json")).unwrap_or(Value::Null); raw.get("record").or_else(||raw.pointer("/post/record")).or_else(||raw.get("value")).filter(|v|v.is_object()).cloned().unwrap_or(Value::Null) }
async fn load(db:&Client,keys:&[String],posts:&mut HashMap<String,Value>)->Result<()> {
    for chunk in keys.chunks(200) {let keys=chunk.to_vec();for row in db.query("SELECT jsonb_build_object('bsky_uri',p.bsky_uri,'author_did',p.author_did,'reply_root',p.reply_root,'reply_parent',p.reply_parent,'raw_json',p.raw_json,'note_id',c.note_id,'owner_user_id',c.owner_user_id::bigint,'fediverse_id',l.fediverse_id,'ap_object_id',l.ap_object_id) FROM bsky_posts p LEFT JOIN bsky_crossposts c ON c.bsky_uri=p.bsky_uri LEFT JOIN bsky_post_links l ON l.bsky_uri=p.bsky_uri WHERE p.bsky_uri=ANY($1)",&[&keys]).await? {let value:Value=row.get(0);posts.insert(text(&value,"bsky_uri").to_owned(),value);}}
    Ok(())
}
/// Results apply to Home only; independent linked-account Bluesky posts remain.
pub async fn flags(db:&Client,uris:&[String])->Result<HashMap<String,Mirror>> {
    let keys:Vec<String>=uris.iter().map(|s|clean(s)).filter(|s|did(s).is_some()).collect::<HashSet<_>>().into_iter().collect();
    let mut out:HashMap<String,Mirror>=keys.iter().map(|k|(k.clone(),Mirror::default())).collect();if keys.is_empty(){return Ok(out)}
    let mut posts=HashMap::new();load(db,&keys,&mut posts).await?;
    let mut roots:Vec<String>=posts.iter().map(|(uri,p)| {let root=clean(text(p,"reply_root"));if root.is_empty(){uri.clone()}else{root}}).collect::<HashSet<_>>().into_iter().collect();
    roots.sort();
    let mut budget=256i64;
    for chunk in roots.chunks(200) {if budget==0{break}let chunk=chunk.to_vec();let rows=db.query("SELECT p.bsky_uri FROM bsky_posts p WHERE (p.reply_root=ANY($1) OR p.bsky_uri=ANY($1)) AND EXISTS(SELECT 1 FROM bsky_crossposts c WHERE c.bsky_uri=p.bsky_uri) ORDER BY p.bsky_uri LIMIT $2",&[&chunk,&budget]).await?;let tips:Vec<String>=rows.iter().map(|r|r.get(0)).collect();budget-=tips.len()as i64;load(db,&tips,&mut posts).await?;}
    load(db,&roots,&mut posts).await?;
    let mut claims=Vec::new();let mut notes=HashSet::new();
    for (uri,p)in &posts {let Some(author)=did(uri)else{continue};if text(p,"author_did")!=author{continue}let record=record(p);for(field,owner)in [("note_id",p.get("owner_user_id").and_then(Value::as_i64).unwrap_or(0)),("fediverse_id",0),("ap_object_id",0),("record",0)] {let note=clean(if field=="record"{text(&record,"fediverseId")}else{text(p,field)});if note.starts_with("https://mkultra.monster/users/")&&note.contains("/notes/"){notes.insert(note.clone());claims.push((uri.clone(),note,author.to_owned(),owner));}}}
    if claims.is_empty(){return Ok(out)}
    // Trusted local identity remains a denial dependency after note deletion.
    let mut trusted_maps=HashSet::new();
    let owners=db.query("SELECT u.id::bigint,u.actor_id,s.did FROM ap_users u LEFT JOIN bsky_sessions s ON s.owner_user_id=u.id",&[]).await?;
    for(uri,note,author,owner)in &claims {if *owner<1{continue}for row in &owners {let actor:String=row.get(1);if !note.starts_with(&format!("{}/notes/",actor.trim_end_matches('/'))){continue}let connected:Option<String>=row.get(2);if if *owner>0{*owner==row.get::<_,i64>(0)}else{connected.as_deref()==Some(author.as_str())}{if let Some(flag)=out.get_mut(uri){flag.note_id=Some(note.clone());}trusted_maps.insert((uri.clone(),note.clone()));break}}}
    let mut local=HashMap::new();let notes:Vec<String>=notes.into_iter().collect();
    for chunk in notes.chunks(200) {let variants:Vec<String>=chunk.iter().flat_map(|n|[n.clone(),format!("{n}/")]).collect();for row in db.query("SELECT jsonb_build_object('note_id',n.note_id,'owner_user_id',u.id::bigint,'did',s.did,'raw_create_json',o.raw_create_json) FROM (SELECT note_id FROM masto_statuses WHERE note_id=ANY($1) UNION SELECT id AS note_id FROM outbox_notes WHERE id=ANY($1)) n JOIN ap_users u ON substr(rtrim(n.note_id,'/'),1,length(rtrim(u.actor_id,'/'))+7)=rtrim(u.actor_id,'/')||'/notes/' LEFT JOIN bsky_sessions s ON s.owner_user_id=u.id LEFT JOIN outbox_notes o ON o.id IN(rtrim(n.note_id,'/'),rtrim(n.note_id,'/')||'/') WHERE u.disabled_at IS NULL",&[&variants]).await? {let p:Value=row.get(0);local.insert(clean(text(&p,"note_id")),p);}}
    let imports:HashSet<(String,String)>=claims.iter().filter_map(|(uri,note,_,_)|{
        let doc:Value=local.get(note).and_then(|p|serde_json::from_str(text(p,"raw_create_json")).ok()).unwrap_or(Value::Null);
        let origin=doc.pointer("/object/vaakOrigin").and_then(Value::as_str).unwrap_or("");
        let derived=note.split_once("/notes/").map(|(actor,_)|format!("{actor}/notes/{}",&hex::encode(Sha256::digest(uri.as_bytes()))[..16]));
        if origin=="bluesky"||(!doc.get("object").is_some_and(Value::is_object)&&derived.as_deref()==Some(note.as_str())){Some((uri.clone(),note.clone()))}else{None}
    }).collect();
    for(uri,flag)in out.iter_mut(){if flag.note_id.as_ref().is_some_and(|note|imports.contains(&(uri.clone(),note.clone()))){flag.note_id=None;}}
    let mut anchors=Vec::new();
    for(uri,note,author,owner)in claims {
        if imports.contains(&(uri.clone(),note.clone())){continue}let local_row=local.get(&note);
        let mirror=local_row.map(|l|if owner>0{l.get("owner_user_id").and_then(Value::as_i64)==Some(owner)}else{text(l,"did")==author}).unwrap_or(false);
        if !mirror&&!trusted_maps.contains(&(uri.clone(),note.clone())){continue}
        let raw:Value=local_row.and_then(|l|serde_json::from_str(text(l,"raw_create_json")).ok()).unwrap_or(Value::Null);let obj=raw.get("object").unwrap_or(&raw);
        anchors.push((uri,note,author,clean(text(obj,"blueskyUri")),mirror));
    }
    let mut seeds:Vec<String>=anchors.iter().filter(|(uri,_,_,first,_)|first!=uri&&!text(&posts[uri],"reply_parent").trim().is_empty()).map(|(uri,_,_,_,_)|uri.clone()).collect::<HashSet<_>>().into_iter().collect();seeds.sort();seeds.truncate(256);
    if !seeds.is_empty(){for row in db.query("WITH RECURSIVE lineage(bsky_uri,author_did,reply_root,reply_parent,raw_json) AS (SELECT bsky_uri,author_did,reply_root,reply_parent,raw_json FROM bsky_posts WHERE bsky_uri=ANY($1) UNION SELECT p.bsky_uri,p.author_did,p.reply_root,p.reply_parent,p.raw_json FROM bsky_posts p JOIN lineage a ON p.bsky_uri=a.reply_parent WHERE p.author_did=a.author_did AND (p.reply_root=a.reply_root OR p.bsky_uri=a.reply_root)) SELECT jsonb_build_object('bsky_uri',bsky_uri,'author_did',author_did,'reply_root',reply_root,'reply_parent',reply_parent,'raw_json',raw_json) FROM lineage LIMIT 2048",&[&seeds]).await? {let p:Value=row.get(0);posts.entry(text(&p,"bsky_uri").to_owned()).or_insert(p);}}
    for(mut uri,note,author,first,mirror)in anchors {let mut seen=HashSet::new();let mut chain=Vec::new();let mut complete=false;let expected_root=clean(text(&posts[&uri],"reply_root"));for _ in 0..256 {if !seen.insert(uri.clone()){break}let Some(p)=posts.get(&uri)else{break};if text(p,"author_did")!=author||did(&uri)!=Some(author.as_str()){break}if !chain.is_empty()&&uri!=expected_root&&clean(text(p,"reply_root"))!=expected_root{break}let record=record(p);let marker=clean(text(&record,"fediverseId"));if !marker.is_empty()&&marker!=note{break}chain.push(uri.clone());if(!first.is_empty()&&first==uri)||marker==note{complete=true;break}let parent=clean(text(p,"reply_parent"));let root=clean(text(p,"reply_root"));if parent.is_empty()||root.is_empty()||uri==root{break}uri=parent;}
        if let Some(uri)=chain.first(){if let Some(flag)=out.get_mut(uri){*flag=Mirror{mirror,note_id:Some(note.clone())};}}
        if complete{for uri in chain {if let Some(flag)=out.get_mut(&uri){*flag=Mirror{mirror,note_id:Some(note.clone())};}}}
    }
    Ok(out)
}
/// Last-boundary protection for cached envelopes; no canonical page-window dependence.
pub async fn own_did(db:&Client,owner:i64)->Result<Option<String>> {Ok(db.query_opt("SELECT did FROM bsky_sessions WHERE owner_user_id=$1::bigint LIMIT 1",&[&owner]).await?.and_then(|r|r.get::<_,Option<String>>(0)))}
pub async fn filter_statuses(db:&Client,owner:i64,statuses:&mut Vec<Value>)->Result<()> {
    let uris:Vec<String>=statuses.iter().filter_map(|s|s.get("uri").and_then(Value::as_str).map(str::to_owned)).collect();if !uris.iter().any(|uri|did(uri).is_some()){return Ok(())}let facts=flags(db,&uris).await?;
    let own=own_did(db,owner).await?;statuses.retain(|s| {let uri=clean(text(s,"uri"));!(own.is_some()&&did(&uri)==own.as_deref())&&!facts.get(&uri).map(|f|f.mirror||f.note_id.is_some()).unwrap_or(false)});Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use sha2::{Digest,Sha256};
    fn sql(v:&Value)->String {if v.is_null(){"NULL".into()}else if let Some(n)=v.as_i64(){n.to_string()}else{format!("'{}'",v.as_str().unwrap().replace('\'',"''"))}}
    #[tokio::test]
    #[ignore = "owned actual PostgreSQL/Redis fixture, PHP PDO captured oracle"]
    async fn actual_php_sql_and_redis_mirror_admission() {
        let dsn=std::env::var("HOME_MIRROR_TEST_DSN").unwrap();
        let redis_url=std::env::var("HOME_MIRROR_TEST_REDIS").unwrap();
        let captures:Vec<Value>=serde_json::from_slice(&std::fs::read(std::env::var("HOME_MIRROR_TEST_CAPTURE").unwrap()).unwrap()).unwrap();
        let db=crate::db::connect(&dsn).await.unwrap();let mut cache=crate::redis_util::connect(&redis_url).await.unwrap();
        let mut results=Vec::new();
        for width in ["integer","bigint"] {
            let schema=format!("home_mirror_native_{}_{}",std::process::id(),width);let id_type=if width=="integer"{"INTEGER"}else{"BIGINT"};
            db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema};CREATE TABLE ap_users(id {id_type},actor_id TEXT,disabled_at TEXT);CREATE TABLE bsky_sessions(owner_user_id {id_type},did TEXT);CREATE TABLE masto_statuses(note_id TEXT);CREATE TABLE outbox_notes(id TEXT,raw_create_json TEXT);CREATE TABLE bsky_posts(bsky_uri TEXT PRIMARY KEY,author_did TEXT,reply_root TEXT,reply_parent TEXT,raw_json TEXT);CREATE TABLE bsky_crossposts(bsky_uri TEXT,note_id TEXT,owner_user_id {id_type});CREATE TABLE bsky_post_links(bsky_uri TEXT,fediverse_id TEXT,ap_object_id TEXT);CREATE TABLE bsky_graph_sync(owner_user_id {id_type},kind TEXT,target_did TEXT);CREATE TABLE bsky_hide_dids(owner_user_id {id_type},did TEXT);CREATE TABLE ap_mutes(owner_user_id {id_type},actor_id TEXT);CREATE TABLE ap_user_blocks(owner_user_id {id_type},scope TEXT,value TEXT);CREATE TABLE ap_blocks(scope TEXT,value TEXT,kind TEXT);CREATE TABLE ap_muted_words(owner_user_id {id_type},phrase TEXT);")).await.unwrap();
            let work:Result<()>=async {
                for case in captures.iter().filter(|c|text(c,"layout")==width) {
                    for(table,rows)in case["tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await?;for row in rows.as_array().unwrap(){let obj=row.as_object().unwrap();let columns=obj.keys().cloned().collect::<Vec<_>>().join(",");let values=obj.values().map(sql).collect::<Vec<_>>().join(",");db.batch_execute(&format!("INSERT INTO {table}({columns})VALUES({values})")).await?;}}
                    let uri=clean(text(case,"uri"));let observed=flags(&db,&[uri.clone()]).await?;let got=observed.get(&uri).unwrap();let expected=&case["flags"][&uri];anyhow::ensure!(got.mirror==expected["mirror"].as_bool().unwrap(),"{width} {} mirror",text(case,"case"));let canonical=expected.get("canonical_note_id").and_then(Value::as_str);anyhow::ensure!(got.note_id.as_deref()==canonical,"{width} {} canonical",text(case,"case"));
                    // Actual worker fanout and actual Redis ranked envelope: own raw tail is never enqueued.
                    let mut fanout_assertions=0;
                    if ["record_before_link","peer_independent","peer_local_mirror","split_tip","local_note_deleted","peer_profile_import","legacy_profile_import","deleted_profile_import","explicit_vaak_hash_conflict","own_tail_before_map","peer_quote_local","deleted_split_root","deleted_split_middle","deleted_split_sibling","deleted_split_foreign"].contains(&text(case,"case")) {
                        db.batch_execute("DELETE FROM bsky_graph_sync;INSERT INTO bsky_graph_sync VALUES(1,'follow','did:plc:peer'),(2,'follow','did:plc:a'),(1,'follow','did:plc:foreign'),(2,'follow','did:plc:foreign')").await?;
                        let mut owned_keys=Vec::new();for owner in [1i64,2] {let logical=format!("v13_home_u{owner}_fixture_{}_{}",std::process::id(),width);let index=format!("vaak:timeline:owner-index:v1:{owner}");let key=format!("vaak:timeline:ranked:v2:{}",hex::encode(Sha256::digest(logical.as_bytes())));crate::redis_util::json_set(&mut cache,&index,&serde_json::json!([logical]),60).await?;crate::redis_util::json_set(&mut cache,&key,&serde_json::json!({"ranked":[{"k":"outbox","id":"https://mkultra.monster/users/a/notes/one","s":"outbox"}],"ts":chrono::Utc::now().timestamp()}),60).await?;owned_keys.push((owner,index,key));}
                        let cfg=crate::config::Config{database_url:format!("{dsn} options='-csearch_path={schema}'"),redis_url:redis_url.clone(),redis_queue_url:redis_url.clone(),jetstream_state:"/tmp/unused-mirror".into(),default_owner_id:1,bsky_public_api:"http://127.0.0.1:1".into(),notif_cache_dir:"/tmp/unused-mirror".into(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
                        let actor=did(&uri).unwrap().to_owned();let job=crate::timeline_fanout::FanoutJob{op:"home_bsky".into(),k:"bsky".into(),id:uri.clone(),s:"bluesky".into(),actor:actor.clone(),type_name:String::new(),type_alt:String::new(),visibility:"public".into(),owner:0,views:vec![],hydrate:false,ts:chrono::Utc::now().timestamp()};
                        let _=crate::timeline_fanout::process_job(&cfg,&job).await?;
                        for(owner,index,key)in owned_keys {let env=crate::redis_util::json_get(&mut cache,&key).await?.unwrap();let inserted=env["ranked"].as_array().unwrap().iter().any(|k|k["id"].as_str()==Some(uri.as_str()));let should_insert=!(got.mirror||got.note_id.is_some())&&own_did(&db,owner).await?.as_deref()!=Some(actor.as_str());anyhow::ensure!(inserted==should_insert,"{width} {} actual fanout owner{owner}",text(case,"case"));let _:usize=redis::cmd("DEL").arg(&[index,key]).query_async(&mut cache).await?;fanout_assertions+=1;}
                    }
                    // Fresh top-level return function preserves peer quotes and card/actions byte for byte.
                    let status=serde_json::json!({"uri":uri,"content":"synthetic preserved body","visibility":"public","actions":{"reply":true},"quote":{"quoted_status":{"uri":"at://did:plc:a/app.bsky.feed.post/root"}}});let cache_key=format!("home-mirror-owned-return:{}:{width}:{}",std::process::id(),text(case,"case"));crate::redis_util::json_set(&mut cache,&cache_key,&serde_json::json!([status.clone()]),60).await?;let mut items=crate::redis_util::json_get(&mut cache,&cache_key).await?.unwrap().as_array().unwrap().clone();filter_statuses(&db,2,&mut items).await?;let _:usize=redis::cmd("DEL").arg(&cache_key).query_async(&mut cache).await?;let own=own_did(&db,2).await?;let retained=!(got.mirror||got.note_id.is_some())&&own.as_deref()!=did(&uri);anyhow::ensure!(!items.is_empty()==retained,"{width} {} fresh return",text(case,"case"));if retained{anyhow::ensure!(items[0]==status,"preserved complete status");}
                    if text(case,"case")=="peer_independent" {
                        let note="https://mkultra.monster/users/peer/notes/late";
                        db.batch_execute(&format!("INSERT INTO masto_statuses VALUES('{note}');INSERT INTO outbox_notes VALUES('{note}','{{\"object\":{{\"vaakOrigin\":\"vaak\"}}}}');INSERT INTO bsky_crossposts VALUES('{}','{note}',2)",uri.replace('\'',"''"))).await?;
                        let mut late=vec![status.clone()];filter_statuses(&db,1,&mut late).await?;anyhow::ensure!(late.is_empty(),"late map frozen body must not return");
                    }
                    results.push(serde_json::json!({"width":width,"case":text(case,"case"),"canonical":canonical,"mirror":got.mirror,"fanout_assertions":fanout_assertions,"fresh_return":retained}));
                }Ok(())
            }.await;
            db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();work.unwrap();
        }
        std::fs::write(std::env::var("HOME_MIRROR_TEST_OUTPUT").unwrap(),serde_json::to_vec_pretty(&serde_json::json!({"cases":results.len(),"results":results})).unwrap()).unwrap();
    }
}
