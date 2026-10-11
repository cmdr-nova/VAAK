#!/usr/bin/env python3
"""Fresh independent adversarial checks on actual Library modules, disposable PG only.
Emit harness; compile with a separate /tmp target, run with LIBRARY_FIXTURE_DB.
No production environment, external account actions, or network calls.
"""
import pathlib, subprocess
root = pathlib.Path(__file__).resolve().parents[2]
source = subprocess.check_output(['python3', str(root/'api/bin/library-facts-independent-postgres.py')], text=True)
marker = ' println!("LEGACY typed full wrapper/current owner facts/final wrapper moderation PASS");'
assert marker in source
extra = r'''
 db.execute("UPDATE masto_statuses SET sensitive=0,spoiler_text=$1",&[&"\u{a0}"]).await?;
 assert!(facts::resolve(db,1,actor,&own,&session,false).await?.own.unwrap().sensitive,"PHP trim retains NBSP-only CW");
 db.batch_execute("UPDATE masto_statuses SET spoiler_text='CW still present';").await?;
 for depth in [14,15,16,17] {
   let mut tree=serde_json::json!({});for _ in 0..depth {tree=serde_json::json!({"child":tree});}
   assert_eq!(crate::hidden::library_status_hidden(&serde_json::json!({"bsky_post":tree}),&crate::hidden::HiddenSets::default()),depth>16,"actual PHP depth boundary");
 }
 let mut tree=serde_json::json!({});for _ in 0..18 {tree=serde_json::json!({"child":tree});}
 for metadata in [tree,serde_json::json!({"viewer":{"blockedBy":true}})] {assert!(!crate::hidden::library_status_hidden(&serde_json::json!({"content":"clean","metadata":metadata}),&crate::hidden::HiddenSets::default()));}
 let mut blocked=wrapper.clone();blocked["post"]["viewer"]=serde_json::json!({"blockedBy":true});
 db.execute("UPDATE bsky_posts SET raw_json=$1 WHERE bsky_uri=$2",&[&blocked.to_string(),&at]).await?;
 assert!(context::admit_for_render(db,&session,vec![blocked]).await?.is_empty());
 db.execute("UPDATE bsky_posts SET raw_json=$1 WHERE bsky_uri=$2",&[&wrapper.to_string(),&at]).await?;
 let mut normalized=serde_json::json!({"id":"bsky:saved","uri":at,"content":"clean","account":{"acct":"v.test"},"bsky_post":wrapper.clone()});
 assert_eq!(source::hydrate(db,vec![normalized.clone()]).await?.len(),1);
 let mut adult=wrapper.clone();adult["post"]["labels"]=serde_json::json!([{"val":"porn"}]);
 db.execute("UPDATE bsky_posts SET raw_json=$1 WHERE bsky_uri=$2",&[&adult.to_string(),&at]).await?;
 assert!(source::hydrate(db,vec![normalized.clone()]).await.is_err(),"fresh adult labels cannot reuse stale clean normalized body");
 normalized["bsky_post"]=adult.clone();assert!(source::hydrate(db,vec![normalized.clone()]).await.is_err(),"adult source with clean normalized sensitive flag must fallback");
 normalized["sensitive"]=serde_json::json!(true);assert!(source::hydrate(db,vec![normalized]).await.is_ok());
 db.execute("UPDATE bsky_posts SET raw_json=$1 WHERE bsky_uri=$2",&[&wrapper.to_string(),&at]).await?;
 db.batch_execute("INSERT INTO ap_blocks VALUES(99,'domain','remote.test','block');").await?;
 assert!(!facts::resolve(db,1,actor,&remote,&session,false).await?.moderation.is_wafrn);
 db.batch_execute("DELETE FROM ap_blocks WHERE id=99; INSERT INTO ap_asks VALUES(77,'https://remote.test/post','Anonymous current question',NULL);").await?;
 let mut stale=remote.clone();stale["content"]=serde_json::json!("safe answer");stale["vaak_ask"]=serde_json::json!({"asker_actor":"https://mkultra.monster/users/viewer","asker_display":"Stale private name","asker_avatar":"https://private.test/identifying-avatar"});
 let cards=context::admit_for_render(db,&session,vec![stale]).await?;let ask=cards[0].facts().ask_row.as_ref().unwrap();
 assert_eq!(ask["asker_actor"],"");assert_eq!(ask["asker_handle"],"");assert_eq!(ask["asker_avatar"],"https://mkultra.monster/img/avatar/default.webp");
 assert!(!cards[0].status().to_string().contains("private.test"));
 db.batch_execute("DELETE FROM ap_asks;").await?;
 let foreign_like="at://did:plc:viewer/app.bsky.feed.like/foreign";let foreign_repost="at://did:plc:viewer/app.bsky.feed.repost/foreign";
 let mut foreign=wrapper.clone();foreign["post"]["viewer"]=serde_json::json!({"like":foreign_like,"repost":foreign_repost});
 let f=bsky::resolve(db,2,&foreign,true).await?.unwrap();assert!(f.like_record.is_empty()&&f.repost_record.is_empty());
 assert!(bsky::resolve(db,1,&foreign,true).await.is_err(),"unknown same-owner external record requires explicit fallback, not false Unlike state");
 db.execute("INSERT INTO bsky_favourite_cache VALUES(1,$1,$2)",&[&at,&foreign["post"].to_string()]).await?;
 db.execute("INSERT INTO ap_action_queue(owner_user_id,platform,action_kind,target_key,status,confirmed_state,receipt_json,updated_at) VALUES(1,'bsky','boost',$1,'succeeded',1,$2,'2026-10-10T10:00:00Z')",&[&at,&serde_json::json!({"record_uri":foreign_repost}).to_string()]).await?;
 let f=bsky::resolve(db,1,&foreign,true).await?.unwrap();assert_eq!(f.like_record,foreign_like);assert_eq!(f.repost_record,foreign_repost);
 let f=bsky::resolve(db,2,&foreign,true).await?.unwrap();assert!(f.like_record.is_empty()&&f.repost_record.is_empty());
 db.batch_execute("DELETE FROM masto_bookmarks;").await?;
 db.execute("INSERT INTO bsky_bookmark_cache VALUES(1,$1)",&[&at]).await?;assert!(bsky::resolve(db,1,&foreign,true).await?.unwrap().bookmarked);assert!(!bsky::resolve(db,2,&foreign,true).await?.unwrap().bookmarked);
 for action in ["like","boost","bookmark"] {db.execute("INSERT INTO ap_action_queue(owner_user_id,platform,action_kind,target_key,status,confirmed_state,receipt_json,updated_at) VALUES(1,'bsky',$1,$2,'succeeded',0,'{}','2026-10-10T11:00:00Z')",&[&action,&at]).await?;}
 let f=bsky::resolve(db,1,&foreign,true).await?.unwrap();assert!(f.like_record.is_empty()&&f.repost_record.is_empty()&&!f.bookmarked);assert_eq!(f.confirmed_like,Some(false));assert_eq!(f.confirmed_bookmark,Some(false));
 println!("INDEPENDENT current facts PASS: depth/source metadata, wrapper blockedBy, stale adult-label fallback, Wafrn domain policy, anonymous identity scrub, two-owner records/cache/bookmarks, undo receipt precedence");
'''
print(source.replace(marker, marker+extra))
