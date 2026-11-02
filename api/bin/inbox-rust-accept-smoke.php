<?php
/**
 * Rust inbox front door (cutover F2) against Postgres, Redis and a worker
 * started with VAAK_INBOX_RUST_ACCEPT=1. A delivery signed with a key PHP
 * mirrored is spooled and drained through PHP routing; a missing key, a
 * blocked host and an actor/key mismatch are forwarded unchanged to the PHP
 * stand-in (inbox-forward-stub-fixture.php). Keys are generated in-process.
 */
declare(strict_types=1);

putenv('VAAK_INBOX_RUST_ACCEPT=1');
define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__) . '/ap-inbox.php';

$rust = rtrim((string) getenv('VAAK_INBOX_RUST_URL'), '/');
if ($rust === '') {
    fwrite(STDERR, "inbox-rust-accept: set VAAK_INBOX_RUST_URL\n");
    exit(2);
}
$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void {
    if (!$ok) {
        fwrite(STDERR, "inbox-rust-accept: FAIL {$why}\n");
        exit(1);
    }
    $n++;
};

$db = ap_db();
$db->exec("INSERT INTO ap_blocks (created_at, updated_at, scope, value, kind) VALUES ('2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', 'domain', 'blocked.test', 'block')");
ap_block_list_cached(true);
// Before anything reads the relay list, which PHP memoizes for 30 seconds.
$db->exec("INSERT INTO ap_relays (inbox_url, state, created_at, updated_at) VALUES ('https://relay.test/inbox', 'accepted', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");

$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($res, $priv);
$pem = openssl_pkey_get_details($res)['key'];

function smoke_post(string $url, string $path, string $body, string $keyId, string $priv, ?string $signedPath = null): array
{
    $date = gmdate('D, d M Y H:i:s') . ' GMT';
    $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    $signing = '(request-target): post ' . ($signedPath ?? $path) . "\nhost: mkultra.monster\ndate: {$date}\ndigest: {$digest}";
    openssl_sign($signing, $raw, $priv, OPENSSL_ALGO_SHA256);
    $sig = 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . base64_encode($raw) . '"';
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'ignore_errors' => true,
        'timeout' => 20,
        'header' => implode("\r\n", [
            'Host: mkultra.monster',
            'Content-Type: application/activity+json',
            'Date: ' . $date,
            'Digest: ' . $digest,
            'Signature: ' . $sig,
            'X-Forwarded-For: 203.0.113.7',
        ]),
        'content' => $body,
    ]]);
    $out = @file_get_contents($url . $path, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, is_string($out) ? (json_decode($out, true) ?: []) : []];
}

function smoke_like(string $actor, string $n): string
{
    return json_encode([
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $actor . '#likes/' . $n,
        'type' => 'Like',
        'actor' => $actor,
        'object' => 'https://vaak.test/users/ci_owner/statuses/' . $n,
    ], JSON_UNESCAPED_SLASHES);
}

$alice = 'https://remote.test/users/alice';
$aliceKey = $alice . '#main-key';
ap_inbox_rust_key_mirror($aliceKey, $pem);
$redis = ap_redis_client();
$check($redis && is_string($redis->get(ap_inbox_rust_key_mirror_key($aliceKey))), 'PHP mirrors a verified key for the Rust inbox');

// Fast path: spooled, answered 202, then routed by PHP.
$like = smoke_like($alice, '1');
[$status, $json] = smoke_post($rust, '/inbox', $like, $aliceKey, $priv);
$check($status === 202 && ($json['action'] ?? '') === 'spooled', 'signed delivery with a mirrored key is spooled (got ' . $status . ')');
$row = $db->query("SELECT * FROM ap_inbox_spool WHERE key_id = " . $db->quote($aliceKey) . " ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$check(is_array($row) && $row['body'] === $like && $row['state'] === 'pending' && $row['activity_type'] === 'Like', 'spool row keeps the exact body');
$check(($row['client_ip'] ?? '') === '203.0.113.7' && ($row['signature_path'] ?? '') === '/inbox', 'spool row records client address and signed path');
$out = ap_inbox_spool_process($row);
$check($out['state'] === 'done' && $out['result'] !== '', 'spooled Like routes through PHP (' . $out['result'] . ')');
$blockedObject = json_encode(['id' => $alice . '#announce-blocked', 'type' => 'Announce', 'actor' => $alice, 'object' => 'https://blocked.test/notes/1']);
$out = ap_inbox_spool_process(['id' => 0, 'client_ip' => '', 'key_id' => $aliceKey, 'request_path' => '/inbox', 'body' => $blockedObject]);
$check($out['state'] === 'dropped' && $out['result'] === 'rejected: Object instance blocked', 'a delivery routing rejects is dropped, not a process exit');

// Personal inbox path, drained by the CLI worker.
$like2 = smoke_like($alice, '2');
[$status] = smoke_post($rust, '/users/ci_owner/inbox', $like2, $aliceKey, $priv);
$check($status === 202, 'personal inbox delivery is spooled');
// A row whose run died mid-processing, already at the attempt limit.
$db->exec("INSERT INTO ap_inbox_spool (key_id, request_path, signature_path, activity_type, body, state, attempts, claimed_at)
           VALUES ('https://stale.test/users/x#main-key', '/inbox', '/inbox', 'Like', '{}', 'processing', 5, now() - interval '11 minutes')");
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/inbox-spool-drain.php') . ' --once 2>&1';
$drain = (string) shell_exec('VAAK_FPM_POOL_CONF=/nonexistent ' . $cmd);
$state = $db->query("SELECT state FROM ap_inbox_spool WHERE body = " . $db->quote($like2))->fetchColumn();
$check($state === 'done', 'drain worker processes the pending row (' . trim($drain) . ')');
$stale = $db->query("SELECT state FROM ap_inbox_spool WHERE key_id = 'https://stale.test/users/x#main-key'")->fetchColumn();
$check($stale === 'failed', 'a row stuck in processing at the attempt limit is failed, not requeued');

// Fallbacks reach PHP byte for byte.
$bob = 'https://other.test/users/bob';
[$status, $json] = smoke_post($rust, '/inbox', smoke_like($bob, '3'), $bob . '#main-key', $priv);
$check($status === 418 && ($json['forwarded'] ?? false) === true, 'unknown key is forwarded to PHP');
$check(($json['host'] ?? '') === 'mkultra.monster' && ($json['uri'] ?? '') === '/inbox', 'forward keeps Host and path');
$check(($json['body_sha256'] ?? '') === hash('sha256', smoke_like($bob, '3')) && str_contains((string) ($json['xff'] ?? ''), '203.0.113.7'), 'forward keeps body and client address');

$evil = 'https://blocked.test/users/eve';
ap_inbox_rust_key_mirror($evil . '#main-key', $pem);
[$status] = smoke_post($rust, '/inbox', smoke_like($evil, '4'), $evil . '#main-key', $priv);
$check($status === 418, 'blocked key host is forwarded, not spooled');

$mallory = 'https://remote.test/users/mallory';
$mismatched = smoke_like($mallory, '5');
[$status] = smoke_post($rust, '/inbox', $mismatched, $aliceKey, $priv);
$check($status === 202, 'verified signer who is not the actor is spooled for the drain to decide');

[$status] = smoke_post($rust, '/inbox', smoke_like($alice, '6'), $aliceKey, $priv, '/outbox');
$check($status === 418, 'bad signature is forwarded');

[$status] = smoke_post($rust, '/users/nobody_here/inbox', smoke_like($alice, '7'), $aliceKey, $priv);
$check($status === 418, 'personal inbox of an unknown user is forwarded (PHP answers 404)');

// An accepted relay forwards someone else's Announce: spooled and routed.
$relayKey = 'https://relay.test/actor#main-key';
ap_inbox_rust_key_mirror($relayKey, $pem);
$mirror = json_decode((string) $redis->get(ap_inbox_rust_key_mirror_key($relayKey)), true);
$check(($mirror['relay'] ?? null) === true && $redis->ttl(ap_inbox_rust_key_mirror_key($relayKey)) > 3600, 'relay keys are mirrored, marked, for a day');
$announce = json_encode(['id' => $alice . '#announces/8', 'type' => 'Announce', 'actor' => $alice, 'object' => 'https://vaak.test/users/ci_owner/statuses/8'], JSON_UNESCAPED_SLASHES);
[$status] = smoke_post($rust, '/inbox', $announce, $relayKey, $priv);
$check($status === 202, 'relay-forwarded Announce is spooled (got ' . $status . ')');
$row = $db->query("SELECT * FROM ap_inbox_spool WHERE key_id = " . $db->quote($relayKey))->fetch(PDO::FETCH_ASSOC);
$out = is_array($row) ? ap_inbox_spool_process($row) : ['state' => 'missing'];
$check($out['state'] === 'done', 'spooled relay Announce routes through PHP (' . ($out['result'] ?? '') . ')');
$delete = json_encode(['id' => $alice . '#delete/9', 'type' => 'Delete', 'actor' => $alice, 'object' => $alice . '/statuses/9'], JSON_UNESCAPED_SLASHES);
[$status] = smoke_post($rust, '/inbox', $delete, $relayKey, $priv);
$check($status === 202, 'relay-signed Delete for another actor is spooled too');
$announce2 = str_replace('announces/8', 'announces/10', $announce);
[$status] = smoke_post($rust, '/inbox', $announce2, $relayKey, $priv);
$check($status === 202, 'second relay Announce is spooled');
$db->exec("UPDATE ap_relays SET state = 'idle'");
shell_exec('VAAK_FPM_POOL_CONF=/nonexistent ' . $cmd);
$late = $db->query("SELECT state, result FROM ap_inbox_spool WHERE body = " . $db->quote($announce2))->fetch(PDO::FETCH_ASSOC);
$check(($late['state'] ?? '') === 'dropped' && ($late['result'] ?? '') === 'actor_mismatch', 'the drain re-checks that the relay is still accepted');
// Neither is a forward PHP takes, and origin recovery finds nothing: dropped
// where PHP answered 401.
foreach (['mismatched Like' => $mismatched, 'relay-signed Delete' => $delete] as $label => $body) {
    $r = $db->query("SELECT state, result FROM ap_inbox_spool WHERE body = " . $db->quote($body))->fetch(PDO::FETCH_ASSOC);
    $check(($r['state'] ?? '') === 'dropped' && ($r['result'] ?? '') === 'actor_mismatch', "unrecoverable {$label} is dropped by the drain");
}

$spooled = (int) $db->query("SELECT count(*) FROM ap_inbox_spool WHERE key_id <> 'https://stale.test/users/x#main-key'")->fetchColumn();
$check($spooled === 6, 'only the six signed deliveries were spooled');
$stats = $redis->hGetAll('vaak:inbox_rust:stats:v1');
$check((int) ($stats['spooled'] ?? 0) === 4 && (int) ($stats['spooled_mismatch'] ?? 0) === 2 && (int) ($stats['fwd_no_key'] ?? 0) === 1
    && (int) ($stats['fwd_blocked'] ?? 0) === 1 && !isset($stats['fwd_actor_mismatch']) && (int) ($stats['fwd_signature'] ?? 0) === 1 && (int) ($stats['fwd_unknown_user'] ?? 0) === 1, 'stats count each outcome');

echo "inbox-rust-accept: {$n} checks passed\n";
