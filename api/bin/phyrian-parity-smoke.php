<?php
declare(strict_types=1);

/**
 * Pure Phyrian OpenSim normalizer parity check.
 *
 * This intentionally never opens the database or calls OpenSim. It consumes
 * the same fixture used by the Rust test so a normalization drift fails before
 * any bridge mutation ownership can move.
 */
$apiDir = dirname(__DIR__);
require_once $apiDir . '/ap-phyrian.php';
require_once $apiDir . '/ap-phyrian-bridge.php';

$fixture = dirname(__DIR__, 2) . '/rust/vaak-worker/fixtures/phyrian/opensim-normalization.json';
$raw = @file_get_contents($fixture);
if (!is_string($raw) || $raw === '') {
    fwrite(STDERR, "phyrian-parity: fixture missing: {$fixture}\n");
    exit(2);
}
$cases = json_decode($raw, true);
if (!is_array($cases)) {
    fwrite(STDERR, "phyrian-parity: invalid fixture JSON\n");
    exit(2);
}

$failures = [];
foreach ($cases as $case) {
    if (!is_array($case)) {
        $failures[] = 'non-array case';
        continue;
    }
    $name = (string) ($case['name'] ?? 'unnamed');
    $actual = ap_phyrian_bridge_normalize_opensim_player(
        is_array($case['input'] ?? null) ? $case['input'] : []
    );
    $expected = is_array($case['expected'] ?? null) ? $case['expected'] : [];
    if ($actual !== $expected) {
        $failures[] = $name . "\nexpected=" . json_encode($expected, JSON_UNESCAPED_SLASHES)
            . "\nactual=" . json_encode($actual, JSON_UNESCAPED_SLASHES);
    }
}

if ($failures !== []) {
    fwrite(STDERR, "phyrian-parity: FAIL\n" . implode("\n", $failures) . "\n");
    exit(1);
}

$envelope = ap_phyrian_bridge_request_payload(
    'vaak_player_pull',
    ['avatar_uuid' => 'abc'],
    'fixture-secret'
);
$expectedEnvelope = [
    'avatar_uuid' => 'abc',
    'action' => 'vaak_player_pull',
    'bridge_secret' => 'fixture-secret',
];
if ($envelope !== $expectedEnvelope) {
    fwrite(STDERR, "phyrian-parity: bridge envelope mismatch\n");
    exit(1);
}
fwrite(STDOUT, sprintf("phyrian-parity: PASS (%d normalization fixtures)\n", count($cases)));
