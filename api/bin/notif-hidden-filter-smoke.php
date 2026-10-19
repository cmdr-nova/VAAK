<?php
declare(strict_types=1);
/**
 * Mastodon API notification reads apply mute/block at read time. The Redis
 * list cache is warmed before a mute or block can land, so the API reader must
 * drop hidden notifiers itself, and an unmute brings the rows back.
 * The embedded status is the viewer's own post, so only the notifier counts.
 * No live bootstrap, database, or network.
 */
$api = getenv('VAAK_NOTIF_PREVIEW_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}

$GLOBALS['smoke_hidden'] = ['https://pest.example/users/loud' => true, 'https://bsky.app/profile/spam.bsky.social' => true];
$GLOBALS['smoke_owner_seen'] = [];
function ap_db_masto_owner_user_id(): int { return 7; }
function ap_search_account_hidden(array $acc, int $ownerUserId): bool
{
    $GLOBALS['smoke_owner_seen'][] = $ownerUserId;
    return !empty($GLOBALS['smoke_hidden'][(string) ($acc['uri'] ?? '')]);
}
function ap_masto_notifications_fetch(int $limit = 40, ?string $maxId = null, ?string $sinceId = null, array $types = [], array $exclude = [], bool $bypassCache = false): array
{
    $own = ['account' => ['uri' => 'https://mkultra.monster/users/cmdr_nova']];
    return [
        ['id' => '3', 'type' => 'favourite', 'account' => ['uri' => 'https://pest.example/users/loud'], 'status' => $own],
        ['id' => '2', 'type' => 'mention', 'account' => ['uri' => 'https://friend.example/users/kind'], 'status' => null],
        ['id' => '1', 'type' => 'reblog', 'account' => ['uri' => 'https://bsky.app/profile/spam.bsky.social'], 'status' => $own],
        'not-a-notification',
        ['id' => '0', 'type' => 'favourite', 'account' => ['uri' => 'https://friend.example/users/kind'], 'status' => ['account' => ['uri' => 'https://pest.example/users/loud']]],
    ];
}

reference_load($api . '/ap-masto-entities.php', [
    'ap_masto_notifications_drop_hidden',
    'ap_masto_notifications_fetch_visible',
]);

$fail = static function (string $msg): never {
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
};

$ids = array_column(ap_masto_notifications_fetch_visible(), 'id');
if ($ids !== ['2', '0']) {
    $fail('hidden notifiers survived: ' . json_encode($ids));
}
if (array_unique($GLOBALS['smoke_owner_seen']) !== [7]) {
    $fail('filter did not use the API owner');
}
$GLOBALS['smoke_hidden'] = [];
$ids = array_column(ap_masto_notifications_fetch_visible(), 'id');
if ($ids !== ['3', '2', '1', '0']) {
    $fail('unmute did not bring rows back: ' . json_encode($ids));
}
$GLOBALS['smoke_hidden'] = ['https://friend.example/users/kind' => true];
$ids = array_column(ap_masto_notifications_drop_hidden(ap_masto_notifications_fetch(), 42), 'id');
if ($ids !== ['3', '1'] || end($GLOBALS['smoke_owner_seen']) !== 42) {
    $fail('explicit owner filter wrong: ' . json_encode($ids));
}
echo "notif-hidden-filter-smoke: ok\n";
