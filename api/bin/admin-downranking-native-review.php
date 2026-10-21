<?php
/**
 * Admin Home downranking vocabulary native read review (sandbox only).
 *
 * Runs the genuine vocabulary query of the `downranking` view and its phrase list
 * template from ap-admin.php (with the genuine relative_time and h) against a
 * seeded PostgreSQL fixture, asks a running worker for the same list over
 * /internal/admin/read, and compares the HTML after collapsing whitespace runs.
 * Checks escaping, ordering by category then phrase, past and future times, the
 * empty state, the bridge validator and the fallback cases. Never point it at
 * production: it drops and recreates its tables.
 *
 * Usage: php admin-downranking-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET
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
foreach (['h', 'relative_time'] as $fn) eval(declaration($source, $fn));
foreach (['ap_fix_utf8'] as $fn) eval(declaration($dbSource, $fn));
function ap_auth_csrf_token(): string { return 'session'; }

function region(string $source, string $start, string $end): string {
    $a = strpos($source, $start); if ($a === false) throw new RuntimeException('missing start ' . substr($start, 0, 60));
    $b = strpos($source, $end, $a); if ($b === false) throw new RuntimeException('missing end ' . substr($end, 0, 60));
    return substr($source, $a + strlen($start), $b - $a - strlen($start));
}
$compute = 'try {' . region($source, "            if (\$downrankingNativeBody === null) try {", "\n          ?>\n          <div class=\"meta\" style=\"margin-bottom:1rem\">\n            Add phrases");
$template = '<?php if (!$downrankTerms): ?>' . region($source, "<?php if (\$downrankingNativeBody !== null): ?><?= \$downrankingNativeBody ?><?php elseif (!\$downrankTerms): ?>", "        <?php endif; ?>\n\n      <?php elseif (\$view === 'policies'): ?>");
require $api . '/ap-admin-native.php';

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
function at(int $offset): string { return gmdate('c', time() + $offset); }
function schema(bool $seed = true): void {
    global $db, $idType;
    $db->exec('DROP TABLE IF EXISTS ap_users; DROP TABLE IF EXISTS ap_home_downrank_terms');
    $db->exec("CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
    INSERT INTO ap_users VALUES (1,'operator','cmdr_nova',1,NULL),(2,'ordinary','ord',0,NULL),(3,'retired','ret',1,'2026-01-01T00:00:00+00:00');
    CREATE TABLE ap_home_downrank_terms(id $idType PRIMARY KEY, phrase TEXT NOT NULL UNIQUE, category TEXT NOT NULL DEFAULT 'custom', enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    if (!$seed) return;
    $big = $idType === 'BIGINT' ? 9000000000 : 0;
    $st = $db->prepare('INSERT INTO ap_home_downrank_terms VALUES (?,?,?,?,?,?)');
    foreach ([
        [5, 'zeta phrase', 'custom', 1, at(-30)],
        [2, 'Alpha <b>&"quote"\'', 'custom', 1, at(-7200)],
        [9, 'alpha lower', 'custom', 0, at(-90000)],
        [3, 'slur one', 'protected_class', 1, at(-3 * 86400)],
        [7, 'cat call 💬 ü', 'sexualized_harassment', 1, at(-400)],
        [4, 'future dated', 'protected_class', 1, at(120)],
        [8, 'odd <category>', 'x"y<z>', 1, '2026-10-19 12:00:00'],
    ] as [$id, $phrase, $category, $enabled, $created]) $st->execute([$id + $big, $phrase, $category, $enabled, $created, $created]);
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
function request(int $owner, string $mode = 'full'): array {
    return ['protocol_version' => 1, 'owner_id' => $owner, 'csrf' => 'session', 'view' => 'downranking', 'mode' => $mode,
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => date_default_timezone_get()];
}
function render(): string {
    global $compute, $template;
    $downrankTerms = []; $error = null;
    eval($compute);
    ob_start(); eval('?>' . $template); return collapse((string) ob_get_clean());
}
function compare(string $mode, string $label): ?array {
    global $secret;
    $php = render(); [$status, $reply] = native(request(1, $mode), $secret);
    check($status === 200, "$label status $status");
    $html = collapse((string) ($reply['html'] ?? ''));
    check($html === $php, "$label html");
    if ($html !== $php) { file_put_contents(sys_get_temp_dir() . "/downranking-$mode.php.html", $php); file_put_contents(sys_get_temp_dir() . "/downranking-$mode.native.html", $html); }
    check(is_array($reply) && ap_admin_native_reply($reply, request(1, $mode)) === ($reply['html'] ?? null), "$label bridge accepts reply");
    return is_array($reply) ? $reply : null;
}

schema();
foreach (['full', 'shell'] as $mode) {
    $reply = compare($mode, "terms/$mode");
    $html = (string) ($reply['html'] ?? '');
    check(count($reply['rows'] ?? []) === 7 && ($reply['source_count'] ?? null) === 7, "terms/$mode seven rows");
    check(str_contains($html, 'Alpha &lt;b&gt;&amp;&quot;quote&quot;&#039;') && str_contains($html, 'x&quot;y&lt;z&gt;'), "terms/$mode escaped");
    check(strpos($html, 'Alpha') < strpos($html, 'zeta') && strpos($html, 'slur one') < strpos($html, 'cat call'), "terms/$mode category then phrase order");
    check(str_contains($html, 'name="csrf" value="session"'), "terms/$mode csrf");
    if ($mode === 'full' && is_array($reply)) {
        $sent = request(1);
        $t = $reply; $t['rows'][0]['id'] = '1'; check(ap_admin_native_reply($t, $sent) === null, 'bridge rejects string id');
        $t = $reply; $t['rows'][1] = $t['rows'][0]; check(ap_admin_native_reply($t, $sent) === null, 'bridge rejects duplicate id');
        $t = $reply; $t['rows'][0]['phrase'] = 'x'; check(ap_admin_native_reply($t, $sent) === null, 'bridge rejects extra key');
        $t = $reply; $t['rows'][0]['id'] = 0; check(ap_admin_native_reply($t, $sent) === null, 'bridge rejects zero id');
        $t = $reply; array_pop($t['rows']); check(ap_admin_native_reply($t, $sent) === null, 'bridge rejects a short count');
        $t = $reply; $t['request_binding']['offset'] = 1; check(ap_admin_native_reply($t, $sent) === null, 'bridge rejects binding');
    }
}
schema(false);
$reply = compare('full', 'empty');
check(str_contains((string) ($reply['html'] ?? ''), 'No admin-managed phrases yet.'), 'empty state');

// Fallbacks: a missing table (PHP's migration notice), a date only strtotime reads, text PHP would repair, the row bound, admin only.
foreach ([
    'missing table' => 'DROP TABLE ap_home_downrank_terms',
    'relative date' => "UPDATE ap_home_downrank_terms SET created_at='yesterday' WHERE phrase='zeta phrase'",
    'mojibake phrase' => "UPDATE ap_home_downrank_terms SET phrase='CafÃ©' WHERE phrase='zeta phrase'",
    'row bound' => "INSERT INTO ap_home_downrank_terms SELECT 100000 + g, 'bulk ' || g, 'custom', 1, '2026-10-19T00:00:00+00:00', '2026-10-19T00:00:00+00:00' FROM generate_series(1, 1000) g",
] as $name => $sql) {
    schema(); $db->exec($sql);
    [$status] = native(request(1), $secret); check($status === 422, "$name -> $status");
}
schema();
[$status] = native(request(2), $secret); check($status === 422, "ordinary account -> $status");
[$status] = native(request(3), $secret); check($status === 422, "disabled admin -> $status");
[$status] = native(request(9), $secret); check($status === 422, "unknown account -> $status");
$bad = request(1); $bad['offset'] = 1; [$status] = native($bad, $secret); check($status === 400, "offset -> $status");
$bad = request(1); $bad['filter'] = 'open'; [$status] = native($bad, $secret); check($status === 400, "filter selector -> $status");

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
