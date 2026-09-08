<?php
/**
 * CLI: periodically warm remote avatar + header caches into R2.
 *
 * Targets (in priority order, deduped):
 *   1. Accounts we follow
 *   2. Recent Create/Announce event actors
 *   3. remote_actors rows missing icon or with stale cache
 *
 * Usage:
 *   php ap-media-warm-batch.php
 *   php ap-media-warm-batch.php --limit=40 --stale-days=3 --sleep-ms=200
 *   php ap-media-warm-batch.php --force   # re-fetch even if cache fresh
 *
 * Cron (every 6 hours) — see /etc/cron.d/mkultra-ap-media
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
require_once __DIR__ . '/ap-r2.php';
require_once __DIR__ . '/ap-inbox.php';

$limit = 50;
$staleDays = 3;
$sleepMs = 200;
$force = false;
$eventsScan = 120;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(200, (int) $m[1]));
        continue;
    }
    if (preg_match('/^--stale-days=(\d+)$/', $arg, $m)) {
        $staleDays = max(0, min(30, (int) $m[1]));
        continue;
    }
    if (preg_match('/^--sleep-ms=(\d+)$/', $arg, $m)) {
        $sleepMs = max(0, min(5000, (int) $m[1]));
        continue;
    }
    if (preg_match('/^--events=(\d+)$/', $arg, $m)) {
        $eventsScan = max(20, min(500, (int) $m[1]));
        continue;
    }
}

function warm_batch_log(string $msg): void
{
    fwrite(STDOUT, '[' . gmdate('c') . '] ' . $msg . "\n");
}

/**
 * @return list<string>
 */
function warm_batch_collect_actors(int $eventsScan, int $staleDays): array
{
    $seen = [];
    $out = [];
    $add = static function (string $actorId) use (&$seen, &$out): void {
        $actorId = rtrim(trim($actorId), '/');
        if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
            return;
        }
        if (str_contains($actorId, 'mkultra.monster')) {
            return;
        }
        if (isset($seen[$actorId])) {
            return;
        }
        $seen[$actorId] = true;
        $out[] = $actorId;
    };

    foreach (ap_following_list() as $f) {
        $add((string) ($f['actor_id'] ?? ''));
    }

    $lim = (int) $eventsScan;
    $st = ap_db()->query(
        "SELECT actor_id FROM events
         WHERE type IN ('Create', 'Announce') AND actor_id IS NOT NULL AND actor_id != ''
         GROUP BY actor_id
         ORDER BY MAX(id) DESC
         LIMIT {$lim}"
    );
    foreach ($st->fetchAll() as $r) {
        $add((string) ($r['actor_id'] ?? ''));
    }

    // Actors with no icon metadata yet (cheap fill)
    foreach (ap_db()->query(
        "SELECT actor_id FROM remote_actors
         WHERE (icon_source_url IS NULL OR icon_source_url = '')
         ORDER BY updated_at DESC LIMIT 80"
    ) as $r) {
        $add((string) ($r['actor_id'] ?? ''));
    }

    // Refresh older profile metadata too, even when its media blob is still
    // present. This keeps names, handles, and changed avatar/header URLs from
    // going stale indefinitely.
    $cutoff = gmdate('c', time() - max(1, $staleDays) * 86400);
    $stale = ap_db()->prepare(
        'SELECT actor_id FROM remote_actors WHERE updated_at < ? ORDER BY updated_at ASC LIMIT 200'
    );
    $stale->execute([$cutoff]);
    foreach ($stale->fetchAll() as $r) {
        $add((string) ($r['actor_id'] ?? ''));
    }

    return $out;
}

/**
 * True if avatar cache is missing or older than $staleDays (or force).
 */
function warm_batch_needs(string $actorId, string $kind, int $staleDays, bool $force): bool
{
    if ($force) {
        return true;
    }
    $cached = ap_remote_media_get($actorId, $kind);
    if (!$cached) {
        return true;
    }
    if ($staleDays <= 0) {
        return false;
    }
    $fetched = strtotime((string) ($cached['fetched_at'] ?? '')) ?: 0;
    return (time() - $fetched) >= ($staleDays * 86400);
}

$actors = warm_batch_collect_actors($eventsScan, $staleDays);
warm_batch_log(sprintf(
    'start candidates=%d limit=%d stale_days=%d force=%s sleep_ms=%d',
    count($actors),
    $limit,
    $staleDays,
    $force ? 'yes' : 'no',
    $sleepMs
));

$attempted = 0;
$avatarOk = 0;
$headerOk = 0;
$skipped = 0;
$noMedia = 0; // actor has no icon/header in AS2 (not a transport failure)
$failed = 0;

foreach ($actors as $actorId) {
    if ($attempted >= $limit) {
        break;
    }
    // Metadata refresh is intentionally separate from media freshness.
    if (!$force && function_exists('ap_remote_actor_ensure')) {
        try {
            ap_remote_actor_ensure($actorId, true);
        } catch (Throwable $e) {
            warm_batch_log('actor_refresh_failed actor=' . $actorId . ' error=' . $e->getMessage());
        }
    }
    $needA = warm_batch_needs($actorId, 'avatar', $staleDays, $force);
    $needH = warm_batch_needs($actorId, 'header', $staleDays, $force);
    if (!$needA && !$needH) {
        $skipped++;
        continue;
    }
    $attempted++;
    $aUrl = null;
    $hUrl = null;
    try {
        if ($needA) {
            $aUrl = ap_remote_media_ensure($actorId, 'avatar', $force);
            if ($aUrl) {
                $avatarOk++;
            } else {
                $meta = ap_remote_actor_get($actorId);
                if (is_array($meta) && !empty($meta['icon_source_url'])) {
                    $failed++; // had a URL but download/cache failed
                } else {
                    $noMedia++; // actor publishes no icon — not an error
                }
            }
        }
        if ($needH) {
            $hUrl = ap_remote_media_ensure($actorId, 'header', $force);
            if ($hUrl) {
                $headerOk++;
            }
        }
        $host = parse_url($actorId, PHP_URL_HOST) ?: '?';
        if (!$needA) {
            $aLabel = 'skip';
        } elseif ($aUrl) {
            $aLabel = 'ok';
        } else {
            $meta = ap_remote_actor_get($actorId);
            $aLabel = (is_array($meta) && !empty($meta['icon_source_url'])) ? 'fail' : 'none';
        }
        warm_batch_log(sprintf(
            'warm %s avatar=%s header=%s',
            $host . parse_url($actorId, PHP_URL_PATH),
            $aLabel,
            $hUrl ? 'ok' : ($needH ? 'none' : 'skip')
        ));
    } catch (Throwable $e) {
        $failed++;
        warm_batch_log('error ' . $actorId . ' ' . $e->getMessage());
    }
    if ($sleepMs > 0) {
        usleep($sleepMs * 1000);
    }
}

warm_batch_log(sprintf(
    'done attempted=%d avatar_ok=%d header_ok=%d skipped_fresh=%d no_remote_media=%d failed=%d remaining_candidates=%d',
    $attempted,
    $avatarOk,
    $headerOk,
    $skipped,
    $noMedia,
    $failed,
    max(0, count($actors) - $attempted - $skipped)
));

exit($failed > 0 && $avatarOk === 0 ? 1 : 0);
