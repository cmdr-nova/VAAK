<?php
/**
 * Background instance Block/Undo fan-out worker.
 * Usage: php ap-instance-block-worker.php /tmp/ap-instance-block-jobs/<id>.json
 *
 * Invoked by ap_instance_federate_actor_block_background() so admin
 * Block (Server) can return immediately after the local ap_blocks write.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$jobFile = $argv[1] ?? '';
if ($jobFile === '' || !str_starts_with($jobFile, '/tmp/ap-instance-block-jobs/') || !is_file($jobFile)) {
    fwrite(STDERR, "invalid job file\n");
    exit(1);
}

$raw = file_get_contents($jobFile);
@unlink($jobFile);
if (!is_string($raw) || $raw === '') {
    exit(0);
}

$job = json_decode($raw, true);
if (!is_array($job)) {
    exit(0);
}

$target = trim((string) ($job['target'] ?? ''));
$blocking = !empty($job['blocking']);
if ($target === '' || !str_starts_with($target, 'https://')) {
    exit(0);
}

define('AP_INBOX_LIB_ONLY', true);
define('AP_INSTANCE_BLOCK_WORKER', true);
require_once __DIR__ . '/ap-inbox.php';

$fed = ap_instance_federate_actor_block($target, $blocking);
ap_log(
    'instance_block_worker_done target=' . ap_short($target)
    . ' blocking=' . ($blocking ? '1' : '0')
    . ' attempted=' . (int) ($fed['attempted'] ?? 0)
    . ' delivered=' . (int) ($fed['delivered'] ?? 0)
);
exit(0);
