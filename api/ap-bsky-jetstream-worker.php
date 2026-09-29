<?php
/** Consume durable Jetstream JSONL segments into the shared Bluesky cache. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('AP_INBOX_LIB_ONLY')) define('AP_INBOX_LIB_ONLY', true);
require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-auth.php';
require_once __DIR__ . '/ap-bsky.php';

$stateDir = getenv('VAAK_JETSTREAM_STATE') ?: '/var/lib/mkultra/ap/jetstream';
$incoming = $stateDir . '/incoming';
$processing = $stateDir . '/processing';
if (!is_dir($incoming)) mkdir($incoming, 0750, true);
if (!is_dir($processing)) mkdir($processing, 0750, true);
$lock = fopen($stateDir . '/worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
$files = array_merge(glob($processing . '/*.jsonl') ?: [], glob($incoming . '/*.jsonl') ?: []);
sort($files, SORT_STRING);
$done = 0; $failed = 0;
foreach ($files as $file) {
    $name = basename($file);
    $active = $processing . '/' . $name;
    if (dirname($file) !== $processing && !rename($file, $active)) { $failed++; continue; }
    $ok = true;
    $fh = fopen($active, 'rb');
    if (!$fh) { $failed++; continue; }
    while (($line = fgets($fh)) !== false) {
        $event = json_decode($line, true);
        if (!is_array($event)) continue;
        try {
            $result = ap_bsky_jetstream_ingest_event($event);
            if (empty($result['ok'])) throw new RuntimeException((string) ($result['error'] ?? 'ingest failed'));
            $done++;
        } catch (Throwable $e) {
            error_log('[ap-bsky-jetstream-worker] ' . $e->getMessage());
            $ok = false; $failed++; break;
        }
    }
    fclose($fh);
    if ($ok) unlink($active);
    else rename($active, $incoming . '/' . $name);
    if (!$ok) break;
}
flock($lock, LOCK_UN); fclose($lock);
fwrite(STDOUT, sprintf("jetstream processed=%d failed=%d\n", $done, $failed));
if ($failed > 0) exit(1);
