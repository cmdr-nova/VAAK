#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Bootstrap fill for `ap_notification_projection` (VAAK 0.6.66+).
 *
 * The Rust `vaak-worker notif-list` loop only materializes projection → Redis.
 * After 0.6.63 retired request-path PHP list-warm, heavy accounts never got
 * projection rows written in the background, so Mentions stayed on cold PHP
 * hydrate. This CLI is a temporary bridge: hydrate once from local PG into
 * the projection (and Redis list envelopes) so the native Redis path can win.
 *
 * Destination remains Rust-native hydrate; do not call this from HTTP.
 *
 * Usage:
 *   sudo -u www-data env AP_DB_DSN='pgsql:dbname=novalandia' VAAK_NOTIF_LIST_WARM=1 \
 *     php api/bin/notif-projection-fill.php --owner-id=1 [--limit=80]
 */

$opts = getopt('', ['owner-id:', 'limit:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "notif-projection-fill.php --owner-id=N [--limit=80]\n");
    exit(0);
}

$ownerId = isset($opts['owner-id']) ? (int) $opts['owner-id'] : 0;
if ($ownerId < 1) {
    fwrite(STDERR, "notif-projection-fill: --owner-id=N required\n");
    exit(2);
}
$limit = isset($opts['limit']) ? max(1, min(80, (int) $opts['limit'])) : 80;

if (getenv('AP_DB_DSN') === false || getenv('AP_DB_DSN') === '') {
    putenv('AP_DB_DSN=pgsql:dbname=novalandia');
    $_ENV['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
    $_SERVER['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
}
// Mark warm mode so fetch writes source=vaak-worker-live and skips request-path SWR shortcuts.
putenv('VAAK_NOTIF_LIST_WARM=1');
$_ENV['VAAK_NOTIF_LIST_WARM'] = '1';
$_SERVER['VAAK_NOTIF_LIST_WARM'] = '1';

$apiDir = dirname(__DIR__);
require $apiDir . '/ap-db.php';
require_once $apiDir . '/ap-action-queue.php';
if (!function_exists('ap_action_queue_bind_owner') || !ap_action_queue_bind_owner($ownerId)) {
    fwrite(STDERR, "notif-projection-fill: cannot bind owner_id={$ownerId}\n");
    exit(1);
}

define('AP_MASTO_LIB_ONLY', true);
require_once $apiDir . '/ap-mastodon.php';

if (!function_exists('ap_masto_notifications_fetch')) {
    fwrite(STDERR, "notif-projection-fill: ap_masto_notifications_fetch missing\n");
    exit(1);
}

$started = microtime(true);
// Bypass Redis so we always rebuild projection from local sources.
$items = ap_masto_notifications_fetch($limit, null, null, [], [], true);
$ms = (int) round((microtime(true) - $started) * 1000);

$projCount = 0;
try {
    $st = ap_db()->prepare(
        'SELECT COUNT(*) FROM ap_notification_projection
         WHERE owner_user_id = ? AND updated_at >= ?'
    );
    $cutoff = gmdate('c', time() - 900);
    $st->execute([$ownerId, $cutoff]);
    $projCount = (int) $st->fetchColumn();
} catch (Throwable $e) {
    // optional
}

$out = [
    'owner' => $ownerId,
    'limit' => $limit,
    'n' => count($items),
    'projection_recent' => $projCount,
    'ms' => $ms,
    'source' => 'notif-projection-fill',
];
fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
exit(0);
