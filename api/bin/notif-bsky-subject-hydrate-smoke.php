<?php
declare(strict_types=1);
/**
 * Bluesky like/repost notifications on posts VAAK never cached.
 *
 * 1. The notification poller warms every missing subject with one batched
 *    public getPosts call, so the row stores the real text instead of
 *    "liked your Bluesky post", and the owner's own appview-only posts are
 *    imported as local notes at once.
 * 2. At paint, a liked quote post carries its own media and the quoted post
 *    (with media) from the cache. Paint never calls AppView.
 * SQLite in memory; network calls are stubs that count.
 */
$api = getenv('VAAK_NOTIF_HYDRATE_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');
const AP_BSKY_PUBLIC_API = 'https://public.api.bsky.app';

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo']->exec('CREATE TABLE bsky_posts (bsky_uri TEXT PRIMARY KEY, author_handle TEXT, raw_json TEXT)');

$GLOBALS['xrpc'] = [];
$GLOBALS['imported'] = [];
$self = 'did:plc:me';
$GLOBALS['remote'] = [
    'at://did:plc:me/app.bsky.feed.post/ownreply' => ['uri' => 'at://did:plc:me/app.bsky.feed.post/ownreply', 'author' => ['did' => $self, 'handle' => 'me.test'], 'record' => ['text' => 'my appview reply']],
    'at://did:plc:bob/app.bsky.feed.post/bobpost' => ['uri' => 'at://did:plc:bob/app.bsky.feed.post/bobpost', 'author' => ['did' => 'did:plc:bob', 'handle' => 'bob.test'], 'record' => ['text' => 'bob text']],
];

function ap_db(): PDO { return $GLOBALS['pdo']; }
function ap_bsky_posts_migrate(): void {}
function ap_bsky_session_row(int $owner): ?array { return ['did' => 'did:plc:me']; }
function ap_bsky_local_note_id_for_at_uri(string $uri, int $owner = 0, bool $allowFetch = true): ?string { return null; }
function ap_bsky_xrpc(string $host, string $nsid, string $method, array $q, $body = null, $tok = null, int $timeout = 8): array
{
    $GLOBALS['xrpc'][] = $nsid . ':' . $q['uris'];
    $posts = [];
    foreach (explode(',', $q['uris']) as $u) {
        if (isset($GLOBALS['remote'][$u])) $posts[] = $GLOBALS['remote'][$u];
    }
    return ['ok' => true, 'json' => ['posts' => $posts]];
}
function ap_bsky_post_upsert_from_feed_item(array $post, ?int $owner = null): void
{
    ap_db()->prepare('INSERT OR REPLACE INTO bsky_posts VALUES (?, ?, ?)')
        ->execute([$post['uri'], $post['author']['handle'] ?? '', json_encode($post)]);
}
function ap_bsky_post_item_by_uri(string $uri): ?array
{
    $st = ap_db()->prepare('SELECT raw_json FROM bsky_posts WHERE bsky_uri = ?');
    $st->execute([$uri]);
    $raw = $st->fetchColumn();
    return is_string($raw) ? ['post' => json_decode($raw, true)] : null;
}
function ap_bsky_import_own_post_as_local(int $owner, array $post, int $depth = 0): array
{
    $GLOBALS['imported'][] = $post['uri'];
    return ['ok' => true];
}
function ap_masto_animated_media_from_url(string $url, string $preview): array
{
    return ['type' => 'image', 'url' => $url, 'preview_url' => $preview, 'mediaType' => 'image/jpeg'];
}
function ap_bsky_https_url_from_at_uri(string $at, ?string $handle = null): string { return 'https://bsky.app/profile/x/post/' . basename($at); }
function ap_bsky_actor_profile_url(string $h): string { return 'https://bsky.app/profile/' . $h; }

reference_load($api . '/ap-bsky.php', ['ap_bsky_notification_subjects_warm', 'ap_bsky_cached_at_uri_for_web_url']);
reference_load($api . '/ap-masto-entities.php', ['ap_masto_notif_bsky_subject_enrich', 'ap_masto_bsky_media_attachments', 'ap_masto_bsky_trend_status']);

$checks = 0;
function hydrate_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

// Cached own post that the backfill has not imported yet.
ap_bsky_post_upsert_from_feed_item(['uri' => 'at://did:plc:me/app.bsky.feed.post/cachedown', 'author' => ['did' => $self, 'handle' => 'me.test'], 'record' => ['text' => 'cached']]);
$notifs = [
    ['reason' => 'like', 'reasonSubject' => 'at://did:plc:me/app.bsky.feed.post/ownreply'],
    ['reason' => 'repost', 'reasonSubject' => 'at://did:plc:bob/app.bsky.feed.post/bobpost'],
    ['reason' => 'like', 'reasonSubject' => 'at://did:plc:me/app.bsky.feed.post/cachedown'],
    ['reason' => 'follow'],
];
hydrate_check(ap_bsky_notification_subjects_warm(1, $notifs) === 2, 'two missing subjects fetched');
hydrate_check(count($GLOBALS['xrpc']) === 1 && str_starts_with($GLOBALS['xrpc'][0], 'app.bsky.feed.getPosts:'), 'one batched getPosts call');
hydrate_check(ap_bsky_post_item_by_uri('at://did:plc:me/app.bsky.feed.post/ownreply') !== null, 'missing subject is now cached');
hydrate_check(in_array('at://did:plc:me/app.bsky.feed.post/ownreply', $GLOBALS['imported'], true), 'own appview post imported right away');
hydrate_check(in_array('at://did:plc:me/app.bsky.feed.post/cachedown', $GLOBALS['imported'], true), 'cached own post without a twin imported');
hydrate_check(!in_array('at://did:plc:bob/app.bsky.feed.post/bobpost', $GLOBALS['imported'], true), 'other people\'s posts are only cached');
ap_bsky_notification_subjects_warm(1, $notifs);
hydrate_check(count($GLOBALS['xrpc']) === 1, 'cached subjects cost no AppView call on the next poll');

// Liked quote post: own media + quoted post with its media.
ap_bsky_post_upsert_from_feed_item([
    'uri' => 'at://did:plc:me/app.bsky.feed.post/qt1',
    'author' => ['did' => $self, 'handle' => 'me.test'],
    'record' => ['text' => 'look at this'],
    'embed' => [
        '$type' => 'app.bsky.embed.recordWithMedia#view',
        'media' => ['$type' => 'app.bsky.embed.images#view', 'images' => [['fullsize' => 'https://cdn.bsky.app/own.jpg', 'thumb' => 'https://cdn.bsky.app/own_t.jpg']]],
        'record' => ['record' => [
            '$type' => 'app.bsky.embed.record#viewRecord',
            'uri' => 'at://did:plc:carol/app.bsky.feed.post/orig',
            'author' => ['did' => 'did:plc:carol', 'handle' => 'carol.test'],
            'value' => ['text' => 'original', 'createdAt' => '2026-10-17T00:00:00Z'],
            'embeds' => [['$type' => 'app.bsky.embed.images#view', 'images' => [['fullsize' => 'https://cdn.bsky.app/orig.jpg', 'thumb' => 'https://cdn.bsky.app/orig_t.jpg']]]],
        ]],
    ],
]);
$xrpcBefore = count($GLOBALS['xrpc']);
$status = ap_masto_notif_bsky_subject_enrich(['id' => 'n1', 'content' => '<p>look at this</p>', 'media_attachments' => []], 'https://bsky.app/profile/me.test/post/qt1', 1);
hydrate_check(($status['media_attachments'][0]['remote_url'] ?? '') === 'https://cdn.bsky.app/own.jpg', 'quote post keeps its own media');
$quoted = $status['quote']['quoted_status'] ?? [];
hydrate_check(str_contains((string) ($quoted['content'] ?? ''), 'original'), 'quoted post text is attached');
hydrate_check(($quoted['media_attachments'][0]['remote_url'] ?? '') === 'https://cdn.bsky.app/orig.jpg', 'quoted post keeps its media');
hydrate_check(count($GLOBALS['xrpc']) === $xrpcBefore, 'paint is cache-only');
$plain = ap_masto_notif_bsky_subject_enrich(['id' => 'n2', 'media_attachments' => []], 'https://bsky.app/profile/bob.test/post/bobpost', 1);
hydrate_check(!isset($plain['quote']) && $plain['media_attachments'] === [], 'plain text post is unchanged');
hydrate_check(ap_bsky_cached_at_uri_for_web_url('https://bsky.app/profile/nobody.test/post/zzz') === null, 'unknown web URL stays unresolved without network');

echo "notif-bsky-subject-hydrate-smoke: {$checks} checks passed\n";
