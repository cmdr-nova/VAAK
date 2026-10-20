#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Opt-in cleanup for doubled "quoted your post" notifications.
 *
 * Before the inbox fix, a quote of a local post stored two mentions rows: one
 * for the quoting Create (<note>#quote-…) and one for the Update that follows
 * it when the quote is approved or edited (<note>#update-…, activity_type
 * Quote). This soft-deletes the #update- twin wherever the same account still
 * has the #quote- row for the same quoting note. A lone #update- row (its
 * Create never arrived) is kept so no quote loses its only notification.
 *
 * Not installed as cron and not run by deploy. Prints what it would hide;
 * writes only with --apply.
 *
 *   php api/bin/quote-notify-dedupe-cleanup.php
 *   php api/bin/quote-notify-dedupe-cleanup.php --apply
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$apply = in_array('--apply', array_slice($argv, 1), true);

require_once dirname(__DIR__) . '/ap-db.php';

$db = ap_db();
$st = $db->query(
    "SELECT id, owner_user_id, object_id, actor_id, created_at FROM mentions
     WHERE deleted_at IS NULL AND activity_type = 'Quote' AND object_id LIKE '%#update-%'
     ORDER BY id"
);
$twin = $db->prepare(
    "SELECT id FROM mentions
     WHERE deleted_at IS NULL AND owner_user_id = ? AND object_id LIKE ? AND id <> ?
     ORDER BY id LIMIT 1"
);
$hide = $db->prepare('UPDATE mentions SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL');

$found = 0;
$hidden = 0;
$owners = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $objectId = (string) $row['object_id'];
    $note = rtrim(substr($objectId, 0, (int) strpos($objectId, '#')), '/');
    if ($note === '') {
        continue;
    }
    $twin->execute([(int) $row['owner_user_id'], addcslashes($note, '\\%_') . '#quote-%', (int) $row['id']]);
    $keep = $twin->fetchColumn();
    if ($keep === false) {
        continue;
    }
    $found++;
    echo sprintf(
        "%s mention %d (owner %d, %s, %s) duplicates %d\n",
        $apply ? 'hide' : 'would hide',
        (int) $row['id'],
        (int) $row['owner_user_id'],
        (string) $row['actor_id'],
        (string) $row['created_at'],
        (int) $keep
    );
    if ($apply) {
        $hide->execute([ap_db_now(), (int) $row['id']]);
        $hidden += $hide->rowCount();
        $owners[(int) $row['owner_user_id']] = true;
    }
}

foreach (array_keys($owners) as $owner) {
    if (function_exists('ap_notification_projection_invalidate_owner')) {
        ap_notification_projection_invalidate_owner($owner);
    }
    if (function_exists('ap_masto_notifications_unread_invalidate')) {
        ap_masto_notifications_unread_invalidate($owner);
    }
}

echo $apply
    ? "quote-notify-dedupe-cleanup: hid {$hidden} of {$found} duplicate quote notifications\n"
    : "quote-notify-dedupe-cleanup: {$found} duplicate quote notifications (dry run; add --apply)\n";
