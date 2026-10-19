<?php
declare(strict_types=1);
/** The post-deploy canary passes, skips and fails on the right evidence. */
require __DIR__ . '/migration-reference-functions.php';
$api = dirname(__DIR__);
reference_load(__DIR__ . '/deploy-canary.php', [
    'canary_r', 'canary_has_row', 'canary_count_statuses', 'canary_check_home',
    'canary_check_bsky_media', 'canary_check_remote_poll', 'canary_check_axum',
]);
reference_load($api . '/ap-bsky.php', [
    'ap_bsky_blob_cid', 'ap_bsky_record_embed_view', 'ap_bsky_record_embed_synth_compact',
    'ap_bsky_post_embed_compact', 'ap_bsky_embed_video_view', 'ap_bsky_compact_label_list',
    'ap_bsky_compact_facets', 'ap_bsky_post_upsert_from_feed_item', 'ap_bsky_cached_quote_view',
]);
reference_load($api . '/ap-masto-entities.php', ['ap_masto_bsky_media_attachments', 'ap_masto_animated_media_from_url']);
reference_load($api . '/ap-admin.php', ['admin_poll_block_html', 'h', 'admin_tl_html_axum_fetch']);
reference_load($api . '/ap-db.php', [
    'ap_db_driver', 'ap_remote_polls_migrate', 'ap_remote_poll_id_base', 'ap_remote_poll_public_id',
    'ap_remote_poll_row_id', 'ap_remote_poll_find', 'ap_remote_poll_own_votes', 'ap_remote_poll_is_expired',
]);
if (!defined('AP_BSKY_POST_TEXT_MAX')) define('AP_BSKY_POST_TEXT_MAX', 5000);
const CANARY_BSKY_WINDOW_SECONDS = 6 * 3600;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE bsky_posts (bsky_uri TEXT PRIMARY KEY, bsky_cid TEXT, author_did TEXT NOT NULL,
    author_handle TEXT, author_display TEXT, author_avatar TEXT, indexed_at TEXT, published_at TEXT,
    text TEXT NOT NULL DEFAULT '', embed_json TEXT, reply_parent TEXT, reply_root TEXT, like_count INTEGER,
    repost_count INTEGER, reply_count INTEGER, quote_count INTEGER, reason_json TEXT, raw_json TEXT,
    owner_user_id BIGINT, seen_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
$db->exec('CREATE TABLE masto_polls (note_id TEXT, local_id INTEGER, multiple INTEGER, votes_count INTEGER,
    voters_count INTEGER, expires_at TEXT, options_json TEXT, voters_json TEXT)');
$db->exec('CREATE TABLE outbox_notes (id TEXT, raw_create_json TEXT)');
$db->exec('CREATE TABLE remote_polls (note_id TEXT PRIMARY KEY, multiple INTEGER NOT NULL DEFAULT 0, expires_at TEXT,
    closed INTEGER NOT NULL DEFAULT 0, options_json TEXT NOT NULL, votes_count BIGINT NOT NULL DEFAULT 0,
    voters_count BIGINT NOT NULL DEFAULT 0, updated_at TEXT NOT NULL)');
$db->exec('CREATE TABLE following (actor_id TEXT PRIMARY KEY, followed_at TEXT NOT NULL, host TEXT)');

function ap_db(): PDO { return $GLOBALS['db']; }
// Declared before the canary can require api/ap-shadow-http.php: no token
// until a case sets $GLOBALS['shadow_token'].
function ap_shadow_http_auth_headers(): array
{
    $token = (string) ($GLOBALS['shadow_token'] ?? '');
    return $token === '' ? [] : ['X-VAAK-Shadow-Token: ' . $token];
}
function canary_boot_masto(): int { return $GLOBALS['owner']; }
function canary_boot_admin(): int { return $GLOBALS['owner']; }
function ap_masto_timeline_home_merged(int $limit, ?string $max, ?string $since, bool $std): array { return $GLOBALS['home']; }
function ap_bsky_posts_migrate(): void {}
function ap_bsky_avatar_public_url(mixed $avatar, string $did = ''): ?string { return null; }
function ap_bsky_post_observation_touch(string $uri, int $owner): void {}
function ap_bsky_index_feed_post_links(array $post): void {}

$checks = 0;
function dc_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
$owner = 1;
$home = [];
$did = 'did:plc:abc123';
$images = ['$type' => 'app.bsky.embed.images', 'images' => [['alt' => 'a flag', 'image' => ['$type' => 'blob',
    'ref' => ['$link' => 'bafkreiaaaa1111'], 'mimeType' => 'image/jpeg', 'size' => 10]]]];
$server = null;
try {
    // Home: statuses pass; empty with follows fails; empty with nothing to show skips.
    $home = [['id' => '1', 'content' => 'x'], ['id' => '2', 'content' => 'y']];
    dc_check(canary_check_home() === ['ok', 'owner=1 statuses=2'], 'home with statuses passes');
    $home = [];
    dc_check(canary_check_home()[0] === 'skip', 'empty instance skips Home');
    $db->exec("INSERT INTO following VALUES ('https://remote.example/users/a', '2026-10-19T00:00:00Z', 'remote.example')");
    dc_check(canary_check_home()[0] === 'fail', 'empty Home with follows fails');
    $owner = 0;
    dc_check(canary_check_home() === ['skip', 'no enabled account'], 'no account skips');
    $owner = 1;

    // Bluesky media: nothing recent skips; a Jetstream row with blob refs paints; a bare record fails.
    dc_check(canary_check_bsky_media()[0] === 'skip', 'no Bluesky rows skips');
    $uri = "at://{$did}/app.bsky.feed.post/3my7dllbzn226";
    ap_bsky_post_upsert_from_feed_item(['uri' => $uri, 'cid' => 'bafyrec', 'author' => ['did' => $did],
        'record' => ['text' => '', 'createdAt' => gmdate('c'), 'embed' => $images]], null);
    dc_check(canary_check_bsky_media() === ['ok', 'painted=1/1'], 'Jetstream image row paints an image URL');
    $db->exec('UPDATE bsky_posts SET embed_json = NULL, raw_json = ' . $db->quote(json_encode(['uri' => $uri,
        'record' => ['text' => '', 'embed' => $images], 'embed' => null], JSON_UNESCAPED_SLASHES)));
    $r = canary_check_bsky_media();
    dc_check($r[0] === 'fail' && str_contains($r[1], $uri), 'row with only blob refs fails and names the post');
    $db->exec("UPDATE bsky_posts SET seen_at = '2020-01-01T00:00:00+00:00'");
    dc_check(canary_check_bsky_media()[0] === 'skip', 'old rows are outside the window');

    // Remote poll: none skips; stored options paint; a renderer that drops options fails.
    dc_check(canary_check_remote_poll()[0] === 'skip', 'no remote polls skips');
    $db->prepare('INSERT INTO remote_polls (note_id, options_json, votes_count, voters_count, updated_at) VALUES (?, ?, 7, 7, ?)')
        ->execute(['https://social.example/users/a/statuses/1', json_encode([['title' => 'Tea', 'votes_count' => 4],
            ['title' => 'Coffee', 'votes_count' => 3]]), gmdate('c')]);
    dc_check(canary_check_remote_poll() === ['ok', 'options=2'], 'remote poll paints its options');
    $db->exec("UPDATE remote_polls SET options_json = '[{\"title\":\"\"}]'");
    dc_check(canary_check_remote_poll()[0] === 'fail', 'poll that paints no options fails');

    // Axum: a stub on loopback answers 200, 404, then 500.
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($sock, false), strlen('127.0.0.1:'));
    fclose($sock);
    $router = tempnam(sys_get_temp_dir(), 'canary-router');
    $mode = $router . '.mode';
    file_put_contents($router, '<?php $m = trim((string) @file_get_contents(' . var_export($mode, true) . '));'
        . ' if ($m === "404") { http_response_code(404); echo "{}"; return true; }'
        . ' if ($m === "500") { http_response_code(500); echo "{}"; return true; }'
        . ' if ($m === "503tok") { http_response_code(503); echo "shadow token not configured"; return true; }'
        . ' if ($m === "auth-log") { file_put_contents(' . var_export($router . '.log', true) . ', ($_SERVER["HTTP_X_VAAK_SHADOW_TOKEN"] ?? "-") . "\\n", FILE_APPEND); }'
        . ' if ($m === "auth" && ($_SERVER["HTTP_X_VAAK_SHADOW_TOKEN"] ?? "") !== "good-token") { http_response_code(401); return true; }'
        . ' header("Content-Type: text/html"); echo "<article>card</article>"; return true;');
    file_put_contents($mode, '200');
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
    putenv("VAAK_SHADOW_HTTP=http://127.0.0.1:{$port}");
    dc_check(canary_check_axum('home')[0] === 'ok', 'Axum 200 with HTML passes');
    file_put_contents($mode, '404');
    dc_check(canary_check_axum('local')[0] === 'skip', 'Axum cache miss skips');
    file_put_contents($mode, '500');
    dc_check(canary_check_axum('home')[0] === 'fail', 'Axum 500 fails');
    // After the shadow-token change: unconfigured worker skips, a token PHP
    // sends is accepted, and a missing or mismatched token fails.
    file_put_contents($mode, '503tok');
    $r = canary_check_axum('home');
    dc_check($r[0] === 'skip' && str_contains($r[1], 'token not configured'), 'unconfigured shadow token skips');
    file_put_contents($mode, 'auth');
    $r = canary_check_axum('home');
    dc_check($r[0] === 'fail' && str_contains($r[1], '401') && str_contains($r[1], 'no token'), 'missing shadow token fails');
    $GLOBALS['shadow_token'] = 'good-token';
    // Main's admin fetch sends no token yet, so log the header rather than
    // reject; the canary's own request must carry it.
    file_put_contents($mode, 'auth-log');
    dc_check(canary_check_axum('home')[0] === 'ok', 'shadow token request passes');
    $seen = (string) @file_get_contents($router . '.log');
    dc_check(str_starts_with($seen, "good-token\n"), 'shadow token header is sent');
    file_put_contents($mode, 'auth');
    $GLOBALS['shadow_token'] = 'stale-token';
    $r = canary_check_axum('local');
    dc_check($r[0] === 'fail' && str_contains($r[1], 'different tokens'), 'mismatched shadow token fails');
    putenv('VAAK_SHADOW_HTTP=http://127.0.0.1:1');
    dc_check(canary_check_axum('home')[0] === 'fail', 'Axum down fails');
    @unlink($mode);
    @unlink($router . '.log');
    @unlink($router);

    // The whole script: a check that throws is a failure, and the exit code says so.
    $out = [];
    exec('AP_DB_DSN=' . escapeshellarg('pgsql:host=/nonexistent-vaak-canary;dbname=none')
        . ' VAAK_CANARY_ENV_FILE=/nonexistent-vaak-canary ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/deploy-canary.php')
        . ' --only=home,bsky_media --timeout=20 2>&1', $out, $rc);
    $text = implode("\n", $out);
    dc_check($rc === 3 && str_contains($text, 'canary home fail:') && str_contains($text, 'deploy_canary_failed ok=0 skip=0 fail=2'),
        'unreachable database fails every check with exit 3');
} catch (Throwable $e) {
    fwrite(STDERR, 'deploy-canary-smoke failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($server)) proc_terminate($server);
}
fwrite(STDOUT, "deploy-canary-smoke ok checks={$checks}\n");
