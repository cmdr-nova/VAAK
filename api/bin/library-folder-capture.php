<?php
declare(strict_types=1);
/** Folder-list references for Library step 4. No network and no Postgres.
 *  Real folder SQL and the Bookmarks chrome run against in-memory SQLite. */
if (!extension_loaded('mbstring') || !extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Folder capture requires mbstring and pdo_sqlite\n");
    exit(2);
}
require_once __DIR__ . '/migration-reference-functions.php';

$fixtureDb = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$fixtureDb->exec('CREATE TABLE ap_users(id INTEGER PRIMARY KEY, actor_id TEXT)');
$fixtureDb->exec("INSERT INTO ap_users VALUES(1,'https://local.test/users/reader'),(2,'https://local.test/users/other')");
$fixtureDb->exec('CREATE TABLE masto_bookmarks(id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER, owner_actor_id TEXT, status_id TEXT, object_id TEXT, created_at TEXT, UNIQUE(owner_user_id, status_id))');
$fixtureDb->exec('CREATE TABLE events(id INTEGER PRIMARY KEY, object_id TEXT)');
$fixtureDb->exec('CREATE TABLE masto_statuses(local_id INTEGER PRIMARY KEY, note_id TEXT)');
$fixtureDb->exec('CREATE TABLE vaak_bookmark_folders(id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER, title TEXT, position INTEGER, created_at TEXT, updated_at TEXT)');
$fixtureDb->exec('CREATE TABLE vaak_bookmark_folder_items(folder_id INTEGER NOT NULL, owner_user_id INTEGER NOT NULL, status_id TEXT NOT NULL, added_at TEXT NOT NULL, PRIMARY KEY(folder_id, status_id))');

function ap_db(): PDO { return $GLOBALS['fixtureDb']; }
function ap_db_now(): string { return '2026-10-14T00:00:00Z'; }
function ap_db_last_insert_id(string $table, string $column = 'id', ?PDO $db = null): int
{
    return (int) ap_db()->lastInsertId();
}
function ap_masto_bookmark_add(string $statusId, ?string $objectId, ?int $ownerUserId = null): void
{
    $owner = (int) $ownerUserId;
    if ($owner < 1 || $statusId === '') {
        return;
    }
    ap_db()->prepare('INSERT OR IGNORE INTO masto_bookmarks(owner_user_id, owner_actor_id, status_id, object_id, created_at) VALUES(?,?,?,?,?)')
        ->execute([$owner, 'https://local.test/users/reader', $statusId, (string) $objectId, ap_db_now()]);
}

$root = dirname(__DIR__);
reference_load($root . '/ap-db.php', ['ap_fix_utf8']);
reference_load($root . '/ap-admin.php', ['h', 'admin_bookmark_folder_soft_nav_html', 'admin_bookmark_folder_page_html']);
reference_load($root . '/ap-bookmark-folders.php', [
    'vaak_bookmark_folder_fedi_match_sql',
    'vaak_bookmark_folder_saved_keys',
    'vaak_bookmark_folders_ensure_schema',
    'vaak_bookmark_folders_list',
    'vaak_bookmark_folder_create',
    'vaak_bookmark_folder_delete',
    'vaak_bookmark_folder_add_status',
    'vaak_bookmark_folder_remove_status',
    'vaak_bookmark_folders_on_unbookmark',
    'vaak_bookmark_folders_for_status',
]);

function folder_rows(string $sql): array
{
    $out = [];
    foreach (ap_db()->query($sql)->fetchAll() ?: [] as $row) {
        $out[] = array_map(static fn($value) => $value === null ? '' : (string) $value, $row);
    }
    return $out;
}

function folder_snapshot(string $id, int $owner, int $selected, string $csrf, string $statusId): array
{
    $list = vaak_bookmark_folders_list($owner);
    $folders = [];
    foreach ($list as $row) {
        $folders[] = [
            'id' => (int) ($row['id'] ?? 0),
            'owner_id' => (int) ($row['owner_user_id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'position' => (int) ($row['position'] ?? 0),
            'item_count' => (int) ($row['item_count'] ?? 0),
        ];
    }
    return [
        'id' => $id,
        'owner_id' => $owner,
        'selected' => $selected,
        'csrf' => $csrf,
        'status_id' => $statusId,
        'folders' => $folders,
        'items' => folder_rows('SELECT folder_id, owner_user_id, status_id FROM vaak_bookmark_folder_items ORDER BY folder_id, status_id'),
        'bookmarks' => folder_rows('SELECT id, owner_user_id, status_id, object_id FROM masto_bookmarks ORDER BY id'),
        'events' => folder_rows('SELECT id, object_id FROM events ORDER BY id'),
        'locals' => folder_rows('SELECT local_id, note_id FROM masto_statuses ORDER BY local_id'),
        'for_status' => vaak_bookmark_folders_for_status($statusId, $owner),
        'page_html' => admin_bookmark_folder_page_html($list, $selected, $csrf),
        'soft_html' => admin_bookmark_folder_soft_nav_html($list, $selected),
    ];
}

function folder_count(array $snapshot, string $title): int
{
    foreach ($snapshot['folders'] as $folder) {
        if ($folder['title'] === $title) {
            return (int) $folder['item_count'];
        }
    }
    throw new RuntimeException('missing folder ' . $title);
}

$csrf = 'csrf&<>"\'';
$cases = [];
$cases[] = folder_snapshot('guest', 0, 0, $csrf, 'note-1');

$shelf = vaak_bookmark_folder_create(1, '  Shelf  ');
$reading = vaak_bookmark_folder_create(1, 'Reading');
$theirs = vaak_bookmark_folder_create(2, 'Theirs');
if (!$shelf['ok'] || !$reading['ok'] || !$theirs['ok'] || ($shelf['folder']['title'] ?? '') !== 'Shelf') {
    throw new RuntimeException('folder create did not trim and persist');
}
if (vaak_bookmark_folder_create(1, ' ')['ok'] || vaak_bookmark_folder_create(1, str_repeat('é', 81))['ok']) {
    throw new RuntimeException('invalid folder names were stored');
}
$empty = folder_snapshot('empty', 1, 0, $csrf, 'note-1');
if (folder_count($empty, 'Shelf') !== 0 || folder_count($empty, 'Reading') !== 0 || count($empty['folders']) !== 2) {
    throw new RuntimeException('empty folders were dropped or leaked across owners');
}
$cases[] = $empty;

$renamed = 'Tom & "Jerry\'s" <folder>';
ap_db()->prepare('UPDATE vaak_bookmark_folders SET title = ?, position = 5 WHERE id = ? AND owner_user_id = 1')
    ->execute([$renamed, (int) $shelf['id']]);
$renamedSnap = folder_snapshot('renamed_empty', 1, (int) $shelf['id'], $csrf, 'note-1');
if (($renamedSnap['folders'][0]['title'] ?? '') !== 'Reading' || ($renamedSnap['folders'][1]['title'] ?? '') !== $renamed) {
    throw new RuntimeException('renamed folder did not follow position, id order');
}
if (folder_count($renamedSnap, $renamed) !== 0 || !str_contains($renamedSnap['page_html'], '(0)')) {
    throw new RuntimeException('renamed empty folder lost its zero count');
}
$cases[] = $renamedSnap;

ap_db()->prepare('INSERT INTO masto_bookmarks(id, owner_user_id, owner_actor_id, status_id, object_id, created_at) VALUES(?,?,?,?,?,?)')
    ->execute([10, 1, 'https://local.test/users/reader', 'note-1', 'https://remote.test/posts/1', ap_db_now()]);
ap_db()->prepare('INSERT INTO masto_bookmarks(id, owner_user_id, owner_actor_id, status_id, object_id, created_at) VALUES(?,?,?,?,?,?)')
    ->execute([11, 1, 'https://local.test/users/reader', 'note-2', 'https://remote.test/objects/2', ap_db_now()]);
ap_db()->prepare('INSERT INTO masto_bookmarks(id, owner_user_id, owner_actor_id, status_id, object_id, created_at) VALUES(?,?,?,?,?,?)')
    ->execute([12, 1, 'https://local.test/users/reader', 'note-3', 'https://remote.test/users/a/statuses/3', ap_db_now()]);
ap_db()->prepare('INSERT INTO masto_bookmarks(id, owner_user_id, owner_actor_id, status_id, object_id, created_at) VALUES(?,?,?,?,?,?)')
    ->execute([20, 2, 'https://local.test/users/other', 'note-1', 'https://remote.test/posts/1', ap_db_now()]);
ap_db()->exec("INSERT INTO events(id, object_id) VALUES(5, 'https://remote.test/objects/2')");
ap_db()->exec("INSERT INTO masto_statuses(local_id, note_id) VALUES(9, 'https://remote.test/users/a/statuses/3/')");
$insert = ap_db()->prepare('INSERT INTO vaak_bookmark_folder_items(folder_id, owner_user_id, status_id, added_at) VALUES(?,?,?,?)');
$shelfId = (int) $shelf['id'];
foreach (['note-1', 'https://remote.test/posts/1/', 'loose-raw', '3000005', '9'] as $statusId) {
    $insert->execute([$shelfId, 1, $statusId, ap_db_now()]);
}
$insert->execute([$shelfId, 2, 'foreign-item', ap_db_now()]);
$filled = folder_snapshot('filled', 1, 0, $csrf, 'note-1');
$filledCount = folder_count($filled, $renamed);
if ($filledCount !== 4 || folder_count($filled, 'Reading') !== 0) {
    throw new RuntimeException('folder count did not collapse aliases to saved bookmarks, got ' . $filledCount);
}
if (vaak_bookmark_folders_for_status('note-1', 1) !== [$shelfId]) {
    throw new RuntimeException('status membership did not stay on the exact stored id');
}
$cases[] = $filled;

if (!vaak_bookmark_folder_remove_status($shelfId, 'loose-raw', 1)['ok']) {
    throw new RuntimeException('remove from the source folder failed');
}
if (!vaak_bookmark_folder_add_status((int) $reading['id'], 'loose-raw', 1, 'https://remote.test/loose')['ok']) {
    throw new RuntimeException('add to the destination folder failed');
}
if (vaak_bookmark_folder_add_status($shelfId, 'loose-raw', 2)['ok']) {
    throw new RuntimeException('foreign folder add was accepted');
}
$moved = folder_snapshot('moved', 1, (int) $reading['id'], $csrf, 'loose-raw');
if (folder_count($moved, $renamed) !== 3 || folder_count($moved, 'Reading') !== 1) {
    throw new RuntimeException('move did not leave the bookmark saved and change both counts');
}
if ($moved['for_status'] !== [(int) $reading['id']]) {
    throw new RuntimeException('moved status is not listed only in the destination folder');
}
if (ap_db()->query('SELECT COUNT(*) FROM masto_bookmarks WHERE status_id = \'note-1\'')->fetchColumn() != 2) {
    throw new RuntimeException('move removed an unrelated bookmark');
}
$cases[] = $moved;
$cases[] = folder_snapshot('other_owner', 2, (int) $theirs['id'], $csrf, 'note-1');

echo json_encode(['cases' => $cases], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
