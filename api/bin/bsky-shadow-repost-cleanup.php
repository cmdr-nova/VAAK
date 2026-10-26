#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Opt-in cleanup for doubled boosts on profile Boosts tabs.
 *
 * Boosting another local account's post also reposts its Bluesky mirror, and
 * that repost was stored as a second masto_reblogs row (status_id
 * bsky-repost-…), so the Boosts tab showed the post twice: once as the VAAK
 * card and once as the Bluesky card. This removes the bsky-repost row wherever
 * the same account already has its VAAK boost of the mirrored local note.
 * Nothing is unposted on Bluesky; only the duplicate profile row goes.
 *
 * Not installed as cron and not run by deploy. Prints what it would remove;
 * writes only with --apply.
 *
 *   php api/bin/bsky-shadow-repost-cleanup.php
 *   php api/bin/bsky-shadow-repost-cleanup.php --apply
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$apply = in_array('--apply', array_slice($argv, 1), true);

require_once dirname(__DIR__) . '/ap-db.php';
require_once dirname(__DIR__) . '/ap-bsky.php';

$db = ap_db();
$st = $db->query(
    "SELECT r.owner_user_id, r.object_id, s.did
     FROM masto_reblogs r
     JOIN bsky_sessions s ON s.owner_user_id = r.owner_user_id
     WHERE r.status_id NOT LIKE 'bsky-repost-%'
       AND r.object_id LIKE 'https://mkultra.monster/users/%/notes/%'
     ORDER BY r.owner_user_id, r.id"
);
$find = $db->prepare('SELECT status_id FROM masto_reblogs WHERE owner_user_id = ? AND status_id = ?');
$drop = $db->prepare('DELETE FROM masto_reblogs WHERE owner_user_id = ? AND status_id = ?');

$found = 0;
$removed = 0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $owner = (int) $row['owner_user_id'];
    $note = rtrim((string) $row['object_id'], '/');
    $did = trim((string) $row['did']);
    if ($did === '' || !ap_bsky_is_local_note_id($note)) {
        continue;
    }
    foreach (ap_bsky_uris_for_note($note) as $uri) {
        $stable = ap_bsky_own_repost_status_id($did, $uri);
        $find->execute([$owner, $stable]);
        if ($find->fetchColumn() === false) {
            continue;
        }
        $found++;
        echo sprintf("owner %d: %s duplicates boost of %s (%s)\n", $owner, $stable, $note, $uri);
        if ($apply) {
            $drop->execute([$owner, $stable]);
            $removed += $drop->rowCount();
        }
    }
}

echo $apply
    ? sprintf("removed %d of %d duplicate Bluesky repost rows\n", $removed, $found)
    : sprintf("dry run: %d duplicate Bluesky repost rows (re-run with --apply to remove)\n", $found);
