#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Opt-in repair: add Bluesky reposts made from VAAK to the owner's profile
 * Boosts tab. Reposts used to reach that tab only when an author-feed import
 * happened to run, so finished reposts could stay missing. Reads succeeded
 * repost actions from ap_action_queue and writes only local masto_reblogs
 * rows; nothing is sent to Bluesky.
 *
 * Not installed as cron and not run by deploy. Run --dry-run first.
 *
 *   php api/bin/bsky-own-repost-profile-backfill.php --user=cmdr_nova --days=14 --dry-run
 *   php api/bin/bsky-own-repost-profile-backfill.php --user=cmdr_nova --days=14
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$user = '';
$days = 14;
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
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(1);
}
if ($user === '') {
    fwrite(STDERR, "Usage: bsky-own-repost-profile-backfill.php --user=NAME [--days=14] [--dry-run]\n");
    exit(1);
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once dirname(__DIR__) . '/ap-inbox.php';
require_once dirname(__DIR__) . '/ap-bsky.php';

$owner = ap_local_user_by_username($user);
$ownerId = is_array($owner) ? (int) ($owner['id'] ?? 0) : 0;
if ($ownerId < 1) {
    fwrite(STDERR, "No active user {$user}\n");
    exit(1);
}
$session = ap_bsky_session_row($ownerId);
$selfDid = is_array($session) ? trim((string) ($session['did'] ?? '')) : '';
if ($selfDid === '') {
    fwrite(STDERR, "{$user} has no Bluesky session\n");
    exit(1);
}

$since = gmdate('c', time() - $days * 86400);
$st = ap_db()->prepare(
    "SELECT target_key, payload_json FROM ap_action_queue
     WHERE owner_user_id = ? AND platform = 'bsky' AND action_kind = 'boost'
       AND status = 'succeeded' AND desired_state = 1 AND updated_at >= ?
     ORDER BY updated_at ASC"
);
$st->execute([$ownerId, $since]);
$have = ap_db()->prepare('SELECT 1 FROM masto_reblogs WHERE owner_user_id = ? AND status_id = ? LIMIT 1');

$seen = 0;
$missing = 0;
foreach ($st->fetchAll() ?: [] as $row) {
    $payload = json_decode((string) ($row['payload_json'] ?? ''), true) ?: [];
    $uri = trim((string) ($payload['uri'] ?? $row['target_key'] ?? ''));
    if (!str_starts_with($uri, 'at://')) {
        continue;
    }
    $seen++;
    $have->execute([$ownerId, ap_bsky_own_repost_status_id($selfDid, $uri)]);
    if ($have->fetchColumn() !== false) {
        continue;
    }
    $missing++;
    fwrite(STDOUT, ($dryRun ? 'would add ' : 'add ') . $uri . "\n");
    if (!$dryRun) {
        ap_bsky_own_repost_profile_sync($ownerId, $uri, true);
    }
}
fwrite(STDOUT, sprintf(
    "bsky-own-repost-profile-backfill user=%s days=%d dry_run=%d reposts=%d missing=%d\n",
    $user,
    $days,
    $dryRun ? 1 : 0,
    $seen,
    $missing
));
