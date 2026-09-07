<?php
/**
 * CLI: backfill recent outbox Creates for one actor, or all following.
 *
 *   php ap-outbox-backfill.php https://example.com/users/alice
 *   php ap-outbox-backfill.php --all
 *   php ap-outbox-backfill.php --all --limit=8
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo "CLI only\n";
    exit(1);
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-inbox.php';

$limit = 12;
$all = false;
$actor = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--all') {
        $all = true;
        continue;
    }
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(40, (int) substr($arg, 8)));
        continue;
    }
    if (str_starts_with($arg, 'https://')) {
        $actor = $arg;
    }
}

if (!$all && $actor === null) {
    fwrite(STDERR, "Usage: php ap-outbox-backfill.php <actorUrl>|--all [--limit=N]\n");
    exit(1);
}

$targets = [];
if ($all) {
    foreach (ap_following_list() as $f) {
        $a = rtrim((string) ($f['actor_id'] ?? ''), '/');
        if ($a !== '' && str_starts_with($a, 'https://')) {
            $targets[] = $a;
        }
    }
} else {
    $targets[] = rtrim((string) $actor, '/');
}

$totalIn = 0;
$totalSkip = 0;
$fail = 0;
foreach ($targets as $a) {
    $res = ap_outbox_backfill_actor($a, $limit);
    $in = (int) ($res['imported'] ?? 0);
    $sk = (int) ($res['skipped'] ?? 0);
    $totalIn += $in;
    $totalSkip += $sk;
    if (empty($res['ok'])) {
        $fail++;
        fwrite(STDOUT, 'FAIL ' . $a . ' err=' . ($res['error'] ?? '?') . "\n");
    } else {
        fwrite(STDOUT, 'OK   ' . $a . " imported=$in skipped=$sk\n");
    }
    // Be polite to remotes when sweeping everyone
    if ($all) {
        usleep(250000);
    }
}
fwrite(STDOUT, "done targets=" . count($targets) . " imported=$totalIn skipped=$totalSkip fail=$fail\n");
exit($fail > 0 && $totalIn === 0 ? 1 : 0);
