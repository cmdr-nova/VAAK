<?php
/** Background link-preview warmer. Usage: php ap-link-preview-warm.php https://… */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
$url = trim((string) ($argv[1] ?? ''));
if ($url === '' || !str_starts_with(strtolower($url), 'https://') || !filter_var($url, FILTER_VALIDATE_URL)) {
    exit(1);
}
$lockPath = sys_get_temp_dir() . '/vaak-lp-' . hash('sha256', $url) . '.lock';
$lock = @fopen($lockPath, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
define('AP_INBOX_LIB_ONLY', true);
require_once __DIR__ . '/ap-link-preview.php';
try {
    ap_link_preview_for_url($url, true);
} catch (Throwable $e) {
    error_log('[ap-link-preview] async warm: ' . $e->getMessage());
}
flock($lock, LOCK_UN);
fclose($lock);
