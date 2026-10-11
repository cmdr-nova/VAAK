<?php
declare(strict_types=1);
/** Current PHP feature/session/provenance and full shared profile painter.
 * Disposable SQLite; original bootstrap transport boundaries remain declared.
 * This captures protocol presentation, not local provider membership/HTTP.
 */
ob_start(); require __DIR__.'/migration-card-reference-capture.php';
$base=json_decode((string)ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
reference_load(dirname(__DIR__).'/ap-db.php',['ap_feature_enabled']);
reference_load(dirname(__DIR__).'/ap-bsky.php',['ap_bsky_tab_enabled','ap_bsky_session_row']);
require dirname(__DIR__).'/ap-profile-native.php';
ap_db()->exec('CREATE TABLE bsky_sessions(owner_user_id INTEGER PRIMARY KEY,did TEXT,handle TEXT)');
ap_db()->exec('CREATE TABLE bsky_bookmark_cache(owner_user_id INTEGER,bookmark_uri TEXT,post_json TEXT)');
ap_db()->exec('CREATE TABLE bsky_posts(bsky_uri TEXT PRIMARY KEY,raw_json TEXT,reason_json TEXT,author_did TEXT,author_handle TEXT,author_display TEXT,author_avatar TEXT)');
$post=$base['captures']['bsky_normalized']['status']['post'];
$post['author']['did']='did:plc:remote-profile';
$post['viewer']=['bookmarked'=>true,'like'=>'at://did:plc:foreign/app.bsky.feed.like/record'];
$uri=$post['uri']; $captures=[];
ap_db()->prepare('INSERT INTO bsky_posts VALUES(?,?,NULL,?,?,?,?)')->execute([$uri,json_encode($post,JSON_THROW_ON_ERROR),$post['author']['did'],$post['author']['handle'],$post['author']['displayName'],$post['author']['avatar']]);
foreach ([false,true] as $enabled) foreach ([false,true] as $connected) foreach (['unknown','true','false','foreign'] as $state) {
    putenv('VAAK_FEATURE_BLUESKY_TAB='.($enabled?'1':'0'));
    ap_db()->exec('DELETE FROM bsky_sessions');
    if ($connected) ap_db()->prepare('INSERT INTO bsky_sessions VALUES(?,?,?)')->execute([2,'did:plc:profile-owner','profile-owner.test']);
    unset($GLOBALS['vaak_bsky_bookmark_owner_state'],$GLOBALS['ap_masto_flag_memo']);
    if ($state!=='unknown') $GLOBALS['vaak_bsky_bookmark_owner_state'][2][$uri]=['did'=>$state==='foreign'?'did:plc:foreign':'did:plc:profile-owner','bookmarked'=>$state!=='false','priority'=>1];
    $GLOBALS['vaak_owner_id']=2;$GLOBALS['vaak_actor_key']='other';$GLOBALS['vaak_user']=['id'=>2,'username'=>'other'];
    $source=ap_normalize_from_bsky_post(['post'=>$post]);
    ob_start();admin_render_masto_status_card($source,['focused'=>false,'showOpen'=>true,'profile_actor'=>'https://mkultra.monster/users/fixture'],'remote_profile');$html=(string)ob_get_clean();
    $captures[]=['enabled'=>$enabled,'connected'=>$connected,'state'=>$state,'source'=>$source,'provenance'=>ap_profile_complete_bsky_provenance(2),'html'=>$html];
}
$out=['scope'=>'actual PHP server feature/current owner session/request bookmark provenance and shared remote_profile protocol painter; no provider, native, HTTP, Settings save or mirror membership acceptance','captures'=>$captures];
$path=$argv[1]??'/tmp/vaak-profile-bsky-owner-reference.json';file_put_contents($path,json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
echo json_encode(['captures'=>count($captures),'output'=>$path],JSON_THROW_ON_ERROR),"\n";
