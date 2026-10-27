<?php
declare(strict_types=1);
/**
 * Bluesky block, mute, listblock, and listmute senders are dropped from the
 * web notification filter, the Mastodon API filter, and the projection.
 * Handle → DID uses the local cache only. No network.
 */
$api = dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}

$fail = static function (string $msg): never {
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
};
$check = static function (bool $ok, string $label) use ($fail): void {
    if (!$ok) {
        $fail($label);
    }
};

final class NotifHideCountingPdo extends PDO
{
    public int $prepares = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepares++;
        return parent::prepare($query, $options);
    }
}

$db = new NotifHideCountingPdo('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE bsky_hide_dids (
    owner_user_id INTEGER NOT NULL,
    did TEXT NOT NULL,
    reason TEXT NOT NULL,
    list_uri TEXT NOT NULL DEFAULT "",
    updated_at TEXT NOT NULL
)');
$db->exec('CREATE TABLE bsky_posts (author_handle TEXT, author_did TEXT)');
$db->exec('CREATE TABLE bsky_actor_profiles (actor_ref TEXT, did TEXT, profile_json TEXT)');
$db->exec('CREATE TABLE masto_statuses (note_id TEXT, content_text TEXT, spoiler_text TEXT)');
$db->exec('CREATE TABLE outbox_notes (id TEXT PRIMARY KEY)');
$db->exec('CREATE TABLE ap_notification_projection (
    owner_user_id INTEGER NOT NULL,
    notification_id TEXT NOT NULL,
    notification_type TEXT NOT NULL,
    actor_id TEXT NOT NULL DEFAULT "",
    status_id TEXT NOT NULL DEFAULT "",
    payload_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, notification_id)
)');
$GLOBALS['smoke_db'] = $db;
$GLOBALS['smoke_phrases'] = [];
$GLOBALS['smoke_native_hidden'] = [];
$GLOBALS['ap_bsky_hide_did_cache'] = [];

$ins = $db->prepare('INSERT INTO bsky_hide_dids (owner_user_id, did, reason, list_uri, updated_at) VALUES (?, ?, ?, ?, ?)');
$ins->execute([7, 'did:plc:listblock', 'listblock', 'at://did:plc:me/app.bsky.graph.list/block', '2026-10-27T00:00:00Z']);
$ins->execute([7, 'did:plc:block', 'block', '', '2026-10-27T00:00:00Z']);
$ins->execute([7, 'did:plc:mute', 'mute', '', '2026-10-27T00:00:00Z']);
$ins->execute([7, 'did:plc:listmute', 'listmute', 'at://did:plc:me/app.bsky.graph.list/mute', '2026-10-27T00:00:00Z']);
$ins->execute([8, 'did:plc:listblock', 'listblock', '', '2026-10-27T00:00:00Z']);
$db->prepare('INSERT INTO bsky_posts (author_handle, author_did) VALUES (?, ?)')->execute([
    'broadeyeview.bsky.social', 'did:plc:listblock',
]);
$db->prepare('INSERT INTO bsky_posts (author_handle, author_did) VALUES (?, ?)')->execute([
    'blocked.bsky.social', 'did:plc:block',
]);
$db->prepare('INSERT INTO bsky_posts (author_handle, author_did) VALUES (?, ?)')->execute([
    'muted.bsky.social', 'did:plc:mute',
]);
$db->prepare('INSERT INTO bsky_posts (author_handle, author_did) VALUES (?, ?)')->execute([
    'friend.bsky.social', 'did:plc:friend',
]);
$db->prepare('INSERT INTO bsky_actor_profiles (actor_ref, did, profile_json) VALUES (?, ?, ?)')->execute([
    'https://bsky.app/profile/listmuted.bsky.social',
    'did:plc:listmute',
    '{"did":"did:plc:listmute","handle":"listmuted.bsky.social"}',
]);
$live = 'https://mkultra.monster/users/ada/notes/live';
$gone = 'https://mkultra.monster/users/ada/notes/gone';
$db->prepare('INSERT INTO masto_statuses (note_id, content_text, spoiler_text) VALUES (?, ?, ?)')->execute([$live, 'visible post', '']);
$db->prepare('INSERT INTO outbox_notes (id) VALUES (?)')->execute([$live]);

function ap_db(): PDO
{
    return $GLOBALS['smoke_db'];
}
function ap_db_masto_owner_user_id(): int
{
    return 7;
}
function ap_muted_words_phrases_cached(int $ownerUserId, bool $refresh = false): array
{
    return $ownerUserId > 0 ? $GLOBALS['smoke_phrases'] : [];
}
function ap_search_account_hidden(array $acc, int $ownerUserId): bool
{
    return !empty($GLOBALS['smoke_native_hidden'][(string) ($acc['uri'] ?? '')]);
}
function ap_bsky_hide_did_set(int $ownerUserId): array
{
    if (!isset($GLOBALS['ap_bsky_hide_did_cache']) || !is_array($GLOBALS['ap_bsky_hide_did_cache'])) {
        $GLOBALS['ap_bsky_hide_did_cache'] = [];
    }
    if (isset($GLOBALS['ap_bsky_hide_did_cache'][$ownerUserId])) {
        return $GLOBALS['ap_bsky_hide_did_cache'][$ownerUserId];
    }
    $out = [];
    $st = ap_db()->prepare('SELECT DISTINCT did FROM bsky_hide_dids WHERE owner_user_id = ?');
    $st->execute([$ownerUserId]);
    while ($row = $st->fetch()) {
        $did = trim((string) ($row['did'] ?? ''));
        if ($did !== '') {
            $out[$did] = true;
        }
    }
    $GLOBALS['ap_bsky_hide_did_cache'][$ownerUserId] = $out;
    return $out;
}

reference_load($api . '/ap-db.php', [
    'ap_notification_bsky_ref_identity',
    'ap_notification_bsky_sender_refs',
    'ap_notification_bsky_sender_did',
    'ap_notification_owner_bsky_hide_set',
    'ap_notifications_bsky_handle_did_map',
    'ap_notifications_drop_bsky_hidden_senders',
    'ap_notification_is_local_note',
    'ap_notification_target_object_id',
    'ap_notification_missing_local_notes',
    'ap_notification_collect_item_uris',
    'ap_notification_item_targets_missing',
    'ap_notification_tree_muted',
    'ap_muted_words_normalize_haystack',
    'ap_muted_words_match',
    'ap_notification_item_muted',
    'ap_notifications_apply_viewer_filters',
]);
reference_load($api . '/ap-masto-entities.php', [
    'ap_notification_projection_payload',
    'ap_notification_projection_item',
    'ap_notification_projection_write',
    'ap_notification_projection_read',
    'ap_masto_notifications_drop_hidden',
    'ap_masto_notifications_mark_cheap_hidden',
]);
reference_load($api . '/ap-bsky.php', [
    'ap_bsky_notification_author_hidden',
]);

$viewer = ['uri' => 'https://mkultra.monster/users/ada', 'url' => 'https://mkultra.monster/users/ada'];
$bskyAccount = static function (string $handleOrDid): array {
    $uri = 'https://bsky.app/profile/' . $handleOrDid;
    return [
        'id' => 'acct-' . $handleOrDid,
        'uri' => $uri,
        'url' => $uri,
        'username' => $handleOrDid,
        'acct' => $handleOrDid,
    ];
};
$card = static function (string $id, string $type, array $account, ?string $activityId = null, ?array $status = null) use ($viewer): array {
    $item = [
        'id' => $id,
        'type' => $type,
        'created_at' => '2026-10-27T12:00:' . str_pad((string) min(59, (int) $id), 2, '0', STR_PAD_LEFT) . 'Z',
        'account' => $account,
        'status' => $status ?? [
            'uri' => 'https://bsky.app/profile/example/post/' . $id,
            'content' => 'hello',
            'account' => $account,
        ],
    ];
    if ($activityId !== null) {
        $item['activity_id'] = $activityId;
    }
    if ($type === 'favourite' || $type === 'reblog') {
        $item['status'] = [
            'uri' => 'https://mkultra.monster/users/ada/notes/live',
            'content' => 'my post',
            'account' => $viewer,
        ];
    }
    if ($type === 'follow') {
        $item['status'] = null;
    }
    return $item;
};

// broadeyeview is the production shape: a Note whose card only has the handle.
// The DID is listblock and lives in bsky_posts, not on the card.
$items = [
    $card('1997', 'mention', $bskyAccount('broadeyeview.bsky.social')),
    $card('2', 'mention', $bskyAccount('blocked.bsky.social'), 'at://did:plc:block/app.bsky.feed.post/reply', [
        'uri' => 'https://bsky.app/profile/blocked.bsky.social/post/reply',
        'content' => 'a reply',
        'in_reply_to_id' => 'parent',
        'account' => $bskyAccount('blocked.bsky.social'),
    ]),
    $card('3', 'favourite', $bskyAccount('muted.bsky.social'), 'at://did:plc:mute/app.bsky.feed.like/1'),
    // listmute is only in bsky_actor_profiles, and this card has no at:// DID,
    // so the page resolves it from the profile cache rather than the activity id.
    $card('4', 'reblog', $bskyAccount('listmuted.bsky.social')),
    $card('5', 'follow', $bskyAccount('broadeyeview.bsky.social')),
    $card('6', 'quote', $bskyAccount('did:plc:block'), 'at://did:plc:block/app.bsky.feed.post/quote'),
    $card('7', 'mention', $bskyAccount('friend.bsky.social'), 'at://did:plc:friend/app.bsky.feed.post/1'),
    $card('8', 'favourite', $bskyAccount('friend.bsky.social'), 'at://did:plc:friend/app.bsky.feed.like/1'),
    [
        'id' => '9',
        'type' => 'mention',
        'created_at' => '2026-10-27T11:00:00Z',
        'account' => ['id' => 'fedi', 'uri' => 'https://friend.example/users/kind', 'url' => 'https://friend.example/users/kind'],
        'status' => ['uri' => 'https://friend.example/users/kind/notes/1', 'content' => 'hello', 'account' => ['uri' => 'https://friend.example/users/kind']],
    ],
];
// Follow rows from the events scan carry the at:// object id, not a hydrated account.
$items[] = [
    'id' => '10',
    'type' => 'follow',
    'row' => [
        'actor_id' => 'https://bsky.app/profile/listmuted.bsky.social',
        'object_id' => 'at://did:plc:listmute/app.bsky.graph.follow/1',
    ],
];

$before = $db->prepares;
$dropped = ap_notifications_drop_bsky_hidden_senders($items, 7);
$hideQueries = $db->prepares - $before;
$droppedIds = array_column($dropped, 'id');
$check($droppedIds === ['7', '8', '9'], 'hide helper kept ' . json_encode($droppedIds));
// One hide-set read, one bsky_posts read, one profile-cache read. Not one per card.
$check($hideQueries <= 3, 'hide helper used ' . $hideQueries . ' queries for the page');
$again = $db->prepares;
$check(array_column(ap_notifications_drop_bsky_hidden_senders($items, 7), 'id') === ['7', '8', '9'], 'second hide read matches');
$check($db->prepares === $again, 'cached hide set and handle map did not query again');

$web = ap_notifications_apply_viewer_filters($items, 7);
$webIds = array_column($web, 'id');
$check($webIds === ['7', '8', '9'], 'web filter kept ' . json_encode($webIds));

$apiIds = array_column(ap_masto_notifications_drop_hidden($items, 7), 'id');
$check($apiIds === ['7', '8', '9'], 'mastodon filter kept ' . json_encode($apiIds));
$check(array_column(ap_masto_notifications_drop_hidden($items), 'id') === ['7', '8', '9'], 'mastodon filter uses the API owner');

$GLOBALS['smoke_native_hidden'] = ['https://friend.example/users/kind' => true];
$nativeIds = array_column(ap_masto_notifications_drop_hidden($items, 7), 'id');
$check($nativeIds === ['7', '8'], 'native mute still drops, and bluesky hide still drops: ' . json_encode($nativeIds));
$GLOBALS['smoke_native_hidden'] = [];

$GLOBALS['smoke_phrases'] = ['spoiler phrase'];
$muted = $items;
$muted[6]['status']['content'] = '<p>SPOILER phrase</p>';
$mutedIds = array_column(ap_notifications_apply_viewer_filters($muted, 7), 'id');
$check($mutedIds === ['8', '9'], 'muted words still drop a visible sender: ' . json_encode($mutedIds));
$GLOBALS['smoke_phrases'] = [];
$check(array_column(ap_notifications_apply_viewer_filters($muted, 7), 'id') === ['7', '8', '9'], 'clearing the phrase brings the mention back');

$missing = [
    $card('11', 'favourite', $bskyAccount('friend.bsky.social'), 'at://did:plc:friend/app.bsky.feed.like/missing'),
];
$missing[0]['status']['uri'] = $gone;
$check(array_column(ap_notifications_apply_viewer_filters($missing, 7), 'id') === [], 'deleted local target still drops');
$missing[0]['status']['uri'] = $live;
$check(array_column(ap_notifications_apply_viewer_filters($missing, 7), 'id') === ['11'], 'live local target stays');

$otherOwner = ap_notifications_apply_viewer_filters([$items[0]], 8);
$check(array_column($otherOwner, 'id') === [], 'another owner with the same listblock did is hidden');
$GLOBALS['ap_bsky_hide_did_cache'] = [];
$stranger = ap_notifications_apply_viewer_filters([$items[0]], 9);
$check(array_column($stranger, 'id') === ['1997'], 'an owner with an empty hide set still sees the mention');

$filled = [
    $items[0],
    $items[2],
    $items[4],
    $items[5],
    $items[6],
    $items[7],
    $items[8],
];
foreach ($filled as $i => $item) {
    $filled[$i]['created_at'] = '2026-10-27T12:00:' . str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT) . 'Z';
}
ap_notification_projection_write(7, $filled);
$stored = $db->query('SELECT notification_id FROM ap_notification_projection WHERE owner_user_id = 7 ORDER BY notification_id')->fetchAll(PDO::FETCH_COLUMN);
sort($stored);
$check($stored === ['7', '8', '9'] || $stored === [7, 8, 9], 'projection fill did not store hidden senders: ' . json_encode($stored));

$stale = $items[0];
$stale['id'] = '1997';
$stale['created_at'] = '2026-10-27T13:00:00Z';
$payload = ap_notification_projection_payload($stale);
$check(is_string($payload) && $payload !== '', 'stale payload encodes');
$db->prepare(
    'INSERT INTO ap_notification_projection
        (owner_user_id, notification_id, notification_type, actor_id, status_id, payload_json, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([7, '1997', 'mention', 'acct-broadeyeview.bsky.social', '', $payload, $stale['created_at'], gmdate('c')]);
$want = ['mention', 'follow', 'favourite', 'reblog', 'quote'];
$read = ap_notification_projection_read(7, 3, $want, []);
$readIds = array_column($read, 'id');
$check(!in_array('1997', $readIds, true), 'projection read drops a stale hidden sender: ' . json_encode($readIds));
$check($readIds === ['9', '8', '7'], 'projection read serves the visible page: ' . json_encode($readIds));

$check(ap_bsky_notification_author_hidden(7, ['author' => ['did' => 'did:plc:listblock'], 'uri' => 'at://did:plc:listblock/app.bsky.feed.post/1']), 'ingest skips listblock');
$check(ap_bsky_notification_author_hidden(7, ['author' => ['did' => 'did:plc:block']]), 'ingest skips block');
$check(ap_bsky_notification_author_hidden(7, ['author' => ['handle' => 'muted.bsky.social'], 'uri' => 'at://did:plc:mute/app.bsky.feed.like/1']), 'ingest skips mute from the at:// uri');
$check(ap_bsky_notification_author_hidden(7, ['author' => ['did' => 'did:plc:listmute'], 'reason' => 'follow']), 'ingest skips listmute');
$check(!ap_bsky_notification_author_hidden(7, ['author' => ['did' => 'did:plc:friend'], 'uri' => 'at://did:plc:friend/app.bsky.feed.post/1']), 'ingest keeps a sender who is not hidden');
$check(!ap_bsky_notification_author_hidden(7, ['author' => ['handle' => 'unknown.bsky.social']]), 'ingest does not resolve a handle over the network');
$check(!ap_bsky_notification_author_hidden(9, ['author' => ['did' => 'did:plc:listblock']]), 'ingest keeps the same did for an owner who has not hidden them');
$bsky = (string) file_get_contents($api . '/ap-bsky.php');
$check(str_contains($bsky, 'if (ap_bsky_notification_author_hidden($ownerUserId, $notif))'), 'notification ingest consults the hide set before storing');
$check(strpos($bsky, 'ap_bsky_notification_author_hidden($ownerUserId, $notif)') < strpos($bsky, 'ap_mention_store($row)'), 'the hide check runs before a mention is stored');

// Database pages mark the raw row before the limit is chosen. A hidden sender
// must not consume a slot the way a muted word or a deleted target does not.
$pool = [
    ['id' => 'p-listblock', 'hidden' => false, 'kind' => 'mention', 'row' => [
        'actor_id' => 'https://bsky.app/profile/broadeyeview.bsky.social',
        'activity_id' => 'at://did:plc:listblock/app.bsky.feed.post/1',
    ]],
    ['id' => 'p-block', 'hidden' => false, 'kind' => 'mention', 'row' => [
        'actor_id' => 'https://bsky.app/profile/blocked.bsky.social',
        'activity_id' => 'at://did:plc:block/app.bsky.feed.post/reply',
    ]],
    ['id' => 'p-mute', 'hidden' => false, 'kind' => 'mention', 'row' => [
        'actor_id' => 'https://bsky.app/profile/muted.bsky.social',
        'activity_id' => 'at://did:plc:mute/app.bsky.feed.like/1',
    ]],
    ['id' => 'p-listmute', 'hidden' => false, 'kind' => 'follow', 'row' => [
        'actor_id' => 'https://bsky.app/profile/listmuted.bsky.social',
        'object_id' => 'at://did:plc:listmute/app.bsky.graph.follow/1',
    ]],
    ['id' => 'p-friend', 'hidden' => false, 'kind' => 'mention', 'row' => [
        'actor_id' => 'https://bsky.app/profile/friend.bsky.social',
        'activity_id' => 'at://did:plc:friend/app.bsky.feed.post/1',
    ]],
];
ap_masto_notifications_mark_cheap_hidden(7, $pool, 0);
$marked = [];
foreach ($pool as $row) {
    if (!empty($row['hidden'])) {
        $marked[] = $row['id'];
    }
}
$check($marked === ['p-listblock', 'p-block', 'p-mute', 'p-listmute'], 'page fill marks every hide reason before the limit: ' . json_encode($marked));

echo "notif-bsky-hide-list-smoke: ok\n";
