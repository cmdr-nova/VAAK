<?php
/** CLI warmer for one uncached Bluesky post preview. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$uri = '';
$owner = 0;
$lockPath = '';
foreach ($argv as $arg) {
    if (preg_match('/^--uri=(.+)$/', $arg, $m)) $uri = trim($m[1]);
    if (preg_match('/^--owner=(\d+)$/', $arg, $m)) $owner = (int) $m[1];
    if (preg_match('/^--lock=(.+)$/', $arg, $m)) $lockPath = $m[1];
}
if (!str_starts_with($uri, 'at://')) {
    if ($lockPath !== '') @unlink($lockPath);
    exit(0);
}
try {
    require_once __DIR__ . '/ap-db.php';
    require_once __DIR__ . '/ap-auth.php';
    require_once __DIR__ . '/ap-bsky.php';
    ap_bsky_feed_item_from_any_url($uri, $owner, true);
} catch (Throwable $e) {
    error_log('[ap-bsky-post-warm] ' . $e->getMessage());
    exit(1);
} finally {
    if ($lockPath !== '') @unlink($lockPath);
}
