<?php
declare(strict_types=1);
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__, 2);
ob_start();
require $root . '/api/bin/search-render-reference-capture.php';
$raw = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$cases = [];
foreach ($raw['captures'] as $name => $case) {
    $kind = (string) ($case['kind'] ?? '');
    if (!in_array($kind, ['hashtag', 'account', 'summary'], true)) {
        continue;
    }
    $cases[] = [
        'id' => (string) $name,
        'input' => [
            'kind' => $kind,
            'input' => $case['input'],
            'facts' => $case['facts'] ?? null,
        ],
        'output' => $case['html'],
    ];
}
echo json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
