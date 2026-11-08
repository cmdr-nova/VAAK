<?php
/**
 * Publish delivery hung-worker reaper smoke (no network, no database).
 *
 * A hung ap-publish-delivery-worker.php used to hold its flock forever, so
 * every later run only logged busy=1 and no post reached the Fediverse or
 * Bluesky. The holder now writes "pid start" into the lock file and a later
 * run kills a holder older than AP_PUBLISH_DELIVERY_MAX_SECONDS + 60, but
 * only when that pid really is a publish delivery worker.
 *
 * Usage: php api/bin/publish-delivery-reaper-smoke.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/ap-publish-delivery.php';

$fail = 0;
function check(bool $ok, string $what): void { global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n"; if (!$ok) $fail++; }
function lock_with(string $line) {
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, $line);
    return $fh;
}
$old = time() - AP_PUBLISH_DELIVERY_MAX_SECONDS - 120;

check(ap_publish_delivery_reap_stale_holder(lock_with('')) === 'holder unknown', 'empty lock file (pre-upgrade holder) is left alone');
check(str_starts_with(ap_publish_delivery_reap_stale_holder(lock_with(getmypid() . ' ' . time())), 'pid='), 'fresh holder is reported busy, not killed');
check(str_ends_with(ap_publish_delivery_reap_stale_holder(lock_with(getmypid() . ' ' . $old)), 'stale, not killed'), 'stale pid that is not a publish worker is never killed');

if (!function_exists('posix_kill') || !is_dir('/proc/self')) {
    echo "skip hung-worker kill check (needs posix + /proc)\n";
} else {
    // A stand-in whose command line names the worker script.
    $proc = proc_open([PHP_BINARY, '-r', 'sleep(30);', 'ap-publish-delivery-worker.php'], [], $pipes);
    $status = proc_get_status($proc);
    $pid = (int) $status['pid'];
    usleep(200000);
    $r = ap_publish_delivery_reap_stale_holder(lock_with($pid . ' ' . $old));
    check($r === '', 'stale publish worker is killed');
    for ($i = 0; $i < 20 && proc_get_status($proc)['running']; $i++) usleep(100000);
    check(!proc_get_status($proc)['running'], 'killed worker is gone');
    proc_close($proc);
}

$src = (string) file_get_contents(dirname(__DIR__) . '/ap-publish-delivery.php');
check(str_contains($src, "fwrite(\$lock, getmypid() . ' ' . time())"), 'lock holder records "pid start"');
$cli = (string) file_get_contents(dirname(__DIR__) . '/ap-publish-delivery-worker.php');
check(str_contains($cli, 'pcntl_alarm(AP_PUBLISH_DELIVERY_MAX_SECONDS)'), 'CLI worker arms a run-time alarm');

echo $fail === 0 ? "publish-delivery-reaper-smoke: ok\n" : "publish-delivery-reaper-smoke: $fail failed\n";
exit($fail === 0 ? 0 : 1);
