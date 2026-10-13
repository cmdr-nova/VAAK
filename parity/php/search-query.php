<?php
declare(strict_types=1);
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__, 2);
ob_start();
require $root . '/api/bin/search-query-reference.php';
$rows = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$cases = [];
foreach ($rows as $i => $row) {
    $cases[] = [
        'id' => 'q' . $i,
        'input' => ['query' => $row['query'], 'tag' => $row['tag']],
        'output' => ['match' => $row['match'], 'postgres' => $row['postgres']],
    ];
}
echo json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
