#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * List local replies since 2026-10-18 in Bluesky-origin threads that never
 * wrote a mentions row for the local parent or a locally mentioned user.
 *
 * Dry-run unless --apply is passed. Not installed as cron and not run by deploy.
 *
 *   php api/bin/bsky-thread-local-notify-backfill.php
 *   php api/bin/bsky-thread-local-notify-backfill.php --since=2026-10-18T00:00:00Z --limit=500
 *   php api/bin/bsky-thread-local-notify-backfill.php --apply
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$apply = false;
$since = '2026-10-18T00:00:00Z';
$limit = 500;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if ($arg === '--dry-run') {
        $apply = false;
        continue;
    }
    if (preg_match('/^--since=(\d{4}-\d{2}-\d{2}(?:T[0-9:.+-]+)?)$/', $arg, $m)) {
        $since = $m[1];
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(2000, (int) $m[1]));
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(1);
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once dirname(__DIR__) . '/ap-inbox.php';

$report = ap_bsky_thread_local_notify_backfill($apply, $since, $limit);
fwrite(STDOUT, sprintf(
    "bsky-thread-local-notify-backfill since=%s dry_run=%d scanned=%d missing=%d applied=%d\n",
    $report['since'],
    $apply ? 0 : 1,
    $report['scanned'],
    count($report['missing']),
    $report['applied']
));
foreach ($report['missing'] as $row) {
    fwrite(STDOUT, sprintf(
        "%s actor=%s in_reply_to=%s recipients=%s\n",
        $row['note_id'],
        $row['actor'],
        $row['in_reply_to'],
        implode(',', $row['recipients'])
    ));
}
