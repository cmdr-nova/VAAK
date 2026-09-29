<?php
/** Refresh the DID filter consumed by the shared Jetstream spooler. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/ap-db.php';

$stateDir = getenv('VAAK_JETSTREAM_STATE') ?: '/var/lib/mkultra/ap/jetstream';
if (!is_dir($stateDir)) mkdir($stateDir, 0750, true);
$dids = [];
try {
    foreach (ap_db()->query("SELECT did FROM bsky_sessions WHERE did IS NOT NULL AND did <> ''")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $did) {
        if (is_string($did) && str_starts_with($did, 'did:')) $dids[$did] = true;
    }
    foreach (ap_db()->query("SELECT target_did FROM bsky_graph_sync WHERE kind = 'follow' AND target_did IS NOT NULL AND target_did <> ''")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $did) {
        if (is_string($did) && str_starts_with($did, 'did:')) $dids[$did] = true;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "wanted-dids refresh failed: {$e->getMessage()}\n");
    exit(1);
}
$tmp = $stateDir . '/wanted-dids.json.tmp';
file_put_contents($tmp, json_encode(array_keys($dids), JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
rename($tmp, $stateDir . '/wanted-dids.json');
fwrite(STDOUT, sprintf("wanted-dids=%d\n", count($dids)));
