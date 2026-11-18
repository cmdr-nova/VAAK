<?php
/**
 * Selective VAAK Redis cache invalidation.
 *
 * Usage:
 *   php ap-cache-rebuild.php --list
 *   php ap-cache-rebuild.php --scope=notifications --owner-id=1 --apply
 *   php ap-cache-rebuild.php --scope=all --owner-id=1 --apply
 *
 * The command is intentionally dry-run by default. Redis is an accelerator;
 * deleting these keys only causes the normal PostgreSQL/cache rebuild paths to
 * run on the next request.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-redis.php';

$scope = '';
$ownerId = 0;
$apply = false;
$list = false;
$statusOnly = false;
foreach ($argv as $arg) {
    if ($arg === '--apply') $apply = true;
    if ($arg === '--list') $list = true;
    if ($arg === '--status') $statusOnly = true;
    if (preg_match('/^--scope=([a-z_]+)$/', $arg, $m)) $scope = strtolower($m[1]);
    if (preg_match('/^--owner-id=(\d+)$/', $arg, $m)) $ownerId = (int) $m[1];
}

$knownScopes = ['notifications', 'timelines', 'library', 'relationships', 'all'];
if ($statusOnly) {
    $status = function_exists('ap_redis_json_get')
        ? ap_redis_json_get('vaak:cache-rebuild:last')
        : null;
    fwrite(STDOUT, $status ? (json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") : "No recorded cache rebuild run.\n");
    exit(0);
}
if ($list || $scope === '') {
    fwrite(STDOUT, "Scopes: " . implode(', ', $knownScopes) . "\n");
    fwrite(STDOUT, "Default mode is dry-run; add --apply to delete matching Redis keys.\n");
    fwrite(STDOUT, "Use --status to show the last recorded run.\n");
    exit($scope === '' ? 0 : 0);
}
if (!in_array($scope, $knownScopes, true)) {
    fwrite(STDERR, "Unknown scope: {$scope}\n");
    exit(2);
}
if ($ownerId < 1 && function_exists('ap_db_default_owner_user_id')) {
    $ownerId = (int) ap_db_default_owner_user_id();
}
if ($ownerId < 1) {
    fwrite(STDERR, "An owner id is required (use --owner-id=N).\n");
    exit(2);
}

$actions = [];
$add = static function (string $action) use (&$actions): void {
    if (!in_array($action, $actions, true)) $actions[] = $action;
};
if ($scope === 'notifications' || $scope === 'all') {
    $add('notification-list-epoch:' . $ownerId);
    $add('notification-unread-epoch:' . $ownerId);
}
if ($scope === 'timelines' || $scope === 'all') {
    $add('timeline-owner:' . $ownerId);
}
if ($scope === 'library' || $scope === 'all') {
    $add('library-epoch:' . $ownerId);
}
if ($scope === 'relationships' || $scope === 'all') {
    $add('relationships:' . $ownerId);
}

fwrite(STDOUT, sprintf("scope=%s owner_id=%d apply=%s\n", $scope, $ownerId, $apply ? 'yes' : 'no'));
$statusKey = 'vaak:cache-rebuild:last';
$statusRecord = [
    'scope' => $scope,
    'owner_id' => $ownerId,
    'apply' => $apply,
    'started_at' => gmdate('c'),
    'status' => $apply ? 'running' : 'dry_run',
];
if (function_exists('ap_redis_json_set')) {
    ap_redis_json_set($statusKey, $statusRecord, 604800);
}
foreach ($actions as $action) {
    fwrite(STDOUT, "action={$action}\n");
    if (!$apply) {
        continue;
    }
    if (str_starts_with($action, 'notification-list-epoch:') && function_exists('ap_notification_list_epoch_bump')) {
        ap_notification_list_epoch_bump($ownerId);
        if (function_exists('ap_redis_delete') && function_exists('ap_notification_page_index_key')) {
            ap_redis_delete(ap_notification_page_index_key($ownerId));
        }
    } elseif (str_starts_with($action, 'notification-unread-epoch:') && function_exists('ap_notification_unread_epoch_bump')) {
        ap_notification_unread_epoch_bump($ownerId);
    } elseif (str_starts_with($action, 'timeline-owner:') && function_exists('ap_timeline_cache_invalidate_owner')) {
        ap_timeline_cache_invalidate_owner($ownerId);
    } elseif (str_starts_with($action, 'library-epoch:') && function_exists('ap_redis_library_html_invalidate')) {
        ap_redis_library_html_invalidate($ownerId);
    } elseif (str_starts_with($action, 'relationships:')) {
        if (function_exists('ap_following_id_set_invalidate_owner')) {
            ap_following_id_set_invalidate_owner($ownerId);
        }
        if (function_exists('ap_redis_delete')) {
            ap_redis_delete('vaak:bsky:followed-handles:' . $ownerId);
        }
    }
}
$statusRecord['finished_at'] = gmdate('c');
$statusRecord['status'] = 'completed';
$statusRecord['patterns'] = count($actions);
if (function_exists('ap_redis_json_set')) {
    ap_redis_json_set($statusKey, $statusRecord, 604800);
}
if ($apply) fwrite(STDOUT, "cache invalidation requested; PostgreSQL remains authoritative.\n");
