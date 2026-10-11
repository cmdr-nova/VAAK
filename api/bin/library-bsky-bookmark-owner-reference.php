<?php
declare(strict_types=1);
/** Actual owner-session SQL, access binding, cache lookup and both painters.
 * Only encryption, migrations, XRPC and background-job transport are fixtures.
 * Generated session material stays private inside this process and is not output.
 */
function ap_auth_secret_decrypt(string $value): string { return $value; }
function ap_auth_secret_encrypt(string $value): string { return $value; }
function ap_bsky_posts_migrate(): void {}
function ap_bsky_bookmark_cache_migrate(): bool { return true; }
function ap_bsky_background_sync_enqueue(int $owner, string $kind, bool $force): void {}
function ap_bsky_xrpc(string $base,string $nsid,string $method='GET',?array $query=null,?array $body=null,?string $bearer=null,int $timeout=6): array { return ['ok'=>true,'status'=>200,'json'=>[]]; }
ob_start();require __DIR__.'/migration-card-reference-capture.php';$reference=json_decode((string)ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
eval('function ap_bsky_tab_enabled(): bool { return true; }');
reference_load(dirname(__DIR__).'/ap-bsky.php',['ap_bsky_session_row','ap_bsky_jwt_expired','ap_bsky_access_token','ap_bsky_post_item_by_uri','ap_bsky_bookmark_cache_read','ap_bsky_bookmark_cache_clear','ap_bsky_create_bookmark','ap_bsky_delete_bookmark']);
if (!defined('AP_BSKY_DEFAULT_PDS')) define('AP_BSKY_DEFAULT_PDS','https://fixture.invalid');
ap_db()->exec('CREATE TABLE bsky_sessions(owner_user_id INTEGER PRIMARY KEY,did TEXT,handle TEXT,pds_host TEXT,access_jwt_enc TEXT,refresh_jwt_enc TEXT)');
ap_db()->exec('CREATE TABLE bsky_posts(bsky_uri TEXT PRIMARY KEY,raw_json TEXT,reason_json TEXT,author_did TEXT,author_handle TEXT,author_display TEXT,author_avatar TEXT)');
ap_db()->exec('CREATE TABLE IF NOT EXISTS bsky_bookmark_cache(owner_user_id INTEGER,bookmark_uri TEXT,post_json TEXT,bookmarked_at TEXT)');
$cacheColumns=array_column(ap_db()->query('PRAGMA table_info(bsky_bookmark_cache)')->fetchAll(),'name');
if(!in_array('bookmarked_at',$cacheColumns,true))ap_db()->exec("ALTER TABLE bsky_bookmark_cache ADD COLUMN bookmarked_at TEXT DEFAULT '2026-10-10T00:00:00Z'");
ap_db()->exec('CREATE TABLE bsky_bookmark_sync_state(owner_user_id INTEGER PRIMARY KEY,head_checked_at TEXT,full_synced_at TEXT,updated_at TEXT)');
$credentials=[];
foreach([1,2] as $owner){
    $credentials[$owner]=rtrim(strtr(base64_encode(random_bytes(12)),'+/','-_'),'=').'.'.rtrim(strtr(base64_encode(json_encode(['exp'=>time()+3600])),'+/','-_'),'=').'.'.rtrim(strtr(base64_encode(random_bytes(12)),'+/','-_'),'=');
    ap_db()->prepare('INSERT INTO bsky_sessions VALUES(?,?,?,?,?,?)')->execute([$owner,'did:plc:owner-'.$owner,'owner'.$owner.'.test','https://fixture.invalid',$credentials[$owner],bin2hex(random_bytes(16))]);
}
$post=$reference['captures']['bsky_normalized']['status']['post'];$uri=$post['uri'];
$post['viewer']=['bookmarked'=>true,'bookmark'=>true,'metadata'=>['policy'=>'retained']];
ap_db()->prepare('INSERT INTO bsky_posts VALUES(?,?,NULL,?,?,?,?)')->execute([$uri,json_encode($post,JSON_THROW_ON_ERROR),$post['author']['did'],$post['author']['handle'],$post['author']['displayName'],$post['author']['avatar']]);
function bookmark_paint(array $item,int $owner,bool $normalized=false): bool {
    $GLOBALS['vaak_owner_id']=$owner;$GLOBALS['vaak_actor_key']=$owner===1?'fixture':'other';$GLOBALS['vaak_user']=['id'=>$owner,'username'=>$GLOBALS['vaak_actor_key']];unset($GLOBALS['ap_masto_flag_memo']);
    ob_start();if($normalized)admin_render_masto_status_card(ap_normalize_from_bsky_post($item),[],'bookmarks');else admin_render_bsky_feed_item($item,'following','bookmarks');$html=(string)ob_get_clean();
    return str_contains($html,'title="Bookmark folders"');
}
function bookmark_assert(bool $condition,string $name): void { if(!$condition)throw new RuntimeException($name);$GLOBALS['bookmark_checks'][]=$name; }
$GLOBALS['bookmark_checks']=[];
$GLOBALS['vaak_owner_id']=2;
$item=ap_bsky_post_item_by_uri($uri);
bookmark_assert(is_array($item),'global lookup available');
bookmark_assert(!isset($item['post']['viewer']['bookmark'],$item['post']['viewer']['bookmarked']),'unproven booleans removed');
bookmark_assert(($item['post']['viewer']['metadata']??null)===$post['viewer']['metadata'],'source policy metadata retained');
foreach([false,true] as $normalized)bookmark_assert(!bookmark_paint($item,2,$normalized),'foreign global flag denied '.($normalized?'normalized':'legacy'));
// A JSON owner marker is not a server provenance source.
$forged=$post;$forged['_vaak_owner_user_id']=2;$forged['viewer_owner_user_id']=2;
bookmark_assert(!bookmark_paint(['post'=>$forged],2),'browser/source owner markers ignored');
// Current-owner local rows still restore an unproven shared snapshot.
$keys=admin_bsky_bookmark_keys($uri,2);
ap_db()->prepare('INSERT INTO masto_bookmarks VALUES(?,?,?)')->execute([2,$keys['status_id'],$keys['object_id']]);
foreach([false,true] as $normalized)bookmark_assert(bookmark_paint($item,2,$normalized),'owner local save restored '.($normalized?'normalized':'legacy'));
ap_db()->exec('DELETE FROM masto_bookmarks');
$token=ap_bsky_access_token(2);
bookmark_assert(!empty($token['ok']),'actual access helper binds owner');
$unknownInitial=$post;$unknownInitial['viewer']=['like'=>'at://did:plc:owner-2/app.bsky.feed.like/external'];
ap_db()->prepare('INSERT INTO masto_bookmarks VALUES(?,?,?)')->execute([2,$keys['status_id'],$keys['object_id']]);
ap_bsky_authenticated_viewer_response(['posts'=>[$unknownInitial]],$token['access'],'app.bsky.feed.getPosts');
bookmark_assert(ap_bsky_bookmark_owner_state($uri,2)===null,'absent optional flags do not invent false');
foreach([false,true] as $normalized)bookmark_assert(bookmark_paint(['post'=>$unknownInitial],2,$normalized),'old AppView preserves local save '.($normalized?'normalized':'legacy'));
ap_db()->exec('DELETE FROM masto_bookmarks');

ap_bsky_authenticated_viewer_response(['posts'=>[$post]],$token['access'],'app.bsky.feed.getPosts');
foreach([false,true] as $normalized)bookmark_assert(bookmark_paint(['post'=>$post],2,$normalized),'external direct current-owner save preserved '.($normalized?'normalized':'legacy'));
bookmark_assert(!bookmark_paint(['post'=>$post],1),'direct response not transferable to another owner');
// User record/value JSON cannot supply provenance for a different post.
$otherUri='at://did:plc:remote/app.bsky.feed.post/forged';
$evil=$post;$evil['record']['posts']=[['uri'=>$otherUri,'viewer'=>['bookmarked'=>true]]];
ap_bsky_authenticated_viewer_response(['posts'=>[$evil]],$token['access'],'app.bsky.feed.getPosts');
bookmark_assert(ap_bsky_bookmark_owner_state($otherUri,2)===null,'record JSON cannot forge response provenance');
ap_bsky_authenticated_viewer_response(['posts'=>[['uri'=>$otherUri,'viewer'=>['bookmarked'=>true]]]],null,'app.bsky.feed.getPosts');
bookmark_assert(ap_bsky_bookmark_owner_state($otherUri,2)===null,'unauthenticated response cannot establish provenance');
// Omitted optional flags are unknown, preserving a proven save; false is distinct.
$unknown=$post;$unknown['viewer']=['like'=>'at://did:plc:owner-2/app.bsky.feed.like/known'];
ap_bsky_authenticated_viewer_response(['posts'=>[$unknown]],$token['access'],'app.bsky.feed.getPosts');
bookmark_assert(ap_bsky_bookmark_owner_state($uri,2)===true,'absent optional bookmark fields preserve known state');
$false=$post;$false['viewer']=['bookmarked'=>false];
ap_bsky_authenticated_viewer_response(['posts'=>[$false]],$token['access'],'app.bsky.feed.getPosts');
ap_db()->prepare('INSERT INTO bsky_bookmark_cache(owner_user_id,bookmark_uri,post_json) VALUES(?,?,?)')->execute([2,$uri,json_encode(['post'=>$post],JSON_THROW_ON_ERROR)]);
$warm=ap_bsky_bookmark_cache_read(2,10);
bookmark_assert(count($warm)===1,'actual joined owner cache read');
bookmark_assert(ap_bsky_bookmark_owner_state($uri,2)===false,'warm cache cannot resurrect authenticated false');
foreach([false,true] as $normalized)bookmark_assert(!bookmark_paint($warm[0],2,$normalized),'explicit false rendered off '.($normalized?'normalized':'legacy'));
// External native undo does not remove an independent current-owner AP save.
ap_db()->prepare('INSERT INTO masto_bookmarks VALUES(?,?,?)')->execute([2,$keys['status_id'],$keys['object_id']]);
foreach([false,true] as $normalized)bookmark_assert(bookmark_paint($warm[0],2,$normalized),'native false preserves independent AP save '.($normalized?'normalized':'legacy'));
ap_db()->exec('DELETE FROM masto_bookmarks');
// Session switch invalidates earlier access/context even under the same owner ID.
ap_db()->prepare('UPDATE bsky_sessions SET did=? WHERE owner_user_id=2')->execute(['did:plc:replacement']);
bookmark_assert(ap_bsky_bookmark_owner_state($uri,2)===null,'session account switch invalidates provenance');
ap_bsky_authenticated_viewer_response(['posts'=>[$post]],$token['access'],'app.bsky.feed.getPosts');
bookmark_assert(ap_bsky_bookmark_owner_state($uri,2)===null,'previous account response cannot rebind provenance');
ap_db()->prepare('UPDATE bsky_sessions SET did=? WHERE owner_user_id=2')->execute(['did:plc:owner-2']);
unset($GLOBALS['vaak_bsky_bookmark_owner_state']);
$warm=ap_bsky_bookmark_cache_read(2,10);
bookmark_assert(bookmark_paint($warm[0],2),'joined owner cache preserves save without direct response');
bookmark_assert(!bookmark_paint($warm[0],1),'joined cache cannot transfer save to another owner');
// Actual native mutation helpers with offline XRPC transport and real cache SQL.
bookmark_assert(!empty(ap_bsky_delete_bookmark(2,$uri)['ok']),'actual unbookmark helper succeeded offline');
bookmark_assert((int)ap_db()->query('SELECT count(*) FROM bsky_bookmark_cache WHERE owner_user_id=2')->fetchColumn()===0,'actual warm unbookmark removes owner cache row');
bookmark_assert(ap_bsky_bookmark_owner_state($uri,2)===false,'confirmed undo invalidates request warm state');
bookmark_assert(!bookmark_paint($warm[0],2),'old warm item stays off after confirmed undo');
bookmark_assert(!empty(ap_bsky_create_bookmark(2,['uri'=>$uri,'cid'=>$post['cid']])['ok']),'actual bookmark helper succeeded offline');
bookmark_assert(bookmark_paint(['post'=>$post],2),'confirmed save restores current owner state');
unset($GLOBALS['vaak_bsky_bookmark_owner_state']);
$mismatched=$post;$mismatched['uri']=$otherUri;
ap_db()->prepare('INSERT INTO bsky_bookmark_cache(owner_user_id,bookmark_uri,post_json) VALUES(?,?,?)')->execute([2,$uri,json_encode(['post'=>$mismatched],JSON_THROW_ON_ERROR)]);
$wrong=ap_bsky_bookmark_cache_read(2,10);
bookmark_assert(count($wrong)===1 && ap_bsky_bookmark_owner_state($otherUri,2)===null,'owner cache URI cannot establish another post save');
$GLOBALS['vaak_owner_id']=2;$GLOBALS['vaak_actor_key']='other';$GLOBALS['vaak_user']=['id'=>2,'username'=>'other'];
bookmark_assert(block_quick_actions('https://bsky.app/profile/did:plc:owner-2','bsky.app','bookmarks',2)==='','exact Bluesky own profile menu suppressed');
foreach([['bsky.app.evil.test','bsky.app.evil.test'],['notbsky.app','notbsky.app'],['bsky.app.evil.test','bsky.app']] as [$actorHost,$hostHint]) {
    $menu=block_quick_actions('https://'.$actorHost.'/profile/did:plc:owner-2',$hostHint,'bookmarks',2);
    bookmark_assert(str_contains($menu,'Mute') && str_contains($menu,'Block'),'deceptive Bluesky host retains moderation '.$actorHost.' '.$hostHint);
}
echo json_encode(['checks'=>count($GLOBALS['bookmark_checks']),'cases'=>$GLOBALS['bookmark_checks'],'promotion_allowed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
