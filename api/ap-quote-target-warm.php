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
// Background nohup from php-fpm may lack pool env — load vaak.env when needed.
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
require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-masto-entities.php';

try {
    if (function_exists('ap_masto_ensure_remote_note_event')) {
        // Refuse non-status URLs early (YouTube/blog links misread as quote targets).
        if (function_exists('ap_url_looks_like_status_object')
            && !ap_url_looks_like_status_object($url)) {
            if (function_exists('ap_object_target_mark_warm_miss')) {
                ap_object_target_mark_warm_miss($url, 'miss');
            }
            fwrite(STDOUT, 'skip-nonstatus ' . $url . "\n");
            exit(3);
        }
        $before = function_exists('ap_event_by_object_id') ? ap_event_by_object_id($url) : null;
        $beforeSum = is_array($before) ? trim((string) ($before['summary'] ?? '')) : '';
        $row = ap_masto_ensure_remote_note_event($url);
        $ok = is_array($row);
        $afterSum = $ok ? trim((string) ($row['summary'] ?? '')) : '';
        $filled = $ok && $beforeSum === '' && $afterSum !== '';
        if (!$ok && function_exists('ap_object_target_mark_warm_miss')) {
            $reason = 'miss';
            try {
                $stDel = ap_db()->prepare(
                    "SELECT 1 FROM events
                     WHERE type = 'Delete'
                       AND (object_id = ? OR object_id = ?)
                     LIMIT 1"
                );
                $stDel->execute([$url, $url . '/']);
                if ($stDel->fetchColumn()) {
                    $reason = 'deleted';
                }
            } catch (Throwable $e) {
                // keep miss
            }
            ap_object_target_mark_warm_miss($url, $reason);
        }
        fwrite(STDOUT, ($ok ? ($filled ? 'ok-filled' : 'ok') : 'miss') . ' ' . $url . "\n");
        exit($ok ? 0 : 3);
    }
    fwrite(STDERR, "ensure unavailable\n");
    exit(4);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(5);
}
