<?php
/**
 * Admin Wafrn friends native read review (sandbox only).
 *
 * Renders the genuine friend_servers branch of ap-admin.php against a seeded
 * PostgreSQL fixture, asks a running worker for the same page over
 * /internal/admin/read, and compares the HTML after collapsing whitespace
 * runs. Also checks the fallback cases (non-admin, disabled admin, odd catalog tokens,
 * too many rows). It writes /var/lib/mkultra/ap/wafrn-friend-hosts.txt, so run it
 * only in a throwaway sandbox. Never point it at production: it drops and recreates its tables.
 *
 * Usage: php admin-wafrn-friends-native-review.php 'pgsql:...;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET API_ROOT
 *   (API_ROOT is the worker's VAAK_API_ROOT, a scratch directory)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
[$self, $dsn, $idType, $url, $secret, $apiRoot] = $argv + [null, null, 'INTEGER', null, null, null];
if (!$dsn || !in_array($idType, ['INTEGER', 'BIGINT'], true) || !$url || strlen((string) $secret) < 32 || !$apiRoot || !is_dir((string) $apiRoot)) {
    fwrite(STDERR, "usage: dsn INTEGER|BIGINT url secret api_root\n"); exit(2);
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

$marker = "      <?php elseif (\$view === 'friend_servers'): ?>";
$start = strpos($source, $marker);
$end = strpos($source, "      <?php elseif (\$view === 'dms'): ?>", (int) $start);
if ($start === false || $end === false) throw new RuntimeException('friend_servers branch not found');
$template = substr($source, $start + strlen($marker), $end - $start - strlen($marker));
// The bundled catalog sits next to ap-admin.php; point it at the worker's API root.
$bundledExpr = "__DIR__ . '/deploy/wafrn-friend-hosts.txt'";
if (substr_count($template, $bundledExpr) !== 1) throw new RuntimeException('bundled catalog path changed');
$template = str_replace($bundledExpr, "\$GLOBALS['apiRoot'] . '/deploy/wafrn-friend-hosts.txt'", $template);
// The branch opens with the native bridge; render the PHP body by keeping it off.
$bridgeLine = "<?php if ((\$adminNativeBody = ap_admin_native_read_body('friend_servers')) !== null): ?>";
if (substr_count($template, $bridgeLine) !== 1) throw new RuntimeException('bridge line changed');
$template = str_replace($bridgeLine, '<?php if (false): ?>', $template);
require $api . '/ap-admin-native.php';

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
const CSRF = 'session<&"token\'';
function ap_auth_csrf_token(): string { return CSRF; }
putenv('VAAK_WAFRN_FRIEND_HOSTS');

const RUNTIME = '/var/lib/mkultra/ap/wafrn-friend-hosts.txt';
if (is_file(RUNTIME)) { fwrite(STDERR, "refusing to overwrite an existing " . RUNTIME . "\n"); exit(2); }
@mkdir(dirname(RUNTIME), 0750, true);
@mkdir($apiRoot . '/deploy', 0755, true);
$bundled = $apiRoot . '/deploy/wafrn-friend-hosts.txt';

$db->exec("DROP TABLE IF EXISTS ap_wafrn_friend_servers; DROP TABLE IF EXISTS ap_users;
CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
INSERT INTO ap_users VALUES (1,'operator',1,NULL),(2,'ordinary',0,NULL)");
function friends(?array $rows): void {
    $db = ap_db();
    $db->exec('DROP TABLE IF EXISTS ap_wafrn_friend_servers');
    if ($rows === null) return;
    $db->exec("CREATE TABLE ap_wafrn_friend_servers (host TEXT PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 1, note TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $insert = $db->prepare('INSERT INTO ap_wafrn_friend_servers VALUES (?,?,?,?,?)');
    foreach ($rows as [$host, $enabled, $note]) $insert->execute([$host, $enabled, $note, '2026-10-01 00:00:00', '2026-10-02 00:00:00']);
}
function files(?string $runtime, ?string $bundled): void {
    @unlink(RUNTIME); @unlink($GLOBALS['bundled']);
    if ($runtime !== null) file_put_contents(RUNTIME, $runtime);
    if ($bundled !== null) file_put_contents($GLOBALS['bundled'], $bundled);
}

$prodLike = implode("\n", ['waffles.baeddel.social', 'app.wafrn.net', 'wafrn.example', 'b.example', 'a-b.example', '1.2.3.4', 'xn--bcher-kva.example']) . "\n";
$scenarios = [
    // name => [runtime catalog, bundled catalog, friend rows or null for no table]
    'no-files-no-table' => [null, null, null],
    'no-files-empty-table' => [null, null, []],
    'runtime-prod-like' => [$prodLike, "ignored.example\n", []],
    'runtime-mixed-separators' => ["Upper.Example\r\nhttps://pre.example/,comma.example\t tab.example\x0Bvt.example\x0Cff.example\n\nmkultra.monster\nMKULTRA.MONSTER\nupper.example\n", null, []],
    'runtime-empty-file' => ['', "bundled.example\n", []],
    'runtime-blank-file' => [" \n\t\n", null, []],
    'bundled-only' => [null, "bundled.example\nzzz.example\n", []],
    'rows' => [$prodLike, null, [
        ['friend.example', 1, 'Trusted <b>&"\'é'],
        ['disabled.example', 0, 'off'],
        ['a&b<c>', 1, ''],
        ['zz.example', 1, '0'],
    ]],
];
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
    return ['protocol_version' => 1, 'owner_id' => $owner, 'csrf' => CSRF, 'view' => 'friend_servers', 'mode' => $mode,
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => 'UTC'];
}
function php_page(): string { ob_start(); eval('?>' . $GLOBALS['template']); return collapse((string) ob_get_clean()); }

foreach ($scenarios as $name => [$runtime, $bundledText, $rows]) {
    files($runtime, $bundledText); friends($rows);
    $php = php_page();
    $enabled = $rows === null ? 0 : count(array_filter($rows, fn($r) => $r[1] === 1));
    foreach (['full', 'shell'] as $mode) {
        [$status, $reply] = native(request(1, $mode), $secret);
        check($status === 200, "$name/$mode status $status");
        $html = collapse((string) ($reply['html'] ?? ''));
        check($html === $php, "$name/$mode html");
        if ($html !== $php) {
            file_put_contents(sys_get_temp_dir() . "/wafrn-$name-$mode.php.html", $php);
            file_put_contents(sys_get_temp_dir() . "/wafrn-$name-$mode.native.html", $html);
        }
        check(($reply['source_count'] ?? -1) === $enabled, "$name/$mode count");
        check(is_array($reply) && ap_admin_native_reply($reply, request(1, $mode)) === ($reply['html'] ?? null), "$name/$mode bridge accepts reply");
        if ($enabled > 0) {
            $sent = request(1, $mode);
            $t = $reply; $t['rows'][0]['note'] = 1; check(ap_admin_native_reply($t, $sent) === null, "$name/$mode bridge rejects int note");
            $t = $reply; $t['rows'][0]['enabled'] = 1; check(ap_admin_native_reply($t, $sent) === null, "$name/$mode bridge rejects extra key");
            $t = $reply; $t['rows'][] = $t['rows'][0]; $t['source_count']++; check(ap_admin_native_reply($t, $sent) === null, "$name/$mode bridge rejects duplicate host");
        }
        $t = $reply; $t['request_binding']['filter'] = 'open'; check(ap_admin_native_reply($t, request(1, $mode)) === null, "$name/$mode bridge rejects extra binding");
        check(substr_count($html, 'name="csrf" value="' . h(CSRF) . '"') === substr_count($php, 'name="csrf"'), "$name/$mode csrf");
    }
}

// Tokens PHP might read differently are a fallback, and the PHP page renders.
foreach (['http://scheme.example', 'port.example:443', 'dot..example', 'trail.example.', 'path.example/x', 'under_score.example', "\u{e9}.example", "nul\0.example", '-dash.example'] as $odd) {
    files("good.example\n$odd\n", null); friends([]);
    [$status] = native(request(1, 'full'), $secret); check($status === 422, "odd token " . json_encode($odd) . " -> $status");
}
// The worker reads its own catalog; one it cannot read is a fallback, never an empty list.
files(null, null); mkdir(RUNTIME); friends([]);
[$status] = native(request(1, 'full'), $secret); check($status === 200, "runtime path is a directory -> $status");
rmdir(RUNTIME);
files("ok.example\n", null);
[$status] = native(request(2, 'full'), $secret); check($status === 422, "ordinary user -> $status");
$db->exec("UPDATE ap_users SET disabled_at='2026-10-19 00:00:00' WHERE id=1");
[$status] = native(request(1, 'full'), $secret); check($status === 422, "disabled admin -> $status");
$db->exec("UPDATE ap_users SET disabled_at=NULL, is_admin=0 WHERE id=1");
[$status] = native(request(1, 'full'), $secret); check($status === 422, "revoked admin -> $status");
$db->exec('UPDATE ap_users SET is_admin=1 WHERE id=1');
[$status] = native(request(1, 'full'), str_repeat('x', strlen($secret))); check($status === 401, "wrong token -> $status");
$bad = request(1, 'full'); $bad['offset'] = 1; [$status] = native($bad, $secret); check($status === 400, "offset -> $status");
$bad = request(1, 'full'); $bad['filter'] = 'open'; [$status] = native($bad, $secret); check($status === 400, "filter -> $status");
$many = []; for ($i = 1; $i <= 101; $i++) $many[] = ["f$i.example", 1, ''];
friends($many);
[$status] = native(request(1, 'full'), $secret); check($status === 422, "101 friends -> $status");
files(null, null); friends(null);

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
