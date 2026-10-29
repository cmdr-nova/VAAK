<?php
declare(strict_types=1);
/**
 * A self-reply whose parent is still in the publish queue must wait for the
 * parent's Bluesky mirror (deferred, retried) instead of being dropped as
 * "Reply parent is Fediverse-only". Replies run before top-level posts in the
 * publish queue, so a reply posted within a minute of its parent hits this.
 * SQLite in memory; no network.
 */
$api = getenv('VAAK_BSKY_REPLY_PENDING_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo']->exec("CREATE TABLE ap_publish_delivery_queue (id INTEGER PRIMARY KEY, note_id TEXT UNIQUE, status TEXT)");
$GLOBALS['pdo']->exec("CREATE TABLE bsky_crosspost_retries (id INTEGER PRIMARY KEY, note_id TEXT UNIQUE, status TEXT)");

function ap_db(): PDO { return $GLOBALS['pdo']; }
// No parent mapping exists yet in every case below.
function ap_bsky_resolve_strong_ref(string $ref, int $owner): ?array { return null; }
function ap_bsky_result_is_rate_limited(?array $r): bool { return false; }

reference_load($api . '/ap-bsky.php', [
    'ap_bsky_is_local_note_id', 'ap_bsky_local_parent_mirror_pending',
    'ap_bsky_crosspost_status_inner', 'ap_bsky_crosspost_should_retry',
]);

$checks = 0;
function rp_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}
function rp_mirror(string $parent): array
{
    return ap_bsky_crosspost_status_inner(1, 'reply', 'public', [], '', $parent, null,
        'https://mkultra.monster/users/cmdr_nova/notes/child', ['pds_host' => 'https://pds.example'], true);
}

$parent = 'https://mkultra.monster/users/cmdr_nova/notes/b06652c77410175f';

// Parent still queued for publish delivery: defer and retry.
ap_db()->exec("INSERT INTO ap_publish_delivery_queue (note_id, status) VALUES ('$parent', 'pending')");
$res = rp_mirror($parent);
rp_check(empty($res['ok']) && !empty($res['deferred']), 'queued parent defers the reply');
rp_check($res['error'] === 'Reply parent mirror pending', 'deferred reply names the wait');
rp_check(ap_bsky_crosspost_should_retry($res), 'deferred reply is retried');

// Parent delivered but its own mirror is in the retry queue: still defer.
ap_db()->exec("UPDATE ap_publish_delivery_queue SET status = 'succeeded'");
ap_db()->exec("INSERT INTO bsky_crosspost_retries (note_id, status) VALUES ('$parent/', 'processing')");
$res = rp_mirror($parent);
rp_check(!empty($res['deferred']), 'parent mirror retry defers the reply');

// Parent finished and never mirrored (e.g. followers-only): stays Fediverse-only.
ap_db()->exec("UPDATE bsky_crosspost_retries SET status = 'dead'");
$res = rp_mirror($parent);
rp_check(!empty($res['ok']) && !empty($res['skipped']) && $res['error'] === 'Reply parent is Fediverse-only', 'settled parent stays fedi-only');
rp_check(!ap_bsky_crosspost_should_retry($res), 'fedi-only reply is not retried');

// Remote parents never defer, even with a matching queue row.
$remote = 'https://mastodon.example/users/a/statuses/1';
ap_db()->exec("INSERT INTO ap_publish_delivery_queue (note_id, status) VALUES ('$remote', 'pending')");
$res = rp_mirror($remote);
rp_check(!empty($res['skipped']) && empty($res['deferred']), 'remote parent is not deferred');

echo "bsky-reply-parent-pending-smoke: ok ({$checks} checks)\n";
