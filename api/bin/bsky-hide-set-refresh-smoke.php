<?php
declare(strict_types=1);
/**
 * A Bluesky hide-set refresh that cannot reach the AppView must keep the
 * blocks, mutes and list members it already had. Only parts that fetched
 * completely drop rows the pull no longer returns.
 * SQLite in memory; no network.
 */
$api = getenv('VAAK_BSKY_HIDE_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');

const AP_BSKY_DEFAULT_PDS = 'https://pds.example';

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo']->exec("CREATE TABLE bsky_hide_dids (owner_user_id INTEGER NOT NULL, did TEXT NOT NULL, reason TEXT NOT NULL,
    list_uri TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL, PRIMARY KEY (owner_user_id, did, reason, list_uri))");

// nsid => response json, or null for an AppView error.
$GLOBALS['xrpc'] = [];

function ap_db(): PDO { return $GLOBALS['pdo']; }
function ap_bsky_tab_enabled(): bool { return true; }
function ap_bsky_session_row(int $owner): ?array { return $owner === 1 ? ['did' => 'did:plc:valerie', 'pds_host' => AP_BSKY_DEFAULT_PDS] : null; }
function ap_bsky_graph_sync_migrate(): void {}
function ap_bsky_access_token(int $owner, bool $force): array { return ['ok' => true, 'access' => 'tok']; }
function ap_bsky_feed_hosts(string $pds): array { return [$pds]; }
function ap_bsky_xrpc(string $base, string $nsid, string $method = 'GET', ?array $query = null, ?array $body = null, ?string $bearer = null, int $t = 8): array
{
    $key = $nsid === 'app.bsky.graph.getList' ? $nsid . ' ' . ($query['list'] ?? '') : $nsid;
    $j = array_key_exists($key, $GLOBALS['xrpc']) ? $GLOBALS['xrpc'][$key] : ['cursor' => null];
    return $j === null ? ['ok' => false, 'status' => 500, 'error' => 'InternalServerError'] : ['ok' => true, 'status' => 200, 'json' => $j];
}

reference_load($api . '/ap-bsky.php', [
    'ap_bsky_hide_did_add', 'ap_bsky_hide_did_set_clear_cache', 'ap_bsky_refresh_hide_set',
]);

$checks = 0;
function hs_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}
function hs_dids(string $reason): array
{
    $st = ap_db()->prepare('SELECT did FROM bsky_hide_dids WHERE owner_user_id = 1 AND reason = ? ORDER BY did');
    $st->execute([$reason]);
    return array_column($st->fetchAll(), 'did');
}

$old = '2026-10-01T00:00:00+00:00';
$seed = ap_db()->prepare('INSERT INTO bsky_hide_dids VALUES (1, ?, ?, ?, ?)');
$seed->execute(['did:plc:oldblock', 'block', '', $old]);
$seed->execute(['did:plc:oldmute', 'mute', '', $old]);
$seed->execute(['did:plc:listed', 'listblock', 'at://list/a', $old]);
$seed->execute(['did:plc:gone', 'listblock', 'at://list/b', $old]);

// AppView down for everything: nothing is removed.
$GLOBALS['xrpc'] = [
    'app.bsky.graph.getBlocks' => null,
    'app.bsky.graph.getMutes' => null,
    'app.bsky.graph.getListBlocks' => null,
    'app.bsky.graph.getListMutes' => null,
];
$res = ap_bsky_refresh_hide_set(1, true);
hs_check(empty($res['ok']), 'a failed pull reports not ok');
hs_check(hs_dids('block') === ['did:plc:oldblock'], 'failed getBlocks keeps blocks');
hs_check(hs_dids('mute') === ['did:plc:oldmute'], 'failed getMutes keeps mutes');
hs_check(hs_dids('listblock') === ['did:plc:gone', 'did:plc:listed'], 'failed list index keeps list members');

// Blocks fetch, mutes fail, one list fails: only complete parts prune.
$GLOBALS['xrpc'] = [
    'app.bsky.graph.getBlocks' => ['blocks' => [['did' => 'did:plc:newblock']]],
    'app.bsky.graph.getMutes' => null,
    'app.bsky.graph.getListBlocks' => ['lists' => [['uri' => 'at://list/a'], ['uri' => 'at://list/b']]],
    'app.bsky.graph.getList at://list/a' => ['items' => [['subject' => ['did' => 'did:plc:listed']]]],
    'app.bsky.graph.getList at://list/b' => null,
];
$res = ap_bsky_refresh_hide_set(1, true);
hs_check(empty($res['ok']), 'a partial pull reports not ok');
hs_check(hs_dids('block') === ['did:plc:newblock'], 'complete getBlocks replaces blocks');
hs_check(hs_dids('mute') === ['did:plc:oldmute'], 'failed getMutes still keeps mutes');
hs_check(hs_dids('listblock') === ['did:plc:gone', 'did:plc:listed'], 'failed list keeps its members');

// Everything fetches: rows the pull no longer returns are dropped.
$GLOBALS['xrpc'] = [
    'app.bsky.graph.getBlocks' => ['blocks' => [['did' => 'did:plc:newblock']]],
    'app.bsky.graph.getMutes' => ['mutes' => []],
    'app.bsky.graph.getListBlocks' => ['lists' => [['uri' => 'at://list/a']]],
    'app.bsky.graph.getList at://list/a' => ['items' => [['subject' => ['did' => 'did:plc:listed']]]],
];
ap_db()->exec("UPDATE bsky_hide_dids SET updated_at = '$old'");
$res = ap_bsky_refresh_hide_set(1, true);
hs_check(!empty($res['ok']), 'a full pull reports ok');
hs_check(hs_dids('block') === ['did:plc:newblock'], 'full pull keeps current block');
hs_check(hs_dids('mute') === [], 'full pull drops an unmuted account');
hs_check(hs_dids('listblock') === ['did:plc:listed'], 'full pull drops an unsubscribed list');

@unlink(sys_get_temp_dir() . '/vaak-bsky-hide-1.json');
echo "bsky-hide-set-refresh-smoke: ok ({$checks} checks)\n";
