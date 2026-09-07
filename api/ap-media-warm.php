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

require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-r2.php';
if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once __DIR__ . '/ap-inbox.php';

$a = ap_remote_media_ensure($actor, 'avatar', false);
$h = ap_remote_media_ensure($actor, 'header', false);
fwrite(STDOUT, 'warm ' . $actor . ' avatar=' . ($a ? 'ok' : 'no') . ' header=' . ($h ? 'ok' : 'no') . "\n");
exit(0);
