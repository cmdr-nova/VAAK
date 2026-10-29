<?php
/**
 * Drain ap_inbox_spool: inbox deliveries the Rust worker accepted (Rust
 * cutover F2). Each row is claimed once (FOR UPDATE SKIP LOCKED), routed by
 * ap_inbox_spool_process(), and marked done, dropped, or retried with
 * backoff. Rows stuck in "processing" (a crashed run) go back to pending.
 *
 * Runs with the PHP-FPM pool's env[] lines (VAAK_FPM_POOL_CONF, default
 * /etc/php/8.3/fpm/pool.d/www.conf), so routing sees the same flags as the
 * inbox did. The systemd unit restarts it after each budget so per-process
 * caches stay fresh.
 *
 *   php api/bin/inbox-spool-drain.php [--budget=300] [--once]
 *
 * --once processes what is pending now and exits (tests, manual runs).
 */
declare(strict_types=1);

$opts = getopt('', ['budget::', 'once']);
$budget = max(10, (int) ($opts['budget'] ?? 300));
$once = isset($opts['once']);

/** Copy `env[NAME] = value` lines from the FPM pool into this process. */
function inbox_drain_load_pool_env(string $path): int
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return 0;
    }
    $n = 0;
    foreach ($lines as $line) {
        if (!preg_match('/^\s*env\[([A-Za-z_][A-Za-z0-9_]*)\]\s*=\s*(.*?)\s*$/', $line, $m)) {
            continue;
        }
        $value = $m[2];
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if (getenv($m[1]) === false) {
            putenv($m[1] . '=' . $value);
            $n++;
        }
    }
    return $n;
}

$poolConf = getenv('VAAK_FPM_POOL_CONF') ?: '/etc/php/8.3/fpm/pool.d/www.conf';
inbox_drain_load_pool_env($poolConf);

define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__) . '/ap-inbox.php';

$db = ap_db();
if (ap_db_driver($db) !== 'pgsql') {
    fwrite(STDERR, "inbox-spool-drain: needs PostgreSQL (AP_DB_DSN)\n");
    exit(2);
}

$claim = $db->prepare(
    "UPDATE ap_inbox_spool SET state = 'processing', claimed_at = now(), attempts = attempts + 1
     WHERE id = (
         SELECT id FROM ap_inbox_spool
         WHERE state = 'pending' AND next_attempt_at <= now()
         ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED
     )
     RETURNING id, client_ip, key_id, request_path, signature_path, activity_type, body, attempts"
);
$finish = $db->prepare("UPDATE ap_inbox_spool SET state = ?, result = ?, finished_at = now() WHERE id = ?");
$retry = $db->prepare(
    "UPDATE ap_inbox_spool SET state = ?, result = ?, claimed_at = NULL,
        next_attempt_at = now() + make_interval(secs => ?), finished_at = CASE WHEN ? = 'failed' THEN now() END
     WHERE id = ?"
);

$deadline = time() + $budget;
$lastHousekeeping = 0;
$done = 0;
$queue = function_exists('ap_redis_client') ? ap_redis_client('queue') : null;

while (time() < $deadline) {
    if (time() - $lastHousekeeping >= 60) {
        $lastHousekeeping = time();
        ap_block_list_cached(true);
        $db->exec("UPDATE ap_inbox_spool SET state = 'pending', claimed_at = NULL
                   WHERE state = 'processing' AND claimed_at < now() - interval '10 minutes'");
        $db->exec("DELETE FROM ap_inbox_spool WHERE id IN (
                       SELECT id FROM ap_inbox_spool
                       WHERE finished_at < now() - interval '7 days' AND state IN ('done', 'dropped')
                       LIMIT 5000)");
    }
    $claim->execute();
    $row = $claim->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        if ($once) {
            break;
        }
        try {
            if ($queue) {
                $queue->brPop(['vaak:queue:inbox_spool'], 5);
            } else {
                sleep(2);
            }
        } catch (Throwable $e) {
            $queue = null;
            sleep(2);
        }
        continue;
    }
    $id = (int) $row['id'];
    try {
        $out = ap_inbox_spool_process($row);
        $finish->execute([$out['state'], $out['result'], $id]);
        $done++;
    } catch (Throwable $e) {
        $attempts = (int) $row['attempts'];
        $state = $attempts >= 5 ? 'failed' : 'pending';
        $message = substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 200);
        $retry->execute([$state, 'error: ' . $message, min(3600, 30 * $attempts * $attempts), $state, $id]);
        ap_log(sprintf('spool_error id=%d attempt=%d state=%s msg=%s', $id, $attempts, $state, $message));
    }
}

if ($once) {
    echo "inbox-spool-drain: processed {$done}\n";
}
