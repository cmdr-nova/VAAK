#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Quoted authors use the same best-known profile as post cards and the hover
 * card: a thin or stale quote embed must not paint as a bare handle with the
 * default avatar when VAAK already has the profile cached.
 *
 * Run: api/bin/php-policy-test api/bin/quote-author-best-known-smoke.php
 */

require_once __DIR__ . '/migration-reference-functions.php';

$checks = 0;
function qa_check(bool $ok, string $why): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: $why\n");
        exit(1);
    }
    $GLOBALS['checks']++;
}

const QA_DID = 'did:plc:patricia';
const QA_NEW_AVATAR = 'https://cdn.bsky.app/img/avatar/plain/did:plc:patricia/new@jpeg';
const QA_OLD_AVATAR = 'https://cdn.bsky.app/img/avatar/plain/did:plc:patricia/old@jpeg';

// Durable profile cache (bsky_actor_profiles) as the hover card reads it.
function ap_bsky_actor_profile_cache_get(string $actorRef, int $ownerUserId = 0, ?PDO $db = null): ?array
{
    $known = [QA_DID, 'https://bsky.app/profile/' . QA_DID, 'https://bsky.app/profile/patigallardo.bsky.social'];
    if (!in_array($actorRef, $known, true)) {
        return null;
    }
    return ['did' => QA_DID, 'updated_at' => gmdate('c'), 'profile' => [
        'did' => QA_DID, 'handle' => 'patigallardo.bsky.social', 'displayName' => 'Patricia Aas', 'avatar' => QA_NEW_AVATAR,
    ]];
}
function ap_bsky_enrich_author_view(array $author, int $ownerUserId = 0): array { return $author; }
function ap_bsky_avatar_public_url(mixed $avatar, string $did = ''): ?string
{
    return is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null;
}
function ap_bsky_https_url_from_at_uri(string $uri, ?string $handle): string
{
    return 'https://bsky.app/profile/' . ($handle ?? 'x') . '/post/' . basename($uri);
}
function ap_bsky_media_items_from_embeds(array $embeds): array { return []; }
function ap_bsky_tombstone_uri(string $uri, mixed $x): void {}
function ap_bsky_is_profile_ref(string $ref): bool
{
    return str_starts_with($ref, 'did:') || str_starts_with($ref, 'https://bsky.app/profile/')
        || str_starts_with($ref, 'at://did:');
}
function ap_profile_sanitize_https_url(mixed $u): ?string { return is_string($u) && str_starts_with($u, 'https://') ? $u : null; }
function admin_actor_avatar_url(?string $actorId): string
{
    $cached = admin_bsky_cached_profile((string) $actorId);
    $av = $cached['profile']['avatar'] ?? '';
    if (is_string($av) && str_starts_with($av, 'https://')) return $av;
    if ($actorId === 'https://remote.example/users/bryn') return 'https://remote.example/media/bryn.png';
    return 'https://mkultra.monster/img/avatar/default.webp';
}
function ap_remote_actor_get(string $actorId): ?array
{
    return $actorId === 'https://remote.example/users/bryn'
        ? ['display_name' => 'Bryn', 'username' => 'bryn', 'icon_source_url' => 'https://remote.example/media/bryn.png']
        : null;
}

reference_load(dirname(__DIR__) . '/ap-bsky.php', ['ap_bsky_quote_author_best_known', 'ap_bsky_quote_preview', 'ap_bsky_quote_view_is_hollow']);
reference_load(dirname(__DIR__) . '/ap-admin.php', ['admin_bsky_cached_profile', 'admin_account_best_known', 'admin_avatar_is_placeholder', 'admin_account_actor_ref']);

$uri = 'at://' . QA_DID . '/app.bsky.feed.post/3quoted';

// 1) Thin embed (Jetstream / cached quote with no author): DID from the URI.
$thin = ['embed' => ['$type' => 'app.bsky.embed.record#view', 'record' => [
    '$type' => 'app.bsky.embed.record#viewRecord', 'uri' => $uri,
    'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'Well. Shit.'],
]]];
$prev = ap_bsky_quote_preview($thin);
qa_check(is_array($prev), 'thin quote still previews');
qa_check(($prev['display'] ?? '') === 'Patricia Aas', 'thin quote name from profile cache');
qa_check(($prev['handle'] ?? '') === 'patigallardo.bsky.social', 'thin quote handle from profile cache');
qa_check(($prev['avatar'] ?? '') === QA_NEW_AVATAR, 'thin quote avatar from profile cache');
qa_check(($prev['did'] ?? '') === QA_DID, 'thin quote keeps the DID');

// 2) Stale snapshot (retired avatar CID, old name): the profile cache wins.
$stale = ['embed' => ['$type' => 'app.bsky.embed.recordWithMedia#view', 'media' => [], 'record' => ['record' => [
    '$type' => 'app.bsky.embed.record#viewRecord', 'uri' => $uri,
    'author' => ['did' => QA_DID, 'handle' => 'patigallardo.bsky.social', 'displayName' => 'Old name', 'avatar' => QA_OLD_AVATAR],
    'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'Well. Shit.'],
]]]];
$prev = ap_bsky_quote_preview($stale);
qa_check(($prev['avatar'] ?? '') === QA_NEW_AVATAR, 'stale quote avatar replaced by cached profile');
qa_check(($prev['display'] ?? '') === 'Patricia Aas', 'stale quote name replaced by cached profile');

// 3) Unknown author keeps its embed fields untouched.
$other = ['embed' => ['$type' => 'app.bsky.embed.record#view', 'record' => [
    '$type' => 'app.bsky.embed.record#viewRecord', 'uri' => 'at://did:plc:stranger/app.bsky.feed.post/1',
    'author' => ['did' => 'did:plc:stranger', 'handle' => 'stranger.test', 'displayName' => 'Stranger', 'avatar' => 'https://cdn.bsky.app/s.jpg'],
    'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'hello'],
]]];
$prev = ap_bsky_quote_preview($other);
qa_check(($prev['display'] ?? '') === 'Stranger' && ($prev['avatar'] ?? '') === 'https://cdn.bsky.app/s.jpg', 'uncached author unchanged');

// 4) Card-level fill (admin_feed_quote_embed_html): handle-as-name + default face.
$acct = admin_account_best_known([
    'acct' => 'patigallardo.bsky.social', 'username' => 'patigallardo.bsky.social',
    'display_name' => 'patigallardo.bsky.social', 'url' => 'https://bsky.app/profile/patigallardo.bsky.social',
    'avatar' => 'https://mkultra.monster/img/avatar/default.webp',
]);
qa_check(($acct['display_name'] ?? '') === 'Patricia Aas', 'card name filled from Bluesky cache');
qa_check(($acct['avatar'] ?? '') === QA_NEW_AVATAR, 'card avatar filled from Bluesky cache');

$fedi = admin_account_best_known(['acct' => 'bryn@remote.example', 'username' => 'bryn', 'display_name' => 'bryn',
    'uri' => 'https://remote.example/users/bryn']);
qa_check(($fedi['display_name'] ?? '') === 'Bryn', 'fediverse card name from remote_actors');
qa_check(($fedi['avatar'] ?? '') === 'https://remote.example/media/bryn.png', 'fediverse card avatar from shared avatar path');

$kept = admin_account_best_known(['acct' => 'bryn@remote.example', 'display_name' => 'Custom', 'avatar' => 'https://remote.example/own.png',
    'uri' => 'https://remote.example/users/bryn']);
qa_check($kept['display_name'] === 'Custom' && $kept['avatar'] === 'https://remote.example/own.png', 'real account fields are kept');

// 5) Blocked / deleted quotes are not posts. AppView sends viewBlocked with
// only the DID; older compact caches stored it as an empty viewRecord, which
// painted the DID as name and handle with no text.
$blockedUri = 'at://did:plc:d363uy4ngyyc7ofy4jtvgsgb/app.bsky.feed.post/3mznm6hj6pk2c';
$warmed = [];
function ap_bsky_post_preview_warm_enqueue(string $url, int $ownerUserId = 0): bool { $GLOBALS['warmed'][] = $url; return true; }
$prev = ap_bsky_quote_preview(['uri' => 'at://did:plc:edith/app.bsky.feed.post/q', 'embed' => ['$type' => 'app.bsky.embed.record#view', 'record' => [
    '$type' => 'app.bsky.embed.record#viewBlocked', 'uri' => $blockedUri, 'blocked' => true,
    'author' => ['did' => 'did:plc:d363uy4ngyyc7ofy4jtvgsgb'],
]]]);
qa_check(($prev['display'] ?? '') === 'Unavailable' && ($prev['text'] ?? '') === 'Quoted post is blocked', 'live blocked quote reads as blocked');
qa_check($warmed === [], 'live blocked quote queues nothing');
foreach (['did:plc:d363uy4ngyyc7ofy4jtvgsgb', ''] as $hollowDid) {
    $prev = ap_bsky_quote_preview(['uri' => 'at://did:plc:edith/app.bsky.feed.post/q', 'embed' => ['$type' => 'app.bsky.embed.record#view', 'record' => [
        '$type' => 'app.bsky.embed.record#viewRecord', 'uri' => $blockedUri, 'cid' => '',
        'author' => ['did' => $hollowDid, 'handle' => '', 'displayName' => '', 'avatar' => ''],
        'value' => ['$type' => 'app.bsky.feed.post', 'text' => '', 'createdAt' => ''],
    ]]]);
    qa_check(($prev['display'] ?? '') === 'Unavailable' && ($prev['handle'] ?? 'x') === '', 'hollow cached quote is not painted as a DID author');
    qa_check(($prev['text'] ?? '') === 'Quoted post unavailable', 'hollow cached quote says unavailable');
}
qa_check(count($warmed) === 2 && $warmed[0] === 'at://did:plc:edith/app.bsky.feed.post/q', 'hollow quote queues an AppView warm of the quoting post');

// 6) The compact cache keeps the gone state instead of inventing a post.
reference_load(dirname(__DIR__) . '/ap-bsky.php', ['ap_bsky_post_embed_compact', 'ap_bsky_compact_label_list', 'ap_bsky_embed_video_view']);
$compact = ap_bsky_post_embed_compact(['$type' => 'app.bsky.embed.record#view', 'record' => [
    '$type' => 'app.bsky.embed.record#viewBlocked', 'uri' => $blockedUri, 'blocked' => true,
    'author' => ['did' => 'did:plc:d363uy4ngyyc7ofy4jtvgsgb', 'viewer' => ['blockedBy' => false]],
]]);
qa_check(($compact['record']['$type'] ?? '') === 'app.bsky.embed.record#viewBlocked', 'compact keeps viewBlocked');
qa_check(!isset($compact['record']['value']) && ($compact['record']['blocked'] ?? null) === true, 'compact invents no post body');
$compact = ap_bsky_post_embed_compact(['$type' => 'app.bsky.embed.recordWithMedia#view', 'media' => ['$type' => 'app.bsky.embed.images#view', 'images' => []],
    'record' => ['record' => ['$type' => 'app.bsky.embed.record#viewNotFound', 'uri' => $blockedUri, 'notFound' => true]]]);
qa_check(($compact['record']['record']['$type'] ?? '') === 'app.bsky.embed.record#viewNotFound', 'compact keeps viewNotFound inside recordWithMedia');
$prev = ap_bsky_quote_preview(['embed' => $compact]);
qa_check(($prev['text'] ?? '') === 'Quoted post unavailable', 'compacted viewNotFound previews as unavailable');
$compact = ap_bsky_post_embed_compact(['$type' => 'app.bsky.embed.record#view', 'record' => [
    '$type' => 'app.bsky.embed.record#viewRecord', 'uri' => $uri,
    'author' => ['did' => QA_DID, 'handle' => 'patigallardo.bsky.social'],
    'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'Well. Shit.', 'createdAt' => '2026-11-07T00:00:00Z'],
]]);
qa_check(($compact['record']['value']['text'] ?? '') === 'Well. Shit.', 'real quotes still compact to a viewRecord');

echo "quote-author-best-known-smoke ok checks=$checks\n";
