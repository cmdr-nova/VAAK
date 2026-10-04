#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Rebuild Home / Local / Federated ranked Redis ID caches for one owner.
 *
 * Invoked by `vaak-worker ranked-warm` (loading-plan slice 2). Uses the same
 * admin_tl_lean_ranked_warm path as soft-nav so Redis key parity stays exact.
 * Interim PHP materializer — replace with native Rust ranked rebuild (then drop).
 *
 * Usage:
 *   sudo -u www-data env AP_DB_DSN='pgsql:dbname=novalandia' \
 *     php api/bin/ranked-warm.php --owner-id=1
 *   php api/bin/ranked-warm.php --owner-id=1 --views=home,local,feed
 */

$opts = getopt('', ['owner-id:', 'views:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "ranked-warm.php --owner-id=N [--views=home,local,feed]\n");
    exit(0);
}

$ownerId = isset($opts['owner-id']) ? (int) $opts['owner-id'] : 0;
if ($ownerId < 1) {
    fwrite(STDERR, "ranked-warm: --owner-id=N required\n");
    exit(2);
}

$views = ['home', 'local', 'feed'];
if (!empty($opts['views']) && is_string($opts['views'])) {
    $parsed = [];
    foreach (explode(',', $opts['views']) as $v) {
        $v = strtolower(trim($v));
        if (in_array($v, ['home', 'local', 'feed'], true)) {
            $parsed[$v] = true;
        }
    }
    if ($parsed !== []) {
        $views = array_keys($parsed);
    }
}

if (getenv('AP_DB_DSN') === false || getenv('AP_DB_DSN') === '') {
    putenv('AP_DB_DSN=pgsql:dbname=novalandia');
    $_ENV['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
    $_SERVER['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
}
if (getenv('VAAK_FEATURE_BLUESKY_TAB') === false) {
    putenv('VAAK_FEATURE_BLUESKY_TAB=1');
    $_ENV['VAAK_FEATURE_BLUESKY_TAB'] = '1';
}
putenv('VAAK_RANKED_WARM=1');
$_ENV['VAAK_RANKED_WARM'] = '1';

$apiDir = dirname(__DIR__);
require $apiDir . '/ap-db.php';
require_once $apiDir . '/ap-action-queue.php';
if (!function_exists('ap_action_queue_bind_owner') || !ap_action_queue_bind_owner($ownerId)) {
    fwrite(STDERR, "ranked-warm: cannot bind owner_id={$ownerId}\n");
    exit(1);
}

if (!defined('AP_ADMIN_LIB_ONLY')) {
    define('AP_ADMIN_LIB_ONLY', true);
}
require $apiDir . '/ap-admin.php';

if (!function_exists('admin_tl_lean_ranked_warm') || !function_exists('admin_tl_cache_key')) {
    fwrite(STDERR, "ranked-warm: admin_tl helpers missing after AP_ADMIN_LIB_ONLY load\n");
    exit(1);
}

$actorId = rtrim((string) ($GLOBALS['vaak_actor_id'] ?? ''), '/');
$following = [];
if ($actorId !== '' && function_exists('ap_following_list')) {
    try {
        $following = ap_following_list($actorId);
        if (!is_array($following)) {
            $following = [];
        }
    } catch (Throwable $e) {
        $following = [];
        fwrite(STDERR, "ranked-warm: following_list warn=" . $e->getMessage() . "\n");
    }
}

$ttlNote = '';
$listPrimary = getenv('VAAK_RANKED_RUST_PRIMARY');
$listPrimary = ($listPrimary === false || $listPrimary === '')
    ? true
    : !in_array(strtolower(trim((string) $listPrimary)), ['0', 'false', 'off', 'no'], true);

$started = microtime(true);
$ok = 0;
$fail = 0;
foreach ($views as $view) {
    $t0 = microtime(true);
    try {
        $cacheKey = admin_tl_cache_key($view, $following);
        admin_tl_lean_ranked_warm($view, $following, $cacheKey);
        $redisKey = function_exists('admin_tl_redis_key') ? admin_tl_redis_key($cacheKey) : '';
        $count = 0;
        $source = '';
        if ($redisKey !== '' && function_exists('ap_redis_json_get')) {
            $payload = ap_redis_json_get($redisKey);
            if (is_array($payload)) {
                $ranked = is_array($payload['ranked'] ?? null) ? $payload['ranked'] : [];
                $count = count($ranked);
                $source = (string) ($payload['source'] ?? '');
                // Tag live warm source without rewriting ranked entries.
                if ($source !== 'vaak-worker-live') {
                    $payload['source'] = 'vaak-worker-live';
                    $payload['warm_ts'] = time();
                    $ttl = $listPrimary ? 600 : 300;
                    ap_redis_json_set($redisKey, $payload, $ttl);
                    if (function_exists('admin_tl_cache_register_owner')) {
                        admin_tl_cache_register_owner($cacheKey);
                    }
                }
            }
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        fwrite(STDOUT, sprintf(
            "owner=%d view=%s key=%s ranked=%d ms=%d\n",
            $ownerId,
            $view,
            $cacheKey,
            $count,
            $ms
        ));
        $ok++;
    } catch (Throwable $e) {
        $fail++;
        fwrite(STDERR, sprintf(
            "owner=%d view=%s error=%s\n",
            $ownerId,
            $view,
            $e->getMessage()
        ));
    }
}

$totalMs = (int) round((microtime(true) - $started) * 1000);
fwrite(STDOUT, sprintf(
    "owner=%d warm_ok=%d warm_fail=%d total_ms=%d%s\n",
    $ownerId,
    $ok,
    $fail,
    $totalMs,
    $ttlNote
));
exit($fail > 0 && $ok === 0 ? 1 : 0);
