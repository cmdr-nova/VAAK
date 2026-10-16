<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/ap-wafrn-crosspost.php';

$fixture = dirname(__DIR__, 2) . '/api/fixtures/wafrn-crosspost.json';
$decoded = json_decode((string) file_get_contents($fixture), true);
$cases = [];
foreach (is_array($decoded['cases'] ?? null) ? $decoded['cases'] : [] as $case) {
    if (!is_array($case)) {
        continue;
    }
    $input = $case;
    unset($input['expect'], $input['id']);
    $cases[] = [
        'id' => (string) ($case['id'] ?? ''),
        'input' => $input,
        'output' => ap_wafrn_replay($input),
    ];
}

echo json_encode(['cases' => $cases], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
