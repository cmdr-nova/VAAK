<?php
/**
 * Admin AP stats native read review (sandbox only).
 *
 * Runs the genuine `stats` data block and template of ap-admin.php (with the genuine
 * ap_db_execute_retry and h) against a seeded PostgreSQL fixture, asks a running
 * worker for the same page over /internal/admin/read, and compares the HTML after
 * collapsing whitespace runs. Checks firehose windows, breakdown ordering and the
 * 12-row limit, per-user posts, follows and mentions with case and trailing-slash
 * keys, flags, and the fallback cases. Never point it at production: it drops and
 * recreates its tables.
 *
 * Usage: php admin-stats-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET
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
foreach (['h'] as $fn) eval(declaration($source, $fn));
foreach (['ap_fix_utf8', 'ap_db_execute_retry', 'ap_db_is_locked_exception'] as $fn) eval(declaration($dbSource, $fn));

function region(string $source, string $start, string $end): string {
    $a = strpos($source, $start); if ($a === false) throw new RuntimeException('missing start ' . substr($start, 0, 60));
    $b = strpos($source, $end, $a); if ($b === false) throw new RuntimeException('missing end ' . substr($end, 0, 60));
    return substr($source, $a + strlen($start), $b - $a - strlen($start));
}
$init = region($source, "// Stats-only event COUNTs (were previously paid on every full-page nav click).\n", "\$queueHealthRows = [];");
$sinceInit = '$since24' . region($source, "\$db = ap_db();\n\$since24", "\n\n\$tlLimit");
$compute = 'if (true) {' . region($source, "if (\$view === 'stats' && \$statsNativeBody === null) {", "error_log('[ap-admin] instance stats: ' . \$e->getMessage());\n    }\n}") . "error_log('[ap-admin] instance stats: ' . \$e->getMessage());\n    }\n}";
$template = region($source, "<?php if (\$statsNativeBody !== null): ?><?= \$statsNativeBody ?><?php else: ?>", "        <?php endif; ?>\n\n      <?php elseif (\$view === 'about'): ?>");
require $api . '/ap-admin-native.php';

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
function at(int $offset): string { return gmdate('c', time() + $offset); }
function schema(): void {
    global $db, $idType;
    $db->exec("DROP TABLE IF EXISTS ap_users; DROP TABLE IF EXISTS events; DROP TABLE IF EXISTS outbox_notes; DROP TABLE IF EXISTS followers; DROP TABLE IF EXISTS following; DROP TABLE IF EXISTS mentions;
    CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
    INSERT INTO ap_users VALUES (1,'operator','cmdr_nova',1,NULL),(2,'Ordinary <&>','Ord',0,NULL),(3,'gone','gone',0,'2026-10-01T00:00:00+00:00'),(4,'keyless',NULL,0,NULL),(5,NULL,NULL,1,NULL);
    CREATE TABLE events(id $idType PRIMARY KEY, created_at TEXT NOT NULL, type TEXT NOT NULL, actor_id TEXT, object_id TEXT, target_actor TEXT, host TEXT, bytes INTEGER DEFAULT 0, action_taken TEXT, summary TEXT);
    CREATE TABLE outbox_notes(id TEXT PRIMARY KEY, content TEXT);
    CREATE TABLE followers(id $idType PRIMARY KEY, owner_actor_id TEXT, actor_id TEXT);
    CREATE TABLE following(id $idType PRIMARY KEY, owner_actor_id TEXT, actor_id TEXT);
    CREATE TABLE mentions(id $idType PRIMARY KEY, owner_user_id $idType, deleted_at TEXT)");
    $ev = $db->prepare('INSERT INTO events(id,created_at,type,host,action_taken) VALUES (?,?,?,?,?)'); $id = 0;
    // 24h: 15 types (ties at the 12-row cut), NULL action, NULL host; 7d and older noise.
    foreach (range(1, 15) as $t) foreach (range(1, $t <= 3 ? 4 : ($t <= 9 ? 2 : 1)) as $n) $ev->execute([++$id, at(-3600 * $t), 'Type' . chr(64 + $t), $t % 4 === 0 ? null : 'host' . ($t % 5) . '.example', $t % 3 === 0 ? null : 'act' . ($t % 2)]);
    foreach (range(1, 6) as $n) $ev->execute([++$id, at(-86400 * 3), 'Old', 'old.example', 'stored']);
    $ev->execute([++$id, at(-86400 * 9), 'Ancient', 'ancient.example', 'stored']);
    $st = $db->prepare('INSERT INTO outbox_notes VALUES (?,?)');
    foreach (['https://mkultra.monster/users/cmdr_nova/statuses/1', 'https://mkultra.monster/users/cmdr_nova/statuses/2', 'https://mkultra.monster/users/ord/statuses/1', 'https://mkultra.monster/users/keyless/statuses/9', 'https://elsewhere.example/users/cmdr_nova/1'] as $n) $st->execute([$n, 'x']);
    $st = $db->prepare('INSERT INTO followers VALUES (?,?,?)');
    foreach ([[1, 'https://mkultra.monster/users/cmdr_nova'], [2, 'https://mkultra.monster/users/cmdr_nova'], [3, 'https://mkultra.monster/users/Ord'], [4, null]] as [$i, $o]) $st->execute([$i, $o, 'https://r.example/u/' . $i]);
    $st = $db->prepare('INSERT INTO following VALUES (?,?,?)');
    foreach ([[1, 'https://mkultra.monster/users/cmdr_nova/'], [2, 'https://mkultra.monster/users/keyless']] as [$i, $o]) $st->execute([$i, $o, 'https://r.example/u/' . $i]);
    $st = $db->prepare('INSERT INTO mentions VALUES (?,?,?)');
    foreach ([[1, 1, null], [2, 1, null], [3, 1, '2026-10-01'], [4, 2, null], [5, null, null]] as $row) $st->execute($row);
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
    return ['protocol_version' => 1, 'owner_id' => $owner, 'csrf' => 'session', 'view' => 'stats', 'mode' => $mode,
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => date_default_timezone_get()];
}
function render(): string {
    global $init, $sinceInit, $compute, $template;
    $view = 'stats'; $statsNativeBody = null;
    eval($sinceInit); eval($init); eval($compute);
    ob_start(); eval('?>' . $template); return collapse((string) ob_get_clean());
}
function compare(int $owner, string $mode, string $label): ?array {
    global $secret;
    $php = render(); [$status, $reply] = native(request($owner, $mode), $secret);
    check($status === 200, "$label status $status");
    $html = collapse((string) ($reply['html'] ?? ''));
    check($html === $php, "$label html");
    if ($html !== $php) { file_put_contents(sys_get_temp_dir() . "/stats-$owner-$mode.php.html", $php); file_put_contents(sys_get_temp_dir() . "/stats-$owner-$mode.native.html", $html); }
    check(is_array($reply) && ap_admin_native_reply($reply, request($owner, $mode)) === ($reply['html'] ?? null), "$label bridge accepts reply");
    return is_array($reply) ? $reply : null;
}

schema();
foreach ([1, 5] as $owner) foreach (['full', 'shell'] as $mode) {
    $label = "admin$owner/$mode";
    $reply = compare($owner, $mode, $label);
    $rows = $reply['rows'] ?? [];
    check(array_column($rows, 'id') === [1, 2, 3, 4, 5], "$label user order");
    $byId = array_column($rows, null, 'id');
    check(($byId[1]['posts'] ?? null) === 2 && ($byId[1]['followers'] ?? null) === 2 && ($byId[1]['following'] ?? null) === 1 && ($byId[1]['mentions'] ?? null) === 2, "$label operator counts");
    check(($byId[2]['posts'] ?? null) === 1 && ($byId[2]['followers'] ?? null) === 1 && ($byId[2]['mentions'] ?? null) === 1, "$label case-folded posts, exact follower key");
    check(($byId[4]['posts'] ?? null) === 1 && ($byId[4]['following'] ?? null) === 1 && ($byId[4]['username'] ?? null) === 'keyless', "$label username as key");
    check(($byId[5]['username'] ?? null) === '' && ($byId[5]['followers'] ?? null) === 1 && ($byId[5]['mentions'] ?? null) === 0, "$label empty key reads the NULL-owner follower count");
    check(($byId[3]['disabled'] ?? null) === true && ($byId[1]['is_admin'] ?? null) === true, "$label flags");
    $html = (string) ($reply['html'] ?? '');
    check(substr_count($html, 'class="bar-row"') === 12 + 3 + 5, "$label breakdown rows");
    check(str_contains($html, '@Ordinary &lt;&amp;&gt;') && str_contains($html, 'admin · disabled') === false && str_contains($html, '>Firehose events (7d)'), "$label escaped names");
    if ($owner === 1 && is_array($reply)) {
        $sent = request(1, $mode);
        $t = $reply; $t['rows'][0]['posts'] = '2'; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects string count");
        $t = $reply; $t['rows'][0]['is_admin'] = 1; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects int flag");
        $t = $reply; $t['rows'][0]['email'] = 'x'; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects extra key");
        $t = $reply; $t['rows'] = array_reverse($t['rows']); check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects unordered ids");
        $t = $reply; $t['request_binding']['viewer_admin'] = true; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects extra binding");
    }
}
// Empty instance pieces: no events, no users.
schema(); $db->exec('DELETE FROM events');
$reply = compare(1, 'full', 'no events');
check(str_contains((string) ($reply['html'] ?? ''), 'No events in the last 24h.'), 'no events empty state');

// Fallbacks: duplicate PHP array keys (unordered winner), text PHP would repair, a schema without
// mentions.deleted_at (PHP retries without it), and admin-only access.
foreach ([
    'trailing-slash follower duplicate' => "INSERT INTO followers VALUES (9,'https://mkultra.monster/users/cmdr_nova/','x')",
    'case duplicate posts' => "INSERT INTO outbox_notes VALUES ('https://mkultra.monster/users/CMDR_NOVA/statuses/3','x')",
    'NULL and zero mention owners' => "INSERT INTO mentions VALUES (9,0,NULL)",
    'mojibake username' => "UPDATE ap_users SET username='CafÃ©' WHERE id=2",
    'mojibake host' => "UPDATE events SET host='CafÃ©' WHERE id=1",
    'no mentions.deleted_at' => 'ALTER TABLE mentions DROP COLUMN deleted_at',
] as $name => $sql) {
    schema(); $db->exec($sql);
    check(str_contains(render(), 'Local users'), "$name PHP page rendered");
    [$status] = native(request(1), $secret); check($status === 422, "$name -> $status");
}
schema();
[$status] = native(request(2), $secret); check($status === 422, "ordinary account -> $status");
[$status] = native(request(3), $secret); check($status === 422, "disabled account -> $status");
[$status] = native(request(9), $secret); check($status === 422, "unknown account -> $status");
$bad = request(1); $bad['offset'] = 1; [$status] = native($bad, $secret); check($status === 400, "offset -> $status");
$bad = request(1); $bad['viewer_admin'] = true; [$status] = native($bad, $secret); check($status === 400, "role selector -> $status");

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
