<?php
/** Actual moderation alias/set/invalidation helpers; SQLite DB and Redis transport fixture. */
declare(strict_types=1);
require __DIR__.'/migration-reference-functions.php';
if (!extension_loaded('mbstring')) throw new RuntimeException('Actual policy tests require mbstring.');
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE ap_blocks(scope TEXT,kind TEXT,value TEXT,created_at TEXT);
CREATE TABLE ap_user_blocks(id INTEGER,owner_user_id INTEGER,scope TEXT,value TEXT,created_at TEXT);
CREATE TABLE ap_mutes(owner_user_id INTEGER,actor_id TEXT,created_at TEXT);
CREATE TABLE bsky_posts(author_handle TEXT,author_did TEXT);
CREATE TABLE bsky_actor_profiles(did TEXT,actor_ref TEXT,profile_json TEXT);');
$GLOBALS['redis_fixture']=[];$GLOBALS['deleted']=[];$GLOBALS['reads']=[];
function ap_db(): PDO { return $GLOBALS['db']; }
function ap_redis_json_get(string $key): mixed { $GLOBALS['reads'][]=$key;return $GLOBALS['redis_fixture'][$key]??null; }
function ap_redis_json_set(string $key,mixed $value,int $ttl): void { $GLOBALS['redis_fixture'][$key]=$value; }
function ap_redis_delete(string ...$keys): void { foreach($keys as $key){$GLOBALS['deleted'][]=$key;unset($GLOBALS['redis_fixture'][$key]);} }
function ap_redis_relsets_enabled(): bool { return true; }
function ap_timeline_cache_invalidate_owner(int $owner): void {}
function ap_notification_projection_invalidate_owner(int $owner): void {}
function ap_notification_projection_invalidate_all(): void {}
reference_load(dirname(__DIR__).'/ap-db.php',['ap_actor_id_membership_map','ap_block_list','ap_block_list_cached','ap_block_cache_clear','ap_actor_moderation_aliases','ap_actor_moderation_refs_match','ap_moderation_syntactic_refs','ap_moderation_path_segment_forms','ap_server_blocked_actors_set','ap_is_blocked_host','ap_is_blocked_actor','ap_is_globally_muted_actor','ap_user_blocks_list','ap_user_blocks_list_cached','ap_user_blocks_cache_clear','ap_blocks_actor_id_set','ap_user_is_blocked','ap_mutes_list','ap_mutes_set_cached','ap_mutes_cache_clear','ap_is_muted_actor']);
$count=0;function check(bool $v,string $label): void { if(!$v)throw new RuntimeException($label);$GLOBALS['count']++; }
$GLOBALS['redis_fixture']['vaak:relset:v1:blocks-actors:201']=[];
$GLOBALS['redis_fixture']['vaak:moderation:mutes:v2:201']=[];
$GLOBALS['redis_fixture']['vaak:moderation:server-blocks:v1']=[];
$db->exec("INSERT INTO ap_user_blocks VALUES(1,201,'actor','https://encoded.test/users/%61lice','now');
INSERT INTO ap_mutes VALUES(201,'https://mute.test/users/%61lice','now');
INSERT INTO ap_blocks VALUES('actor','block','https://server.test/users/%61lice','now'),('actor','mute','https://global.test/users/%61lice','now');");
foreach(['https://encoded.test/users/alice','https://encoded.test/@alice','https://encoded.test/users/%61lice'] as $candidate) check(ap_user_is_blocked($candidate,null,201),'stored encoded personal block '.$candidate);
check(!ap_user_is_blocked('https://encoded.test/users/Alice',null,201),'personal block keeps path case');
check(!ap_user_is_blocked('https://encoded.test/@alice',null,202),'personal block owner isolation');
foreach(['https://mute.test/users/alice','https://mute.test/@alice','https://mute.test/users/%61lice'] as $candidate) check(ap_is_muted_actor($candidate,201),'stored encoded personal mute '.$candidate);
check(!ap_is_muted_actor('https://mute.test/users/Alice',201),'personal mute keeps path case');
check(!ap_is_muted_actor('https://mute.test/@alice',202),'personal mute owner isolation');
foreach(['https://server.test/users/alice','https://server.test/@alice','https://server.test/users/%61lice'] as $candidate) check(ap_is_blocked_actor($candidate),'stored encoded server block '.$candidate);
check(!ap_is_blocked_actor('https://server.test/users/Alice'),'server block keeps path case');
foreach(['https://global.test/users/alice','https://global.test/@alice','https://global.test/users/%61lice','https://global.test/@ALICE'] as $candidate) check(ap_is_globally_muted_actor($candidate),'stored encoded global mute '.$candidate);
$db->exec("UPDATE ap_user_blocks SET value='https://encoded.test/users/alice';
UPDATE ap_mutes SET actor_id='https://mute.test/users/alice';
UPDATE ap_blocks SET value=replace(value,'%61lice','alice');");
ap_user_blocks_cache_clear(201);ap_mutes_cache_clear(201);ap_block_cache_clear();
check(ap_user_is_blocked('https://encoded.test/users/%61lice',null,201),'literal stored block matches encoded candidate');
check(ap_is_muted_actor('https://mute.test/users/%61lice',201),'literal stored mute matches encoded candidate');
check(ap_is_blocked_actor('https://server.test/users/%61lice'),'literal server block matches encoded candidate');
check(ap_is_globally_muted_actor('https://global.test/users/%61lice'),'literal server mute matches encoded candidate');
$db->exec("INSERT INTO ap_user_blocks VALUES(2,201,'actor','https://numeric.test/users/123','now');
INSERT INTO ap_mutes VALUES(201,'https://numeric.test/users/123','now');
INSERT INTO ap_blocks VALUES('actor','block','https://numeric.test/users/123','now'),('actor','mute','https://numeric-mute.test/users/123','now');");
ap_user_blocks_cache_clear(201);ap_mutes_cache_clear(201);ap_block_cache_clear();
check(ap_user_is_blocked('https://numeric.test/users/123',null,201) && !ap_user_is_blocked('https://numeric.test/@123',null,201),'numeric personal block has no web alias');
check(ap_is_muted_actor('https://numeric.test/users/123',201) && !ap_is_muted_actor('https://numeric.test/@123',201),'numeric personal mute has no web alias');
check(ap_is_blocked_actor('https://numeric.test/users/123') && !ap_is_blocked_actor('https://numeric.test/@123'),'numeric server block has no web alias');
check(ap_is_globally_muted_actor('https://numeric-mute.test/users/123') && !ap_is_globally_muted_actor('https://numeric-mute.test/@123'),'numeric global mute has no web alias');
$db->exec('DELETE FROM ap_user_blocks;DELETE FROM ap_mutes;DELETE FROM ap_blocks;');
ap_user_blocks_cache_clear(201);ap_mutes_cache_clear(201);ap_block_cache_clear();
check(!ap_user_is_blocked('https://encoded.test/@alice',null,201),'warm personal block clearing rebuilds actual set');
check(!ap_is_muted_actor('https://mute.test/@alice',201),'warm personal mute clearing rebuilds actual set');
check(!ap_is_blocked_actor('https://server.test/@alice'),'warm server block clearing resets compact memo and Redis');
check(!ap_is_globally_muted_actor('https://global.test/@alice'),'warm global mute clearing refreshes list');
foreach(['vaak:relset:v1:blocks-actors:201','vaak:relset:v2:blocks-actors:201','vaak:moderation:mutes:v2:201','vaak:moderation:mutes:v3:201','vaak:moderation:server-blocks:v1','vaak:moderation:server-blocks:v2'] as $key) check(in_array($key,$GLOBALS['deleted'],true),'exact version invalidation '.$key);
check(!in_array('vaak:relset:v1:blocks-actors:201',$GLOBALS['reads'],true),'old compact block maps never read');
check(!in_array('vaak:moderation:mutes:v2:201',$GLOBALS['reads'],true),'old compact mute maps never read');
check(!in_array('vaak:moderation:server-blocks:v1',$GLOBALS['reads'],true),'old compact server maps never read');
function warm_policy(string $actor): void {
    ap_db()->exec('DELETE FROM ap_user_blocks;DELETE FROM ap_mutes;DELETE FROM ap_blocks;');
    $q=ap_db()->prepare("INSERT INTO ap_user_blocks VALUES(1,201,'actor',?,'now')");$q->execute([$actor]);
    $q=ap_db()->prepare("INSERT INTO ap_mutes VALUES(201,?,'now')");$q->execute([$actor]);
    $q=ap_db()->prepare("INSERT INTO ap_blocks VALUES('actor','block',?,'now'),('actor','mute',?,'now')");$q->execute([$actor,$actor]);
    ap_user_blocks_cache_clear(201);ap_mutes_cache_clear(201);ap_block_cache_clear();
}
function policy_match(string $actor): array { return [ap_user_is_blocked($actor,null,201),ap_is_muted_actor($actor,201),ap_is_blocked_actor($actor),ap_is_globally_muted_actor($actor)]; }
foreach ([['alice%2F','alice'],['alice%3F','alice?'],['alice%23','alice#'],['alice%25','alice%'],['alice%FF','alice�'],['alice%G1','alice%25G1']] as [$escaped,$other]) {
    foreach ([[$escaped,$other],[$other,$escaped]] as [$stored,$candidate]) {
        $actor='https://edge.test/users/'.$stored;warm_policy($actor);
        check(policy_match($actor)===[true,true,true,true],'reserved/invalid exact stored self remains hidden '.$stored);
        check(policy_match('https://edge.test/users/'.$candidate)===[false,false,false,false],'reserved/invalid collision denied both directions '.$stored.' versus '.$candidate);
        $refs=ap_moderation_syntactic_refs($actor);
        if (str_contains($stored,'%2F')) check(!in_array('https://edge.test/users/alice%252F',$refs,true),'retained delimiter escape never double encoded');
        $map=ap_actor_id_membership_map([['actor_id'=>$actor]],true);
        check(empty($map['https://edge.test/users/'.$candidate]),'actual rich relationship map also preserves distinct segment '.$stored);
    }
}
foreach ([['users/%31','users/1','@1'],['@%31','@1','users/1'],['users/1','users/%31','@%31'],['@1','@%31','users/%31']] as [$stored,$same,$other]) {
    warm_policy('https://edge.test/'.$stored);
    check(policy_match('https://edge.test/'.$same)===[true,true,true,true],'numeric unreserved encoding same URI shape '.$stored);
    check(policy_match('https://edge.test/'.$other)===[false,false,false,false],'numeric cross shape remains distinct '.$stored);
}
foreach ([['élise','%C3%A9lise'],['%C3%A9lise','élise']] as [$stored,$candidate]) {
    warm_policy('https://edge.test/users/'.$stored);
    check(policy_match('https://edge.test/users/'.$candidate)===[true,true,true,true],'valid UTF8 literal/encoded aliases '.$stored);
    check(policy_match('https://edge.test/@'.$candidate)===[true,true,true,true],'valid UTF8 canonical/web aliases '.$stored);
}
foreach(['vaak:relset:v3:blocks-actors:201','vaak:moderation:mutes:v4:201','vaak:moderation:server-blocks:v3'] as $key) check(in_array($key,$GLOBALS['deleted'],true),'latest exact namespace invalidation '.$key);
echo "moderation-alias-cache: PASS ($count actual-helper controls; SQLite DB and Redis transport fixture)\n";
