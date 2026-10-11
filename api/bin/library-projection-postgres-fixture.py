#!/usr/bin/env python3
"""Exercise actual Library projection functions in disposable PostgreSQL only."""
import pathlib, subprocess
root=pathlib.Path(__file__).resolve().parents[2]
s=subprocess.check_output(['python3',str(root/'api/bin/library-facts-postgres-fixture.py'),'--emit-rust-harness'],text=True)
print(s.split('pub(crate) async fn fixture(')[0]+'}\n')
print(r'''
#[tokio::main]
async fn main()->anyhow::Result<()> {
 let url=std::env::var("LIBRARY_FIXTURE_DB")?;
 let db=db::connect(&url).await?;
 for width in ["INTEGER","BIGINT"] {
  let schema=format!("library_projection_{}",width.to_lowercase());
  db.batch_execute(&format!("DROP SCHEMA IF EXISTS {schema} CASCADE; CREATE SCHEMA {schema}; SET search_path={schema};
   CREATE TABLE masto_favourites(id {width},owner_user_id {width},owner_actor_id TEXT,status_id TEXT,object_id TEXT,target_actor TEXT,like_activity_id TEXT,created_at TEXT);
   CREATE TABLE masto_bookmarks(id {width},owner_user_id {width},owner_actor_id TEXT,status_id TEXT,object_id TEXT,created_at TEXT);
   CREATE TABLE vaak_bookmark_folder_items(folder_id {width},owner_user_id {width},status_id TEXT);
   CREATE TABLE bsky_favourite_cache(owner_user_id {width},post_json TEXT,favourited_at TEXT,favourite_uri TEXT);
   CREATE TABLE bsky_bookmark_cache(owner_user_id {width},post_json TEXT,bookmarked_at TEXT,bookmark_uri TEXT);
   INSERT INTO masto_favourites VALUES(1,1,'actor','1','https://fedi.test/1','target','like1','2026'),(2,1,NULL,'2','https://fedi.test/2',NULL,NULL,'2026'),(3,2,NULL,'foreign','https://fedi.test/foreign',NULL,NULL,'2027'),(4,1,NULL,'bsky:bad','at://bad',NULL,NULL,'2028');
   INSERT INTO masto_bookmarks VALUES(1,1,'actor','1','https://fedi.test/1','2026'),(2,1,NULL,'2','https://fedi.test/2','2026'),(3,2,NULL,'foreign','https://fedi.test/foreign','2027'),(4,1,NULL,'mapped','at://did:plc:a/app.bsky.feed.post/a','2028');
   INSERT INTO vaak_bookmark_folder_items VALUES(7,1,'https://fedi.test/1'),(7,1,'mapped'),(8,1,'2'),(8,1,'at://did:plc:b/app.bsky.feed.post/b'),(7,2,'foreign');
   INSERT INTO bsky_favourite_cache VALUES(1,'{{\"uri\":\"at://did:plc:a/app.bsky.feed.post/a\",\"viewer\":{{\"like\":\"own-like\"}}}}','2026','b'),(1,'{{\"uri\":\"at://did:plc:b/app.bsky.feed.post/b\"}}','2026','a'),(2,'{{\"uri\":\"foreign\"}}','2027','foreign');
   INSERT INTO bsky_bookmark_cache VALUES(1,'{{\"uri\":\"at://did:plc:a/app.bsky.feed.post/a\",\"viewer\":{{\"bookmarked\":true}}}}','2026','at://did:plc:a/app.bsky.feed.post/a'),(1,'{{\"uri\":\"at://did:plc:b/app.bsky.feed.post/b\"}}','2026','at://did:plc:b/app.bsky.feed.post/b'),(2,'{{\"uri\":\"foreign\"}}','2027','foreign');")).await?;
  let cfg=config::Config {database_url:format!("{url} options='-c search_path={schema}'"),redis_url:String::new(),redis_queue_url:String::new(),jetstream_state:Default::default(),default_owner_id:1,bsky_public_api:String::new(),notif_cache_dir:Default::default(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
  db.batch_execute("CREATE INDEX projection_fav_owner ON masto_favourites(owner_user_id); CREATE INDEX projection_folder_owner ON vaak_bookmark_folder_items(owner_user_id,folder_id); INSERT INTO masto_favourites SELECT i,3,NULL,i::text,'https://fedi.test/'||i,NULL,NULL,'2029' FROM generate_series(1000,11000) i; INSERT INTO vaak_bookmark_folder_items SELECT 99,3,i::text FROM generate_series(1000,11000) i; ANALYZE masto_favourites; ANALYZE vaak_bookmark_folder_items;").await?;
  for (query,index) in [("EXPLAIN SELECT id FROM masto_favourites WHERE owner_user_id=$1::bigint","projection_fav_owner"),("EXPLAIN SELECT status_id FROM vaak_bookmark_folder_items WHERE owner_user_id=$1::bigint AND folder_id=$2::bigint","projection_folder_owner")] {
   let params:Vec<&(dyn tokio_postgres::types::ToSql+Sync)>=if query.contains("$2"){vec![&1_i64,&7_i64]}else{vec![&1_i64]};
   let plan=db.query(query,&params).await?.iter().map(|row|row.get::<_,String>(0)).collect::<Vec<_>>().join("\n");assert!(plan.contains(index),"parameter casts preserve integer/bigint index selection: {plan}");
  }
  db.batch_execute("UPDATE bsky_favourite_cache SET post_json=jsonb_set(post_json::jsonb,'{post}',jsonb_build_object('uri',post_json::jsonb->'uri'))::text;CREATE TABLE bsky_favourite_sync_state(owner_user_id BIGINT);").await?;
  let f=match library::favourites(&cfg,1,-2,1).await {Ok(v)=>v,Err(e)=>{println!("BEFORE {width} actual favourites error: {e:#}");return Err(e)}};
  assert_eq!((f.offset,f.limit,f.total_fedi,f.total_bsky),(0,10,2,2));assert_eq!(f.fedi_rows[0]["id"],2);assert_eq!(f.fedi_rows[0]["owner_user_id"],1);assert_eq!(f.fedi_rows[1]["like_activity_id"],"like1");assert!(f.fedi_rows[0]["target_actor"].is_null());assert_eq!(f.bsky_rows[0]["viewer"]["like"],"own-like");assert_eq!(f.bsky_rows[0]["favourited_at"],"2026");
  let b=library::bookmarks(&cfg,1,0,40,Some(7)).await?;assert_eq!((b.total_fedi,b.total_bsky),(1,1));assert_eq!(b.fedi_rows[0]["status_id"],"1");assert_eq!(b.fedi_rows[0]["id"],1);assert_eq!(b.bsky_rows[0]["viewer"]["bookmarked"],true);
  let php=std::process::Command::new("/home/cmdr-nova/vaak-public/api/bin/php-policy-test").args(["-d","extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so","/home/cmdr-nova/vaak-public/api/bin/library-projection-php-postgres.php",&schema]).env("LIBRARY_FIXTURE_SOCKET","/tmp/vaak-library-facts-pg-socket").output()?;
  assert!(php.status.success(),"actual PHP PDO fixture: {}",String::from_utf8_lossy(&php.stderr));let canonical:serde_json::Value=serde_json::from_slice(&php.stdout)?;
  assert_eq!(canonical["fedi_ids"],serde_json::json!([1,2]));assert_eq!(canonical["folder_ids"],serde_json::json!([1]));assert_eq!(canonical["total_bsky"],f.total_bsky);
  assert_eq!(canonical["bsky_uris"],serde_json::json!(f.bsky_rows.iter().map(|row|row["post"]["uri"].clone()).collect::<Vec<_>>()));
  let direct=library::bookmarks(&cfg,1,0,10,Some(8)).await?;assert_eq!((direct.total_fedi,direct.total_bsky),(1,1));assert_eq!(direct.fedi_rows[0]["status_id"],"2");assert_eq!(direct.bsky_rows[0]["uri"],"at://did:plc:b/app.bsky.feed.post/b");
  let all=library::bookmarks(&cfg,1,1,99,None).await?;assert_eq!((all.offset,all.limit,all.total_fedi,all.total_bsky),(1,40,2,2));assert_eq!(all.fedi_rows[0]["status_id"],"1");assert_eq!(all.bsky_rows[0]["uri"],"at://did:plc:a/app.bsky.feed.post/a");
  db.batch_execute("INSERT INTO masto_favourites SELECT i,1,NULL,i::text,'https://fedi.test/'||i,NULL,NULL,'2029' FROM generate_series(10,21) i;").await?;
  let first=library::favourites(&cfg,1,0,10).await?;assert_eq!(first.total_fedi,14);assert!(first.has_more_fedi);assert!(!first.has_more_bsky);assert_eq!(first.fedi_rows.len(),10);assert_eq!(first.fedi_rows[0]["id"],21);
  let next=library::favourites(&cfg,1,10,10).await?;assert_eq!(next.fedi_rows.len(),4);assert!(!next.has_more_fedi);assert_eq!(next.fedi_rows[0]["id"],11);
  // Owner and pagination controls use server integers, independent of schema width.
  let other=library::favourites(&cfg,2,0,10).await?;assert_eq!((other.total_fedi,other.total_bsky),(1,1));assert_eq!(other.fedi_rows[0]["owner_user_id"],2);
  assert!(library::favourites(&cfg,0,0,10).await.is_err());
  db.batch_execute("DROP TABLE bsky_favourite_cache;DROP TABLE bsky_bookmark_cache;").await?;
  assert!(library::favourites(&cfg,1,0,10).await?.bsky_rows.is_empty());assert_eq!(library::bookmarks(&cfg,1,0,10,None).await?.total_bsky,0);
  db.batch_execute("CREATE TABLE bsky_favourite_cache(owner_user_id TEXT,post_json TEXT,favourited_at TEXT,favourite_uri TEXT); INSERT INTO bsky_favourite_cache VALUES('not-an-id','{}','2026','bad');").await?;
  assert!(library::favourites(&cfg,1,0,10).await.is_err(),"non-optional schema/data error must not masquerade as empty cache");
  db.batch_execute("DROP TABLE bsky_favourite_cache; CREATE TABLE bsky_favourite_cache(owner_user_id BIGINT,post_json INTEGER,favourited_at TEXT,favourite_uri TEXT); INSERT INTO bsky_favourite_cache VALUES(1,42,'2026','bad');").await?;
  assert!(library::favourites(&cfg,1,0,10).await.is_err(),"post row decoding error must propagate");
  db.batch_execute("DROP TABLE bsky_favourite_cache; ALTER TABLE masto_bookmarks ALTER COLUMN created_at TYPE INTEGER USING 42;").await?;
  assert!(library::bookmarks(&cfg,1,0,10,None).await.is_err(),"Fediverse serializer must return error, not panic");
  db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE;")).await?;
  println!("PROJECTION {width} PASS actual favourites/bookmarks rows, owners, order, cursor/limits, folder aliases, saved fields, optional cache absence and real errors");
 }
 Ok(())
}
''')
