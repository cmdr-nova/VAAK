<?php
/**
 * CLI: retry failed / soft-skipped Bluesky mirrors (DNS/auth blips, reply waiting on parent).
 *
 * Usage:
 *   php ap-bsky-crosspost-retry.php
 *   php ap-bsky-crosspost-retry.php --limit=5
 *
 * Cron (every minute):
 *   * * * * * www-data /usr/bin/php /srv/mkultra/html/api/ap-bsky-crosspost-retry.php >> /var/log/mkultra/ap-bsky-crosspost-retry.log 2>&1
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

$lockPath = '/tmp/ap-bsky-crosspost-retry.lock';
$lockFh = @fopen($lockPath, 'c+');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, sprintf("[%s] bsky_crosspost_retry busy\n", gmdate('c')));
    exit(0);
}

try {
    if (!defined('AP_INBOX_LIB_ONLY')) {
        define('AP_INBOX_LIB_ONLY', true);
    }
    require_once __DIR__ . '/ap-db.php';
    require_once __DIR__ . '/ap-auth.php';
    require_once __DIR__ . '/ap-r2.php';
    require_once __DIR__ . '/ap-bsky.php';

    $stats = ap_bsky_crosspost_retry_worker_run($limit);
    fwrite(STDOUT, sprintf(
        "[%s] bsky_crosspost_retry claimed=%d ok=%d failed=%d skipped=%d dead=%d\n",
        gmdate('c'),
        $stats['claimed'],
        $stats['ok'],
        $stats['failed'],
        $stats['skipped'],
        $stats['dead']
    ));
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] bsky_crosspost_retry error %s\n", gmdate('c'), $e->getMessage()));
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    exit(1);
}

flock($lockFh, LOCK_UN);
fclose($lockFh);
exit(0);
