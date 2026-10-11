<?php
declare(strict_types=1);require dirname(__DIR__).'/ap-admin-native.php';
$request=['owner_id'=>1,'view'=>'users','mode'=>'full','offset'=>1,'approvals_enabled'=>false,'search'=>'x'];
$row=['id'=>1,'username'=>'user','email'=>null,'actor_key'=>'user','actor_id'=>'https://local/users/user','is_admin'=>0,'created_at'=>'2026-10-10 12:00:00','updated_at'=>'2026-10-10 12:00:00','disabled_at'=>null,'profile_name'=>null,'icon_url'=>null];
$good=['protocol_version'=>1,'ok'=>true,'request_binding'=>$request,'source_count'=>1,'rows'=>[$row],'html'=>'<article>native</article>'];$checks=[];
function users_check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);$GLOBALS['checks'][]=$name;}
users_check(ap_admin_native_reply($good,$request)===$good['html'],'valid Users bound typed receipt');
foreach(['owner_id'=>2,'view'=>'invites','mode'=>'shell','offset'=>2,'approvals_enabled'=>true,'search'=>'other'] as $k=>$v){$bad=$good;$bad['request_binding'][$k]=$v;users_check(ap_admin_native_reply($bad,$request)===null,'reject borrowed '.$k);}
foreach(['password_hash'=>'forbidden','username'=>null,'id'=>'1','is_admin'=>'1','actor_key'=>null,'created_at'=>null] as $k=>$v){$bad=$good;$bad['rows'][0][$k]=$v;users_check(ap_admin_native_reply($bad,$request)===null,'reject unsafe row '.$k);}
$bad=$good;$bad['rows']=array_fill(0,21,$row);$bad['source_count']=21;users_check(ap_admin_native_reply($bad,$request)===null,'Users cap20');
$bad=$good;unset($bad['request_binding']['search']);users_check(ap_admin_native_reply($bad,$request)===null,'Users search binding required');
file_put_contents($argv[1]??'/tmp/admin-users-native-protocol.json',json_encode(['checks'=>$checks,'passed'=>count($checks)],JSON_PRETTY_PRINT));echo count($checks)." Users protocol controls\n";
