<?php
declare(strict_types=1);
require_once __DIR__ . '/../ap-library-native.php';
function ap_auth_current_user(): ?array { return ['id'=>1]; }
ob_start();require __DIR__ . '/migration-card-reference-capture.php';$baseline=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
$base=$baseline['captures']['plain']['status'];$quote=array_replace($base,['id'=>'99','uri'=>'https://quote.test/users/author/statuses/99','url'=>'https://quote.test/@author/99','content'=>'<p>Current quoted body</p>','spoiler_text'=>'Quote CW','sensitive'=>true]);
$target=$quote['url'];$cases=[];
foreach (['accepted','pending'] as $state) $cases[$state]=array_replace($base,['content'=>'Comment','quote'=>['state'=>$state,'quoted_status'=>$quote]]);
$cases['pending_cached']=array_replace($base,['content'=>'Comment','quote'=>['state'=>'pending'],'quote_url'=>$target]);
$cases['raw_cached']=array_replace($base,['content'=>"Comment\n↪ QT @author@quote.test: $target",'quote_url'=>$target]);
$cases['compact_cached']=array_replace($base,['content'=>"Comment\n↪ QT @author@quote.test: Compact old body",'quote_url'=>$target]);
$cases['pending_missing']=array_replace($base,['content'=>'Comment','quote'=>['state'=>'pending'],'quote_url'=>'https://missing.test/quote']);
$cases['raw_missing']=array_replace($base,['content'=>"Comment\n↪ QT @author@quote.test: https://missing.test/quote"]);
$cases['compact_missing']=array_replace($base,['content'=>"Comment\n↪ QT @author@quote.test: Compact old body"]);
foreach($cases as $name=>$status){
 ap_db()->exec('DELETE FROM fixture_status_cache');ap_db()->prepare('INSERT INTO fixture_status_cache VALUES(?,?)')->execute([$target,json_encode($quote)]);
 $outputs=[];$effects=[];
 foreach([false,true] as $enabled){
  putenv('VAAK_LIBRARY_NATIVE_RENDER='.($enabled?'1':'0'));putenv('VAAK_LIBRARY_NATIVE_SECRET='.str_repeat('s',32));putenv('VAAK_LIBRARY_NATIVE_URL=http://public.invalid/internal/library/render');
  $GLOBALS['fixture_render_clock']=1700000000;$GLOBALS['admin_quote_link_preview_budget']=3;$GLOBALS['preview_http_requests']=[];$GLOBALS['preview_warm_requests']=[];
  $outputs[]=admin_bookmark_mixed_markup([['kind'=>'fedi','item'=>$status]],23,20,true,0,false);
  $effects[]=['fetch_budget'=>$GLOBALS['admin_library_fetch_budget'],'link_budget'=>$GLOBALS['admin_quote_link_preview_budget'],'network'=>$GLOBALS['preview_http_requests'],'warming'=>$GLOBALS['preview_warm_requests']];
 }
 if($outputs[0]!==$outputs[1])throw new RuntimeException("Canonical fallback DOM changed: $name");
 if($effects[0]!==$effects[1])throw new RuntimeException("Canonical fallback budget/IO changed: $name");
 if(!str_contains($outputs[1],'data-offset="23"')||!str_contains($outputs[1],'data-has-more="1"'))throw new RuntimeException('Cursor shell changed');
}
echo "library-native-fallback: PASS (8 actual PHP complete DOM/cursor/budget/network/warming comparisons; cache-only lookup transport fixture)\n";
