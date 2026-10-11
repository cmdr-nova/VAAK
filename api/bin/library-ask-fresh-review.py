#!/usr/bin/env python3
"""Independent current DB Ask Unicode/word checks on actual typed renderer.
Emit disposable PG harness; no runtime edits or production writes.
"""
import pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
s=subprocess.check_output(['python3',str(root/'api/bin/library-bridge-fresh-review.py')],text=True)
marker=next(line for line in s.splitlines() if 'UPDATE ap_users SET disabled_at=' in line)
extra=r'''
 db.batch_execute("DELETE FROM ap_asks;INSERT INTO ap_asks VALUES(88,'https://remote.test/post','Current question',NULL);DELETE FROM ap_muted_words;").await?;
 for (answer,expected) in [("\u{a0}","\u{a0}"),("\u{2009}","\u{2009}"),("\u{a0}Answer\u{a0}","\u{a0}Answer\u{a0}"),("\u{2009}Answer\u{2009}","\u{2009}Answer\u{2009}"),(" \tAnswer\r\n","Answer"),(" \t\u{a0}Answer\u{2009}\n","\u{a0}Answer\u{2009}")] {
   db.execute("UPDATE masto_statuses SET content_text=$1 WHERE note_id='https://remote.test/post'",&[&answer]).await?;
   let mut status=remote.clone();status["content"]=serde_json::json!("Old answer");status["vaak_ask"]=serde_json::json!({"question":"Old question","answer":"Stale private answer"});
   let cards=context::admit_for_render(db,&session,vec![status]).await?;assert_eq!(cards.len(),1,"current Ask Unicode still visible");
   assert_eq!(cards[0].status()["content"],expected,"current Ask answer preserves canonical nonASCII boundary");
   let html=cards.iter().map(card::render_admitted).collect::<anyhow::Result<Vec<_>>>()?.join("");assert!(html.contains(expected)&&!html.contains("Stale private answer"),"real Ask HTML preserves current Unicode answer");
 }
 for question in ["\u{a0}","\u{2009}","\u{a0}Current question\u{2009}"] {
   db.execute("UPDATE ap_asks SET question=$1",&[&question]).await?;
   let mut status=remote.clone();status["content"]=serde_json::json!("Old body");let cards=context::admit_for_render(db,&session,vec![status]).await?;let html=cards.iter().map(card::render_admitted).collect::<anyhow::Result<Vec<_>>>()?.join("");assert!(html.contains(&format!("\">{question}</blockquote>")),"actual Ask question chrome keeps canonical Unicode boundary");
 }
 db.batch_execute("UPDATE masto_statuses SET content_text='Fresh denied answer' WHERE note_id='https://remote.test/post';INSERT INTO ap_muted_words VALUES(1,'denied answer');").await?;
 let mut status=remote.clone();status["content"]=serde_json::json!("Old allowed answer");assert!(context::admit_for_render(db,&session,vec![status.clone()]).await?.is_empty(),"current answer newly muted before paint");
 db.batch_execute("DELETE FROM ap_muted_words;UPDATE masto_statuses SET content_text='Fresh safe answer' WHERE note_id='https://remote.test/post';UPDATE ap_asks SET question='Fresh denied question';INSERT INTO ap_muted_words VALUES(1,'denied question');").await?;
 assert!(context::admit_for_render(db,&session,vec![status]).await?.is_empty(),"current question newly muted before paint");db.batch_execute("DELETE FROM ap_muted_words;DELETE FROM ap_asks;").await?;
 println!("INDEPENDENT ASK UNICODE PASS: current NBSP/thinspace/ASCII boundaries and rendered body/question, late answer/question mute, stale answer absent");
'''
assert marker in s
print(s.replace(marker,extra+marker))
