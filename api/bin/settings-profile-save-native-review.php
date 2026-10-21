<?php
/**
 * Settings > Profile save native review (S4a, sandbox only).
 *
 * Two modes:
 *
 *   --corpus OUT.json
 *     Runs genuine PHP (strip_tags, filter_var and the ap-db.php profile helpers)
 *     over generated and hand-picked inputs and writes the answers for the Rust
 *     test settings_profile_save::tests::actual_php_helper_corpus.
 *
 *   DSN INTEGER|BIGINT URL SECRET   (env PROFILE_SAVE_REDIS, PROFILE_SAVE_SCRATCH)
 *     For each scenario, seeds a PostgreSQL fixture, runs the genuine save path of
 *     the save_profile action (ap_auth_user_set_email, then ap_profile_save) and
 *     records the ap_users and actor_profile rows, the result, the after-save
 *     flags and what ap_profile_after_save left in Redis and on disk; reseeds,
 *     runs ap_settings_profile_save_native against a running worker (started
 *     with the same Redis as VAAK_REDIS_URL, VAAK_API_ROOT=SCRATCH/site/api and
 *     VAAK_ADMIN_TL_CACHE_DIR=SCRATCH/tl) and compares. Fallback scenarios must answer null and leave the rows
 *     untouched. rel=me pages are not fetched (link preview is stubbed off in PHP
 *     and fixture hosts are .invalid for the worker); the scan itself is in the
 *     corpus. Never point it at production: it drops and recreates its tables.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
date_default_timezone_set('UTC');
$api = dirname(__DIR__);
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
foreach (['ap_fix_utf8', 'ap_db_now', 'ap_profile_defaults', 'ap_profile_badge_catalog', 'ap_profile_normalize_badges',
    'ap_profile_supporter_awarded', 'ap_profile_authorized_badges', 'ap_profile_get', 'ap_profile_normalize_summary_html',
    'ap_profile_plain_bio_to_html', 'ap_profile_save', 'ap_profile_save_unlocked', 'ap_profile_actor_url', 'ap_profile_identity_urls',
    'ap_profile_coerce_https_url', 'ap_profile_normalize_verify_url', 'ap_profile_urls_match', 'ap_profile_html_has_rel_me',
    'ap_profile_verify_rel_me_url', 'ap_profile_verify_attachments', 'ap_profile_sanitize_https_url'] as $fn) eval(declaration($dbSource, $fn));
eval(declaration($authSource, 'ap_auth_user_set_email'));
// Network stubs: no page is fetched and no redirect followed in either mode.
function ap_profile_url_redirects_to(string $url, string $target): bool { return false; }
function ap_link_preview_url_allowed(string $url): bool { return false; }
function ap_link_preview_http_get(string $url, int $t = 4, int $m = 524288): ?string { return null; }
$GLOBALS['after_save'] = null;
// Records the flags; in fixture mode also runs the genuine after-save work (renamed).
function ap_profile_after_save(string $actorKey, bool $trends, bool $algorithm): void {
    $GLOBALS['after_save'] = [$actorKey, $trends, $algorithm];
    if (function_exists('genuine_profile_after_save')) genuine_profile_after_save($actorKey, $trends, $algorithm);
}

/** The inline `<a ...>` rewrite of ap_profile_save_unlocked, cut from its source. */
function links_callback_source(string $dbSource): string {
    $a = strpos($dbSource, "        \$summary = preg_replace_callback(\n");
    $b = strpos($dbSource, "        ) ?? \$summary;\n", $a);
    if ($a === false || $b === false) throw new RuntimeException('missing summary link rewrite');
    return substr($dbSource, $a, $b - $a) . "        ) ?? \$summary;\n";
}

if (($argv[1] ?? '') === '--corpus') {
    $out = (string) ($argv[2] ?? '/tmp/vaak-profile-save-corpus.json');
    $links = eval('return static function (string $summary): string {' . links_callback_source($dbSource) . ' return $summary; };');
    mt_srand(20261021);
    $pick = static fn(array $a) => $a[mt_rand(0, count($a) - 1)];
    $tagBits = ['<', '>', '</', '/>', '<!--', '-->', '<?', '?>', '<!', '<!DOCTYPE html>', '<?xml', '"', "'", '\\', '(', ')', ' ', "\n", "\t", '=', '-', 'p', 'a', 'br', 'b', 'i', 'em', 'li', 'ul', 'span', 'script', 'href', 'rel', 'class', 'x', 'é', '💜', "\0", '<a href="https://x.test/a">', '<A HREF=\'http://y.test/\'>', '</a>', '<br/>', '<BR >', '<p class="c">', '</P>', '< p>', '<p/', 'code', '<strong>', 'Ã', '&amp;', '&'];
    $cases = [];
    $add = static function (string $kind, string $input, mixed $php) use (&$cases): void { $cases[] = ['kind' => $kind, 'input' => $input, 'php' => $php]; };
    $allow = '<p><br><br/><a><code><strong><em><b><i><ul><ol><li>';
    $hand = ['<p>Hi <span class="x">there</span></p>', 'a<br/>b<BR>c', 'x < y', '<!-- c -->ok', '<?php echo 1; ?>ok', '<a href="x>y">t</a>', 'tail <b',
        '<!DOCTYPE html><p>d</p>', '<?xml version="1.0"?><p>x</p>', '<p onclick="a(\'>\')">q</p>', '<<p>>', '<p <b>>t', "<p\n>n</p>", '<a\thref="https://t.test/">', '<!-- a -- b --> c', '<!--->x-->y'];
    $gen = static function (int $n) use ($pick, $tagBits): array { $s = ''; for ($i = 0; $i < $n; $i++) $s .= $pick($tagBits); return [$s]; };
    for ($i = 0, $n = (int) (getenv('CORPUS_N') ?: 6000); $i < $n; $i++) $hand[] = $gen(mt_rand(1, 14))[0];
    foreach ($hand as $s) {
        $add('strip_allow', $s, strip_tags($s, $allow));
        $add('strip_a', $s, strip_tags($s, '<a>'));
        $add('strip_none', $s, strip_tags($s));
        if (mb_check_encoding($s, 'UTF-8')) {
            $add('links', $s, $links($s));
            $add('normalize', $s, ap_profile_normalize_summary_html($s));
        }
    }
    $bios = ["one\r\ntwo\n\n\n\nthree & <four>", "  lead\n\n\n\n\ntail  ", "a\rb\r\rc", "\"q\" 'a' café 💜", "x\n \ny", "\n\n\n"];
    foreach ($bios as $s) $add('plain_bio', $s, ap_profile_plain_bio_to_html($s));
    $norm = ['<p>a</p><br><br><p>b</p>', '<br/><br /><br>c', '<p> </p><p>x</p>', '<p><br></p>', '</p> <br> </p>', '<P>a</P><BR><Pre>', "<br>\n<br>\n<br>\n<br>"];
    foreach ($norm as $s) $add('normalize', $s, ap_profile_normalize_summary_html($s));
    $hosts = ['mkultra.monster', 'Example.COM', 'sub.x-y.test', 'a.b', 'x.co.uk', 'localhost', 'x_y.test', '1.2.3.4', 'xn--bcher-kva.example'];
    $paths = ['', '/', '/a', '/a/', '/@val', '/users/val/', '/p?q=1', '/p?', '/p#f', '/p?q#f', '#f', '?q', '/a).', '/a!?', "/a'", '/a b', '/é'];
    $urls = [];
    foreach ($hosts as $h) foreach ($paths as $p) { $urls[] = 'https://' . $h . $p; $urls[] = $h . $p; $urls[] = 'HTTPS://' . strtoupper($h) . $p; }
    foreach ($urls as $u) {
        foreach ([$u, '<a href="' . $u . '" rel="me">x</a>', "<a href='" . $u . "'>y</a>", '<b>' . $u . '</b>', ' ' . $u . ' ', '@' . $u, $u . ' x'] as $v) $add('coerce', $v, ap_profile_coerce_https_url($v));
        $add('sanitize', $u, ap_profile_sanitize_https_url($u));
        $add('sanitize', ' ' . $u, ap_profile_sanitize_https_url(' ' . $u));
    }
    foreach (['x.test/?a=1&amp;b=2', 'AT&T', '&lt;b&gt;x.test', '&#039;x.test', '&#x41;b.test', '&#65;b.test/&quot;', 'x.test/&hellip;', 'x.test/&amp', 'Tom &amp; Jerry', '&amp;amp;x.test',
        '&#0;x.test', '&#xD800;x.test', '&#;x.test', '&AMP;x.test', 'Pronouns: she/her &lt;3', 'https://x.test/a&amp;b', '&nbsp;x.test'] as $v) $add('coerce', $v, ap_profile_coerce_https_url($v));
    foreach (['https://a@b.test/x.png', 'https://b.test/x@y.png', 'https://b.test?x@y/', 'http://b.test/', 'https://', 'https://b.test/' . str_repeat('a', 800), "https://b.test/\x01", 'https://127.0.0.1/a'] as $u) $add('sanitize', $u, ap_profile_sanitize_https_url($u));
    $emails = ['val@mkultra.monster', 'a.b+c@sub.example-x.co', 'A_B@X.TEST', "o'neil@x.test", 'x@a-b--c.test', 'x@1a.test', 'x@a.b1', 'x@a.1b', 'x@-a.test', 'x@a-.test',
        str_repeat('a', 64) . '@x.test', str_repeat('a', 65) . '@x.test', 'a@' . str_repeat('b', 63) . '.test', 'a@' . str_repeat('b', 64) . '.test', str_repeat('a', 190) . '@xyz.test',
        'x@xn--bcher-kva.example', 'x@x.xn--p1ai', 'x@x.test-a', 'x@x.t', '{}|~`^@x.test', '/=?@x.test'];
    foreach ($emails as $e) $add('email', $e, filter_var($e, FILTER_VALIDATE_EMAIL) !== false && strlen($e) <= 200);
    $pages = [
        '<link rel="me" href="https://mkultra.monster/@val">',
        '<a href="/rel" rel="me"><a rel=\'nofollow me\' href=\'https://www.mkultra.monster/users/val/\'>',
        '<abbr rel="me" href="https://mkultra.monster/users/val"><a xrel="me" href="https://mkultra.monster/users/val">',
        '<a rel="ME" href="HTTPS://VAAK.MONSTER/vaak/users/val">', '<a rel="me" href="https://mkultra.monster/users/val?x=1">',
        '<a rel="me"href="https://mkultra.monster/users/val">', '<a rel = "me" href = "https://mkultra.monster/users/valx">',
        '<a rel="me" href="https://other.test/users/val">', '<a href="https://mkultra.monster/users/val">', '<a rel="me nofollow" href=" https://mkultra.monster/vaak/users/val/ ">',
        '<a rel="me" href="https://mkultra.monster/users/val#top">', '<a rel="me" href="https://mkultra.monster/users/val&#x2f;">', '<a rel="me" href="https://mkultra.monster/@val?a=1&amp;b=2">', '<a rel="me" href="&#104;ttps://mkultra.monster/@val">', '<LINK REL="me" HREF="https://www.vaak.monster/users/val">', '<a rel="me" href="http://mkultra.monster/users/val">',
    ];
    foreach ($pages as $h) $add('rel_me', $h, ap_profile_html_has_rel_me($h, ap_profile_identity_urls('val')));
    file_put_contents($out, json_encode(['cases' => $cases], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    fwrite(STDOUT, 'corpus cases: ' . count($cases) . "\n");
    exit(0);
}

[$self, $dsn, $idType, $url, $secret] = $argv + [null, null, 'INTEGER', null, null];
if (!$dsn || !in_array($idType, ['INTEGER', 'BIGINT'], true) || !$url || strlen((string) $secret) < 32) {
    fwrite(STDERR, "usage: --corpus OUT | dsn INTEGER|BIGINT url secret\n"); exit(2);
}
if (!str_contains($dsn, 'fixture')) { fwrite(STDERR, "refusing a database without 'fixture' in its name\n"); exit(2); }
putenv('VAAK_ADMIN_READ_NATIVE=1'); putenv('VAAK_ADMIN_READ_SECRET=' . $secret); putenv('VAAK_SETTINGS_PROFILE_SAVE_NATIVE=1');
putenv('VAAK_SETTINGS_PROFILE_SAVE_URL=' . $url);
require $api . '/ap-admin-native.php';
// After-save work (S4b) runs for real against Redis and a scratch site and
// timeline cache directory. The worker must use the same three (see usage).
$redisDsn = (string) (getenv('PROFILE_SAVE_REDIS') ?: 'redis://127.0.0.1:6390/5');
$scratch = (string) (getenv('PROFILE_SAVE_SCRATCH') ?: sys_get_temp_dir() . '/vaak-profile-save');
$site = $scratch . '/site'; $tlDir = $scratch . '/tl';
putenv('VAAK_REDIS_DSN=' . $redisDsn); putenv('VAAK_REDIS_ENABLED=1');
require $api . '/ap-redis.php';
$adminSource = (string) file_get_contents($api . '/ap-admin.php');
$docSource = (string) file_get_contents($api . '/ap-public-doc.php');
foreach (['admin_tl_redis_key', 'admin_tl_owner_index_key', 'admin_tl_cache_clear_owner'] as $fn) eval(declaration($adminSource, $fn));
eval(str_replace("'/var/lib/mkultra/ap/admin-tl-cache'", var_export($tlDir, true), declaration($adminSource, 'admin_tl_cache_dir')));
foreach (['ap_timeline_cache_invalidate_owner', 'ap_timeline_ranked_redis_key', 'ap_timeline_home_hydrate_redis_key', 'ap_timeline_home_hydrate_invalidate_owner'] as $fn) eval(declaration($dbSource, $fn));
eval(str_replace('dirname(__DIR__)', var_export($site, true), declaration($dbSource, 'ap_profile_export_public_cache')));
foreach (['ap_public_doc_cache_key', 'ap_public_doc_local_actor_id', 'ap_public_doc_cache_forget', 'ap_public_doc_invalidate_actor'] as $fn) eval(declaration($docSource, $fn));
eval(preg_replace('/^function ap_profile_after_save\(/', 'function genuine_profile_after_save(', declaration($dbSource, 'ap_profile_after_save')));
$redis = ap_redis_client('cache');
if (!$redis) { fwrite(STDERR, "Redis at $redisDsn is required\n"); exit(2); }
$db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
$GLOBALS['current_user'] = null;
function ap_auth_current_user(): ?array { return $GLOBALS['current_user']; }

function seed(array $over = []): void {
    global $db, $idType;
    $db->exec('DROP TABLE IF EXISTS ap_supporter_codes; DROP TABLE IF EXISTS actor_profile; DROP TABLE IF EXISTS ap_users');
    $db->exec("CREATE TABLE ap_users(id $idType PRIMARY KEY, username TEXT NOT NULL, email TEXT UNIQUE, actor_key TEXT NOT NULL UNIQUE, is_admin INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, disabled_at TEXT)");
    $db->exec("INSERT INTO ap_users VALUES (1,'operator','op@mkultra.monster','cmdr_nova',1,'2026-01-01T00:00:00+00:00','2026-01-01T00:00:00+00:00',NULL),
        (2,'val','old@x.test','val',0,'2026-01-01T00:00:00+00:00','2026-01-01T00:00:00+00:00',NULL),
        (3,'gone','gone@x.test','gone',0,'2026-01-01T00:00:00+00:00','2026-01-01T00:00:00+00:00','2026-02-01T00:00:00+00:00'),
        (4,'fresh',NULL,'fresh',0,'2026-01-01T00:00:00+00:00','2026-01-01T00:00:00+00:00',NULL)");
    $db->exec("CREATE TABLE actor_profile (actor_key TEXT PRIMARY KEY, name TEXT NOT NULL, summary TEXT NOT NULL, attachment_json TEXT NOT NULL DEFAULT '[]', icon_url TEXT, image_url TEXT,
        manually_approves INTEGER NOT NULL DEFAULT 0, discoverable INTEGER NOT NULL DEFAULT 1, auto_unblur_sensitive INTEGER NOT NULL DEFAULT 0, automated INTEGER NOT NULL DEFAULT 0,
        reply_policy TEXT NOT NULL DEFAULT 'anyone', quote_policy TEXT NOT NULL DEFAULT 'anyone', forum_signature TEXT NOT NULL DEFAULT '', profile_badges TEXT NOT NULL DEFAULT '[]',
        hide_profile_replies INTEGER NOT NULL DEFAULT 0, hide_profile_boosts INTEGER NOT NULL DEFAULT 0, algorithm_enabled INTEGER NOT NULL DEFAULT 1, trending_enabled INTEGER NOT NULL DEFAULT 1,
        downranking_enabled INTEGER NOT NULL DEFAULT 1, asks_enabled INTEGER NOT NULL DEFAULT 1, webmentions_enabled INTEGER NOT NULL DEFAULT 1, updated_at TEXT NOT NULL,
        indexable INTEGER NOT NULL DEFAULT 1, collection_consent INTEGER NOT NULL DEFAULT 1, vanity_verified INTEGER NOT NULL DEFAULT 0, auto_follow_back INTEGER NOT NULL DEFAULT 0,
        anti_ai_marker INTEGER NOT NULL DEFAULT 0, auto_delete_posts_7d INTEGER NOT NULL DEFAULT 0)");
    foreach (['cmdr_nova', 'val'] as $key) {
        $row = array_merge(['actor_key' => $key, 'name' => 'Old ' . $key, 'summary' => '<p>old</p>', 'attachment_json' => '[]', 'icon_url' => null, 'image_url' => null,
            'reply_policy' => 'followers', 'quote_policy' => 'nobody', 'profile_badges' => '["cat"]', 'algorithm_enabled' => 1, 'trending_enabled' => 1, 'downranking_enabled' => 0,
            'anti_ai_marker' => 1, 'updated_at' => '2026-01-01T00:00:00+00:00'], $over[$key] ?? []);
        $cols = array_keys($row);
        $db->prepare('INSERT INTO actor_profile (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
    }
    $db->exec("CREATE TABLE ap_supporter_codes (id $idType PRIMARY KEY, code_hash TEXT, used_by $idType, used_at TEXT)");
    if (!empty($over['supporter'])) $db->exec("INSERT INTO ap_supporter_codes VALUES (1,'h',2,'2026-03-01T00:00:00+00:00')");
    seed_caches();
}
/** Redis keys and files every after-save step touches, plus neighbours it must leave. */
function seed_caches(): void {
    global $redis, $site, $tlDir;
    $redis->flushDB();
    foreach ([1, 2] as $owner) {
        $redis->set(admin_tl_owner_index_key($owner), json_encode(["home:$owner:a", "feed:$owner", '', '0']));
        foreach (["home:$owner:a", "feed:$owner"] as $logical) $redis->set(admin_tl_redis_key($logical), 'ranked');
        $redis->set("vaak:fragment:tl:v1:$owner:x", 'f'); $redis->set("vaak:fragment:tl:v1:{$owner}2:y", 'neighbour');
        foreach ([15, 50, 240, 99] as $limit) $redis->set(ap_timeline_home_hydrate_redis_key($owner, $limit), 'hydrate');
        $redis->set('vaak:trends:policy-epoch:v1:' . $owner, '4');
    }
    foreach (['val', 'cmdr_nova', 'other'] as $key) $redis->set(ap_public_doc_cache_key(ap_public_doc_local_actor_id($key)), 'doc');
    // A pre-save snapshot: any successful save must delete it.
    $redis->set('vaak:profile:v1:' . hash('sha256', 'val'), json_encode(['actor_key' => 'val', 'name' => 'Old val', 'summary' => '<p>old</p>']));
    @mkdir($tlDir, 0755, true); @mkdir($site . '/assets/data', 0755, true);
    foreach (glob($tlDir . '/*') ?: [] as $f) unlink($f);
    foreach (['tl_home1a.json', 'tl_feed1.json', 'tl_home2a.json', 'tl_feed2.json', 'tl_tl.json', 'tl_other.json'] as $f) file_put_contents($tlDir . '/' . $f, '{}');
    file_put_contents($site . '/assets/data/ap-cmdr-nova.json', "old\n");
}
function cache_snapshot(): array {
    global $redis, $site, $tlDir;
    $keys = $redis->keys('*'); sort($keys); $out = [];
    foreach ($keys as $k) {
        // ap_profile_get may refill the profile cache from the new row; only its absence or freshness matters.
        if (str_starts_with($k, 'vaak:profile:v1:')) continue;
        $out[$k] = [$redis->get($k), $redis->ttl($k) > 0];
    }
    // ap_profile_get may refill a profile cache from the current row; only a snapshot older than the row is wrong.
    foreach (ap_db()->query('SELECT actor_key, name FROM actor_profile')->fetchAll() as $row) {
        $cached = json_decode((string) $redis->get('vaak:profile:v1:' . hash('sha256', $row['actor_key'])), true);
        if (is_array($cached) && ($cached['name'] ?? null) !== $row['name']) $out['profile cache ' . $row['actor_key']] = 'STALE';
    }
    $files = array_map('basename', glob($tlDir . '/*') ?: []); sort($files);
    // The export carries the row's updated_at; the two runs write it in different seconds.
    $export = (string) preg_replace('/"updated_at": "\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00"/', '"updated_at": "now"', (string) @file_get_contents($site . '/assets/data/ap-cmdr-nova.json'));
    return ['redis' => $out, 'tl' => $files, 'export' => $export];
}
function snapshot(): array {
    global $db;
    $users = $db->query('SELECT id, email, updated_at FROM ap_users ORDER BY id')->fetchAll();
    $profiles = $db->query('SELECT * FROM actor_profile ORDER BY actor_key')->fetchAll();
    foreach ($users as &$u) { $u['updated_at'] = $u['updated_at'] === '2026-01-01T00:00:00+00:00' ? 'seed' : (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', (string) $u['updated_at']) ? 'now' : 'BAD ' . $u['updated_at']); }
    foreach ($profiles as &$p) { $p['updated_at'] = $p['updated_at'] === '2026-01-01T00:00:00+00:00' ? 'seed' : (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', (string) $p['updated_at']) ? 'now' : 'BAD ' . $p['updated_at']); }
    return ['users' => $users, 'profiles' => $profiles, 'caches' => cache_snapshot()];
}
function form(array $over = []): array {
    return array_merge(['name' => 'Val', 'summary' => 'Hello there', 'attachment' => [], 'icon_url' => '', 'image_url' => '', 'manually_approves' => false, 'discoverable' => true,
        'indexable' => true, 'collection_consent' => true, 'vanity_verified' => false, 'auto_follow_back' => false, 'anti_ai_marker' => false, 'auto_unblur_sensitive' => false,
        'auto_delete_posts_7d' => false, 'automated' => false, 'reply_policy' => 'anyone', 'quote_policy' => 'anyone', 'hide_profile_replies' => false, 'hide_profile_boosts' => false,
        'algorithm_enabled' => true, 'trending_enabled' => true, 'downranking_enabled' => false, 'asks_enabled' => true, 'webmentions_enabled' => true, 'forum_signature' => '',
        'profile_badges' => []], $over);
}
/** The save_profile action's email then profile steps, as PHP runs them without the worker. */
function php_save(int $owner, string $key, string $email, array $fields): array {
    $GLOBALS['after_save'] = null;
    $res = ap_auth_user_set_email($owner, trim($email));
    if (empty($res['ok'])) return ['ok' => false, 'error' => $res['error'] ?? 'Could not save email.', 'after' => null];
    $saved = ap_profile_save($fields, $key);
    return ['ok' => !empty($saved['ok']), 'error' => $saved['error'] ?? null, 'after' => $GLOBALS['after_save']];
}
function native_save(int $owner, string $key, string $email, array $fields): ?array {
    $GLOBALS['current_user'] = ['id' => $owner, 'actor_key' => $key];
    $r = ap_settings_profile_save_native($owner, $key, trim($email), $fields);
    if ($r === null) return null;
    // As the save_profile action does: PHP runs the after-save work only when the worker left it.
    if ($r['ok'] && !$r['after_save']) { $GLOBALS['native_after_save_left']++; genuine_profile_after_save($key, $r['trends_changed'], $r['algorithm_changed']); }
    return ['ok' => $r['ok'], 'error' => $r['error'], 'after' => $r['ok'] ? [$key, $r['trends_changed'], $r['algorithm_changed']] : null];
}

/** @return list<string> paths where two snapshots differ, with both values. */
function differences(mixed $a, mixed $b, string $path = ''): array {
    if (is_array($a) && is_array($b)) {
        $out = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            if (!array_key_exists($k, $a) || !array_key_exists($k, $b)) { $out[] = "$path/$k: php " . json_encode($a[$k] ?? '(none)', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' rust ' . json_encode($b[$k] ?? '(none)', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); continue; }
            array_push($out, ...differences($a[$k], $b[$k], "$path/$k"));
        }
        if ($out === [] && array_keys($a) !== array_keys($b)) $out[] = "$path: key order";
        return $out;
    }
    return $a === $b ? [] : ["$path: php " . json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' rust ' . json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
}
$long = str_repeat('x', 101);
$scenarios = [
    'plain bio' => [2, 'val', 'new@x.test', form(['summary' => "Line one\nline two\n\n\n\nPara & <tag> \"q\""])],
    'html bio' => [2, 'val', '', form(['summary' => '<p>Hi <span class="h-card"><a href="https://mkultra.monster/@val" class="u-url mention" target="_blank">@val</a></span></p><p><a href="http://plain.test/">insecure</a><br/><br/><br/><br>x</p><script>alert(1)</script><p> </p>'])],
    'mastodon style bio' => [2, 'val', 'old@x.test', form(['summary' => "<p>Writer. <em>She/her</em> &amp; <strong>coffee</strong></p><ul><li>One</li><li>Two</li></ul><!-- note --><p>Ends</p>"])],
    'name required' => [2, 'val', 'new@x.test', form(['name' => '  '])],
    'name too long' => [2, 'val', '', form(['name' => $long])],
    'bio required' => [2, 'val', '', form(['summary' => '   '])],
    'bio only tags' => [2, 'val', '', form(['summary' => '<span></span>'])],
    'bio too long' => [2, 'val', '', form(['summary' => '<p>' . str_repeat('é', 2001) . '</p>'])],
    'signature control byte' => [2, 'val', '', form(['forum_signature' => "sig\x07"])],
    'signature ok' => [2, 'val', '', form(['forum_signature' => '  — Val  '])],
    'avatar not https' => [2, 'val', '', form(['icon_url' => 'http://x.invalid/a.png'])],
    'header not https' => [2, 'val', '', form(['image_url' => '   '])],
    'media urls' => [2, 'val', '', form(['icon_url' => ' https://mkultra.monster/img/profile/avatar-2.png ', 'image_url' => 'https://mkultra.monster/img/profile/header-2.jpg'])],
    'fields' => [2, 'val', '', form(['attachment' => [
        ['name' => 'Site', 'value' => 'example.invalid'], ['name' => 'Blog', 'value' => 'https://Blog.Example.invalid/Path/'],
        ['name' => 'Anchor', 'value' => '<a href="https://a.invalid/u" rel="me" class="x">me</a>'], ['name' => 'Text', 'value' => 'Pronouns: she/her <3'],
        ['name' => '', 'value' => 'skipped'], ['name' => str_repeat('L', 65), 'value' => 'skipped'], ['name' => 'Bold', 'value' => '<b>b.invalid</b>'], ['name' => 'Handle', 'value' => '@val@mkultra.monster'],
    ]])],
    'entities in fields' => [2, 'val', '', form(['attachment' => [['name' => 'Q', 'value' => 'a.invalid/?a=1&amp;b=2'], ['name' => 'Tom & Jerry', 'value' => 'AT&T <3 "quoted"']]])],
    'badges' => [2, 'val', '', form(['profile_badges' => ['admin', 'supporter', 'cat', ' dog ', 'cat', 'nope', 'coffee', 'tea', 'bookworm', 'gamer', 'music', 'artist', ['x']]])],
    'admin badges' => [1, 'cmdr_nova', 'op@mkultra.monster', form(['name' => 'Nova', 'profile_badges' => ['wizard']])],
    'homepage export and algorithm off' => [1, 'cmdr_nova', '', form(['name' => 'Nova "N" é/💜', 'summary' => "<p>Bio\u{2028}line</p>", 'icon_url' => 'https://mkultra.monster/img/profile/avatar-1.png', 'algorithm_enabled' => false, 'trending_enabled' => false])],
    'supporter badge' => [2, 'val', '', form(['profile_badges' => ['wizard']]), ['supporter' => true]],
    'policies kept' => [2, 'val', '', form(['reply_policy' => 'bogus', 'quote_policy' => 'followers'])],
    'trends and algorithm change' => [2, 'val', '', form(['trending_enabled' => false, 'downranking_enabled' => true, 'algorithm_enabled' => false])],
    'flags' => [2, 'val', '', form(['manually_approves' => true, 'discoverable' => false, 'indexable' => false, 'collection_consent' => false, 'vanity_verified' => true, 'auto_follow_back' => true,
        'auto_unblur_sensitive' => true, 'auto_delete_posts_7d' => true, 'automated' => true, 'hide_profile_replies' => true, 'hide_profile_boosts' => true, 'asks_enabled' => false, 'webmentions_enabled' => false])],
    'email cleared' => [2, 'val', '  ', form()],
    'email taken' => [2, 'val', 'OP@mkultra.monster', form()],
    'email of a disabled account' => [2, 'val', 'gone@x.test', form()],
    'email error beats profile error' => [2, 'val', str_repeat('a', 195) . '@x.test', form(['name' => ''])],
    'email saved before profile error' => [2, 'val', 'saved@x.test', form(['name' => ''])],
];
$fallbacks = [
    'mojibake name' => [2, 'val', '', form(['name' => 'Itâ€™s me'])],
    'odd email' => [2, 'val', 'not-an-email', form()],
    'unusual entity in field' => [2, 'val', '', form(['attachment' => [['name' => 'Q', 'value' => 'a.invalid/&hellip;']]])],
    'ip avatar' => [2, 'val', '', form(['icon_url' => 'https://10.0.0.1/a.png'])],
    'no stored profile' => [4, 'fresh', '', form()],
    'other account' => [2, 'cmdr_nova', '', form()],
    'disabled account' => [3, 'gone', '', form()],
];
$failures = 0; $compared = 0; $GLOBALS['native_after_save_left'] = 0;
foreach ($scenarios as $label => $s) {
    [$owner, $key, $email, $fields] = $s; $over = $s[4] ?? [];
    seed($over); $php = php_save($owner, $key, $email, $fields); $phpRows = snapshot();
    seed($over); $native = native_save($owner, $key, $email, $fields); $nativeRows = snapshot();
    $compared++;
    if (getenv('PROFILE_SAVE_DUMP') === $label) fwrite(STDOUT, json_encode(['result' => $native, 'rows' => $nativeRows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    if ($native === null) { $failures++; fwrite(STDOUT, "FAIL $label: worker fell back\n"); continue; }
    if ($php !== $native) { $failures++; fwrite(STDOUT, "FAIL $label result\n php: " . json_encode($php) . "\n rust: " . json_encode($native) . "\n"); }
    if ($phpRows !== $nativeRows) { $failures++; fwrite(STDOUT, "FAIL $label rows\n" . implode('', array_map(static fn(string $d): string => "  $d\n", differences($phpRows, $nativeRows)))); }
}
foreach ($fallbacks as $label => $s) {
    [$owner, $key, $email, $fields] = $s;
    seed(); $before = snapshot(); $native = native_save($owner, $key, $email, $fields); $after = snapshot(); $compared++;
    if ($native !== null || $before !== $after) { $failures++; fwrite(STDOUT, "FAIL $label: expected a fallback with no writes, got " . json_encode($native) . "\n"); }
}
$compared++;
if ($GLOBALS['native_after_save_left'] !== 0) { $failures++; fwrite(STDOUT, "FAIL worker left the after-save work to PHP {$GLOBALS['native_after_save_left']} times\n"); }
// Bridge validator: a reply bound to another request, or an inconsistent one, is refused.
$good = ['protocol_version' => 1, 'ok' => true, 'error' => null, 'email_saved' => true, 'trends_changed' => false, 'algorithm_changed' => true, 'after_save' => true, 'request_binding' => ['actor_key' => 'val', 'owner_id' => 2]];
$checks = [[$good, true], [array_merge($good, ['request_binding' => ['actor_key' => 'val', 'owner_id' => 3]]), false], [array_merge($good, ['error' => 'x']), false],
    [array_merge($good, ['ok' => false, 'error' => 'Bad', 'algorithm_changed' => false, 'after_save' => false]), true], [array_merge($good, ['ok' => false, 'error' => '', 'algorithm_changed' => false]), false],
    [array_merge($good, ['extra' => 1]), false], [array_merge($good, ['email_saved' => false]), false],
    [array_merge($good, ['ok' => false, 'error' => 'Bad', 'algorithm_changed' => false, 'after_save' => true]), false], [array_merge($good, ['after_save' => 1]), false]];
foreach ($checks as $i => [$reply, $want]) { $compared++; if ((ap_settings_profile_save_native_reply($reply, 2, 'val') !== null) !== $want) { $failures++; fwrite(STDOUT, "FAIL validator case $i\n"); } }
// Field shapes PHP does not send are refused before any request.
$GLOBALS['current_user'] = ['id' => 2, 'actor_key' => 'val'];
foreach ([form(['extra' => 1]), form(['name' => 1]), form(['attachment' => [['value' => 'v', 'name' => 'n']]]), form(['automated' => 1])] as $i => $bad) {
    $compared++; if (ap_settings_profile_save_native(2, 'val', '', $bad) !== null) { $failures++; fwrite(STDOUT, "FAIL field shape case $i\n"); }
}
fwrite(STDOUT, ($failures === 0 ? 'OK' : 'FAILED') . " $idType: $compared checks, $failures failures\n");
exit($failures === 0 ? 0 : 1);
