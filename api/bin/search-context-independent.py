#!/usr/bin/env python3
"""Independent same-DB PHP policy/painter controls around actual Search tokens."""
import pathlib,subprocess
root=pathlib.Path(__file__).resolve().parents[2]
s=subprocess.check_output(['python3',str(root/'api/bin/search-context-postgres-fixture.py')],text=True)
s=s.replace('library_facts_integer','search_context_review_integer').replace('library_facts_bigint','search_context_review_bigint')
# Exercise a legitimate local Ask source, not a local ap_asks row attached to
# a foreign URI. A canonical cached local Ask carries its immutable question
# and asker actor; current answer text comes from the local note.
s=s.replace("INSERT INTO ap_asks VALUES(88,'https://remote.test/post'", "INSERT INTO ap_asks VALUES(88,'https://mkultra.monster/users/viewer/notes/own'")
s=s.replace('let mut ask=remote.clone();','let mut ask=own.clone();')
s=s.replace('{"question":"Stale question"}', '{"question":"Fresh question","asker_actor":"https://mkultra.monster/users/viewer"}')
s=s.replace('html.contains("Current authoritative answer")','html.contains("Fresh own content")')
marker=' let cards=admit_for_render(db,&session,vec![remote.clone(),own.clone()]).await?;'
extra=r'''
 async fn pair(db:&tokio_postgres::Client,owner:i64,input:&serde_json::Value,expected:bool,markers:&[&str])->anyhow::Result<()> {
  let schema:String=db.query_one("SELECT current_schema()",&[]).await?.get(0);
  let is_library=input.get("_review_return_view").and_then(serde_json::Value::as_str)==Some("bookmarks");
  let expect_slash_fallback=input.get("_review_expected_slash_fallback").and_then(serde_json::Value::as_bool).unwrap_or(false);
  let expect_visibility_fallback=input.get("_review_expected_visibility_fallback").and_then(serde_json::Value::as_bool).unwrap_or(false);
  let mut cmd=std::process::Command::new("ROOT/api/bin/php-policy-test");cmd.args(["-d","extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so","ROOT/api/bin/search-context-php-independent.php",&schema,&owner.to_string()]).stdin(std::process::Stdio::piped()).stdout(std::process::Stdio::piped()).stderr(std::process::Stdio::piped());
  let mut child=cmd.spawn()?;std::io::Write::write_all(child.stdin.as_mut().unwrap(),serde_json::to_string(input)?.as_bytes())?;drop(child.stdin.take());let result=child.wait_with_output()?;assert!(result.status.success(),"same DB PHP failed {}",String::from_utf8_lossy(&result.stderr));
  let php:serde_json::Value=serde_json::from_slice(&result.stdout)?;assert_eq!(php["allowed"],expected,"actual PHP current admission owner {owner}");
  let input=php.get("status").unwrap_or(input);
  let native_session=SearchSession::from_php_session(owner,"canonical-current-session".into(),false)?;let native_result=if is_library{let session=context::TrustedSession::from_php_session(owner,"canonical-current-session".into(),"bookmarks".into(),false)?;context::admit_for_render(db,&session,vec![input.clone()]).await.and_then(|cards|cards.iter().map(card::render_admitted).collect::<anyhow::Result<Vec<_>>>())}else{admit_for_render(db,&native_session,vec![input.clone()]).await.and_then(|cards|cards.iter().map(card::render_search_admitted).collect::<anyhow::Result<Vec<_>>>())};if expect_visibility_fallback||expect_slash_fallback{let error=match native_result{Ok(_)=>panic!("visibility conflict unexpectedly accepted"),Err(e)=>e};assert_eq!(error.to_string(),if expect_slash_fallback{"PHP fallback: own trailing-slash presentation requires canonical lookup"}else{"PHP fallback: own cached/current visibility presentation conflicts"});assert!(php["html"].as_str().unwrap().contains("Current private body"));println!("INDEPENDENT SEARCH paired canonicalPHP wholebatchvisibilityfallback PASS");return Ok(())}let native=native_result?;assert_eq!(!native.is_empty(),expected,"sameDB native admission");
  if expected {let php_html=php["html"].as_str().unwrap();let native_html=&native[0];for marker in markers{let (needle,wanted)=marker.strip_prefix('!').map(|m|(m,false)).unwrap_or((marker,true));assert_eq!(php_html.contains(needle),wanted,"actual PHP displayed facet {marker}");assert_eq!(native_html.contains(needle),wanted,"native displayed facet {marker}");}
   use std::io::Write;let mut file=std::fs::OpenOptions::new().create(true).append(true).open("/tmp/vaak-search-context-independent-cards.jsonl")?;writeln!(file,"{}",serde_json::json!({"schema":schema,"owner":owner,"input":input,"markers":markers,"php":php_html,"native":native_html,"delete_writer_affected":php["delete_writer_affected"]}))?;
  }
  println!("INDEPENDENT SEARCH paired owner{owner} expected{expected} markers{markers:?} PASS");Ok(())
 }
 for (owner,input,expected,markers) in [(1,&remote,true,vec!["Cached public body word-mute"]),(2,&remote,true,vec!["Cached public body word-mute"]),(1,&own,true,vec!["Current private body"]),(2,&own,false,vec![])] {pair(db,owner,input,expected,&markers).await?;}
 let mut conflict_pair=own.clone();conflict_pair["visibility"]=serde_json::json!("public");pair(db,1,&serde_json::json!({"status":conflict_pair,"_review_expected_visibility_fallback":true}),true,&["Current private body"]).await?;
 pair(db,1,&serde_json::json!({"status":own.clone(),"_review_canonical_own_id":true,"_review_return_view":"bookmarks","_review_current_own_source":true}),true,&["Current private body","Current warning"]).await?;
 let mut canonical_id=own.clone();canonical_id["visibility"]=serde_json::json!("private");pair(db,1,&serde_json::json!({"status":canonical_id.clone(),"_review_canonical_own_id":true}),true,&["Current warning"]).await?;
 pair(db,1,&serde_json::json!({"status":canonical_id.clone(),"_review_canonical_own_id":true}),true,&["Current warning","class=\"cw-gate\""]).await?;
 let mut stale_cw=canonical_id.clone();stale_cw["spoiler_text"]=serde_json::json!("Old cached CW");stale_cw["sensitive"]=serde_json::json!(true);
 db.batch_execute("UPDATE masto_statuses SET spoiler_text='',sensitive=0 WHERE local_id=9;").await?;pair(db,1,&serde_json::json!({"status":stale_cw.clone(),"_review_canonical_own_id":true}),true,&["!Old cached CW","!class=\"cw-gate\""]).await?;
 db.batch_execute("UPDATE masto_statuses SET sensitive=1 WHERE local_id=9;UPDATE actor_profile SET auto_unblur_sensitive=0 WHERE actor_key='viewer';").await?;pair(db,1,&serde_json::json!({"status":canonical_id.clone(),"_review_canonical_own_id":true}),true,&["Sensitive content","class=\"cw-gate\""]).await?;
 db.batch_execute("UPDATE actor_profile SET auto_unblur_sensitive=1 WHERE actor_key='viewer';").await?;pair(db,1,&serde_json::json!({"status":canonical_id.clone(),"_review_canonical_own_id":true}),true,&["!class=\"cw-gate\""]).await?;
 db.batch_execute("UPDATE masto_statuses SET spoiler_text='New custom CW' WHERE local_id=9;").await?;pair(db,1,&serde_json::json!({"status":stale_cw.clone(),"_review_canonical_own_id":true}),true,&["New custom CW","!Old cached CW","class=\"cw-gate\""]).await?;
 let mut slash=canonical_id.clone();slash["uri"]=serde_json::json!(format!("{actor}/notes/own/"));pair(db,1,&serde_json::json!({"status":slash.clone(),"_review_canonical_own_id":true,"_review_expected_slash_fallback":true}),true,&["New custom CW","Current private body"]).await?;
 let mut boost_cw=remote.clone();boost_cw["content"]=serde_json::json!("");boost_cw["reblog"]=canonical_id.clone();pair(db,1,&serde_json::json!({"status":boost_cw.clone(),"_review_canonical_own_id":true}),true,&["New custom CW","Current private body"]).await?;
 db.batch_execute("UPDATE masto_statuses SET spoiler_text='Current warning',sensitive=1 WHERE local_id=9;").await?;
 db.batch_execute("UPDATE events SET visibility='private' WHERE id=100;").await?;pair(db,1,&remote,false,&[]).await?;
 db.execute("INSERT INTO following VALUES($1,'https://sub.remote.test/users/a')",&[&actor]).await?;pair(db,1,&remote,true,&["Cached public body word-mute"]).await?;db.batch_execute("DELETE FROM following;").await?;pair(db,1,&remote,false,&[]).await?;
 db.batch_execute("INSERT INTO mentions(owner_user_id,activity_type,object_id,id) VALUES(1,'Create','https://remote.test/post',990);").await?;pair(db,1,&remote,true,&["Cached public body word-mute"]).await?;pair(db,2,&remote,false,&[]).await?;db.batch_execute("UPDATE mentions SET deleted_at='deleted';").await?;pair(db,1,&remote,false,&[]).await?;db.batch_execute("DELETE FROM mentions;UPDATE events SET visibility='public' WHERE id=100;").await?;
 db.execute("INSERT INTO followers VALUES($1,$2)",&[&actor,&other_actor]).await?;pair(db,2,&own,true,&["Cached own body"]).await?;pair(db,2,&serde_json::json!({"status":own.clone(),"_review_canonical_own_id":true,"_review_return_view":"bookmarks","_review_current_own_source":true}),true,&["Current private body","Current warning"]).await?;let mut current_private=own.clone();current_private["visibility"]=serde_json::json!("private");pair(db,2,&serde_json::json!({"status":current_private,"_review_canonical_own_id":true}),true,&["Cached own body"]).await?;db.batch_execute("DELETE FROM followers;").await?;pair(db,2,&own,false,&[]).await?;
 for field in ["to_json","cc_json"] {db.execute(&format!("UPDATE outbox_notes SET visibility='direct',{field}=$1"),&[&format!("[\"{other_actor}\"]")]).await?;pair(db,2,&own,true,&["Cached own body"]).await?;db.batch_execute("UPDATE outbox_notes SET to_json='[]',cc_json='[]';").await?;pair(db,2,&own,false,&[]).await?;}db.batch_execute("UPDATE outbox_notes SET visibility='private';").await?;
 let mut sensitive=remote.clone();sensitive["sensitive"]=serde_json::json!(true);
 db.batch_execute("UPDATE actor_profile SET auto_unblur_sensitive=0 WHERE actor_key='viewer';").await?;pair(db,1,&sensitive,true,&["class=\"cw-gate\""]).await?;
 db.batch_execute("UPDATE actor_profile SET auto_unblur_sensitive=1 WHERE actor_key='viewer';").await?;pair(db,1,&sensitive,true,&["!class=\"cw-gate\""]).await?;
 sensitive["spoiler_text"]=serde_json::json!("Snapshot custom CW");pair(db,1,&sensitive,true,&["class=\"cw-gate\"","Snapshot custom CW"]).await?;
'''.replace('ROOT',str(root))
assert marker in s
s=s.replace(marker,extra+marker)
# Pair actual canonical PHP at each independently meaningful state transition.
controls=[
 ('Search word mute intentionally remains visible','pair(db,1,&remote,true,&["Cached public body word-mute"]).await?;'),
 ('assert_eq!(admit_for_render(db,&other,vec![remote.clone()]).await?.len(),1);db.batch_execute("DELETE FROM ap_user_blocks;', 'pair(db,1,&remote,false,&[]).await?;pair(db,2,&remote,true,&["Cached public body word-mute"]).await?;'),
 ('"current deletion {kind}");', 'pair(db,1,&remote,false,&[]).await?;'),
 ('"author indexing exception");','pair(db,1,&own,true,&["Current private body"]).await?;'),
 ('"withdrawn current indexing");','pair(db,2,&own,false,&[]).await?;'),
 ('"canonical cached body fallback {body:?}");','pair(db,1,&own,true,&[expected]).await?;pair(db,1,&serde_json::json!({"status":own.clone(),"_review_canonical_own_id":true}),true,&[expected]).await?;'),
 ('!html.contains("Stale question"));','pair(db,1,&ask,true,&["Fresh question","Current asker","Fresh own content"]).await?;'),
 ('vec![nested.clone()]).await?.len(),1);','pair(db,1,&nested,true,&["Cached public body word-mute","Cached own body"]).await?;'),
]
for anchor,call in controls:
 if anchor=='Search word mute intentionally remains visible':
  anchor='"Search word mute intentionally remains visible");'
 if anchor.startswith('assert_eq!'):
  # Place the paired call before the cleanup, while the rule still exists.
  s=s.replace(anchor,call+anchor)
 else:
  assert anchor in s,anchor
  s=s.replace(anchor,anchor+call)
# The Ask input is needed by the actual PHP oracle after native admission.
s=s.replace('vec![ask]).await?', 'vec![ask.clone()]).await?')
remote_ask=r'''
 db.batch_execute("ALTER TABLE mentions ADD owner_actor_id TEXT;ALTER TABLE mentions ADD created_at TEXT;ALTER TABLE mentions ADD activity_id TEXT;ALTER TABLE mentions ADD type TEXT;ALTER TABLE mentions ADD content TEXT;ALTER TABLE mentions ADD in_reply_to TEXT;ALTER TABLE mentions ADD media_urls TEXT;ALTER TABLE mentions ADD spoiler_text TEXT;ALTER TABLE mentions ADD sensitive INTEGER;CREATE UNIQUE INDEX review_mention_object ON mentions(owner_user_id,object_id);").await?;
 let initial=serde_json::json!({"owner_user_id":1,"owner_actor_id":actor,"actor_id":"https://sub.remote.test/users/a","object_id":"https://remote.test/post","activity_id":"https://remote.test/activities/create","activity_type":"Create","type":"mention","content":"Old remote answer","ask_actor":actor,"ask_question":"Original remote question","ask_answer":"Old remote answer"});
 let mut update=initial.clone();update["activity_type"]=serde_json::json!("Update");update["activity_id"]=serde_json::json!("https://remote.test/activities/update");update["ask_question"]=serde_json::json!("Edited remote question");update["ask_answer"]=serde_json::json!("Edited remote answer");update["content"]=serde_json::json!("Edited remote answer");
 pair(db,1,&serde_json::json!({"status":remote.clone(),"_review_remote_ask_writer":[initial,update]}),true,&["Edited remote question","Edited remote answer"]).await?;
'''
disabled=' db.batch_execute("UPDATE ap_users SET disabled_at=\'disabled\' WHERE id=1;")'
assert disabled in s
s=s.replace(disabled,remote_ask+disabled)

provenance=r'''
 db.batch_execute("INSERT INTO bsky_sessions(owner_user_id,did,handle) VALUES(1,'did:plc:review-owner','review.test');").await?;
 let bookmark_uri="at://did:plc:author/app.bsky.feed.post/review".to_string();
 let state=vec![(bookmark_uri.clone(),"did:plc:review-owner".to_string(),false)];
 let bound=SearchSession::from_php_session(1,"canonical-current-session".into(),true)?.with_native_bookmarks(db,state.clone()).await?;
 use crate::library::facts::PresentationSession;
 let raw_bookmark=serde_json::json!({"bsky_post_view":{"uri":bookmark_uri,"viewer":{"bookmarked":true}},"_vaak_bookmark_owner":1});
 assert_eq!(bound.bsky_bookmark_state(&raw_bookmark),Some(false),"private exact URI wins raw viewer marker");
 assert_eq!(bound.bsky_bookmark_state(&serde_json::json!({"bsky_post_view":{"uri":"at://did:plc:author/app.bsky.feed.post/other","viewer":{"bookmarked":true}}})),None,"unrelated URI has no capability");
 assert_eq!(SearchSession::from_php_session(1,"csrf".into(),true)?.bsky_bookmark_state(&raw_bookmark),None,"raw status cannot create private provenance");
 assert!(SearchSession::from_php_session(2,"csrf".into(),true)?.with_native_bookmarks(db,state.clone()).await.is_err(),"other owner cannot reuse DID");
 assert!(SearchSession::from_php_session(1,"csrf".into(),true)?.with_native_bookmarks(db,vec![(bookmark_uri.clone(),"did:plc:wrong".into(),true)]).await.is_err());
 assert!(SearchSession::from_php_session(1,"csrf".into(),true)?.with_native_bookmarks(db,vec![state[0].clone(),state[0].clone()]).await.is_err());
 assert!(SearchSession::from_php_session(1,"csrf".into(),true)?.with_native_bookmarks(db,vec![("at://did:plc:author/app.bsky.feed.post/key?x=1".into(),"did:plc:review-owner".into(),true)]).await.is_err());
 assert!(SearchSession::from_php_session(1,"csrf".into(),true)?.with_native_bookmarks(db,vec![state[0].clone();122]).await.is_err());
 println!("INDEPENDENT SEARCH private constructor8 PASS: exact URI/raw ignored/owner/DID/duplicate/grammar/window");
'''
s=s.replace(disabled,provenance+disabled)

print(s)
