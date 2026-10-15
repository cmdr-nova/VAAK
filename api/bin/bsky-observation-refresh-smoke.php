<?php
declare(strict_types=1);

/**
 * Owner/post observation writes stay cheap.
 * SQLite only. A touch inside the 10-minute window does not rewrite
 * last_seen_at. The batch sync only considers posts seen inside that window,
 * skips a fresh observation, and uses ON CONFLICT DO UPDATE … WHERE.
 * The Rust writer uses the same window and the same conflict predicate.
 */

require_once __DIR__ . '/migration-reference-functions.php';

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$root = dirname(__DIR__, 2);
$api = dirname(__DIR__);
$GLOBALS['observation_db'] = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function ap_db(): PDO
{
    return $GLOBALS['observation_db'];
}

function ap_db_driver(?PDO $db = null): string
{
    return 'sqlite';
}

reference_load($api . '/ap-bsky.php', [
    'ap_bsky_observation_fresh_seconds',
    'ap_bsky_observation_stamp',
    'ap_bsky_post_observation_touch',
    'ap_bsky_post_observations_migrate',
    'ap_bsky_post_observations_refresh',
    'ap_bsky_post_observations_refresh_batch',
]);

$nowUnix = strtotime('2026-10-15T23:30:00+00:00');
$check(is_int($nowUnix) && $nowUnix > 0, 'fixed clock parses');
$now = ap_bsky_observation_stamp((int) $nowUnix);
$stale = ap_bsky_observation_stamp((int) $nowUnix - 700);
$check(ap_bsky_observation_fresh_seconds() === 600, 'fresh window is 10 minutes');
$check($now === '2026-10-15T23:30:00+00:00', 'stamp matches PHP gmdate c in UTC');

$db = ap_db();
$db->exec(
    'CREATE TABLE bsky_posts (
        bsky_uri TEXT PRIMARY KEY,
        owner_user_id INTEGER,
        seen_at TEXT NOT NULL
    )'
);
ap_bsky_post_observations_migrate($db);

$insertPost = $db->prepare('INSERT INTO bsky_posts (bsky_uri, owner_user_id, seen_at) VALUES (?, ?, ?)');
$read = $db->prepare('SELECT first_seen_at, last_seen_at FROM bsky_post_observations WHERE bsky_uri = ? AND owner_user_id = ?');

ap_bsky_post_observation_touch('at://did:plc:fixture/app.bsky.feed.post/touch', 1, $db);
$read->execute(['at://did:plc:fixture/app.bsky.feed.post/touch', 1]);
$first = $read->fetch();
$check(is_array($first) && ($first['first_seen_at'] ?? '') === ($first['last_seen_at'] ?? '') && ($first['last_seen_at'] ?? '') !== '', 'first touch inserts one observation');
ap_bsky_post_observation_touch('at://did:plc:fixture/app.bsky.feed.post/touch', 1, $db);
$read->execute(['at://did:plc:fixture/app.bsky.feed.post/touch', 1]);
$second = $read->fetch();
$check($second === $first, 'a second touch inside the window does not rewrite the row');
ap_bsky_post_observation_touch('not-an-at-uri', 1, $db);
ap_bsky_post_observation_touch('at://did:plc:fixture/app.bsky.feed.post/touch', 0, $db);
$count = (int) $db->query('SELECT COUNT(*) FROM bsky_post_observations')->fetchColumn();
$check($count === 1, 'a bad uri or owner does not insert');

$wall = time();
$wallNow = ap_bsky_observation_stamp($wall);
$wallStale = ap_bsky_observation_stamp($wall - 700);
$db->prepare('UPDATE bsky_post_observations SET last_seen_at = ? WHERE bsky_uri = ?')->execute([
    $wallStale,
    'at://did:plc:fixture/app.bsky.feed.post/touch',
]);
ap_bsky_post_observation_touch('at://did:plc:fixture/app.bsky.feed.post/touch', 1, $db);
$read->execute(['at://did:plc:fixture/app.bsky.feed.post/touch', 1]);
$bumped = $read->fetch();
$check(
    is_array($bumped)
        && ($bumped['first_seen_at'] ?? '') === ($first['first_seen_at'] ?? '')
        && ($bumped['last_seen_at'] ?? '') !== $wallStale
        && ($bumped['last_seen_at'] ?? '') >= $wallNow,
    'a stale observation is bumped and keeps first_seen_at'
);

$insertPost->execute(['at://did:plc:fixture/app.bsky.feed.post/recent', 1, $now]);
$insertPost->execute(['at://did:plc:fixture/app.bsky.feed.post/old', 1, $stale]);
$insertPost->execute(['at://did:plc:fixture/app.bsky.feed.post/fresh', 1, $now]);
$db->prepare(
    'INSERT INTO bsky_post_observations (bsky_uri, owner_user_id, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?)'
)->execute(['at://did:plc:fixture/app.bsky.feed.post/fresh', 1, $stale, $now]);
$insertPost->execute(['at://did:plc:fixture/app.bsky.feed.post/rewrite', 1, $now]);
$db->prepare(
    'INSERT INTO bsky_post_observations (bsky_uri, owner_user_id, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?)'
)->execute(['at://did:plc:fixture/app.bsky.feed.post/rewrite', 1, '2020-01-01T00:00:00+00:00', $stale]);

$step = ap_bsky_post_observations_refresh_batch($db, '', 10, (int) $nowUnix);
$written = $db->query(
    "SELECT bsky_uri, first_seen_at, last_seen_at FROM bsky_post_observations ORDER BY bsky_uri"
)->fetchAll();
$byUri = [];
foreach ($written as $row) {
    $byUri[(string) $row['bsky_uri']] = $row;
}
$check(($step['written'] ?? 0) === 2, 'batch writes only the recent missing row and the stale row');
$check(isset($byUri['at://did:plc:fixture/app.bsky.feed.post/recent']), 'a recent post without an observation is inserted');
$check(!isset($byUri['at://did:plc:fixture/app.bsky.feed.post/old']), 'a post outside the seen window is not backfilled');
$check(
    ($byUri['at://did:plc:fixture/app.bsky.feed.post/fresh']['last_seen_at'] ?? '') === $now
        && ($byUri['at://did:plc:fixture/app.bsky.feed.post/fresh']['first_seen_at'] ?? '') === $stale,
    'a fresh observation is left unchanged'
);
$check(
    ($byUri['at://did:plc:fixture/app.bsky.feed.post/rewrite']['first_seen_at'] ?? '') === '2020-01-01T00:00:00+00:00'
        && ($byUri['at://did:plc:fixture/app.bsky.feed.post/rewrite']['last_seen_at'] ?? '') === $now,
    'a stale observation takes the post seen stamp and keeps first_seen_at'
);
$again = ap_bsky_post_observations_refresh_batch($db, '', 10, (int) $nowUnix);
$check(($again['written'] ?? -1) === 0 && ($again['scanned'] ?? -1) === 0, 'a second batch in the same window writes nothing');

$paged = 0;
$db->exec('DELETE FROM bsky_posts');
$db->exec('DELETE FROM bsky_post_observations');
for ($i = 0; $i < 3; $i++) {
    $insertPost->execute(['at://did:plc:fixture/app.bsky.feed.post/page' . $i, 1, $now]);
}
$paged = ap_bsky_post_observations_refresh($db, 2, 5, (int) $nowUnix);
$check($paged === 3, 'refresh walks batches until the recent posts are covered');

$php = (string) file_get_contents($api . '/ap-bsky.php');
$rust = (string) file_get_contents($root . '/rust/vaak-worker/src/bsky_observations.rs');
$check(str_contains($php, 'ON CONFLICT (bsky_uri, owner_user_id) DO UPDATE'), 'PHP touch and batch use the primary key conflict target');
$check(str_contains($php, 'WHERE bsky_post_observations.last_seen_at < ?'), 'PHP touch drops a no-op update');
$check(str_contains($php, 'WHERE bsky_post_observations.last_seen_at < excluded.last_seen_at'), 'PHP batch drops a no-op update');
$check(str_contains($php, 'LIMIT ?') && str_contains($php, 'NOT EXISTS'), 'PHP batch is bounded and skips a fresh row');
$check(str_contains($rust, 'OBSERVATION_FRESH_SECS: i64 = 600'), 'Rust uses the same 10-minute window');
$check(str_contains($rust, 'ON CONFLICT (bsky_uri, owner_user_id) DO UPDATE'), 'Rust uses the same conflict target');
$check(str_contains($rust, 'SET last_seen_at = excluded.last_seen_at'), 'Rust writes last_seen_at only');
$check(str_contains($rust, 'bsky_post_observations.last_seen_at <'), 'Rust drops a no-op update');
$check(str_contains($rust, 'NOT EXISTS') && str_contains($rust, 'LIMIT $4') && str_contains($rust, 'RETURNING bsky_uri'), 'Rust batch matches the PHP page');
$check(str_contains($rust, '.replace(\'Z\', "+00:00")'), 'Rust stamps sort like PHP gmdate c');

if ($failures !== []) {
    fwrite(STDERR, "bsky-observation-refresh-smoke failed\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "bsky-observation-refresh-smoke ok checks={$checks}\n";
