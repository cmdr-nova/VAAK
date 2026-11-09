<?php
declare(strict_types=1);

/**
 * Second Life link rules for Phyrian Strains (pure helpers only).
 * No database, no strains calls.
 */
$apiDir = dirname(__DIR__);
require_once $apiDir . '/ap-phyrian.php';
require_once $apiDir . '/ap-phyrian-bridge.php';
require_once $apiDir . '/ap-phyrian-sl.php';

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$sl = '5e5e5e5e-1111-4222-8333-444455556666';
$grid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

$check(ap_phyrian_sl_resolved_error(null) !== null, 'null avatar rejected');
$check(ap_phyrian_sl_resolved_error(['avatar_uuid' => '']) !== null, 'empty uuid rejected');
$check(
    str_contains((string) ap_phyrian_sl_resolved_error(['avatar_uuid' => $sl, 'registered' => true]), 'not live'),
    'old strains server without world field is rejected'
);
$check(
    str_contains((string) ap_phyrian_sl_resolved_error(['avatar_uuid' => $sl, 'registered' => false, 'world' => '']), 'Register'),
    'unregistered SL avatar told to register'
);
$check(
    str_contains((string) ap_phyrian_sl_resolved_error(['avatar_uuid' => $grid, 'registered' => true, 'world' => 'opensim']), 'grid avatar'),
    'grid avatar cannot be linked as SL'
);
$check(ap_phyrian_sl_resolved_error(['avatar_uuid' => $sl, 'registered' => true, 'world' => 'sl']) === null, 'registered SL avatar accepted');

$h = ap_phyrian_sl_code_hash('a7k3mq', strtoupper($sl), $grid, 7);
$check(hash_equals($h, ap_phyrian_sl_code_hash('A7K3MQ', $sl, strtoupper($grid), 7)), 'hash ignores case of code and uuids');
$check(!hash_equals($h, ap_phyrian_sl_code_hash('A7K3MQ', $sl, $grid, 8)), 'hash bound to VAAK user');
$check(!hash_equals($h, ap_phyrian_sl_code_hash('A7K3MQ', $sl, 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 7)), 'hash bound to grid avatar');

$code = ap_phyrian_sl_new_code();
$check(strlen($code) === AP_PHYRIAN_SL_CODE_LEN && preg_match('/^[A-HJ-NP-Z2-9]+$/', $code) === 1, 'code uses unambiguous alphabet');

if ($failures !== []) {
    fwrite(STDERR, "phyrian-sl-link-smoke FAIL\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo "phyrian-sl-link-smoke PASS ({$checks} checks)\n";
