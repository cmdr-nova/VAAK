#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Hide notifications whose target post is gone.
 *
 * Dry run unless --apply. A local note is gone when it is in neither
 * masto_statuses nor outbox_notes. A remote post is gone only when a Delete
 * or Tombstone event names it. Prints mention ids and counts, never account ids.
 *
 *   php api/bin/notif-deleted-target-cleanup.php
 *   php api/bin/notif-deleted-target-cleanup.php --apply
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$apply = in_array('--apply', array_slice($argv, 1), true);

require_once dirname(__DIR__) . '/ap-db.php';

$rows = ap_notification_stale_mention_ids();
$ids = [];
foreach ($rows as $row) {
    $id = (int) ($row['id'] ?? 0);
    if ($id < 1) {
        continue;
    }
    $ids[] = $id;
    $reason = (string) ($row['reason'] ?? 'stale');
    echo ($apply ? 'hide' : 'would hide') . " mention {$id} reason={$reason}\n";
}

$hidden = 0;
if ($apply && $ids !== []) {
    $owners = [];
    $db = ap_db();
    foreach (array_chunk($ids, 200) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        try {
            $sel = $db->prepare("SELECT DISTINCT owner_user_id FROM mentions WHERE id IN ($ph) AND deleted_at IS NULL");
            $sel->execute($chunk);
            foreach ($sel->fetchAll(PDO::FETCH_COLUMN) ?: [] as $owner) {
                $owner = (int) $owner;
                if ($owner > 0) {
                    $owners[$owner] = true;
                }
            }
        } catch (Throwable $e) {
            // The hide below still runs. Unread caches expire on their own.
        }
        $upd = $db->prepare("UPDATE mentions SET deleted_at = ? WHERE deleted_at IS NULL AND id IN ($ph)");
        $upd->execute(array_merge([ap_db_now()], $chunk));
        $hidden += (int) $upd->rowCount();
    }
    if (function_exists('ap_notification_projection_invalidate_all')) {
        ap_notification_projection_invalidate_all();
    }
    if (function_exists('ap_notification_unread_invalidate_owners')) {
        ap_notification_unread_invalidate_owners(array_keys($owners));
    }
}

$found = count($ids);
echo $apply
    ? "notif-deleted-target-cleanup: hid {$hidden} of {$found} notifications\n"
    : "notif-deleted-target-cleanup: {$found} notifications (dry run; add --apply)\n";
