<?php
/**
 * Rust fan-out delivery worker (cutover D2) against Postgres and Redis.
 *
 *   --seed   queue rows that exercise the worker without any network:
 *            a host that does not resolve, a Bluesky activity, a suspended
 *            host, a probe to that host, and an expired lease.
 *   --check  after a worker started with VAAK_FANOUT_DELIVERY_RUST=1 has
 *            run, every row must be in the state the PHP worker
 *            (api/ap-fanout-delivery-worker.php) would leave it in, and
 *            PHP must see the Rust heartbeat.
 */
declare(strict_types=1);

define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__) . '/ap-inbox.php';

$mode = $argv[1] ?? '';
$db = ap_db();
$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void {
    if (!$ok) {
        fwrite(STDERR, "fanout-rust-delivery: FAIL {$why}\n");
        exit(1);
    }
    $n++;
};

$actor = 'https://vaak.test/users/ci_owner';
$keyId = $actor . '#main-key';
$rows = [
    'unresolved' => ['https://nowhere.invalid/inbox', ['id' => 'https://vaak.test/a/1', 'type' => 'Create', 'actor' => $actor], null, 'pending', 0],
    'bluesky' => ['https://other.invalid/inbox', ['id' => 'https://vaak.test/a/2', 'type' => 'Like', 'actor' => 'did:plc:abc'], null, 'pending', 0],
    'suspended' => ['https://dead.invalid/inbox', ['id' => 'https://vaak.test/a/3', 'type' => 'Create', 'actor' => $actor], null, 'pending', 0],
    'probe' => ['https://dead.invalid/users/x/inbox', ['id' => 'https://vaak.test/a/4', 'type' => 'Create', 'actor' => $actor], 'host-probe', 'pending', 0],
    'lease' => ['https://lease.invalid/inbox', ['id' => 'https://vaak.test/a/5', 'type' => 'Announce', 'actor' => $actor], null, 'processing', 3],
];

if ($mode === '--seed') {
    $db->exec('DELETE FROM ap_fanout_delivery_queue');
    $db->exec("DELETE FROM ap_fanout_host_health WHERE host LIKE '%.invalid'");
    $now = gmdate('c');
    $old = gmdate('c', time() - 1200);
    $insert = $db->prepare('INSERT INTO ap_fanout_delivery_queue
        (activity_key, inbox_url, activity_json, key_id, priv_path, status, priority, attempts, next_attempt_at, claimed_at, last_error, created_at, updated_at)
        VALUES (?,?,?,?,?,?,50,?,?,?,?,?,?)');
    foreach ($rows as [$inbox, $activity, $lastError, $status, $attempts]) {
        $insert->execute([
            $activity['id'], $inbox, json_encode($activity, JSON_UNESCAPED_SLASHES), $keyId, '/nonexistent/key.pem',
            $status, $attempts, $old, $status === 'processing' ? $old : null, $lastError, $old, $old,
        ]);
    }
    // 25 dead failures that started 10 days ago and nothing since: suspended.
    $streak = gmdate('c', time() - 10 * 86400);
    $db->prepare('INSERT INTO ap_fanout_host_health (host, streak_started_at, consecutive_terminal_failures, last_failure_at, last_error, updated_at)
        VALUES (?,?,?,?,?,?)')->execute(['dead.invalid', $streak, 25, $now, 'No HTTP response from inbox', $now]);
    $check(ap_fanout_host_is_suspended_host('dead.invalid'), 'fixture host is suspended for PHP');
    echo "fanout-rust-delivery: seeded {$n} checks ok\n";
    exit(0);
}

if ($mode !== '--check') {
    fwrite(STDERR, "usage: fanout-rust-delivery-smoke.php --seed|--check\n");
    exit(2);
}

$byKey = [];
$deadline = time() + 30;
do {
    $byKey = [];
    foreach ($db->query('SELECT * FROM ap_fanout_delivery_queue')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byKey[(string) $row['activity_key']] = $row;
    }
    $settled = count($byKey) === count($rows);
    foreach ($byKey as $row) {
        if ($row['status'] === 'processing' || ((int) $row['attempts'] === 0 && $row['status'] === 'pending')) {
            $settled = false;
        }
    }
    if ($settled && (int) ($byKey['https://vaak.test/a/5']['attempts'] ?? 0) >= 5) {
        break;
    }
    usleep(250000);
} while (time() < $deadline);

$get = static fn(string $name): array => $byKey[$rows[$name][1]['id']] ?? [];

$r = $get('unresolved');
$check(($r['status'] ?? '') === 'pending', 'unresolved host is retried');
$check((int) ($r['attempts'] ?? 0) === 1, 'unresolved host counts one attempt');
$check(($r['last_error'] ?? '') === 'Inbox host is unavailable or not public', 'unresolved host error is PHP text');
$check(($r['next_attempt_at'] ?? '') > gmdate('c'), 'unresolved host waits before retrying');
$check(array_key_exists('claimed_at', $r) && $r['claimed_at'] === null, 'lease released');

$r = $get('bluesky');
$check(($r['status'] ?? '') === 'failed', 'Bluesky activity is not delivered');
$check(($r['last_error'] ?? '') === 'Bluesky activity is not delivered', 'Bluesky error text');

$r = $get('suspended');
$check(($r['status'] ?? '') === 'failed', 'suspended host fails without a request');
$check(($r['last_error'] ?? '') === 'Suspended remote host', 'suspended host error text');

$r = $get('probe');
$check(($r['status'] ?? '') === 'failed', 'a failed probe to a suspended host is terminal');
$health = $db->query("SELECT consecutive_terminal_failures, last_error FROM ap_fanout_host_health WHERE host = 'dead.invalid'")->fetch(PDO::FETCH_ASSOC);
$check((int) ($health['consecutive_terminal_failures'] ?? 0) === 26, 'probe failure extends the dead streak once');
$check(($health['last_error'] ?? '') === 'Inbox host is unavailable or not public', 'host health keeps the probe error');

$r = $get('lease');
// Reclaimed (attempt 4, "Worker lease expired"), then tried in the same pass (attempt 5).
$check(($r['status'] ?? '') === 'pending', 'expired lease is reclaimed and retried');
$check((int) ($r['attempts'] ?? 0) === 5, 'expired lease counts the reclaim and the try');

$check(ap_fanout_rust_worker_active(), 'PHP sees the Rust heartbeat');
// CI starts the worker with VAAK_FANOUT_SYNC_RUST=1 (cutover D3).
$check(ap_fanout_rust_sync_active(), 'PHP sees the Rust sync heartbeat');
$stats = ap_redis_client()?->hGetAll('vaak:fanout_delivery_rust:stats:v1') ?: [];
$check((int) ($stats['claimed'] ?? 0) >= 5, 'worker counts its claims');

echo "fanout-rust-delivery: {$n} checks ok\n";
