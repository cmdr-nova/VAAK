<?php
declare(strict_types=1);
/**
 * A VAAK account taken down or labeled at the Bluesky AppView must keep
 * posting to its Bluesky repo, and VAAK must not read the AppView's silence
 * as "deleted". The AppView here hides every post of did:plc:self (getPosts
 * returns nothing, authed calls fail); the PLC directory and the account's PDS
 * still answer. SQLite in memory; every HTTP call is a fixture.
 */
$api = getenv('VAAK_BSKY_TAKEDOWN_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');

const AP_BSKY_DEFAULT_PDS = 'https://bsky.social';
const AP_BSKY_PUBLIC_API = 'https://public.api.bsky.app';
const SELF_DID = 'did:plc:aaaaaaaaaaaaaaaaaaaaaaaa';
const OTHER_DID = 'did:plc:bbbbbbbbbbbbbbbbbbbbbbbb';
const SELF_PDS = 'https://pds.example';
const OTHER_PDS = 'https://other-pds.example';

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo']->exec("CREATE TABLE bsky_sessions (owner_user_id INTEGER, did TEXT, handle TEXT, pds_host TEXT)");
$GLOBALS['pdo']->exec("INSERT INTO bsky_sessions VALUES (1, '" . SELF_DID . "', 'me.pds.example', '" . SELF_PDS . "')");
$GLOBALS['pdo']->exec("CREATE TABLE bsky_crossposts (note_id TEXT, owner_user_id INTEGER, bsky_uri TEXT, created_at TEXT)");
$GLOBALS['pdo']->exec("CREATE TABLE masto_statuses (note_id TEXT, local_id INTEGER)");

function ap_db(): PDO { return $GLOBALS['pdo']; }
function ap_bsky_budget_exceeded(): bool { return false; }
function ap_bsky_effective_timeout(int $t): int { return $t; }
function ap_bsky_tab_enabled(): bool { return true; }
function ap_bsky_crossposts_migrate(): void {}
function ap_bsky_post_link_by_uri(string $u): ?array { return null; }
function ap_bsky_post_item_by_uri(string $u): ?array { return null; }
function ap_bsky_crosspost_by_uri(string $u, bool $scan = false): ?array { return null; }
function ap_bsky_post_link_upsert(...$a): void {}
function ap_bsky_session_row(int $o): ?array { return ['did' => SELF_DID, 'pds_host' => SELF_PDS]; }
function ap_bsky_access_token(int $o, bool $f = false, int $t = 6): array { return ['ok' => true, 'access' => 'tok']; }
function ap_bsky_feed_hosts(string $pds): array { return [$pds, AP_BSKY_PUBLIC_API]; }
function ap_delete_local_status(int $id): array { $GLOBALS['deleted'][] = $id; return ['ok' => true]; }
function ap_bsky_imported_note_origin(string $n): string { return 'vaak'; }
function ap_bsky_delete_crosspost_for_note(int $o, string $n): array { $GLOBALS['orphans'][] = $n; return ['ok' => true]; }
function ap_bsky_tombstone_uri(string $u, ?int $o = null): bool { $GLOBALS['tombstoned'][] = $u; return true; }

/** Records that exist in each repo, keyed by at:// URI. */
$GLOBALS['records'] = [
    'at://' . SELF_DID . '/app.bsky.feed.post/mine' => ['cid' => 'cid-mine', 'value' => ['text' => 'mine']],
    'at://' . SELF_DID . '/app.bsky.feed.post/myreply' => ['cid' => 'cid-myreply', 'value' => [
        'text' => 'reply', 'reply' => [
            'root' => ['uri' => 'at://' . OTHER_DID . '/app.bsky.feed.post/root', 'cid' => 'cid-root'],
            'parent' => ['uri' => 'at://' . OTHER_DID . '/app.bsky.feed.post/root', 'cid' => 'cid-root'],
        ],
    ]],
    'at://' . OTHER_DID . '/app.bsky.feed.post/root' => ['cid' => 'cid-root', 'value' => ['text' => 'root']],
];
$GLOBALS['pds_down'] = false;
$GLOBALS['calls'] = [];

function ap_bsky_dns_txt(string $name): array
{
    return $name === '_atproto.txt.example' ? ['did=' . SELF_DID] : [];
}

function ap_bsky_identity_http_get(string $url, int $t = 4): array
{
    $GLOBALS['calls'][] = 'GET ' . $url;
    $docs = [SELF_DID => SELF_PDS, OTHER_DID => OTHER_PDS];
    foreach ($docs as $did => $pds) {
        if ($url === 'https://plc.directory/' . $did) {
            return ['ok' => true, 'status' => 200, 'body' => json_encode([
                'id' => $did,
                'alsoKnownAs' => ['at://' . ($did === SELF_DID ? 'me.pds.example' : 'friend.example')],
                'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => $pds . '/']],
            ])];
        }
    }
    if ($url === 'https://friend.example/.well-known/atproto-did') {
        return ['ok' => true, 'status' => 200, 'body' => OTHER_DID . "\n"];
    }
    return ['ok' => false, 'status' => 404, 'body' => ''];
}

function ap_bsky_xrpc(string $base, string $nsid, string $method = 'GET', ?array $q = null, ?array $b = null, ?string $bearer = null, int $t = 8, array $h = []): array
{
    $base = rtrim($base, '/');
    $GLOBALS['calls'][] = $base . ' ' . $nsid . ($bearer !== null ? ' +auth' : '');
    if ($base === AP_BSKY_PUBLIC_API) {
        // The AppView: takedown hides SELF_DID's posts; authed calls error.
        if ($bearer !== null) {
            return ['ok' => false, 'status' => 500, 'error' => 'Internal Server Error'];
        }
        if ($nsid === 'app.bsky.feed.getPosts') {
            $out = [];
            foreach (explode(',', (string) ($q['uris'] ?? '')) as $u) {
                if (isset($GLOBALS['records'][$u]) && !str_contains($u, SELF_DID)) {
                    $out[] = ['uri' => $u, 'cid' => $GLOBALS['records'][$u]['cid']];
                }
            }
            return ['ok' => true, 'status' => 200, 'json' => ['posts' => $out]];
        }
        return ['ok' => false, 'status' => 503, 'error' => 'AppView unavailable'];
    }
    if ($GLOBALS['pds_down']) {
        return ['ok' => false, 'status' => 0, 'error' => 'Operation timed out'];
    }
    if ($nsid === 'com.atproto.repo.getRecord') {
        $uri = 'at://' . $q['repo'] . '/' . $q['collection'] . '/' . $q['rkey'];
        $home = str_contains($uri, SELF_DID) ? SELF_PDS : OTHER_PDS;
        if ($base !== $home) {
            return ['ok' => false, 'status' => 400, 'json' => ['error' => 'InvalidRequest'], 'error' => 'Could not find repo'];
        }
        if (!isset($GLOBALS['records'][$uri])) {
            return ['ok' => false, 'status' => 400, 'json' => ['error' => 'RecordNotFound'], 'error' => 'Could not locate record'];
        }
        $r = $GLOBALS['records'][$uri];
        return ['ok' => true, 'status' => 200, 'json' => ['uri' => $uri, 'cid' => $r['cid'], 'value' => $r['value']]];
    }
    return ['ok' => false, 'status' => 400, 'error' => 'unexpected ' . $nsid];
}

reference_load($api . '/ap-bsky.php', [
    'ap_bsky_did_is_resolvable', 'ap_bsky_did_document', 'ap_bsky_parse_did_document',
    'ap_bsky_did_pds_endpoint', 'ap_bsky_get_record_direct', 'ap_bsky_session_dids',
    'ap_bsky_uri_is_session_repo', 'ap_bsky_resolve_handle_direct', 'ap_bsky_cid_for_uri',
    'ap_bsky_resolve_strong_ref', 'ap_bsky_reply_ref_for_parent',
    'ap_bsky_reconcile_deleted_crossposts', 'ap_bsky_purge_missing_uris',
    'ap_bsky_crosspost_should_retry',
]);

$checks = 0;
function td_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n" . implode("\n", $GLOBALS['calls']) . "\n");
        exit(1);
    }
    $GLOBALS['checks']++;
}
function td_auth_to_appview(): bool
{
    foreach ($GLOBALS['calls'] as $c) {
        if (str_starts_with($c, AP_BSKY_PUBLIC_API) && str_ends_with($c, '+auth')) {
            return true;
        }
    }
    return false;
}

$mine = 'at://' . SELF_DID . '/app.bsky.feed.post/mine';
$myReply = 'at://' . SELF_DID . '/app.bsky.feed.post/myreply';
$gone = 'at://' . SELF_DID . '/app.bsky.feed.post/gone';
$root = 'at://' . OTHER_DID . '/app.bsky.feed.post/root';

// DID documents.
td_check(ap_bsky_did_pds_endpoint(SELF_DID) === SELF_PDS, 'PLC gives the account PDS (trailing slash trimmed)');
td_check(ap_bsky_parse_did_document(SELF_DID, ['id' => OTHER_DID, 'service' => []]) === null, 'a document for another DID is refused');
td_check(ap_bsky_parse_did_document(SELF_DID, ['id' => SELF_DID, 'service' => [[
    'id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'http://pds.example']]]) === null,
    'a non-https PDS endpoint is refused');
td_check(!ap_bsky_did_is_resolvable('did:web:localhost') && !ap_bsky_did_is_resolvable('did:plc:short'), 'odd DIDs are not fetched');

// Own post hidden by the AppView: CID still comes from the PDS.
$GLOBALS['calls'] = [];
$notFound = null;
td_check(ap_bsky_cid_for_uri($mine, 1, $notFound) === 'cid-mine', 'own post CID read from its PDS despite the takedown');
td_check($notFound === false, 'found posts are not flagged missing');
td_check(!td_auth_to_appview(), 'no account token is sent to the AppView');
td_check($GLOBALS['calls'] === [SELF_PDS . ' com.atproto.repo.getRecord'], 'own repo: one unauthenticated read from its PDS, no AppView call');

$ref = ap_bsky_resolve_strong_ref($mine, 1);
td_check($ref === ['uri' => $mine, 'cid' => 'cid-mine'], 'strongRef for own taken-down post resolves');

// Reply threading: parent record read from its own PDS, root kept.
$GLOBALS['calls'] = [];
$unverified = null;
$reply = ap_bsky_reply_ref_for_parent(['uri' => $myReply, 'cid' => 'cid-myreply'], 1, $unverified);
td_check($reply['root']['uri'] === $root && $reply['parent']['uri'] === $myReply, 'nested reply keeps the real thread root');
td_check($unverified === false, 'readable parent is verified');
td_check(!td_auth_to_appview(), 'reply lookup sends no token to the AppView');

// Deleted on the PDS: reported as not found, not as "try later".
$failure = null;
td_check(ap_bsky_resolve_strong_ref($gone, 1, $failure) === null && $failure === 'not_found', 'deleted post is not_found');

// PDS and AppView both unreachable: retry, never drop the reply.
$GLOBALS['pds_down'] = true;
$failure = null;
td_check(ap_bsky_resolve_strong_ref('at://' . SELF_DID . '/app.bsky.feed.post/other', 1, $failure) === null
    && $failure === 'cid_unavailable', 'unreachable PDS reports cid_unavailable');
td_check(ap_bsky_crosspost_should_retry(['ok' => false, 'deferred' => true, 'error' => 'Reply parent record unavailable']),
    'reply with unreadable parent is retried');
td_check(ap_bsky_crosspost_should_retry(['ok' => false, 'deferred' => true, 'error' => 'Reply thread root unavailable']),
    'reply with unknown root is retried');
$unverified = null;
ap_bsky_reply_ref_for_parent(['uri' => $myReply, 'cid' => 'cid-myreply'], 1, $unverified);
td_check($unverified === true, 'unreadable parent marks the root unverified');

// Delete reconcile: AppView silence and PDS timeouts never delete posts.
$GLOBALS['pdo']->exec("INSERT INTO bsky_crossposts VALUES ('https://mkultra.monster/users/me/notes/1', 1, '$mine', '2026-11-02T00:00:02Z')");
$GLOBALS['pdo']->exec("INSERT INTO bsky_crossposts VALUES ('https://mkultra.monster/users/me/notes/2', 1, '$gone', '2026-11-02T00:00:01Z')");
$GLOBALS['pdo']->exec("INSERT INTO masto_statuses VALUES ('https://mkultra.monster/users/me/notes/1', 101)");
$GLOBALS['pdo']->exec("INSERT INTO masto_statuses VALUES ('https://mkultra.monster/users/me/notes/2', 102)");
$GLOBALS['deleted'] = [];
$throttle = sys_get_temp_dir() . '/vaak-bsky-del-reconcile-1';
@unlink($throttle);
ap_bsky_reconcile_deleted_crossposts(1, 12);
td_check($GLOBALS['deleted'] === [], 'PDS timeout deletes nothing');
$GLOBALS['pds_down'] = false;
@unlink($throttle);
$GLOBALS['calls'] = [];
$res = ap_bsky_reconcile_deleted_crossposts(1, 12);
td_check($GLOBALS['deleted'] === [102], 'only the post its PDS says is gone is deleted (taken-down AppView post kept)');
td_check($res['checked'] === 2, 'both mirrors were checked');
foreach ($GLOBALS['calls'] as $c) {
    td_check(!str_starts_with($c, AP_BSKY_PUBLIC_API), 'delete reconcile never asks the AppView: ' . $c);
}
@unlink($throttle);

// Cache purge: own posts the AppView hides stay cached; others' go.
$GLOBALS['tombstoned'] = [];
$n = ap_bsky_purge_missing_uris([$mine, $gone, 'at://' . OTHER_DID . '/app.bsky.feed.post/hidden', $root], 1);
td_check($n === 2, 'two URIs tombstoned');
td_check(!in_array($mine, $GLOBALS['tombstoned'], true), 'own post hidden by the AppView stays cached');
td_check(in_array($gone, $GLOBALS['tombstoned'], true), 'own post deleted on its PDS is purged');
td_check(!in_array($root, $GLOBALS['tombstoned'], true), 'visible post is kept');

// Handle -> DID without the AppView, checked against the DID document.
td_check(ap_bsky_resolve_handle_direct('friend.example') === OTHER_DID, 'well-known handle resolves and round-trips');
td_check(ap_bsky_resolve_handle_direct('nobody.invalid') === null, 'unresolvable handle stays null');
td_check(ap_bsky_resolve_handle_direct('txt.example') === null, 'DNS TXT pointing at a DID that does not claim the handle is refused');

echo "bsky-appview-takedown-smoke: ok ({$checks} checks)\n";
