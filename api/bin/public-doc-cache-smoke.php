<?php
declare(strict_types=1);

/**
 * Public ActivityPub documents, persistent Postgres sessions, and the
 * Bluesky-follow actor index. No Redis and no Postgres. Proves the Pleroma
 * note shape, 304 rules, the skip-scan, and that deploy builds
 * idx_events_action_actor concurrently outside the additive loop.
 */

require_once dirname(__DIR__) . '/ap-public-doc.php';
require_once dirname(__DIR__) . '/ap-bsky.php';
require_once dirname(__DIR__, 2) . '/deploy/remote/migrate.php';

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$noteId = 'https://mkultra.monster/users/cmdr_nova/notes/bc15ca933555c8aa';
$at = 'at://did:plc:example/app.bsky.feed.post/abc';
$note = ap_as2_pleroma_document([
    'type' => 'Note',
    'id' => $noteId,
    'content' => '<p></p>',
    'url' => [
        'https://bsky.app/profile/example/post/abc',
        $noteId,
        ['type' => 'Link', 'rel' => 'alternate', 'href' => $at],
    ],
    'blueskyUri' => $at,
    'blueskyCid' => 'bafyrei',
    'attachment' => [[
        'type' => 'Document',
        'mediaType' => 'video/mp4',
        'url' => 'https://cdn.example/video.mp4',
    ]],
    'alsoKnownAs' => ['at://did:plc:example'],
]);
$check($note['url'] === $noteId, 'note url is the https id, not a list');
$check(($note['blueskyUri'] ?? '') === $at, 'blueskyUri stays on the note');
$check(($note['blueskyCid'] ?? '') === 'bafyrei', 'blueskyCid stays on the note');
$check(($note['content'] ?? '') === '<p>&#8203;</p>', 'empty media text is a zero-width space');
$check(($note['alsoKnownAs'][0] ?? '') === 'at://did:plc:example', 'alsoKnownAs at:// is left in place');
$check(($note['attachment'][0]['url'] ?? '') === 'https://cdn.example/video.mp4', 'attachment url stays https');
$check(!str_contains(json_encode($note['url']), 'at://'), 'url value has no at://');
$again = ap_as2_pleroma_document($note);
$check($again === $note, 'normalizer is idempotent');

$plain = ap_as2_pleroma_document([
    'type' => 'Note',
    'id' => $noteId,
    'content' => '<p>Hello</p>',
    'url' => $noteId,
    'attachment' => [['type' => 'Document', 'url' => 'https://cdn.example/a.jpg']],
]);
$check($plain['content'] === '<p>Hello</p>' && $plain['url'] === $noteId, 'captioned media keeps its text and https url');

$noMedia = ap_as2_pleroma_document([
    'type' => 'Note',
    'id' => $noteId,
    'content' => '<p></p>',
    'url' => $noteId,
]);
$check($noMedia['content'] === '<p></p>', 'empty text without media is unchanged');

$actor = ap_as2_pleroma_document([
    'type' => 'Person',
    'id' => 'https://mkultra.monster/users/cmdr_nova',
    'url' => 'https://mkultra.monster/users/cmdr_nova',
    'alsoKnownAs' => ['at://did:plc:example'],
    'attachment' => [['type' => 'PropertyValue', 'name' => 'Site', 'value' => 'https://example.com']],
]);
$check($actor['url'] === 'https://mkultra.monster/users/cmdr_nova', 'actor url stays the https id');
$check($actor['alsoKnownAs'] === ['at://did:plc:example'], 'actor alsoKnownAs is not rewritten');
$check(($actor['attachment'][0]['value'] ?? '') === 'https://example.com', 'profile fields are not rewritten');

$follow = ['type' => 'Follow', 'object' => 'https://example.com/users/other'];
$check(ap_as2_pleroma_document($follow) === $follow, 'Follow delivery is unchanged');
$add = ['type' => 'Add', 'object' => $noteId, 'target' => 'https://mkultra.monster/users/cmdr_nova/collections/featured'];
$check(ap_as2_pleroma_document($add) === $add, 'Add pin delivery is unchanged');

$featured = ap_as2_pleroma_document([
    'type' => 'OrderedCollection',
    'id' => 'https://mkultra.monster/users/cmdr_nova/collections/featured',
    'orderedItems' => [$noteId],
]);
$check($featured['orderedItems'] === [$noteId], 'featured orderedItems stay note ids');

$check(ap_public_doc_is_shared_visibility('public'), 'public notes are cacheable');
$check(ap_public_doc_is_shared_visibility('unlisted'), 'unlisted notes are cacheable');
$check(ap_public_doc_is_shared_visibility(''), 'missing visibility stays on the public cache');
$check(!ap_public_doc_is_shared_visibility('private'), 'followers-only notes are not publicly cached');
$check(!ap_public_doc_is_shared_visibility('direct'), 'direct notes are not publicly cached');
$check(!ap_public_doc_is_shared_visibility('local'), 'local-only notes are not publicly cached');

$etag = '"abc"';
$modified = 'Thu, 16 Oct 2025 00:00:00 GMT';
$check(ap_public_doc_conditional_status($etag, $modified, '"abc"', null) === 304, 'matching If-None-Match is 304');
$check(ap_public_doc_conditional_status($etag, $modified, '*', 'Wed, 01 Jan 2020 00:00:00 GMT') === 304, 'If-None-Match star is 304');
$check(ap_public_doc_conditional_status($etag, $modified, 'W/"abc"', null) === 304, 'weak If-None-Match matches');
$check(ap_public_doc_conditional_status($etag, $modified, '"other"', $modified) === 200, 'a present If-None-Match wins over If-Modified-Since');
$check(ap_public_doc_conditional_status($etag, $modified, null, $modified) === 304, 'If-Modified-Since equal to Last-Modified is 304');
$check(ap_public_doc_conditional_status($etag, $modified, null, 'Wed, 01 Jan 2020 00:00:00 GMT') === 200, 'older If-Modified-Since is 200');
$check(AP_PUBLIC_DOC_MAX_AGE === 60 && AP_PUBLIC_DOC_CACHE_TTL === 90, 'public cache is 60s and the server copy is 90s');

$proxy = ap_note_attach_bsky_proxy([
    'id' => $noteId,
    'type' => 'Note',
    'url' => $noteId,
    'content' => '<p></p>',
], $at, 'bafyrei');
$check(is_string($proxy['url'] ?? null) && str_starts_with((string) $proxy['url'], 'https://'), 'new Bluesky mirrors store an https url string');
$check(($proxy['blueskyUri'] ?? '') === $at, 'new Bluesky mirrors keep blueskyUri');
$check(!str_contains((string) json_encode($proxy['url']), 'at://'), 'new Bluesky mirrors do not put at:// in url');

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE events (id INTEGER PRIMARY KEY, action_taken TEXT, actor_id TEXT)');
$insert = $db->prepare('INSERT INTO events (action_taken, actor_id) VALUES (?, ?)');
foreach ([
    ['bsky_follow', 'https://bsky.app/profile/did:plc:b'],
    ['bsky_follow', 'https://bsky.app/profile/did:plc:b'],
    ['bsky_follow', 'https://bsky.app/profile/did:plc:a'],
    ['bsky_follow', null],
    ['other', 'https://bsky.app/profile/did:plc:z'],
    ['bsky_follow', 'https://bsky.app/profile/did:plc:a'],
] as $row) {
    $insert->execute($row);
}
$skip = ap_events_distinct_actor_ids_query($db, 'bsky_follow');
sort($skip);
$distinct = $db->query(
    "SELECT DISTINCT actor_id FROM events WHERE action_taken = 'bsky_follow' AND actor_id IS NOT NULL"
)->fetchAll(PDO::FETCH_COLUMN);
sort($distinct);
$check($skip === $distinct, 'skip-scan matches DISTINCT actor ids');
$check(ap_events_distinct_actor_ids_query($db, 'missing') === [], 'an unused action returns no actors');
$check(!in_array('https://bsky.app/profile/did:plc:z', $skip, true), 'other actions stay out of the follow set');

$root = dirname(__DIR__, 2);
$api = dirname(__DIR__);
$schema = (string) file_get_contents($api . '/ap-db.php');
$migrate = (string) file_get_contents($root . '/deploy/remote/migrate.php');
$indexSql = (string) file_get_contents($api . '/migrations/20261016_events_action_actor_postgres.sql');
$inbox = (string) file_get_contents($api . '/ap-inbox.php');
$entities = (string) file_get_contents($api . '/ap-masto-entities.php');
$spec = vaak_migrate_events_action_actor_index_spec();
$flatSql = preg_replace('/\s+/', ' ', $indexSql) ?: '';
$flatCreate = preg_replace('/\s+/', ' ', $spec['create']) ?: $spec['create'];
$check(str_contains($migrate, 'vaak_migrate_events_action_actor_indexes($lock)'), 'migrate.php builds the events index on its autocommit connection');
$check(str_starts_with($spec['create'], 'CREATE INDEX CONCURRENTLY '), 'events index is created concurrently');
$check(str_starts_with($spec['drop'], 'DROP INDEX CONCURRENTLY '), 'invalid events index is dropped concurrently');
$check($spec['create'] === 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_action_actor ON events (action_taken, actor_id) WHERE actor_id IS NOT NULL', 'events index matches the partial key');
$check(str_contains($flatSql, $flatCreate), 'SQL file matches the deploy statement');
$check(vaak_migrate_events_action_actor_index_statements('skip') === [], 'a valid events index issues no DDL');
$check(vaak_migrate_events_action_actor_index_statements('create') === [$spec['create']], 'first deploy only creates the events index');
$check(
    vaak_migrate_events_action_actor_index_statements('rebuild') === [$spec['drop'], $spec['create']],
    'an invalid events index is rebuilt concurrently'
);
$files = array_map('basename', vaak_migrate_deploy_sql_files($api));
$check(!in_array('20261016_events_action_actor_postgres.sql', $files, true), 'concurrent events index stays out of the additive loop');
$check(!preg_match('/CREATE\s+INDEX\b[^;]*idx_events_action_actor/', $schema), 'a page load does not build idx_events_action_actor');
$check(str_contains($schema, 'PDO::ATTR_PERSISTENT'), 'Postgres reuses connections');
$check(str_contains($schema, 'DISCARD ALL'), 'a reused session drops leftover state');
$check(str_contains($schema, 'ap_db_postgres_reset_session'), 'each request resets the Postgres session');
$check(str_contains($entities, 'ap_events_distinct_actor_ids('), 'suggestions use the skip-scan helper');
$check(
    substr_count($entities, "AND action_taken IN ('local_accept_followback', ?)") === 2
        && substr_count($entities, 'AND (target_actor = ? OR target_actor = ?)') >= 2,
    'Bluesky follow notification lookups still filter on the target actor'
);
$check(str_contains($inbox, 'ap_as2_pleroma_document($activity)'), 'delivery normalizes a copy before signing');
$check(!str_contains($indexSql, 'password') && !str_contains($indexSql, 'postgres://'), 'index file has no credentials');

final class EventsIndexTxPdo extends PDO
{
    public function inTransaction(): bool
    {
        return true;
    }
}
$blocked = false;
try {
    vaak_migrate_events_action_actor_indexes(new EventsIndexTxPdo('sqlite::memory:'));
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'events action actor index must not run inside a transaction');
}
$check($blocked, 'concurrent events index build refuses an open transaction');

if ($failures !== []) {
    fwrite(STDERR, "public-doc-cache-smoke failed\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "public-doc-cache-smoke ok checks={$checks}\n";
