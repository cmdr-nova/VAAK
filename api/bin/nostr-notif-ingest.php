#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Store staged Nostr notifications in mentions.
 *
 * `vaak-worker nostr-sync` (rust/vaak-worker/src/nostr/notif.rs) reads the
 * replies, mentions, reposts and likes that tag each linked npub, keeps the
 * ones that should notify, and stages them in nostr_notifs with the mentions
 * row already built. It runs this script after each sync. Each row goes
 * through ap_mention_store like a Bluesky notification, so the badge, the
 * notification caches, search and push all see it, and is stamped
 * ingested_at. A failed row stays staged and is tried again next run.
 *
 * Usage (as www-data, from the release root):
 *   php api/bin/nostr-notif-ingest.php [--limit=200]
 */

$opts = getopt('', ['limit:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "nostr-notif-ingest.php [--limit=200]\n");
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
        if (($row['in_reply_to'] ?? '') === '') {
            $row['in_reply_to'] = null;
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
            ap_webpush_notify_event($type, (string) $row['actor_id'], $nid, (string) ($row['content'] ?? ''), $ownerUserId);
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
