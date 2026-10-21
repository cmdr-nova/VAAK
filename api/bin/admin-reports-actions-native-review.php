<?php
/**
 * Admin moderation report actions native review (A10c, sandbox only).
 *
 * Runs the genuine report_note, report_dismiss, report_ignore and report_reopen
 * handlers from ap-admin.php (with the genuine ap_report_set_state and
 * ap_report_append_admin_note) against a seeded PostgreSQL fixture, reseeds,
 * runs the same request through the native branch against a running worker,
 * and compares the notice, error, view, the filter PHP carries on, and every
 * report row. Fallback cases must reach PHP with nothing written. Never point
 * it at production: it drops and recreates its tables.
 *
 * Usage: php admin-reports-actions-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/action SECRET
 *   (run the worker with VAAK_ADMIN_REPORTS_ACTIONS_NATIVE=1)
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
$phpFirst = "    } elseif (\$action === 'report_note') {";
$chain = region($source, "    } elseif (in_array(\$action, ['report_note', 'report_dismiss', 'report_ignore', 'report_reopen'], true) && \$vaakIsAdmin",
    "    } elseif (\$action === 'mute_remote' || \$action === 'unmute_remote') {");
$chain = 'if (false) {' . str_replace($phpFirst, "    } elseif (\$nativeOnly) { \$fellBack = true;\n" . $phpFirst, $chain) . '}';

putenv('VAAK_ADMIN_READ_NATIVE=1'); putenv('VAAK_ADMIN_READ_SECRET=' . $secret); putenv('VAAK_ADMIN_ACTION_URL=' . $url);
require $api . '/ap-admin-native.php';
function ap_db_now(): string { return gmdate('c'); }
$reportsSource = (string) file_get_contents($api . '/ap-reports.php');
foreach (['ap_report_set_state', 'ap_report_append_admin_note'] as $fn) eval(declaration($reportsSource, $fn));

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
$GLOBALS['current_user'] = null;
function ap_auth_current_user(): ?array { return $GLOBALS['current_user']; }
function ap_auth_is_admin(?array $user = null): bool { $user ??= ap_auth_current_user(); return is_array($user) && !empty($user['is_admin']); }

$big = $idType === 'BIGINT' ? 9000000000 : 0;
function schema(): void {
    global $db, $idType, $big;
    $db->exec('DROP TABLE IF EXISTS ap_users, ap_reports');
    $db->exec("CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
    CREATE TABLE ap_reports(id $idType PRIMARY KEY, activity_id TEXT NOT NULL UNIQUE, direction TEXT NOT NULL, reporter_actor_id TEXT NOT NULL, target_actor_id TEXT, status_uris_json TEXT, comment TEXT, about_us INTEGER NOT NULL DEFAULT 0, state TEXT NOT NULL DEFAULT 'open', admin_notes TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $st = $db->prepare('INSERT INTO ap_users VALUES (?,?,?,?,?)');
    foreach ([[1, ' operator ', 'cmdr_nova', 1, null], [2, 'ordinary', 'ord', 0, null], [3, 'retired', 'ret', 1, '2026-01-01T00:00:00+00:00'], [4, '', 'keyonly', 1, null], [5, null, 'nulluser', 1, null]] as [$id, $u, $k, $a, $d]) $st->execute([$id + $big, $u, $k, $a, $d]);
    $st = $db->prepare('INSERT INTO ap_reports (id, activity_id, direction, reporter_actor_id, state, admin_notes, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)');
    $old = '2026-10-19T12:00:00+00:00';
    foreach ([
        [1, 'open', ''],
        [2, 'dismissed', "  [2026-10-18 10:00:00 UTC · @operator@mkultra.monster]\nEarlier note  \n"],
        [3, 'ignored', str_repeat('x', 49990)],
        // 49990 bytes; a 60 byte note by @operator@mkultra.monster cuts the history at byte 107, inside the é.
        [4, 'open', str_repeat('x', 106) . 'é' . str_repeat('x', 49882)],
    ] as [$id, $state, $notes]) $st->execute([$id + $big, "https://r.example/a/$id", 'in', 'https://r.example/u', $state, $notes, $old, $old]);
}
function snapshot(): array {
    global $db;
    $rows = $db->query('SELECT id, state, admin_notes, updated_at FROM ap_reports ORDER BY id')->fetchAll();
    foreach ($rows as &$row) {
        $v = (string) $row['updated_at'];
        if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $v) && abs(strtotime($v) - time()) < 120) $row['updated_at'] = 'NOW';
        // The note stamp is the second it ran; compare everything around it.
        $row['admin_notes'] = preg_replace_callback('/\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d) UTC · /u', static fn(array $m): string => abs(strtotime($m[1] . ' UTC') - time()) < 120 ? '[NOW UTC · ' : $m[0], (string) $row['admin_notes']);
        $row['notes_bytes'] = strlen((string) $row['admin_notes']);
    }
    return $rows;
}
/** The handle ap-admin.php derives for this account. */
function handle_for(?array $user): string {
    if (!$user) return '';
    $name = trim((string) ($user['username'] ?? ''));
    if ($name === '') $name = trim((string) $user['actor_key']);
    return $name !== '' ? '@' . $name . '@mkultra.monster' : '';
}
function run(string $action, array $post, bool $native, int $owner = 1, ?string $handle = null): array {
    global $chain, $big, $db;
    $u = $db->prepare('SELECT id, username, actor_key, is_admin FROM ap_users WHERE id = ?'); $u->execute([$owner + $big]); $user = $u->fetch() ?: null;
    $GLOBALS['current_user'] = $user ? ['id' => (int) $user['id'], 'actor_key' => $user['actor_key'], 'is_admin' => (int) $user['is_admin']] : null;
    $vaakOwnerId = (int) ($user['id'] ?? 0); $vaakActorKey = (string) ($user['actor_key'] ?? '');
    $vaakHandle = $handle ?? handle_for($user);
    $vaakIsAdmin = true; // PHP's admin-only gate already passed for every request here.
    putenv('VAAK_ADMIN_REPORTS_ACTIONS_NATIVE=' . ($native ? '1' : '0'));
    $_POST = $post; $_GET = []; $notice = null; $error = null; $view = 'home'; $nativeOnly = $native; $fellBack = false;
    eval($chain);
    return ['notice' => $notice, 'error' => $error, 'view' => $view, 'filter' => $_GET['filter'] ?? null, 'fell_back' => $fellBack];
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
    if (getenv('REPORTS_ACTIONS_DUMP') === '1' && ($php !== $native || $phpState !== $nativeState)) {
        fwrite(STDERR, "== $label\n" . json_encode(['php' => [$php, $phpState], 'native' => [$native, $nativeState]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
function fallback(string $label, string $action, array $post, int $owner = 1, ?string $handle = null): void {
    schema(); $before = snapshot();
    $r = run($action, $post, true, $owner, $handle);
    check($r['fell_back'], "$label reached PHP");
    check(snapshot() === $before, "$label wrote nothing");
}

$id = static fn(int $n): string => (string) ($n + $GLOBALS['big']);
foreach ([
    'note on empty notes' => ['report_note', ['report_id' => $id(1), 'admin_note' => "  First note\nline two  ", 'filter' => 'open']],
    'note appended' => ['report_note', ['report_id' => $id(2), 'admin_note' => 'Second <b>note</b> ü 💬']],
    'note trimmed to 5000' => ['report_note', ['report_id' => $id(1), 'admin_note' => str_repeat('é', 5100)]],
    'note history capped' => ['report_note', ['report_id' => $id(3), 'admin_note' => 'pushes past fifty thousand bytes']],
    'note empty' => ['report_note', ['report_id' => $id(1), 'admin_note' => " \n\t "]],
    'note bad id' => ['report_note', ['report_id' => '0', 'admin_note' => 'x']],
    'note missing report' => ['report_note', ['report_id' => $id(99), 'admin_note' => 'x', 'filter' => 'Bad-Filter!']],
    'dismiss' => ['report_dismiss', ['report_id' => $id(1), 'filter' => 'all']],
    'dismiss with note' => ['report_dismiss', ['report_id' => $id(1), 'admin_note' => ' handled ']],
    'ignore with note' => ['report_ignore', ['report_id' => $id(2), 'admin_note' => 'spam wave']],
    'reopen' => ['report_reopen', ['report_id' => $id(2), 'filter' => '!!!']],
    'reopen same state' => ['report_reopen', ['report_id' => $id(1)]],
    'dismiss blank note' => ['report_dismiss', ['report_id' => $id(1), 'admin_note' => '   ']],
    'dismiss missing report' => ['report_dismiss', ['report_id' => $id(99), 'admin_note' => 'lost']],
    'dismiss zero id' => ['report_dismiss', ['report_id' => 'abc']],
] as $label => [$action, $post]) compare($label, $action, $post);
compare('note signed by actor key', 'report_note', ['report_id' => $id(1), 'admin_note' => 'from key'], 4);
compare('note signed by null username', 'report_note', ['report_id' => $id(1), 'admin_note' => 'from null'], 5);

fallback('array note', 'report_note', ['report_id' => $id(1), 'admin_note' => ['x']]);
fallback('NUL note', 'report_note', ['report_id' => $id(1), 'admin_note' => "a\0b"]);
fallback('history cut inside a character', 'report_note', ['report_id' => $id(4), 'admin_note' => str_repeat('y', 60)]);
fallback('handle from another account', 'report_note', ['report_id' => $id(1), 'admin_note' => 'x'], 1, '@someone@mkultra.monster');
fallback('disabled admin', 'report_dismiss', ['report_id' => $id(1)], 3);
if ($idType === 'INTEGER') fallback('report id past int4', 'report_dismiss', ['report_id' => '3000000000']);
else compare('report id past int4', 'report_dismiss', ['report_id' => '3000000000']);

// The bridge validator.
$big1 = 1 + $big;
$GLOBALS['current_user'] = ['id' => $big1, 'actor_key' => 'cmdr_nova', 'is_admin' => 1];
$good = ['protocol_version' => 1, 'ok' => true, 'outcome' => 'state_set', 'preview' => null, 'php_epoch_bump' => false, 'count' => null,
    'request_binding' => ['owner_id' => $big1, 'actor_key' => 'cmdr_nova', 'action' => 'report_ignore']];
check(ap_admin_action_native_reply($good, 'report_ignore', $big1, 'cmdr_nova') !== null, 'bridge accepts a state change');
foreach ([
    'note outcome on a state action' => static function (array $t): array { $t['outcome'] = 'note_saved'; return $t; },
    'a count' => static function (array $t): array { $t['count'] = 1; return $t; },
    'other action binding' => static function (array $t): array { $t['request_binding']['action'] = 'report_dismiss'; return $t; },
] as $name => $mutate) check(ap_admin_action_native_reply($mutate($good), 'report_ignore', $big1, 'cmdr_nova') === null, "bridge rejects $name");
check(ap_admin_action_native('report_note', $big1, 'cmdr_nova', ['report_id' => '1', 'admin_note' => 'x', 'admin_handle' => '@operator@mkultra.monster']) === null, 'bridge refuses a string id');
check(ap_admin_action_native('report_note', $big1, 'cmdr_nova', ['report_id' => 1, 'admin_note' => 'x']) === null, 'bridge refuses a missing handle');
putenv('VAAK_ADMIN_REPORTS_ACTIONS_NATIVE=0'); putenv('VAAK_ADMIN_POLICIES_ACTIONS_NATIVE=1');
check(ap_admin_action_native('report_note', $big1, 'cmdr_nova', ['report_id' => 1, 'admin_note' => 'x', 'admin_handle' => '@operator@mkultra.monster']) === null, 'another family switch keeps PHP');

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
