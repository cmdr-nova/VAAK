<?php
declare(strict_types=1);
/**
 * Complete-profile refusal cache. No Redis server, worker, or post content.
 * A 422 reason code is stored for a short TTL and the next PHP call skips Rust.
 */
$GLOBALS['profile_complete_cache'] = [];
$GLOBALS['profile_complete_cache_ttl'] = [];
function ap_redis_json_get(string $key): ?array
{
    return $GLOBALS['profile_complete_cache'][$key] ?? null;
}
function ap_redis_json_set(string $key, array $value, int $ttlSeconds): bool
{
    if ($ttlSeconds < 1) return false;
    $GLOBALS['profile_complete_cache'][$key] = $value;
    $GLOBALS['profile_complete_cache_ttl'][$key] = $ttlSeconds;
    return true;
}
require dirname(__DIR__) . '/ap-profile-native.php';

function fallback_fail(string $message): never
{
    throw new RuntimeException($message);
}

if (ap_profile_complete_timeout_ms() !== 2000) fallback_fail('default timeout is 2000ms');
putenv('VAAK_PROFILE_COMPLETE_TIMEOUT_MS=9000');
if (ap_profile_complete_timeout_ms() !== 8000) fallback_fail('timeout clamps high');
putenv('VAAK_PROFILE_COMPLETE_TIMEOUT_MS=50');
if (ap_profile_complete_timeout_ms() !== 100) fallback_fail('timeout clamps low');
putenv('VAAK_PROFILE_COMPLETE_TIMEOUT_MS=');
if (ap_profile_complete_timeout_ms() !== 2000) fallback_fail('empty timeout uses 2000ms');
if (ap_profile_complete_fallback_ttl() !== 30) fallback_fail('default fallback ttl is 30s');
putenv('VAAK_PROFILE_COMPLETE_FALLBACK_TTL=1');
if (ap_profile_complete_fallback_ttl() !== 5) fallback_fail('ttl clamps low');
putenv('VAAK_PROFILE_COMPLETE_FALLBACK_TTL=');

$key = ap_profile_complete_fallback_key('Cmdr_Nova', 'page', 'posts');
if ($key !== 'vaak:profile-complete-fallback:v1:page:cmdr_nova:posts') fallback_fail('fallback key shape');
$secretBody = 'quoted post body must stay out of the cache';
ap_profile_complete_observe_transport('cmdr_nova', 'page', 'posts', 422, '{"fallback":true,"reason":"bluesky_quote","content":"' . $secretBody . '"}');
$stored = ap_redis_json_get($key);
if (!is_array($stored) || ($stored['fallback'] ?? null) !== true || ($stored['reason'] ?? '') !== 'bluesky_quote') fallback_fail('422 reason code stored');
if ($GLOBALS['profile_complete_cache_ttl'][$key] !== 30) fallback_fail('fallback ttl');
if (str_contains(json_encode($stored, JSON_THROW_ON_ERROR), $secretBody)) fallback_fail('post content leaked into fallback cache');
if (!ap_profile_complete_fallback_cached('cmdr_nova', 'page', 'posts')) fallback_fail('cached refusal');

ap_profile_complete_observe_transport('luna_naloc', 'page', 'media', 422, '{"fallback":true,"reason":"' . $secretBody . '"}');
$luna = ap_redis_json_get(ap_profile_complete_fallback_key('luna_naloc', 'page', 'media'));
if (!is_array($luna) || ($luna['reason'] ?? '') !== 'unclassified') fallback_fail('non-code reason rejected');
if (str_contains(json_encode($luna, JSON_THROW_ON_ERROR), 'quoted post')) fallback_fail('rejected reason leaked');

ap_profile_complete_observe_transport('shana_flux', 'full', 'boosts', 0, false);
$timeout = ap_redis_json_get(ap_profile_complete_fallback_key('shana_flux', 'full', 'boosts'));
if (!is_array($timeout) || ($timeout['reason'] ?? '') !== 'timeout') fallback_fail('transport timeout code');
ap_profile_complete_observe_transport('fresh_actor', 'page', 'posts', 200, '{"ok":true}');
if (ap_profile_complete_fallback_cached('fresh_actor', 'page', 'posts')) fallback_fail('success must not skip rust');

putenv('VAAK_PROFILE_COMPLETE_NATIVE=1');
putenv('VAAK_PROFILE_COMPLETE_SECRET=' . str_repeat('a', 32));
putenv('VAAK_SHADOW_HTTP=http://127.0.0.1:9');
putenv('VAAK_PROFILE_COMPLETE_TIMEOUT_MS=100');
$GLOBALS['vaak_guest_profile'] = true;
$started = microtime(true);
$hit = ap_profile_complete_fetch('https://mkultra.monster/users/cmdr_nova', 'posts', 12, 0, 0, 'first_paint');
$hitElapsed = microtime(true) - $started;
if ($hit !== null || $hitElapsed > 0.05) fallback_fail('cached refusal still called rust');

$started = microtime(true);
$miss = ap_profile_complete_fetch('https://mkultra.monster/users/fresh_actor', 'posts', 12, 0, 0, 'first_paint');
$missElapsed = microtime(true) - $started;
if ($miss !== null) fallback_fail('unreachable worker returned a page');
if ($missElapsed > 1.0) fallback_fail('php caller exceeded the short rust timeout');
$fresh = ap_redis_json_get(ap_profile_complete_fallback_key('fresh_actor', 'page', 'posts'));
if (!is_array($fresh) || !in_array($fresh['reason'] ?? '', ['timeout', 'transport'], true)) fallback_fail('unreachable worker was not cached');
$started = microtime(true);
$second = ap_profile_complete_fetch('https://mkultra.monster/users/fresh_actor', 'posts', 50, 12, 0, 'append');
if ($second !== null || (microtime(true) - $started) > 0.05) fallback_fail('repeat refusal was not skipped');

echo "complete-profile fallback cache: reason codes, 30s skip, and short timeout passed\n";
