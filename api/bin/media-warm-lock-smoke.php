<?php
declare(strict_types=1);

/**
 * Remote media warm launches do not stack.
 * The worker takes a non-blocking flock before it loads the API. A second
 * launch exits busy without a second copy. PHP and Rust use the same command.
 */

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$root = dirname(__DIR__, 2);
$api = dirname(__DIR__);
$workerPath = $api . '/ap-media-warm-worker.php';
$worker = (string) file_get_contents($workerPath);
$r2 = (string) file_get_contents($api . '/ap-r2.php');
$rust = (string) file_get_contents($root . '/rust/vaak-worker/src/profile_warm.rs');

$requireAt = strpos($worker, "require_once __DIR__ . '/ap-db.php'");
$flockAt = strpos($worker, 'flock -n -E 2');
$lockedAt = strpos($worker, "getenv('VAAK_MEDIA_WARM_LOCKED') !== '1'");
$check($flockAt !== false && $lockedAt !== false && $requireAt !== false, 'worker has a lock gate and still loads the API');
$check($flockAt < $requireAt && $lockedAt < $requireAt, 'the lock is taken before ap-db.php is loaded');
$check(str_contains($worker, 'VAAK_MEDIA_WARM_LOCKED=1'), 'the locked child is marked so it does not lock twice');
$check(str_contains($worker, 'busy=1'), 'a contended launch reports busy');

if (!preg_match('/^function ap_media_warm_worker_nudge\(.*?^}/ms', $r2, $nudge)) {
    $check(false, 'nudge function is extractable');
    $nudgeBody = '';
} else {
    $nudgeBody = $nudge[0];
    $check(true, 'nudge function is extractable');
}
$check(str_contains($nudgeBody, 'flock -n -E 2'), 'PHP nudge launches under flock');
$check(str_contains($nudgeBody, 'VAAK_MEDIA_WARM_LOCKED=1'), 'PHP nudge marks the child locked');
$check(!str_contains($nudgeBody, 'LOCK_UN'), 'PHP nudge does not take and drop the lock itself');
$check(str_contains($rust, 'flock') && str_contains($rust, '"-n"') && str_contains($rust, '"-E"') && str_contains($rust, '"2"'), 'Rust launches under the same non-blocking flock');
$check(str_contains($rust, 'VAAK_MEDIA_WARM_LOCKED=1'), 'Rust marks the child locked');
$check(str_contains($rust, 'status.code() != Some(2)'), 'Rust treats flock exit 2 as already running');

$lockPath = sys_get_temp_dir() . '/vaak-media-warm-lock-smoke-' . getmypid() . '.lock';
@unlink($lockPath);
putenv('VAAK_MEDIA_WARM_LOCK=' . $lockPath);
$held = fopen($lockPath, 'c+');
$check(is_resource($held) && flock($held, LOCK_EX | LOCK_NB), 'this test can hold the worker lock');
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerPath) . ' --limit=3';
$out = [];
$code = 1;
exec($cmd . ' 2>&1', $out, $code);
$text = implode("\n", $out);
$check($code === 0, 'a second launch exits 0');
$check(str_contains($text, 'busy=1'), 'a second launch does not start another worker');
$check(!str_contains($text, 'media warm worker failed'), 'a contended launch does not load the database');
if (is_resource($held)) {
    flock($held, LOCK_UN);
    fclose($held);
}
@unlink($lockPath);

if ($failures !== []) {
    fwrite(STDERR, "media-warm-lock-smoke failed\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "media-warm-lock-smoke ok checks={$checks}\n";
