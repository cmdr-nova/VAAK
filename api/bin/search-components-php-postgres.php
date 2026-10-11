<?php
/** Actual canonical component helpers/painters on isolated PostgreSQL tables. */
declare(strict_types=1);
require __DIR__.'/migration-reference-functions.php';
$schema=$argv[1]??'';
if(!preg_match('/^search_components_(integer|bigint)$/D',$schema))throw new RuntimeException('Disposable schema required');
$GLOBALS['db']=new PDO('pgsql:host=/tmp/vaak-library-facts-pg-socket;dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function ap_db():PDO{return $GLOBALS['db'];}
function ap_db_masto_owner_user_id():int{return (int)$GLOBALS['vaak_owner_id'];}
// Schema initialization is owned by the disposable native fixture; these hooks
// replace migration/bootstrap IO only, never relationship or painter policy.
function ap_bsky_graph_sync_migrate():void{}
function ap_bsky_actor_refresh_migrate(?PDO $db=null):bool{return true;}
ap_db()->exec('SET search_path='.$schema);
$owner=(int)$argv[2];$input=json_decode($argv[3],true,512,JSON_THROW_ON_ERROR);$kind=$argv[4]??'account';
$user=$owner>0?ap_db()->query('SELECT * FROM ap_users WHERE id='.$owner)->fetch():null;
$GLOBALS['vaak_user']=is_array($user)?$user:['username'=>'guest'];$GLOBALS['vaak_owner_id']=$owner;
$GLOBALS['vaak_actor_id']=$user['actor_id']??'';$GLOBALS['vaak_actor_key']=$user['actor_key']??'__guest__';
$GLOBALS['ap_req_actor']=['id'=>$GLOBALS['vaak_actor_id'],'key'=>$GLOBALS['vaak_actor_key']];
reference_load(dirname(__DIR__).'/ap-admin.php',['h','admin_owner_user_id','vaak_actor_id','vaak_is_own_url','vaak_is_local_url','admin_search_viewer_username','admin_is_following','admin_rel_badge','admin_open_profile_label','admin_emoji_html','admin_search_render_account','admin_search_render_hashtag']);
reference_load(dirname(__DIR__).'/ap-vaak-profile-route.php',['ap_vaak_normalize_actor_key','ap_vaak_pretty_profile_path']);
reference_load(dirname(__DIR__).'/ap-db.php',['ap_fix_utf8','ap_request_actor_get','ap_local_actor_id','ap_follow_lists_cache_gen','ap_following_list','ap_followers_list','ap_actor_id_membership_map','ap_moderation_syntactic_refs','ap_remote_actor_username_is_placeholder','ap_remote_emoji_map_for_host','ap_remote_emoji_ensure_host','ap_remote_emoji_ensure_for_display','ap_emoji_html']);
$source=(string)file_get_contents(dirname(__DIR__).'/ap-db.php');preg_match_all('/^function\s+(ap_moderation_[A-Za-z0-9_]+)\s*\(/m',$source,$moderation);reference_load(dirname(__DIR__).'/ap-db.php',$moderation[1]);preg_match_all('/^function\s+(ap_profile_[A-Za-z0-9_]+)\s*\(/m',$source,$matches);reference_load(dirname(__DIR__).'/ap-db.php',$matches[1]);
reference_load(dirname(__DIR__).'/ap-masto-entities.php',['ap_masto_session_account_id','ap_masto_local_user_by_account_id','ap_masto_remote_account_acct_key','ap_masto_remote_account_id','ap_masto_actor_id_aliases','ap_masto_actor_id_from_account_id','ap_masto_relationship_for_account_id','ap_masto_normalize_tag_name','ap_masto_tag_is_following']);
reference_load(dirname(__DIR__).'/ap-bsky.php',['ap_bsky_is_profile_ref','ap_bsky_graph_sync_get','ap_bsky_graph_sync_list','ap_bsky_actor_profile_cache_get','ap_bsky_owner_follows_ref']);
if($kind==='id'){echo json_encode(['id'=>ap_masto_remote_account_id((string)$input['actor'])],JSON_THROW_ON_ERROR);exit;}
if($kind==='tag'){ob_start();admin_search_render_hashtag($input);$html=ob_get_clean();echo json_encode(['html'=>$html,'following'=>$input['following']],JSON_THROW_ON_ERROR);exit;}
$rows=$owner>0?ap_following_list($GLOBALS['vaak_actor_id']):[];
$following=ap_actor_id_membership_map($rows,true);
ob_start();admin_search_render_account($input,$following);$html=ob_get_clean();
echo json_encode(['html'=>$html,'viewer'=>admin_search_viewer_username()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
