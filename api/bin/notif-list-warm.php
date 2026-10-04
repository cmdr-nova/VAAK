#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Rebuild Mentions / Ice Cubes notification list Redis keys for one owner.
 *
 * Invoked by `vaak-worker notif-list` (M1 list-warm; M4 leaner tick).
 * Uses the same ap_masto_notifications_fetch hydrate path so cached JSON
 * matches PHP paint. M4: skip-if-fresh, derive all30 from all40, cache-only
 * Bluesky media (thin-media enqueue), flat actor DID prefetch inside fetch.
 *
 * Interim PHP materializer — native Rust hydrate replaces this, then drop.
 *
 * Usage:
 *   sudo -u www-data env AP_DB_DSN='pgsql:dbname=novalandia' \
 *     VAAK_NOTIF_LIST_WARM=1 \
 *     php api/bin/notif-list-warm.php --owner-id=1
 *   php api/bin/notif-list-warm.php --owner-id=1 --limit=30 --force
 *   php api/bin/notif-list-warm.php --owner-id=1 --refresh-secs=90
 */

$opts = getopt('', ['owner-id:', 'limit:', 'refresh-secs:', 'force', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "notif-list-warm.php --owner-id=N [--limit=30] [--refresh-secs=90] [--force]\n");
    exit(0);
}

$ownerId = isset($opts['owner-id']) ? (int) $opts['owner-id'] : 0;
if ($ownerId < 1) {
    fwrite(STDERR, "notif-list-warm: --owner-id=N required\n");
    exit(2);
}
$limit = isset($opts['limit']) ? max(1, min(80, (int) $opts['limit'])) : 30;
$force = isset($opts['force']);
$refreshSecs = isset($opts['refresh-secs']) ? max(15, min(600, (int) $opts['refresh-secs'])) : 90;

if (getenv('AP_DB_DSN') === false || getenv('AP_DB_DSN') === '') {
    putenv('AP_DB_DSN=pgsql:dbname=novalandia');
    $_ENV['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
    $_SERVER['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
}
putenv('VAAK_NOTIF_LIST_WARM=1');
$_ENV['VAAK_NOTIF_LIST_WARM'] = '1';
$_SERVER['VAAK_NOTIF_LIST_WARM'] = '1';
if (getenv('VAAK_FEATURE_BLUESKY_TAB') === false) {
    putenv('VAAK_FEATURE_BLUESKY_TAB=1');
    $_ENV['VAAK_FEATURE_BLUESKY_TAB'] = '1';
}

$apiDir = dirname(__DIR__);
require $apiDir . '/ap-db.php';
require_once $apiDir . '/ap-action-queue.php';
if (!function_exists('ap_action_queue_bind_owner') || !ap_action_queue_bind_owner($ownerId)) {
    fwrite(STDERR, "notif-list-warm: cannot bind owner_id={$ownerId}\n");
    exit(1);
}
require_once $apiDir . '/ap-masto-entities.php';

if (!function_exists('ap_masto_notifications_fetch')
    || !function_exists('ap_masto_notifications_list_redis_key')
    || !function_exists('ap_masto_notifications_want_types')
    || !function_exists('ap_masto_notifications_list_cache_age')
    || !function_exists('ap_masto_notifications_list_cache_put')) {
    fwrite(STDERR, "notif-list-warm: masto notification helpers missing\n");
    exit(1);
}

/**
 * @param list<string> $types
 * @return array{key:string,age:?int,fresh:bool}
 */
$inspectJob = static function (int $ownerId, int $jobLimit, array $types) use ($refreshSecs, $force): array {
    $want = ap_masto_notifications_want_types($types, []);
    $key = ap_masto_notifications_list_redis_key($ownerId, $jobLimit, null, null, $want, []);
    $age = ap_masto_notifications_list_cache_age($key);
    $fresh = !$force && $age !== null && $age <= $refreshSecs;
    return ['key' => $key, 'age' => $age, 'fresh' => $fresh];
};

// Match Mentions filter tabs + Ice Cubes first-page shapes.
// all40 runs before all30 so a rebuild can derive all30 without a second hydrate.
$jobs = [
    ['label' => 'all40', 'limit' => 40, 'types' => []],
    ['label' => 'all30', 'limit' => $limit, 'types' => [], 'derive_from' => 'all40'],
    ['label' => 'mentions', 'limit' => $limit, 'types' => ['mention']],
    ['label' => 'favourites', 'limit' => $limit, 'types' => ['favourite']],
    ['label' => 'boosts_quotes', 'limit' => $limit, 'types' => ['reblog', 'quote']],
];

$started = microtime(true);
$ok = 0;
$fail = 0;
$skipped = 0;
$derived = 0;
/** @var array<string, list<array<string,mixed>>> $built */
$built = [];

foreach ($jobs as $job) {
    $label = (string) $job['label'];
    $jobLimit = (int) $job['limit'];
    /** @var list<string> $types */
    $types = $job['types'];
    $t0 = microtime(true);
    try {
        $meta = $inspectJob($ownerId, $jobLimit, $types);
        if ($meta['fresh']) {
            $ms = (int) round((microtime(true) - $t0) * 1000);
            fwrite(STDOUT, sprintf(
                "owner=%d job=%s skip_fresh age=%d refresh=%d ms=%d\n",
                $ownerId,
                $label,
                (int) $meta['age'],
                $refreshSecs,
                $ms
            ));
            $skipped++;
            $ok++;
            continue;
        }

        $deriveFrom = isset($job['derive_from']) ? (string) $job['derive_from'] : '';
        if ($deriveFrom !== '' && isset($built[$deriveFrom]) && is_array($built[$deriveFrom])) {
            $items = array_slice($built[$deriveFrom], 0, $jobLimit);
            ap_masto_notifications_list_cache_put($ownerId, $jobLimit, $items, $types, [], 'vaak-worker-live');
            $n = count($items);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            fwrite(STDOUT, sprintf(
                "owner=%d job=%s derive=%s items=%d ms=%d\n",
                $ownerId,
                $label,
                $deriveFrom,
                $n,
                $ms
            ));
            $derived++;
            $ok++;
            $built[$label] = $items;
            continue;
        }

        $items = ap_masto_notifications_fetch(
            $jobLimit,
            null,
            null,
            $types,
            [],
            true
        );
        if (!is_array($items)) {
            $items = [];
        }
        $built[$label] = $items;
        $n = count($items);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        fwrite(STDOUT, sprintf(
            "owner=%d job=%s items=%d ms=%d\n",
            $ownerId,
            $label,
            $n,
            $ms
        ));
        $ok++;
    } catch (Throwable $e) {
        $fail++;
        fwrite(STDERR, sprintf(
            "owner=%d job=%s error=%s\n",
            $ownerId,
            $label,
            $e->getMessage()
        ));
    }
}

$totalMs = (int) round((microtime(true) - $started) * 1000);
fwrite(STDOUT, sprintf(
    "owner=%d warm_ok=%d warm_fail=%d skip_fresh=%d derive=%d total_ms=%d refresh_secs=%d force=%d\n",
    $ownerId,
    $ok,
    $fail,
    $skipped,
    $derived,
    $totalMs,
    $refreshSecs,
    $force ? 1 : 0
));
exit($fail > 0 && $ok === 0 ? 1 : 0);
