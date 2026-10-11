#!/usr/bin/env python3
"""Probe known remote Delete versus unknown public snapshot in actual Library."""
import pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
s=subprocess.check_output(['python3',str(root/'api/bin/search-context-independent.py')],text=True)
a=s.index('pub(crate) async fn fixture(');end=s.index('#[tokio::main]',a);module_end=s.rfind('\n}',a,end);b=s.rfind('\n}',a,module_end)+2
p=s.index(' async fn pair(',a);q=s.index(' for (owner,input,expected,markers)',p);pair=s[p:q]
pair=pair.replace('let native=native_result?;',r'''let native=match native_result {Ok(v)=>v,Err(e)=>{assert!(e.to_string().contains("thin AS2 source needs canonical cached/signed hydration")||e.to_string()=="PHP fallback: compact quote preview needs retained AS2/PostView/RSS authority and cached recovery (link budget 3)","unexpected error {e}");let html=php["html"].as_str().unwrap();assert!(!expected,"supported positive cannot silently fall back");assert!(!html.contains("Deleted saved source marker")&&!html.contains("<article"),"canonical fallback must deny deleted body");use std::io::Write;let mut f=std::fs::OpenOptions::new().create(true).append(true).open("/tmp/vaak-library-delete-fallback-observations.jsonl")?;writeln!(f,"{}",serde_json::json!({"schema":schema,"php":html,"native_fallback":e.to_string()}))?;println!("INDEPENDENT LIBRARY PHP denies deletedbody while native explicit authority fallback PASS");return Ok(())}};''')
body=r'''pub(crate) async fn fixture(db:&tokio_postgres::Client)->anyhow::Result<()> {
 use crate::search_context::{SearchSession,admit_for_render};
'''+pair+r'''
 db.batch_execute("DELETE FROM ap_user_blocks;DELETE FROM ap_mutes;DELETE FROM ap_muted_words;DELETE FROM ap_deprioritized_actors;DELETE FROM ap_asks;DELETE FROM masto_polls;DELETE FROM events;").await?;
 let uri="https://remote.test/saved-delete";
 let saved=serde_json::json!({"id":"saved","uri":uri,"visibility":"public","content":"Deleted saved source marker","account":{"uri":"https://sub.remote.test/users/a","acct":"a@sub.remote.test","display_name":"Remote snapshot name"}});
 db.execute("INSERT INTO events(type,actor_id,object_id,id,action_taken,visibility) VALUES('Create','https://sub.remote.test/users/a',$1,100,'log','public')",&[&uri]).await?;
 pair(db,1,&serde_json::json!({"status":saved.clone(),"_review_return_view":"bookmarks","_review_delete_object":uri}),false,&[]).await?;
 for (id,kind) in [(101_i64,"Delete"),(102,"Tombstone")] {db.execute("INSERT INTO events(type,actor_id,object_id,id,action_taken,visibility) VALUES($1,'https://sub.remote.test/users/a',$2,$3::bigint,'log','public')",&[&kind,&uri,&id]).await?;pair(db,1,&serde_json::json!({"status":saved.clone(),"_review_return_view":"bookmarks"}),false,&[]).await?;}
 let mut nested=serde_json::json!({"id":"outer","uri":"https://ordinary-public.test/outer","visibility":"public","content":"Outer deleted quote marker","account":{"uri":"https://ordinary-public.test/users/a","acct":"a@ordinary-public.test"}});nested["quote"]=serde_json::json!({"state":"accepted","quoted_status":saved.clone()});pair(db,1,&serde_json::json!({"status":nested,"_review_return_view":"bookmarks"}),false,&[]).await?;
 let mut alias=saved.clone();alias["uri"]=serde_json::json!(format!("{uri}/"));pair(db,1,&serde_json::json!({"status":alias,"_review_return_view":"bookmarks"}),false,&[]).await?;
 for field in ["reblog","vaak_quote_preview"] {let mut child=serde_json::json!({"id":"outer","uri":"https://ordinary-public.test/outer","visibility":"public","content":"Outer deleted child marker","account":{"uri":"https://ordinary-public.test/users/a","acct":"a@ordinary-public.test"}});child[field]=saved.clone();pair(db,1,&serde_json::json!({"status":child,"_review_return_view":"bookmarks"}),false,&[]).await?;}
 db.execute("INSERT INTO events(type,actor_id,object_id,id,action_taken,visibility) VALUES('Create','https://sub.remote.test/users/a',$1,103,'log','public')",&[&uri]).await?;
 pair(db,1,&serde_json::json!({"status":saved.clone(),"_review_return_view":"bookmarks"}),true,&["Deleted saved source marker"]).await?;
 db.execute("INSERT INTO events(type,actor_id,object_id,id,action_taken,visibility) VALUES('Announce','https://someone.test/users/b',$1,104,'deleted','public')",&[&uri]).await?;
 pair(db,1,&serde_json::json!({"status":saved.clone(),"_review_return_view":"bookmarks"}),true,&["Deleted saved source marker"]).await?;
 let mut unknown=saved.clone();unknown["uri"]=serde_json::json!("https://ordinary-public.test/cached");unknown["content"]=serde_json::json!("Unknown public positive marker");pair(db,1,&serde_json::json!({"status":unknown,"_review_return_view":"bookmarks"}),true,&["Unknown public positive marker"]).await?;
 println!("INDEPENDENT LIBRARY DELETE denies markedCreate/latestDelete/Tombstone/rootalias/nested sources; preserves newerCreate/UndoAnnounce/unknownpublic");Ok(())
}'''
s=s[:a]+body+s[b:];print(s)
