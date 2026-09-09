<?php
/**
 * CLI: poll connected Bluesky accounts and ingest mentions/quotes/reposts/likes
 * into VAAK notifications (mentions table → Ice Cubes /api/v1/notifications).
 *
 * Usage:
 *   php ap-bsky-notif-poll.php
 *   php ap-bsky-notif-poll.php --limit=30
 *   php ap-bsky-notif-poll.php --user=1
 *
 * Cron (every minute):
 *   AP_DB_DSN=pgsql:dbname=novalandia
 *   VAAK_FEATURE_BLUESKY_TAB=1
 *   * * * * * www-data /usr/bin/php /srv/mkultra/html/api/ap-bsky-notif-poll.php >> /var/log/mkultra/ap-bsky-notifs.log 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$limit = 50;
$userId = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(100, (int) $m[1]));
    }
    if (preg_match('/^--user=(\d+)$/', $arg, $m)) {
        $userId = max(0, (int) $m[1]);
    }
}

$lockPath = '/tmp/ap-bsky-notif-poll.lock';
$lockFh = @fopen($lockPath, 'c+');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, sprintf("[%s] bsky_notif_poll busy\n", gmdate('c')));
    exit(0);
}

try {
    if (!defined('AP_INBOX_LIB_ONLY')) {
        define('AP_INBOX_LIB_ONLY', true);
    }
    require_once __DIR__ . '/ap-db.php';
    require_once __DIR__ . '/ap-r2.php';
    require_once __DIR__ . '/ap-bsky.php';
    // Soft-load for type mapping + webpush (ingest uses these when pushing).
    if (is_file(__DIR__ . '/ap-masto-entities.php')) {
        require_once __DIR__ . '/ap-masto-entities.php';
    }
    if (is_file(__DIR__ . '/ap-webpush.php')) {
        require_once __DIR__ . '/ap-webpush.php';
    }

    if (!ap_bsky_tab_enabled()) {
        fwrite(STDOUT, sprintf("[%s] bsky_notif_poll skipped (bluesky_tab off)\n", gmdate('c')));
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
        exit(0);
    }

    if ($userId > 0) {
        $res = ap_bsky_poll_notifications($userId, $limit);
        fwrite(STDOUT, sprintf(
            "[%s] bsky_notif_poll user=%d ok=%d fetched=%d inserted=%d skipped=%d pushed=%d backfill=%d err=%s\n",
            gmdate('c'),
            $userId,
            !empty($res['ok']) ? 1 : 0,
            (int) ($res['fetched'] ?? 0),
            (int) ($res['inserted'] ?? 0),
            (int) ($res['skipped'] ?? 0),
            (int) ($res['pushed'] ?? 0),
            !empty($res['backfill']) ? 1 : 0,
            !empty($res['ok']) ? '' : (string) ($res['error'] ?? 'fail')
        ));
        if (!empty($res['errors']) && is_array($res['errors'])) {
            foreach ($res['errors'] as $e) {
                fwrite(STDOUT, sprintf("[%s] bsky_notif_poll detail %s\n", gmdate('c'), $e));
            }
        }
        $hide = ap_bsky_refresh_hide_set($userId, false);
        fwrite(STDOUT, sprintf(
            "[%s] bsky_hide_refresh user=%d ok=%d blocks=%d mutes=%d list=%d cached=%d\n",
            gmdate('c'),
            $userId,
            !empty($hide['ok']) ? 1 : 0,
            (int) ($hide['blocks'] ?? 0),
            (int) ($hide['mutes'] ?? 0),
            (int) ($hide['list_members'] ?? 0),
            !empty($hide['cached']) ? 1 : 0
        ));
    } else {
        $res = ap_bsky_poll_all_notifications($limit);
        fwrite(STDOUT, sprintf(
            "[%s] bsky_notif_poll users=%d inserted=%d pushed=%d ok=%d\n",
            gmdate('c'),
            (int) ($res['users'] ?? 0),
            (int) ($res['inserted'] ?? 0),
            (int) ($res['pushed'] ?? 0),
            !empty($res['ok']) ? 1 : 0
        ));
        foreach ($res['errors'] ?? [] as $e) {
            fwrite(STDOUT, sprintf("[%s] bsky_notif_poll err %s\n", gmdate('c'), $e));
        }
        try {
            $rows = ap_db()->query('SELECT owner_user_id FROM bsky_sessions ORDER BY owner_user_id ASC')->fetchAll();
            foreach ($rows as $r) {
                $uid = (int) ($r['owner_user_id'] ?? 0);
                if ($uid < 1) {
                    continue;
                }
                $hide = ap_bsky_refresh_hide_set($uid, false);
                fwrite(STDOUT, sprintf(
                    "[%s] bsky_hide_refresh user=%d ok=%d blocks=%d mutes=%d list=%d cached=%d\n",
                    gmdate('c'),
                    $uid,
                    !empty($hide['ok']) ? 1 : 0,
                    (int) ($hide['blocks'] ?? 0),
                    (int) ($hide['mutes'] ?? 0),
                    (int) ($hide['list_members'] ?? 0),
                    !empty($hide['cached']) ? 1 : 0
                ));
            }
        } catch (Throwable $e) {
            fwrite(STDERR, sprintf("[%s] bsky_hide_refresh error %s\n", gmdate('c'), $e->getMessage()));
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] bsky_notif_poll error %s\n", gmdate('c'), $e->getMessage()));
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    exit(1);
}

flock($lockFh, LOCK_UN);
fclose($lockFh);
exit(0);
