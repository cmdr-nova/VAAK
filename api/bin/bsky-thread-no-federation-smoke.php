<?php
declare(strict_types=1);
/**
 * Replies in Bluesky threads stay Bluesky-side.
 *
 * Report (2026-10-17): a VAAK reply to the Bluesky-only account
 * kevinr.free-dissociation.com reached a separate fediverse account
 * kevinr@masto.free-dissociation.com. The bare-@user mention pass read
 * "@kevinr" out of "@kevinr.free-dissociation.com@bsky.app", resolved it to the
 * most recent remote actor named kevinr, added an AP Mention, and the Create
 * federated. Checks the mention parser and the Bluesky-thread classifier.
 * SQLite in memory; no network.
 */
$api = getenv('VAAK_BSKY_THREAD_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo']->exec('CREATE TABLE remote_actors (actor_id TEXT, username TEXT, host TEXT, updated_at TEXT)');
$GLOBALS['pdo']->exec('CREATE TABLE outbox_notes (id TEXT PRIMARY KEY, in_reply_to TEXT, raw_create_json TEXT)');
$GLOBALS['pdo']->exec("INSERT INTO remote_actors VALUES
    ('https://masto.free-dissociation.com/users/kevinr', 'kevinr', 'masto.free-dissociation.com', '2026-10-17'),
    ('https://example.social/users/alice', 'alice', 'example.social', '2026-10-17')");

function ap_db(): PDO { return $GLOBALS['pdo']; }
function ap_masto_mention_from_actor(string $actorId): array
{
    $user = basename($actorId);
    return ['id' => '9', 'username' => $user, 'url' => $actorId, 'acct' => $user . '@' . parse_url($actorId, PHP_URL_HOST)];
}
// No VAAK mirror mapping for these Bluesky URLs except the one below.
function ap_bsky_local_note_id_for_at_uri(string $uri, int $owner = 0, bool $allowFetch = true): ?string
{
    return str_contains($uri, '/post/mirror1') ? 'https://mkultra.monster/users/cmdr_nova/notes/fedi1' : null;
}

reference_load($api . '/ap-masto-entities.php', [
    'ap_masto_content_with_mentions',
    'ap_masto_repair_at_hash_tags',
    'ap_masto_unglue_bare_handles',
    'ap_masto_unglue_host_mentions',
    'ap_masto_resolve_bare_username',
    'ap_masto_resolve_bsky_handle_actor',
    'ap_masto_linkify_hashtags_escaped',
    'ap_masto_tag_url',
]);
reference_load($api . '/ap-db.php', ['ap_normalize_bridgy_plain_mentions']);
reference_load($api . '/ap-inbox.php', ['ap_reply_parent_is_bluesky_side']);

$checks = 0;
function thread_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}
function mention_urls(string $text): array
{
    return array_map(static fn(array $m): string => (string) $m['url'], ap_masto_content_with_mentions($text)['mentions']);
}

$kevin = 'https://masto.free-dissociation.com/users/kevinr';
thread_check(ap_normalize_bridgy_plain_mentions('@kevinr.free-dissociation.com@bsky.app hi') === '@kevinr.free-dissociation.com hi', 'composer @bsky.app seed is normalized');
thread_check(!in_array($kevin, mention_urls('@kevinr.free-dissociation.com@bsky.app thanks!'), true), 'seeded Bluesky handle does not mention fediverse kevinr');
thread_check(!in_array($kevin, mention_urls('@kevinr.free-dissociation.com thanks!'), true), 'Bluesky handle does not mention fediverse kevinr');
thread_check(!in_array($kevin, mention_urls('cc @kevinr.free-dissociation.com.'), true), 'Bluesky handle before a period does not mention fediverse kevinr');
thread_check(mention_urls('@kevinr thanks') === [$kevin], 'a real bare @kevinr still resolves');
thread_check(mention_urls('thanks @alice.') === ['https://example.social/users/alice'], 'bare @user before a period still resolves');
thread_check(mention_urls('@alice@example.social hi') === ['https://example.social/users/alice'], '@user@host still resolves');

$pdo = $GLOBALS['pdo'];
$ins = $pdo->prepare('INSERT INTO outbox_notes VALUES (?, ?, ?)');
$ins->execute(['https://mkultra.monster/users/cmdr_nova/notes/imported', null, '{"object":{"vaakOrigin":"bluesky"}}']);
$ins->execute(['https://mkultra.monster/users/cmdr_nova/notes/bskyreply', 'https://bsky.app/profile/kevinr.free-dissociation.com/post/3abc', '{"object":{}}']);
$ins->execute(['https://mkultra.monster/users/cmdr_nova/notes/deep', 'https://mkultra.monster/users/cmdr_nova/notes/bskyreply', '{"object":{}}']);
$ins->execute(['https://mkultra.monster/users/cmdr_nova/notes/fedi1', null, '{"object":{"vaakOrigin":"vaak"}}']);
$ins->execute(['https://mkultra.monster/users/cmdr_nova/notes/fedireply', 'https://example.social/users/alice/statuses/1', '{"object":{}}']);

thread_check(ap_reply_parent_is_bluesky_side('https://bsky.app/profile/kevinr.free-dissociation.com/post/3abc'), 'Bluesky-native parent is Bluesky-side');
thread_check(ap_reply_parent_is_bluesky_side('at://did:plc:abc/app.bsky.feed.post/3abc'), 'AT URI parent is Bluesky-side');
thread_check(ap_reply_parent_is_bluesky_side('https://mkultra.monster/users/cmdr_nova/notes/imported'), 'appview-imported note is Bluesky-side');
thread_check(ap_reply_parent_is_bluesky_side('https://mkultra.monster/users/cmdr_nova/notes/deep'), 'local reply further down a Bluesky thread is Bluesky-side');
thread_check(!ap_reply_parent_is_bluesky_side('https://bsky.app/profile/cmdr-nova.example/post/mirror1'), 'Bluesky mirror of a VAAK note federates');
thread_check(!ap_reply_parent_is_bluesky_side('https://mkultra.monster/users/cmdr_nova/notes/fedireply'), 'fediverse thread federates');
thread_check(!ap_reply_parent_is_bluesky_side('https://example.social/users/alice/statuses/1'), 'remote AP parent federates');
thread_check(!ap_reply_parent_is_bluesky_side('https://bsky.brid.gy/r/https://bsky.app/profile/x/post/1'), 'Bridgy Fed parent federates');

$inbox = (string) file_get_contents($api . '/ap-inbox.php');
thread_check(str_contains($inbox, "'skip_fedi' => \$bskySide"), 'compose queues skip_fedi for Bluesky threads');
$worker = (string) file_get_contents($api . '/ap-publish-delivery.php');
thread_check(str_contains($worker, "(\$visibility === 'local' || \$skipFedi)"), 'delivery worker skips ActivityPub fanout for Bluesky threads');

echo "bsky-thread-no-federation-smoke: {$checks} checks passed\n";
