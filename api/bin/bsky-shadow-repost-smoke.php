<?php
declare(strict_types=1);
/**
 * Boosting a local post also reposts its Bluesky mirror. The repost must not
 * become a second profile Boosts row next to the VAAK boost. Reposts of
 * mirrors without a VAAK boost (made in the Bluesky app) still get a row.
 * SQLite in memory; no network.
 */
$api = getenv('VAAK_BSKY_SHADOW_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo']->exec('CREATE TABLE masto_reblogs (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER, status_id TEXT,
    boost_status_id TEXT, object_id TEXT, target_actor TEXT, announce_activity_id TEXT, UNIQUE(owner_user_id, status_id))');

$note = 'https://mkultra.monster/users/shana_flux/notes/afcec15ea969bd2c';
$mirror = 'at://did:plc:shana/app.bsky.feed.post/3mirror';
$other = 'at://did:plc:stranger/app.bsky.feed.post/3other';

function ap_db(): PDO { return $GLOBALS['pdo']; }
function ap_bsky_session_row(int $owner): ?array { return $owner === 1 ? ['did' => 'did:plc:valerie'] : null; }
function ap_bsky_own_mirror_note_id_for_uri(string $uri): ?string { return $uri === $GLOBALS['mirror'] ? $GLOBALS['note'] : null; }
function ap_bsky_uris_for_note(string $note): array { return rtrim($note, '/') === $GLOBALS['note'] ? [$GLOBALS['mirror']] : []; }
function ap_bsky_https_url_from_at_uri(string $uri, ?string $h = null): string { return 'https://bsky.app/x/' . md5($uri); }
function ap_bsky_actor_profile_url(string $ref): string { return 'https://bsky.app/profile/' . $ref; }
function ap_masto_reblog_add(string $sid, string $bid, string $obj, ?string $actor, string $ann, ?int $owner = null): array
{
    ap_db()->prepare('INSERT OR REPLACE INTO masto_reblogs (owner_user_id, status_id, boost_status_id, object_id, target_actor, announce_activity_id) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$owner, $sid, $bid, $obj, $actor, $ann]);
    return ['ok' => true];
}

reference_load($api . '/ap-bsky.php', [
    'ap_bsky_own_repost_status_id', 'ap_bsky_own_repost_profile_sync',
    'ap_bsky_repost_shadows_local_boost', 'ap_bsky_drop_shadow_reposts_for_note',
]);

$checks = 0;
function sh_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}
function sh_rows(): int { return (int) ap_db()->query('SELECT COUNT(*) FROM masto_reblogs')->fetchColumn(); }

// No VAAK boost yet: a Bluesky-app repost of the mirror keeps its row.
sh_check(!ap_bsky_repost_shadows_local_boost(1, $mirror), 'no shadow without a VAAK boost');
ap_bsky_own_repost_profile_sync(1, $mirror, true);
sh_check(sh_rows() === 1, 'Bluesky-only repost is listed');

// VAAK boost lands afterwards: the worker drops the earlier Bluesky row.
ap_masto_reblog_add('11274', '9001', $note, 'https://mkultra.monster/users/shana_flux', 'https://mkultra.monster/users/cmdr_nova/announces/x', 1);
sh_check(ap_bsky_drop_shadow_reposts_for_note(1, $note) === 1, 'earlier Bluesky row removed after VAAK boost');
sh_check(sh_rows() === 1, 'only the VAAK boost remains');

// Normal order: VAAK boost first, then the queued Bluesky repost syncs.
sh_check(ap_bsky_repost_shadows_local_boost(1, $mirror), 'mirror repost shadows the VAAK boost');
ap_bsky_own_repost_profile_sync(1, $mirror, true);
sh_check(sh_rows() === 1, 'queued Bluesky repost adds no second row');

// Unrelated Bluesky reposts and other owners are untouched.
sh_check(!ap_bsky_repost_shadows_local_boost(1, $other), 'non-mirror repost is not a shadow');
sh_check(!ap_bsky_repost_shadows_local_boost(2, $mirror), 'another owner has no VAAK boost');
sh_check(ap_bsky_drop_shadow_reposts_for_note(2, $note) === 0, 'owner without Bluesky drops nothing');

// Unrepost still clears a Bluesky row.
ap_bsky_own_repost_profile_sync(1, $other, true);
sh_check(sh_rows() === 2, 'stranger repost listed');
ap_bsky_own_repost_profile_sync(1, $other, false);
sh_check(sh_rows() === 1, 'stranger unrepost removed');

$feedSrc = (string) file_get_contents($api . '/ap-bsky.php');
sh_check(substr_count($feedSrc, 'ap_bsky_repost_shadows_local_boost($ownerUserId,') === 2, 'profile sync and author-feed import both guard');

echo "bsky-shadow-repost-smoke ok ({$checks} checks)\n";
