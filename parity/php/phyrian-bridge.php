<?php
declare(strict_types=1);
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__, 2);
require_once $root . '/api/ap-phyrian.php';
require_once $root . '/api/ap-phyrian-bridge.php';
$path = $argv[1] ?? ($root . '/parity/fixtures/phyrian-bridge.json');
$rows = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$cases = [];
foreach ($rows as $row) {
    $kind = (string) ($row['kind'] ?? '');
    if ($kind === 'envelope') {
        $output = ap_phyrian_bridge_request_payload(
            (string) $row['action'],
            is_array($row['body'] ?? null) ? $row['body'] : [],
            (string) $row['secret']
        );
    } elseif ($kind === 'decode') {
        $output = ap_phyrian_bridge_decode_response((int) $row['http'], (string) $row['raw']);
    } else {
        fwrite(STDERR, "phyrian-bridge: unknown kind {$kind}\n");
        exit(1);
    }
    $cases[] = ['id' => (string) $row['id'], 'input' => $row, 'output' => $output];
}
echo json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
