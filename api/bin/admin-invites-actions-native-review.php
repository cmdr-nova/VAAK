<?php
/**
 * Admin invites and registration switch native review (A10e, sandbox only).
 *
 * Runs the genuine invite_create, registration_open and registration_close
 * handlers from ap-admin.php (with the genuine ap_auth_invite_create,
 * ap_registration_set_accepting and its helpers) against a seeded PostgreSQL
 * fixture, reseeds, runs the same request through the native branch against a
 * running worker, and compares the notice, error, view, every invite row (the
 * random code checked for shape) and the registration control row. Fallback
 * cases must reach PHP with nothing written. Never point it at production: it
 * drops and recreates its tables.
 *
 * Usage: php admin-invites-actions-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/action SECRET
 *   (run the worker with VAAK_ADMIN_INVITES_ACTIONS_NATIVE=1)
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
$chain = region($source, "    } elseif ((\$action === 'registration_open' || \$action === 'registration_close') && \$vaakIsAdmin",
    "    } elseif (\$action === 'pds_migration_invite_request') {");
// Each refusal marker only catches its own actions, since the invite branch comes after the registration handlers.
foreach (["    } elseif (\$action === 'registration_open' || \$action === 'registration_close') {" => "\$action !== 'invite_create'", "    } elseif (\$action === 'invite_create') {" => "true"] as $phpFirst => $mine) {
    $chain = str_replace($phpFirst, "    } elseif (\$nativeOnly && $mine) { \$fellBack = true;\n" . $phpFirst, $chain);
}
$chain = 'if (false) {' . $chain . '}';

putenv('VAAK_ADMIN_READ_NATIVE=1'); putenv('VAAK_ADMIN_READ_SECRET=' . $secret); putenv('VAAK_ADMIN_ACTION_URL=' . $url);
require $api . '/ap-admin-native.php';
function ap_db_now(): string { return gmdate('c'); }
$authSource = (string) file_get_contents($api . '/ap-auth.php');
eval(declaration($authSource, 'ap_auth_invite_create'));
$regSource = (string) file_get_contents($api . '/ap-registration-approvals.php');
foreach (['ap_registration_approvals_enabled', 'ap_registration_set_accepting', 'ap_registration_current_admin', 'ap_registration_approvals_schema', 'ap_registration_approvals_lock'] as $fn) eval(declaration($regSource, $fn));
function ap_db_driver(?PDO $db = null): string { return 'pgsql'; }
function ap_auth_csrf_ok(string $token): bool { return $token === 'session'; }
function ap_db_last_insert_id(string $table, string $column = 'id', ?PDO $db = null): int { return 0; }

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
$GLOBALS['current_user'] = null;
function ap_auth_current_user(): ?array { return $GLOBALS['current_user']; }
function ap_auth_is_admin(?array $user = null): bool { $user ??= ap_auth_current_user(); return is_array($user) && !empty($user['is_admin']); }

$big = $idType === 'BIGINT' ? 9000000000 : 0;
function schema(bool $control = true, bool $supporterColumn = true): void {
    global $db, $idType, $big;
    $db->exec('DROP TABLE IF EXISTS ap_users, ap_invite_codes, ap_registration_control, ap_registration_applications');
    $serial = $idType === 'BIGINT' ? 'BIGSERIAL' : 'SERIAL';
    $db->exec("CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, is_admin INTEGER, disabled_at TEXT, updated_at TEXT);
    CREATE TABLE ap_invite_codes(id $serial PRIMARY KEY, code TEXT NOT NULL UNIQUE, created_by $idType, created_at TEXT NOT NULL, used_by $idType, used_at TEXT, expires_at TEXT, note TEXT);
    CREATE TABLE ap_registration_applications(id BIGSERIAL PRIMARY KEY, username TEXT NOT NULL, email TEXT, password_hash TEXT, status TEXT NOT NULL DEFAULT 'pending', created_at TEXT NOT NULL, decided_at TEXT, decided_by BIGINT, user_id BIGINT" . ($supporterColumn ? ', supporter_hash TEXT' : '') . ")");
    if ($control) $db->exec("CREATE TABLE ap_registration_control(id INTEGER PRIMARY KEY CHECK (id=1), accepting INTEGER NOT NULL CHECK (accepting IN (0,1)), updated_at TEXT NOT NULL, updated_by BIGINT);
        INSERT INTO ap_registration_control VALUES (1, 1, '2026-10-12T00:00:00Z', NULL)");
    $old = '2026-10-19T12:00:00+00:00';
    $st = $db->prepare('INSERT INTO ap_users VALUES (?,?,?,?,?,?)');
    foreach ([[1, 'operator', 'cmdr_nova', 1, null], [2, 'ordinary', 'ord', 0, null], [3, 'retired', 'ret', 1, '2026-01-01T00:00:00+00:00'], [4, 'odd admin', 'odd', 2, null]] as [$id, $u, $k, $a, $d]) $st->execute([$id + $big, $u, $k, $a, $d, $old]);
    $start = 1 + $big;
    $db->exec("ALTER SEQUENCE ap_invite_codes_id_seq RESTART WITH $start; INSERT INTO ap_invite_codes (code, created_by, created_at, note) VALUES ('AAAAAAAA-BBBB', $start, '$old', 'old')");
}
function snapshot(): array {
    global $db;
    $invites = $db->query('SELECT id, code, created_by, created_at, used_by, used_at, expires_at, note FROM ap_invite_codes ORDER BY id')->fetchAll();
    foreach ($invites as &$row) {
        if ($row['code'] !== 'AAAAAAAA-BBBB') $row['code'] = preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}$/D', $row['code']) ? 'CODE' : 'BAD:' . $row['code'];
        if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', (string) $row['created_at']) && abs(strtotime((string) $row['created_at']) - time()) < 120) $row['created_at'] = 'NOW';
    }
    $control = [];
    try {
        $control = $db->query('SELECT accepting, updated_at, updated_by FROM ap_registration_control')->fetchAll();
        foreach ($control as &$row) if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', (string) $row['updated_at']) && abs(strtotime((string) $row['updated_at']) - time()) < 120) $row['updated_at'] = 'NOW';
    } catch (PDOException $e) { $control = 'missing'; }
    $columns = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name='ap_registration_applications' ORDER BY column_name")->fetchAll(PDO::FETCH_COLUMN);
    return ['invites' => $invites, 'control' => $control, 'application_columns' => $columns];
}
function run(string $action, array $post, bool $native, int $owner = 1): array {
    global $chain, $big, $db;
    $u = $db->prepare('SELECT id, actor_key, is_admin FROM ap_users WHERE id = ?'); $u->execute([$owner + $big]); $user = $u->fetch() ?: null;
    $GLOBALS['current_user'] = $user ? ['id' => (int) $user['id'], 'actor_key' => $user['actor_key'], 'is_admin' => (int) $user['is_admin']] : null;
    $vaakOwnerId = (int) ($user['id'] ?? 0); $vaakActorKey = (string) ($user['actor_key'] ?? '');
    $vaakIsAdmin = true; // PHP's admin-only gate already passed for every request here.
    $vaakUser = ['id' => $vaakOwnerId]; $csrfToken = 'session';
    putenv('VAAK_ADMIN_INVITES_ACTIONS_NATIVE=' . ($native ? '1' : '0'));
    $_POST = $post; $notice = null; $error = null; $view = 'home'; $nativeOnly = $native; $fellBack = false;
    eval($chain);
    if (is_string($notice)) $notice = preg_replace('/^Invite created: [0-9A-F]{8}-[0-9A-F]{4}$/D', 'Invite created: CODE', $notice);
    return ['notice' => $notice, 'error' => $error, 'view' => $view, 'fell_back' => $fellBack];
}

$checks = 0; $failures = [];
function check(bool $ok, string $label): void { global $checks, $failures; $checks++; if (!$ok) $failures[] = $label; }
function compare(string $label, string $action, array $post, int $owner = 1, bool $control = true): void {
    schema($control); $php = run($action, $post, false, $owner); $phpState = snapshot();
    schema($control); $native = run($action, $post, true, $owner); $nativeState = snapshot();
    check(!$native['fell_back'], "$label native ran");
    unset($php['fell_back'], $native['fell_back']);
    check($php === $native, "$label page result");
    check($phpState === $nativeState, "$label state");
    if (getenv('INVITES_ACTIONS_DUMP') === '1' && ($php !== $native || $phpState !== $nativeState)) {
        fwrite(STDERR, "== $label\n" . json_encode(['php' => [$php, $phpState], 'native' => [$native, $nativeState]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
function fallback(string $label, string $action, array $post, int $owner = 1, bool $control = true, bool $supporterColumn = true): void {
    schema($control, $supporterColumn); $before = snapshot();
    $r = run($action, $post, true, $owner);
    check($r['fell_back'], "$label reached PHP");
    check(snapshot() === $before, "$label wrote nothing");
}

foreach ([
    'invite with note' => ['invite_create', ['note' => '  for Luna  ']],
    'invite without note' => ['invite_create', []],
    'invite blank note' => ['invite_create', ['note' => " \t "]],
    'invite long note' => ['invite_create', ['note' => str_repeat('é', 250)]],
    'invite html note' => ['invite_create', ['note' => '<b>x</b> & "y" 💬']],
    'close registration' => ['registration_close', []],
    'open registration' => ['registration_open', []],
] as $label => [$action, $post]) compare($label, $action, $post);
putenv('VAAK_REGISTRATION_APPROVALS=1'); compare('open with approvals on', 'registration_open', []); putenv('VAAK_REGISTRATION_APPROVALS');
schema(); $db->exec('DELETE FROM ap_registration_control');
$php = run('registration_close', [], false); $phpState = snapshot();
schema(); $db->exec('DELETE FROM ap_registration_control');
$native = run('registration_close', [], true); $nativeState = snapshot();
check(!$native['fell_back'] && array_diff_key($php, ['fell_back' => 0]) === array_diff_key($native, ['fell_back' => 0]) && $phpState === $nativeState, 'empty control table answers not ready on both');

fallback('array note', 'invite_create', ['note' => ['x']]);
fallback('NUL note', 'invite_create', ['note' => "a\0b"]);
fallback('disabled admin invite', 'invite_create', ['note' => 'x'], 3);
fallback('no control table', 'registration_close', [], 1, false);
fallback('applications table without supporter column', 'registration_close', [], 1, true, false);
fallback('admin flag other than 1', 'registration_close', [], 4);
fallback('disabled admin registration', 'registration_open', [], 3);

// The bridge validator.
$big1 = 1 + $big;
$GLOBALS['current_user'] = ['id' => $big1, 'actor_key' => 'cmdr_nova', 'is_admin' => 1];
$good = ['protocol_version' => 1, 'ok' => true, 'outcome' => 'invite_created', 'preview' => null, 'php_epoch_bump' => false, 'count' => null, 'code' => '0A1B2C3D-4E5F',
    'request_binding' => ['owner_id' => $big1, 'actor_key' => 'cmdr_nova', 'action' => 'invite_create']];
check(ap_admin_action_native_reply($good, 'invite_create', $big1, 'cmdr_nova')['code'] === '0A1B2C3D-4E5F', 'bridge accepts an invite code');
foreach ([
    'lowercase code' => static function (array $t): array { $t['code'] = '0a1b2c3d-4e5f'; return $t; },
    'markup in code' => static function (array $t): array { $t['code'] = '<b>AAAAA-BBBB'; return $t; },
    'code with newline' => static function (array $t): array { $t['code'] = "0A1B2C3D-4E5F\n"; return $t; },
    'missing code' => static function (array $t): array { $t['code'] = null; return $t; },
] as $name => $mutate) check(ap_admin_action_native_reply($mutate($good), 'invite_create', $big1, 'cmdr_nova') === null, "bridge rejects $name");
$reg = ['protocol_version' => 1, 'ok' => true, 'outcome' => 'registration_closed', 'preview' => null, 'php_epoch_bump' => false, 'count' => null, 'code' => '0A1B2C3D-4E5F',
    'request_binding' => ['owner_id' => $big1, 'actor_key' => 'cmdr_nova', 'action' => 'registration_close']];
check(ap_admin_action_native_reply($reg, 'registration_close', $big1, 'cmdr_nova') === null, 'bridge rejects a code on another action');
$reg['code'] = null; $reg['outcome'] = 'registration_opened';
check(ap_admin_action_native_reply($reg, 'registration_close', $big1, 'cmdr_nova') === null, 'bridge rejects the opposite switch');
check(ap_admin_action_native('registration_close', $big1, 'cmdr_nova', ['x' => 1]) === null, 'bridge refuses fields on the switch');
putenv('VAAK_ADMIN_INVITES_ACTIONS_NATIVE=0'); putenv('VAAK_ADMIN_USERS_ACTIONS_NATIVE=1');
check(ap_admin_action_native('invite_create', $big1, 'cmdr_nova', ['note' => 'x']) === null, 'another family switch keeps PHP');

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
