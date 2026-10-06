<?php
declare(strict_types=1);

/**
 * Compare PHP and Rust Phyrian mutations against an isolated PostgreSQL
 * database. This is intentionally opt-in: it refuses production-looking DSNs
 * and requires a separately started Rust worker pointed at the same database.
 *
 * Required environment:
 *   VAAK_PHYRIAN_PARITY_DATABASE_URL=pgsql:dbname=vaak_phyrian_test
 *   VAAK_PHYRIAN_PARITY_RUST_URL=http://127.0.0.1:9878
 *   VAAK_PHYRIAN_MUTATION_TOKEN=temporary-test-token
 */
$dsn = trim((string) (getenv('VAAK_PHYRIAN_PARITY_DATABASE_URL') ?: ''));
$rustUrl = rtrim(trim((string) (getenv('VAAK_PHYRIAN_PARITY_RUST_URL') ?: '')), '/');
$token = trim((string) (getenv('VAAK_PHYRIAN_MUTATION_TOKEN') ?: ''));
if ($dsn === '' || $rustUrl === '' || $token === '') {
    fwrite(STDERR, "phyrian-transaction-parity: requires isolated DB URL, Rust URL, and test token\n");
    exit(2);
}
if (preg_match('/novalandia|mkultra|144\.91\.124\.35/i', $dsn . ' ' . $rustUrl)) {
    fwrite(STDERR, "phyrian-transaction-parity: refusing production-looking target\n");
    exit(2);
}
putenv('AP_DB_DSN=' . $dsn);
putenv('VAAK_PHYRIAN_RUST_MUTATIONS=0');
require_once dirname(__DIR__) . '/ap-db.php';
require_once dirname(__DIR__) . '/ap-phyrian.php';

$db = ap_db();
$db->exec("CREATE TABLE IF NOT EXISTS ap_users (
    id INTEGER PRIMARY KEY, actor_id TEXT NOT NULL, actor_key TEXT NOT NULL,
    username TEXT NOT NULL, disabled_at TIMESTAMPTZ NULL
)");
ap_phyrian_migrate($db);

function test_user(int $id): void
{
    ap_db()->prepare(
        "INSERT INTO ap_users (id, actor_id, actor_key, username, disabled_at)
         VALUES (?, ?, ?, ?, NULL)
         ON CONFLICT (id) DO UPDATE SET disabled_at = NULL"
    )->execute([$id, 'https://mkultra.monster/users/test' . $id, 'test' . $id, 'test' . $id]);
}

/** @return array<string,mixed> */
function rust_call(string $url, string $token, array $payload): array
{
    $ch = curl_init($url . '/internal/phyrian/mutate');
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-VAAK-Internal-Token: ' . $token],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        throw new RuntimeException("Rust mutation returned HTTP {$http} with invalid JSON");
    }
    $data['_http'] = $http;
    return $data;
}

function assert_equal(string $label, mixed $left, mixed $right): void
{
    if ($left !== $right) {
        throw new RuntimeException($label . " mismatch\nPHP=" . json_encode($left) . "\nRust=" . json_encode($right));
    }
}

/** @return array<string,mixed> */
function request_row(int $id): array
{
    $st = ap_db()->prepare('SELECT kind, from_owner_id, to_owner_id, status FROM phyrian_requests WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string,mixed> */
function player_row(int $id): array
{
    $st = ap_db()->prepare(
        'SELECT status, COALESCE(strain, \'\') AS strain, resonance, generation,
                CASE WHEN imprinted_by_owner_id IS NULL THEN 0 ELSE 1 END AS has_parent,
                inductions_given, resonance_exchanges
         FROM phyrian_players WHERE owner_user_id = ?'
    );
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

try {
    foreach (range(101, 134) as $id) {
        test_user($id);
        ap_phyrian_ensure_player($id, 'https://mkultra.monster/users/test' . $id);
    }

    // Create and deny: compares request rows and the denial transition.
    // Use a valid non-origin sender so this exercises the normal peer path;
    // origin imprint creation is intentionally PHP-owned and tested by the
    // separate fixture suite.
    ap_db()->exec("UPDATE phyrian_players SET status='imprinted', strain='Voidborne', resonance=50, generation=2 WHERE owner_user_id IN (101,103)");
    ap_db()->exec("UPDATE phyrian_players SET status='unknown', strain=NULL, resonance=0, generation=1 WHERE owner_user_id IN (102,104)");
    $phpCreate = ap_phyrian_request_create(101, 102, 'imprint');
    $rustCreate = rust_call($rustUrl, $token, ['action' => 'create', 'from_owner_id' => 103, 'to_owner_id' => 104, 'kind' => 'imprint']);
    if (empty($phpCreate['ok']) || empty($rustCreate['ok'])) {
        throw new RuntimeException('create scenario failed PHP=' . json_encode($phpCreate) . ' Rust=' . json_encode($rustCreate));
    }
    assert_equal('create semantics',
        ['kind' => request_row((int) $phpCreate['id'])['kind'] ?? '', 'status' => request_row((int) $phpCreate['id'])['status'] ?? ''],
        ['kind' => request_row((int) $rustCreate['id'])['kind'] ?? '', 'status' => request_row((int) $rustCreate['id'])['status'] ?? '']);
    assert_equal('PHP deny', true, ap_phyrian_request_resolve(102, (int) $phpCreate['id'], false)['ok'] ?? false);
    $rustDeny = rust_call($rustUrl, $token, ['action' => 'resolve', 'owner_id' => 104, 'request_id' => (int) $rustCreate['id'], 'accept' => false]);
    assert_equal('Rust deny', true, (bool) ($rustDeny['ok'] ?? false));
    assert_equal('deny semantics',
        request_row((int) $phpCreate['id'])['status'] ?? '',
        request_row((int) $rustCreate['id'])['status'] ?? '');

    // Peer imprint: compare the state transition and induction counter. Parent
    // IDs differ between the PHP and Rust pairs by design, so compare the
    // presence of lineage rather than the fixture-specific owner number.
    ap_db()->exec("UPDATE phyrian_players SET status='imprinted', strain='Voidborne', resonance=61, generation=4, inductions_given=2 WHERE owner_user_id IN (105,107)");
    ap_db()->exec("UPDATE phyrian_players SET status='unknown', strain=NULL, resonance=0, generation=1, inductions_given=0 WHERE owner_user_id IN (106,108)");
    $phpPeer = ap_phyrian_request_create(105, 106, 'imprint');
    $rustPeer = rust_call($rustUrl, $token, ['action' => 'create', 'from_owner_id' => 107, 'to_owner_id' => 108, 'kind' => 'imprint']);
    ap_phyrian_request_resolve(106, (int) $phpPeer['id'], true);
    rust_call($rustUrl, $token, ['action' => 'resolve', 'owner_id' => 108, 'request_id' => (int) $rustPeer['id'], 'accept' => true]);
    assert_equal('peer child', player_row(106), player_row(108));
    assert_equal('peer parent', player_row(105), player_row(107));

    // Resonance: compare capped increments and exchange counters.
    ap_db()->exec("UPDATE phyrian_players SET status='imprinted', strain='Phyrian', resonance=98, generation=3, resonance_exchanges=4 WHERE owner_user_id IN (109,111)");
    ap_db()->exec("UPDATE phyrian_players SET status='imprinted', strain='Phyrian', resonance=40, generation=2, resonance_exchanges=1 WHERE owner_user_id IN (110,112)");
    $phpRes = ap_phyrian_request_create(109, 110, 'resonance');
    $rustRes = rust_call($rustUrl, $token, ['action' => 'create', 'from_owner_id' => 111, 'to_owner_id' => 112, 'kind' => 'resonance']);
    ap_phyrian_request_resolve(110, (int) $phpRes['id'], true);
    rust_call($rustUrl, $token, ['action' => 'resolve', 'owner_id' => 112, 'request_id' => (int) $rustRes['id'], 'accept' => true]);
    assert_equal('resonance from', player_row(109), player_row(111));
    assert_equal('resonance to', player_row(110), player_row(112));

    fwrite(STDOUT, "phyrian-transaction-parity: PASS (create/deny, peer imprint, resonance)\n");
} catch (Throwable $e) {
    fwrite(STDERR, "phyrian-transaction-parity: FAIL: " . $e->getMessage() . "\n");
    exit(1);
}
