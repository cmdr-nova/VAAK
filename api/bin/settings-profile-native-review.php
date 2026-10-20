<?php
/**
 * Settings profile form native read review (sandbox only).
 *
 * Renders the genuine save_profile form of the profile view in ap-admin.php,
 * with the genuine ap_profile_get, ap_fix_utf8 and badge helpers, against a
 * seeded PostgreSQL fixture, asks a running worker for the same form over
 * /internal/admin/read, and compares the HTML after collapsing whitespace runs.
 * Checks an admin and an ordinary account, the Redis profile cache when the
 * worker shares one (optional CACHE_REDIS as port/db), the old-schema column
 * defaults and the fallback cases. Never point it at production: it drops and
 * recreates its tables.
 *
 * Usage: php settings-profile-native-review.php 'pgsql:host=127.0.0.1;dbname=X_fixture' INTEGER|BIGINT http://127.0.0.1:PORT/internal/admin/read SECRET [CACHE_REDIS]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
[$self, $dsn, $idType, $url, $secret] = $argv + [null, null, 'INTEGER', null, null];
if (!$dsn || !in_array($idType, ['INTEGER', 'BIGINT'], true) || !$url || strlen((string) $secret) < 32) {
    fwrite(STDERR, "usage: dsn INTEGER|BIGINT url secret [cache-redis port/db]\n"); exit(2);
}
if (!str_contains($dsn, 'fixture')) { fwrite(STDERR, "refusing a database without 'fixture' in its name\n"); exit(2); }
$cacheRedis = isset($argv[5]) && preg_match('#^(\d+)/(\d+)$#', $argv[5], $cm) ? [(int) $cm[1], (int) $cm[2]] : null;

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
if (!preg_match('/^const AP_LOCAL_AVATAR_FALLBACK = \'[^\']*\';/m', $dbSource, $m)) throw new RuntimeException('missing AP_LOCAL_AVATAR_FALLBACK');
eval($m[0]);
foreach (['h', 'relative_time'] as $fn) eval(declaration($source, $fn));
foreach (['ap_profile_get', 'ap_profile_defaults', 'ap_profile_badge_catalog', 'ap_profile_normalize_badges', 'ap_profile_authorized_badges',
    'ap_profile_supporter_awarded', 'ap_fix_utf8', 'ap_local_avatar_url', 'ap_profile_sanitize_https_url'] as $fn) eval(declaration($dbSource, $fn));
// Declared substitutions for the Redis cache, each recording its call.
$effects = [];
function ap_redis_json_get(string $key): ?array {
    global $cacheRedis; if ($cacheRedis === null) return null;
    $out = []; exec('redis-cli -p ' . $cacheRedis[0] . ' -n ' . $cacheRedis[1] . ' --raw GET ' . escapeshellarg($key), $out);
    $decoded = json_decode(implode("\n", $out), true); return is_array($decoded) ? $decoded : null;
}
function ap_redis_json_set(string $key, array $value, int $ttl): bool { $GLOBALS['effects'][] = 'cache-set'; return true; }
function cache_put(string $key, string $json): void {
    global $cacheRedis; exec('redis-cli -p ' . $cacheRedis[0] . ' -n ' . $cacheRedis[1] . ' SET ' . escapeshellarg('vaak:profile:v1:' . hash('sha256', trim($key))) . ' ' . escapeshellarg($json));
}
function cache_clear(): void { global $cacheRedis; if ($cacheRedis !== null) exec('redis-cli -p ' . $cacheRedis[0] . ' -n ' . $cacheRedis[1] . ' FLUSHDB'); }

function region(string $source, string $start, string $end): string {
    $a = strpos($source, $start); if ($a === false) throw new RuntimeException('missing start ' . substr($start, 0, 60));
    $b = strpos($source, $end, $a); if ($b === false) throw new RuntimeException('missing end ' . substr($end, 0, 60));
    return substr($source, $a + strlen($start), $b - $a - strlen($start));
}
$identity = region($source, "} else {\n    \$vaakActorKey = (string) (\$vaakUser['actor_key'] ?? 'cmdr_nova');",
    "\n}\n\$GLOBALS['vaak_actor_key']");
$identity = "\$vaakActorKey = (string) (\$vaakUser['actor_key'] ?? 'cmdr_nova');" . $identity;
$template = region($source, "<?php elseif (\$view === 'profile' || \$view === 'atmosphere'): ?>", "        <?php if (\$view === 'profile'): ?>")
    . region($source, "<?php if ((\$settingsNativeProfile = ap_settings_native_body('settings_profile', !empty(\$vaakIsAdmin))) !== null): ?><?= \$settingsNativeProfile ?><?php else: ?>",
        "        <?php endif; ?>\n\n        <?php if ((\$settingsNativeLinks = ap_settings_native_body('settings_links'");
require $api . '/ap-admin-native.php';

$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
const CSRF = 'session<&"token\'';
function ap_auth_csrf_token(): string { return CSRF; }
$profileTable = 'CREATE TABLE actor_profile ' . region($dbSource, 'CREATE TABLE IF NOT EXISTS actor_profile ', ";\n");
$extraColumns = 'ALTER TABLE actor_profile ADD COLUMN indexable INTEGER NOT NULL DEFAULT 1, ADD COLUMN collection_consent INTEGER NOT NULL DEFAULT 1,
    ADD COLUMN vanity_verified INTEGER NOT NULL DEFAULT 0, ADD COLUMN auto_follow_back INTEGER NOT NULL DEFAULT 0, ADD COLUMN anti_ai_marker INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN auto_delete_posts_7d INTEGER NOT NULL DEFAULT 0';
function schema(bool $old): void {
    global $db, $profileTable, $extraColumns, $idType;
    $db->exec("DROP TABLE IF EXISTS actor_profile; DROP TABLE IF EXISTS ap_users; DROP TABLE IF EXISTS ap_supporter_codes;
    CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT, email TEXT, actor_key TEXT, actor_id TEXT, is_admin INTEGER NOT NULL DEFAULT 0, disabled_at TEXT);
    INSERT INTO ap_users VALUES (1,'operator','op@example.com<&>','op_er','https://mkultra.monster/users/op_er/',1,NULL),(2,NULL,NULL,'ord',NULL,0,NULL),(3,'  ','x@example.com','spaced',NULL,0,NULL);
    $profileTable");
    if (!$old) $db->exec($extraColumns);
    else $db->exec('ALTER TABLE actor_profile DROP COLUMN reply_policy, DROP COLUMN trending_enabled, DROP COLUMN asks_enabled, DROP COLUMN webmentions_enabled, DROP COLUMN hide_profile_boosts');
}

$checks = 0; $failures = [];
function check(bool $ok, string $label): void { global $checks, $failures; $checks++; if (!$ok) $failures[] = $label; }
function collapse(string $html): string { return trim((string) preg_replace('/\s+/u', ' ', $html)); }
// Whitespace inside textareas and attribute values reaches the saved profile, so those compare exactly.
function exact_parts(string $html): array { preg_match_all('#<textarea[^>]*>(.*?)</textarea>|value="([^"]*)"#s', $html, $m, PREG_SET_ORDER); return array_map(fn($x) => $x[0], $m); }
function native(array $request, string $token): array {
    global $url;
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($request), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Vaak-Admin-Token: ' . $token]]);
    $body = (string) curl_exec($c); $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    return [$status, json_decode($body, true)];
}
function request(int $owner, bool $admin, string $mode = 'full'): array {
    return ['protocol_version' => 1, 'owner_id' => $owner, 'csrf' => CSRF, 'view' => 'settings_profile', 'mode' => $mode,
        'offset' => 0, 'approvals_enabled' => getenv('VAAK_REGISTRATION_APPROVALS') === '1', 'now' => time(), 'php_timezone' => 'UTC', 'viewer_admin' => $admin];
}
function render(int $owner): string {
    global $template, $identity, $db;
    $vaakUser = $db->query('SELECT * FROM ap_users WHERE id = ' . $owner)->fetch();
    eval($identity);
    $profile = ap_profile_get($vaakActorKey); $view = 'profile';
    ob_start(); eval('?>' . $template); $GLOBALS['raw'] = (string) ob_get_clean(); return collapse($GLOBALS['raw']);
}
function compare(int $owner, bool $admin, string $mode): array {
    for ($try = 0; $try < 3; $try++) {
        $second = time(); $GLOBALS['effects'] = [];
        $php = render($owner); [$status, $reply] = native(request($owner, $admin, $mode), $GLOBALS['secret']);
        if (time() === $second) break;
    }
    return [$php, $status, $reply];
}
$cols = ['actor_key', 'name', 'summary', 'attachment_json', 'icon_url', 'image_url', 'manually_approves', 'discoverable', 'auto_unblur_sensitive', 'automated',
    'reply_policy', 'quote_policy', 'forum_signature', 'profile_badges', 'hide_profile_replies', 'hide_profile_boosts', 'algorithm_enabled', 'trending_enabled',
    'downranking_enabled', 'asks_enabled', 'webmentions_enabled', 'updated_at', 'indexable', 'collection_consent', 'vanity_verified', 'auto_follow_back', 'auto_delete_posts_7d'];
function profile_row(string $key, array $over): void {
    global $db, $cols;
    $row = ['actor_key' => $key, 'name' => 'Name ' . $key, 'summary' => 'Plain bio', 'attachment_json' => '[]', 'icon_url' => null, 'image_url' => null,
        'manually_approves' => 0, 'discoverable' => 1, 'auto_unblur_sensitive' => 0, 'automated' => 0, 'reply_policy' => 'anyone', 'quote_policy' => 'anyone',
        'forum_signature' => '', 'profile_badges' => '[]', 'hide_profile_replies' => 0, 'hide_profile_boosts' => 0, 'algorithm_enabled' => 1, 'trending_enabled' => 1,
        'downranking_enabled' => 1, 'asks_enabled' => 1, 'webmentions_enabled' => 1, 'updated_at' => gmdate('Y-m-d H:i:s', time() - 7200),
        'indexable' => 1, 'collection_consent' => 1, 'vanity_verified' => 0, 'auto_follow_back' => 0, 'auto_delete_posts_7d' => 0];
    $row = array_merge($row, $over);
    $present = array_column($db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'actor_profile'")->fetchAll(), 'column_name');
    $row = array_intersect_key($row, array_flip($present));
    $db->prepare('INSERT INTO actor_profile (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
}
$mastodonLink = '<a href="https://site.example/me" target="_blank" rel="nofollow noopener noreferrer me" translate="no"><span class="invisible">https://</span><span class="">site.example/me</span><span class="invisible"></span></a>';
$atts = json_encode([
    ['name' => 'Site <&>', 'value' => $mastodonLink, 'verified_at' => '2026-10-01T10:00:00Z'],
    ['name' => 'Plain', 'value' => 'just text &amp; more'],
    ['name' => 'Bare', 'value' => '<a href=\'https://bare.example/x\'></a>'],
    ['name' => '', 'value' => ''],
    ['name' => 'Num', 'value' => 42, 'verified_at' => ''],
    ['name' => 'Quoted', 'value' => '<span title="a>b">kept</span> &#x27;q&#39; &apos;s&apos; &#128512;'],
]);
$cases = [
    'full' => [
        1 => ['name' => 'Op <&"\'>', 'summary' => "<p>Hello &amp; welcome</p><p>Line two<br>three<br />four</p>\n\n\n\n<p>Tail &quot;x&quot; a & b</p>",
            'attachment_json' => $atts, 'icon_url' => 'http://insecure.example/a.png', 'image_url' => 'https://img.example/h.png?a=1&b="2"',
            'manually_approves' => 1, 'discoverable' => 0, 'auto_unblur_sensitive' => 1, 'automated' => 1, 'reply_policy' => 'followers', 'quote_policy' => 'nobody',
            'forum_signature' => "  sig <b>  \n", 'profile_badges' => '["trans","admin","supporter","bogus"," cat ","dog","coffee","tea","gamer","music","art"]',
            'hide_profile_replies' => 1, 'algorithm_enabled' => 0, 'trending_enabled' => 0, 'downranking_enabled' => 0, 'asks_enabled' => 0, 'webmentions_enabled' => 0,
            'indexable' => 0, 'collection_consent' => 0, 'vanity_verified' => 1, 'auto_follow_back' => 1, 'auto_delete_posts_7d' => 1, 'updated_at' => gmdate('Y-m-d H:i:s', time() - 30)],
        2 => ['name' => '', 'summary' => '<p>Rich <a href="https://x.example/">link</a> &amp; <strong>bold</strong></p>', 'attachment_json' => '[{"name":"A","value":"B"}]',
            'icon_url' => 'https://mkultra.monster/img/profile/ord.webp', 'quote_policy' => 'weird', 'profile_badges' => '[]', 'updated_at' => ''],
        3 => ['summary' => "First\n\n\n\nsecond &#128512; &nbsp;x &unknownish", 'attachment_json' => '[]', 'profile_badges' => 'not json'],
    ],
];
$fallbacks = [
    'no profile row' => null,
    'mojibake name' => ['name' => 'CafÃ© fan'],
    'mojibake attachment' => ['attachment_json' => '[{"name":"Ã©","value":"x"}]'],
    'invalid attachment json' => ['attachment_json' => '{bad'],
    'attachment map' => ['attachment_json' => '{"a":{"name":"x","value":"y"}}'],
    'html5-only entity' => ['summary' => 'Wait&hellip;'],
    'heart tag' => ['summary' => 'I <3 cats'],
    'comment' => ['summary' => 'a <!-- c --> b'],
    'badge map' => ['profile_badges' => '{"a":"trans"}'],
    'odd date' => ['updated_at' => 'yesterday'],
];

cache_clear();
foreach ([false, true] as $old) {
    schema($old);
    foreach ($cases['full'] as $owner => $over) profile_row((string) $db->query("SELECT actor_key FROM ap_users WHERE id = $owner")->fetchColumn(), $over);
    foreach ([[1, true], [2, false], [3, false]] as [$owner, $admin]) foreach (['full', 'shell'] as $mode) {
        [$php, $status, $reply] = compare($owner, $admin, $mode);
        $label = ($old ? 'old' : 'current') . "/owner$owner/$mode";
        check($status === 200, "$label status $status");
        $html = collapse((string) ($reply['html'] ?? ''));
        check($html === $php, "$label html");
        check(exact_parts((string) ($reply['html'] ?? '')) === exact_parts($GLOBALS['raw']), "$label exact textarea and values");
        if ($html !== $php) {
            file_put_contents(sys_get_temp_dir() . "/profile-$owner-$mode-" . (int) $old . '.php.html', $php);
            file_put_contents(sys_get_temp_dir() . "/profile-$owner-$mode-" . (int) $old . '.native.html', $html);
        }
        check(strlen($php) > 3000 && str_contains($php, 'name="action" value="save_profile"'), "$label PHP form rendered");
        check(($reply['rows'] ?? null) === [['actor_key' => (string) $db->query("SELECT actor_key FROM ap_users WHERE id = $owner")->fetchColumn(), 'source' => 'db']], "$label rows");
        $sent = request($owner, $admin, $mode);
        check(is_array($reply) && ap_admin_native_reply($reply, $sent) === ($reply['html'] ?? null), "$label bridge accepts reply");
        if ($owner === 1 && is_array($reply)) {
            $tampered = $reply; $tampered['rows'][0]['extra'] = 1;
            check(ap_admin_native_reply($tampered, $sent) === null, "$label bridge rejects extra row key");
            $tampered = $reply; $tampered['rows'][0]['source'] = 'guess';
            check(ap_admin_native_reply($tampered, $sent) === null, "$label bridge rejects unknown source");
            $tampered = $reply; $tampered['rows'][] = $tampered['rows'][0]; $tampered['source_count'] = 2;
            check(ap_admin_native_reply($tampered, $sent) === null, "$label bridge rejects two profiles");
            $tampered = $reply; $tampered['request_binding']['viewer_admin'] = !$admin;
            check(ap_admin_native_reply($tampered, $sent) === null, "$label bridge rejects other role binding");
        }
    }
}

// The Redis profile cache wins over the row when PHP would accept it, with PHP's defaults for keys it lacks.
if ($cacheRedis !== null) {
    schema(false);
    foreach ($cases['full'] as $owner => $over) profile_row((string) $db->query("SELECT actor_key FROM ap_users WHERE id = $owner")->fetchColumn(), $over);
    $cached = ['actor_key' => 'op_er', 'name' => 'Cached <name>', 'summary' => '<p>From cache</p>', 'attachment' => [['name' => 'C', 'value' => 'https://c.example', 'verified_at' => '2026-10-02']],
        'icon_url' => null, 'image_url' => null, 'manually_approves' => false, 'discoverable' => true, 'indexable' => true, 'collection_consent' => true,
        'vanity_verified' => false, 'reply_policy' => 'nobody', 'quote_policy' => 'followers', 'forum_signature' => 'cached sig',
        'profile_badges' => ['admin', 'trans'], 'algorithm_enabled' => true, 'trending_enabled' => false, 'downranking_enabled' => true, 'updated_at' => gmdate('Y-m-d H:i:s', time() - 90000)];
    $variants = [
        'hit' => [json_encode($cached), 'cache'],
        'hit with null policy' => [json_encode(['reply_policy' => null] + $cached), 'cache'],
        'trending not bool' => [json_encode(['trending_enabled' => 1] + $cached), 'db'],
        'null summary' => [json_encode(['summary' => null] + $cached), 'db'],
        'list' => ['["op_er"]', 'db'],
        'garbage' => ['{nope', 'db'],
    ];
    foreach ($variants as $name => [$json, $want]) foreach (['full', 'shell'] as $mode) {
        cache_clear(); cache_put('op_er', $json);
        [$php, $status, $reply] = compare(1, true, $mode);
        $label = "cache $name/$mode";
        check($status === 200, "$label status $status");
        $html = collapse((string) ($reply['html'] ?? ''));
        check($html === $php, "$label html");
        check(exact_parts((string) ($reply['html'] ?? '')) === exact_parts($GLOBALS['raw']), "$label exact textarea and values");
        if ($html !== $php) { file_put_contents(sys_get_temp_dir() . "/profile-cache-$mode.php.html", $php); file_put_contents(sys_get_temp_dir() . "/profile-cache-$mode.native.html", $html); }
        check(($reply['rows'][0]['source'] ?? null) === $want, "$label source " . json_encode($reply['rows'][0]['source'] ?? null));
        check(($GLOBALS['effects'] === []) === ($want === 'cache'), "$label PHP cache write " . json_encode($GLOBALS['effects']));
    }
    cache_clear(); cache_put('op_er', json_encode(['name' => 'CafÃ© cached'] + $cached));
    [$status] = native(request(1, true), $secret); check($status === 422, "cache mojibake -> $status");
    cache_clear();
}

// Fallbacks: text PHP would repair or decode beyond the proven subset, and every refusal, render the PHP form.
foreach ($fallbacks as $name => $over) {
    schema(false); if ($over !== null) profile_row('op_er', $over);
    $php = render(1); check(str_contains($php, 'value="save_profile"'), "$name PHP form rendered");
    [$status] = native(request(1, true), $secret); check($status === 422, "$name -> $status");
}
schema(false); profile_row('op_er', []); profile_row('ord', []);
[$status] = native(request(1, false), $secret); check($status === 422, "role mismatch -> $status");
[$status] = native(request(9, false), $secret); check($status === 422, "unknown account -> $status");
$bad = request(1, true); unset($bad['viewer_admin']); [$status] = native($bad, $secret); check($status === 400, "no role -> $status");
$bad = request(1, true); $bad['offset'] = 1; [$status] = native($bad, $secret); check($status === 400, "offset -> $status");
$db->exec("UPDATE ap_users SET disabled_at='2026-10-19 00:00:00' WHERE id=2");
[$status] = native(request(2, false), $secret); check($status === 422, "disabled account -> $status");

echo json_encode(['id_type' => $idType, 'checks' => $checks, 'failures' => $failures], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures ? 1 : 0);
