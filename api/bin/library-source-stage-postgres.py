#!/usr/bin/env python3
"""Real-module typed current quote/Ask source controls, disposable PG only."""
import pathlib, subprocess
root=pathlib.Path(__file__).resolve().parents[2]
s=subprocess.check_output(['python3',str(root/'api/bin/library-current-facts-review.py')],text=True)
marker=' db.batch_execute("UPDATE ap_users SET disabled_at=\'disabled\' WHERE id=1;"'
extra=r'''
 for h in ["bsky.app","bsky.app.evil.test","notbsky.app"] {
   let ref_actor=format!("https://{h}/profile/did:plc:viewer");let status=serde_json::json!({"id":"self-test","uri":"https://remote.test/hostcontrol","content":"body","source":"bluesky","account":{"uri":ref_actor,"acct":"viewer","avatar":"https://cache.test/avatar"}});
   assert_eq!(facts::resolve(db,1,actor,&status,&session,false).await?.moderation.is_self,h=="bsky.app","exact host self identity");
 }
 db.batch_execute("DELETE FROM ap_asks;INSERT INTO ap_asks VALUES(88,'https://remote.test/post','Fresh DB question',NULL);UPDATE masto_statuses SET content_text='Current edited answer' WHERE note_id='https://remote.test/post';").await?;
 let mut ask_status=remote.clone();ask_status["content"]=serde_json::json!("Old body");ask_status["vaak_ask"]=serde_json::json!({"question":"Old question","answer":"Old stale answer"});
 let cards=context::admit_for_render(db,&session,vec![ask_status.clone()]).await?;assert_eq!(cards[0].status()["content"],"Current edited answer");assert_eq!(cards[0].status()["vaak_ask"]["question"],"Fresh DB question");assert!(!cards[0].status().to_string().contains("Old stale answer"));

 for answer in ["\u{a0}","\u{2009}","\u{a0} Current answer \u{a0}","\u{2009} Current answer \u{2009}"] {
   db.execute("UPDATE masto_statuses SET content_text=$1 WHERE note_id='https://remote.test/post'",&[&answer]).await?;
   let cards=context::admit_for_render(db,&session,vec![ask_status.clone()]).await?;
   assert_eq!(cards[0].status()["content"],answer,"current Ask ASCII trim");
   let html=card::render_admitted(&cards[0])?;assert!(html.contains(answer),"current unicode answer painted");
 }
 db.batch_execute("UPDATE masto_statuses SET content_text='Current denied phrase' WHERE note_id='https://remote.test/post';INSERT INTO ap_muted_words VALUES(1,'denied phrase');").await?;assert!(context::admit_for_render(db,&session,vec![ask_status]).await?.is_empty());db.batch_execute("DELETE FROM ap_muted_words;DELETE FROM ap_asks;").await?;
 db.batch_execute("INSERT INTO mentions(object_id,deleted_at,id,ask_question,ask_answer) VALUES('https://remote.test/post',NULL,99,'Remote current question','Remote current answer');").await?;
 let mut status=remote.clone();status["content"]=serde_json::json!("Old remote body");let cards=context::admit_for_render(db,&session,vec![status]).await?;assert_eq!(cards[0].status()["content"],"Remote current answer");db.batch_execute("DELETE FROM mentions WHERE id=99;").await?;
 db.batch_execute("UPDATE rss_items SET mirror_note_id='https://remote.test/quote',title='Current owner article',summary_text='Fresh quote summary' WHERE id=11;").await?;
 let q=serde_json::json!({"id":"quote-source","uri":"https://remote.test/quote","url":"https://remote.test/@a/quote","visibility":"public","content":"Public ordinary body","account":{"uri":"https://sub.remote.test/users/a","acct":"a@sub.remote.test"}});
 let mut parent=remote.clone();parent["content"]=serde_json::json!("My comment");parent["quote"]=serde_json::json!({"state":"pending","quoted_status":q});parent["quote_url"]=serde_json::json!("https://remote.test/@a/quote");
 let cards=context::admit_for_render(db,&session,vec![parent.clone()]).await?;assert_eq!(cards[0].facts().rss_quote_row.as_ref().unwrap()["title"],"Current owner article");assert_eq!(cards[0].status()["quote"]["state"],"pending");
 let cards=context::admit_for_render(db,&other,vec![parent.clone()]).await?;assert!(cards[0].facts().rss_quote_row.is_none());assert_eq!(cards[0].status()["quote"]["quoted_status"]["content"],"Public ordinary body");assert!(!cards[0].status().to_string().contains("Current owner article"));
 db.batch_execute("UPDATE rss_items SET summary_text='New denied article';INSERT INTO ap_muted_words VALUES(1,'denied article');").await?;assert!(context::admit_for_render(db,&session,vec![parent.clone()]).await?.is_empty());db.batch_execute("DELETE FROM ap_muted_words;").await?;
 let mut denied=parent;denied["quote"]["quoted_status"]["visibility"]=serde_json::json!("direct");assert!(context::admit_for_render(db,&session,vec![denied]).await?.is_empty());
 println!("SOURCE STAGE PASS: fresh local/mention Ask answer, late words, current owner RSS quote, foreign public AS2 preservation, nested final audience");
'''
assert marker in s
print(s.replace(marker,extra+marker))
