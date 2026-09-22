<?php
/**
 * CLI: purge unused remote actor and post-media cache from R2 + the database.
 * This job is intentionally limited to mkultra/cache/; it never deletes
 * attached local post media (mkultra/media/).
 *
 * Usage:
 *   php ap-media-cleanup.php           # 30-day unused (default)
 *   php ap-media-cleanup.php --days=30 --limit=200 --post-max-mb=4096
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

$days = 30;
$limit = 200;
$postMaxMb = 4096;
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = max(1, (int) $m[1]);
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(1000, (int) $m[1]));
    }
    if (preg_match('/^--post-max-mb=(\d+)$/', $arg, $m)) {
        $postMaxMb = max(128, min(102400, (int) $m[1]));
    }
}

$res = ap_remote_media_cleanup($days, $limit);
$postRes = function_exists('ap_remote_post_media_cleanup')
    ? ap_remote_post_media_cleanup(max(30, $days), $limit, $postMaxMb)
    : ['scanned' => 0, 'deleted' => 0, 'errors' => 0];
$line = sprintf(
    "[%s] remote_media_cleanup days=%d scanned=%d deleted=%d errors=%d post_scanned=%d post_deleted=%d post_errors=%d\n",
    gmdate('c'),
    $days,
    $res['scanned'],
    $res['deleted'],
    $res['errors'],
    $postRes['scanned'],
    $postRes['deleted'],
    $postRes['errors']
);
fwrite(STDOUT, $line);
exit(($res['errors'] + $postRes['errors']) > 0 ? 2 : 0);
