#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Opt-in: teach Home ranking about Bluesky likes, reposts and bookmarks the
 * account made before VAAK counted them (or made in other apps). Writes
 * ap_user_signals rows, one per action, dated when the action happened so the
 * rankers' 14-day decay weighs them correctly. Nothing is sent to Bluesky.
 *
 * Sources:
 *   likes, reposts  com.atproto.repo.listRecords on the account's own PDS
 *                   (app.bsky.feed.like / app.bsky.feed.repost), newest first
 *   bookmarks       the local bsky_bookmark_cache (filled when Library >
 *                   Bookmarks syncs); no network
 *
 * Rows that already exist (same owner, kind and post) are skipped, so a re-run
 * is safe. --max-per-kind keeps the newest N per kind: the rankers read the
 * newest few hundred explicit rows, and a flood of month-old likes would push
 * out recent actions while adding little (a 30-day-old action keeps ~12% of
 * its weight).
 *
 * Not installed as cron and not run by deploy. Run --dry-run first, as www-data
 * from /srv/mkultra/html:
 *
 *   php api/bin/bsky-signal-backfill.php --user=cmdr_nova --dry-run
 *   php api/bin/bsky-signal-backfill.php --user=cmdr_nova [--days=30] [--max-per-kind=150]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$user = '';
$days = 30;
$maxPerKind = 150;
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
    if (preg_match('/^--max-per-kind=(\d+)$/', $arg, $m)) {
        $maxPerKind = max(1, min(500, (int) $m[1]));
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(1);
}
if ($user === '') {
    fwrite(STDERR, "Usage: bsky-signal-backfill.php --user=NAME [--days=30] [--max-per-kind=150] [--dry-run]\n");
    exit(1);
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once dirname(__DIR__) . '/ap-inbox.php';
require_once dirname(__DIR__) . '/ap-bsky.php';
require_once dirname(__DIR__) . '/ap-signals.php';

$owner = ap_local_user_by_username($user);
$ownerId = is_array($owner) ? (int) ($owner['id'] ?? 0) : 0;
if ($ownerId < 1) {
    fwrite(STDERR, "No active user {$user}\n");
    exit(1);
}
$session = ap_bsky_session_row($ownerId);
$selfDid = is_array($session) ? trim((string) ($session['did'] ?? '')) : '';
$pds = is_array($session) ? rtrim((string) ($session['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/') : '';
if ($selfDid === '' || $pds === '') {
    fwrite(STDERR, "{$user} has no Bluesky session\n");
    exit(1);
}

$cutoff = time() - $days * 86400;

/**
 * Newest-first records of one collection in the account's repo, back to the
 * cutoff. @return list<array{uri:string,at:int}>
 */
$listOwn = static function (string $collection) use ($pds, $selfDid, $cutoff, $maxPerKind): array {
    $out = [];
    $cursor = null;
    for ($page = 0; $page < 20 && count($out) < $maxPerKind; $page++) {
        $query = ['repo' => $selfDid, 'collection' => $collection, 'limit' => 100];
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }
        $res = ap_bsky_xrpc($pds, 'com.atproto.repo.listRecords', 'GET', $query, null, null, 15);
        if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
            fwrite(STDERR, sprintf("listRecords %s page %d failed: %s\n", $collection, $page, (string) ($res['error'] ?? 'error')));
            break;
        }
        $records = is_array($res['json']['records'] ?? null) ? $res['json']['records'] : [];
        $older = false;
        foreach ($records as $rec) {
            $val = is_array($rec['value'] ?? null) ? $rec['value'] : [];
            $subject = trim((string) ($val['subject']['uri'] ?? ''));
            $at = strtotime((string) ($val['createdAt'] ?? '')) ?: 0;
            if ($at > 0 && $at < $cutoff) {
                $older = true;
                break;
            }
            if ($subject === '' || $at <= 0 || ap_signal_did_from_at_uri($subject) === '') {
                continue;
            }
            $out[] = ['uri' => $subject, 'at' => $at];
            if (count($out) >= $maxPerKind) {
                break;
            }
        }
        $cursor = isset($res['json']['cursor']) && is_string($res['json']['cursor']) && $res['json']['cursor'] !== ''
            ? $res['json']['cursor'] : null;
        if ($older || $cursor === null || $records === []) {
            break;
        }
    }
    return $out;
};

$sources = [
    'like' => $listOwn('app.bsky.feed.like'),
    'boost' => $listOwn('app.bsky.feed.repost'),
    'bookmark' => [],
];
try {
    $st = ap_db()->prepare(
        'SELECT bookmark_uri, bookmarked_at FROM bsky_bookmark_cache WHERE owner_user_id = ? ORDER BY bookmarked_at DESC'
    );
    $st->execute([$ownerId]);
    foreach ($st->fetchAll() ?: [] as $row) {
        $at = strtotime((string) ($row['bookmarked_at'] ?? '')) ?: 0;
        $uri = trim((string) ($row['bookmark_uri'] ?? ''));
        if ($at >= $cutoff && ap_signal_did_from_at_uri($uri) !== '' && count($sources['bookmark']) < $maxPerKind) {
            $sources['bookmark'][] = ['uri' => $uri, 'at' => $at];
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "bookmark cache unavailable: " . $e->getMessage() . "\n");
}

$have = ap_db()->prepare(
    "SELECT 1 FROM ap_user_signals WHERE owner_user_id = ? AND platform = 'bsky' AND signal_type = ? AND target_key = ? LIMIT 1"
);
$ins = ap_db()->prepare(
    'INSERT INTO ap_user_signals (owner_user_id, platform, signal_type, target_key, weight, metadata_json, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);

$totals = [];
foreach ($sources as $kind => $rows) {
    $added = 0;
    $skipped = 0;
    // Oldest first, so ids rise with time like rows written live.
    foreach (array_reverse($rows) as $row) {
        $have->execute([$ownerId, $kind, $row['uri']]);
        if ($have->fetchColumn()) {
            $skipped++;
            continue;
        }
        if (!$dryRun) {
            $meta = json_encode([
                'target_actor' => '',
                'author_did' => ap_signal_did_from_at_uri($row['uri']),
                'author_handle' => '',
                'object_id' => $row['uri'],
                'uri' => $row['uri'],
                'source' => 'backfill',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ins->execute([$ownerId, 'bsky', $kind, substr($row['uri'], 0, 2048), 1.0, is_string($meta) ? $meta : '{}', gmdate('c', $row['at'])]);
        }
        $added++;
    }
    $totals[$kind] = ['found' => count($rows), 'added' => $added, 'already' => $skipped];
}

if (!$dryRun && function_exists('ap_redis_delete')) {
    ap_redis_delete(
        'vaak:recommend:v1:signals:' . $ownerId,
        'vaak:recommend:v1:favourite-actors:' . $ownerId,
        'vaak:recommend:v1:favourite-tags:' . $ownerId
    );
}

foreach ($totals as $kind => $t) {
    printf("%-8s found=%d %s=%d already=%d\n", $kind, $t['found'], $dryRun ? 'would_add' : 'added', $t['added'], $t['already']);
}
printf("user=%s days=%d max_per_kind=%d%s\n", $user, $days, $maxPerKind, $dryRun ? ' dry-run' : '');
if (!$dryRun) {
    echo "Home picks these up on its next rebuild (or tap the Home reload).\n";
}
