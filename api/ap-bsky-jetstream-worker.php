<?php
/** Consume durable Jetstream JSONL segments into the shared Bluesky cache. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$stateDir = getenv('VAAK_JETSTREAM_STATE') ?: '/var/lib/mkultra/ap/jetstream';
$incoming = $stateDir . '/incoming';
$processing = $stateDir . '/processing';
if (!is_dir($incoming)) mkdir($incoming, 0750, true);
if (!is_dir($processing)) mkdir($processing, 0750, true);
$lock = fopen($stateDir . '/worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
$files = array_merge(glob($processing . '/*.jsonl') ?: [], glob($incoming . '/*.jsonl') ?: []);
// The timer fires every 5 seconds. Most runs find nothing, so check the spool
// before compiling the Bluesky libraries (about 0.1 s of CPU per run).
if ($files === []) { flock($lock, LOCK_UN); fclose($lock); exit(0); }
if (!defined('AP_INBOX_LIB_ONLY')) define('AP_INBOX_LIB_ONLY', true);
require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-auth.php';
require_once __DIR__ . '/ap-bsky.php';
sort($files, SORT_STRING);
$failedDir = $stateDir . '/failed';
$done = 0; $ignored = 0; $failed = 0;
foreach ($files as $file) {
    $name = basename($file);
    $active = $processing . '/' . $name;
    if (dirname($file) !== $processing && !rename($file, $active)) { $failed++; continue; }
    $fh = fopen($active, 'rb');
    if (!$fh) { $failed++; continue; }
    // One event that cannot be ingested is set aside in failed/ and the rest
    // of the segment carries on. Putting the whole segment back blocked every
    // later segment behind the same bad event.
    while (($line = fgets($fh)) !== false) {
        $event = json_decode($line, true);
        if (!is_array($event)) continue;
        try {
            $result = ap_bsky_jetstream_ingest_event($event);
            if (!empty($result['ok'])) { $done++; continue; }
            if (!empty($result['ignored'])) { $ignored++; continue; }
            throw new RuntimeException((string) ($result['error'] ?? 'ingest failed'));
        } catch (Throwable $e) {
            error_log('[ap-bsky-jetstream-worker] ' . $name . ': ' . $e->getMessage());
            $failed++;
            if (!is_dir($failedDir)) @mkdir($failedDir, 0750, true);
            @file_put_contents($failedDir . '/' . $name, $line, FILE_APPEND | LOCK_EX);
        }
    }
    fclose($fh);
    unlink($active);
}
flock($lock, LOCK_UN); fclose($lock);
fwrite(STDOUT, sprintf("jetstream processed=%d ignored=%d failed=%d\n", $done, $ignored, $failed));
if ($failed > 0) exit(1);
