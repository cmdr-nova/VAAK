<?php
declare(strict_types=1);
/**
 * A cold Bluesky profile view fills the cache inline (profile + first post
 * page) under one wall-clock budget, remembers a failed attempt, restores any
 * outer budget, and honours VAAK_BSKY_PROFILE_COLD_FILL=0.
 * No network; SQLite in memory; XRPC calls are recorded.
 */
$api = getenv('VAAK_BSKY_PROFILE_COLD_FILL_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');

$GLOBALS['db'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
function ap_db_driver(?PDO $db = null): string { return 'sqlite'; }
$GLOBALS['redis'] = [];
function ap_redis_json_get(string $key): ?array { return $GLOBALS['redis'][$key] ?? null; }
function ap_redis_json_set(string $key, array $value, int $ttl): bool { $GLOBALS['redis'][$key] = $value; return true; }
function ap_redis_delete_pattern(string $pattern): void
{
    $prefix = rtrim($pattern, '*');
    foreach (array_keys($GLOBALS['redis']) as $k) {
        if (str_starts_with($k, $prefix)) unset($GLOBALS['redis'][$k]);
    }
}
function ap_bsky_access_token(int $owner, bool $force): array { return ['ok' => true, 'access' => 'tok']; }
function ap_bsky_session_row(int $owner): ?array { return ['pds_host' => 'https://pds.example', 'did' => 'did:plc:owner', 'handle' => 'owner.test']; }
function ap_bsky_feed_hosts(string $pds): array { return [$pds]; }
function ap_bsky_session_did(array $row): string { return (string) $row['did']; }
function ap_bsky_import_own_feed_as_local(int $owner, array $feed): void {}
function ap_bsky_reconcile_author_cache_deletes(string $did, array $feed, int $owner): void {}
function ap_bsky_resolve_handle_did(string $h, int $o = 0): ?string { return null; }
$GLOBALS['posts'] = [];
function ap_bsky_index_feed_items(array $feed, ?int $owner = null, int $limit = 15): void
{
    foreach ($feed as $item) { $GLOBALS['posts'][(string) $item['post']['author']['did']][] = $item['post']['uri']; }
}
function ap_bsky_posts_for_author(string $did, int $limit = 20, int $offset = 0): array { return array_slice($GLOBALS['posts'][$did] ?? [], 0, $limit); }
$GLOBALS['calls'] = [];
$GLOBALS['profile_reply'] = null;
function ap_bsky_xrpc(string $base, string $nsid, string $method = 'GET', ?array $query = null, ?array $body = null, ?string $bearer = null, int $timeout = 8): array
{
    $GLOBALS['calls'][] = ['nsid' => $nsid, 'timeout' => ap_bsky_effective_timeout($timeout), 'actor' => $query['actor'] ?? ''];
    if ($nsid === 'app.bsky.actor.getProfile') return $GLOBALS['profile_reply'];
    if ($nsid === 'app.bsky.feed.getAuthorFeed') {
        return ['ok' => true, 'json' => ['feed' => [['post' => ['uri' => 'at://did:plc:rocket/app.bsky.feed.post/1', 'author' => ['did' => 'did:plc:rocket']]]]]];
    }
    return ['ok' => false, 'status' => 0];
}
reference_load($api . '/ap-bsky.php', [
    'ap_bsky_budget_begin', 'ap_bsky_budget_clear', 'ap_bsky_budget_remaining', 'ap_bsky_budget_exceeded', 'ap_bsky_effective_timeout',
    'ap_bsky_is_profile_ref', 'ap_bsky_get_profile', 'ap_bsky_actor_refresh_migrate', 'ap_bsky_actor_profile_cache_get',
    'ap_bsky_actor_profile_cache_upsert', 'ap_bsky_get_author_feed', 'ap_bsky_actor_profile_cold_fill',
]);

$checks = 0;
function cold_check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); $GLOBALS['checks']++; }
$ref = 'https://bsky.app/profile/drivemyrocket.upuran.us';
$profile = ['did' => 'did:plc:rocket', 'handle' => 'drivemyrocket.upuran.us', 'displayName' => 'Rocket', 'description' => 'Hello there', 'viewer' => ['following' => 'at://did:plc:owner/app.bsky.graph.follow/x']];

// Cold miss: profile + first post page, both under the 4 s budget.
$GLOBALS['profile_reply'] = ['ok' => true, 'json' => $profile];
cold_check(ap_bsky_actor_profile_cache_get($ref, 1) === null, 'starts cold');
$got = ap_bsky_actor_profile_cold_fill(1, $ref, true);
cold_check(is_array($got) && ($got['profile']['description'] ?? '') === 'Hello there', 'bio painted on first load');
cold_check(($got['viewer']['following'] ?? '') !== '', 'viewer follow state cached');
cold_check(array_column($GLOBALS['calls'], 'nsid') === ['app.bsky.actor.getProfile', 'app.bsky.feed.getAuthorFeed'], 'one profile + one feed call');
cold_check(max(array_column($GLOBALS['calls'], 'timeout')) <= 4, 'calls capped by the cold-fill budget');
cold_check(ap_bsky_posts_for_author('did:plc:rocket', 5) !== [], 'first post page indexed');
cold_check(!isset($GLOBALS['ap_bsky_budget_deadline']), 'no budget left behind');
cold_check(is_array(ap_bsky_actor_profile_cache_get('did:plc:rocket', 1)), 'DID key cached too');

// Warm author: no second feed call; profile tab outside posts skips the feed.
$GLOBALS['calls'] = [];
$ref2 = 'https://bsky.app/profile/other.test';
$GLOBALS['profile_reply'] = ['ok' => true, 'json' => ['did' => 'did:plc:rocket', 'handle' => 'other.test']];
ap_bsky_actor_profile_cold_fill(1, $ref2, true);
cold_check(array_column($GLOBALS['calls'], 'nsid') === ['app.bsky.actor.getProfile'], 'cached posts skip the feed call');
$GLOBALS['calls'] = [];
$GLOBALS['profile_reply'] = ['ok' => true, 'json' => ['did' => 'did:plc:nofeed', 'handle' => 'nofeed.test']];
ap_bsky_actor_profile_cold_fill(1, 'https://bsky.app/profile/nofeed.test', false);
cold_check(array_column($GLOBALS['calls'], 'nsid') === ['app.bsky.actor.getProfile'], 'non-post tab skips the feed call');

// Failure: null, remembered, no second network attempt (even in a new request).
$GLOBALS['calls'] = [];
$GLOBALS['profile_reply'] = ['ok' => false, 'status' => 0, 'error' => 'timeout'];
$dead = 'https://bsky.app/profile/gone.test';
cold_check(ap_bsky_actor_profile_cold_fill(1, $dead, true) === null, 'failure returns null');
$mark = 'vaak:bsky:profile-cold-fill:v1:1:' . hash('sha256', strtolower($dead));
cold_check(isset($GLOBALS['redis'][$mark]), 'failure remembered');
$GLOBALS['calls'] = [];
cold_check(ap_bsky_actor_profile_cold_fill(1, $dead, true) === null && $GLOBALS['calls'] === [], 'remembered failure skips network');

// An outer, shorter budget wins and is restored afterwards.
$GLOBALS['calls'] = [];
$GLOBALS['profile_reply'] = ['ok' => true, 'json' => ['did' => 'did:plc:outer', 'handle' => 'outer.test']];
$outer = microtime(true) + 2.5;
$GLOBALS['ap_bsky_budget_deadline'] = $outer;
ap_bsky_actor_profile_cold_fill(1, 'https://bsky.app/profile/outer.test', false);
cold_check(($GLOBALS['ap_bsky_budget_deadline'] ?? null) === $outer, 'outer budget restored');
cold_check(max(array_column($GLOBALS['calls'], 'timeout')) <= 2, 'outer budget caps the fill');
unset($GLOBALS['ap_bsky_budget_deadline']);

// Kill switch and non-Bluesky refs.
$GLOBALS['calls'] = [];
putenv('VAAK_BSKY_PROFILE_COLD_FILL=0');
cold_check(ap_bsky_actor_profile_cold_fill(1, 'https://bsky.app/profile/switch.test', true) === null && $GLOBALS['calls'] === [], 'kill switch');
putenv('VAAK_BSKY_PROFILE_COLD_FILL');
cold_check(ap_bsky_actor_profile_cold_fill(1, 'https://mastodon.example/users/a', true) === null && $GLOBALS['calls'] === [], 'non-Bluesky ref ignored');
cold_check(ap_bsky_actor_profile_cold_fill(0, $ref, true) === null, 'no owner, no fill');

echo "bsky_profile_cold_fill_smoke_ok checks={$checks}\n";
