#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Rebuild the On VAAK trend caches (tags, links, posts) outside PHP-FPM.
 *
 * Run every few minutes by `vaak-worker trends-service`
 * (deploy/vaak-trends-service.service), so it inherits that unit's CPU cap.
 * Same lists the sidebar and /api/v1/trends always showed; they are just
 * never computed inside a page request any more. Safe to run by hand:
 *
 *   sudo -u www-data php api/bin/trends-warm.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

if (getenv('AP_DB_DSN') === false || getenv('AP_DB_DSN') === '') {
    putenv('AP_DB_DSN=pgsql:dbname=novalandia');
    $_ENV['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
    $_SERVER['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
}

$apiDir = dirname(__DIR__);
require $apiDir . '/ap-db.php';
define('AP_MASTO_LIB_ONLY', true);
require_once $apiDir . '/ap-mastodon.php';
require_once $apiDir . '/ap-masto-entities.php';

// A recompute against a dead database would write empty lists over good ones.
try {
    ap_db()->query('SELECT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "trends-warm: database unavailable, keeping current caches\n");
    exit(1);
}

$lockOk = !function_exists('ap_redis_lock') || ap_redis_client('queue') === null || ap_redis_lock('trends:refresh', 240);
if (!$lockOk) {
    fwrite(STDOUT, "trends-warm: another refresh holds the lock, skipping\n");
    exit(0);
}

$GLOBALS['vaak_trends_force_recompute'] = true;
$started = microtime(true);
$counts = [];
foreach (['tags' => 'ap_masto_trends_tags', 'links' => 'ap_masto_trends_links', 'statuses' => 'ap_masto_trends_statuses'] as $kind => $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "trends-warm: {$fn} missing\n");
        exit(1);
    }
    try {
        $counts[$kind] = count($fn(10));
    } catch (Throwable $e) {
        fwrite(STDERR, "trends-warm: {$kind} failed: " . $e->getMessage() . "\n");
        $counts[$kind] = -1;
    }
}
$GLOBALS['vaak_trends_force_recompute'] = false;
fwrite(STDOUT, sprintf(
    "trends-warm: tags=%d links=%d statuses=%d in %d ms\n",
    $counts['tags'], $counts['links'], $counts['statuses'], (int) round((microtime(true) - $started) * 1000)
));
exit(in_array(-1, $counts, true) ? 1 : 0);
