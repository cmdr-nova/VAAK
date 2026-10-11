<?php
declare(strict_types=1);

// Disposable PostgreSQL proof of actual PHP read/decay/check-in behavior.
// No bridge transports are invoked; linked authority is an explicit substitute.
$socket = '/tmp/vaak-library-facts-pg-socket';
if (getenv('VAAK_PHYRIAN_PASSIVE_FIXTURE') !== '1' || !is_dir($socket)) {
    fwrite(STDERR, "Requires VAAK_PHYRIAN_PASSIVE_FIXTURE=1 and the owned offline PostgreSQL socket\n");
    exit(2);
}
$fixtureDb = new PDO('pgsql:host=' . $socket . ';dbname=postgres', 'postgres', null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function ap_db(): PDO { return $GLOBALS['fixtureDb']; }
function ap_phyrian_bridge_is_linked(int $owner): bool { return $owner === 101; }
require dirname(__DIR__) . '/ap-phyrian.php';
$checks = [];
function proof(string $label, bool $ok): void {
    if (!$ok) { throw new RuntimeException($label); }
    $GLOBALS['checks'][] = $label;
}
try {
    $fixtureDb->beginTransaction();
    $schema = 'phyrian_passive_' . getmypid();
    $fixtureDb->exec('CREATE SCHEMA ' . $schema);
    $fixtureDb->exec('SET LOCAL search_path TO ' . $schema);
    $fixtureDb->exec("SET LOCAL TIME ZONE 'Europe/Berlin'");
    ap_phyrian_migrate();
    $fixtureDb->exec('CREATE TABLE ap_users (id INTEGER PRIMARY KEY, actor_id TEXT, actor_key TEXT, disabled_at TIMESTAMPTZ)');
    foreach ([101, 102, 103, 104] as $id) {
        $fixtureDb->prepare('INSERT INTO ap_users VALUES (?, ?, ?, NULL)')->execute([$id, 'https://example.test/users/' . $id, (string) $id]);
        ap_phyrian_ensure_player($id, 'https://example.test/users/' . $id);
    }
    $fixtureDb->exec("UPDATE phyrian_players SET status='imprinted',strain='Phyrian',resonance=100,
        imprinted_at=NOW()-INTERVAL '10 days',last_decay_at=NOW()-INTERVAL '2 days' WHERE owner_user_id IN (101,102)");
    $linkedBefore = ap_phyrian_ensure_player(101, 'https://example.test/users/101');
    $linkedAfter = ap_phyrian_ensure_player(101, 'https://example.test/users/101');
    proof('linked passive reads preserve authority resonance and last check-in', $linkedAfter === $linkedBefore && (int)$linkedAfter['resonance'] === 100 && $linkedAfter['last_checkin_at'] === null);
    $unlinked = ap_phyrian_ensure_player(102, 'https://example.test/users/102');
    proof('unlinked passive read applies two completed days of decay', (int)$unlinked['resonance'] === 90 && $unlinked['last_checkin_at'] === null);
    $repeat = ap_phyrian_ensure_player(102, 'https://example.test/users/102');
    proof('repeated passive read does not refill or repeat decay', (int)$repeat['resonance'] === 90 && strtotime($repeat['last_decay_at']) === strtotime($unlinked['last_decay_at']));
    $claim = ap_phyrian_checkin(102);
    proof('explicit unlinked check-in grants existing three-point policy', !empty($claim['ok']) && $claim['resonance'] === 93);
    $duplicate = ap_phyrian_checkin(102);
    proof('sequential duplicate check-in grants nothing', empty($duplicate['ok']) && (int)ap_phyrian_ensure_player(102, 'https://example.test/users/102')['resonance'] === 93);
    $fixtureDb->exec("UPDATE phyrian_players SET status='imprinted',strain='Phyrian',resonance=100,
        imprinted_at=NOW()-INTERVAL '10 days',last_decay_at=NOW()-INTERVAL '23 hours' WHERE owner_user_id=103");
    proof('less than 24 hours preserves yesterday recharge', (int)ap_phyrian_ensure_player(103, 'https://example.test/users/103')['resonance'] === 100);
    $fixtureDb->exec("UPDATE phyrian_players SET status='imprinted',strain='Phyrian',resonance=100,
        imprinted_at=NOW()-INTERVAL '12 hours',last_decay_at=NOW()-INTERVAL '2 days' WHERE owner_user_id=104");
    proof('imprint grace preserves resonance', (int)ap_phyrian_ensure_player(104, 'https://example.test/users/104')['resonance'] === 100);
    $times = $fixtureDb->query("SELECT EXTRACT(EPOCH FROM TIMESTAMPTZ '2026-10-10 17:35:06')::bigint AS php_naive,
        EXTRACT(EPOCH FROM TIMESTAMPTZ '2026-10-10 17:35:06Z')::bigint AS rust_explicit_utc")->fetch(PDO::FETCH_ASSOC);
    proof('real PostgreSQL demonstrates two-hour naive-versus-UTC bridge difference', (int)$times['rust_explicit_utc'] - (int)$times['php_naive'] === 7200);
    $fixtureDb->rollBack();
    echo json_encode(['ok'=>true,'checks'=>$checks,'timestamp_epochs'=>$times,
        'authority_substitute'=>'linked owner predicate only; no OpenSim requests; Rust timestamp semantics inspected in source, not executed'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $e) {
    if ($fixtureDb->inTransaction()) { $fixtureDb->rollBack(); }
    throw $e;
}
