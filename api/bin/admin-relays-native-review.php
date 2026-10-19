<?php
/**
 * Admin Relays native read review (sandbox only).
 *
 * Renders the genuine Relays branch of ap-admin.php against a seeded
 * PostgreSQL fixture, asks a running worker for the same page over
 * /internal/admin/read, and compares the HTML after collapsing whitespace
 * runs. Also checks the fallback cases (non-admin, disabled admin, too many
 * rows). Never point it at production: it drops and recreates its tables.
 *
 * Usage: php admin-relays-native-review.php 'pgsql:host=/var/run/postgresql;dbname=X' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET
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
eval(declaration((string) file_get_contents($api . '/ap-relays.php'), 'ap_relays_list'));

$marker = "      <?php elseif (\$view === 'relays'): ?>";
$start = strpos($source, $marker);
$end = strpos($source, "      <?php elseif (\$view === 'account_switcher'): ?>", (int) $start);
if ($start === false || $end === false) throw new RuntimeException('relays branch not found');
$template = substr($source, $start + strlen($marker), $end - $start - strlen($marker));
// The branch now opens with the native bridge; render the PHP body by keeping it off.
function ap_admin_native_read_body(string $view): ?string { return null; }

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
const CSRF = 'session<&"token\'';
function ap_auth_csrf_token(): string { return CSRF; }

$db->exec("DROP TABLE IF EXISTS ap_relays; DROP TABLE IF EXISTS ap_users;
CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
INSERT INTO ap_users VALUES (1,'operator',1,NULL),(2,'ordinary',0,NULL);
CREATE TABLE ap_relays(id $idType PRIMARY KEY, inbox_url TEXT NOT NULL UNIQUE, state TEXT NOT NULL DEFAULT 'idle', follow_activity_id TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");

$seeds = [
    'empty' => [],
    'one-accepted' => [['https://relay.one/inbox', 'accepted', 'https://mkultra.monster/follow/1', '2026-10-01T00:00:00+00:00']],
    'all-states' => [
        ['https://a.example/inbox', 'accepted', 'https://mkultra.monster/f/a', '2026-10-01 10:00:00'],
        ['https://p.example/inbox', 'pending', 'https://mkultra.monster/f/p', '2026-10-02 10:00:00'],
        ['https://r.example/inbox', 'rejected', null, '2026-10-03 10:00:00'],
        ['https://i.example/inbox', 'idle', '', '2026-10-04 10:00:00'],
        ['https://u.example/inbox', 'weird', '0', ''],
        ['https://esc.example/inbox?a=1&b=<x>"\'', 'pending', 'id with <b>&"\'é', 'ünï & <code>'],
        ['https://zero.example/inbox', 'accepted', '00', '2026-10-05'],
    ],
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
    return ['protocol_version' => 1, 'owner_id' => $owner, 'csrf' => CSRF, 'view' => 'relays', 'mode' => $mode,
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => 'UTC'];
}

$insert = $db->prepare('INSERT INTO ap_relays VALUES (?,?,?,?,?,?)');
foreach ($seeds as $name => $rows) {
    $db->exec('DELETE FROM ap_relays');
    foreach ($rows as $i => [$inbox, $state, $follow, $updated]) $insert->execute([$i + 1, $inbox, $state, $follow, '2026-09-01 00:00:00', $updated]);
    ob_start(); eval('?>' . $template); $php = collapse((string) ob_get_clean());
    foreach (['full', 'shell'] as $mode) {
        [$status, $reply] = native(request(1, $mode), $secret);
        check($status === 200, "$name/$mode status $status");
        $html = collapse((string) ($reply['html'] ?? ''));
        check($html === $php, "$name/$mode html");
        if ($html !== $php) {
            file_put_contents(sys_get_temp_dir() . "/relays-$name-$mode.php.html", $php);
            file_put_contents(sys_get_temp_dir() . "/relays-$name-$mode.native.html", $html);
        }
        check(($reply['source_count'] ?? -1) === count($rows), "$name/$mode count");
        check(substr_count($html, 'name="csrf" value="' . h(CSRF) . '"') === substr_count($php, 'name="csrf"'), "$name/$mode csrf");
    }
}

// Fallbacks: the PHP bridge renders the PHP page whenever native does not answer 200.
[$status] = native(request(2, 'full'), $secret); check($status === 422, "ordinary user -> $status");
$db->exec("UPDATE ap_users SET disabled_at='2026-10-19 00:00:00' WHERE id=1");
[$status] = native(request(1, 'full'), $secret); check($status === 422, "disabled admin -> $status");
$db->exec("UPDATE ap_users SET disabled_at=NULL, is_admin=0 WHERE id=1");
[$status] = native(request(1, 'full'), $secret); check($status === 422, "revoked admin -> $status");
$db->exec('UPDATE ap_users SET is_admin=1 WHERE id=1');
[$status] = native(request(1, 'full'), str_repeat('x', strlen($secret))); check($status === 401, "wrong token -> $status");
$bad = request(1, 'full'); $bad['offset'] = 1; [$status] = native($bad, $secret); check($status === 400, "offset -> $status");
$bad = request(1, 'full'); $bad['search'] = ''; [$status] = native($bad, $secret); check($status === 400, "search -> $status");
$db->exec('DELETE FROM ap_relays');
for ($i = 1; $i <= 101; $i++) $insert->execute([$i, "https://r$i.example/inbox", 'idle', null, 'x', 'y']);
[$status] = native(request(1, 'full'), $secret); check($status === 422, "101 relays -> $status");

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
