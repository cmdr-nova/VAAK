<?php
declare(strict_types=1);
ini_set('display_errors', 'stderr');

/**
 * Replay ap_visibility_public_status and ap_visibility_status_audience_allowed
 * against an in-memory SQLite policy store. The visibility functions are the
 * real ones. The database handle is a fixture, not Postgres.
 */
$root = dirname(__DIR__, 2);
$path = $argv[1] ?? ($root . '/parity/fixtures/audience.json');
$spec = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

function ap_db(): PDO
{
    return $GLOBALS['parity_db'];
}

function ap_db_owner_actor_id_for_user_id(int $owner): string
{
    if ($owner < 1) {
        return '';
    }
    $statement = ap_db()->prepare('SELECT actor_id FROM ap_users WHERE id = ? AND disabled_at IS NULL');
    $statement->execute([$owner]);
    $actor = $statement->fetchColumn();
    return is_string($actor) ? $actor : '';
}

require_once $root . '/api/ap-visibility.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE ap_users(id INTEGER, actor_id TEXT, disabled_at TEXT);
    CREATE TABLE events(id INTEGER, object_id TEXT, visibility TEXT, actor_id TEXT, type TEXT, action_taken TEXT);
    CREATE TABLE outbox_notes(id TEXT, visibility TEXT, to_json TEXT, cc_json TEXT);
    CREATE TABLE mentions(owner_user_id INTEGER, object_id TEXT, deleted_at TEXT, activity_type TEXT);
    CREATE TABLE followers(owner_actor_id TEXT, actor_id TEXT);
    CREATE TABLE following(owner_actor_id TEXT, actor_id TEXT);');
$GLOBALS['parity_db'] = $db;

$inserts = [
    'users' => $db->prepare('INSERT INTO ap_users(id, actor_id, disabled_at) VALUES(?, ?, NULL)'),
    'outbox' => $db->prepare('INSERT INTO outbox_notes(id, visibility, to_json, cc_json) VALUES(?, ?, ?, ?)'),
    'mentions' => $db->prepare('INSERT INTO mentions(owner_user_id, object_id, deleted_at, activity_type) VALUES(?, ?, NULL, ?)'),
    'followers' => $db->prepare('INSERT INTO followers(owner_actor_id, actor_id) VALUES(?, ?)'),
    'following' => $db->prepare('INSERT INTO following(owner_actor_id, actor_id) VALUES(?, ?)'),
];

$cases = [];
foreach ($spec['cases'] as $case) {
    foreach (['ap_users', 'events', 'outbox_notes', 'mentions', 'followers', 'following'] as $table) {
        $db->exec('DELETE FROM ' . $table);
    }
    $dbRows = is_array($case['db'] ?? null) ? $case['db'] : [];
    foreach ($dbRows['users'] ?? [] as $row) {
        $inserts['users']->execute([(int) $row[0], (string) $row[1]]);
    }
    foreach ($dbRows['outbox'] ?? [] as $row) {
        $inserts['outbox']->execute([(string) $row[0], (string) $row[1], (string) $row[2], (string) $row[3]]);
    }
    foreach ($dbRows['mentions'] ?? [] as $row) {
        $inserts['mentions']->execute([(int) $row[0], (string) $row[1], (string) $row[2]]);
    }
    foreach ($dbRows['followers'] ?? [] as $row) {
        $inserts['followers']->execute([(string) $row[0], (string) $row[1]]);
    }
    foreach ($dbRows['following'] ?? [] as $row) {
        $inserts['following']->execute([(string) $row[0], (string) $row[1]]);
    }
    $status = $case['status'];
    $mode = (string) $case['mode'];
    if ($mode === 'public_status') {
        $output = ap_visibility_public_status($status);
    } elseif ($mode === 'audience_allowed') {
        $output = ap_visibility_status_audience_allowed($status, (int) $case['owner']);
    } else {
        fwrite(STDERR, 'audience: unknown mode ' . $mode . "\n");
        exit(1);
    }
    $input = $case;
    unset($input['db'], $input['note']);
    $cases[] = ['id' => (string) $case['id'], 'input' => $input, 'output' => $output];
}

echo json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
