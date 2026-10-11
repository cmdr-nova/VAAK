<?php
declare(strict_types=1);
/** Complete profile presentation only; current SQL/source grants live in Rust. */
require_once __DIR__.'/ap-native-preview-state.php';
/** Server request provenance only: never accept PostView viewer flags here. */
function ap_profile_complete_bsky_provenance(int $owner): array
{
    $session = function_exists('ap_bsky_session_row') ? ap_bsky_session_row($owner) : null;
    $did = is_array($session) ? trim((string)($session['did'] ?? '')) : '';
    $bookmarks = [];
    foreach (($GLOBALS['vaak_bsky_bookmark_owner_state'][$owner] ?? []) as $uri => $state) {
        if (!is_string($uri) || !is_array($state) || ($state['did'] ?? null) !== $did
            || $did === '' || !is_bool($state['bookmarked'] ?? null)
            || !preg_match('~^at://[^/]+/app\.bsky\.feed\.post/[^/?#]+$~', $uri)) continue;
        $bookmarks[] = ['uri'=>$uri, 'bookmarked'=>$state['bookmarked']];
    }
    return ['enabled'=>function_exists('ap_bsky_tab_enabled') && ap_bsky_tab_enabled(), 'did'=>$did, 'bookmarks'=>$bookmarks];
}
function ap_profile_complete_native_enabled(): bool
{
    return getenv('VAAK_PROFILE_COMPLETE_NATIVE') === '1'
        && strlen((string) getenv('VAAK_PROFILE_COMPLETE_SECRET')) >= 32;
}
/** Validate every field before publishing any HTML or either preview global. */
function ap_profile_complete_reply(mixed $reply, array $binding, int $statusInitial, int $quoteInitial): ?array
{
    if (!is_array($reply) || !ap_native_preview_exact_keys($reply, ['protocol_version','ok','request_binding','page','cards','status_preview_state','quote_preview_state'])
        || $reply['protocol_version'] !== 1 || $reply['ok'] !== true || $reply['request_binding'] !== $binding
        || !is_array($reply['page']) || !ap_native_preview_exact_keys($reply['page'], ['source_count','next_offset','has_more'])
        || !is_int($reply['page']['source_count']) || $reply['page']['source_count'] < 0 || $reply['page']['source_count'] > $binding['limit']
        || !is_int($reply['page']['next_offset']) || $reply['page']['next_offset'] !== $binding['offset'] + $reply['page']['source_count']
        || !is_bool($reply['page']['has_more']) || ($reply['page']['has_more'] && $reply['page']['source_count'] !== $binding['limit'])
        || !is_array($reply['cards']) || !array_is_list($reply['cards']) || count($reply['cards']) > $reply['page']['source_count']
        || !ap_native_preview_state_valid($reply['status_preview_state'], $statusInitial, $reply['page']['source_count'])
        || !ap_native_preview_state_valid($reply['quote_preview_state'], $quoteInitial, $reply['page']['source_count'] * 16)) return null;
    $last = -1; $html = '';
    foreach ($reply['cards'] as $card) {
        if (!is_array($card) || !ap_native_preview_exact_keys($card, ['source_index','html'])
            || !is_int($card['source_index']) || $card['source_index'] <= $last || $card['source_index'] >= $reply['page']['source_count']
            || !is_string($card['html'])) return null;
        $last = $card['source_index']; $html .= $card['html'];
        if (strlen($html) > 8 * 1024 * 1024) return null;
    }
    // Untouched raw globals (including absence and non-int values) stay intact.
    ap_native_preview_commit_state($reply['status_preview_state']);
    if ($reply['quote_preview_state']['attempts'] > 0) $GLOBALS['admin_quote_link_preview_budget'] = $reply['quote_preview_state']['remaining'];
    return ['html'=>$html, 'has_more'=>$reply['page']['has_more'], 'next_offset'=>$reply['page']['next_offset'], 'complete_native'=>true, 'source_count'=>$reply['page']['source_count']];
}
function ap_profile_complete_fetch(string $actor, string $tab, int $limit, int $offset, int $owner, string $mode='append'): ?array
{
    if (!ap_profile_complete_native_enabled()) return null;
    $actor = rtrim(trim($actor), '/');
    if (!preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $actor, $match)) return null;
    if (!in_array($tab, ['posts','replies','boosts','media'], true) || !in_array($mode, ['first_paint','append'], true)) return null;
    $guest = !empty($GLOBALS['vaak_guest_profile']);
    if (!$guest) {
        if (!function_exists('ap_auth_current_user') || !function_exists('ap_auth_csrf_token')) return null;
        $user=ap_auth_current_user();
        if (!is_array($user) || !empty($user['is_guest']) || $owner < 1 || (int)($user['id']??0)!==$owner) return null;
        $csrf=ap_auth_csrf_token(); if (!is_string($csrf)||$csrf==='') return null;
    }
    $binding = ['actor_key'=>strtolower($match[1]), 'actor'=>$actor, 'tab'=>$tab, 'mode'=>$mode, 'offset'=>$mode==='first_paint'?0:max(0,$offset), 'limit'=>$mode==='first_paint'?12:max(10,min(50,$limit))];
    $status = ap_native_preview_effective_budget(); $rawQuote = $GLOBALS['admin_quote_link_preview_budget'] ?? null; $quote = is_int($rawQuote) ? $rawQuote : 2;
    if ($status < 0 || $status > 100) return null;
    $request = ['protocol_version'=>1, 'viewer'=>$guest?['kind'=>'guest']:['kind'=>'authenticated','owner_id'=>$owner,'csrf'=>$csrf]] + $binding + ['lazy_media'=>!empty($GLOBALS['admin_bookmark_lazy_media']),'status_preview_budget'=>$status,'quote_preview_budget'=>$quote];
    if (!$guest) $request['viewer']['bsky'] = ap_profile_complete_bsky_provenance($owner);
    // Forward the existing request deadline; null preserves legacy per-call timing.
    // An absolute timestamp includes PHP preprocessing and loopback transit time.
    $bskyRemaining = function_exists('ap_bsky_budget_remaining') ? ap_bsky_budget_remaining() : null;
    if ($bskyRemaining !== null && (!is_float($bskyRemaining) && !is_int($bskyRemaining) || !is_finite((float)$bskyRemaining))) return null;
    $request['bsky_budget_deadline'] = $bskyRemaining === null ? null : microtime(true) + $bskyRemaining;
    try {$body=json_encode($request, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);} catch (Throwable) {return null;}
    $base=getenv('VAAK_SHADOW_HTTP');$base=is_string($base)&&trim($base)!==''?rtrim(trim($base),'/'):'http://127.0.0.1:8787';
    $host=parse_url($base,PHP_URL_HOST);if (!is_string($host)||!in_array(strtolower($host),['127.0.0.1','localhost','::1','[::1]'],true)||!function_exists('curl_init')) return null;
    $ch=curl_init($base.'/internal/profile/page');if($ch===false)return null;
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>120,CURLOPT_TIMEOUT_MS=>8000,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-VAAK-Profile-Token: '.getenv('VAAK_PROFILE_COMPLETE_SECRET')]]);
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($code!==200||!is_string($raw)||strlen($raw)>8*1024*1024)return null;
    try{$reply=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}catch(Throwable){return null;}
    return ap_profile_complete_reply($reply,$binding,$status,$quote);
}

/** Full and shell use the same inner profile fragment. Publish both raw state
 * globals only after the complete header/body/binding has validated. */
function ap_profile_full_fragment_valid(array $reply): bool
{
    $fragment=$reply['fragment']??null; $page=$reply['source_page']??null;
    if (!is_array($fragment) || !ap_native_preview_exact_keys($fragment,['kind','header_bytes','content_bytes','card_ordinals'])
        || !is_array($page) || !ap_native_preview_exact_keys($page,['source_count','next_offset','has_more'])
        || !is_int($fragment['header_bytes']) || $fragment['header_bytes']<=0 || !is_int($fragment['content_bytes']) || $fragment['content_bytes']<0
        || $fragment['header_bytes']>strlen($reply['html']) || $fragment['content_bytes']!==strlen($reply['html'])-$fragment['header_bytes']
        || !is_array($fragment['card_ordinals']) || !array_is_list($fragment['card_ordinals'])
        || !is_int($page['source_count']) || $page['source_count']!==$reply['source_count'] || !is_int($page['next_offset']) || !is_bool($page['has_more'])) return false;
    $previous=-1; foreach ($fragment['card_ordinals'] as $ordinal) {
        if (!is_int($ordinal) || $ordinal<0 || $ordinal<=$previous || $ordinal>=$reply['source_count']) return false;
        $previous=$ordinal;
    }
    if ($fragment['kind']==='timeline') return in_array($reply['effective_tab'],['posts','replies','boosts','media'],true)
        && $fragment['content_bytes']>0 && $page['next_offset']===$reply['source_count'] && (!$page['has_more'] || $reply['source_count']===12);
    if ($fragment['kind']==='secondary') return in_array($reply['effective_tab'],['pinned','asks'],true) && $fragment['content_bytes']>0 && $page['next_offset']===$reply['source_count'] && !$page['has_more'];
    if ($fragment['kind']==='featured') return $reply['effective_tab']==='featured' && $fragment['content_bytes']>0 && $page['next_offset']===$reply['source_count'] && !$page['has_more'];
    if ($fragment['kind']==='blog') return $reply['effective_tab']==='blog' && $fragment['content_bytes']>0 && $page===['source_count'=>0,'next_offset'=>0,'has_more'=>false] && $fragment['card_ordinals']===[];
    if ($fragment['kind']==='blocked') return $fragment['content_bytes']===0 && $page===['source_count'=>0,'next_offset'=>0,'has_more'=>false] && $fragment['card_ordinals']===[];
    return false;
}
function ap_profile_full_reply(mixed $reply, array $binding, int $statusInitial, int $quoteInitial): ?array
{
    if (!is_array($reply) || !ap_native_preview_exact_keys($reply, ['protocol_version','ok','request_binding','source_count','effective_tab','html','fragment','source_page','status_preview_state','quote_preview_state'])
        || $reply['protocol_version'] !== 1 || $reply['ok'] !== true || $reply['request_binding'] !== $binding
        || !is_int($reply['source_count']) || $reply['source_count'] < 0
        || !is_string($reply['effective_tab']) || !in_array($reply['effective_tab'], ['posts','replies','boosts','media','pinned','asks','featured','blog'], true)
        || $reply['source_count'] > ($reply['effective_tab'] === 'asks' ? 200 : ($reply['effective_tab'] === 'featured' ? 40 : 12))
        || !is_string($reply['html']) || $reply['html'] === '' || strlen($reply['html']) > 8 * 1024 * 1024
        || !ap_profile_full_fragment_valid($reply)
        || !ap_native_preview_state_valid($reply['status_preview_state'], $statusInitial, $reply['source_count'])
        || !ap_native_preview_state_valid($reply['quote_preview_state'], $quoteInitial, $reply['source_count'] * 16)) return null;
    ap_native_preview_commit_state($reply['status_preview_state']);
    if ($reply['quote_preview_state']['attempts'] > 0) $GLOBALS['admin_quote_link_preview_budget'] = $reply['quote_preview_state']['remaining'];
    return ['html'=>$reply['html'], 'effective_tab'=>$reply['effective_tab'], 'source_count'=>$reply['source_count'], 'complete_native'=>true];
}
function ap_profile_full_fetch(string $actor, string $tab, string $from, string $post, bool $shell, int $owner): ?array
{
    if (!ap_profile_complete_native_enabled()) return null;
    $actor = rtrim(trim($actor), '/');
    if (!preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $actor, $match)) return null;
    $tab = strtolower(trim($tab)); if (!in_array($tab, ['posts','replies','boosts','media','pinned','asks','featured','blog'], true)) $tab = 'posts';
    $from = preg_replace('/[^a-z_]/', '', $from) ?: '';
    $post = trim($post); if (strlen($post) > 256) return null;
    $guest = !empty($GLOBALS['vaak_guest_profile']);
    if (!$guest) {
        if (!function_exists('ap_auth_current_user') || !function_exists('ap_auth_csrf_token')) return null;
        $user = ap_auth_current_user();
        if (!is_array($user) || !empty($user['is_guest']) || $owner < 1 || (int)($user['id'] ?? 0) !== $owner) return null;
        $csrf = ap_auth_csrf_token(); if (!is_string($csrf) || $csrf === '') return null;
    }
    $binding = ['actor_key'=>strtolower($match[1]), 'actor'=>$actor, 'tab'=>$tab, 'from'=>$from, 'post'=>$post, 'shell'=>$shell];
    $status = ap_native_preview_effective_budget(); $rawQuote = $GLOBALS['admin_quote_link_preview_budget'] ?? null; $quote = is_int($rawQuote) ? $rawQuote : 2;
    if ($status < 0 || $status > 100) return null;
    $request = ['protocol_version'=>1, 'viewer'=>$guest ? ['kind'=>'guest'] : ['kind'=>'authenticated','owner_id'=>$owner,'csrf'=>$csrf]] + $binding + ['lazy_media'=>!empty($GLOBALS['admin_bookmark_lazy_media']), 'status_preview_budget'=>$status, 'quote_preview_budget'=>$quote];
    if (!$guest) $request['viewer']['bsky'] = ap_profile_complete_bsky_provenance($owner);
    $remaining = function_exists('ap_bsky_budget_remaining') ? ap_bsky_budget_remaining() : null;
    if ($remaining !== null && (!is_float($remaining) && !is_int($remaining) || !is_finite((float)$remaining))) return null;
    $request['bsky_budget_deadline'] = $remaining === null ? null : microtime(true) + $remaining;
    try { $body = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); } catch (Throwable) { return null; }
    $base = getenv('VAAK_SHADOW_HTTP'); $base = is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/') : 'http://127.0.0.1:8787';
    $host = parse_url($base, PHP_URL_HOST);
    if (!is_string($host) || !in_array(strtolower($host), ['127.0.0.1','localhost','::1','[::1]'], true) || !function_exists('curl_init')) return null;
    $ch = curl_init($base . '/internal/profile/full'); if ($ch === false) return null;
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT_MS=>120, CURLOPT_TIMEOUT_MS=>8000, CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'X-VAAK-Profile-Token: '.getenv('VAAK_PROFILE_COMPLETE_SECRET')]]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($code !== 200 || !is_string($raw) || strlen($raw) > 8 * 1024 * 1024) return null;
    try { $reply = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { return null; }
    return ap_profile_full_reply($reply, $binding, $status, $quote);
}
