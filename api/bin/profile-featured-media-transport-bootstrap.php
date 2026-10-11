<?php
declare(strict_types=1);
/** Test-only external actor/download/R2 transport. The media resolver body is unchanged. */
$path=getenv('PROFILE_FEATURED_MEDIA_TRANSPORT');
if(!is_string($path)||!str_starts_with($path,'/tmp/')&& !str_contains($path,'/rust/target/parity-evidence/'))throw new RuntimeException('owned fixture transport path');
$GLOBALS['profile_featured_media_transport']=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
function featured_transport_record(string $kind,array $args):void{
 $c=$GLOBALS['profile_featured_media_transport'];$out=$c['log']??null;if(!is_string($out))throw new RuntimeException('missing transport log');$row=['kind'=>$kind,'args'=>$args];if(!empty($c['transport_trace']))$row+=['pid'=>getmypid(),'time'=>microtime(true)];file_put_contents($out,json_encode($row,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
}
function ap_fetch_actor_doc(string $actor):?array{
 featured_transport_record('actor_document',[$actor]);$c=$GLOBALS['profile_featured_media_transport'];if(isset($c['delay_ms']))usleep((int)$c['delay_ms']*1000);
 if(isset($c['mutation_sql'])){ap_db()->exec((string)$c['mutation_sql']);}
 if(!empty($c['transport_trace']))featured_transport_record('actor_document_return',[$actor]);return $c['document']??null;
}
function ap_remote_media_download(string $source,float $timeout=4.0):array{featured_transport_record('media_download',[$source,$timeout]);$c=$GLOBALS['profile_featured_media_transport'];if(isset($c['download_delay_ms']))usleep((int)$c['download_delay_ms']*1000);if(!empty($c['transport_trace']))featured_transport_record('media_download_return',[$source]);return $GLOBALS['profile_featured_media_transport']['download']??['ok'=>false];}
function ap_r2_put_object(string $key,string $body,string $type):array{featured_transport_record('r2_put',[$key,$body,$type]);$c=$GLOBALS['profile_featured_media_transport'];if(!empty($c['transport_trace']))featured_transport_record('r2_put_return',[$key]);return !empty($c['put_ok'])?['ok'=>true,'key'=>$key,'public_url'=>$c['public_url']]:['ok'=>false];}
function ap_r2_delete_object(string $key):array{featured_transport_record('r2_delete',[$key]);return ['ok'=>true];}
$sourcePath=dirname(__DIR__).'/ap-r2.php';$source=(string)file_get_contents($sourcePath);preg_match_all('/^function ([A-Za-z0-9_]+)\(.*?^}/ms',$source,$matches,PREG_SET_ORDER);
foreach($matches as $m){if(!in_array($m[1],['ap_remote_media_ensure','ap_as2_media_url','ap_remote_media_download_slot','ap_remote_media_download_slot_release'],true)||function_exists($m[1]))continue;$resolved='';foreach(token_get_all('<?php '.$m[0])as $t)$resolved.=is_array($t)&&$t[0]===T_DIR?var_export(dirname($sourcePath),true):(is_array($t)?$t[1]:$t);eval(substr($resolved,5));}
