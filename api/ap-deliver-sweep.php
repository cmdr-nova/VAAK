<?php
/**
 * CLI: drain ActivityPub background deliver jobs, then drop ancient leftovers.
 *
 * Usage:
 *   php ap-deliver-sweep.php                 # process pending + delete >6h
 *   php ap-deliver-sweep.php --hours=24
 *   php ap-deliver-sweep.php --hours=1 --dry-run
 *   php ap-deliver-sweep.php --process-only  # drain without age deletes
 *
 * Cron (recommended every minute — unsticks media/poll fan-out if FPM spawn flaps):
 *   * * * * * www-data php /srv/mkultra/html/api/ap-deliver-sweep.php >> /var/log/mkultra/ap-deliver-sweep.log 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$dir = '/tmp/ap-deliver-jobs';
$hours = 6;
$dryRun = false;
$processOnly = false;
foreach ($argv as $arg) {
    if (preg_match('/^--hours=(\d+)$/', $arg, $m)) {
        $hours = max(1, min(168, (int) $m[1]));
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
    if ($arg === '--process-only') {
        $processOnly = true;
    }
}

$lockPath = '/tmp/ap-deliver-sweep.lock';
$lockFh = @fopen($lockPath, 'c+');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, sprintf("[%s] deliver_sweep busy\n", gmdate('c')));
    exit(0);
}

$worker = __DIR__ . '/ap-deliver-worker.php';
$php = '/usr/bin/php8.3';
if (!is_executable($php)) {
    $php = is_executable('/usr/bin/php') ? '/usr/bin/php' : 'php';
}

$processed = 0;
$processFail = 0;
$deleted = 0;
$errors = 0;
$kept = 0;
$scanned = 0;
$cutoff = time() - ($hours * 3600);

if (!is_dir($dir)) {
    fwrite(STDOUT, sprintf("[%s] deliver_sweep no_dir %s\n", gmdate('c'), $dir));
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    exit(0);
}

$files = [];
$dh = opendir($dir);
if ($dh === false) {
    fwrite(STDERR, "Cannot open $dir\n");
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    exit(1);
}
while (($name = readdir($dh)) !== false) {
    if (!preg_match('/^[a-f0-9]{8,64}\.json$/i', $name)) {
        continue;
    }
    $path = $dir . '/' . $name;
    $real = realpath($path);
    if ($real === false || !str_starts_with($real, rtrim($dir, '/') . '/')) {
        continue;
    }
    if (!is_file($real)) {
        continue;
    }
    $files[] = $real;
}
closedir($dh);

// Newest first so recent media/poll Creates drain before ancient noise
usort($files, static fn($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

foreach ($files as $real) {
    $scanned++;
    $mtime = filemtime($real) ?: 0;

    // Prefer processing; only age-delete if process-only is off and file is ancient
    // AND we are not going to run the worker (dry-run or missing worker).
    if (!$dryRun && is_file($worker)) {
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($real);
        $exit = 0;
        // Worker unlinks the job file itself on start
        passthru($cmd, $exit);
        if ($exit === 0) {
            $processed++;
        } else {
            $processFail++;
            // If worker failed before unlink, drop truly ancient junk
            if (!$processOnly && $mtime <= $cutoff && is_file($real)) {
                if (@unlink($real)) {
                    $deleted++;
                } else {
                    $errors++;
                }
            } elseif (is_file($real)) {
                $kept++;
            }
        }
        continue;
    }

    if ($dryRun) {
        fwrite(STDOUT, "would_process $real mtime=" . gmdate('c', $mtime) . "\n");
        $processed++;
        continue;
    }

    if (!$processOnly && $mtime <= $cutoff) {
        if (@unlink($real)) {
            $deleted++;
        } else {
            $errors++;
            fwrite(STDERR, "delete_fail $real\n");
        }
    } else {
        $kept++;
    }
}

fwrite(STDOUT, sprintf(
    "[%s] deliver_sweep hours=%d dry_run=%d scanned=%d processed=%d process_fail=%d deleted=%d kept=%d errors=%d\n",
    gmdate('c'),
    $hours,
    $dryRun ? 1 : 0,
    $scanned,
    $processed,
    $processFail,
    $deleted,
    $kept,
    $errors
));

flock($lockFh, LOCK_UN);
fclose($lockFh);
exit($errors > 0 || $processFail > 0 ? 2 : 0);
