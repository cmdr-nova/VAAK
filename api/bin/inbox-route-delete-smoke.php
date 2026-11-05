<?php
/**
 * Rust Delete routing (cutover F3 switch) against Postgres, Redis and a
 * worker started with VAAK_INBOX_RUST_ACCEPT=1 VAAK_INBOX_ROUTE_DELETE=1.
 *
 * Each case is sent twice with fresh ids: once to the worker, which routes
 * it itself, and once through PHP (ap_inbox_spool_process, as the drain
 * would). Results, the Delete's events row and the deleted state of the
 * timeline event must match. A Delete that touches a live mention, or comes
 * from a host an actor block names, must be left to PHP (spooled pending).
 */
declare(strict_types=1);

putenv('VAAK_INBOX_RUST_ACCEPT=1');
define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__) . '/ap-inbox.php';

$rust = rtrim((string) getenv('VAAK_INBOX_RUST_URL'), '/');
if ($rust === '') {
    fwrite(STDERR, "inbox-route-delete: set VAAK_INBOX_RUST_URL\n");
    exit(2);
}
$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void {
    if (!$ok) {
        fwrite(STDERR, "inbox-route-delete: FAIL {$why}\n");
        exit(1);
    }
    $n++;
};

function rd_post(string $url, string $body, string $keyId, string $priv): int
{
    $date = gmdate('D, d M Y H:i:s') . ' GMT';
    $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    $signing = "(request-target): post /inbox\nhost: mkultra.monster\ndate: {$date}\ndigest: {$digest}";
    openssl_sign($signing, $sig, $priv, OPENSSL_ALGO_SHA256);
    $header = sprintf('keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="%s"', $keyId, base64_encode($sig));
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
            'Host: mkultra.monster', 'Date: ' . $date, 'Digest: ' . $digest, 'Signature: ' . $header,
            'Content-Type: application/activity+json', 'X-Forwarded-For: 203.0.113.9',
        ]),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 15,
    ]]);
    @file_get_contents($url . '/inbox', false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    return $status;
}

$db = ap_db();
$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($res, $priv);
$pem = openssl_pkey_get_details($res)['key'];

$zed = 'https://route.test/users/zed';
$zedKey = $zed . '#main-key';
ap_inbox_rust_key_mirror($zedKey, $pem);
$redis = ap_redis_client();
$redis->del('vaak:inbox_rust:stats:v1');
// The worker caches actor blocks for 60 s from its first Delete, so the block
// the actor-block case needs has to exist before any request.
$db->exec("INSERT INTO ap_blocks (created_at, updated_at, scope, value, kind) VALUES ('2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', 'actor', 'https://blockhost.test/users/troll', 'block')");

$spoolRow = static function (string $body) use ($db): ?array {
    $r = $db->query('SELECT * FROM ap_inbox_spool WHERE body = ' . $db->quote($body) . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : null;
};
$deleteEvent = static function (string $objectId) use ($db): ?array {
    $r = $db->query("SELECT type, actor_id, object_id, target_actor, host, bytes, action_taken, summary, media_urls, in_reply_to,
                            spoiler_text, sensitive, visibility
                     FROM events WHERE type = 'Delete' AND object_id = " . $db->quote($objectId) . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : null;
};
$seedCreate = static function (string $objectId) use ($db, $zed): void {
    $db->prepare("INSERT INTO events (created_at, type, actor_id, object_id, action_taken) VALUES (?, 'Create', ?, ?, 'log')")
        ->execute([gmdate('c'), $zed, $objectId]);
};
$createState = static function (string $objectId) use ($db): string {
    return (string) $db->query("SELECT action_taken FROM events WHERE type = 'Create' AND object_id = " . $db->quote($objectId))->fetchColumn();
};

$cases = [
    'local_status_delete' => static fn(string $oid) => ['type' => 'Delete', 'actor' => $zed, 'object' => ['type' => 'Tombstone', 'id' => $oid]],
    'delete_noop' => static fn(string $oid) => ['type' => 'Delete', 'actor' => $zed, 'object' => $oid],
];
foreach ($cases as $expect => $make) {
    $outcomes = [];
    foreach (['rust', 'php'] as $side) {
        $oid = $zed . '/statuses/' . $expect . '-' . $side;
        if ($expect === 'local_status_delete') {
            $seedCreate($oid);
        }
        $doc = $make($oid) + ['id' => $zed . '#delete/' . $expect . '-' . $side];
        $body = json_encode($doc, JSON_UNESCAPED_SLASHES);
        if ($side === 'rust') {
            $check(rd_post($rust, $body, $zedKey, $priv) === 202, "{$expect}: worker answers 202");
            $row = $spoolRow($body);
            $check(($row['state'] ?? '') === 'done' && (int) ($row['attempts'] ?? 0) === 1, "{$expect}: Rust writes the spool row done");
            $result = (string) ($row['result'] ?? '');
        } else {
            $out = ap_inbox_spool_process(['id' => 0, 'client_ip' => '', 'key_id' => $zedKey, 'request_path' => '/inbox', 'body' => $body]);
            $result = (string) $out['result'];
        }
        $ev = $deleteEvent($oid);
        $check(is_array($ev), "{$expect}: {$side} records the Delete in events");
        $ev['object_id'] = str_replace('-' . $side, '', (string) $ev['object_id']);
        $ev['bytes'] = (int) $ev['bytes'] - 2 * strlen($side); // the two ids differ only by the side name
        $outcomes[$side] = ['result' => $result, 'event' => $ev, 'create' => $expect === 'local_status_delete' ? $createState($oid) : ''];
    }
    $check($outcomes['rust']['result'] === $expect && $outcomes['php']['result'] === $expect, "{$expect}: both sides return it (rust="
        . $outcomes['rust']['result'] . ' php=' . $outcomes['php']['result'] . ')');
    $check($outcomes['rust']['event'] == $outcomes['php']['event'], "{$expect}: the Delete events rows match\n"
        . json_encode($outcomes, JSON_UNESCAPED_SLASHES));
    $check($outcomes['rust']['create'] === $outcomes['php']['create'], "{$expect}: the deleted Create ends in the same state");
}

// A live mention for the object: PHP's branch (notification caches), so Rust
// spools it pending.
$mentioned = $zed . '/statuses/mentioned';
ap_mention_store(['owner_user_id' => 1, 'owner_actor_id' => LOCAL_ACTOR, 'object_id' => $mentioned, 'actor_id' => $zed,
    'type' => 'Create', 'content' => 'hi', 'activity_id' => $zed . '#create/m']);
$body = json_encode(['id' => $zed . '#delete/mentioned', 'type' => 'Delete', 'actor' => $zed, 'object' => $mentioned], JSON_UNESCAPED_SLASHES);
$check(rd_post($rust, $body, $zedKey, $priv) === 202, 'mention case: answered 202');
$row = $spoolRow($body);
$check(($row['state'] ?? '') === 'pending', 'mention case: left to the PHP drain');

// An actor block that names the host (seeded before the first request): PHP
// resolves block aliases, so Rust spools it pending even for another account there.
$amy = 'https://blockhost.test/users/amy';
ap_inbox_rust_key_mirror($amy . '#main-key', $pem);
$body = json_encode(['id' => $amy . '#delete/1', 'type' => 'Delete', 'actor' => $amy, 'object' => $amy . '/statuses/1'], JSON_UNESCAPED_SLASHES);
$check(rd_post($rust, $body, $amy . '#main-key', $priv) === 202, 'actor-block host: answered 202');
$check(($spoolRow($body)['state'] ?? '') === 'pending', 'actor-block host: left to the PHP drain');

$stats = $redis->hGetAll('vaak:inbox_rust:stats:v1');
$check((int) ($stats['routed_local_status_delete'] ?? 0) === 1 && (int) ($stats['routed_delete_noop'] ?? 0) === 1, 'stats count what Rust routed');
$check((int) ($stats['route_defer_mention'] ?? 0) === 1 && (int) ($stats['route_defer_actor_block'] ?? 0) === 1, 'stats count each deferral');

echo "inbox-route-delete: {$n} checks passed\n";
