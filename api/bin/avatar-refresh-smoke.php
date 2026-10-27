#!/usr/bin/env php
<?php
declare(strict_types=1);

function vaak_extract_function(string $source, string $name): string
{
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\(/m', $source, $m, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('missing ' . $name);
    }
    $start = (int) $m[0][1];
    $line = strrpos(substr($source, 0, $start), "\n");
    $start = $line === false ? 0 : $line + 1;
    $len = strlen($source);
    $i = strpos($source, '{', $start);
    if ($i === false) {
        throw new RuntimeException('no body ' . $name);
    }
    $depth = 0;
    $state = '';
    for ($j = $i; $j < $len; $j++) {
        $c = $source[$j];
        $next = $j + 1 < $len ? $source[$j + 1] : '';
        if ($state === '') {
            if ($c === "'" || $c === '"') {
                $state = $c;
                continue;
            }
            if ($c === '/' && $next === '/') {
                $state = 'line';
                continue;
            }
            if ($c === '/' && $next === '*') {
                $state = 'block';
                $j++;
                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $j - $start + 1);
                }
            }
        } elseif ($state === 'line') {
            if ($c === "\n") {
                $state = '';
            }
        } elseif ($state === 'block') {
            if ($c === '*' && $next === '/') {
                $state = '';
                $j++;
            }
        } else {
            if ($c === '\\') {
                $j++;
                continue;
            }
            if ($c === $state) {
                $state = '';
            }
        }
    }
    throw new RuntimeException('unclosed ' . $name);
}

function vaak_eval_functions(string $path, array $names): void
{
    $source = (string) file_get_contents($path);
    foreach ($names as $name) {
        eval(vaak_extract_function($source, $name));
    }
}

$root = dirname(__DIR__);
vaak_eval_functions($root . '/ap-db.php', [
    'ap_profile_sanitize_https_url',
    'ap_vaak_own_media_host',
    'ap_masto_system_media_path',
    'ap_account_media_is_broken_local',
    'ap_account_media_resolve',
    'ap_remote_actor_username_is_placeholder',
    'ap_remote_actor_as2_media_url',
    'ap_remote_actor_needs_refresh',
    'ap_remote_actor_host_fetch_allowed',
]);
vaak_eval_functions($root . '/ap-bsky.php', [
    'ap_bsky_avatar_cid_ok',
    'ap_bsky_avatar_public_url',
]);

function ap_db(): PDO { return $GLOBALS['fixture_db']; }
function ap_remote_actor_warm_async(string $actorId, bool $force = false): void
{
    $GLOBALS['fixture_warm'][] = ['actor' => $actorId, 'force' => $force];
}
function ap_remote_media_warm_async(string $actorId): void
{
    $GLOBALS['fixture_media'][] = $actorId;
}
function ap_bsky_is_profile_ref(string $ref): bool
{
    return str_contains($ref, 'bsky.app/profile/') || str_contains($ref, 'bsky.brid.gy/ap/did:');
}
function ap_bsky_actor_refresh_enqueue(int $owner, string $actor, bool $force = false): void
{
    $GLOBALS['fixture_bsky_refresh'][] = [$owner, $actor, $force];
}
function ap_redis_lock(string $key, int $ttl = 30): bool
{
    $GLOBALS['fixture_locks'][] = [$key, $ttl];
    return true;
}
function ap_redis_queue_push(string $queue, string $payload): bool
{
    $GLOBALS['fixture_pushes'][] = [$queue, $payload];
    return true;
}

vaak_eval_functions($root . '/ap-avatar-refresh.php', [
    'ap_avatar_is_usable',
    'ap_avatar_actor_aliases',
    'ap_avatar_host_budget_take',
    'ap_avatar_pick',
    'ap_avatar_bsky_did',
    'ap_avatar_is_bsky_app',
    'ap_avatar_queue_bsky',
    'ap_avatar_queue_refresh',
    'ap_avatar_mark_stale',
    'ap_avatar_refresh_resolve',
]);

if (!defined('AP_REMOTE_AVATAR_FALLBACK')) {
    define('AP_REMOTE_AVATAR_FALLBACK', 'https://mkultra.monster/img/avatar/default.webp');
}

$n = 0;
$check = static function (bool $ok, string $label) use (&$n): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $n++;
};

$now = 1_700_000_000;
$fresh = ['username' => 'alice', 'icon_source_url' => 'https://cdn.example/a.png', 'updated_at' => gmdate('c', $now - 3600)];
$check(ap_remote_actor_needs_refresh($fresh, $now, false, 0) === false, 'fresh icon stays cached');
$check(ap_remote_actor_needs_refresh($fresh, $now, true, $now + 99) === true, 'force bypasses freshness and icon miss');
$check(ap_remote_actor_needs_refresh(null, $now, false, 0) === true, 'missing row fetches');
$check(ap_remote_actor_needs_refresh(null, $now, false, $now + 60) === false, 'icon miss holds a missing row');
$placeholder = ['username' => '1234567', 'icon_source_url' => 'https://cdn.example/a.png', 'updated_at' => gmdate('c', $now)];
$check(ap_remote_actor_needs_refresh($placeholder, $now, false, $now + 60) === true, 'placeholder username still fetches');
$iconless = ['username' => 'alice', 'icon_source_url' => '', 'updated_at' => gmdate('c', $now)];
$check(ap_remote_actor_needs_refresh($iconless, $now, false, 0) === true, 'empty icon refetches');
$check(ap_remote_actor_needs_refresh($iconless, $now, false, $now + 60) === false, 'icon miss holds an empty icon');
$stale = ['username' => 'alice', 'icon_source_url' => 'https://cdn.example/a.png', 'updated_at' => gmdate('c', $now - 13 * 3600)];
$check(ap_remote_actor_needs_refresh($stale, $now, false, 0) === true, '12h icon refreshes');

$check(ap_remote_actor_as2_media_url('https://cdn.example/a.png') === 'https://cdn.example/a.png', 'string icon');
$check(ap_remote_actor_as2_media_url(['type' => 'Image', 'url' => 'https://cdn.example/b.png']) === 'https://cdn.example/b.png', 'url string');
$check(ap_remote_actor_as2_media_url(['url' => ['href' => 'https://cdn.example/c.png']]) === 'https://cdn.example/c.png', 'url href');
$check(ap_remote_actor_as2_media_url(['href' => 'https://cdn.example/d.png']) === 'https://cdn.example/d.png', 'link href');
$check(ap_remote_actor_as2_media_url([['type' => 'Image', 'url' => 'https://cdn.example/e.png']]) === 'https://cdn.example/e.png', 'icon list');
$check(ap_remote_actor_as2_media_url('http://cdn.example/a.png') === null, 'http icon rejected');
$check(ap_remote_actor_as2_media_url('https://127.0.0.1/a.png') === null, 'loopback icon rejected');

$did = 'did:plc:abc123actor';
$cdn = 'https://cdn.bsky.app/img/avatar/plain/did:plc:abc123actor/bafkreightexamplecidvalue1@jpeg';
$check(ap_bsky_avatar_public_url($cdn, $did) === $cdn, 'cdn string kept');
$blob = ['$type' => 'blob', 'ref' => ['$link' => 'bafkreightexamplecidvalue1'], 'mimeType' => 'image/png'];
$check(
    ap_bsky_avatar_public_url($blob, $did) === 'https://cdn.bsky.app/img/avatar/plain/did:plc:abc123actor/bafkreightexamplecidvalue1@png',
    'blob png'
);
$check(
    ap_bsky_avatar_public_url('bafkreightexamplecidvalue1', $did) === 'https://cdn.bsky.app/img/avatar/plain/did:plc:abc123actor/bafkreightexamplecidvalue1@jpeg',
    'bare cid'
);
$check(ap_bsky_avatar_public_url($blob, 'did%3Aplc%3Aabc') === null, 'encoded did rejected');
$check(ap_bsky_avatar_public_url($blob, '') === null, 'blob without did rejected');
$check(!str_contains((string) ap_bsky_avatar_public_url($blob, $did), 'did%3A'), 'did is not percent-encoded');

$fallback = AP_REMOTE_AVATAR_FALLBACK;
$check(ap_avatar_pick('https://r2.example/a.webp', 'https://cdn.example/icon.png', 'https://cdn.bsky.app/x') === 'https://r2.example/a.webp', 'r2 wins');
$check(ap_avatar_pick($fallback, 'https://cdn.example/icon.png', null) === 'https://cdn.example/icon.png', 'fallback is not a real avatar');
$check(ap_avatar_pick(null, null, null) === null, 'nothing known');
$check(in_array('https://social.example/users/alice', ap_avatar_actor_aliases('https://social.example/@alice'), true), 'at alias');

$GLOBALS['ap_avatar_host_budget'] = [];
$host = 'https://budget.example/users/';
for ($i = 0; $i < 8; $i++) {
    $check(ap_avatar_host_budget_take($host . $i), 'budget slot ' . $i);
}
$check(ap_avatar_host_budget_take($host . 'overflow') === false, 'ninth same-host enqueue dropped');
$check(ap_avatar_host_budget_take('https://other.example/users/a') === true, 'other host has its own budget');

$uniq = bin2hex(random_bytes(4));
for ($i = 1; $i <= 4; $i++) {
    $check(ap_remote_actor_host_fetch_allowed('https://cap-' . $uniq . '.example/users/' . $i), 'fetch cap ' . $i);
}
$check(ap_remote_actor_host_fetch_allowed('https://cap-' . $uniq . '.example/users/5') === false, 'fifth same-request fetch denied');
$GLOBALS['ap_remote_actor_skip_host_cap'] = true;
$check(ap_remote_actor_host_fetch_allowed('https://cap-' . $uniq . '.example/users/6') === true, 'opt-in backfill can skip the cap');
$GLOBALS['ap_remote_actor_skip_host_cap'] = false;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['fixture_db'] = $db;
$db->exec("CREATE TABLE remote_media_cache (actor_id TEXT, kind TEXT, public_url TEXT, fetched_at TEXT);
CREATE TABLE remote_actors (actor_id TEXT, icon_source_url TEXT, updated_at TEXT);
CREATE TABLE bsky_actor_profiles (actor_ref TEXT, did TEXT, profile_json TEXT);");
$db->exec("INSERT INTO remote_media_cache VALUES ('https://social.example/users/ada','avatar','https://r2.example/ada.webp','2020-01-01T00:00:00Z')");
$db->exec("INSERT INTO remote_actors VALUES ('https://social.example/users/bea','https://cdn.example/bea.png','2020-01-01T00:00:00Z')");
$db->exec("INSERT INTO remote_actors VALUES ('https://social.example/users/cy','" . AP_REMOTE_AVATAR_FALLBACK . "','2020-01-01T00:00:00Z')");
$profile = json_encode(['avatar' => 'https://cdn.bsky.app/img/avatar/plain/did:plc:dee/bafkreightexamplecidvalue1@jpeg']);
$db->prepare('INSERT INTO bsky_actor_profiles VALUES (?,?,?)')->execute([
    'https://bsky.app/profile/did:plc:dee',
    'did:plc:dee',
    $profile,
]);
$GLOBALS['fixture_warm'] = [];
$GLOBALS['fixture_media'] = [];
$GLOBALS['fixture_bsky_refresh'] = [];
$GLOBALS['fixture_pushes'] = [];
$GLOBALS['ap_avatar_host_budget'] = [];
$urls = ap_avatar_refresh_resolve([
    'https://social.example/users/ada',
    'https://social.example/@bea',
    'https://social.example/users/cy',
    'https://bsky.app/profile/did:plc:dee',
    'https://social.example/users/missing',
], []);
$check(($urls['https://social.example/users/ada'] ?? '') === 'https://r2.example/ada.webp', 'resolve r2');
$check(($urls['https://social.example/@bea'] ?? '') === 'https://cdn.example/bea.png', 'resolve alias icon');
$check(!isset($urls['https://social.example/users/cy']), 'placeholder url is not returned');
$check(str_starts_with($urls['https://bsky.app/profile/did:plc:dee'] ?? '', 'https://cdn.bsky.app/'), 'resolve bsky profile');
$check(!isset($urls['https://social.example/users/missing']), 'unknown actor omitted');
$check(in_array('https://social.example/users/cy', array_column($GLOBALS['fixture_warm'], 'actor'), true), 'fallback queues actor warm');
$check(in_array('https://social.example/users/missing', $GLOBALS['fixture_media'], true), 'missing icon still queues media');
$check($GLOBALS['fixture_pushes'] === [], 'known bsky avatar does not enqueue');
$GLOBALS['ap_avatar_host_budget'] = [];
ap_avatar_refresh_resolve(['https://bsky.app/profile/did:plc:missingactor'], []);
$check($GLOBALS['fixture_pushes'] !== [], 'bsky miss pushes the warm queue');

$GLOBALS['fixture_pushes'] = [];
$GLOBALS['ap_avatar_host_budget'] = [];
ap_avatar_refresh_resolve(['https://bsky.app/profile/alice.bsky.example'], []);
$handlePush = json_decode((string) ($GLOBALS['fixture_pushes'][0][1] ?? ''), true);
$check(($handlePush['did'] ?? '') === 'https://bsky.app/profile/alice.bsky.example', 'handle-form bsky miss pushes the warm queue');

$GLOBALS['fixture_pushes'] = [];
$GLOBALS['ap_avatar_host_budget'] = [];
$many = [];
for ($i = 0; $i < 20; $i++) {
    $many[] = 'https://bsky.app/profile/did:plc:many' . $i;
}
ap_avatar_refresh_resolve($many, []);
$check(count($GLOBALS['fixture_pushes']) === 20, 'bsky misses are not capped by the per-host budget');

$GLOBALS['ap_avatar_host_budget'] = [];
$brokenBsky = ap_avatar_refresh_resolve(
    ['https://bsky.app/profile/did:plc:dee'],
    ['https://bsky.app/profile/did:plc:dee']
);
$check(str_starts_with($brokenBsky['https://bsky.app/profile/did:plc:dee'] ?? '', 'https://cdn.bsky.app/'), 'broken bsky avatar gets the profile cache url');

$GLOBALS['fixture_warm'] = [];
$GLOBALS['ap_avatar_host_budget'] = [];
$broken = ap_avatar_refresh_resolve(
    ['https://social.example/users/bea'],
    ['https://social.example/users/bea']
);
$check(!isset($broken['https://social.example/users/bea']), 'broken hotlink is not handed back');
$forced = $GLOBALS['fixture_warm'][0] ?? null;
$check(is_array($forced) && $forced['force'] === true, 'broken avatar forces a refresh');
$staleAt = (string) $db->query("SELECT updated_at FROM remote_actors WHERE actor_id = 'https://social.example/users/bea'")->fetchColumn();
$check(strtotime($staleAt) < time() - 12 * 3600, 'broken row is marked stale');

printf("avatar-refresh: PASS (%d checks)\n", $n);
