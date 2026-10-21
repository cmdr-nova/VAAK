<?php
/**
 * Settings muted words native review (S7a, sandbox only).
 *
 * Runs the genuine muted_word_add and muted_word_remove handlers from
 * ap-admin.php (with the genuine ap_muted_word_add, ap_muted_word_remove,
 * ap_muted_words_cache_clear and admin_tl_cache_clear) against a seeded
 * PostgreSQL fixture, Redis and a scratch timeline cache directory, reseeds,
 * runs the same request through the native branch against a running worker,
 * and compares the notice, error, view, every muted word row, every Redis key
 * and every cache file. Fallback cases must reach PHP with nothing written.
 * Never point it at production: it drops and recreates its tables and flushes
 * its Redis database.
 *
 * Usage: php settings-muted-words-actions-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/settings/action SECRET
 *   env MUTED_WORDS_REDIS (default redis://127.0.0.1:6390/7), MUTED_WORDS_TL_DIR
 *   (run the worker with VAAK_SETTINGS_MUTED_WORDS_ACTIONS_NATIVE=1, the same Redis
 *   as VAAK_REDIS_URL and VAAK_ADMIN_TL_CACHE_DIR=MUTED_WORDS_TL_DIR)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
[$self, $dsn, $idType, $url, $secret] = $argv + [null, null, 'INTEGER', null, null];
if (!$dsn || !in_array($idType, ['INTEGER', 'BIGINT'], true) || !$url || strlen((string) $secret) < 32) {
    fwrite(STDERR, "usage: dsn INTEGER|BIGINT url secret\n"); exit(2);
}
date_default_timezone_set('UTC');
if (!str_contains($dsn, 'fixture')) { fwrite(STDERR, "refusing a database without 'fixture' in its name\n"); exit(2); }

$api = dirname(__DIR__);
$source = (string) file_get_contents($api . '/ap-admin.php');
$dbSource = (string) file_get_contents($api . '/ap-db.php');
function region(string $source, string $start, string $end): string {
    $a = strpos($source, $start); if ($a === false) throw new RuntimeException('missing start ' . substr($start, 0, 60));
    $b = strpos($source, $end, $a); if ($b === false) throw new RuntimeException('missing end ' . substr($end, 0, 60));
    return substr($source, $a, $b - $a);
}
function declaration(string $source, string $name): string {
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) throw new RuntimeException('missing ' . $name);
    $depth = 0; $opened = false; $out = '';
    foreach (array_slice(token_get_all('<?php ' . substr($source, $start)), 1) as $t) {
        $out .= is_array($t) ? $t[1] : $t;
        if (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) $depth++;
        if ($t === '{') { $depth++; $opened = true; }
        if ($t === '}') $depth--;
        if ($opened && $depth === 0) break;
    }
    return $out;
}
// Both handlers as one if/elseif chain. In native-only runs reaching the PHP
// helper records a refusal instead of writing.
$chain = region($source, "    } elseif (\$action === 'muted_word_add') {", "    } elseif (\$action === 'follow_request_decide') {");
foreach (['ap_muted_word_add', 'ap_muted_word_remove'] as $helper) {
    $needle = "?? (function_exists('$helper')";
    if (substr_count($chain, $needle) !== 1) throw new RuntimeException("native branch for $helper changed");
    $chain = str_replace($needle, "?? ((!\$nativeOnly || !(\$fellBack = true)) && function_exists('$helper')", $chain);
}
$chain = 'if (false) {' . $chain . '}';

putenv('VAAK_ADMIN_READ_NATIVE=1'); putenv('VAAK_ADMIN_READ_SECRET=' . $secret); putenv('VAAK_SETTINGS_ACTION_URL=' . $url);
require $api . '/ap-admin-native.php';
$redisDsn = (string) (getenv('MUTED_WORDS_REDIS') ?: 'redis://127.0.0.1:6390/7');
$tlDir = (string) (getenv('MUTED_WORDS_TL_DIR') ?: sys_get_temp_dir() . '/vaak-muted-words-tl');
if (!is_dir($tlDir)) mkdir($tlDir, 0700, true);
putenv('VAAK_REDIS_DSN=' . $redisDsn); putenv('VAAK_REDIS_ENABLED=1');
require $api . '/ap-redis.php';
foreach (['admin_tl_redis_key', 'admin_tl_owner_index_key', 'admin_tl_cache_clear_owner', 'admin_tl_cache_clear'] as $fn) eval(declaration($source, $fn));
eval(str_replace("'/var/lib/mkultra/ap/admin-tl-cache'", var_export($tlDir, true), declaration($source, 'admin_tl_cache_dir')));
foreach (['ap_timeline_cache_invalidate_owner', 'ap_timeline_ranked_redis_key', 'ap_db_now', 'ap_db_last_insert_id',
    'ap_muted_words_list', 'ap_muted_words_phrases_cached', 'ap_muted_words_cache_clear', 'ap_muted_word_add', 'ap_muted_word_remove'] as $fn) eval(declaration($dbSource, $fn));
function ap_db_driver(?PDO $db = null): string { return 'pgsql'; }
$redis = ap_redis_client('cache');
if (!$redis) { fwrite(STDERR, "Redis at $redisDsn is required\n"); exit(2); }

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
$GLOBALS['current_user'] = null;
function ap_auth_current_user(): ?array { return $GLOBALS['current_user']; }
function admin_owner_user_id(): int { return (int) ($GLOBALS['current_user']['id'] ?? 0); }

$big = $idType === 'BIGINT' ? 9000000000 : 0;
function schema(array $extra = []): void {
    global $db, $idType, $big;
    $db->exec('DROP TABLE IF EXISTS ap_users, ap_muted_words');
    $db->exec("CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, is_admin INTEGER, disabled_at TEXT)");
    $serial = $idType === 'BIGINT' ? 'BIGSERIAL' : 'SERIAL';
    $db->exec("CREATE TABLE ap_muted_words (id $serial PRIMARY KEY, owner_user_id $idType NOT NULL, phrase TEXT NOT NULL, created_at TEXT NOT NULL, note TEXT, UNIQUE(owner_user_id, phrase))");
    if ($big) $db->exec("ALTER SEQUENCE ap_muted_words_id_seq RESTART WITH $big");
    $st = $db->prepare('INSERT INTO ap_users VALUES (?,?,?,?,?)');
    foreach ([[1, 'operator', 'cmdr_nova', 1, null], [2, 'val', 'val', 0, null], [3, 'gone', 'gone', 0, '2026-02-01T00:00:00+00:00'], [4, 'other', 'other', 0, null]] as [$id, $u, $k, $a, $d]) $st->execute([$id + $big, $u, $k, $a, $d]);
    $st = $db->prepare('INSERT INTO ap_muted_words (owner_user_id, phrase, created_at, note) VALUES (?,?,?,?)');
    foreach (array_merge([[2, 'Spoiler Alert', '2026-10-01T00:00:00+00:00', 'old'], [2, 'été', '2026-10-02T00:00:00+00:00', null],
        [2, 'crypto', '2026-10-03T00:00:00+00:00', null], [4, 'politics', '2026-10-04T00:00:00+00:00', 'theirs']], $extra) as [$o, $p, $c, $n]) $st->execute([$o + $big, $p, $c, $n]);
    seed_caches();
}
function seed_caches(): void {
    global $redis, $tlDir, $big;
    $redis->flushDB();
    foreach ([2, 4] as $o) {
        $owner = $o + $big;
        $redis->set('vaak:moderation:muted-words:' . $owner, json_encode(['old']));
        $redis->set('vaak:trends:policy-epoch:v1:' . $owner, '4');
        $redis->set(admin_tl_owner_index_key($owner), json_encode(["home:$owner:a", "feed:$owner"]));
        foreach (["home:$owner:a", "feed:$owner"] as $logical) $redis->set(admin_tl_redis_key($logical), 'ranked');
        $redis->set("vaak:fragment:tl:v1:$owner:x", 'f'); $redis->set("vaak:fragment:tl:v1:{$owner}9:y", 'neighbour');
    }
    $redis->set('vaak:timeline:ranked:v1:legacy', 'v1');
    $redis->set('vaak:trends:policy-epoch:v1:server', '7');
    $redis->set('vaak:keep:me', 'unrelated');
    foreach (glob($tlDir . '/*') ?: [] as $f) unlink($f);
    foreach (['tl_home2a.json', 'tl_feed2.json', 'tl_tl.json', 'tl_other.json', 'keep.json', 'tl_x.txt'] as $f) file_put_contents($tlDir . '/' . $f, '{}');
}
function snapshot(): array {
    global $db, $redis, $tlDir;
    $rows = $db->query('SELECT id, owner_user_id, phrase, created_at, note FROM ap_muted_words ORDER BY id')->fetchAll();
    foreach ($rows as &$row) if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', (string) $row['created_at']) && abs(strtotime($row['created_at']) - time()) < 120) $row['created_at'] = 'NOW';
    unset($row);
    $keys = $redis->keys('*'); sort($keys); $out = [];
    foreach ($keys as $k) $out[$k] = [$redis->get($k), $redis->ttl($k) > 0];
    $files = array_map('basename', glob($tlDir . '/*') ?: []); sort($files);
    return ['rows' => $rows, 'redis' => $out, 'tl' => $files];
}
function run(string $action, array $post, bool $native, int $owner = 2): array {
    global $chain, $big, $db;
    $u = $db->prepare('SELECT id, actor_key FROM ap_users WHERE id = ?'); $u->execute([$owner + $big]); $user = $u->fetch() ?: null;
    $GLOBALS['current_user'] = $user ? ['id' => (int) $user['id'], 'actor_key' => $user['actor_key']] : null;
    $vaakOwnerId = (int) ($user['id'] ?? 0);
    $vaakUser = $GLOBALS['current_user'] ?? [];
    putenv('VAAK_SETTINGS_MUTED_WORDS_ACTIONS_NATIVE=' . ($native ? '1' : '0'));
    $_POST = $post; $notice = null; $error = null; $view = 'home'; $nativeOnly = $native; $fellBack = false;
    eval($chain);
    return ['notice' => $notice, 'error' => $error, 'view' => $view, 'fell_back' => $fellBack];
}

$checks = 0; $failures = [];
function check(bool $ok, string $label): void { global $checks, $failures; $checks++; if (!$ok) $failures[] = $label; }
function compare(string $label, string $action, array $post, int $owner = 2, array $extra = []): void {
    schema($extra); $php = run($action, $post, false, $owner); $phpState = snapshot();
    schema($extra); $native = run($action, $post, true, $owner); $nativeState = snapshot();
    check(!$native['fell_back'], "$label native ran");
    unset($php['fell_back'], $native['fell_back']);
    check($php === $native, "$label page result");
    check($phpState === $nativeState, "$label state");
    if (getenv('MUTED_WORDS_DUMP') === '1' && ($php !== $native || $phpState !== $nativeState)) {
        fwrite(STDERR, "== $label\n" . json_encode(['php' => [$php, $phpState], 'native' => [$native, $nativeState]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
function fallback(string $label, string $action, array $post, int $owner = 2, array $extra = []): void {
    schema($extra); $before = snapshot();
    $r = run($action, $post, true, $owner);
    check($r['fell_back'], "$label reached PHP");
    check(snapshot() === $before, "$label wrote nothing");
}

$id = static fn(int $n): string => (string) ($n + $GLOBALS['big']);
$long = str_repeat('é', 200); $note300 = str_repeat('ü', 300);
foreach ([
    'add new' => ['muted_word_add', ['phrase' => "  Hot   take\t\nhere ", 'note' => '  why <b>&  ']],
    'add without note' => ['muted_word_add', ['phrase' => 'doom', 'note' => '']],
    'add whitespace note' => ['muted_word_add', ['phrase' => 'doom', 'note' => " \t "]],
    'add same phrase another case' => ['muted_word_add', ['phrase' => 'SPOILER   alert', 'note' => 'ignored']],
    'add accented upper' => ['muted_word_add', ['phrase' => 'ÉTÉ', 'note' => '']],
    'add emoji' => ['muted_word_add', ['phrase' => '🙂🙂', 'note' => '']],
    'add other owner phrase' => ['muted_word_add', ['phrase' => 'Politics', 'note' => '']],
    'add one char' => ['muted_word_add', ['phrase' => ' x ', 'note' => '']],
    'add one multibyte char' => ['muted_word_add', ['phrase' => 'é', 'note' => '']],
    'add empty' => ['muted_word_add', ['phrase' => " \n ", 'note' => '']],
    'add missing phrase' => ['muted_word_add', ['note' => '']],
    'add 200 chars' => ['muted_word_add', ['phrase' => $long, 'note' => $note300]],
    'add 201 chars' => ['muted_word_add', ['phrase' => $long . 'x', 'note' => '']],
    'add long note' => ['muted_word_add', ['phrase' => 'fine', 'note' => $note300 . 'x']],
    'add short beats long note' => ['muted_word_add', ['phrase' => 'x', 'note' => $note300 . 'x']],
    'remove own' => ['muted_word_remove', ['id' => $id(3)]],
    'remove other owner row' => ['muted_word_remove', ['id' => $id(4)]],
    'remove missing' => ['muted_word_remove', ['id' => $id(99)]],
    'remove zero' => ['muted_word_remove', ['id' => '0']],
    'remove junk' => ['muted_word_remove', ['id' => 'abc']],
    'remove negative' => ['muted_word_remove', ['id' => '-5']],
    'remove no id' => ['muted_word_remove', []],
] as $label => [$action, $post]) compare($label, $action, $post);
compare('add by another account', 'muted_word_add', ['phrase' => 'politics', 'note' => ''], 4);
// A cached list PHP would encode differently leaves the whole cache clear to PHP.
compare('line separator in an existing phrase', 'muted_word_add', ['phrase' => 'fresh', 'note' => ''], 2, [[2, "a\u{2028}b", '2026-10-05T00:00:00+00:00', null]]);

fallback('dotted capital I', 'muted_word_add', ['phrase' => 'İstanbul', 'note' => '']);
fallback('sigma in an existing phrase', 'muted_word_add', ['phrase' => 'anything', 'note' => ''], 2, [[2, 'ΟΔΟΣ', '2026-10-05T00:00:00+00:00', null]]);
fallback('NUL in phrase', 'muted_word_add', ['phrase' => "a\0b", 'note' => '']);
fallback('NUL in note', 'muted_word_add', ['phrase' => 'ab', 'note' => "a\0"]);
fallback('Mongolian vowel separator', 'muted_word_add', ['phrase' => "a\u{180E}b", 'note' => '']);
fallback('disabled account acting', 'muted_word_add', ['phrase' => 'abc', 'note' => ''], 3);
if ($idType === 'INTEGER') fallback('id past int4', 'muted_word_remove', ['id' => '3000000000']);
else compare('id past int4', 'muted_word_remove', ['id' => '3000000000']);

// Cache work left undone comes back to PHP: with the cache directory gone the
// worker commits, names the work, and the handler and bridge redo it.
schema(); rename($tlDir, $tlDir . '.moved');
$r = run('muted_word_add', ['phrase' => 'cache later', 'note' => ''], true);
// admin_tl_cache_dir recreates the directory, empty.
foreach (glob($tlDir . '/*') ?: [] as $f) unlink($f);
if (is_dir($tlDir)) rmdir($tlDir);
rename($tlDir . '.moved', $tlDir);
check(!$r['fell_back'] && $r['notice'] !== null && $r['error'] === null, 'missing cache dir still saves natively');
check($db->query("SELECT count(*) FROM ap_muted_words WHERE phrase = 'cache later'")->fetchColumn() == 1, 'missing cache dir saved one row');
check(str_contains((string) $redis->get('vaak:moderation:muted-words:' . $id(2)), 'cache later') && $redis->get('vaak:trends:policy-epoch:v1:' . $id(2)) === '5'
    && $redis->get('vaak:timeline:ranked:v1:legacy') === false && $redis->get("vaak:fragment:tl:v1:{$id(2)}:x") === false, 'PHP redid the cache clear');

// The bridge validator.
$owner = 2 + $big;
$GLOBALS['current_user'] = ['id' => $owner, 'actor_key' => 'val'];
$good = ['protocol_version' => 1, 'ok' => true, 'outcome' => 'added', 'phrase' => 'cats', 'id' => 5, 'php_cache' => null,
    'request_binding' => ['owner_id' => $owner, 'actor_key' => 'val', 'action' => 'muted_word_add']];
check(ap_settings_action_native_reply($good, 'muted_word_add', $owner, 'val') === ['ok' => true, 'id' => 5, 'phrase' => 'cats'], 'bridge accepts an add');
foreach ([
    'remove outcome on an add' => static function (array $t): array { $t['outcome'] = 'removed'; return $t; },
    'other action binding' => static function (array $t): array { $t['request_binding']['action'] = 'muted_word_remove'; return $t; },
    'other owner binding' => static function (array $t): array { $t['request_binding']['owner_id']++; return $t; },
    'an extra key' => static function (array $t): array { $t['count'] = 1; return $t; },
    'a global cache on an add' => static function (array $t): array { $t['php_cache'] = 'global'; return $t; },
    'an unknown cache' => static function (array $t): array { $t['php_cache'] = 'all'; return $t; },
    'a string id' => static function (array $t): array { $t['id'] = '5'; return $t; },
    'an empty phrase' => static function (array $t): array { $t['phrase'] = ''; return $t; },
    'an error with a phrase' => static function (array $t): array { $t['outcome'] = 'phrase_short'; return $t; },
    'an unknown outcome' => static function (array $t): array { $t['outcome'] = 'saved'; return $t; },
] as $name => $mutate) check(ap_settings_action_native_reply($mutate($good), 'muted_word_add', $owner, 'val') === null, "bridge rejects $name");
check(ap_settings_action_native('muted_word_remove', $owner, 'val', ['id' => '2']) === null, 'bridge refuses a string id');
check(ap_settings_action_native('muted_word_add', $owner, 'val', ['note' => '', 'phrase' => 'x']) === null, 'bridge refuses reordered fields');
check(ap_settings_action_native('muted_word_add', $owner, 'someone else', ['phrase' => 'x', 'note' => '']) === null, 'bridge refuses another actor key');
putenv('VAAK_SETTINGS_MUTED_WORDS_ACTIONS_NATIVE=0');
check(ap_settings_action_native('muted_word_add', $owner, 'val', ['phrase' => 'x', 'note' => '']) === null, 'switch off keeps PHP');

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
