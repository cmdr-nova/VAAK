<?php
/**
 * Lightweight ActivityPub maintenance for mkultra.monster.
 *
 * Usage:
 *   php ap-maintain.php                  # daily: prune events, soft-deleted mentions,
 *                                        # expired oauth rows, tmp caches, stale remote
 *                                        # emoji/actors/link-previews; ANALYZE
 *   php ap-maintain.php --dry-run
 *   php ap-maintain.php --events-days=14
 *   php ap-maintain.php --vacuum         # also VACUUM (weekly; locks DB briefly)
 *   php ap-maintain.php --vacuum-only    # VACUUM + ANALYZE only
 *
 * Cron (recommended):
 *   45 4 * * * www-data php /srv/mkultra/html/api/ap-maintain.php --events-days=14 >> /var/log/mkultra/ap-maintain.log 2>&1
 *   15 5 * * 0 www-data php /srv/mkultra/html/api/ap-maintain.php --vacuum-only >> /var/log/mkultra/ap-maintain.log 2>&1
 *
 * Related (separate cron): ap-media-cleanup.php purges unused R2 avatar/header blobs (7d).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/ap-db.php';

$dryRun = false;
$vacuum = false;
$vacuumOnly = false;
$eventsDays = 14;
$mentionGoneDays = 30;
$oauthGoneDays = 7; // revoked / fully-expired OAuth tokens
$tmpMaxAgeHours = 48;
$emojiDays = 30;
$actorDays = 60;

foreach ($argv as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
    if ($arg === '--vacuum') {
        $vacuum = true;
    }
    if ($arg === '--vacuum-only') {
        $vacuumOnly = true;
        $vacuum = true;
    }
    if (preg_match('/^--events-days=(\d+)$/', $arg, $m)) {
        $eventsDays = max(3, min(90, (int) $m[1]));
    }
    if (preg_match('/^--mention-gone-days=(\d+)$/', $arg, $m)) {
        $mentionGoneDays = max(7, min(180, (int) $m[1]));
    }
    if (preg_match('/^--oauth-gone-days=(\d+)$/', $arg, $m)) {
        $oauthGoneDays = max(7, min(180, (int) $m[1]));
    }
    if (preg_match('/^--emoji-days=(\d+)$/', $arg, $m)) {
        $emojiDays = max(7, min(180, (int) $m[1]));
    }
    if (preg_match('/^--actor-days=(\d+)$/', $arg, $m)) {
        $actorDays = max(14, min(365, (int) $m[1]));
    }
}

$lockPath = '/tmp/ap-maintain.lock';
$lockFh = @fopen($lockPath, 'c+');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, sprintf("[%s] maintain busy\n", gmdate('c')));
    exit(0);
}

$stats = [
    'events_deleted' => 0,
    'mentions_deleted' => 0,
    'oauth_deleted' => 0,
    'tmp_deleted' => 0,
    'emoji_deleted' => 0,
    'emoji_meta_deleted' => 0,
    'actors_deleted' => 0,
    'link_previews_deleted' => 0,
    'analyzed' => 0,
    'vacuumed' => 0,
    'errors' => 0,
];

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$log = static function (string $msg) use ($dryRun): void {
    fwrite(STDOUT, sprintf("[%s]%s %s\n", gmdate('c'), $dryRun ? ' dry-run' : '', $msg));
};

try {
    $db = ap_db();
    $isPostgres = ap_db_driver() === 'pgsql';

    if (!$vacuumOnly) {
        // --- Firehose / Home events retention ---
        $eventsCutoff = $nowUtc->modify('-' . $eventsDays . ' days')->format('c');
        $st = $db->prepare('SELECT COUNT(*) FROM events WHERE created_at < ?');
        $st->execute([$eventsCutoff]);
        $n = (int) $st->fetchColumn();
        if ($n > 0) {
            if ($dryRun) {
                $log("would_delete events older_than=$eventsCutoff count=$n");
            } else {
                $del = $db->prepare('DELETE FROM events WHERE created_at < ?');
                $del->execute([$eventsCutoff]);
                $stats['events_deleted'] = $del->rowCount();
                $log("deleted events older_than=$eventsCutoff count={$stats['events_deleted']}");
            }
        } else {
            $log("events prune: nothing older than $eventsCutoff");
        }

        // --- Soft-deleted mentions (keep live notification history) ---
        $mentionCutoff = $nowUtc->modify('-' . $mentionGoneDays . ' days')->format('c');
        try {
            $st = $db->prepare('SELECT COUNT(*) FROM mentions WHERE deleted_at IS NOT NULL AND deleted_at < ?');
            $st->execute([$mentionCutoff]);
            $n = (int) $st->fetchColumn();
            if ($n > 0) {
                if ($dryRun) {
                    $log("would_delete soft-deleted mentions older_than=$mentionCutoff count=$n");
                } else {
                    $del = $db->prepare('DELETE FROM mentions WHERE deleted_at IS NOT NULL AND deleted_at < ?');
                    $del->execute([$mentionCutoff]);
                    $stats['mentions_deleted'] = $del->rowCount();
                    $log("deleted soft-deleted mentions count={$stats['mentions_deleted']}");
                }
            } else {
                $log('mentions purge: nothing to remove');
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            $log('mentions purge error: ' . $e->getMessage());
        }

        // --- Soft-deleted DMs ---
        try {
            $st = $db->prepare('SELECT COUNT(*) FROM direct_messages WHERE deleted_at IS NOT NULL AND deleted_at < ?');
            $st->execute([$mentionCutoff]);
            $n = (int) $st->fetchColumn();
            if ($n > 0 && !$dryRun) {
                $del = $db->prepare('DELETE FROM direct_messages WHERE deleted_at IS NOT NULL AND deleted_at < ?');
                $del->execute([$mentionCutoff]);
                $log('deleted soft-deleted DMs count=' . $del->rowCount());
            } elseif ($n > 0) {
                $log("would_delete soft-deleted DMs count=$n");
            }
        } catch (Throwable $e) {
            // table may be absent
        }

        // --- OAuth: drop revoked + fully expired tokens; keep app registrations ---
        try {
            if ($dryRun) {
                $oauthCutoff = $nowUtc->modify('-' . $oauthGoneDays . ' days')->format('c');
                $nowIso = $nowUtc->format('c');
                $st = $db->prepare(
                    "SELECT COUNT(*) FROM oauth_tokens WHERE
                      (revoked_at IS NOT NULL AND revoked_at < ?)
                      OR (
                        expires_at IS NOT NULL AND expires_at < ?
                        AND (refresh_expires_at IS NULL OR refresh_expires_at < ?)
                        AND revoked_at IS NULL
                      )"
                );
                $st->execute([$oauthCutoff, $nowIso, $nowIso]);
                $log('would_delete oauth_tokens count=' . (int) $st->fetchColumn() . " after_days=$oauthGoneDays");
            } else {
                $purge = ap_oauth_tokens_purge_stale($oauthGoneDays, false);
                $stats['oauth_deleted'] = (int) ($purge['revoked_deleted'] ?? 0) + (int) ($purge['expired_deleted'] ?? 0);
                $log(
                    "deleted oauth_tokens revoked={$purge['revoked_deleted']} expired={$purge['expired_deleted']}"
                    . " apps={$purge['apps_deleted']} after_days=$oauthGoneDays"
                );
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            $log('oauth purge error: ' . $e->getMessage());
        }

        // --- Tmp key + rate caches ---
        foreach (['/tmp/ap-inbox-keys', '/tmp/ap-inbox-rate'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $cutoffTs = time() - ($tmpMaxAgeHours * 3600);
            $dh = opendir($dir);
            if ($dh === false) {
                continue;
            }
            while (($name = readdir($dh)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $dir . '/' . $name;
                if (!is_file($path)) {
                    continue;
                }
                $mtime = filemtime($path);
                if ($mtime === false || $mtime > $cutoffTs) {
                    continue;
                }
                if ($dryRun) {
                    $stats['tmp_deleted']++;
                    continue;
                }
                if (@unlink($path)) {
                    $stats['tmp_deleted']++;
                } else {
                    $stats['errors']++;
                }
            }
            closedir($dh);
        }
        $log("tmp cache files purged count={$stats['tmp_deleted']} max_age_h=$tmpMaxAgeHours");

        // --- Remote custom emoji cache (re-fetched on demand) ---
        try {
            $emojiCutoff = $nowUtc->modify('-' . $emojiDays . ' days')->format('c');
            if ($dryRun) {
                $st = $db->prepare('SELECT COUNT(*) FROM remote_custom_emojis WHERE updated_at < ?');
                $st->execute([$emojiCutoff]);
                $nEmoji = (int) $st->fetchColumn();
                $st = $db->prepare('SELECT COUNT(*) FROM remote_emoji_host_meta WHERE fetched_at < ?');
                $st->execute([$emojiCutoff]);
                $nMeta = (int) $st->fetchColumn();
                $log("would_delete remote_custom_emojis count=$nEmoji host_meta=$nMeta older_than=$emojiCutoff");
            } else {
                $del = $db->prepare('DELETE FROM remote_custom_emojis WHERE updated_at < ?');
                $del->execute([$emojiCutoff]);
                $stats['emoji_deleted'] = $del->rowCount();
                $del2 = $db->prepare('DELETE FROM remote_emoji_host_meta WHERE fetched_at < ?');
                $del2->execute([$emojiCutoff]);
                $stats['emoji_meta_deleted'] = $del2->rowCount();
                $log("deleted remote_custom_emojis count={$stats['emoji_deleted']} host_meta={$stats['emoji_meta_deleted']} older_than=$emojiCutoff");
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            $log('emoji purge error: ' . $e->getMessage());
        }

        // --- Stale remote_actors (keep anyone we follow / who follows us) ---
        try {
            $actorCutoff = $nowUtc->modify('-' . $actorDays . ' days')->format('c');
            $sqlCount = "SELECT COUNT(*) FROM remote_actors ra
                WHERE ra.updated_at < ?
                  AND NOT EXISTS (
                    SELECT 1 FROM following f
                    WHERE rtrim(f.actor_id, '/') = rtrim(ra.actor_id, '/')
                  )
                  AND NOT EXISTS (
                    SELECT 1 FROM followers fo
                    WHERE rtrim(fo.actor_id, '/') = rtrim(ra.actor_id, '/')
                  )";
            $st = $db->prepare($sqlCount);
            $st->execute([$actorCutoff]);
            $n = (int) $st->fetchColumn();
            if ($n > 0) {
                if ($dryRun) {
                    $log("would_delete remote_actors stale_not_followed count=$n older_than=$actorCutoff");
                } else {
                    $del = $db->prepare(
                        "DELETE FROM remote_actors
                         WHERE updated_at < ?
                           AND NOT EXISTS (
                             SELECT 1 FROM following f
                             WHERE rtrim(f.actor_id, '/') = rtrim(remote_actors.actor_id, '/')
                           )
                           AND NOT EXISTS (
                             SELECT 1 FROM followers fo
                             WHERE rtrim(fo.actor_id, '/') = rtrim(remote_actors.actor_id, '/')
                           )"
                    );
                    $del->execute([$actorCutoff]);
                    $stats['actors_deleted'] = $del->rowCount();
                    // Drop orphaned account-id map rows for deleted actors
                    try {
                        $db->exec(
                            "DELETE FROM masto_account_actors
                             WHERE NOT EXISTS (
                               SELECT 1 FROM remote_actors ra
                               WHERE ra.actor_id = masto_account_actors.actor_id
                                  OR ra.actor_id = rtrim(masto_account_actors.actor_id, '/')
                             )
                             AND NOT EXISTS (
                               SELECT 1 FROM following f
                               WHERE f.actor_id = masto_account_actors.actor_id
                                  OR f.actor_id = rtrim(masto_account_actors.actor_id, '/')
                             )
                             AND NOT EXISTS (
                               SELECT 1 FROM followers fo
                               WHERE fo.actor_id = masto_account_actors.actor_id
                                  OR fo.actor_id = rtrim(masto_account_actors.actor_id, '/')
                             )"
                        );
                    } catch (Throwable $e) {
                        // non-fatal
                    }
                    $log("deleted remote_actors count={$stats['actors_deleted']} older_than=$actorCutoff");
                }
            } else {
                $log("remote_actors prune: nothing stale beyond $actorCutoff (excluding follows)");
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            $log('remote_actors purge error: ' . $e->getMessage());
        }

        // --- Expired link preview cards ---
        try {
            $nowIso = $nowUtc->format('c');
            if ($dryRun) {
                $st = $db->prepare('SELECT COUNT(*) FROM link_preview_cards WHERE expires_at < ?');
                $st->execute([$nowIso]);
                $log('would_delete link_preview_cards expired count=' . (int) $st->fetchColumn());
            } else {
                $del = $db->prepare('DELETE FROM link_preview_cards WHERE expires_at < ?');
                $del->execute([$nowIso]);
                $stats['link_previews_deleted'] = $del->rowCount();
                $log("deleted link_preview_cards count={$stats['link_previews_deleted']}");
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            $log('link_preview purge error: ' . $e->getMessage());
        }
    }

    // --- Planner statistics (cheap) ---
    if (!$dryRun) {
        $db->exec('ANALYZE');
        $stats['analyzed'] = 1;
        $log('ANALYZE ok');
    } else {
        $log('would ANALYZE');
    }

    // --- VACUUM (weekly; exclusive on SQLite) ---
    if ($vacuum) {
        if ($dryRun) {
            $log('would VACUUM');
        } else {
            if (!$isPostgres) {
                // Ensure no long-lived WAL buildup before SQLite VACUUM.
                $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            }
            $db->exec('VACUUM');
            $stats['vacuumed'] = 1;
            $log('VACUUM ok');
        }
    }

    if ($isPostgres) {
        $mb = round(((int) $db->query('SELECT pg_database_size(current_database())')->fetchColumn()) / 1048576, 2);
    } else {
        $pageCount = (int) $db->query('PRAGMA page_count')->fetchColumn();
        $pageSize = (int) $db->query('PRAGMA page_size')->fetchColumn();
        $mb = round(($pageCount * $pageSize) / 1048576, 2);
    }
    $eventsLeft = (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $log("done db_mb=$mb events=$eventsLeft " . json_encode($stats, JSON_UNESCAPED_SLASHES));
} catch (Throwable $e) {
    $stats['errors']++;
    fwrite(STDERR, sprintf("[%s] maintain fatal: %s\n", gmdate('c'), $e->getMessage()));
}

flock($lockFh, LOCK_UN);
fclose($lockFh);
@unlink($lockPath);
exit($stats['errors'] > 0 ? 2 : 0);
