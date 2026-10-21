<?php
/**
 * Admin downranking vocabulary actions native review (A10a, sandbox only).
 *
 * Runs the genuine downrank_term_add, downrank_term_delete, downrank_signal_clear
 * and downrank_term_preview handlers from ap-admin.php (with the genuine
 * admin_home_downrank_audit, ap_downrank_phrase_matches_text and
 * ap_trends_policy_epoch_bump) against a seeded PostgreSQL fixture and a Redis
 * database, reseeds, runs the same request through the native branch against a
 * running worker, and compares the notice, error, view, preview, every row of
 * the vocabulary, suppression and audit tables, and the server trends epoch.
 * Fallback cases must reach PHP with nothing written. Never point it at
 * production: it drops and recreates its tables and writes Redis.
 *
 * Usage: php admin-downranking-actions-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/action SECRET
 *   (env DOWNRANK_ACTIONS_REDIS, default redis://127.0.0.1:6390/5; run the
 *   worker with VAAK_ADMIN_DOWNRANKING_ACTIONS_NATIVE=1 and that Redis as
 *   VAAK_REDIS_URL)
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
    return (string) preg_replace('/\bstatic (\$\w+\s*=)/', '$1', $out);
}
foreach (['admin_home_downrank_audit', 'admin_home_phrase_matches_text'] as $fn) eval(declaration($source, $fn));
foreach (['ap_downrank_phrase_matches_text'] as $fn) eval(declaration($dbSource, $fn));

function region(string $source, string $start, string $end): string {
    $a = strpos($source, $start); if ($a === false) throw new RuntimeException('missing start ' . substr($start, 0, 60));
    $b = strpos($source, $end, $a); if ($b === false) throw new RuntimeException('missing end ' . substr($end, 0, 60));
    return substr($source, $a, $b - $a);
}
// The native branch and the PHP handlers after it, as one if/elseif chain. In
// native-only runs a refusal is recorded instead of running the PHP handler.
$phpFirst = "    } elseif (\$action === 'downrank_term_preview') {";
$chain = region($source, "    } elseif (in_array(\$action, ['downrank_term_add', 'downrank_term_delete', 'downrank_signal_clear', 'downrank_term_preview'], true) && \$vaakIsAdmin",
    "    } elseif (\$action === 'policies_save_privacy'");
$chain = 'if (false) {' . str_replace($phpFirst, "    } elseif (\$nativeOnly) { \$fellBack = true;\n" . $phpFirst, $chain) . '}';

$redisDsn = (string) (getenv('DOWNRANK_ACTIONS_REDIS') ?: 'redis://127.0.0.1:6390/5');
putenv('VAAK_REDIS_DSN=' . $redisDsn); putenv('VAAK_REDIS_ENABLED=1');
require $api . '/ap-redis.php';
putenv('VAAK_ADMIN_READ_NATIVE=1'); putenv('VAAK_ADMIN_READ_SECRET=' . $secret); putenv('VAAK_ADMIN_ACTION_URL=' . $url);
require $api . '/ap-admin-native.php';

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
$GLOBALS['current_user'] = null;
function ap_auth_current_user(): ?array { return $GLOBALS['current_user']; }
function ap_auth_is_admin(?array $user = null): bool { $user ??= ap_auth_current_user(); return is_array($user) && !empty($user['is_admin']); }
// One base time, so the PHP and native runs see identical seeded rows.
$T0 = time();
function at(int $offset): string { return gmdate('c', $GLOBALS['T0'] + $offset); }

$big = $idType === 'BIGINT' ? 9000000000 : 0;
function schema(): void {
    global $db, $idType, $big;
    $db->exec('DROP TABLE IF EXISTS ap_users, ap_home_downrank_terms, ap_home_downrank_audit, ap_home_suppression, events');
    $serial = $idType === 'BIGINT' ? 'BIGSERIAL' : 'SERIAL';
    $db->exec("CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
    CREATE TABLE ap_home_downrank_terms(id $serial PRIMARY KEY, phrase TEXT NOT NULL UNIQUE, category TEXT NOT NULL DEFAULT 'custom', enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
    CREATE TABLE ap_home_downrank_audit(id $serial PRIMARY KEY, admin_user_id $idType NOT NULL DEFAULT 0, admin_actor TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, actor_id TEXT NOT NULL DEFAULT '', term_id $idType NOT NULL DEFAULT 0, phrase TEXT NOT NULL DEFAULT '', details_json TEXT NOT NULL DEFAULT '{}', created_at TEXT NOT NULL);
    CREATE TABLE ap_home_suppression(owner_user_id $idType NOT NULL, actor_id TEXT NOT NULL, score INTEGER NOT NULL DEFAULT 0, categories_json TEXT NOT NULL DEFAULT '[]', suppressed_until TEXT NOT NULL, last_object_id TEXT NOT NULL DEFAULT '', seen_object_ids_json TEXT NOT NULL DEFAULT '[]', updated_at TEXT NOT NULL, PRIMARY KEY(owner_user_id, actor_id));
    CREATE TABLE events(id $serial PRIMARY KEY, created_at TEXT NOT NULL, type TEXT NOT NULL, actor_id TEXT, object_id TEXT, target_actor TEXT, host TEXT, bytes INTEGER DEFAULT 0, action_taken TEXT, summary TEXT, content TEXT, visibility TEXT)");
    $st = $db->prepare('INSERT INTO ap_users VALUES (?,?,?,?,?)');
    foreach ([[1, 'operator', 'cmdr_nova', 1, null], [2, 'ordinary', 'ord', 0, null], [3, 'retired', 'ret', 1, '2026-01-01T00:00:00+00:00'], [4, 'second', 'second_admin', 1, null]] as [$id, $u, $k, $a, $d]) {
        $st->execute([$id + $big, $u, $k, $a, $d]);
    }
    $start = 1 + $big;
    $db->exec("ALTER SEQUENCE ap_home_downrank_terms_id_seq RESTART WITH $start; ALTER SEQUENCE ap_home_downrank_audit_id_seq RESTART WITH $start");
    $st = $db->prepare('INSERT INTO ap_home_downrank_terms (phrase, category, enabled, created_at, updated_at) VALUES (?,?,?,?,?)');
    foreach ([['zeta phrase', 'custom', 1], ['Alpha "quote" é', 'protected_class', 0], ['odd', 'x"y<z>/ü', 1]] as [$p, $c, $e]) $st->execute([$p, $c, $e, '2026-10-19T12:00:00+00:00', '2026-10-19T12:00:00+00:00']);
    $st = $db->prepare('INSERT INTO ap_home_suppression VALUES (?,?,?,?,?,?,?,?)');
    foreach ([[1, 'https://bad.example/users/x'], [4, 'https://bad.example/users/x'], [1, 'https://other.example/users/y']] as [$o, $a]) $st->execute([$o + $big, $a, 3, '["custom"]', at(3600), '', '[]', at(-60)]);
    $st = $db->prepare('INSERT INTO events (created_at, type, actor_id, object_id, host, action_taken, summary, content, visibility) VALUES (?,?,?,?,?,?,?,?,?)');
    $rows = [
        [at(-10), 'Create', 'https://a.example/u/1', 'o1', 'a.example', 'log', null, '<p>You are a <b>bad</b> word here</p>', 'public'],
        [at(-20), 'Create', 'https://b.example/u/2', 'o2', 'b.example', 'local_observe', 'bad word', '<p>cw text</p>', 'unlisted'],
        [at(-30), 'Create', 'https://c.example/u/3', 'o3', null, 'log', '', '<p>#badword only a tag, and badwords</p>', 'public'],
        [at(-40), 'Create', 'https://d.example/u/4', 'o4', 'd.example', 'log', '', '<p>BAD WORD loud</p>', 'followers'],
        [at(-50), 'Like', 'https://e.example/u/5', 'o5', 'e.example', 'log', '', 'bad word', 'public'],
        [at(-60), 'Create', 'https://f.example/u/6', 'o6', 'f.example', 'reject', '', 'bad word', 'public'],
        [at(-31 * 86400), 'Create', 'https://g.example/u/7', 'o7', 'g.example', 'log', '', 'bad word old', 'public'],
        [at(-70), 'Create', 'https://h.example/u/8', 'o8', 'h.example', 'log', '', '<a href="x">bad</a> <i>word</i> split by tags', 'public'],
        [at(-80), 'Create', 'https://i.example/u/9', 'o9', 'i.example', 'log', '', 'Ünïcode café bad word ü', 'public'],
        [at(-80), 'Create', 'https://j.example/u/10', 'o10', 'j.example', 'log', '', 'same time bad word tie', 'public'],
        [at(-90), 'Create', 'https://k.example/u/11', 'o11', 'k.example', 'log', '', '<!-- bad word --> hidden comment', 'public'],
        [at(-100), 'Create', 'https://l.example/u/12', 'o12', 'l.example', 'log', '', 'café ok', 'public'],
    ];
    for ($i = 0; $i < 9; $i++) $rows[] = [at(-200 - $i), 'Create', "https://m.example/u/m$i", "m$i", 'm.example', 'log', '', "many bad word $i", 'public'];
    foreach ($rows as $row) $st->execute($row);
}
function redis(): Redis { $r = ap_redis_client('cache'); if (!$r) throw new RuntimeException('redis unavailable'); return $r; }
function snapshot(): array {
    global $db;
    $norm = static function (array $rows, array $timeCols): array {
        foreach ($rows as &$row) foreach ($timeCols as $c) {
            $v = (string) ($row[$c] ?? '');
            if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $v) && abs(strtotime($v) - time()) < 120) $row[$c] = 'NOW';
        }
        return $rows;
    };
    return [
        'terms' => $norm($db->query('SELECT id, phrase, category, enabled, created_at, updated_at FROM ap_home_downrank_terms ORDER BY id')->fetchAll(), ['created_at', 'updated_at']),
        'audit' => $norm($db->query('SELECT id, admin_user_id, admin_actor, action, actor_id, term_id, phrase, details_json, created_at FROM ap_home_downrank_audit ORDER BY id')->fetchAll(), ['created_at']),
        'suppression' => $db->query('SELECT owner_user_id, actor_id FROM ap_home_suppression ORDER BY owner_user_id, actor_id')->fetchAll(),
        'epoch' => redis()->get('vaak:trends:policy-epoch:v1:server'),
        'epoch_ttl_set' => redis()->ttl('vaak:trends:policy-epoch:v1:server') > 86400 * 29,
        'owner_epoch' => redis()->get('vaak:trends:policy-epoch:v1:1'),
    ];
}
function reset_state(): void {
    schema();
    redis()->set('vaak:trends:policy-epoch:v1:server', '7'); redis()->persist('vaak:trends:policy-epoch:v1:server');
    redis()->set('vaak:trends:policy-epoch:v1:1', '3');
}
/** Runs one POST through the chain: native-only or the switch off (PHP). */
function run(string $action, array $post, bool $native, int $owner = 1): array {
    global $chain, $big, $db;
    $u = $db->prepare('SELECT id, actor_key, is_admin FROM ap_users WHERE id = ?'); $u->execute([$owner + $big]); $user = $u->fetch() ?: null;
    $GLOBALS['current_user'] = $user ? ['id' => (int) $user['id'], 'actor_key' => $user['actor_key'], 'is_admin' => (int) $user['is_admin']] : null;
    $GLOBALS['vaak_owner_id'] = $vaakOwnerId = (int) ($user['id'] ?? 0);
    $GLOBALS['vaak_actor_key'] = $vaakActorKey = (string) ($user['actor_key'] ?? '');
    $vaakIsAdmin = true; // PHP's admin-only gate already passed for every request here.
    putenv('VAAK_ADMIN_DOWNRANKING_ACTIONS_NATIVE=' . ($native ? '1' : '0'));
    $_POST = $post; $notice = null; $error = null; $view = 'home'; $downrankPreview = null; $nativeOnly = $native; $fellBack = false;
    eval($chain);
    return ['notice' => $notice, 'error' => $error, 'view' => $view, 'preview' => $downrankPreview, 'fell_back' => $fellBack];
}

$checks = 0; $failures = [];
function check(bool $ok, string $label): void { global $checks, $failures; $checks++; if (!$ok) $failures[] = $label; }
function compare(string $label, string $action, array $post): void {
    reset_state(); $php = run($action, $post, false); $phpState = snapshot();
    reset_state(); $native = run($action, $post, true); $nativeState = snapshot();
    check(!$native['fell_back'], "$label native ran");
    unset($php['fell_back'], $native['fell_back']);
    check($php === $native, "$label page result");
    check($phpState === $nativeState, "$label state");
    if (getenv('DOWNRANK_ACTIONS_DUMP') === '1' && ($php !== $native || $phpState !== $nativeState)) {
        fwrite(STDERR, "== $label\n" . json_encode(['php' => [$php, $phpState], 'native' => [$native, $nativeState]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
function fallback(string $label, string $action, array $post, int $owner = 1): void {
    reset_state(); $before = snapshot();
    $r = run($action, $post, true, $owner);
    check($r['fell_back'], "$label reached PHP");
    check(snapshot() === $before, "$label wrote nothing");
}

$big1 = 1 + $big; $big3 = 3 + $big;
foreach ([
    'add new' => ['downrank_term_add', ['phrase' => '  new   phrase ', 'category' => 'sexualized_harassment']],
    'add existing re-enables' => ['downrank_term_add', ['phrase' => 'Alpha "quote" é', 'category' => 'custom']],
    'add stripped category' => ['downrank_term_add', ['phrase' => 'cat test', 'category' => 'protected<>_class']],
    'add unknown category' => ['downrank_term_add', ['phrase' => 'cat test', 'category' => 'nonsense']],
    'add empty category' => ['downrank_term_add', ['phrase' => 'cat test', 'category' => '!!!']],
    'add unicode spaces' => ['downrank_term_add', ['phrase' => "wide\u{3000}space\u{00A0}here", 'category' => 'custom']],
    'add html kept' => ['downrank_term_add', ['phrase' => '<b>bold</b> & co', 'category' => 'custom']],
    'add 120 chars' => ['downrank_term_add', ['phrase' => str_repeat('a', 120), 'category' => 'custom']],
    'add too long' => ['downrank_term_add', ['phrase' => str_repeat('a', 121), 'category' => 'custom']],
    'add too short' => ['downrank_term_add', ['phrase' => ' x ', 'category' => 'custom']],
    'add empty' => ['downrank_term_add', ['phrase' => "  \n ", 'category' => 'custom']],
    'delete' => ['downrank_term_delete', ['term_id' => (string) ($big + 3)]],
    'delete missing' => ['downrank_term_delete', ['term_id' => (string) ($big + 99)]],
    'delete invalid' => ['downrank_term_delete', ['term_id' => 'abc']],
    'delete zero' => ['downrank_term_delete', ['term_id' => '0']],
    'clear signal' => ['downrank_signal_clear', ['actor_id' => ' https://bad.example/users/x// ']],
    'clear unknown actor' => ['downrank_signal_clear', ['actor_id' => 'https://none.example/u']],
    'clear missing actor' => ['downrank_signal_clear', ['actor_id' => ' / ']],
    'preview' => ['downrank_term_preview', ['preview_phrase' => ' bad   word ']],
    'preview caseless' => ['downrank_term_preview', ['preview_phrase' => 'Bad Word']],
    'preview tagged phrase' => ['downrank_term_preview', ['preview_phrase' => '<i>bad</i> word']],
    'preview unicode' => ['downrank_term_preview', ['preview_phrase' => 'CAFÉ']],
    'preview hashtag ignored' => ['downrank_term_preview', ['preview_phrase' => 'badword']],
    'preview no match' => ['downrank_term_preview', ['preview_phrase' => 'nothing matches this']],
    'preview one char' => ['downrank_term_preview', ['preview_phrase' => 'x']],
    'preview tags only' => ['downrank_term_preview', ['preview_phrase' => '<b></b>']],
    'preview empty' => ['downrank_term_preview', ['preview_phrase' => '   ']],
    'preview too long' => ['downrank_term_preview', ['preview_phrase' => str_repeat('é', 121)]],
] as $label => [$action, $post]) compare($label, $action, $post);

// The scenarios above are not vacuous: the main preview finds and caps samples.
reset_state(); $r = run('downrank_term_preview', ['preview_phrase' => 'bad word'], true);
check(($r['preview']['count'] ?? 0) === 14 && count($r['preview']['samples'] ?? []) === 8 && ($r['preview']['samples'][0]['actor_id'] ?? '') === 'https://a.example/u/1', 'preview counts ' . json_encode($r['preview']['count'] ?? null));
reset_state(); run('downrank_signal_clear', ['actor_id' => 'https://bad.example/users/x'], true);
check(snapshot()['epoch'] === '8' && count(snapshot()['suppression']) === 1, 'signal clear bumps the epoch and deletes both owners');

// What the native branch must leave to PHP, untouched.
fallback('array phrase', 'downrank_term_add', ['phrase' => ['x'], 'category' => 'custom']);
fallback('U+180E phrase', 'downrank_term_add', ['phrase' => "mongolian\u{180E}sep", 'category' => 'custom']);
fallback('audit cut inside a character', 'downrank_term_add', ['phrase' => 'a' . str_repeat('é', 60), 'category' => 'custom']);
if ($idType === 'INTEGER') fallback('term id past int4', 'downrank_term_delete', ['term_id' => '3000000000']);
else compare('term id past int4', 'downrank_term_delete', ['term_id' => '3000000000']);
fallback('ordinary account', 'downrank_term_add', ['phrase' => 'from ordinary', 'category' => 'custom'], 2);
fallback('disabled admin', 'downrank_term_add', ['phrase' => 'from retired', 'category' => 'custom'], 3);
reset_state(); $db->exec('DROP TABLE ap_home_downrank_audit');
$r = run('downrank_term_add', ['phrase' => 'no audit table', 'category' => 'custom'], true);
check($r['fell_back'] && (int) $db->query("SELECT count(*) FROM ap_home_downrank_terms WHERE phrase = 'no audit table'")->fetchColumn() === 0, 'missing audit table rolls back and reaches PHP');

// The bridge validator.
reset_state();
$GLOBALS['current_user'] = ['id' => $big1, 'actor_key' => 'cmdr_nova', 'is_admin' => 1];
$good = ['protocol_version' => 1, 'ok' => true, 'outcome' => 'previewed', 'preview' => ['count' => 1, 'phrase' => 'x', 'samples' => [['host' => 'h', 'actor_id' => 'a', 'created_at' => 'c']]], 'php_epoch_bump' => false,
    'request_binding' => ['owner_id' => $big1, 'actor_key' => 'cmdr_nova', 'action' => 'downrank_term_preview']];
check(ap_admin_action_native_reply($good, 'downrank_term_preview', $big1, 'cmdr_nova') === ['outcome' => 'previewed', 'preview' => ['phrase' => 'x', 'count' => 1, 'samples' => [['actor_id' => 'a', 'host' => 'h', 'created_at' => 'c']]], 'php_epoch_bump' => false], 'bridge accepts and orders a preview');
foreach ([
    'wrong action binding' => static function (array $t): array { $t['request_binding']['action'] = 'downrank_term_add'; return $t; },
    'other owner' => static function (array $t): array { $t['request_binding']['owner_id'] = 2; return $t; },
    'extra key' => static function (array $t): array { $t['notice'] = 'x'; return $t; },
    'outcome of another action' => static function (array $t): array { $t['outcome'] = 'term_saved'; return $t; },
    'epoch bump on preview' => static function (array $t): array { $t['php_epoch_bump'] = true; return $t; },
    'too many samples' => static function (array $t): array { $t['preview']['samples'][] = $t['preview']['samples'][0]; return $t; },
    'sample extra key' => static function (array $t): array { $t['preview']['samples'][0]['x'] = 'y'; return $t; },
    'count as string' => static function (array $t): array { $t['preview']['count'] = '1'; return $t; },
    'bad phrase with samples' => static function (array $t): array { $t['outcome'] = 'bad_preview_phrase'; return $t; },
    'previewed empty phrase' => static function (array $t): array { $t['preview']['phrase'] = ''; return $t; },
    'not ok' => static function (array $t): array { $t['ok'] = false; return $t; },
] as $name => $mutate) check(ap_admin_action_native_reply($mutate($good), 'downrank_term_preview', $big1, 'cmdr_nova') === null, "bridge rejects $name");
$clear = ['protocol_version' => 1, 'ok' => true, 'outcome' => 'signal_cleared', 'preview' => null, 'php_epoch_bump' => true, 'request_binding' => ['owner_id' => $big1, 'actor_key' => 'cmdr_nova', 'action' => 'downrank_signal_clear']];
check(ap_admin_action_native_reply($clear, 'downrank_signal_clear', $big1, 'cmdr_nova') !== null, 'bridge accepts an epoch bump left to PHP');
$clear['preview'] = []; check(ap_admin_action_native_reply($clear, 'downrank_signal_clear', $big1, 'cmdr_nova') === null, 'bridge rejects a preview on another action');
check(ap_admin_action_native('downrank_term_add', $big1, 'cmdr_nova', ['phrase' => 'x']) === null, 'bridge refuses a short field set');
check(ap_admin_action_native('downrank_term_delete', $big1, 'cmdr_nova', ['term_id' => '3']) === null, 'bridge refuses a string id');
check(ap_admin_action_native('user_ban', $big1, 'cmdr_nova', ['user_id' => 2]) === null, 'bridge refuses another family');
$GLOBALS['current_user']['is_admin'] = 0;
check(ap_admin_action_native('downrank_term_add', $big1, 'cmdr_nova', ['phrase' => 'x y', 'category' => 'custom']) === null, 'bridge refuses a non-admin session');
$GLOBALS['current_user'] = ['id' => $big1, 'actor_key' => 'cmdr_nova', 'is_admin' => 1];
putenv('VAAK_ADMIN_DOWNRANKING_ACTIONS_NATIVE=0');
check(ap_admin_action_native('downrank_term_add', $big1, 'cmdr_nova', ['phrase' => 'x y', 'category' => 'custom']) === null, 'switch off keeps PHP');
// The worker refuses a forged session the bridge would never send.
$forge = static function (array $body) use ($url, $secret): int {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Vaak-Admin-Token: ' . $secret]]);
    curl_exec($c); $s = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c); return $s;
};
$body = ['protocol_version' => 1, 'owner_id' => $big1, 'actor_key' => 'ord', 'action' => 'downrank_term_add', 'fields' => ['phrase' => 'forged', 'category' => 'custom']];
check($forge($body) === 422, 'worker refuses an actor key that is not the account');
$body['actor_key'] = 'cmdr_nova'; $body['fields']['is_admin'] = true;
check($forge($body) === 400, 'worker refuses an unknown field');

$failures = array_values($failures);
echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
