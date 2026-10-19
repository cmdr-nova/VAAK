<?php
/**
 * Settings personal lists native read review (sandbox only).
 *
 * Renders the genuine Deprioritized / Muted words / Your blocks sections of the
 * profile view in ap-admin.php against a seeded PostgreSQL fixture, asks a
 * running worker for the same body over /internal/admin/read, and compares the
 * HTML after collapsing whitespace runs. Checks that ordinary accounts are
 * served, that other owners' rows never appear, and the fallback cases
 * (disabled account, changed admin role, too many rows, unproven dates).
 * Never point it at production: it drops and recreates its tables.
 *
 * Usage: php settings-lists-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET
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
foreach (['h', 'relative_time'] as $fn) eval(declaration($source, $fn));
require $api . '/ap-admin-native.php';
foreach (['ap_deprioritized_list', 'ap_muted_words_list', 'ap_user_blocks_list'] as $fn) eval(declaration($dbSource, $fn));

$marker = '<?php if (($settingsNativeLists = ap_settings_native_lists_body(!empty($vaakIsAdmin))) !== null): ?><?= $settingsNativeLists ?><?php else: ?>';
$start = strpos($source, $marker);
$end = strpos($source, "        <?php endif; ?>\n        <script>\n          (function () {\n            const input = document.getElementById('profile-list-search');", (int) $start);
if ($start === false || $end === false) throw new RuntimeException('settings lists branch not found');
$template = substr($source, $start + strlen($marker), $end - $start - strlen($marker));

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
const CSRF = 'session<&"token\'';
function ap_auth_csrf_token(): string { return CSRF; }

$db->exec("DROP TABLE IF EXISTS ap_deprioritized_actors; DROP TABLE IF EXISTS ap_muted_words; DROP TABLE IF EXISTS ap_user_blocks; DROP TABLE IF EXISTS ap_users;
CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
INSERT INTO ap_users VALUES (1,'operator',1,NULL),(2,'ordinary',0,NULL),(3,'other',0,NULL);
CREATE TABLE ap_deprioritized_actors(owner_user_id $idType NOT NULL, actor_id TEXT NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY(owner_user_id, actor_id));
CREATE TABLE ap_muted_words(id $idType PRIMARY KEY, owner_user_id $idType NOT NULL, phrase TEXT NOT NULL, created_at TEXT NOT NULL, note TEXT, UNIQUE(owner_user_id, phrase));
CREATE TABLE ap_user_blocks(id $idType PRIMARY KEY, owner_user_id $idType NOT NULL, scope TEXT NOT NULL, value TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'block', reason TEXT, created_at TEXT NOT NULL, UNIQUE(owner_user_id, scope, value))");

$seeds = [
    'empty' => [[], [], []],
    'one-each' => [
        [['https://a.example/users/one', '2026-10-18 10:00:00']],
        [[1, 'spoilers', '2026-10-01T00:00:00+00:00', null]],
        [[1, 'actor', 'https://b.example/users/two', 'block', null, '2026-10-19 09:00:00']],
    ],
    'many' => [
        [['https://a.example/users/x/', '2026-10-18 10:00:00'], ['https://a.example/users/y', '2026-10-18 10:00:00'],
         ['https://esc.example/u?a=1&b=<x>"\'', '2026-10-17 00:00:00'], ['https://z.example/users/z', '']],
        [[1, 'tie', '2026-10-02 00:00:00', 'kept'], [2, 'also tie', '2026-10-02 00:00:00', '0'],
         [3, '<b>&"\'é ünï', '2026-09-01 00:00:00', 'note <i>&</i>'], [4, '0', '2026-08-01 00:00:00', ''],
         [5, 'future', gmdate('Y-m-d H:i:s', time() + 3600), null]],
        [[1, 'actor', 'https://b.example/users/a b?c=d&e', 'block', 'harassment <x>', '2026-10-19 09:00:00'],
         [2, 'domain', 'spam.example', 'suspend', '0', '2026-10-19 09:00:00'],
         [3, 'actor', 'https://c.example/@x~y', 'suspend', '', '2026-10-01T12:00:00Z'],
         [4, 'weird', 'odd & <scope>', 'kind<>', null, '2026-10-01 12:00:00'],
         [5, 'domain', 'pgtext.example', 'block', null, gmdate('Y-m-d H:i:s', time() - 7200) . '.654321+00']],
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
function request(int $owner, bool $admin, string $mode = 'full'): array {
    return ['protocol_version' => 1, 'owner_id' => $owner, 'csrf' => CSRF, 'view' => 'settings_lists', 'mode' => $mode,
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => 'UTC', 'viewer_admin' => $admin];
}
function render(int $owner, bool $admin): string {
    global $template;
    $deprioritizedRows = ap_deprioritized_list($owner); $mutedWordRows = ap_muted_words_list($owner);
    $userBlocks = ap_user_blocks_list($owner); $vaakIsAdmin = $admin;
    ob_start(); eval('?>' . $template); return collapse((string) ob_get_clean());
}
// Same clock second for PHP and native, so relative times agree.
function compare(int $owner, bool $admin, string $mode): array {
    for ($try = 0; $try < 3; $try++) {
        $second = time(); $php = render($owner, $admin); [$status, $reply] = native(request($owner, $admin, $mode), $GLOBALS['secret']);
        if (time() === $second) break;
    }
    return [$php, $status, $reply];
}

$dp = $db->prepare('INSERT INTO ap_deprioritized_actors VALUES (?,?,?)');
$mw = $db->prepare('INSERT INTO ap_muted_words VALUES (?,?,?,?,?)');
$ub = $db->prepare('INSERT INTO ap_user_blocks VALUES (?,?,?,?,?,?,?)');
function seed(array $lists): int {
    global $db, $dp, $mw, $ub;
    $db->exec('DELETE FROM ap_deprioritized_actors; DELETE FROM ap_muted_words; DELETE FROM ap_user_blocks');
    [$d, $w, $b] = $lists;
    foreach ($d as [$actor, $at]) { foreach ([1, 2] as $o) $dp->execute([$o, $actor, $at]); $dp->execute([3, $actor . '/other', $at]); }
    foreach ($w as [$id, $phrase, $at, $note]) { foreach ([1, 2] as $o) $mw->execute([$id * 10 + $o, $o, $phrase, $at, $note]); $mw->execute([$id * 10 + 3, 3, $phrase . ' other', $at, $note]); }
    foreach ($b as [$id, $scope, $value, $kind, $reason, $at]) { foreach ([1, 2] as $o) $ub->execute([$id * 10 + $o, $o, $scope, $value, $kind, $reason, $at]); $ub->execute([$id * 10 + 3, 3, $scope, $value . '.other', $kind, $reason, $at]); }
    return count($d) + count($w) + count($b);
}
foreach ($seeds as $name => $lists) {
    $count = seed($lists);
    foreach ([[1, true], [2, false]] as [$owner, $admin]) foreach (['full', 'shell'] as $mode) {
        [$php, $status, $reply] = compare($owner, $admin, $mode);
        $label = "$name/owner$owner/$mode";
        check($status === 200, "$label status $status");
        $html = collapse((string) ($reply['html'] ?? ''));
        check($html === $php, "$label html");
        if ($html !== $php) {
            file_put_contents(sys_get_temp_dir() . "/settings-$name-$owner-$mode.php.html", $php);
            file_put_contents(sys_get_temp_dir() . "/settings-$name-$owner-$mode.native.html", $html);
        }
        check(($reply['source_count'] ?? -1) === $count, "$label count");
        $sent = request($owner, $admin, $mode); $sent['now'] = 0;
        check(is_array($reply) && ap_admin_native_reply($reply, $sent) === ($reply['html'] ?? null), "$label bridge accepts reply");
        if (is_array($reply) && $reply['rows']) {
            $tampered = $reply; $tampered['rows'][0]['owner_user_id'] = 3; check(ap_admin_native_reply($tampered, $sent) === null, "$label bridge rejects extra row key");
            $tampered = $reply; $tampered['request_binding']['viewer_admin'] = !$admin; check(ap_admin_native_reply($tampered, $sent) === null, "$label bridge rejects role binding");
        }
        check(!str_contains($html, 'other'), "$label other owner's rows");
        check(str_contains($html, 'Admin → Server blocks') === $admin, "$label admin link");
        check(substr_count($html, 'name="csrf" value="' . h(CSRF) . '"') === count($lists[0]), "$label csrf");
    }
}

// Fallbacks: the PHP bridge renders the PHP sections whenever native does not answer 200.
[$status] = native(request(1, false), $secret); check($status === 422, "admin painted as ordinary -> $status");
[$status] = native(request(2, true), $secret); check($status === 422, "ordinary painted as admin -> $status");
[$status] = native(request(9, false), $secret); check($status === 422, "unknown account -> $status");
$db->exec("UPDATE ap_users SET disabled_at='2026-10-19 00:00:00' WHERE id=2");
[$status] = native(request(2, false), $secret); check($status === 422, "disabled account -> $status");
$db->exec('UPDATE ap_users SET disabled_at=NULL WHERE id=2');
[$status] = native(request(2, false), str_repeat('x', strlen($secret))); check($status === 401, "wrong token -> $status");
$bad = request(2, false); $bad['offset'] = 1; [$status] = native($bad, $secret); check($status === 400, "offset -> $status");
$bad = request(2, false); unset($bad['viewer_admin']); [$status] = native($bad, $secret); check($status === 400, "no viewer role -> $status");
$bad = request(2, false); $bad['search'] = ''; [$status] = native($bad, $secret); check($status === 400, "search -> $status");
$bad = request(1, true); $bad['view'] = 'blocks'; [$status] = native($bad, $secret); check($status === 400, "viewer role on admin view -> $status");
seed([[], [[1, 'x', 'yesterday', null]], []]);
[$status] = native(request(2, false), $secret); check($status === 422, "unproven date -> $status");
seed([[], [], []]);
for ($i = 1; $i <= 1001; $i++) $mw->execute([$i, 2, "w$i", '2026-10-01 00:00:00', null]);
[$status] = native(request(2, false), $secret); check($status === 422, "1001 muted words -> $status");

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
