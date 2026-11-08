<?php
declare(strict_types=1);
/**
 * Fediverse and Bluesky copies of one post (bsky_post_links.fediverse_id,
 * written by Wafrn) share like / bookmark / boost state. Undoing on one copy
 * queues the undo for the other copy only when this account has it there.
 * SQLite in memory; no network.
 */
require __DIR__ . '/migration-reference-functions.php';
$api = dirname(__DIR__);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE bsky_post_links (bsky_uri TEXT PRIMARY KEY, bsky_cid TEXT, fediverse_id TEXT, ap_object_id TEXT, seen_at TEXT)');
$pdo->exec('CREATE TABLE masto_favourites (owner_user_id INTEGER, status_id TEXT, object_id TEXT, target_actor TEXT, like_activity_id TEXT)');
$pdo->exec('CREATE TABLE masto_bookmarks (owner_user_id INTEGER, status_id TEXT, object_id TEXT)');
$pdo->exec('CREATE TABLE masto_reblogs (owner_user_id INTEGER, status_id TEXT, object_id TEXT)');
$GLOBALS['pdo'] = $pdo;
$GLOBALS['queued'] = [];

function ap_db(): PDO { return $GLOBALS['pdo']; }
function ap_bsky_post_link_by_uri(string $uri): ?array
{
    $st = ap_db()->prepare('SELECT * FROM bsky_post_links WHERE bsky_uri = ?');
    $st->execute([$uri]);
    return $st->fetch() ?: null;
}
function ap_bsky_post_link_by_fedi(string $id): ?array
{
    $st = ap_db()->prepare('SELECT * FROM bsky_post_links WHERE fediverse_id IN (?, ?) OR ap_object_id IN (?, ?)');
    $st->execute([$id, $id . '/', $id, $id . '/']);
    return $st->fetch() ?: null;
}
function ap_bsky_https_url_from_at_uri(string $uri, ?string $handle): ?string { return null; }
function ap_action_queue_enqueue(int $owner, string $platform, string $kind, string $target, bool $desired, array $payload): array
{
    $GLOBALS['queued'][] = [$platform, $kind, $target, $desired, $payload];
    return ['ok' => true, 'id' => 1, 'revision' => 1];
}
function ap_masto_favourite_remove(string $sid, ?int $owner = null, ?string $oid = null): ?array
{
    ap_db()->prepare('DELETE FROM masto_favourites WHERE owner_user_id = ? AND status_id = ?')->execute([$owner, $sid]);
    return null;
}
function ap_masto_bookmark_remove(string $sid, ?int $owner = null, ?string $oid = null): void
{
    ap_db()->prepare('DELETE FROM masto_bookmarks WHERE owner_user_id = ? AND status_id = ?')->execute([$owner, $sid]);
}
reference_load($api . '/ap-wafrn-crosspost.php', ['ap_wafrn_post_identity']);
reference_load($api . '/ap-action-queue.php', ['ap_interaction_twin_keys', 'ap_interaction_twin_undo']);

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    if (!$ok) { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

$fedi = 'https://mu.social/fediverse/post/abc';
$at = 'at://did:plc:wafrn/app.bsky.feed.post/xyz';
$atHash = 'bsky:' . substr(hash('sha256', $at), 0, 32);
$pdo->prepare('INSERT INTO bsky_post_links VALUES (?, ?, ?, NULL, ?)')->execute([$at, 'cid1', $fedi, '2026-11-08']);

check(ap_interaction_twin_keys($fedi) === [$at], 'fediverse id finds the Bluesky twin');
check(ap_interaction_twin_keys($fedi . '/') === [$at], 'trailing slash ignored');
check(ap_interaction_twin_keys('https://mu.social/fediverse/activity/post/abc') === [$at], 'Wafrn activity id finds the twin');
check(ap_interaction_twin_keys('bsky:' . $at) === [$fedi], 'at:// finds the fediverse twin');
check(ap_interaction_twin_keys('https://mu.social/fediverse/post/other') === [], 'unlinked post has no twin');
check(ap_interaction_twin_keys('https://bsky.app/profile/x/post/1') === [], 'bsky.app permalinks are not fediverse ids');

// Liked on Bluesky (Home), unliked from the fediverse mention card.
$pdo->prepare('INSERT INTO masto_favourites VALUES (1, ?, ?, NULL, NULL)')->execute([$atHash, 'https://bsky.app/profile/x/post/xyz']);
$keys = ap_interaction_twin_undo(1, 'like', $fedi);
check($keys === [$at], 'undo returns twin keys for the client');
check(count($GLOBALS['queued']) === 1 && $GLOBALS['queued'][0][0] === 'bsky' && $GLOBALS['queued'][0][1] === 'like'
    && $GLOBALS['queued'][0][2] === $at && $GLOBALS['queued'][0][3] === false, 'Bluesky unlike queued for the twin');
check((int) $pdo->query('SELECT COUNT(*) FROM masto_favourites')->fetchColumn() === 0, 'local Bluesky like row cleared');

// Another account's like is never touched.
$GLOBALS['queued'] = [];
$pdo->prepare('INSERT INTO masto_favourites VALUES (2, ?, ?, NULL, NULL)')->execute([$atHash, $at]);
ap_interaction_twin_undo(1, 'like', $fedi);
check($GLOBALS['queued'] === [], 'no twin like for this account means nothing queued');

// Liked on the fediverse copy, unliked from the Bluesky card.
$pdo->prepare('INSERT INTO masto_favourites VALUES (1, ?, ?, ?, ?)')->execute(['5511', $fedi, 'https://mu.social/fediverse/blog/gg', 'https://mkultra.monster/likes/1']);
ap_interaction_twin_undo(1, 'like', $at);
$q = $GLOBALS['queued'][0] ?? [];
check(($q[0] ?? '') === 'fedi' && ($q[1] ?? '') === 'like' && ($q[3] ?? true) === false
    && ($q[4]['status_id'] ?? '') === '5511' && ($q[4]['target_actor'] ?? '') === 'https://mu.social/fediverse/blog/gg',
    'fediverse unlike queued with the row the worker needs for Undo');

// Bookmarks and boosts.
$GLOBALS['queued'] = [];
$pdo->prepare('INSERT INTO masto_bookmarks VALUES (1, ?, ?)')->execute(['5511', $fedi]);
ap_interaction_twin_undo(1, 'bookmark', $at);
check((int) $pdo->query('SELECT COUNT(*) FROM masto_bookmarks WHERE owner_user_id = 1')->fetchColumn() === 0, 'fediverse twin bookmark removed');
$pdo->prepare('INSERT INTO masto_reblogs VALUES (1, ?, ?)')->execute(['5511', $fedi]);
ap_interaction_twin_undo(1, 'boost', $at);
$q = end($GLOBALS['queued']) ?: [];
check(($q[0] ?? '') === 'fedi' && ($q[1] ?? '') === 'boost' && ($q[3] ?? true) === false, 'fediverse twin boost undo queued');
$GLOBALS['queued'] = [];
ap_interaction_twin_undo(1, 'boost', $fedi);
check($GLOBALS['queued'] === [], 'fediverse unboost already undoes the Bluesky repost itself');

if ($failures) exit(1);
echo "interaction-twin-smoke ok\n";
