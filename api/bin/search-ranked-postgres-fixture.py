#!/usr/bin/env python3
"""Actual native ranked candidates vs actual PHP, same disposable PG tables."""
import pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
s=subprocess.check_output(['python3',str(root/'api/bin/library-facts-postgres-fixture.py'),'--emit-rust-harness'],text=True).split('pub(crate) async fn fixture(')[0]+'}\n'
print(s)
print(r'''
#[tokio::main] async fn main()->anyhow::Result<()> {
 let url=std::env::var("LIBRARY_FIXTURE_DB")?;let db=db::connect(&url).await?;
 for width in ["INTEGER","BIGINT"] {
  let schema=format!("search_ranked_{}",width.to_lowercase());
  db.batch_execute(&format!("DROP SCHEMA IF EXISTS {schema} CASCADE;CREATE SCHEMA {schema};SET search_path={schema};
  CREATE TABLE ap_users(id {width},actor_key TEXT,actor_id TEXT);CREATE TABLE actor_profile(actor_key TEXT,indexable INTEGER);
  CREATE TABLE events(id {width},actor_id TEXT);CREATE TABLE mentions(id {width},deleted_at TEXT);CREATE TABLE masto_statuses(local_id {width});CREATE TABLE bsky_posts(bsky_uri TEXT);
  CREATE TABLE ap_search_docs(source TEXT,source_pk {width},object_id TEXT,created_at TEXT,body TEXT,body_tsv TSVECTOR GENERATED ALWAYS AS(to_tsvector('simple',body)) STORED);
  INSERT INTO ap_users VALUES(1,'owner','https://mkultra.monster/users/owner'),(2,'other','https://mkultra.monster/users/other');INSERT INTO actor_profile VALUES('owner',0),('other',1);
  INSERT INTO events VALUES(1,'https://remote.test/users/a'),(2,'https://mkultra.monster/users/owner');INSERT INTO mentions VALUES(3,NULL),(4,'deleted');INSERT INTO masto_statuses VALUES(5),(6);
  INSERT INTO bsky_posts VALUES('at://did:plc:a/app.bsky.feed.post/7'),('at://did:plc:b/app.bsky.feed.post/8');
  INSERT INTO ap_search_docs VALUES('event',1,'https://remote.test/n/1','2026-10-10T00:00:01Z','word word word #rust_tag htag_rust_tag'),('event',2,'https://remote.test/n/2','2026-10-10T00:00:02Z','word #rust_tag htag_rust_tag'),('mention',3,'https://remote.test/n/3','2026-10-10T00:00:03Z','word word #rustXtag htag_rust_tag'),('mention',4,'https://remote.test/n/4','2026-10-10T00:00:04Z','word'),('status',5,'https://mkultra.monster/users/owner/notes/5','2026-10-10T00:00:05Z','word'),('status',6,'https://mkultra.monster/users/other/notes/6','2026-10-10T00:00:06Z','word'),('event',99,'missing','2026-10-10T00:00:09Z','word word word word'),('bsky_post',7,'at://did:plc:a/app.bsky.feed.post/7','2026-10-10T00:00:07Z','word word #rust_tag htag_rust_tag'),('bsky_post',8,'at://did:plc:b/app.bsky.feed.post/8','2026-10-10T00:00:08Z','word'),('bsky_post',9,'missing','2026-10-10T00:00:09Z','word word word word');")).await?;
  for (owner,query,tag,limit) in [(1,"word","",40),(2,"word","",40),(1,"rust_tag","rust_tag",40),(2,"rust_tag","rust_tag",40),(1,"a","",40),(1,"word","",1),(1,"word","",999)] {
   let native=search::ranked_candidates(&db,owner,query,tag,limit).await?;
   let php=std::process::Command::new("ROOT/api/bin/php-policy-test").args(["-d","extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so","ROOT/api/bin/search-ranked-php-postgres.php",&schema,&owner.to_string(),query,tag,&limit.to_string()]).output()?;
   assert!(php.status.success(),"{}",String::from_utf8_lossy(&php.stderr));let expected:serde_json::Value=serde_json::from_slice(&php.stdout)?;
   let mut actual=serde_json::to_value(&native)?;for row in actual.as_array_mut().unwrap(){row.as_object_mut().unwrap().remove("status");}
   assert_eq!(actual,expected,"same-DB PHP ranked order/rank {width} owner{owner} q{query} tag{tag}");
  }
  db.batch_execute("UPDATE actor_profile SET indexable=1 WHERE actor_key='owner';DELETE FROM bsky_posts WHERE bsky_uri='at://did:plc:a/app.bsky.feed.post/7';DELETE FROM events WHERE id=1;").await?;
  let rows=search::ranked_candidates(&db,2,"word","",40).await?;assert_eq!(rows.len(),5);assert!(!rows.iter().any(|row|row.source=="event"&&row.source_pk==1));assert!(!rows.iter().any(|row|row.source_pk==7));

  db.batch_execute("INSERT INTO masto_statuses SELECT i FROM generate_series(200,400) i;INSERT INTO ap_search_docs(source,source_pk,object_id,created_at,body) SELECT 'status',i,'https://remote.test/n/'||i,lpad(i::text,8,'0'),'word' FROM generate_series(200,400) i;").await?;
  let capped=search::ranked_candidates(&db,1,"word","",999).await?;assert_eq!(capped.len(),180);
  let php=std::process::Command::new("ROOT/api/bin/php-policy-test").args(["-d","extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so","ROOT/api/bin/search-ranked-php-postgres.php",&schema,"1","word","","999"]).output()?;
  assert!(php.status.success());let expected:serde_json::Value=serde_json::from_slice(&php.stdout)?;let mut actual=serde_json::to_value(capped)?;for row in actual.as_array_mut().unwrap(){row.as_object_mut().unwrap().remove("status");}assert_eq!(actual,expected,"actual PHP180 ranked cap");
  db.batch_execute("DROP TABLE bsky_posts;").await?;assert!(search::ranked_candidates(&db,1,"word","",40).await.is_err(),"unsupported cache migration remains explicit PHP fallback");
  db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE;")).await?;println!("SEARCH RANKED {width} actual paired PHP candidates/order/rank/live/tag/owner PASS");
 }
 Ok(())
}
'''.replace('ROOT',str(root)))
