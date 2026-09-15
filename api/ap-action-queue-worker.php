<?php
/** CLI worker for durable reversible Fediverse / Bluesky actions. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$limit = 20;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = max(1, min(50, (int) $m[1]));
}

try {
    if (!defined('AP_INBOX_LIB_ONLY')) define('AP_INBOX_LIB_ONLY', true);
    require_once __DIR__ . '/ap-db.php';
    require_once __DIR__ . '/ap-auth.php';
    require_once __DIR__ . '/ap-r2.php';
    require_once __DIR__ . '/ap-bsky.php';
    require_once __DIR__ . '/ap-action-queue.php';
    $stats = ap_action_queue_worker_run($limit);
    fwrite(STDOUT, sprintf("[%s] action_queue claimed=%d succeeded=%d retried=%d failed=%d%s\n",
        gmdate('c'), $stats['claimed'], $stats['succeeded'], $stats['retried'], $stats['failed'],
        !empty($stats['busy']) ? ' busy=1' : ''));
} catch (Throwable $e) {
    error_log('[ap-action-queue-worker] ' . $e->getMessage());
    fwrite(STDERR, sprintf("[%s] action_queue worker failed\n", gmdate('c')));
    exit(1);
}
