//! Disposable-PostgreSQL header parity and timing fixture. The expected digests
//! were captured from the pre-optimization header (origin/main at 0.9.29), so a
//! later change that alters one byte of header HTML fails here.
use anyhow::{Context,Result};
use serde_json::json;
use sha2::{Digest,Sha256};
const SCHEMA:&str="CREATE TABLE ap_users(id BIGINT PRIMARY KEY,username TEXT,actor_key TEXT,actor_id TEXT,disabled_at TEXT,is_admin INTEGER NOT NULL DEFAULT 0,created_at TEXT);
CREATE TABLE actor_profile(actor_key TEXT PRIMARY KEY,name TEXT NOT NULL,summary TEXT NOT NULL,attachment_json TEXT NOT NULL DEFAULT '[]',icon_url TEXT,image_url TEXT,manually_approves INTEGER NOT NULL DEFAULT 0,discoverable INTEGER NOT NULL DEFAULT 1,automated INTEGER NOT NULL DEFAULT 0,reply_policy TEXT NOT NULL DEFAULT 'anyone',quote_policy TEXT NOT NULL DEFAULT 'anyone',forum_signature TEXT NOT NULL DEFAULT '',profile_badges TEXT NOT NULL DEFAULT '[]',hide_profile_replies INTEGER NOT NULL DEFAULT 0,hide_profile_boosts INTEGER NOT NULL DEFAULT 0,updated_at TEXT NOT NULL);
CREATE TABLE outbox_notes(id TEXT PRIMARY KEY,create_id TEXT NOT NULL,published TEXT NOT NULL,content TEXT NOT NULL,in_reply_to TEXT,raw_create_json TEXT NOT NULL,visibility TEXT);
CREATE TABLE masto_reblogs(id BIGINT,owner_user_id BIGINT,status_local_id BIGINT);
CREATE TABLE masto_statuses(local_id BIGINT PRIMARY KEY,note_id TEXT,visibility TEXT);
CREATE TABLE masto_pins(owner_user_id BIGINT,status_local_id BIGINT,pinned_at TEXT);
CREATE TABLE ap_asks(id BIGINT,owner_user_id BIGINT,answered INTEGER,answer_note_id TEXT);
CREATE TABLE ap_featured_accounts(id BIGINT,owner_user_id BIGINT,target_actor_id TEXT,position INTEGER,created_at TEXT);
CREATE TABLE vaak_blog_posts(id BIGINT,owner_user_id BIGINT,actor_key TEXT,slug TEXT,title TEXT,status TEXT,published_at TEXT,updated_at TEXT);
CREATE TABLE following(owner_actor_id TEXT,actor_id TEXT,followed_at TEXT,host TEXT);
CREATE TABLE followers(owner_actor_id TEXT,actor_id TEXT,inbox TEXT,followed_at TEXT,last_seen TEXT);
CREATE TABLE ap_follow_requests(id BIGINT,owner_actor_id TEXT,actor_id TEXT,status TEXT,created_at TEXT);
CREATE TABLE ap_post_subscriptions(owner_user_id BIGINT,target_actor_id TEXT,created_at TEXT);
CREATE TABLE ap_user_blocks(id BIGINT,owner_user_id BIGINT,scope TEXT,value TEXT,kind TEXT,created_at TEXT);
CREATE TABLE ap_blocks(id BIGINT,scope TEXT,value TEXT,kind TEXT,created_at TEXT);
CREATE TABLE ap_mutes(owner_user_id BIGINT,actor_id TEXT,created_at TEXT);
CREATE TABLE ap_deprioritized_actors(owner_user_id BIGINT,actor_id TEXT,created_at TEXT);
CREATE TABLE ap_supporter_codes(code_hash TEXT,used_by BIGINT,used_at TEXT);
CREATE TABLE remote_custom_emojis(host TEXT,shortcode TEXT,url TEXT,updated_at TEXT);
CREATE TABLE ap_sl_links(owner_user_id BIGINT,sl_username TEXT,sl_agent_id TEXT);
CREATE TABLE bsky_sessions(owner_user_id BIGINT,handle TEXT,did TEXT);
CREATE TABLE bsky_graph_sync(owner_user_id BIGINT,kind TEXT,target_did TEXT,bsky_uri TEXT);";
async fn seed(db:&tokio_postgres::Client,scale:i64)->Result<()>{
 db.batch_execute("DROP SCHEMA IF EXISTS public CASCADE;CREATE SCHEMA public").await?;db.batch_execute(SCHEMA).await?;
 db.batch_execute(&format!("INSERT INTO ap_users VALUES(1,'cmdr_nova','cmdr_nova','https://mkultra.monster/users/cmdr_nova',NULL,1,'2026-01-01'),(2,'luna_naloc','luna_naloc','https://mkultra.monster/users/luna_naloc',NULL,0,'2026-01-01');
  INSERT INTO ap_users SELECT g,'user'||g,'user'||g,'https://mkultra.monster/users/user'||g,NULL,0,'2026-01-01' FROM generate_series(3,60) g;
  INSERT INTO actor_profile(actor_key,name,summary,attachment_json,icon_url,image_url,profile_badges,updated_at) VALUES('cmdr_nova','Nova :wave:','<p>Hello <b>world</b> &amp; friends</p>','[{{\"type\":\"PropertyValue\",\"name\":\"Site\",\"value\":\"<a href=\\\"https://mkultra.monster/\\\">mkultra</a>\"}}]','https://mkultra.monster/img/a.webp','https://mkultra.monster/img/b.webp','[\"cat\",\"coffee\"]','2026-10-01'),('luna_naloc','Luna','<p>moon</p>','[]',NULL,NULL,'[]','2026-10-01');
  INSERT INTO outbox_notes SELECT 'https://mkultra.monster/users/cmdr_nova/notes/n'||g,'c'||g,'2026-10-01','x',CASE WHEN g%4=0 THEN 'https://elsewhere.test/n'||g END,CASE WHEN g%5=0 THEN '{{\"attachment\":[1]}}' ELSE '{{}}' END,CASE WHEN g%7=0 THEN 'private' ELSE 'public' END FROM generate_series(1,{scale}) g;
  INSERT INTO outbox_notes SELECT 'https://mkultra.monster/users/luna_naloc/notes/n'||g,'c'||g,'2026-10-01','x',NULL,'{{}}','public' FROM generate_series(1,{scale}/4) g;
  INSERT INTO masto_reblogs SELECT g,1,g FROM generate_series(1,{scale}/10) g;
  INSERT INTO masto_statuses VALUES(701,'https://mkultra.monster/users/cmdr_nova/notes/n1','public');INSERT INTO masto_pins VALUES(1,701,'2026-10-02');
  INSERT INTO ap_asks VALUES(1,1,1,'https://mkultra.monster/users/cmdr_nova/notes/n3'),(2,1,0,NULL);
  INSERT INTO ap_featured_accounts VALUES(1,1,'https://mkultra.monster/users/luna_naloc',0,'2026-10-01');
  INSERT INTO vaak_blog_posts VALUES(1,1,'cmdr_nova','first','First','published','2026-10-01','2026-10-01');
  INSERT INTO following SELECT 'https://mkultra.monster/users/'||(ARRAY['cmdr_nova','luna_naloc','user3'])[1+g%3],'https://remote'||g||'.test/users/a',NULL,'remote'||g||'.test' FROM generate_series(1,{scale}) g;
  INSERT INTO following VALUES('https://mkultra.monster/users/luna_naloc','https://mkultra.monster/users/cmdr_nova','2026-10-01','mkultra.monster');
  INSERT INTO followers SELECT 'https://mkultra.monster/users/'||(ARRAY['cmdr_nova','luna_naloc','user3'])[1+g%3],'https://remote'||g||'.test/users/b',NULL,'2026-10-01','2026-10-01' FROM generate_series(1,{scale}) g;
  INSERT INTO ap_follow_requests SELECT g,'https://mkultra.monster/users/user3','https://remote'||g||'.test/users/c','pending','2026-10-01' FROM generate_series(1,{scale}/20) g;
  INSERT INTO ap_post_subscriptions VALUES(2,'https://mkultra.monster/users/cmdr_nova','2026-10-01');
  INSERT INTO ap_user_blocks SELECT g,3+g%5,'actor','https://blocked'||g||'.test/users/x','block','2026-10-01' FROM generate_series(1,{scale}/20) g;
  INSERT INTO ap_blocks SELECT g,'domain','spam'||g||'.test','block','2026-10-01' FROM generate_series(1,50) g;
  INSERT INTO ap_mutes SELECT 3+g%5,'https://muted'||g||'.test/users/x','2026-10-01' FROM generate_series(1,{scale}/20) g;
  INSERT INTO ap_deprioritized_actors VALUES(2,'https://elsewhere.test/users/z','2026-10-01');
  INSERT INTO ap_supporter_codes SELECT md5(g::text),CASE WHEN g=1 THEN 1 END,CASE WHEN g=1 THEN '2026-10-01' END FROM generate_series(1,200) g;
  INSERT INTO remote_custom_emojis SELECT 'host'||(g%300)||'.test','e'||g,'https://host.test/e'||g||'.png','2026-10-01' FROM generate_series(1,{scale}*2) g;
  INSERT INTO remote_custom_emojis VALUES('mkultra.monster','wave','https://mkultra.monster/emoji/wave.png','2026-10-01');
  INSERT INTO ap_sl_links VALUES(1,'nova.resident','agent-1');
  INSERT INTO bsky_sessions VALUES(1,'nova.bsky.test','did:plc:nova');
  INSERT INTO bsky_graph_sync VALUES(1,'meta','graph_sync_v1','{{\"followers\":8,\"following\":3,\"profile_followers\":11,\"profile_following\":5}}');
  INSERT INTO bsky_graph_sync SELECT 1+g%2,'follower','did:plc:f'||g,'at://did:plc:f'||g FROM generate_series(1,{scale}*2) g;
  ANALYZE;")).await?;Ok(())
}
struct NoProcesses;impl crate::profile_warm::WarmTransport for NoProcesses{fn actor_process<'a>(&'a self,_:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=bool>+Send+'a>>{Box::pin(async{panic!("local header must not warm remote actors")})}fn media_process(&self)->std::pin::Pin<Box<dyn std::future::Future<Output=bool>+Send+'_>>{Box::pin(async{panic!("local header must not warm remote media")})}}
// One header first paint shaped like prepare_local_profile_first_paint: root
// check, admission (with its own recheck), then the Recheck and Publish reruns.
async fn paint(db:&tokio_postgres::Client,warm:&std::sync::Arc<crate::profile_enrichment::WarmContext>,key:&str,owner:i64,tab:&str)->Result<(String,u128,u128)>{
 let actor=format!("https://mkultra.monster/users/{key}");let request=super::HeaderRequest{tab:tab.into(),from:String::new(),post:String::new(),page:0};
 let started=std::time::Instant::now();anyhow::ensure!(!crate::profile_context::root_content_blocked_for(db,owner,&actor).await?);
 let header=if owner>0{let session=crate::profile_context::AuthenticatedProfileSession::from_php_session(owner,"csrf-fixture".into(),key.into(),actor.clone(),false,0)?.with_warm_context(warm.clone());crate::profile_context::admit_header_authenticated(db,&session,&request).await?}else{let session=crate::profile_context::GuestProfileSession::from_php_guest_profile(key.into(),actor.clone(),false,0)?.with_warm_context(warm.clone());crate::profile_context::admit_header_guest(db,&session,&request).await?};
 let header_us=started.elapsed().as_micros();crate::profile_bridge::record_stage(crate::profile_bridge::Stage::Header,started);header.require_current(db).await?;header.require_current(db).await?;
 Ok((super::render(&header)?,header_us,started.elapsed().as_micros()))
}
// Digests of the header HTML produced by the pre-optimization code for this seed, per scale.
const EXPECTED:&[(i64,&str,i64,&str,&str)]=&[
 (20000,"cmdr_nova",0,"posts","77434f7a4e90ddb7e290d67fc3f9cc3fcd278e1aaff1c7bddf6135938a05bb04"),(20000,"luna_naloc",0,"posts","f91dc5b7089a67b8bb389592021fd8e3e14555a0fbfb85f031e540de47f34124"),
 (20000,"cmdr_nova",2,"posts","de33e5fbb8bbe4fadf3b346ae96ffbb3c66ce1a3c0ea7dfb3f5674552acb7d31"),(20000,"cmdr_nova",0,"pinned","e342af666822c720f8ad374bd093ab4d9e7e98026cd6d4120c27810c9a2a09e2"),
 (20000,"cmdr_nova",2,"blog","5c0cb3722c5de6e3f8092ef0efd5e25de26789d626c4c8d5113e2a494f81a16c"),
 (4000,"cmdr_nova",0,"posts","e00bc13032c1ea20b67116bf2e1af4d29f4bf8b2d947c1c0b2d65d4ad2b8b2f6"),(4000,"luna_naloc",0,"posts","148180bd44c3bc1682eb324d68e0d7fbfaec846f1bde58fa280a7cfa7685d94a"),
 (4000,"cmdr_nova",2,"posts","9eed33801961234bfb3f2f1cabe8bf3e2540e83763cae0c49c50f632fd616848"),(4000,"cmdr_nova",0,"pinned","d3f8f254cef18194ccc2a22be037e0bf9224d9c6ee496fead91fe0a064d66fe8"),
 (4000,"cmdr_nova",2,"blog","78f53cce2b3400e4dda7de10c98fe6e45d77998b785066c4922d299f666b6098"),
];
// The pre-optimization per-table dependency query, without the column prefilter.
async fn unfiltered_dependencies(db:&tokio_postgres::Client,key:&str,actor:&str,owner:i64,viewer:&str,target:i64)->Result<serde_json::Map<String,serde_json::Value>>{
 let aliases=vec![actor.trim_end_matches('/').to_owned(),format!("{}/",actor.trim_end_matches('/')),viewer.trim_end_matches('/').to_owned(),format!("{}/",viewer.trim_end_matches('/'))];let owners=vec![owner.to_string(),target.to_string()];let mut out=serde_json::Map::new();
 for(table,condition)in super::DEPENDENCY_TABLES{if !db.query_one("SELECT to_regclass($1)::text IS NOT NULL",&[&table]).await?.get::<_,bool>(0){out.insert(table.into(),serde_json::Value::Null);continue}let scoped=if table=="ap_supporter_codes"{" WHERE used_by=ANY($2::text[]::bigint[]) AND used_at IS NOT NULL"}else{""};
  out.insert(table.into(),db.query_one(&format!("SELECT jsonb_build_object('count',COUNT(*),'digest',md5(COALESCE(string_agg(j::text,'' ORDER BY j::text),''))) FROM (SELECT to_jsonb(t) j FROM {table} t{scoped}) q WHERE ($1::text[] IS NOT NULL AND $2::text[] IS NOT NULL AND $3::text IS NOT NULL) AND ({condition})"),&[&aliases,&owners,&key]).await?.get(0));}
 out.insert("key".into(),json!(key));Ok(out)
}
#[tokio::test]
#[ignore="requires an isolated PostgreSQL (PROFILE_HEADER_SPEED_DB on a /tmp socket) and a local Redis (PROFILE_HEADER_SPEED_REDIS)"]
async fn header_speed_fixture_html_parity()->Result<()>{
 let url=std::env::var("PROFILE_HEADER_SPEED_DB")?;anyhow::ensure!(url.contains("/tmp/"),"isolated socket required");let redis_url=std::env::var("PROFILE_HEADER_SPEED_REDIS").context("local Redis url")?;anyhow::ensure!(redis_url.starts_with("redis://127.0.0.1:"),"local Redis required");
 let scale=std::env::var("PROFILE_HEADER_SPEED_SCALE").ok().and_then(|v|v.parse().ok()).unwrap_or(4000i64);let rounds=std::env::var("PROFILE_HEADER_SPEED_ROUNDS").ok().and_then(|v|v.parse().ok()).unwrap_or(5usize);
 let db=crate::db::connect(&url).await?;seed(&db,scale).await?;
 let mut redis=crate::redis_util::connect(&redis_url).await?;let _:()=redis::cmd("FLUSHDB").query_async(&mut redis).await?;
 let cfg=crate::config::Config{database_url:url.clone(),redis_url:redis_url.clone(),redis_queue_url:redis_url.clone(),jetstream_state:std::path::PathBuf::new(),default_owner_id:1,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
 let warm=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg,std::sync::Arc::new(NoProcesses)));let mut seen=Vec::new();
 for(key,owner,viewer,target)in[("cmdr_nova",0,"",1),("cmdr_nova",2,"https://mkultra.monster/users/luna_naloc",1),("luna_naloc",1,"https://mkultra.monster/users/cmdr_nova/",2),("user3",0,"",3)]{let actor=format!("https://mkultra.monster/users/{key}");let expected=serde_json::Value::Object(unfiltered_dependencies(&db,key,&actor,owner,viewer,target).await?);
  for production in[false,true]{let current=if production{crate::profile_bridge::with_production_budget(super::header_dependencies(&db,key,&actor,owner,viewer,target,false)).await?}else{super::header_dependencies(&db,key,&actor,owner,viewer,target,false).await?};assert_eq!(current,expected,"prefiltered dependency digests differ for {key}/{owner}");}
  let blocked=super::header_dependencies(&db,key,&actor,owner,viewer,target,true).await?;for(table,value)in blocked.as_object().unwrap(){assert_eq!(Some(value),expected.get(table),"{table}");}}
 for(key,owner,tab)in[("cmdr_nova",0,"posts"),("luna_naloc",0,"posts"),("cmdr_nova",2,"posts"),("cmdr_nova",0,"pinned"),("cmdr_nova",2,"blog")]{
  let mut header_us=Vec::new();let mut total_us=Vec::new();let mut digest=String::new();
  for round in 0..rounds{let(painted,stages)=crate::profile_bridge::with_production_budget(async{(paint(&db,&warm,key,owner,tab).await,crate::profile_bridge::stage_times())}).await;let(html,h,t)=painted?;if round==0||round+1==rounds{eprintln!("{}",json!({"key":key,"owner":owner,"tab":tab,"round":round,"stages":stages.header_breakdown()}));}let d=hex::encode(Sha256::digest(html.as_bytes()));if round>0{anyhow::ensure!(d==digest,"warm header differs from cold header for {key}/{owner}/{tab}");}digest=d;header_us.push(h);total_us.push(t);}
  header_us.sort();total_us.sort();eprintln!("{}",json!({"key":key,"owner":owner,"tab":tab,"scale":scale,"sha256":digest,"max_header_ms":header_us.last().map(|v|*v as f64/1000.0),"median_header_ms":header_us[header_us.len()/2] as f64/1000.0,"median_header_plus_rechecks_ms":total_us[total_us.len()/2] as f64/1000.0}));
  seen.push((key,owner,tab,digest));
 }
 if EXPECTED.iter().any(|e|e.0==scale){for(key,owner,tab,digest)in &seen{let expected=EXPECTED.iter().find(|e|e.0==scale&&e.1==*key&&e.2==*owner&&e.3==*tab).context("expected digest missing")?;assert_eq!(digest,expected.4,"header HTML changed for {key}/{owner}/{tab}");}}
Ok(())
}
