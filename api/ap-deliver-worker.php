<?php
/**
 * Background ActivityPub delivery worker.
 * Usage: php ap-deliver-worker.php /tmp/ap-deliver-jobs/<id>.json
 *
 * Invoked by ap_deliver_fanout_background() after admin compose so the
 * outbox UI can render without waiting on slow shared-inbox fan-out.
 *
 * Fail-fast per inbox so one hung Threads/media peer cannot jam the rest
 * of the queue (or subsequent Creates to Bridgy).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$jobFile = $argv[1] ?? '';
if ($jobFile === '' || !str_starts_with($jobFile, '/tmp/ap-deliver-jobs/') || !is_file($jobFile)) {
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

$activity = $job['activity'] ?? null;
$inboxes = $job['inboxes'] ?? null;
$keyId = $job['key_id'] ?? '';
$priv = $job['priv'] ?? '';
$timeout = isset($job['timeout']) ? (float) $job['timeout'] : 2.5;
$timeout = max(1.0, min(6.0, $timeout));

if (!is_array($activity) || !is_array($inboxes) || !is_string($keyId) || $keyId === '' || !is_string($priv) || $priv === '') {
    exit(0);
}
if (!str_starts_with($priv, '/etc/mkultra/ap-inbox/') || !is_file($priv)) {
    fwrite(STDERR, "refusing priv path\n");
    exit(1);
}

define('AP_INBOX_LIB_ONLY', true);
require_once __DIR__ . '/ap-inbox.php';

$ok = 0;
$n = 0;
$hostFails = [];
foreach ($inboxes as $inbox) {
    if (!is_string($inbox) || !str_starts_with($inbox, 'https://')) {
        continue;
    }
    if ($n >= 80) {
        break;
    }
    if (ap_is_blocked_inbox($inbox)) {
        continue;
    }
    $host = strtolower((string) (parse_url($inbox, PHP_URL_HOST) ?: ''));
    // Skip hosts that already failed twice in this job (Threads 500s, etc.)
    if ($host !== '' && ($hostFails[$host] ?? 0) >= 2) {
        continue;
    }
    // Dead Bridgy path — never worth waiting on
    if (preg_match('#^https://([a-z0-9-]+\.)?brid\.gy/inbox$#i', $inbox)) {
        continue;
    }
    $inboxTimeout = $timeout;
    if ($host === 'threads.net' || str_ends_with($host, '.threads.net')) {
        $inboxTimeout = min($inboxTimeout, 2.0);
    }
    if (ap_deliver_signed_json($inbox, $activity, $keyId, $priv, $inboxTimeout)) {
        $ok++;
    } elseif ($host !== '') {
        $hostFails[$host] = ($hostFails[$host] ?? 0) + 1;
    }
    $n++;
}

ap_log("deliver_bg_done ok=$ok attempted=$n");
exit(0);
