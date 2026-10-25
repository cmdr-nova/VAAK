<?php
declare(strict_types=1);
/**
 * A follow or mention that lands while a notification hydrate is running must
 * not be hidden by that hydrate writing its older page back. Invalidation
 * bumps the owner's list epoch before deleting; the fetch reads the epoch
 * before any source query and skips projection + Redis writes when it moved.
 * No Postgres, Redis, or worker.
 */
$api = dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}

$checks = 0;
function epoch_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE ap_notification_projection(owner_user_id INTEGER, notification_id TEXT);
INSERT INTO ap_notification_projection VALUES (7, '1'), (7, '2'), (8, '3');");
$GLOBALS['db'] = $db;
$GLOBALS['calls'] = [];

function ap_db(): PDO
{
    $GLOBALS['calls'][] = 'db';
    return $GLOBALS['db'];
}
function ap_notification_list_epoch_bump(?int $ownerUserId = null): void
{
    $GLOBALS['calls'][] = 'bump:' . ($ownerUserId ?? 'server');
}
function ap_redis_delete_pattern(string $pattern): void
{
    $GLOBALS['calls'][] = 'del:' . $pattern;
}

reference_load($api . '/ap-db.php', [
    'ap_notification_projection_invalidate_owner',
    'ap_notification_projection_invalidate_all',
]);

ap_notification_projection_invalidate_owner(7);
epoch_check(($GLOBALS['calls'][0] ?? '') === 'bump:7', 'owner invalidation bumps the epoch before deleting');
epoch_check(in_array('del:vaak:notifications:v4:7:*', $GLOBALS['calls'], true), 'owner invalidation still drops v4 pages');
$left = $db->query('SELECT owner_user_id FROM ap_notification_projection ORDER BY owner_user_id')->fetchAll(PDO::FETCH_COLUMN);
epoch_check($left === [8] || $left === ['8'], 'only the invalidated owner loses projection rows');

$GLOBALS['calls'] = [];
ap_notification_projection_invalidate_all();
epoch_check(($GLOBALS['calls'][0] ?? '') === 'bump:server', 'full invalidation bumps the server epoch first');

// The fetch is too entangled to run here; pin the ordering it relies on.
$src = (string) file_get_contents($api . '/ap-masto-entities.php');
$start = strpos($src, 'function ap_masto_notifications_fetch(');
epoch_check($start !== false, 'notifications fetch present');
$end = strpos($src, "\nfunction ", $start + 10);
$body = substr($src, $start, $end === false ? null : $end - $start);
$epochAt = strpos($body, '$listEpoch = ');
$firstRead = strpos($body, 'ap_redis_json_get(');
$firstQuery = strpos($body, 'ap_db()->prepare(');
epoch_check($epochAt !== false && $epochAt < $firstRead && $epochAt < $firstQuery, 'epoch is read before any cache or source read');
epoch_check(preg_match('/if \(\$writeBack\) \{\s*ap_notification_projection_write\(/', $body) === 1, 'projection write is epoch-guarded');
epoch_check(str_contains($body, "if (\$writeBack && function_exists('ap_redis_json_set'))"), 'Redis page write is epoch-guarded');
epoch_check(str_contains($body, "if (function_exists('ap_redis_json_set') && \$listEpochCurrent())"), 'projection-hit Redis write is epoch-guarded');

fwrite(STDOUT, "notif-list-epoch-smoke: {$checks} checks ok\n");
