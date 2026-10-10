#!/usr/bin/env php
<?php
declare(strict_types=1);
// Execute the real FTS fallback query against isolated docs/preferences.
function ap_db(): PDO { return $GLOBALS['db']; }
function ap_db_masto_owner_user_id(): int { return $GLOBALS['owner']; }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $s): string { return strtolower($s); } }
require_once dirname(__DIR__) . '/ap-search-fts.php';
$db=new PDO('sqlite::memory:');$GLOBALS['db']=$db;
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE ap_users (id INTEGER,actor_key TEXT,actor_id TEXT);
CREATE TABLE actor_profile (actor_key TEXT,indexable INTEGER);
CREATE TABLE masto_statuses (local_id INTEGER);
CREATE TABLE events (id INTEGER,actor_id TEXT);
CREATE TABLE mentions (id INTEGER,deleted_at TEXT);
CREATE TABLE ap_search_docs (id INTEGER PRIMARY KEY,source TEXT,source_pk INTEGER,object_id TEXT,created_at TEXT,body TEXT);
CREATE VIRTUAL TABLE ap_search_fts USING fts5(body,content='ap_search_docs',content_rowid='id');
INSERT INTO ap_users VALUES (201,'alice_1','https://mkultra.monster/users/alice_1'),(202,'alicex1','https://mkultra.monster/users/alicex1');
INSERT INTO actor_profile VALUES ('alice_1',0),('alicex1',1);
INSERT INTO masto_statuses VALUES (1),(2);
INSERT INTO events VALUES (3,'https://mkultra.monster/users/alice_1');
INSERT INTO ap_search_docs VALUES (1,'status',1,'https://mkultra.monster/users/alice_1/notes/old','2026-10-10','fixture keyword'),(2,'status',2,'https://mkultra.monster/users/alicex1/notes/other','2026-10-10','fixture keyword'),(3,'event',3,'https://remote.test/cached-copy','2026-10-10','fixture keyword');
INSERT INTO ap_search_fts(ap_search_fts) VALUES ('rebuild');");
$GLOBALS['owner']=202;
$hits=ap_search_fts_ranked_hits('keyword*','',40,'fedi',false);
if (array_column($hits,'source_pk')!==[2]) throw new RuntimeException('indexing opt-out or underscore isolation');
$GLOBALS['owner']=201;
$hits=ap_search_fts_ranked_hits('keyword*','',40,'fedi',false);
if (count($hits)!==3) throw new RuntimeException('own post search must remain available');
$db->exec("UPDATE actor_profile SET indexable=1 WHERE actor_key='alice_1'");$GLOBALS['owner']=202;
if (count(ap_search_fts_ranked_hits('keyword*','',40,'fedi',false))!==3) throw new RuntimeException('re-enable without reindex');
echo "search-indexing: PASS (3 canonical opt-out/owner/stale-index checks; isolated SQLite)\n";
