<?php
declare(strict_types=1);
require_once __DIR__ . '/../ap-library-native.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach (['http://127.0.0.1:8787/internal/library/render','http://[::1]:8787/internal/library/render'] as $url) check(ap_library_native_url_allowed($url),'literal loopback accepted');
foreach (['https://127.0.0.1/internal/library/render','http://localhost/internal/library/render','http://127.0.0.1.evil.test/internal/library/render','http://user@127.0.0.1/internal/library/render','http://127.0.0.1/internal/library/render?q=1','http://127.0.0.1/shadow/library-fragment','http://192.168.1.2/internal/library/render','http://127.0.0.1/internal/library/render#x'] as $url) check(!ap_library_native_url_allowed($url),'unsafe bridge URL rejected');
putenv('VAAK_LIBRARY_NATIVE_RENDER');putenv('VAAK_LIBRARY_NATIVE_SECRET');check(!ap_library_native_enabled(),'default disabled');check(ap_library_native_render_batch([], 'bookmarks')===null,'default disabled no auth/transport');
putenv('VAAK_LIBRARY_NATIVE_RENDER=1');putenv('VAAK_LIBRARY_NATIVE_SECRET=short');check(!ap_library_native_enabled(),'short secret disabled');
$GLOBALS['lookups']=0;
function ap_masto_lookup_status_by_object_url(string $url,int $depth,bool $fetch,bool $hidden):?array{
 check($depth===0&&!$fetch&&$hidden,'cache-only canonical lookup flags');$GLOBALS['lookups']++;
 return ['id'=>'99','uri'=>'https://remote.test/users/a/statuses/99','url'=>$url,'content'=>'Current body','visibility'=>'private','account'=>['uri'=>'https://remote.test/users/a','acct'=>'a@remote.test'],'raw_authority'=>['to'=>['https://remote.test/users/allowed']]];
}
$source=ap_library_native_assemble_status(['id'=>'saved','content'=>'Comment','quote_url'=>'https://remote.test/@a/99','quote'=>['state'=>'pending']]);
check($source['quote']['state']==='pending','pending preserved');check($source['quote']['quoted_status']['visibility']==='private','full authority preserved');check($source['quote_url']==='https://remote.test/@a/99','web alias preserved');check($GLOBALS['lookups']===1,'one cache lookup');
$again=ap_library_native_assemble_status($source);check($again===$source&&$GLOBALS['lookups']===1,'assembled source does not repeat lookup');
echo "library-native-bridge: PASS (default off, token minimum, 10 URL controls, pending full cached authority, no repeat IO)\n";
