<?php
declare(strict_types=1);
/**
 * Blocking a Bluesky account ends the follow: the follow record is deleted
 * from the owner's repo and the cached edge goes; a failed delete keeps the
 * edge. Own block lists count as blocks, subscribed ones do not. Home's PHP
 * visibility check hides a handle-keyed or thin card whose author's DID is in
 * the Bluesky hide set. No network; stubs only.
 */
$api = getenv('VAAK_BSKY_BLOCK_FOLLOW_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');

$GLOBALS['edges'] = ['did:plc:alice' => 'at://did:plc:self/app.bsky.graph.follow/edge1', 'did:plc:bob' => 'at://did:plc:self/app.bsky.graph.follow/bob1'];
$GLOBALS['deleted'] = [];
$GLOBALS['fail_delete'] = false;
function ap_bsky_session_row(int $owner): ?array { return $owner === 1 ? ['did' => 'did:plc:self'] : null; }
function ap_bsky_graph_sync_get(int $o, string $k, string $d): ?array { return isset($GLOBALS['edges'][$d]) ? ['bsky_uri' => $GLOBALS['edges'][$d]] : null; }
function ap_bsky_graph_sync_delete(int $o, string $k, string $d): void { unset($GLOBALS['edges'][$d]); }
function ap_bsky_actor_viewer_cache_update(int $o, string $d, ?string $u): void {}
function ap_bsky_tl_cache_clear_owner(int $o): void {}
function ap_bsky_delete_record_uri(int $o, string $uri): array
{
    if ($GLOBALS['fail_delete']) return ['ok' => false, 'error' => 'boom'];
    $GLOBALS['deleted'][] = $uri;
    return ['ok' => true];
}
function ap_bsky_account_xrpc(int $o, string $nsid, string $m = 'GET', ?array $q = null, ?array $b = null): array
{
    return ['ok' => true, 'json' => ['records' => [
        ['uri' => 'at://did:plc:self/app.bsky.graph.follow/a2', 'value' => ['subject' => 'did:plc:alice']],
        ['uri' => 'at://did:plc:self/app.bsky.graph.follow/c1', 'value' => ['subject' => 'did:plc:carol']],
    ]]];
}
// Visibility stubs: only the Bluesky hide set matters here.
function ap_bsky_hide_did_set(int $o): array { return ['did:plc:alice' => true]; }
function ap_notifications_bsky_handle_did_map(array $h): array { return in_array('subdirt.example', $h, true) ? ['subdirt.example' => 'did:plc:alice'] : []; }

reference_load($api . '/ap-bsky.php', ['ap_bsky_follow_record_uris', 'ap_bsky_list_is_own', 'ap_bsky_drop_follow_for_block']);
reference_load($api . '/ap-visibility.php', ['ap_visibility_actor_hidden', 'ap_visibility_bsky_profile_did', 'ap_visibility_bsky_post_author', 'ap_visibility_status_hidden']);

$checks = 0;
function check(bool $ok, string $msg): void { if (!$ok) throw new RuntimeException($msg); $GLOBALS['checks']++; }

check(ap_bsky_list_is_own(1, 'at://did:plc:self/app.bsky.graph.list/x'), 'own list');
check(!ap_bsky_list_is_own(1, 'at://did:plc:other/app.bsky.graph.list/x'), 'subscribed list is not own');

$repo = ap_bsky_follow_record_uris(1);
check(($repo['did:plc:alice'] ?? []) === ['at://did:plc:self/app.bsky.graph.follow/a2'], 'repo follows keyed by subject');

$GLOBALS['fail_delete'] = true;
$r = ap_bsky_drop_follow_for_block(1, 'did:plc:bob');
check(empty($r['ok']) && isset($GLOBALS['edges']['did:plc:bob']), 'failed delete keeps the edge');
$GLOBALS['fail_delete'] = false;

$r = ap_bsky_drop_follow_for_block(1, 'did:plc:alice', $repo);
check(!empty($r['ok']) && $r['deleted'] === 2, 'repo record and cached edge record both deleted');
check(!isset($GLOBALS['edges']['did:plc:alice']), 'edge removed');
check(in_array('at://did:plc:self/app.bsky.graph.follow/a2', $GLOBALS['deleted'], true), 'repo-only record deleted');

$r = ap_bsky_drop_follow_for_block(1, 'did:plc:nobody');
check(!empty($r['ok']) && $r['deleted'] === 0, 'nothing followed is a no-op');

// Home PHP paint: handle-keyed account, thin boost, Bridgy convert quote.
$card = ['account' => ['uri' => 'https://bsky.app/profile/subdirt.example'], 'content' => ''];
check(ap_visibility_status_hidden($card, 1), 'handle URL resolves to a hidden DID');
$boost = ['account' => ['uri' => 'https://mkultra.monster/users/cmdr_nova'], 'content' => '',
    'reblog' => ['url' => 'https://bsky.app/profile/subdirt.example/post/3abc', 'account' => [], 'content' => '']];
check(ap_visibility_status_hidden($boost, 1), 'thin boost of a blocked author hidden');
$quote = ['account' => ['uri' => 'https://masto.example/users/friend'], 'content' => '',
    'quote' => ['quoted_status' => ['uri' => 'https://bsky.brid.gy/convert/ap/at://did:plc:alice/app.bsky.feed.post/1', 'account' => [], 'content' => '']]];
check(ap_visibility_status_hidden($quote, 1), 'Bridgy quote of a blocked author hidden');
$other = ['account' => ['uri' => 'https://bsky.app/profile/friend.example'], 'url' => 'https://bsky.app/profile/friend.example/post/1', 'content' => ''];
check(!ap_visibility_status_hidden($other, 1), 'unblocked author stays');

echo "bsky block follow smoke: {$checks} checks ok\n";
