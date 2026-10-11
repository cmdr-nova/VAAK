<?php
/** Actual canonical PHP→Rust transport; genuine curl required, fixture DB/session only. */
declare(strict_types=1);
require_once __DIR__.'/../ap-library-native.php';
function ap_auth_current_user(): ?array {return ['id'=>1];}
ob_start();require __DIR__.'/migration-card-reference-capture.php';$reference=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
foreach(['fixture_words','fixture_mutes','fixture_blocks','fixture_server_blocks','bsky_hide_dids','masto_favourites','masto_reblogs','masto_bookmarks'] as $table)ap_db()->exec('DELETE FROM '.$table);
$base=$reference['captures']['plain']['status'];$base['created_at']='2020-01-01T12:00:00Z';
$media=$reference['captures']['cw_media']['status'];$media['created_at']=$base['created_at'];
if(($argv[1]??'')==='--export') {echo json_encode(['owner_actor'=>vaak_actor_id(),'avatar'=>admin_actor_avatar_url($base['account']['uri']),'auto_unblur'=>(int)(ap_profile_get('fixture')['auto_unblur_sensitive']??0),'statuses'=>[$base,$media]],JSON_THROW_ON_ERROR);exit;}
if(!extension_loaded('curl'))throw new RuntimeException('Requires genuine curl extension');
function full_dom_tokens(string $html):array {preg_match_all('/<[^>]+>|[^<]+/s',$html,$m);return array_values(array_filter(array_map(static fn($s)=>trim($s," \t\r\n\0\x0B"),$m[0]),static fn($s)=>$s!==''));}
$checks=0;
foreach([false,true] as $lazy)foreach(['favourites','bookmarks'] as $view){
 $GLOBALS['admin_bookmark_lazy_media']=$lazy;$GLOBALS['fixture_render_clock']=time();
 ob_start();foreach([$base,$media] as $st)admin_render_library_status_card($st,$view);$canonical=ob_get_clean();
 $native=ap_library_native_render_batch([$base,$media],$view);
 if($native===null)throw new RuntimeException("Native fulltransport fell back lazy=".(int)$lazy." view=$view");
 if(full_dom_tokens($native)!==full_dom_tokens($canonical)){file_put_contents('/tmp/vaak-independent-php-canonical.html',$canonical);file_put_contents('/tmp/vaak-independent-native-accepted.html',$native);throw new RuntimeException("Actual PHP/native fullDOM mismatch lazy=".(int)$lazy." view=$view");}$checks++;
 $unsupported=['id'=>'thin','uri'=>'https://missing.test/post','content'=>'','account'=>['acct'=>'?']];
 if(ap_library_native_render_batch([$base,$unsupported],$view)!==null)throw new RuntimeException('Unsupported batch exposed partial native HTML');$checks++;
}
echo "library-php-worker-independent: PASS ($checks genuine PHPcurl→Rust fullDOM/returnview/lazycontext/wholebatchfallback controls)\n";
