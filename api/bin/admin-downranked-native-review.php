<?php
/**
 * Admin Downranked native read review (sandbox only).
 *
 * Renders the genuine `downranked` branch of ap-admin.php (with the genuine
 * signal, evidence, toxicity, actor label, link and audit helpers) against a
 * seeded PostgreSQL fixture, renders it again with the native bridge returning a
 * marker, asks a running worker for the native body over /internal/admin/read,
 * and checks that the marker page with the native body in place equals the PHP
 * page after collapsing whitespace runs. Also checks the fallback cases. Never
 * point it at production: it drops and recreates its tables.
 *
 * Usage: php admin-downranked-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET
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
foreach (['h', 'relative_time', 'short_host', 'actor_handle', 'actor_display_name', 'admin_status_href', 'admin_remote_actor_href',
    'admin_home_downrank_actor_exempt', 'admin_home_downrank_purge_stale', 'admin_home_downranked_actor_rows', 'admin_home_downrank_evidence_rows',
    'admin_home_toxicity_categories', 'admin_home_downrank_audit_rows'] as $fn) eval(declaration($source, $fn));
foreach (['ap_fix_utf8', 'ap_downrank_actor_exempt', 'ap_downrank_text_categories', 'ap_downrank_phrase_matches_text',
    'ap_remote_actor_label', 'ap_remote_actor_ensure', 'ap_remote_actor_get', 'ap_remote_actor_id_is_unfederated_local',
    'ap_remote_actor_normalize_username', 'ap_remote_actor_username_is_placeholder',
    'ap_bridgy_bsky_profile_web_url', 'ap_threads_profile_web_url', 'ap_is_threads_url'] as $fn) eval(declaration($dbSource, $fn));
// Declared substitutions for file/Redis/queue helpers (no Redis cache in the sandbox on either side).
function ap_remote_actor_icon_miss_until(string $actorId): int { return 0; }
function ap_remote_actor_flat_put(string $actorId, ?array $row = null): void {}
function ap_remote_actor_warm_async(string $actorId, bool $force = false): void {}
const CSRF = 'session<&"token\'';
function ap_auth_csrf_token(): string { return CSRF; }
const MARK = '@@NATIVE-BODY@@';
$nativeStub = null;
function ap_admin_native_read_body(string $view): ?string { return $view === 'downranked' ? $GLOBALS['nativeStub'] : null; }
$bridgeSource = (string) file_get_contents($api . '/ap-admin-native.php');
foreach (['ap_admin_native_reply', 'ap_admin_downranked_native_rows_valid'] as $fn) eval(declaration($bridgeSource, $fn));

$marker = "      <?php elseif (\$view === 'downranked'): ?>";
$a = strpos($source, $marker); $b = strpos($source, "      <?php elseif (\$view === 'downranking'): ?>", (int) $a);
if ($a === false || $b === false) throw new RuntimeException('downranked branch not found');
$branch = '<?php if (false): ?>' . substr($source, $a, $b - $a) . '<?php endif; ?>';

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
function at(int $offset): string { return gmdate('c', time() + $offset); }
const ALICE = 'https://remote.example/users/alice';
const BOB = 'https://other.example/@bob';
const SKY = 'https://bsky.app/profile/did:plc:abc123';
function schema(): void {
    global $db, $idType;
    foreach (['ap_users', 'ap_home_suppression', 'ap_home_downrank_terms', 'ap_home_downrank_audit', 'events', 'remote_actors'] as $t) $db->exec("DROP TABLE IF EXISTS $t");
    $db->exec("CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
    INSERT INTO ap_users VALUES (1,'operator','cmdr_nova',1,NULL),(2,'ordinary','ord',0,NULL),(3,'retired','ret',1,'2026-01-01T00:00:00+00:00');
    CREATE TABLE ap_home_suppression(owner_user_id $idType NOT NULL, actor_id TEXT NOT NULL, score INTEGER NOT NULL DEFAULT 0, categories_json TEXT NOT NULL DEFAULT '[]', suppressed_until TEXT NOT NULL, last_object_id TEXT NOT NULL DEFAULT '', seen_object_ids_json TEXT NOT NULL DEFAULT '[]', updated_at TEXT NOT NULL, PRIMARY KEY (owner_user_id, actor_id));
    CREATE TABLE ap_home_downrank_terms(id $idType PRIMARY KEY, phrase TEXT NOT NULL UNIQUE, category TEXT NOT NULL DEFAULT 'custom', enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
    CREATE TABLE ap_home_downrank_audit(id $idType PRIMARY KEY, admin_user_id $idType NOT NULL DEFAULT 0, admin_actor TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, actor_id TEXT NOT NULL DEFAULT '', term_id $idType NOT NULL DEFAULT 0, phrase TEXT NOT NULL DEFAULT '', details_json TEXT NOT NULL DEFAULT '{}', created_at TEXT NOT NULL);
    CREATE TABLE events(id $idType PRIMARY KEY, actor_id TEXT, object_id TEXT, created_at TEXT, summary TEXT, spoiler_text TEXT, visibility TEXT);
    CREATE TABLE remote_actors(actor_id TEXT PRIMARY KEY, username TEXT, display_name TEXT, host TEXT, icon_source_url TEXT, image_source_url TEXT, updated_at TEXT NOT NULL DEFAULT '', summary TEXT, profile_json TEXT)");
}
function seed(): void {
    global $db, $idType;
    $big = $idType === 'BIGINT' ? 9000000000 : 0;
    $db->prepare("INSERT INTO remote_actors(actor_id, username, display_name, host) VALUES (?, 'alice', 'Alice <&> \"A\"', 'remote.example')")->execute([ALICE]);
    $st = $db->prepare('INSERT INTO ap_home_suppression(owner_user_id, actor_id, score, categories_json, suppressed_until, last_object_id, updated_at) VALUES (?,?,?,?,?,?,?)');
    foreach ([
        [1, ALICE, 3, '["direct_abuse"]', at(5 * 3600 + 1800), ALICE . '/s/last', at(-60)],
        [2, ALICE, 5, '["custom","direct_abuse","xy"]', at(2 * 3600 + 1800), ALICE . '/s/old', at(-30)],
        [3, ALICE . '/', -2, '["misogyny"]', at(9 * 86400 + 1800), ALICE . '/s/newest', at(-10)],
        [1, BOB, 2, '["ai_slogan","0","","custom"]', at(3 * 86400 + 1800), '', at(-100)],
        [1, SKY, 2, 'not json', at(3 * 86400 + 1800), '', at(-100)],
        [1, 'https://tie.example/users/zed', 2, '[]', at(3 * 86400 + 1800), '', at(-100)],
        [1, 'https://mkultra.monster/users/local', 9, '["custom"]', at(86400), '', at(-5)],
        [1, 'https://x.example/@cmdr_nova', 9, '["custom"]', at(86400), '', at(-5)],
        [1, 'https://gone.example/users/old', 9, '["custom"]', at(-600), '', at(-700)],
        [1, 'https://soon.example/users/one', 1, '["custom"]', at(45), '', at(-1)],
    ] as [$owner, $actor, $score, $cats, $until, $last, $updated]) $st->execute([$owner + $big, $actor, $score, $cats, $until, $last, $updated]);
    $st = $db->prepare('INSERT INTO ap_home_downrank_terms VALUES (?,?,?,?,?,?)');
    foreach ([[1, 'bad word', 'custom', 1], [2, 'zzz top', 'custom', 0], [3, 'naughty  thing', 'x y!', 1], [4, 'Ünïcode Wörd', 'custom', 1]] as [$id, $p, $c, $e]) $st->execute([$id + $big, $p, $c, $e, at(-9000), at(-9000)]);
    $st = $db->prepare('INSERT INTO events VALUES (?,?,?,?,?,?,?)');
    $id = 0;
    $long = str_repeat('fuck you ünïcødé 💬 ', 12);
    foreach ([
        [ALICE, ALICE . '/s/1', at(-100), 'well fuck you then', null, 'public'],
        [ALICE . '/', ALICE . '/s/2/', at(-200), '', "spoiler: fuck   you\tagain", 'unlisted'],
        [ALICE, ALICE . '/s/3', at(-300), 'nice day', null, 'public'],
        [ALICE, ALICE . '/s/old', at(-350), 'harmless but the old signal object', null, 'public'],
        [ALICE, ALICE . '/s/last', at(-360), 'harmless but the last signal object', null, 'public'],
        [ALICE, ALICE . '/s/private', at(-370), 'fuck you privately', null, 'private'],
        [ALICE, ALICE . '/s/4#announce-12', at(-400), 'a bad word here and NAUGHTY THING', null, 'public'],
        [ALICE, ALICE . '/s/5', at(-500), $long, null, 'public'],
        [ALICE, ALICE . '/s/6', at(-500), "women　are\u{3000}property", ' ', 'public'],
        [ALICE, ALICE . '/s/7', at(-600), 'go fuck yourself', null, 'public'],
        [ALICE, ALICE . '/s/8', at(-700), 'fuck you once more', null, 'public'],
        [BOB, BOB . '/s/1', at(-100), 'ñslop is everywhere', null, 'public'],
        [BOB, BOB . '/s/2', at(-200), 'sloppy joes and #slop', null, 'public'],
        [BOB, BOB . '/s/3', at(-300), 'ünïcode wörd and ai   trash', null, 'public'],
        [BOB, '', at(-400), 'slop without an object', null, 'public'],
        [BOB, BOB . '/s/5', at(-500), '   ', null, 'public'],
    ] as $row) $st->execute([++$id + $big, ...$row]);
    $st = $db->prepare('INSERT INTO ap_home_downrank_audit(id, admin_user_id, admin_actor, action, actor_id, term_id, phrase, created_at) VALUES (?,?,?,?,?,?,?,?)');
    $same = at(-4000);
    foreach ([[1, 'cmdr_nova', 'term_saved', '', 'bad <word>', $same], [2, '', 'signal_cleared', ALICE, '', $same], [3, '0', 'server_mute', BOB, '', at(-90)], [4, 'cmdr_nova', 'term_previewed', '', 'x', at(-100000)]] as [$i, $admin, $action, $actor, $phrase, $created]) $st->execute([$i + $big, 1, $admin, $action, $actor, 0, $phrase, $created]);
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
    return ['protocol_version' => 1, 'owner_id' => $owner, 'csrf' => CSRF, 'view' => 'downranked', 'mode' => $mode,
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => date_default_timezone_get()];
}
function render(?string $stub, string $q = ''): string {
    global $branch;
    $GLOBALS['nativeStub'] = $stub; $_GET = $q === '' ? [] : ['q' => $q];
    $view = 'downranked'; $vaakIsAdmin = true;
    ob_start(); eval('?>' . $branch); return (string) ob_get_clean();
}
function compare(string $mode, string $label): ?array {
    global $secret;
    $php = collapse(render(null)); $shell = render(MARK); [$status, $reply] = native(request(1, $mode), $secret);
    check($status === 200, "$label status $status");
    check(substr_count($shell, MARK) === 1, "$label marker once");
    $html = (string) ($reply['html'] ?? '');
    $merged = collapse(str_replace(MARK, $html, $shell));
    check($merged === $php, "$label html");
    if ($merged !== $php) { file_put_contents(sys_get_temp_dir() . "/downranked-$mode.php.html", $php); file_put_contents(sys_get_temp_dir() . "/downranked-$mode.native.html", $merged); }
    check(is_array($reply) && ap_admin_native_reply($reply, request(1, $mode)) === ($reply['html'] ?? null), "$label bridge accepts reply");
    return is_array($reply) ? $reply : null;
}

schema(); seed();
foreach (['full', 'shell'] as $mode) {
    $reply = compare($mode, "signals/$mode");
    $html = (string) ($reply['html'] ?? '');
    $rows = $reply['rows'] ?? [];
    check(array_column($rows, 'actor_id') === [ALICE, SKY, BOB, 'https://tie.example/users/zed', 'https://soon.example/users/one'], "signals/$mode actor order");
    check(($rows[0]['viewers'] ?? 0) === 3 && ($rows[0]['evidence'] ?? 0) === 5, "signals/$mode alice grouped, five evidence");
    check(str_contains($html, 'Alice &lt;&amp;&gt; &quot;A&quot;') && str_contains($html, 'href="https://remote.example/@alice"'), "signals/$mode alice label and profile");
    check(str_contains($html, 'Categories: misogyny, direct_abuse, custom, xy'), "signals/$mode merged categories");
    check(str_contains($html, 'Categories: ai_slogan, custom ·'), "signals/$mode filtered categories");
    check(str_contains($html, 'Categories: uncategorized'), "signals/$mode uncategorized");
    check(str_contains($html, '?view=status&amp;object=https%3A%2F%2Fremote.example%2Fusers%2Falice%2Fs%2F4&amp;from=downranked'), "signals/$mode announce suffix stripped");
    check(str_contains($html, 'ñslop is everywhere') && str_contains($html, 'sloppy joes and #slop') && !str_contains($html, 'women　are'), "signals/$mode ASCII word boundary and whitespace");
    check(str_contains($html, 'ünïcode wörd and ai trash · ai_slogan, custom'), "signals/$mode Unicode caseless phrase");
    check(str_contains($html, 'Recent audit trail') && str_contains($html, '· by cmdr_nova'), "signals/$mode audit");
    if ($mode === 'full' && is_array($reply)) {
        $sent = request(1);
        $t = $reply; $t['rows'][0]['viewers'] = 0; check(ap_admin_native_reply($t, $sent) === null, 'bridge rejects zero viewers');
        $t = $reply; $t['rows'][0]['evidence'] = 6; check(ap_admin_native_reply($t, $sent) === null, 'bridge rejects six evidence');
        $t = $reply; $t['rows'][1]['actor_id'] = ALICE; check(ap_admin_native_reply($t, $sent) === null, 'bridge rejects duplicate actor');
        $t = $reply; $t['rows'][0]['actor_id'] = 'did:plc:x'; check(ap_admin_native_reply($t, $sent) === null, 'bridge rejects non-URL actor');
        $t = $reply; $t['rows'][0]['extra'] = 1; check(ap_admin_native_reply($t, $sent) === null, 'bridge rejects extra key');
    }
}
// A search stays PHP: the bridge is not asked.
check(!str_contains(render(MARK, 'alice'), MARK), 'search renders PHP');
$db->exec('DELETE FROM ap_home_suppression; DELETE FROM ap_home_downrank_audit');
$reply = compare('full', 'empty');
check(str_contains((string) ($reply['html'] ?? ''), 'No active temporary downranking signals.') && str_contains((string) ($reply['html'] ?? ''), 'No audit entries yet.'), 'empty states');

// Fallbacks: text strip_tags would change, dates only strtotime reads, PHP-only labels and links, odd category JSON, mojibake, the actor bound, admin only.
foreach ([
    'tag in evidence' => "UPDATE events SET summary='fuck you <b>x</b>' WHERE object_id='" . ALICE . "/s/3'",
    'tag in phrase' => "UPDATE ap_home_downrank_terms SET phrase='bad <i>word</i>' WHERE phrase='bad word'",
    'mongolian separator' => "UPDATE events SET summary='nice\u{180E}day' WHERE object_id='" . ALICE . "/s/3'",
    'relative until' => "UPDATE ap_home_suppression SET suppressed_until='tomorrow' WHERE actor_id='" . BOB . "'",
    'did actor' => "INSERT INTO ap_home_suppression(owner_user_id, actor_id, score, categories_json, suppressed_until, updated_at) VALUES (1, 'did:plc:zzz', 1, '[]', '" . at(86400) . "', '')",
    'threads actor' => "INSERT INTO ap_home_suppression(owner_user_id, actor_id, score, categories_json, suppressed_until, updated_at) VALUES (1, 'https://www.threads.net/ap/users/1', 1, '[]', '" . at(86400) . "', '')",
    'bridgy actor' => "INSERT INTO ap_home_suppression(owner_user_id, actor_id, score, categories_json, suppressed_until, updated_at) VALUES (1, 'https://bsky.brid.gy/ap/did:plc:q', 1, '[]', '" . at(86400) . "', '')",
    'other local host' => "INSERT INTO ap_home_suppression(owner_user_id, actor_id, score, categories_json, suppressed_until, updated_at) VALUES (1, 'https://www.mkultra.monster/users/x', 1, '[]', '" . at(86400) . "', '')",
    'number category' => "UPDATE ap_home_suppression SET categories_json='[1]' WHERE actor_id='" . BOB . "'",
    'keyed categories' => "UPDATE ap_home_suppression SET categories_json='{\"a\":\"custom\"}' WHERE actor_id='" . BOB . "'",
    'mojibake summary' => "UPDATE events SET summary='fuck you CafÃ©' WHERE object_id='" . ALICE . "/s/1'",
    'at uri evidence' => "UPDATE events SET object_id='at://did:plc:abc/app.bsky.feed.post/1' WHERE object_id='" . ALICE . "/s/1'",
    'actor bound' => "INSERT INTO ap_home_suppression(owner_user_id, actor_id, score, categories_json, suppressed_until, updated_at) SELECT 1, 'https://bulk.example/users/' || g, 1, '[]', '" . at(86400) . "', '' FROM generate_series(1, 300) g",
] as $name => $sql) {
    schema(); seed(); $db->exec($sql);
    [$status] = native(request(1), $secret); check($status === 422, "$name -> $status");
}
schema(); seed();
[$status] = native(request(2), $secret); check($status === 422, "ordinary account -> $status");
[$status] = native(request(3), $secret); check($status === 422, "disabled admin -> $status");
[$status] = native(request(9), $secret); check($status === 422, "unknown account -> $status");
$bad = request(1); $bad['offset'] = 1; [$status] = native($bad, $secret); check($status === 400, "offset -> $status");
$bad = request(1); $bad['search'] = 'alice'; [$status] = native($bad, $secret); check($status === 400, "search selector -> $status");

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
