<?php
declare(strict_types=1);
/**
 * Redis client bounds and per-user cache generations.
 * No Redis server: key builders take an explicit generation, and the SCAN
 * gate is a pure clock check. A failed read must not alias generation 0.
 */
$api = dirname(__DIR__);
require $api . '/ap-redis.php';
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}

$checks = 0;
function redis_gen_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

$timeout = ap_redis_read_timeout();
redis_gen_check($timeout > 0.0 && $timeout <= 0.5, 'read timeout is positive and at most half a second');
redis_gen_check(PHP_SAPI !== 'cli' || abs($timeout - 0.40) < 0.001, 'CLI workers wait 0.40s, not forever and not the 80ms web budget');

redis_gen_check(ap_redis_generation_token('0') === '0', 'generation 0 stays 0');
redis_gen_check(ap_redis_generation_token('18') === '18', 'a decimal generation is kept');
redis_gen_check(ap_redis_generation_token('') === 'x', 'a failed generation read does not alias 0');
redis_gen_check(ap_redis_generation_token('nope') === 'x', 'a corrupt generation does not alias 0');

redis_gen_check(ap_redis_ranked_key('home', '0') === 'vaak:timeline:ranked:v2:0:' . hash('sha256', 'home'), 'ranked key includes generation 0');
redis_gen_check(ap_redis_ranked_key('home', '3') !== ap_redis_ranked_key('home', '4'), 'a ranked generation bump changes the key');
$local = 'v13_local_u1_follows';
$cold = ap_redis_ranked_read_keys($local, '0');
redis_gen_check($cold === [ap_redis_ranked_key($local, '0'), ap_redis_ranked_legacy_key($local)], 'generation 0 still reads the pre-epoch local ranked list');
redis_gen_check(!str_contains($cold[1], ':0:'), 'the legacy local key has no generation segment');
$bumped = ap_redis_ranked_read_keys($local, '1');
redis_gen_check($bumped === [ap_redis_ranked_key($local, '1')], 'a real bump does not serve the retired local list');
redis_gen_check(str_starts_with(ap_redis_ranked_key('home', ''), 'vaak:timeline:ranked:v2:x:'), 'an unreadable ranked generation misses');

redis_gen_check(
    ap_fragment_tl_key(7, 'abc') === 'vaak:fragment:tl:v1:7:0:abc' || str_starts_with(ap_fragment_tl_key(7, 'abc'), 'vaak:fragment:tl:v1:7:'),
    'timeline fragment key is per owner'
);
// Force the token path without a server by passing generations into the library key.
$libA = ap_redis_library_html_key('favourites_bsky', 4, 'offset=0', '1', '0');
$libB = ap_redis_library_html_key('favourites_bsky', 4, 'offset=0', '2', '0');
$libKind = ap_redis_library_html_key('favourites_bsky', 4, 'offset=0', '1', '9');
$libOther = ap_redis_library_html_key('bookmarks_bsky', 4, 'offset=0', '1', '0');
redis_gen_check($libA !== $libB, 'an owner library generation bump changes every kind');
redis_gen_check($libA !== $libKind, 'a kind generation bump changes only that kind');
redis_gen_check(str_starts_with($libOther, 'vaak:fragment:lib:v1:bookmarks_bsky:4:1:0:'), 'another kind keeps its own generation');

$unreadA = ap_notification_unread_redis_key(3, 80, '100', '1');
$unreadB = ap_notification_unread_redis_key(3, 80, '100', '2');
$shadow = ap_notification_unread_redis_key(3, 80, '100', '2', true);
redis_gen_check($unreadA !== $unreadB, 'an unread generation bump changes the badge key');
redis_gen_check(str_starts_with($unreadB, 'vaak:notifications:v1:unread:3:2:80:'), 'live badge key carries the generation');
redis_gen_check(str_starts_with($shadow, 'vaak:shadow:notifications:v1:unread:3:2:80:'), 'shadow badge key carries the same generation');
redis_gen_check(ap_notification_unread_redis_key(3, 80, '100', '') !== $unreadA, 'a failed unread read does not reuse generation 0');

[$server, $user] = ap_notification_list_epoch_parts('4:9');
redis_gen_check($server === '4' && $user === '9', 'list epoch splits into server and owner');
[$server, $user] = ap_notification_list_epoch_parts('');
redis_gen_check($server === 'x' && $user === 'x', 'a failed list epoch does not alias 0:0');
[$server, $user] = ap_notification_list_epoch_parts('0:0');
redis_gen_check($server === '0' && $user === '0', 'a missing list epoch is 0:0');

reference_load($api . '/ap-masto-entities.php', ['ap_masto_notifications_list_redis_key']);
$older = ap_masto_notifications_list_redis_key(1, 30, null, null, ['mention'], [], '2:7');
$newer = ap_masto_notifications_list_redis_key(1, 30, null, null, ['mention'], [], '2:8');
redis_gen_check(str_starts_with($older, 'vaak:notifications:v4:1:2:7:'), 'notification pages stay in the v4 prefix');
redis_gen_check($older !== $newer, 'a list epoch bump changes the page key without a SCAN');

$t = 1_000_000.0;
redis_gen_check(ap_redis_pattern_scan_allowed('vaak:test:*', $t), 'the first SCAN in a quiet minute is allowed');
redis_gen_check(!ap_redis_pattern_scan_allowed('vaak:test:*', $t + 1), 'the same pattern cannot SCAN again immediately');
redis_gen_check(!ap_redis_pattern_scan_allowed('vaak:other:*', $t + 2), 'a second pattern cannot SCAN inside the global gap');
redis_gen_check(ap_redis_pattern_scan_allowed('vaak:test:*', $t + 61), 'a pattern may SCAN again after a minute');
redis_gen_check(!ap_redis_pattern_scan_allowed('', $t + 120), 'an empty pattern is never scanned');

ap_redis_discard_client('cache');
ap_redis_discard_client('queue');
redis_gen_check(true, 'discarding a client that was never opened does not throw');

fwrite(STDOUT, "redis-generation-smoke: {$checks} checks ok\n");
