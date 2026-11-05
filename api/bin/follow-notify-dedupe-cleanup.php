#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Opt-in cleanup for doubled "followed you" notifications.
 *
 * Before the inbox fix, every Follow activity from someone who already followed
 * (a retried delivery with a new activity id, or a Follow re-sent after a lost
 * Accept) stored another local_accept_followback event, and Notifications
 * listed each one. This keeps the oldest live row per follower/account pair
 * and turns the rest into local_follow_repeat, the same thing the inbox now
 * does on arrival. Rows from a real unfollow + refollow are not touched: the
 * Undo already marked the earlier row 'undone'.
 *
 * Not installed as cron and not run by deploy. Prints each pair with its
 * activity ids (which shows why the Follow came twice); writes only with --apply.
 *
 *   php api/bin/follow-notify-dedupe-cleanup.php
 *   php api/bin/follow-notify-dedupe-cleanup.php --apply
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$apply = in_array('--apply', array_slice($argv, 1), true);

require_once dirname(__DIR__) . '/ap-db.php';

$db = ap_db();
$pairs = $db->query(
    "SELECT rtrim(actor_id, '/') AS actor, rtrim(target_actor, '/') AS target, COUNT(*) AS n
     FROM events
     WHERE type = 'Follow' AND action_taken = 'local_accept_followback'
       AND actor_id IS NOT NULL AND target_actor IS NOT NULL
     GROUP BY 1, 2
     HAVING COUNT(*) > 1
     ORDER BY 1, 2"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$rows = $db->prepare(
    "SELECT id, created_at, object_id FROM events
     WHERE type = 'Follow' AND action_taken = 'local_accept_followback'
       AND actor_id IN (?, ?) AND target_actor IN (?, ?)
     ORDER BY id"
);

$extra = 0;
$collapsed = 0;
foreach ($pairs as $pair) {
    $actor = (string) $pair['actor'];
    $target = (string) $pair['target'];
    $extra += (int) $pair['n'] - 1;
    echo sprintf("%s -> %s: %d live follow notifications\n", $actor, $target, (int) $pair['n']);
    $rows->execute([$actor, $actor . '/', $target, $target . '/']);
    $first = true;
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        echo sprintf(
            "  %s event %d  %s  %s\n",
            $first ? 'keep' : ($apply ? 'hide' : 'would hide'),
            (int) $row['id'],
            (string) $row['created_at'],
            (string) ($row['object_id'] ?? '')
        );
        $first = false;
    }
    if ($apply) {
        $collapsed += ap_notification_collapse_repeat_follows($actor, $target);
    }
}

echo $apply
    ? "follow-notify-dedupe-cleanup: hid {$collapsed} of {$extra} duplicate follow notifications\n"
    : "follow-notify-dedupe-cleanup: {$extra} duplicate follow notifications (dry run; add --apply)\n";
