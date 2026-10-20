<?php
/**
 * Settings Security page native read review (sandbox only).
 *
 * Renders the genuine two-factor and OAuth token sections of the security view in
 * ap-admin.php, with the genuine ap_oauth_tokens_list, ap_db_cmdr_nova_user_id,
 * ap_auth_2fa_row and ap_auth_2fa_enabled, against a seeded PostgreSQL fixture, asks
 * a running worker for the same sections over /internal/admin/read, and compares the
 * HTML after collapsing whitespace runs. Checks the operator, ordinary and admin
 * accounts, legacy NULL-owner tokens, revoked, expired and active tokens, the 40-row
 * cap, that token hashes, client secrets, TOTP secrets and recovery codes never
 * appear in a reply, and the fallback cases. Never point it at production: it drops
 * and recreates its tables.
 *
 * Usage: php settings-security-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET
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
// ap_db_cmdr_nova_user_id loses its static cache so each scenario reads the fixture afresh.
foreach (['ap_fix_utf8', 'ap_oauth_tokens_list', 'ap_db_cmdr_nova_user_id'] as $fn) eval(declaration($dbSource, $fn));
foreach (['ap_auth_2fa_row', 'ap_auth_2fa_enabled'] as $fn) eval(declaration($authSource, $fn));
function ap_auth_bootstrap(): void {}
function ap_auth_current_user(): ?array { throw new RuntimeException('the template passes the page user'); }

function region(string $source, string $start, string $end): string {
    $a = strpos($source, $start); if ($a === false) throw new RuntimeException('missing start ' . substr($start, 0, 60));
    $b = strpos($source, $end, $a); if ($b === false) throw new RuntimeException('missing end ' . substr($end, 0, 60));
    return substr($source, $a + strlen($start), $b - $a - strlen($start));
}
// The two branches the native view replaces; the recovery-code and setup branches before them stay PHP.
$twoFactorTemplate = '<?php if (false): ?>' . region($source,
    "<?php elseif ((\$settingsNativeTwoFactor = ap_settings_native_body('settings_two_factor', !empty(\$vaakIsAdmin))) !== null): ?><?= \$settingsNativeTwoFactor ?>",
    "        <?php endif; ?>\n\n        <div class=\"tweet\" style=\"margin-bottom:1.25rem\" id=\"vaak-push-card\">") . '<?php endif; ?>';
$tokensTemplate = region($source,
    "<?php if ((\$settingsNativeTokens = ap_settings_native_body('settings_tokens', !empty(\$vaakIsAdmin))) !== null): ?><?= \$settingsNativeTokens ?><?php else: ?>",
    "        <?php endif; ?>\n\n      <?php elseif (\$view === 'profile' || \$view === 'atmosphere'): ?>");
check_template_markers();
function check_template_markers(): void {
    global $source;
    if (!str_contains($source, "<?php \$twoFaEnabled = ap_auth_2fa_enabled(\$vaakOwnerId); ?>")) throw new RuntimeException('2FA state is no longer read from ap_auth_2fa_enabled');
    if (!str_contains($source, "<?php \$isCmdrSecurity = (\$vaakActorKey === 'cmdr_nova'); ?>")) throw new RuntimeException('operator rule moved');
}
require $api . '/ap-admin-native.php';

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
const CSRF = 'session<&"token\'';
function ap_auth_csrf_token(): string { return CSRF; }
function at(int $offset): string { return gmdate('c', time() + $offset); }
function schema(): void {
    global $db, $idType;
    $db->exec("DROP TABLE IF EXISTS ap_users; DROP TABLE IF EXISTS oauth_apps; DROP TABLE IF EXISTS oauth_tokens; DROP TABLE IF EXISTS ap_user_2fa;
    CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
    INSERT INTO ap_users VALUES (1,'operator','cmdr_nova',1,NULL),(2,'ordinary','ord',0,NULL),(3,'legacy',NULL,0,NULL),(4,'empty','empty',0,NULL),(6,'second admin','admin2',1,NULL);
    CREATE TABLE oauth_apps(id $idType PRIMARY KEY, client_name TEXT NOT NULL, client_id TEXT NOT NULL UNIQUE, client_secret_hash TEXT NOT NULL, redirect_uris TEXT NOT NULL, scopes TEXT NOT NULL, website TEXT, created_at TEXT NOT NULL);
    INSERT INTO oauth_apps VALUES (1,'Ice <Cubes> & \"co\"','ice','CLIENT_SECRET_DECOY','urn:x','read write','https://ice.example','2026-01-01T00:00:00+00:00'),
      (2,'Tusky','tusky','CLIENT_SECRET_DECOY','urn:x','read','https://tusky.example','2026-01-01T00:00:00+00:00');
    CREATE TABLE oauth_tokens(id $idType PRIMARY KEY, access_token_hash TEXT NOT NULL UNIQUE, client_id TEXT NOT NULL, scopes TEXT NOT NULL, created_at TEXT NOT NULL, revoked_at TEXT, expires_at TEXT, refresh_token_hash TEXT, refresh_expires_at TEXT, user_id $idType);
    CREATE TABLE ap_user_2fa(user_id $idType PRIMARY KEY, secret_enc TEXT NOT NULL DEFAULT '', pending_secret_enc TEXT NOT NULL DEFAULT '', recovery_codes_json TEXT NOT NULL DEFAULT '[]', enabled_at TEXT, updated_at TEXT NOT NULL);
    INSERT INTO ap_user_2fa VALUES (1,'TOTP_SECRET_DECOY','','[\"RECOVERY_DECOY\"]','2026-10-01T00:00:00+00:00','x'),
      (3,'TOTP_SECRET_DECOY','PENDING_SECRET_DECOY','[]',NULL,'x'),(4,' \t\n','','[]','2026-10-01T00:00:00+00:00','x'),(6,'TOTP_SECRET_DECOY','','[]',' ','x')");
    $tokens = [
        [1, 'ice', 'read write <admin>', at(-86400), null, at(86400 * 80), 1],
        [2, 'ice', 'read', at(-86400 * 3), at(-86400), at(86400 * 80), 1],
        [3, 'ice', 'read', at(-86400 * 40), at(-86400 * 30), at(86400 * 50), 1],
        [4, 'gone', 'read write follow', '2025-01-01T00:00:00+00:00', null, null, null],
        [5, 'tusky', 'read', at(-86400 * 100), null, at(-60), 1],
        [6, 'tusky', 'read', at(-3600), null, at(86400 * 90), 2],
        [7, 'tusky', 'read', at(-7200), at(-3600), at(86400 * 90), 2],
        [8, 'ice', 'push', '2026-10-01 00:00:00+00', null, '2099-01-01 00:00:00+00', 1],
        [9, 'ice', '', at(-60), null, '', 1],
        [10, 'ice', 'read', at(-60), null, at(86400), 3],
        [11, 'ice', 'read', at(-60), at(-86400 * 6), at(86400), 6],
        [12, 'ice', 'read', at(-60), null, at(86400), 6],
    ];
    $st = $db->prepare('INSERT INTO oauth_tokens(id,access_token_hash,client_id,scopes,created_at,revoked_at,expires_at,refresh_token_hash,refresh_expires_at,user_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($tokens as [$id, $client, $scopes, $created, $revoked, $expires, $user]) $st->execute([$id, 'TOKEN_HASH_DECOY_' . $id, $client, $scopes, $created, $revoked, $expires, 'REFRESH_HASH_DECOY_' . $id, at(86400 * 180), $user]);
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
function request(string $view, int $owner, bool $admin, string $mode = 'full'): array {
    return ['protocol_version' => 1, 'owner_id' => $owner, 'csrf' => CSRF, 'view' => $view, 'mode' => $mode,
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => 'UTC', 'viewer_admin' => $admin];
}
function render(string $view, int $owner, bool $admin): string {
    global $twoFactorTemplate, $tokensTemplate, $db;
    $vaakUser = $db->query('SELECT * FROM ap_users WHERE id = ' . $owner)->fetch();
    $vaakOwnerId = $owner; $vaakIsAdmin = $admin;
    $vaakActorKey = (string) ($vaakUser['actor_key'] ?? 'cmdr_nova');
    $isCmdrSecurity = ($vaakActorKey === 'cmdr_nova');
    $twoFaEnabled = ap_auth_2fa_enabled($vaakOwnerId);
    ob_start(); eval('?>' . ($view === 'settings_tokens' ? $tokensTemplate : $twoFactorTemplate)); return collapse((string) ob_get_clean());
}
function compare(string $view, int $owner, bool $admin, string $mode, string $label): ?array {
    global $secret;
    $php = render($view, $owner, $admin); [$status, $reply, $body] = native(request($view, $owner, $admin, $mode), $secret);
    check($status === 200, "$label status $status");
    $html = collapse((string) ($reply['html'] ?? ''));
    check($html === $php, "$label html");
    if ($html !== $php) { file_put_contents(sys_get_temp_dir() . "/security-$view-$owner-$mode.php.html", $php); file_put_contents(sys_get_temp_dir() . "/security-$view-$owner-$mode.native.html", $html); }
    check(!preg_match('/DECOY/', $body), "$label no secret in the reply");
    check(is_array($reply) && ap_admin_native_reply($reply, request($view, $owner, $admin, $mode)) === ($reply['html'] ?? null), "$label bridge accepts reply");
    return is_array($reply) ? $reply : null;
}

schema();
// owner => [admin, visible token ids => status, purge button label, 2FA enabled]
$expect = [
    1 => [true, [9 => 'active', 8 => 'active', 5 => 'expired', 4 => 'active', 2 => 'revoked', 1 => 'active'], 'Purge revoked now (1)', true],
    2 => [false, [7 => 'revoked', 6 => 'active'], null, false],
    3 => [false, [10 => 'active'], null, false],
    4 => [false, [], null, false],
    6 => [true, [12 => 'active', 11 => 'revoked'], null, false],
];
foreach ($expect as $owner => [$admin, $tokens, $purge, $enabled]) foreach (['full', 'shell'] as $mode) {
    $label = "tokens owner$owner/$mode";
    $reply = compare('settings_tokens', $owner, $admin, $mode, $label);
    $rows = []; foreach ($tokens as $id => $status) $rows[] = ['id' => $id, 'status' => $status];
    check(($reply['rows'] ?? null) === $rows, "$label rows " . json_encode($reply['rows'] ?? null));
    $html = (string) ($reply['html'] ?? '');
    check($purge === null ? !str_contains($html, 'purge_oauth_tokens') : str_contains($html, $purge . ' </button>'), "$label operator purge button");
    check($tokens ? substr_count($html, 'revoke_oauth_token') === count(array_filter($tokens, fn($s) => $s === 'active')) : str_contains($html, 'No tokens yet'), "$label revoke forms");
    if ($owner === 1 && is_array($reply)) {
        $sent = request('settings_tokens', 1, true, $mode);
        check(str_contains($html, 'unknown app') && str_contains($html, 'expires n/a') && str_contains($html, 'Ice &lt;Cubes&gt; &amp; &quot;co&quot;'), "$label names and missing dates");
        $t = $reply; $t['rows'][0]['status'] = 'pending'; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects unknown status");
        $t = $reply; $t['rows'][0]['id'] = '9'; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects string id");
        $t = $reply; $t['rows'][0]['client_name'] = 'x'; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects extra row key");
    }
    $label = "2fa owner$owner/$mode";
    $reply = compare('settings_two_factor', $owner, $admin, $mode, $label);
    check(($reply['rows'] ?? null) === [['enabled' => $enabled, 'panel' => 'two_factor']], "$label rows " . json_encode($reply['rows'] ?? null));
    check(str_contains((string) ($reply['html'] ?? ''), $enabled ? '2fa_disable' : '2fa_begin_setup'), "$label branch");
    check(str_contains((string) ($reply['html'] ?? ''), 'name="csrf" value="session&lt;&amp;&quot;token&#039;"'), "$label csrf escaped");
    if ($owner === 1 && is_array($reply)) {
        $sent = request('settings_two_factor', 1, true, $mode);
        $t = $reply; $t['rows'][0]['enabled'] = 1; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects non-boolean state");
        $t = $reply; $t['rows'][0]['panel'] = 'ai_alt_text'; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects other panel");
        $t = $reply; $t['rows'][] = $t['rows'][0]; $t['source_count'] = 2; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects two rows");
        $t = $reply; $t['request_binding']['viewer_admin'] = false; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects other role binding");
    }
}

// Without a cmdr_nova row PHP falls back to user 1 as the legacy owner, and nobody is the operator.
schema(); $db->exec("UPDATE ap_users SET actor_key='renamed' WHERE id=1");
$reply = compare('settings_tokens', 1, true, 'full', 'no cmdr_nova row');
check(array_column($reply['rows'] ?? [], 'id') === [9, 8, 5, 4, 2, 1] && !str_contains((string) ($reply['html'] ?? ''), 'purge_oauth_tokens'), 'no cmdr_nova row keeps legacy rows, drops purge');
// A NULL actor_key reads as cmdr_nova in PHP: an admin with one gets the purge button.
schema(); $db->exec("UPDATE ap_users SET actor_key=NULL WHERE id=6");
$reply = compare('settings_tokens', 6, true, 'full', 'null actor key admin');
check(str_contains((string) ($reply['html'] ?? ''), 'Purge revoked now (1)'), 'null actor key admin purge button');
// The 40-row cap.
schema(); $st = $db->prepare("INSERT INTO oauth_tokens(id,access_token_hash,client_id,scopes,created_at,expires_at,user_id) VALUES (?,?,'tusky','read',?,?,2)");
for ($i = 100; $i < 150; $i++) $st->execute([$i, 'TOKEN_HASH_DECOY_' . $i, at(-60), at(86400)]);
$reply = compare('settings_tokens', 2, false, 'full', 'cap');
check(count($reply['rows'] ?? []) === 40 && ($reply['rows'][0]['id'] ?? null) === 149, 'cap keeps the 40 newest');

// Fallbacks: text PHP would repair, dates strtotime reads by PHP rules, and a missing 2FA table.
foreach ([
    'mojibake app name' => ['settings_tokens', "UPDATE oauth_apps SET client_name='CafÃ©' WHERE client_id='ice'"],
    'mojibake scopes' => ['settings_tokens', "UPDATE oauth_tokens SET scopes='CafÃ©' WHERE id=1"],
    'relative expiry' => ['settings_tokens', "UPDATE oauth_tokens SET expires_at='next week' WHERE id=1"],
    'zoneless expiry' => ['settings_tokens', "UPDATE oauth_tokens SET expires_at='2027-01-01 00:00:00' WHERE id=1"],
    'no 2FA table' => ['settings_two_factor', 'DROP TABLE ap_user_2fa'],
    'no tokens table' => ['settings_tokens', 'DROP TABLE oauth_tokens'],
] as $name => [$view, $sql]) {
    schema(); $db->exec($sql);
    if ($view === 'settings_two_factor') check(str_contains(render($view, 1, true), '2fa_begin_setup'), "$name PHP section rendered");
    elseif ($name !== 'no tokens table') check(str_contains(render($view, 1, true), 'Your OAuth tokens'), "$name PHP section rendered");
    [$status] = native(request($view, 1, true), $secret); check($status === 422, "$name -> $status");
}
schema();
foreach (['settings_tokens', 'settings_two_factor'] as $view) {
    [$status] = native(request($view, 1, false), $secret); check($status === 422, "$view role mismatch -> $status");
    [$status] = native(request($view, 9, false), $secret); check($status === 422, "$view unknown account -> $status");
    $bad = request($view, 1, true); unset($bad['viewer_admin']); [$status] = native($bad, $secret); check($status === 400, "$view no role -> $status");
    $bad = request($view, 1, true); $bad['offset'] = 1; [$status] = native($bad, $secret); check($status === 400, "$view offset -> $status");
}
$db->exec("UPDATE ap_users SET disabled_at='2026-10-19 00:00:00' WHERE id=2");
[$status] = native(request('settings_tokens', 2, false), $secret); check($status === 422, "disabled account -> $status");

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
