<?php
/**
 * Admin Queue health native read review (sandbox only).
 *
 * Runs the genuine queue and dependency part of the `queue_health` data block and
 * the Queue classes and Federation delivery dependencies template of ap-admin.php
 * (with the genuine relative_time and h) against a seeded PostgreSQL fixture, asks
 * a running worker for the same cards over /internal/admin/read, and compares the
 * HTML after collapsing whitespace runs, plus the totals grid PHP sums from the
 * reply rows. Checks timestamptz and text queue columns, past and future times,
 * missing queue tables, dependency ordering and the 25-row limit, and the fallback
 * cases. Never point it at production: it drops and recreates its tables.
 *
 * Usage: php admin-queue-health-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET
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

function region(string $source, string $start, string $end): string {
    $a = strpos($source, $start); if ($a === false) throw new RuntimeException('missing start ' . substr($start, 0, 60));
    $b = strpos($source, $end, $a); if ($b === false) throw new RuntimeException('missing end ' . substr($end, 0, 60));
    return substr($source, $a + strlen($start), $b - $a - strlen($start));
}
$depEnd = "error_log('[ap-admin] queue dependency summary: ' . \$e->getMessage());\n    }";
$compute = '$queueDefs = ' . region($source, "    \$queueDefs = ", $depEnd) . $depEnd;
$grid = region($source, "        <div class=\"stat-grid\">\n          <div class=\"stat\"><div class=\"n\"><?= (int) array_sum(array_column(\$queueHealthRows, 'queued')) ?>", "        </div>\n        <div class=\"side-card\" style=\"margin-top:1rem\">\n          <h3>Redis coordination</h3>");
$grid = "<div class=\"stat-grid\"><div class=\"stat\"><div class=\"n\"><?= (int) array_sum(array_column(\$queueHealthRows, 'queued')) ?>" . $grid . '</div>';
$template = region($source, "<?php if (\$queueHealthNativeBody !== null): ?><?= \$queueHealthNativeBody ?><?php else: ?>", "        <?php endif; ?>\n        <?php if (\$timingMetrics): ?>");
require $api . '/ap-admin-native.php';

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
function at(int $offset): string { return gmdate('c', time() + $offset); }
const TABLES = ['ap_action_queue', 'ap_publish_delivery_queue', 'ap_fanout_delivery_queue', 'ap_media_warm_queue', 'ap_post_queue', 'bsky_actor_refresh_queue'];
function schema(): void {
    global $db, $idType;
    $db->exec('DROP TABLE IF EXISTS ap_users; DROP TABLE IF EXISTS ' . implode('; DROP TABLE IF EXISTS ', TABLES));
    $db->exec("CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
    INSERT INTO ap_users VALUES (1,'operator','cmdr_nova',1,NULL),(2,'ordinary','ord',0,NULL);
    CREATE TABLE ap_action_queue(id $idType PRIMARY KEY, status TEXT, attempts INTEGER, next_attempt_at TIMESTAMPTZ, claimed_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL);
    CREATE TABLE ap_publish_delivery_queue(id $idType PRIMARY KEY, status TEXT, attempts INTEGER, next_attempt_at TEXT, claimed_at TEXT, created_at TEXT NOT NULL);
    CREATE TABLE ap_fanout_delivery_queue(id $idType PRIMARY KEY, activity_key TEXT, status TEXT, attempts INTEGER, next_attempt_at TEXT, claimed_at TEXT, created_at TEXT NOT NULL, updated_at TEXT);
    CREATE TABLE ap_media_warm_queue(id $idType PRIMARY KEY, status TEXT, attempts INTEGER, next_attempt_at TEXT, claimed_at TEXT, created_at TEXT NOT NULL);
    CREATE TABLE ap_post_queue(id $idType PRIMARY KEY, state TEXT, attempts INTEGER, scheduled_at TEXT, claimed_at TEXT, created_at TEXT NOT NULL);
    CREATE TABLE bsky_actor_refresh_queue(id $idType PRIMARY KEY, status TEXT, attempts INTEGER, next_attempt_at TEXT, locked_at TEXT, queued_at TEXT NOT NULL)");
    $id = 0;
    $st = $db->prepare('INSERT INTO ap_action_queue VALUES (?,?,?,?,?,?)');
    foreach ([['pending', 0, at(300), null, at(-7200)], ['pending', 2, at(-90), null, at(-5000)], ['processing', 1, null, at(-45), at(-600)], ['succeeded', 1, at(-3 * 86400), null, at(-4 * 86400)], ['failed', 5, at(-2 * 3600), null, at(-9 * 86400)]] as $r) $st->execute([++$id, ...$r]);
    $st = $db->prepare('INSERT INTO ap_publish_delivery_queue VALUES (?,?,?,?,?,?)');
    foreach ([['pending', 0, null, null, at(-40)], ['failed', 3, null, null, at(-86400 * 2)]] as $r) $st->execute([++$id, ...$r]);
    $st = $db->prepare('INSERT INTO ap_fanout_delivery_queue VALUES (?,?,?,?,?,?,?,?)');
    foreach (range(1, 28) as $n) foreach (range(1, $n % 3 + 1) as $k) $st->execute([++$id, 'https://mkultra.monster/users/cmdr_nova/statuses/' . $n . '#<&>', ['succeeded', 'pending', 'processing', 'failed'][($n + $k) % 4], $k, at(-60 * $n), null, at(-120 * $n), at(-60 * ($n % 20))]);
    $st = $db->prepare('INSERT INTO ap_post_queue VALUES (?,?,?,?,?,?)');
    foreach ([['pending', 0, at(86400 * 2), null, at(-60)], ['publishing', 1, at(-30), at(-20), at(-90)], ['published', 1, at(-3600), null, at(-7200)]] as $r) $st->execute([++$id, ...$r]);
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
    return ['protocol_version' => 1, 'owner_id' => $owner, 'csrf' => 'session', 'view' => 'queue_health', 'mode' => $mode,
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => date_default_timezone_get()];
}
/** @return array{0:string,1:string} the PHP cards and totals grid */
function render(?array $nativeRows = null): array {
    global $compute, $template, $grid, $db;
    $queueHealthNativeBody = null; $queueHealthRows = []; $queueDependencyRows = [];
    error_reporting(E_ALL & ~E_WARNING); eval($compute); error_reporting(E_ALL);
    ob_start(); eval('?>' . $template); $cards = collapse((string) ob_get_clean());
    if ($nativeRows !== null) $queueHealthRows = $nativeRows;
    ob_start(); eval('?>' . $grid); return [$cards, collapse((string) ob_get_clean())];
}
function compare(string $mode, string $label): ?array {
    global $secret;
    [$php, $phpGrid] = render(); [$status, $reply] = native(request(1, $mode), $secret);
    check($status === 200, "$label status $status");
    $html = collapse((string) ($reply['html'] ?? ''));
    check($html === $php, "$label html");
    if ($html !== $php) { file_put_contents(sys_get_temp_dir() . "/queue-$mode.php.html", $php); file_put_contents(sys_get_temp_dir() . "/queue-$mode.native.html", $html); }
    $rows = null;
    check(is_array($reply) && ap_admin_native_reply($reply, request(1, $mode)) === ($reply['html'] ?? null), "$label bridge accepts reply");
    if (is_array($reply) && ap_admin_native_post_rows_probe($reply, request(1, $mode), $rows)) {
        check(render($rows)[1] === $phpGrid, "$label totals grid from reply rows");
    } else check(false, "$label rows out");
    return is_array($reply) ? $reply : null;
}
/** The rows ap_admin_native_post hands back are the validated reply rows. */
function ap_admin_native_post_rows_probe(array $reply, array $request, ?array &$rows): bool {
    if (ap_admin_native_reply($reply, $request) === null) return false;
    $rows = $reply['rows']; return true;
}

schema();
foreach (['full', 'shell'] as $mode) {
    $label = "queues/$mode";
    $reply = compare($mode, $label);
    $html = (string) ($reply['html'] ?? '');
    check(count($reply['rows'] ?? []) === 6 && ($reply['rows'][5]['ok'] ?? null) === true, "$label six queues");
    check(substr_count($html, '<tr> <td class="meta"') === 25, "$label dependency limit");
    check(str_contains($html, '#&lt;&amp;&gt;'), "$label activity escaped");
    check(str_contains($html, '>-') || str_contains($html, '-'), "$label future times present");
    if ($mode === 'full' && is_array($reply)) {
        $sent = request(1, $mode);
        $t = $reply; $t['rows'][0]['queued'] = '1'; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects string count");
        $t = $reply; $t['rows'][0]['name'] = 'Other'; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects other queue");
        $t = $reply; array_pop($t['rows']); $t['source_count'] = 5; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects five queues");
        $t = $reply; $t['rows'][1]['ok'] = false; check(ap_admin_native_reply($t, $sent) === null, "$label bridge rejects counts on an unavailable queue");
    }
}
// Missing queue tables read as PHP's "unavailable" rows; no fan-out table hides the dependency card.
schema(); $db->exec('DROP TABLE bsky_actor_refresh_queue; DROP TABLE ap_fanout_delivery_queue');
$reply = compare('full', 'missing tables');
check(($reply['rows'][5]['ok'] ?? null) === false && ($reply['rows'][2]['ok'] ?? null) === false && !str_contains((string) ($reply['html'] ?? ''), 'Federation delivery dependencies'), 'missing tables unavailable');
// Empty queues.
schema(); foreach (TABLES as $t) $db->exec("DELETE FROM $t");
compare('full', 'empty queues');

// Fallbacks: a query PHP catches per table, a date only strtotime reads, text PHP would repair, admin only.
foreach ([
    'missing attempts column' => 'ALTER TABLE ap_media_warm_queue DROP COLUMN attempts',
    'relative date' => "UPDATE ap_publish_delivery_queue SET created_at='yesterday' WHERE status='pending'",
    'mojibake activity' => "UPDATE ap_fanout_delivery_queue SET activity_key='CafÃ©' WHERE id=(SELECT MIN(id) FROM ap_fanout_delivery_queue)",
] as $name => $sql) {
    schema(); $db->exec($sql);
    check(str_contains(render()[0], 'Queue classes'), "$name PHP cards rendered");
    $bad = request(1); $bad['now'] = time(); [$status] = native($bad, $secret); check($status === 422, "$name -> $status");
}
schema();
[$status] = native(request(2), $secret); check($status === 422, "ordinary account -> $status");
[$status] = native(request(9), $secret); check($status === 422, "unknown account -> $status");
$bad = request(1); $bad['offset'] = 1; [$status] = native($bad, $secret); check($status === 400, "offset -> $status");

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
