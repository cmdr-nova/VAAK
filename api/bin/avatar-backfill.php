#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Opt-in refresh for remote actors whose icon is missing or older than --stale-hours.
 *
 * Not installed as cron and not run by deploy. A full bsky_posts scan is intentionally
 * absent: that table is huge. Bluesky mode reads bsky_actor_profiles only.
 *
 *   php api/bin/avatar-backfill.php --limit=50 --stale-hours=12
 *   php api/bin/avatar-backfill.php --bsky --limit=50
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$limit = 50;
$staleHours = 12;
$bsky = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--bsky') {
        $bsky = true;
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(500, (int) $m[1]));
        continue;
    }
    if (preg_match('/^--stale-hours=(\d+)$/', $arg, $m)) {
        $staleHours = max(1, min(24 * 30, (int) $m[1]));
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(1);
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once dirname(__DIR__) . '/ap-inbox.php';
require_once dirname(__DIR__) . '/ap-avatar-refresh.php';
if (is_file(dirname(__DIR__) . '/ap-bsky.php')) {
    require_once dirname(__DIR__) . '/ap-bsky.php';
}

$GLOBALS['ap_remote_actor_force_refresh'] = true;
$GLOBALS['ap_remote_actor_skip_host_cap'] = true;

$refreshed = 0;
$queued = 0;
$hosts = [];
$pause = static function (string $actorId) use (&$hosts): void {
    $host = strtolower((string) (parse_url($actorId, PHP_URL_HOST) ?: ''));
    if ($host !== '' && isset($hosts[$host])) {
        usleep(200000);
    }
    if ($host !== '') {
        $hosts[$host] = true;
    }
};

if ($bsky) {
    $st = ap_db()->prepare(
        'SELECT actor_ref, did, profile_json FROM bsky_actor_profiles ORDER BY updated_at ASC LIMIT ?'
    );
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll() ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $did = trim((string) ($row['did'] ?? ''));
        $ref = trim((string) ($row['actor_ref'] ?? ''));
        $profile = json_decode((string) ($row['profile_json'] ?? ''), true);
        $avatar = function_exists('ap_bsky_avatar_public_url')
            ? ap_bsky_avatar_public_url(is_array($profile) ? ($profile['avatar'] ?? null) : null, $did)
            : null;
        if (ap_avatar_is_usable(is_string($avatar) ? $avatar : null)) {
            continue;
        }
        $actor = $ref !== '' ? $ref : ($did !== '' ? 'https://bsky.app/profile/' . $did : '');
        if ($actor === '') {
            continue;
        }
        $pause($actor);
        ap_avatar_queue_bsky($actor, 0);
        $queued++;
    }
    fwrite(STDOUT, "avatar-backfill bsky queued={$queued}\n");
    exit(0);
}

$cutoff = gmdate('c', time() - $staleHours * 3600);
$st = ap_db()->prepare(
    "SELECT actor_id FROM remote_actors
     WHERE actor_id LIKE 'https://%'
       AND (
         icon_source_url IS NULL OR btrim(icon_source_url) = '' OR icon_source_url NOT LIKE 'https://%'
         OR updated_at < ?
       )
     ORDER BY updated_at ASC
     LIMIT ?"
);
$st->bindValue(1, $cutoff);
$st->bindValue(2, $limit, PDO::PARAM_INT);
$st->execute();
foreach ($st->fetchAll() ?: [] as $row) {
    $actor = rtrim(trim((string) (is_array($row) ? ($row['actor_id'] ?? '') : '')), '/');
    if ($actor === '' || ap_avatar_is_bsky_app($actor)) {
        if ($actor !== '') {
            $pause($actor);
            ap_avatar_queue_bsky($actor, 0);
            $queued++;
        }
        continue;
    }
    $pause($actor);
    $before = ap_remote_actor_get($actor);
    $got = ap_remote_actor_ensure($actor, true);
    $icon = is_array($got) ? (string) ($got['icon_source_url'] ?? '') : '';
    if (ap_avatar_is_usable($icon) && function_exists('ap_remote_media_warm_async')) {
        ap_remote_media_warm_async($actor);
    }
    if ($icon !== '' && $icon !== (string) (is_array($before) ? ($before['icon_source_url'] ?? '') : '')) {
        $refreshed++;
    }
}

fwrite(STDOUT, "avatar-backfill refreshed={$refreshed} bsky_queued={$queued} limit={$limit}\n");
