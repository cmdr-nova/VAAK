<?php
declare(strict_types=1);
require dirname(__DIR__).'/ap-admin-native.php';
$request=['owner_id'=>1,'view'=>'approvals','mode'=>'full','offset'=>0,'approvals_enabled'=>true];
$row=['id'=>1,'username'=>'private','email'=>null,'status'=>'pending','created_at'=>'2026-10-10 12:00:00','decided_at'=>null];
$good=['protocol_version'=>1,'ok'=>true,'request_binding'=>$request,'source_count'=>1,'rows'=>[$row],'html'=>'<article>native</article>'];
$checks=[];$assert=static function(bool $ok,string $name)use(&$checks){if(!$ok)throw new RuntimeException($name);$checks[]=$name;};
$assert(ap_admin_native_reply($good,$request)===$good['html'],'valid bound typed receipt');
foreach(['owner_id'=>2,'view'=>'invites','mode'=>'shell','offset'=>100,'approvals_enabled'=>false] as $key=>$value){$bad=$good;$bad['request_binding'][$key]=$value;$assert(ap_admin_native_reply($bad,$request)===null,'reject borrowed '.$key);}
foreach(['password_hash'=>'forbidden','username'=>null,'status'=>'unknown','id'=>'1','created_at'=>null] as $key=>$value){$bad=$good;$bad['rows'][0][$key]=$value;$assert(ap_admin_native_reply($bad,$request)===null,'reject row '.$key);}
foreach(['protocol_version'=>2,'source_count'=>0,'ok'=>false,'html'=>str_repeat('x',2*1024*1024+1),'extra'=>'unproven'] as $key=>$value){$bad=$good;$bad[$key]=$value;$assert(ap_admin_native_reply($bad,$request)===null,'reject envelope '.$key);}
$assert(ap_admin_native_url_allowed('http://127.0.0.1:8787/internal/admin/read'),'allow local endpoint');
foreach(['https://127.0.0.1/internal/admin/read','http://example.test/internal/admin/read','http://127.0.0.1/internal/admin/read?override=1','http://u:p@127.0.0.1/internal/admin/read','http://127.0.0.1/other'] as $url)$assert(!ap_admin_native_url_allowed($url),'reject remote/selector URL '.$url);
putenv('VAAK_ADMIN_READ_NATIVE');$assert(!ap_admin_native_enabled(),'default off');putenv('VAAK_ADMIN_READ_NATIVE=1');putenv('VAAK_ADMIN_READ_SECRET=short');$assert(!ap_admin_native_enabled(),'short secret denied');
file_put_contents($argv[1]??'/tmp/admin-native-protocol.json',json_encode(['checks'=>$checks,'passed'=>count($checks)],JSON_PRETTY_PRINT));echo json_encode(['passed'=>count($checks)]).PHP_EOL;
