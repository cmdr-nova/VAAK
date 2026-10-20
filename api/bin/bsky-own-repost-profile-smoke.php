<?php
declare(strict_types=1);
/**
 * A Bluesky repost made from VAAK lands on the owner's profile Boosts tab as
 * soon as the action queue finishes it, under the same row key the author-feed
 * import uses, and an undo removes it. No network; SQLite in memory.
 */
$api = getenv('VAAK_BSKY_OWN_REPOST_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');

$GLOBALS['db'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$GLOBALS['db']->exec('CREATE TABLE masto_reblogs (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER, owner_actor_id TEXT, status_id TEXT, boost_status_id TEXT, object_id TEXT, target_actor TEXT, announce_activity_id TEXT, created_at TEXT, UNIQUE(owner_user_id, status_id))');
function ap_db(): PDO { return $GLOBALS['db']; }
function ap_db_default_owner_user_id(): int { return 0; }
function ap_db_owner_actor_id_for_user_id(int $id): string { return $id === 1 ? 'https://mkultra.monster/users/cmdr_nova' : ''; }
function ap_masto_reblog_row_by_status(string $sid, int $owner): ?array
{
    $st = ap_db()->prepare('SELECT * FROM masto_reblogs WHERE owner_user_id = ? AND status_id = ?');
    $st->execute([$owner, $sid]);
    return $st->fetch() ?: null;
}
function ap_bsky_session_row(int $owner): ?array { return $owner === 1 ? ['did' => 'did:plc:self'] : null; }

require_once $api . '/ap-bsky-urls.php';
reference_load($api . '/ap-db.php', ['ap_masto_reblog_add']);
reference_load($api . '/ap-bsky.php', ['ap_bsky_own_repost_status_id', 'ap_bsky_own_repost_profile_sync']);

$checks = 0;
function repost_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}
function rows(): array { return ap_db()->query('SELECT * FROM masto_reblogs ORDER BY id')->fetchAll(); }

$uri = 'at://did:plc:author/app.bsky.feed.post/3abc';
ap_bsky_own_repost_profile_sync(1, $uri, true);
$r = rows();
repost_check(count($r) === 1, 'a finished repost adds one Boosts row');
repost_check($r[0]['status_id'] === 'bsky-repost-' . substr(hash('sha256', 'did:plc:self|' . $uri), 0, 32), 'row uses the author-feed import key');
repost_check($r[0]['object_id'] === 'https://bsky.app/profile/did:plc:author/post/3abc', 'row points at the reposted post');
repost_check($r[0]['target_actor'] === 'https://bsky.app/profile/did:plc:author', 'row names the post author');
repost_check($r[0]['owner_actor_id'] === 'https://mkultra.monster/users/cmdr_nova', 'row belongs to the reposting account');

ap_bsky_own_repost_profile_sync(1, $uri, true);
repost_check(count(rows()) === 1, 'a retry or later import does not add a second row');

ap_bsky_own_repost_profile_sync(2, $uri, true);
ap_bsky_own_repost_profile_sync(1, 'https://bsky.app/profile/x/post/1', true);
repost_check(count(rows()) === 1, 'no session or a non at:// subject writes nothing');

ap_bsky_own_repost_profile_sync(1, $uri, false);
repost_check(rows() === [], 'undoing the repost removes the Boosts row');

$queue = (string) file_get_contents($api . '/ap-action-queue.php');
repost_check(substr_count($queue, 'ap_bsky_own_repost_profile_sync(') === 3, 'action queue syncs the Boosts tab on repost and both undo paths');
$import = (string) file_get_contents($api . '/ap-bsky.php');
repost_check(str_contains($import, '$stable = ap_bsky_own_repost_status_id($selfDid, $uri);'), 'author-feed import shares the row key');

fwrite(STDOUT, "bsky-own-repost-profile-smoke ok checks={$checks}\n");
