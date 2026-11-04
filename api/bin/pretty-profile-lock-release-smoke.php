<?php
declare(strict_types=1);
/**
 * Pretty profile pages (/vaak/users/{key}) require ap-admin.php from inside
 * ap_vaak_boot_pretty_profile(), so ap-admin's top-level variables are that
 * function's locals and `global $adminTlStampedeLock` is null. Releasing the
 * stampede lock must then be a no-op, not a TypeError (every profile 503'd
 * after 0.37.0). Runs the real function body with a strict stub; no database.
 */
$root = getenv('VAAK_PRETTY_PROFILE_ROOT') ?: dirname(__DIR__, 2);
$admin = (string) file_get_contents($root . '/api/ap-admin.php');
if (!preg_match('/\nfunction admin_tl_release_stampede_lock\(\): void\n\{.*?\n\}\n/s', $admin, $m)) {
    fwrite(STDERR, "admin_tl_release_stampede_lock not found\n");
    exit(1);
}
$unlocked = [];
function ap_redis_unlock(string $key): void
{
    $GLOBALS['unlocked'][] = $key;
}
eval(substr($m[0], 1));

// 1. Global never set (the pretty-profile path).
unset($GLOBALS['adminTlStampedeLock']);
admin_tl_release_stampede_lock();
// 2. Empty string (no lock taken).
$GLOBALS['adminTlStampedeLock'] = '';
admin_tl_release_stampede_lock();
if ($unlocked !== []) {
    fwrite(STDERR, "unlocked without a lock key\n");
    exit(1);
}
// 3. A real lock is released once and cleared.
$GLOBALS['adminTlStampedeLock'] = 'tl-rebuild:1:home:abc';
admin_tl_release_stampede_lock();
admin_tl_release_stampede_lock();
if ($unlocked !== ['tl-rebuild:1:home:abc'] || $GLOBALS['adminTlStampedeLock'] !== '') {
    fwrite(STDERR, "real lock not released exactly once\n");
    exit(1);
}
echo "pretty-profile-lock-release-smoke: ok\n";
