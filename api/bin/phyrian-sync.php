#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Mirror verified NovaLandia/OpenSim Phyrian bodies into VAAK.
 *
 * This performs only the read-from-OpenSim + apply-to-VAAK direction. It
 * never claims a daily check-in and never mutates the OpenSim side. The
 * bridge's own sync TTL coalesces repeated calls, while this process lock
 * prevents overlapping mirror runs.
 *
 * Usage:
 *   php api/bin/phyrian-sync.php
 *   php api/bin/phyrian-sync.php --owner=1 --force
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$owner = 0;
$force = false;
$limit = 200;
foreach ($argv as $arg) {
    if (preg_match('/^--owner=(\d+)$/', $arg, $m)) {
        $owner = max(0, (int) $m[1]);
    } elseif ($arg === '--force') {
        $force = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(2000, (int) $m[1]));
    } elseif ($arg === '--help') {
        fwrite(STDOUT, "phyrian-sync.php [--owner=N] [--limit=200] [--force]\n");
        exit(0);
    }
}

if (getenv('AP_DB_DSN') === false || getenv('AP_DB_DSN') === '') {
    putenv('AP_DB_DSN=pgsql:dbname=novalandia');
    $_ENV['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
    $_SERVER['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
}

$apiDir = dirname(__DIR__);
require $apiDir . '/ap-db.php';
require_once $apiDir . '/ap-phyrian.php';
require_once $apiDir . '/ap-phyrian-bridge.php';

// The five-minute job is CLI, so it does not inherit the php-fpm flag.
// Rust mirrors the pull; a failed call still uses the PHP bridge below.
$workerEnv = '/etc/mkultra/vaak.env';
if (is_readable($workerEnv)) {
    $envLines = file($workerEnv, FILE_IGNORE_NEW_LINES);
    if (is_array($envLines)) {
        foreach ($envLines as $envLine) {
            $envLine = trim($envLine);
            if ($envLine === '' || str_starts_with($envLine, '#') || !str_contains($envLine, '=')) {
                continue;
            }
            [$envKey, $envValue] = explode('=', $envLine, 2);
            if (trim($envKey) !== 'VAAK_PHYRIAN_MUTATION_TOKEN') {
                continue;
            }
            $envValue = trim($envValue, " \t\"'");
            if ($envValue !== '' && getenv('VAAK_PHYRIAN_MUTATION_TOKEN') === false) {
                putenv('VAAK_PHYRIAN_MUTATION_TOKEN=' . $envValue);
                $_ENV['VAAK_PHYRIAN_MUTATION_TOKEN'] = $envValue;
            }
        }
    }
}
if (trim((string) getenv('VAAK_PHYRIAN_RUST_MUTATIONS')) === '') {
    putenv('VAAK_PHYRIAN_RUST_MUTATIONS=1');
    $_ENV['VAAK_PHYRIAN_RUST_MUTATIONS'] = '1';
}

$lockPath = '/tmp/ap-phyrian-sync.lock';
$lockFh = @fopen($lockPath, 'c+');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, sprintf("[%s] phyrian-sync busy\n", gmdate('c')));
    exit(0);
}

$rows = [];
try {
    $db = ap_db();
    $sql = "SELECT owner_user_id
            FROM phyrian_bridge_links
            WHERE status = 'verified' AND unlinked_at IS NULL";
    $params = [];
    if ($owner > 0) {
        $sql .= ' AND owner_user_id = ?';
        $params[] = $owner;
    }
    $sql .= ' ORDER BY owner_user_id ASC LIMIT ' . (int) $limit;
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    fwrite(STDERR, "phyrian-sync database error: " . $e->getMessage() . "\n");
    exit(1);
}

$started = microtime(true);
$ok = 0;
$skipped = 0;
$failed = 0;
foreach ($rows as $rawOwner) {
    $ownerId = (int) $rawOwner;
    $result = ap_phyrian_bridge_sync_from_opensim($ownerId, $force);
    if (!empty($result['ok'])) {
        if (!empty($result['skipped'])) {
            $skipped++;
        } else {
            $ok++;
        }
        continue;
    }
    $failed++;
    fwrite(STDERR, sprintf(
        "[%s] owner=%d error=%s\n",
        gmdate('c'),
        $ownerId,
        (string) ($result['error'] ?? 'sync failed')
    ));
}

fwrite(STDOUT, sprintf(
    "phyrian-sync linked=%d synced=%d skipped=%d failed=%d force=%d ms=%d\n",
    count($rows), $ok, $skipped, $failed, $force ? 1 : 0,
    (int) round((microtime(true) - $started) * 1000)
));
exit($failed > 0 ? 1 : 0);
