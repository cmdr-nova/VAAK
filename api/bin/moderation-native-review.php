<?php
/**
 * Moderation reports native read review (sandbox only).
 *
 * Renders the genuine report list of the Moderation view in ap-admin.php with
 * the genuine ap_reports_list, actor_handle, admin_global_actor_control and
 * admin_is_local_admin_actor declarations against a seeded PostgreSQL fixture,
 * asks a running worker for the same list over /internal/admin/read, and
 * compares the HTML after collapsing whitespace runs, for every filter, in full
 * and shell modes. Also checks which warms each side queues, the bridge
 * validator, and the fallback cases. Never point it at production: it drops
 * and recreates its tables.
 *
 * Usage: php moderation-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET [QUEUE_REDIS_PORT]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
[$self, $dsn, $idType, $url, $secret] = $argv + [null, null, 'INTEGER', null, null];
if (!$dsn || !in_array($idType, ['INTEGER', 'BIGINT'], true) || !$url || strlen((string) $secret) < 32) {
    fwrite(STDERR, "usage: dsn INTEGER|BIGINT url secret [queue-redis-port]\n"); exit(2);
}
if (!str_contains($dsn, 'fixture')) { fwrite(STDERR, "refusing a database without 'fixture' in its name\n"); exit(2); }
$queuePort = $argv[5] ?? null;

$api = dirname(__DIR__);
$source = (string) file_get_contents($api . '/ap-admin.php');
$dbSource = (string) file_get_contents($api . '/ap-db.php');
$reportsSource = (string) file_get_contents($api . '/ap-reports.php');
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
    // Request-lifetime statics would carry one case's rows into the next.
    return (string) preg_replace('/\bstatic (\$\w+\s*=)/', '$1', $out);
}
foreach (['h', 'relative_time', 'short_host', 'actor_handle', 'admin_status_href', 'admin_global_actor_control', 'admin_is_local_admin_actor', 'vaak_actor_id'] as $fn) eval(declaration($source, $fn));
foreach (['ap_block_list', 'ap_actor_moderation_aliases', 'ap_remote_actor_label', 'ap_remote_actor_ensure', 'ap_remote_actor_get', 'ap_remote_actor_id_is_unfederated_local',
    'ap_remote_actor_normalize_username', 'ap_remote_actor_username_is_placeholder', 'ap_local_user_by_username'] as $fn) eval(declaration($dbSource, $fn));
foreach (['ap_reports_list', 'ap_report_priority_score'] as $fn) eval(declaration($reportsSource, $fn));
$effects = [];
function effect(string $kind, string $value): void { $GLOBALS['effects'][] = "$kind:$value"; }
function ap_remote_actor_icon_miss_until(string $actorId): int { return 0; }
function ap_remote_actor_flat_put(string $actorId, ?array $row = null): void {}
function ap_remote_actor_warm_async(string $actorId, bool $force = false): void {
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '' || !str_starts_with(strtolower($actorId), 'https://') || !filter_var($actorId, FILTER_VALIDATE_URL)) return;
    effect('actor', $actorId);
}

function region(string $source, string $start, string $end): string {
    $a = strpos($source, $start); if ($a === false) throw new RuntimeException('missing start ' . substr($start, 0, 60));
    $b = strpos($source, $end, $a); if ($b === false) throw new RuntimeException('missing end ' . substr($end, 0, 60));
    return substr($source, $a + strlen($start), $b - $a - strlen($start));
}
$template = '<?php $modReports = ap_reports_list($modFilter, 80); ?>' . region($source,
    "<?php else: \$modReports = ap_reports_list(\$modFilter, 80); ?>",
    "        <?php endif; ?>\n\n      <?php elseif (\$view === 'blocks'): ?>");
require $api . '/ap-admin-native.php';

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
const CSRF = 'session<&"token\'';
function ap_auth_csrf_token(): string { return CSRF; }

$db->exec("DROP TABLE IF EXISTS ap_reports; DROP TABLE IF EXISTS ap_blocks; DROP TABLE IF EXISTS remote_actors; DROP TABLE IF EXISTS ap_users;
CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, actor_id TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
INSERT INTO ap_users VALUES (1,'operator','op_er','https://mkultra.monster/users/op_er',1,NULL),(2,'ordinary','ord',NULL,0,NULL),(4,'boss','boss','https://mkultra.monster/users/boss/',1,'2026-01-01 00:00:00');
CREATE TABLE ap_reports(id $idType PRIMARY KEY, activity_id TEXT NOT NULL UNIQUE, direction TEXT NOT NULL, reporter_actor_id TEXT NOT NULL, target_actor_id TEXT, status_uris_json TEXT, comment TEXT,
  about_us INTEGER NOT NULL DEFAULT 0, state TEXT NOT NULL DEFAULT 'open', admin_notes TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE ap_blocks(id $idType PRIMARY KEY, scope TEXT NOT NULL, value TEXT NOT NULL, kind TEXT NOT NULL, reason TEXT, created_at TEXT NOT NULL);
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
function request(string $filter, int $owner = 1, string $mode = 'full'): array {
    return ['protocol_version' => 1, 'owner_id' => $owner, 'csrf' => CSRF, 'view' => 'moderation', 'mode' => $mode,
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => 'UTC', 'filter' => $filter];
}
function queued(): array {
    global $queuePort; if ($queuePort === null) return [];
    $out = []; exec('redis-cli -p ' . (int) $queuePort . ' LRANGE vaak:queue:ap_actor_warm 0 -1', $out);
    $ids = array_values(array_unique(array_filter(array_map(fn($j) => (string) (json_decode($j, true)['actor_id'] ?? ''), $out), 'strlen'))); sort($ids); return $ids;
}
function clear_queue(): void { global $queuePort; if ($queuePort !== null) exec('redis-cli -p ' . (int) $queuePort . ' FLUSHDB'); }
function render(string $modFilter, int $owner): string {
    global $template, $db;
    $GLOBALS['ap_remote_actor_memo'] = []; $GLOBALS['vaak_ap_actor_memo'] = [];
    $u = $db->query('SELECT * FROM ap_users WHERE id = ' . $owner)->fetch();
    $key = (string) ($u['actor_key'] ?? 'cmdr_nova');
    $GLOBALS['vaak_actor_id'] = rtrim((string) ($u['actor_id'] ?? ('https://mkultra.monster/users/' . $key)), '/');
    ob_start(); eval('?>' . $template); return collapse((string) ob_get_clean());
}
function compare(string $filter, string $mode): array {
    for ($try = 0; $try < 3; $try++) {
        $second = time(); $GLOBALS['effects'] = []; clear_queue();
        $php = render($filter, 1); [$status, $reply] = native(request($filter, 1, $mode), $GLOBALS['secret']);
        if (time() === $second) break;
    }
    return [$php, $status, $reply];
}
function seed(array $s): void {
    global $db;
    $db->exec('DELETE FROM ap_reports; DELETE FROM ap_blocks; DELETE FROM remote_actors');
    $ins = fn(string $sql, array $rows) => array_map(fn($r) => $db->prepare($sql)->execute($r), $rows);
    $ins('INSERT INTO ap_reports (id, activity_id, direction, reporter_actor_id, target_actor_id, status_uris_json, comment, about_us, state, admin_notes, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        array_map(fn($r) => [$r[0], 'https://act.example/' . $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8], $r[9], $r[9]], $s['reports'] ?? []));
    $ins('INSERT INTO ap_blocks (id, scope, value, kind, reason, created_at) VALUES (?,?,?,?,?,?)', $s['blocks'] ?? []);
    $ins('INSERT INTO remote_actors (actor_id, username, display_name, host) VALUES (?,?,?,?)', $s['actors'] ?? []);
}

$now = time();
$ago = fn(int $s, string $format = 'c') => gmdate($format, $now - $s);
$many = json_encode(array_map(fn($i) => "https://p.example/notes/$i", range(1, 14)));
$base = [
    // id, direction, reporter, target, status_uris_json, comment, about_us, state, admin_notes, created_at
    'reports' => [
        [1, 'in', 'https://r.example/users/rita', 'https://mkultra.monster/users/op_er', json_encode(['https://mkultra.monster/notes/1', 'https://x.example/p/2/#announce-5', 7, '', ' https://x.example/p/3 ', 'http://plain.example/p']), "Spam <b>& \"quoted\"\n line", 1, 'open', '', $ago(3600)],
        [2, 'in', 'https://r.example/@sam/', 'https://t.example/users/tom', '', '  ', 0, 'dismissed', "Looked <i>\nfine", $ago(3 * 86400 + 5)],
        [3, 'local', 'https://mkultra.monster/users/ord', 'https://t.example/users/mute', null, 'local note', 0, 'open', '', $ago(7200, 'Y-m-d H:i:s.u') . '+00'],
        [4, 'out', 'https://mkultra.monster/users/ord', 'https://t.example/users/tom', '{bad json', null, 0, 'open', '0', $ago(90000, 'Y-m-d H:i:s')],
        [5, 'out', 'https://remote.example/users/x', null, '[]', '0', 0, 'open', '', $ago(100)],
        [6, 'in', 'https://r.example/users/uncached', 'https://t.example/users/123456789', $many, 'x', 0, 'ignored', '', $ago(-100)],
        [7, 'in', 'https://mkultra.monster/users/boss', '', '["https://p.example/a"]', '', 0, 'open', '', $ago(5 * 86400)],
        [8, 'local', 'https://mkultra.monster/users/ord', 'https://r.example/users/rita', null, null, 1, 'dismissed', '', $ago(50)],
        [9, 'out', 'https://mkultra.monster/users/ord', 'https://t.example/users/brid', null, null, 0, 'ignored', '', $ago(50)],
        [10, 'in', 'https://r.example/users/rita', 'https://t.example/users/sus', null, null, 1, 'open', '', $ago(3600)],
    ],
    'blocks' => [
        [1, 'actor', 'https://t.example/users/tom', 'block', null, $ago(500)],
        [2, 'actor', 'https://t.example/users/tom', 'suspend', null, $ago(400)],
        [3, 'actor', 'https://t.example/users/mute/', 'mute', null, $ago(300)],
        [4, 'domain', 't.example', 'block', null, $ago(200)],
        [5, 'actor', 'https://bsky.app/profile/did:plc:abc', 'block', null, $ago(100)],
        [6, 'actor', 'https://t.example/users/sus', 'suspend', 'r<&>', $ago(50)],
    ],
    'actors' => [
        ['https://r.example/users/rita', 'rita', 'Rita <R>', 'r.example'],
        ['https://r.example/@sam', 'sam', null, 'r.example'],
        ['https://t.example/users/tom', 'tom', 'Tom', 'other.example'],
        ['https://t.example/users/123456789', '123456789', null, 't.example'],
        ['https://t.example/users/brid', 'alice.bsky.social', 'Alice', 'bsky.brid.gy'],
    ],
];
$cases = ['empty' => [], 'populated' => $base];
foreach ($cases as $name => $seedRows) {
    seed($seedRows);
    foreach (['open', 'about_us', 'outbound', 'closed', 'all'] as $filter) foreach (['full', 'shell'] as $mode) {
        [$php, $status, $reply] = compare($filter, $mode);
        $label = "$name/$filter/$mode";
        check($status === 200, "$label status $status");
        $html = collapse((string) ($reply['html'] ?? ''));
        check($html === $php, "$label html");
        if ($html !== $php) {
            file_put_contents(sys_get_temp_dir() . "/moderation-$name-$filter-$mode.php.html", $php);
            file_put_contents(sys_get_temp_dir() . "/moderation-$name-$filter-$mode.native.html", $html);
        }
        $phpWarm = array_values(array_unique(array_map(fn($e) => substr($e, 6), array_filter($GLOBALS['effects'], fn($e) => str_starts_with($e, 'actor:'))))); sort($phpWarm);
        if ($queuePort !== null) check(queued() === $phpWarm, "$label native warms " . json_encode(queued()) . ' php ' . json_encode($phpWarm));
        $sent = request($filter, 1, $mode);
        check(is_array($reply) && ap_admin_native_reply($reply, $sent) === ($reply['html'] ?? null), "$label bridge accepts reply");
        if ($name === 'populated' && is_array($reply)) {
            check(($reply['source_count'] ?? 0) > 0 && strlen($php) > 1000, "$label populated list is not empty");
            if ($filter === 'all') check($phpWarm !== [], "$label warms some actor");
            $tampered = $reply; $tampered['rows'][0]['extra'] = 1;
            check(ap_admin_native_reply($tampered, $sent) === null, "$label bridge rejects extra row key");
            $tampered = $reply; $tampered['request_binding']['filter'] = $filter === 'all' ? 'open' : 'all';
            check(ap_admin_native_reply($tampered, $sent) === null, "$label bridge rejects other filter binding");
        }
    }
}

// Fallbacks: rows PHP labels from caches or links PHP converts, and every refusal, render the PHP list.
$report = fn(string $reporter, ?string $target, ?string $uris = null, string $created = '') => ['reports' => [[1, 'in', $reporter, $target, $uris, null, 0, 'open', '', $created !== '' ? $created : $ago(60)]]];
$fallbacks = [
    'did reporter' => $report('did:plc:abc', null),
    'bluesky profile target' => $report('https://r.example/users/rita', 'https://bsky.app/profile/alice.bsky.social'),
    'bridged did target' => $report('https://r.example/users/rita', 'https://bsky.brid.gy/ap/did:plc:abc'),
    'http reporter' => $report('http://r.example/users/rita', null),
    'at uri' => $report('https://r.example/users/rita', null, '["at://did:plc:abc/app.bsky.feed.post/1"]'),
    'uri map' => $report('https://r.example/users/rita', null, '{"a":"https://p.example/a"}'),
    'odd date' => $report('https://r.example/users/rita', null, null, 'yesterday'),
    'cached local actor' => $report('https://mkultra.monster/users/ord', null) + ['actors' => [['https://mkultra.monster/users/ord', 'ord', 'Ord', 'mkultra.monster']]],
];
foreach ($fallbacks as $name => $rows) {
    seed($rows); clear_queue();
    [$status] = native(request('all'), $secret); check($status === 422, "$name -> $status");
}
seed($base);
[$status] = native(request('all', 2), $secret); check($status === 422, "ordinary account -> $status");
[$status] = native(request('all', 4), $secret); check($status === 422, "disabled admin -> $status");
$bad = request('bogus'); [$status] = native($bad, $secret); check($status === 400, "unknown filter -> $status");
$bad = request('all'); unset($bad['filter']); [$status] = native($bad, $secret); check($status === 400, "no filter -> $status");
$bad = request('all'); $bad['offset'] = 1; [$status] = native($bad, $secret); check($status === 400, "offset -> $status");
$bad = request('all'); $bad['view'] = 'blocks'; [$status] = native($bad, $secret); check($status === 400, "filter on another view -> $status");

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
