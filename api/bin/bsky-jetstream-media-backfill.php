#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Opt-in repair for Bluesky posts that arrived through Jetstream before
 * blob-built embeds, so Home shows their images, video and link thumbnails.
 * Rewrites only bsky_posts rows with no embed_json. No network calls; the
 * thin-media warm worker later swaps in the AppView view and its labels.
 *
 * Not installed as cron and not run by deploy. Run --dry-run first.
 *
 *   php api/bin/bsky-jetstream-media-backfill.php --days=3 --dry-run
 *   php api/bin/bsky-jetstream-media-backfill.php --days=3
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$days = 3;
$limit = 5000;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = max(1, min(90, (int) $m[1]));
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(50000, (int) $m[1]));
        continue;
    }
    fwrite(STDERR, "Usage: bsky-jetstream-media-backfill.php [--days=3] [--limit=5000] [--dry-run]\n");
    exit(1);
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once dirname(__DIR__) . '/ap-db.php';
require_once dirname(__DIR__) . '/ap-auth.php';
require_once dirname(__DIR__) . '/ap-bsky.php';

$r = ap_bsky_jetstream_media_backfill($days, $limit, $dryRun);
fwrite(STDOUT, sprintf(
    "bsky-jetstream-media-backfill days=%d dry_run=%d scanned=%d repaired=%d no_media=%d\n",
    $days,
    $dryRun ? 1 : 0,
    $r['scanned'],
    $r['repaired'],
    $r['no_media']
));
foreach ($r['sample'] as $uri) {
    fwrite(STDOUT, "  {$uri}\n");
}
