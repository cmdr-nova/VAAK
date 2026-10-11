<?php
declare(strict_types=1);
/** Actual PHP painters and caller markup, offline DB/HTTP transport fixtures. */
require_once __DIR__ . '/../ap-library-native.php';
function ap_auth_current_user(): ?array { return ['id'=>1]; }
ob_start(); require __DIR__ . '/migration-card-reference-capture.php';
$baseline = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
function caller_canonical_entries(array $entries, string $view): void {
    foreach ($entries as $entry) {
        if (!is_array($entry) || !is_array($entry['item'] ?? null)) continue;
        if (($entry['kind'] ?? '') === 'bsky') admin_render_bsky_feed_item($entry['item'], 'following', $view);
        else admin_render_library_status_card($entry['item'], $view);
    }
}
function caller_canonical_network(array $items, string $network, string $view): void {
    $entries=[]; foreach($items as $item) if(is_array($item)) $entries[]=['kind'=>$network,'item'=>$item];
    caller_canonical_entries($entries,$view);
}
$source=(string)file_get_contents(__DIR__.'/../ap-admin.php');
foreach(['admin_bookmark_fragment_markup','admin_bookmark_mixed_markup'] as $name) {
    if (!preg_match('/^function '.preg_quote($name,'/').'\(.*?^}/ms', $source, $match)) throw new RuntimeException('Missing caller declaration');
    $function=$match[0];
    $function=str_replace('function '.$name.'(', 'function '.$name.'_canonical(', $function);
    $function=str_replace(['admin_render_library_network_items(', 'admin_render_library_entries('], ['caller_canonical_network(', 'caller_canonical_entries('], $function);
    eval($function);
}
// Restore an unrestricted fixture owner after the reference corpus's deny cases.
foreach(['fixture_words','fixture_mutes','fixture_blocks','fixture_server_blocks','bsky_hide_dids'] as $table) ap_db()->exec('DELETE FROM '.$table);
unset($GLOBALS['ap_bsky_hide_did_cache']);
$base=$baseline['captures']['plain']['status'];
$bsky=$baseline['captures']['bsky_normalized']['status'];
$quote=array_replace($base,['id'=>'99','uri'=>'https://quote.test/users/author/statuses/99','url'=>'https://quote.test/@author/99','content'=>'<p>Current quote</p>','spoiler_text'=>'Quote CW','sensitive'=>true]);
$pending=array_replace($base,['id'=>'pending-source-124','uri'=>'https://remote.test/users/author/statuses/pending-source-124','url'=>'https://remote.test/@author/pending-source-124','content'=>'Comment','quote'=>['state'=>'pending'],'quote_url'=>$quote['url']]);
$cases=[];
foreach(['favourites','bookmarks'] as $view) foreach(['fedi','bsky'] as $network) {
 $items=$network==='fedi'?[$base,$pending]:[$bsky];
 $cases["$view-$network"]=[static function()use($items,$network,$view){caller_canonical_network($items,$network,$view);},static function()use($items,$network,$view){admin_render_library_network_items($items,$network,$view);}];
}
foreach(['fedi','bsky'] as $network) foreach([false,true] as $first) foreach([false,true] as $refresh) foreach([false,true] as $empty) {
 $items=$empty?[]:($network==='fedi'?[$base,$pending]:[$bsky]);
 $args=[$network,$items,37,20,true,5,$first,$refresh];
 $cases['network-'.implode('-',[$network,(int)$first,(int)$refresh,(int)$empty])]=[static function()use($args){echo admin_bookmark_fragment_markup_canonical(...$args);},static function()use($args){echo admin_bookmark_fragment_markup(...$args);}];
}
$entries=[['kind'=>'fedi','item'=>$pending],['kind'=>'bsky','item'=>$bsky]];
$cases['mixed']=[static function()use($entries){echo admin_bookmark_mixed_markup_canonical($entries,43,20,true,0,false);},static function()use($entries){echo admin_bookmark_mixed_markup($entries,43,20,true,0,false);}];
$count=0;$export=[];
foreach($cases as $name=>$painters) foreach([false,true] as $enabled) {
 $outputs=[];$effects=[];
 foreach($painters as $painter) {
  putenv('VAAK_LIBRARY_NATIVE_RENDER='.($enabled?'1':'0'));putenv('VAAK_LIBRARY_NATIVE_SECRET='.str_repeat('s',32));putenv('VAAK_LIBRARY_NATIVE_URL='.(getenv('LIBRARY_CALLER_FIXTURE_URL') ?: 'http://public.invalid/internal/library/render'));
  ap_db()->exec('DELETE FROM fixture_status_cache');ap_db()->prepare('INSERT INTO fixture_status_cache VALUES(?,?)')->execute([$quote['url'],json_encode($quote)]);
  ap_db()->exec('DELETE FROM link_preview_cards');
  $GLOBALS['fixture_render_clock']=1700000000;$GLOBALS['admin_library_fetch_budget']=3;$GLOBALS['admin_quote_link_preview_budget']=3;$GLOBALS['preview_http_requests']=[];$GLOBALS['preview_warm_requests']=[];
  ob_start();$painter();$outputs[]=ob_get_clean();
  $effects[]=['fetch'=>$GLOBALS['admin_library_fetch_budget'],'link'=>$GLOBALS['admin_quote_link_preview_budget'],'network'=>$GLOBALS['preview_http_requests'],'warm'=>$GLOBALS['preview_warm_requests']];
 }
 if($outputs[0]!==$outputs[1]) throw new RuntimeException("Actual caller DOM mismatch: $name enabled=".(int)$enabled);
 if($effects[0]!==$effects[1]) throw new RuntimeException("Actual caller IO/budget mismatch: $name enabled=".(int)$enabled);
 $export[$name][$enabled?'candidate_fallback':'php']=['html'=>$outputs[1],'effects'=>$effects[1]];
 $count++;
}
if(isset($argv[1])) {
 $fragments=[];
 foreach(['fedi','bsky'] as $network) {
  $items=$network==='fedi'?[$base,$pending]:[$bsky];
  foreach([false,true] as $enabled) {
   putenv('VAAK_LIBRARY_NATIVE_RENDER='.($enabled?'1':'0'));
   $mode=$enabled?'candidate_fallback':'php';
   foreach([0,1] as $page) {
    $next=$page===0?20:40;$more=$page===0;
    $pageItems=$items;$pageEntries=$entries;
    if($page===1) {
     foreach($pageItems as &$item) {
      if(isset($item['post'])) {$item['post']['uri']='at://did:plc:fixture/app.bsky.feed.post/page2unique';$item['post']['record']['text']='Second page unique Bluesky source';}
      else {$item['id']='page2-'.($item['id']??'source');$item['uri']='https://remote.test/users/author/statuses/'.$item['id'];$item['url']=$item['uri'];$item['content']='<p>Second page unique Fediverse source '.$item['id'].'</p>';}
     } unset($item);
     foreach($pageEntries as &$entry) {
      if($entry['kind']==='bsky'){$entry['item']['post']['uri']='at://did:plc:fixture/app.bsky.feed.post/mixedpage2unique';$entry['item']['post']['record']['text']='Second mixed page unique Bluesky source';}
      else{$entry['item']['id']='mixedpage2-'.$entry['item']['id'];$entry['item']['uri']='https://remote.test/users/author/statuses/'.$entry['item']['id'];$entry['item']['url']=$entry['item']['uri'];$entry['item']['content']='<p>Second mixed page unique Fediverse source</p>';}
     }unset($entry);
    }
    $fragments['bookmarks_'.$network][$mode][$page]=admin_bookmark_fragment_markup($network,$pageItems,$next,20,$more,0,$page===0,false);
    ob_start();
    echo '<div data-'.$network.'-favourites-fragment data-offset="'.$next.'" data-limit="20" data-has-more="'.($more?'1':'0').'"'.($network==='bsky'?' data-loaded="1"':'').'>';
    admin_render_library_network_items($pageItems,$network,'favourites');echo '</div>';
    $fragments['favourites_'.$network][$mode][$page]=ob_get_clean();
    $fragments['bookmarks'][$mode][$page]=admin_bookmark_mixed_markup($pageEntries,$next,20,$more,0,$page===0);
    $fragments['bookmarks_folder'][$mode][$page]=admin_bookmark_mixed_markup($pageEntries,$next,20,$more,5,$page===0);
   }
   // No emitted cards still consumes a source page; continuation must not loop.
   ap_db()->exec("INSERT INTO fixture_blocks VALUES(1,'actor','".$base['account']['uri']."')");
   $fragments['zero_card_more'][$mode]=admin_bookmark_fragment_markup('fedi',[$base],20,20,true,0,false,false);
   $fragments['zero_card_bookmarks'][$mode]=admin_bookmark_mixed_markup([['kind'=>'fedi','item'=>$base]],40,20,true,0,false);
   ob_start();echo '<div data-fedi-favourites-fragment data-offset="40" data-limit="20" data-has-more="1">';admin_render_library_network_items([$base],'fedi','favourites');echo '</div>';$fragments['zero_card_favourites_fedi'][$mode]=ob_get_clean();
   ap_db()->exec('DELETE FROM fixture_blocks');
   ap_db()->exec("INSERT INTO bsky_hide_dids VALUES(1,'did:plc:fixture')");unset($GLOBALS['ap_bsky_hide_did_cache']);
   ob_start();echo '<div data-bsky-favourites-fragment data-offset="40" data-limit="20" data-has-more="1" data-loaded="1">';admin_render_library_network_items([$bsky],'bsky','favourites');echo '</div>';$fragments['zero_card_favourites_bsky'][$mode]=ob_get_clean();
   ap_db()->exec('DELETE FROM bsky_hide_dids');unset($GLOBALS['ap_bsky_hide_did_cache']);
   $unique=array_replace($base,['id'=>'later-source-789','uri'=>'https://remote.test/users/later/statuses/789','url'=>'https://remote.test/@later/789','content'=>'<p>Later unique valid source</p>']);
   $fragments['later_bookmarks'][$mode]=admin_bookmark_mixed_markup([['kind'=>'fedi','item'=>$unique]],60,20,false,0,false);
   ob_start();echo '<div data-fedi-favourites-fragment data-offset="60" data-limit="20" data-has-more="0">';admin_render_library_network_items([$unique],'fedi','favourites');echo '</div>';$fragments['later_favourites_fedi'][$mode]=ob_get_clean();
   $uniqueBsky=$bsky;$uniqueBsky['post']['uri']='at://did:plc:fixture/app.bsky.feed.post/later789';$uniqueBsky['post']['record']['text']='Later unique Bluesky source';
   ob_start();echo '<div data-bsky-favourites-fragment data-offset="60" data-limit="20" data-has-more="0" data-loaded="1">';admin_render_library_network_items([$uniqueBsky],'bsky','favourites');echo '</div>';$fragments['later_favourites_bsky'][$mode]=ob_get_clean();
  }
 }
 file_put_contents($argv[1],json_encode(['coverage'=>'Actual PHP painters with bridge default off or explicit unsupported-endpoint fallback; native accepted HTML is a separate Rust/HTTP gate','cases'=>$export,'fragments'=>$fragments],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}
echo "library-native-callers: PASS ($count actual canonical complete markup/favourites/network/mixed DOM and IO comparisons; default off and enabled invalid-endpoint fallback; cursor/heading/empty/refresh shells retained)\n";
