#!/usr/bin/env php
<?php
/**
 * Pachli account refresh and the three failures it hit:
 * GET /api/v2/filters 404, GET /api/v1/trends/statuses 500, and a Mastodon
 * /system/accounts/avatars URL rewritten onto this host.
 */
declare(strict_types=1);

require __DIR__ . '/migration-reference-functions.php';

$checks = 0;
function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
}

$api = dirname(__DIR__);
$paperclip = 'https://mkultra.monster/system/accounts/avatars/111/939/142/189/797/780/original/3b513d04526c9dac.png';
$remotePaperclip = 'https://mastodon.social/system/accounts/avatars/111/939/142/189/797/780/original/3b513d04526c9dac.png';
$relative = '/system/accounts/avatars/111/939/142/189/797/780/original/3b513d04526c9dac.png';

reference_load($api . '/ap-db.php', [
    'ap_profile_sanitize_https_url',
    'ap_vaak_own_media_host',
    'ap_masto_system_media_path',
    'ap_account_media_is_broken_local',
    'ap_account_media_resolve',
    'ap_remote_actor_as2_media_url',
    'ap_account_media_repair_decision',
    'ap_account_media_cache_repair_decision',
    'ap_oauth_scopes_allow',
]);
reference_load($api . '/ap-masto-entities.php', [
    'ap_masto_https_media_url',
    'ap_masto_trend_status_seen_uris',
    'ap_masto_trend_status_world_metadata',
    'ap_masto_trend_ensure_bsky_viewer',
    'ap_masto_trend_status_owner_metadata',
]);
reference_load($api . '/ap-mastodon.php', [
    'ap_masto_bounded_int',
    'ap_masto_filter_contexts',
    'ap_masto_is_filters_path',
    'ap_masto_is_lists_path',
    'ap_masto_account_refresh_route',
    'ap_masto_filter_phrase_from_input',
    'ap_masto_filter_keyword',
    'ap_masto_filter_v1',
    'ap_masto_filter_v2',
]);

check(ap_masto_account_refresh_route('GET', '/api/v2/filters') === 'filters', 'v2 filter collection is an account-refresh route');
check(ap_masto_account_refresh_route('GET', '/api/v1/filters') === 'filters', 'v1 filter collection is an account-refresh route');
check(ap_masto_account_refresh_route('GET', '/api/v1/lists') === 'lists', 'list collection is an account-refresh route');
check(ap_masto_is_filters_path('/api/v2/filters'), 'v2 filters path');
check(ap_masto_is_filters_path('/api/v1/filters/4'), 'v1 filter id path');
check(ap_masto_is_filters_path('/api/v2/filters/4/keywords'), 'v2 keyword path');
check(ap_masto_is_lists_path('/api/v1/lists'), 'lists collection path');
check(ap_masto_is_lists_path('/api/v1/lists/3/accounts'), 'list accounts path');
check(ap_masto_is_lists_path('/api/v1/accounts/9/lists'), 'account lists path');
check(ap_oauth_scopes_allow('read write follow push', 'read:filters'), 'broad read token can read filters');
check(ap_oauth_scopes_allow('read write follow push', 'read:lists'), 'broad read token can read lists');
check(ap_oauth_scopes_allow('read:filters', 'read:filters'), 'exact filter scope');
check(!ap_oauth_scopes_allow('read:statuses', 'read:lists'), 'status scope does not cover lists');

$row = ['id' => 7, 'phrase' => 'spoiler'];
$v2 = ap_masto_filter_v2($row);
check($v2['id'] === '7' && $v2['title'] === 'spoiler', 'v2 filter id and title');
check($v2['filter_action'] === 'hide' && $v2['expires_at'] === null, 'v2 filter hides and does not expire');
check($v2['keywords'] === [['id' => '7', 'keyword' => 'spoiler', 'whole_word' => false]], 'v2 keyword');
check($v2['statuses'] === [], 'v2 filter has no status targets');
foreach (['home', 'notifications', 'public', 'thread', 'account'] as $context) {
    check(in_array($context, $v2['context'], true), 'v2 context ' . $context);
}
$v1 = ap_masto_filter_v1($row);
check($v1['phrase'] === 'spoiler' && $v1['whole_word'] === false && $v1['irreversible'] === true, 'v1 filter shape');
check(ap_masto_filter_phrase_from_input(['phrase' => 'alpha']) === 'alpha', 'v1 phrase');
check(ap_masto_filter_phrase_from_input(['title' => 'beta']) === 'beta', 'v2 title');
check(ap_masto_filter_phrase_from_input(['keywords_attributes' => [['keyword' => 'gamma']]]) === 'gamma', 'keyword attributes');
check(ap_masto_filter_phrase_from_input([]) === '', 'missing phrase stays empty');

check(ap_masto_bounded_int(['1'], 10, 1, 20) === 10, 'array limit does not cast');
check(ap_masto_bounded_int('nope', 10, 1, 20) === 10, 'bogus limit uses the default');
check(ap_masto_bounded_int('0', 10, 1, 20) === 1, 'zero limit clamps');
check(ap_masto_bounded_int('999', 0, 0, 40) === 40, 'huge offset clamps');
check(ap_masto_bounded_int(null, 10, 1, 20) === 10, 'missing limit uses the default');

$seen = ap_masto_trend_status_seen_uris([
    ['uri' => null],
    ['uri' => ''],
    ['uri' => 'https://social.example/users/a/statuses/1'],
    'not-a-row',
]);
check($seen === ['https://social.example/users/a/statuses/1' => true], 'null trend uri is skipped');

if (!function_exists('ap_masto_status_is_favourited')) {
    function ap_masto_status_is_favourited(string $id, int $owner, ?string $uri = null): bool
    {
        return false;
    }
}
if (!function_exists('ap_masto_status_is_bookmarked')) {
    function ap_masto_status_is_bookmarked(string $id, int $owner, ?string $uri = null): bool
    {
        return false;
    }
}
if (!function_exists('ap_masto_status_is_reblogged')) {
    function ap_masto_status_is_reblogged(string $id, int $owner): bool
    {
        return false;
    }
}
if (!function_exists('ap_db')) {
    function ap_db(): never
    {
        throw new RuntimeException('trends smoke has no database');
    }
}
check(!function_exists('ap_bsky_viewer_for_owner'), 'bluesky viewer helper starts unloaded');
$trend = ap_masto_trend_status_owner_metadata([
    'id' => '1',
    'uri' => 'at://did:plc:abc/app.bsky.feed.post/xyz',
    'favourited' => true,
    'bsky_post' => ['text' => 'hi', 'viewer' => ['like' => 'at://did:plc:abc/app.bsky.feed.like/1']],
], 4);
check(is_array($trend), 'owner metadata returns a status when the viewer helper was not loaded');
check(($trend['favourited'] ?? null) === false, 'owner metadata does not fatal into a 500');
check(!isset($trend['bsky_post']['viewer']['like']), 'failed viewer lookup does not keep a foreign like');

check(ap_account_media_is_broken_local($paperclip), 'pachli avatar url is a local system path');
check(ap_account_media_resolve($paperclip) === null, 'local system avatar is dropped');
check(ap_account_media_resolve($remotePaperclip) === $remotePaperclip, 'same path on the remote host is kept');
check(ap_account_media_resolve($relative, 'https://mastodon.social/users/alice') === $remotePaperclip, 'relative icon resolves against the actor host');
check(ap_account_media_resolve($relative, 'https://mkultra.monster/users/alice') === null, 'relative icon is not rewritten onto this host');
check(ap_account_media_resolve('https://cdn.example/a.png') === 'https://cdn.example/a.png', 'ordinary cdn avatar is kept');
check(ap_account_media_resolve('https://mkultra.monster/img/avatar/default.webp') === 'https://mkultra.monster/img/avatar/default.webp', 'default avatar is kept');
check(
    ap_masto_https_media_url($paperclip, 'https://mkultra.monster/img/avatar/default.webp') === 'https://mkultra.monster/img/avatar/default.webp',
    'account serializer falls back from the broken avatar'
);
check(
    ap_remote_actor_as2_media_url(['type' => 'Image', 'url' => $relative], 'https://mastodon.social/users/alice') === $remotePaperclip,
    'activitypub icon object resolves against the actor'
);
check(ap_remote_actor_as2_media_url($paperclip, 'https://mastodon.social/users/alice') === null, 'stored local paperclip url is not an icon');
$replaced = ap_account_media_repair_decision($paperclip, 'https://mastodon.social/users/alice', json_encode(['icon' => 'https://cdn.example/real.png']), 'icon');
check($replaced['action'] === 'replace' && $replaced['replacement'] === 'https://cdn.example/real.png', 'repair can restore a remote icon from the profile document');
$cleared = ap_account_media_repair_decision($paperclip, 'https://mastodon.social/users/alice', null, 'icon');
check($cleared['action'] === 'clear' && $cleared['replacement'] === null, 'repair clears a broken icon with no replacement');
$kept = ap_account_media_repair_decision('https://cdn.example/a.png', 'https://mastodon.social/users/alice', null, 'icon');
check($kept['action'] === 'keep', 'repair leaves a real remote icon alone');
$cacheReplace = ap_account_media_cache_repair_decision($paperclip, 'https://cdn.example/real.png');
check($cacheReplace['action'] === 'replace' && $cacheReplace['replacement'] === 'https://cdn.example/real.png', 'cache public url can fall back to its source');
$cacheDelete = ap_account_media_cache_repair_decision($paperclip, $paperclip);
check($cacheDelete['action'] === 'delete', 'cache row with no remote source is removed');
check(is_file($api . '/bin/repair-remote-avatar-urls.php'), 'repair script ships in api/bin');

echo "ok {$GLOBALS['checks']}\n";
