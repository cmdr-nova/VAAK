<?php
declare(strict_types=1);
/** Test-only entry point: exact current CLI helper/SQL media body, declared external transports. */
function ap_db():PDO{
 static $db=null;if($db instanceof PDO)return $db;
 $dsn=(string)getenv('AP_DB_DSN');if(!str_contains($dsn,'/tmp/vaak-library-facts-pg-socket'))throw new RuntimeException('owned fixture DB only');
 $db=new PDO($dsn,'postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);return $db;
}
function ap_db_now():string{return (string)getenv('PROFILE_FEATURED_MEDIA_CLOCK');}
require __DIR__.'/profile-featured-media-transport-bootstrap.php';
$path=dirname(__DIR__).'/ap-db.php';$source=(string)file_get_contents($path);preg_match_all('/^function ([A-Za-z0-9_]+)\(.*?^}/ms',$source,$matches,PREG_SET_ORDER);
foreach($matches as $m){if(function_exists($m[1]))continue;$resolved='';foreach(token_get_all('<?php '.$m[0])as $t)$resolved.=is_array($t)&&$t[0]===T_DIR?var_export(dirname($path),true):(is_array($t)?$t[1]:$t);eval(substr($resolved,5));}
if(isset($GLOBALS['profile_featured_media_transport']['helper_fault'])){switch($GLOBALS['profile_featured_media_transport']['helper_fault']){case 'failure':exit(7);case 'malformed':echo '{';exit;case 'oversized':echo str_repeat('x',65537);exit;case 'timeout':sleep(16);break;}}
$path=dirname(__DIR__).'/ap-profile-featured-media.php';$source=(string)file_get_contents($path);
$source=str_replace(["require_once __DIR__.'/ap-db.php';","require_once __DIR__.'/ap-r2.php';"],'',$source);ob_start();eval(substr($source,5));$reply=(string)ob_get_clean();
$c=$GLOBALS['profile_featured_media_transport'];if(isset($c['receipt_fault'])){$v=json_decode($reply,true,512,JSON_THROW_ON_ERROR);switch($c['receipt_fault']){case 'actor':$v['actor']='https://wrong.test/users/actor';break;case 'before':$v['cache_receipts'][0]['before']=[];break;case 'table':$v['cache_receipts'][0]['table']='ap_users';break;}$reply=json_encode($v,JSON_THROW_ON_ERROR);}
if(isset($c['after_receipt_sql']))ap_db()->exec((string)$c['after_receipt_sql']);
$log=getenv('PROFILE_FEATURED_MEDIA_RECEIPT_LOG');if(is_string($log)&&$log!=='')file_put_contents($log,$reply."\n",FILE_APPEND|LOCK_EX);echo $reply;
