<?php
/**
 * Settings AI alt-text panel native read review (sandbox only).
 *
 * Renders the genuine AI alt-text section of the profile view in ap-admin.php, with
 * the genuine ap_auth_user_has_xai_key and ap_auth_user_by_id, against a seeded
 * PostgreSQL fixture, asks a running worker for the same section over
 * /internal/admin/read, and compares the HTML after collapsing whitespace runs.
 * Checks an admin and ordinary accounts with and without a key, that the encrypted
 * key never appears in the reply, and the fallback cases. Never point it at
 * production: it drops and recreates its tables.
 *
 * Usage: php settings-ai-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET
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
$dbSource = (string) file_get_contents($api . '/ap-db.php');
$authSource = (string) file_get_contents($api . '/ap-auth.php');
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
foreach (['h'] as $fn) eval(declaration($source, $fn));
foreach (['ap_fix_utf8'] as $fn) eval(declaration($dbSource, $fn));
foreach (['ap_auth_user_has_xai_key', 'ap_auth_user_by_id'] as $fn) eval(declaration($authSource, $fn));
function ap_auth_current_user(): ?array { throw new RuntimeException('the template passes the page user'); }

function region(string $source, string $start, string $end): string {
    $a = strpos($source, $start); if ($a === false) throw new RuntimeException('missing start ' . substr($start, 0, 60));
    $b = strpos($source, $end, $a); if ($b === false) throw new RuntimeException('missing end ' . substr($end, 0, 60));
    return substr($source, $a + strlen($start), $b - $a - strlen($start));
}
$template = region($source,
    "<?php if ((\$settingsNativeAi = ap_settings_native_body('settings_ai', !empty(\$vaakIsAdmin))) !== null): ?><?= \$settingsNativeAi ?><?php else: ?>",
    "        <?php endif; ?>\n\n        <?php if (empty(\$vaakIsAdmin)): ?>");
require $api . '/ap-admin-native.php';

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
const CSRF = 'session<&"token\'';
function ap_auth_csrf_token(): string { return CSRF; }
function schema(): void {
    global $db, $idType;
    $db->exec("DROP TABLE IF EXISTS ap_users;
    CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT, xai_api_key_enc TEXT, ai_api_root TEXT, ai_model TEXT);
    INSERT INTO ap_users VALUES (1,'operator','op_er',1,NULL,'ENC_SECRET_BLOB_1','  https://api.example/v1?a=1&b=\"2\"  ',' model <x> '),
      (2,'ordinary','ord',0,NULL,NULL,NULL,NULL),(3,'blank','blank',0,NULL,'','https://only-root.example',''),(4,'keyed','keyed',0,NULL,'ENC_SECRET_BLOB_4',NULL,'gpt-4o-mini')");
}

$checks = 0; $failures = [];
function check(bool $ok, string $label): void { global $checks, $failures; $checks++; if (!$ok) $failures[] = $label; }
function collapse(string $html): string { return trim((string) preg_replace('/\s+/u', ' ', $html)); }
function native(array $request, string $token): array {
    global $url;
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($request), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Vaak-Admin-Token: ' . $token]]);
    $body = (string) curl_exec($c); $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    return [$status, json_decode($body, true), $body];
}
function request(int $owner, bool $admin, string $mode = 'full'): array {
    return ['protocol_version' => 1, 'owner_id' => $owner, 'csrf' => CSRF, 'view' => 'settings_ai', 'mode' => $mode,
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => 'UTC', 'viewer_admin' => $admin];
}
function render(int $owner): string {
    global $template, $db;
    $vaakUser = $db->query('SELECT * FROM ap_users WHERE id = ' . $owner)->fetch();
    ob_start(); eval('?>' . $template); return collapse((string) ob_get_clean());
}

schema();
foreach ([[1, true, true], [2, false, false], [3, false, false], [4, false, true]] as [$owner, $admin, $configured]) foreach (['full', 'shell'] as $mode) {
    $php = render($owner); [$status, $reply, $body] = native(request($owner, $admin, $mode), $secret);
    $label = "owner$owner/$mode";
    check($status === 200, "$label status $status");
    $html = collapse((string) ($reply['html'] ?? ''));
    check($html === $php, "$label html");
    if ($html !== $php) { file_put_contents(sys_get_temp_dir() . "/ai-$owner-$mode.php.html", $php); file_put_contents(sys_get_temp_dir() . "/ai-$owner-$mode.native.html", $html); }
    check(str_contains($php, 'AI alt-text settings'), "$label PHP section rendered");
    check(!str_contains($body, 'ENC_SECRET'), "$label encrypted key never in the reply");
    check(($reply['rows'] ?? null) === [['configured' => $configured, 'panel' => 'ai_alt_text']], "$label rows " . json_encode($reply['rows'] ?? null));
    $sent = request($owner, $admin, $mode);
    check(is_array($reply) && ap_admin_native_reply($reply, $sent) === ($reply['html'] ?? null), "$label bridge accepts reply");
    if ($owner === 1 && is_array($reply)) {
        $t = $reply; $t['rows'][0]['extra'] = 1; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects extra row key");
        $t = $reply; $t['rows'][0]['configured'] = 1; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects non-boolean presence");
        $t = $reply; $t['rows'][0]['panel'] = 'wow'; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects other panel");
        $t = $reply; $t['request_binding']['viewer_admin'] = !$admin; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects other role binding");
    }
}

// Fallbacks: text PHP would repair, a schema without the AI columns (PHP shows not configured), and every refusal.
foreach (['mojibake model' => "UPDATE ap_users SET ai_model='CafÃ©' WHERE id=1", 'no model column' => 'ALTER TABLE ap_users DROP COLUMN ai_model'] as $name => $sql) {
    schema(); $db->exec($sql);
    check(str_contains(render(1), 'AI alt-text settings'), "$name PHP section rendered");
    [$status] = native(request(1, true), $secret); check($status === 422, "$name -> $status");
}
schema();
[$status] = native(request(1, false), $secret); check($status === 422, "role mismatch -> $status");
[$status] = native(request(9, false), $secret); check($status === 422, "unknown account -> $status");
$bad = request(1, true); unset($bad['viewer_admin']); [$status] = native($bad, $secret); check($status === 400, "no role -> $status");
$bad = request(1, true); $bad['offset'] = 1; [$status] = native($bad, $secret); check($status === 400, "offset -> $status");
$db->exec("UPDATE ap_users SET disabled_at='2026-10-19 00:00:00' WHERE id=2");
[$status] = native(request(2, false), $secret); check($status === 422, "disabled account -> $status");

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
