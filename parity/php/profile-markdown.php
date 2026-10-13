<?php
declare(strict_types=1);
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__, 2);
ob_start();
require $root . '/api/bin/profile-secondary-components-reference.php';
$raw = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$cases = [];
foreach ($raw['markdown'] as $i => $row) {
    $cases[] = [
        'id' => 'markdown-' . $i,
        'input' => ['kind' => 'markdown', 'text' => $row['input']],
        'output' => $row['html'],
    ];
}
foreach ($raw['fields'] as $i => $row) {
    $cases[] = [
        'id' => 'field-' . $i,
        'input' => ['kind' => 'field', 'text' => $row['input']],
        'output' => $row['html'],
    ];
}
echo json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
