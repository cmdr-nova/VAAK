<?php
/**
 * Settings account sections native read review (sandbox only).
 *
 * Renders the genuine Pending follower requests, Featured accounts and Muted
 * accounts sections of the profile view in ap-admin.php against a seeded
 * PostgreSQL fixture, asks a running worker for the same section over
 * /internal/admin/read, and compares the HTML after collapsing whitespace runs.
 * Checks an admin and an ordinary account, that other owners' rows never
 * appear, which warms each side queues, and the fallback cases. Never point it
 * at production: it drops and recreates its tables.
 *
 * Usage: php settings-accounts-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET [QUEUE_REDIS_PORT]
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
$featuredSource = (string) file_get_contents($api . '/ap-featured.php');
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
    // Request-lifetime statics (avatar memo) would carry one render's warms into the next.
    return (string) preg_replace('/\bstatic (\$\w+\s*=)/', '$1', $out);
}
foreach (['AP_REMOTE_AVATAR_FALLBACK', 'AP_LOCAL_AVATAR_FALLBACK', 'AP_WAFRN_APPROVALS_AVATAR'] as $name) {
    if (!preg_match('/^const ' . $name . ' = \'[^\']*\';/m', $dbSource, $m)) throw new RuntimeException('missing ' . $name);
    eval($m[0]);
}
if (!preg_match('/^const AP_FEATURED_ACCOUNTS_MAX = \d+;/m', $featuredSource, $m)) throw new RuntimeException('missing AP_FEATURED_ACCOUNTS_MAX');
eval($m[0]);
foreach (['h', 'relative_time', 'short_host', 'actor_handle', 'actor_display_name', 'actor_display_name_html', 'admin_emoji_html',
    'admin_avatar_img', 'admin_actor_avatar_url', 'admin_profile_hover_attr', 'admin_open_profile_label', 'vaak_is_local_url'] as $fn) eval(declaration($source, $fn));
foreach (['ap_follow_requests_list', 'ap_mutes_list', 'ap_remote_actor_label', 'ap_remote_actor_ensure', 'ap_remote_actor_get', 'ap_remote_actor_id_is_unfederated_local',
    'ap_remote_actor_normalize_username', 'ap_remote_actor_username_is_placeholder', 'ap_remote_media_get', 'ap_profile_sanitize_https_url',
    'ap_local_avatar_url', 'ap_peer_avatar_override', 'ap_emoji_html'] as $fn) eval(declaration($dbSource, $fn));
foreach (['ap_featured_cards', 'ap_featured_list', 'ap_featured_card_for_actor'] as $fn) eval(declaration($featuredSource, $fn));
// Declared substitutions for file/Redis/queue/network helpers, each recording its call.
$effects = [];
function effect(string $kind, string $value): void { $GLOBALS['effects'][] = "$kind:$value"; }
function ap_remote_actor_icon_miss_until(string $actorId): int { return 0; }
function ap_remote_actor_flat_put(string $actorId, ?array $row = null): void {}
function ap_remote_actor_warm_async(string $actorId, bool $force = false): void { effect('actor', $actorId); }
function ap_remote_media_warm_async(string $actorId): void { effect('media', $actorId); }
function ap_remote_media_ensure(string $actorId, string $kind, bool $sync = false): ?string { effect('ensure', $actorId); return null; }
function ap_remote_emoji_ensure_for_display(string $actorId, string $text, bool $allowFetch = false): void { effect('emoji', $actorId); }
function ap_remote_emoji_map_for_host(string $host): array { return []; }
function ap_featured_schema_ensure(): void {}
function ap_featured_media_lib_ensure(): void {}
function ap_fix_utf8(string $s): string { return $s; }
function ap_profile_get(string $key): array {
    $q = ap_db()->prepare('SELECT name, icon_url FROM actor_profile WHERE actor_key = ?'); $q->execute([$key]); $row = $q->fetch();
    if (!$row) { effect('profile-default', $key); return ['name' => $key, 'icon_url' => null]; }
    return ['name' => (string) $row['name'], 'icon_url' => $row['icon_url'] !== null && $row['icon_url'] !== '' ? (string) $row['icon_url'] : null];
}

function region(string $source, string $start, string $end): string {
    $a = strpos($source, $start); if ($a === false) throw new RuntimeException('missing start ' . substr($start, 0, 60));
    $b = strpos($source, $end, $a); if ($b === false) throw new RuntimeException('missing end ' . substr($end, 0, 60));
    return substr($source, $a + strlen($start), $b - $a - strlen($start));
}
$templates = [
    'settings_follow_requests' => '<?php if (!empty($followRequests)): ?>' . region($source,
        "<?php if ((\$settingsNativeFollow = ap_settings_native_body('settings_follow_requests', !empty(\$vaakIsAdmin))) !== null): ?><?= \$settingsNativeFollow ?><?php elseif (!empty(\$followRequests)): ?>",
        "        <?php endif; ?>\n        <?php if (getenv('VAAK_SUPPORTER_CODES') === '1'): ?>") . '<?php endif; ?>',
    'settings_featured' => region($source,
        "<?php if ((\$settingsNativeFeatured = ap_settings_native_body('settings_featured', !empty(\$vaakIsAdmin))) !== null): ?><?= \$settingsNativeFeatured ?><?php else: ?>",
        "        <?php endif; ?>\n\n        <form class=\"composer\" method=\"post\" action=\"?view=profile\" style=\"margin-top:1.25rem\" autocomplete=\"off\">"),
    'settings_mutes' => region($source,
        "<?php if ((\$settingsNativeMutes = ap_settings_native_body('settings_mutes', !empty(\$vaakIsAdmin))) !== null): ?><?= \$settingsNativeMutes ?><?php else: ?>",
        "        <?php endif; ?>\n\n        <?php if ((\$settingsNativeLists"),
];
require $api . '/ap-admin-native.php';

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
const CSRF = 'session<&"token\'';
function ap_auth_csrf_token(): string { return CSRF; }

$db->exec("DROP TABLE IF EXISTS ap_follow_requests; DROP TABLE IF EXISTS ap_featured_accounts; DROP TABLE IF EXISTS ap_mutes; DROP TABLE IF EXISTS remote_actors; DROP TABLE IF EXISTS remote_media_cache; DROP TABLE IF EXISTS actor_profile; DROP TABLE IF EXISTS ap_users; DROP TABLE IF EXISTS ap_media_warm_queue;
CREATE TABLE ap_media_warm_queue(id $idType GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, job_type TEXT NOT NULL, target_key TEXT NOT NULL, media_kind TEXT NOT NULL, status TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 8, next_attempt_at TEXT, claimed_at TEXT, last_error TEXT, created_at TEXT, updated_at TEXT, UNIQUE(job_type, target_key, media_kind));
CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, actor_key TEXT, actor_id TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
INSERT INTO ap_users VALUES (1,'operator','op_er','https://mkultra.monster/users/op_er',1,NULL),(2,'ordinary','ord',NULL,0,NULL),(3,'other','other','https://mkultra.monster/users/other',0,NULL);
CREATE TABLE ap_follow_requests(id $idType PRIMARY KEY, owner_actor_id TEXT NOT NULL, actor_id TEXT NOT NULL, inbox TEXT, shared_inbox TEXT, username TEXT, follow_activity_id TEXT, status TEXT NOT NULL DEFAULT 'pending', created_at TEXT NOT NULL);
CREATE TABLE ap_featured_accounts(id $idType PRIMARY KEY, owner_user_id $idType NOT NULL, target_actor_id TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL);
CREATE TABLE ap_mutes(owner_user_id $idType NOT NULL, actor_id TEXT NOT NULL, created_at TEXT NOT NULL, notifications INTEGER NOT NULL DEFAULT 1, host TEXT, PRIMARY KEY(owner_user_id, actor_id));
CREATE TABLE remote_actors(actor_id TEXT PRIMARY KEY, username TEXT, display_name TEXT, host TEXT, icon_source_url TEXT, image_source_url TEXT, updated_at TEXT NOT NULL DEFAULT '', summary TEXT, profile_json TEXT);
CREATE TABLE remote_media_cache(actor_id TEXT NOT NULL, kind TEXT NOT NULL, public_url TEXT, fetched_at TEXT, PRIMARY KEY(actor_id, kind));
CREATE TABLE actor_profile(actor_key TEXT PRIMARY KEY, name TEXT, icon_url TEXT)");

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
function request(string $view, int $owner, bool $admin, string $mode = 'full'): array {
    return ['protocol_version' => 1, 'owner_id' => $owner, 'csrf' => CSRF, 'view' => $view, 'mode' => $mode,
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => 'UTC', 'viewer_admin' => $admin];
}
function queued(): array {
    global $queuePort; if ($queuePort === null) return [];
    $out = []; exec('redis-cli -p ' . (int) $queuePort . ' LRANGE vaak:queue:ap_actor_warm 0 -1', $out);
    $ids = array_values(array_unique(array_filter(array_map(fn($j) => (string) (json_decode($j, true)['actor_id'] ?? ''), $out), 'strlen'))); sort($ids); return $ids;
}
function clear_queue(): void { global $queuePort, $db; if ($queuePort !== null) exec('redis-cli -p ' . (int) $queuePort . ' FLUSHDB'); $db->exec('DELETE FROM ap_media_warm_queue'); }
function media_queued(): array { global $db; $ids = $db->query("SELECT target_key FROM ap_media_warm_queue WHERE job_type = 'actor_media' AND status = 'pending'")->fetchAll(PDO::FETCH_COLUMN); sort($ids); return $ids; }
function render(string $view, int $owner, bool $admin): string {
    global $templates, $db;
    $GLOBALS['ap_remote_actor_memo'] = []; $GLOBALS['ap_remote_media_memo'] = []; $GLOBALS['vaak_ap_actor_memo'] = [];
    $u = $db->query('SELECT * FROM ap_users WHERE id = ' . $owner)->fetch();
    $vaakUser = $u; $vaakIsAdmin = $admin; $vaakActorKey = (string) ($u['actor_key'] ?? 'cmdr_nova');
    $vaakActorId = rtrim((string) ($u['actor_id'] ?? ('https://mkultra.monster/users/' . $vaakActorKey)), '/');
    $followRequests = ap_follow_requests_list(rtrim((string) ($vaakUser['actor_id'] ?? $vaakActorId), '/'));
    $mutes = ap_mutes_list($owner);
    ob_start(); eval('?>' . $templates[$view]); return collapse((string) ob_get_clean());
}
function compare(string $view, int $owner, bool $admin, string $mode): array {
    for ($try = 0; $try < 3; $try++) {
        $second = time(); $GLOBALS['effects'] = []; clear_queue();
        $php = render($view, $owner, $admin); [$status, $reply] = native(request($view, $owner, $admin, $mode), $GLOBALS['secret']);
        if (time() === $second) break;
    }
    return [$php, $status, $reply];
}

$now = time();
$ago = fn(int $s, bool $iso = true) => $iso ? gmdate('c', $now - $s) : gmdate('Y-m-d H:i:s', $now - $s);
$fresh = gmdate('c', $now - 3600);
function seed(array $s): void {
    global $db;
    $db->exec('DELETE FROM ap_follow_requests; DELETE FROM ap_featured_accounts; DELETE FROM ap_mutes; DELETE FROM remote_actors; DELETE FROM remote_media_cache; DELETE FROM actor_profile');
    $ins = fn(string $sql, array $rows) => array_map(fn($r) => $db->prepare($sql)->execute($r), $rows);
    $ins('INSERT INTO ap_follow_requests (id, owner_actor_id, actor_id, username, status, created_at) VALUES (?,?,?,?,?,?)', $s['follow'] ?? []);
    $ins('INSERT INTO ap_featured_accounts (id, owner_user_id, target_actor_id, position, created_at) VALUES (?,?,?,?,?)', $s['featured'] ?? []);
    $ins('INSERT INTO ap_mutes (owner_user_id, actor_id, created_at) VALUES (?,?,?)', $s['mutes'] ?? []);
    $ins('INSERT INTO remote_actors (actor_id, username, display_name, host, icon_source_url) VALUES (?,?,?,?,?)', $s['actors'] ?? []);
    $ins('INSERT INTO remote_media_cache (actor_id, kind, public_url, fetched_at) VALUES (?,?,?,?)', $s['media'] ?? []);
    $ins('INSERT INTO actor_profile (actor_key, name, icon_url) VALUES (?,?,?)', $s['profiles'] ?? []);
}
$me1 = 'https://mkultra.monster/users/op_er'; $me2 = 'https://mkultra.monster/users/ord'; $me3 = 'https://mkultra.monster/users/other';
// Every case seeds the same rows for owners 1 and 2 plus decoys for owner 3, whose text contains "decoy".
$cases = [
    'empty' => [[], [], []],
    'populated' => [[
        'follow' => [
            [1, $me1, 'https://f.example/users/ann', 'ann', 'pending', $ago(50)], [2, $me1, 'https://f.example/users/b<&"\'', '  ', 'pending', $ago(40)],
            [3, $me1, 'https://f.example/users/old', 'old', 'approved', $ago(30)], [4, $me1 . '/', 'https://f.example/users/slash', 'slash', 'pending', $ago(20)],
            [5, $me2, 'https://f.example/users/ann', 'ann', 'pending', $ago(50)], [6, $me2, 'https://f.example/users/tie', null, 'pending', $ago(50)],
            [7, $me3, 'https://f.example/users/decoy', 'decoy', 'pending', $ago(10)],
        ],
        'featured' => [
            [1, 1, 'https://g.example/users/gina', 2, $ago(5)], [2, 1, 'https://g.example/@hal/', 1, $ago(5)], [3, 1, 'http://insecure.example/u', 0, $ago(5)],
            [4, 1, 'https://mkultra.monster/users/Friend', 1, $ago(5)], [5, 1, 'https://g.example/users/nometa', 3, $ago(5)], [6, 1, 'https://g.example/@%C3%A9t%C3%A9', 4, $ago(5)],
            [7, 2, 'https://g.example/users/gina', 0, $ago(5)], [8, 2, 'https://mkultra.monster/users/noname', 0, $ago(5)],
            [9, 3, 'https://g.example/users/decoy', 0, $ago(5)],
        ],
        'mutes' => [
            [1, 'https://m.example/users/mia', $ago(100)], [1, 'https://m.example/users/tie', $ago(100)], [1, 'https://m.example/users/<b>&"', $ago(200, false)],
            [1, 'https://waffles.baeddel.social/fediverse/blog/admin', $ago(300)], [1, 'https://m.example/users/new/', $ago(400)],
            [1, 'https://m.example/users/stale', $ago(500)], [1, 'https://bsky.app/profile/alice.bsky.social', $ago(600)], [1, 'https://bsky.app/profile/did:plc:abc', $ago(700)],
            [2, 'https://m.example/users/mia', $ago(100)], [3, 'https://m.example/users/decoy', $ago(100)],
        ],
        'actors' => [
            ['https://g.example/users/gina', 'gina', 'Gina <G>', 'g.example', null], ['https://g.example/@hal', '', '  ', '', null],
            ['https://m.example/users/mia', 'mia', 'Mia & co', 'm.example', null], ['https://m.example/users/tie', 'tie', '', null, null],
            ['https://m.example/users/<b>&"', 'esc', 'Esc "x"', 'm.example', null],
            ['https://waffles.baeddel.social/fediverse/blog/admin', 'admin', 'Wafrn', 'waffles.baeddel.social', null],
            ['https://m.example/users/decoy', 'decoy', 'decoy', 'm.example', null],
            ['https://m.example/users/stale', 'stale', 'Stale', 'm.example', null], ['https://bsky.app/profile/alice.bsky.social', 'alice.bsky.social', 'Alice', 'bsky.app', null],
        ],
        'media' => [
            ['https://g.example/users/gina', 'avatar', 'https://cdn.example/gina.webp', $fresh], ['https://g.example/@hal', 'avatar', 'https://cdn.example/hal.png?a=1&b=2', ''],
            ['https://g.example/users/nometa', 'avatar', 'https://cdn.example/n.webp', $fresh], ['https://g.example/@%C3%A9t%C3%A9', 'avatar', 'https://cdn.example/e.webp', $fresh],
            ['https://m.example/users/mia', 'avatar', 'https://cdn.example/mia.webp', $fresh], ['https://m.example/users/tie', 'avatar', 'https://cdn.example/tie.webp', ''],
            ['https://m.example/users/<b>&"', 'avatar', 'https://cdn.example/esc.webp', gmdate('Y-m-d H:i:s', $now - 86400) . '.5+00'],
            ['https://m.example/users/new', 'avatar', 'https://cdn.example/new.webp', $fresh], ['https://m.example/users/decoy', 'avatar', 'https://cdn.example/decoy.webp', $fresh],
            ['https://g.example/users/decoy', 'avatar', 'https://cdn.example/decoy.webp', $fresh],
            ['https://m.example/users/stale', 'avatar', 'https://cdn.example/stale.webp', gmdate('c', $now - 8 * 86400)],
            ['https://bsky.app/profile/alice.bsky.social', 'avatar', 'https://cdn.example/alice.webp', $fresh], ['https://bsky.app/profile/did:plc:abc', 'avatar', 'https://cdn.example/did.webp', gmdate('c', $now - 9 * 86400)],
        ],
        'profiles' => [['friend', 'Friend <F>', 'https://mkultra.monster/img/f.webp'], ['noname', null, 'http://bad.example/x']],
    ], ['settings_mutes' => ['https://bsky.app/profile/did:plc:abc', 'https://m.example/users/new']], ['settings_mutes' => ['https://bsky.app/profile/did:plc:abc', 'https://m.example/users/stale']]],
];
foreach ($cases as $name => [$seedRows, $expectWarm, $expectMedia]) {
    seed($seedRows);
    foreach (['settings_follow_requests', 'settings_featured', 'settings_mutes'] as $view) foreach ([[1, true], [2, false]] as [$owner, $admin]) foreach (['full', 'shell'] as $mode) {
        [$php, $status, $reply] = compare($view, $owner, $admin, $mode);
        $label = "$name/$view/owner$owner/$mode";
        check($status === 200, "$label status $status");
        $html = collapse((string) ($reply['html'] ?? ''));
        check($html === $php, "$label html");
        if ($html !== $php) {
            file_put_contents(sys_get_temp_dir() . "/accounts-$name-$view-$owner-$mode.php.html", $php);
            file_put_contents(sys_get_temp_dir() . "/accounts-$name-$view-$owner-$mode.native.html", $html);
        }
        check(!str_contains($html, 'decoy'), "$label other owner's rows");
        $want = $owner === 1 ? ($expectWarm[$view] ?? []) : [];
        $phpWarm = array_values(array_unique(array_map(fn($e) => substr($e, 6), array_filter($GLOBALS['effects'], fn($e) => str_starts_with($e, 'actor:'))))); sort($phpWarm);
        $phpMedia = array_values(array_unique(array_map(fn($e) => substr($e, 6), array_filter($GLOBALS['effects'], fn($e) => str_starts_with($e, 'media:'))))); sort($phpMedia);
        $wantMedia = $owner === 1 ? ($expectMedia[$view] ?? []) : [];
        check($phpMedia === $wantMedia, "$label php media warms " . json_encode($phpMedia));
        check(media_queued() === $wantMedia, "$label native media warms " . json_encode(media_queued()));
        $other = array_values(array_filter($GLOBALS['effects'], fn($e) => !str_starts_with($e, 'actor:') && !str_starts_with($e, 'media:')));
        check($phpWarm === $want, "$label php actor warms " . json_encode($phpWarm));
        check($other === [], "$label php other effects " . json_encode($other));
        if ($queuePort !== null) check(queued() === $want, "$label native warms " . json_encode(queued()));
        $sent = request($view, $owner, $admin, $mode);
        check(is_array($reply) && ap_admin_native_reply($reply, $sent) === ($reply['html'] ?? null), "$label bridge accepts reply");
        if ($name === 'populated' && $owner === 1 && is_array($reply)) {
            check(strlen($php) > 300 && ($reply['source_count'] ?? 0) > 0, "$label populated section is not empty");
            $tampered = $reply; $tampered['rows'][0]['extra'] = 1;
            check(ap_admin_native_reply($tampered, $sent) === null, "$label bridge rejects extra row key");
            $tampered = $reply; $tampered['request_binding']['viewer_admin'] = !$admin;
            check(ap_admin_native_reply($tampered, $sent) === null, "$label bridge rejects other role binding");
        }
    }
}

// Fallbacks: rows PHP answers with a fetch, warm or emoji lookup, and every refusal, render the PHP section.
$base = $cases['populated'][0];
$fallbacks = [
    'settings_featured' => [
        'uncached avatar' => ['featured' => [[1, 1, 'https://g.example/users/cold', 0, $ago(5)]]],
        'local without profile' => ['featured' => [[1, 1, 'https://mkultra.monster/users/ghost', 0, $ago(5)]]],
    ],
    'settings_mutes' => [
        'no avatar' => ['mutes' => [[1, 'https://m.example/users/bare', $ago(5)]]],
        'unsafe avatar' => ['mutes' => [[1, 'https://m.example/users/u', $ago(5)]], 'media' => [['https://m.example/users/u', 'avatar', 'https://127.0.0.1/x.webp', $fresh]]],
        'emoji name' => ['mutes' => [[1, 'https://m.example/users/e', $ago(5)]], 'actors' => [['https://m.example/users/e', 'e', 'Hi :blob:', 'm.example', null]], 'media' => [['https://m.example/users/e', 'avatar', 'https://cdn.example/e.webp', $fresh]]],
        'local actor' => ['mutes' => [[1, 'https://mkultra.monster/users/friend', $ago(5)]]],
        'odd date' => ['mutes' => [[1, 'https://m.example/users/mia', 'yesterday']], 'actors' => $base['actors'], 'media' => $base['media']],
    ],
];
foreach ($fallbacks as $view => $list) foreach ($list as $name => $rows) {
    seed($rows); clear_queue();
    [$status] = native(request($view, 1, true), $secret); check($status === 422, "$view $name -> $status");
}
seed($base);
foreach (['settings_follow_requests', 'settings_featured', 'settings_mutes'] as $view) {
    [$status] = native(request($view, 1, false), $secret); check($status === 422, "$view role mismatch -> $status");
    [$status] = native(request($view, 9, false), $secret); check($status === 422, "$view unknown account -> $status");
    $bad = request($view, 1, true); unset($bad['viewer_admin']); [$status] = native($bad, $secret); check($status === 400, "$view no role -> $status");
    $bad = request($view, 1, true); $bad['offset'] = 1; [$status] = native($bad, $secret); check($status === 400, "$view offset -> $status");
}
$db->exec("UPDATE ap_users SET disabled_at='2026-10-19 00:00:00' WHERE id=2");
[$status] = native(request('settings_mutes', 2, false), $secret); check($status === 422, "disabled account -> $status");
$db->exec('UPDATE ap_users SET disabled_at=NULL WHERE id=2');
seed([]); $ins = $db->prepare('INSERT INTO ap_mutes (owner_user_id, actor_id, created_at) VALUES (?,?,?)');
for ($i = 1; $i <= 1001; $i++) $ins->execute([2, "https://m.example/users/n$i", $fresh]);
[$status] = native(request('settings_mutes', 2, false), $secret); check($status === 422, "1001 mutes -> $status");

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
