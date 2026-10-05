#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Materialize Mastodon Home timeline JSON into Redis `vaak:timeline:v1:*`
 * (same envelope Ice Cubes / ap_masto_timeline_cache_store already uses).
 *
 * Primed by `vaak-worker ranked-warm` after a successful Home rebuild (0.6.67),
 * or on demand / HTML Axum miss (`ap_masto_timeline_home_hydrate_warm_async`).
 *
 * Usage:
 *   sudo -u www-data env AP_DB_DSN='pgsql:dbname=novalandia' \
 *     php api/bin/home-timeline-warm.php --owner-id=1
 *   php api/bin/home-timeline-warm.php --owner-id=1 --limit=40
 *   php api/bin/home-timeline-warm.php --owner-id=1 --limits=15,40,50,80
 */

$opts = getopt('', ['owner-id:', 'limit:', 'limits:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "home-timeline-warm.php --owner-id=N [--limit=40|--limits=15,40,50,80]\n");
    exit(0);
}

$ownerId = isset($opts['owner-id']) ? (int) $opts['owner-id'] : 0;
if ($ownerId < 1) {
    fwrite(STDERR, "home-timeline-warm: --owner-id=N required\n");
    exit(2);
}
$limits = [];
if (isset($opts['limits']) && is_string($opts['limits']) && trim($opts['limits']) !== '') {
    foreach (explode(',', (string) $opts['limits']) as $part) {
        $n = (int) trim($part);
        if ($n >= 1 && $n <= 80) {
            $limits[] = $n;
        }
    }
}
if ($limits === []) {
    $limits[] = isset($opts['limit']) ? max(1, min(80, (int) $opts['limit'])) : 40;
}
$limits = array_values(array_unique($limits));
sort($limits);

if (getenv('AP_DB_DSN') === false || getenv('AP_DB_DSN') === '') {
    putenv('AP_DB_DSN=pgsql:dbname=novalandia');
    $_ENV['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
    $_SERVER['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
}
if (getenv('VAAK_FEATURE_BLUESKY_TAB') === false) {
    putenv('VAAK_FEATURE_BLUESKY_TAB=1');
    $_ENV['VAAK_FEATURE_BLUESKY_TAB'] = '1';
}

$apiDir = dirname(__DIR__);
require $apiDir . '/ap-db.php';
require_once $apiDir . '/ap-action-queue.php';
if (!function_exists('ap_action_queue_bind_owner') || !ap_action_queue_bind_owner($ownerId)) {
    fwrite(STDERR, "home-timeline-warm: cannot bind owner_id={$ownerId}\n");
    exit(1);
}

define('AP_MASTO_LIB_ONLY', true);
require_once $apiDir . '/ap-mastodon.php';

if (!function_exists('ap_masto_timeline_home_merged')
    || !function_exists('ap_masto_timeline_cache_store')
    || !function_exists('ap_masto_timeline_cache_key')) {
    fwrite(STDERR, "home-timeline-warm: mastodon timeline helpers missing\n");
    exit(1);
}

$started = microtime(true);
// Build once at the largest limit, then store head slices (15 + 40 share the same merge).
$maxLimit = max($limits);
$statuses = ap_masto_timeline_home_merged($maxLimit, null, null, true);
if (function_exists('ap_visibility_filter_statuses')) {
    $statuses = ap_visibility_filter_statuses($statuses, $ownerId);
}
$stored = [];
foreach ($limits as $limit) {
    $slice = array_slice($statuses, 0, $limit);
    ap_masto_timeline_cache_store($slice, '/api/v1/timelines/home', $limit, null, null);
    $stored[] = [
        'limit' => $limit,
        'n' => count($slice),
        'redis_key' => ap_masto_timeline_cache_key('/api/v1/timelines/home', $limit, null, []),
    ];
}
$ms = (int) round((microtime(true) - $started) * 1000);

$out = [
    'owner' => $ownerId,
    'limits' => $limits,
    'n' => count($statuses),
    'stored' => $stored,
    'ms' => $ms,
    'source' => 'home-timeline-warm',
    'first_id' => isset($statuses[0]['id']) ? (string) $statuses[0]['id'] : null,
];
fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
