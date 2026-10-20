<?php
/**
 * Settings Import / Export alias list and Move section native read review (sandbox only).
 *
 * Renders the genuine alias list and Move followers regions of the import_export view
 * in ap-admin.php, with the genuine vaak_actor_key, ap_alias_list, ap_move_get,
 * ap_move_active_target and ap_move_cooldown_remaining_seconds, against a seeded
 * PostgreSQL fixture, asks a running worker for the same regions over
 * /internal/admin/read, and compares the HTML after collapsing whitespace runs.
 * Checks actor-key scoping and normalisation, alias display fallbacks, verified
 * flags, active, cleared and cooling-down moves, and the fallback cases. Never point
 * it at production: it drops and recreates its tables.
 *
 * Usage: php settings-migration-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
[$self, $dsn, $idType, $url, $secret] = $argv + [null, null, 'INTEGER', null, null];
if (!$dsn || !in_array($idType, ['INTEGER', 'BIGINT'], true) || !$url || strlen((string) $secret) < 32) {
    fwrite(STDERR, "usage: dsn INTEGER|BIGINT url secret\n"); exit(2);
}
date_default_timezone_set('UTC'); // the envelope only admits UTC, the zone PHP reads zoneless dates in
if (!str_contains($dsn, 'fixture')) { fwrite(STDERR, "refusing a database without 'fixture' in its name\n"); exit(2); }

$api = dirname(__DIR__);
$source = (string) file_get_contents($api . '/ap-admin.php');
$dbSource = (string) file_get_contents($api . '/ap-db.php');
$ieSource = (string) file_get_contents($api . '/ap-import-export.php');
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
foreach (['h', 'vaak_actor_key'] as $fn) eval(declaration($source, $fn));
foreach (['ap_fix_utf8'] as $fn) eval(declaration($dbSource, $fn));
foreach (['ap_alias_list', 'ap_move_get', 'ap_move_active_target', 'ap_move_cooldown_remaining_seconds'] as $fn) eval(declaration($ieSource, $fn));
if (!preg_match('/^const AP_IE_MOVE_COOLDOWN_DAYS = (\d+);$/m', $ieSource, $m)) throw new RuntimeException('cooldown constant moved');
define('AP_IE_MOVE_COOLDOWN_DAYS', (int) $m[1]);
if (AP_IE_MOVE_COOLDOWN_DAYS !== 30) throw new RuntimeException('the worker copies a 30 day cooldown');
function ap_auth_current_user(): ?array { throw new RuntimeException('the template passes the page user'); }

function region(string $source, string $start, string $end): string {
    $a = strpos($source, $start); if ($a === false) throw new RuntimeException('missing start ' . substr($start, 0, 60));
    $b = strpos($source, $end, $a); if ($b === false) throw new RuntimeException('missing end ' . substr($end, 0, 60));
    return substr($source, $a + strlen($start), $b - $a - strlen($start));
}
$setup = region($source, "<?php elseif (\$view === 'import_export'): ?>\n        <?php", "?>\n        <div class=\"meta\" style=\"margin-bottom:1rem\">\n          Mastodon-compatible");
$setup = str_replace('$followStatus = ap_ie_follow_status();', '', $setup); // a state file, not part of either region
$aliasTemplate = '<?php if (!$aliases): ?>' . region($source,
    "<?php if ((\$settingsNativeAliases = ap_settings_native_body('settings_aliases', !empty(\$vaakIsAdmin))) !== null): ?><?= \$settingsNativeAliases ?><?php elseif (!\$aliases): ?>",
    "\n\n        <h3 style=\"font-size:.95rem;color:var(--muted);margin:1.25rem 0 .5rem\">Migration — Move followers</h3>");
$moveTemplate = '<?php if ($activeMove): ?>' . region($source,
    "<?php if ((\$settingsNativeMove = ap_settings_native_body('settings_move', !empty(\$vaakIsAdmin))) !== null): ?><?= \$settingsNativeMove ?><?php elseif (\$activeMove): ?>",
    "\n\n        <form class=\"composer\" method=\"post\" action=\"?view=import_export\" style=\"margin-bottom:1rem\">\n          <input type=\"hidden\" name=\"action\" value=\"move_prepare_target\">");
$identity = region($source, "} else {\n    \$vaakActorKey = (string) (\$vaakUser['actor_key'] ?? 'cmdr_nova');", "\n    \$vaakActorId");
if (trim($identity) !== '') throw new RuntimeException('actor key rule moved');
require $api . '/ap-admin-native.php';

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
const CSRF = 'session<&"token\'';
function ap_auth_csrf_token(): string { return CSRF; }
function at(int $offset): string { return gmdate('c', time() + $offset); }
function schema(): void {
    global $db, $idType;
    $db->exec("DROP TABLE IF EXISTS ap_users; DROP TABLE IF EXISTS account_aliases; DROP TABLE IF EXISTS ap_account_move;
    CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
    INSERT INTO ap_users VALUES (1,'operator','cmdr_nova',1,NULL),(2,'ordinary','ord',0,NULL),(3,'legacy',NULL,0,NULL),(4,'mixed',' Mixed.Case ',0,NULL),(5,'symbols','\$\$\$',0,NULL),(6,'nomove','nomove',1,NULL);
    CREATE TABLE account_aliases(id $idType PRIMARY KEY, actor_key TEXT NOT NULL DEFAULT 'cmdr_nova', alias_actor_id TEXT NOT NULL, alias_acct TEXT, direction TEXT NOT NULL, verified_at TEXT, created_at TEXT NOT NULL, UNIQUE(actor_key, alias_actor_id, direction));
    CREATE TABLE ap_account_move(actor_key TEXT PRIMARY KEY, moved_to_actor_id TEXT, moved_to_acct TEXT, moved_at TEXT, cleared_at TEXT)");
    $st = $db->prepare('INSERT INTO account_aliases VALUES (?,?,?,?,?,?,?)');
    foreach ([
        [1, 'cmdr_nova', 'https://old.example/users/nova', '@nova@old.example', 'moved_from', '2026-10-01T00:00:00+00:00', '2026-09-01T00:00:00+00:00'],
        [2, 'cmdr_nova', 'https://alt.example/@me?a=1&b="2"', '', 'aka', null, '2026-09-03T00:00:00+00:00'],
        [3, 'cmdr_nova', 'https://zero.example/u/0', '0', 'aka', '0', '2026-09-02T00:00:00+00:00'],
        [4, 'cmdr_nova', 'https://new.example/users/x', "<b>x</b>&'", 'moved_to', '', '2026-08-01T00:00:00+00:00'],
        [5, 'ord', 'https://ord.example/users/o', null, 'aka', '2026-10-02 00:00:00+00', '2026-09-01T00:00:00+00:00'],
        [6, 'mixedcase', 'https://mixed.example/users/m', '@m@mixed.example', 'aka', null, '2026-09-01T00:00:00+00:00'],
        [7, 'Mixed.Case', 'https://raw.example/users/m', '@raw@raw.example', 'aka', null, '2026-09-01T00:00:00+00:00'],
    ] as $row) $st->execute($row);
    $st = $db->prepare('INSERT INTO ap_account_move VALUES (?,?,?,?,?)');
    foreach ([
        ['cmdr_nova', 'https://new.example/users/x//', '@x@new.example', at(-86400 * 3), null],
        ['ord', 'https://ord-new.example/users/o', null, at(-86400 * 5 - 10), at(-86400)],
        ['mixedcase', '', null, at(-86400 * 40), null],
    ] as $row) $st->execute($row);
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
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => date_default_timezone_get(), 'viewer_admin' => $admin];
}
function render(string $view, int $owner): string {
    global $setup, $aliasTemplate, $moveTemplate, $db;
    $vaakUser = $db->query('SELECT * FROM ap_users WHERE id = ' . $owner)->fetch();
    $vaakActorKey = (string) ($vaakUser['actor_key'] ?? 'cmdr_nova');
    $GLOBALS['vaak_actor_key'] = $vaakActorKey; $GLOBALS['vaak_user'] = $vaakUser;
    eval($setup);
    ob_start(); eval('?>' . ($view === 'settings_aliases' ? $aliasTemplate : $moveTemplate)); return collapse((string) ob_get_clean());
}
function compare(string $view, int $owner, bool $admin, string $mode, string $label): ?array {
    global $secret;
    $php = render($view, $owner); [$status, $reply] = native(request($view, $owner, $admin, $mode), $secret);
    check($status === 200, "$label status $status");
    $html = collapse((string) ($reply['html'] ?? ''));
    check($html === $php, "$label html");
    if ($html !== $php) { file_put_contents(sys_get_temp_dir() . "/migration-$view-$owner-$mode.php.html", $php); file_put_contents(sys_get_temp_dir() . "/migration-$view-$owner-$mode.native.html", $html); }
    check(is_array($reply) && ap_admin_native_reply($reply, request($view, $owner, $admin, $mode)) === ($reply['html'] ?? null), "$label bridge accepts reply");
    return is_array($reply) ? $reply : null;
}

schema();
// owner => [admin, alias rows, move state, text the Move section must show]
$expect = [
    1 => [true, [[2, false], [3, false], [1, true], [4, false]], 'active', '(@x@new.example)'],
    2 => [false, [[5, true]], 'none', 'Cooldown remaining ~25 days'],
    3 => [false, [[2, false], [3, false], [1, true], [4, false]], 'active', 'Clear movedTo'],
    4 => [false, [[6, false]], 'none', 'Move out'],
    6 => [true, [], 'none', 'Move out'],
];
foreach ($expect as $owner => [$admin, $aliases, $move, $text]) foreach (['full', 'shell'] as $mode) {
    $label = "aliases owner$owner/$mode";
    $reply = compare('settings_aliases', $owner, $admin, $mode, $label);
    check(($reply['rows'] ?? null) === array_map(fn($a) => ['id' => $a[0], 'verified' => $a[1]], $aliases), "$label rows " . json_encode($reply['rows'] ?? null));
    if (!$aliases) check(str_contains((string) ($reply['html'] ?? ''), 'No aliases yet.'), "$label empty state");
    if ($owner === 1 && is_array($reply)) {
        $html = (string) ($reply['html'] ?? '');
        check(str_contains($html, '&lt;b&gt;x&lt;/b&gt;&amp;&#039;') && str_contains($html, '>https://zero.example/u/0</span>'), "$label display fallbacks escaped");
        $sent = request('settings_aliases', 1, true, $mode);
        $t = $reply; $t['rows'][0]['verified'] = 'no'; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects non-boolean verified");
        $t = $reply; $t['rows'][0]['alias_actor_id'] = 'x'; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects extra row key");
        $t = $reply; $t['rows'][0]['id'] = 0; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects bad id");
    }
    $label = "move owner$owner/$mode";
    $reply = compare('settings_move', $owner, $admin, $mode, $label);
    check(($reply['rows'] ?? null) === [['panel' => 'move', 'state' => $move]], "$label rows " . json_encode($reply['rows'] ?? null));
    check(str_contains((string) ($reply['html'] ?? ''), $text), "$label shows $text");
    if ($owner === 1 && is_array($reply)) {
        $sent = request('settings_move', 1, true, $mode);
        check(str_contains((string) $reply['html'], '→ https://new.example/users/x '), "$label trailing slashes trimmed");
        $t = $reply; $t['rows'][0]['state'] = 'pending'; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects unknown state");
        $t = $reply; $t['rows'][0]['panel'] = 'wow'; $t['rows'][0]['state'] = 'linked'; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects other panel");
        $t = $reply; $t['rows'][] = $t['rows'][0]; $t['source_count'] = 2; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects two rows");
        $t = $reply; $t['request_binding']['viewer_admin'] = false; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects other role binding");
    }
}

// Cooldown edge cases PHP reads the same way: zoneless and Postgres dates, a '0' date, an active move without acct.
foreach ([
    'zoneless moved_at' => ["UPDATE ap_account_move SET moved_at='" . gmdate('Y-m-d H:i:s', time() - 86400 * 2) . "' WHERE actor_key='ord'", 2, 'Cooldown remaining ~28 days'],
    'postgres moved_at' => ["UPDATE ap_account_move SET moved_at='" . gmdate('Y-m-d H:i:s', time() - 86400 * 29 - 3600) . "+00' WHERE actor_key='ord'", 2, 'Cooldown remaining ~1 days'],
    'zero moved_at' => ["UPDATE ap_account_move SET moved_at='0' WHERE actor_key='ord'", 2, 'Move out'],
    'active without acct' => ["UPDATE ap_account_move SET moved_to_acct=NULL, moved_at=NULL WHERE actor_key='cmdr_nova'", 1, 'Since </div>'],
    'cleared as zero' => ["UPDATE ap_account_move SET cleared_at='0' WHERE actor_key='cmdr_nova'", 1, 'Clear movedTo'],
] as $name => [$sql, $owner, $text]) {
    schema(); $db->exec($sql);
    $reply = compare('settings_move', $owner, $owner === 1, 'full', $name);
    check(str_contains(collapse((string) ($reply['html'] ?? '')), $text), "$name shows $text");
}
schema(); $db->exec("UPDATE ap_account_move SET moved_at='" . gmdate('Y-m-d H:i:s', time() - 86400 * 2) . "' WHERE actor_key='ord'");
$reply = compare('settings_move', 2, false, 'full', 'cooldown check');
check(!str_contains(render('settings_move', 2), 'Cooldown remaining ~0'), 'cooldown never shows zero');

// Fallbacks: an actor key PHP must take other routes for, text PHP would repair, dates only PHP can read,
// equal sort keys PHP leaves in plan order, and missing tables (PHP shows the empty state).
foreach ([
    'empty actor key' => ['settings_aliases', '', 5],
    'mojibake alias' => ['settings_aliases', "UPDATE account_aliases SET alias_acct='CafÃ©' WHERE id=1", 1],
    'alias order tie' => ['settings_aliases', "UPDATE account_aliases SET created_at='2026-09-03T00:00:00+00:00' WHERE id=3", 1],
    'no alias table' => ['settings_aliases', 'DROP TABLE account_aliases', 1],
    'relative moved_at' => ['settings_move', "UPDATE ap_account_move SET moved_at='yesterday' WHERE actor_key='ord'", 2],
    'mojibake move' => ['settings_move', "UPDATE ap_account_move SET moved_to_acct='CafÃ©' WHERE actor_key='cmdr_nova'", 1],
    'no move table' => ['settings_move', 'DROP TABLE ap_account_move', 1],
] as $name => [$view, $sql, $owner]) {
    schema(); if ($sql !== '') $db->exec($sql);
    $php = render($view, $owner);
    check($php !== '', "$name PHP section rendered");
    [$status] = native(request($view, $owner, in_array($owner, [1, 6], true)), $secret); check($status === 422, "$name -> $status");
}
schema();
foreach (['settings_aliases', 'settings_move'] as $view) {
    [$status] = native(request($view, 1, false), $secret); check($status === 422, "$view role mismatch -> $status");
    [$status] = native(request($view, 9, false), $secret); check($status === 422, "$view unknown account -> $status");
    $bad = request($view, 1, true); unset($bad['viewer_admin']); [$status] = native($bad, $secret); check($status === 400, "$view no role -> $status");
    $bad = request($view, 1, true); $bad['offset'] = 1; [$status] = native($bad, $secret); check($status === 400, "$view offset -> $status");
}
$db->exec("UPDATE ap_users SET disabled_at='2026-10-19 00:00:00' WHERE id=2");
[$status] = native(request('settings_move', 2, false), $secret); check($status === 422, "disabled account -> $status");

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
