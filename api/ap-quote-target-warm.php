<?php
/**
 * CLI: warm a remote quote target into events/masto cache for full quote cards.
 * Usage: php ap-quote-target-warm.php <https://object-url>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$url = rtrim(trim((string) ($argv[1] ?? '')), '/');
if ($url === '' || !str_starts_with($url, 'https://')) {
    fwrite(STDERR, "usage: php ap-quote-target-warm.php <https-url>\n");
    exit(2);
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-masto-entities.php';

try {
    if (function_exists('ap_masto_ensure_remote_note_event')) {
        $row = ap_masto_ensure_remote_note_event($url);
        $ok = is_array($row);
        fwrite(STDOUT, ($ok ? 'ok' : 'miss') . ' ' . $url . "\n");
        exit($ok ? 0 : 3);
    }
    fwrite(STDERR, "ensure unavailable\n");
    exit(4);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(5);
}
