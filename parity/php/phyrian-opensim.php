<?php
declare(strict_types=1);
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__, 2);
require_once $root . '/api/ap-phyrian.php';
require_once $root . '/api/ap-phyrian-bridge.php';
$path = $argv[1] ?? ($root . '/rust/vaak-worker/fixtures/phyrian/opensim-normalization.json');
$casesIn = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$cases = [];
foreach ($casesIn as $case) {
    $input = is_array($case['input'] ?? null) ? $case['input'] : [];
    $cases[] = [
        'id' => (string) ($case['name'] ?? 'unnamed'),
        'input' => $input,
        'output' => ap_phyrian_bridge_normalize_opensim_player($input),
    ];
}
echo json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
