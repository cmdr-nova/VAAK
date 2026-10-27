<?php
/**
 * Durable health for ActivityPub fan-out peers.
 *
 * A host is suspended only after a long streak of terminal transport failures
 * and no recent success. HTTP 401, 403 and 500 are live answers: they do not
 * count as a dead streak, and a recent one keeps the host unsuspended.
 * Bluesky hosts and Bluesky activities are never probed. The only new outbound
 * request is at most one ActivityPub delivery per suspended host per day.
 */
declare(strict_types=1);

function ap_fanout_host_marker(): string
{
    return '*';
}

function ap_fanout_config_int(string $env, int $default, int $min, int $max): int
{
    $raw = getenv($env);
    if ($raw === false || trim((string) $raw) === '') {
        $config = $GLOBALS['ap_fanout_host_config'][$env] ?? null;
        if ($config === null || $config === '') {
            return $default;
        }
        $raw = (string) $config;
    }
    $raw = trim((string) $raw);
    if (!preg_match('/^\d+$/', $raw)) {
        return $default;
    }
    return max($min, min($max, (int) $raw));
}

function ap_fanout_dead_days(): int
{
    return ap_fanout_config_int('VAAK_FANOUT_DEAD_DAYS', 7, 1, 90);
}

function ap_fanout_dead_failures(): int
{
    return ap_fanout_config_int('VAAK_FANOUT_DEAD_FAILURES', 20, 1, 100000);
}

function ap_fanout_host_now(): int
{
    return (int) ($GLOBALS['ap_fanout_host_now'] ?? time());
}

function ap_fanout_host_unix(?string $iso): ?int
{
    if ($iso === null || $iso === '') {
        return null;
    }
    $unix = strtotime($iso);
    return $unix === false ? null : $unix;
}

function ap_fanout_host_normalize(string $hostOrInbox): string
{
    $raw = trim($hostOrInbox);
    if ($raw === '' || $raw === ap_fanout_host_marker()) {
        return '';
    }
    if (str_contains($raw, '://')) {
        $host = parse_url($raw, PHP_URL_HOST);
        $raw = is_string($host) ? $host : '';
    }
    $raw = strtolower(rtrim($raw, '.'));
    if ($raw === '' || !preg_match('/^[a-z0-9.-]+$/', $raw) || !str_contains($raw, '.')) {
        return '';
    }
    return $raw;
}

function ap_fanout_host_is_bluesky_host(string $host): bool
{
    $host = ap_fanout_host_normalize($host);
    if ($host === '') {
        return false;
    }
    return $host === 'bsky.app' || str_ends_with($host, '.bsky.app')
        || $host === 'bsky.social' || str_ends_with($host, '.bsky.social');
}

/** HTTP status when the peer answered with a live rejection, otherwise null. */
function ap_fanout_live_rejection_status(string $error): ?int
{
    if (preg_match('/\bHTTP\s+(401|403|500)\b/', $error, $match) === 1) {
        return (int) $match[1];
    }
    return null;
}

/**
 * True when a terminal error is evidence the host is unreachable.
 * Live rejections, local signing problems, and an open circuit are not.
 */
function ap_fanout_counts_as_dead_failure(string $error): bool
{
    if (ap_fanout_live_rejection_status($error) !== null) {
        return false;
    }
    $lower = strtolower(trim($error));
    if ($lower === '') {
        return false;
    }
    foreach ([
        'circuit is open',
        'suspended remote host',
        'lease expired',
        'signing key',
        'activity signature',
        'activity json',
        'curl is required',
        'invalid https inbox',
        'bluesky activity',
    ] as $needle) {
        if (str_contains($lower, $needle)) {
            return false;
        }
    }
    return true;
}

function ap_fanout_activity_is_bluesky(string $activityJson): bool
{
    $doc = json_decode($activityJson, true);
    if (!is_array($doc)) {
        return false;
    }
    $actor = is_string($doc['actor'] ?? null) ? $doc['actor'] : '';
    if ($actor === '' && is_array($doc['actor'] ?? null)) {
        $actor = is_string($doc['actor']['id'] ?? null) ? $doc['actor']['id'] : '';
    }
    if (str_starts_with($actor, 'did:') || str_contains($actor, 'bsky.app/') || str_contains($actor, 'bsky.social/')) {
        return true;
    }
    $host = ap_fanout_host_normalize($actor);
    return $host !== '' && ap_fanout_host_is_bluesky_host($host);
}

/**
 * Suspended when the dead streak is old enough and long enough, and the host
 * has neither succeeded nor answered 401/403/500 inside the same window.
 */
function ap_fanout_host_suspended(?int $lastSuccessUnix, ?int $streakStartedUnix, int $consecutive, ?int $lastLiveUnix, int $now, ?int $deadDays = null, ?int $deadFailures = null): bool
{
    $days = $deadDays ?? ap_fanout_dead_days();
    $need = $deadFailures ?? ap_fanout_dead_failures();
    if ($consecutive < $need || $streakStartedUnix === null) {
        return false;
    }
    $cutoff = $now - ($days * 86400);
    if ($streakStartedUnix > $cutoff) {
        return false;
    }
    if ($lastSuccessUnix !== null && $lastSuccessUnix > $cutoff) {
        return false;
    }
    if ($lastLiveUnix !== null && $lastLiveUnix > $cutoff) {
        return false;
    }
    return true;
}

function ap_fanout_terminal_attempt_limit(bool $suspended): int
{
    return $suspended ? 1 : 12;
}

function ap_fanout_probe_due(?int $lastProbeUnix, int $now): bool
{
    if ($lastProbeUnix === null) {
        return true;
    }
    return ($now - $lastProbeUnix) >= 86400;
}

/** @return array{last_success_at:?int,streak_started_at:?int,consecutive:int,last_live_at:?int,suspended:bool} */
function ap_fanout_host_after_success(?int $lastLiveUnix, int $now): array
{
    return [
        'last_success_at' => $now,
        'streak_started_at' => null,
        'consecutive' => 0,
        'last_live_at' => $lastLiveUnix,
        'suspended' => false,
    ];
}

/**
 * @param array{last_success_at:?int,streak_started_at:?int,consecutive:int,last_live_at:?int,last_live_status?:?int} $row
 * @return array{last_success_at:?int,streak_started_at:?int,consecutive:int,last_live_at:?int,last_live_status:?int,suspended:bool,counted:bool}
 */
function ap_fanout_host_after_terminal_failure(array $row, string $error, int $now, ?int $deadDays = null, ?int $deadFailures = null): array
{
    $success = $row['last_success_at'] ?? null;
    $streak = $row['streak_started_at'] ?? null;
    $consecutive = (int) ($row['consecutive'] ?? 0);
    $liveAt = $row['last_live_at'] ?? null;
    $liveStatus = isset($row['last_live_status']) ? (int) $row['last_live_status'] : null;
    $currentLive = ap_fanout_live_rejection_status($error);
    $counted = ap_fanout_counts_as_dead_failure($error);
    if ($counted) {
        if ($consecutive <= 0 || $streak === null) {
            $streak = $now;
        }
        $consecutive++;
    } elseif ($currentLive !== null) {
        $liveAt = $now;
        $liveStatus = $currentLive;
    }
    return [
        'last_success_at' => $success,
        'streak_started_at' => $streak,
        'consecutive' => $consecutive,
        'last_live_at' => $liveAt,
        'last_live_status' => $liveStatus,
        'counted' => $counted,
        'suspended' => ap_fanout_host_suspended($success, $streak, $consecutive, $liveAt, $now, $deadDays, $deadFailures),
    ];
}

/**
 * @return array{try_http:bool,status:string,terminal:bool,record_terminal:bool}
 */
function ap_fanout_worker_outcome(bool $suspended, bool $probe, bool $circuitOpen, bool $httpOk, int $attempts): array
{
    if ($suspended && !$probe) {
        return ['try_http' => false, 'status' => 'failed', 'terminal' => true, 'record_terminal' => false];
    }
    $tryHttp = !($circuitOpen && !$probe);
    if (!$tryHttp) {
        $next = $attempts + 1;
        $terminal = $next >= ap_fanout_terminal_attempt_limit(false);
        return ['try_http' => false, 'status' => $terminal ? 'failed' : 'pending', 'terminal' => $terminal, 'record_terminal' => $terminal];
    }
    if ($httpOk) {
        return ['try_http' => true, 'status' => 'succeeded', 'terminal' => false, 'record_terminal' => false];
    }
    $next = $attempts + 1;
    $limit = ap_fanout_terminal_attempt_limit($suspended);
    $terminal = $next >= $limit;
    return ['try_http' => true, 'status' => $terminal ? 'failed' : 'pending', 'terminal' => $terminal, 'record_terminal' => $terminal];
}

/**
 * Walk queue rows in chronological order. Success ends the streak. Live
 * rejections do not. The streak that remains is the current one.
 *
 * @param list<array<string,mixed>> $rows
 * @return array{consecutive:int,streak_started_at:?string,last_success_at:?string,last_live_at:?string,last_live_status:?int,last_failure_at:?string,last_error:?string}
 */
function ap_fanout_host_state_from_history(array $rows): array
{
    $streak = 0;
    $streakStart = null;
    $lastSuccess = null;
    $lastLiveAt = null;
    $lastLiveStatus = null;
    $lastFailureAt = null;
    $lastError = null;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $at = (string) ($row['updated_at'] ?? '');
        $status = (string) ($row['status'] ?? '');
        if ($status === 'succeeded') {
            $lastSuccess = $at !== '' ? $at : $lastSuccess;
            $streak = 0;
            $streakStart = null;
            continue;
        }
        if ($status !== 'failed') {
            continue;
        }
        $error = (string) ($row['last_error'] ?? '');
        $live = ap_fanout_live_rejection_status($error);
        if (!ap_fanout_counts_as_dead_failure($error)) {
            if ($live !== null) {
                $lastLiveStatus = $live;
                $lastLiveAt = $at !== '' ? $at : $lastLiveAt;
            }
            $lastFailureAt = $at !== '' ? $at : $lastFailureAt;
            $lastError = $error;
            continue;
        }
        if ($streak === 0) {
            $streakStart = $at !== '' ? $at : $streakStart;
        }
        $streak++;
        $lastFailureAt = $at !== '' ? $at : $lastFailureAt;
        $lastError = $error;
    }
    return [
        'consecutive' => $streak,
        'streak_started_at' => $streakStart,
        'last_success_at' => $lastSuccess,
        'last_live_at' => $lastLiveAt,
        'last_live_status' => $lastLiveStatus,
        'last_failure_at' => $lastFailureAt,
        'last_error' => $lastError,
    ];
}

function ap_fanout_host_sqlite_ddl(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS ap_fanout_host_health (
    host TEXT PRIMARY KEY,
    last_success_at TEXT,
    streak_started_at TEXT,
    consecutive_terminal_failures INTEGER NOT NULL DEFAULT 0,
    last_failure_at TEXT,
    last_error TEXT,
    last_live_status INTEGER,
    last_live_at TEXT,
    last_probe_at TEXT,
    probe_inbox_url TEXT,
    probe_activity_json TEXT,
    probe_key_id TEXT,
    probe_priv_path TEXT,
    updated_at TEXT NOT NULL
)
SQL;
}

function ap_fanout_host_ensure_sqlite(PDO $db): void
{
    if (strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'pgsql') {
        return;
    }
    $db->exec(ap_fanout_host_sqlite_ddl());
    $db->exec('CREATE INDEX IF NOT EXISTS idx_ap_fanout_host_health_failures ON ap_fanout_host_health (consecutive_terminal_failures, last_probe_at)');
    unset($GLOBALS['ap_fanout_host_table_ready']);
}

function ap_fanout_host_cache_reset(): void
{
    unset($GLOBALS['ap_fanout_host_cache'], $GLOBALS['ap_fanout_host_cache_at'], $GLOBALS['ap_fanout_host_table_ready']);
}

function ap_fanout_host_table_ready(?PDO $db = null): bool
{
    if (array_key_exists('ap_fanout_host_table_ready', $GLOBALS) && is_bool($GLOBALS['ap_fanout_host_table_ready'])) {
        return $GLOBALS['ap_fanout_host_table_ready'];
    }
    $ok = false;
    try {
        $db ??= ap_db();
        $driver = strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $name = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ap_fanout_host_health'")->fetchColumn();
            $ok = $name === 'ap_fanout_host_health';
        } else {
            $name = $db->query("SELECT to_regclass('public.ap_fanout_host_health')")->fetchColumn();
            $ok = is_string($name) && $name !== '';
        }
    } catch (Throwable $e) {
        $ok = false;
    }
    $GLOBALS['ap_fanout_host_table_ready'] = $ok;
    return $ok;
}

/** @return array<string,array{last_success_at:?string,streak_started_at:?string,consecutive:int,last_live_at:?string,last_probe_at:?string}> */
function ap_fanout_host_cache(?PDO $db = null): array
{
    $now = ap_fanout_host_now();
    $cached = $GLOBALS['ap_fanout_host_cache'] ?? null;
    $at = (int) ($GLOBALS['ap_fanout_host_cache_at'] ?? 0);
    if (is_array($cached) && ($now - $at) < 60 && ($now - $at) >= 0) {
        return $cached;
    }
    $map = [];
    try {
        if (!ap_fanout_host_table_ready($db)) {
            $GLOBALS['ap_fanout_host_cache'] = [];
            $GLOBALS['ap_fanout_host_cache_at'] = $now;
            return [];
        }
        $db ??= ap_db();
        $st = $db->prepare(
            'SELECT host, last_success_at, streak_started_at, consecutive_terminal_failures, last_live_at, last_probe_at
             FROM ap_fanout_host_health
             WHERE host <> ? AND consecutive_terminal_failures > 0'
        );
        $st->execute([ap_fanout_host_marker()]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $host = ap_fanout_host_normalize((string) ($row['host'] ?? ''));
            if ($host === '') {
                continue;
            }
            $map[$host] = [
                'last_success_at' => isset($row['last_success_at']) ? (string) $row['last_success_at'] : null,
                'streak_started_at' => isset($row['streak_started_at']) ? (string) $row['streak_started_at'] : null,
                'consecutive' => (int) ($row['consecutive_terminal_failures'] ?? 0),
                'last_live_at' => isset($row['last_live_at']) ? (string) $row['last_live_at'] : null,
                'last_probe_at' => isset($row['last_probe_at']) ? (string) $row['last_probe_at'] : null,
            ];
        }
    } catch (Throwable $e) {
        $map = [];
    }
    $GLOBALS['ap_fanout_host_cache'] = $map;
    $GLOBALS['ap_fanout_host_cache_at'] = $now;
    return $map;
}

function ap_fanout_host_is_suspended_host(string $host): bool
{
    $host = ap_fanout_host_normalize($host);
    if ($host === '' || ap_fanout_host_is_bluesky_host($host)) {
        return false;
    }
    $cache = ap_fanout_host_cache();
    if (!isset($cache[$host])) {
        return false;
    }
    $row = $cache[$host];
    return ap_fanout_host_suspended(
        ap_fanout_host_unix($row['last_success_at']),
        ap_fanout_host_unix($row['streak_started_at']),
        (int) $row['consecutive'],
        ap_fanout_host_unix($row['last_live_at']),
        ap_fanout_host_now()
    );
}

function ap_fanout_host_note_success(string $host): void
{
    try {
        $host = ap_fanout_host_normalize($host);
        if ($host === '' || ap_fanout_host_is_bluesky_host($host)) {
            return;
        }
        $cache = ap_fanout_host_cache();
        if (!isset($cache[$host])) {
            return;
        }
        $now = ap_fanout_host_now();
        $iso = gmdate('c', $now);
        $db = ap_db();
        $db->prepare(
            'UPDATE ap_fanout_host_health
             SET last_success_at = ?, streak_started_at = NULL, consecutive_terminal_failures = 0, updated_at = ?
             WHERE host = ?'
        )->execute([$iso, $iso, $host]);
        unset($cache[$host]);
        $GLOBALS['ap_fanout_host_cache'] = $cache;
    } catch (Throwable $e) {
        error_log('[ap-fanout-host] success ' . $e->getMessage());
    }
}

function ap_fanout_host_note_inbound(string $host): void
{
    ap_fanout_host_note_success($host);
}

function ap_fanout_host_note_terminal(string $host, string $error): void
{
    try {
        $host = ap_fanout_host_normalize($host);
        if ($host === '' || ap_fanout_host_is_bluesky_host($host)) {
            return;
        }
        if (!ap_fanout_host_table_ready()) {
            return;
        }
        $db = ap_db();
        $existing = $db->prepare(
            'SELECT last_success_at, streak_started_at, consecutive_terminal_failures, last_live_status, last_live_at, last_probe_at
             FROM ap_fanout_host_health WHERE host = ?'
        );
        $existing->execute([$host]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        $prior = is_array($row) ? [
            'last_success_at' => isset($row['last_success_at']) ? (string) $row['last_success_at'] : null,
            'streak_started_at' => isset($row['streak_started_at']) ? (string) $row['streak_started_at'] : null,
            'consecutive' => (int) ($row['consecutive_terminal_failures'] ?? 0),
            'last_live_status' => isset($row['last_live_status']) ? (int) $row['last_live_status'] : null,
            'last_live_at' => isset($row['last_live_at']) ? (string) $row['last_live_at'] : null,
            'last_probe_at' => isset($row['last_probe_at']) ? (string) $row['last_probe_at'] : null,
        ] : [
            'last_success_at' => null,
            'streak_started_at' => null,
            'consecutive' => 0,
            'last_live_status' => null,
            'last_live_at' => null,
            'last_probe_at' => null,
        ];
        $cache = ap_fanout_host_cache();
        $now = ap_fanout_host_now();
        $next = ap_fanout_host_after_terminal_failure([
            'last_success_at' => ap_fanout_host_unix($prior['last_success_at']),
            'streak_started_at' => ap_fanout_host_unix($prior['streak_started_at']),
            'consecutive' => (int) $prior['consecutive'],
            'last_live_at' => ap_fanout_host_unix($prior['last_live_at']),
            'last_live_status' => $prior['last_live_status'],
        ], $error, $now);
        $iso = gmdate('c', $now);
        $successIso = $next['last_success_at'] !== null ? gmdate('c', (int) $next['last_success_at']) : $prior['last_success_at'];
        $streakIso = $next['streak_started_at'] !== null ? gmdate('c', (int) $next['streak_started_at']) : null;
        $liveIso = $next['last_live_at'] !== null ? gmdate('c', (int) $next['last_live_at']) : null;
        $db->prepare(
            'INSERT INTO ap_fanout_host_health
                (host, last_success_at, streak_started_at, consecutive_terminal_failures, last_failure_at, last_error, last_live_status, last_live_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON CONFLICT(host) DO UPDATE SET
                last_success_at = excluded.last_success_at,
                streak_started_at = excluded.streak_started_at,
                consecutive_terminal_failures = excluded.consecutive_terminal_failures,
                last_failure_at = excluded.last_failure_at,
                last_error = excluded.last_error,
                last_live_status = excluded.last_live_status,
                last_live_at = excluded.last_live_at,
                updated_at = excluded.updated_at'
        )->execute([
            $host,
            $successIso,
            $streakIso,
            (int) $next['consecutive'],
            $iso,
            substr($error, 0, 500),
            $next['last_live_status'],
            $liveIso,
            $iso,
        ]);
        if ((int) $next['consecutive'] > 0) {
            $cache[$host] = [
                'last_success_at' => $successIso,
                'streak_started_at' => $streakIso,
                'consecutive' => (int) $next['consecutive'],
                'last_live_at' => $liveIso,
                'last_probe_at' => $prior['last_probe_at'] ?? null,
            ];
        } else {
            unset($cache[$host]);
        }
        $GLOBALS['ap_fanout_host_cache'] = $cache;
        $GLOBALS['ap_fanout_host_cache_at'] = $now;
    } catch (Throwable $e) {
        error_log('[ap-fanout-host] terminal ' . $e->getMessage());
    }
}

function ap_fanout_host_backfill(?PDO $db = null): void
{
    try {
        $db ??= ap_db();
        if (!ap_fanout_host_table_ready($db)) {
            return;
        }
        $marker = $db->prepare('SELECT 1 FROM ap_fanout_host_health WHERE host = ?');
        $marker->execute([ap_fanout_host_marker()]);
        if ($marker->fetchColumn()) {
            return;
        }
        $rows = [];
        try {
            $rows = $db->query(
                "SELECT inbox_url, status, updated_at, last_error FROM ap_fanout_delivery_queue
                 WHERE status IN ('failed','succeeded')
                 ORDER BY updated_at DESC, id DESC LIMIT 5000"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $rows = array_reverse($rows);
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'ap_fanout_delivery_queue')) {
                return;
            }
        }
        $byHost = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $host = ap_fanout_host_normalize((string) ($row['inbox_url'] ?? ''));
            if ($host === '' || ap_fanout_host_is_bluesky_host($host)) {
                continue;
            }
            $byHost[$host][] = $row;
        }
        $nowIso = gmdate('c', ap_fanout_host_now());
        $insert = $db->prepare(
            'INSERT INTO ap_fanout_host_health
                (host, last_success_at, streak_started_at, consecutive_terminal_failures, last_failure_at, last_error, last_live_status, last_live_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON CONFLICT(host) DO NOTHING'
        );
        foreach ($byHost as $host => $hostRows) {
            $state = ap_fanout_host_state_from_history($hostRows);
            if ($state['consecutive'] === 0 && $state['last_success_at'] === null && $state['last_live_at'] === null) {
                continue;
            }
            $insert->execute([
                $host,
                $state['last_success_at'],
                $state['streak_started_at'],
                $state['consecutive'],
                $state['last_failure_at'],
                $state['last_error'] !== null ? substr($state['last_error'], 0, 500) : null,
                $state['last_live_status'],
                $state['last_live_at'],
                $nowIso,
            ]);
        }
        $insert->execute([ap_fanout_host_marker(), null, null, 0, null, null, null, null, $nowIso]);
        ap_fanout_host_cache_reset();
    } catch (Throwable $e) {
        error_log('[ap-fanout-host] backfill ' . $e->getMessage());
    }
}

function ap_fanout_host_kick_worker(): void
{
    if (getenv('VAAK_FANOUT_HOST_NO_KICK') === '1' || !empty($GLOBALS['ap_fanout_host_no_kick'])) {
        return;
    }
    $worker = __DIR__ . '/ap-fanout-delivery-worker.php';
    if (!is_file($worker) || !function_exists('ap_php_cli_binary')) {
        return;
    }
    $running = trim((string) @shell_exec("pgrep -fc 'ap-fanout-delivery-worker.php' 2>/dev/null"));
    if ((int) $running > 0) {
        return;
    }
    $php = ap_php_cli_binary();
    @exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' --limit=40 >/dev/null 2>&1 </dev/null &');
}

function ap_fanout_host_enqueue_probe(PDO $db, string $host, string $inboxUrl, string $activityJson, string $keyId, string $privPath, int $now, bool $kick): bool
{
    if (ap_fanout_activity_is_bluesky($activityJson) || ap_fanout_host_is_bluesky_host($host)) {
        return false;
    }
    $doc = json_decode($activityJson, true);
    if (!is_array($doc)) {
        return false;
    }
    $cache = ap_fanout_host_cache($db);
    $prev = $cache[$host]['last_probe_at'] ?? null;
    $iso = gmdate('c', $now);
    $claim = $db->prepare(
        'UPDATE ap_fanout_host_health
         SET last_probe_at = ?, probe_inbox_url = ?, probe_activity_json = ?, probe_key_id = ?, probe_priv_path = ?, updated_at = ?
         WHERE host = ? AND (last_probe_at IS NULL OR last_probe_at = ?)'
    );
    $claim->execute([$iso, $inboxUrl, $activityJson, $keyId, $privPath, $iso, $host, $prev]);
    if ($claim->rowCount() !== 1) {
        return false;
    }
    $cache[$host]['last_probe_at'] = $iso;
    $GLOBALS['ap_fanout_host_cache'] = $cache;
    $GLOBALS['ap_fanout_host_cache_at'] = $now;

    $activityKey = is_string($doc['id'] ?? null) && $doc['id'] !== ''
        ? (string) $doc['id']
        : 'sha256:' . hash('sha256', $activityJson);
    // Reuse a queued ActivityPub payload for this inbox. A Bluesky activity
    // on the same inbox stays where it is and is not turned into the probe.
    $picked = $db->prepare(
        "SELECT id, activity_json FROM ap_fanout_delivery_queue
         WHERE inbox_url = ? AND status <> 'processing'
         ORDER BY CASE status WHEN 'pending' THEN 0 WHEN 'failed' THEN 1 ELSE 2 END, id DESC
         LIMIT 20"
    );
    $picked->execute([$inboxUrl]);
    $reuseId = 0;
    foreach ($picked->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
        if (!is_array($candidate) || ap_fanout_activity_is_bluesky((string) ($candidate['activity_json'] ?? ''))) {
            continue;
        }
        $reuseId = (int) ($candidate['id'] ?? 0);
        if ($reuseId > 0) {
            break;
        }
    }
    $reused = false;
    if ($reuseId > 0) {
        $update = $db->prepare(
            "UPDATE ap_fanout_delivery_queue
             SET status = 'pending', attempts = 0, next_attempt_at = ?, claimed_at = NULL, last_error = 'host-probe', updated_at = ?
             WHERE id = ? AND status <> 'processing'"
        );
        $update->execute([$iso, $iso, $reuseId]);
        $reused = $update->rowCount() === 1;
    }
    if (!$reused) {
        $insert = $db->prepare(
            'INSERT INTO ap_fanout_delivery_queue
                (activity_key,inbox_url,activity_json,key_id,priv_path,status,priority,attempts,next_attempt_at,last_error,created_at,updated_at)
             VALUES (?,?,?,?,?,\'pending\',1,0,?,\'host-probe\',?,?)
             ON CONFLICT(activity_key,inbox_url) DO NOTHING'
        );
        $insert->execute([$activityKey, $inboxUrl, $activityJson, $keyId, $privPath, $iso, $iso, $iso]);
    }
    if ($kick) {
        ap_fanout_host_kick_worker();
    }
    return true;
}

/**
 * Sync fan-out helper. A suspended host is not contacted here. A due probe is
 * queued instead and this returns false so the request does not wait on it.
 */
function ap_fanout_deliver_or_hold(string $inbox, array $activity, string $keyId, string $priv, float $timeout): bool
{
    if (ap_fanout_host_admit($inbox, $activity, $keyId, $priv) !== 'send') {
        return false;
    }
    if (!function_exists('ap_deliver_signed_json')) {
        return false;
    }
    return ap_deliver_signed_json($inbox, $activity, $keyId, $priv, $timeout);
}

/** @return 'send'|'probe'|'skip' */
function ap_fanout_host_admit(string $inboxUrl, array $activity, string $keyId, string $privPath): string
{
    try {
        $host = ap_fanout_host_normalize($inboxUrl);
        if ($host === '' || ap_fanout_host_is_bluesky_host($host)) {
            return 'send';
        }
        if (!ap_fanout_host_is_suspended_host($host)) {
            return 'send';
        }
        $json = json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '' || ap_fanout_activity_is_bluesky($json)) {
            return 'skip';
        }
        $now = ap_fanout_host_now();
        $cache = ap_fanout_host_cache();
        $prev = ap_fanout_host_unix($cache[$host]['last_probe_at'] ?? null);
        if (!ap_fanout_probe_due($prev, $now)) {
            return 'skip';
        }
        if (!ap_fanout_host_enqueue_probe(ap_db(), $host, $inboxUrl, $json, $keyId, $privPath, $now, true)) {
            return 'skip';
        }
        return 'probe';
    } catch (Throwable $e) {
        error_log('[ap-fanout-host] admit ' . $e->getMessage());
        return 'send';
    }
}

function ap_fanout_host_requeue_probe(?PDO $db = null): bool
{
    try {
        $db ??= ap_db();
        if (!ap_fanout_host_table_ready($db)) {
            return false;
        }
        $now = ap_fanout_host_now();
        $candidates = [];
        foreach (ap_fanout_host_cache($db) as $host => $row) {
            if (!ap_fanout_host_is_suspended_host($host)) {
                continue;
            }
            if (!ap_fanout_probe_due(ap_fanout_host_unix($row['last_probe_at']), $now)) {
                continue;
            }
            $candidates[] = [$row['last_probe_at'] ?? '', $host];
        }
        usort($candidates, static fn(array $a, array $b): int => strcmp((string) $a[0], (string) $b[0]));
        foreach ($candidates as [, $host]) {
            $st = $db->prepare('SELECT probe_inbox_url, probe_activity_json, probe_key_id, probe_priv_path FROM ap_fanout_host_health WHERE host = ?');
            $st->execute([$host]);
            $stored = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($stored)) {
                continue;
            }
            $inbox = (string) ($stored['probe_inbox_url'] ?? '');
            $json = (string) ($stored['probe_activity_json'] ?? '');
            $keyId = (string) ($stored['probe_key_id'] ?? '');
            $priv = (string) ($stored['probe_priv_path'] ?? '');
            if ($inbox === '' || $json === '' || $keyId === '' || $priv === '') {
                continue;
            }
            if (ap_fanout_host_enqueue_probe($db, $host, $inbox, $json, $keyId, $priv, $now, false)) {
                return true;
            }
        }
    } catch (Throwable $e) {
        error_log('[ap-fanout-host] probe ' . $e->getMessage());
    }
    return false;
}

/** @return array{healthy:int,suspended:int} */
function ap_fanout_terminal_failure_counts(?PDO $db = null): array
{
    $healthy = 0;
    $suspended = 0;
    try {
        $db ??= ap_db();
        $suspendedHosts = [];
        if (ap_fanout_host_table_ready($db)) {
            foreach (ap_fanout_host_cache($db) as $host => $_row) {
                if (ap_fanout_host_is_suspended_host($host)) {
                    $suspendedHosts[$host] = true;
                }
            }
        }
        $rows = $db->query("SELECT inbox_url FROM ap_fanout_delivery_queue WHERE status = 'failed'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $host = ap_fanout_host_normalize((string) ($row['inbox_url'] ?? ''));
            if ($host !== '' && isset($suspendedHosts[$host])) {
                $suspended++;
            } else {
                $healthy++;
            }
        }
    } catch (Throwable $e) {
        return ['healthy' => 0, 'suspended' => 0];
    }
    return ['healthy' => $healthy, 'suspended' => $suspended];
}
