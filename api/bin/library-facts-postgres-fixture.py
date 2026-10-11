#!/usr/bin/env python3
"""Emit actual-module Rust checks for a disposable PostgreSQL DB only.
No production environment or credentials are read. Pass LIBRARY_FIXTURE_DB
when running the emitted binary. Both historical INTEGER/BIGINT schemas are
covered. PHP reference local-count SQL is extracted separately by --php-counts.
"""
import json, pathlib, re, sys, subprocess
ROOT=pathlib.Path(__file__).resolve().parents[2]
SRC=ROOT/'rust/vaak-worker/src'
j=json.dumps
if '--php-counts' in sys.argv:
    code=r'''
require $argv[1].'/api/bin/migration-reference-functions.php';
$GLOBALS['db']=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function ap_db(): PDO{return $GLOBALS['db'];}
reference_load($argv[1].'/api/ap-db.php',['ap_local_note_engagement_counts']);
$note='https://mkultra.monster/users/viewer/notes/own';
ap_db()->exec("CREATE TABLE events(type TEXT,actor_id TEXT,object_id TEXT,quote_object TEXT,summary TEXT); CREATE TABLE quote_authorizations(deleted_at TEXT,quoted_note_id TEXT); CREATE TABLE masto_favourites(status_id TEXT,object_id TEXT); CREATE TABLE masto_reblogs(status_id TEXT,object_id TEXT); CREATE TABLE masto_bookmarks(status_id TEXT,object_id TEXT); CREATE TABLE bsky_crossposts(note_id TEXT,bsky_uri TEXT,created_at TEXT); CREATE TABLE bsky_posts(bsky_uri TEXT,like_count INTEGER,repost_count INTEGER,quote_count INTEGER);");
$q=ap_db()->prepare('INSERT INTO events VALUES(?,?,?,?,?)');
foreach([['Like','https://a.test/u/a',$note,null,null],['Like','https://a.test/u/a/',$note.'/',null,null],['Announce','https://a.test/u/a',$note,null,null],['Create',null,null,null,'QT '.$note],['Create',null,'https://other.test/n',null,'QT '.$note],['Create',null,$note,null,'QT '.$note]] as $row)$q->execute($row);
foreach(['masto_favourites','masto_reblogs','masto_bookmarks'] as $table){$q=ap_db()->prepare('INSERT INTO '.$table.' VALUES(?,?)');$q->execute(['9',$note]);}
ap_db()->prepare('INSERT INTO masto_bookmarks VALUES(?,?)')->execute(['other',$note.'/']);
ap_db()->prepare('INSERT INTO bsky_crossposts VALUES(?,?,?)')->execute([$note,'at://did:plc:v/app.bsky.feed.post/1','2026']);
ap_db()->exec("INSERT INTO bsky_posts VALUES('at://did:plc:v/app.bsky.feed.post/1',2,1,1)");
$expected=['favourites'=>4,'reblogs'=>3,'quotes'=>3,'bookmarks'=>2];$counts=ap_local_note_engagement_counts($note,'9');if($counts!==$expected)throw new RuntimeException(json_encode($counts));
ap_db()->prepare('INSERT INTO quote_authorizations VALUES(NULL,?)')->execute([$note.'/']);
$counts=ap_local_note_engagement_counts($note,'9');if($counts['quotes']!==2)throw new RuntimeException('Authorization branch mismatch');
echo "library-facts-php-counts: PASS (actual PHP SQL, NULL summary target, duplicate actor/slash, own saves, mirror and authorization branches)\n";
'''
    subprocess.run([str(ROOT/'api/bin/php-policy-test'),'-r',code,str(ROOT)],check=True)
    raise SystemExit()
if '--emit-rust-harness' not in sys.argv:
    raise SystemExit('Use --emit-rust-harness; disposable PostgreSQL only.')
for name in re.findall(r'^mod (\w+);',(SRC/'main.rs').read_text(),re.M):
    if name=='library': continue
    path=SRC/(name+'.rs')
    if not path.exists(): path=SRC/name/'mod.rs'
    print('#[path='+j(str(path))+'] mod '+name+';')
source=(SRC/'library.rs').read_text()
source=re.sub(r'^//!','//',source,flags=re.M)
source=re.sub(r'#\[path = "([^"]+)"\]',lambda m:'#[path = '+j(str(SRC/m[1]))+']',source)
print('mod library { '+source)
print(r'''
pub(crate) async fn fixture(db:&tokio_postgres::Client)->anyhow::Result<()> {
 let session=context::TrustedSession::from_php_session(1,"real-server-session".into(),"bookmarks".into(),false)?;
 let actor="https://mkultra.monster/users/viewer";
 let own=serde_json::json!({"id":"9", "visibility":"private", "uri":format!("{actor}/notes/own"),"account":{"uri":actor,"acct":"viewer","avatar":"https://local.test/avatar"},"content":"stale text", "favourited":true});
 let f=facts::resolve(db,1,actor,&own,&session,true).await?;
 let o=f.own.as_ref().unwrap(); assert_eq!(o.local_id,9);assert_eq!(o.plain,"Current private body");assert_eq!(o.spoiler,"Current warning");assert_eq!(o.visibility,"private");assert!(o.pinned);assert!(o.sensitive);
 assert_eq!(o.counts.favourites,4);assert_eq!(o.counts.reblogs,3);assert_eq!(o.counts.bookmarks,2);assert_eq!(o.counts.quotes,3);assert!(!f.moderation.is_admin);assert!(f.moderation.is_self);assert_eq!(f.csrf,"real-server-session");
 db.batch_execute("INSERT INTO quote_authorizations VALUES(NULL,'https://mkultra.monster/users/viewer/notes/own/');").await?;let f=facts::resolve(db,1,actor,&own,&session,true).await?;assert_eq!(f.own.unwrap().counts.quotes,2);db.batch_execute("DELETE FROM quote_authorizations;").await?;
 db.batch_execute("UPDATE ap_users SET is_admin=1 WHERE id=1; DELETE FROM masto_pins;").await?;
 let f=facts::resolve(db,1,actor,&own,&session,false).await?;assert!(f.moderation.is_admin);assert!(!f.own.unwrap().pinned);assert!(!f.auto_unblur_sensitive);
 let remote=serde_json::json!({"id":"remote","uri":"https://remote.test/post","account":{"uri":"https://sub.remote.test/users/a","acct":"a@sub.remote.test"},"mentions":[{"acct":"friend@else.test"},{"acct":"viewer@mkultra.monster"}]});
 let f=facts::resolve(db,1,actor,&remote,&session,false).await?;assert_eq!(f.moderation.personal_block_id,Some(7));assert!(f.moderation.muted);assert!(f.moderation.deprioritized);assert_eq!(f.reply_mentions,vec!["a@sub.remote.test","friend@else.test"]);assert_eq!(f.poll_row.unwrap()["local_id"],4);assert_eq!(f.ask_row.unwrap()["question"],"Current question");
 db.batch_execute("DELETE FROM ap_user_blocks; DELETE FROM ap_mutes; DELETE FROM ap_deprioritized_actors; DELETE FROM masto_polls; DELETE FROM ap_asks;").await?;
 let f=facts::resolve(db,1,actor,&remote,&session,false).await?;assert!(f.moderation.personal_block_id.is_none());assert!(!f.moderation.muted);assert!(!f.moderation.deprioritized);assert!(f.poll_row.is_none());assert!(f.ask_row.is_none());
 db.batch_execute("INSERT INTO ap_asks VALUES(6,'https://remote.test/post','Fresh local question','https://mkultra.monster/users/viewer');").await?;
 let f=facts::resolve(db,1,actor,&remote,&session,false).await?;let ask=f.ask_row.unwrap();assert_eq!(ask["asker_display"],"Current viewer");assert_eq!(ask["asker_handle"],"@viewer@mkultra.monster");assert_eq!(ask["asker_avatar"],"https://local.test/current.webp");
 db.batch_execute("UPDATE ap_asks SET asker_actor=NULL; UPDATE actor_profile SET name='Changed current name' WHERE actor_key='viewer';").await?;
 let mut stale_ask=remote.clone();stale_ask["content"]=serde_json::json!("safe answer");stale_ask["vaak_ask"]=serde_json::json!({"question":"Old question","asker_actor":"https://mkultra.monster/users/viewer","asker_display":"Leaked cached name"});
 let cards=context::admit_for_render(db,&session,vec![stale_ask.clone()]).await?;assert_eq!(cards.len(),1);let ask=cards[0].facts().ask_row.as_ref().unwrap();assert_eq!(ask["asker_display"],"Anonymous");assert_eq!(ask["asker_handle"],"");assert_eq!(ask["asker_actor"],"");assert_eq!(cards[0].status()["vaak_ask"]["asker_display"],"Anonymous");
 db.batch_execute("UPDATE ap_asks SET question='Fresh blocked phrase';INSERT INTO ap_muted_words VALUES(1,'blocked phrase');").await?;assert!(context::admit_for_render(db,&session,vec![stale_ask]).await?.is_empty());db.batch_execute("DELETE FROM ap_asks; DELETE FROM ap_muted_words;").await?;
 let cards=context::admit_for_render(db,&session,vec![own.clone()]).await?;assert_eq!(cards.len(),1);assert_eq!(cards[0].status()["content"],"Current private body");assert_eq!(cards[0].status()["spoiler_text"],"Current warning");assert!(!cards[0].facts().favourited);assert!(cards[0].facts().moderation.is_admin);assert!(!cards[0].facts().own.as_ref().unwrap().pinned);
 db.batch_execute("INSERT INTO ap_muted_words VALUES(1,'private body');").await?;assert!(context::admit_for_render(db,&session,vec![own.clone()]).await?.is_empty());db.batch_execute("DELETE FROM ap_muted_words;").await?;
 let rss=serde_json::json!({"id":"rss:11","source":"rss","content":"stale cached body","account":{"acct":"RSS"}});
 let cards=context::admit_for_render(db,&session,vec![rss.clone()]).await?;assert_eq!(cards.len(),1);assert!(cards[0].status()["content"].as_str().unwrap().contains("Fresh title"));assert!(cards[0].facts().rss_row.is_some());
 let other=context::TrustedSession::from_php_session(2,"other-session".into(),"bookmarks".into(),false)?;assert!(context::admit_for_render(db,&other,vec![rss.clone()]).await?.is_empty());
 db.batch_execute("UPDATE rss_items SET summary_text='new muted secret' WHERE id=11; INSERT INTO ap_muted_words VALUES(1,'muted secret');").await?;assert!(context::admit_for_render(db,&session,vec![rss]).await?.is_empty());db.batch_execute("DELETE FROM ap_muted_words;").await?;
 db.batch_execute("UPDATE ap_users SET disabled_at='disabled' WHERE id=1;").await?;assert!(facts::resolve(db,1,actor,&own,&session,false).await.is_err());
 db.batch_execute("UPDATE ap_users SET disabled_at=NULL WHERE id=1; DROP TABLE quote_authorizations;").await?;assert!(facts::resolve(db,1,actor,&own,&session,false).await.is_err());
 Ok(())
}
}
#[tokio::main] async fn main()->anyhow::Result<()> {
 let db=db::connect(&std::env::var("LIBRARY_FIXTURE_DB")?).await?;
''')
for width in ['INTEGER','BIGINT']:
    sql=f'''
CREATE SCHEMA library_facts_{width.lower()};SET search_path TO library_facts_{width.lower()};
CREATE TABLE ap_users(id {width},actor_id TEXT,actor_key TEXT,is_admin {width},disabled_at TEXT);
INSERT INTO ap_users VALUES(1,'https://mkultra.monster/users/viewer','viewer',0,NULL);
INSERT INTO ap_users VALUES(2,'https://mkultra.monster/users/other','other',0,NULL);
CREATE TABLE actor_profile(actor_key TEXT,name TEXT,icon_url TEXT,automated {width},discoverable {width},indexable {width},auto_unblur_sensitive {width});INSERT INTO actor_profile VALUES('viewer','Current viewer','https://local.test/current.webp',0,1,1,1),('other','Other viewer','https://local.test/other.webp',0,1,1,0);
CREATE TABLE bsky_sessions(owner_user_id {width},did TEXT,handle TEXT);
CREATE TABLE bsky_hide_dids(owner_user_id {width},did TEXT);
CREATE TABLE ap_muted_words(owner_user_id {width},phrase TEXT);
CREATE TABLE outbox_notes(id TEXT,visibility TEXT,to_json TEXT,cc_json TEXT);INSERT INTO outbox_notes VALUES('https://mkultra.monster/users/viewer/notes/own','private','[]','[]');
CREATE TABLE mentions(owner_user_id {width},deleted_at TEXT,activity_type TEXT,object_id TEXT,id {width},ask_actor TEXT,ask_question TEXT,ask_answer TEXT);
CREATE TABLE followers(owner_actor_id TEXT,actor_id TEXT);CREATE TABLE following(owner_actor_id TEXT,actor_id TEXT);
CREATE TABLE rss_feeds(id {width},owner_user_id {width},title TEXT,favicon_url TEXT,site_url TEXT,feed_url TEXT);INSERT INTO rss_feeds VALUES(1,1,'Feed','https://feed.test/avatar','https://feed.test','https://feed.test/rss');
CREATE TABLE rss_items(id {width},feed_id {width},title TEXT,url TEXT,summary_text TEXT,image_url TEXT,published_at TEXT,mirror_note_id TEXT);INSERT INTO rss_items VALUES(11,1,'Fresh title','https://article.test/post','Fresh body','','2026-10-10T01:00:00Z',NULL);
CREATE TABLE ap_user_blocks(id {width},owner_user_id {width},scope TEXT,value TEXT,created_at TEXT);
INSERT INTO ap_user_blocks VALUES(7,1,'domain','remote.test','2026-10-10');
CREATE TABLE ap_mutes(owner_user_id {width},actor_id TEXT);INSERT INTO ap_mutes VALUES(1,'https://sub.remote.test/@a');
CREATE TABLE ap_deprioritized_actors(owner_user_id {width},actor_id TEXT);INSERT INTO ap_deprioritized_actors VALUES(1,'https://sub.remote.test/users/a');
CREATE TABLE ap_blocks(id {width},scope TEXT,value TEXT,kind TEXT);
CREATE TABLE ap_wafrn_friend_servers(host TEXT,enabled {width});
CREATE TABLE remote_custom_emojis(host TEXT,shortcode TEXT,url TEXT);
CREATE TABLE remote_media_cache(actor_id TEXT,kind TEXT,public_url TEXT);INSERT INTO remote_media_cache VALUES('https://sub.remote.test/users/a','avatar','https://cache.test/fresh.webp');
CREATE TABLE masto_statuses(local_id {width},note_id TEXT,content_text TEXT,spoiler_text TEXT,sensitive {width},visibility TEXT);
INSERT INTO masto_statuses VALUES(9,'https://mkultra.monster/users/viewer/notes/own','Current private body','Current warning',1,'private'),(10,'https://remote.test/post','Current authoritative answer','',0,'public');
CREATE TABLE masto_pins(owner_user_id {width},status_local_id {width});INSERT INTO masto_pins VALUES(1,9);
CREATE TABLE masto_polls(local_id {width},note_id TEXT);INSERT INTO masto_polls VALUES(4,'https://remote.test/post/');
CREATE TABLE ap_asks(id {width},answer_note_id TEXT,question TEXT,asker_actor TEXT);INSERT INTO ap_asks VALUES(5,'https://remote.test/post','Current question',NULL);
CREATE TABLE events(type TEXT,actor_id TEXT,object_id TEXT,quote_object TEXT,summary TEXT,id {width},action_taken TEXT,visibility TEXT);
INSERT INTO events(type,actor_id,object_id,quote_object,summary) VALUES('Like','https://a.test/u/a','https://mkultra.monster/users/viewer/notes/own',NULL,NULL),('Like','https://a.test/u/a/','https://mkultra.monster/users/viewer/notes/own/',NULL,NULL),('Announce','https://a.test/u/a','https://mkultra.monster/users/viewer/notes/own',NULL,NULL),('Create',NULL,NULL,NULL,'QT https://mkultra.monster/users/viewer/notes/own'),('Create',NULL,'https://other.test/n',NULL,'QT https://mkultra.monster/users/viewer/notes/own'),('Create',NULL,'https://mkultra.monster/users/viewer/notes/own',NULL,'QT https://mkultra.monster/users/viewer/notes/own');
CREATE TABLE quote_authorizations(deleted_at TEXT,quoted_note_id TEXT);
CREATE TABLE masto_favourites(owner_user_id {width},status_id TEXT,object_id TEXT); INSERT INTO masto_favourites VALUES(2,'9','https://mkultra.monster/users/viewer/notes/own');
CREATE TABLE masto_reblogs(owner_user_id {width},status_id TEXT,object_id TEXT); INSERT INTO masto_reblogs VALUES(1,'9','https://mkultra.monster/users/viewer/notes/own');
CREATE TABLE masto_bookmarks(owner_user_id {width},status_id TEXT,object_id TEXT); INSERT INTO masto_bookmarks VALUES(1,'9','https://mkultra.monster/users/viewer/notes/own'),(2,'other','https://mkultra.monster/users/viewer/notes/own/');
CREATE TABLE bsky_crossposts(note_id TEXT,bsky_uri TEXT,created_at TEXT);INSERT INTO bsky_crossposts VALUES('https://mkultra.monster/users/viewer/notes/own','at://did:plc:v/app.bsky.feed.post/1','2026');
CREATE TABLE bsky_posts(bsky_uri TEXT,like_count {width},repost_count {width},quote_count {width}); INSERT INTO bsky_posts VALUES('at://did:plc:v/app.bsky.feed.post/1',2,1,1);
'''
    print('db.batch_execute('+j(sql)+').await?; library::fixture(&db).await?;')
    print('db.batch_execute('+j('SET search_path TO public; DROP SCHEMA library_facts_'+width.lower()+' CASCADE;')+').await?;')
print('println!("library-facts-postgres: PASS (INTEGER/BIGINT combined typed source/admission/current facts, current own/RSS text and late word policy, owners, roles, private text/CW/pins/counts, NULL summary/authorization quotes, aliases/domain blocks, settings/polls/Ask and explicit errors)");Ok(())}')
