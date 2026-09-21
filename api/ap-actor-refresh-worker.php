<?php
/** CLI worker for background Bluesky actor/profile and relationship hydration. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$limit = 12;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(30, (int) $m[1]));
    }
}

try {
    if (!defined('AP_INBOX_LIB_ONLY')) define('AP_INBOX_LIB_ONLY', true);
    require_once __DIR__ . '/ap-db.php';
    require_once __DIR__ . '/ap-auth.php';
    require_once __DIR__ . '/ap-r2.php';
    require_once __DIR__ . '/ap-bsky.php';
    $stats = ap_bsky_actor_refresh_worker_run($limit);
    fwrite(STDOUT, sprintf(
        "[%s] actor_refresh claimed=%d succeeded=%d retried=%d failed=%d%s\n",
        gmdate('c'), $stats['claimed'], $stats['succeeded'], $stats['retried'], $stats['failed'],
        !empty($stats['busy']) ? ' busy=1' : ''
    ));
} catch (Throwable $e) {
    error_log('[ap-actor-refresh-worker] ' . $e->getMessage());
    fwrite(STDERR, sprintf("[%s] actor_refresh worker failed\n", gmdate('c')));
    exit(1);
}
