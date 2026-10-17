//! Opt-in native admission mutation proof. Immutable genuine PHP fixtures seed
//! the schema; distinct current SQL mutations below test publication, not DOM.
use anyhow::Result;
use serde_json::{json,Value};
use tokio_postgres::Client;
use std::{future::Future,pin::Pin,net::Ipv4Addr,sync::atomic::{AtomicUsize,Ordering}};
struct MutationDns<'a>{db:&'a Client,first:&'a str,case:&'a str,calls:AtomicUsize}
impl crate::direct_preview::PreviewDns for MutationDns<'_>{
 fn ipv4<'a>(&'a self,_:&'a str)->Pin<Box<dyn Future<Output=Result<Vec<Ipv4Addr>>>+Send+'a>>{Box::pin(async move{
  self.calls.fetch_add(1,Ordering::SeqCst);
  match self.case{
   "quote_recipients"=>{self.db.execute("UPDATE outbox_notes SET visibility='direct',to_json='[\"https://recipient.test/users/other\"]',cc_json='[]' WHERE id=$1",&[&self.first]).await?;},
   "quote_child_late"|"quote_child_last_omitted"=>{self.db.execute("UPDATE masto_statuses SET visibility='private' WHERE note_id=$1",&[&self.first]).await?;if self.case=="quote_child_last_omitted"{self.db.batch_execute("DELETE FROM masto_statuses WHERE note_id='https://mkultra.monster/users/fixture/notes/profile-reference-1'").await?;}},
   "omitted_last"=>{self.db.execute("UPDATE outbox_notes SET content=content||' withdrawn while final card is omitted' WHERE id=$1",&[&self.first]).await?;self.db.batch_execute("DELETE FROM masto_statuses WHERE note_id='https://mkultra.monster/users/fixture/notes/profile-reference-1'").await?;},
   "source"=>{self.db.execute("UPDATE outbox_notes SET content=content||' withdrawn during later DNS' WHERE id=$1",&[&self.first]).await?;},
   "masto"=>{self.db.execute("DELETE FROM masto_statuses WHERE note_id=$1",&[&self.first]).await?;},
   "profile"=>{self.db.batch_execute("UPDATE actor_profile SET name='Changed during later DNS' WHERE actor_key='fixture'").await?;},
   "root_block"=>{self.db.batch_execute("INSERT INTO ap_blocks(id,scope,value,kind) VALUES(99001,'actor','https://mkultra.monster/users/fixture','block')").await?;},
   "owner"=>{self.db.batch_execute("UPDATE ap_users SET disabled_at='fixture-disabled-after-prefix' WHERE id=1").await?;},
   _=>{}
  }
  Ok(vec![Ipv4Addr::new(8,8,8,8)])
 })}
}
async fn restore(db:&Client,capture:&Value){
 for(table,rows)in capture["current_tables"].as_object().unwrap(){
  db.batch_execute(&format!("TRUNCATE {table}")).await.unwrap();
  for row in rows.as_array().unwrap(){let text=serde_json::to_string(row).unwrap();db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1::text::jsonb)"),&[&text]).await.unwrap();}
 }
}
#[tokio::test]
#[ignore="actual SQL equivalence of batched profile durable tree predicates"]
async fn actual_profile_bulk_tree_predicates(){
 let db=crate::db::connect(&std::env::var("LIBRARY_FIXTURE_DB").unwrap()).await.unwrap();
 let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-shared-card-genuine-lookup-reference.json").unwrap()).unwrap();
 for width in ["INTEGER","BIGINT"]{
  let schema=format!("profile_bulk_tree_{}_{}",std::process::id(),width.to_ascii_lowercase());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
  for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|column|format!("{} {}",column["column_name"].as_str().unwrap(),column["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
  for capture in reference["captures"].as_array().unwrap().iter().filter(|capture|capture["width"]==width&&["baseline_posts_owner1","baseline_boosts_owner2","quote_private_child_posts_owner2","quote_deleted_child_posts_owner1","own_remote_boost_public_boosts_owner1"].contains(&capture["case"].as_str().unwrap())){
   for mutation in ["baseline","missing_masto","missing_outbox","deleted_original","undo_only","private","direct_recipient","follower"]{
    restore(&db,capture).await;
    match mutation{"missing_masto"=>{db.batch_execute("DELETE FROM masto_statuses").await.unwrap();},"missing_outbox"=>{db.batch_execute("DELETE FROM outbox_notes").await.unwrap();},"deleted_original"=>{db.batch_execute("UPDATE events SET action_taken='deleted' WHERE type IN ('Create','Update')").await.unwrap();},"undo_only"=>{db.batch_execute("UPDATE events SET action_taken='deleted' WHERE type='Announce'").await.unwrap();},"private"=>{db.batch_execute("UPDATE outbox_notes SET visibility='private'").await.unwrap();},"direct_recipient"=>{db.batch_execute("UPDATE outbox_notes SET visibility='direct',to_json='[\"https://mkultra.monster/users/other\"]',cc_json='[]'").await.unwrap();},"follower"=>{db.batch_execute("UPDATE outbox_notes SET visibility='private';INSERT INTO followers(owner_actor_id,actor_id) VALUES('https://mkultra.monster/users/fixture','https://mkultra.monster/users/other')").await.unwrap();},_=>{}}
    let source=&capture["source"];
    for owner in [0,1,2]{let mut old=vec![source.clone()];let mut batched=old.clone();crate::audience::filter_statuses(&db,owner,crate::audience::Surface::Direct,&mut old).await.unwrap();crate::audience::filter_profile_statuses(&db,owner,crate::audience::Surface::Direct,&mut batched).await.unwrap();assert_eq!(old,batched,"current audience {width}/{}/{mutation}/{owner}",capture["case"]);}
    let expected=crate::direct_context::local_outbox_tree(&db,source,0).await.unwrap()&&crate::direct_context::current_tree(&db,source,0).await.unwrap();
    assert_eq!(crate::direct_context::profile_trees_current(&db,&[source]).await.unwrap(),expected,"{width}/{}/{mutation}",capture["case"]);
    assert_eq!(crate::direct_context::profile_trees_current(&db,&[source,source]).await.unwrap(),expected);
   }
  }
  db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }
}
#[tokio::test]
#[ignore="opt-in actual-schema native prepared-prefix/last-source mutation proof"]
async fn actual_raw_profile_prefix_mutations(){
 let connection=std::env::var("LIBRARY_FIXTURE_DB").expect("disposable PG");assert!(connection.contains("/tmp/"));
 let input:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-raw-card-cold-production-media-guest-sql-reference.json").unwrap()).unwrap();
 let db=crate::db::connect(&connection).await.unwrap();let mut output=Vec::new();
 let first="https://mkultra.monster/users/fixture/notes/profile-reference-6";
 let last="https://mkultra.monster/users/fixture/notes/profile-reference-1";
 for width in ["INTEGER","BIGINT"]{
  let schema=format!("profile_prefix_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
  for(table,columns)in input["current_schema_by_width"][width].as_object().unwrap(){
   let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();
  }
  let capture=input["captures"].as_array().unwrap().iter().find(|r|r["width"]==width&&r["case"]=="baseline_posts_owner0").unwrap();
  for owner in [0,1]{for case in ["baseline","source","masto","profile","root_block","owner","shared_baseline","omitted_last"]{
   if owner==0&&matches!(case,"shared_baseline"|"omitted_last"){continue}
   if owner==0&&case=="owner"{continue}
   restore(&db,capture).await;
   db.execute("DELETE FROM outbox_notes WHERE id<>$1 AND id<>$2",&[&first,&last]).await.unwrap();
   db.execute("UPDATE outbox_notes SET content='Later cached https://article.test/raw-mutation' WHERE id=$1",&[&last]).await.unwrap();
   db.execute("UPDATE masto_statuses SET content_text='Later cached https://article.test/raw-mutation' WHERE note_id=$1",&[&last]).await.unwrap();
   db.batch_execute("INSERT INTO link_preview_cards(url,title,type,status) VALUES('https://article.test/raw-mutation','Mutation fixture','link','ok')").await.unwrap();
   let selected=crate::profile_source::select(&db,"fixture","https://mkultra.monster/users/fixture","posts",10,0).await.unwrap();
   assert_eq!(selected.rows().len(),2);assert_eq!(selected.rows()[0]["id"],first);assert_eq!(selected.rows()[1]["id"],last);
   let dns=MutationDns{db:&db,first,case,calls:AtomicUsize::new(0)};
   let result=if owner==0{
    let session=crate::profile_context::GuestProfileSession::from_php_guest_profile("fixture".into(),"https://mkultra.monster/users/fixture".into(),false,0).unwrap();
    crate::profile_context::admit_guest_raw_with_dns(&db,&session,selected,&dns).await.map(|batch|batch.cards().count())
   }else{
    let session=crate::profile_context::AuthenticatedProfileSession::from_php_session(1,"fixture-csrf-token".into(),"fixture".into(),"https://mkultra.monster/users/fixture".into(),false,0).unwrap();
    if matches!(case,"shared_baseline"|"omitted_last"){
     async {
      let mut materialized=crate::profile_materialize::materialize(&db,&crate::profile_materialize::ProfileViewer::Auth(&session),selected).await?;
      let grant=crate::profile_context::admit_projection_sources(&db,&crate::profile_materialize::ProfileViewer::Auth(&session),&materialized).await?;
      materialized.complete_source_cache(&db,&dns,&grant,0).await?;
      let crate::profile_materialize::ProfileSourceKind::Shared(first_status)=materialized.sources()[0].source() else{panic!("first actual Masto source must project shared")};
      let first_batch=crate::profile_context::admit_shared_with_dns(&db,&session,materialized.page().clone(),vec![crate::profile_context::ProfileCardSource::shared(0,first_status.clone())?],&dns).await?;
      assert_eq!(first_batch.cards().count(),1,"prefix must be genuinely prepared before later DNS");assert_eq!(dns.calls.load(Ordering::SeqCst),0);
      materialized.complete_source_cache(&db,&dns,&grant,1).await?;
      let(page,sources,ledger,_effects)=materialized.into_parts();
      let last=sources.into_iter().last().unwrap();let(index,kind)=last.into_source()?;let crate::profile_materialize::ProfileSourceKind::Shared(status)=kind else{panic!("last actual Masto source must project shared")};
      let final_batch=crate::profile_context::admit_shared_with_dns(&db,&session,page,vec![crate::profile_context::ProfileCardSource::shared(index,status)?],&dns).await?;
      ledger.require_current(&db).await?;
      for(_,card)in first_batch.cards(){crate::profile_context::require_current_profile_card_for_fixture(&db,1,card.status()).await?;}
      Ok(first_batch.cards().count()+final_batch.cards().count())
     }.await
    }else{crate::profile_context::admit_authenticated_raw_with_dns(&db,&session,selected,&dns).await.map(|batch|batch.cards().count())}
   };
   assert_eq!(dns.calls.load(Ordering::SeqCst),1,"{width} owner{owner} {case}: mutation must run after earlier preparation");
   let outcome=if matches!(case,"baseline"|"shared_baseline"){assert_eq!(result.unwrap(),2);"two prepared cards".into()}else{let error=result.unwrap_err().to_string();assert!(error.contains("changed")||error.contains("inactive"),"unexpected fallback {error}");error};
   output.push(json!({"width":width,"owner":owner,"case":case,"dns_calls":1,"outcome":outcome,"published_cards":if matches!(case,"baseline"|"shared_baseline"){2}else{0}}));
  }}
  db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }
 assert_eq!(output.len(),26);std::fs::write("/tmp/vaak-profile-raw-prefix-mutations-final26-native.json",serde_json::to_vec_pretty(&output).unwrap()).unwrap();
}

#[tokio::test]
#[ignore="opt-in genuine raw quote late-child and omitted-last publication proof"]
async fn actual_raw_quote_prefix_mutations(){
 let connection=std::env::var("LIBRARY_FIXTURE_DB").expect("disposable PG");assert!(connection.contains("/tmp/"));
 let input:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-raw-card-cold-production-media-guest-sql-reference.json").unwrap()).unwrap();
 let db=crate::db::connect(&connection).await.unwrap();let mut output=Vec::new();
 let first="https://mkultra.monster/users/fixture/notes/profile-reference-6";let last="https://mkultra.monster/users/fixture/notes/profile-reference-1";let child="https://mkultra.monster/users/fixture/notes/profile-reference-2";
 for width in ["INTEGER","BIGINT"]{
  let schema=format!("profile_quote_prefix_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
  for(table,columns)in input["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
  let capture=input["captures"].as_array().unwrap().iter().find(|r|r["width"]==width&&r["case"]=="quote_child_cw_posts_owner0").unwrap();
  for owner in [0,1]{for case in ["baseline","quote_child_late","quote_child_last_omitted"]{
   restore(&db,capture).await;
   db.execute("DELETE FROM outbox_notes WHERE id<>$1 AND id<>$2 AND id<>$3",&[&first,&last,&child]).await.unwrap();
   db.execute("UPDATE outbox_notes SET in_reply_to='https://mkultra.monster/users/fixture/notes/child-source-not-in-posts' WHERE id=$1",&[&child]).await.unwrap();
   db.execute("UPDATE outbox_notes SET content='Later cached https://article.test/raw-quote-mutation' WHERE id=$1",&[&last]).await.unwrap();
   db.execute("UPDATE masto_statuses SET content_text='Later cached https://article.test/raw-quote-mutation' WHERE note_id=$1",&[&last]).await.unwrap();
   db.batch_execute("INSERT INTO link_preview_cards(url,title,type,status) VALUES('https://article.test/raw-quote-mutation','Quote mutation fixture','link','ok')").await.unwrap();
   let selected=crate::profile_source::select(&db,"fixture","https://mkultra.monster/users/fixture","posts",10,0).await.unwrap();assert_eq!(selected.rows().len(),2);
   let dns=MutationDns{db:&db,first:child,case,calls:AtomicUsize::new(0)};
   let result=if owner==0{let session=crate::profile_context::GuestProfileSession::from_php_guest_profile("fixture".into(),"https://mkultra.monster/users/fixture".into(),false,0).unwrap();crate::profile_context::admit_guest_raw_with_dns(&db,&session,selected,&dns).await.map(|batch|batch.cards().count())}else{let session=crate::profile_context::AuthenticatedProfileSession::from_php_session(1,"fixture-csrf-token".into(),"fixture".into(),"https://mkultra.monster/users/fixture".into(),false,0).unwrap();crate::profile_context::admit_authenticated_raw_with_dns(&db,&session,selected,&dns).await.map(|batch|batch.cards().count())};
   assert_eq!(dns.calls.load(Ordering::SeqCst),1,"later root cache must run after first quoted card");
   let outcome=if case=="baseline"{assert_eq!(result.unwrap(),2);"two quoted/raw cards prepared".into()}else{let error=result.unwrap_err().to_string();assert!(error.contains("changed"),"unexpected rejection {error}");error};
   output.push(json!({"width":width,"owner":owner,"case":case,"dns_calls":1,"outcome":outcome,"published_cards":if case=="baseline"{2}else{0}}));
  }}
  db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }
 assert_eq!(output.len(),12);std::fs::write("/tmp/vaak-profile-raw-quote-prefix12-native.json",serde_json::to_vec_pretty(&output).unwrap()).unwrap();
}
#[tokio::test]
#[ignore="opt-in genuine raw quote late-child and omitted-last publication proof"]
async fn actual_raw_quote_two_task_revocation(){
 let connection=std::env::var("LIBRARY_FIXTURE_DB").expect("disposable PG");assert!(connection.contains("/tmp/"));
 let input:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-raw-card-cold-production-media-guest-sql-reference.json").unwrap()).unwrap();
 let db=crate::db::connect(&connection).await.unwrap();let mut output=Vec::new();
 let first="https://mkultra.monster/users/fixture/notes/profile-reference-6";let last="https://mkultra.monster/users/fixture/notes/profile-reference-1";let child="https://mkultra.monster/users/fixture/notes/profile-reference-2";
 for width in ["INTEGER","BIGINT"]{
  let schema=format!("profile_quote_tasks_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
  for(table,columns)in input["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
  let capture=input["captures"].as_array().unwrap().iter().find(|r|r["width"]==width&&r["case"]=="quote_child_cw_posts_owner0").unwrap();
  for owner in [0,2]{for case in ["baseline","quote_recipients"]{
   restore(&db,capture).await;
   db.execute("DELETE FROM outbox_notes WHERE id<>$1 AND id<>$2 AND id<>$3",&[&first,&last,&child]).await.unwrap();
   db.execute("UPDATE outbox_notes SET in_reply_to='https://mkultra.monster/users/fixture/notes/child-source-not-in-posts' WHERE id=$1",&[&child]).await.unwrap();
   db.execute("UPDATE outbox_notes SET content='Later cached https://article.test/raw-quote-mutation' WHERE id=$1",&[&last]).await.unwrap();
   db.execute("UPDATE masto_statuses SET content_text='Later cached https://article.test/raw-quote-mutation' WHERE note_id=$1",&[&last]).await.unwrap();
   db.execute("UPDATE masto_statuses SET content_text='Quoted cached https://quote.test/raw-child-task' WHERE note_id=$1",&[&child]).await.unwrap();
   db.batch_execute("INSERT INTO link_preview_cards(url,title,type,status) VALUES('https://quote.test/raw-child-task','First quote cache task','link','ok')").await.unwrap();
   db.batch_execute("INSERT INTO link_preview_cards(url,title,type,status) VALUES('https://article.test/raw-quote-mutation','Quote mutation fixture','link','ok')").await.unwrap();
   let selected=crate::profile_source::select(&db,"fixture","https://mkultra.monster/users/fixture","posts",10,0).await.unwrap();assert_eq!(selected.rows().len(),2);
   let dns=MutationDns{db:&db,first:child,case,calls:AtomicUsize::new(0)};
   let result=if owner==0{let session=crate::profile_context::GuestProfileSession::from_php_guest_profile("fixture".into(),"https://mkultra.monster/users/fixture".into(),false,0).unwrap();crate::profile_context::admit_guest_raw_with_dns(&db,&session,selected,&dns).await.map(|batch|batch.cards().count())}else{let session=crate::profile_context::AuthenticatedProfileSession::from_php_session(2,"fixture-csrf-token".into(),"fixture".into(),"https://mkultra.monster/users/fixture".into(),false,0).unwrap();crate::profile_context::admit_authenticated_raw_with_dns(&db,&session,selected,&dns).await.map(|batch|batch.cards().count())};
   assert_eq!(dns.calls.load(Ordering::SeqCst),if case=="baseline"{2}else{1},"revoked child must stop before later source DNS");
   let outcome=if case=="baseline"{assert_eq!(result.unwrap(),2);"two quoted/raw cards prepared".into()}else{let error=result.unwrap_err().to_string();assert!(error.contains("prepared profile source changed"),"expected audience rejection before next IO, got {error}");error};
   output.push(json!({"width":width,"owner":owner,"case":case,"dns_calls":dns.calls.load(Ordering::SeqCst),"outcome":outcome,"published_cards":if case=="baseline"{2}else{0}}));
  }}
  db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }
 assert_eq!(output.len(),8);std::fs::write("/tmp/vaak-profile-raw-quote-two-task8-native.json",serde_json::to_vec_pretty(&output).unwrap()).unwrap();
}
struct NoEffectTransport;
impl crate::profile_warm::WarmTransport for NoEffectTransport{
 fn actor_process<'a>(&'a self,_:&'a str)->Pin<Box<dyn Future<Output=bool>+Send+'a>>{Box::pin(async{panic!("denied profile source reached actor transport")})}
 fn media_process(&self)->Pin<Box<dyn Future<Output=bool>+Send+'_>>{Box::pin(async{panic!("denied profile source reached media transport")})}
}
struct NoDns;
impl crate::direct_preview::PreviewDns for NoDns{
 fn ipv4<'a>(&'a self,_:&'a str)->Pin<Box<dyn Future<Output=Result<Vec<Ipv4Addr>>>+Send+'a>>{Box::pin(async{panic!("denied profile source reached DNS")})}
}
#[tokio::test]
#[ignore="opt-in genuine private/deleted profile source no-enrichment proof"]
async fn actual_profile_denied_sources_no_enrichment(){
 let connection=std::env::var("LIBRARY_FIXTURE_DB").expect("disposable PG");assert!(connection.contains("/tmp/"));
 let input:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-shared-card-genuine-lookup-reference.json").unwrap()).unwrap();
 let db=crate::db::connect(&connection).await.unwrap();let mut output=Vec::new();
 for width in ["INTEGER","BIGINT"]{
  let schema=format!("profile_no_enrich_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
  for(table,columns)in input["current_schema_by_width"][width].as_object().unwrap(){
   let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();
  }
  for case in ["quote_private_child_posts_owner2","quote_deleted_child_posts_owner2","own_remote_boost_private_boosts_owner2"]{
   let capture=input["captures"].as_array().unwrap().iter().find(|r|r["width"]==width&&r["case"]==case).unwrap();restore(&db,capture).await;
   let cfg=crate::config::Config{database_url:String::new(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:2,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
   let warm=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg,std::sync::Arc::new(NoEffectTransport)));
   let session=crate::profile_context::AuthenticatedProfileSession::from_php_session(2,"fixture-csrf-token".into(),"fixture".into(),"https://mkultra.monster/users/fixture".into(),false,3).unwrap().with_quote_preview_budget(3).with_warm_context(warm.clone());
   let tab=if case.contains("boosts"){"boosts"}else{"posts"};let selection=crate::profile_source::select(&db,"fixture",session.source_actor(),tab,12,0).await.unwrap();
   let (_,page)=selection.into_parts();let source=crate::profile_context::ProfileCardSource::shared(capture["source_index"].as_u64().unwrap() as usize,capture["source"].clone()).unwrap();
   let batch=crate::profile_context::admit_shared_with_dns(&db,&session,page,vec![source],&NoDns).await.unwrap();
   assert_eq!(batch.cards().count(),0,"{width} {case}: mandatory actual denial");assert!(warm.requested_actors().is_empty());assert!(warm.requested_media().is_empty());
   output.push(json!({"width":width,"case":case,"cards":0,"dns":0,"actor_warm":0,"media_warm":0,"source":capture["source"].clone()}));
  }
  db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }
 assert_eq!(output.len(),6);std::fs::write("/tmp/vaak-profile-denied-no-enrichment-native.json",serde_json::to_vec_pretty(&output).unwrap()).unwrap();
}
#[tokio::test]
#[ignore="genuine single-request mixed PHP baseline and isolated PostgreSQL"]
async fn actual_ordered_mixed_profile_pages(){
 let connection=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(connection.contains("/tmp/"));let db=crate::db::connect(&connection).await.unwrap();
 let reference:Value=serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_MIXED_REFERENCE").unwrap_or_else(|_|"/tmp/vaak-profile-mixed-page-reference.json".into())).unwrap()).unwrap();let mut output=Vec::new();
 for capture in reference["captures"].as_array().unwrap(){
  let schema=format!("profile_mixed_native_{}_{}",std::process::id(),output.len());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
  for(table,columns)in capture["current_schema"].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|column|format!("{} {}",column["column_name"].as_str().unwrap(),column["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}restore(&db,capture).await;
  let cfg=crate::config::Config{database_url:String::new(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:2,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
  let warm=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg,std::sync::Arc::new(NoEffectTransport)));let owner=capture["owner"].as_i64().unwrap();let key=capture["key"].as_str().unwrap();let actor=capture["actor"].as_str().unwrap();
  let perf=std::env::var("PROFILE_PERF_LOG").ok().as_deref()==Some("1");let tag=format!("NATIVE_PROFILE_PERF_{}_{}",capture["width"].as_str().unwrap(),capture["limit"].as_i64().unwrap());let backend:i32=db.query_one("SELECT pg_backend_pid()",&[]).await.unwrap().get(0);if perf{db.batch_execute("SET log_statement='all'").await.unwrap();db.simple_query(&format!("SELECT '{tag}_BEGIN'")).await.unwrap();}
  let request_started=std::time::Instant::now();let selected=crate::profile_source::select(&db,key,actor,"posts",capture["limit"].as_i64().unwrap_or(10),0).await.unwrap();assert_eq!(Value::Array(selected.rows().to_vec()),capture["page"]["items"]);
  let initial_status=capture["initial_status_budget"].as_i64().unwrap_or(0);let initial_quote=capture["initial_quote_budget"].as_i64().unwrap_or(0);
  let guest=crate::profile_context::GuestProfileSession::from_php_guest_profile(key.into(),actor.into(),false,initial_status).unwrap().with_quote_preview_budget(initial_quote).with_warm_context(warm.clone());
  let auth=crate::profile_context::AuthenticatedProfileSession::from_php_session(2,"fixture-csrf-token".into(),key.into(),actor.into(),false,initial_status).unwrap().with_quote_preview_budget(initial_quote).with_warm_context(warm.clone());
  let viewer=if owner==0{crate::profile_materialize::ProfileViewer::Guest(&guest)}else{crate::profile_materialize::ProfileViewer::Auth(&auth)};
  let result=crate::profile_context::prepare_ordered_profile_page(&db,&viewer,selected,&crate::direct_preview::SystemDns).await;
  let row=match result{Ok(mut page)=>{page.set_render_clock_for_fixture(chrono::DateTime::from_timestamp(capture["clock"].as_i64().unwrap(),0).unwrap()).unwrap();page.require_current(&db).await.unwrap();
   if std::env::var("PROFILE_FINAL_PREFIX_MUTATIONS").ok().as_deref()==Some("1"){
    let mutations=["UPDATE outbox_notes SET content=content||' late original withdrawal'","UPDATE masto_statuses SET content_text=content_text||' late body edit'","UPDATE actor_profile SET name='Late profile metadata'","INSERT INTO ap_blocks(id,scope,value,kind) VALUES(99003,'actor','https://mkultra.monster/users/fixture','block')","UPDATE outbox_notes SET visibility='direct',to_json='[]',cc_json='[]'"];
    for mutation in mutations{db.batch_execute("BEGIN").await.unwrap();db.batch_execute(mutation).await.unwrap();assert!(page.require_current(&db).await.is_err(),"{mutation}");db.batch_execute("ROLLBACK").await.unwrap();page.require_current(&db).await.unwrap();}
    if owner>0{db.batch_execute("BEGIN;UPDATE ap_users SET disabled_at='late owner withdrawal' WHERE id=2").await.unwrap();assert!(page.require_current(&db).await.is_err());db.batch_execute("ROLLBACK").await.unwrap();page.require_current(&db).await.unwrap();}
   }
   json!({"width":capture["width"],"case":capture["case"],"owner":owner,"native":true,"cards":page.cards().iter().map(|(source_index,html)|json!({"source_index":source_index,"html":html})).collect::<Vec<_>>(),"source_count":page.page().source_count(),"next_offset":page.page().next_offset(),"has_more":page.page().has_more(),"status_state":page.status_state(),"quote_state":page.quote_state(),"actor_warm":warm.requested_actors(),"media_warm":warm.requested_media(),"elapsed_ms":request_started.elapsed().as_millis(),"backend_pid":backend,"log_tag":tag})},Err(error)=>json!({"width":capture["width"],"case":capture["case"],"owner":owner,"native":false,"error":error.to_string()})};if perf{db.simple_query(&format!("SELECT '{tag}_END'")).await.unwrap();db.batch_execute("SET log_statement='none'").await.unwrap();}output.push(row);
  db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }
 std::fs::write(std::env::var("PROFILE_MIXED_NATIVE").unwrap_or_else(|_|"/tmp/vaak-profile-mixed-page-native.json".into()),serde_json::to_vec_pretty(&output).unwrap()).unwrap();assert_eq!(output.len(),reference["captures"].as_array().unwrap().len());assert!(output.iter().all(|row|row["native"]==true),"unfinished mixed page: {:?}",output.iter().filter(|row|row["native"]!=true).collect::<Vec<_>>());println!("Ordered page capabilities prepared; independent complete DOM/effects verification required");
}
#[derive(Clone)]
struct MixedProfileHttpState{cfg:crate::config::Config,clock:chrono::DateTime<chrono::Utc>,warm:std::sync::Arc<crate::profile_enrichment::WarmContext>}
async fn mixed_profile_http_handler(axum::extract::State(state):axum::extract::State<MixedProfileHttpState>,headers:axum::http::HeaderMap,bytes:axum::body::Bytes)->axum::response::Response{
 let deps=crate::direct_preview::PreviewDependencies{dns:&crate::direct_preview::SystemDns,render_clock:Some(state.clock)};
 crate::profile_bridge::fixture_request(&state.cfg,"fixture-profile-private-token-32bytes",headers,bytes,&deps,state.warm).await
}
#[tokio::test]
#[ignore="actual loopback HTTP profile contract and disposable genuine mixed pages"]
async fn actual_mixed_profile_http_requests(){
 let connection=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(connection.contains("/tmp/"));let db=crate::db::connect(&connection).await.unwrap();let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-mixed-page-reference.json").unwrap()).unwrap();let mut output=Vec::new();
 for capture in reference["captures"].as_array().unwrap(){let schema=format!("profile_mixed_http_{}_{}",std::process::id(),output.len());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();for(table,columns)in capture["current_schema"].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|column|format!("{} {}",column["column_name"].as_str().unwrap(),column["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}restore(&db,capture).await;
  let cfg=crate::config::Config{database_url:format!("{connection} options='-c search_path={schema}'"),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:2,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};let warm=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg.clone(),std::sync::Arc::new(NoEffectTransport)));let clock=chrono::DateTime::from_timestamp(capture["clock"].as_i64().unwrap(),0).unwrap();
  let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();let address=listener.local_addr().unwrap();let app=axum::Router::new().route("/internal/profile/page",axum::routing::post(mixed_profile_http_handler)).layer(axum::extract::DefaultBodyLimit::max(64*1024)).layer(axum::middleware::map_response(crate::profile_bridge::no_store)).with_state(MixedProfileHttpState{cfg,clock,warm});let server=tokio::spawn(async move{axum::serve(listener,app).await.unwrap()});
  let viewer=if capture["owner"]==0{json!({"kind":"guest"})}else{json!({"kind":"authenticated","owner_id":2,"csrf":"fixture-csrf-token"})};let request=json!({"protocol_version":1,"viewer":viewer,"actor_key":capture["key"],"actor":capture["actor"],"tab":"posts","mode":"append","offset":0,"limit":10,"lazy_media":false,"status_preview_budget":0,"quote_preview_budget":0});let client=reqwest::Client::new();let url=format!("http://{address}/internal/profile/page");
  let response=client.post(&url).header("x-vaak-profile-token","fixture-profile-private-token-32bytes").json(&request).send().await.unwrap();let status=response.status().as_u16();assert_eq!(response.headers().get("cache-control").unwrap(),"no-store");let bytes=response.bytes().await.unwrap();let reply:Value=serde_json::from_slice(&bytes).unwrap();
  output.push(json!({"width":capture["width"],"case":capture["case"],"owner":capture["owner"],"status":status,"request":request,"reply":reply}));
  let unauthorized=client.post(&url).json(&request).send().await.unwrap();assert_eq!(unauthorized.status().as_u16(),401);assert_eq!(unauthorized.headers().get("cache-control").unwrap(),"no-store");
  let oversized=client.post(&url).header("x-vaak-profile-token","fixture-profile-private-token-32bytes").body(vec![b'x';64*1024+1]).send().await.unwrap();assert_eq!(oversized.status().as_u16(),413);assert_eq!(oversized.headers().get("cache-control").unwrap(),"no-store");
  let mut invalid=request.clone();invalid["actor_key"]=json!("other");let mismatch=client.post(&url).header("x-vaak-profile-token","fixture-profile-private-token-32bytes").json(&invalid).send().await.unwrap();assert_eq!(mismatch.status().as_u16(),422);assert_eq!(mismatch.headers().get("cache-control").unwrap(),"no-store");
  let input=std::env::temp_dir().join(format!("profile-http-consumer-{}-{}.json",std::process::id(),output.len()));std::fs::write(&input,serde_json::to_vec(capture).unwrap()).unwrap();
  let php=tokio::process::Command::new("php").args(["-d","extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so"]).arg(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/profile-native-http-consumer.php")).arg(&input).env("VAAK_PROFILE_COMPLETE_NATIVE","1").env("VAAK_PROFILE_COMPLETE_SECRET","fixture-profile-private-token-32bytes").env("VAAK_SHADOW_HTTP",format!("http://{address}")).output().await.unwrap();std::fs::remove_file(input).unwrap();assert!(php.status.success(),"{}",String::from_utf8_lossy(&php.stderr));
  let consumer:Value=serde_json::from_slice(&php.stdout).unwrap();assert!(consumer["reply"]["complete_native"]==true,"{consumer}");assert_eq!(consumer["status_budget"],0);assert_eq!(consumer["quote_budget"],0);
  output.last_mut().unwrap()["php_consumer"]=consumer;
  if capture["owner"]!=0 {db.execute("UPDATE ap_users SET disabled_at='withdrawn' WHERE id=2",&[]).await.unwrap();let inactive=client.post(&url).header("x-vaak-profile-token","fixture-profile-private-token-32bytes").json(&request).send().await.unwrap();assert_eq!(inactive.status().as_u16(),422);assert_eq!(inactive.headers().get("cache-control").unwrap(),"no-store");}
  server.abort();db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }
 std::fs::write("/tmp/vaak-profile-mixed-http-native.json",serde_json::to_vec_pretty(&output).unwrap()).unwrap();assert_eq!(output.len(),8);assert!(output.iter().all(|row|row["status"]==200),"HTTP unfinished: {:?}",output);println!("eight actual loopback HTTP200 pages and eight401/no-store authorization controls");
}

#[tokio::test]
#[ignore="actual durable source withdrawal during owned warming RESP awaits"]
async fn actual_profile_warm_source_withdrawal(){
 use tokio::io::{AsyncBufReadExt,AsyncReadExt,AsyncWriteExt,BufReader};
 let connection=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(connection.contains("/tmp/"));let db=crate::db::connect(&connection).await.unwrap();let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-mixed-effects-reference.json").unwrap()).unwrap();let mut output=Vec::new();
 for width in ["INTEGER","BIGINT"]{for case in ["baseline","lock_withdrawal","push_failure_withdrawal"]{
  let capture=reference["captures"].as_array().unwrap().iter().find(|row|row["width"]==width&&row["owner"]==2&&row["case"]=="shared_raw_repeated_ask").unwrap();let schema=format!("profile_warm_withdraw_{}_{}",std::process::id(),output.len());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();for(table,columns)in capture["current_schema"].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}restore(&db,capture).await;
  let actor=capture["actor"].as_str().unwrap();let key=capture["key"].as_str().unwrap();let selected=crate::profile_source::select(&db,key,actor,"posts",10,0).await.unwrap();let auth=crate::profile_context::AuthenticatedProfileSession::from_php_session(2,"fixture-csrf-token".into(),key.into(),actor.into(),false,3).unwrap();let viewer=crate::profile_materialize::ProfileViewer::Auth(&auth);let projection=crate::profile_materialize::materialize(&db,&viewer,selected).await.unwrap();let grant=crate::profile_context::admit_projection_sources(&db,&viewer,&projection).await.unwrap();let source=projection.sources().iter().find(|source|source.source_index()==0).unwrap();let crate::profile_materialize::ProfileSourceKind::Shared(status)=source.source()else{panic!("genuine shared first source")};let scoped=projection.source_current_grant_owned(grant,0,status.clone());
  let mutation=crate::db::connect(&format!("{connection} options='-c search_path={schema}'")).await.unwrap();let note=capture["page"]["items"][0]["id"].as_str().unwrap().to_owned();let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();let port=listener.local_addr().unwrap().port();let commands=std::sync::Arc::new(std::sync::Mutex::new(Vec::<String>::new()));let recorded=commands.clone();
  let server=tokio::spawn(async move{let(stream,_)=listener.accept().await.unwrap();let(read,mut write)=stream.into_split();let mut reader=BufReader::new(read);loop{let mut head=String::new();if reader.read_line(&mut head).await.unwrap_or(0)==0{return}let Ok(count)=head.trim().trim_start_matches('*').parse::<usize>()else{return};let mut args=Vec::new();for _ in 0..count{head.clear();reader.read_line(&mut head).await.unwrap();let len=head.trim().trim_start_matches('$').parse::<usize>().unwrap();let mut bytes=vec![0;len+2];reader.read_exact(&mut bytes).await.unwrap();args.push(String::from_utf8_lossy(&bytes[..len]).into_owned());}let command=args[0].as_str();recorded.lock().unwrap().push(command.into());if(case=="lock_withdrawal"&&command=="SET")||(case=="push_failure_withdrawal"&&command=="LPUSH"){mutation.execute("UPDATE outbox_notes SET visibility='private' WHERE id=$1",&[&note]).await.unwrap();}let answer=match command{"SET"=>"+OK\r\n","LPUSH" if case=="push_failure_withdrawal"=>"-ERR push\r\n","LPUSH"|"EXPIRE"=>":1\r\n",_=>"+OK\r\n"};if write.write_all(answer.as_bytes()).await.is_err(){return}}});
  let cfg=crate::config::Config{database_url:String::new(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:format!("redis://127.0.0.1:{port}/1"),jetstream_state:std::path::PathBuf::new(),default_owner_id:2,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
  let warm=crate::profile_enrichment::WarmContext::new(cfg,std::sync::Arc::new(NoEffectTransport));let result=warm.actor_with_grant(&db,"https://remote.test/users/asker",scoped.as_ref()).await;let calls=commands.lock().unwrap().clone();if case=="baseline"{assert!(result.is_ok());assert!(calls.contains(&"EXPIRE".into()));}else{assert!(result.is_err());assert!(!calls.contains(&"EXPIRE".into()));if case=="lock_withdrawal"{assert!(!calls.contains(&"LPUSH".into()));}}
  output.push(json!({"width":width,"case":case,"accepted":result.is_ok(),"commands":calls,"error":result.err().map(|e|e.to_string())}));server.abort();db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }}std::fs::write("/tmp/vaak-profile-warm-source-withdrawal-native.json",serde_json::to_vec_pretty(&output).unwrap()).unwrap();assert_eq!(output.len(),6);
}
struct RecordedQuoteTransport;
impl crate::profile_quote_warm::QuoteWarmTransport for RecordedQuoteTransport {
 fn quote_process<'a>(&'a self,_:&'a str,grant:&'a dyn crate::profile_quote_warm::CurrentQuoteGrant)->std::pin::Pin<Box<dyn std::future::Future<Output=anyhow::Result<bool>>+Send+'a>>{Box::pin(async move{grant.require_current().await?;Ok(true)})}
 fn bsky_warm<'a>(&'a self,_:&'a str,_:i64,_:i64,grant:&'a dyn crate::profile_quote_warm::CurrentQuoteGrant)->std::pin::Pin<Box<dyn std::future::Future<Output=anyhow::Result<bool>>+Send+'a>>{Box::pin(async move{grant.require_current().await?;Ok(true)})}
 fn resolve_handle<'a>(&'a self,_:&'a str,grant:&'a dyn crate::profile_quote_warm::CurrentQuoteGrant)->std::pin::Pin<Box<dyn std::future::Future<Output=anyhow::Result<Option<String>>>+Send+'a>>{Box::pin(async move{grant.require_current().await?;Ok(None)})}
}
#[tokio::test]
#[ignore="genuine representative pending quote PHP cards and selected source SQL"]
async fn actual_profile_pending_quote_cards(){
 let connection=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(connection.contains("/tmp/"));let db=crate::db::connect(&connection).await.unwrap();let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-shared-card-genuine-quote-opts-reference.json").unwrap()).unwrap();let mut output=Vec::new();
 for capture in reference["captures"].as_array().unwrap().iter().filter(|row|row["source"]["quote"]["state"]=="pending"&&row["context"]["owner"]==1){
  let width=capture["width"].as_str().unwrap();let schema=format!("profile_pending_{}_{}",std::process::id(),output.len());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}restore(&db,capture).await;if capture.get("positive_http_body").is_some(){db.batch_execute("ALTER TABLE link_preview_cards ADD PRIMARY KEY(url)").await.unwrap();}
  let actor=capture["context"]["get_actor"].as_str().unwrap();let key=actor.rsplit('/').next().unwrap();let tab=if capture["case"].as_str().unwrap().contains("_media_"){"media"}else{"posts"};let selected=crate::profile_source::select(&db,key,actor,tab,10,0).await.unwrap();let selected_row=selected.rows().iter().find(|row|row==&&capture["selected_source"]).unwrap().clone();let page=selected.into_parts().1;let selection=crate::profile_source::SelectedProfilePage::raw_subset(page,vec![(0,selected_row)],key,actor).unwrap();
  let cfg=crate::config::Config{database_url:String::new(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:1,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};let warm=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg,std::sync::Arc::new(NoEffectTransport)).with_quote_context(std::sync::Arc::new(crate::profile_quote_warm::QuoteWarmContext::new(std::sync::Arc::new(RecordedQuoteTransport)))));let auth=crate::profile_context::AuthenticatedProfileSession::from_php_session(1,"fixture-csrf-token".into(),key.into(),actor.into(),false,0).unwrap().with_quote_preview_budget(0).with_warm_context(warm.clone());
  let result=crate::profile_context::prepare_ordered_profile_page(&db,&crate::profile_materialize::ProfileViewer::Auth(&auth),selection,&crate::direct_preview::SystemDns).await;
  let row=match result{Ok(mut page)=>{page.set_render_clock_for_fixture(chrono::DateTime::from_timestamp(capture["context"]["rendered_at"].as_i64().unwrap(),0).unwrap()).unwrap();json!({"width":width,"case":capture["case"],"native":true,"cards":page.cards().iter().map(|(index,html)|json!({"source_index":index,"html":html})).collect::<Vec<_>>(),"quote_warm":warm.requested_quotes(),"quote_state":page.quote_state()})},Err(error)=>json!({"width":width,"case":capture["case"],"native":false,"error":error.to_string()})};output.push(row);db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }std::fs::write("/tmp/vaak-profile-pending-quote-native.json",serde_json::to_vec_pretty(&output).unwrap()).unwrap();assert_eq!(output.len(),2);assert!(output.iter().all(|row|row["native"]==true),"{output:?}");assert!(output.iter().all(|row|row["quote_warm"]==json!([])&&row["quote_state"]==json!([0,0,0])),"known denied child must not warm or consume quote state: {output:?}");
}

struct RecordedProfilePreview{mutation:Option<(std::sync::Arc<Client>,String)>,body:Option<String>,http:std::sync::Mutex<Vec<String>>,warm:std::sync::Mutex<Vec<String>>}
impl crate::profile_preview_fetch::PreviewFetchTransport for RecordedProfilePreview{
 fn http_get<'a>(&'a self,url:&'a str,_:u64,_:usize,grant:&'a dyn crate::profile_quote_warm::CurrentQuoteGrant)->Pin<Box<dyn Future<Output=Result<Option<String>>>+Send+'a>>{Box::pin(async move{grant.require_current().await?;self.http.lock().unwrap().push(url.into());if let Some((db,target))=&self.mutation{db.execute("UPDATE outbox_notes SET content=content||' withdrawn while HTTP pending' WHERE id=$1",&[target]).await?;}grant.require_current().await?;Ok(self.body.clone())})}
 fn warm<'a>(&'a self,url:&'a str,grant:&'a dyn crate::profile_quote_warm::CurrentQuoteGrant)->Pin<Box<dyn Future<Output=Result<bool>>+Send+'a>>{Box::pin(async move{grant.require_current().await?;self.warm.lock().unwrap().push(url.into());grant.require_current().await?;Ok(true)})}
}
#[tokio::test]
#[ignore="actual selected SQL quote URL cold/cache budgets and complete native cards"]
async fn actual_profile_quote_url_cards(){
 let db=crate::db::connect(&std::env::var("LIBRARY_FIXTURE_DB").unwrap()).await.unwrap();let reference:Value=serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_QUOTE_URL_REFERENCE").unwrap_or_else(|_|"/tmp/vaak-local-profile-quote-url-budget-reference.json".into())).unwrap()).unwrap();let mut output=Vec::new();
 for capture in reference["captures"].as_array().unwrap(){
  let width=capture["width"].as_str().unwrap();let schema=format!("profile_quote_url_{}_{}",std::process::id(),output.len());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}restore(&db,capture).await;if capture.get("positive_http_body").is_some(){db.batch_execute("ALTER TABLE link_preview_cards ADD PRIMARY KEY(url)").await.unwrap();}
  let actor=capture["context"]["get_actor"].as_str().unwrap();let key=actor.rsplit('/').next().unwrap();let owner=capture["context"]["owner"].as_i64().unwrap();let selected=crate::profile_source::select(&db,key,actor,"posts",10,0).await.unwrap();let row=selected.rows().iter().find(|row|row==&&capture["selected_source"]).unwrap().clone();let page=selected.into_parts().1;let selection=crate::profile_source::SelectedProfilePage::raw_subset(page,vec![(0,row)],key,actor).unwrap();
  let cfg=crate::config::Config{database_url:String::new(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:owner,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};let recorder=std::sync::Arc::new(RecordedProfilePreview{mutation:None,body:capture["positive_http_body"].as_str().map(str::to_owned),http:std::sync::Mutex::new(Vec::new()),warm:std::sync::Mutex::new(Vec::new())});let warm=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg,std::sync::Arc::new(NoEffectTransport)).with_fixture_clock(chrono::DateTime::from_timestamp(capture["context"]["rendered_at"].as_i64().unwrap(),0).unwrap()).with_preview_context(std::sync::Arc::new(crate::profile_preview_fetch::PreviewFetchContext::new(recorder.clone()))));let initial=if capture["initial_quote_budget"]["type"]=="int"{capture["initial_quote_budget"]["value"].as_i64().unwrap()}else{2};let session=crate::profile_context::AuthenticatedProfileSession::from_php_session(owner,"fixture-csrf-token".into(),key.into(),actor.into(),false,0).unwrap().with_quote_preview_budget(initial).with_warm_context(warm);
  let result=crate::profile_context::prepare_ordered_profile_page(&db,&crate::profile_materialize::ProfileViewer::Auth(&session),selection,&crate::direct_preview::SystemDns).await;
  let row=match result{Ok(mut page)=>{page.set_render_clock_for_fixture(chrono::DateTime::from_timestamp(capture["context"]["rendered_at"].as_i64().unwrap(),0).unwrap()).unwrap();page.require_current(&db).await.unwrap();json!({"width":width,"case":capture["case"],"native":true,"cards":page.cards().iter().map(|(index,html)|json!({"source_index":index,"html":html})).collect::<Vec<_>>(),"quote_state":page.quote_state(),"status_state":page.status_state(),"preview_http":*recorder.http.lock().unwrap(),"preview_warm":*recorder.warm.lock().unwrap(),"cache_rows":db.query("SELECT to_jsonb(c) FROM link_preview_cards c ORDER BY url",&[]).await.unwrap().iter().map(|row|row.get::<_,Value>(0)).collect::<Vec<_>>()})},Err(error)=>json!({"width":width,"case":capture["case"],"native":false,"error":error.to_string()})};output.push(row);db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }std::fs::write(std::env::var("PROFILE_QUOTE_URL_NATIVE").unwrap_or_else(|_|"/tmp/vaak-profile-quote-url24-integrated-native.json".into()),serde_json::to_vec_pretty(&output).unwrap()).unwrap();assert_eq!(output.len(),reference["captures"].as_array().unwrap().len());assert!(output.iter().all(|row|row["native"]==true),"{output:?}");
}

#[tokio::test]
#[ignore="actual durable parent/child withdrawal during positive quote HTTP prevents cache writes/warm/publication"]
async fn actual_profile_quote_http_withdrawals(){
 let db=std::sync::Arc::new(crate::db::connect(&std::env::var("LIBRARY_FIXTURE_DB").unwrap()).await.unwrap());let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-positive-quote-preview-final-reference.json").unwrap()).unwrap();let mut output=Vec::new();
 for capture in reference["captures"].as_array().unwrap(){for case in ["parent","child"]{
  let width=capture["width"].as_str().unwrap();let schema=format!("profile_quote_url_{}_{}",std::process::id(),output.len());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}restore(&db,capture).await;if capture.get("positive_http_body").is_some(){db.batch_execute("ALTER TABLE link_preview_cards ADD PRIMARY KEY(url)").await.unwrap();}
  let actor=capture["context"]["get_actor"].as_str().unwrap();let key=actor.rsplit('/').next().unwrap();let owner=capture["context"]["owner"].as_i64().unwrap();let selected=crate::profile_source::select(&db,key,actor,"posts",10,0).await.unwrap();let row=selected.rows().iter().find(|row|row==&&capture["selected_source"]).unwrap().clone();let page=selected.into_parts().1;let selection=crate::profile_source::SelectedProfilePage::raw_subset(page,vec![(0,row)],key,actor).unwrap();
  let cfg=crate::config::Config{database_url:String::new(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:owner,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};let recorder=std::sync::Arc::new(RecordedProfilePreview{mutation:Some((db.clone(),if case=="parent"{capture["selected_source"]["id"].as_str().unwrap().into()}else{capture["source"]["quote"]["quoted_status"]["uri"].as_str().unwrap().into()})),body:capture["positive_http_body"].as_str().map(str::to_owned),http:std::sync::Mutex::new(Vec::new()),warm:std::sync::Mutex::new(Vec::new())});let warm=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg,std::sync::Arc::new(NoEffectTransport)).with_fixture_clock(chrono::DateTime::from_timestamp(capture["context"]["rendered_at"].as_i64().unwrap(),0).unwrap()).with_preview_context(std::sync::Arc::new(crate::profile_preview_fetch::PreviewFetchContext::new(recorder.clone()))));let initial=if capture["initial_quote_budget"]["type"]=="int"{capture["initial_quote_budget"]["value"].as_i64().unwrap()}else{2};let session=crate::profile_context::AuthenticatedProfileSession::from_php_session(owner,"fixture-csrf-token".into(),key.into(),actor.into(),false,0).unwrap().with_quote_preview_budget(initial).with_warm_context(warm);
  let result=crate::profile_context::prepare_ordered_profile_page(&db,&crate::profile_materialize::ProfileViewer::Auth(&session),selection,&crate::direct_preview::SystemDns).await;
  let error=match result{Ok(_)=>panic!("withdrawn quote published"),Err(error)=>error.to_string()};let cache_count:i64=db.query_one("SELECT count(*) FROM link_preview_cards",&[]).await.unwrap().get(0);assert_eq!(cache_count,0);assert_eq!(recorder.http.lock().unwrap().len(),1);assert!(recorder.warm.lock().unwrap().is_empty());let row=json!({"width":width,"owner":owner,"case":case,"rejected":true,"error":error,"output_count":0,"http_count":1,"cache_count":0,"warm_count":0});output.push(row);db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }}std::fs::write("/tmp/vaak-profile-quote-http-withdrawal8-native.json",serde_json::to_vec_pretty(&output).unwrap()).unwrap();assert_eq!(output.len(),8);
}

#[tokio::test]
#[ignore="actual full/shell PHP fragment plus isolated current PostgreSQL"]
async fn actual_local_profile_full_first_paint(){
 let connection=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(connection.contains("/tmp/"));let db=crate::db::connect(&connection).await.unwrap();
 let input:Value=serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_FULL_REFERENCE").unwrap()).unwrap()).unwrap();let mut output=Vec::new();
 for capture in input["captures"].as_array().unwrap(){
  let schema=format!("profile_full_native_{}_{}",std::process::id(),output.len());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
  for(table,columns)in capture["current_schema"].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|column|format!("{} {}",column["column_name"].as_str().unwrap(),column["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}restore(&db,capture).await;
  let cfg=crate::config::Config{database_url:String::new(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:2,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
  let warm=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg,std::sync::Arc::new(NoEffectTransport)));let owner=capture["owner"].as_i64().unwrap();let actor=capture["actor"].as_str().unwrap();let key=capture["key"].as_str().unwrap();
  let guest=crate::profile_context::GuestProfileSession::from_php_guest_profile(key.into(),actor.into(),false,0).unwrap().with_quote_preview_budget(0).with_warm_context(warm.clone());let auth=crate::profile_context::AuthenticatedProfileSession::from_php_session(owner.max(1),"fixture-csrf-token".into(),key.into(),actor.into(),false,0).unwrap().with_quote_preview_budget(0).with_warm_context(warm.clone());
  let viewer=if owner==0{crate::profile_materialize::ProfileViewer::Guest(&guest)}else{crate::profile_materialize::ProfileViewer::Auth(&auth)};
  let request=crate::profile_header::HeaderRequest{tab:capture["request"]["tab"].as_str().unwrap().into(),from:capture["request"]["from"].as_str().unwrap().into(),post:capture["request"]["post"].as_str().unwrap().into(),page:capture["request"]["page"].as_i64().unwrap_or(0)};
  let result=crate::profile_context::prepare_local_profile_first_paint(&db,&viewer,&request,&crate::direct_preview::SystemDns).await;
  let row=match result{Ok(mut prepared)=>{if let crate::profile_context::LocalProfileFirstPaint::Timeline{body,..}=&mut prepared{body.set_render_clock_for_fixture(chrono::DateTime::from_timestamp(capture["clock"].as_i64().unwrap(),0).unwrap()).unwrap();}prepared.require_current(&db).await.unwrap();let html=prepared.render().unwrap();json!({"width":capture["width"],"case":capture["case"],"owner":owner,"shell":capture["shell"],"native":true,"html":html,"actor_warm":warm.requested_actors(),"media_warm":warm.requested_media()})},Err(error)=>json!({"width":capture["width"],"case":capture["case"],"owner":owner,"shell":capture["shell"],"native":false,"error":error.to_string()})};output.push(row);db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }
 std::fs::write(std::env::var("PROFILE_FULL_NATIVE").unwrap(),serde_json::to_vec_pretty(&output).unwrap()).unwrap();assert_eq!(output.len(),input["captures"].as_array().unwrap().len());assert!(output.iter().all(|row|row["native"]==true),"unfinished full first paint: {:?}",output.iter().filter(|row|row["native"]!=true).collect::<Vec<_>>());
}

async fn full_profile_http_handler(axum::extract::State(state):axum::extract::State<MixedProfileHttpState>,headers:axum::http::HeaderMap,bytes:axum::body::Bytes)->axum::response::Response{
 let deps=crate::direct_preview::PreviewDependencies{dns:&crate::direct_preview::SystemDns,render_clock:Some(state.clock)};
 crate::profile_bridge::fixture_full_request(&state.cfg,"fixture-profile-private-token-32bytes",headers,bytes,&deps,state.warm,state.clock).await
}
#[tokio::test]
#[ignore="actual full/shell private HTTP and PHP dispatch in disposable schemas"]
async fn actual_local_profile_full_http_callers(){
 let connection=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(connection.contains("/tmp/"));let db=crate::db::connect(&connection).await.unwrap();let reference:Value=serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_FULL_REFERENCE").unwrap()).unwrap()).unwrap();let mut output=Vec::new();
 for capture in reference["captures"].as_array().unwrap(){let schema=format!("profile_full_http_{}_{}",std::process::id(),output.len());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();for(table,columns)in capture["current_schema"].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|column|format!("{} {}",column["column_name"].as_str().unwrap(),column["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}restore(&db,capture).await;
 let cfg=crate::config::Config{database_url:format!("{connection} options='-c search_path={schema}'"),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:2,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};let warm=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg.clone(),std::sync::Arc::new(NoEffectTransport)));let clock=chrono::DateTime::from_timestamp(capture["clock"].as_i64().unwrap(),0).unwrap();
 let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();let address=listener.local_addr().unwrap();let app=axum::Router::new().route("/internal/profile/full",axum::routing::post(full_profile_http_handler)).layer(axum::extract::DefaultBodyLimit::max(64*1024)).layer(axum::middleware::map_response(crate::profile_bridge::no_store)).with_state(MixedProfileHttpState{cfg,clock,warm});let server=tokio::spawn(async move{axum::serve(listener,app).await.unwrap()});
 let viewer=if capture["owner"]==0{json!({"kind":"guest"})}else{json!({"kind":"authenticated","owner_id":2,"csrf":"fixture-csrf-token"})};let request=json!({"protocol_version":1,"viewer":viewer,"actor_key":capture["key"],"actor":capture["actor"],"tab":capture["request"]["tab"],"from":capture["request"]["from"],"post":capture["request"]["post"],"shell":capture["shell"],"lazy_media":false,"status_preview_budget":0,"quote_preview_budget":0});let client=reqwest::Client::new();let url=format!("http://{address}/internal/profile/full");
 let response=client.post(&url).header("x-vaak-profile-token","fixture-profile-private-token-32bytes").json(&request).send().await.unwrap();let status=response.status().as_u16();assert_eq!(response.headers().get("cache-control").unwrap(),"no-store");let bytes=response.bytes().await.unwrap();let reply:Value=serde_json::from_slice(&bytes).unwrap();
 for(case,body,token,expected)in [("unauthorized",serde_json::to_vec(&request).unwrap(),false,401),("oversized",vec![b'x';64*1024+1],true,413),("malformed",b"{".to_vec(),true,400)]{let mut call=client.post(&url).body(body);if token{call=call.header("x-vaak-profile-token","fixture-profile-private-token-32bytes");}let denied=call.send().await.unwrap();assert_eq!(denied.status().as_u16(),expected,"{case}");assert_eq!(denied.headers().get("cache-control").unwrap(),"no-store");}
 let mut mismatch=request.clone();mismatch["actor_key"]=json!("other");let denied=client.post(&url).header("x-vaak-profile-token","fixture-profile-private-token-32bytes").json(&mismatch).send().await.unwrap();assert_eq!(denied.status().as_u16(),422);
 let input=std::env::temp_dir().join(format!("profile-full-consumer-{}-{}.json",std::process::id(),output.len()));std::fs::write(&input,serde_json::to_vec(capture).unwrap()).unwrap();let php=tokio::process::Command::new("php").args(["-d","extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so"]).arg(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/profile-full-http-consumer.php")).arg(&input).env("VAAK_PROFILE_COMPLETE_NATIVE","1").env("VAAK_PROFILE_COMPLETE_SECRET","fixture-profile-private-token-32bytes").env("VAAK_SHADOW_HTTP",format!("http://{address}")).output().await.unwrap();std::fs::remove_file(input).unwrap();assert!(php.status.success(),"{}",String::from_utf8_lossy(&php.stderr));let consumer:Value=serde_json::from_slice(&php.stdout).unwrap();
 let mut after_tables=serde_json::Map::new();for table in capture["current_tables"].as_object().unwrap().keys(){let rows=db.query(&format!("SELECT to_jsonb(t) FROM {table} t"),&[]).await.unwrap().into_iter().map(|row|row.get::<_,Value>(0)).collect::<Vec<_>>();after_tables.insert(table.clone(),Value::Array(rows));}
 if capture["owner"]!=0{db.execute("UPDATE ap_users SET disabled_at='withdrawn' WHERE id=2",&[]).await.unwrap();let denied=client.post(&url).header("x-vaak-profile-token","fixture-profile-private-token-32bytes").json(&request).send().await.unwrap();assert_eq!(denied.status().as_u16(),422);}
 output.push(json!({"width":capture["width"],"case":capture["case"],"owner":capture["owner"],"shell":capture["shell"],"native":status==200,"status":status,"reply":reply,"html":reply["html"],"php_consumer":consumer,"after_tables":after_tables}));server.abort();db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }std::fs::write(std::env::var("PROFILE_FULL_NATIVE").unwrap(),serde_json::to_vec_pretty(&output).unwrap()).unwrap();assert_eq!(output.len(),reference["captures"].as_array().unwrap().len());assert!(output.iter().all(|row|row["native"]==true),"unfinished HTTP: {:?}",output);
}

#[tokio::test]
#[ignore="actual source-bound secondary final freshness after completed preparation"]
async fn actual_secondary_final_freshness(){
 let connection=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(connection.contains("/tmp/"));let db=crate::db::connect(&connection).await.unwrap();
 let input:Value=serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_FULL_REFERENCE").unwrap()).unwrap()).unwrap();let mut output=Vec::new();
 for capture in input["captures"].as_array().unwrap().iter().filter(|c|c["shell"]==false && matches!(c["case"].as_str().unwrap(),"featured_cached"|"pins_public"|"asks_nonempty")){
  let schema=format!("profile_full_native_{}_{}",std::process::id(),output.len());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
  for(table,columns)in capture["current_schema"].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|column|format!("{} {}",column["column_name"].as_str().unwrap(),column["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}restore(&db,capture).await;
  let cfg=crate::config::Config{database_url:String::new(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:2,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
  let warm=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg,std::sync::Arc::new(NoEffectTransport)));let owner=capture["owner"].as_i64().unwrap();let actor=capture["actor"].as_str().unwrap();let key=capture["key"].as_str().unwrap();
  let guest=crate::profile_context::GuestProfileSession::from_php_guest_profile(key.into(),actor.into(),false,0).unwrap().with_quote_preview_budget(0).with_warm_context(warm.clone());let auth=crate::profile_context::AuthenticatedProfileSession::from_php_session(owner.max(1),"fixture-csrf-token".into(),key.into(),actor.into(),false,0).unwrap().with_quote_preview_budget(0).with_warm_context(warm.clone());
  let viewer=if owner==0{crate::profile_materialize::ProfileViewer::Guest(&guest)}else{crate::profile_materialize::ProfileViewer::Auth(&auth)};
  let request=crate::profile_header::HeaderRequest{tab:capture["request"]["tab"].as_str().unwrap().into(),from:capture["request"]["from"].as_str().unwrap().into(),post:capture["request"]["post"].as_str().unwrap().into(),page:capture["request"]["page"].as_i64().unwrap_or(0)};

  let prepared=crate::profile_context::prepare_local_profile_first_paint(&db,&viewer,&request,&crate::direct_preview::SystemDns).await.unwrap();prepared.require_current(&db).await.unwrap();assert!(!prepared.render().unwrap().is_empty());
  let sql=match capture["case"].as_str().unwrap(){"featured_cached"=>"DELETE FROM ap_featured_accounts","pins_public"=>"DELETE FROM masto_pins","asks_nonempty"=>"UPDATE ap_asks SET answered=0",_=>unreachable!()};db.batch_execute(sql).await.unwrap();assert!(prepared.require_current(&db).await.is_err(),"late selected source mutation must reject publication");
  output.push(json!({"width":capture["width"],"owner":owner,"case":capture["case"],"baseline":true,"late_mutation_denied":true,"published":0}));db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }
 std::fs::write(std::env::var("PROFILE_FULL_NATIVE").unwrap(),serde_json::to_vec_pretty(&output).unwrap()).unwrap();assert!(!output.is_empty());
}
#[test]
#[ignore="genuine current PHP mapped post-card navigation targets"]
fn actual_post_navigation_targets(){
 let v:Value=serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_NAV_REFERENCE").unwrap()).unwrap()).unwrap();let mut results=Vec::new();
 for(name,c)in v["cases"].as_object().unwrap(){let uri=c["object"].as_str().unwrap();let remote=crate::library::card::remote_object_href(uri,c.get("hint")).unwrap();assert_eq!(remote,c["expected_remote"].as_str().unwrap(),"{name}");let view=if name.starts_with("native_at_"){"home"}else{"bookmarks"};let show=c["expected_open"].is_string();let html=if uri.starts_with("https://"){
 let ctx=crate::library::actions::FediActionContext{owner_id:1,status_id:"123".into(),object_id:uri.into(),actor_id:"https://remote.test/users/alice".into(),remote_url:remote.clone(),return_view:view.into(),reply_mentions:Vec::new(),spoiler:String::new(),sensitive:false,favourited:false,bookmarked:false,boosted:false,counts:Default::default(),csrf:"fixture".into(),moderation:Default::default()};crate::library::actions::render_moderation(&ctx)
 }else{crate::library::actions::read_only_overflow_mode(uri,view,&remote,show)};
 assert!(html.contains(&format!("href=\"{}\"",crate::library::actions::esc(&remote))),"mapped external {name}");if show{assert!(html.contains(&format!("href=\"{}\"",crate::library::actions::esc(c["expected_open"].as_str().unwrap()))),"in-app {name}");}else{assert!(!html.contains("?view=status&"));}
 results.push(json!({"case":name,"remote":remote,"show_open":show,"html":html}));}
 assert_eq!(results.len(),10);std::fs::write(std::env::var("PROFILE_NAV_NATIVE").unwrap(),serde_json::to_vec_pretty(&results).unwrap()).unwrap();
}

#[derive(Default)]
struct ProfileEffectRecorder{actors:std::sync::Mutex<Vec<String>>,media:std::sync::Mutex<usize>}
impl crate::profile_warm::WarmTransport for ProfileEffectRecorder{
 fn actor_process<'a>(&'a self,actor:&'a str)->Pin<Box<dyn Future<Output=bool>+Send+'a>>{Box::pin(async move{self.actors.lock().unwrap().push(actor.into());true})}
 fn media_process(&self)->Pin<Box<dyn Future<Output=bool>+Send+'_>>{Box::pin(async move{*self.media.lock().unwrap()+=1;true})}
}
#[tokio::test]
#[ignore="actual full HTTP warming intents, declared delivery substitute and zero enrichment on rejects"]
async fn actual_local_profile_full_http_effect_callers(){
 let connection=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(connection.contains("/tmp/"));let db=crate::db::connect(&connection).await.unwrap();let reference:Value=serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_FULL_REFERENCE").unwrap()).unwrap()).unwrap();let mut output=Vec::new();
 for capture in reference["captures"].as_array().unwrap(){let schema=format!("profile_full_http_{}_{}",std::process::id(),output.len());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();for(table,columns)in capture["current_schema"].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|column|format!("{} {}",column["column_name"].as_str().unwrap(),column["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}restore(&db,capture).await;
 if let Some(sql)=capture["negative_before_sql"].as_str(){db.batch_execute(sql).await.unwrap();}
 let media_fixture=if capture.get("declared_media_transport").is_some(){Some(FeaturedMediaFixtureEnvironment::new(capture,&schema))}else{None};
 if media_fixture.is_some(){db.batch_execute("CREATE UNIQUE INDEX fixture_cold_actor_unique ON remote_actors(actor_id);CREATE UNIQUE INDEX fixture_cold_media_unique ON remote_media_cache(actor_id,kind)").await.unwrap();}
 let cfg=crate::config::Config{database_url:format!("{connection} options='-c search_path={schema}'"),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:2,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};let transport=std::sync::Arc::new(ProfileEffectRecorder::default());let warm=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg.clone(),transport.clone()));let clock=chrono::DateTime::from_timestamp(capture["clock"].as_i64().unwrap(),0).unwrap();
 let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();let address=listener.local_addr().unwrap();let app=axum::Router::new().route("/internal/profile/full",axum::routing::post(full_profile_http_handler)).layer(axum::extract::DefaultBodyLimit::max(64*1024)).layer(axum::middleware::map_response(crate::profile_bridge::no_store)).with_state(MixedProfileHttpState{cfg,clock,warm:warm.clone()});let server=tokio::spawn(async move{axum::serve(listener,app).await.unwrap()});
 let viewer=if capture["owner"]==0{json!({"kind":"guest"})}else{json!({"kind":"authenticated","owner_id":2,"csrf":"fixture-csrf-token"})};let request=json!({"protocol_version":1,"viewer":viewer,"actor_key":capture["key"],"actor":capture["actor"],"tab":capture["request"]["tab"],"from":capture["request"]["from"],"post":capture["request"]["post"],"shell":capture["shell"],"lazy_media":false,"status_preview_budget":0,"quote_preview_budget":0});let client=reqwest::Client::new();let url=format!("http://{address}/internal/profile/full");
 let response=client.post(&url).header("x-vaak-profile-token","fixture-profile-private-token-32bytes").json(&request).send().await.unwrap();let status=response.status().as_u16();assert_eq!(response.headers().get("cache-control").unwrap(),"no-store");let bytes=response.bytes().await.unwrap();let reply:Value=serde_json::from_slice(&bytes).unwrap();let media_effects=media_fixture.as_ref().map(|fixture|fixture.effects());let effects=json!({"actor_intents":warm.requested_actors(),"media_intents":warm.requested_media(),"quote_intents":warm.requested_quotes(),"actor_process":transport.actors.lock().unwrap().clone(),"media_process":*transport.media.lock().unwrap()});
 if let Some(control)=capture["negative_control"].as_str(){
 let expected_status=capture["expected_status"].as_u64().unwrap_or(422) as u16;assert_eq!(status,expected_status,"negative {control}");if expected_status==422{assert!(reply.get("html").is_none()&&reply.get("status_preview_state").is_none()&&reply.get("quote_preview_state").is_none(),"no partial response {control}");}else{assert_eq!(reply["source_count"],0);assert!(!reply["html"].as_str().unwrap().contains("featured-account-link"));}assert!(warm.requested_actors().is_empty()&&warm.requested_media().is_empty());
 let calls=media_effects.as_ref().unwrap()["calls"].as_array().unwrap();let expected=capture["expected_helper_calls"].as_u64().unwrap() as usize;assert_eq!(calls.len(),expected,"helper sequence {control}");
 output.push(json!({"width":capture["width"],"owner":capture["owner"],"control":control,"native":true,"accepted":false,"status":status,"reply":reply,"warm_intents":effects,"featured_media_effects":media_effects}));server.abort();db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();continue;
 }
 if let Some(effect)=&media_effects{assert_eq!(effect["calls"],capture["featured_media_transport"],"actual media worker transport intents");}
 assert_eq!(effects["actor_intents"],capture["php"]["effects"]["fixture_actor_warm_requests"]["value"],"actual actor intents {}/{}",capture["case"],capture["owner"]);assert_eq!(effects["media_intents"],capture["php"]["effects"]["fixture_media_warm_requests"]["value"]);assert_eq!(effects["quote_intents"],capture["php"]["effects"]["fixture_quote_warm_requests"]["value"]);

 for(case,body,token,expected)in [("unauthorized",serde_json::to_vec(&request).unwrap(),false,401),("oversized",vec![b'x';64*1024+1],true,413),("malformed",b"{".to_vec(),true,400)]{let mut call=client.post(&url).body(body);if token{call=call.header("x-vaak-profile-token","fixture-profile-private-token-32bytes");}let denied=call.send().await.unwrap();assert_eq!(denied.status().as_u16(),expected,"{case}");assert_eq!(denied.headers().get("cache-control").unwrap(),"no-store");}
 let mut mismatch=request.clone();mismatch["actor_key"]=json!("other");let denied=client.post(&url).header("x-vaak-profile-token","fixture-profile-private-token-32bytes").json(&mismatch).send().await.unwrap();assert_eq!(denied.status().as_u16(),422);
 assert_eq!(warm.requested_actors(),effects["actor_intents"].as_array().unwrap().iter().map(|v|v.as_str().unwrap().to_string()).collect::<Vec<_>>());assert_eq!(warm.requested_media(),effects["media_intents"].as_array().unwrap().iter().map(|v|v.as_str().unwrap().to_string()).collect::<Vec<_>>());
 let input=std::env::temp_dir().join(format!("profile-full-consumer-{}-{}.json",std::process::id(),output.len()));std::fs::write(&input,serde_json::to_vec(capture).unwrap()).unwrap();let php=tokio::process::Command::new("php").args(["-d","extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so"]).arg(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/profile-full-http-consumer.php")).arg(&input).env("VAAK_PROFILE_COMPLETE_NATIVE","1").env("VAAK_PROFILE_COMPLETE_SECRET","fixture-profile-private-token-32bytes").env("VAAK_SHADOW_HTTP",format!("http://{address}")).output().await.unwrap();std::fs::remove_file(input).unwrap();assert!(php.status.success(),"{}",String::from_utf8_lossy(&php.stderr));let consumer:Value=serde_json::from_slice(&php.stdout).unwrap();
 let mut after_tables=serde_json::Map::new();for table in capture["current_tables"].as_object().unwrap().keys(){let rows=db.query(&format!("SELECT to_jsonb(t) FROM {table} t"),&[]).await.unwrap().into_iter().map(|row|row.get::<_,Value>(0)).collect::<Vec<_>>();after_tables.insert(table.clone(),Value::Array(rows));}
 let before_disabled=(warm.requested_actors(),warm.requested_media());if capture["owner"]!=0{db.execute("UPDATE ap_users SET disabled_at='withdrawn' WHERE id=2",&[]).await.unwrap();let denied=client.post(&url).header("x-vaak-profile-token","fixture-profile-private-token-32bytes").json(&request).send().await.unwrap();assert_eq!(denied.status().as_u16(),422);}
 assert_eq!(before_disabled,(warm.requested_actors(),warm.requested_media()));
 output.push(json!({"warm_intents":effects,"featured_media_effects":media_effects,"declared_delivery":"Redis unavailable; actor/media subprocess recorder only, no fetch/delivery claim","width":capture["width"],"case":capture["case"],"owner":capture["owner"],"shell":capture["shell"],"native":status==200,"status":status,"reply":reply,"html":reply["html"],"php_consumer":consumer,"after_tables":after_tables}));server.abort();db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }std::fs::write(std::env::var("PROFILE_FULL_NATIVE").unwrap(),serde_json::to_vec_pretty(&output).unwrap()).unwrap();assert_eq!(output.len(),reference["captures"].as_array().unwrap().len());assert!(output.iter().all(|row|row["native"]==true),"unfinished HTTP: {:?}",output);
}


struct FeaturedMediaFixtureEnvironment{saved:Vec<(String,Option<std::ffi::OsString>)>,calls:std::path::PathBuf,receipts:std::path::PathBuf}
impl FeaturedMediaFixtureEnvironment{
 fn new(capture:&Value,schema:&str)->Self{
 use std::os::unix::fs::PermissionsExt;
 let api=std::path::PathBuf::from(std::env::var("PROFILE_COLD_API").unwrap());assert!(api.to_string_lossy().contains("/rust/target/parity-evidence/"));let root=api.parent().unwrap();let stem=format!("native-media-{}-{schema}",std::process::id());let calls=root.join(format!("{stem}-calls.jsonl"));let receipts=root.join(format!("{stem}-receipts.jsonl"));std::fs::write(&calls,"").unwrap();std::fs::write(&receipts,"").unwrap();let mut config=capture["declared_media_transport"].clone();config["log"]=json!(calls);let config_path=root.join(format!("{stem}-transport.json"));std::fs::write(&config_path,serde_json::to_vec(&config).unwrap()).unwrap();
 let source=config["document"]["icon"]["url"].as_str().unwrap_or("");if !source.is_empty(){use sha2::{Digest,Sha256};let hash=hex::encode(Sha256::digest(format!("https://featured.test/users/remote|avatar|{source}").as_bytes()));let path=std::env::temp_dir().join(format!("vaak-media-negative-{hash}"));if path.exists(){std::fs::remove_file(path).unwrap();}}
 let shim=root.join("fixture-php-media");let helper=api.join("bin/profile-featured-media-helper-fixture.php");let script=format!("#!/usr/bin/env -S php -d extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so\n<?php\n$argv=[$argv[0],$argv[2]];require {};\n",serde_json::to_string(helper.to_str().unwrap()).unwrap());std::fs::write(&shim,script).unwrap();std::fs::set_permissions(&shim,std::fs::Permissions::from_mode(0o700)).unwrap();
 let vars=[("VAAK_API_ROOT",api.to_string_lossy().into_owned()),("VAAK_PHP_BIN",shim.to_string_lossy().into_owned()),("AP_DB_DSN",format!("pgsql:host=/tmp/vaak-library-facts-pg-socket;dbname=postgres;options=--search_path={schema}")),("PROFILE_FEATURED_MEDIA_TRANSPORT",config_path.to_string_lossy().into_owned()),("PROFILE_FEATURED_MEDIA_CLOCK",chrono::DateTime::from_timestamp(capture["clock"].as_i64().unwrap(),0).unwrap().to_rfc3339()),("PROFILE_FEATURED_MEDIA_RECEIPT_LOG",receipts.to_string_lossy().into_owned())];let saved=vars.iter().map(|(k,_)|(k.to_string(),std::env::var_os(k))).collect();for(k,v)in vars{std::env::set_var(k,v);}Self{saved,calls,receipts}
 }
 fn effects(&self)->Value{let rows=|p:&std::path::Path|std::fs::read_to_string(p).unwrap().lines().map(|line|serde_json::from_str::<Value>(line).unwrap()).collect::<Vec<_>>();json!({"calls":rows(&self.calls),"receipts":rows(&self.receipts),"delivery":"declared actor document/download/R2 responses; unchanged actual media resolver and current SQL receipts"})}
}
impl Drop for FeaturedMediaFixtureEnvironment{fn drop(&mut self){for(k,v)in &self.saved{if let Some(v)=v{std::env::set_var(k,v);}else{std::env::remove_var(k);}}}}

#[tokio::test]
#[ignore="actual configured eight-second PHP complete caller, baseline and whole fallback effects"]
async fn actual_profile_configured_cold_caller(){
 let connection=std::env::var("LIBRARY_FIXTURE_DB").unwrap();let db=crate::db::connect(&connection).await.unwrap();let reference:Value=serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_FULL_REFERENCE").unwrap()).unwrap()).unwrap();let mut output=Vec::new();
 for capture in reference["captures"].as_array().unwrap(){let schema=format!("profile_caller_{}_{}",std::process::id(),output.len());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();for(table,columns)in capture["current_schema"].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}if capture.get("declared_media_transport").is_some(){db.batch_execute("CREATE UNIQUE INDEX actor_unique ON remote_actors(actor_id);CREATE UNIQUE INDEX media_unique ON remote_media_cache(actor_id,kind)").await.unwrap();}
 let cfg=crate::config::Config{database_url:format!("{connection} options='-c search_path={schema}'"),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:Default::default(),default_owner_id:2,bsky_public_api:String::new(),notif_cache_dir:Default::default(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};let recorder=std::sync::Arc::new(ProfileEffectRecorder::default());let warm=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg.clone(),recorder));let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();let address=listener.local_addr().unwrap();let app=axum::Router::new().route("/internal/profile/full",axum::routing::post(full_profile_http_handler)).layer(axum::middleware::map_response(crate::profile_bridge::no_store)).with_state(MixedProfileHttpState{cfg,clock:chrono::DateTime::from_timestamp(capture["clock"].as_i64().unwrap(),0).unwrap(),warm:warm.clone()});let server=tokio::spawn(async move{axum::serve(listener,app).await.unwrap()});let mut modes=Vec::new();
 for enabled in [false,true]{restore(&db,capture).await;let fixture=if capture.get("declared_media_transport").is_some(){Some(FeaturedMediaFixtureEnvironment::new(capture,&schema))}else{None};let input=std::env::temp_dir().join(format!("profile-configured-{}-{}.json",std::process::id(),enabled));std::fs::write(&input,serde_json::to_vec(capture).unwrap()).unwrap();let command_started=std::time::Instant::now();let php=tokio::process::Command::new("php").args(["-d","extension=/tmp/vaak-php-mbstring-test/usr/lib/php/20230831/mbstring.so","-d","extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so","-d","extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so"]).arg(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/profile-full-configured-caller.php")).arg(&input).env("VAAK_PROFILE_COMPLETE_NATIVE",if enabled{"1"}else{"0"}).env("VAAK_PROFILE_COMPLETE_SECRET","fixture-profile-private-token-32bytes").env("VAAK_SHADOW_HTTP",format!("http://{address}")).env("PROFILE_CALLER_SCHEMA",&schema).output().await.unwrap();std::fs::remove_file(input).unwrap();assert!(php.status.success(),"{}",String::from_utf8_lossy(&php.stderr));let reply:Value=serde_json::from_slice(&php.stdout).unwrap();assert_eq!(reply["before"],reply["after"],"no-attempt raw budget commit");
 // Observe completion after caller timeout rather than mistaking returned PHP
 // fallback for cancellation of a still-running native helper.
 if enabled&&reply["fallback"]==true&&(capture["declared_media_transport"]["delay_ms"].as_u64().unwrap_or(0)>0||capture["declared_media_transport"]["helper_fault"]=="timeout"){let remaining=17.0-command_started.elapsed().as_secs_f64();if remaining>0.0{tokio::time::sleep(std::time::Duration::from_secs_f64(remaining)).await;}}
 modes.push(json!({"enabled":enabled,"caller":reply,"retained_helper":fixture.as_ref().map(|f|f.effects()),"native_actor_intents":warm.requested_actors(),"native_media_intents":warm.requested_media()}));drop(fixture);
 }
 output.push(json!({"width":capture["width"],"case":capture["case"],"scenario":capture["caller_scenario"],"owner":capture["owner"],"modes":modes}));server.abort();db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }
 std::fs::write(std::env::var("PROFILE_FULL_NATIVE").unwrap(),serde_json::to_vec_pretty(&output).unwrap()).unwrap();assert_eq!(output.len(),reference["captures"].as_array().unwrap().len());
}
