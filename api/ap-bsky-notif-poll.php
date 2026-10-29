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

// A normal run takes seconds. A run past this is hung, and while it holds the
// lock every cron run only logs "busy", so no Bluesky notification gets in.
const BSKY_NOTIF_POLL_MAX_SECONDS = 600;

/**
 * Kill the lock holder when it has outlived the cap. The holder writes
 * "pid start" into the lock file; a holder without that line is left alone.
 *
 * @return string why the lock is still busy, or '' when the holder was killed
 */
function bsky_notif_poll_reap_stale_holder($lockFh): string
{
    rewind($lockFh);
    $line = trim((string) stream_get_contents($lockFh));
    if (!preg_match('/^(\d+) (\d+)$/', $line, $m)) {
        return 'holder unknown';
    }
    $pid = (int) $m[1];
    $age = time() - (int) $m[2];
    if ($age <= BSKY_NOTIF_POLL_MAX_SECONDS + 60) {
        return sprintf('pid=%d age=%ds', $pid, $age);
    }
    $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    if (!str_contains($cmd, 'ap-bsky-notif-poll') || !function_exists('posix_kill')) {
        return sprintf('pid=%d age=%ds stale, not killed', $pid, $age);
    }
    posix_kill($pid, 9);
    fwrite(STDOUT, sprintf("[%s] bsky_notif_poll killed hung pid=%d age=%ds\n", gmdate('c'), $pid, $age));
    return '';
}

$lockPath = '/tmp/ap-bsky-notif-poll.lock';
$lockFh = @fopen($lockPath, 'c+');
if ($lockFh === false) {
    fwrite(STDOUT, sprintf("[%s] bsky_notif_poll busy (lock file unavailable)\n", gmdate('c')));
    exit(0);
}
if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
    $busy = bsky_notif_poll_reap_stale_holder($lockFh);
    $locked = false;
    for ($i = 0; $busy === '' && $i < 10 && !$locked; $i++) {
        usleep(500000);
        $locked = flock($lockFh, LOCK_EX | LOCK_NB);
    }
    if (!$locked) {
        fwrite(STDOUT, sprintf("[%s] bsky_notif_poll busy %s\n", gmdate('c'), $busy !== '' ? $busy : 'after kill'));
        exit(0);
    }
}
ftruncate($lockFh, 0);
rewind($lockFh);
fwrite($lockFh, getmypid() . ' ' . time());
fflush($lockFh);
// Backstop inside the run itself. Each XRPC call has its own timeout, so the
// alarm fires within one call's length of the cap.
if (function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function (): void {
        fwrite(STDERR, sprintf("[%s] bsky_notif_poll timeout after %ds\n", gmdate('c'), BSKY_NOTIF_POLL_MAX_SECONDS));
        exit(2);
    });
    pcntl_alarm(BSKY_NOTIF_POLL_MAX_SECONDS);
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
        ap_bsky_budget_begin(AP_BSKY_NOTIF_POLL_BUDGET);
        $res = ap_bsky_poll_notifications($userId, $limit);
        ap_bsky_budget_clear();
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
        ap_bsky_budget_begin(AP_BSKY_NOTIF_POLL_BUDGET);
        $hide = ap_bsky_refresh_hide_set($userId, false);
        ap_bsky_budget_clear();
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
                ap_bsky_budget_begin(AP_BSKY_NOTIF_POLL_BUDGET);
                $hide = ap_bsky_refresh_hide_set($uid, false);
                ap_bsky_budget_clear();
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
