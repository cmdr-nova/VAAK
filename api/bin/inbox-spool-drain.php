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

/** The Rust prediction stored with this spool row (cutover F3), if any. */
function inbox_drain_route_shadow_result(PDO $db, int $id): ?string
{
    static $st = null;
    static $missing = false;
    if ($missing) {
        return null;
    }
    try {
        $st ??= $db->prepare('SELECT rust_result FROM ap_inbox_route_shadow WHERE spool_id = ?');
        $st->execute([$id]);
        $v = $st->fetchColumn();
        return is_string($v) && $v !== '' ? $v : null;
    } catch (Throwable $e) {
        // Release without the migration: stop asking.
        $missing = true;
        $st = null;
        return null;
    }
}

/**
 * Cutover F3 shadow: compare the result the Rust front door predicted for a
 * Delete/Tombstone with the router's. PHP outcomes outside the Delete branch
 * (a block found while routing, a drain-level drop) count as skipped.
 */
function inbox_drain_route_shadow_note(int $id, string $rust, string $php): void
{
    $field = in_array($php, ['delete_noop', 'local_mention_delete', 'local_status_delete'], true)
        ? ($php === $rust ? 'agree' : 'disagree')
        : 'skipped';
    if ($field === 'disagree') {
        ap_log(sprintf('route_shadow_disagree id=%d rust=%s php=%s', $id, $rust, $php));
    }
    $redis = function_exists('ap_redis_client') ? ap_redis_client() : null;
    if (!$redis) {
        return;
    }
    try {
        $redis->hIncrBy('vaak:inbox_route_shadow:stats:v1', $field, 1);
        $redis->hIncrBy('vaak:inbox_route_shadow:stats:v1', $field . ':' . $rust, 1);
        $redis->expire('vaak:inbox_route_shadow:stats:v1', 14 * 86400);
    } catch (Throwable $e) {
        // Shadow stats only.
    }
}

/**
 * Cutover F5 shadow: a like or boost of a local note. Rust predicted
 * "local_observe {owner}" (no notification for this recipient),
 * "local_observe {owner} {object_id}" (a notification row) or
 * "local_private_interaction_skipped". Agree needs the same result and, for
 * a notification, that live mentions row; with no notification predicted,
 * no live row of that recipient may carry this activity's id. Rows of other
 * accounts are not this delivery's: one activity delivered to several local
 * inboxes notifies only the note's owner. A bare "local_observe" (a worker
 * from before the owner was sent) only compares the result. Other PHP
 * outcomes count as skipped.
 */
function inbox_drain_interaction_shadow_note(PDO $db, int $id, string $rust, string $php, string $body): void
{
    $parts = explode(' ', $rust, 3);
    $rustResult = $parts[0];
    $owner = isset($parts[1]) ? (int) $parts[1] : 0;
    $mentionId = $parts[2] ?? '';
    $label = $rustResult . ($mentionId !== '' ? '+mention' : '');
    $field = 'skipped';
    if (in_array($php, ['local_observe', 'local_private_interaction_skipped', 'log'], true)) {
        $field = $php === $rustResult ? 'agree' : 'disagree';
        $why = $field === 'disagree' ? 'result' : '';
        if ($field === 'agree' && $php === 'local_observe') {
            try {
                if ($mentionId !== '') {
                    $st = $db->prepare('SELECT 1 FROM mentions WHERE owner_user_id = ? AND object_id = ? AND deleted_at IS NULL LIMIT 1');
                    $st->execute([$owner, $mentionId]);
                    if ($st->fetchColumn() === false) {
                        $field = 'disagree';
                        $why = 'mention_missing';
                    }
                } else {
                    $doc = json_decode($body, true);
                    $activityId = is_array($doc) ? ap_as_id($doc['id'] ?? null) : null;
                    if (is_string($activityId) && $activityId !== '' && $owner > 0) {
                        $st = $db->prepare('SELECT 1 FROM mentions WHERE activity_id = ? AND owner_user_id = ? AND deleted_at IS NULL LIMIT 1');
                        $st->execute([$activityId, $owner]);
                        if ($st->fetchColumn() !== false) {
                            $field = 'disagree';
                            $why = 'mention_unexpected';
                        }
                    }
                }
            } catch (Throwable $e) {
                $field = 'skipped';
            }
        }
        if ($field === 'disagree') {
            ap_log(sprintf('route_shadow_disagree id=%d rust=%s php=%s why=%s', $id, $label, $php, $why));
        }
    }
    $redis = function_exists('ap_redis_client') ? ap_redis_client() : null;
    if (!$redis) {
        return;
    }
    try {
        $redis->hIncrBy('vaak:inbox_route_shadow:stats:v1', $field, 1);
        $redis->hIncrBy('vaak:inbox_route_shadow:stats:v1', $field . ':' . $label, 1);
        $redis->expire('vaak:inbox_route_shadow:stats:v1', 14 * 86400);
    } catch (Throwable $e) {
        // Shadow stats only.
    }
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
        // A row whose run died (no exception to catch) still counts the attempt.
        $db->exec("UPDATE ap_inbox_spool
                   SET state = CASE WHEN attempts >= 5 THEN 'failed' ELSE 'pending' END,
                       result = CASE WHEN attempts >= 5 THEN 'error: run ended while processing' ELSE result END,
                       finished_at = CASE WHEN attempts >= 5 THEN now() END,
                       claimed_at = NULL
                   WHERE state = 'processing' AND claimed_at < now() - interval '10 minutes'");
        try {
            $db->exec("DELETE FROM ap_inbox_route_shadow WHERE created_at < now() - interval '7 days'");
        } catch (Throwable $e) {
            // Table not migrated yet.
        }
        $db->exec("DELETE FROM ap_inbox_spool WHERE id IN (
                       SELECT id FROM ap_inbox_spool
                       WHERE (finished_at < now() - interval '7 days' AND state IN ('done', 'dropped'))
                          -- Failed rows stay longer for debugging, but not forever.
                          OR (finished_at < now() - interval '30 days' AND state = 'failed')
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
        $shadowType = strtolower((string) $row['activity_type']);
        if (in_array($shadowType, ['delete', 'tombstone'], true)) {
            $rust = inbox_drain_route_shadow_result($db, $id);
            if ($rust !== null) {
                inbox_drain_route_shadow_note($id, $rust, (string) $out['result']);
            }
        } elseif (in_array($shadowType, ['like', 'emojireact', 'announce'], true)) {
            $rust = inbox_drain_route_shadow_result($db, $id);
            if ($rust !== null) {
                inbox_drain_interaction_shadow_note($db, $id, $rust, (string) $out['result'], (string) $row['body']);
            }
        }
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
