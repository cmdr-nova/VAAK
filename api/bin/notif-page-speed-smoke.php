<?php
declare(strict_types=1);
/**
 * Notification page reads stay fail-closed and match the per-status audience
 * checks. The page prefetch only batches those reads; it does not grant a
 * status the unbatched queries would deny. No Postgres, Redis, or worker.
 */
$api = dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}

$checks = 0;
function speed_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

final class CountingPdo extends PDO
{
    public int $prepares = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepares++;
        return parent::prepare($query, $options);
    }
}

$db = new CountingPdo('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec("CREATE TABLE events(id INTEGER PRIMARY KEY, object_id TEXT, type TEXT, action_taken TEXT, visibility TEXT, actor_id TEXT);
CREATE TABLE outbox_notes(id TEXT PRIMARY KEY, visibility TEXT, to_json TEXT, cc_json TEXT);
CREATE TABLE mentions(id INTEGER PRIMARY KEY, owner_user_id INTEGER, deleted_at TEXT, activity_type TEXT, object_id TEXT);
CREATE TABLE followers(owner_actor_id TEXT, actor_id TEXT);
CREATE TABLE following(owner_actor_id TEXT, actor_id TEXT);
INSERT INTO events(id, object_id, type, action_taken, visibility, actor_id) VALUES
 (1, 'https://remote.test/quoted', 'Create', 'log', 'private', 'https://remote.test/users/quoter'),
 (2, 'https://remote.test/quoted', 'Quote', 'log', 'public', 'https://remote.test/users/quoter'),
 (3, 'https://remote.test/slashed/', 'Create', 'log', 'unlisted', 'https://remote.test/users/author');
INSERT INTO outbox_notes(id, visibility, to_json, cc_json) VALUES
 ('https://mkultra.monster/users/alice/notes/direct', 'direct', '[\"https://mkultra.monster/users/viewer\"]', '[]'),
 ('https://mkultra.monster/users/alice/notes/both', 'public', '[]', '[]'),
 ('https://mkultra.monster/users/alice/notes/both/', 'direct', '[\"https://mkultra.monster/users/viewer\"]', '[]'),
 ('https://mkultra.monster/users/viewer/notes/self', 'private', '[]', '[]');
INSERT INTO mentions(id, owner_user_id, deleted_at, activity_type, object_id) VALUES
 (1, 7, NULL, 'Create', 'https://remote.test/mentioned'),
 (2, 7, '2026-01-01', 'Create', 'https://remote.test/deleted-mention'),
 (3, 8, NULL, 'Create', 'https://remote.test/other-owner');
INSERT INTO following(owner_actor_id, actor_id) VALUES
 ('https://mkultra.monster/users/viewer', 'https://remote.test/users/author');
INSERT INTO followers(owner_actor_id, actor_id) VALUES
 ('https://mkultra.monster/users/alice', 'https://mkultra.monster/users/viewer');");

function ap_db(): CountingPdo
{
    return $GLOBALS['db'];
}

function ap_db_owner_actor_id_for_user_id(int $ownerUserId): string
{
    return $ownerUserId > 0 ? 'https://mkultra.monster/users/viewer' : '';
}

$GLOBALS['db'] = $db;
reference_load($api . '/ap-visibility.php', [
    'ap_visibility_collect_audience_status',
    'ap_visibility_notification_page_prefetch',
    'ap_visibility_status_audience_allowed',
]);

/** @return array<string,array<string,mixed>> */
function speed_cases(): array
{
    $author = ['uri' => 'https://remote.test/users/author'];
    $local = ['uri' => 'https://mkultra.monster/users/alice'];
    return [
        'public' => ['uri' => 'https://remote.test/public', 'visibility' => 'public', 'account' => $author],
        'unlisted' => ['uri' => 'https://remote.test/unlisted', 'visibility' => 'unlisted', 'account' => $author],
        'private follower' => ['uri' => 'https://remote.test/private', 'visibility' => 'private', 'account' => $author],
        'direct recipient' => ['uri' => 'https://mkultra.monster/users/alice/notes/direct', 'visibility' => 'public', 'account' => $local],
        'direct mention' => ['uri' => 'https://remote.test/mentioned', 'visibility' => 'direct', 'account' => $author],
        'missing local' => ['uri' => 'https://mkultra.monster/users/bob/notes/9', 'visibility' => 'public', 'account' => ['uri' => 'https://mkultra.monster/users/bob']],
        'quote ignored' => ['uri' => 'https://remote.test/quoted', 'visibility' => 'public', 'account' => ['uri' => 'https://remote.test/users/quoter']],
        'revoked follower' => ['uri' => 'https://remote.test/revoked', 'visibility' => 'private', 'account' => ['uri' => 'https://remote.test/users/stranger']],
        'deleted mention' => ['uri' => 'https://remote.test/deleted-mention', 'visibility' => 'direct', 'account' => ['uri' => 'https://remote.test/users/stranger']],
        'other owner mention' => ['uri' => 'https://remote.test/other-owner', 'visibility' => 'direct', 'account' => ['uri' => 'https://remote.test/users/stranger']],
        'slash event' => ['uri' => 'https://remote.test/slashed', 'visibility' => 'private', 'account' => $author],
        'local follower' => ['uri' => 'https://remote.test/local-followers', 'visibility' => 'private', 'account' => $local],
        'self private' => ['uri' => 'https://mkultra.monster/users/viewer/notes/self', 'visibility' => 'public', 'account' => $local],
        'nested quote' => [
            'uri' => 'https://remote.test/outer',
            'visibility' => 'public',
            'account' => $author,
            'quote' => ['uri' => 'https://remote.test/revoked', 'visibility' => 'private', 'account' => ['uri' => 'https://remote.test/users/stranger']],
        ],
        'both outbox forms' => ['uri' => 'https://mkultra.monster/users/alice/notes/both', 'visibility' => 'public', 'account' => $local],
    ];
}

/** @param array<string,array<string,mixed>> $cases @return array<string,bool> */
function speed_decisions(array $cases, int $owner = 7): array
{
    $out = [];
    foreach ($cases as $name => $status) {
        $out[$name] = ap_visibility_status_audience_allowed($status, $owner);
    }
    return $out;
}

$cases = speed_cases();
unset($GLOBALS['ap_visibility_audience_memo']);
$db->prepares = 0;
$before = speed_decisions($cases);
$beforePrepares = $db->prepares;
$expected = [
    'public' => true,
    'unlisted' => true,
    'private follower' => true,
    'direct recipient' => true,
    'direct mention' => true,
    'missing local' => false,
    'quote ignored' => false,
    'revoked follower' => false,
    'deleted mention' => false,
    'other owner mention' => false,
    'slash event' => true,
    'local follower' => true,
    'self private' => true,
    'nested quote' => false,
    'both outbox forms' => true,
];
foreach ($expected as $name => $allowed) {
    speed_check($before[$name] === $allowed, $name . ' unbatched decision');
}
speed_check(ap_visibility_status_audience_allowed($cases['private follower'], 0) === false, 'ownerless private denied');
speed_check(ap_visibility_status_audience_allowed($cases['public'], 0) === true, 'ownerless public allowed');

$db->exec('DELETE FROM following');
unset($GLOBALS['ap_visibility_audience_memo']);
$revoked = speed_decisions(['private follower' => $cases['private follower']]);
speed_check($revoked['private follower'] === false, 'revoked follower denied by current SQL');
$db->exec("INSERT INTO following(owner_actor_id, actor_id) VALUES ('https://mkultra.monster/users/viewer', 'https://remote.test/users/author')");
unset($GLOBALS['ap_visibility_audience_memo']);
$restored = speed_decisions(['private follower' => $cases['private follower']]);
speed_check($restored['private follower'] === true, 'restored follower allowed by current SQL');

$notifications = [];
foreach ($cases as $name => $status) {
    $notifications[] = ['id' => $name, 'type' => 'mention', 'status' => $status];
}
unset($GLOBALS['ap_visibility_audience_memo']);
$db->prepares = 0;
ap_visibility_notification_page_prefetch($notifications, 7);
$prefetchOnly = $db->prepares;
$after = speed_decisions($cases);
speed_check($after === $before, 'prefetched page matches unbatched decisions');
speed_check($db->prepares < $beforePrepares, 'prefetched page issues fewer queries');
speed_check($prefetchOnly === 3, 'mixed page prefetches events, outbox, and mentions once');

$publicPage = [];
for ($i = 0; $i < 24; $i++) {
    $publicPage[] = [
        'type' => 'favourite',
        'status' => [
            'id' => (string) $i,
            'uri' => 'https://remote.test/batch/' . $i,
            'visibility' => 'public',
            'account' => ['uri' => 'https://remote.test/users/author'],
        ],
    ];
}
unset($GLOBALS['ap_visibility_audience_memo']);
$db->prepares = 0;
foreach ($publicPage as $row) {
    speed_check(ap_visibility_status_audience_allowed($row['status'], 7) === true, 'public batch card allowed');
}
$serialPublic = $db->prepares;
unset($GLOBALS['ap_visibility_audience_memo']);
$db->prepares = 0;
ap_visibility_notification_page_prefetch($publicPage, 7);
foreach ($publicPage as $row) {
    speed_check(ap_visibility_status_audience_allowed($row['status'], 7) === true, 'prefetched public card allowed');
}
$batchedPublic = $db->prepares;
speed_check($batchedPublic <= 3, 'twenty-four public cards resolve in one batched read');
speed_check($batchedPublic < $serialPublic, 'batched public cards skip the per-card prepares');
unset($GLOBALS['ap_visibility_audience_memo']);
speed_check(ap_visibility_status_audience_allowed($cases['revoked follower'], 7) === false, 'cleared memo does not reuse an earlier grant');

reference_load($api . '/ap-admin.php', ['admin_notif_embed_uses_axum']);
unset($GLOBALS['admin_notif_inline_cards']);
speed_check(admin_notif_embed_uses_axum() === true, 'embed worker remains available outside the mentions fill');
$GLOBALS['admin_notif_inline_cards'] = true;
speed_check(admin_notif_embed_uses_axum() === false, 'mentions fill paints cards in the request');
unset($GLOBALS['admin_notif_inline_cards']);
speed_check(admin_notif_embed_uses_axum() === true, 'clearing the fill flag restores the embed worker');

$admin = (string) file_get_contents($api . '/ap-admin.php');
$visibility = (string) file_get_contents($api . '/ap-visibility.php');
$schema = (string) file_get_contents($api . '/ap-db.php');
$migratePath = dirname($api) . '/deploy/remote/migrate.php';
$migrate = (string) file_get_contents($migratePath);
$indexSql = (string) file_get_contents($api . '/migrations/20261014_notification_read_indexes_postgres.sql');
speed_check(str_contains($admin, "\$GLOBALS['admin_notif_inline_cards'] = true;"), 'mentions fill sets inline cards');
speed_check(str_contains($admin, 'admin_notif_embed_uses_axum()'), 'embed path asks whether this fill is inline');
speed_check(str_contains($admin, 'ap_visibility_notification_page_prefetch('), 'mentions fill prefetches audiences');
speed_check(substr_count($visibility, 'ap_visibility_status_audience_allowed($child, $ownerUserId, $depth + 1)') === 1, 'nested audience recursion unchanged');
speed_check(!preg_match('/CREATE\s+INDEX\b[^;]*idx_events_object_key_id/', $schema), 'a page load does not build the events expression index');
speed_check(!preg_match('/CREATE\s+INDEX\b[^;]*idx_mentions_owner_live_id/', $schema), 'a page load does not build the mentions partial index');
speed_check(str_contains($migrate, 'vaak_migrate_notification_read_indexes($lock)'), 'migrate.php builds the indexes on its autocommit connection');
require_once $migratePath;
$specs = vaak_migrate_notification_index_specs();
speed_check(isset($specs['idx_events_object_key_id'], $specs['idx_mentions_owner_live_id']), 'deploy knows both notification read indexes');
$flatSql = preg_replace('/\s+/', ' ', $indexSql) ?: '';
foreach ($specs as $name => $spec) {
    speed_check(str_starts_with($spec['create'], 'CREATE INDEX CONCURRENTLY '), $name . ' is created concurrently');
    speed_check(str_starts_with($spec['drop'], 'DROP INDEX CONCURRENTLY '), $name . ' invalid rebuild does not take a blocking drop');
    speed_check(str_contains($flatSql, preg_replace('/\s+/', ' ', $spec['create']) ?: $spec['create']), $name . ' matches the concurrent SQL file');
    speed_check(vaak_migrate_notification_index_plan(true, true) === 'skip', $name . ' valid index is left untouched');
    speed_check(vaak_migrate_notification_index_statements($name, 'skip') === [], $name . ' second deploy issues no DDL');
    speed_check(vaak_migrate_notification_index_statements($name, 'create') === [$spec['create']], $name . ' first deploy only creates');
    speed_check(
        vaak_migrate_notification_index_statements($name, 'rebuild') === [$spec['drop'], $spec['create']],
        $name . ' invalid index is rebuilt concurrently'
    );
}
speed_check(vaak_migrate_notification_index_plan(false, null) === 'missing-table', 'missing events table does not pretend the index exists');
$files = array_map('basename', vaak_migrate_deploy_sql_files($api));
speed_check(!in_array('20261014_notification_read_indexes_postgres.sql', $files, true), 'concurrent index file stays out of the additive loop');
final class NotifIndexTxPdo extends PDO
{
    public function inTransaction(): bool
    {
        return true;
    }
}
$blocked = false;
try {
    vaak_migrate_notification_read_indexes(new NotifIndexTxPdo('sqlite::memory:'));
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'must not run inside a transaction');
}
speed_check($blocked, 'concurrent index build refuses an open transaction');
speed_check(!str_contains($indexSql, 'password') && !str_contains($indexSql, 'postgres://'), 'index file has no credentials');

echo "notif-page-speed-smoke ok checks={$checks} serial_public_prepares={$serialPublic} batched_public_prepares={$batchedPublic}\n";
