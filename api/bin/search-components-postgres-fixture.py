#!/usr/bin/env python3
"""Emit actual-module Search component/PDO parity fixture; no production IO."""
import pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
s=subprocess.check_output(['python3',str(root/'api/bin/library-facts-postgres-fixture.py'),'--emit-rust-harness'],text=True).split('pub(crate) async fn fixture(')[0]+'}\n'
print(s)
print(r'''
async fn php(schema:&str,owner:i64,input:&serde_json::Value,kind:&str)->anyhow::Result<serde_json::Value>{
 let out=std::process::Command::new("ROOT/api/bin/php-policy-test").args(["-d","extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so","ROOT/api/bin/search-components-php-postgres.php",schema,&owner.to_string(),&input.to_string(),kind]).output()?;
 anyhow::ensure!(out.status.success(),"{}",String::from_utf8_lossy(&out.stderr));Ok(serde_json::from_slice(&out.stdout)?)
}
#[tokio::main] async fn main()->anyhow::Result<()> {
 let db=db::connect(&std::env::var("LIBRARY_FIXTURE_DB")?).await?;
 for width in ["INTEGER","BIGINT"] {
  let schema=format!("search_components_{}",width.to_lowercase());
  db.batch_execute(&format!("DROP SCHEMA IF EXISTS {schema} CASCADE;CREATE SCHEMA {schema};SET search_path={schema};
  CREATE TABLE ap_users(id {width},actor_id TEXT,actor_key TEXT,username TEXT,disabled_at TEXT);
  CREATE TABLE actor_profile(actor_key TEXT,name TEXT,summary TEXT,vanity_verified {width});
  CREATE TABLE following(owner_user_id {width},owner_actor_id TEXT,actor_id TEXT,host TEXT,followed_at TEXT);
  CREATE TABLE followers(owner_actor_id TEXT,actor_id TEXT,followed_at TEXT);
  CREATE TABLE remote_actors(actor_id TEXT,username TEXT,host TEXT);
  CREATE TABLE masto_account_actors(account_id TEXT,actor_id TEXT);
  CREATE TABLE events(actor_id TEXT,type TEXT);
  CREATE TABLE bsky_graph_sync(owner_user_id {width},kind TEXT,target_did TEXT,updated_at TEXT);
  CREATE TABLE bsky_actor_profiles(actor_ref TEXT,did TEXT,profile_json TEXT,updated_at TEXT);
  CREATE TABLE bsky_actor_viewers(owner_user_id {width},actor_ref TEXT,following_uri TEXT,followed_by {width});
  CREATE TABLE masto_followed_tags(owner_user_id {width},name TEXT);
  CREATE TABLE remote_custom_emojis(host TEXT,shortcode TEXT,url TEXT);
  CREATE TABLE remote_emoji_host_meta(host TEXT,fetched_at TEXT,source TEXT);
  INSERT INTO ap_users VALUES(1,'https://mkultra.monster/users/fixture','fixture','fixture',NULL),(2,'https://mkultra.monster/users/other','other','Other',NULL),(3,'https://mkultra.monster/users/fallback','fallback','guest',NULL);
  INSERT INTO actor_profile VALUES('fixture','Current local :wave:','',1),('other','Other current','',0),('fallback','Fallback','',0);
  INSERT INTO following VALUES(1,'https://mkultra.monster/users/fixture','https://remote.test/users/Bob','remote.test','today'),(2,'https://mkultra.monster/users/other','https://remote.test/users/other','remote.test','today'),(1,'https://mkultra.monster/users/fixture','https://nocache.test/users/Alice','nocache.test','today'),(1,'https://mkultra.monster/users/fixture','https://greek.test/users/ΟΣ','greek.test','today');
  INSERT INTO remote_actors VALUES('https://remote.test/users/Bob','Bob','remote.test'),('https://remote.test/ap/users/123456','Bob','remote.test');
  INSERT INTO masto_account_actors VALUES('900000001','https://remote.test/users/Bob'),('900000002','https://remote.test/users/notfollowed');
  INSERT INTO bsky_graph_sync VALUES(1,'follow','did:plc:friend','today');
  INSERT INTO bsky_actor_profiles VALUES('did:plc:friend','did:plc:friend','{{\"handle\":\"friend.test\"}}','today');
  INSERT INTO remote_custom_emojis VALUES('remote.test','wave','https://remote.test/current.png?a=1&b=2'),('mkultra.monster','wave','https://mkultra.monster/current.png');
  INSERT INTO masto_followed_tags VALUES(1,'rust_tag'),(2,'other');")).await?;
  let base=serde_json::json!({"id":"","uri":"https://remote.test/users/alice","url":"https://remote.test/@alice","username":"alice","acct":"alice@remote.test","display_name":"Alice :wave:"});
  let mut cases=Vec::new();
  cases.push((1,base.clone()));cases.push((2,base.clone()));cases.push((0,base.clone()));
  let mutate=|changes:serde_json::Value| {let mut v=base.clone();for(k,val)in changes.as_object().unwrap(){v[k]=val.clone()}v};
  cases.push((1,mutate(serde_json::json!({"uri":"https://remote.test/@Bob","url":"https://remote.test/@Bob"}))));
  cases.push((1,mutate(serde_json::json!({"uri":"https://remote.test/ap/users/123456","url":"https://remote.test/ap/users/123456"}))));
  cases.push((1,mutate(serde_json::json!({"id":"900000001"}))));
  cases.push((2,mutate(serde_json::json!({"id":"900000001"}))));
  cases.push((1,mutate(serde_json::json!({"id":"900000002"}))));
  cases.push((1,mutate(serde_json::json!({"id":"1","uri":"https://mkultra.monster/users/fixture","url":"https://mkultra.monster/users/fixture","acct":"fixture"}))));
  cases.push((1,mutate(serde_json::json!({"id":"2","uri":"https://mkultra.monster/users/other","url":"https://mkultra.monster/users/other","acct":"other"}))));
  cases.push((3,mutate(serde_json::json!({"uri":"https://mkultra.monster/users/fallback","url":"https://mkultra.monster/users/fallback","acct":"fallback"}))));
  cases.push((1,mutate(serde_json::json!({"uri":"https://bsky.app/profile/did:plc:friend","url":"https://bsky.app/profile/did:plc:friend","acct":"friend.test"}))));
  cases.push((1,mutate(serde_json::json!({"uri":"https://bsky.app/profile/friend.test","url":"https://bsky.app/profile/friend.test","acct":"friend.test"}))));
  for actor in ["https://nocache.test/users/Alice","https://nocache.test/@Alice","https://nocache.test/usersAlice","https://greek.test/users/ΟΣ","https://greek.test/@ΟΣ"] {
   let id=php(&schema,1,&serde_json::json!({"actor":actor}),"id").await?["id"].as_str().unwrap().to_owned();
   db.execute("INSERT INTO masto_account_actors VALUES($1,$2)",&[&id,&actor]).await?;
   cases.push((1,mutate(serde_json::json!({"id":id,"uri":"https://identity.test/users/not-followed","url":"https://identity.test/@not-followed"}))));
  }
  for username in [serde_json::json!("SnapshotUsername"),serde_json::json!(""),serde_json::json!(null)] {let mut input=cases[8].1.clone();input["username"]=username;input["display_name"]=serde_json::json!("Cached local display name");cases.push((1,input));}
  let mut missing_username=cases[8].1.clone();missing_username.as_object_mut().unwrap().remove("username");cases.push((1,missing_username));
  let inputs:Vec<serde_json::Value>=cases.iter().map(|(_,input)|input.clone()).collect();
  let mut contexts=std::collections::HashMap::new();for owner in [0,1,2,3] {contexts.insert(owner,search_components::ComponentContext::load(&db,(owner>0).then_some(owner),&inputs).await?);}
  for(owner,input)in &cases {
   let resolved=contexts[owner].resolve_account(input).map_err(|error|anyhow::anyhow!("owner{owner} input{input}: {error}"))?;
   let native=search_render::render_account(&resolved);let canonical=php(&schema,*owner,input,"account").await?;
   assert_eq!(native,canonical["html"].as_str().unwrap(),"actual component HTML {width} owner{owner} {input}");
  }
  for(owner,name)in [(1,"#RuST_tag"),(2,"rust_tag"),(1,"other"),(2,"other"),(0,"rust_tag"),(1,"ΟΣ"),(1,"İX")] {
   let input=serde_json::json!({"name":name,"following":true});let token=search_components::ComponentContext::load(&db,(owner>0).then_some(owner),&[]).await?.resolve_tag(&input)?;
   assert_eq!(search_render::render_tag(&token),php(&schema,owner,&input,"tag").await?["html"].as_str().unwrap());
  }
  for following in [serde_json::json!(true),serde_json::json!(false),serde_json::json!("0"),serde_json::json!("1"),serde_json::json!(0),serde_json::json!(1),serde_json::json!(null)] {let input=serde_json::json!({"name":"rust_tag","following":following});let token=search_components::ComponentContext::load(&db,Some(1),&[]).await?.resolve_tag(&input)?;assert_eq!(search_render::render_tag(&token),php(&schema,1,&input,"tag").await?["html"].as_str().unwrap(),"canonical tag snapshot flag");}
  let local=&cases[8].1;let before=search_components::ComponentContext::load(&db,Some(1),&[local.clone()]).await?.resolve_account(local)?;
  db.batch_execute("UPDATE actor_profile SET name='Mutated current',vanity_verified=0 WHERE actor_key='fixture';UPDATE remote_custom_emojis SET url='https://remote.test/new.png' WHERE host='remote.test';DELETE FROM following WHERE owner_user_id=1;DELETE FROM masto_followed_tags WHERE owner_user_id=1;").await?;
  let after=search_components::ComponentContext::load(&db,Some(1),&[local.clone()]).await?.resolve_account(local)?;assert_eq!(search_render::render_account(&before),search_render::render_account(&after),"canonical local snapshot display survives profile edit");
  assert_eq!(search_render::render_account(&after),php(&schema,1,local,"account").await?["html"].as_str().unwrap());
  let fresh=search_components::ComponentContext::load(&db,Some(1),&[base.clone()]).await?.resolve_account(&base)?;assert_eq!(search_render::render_account(&fresh),php(&schema,1,&base,"account").await?["html"].as_str().unwrap());
  let follow_input=&cases[3].1;
  let withdrawn=search_components::ComponentContext::load(&db,Some(1),&[follow_input.clone()]).await?.resolve_account(follow_input)?;
  assert!(!withdrawn.following());assert_eq!(search_render::render_account(&withdrawn),php(&schema,1,follow_input,"account").await?["html"].as_str().unwrap());
  let tag_input=serde_json::json!({"name":"rust_tag","following":true});let tag_context=search_components::ComponentContext::load(&db,Some(1),&[]).await?;
  assert_eq!(search_render::render_tag(&tag_context.resolve_tag(&tag_input)?),php(&schema,1,&tag_input,"tag").await?["html"].as_str().unwrap());
  let guest_numeric=mutate(serde_json::json!({"id":"900000001"}));assert!(search_components::ComponentContext::load(&db,None,&[guest_numeric.clone()]).await?.resolve_account(&guest_numeric).is_err());
  let bound=search_components::ComponentContext::load(&db,Some(1),&[base.clone()]).await?;let changed=mutate(serde_json::json!({"acct":"different"}));assert!(bound.resolve_account(&changed).is_err());
  assert!(search_components::ComponentContext::load(&db,Some(1),&vec![base.clone();101]).await.is_err());
  let unknown=mutate(serde_json::json!({"id":"899999999"}));assert!(search_components::ComponentContext::load(&db,Some(1),&[unknown.clone()]).await?.resolve_account(&unknown).is_err());
  let cold=mutate(serde_json::json!({"uri":"https://bsky.app/profile/cold.test","url":"https://bsky.app/profile/cold.test"}));assert!(search_components::ComponentContext::load(&db,Some(1),&[cold.clone()]).await?.resolve_account(&cold).is_err());
  db.batch_execute("UPDATE ap_users SET disabled_at='now' WHERE id=1").await?;assert!(search_components::ComponentContext::load(&db,Some(1),&[base.clone()]).await.is_err());
  db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await?;
  println!("SEARCH COMPONENTS {width} actual PDO account/follow/numeric/Bsky/profile/emoji/tag controls PASS");
 }
 Ok(())
}
'''.replace('ROOT',str(root)))
