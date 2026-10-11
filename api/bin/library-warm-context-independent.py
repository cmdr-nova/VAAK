#!/usr/bin/env python3
"""Actual Library caller/native token proof of complete warm source boundaries.
Controlled edits affect only disposable durable rows after source assembly;
this does not execute a federation writer or claim whole serializer coverage.
"""
import pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
s=subprocess.check_output(['python3',str(root/'api/bin/search-context-independent.py')],text=True)
a=s.index('pub(crate) async fn fixture(');end=s.index('#[tokio::main]',a);module_end=s.rfind('\n}',a,end);b=s.rfind('\n}',a,module_end)+2
p=s.index(' async fn pair(',a);q=s.index(' for (owner,input,expected,markers)',p)
pair=s[p:q]
body=r'''pub(crate) async fn fixture(db:&tokio_postgres::Client)->anyhow::Result<()> {
 use crate::search_context::{SearchSession,admit_for_render};
'''+pair+r'''
 let actor="https://mkultra.monster/users/viewer";let other="https://mkultra.monster/users/other";
 db.batch_execute("DELETE FROM ap_user_blocks;DELETE FROM ap_mutes;DELETE FROM ap_muted_words;DELETE FROM ap_deprioritized_actors;DELETE FROM ap_asks;DELETE FROM masto_polls;").await?;
 db.batch_execute("UPDATE masto_statuses SET content_text='Before assembly body',spoiler_text='',sensitive=0 WHERE local_id=9;UPDATE actor_profile SET name='Before assembly name' WHERE actor_key='viewer';").await?;
 let stored:serde_json::Value=db.query_one("SELECT to_jsonb(s) FROM masto_statuses s WHERE local_id=9",&[]).await?.get(0);
 let profile_name:String=db.query_one("SELECT name FROM actor_profile WHERE actor_key='viewer'",&[]).await?.get(0);
 let source=serde_json::json!({"id":"9","uri":format!("{actor}/notes/own"),"visibility":"private","content":stored["content_text"],"spoiler_text":stored["spoiler_text"],"sensitive":stored["sensitive"],"account":{"uri":actor,"acct":"viewer","display_name":profile_name}});
 // Legitimate complete normalized source has been assembled; isolated current
 // rows now represent a later same-author content/CW/profile edit.
 db.batch_execute("UPDATE masto_statuses SET content_text='After assembly body',spoiler_text='After assembly warning',sensitive=1 WHERE local_id=9;UPDATE actor_profile SET name='After assembly name' WHERE actor_key='viewer';").await?;
 db.execute("INSERT INTO followers VALUES($1,$2)",&[&actor,&other]).await?;
 for owner in [1,2] {pair(db,owner,&serde_json::json!({"status":source.clone(),"_review_canonical_own_id":true,"_review_return_view":"bookmarks"}),true,&[]).await?;}
 let outer=serde_json::json!({"id":"remote-wrapper","uri":"https://remote.test/post","visibility":"public","content":"Parent cached body","account":{"uri":"https://sub.remote.test/users/a","acct":"a@sub.remote.test","display_name":"Parent cached name"}});
 let mut boost=outer.clone();boost["content"]=serde_json::json!("");boost["reblog"]=source.clone();pair(db,1,&serde_json::json!({"status":boost,"_review_canonical_own_id":true,"_review_return_view":"bookmarks"}),true,&["After assembly body","After assembly warning"]).await?;
 let mut quoted=outer.clone();quoted["quote"]=serde_json::json!({"state":"accepted","quoted_status":source.clone()});pair(db,2,&serde_json::json!({"status":quoted,"_review_return_view":"bookmarks"}),true,&[]).await?;
 db.batch_execute("DELETE FROM followers;").await?;
 // Removing a warning after assembly isolates stale-warning removal too.
 db.batch_execute("UPDATE masto_statuses SET spoiler_text='Removed stored warning',sensitive=1 WHERE local_id=9;").await?;
 let mut stale_warning=source.clone();stale_warning["spoiler_text"]=serde_json::json!("Removed stored warning");stale_warning["sensitive"]=serde_json::json!(true);
 db.batch_execute("UPDATE masto_statuses SET spoiler_text='',sensitive=0 WHERE local_id=9;").await?;
 pair(db,1,&serde_json::json!({"status":stale_warning,"_review_canonical_own_id":true,"_review_return_view":"bookmarks"}),true,&[]).await?;
 println!("INDEPENDENT LIBRARY WARM caller fullHTML observations PASS (source assembly then isolated durable content/CW/profile edits; grants current)");Ok(())
}'''
s=s[:a]+body+s[b:]
print(s)
