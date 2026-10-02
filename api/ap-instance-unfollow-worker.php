<?php
/**
 * Background Undo(Follow) fan-out after server actor/domain blocks.
 * Usage: php ap-instance-unfollow-worker.php /tmp/ap-instance-unfollow-jobs/<id>.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$jobFile = $argv[1] ?? '';
if ($jobFile === '' || !str_starts_with($jobFile, '/tmp/ap-instance-unfollow-jobs/') || !is_file($jobFile)) {
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

$pairs = is_array($job['pairs'] ?? null) ? $job['pairs'] : [];
if ($pairs === []) {
    exit(0);
}

define('AP_INBOX_LIB_ONLY', true);
define('AP_INSTANCE_BLOCK_WORKER', true);
// Background nohup from php-fpm may lack pool env.
if (getenv('AP_DB_DSN') === false || trim((string) getenv('AP_DB_DSN')) === '') {
    $envFile = '/etc/mkultra/vaak.env';
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            $v = trim($v, " \t\"'");
            if ($k !== '' && getenv($k) === false) {
                putenv($k . '=' . $v);
                $_ENV[$k] = $v;
            }
        }
    }
}
require_once __DIR__ . '/ap-inbox.php';

$fed = ap_instance_unfollow_pairs($pairs);
ap_log(
    'instance_unfollow_worker_done pairs=' . count($pairs)
    . ' attempted=' . (int) ($fed['attempted'] ?? 0)
    . ' delivered=' . (int) ($fed['delivered'] ?? 0)
);
exit(0);
