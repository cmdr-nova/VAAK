<?php
declare(strict_types=1);
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__, 2);
require_once $root . '/api/bin/settings-parity-smoke.php';
$fixturePath = $root . '/api/fixtures/settings/profile-fields.json';
$fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$cases = [
    [
        'id' => 'canonical-fields',
        'input' => ['source' => 'vaak_settings_parity_canonical_fields'],
        'output' => vaak_settings_parity_canonical_fields(),
    ],
    [
        'id' => 'declared-fixture',
        'input' => ['source' => 'api/fixtures/settings/profile-fields.json'],
        'output' => $fixture,
    ],
];
echo json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
