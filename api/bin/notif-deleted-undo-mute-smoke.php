<?php
declare(strict_types=1);
/**
 * Deleted posts, undone likes/boosts/follows, and muted words.
 * PHP decisions here match rust/vaak-worker/src/notif/mod.rs
 * deleted_local_target_and_muted_words_match_php.
 * No live database and no network.
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

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE mentions (
    id INTEGER PRIMARY KEY,
    owner_user_id INTEGER,
    object_id TEXT,
    actor_id TEXT,
    activity_id TEXT,
    content TEXT,
    deleted_at TEXT
)');
$db->exec('CREATE TABLE events (
    id INTEGER PRIMARY KEY,
    type TEXT,
    actor_id TEXT,
    object_id TEXT,
    target_actor TEXT,
    action_taken TEXT
)');
$db->exec('CREATE TABLE masto_statuses (
    local_id INTEGER PRIMARY KEY,
    note_id TEXT,
    content_text TEXT,
    spoiler_text TEXT
)');
$db->exec('CREATE TABLE outbox_notes (id TEXT PRIMARY KEY)');
$GLOBALS['smoke_db'] = $db;
$GLOBALS['smoke_phrases'] = [];
$GLOBALS['smoke_unread'] = [];
$GLOBALS['smoke_projection'] = 0;

function ap_db(): PDO
{
    return $GLOBALS['smoke_db'];
}
function ap_db_now(): string
{
    return '2026-10-27T00:00:00Z';
}
function ap_notification_projection_invalidate_all(): void
{
    $GLOBALS['smoke_projection']++;
}
function ap_notification_projection_invalidate_owner(int $owner): void
{
    $GLOBALS['smoke_projection']++;
}
function ap_masto_notifications_unread_invalidate(?int $ownerUserId = null): void
{
    if ($ownerUserId !== null && $ownerUserId > 0) {
        $GLOBALS['smoke_unread'][$ownerUserId] = true;
    }
}
function ap_muted_words_phrases_cached(int $ownerUserId, bool $refresh = false): array
{
    return $ownerUserId > 0 ? $GLOBALS['smoke_phrases'] : [];
}
function ap_db_owner_user_id_for_actor(?string $actorIdOrKey): int
{
    return rtrim((string) $actorIdOrKey, '/') === 'https://mkultra.monster/users/ada' ? 7 : 0;
}

reference_load($api . '/ap-db.php', [
    'ap_notification_is_local_note',
    'ap_notification_target_object_id',
    'ap_notification_unread_invalidate_owners',
    'ap_mention_active_owner_ids',
    'ap_notification_missing_local_notes',
    'ap_notification_event_deleted_objects',
    'ap_notification_status_texts_for_notes',
    'ap_muted_words_normalize_haystack',
    'ap_muted_words_match',
    'ap_notification_tree_muted',
    'ap_notification_item_muted',
    'ap_notification_collect_item_uris',
    'ap_notification_item_targets_missing',
    'ap_notifications_apply_viewer_filters',
    'ap_notification_mention_row_muted',
    'ap_notification_dismiss_follow',
    'ap_notification_dismiss_follow_object',
    'ap_notification_dismiss_local_interaction',
    'ap_mention_soft_delete',
    'ap_mention_interaction_object_id',
    'ap_mention_soft_delete_interaction',
    'ap_mention_soft_delete_at_uri',
    'ap_notification_stale_mention_ids',
]);
reference_load($api . '/ap-masto-entities.php', [
    'ap_masto_strip_announce_fragment',
    'ap_masto_mention_target_object_id',
]);

$note = 'https://mkultra.monster/users/ada/notes/post1';
$check(ap_notification_target_object_id($note . '#like-0123456789ab') === $note, 'like suffix');
$check(ap_notification_target_object_id($note . '#reblog-abcdef012345') === $note, 'reblog suffix');
$check(ap_notification_target_object_id($note . '#quote-deadbeef0000') === $note, 'quote suffix');
$check(ap_notification_target_object_id($note . '/#announce-4') === $note, 'announce fragment');
$check(ap_masto_mention_target_object_id($note . '#like-0123456789ab') === ap_notification_target_object_id($note . '#like-0123456789ab'), 'entity target matches');
$check(ap_notification_is_local_note($note), 'local note');
$check(!ap_notification_is_local_note('https://remote.test/users/ada/notes/post1'), 'remote note is not local');
$check(!ap_notification_is_local_note('https://bsky.app/profile/ada/post/abc'), 'bsky url is not local');

$ins = $db->prepare('INSERT INTO mentions (id, owner_user_id, object_id, actor_id, activity_id, content, deleted_at) VALUES (?, ?, ?, ?, ?, ?, NULL)');
$ins->execute([1, 7, $note, 'https://remote.test/users/bob', 'https://remote.test/activities/mention', 'hello ada']);
$ins->execute([2, 7, $note, 'https://remote.test/users/bea', 'https://remote.test/activities/reply', 'reply body']);
$actor = 'https://remote.test/users/cam';
$ins->execute([3, 7, ap_mention_interaction_object_id($note, $actor, 'like'), $actor, 'https://remote.test/activities/like', '']);
$ins->execute([4, 7, ap_mention_interaction_object_id($note, $actor, 'reblog'), $actor, 'https://remote.test/activities/boost', '']);
$ins->execute([5, 7, ap_mention_interaction_object_id($note, $actor, 'quote'), $actor, 'https://remote.test/activities/quote', 'quoted you']);
$ins->execute([6, 7, 'https://remote.test/users/bob/notes/kept', 'https://remote.test/users/bob', 'https://remote.test/activities/remote', 'remote mention']);
$check(ap_mention_soft_delete($note) === 5, 'delete hides mention, reply, like, boost, and quote');
$alive = $db->query('SELECT id FROM mentions WHERE deleted_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$check($alive === [6] || $alive === ['6'], 'remote mention stays: ' . json_encode($alive));
$check(isset($GLOBALS['smoke_unread'][7]), 'delete clears the owner unread cache');

$db->exec('UPDATE mentions SET deleted_at = NULL');
$GLOBALS['smoke_unread'] = [];
$check(ap_notification_dismiss_local_interaction($actor, $note, 'like', 'https://remote.test/activities/like') >= 1, 'undo like hides the like');
$likeGone = $db->query('SELECT deleted_at FROM mentions WHERE id = 3')->fetchColumn();
$check(is_string($likeGone) && $likeGone !== '', 'like row is deleted');
$replyKept = $db->query('SELECT deleted_at FROM mentions WHERE id = 2')->fetchColumn();
$check($replyKept === null || $replyKept === '', 'undo like leaves the reply');
$check(ap_notification_dismiss_local_interaction($actor, $note, 'reblog', 'https://remote.test/activities/boost') >= 1, 'undo boost hides the boost');
$boostGone = $db->query('SELECT deleted_at FROM mentions WHERE id = 4')->fetchColumn();
$check(is_string($boostGone) && $boostGone !== '', 'boost row is deleted');

$db->exec("INSERT INTO events (id, type, actor_id, object_id, target_actor, action_taken) VALUES (9, 'Follow', 'https://remote.test/users/cam', 'https://remote.test/follows/1', 'https://mkultra.monster/users/ada', 'local_accept_followback')");
$check(ap_notification_dismiss_follow('https://remote.test/users/cam/', 'https://mkultra.monster/users/ada/') === 1, 'unfollow hides the follow');
$action = $db->query('SELECT action_taken FROM events WHERE id = 9')->fetchColumn();
$check($action === 'undone', 'follow action is undone');
$db->exec("INSERT INTO events (id, type, actor_id, object_id, target_actor, action_taken) VALUES (10, 'Follow', 'https://bsky.app/profile/cam.bsky.social', 'at://did:plc:cam/app.bsky.graph.follow/rkey', 'https://mkultra.monster/users/ada', 'bsky_follow')");
$check(ap_notification_dismiss_follow_object('at://did:plc:cam/app.bsky.graph.follow/rkey') === 1, 'bluesky unfollow hides the follow');

$db->exec("INSERT INTO masto_statuses (local_id, note_id, content_text, spoiler_text) VALUES (1, 'https://mkultra.monster/users/ada/notes/live', 'visible post', '')");
$db->exec("INSERT INTO outbox_notes (id) VALUES ('https://mkultra.monster/users/ada/notes/live')");
$missing = ap_notification_missing_local_notes([
    $note,
    'https://mkultra.monster/users/ada/notes/live',
    'https://remote.test/users/ada/notes/post1',
]);
$check(isset($missing[$note]) && !isset($missing['https://mkultra.monster/users/ada/notes/live']), 'only the absent local note is missing');
$check(!isset($missing['https://remote.test/users/ada/notes/post1']), 'remote url is not missing');

$items = [
    ['id' => '1', 'type' => 'favourite', 'status' => ['uri' => $note, 'content' => 'hello']],
    ['id' => '2', 'type' => 'mention', 'status' => ['uri' => 'https://remote.test/users/ada/notes/post1', 'content' => 'hello']],
    ['id' => '3', 'type' => 'mention', 'status' => ['uri' => 'https://mkultra.monster/users/ada/notes/live', 'content' => 'hello']],
    ['id' => '4', 'type' => 'follow', 'account' => ['uri' => 'https://remote.test/users/cam']],
];
$ids = array_column(ap_notifications_apply_viewer_filters($items, 7), 'id');
$check($ids === ['2', '3', '4'], 'missing local target dropped, remote and follow kept: ' . json_encode($ids));

$GLOBALS['smoke_phrases'] = ['spoiler phrase', 'cats & dogs'];
$muted = [
    ['id' => 'a', 'type' => 'mention', 'status' => ['content' => '<p>SPOILER <em>phrase</em></p>']],
    ['id' => 'b', 'type' => 'mention', 'status' => ['content' => 'fine', 'spoiler_text' => 'Cats &amp; dogs']],
    ['id' => 'c', 'type' => 'mention', 'status' => ['content' => 'clean', 'quote' => ['quoted_status' => ['content' => 'spoiler phrase']]]],
    ['id' => 'd', 'type' => 'mention', 'status' => ['content' => 'A clean post']],
    ['id' => 'e', 'type' => 'follow'],
];
$ids = array_column(ap_notifications_apply_viewer_filters($muted, 7), 'id');
$check($ids === ['d', 'e'], 'muted word hides body, warning, and nested quote: ' . json_encode($ids));
$GLOBALS['smoke_phrases'] = [];
$ids = array_column(ap_notifications_apply_viewer_filters($muted, 7), 'id');
$check($ids === ['a', 'b', 'c', 'd', 'e'], 'no phrases leaves every notification');

$db->exec("INSERT INTO mentions (id, owner_user_id, object_id, actor_id, activity_id, content, deleted_at) VALUES (11, 8, 'https://remote.test/notes/gone', 'https://remote.test/users/bob', 'act-del', 'gone', NULL)");
$db->exec("INSERT INTO events (id, type, actor_id, object_id, target_actor, action_taken) VALUES (12, 'Delete', 'https://remote.test/users/bob', 'https://remote.test/notes/gone', NULL, 'log')");
$db->exec("INSERT INTO mentions (id, owner_user_id, object_id, actor_id, activity_id, content, deleted_at) VALUES (13, 8, '{$note}#like-aaaaaaaaaaaa', 'https://remote.test/users/cam', 'act-like', '', NULL)");
$stale = ap_notification_stale_mention_ids();
$staleIds = [];
foreach ($stale as $row) {
    $staleIds[(int) $row['id']] = (string) $row['reason'];
}
$check(($staleIds[13] ?? '') === 'local_missing', 'cleanup finds the missing local like');
$check(($staleIds[11] ?? '') === 'remote_delete', 'cleanup finds the remote Delete');
$check(!isset($staleIds[6]), 'cleanup keeps a remote mention with no Delete');
$check(!isset($staleIds[3]), 'an undone like is not still active');

$inbox = (string) file_get_contents($api . '/ap-inbox.php');
$check(str_contains($inbox, 'ap_mention_soft_delete($noteId)'), 'local delete soft-deletes mentions');
$check(str_contains($inbox, "strcasecmp(\$type, 'Tombstone')"), 'inbox accepts a Tombstone activity');
$dbSrc = (string) file_get_contents($api . '/ap-db.php');
$check(str_contains($dbSrc, 'ap_notification_dismiss_follow($actorId, $owner)'), 'follower removal dismisses the follow notification');
$check(str_contains($dbSrc, "ap_notification_dismiss_local_interaction(\$actor, \$target, 'like'"), 'unfavourite dismisses the like notification');
$check(str_contains($dbSrc, "ap_notification_dismiss_local_interaction(\$actor, \$target, 'reblog'"), 'unreblog dismisses the boost notification');
$entities = (string) file_get_contents($api . '/ap-masto-entities.php');
$check(substr_count($entities, '$presentNotifications(') >= 5, 'list, cache, grouped source, and marker reads share the filter');
$mastodon = (string) file_get_contents($api . '/ap-mastodon.php');
$check(!str_contains($mastodon, '/api/v2/filters'), 'no Mastodon v2 filters context to diverge from notifications');
$check(str_contains($mastodon, "'/api/v1/filters'"), 'v1 filters stub remains');

echo "notif-deleted-undo-mute-smoke: ok\n";
