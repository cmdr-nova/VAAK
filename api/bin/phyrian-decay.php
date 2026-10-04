#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Batch Phyrian resonance decay for imprinted players.
 *
 * Lazy decay also runs on page load; this cron keeps offline players honest.
 *
 * Usage:
 *   sudo -u www-data env AP_DB_DSN='pgsql:dbname=novalandia' \
 *     php api/bin/phyrian-decay.php
 *   php api/bin/phyrian-decay.php --limit=500
 */

$opts = getopt('', ['limit:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "phyrian-decay.php [--limit=500]\n");
    exit(0);
}
$limit = isset($opts['limit']) ? max(1, min(2000, (int) $opts['limit'])) : 500;

if (getenv('AP_DB_DSN') === false || getenv('AP_DB_DSN') === '') {
    putenv('AP_DB_DSN=pgsql:dbname=novalandia');
    $_ENV['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
    $_SERVER['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
}

$apiDir = dirname(__DIR__);
require $apiDir . '/ap-db.php';
require_once $apiDir . '/ap-phyrian.php';

if (!function_exists('ap_phyrian_decay_all')) {
    fwrite(STDERR, "phyrian-decay: ap_phyrian_decay_all missing\n");
    exit(1);
}

$started = microtime(true);
$result = ap_phyrian_decay_all($limit);
$ms = (int) round((microtime(true) - $started) * 1000);
fwrite(
    STDOUT,
    sprintf(
        "phyrian-decay scanned=%d changed=%d ms=%d daily=%d\n",
        (int) ($result['scanned'] ?? 0),
        (int) ($result['changed'] ?? 0),
        $ms,
        defined('AP_PHYRIAN_DAILY_DECAY') ? (int) AP_PHYRIAN_DAILY_DECAY : 5
    )
);
exit(0);
