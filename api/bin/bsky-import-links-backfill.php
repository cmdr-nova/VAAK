#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Opt-in repair: posts made directly on Bluesky and imported as local notes
 * (vaakOrigin=bluesky) were stored with ap_plain_text_to_html, which ignored
 * the record's richtext facets. Shortened link text ("example.com/pa...")
 * stayed dead and mentions/tags were plain. This re-reads each post (bsky_posts
 * cache first, then the public AppView in batches of 25), rebuilds the HTML
 * from text + facets and clears the owner's timeline caches.
 *
 * Not installed as cron and not run by deploy. Run --dry-run first.
 *
 *   php api/bin/bsky-import-links-backfill.php --user=cmdr_nova --dry-run
 *   php api/bin/bsky-import-links-backfill.php --user=cmdr_nova
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$user = '';
$limit = 2000;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (preg_match('/^--user=([A-Za-z][A-Za-z0-9_]{1,29})$/', $arg, $m)) {
        $user = $m[1];
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(20000, (int) $m[1]));
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(1);
}
if ($user === '') {
    fwrite(STDERR, "Usage: bsky-import-links-backfill.php --user=NAME [--limit=2000] [--dry-run]\n");
    exit(1);
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once dirname(__DIR__) . '/ap-inbox.php';
require_once dirname(__DIR__) . '/ap-bsky.php';
require_once dirname(__DIR__) . '/ap-masto-entities.php';

$owner = ap_local_user_by_username($user);
$ownerId = is_array($owner) ? (int) ($owner['id'] ?? 0) : 0;
if ($ownerId < 1) {
    fwrite(STDERR, "No active user {$user}\n");
    exit(1);
}
$actorId = ap_db_owner_actor_id_for_user_id($ownerId);
if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
    fwrite(STDERR, "No local actor for {$user}\n");
    exit(1);
}

$st = ap_db()->prepare(
    "SELECT id, raw_create_json FROM outbox_notes
     WHERE id LIKE ? AND raw_create_json LIKE '%\"vaakOrigin\":\"bluesky\"%'
     ORDER BY published DESC LIMIT " . $limit
);
$st->execute([rtrim($actorId, '/') . '/notes/%']);
$byUri = [];
$rows = 0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $rows++;
    $create = json_decode((string) ($row['raw_create_json'] ?? ''), true);
    $uri = is_array($create) ? trim((string) ($create['object']['blueskyUri'] ?? '')) : '';
    if (str_starts_with($uri, 'at://')) {
        $byUri[$uri] = (string) $row['id'];
    }
}

$posts = [];
$missing = [];
foreach (array_keys($byUri) as $uri) {
    $item = ap_bsky_post_item_by_uri($uri);
    if (is_array($item['post'] ?? null) && is_array($item['post']['record'] ?? null)) {
        $posts[$uri] = $item['post'];
    } else {
        $missing[] = $uri;
    }
}
$fetched = 0;
foreach (array_chunk($missing, 25) as $chunk) {
    $got = ap_bsky_xrpc(AP_BSKY_PUBLIC_API, 'app.bsky.feed.getPosts', 'GET', [
        'uris' => implode(',', $chunk),
    ], null, null, 10);
    if (empty($got['ok']) || !is_array($got['json']['posts'] ?? null)) {
        continue;
    }
    foreach ($got['json']['posts'] as $post) {
        $uri = is_array($post) ? trim((string) ($post['uri'] ?? '')) : '';
        if ($uri !== '' && isset($byUri[$uri])) {
            $posts[$uri] = $post;
            $fetched++;
        }
    }
    usleep(200000);
}

$updated = [];
$errors = 0;
foreach ($posts as $uri => $post) {
    $r = ap_bsky_repair_imported_note_content($byUri[$uri], $post, $dryRun);
    if (empty($r['ok'])) {
        $errors++;
        continue;
    }
    if (!empty($r['updated'])) {
        $updated[] = $byUri[$uri];
    }
}
if (!$dryRun && $updated !== []) {
    ap_timeline_cache_invalidate_owner($ownerId);
    if (function_exists('admin_tl_cache_clear')) {
        admin_tl_cache_clear();
    }
}

fwrite(STDOUT, sprintf(
    "bsky-import-links-backfill user=%s dry_run=%d imported_rows=%d with_uri=%d found=%d appview_fetched=%d %s=%d errors=%d\n",
    $user,
    $dryRun ? 1 : 0,
    $rows,
    count($byUri),
    count($posts),
    $fetched,
    $dryRun ? 'would_update' : 'updated',
    count($updated),
    $errors
));
foreach (array_slice($updated, 0, 20) as $id) {
    fwrite(STDOUT, "  {$id}\n");
}
