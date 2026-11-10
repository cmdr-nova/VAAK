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

// Shared hung-holder reaper (ap-worker-lock.php): a run older than the cap
// is killed by the next one instead of leaving every later run "busy".
require_once __DIR__ . '/ap-worker-lock.php';
$vaakLock = ap_worker_lock_take('/tmp/ap-queue-publish.lock', 900, 'ap-queue-publish');
$lockFh = $vaakLock['lock'];
if (!$lockFh) {
    fwrite(STDOUT, sprintf("[%s] queue_publish busy (%s)\n", gmdate('c'), $vaakLock['busy']));
    exit(0);
}
ap_worker_lock_alarm(900, 'queue_publish');

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
    // Scheduled blog posts ride the same every-minute cron.
    require_once __DIR__ . '/ap-blog.php';
    $blogStats = ap_blog_publish_due($limit);
    if ($blogStats['due'] > 0) {
        fwrite(STDOUT, sprintf(
            "[%s] blog_publish due=%d published=%d failed=%d\n",
            gmdate('c'),
            $blogStats['due'],
            $blogStats['published'],
            $blogStats['failed']
        ));
    }
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] queue_publish error %s\n", gmdate('c'), $e->getMessage()));
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    exit(1);
}

flock($lockFh, LOCK_UN);
fclose($lockFh);
exit(0);
