#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Opt-in repair for Bluesky reply / mention notifications that quote a post
 * (often the author's own) but were stored before ingest kept the quote.
 * Re-reads those posts from the public AppView, appends the quote line,
 * caches the quoted post, and clears the owner's notification cache.
 *
 * Not installed as cron and not run by deploy. Run --dry-run first.
 *
 *   php api/bin/bsky-notif-quote-backfill.php --user=cmdr_nova --days=7 --dry-run
 *   php api/bin/bsky-notif-quote-backfill.php --user=cmdr_nova --days=7
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$user = '';
$days = 7;
$limit = 200;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (preg_match('/^--user=([A-Za-z][A-Za-z0-9_]{1,29})$/', $arg, $m)) {
        $user = $m[1];
        continue;
    }
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = max(1, min(90, (int) $m[1]));
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(500, (int) $m[1]));
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(1);
}
if ($user === '') {
    fwrite(STDERR, "Usage: bsky-notif-quote-backfill.php --user=NAME [--days=7] [--limit=200] [--dry-run]\n");
    exit(1);
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once dirname(__DIR__) . '/ap-inbox.php';
require_once dirname(__DIR__) . '/ap-bsky.php';

$owner = ap_local_user_by_username($user);
if (!is_array($owner) || (int) ($owner['id'] ?? 0) < 1) {
    fwrite(STDERR, "No active user {$user}\n");
    exit(1);
}
if (ap_bsky_session_row((int) $owner['id']) === null) {
    fwrite(STDERR, "{$user} has no Bluesky session\n");
    exit(1);
}

$r = ap_bsky_notification_quotes_backfill((int) $owner['id'], $days, $limit, $dryRun);
fwrite(STDOUT, sprintf(
    "bsky-notif-quote-backfill user=%s days=%d dry_run=%d rows=%d fetched=%d quoting=%d updated=%d ids=%s\n",
    $user,
    $days,
    $dryRun ? 1 : 0,
    $r['rows'],
    $r['fetched'],
    $r['quoting'],
    $r['updated'],
    $r['ids'] === [] ? 'none' : implode(',', $r['ids'])
));
