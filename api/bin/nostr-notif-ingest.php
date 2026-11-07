#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Store staged Nostr notifications in mentions.
 *
 * `vaak-worker nostr-sync` (rust/vaak-worker/src/nostr/notif.rs) reads the
 * replies, mentions, reposts, likes and zaps that tag each linked npub, keeps the
 * ones that should notify, and stages them in nostr_notifs with the mentions
 * row already built. New followers (a kind 3 contact list that names the user
 * for the first time) are staged the same way and stored in events as
 * action_taken nostr_follow, like Bluesky follows. It runs this script after each sync. Each row goes
 * through ap_mention_store like a Bluesky notification, so the badge, the
 * notification caches, search and push all see it, and is stamped
 * ingested_at. A failed row stays staged and is tried again next run.
 *
 *
 * A reply to a crossposted note (one carrying a NIP-48 proxy tag back to a
 * VAAK post) is stored as a reply to that VAAK post, so the thread shows the
 * post above the reply and the post lists it under its replies.
 * --remap-replies rewrites rows stored before that the same way, then exits.
 *
 * Usage (as www-data, from the release root):
 *   php api/bin/nostr-notif-ingest.php [--limit=200] [--remap-replies]
 */

$opts = getopt('', ['limit:', 'remap-replies', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "nostr-notif-ingest.php [--limit=200] [--remap-replies]\n");
    exit(0);
}
$limit = isset($opts['limit']) ? max(1, min(1000, (int) $opts['limit'])) : 200;

if (getenv('AP_DB_DSN') === false || getenv('AP_DB_DSN') === '') {
    putenv('AP_DB_DSN=pgsql:dbname=novalandia');
    $_ENV['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
    $_SERVER['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
}

$apiDir = dirname(__DIR__);
require $apiDir . '/ap-db.php';
define('AP_MASTO_LIB_ONLY', true);
require_once $apiDir . '/ap-mastodon.php';
require_once $apiDir . '/ap-nostr.php';
foreach (['ap-webpush.php', 'ap-search-fts.php'] as $lib) {
    if (is_file($apiDir . '/' . $lib)) {
        require_once $apiDir . '/' . $lib;
    }
}

/**
 * The remote_actors fields for a Nostr notifier, from its kind 0 profile
 * (nostr_profiles). Shown by the PHP notification list and API clients.
 *
 * @param array<string,mixed> $staged
 * @return array<string,string>
 */
function nostr_notif_actor_fields(array $staged): array
{
    $npub = ap_nostr_npub((string) $staged['actor']);
    $short = strlen($npub) > 20 ? substr($npub, 0, 10) . '…' . substr($npub, -6) : $npub;
    $name = trim((string) ($staged['name'] ?? ''));
    $display = trim((string) ($staged['display_name'] ?? ''));
    $picture = trim((string) ($staged['picture'] ?? ''));
    $fields = [
        'username' => $name !== '' ? $name : $short,
        'display_name' => $display !== '' ? $display : ($name !== '' ? $name : $short),
        'host' => 'njump.me',
    ];
    if (str_starts_with($picture, 'https://')) {
        $fields['icon_source_url'] = $picture;
    }
    return $fields;
}

$db = ap_db();

if (isset($opts['remap-replies'])) {
    $old = $db->query(
        "SELECT id, in_reply_to FROM mentions
         WHERE in_reply_to LIKE 'https://njump.me/note1%' AND deleted_at IS NULL"
    )->fetchAll(PDO::FETCH_ASSOC);
    $fix = $db->prepare('UPDATE mentions SET in_reply_to = ? WHERE id = ?');
    $remapped = 0;
    foreach ($old as $m) {
        $local = ap_nostr_proxied_local_note((string) $m['in_reply_to'], $db);
        if ($local !== '') {
            $fix->execute([$local, (int) $m['id']]);
            $remapped++;
        }
    }
    echo 'checked=' . count($old) . " remapped={$remapped}\n";
    exit(0);
}

$rows = $db->prepare(
    'SELECT n.owner_user_id, n.event_id, n.actor, n.mention, n.push,
            p.name, p.display_name, p.picture
     FROM nostr_notifs n LEFT JOIN nostr_profiles p ON p.pubkey = n.actor
     WHERE n.ingested_at = 0
     ORDER BY n.created_at ASC
     LIMIT ?'
);
$rows->execute([$limit]);
$done = $db->prepare('UPDATE nostr_notifs SET ingested_at = ? WHERE owner_user_id = ? AND event_id = ?');

/**
 * A new Nostr follower: one events row per follower and owner (a later
 * contact list edit is not a new follow). Returns whether it was stored.
 *
 * @param array<string,mixed> $row
 * @param array<string,mixed> $staged
 */
function nostr_notif_store_follow(PDO $db, array $row, array $staged, int $ownerUserId, string $ownerActorId): bool
{
    $actorId = (string) ($row['actor_id'] ?? '');
    $owner = rtrim($ownerActorId, '/');
    if ($actorId === '' || $owner === '' || !str_starts_with($actorId, 'https://njump.me/npub1')) {
        return false;
    }
    $exists = $db->prepare(
        "SELECT 1 FROM events WHERE type = 'Follow' AND action_taken IN ('nostr_follow', 'undone')
           AND rtrim(actor_id, '/') = ? AND (target_actor = ? OR target_actor = ?) LIMIT 1"
    );
    $exists->execute([$actorId, $owner, $owner . '/']);
    if ($exists->fetchColumn()) {
        return false;
    }
    $fields = nostr_notif_actor_fields($staged);
    ap_remote_actor_upsert($actorId, $fields);
    ap_metrics_record('Follow', $actorId, (string) $row['object_id'], $owner, 0, 'nostr_follow', (string) $fields['display_name'], null, (string) ($row['created_at'] ?? gmdate('c')));
    return true;
}

$stored = 0;
$skipped = 0;
$pushed = 0;
$errors = 0;
foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $staged) {
    $ownerUserId = (int) $staged['owner_user_id'];
    try {
        $row = json_decode((string) $staged['mention'], true);
        if (!is_array($row) || ($row['object_id'] ?? '') === '') {
            $done->execute([time(), $ownerUserId, $staged['event_id']]);
            $skipped++;
            continue;
        }
        $row['owner_user_id'] = $ownerUserId;
        $row['owner_actor_id'] = ap_db_owner_actor_id_for_user_id($ownerUserId);
        if (($row['activity_type'] ?? '') === 'Follow') {
            if (!nostr_notif_store_follow($db, $row, $staged, $ownerUserId, (string) $row['owner_actor_id'])) {
                $done->execute([time(), $ownerUserId, $staged['event_id']]);
                $skipped++;
                continue;
            }
            $stored++;
            $wantsPush = $staged['push'] === true || $staged['push'] === 't' || $staged['push'] === 1 || $staged['push'] === '1';
            if ($wantsPush && function_exists('ap_webpush_notify_event')) {
                $nid = null;
                $find = $db->prepare(
                    "SELECT id, created_at FROM events WHERE type = 'Follow' AND action_taken = 'nostr_follow'
                       AND object_id = ? AND rtrim(target_actor, '/') = ? ORDER BY id DESC LIMIT 1"
                );
                $find->execute([(string) $row['object_id'], rtrim((string) $row['owner_actor_id'], '/')]);
                $frow = $find->fetch(PDO::FETCH_ASSOC);
                if (is_array($frow) && function_exists('ap_masto_notification_id_for_follow_event')) {
                    $nid = ap_masto_notification_id_for_follow_event((int) $frow['id'], (string) $frow['created_at']);
                }
                ap_webpush_notify_event('follow', (string) $row['actor_id'], $nid, null, $ownerUserId);
                $pushed++;
            }
            $done->execute([time(), $ownerUserId, $staged['event_id']]);
            continue;
        }
        if (($row['in_reply_to'] ?? '') === '') {
            $row['in_reply_to'] = null;
        } else {
            $localParent = ap_nostr_proxied_local_note((string) $row['in_reply_to'], $db);
            if ($localParent !== '') {
                $row['in_reply_to'] = $localParent;
            }
        }
        $exists = $db->prepare(
            'SELECT 1 FROM mentions WHERE owner_user_id = ? AND (activity_id = ? OR object_id = ?) LIMIT 1'
        );
        $exists->execute([$ownerUserId, (string) $row['activity_id'], (string) $row['object_id']]);
        if ($exists->fetchColumn()) {
            $done->execute([time(), $ownerUserId, $staged['event_id']]);
            $skipped++;
            continue;
        }
        ap_remote_actor_upsert((string) $row['actor_id'], nostr_notif_actor_fields($staged));
        ap_mention_store($row);
        $stored++;
        $type = ap_masto_mention_notif_type($row);
        $wantsPush = $staged['push'] === true || $staged['push'] === 't' || $staged['push'] === 1 || $staged['push'] === '1';
        if ($wantsPush && is_string($type) && $type !== '' && function_exists('ap_webpush_notify_event')) {
            $nid = null;
            $find = $db->prepare(
                'SELECT id, created_at FROM mentions
                 WHERE owner_user_id = ? AND object_id = ? AND deleted_at IS NULL
                 ORDER BY id DESC LIMIT 1'
            );
            $find->execute([$ownerUserId, (string) $row['object_id']]);
            $mrow = $find->fetch(PDO::FETCH_ASSOC);
            if (is_array($mrow)) {
                $nid = ap_masto_notification_id_for_mention((int) $mrow['id'], (string) $mrow['created_at']);
            }
            $pushBody = (string) ($row['content'] ?? '');
            $zapSats = ap_masto_mention_zap_sats((string) $row['object_id']);
            if ($zapSats > 0) {
                $pushBody = '⚡ zapped ' . number_format($zapSats) . ($zapSats === 1 ? ' sat' : ' sats') . ' · ' . $pushBody;
            }
            ap_webpush_notify_event($type, (string) $row['actor_id'], $nid, $pushBody, $ownerUserId);
            $pushed++;
        }
        $done->execute([time(), $ownerUserId, $staged['event_id']]);
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, 'nostr-notif-ingest: ' . $staged['event_id'] . ': ' . $e->getMessage() . "\n");
    }
}

echo "stored={$stored} skipped={$skipped} pushed={$pushed} errors={$errors}\n";
exit($errors > 0 && $stored === 0 ? 1 : 0);
