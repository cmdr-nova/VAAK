<?php
declare(strict_types=1);

/**
 * Grid lineage mirror for the Phyrian family tree.
 * Never calls OpenSim: a stub stands in for vaak_lineage_export. SQLite
 * stands in for phyrian_grid_lineage.
 */
$apiDir = dirname(__DIR__);
require_once $apiDir . '/ap-phyrian.php';
require_once $apiDir . '/ap-phyrian-bridge.php';

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$a = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$b = 'BBBBBBBB-BBBB-BBBB-BBBB-BBBBBBBBBBBB';
$rows = ap_phyrian_bridge_normalize_lineage(['players' => [
    ['avatar_uuid' => $a, 'avatar_name' => "  Ada\n <i>Gridborn</i> ", 'parent_uuid' => $b, 'strain' => 'Grid Tide', 'generation' => 99, 'imprinted_at' => '2026-10-18T10:00:00Z'],
    ['avatar_uuid' => $b, 'avatar_name' => 'Bex Root', 'parent_uuid' => 'not-a-uuid', 'strain' => 'Grid Tide', 'generation' => 0, 'imprinted_at' => '2026-10-18 12:00:00'],
    ['avatar_uuid' => $a, 'avatar_name' => 'Duplicate', 'strain' => 'X'],
    ['avatar_uuid' => 'bogus', 'avatar_name' => 'Bad', 'strain' => 'X'],
    ['avatar_uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc', 'parent_uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc', 'strain' => 'Self', 'imprinted_at' => '2026-10-18T12:00:00+02:00'],
    'junk',
]]);
$check(count($rows) === 3, 'bad uuids, duplicates and junk are dropped');
$check($rows[0]['avatar_name'] === 'Ada Gridborn', 'names are stripped and collapsed');
$check($rows[0]['parent_uuid'] === strtolower($b), 'parent uuids are lowercased');
$check($rows[0]['generation'] === 10 && $rows[1]['generation'] === 1, 'generation is clamped');
$check($rows[1]['parent_uuid'] === null && $rows[2]['parent_uuid'] === null, 'bad or self parents become none');
$check($rows[0]['imprinted_at'] === '2026-10-18T10:00:00Z' && $rows[2]['imprinted_at'] === '2026-10-18T12:00:00+02:00', 'explicit-offset timestamps are kept');
$check($rows[1]['imprinted_at'] === null, 'naive timestamps are dropped instead of guessed');
$check(ap_phyrian_bridge_normalize_lineage([]) === [] && ap_phyrian_bridge_normalize_lineage(['players' => 'x']) === [], 'missing players list normalizes to nothing');

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE phyrian_grid_lineage (avatar_uuid TEXT PRIMARY KEY, avatar_name TEXT, parent_uuid TEXT, strain TEXT, generation INTEGER, imprinted_at TEXT, synced_at TEXT)');
$db->exec("INSERT INTO phyrian_grid_lineage (avatar_uuid, avatar_name, strain, generation) VALUES ('dddddddd-dddd-dddd-dddd-dddddddddddd', 'Stale', 'Old', 1)");

$calls = [];
$ok = static function (string $action, array $body) use (&$calls, $a, $b): array {
    $calls[] = $action;
    return ['ok' => true, 'http' => 200, 'data' => ['ok' => true, 'players' => [
        ['avatar_uuid' => $a, 'avatar_name' => 'Ada', 'parent_uuid' => $b, 'strain' => 'Grid Tide', 'generation' => 3, 'imprinted_at' => '2026-10-18T10:00:00Z'],
        ['avatar_uuid' => $b, 'avatar_name' => 'Bex', 'strain' => 'Grid Tide', 'generation' => 2],
    ]]];
};
$res = ap_phyrian_bridge_lineage_sync($db, $ok);
$check(!empty($res['ok']) && $res['rows'] === 2 && $calls === ['vaak_lineage_export'], 'sync calls the export once and stores its rows');
$stored = $db->query('SELECT avatar_uuid, parent_uuid FROM phyrian_grid_lineage ORDER BY avatar_uuid')->fetchAll(PDO::FETCH_KEY_PAIR);
$check(count($stored) === 2 && !isset($stored['dddddddd-dddd-dddd-dddd-dddddddddddd']), 'sync replaces stale rows');
$check(($stored[$a] ?? '') === strtolower($b), 'parent link is stored');

$missing = static fn (string $action, array $body): array => ['ok' => false, 'http' => 400, 'error' => 'Unknown action.'];
$res = ap_phyrian_bridge_lineage_sync($db, $missing);
$check(empty($res['ok']) && (int) $db->query('SELECT COUNT(*) FROM phyrian_grid_lineage')->fetchColumn() === 2, 'a grid without the export changes nothing');
$noList = static fn (string $action, array $body): array => ['ok' => true, 'http' => 200, 'data' => ['ok' => true]];
$res = ap_phyrian_bridge_lineage_sync($db, $noList);
$check(empty($res['ok']) && (int) $db->query('SELECT COUNT(*) FROM phyrian_grid_lineage')->fetchColumn() === 2, 'a response without players does not wipe the mirror');

$broken = new PDO('sqlite::memory:');
$broken->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$res = ap_phyrian_bridge_lineage_sync($broken, $ok);
$check(empty($res['ok']) && !$broken->inTransaction(), 'a store failure rolls back and reports');

if ($failures !== []) {
    fwrite(STDERR, "phyrian-grid-lineage-smoke FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "phyrian-grid-lineage-smoke PASS ({$checks} checks)\n";
