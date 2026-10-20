<?php
declare(strict_types=1);
/**
 * Deprioritize on Home saves one row and hands the Home rebuild to the
 * timeline fan-out queue (`owner_rewarm`). It must not clear every user's
 * timeline cache or repaint Home inside the request. No network or Redis.
 */
$api = getenv('VAAK_DEPRIORITIZE_QUEUE_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}

$GLOBALS['queued'] = [];
$GLOBALS['queue_ok'] = true;
$GLOBALS['owner_cleared'] = [];
function ap_timeline_fanout_enqueue(array $job): bool
{
    if (!$GLOBALS['queue_ok']) {
        return false;
    }
    $GLOBALS['queued'][] = $job;
    return true;
}
function admin_tl_cache_clear_owner(int $ownerUserId): void { $GLOBALS['owner_cleared'][] = $ownerUserId; }
function ap_timeline_home_hydrate_invalidate_owner(int $ownerUserId): void {}

reference_load($api . '/ap-admin.php', ['admin_home_rank_pref_rewarm']);

$checks = 0;
function dq_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

admin_home_rank_pref_rewarm(7);
dq_check($GLOBALS['queued'] === [['op' => 'owner_rewarm', 'owner' => 7]], 'rebuild goes to the fan-out queue for that owner only');
dq_check($GLOBALS['owner_cleared'] === [], 'nothing is cleared inline when the queue takes it');
admin_home_rank_pref_rewarm(0);
dq_check(count($GLOBALS['queued']) === 1, 'no owner, no job');

$admin = (string) file_get_contents($api . '/ap-admin.php');
$start = strpos($admin, "} elseif (\$action === 'deprioritize_remote' || \$action === 'undeprioritize_remote') {");
$end = strpos($admin, "} elseif (\$action === 'subscribe_posts'", (int) $start);
dq_check($start !== false && $end !== false, 'deprioritize handler found');
$handler = substr($admin, (int) $start, (int) $end - (int) $start);
dq_check(!str_contains($handler, 'admin_tl_cache_clear()'), 'handler no longer clears every timeline cache');
dq_check(substr_count($handler, 'admin_home_rank_pref_rewarm((int) $vaakOwnerId)') === 2, 'save and remove both queue the rebuild');
dq_check(str_contains($handler, "'deprioritized' => \$deprioritizedNow"), 'background save answers with JSON');
dq_check(str_contains($admin, "action !== 'deprioritize_remote' && action !== 'undeprioritize_remote'"), 'menu forms submit in the background');
dq_check(str_contains($admin, "'bite_remote', 'deprioritize_remote', 'undeprioritize_remote',"), 'no page-level Saving pill');

$worker = (string) file_get_contents(dirname($api) . '/rust/vaak-worker/src/timeline_fanout.rs');
dq_check(str_contains($worker, '"owner_rewarm" => process_owner_rewarm(cfg, job).await,'), 'worker handles owner_rewarm');

echo "deprioritize-queue-smoke: {$checks} checks passed\n";
