#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Opt-in repair for remote posts that still show raw :shortcode: text
 * because their host's custom emoji were never cached (Misskey/Sharkey local
 * emoji, posts that arrived through a boost or an authorized-fetch fallback).
 *
 * Scans recent events for shortcodes missing from remote_custom_emojis,
 * refetches each host's emoji catalog once, then fetches a few of the posts
 * themselves for anything still missing (their Emoji tags are cached on fetch).
 * Only writes remote_custom_emojis / remote_emoji_host_meta; posts are not
 * rewritten, timelines pick the emoji up on their next paint.
 *
 * Not installed as cron and not run by deploy. Run --dry-run first (no network).
 *
 *   php api/bin/remote-emoji-backfill.php --days=7 --dry-run
 *   php api/bin/remote-emoji-backfill.php --days=7
 *   php api/bin/remote-emoji-backfill.php --host=transfem.social
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$days = 7;
$limit = 20000;
$notesPerHost = 3;
$onlyHost = null;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = max(1, min(90, (int) $m[1]));
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(200000, (int) $m[1]));
        continue;
    }
    if (preg_match('/^--notes-per-host=(\d+)$/', $arg, $m)) {
        $notesPerHost = max(0, min(20, (int) $m[1]));
        continue;
    }
    if (preg_match('/^--host=([A-Za-z0-9.-]+)$/', $arg, $m)) {
        $onlyHost = strtolower($m[1]);
        continue;
    }
    fwrite(STDERR, "Usage: remote-emoji-backfill.php [--days=7] [--limit=20000] [--notes-per-host=3] [--host=example.social] [--dry-run]\n");
    exit(1);
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once dirname(__DIR__) . '/ap-db.php';
require_once dirname(__DIR__) . '/ap-inbox.php';

$cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);
$st = ap_db()->prepare(
    "SELECT type, COALESCE(actor_id, '') AS actor_id, COALESCE(object_id, '') AS object_id, summary
       FROM events
      WHERE created_at >= ?
        AND type IN ('Create', 'Update', 'Announce')
        AND summary LIKE '%:%:%'
      ORDER BY id DESC
      LIMIT " . $limit
);
$st->execute([$cutoff]);

/** @var array<string, array<string, true>> $codesByHost */
$codesByHost = [];
/** @var array<string, array<string, list<string>>> $objectsByCode host => code => object ids */
$objectsByCode = [];
$scanned = 0;
while ($row = $st->fetch()) {
    $scanned++;
    $codes = ap_emoji_shortcodes_in((string) $row['summary']);
    if ($codes === []) {
        continue;
    }
    // The object's host is its author's host for Create and Announce alike.
    $ref = str_starts_with((string) $row['object_id'], 'https://') ? (string) $row['object_id'] : (string) $row['actor_id'];
    $host = strtolower((string) (parse_url($ref, PHP_URL_HOST) ?: ''));
    if ($host === '' || $host === 'mkultra.monster' || ($onlyHost !== null && $host !== $onlyHost)) {
        continue;
    }
    foreach ($codes as $code) {
        $codesByHost[$host][$code] = true;
        if (str_starts_with((string) $row['object_id'], 'https://')
            && count($objectsByCode[$host][$code] ?? []) < 3) {
            $objectsByCode[$host][$code][] = (string) $row['object_id'];
        }
    }
}

$missingBefore = 0;
$resolved = 0;
$notesFetched = 0;
$stillMissing = [];
foreach ($codesByHost as $host => $set) {
    $missing = ap_remote_emoji_ensure_codes($host, array_keys($set), false);
    if ($missing === []) {
        continue;
    }
    $before = count($missing);
    $missingBefore += $before;
    if (!$dryRun) {
        // Force the catalog: the hourly guard is for live traffic, not repairs.
        try {
            ap_remote_emoji_ensure_host($host, true, true);
        } catch (Throwable $e) {
            fwrite(STDERR, "  {$host}: catalog failed: {$e->getMessage()}\n");
        }
        ap_remote_emoji_map_for_host($host, true);
        $missing = ap_remote_emoji_ensure_codes($host, $missing, false);
        $fetched = [];
        foreach ($missing as $code) {
            foreach ($objectsByCode[$host][$code] ?? [] as $objectId) {
                if (count($fetched) >= $notesPerHost) {
                    break 2;
                }
                if (isset($fetched[$objectId])) {
                    continue;
                }
                $fetched[$objectId] = true;
                ap_fetch_as2_object($objectId); // caches the note's Emoji tags
            }
        }
        $notesFetched += count($fetched);
        ap_remote_emoji_map_for_host($host, true);
        $missing = ap_remote_emoji_ensure_codes($host, $missing, false);
        $resolved += $before - count($missing);
    }
    if ($missing !== []) {
        $stillMissing[$host] = $missing;
    }
}

fwrite(STDOUT, sprintf(
    "remote-emoji-backfill days=%d dry_run=%d scanned=%d hosts=%d missing=%d resolved=%d notes_fetched=%d\n",
    $days,
    $dryRun ? 1 : 0,
    $scanned,
    count($codesByHost),
    $missingBefore,
    $resolved,
    $notesFetched
));
// Unresolved tokens are often not emoji at all (times, ratios, :3).
foreach (array_slice($stillMissing, 0, 25, true) as $host => $codes) {
    fwrite(STDOUT, '  ' . $host . ': ' . implode(' ', array_map(static fn ($c) => ':' . $c . ':', array_slice($codes, 0, 12)))
        . (count($codes) > 12 ? ' (+' . (count($codes) - 12) . ')' : '') . "\n");
}
