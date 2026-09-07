<?php
/**
 * Scan cached Create events for anti-AI slang and mark remote actors.
 *
 * Marks when an actor has >= 3 distinct matching posts in the retention window
 * (default 90 days). Clears the mark when recent hits drop below that threshold.
 *
 * Usage:
 *   php ap-anti-ai-scan.php
 *   php ap-anti-ai-scan.php --dry-run
 *   php ap-anti-ai-scan.php --threshold=3 --retention-days=90
 *
 * Cron (recommended every 6 hours):
 *   20 star/6 * * * www-data php .../ap-anti-ai-scan.php >> /var/log/mkultra/ap-anti-ai-scan.log
 *   (replace star with * — written that way so this docblock does not end early)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/ap-db.php';

$dryRun = false;
$threshold = 3;
$retentionDays = 90;
$eventLimit = 50000;

foreach ($argv as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
    if (preg_match('/^--threshold=(\d+)$/', $arg, $m)) {
        $threshold = max(2, min(20, (int) $m[1]));
    }
    if (preg_match('/^--retention-days=(\d+)$/', $arg, $m)) {
        $retentionDays = max(30, min(365, (int) $m[1]));
    }
    if (preg_match('/^--event-limit=(\d+)$/', $arg, $m)) {
        $eventLimit = max(1000, min(200000, (int) $m[1]));
    }
}

$lockPath = '/tmp/ap-anti-ai-scan.lock';
$lockFh = @fopen($lockPath, 'c+');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, sprintf("[%s] anti_ai_scan busy\n", gmdate('c')));
    exit(0);
}

$ts = gmdate('c');
if ($dryRun) {
    // Count matching Creates without writing
    $regex = ap_anti_ai_keyword_regex();
    $scanned = 0;
    $matches = 0;
    $byActor = [];
    $st = ap_db()->prepare(
        "SELECT actor_id, object_id, summary
         FROM events
         WHERE type = 'Create'
           AND summary IS NOT NULL AND summary != ''
           AND actor_id IS NOT NULL AND actor_id != ''
         ORDER BY id DESC LIMIT ?"
    );
    $st->bindValue(1, $eventLimit, PDO::PARAM_INT);
    $st->execute();
    while ($row = $st->fetch()) {
        $scanned++;
        $summary = (string) ($row['summary'] ?? '');
        if (!ap_text_looks_anti_ai($summary)) {
            continue;
        }
        $aid = rtrim((string) ($row['actor_id'] ?? ''), '/');
        if ($aid === '' || preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $aid)) {
            continue;
        }
        $matches++;
        $byActor[$aid] = ($byActor[$aid] ?? 0) + 1;
    }
    $wouldMark = 0;
    foreach ($byActor as $n) {
        if ($n >= $threshold) {
            $wouldMark++;
        }
    }
    fwrite(STDOUT, sprintf(
        "[%s] anti_ai_scan dry_run=1 scanned=%d matches=%d actors=%d would_mark=%d threshold=%d\n",
        $ts,
        $scanned,
        $matches,
        count($byActor),
        $wouldMark,
        $threshold
    ));
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    exit(0);
}

$res = ap_anti_ai_scan_cached_posts($threshold, $retentionDays, $eventLimit);
fwrite(STDOUT, sprintf(
    "[%s] anti_ai_scan ok=%d scanned=%d new_hits=%d actors=%d newly_marked=%d cleared=%d marked_total=%d threshold=%d retention_days=%d\n",
    $ts,
    !empty($res['ok']) ? 1 : 0,
    (int) ($res['scanned'] ?? 0),
    (int) ($res['new_hits'] ?? 0),
    (int) ($res['actors_touched'] ?? 0),
    (int) ($res['newly_marked'] ?? 0),
    (int) ($res['cleared'] ?? 0),
    (int) ($res['marked_total'] ?? 0),
    $threshold,
    $retentionDays
));

flock($lockFh, LOCK_UN);
fclose($lockFh);
exit(!empty($res['ok']) ? 0 : 1);
