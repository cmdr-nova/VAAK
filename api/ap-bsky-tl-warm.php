<?php
/**
 * CLI: warm durable Bluesky post cache (bsky_posts) from each connected session's
 * home timeline (and author feed when home is empty).
 *
 * Usage:
 *   php ap-bsky-tl-warm.php
 *   php ap-bsky-tl-warm.php --limit=40
 *   php ap-bsky-tl-warm.php --user=1
 *   php ap-bsky-tl-warm.php --sleep-ms=200 --dry-run
 *
 * Cron (every 5 minutes) — see /etc/cron.d/mkultra-ap-bsky-tl-warm
 *   AP_DB_DSN=pgsql:dbname=novalandia VAAK_FEATURE_BLUESKY_TAB=1
 *   php /srv/mkultra/html/api/ap-bsky-tl-warm.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$limit = 40;
$userId = 0;
$sleepMs = 200;
$dryRun = false;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(5, min(50, (int) $m[1]));
    }
    if (preg_match('/^--user=(\d+)$/', $arg, $m)) {
        $userId = max(0, (int) $m[1]);
    }
    if (preg_match('/^--sleep-ms=(\d+)$/', $arg, $m)) {
        $sleepMs = max(0, min(5000, (int) $m[1]));
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

$lockPath = '/tmp/ap-bsky-tl-warm.lock';
$lockFh = @fopen($lockPath, 'c+');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, sprintf("[%s] bsky_tl_warm busy\n", gmdate('c')));
    exit(0);
}

try {
    if (!defined('AP_INBOX_LIB_ONLY')) {
        define('AP_INBOX_LIB_ONLY', true);
    }
    require_once __DIR__ . '/ap-db.php';
    require_once __DIR__ . '/ap-bsky.php';

    if (!ap_bsky_tab_enabled()) {
        fwrite(STDOUT, sprintf("[%s] bsky_tl_warm skipped (bluesky_tab off)\n", gmdate('c')));
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
        exit(0);
    }

    ap_bsky_posts_migrate();

    $owners = [];
    if ($userId > 0) {
        $owners = [['owner_user_id' => $userId]];
    } else {
        $owners = ap_db()->query(
            'SELECT owner_user_id FROM bsky_sessions ORDER BY owner_user_id ASC'
        )->fetchAll() ?: [];
    }

    $users = 0;
    $upserted = 0;
    $errors = 0;

    foreach ($owners as $row) {
        $uid = (int) ($row['owner_user_id'] ?? 0);
        if ($uid < 1) {
            continue;
        }
        $users++;
        try {
            $tl = ap_bsky_get_timeline($uid, $limit, null);
            if (empty($tl['ok'])) {
                fwrite(STDOUT, sprintf(
                    "[%s] bsky_tl_warm user=%d timeline_fail err=%s\n",
                    gmdate('c'),
                    $uid,
                    (string) ($tl['error'] ?? 'fail')
                ));
                $errors++;
                continue;
            }
            $feed = is_array($tl['feed'] ?? null) ? $tl['feed'] : [];
            $source = (string) ($tl['source'] ?? 'home');
            if ($feed === [] && $source !== 'author') {
                $own = ap_bsky_get_author_feed($uid, $limit, null);
                if (!empty($own['ok']) && is_array($own['feed'] ?? null)) {
                    $feed = $own['feed'];
                    $source = 'author';
                    $tl = $own;
                }
            }
            // Also sample pinned/saved custom feeds so Home mix includes feed content.
            $feedExtra = 0;
            $prefsRaw = ap_bsky_get_preferences($uid);
            $prefs = ap_bsky_parse_feed_prefs(
                !empty($prefsRaw['ok']) && is_array($prefsRaw['preferences'] ?? null)
                    ? $prefsRaw['preferences']
                    : []
            );
            $savedUris = [];
            foreach (array_slice($prefs['pinned'] ?? [], 0, 8) as $pin) {
                if (!is_array($pin)) {
                    continue;
                }
                $t = (string) ($pin['type'] ?? '');
                $v = (string) ($pin['value'] ?? '');
                if (($t === 'feed' || $t === 'list') && str_starts_with($v, 'at://')) {
                    $savedUris[] = $v;
                }
            }
            foreach (array_slice($prefs['savedFeedUris'] ?? [], 0, 6) as $uri) {
                if (is_string($uri) && str_starts_with($uri, 'at://')) {
                    $savedUris[] = $uri;
                }
            }
            $savedUris = array_values(array_unique($savedUris));
            $samplePerFeed = max(5, min(15, (int) floor($limit / 2)));
            foreach (array_slice($savedUris, 0, 4) as $feedUri) {
                $cf = ap_bsky_get_custom_feed($uid, $feedUri, $samplePerFeed, null);
                if (empty($cf['ok']) || empty($cf['feed']) || !is_array($cf['feed'])) {
                    continue;
                }
                foreach ($cf['feed'] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if (!$dryRun) {
                        ap_bsky_index_feed_item($item, $uid);
                    }
                    $feedExtra++;
                }
            }
            $n = 0;
            if (!$dryRun) {
                foreach ($feed as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    ap_bsky_index_feed_item($item, $uid);
                    $n++;
                }
                // Refresh short head file cache for snappy next paint.
                $cacheKey = ap_bsky_tl_cache_key($uid, 'following', null, false);
                ap_bsky_tl_cache_put(
                    $cacheKey,
                    $feed,
                    isset($tl['cursor']) && is_string($tl['cursor']) ? $tl['cursor'] : null,
                    $source
                );
                ap_bsky_schedule_hide_refresh($uid);
                // Invalidate Home ranked cache so the next paint picks up fresh Bluesky mix.
                foreach ([
                    '/var/lib/mkultra/ap/admin-tl-cache',
                    sys_get_temp_dir() . '/vaak-admin-tl-cache',
                ] as $tlDir) {
                    if (!is_dir($tlDir)) {
                        continue;
                    }
                    foreach (glob($tlDir . '/tl_home_*.json') ?: [] as $f) {
                        @unlink($f);
                    }
                }
            } else {
                $n = count($feed);
            }
            $upserted += $n + $feedExtra;
            fwrite(STDOUT, sprintf(
                "[%s] bsky_tl_warm user=%d source=%s following=%d feeds=%d saved_feeds=%d%s\n",
                gmdate('c'),
                $uid,
                $source,
                $n,
                $feedExtra,
                count($savedUris),
                $dryRun ? ' dry-run' : ''
            ));
        } catch (Throwable $e) {
            $errors++;
            fwrite(STDERR, sprintf("[%s] bsky_tl_warm user=%d error %s\n", gmdate('c'), $uid, $e->getMessage()));
        }
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    fwrite(STDOUT, sprintf(
        "[%s] bsky_tl_warm done users=%d upserted=%d errors=%d%s\n",
        gmdate('c'),
        $users,
        $upserted,
        $errors,
        $dryRun ? ' dry-run' : ''
    ));
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] bsky_tl_warm fatal %s\n", gmdate('c'), $e->getMessage()));
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    exit(1);
}

flock($lockFh, LOCK_UN);
fclose($lockFh);
exit(0);
