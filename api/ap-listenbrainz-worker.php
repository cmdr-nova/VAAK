<?php
/**
 * Poll ListenBrainz for users who opted in (Settings > Profile) and store
 * what they are playing. Profile pages read the stored row only.
 *
 *   php ap-listenbrainz-worker.php [--limit=20]
 *
 * Cron: deploy/mkultra-ap-listenbrainz (every minute, flock, Nice 10).
 * Each run handles at most --limit due users and spaces requests at least
 * 1.1 s apart (ListenBrainz asks for one call per second). A 429 ends the run.
 * Kill switch: VAAK_LISTENBRAINZ=0. Missing table: exits quietly.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-net-guard.php';
require_once __DIR__ . '/ap-listenbrainz.php';

if (getenv('VAAK_LISTENBRAINZ') === '0') {
    exit(0);
}

$limit = 20;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d{1,3})$/', $arg, $m)) {
        $limit = max(1, min(100, (int) $m[1]));
    }
}

$db = ap_db();
if (!ap_listenbrainz_table_ready($db)) {
    exit(0);
}

$now = time();
$st = $db->prepare(
    'SELECT l.* FROM ap_listenbrainz_links l
     INNER JOIN ap_users u ON u.id = l.owner_user_id
     WHERE l.next_fetch_at <= ? AND u.disabled_at IS NULL
     ORDER BY l.next_fetch_at ASC, l.owner_user_id ASC
     LIMIT ' . $limit
);
$st->execute([$now]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$lastCall = 0.0;
$pause = static function () use (&$lastCall): void {
    $wait = 1.1 - (microtime(true) - $lastCall);
    if ($lastCall > 0 && $wait > 0) {
        usleep((int) ceil($wait * 1000000));
    }
};
$get = static function (string $path) use (&$lastCall, $pause): array {
    $pause();
    $lastCall = microtime(true);
    return ap_listenbrainz_http_get($path);
};

$polled = 0;
$errors = 0;
foreach ($rows as $row) {
    $userId = (int) $row['owner_user_id'];
    $username = (string) $row['lb_username'];
    $result = ap_listenbrainz_poll($row, time(), $get, $pause);
    try {
        ap_listenbrainz_store_poll($db, $userId, $username, $result['update']);
    } catch (Throwable $e) {
        error_log('[listenbrainz] store failed for user ' . $userId . ': ' . $e->getMessage());
    }
    $polled++;
    if (($result['update']['last_error'] ?? '') !== '') {
        $errors++;
    }
    if ($result['rate_limited'] > 0) {
        fwrite(STDERR, '[listenbrainz] rate limited, reset in ' . $result['rate_limited'] . "s\n");
        break;
    }
}
if ($polled > 0 && $errors > 0) {
    fwrite(STDERR, '[listenbrainz] polled ' . $polled . ', errors ' . $errors . "\n");
}
