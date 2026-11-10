<?php
/**
 * Shared worker lock smoke (no network, no database).
 *
 * ap-worker-lock.php gives minute-cron workers the hung-holder reaper that
 * publish delivery and the Bluesky notification poller already had: the
 * holder writes "pid start", and a later run kills a holder older than the
 * cap, but only when /proc/<pid>/cmdline names the worker script.
 *
 * Usage: php api/bin/worker-lock-reaper-smoke.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/ap-worker-lock.php';

$fail = 0;
function check(bool $ok, string $what): void { global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n"; if (!$ok) $fail++; }
function lock_with(string $line) {
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, $line);
    return $fh;
}
$cap = 600;
$old = time() - $cap - 120;
$needle = 'ap-action-queue-worker';

check(ap_worker_lock_reap_stale_holder(lock_with(''), $cap, $needle) === 'holder unknown', 'empty lock file (pre-upgrade holder) is left alone');
check(str_starts_with(ap_worker_lock_reap_stale_holder(lock_with(getmypid() . ' ' . time()), $cap, $needle), 'pid='), 'fresh holder is reported busy, not killed');
check(str_ends_with(ap_worker_lock_reap_stale_holder(lock_with(getmypid() . ' ' . $old), $cap, $needle), 'stale, not killed'), 'stale pid that is not this worker is never killed');
check(str_ends_with(ap_worker_lock_reap_stale_holder(lock_with(getmypid() . ' ' . $old), $cap, ''), 'stale, not killed'), 'an empty script name never kills');

$path = sys_get_temp_dir() . '/vaak-worker-lock-smoke-' . getmypid() . '.lock';
@unlink($path);
$a = ap_worker_lock_take($path, $cap, $needle);
check(is_resource($a['lock']) && !$a['reaped'], 'first run takes the lock');
check(preg_match('/^' . getmypid() . ' \d+$/', (string) file_get_contents($path)) === 1, 'holder records "pid start"');
$b = ap_worker_lock_take($path, $cap, $needle);
check($b['lock'] === null && str_starts_with($b['busy'], 'pid='), 'second run while held is busy');
ap_worker_lock_release($a['lock']);
$c = ap_worker_lock_take($path, $cap, $needle);
check(is_resource($c['lock']), 'lock is free again after release');
ap_worker_lock_release($c['lock']);
@unlink($path);

if (!function_exists('posix_kill') || !is_dir('/proc/self')) {
    echo "skip hung-worker kill check (needs posix + /proc)\n";
} else {
    // A stand-in whose command line names the worker script.
    $proc = proc_open([PHP_BINARY, '-r', 'sleep(30);', 'ap-action-queue-worker.php'], [], $pipes);
    $pid = (int) proc_get_status($proc)['pid'];
    usleep(200000);
    check(ap_worker_lock_reap_stale_holder(lock_with($pid . ' ' . $old), $cap, $needle) === '', 'stale worker is killed');
    for ($i = 0; $i < 20 && proc_get_status($proc)['running']; $i++) usleep(100000);
    check(!proc_get_status($proc)['running'], 'killed worker is gone');
    proc_close($proc);
}

$queue = (string) file_get_contents(dirname(__DIR__) . '/ap-action-queue.php');
check(str_contains($queue, "ap_worker_lock_take(ap_action_queue_lock_path(), AP_ACTION_QUEUE_MAX_SECONDS, 'ap-action-queue-worker')"), 'action queue uses the reaping lock');
$cli = (string) file_get_contents(dirname(__DIR__) . '/ap-action-queue-worker.php');
check(str_contains($cli, 'ap_worker_lock_alarm(AP_ACTION_QUEUE_MAX_SECONDS'), 'action queue CLI arms a run-time alarm');

echo $fail === 0 ? "worker-lock-reaper-smoke: ok\n" : "worker-lock-reaper-smoke: $fail failed\n";
exit($fail === 0 ? 0 : 1);
