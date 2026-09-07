<?php
/**
 * CLI: publish due items from the admin posting queue.
 *
 * Usage:
 *   php ap-queue-publish.php
 *   php ap-queue-publish.php --limit=3
 *
 * Cron (every minute):
 *   * * * * * www-data /usr/bin/php /srv/mkultra/html/api/ap-queue-publish.php >> /var/log/mkultra/ap-queue.log 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$limit = 5;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(20, (int) $m[1]));
    }
}

$lockPath = '/tmp/ap-queue-publish.lock';
$lockFh = @fopen($lockPath, 'c+');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, sprintf("[%s] queue_publish busy\n", gmdate('c')));
    exit(0);
}

try {
    if (!defined('AP_INBOX_LIB_ONLY')) {
        define('AP_INBOX_LIB_ONLY', true);
    }
    require_once __DIR__ . '/ap-db.php';
    require_once __DIR__ . '/ap-r2.php';
    require_once __DIR__ . '/ap-inbox.php';
    require_once __DIR__ . '/ap-queue.php';

    $stats = ap_queue_worker_run($limit);
    fwrite(STDOUT, sprintf(
        "[%s] queue_publish claimed=%d published=%d failed=%d reclaimed=%d pruned=%d\n",
        gmdate('c'),
        $stats['claimed'],
        $stats['published'],
        $stats['failed'],
        $stats['reclaimed'],
        $stats['pruned'] ?? 0
    ));
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] queue_publish error %s\n", gmdate('c'), $e->getMessage()));
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    exit(1);
}

flock($lockFh, LOCK_UN);
fclose($lockFh);
exit(0);
