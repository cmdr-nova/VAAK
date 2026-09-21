<?php
/** Durable remote actor-media warm worker. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$limit = 3;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(10, (int) $m[1]));
    }
}

try {
    if (!defined('AP_INBOX_LIB_ONLY')) define('AP_INBOX_LIB_ONLY', true);
    require_once __DIR__ . '/ap-db.php';
    require_once __DIR__ . '/ap-r2.php';
    require_once __DIR__ . '/ap-inbox.php';
    $stats = ap_media_warm_worker_run($limit);
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
