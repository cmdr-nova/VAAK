<?php
declare(strict_types=1);
/** Actual profile save/read and privacy helpers; isolated SQLite and cache/file
 * transport adapters. Does not cover the upload/email/federation controller. */
require __DIR__.'/migration-reference-functions.php';
if(!extension_loaded('mbstring')||!extension_loaded('pdo_sqlite'))throw new RuntimeException('Use php-policy-test with real mbstring and SQLite');
function ap_db(): PDO { return $GLOBALS['fixture_db']; }
function ap_redis_json_get(string $key): mixed { return $GLOBALS['cache'][$key]??null; }
function ap_redis_json_set(string $key,mixed $value,int $ttl): void { $GLOBALS['cache'][$key]=$value; }
function ap_redis_delete(string ...$keys): void { foreach($keys as $key){unset($GLOBALS['cache'][$key]);$GLOBALS['invalidated'][]=$key;} }
function ap_profile_export_public_cache(string $key): void {
    // Disk transport adapter still uses actual current reader and enforces order.
    $cacheKey='vaak:profile:v1:'.hash('sha256',$key);
    if(!in_array($cacheKey,$GLOBALS['invalidated'],true))throw new RuntimeException('Export before cache invalidation');
    $GLOBALS['exports'][$key]=ap_profile_get($key);
}
function ap_timeline_cache_invalidate_owner(int $owner): void { $GLOBALS['ranked'][]=$owner; }
function ap_timeline_home_hydrate_invalidate_owner(int $owner): void { $GLOBALS['hydrated'][]=$owner; }
reference_load(dirname(__DIR__).'/ap-db.php',['ap_profile_defaults','ap_profile_get','ap_profile_supporter_awarded','ap_profile_authorized_badges','ap_profile_save','ap_profile_save_unlocked','ap_fix_utf8','ap_profile_sanitize_https_url','ap_profile_normalize_summary_html','ap_profile_normalize_badges','ap_profile_badge_catalog','ap_profile_verify_attachments','ap_profile_actor_url','ap_db_now','ap_profile_collection_consent','ap_webmention_target_enabled']);
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$GLOBALS['fixture_db']=$db;
$flags=['manually_approves','discoverable','indexable','collection_consent','vanity_verified','auto_follow_back','anti_ai_marker','auto_unblur_sensitive','auto_delete_posts_7d','automated','hide_profile_replies','hide_profile_boosts','algorithm_enabled','downranking_enabled','asks_enabled','webmentions_enabled','trending_enabled'];
$db->exec('CREATE TABLE actor_profile(actor_key TEXT PRIMARY KEY,name TEXT,summary TEXT,attachment_json TEXT,icon_url TEXT,image_url TEXT,reply_policy TEXT,quote_policy TEXT,forum_signature TEXT,profile_badges TEXT,updated_at TEXT,'.implode(',',array_map(fn($f)=>$f.' INTEGER NOT NULL DEFAULT 0',$flags)).')');
$db->exec("CREATE TABLE ap_users(id INTEGER,actor_key TEXT);INSERT INTO ap_users VALUES(201,'alice'),(202,'bob');
INSERT INTO actor_profile(actor_key,name,summary,attachment_json,profile_badges,reply_policy,quote_policy,forum_signature,manually_approves) VALUES('alice','Alice','<p>Unicode é bio</p>','[]','[]','followers','nobody','keep signature',1),('bob','Bob','<p>Other bio</p>','[]','[]','anyone','anyone','other signature',0);");
$GLOBALS['cache']=[];$GLOBALS['invalidated']=[];$GLOBALS['exports']=[];$GLOBALS['ranked']=[];$GLOBALS['hydrated']=[];$checks=0;
function settings_check(bool $ok,string $case): void {if(!$ok)throw new RuntimeException($case);$GLOBALS['checks']++;}
$before=ap_profile_get('alice');$bob=ap_profile_get('bob');$base=['name'=>'Alice','summary'=>'<p>Unicode é bio</p>'];
foreach($flags as $flag) {
    $value=!$before[$flag];
    settings_check(ap_profile_save($base+[$flag=>$value],'alice')['ok'],'actual save '.$flag);
    $after=ap_profile_get('alice');settings_check($after[$flag]===$value,'actual current read '.$flag);
    foreach($flags as $other)if($other!==$flag)settings_check($after[$other]===$before[$other],$flag.' preserves omitted '.$other);
    settings_check($after['reply_policy']===$before['reply_policy']&&$after['quote_policy']===$before['quote_policy'],'preserve interaction privacy '.$flag);
    settings_check(ap_profile_get('bob')===$bob,'second owner unchanged '.$flag);
    settings_check(($GLOBALS['exports']['alice'][$flag]??null)===$value,'public export fresh '.$flag);
    $before=$after;
}
settings_check($GLOBALS['ranked']===[201]&&$GLOBALS['hydrated']===[201],'algorithm transition only invalidates current owner');
settings_check(ap_profile_save($base+['reply_policy'=>'nobody','quote_policy'=>'followers'],'alice')['ok'],'interaction policy save');
$p=ap_profile_get('alice');settings_check($p['reply_policy']==='nobody'&&$p['quote_policy']==='followers','current interaction policy read');
settings_check(ap_profile_collection_consent('alice')===true,'consent current helper');
settings_check(ap_profile_save($base+['collection_consent'=>false,'webmentions_enabled'=>false],'alice')['ok'],'disable consent and webmentions');
settings_check(!ap_profile_collection_consent('alice'),'consent withdrawal current helper');
settings_check(!ap_webmention_target_enabled('https://mkultra.monster/users/alice/notes/abcdef'),'webmentions withdrawal current helper');
settings_check(ap_profile_save($base+['webmentions_enabled'=>true],'alice')['ok'],'webmentions enable');
settings_check(ap_webmention_target_enabled('https://mkultra.monster/users/alice/notes/abcdef'),'webmentions current helper enabled');
settings_check(!ap_webmention_target_enabled('https://mkultra.monster/users/bob/notes/abcdef'),'webmention owner isolation');
$p=ap_profile_get('alice');
foreach([['name'=>''],['name'=>str_repeat('é',101)],['summary'=>''],['icon_url'=>'http://insecure.test/avatar'],['forum_signature'=>str_repeat('é',501)]] as $bad) {
    settings_check(!ap_profile_save(array_replace($base,$bad),'alice')['ok'],'invalid settings rejected '.array_key_first($bad));
    settings_check(ap_profile_get('alice')===$p,'invalid settings cause no write');
}
settings_check(ap_profile_save(array_replace($base,['name'=>str_repeat('é',100)]),'alice')['ok'],'real Unicode 100 character boundary');
settings_check(ap_profile_get('alice')['name']===str_repeat('é',100),'Unicode boundary saved exact name');
$db->beginTransaction();$db->exec("UPDATE actor_profile SET collection_consent=1 WHERE actor_key='alice'");$db->rollBack();
$st=$db->query("SELECT collection_consent FROM actor_profile WHERE actor_key='alice'");settings_check((int)$st->fetchColumn()===0,'rollback durable privacy remains withdrawn');
echo 'library-settings-durable: PASS ('.$checks." checks; actual PHP profile save/read + consent/webmention helpers; SQLite/Redis/file adapters)\n";
