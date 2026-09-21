<?php
/** CLI worker for durable post/quote publication delivery. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$limit = 10;
foreach ($argv as $arg) if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = max(1, min(50, (int) $m[1]));

try {
    if (!defined('AP_INBOX_LIB_ONLY')) define('AP_INBOX_LIB_ONLY', true);
    require_once __DIR__ . '/ap-db.php';
    require_once __DIR__ . '/ap-auth.php';
    require_once __DIR__ . '/ap-r2.php';
    require_once __DIR__ . '/ap-bsky.php';
    require_once __DIR__ . '/ap-action-queue.php';
    require_once __DIR__ . '/ap-inbox.php';
    require_once __DIR__ . '/ap-publish-delivery.php';
    $stats = ap_publish_delivery_worker_run($limit);
    fwrite(STDOUT, sprintf("[%s] publish_delivery claimed=%d succeeded=%d retried=%d failed=%d%s\n",
        gmdate('c'), $stats['claimed'], $stats['succeeded'], $stats['retried'], $stats['failed'],
        !empty($stats['busy']) ? ' busy=1' : ''));
} catch (Throwable $e) {
    error_log('[ap-publish-delivery-worker] ' . $e->getMessage());
    fwrite(STDERR, sprintf("[%s] publish_delivery worker failed\n", gmdate('c')));
    exit(1);
}
