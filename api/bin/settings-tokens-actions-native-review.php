<?php
/**
 * Settings OAuth token revoke native review (S7c, sandbox only).
 *
 * Runs the genuine revoke_oauth_token handler from ap-admin.php (with the
 * genuine ap_oauth_token_revoke_by_id and ap_db_cmdr_nova_user_id) against a
 * seeded PostgreSQL fixture, reseeds, runs the same request through the native
 * branch against a running worker, and compares the notice, error, view and
 * every token row. Fallback cases must reach PHP with nothing written. Never
 * point it at production: it drops and recreates its tables.
 *
 * Usage: php settings-tokens-actions-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/settings/action SECRET
 *   (run the worker with VAAK_SETTINGS_TOKENS_ACTIONS_NATIVE=1)
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
$chain = region($source, "    } elseif (\$action === 'revoke_oauth_token') {", "    } elseif (\$action === 'purge_oauth_tokens') {");
$needle = '($tid > 0 && ap_oauth_token_revoke_by_id(';
if (substr_count($chain, $needle) !== 1) throw new RuntimeException('native branch changed');
$chain = 'if (false) {' . str_replace($needle, '((!$nativeOnly || !($fellBack = true)) && $tid > 0 && ap_oauth_token_revoke_by_id(', $chain) . '}';

putenv('VAAK_ADMIN_READ_NATIVE=1'); putenv('VAAK_ADMIN_READ_SECRET=' . $secret); putenv('VAAK_SETTINGS_ACTION_URL=' . $url);
require $api . '/ap-admin-native.php';
$dbSource = (string) file_get_contents($api . '/ap-db.php');
eval(declaration($dbSource, 'ap_db_now'));
eval(declaration($dbSource, 'ap_oauth_token_revoke_by_id'));
// Its static cache would outlive each reseed here.
$cmdr = declaration($dbSource, 'ap_db_cmdr_nova_user_id');
if (!str_contains($cmdr, 'static $cached = null;')) throw new RuntimeException('cmdr id cache changed');
eval(str_replace('static $cached = null;', '$cached = null;', $cmdr));

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
$GLOBALS['current_user'] = null;
function ap_auth_current_user(): ?array { return $GLOBALS['current_user']; }
function admin_owner_user_id(): int { return (int) ($GLOBALS['current_user']['id'] ?? 0); }

$big = $idType === 'BIGINT' ? 9000000000 : 0;
function schema(bool $twoCmdr = false, bool $noCmdr = false): void {
    global $db, $idType, $big;
    $db->exec('DROP TABLE IF EXISTS ap_users, oauth_tokens');
    $serial = $idType === 'BIGINT' ? 'BIGSERIAL' : 'SERIAL';
    $db->exec("CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, is_admin INTEGER, disabled_at TEXT);
    CREATE TABLE oauth_tokens(id $serial PRIMARY KEY, access_token_hash TEXT NOT NULL UNIQUE, client_id TEXT NOT NULL, scopes TEXT NOT NULL, created_at TEXT NOT NULL, revoked_at TEXT, user_id $idType)");
    if ($big) $db->exec("ALTER SEQUENCE oauth_tokens_id_seq RESTART WITH $big");
    $st = $db->prepare('INSERT INTO ap_users VALUES (?,?,?,?,?)');
    $users = [[1, 'operator', $noCmdr ? 'op' : 'cmdr_nova', 1, null], [2, 'val', 'val', 0, null], [3, 'gone', 'gone', 0, '2026-02-01T00:00:00+00:00']];
    if ($twoCmdr) $users[] = [4, 'dup', 'cmdr_nova', 0, null];
    foreach ($users as [$id, $u, $k, $a, $d]) $st->execute([$id + $big, $u, $k, $a, $d]);
    $old = '2026-10-19T12:00:00+00:00';
    $st = $db->prepare('INSERT INTO oauth_tokens (access_token_hash, client_id, scopes, created_at, revoked_at, user_id) VALUES (?,?,?,?,?,?)');
    // ids from 1 (+big): 1 val, 2 val revoked, 3 operator, 4 legacy NULL owner, 5 gone, 6 val
    foreach ([['a', 2, null], ['b', 2, '2026-10-02T00:00:00+00:00'], ['c', 1, null], ['d', null, null], ['e', 3, null], ['f', 2, null]] as [$h, $u, $r]) $st->execute([$h, 'app', 'read', $old, $r, $u === null ? null : $u + $big]);
}
function snapshot(): array {
    global $db;
    $rows = $db->query('SELECT id, user_id, revoked_at FROM oauth_tokens ORDER BY id')->fetchAll();
    foreach ($rows as &$row) if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', (string) $row['revoked_at']) && abs(strtotime($row['revoked_at']) - time()) < 120) $row['revoked_at'] = 'NOW';
    return $rows;
}
function run(array $post, bool $native, int $owner): array {
    global $chain, $big, $db;
    $u = $db->prepare('SELECT id, actor_key FROM ap_users WHERE id = ?'); $u->execute([$owner + $big]); $user = $u->fetch() ?: null;
    $GLOBALS['current_user'] = $user ? ['id' => (int) $user['id'], 'actor_key' => $user['actor_key']] : null;
    $vaakOwnerId = (int) ($user['id'] ?? 0);
    $vaakUser = $GLOBALS['current_user'] ?? [];
    putenv('VAAK_SETTINGS_TOKENS_ACTIONS_NATIVE=' . ($native ? '1' : '0'));
    $action = 'revoke_oauth_token';
    $_POST = $post; $notice = null; $error = null; $view = 'home'; $nativeOnly = $native; $fellBack = false;
    eval($chain);
    return ['notice' => $notice, 'error' => $error, 'view' => $view, 'fell_back' => $fellBack];
}

$checks = 0; $failures = [];
function check(bool $ok, string $label): void { global $checks, $failures; $checks++; if (!$ok) $failures[] = $label; }
function compare(string $label, array $post, int $owner, array $schema = []): void {
    schema(...$schema); $php = run($post, false, $owner); $phpState = snapshot();
    schema(...$schema); $native = run($post, true, $owner); $nativeState = snapshot();
    check(!$native['fell_back'], "$label native ran");
    unset($php['fell_back'], $native['fell_back']);
    check($php === $native, "$label page result");
    check($phpState === $nativeState, "$label state");
}
function fallback(string $label, array $post, int $owner, array $schema = []): void {
    schema(...$schema); $before = snapshot();
    $r = run($post, true, $owner);
    check($r['fell_back'], "$label reached PHP");
    check(snapshot() === $before, "$label wrote nothing");
}

$tok = static fn(int $n): string => (string) ($n + $GLOBALS['big']);
foreach ([
    'revoke own' => [['token_id' => $tok(1)], 2],
    'revoke own again revoked' => [['token_id' => $tok(2)], 2],
    "revoke another account's" => [['token_id' => $tok(3)], 2],
    'revoke legacy as ordinary account' => [['token_id' => $tok(4)], 2],
    'revoke legacy as cmdr_nova' => [['token_id' => $tok(4)], 1],
    'revoke own as cmdr_nova' => [['token_id' => $tok(3)], 1],
    "revoke val's as cmdr_nova" => [['token_id' => $tok(1)], 1],
    'revoke missing' => [['token_id' => $tok(99)], 2],
    'revoke zero' => [['token_id' => '0'], 2],
    'revoke junk' => [['token_id' => 'abc'], 2],
    'revoke negative' => [['token_id' => '-1'], 2],
    'revoke no id' => [[], 2],
] as $label => [$post, $owner]) compare($label, $post, $owner);
compare('no cmdr_nova row: id 1 owns legacy', ['token_id' => $tok(4)], 1, [false, true]);
fallback('two cmdr_nova rows', ['token_id' => $tok(4)], 1, [true, false]);
fallback('disabled account acting', ['token_id' => $tok(5)], 3);
if ($idType === 'INTEGER') fallback('token id past int4', ['token_id' => '3000000000'], 2);
else compare('token id past int4', ['token_id' => '3000000000'], 2);

// The bridge validator.
$owner = 2 + $big;
$GLOBALS['current_user'] = ['id' => $owner, 'actor_key' => 'val'];
$good = ['protocol_version' => 1, 'ok' => true, 'outcome' => 'revoked', 'phrase' => null, 'id' => 7, 'php_cache' => null,
    'request_binding' => ['owner_id' => $owner, 'actor_key' => 'val', 'action' => 'revoke_oauth_token']];
check(ap_settings_action_native_reply($good, 'revoke_oauth_token', $owner, 'val') === ['ok' => true], 'bridge accepts a revoke');
$no = $good; $no['outcome'] = 'not_revoked'; $no['id'] = null;
check(ap_settings_action_native_reply($no, 'revoke_oauth_token', $owner, 'val') === ['ok' => false], 'bridge accepts a refusal');
foreach ([
    'a revoke without id' => static function (array $t): array { $t['id'] = null; return $t; },
    'a refusal with id' => static function (array $t): array { $t['outcome'] = 'not_revoked'; return $t; },
    'cache work' => static function (array $t): array { $t['php_cache'] = 'global'; return $t; },
    'a muted word outcome' => static function (array $t): array { $t['outcome'] = 'removed'; return $t; },
    'other action binding' => static function (array $t): array { $t['request_binding']['action'] = 'muted_word_remove'; return $t; },
] as $name => $mutate) check(ap_settings_action_native_reply($mutate($good), 'revoke_oauth_token', $owner, 'val') === null, "bridge rejects $name");
check(ap_settings_action_native('revoke_oauth_token', $owner, 'val', ['token_id' => '2']) === null, 'bridge refuses a string id');
putenv('VAAK_SETTINGS_TOKENS_ACTIONS_NATIVE=0'); putenv('VAAK_SETTINGS_MUTED_WORDS_ACTIONS_NATIVE=1');
check(ap_settings_action_native('revoke_oauth_token', $owner, 'val', ['token_id' => 2]) === null, 'another family switch keeps PHP');

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
