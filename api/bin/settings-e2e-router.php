<?php
declare(strict_types=1);
/** Test-only loopback SAPI runner: never included by production. */
if(PHP_SAPI!=='cli-server'||!in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true)){http_response_code(404);exit;}
require __DIR__.'/migration-reference-functions.php';
$runId=(string)getenv('VAAK_SETTINGS_E2E_RUN_ID');if(!preg_match('/^[a-f0-9]{32}$/',$runId))throw new RuntimeException('per-run fixture identity required');
$schema=(string)getenv('VAAK_SETTINGS_E2E_SCHEMA');
if(!preg_match('/^settings_e2e_[a-z0-9_]+$/',$schema))throw new RuntimeException('isolated schema required');
$GLOBALS['fixture_db']=new PDO('pgsql:host='.getenv('LIBRARY_FIXTURE_SOCKET').';dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['fixture_db']->exec('SET search_path='.$schema);
$GLOBALS['redis']=[];$GLOBALS['effects']=[];
function ap_db():PDO{return $GLOBALS['fixture_db'];}
function ap_auth_session_save_path():string{return (string)getenv('VAAK_SETTINGS_E2E_SESSIONS');}
function ap_redis_json_get(string $key):?array{return $GLOBALS['redis'][$key]??null;}
function ap_redis_json_set(string $key,array $value,int $ttl):bool{$GLOBALS['redis'][$key]=$value;return true;}
function ap_redis_delete(string ...$keys):void{foreach($keys as $key){unset($GLOBALS['redis'][$key]);$GLOBALS['effects'][]=['delete',$key];}}
function ap_timeline_cache_invalidate_owner(int $owner):void{$GLOBALS['effects'][]=['ranked',$owner];}
function ap_timeline_home_hydrate_invalidate_owner(int $owner):void{$GLOBALS['effects'][]=['hydrate',$owner];}
const AP_AUTH_SESSION_NAME='vaak_sess';const AP_AUTH_CSRF_KEY='_vaak_csrf';const AP_AUTH_SESSION_TTL=7776000;const AP_AUTH_COOKIE_REFRESH_EVERY=86400;
reference_load(dirname(__DIR__).'/ap-auth.php',['ap_auth_cookie_secure','ap_auth_cookie_params','ap_auth_emit_session_cookie','ap_auth_start_session','ap_auth_touch_session_cookie','ap_auth_csrf_token','ap_auth_csrf_ok','ap_auth_bootstrap','ap_auth_current_user','ap_auth_user_by_id','ap_auth_user_set_email']);
reference_load(dirname(__DIR__).'/ap-db.php',['ap_profile_save','ap_profile_save_unlocked','ap_profile_get','ap_profile_defaults','ap_fix_utf8','ap_profile_plain_bio_to_html','ap_profile_normalize_summary_html','ap_profile_sanitize_https_url','ap_profile_verify_attachments','ap_profile_identity_urls','ap_profile_actor_url','ap_profile_coerce_https_url','ap_profile_normalize_verify_url','ap_profile_normalize_badges','ap_profile_badge_catalog','ap_db_now','ap_profile_export_public_cache','ap_profile_collection_consent','ap_webmention_target_enabled','ap_note_interaction_policy_for_actor']);
reference_load(dirname(__DIR__).'/ap-admin.php',['admin_settings_projection_axum_fetch']);
$admin=(string)file_get_contents(dirname(__DIR__).'/ap-admin.php');
function e2e_slice(string $source,string $start,string $end):string{$a=strpos($source,$start);$b=strpos($source,$end,$a===false?0:$a);if($a===false||$b===false)throw new RuntimeException('source boundary drift');return substr($source,$a,$b-$a);}
$gate=e2e_slice($admin,'ap_auth_bootstrap();',"if (\$vaakGuestProfile && \$vaakUser === null)");
$csrf=e2e_slice($admin,'    // CSRF: session token','    // Server-wide / operator-only mutations');
$save=e2e_slice($admin,"        \$fieldNames = \$_POST['field_name'] ?? [];","            if (empty(\$saved['ok']))");
$vaakGuestProfile=false;$GLOBALS['vaak_guest_profile']=false;eval($gate);
$vaakOwnerId=(int)$vaakUser['id'];$vaakActorKey=(string)$vaakUser['actor_key'];$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=(string)($_POST['action']??'');eval($csrf);
 if($action==='save_profile'&&$error===null){eval($save."\n        }");}
 else if($error===null){http_response_code(400);$error='fixture accepts save_profile only';}
}
header('Content-Type: application/json');
echo json_encode(['fixture_run_id'=>$runId,'owner'=>$vaakOwnerId,'actor_key'=>$vaakActorKey,'csrf'=>ap_auth_csrf_token(),'error'=>$error,'save'=>$saved??null,'php'=>ap_profile_get($vaakActorKey),'native'=>admin_settings_projection_axum_fetch($vaakOwnerId),'collection_consent'=>ap_profile_collection_consent($vaakActorKey),'webmention_enabled'=>ap_webmention_target_enabled('https://mkultra.monster/users/'.$vaakActorKey.'/notes/abcdef'),'interaction_policy'=>ap_note_interaction_policy_for_actor($vaakActorKey),'effects'=>$GLOBALS['effects'],'source_hashes'=>['auth_gate'=>hash('sha256',$gate),'csrf_gate'=>hash('sha256',$csrf),'pre_publish_save'=>hash('sha256',$save)]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
