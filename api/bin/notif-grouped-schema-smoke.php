<?php
declare(strict_types=1);
/**
 * Ice Cubes decodes grouped notifications with strict Swift models.
 * A null avatar, an empty-string URL, media meta as a JSON array, a
 * non-Mastodon visibility, or a string most_recent_notification_id aborts
 * the whole payload. This builds a v2 response from bad fixtures and fails
 * on any violation. most_recent_notification_id stays a JSON integer because
 * NotificationGroup.mostRecentNotificationId is Int; every other id is a string.
 * No live bootstrap, database, or network.
 */
$api = getenv('VAAK_NOTIF_PREVIEW_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');
reference_load($api . '/ap-masto-entities.php', [
    'ap_masto_format_time',
    'ap_masto_https_avatar_fallback',
    'ap_masto_https_header_fallback',
    'ap_masto_https_media_url',
    'ap_masto_api_visibility',
    'ap_masto_notification_api_type',
    'ap_masto_api_time',
    'ap_masto_api_bool',
    'ap_masto_api_int',
    'ap_masto_api_digit_id',
    'ap_masto_api_string_id',
    'ap_masto_api_nullable_id',
    'ap_masto_api_fields',
    'ap_masto_api_emojis',
    'ap_masto_api_media_meta',
    'ap_masto_api_media_list',
    'ap_masto_api_mentions',
    'ap_masto_api_tags',
    'ap_masto_api_card',
    'ap_masto_api_poll',
    'ap_masto_api_quote_approval',
    'ap_masto_api_quote',
    'ap_masto_api_application',
    'ap_masto_api_account',
    'ap_masto_api_partial_account',
    'ap_masto_api_status',
    'ap_masto_api_notification',
    'ap_masto_notifications_api_list',
    'ap_masto_notifications_grouped_from_items',
    'ap_masto_notifications_grouped_slice',
    'ap_masto_notifications_list_redis_key',
]);

$checks = 0;
function schema_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

function schema_roundtrip(array $payload): array
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('grouped payload did not decode to an object');
    }
    return $decoded;
}

function schema_https(mixed $value): bool
{
    return is_string($value) && preg_match('#^https://[^\s]+$#', $value) === 1;
}

function schema_date(mixed $value): bool
{
    return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $value) === 1;
}

function schema_fail(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        $GLOBALS['checks']++;
        return;
    }
    throw new RuntimeException($message);
}

/** @param array<string,mixed> $account */
function schema_account(array $account, string $where): void
{
    schema_check(is_string($account['id'] ?? null) && $account['id'] !== '', "$where id string");
    schema_check(is_string($account['username'] ?? null) && $account['username'] !== '', "$where username");
    schema_check(is_string($account['acct'] ?? null) && $account['acct'] !== '', "$where acct");
    schema_check(is_string($account['display_name'] ?? null) || ($account['display_name'] ?? null) === null, "$where display_name");
    foreach (['avatar', 'header', 'avatar_static', 'header_static'] as $field) {
        schema_check(schema_https($account[$field] ?? null), "$where $field absolute https");
    }
    schema_check(is_string($account['note'] ?? null), "$where note string");
    schema_check(schema_date($account['created_at'] ?? null), "$where created_at");
    foreach (['followers_count', 'following_count', 'statuses_count'] as $field) {
        schema_check(is_int($account[$field] ?? null), "$where $field int");
    }
    schema_check(is_bool($account['locked'] ?? null) && is_bool($account['bot'] ?? null), "$where locked/bot bool");
    schema_check(is_array($account['fields'] ?? null) && is_array($account['emojis'] ?? null), "$where fields and emojis");
    schema_check(!array_key_exists('url', $account) || $account['url'] === null || schema_https($account['url']), "$where url");
    if (isset($account['moved'])) {
        schema_check(is_array($account['moved']), "$where moved");
        schema_account($account['moved'], $where . ' moved');
    }
}

/** @param array<string,mixed> $status */
function schema_status(array $status, string $where): void
{
    schema_check(is_string($status['id'] ?? null) && $status['id'] !== '', "$where id string");
    schema_check(is_string($status['content'] ?? null), "$where content");
    schema_check(is_string($status['spoiler_text'] ?? null), "$where spoiler_text");
    schema_check(is_array($status['account'] ?? null), "$where account");
    schema_account($status['account'], $where . ' account');
    schema_check(schema_date($status['created_at'] ?? null), "$where created_at");
    schema_check(($status['edited_at'] ?? null) === null || schema_date($status['edited_at']), "$where edited_at");
    schema_check(in_array($status['visibility'] ?? '', ['public', 'unlisted', 'private', 'direct'], true), "$where visibility");
    schema_check(is_bool($status['sensitive'] ?? null), "$where sensitive bool");
    foreach (['replies_count', 'reblogs_count', 'favourites_count'] as $field) {
        schema_check(is_int($status[$field] ?? null), "$where $field int");
    }
    foreach (['favourited', 'reblogged', 'bookmarked', 'pinned'] as $field) {
        if (array_key_exists($field, $status) && $status[$field] !== null) {
            schema_check(is_bool($status[$field]), "$where $field bool");
        }
    }
    foreach (['in_reply_to_id', 'in_reply_to_account_id'] as $field) {
        schema_check(!array_key_exists($field, $status) || $status[$field] === null || is_string($status[$field]), "$where $field string");
    }
    schema_check(is_array($status['media_attachments'] ?? null), "$where media");
    foreach ($status['media_attachments'] as $media) {
        schema_check(is_array($media) && is_string($media['id'] ?? null), "$where media id");
        schema_check(($media['url'] ?? null) === null || schema_https($media['url']), "$where media url");
        schema_check(($media['preview_url'] ?? null) === null || schema_https($media['preview_url']), "$where media preview");
        schema_check(!isset($media['meta']) || (is_array($media['meta']) && !array_is_list($media['meta'])), "$where media meta object");
        if (isset($media['meta']['original'])) {
            foreach (['width', 'height'] as $dim) {
                if (array_key_exists($dim, $media['meta']['original'])) {
                    schema_check(is_int($media['meta']['original'][$dim]), "$where media $dim int");
                }
            }
        }
    }
    schema_check(is_array($status['mentions'] ?? null), "$where mentions");
    foreach ($status['mentions'] as $mention) {
        schema_check(is_string($mention['id'] ?? null) && is_string($mention['username'] ?? null) && is_string($mention['acct'] ?? null), "$where mention ids");
        schema_check(schema_https($mention['url'] ?? null), "$where mention url");
    }
    schema_check(is_array($status['tags'] ?? null) && is_array($status['emojis'] ?? null), "$where tags/emojis");
    foreach ($status['tags'] as $tag) {
        schema_check(is_string($tag['name'] ?? null) && is_string($tag['url'] ?? null) && $tag['url'] !== '', "$where tag");
    }
    if (array_key_exists('card', $status) && $status['card'] !== null) {
        schema_check(is_array($status['card']) && is_string($status['card']['url'] ?? null) && is_string($status['card']['type'] ?? null), "$where card");
        schema_check(($status['card']['image'] ?? null) === null || schema_https($status['card']['image']), "$where card image");
    }
    if (array_key_exists('poll', $status) && $status['poll'] !== null) {
        $poll = $status['poll'];
        schema_check(is_array($poll) && is_string($poll['id'] ?? null), "$where poll id");
        schema_check(is_bool($poll['expired'] ?? null) && is_bool($poll['multiple'] ?? null) && is_int($poll['votes_count'] ?? null), "$where poll scalars");
        schema_check(($poll['expires_at'] ?? null) === null || is_string($poll['expires_at']), "$where poll expires");
        schema_check(is_array($poll['options'] ?? null), "$where poll options");
    }
    if (array_key_exists('quote', $status) && $status['quote'] !== null) {
        $quote = $status['quote'];
        schema_check(is_array($quote), "$where quote");
        schema_check(in_array($quote['state'] ?? '', ['accepted', 'pending', 'rejected', 'revoked', 'deleted', 'unauthorized'], true), "$where quote state");
        schema_check(($quote['quoted_status_id'] ?? null) === null || is_string($quote['quoted_status_id']), "$where quoted id");
        if (isset($quote['quoted_status'])) {
            schema_check(is_array($quote['quoted_status']), "$where quoted status");
            schema_status($quote['quoted_status'], $where . ' quoted');
        }
    }
    if (array_key_exists('quote_approval', $status) && $status['quote_approval'] !== null) {
        $approval = $status['quote_approval'];
        schema_check(is_array($approval) && in_array($approval['current_user'] ?? '', ['automatic', 'manual', 'denied', 'unknown'], true), "$where quote approval");
        schema_check(is_array($approval['automatic'] ?? null) && is_array($approval['manual'] ?? null), "$where quote approval lists");
    }
    if (array_key_exists('reblog', $status) && $status['reblog'] !== null) {
        schema_check(is_array($status['reblog']), "$where reblog");
        schema_status($status['reblog'], $where . ' reblog');
    }
    schema_check(!array_key_exists('filtered', $status), "$where does not emit filtered");
}

/** @param array<string,mixed> $payload */
function schema_grouped(array $payload, bool $partial): void
{
    schema_check(is_array($payload['accounts'] ?? null) && is_array($payload['statuses'] ?? null) && is_array($payload['notification_groups'] ?? null), 'grouped roots');
    schema_check($partial === array_key_exists('partial_accounts', $payload), 'partial_accounts only when requested');
    $ids = [];
    foreach ($payload['accounts'] as $account) {
        schema_account($account, 'account ' . ($account['id'] ?? '?'));
        $ids[(string) $account['id']] = true;
    }
    if ($partial) {
        schema_check(is_array($payload['partial_accounts']), 'partial_accounts array');
        foreach ($payload['partial_accounts'] as $account) {
            schema_check(is_string($account['id'] ?? null) && is_string($account['acct'] ?? null), 'partial id/acct');
            schema_check(schema_https($account['avatar'] ?? null) && schema_https($account['avatar_static'] ?? null), 'partial avatar');
            schema_check(schema_https($account['url'] ?? null), 'partial url');
            schema_check(is_bool($account['locked'] ?? null) && is_bool($account['bot'] ?? null), 'partial bools');
            $ids[(string) $account['id']] = true;
        }
    }
    $statusIds = [];
    foreach ($payload['statuses'] as $status) {
        schema_status($status, 'status ' . ($status['id'] ?? '?'));
        $statusIds[(string) $status['id']] = true;
    }
    $allowed = [
        'follow', 'follow_request', 'favourite', 'reblog', 'mention', 'quote', 'poll',
        'status', 'update', 'quoted_update', 'added_to_collection', 'collection_update',
    ];
    schema_check($payload['notification_groups'] !== [], 'at least one group');
    foreach ($payload['notification_groups'] as $group) {
        schema_check(is_string($group['group_key'] ?? null) && $group['group_key'] !== '', 'group_key');
        schema_check(is_int($group['notifications_count'] ?? null) && $group['notifications_count'] > 0, 'notifications_count');
        schema_check(is_string($group['type'] ?? null) && in_array($group['type'], $allowed, true), 'standard type ' . json_encode($group['type'] ?? null));
        schema_check(is_int($group['most_recent_notification_id'] ?? null) && $group['most_recent_notification_id'] > 0, 'most_recent integer');
        schema_check(is_string($group['page_min_id'] ?? null) && is_string($group['page_max_id'] ?? null), 'page ids strings');
        schema_check(schema_date($group['latest_page_notification_at'] ?? null), 'latest_page_notification_at');
        schema_check(is_array($group['sample_account_ids'] ?? null) && $group['sample_account_ids'] !== [], 'sample accounts');
        foreach ($group['sample_account_ids'] as $sampleId) {
            schema_check(is_string($sampleId) && isset($ids[$sampleId]), 'sample account ' . json_encode($sampleId) . ' present');
        }
        if (array_key_exists('status_id', $group) && $group['status_id'] !== null) {
            schema_check(is_string($group['status_id']) && isset($statusIds[$group['status_id']]), 'status_id present in statuses');
        }
        schema_check(!array_key_exists('collection', $group), 'no partial collection');
    }
}

$avatarFallback = ap_masto_https_avatar_fallback();
schema_check(schema_https($avatarFallback), 'avatar fallback is https');

schema_fail(static function (): void {
    schema_account(['id' => '1', 'username' => 'a', 'acct' => 'a', 'avatar' => null, 'header' => 'https://cdn.example/h.jpg', 'avatar_static' => 'https://cdn.example/a.jpg', 'header_static' => 'https://cdn.example/h.jpg', 'note' => '', 'created_at' => '2026-10-14T06:05:00.000Z', 'followers_count' => 0, 'following_count' => 0, 'statuses_count' => 0, 'locked' => false, 'bot' => false, 'fields' => [], 'emojis' => []], 'raw');
}, 'validator must reject a null avatar');
schema_fail(static function (): void {
    schema_status([
        'id' => '9', 'content' => '', 'spoiler_text' => '', 'visibility' => 'followers', 'sensitive' => false,
        'created_at' => '2026-10-14T06:05:00.000Z', 'replies_count' => 0, 'reblogs_count' => 0, 'favourites_count' => 0,
        'account' => ['id' => '1', 'username' => 'a', 'acct' => 'a', 'avatar' => 'https://cdn.example/a.jpg', 'header' => 'https://cdn.example/h.jpg', 'avatar_static' => 'https://cdn.example/a.jpg', 'header_static' => 'https://cdn.example/h.jpg', 'note' => '', 'created_at' => '2026-10-14T06:05:00.000Z', 'followers_count' => 0, 'following_count' => 0, 'statuses_count' => 0, 'locked' => false, 'bot' => false, 'fields' => [], 'emojis' => []],
        'media_attachments' => [['id' => 'm', 'type' => 'image', 'url' => 'https://cdn.example/m.jpg', 'preview_url' => 'https://cdn.example/m.jpg', 'meta' => []]],
        'mentions' => [], 'tags' => [], 'emojis' => [],
    ], 'raw');
}, 'validator must reject followers visibility and array meta');
schema_fail(static function (): void {
    schema_status([
        'id' => '9', 'content' => '', 'spoiler_text' => '', 'visibility' => 'public', 'sensitive' => false,
        'created_at' => '2026-10-14T06:05:00.000Z', 'replies_count' => 0, 'reblogs_count' => 0, 'favourites_count' => 0,
        'account' => ['id' => '1', 'username' => 'a', 'acct' => 'a', 'avatar' => 'https://cdn.example/a.jpg', 'header' => 'https://cdn.example/h.jpg', 'avatar_static' => 'https://cdn.example/a.jpg', 'header_static' => 'https://cdn.example/h.jpg', 'note' => '', 'created_at' => '2026-10-14T06:05:00.000Z', 'followers_count' => 0, 'following_count' => 0, 'statuses_count' => 0, 'locked' => false, 'bot' => false, 'fields' => [], 'emojis' => []],
        'media_attachments' => [['id' => 'm', 'type' => 'image', 'url' => 'https://cdn.example/m.jpg', 'preview_url' => 'https://cdn.example/m.jpg', 'meta' => []]],
        'mentions' => [], 'tags' => [], 'emojis' => [],
    ], 'raw-meta');
}, 'validator must reject an empty media url and array meta');
schema_fail(static function (): void {
    schema_grouped([
        'accounts' => [],
        'statuses' => [],
        'notification_groups' => [[
            'group_key' => 'x', 'notifications_count' => 1, 'type' => 'phyrian_imprint',
            'most_recent_notification_id' => '12', 'page_min_id' => 12, 'page_max_id' => '12',
            'latest_page_notification_at' => '2026-10-14T06:05:00.000Z', 'sample_account_ids' => [],
        ]],
    ], false);
}, 'validator must reject phyrian types and a string most_recent_notification_id');

$base = 1760400000000000000;
$nid = static function (int $n) use ($base): string {
    return (string) ($base + $n);
};
$badAccount = static function (string $id, array $extra = []) use ($avatarFallback): array {
    return array_merge([
        'id' => $id,
        'username' => 'alice',
        'acct' => 'alice@bsky.social',
        'display_name' => 'Alice',
        'avatar' => null,
        'avatar_static' => '/relative/avatar.jpg',
        'header' => '',
        'header_static' => ['url' => 'https://cdn.example/header.jpg'],
        'note' => null,
        'url' => 'bsky.app/profile/alice',
        'locked' => 1,
        'bot' => 0,
        'created_at' => '2026-10-14 06:05:00',
        'followers_count' => '3',
        'following_count' => '4',
        'statuses_count' => '5',
    ], $extra);
};
$statusShell = static function (mixed $id, array $account, array $extra = []): array {
    return array_merge([
        'id' => $id,
        'content' => '<p>hello</p>',
        'spoiler_text' => '',
        'account' => $account,
        'created_at' => '2026-10-14 06:05:00',
        'visibility' => 'followers',
        'sensitive' => 0,
        'favourited' => 0,
        'reblogged' => 1,
        'bookmarked' => 0,
        'pinned' => 1,
        'replies_count' => '1',
        'reblogs_count' => '2',
        'favourites_count' => '3',
        'media_attachments' => [[
            'id' => 9001,
            'type' => 'image',
            'url' => '',
            'preview_url' => 'https://cdn.example/preview.jpg',
            'meta' => [],
        ]],
        'mentions' => [
            ['id' => 7, 'username' => 'bob', 'acct' => 'bob@example.com', 'url' => ''],
            ['id' => '8', 'username' => 'cara', 'acct' => 'cara@example.com', 'url' => 'https://example.com/@cara'],
        ],
        'tags' => [['name' => 'vaak', 'url' => '']],
        'card' => ['url' => 'https://example.com/post', 'title' => 'Post', 'description' => '', 'type' => 'link', 'image' => ''],
        'emojis' => [],
    ], $extra);
};

$items = [
    ['id' => $nid(1), 'type' => 'follow', 'group_key' => 'ungrouped-' . $nid(1), 'created_at' => '2026-10-14 06:00:00', 'account' => $badAccount('acct-follow'), 'status' => null],
    ['id' => $nid(2), 'type' => 'follow_request', 'created_at' => '2026-10-14 06:01:00', 'account' => $badAccount('acct-request'), 'status' => null],
    ['id' => $nid(3), 'type' => 'favourite', 'group_key' => 'favourite-shared', 'created_at' => '2026-10-14 06:02:00', 'account' => $badAccount('acct-a'), 'status' => $statusShell('status-fav', $badAccount('author-fav'))],
    ['id' => $nid(4), 'type' => 'favourite', 'group_key' => 'favourite-shared', 'created_at' => '2026-10-14 06:03:00', 'account' => $badAccount('acct-b'), 'status' => $statusShell('status-fav', $badAccount('author-fav'))],
    ['id' => $nid(5), 'type' => 'favourite', 'group_key' => 'favourite-shared', 'created_at' => '2026-10-14 06:04:00', 'account' => $badAccount('acct-c'), 'status' => $statusShell('status-fav', $badAccount('author-fav'))],
    ['id' => $nid(6), 'type' => 'reblog', 'created_at' => '2026-10-14 06:05:00', 'account' => $badAccount('acct-reblog'), 'status' => $statusShell('status-reblog', $badAccount('author-reblog'), ['visibility' => 'local'])],
    ['id' => $nid(7), 'type' => 'mention', 'created_at' => '2026-10-14 06:06:00', 'account' => $badAccount('acct-mention'), 'status' => $statusShell(1760400000000000099, $badAccount('author-mention'), [
        'visibility' => 'public',
        'in_reply_to_id' => 1760400000000000088,
        'in_reply_to_account_id' => 42,
    ])],
    ['id' => $nid(8), 'type' => 'quote', 'created_at' => '2026-10-14 06:07:00', 'account' => $badAccount('acct-quote'), 'status' => $statusShell('status-quote', $badAccount('author-quote'), [
        'quote' => [
            'state' => 'nope',
            'quoted_status_id' => 55,
            'quoted_status' => [
                'id' => 'bsky:' . md5('quoted'),
                'content' => '<p>quoted</p>',
                'created_at' => '2026-10-14 05:00:00',
                'account' => [
                    'id' => 'bsky:' . md5('alice.bsky.social'),
                    'username' => 'alice.bsky.social',
                    'acct' => 'alice.bsky.social',
                    'display_name' => 'Alice',
                    'url' => 'https://bsky.app/profile/alice.bsky.social',
                ],
                'media_attachments' => [[
                    'id' => 'bsky-media-1',
                    'type' => 'image',
                    'url' => 'https://cdn.bsky.app/img.jpg',
                    'preview_url' => 'https://cdn.bsky.app/img.jpg',
                    'meta' => [],
                ]],
            ],
        ],
        'quote_approval' => ['current_user' => 'nope', 'automatic' => [1], 'manual' => null],
    ])],
    ['id' => $nid(9), 'type' => 'poll', 'created_at' => '2026-10-14 06:08:00', 'account' => $badAccount('acct-poll'), 'status' => $statusShell('status-poll', $badAccount('author-poll'), [
        'poll' => [
            'id' => 77,
            'expires_at' => null,
            'expired' => 1,
            'multiple' => 0,
            'votes_count' => '8',
            'options' => [['title' => 'yes', 'votes_count' => '3']],
        ],
    ])],
    ['id' => $nid(10), 'type' => 'status', 'created_at' => '2026-10-14 06:09:00', 'account' => $badAccount('acct-status'), 'status' => $statusShell('status-status', $badAccount('author-status'), ['visibility' => 'followers_only'])],
    ['id' => $nid(11), 'type' => 'update', 'created_at' => '2026-10-14 06:10:00', 'account' => $badAccount('acct-update'), 'status' => $statusShell('status-update', $badAccount('author-update'))],
    ['id' => $nid(12), 'type' => 'quoted_update', 'created_at' => '2026-10-14 06:11:00', 'account' => $badAccount('acct-qupdate'), 'status' => $statusShell('status-qupdate', $badAccount('author-qupdate'))],
    ['id' => $nid(13), 'type' => 'bite', 'group_key' => 'bite-row', 'created_at' => '2026-10-14 06:12:00', 'account' => $badAccount('acct-bite'), 'status' => $statusShell('status-bite', $badAccount('author-bite'))],
    ['id' => 'phyrian1', 'type' => 'phyrian_imprint', 'group_key' => 'phyrian-1', 'created_at' => '2026-10-14 06:13:00', 'account' => ['id' => '9', 'username' => 'phy', 'acct' => 'phy', 'avatar' => '', 'avatar_static' => ''], 'status' => null],
    ['id' => 'phyrian2', 'type' => 'phyrian_resonance', 'group_key' => 'phyrian-2', 'created_at' => '2026-10-14 06:14:00', 'account' => $badAccount('acct-phy'), 'status' => null],
    ['id' => $nid(14), 'type' => 'added_to_collection', 'created_at' => '2026-10-14 06:15:00', 'account' => $badAccount('acct-collection'), 'status' => null, 'collection' => ['name' => 'partial']],
    ['id' => $nid(15), 'type' => 'collection_update', 'created_at' => '2026-10-14 06:16:00', 'account' => $badAccount('acct-colupdate'), 'status' => null],
];

schema_check($items[0]['account']['avatar'] === null, 'fixture really has a null avatar');
schema_check($items[7]['status']['quote']['quoted_status']['media_attachments'][0]['meta'] === [], 'fixture really has array meta');
schema_check($items[12]['type'] === 'bite' && $items[13]['type'] === 'phyrian_imprint', 'fixture covers bite and phyrian');

$full = schema_roundtrip(ap_masto_notifications_grouped_from_items($items, 'full'));
schema_grouped($full, false);
$partial = schema_roundtrip(ap_masto_notifications_grouped_from_items($items, 'partial_avatars'));
schema_grouped($partial, true);
$v1 = schema_roundtrip(ap_masto_notifications_api_list($items));

$types = array_column($full['notification_groups'], 'type');
schema_check(!in_array('phyrian_imprint', $types, true) && !in_array('phyrian_resonance', $types, true) && !in_array('bite', $types, true), 'nonstandard types dropped or mapped');
schema_check(in_array('favourite', $types, true) && in_array('quote', $types, true) && in_array('mention', $types, true), 'standard types kept');
schema_check(in_array('added_to_collection', $types, true) && in_array('collection_update', $types, true) && in_array('quoted_update', $types, true), 'collection and quoted_update kept');
foreach ($v1 as $row) {
    schema_check(is_string($row['id'] ?? null) && schema_date($row['created_at'] ?? null), 'v1 id and date');
    schema_check(in_array($row['type'], ['follow', 'follow_request', 'favourite', 'reblog', 'mention', 'quote', 'poll', 'status', 'update', 'quoted_update', 'added_to_collection', 'collection_update'], true), 'v1 type');
    schema_account($row['account'], 'v1 account');
    if ($row['status'] !== null) {
        schema_status($row['status'], 'v1 status');
    }
    schema_check(!str_starts_with((string) $row['type'], 'phyrian'), 'v1 drops phyrian');
}
schema_check(count($v1) === count($items) - 2, 'v1 drops only the two phyrian rows');
schema_check(count(array_filter($v1, static fn (array $row): bool => $row['type'] === 'favourite')) === 4, 'three favourites plus bite mapped to favourite');

$fav = null;
foreach ($full['notification_groups'] as $group) {
    if ($group['group_key'] === 'favourite-shared') {
        $fav = $group;
    }
}
schema_check(is_array($fav) && $fav['notifications_count'] === 3, 'shared favourites collapse');
schema_check($fav['sample_account_ids'] === ['acct-a', 'acct-b', 'acct-c'], 'sample order keeps the newest-first account first');
schema_check($fav['most_recent_notification_id'] === (int) $nid(5), 'most recent id is the greatest integer');
schema_check($fav['page_min_id'] === $nid(3) && $fav['page_max_id'] === $nid(5), 'page bounds are strings');
schema_check($fav['status_id'] === 'status-fav', 'favourite status_id resolves');
$followAccount = null;
foreach ($full['accounts'] as $account) {
    if ($account['id'] === 'acct-follow') {
        $followAccount = $account;
    }
}
schema_check(is_array($followAccount), 'follow account kept');
schema_check($followAccount['avatar'] === $avatarFallback && $followAccount['header'] === 'https://mkultra.monster/img/gifs/waves.gif', 'null and empty avatar fields use https fallbacks');
schema_check($followAccount['header_static'] === 'https://cdn.example/header.jpg', 'activitypub header object becomes an https url');
schema_check($followAccount['locked'] === true && $followAccount['bot'] === false && $followAccount['followers_count'] === 3, 'account bools and counts are JSON scalars Ice Cubes accepts');

$partialIds = array_column($partial['partial_accounts'], 'id');
$fullIds = array_column($partial['accounts'], 'id');
schema_check(in_array('acct-a', $fullIds, true) && !in_array('acct-b', $fullIds, true) && !in_array('acct-c', $fullIds, true), 'only the primary favourite account stays expanded');
schema_check(in_array('acct-b', $partialIds, true) && in_array('acct-c', $partialIds, true), 'secondary samples move to partial_accounts');
schema_check(in_array('acct-follow', $fullIds, true), 'a group primary stays a full account');

$quoteStatus = null;
foreach ($full['statuses'] as $status) {
    if ($status['id'] === 'status-quote') {
        $quoteStatus = $status;
    }
    if ($status['id'] === (string) 1760400000000000099) {
        schema_check($status['in_reply_to_id'] === '1760400000000000088' && $status['in_reply_to_account_id'] === '42', 'reply ids are strings');
        schema_check($status['visibility'] === 'public', 'mention visibility preserved');
        schema_check(count($status['mentions']) === 1 && $status['mentions'][0]['url'] === 'https://example.com/@cara', 'empty mention url dropped');
    }
    if ($status['visibility'] === 'followers' || $status['visibility'] === 'followers_only' || $status['visibility'] === 'local') {
        throw new RuntimeException('nonstandard visibility leaked');
    }
}
schema_check(is_array($quoteStatus), 'quote status included');
schema_check($quoteStatus['quote']['state'] === 'pending', 'unknown quote state mapped');
schema_check($quoteStatus['quote_approval']['current_user'] === 'automatic', 'unknown quote approval mapped');
schema_check(schema_https($quoteStatus['quote']['quoted_status']['account']['avatar']), 'bluesky quote account avatar filled');
schema_check(schema_https($quoteStatus['quote']['quoted_status']['account']['header']), 'bluesky quote account header filled');
schema_check(is_array($quoteStatus['quote']['quoted_status']['account']['fields']) && is_array($quoteStatus['quote']['quoted_status']['account']['emojis']), 'bluesky quote account required arrays');
schema_check($quoteStatus['quote']['quoted_status']['media_attachments'][0]['meta'] === null, 'bluesky media meta array became null');
schema_check($quoteStatus['card']['image'] === null, 'empty card image became null');
schema_check($quoteStatus['visibility'] === 'private', 'followers visibility mapped to private');

$slice = ap_masto_notifications_grouped_slice($full, 'favourite-shared');
schema_check(is_array($slice) && count($slice['notification_groups']) === 1 && count($slice['accounts']) === 3 && count($slice['statuses']) === 1, 'group detail carries referenced accounts and the status');
schema_grouped(schema_roundtrip($slice), false);

$entities = (string) file_get_contents($api . '/ap-masto-entities.php');
schema_check(!str_contains($entities, "'meta' => []"), 'producers do not emit empty media meta arrays');
schema_check(str_starts_with(ap_masto_notifications_list_redis_key(1, 30, null, null, ['mention']), 'vaak:notifications:v4:1:'), 'notification cache key bumped to v4');

echo "notif-grouped-schema-smoke PASS $checks\n";
