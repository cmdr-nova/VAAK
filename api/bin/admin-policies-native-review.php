<?php
/**
 * Admin Policies native read review (sandbox only).
 *
 * Renders the genuine Policies branch of ap-admin.php against a seeded
 * PostgreSQL fixture, asks a running worker for the same page over
 * /internal/admin/read, and compares the HTML after collapsing whitespace
 * runs. Also checks the fallback cases (non-admin, disabled admin, too many
 * rows). Never point it at production: it drops and recreates its tables.
 *
 * Usage: php admin-policies-native-review.php 'pgsql:host=/var/run/postgresql;dbname=X' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET
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
$docs = (string) file_get_contents($api . '/ap-instance-docs.php');
foreach (['ap_instance_docs_migrate', 'ap_instance_doc_get', 'ap_instance_rules_list', 'ap_instance_docs_default_privacy', 'ap_instance_docs_default_conduct', 'ap_instance_docs_default_rules'] as $fn) eval(declaration($docs, $fn));
function ap_db_driver(PDO $db): string { return 'pgsql'; }

$marker = "      <?php elseif (\$view === 'policies'): ?>";
$start = strpos($source, $marker);
$end = strpos($source, "      <?php elseif (\$view === 'approvals'): ?>", (int) $start);
if ($start === false || $end === false) throw new RuntimeException('policies branch not found');
$template = substr($source, $start + strlen($marker), $end - $start - strlen($marker));
// Declared substitution: the declarations above stand in for this include.
$template = str_replace("require_once __DIR__ . '/ap-instance-docs.php';", '', $template, $replaced);
if ($replaced !== 1) throw new RuntimeException('include line changed');
$vaakIsAdmin = true;
// The branch now opens with the native bridge; render the PHP body by keeping it off.
function ap_admin_native_read_body(string $view): ?string { return null; }

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
const CSRF = 'session<&"token\'';
function ap_auth_csrf_token(): string { return CSRF; }

$db->exec("DROP TABLE IF EXISTS ap_instance_docs; DROP TABLE IF EXISTS ap_instance_rules; DROP TABLE IF EXISTS ap_users;
CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
INSERT INTO ap_users VALUES (1,'operator',1,NULL),(2,'ordinary',0,NULL);
CREATE TABLE ap_instance_docs(doc_key TEXT PRIMARY KEY, title TEXT, body TEXT DEFAULT '', updated_at TEXT);
CREATE TABLE ap_instance_rules(id $idType PRIMARY KEY, sort_order INTEGER NOT NULL DEFAULT 0, text TEXT, updated_at TEXT NOT NULL DEFAULT '')");

$checks = 0; $failures = [];
function check(bool $ok, string $label): void { global $checks, $failures; $checks++; if (!$ok) $failures[] = $label; }
/** Whitespace runs collapse outside textareas; textarea bodies must match byte for byte. */
function shape(string $html): array {
    preg_match_all('#<textarea\b[^>]*>(.*?)</textarea>#s', $html, $m);
    $outer = preg_replace('#(<textarea\b[^>]*>).*?(</textarea>)#s', '$1$2', $html);
    return [trim((string) preg_replace('/\s+/u', ' ', (string) $outer)), $m[1]];
}
function native(array $request, string $token): array {
    global $url;
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($request), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Vaak-Admin-Token: ' . $token]]);
    $body = (string) curl_exec($c); $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    return [$status, json_decode($body, true)];
}
function request(int $owner, string $mode): array {
    return ['protocol_version' => 1, 'owner_id' => $owner, 'csrf' => CSRF, 'view' => 'policies', 'mode' => $mode,
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => 'UTC'];
}
$doc = $db->prepare('INSERT INTO ap_instance_docs VALUES (?,?,?,?)');
$rule = $db->prepare('INSERT INTO ap_instance_rules (id, sort_order, text) VALUES (?,?,?)');
function seed(array $docs, array $rules): void {
    global $db, $doc, $rule;
    $db->exec('DELETE FROM ap_instance_docs; DELETE FROM ap_instance_rules');
    foreach ($docs as $d) $doc->execute($d);
    foreach ($rules as $i => [$order, $text]) $rule->execute([$i + 1, $order, $text]);
}
$cases = [
    'plain' => [[['privacy', 'Privacy Policy', "We keep little.\n\nThat's it.", '2026-10-01 00:00:00'], ['conduct', 'Code of Conduct', 'Be kind.', '2026-10-02T00:00:00+00:00']], [[1, 'No hate'], [2, 'No spam']]],
    'escaping' => [[['privacy', 'P <&"\'> é', "\n  leading newline & <b>tags</b>\r\n\ttabs\n\n\n", '0'], ['conduct', '0', '', '']], [[2, "  second  "], [1, "first <&>"], [1, "\t"], [3, ''], [3, null], [0, "zero \"q\" 'a'"]]],
    'nulls' => [[['privacy', null, null, null], ['conduct', '', 'x', 'today']], [[5, 'only rule']]],
    'ordering' => [[['privacy', 'a', 'b', 'c'], ['conduct', 'd', 'e', 'f']], array_map(fn($i) => [100 - $i, "rule $i"], range(1, 100))],
];
foreach ($cases as $name => [$docs, $rules]) {
    seed($docs, $rules);
    ob_start(); eval('?>' . $template); [$php, $phpAreas] = shape((string) ob_get_clean());
    check(count($phpAreas) === 3, "$name php textareas");
    foreach (['full', 'shell'] as $mode) {
        [$status, $reply] = native(request(1, $mode), $secret);
        check($status === 200, "$name/$mode status $status");
        [$html, $areas] = shape((string) ($reply['html'] ?? ''));
        check($html === $php, "$name/$mode html");
        check($areas === $phpAreas, "$name/$mode textarea bodies");
        if ($html !== $php || $areas !== $phpAreas) {
            file_put_contents(sys_get_temp_dir() . "/policies-$name-$mode.php.html", $php . "\n" . json_encode($phpAreas));
            file_put_contents(sys_get_temp_dir() . "/policies-$name-$mode.native.html", $html . "\n" . json_encode($areas));
        }
        check(($reply['source_count'] ?? -1) === count(ap_instance_rules_list()), "$name/$mode count");
    }
}

// Fallbacks: PHP keeps the built-in default texts and every refusal renders the PHP page.
$good = $cases['plain'];
seed([$good[0][1]], $good[1]); [$status] = native(request(1, 'full'), $secret); check($status === 422, "missing privacy -> $status");
seed([$good[0][0]], $good[1]); [$status] = native(request(1, 'full'), $secret); check($status === 422, "missing conduct -> $status");
seed($good[0], []); [$status] = native(request(1, 'full'), $secret); check($status === 422, "no rules -> $status");
seed($good[0], [[1, '  '], [2, '']]); [$status] = native(request(1, 'full'), $secret); check($status === 422, "blank rules -> $status");
seed($good[0], array_map(fn($i) => [$i, "r$i"], range(1, 101))); [$status] = native(request(1, 'full'), $secret); check($status === 422, "101 rules -> $status");
seed($good[0], $good[1]);
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
