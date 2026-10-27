<?php
declare(strict_types=1);
/**
 * Database notification pages must match PHP's Mastodon entities.
 * This smoke loads the real id, HTML, classification, account-id, and API
 * shapers and prints the page JSON Rust's database builder has to equal.
 * No database and no network: ap_db() throws if a loaded function reaches it.
 */
$api = getenv('VAAK_NOTIF_PREVIEW_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}

function ap_db(): never
{
    throw new RuntimeException('notif parity smoke must not open a database');
}
function ap_masto_session_actor_id(): string
{
    return 'https://mkultra.monster/users/cmdr_nova';
}
function ap_post_subscription_is(string $actor, ?int $owner = null): bool
{
    return false;
}
function ap_masto_favourite_by_object_id(string $objectId): bool
{
    return false;
}
function ap_db_owner_user_id_for_actor(string $actor): int
{
    return 0;
}

reference_load($api . '/ap-db.php', [
    'ap_fix_utf8',
    'ap_plain_text_to_html',
    'ap_content_addresses_local_actor',
]);
reference_load($api . '/ap-masto-entities.php', [
    'ap_masto_strip_announce_fragment',
    'ap_masto_status_is_boost_wrapper',
    'ap_masto_quoted_display_status',
    'ap_masto_attachment_asset_urls',
    'ap_masto_outer_media_without_quote_duplicates',
    'ap_masto_quoted_media_is_authoritative',
    'ap_masto_rehome_flattened_quote_media',
    'ap_masto_snowflake_id',
    'ap_masto_format_time',
    'ap_masto_mention_target_object_id',
    'ap_masto_mention_is_bluesky',
    'ap_masto_mention_is_quote_of_ours',
    'ap_masto_mention_notif_type',
    'ap_masto_remote_account_acct_key',
    'ap_masto_actor_id_aliases',
    'ap_masto_remote_account_id',
    'ap_masto_https_media_url',
    'ap_masto_https_avatar_fallback',
    'ap_masto_https_header_fallback',
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
    'ap_masto_api_status',
    'ap_masto_api_notification',
    'ap_masto_notifications_api_list',
]);

$fail = static function (string $msg): never {
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
};

$mentionAt = '2026-10-24T15:27:24+00:00';
$noteAt = '2026-10-24T15:27:12+00:00';
$likeAt = '2026-10-25T13:45:06+00:00';
$likedAt = '2026-10-25T13:33:31+00:00';
$quoteAt = '2026-10-26T08:00:00+00:00';
$quotedAt = '2023-11-14T22:13:20+00:00';
$followAt = '2026-10-26T09:00:00+00:00';
$pollAt = '2026-10-26T10:00:00+00:00';
$replyAt = '2026-10-26T11:00:00+00:00';
$parentAt = '2026-10-26T10:30:00+00:00';

$localAvatar = 'https://mkultra.monster/img/avatar/local-default.webp';
$localHeader = 'https://mkultra.monster/img/gifs/waves.gif';
$remoteAvatar = 'https://mkultra.monster/img/avatar/default.webp';
$localCreated = '2018-01-01T00:00:00.000Z';
$remoteCreated = '2020-01-01T00:00:00.000Z';
$quoteApproval = ['automatic' => ['public'], 'manual' => [], 'current_user' => 'automatic'];

$html = static function (string $text) use ($fail): string {
    $out = ap_plain_text_to_html($text);
    if ($out === '' && trim($text) !== '') {
        $fail('plain html empty for ' . $text);
    }
    return $out;
};
$snow = static fn (string $iso, int $db, int $type): string => ap_masto_snowflake_id($iso, $db, $type);
$when = static fn (string $iso): string => ap_masto_format_time($iso);
$group = static function (string $type, string $actor, string $ref): string {
    $actor = rtrim($actor, '/');
    if ($actor === '') {
        $actor = '-';
    }
    return $type . '-' . substr(hash('sha256', $actor . '|' . $ref), 0, 24);
};

$accountId = static function (string $seed): string {
    $n = (int) sprintf('%u', crc32($seed));
    return (string) (300000000 + ($n % 699999999));
};
$remote = static function (string $actor, string $display = '', ?array $row = null) use ($remoteAvatar, $remoteCreated, $accountId): array {
    $actor = rtrim($actor, '/');
    $host = strtolower((string) (parse_url($actor, PHP_URL_HOST) ?: ''));
    $username = 'user';
    $rowUser = is_array($row) ? trim((string) ($row['username'] ?? ''), " \t\n\r\0\x0B@") : '';
    $rowHost = is_array($row) ? strtolower(trim((string) ($row['host'] ?? ''))) : '';
    if ($rowHost !== '') {
        $host = $rowHost;
    }
    if ($rowUser !== '') {
        $username = $rowUser;
    } elseif (preg_match('#/(?:users|@)/([^/]+)$#i', $actor, $m) || preg_match('#/profile/([^/]+)$#i', $actor, $m)) {
        $username = rawurldecode($m[1]);
    } else {
        $base = basename((string) (parse_url($actor, PHP_URL_PATH) ?: ''));
        if ($base !== '' && !ctype_digit($base)) {
            $username = rawurldecode($base);
        }
    }
    $acct = $host === '' ? $username : ($username . '@' . $host);
    $web = $actor;
    if ($host === 'bsky.app' && $username !== '' && !str_starts_with(strtolower($username), 'did:')) {
        $acct = $username;
        $web = 'https://bsky.app/profile/' . rawurlencode($username);
    } elseif ($host !== '' && $username !== 'user') {
        $web = 'https://' . $host . '/@' . rawurlencode($username);
    }
    if ($display === '') {
        $display = $username;
    }
    $id = $rowUser !== '' && $host !== ''
        ? $accountId('acct:' . mb_strtolower($username) . '@' . $host)
        : ap_masto_remote_account_id($actor);
    return [
        'id' => $id,
        'username' => $username,
        'acct' => $acct,
        'display_name' => $display,
        'locked' => false,
        'bot' => false,
        'discoverable' => true,
        'group' => false,
        'created_at' => $remoteCreated,
        'note' => '',
        'url' => $web,
        'uri' => $actor,
        'avatar' => $remoteAvatar,
        'avatar_static' => $remoteAvatar,
        'header' => $remoteAvatar,
        'header_static' => $remoteAvatar,
        'followers_count' => 0,
        'following_count' => 0,
        'statuses_count' => 0,
        'last_status_at' => null,
        'emojis' => [],
        'fields' => [],
    ];
};

$local = static function (string $key, string $userId) use ($localAvatar, $localHeader, $localCreated): array {
    $id = $key === 'cmdr_nova' ? '1' : $userId;
    return [
        'id' => $id,
        'username' => $key,
        'acct' => $key,
        'display_name' => $key,
        'locked' => false,
        'bot' => false,
        'discoverable' => false,
        'indexable' => true,
        'group' => false,
        'created_at' => $localCreated,
        'note' => '',
        'url' => 'https://vaak.monster/users/' . $key,
        'uri' => 'https://mkultra.monster/users/' . $key,
        'avatar' => $localAvatar,
        'avatar_static' => $localAvatar,
        'header' => $localHeader,
        'header_static' => $localHeader,
        'followers_count' => 0,
        'following_count' => 0,
        'statuses_count' => 0,
        'last_status_at' => null,
        'emojis' => [],
        'fields' => [],
    ];
};

$mentionOf = static function (string $key) use ($fail): array {
    if ($key === 'cmdr_nova') {
        return [
            'id' => '1',
            'username' => 'cmdr_nova',
            'url' => 'https://mkultra.monster/users/cmdr_nova',
            'acct' => 'cmdr_nova',
        ];
    }
    $fail('mention fixture only special-cases cmdr_nova');
};

$wrap = static function (array $fetch) use ($fail): array {
    $api = ap_masto_api_notification($fetch);
    if (!is_array($api)) {
        $fail('api notification dropped a fixture');
    }
    return $api;
};

$baseStatus = static function (array $over) use ($quoteApproval): array {
    return array_merge([
        'edited_at' => null,
        'in_reply_to_id' => null,
        'in_reply_to_account_id' => null,
        'sensitive' => false,
        'spoiler_text' => '',
        'visibility' => 'public',
        'language' => 'en',
        'replies_count' => 0,
        'reblogs_count' => 0,
        'favourites_count' => 0,
        'quotes_count' => 0,
        'favourited' => false,
        'reblogged' => false,
        'muted' => false,
        'bookmarked' => false,
        'pinned' => false,
        'reblog' => null,
        'application' => null,
        'media_attachments' => [],
        'mentions' => [],
        'tags' => [],
        'emojis' => [],
        'card' => null,
        'poll' => null,
        'quote' => null,
        'quote_approval' => $quoteApproval,
    ], $over);
};

$shana = 'https://example.social/users/shana_flux';
$candy = 'https://bsky.app/profile/candysins.bsky.social';
$ourNote = 'https://mkultra.monster/users/cmdr_nova/notes/abc';
$likedNote = 'https://mkultra.monster/users/luna_naloc/notes/liked';
$quotedNote = 'https://mkultra.monster/users/cmdr_nova/notes/quoted';
$parentNote = 'https://mkultra.monster/users/cmdr_nova/notes/parent1';
$quoter = 'https://example.social/users/quoter';
$follower = 'https://example.social/users/follower';
$nova = 'https://mkultra.monster/users/cmdr_nova';
$luna = 'https://mkultra.monster/users/luna_naloc';

$mentionStatusId = $snow($mentionAt, 1853, 2);
$mentionNotifId = $snow($mentionAt, 1853, 5);
$likeStatusId = $snow($likedAt, 11294, 0);
$likeNotifId = $snow($likeAt, 1891, 5);
$replyStatusId = $snow($replyAt, 44, 2);
$replyNotifId = $snow($replyAt, 44, 5);
$parentStatusId = $snow($parentAt, 40, 0);
$reblogStatusId = $snow($noteAt, 11249, 0);
$reblogNotifId = $snow($mentionAt, 70, 5);
$quoteStatusId = $snow($quoteAt, 7, 2);
$quoteNotifId = $snow($quoteAt, 7, 5);
$quotedStatusId = $snow($quotedAt, 50, 0);
$followNotifId = $snow($followAt, 9, 6);
$bskyFollowId = $snow($followAt, 10, 6);
$pollNotifId = $snow($pollAt, 3, 7);
$pollStatusId = $snow($noteAt, 80, 0);
$cardNotifId = $snow($replyAt, 55, 5);
$cardStatusId = $snow($replyAt, 55, 2);
$bskyQuoteNotifId = $snow($quoteAt, 11, 5);
$bskyQuoteStatusId = $snow($quoteAt, 11, 2);
$bskyRepostNotifId = $snow($likeAt, 12, 5);
$flatNotifId = $snow($quoteAt, 8, 5);
$flatStatusId = $snow($quoteAt, 8, 2);

$mentionMedia = [[
    'id' => '20018531',
    'type' => 'image',
    'url' => 'https://pics.example/a.jpg',
    'preview_url' => 'https://pics.example/a.jpg',
    'remote_url' => 'https://pics.example/a.jpg',
    'preview_remote_url' => null,
    'text_url' => null,
    'meta' => null,
    'description' => null,
    'blurhash' => null,
    'mediaType' => null,
]];
$quotedMedia = [[
    'id' => '9',
    'type' => 'image',
    'url' => 'https://pics.example/quoted.jpg',
    'preview_url' => 'https://pics.example/quoted.jpg',
    'remote_url' => null,
    'text_url' => null,
    'meta' => null,
    'description' => null,
    'blurhash' => null,
]];
$flatMedia = [[
    'id' => '20000081',
    'type' => 'image',
    'url' => 'https://pics.example/parent.jpg',
    'preview_url' => 'https://pics.example/parent.jpg',
    'remote_url' => 'https://pics.example/parent.jpg',
    'preview_remote_url' => null,
    'text_url' => null,
    'meta' => null,
    'description' => null,
    'blurhash' => null,
    'mediaType' => null,
], [
    'id' => '20000082',
    'type' => 'image',
    'url' => 'https://pics.example/quoted.jpg',
    'preview_url' => 'https://pics.example/quoted.jpg',
    'remote_url' => 'https://pics.example/quoted.jpg',
    'preview_remote_url' => null,
    'text_url' => null,
    'meta' => null,
    'description' => null,
    'blurhash' => null,
    'mediaType' => null,
]];
$card = [
    'url' => 'https://example.com/article',
    'title' => 'An article',
    'description' => 'Words',
    'type' => 'link',
    'author_name' => '',
    'author_url' => '',
    'provider_name' => 'Example',
    'provider_url' => 'https://example.com',
    'html' => '',
    'width' => 0,
    'height' => 0,
    'image' => 'https://pics.example/card.jpg',
    'embed_url' => '',
    'blurhash' => null,
    'language' => null,
    'published_at' => null,
    'authors' => [],
];

$localStatus = static function (string $id, string $whenIso, string $url, array $account, string $text, array $extra = []) use ($baseStatus, $html, $when): array {
    return $baseStatus(array_merge([
        'id' => $id,
        'created_at' => $when($whenIso),
        'uri' => $url,
        'url' => $url,
        'content' => $html($text),
        'account' => $account,
        'application' => ['name' => 'mkultra.monster', 'website' => 'https://mkultra.monster'],
    ], $extra));
};
$mentionStatus = static function (string $id, string $whenIso, string $url, array $account, string $text, array $extra = []) use ($baseStatus, $html, $when, $mentionOf): array {
    return $baseStatus(array_merge([
        'id' => $id,
        'created_at' => $when($whenIso),
        'uri' => $url,
        'url' => $url,
        'content' => $html($text),
        'account' => $account,
        'mentions' => [$mentionOf('cmdr_nova')],
        'in_reply_to' => null,
    ], $extra));
};
$notif = static function (string $id, string $type, string $actor, string $created, array $account, ?array $status) use ($group, $when, $wrap): array {
    $ref = is_array($status) && !empty($status['id']) ? (string) $status['id'] : $id;
    $fetch = [
        'id' => $id,
        'type' => $type,
        'group_key' => $type === 'follow' || $type === 'poll' ? ('ungrouped-' . $id) : $group($type, $actor, $ref),
        'created_at' => $when($created),
        'account' => $account,
        'status' => $status,
    ];
    return $wrap($fetch);
};

$novaAcct = $local('cmdr_nova', '17');
$lunaAcct = $local('luna_naloc', '14');
$shanaAcct = $remote($shana, 'Shana', ['username' => 'shana_flux', 'host' => 'example.social']);
$candyAcct = $remote($candy);
$quoterAcct = $remote($quoter, '', ['username' => 'quoter', 'host' => 'example.social']);
$followerAcct = $remote($follower);

$cases = [];
$push = static function (string $name, array $item, array $notes, array $api, array $extra = []) use (&$cases): void {
    $cases[] = array_merge([
        'name' => $name,
        'item' => $item,
        'notes' => $notes,
        'api' => $api,
    ], $extra);
};

$item = static function (array $over): array {
    return array_merge([
        'kind' => 'mention',
        'activity_id' => '',
        'is_bsky' => false,
        'owner_actor' => 'https://mkultra.monster/users/cmdr_nova',
        'status_local_id' => 0,
        'in_reply_to' => '',
        'media_urls' => '',
        'spoiler' => '',
        'sensitive' => false,
        'content' => '',
        'object_id' => '',
    ], $over);
};

$push('mention', $item([
    'id' => $mentionNotifId,
    'db_id' => 1853,
    'created' => $mentionAt,
    'actor_id' => $shana,
    'type_name' => 'mention',
    'object_id' => $ourNote,
    'content' => 'hello from shana',
    'media_urls' => '["https://pics.example/a.jpg"]',
]), [
    ['local_id' => 11249, 'note_id' => $ourNote, 'published' => $noteAt, 'content_text' => 'ours', 'visibility' => 'public'],
], [$notif($mentionNotifId, 'mention', $shana, $mentionAt, $shanaAcct, $mentionStatus(
    $mentionStatusId,
    $mentionAt,
    $ourNote,
    $shanaAcct,
    'hello from shana',
    ['media_attachments' => $mentionMedia]
))], ['remotes' => [['actor_id' => $shana, 'username' => 'shana_flux', 'host' => 'example.social', 'display_name' => 'Shana']]]);

$push('reply', $item([
    'id' => $replyNotifId,
    'db_id' => 44,
    'created' => $replyAt,
    'actor_id' => $shana,
    'type_name' => 'mention',
    'object_id' => 'https://example.social/users/shana_flux/statuses/9',
    'content' => 'replying to you',
    'in_reply_to' => $parentNote,
]), [
    ['local_id' => 40, 'note_id' => $parentNote, 'published' => $parentAt, 'content_text' => 'parent', 'visibility' => 'public'],
], [$notif($replyNotifId, 'mention', $shana, $replyAt, $shanaAcct, $mentionStatus(
    $replyStatusId,
    $replyAt,
    'https://example.social/users/shana_flux/statuses/9',
    $shanaAcct,
    'replying to you',
    [
        'in_reply_to_id' => $parentStatusId,
        'in_reply_to_account_id' => '1',
        'in_reply_to' => $parentNote,
    ]
))], ['remotes' => [['actor_id' => $shana, 'username' => 'shana_flux', 'host' => 'example.social', 'display_name' => 'Shana']]]);

$push('favourite', $item([
    'id' => $likeNotifId,
    'db_id' => 1891,
    'created' => $likeAt,
    'actor_id' => $shana,
    'type_name' => 'favourite',
    'object_id' => $likedNote,
    'content' => '',
    'owner_actor' => $luna,
]), [
    ['local_id' => 11294, 'note_id' => $likedNote, 'published' => $likedAt, 'content_text' => 'the liked post', 'visibility' => 'public'],
], [$notif($likeNotifId, 'favourite', $shana, $likeAt, $shanaAcct, $localStatus(
    $likeStatusId,
    $likedAt,
    $likedNote,
    $lunaAcct,
    'the liked post'
))], [
    'owner_key' => 'luna_naloc',
    'owner_id' => 14,
    'owner_actor' => $luna,
    'remotes' => [['actor_id' => $shana, 'username' => 'shana_flux', 'host' => 'example.social', 'display_name' => 'Shana']],
]);

$push('reblog', $item([
    'id' => $reblogNotifId,
    'db_id' => 70,
    'created' => $mentionAt,
    'actor_id' => $shana,
    'type_name' => 'reblog',
    'object_id' => $ourNote,
    'content' => '',
]), [
    ['local_id' => 11249, 'note_id' => $ourNote, 'published' => $noteAt, 'content_text' => 'ours', 'visibility' => 'public'],
], [$notif($reblogNotifId, 'reblog', $shana, $mentionAt, $shanaAcct, $localStatus(
    $reblogStatusId,
    $noteAt,
    $ourNote,
    $novaAcct,
    'ours'
))], ['remotes' => [['actor_id' => $shana, 'username' => 'shana_flux', 'host' => 'example.social', 'display_name' => 'Shana']]]);

$push('follow', $item([
    'id' => $followNotifId,
    'kind' => 'follow',
    'db_id' => 9,
    'created' => $followAt,
    'actor_id' => $follower,
    'type_name' => 'follow',
]), [], [$notif($followNotifId, 'follow', $follower, $followAt, $followerAcct, null)]);

$push('quote', $item([
    'id' => $quoteNotifId,
    'db_id' => 7,
    'created' => $quoteAt,
    'actor_id' => $quoter,
    'type_name' => 'quote',
    'object_id' => 'https://example.social/users/quoter/statuses/1',
    'content' => "commentary\n\n↪ QT {$quotedNote}",
]), [
    ['local_id' => 50, 'note_id' => $quotedNote, 'published' => $quotedAt, 'content_text' => 'quoted words', 'visibility' => 'public'],
], [$notif($quoteNotifId, 'quote', $quoter, $quoteAt, $quoterAcct, $mentionStatus(
    $quoteStatusId,
    $quoteAt,
    'https://example.social/users/quoter/statuses/1',
    $quoterAcct,
    'commentary',
    ['quote' => [
        'state' => 'accepted',
        'quoted_status_id' => $quotedStatusId,
        'quoted_status' => $localStatus($quotedStatusId, $quotedAt, $quotedNote, $novaAcct, 'quoted words', [
            'media_attachments' => $quotedMedia,
        ]),
    ]]
))], [
    'remotes' => [['actor_id' => $quoter, 'username' => 'quoter', 'host' => 'example.social', 'display_name' => '']],
    'media' => ['50' => $quotedMedia],
]);

$push('quote_flattened', $item([
    'id' => $flatNotifId,
    'db_id' => 8,
    'created' => $quoteAt,
    'actor_id' => $quoter,
    'type_name' => 'quote',
    'object_id' => 'https://example.social/users/quoter/statuses/2',
    'content' => "commentary\n\n↪ QT {$quotedNote}",
    'media_urls' => '["https://pics.example/parent.jpg","https://pics.example/quoted.jpg"]',
]), [
    ['local_id' => 50, 'note_id' => $quotedNote, 'published' => $quotedAt, 'content_text' => 'quoted words', 'visibility' => 'public'],
], [$notif($flatNotifId, 'quote', $quoter, $quoteAt, $quoterAcct, $mentionStatus(
    $flatStatusId,
    $quoteAt,
    'https://example.social/users/quoter/statuses/2',
    $quoterAcct,
    'commentary',
    [
        'media_attachments' => $flatMedia,
        'quote' => [
            'state' => 'accepted',
            'quoted_status_id' => $quotedStatusId,
            'quoted_status' => $localStatus($quotedStatusId, $quotedAt, $quotedNote, $novaAcct, 'quoted words', [
                'media_attachments' => $quotedMedia,
            ]),
        ],
    ]
))], [
    'remotes' => [['actor_id' => $quoter, 'username' => 'quoter', 'host' => 'example.social', 'display_name' => '']],
    'media' => ['50' => $quotedMedia],
]);

$push('poll', $item([
    'id' => $pollNotifId,
    'kind' => 'poll',
    'db_id' => 3,
    'created' => $pollAt,
    'actor_id' => $nova,
    'type_name' => 'poll',
    'object_id' => $ourNote,
    'status_local_id' => 80,
    'owner_actor' => $nova,
]), [
    ['local_id' => 80, 'note_id' => 'https://mkultra.monster/users/cmdr_nova/notes/pollnote', 'published' => $noteAt, 'content_text' => 'which one', 'visibility' => 'public'],
], [$notif($pollNotifId, 'poll', $nova, $pollAt, $novaAcct, $localStatus(
    $pollStatusId,
    $noteAt,
    'https://mkultra.monster/users/cmdr_nova/notes/pollnote',
    $novaAcct,
    'which one'
))]);

$bskyAt = 'at://did:plc:candy/app.bsky.feed.post/3abc';
$bskyHttps = 'https://bsky.app/profile/did:plc:candy/post/3abc';
$push('bluesky_like', $item([
    'id' => $likeNotifId,
    'db_id' => 1891,
    'created' => $likeAt,
    'actor_id' => $candy,
    'type_name' => 'favourite',
    'object_id' => $bskyHttps,
    'activity_id' => $bskyAt . '#like',
    'is_bsky' => true,
    'owner_actor' => $luna,
    'content' => 'liked your Bluesky post',
]), [
    ['local_id' => 11294, 'note_id' => $likedNote, 'published' => $likedAt, 'content_text' => 'the liked post', 'visibility' => 'public'],
], [$notif($likeNotifId, 'favourite', $candy, $likeAt, $candyAcct, $localStatus(
    $likeStatusId,
    $likedAt,
    $likedNote,
    $lunaAcct,
    'the liked post'
))], [
    'owner_key' => 'luna_naloc',
    'owner_id' => 14,
    'owner_actor' => $luna,
    'bsky_note' => [$bskyAt => $likedNote, $bskyHttps => $likedNote],
]);

$push('bluesky_repost', $item([
    'id' => $bskyRepostNotifId,
    'db_id' => 12,
    'created' => $likeAt,
    'actor_id' => $candy,
    'type_name' => 'reblog',
    'object_id' => $bskyHttps,
    'activity_id' => $bskyAt . '#repost',
    'is_bsky' => true,
    'owner_actor' => $luna,
    'content' => '',
]), [
    ['local_id' => 11294, 'note_id' => $likedNote, 'published' => $likedAt, 'content_text' => 'the liked post', 'visibility' => 'public'],
], [$notif($bskyRepostNotifId, 'reblog', $candy, $likeAt, $candyAcct, $localStatus(
    $likeStatusId,
    $likedAt,
    $likedNote,
    $lunaAcct,
    'the liked post'
))], [
    'owner_key' => 'luna_naloc',
    'owner_id' => 14,
    'owner_actor' => $luna,
    'bsky_note' => [$bskyAt => $likedNote, $bskyHttps => $likedNote],
]);

$push('bluesky_follow', $item([
    'id' => $bskyFollowId,
    'kind' => 'follow',
    'db_id' => 10,
    'created' => $followAt,
    'actor_id' => $candy,
    'type_name' => 'follow',
    'is_bsky' => true,
    'owner_actor' => $luna,
]), [], [$notif($bskyFollowId, 'follow', $candy, $followAt, $candyAcct, null)], [
    'owner_key' => 'luna_naloc',
    'owner_id' => 14,
    'owner_actor' => $luna,
]);

$push('bluesky_quote', $item([
    'id' => $bskyQuoteNotifId,
    'db_id' => 11,
    'created' => $quoteAt,
    'actor_id' => $candy,
    'type_name' => 'quote',
    'object_id' => 'https://bsky.app/profile/candysins.bsky.social/post/3quote',
    'activity_id' => 'at://did:plc:candy/app.bsky.feed.post/3quote',
    'is_bsky' => true,
    'content' => "nice\n\n↪ QT {$quotedNote}",
]), [
    ['local_id' => 50, 'note_id' => $quotedNote, 'published' => $quotedAt, 'content_text' => 'quoted words', 'visibility' => 'public'],
], [$notif($bskyQuoteNotifId, 'quote', $candy, $quoteAt, $candyAcct, $mentionStatus(
    $bskyQuoteStatusId,
    $quoteAt,
    'https://bsky.app/profile/candysins.bsky.social/post/3quote',
    $candyAcct,
    'nice',
    ['quote' => [
        'state' => 'accepted',
        'quoted_status_id' => $quotedStatusId,
        'quoted_status' => $localStatus($quotedStatusId, $quotedAt, $quotedNote, $novaAcct, 'quoted words', [
            'media_attachments' => $quotedMedia,
        ]),
    ]]
))], ['media' => ['50' => $quotedMedia]]);

$push('card', $item([
    'id' => $cardNotifId,
    'db_id' => 55,
    'created' => $replyAt,
    'actor_id' => $shana,
    'type_name' => 'mention',
    'object_id' => 'https://example.social/users/shana_flux/statuses/55',
    'content' => 'Read https://example.com/article today',
]), [], [$notif($cardNotifId, 'mention', $shana, $replyAt, $shanaAcct, $mentionStatus(
    $cardStatusId,
    $replyAt,
    'https://example.social/users/shana_flux/statuses/55',
    $shanaAcct,
    'Read https://example.com/article today',
    ['card' => $card]
))], [
    'remotes' => [['actor_id' => $shana, 'username' => 'shana_flux', 'host' => 'example.social', 'display_name' => 'Shana']],
    'cards' => ['https://example.com/article' => $card],
]);

$push('missing_favourite', $item([
    'id' => $likeNotifId,
    'db_id' => 1891,
    'created' => $likeAt,
    'actor_id' => $shana,
    'type_name' => 'favourite',
    'object_id' => 'https://example.social/users/shana_flux/statuses/404',
    'content' => '',
]), [], []);

$classifyRows = [
    ['name' => 'valerie', 'activity_type' => 'Create', 'type' => 'Note', 'object_id' => 'https://cmplxdecay.space/users/valerie/statuses/1', 'actor_id' => 'https://cmplxdecay.space/users/valerie', 'content' => 'Since my website (mkultra.monster) has been quiet, here is a post.', 'in_reply_to' => '', 'owner_actor_id' => $nova, 'activity_id' => 'https://cmplxdecay.space/activities/1'],
    ['name' => 'addressed', 'activity_type' => 'Create', 'type' => 'Note', 'object_id' => 'https://example.social/users/shana_flux/statuses/2', 'actor_id' => $shana, 'content' => 'hello @cmdr_nova', 'in_reply_to' => '', 'owner_actor_id' => $nova, 'activity_id' => ''],
    ['name' => 'longer_name', 'activity_type' => 'Create', 'type' => 'Note', 'object_id' => 'https://example.social/users/shana_flux/statuses/3', 'actor_id' => $shana, 'content' => 'hello @cmdr_novae', 'in_reply_to' => '', 'owner_actor_id' => $nova, 'activity_id' => ''],
    ['name' => 'email_word', 'activity_type' => 'Create', 'type' => 'Note', 'object_id' => 'https://example.social/users/shana_flux/statuses/4', 'actor_id' => $shana, 'content' => 'mail name@cmdr_nova today', 'in_reply_to' => '', 'owner_actor_id' => $nova, 'activity_id' => ''],
    ['name' => 'like', 'activity_type' => 'Like', 'type' => 'Like', 'object_id' => $ourNote . '#like-1', 'actor_id' => $shana, 'content' => '', 'in_reply_to' => '', 'owner_actor_id' => $nova, 'activity_id' => ''],
    ['name' => 'announce_ours', 'activity_type' => 'Announce', 'type' => 'Announce', 'object_id' => $ourNote, 'actor_id' => $shana, 'content' => '', 'in_reply_to' => '', 'owner_actor_id' => $nova, 'activity_id' => ''],
    ['name' => 'announce_other', 'activity_type' => 'Announce', 'type' => 'Announce', 'object_id' => 'https://example.social/users/shana_flux/statuses/8', 'actor_id' => $shana, 'content' => '', 'in_reply_to' => '', 'owner_actor_id' => $nova, 'activity_id' => ''],
    ['name' => 'quote_re', 'activity_type' => 'Create', 'type' => 'Note', 'object_id' => 'https://example.social/users/quoter/statuses/1', 'actor_id' => $quoter, 'content' => 'RE: https://mkultra.monster/users/cmdr_nova/notes/abc', 'in_reply_to' => '', 'owner_actor_id' => $nova, 'activity_id' => ''],
    ['name' => 'bsky_like', 'activity_type' => 'Like', 'type' => 'Like', 'object_id' => $bskyHttps, 'actor_id' => $candy, 'content' => '', 'in_reply_to' => '', 'owner_actor_id' => $luna, 'activity_id' => $bskyAt . '#like'],
    ['name' => 'bsky_mention', 'activity_type' => 'Create', 'type' => 'Note', 'object_id' => 'https://bsky.app/profile/candysins.bsky.social/post/3m', 'actor_id' => $candy, 'content' => 'no handle here', 'in_reply_to' => '', 'owner_actor_id' => $nova, 'activity_id' => 'at://did:plc:candy/app.bsky.feed.post/3m'],
    ['name' => 'webmention', 'activity_type' => 'Webmention', 'type' => 'Note', 'object_id' => 'https://example.test/posts/1', 'actor_id' => 'https://example.test/users/a', 'content' => 'no mention', 'in_reply_to' => '', 'owner_actor_id' => $nova, 'activity_id' => ''],
    ['name' => 'reply_to_note', 'activity_type' => 'Create', 'type' => 'Note', 'object_id' => 'https://example.social/users/shana_flux/statuses/9', 'actor_id' => $shana, 'content' => 'no handle in the body', 'in_reply_to' => $parentNote, 'owner_actor_id' => $nova, 'activity_id' => ''],
    ['name' => 'update', 'activity_type' => 'Update', 'type' => 'Note', 'object_id' => 'https://example.social/users/shana_flux/statuses/9', 'actor_id' => $shana, 'content' => 'edited', 'in_reply_to' => '', 'owner_actor_id' => $nova, 'activity_id' => ''],
    ['name' => 'person', 'activity_type' => 'Create', 'type' => 'Person', 'object_id' => 'https://example.social/users/shana_flux', 'actor_id' => $shana, 'content' => '', 'in_reply_to' => '', 'owner_actor_id' => $nova, 'activity_id' => ''],
    ['name' => 'bite', 'activity_type' => 'Bite', 'type' => 'Bite', 'object_id' => $ourNote, 'actor_id' => $shana, 'content' => '', 'in_reply_to' => '', 'owner_actor_id' => $nova, 'activity_id' => ''],
];
$classify = [];
foreach ($classifyRows as $row) {
    $name = $row['name'];
    unset($row['name']);
    $classify[] = ['name' => $name, 'row' => $row, 'type' => ap_masto_mention_notif_type($row)];
}

$roundIn = [[
    'id' => '5',
    'type' => 'bite',
    'group_key' => 'bite-abc',
    'created_at' => '2026-10-24T00:00:00.000Z',
    'account' => [
        'id' => '9',
        'username' => 'a',
        'acct' => 'a@h',
        'uri' => 'https://h.example/users/a',
        'indexable' => true,
        'group' => false,
        'source' => ['privacy' => 'public'],
        'created_at' => '2020-01-01T00:00:00.000Z',
        'avatar' => $remoteAvatar,
        'header' => $remoteAvatar,
        'display_name' => 'A',
    ],
    'status' => [
        'id' => '6',
        'created_at' => '2026-10-24T00:00:00.000Z',
        'account' => $novaAcct,
        'content' => 'x',
        'muted' => false,
        'in_reply_to' => 'https://example.social/x',
        'application' => null,
        'quote' => null,
        'quote_approval' => $quoteApproval,
        'media_attachments' => [[
            'id' => '1',
            'type' => 'image',
            'url' => 'https://pics.example/a.jpg',
            'preview_url' => 'https://pics.example/a.jpg',
            'preview_remote_url' => 'https://pics.example/a.jpg',
            'mediaType' => 'image/jpeg',
        ]],
    ],
]];
$roundOut = ap_masto_notifications_api_list($roundIn);
if (count($roundOut) !== 1 || ($roundOut[0]['type'] ?? '') !== 'favourite') {
    $fail('api list did not rewrite bite');
}

foreach ($cases as $case) {
    if (($case['name'] ?? '') !== 'quote_flattened') {
        continue;
    }
    $status = $case['api'][0]['status'] ?? null;
    $outer = is_array($status['media_attachments'] ?? null) ? $status['media_attachments'] : [];
    $inner = $status['quote']['quoted_status']['media_attachments'] ?? null;
    $outerUrls = [];
    foreach ($outer as $attachment) {
        if (is_array($attachment) && is_string($attachment['url'] ?? null)) {
            $outerUrls[] = $attachment['url'];
        }
    }
    $innerUrl = is_array($inner) && isset($inner[0]['url']) ? (string) $inner[0]['url'] : '';
    if ($innerUrl !== 'https://pics.example/quoted.jpg' || in_array('https://pics.example/quoted.jpg', $outerUrls, true) || !in_array('https://pics.example/parent.jpg', $outerUrls, true)) {
        $fail('quoted media must stay inside the quote');
    }
}

$doc = [
    'cases' => $cases,
    'classify' => $classify,
    'api_roundtrip' => ['input' => $roundIn, 'output' => $roundOut],
    'ids' => [
        'mention_status' => $mentionStatusId,
        'mention_notif' => $mentionNotifId,
        'like_status' => $likeStatusId,
        'shana' => $accountId('acct:shana_flux@example.social'),
        'candy' => ap_masto_remote_account_id($candy),
    ],
];
echo 'PARITY_JSON=' . json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
echo "notif-db-page-parity-smoke: ok\n";
