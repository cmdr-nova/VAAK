<?php
/**
 * Admin policies save native review (A10b, sandbox only).
 *
 * Runs the genuine policies_save_privacy, policies_save_conduct and
 * policies_save_rules handlers from ap-admin.php (with the genuine
 * ap_instance_doc_save and ap_instance_rules_save_from_text) against a seeded
 * PostgreSQL fixture, reseeds, runs the same request through the native branch
 * against a running worker, and compares the notice, error, view and every row
 * of ap_instance_docs and ap_instance_rules. Fallback cases must reach PHP with
 * nothing written. Never point it at production: it drops and recreates its
 * tables.
 *
 * Usage: php admin-policies-actions-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/action SECRET
 *   (run the worker with VAAK_ADMIN_POLICIES_ACTIONS_NATIVE=1)
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
// The native branch and the PHP handler after it, as one if/elseif chain. In
// native-only runs a refusal is recorded instead of running the PHP handler.
$phpFirst = "    } elseif (\$action === 'policies_save_privacy' || \$action === 'policies_save_conduct' || \$action === 'policies_save_rules') {";
$chain = region($source, "    } elseif (in_array(\$action, ['policies_save_privacy', 'policies_save_conduct', 'policies_save_rules'], true) && \$vaakIsAdmin",
    "    } elseif (\$action === 'upload_media') {");
// ap-instance-docs.php pulls in all of ap-db.php; its save functions are loaded on their own below.
$chain = str_replace("require_once __DIR__ . '/ap-instance-docs.php';", '', $chain);
$chain = 'if (false) {' . str_replace($phpFirst, "    } elseif (\$nativeOnly) { \$fellBack = true;\n" . $phpFirst, $chain) . '}';

putenv('VAAK_ADMIN_READ_NATIVE=1'); putenv('VAAK_ADMIN_READ_SECRET=' . $secret); putenv('VAAK_ADMIN_ACTION_URL=' . $url);
require $api . '/ap-admin-native.php';
function ap_db_driver(?PDO $db = null): string { return 'pgsql'; }
function ap_db_now(): string { return gmdate('c'); }
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
$docsSource = (string) file_get_contents($api . '/ap-instance-docs.php');
foreach (['ap_instance_docs_migrate', 'ap_instance_doc_save', 'ap_instance_rules_save_from_text'] as $fn) eval(declaration($docsSource, $fn));

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
$GLOBALS['current_user'] = null;
function ap_auth_current_user(): ?array { return $GLOBALS['current_user']; }
function ap_auth_is_admin(?array $user = null): bool { $user ??= ap_auth_current_user(); return is_array($user) && !empty($user['is_admin']); }

$big = $idType === 'BIGINT' ? 9000000000 : 0;
function schema(bool $docs = true): void {
    global $db, $idType, $big;
    $db->exec('DROP TABLE IF EXISTS ap_users, ap_instance_docs, ap_instance_rules');
    $serial = $idType === 'BIGINT' ? 'BIGSERIAL' : 'SERIAL';
    $db->exec("CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
    CREATE TABLE ap_instance_docs(doc_key TEXT PRIMARY KEY, title TEXT NOT NULL, body TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL);
    CREATE TABLE ap_instance_rules(id $serial PRIMARY KEY, sort_order INTEGER NOT NULL DEFAULT 0, text TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $st = $db->prepare('INSERT INTO ap_users VALUES (?,?,?,?,?)');
    foreach ([[1, 'operator', 'cmdr_nova', 1, null], [2, 'ordinary', 'ord', 0, null], [3, 'retired', 'ret', 1, '2026-01-01T00:00:00+00:00']] as [$id, $u, $k, $a, $d]) $st->execute([$id + $big, $u, $k, $a, $d]);
    $start = 1 + $big;
    $db->exec("ALTER SEQUENCE ap_instance_rules_id_seq RESTART WITH $start");
    if ($docs) {
        $db->exec("INSERT INTO ap_instance_docs VALUES ('privacy','Old privacy','old body','2026-10-19T12:00:00+00:00')");
    }
    $db->exec("INSERT INTO ap_instance_rules (sort_order, text, updated_at) VALUES (1,'Old one','2026-10-19T12:00:00+00:00'),(2,'Old two','2026-10-19T12:00:00+00:00')");
}
function snapshot(): array {
    global $db;
    $norm = static function (array $rows): array {
        foreach ($rows as &$row) {
            $v = (string) ($row['updated_at'] ?? '');
            if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $v) && abs(strtotime($v) - time()) < 120) $row['updated_at'] = 'NOW';
        }
        return $rows;
    };
    return [
        'docs' => $norm($db->query('SELECT doc_key, title, body, updated_at FROM ap_instance_docs ORDER BY doc_key')->fetchAll()),
        'rules' => $norm($db->query('SELECT id, sort_order, text, updated_at FROM ap_instance_rules ORDER BY id')->fetchAll()),
    ];
}
function run(string $action, array $post, bool $native, int $owner = 1): array {
    global $chain, $big, $db;
    $u = $db->prepare('SELECT id, actor_key, is_admin FROM ap_users WHERE id = ?'); $u->execute([$owner + $big]); $user = $u->fetch() ?: null;
    $GLOBALS['current_user'] = $user ? ['id' => (int) $user['id'], 'actor_key' => $user['actor_key'], 'is_admin' => (int) $user['is_admin']] : null;
    $vaakOwnerId = (int) ($user['id'] ?? 0); $vaakActorKey = (string) ($user['actor_key'] ?? '');
    $vaakIsAdmin = true; // PHP's admin-only gate already passed for every request here.
    putenv('VAAK_ADMIN_POLICIES_ACTIONS_NATIVE=' . ($native ? '1' : '0'));
    $_POST = $post; $notice = null; $error = null; $view = 'home'; $nativeOnly = $native; $fellBack = false;
    eval($chain);
    return ['notice' => $notice, 'error' => $error, 'view' => $view, 'fell_back' => $fellBack];
}

$checks = 0; $failures = [];
function check(bool $ok, string $label): void { global $checks, $failures; $checks++; if (!$ok) $failures[] = $label; }
function compare(string $label, string $action, array $post, bool $docs = true): void {
    schema($docs); $php = run($action, $post, false); $phpState = snapshot();
    schema($docs); $native = run($action, $post, true); $nativeState = snapshot();
    check(!$native['fell_back'], "$label native ran");
    unset($php['fell_back'], $native['fell_back']);
    check($php === $native, "$label page result");
    check($phpState === $nativeState, "$label state");
    if (getenv('POLICIES_ACTIONS_DUMP') === '1' && ($php !== $native || $phpState !== $nativeState)) {
        fwrite(STDERR, "== $label\n" . json_encode(['php' => [$php, $phpState], 'native' => [$native, $nativeState]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
function fallback(string $label, string $action, array $post, int $owner = 1): void {
    schema(); $before = snapshot();
    $r = run($action, $post, true, $owner);
    check($r['fell_back'], "$label reached PHP");
    check(snapshot() === $before, "$label wrote nothing");
}

$long = static fn(int $n, string $c = 'a'): string => str_repeat($c, $n);
foreach ([
    'privacy update' => ['policies_save_privacy', ['title' => '  New privacy  ', 'body' => "Line one\n\n<b>kept</b> & \"quoted\"\r\n  trailing  "]],
    'privacy default title' => ['policies_save_privacy', ['title' => " \t ", 'body' => '']],
    'conduct insert' => ['policies_save_conduct', ['title' => 'Conduct ü 💬', 'body' => 'Be kind.']],
    'conduct default title' => ['policies_save_conduct', ['title' => '', 'body' => 'x']],
    'title 120' => ['policies_save_privacy', ['title' => $long(120, 'é'), 'body' => 'x']],
    'title 121' => ['policies_save_privacy', ['title' => $long(121, 'é'), 'body' => 'x']],
    'body 100000' => ['policies_save_conduct', ['title' => 't', 'body' => $long(100000)]],
    'body 100001' => ['policies_save_conduct', ['title' => 't', 'body' => $long(100001)]],
    'rules replace' => ['policies_save_rules', ['rules_text' => "1. Be kind\r\n\r\n2) No spam  \n  3.no space\n12.  \t Twelve\n1.\n- dash kept"]],
    'rules unicode breaks' => ['policies_save_rules', ['rules_text' => "one\u{2028}two\u{2029}three\u{85}four\rfive\x0Bsix\x0Cseven"]],
    'rules 40' => ['policies_save_rules', ['rules_text' => implode("\n", array_map(static fn(int $i): string => "Rule $i", range(1, 40)))]],
    'rules 41' => ['policies_save_rules', ['rules_text' => implode("\n", array_map(static fn(int $i): string => "Rule $i", range(1, 41)))]],
    'rule 500' => ['policies_save_rules', ['rules_text' => "short\n" . $long(500, 'é')]],
    'rule 501' => ['policies_save_rules', ['rules_text' => "short\n" . $long(501, 'é')]],
    'rule long after 41' => ['policies_save_rules', ['rules_text' => implode("\n", array_fill(0, 41, 'r')) . "\n" . $long(600)]],
    'rules empty' => ['policies_save_rules', ['rules_text' => " \n\t\n "]],
    'rules markers only' => ['policies_save_rules', ['rules_text' => "1. \n2)  "]],
    'rules html kept' => ['policies_save_rules', ['rules_text' => '<script>x</script> & "q"']],
] as $label => [$action, $post]) compare($label, $action, $post);
compare('privacy insert into an empty table', 'policies_save_privacy', ['title' => 'Fresh', 'body' => 'fresh'], false);

fallback('array body', 'policies_save_privacy', ['title' => 't', 'body' => ['x']]);
fallback('NUL body', 'policies_save_conduct', ['title' => 't', 'body' => "a\0b"]);
fallback('NUL rules', 'policies_save_rules', ['rules_text' => "a\0b"]);
fallback('disabled admin', 'policies_save_rules', ['rules_text' => 'from retired'], 3);
schema(); $db->exec('DROP TABLE ap_instance_rules');
$r = run('policies_save_rules', ['rules_text' => 'no table'], true);
check($r['fell_back'], 'missing rules table reaches PHP');

// The bridge validator.
$big1 = 1 + $big;
$GLOBALS['current_user'] = ['id' => $big1, 'actor_key' => 'cmdr_nova', 'is_admin' => 1];
$good = ['protocol_version' => 1, 'ok' => true, 'outcome' => 'rules_saved', 'preview' => null, 'php_epoch_bump' => false, 'count' => 3, 'code' => null,
    'request_binding' => ['owner_id' => $big1, 'actor_key' => 'cmdr_nova', 'action' => 'policies_save_rules']];
check(ap_admin_action_native_reply($good, 'policies_save_rules', $big1, 'cmdr_nova') === ['outcome' => 'rules_saved', 'preview' => null, 'php_epoch_bump' => false, 'count' => 3, 'code' => null], 'bridge accepts saved rules');
foreach ([
    'missing count' => static function (array $t): array { $t['count'] = null; return $t; },
    'count past 40' => static function (array $t): array { $t['count'] = 41; return $t; },
    'count as string' => static function (array $t): array { $t['count'] = '3'; return $t; },
    'count on an error' => static function (array $t): array { $t['outcome'] = 'no_rules'; return $t; },
    'doc outcome' => static function (array $t): array { $t['outcome'] = 'doc_saved'; $t['count'] = null; return $t; },
    'preview' => static function (array $t): array { $t['preview'] = []; return $t; },
    'epoch bump' => static function (array $t): array { $t['php_epoch_bump'] = true; return $t; },
    'other action binding' => static function (array $t): array { $t['request_binding']['action'] = 'policies_save_privacy'; return $t; },
] as $name => $mutate) check(ap_admin_action_native_reply($mutate($good), 'policies_save_rules', $big1, 'cmdr_nova') === null, "bridge rejects $name");
$doc = ['protocol_version' => 1, 'ok' => true, 'outcome' => 'doc_saved', 'preview' => null, 'php_epoch_bump' => false, 'count' => null, 'code' => null,
    'request_binding' => ['owner_id' => $big1, 'actor_key' => 'cmdr_nova', 'action' => 'policies_save_conduct']];
check(ap_admin_action_native_reply($doc, 'policies_save_conduct', $big1, 'cmdr_nova') !== null, 'bridge accepts a saved document');
check(ap_admin_action_native('policies_save_rules', $big1, 'cmdr_nova', ['title' => 'x', 'body' => 'y']) === null, 'bridge refuses document fields for rules');
$GLOBALS['current_user']['is_admin'] = 0;
check(ap_admin_action_native('policies_save_rules', $big1, 'cmdr_nova', ['rules_text' => 'x']) === null, 'bridge refuses a non-admin session');
$GLOBALS['current_user'] = ['id' => $big1, 'actor_key' => 'cmdr_nova', 'is_admin' => 1];
putenv('VAAK_ADMIN_POLICIES_ACTIONS_NATIVE=0');
check(ap_admin_action_native('policies_save_rules', $big1, 'cmdr_nova', ['rules_text' => 'x']) === null, 'switch off keeps PHP');
// The downranking switch does not open this family.
putenv('VAAK_ADMIN_DOWNRANKING_ACTIONS_NATIVE=1');
check(ap_admin_action_native('policies_save_rules', $big1, 'cmdr_nova', ['rules_text' => 'x']) === null, 'another family switch keeps PHP');

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
