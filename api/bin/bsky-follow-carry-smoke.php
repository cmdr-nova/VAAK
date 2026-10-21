<?php
declare(strict_types=1);
/**
 * When a VAAK user creates a Bluesky account, only their accepted local
 * followers who have Bluesky connected and opted in get a queued Bluesky
 * follow, and the resulting Bluesky twin follower lists once under "All".
 * No network; SQLite in memory; the action queue is recorded.
 */
$api = getenv('VAAK_BSKY_FOLLOW_CARRY_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');

$GLOBALS['db'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
$GLOBALS['queued'] = [];
function ap_action_queue_enqueue(int $owner, string $platform, string $kind, string $target, bool $desired, array $payload): array
{
    $GLOBALS['queued'][] = [$owner, $platform, $kind, $target, $desired, $payload['source'] ?? ''];
    return ['ok' => true, 'id' => count($GLOBALS['queued'])];
}

require_once $api . '/ap-bsky-urls.php';
reference_load($api . '/ap-bsky.php', ['ap_bsky_follow_carry_enabled', 'ap_bsky_follow_carry_set', 'ap_bsky_follow_carry_enqueue', 'ap_bsky_twin_local_actor_id', 'ap_bsky_collapse_twin_rows']);

$checks = 0;
function carry_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

$newDid = 'did:plc:shana';
// Missing table (before the deploy migration): reads as off, queues nothing.
carry_check(ap_bsky_follow_carry_enabled(1) === false, 'missing table reads as off');
carry_check(ap_bsky_follow_carry_set(1, true)['ok'] === false, 'missing table cannot be switched on');
carry_check(ap_bsky_follow_carry_enqueue(5, $newDid) === 0, 'missing table queues nothing');

$db = ap_db();
$db->exec('CREATE TABLE ap_users (id INTEGER PRIMARY KEY, actor_id TEXT NOT NULL, disabled_at TEXT)');
$db->exec('CREATE TABLE followers (owner_actor_id TEXT, actor_id TEXT)');
$db->exec('CREATE TABLE bsky_sessions (owner_user_id BIGINT PRIMARY KEY, handle TEXT NOT NULL, did TEXT NOT NULL)');
$db->exec((string) preg_replace('/GRANT[^;]*;/', '', (string) file_get_contents($api . '/migrations/20261021_bsky_follow_carry_postgres.sql')));

$u = static fn (string $k): string => 'https://mkultra.monster/users/' . $k;
foreach ([1 => 'cmdr_nova', 2 => 'no_bsky', 3 => 'opted_out', 4 => 'disabled', 5 => 'shana_flux', 6 => 'not_follower', 7 => 'pending'] as $id => $key) {
    $db->prepare('INSERT INTO ap_users (id, actor_id, disabled_at) VALUES (?, ?, ?)')->execute([$id, $u($key), $id === 4 ? '2026-10-01' : null]);
}
foreach ([1, 3, 4, 5, 6] as $id) {
    $db->prepare('INSERT INTO bsky_sessions (owner_user_id, handle, did) VALUES (?, ?, ?)')->execute([$id, 'u' . $id . '.bsky.mkultra.monster', $id === 5 ? $newDid : 'did:plc:u' . $id]);
}
// Accepted local followers of shana_flux, plus one remote follower.
foreach (['cmdr_nova', 'no_bsky', 'opted_out', 'disabled'] as $key) {
    $db->prepare('INSERT INTO followers (owner_actor_id, actor_id) VALUES (?, ?)')->execute([$u('shana_flux'), $u($key)]);
}
$db->prepare('INSERT INTO followers (owner_actor_id, actor_id) VALUES (?, ?)')->execute([$u('shana_flux'), 'https://remote.example/users/someone']);
// not_follower follows someone else.
$db->prepare('INSERT INTO followers (owner_actor_id, actor_id) VALUES (?, ?)')->execute([$u('cmdr_nova'), $u('not_follower')]);

carry_check(ap_bsky_follow_carry_enabled(1) === false, 'off by default');
carry_check(ap_bsky_follow_carry_enqueue(5, $newDid) === 0, 'nobody opted in, nothing queued');
carry_check($GLOBALS['queued'] === [], 'queue untouched while everyone is off');

foreach ([1, 2, 4, 6] as $id) {
    carry_check(ap_bsky_follow_carry_set($id, true)['ok'] === true, "user {$id} can opt in");
}
carry_check(ap_bsky_follow_carry_set(1, true)['ok'] === true, 'opting in twice is fine');
carry_check(ap_bsky_follow_carry_enabled(1) === true, 'opt-in reads back');

carry_check(ap_bsky_follow_carry_enqueue(5, $newDid) === 1, 'only the opted-in, connected, active local follower is queued');
carry_check($GLOBALS['queued'] === [[1, 'bsky', 'follow', $newDid, true, 'local_follow_carry']], 'cmdr_nova queues a Bluesky follow of the new DID');

carry_check(ap_bsky_follow_carry_set(1, false)['ok'] === true && ap_bsky_follow_carry_enabled(1) === false, 'opting out removes the row');
$GLOBALS['queued'] = [];
carry_check(ap_bsky_follow_carry_enqueue(5, $newDid) === 0 && $GLOBALS['queued'] === [], 'opted-out follower is skipped');
carry_check(ap_bsky_follow_carry_enqueue(5, 'not-a-did') === 0, 'a non-DID is refused');

// Twin dedupe: cmdr_nova (local) and her Bluesky account follow shana_flux.
carry_check(ap_bsky_twin_local_actor_id('https://bsky.app/profile/U1.bsky.mkultra.monster') === $u('cmdr_nova'), 'handle URL maps to the local twin');
carry_check(ap_bsky_twin_local_actor_id('https://bsky.app/profile/did%3Aplc%3Au1') === $u('cmdr_nova'), 'DID URL maps to the local twin');
carry_check(ap_bsky_twin_local_actor_id('did:plc:u1') === $u('cmdr_nova'), 'bare DID maps to the local twin');
carry_check(ap_bsky_twin_local_actor_id('https://bsky.app/profile/stranger.bsky.social') === '', 'unlinked Bluesky account has no twin');
carry_check(ap_bsky_twin_local_actor_id($u('cmdr_nova')) === '', 'a local actor is not a Bluesky ref');
$listed = [
    ['actor_id' => $u('cmdr_nova'), 'host' => 'mkultra.monster'],
    ['actor_id' => 'https://bsky.app/profile/u1.bsky.mkultra.monster', 'host' => 'bsky.app'],
    ['actor_id' => 'https://bsky.app/profile/u6.bsky.mkultra.monster', 'host' => 'bsky.app'],
    ['actor_id' => 'https://bsky.app/profile/stranger.bsky.social', 'host' => 'bsky.app'],
];
$collapsed = array_column(ap_bsky_collapse_twin_rows($listed), 'actor_id');
carry_check($collapsed === [$u('cmdr_nova'), 'https://bsky.app/profile/u6.bsky.mkultra.monster', 'https://bsky.app/profile/stranger.bsky.social'], 'All drops only the Bluesky twin whose local account is listed');
carry_check(count(ap_bsky_collapse_twin_rows(array_slice($listed, 1))) === 3, 'a Bluesky follower whose local account does not follow stays');
$entities = (string) file_get_contents($api . '/ap-masto-entities.php');
carry_check(substr_count($entities, '$twin = ap_bsky_twin_local_actor_id($fa);') === 2, 'Mentions list and badge both skip twin follows');
$rust = (string) file_get_contents(dirname($api) . '/rust/vaak-worker/src/notif/mod.rs');
carry_check(str_contains($rust, 'bsky_twin_local_actor(&actor_id, &twins)'), 'Rust unread count skips twin follows too');

$src = (string) file_get_contents($api . '/ap-bsky.php');
carry_check(str_contains($src, '$carried = ap_bsky_follow_carry_enqueue($ownerUserId, $did);'), 'account creation runs the carry');
$admin = (string) file_get_contents($api . '/ap-admin.php');
carry_check(str_contains($admin, "name=\"action\" value=\"bsky_follow_carry_save\""), 'ATmosphere has the opt-in form');

fwrite(STDOUT, "bsky-follow-carry-smoke ok checks={$checks}\n");
