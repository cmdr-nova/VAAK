#!/usr/bin/env python3
"""Actual PostgreSQL-bound full-card checks against canonical PHP Unicode edges."""
import pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
s=subprocess.check_output(['python3',str(root/'api/bin/library-bridge-fresh-review.py')],text=True)
marker=next(line for line in s.splitlines() if 'UPDATE ap_users SET disabled_at=' in line)
extra=r'''
 db.batch_execute("DELETE FROM ap_asks;DELETE FROM ap_muted_words;").await?;
 for token in ["(media)","(quote)","(poll)"] {
  let body=format!("\u{a0}{token}\u{a0}");
  db.execute("UPDATE masto_statuses SET content_text=$1 WHERE note_id=$2",&[&body,&format!("{actor}/notes/own")]).await?;
  let cards=context::admit_for_render(db,&session,vec![own.clone()]).await?;assert_eq!(cards.len(),1);
  assert_eq!(cards[0].status()["content"],body,"current own body loaded intact");
  let html=card::render_admitted(&cards[0])?;
  assert!(html.contains(&body),"actual native full own card must preserve canonical Unicode placeholder borders: {token}");
  println!("INDEPENDENT UNICODE {token} canonical=true native={}",html.contains(&body));
 }
 let name="\u{a0}Current Unicode name\u{2009}";
 db.execute("UPDATE actor_profile SET name=$1 WHERE actor_key='viewer'",&[&name]).await?;
 db.execute("INSERT INTO ap_asks VALUES(88,'https://remote.test/post','Unicode identity?',$1)",&[&actor]).await?;
 let mut identity_status=remote.clone();identity_status["content"]=serde_json::json!("Current answer");identity_status["vaak_ask"]=serde_json::json!({"question":"Old question"});
 let cards=context::admit_for_render(db,&session,vec![identity_status]).await?;assert_eq!(cards.len(),1);
 let html=card::render_admitted(&cards[0])?;
 assert!(html.contains(name),"actual native full Ask card must preserve canonical current identity Unicode borders");
 println!("INDEPENDENT UNICODE identity canonical=true native={}",html.contains(name));
 db.batch_execute("DELETE FROM ap_asks;").await?;
'''
print(s.replace(marker,extra+marker))
