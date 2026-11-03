#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cache Bluemoji (Bluesky custom emoji) for posts that painted them as text.
 *
 * The Rust worker starts this in the background when a page has Bluemoji
 * facets with no row in remote_custom_emojis (host = owner DID); it never
 * waits on it. One DID document lookup and up to 3 listRecords pages of the
 * owner's blue.moji.collection.item per DID, at most once an hour per DID
 * (remote_emoji_host_meta). See ap_bsky_bluemoji_warm in api/ap-bsky.php.
 *
 *   php api/bin/bsky-bluemoji-warm.php --claims='[{"did":"did:plc:...","code":"blobcat","cid":"bafk...","static_cid":"bafk..."}]'
 *   php api/bin/bsky-bluemoji-warm.php --force --claims='[...]'
 *   php api/bin/bsky-bluemoji-warm.php --dry-run --claims='[...]'   (parse only, no network)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$claims = null;
$force = false;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($arg, '--claims=')) {
        $claims = json_decode(substr($arg, 9), true);
    }
}
if (!is_array($claims) || !array_is_list($claims)) {
    fwrite(STDERR, "usage: bsky-bluemoji-warm.php [--force] [--dry-run] --claims=JSON\n");
    exit(2);
}
$claims = array_slice($claims, 0, 40);

require_once dirname(__DIR__) . '/ap-db.php';
require_once dirname(__DIR__) . '/ap-bsky.php';

if ($dryRun) {
    echo json_encode(['claims' => count($claims)]) . "\n";
    exit(0);
}

// No lock: the per-DID meta row is written before any fetch, and the worker
// starts at most 6 of these a minute.
try {
    echo json_encode(ap_bsky_bluemoji_warm($claims, $force)) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'bluemoji warm: ' . $e->getMessage() . "\n");
    exit(1);
}
