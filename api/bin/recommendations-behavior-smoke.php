#!/usr/bin/env php
<?php
declare(strict_types=1);
// Actual fallback SQL against disposable in-memory fixtures, no bootstrap.
function ap_db_now(): string { return '2026-10-10T12:00:00Z'; }
function ap_db(): PDO { return $GLOBALS['db']; }
require_once dirname(__DIR__) . '/ap-recommendations.php';
$f=json_decode((string)file_get_contents(dirname(__DIR__) . '/fixtures/recommendations/interactions.json'),true,512,JSON_THROW_ON_ERROR);
$db=new PDO('sqlite::memory:');$GLOBALS['db']=$db;
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ap_users (id INTEGER,actor_id TEXT);
 CREATE TABLE masto_favourites (owner_user_id INTEGER,object_id TEXT);
 CREATE TABLE masto_bookmarks (owner_user_id INTEGER,object_id TEXT);
 CREATE TABLE masto_reblogs (owner_user_id INTEGER,object_id TEXT);
 CREATE TABLE outbox_notes (id TEXT,in_reply_to TEXT,raw_create_json TEXT);
 CREATE TABLE quote_authorizations (requester_actor TEXT,quoted_note_id TEXT);
 CREATE TABLE ap_user_signals (owner_user_id INTEGER,signal_type TEXT,target_key TEXT,weight REAL,metadata_json TEXT);');
foreach (['users'=>'ap_users','favourites'=>'masto_favourites','bookmarks'=>'masto_bookmarks','reblogs'=>'masto_reblogs','outbox'=>'outbox_notes','authorizations'=>'quote_authorizations','signals'=>'ap_user_signals'] as $field=>$table) {
 foreach ($f[$field] as $row) $db->prepare('INSERT INTO '.$table.' VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute($row);
}
$urls=array_map(static fn($key)=>'https://remote.test/posts/'.$key,[...$f['consumed'],...$f['unseen']]);
$hits=ap_home_recommendation_interacted_objects(201,$urls);
$count=0;
foreach ($f['consumed'] as $key) { if (!isset($hits['https://remote.test/posts/'.$key])) throw new RuntimeException('not consumed: '.$key); $count++; }
foreach ($f['unseen'] as $key) { if (isset($hits['https://remote.test/posts/'.$key])) throw new RuntimeException('incorrectly consumed: '.$key); $count++; }
if (ap_home_recommendation_interacted_objects(0,$urls)!==[]) throw new RuntimeException('guest isolation');$count++;
if (ap_home_recommendation_interacted_objects(201,[])!==[]) throw new RuntimeException('empty batch');$count++;
$other=ap_home_recommendation_interacted_objects(202,$urls);
if (isset($other['https://remote.test/posts/quote']) || !isset($other['https://remote.test/posts/other-quote'])) throw new RuntimeException('owner namespace isolation');$count++;
$db->exec("ALTER TABLE ap_user_signals ADD COLUMN platform TEXT; ALTER TABLE ap_user_signals ADD COLUMN created_at TEXT;");
ap_home_recommendation_record_publication(['id'=>'https://mkultra.monster/users/alice_1/notes/new','in_reply_to'=>'https://remote.test/posts/deleted-reply'], ['object'=>['quote'=>'https://remote.test/posts/deleted-quote']]);
$history=ap_home_recommendation_interacted_objects(201,['https://remote.test/posts/deleted-reply','https://remote.test/posts/deleted-quote']);
if (count($history)!==2) throw new RuntimeException('durable reply/quote history');$count++;
if (ap_home_recommendation_interacted_objects(202,['https://remote.test/posts/deleted-reply','https://remote.test/posts/deleted-quote'])!==[]) throw new RuntimeException('publication owner isolation');$count++;
echo "recommendations-behavior: PASS ($count checks; isolated SQLite, no network)\n";
