<?php
declare(strict_types=1);

/**
 * Single-run lock for minute-cron workers that cannot wedge their queue.
 *
 * A plain `flock(LOCK_NB)` worker that hangs holds its lock forever, and every
 * later run only reports "busy". That stopped Bluesky notifications on
 * 2026-10-29 and every outgoing post on 2026-11-05 (two days). Those two
 * workers got their own reaper; this is the same rule for the rest:
 *
 *  - the holder writes "pid start" into the lock file;
 *  - a later run kills a holder older than $maxSeconds + 60, but only when
 *    /proc/<pid>/cmdline names the worker script;
 *  - ap_worker_lock_alarm() arms a run-time cap inside the run itself.
 *
 * A holder without the "pid start" line (a run from before this change) is
 * left alone.
 */

/**
 * @param resource $lock
 * @return string why the lock is still busy, or '' when the holder was killed
 */
function ap_worker_lock_reap_stale_holder($lock, int $maxSeconds, string $scriptNeedle, ?int $now = null): string
{
    rewind($lock);
    $line = trim((string) stream_get_contents($lock));
    if (!preg_match('/^(\d+) (\d+)$/', $line, $m)) {
        return 'holder unknown';
    }
    $pid = (int) $m[1];
    $age = ($now ?? time()) - (int) $m[2];
    if ($age <= $maxSeconds + 60) {
        return sprintf('pid=%d age=%ds', $pid, $age);
    }
    $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($scriptNeedle === '' || !str_contains($cmd, $scriptNeedle) || !function_exists('posix_kill')) {
        return sprintf('pid=%d age=%ds stale, not killed', $pid, $age);
    }
    posix_kill($pid, 9);
    error_log(sprintf('[ap-worker-lock] killed hung %s pid=%d age=%ds', $scriptNeedle, $pid, $age));
    return '';
}

/**
 * Take the lock, reaping a hung holder first when it has outlived the cap.
 *
 * @return array{lock: resource|null, busy: string, reaped: bool}
 */
function ap_worker_lock_take(string $path, int $maxSeconds, string $scriptNeedle): array
{
    $lock = @fopen($path, 'c+');
    if (!$lock) {
        return ['lock' => null, 'busy' => 'lock file unavailable', 'reaped' => false];
    }
    $reaped = false;
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        $busy = ap_worker_lock_reap_stale_holder($lock, $maxSeconds, $scriptNeedle);
        $locked = false;
        for ($i = 0; $busy === '' && $i < 10 && !$locked; $i++) {
            usleep(500000);
            $locked = flock($lock, LOCK_EX | LOCK_NB);
        }
        if (!$locked) {
            fclose($lock);
            return ['lock' => null, 'busy' => $busy !== '' ? $busy : 'after kill', 'reaped' => false];
        }
        $reaped = true;
    }
    ftruncate($lock, 0);
    rewind($lock);
    fwrite($lock, getmypid() . ' ' . time());
    fflush($lock);
    return ['lock' => $lock, 'busy' => '', 'reaped' => $reaped];
}

/** @param resource|null $lock */
function ap_worker_lock_release($lock): void
{
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Backstop inside a CLI run. If a blocking call ignores the signal, the next
 * scheduled run kills this one through ap_worker_lock_reap_stale_holder().
 */
function ap_worker_lock_alarm(int $seconds, string $label): void
{
    if (PHP_SAPI !== 'cli' || !function_exists('pcntl_alarm') || !function_exists('pcntl_signal')) {
        return;
    }
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function () use ($seconds, $label): void {
        fwrite(STDERR, sprintf("[%s] %s timeout after %ds\n", gmdate('c'), $label, $seconds));
        exit(2);
    });
    pcntl_alarm($seconds);
}
