<?php
declare(strict_types=1);

/**
 * Queue and rollback gate for the PHP cleanup workers.
 *
 * Uses a private SQLite database and the pure media-key decision. It does not
 * open the production DSN, delete an R2 object, or move ownership to Rust.
 */
if (trim((string) getenv('AP_DB_DSN')) !== '') {
    fwrite(STDERR, "cleanup-worker-rollback: refusing to run with AP_DB_DSN set\n");
    exit(2);
}

define('AP_MAINTAIN_LIB_ONLY', true);
define('AP_ACCOUNT_DELETE_LIB_ONLY', true);
require_once dirname(__DIR__) . '/ap-maintain.php';
require_once dirname(__DIR__) . '/ap-r2.php';
require_once dirname(__DIR__) . '/ap-account-delete-worker.php';

function fail(string $message): never
{
    fwrite(STDERR, "cleanup-worker-rollback: FAIL: {$message}\n");
    exit(1);
}

function count_rows(PDO $db, string $table): int
{
    return (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

$decisions = [
    ['cache/post-media/abc.jpg', false, 'cache'],
    ['mkultra/cache/avatars/actor.webp', false, 'cache'],
    ['mkultra/media/local.jpg', false, 'refuse'],
    ['mkultra/media/local.jpg', true, 'local'],
    ['mkultra/media/cache/post-media/hidden.jpg', false, 'refuse'],
    ['mkultra/cache/../media/secret.jpg', false, 'refuse'],
    ['', false, 'refuse'],
];
foreach ($decisions as [$key, $allowLocal, $expect]) {
    $got = ap_r2_cleanup_key_decision($key, $allowLocal);
    if ($got !== $expect) {
        fail("key {$key} decided {$got}, expected {$expect}");
    }
}

$plan = ap_maintain_queue_retention_plan();
$tables = array_column($plan, 'table');
foreach ([
    'ap_publish_delivery_queue',
    'ap_fanout_delivery_queue',
    'ap_media_warm_queue',
    'bsky_actor_refresh_queue',
] as $required) {
    if (!in_array($required, $tables, true)) {
        fail('retention plan is missing ' . $required);
    }
}

$db = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('CREATE TABLE ap_publish_delivery_queue (
    id INTEGER PRIMARY KEY,
    status TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    note_id TEXT NOT NULL
)');
$db->exec('CREATE TABLE bsky_actor_refresh_queue (
    owner_user_id INTEGER NOT NULL,
    actor_ref TEXT NOT NULL,
    status TEXT NOT NULL,
    queued_at TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, actor_ref)
)');
$db->exec('CREATE TABLE ap_users (
    id INTEGER PRIMARY KEY,
    status TEXT NOT NULL,
    updated_at TEXT NOT NULL
)');
ap_maintain_ensure_cold_archive($db, false);

$old = '2026-09-01T00:00:00+00:00';
$fresh = '2026-10-07T00:00:00+00:00';
$cutoff = '2026-09-30T00:00:00+00:00';
$db->exec("INSERT INTO ap_publish_delivery_queue (id, status, updated_at, note_id) VALUES
    (1, 'succeeded', '{$old}', 'old-ok'),
    (2, 'failed', '{$old}', 'old-fail'),
    (3, 'pending', '{$old}', 'old-pending'),
    (4, 'succeeded', '{$fresh}', 'fresh-ok')");
$db->exec("INSERT INTO bsky_actor_refresh_queue (owner_user_id, actor_ref, status, queued_at) VALUES
    (9, 'did:plc:old', 'succeeded', '{$old}'),
    (9, 'did:plc:pending', 'pending', '{$old}')");
$db->exec("INSERT INTO ap_users (id, status, updated_at) VALUES (1, 'succeeded', '{$old}')");

$beforeQueue = count_rows($db, 'ap_publish_delivery_queue');
$beforeBsky = count_rows($db, 'bsky_actor_refresh_queue');
$beforeUsers = count_rows($db, 'ap_users');
$db->beginTransaction();
$rolledArchive = ap_maintain_archive_expired($db, 'ap_publish_delivery_queue', 'updated_at', ['succeeded', 'failed'], $cutoff, false);
$db->prepare('DELETE FROM ap_publish_delivery_queue WHERE status IN (?, ?) AND updated_at < ?')
    ->execute(['succeeded', 'failed', $cutoff]);
$db->rollBack();
if ($rolledArchive < 1) {
    fail('rollback pass archived nothing');
}
if (count_rows($db, 'ap_publish_delivery_queue') !== $beforeQueue
    || count_rows($db, 'ap_cold_archive') !== 0) {
    fail('rollback left queue or cold-archive rows behind');
}

$archived = ap_maintain_archive_expired($db, 'ap_publish_delivery_queue', 'updated_at', ['succeeded', 'failed'], $cutoff, false);
$bskyArchived = ap_maintain_archive_expired($db, 'bsky_actor_refresh_queue', 'queued_at', ['succeeded', 'failed'], $cutoff, false);
$refused = ap_maintain_archive_expired($db, 'ap_users', 'updated_at', ['succeeded', 'failed'], $cutoff, false);
if ($archived !== 2 || $bskyArchived !== 1 || $refused !== 0) {
    fail("archive counts publish={$archived} bsky={$bskyArchived} refused={$refused}");
}
$db->prepare('DELETE FROM ap_publish_delivery_queue WHERE status IN (?, ?) AND updated_at < ?')
    ->execute(['succeeded', 'failed', $cutoff]);
$db->prepare('DELETE FROM bsky_actor_refresh_queue WHERE status IN (?, ?) AND queued_at < ?')
    ->execute(['succeeded', 'failed', $cutoff]);

$left = $db->query('SELECT note_id FROM ap_publish_delivery_queue ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
if ($left !== ['old-pending', 'fresh-ok']) {
    fail('publish queue kept the wrong rows: ' . implode(',', $left));
}
$bskyLeft = (int) $db->query("SELECT COUNT(*) FROM bsky_actor_refresh_queue WHERE actor_ref = 'did:plc:pending'")->fetchColumn();
if ($bskyLeft !== 1 || count_rows($db, 'bsky_actor_refresh_queue') !== 1) {
    fail('pending Bluesky refresh row was removed');
}
if (count_rows($db, 'ap_users') !== $beforeUsers) {
    fail('disallowed table was archived or deleted');
}
$cold = $db->query('SELECT source_table, source_id FROM ap_cold_archive ORDER BY source_table, source_id')->fetchAll();
$coldIds = array_map(static fn (array $row): string => $row['source_table'] . ':' . $row['source_id'], $cold);
$expectCold = [
    'ap_publish_delivery_queue:1',
    'ap_publish_delivery_queue:2',
    'bsky_actor_refresh_queue:9:did:plc:old',
];
if ($coldIds !== $expectCold) {
    fail('cold archive contents: ' . implode(',', $coldIds));
}
if ($beforeBsky !== 2) {
    fail('fixture did not insert both Bluesky rows');
}

$rel = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$rel->exec('CREATE TABLE followers (owner_actor_id TEXT, actor_id TEXT)');
$rel->exec('CREATE TABLE following (owner_actor_id TEXT, actor_id TEXT)');
$rel->exec('CREATE TABLE actor_profile (actor_key TEXT)');
$gone = 'https://mkultra.monster/users/gone';
$kept = 'https://mkultra.monster/users/kept';
$other = 'https://mkultra.monster/users/other';
$rel->prepare('INSERT INTO followers (owner_actor_id, actor_id) VALUES (?, ?), (?, ?)')
    ->execute([$gone, $other, $kept, $other]);
$rel->prepare('INSERT INTO following (owner_actor_id, actor_id) VALUES (?, ?), (?, ?)')
    ->execute([$gone, $other, $kept, $other]);
$rel->prepare('INSERT INTO actor_profile (actor_key) VALUES (?), (?)')->execute(['gone', 'kept']);
$rel->beginTransaction();
ap_account_delete_local_relationships($rel, $gone, 'gone');
$rel->rollBack();
if (count_rows($rel, 'followers') !== 2 || count_rows($rel, 'following') !== 2 || count_rows($rel, 'actor_profile') !== 2) {
    fail('account relationship rollback did not restore rows');
}
ap_account_delete_local_relationships($rel, $gone, 'gone');
$followerLeft = (int) $rel->query('SELECT COUNT(*) FROM followers WHERE owner_actor_id = ' . $rel->quote($kept))->fetchColumn();
$followingLeft = (int) $rel->query('SELECT COUNT(*) FROM following WHERE owner_actor_id = ' . $rel->quote($kept))->fetchColumn();
$profileLeft = (int) $rel->query("SELECT COUNT(*) FROM actor_profile WHERE actor_key = 'kept'")->fetchColumn();
$goneLeft = count_rows($rel, 'followers') + count_rows($rel, 'following') + count_rows($rel, 'actor_profile')
    - $followerLeft - $followingLeft - $profileLeft;
if ($followerLeft !== 1 || $followingLeft !== 1 || $profileLeft !== 1 || $goneLeft !== 0) {
    fail('account cleanup removed another account or kept the deleted one');
}

$proc = proc_open(
    [PHP_BINARY, dirname(__DIR__) . '/ap-account-delete-worker.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    ['AP_DB_DSN' => '', 'AP_ALLOW_SQLITE' => '']
);
if (!is_resource($proc)) {
    fail('could not start the account-delete worker');
}
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($proc);
if ($exit !== 1) {
    fail('account-delete worker without a user id exited ' . $exit);
}

echo "cleanup-worker-rollback: PASS (queue archive matches retention delete, rollback restores both, media keys stay in cache, account relationships stay isolated)\n";
