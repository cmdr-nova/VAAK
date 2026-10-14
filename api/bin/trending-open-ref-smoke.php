<?php
/** Trending posts must link an id the status view can open, and say why when they cannot. */
declare(strict_types=1);

require __DIR__ . '/migration-reference-functions.php';

$checks = 0;
function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
}

reference_load(dirname(__DIR__) . '/ap-masto-entities.php', [
    'ap_masto_trend_status_rkey_pattern',
    'ap_masto_trend_status_embedded_at',
    'ap_masto_trend_status_ref_is_lossy_web',
    'ap_masto_trend_status_open_ref',
    'ap_masto_trend_status_listable',
    'ap_masto_trend_status_twin_blocked',
    'ap_masto_trend_status_twin_map',
]);

$at = 'at://did:plc:abc/app.bsky.feed.post/rkey1';
$handle = 'https://bsky.app/profile/alice.bsky.social/post/rkey1';
$didWeb = 'https://bsky.app/profile/did:plc:abc/post/rkey1';
$bridgy = 'https://bsky.brid.gy/convert/ap/' . $at;

check(ap_masto_trend_status_open_ref(['uri' => $at, 'url' => $handle, 'id' => $at]) === $at, 'native bluesky row links the at uri, not the handle permalink');
check(ap_masto_trend_status_open_ref(['uri' => $bridgy, 'url' => $didWeb]) === $bridgy, 'bridgy object id stays the stored post');
check(ap_masto_trend_status_embedded_at($bridgy) === $at, 'bridgy url still carries the at uri');
check(ap_masto_trend_status_embedded_at('https://bsky.brid.gy/convert/ap/' . rawurlencode($at)) === $at, 'encoded bridgy at uri decodes');
check(ap_masto_trend_status_open_ref([
    'uri' => 'https://social.example/users/alice/statuses/9',
    'url' => 'https://social.example/@alice/9',
]) === 'https://social.example/users/alice/statuses/9', 'federated object id beats the web alias');
check(ap_masto_trend_status_open_ref([
    'uri' => 'https://www.threads.net/@someone',
    'url' => 'https://www.threads.net/@someone',
    'content' => 'profile',
]) === '', 'threads profile is not a post');
check(!ap_masto_trend_status_listable([
    'uri' => 'https://www.threads.net/@someone',
    'url' => 'https://www.threads.net/@someone',
    'content' => 'profile',
]), 'profile-only row is left out of trending');
check(ap_masto_trend_status_open_ref(['url' => $didWeb]) === $at, 'did web url becomes an at uri');
check(ap_masto_trend_status_open_ref([
    'uri' => 'at://did:plc:abc/app.bsky.feed.post/rk_ey',
    'url' => 'https://bsky.app/profile/alice.bsky.social/post/rk_ey',
]) === 'at://did:plc:abc/app.bsky.feed.post/rk_ey', 'underscore rkey is preserved');
check(!ap_masto_trend_status_listable([
    'uri' => $at,
    'url' => $handle,
    'visibility' => 'private',
    'content' => 'secret',
]), 'private visibility is not listed');
check(!ap_masto_trend_status_listable([
    'uri' => $handle,
    'url' => $handle,
    'visibility' => 'public',
    'content' => 'handle only',
]), 'handle-only permalink is not listed');
check(ap_masto_trend_status_listable(['name' => 'tag', 'history' => []]), 'tag rows stay listable');
check(ap_masto_trend_status_listable([
    'id' => '100123',
    'name' => 'kept',
    'url' => 'https://example.test/tags/kept',
    'history' => [],
]), 'tag entities with a page url stay in the shared filter');
check(ap_masto_trend_status_twin_blocked(null), 'missing twin blocks');
check(ap_masto_trend_status_twin_blocked(['visibility' => 'private']), 'private twin blocks');
check(ap_masto_trend_status_twin_blocked(['visibility' => 'public', 'deleted_at' => '2020-01-01T00:00:00Z']), 'deleted twin blocks');
check(ap_masto_trend_status_twin_blocked(['visibility' => 'public', 'action_taken' => 'deleted']), 'deleted action blocks');
check(ap_masto_trend_status_twin_blocked(['visibility' => 'public', 'type' => 'Tombstone']), 'tombstone twin blocks');
check(!ap_masto_trend_status_twin_blocked(['visibility' => 'Public']), 'public twin stays');

$picked = [];
foreach ([
    ['uri' => $handle, 'url' => $handle, 'content' => 'lossy', 'visibility' => 'public'],
    ['uri' => $at, 'url' => $handle, 'content' => 'kept', 'visibility' => 'public'],
    ['uri' => 'https://social.example/users/a/statuses/1', 'content' => 'secret', 'visibility' => 'direct'],
] as $item) {
    if (!ap_masto_trend_status_listable($item)) {
        continue;
    }
    $picked[] = ap_masto_trend_status_open_ref($item);
}
check($picked === [$at], 'the next openable public post backfills a lossy row');

$db = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$GLOBALS['open_ref_db'] = $db;
function ap_db(): PDO
{
    return $GLOBALS['open_ref_db'];
}
$db->exec('CREATE TABLE bsky_crossposts (bsky_uri TEXT, note_id TEXT)');
$db->exec('CREATE TABLE bsky_post_links (bsky_uri TEXT, fediverse_id TEXT, ap_object_id TEXT)');
$db->exec('CREATE TABLE bsky_posts (bsky_uri TEXT, author_handle TEXT)');
$db->exec('CREATE TABLE bsky_hide_dids (owner_user_id INTEGER, did TEXT)');
$linkOnly = 'at://did:plc:link/app.bsky.feed.post/link1';
$db->exec("INSERT INTO bsky_crossposts VALUES ('{$at}', 'https://example.test/users/a/notes/1')");
$db->exec("INSERT INTO bsky_post_links VALUES ('{$linkOnly}', 'https://example.test/users/a/notes/2', '')");
$map = ap_masto_trend_status_twin_map([$at, $linkOnly, 'https://not-at.example/status']);
check(($map[$at] ?? '') === 'https://example.test/users/a/notes/1', 'crosspost twin mapped');
check(($map[$linkOnly] ?? '') === 'https://example.test/users/a/notes/2', 'post link twin mapped when no crosspost');
$empty = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['open_ref_db'] = $empty;
check(ap_masto_trend_status_twin_map([$at]) === [], 'missing twin tables do not throw');
$GLOBALS['open_ref_db'] = $db;

$focus = 'at://did:plc:fixture/app.bsky.feed.post/focus';
$db->exec("INSERT INTO bsky_posts VALUES ('{$focus}', 'alice.bsky.test')");
$db->exec("INSERT INTO bsky_posts VALUES ('at://did:plc:other/app.bsky.feed.post/focus', 'carol.test')");
$db->exec("INSERT INTO bsky_posts VALUES ('at://did:plc:fixture/app.bsky.feed.post/ab', 'alice.bsky.test')");
$db->exec("INSERT INTO bsky_posts VALUES ('at://did:plc:fixture/app.bsky.feed.post/a_b', 'alice.bsky.test')");

$GLOBALS['resolve_calls'] = 0;
if (!defined('AP_BSKY_PUBLIC_API')) define('AP_BSKY_PUBLIC_API', 'https://public.api.bsky.app');
function ap_bsky_xrpc(string $base, string $nsid, string $method, array $query = [], mixed $body = null, ?string $bearer = null, int $timeout = 8): array
{
    $GLOBALS['resolve_calls']++;
    return ['ok' => false];
}
function admin_owner_user_id(): int
{
    return 7;
}
function ap_actor_is_content_blocked(string $actor, mixed $context = null, int $owner = 0): bool
{
    return $owner === 7 && $actor === 'did:plc:blocked';
}
function ap_masto_status_by_note_id(string $id): ?array
{
    $row = $GLOBALS['notes'][$id] ?? null;
    return is_array($row) ? $row : null;
}
function ap_masto_status_from_row(array $row, bool $withAccount = true, bool $withFlags = false): array
{
    return $row;
}
function admin_direct_status_allowed(array $status, int $owner, int $depth = 0): bool
{
    return strtolower((string) ($status['visibility'] ?? '')) === 'public';
}
function admin_direct_status_content_blocked(array $status): bool
{
    return !empty($status['blocked']);
}
$GLOBALS['twin_for'] = [];
function admin_bsky_thread_local_twin(string $uri): ?string
{
    $twin = $GLOBALS['twin_for'][$uri] ?? null;
    return is_string($twin) && $twin !== '' ? $twin : null;
}

reference_load(dirname(__DIR__) . '/ap-bsky-urls.php', ['ap_bsky_https_url_from_at_uri']);
reference_load(dirname(__DIR__) . '/ap-admin.php', [
    'admin_bsky_thread_at_uri_ok',
    'admin_bsky_thread_extract_at',
    'admin_bsky_thread_like_literal',
    'admin_bsky_thread_target_uri',
    'h',
    'vaak_is_local_url',
    'admin_status_home_href',
    'admin_status_unavailable_html',
    'admin_status_bsky_unavailable_html',
]);

check($GLOBALS['resolve_calls'] === 0, 'loading helpers does not resolve handles');
check(admin_bsky_thread_target_uri($focus) === $focus, 'raw at uri resolves with no network');
check(admin_bsky_thread_target_uri('https://bsky.app/profile/did:plc:fixture/post/focus') === $focus, 'did web url resolves with no network');
check(admin_bsky_thread_target_uri('https://bsky.brid.gy/convert/ap/' . $focus) === $focus, 'bridgy url resolves with no network');
check(admin_bsky_thread_target_uri('https://bsky.brid.gy/convert/ap/' . rawurlencode($focus)) === $focus, 'encoded bridgy url resolves with no network');
check($GLOBALS['resolve_calls'] === 0, 'did and bridgy identities do not call resolveHandle');
check(admin_bsky_thread_target_uri('https://bsky.app/profile/alice.bsky.test/post/focus') === $focus, 'exact cached handle still resolves');
check($GLOBALS['resolve_calls'] === 0, 'unique cached handle does not call resolveHandle');
check(admin_bsky_thread_target_uri('https://bsky.app/profile/other.test/post/focus') === null, 'same rkey different handle cannot borrow cached source');
check($GLOBALS['resolve_calls'] === 1, 'unknown handle uses one bounded resolve');
check(admin_bsky_thread_target_uri('https://bsky.app/profile/alice.bsky.test/post/a_b') === 'at://did:plc:fixture/app.bsky.feed.post/a_b', 'underscore rkey does not match a shorter rkey');
check(admin_bsky_thread_target_uri('https://bsky.app/profile/alice.bsky.test/post/ab') === 'at://did:plc:fixture/app.bsky.feed.post/ab', 'shorter rkey stays unique beside an underscore rkey');

$remote = admin_status_unavailable_html($focus, false);
check(str_contains($remote, 'This post is unavailable.') && str_contains($remote, 'Open on its home server') && str_contains($remote, 'https://bsky.app/profile/did:plc:fixture/post/focus'), 'remote miss links to the home server');
$hidden = admin_status_unavailable_html($focus, true);
check(str_contains($hidden, 'This post is unavailable or hidden.') && !str_contains($hidden, 'Open on its home server'), 'hidden post has no home-server link');
$local = admin_status_unavailable_html('https://mkultra.monster/users/alice/notes/9', false);
check(str_contains($local, 'no longer on this instance') && !str_contains($local, 'home server'), 'local delete explains that the post is gone');

$blocked = admin_status_bsky_unavailable_html('at://did:plc:blocked/app.bsky.feed.post/focus');
check(str_contains($blocked, 'unavailable or hidden') && !str_contains($blocked, 'Open on its home server'), 'blocked bluesky author has no bypass link');
$note = 'https://mkultra.monster/users/alice/notes/twin';
$GLOBALS['twin_for'][$focus] = $note;
$GLOBALS['notes'][$note] = ['visibility' => 'private', 'uri' => $note];
$privateTwin = admin_status_bsky_unavailable_html($focus);
check(str_contains($privateTwin, 'unavailable or hidden') && !str_contains($privateTwin, 'home server'), 'private local twin stays hidden');
$GLOBALS['notes'][$note] = ['visibility' => 'public', 'uri' => $note];
$publicTwin = admin_status_bsky_unavailable_html($focus);
check(str_contains($publicTwin, 'Open on its home server'), 'public twin does not hide the home server');
unset($GLOBALS['twin_for'][$focus]);
$plain = admin_status_bsky_unavailable_html($focus);
check(str_contains($plain, 'This post is unavailable.') && str_contains($plain, 'https://bsky.app/profile/did:plc:fixture/post/focus'), 'uncached bluesky miss still links home');

function admin_trends_display_state(?array $user = null): array
{
    return ['owner' => 201, 'enabled' => true];
}
function ap_auth_current_user(): ?array
{
    return ['id' => 201];
}
function admin_trend_downrank_ref_set(int $ownerUserId): array
{
    return [];
}
function admin_trend_status_hidden(array $item, int $ownerUserId, array $refs, int $depth = 0): bool
{
    return false;
}
function ap_masto_trends_cache_read(string $kind, int $minItems = 1, bool $allowStale = false, int $ownerUserId = 0): ?array
{
    return ['age' => 1, 'stale' => false, 'items' => $GLOBALS['sidebar_items'][$kind] ?? []];
}
$GLOBALS['sidebar_items'] = [
    'statuses' => [
        ['uri' => $handle, 'url' => $handle, 'content' => 'LOSSY-POST', 'visibility' => 'public', 'account' => ['acct' => 'alice']],
        ['uri' => $at, 'url' => $handle, 'content' => 'KEPT-POST', 'visibility' => 'public', 'account' => ['acct' => 'alice']],
        ['uri' => 'https://social.example/users/a/statuses/3', 'url' => 'https://social.example/@a/3', 'content' => 'SECRET-POST', 'visibility' => 'direct', 'account' => ['acct' => 'a']],
        ['uri' => 'https://social.example/users/a/statuses/4', 'url' => 'https://social.example/@a/4', 'content' => 'FEDI-OK', 'visibility' => 'public', 'account' => ['acct' => 'a']],
    ],
];
reference_load(dirname(__DIR__) . '/ap-admin.php', ['admin_status_href', 'admin_trends_sidebar_html']);
$meta = null;
$sidebar = admin_trends_sidebar_html('home', true, $meta, false, 'statuses');
check(str_contains($sidebar, 'KEPT-POST') && str_contains($sidebar, 'FEDI-OK'), 'sidebar keeps resolvable public posts');
check(!str_contains($sidebar, 'LOSSY-POST') && !str_contains($sidebar, 'SECRET-POST'), 'sidebar omits handle-only and non-public posts');
check(str_contains($sidebar, 'did%3Aplc%3Aabc') && !str_contains($sidebar, 'alice.bsky.social'), 'sidebar href uses the DID permalink');
check(str_contains($sidebar, 'statuses%2F4') && !str_contains($sidebar, '%40a%2F4'), 'sidebar href uses the federated object id');

echo "trending-open-ref-smoke: {$GLOBALS['checks']} checks ok\n";
