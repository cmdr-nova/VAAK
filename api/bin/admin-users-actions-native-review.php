<?php
/**
 * Admin user ban and unban native review (A10d, sandbox only).
 *
 * Runs the genuine user_ban and user_unban handler from ap-admin.php (with the
 * genuine ap_auth_user_set_disabled) against a seeded PostgreSQL fixture,
 * reseeds, runs the same request through the native branch against a running
 * worker, and compares the notice, error, view, every account row and every
 * OAuth token row. Fallback cases must reach PHP with nothing written. Never
 * point it at production: it drops and recreates its tables.
 *
 * Usage: php admin-users-actions-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/action SECRET
 *   (run the worker with VAAK_ADMIN_USERS_ACTIONS_NATIVE=1)
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
// The native branch and the PHP handlers after it, as one if/elseif chain. In
// native-only runs a refusal is recorded instead of running the PHP handler.
$phpFirst = "    } elseif (\$action === 'user_ban' || \$action === 'user_unban') {";
$chain = region($source, "    } elseif ((\$action === 'user_ban' || \$action === 'user_unban') && \$vaakIsAdmin",
    "    } elseif (\$action === 'downrank_term_preview') {");
$chain = 'if (false) {' . str_replace($phpFirst, "    } elseif (\$nativeOnly) { \$fellBack = true;\n" . $phpFirst, $chain) . '}';

putenv('VAAK_ADMIN_READ_NATIVE=1'); putenv('VAAK_ADMIN_READ_SECRET=' . $secret); putenv('VAAK_ADMIN_ACTION_URL=' . $url);
require $api . '/ap-admin-native.php';
function ap_db_now(): string { return gmdate('c'); }
$authSource = (string) file_get_contents($api . '/ap-auth.php');
eval(declaration($authSource, 'ap_auth_user_set_disabled'));

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
$GLOBALS['current_user'] = null;
function ap_auth_current_user(): ?array { return $GLOBALS['current_user']; }
function ap_auth_is_admin(?array $user = null): bool { $user ??= ap_auth_current_user(); return is_array($user) && !empty($user['is_admin']); }

$big = $idType === 'BIGINT' ? 9000000000 : 0;
function schema(): void {
    global $db, $idType, $big;
    $db->exec('DROP TABLE IF EXISTS ap_users, oauth_tokens');
    $serial = $idType === 'BIGINT' ? 'BIGSERIAL' : 'SERIAL';
    $db->exec("CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, is_admin INTEGER, disabled_at TEXT, updated_at TEXT);
    CREATE TABLE oauth_tokens(id $serial PRIMARY KEY, access_token_hash TEXT NOT NULL UNIQUE, client_id TEXT NOT NULL, scopes TEXT NOT NULL, created_at TEXT NOT NULL, revoked_at TEXT, user_id $idType)");
    $old = '2026-10-19T12:00:00+00:00';
    $st = $db->prepare('INSERT INTO ap_users VALUES (?,?,?,?,?,?)');
    foreach ([[1, 'operator', 'cmdr_nova', 1, null], [2, 'ordinary', 'ord', 0, null], [3, 'retired', 'ret', 1, '2026-01-01T00:00:00+00:00'],
        [4, 'banned', 'banned', 0, '2026-10-01T00:00:00+00:00'], [5, 'other admin', 'second', 1, null], [6, 'op key', 'cmdr_nova_alt', null, null], [7, 'nonadmin key', 'cmdr_nova', 0, null]] as [$id, $u, $k, $a, $d]) {
        $st->execute([$id + $big, $u, $k, $a, $d, $old]);
    }
    $st = $db->prepare('INSERT INTO oauth_tokens (access_token_hash, client_id, scopes, created_at, revoked_at, user_id) VALUES (?,?,?,?,?,?)');
    foreach ([['a', 2, null], ['b', 2, '2026-10-02T00:00:00+00:00'], ['c', 6, null], ['d', 1, null], ['e', 4, null]] as [$h, $u, $r]) $st->execute([$h, 'app', 'read', $old, $r, $u + $big]);
}
function snapshot(): array {
    global $db;
    $now = static function (array $rows, array $cols): array {
        foreach ($rows as &$row) foreach ($cols as $c) {
            $v = (string) ($row[$c] ?? '');
            if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $v) && abs(strtotime($v) - time()) < 120) $row[$c] = 'NOW';
        }
        return $rows;
    };
    return [
        'users' => $now($db->query('SELECT id, username, actor_key, is_admin, disabled_at, updated_at FROM ap_users ORDER BY id')->fetchAll(), ['disabled_at', 'updated_at']),
        'tokens' => $now($db->query('SELECT id, user_id, revoked_at FROM oauth_tokens ORDER BY id')->fetchAll(), ['revoked_at']),
    ];
}
function run(string $action, array $post, bool $native, int $owner = 1): array {
    global $chain, $big, $db;
    $u = $db->prepare('SELECT id, actor_key, is_admin FROM ap_users WHERE id = ?'); $u->execute([$owner + $big]); $user = $u->fetch() ?: null;
    $GLOBALS['current_user'] = $user ? ['id' => (int) $user['id'], 'actor_key' => $user['actor_key'], 'is_admin' => (int) $user['is_admin']] : null;
    $vaakOwnerId = (int) ($user['id'] ?? 0); $vaakActorKey = (string) ($user['actor_key'] ?? '');
    $vaakIsAdmin = true; // PHP's admin-only gate already passed for every request here.
    $vaakUser = ['id' => $vaakOwnerId];
    putenv('VAAK_ADMIN_USERS_ACTIONS_NATIVE=' . ($native ? '1' : '0'));
    $_POST = $post; $notice = null; $error = null; $view = 'home'; $nativeOnly = $native; $fellBack = false;
    eval($chain);
    return ['notice' => $notice, 'error' => $error, 'view' => $view, 'fell_back' => $fellBack];
}

$checks = 0; $failures = [];
function check(bool $ok, string $label): void { global $checks, $failures; $checks++; if (!$ok) $failures[] = $label; }
function compare(string $label, string $action, array $post, int $owner = 1): void {
    schema(); $php = run($action, $post, false, $owner); $phpState = snapshot();
    schema(); $native = run($action, $post, true, $owner); $nativeState = snapshot();
    check(!$native['fell_back'], "$label native ran");
    unset($php['fell_back'], $native['fell_back']);
    check($php === $native, "$label page result");
    check($phpState === $nativeState, "$label state");
    if (getenv('USERS_ACTIONS_DUMP') === '1' && ($php !== $native || $phpState !== $nativeState)) {
        fwrite(STDERR, "== $label\n" . json_encode(['php' => [$php, $phpState], 'native' => [$native, $nativeState]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
function fallback(string $label, string $action, array $post, int $owner = 1): void {
    schema(); $before = snapshot();
    $r = run($action, $post, true, $owner);
    check($r['fell_back'], "$label reached PHP");
    check(snapshot() === $before, "$label wrote nothing");
}

$id = static fn(int $n): string => (string) ($n + $GLOBALS['big']);
foreach ([
    'ban' => ['user_ban', ['user_id' => $id(2)]],
    'ban null-admin account' => ['user_ban', ['user_id' => $id(6)]],
    'ban already banned' => ['user_ban', ['user_id' => $id(4)]],
    'unban' => ['user_unban', ['user_id' => $id(4)]],
    'unban active account' => ['user_unban', ['user_id' => $id(2)]],
    'ban operator' => ['user_ban', ['user_id' => $id(1)]],
    'ban other admin' => ['user_ban', ['user_id' => $id(5)]],
    'unban disabled admin' => ['user_unban', ['user_id' => $id(3)]],
    'ban cmdr_nova key without admin' => ['user_ban', ['user_id' => $id(7)]],
    'ban missing' => ['user_ban', ['user_id' => $id(99)]],
    'ban zero' => ['user_ban', ['user_id' => '0']],
    'ban junk' => ['user_ban', ['user_id' => 'abc']],
] as $label => [$action, $post]) compare($label, $action, $post);
compare('ban by the second admin', 'user_ban', ['user_id' => $id(2)], 5);

fallback('disabled admin acting', 'user_ban', ['user_id' => $id(2)], 3);
fallback('ordinary account acting', 'user_ban', ['user_id' => $id(4)], 2);
if ($idType === 'INTEGER') fallback('user id past int4', 'user_ban', ['user_id' => '3000000000']);
else compare('user id past int4', 'user_ban', ['user_id' => '3000000000']);
schema(); $db->exec('DROP TABLE oauth_tokens');
$r = run('user_ban', ['user_id' => $id(2)], true);
check($r['fell_back'] && $db->query('SELECT disabled_at FROM ap_users WHERE id = ' . $id(2))->fetchColumn() === null, 'missing token table rolls the ban back and reaches PHP');

// The bridge validator.
$big1 = 1 + $big;
$GLOBALS['current_user'] = ['id' => $big1, 'actor_key' => 'cmdr_nova', 'is_admin' => 1];
$good = ['protocol_version' => 1, 'ok' => true, 'outcome' => 'banned', 'preview' => null, 'php_epoch_bump' => false, 'count' => null,
    'request_binding' => ['owner_id' => $big1, 'actor_key' => 'cmdr_nova', 'action' => 'user_ban']];
check(ap_admin_action_native_reply($good, 'user_ban', $big1, 'cmdr_nova') !== null, 'bridge accepts a ban');
foreach ([
    'unban outcome on a ban' => static function (array $t): array { $t['outcome'] = 'unbanned'; return $t; },
    'other action binding' => static function (array $t): array { $t['request_binding']['action'] = 'user_unban'; return $t; },
    'a count' => static function (array $t): array { $t['count'] = 1; return $t; },
] as $name => $mutate) check(ap_admin_action_native_reply($mutate($good), 'user_ban', $big1, 'cmdr_nova') === null, "bridge rejects $name");
check(ap_admin_action_native('user_ban', $big1, 'cmdr_nova', ['user_id' => '2']) === null, 'bridge refuses a string id');
putenv('VAAK_ADMIN_USERS_ACTIONS_NATIVE=0'); putenv('VAAK_ADMIN_REPORTS_ACTIONS_NATIVE=1');
check(ap_admin_action_native('user_ban', $big1, 'cmdr_nova', ['user_id' => 2]) === null, 'another family switch keeps PHP');

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
