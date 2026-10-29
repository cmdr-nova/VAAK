<?php
/** Durable remote actor-media warm worker. One instance at a time. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!function_exists('ap_media_warm_lock_path')) {
    function ap_media_warm_lock_path(): string
    {
        $override = getenv('VAAK_MEDIA_WARM_LOCK');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        return sys_get_temp_dir() . '/vaak-media-warm-worker.lock';
    }
}

// Non-blocking lock before any application code is loaded. flock(1) holds it
// until this process exits, so a second launch does not parse the API and
// burn a core just to discover it is busy.
if (getenv('VAAK_MEDIA_WARM_LOCKED') !== '1') {
    $tail = '';
    foreach (array_slice($argv, 1) as $arg) {
        $tail .= ' ' . escapeshellarg((string) $arg);
    }
    $cmd = 'flock -n -E 2 ' . escapeshellarg(ap_media_warm_lock_path())
        . ' env VAAK_MEDIA_WARM_LOCKED=1 '
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . $tail;
    passthru($cmd, $code);
    if ($code === 2) {
        fwrite(STDOUT, sprintf(
            "[%s] media_warm claimed=0 succeeded=0 retried=0 failed=0 busy=1\n",
            gmdate('c')
        ));
        exit(0);
    }
    exit($code);
}

$limit = 3;
// Keep claiming batches until the queue is empty or this many seconds have
// passed. A nudge used to run one batch of 3, so the rest of a cold Home
// page (up to 12 images) waited for cron. --budget=0 runs a single batch.
$budget = 45;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(10, (int) $m[1]));
    }
    if (preg_match('/^--budget=(\d+)$/', $arg, $m)) {
        $budget = max(0, min(240, (int) $m[1]));
    }
}

try {
    if (!defined('AP_INBOX_LIB_ONLY')) define('AP_INBOX_LIB_ONLY', true);
    require_once __DIR__ . '/ap-db.php';
    require_once __DIR__ . '/ap-r2.php';
    require_once __DIR__ . '/ap-inbox.php';
    $started = microtime(true);
    $stats = ap_media_warm_worker_run($limit);
    $lastClaimed = $stats['claimed'];
    while (empty($stats['busy']) && $budget > 0 && $lastClaimed > 0
        && microtime(true) - $started < $budget) {
        $next = ap_media_warm_worker_run($limit);
        foreach (['claimed', 'succeeded', 'retried', 'failed'] as $k) {
            $stats[$k] += $next[$k];
        }
        $lastClaimed = $next['claimed'];
    }
    fwrite(STDOUT, sprintf(
        "[%s] media_warm claimed=%d succeeded=%d retried=%d failed=%d%s\n",
        gmdate('c'), $stats['claimed'], $stats['succeeded'], $stats['retried'], $stats['failed'],
        !empty($stats['busy']) ? ' busy=1' : ''
    ));
} catch (Throwable $e) {
    error_log('[ap-media-warm-worker] ' . $e->getMessage());
    fwrite(STDERR, "media warm worker failed\n");
    exit(1);
}
