<?php
declare(strict_types=1);

/**
 * Redis wake-up worker for VAAK's durable database queues.
 * Redis carries wake-up signals; the existing database workers remain
 * authoritative and retain their leases, retries, and restart recovery.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/ap-db.php';

$once = in_array('--once', $argv ?? [], true);
$scripts = [
    'actions' => 'ap-action-queue-worker.php',
    'publish-delivery' => 'ap-publish-delivery-worker.php',
    'fanout-delivery' => 'ap-fanout-delivery-worker.php',
];

while (true) {
    $signal = ap_redis_queue_pop_any(array_keys($scripts), 5);
    if (!is_array($signal)) {
        if ($once || ap_redis_client('queue') === null) break;
        continue;
    }
    $queue = (string) ($signal['queue'] ?? '');
    if (!isset($scripts[$queue])) {
        if ($once) break;
        continue;
    }
    $php = function_exists('ap_php_cli_binary') ? ap_php_cli_binary() : PHP_BINARY;
    $script = __DIR__ . '/' . $scripts[$queue];
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --limit=20';
    @exec('nohup ' . $cmd . ' >/dev/null 2>&1 </dev/null &');
    if ($once) break;
}
