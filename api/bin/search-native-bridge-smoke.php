<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/ap-search-native.php';
// These are transport-fixture source mapping boundaries, not a PHP presentation oracle.
function admin_html_to_plain(string $text): string { return strip_tags($text); }
function admin_quote_opts_from_status(array $status,string $url): array {
    return ['text'=>trim(admin_html_to_plain((string)($status['content']??''))),
        'media'=>is_array($status['media_attachments']??null)?$status['media_attachments']:[],
        'card'=>is_array($status['card']??null)?$status['card']:null];
}
function ap_auth_current_user(): ?array { return $GLOBALS['test_user'] ?? ['id'=>1]; }
function ap_auth_csrf_token(): string { return 'current-canonical-session-csrf'; }
function ap_bsky_tab_enabled(): bool { return true; }
function ap_bsky_session_row(int $owner): array { return ['did'=>'did:plc:viewer']; }
function ap_bsky_bookmark_owner_state(string $uri,int $owner): ?bool { return $uri==='at://did:plc:author/app.bsky.feed.post/child'; }
function ap_masto_lookup_status_by_object_url(string $url,int $depth,bool $fetch,bool $hidden): ?array {
    if ($depth!==0 || $fetch || !$hidden) throw new RuntimeException('Unexpected source IO');
    $GLOBALS['lookups'][]=$url;
    return $url==='https://quote.test/note' ? ['uri'=>$url,'content'=>'Complete cached child','account'=>['uri'=>'https://quote.test/author']] : null;
}
$checks=0;
function bridge_check(bool $value,string $message): void { global $checks; if(!$value) throw new RuntimeException($message); $checks++; }
foreach(['http://127.0.0.1:18873/internal/search/render','http://[::1]:18873/internal/search/render'] as $url) bridge_check(ap_search_native_url_allowed($url),'loopback allowed');
foreach(['https://127.0.0.1/internal/search/render','http://localhost/internal/search/render','http://127.0.0.1@evil.test/internal/search/render','http://127.0.0.1/internal/search/render?q=1','http://127.0.0.1/internal/search/render#x','http://127.0.0.1/internal/library/render','http://user@127.0.0.1/internal/search/render'] as $url) bridge_check(!ap_search_native_url_allowed($url),'unsafe URL rejected');
$counts=['accounts'=>1,'hashtags'=>1,'statuses'=>3];
$reply=['ok'=>true,'protocol_version'=>1,'status_preview_state'=>['initial'=>3,'remaining'=>3,'attempts'=>0],'source_counts'=>$counts,'accounts'=>['A'],'hashtags'=>[''],'statuses'=>[['source_index'=>0,'html'=>'S0'],['source_index'=>2,'html'=>'S2']]];
bridge_check(ap_search_native_validate_reply($reply,$counts)===['accounts'=>'A','hashtags'=>'','statuses'=>'S0S2'],'ordered original slots');
foreach([
 array_replace($reply,['ok'=>1]),array_replace($reply,['protocol_version'=>2]),array_replace($reply,['status_preview_state'=>['initial'=>3,'remaining'=>3,'attempts'=>1]]),array_replace($reply,['accounts'=>[]]),array_replace($reply,['source_counts'=>array_replace($counts,['statuses'=>'3'])]),
 array_replace($reply,['statuses'=>[['source_index'=>2,'html'=>'x'],['source_index'=>0,'html'=>'x']]]),
 array_replace($reply,['statuses'=>[['source_index'=>0,'html'=>'x'],['source_index'=>0,'html'=>'x']]]),
 array_replace($reply,['statuses'=>[['source_index'=>3,'html'=>'x']]]),array_replace($reply,['statuses'=>[['source_index'=>-1,'html'=>'x']]]),
 array_replace($reply,['statuses'=>[['source_index'=>0,'html'=>false]]]),array_replace($reply,['unexpected'=>true]),
 array_replace($reply,['statuses'=>[['source_index'=>0,'html'=>'x','capabilities'=>[]]]])
] as $bad) bridge_check(ap_search_native_validate_reply($bad,$counts)===null,'entire bad reply rejected');
bridge_check(ap_search_native_validate_reply(array_replace($reply,['statuses'=>[]]),$counts)!==null,'successful all-denied status window');
$source=['uri'=>'https://parent.test/note','quote'=>['state'=>'pending'],'quote_url'=>'https://quote.test/note'];
$lateFallback=false;
try { ap_search_native_assemble_status($source); } catch (RuntimeException $error) { $lateFallback=true; }
bridge_check($lateFallback&&!isset($source['quote']['quoted_status'])&&empty($GLOBALS['lookups']),'late quote falls back before lookup, transport or budget effects');
$complete=$source;$complete['quote']['quoted_status']=['uri'=>'https://quote.test/note','content'=>'Complete cached child','account'=>['uri'=>'https://quote.test/author'],'card'=>['url'=>'https://quote.test/card']];
bridge_check(ap_search_native_assemble_status($complete)===$complete,'already complete canonical quote retained');
$dynamic=$complete;unset($dynamic['quote']['quoted_status']['card']);$previewFallback=false;
try { ap_search_native_assemble_status($dynamic); } catch (RuntimeException $error) { $previewFallback=true; }
bridge_check($previewFallback,'complete plain quote dynamic preview stays PHP');
$mediaQuote=$dynamic;$mediaQuote['quote']['quoted_status']['media_attachments']=[['type'=>'image','url'=>'https://quote.test/image.png']];
bridge_check(ap_search_native_assemble_status($mediaQuote)===$mediaQuote,'complete media quote stays eligible');
$nested=[['reblog'=>['quote'=>['quoted_status'=>['bsky_post'=>['post'=>['uri'=>'at://did:plc:author/app.bsky.feed.post/child']]]]]]];
bridge_check(ap_search_native_bsky_bookmark_states($nested,1)===[['uri'=>'at://did:plc:author/app.bsky.feed.post/child','did'=>'did:plc:viewer','bookmarked'=>true]],'recursive current owner provenance');
$mode=getenv('SEARCH_TRANSPORT_EXPECT');
if($mode!==false){
    bridge_check(extension_loaded('curl'),'real cURL required');
    $result=ap_search_native_render_batch([['uri'=>'https://remote.test/author']],[['name'=>'rust']],[$complete]);
    bridge_check(ap_search_native_render_batch([],[],[$source])===null&&empty($GLOBALS['lookups']),'late quote avoids native transport and preserves PHP recovery');
    bridge_check($mode==='success' ? $result===['accounts'=>'transport-account','hashtags'=>'transport-tag','statuses'=>'transport-status'] : $result===null,'real transport result');
    $GLOBALS['test_user']=['id'=>1,'is_guest'=>true];bridge_check(ap_search_native_render_batch([],[],[])===null,'guest rejected');
    $GLOBALS['test_user']=['id'=>1];
    bridge_check(ap_search_native_render_batch([['display_name'=>"\xff"]],[],[])===null,'invalid UTF8 source fallback');
    bridge_check(ap_search_native_render_batch([],[],[['content'=>str_repeat('x',2*1024*1024)]])===null,'request cap');
    bridge_check(ap_search_native_render_batch(array_fill(0,41,['uri'=>'x']),[],[])===null,'section cap');
}
echo "search-native-bridge: PASS ($checks controls; transport binding only, no native presentation claim)\n";
