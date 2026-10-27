<?php
declare(strict_types=1);
/**
 * A local reply in a Bluesky-origin thread notifies the local parent and any
 * locally mentioned user, and does not queue ActivityPub delivery or fan-out.
 * SQLite in memory; no network.
 */
$api = getenv('VAAK_BSKY_THREAD_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');

if (!defined('LOCAL_ACTOR')) {
    define('LOCAL_ACTOR', 'https://mkultra.monster/users/shana_flux');
}

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo']->exec('CREATE TABLE remote_actors (actor_id TEXT, username TEXT, host TEXT, updated_at TEXT)');
$GLOBALS['pdo']->exec('CREATE TABLE outbox_notes (
    id TEXT PRIMARY KEY, in_reply_to TEXT, raw_create_json TEXT, published TEXT, visibility TEXT
)');
$GLOBALS['pdo']->exec('CREATE TABLE mentions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER NOT NULL,
    owner_actor_id TEXT,
    created_at TEXT,
    activity_id TEXT,
    object_id TEXT,
    actor_id TEXT,
    type TEXT,
    content TEXT,
    in_reply_to TEXT,
    deleted_at TEXT,
    media_urls TEXT,
    activity_type TEXT,
    spoiler_text TEXT NOT NULL DEFAULT \'\',
    sensitive INTEGER NOT NULL DEFAULT 0,
    ask_actor TEXT NOT NULL DEFAULT \'\',
    ask_question TEXT NOT NULL DEFAULT \'\',
    ask_answer TEXT NOT NULL DEFAULT \'\',
    UNIQUE(owner_user_id, object_id)
)');
$GLOBALS['pdo']->exec('CREATE TABLE ap_notification_projection (
    owner_user_id INTEGER NOT NULL,
    notification_id TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, notification_id)
)');
$GLOBALS['pdo']->exec('CREATE TABLE ap_publish_delivery_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER,
    note_id TEXT UNIQUE,
    payload_json TEXT,
    status TEXT,
    priority INTEGER,
    attempts INTEGER,
    next_attempt_at TEXT,
    created_at TEXT,
    updated_at TEXT
)');
$GLOBALS['pdo']->exec('CREATE TABLE ap_fanout_delivery_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, activity_key TEXT)');
$GLOBALS['pdo']->exec("INSERT INTO remote_actors VALUES
    ('https://example.social/users/alice', 'alice', 'example.social', '2026-10-18')");
$GLOBALS['pdo']->exec("INSERT INTO ap_notification_projection VALUES (1, 'stale'), (4, 'stale')");

$users = [
    'https://mkultra.monster/users/cmdr_nova' => ['id' => 1, 'actor_id' => 'https://mkultra.monster/users/cmdr_nova', 'actor_key' => 'cmdr_nova'],
    'https://mkultra.monster/users/shana_flux' => ['id' => 2, 'actor_id' => 'https://mkultra.monster/users/shana_flux', 'actor_key' => 'shana_flux'],
    'https://mkultra.monster/users/ada' => ['id' => 4, 'actor_id' => 'https://mkultra.monster/users/ada', 'actor_key' => 'ada'],
];
$parent = 'https://mkultra.monster/users/cmdr_nova/notes/imported';
$GLOBALS['pdo']->prepare('INSERT INTO outbox_notes (id, in_reply_to, raw_create_json, published, visibility) VALUES (?,?,?,?,?)')
    ->execute([$parent, null, '{"object":{"vaakOrigin":"bluesky"}}', '2026-10-19T00:00:00Z', 'public']);

function ap_db(): PDO { return $GLOBALS['pdo']; }
function ap_db_now(): string { return '2026-10-27T00:00:00Z'; }
function ap_fix_utf8(string $value): string { return $value; }
function ap_log(string $message): void { $GLOBALS['logs'][] = $message; }
function ap_short(string $value): string { return $value; }
function ap_local_actor_id(): string { return 'https://mkultra.monster/users/shana_flux'; }
function ap_local_user_by_actor_id(string $actorId): ?array
{
    return $GLOBALS['users'][rtrim($actorId, '/')] ?? null;
}
function ap_local_user_by_username(string $username): ?array
{
    foreach ($GLOBALS['users'] as $user) {
        if ($user['actor_key'] === strtolower($username)) {
            return $user;
        }
    }
    return null;
}
function ap_db_owner_user_id_for_actor(string $actor): int
{
    $user = ap_local_user_by_actor_id($actor);
    return is_array($user) ? (int) $user['id'] : 0;
}
function ap_user_is_blocked(?string $actor, ?string $host, int $owner): bool { return false; }
function ap_media_by_local_ids(array $ids): array { return []; }
function ap_media_as2_attachments(array $rows): array { return []; }
function ap_normalize_visibility(string $visibility): string { return $visibility !== '' ? $visibility : 'public'; }
function ap_plain_text_to_html(string $text): string { return '<p>' . htmlspecialchars($text, ENT_QUOTES) . '</p>'; }
function ap_note_interaction_policy_for_actor(string $key): array { return []; }
function ap_create_finalize(array $create): array { return $create; }
function ap_outbox_store(array $note): void
{
    ap_db()->prepare('INSERT INTO outbox_notes (id, in_reply_to, raw_create_json, published, visibility) VALUES (?,?,?,?,?)')
        ->execute([
            $note['id'] ?? '',
            $note['in_reply_to'] ?? null,
            $note['raw_create_json'] ?? '',
            $note['published'] ?? ap_db_now(),
            $note['visibility'] ?? 'public',
        ]);
}
function ap_masto_status_register(string $noteId, string $createId, string $published, string $text, ?int $replyLocalId, string $spoiler, bool $sensitive, string $visibility): int
{
    return 42;
}
function ap_metrics_record(mixed ...$args): void {}
function ap_timeline_fanout_local_status(int $owner, array $entry, array $views): void {}
function ap_masto_mention_from_actor(string $actorId): array
{
    $actorId = rtrim($actorId, '/');
    if (preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $actorId, $m)) {
        return ['id' => '1', 'username' => $m[1], 'url' => $actorId, 'acct' => $m[1]];
    }
    $user = basename($actorId);
    $host = (string) parse_url($actorId, PHP_URL_HOST);
    return ['id' => '9', 'username' => $user, 'url' => $actorId, 'acct' => $user . '@' . $host];
}
function ap_deliver_public_activity(array $activity, array $extra = [], bool $pending = false): array
{
    ap_db()->prepare('INSERT INTO ap_fanout_delivery_queue (activity_key) VALUES (?)')->execute(['public']);
    return ['delivered' => 1, 'queued' => 1, 'bridgy' => true];
}
function ap_deliver_followers_activity(array $activity, array $extra = []): array
{
    ap_db()->prepare('INSERT INTO ap_fanout_delivery_queue (activity_key) VALUES (?)')->execute(['followers']);
    return ['delivered' => 1, 'queued' => 1, 'bridgy' => false];
}
function ap_deliver_fanout_background(array $activity, array $inboxes, string $keyId, string $priv): bool
{
    ap_db()->prepare('INSERT INTO ap_fanout_delivery_queue (activity_key) VALUES (?)')->execute(['fanout']);
    return true;
}
function ap_bsky_crosspost_status(mixed ...$args): array
{
    $GLOBALS['bsky_mirrors'][] = $args;
    return ['ok' => true, 'skipped' => true, 'error' => 'fixture'];
}
function ap_bsky_local_note_id_for_at_uri(string $uri, int $owner = 0, bool $allowFetch = true): ?string
{
    return null;
}

$GLOBALS['users'] = $users;
$GLOBALS['logs'] = [];
$GLOBALS['bsky_mirrors'] = [];

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
reference_load($api . '/ap-db.php', [
    'ap_mention_store',
    'ap_notification_projection_invalidate_owner',
]);
reference_load($api . '/ap-inbox.php', [
    'ap_reply_parent_is_bluesky_side',
    'ap_publish_local_actor_from_note',
    'ap_publish_local_reply_recipient_actors',
    'ap_publish_notify_local_reply_participants',
    'ap_bsky_thread_local_notify_backfill',
    'ap_publish_status_text',
]);

$checks = 0;
function notify_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

$result = ap_publish_status_text(
    '@ada @kryptospider.bsky.social @alice@example.social hello',
    $parent,
    null,
    [],
    '',
    false,
    null,
    null,
    'public'
);
notify_check(!empty($result['ok']), 'bluesky-thread reply publishes');
$noteId = (string) ($result['note_id'] ?? '');
notify_check(str_starts_with($noteId, 'https://mkultra.monster/users/shana_flux/notes/'), 'reply is a local note');

$owners = ap_db()->query('SELECT owner_user_id, actor_id, activity_type, in_reply_to FROM mentions ORDER BY owner_user_id')->fetchAll();
notify_check(array_column($owners, 'owner_user_id') === [1, 4], 'parent author and the local mention each get one mentions row');
notify_check($owners[0]['actor_id'] === 'https://mkultra.monster/users/shana_flux', 'notification actor is the replier');
notify_check($owners[0]['activity_type'] === 'Create' && $owners[0]['in_reply_to'] === $parent, 'notification is a reply to the bluesky-origin parent');
notify_check((int) ap_db()->query('SELECT COUNT(*) FROM ap_publish_delivery_queue')->fetchColumn() === 0, 'publish delivery queue stays empty');
notify_check((int) ap_db()->query('SELECT COUNT(*) FROM ap_fanout_delivery_queue')->fetchColumn() === 0, 'fan-out delivery queue stays empty');
notify_check((int) ap_db()->query('SELECT COUNT(*) FROM ap_notification_projection')->fetchColumn() === 0, 'notification projection is cleared for the Rust list');

$stored = ap_db()->prepare('SELECT raw_create_json FROM outbox_notes WHERE id = ?');
$stored->execute([$noteId]);
$create = json_decode((string) $stored->fetchColumn(), true);
$tags = is_array($create['object']['tag'] ?? null) ? $create['object']['tag'] : [];
$hrefs = [];
foreach ($tags as $tag) {
    if (is_array($tag) && strcasecmp((string) ($tag['type'] ?? ''), 'Mention') === 0) {
        $hrefs[] = (string) ($tag['href'] ?? '');
    }
}
sort($hrefs);
notify_check($hrefs === ['https://mkultra.monster/users/ada'], 'only the local mention stays on the note');
notify_check(!str_contains(json_encode($create['to'] ?? []) . json_encode($create['cc'] ?? []), 'example.social'), 'remote mention is not addressed');
notify_check(!str_contains(json_encode($create), 'bsky.app'), 'bluesky handle is not an ActivityPub address');
notify_check($GLOBALS['bsky_mirrors'] !== [], 'bluesky mirror still runs without a delivery queue');

$gap = 'https://mkultra.monster/users/shana_flux/notes/oldgap';
ap_db()->prepare('INSERT INTO outbox_notes (id, in_reply_to, raw_create_json, published, visibility) VALUES (?,?,?,?,?)')->execute([
    $gap,
    $parent,
    json_encode([
        'id' => $gap . '/create',
        'type' => 'Create',
        'actor' => 'https://mkultra.monster/users/shana_flux',
        'object' => [
            'id' => $gap,
            'type' => 'Note',
            'content' => '<p><span class="h-card"><a href="https://mkultra.monster/users/ada" class="u-url mention">@ada</a></span> later</p>',
            'inReplyTo' => $parent,
        ],
    ]),
    '2026-10-20T00:00:00Z',
    'public',
]);
$dry = ap_bsky_thread_local_notify_backfill(false, '2026-10-18T00:00:00Z', 50);
$dryIds = array_column($dry['missing'], 'note_id');
notify_check(in_array($gap, $dryIds, true), 'dry run lists the reply that has no local notification');
notify_check(!in_array($noteId, $dryIds, true), 'dry run skips the reply that already notified its local recipients');
notify_check($dry['applied'] === 0, 'dry run inserts nothing');
notify_check((int) ap_db()->query('SELECT COUNT(*) FROM mentions WHERE object_id = ' . ap_db()->quote($gap))->fetchColumn() === 0, 'dry run leaves the mentions table unchanged');
$applied = ap_bsky_thread_local_notify_backfill(true, '2026-10-18T00:00:00Z', 50);
notify_check($applied['applied'] === 1, 'apply repairs the missing reply');
$repaired = ap_db()->query('SELECT owner_user_id FROM mentions WHERE object_id = ' . ap_db()->quote($gap) . ' ORDER BY owner_user_id')->fetchAll(PDO::FETCH_COLUMN);
notify_check(array_map('intval', $repaired) === [1, 4], 'apply writes the parent and the local mention');
notify_check((int) ap_db()->query('SELECT COUNT(*) FROM ap_publish_delivery_queue')->fetchColumn() === 0, 'repair does not queue delivery');
notify_check((int) ap_db()->query('SELECT COUNT(*) FROM ap_fanout_delivery_queue')->fetchColumn() === 0, 'repair does not queue fan-out');

echo "bsky-thread-local-notify-smoke: {$checks} checks passed\n";
