<?php
/** Read-only Redis health and ACL diagnostics. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/ap-redis.php';
$checks = [ap_redis_health_check('cache'), ap_redis_health_check('queue')];
$warnings = [];
foreach ($checks as $check) {
    foreach (($check['warnings'] ?? []) as $warning) {
        $warnings[] = (string) ($check['purpose'] ?? 'redis') . ': ' . $warning;
    }
}
fwrite(STDOUT, json_encode([
    'redis' => $checks,
    'warnings' => $warnings,
    'authoritative' => false,
], JSON_UNESCAPED_SLASHES) . "\n");
exit($warnings === [] ? 0 : 2);
