<?php
declare(strict_types=1);
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__, 2);
ob_start();
require $root . '/api/bin/profile-identity-components-reference.php';
$raw = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$cases = [];
foreach ($raw['captures'] as $i => $row) {
    $cases[] = [
        'id' => 'label-' . $i,
        'input' => ['kind' => 'label', 'actor' => $row['actor'], 'row' => $row['row']],
        'output' => [
            'label' => $row['label'],
            'flat_key' => $row['flat_key'],
            'warm' => !empty($row['warms']),
        ],
    ];
}
foreach ($raw['avatars'] as $i => $row) {
    $cases[] = [
        'id' => 'avatar-' . $i,
        'input' => ['kind' => 'avatar', 'icon' => $row['input']],
        'output' => $row['output'],
    ];
}
echo json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
