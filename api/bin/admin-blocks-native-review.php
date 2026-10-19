<?php
/**
 * Admin Server blocks native read review (sandbox only).
 *
 * Renders the genuine Server blocks branch of ap-admin.php against a seeded
 * PostgreSQL fixture, asks a running worker for the same page over
 * /internal/admin/read, and compares the HTML after collapsing whitespace
 * runs. Also checks the fallback cases (non-admin, disabled admin, too many
 * rows). Never point it at production: it drops and recreates its tables.
 *
 * Usage: php admin-blocks-native-review.php 'pgsql:host=/var/run/postgresql;dbname=X' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
[$self, $dsn, $idType, $url, $secret] = $argv + [null, null, 'INTEGER', null, null];
if (!$dsn || !in_array($idType, ['INTEGER', 'BIGINT'], true) || !$url || strlen((string) $secret) < 32) {
    fwrite(STDERR, "usage: dsn INTEGER|BIGINT url secret\n"); exit(2);
}
if (!str_contains($dsn, 'fixture')) { fwrite(STDERR, "refusing a database without 'fixture' in its name\n"); exit(2); }

$api = dirname(__DIR__);
$source = (string) file_get_contents($api . '/ap-admin.php');
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
eval(declaration($source, 'h'));
$db_src = (string) file_get_contents($api . '/ap-db.php');
foreach (['relative_time', 'short_host', 'actor_handle', 'actor_display_name'] as $fn) eval(declaration($source, $fn));
foreach (['ap_block_list', 'ap_remote_actor_label', 'ap_remote_actor_ensure', 'ap_remote_actor_get', 'ap_remote_actor_id_is_unfederated_local',
    'ap_remote_actor_normalize_username', 'ap_remote_actor_username_is_placeholder'] as $fn) eval(declaration($db_src, $fn));
// Declared substitutions for file/Redis/queue helpers (no Redis in the sandbox on either side).
function ap_remote_actor_icon_miss_until(string $actorId): int { return 0; }
function ap_remote_actor_flat_put(string $actorId, ?array $row = null): void {}
$warmed = [];
function ap_remote_actor_warm_async(string $actorId, bool $force = false): void { $GLOBALS['warmed'][] = $actorId; }
$marker = "      <?php elseif (\$view === 'blocks'): ?>";
$start = strpos($source, $marker);
$end = strpos($source, "      <?php elseif (\$view === 'users'): ?>", (int) $start);
if ($start === false || $end === false) throw new RuntimeException('blocks branch not found');
$template = substr($source, $start + strlen($marker), $end - $start - strlen($marker));

// The branch now opens with the native bridge; render the PHP body by keeping it off.
function ap_admin_native_read_body(string $view): ?string { return null; }

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
const CSRF = 'session<&"token\'';
function ap_auth_csrf_token(): string { return CSRF; }

$db->exec("DROP TABLE IF EXISTS ap_blocks; DROP TABLE IF EXISTS remote_actors; DROP TABLE IF EXISTS ap_users;
CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
INSERT INTO ap_users VALUES (1,'operator',1,NULL),(2,'ordinary',0,NULL);
CREATE TABLE ap_blocks(id $idType PRIMARY KEY, created_at TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT '', scope TEXT NOT NULL, value TEXT NOT NULL, kind TEXT NOT NULL, reason TEXT, created_by TEXT DEFAULT 'admin', UNIQUE(scope, value));
CREATE TABLE remote_actors(actor_id TEXT PRIMARY KEY, username TEXT, display_name TEXT, host TEXT, icon_source_url TEXT, image_source_url TEXT, updated_at TEXT NOT NULL DEFAULT '', summary TEXT, profile_json TEXT)");

$checks = 0; $failures = [];
function check(bool $ok, string $label): void { global $checks, $failures; $checks++; if (!$ok) $failures[] = $label; }
function collapse(string $html): string { return trim((string) preg_replace('/\s+/u', ' ', $html)); }
function native(array $request, string $token): array {
    global $url;
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($request), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Vaak-Admin-Token: ' . $token]]);
    $body = (string) curl_exec($c); $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    return [$status, json_decode($body, true)];
}
function request(int $owner, string $mode): array {
    return ['protocol_version' => 1, 'owner_id' => $owner, 'csrf' => CSRF, 'view' => 'blocks', 'mode' => $mode,
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => 'UTC'];
}
$block = $db->prepare('INSERT INTO ap_blocks (id, created_at, scope, value, kind, reason) VALUES (?,?,?,?,?,?)');
$actor = $db->prepare('INSERT INTO remote_actors (actor_id, username, display_name, host) VALUES (?,?,?,?)');
function seed(array $blocks, array $actors): void {
    global $db, $block, $actor;
    $db->exec('DELETE FROM ap_blocks; DELETE FROM remote_actors');
    foreach ($blocks as $i => $b) $block->execute(array_merge([$i + 1], $b));
    foreach ($actors as $a) $actor->execute($a);
}
$ago = fn(int $s, bool $iso = true) => $iso ? gmdate('c', time() - $s) : gmdate('Y-m-d H:i:s', time() - $s);
$actorsCached = [
    ['https://m.example/users/alice', 'alice', 'Alice <A&B>', 'm.example'],
    ['https://m.example/users/bob', 'bob', '', null],
    ['https://g.example/@carol', '@carol', '   ', 'G.Example'],
    ['https://bsky.brid.gy/ap/did:plc:abc', 'dave.bsky.social', 'Dave', 'bsky.brid.gy'],
    ['https://z.example/users/zero', 'zero', '0', 'z.example'],
    ['https://n.example/users/num', 'num', '1234567', 'n.example'],
    ['https://s.example/users/slash/', 'slash', 'Slash', 's.example'],
];
$cases = [
    'empty' => [[], []],
    'domains' => [[
        [$ago(30), 'domain', 'spam.example', 'block', 'spam  & <b>junk</b> '],
        [$ago(3000, false), 'domain', 'mute.example', 'mute', null],
        [$ago(90000), 'domain', 'sus.example', 'suspend', '0'],
        [$ago(90000), 'domain', 'tie.example', 'block', ''],
        [$ago(5), 'domain', 'Ünïcode.Example', 'block', "line\nbreak"],
    ], []],
    'actors' => [[
        [$ago(10), 'actor', 'https://m.example/users/alice', 'block', 'harassment'],
        [$ago(20), 'actor', 'https://m.example/users/bob', 'suspend', null],
        [$ago(30), 'actor', 'https://g.example/@carol', 'mute', ' x '],
        [$ago(40), 'actor', 'https://bsky.brid.gy/ap/did:plc:abc', 'block', null],
        [$ago(50), 'actor', 'https://z.example/users/zero', 'block', null],
        [$ago(60), 'actor', 'https://n.example/users/num', 'block', null],
        [$ago(70), 'actor', 'https://s.example/users/slash/', 'block', 'q?a=1&b="2"'],
        [$ago(70), 'domain', 'mixed.example', 'block', null],
        [$ago(80), 'actor', '', 'block', 'empty value'],
    ], $actorsCached],
    'many' => [array_map(fn($i) => [$ago($i), $i % 2 ? 'actor' : 'domain', $i % 2 ? "https://m.example/users/u$i" : "d$i.example", 'block', null], range(1, 170)),
        array_map(fn($i) => ["https://m.example/users/u$i", "u$i", "User $i", 'm.example'], range(1, 170, 2))],
];
foreach ($cases as $name => [$blocks, $actors]) {
    seed($blocks, $actors); $GLOBALS['ap_remote_actor_memo'] = []; $warmed = [];
    $blocks = ap_block_list();
    foreach (['full', 'shell'] as $mode) {
        // Relative times read the clock: retry until PHP and the native request share one second.
        for ($try = 0; $try < 5; $try++) {
            $second = time();
            ob_start(); eval('?>' . $template); $php = collapse((string) ob_get_clean());
            [$status, $reply] = native(request(1, $mode), $secret);
            if (time() === $second) break;
        }
        check($warmed === [], "$name php warmed nothing");
        check($status === 200, "$name/$mode status $status");
        $html = collapse((string) ($reply['html'] ?? ''));
        check($html === $php, "$name/$mode html");
        if ($html !== $php) {
            file_put_contents(sys_get_temp_dir() . "/blocks-$name-$mode.php.html", $php);
            file_put_contents(sys_get_temp_dir() . "/blocks-$name-$mode.native.html", $html);
        }
        check(($reply['source_count'] ?? -1) === count($blocks), "$name/$mode count");
    }
}

// Fallbacks: rows PHP labels from a local profile or would warm, and every refusal, render the PHP page.
$base = [[$ago(10), 'domain', 'ok.example', 'block', null]];
$fallbacks = [
    'uncached actor' => [[[$ago(5), 'actor', 'https://x.example/users/new', 'block', null]], []],
    'placeholder actor' => [[[$ago(5), 'actor', 'https://x.example/ap/users/123456789', 'block', null]], [['https://x.example/ap/users/123456789', '123456789', '', 'x.example']]],
    'local actor' => [[[$ago(5), 'actor', 'https://mkultra.monster/users/someone', 'block', null]], []],
    'non-https actor' => [[[$ago(5), 'actor', 'http://x.example/users/a', 'block', null]], []],
    'unknown scope' => [[[$ago(5), 'tag', 'x', 'block', null]], []],
    'odd date' => [[[ 'yesterday-ish', 'domain', 'd.example', 'block', null]], []],
];
foreach ($fallbacks as $name => [$blocks, $actors]) { seed(array_merge($base, $blocks), $actors); [$status] = native(request(1, 'full'), $secret); check($status === 422, "$name -> $status"); }
seed(array_map(fn($i) => [$ago($i), 'domain', "d$i.example", 'block', null], range(1, 1001)), []);
[$status] = native(request(1, 'full'), $secret); check($status === 422, "1001 rows -> $status");
seed($base, []);
[$status] = native(request(2, 'full'), $secret); check($status === 422, "ordinary user -> $status");
$db->exec("UPDATE ap_users SET disabled_at='2026-10-19 00:00:00' WHERE id=1");
[$status] = native(request(1, 'full'), $secret); check($status === 422, "disabled admin -> $status");
$db->exec("UPDATE ap_users SET disabled_at=NULL, is_admin=0 WHERE id=1");
[$status] = native(request(1, 'full'), $secret); check($status === 422, "revoked admin -> $status");
$db->exec('UPDATE ap_users SET is_admin=1 WHERE id=1');
[$status] = native(request(1, 'full'), str_repeat('x', strlen($secret))); check($status === 401, "wrong token -> $status");
$bad = request(1, 'full'); $bad['offset'] = 1; [$status] = native($bad, $secret); check($status === 400, "offset -> $status");

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
