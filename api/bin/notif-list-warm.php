#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Rebuild Mentions / Ice Cubes notification list Redis keys for one owner.
 *
 * Invoked by `vaak-worker notif-list` (M1 list-warm). Uses the same
 * ap_masto_notifications_fetch hydrate path so cached JSON matches PHP paint.
 *
 * Usage:
 *   sudo -u www-data env AP_DB_DSN='pgsql:dbname=novalandia' \
 *     VAAK_NOTIF_LIST_WARM=1 \
 *     php api/bin/notif-list-warm.php --owner-id=1
 *   php api/bin/notif-list-warm.php --owner-id=1 --limit=30
 */

$opts = getopt('', ['owner-id:', 'limit:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "notif-list-warm.php --owner-id=N [--limit=30]\n");
    exit(0);
}

$ownerId = isset($opts['owner-id']) ? (int) $opts['owner-id'] : 0;
if ($ownerId < 1) {
    fwrite(STDERR, "notif-list-warm: --owner-id=N required\n");
    exit(2);
}
$limit = isset($opts['limit']) ? max(1, min(80, (int) $opts['limit'])) : 30;

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

if (!function_exists('ap_masto_notifications_fetch')) {
    fwrite(STDERR, "notif-list-warm: ap_masto_notifications_fetch missing\n");
    exit(1);
}

// Match Mentions filter tabs + Ice Cubes first-page shapes.
$jobs = [
    ['label' => 'all30', 'limit' => $limit, 'types' => []],
    ['label' => 'all40', 'limit' => 40, 'types' => []],
    ['label' => 'mentions', 'limit' => $limit, 'types' => ['mention']],
    ['label' => 'favourites', 'limit' => $limit, 'types' => ['favourite']],
    ['label' => 'boosts_quotes', 'limit' => $limit, 'types' => ['reblog', 'quote']],
];

$started = microtime(true);
$ok = 0;
$fail = 0;
foreach ($jobs as $job) {
    $t0 = microtime(true);
    try {
        $items = ap_masto_notifications_fetch(
            (int) $job['limit'],
            null,
            null,
            $job['types'],
            [],
            true
        );
        $n = is_array($items) ? count($items) : 0;
        $ms = (int) round((microtime(true) - $t0) * 1000);
        fwrite(STDOUT, sprintf(
            "owner=%d job=%s items=%d ms=%d\n",
            $ownerId,
            (string) $job['label'],
            $n,
            $ms
        ));
        $ok++;
    } catch (Throwable $e) {
        $fail++;
        fwrite(STDERR, sprintf(
            "owner=%d job=%s error=%s\n",
            $ownerId,
            (string) $job['label'],
            $e->getMessage()
        ));
    }
}

$totalMs = (int) round((microtime(true) - $started) * 1000);
fwrite(STDOUT, sprintf(
    "owner=%d warm_ok=%d warm_fail=%d total_ms=%d\n",
    $ownerId,
    $ok,
    $fail,
    $totalMs
));
exit($fail > 0 && $ok === 0 ? 1 : 0);
