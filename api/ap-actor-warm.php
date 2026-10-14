<?php
/**
 * Background remote actor/profile warmer (signed AS2 fetch via ap_remote_actor_ensure).
 * Usage: php ap-actor-warm.php https://…
 * Also spawned by `vaak-worker ap-actor-warm` when draining Redis `ap_actor_warm`.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
$force = false;
$actorId = '';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
        continue;
    }
    if ($actorId === '' && is_string($arg) && str_starts_with(strtolower($arg), 'https://')) {
        $actorId = rtrim(trim($arg), '/');
    }
}
if ($actorId === '' || !filter_var($actorId, FILTER_VALIDATE_URL)) {
    exit(1);
}
if ($force) {
    $GLOBALS['ap_remote_actor_force_refresh'] = true;
}
$lockPath = sys_get_temp_dir() . '/vaak-actor-' . hash('sha256', $actorId) . '.lock';
$lock = @fopen($lockPath, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
define('AP_INBOX_LIB_ONLY', true);
require_once __DIR__ . '/ap-inbox.php';
try {
    $row = ap_remote_actor_ensure($actorId, true);
    // Ensure upsert already flat_puts; refresh again in case ensure returned a memo hit.
    if (is_array($row) && function_exists('ap_remote_actor_flat_put')) {
        ap_remote_actor_flat_put($actorId, $row);
    }
} catch (Throwable $e) {
    error_log('[ap-actor] async warm: ' . $e->getMessage());
}
flock($lock, LOCK_UN);
fclose($lock);
