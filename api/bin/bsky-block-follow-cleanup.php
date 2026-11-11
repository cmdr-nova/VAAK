#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Opt-in repair: end follows of Bluesky accounts you block.
 *
 * Bluesky keeps the app.bsky.graph.follow record of an account you block (the
 * AppView only hides it), and VAAK's follow sync reads those records, so a
 * blocked account kept showing as "Following". New blocks now end the follow
 * themselves; this script cleans up the ones made before that.
 *
 * A block here is: a direct Bluesky block, membership of a block list you own
 * on Bluesky, a VAAK personal block of a Bluesky account, or membership of a
 * VAAK moderation list set to block. Subscribed block lists made by other
 * accounts are left alone unless --include-subscribed-lists is passed.
 *
 * Dry run by default (reads your repo, deletes nothing). --apply deletes the
 * follow records on Bluesky and VAAK's cached follow edge.
 *
 *   php api/bin/bsky-block-follow-cleanup.php --owner=1
 *   php api/bin/bsky-block-follow-cleanup.php --owner=1 --apply
 *   php api/bin/bsky-block-follow-cleanup.php            (every connected account)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$apply = false;
$owner = 0;
$subscribed = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--include-subscribed-lists') {
        $subscribed = true;
    } elseif (preg_match('/^--owner=(\d+)$/', $arg, $m)) {
        $owner = (int) $m[1];
    } else {
        fwrite(STDERR, "Usage: bsky-block-follow-cleanup.php [--owner=N] [--include-subscribed-lists] [--apply]\n");
        exit(1);
    }
}

if (getenv('AP_DB_DSN') === false || getenv('AP_DB_DSN') === '') {
    putenv('AP_DB_DSN=pgsql:dbname=novalandia');
    $_ENV['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
    $_SERVER['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
}
if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once dirname(__DIR__) . '/ap-db.php';
require_once dirname(__DIR__) . '/ap-inbox.php';
require_once dirname(__DIR__) . '/ap-bsky.php';

$db = ap_db();
if ($owner > 0) {
    $owners = [$owner];
} else {
    $owners = array_map('intval', $db->query('SELECT owner_user_id FROM bsky_sessions ORDER BY owner_user_id')->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/** @return array<string,string> did => why */
function cleanup_blocked_dids(PDO $db, int $owner, bool $subscribed): array
{
    $out = [];
    $st = $db->prepare("SELECT did, reason, list_uri FROM bsky_hide_dids WHERE owner_user_id = ? AND reason IN ('block', 'listblock')");
    $st->execute([$owner]);
    while ($row = $st->fetch()) {
        $did = trim((string) $row['did']);
        if (!str_starts_with($did, 'did:')) {
            continue;
        }
        if ($row['reason'] === 'block') {
            $out[$did] = 'Bluesky block';
        } elseif (ap_bsky_list_is_own($owner, (string) $row['list_uri'])) {
            $out[$did] ??= 'your block list';
        } elseif ($subscribed) {
            $out[$did] ??= 'subscribed block list';
        }
    }
    $st = $db->prepare("SELECT value FROM ap_user_blocks WHERE owner_user_id = ? AND scope = 'actor' AND kind = 'block'");
    $st->execute([$owner]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $value) {
        $value = (string) $value;
        if (!str_starts_with($value, 'did:') && stripos($value, 'bsky.app/profile/') === false) {
            continue;
        }
        $did = ap_bsky_resolve_target_did($value, $owner);
        if (is_string($did) && str_starts_with($did, 'did:')) {
            $out[$did] ??= 'VAAK block';
        }
    }
    try {
        $st = $db->prepare("SELECT a.bsky_did FROM masto_lists l JOIN masto_list_accounts a ON a.list_id = l.id
            WHERE l.owner_user_id = ? AND l.list_kind = 'moderation' AND l.bsky_moderation_action = 'block' AND a.bsky_did LIKE 'did:%'");
        $st->execute([$owner]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $did) {
            $out[(string) $did] ??= 'VAAK block list';
        }
    } catch (Throwable $e) {
        // older schema without cross-network list columns
    }
    return $out;
}

$total = 0;
$failed = 0;
foreach ($owners as $ownerId) {
    if (ap_bsky_session_row($ownerId) === null) {
        echo "owner {$ownerId}: no Bluesky session, skipped\n";
        continue;
    }
    $blocked = cleanup_blocked_dids($db, $ownerId, $subscribed);
    $follows = ap_bsky_follow_record_uris($ownerId);
    if ($follows === null) {
        echo "owner {$ownerId}: could not read follow records, skipped\n";
        $failed++;
        continue;
    }
    $edges = $db->prepare("SELECT target_did FROM bsky_graph_sync WHERE owner_user_id = ? AND kind = 'follow'");
    $edges->execute([$ownerId]);
    $edgeSet = array_fill_keys(array_map('strval', $edges->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
    $hits = array_values(array_filter(array_keys($blocked), static fn ($d) => isset($follows[$d]) || isset($edgeSet[$d])));
    echo "owner {$ownerId}: " . count($blocked) . " blocked, " . count($hits) . " still followed\n";
    foreach ($hits as $did) {
        $handle = '';
        $h = $db->prepare('SELECT author_handle FROM bsky_posts WHERE author_did = ? AND author_handle <> \'\' LIMIT 1');
        $h->execute([$did]);
        $handle = (string) ($h->fetchColumn() ?: '');
        $line = sprintf('  %s %s (%s, %d record%s)', $did, $handle, $blocked[$did], count($follows[$did] ?? []), count($follows[$did] ?? []) === 1 ? '' : 's');
        if (!$apply) {
            echo $line . "\n";
            continue;
        }
        $res = ap_bsky_drop_follow_for_block($ownerId, $did, $follows);
        echo $line . ' -> ' . (!empty($res['ok']) ? 'unfollowed, ' . (int) $res['deleted'] . ' deleted' : 'FAILED: ' . (string) ($res['error'] ?? '')) . "\n";
        if (empty($res['ok'])) {
            $failed++;
        } else {
            $total++;
        }
    }
}
echo $apply ? "done: {$total} unfollowed, {$failed} failed\n" : "dry run: nothing changed (add --apply)\n";
exit($failed > 0 ? 1 : 0);
