<?php
/**
 * CLI: warm R2 cache for one remote actor's avatar + header.
 * Usage: php ap-media-warm.php https://example.com/users/alice
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$actor = $argv[1] ?? '';
if ($actor === '' || !str_starts_with($actor, 'https://')) {
    fwrite(STDERR, "actor URL required\n");
    exit(1);
}

// Hold a per-actor lock for the whole run so Home cannot spawn duplicates.
$actorLock = sys_get_temp_dir() . '/vaak-mw-' . hash('sha256', rtrim($actor, '/')) . '.lock';
$actorFh = @fopen($actorLock, 'c+');
if ($actorFh === false || !flock($actorFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "warm skip busy " . $actor . "\n");
    exit(0);
}

require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-r2.php';
if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once __DIR__ . '/ap-inbox.php';

try {
    // Refresh the actor document even when avatar/header blobs are still fresh.
    // This keeps cached names, handles, and source URLs current without making
    // timeline requests perform synchronous remote fetches.
    if (function_exists('ap_remote_actor_ensure')) {
        ap_remote_actor_ensure($actor, true);
    }
    $a = ap_remote_media_ensure($actor, 'avatar', false);
    $h = ap_remote_media_ensure($actor, 'header', false);
    fwrite(STDOUT, 'warm ' . $actor . ' avatar=' . ($a ? 'ok' : 'no') . ' header=' . ($h ? 'ok' : 'no') . "\n");
} finally {
    flock($actorFh, LOCK_UN);
    fclose($actorFh);
    @unlink($actorLock);
}
exit(0);
