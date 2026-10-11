#!/usr/bin/env python3
"""Independent source/bridge review probes on disposable INTEGER/BIGINT PG.
Emits the actual Rust module harness; no production/account/network effects.
"""
import pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
s=subprocess.check_output(['python3',str(root/'api/bin/library-source-stage-postgres.py')],text=True)
marker = next(line for line in s.splitlines() if "UPDATE ap_users SET disabled_at=" in line)
extra=r'''
 let provenance=context::TrustedSession::from_php_session(1,"canonical".into(),"bookmarks".into(),true)?.with_bsky_bookmarks(db,vec![(at.into(),"did:plc:viewer".into(),false)]).await?;
 assert_eq!(provenance.bsky_bookmark_state(&wrapper),Some(false));
 assert!(context::TrustedSession::from_php_session(2,"canonical".into(),"bookmarks".into(),true)?.with_bsky_bookmarks(db,vec![(at.into(),"did:plc:viewer".into(),false)]).await.is_err());
 assert!(context::TrustedSession::from_php_session(1,"canonical".into(),"bookmarks".into(),true)?.with_bsky_bookmarks(db,vec![(at.into(),"did:plc:other".into(),false)]).await.is_err());
 db.batch_execute("DELETE FROM ap_user_blocks;DELETE FROM ap_mutes;DELETE FROM ap_asks;UPDATE rss_items SET title='Current private title',summary_text='Current private summary',mirror_note_id=NULL WHERE id=11;").await?;
 let nested=serde_json::json!({"id":"rss:11","source":"rss","uri":"https://article.test/post","content":"PRIVATE SUBSCRIPTION SNAPSHOT","account":{"acct":"Private Feed","uri":"https://feed.test"},"vaak_rss_item_id":11});
 for location in ["quote","reblog","compact"] {
   println!("RSS REVIEW LOCATION {location}");
   let mut parent=remote.clone();parent["content"]=serde_json::json!("Public comment");
   match location {"quote"=>{parent["quote"]=serde_json::json!({"state":"accepted","quoted_status":nested.clone()});},"reblog"=>{parent["reblog"]=nested.clone();},_=>{parent["vaak_quote_preview"]=nested.clone();}}
   assert!(context::admit_for_render(db,&other,vec![parent.clone()]).await?.is_empty(),"foreign nested RSS denied {location}");
   for bad in ["rss:999","rss:bad","rss:0","rss:-11"] {
      let mut malformed=parent.clone();let child=match location {"quote"=>&mut malformed["quote"]["quoted_status"],"reblog"=>&mut malformed["reblog"],_=>&mut malformed["vaak_quote_preview"]};child["id"]=serde_json::json!(bad);
      assert!(context::admit_for_render(db,&session,vec![malformed]).await?.is_empty(),"missing/malformed nested RSS denied {location} {bad}");
   }
   for marker in [serde_json::json!("11"),serde_json::json!(11.0),serde_json::json!(true),serde_json::Value::Null] {
      let mut malformed=parent.clone();let child=match location {"quote"=>&mut malformed["quote"]["quoted_status"],"reblog"=>&mut malformed["reblog"],_=>&mut malformed["vaak_quote_preview"]};child["id"]=serde_json::json!("ordinary");child["vaak_rss_item_id"]=marker;
      assert!(context::admit_for_render(db,&session,vec![malformed]).await?.is_empty(),"RSS numeric-string/float/bool/null marker denied {location}");
   }
   db.batch_execute("CREATE TEMP TABLE fixture_saved_rss AS SELECT * FROM rss_items;DELETE FROM rss_items WHERE id=11;").await?;
   assert!(context::admit_for_render(db,&session,vec![parent.clone()]).await?.is_empty(),"deleted nested RSS denied after warm source {location}");
   db.batch_execute("INSERT INTO rss_items SELECT * FROM fixture_saved_rss;DROP TABLE fixture_saved_rss;").await?;
   if location=="compact"{assert!(context::admit_for_render(db,&session,vec![parent]).await.is_err(),"current owner compact preview remains explicit full-source fallback");continue;}
   let cards=context::admit_for_render(db,&session,vec![parent.clone()]).await?;assert_eq!(cards.len(),1,"current owner nested RSS remains available {location}");
   let snapshot=cards[0].status().to_string();assert!(snapshot.contains("Current private title")&&!snapshot.contains("PRIVATE SUBSCRIPTION SNAPSHOT"),"current owner fresh source {location}");
   let html=cards.iter().map(card::render_admitted).collect::<anyhow::Result<Vec<_>>>()?.join("");assert!(!html.contains("PRIVATE SUBSCRIPTION SNAPSHOT"),"actual HTML removes stale source {location}");if location=="quote" {assert!(html.contains("Current private title"),"actual fresh quote HTML");}
   db.batch_execute("UPDATE rss_items SET title='Changed forbidden article';INSERT INTO ap_muted_words VALUES(1,'forbidden article');").await?;
   assert!(context::admit_for_render(db,&session,vec![parent.clone()]).await?.is_empty(),"fresh nested title words denied {location}");
   db.batch_execute("DELETE FROM ap_muted_words;UPDATE rss_items SET title='Current private title',summary_text='Changed forbidden summary';INSERT INTO ap_muted_words VALUES(1,'forbidden summary');").await?;assert!(context::admit_for_render(db,&session,vec![parent.clone()]).await?.is_empty(),"fresh nested summary words denied {location}");db.batch_execute("DELETE FROM ap_muted_words;UPDATE rss_items SET summary_text='Current private summary';UPDATE rss_feeds SET owner_user_id=2 WHERE id=1;").await?;
   assert!(context::admit_for_render(db,&session,vec![parent]).await?.is_empty(),"ownership mutation invalidates warm source {location}");
   db.batch_execute("UPDATE rss_feeds SET owner_user_id=1 WHERE id=1;").await?;
 }
 let mirror=serde_json::json!({"id":"public-mirror","uri":"https://remote.test/mirror","visibility":"public","content":"Ordinary public federated article","account":{"acct":"a@sub.remote.test","uri":"https://sub.remote.test/users/a"}});
 let mut parent=remote.clone();parent["content"]=serde_json::json!("Public comment");parent["quote"]=serde_json::json!({"state":"accepted","quoted_status":mirror});
 let cards=context::admit_for_render(db,&other,vec![parent]).await.map_err(|e|anyhow::anyhow!("public mirror control: {e}"))?;assert_eq!(cards.len(),1);assert!(cards[0].facts().rss_quote_row.is_none());let html=cards.iter().map(card::render_admitted).collect::<anyhow::Result<Vec<_>>>()?.join("");assert!(html.contains("Ordinary public federated article")&&!html.contains("Current private title"));
 println!("INDEPENDENT NESTED RSS PASS: foreign/deleted/malformed quote/reblog/compact, current owner fresh rendered HTML, ownership mutation, late words, compact fallback, ordinary foreign AS2 preserved");
'''
assert marker in s
print(s.replace(marker,extra+marker))
