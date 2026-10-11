<?php
/** Real PHP file sessions and actual auth readers; isolated paths/DB/renderer inputs. */
declare(strict_types=1);
require __DIR__.'/migration-reference-functions.php';
const AP_AUTH_SESSION_NAME='vaak_sess'; const AP_AUTH_SESSION_TTL=7776000; const AP_AUTH_COOKIE_REFRESH_EVERY=86400;
function ap_auth_bootstrap():void{} // Schema/seed bootstrap is unrelated to this lock test.
function ap_auth_session_save_path():string{return getenv('TREND_SESSION_DIR');}
function ap_auth_cookie_params():array{return ['lifetime'=>AP_AUTH_SESSION_TTL,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Lax'];}
function ap_auth_emit_session_cookie():void{} // CLI has no browser cookie transport.
function ap_db():PDO{return $GLOBALS['db'];}
reference_load(dirname(__DIR__).'/ap-auth.php',['ap_auth_start_session','ap_auth_touch_session_cookie','ap_auth_current_user','ap_auth_user_by_id']);
$db=new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE ap_users(id INTEGER,disabled_at TEXT); INSERT INTO ap_users VALUES(201,NULL),(202,NULL)');
session_id('trends-session-regression');
$role=$argv[1];
if($role==='seed'){ap_auth_start_session();$_SESSION['vaak_user_id']=201;$_SESSION['_vaak_cookie_touched']=time();$_SESSION['draft']='retained';session_write_close();exit;}
if($role==='consumer'){$start=microtime(true);ap_auth_start_session();$wait=microtime(true)-$start;$_SESSION['vaak_user_id']=202;$draft=$_SESSION['draft']??null;session_write_close();echo json_encode(['wait'=>$wait,'draft'=>$draft]);exit;}
function ap_masto_trends_cache_read(string $kind,int $min,bool $stale):?array{
 if($kind==='tags'){file_put_contents(getenv('TREND_SESSION_DIR').'/ready',json_encode(['active'=>session_status()===PHP_SESSION_ACTIVE]));usleep(900000);}
 return ['age'=>1,'stale'=>false,'items'=>[]];
}
function admin_trend_downrank_ref_set(int $id):array{return [];}
reference_load(getenv('TREND_REFERENCE_SOURCE')?:dirname(__DIR__).'/ap-admin.php',['admin_trends_sidebar_html']);
$html=admin_trends_sidebar_html('home',true,$meta);$active=session_status()===PHP_SESSION_ACTIVE;
if($active)session_write_close();echo json_encode(['html'=>$html,'active_after'=>$active]);
