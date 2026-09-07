<?php
/**
 * CLI: purge unused remote avatar/header cache from R2 + SQLite.
 *
 * Usage:
 *   php ap-media-cleanup.php           # 7-day unused (default)
 *   php ap-media-cleanup.php --days=7 --limit=200
 *
 * Cron (recommended daily):
 *   30 4 * * * www-data php /srv/mkultra/html/api/ap-media-cleanup.php >> /var/log/mkultra-ap-media-cleanup.log 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-r2.php';

$days = 7;
$limit = 200;
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = max(1, (int) $m[1]);
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(1000, (int) $m[1]));
    }
}

$res = ap_remote_media_cleanup($days, $limit);
$line = sprintf(
    "[%s] remote_media_cleanup days=%d scanned=%d deleted=%d errors=%d\n",
    gmdate('c'),
    $days,
    $res['scanned'],
    $res['deleted'],
    $res['errors']
);
fwrite(STDOUT, $line);
exit($res['errors'] > 0 ? 2 : 0);
