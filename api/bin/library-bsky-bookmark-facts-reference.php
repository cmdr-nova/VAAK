<?php
declare(strict_types=1);
/** Actual canonical cache-only mapping SQL and PHP snowflakes, isolated SQLite. */
require __DIR__.'/migration-reference-functions.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
function ap_bsky_crossposts_migrate(): void {}
function ap_bsky_post_links_migrate(): void {}
$api=dirname(__DIR__);
reference_load($api.'/ap-admin.php',['admin_bsky_bookmark_keys']);
reference_load($api.'/ap-db.php',['ap_masto_status_by_note_id']);
reference_load($api.'/ap-masto-entities.php',['ap_masto_snowflake_id']);
reference_load($api.'/ap-bsky.php',['ap_bsky_local_note_id_for_at_uri','ap_bsky_crosspost_by_uri','ap_bsky_post_link_by_uri']);
reference_load($api.'/ap-bsky-urls.php',['ap_bsky_https_url_from_at_uri']);
$db->exec('CREATE TABLE bsky_crossposts(note_id TEXT,bsky_uri TEXT);CREATE TABLE bsky_post_links(bsky_uri TEXT,fediverse_id TEXT,ap_object_id TEXT);CREATE TABLE masto_statuses(local_id INTEGER,note_id TEXT,published TEXT)');
$uri='at://did:plc:author/app.bsky.feed.post/key';
$note='https://mkultra.monster/users/owner-one/notes/7';
$db->prepare('INSERT INTO bsky_crossposts VALUES(?,?)')->execute([$note,$uri]);
$db->prepare('INSERT INTO masto_statuses VALUES(7,?,?)')->execute([$note,'2026-10-10T12:00:00Z']);
$reference=['mapped'=>admin_bsky_bookmark_keys($uri,1),'unmapped'=>admin_bsky_bookmark_keys(str_replace('/key','/unmapped',$uri),2)];
echo json_encode($reference,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
