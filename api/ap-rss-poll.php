<?php
/**
 * CLI: poll due RSS feeds into rss_items (off the interactive path).
 *
 * Usage:
 *   php ap-rss-poll.php
 *   php ap-rss-poll.php --max-feeds=20 --min-age=10
 *   php ap-rss-poll.php --feed-id=3
 *   php ap-rss-poll.php --dry-run
 *
 * Cron example (every 15 minutes):
 *   php /srv/mkultra/html/api/ap-rss-poll.php --max-feeds=25 --min-age=12
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-rss.php';

$maxFeeds = 20;
$minAge = 10;
$feedId = 0;
$dryRun = false;
foreach ($argv as $arg) {
    if (preg_match('/^--max-feeds=(\d+)$/', $arg, $m)) {
        $maxFeeds = max(1, min(50, (int) $m[1]));
    } elseif (preg_match('/^--min-age=(\d+)$/', $arg, $m)) {
        $minAge = max(1, min(180, (int) $m[1]));
    } elseif (preg_match('/^--feed-id=(\d+)$/', $arg, $m)) {
        $feedId = (int) $m[1];
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    }
}

ap_rss_migrate();

$feeds = [];
if ($feedId > 0) {
    $one = ap_rss_feed_by_id($feedId);
    if (is_array($one)) {
        $feeds = [$one];
    }
} else {
    $feeds = ap_rss_feeds_due($maxFeeds, $minAge);
}

$ok = 0;
$fail = 0;
$added = 0;
$skipped = 0;
foreach ($feeds as $feed) {
    $id = (int) ($feed['id'] ?? 0);
    $url = (string) ($feed['feed_url'] ?? '');
    echo '[' . gmdate('c') . "] feed={$id} url={$url}\n";
    if ($dryRun) {
        continue;
    }
    // Host still cooling down from a prior 429 in this process — leave for next tick.
    if (function_exists('ap_rss_host_backoff_until') && ap_rss_host_backoff_until($url) > time()) {
        $skipped++;
        echo "  skip host backoff\n";
        continue;
    }
    $res = ap_rss_refresh_feed($id);
    if (!empty($res['ok'])) {
        $ok++;
        $added += (int) ($res['added'] ?? 0);
        echo "  ok added=" . (int) ($res['added'] ?? 0)
            . (!empty($res['unchanged']) ? ' unchanged' : '') . "\n";
    } else {
        $fail++;
        echo '  FAIL ' . (string) ($res['error'] ?? 'error') . "\n";
    }
    // Tumblr is especially aggressive from datacenter IPs — pause longer between blogs.
    $pauseUs = (function_exists('ap_rss_host_is_tumblr') && ap_rss_host_is_tumblr($url))
        ? 1200000
        : 350000;
    usleep($pauseUs);
}
echo '[' . gmdate('c') . "] done feeds=" . count($feeds)
    . " ok={$ok} fail={$fail} skipped={$skipped} added={$added}\n";
