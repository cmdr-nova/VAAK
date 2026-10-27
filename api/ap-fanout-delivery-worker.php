<?php
/** Durable per-inbox ActivityPub fan-out worker. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$limit = 40;
foreach ($argv as $arg) if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = max(1, min(100, (int) $m[1]));

function ap_fanout_circuit_file(string $host): string
{
    return sys_get_temp_dir() . '/vaak-fanout-circuit-' . hash('sha256', strtolower($host)) . '.json';
}

function ap_fanout_circuit_open(string $host): int
{
    $path = ap_fanout_circuit_file($host);
    $state = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
    return is_array($state) ? max(0, (int) ($state['open_until'] ?? 0)) : 0;
}

function ap_fanout_circuit_failure(string $host): void
{
    $path = ap_fanout_circuit_file($host);
    $state = is_file($path) ? json_decode((string) @file_get_contents($path), true) : [];
    $failures = is_array($state) ? (int) ($state['failures'] ?? 0) : 0;
    $open = $failures + 1 >= 3 ? time() + 300 : 0;
    @file_put_contents($path, json_encode(['failures' => $failures + 1, 'open_until' => $open]), LOCK_EX);
}

function ap_fanout_circuit_success(string $host): void
{
    @unlink(ap_fanout_circuit_file($host));
}

try {
    if (!defined('AP_INBOX_LIB_ONLY')) define('AP_INBOX_LIB_ONLY', true);
    require_once __DIR__ . '/ap-db.php';
    require_once __DIR__ . '/ap-auth.php';
    require_once __DIR__ . '/ap-r2.php';
    require_once __DIR__ . '/ap-bsky.php';
    require_once __DIR__ . '/ap-inbox.php';
    $db = ap_db();
    if (function_exists('ap_fanout_host_backfill')) {
        ap_fanout_host_backfill($db);
    }
    if (function_exists('ap_fanout_host_requeue_probe')) {
        ap_fanout_host_requeue_probe($db);
    }
    $pendingCount = (int) $db->query("SELECT COUNT(*) FROM ap_fanout_delivery_queue WHERE status = 'pending'")->fetchColumn();
    if (function_exists('ap_worker_backpressure_limit')) {
        $limit = ap_worker_backpressure_limit('fanout-delivery', $limit, $pendingCount);
    }
    $now = gmdate('c');
    $stale = gmdate('c', time() - 600);
    $db->prepare("UPDATE ap_fanout_delivery_queue SET status='pending', claimed_at=NULL, attempts=attempts+1, last_error='Worker lease expired', updated_at=? WHERE status='processing' AND claimed_at < ?")->execute([$now, $stale]);
    $st = $db->prepare("SELECT * FROM ap_fanout_delivery_queue WHERE status='pending' AND next_attempt_at <= ? ORDER BY priority, next_attempt_at, id LIMIT ?");
    $st->bindValue(1, $now); $st->bindValue(2, $limit, PDO::PARAM_INT); $st->execute();
    $stats = ['claimed'=>0,'succeeded'=>0,'retried'=>0,'failed'=>0];
    foreach ($st->fetchAll() as $row) {
        $claim = $db->prepare("UPDATE ap_fanout_delivery_queue SET status='processing',claimed_at=?,updated_at=? WHERE id=? AND status='pending'");
        $claim->execute([$now, $now, (int) $row['id']]);
        if ($claim->rowCount() !== 1) continue;
        $stats['claimed']++;
        // A row that is not attempted must not inherit the previous row's
        // delivery error: that text decides dead versus live for its host.
        $GLOBALS['ap_delivery_last_error'] = '';
        $activity = json_decode((string) $row['activity_json'], true);
        $host = function_exists('ap_fanout_host_normalize')
            ? ap_fanout_host_normalize((string) $row['inbox_url'])
            : strtolower((string) (parse_url((string) $row['inbox_url'], PHP_URL_HOST) ?: ''));
        if ($host === '') {
            $host = strtolower((string) (parse_url((string) $row['inbox_url'], PHP_URL_HOST) ?: 'unknown'));
        }
        $probe = str_starts_with((string) ($row['last_error'] ?? ''), 'host-probe');
        $blueskyActivity = function_exists('ap_fanout_activity_is_bluesky')
            && ap_fanout_activity_is_bluesky((string) ($row['activity_json'] ?? ''));
        $blueskyHost = function_exists('ap_fanout_host_is_bluesky_host') && ap_fanout_host_is_bluesky_host($host);
        if ($blueskyActivity && !$blueskyHost) {
            $db->prepare("UPDATE ap_fanout_delivery_queue SET status='failed', attempts=attempts+1, claimed_at=NULL, last_error=?, updated_at=? WHERE id=?")->execute([
                'Bluesky activity is not delivered', gmdate('c'), (int) $row['id'],
            ]);
            $stats['failed']++;
            continue;
        }
        $suspended = function_exists('ap_fanout_host_is_suspended_host') && ap_fanout_host_is_suspended_host($host);
        $circuitUntil = ap_fanout_circuit_open($host);
        $circuitOpen = $circuitUntil > time();
        $plan = function_exists('ap_fanout_worker_outcome')
            ? ap_fanout_worker_outcome($suspended, $probe, $circuitOpen, false, (int) $row['attempts'])
            : ['try_http' => $circuitUntil <= time(), 'status' => 'pending', 'terminal' => false, 'record_terminal' => false];
        $ok = false;
        if (!empty($plan['try_http'])) {
            $ok = is_array($activity) && ap_deliver_signed_json((string) $row['inbox_url'], $activity, (string) $row['key_id'], (string) $row['priv_path'], 2.5);
            if ($ok) ap_fanout_circuit_success($host);
            else ap_fanout_circuit_failure($host);
            if ($ok && function_exists('ap_fanout_host_note_success')) ap_fanout_host_note_success($host);
        }
        $outcome = function_exists('ap_fanout_worker_outcome')
            ? ap_fanout_worker_outcome($suspended, $probe, $circuitOpen, $ok, (int) $row['attempts'])
            : ['status' => $ok ? 'succeeded' : (((int) $row['attempts'] + 1) >= 12 ? 'failed' : 'pending'), 'terminal' => !$ok && ((int) $row['attempts'] + 1) >= 12, 'record_terminal' => !$ok && ((int) $row['attempts'] + 1) >= 12];
        if (($outcome['status'] ?? '') === 'succeeded') {
            $db->prepare("UPDATE ap_fanout_delivery_queue SET status='succeeded',claimed_at=NULL,last_error=NULL,updated_at=? WHERE id=?")->execute([gmdate('c'), (int) $row['id']]);
            $stats['succeeded']++;
            continue;
        }
        $attempts = (int) $row['attempts'] + 1;
        $terminal = !empty($outcome['terminal']);
        $delay = $circuitUntil > time() ? max(60, $circuitUntil - time()) : min(3600, 15 * (2 ** min(8, $attempts - 1)));
        if ($suspended && !$probe) {
            $detail = 'Suspended remote host';
        } else {
            $detail = trim((string) ($GLOBALS['ap_delivery_last_error'] ?? ''));
            if ($detail === '') {
                $detail = $circuitUntil > time() ? 'Remote host circuit is open' : 'Remote inbox delivery failed';
            }
        }
        if ($terminal && !empty($outcome['record_terminal']) && function_exists('ap_fanout_host_note_terminal')) {
            ap_fanout_host_note_terminal($host, $detail);
        }
        $db->prepare('UPDATE ap_fanout_delivery_queue SET status=?, attempts=?, next_attempt_at=?, claimed_at=NULL, last_error=?, updated_at=? WHERE id=?')->execute([
            $terminal ? 'failed' : 'pending', $attempts, gmdate('c', time() + $delay), substr($detail, 0, 500), gmdate('c'), (int) $row['id']
        ]);
        $stats[$terminal ? 'failed' : 'retried']++;
    }
    fwrite(STDOUT, sprintf("[%s] fanout_delivery claimed=%d succeeded=%d retried=%d failed=%d\n", gmdate('c'), $stats['claimed'], $stats['succeeded'], $stats['retried'], $stats['failed']));
} catch (Throwable $e) {
    error_log('[ap-fanout-delivery-worker] ' . $e->getMessage());
    fwrite(STDERR, "fanout_delivery worker failed\n");
    exit(1);
}
