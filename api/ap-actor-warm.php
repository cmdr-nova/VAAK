<?php
/** Background remote actor/profile warmer. Usage: php ap-actor-warm.php https://… */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
$actorId = rtrim(trim((string) ($argv[1] ?? '')), '/');
if ($actorId === '' || !str_starts_with(strtolower($actorId), 'https://') || !filter_var($actorId, FILTER_VALIDATE_URL)) {
    exit(1);
}
$lockPath = sys_get_temp_dir() . '/vaak-actor-' . hash('sha256', $actorId) . '.lock';
$lock = @fopen($lockPath, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
define('AP_INBOX_LIB_ONLY', true);
require_once __DIR__ . '/ap-inbox.php';
try {
    ap_remote_actor_ensure($actorId, true);
} catch (Throwable $e) {
    error_log('[ap-actor] async warm: ' . $e->getMessage());
}
flock($lock, LOCK_UN);
fclose($lock);
