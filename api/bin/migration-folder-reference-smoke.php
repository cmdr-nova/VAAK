<?php
declare(strict_types=1);
/** Actual folder functions against a disposable, in-memory SQLite database.
 * This verifies PHP durable effects; it is not PostgreSQL or controller parity.
 */
require_once __DIR__ . '/migration-reference-functions.php';
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "requires pdo_sqlite\n"); exit(2); }
// Minimal hosts may lack mbstring; preg_match_all counts Unicode codepoints.
if (!function_exists('mb_strlen')) { function mb_strlen(string $s): int { return preg_match_all('/./us', $s); } }
$fixtureDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$fixtureDb->exec('CREATE TABLE vaak_bookmark_folders(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,title TEXT,position INTEGER,created_at TEXT,updated_at TEXT)');
$fixtureDb->exec('CREATE TABLE bookmarks(owner INTEGER,status TEXT,object TEXT,PRIMARY KEY(owner,status))');
function ap_db(): PDO { return $GLOBALS['fixtureDb']; }
function ap_db_now(): string { return '2026-10-10T12:00:00Z'; }
function ap_db_last_insert_id(string $table): string { return ap_db()->lastInsertId(); }
function ap_masto_bookmark_add(string $status, ?string $object, int $owner): void { ap_db()->prepare('INSERT OR IGNORE INTO bookmarks(owner,status,object) VALUES(?,?,?)')->execute([$owner,$status,$object]); }
function verify(bool $condition, string $case): void { if (!$condition) throw new RuntimeException($case); $GLOBALS['checks']++; }
$GLOBALS['checks'] = 0;
reference_load(dirname(__DIR__) . '/ap-bookmark-folders.php', ['vaak_bookmark_folders_ensure_schema','vaak_bookmark_folders_list','vaak_bookmark_folder_create','vaak_bookmark_folder_delete','vaak_bookmark_folder_add_status','vaak_bookmark_folder_remove_status','vaak_bookmark_folders_on_unbookmark']);
verify(!vaak_bookmark_folder_create(0,'test')['ok'], 'missing owner rejected');
verify(!vaak_bookmark_folder_create(1,' ')['ok'], 'empty title rejected');
verify(!vaak_bookmark_folder_create(1,str_repeat('é',81))['ok'], 'Unicode title limit');
$a = vaak_bookmark_folder_create(1,'  Saved A  '); $b = vaak_bookmark_folder_create(2,'Saved B');
verify($a['ok'] && $a['folder']['title'] === 'Saved A' && $b['ok'], 'trim and persist');
verify(count(vaak_bookmark_folders_list(1)) === 1 && count(vaak_bookmark_folders_list(2)) === 1, 'owner list isolation');
verify(!vaak_bookmark_folder_add_status($a['id'],'7',2)['ok'], 'foreign folder add denied');
verify(vaak_bookmark_folder_add_status($a['id'],'7',1,'https://remote.test/7')['ok'], 'add persists');
verify(ap_db()->query('SELECT COUNT(*) FROM bookmarks WHERE owner=1')->fetchColumn() === 1, 'add ensures underlying bookmark');
verify(!empty(vaak_bookmark_folder_add_status($a['id'],'7',1)['already']), 'duplicate idempotent');
verify(vaak_bookmark_folders_list(1)[0]['item_count'] === 1, 'count memberships');
vaak_bookmark_folder_remove_status($a['id'],'7',2);
verify(vaak_bookmark_folders_list(1)[0]['item_count'] === 1, 'foreign remove no effect');
vaak_bookmark_folder_remove_status($a['id'],'7',1);
verify(vaak_bookmark_folders_list(1)[0]['item_count'] === 0 && ap_db()->query('SELECT COUNT(*) FROM bookmarks')->fetchColumn() === 1, 'remove retains bookmark');
$second = vaak_bookmark_folder_create(1,'second');
vaak_bookmark_folder_add_status($a['id'],'7',1); vaak_bookmark_folder_add_status($second['id'],'7',1); vaak_bookmark_folder_add_status($b['id'],'7',2);
vaak_bookmark_folders_on_unbookmark('7',1);
verify(ap_db()->query('SELECT COUNT(*) FROM vaak_bookmark_folder_items WHERE owner_user_id=1')->fetchColumn() === 0, 'unbookmark clears all owner folders');
verify(ap_db()->query('SELECT COUNT(*) FROM vaak_bookmark_folder_items WHERE owner_user_id=2')->fetchColumn() === 1, 'other owner memberships retained');
verify(!vaak_bookmark_folder_delete($a['id'],2)['ok'], 'foreign delete denied');
vaak_bookmark_folder_add_status($a['id'],'8',1);
verify(vaak_bookmark_folder_delete($a['id'],1)['ok'] && ap_db()->query('SELECT COUNT(*) FROM vaak_bookmark_folder_items WHERE folder_id=' . (int)$a['id'])->fetchColumn() === 0, 'delete removes own memberships');
verify(ap_db()->query('SELECT COUNT(*) FROM bookmarks WHERE owner=1')->fetchColumn() === 2, 'folder delete retains flat bookmarks');
for ($i=0;$i<39;$i++) verify(vaak_bookmark_folder_create(1,'folder'.$i)['ok'], 'folder creation below cap');
verify(!vaak_bookmark_folder_create(1,'overflow')['ok'], '40 folder cap');
echo 'migration-folder-reference: PASS (' . $GLOBALS['checks'] . " checks; actual PHP folder SQL effects in disposable SQLite)\n";
