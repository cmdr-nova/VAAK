<?php
/**
 * VAAK Bluesky (ATProto) client — Phase A/B.
 *
 * Opt-in per-user session (app password → createSession). Timeline is read-only
 * for now. Profile sync (avatar / banner / bio) pushes VAAK profile → PDS.
 * Does not interact with Bridgy. Feature-flagged via ap_feature_enabled('bluesky_tab').
 */
declare(strict_types=1);

const AP_BSKY_DEFAULT_PDS = 'https://bsky.social';
const AP_BSKY_VAAK_PDS = 'https://bsky.mkultra.monster';
const AP_BSKY_PUBLIC_API = 'https://public.api.bsky.app';
/** DNS/TCP connect cap — keep UI from stalling on dead resolvers. */
const AP_BSKY_CONNECT_TIMEOUT = 2;
/** Default XRPC total timeout (interactive reads/writes). */
const AP_BSKY_DEFAULT_TIMEOUT = 8;
/** Hard wall-clock budget for in-request crosspost (web). CLI retries use a longer budget. */
const AP_BSKY_CROSSPOST_BUDGET_WEB = 8;
const AP_BSKY_CROSSPOST_BUDGET_CLI = 180;

/**
 * Request-scoped Bluesky time budget so stacked XRPC calls cannot lock FPM workers.
 * null = no budget (legacy / unlimited within per-call timeouts).
 */
function ap_bsky_budget_begin(int $seconds): void
{
    $seconds = max(1, $seconds);
    $GLOBALS['ap_bsky_budget_deadline'] = microtime(true) + $seconds;
}

function ap_bsky_budget_clear(): void
{
    unset($GLOBALS['ap_bsky_budget_deadline']);
}

function ap_bsky_budget_remaining(): ?float
{
    if (!isset($GLOBALS['ap_bsky_budget_deadline'])) {
        return null;
    }
    return (float) $GLOBALS['ap_bsky_budget_deadline'] - microtime(true);
}

function ap_bsky_budget_exceeded(): bool
{
    $left = ap_bsky_budget_remaining();
    return $left !== null && $left <= 0.05;
}

/**
 * Cap a requested timeout against the active budget (and floor at 1s when budget remains).
 */
function ap_bsky_effective_timeout(int $timeoutSec): int
{
    $timeoutSec = max(1, $timeoutSec);
    $left = ap_bsky_budget_remaining();
    if ($left === null) {
        return $timeoutSec;
    }
    if ($left <= 0.05) {
        return 0; // signal caller to abort
    }
    return max(1, min($timeoutSec, (int) floor($left)));
}

function ap_bsky_tab_enabled(): bool
{
    return function_exists('ap_feature_enabled')
        ? ap_feature_enabled('bluesky_tab', false)
        : false;
}

function ap_bsky_migrate(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Avoid CREATE/ALTER spam when the runtime DB role has no DDL (prod www-data).
    $tableReady = false;
    try {
        $driver = function_exists('ap_db_driver') ? ap_db_driver() : 'sqlite';
        if ($driver === 'pgsql') {
            $tableReady = (bool) ap_db()->query(
                "SELECT EXISTS (
                    SELECT 1 FROM information_schema.tables
                    WHERE table_schema = current_schema() AND table_name = 'bsky_sessions'
                 )"
            )->fetchColumn();
        } else {
            $tableReady = (bool) ap_db()->query(
                "SELECT 1 FROM sqlite_master WHERE type='table' AND name='bsky_sessions' LIMIT 1"
            )->fetchColumn();
        }
    } catch (Throwable $e) {
        $tableReady = false;
    }

    if (!$tableReady) {
        try {
            ap_db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_sessions (
    owner_user_id BIGINT PRIMARY KEY,
    handle TEXT NOT NULL,
    did TEXT NOT NULL,
    pds_host TEXT NOT NULL DEFAULT 'https://bsky.social',
    access_jwt_enc TEXT NOT NULL,
    refresh_jwt_enc TEXT NOT NULL,
    connected_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL);
            $tableReady = true;
        } catch (Throwable $e) {
            // Runtime role may lack DDL — provision via ops.
            error_log('[ap-bsky] bsky_sessions migrate: ' . $e->getMessage());
            return;
        }
    }

    // Notification poll watermarks (already added on prod; keep best-effort for fresh DBs).
    foreach (['notif_cursor', 'notif_seen_at', 'notif_polled_at'] as $col) {
        try {
            $driver = function_exists('ap_db_driver') ? ap_db_driver() : 'sqlite';
            if ($driver === 'pgsql') {
                $have = (bool) ap_db()->query(
                    "SELECT EXISTS (
                        SELECT 1 FROM information_schema.columns
                        WHERE table_schema = current_schema()
                          AND table_name = 'bsky_sessions'
                          AND column_name = " . ap_db()->quote($col) . '
                     )'
                )->fetchColumn();
                if (!$have) {
                    ap_db()->exec("ALTER TABLE bsky_sessions ADD COLUMN IF NOT EXISTS {$col} TEXT");
                }
            } else {
                $info = ap_db()->query('PRAGMA table_info(bsky_sessions)')->fetchAll();
                $have = false;
                foreach ($info as $row) {
                    if (($row['name'] ?? '') === $col) {
                        $have = true;
                        break;
                    }
                }
                if (!$have) {
                    ap_db()->exec("ALTER TABLE bsky_sessions ADD COLUMN {$col} TEXT");
                }
            }
        } catch (Throwable $e) {
            // Ignore — file watermark fallback still works.
        }
    }
}

/** Reasons we ingest into VAAK notifications (DMs stay out). */
const AP_BSKY_NOTIF_REASONS = ['mention', 'reply', 'quote', 'repost', 'like', 'follow'];

/** events.action_taken for Bluesky follows (not AP followers table). */
const AP_BSKY_FOLLOW_ACTION = 'bsky_follow';

/**
 * Ensure bsky_crossposts map table exists (note_id ↔ at:// URI).
 * Prod www-data may lack DDL — ops can CREATE once; helpers degrade gracefully.
 */
function ap_bsky_crossposts_migrate(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $driver = function_exists('ap_db_driver') ? ap_db_driver() : 'sqlite';
        if ($driver === 'pgsql') {
            $have = (bool) ap_db()->query(
                "SELECT EXISTS (
                    SELECT 1 FROM information_schema.tables
                    WHERE table_schema = current_schema() AND table_name = 'bsky_crossposts'
                 )"
            )->fetchColumn();
            if (!$have) {
                ap_db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_crossposts (
    note_id TEXT PRIMARY KEY,
    bsky_uri TEXT NOT NULL,
    bsky_cid TEXT,
    owner_user_id BIGINT,
    created_at TEXT NOT NULL
)
SQL);
                try {
                    ap_db()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_bsky_crossposts_uri ON bsky_crossposts (bsky_uri)');
                } catch (Throwable $e) {
                    // ignore
                }
            }
        } else {
            ap_db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_crossposts (
    note_id TEXT PRIMARY KEY,
    bsky_uri TEXT NOT NULL UNIQUE,
    bsky_cid TEXT,
    owner_user_id INTEGER,
    created_at TEXT NOT NULL
)
SQL);
        }
    } catch (Throwable $e) {
        // Table may already exist via ops.
    }
}

/**
 * Pending Bluesky mirror retries (DNS/auth blips, or reply waiting on parent mirror).
 * Prod www-data may lack DDL — ops CREATE once; helpers degrade gracefully.
 */
function ap_bsky_crosspost_retries_migrate(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $driver = function_exists('ap_db_driver') ? ap_db_driver() : 'sqlite';
        if ($driver === 'pgsql') {
            $have = (bool) ap_db()->query(
                "SELECT EXISTS (
                    SELECT 1 FROM information_schema.tables
                    WHERE table_schema = current_schema() AND table_name = 'bsky_crosspost_retries'
                 )"
            )->fetchColumn();
            if (!$have) {
                ap_db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_crosspost_retries (
    id BIGSERIAL PRIMARY KEY,
    note_id TEXT NOT NULL UNIQUE,
    owner_user_id BIGINT NOT NULL,
    local_id BIGINT,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 8,
    next_attempt_at TEXT NOT NULL,
    last_error TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL);
                try {
                    ap_db()->exec(
                        'CREATE INDEX IF NOT EXISTS idx_bsky_crosspost_retries_due
                         ON bsky_crosspost_retries (status, next_attempt_at)'
                    );
                } catch (Throwable $e) {
                    // ignore
                }
            }
        } else {
            ap_db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_crosspost_retries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    note_id TEXT NOT NULL UNIQUE,
    owner_user_id INTEGER NOT NULL,
    local_id INTEGER,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 8,
    next_attempt_at TEXT NOT NULL,
    last_error TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL);
            try {
                ap_db()->exec(
                    'CREATE INDEX IF NOT EXISTS idx_bsky_crosspost_retries_due
                     ON bsky_crosspost_retries (status, next_attempt_at)'
                );
            } catch (Throwable $e) {
                // ignore
            }
        }
    } catch (Throwable $e) {
        // Table may already exist via ops.
    }
}

/**
 * Whether a crosspost outcome should be retried in the background.
 */
function ap_bsky_crosspost_should_retry(?array $result): bool
{
    if (!is_array($result)) {
        return true;
    }
    if (!empty($result['ok']) && empty($result['skipped'])) {
        return false;
    }
    $err = trim((string) ($result['error'] ?? ''));
    if ($err === '') {
        return !empty($result['skipped']) ? false : true;
    }
    // Permanent / intentional skips — do not retry.
    $permanent = [
        'Visibility not cross-posted',
        'Bluesky not connected',
        'Nothing to cross-post',
        'No owner',
        'No Bluesky session',
        'Missing DID',
        'Reply parent is not a Bluesky post',
        // A genuinely Fediverse-only parent must remain Fediverse-only. Retrying
        // can otherwise mirror the reply later if an unrelated mapping appears.
        'Reply parent is Fediverse-only',
        // Quote posts follow the quoted post's source too; do not retry an
        // intentional Fediverse-only quote as a plain-text Bluesky post.
        'Quote target is Fediverse-only',
        'no_owner_session_mapping',
    ];
    foreach ($permanent as $p) {
        if (strcasecmp($err, $p) === 0) {
            return false;
        }
    }
    // Soft skip: parent may get mirrored later (or already did).
    if (stripos($err, 'Fediverse-only') !== false) {
        return true;
    }
    // Explicitly deferred (video / budget) — always backfill.
    if (!empty($result['deferred'])) {
        return true;
    }
    // Rate limits — queue until the window resets.
    if (ap_bsky_result_is_rate_limited($result)) {
        return true;
    }
    // Hard failures (timeouts, auth, DNS, createRecord errors).
    if (empty($result['ok']) || !empty($result['error'])) {
        return true;
    }
    return false;
}

/**
 * Backoff seconds by attempt number (1-indexed after increment).
 */
function ap_bsky_crosspost_retry_backoff_sec(int $attempt): int
{
    $table = [60, 120, 300, 600, 1200, 2400, 5400, 10800];
    $idx = max(0, min(count($table) - 1, $attempt - 1));
    return $table[$idx];
}

/**
 * Queue / refresh a retry for a note that failed to mirror.
 *
 * @return array{ok:bool,queued?:bool,error?:string}
 */
function ap_bsky_crosspost_retry_enqueue(
    string $noteId,
    int $ownerUserId,
    ?int $localId = null,
    ?string $lastError = null,
    int $delaySec = 60
): array {
    $noteId = rtrim(trim($noteId), '/');
    if ($noteId === '' || !str_starts_with($noteId, 'https://') || $ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Invalid retry enqueue'];
    }
    // Already mirrored — nothing to do.
    if (ap_bsky_crosspost_by_note_id($noteId) !== null) {
        return ['ok' => true, 'queued' => false];
    }
    ap_bsky_crosspost_retries_migrate();
    $now = gmdate('c');
    $next = gmdate('c', time() + max(15, $delaySec));
    $err = $lastError !== null ? mb_substr(trim($lastError), 0, 500) : null;
    try {
        $st = ap_db()->prepare(
            'INSERT INTO bsky_crosspost_retries
                (note_id, owner_user_id, local_id, attempts, max_attempts, next_attempt_at, last_error, status, created_at, updated_at)
             VALUES (?, ?, ?, 0, 8, ?, ?, \'pending\', ?, ?)
             ON CONFLICT (note_id) DO UPDATE SET
               owner_user_id = excluded.owner_user_id,
               local_id = COALESCE(excluded.local_id, bsky_crosspost_retries.local_id),
               last_error = COALESCE(excluded.last_error, bsky_crosspost_retries.last_error),
               status = CASE
                 WHEN bsky_crosspost_retries.status = \'done\' THEN \'done\'
                 ELSE \'pending\'
               END,
               next_attempt_at = CASE
                 WHEN bsky_crosspost_retries.status = \'done\' THEN bsky_crosspost_retries.next_attempt_at
                 WHEN bsky_crosspost_retries.next_attempt_at <= excluded.next_attempt_at
                   THEN bsky_crosspost_retries.next_attempt_at
                 ELSE excluded.next_attempt_at
               END,
               updated_at = excluded.updated_at'
        );
        $st->execute([$noteId, $ownerUserId, $localId, $next, $err, $now, $now]);
        return ['ok' => true, 'queued' => true];
    } catch (Throwable $e) {
        error_log('[ap-bsky] crosspost_retry_enqueue: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Mark a retry row done (or drop if already mirrored).
 */
function ap_bsky_crosspost_retry_mark_done(string $noteId): void
{
    $noteId = rtrim(trim($noteId), '/');
    if ($noteId === '') {
        return;
    }
    ap_bsky_crosspost_retries_migrate();
    try {
        $st = ap_db()->prepare(
            'UPDATE bsky_crosspost_retries
             SET status = \'done\', updated_at = ?, last_error = NULL
             WHERE note_id = ? OR note_id = ?'
        );
        $st->execute([gmdate('c'), $noteId, $noteId . '/']);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Reconstruct publish inputs and attempt one Bluesky mirror.
 *
 * @return array{ok:bool,skipped?:bool,error?:string,uri?:string,cid?:string,posts?:int}
 */
function ap_bsky_crosspost_retry_one(array $row): array
{
    $noteId = rtrim((string) ($row['note_id'] ?? ''), '/');
    $ownerUserId = (int) ($row['owner_user_id'] ?? 0);
    if ($noteId === '' || $ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Invalid retry row'];
    }
    if (ap_bsky_crosspost_by_note_id($noteId) !== null) {
        ap_bsky_crosspost_retry_mark_done($noteId);
        return ['ok' => true, 'skipped' => true, 'error' => 'Already mirrored'];
    }

    $status = null;
    try {
        $st = ap_db()->prepare(
            'SELECT local_id, note_id, visibility, spoiler_text, content_text, in_reply_to_local_id
             FROM masto_statuses
             WHERE note_id = ? OR note_id = ?
             LIMIT 1'
        );
        $st->execute([$noteId, $noteId . '/']);
        $status = $st->fetch();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Status lookup failed: ' . $e->getMessage()];
    }
    if (!is_array($status)) {
        return ['ok' => false, 'skipped' => true, 'error' => 'Status missing'];
    }

    $localId = (int) ($status['local_id'] ?? 0);
    $mediaIds = [];
    if ($localId > 0 && function_exists('ap_media_by_local_ids')) {
        try {
            $m = ap_db()->prepare(
                'SELECT local_id FROM masto_media WHERE status_local_id = ? ORDER BY local_id ASC'
            );
            $m->execute([$localId]);
            foreach ($m->fetchAll() as $mr) {
                if (is_array($mr)) {
                    $mediaIds[] = (int) ($mr['local_id'] ?? 0);
                }
            }
            $mediaIds = array_values(array_filter($mediaIds, static fn($i) => $i > 0));
        } catch (Throwable $e) {
            $mediaIds = [];
        }
    }

    $inReplyTo = null;
    $replyLocal = (int) ($status['in_reply_to_local_id'] ?? 0);
    if ($replyLocal > 0) {
        try {
            $p = ap_db()->prepare('SELECT note_id FROM masto_statuses WHERE local_id = ? LIMIT 1');
            $p->execute([$replyLocal]);
            $parentNote = $p->fetchColumn();
            if (is_string($parentNote) && str_starts_with($parentNote, 'https://')) {
                $inReplyTo = $parentNote;
            }
        } catch (Throwable $e) {
            $inReplyTo = null;
        }
    }

    // Quote object from stored Note when present.
    $quoteObjectId = null;
    try {
        $ost = ap_db()->prepare(
            'SELECT raw_create_json FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1'
        );
        $ost->execute([$noteId, $noteId . '/']);
        $orow = $ost->fetch();
        if (is_array($orow)) {
            $create = json_decode((string) ($orow['raw_create_json'] ?? ''), true);
            $obj = is_array($create['object'] ?? null) ? $create['object'] : null;
            if (is_array($obj)) {
                $q = $obj['quote'] ?? null;
                if (is_string($q) && str_starts_with($q, 'https://')) {
                    $quoteObjectId = rtrim($q, '/');
                }
            }
        }
    } catch (Throwable $e) {
        $quoteObjectId = null;
    }

    $res = ap_bsky_crosspost_status(
        $ownerUserId,
        (string) ($status['content_text'] ?? ''),
        (string) ($status['visibility'] ?? 'public'),
        $mediaIds,
        (string) ($status['spoiler_text'] ?? ''),
        $inReplyTo,
        $quoteObjectId,
        $noteId
    );

    if (!empty($res['ok']) && empty($res['skipped']) && !empty($res['uri']) && is_string($res['uri'])) {
        if (function_exists('ap_note_attach_bsky_proxy')) {
            try {
                $ost = ap_db()->prepare(
                    'SELECT raw_create_json FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1'
                );
                $ost->execute([$noteId, $noteId . '/']);
                $orow = $ost->fetch();
                if (is_array($orow)) {
                    $create = json_decode((string) ($orow['raw_create_json'] ?? ''), true);
                    if (is_array($create) && is_array($create['object'] ?? null)) {
                        $create['object'] = ap_note_attach_bsky_proxy(
                            $create['object'],
                            (string) $res['uri'],
                            isset($res['cid']) ? (string) $res['cid'] : null
                        );
                        ap_db()->prepare(
                            'UPDATE outbox_notes SET raw_create_json = ? WHERE id = ? OR id = ?'
                        )->execute([
                            json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                            $noteId,
                            $noteId . '/',
                        ]);
                    }
                }
            } catch (Throwable $e) {
                error_log('[ap-bsky] retry stamp FEP: ' . $e->getMessage());
            }
        }
        ap_bsky_crosspost_retry_mark_done($noteId);
    }

    return $res;
}

/**
 * Process due Bluesky crosspost retries.
 *
 * @return array{claimed:int,ok:int,failed:int,skipped:int,dead:int}
 */
function ap_bsky_crosspost_retry_worker_run(int $limit = 5): array
{
    $stats = ['claimed' => 0, 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'dead' => 0];
    ap_bsky_crosspost_retries_migrate();
    $limit = max(1, min(20, $limit));
    $now = gmdate('c');
    $rows = [];
    try {
        $st = ap_db()->prepare(
            "SELECT * FROM bsky_crosspost_retries
             WHERE status = 'pending' AND next_attempt_at <= ?
             ORDER BY next_attempt_at ASC
             LIMIT ?"
        );
        $st->execute([$now, $limit]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        error_log('[ap-bsky] retry_worker list: ' . $e->getMessage());
        return $stats;
    }
    if (!is_array($rows) || $rows === []) {
        return $stats;
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $stats['claimed']++;
        $noteId = rtrim((string) ($row['note_id'] ?? ''), '/');
        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $maxAttempts = max(1, (int) ($row['max_attempts'] ?? 8));
        $res = ap_bsky_crosspost_retry_one($row);

        if (!empty($res['ok']) && empty($res['skipped'])) {
            $stats['ok']++;
            continue;
        }
        if (!empty($res['ok']) && !empty($res['skipped']) && !ap_bsky_crosspost_should_retry($res)) {
            // Permanent skip or already mirrored.
            ap_bsky_crosspost_retry_mark_done($noteId);
            $stats['skipped']++;
            continue;
        }

        $err = (string) ($res['error'] ?? 'retry failed');
        $ownerId = (int) ($row['owner_user_id'] ?? 0);

        // Rate limits: wait for the window reset and do NOT burn attempt budget.
        if (ap_bsky_result_is_rate_limited($res)) {
            $delay = ap_bsky_result_retry_after_sec($res, 300);
            $next = gmdate('c', time() + $delay);
            try {
                $u = ap_db()->prepare(
                    "UPDATE bsky_crosspost_retries
                     SET next_attempt_at = ?, last_error = ?, status = 'pending', updated_at = ?
                     WHERE note_id = ? OR note_id = ?"
                );
                $u->execute([
                    $next,
                    mb_substr('Rate limit exceeded; retry in ' . $delay . 's', 0, 500),
                    gmdate('c'),
                    $noteId,
                    $noteId . '/',
                ]);
            } catch (Throwable $e) {
                // ignore
            }
            if ($ownerId > 0) {
                ap_bsky_crosspost_retry_pause_owner(
                    $ownerId,
                    $delay,
                    'Rate limit exceeded; paused owner queue'
                );
            }
            $stats['failed']++;
            continue;
        }

        if ($attempts >= $maxAttempts || !ap_bsky_crosspost_should_retry($res)) {
            try {
                $u = ap_db()->prepare(
                    "UPDATE bsky_crosspost_retries
                     SET status = 'dead', attempts = ?, last_error = ?, updated_at = ?
                     WHERE note_id = ? OR note_id = ?"
                );
                $u->execute([$attempts, mb_substr($err, 0, 500), gmdate('c'), $noteId, $noteId . '/']);
            } catch (Throwable $e) {
                // ignore
            }
            $stats['dead']++;
            continue;
        }

        $next = gmdate('c', time() + ap_bsky_crosspost_retry_backoff_sec($attempts));
        try {
            $u = ap_db()->prepare(
                "UPDATE bsky_crosspost_retries
                 SET attempts = ?, next_attempt_at = ?, last_error = ?, status = 'pending', updated_at = ?
                 WHERE note_id = ? OR note_id = ?"
            );
            $u->execute([$attempts, $next, mb_substr($err, 0, 500), gmdate('c'), $noteId, $noteId . '/']);
        } catch (Throwable $e) {
            // ignore
        }
        $stats['failed']++;
    }

    return $stats;
}

/**
 * @return array{note_id:string,bsky_uri:string,bsky_cid:?string,owner_user_id:?int}|null
 */
function ap_bsky_crosspost_save(string $noteId, string $bskyUri, ?string $bskyCid = null, ?int $ownerUserId = null): ?array
{
    $noteId = rtrim(trim($noteId), '/');
    $bskyUri = trim($bskyUri);
    if ($noteId === '' || !str_starts_with($bskyUri, 'at://')) {
        return null;
    }
    ap_bsky_crossposts_migrate();
    $cid = is_string($bskyCid) && $bskyCid !== '' ? $bskyCid : null;
    // Note.blueskyUri stores the *root* segment; bsky_crossposts should keep the *tip*
    // so later self-replies nest under the last chunk. Never clobber a tip with its root.
    $existing = ap_bsky_crosspost_by_note_id($noteId);
    if (is_array($existing)) {
        $prevUri = trim((string) ($existing['bsky_uri'] ?? ''));
        if ($prevUri !== '' && $prevUri !== $bskyUri && ap_bsky_uri_is_thread_root_of($bskyUri, $prevUri)) {
            if (($existing['bsky_cid'] === null || $existing['bsky_cid'] === '') && $cid !== null) {
                try {
                    $ust = ap_db()->prepare(
                        'UPDATE bsky_crossposts SET bsky_cid = COALESCE(bsky_cid, ?) WHERE note_id = ? OR note_id = ?'
                    );
                    $ust->execute([$cid, $noteId, $noteId . '/']);
                } catch (Throwable $e) {
                    // ignore
                }
            }
            return [
                'note_id' => $noteId,
                'bsky_uri' => $prevUri,
                'bsky_cid' => isset($existing['bsky_cid']) && $existing['bsky_cid'] !== ''
                    ? (string) $existing['bsky_cid']
                    : $cid,
                'owner_user_id' => $ownerUserId ?? (isset($existing['owner_user_id']) ? (int) $existing['owner_user_id'] : null),
            ];
        }
    }
    $now = gmdate('c');
    try {
        $st = ap_db()->prepare(
            'INSERT INTO bsky_crossposts (note_id, bsky_uri, bsky_cid, owner_user_id, created_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (note_id) DO UPDATE SET
               bsky_uri = excluded.bsky_uri,
               bsky_cid = COALESCE(excluded.bsky_cid, bsky_crossposts.bsky_cid),
               owner_user_id = COALESCE(excluded.owner_user_id, bsky_crossposts.owner_user_id)'
        );
        $st->execute([$noteId, $bskyUri, $cid, $ownerUserId, $now]);
        return [
            'note_id' => $noteId,
            'bsky_uri' => $bskyUri,
            'bsky_cid' => $cid,
            'owner_user_id' => $ownerUserId,
        ];
    } catch (Throwable $e) {
        error_log('[ap-bsky] crosspost_save: ' . $e->getMessage());
        return null;
    }
}

/**
 * True when $maybeRoot is the thread root (or ancestor) of $tipUri.
 */
function ap_bsky_uri_is_thread_root_of(string $maybeRoot, string $tipUri): bool
{
    $maybeRoot = trim($maybeRoot);
    $tipUri = trim($tipUri);
    if ($maybeRoot === '' || $tipUri === '' || $maybeRoot === $tipUri) {
        return false;
    }
    if (!str_starts_with($maybeRoot, 'at://') || !str_starts_with($tipUri, 'at://')) {
        return false;
    }
    try {
        $st = ap_db()->prepare(
            'SELECT reply_parent, reply_root FROM bsky_posts WHERE bsky_uri = ? LIMIT 1'
        );
        $st->execute([$tipUri]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        $parent = trim((string) ($row['reply_parent'] ?? ''));
        $root = trim((string) ($row['reply_root'] ?? ''));
        return $root === $maybeRoot || $parent === $maybeRoot;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array{note_id:string,bsky_uri:string,bsky_cid:?string,owner_user_id:?int}|null
 */
function ap_bsky_crosspost_by_note_id(string $noteId): ?array
{
    $noteId = rtrim(trim($noteId), '/');
    if ($noteId === '') {
        return null;
    }
    ap_bsky_crossposts_migrate();
    try {
        $st = ap_db()->prepare('SELECT * FROM bsky_crossposts WHERE note_id = ? OR note_id = ? LIMIT 1');
        $st->execute([$noteId, $noteId . '/']);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return array{note_id:string,bsky_uri:string,bsky_cid:?string,owner_user_id:?int}|null
 */
function ap_bsky_crosspost_by_uri(string $bskyUri): ?array
{
    $bskyUri = trim($bskyUri);
    // Accept bsky.app HTTPS and normalize to at:// when possible via map table scan of https… no —
    // callers should normalize first. Also accept our stored at://.
    if (str_starts_with($bskyUri, 'https://bsky.app/')) {
        $resolved = ap_bsky_at_uri_from_https($bskyUri);
        if ($resolved !== null) {
            $bskyUri = $resolved;
        }
    }
    if (!str_starts_with($bskyUri, 'at://')) {
        return null;
    }
    ap_bsky_crossposts_migrate();
    try {
        $st = ap_db()->prepare('SELECT * FROM bsky_crossposts WHERE bsky_uri = ? LIMIT 1');
        $st->execute([$bskyUri]);
        $row = $st->fetch();
        if (is_array($row)) {
            return $row;
        }
    } catch (Throwable $e) {
        // fall through to raw_create_json fallback
    }
    // Fallback: scan recent outbox raw JSON (unindexed — last resort).
    try {
        $st = ap_db()->prepare(
            "SELECT id, raw_create_json FROM outbox_notes
             WHERE raw_create_json LIKE ?
             ORDER BY published DESC
             LIMIT 5"
        );
        $st->execute(['%' . $bskyUri . '%']);
        while ($row = $st->fetch()) {
            $raw = (string) ($row['raw_create_json'] ?? '');
            $j = json_decode($raw, true);
            $obj = is_array($j) ? ($j['object'] ?? $j) : null;
            if (!is_array($obj)) {
                continue;
            }
            $uri = (string) ($obj['blueskyUri'] ?? '');
            if ($uri === $bskyUri) {
                $cid = isset($obj['blueskyCid']) ? (string) $obj['blueskyCid'] : null;
                ap_bsky_crosspost_save((string) $row['id'], $uri, $cid);
                return [
                    'note_id' => (string) $row['id'],
                    'bsky_uri' => $uri,
                    'bsky_cid' => $cid,
                    'owner_user_id' => null,
                ];
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return null;
}

/**
 * Convert https://bsky.app/profile/{actor}/post/{rkey} → at://did…/app.bsky.feed.post/{rkey}.
 * Resolves handles via identity.resolveHandle when needed.
 */
function ap_bsky_at_uri_from_https(string $url, int $ownerUserId = 0): ?string
{
    $url = trim($url);
    if (!preg_match('~^https://bsky\.app/profile/([^/]+)/post/([^/?#]+)~i', $url, $m)) {
        return null;
    }
    $actor = rawurldecode($m[1]);
    $rkey = rawurldecode($m[2]);
    if (str_starts_with($actor, 'did:')) {
        return 'at://' . $actor . '/app.bsky.feed.post/' . $rkey;
    }
    // Handle → look up our crossposts by rkey first (cheap, no network).
    ap_bsky_crossposts_migrate();
    try {
        $st = ap_db()->prepare('SELECT * FROM bsky_crossposts WHERE bsky_uri LIKE ? LIMIT 1');
        $st->execute(['%/app.bsky.feed.post/' . $rkey]);
        $row = $st->fetch();
        if (is_array($row) && !empty($row['bsky_uri'])) {
            return (string) $row['bsky_uri'];
        }
    } catch (Throwable $e) {
        // ignore
    }
    // Resolve handle → DID (uses existing ap_bsky_resolve_handle_did later in this file).
    $did = ap_bsky_resolve_handle_did($actor, $ownerUserId);
    if ($did !== null && str_starts_with($did, 'did:')) {
        return 'at://' . $did . '/app.bsky.feed.post/' . $rkey;
    }
    return null;
}

/**
 * Find the local VAAK note id for a Bluesky post AT-URI (map table or record.fediverseId).
 */
function ap_bsky_local_note_id_for_at_uri(string $atUri, int $ownerUserId = 0, bool $allowFetch = true): ?string
{
    $atUri = trim($atUri);
    if (!str_starts_with($atUri, 'at://')) {
        if (str_starts_with($atUri, 'https://bsky.app/')) {
            $converted = ap_bsky_at_uri_from_https($atUri);
            if ($converted === null) {
                return null;
            }
            $atUri = $converted;
        } else {
            return null;
        }
    }
    $map = ap_bsky_crosspost_by_uri($atUri);
    if (is_array($map) && !empty($map['note_id'])) {
        return (string) $map['note_id'];
    }
    $link = function_exists('ap_bsky_post_link_by_uri') ? ap_bsky_post_link_by_uri($atUri) : null;
    if (is_array($link)) {
        $fedi = rtrim((string) ($link['fediverse_id'] ?? $link['ap_object_id'] ?? ''), '/');
        if ($fedi !== '' && str_contains($fedi, '/notes/')) {
            return $fedi;
        }
    }
    if (!$allowFetch) {
        return null;
    }
    // Read fediverseId from the Bluesky record (Wafrn-compatible dual-publish marker).
    if ($ownerUserId < 1) {
        // Try default owner from sessions
        try {
            $ownerUserId = (int) (ap_db()->query('SELECT owner_user_id FROM bsky_sessions LIMIT 1')->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $ownerUserId = 0;
        }
    }
    if ($ownerUserId < 1 || !preg_match('~^at://([^/]+)/(app\.bsky\.feed\.post)/([^/]+)$~', $atUri, $m)) {
        return null;
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return null;
    }
    $row = ap_bsky_session_row($ownerUserId);
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $got = null;
    foreach (ap_bsky_feed_hosts($pds) as $host) {
        $got = ap_bsky_xrpc($host, 'com.atproto.repo.getRecord', 'GET', [
            'repo' => $m[1],
            'collection' => $m[2],
            'rkey' => $m[3],
        ], null, (string) $tok['access'], 12);
        if (!empty($got['ok'])) {
            break;
        }
    }
    if (empty($got['ok'])) {
        return null;
    }
    $value = is_array($got['json']['value'] ?? null) ? $got['json']['value'] : [];
    $fedi = trim((string) ($value['fediverseId'] ?? ''));
    $cid = (string) ($got['json']['cid'] ?? '');
    if ($fedi !== '' && str_starts_with($fedi, 'https://mkultra.monster/users/')) {
        ap_bsky_crosspost_save($fedi, $atUri, $cid !== '' ? $cid : null, $ownerUserId);
        return $fedi;
    }
    return null;
}

/**
 * Resolve a strongRef {uri,cid} for a local note URL, bsky.app URL, or at:// URI.
 *
 * @return array{uri:string,cid:string}|null
 */
function ap_bsky_resolve_strong_ref(string $ref, int $ownerUserId = 0): ?array
{
    $ref = rtrim(trim($ref), '/');
    if ($ref === '') {
        return null;
    }
    $uri = null;
    $cid = null;
    if (str_starts_with($ref, 'at://')) {
        $uri = $ref;
        $map = ap_bsky_crosspost_by_uri($uri);
        if (is_array($map) && !empty($map['bsky_cid'])) {
            $cid = (string) $map['bsky_cid'];
        }
    } elseif (str_starts_with($ref, 'https://bsky.app/')) {
        $uri = ap_bsky_at_uri_from_https($ref, $ownerUserId);
        if ($uri !== null) {
            $map = ap_bsky_crosspost_by_uri($uri);
            if (is_array($map) && !empty($map['bsky_cid'])) {
                $cid = (string) $map['bsky_cid'];
            }
        }
    } elseif (str_starts_with($ref, 'https://mkultra.monster/users/')) {
        $map = ap_bsky_crosspost_by_note_id($ref);
        if (is_array($map)) {
            $uri = (string) $map['bsky_uri'];
            $cid = isset($map['bsky_cid']) ? (string) $map['bsky_cid'] : null;
        } else {
            // Pull from outbox raw JSON
            try {
                $st = ap_db()->prepare('SELECT raw_create_json FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
                $st->execute([$ref, $ref . '/']);
                $raw = (string) ($st->fetchColumn() ?: '');
                $j = json_decode($raw, true);
                $obj = is_array($j) ? ($j['object'] ?? $j) : null;
                if (is_array($obj) && !empty($obj['blueskyUri'])) {
                    $uri = (string) $obj['blueskyUri'];
                    $cid = isset($obj['blueskyCid']) ? (string) $obj['blueskyCid'] : null;
                    ap_bsky_crosspost_save($ref, $uri, $cid, $ownerUserId > 0 ? $ownerUserId : null);
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
    } elseif (str_starts_with($ref, 'https://')) {
        // Dual-publish remotes (Wafrn / Bridgy / etc.): fediverse Note URL → AT URI map
        if (function_exists('ap_bsky_post_link_by_fedi')) {
            $link = ap_bsky_post_link_by_fedi($ref);
            if (is_array($link) && !empty($link['bsky_uri'])) {
                $uri = (string) $link['bsky_uri'];
                $cid = isset($link['bsky_cid']) ? (string) $link['bsky_cid'] : null;
            }
        }
    }
    if ($uri === null || !str_starts_with($uri, 'at://')) {
        return null;
    }
    if ($cid !== null && $cid !== '') {
        return ['uri' => $uri, 'cid' => $cid];
    }
    $cid = ap_bsky_cid_for_uri($uri, $ownerUserId);
    if ($cid === null || $cid === '') {
        return null;
    }
    return ['uri' => $uri, 'cid' => $cid];
}

/**
 * CID for an at:// post. Cache first, then public AppView getPosts.
 * Do not use com.atproto.repo.getRecord on the viewer's PDS — that only
 * works for the viewer's own repo and is why quote-boost likes 503'd.
 */
function ap_bsky_cid_for_uri(string $uri, int $ownerUserId = 0): ?string
{
    $uri = trim($uri);
    if (!str_starts_with($uri, 'at://')) {
        return null;
    }
    $link = ap_bsky_post_link_by_uri($uri);
    if (is_array($link) && trim((string) ($link['bsky_cid'] ?? '')) !== '') {
        return (string) $link['bsky_cid'];
    }
    $item = ap_bsky_post_item_by_uri($uri);
    if (is_array($item) && is_array($item['post'] ?? null)) {
        $cached = trim((string) ($item['post']['cid'] ?? ''));
        if ($cached !== '') {
            ap_bsky_post_link_upsert($uri, $cached);
            return $cached;
        }
    }
    $map = ap_bsky_crosspost_by_uri($uri);
    if (is_array($map) && trim((string) ($map['bsky_cid'] ?? '')) !== '') {
        return (string) $map['bsky_cid'];
    }
    $got = ap_bsky_xrpc(AP_BSKY_PUBLIC_API, 'app.bsky.feed.getPosts', 'GET', [
        'uris' => $uri,
    ], null, null, 8);
    $cid = '';
    if (!empty($got['ok']) && is_array($got['json']['posts'][0] ?? null)) {
        $cid = trim((string) ($got['json']['posts'][0]['cid'] ?? ''));
    }
    if ($cid === '' && $ownerUserId > 0 && preg_match('~^at://([^/]+)/([^/]+)/([^/]+)$~', $uri, $m)) {
        $tok = ap_bsky_access_token($ownerUserId, false);
        if (empty($tok['ok'])) {
            $tok = ap_bsky_access_token($ownerUserId, true);
        }
        $bearer = !empty($tok['ok']) ? (string) $tok['access'] : null;
        $row = ap_bsky_session_row($ownerUserId);
        $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
        foreach (array_unique(array_merge([AP_BSKY_PUBLIC_API], ap_bsky_feed_hosts($pds))) as $host) {
            $rec = ap_bsky_xrpc($host, 'com.atproto.repo.getRecord', 'GET', [
                'repo' => $m[1],
                'collection' => $m[2],
                'rkey' => $m[3],
            ], null, $bearer, 6);
            $cid = trim((string) ($rec['json']['cid'] ?? ''));
            if ($cid !== '') {
                break;
            }
        }
    }
    if ($cid === '') {
        return null;
    }
    ap_bsky_post_link_upsert($uri, $cid);
    return $cid;
}

/**
 * When replying to our own dual-published / split thread, parent at the tip
 * (latest self-segment under the same root) so Bluesky nests the continuation
 * instead of forking a sibling off the root. Leaves other people's posts alone.
 *
 * @param array{uri:string,cid:string} $parent
 * @return array{uri:string,cid:string}
 */
function ap_bsky_self_thread_tip_ref(array $parent, int $ownerUserId): array
{
    $parentUri = trim((string) ($parent['uri'] ?? ''));
    $parentCid = trim((string) ($parent['cid'] ?? ''));
    if ($ownerUserId < 1 || !preg_match('~^at://([^/]+)/app\.bsky\.feed\.post/([^/\s]+)$~', $parentUri, $m)) {
        return $parent;
    }
    $parentDid = $m[1];
    $row = ap_bsky_session_row($ownerUserId);
    $myDid = is_array($row) ? trim((string) ($row['did'] ?? '')) : '';
    if ($myDid === '' || $parentDid !== $myDid) {
        return $parent;
    }

    // Resolve the thread root for this parent (itself if it's the root).
    $rootUri = $parentUri;
    try {
        $st = ap_db()->prepare(
            'SELECT reply_root FROM bsky_posts WHERE bsky_uri = ? LIMIT 1'
        );
        $st->execute([$parentUri]);
        $rr = trim((string) ($st->fetchColumn() ?: ''));
        if ($rr !== '' && str_starts_with($rr, 'at://')) {
            $rootUri = $rr;
        }
    } catch (Throwable $e) {
        // keep parentUri as root guess
    }

    // Newest own post under this root (includes the root itself).
    $tipUri = $parentUri;
    $tipCid = $parentCid;
    try {
        $st = ap_db()->prepare(
            'SELECT bsky_uri, bsky_cid, published_at, indexed_at
             FROM bsky_posts
             WHERE author_did = ?
               AND (bsky_uri = ? OR reply_root = ?)
             ORDER BY COALESCE(published_at, indexed_at) DESC NULLS LAST, updated_at DESC
             LIMIT 1'
        );
        $st->execute([$myDid, $rootUri, $rootUri]);
        $tip = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($tip) && !empty($tip['bsky_uri'])) {
            $tipUri = (string) $tip['bsky_uri'];
            if (!empty($tip['bsky_cid'])) {
                $tipCid = (string) $tip['bsky_cid'];
            }
        }
    } catch (Throwable $e) {
        // fall through
    }

    if ($tipUri === $parentUri) {
        return $parent;
    }
    if ($tipCid === '') {
        $resolved = ap_bsky_resolve_strong_ref($tipUri, $ownerUserId);
        if (is_array($resolved) && !empty($resolved['cid'])) {
            return $resolved;
        }
        // Keep parent if we can't get a cid for the tip.
        return $parent;
    }
    return ['uri' => $tipUri, 'cid' => $tipCid];
}

/**
 * Build Bluesky reply {root,parent} from a parent strongRef.
 * Fetches the parent record so nested replies keep the real thread root
 * (previously we incorrectly set root=parent for every reply).
 *
 * @param array{uri:string,cid:string} $parent
 * @return array{root:array{uri:string,cid:string},parent:array{uri:string,cid:string}}
 */
function ap_bsky_reply_ref_for_parent(array $parent, int $ownerUserId): array
{
    $parentUri = (string) ($parent['uri'] ?? '');
    $parentCid = (string) ($parent['cid'] ?? '');
    $parentRef = ['uri' => $parentUri, 'cid' => $parentCid];
    if ($parentUri === '' || $parentCid === '' || $ownerUserId < 1) {
        return ['root' => $parentRef, 'parent' => $parentRef];
    }
    if (!preg_match('~^at://([^/]+)/([^/]+)/([^/]+)$~', $parentUri, $m)) {
        return ['root' => $parentRef, 'parent' => $parentRef];
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['root' => $parentRef, 'parent' => $parentRef];
    }
    $row = ap_bsky_session_row($ownerUserId);
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $got = null;
    foreach (array_merge([$pds], ap_bsky_feed_hosts($pds)) as $host) {
        $got = ap_bsky_xrpc($host, 'com.atproto.repo.getRecord', 'GET', [
            'repo' => $m[1],
            'collection' => $m[2],
            'rkey' => $m[3],
        ], null, (string) $tok['access'], 12);
        if (!empty($got['ok'])) {
            break;
        }
    }
    $value = is_array($got['json']['value'] ?? null) ? $got['json']['value'] : null;
    $reply = is_array($value['reply'] ?? null) ? $value['reply'] : null;
    $root = is_array($reply['root'] ?? null) ? $reply['root'] : null;
    $rootUri = is_array($root) ? (string) ($root['uri'] ?? '') : '';
    $rootCid = is_array($root) ? (string) ($root['cid'] ?? '') : '';
    if ($rootUri !== '' && $rootCid !== '' && str_starts_with($rootUri, 'at://')) {
        return [
            'root' => ['uri' => $rootUri, 'cid' => $rootCid],
            'parent' => $parentRef,
        ];
    }
    // Parent is a thread root (or getRecord failed) — root == parent.
    return ['root' => $parentRef, 'parent' => $parentRef];
}

/**
 * @return array{owner_user_id:int,handle:string,did:string,pds_host:string,access_jwt_enc:string,refresh_jwt_enc:string,connected_at:string,updated_at:string}|null
 */
function ap_bsky_session_row(int $ownerUserId): ?array
{
    if ($ownerUserId < 1) {
        return null;
    }
    try {
        $st = ap_db()->prepare('SELECT * FROM bsky_sessions WHERE owner_user_id = ? LIMIT 1');
        $st->execute([$ownerUserId]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ap_bsky_disconnect(int $ownerUserId): bool
{
    if ($ownerUserId < 1) {
        return false;
    }
    try {
        $st = ap_db()->prepare('DELETE FROM bsky_sessions WHERE owner_user_id = ?');
        $st->execute([$ownerUserId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array{ok:bool,error?:string,status?:int,json?:mixed,body?:string}
 */
/** Authenticated XRPC using this account's PDS/AppView proxy hosts. */
function ap_bsky_account_xrpc(int $ownerUserId, string $nsid, string $method = 'GET', ?array $query = null, ?array $body = null): array
{
    $session = ap_bsky_session_row($ownerUserId);
    if ($session === null) {
        return ['ok' => false, 'error' => 'Bluesky not connected'];
    }
    $token = ap_bsky_access_token($ownerUserId, false);
    if (empty($token['ok'])) {
        $token = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($token['ok'])) {
        return ['ok' => false, 'error' => (string) ($token['error'] ?? 'Bluesky session expired')];
    }
    $pds = rtrim((string) ($session['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $last = ['ok' => false, 'error' => $nsid . ' failed'];
    foreach (ap_bsky_feed_hosts($pds) as $host) {
        $last = ap_bsky_xrpc($host, $nsid, $method, $query, $body, (string) $token['access'], 15);
        if (($last['status'] ?? 0) === 401) {
            $token = ap_bsky_access_token($ownerUserId, true);
            if (empty($token['ok'])) {
                return ['ok' => false, 'error' => (string) ($token['error'] ?? 'Bluesky session expired')];
            }
            $last = ap_bsky_xrpc($host, $nsid, $method, $query, $body, (string) $token['access'], 15);
        }
        if (!empty($last['ok'])) {
            return $last;
        }
    }
    return $last;
}

/**
 * @return array{ok:bool,error?:string,feed?:list<array>,cursor?:?string}
 */

function ap_bsky_xrpc(
    string $base,
    string $nsid,
    string $method = 'GET',
    ?array $query = null,
    ?array $jsonBody = null,
    ?string $bearer = null,
    int $timeoutSec = AP_BSKY_DEFAULT_TIMEOUT
): array {
    if (ap_bsky_budget_exceeded()) {
        return ['ok' => false, 'error' => 'Bluesky budget exceeded', 'status' => 0];
    }
    $timeoutSec = ap_bsky_effective_timeout($timeoutSec);
    if ($timeoutSec < 1) {
        return ['ok' => false, 'error' => 'Bluesky budget exceeded', 'status' => 0];
    }
    $base = rtrim($base, '/');
    if ($base === '' || !str_starts_with($base, 'https://')) {
        return ['ok' => false, 'error' => 'Invalid API host'];
    }
    $nsid = trim($nsid);
    if ($nsid === '' || !preg_match('/^[a-z0-9.-]+$/i', $nsid)) {
        return ['ok' => false, 'error' => 'Invalid XRPC method'];
    }
    $url = $base . '/xrpc/' . $nsid;
    if ($method === 'GET' && is_array($query) && $query !== []) {
        $url .= '?' . http_build_query($query);
    }
    $headers = [
        'Accept: application/json',
        'User-Agent: VAAK-Bluesky/1.0 (+https://mkultra.monster/vaak)',
    ];
    if ($bearer !== null && $bearer !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }
    $payload = null;
    if ($method !== 'GET' && is_array($jsonBody)) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            return ['ok' => false, 'error' => 'JSON encode failed'];
        }
        $headers[] = 'Content-Type: application/json';
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }
    $connectTimeout = min(AP_BSKY_CONNECT_TIMEOUT, $timeoutSec);
    $respHeaders = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
            $respHeaders .= $line;
            return strlen($line);
        },
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $rate = ap_bsky_parse_rate_limit_headers($respHeaders);
    if ($errno !== 0 || !is_string($body)) {
        return ['ok' => false, 'error' => $err !== '' ? $err : 'HTTP request failed', 'status' => $status] + $rate;
    }
    $json = json_decode($body, true);
    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'status' => $status, 'json' => $json, 'body' => $body] + $rate;
    }
    $msg = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : '';
    $errCode = is_array($json) ? (string) ($json['error'] ?? '') : '';
    if ($msg === '') {
        $msg = 'HTTP ' . $status;
    }
    $out = ['ok' => false, 'error' => $msg, 'status' => $status, 'json' => $json, 'body' => $body] + $rate;
    if ($status === 429 || strcasecmp($errCode, 'RateLimitExceeded') === 0
        || stripos($msg, 'rate limit') !== false || stripos($msg, 'too many requests') !== false) {
        $out['rate_limited'] = true;
        $out['error'] = 'Rate limit exceeded';
        if (empty($out['retry_after_sec'])) {
            $out['retry_after_sec'] = ap_bsky_rate_limit_fallback_delay_sec($rate);
        }
    }
    return $out;
}

/**
 * Parse ATProto / Bluesky rate-limit response headers.
 *
 * @return array{retry_after_sec?:int,reset_at?:int,ratelimit_remaining?:int,ratelimit_limit?:int}
 */
function ap_bsky_parse_rate_limit_headers(string $headerBlob): array
{
    $out = [];
    if ($headerBlob === '') {
        return $out;
    }
    $retryAfter = null;
    $resetAt = null;
    $remaining = null;
    $limit = null;
    foreach (preg_split("/\r\n|\n|\r/", $headerBlob) ?: [] as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = array_map('trim', explode(':', $line, 2));
        $lname = strtolower($name);
        if ($lname === 'retry-after') {
            if (ctype_digit($value)) {
                $retryAfter = (int) $value;
            } else {
                $ts = strtotime($value);
                if ($ts !== false) {
                    $retryAfter = max(0, $ts - time());
                }
            }
        } elseif ($lname === 'ratelimit-reset') {
            // Bluesky usually sends a unix timestamp; some stacks send delta seconds.
            if (ctype_digit($value)) {
                $n = (int) $value;
                $resetAt = ($n > 1_000_000_000) ? $n : (time() + $n);
            }
        } elseif ($lname === 'ratelimit-remaining' && ctype_digit($value)) {
            $remaining = (int) $value;
        } elseif ($lname === 'ratelimit-limit' && ctype_digit($value)) {
            $limit = (int) $value;
        }
    }
    if ($resetAt !== null) {
        $out['reset_at'] = $resetAt;
        $out['retry_after_sec'] = max(1, $resetAt - time());
    }
    if ($retryAfter !== null) {
        $out['retry_after_sec'] = max(1, (int) $retryAfter);
    }
    if ($remaining !== null) {
        $out['ratelimit_remaining'] = $remaining;
    }
    if ($limit !== null) {
        $out['ratelimit_limit'] = $limit;
    }
    return $out;
}

/**
 * When headers are missing, pick a conservative wait (hourly write window).
 */
function ap_bsky_rate_limit_fallback_delay_sec(array $rate = []): int
{
    if (!empty($rate['retry_after_sec'])) {
        return ap_bsky_clamp_retry_delay((int) $rate['retry_after_sec']);
    }
    if (!empty($rate['reset_at'])) {
        return ap_bsky_clamp_retry_delay((int) $rate['reset_at'] - time());
    }
    return 300; // 5 minutes
}

/** Clamp delay: at least 60s, at most 6 hours. */
function ap_bsky_clamp_retry_delay(int $sec): int
{
    return max(60, min(6 * 3600, $sec));
}

function ap_bsky_result_is_rate_limited(?array $result): bool
{
    if (!is_array($result)) {
        return false;
    }
    if (!empty($result['rate_limited'])) {
        return true;
    }
    $status = (int) ($result['status'] ?? 0);
    if ($status === 429) {
        return true;
    }
    $err = (string) ($result['error'] ?? '');
    return stripos($err, 'rate limit') !== false || stripos($err, 'too many requests') !== false;
}

/**
 * Seconds to wait before retrying a rate-limited Bluesky call.
 */
function ap_bsky_result_retry_after_sec(?array $result, int $fallback = 300): int
{
    if (!is_array($result)) {
        return ap_bsky_clamp_retry_delay($fallback);
    }
    if (!empty($result['retry_after_sec'])) {
        return ap_bsky_clamp_retry_delay((int) $result['retry_after_sec']);
    }
    if (!empty($result['reset_at'])) {
        return ap_bsky_clamp_retry_delay((int) $result['reset_at'] - time());
    }
    return ap_bsky_clamp_retry_delay($fallback);
}

/**
 * Push next_attempt_at forward for all pending retries of one owner (shared rate-limit window).
 */
function ap_bsky_crosspost_retry_pause_owner(int $ownerUserId, int $delaySec, ?string $reason = null): void
{
    if ($ownerUserId < 1) {
        return;
    }
    ap_bsky_crosspost_retries_migrate();
    $delaySec = ap_bsky_clamp_retry_delay($delaySec);
    $next = gmdate('c', time() + $delaySec);
    $now = gmdate('c');
    $err = $reason !== null ? mb_substr(trim($reason), 0, 500) : null;
    try {
        $st = ap_db()->prepare(
            "UPDATE bsky_crosspost_retries
             SET next_attempt_at = CASE
                    WHEN next_attempt_at IS NULL OR next_attempt_at < ? THEN ?
                    ELSE next_attempt_at
                 END,
                 last_error = COALESCE(?, last_error),
                 updated_at = ?
             WHERE owner_user_id = ? AND status = 'pending'"
        );
        $st->execute([$next, $next, $err, $now, $ownerUserId]);
    } catch (Throwable $e) {
        error_log('[ap-bsky] pause_owner: ' . $e->getMessage());
    }
}

/**
 * Connect with Bluesky handle + app password. Stores encrypted JWTs.
 *
 * @return array{ok:bool,error?:string,handle?:string,did?:string}
 */
function ap_bsky_connect(int $ownerUserId, string $identifier, string $appPassword, string $pdsHost = AP_BSKY_DEFAULT_PDS): array
{
    if ($ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Not signed in'];
    }
    if (!function_exists('ap_auth_secret_encrypt')) {
        require_once __DIR__ . '/ap-auth.php';
    }
    $identifier = trim(ltrim($identifier, '@'));
    $appPassword = trim($appPassword);
    $pdsHost = rtrim(trim($pdsHost) !== '' ? trim($pdsHost) : AP_BSKY_DEFAULT_PDS, '/');
    if ($identifier === '' || $appPassword === '') {
        return ['ok' => false, 'error' => 'Handle and app password required'];
    }
    if (!str_starts_with($pdsHost, 'https://')) {
        return ['ok' => false, 'error' => 'PDS host must be https://…'];
    }
    ap_bsky_migrate();
    $res = ap_bsky_xrpc($pdsHost, 'com.atproto.server.createSession', 'POST', null, [
        'identifier' => $identifier,
        'password' => $appPassword,
    ], null, 12);
    if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
        return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Login failed')];
    }
    $j = $res['json'];
    $access = (string) ($j['accessJwt'] ?? '');
    $refresh = (string) ($j['refreshJwt'] ?? '');
    $did = (string) ($j['did'] ?? '');
    $handle = (string) ($j['handle'] ?? $identifier);
    if ($access === '' || $refresh === '' || $did === '') {
        return ['ok' => false, 'error' => 'Session response missing tokens'];
    }
    $accessEnc = ap_auth_secret_encrypt($access);
    $refreshEnc = ap_auth_secret_encrypt($refresh);
    if ($accessEnc === '' || $refreshEnc === '') {
        return ['ok' => false, 'error' => 'Could not encrypt session tokens'];
    }
    $now = gmdate('c');
    try {
        $st = ap_db()->prepare(
            'INSERT INTO bsky_sessions (owner_user_id, handle, did, pds_host, access_jwt_enc, refresh_jwt_enc, connected_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (owner_user_id) DO UPDATE SET
               handle = EXCLUDED.handle,
               did = EXCLUDED.did,
               pds_host = EXCLUDED.pds_host,
               access_jwt_enc = EXCLUDED.access_jwt_enc,
               refresh_jwt_enc = EXCLUDED.refresh_jwt_enc,
               updated_at = EXCLUDED.updated_at'
        );
        $st->execute([$ownerUserId, $handle, $did, $pdsHost, $accessEnc, $refreshEnc, $now, $now]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save session (is bsky_sessions provisioned?)'];
    }
    // Best-effort: mirror VAAK avatar / header / bio onto the Bluesky profile.
    $sync = ap_bsky_sync_profile_from_vaak($ownerUserId);
    return [
        'ok' => true,
        'handle' => $handle,
        'did' => $did,
        'profile_synced' => !empty($sync['ok']),
        'profile_sync_error' => empty($sync['ok']) ? (string) ($sync['error'] ?? '') : '',
    ];
}

/**
 * Create and link a native account on the VAAK PDS.
 * The PDS currently requires an invite code; it is deliberately supplied by
 * the user rather than stored in VAAK or exposed to other accounts.
 *
 * @return array{ok:bool,error?:string,handle?:string,did?:string,profile_synced?:bool,profile_sync_error?:string}
 */
function ap_bsky_create_account(
    int $ownerUserId,
    string $handleLocal,
    string $email,
    string $password,
    string $inviteCode
): array {
    if ($ownerUserId < 1) return ['ok' => false, 'error' => 'Not signed in'];
    if (!function_exists('ap_auth_secret_encrypt')) require_once __DIR__ . '/ap-auth.php';
    if (ap_bsky_session_row($ownerUserId) !== null) {
        return ['ok' => false, 'error' => 'A Bluesky account is already connected. Disconnect it before creating another.'];
    }
    $handleLocal = strtolower(trim(ltrim($handleLocal, '@')));
    $email = trim($email);
    $password = trim($password);
    $inviteCode = trim($inviteCode);
    if (!preg_match('/^[a-z][a-z0-9-]{2,23}$/', $handleLocal)) {
        return ['ok' => false, 'error' => 'Handle must be 3–24 characters, start with a letter, and use only letters, numbers, or hyphens.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Enter a valid recovery email address.'];
    if (strlen($password) < 8 || strlen($password) > 256) return ['ok' => false, 'error' => 'Password must be between 8 and 256 characters.'];
    if ($inviteCode === '' || strlen($inviteCode) > 256) return ['ok' => false, 'error' => 'A VAAK PDS invite code is required.'];

    ap_bsky_migrate();
    $handle = $handleLocal . '.bsky.mkultra.monster';
    $res = ap_bsky_xrpc(AP_BSKY_VAAK_PDS, 'com.atproto.server.createAccount', 'POST', null, [
        'email' => $email,
        'handle' => $handle,
        'password' => $password,
        'inviteCode' => $inviteCode,
    ], null, 15);
    if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
        return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not create the VAAK Bluesky account.')];
    }
    ap_bsky_mark_pds_invite_used($inviteCode);
    $j = $res['json'];
    $access = (string) ($j['accessJwt'] ?? '');
    $refresh = (string) ($j['refreshJwt'] ?? '');
    $did = (string) ($j['did'] ?? '');
    $actualHandle = (string) ($j['handle'] ?? $handle);
    if ($access === '' || $refresh === '' || $did === '') return ['ok' => false, 'error' => 'PDS account response was missing session tokens.'];
    $accessEnc = ap_auth_secret_encrypt($access);
    $refreshEnc = ap_auth_secret_encrypt($refresh);
    if ($accessEnc === '' || $refreshEnc === '') return ['ok' => false, 'error' => 'Could not encrypt the new Bluesky session.'];
    try {
        $st = ap_db()->prepare(
            'INSERT INTO bsky_sessions (owner_user_id, handle, did, pds_host, access_jwt_enc, refresh_jwt_enc, connected_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$ownerUserId, $actualHandle, $did, AP_BSKY_VAAK_PDS, $accessEnc, $refreshEnc, gmdate('c'), gmdate('c')]);
    } catch (Throwable $e) {
        error_log('[ap-bsky] create account session save: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The PDS account was created, but VAAK could not save its connection. Contact the operator before retrying.'];
    }
    $sync = ap_bsky_sync_profile_from_vaak($ownerUserId);
    return [
        'ok' => true,
        'handle' => $actualHandle,
        'did' => $did,
        'profile_synced' => !empty($sync['ok']),
        'profile_sync_error' => empty($sync['ok']) ? (string) ($sync['error'] ?? '') : '',
    ];
}

/** Read the local PDS admin credentials without exposing them to callers. */
function ap_bsky_pds_admin_config(): array
{
    $path = '/etc/mkultra/vaak-pds.env';
    $cfg = [];
    if (is_readable($path)) {
        foreach (preg_split("/\r\n|\n|\r/", (string) @file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $cfg[$key] = trim($value, " \t\"'");
        }
    }
    return $cfg;
}

/** Issue a single-use invite from the VAAK PDS admin API. */
function ap_bsky_create_pds_invite(?int $ownerUserId = null): array
{
    $cfg = ap_bsky_pds_admin_config();
    $password = (string) ($cfg['PDS_ADMIN_PASSWORD'] ?? '');
    if ($password === '') return ['ok' => false, 'error' => 'PDS invite generation is not configured yet.'];
    $ch = curl_init(AP_BSKY_VAAK_PDS . '/xrpc/com.atproto.server.createInviteCode');
    if ($ch === false) return ['ok' => false, 'error' => 'Could not initialize the PDS connection.'];
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['useCount' => 1], JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_USERPWD => 'admin:' . $password,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 8,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($json) || empty($json['code'])) {
        $detail = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : '';
        return ['ok' => false, 'error' => $detail !== '' ? $detail : ('PDS invite generation failed' . ($status > 0 ? ' (HTTP ' . $status . ')' : ($err !== '' ? ': ' . $err : '.')))];
    }
    $code = (string) $json['code'];
    if ($ownerUserId !== null && $ownerUserId > 0) {
        try {
            ap_db()->prepare('INSERT INTO ap_pds_invites (owner_user_id, code, created_at) VALUES (?, ?, ?) ON CONFLICT (code) DO NOTHING')
                ->execute([$ownerUserId, $code, ap_db_now()]);
        } catch (Throwable $e) {
            error_log('[ap-bsky] invite ledger save: ' . $e->getMessage());
        }
    }
    return ['ok' => true, 'code' => $code];
}

/**
 * Return a user's issued PDS invites and reconcile one-time use with the PDS.
 * The short admin request is limited to this page and never runs in feeds.
 */
function ap_bsky_pds_invites_for_user(int $ownerUserId): array
{
    if ($ownerUserId < 1) return [];
    try {
        $st = ap_db()->prepare('SELECT id, code, created_at, used_at FROM ap_pds_invites WHERE owner_user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50');
        $st->execute([$ownerUserId]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        error_log('[ap-bsky] invite ledger read: ' . $e->getMessage());
        return [];
    }
    if (!$rows) return [];
    $cfg = ap_bsky_pds_admin_config();
    $password = (string) ($cfg['PDS_ADMIN_PASSWORD'] ?? '');
    if ($password === '') return $rows;
    $ch = curl_init(AP_BSKY_VAAK_PDS . '/xrpc/com.atproto.server.getInviteCodes?limit=500');
    if ($ch === false) return $rows;
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERPWD => 'admin:' . $password,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT => 3,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    $remote = [];
    foreach ((is_array($json) ? ($json['codes'] ?? []) : []) as $item) {
        if (!is_array($item) || empty($item['code'])) continue;
        $remote[(string) $item['code']] = $item;
    }
    if (!$remote) return $rows;
    foreach ($rows as &$row) {
        if (!empty($row['used_at'])) continue;
        $item = $remote[(string) ($row['code'] ?? '')] ?? null;
        $uses = is_array($item) ? ($item['uses'] ?? []) : [];
        $usedAt = is_array($uses) && !empty($uses[0]['usedAt']) ? (string) $uses[0]['usedAt'] : '';
        if ($usedAt === '' && is_array($item) && array_key_exists('available', $item) && !$item['available']) {
            $usedAt = ap_db_now();
        }
        if ($usedAt !== '') {
            try {
                ap_db()->prepare('UPDATE ap_pds_invites SET used_at = ? WHERE id = ? AND used_at IS NULL')->execute([$usedAt, (int) $row['id']]);
                $row['used_at'] = $usedAt;
            } catch (Throwable $e) {
                error_log('[ap-bsky] invite ledger status save: ' . $e->getMessage());
            }
        }
    }
    unset($row);
    return $rows;
}

function ap_bsky_mark_pds_invite_used(string $code): void
{
    $code = trim($code);
    if ($code === '') return;
    try {
        ap_db()->prepare('UPDATE ap_pds_invites SET used_at = COALESCE(used_at, ?) WHERE code = ?')->execute([ap_db_now(), $code]);
    } catch (Throwable $e) {
        error_log('[ap-bsky] invite ledger use save: ' . $e->getMessage());
    }
}

/**
 * @return array{ok:bool,error?:string,access?:string}
 */
/**
 * True when a JWT is missing/unreadable or past exp (with a small skew).
 * Used to refresh before createRecord instead of posting with a dead access token.
 */
function ap_bsky_jwt_expired(?string $jwt, int $skewSec = 60): bool
{
    $jwt = is_string($jwt) ? trim($jwt) : '';
    if ($jwt === '' || !str_contains($jwt, '.')) {
        return true;
    }
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return true;
    }
    $payload = $parts[1];
    $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
    $json = base64_decode(strtr($payload, '-_', '+/'), true);
    if (!is_string($json) || $json === '') {
        return true;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['exp'])) {
        return true;
    }
    $exp = (int) $data['exp'];
    return $exp <= (time() + max(0, $skewSec));
}

/**
 * Auth errors that mean the stored Bluesky login is dead (password reset, revoked
 * refresh token, etc.) — clear the session so Profile shows reconnect.
 */
function ap_bsky_auth_error_is_fatal(?string $message): bool
{
    $m = strtolower(trim((string) $message));
    if ($m === '') {
        return false;
    }
    return str_contains($m, 'expired')
        || str_contains($m, 'invalidtoken')
        || str_contains($m, 'invalid token')
        || str_contains($m, 'token has been revoked')
        || str_contains($m, 'authenticationrequired')
        || str_contains($m, 'unauthorized');
}

function ap_bsky_access_token(int $ownerUserId, bool $forceRefresh = false, int $timeoutSec = 6): array
{
    if (!function_exists('ap_auth_secret_decrypt') || !function_exists('ap_auth_secret_encrypt')) {
        require_once __DIR__ . '/ap-auth.php';
    }
    $row = ap_bsky_session_row($ownerUserId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Bluesky not connected'];
    }
    $access = ap_auth_secret_decrypt((string) ($row['access_jwt_enc'] ?? ''));
    $refresh = ap_auth_secret_decrypt((string) ($row['refresh_jwt_enc'] ?? ''));
    if (!is_string($refresh) || $refresh === '') {
        ap_bsky_disconnect($ownerUserId);
        return ['ok' => false, 'error' => 'Session expired — reconnect Bluesky in Profile'];
    }
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $accessOk = is_string($access) && $access !== '' && !ap_bsky_jwt_expired($access);
    if (!$forceRefresh && $accessOk) {
        return ['ok' => true, 'access' => $access];
    }
    if (ap_bsky_budget_exceeded()) {
        return ['ok' => false, 'error' => 'Bluesky budget exceeded'];
    }
    $timeoutSec = ap_bsky_effective_timeout($timeoutSec);
    if ($timeoutSec < 1) {
        return ['ok' => false, 'error' => 'Bluesky budget exceeded'];
    }
    // refreshSession: Authorization = refreshJwt, POST with NO body
    // (PDS returns 400 "body provided when none was expected" if we send {}).
    $ch = curl_init($pds . '/xrpc/com.atproto.server.refreshSession');
    if ($ch === false) {
        return ['ok' => false, 'error' => 'Refresh failed'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => min(AP_BSKY_CONNECT_TIMEOUT, $timeoutSec),
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $refresh,
            'Accept: application/json',
            'User-Agent: VAAK-Bluesky/1.0 (+https://mkultra.monster/vaak)',
            'Content-Length: 0',
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = is_string($body) ? json_decode($body, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($j)) {
        $msg = is_array($j) ? (string) ($j['message'] ?? $j['error'] ?? '') : '';
        $errCode = is_array($j) ? (string) ($j['error'] ?? '') : '';
        $combined = trim($errCode . ' ' . $msg);
        // Password reset / revoked refresh — drop the zombie "Connected" row.
        if ($status === 401 || ap_bsky_auth_error_is_fatal($combined) || ap_bsky_jwt_expired($refresh, 0)) {
            ap_bsky_disconnect($ownerUserId);
            return [
                'ok' => false,
                'error' => 'Bluesky login expired (password reset or revoked session) — reconnect in Profile',
            ];
        }
        return [
            'ok' => false,
            'error' => $msg !== ''
                ? ('Session refresh failed: ' . $msg)
                : 'Session expired — reconnect Bluesky in Profile',
        ];
    }
    $newAccess = (string) ($j['accessJwt'] ?? '');
    $newRefresh = (string) ($j['refreshJwt'] ?? $refresh);
    if ($newAccess === '') {
        ap_bsky_disconnect($ownerUserId);
        return ['ok' => false, 'error' => 'Session expired — reconnect Bluesky in Profile'];
    }
    $accessEnc = ap_auth_secret_encrypt($newAccess);
    $refreshEnc = ap_auth_secret_encrypt($newRefresh);
    try {
        $st = ap_db()->prepare(
            'UPDATE bsky_sessions SET access_jwt_enc = ?, refresh_jwt_enc = ?, handle = COALESCE(NULLIF(?, \'\'), handle), updated_at = ? WHERE owner_user_id = ?'
        );
        $st->execute([
            $accessEnc,
            $refreshEnc,
            (string) ($j['handle'] ?? ''),
            gmdate('c'),
            $ownerUserId,
        ]);
    } catch (Throwable $e) {
        // still return token even if persist fails
    }
    return ['ok' => true, 'access' => $newAccess];
}

/**
 * Hosts to try for feed reads, in order.
 * Custom PDSes often reject / aren't accepted by public.api.bsky.app JWTs, but
 * proxy AppView themselves — so prefer the account's PDS first for those.
 *
 * @return list<string>
 */
function ap_bsky_feed_hosts(string $pdsHost): array
{
    $pdsHost = rtrim($pdsHost, '/');
    $hosts = [];
    $isBskySocial = $pdsHost === 'https://bsky.social'
        || str_ends_with(parse_url($pdsHost, PHP_URL_HOST) ?: '', '.bsky.social');
    if (!$isBskySocial && $pdsHost !== '') {
        $hosts[] = $pdsHost;
    }
    $hosts[] = AP_BSKY_PUBLIC_API;
    if ($isBskySocial && $pdsHost !== '' && !in_array($pdsHost, $hosts, true)) {
        $hosts[] = $pdsHost;
    }
    return array_values(array_unique($hosts));
}

/**
 * @return array{ok:bool,error?:string,feed?:list<array>,cursor?:?string}
 */
function ap_bsky_get_timeline(int $ownerUserId, int $limit = 40, ?string $cursor = null): array
{
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        // One forced refresh before giving up
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (empty($tok['ok'])) {
            return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Not connected')];
        }
    }
    $row = ap_bsky_session_row($ownerUserId);
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $query = ['limit' => max(1, min(50, $limit))];
    if (is_string($cursor) && $cursor !== '') {
        $query['cursor'] = $cursor;
    }
    $lastErr = 'Timeline fetch failed';
    $res = null;
    foreach (ap_bsky_feed_hosts($pds) as $apiHost) {
        $attempt = ap_bsky_xrpc($apiHost, 'app.bsky.feed.getTimeline', 'GET', $query, null, (string) $tok['access'], 6);
        if (($attempt['status'] ?? 0) === 401) {
            $tok = ap_bsky_access_token($ownerUserId, true, 4);
            if (empty($tok['ok'])) {
                return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Session expired')];
            }
            $attempt = ap_bsky_xrpc($apiHost, 'app.bsky.feed.getTimeline', 'GET', $query, null, (string) $tok['access'], 6);
        }
        if (!empty($attempt['ok']) && is_array($attempt['json'] ?? null)) {
            $res = $attempt;
            break;
        }
        $lastErr = (string) ($attempt['error'] ?? $lastErr);
    }
    if ($res === null) {
        return ['ok' => false, 'error' => $lastErr];
    }
    $j = $res['json'];
    $feed = is_array($j['feed'] ?? null) ? $j['feed'] : [];
    $next = isset($j['cursor']) && is_string($j['cursor']) ? $j['cursor'] : null;

    // Brand-new PDS accounts often have an empty home timeline (no follows yet).
    // Surface the user's own posts so the tab isn't a dead empty state.
    if ($feed === [] && ($cursor === null || $cursor === '')) {
        $own = ap_bsky_get_author_feed($ownerUserId, $limit, null);
        if (!empty($own['ok']) && !empty($own['feed'])) {
            return $own + ['source' => 'author'];
        }
    }

    return ['ok' => true, 'feed' => $feed, 'cursor' => $next, 'source' => 'home'];
}

/**
 * Author feed (own posts). Used as empty-Following fallback and for its pagination
 * (author cursors are not valid on getTimeline).
 *
 * @return array{ok:bool,error?:string,feed?:list<array>,cursor?:?string,source?:string}
 */
function ap_bsky_get_author_feed(int $ownerUserId, int $limit = 40, ?string $cursor = null): array
{
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (empty($tok['ok'])) {
            return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Not connected')];
        }
    }
    $row = ap_bsky_session_row($ownerUserId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Not connected'];
    }
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $handle = (string) ($row['handle'] ?? '');
    $did = (string) ($row['did'] ?? '');
    $actor = $handle !== '' ? $handle : $did;
    if ($actor === '') {
        return ['ok' => false, 'error' => 'Missing Bluesky actor'];
    }
    // posts_and_author_threads: roots + replies on the author's own threads
    // (catches Bluesky self-thread continuations that posts_no_replies would drop).
    $query = [
        'actor' => $actor,
        'limit' => max(1, min(50, $limit)),
        'filter' => 'posts_and_author_threads',
    ];
    if (is_string($cursor) && $cursor !== '') {
        $query['cursor'] = $cursor;
    }
    $lastErr = 'Author feed failed';
    foreach (ap_bsky_feed_hosts($pds) as $apiHost) {
        $af = ap_bsky_xrpc(
            $apiHost,
            'app.bsky.feed.getAuthorFeed',
            'GET',
            $query,
            null,
            (string) $tok['access'],
            12
        );
        if (($af['status'] ?? 0) === 401) {
            $tok = ap_bsky_access_token($ownerUserId, true);
            if (empty($tok['ok'])) {
                return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Session expired')];
            }
            $af = ap_bsky_xrpc(
                $apiHost,
                'app.bsky.feed.getAuthorFeed',
                'GET',
                $query,
                null,
                (string) $tok['access'],
                12
            );
        }
        if (!empty($af['ok']) && is_array($af['json'] ?? null)) {
            $feed = is_array($af['json']['feed'] ?? null) ? $af['json']['feed'] : [];
            ap_bsky_index_feed_items($feed, $ownerUserId, count($feed));
            ap_bsky_import_own_feed_as_local($ownerUserId, $feed);
            return [
                'ok' => true,
                'feed' => $feed,
                'cursor' => isset($af['json']['cursor']) && is_string($af['json']['cursor'])
                    ? $af['json']['cursor']
                    : null,
                'source' => 'author',
            ];
        }
        $lastErr = (string) ($af['error'] ?? $lastErr);
    }
    return ['ok' => false, 'error' => $lastErr];
}

/**
 * Upsert inbound/outbound AP↔AT link for Wafrn-style dedupe.
 */
function ap_bsky_post_links_migrate(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $driver = function_exists('ap_db_driver') ? ap_db_driver() : 'sqlite';
        if ($driver === 'pgsql') {
            $have = (bool) ap_db()->query(
                "SELECT EXISTS (
                    SELECT 1 FROM information_schema.tables
                    WHERE table_schema = current_schema() AND table_name = 'bsky_post_links'
                 )"
            )->fetchColumn();
            if (!$have) {
                ap_db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_post_links (
    bsky_uri TEXT PRIMARY KEY,
    bsky_cid TEXT,
    fediverse_id TEXT,
    ap_object_id TEXT,
    seen_at TEXT NOT NULL
)
SQL);
                try {
                    ap_db()->exec('CREATE INDEX IF NOT EXISTS idx_bsky_post_links_fedi ON bsky_post_links (fediverse_id)');
                } catch (Throwable $e) {
                    // ignore
                }
            }
        } else {
            ap_db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_post_links (
    bsky_uri TEXT PRIMARY KEY,
    bsky_cid TEXT,
    fediverse_id TEXT,
    ap_object_id TEXT,
    seen_at TEXT NOT NULL
)
SQL);
        }
    } catch (Throwable $e) {
        // ops may provision
    }
}

/**
 * @return array{bsky_uri:string,bsky_cid:?string,fediverse_id:?string,ap_object_id:?string}|null
 */
function ap_bsky_post_link_upsert(
    string $bskyUri,
    ?string $bskyCid = null,
    ?string $fediverseId = null,
    ?string $apObjectId = null
): ?array {
    $bskyUri = trim($bskyUri);
    if (!str_starts_with($bskyUri, 'at://')) {
        return null;
    }
    ap_bsky_post_links_migrate();
    $fediverseId = is_string($fediverseId) && str_starts_with($fediverseId, 'https://') ? $fediverseId : null;
    $apObjectId = is_string($apObjectId) && str_starts_with($apObjectId, 'https://') ? $apObjectId : $fediverseId;
    $cid = is_string($bskyCid) && $bskyCid !== '' ? $bskyCid : null;
    try {
        $st = ap_db()->prepare(
            'INSERT INTO bsky_post_links (bsky_uri, bsky_cid, fediverse_id, ap_object_id, seen_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (bsky_uri) DO UPDATE SET
               bsky_cid = COALESCE(excluded.bsky_cid, bsky_post_links.bsky_cid),
               fediverse_id = COALESCE(excluded.fediverse_id, bsky_post_links.fediverse_id),
               ap_object_id = COALESCE(excluded.ap_object_id, bsky_post_links.ap_object_id),
               seen_at = excluded.seen_at'
        );
        $st->execute([$bskyUri, $cid, $fediverseId, $apObjectId, gmdate('c')]);
        return [
            'bsky_uri' => $bskyUri,
            'bsky_cid' => $cid,
            'fediverse_id' => $fediverseId,
            'ap_object_id' => $apObjectId,
        ];
    } catch (Throwable $e) {
        error_log('[ap-bsky] post_link_upsert: ' . $e->getMessage());
        return null;
    }
}

/**
 * @return array{bsky_uri:string,bsky_cid:?string,fediverse_id:?string,ap_object_id:?string}|null
 */
function ap_bsky_post_link_by_uri(string $bskyUri): ?array
{
    $bskyUri = trim($bskyUri);
    if (!str_starts_with($bskyUri, 'at://')) {
        return null;
    }
    ap_bsky_post_links_migrate();
    try {
        $st = ap_db()->prepare('SELECT * FROM bsky_post_links WHERE bsky_uri = ? LIMIT 1');
        $st->execute([$bskyUri]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return array{bsky_uri:string,bsky_cid:?string,fediverse_id:?string,ap_object_id:?string}|null
 */
function ap_bsky_post_link_by_fedi(string $fediverseId): ?array
{
    $fediverseId = rtrim(trim($fediverseId), '/');
    if ($fediverseId === '' || !str_starts_with($fediverseId, 'https://')) {
        return null;
    }
    ap_bsky_post_links_migrate();
    try {
        $st = ap_db()->prepare(
            'SELECT * FROM bsky_post_links
             WHERE fediverse_id = ? OR fediverse_id = ? OR ap_object_id = ? OR ap_object_id = ?
             LIMIT 1'
        );
        $st->execute([$fediverseId, $fediverseId . '/', $fediverseId, $fediverseId . '/']);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Record dual-publish markers from a Bluesky feed post (inbound dedupe).
 *
 * @param array<string,mixed> $post
 */
function ap_bsky_index_feed_post_links(array $post): void
{
    $uri = trim((string) ($post['uri'] ?? ''));
    if (!str_starts_with($uri, 'at://')) {
        return;
    }
    $cid = trim((string) ($post['cid'] ?? ''));
    $record = is_array($post['record'] ?? null) ? $post['record'] : [];
    $fedi = trim((string) ($record['fediverseId'] ?? ''));
    if ($fedi === '' && isset($record['fediverse_id'])) {
        $fedi = trim((string) $record['fediverse_id']);
    }
    ap_bsky_post_link_upsert($uri, $cid !== '' ? $cid : null, $fedi !== '' ? $fedi : null, null);
}

/** Max plain text stored for a cached Bluesky post (mirrors events.summary cap). */
const AP_BSKY_POST_TEXT_MAX = 4000;

function ap_bsky_posts_migrate(?PDO $db = null): void
{
    static $doneByConnection = [];
    $db ??= ap_db();
    $connectionKey = spl_object_id($db);
    if (isset($doneByConnection[$connectionKey])) {
        return;
    }
    $doneByConnection[$connectionKey] = true;
    try {
        $driver = function_exists('ap_db_driver') ? ap_db_driver($db) : 'sqlite';
        if ($driver === 'pgsql') {
            $have = (bool) $db->query(
                "SELECT EXISTS (
                    SELECT 1 FROM information_schema.tables
                    WHERE table_schema = current_schema() AND table_name = 'bsky_posts'
                 )"
            )->fetchColumn();
            if (!$have) {
                $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_posts (
    bsky_uri TEXT PRIMARY KEY,
    bsky_cid TEXT,
    author_did TEXT NOT NULL,
    author_handle TEXT,
    author_display TEXT,
    author_avatar TEXT,
    indexed_at TEXT,
    published_at TEXT,
    text TEXT NOT NULL DEFAULT '',
    embed_json TEXT,
    reply_parent TEXT,
    reply_root TEXT,
    like_count INTEGER,
    repost_count INTEGER,
    reply_count INTEGER,
    quote_count INTEGER,
    reason_json TEXT,
    raw_json TEXT,
    owner_user_id BIGINT,
    seen_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL);
                try {
                    $db->exec('CREATE INDEX IF NOT EXISTS idx_bsky_posts_author_idx ON bsky_posts (author_did, indexed_at DESC)');
                    $db->exec('CREATE INDEX IF NOT EXISTS idx_bsky_posts_seen ON bsky_posts (seen_at)');
                    $db->exec('CREATE INDEX IF NOT EXISTS idx_bsky_posts_owner_idx ON bsky_posts (owner_user_id, indexed_at DESC)');
                } catch (Throwable $e) {
                    // ignore
                }
            }
        } else {
            $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_posts (
    bsky_uri TEXT PRIMARY KEY,
    bsky_cid TEXT,
    author_did TEXT NOT NULL,
    author_handle TEXT,
    author_display TEXT,
    author_avatar TEXT,
    indexed_at TEXT,
    published_at TEXT,
    text TEXT NOT NULL DEFAULT '',
    embed_json TEXT,
    reply_parent TEXT,
    reply_root TEXT,
    like_count INTEGER,
    repost_count INTEGER,
    reply_count INTEGER,
    quote_count INTEGER,
    reason_json TEXT,
    raw_json TEXT,
    owner_user_id INTEGER,
    seen_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL);
        }
    } catch (Throwable $e) {
        // ops may provision
    }
}

/**
 * Bluesky label values that should blur media like Fediverse "sensitive".
 *
 * @return list<string>
 */
function ap_bsky_sensitive_label_vals(): array
{
    return [
        'porn',
        'sexual',
        'nudity',
        'graphic-media',
        'sexual-cartoon',
        'sexual-figurative',
        'nsfw',
        'self-harm',
        'sensitive',
    ];
}

/**
 * Compact label list from PostView labels or record selfLabels.
 *
 * @param mixed $labels
 * @return list<array{val:string,src?:string}>
 */
function ap_bsky_compact_label_list(mixed $labels): array
{
    if (!is_array($labels)) {
        return [];
    }
    // selfLabels: { $type, values: [ {val}, ... ] }
    if (isset($labels['values']) && is_array($labels['values'])) {
        $labels = $labels['values'];
    }
    $out = [];
    $seen = [];
    foreach ($labels as $lab) {
        $val = '';
        $src = '';
        if (is_array($lab)) {
            $val = strtolower(trim((string) ($lab['val'] ?? '')));
            $src = trim((string) ($lab['src'] ?? ''));
        } elseif (is_string($lab)) {
            $val = strtolower(trim($lab));
        }
        if ($val === '' || isset($seen[$val])) {
            continue;
        }
        $seen[$val] = true;
        $row = ['val' => $val];
        if ($src !== '') {
            $row['src'] = $src;
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * All label vals on a PostView-shaped post (top-level + record self-labels).
 *
 * @param array<string,mixed> $post
 * @return list<string>
 */
function ap_bsky_post_label_vals(array $post): array
{
    $vals = [];
    foreach (ap_bsky_compact_label_list($post['labels'] ?? null) as $lab) {
        $vals[] = (string) ($lab['val'] ?? '');
    }
    $record = is_array($post['record'] ?? null) ? $post['record'] : [];
    foreach (ap_bsky_compact_label_list($record['labels'] ?? ($record['selfLabels'] ?? null)) as $lab) {
        $vals[] = (string) ($lab['val'] ?? '');
    }
    $vals = array_values(array_filter(array_unique($vals), static fn(string $v): bool => $v !== ''));
    return $vals;
}

/**
 * True when a Bluesky post carries adult/graphic moderation labels.
 *
 * @param array<string,mixed> $post
 */
function ap_bsky_post_is_sensitive(array $post): bool
{
    $sens = array_fill_keys(ap_bsky_sensitive_label_vals(), true);
    foreach (ap_bsky_post_label_vals($post) as $val) {
        if (isset($sens[$val])) {
            return true;
        }
    }
    return false;
}

/**
 * Compact embed projection for cache (images / external / quote stub).
 *
 * @param array<string,mixed>|null $embed
 * @return array<string,mixed>|null
 */
function ap_bsky_embed_video_view(?array $embed): ?array
{
    if ($embed === null) {
        return null;
    }
    $type = (string) ($embed['$type'] ?? '');
    if (str_contains($type, 'recordWithMedia')) {
        $media = is_array($embed['media'] ?? null) ? $embed['media'] : null;
        if ($media === null) {
            return null;
        }
        if (is_array($media['video'] ?? null)) {
            return $media['video'];
        }
        if (str_contains((string) ($media['$type'] ?? ''), 'video')
            || isset($media['playlist']) || isset($media['thumbnail'])) {
            return $media;
        }
        return null;
    }
    if (!str_contains($type, 'video')) {
        return null;
    }
    if (is_array($embed['video'] ?? null)) {
        $inner = $embed['video'];
        if (isset($inner['playlist']) || isset($inner['thumbnail']) || isset($inner['url'])) {
            return $inner;
        }
    }
    return (isset($embed['playlist']) || isset($embed['thumbnail'])) ? $embed : null;
}

function ap_bsky_post_embed_compact(?array $embed): ?array
{
    if ($embed === null) {
        return null;
    }
    $type = (string) ($embed['$type'] ?? '');
    $out = ['$type' => $type];
    if (str_contains($type, 'images') && is_array($embed['images'] ?? null)) {
        $imgs = [];
        foreach (array_slice($embed['images'], 0, 4) as $img) {
            if (!is_array($img)) {
                continue;
            }
            $thumb = (string) ($img['thumb'] ?? $img['fullsize'] ?? '');
            $full = (string) ($img['fullsize'] ?? $img['thumb'] ?? '');
            if (!str_starts_with($thumb, 'https://') && !str_starts_with($full, 'https://')) {
                continue;
            }
            $imgs[] = [
                'thumb' => str_starts_with($thumb, 'https://') ? $thumb : $full,
                'fullsize' => str_starts_with($full, 'https://') ? $full : $thumb,
                'alt' => (string) ($img['alt'] ?? ''),
            ];
        }
        if ($imgs !== []) {
            $out['images'] = $imgs;
        }
    }
    if (str_contains($type, 'external') && is_array($embed['external'] ?? null)) {
        $ext = $embed['external'];
        $out['external'] = [
            'uri' => (string) ($ext['uri'] ?? ''),
            'title' => (string) ($ext['title'] ?? ''),
            'description' => mb_substr((string) ($ext['description'] ?? ''), 0, 500),
            'thumb' => (string) ($ext['thumb'] ?? ''),
        ];
    }
    // AppView video#view puts playlist/thumbnail on the embed itself, not under `video`.
    $video = ap_bsky_embed_video_view($embed);
    if ($video !== null) {
        $playlist = (string) ($video['playlist'] ?? $video['url'] ?? '');
        $thumbnail = (string) ($video['thumbnail'] ?? $video['thumb'] ?? '');
        if (str_starts_with($playlist, 'https://') || str_starts_with($thumbnail, 'https://')) {
            $out['video'] = [
                'playlist' => str_starts_with($playlist, 'https://') ? $playlist : '',
                'thumbnail' => str_starts_with($thumbnail, 'https://') ? $thumbnail : '',
                'alt' => mb_substr((string) ($video['alt'] ?? ''), 0, 500),
            ];
            if (is_array($video['aspectRatio'] ?? null)) {
                $out['video']['aspectRatio'] = [
                    'width' => (int) ($video['aspectRatio']['width'] ?? 0),
                    'height' => (int) ($video['aspectRatio']['height'] ?? 0),
                ];
            }
        }
    }
    // Store quote embeds in a shape ap_bsky_quote_preview understands
    // (viewRecord with author + value.text), not a flat {text} stub.
    $compactViewRecord = static function (?array $rec): ?array {
        if (!is_array($rec)) {
            return null;
        }
        if (isset($rec['record']) && is_array($rec['record'])
            && (isset($rec['record']['author']) || isset($rec['record']['value']))) {
            $rec = $rec['record'];
        }
        $author = is_array($rec['author'] ?? null) ? $rec['author'] : [];
        $value = is_array($rec['value'] ?? null)
            ? $rec['value']
            : (is_array($rec['record'] ?? null) ? $rec['record'] : []);
        $text = trim((string) ($value['text'] ?? $rec['text'] ?? ''));
        if (mb_strlen($text) > 400) {
            $text = mb_substr($text, 0, 397) . '…';
        }
        $outRec = [
            '$type' => 'app.bsky.embed.record#viewRecord',
            'uri' => (string) ($rec['uri'] ?? ''),
            'cid' => (string) ($rec['cid'] ?? ''),
            'author' => [
                'did' => (string) ($author['did'] ?? ''),
                'handle' => (string) ($author['handle'] ?? ''),
                'displayName' => (string) ($author['displayName'] ?? ''),
                'avatar' => (string) ($author['avatar'] ?? ''),
            ],
            'value' => [
                '$type' => 'app.bsky.feed.post',
                'text' => $text,
                'createdAt' => (string) ($value['createdAt'] ?? $rec['indexedAt'] ?? ''),
            ],
        ];
        // Keep quoted media so timeline quote blocks can show images/videos.
        $embedsOut = [];
        $srcEmbeds = is_array($rec['embeds'] ?? null) ? $rec['embeds'] : [];
        foreach ($srcEmbeds as $em) {
            if (!is_array($em)) {
                continue;
            }
            $c = ap_bsky_post_embed_compact($em);
            if (is_array($c)) {
                $embedsOut[] = $c;
            }
        }
        if ($embedsOut === [] && is_array($value['embed'] ?? null)) {
            $c = ap_bsky_post_embed_compact($value['embed']);
            if (is_array($c)) {
                $embedsOut[] = $c;
            }
        }
        if ($embedsOut !== []) {
            $outRec['embeds'] = $embedsOut;
        }
        return $outRec;
    };
    if (str_contains($type, 'recordWithMedia') && is_array($embed['media'] ?? null)) {
        $mediaCompact = ap_bsky_post_embed_compact($embed['media']);
        if ($mediaCompact !== null) {
            $out['media'] = $mediaCompact;
        }
        $viewRec = $compactViewRecord(
            is_array($embed['record']['record'] ?? null)
                ? $embed['record']['record']
                : (is_array($embed['record'] ?? null) ? $embed['record'] : null)
        );
        if ($viewRec !== null) {
            $out['record'] = ['record' => $viewRec];
        }
    } elseif (str_contains($type, 'embed.record') || str_contains($type, 'record#view')) {
        $viewRec = $compactViewRecord(
            is_array($embed['record'] ?? null) ? $embed['record'] : null
        );
        if ($viewRec !== null) {
            $out['record'] = $viewRec;
        }
    }
    return $out;
}

/**
 * Upsert a durable Bluesky post body from a FeedViewPost or PostView.
 *
 * @param array<string,mixed> $itemOrPost
 */
function ap_bsky_post_upsert_from_feed_item(array $itemOrPost, ?int $ownerUserId = null): void
{
    $post = is_array($itemOrPost['post'] ?? null) ? $itemOrPost['post'] : $itemOrPost;
    if (!is_array($post)) {
        return;
    }
    $uri = trim((string) ($post['uri'] ?? ''));
    if (!str_starts_with($uri, 'at://')) {
        return;
    }
    $author = is_array($post['author'] ?? null) ? $post['author'] : [];
    $authorDid = trim((string) ($author['did'] ?? ''));
    if ($authorDid === '') {
        return;
    }
    ap_bsky_posts_migrate();
    $record = is_array($post['record'] ?? null) ? $post['record'] : [];
    $text = trim((string) ($record['text'] ?? ''));
    if (mb_strlen($text) > AP_BSKY_POST_TEXT_MAX) {
        $text = mb_substr($text, 0, AP_BSKY_POST_TEXT_MAX - 1) . '…';
    }
    $replyParent = null;
    $replyRoot = null;
    if (is_array($record['reply'] ?? null)) {
        $replyParent = isset($record['reply']['parent']['uri'])
            ? trim((string) $record['reply']['parent']['uri']) : null;
        $replyRoot = isset($record['reply']['root']['uri'])
            ? trim((string) $record['reply']['root']['uri']) : null;
    }
    $embed = is_array($post['embed'] ?? null) ? $post['embed'] : null;
    $embedCompact = ap_bsky_post_embed_compact($embed);
    $reason = is_array($itemOrPost['reason'] ?? null) ? $itemOrPost['reason'] : null;
    $now = gmdate('c');
    $indexedAt = trim((string) ($post['indexedAt'] ?? ''));
    if ($indexedAt === '') {
        $indexedAt = trim((string) ($record['createdAt'] ?? $now));
    }
    $publishedAt = trim((string) ($record['createdAt'] ?? $indexedAt));
    // Trimmed PostView for re-render (drop huge blobs).
    $raw = [
        'uri' => $uri,
        'cid' => (string) ($post['cid'] ?? ''),
        'author' => [
            'did' => $authorDid,
            'handle' => (string) ($author['handle'] ?? ''),
            'displayName' => (string) ($author['displayName'] ?? ''),
            'avatar' => (string) ($author['avatar'] ?? ''),
        ],
        'record' => [
            '$type' => (string) ($record['$type'] ?? 'app.bsky.feed.post'),
            'text' => $text,
            'createdAt' => $publishedAt,
        ],
        'embed' => $embedCompact,
        'indexedAt' => $indexedAt,
        'likeCount' => (int) ($post['likeCount'] ?? 0),
        'repostCount' => (int) ($post['repostCount'] ?? 0),
        'replyCount' => (int) ($post['replyCount'] ?? 0),
        'quoteCount' => (int) ($post['quoteCount'] ?? 0),
        'viewer' => is_array($post['viewer'] ?? null) ? $post['viewer'] : null,
        // Keep moderation labels so Gallery / CW can blur adult media.
        'labels' => ap_bsky_compact_label_list($post['labels'] ?? null),
    ];
    if (isset($record['reply']) && is_array($record['reply'])) {
        $raw['record']['reply'] = $record['reply'];
    }
    if (!empty($record['fediverseId'])) {
        $raw['record']['fediverseId'] = (string) $record['fediverseId'];
    }
    // Self-labels live on the record (composer shield); also fold into top-level labels.
    $selfLabels = ap_bsky_compact_label_list($record['labels'] ?? ($record['selfLabels'] ?? null));
    if ($selfLabels !== []) {
        $raw['record']['labels'] = [
            '$type' => 'com.atproto.label.defs#selfLabels',
            'values' => $selfLabels,
        ];
        $seenVals = [];
        foreach ($raw['labels'] as $lab) {
            if (is_array($lab) && isset($lab['val'])) {
                $seenVals[strtolower((string) $lab['val'])] = true;
            }
        }
        foreach ($selfLabels as $lab) {
            $val = strtolower((string) ($lab['val'] ?? ''));
            if ($val === '' || isset($seenVals[$val])) {
                continue;
            }
            $raw['labels'][] = $lab;
            $seenVals[$val] = true;
        }
    }
    try {
        $st = ap_db()->prepare(
            'INSERT INTO bsky_posts (
                bsky_uri, bsky_cid, author_did, author_handle, author_display, author_avatar,
                indexed_at, published_at, text, embed_json, reply_parent, reply_root,
                like_count, repost_count, reply_count, quote_count, reason_json, raw_json,
                owner_user_id, seen_at, updated_at
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT (bsky_uri) DO UPDATE SET
               bsky_cid = COALESCE(excluded.bsky_cid, bsky_posts.bsky_cid),
               author_did = excluded.author_did,
               author_handle = COALESCE(excluded.author_handle, bsky_posts.author_handle),
               author_display = COALESCE(excluded.author_display, bsky_posts.author_display),
               author_avatar = COALESCE(excluded.author_avatar, bsky_posts.author_avatar),
               indexed_at = COALESCE(excluded.indexed_at, bsky_posts.indexed_at),
               published_at = COALESCE(excluded.published_at, bsky_posts.published_at),
               text = excluded.text,
               embed_json = COALESCE(excluded.embed_json, bsky_posts.embed_json),
               reply_parent = COALESCE(excluded.reply_parent, bsky_posts.reply_parent),
               reply_root = COALESCE(excluded.reply_root, bsky_posts.reply_root),
               like_count = excluded.like_count,
               repost_count = excluded.repost_count,
               reply_count = excluded.reply_count,
               quote_count = excluded.quote_count,
               reason_json = COALESCE(excluded.reason_json, bsky_posts.reason_json),
               raw_json = COALESCE(excluded.raw_json, bsky_posts.raw_json),
               owner_user_id = COALESCE(excluded.owner_user_id, bsky_posts.owner_user_id),
               seen_at = excluded.seen_at,
               updated_at = excluded.updated_at'
        );
        $st->execute([
            $uri,
            trim((string) ($post['cid'] ?? '')) ?: null,
            $authorDid,
            trim((string) ($author['handle'] ?? '')) ?: null,
            trim((string) ($author['displayName'] ?? '')) ?: null,
            trim((string) ($author['avatar'] ?? '')) ?: null,
            $indexedAt,
            $publishedAt,
            $text,
            $embedCompact !== null ? json_encode($embedCompact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            $replyParent,
            $replyRoot,
            (int) ($post['likeCount'] ?? 0),
            (int) ($post['repostCount'] ?? 0),
            (int) ($post['replyCount'] ?? 0),
            (int) ($post['quoteCount'] ?? 0),
            $reason !== null ? json_encode($reason, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ($ownerUserId !== null && $ownerUserId > 0) ? $ownerUserId : null,
            $now,
            $now,
        ]);
    } catch (Throwable $e) {
        error_log('[ap-bsky] post_upsert: ' . $e->getMessage());
    }
    // Keep link map in sync.
    ap_bsky_index_feed_post_links($post);
}

/**
 * Index a FeedViewPost (or PostView) into durable cache + link map.
 *
 * @param array<string,mixed> $item
 */
function ap_bsky_index_feed_item(array $item, ?int $ownerUserId = null): void
{
    ap_bsky_post_upsert_from_feed_item($item, $ownerUserId);
}

/**
 * @param list<array> $feed
 */
function ap_bsky_index_feed_items(array $feed, ?int $ownerUserId = null, int $syncLimit = 15): void
{
    $syncLimit = max(0, min(50, $syncLimit));
    $i = 0;
    foreach ($feed as $item) {
        if (!is_array($item)) {
            continue;
        }
        if ($i < $syncLimit) {
            ap_bsky_index_feed_item($item, $ownerUserId);
        }
        $i++;
    }
    if ($i > $syncLimit) {
        $rest = array_slice($feed, $syncLimit);
        register_shutdown_function(static function () use ($rest, $ownerUserId): void {
            try {
                foreach ($rest as $item) {
                    if (is_array($item)) {
                        ap_bsky_index_feed_item($item, $ownerUserId);
                    }
                }
                if ($ownerUserId !== null && $ownerUserId > 0) {
                    ap_bsky_import_own_feed_as_local($ownerUserId, $rest);
                }
            } catch (Throwable $e) {
                // ignore
            }
        });
    }
}

/**
 * Stable local note id for a Bluesky AT-URI (idempotent re-imports).
 */
function ap_bsky_local_note_id_for_import(string $actorId, string $atUri): string
{
    $actorId = rtrim($actorId, '/');
    return $actorId . '/notes/' . substr(hash('sha256', $atUri), 0, 16);
}

/**
 * Resolve a Bluesky quote embed to a URL VAAK can hydrate (local twin preferred).
 *
 * @param array<string,mixed> $post
 */
function ap_bsky_quote_target_url_for_import(array $post, int $ownerUserId = 0): ?string
{
    if (!function_exists('ap_bsky_quote_preview')) {
        return null;
    }
    $q = ap_bsky_quote_preview($post);
    if (!is_array($q)) {
        return null;
    }
    $at = trim((string) ($q['uri'] ?? ''));
    $web = trim((string) ($q['url'] ?? ''));
    if ($at !== '' && str_starts_with($at, 'at://') && $ownerUserId > 0) {
        $local = ap_bsky_local_note_id_for_at_uri($at, $ownerUserId);
        if (is_string($local) && str_starts_with($local, 'https://')) {
            return rtrim($local, '/');
        }
    }
    if ($web !== '' && $web !== 'https://bsky.app/' && str_starts_with($web, 'https://')) {
        if (function_exists('ap_bsky_normalize_web_url')) {
            $web = ap_bsky_normalize_web_url($web);
        }
        return $web;
    }
    if ($at !== '' && str_starts_with($at, 'at://') && function_exists('ap_bsky_https_url_from_at_uri')) {
        $handle = trim((string) ($q['handle'] ?? ''));
        return ap_bsky_https_url_from_at_uri($at, $handle !== '' ? $handle : null);
    }
    return null;
}

/**
 * Patch an already-imported Bluesky-origin note that is missing quote context.
 *
 * @param array<string,mixed> $post
 * @return array{ok:bool,updated?:bool,error?:string}
 */
function ap_bsky_repair_imported_note_quote(string $noteId, array $post, int $ownerUserId = 0): array
{
    $noteId = rtrim(trim($noteId), '/');
    $quoteUrl = ap_bsky_quote_target_url_for_import($post, $ownerUserId);
    if ($noteId === '' || $quoteUrl === null || $quoteUrl === '') {
        return ['ok' => true, 'updated' => false];
    }
    try {
        $st = ap_db()->prepare('SELECT raw_create_json, kind FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
        $st->execute([$noteId, $noteId . '/']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'Note missing'];
        }
        $create = json_decode((string) ($row['raw_create_json'] ?? ''), true);
        if (!is_array($create) || !is_array($create['object'] ?? null)) {
            return ['ok' => false, 'error' => 'Bad create JSON'];
        }
        $obj = &$create['object'];
        $hasQuote = false;
        foreach (['quote', 'quoteUri', 'quoteUrl', '_misskey_quote'] as $k) {
            if (!empty($obj[$k]) && is_string($obj[$k]) && str_starts_with($obj[$k], 'https://')) {
                $hasQuote = true;
                break;
            }
        }
        if ($hasQuote) {
            return ['ok' => true, 'updated' => false];
        }
        $obj['quote'] = $quoteUrl;
        $obj['quoteUri'] = $quoteUrl;
        $obj['_misskey_quote'] = $quoteUrl;
        $tag = [
            'type' => 'Link',
            'mediaType' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
            'rel' => 'https://misskey-hub.net/ns#_misskey_quote',
            'href' => $quoteUrl,
        ];
        if (!isset($obj['tag']) || !is_array($obj['tag'])) {
            $obj['tag'] = [$tag];
        } else {
            $tags = isset($obj['tag']['type']) || isset($obj['tag']['href']) ? [$obj['tag']] : $obj['tag'];
            $tags[] = $tag;
            $obj['tag'] = $tags;
        }
        $raw = json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $up = ap_db()->prepare(
            "UPDATE outbox_notes SET raw_create_json = ?, kind = 'quote' WHERE id = ? OR id = ?"
        );
        $up->execute([$raw, $noteId, $noteId . '/']);
        // Refresh compose summary so Home cards can show QT text without AppView.
        $qPrev = function_exists('ap_bsky_quote_preview') ? ap_bsky_quote_preview($post) : null;
        if (is_array($qPrev) && function_exists('ap_metrics_record')) {
            $handle = trim((string) ($qPrev['handle'] ?? ''));
            $qText = trim((string) ($qPrev['text'] ?? ''));
            $commentary = trim((string) (($post['record']['text'] ?? '') ?: ''));
            $summary = $commentary;
            if ($qText !== '' || $handle !== '') {
                $summary = ($commentary !== '' ? $commentary . "\n\n" : '')
                    . '↪ QT' . ($handle !== '' ? ' @' . $handle : '') . ': '
                    . ($qText !== '' ? $qText : $quoteUrl);
            }
            // Best-effort: update latest compose event summary for this object.
            try {
                $est = ap_db()->prepare(
                    "UPDATE events SET summary = ? WHERE id = (
                        SELECT id FROM events
                        WHERE object_id = ? AND action_taken = 'compose'
                        ORDER BY id DESC LIMIT 1
                     )"
                );
                $est->execute([$summary, $noteId]);
            } catch (Throwable $e) {
                // ignore
            }
        }
        return ['ok' => true, 'updated' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Import a Bluesky-native post (authored by the connected account) as a local
 * VAAK outbox note for Your Posts + HTML profile. Does NOT federate Create.
 *
 * @param array<string,mixed> $post PostView
 * @return array{ok:bool,skipped?:bool,error?:string,note_id?:string,created?:bool}
 */
function ap_bsky_import_own_post_as_local(int $ownerUserId, array $post, int $depth = 0): array
{
    if ($ownerUserId < 1 || $depth > 4) {
        return ['ok' => false, 'error' => 'Invalid import'];
    }
    $sess = ap_bsky_session_row($ownerUserId);
    if ($sess === null) {
        return ['ok' => true, 'skipped' => true, 'error' => 'No Bluesky session'];
    }
    $myDid = trim((string) ($sess['did'] ?? ''));
    $author = is_array($post['author'] ?? null) ? $post['author'] : [];
    $authorDid = trim((string) ($author['did'] ?? ''));
    if ($myDid === '' || $authorDid === '' || $authorDid !== $myDid) {
        return ['ok' => true, 'skipped' => true, 'error' => 'Not own post'];
    }
    $uri = trim((string) ($post['uri'] ?? ''));
    $cid = trim((string) ($post['cid'] ?? ''));
    if (!preg_match('~^at://([^/]+)/app\.bsky\.feed\.post/([^/\s]+)$~', $uri)) {
        return ['ok' => true, 'skipped' => true, 'error' => 'Not a feed post'];
    }
    // Already dual-published / previously imported.
    $existing = ap_bsky_local_note_id_for_at_uri($uri, $ownerUserId);
    if (is_string($existing) && str_starts_with($existing, 'https://')) {
        $existing = rtrim($existing, '/');
        $repaired = ap_bsky_repair_imported_note_quote($existing, $post, $ownerUserId);
        return [
            'ok' => true,
            'skipped' => true,
            'note_id' => $existing,
            'created' => false,
            'repaired_quote' => !empty($repaired['ok']) && !empty($repaired['updated']),
        ];
    }
    $record = is_array($post['record'] ?? null) ? $post['record'] : [];
    if (!empty($record['fediverseId']) && is_string($record['fediverseId'])
        && str_starts_with($record['fediverseId'], 'https://mkultra.monster/')) {
        // VAAK→Bluesky mirror — never create a second local twin.
        ap_bsky_crosspost_save(rtrim($record['fediverseId'], '/'), $uri, $cid !== '' ? $cid : null, $ownerUserId);
        return ['ok' => true, 'skipped' => true, 'note_id' => rtrim($record['fediverseId'], '/'), 'created' => false];
    }

    $actorId = ap_db_owner_actor_id_for_user_id($ownerUserId);
    if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
        return ['ok' => false, 'error' => 'Missing local actor'];
    }
    $noteId = ap_bsky_local_note_id_for_import($actorId, $uri);
    try {
        $st = ap_db()->prepare('SELECT id, raw_create_json, kind FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
        $st->execute([$noteId, $noteId . '/']);
        $existingRow = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($existingRow) && !empty($existingRow['id'])) {
            ap_bsky_crosspost_save($noteId, $uri, $cid !== '' ? $cid : null, $ownerUserId);
            // Repair quote context on earlier imports that dropped the embed.
            $repaired = ap_bsky_repair_imported_note_quote($noteId, $post, $ownerUserId);
            return [
                'ok' => true,
                'skipped' => true,
                'note_id' => $noteId,
                'created' => false,
                'repaired_quote' => !empty($repaired['ok']) && !empty($repaired['updated']),
            ];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    // Ensure parent self-thread notes exist first (HTML profile reply threading).
    $inReplyTo = null;
    $reply = is_array($record['reply'] ?? null) ? $record['reply'] : null;
    $parentUri = is_array($reply['parent'] ?? null) ? trim((string) ($reply['parent']['uri'] ?? '')) : '';
    if (str_starts_with($parentUri, 'at://')) {
        $parentNote = ap_bsky_local_note_id_for_at_uri($parentUri, $ownerUserId);
        if (!is_string($parentNote) || !str_starts_with($parentNote, 'https://')) {
            $parentItem = ap_bsky_post_item_by_uri($parentUri);
            $parentPost = is_array($parentItem['post'] ?? null) ? $parentItem['post'] : null;
            if (is_array($parentPost)) {
                $parentImp = ap_bsky_import_own_post_as_local($ownerUserId, $parentPost, $depth + 1);
                if (!empty($parentImp['note_id']) && is_string($parentImp['note_id'])) {
                    $parentNote = $parentImp['note_id'];
                }
            }
        }
        if (is_string($parentNote) && str_starts_with($parentNote, 'https://')) {
            $inReplyTo = rtrim($parentNote, '/');
        }
    }

    $text = trim((string) ($record['text'] ?? ''));
    $published = trim((string) ($record['createdAt'] ?? ($post['indexedAt'] ?? '')));
    if ($published === '') {
        $published = gmdate('c');
    }
    $contentHtml = function_exists('ap_plain_text_to_html')
        ? ap_plain_text_to_html($text !== '' ? $text : '')
        : ('<p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>');
    if ($text === '' && $contentHtml === '<p></p>') {
        $contentHtml = '<p></p>';
    }

    $attachments = [];
    if (function_exists('ap_bsky_post_image_urls')) {
        foreach (ap_bsky_post_image_urls($post) as $imgUrl) {
            if (!is_string($imgUrl) || !str_starts_with($imgUrl, 'https://')) {
                continue;
            }
            $attachments[] = [
                'type' => 'Document',
                'mediaType' => 'image/jpeg',
                'url' => $imgUrl,
                'name' => '',
            ];
        }
    }
    if ($attachments === [] && function_exists('ap_bsky_post_video_media')) {
        $vids = ap_bsky_post_video_media($post);
        if (is_array($vids)) {
            foreach ($vids as $vid) {
                if (!is_array($vid)) {
                    continue;
                }
                $vurl = (string) ($vid['url'] ?? '');
                if (!str_starts_with($vurl, 'https://')) {
                    continue;
                }
                $attachments[] = [
                    'type' => 'Document',
                    'mediaType' => (string) ($vid['mediaType'] ?? 'video/mp4'),
                    'url' => $vurl,
                    'name' => '',
                    'thumbnail' => str_starts_with((string) ($vid['thumbnail'] ?? ''), 'https://')
                        ? (string) $vid['thumbnail']
                        : null,
                ];
            }
        }
    }

    $createId = $actorId . '/creates/' . substr(hash('sha256', 'bsky-import:' . $uri), 0, 16);
    $to = ['https://www.w3.org/ns/activitystreams#Public'];
    $cc = [$actorId . '/followers'];
    $note = [
        'id' => $noteId,
        'type' => 'Note',
        'attributedTo' => $actorId,
        'content' => $contentHtml,
        'published' => $published,
        'to' => $to,
        'cc' => $cc,
        'url' => $noteId,
        'vaakOrigin' => 'bluesky',
    ];
    if ($inReplyTo !== null) {
        $note['inReplyTo'] = $inReplyTo;
    }
    if ($attachments !== []) {
        $note['attachment'] = $attachments;
    }
    $kind = 'compose';
    $quoteUrl = ap_bsky_quote_target_url_for_import($post, $ownerUserId);
    if (is_string($quoteUrl) && $quoteUrl !== '') {
        $note['quote'] = $quoteUrl;
        $note['quoteUri'] = $quoteUrl;
        $note['_misskey_quote'] = $quoteUrl;
        $tag = [
            'type' => 'Link',
            'mediaType' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
            'rel' => 'https://misskey-hub.net/ns#_misskey_quote',
            'href' => $quoteUrl,
        ];
        $note['tag'] = [$tag];
        $kind = 'quote';
    }
    if (function_exists('ap_note_attach_bsky_proxy')) {
        $note = ap_note_attach_bsky_proxy($note, $uri, $cid !== '' ? $cid : null);
    } else {
        $note['blueskyUri'] = $uri;
        if ($cid !== '') {
            $note['blueskyCid'] = $cid;
        }
    }

    $create = [
        'id' => $createId,
        'type' => 'Create',
        'actor' => $actorId,
        'published' => $published,
        'to' => $to,
        'cc' => $cc,
        'object' => $note,
    ];
    if (function_exists('ap_create_finalize')) {
        $create = ap_create_finalize($create);
    }

    try {
        ap_outbox_store([
            'id' => $noteId,
            'create_id' => $createId,
            'published' => $published,
            'content' => $contentHtml !== '' ? $contentHtml : '<p></p>',
            'in_reply_to' => $inReplyTo,
            'to' => $to,
            'cc' => $cc,
            'raw_create_json' => json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'kind' => $kind,
            'visibility' => 'public',
        ]);
        $replyLocalId = null;
        if ($inReplyTo !== null && function_exists('ap_masto_status_by_note_id')) {
            $prow = ap_masto_status_by_note_id($inReplyTo);
            if (is_array($prow)) {
                $replyLocalId = (int) ($prow['local_id'] ?? 0) ?: null;
            }
        }
        if (function_exists('ap_masto_status_register')) {
            ap_masto_status_register(
                $noteId,
                $createId,
                $published,
                $text,
                $replyLocalId,
                '',
                false,
                'public'
            );
        }
        ap_bsky_crosspost_save($noteId, $uri, $cid !== '' ? $cid : null, $ownerUserId);
        ap_bsky_post_link_upsert($uri, $cid !== '' ? $cid : null, $noteId, $noteId);
        if (function_exists('ap_metrics_record')) {
            ap_metrics_record('Create', $actorId, $noteId, null, strlen($contentHtml), 'bsky_import', $text);
        }
        // Intentionally no ActivityPub fanout — origin is Bluesky.
        return ['ok' => true, 'note_id' => $noteId, 'created' => true];
    } catch (Throwable $e) {
        error_log('[ap-bsky] import_own_post: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Import Bluesky-native posts from a feed page for the connected account.
 *
 * @param list<array<string,mixed>> $feed
 * @return array{imported:int,skipped:int,errors:int}
 */
function ap_bsky_import_own_feed_as_local(int $ownerUserId, array $feed): array
{
    $stats = ['imported' => 0, 'skipped' => 0, 'errors' => 0];
    if ($ownerUserId < 1 || $feed === []) {
        return $stats;
    }
    // Oldest first so parents exist before replies when possible.
    $posts = [];
    foreach ($feed as $item) {
        if (!is_array($item)) {
            continue;
        }
        $post = is_array($item['post'] ?? null) ? $item['post'] : (isset($item['uri']) ? $item : null);
        if (!is_array($post)) {
            continue;
        }
        $posts[] = $post;
    }
    usort($posts, static function (array $a, array $b): int {
        $ta = strtotime((string) (($a['record']['createdAt'] ?? null) ?: ($a['indexedAt'] ?? ''))) ?: 0;
        $tb = strtotime((string) (($b['record']['createdAt'] ?? null) ?: ($b['indexedAt'] ?? ''))) ?: 0;
        return $ta <=> $tb;
    });
    foreach ($posts as $post) {
        $res = ap_bsky_import_own_post_as_local($ownerUserId, $post);
        if (!empty($res['created'])) {
            $stats['imported']++;
        } elseif (!empty($res['skipped'])) {
            $stats['skipped']++;
        } elseif (empty($res['ok'])) {
            $stats['errors']++;
        } else {
            $stats['skipped']++;
        }
    }
    return $stats;
}

/**
 * Recent cached Bluesky posts for Home mix (posts this owner last warmed/saw).
 *
 * @param ?string $beforeIndexedAt Exclusive upper bound (ISO-8601) for infinite-scroll extend.
 * @return list<array{post:array,reason?:?array,bsky_uri:string,indexed_at:?string,fediverse_id:?string}>
 */
function ap_bsky_posts_for_home(
    int $ownerUserId,
    int $limit = 40,
    ?string $excludeAuthorDid = null,
    ?string $beforeIndexedAt = null,
    ?string $afterIndexedAt = null
): array {
    if ($ownerUserId < 1) {
        return [];
    }
    ap_bsky_posts_migrate();
    $limit = max(1, min(120, $limit));
    try {
        $sql = 'SELECT p.bsky_uri, p.raw_json, p.reason_json, p.indexed_at, p.author_did,
                       l.fediverse_id
                FROM bsky_posts p
                LEFT JOIN bsky_post_links l ON l.bsky_uri = p.bsky_uri
                WHERE p.owner_user_id = ?
                  AND p.text IS NOT NULL';
        $bind = [$ownerUserId];
        if (is_string($excludeAuthorDid) && str_starts_with($excludeAuthorDid, 'did:')) {
            $sql .= ' AND p.author_did <> ?';
            $bind[] = $excludeAuthorDid;
        }
        $beforeIndexedAt = is_string($beforeIndexedAt) ? trim($beforeIndexedAt) : '';
        if ($beforeIndexedAt !== '') {
            $sql .= ' AND p.indexed_at < ?';
            $bind[] = $beforeIndexedAt;
        }
        $afterIndexedAt = is_string($afterIndexedAt) ? trim($afterIndexedAt) : '';
        if ($afterIndexedAt !== '') {
            $sql .= ' AND p.indexed_at > ?';
            $bind[] = $afterIndexedAt;
        }
        $sql .= ' ORDER BY p.indexed_at DESC, p.updated_at DESC LIMIT ?';
        $bind[] = $limit;
        $st = ap_db()->prepare($sql);
        $st->execute($bind);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $raw = is_string($row['raw_json'] ?? null) ? json_decode((string) $row['raw_json'], true) : null;
            if (!is_array($raw)) {
                continue;
            }
            // Skip empty stubs
            $text = trim((string) ($raw['record']['text'] ?? ''));
            $hasEmbed = !empty($raw['embed']);
            if ($text === '' && !$hasEmbed) {
                continue;
            }
            $item = [
                'post' => $raw,
                'bsky_uri' => (string) ($row['bsky_uri'] ?? $raw['uri'] ?? ''),
                'indexed_at' => isset($row['indexed_at']) ? (string) $row['indexed_at'] : null,
                'fediverse_id' => isset($row['fediverse_id']) && is_string($row['fediverse_id'])
                    ? rtrim((string) $row['fediverse_id'], '/')
                    : null,
            ];
            if (!empty($row['reason_json'])) {
                $reason = json_decode((string) $row['reason_json'], true);
                if (is_array($reason)) {
                    $item['reason'] = $reason;
                }
            }
            $out[] = $item;
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[ap-bsky] posts_for_home: ' . $e->getMessage());
        return [];
    }
}

/**
 * Cheap Home-rank keys (uri + time + dual-publish twin). No JSON decode.
 *
 * @return list<array{uri:string,indexed_at:string,fediverse_id:string,author_did:string}>
 */
function ap_bsky_home_rank_keys(
    int $ownerUserId,
    int $limit = 80,
    ?string $excludeAuthorDid = null
): array {
    if ($ownerUserId < 1) {
        return [];
    }
    ap_bsky_posts_migrate();
    $limit = max(1, min(120, $limit));
    try {
        $sql = 'SELECT p.bsky_uri, p.indexed_at, p.author_did, l.fediverse_id
                FROM bsky_posts p
                LEFT JOIN bsky_post_links l ON l.bsky_uri = p.bsky_uri
                WHERE p.owner_user_id = ?
                  AND p.text IS NOT NULL';
        $bind = [$ownerUserId];
        if (is_string($excludeAuthorDid) && str_starts_with($excludeAuthorDid, 'did:')) {
            $sql .= ' AND p.author_did <> ?';
            $bind[] = $excludeAuthorDid;
        }
        $sql .= ' ORDER BY p.indexed_at DESC, p.updated_at DESC LIMIT ?';
        $bind[] = $limit;
        $st = ap_db()->prepare($sql);
        $st->execute($bind);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $uri = trim((string) ($row['bsky_uri'] ?? ''));
            if ($uri === '' || !str_starts_with($uri, 'at://')) {
                continue;
            }
            $out[] = [
                'uri' => $uri,
                'indexed_at' => (string) ($row['indexed_at'] ?? ''),
                'fediverse_id' => rtrim((string) ($row['fediverse_id'] ?? ''), '/'),
                'author_did' => (string) ($row['author_did'] ?? ''),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[ap-bsky] home_rank_keys: ' . $e->getMessage());
        return [];
    }
}

/**
 * Load one cached Bluesky post as a FeedViewPost-shaped item.
 *
 * @return array{post:array,reason?:array,bsky_uri:string}|null
 */
function ap_bsky_post_item_by_uri(string $bskyUri): ?array
{
    $bskyUri = trim($bskyUri);
    if (!str_starts_with($bskyUri, 'at://')) {
        return null;
    }
    ap_bsky_posts_migrate();
    try {
        $st = ap_db()->prepare(
            'SELECT bsky_uri, raw_json, reason_json FROM bsky_posts WHERE bsky_uri = ? LIMIT 1'
        );
        $st->execute([$bskyUri]);
        $row = $st->fetch();
        if (!is_array($row)) {
            return null;
        }
        $raw = is_string($row['raw_json'] ?? null) ? json_decode((string) $row['raw_json'], true) : null;
        if (!is_array($raw)) {
            return null;
        }
        $item = ['post' => $raw, 'bsky_uri' => (string) ($row['bsky_uri'] ?? $bskyUri)];
        if (!empty($row['reason_json'])) {
            $reason = json_decode((string) $row['reason_json'], true);
            if (is_array($reason)) {
                $item['reason'] = $reason;
            }
        }
        return $item;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Load cached posts for an author (newest first).
 *
 * @return list<array{post:array,reason?:array}>
 */
function ap_bsky_posts_for_author(string $authorDid, int $limit = 20): array
{
    $authorDid = trim($authorDid);
    if ($authorDid === '' || !str_starts_with($authorDid, 'did:')) {
        return [];
    }
    ap_bsky_posts_migrate();
    $limit = max(1, min(50, $limit));
    try {
        $st = ap_db()->prepare(
            'SELECT raw_json, reason_json FROM bsky_posts
             WHERE author_did = ?
             ORDER BY indexed_at DESC NULLS LAST, updated_at DESC
             LIMIT ?'
        );
        $st->execute([$authorDid, $limit]);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $raw = is_string($row['raw_json'] ?? null) ? json_decode((string) $row['raw_json'], true) : null;
            if (!is_array($raw)) {
                continue;
            }
            $item = ['post' => $raw];
            if (!empty($row['reason_json'])) {
                $reason = json_decode((string) $row['reason_json'], true);
                if (is_array($reason)) {
                    $item['reason'] = $reason;
                }
            }
            $out[] = $item;
        }
        return $out;
    } catch (Throwable $e) {
        // SQLite may not like NULLS LAST
        try {
            $st = ap_db()->prepare(
                'SELECT raw_json, reason_json FROM bsky_posts
                 WHERE author_did = ?
                 ORDER BY indexed_at DESC, updated_at DESC
                 LIMIT ?'
            );
            $st->execute([$authorDid, $limit]);
            $out = [];
            foreach ($st->fetchAll() ?: [] as $row) {
                $raw = is_string($row['raw_json'] ?? null) ? json_decode((string) $row['raw_json'], true) : null;
                if (!is_array($raw)) {
                    continue;
                }
                $item = ['post' => $raw];
                if (!empty($row['reason_json'])) {
                    $reason = json_decode((string) $row['reason_json'], true);
                    if (is_array($reason)) {
                        $item['reason'] = $reason;
                    }
                }
                $out[] = $item;
            }
            return $out;
        } catch (Throwable $e2) {
            return [];
        }
    }
}

/** Cached mixed-author posts for a VAAK custom list; refreshes are queued lazily. */
function ap_bsky_posts_for_authors(array $authorDids, int $ownerUserId, int $limit = 40): array
{
    $dids = array_values(array_unique(array_filter(array_map('trim', $authorDids), static fn(string $did): bool => str_starts_with($did, 'did:'))));
    if ($ownerUserId < 1 || $dids === []) {
        return [];
    }
    $dids = array_slice($dids, 0, 500);
    foreach ($dids as $did) {
        ap_bsky_author_feed_refresh_enqueue($ownerUserId, $did, 180);
    }
    ap_bsky_posts_migrate();
    $limit = max(1, min(100, $limit));
    $ph = implode(',', array_fill(0, count($dids), '?'));
    try {
        $st = ap_db()->prepare("SELECT raw_json, reason_json FROM bsky_posts WHERE author_did IN ($ph) AND raw_json IS NOT NULL ORDER BY indexed_at DESC NULLS LAST, updated_at DESC LIMIT " . (int) ($limit * 3));
        $st->execute($dids);
    } catch (Throwable $e) {
        try {
            $st = ap_db()->prepare("SELECT raw_json, reason_json FROM bsky_posts WHERE author_did IN ($ph) AND raw_json IS NOT NULL ORDER BY indexed_at DESC, updated_at DESC LIMIT " . (int) ($limit * 3));
            $st->execute($dids);
        } catch (Throwable $e2) {
            return [];
        }
    }
    $items = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        $post = is_string($row['raw_json'] ?? null) ? json_decode((string) $row['raw_json'], true) : null;
        if (!is_array($post)) {
            continue;
        }
        $item = ['post' => $post];
        $reason = is_string($row['reason_json'] ?? null) ? json_decode((string) $row['reason_json'], true) : null;
        if (is_array($reason)) {
            $item['reason'] = $reason;
        }
        $items[] = $item;
    }
    if (function_exists('ap_bsky_filter_hidden_authors')) {
        $items = ap_bsky_filter_hidden_authors($ownerUserId, $items);
    }
    return array_slice($items, 0, $limit);
}

function ap_bsky_graph_sync_list(int $ownerUserId, string $kind): array
{
    if ($ownerUserId < 1 || $kind === '') {
        return [];
    }
    ap_bsky_graph_sync_migrate();
    try {
        $st = ap_db()->prepare(
            'SELECT * FROM bsky_graph_sync WHERE owner_user_id = ? AND kind = ? ORDER BY updated_at DESC'
        );
        $st->execute([$ownerUserId, $kind]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array{actor_id:string,username:string,host:string,followed_at:string,bsky_did?:string}> */
function ap_bsky_admin_following_rows(int $ownerUserId): array
{
    $out = [];
    foreach (ap_bsky_graph_sync_list($ownerUserId, 'follow') as $row) {
        $did = trim((string) ($row['target_did'] ?? ''));
        if (!str_starts_with($did, 'did:')) {
            continue;
        }
        $handle = '';
        if (function_exists('ap_bsky_actor_profile_cache_get')) {
            $cached = ap_bsky_actor_profile_cache_get($did, $ownerUserId);
            $handle = trim((string) (($cached['profile']['handle'] ?? '') ?: ''));
        }
        $actor = function_exists('ap_bsky_actor_profile_url')
            ? ap_bsky_actor_profile_url($handle !== '' ? $handle : $did)
            : ('https://bsky.app/profile/' . rawurlencode($handle !== '' ? $handle : $did));
        $out[] = [
            'actor_id' => $actor,
            'username' => $handle !== '' ? $handle : $did,
            'host' => 'bsky.app',
            'followed_at' => (string) ($row['updated_at'] ?? gmdate('c')),
            'bsky_did' => $did,
        ];
        if ($handle === '' && function_exists('ap_bsky_actor_refresh_enqueue')) {
            ap_bsky_actor_refresh_enqueue($ownerUserId, $did);
        }
    }
    return $out;
}

/** Bluesky accounts that follow this VAAK user (notification ingest, not AP followers table). */
function ap_bsky_admin_follower_rows(int $ownerUserId, string $ownerActorId): array
{
    $out = [];
    $action = defined('AP_BSKY_FOLLOW_ACTION') ? AP_BSKY_FOLLOW_ACTION : 'bsky_follow';
    $ownerActorId = rtrim($ownerActorId, '/');
    if ($ownerActorId === '') {
        return [];
    }
    try {
        $st = ap_db()->prepare(
            "SELECT actor_id, created_at FROM events
             WHERE type = 'Follow' AND action_taken = ?
               AND (target_actor = ? OR target_actor = ?)
             ORDER BY created_at DESC LIMIT 500"
        );
        $st->execute([$action, $ownerActorId, $ownerActorId . '/']);
        $seen = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $aid = rtrim((string) ($row['actor_id'] ?? ''), '/');
            if ($aid === '' || isset($seen[$aid])) {
                continue;
            }
            $seen[$aid] = true;
            $handle = '';
            if (preg_match('~^https://bsky\.app/profile/([^/?#]+)~i', $aid, $hm)) {
                $handle = rawurldecode($hm[1]);
                if (str_starts_with($handle, 'did:')) {
                    $handle = '';
                }
            }
            $out[] = [
                'actor_id' => $aid,
                'username' => $handle,
                'host' => 'bsky.app',
                'followed_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

function ap_bsky_merge_follow_rows(array $base, array $extra): array
{
    $seen = [];
    foreach ($base as $row) {
        $aid = rtrim((string) ($row['actor_id'] ?? ''), '/');
        if ($aid !== '') {
            $seen[$aid] = true;
        }
    }
    foreach ($extra as $row) {
        $aid = rtrim((string) ($row['actor_id'] ?? ''), '/');
        if ($aid === '' || isset($seen[$aid])) {
            continue;
        }
        $seen[$aid] = true;
        $base[] = $row;
    }
    usort($base, static function (array $a, array $b): int {
        $ta = strtotime((string) ($a['followed_at'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['followed_at'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });
    return $base;
}

/** Queue connected-profile public counts without making profile HTML wait on XRPC. */
function ap_bsky_profile_counts_enqueue(int $ownerUserId, string $handle): bool
{
    $handle = ltrim(trim($handle), '@');
    if ($ownerUserId < 1 || $handle === '' || !preg_match('/^[a-z0-9][a-z0-9._:-]*$/i', $handle)
        || str_ends_with(strtolower($handle), '.ap.brid.gy') || str_ends_with(strtolower($handle), '.brid.gy')
        || !ap_bsky_actor_refresh_migrate()) {
        return false;
    }
    $actorRef = '__vaak_profile_counts__:' . strtolower($handle);
    $now = gmdate('c');
    try {
        $st = ap_db()->prepare('SELECT status, queued_at FROM bsky_actor_refresh_queue WHERE owner_user_id = ? AND actor_ref = ? LIMIT 1');
        $st->execute([$ownerUserId, $actorRef]);
        $existing = $st->fetch();
        if (is_array($existing)) {
            $status = (string) ($existing['status'] ?? '');
            $queuedAt = strtotime((string) ($existing['queued_at'] ?? '')) ?: 0;
            if (in_array($status, ['pending', 'processing'], true)
                || ($status === 'succeeded' && $queuedAt > time() - 120)) {
                return true;
            }
        }
        $up = ap_db()->prepare("INSERT INTO bsky_actor_refresh_queue (owner_user_id, actor_ref, status, queued_at, next_attempt_at, attempts) VALUES (?, ?, 'pending', ?, ?, 0) ON CONFLICT (owner_user_id, actor_ref) DO UPDATE SET status = 'pending', queued_at = excluded.queued_at, next_attempt_at = excluded.next_attempt_at, attempts = 0, locked_at = NULL, last_error = NULL WHERE bsky_actor_refresh_queue.status IN ('succeeded', 'failed')");
        $up->execute([$ownerUserId, $actorRef, $now, $now]);
        return true;
    } catch (Throwable $e) {
        error_log('[ap-bsky] profile counts enqueue failed');
        return false;
    }
}

/** Coalesce a fresh authenticated pull of an author's feed onto the durable profile-refresh queue. */
function ap_bsky_author_feed_refresh_enqueue(int $ownerUserId, string $authorDid, int $freshForSec = 180, ?PDO $db = null): void
{
    $authorDid = trim($authorDid);
    if ($ownerUserId < 1 || !str_starts_with($authorDid, 'did:')) {
        return;
    }
    $db ??= ap_db();
    ap_bsky_posts_migrate($db);
    $freshForSec = max(30, min(3600, $freshForSec));
    try {
        $st = $db->prepare('SELECT MAX(seen_at) FROM bsky_posts WHERE author_did = ?');
        $st->execute([$authorDid]);
        $lastPostSeen = strtotime((string) ($st->fetchColumn() ?: '')) ?: 0;
        if ($lastPostSeen > time() - $freshForSec) {
            return;
        }
        // An empty author feed still needs a short cooldown after a successful pull.
        if (ap_bsky_actor_refresh_migrate($db)) {
            $st = $db->prepare("SELECT status, queued_at FROM bsky_actor_refresh_queue WHERE owner_user_id = ? AND actor_ref = ? LIMIT 1");
            $st->execute([$ownerUserId, $authorDid]);
            $job = $st->fetch();
            if (is_array($job)) {
                if (in_array((string) ($job['status'] ?? ''), ['pending', 'processing'], true)) {
                    return;
                }
                $lastAttempt = strtotime((string) ($job['queued_at'] ?? '')) ?: 0;
                if ((string) ($job['status'] ?? '') === 'succeeded' && $lastAttempt > time() - $freshForSec) {
                    return;
                }
            }
        }
        ap_bsky_actor_refresh_enqueue($ownerUserId, $authorDid, true, $db);
    } catch (Throwable $e) {
        // Keep a background-refresh failure from breaking profile/timeline rendering.
    }
}

/**
 * Prune durable Bluesky posts older than cutoff (ISO-8601), same window as events.
 *
 * @return array{posts:int,links:int}
 */
function ap_bsky_posts_prune(string $cutoffIso, bool $dryRun = false): array
{
    ap_bsky_posts_migrate();
    ap_bsky_post_links_migrate();
    $posts = 0;
    $links = 0;
    try {
        $st = ap_db()->prepare('SELECT COUNT(*) FROM bsky_posts WHERE seen_at < ?');
        $st->execute([$cutoffIso]);
        $posts = (int) $st->fetchColumn();
        if ($posts > 0 && !$dryRun) {
            $del = ap_db()->prepare('DELETE FROM bsky_posts WHERE seen_at < ?');
            $del->execute([$cutoffIso]);
            $posts = $del->rowCount();
        }
    } catch (Throwable $e) {
        error_log('[ap-bsky] posts_prune: ' . $e->getMessage());
        $posts = 0;
    }
    // Orphan link rows: not in posts and not a local crosspost map.
    try {
        $sql = 'SELECT COUNT(*) FROM bsky_post_links l
                WHERE NOT EXISTS (SELECT 1 FROM bsky_posts p WHERE p.bsky_uri = l.bsky_uri)
                  AND NOT EXISTS (SELECT 1 FROM bsky_crossposts c WHERE c.bsky_uri = l.bsky_uri)
                  AND l.seen_at < ?';
        $st = ap_db()->prepare($sql);
        $st->execute([$cutoffIso]);
        $links = (int) $st->fetchColumn();
        if ($links > 0 && !$dryRun) {
            $del = ap_db()->prepare(
                'DELETE FROM bsky_post_links
                 WHERE bsky_uri IN (
                   SELECT l.bsky_uri FROM bsky_post_links l
                   WHERE NOT EXISTS (SELECT 1 FROM bsky_posts p WHERE p.bsky_uri = l.bsky_uri)
                     AND NOT EXISTS (SELECT 1 FROM bsky_crossposts c WHERE c.bsky_uri = l.bsky_uri)
                     AND l.seen_at < ?
                 )'
            );
            // Fallback simpler delete for SQLite
            try {
                $del->execute([$cutoffIso]);
                $links = $del->rowCount();
            } catch (Throwable $e) {
                $del2 = ap_db()->prepare(
                    'DELETE FROM bsky_post_links WHERE seen_at < ?
                     AND bsky_uri NOT IN (SELECT bsky_uri FROM bsky_posts)
                     AND bsky_uri NOT IN (SELECT bsky_uri FROM bsky_crossposts WHERE bsky_uri IS NOT NULL)'
                );
                $del2->execute([$cutoffIso]);
                $links = $del2->rowCount();
            }
        }
    } catch (Throwable $e) {
        error_log('[ap-bsky] post_links orphan prune: ' . $e->getMessage());
        $links = 0;
    }
    return ['posts' => $posts, 'links' => $links];
}

/**
 * Index FEP-fffd / Wafrn blueskyUri markers from an inbound AS2 Note.
 *
 * @param array<string,mixed> $note
 */
function ap_bsky_index_as2_note_links(array $note): void
{
    $apId = '';
    if (isset($note['id']) && is_string($note['id']) && str_starts_with($note['id'], 'https://')) {
        $apId = rtrim($note['id'], '/');
    }
    $bskyUri = '';
    $bskyCid = null;
    if (!empty($note['blueskyUri']) && is_string($note['blueskyUri']) && str_starts_with($note['blueskyUri'], 'at://')) {
        $bskyUri = trim($note['blueskyUri']);
    }
    if (!empty($note['blueskyCid']) && is_string($note['blueskyCid'])) {
        $bskyCid = trim($note['blueskyCid']);
    }
    $urlField = $note['url'] ?? null;
    $urls = [];
    if (is_string($urlField)) {
        $urls[] = $urlField;
    } elseif (is_array($urlField)) {
        $urls = $urlField;
    }
    foreach ($urls as $u) {
        if (is_string($u) && str_starts_with($u, 'at://') && $bskyUri === '') {
            $bskyUri = $u;
        }
        if (!is_array($u)) {
            continue;
        }
        $href = trim((string) ($u['href'] ?? ''));
        $rel = $u['rel'] ?? null;
        $rels = is_array($rel) ? $rel : [$rel];
        $isProxy = false;
        foreach ($rels as $r) {
            if (is_string($r) && ($r === 'alternate' || $r === 'canonical')) {
                $isProxy = true;
                break;
            }
        }
        // Wafrn / Bridgy often omit rel; still accept at:// Link hrefs.
        if ($href !== '' && str_starts_with($href, 'at://') && ($isProxy || $bskyUri === '')) {
            $bskyUri = $href;
        }
    }
    if ($bskyUri === '') {
        return;
    }
    ap_bsky_post_link_upsert($bskyUri, $bskyCid, $apId !== '' ? $apId : null, $apId !== '' ? $apId : null);
}

/**
 * @return array{ok:bool,error?:string,preferences?:list<array>}
 */
function ap_bsky_get_preferences(int $ownerUserId): array
{
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (empty($tok['ok'])) {
            return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Not connected')];
        }
    }
    $row = ap_bsky_session_row($ownerUserId);
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $cachePath = sys_get_temp_dir() . '/vaak-bsky-prefs-' . $ownerUserId . '.json';
    if (is_file($cachePath) && (time() - (int) @filemtime($cachePath)) < 600) {
        $raw = @file_get_contents($cachePath);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($j) && isset($j['preferences']) && is_array($j['preferences'])) {
            return ['ok' => true, 'preferences' => $j['preferences'], 'cached' => true];
        }
    }
    $lastErr = 'getPreferences failed';
    $res = null;
    foreach (ap_bsky_feed_hosts($pds) as $apiHost) {
        $attempt = ap_bsky_xrpc($apiHost, 'app.bsky.actor.getPreferences', 'GET', null, null, (string) $tok['access'], 12);
        if (($attempt['status'] ?? 0) === 401) {
            $tok = ap_bsky_access_token($ownerUserId, true);
            if (empty($tok['ok'])) {
                return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Session expired')];
            }
            $attempt = ap_bsky_xrpc($apiHost, 'app.bsky.actor.getPreferences', 'GET', null, null, (string) $tok['access'], 12);
        }
        if (!empty($attempt['ok']) && is_array($attempt['json'] ?? null)) {
            $res = $attempt;
            break;
        }
        $lastErr = (string) ($attempt['error'] ?? $lastErr);
    }
    if ($res === null) {
        return ['ok' => false, 'error' => $lastErr];
    }
    $prefs = is_array($res['json']['preferences'] ?? null) ? $res['json']['preferences'] : [];
    @file_put_contents($cachePath, json_encode(['preferences' => $prefs], JSON_UNESCAPED_SLASHES), LOCK_EX);
    return ['ok' => true, 'preferences' => $prefs];
}

/**
 * @param list<array> $preferences
 * @return array{
 *   mergeFeedEnabled:bool,
 *   hideReplies:bool,
 *   hideReposts:bool,
 *   hideQuotePosts:bool,
 *   hideRepliesByUnfollowed:bool,
 *   pinned:list<array{id:string,type:string,value:string}>,
 *   savedFeedUris:list<string>
 * }
 */
function ap_bsky_parse_feed_prefs(array $preferences): array
{
    $out = [
        'mergeFeedEnabled' => false,
        'hideReplies' => false,
        'hideReposts' => false,
        'hideQuotePosts' => false,
        'hideRepliesByUnfollowed' => true,
        'pinned' => [],
        'savedFeedUris' => [],
    ];
    $savedV2 = null;
    $savedV1 = null;
    foreach ($preferences as $pref) {
        if (!is_array($pref)) {
            continue;
        }
        $type = (string) ($pref['$type'] ?? '');
        if ($type === 'app.bsky.actor.defs#feedViewPref' && (string) ($pref['feed'] ?? '') === 'home') {
            $out['hideReplies'] = !empty($pref['hideReplies']);
            $out['hideReposts'] = !empty($pref['hideReposts']);
            $out['hideQuotePosts'] = !empty($pref['hideQuotePosts']);
            if (array_key_exists('hideRepliesByUnfollowed', $pref)) {
                $out['hideRepliesByUnfollowed'] = !empty($pref['hideRepliesByUnfollowed']);
            }
            // Unspecced lab flag used by official Bluesky app.
            $out['mergeFeedEnabled'] = !empty($pref['lab_mergeFeedEnabled']);
        }
        if ($type === 'app.bsky.actor.defs#savedFeedsPrefV2') {
            $savedV2 = $pref;
        }
        if ($type === 'app.bsky.actor.defs#savedFeedsPref') {
            $savedV1 = $pref;
        }
    }
    if (is_array($savedV2) && is_array($savedV2['items'] ?? null)) {
        foreach ($savedV2['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $t = (string) ($item['type'] ?? '');
            $v = (string) ($item['value'] ?? '');
            $id = (string) ($item['id'] ?? $v);
            if ($t === '' || $v === '') {
                continue;
            }
            if (!empty($item['pinned'])) {
                $out['pinned'][] = ['id' => $id, 'type' => $t, 'value' => $v];
            }
            if (($t === 'feed' || $t === 'list') && str_starts_with($v, 'at://')) {
                $out['savedFeedUris'][] = $v;
            }
        }
    } elseif (is_array($savedV1)) {
        foreach ((array) ($savedV1['pinned'] ?? []) as $uri) {
            if (!is_string($uri) || !str_starts_with($uri, 'at://')) {
                continue;
            }
            $out['pinned'][] = ['id' => $uri, 'type' => 'feed', 'value' => $uri];
            $out['savedFeedUris'][] = $uri;
        }
        foreach ((array) ($savedV1['saved'] ?? []) as $uri) {
            if (is_string($uri) && str_starts_with($uri, 'at://')) {
                $out['savedFeedUris'][] = $uri;
            }
        }
    }
    $out['savedFeedUris'] = array_values(array_unique($out['savedFeedUris']));
    // Ensure Following tab exists first when timeline pin missing.
    $hasTimeline = false;
    foreach ($out['pinned'] as $p) {
        if (($p['type'] ?? '') === 'timeline') {
            $hasTimeline = true;
            break;
        }
    }
    if (!$hasTimeline) {
        array_unshift($out['pinned'], ['id' => 'following', 'type' => 'timeline', 'value' => 'following']);
    }
    return $out;
}

/**
 * @return array{ok:bool,error?:string,feed?:list<array>,cursor?:?string}
 */
function ap_bsky_get_custom_feed(int $ownerUserId, string $feedUri, int $limit = 40, ?string $cursor = null): array
{
    $feedUri = trim($feedUri);
    if (!str_starts_with($feedUri, 'at://')) {
        return ['ok' => false, 'error' => 'Invalid feed URI'];
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (empty($tok['ok'])) {
            return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Not connected')];
        }
    }
    $row = ap_bsky_session_row($ownerUserId);
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $isList = str_contains($feedUri, '/app.bsky.graph.list/');
    $nsid = $isList ? 'app.bsky.feed.getListFeed' : 'app.bsky.feed.getFeed';
    $query = $isList
        ? ['list' => $feedUri, 'limit' => max(1, min(50, $limit))]
        : ['feed' => $feedUri, 'limit' => max(1, min(50, $limit))];
    if (is_string($cursor) && $cursor !== '') {
        $query['cursor'] = $cursor;
    }
    $lastErr = $nsid . ' failed';
    foreach (ap_bsky_feed_hosts($pds) as $apiHost) {
        $attempt = ap_bsky_xrpc($apiHost, $nsid, 'GET', $query, null, (string) $tok['access'], 15);
        if (($attempt['status'] ?? 0) === 401) {
            $tok = ap_bsky_access_token($ownerUserId, true);
            if (empty($tok['ok'])) {
                return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Session expired')];
            }
            $attempt = ap_bsky_xrpc($apiHost, $nsid, 'GET', $query, null, (string) $tok['access'], 15);
        }
        if (!empty($attempt['ok']) && is_array($attempt['json'] ?? null)) {
            $feed = is_array($attempt['json']['feed'] ?? null) ? $attempt['json']['feed'] : [];
            ap_bsky_index_feed_items($feed, $ownerUserId, 15);
            ap_bsky_schedule_hide_refresh($ownerUserId);
            $feed = ap_bsky_filter_hidden_authors($ownerUserId, $feed);
            return [
                'ok' => true,
                'feed' => $feed,
                'cursor' => isset($attempt['json']['cursor']) && is_string($attempt['json']['cursor'])
                    ? $attempt['json']['cursor'] : null,
                'source' => 'feed',
            ];
        }
        $lastErr = (string) ($attempt['error'] ?? $lastErr);
    }
    return ['ok' => false, 'error' => $lastErr];
}

/** Bluesky feed head cache (mirrors admin TL / Masto JSON head discipline). */
function ap_bsky_tl_cache_dir(): string
{
    $dir = '/var/lib/mkultra/ap/bsky-tl-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $dir = sys_get_temp_dir() . '/vaak-bsky-tl-cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }
    return $dir;
}

function ap_bsky_tl_cache_key(int $ownerUserId, string $feedKey, ?string $cursor, bool $withMerge): string
{
    $c = ($cursor === null || $cursor === '') ? 'head' : ('c_' . substr(hash('sha256', $cursor), 0, 16));
    $m = $withMerge ? 'm1' : 'm0';
    return 'bsky_' . $ownerUserId . '_' . substr(hash('sha256', $feedKey), 0, 20) . '_' . $c . '_' . $m;
}

/**
 * @return array{ts:int,feed:list,cursor:?string,source?:string}|null
 */
function ap_bsky_tl_cache_get(string $key, int $ttlSec = 60): ?array
{
    $path = ap_bsky_tl_cache_dir() . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key) . '.json';
    if (!is_file($path)) {
        return null;
    }
    $age = time() - (int) @filemtime($path);
    if ($age < 0 || $age >= max(15, $ttlSec)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j) || !isset($j['feed']) || !is_array($j['feed'])) {
        return null;
    }
    return $j;
}

function ap_bsky_tl_cache_put(string $key, array $feed, ?string $cursor, string $source = 'home'): void
{
    $path = ap_bsky_tl_cache_dir() . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key) . '.json';
    $payload = [
        'ts' => time(),
        'feed' => $feed,
        'cursor' => $cursor,
        'source' => $source,
    ];
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function ap_bsky_tl_cache_clear_owner(int $ownerUserId): void
{
    $dir = ap_bsky_tl_cache_dir();
    $prefix = 'bsky_' . $ownerUserId . '_';
    foreach (glob($dir . '/' . $prefix . '*.json') ?: [] as $f) {
        @unlink($f);
    }
}

function ap_bsky_hide_set_is_stale(int $ownerUserId, int $ttlSec = 900): bool
{
    $path = sys_get_temp_dir() . '/vaak-bsky-hide-' . $ownerUserId . '.json';
    if (!is_file($path)) {
        return true;
    }
    $age = time() - (int) @filemtime($path);
    return $age < 0 || $age >= max(60, $ttlSec);
}

/** Schedule hide-set refresh after the response (never block first paint). */
function ap_bsky_schedule_hide_refresh(int $ownerUserId): void
{
    static $scheduled = [];
    if ($ownerUserId < 1 || isset($scheduled[$ownerUserId])) {
        return;
    }
    if (!ap_bsky_hide_set_is_stale($ownerUserId)) {
        return;
    }
    $scheduled[$ownerUserId] = true;
    register_shutdown_function(static function () use ($ownerUserId): void {
        try {
            // Ignore client abort; finish hide sync in background of this request.
            if (function_exists('ignore_user_abort')) {
                ignore_user_abort(true);
            }
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            ap_bsky_refresh_hide_set($ownerUserId, false);
        } catch (Throwable $e) {
            error_log('[ap-bsky] deferred hide refresh: ' . $e->getMessage());
        }
    });
}

/**
 * Following feed with hide filters. Saved-feed merge is opt-in (defer for first paint).
 *
 * @param array<string,mixed>|null $prefsPreparsed from ap_bsky_parse_feed_prefs (avoids double prefs fetch)
 * @return array{ok:bool,error?:string,feed?:list<array>,cursor?:?string,source?:string,prefs?:array,cache?:string,merge_pending?:bool}
 */
function ap_bsky_following_feed(
    int $ownerUserId,
    int $limit = 40,
    ?string $cursor = null,
    ?array $prefsPreparsed = null,
    bool $includeMerge = false,
    string $sourceHint = ''
): array {
    $t0 = microtime(true);
    $isHead = ($cursor === null || $cursor === '');
    $prefs = is_array($prefsPreparsed) ? $prefsPreparsed : null;
    if ($prefs === null) {
        $prefsRaw = ap_bsky_get_preferences($ownerUserId);
        $prefs = ap_bsky_parse_feed_prefs(
            !empty($prefsRaw['ok']) && is_array($prefsRaw['preferences'] ?? null)
                ? $prefsRaw['preferences']
                : []
        );
    }
    $wantMerge = $includeMerge && !empty($prefs['mergeFeedEnabled']) && $isHead && ($prefs['savedFeedUris'] ?? []) !== [];
    $cacheKey = ap_bsky_tl_cache_key($ownerUserId, 'following', $isHead ? null : $cursor, $wantMerge);
    if ($isHead) {
        $cached = ap_bsky_tl_cache_get($cacheKey, 60);
        if ($cached !== null) {
            $feed = ap_bsky_filter_hidden_authors($ownerUserId, $cached['feed']);
            ap_bsky_schedule_hide_refresh($ownerUserId);
            return [
                'ok' => true,
                'feed' => array_slice($feed, 0, $limit),
                'cursor' => $cached['cursor'] ?? null,
                'source' => (string) ($cached['source'] ?? 'home'),
                'prefs' => $prefs,
                'cache' => 'hit',
                'merge_pending' => !$includeMerge && !empty($prefs['mergeFeedEnabled']) && ($prefs['savedFeedUris'] ?? []) !== [],
                'timing_ms' => (int) round((microtime(true) - $t0) * 1000),
            ];
        }
    }

    // Author-feed fallback uses a different XRPC + cursor namespace — never feed
    // an author cursor into getTimeline or pagination silently dies.
    $tl = ($sourceHint === 'author')
        ? ap_bsky_get_author_feed($ownerUserId, $limit, $cursor)
        : ap_bsky_get_timeline($ownerUserId, $limit, $cursor);
    if (empty($tl['ok'])) {
        return $tl + ['prefs' => $prefs, 'cache' => 'miss'];
    }
    $feed = is_array($tl['feed'] ?? null) ? $tl['feed'] : [];
    // Durable body cache + link map (sync small window; rest after response).
    ap_bsky_index_feed_items($feed, $ownerUserId, 15);
    $feed = ap_bsky_filter_feed_items($feed, $prefs);
    // Never block paint on hide-set crawl — filter with last-known set; refresh async.
    ap_bsky_schedule_hide_refresh($ownerUserId);
    $feed = ap_bsky_filter_hidden_authors($ownerUserId, $feed);
    // Bluesky → VAAK: if a dual-published post was deleted on Bluesky, federate Delete.
    if ($isHead && function_exists('ap_bsky_reconcile_deleted_crossposts')) {
        try {
            ap_bsky_reconcile_deleted_crossposts($ownerUserId, 12);
        } catch (Throwable $e) {
            error_log('[ap-bsky] delete reconcile: ' . $e->getMessage());
        }
    }

    $mergePending = !$includeMerge && !empty($prefs['mergeFeedEnabled']) && $isHead && ($prefs['savedFeedUris'] ?? []) !== [];

    // Saved-feed merge only when explicitly requested (post-paint partial).
    if ($wantMerge) {
        $samples = [];
        foreach (array_slice($prefs['savedFeedUris'], 0, 4) as $feedUri) {
            $cf = ap_bsky_get_custom_feed($ownerUserId, $feedUri, 8, null);
            if (empty($cf['ok']) || empty($cf['feed'])) {
                continue;
            }
            foreach ($cf['feed'] as $item) {
                if (!is_array($item) || !is_array($item['post'] ?? null)) {
                    continue;
                }
                $item['_vaak_feed_source'] = $feedUri;
                $samples[] = $item;
            }
        }
        if ($samples !== []) {
            $merged = [];
            $si = 0;
            foreach ($feed as $i => $item) {
                $merged[] = $item;
                if ($i >= 8 && (($i - 8) % 5) === 0 && isset($samples[$si])) {
                    $merged[] = $samples[$si];
                    $si++;
                }
            }
            while ($si < count($samples) && count($merged) < $limit + 8) {
                $merged[] = $samples[$si];
                $si++;
            }
            $feed = array_slice($merged, 0, max($limit, count($feed)));
            $feed = ap_bsky_filter_hidden_authors($ownerUserId, $feed);
        }
        $mergePending = false;
    }

    if ($isHead) {
        ap_bsky_tl_cache_put($cacheKey, $feed, isset($tl['cursor']) && is_string($tl['cursor']) ? $tl['cursor'] : null, (string) ($tl['source'] ?? 'home'));
    }

    return [
        'ok' => true,
        'feed' => $feed,
        'cursor' => $tl['cursor'] ?? null,
        'source' => (string) ($tl['source'] ?? 'home'),
        'prefs' => $prefs,
        'cache' => 'miss',
        'merge_pending' => $mergePending,
        'timing_ms' => (int) round((microtime(true) - $t0) * 1000),
    ];
}

/**
 * @param list<array> $feed
 * @param array<string,mixed> $prefs
 * @return list<array>
 */
function ap_bsky_filter_feed_items(array $feed, array $prefs): array
{
    $out = [];
    foreach ($feed as $item) {
        if (!is_array($item) || !is_array($item['post'] ?? null)) {
            continue;
        }
        $post = $item['post'];
        $record = is_array($post['record'] ?? null) ? $post['record'] : [];
        $reason = is_array($item['reason'] ?? null) ? $item['reason'] : null;
        $isRepost = is_array($reason) && str_contains((string) ($reason['$type'] ?? ''), 'reasonRepost');
        if (!empty($prefs['hideReposts']) && $isRepost) {
            continue;
        }
        $hasReply = isset($record['reply']) && is_array($record['reply']);
        if (!empty($prefs['hideReplies']) && $hasReply) {
            continue;
        }
        $embedType = (string) (($post['embed']['$type'] ?? '') ?: ($record['embed']['$type'] ?? ''));
        if (!empty($prefs['hideQuotePosts']) && str_contains($embedType, 'embed.record')) {
            continue;
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * Create app.bsky.feed.like for a strongRef.
 *
 * @param array{uri:string,cid:string} $subject
 * @return array{ok:bool,error?:string,uri?:string,cid?:string}
 */
function ap_bsky_create_like(int $ownerUserId, array $subject, ?string $recordKey = null): array
{
    $uri = (string) ($subject['uri'] ?? '');
    $cid = (string) ($subject['cid'] ?? '');
    if (!str_starts_with($uri, 'at://') || $cid === '') {
        return ['ok' => false, 'error' => 'Invalid like subject'];
    }
    $row = ap_bsky_session_row($ownerUserId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Bluesky not connected'];
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No session')];
    }
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $did = (string) ($row['did'] ?? '');
    $body = [
        'repo' => $did,
        'collection' => 'app.bsky.feed.like',
        'record' => [
            '$type' => 'app.bsky.feed.like',
            'subject' => ['uri' => $uri, 'cid' => $cid],
            'createdAt' => gmdate('c'),
        ],
    ];
    $endpoint = 'com.atproto.repo.createRecord';
    if ($recordKey !== null && preg_match('/^[A-Za-z0-9._~:-]{1,240}$/', $recordKey)) {
        $body['rkey'] = $recordKey;
        $endpoint = 'com.atproto.repo.putRecord';
    }
    $put = ap_bsky_xrpc($pds, $endpoint, 'POST', null, $body, (string) $tok['access'], 15);
    if (empty($put['ok']) && (($put['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $put = ap_bsky_xrpc($pds, $endpoint, 'POST', null, $body, (string) $tok['access'], 15);
        }
    }
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => (string) ($put['error'] ?? 'like failed')];
    }
    return [
        'ok' => true,
        'uri' => (string) ($put['json']['uri'] ?? ''),
        'cid' => (string) ($put['json']['cid'] ?? ''),
    ];
}

/**
 * Delete a repo record by its at:// URI (like / repost / etc.).
 *
 * @return array{ok:bool,error?:string}
 */
function ap_bsky_delete_record_uri(int $ownerUserId, string $recordUri): array
{
    $recordUri = trim($recordUri);
    if (!preg_match('~^at://([^/]+)/([^/]+)/([^/]+)$~', $recordUri, $m)) {
        return ['ok' => false, 'error' => 'Invalid record URI'];
    }
    $repo = $m[1];
    $collection = $m[2];
    $rkey = $m[3];
    $row = ap_bsky_session_row($ownerUserId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Bluesky not connected'];
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No session')];
    }
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $ownDid = (string) ($row['did'] ?? '');
    if ($ownDid !== '' && $repo !== $ownDid && !str_starts_with($repo, 'did:')) {
        // Handle-form repo — still prefer own DID for delete.
        $repo = $ownDid;
    }
    $body = [
        'repo' => $ownDid !== '' ? $ownDid : $repo,
        'collection' => $collection,
        'rkey' => $rkey,
    ];
    $del = ap_bsky_xrpc($pds, 'com.atproto.repo.deleteRecord', 'POST', null, $body, (string) $tok['access'], 12);
    if (empty($del['ok']) && (($del['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $del = ap_bsky_xrpc($pds, 'com.atproto.repo.deleteRecord', 'POST', null, $body, (string) $tok['access'], 12);
        }
    }
    if (empty($del['ok'])) {
        return ['ok' => false, 'error' => (string) ($del['error'] ?? 'delete failed')];
    }
    ap_bsky_tl_cache_clear_owner($ownerUserId);
    return ['ok' => true];
}

/**
 * Best-effort like of a VAAK/AP/bsky object URL.
 *
 * @return array{ok:bool,skipped?:bool,error?:string,uri?:string}
 */
function ap_bsky_like_object(int $ownerUserId, string $objectId): array
{
    if ($ownerUserId < 1 || !ap_bsky_tab_enabled()) {
        return ['ok' => true, 'skipped' => true];
    }
    $ref = ap_bsky_resolve_strong_ref($objectId, $ownerUserId);
    if ($ref === null) {
        return ['ok' => true, 'skipped' => true, 'error' => 'No Bluesky subject'];
    }
    return ap_bsky_create_like($ownerUserId, $ref);
}

/** Extract plain text from a Bluesky post record. */
function ap_bsky_post_text(array $post): string
{
    $record = is_array($post['record'] ?? null) ? $post['record'] : [];
    return trim((string) ($record['text'] ?? ''));
}

/**
 * Extract quoted post preview from a hydrated PostView embed.
 *
 * @return array{uri:?string,handle:string,display:string,text:string,url:string}|null
 */
/**
 * Media items (admin_media_row_html shape) from Bluesky embed view/compact trees.
 *
 * @param mixed $embeds Single embed map or list of embeds
 * @return list<array{url:string,mediaType:?string,preview_url?:string}>
 */
function ap_bsky_media_items_from_embeds(mixed $embeds): array
{
    if (!is_array($embeds)) {
        return [];
    }
    $list = isset($embeds['$type']) || isset($embeds['images']) || isset($embeds['video']) || isset($embeds['playlist'])
        ? [$embeds]
        : $embeds;
    $items = [];
    foreach ($list as $em) {
        if (!is_array($em)) {
            continue;
        }
        // Compact or view images
        $images = is_array($em['images'] ?? null) ? $em['images'] : [];
        foreach ($images as $img) {
            if (!is_array($img)) {
                continue;
            }
            $url = (string) ($img['fullsize'] ?? $img['thumb'] ?? $img['url'] ?? '');
            if (!str_starts_with($url, 'https://')) {
                continue;
            }
            $thumb = (string) ($img['thumb'] ?? $img['preview_url'] ?? '');
            $items[] = [
                'url' => $url,
                'mediaType' => 'image/jpeg',
                'preview_url' => str_starts_with($thumb, 'https://') ? $thumb : '',
            ];
        }
        $video = function_exists('ap_bsky_embed_video_view')
            ? ap_bsky_embed_video_view($em)
            : (is_array($em['video'] ?? null) ? $em['video'] : null);
        if (is_array($video)) {
            $playlist = (string) ($video['playlist'] ?? $video['url'] ?? '');
            $thumbnail = (string) ($video['thumbnail'] ?? $video['thumb'] ?? '');
            if (str_starts_with($playlist, 'https://')) {
                $items[] = [
                    'url' => $playlist,
                    'mediaType' => 'application/x-mpegURL',
                    'preview_url' => str_starts_with($thumbnail, 'https://') ? $thumbnail : '',
                ];
            }
        }
    }
    return $items;
}

/**
 * @return array{uri:?string,handle:string,display:string,text:string,url:string,media?:list<array{url:string,mediaType:?string,preview_url?:string}>}|null
 */
function ap_bsky_quote_preview(array $post): ?array
{
    $embed = is_array($post['embed'] ?? null) ? $post['embed'] : null;
    if ($embed === null) {
        return null;
    }
    $type = (string) ($embed['$type'] ?? '');
    $viewRecord = null;
    if (str_contains($type, 'embed.recordWithMedia') || str_contains($type, 'recordWithMedia')) {
        $viewRecord = is_array($embed['record']['record'] ?? null)
            ? $embed['record']['record']
            : (is_array($embed['record'] ?? null) ? $embed['record'] : null);
    } elseif (str_contains($type, 'embed.record') || str_contains($type, 'record#view') || isset($embed['record'])) {
        $viewRecord = is_array($embed['record'] ?? null) ? $embed['record'] : null;
        if (is_array($viewRecord) && isset($viewRecord['record']) && is_array($viewRecord['record'])
            && (isset($viewRecord['record']['author']) || isset($viewRecord['record']['value']) || isset($viewRecord['record']['text']))) {
            $viewRecord = $viewRecord['record'];
        }
    }
    if (!is_array($viewRecord)) {
        return null;
    }
    $vType = (string) ($viewRecord['$type'] ?? '');
    if ($vType !== '' && (str_contains($vType, 'NotFound') || str_contains($vType, 'Blocked') || str_contains($vType, 'Detached'))) {
        return [
            'uri' => isset($viewRecord['uri']) ? (string) $viewRecord['uri'] : null,
            'handle' => '',
            'display' => 'Unavailable',
            'text' => str_contains($vType, 'Blocked') ? 'Quoted post is blocked' : 'Quoted post unavailable',
            'url' => 'https://bsky.app/',
            'media' => [],
        ];
    }
    // Custom feeds often embed feed generators / lists / starter packs as record#view.
    // Those are not quote-posts — don't render an empty "Open quoted" shell.
    if ($vType !== '' && (
        str_contains($vType, 'generatorView')
        || str_contains($vType, 'listView')
        || str_contains($vType, 'starterPack')
        || str_contains($vType, 'labelerView')
    )) {
        return null;
    }
    $author = is_array($viewRecord['author'] ?? null) ? $viewRecord['author'] : [];
    $handle = (string) ($author['handle'] ?? '');
    $display = trim((string) ($author['displayName'] ?? ''));
    if ($display === '') {
        $display = $handle !== '' ? $handle : 'Bluesky user';
    }
    $value = is_array($viewRecord['value'] ?? null)
        ? $viewRecord['value']
        : (is_array($viewRecord['record'] ?? null) ? $viewRecord['record'] : []);
    $valueType = (string) ($value['$type'] ?? '');
    if ($valueType !== '' && !str_contains($valueType, 'feed.post')) {
        return null;
    }
    // Live XRPC uses value.text; our durable compact cache may put text on the record root.
    $text = trim((string) ($value['text'] ?? $viewRecord['text'] ?? ''));
    $media = [];
    $embeds = is_array($viewRecord['embeds'] ?? null) ? $viewRecord['embeds'] : [];
    if ($embeds !== []) {
        $media = ap_bsky_media_items_from_embeds($embeds);
    } elseif (is_array($value['embed'] ?? null)) {
        $media = ap_bsky_media_items_from_embeds($value['embed']);
    }
    // Older compact caches stripped quote embeds — fall back to the quoted post body cache.
    $uriEarly = (string) ($viewRecord['uri'] ?? '');
    if ($media === [] && str_starts_with($uriEarly, 'at://') && function_exists('ap_bsky_post_item_by_uri')) {
        $quotedItem = ap_bsky_post_item_by_uri($uriEarly);
        if (is_array($quotedItem) && is_array($quotedItem['post'] ?? null)) {
            $qp = $quotedItem['post'];
            if (is_array($qp['embed'] ?? null)) {
                $media = ap_bsky_media_items_from_embeds($qp['embed']);
            } elseif (is_array($qp['record']['embed'] ?? null)) {
                $media = ap_bsky_media_items_from_embeds($qp['record']['embed']);
            }
        }
    }
    // Image/video-only quotes: surface a short placeholder so the block isn't just "Open quoted".
    if ($text === '') {
        foreach ($embeds as $em) {
            if (!is_array($em)) {
                continue;
            }
            $et = (string) ($em['$type'] ?? '');
            if (str_contains($et, 'images') || !empty($em['images'])) {
                $text = '📷 Image';
                break;
            }
            if (str_contains($et, 'video') || isset($em['playlist']) || isset($em['thumbnail']) || isset($em['video'])) {
                $text = '🎬 Video';
                break;
            }
            if (str_contains($et, 'external') && is_array($em['external'] ?? null)) {
                $extTitle = trim((string) ($em['external']['title'] ?? ''));
                $extUri = trim((string) ($em['external']['uri'] ?? ''));
                $text = $extTitle !== '' ? $extTitle : ($extUri !== '' ? $extUri : '🔗 Link');
                break;
            }
        }
        if ($text === '' && $media !== []) {
            $text = '📷 Image';
        }
    }
    $uri = (string) ($viewRecord['uri'] ?? '');
    $url = $uri !== ''
        ? ap_bsky_https_url_from_at_uri($uri, $handle !== '' ? $handle : null)
        : 'https://bsky.app/';
    // No usable quote payload (common with broken compact cache) — don't render an empty shell.
    if ($text === '' && $handle === '' && $media === [] && ($uri === '' || $url === 'https://bsky.app/')) {
        return null;
    }
    // Generator-style records sometimes lack author; skip empty shells.
    if ($text === '' && $handle === '' && $media === []) {
        return null;
    }
    return [
        'uri' => $uri !== '' ? $uri : null,
        'handle' => $handle,
        'display' => $display,
        'text' => $text,
        'url' => $url,
        'media' => $media,
    ];
}

/**
 * Extract an AT-URI post from bsky.app / Bridgy Fed / raw at:// URLs.
 */
function ap_bsky_at_uri_from_any_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (str_starts_with($url, 'at://')) {
        return preg_match('~^at://[^/]+/app\.bsky\.feed\.post/[A-Za-z0-9]+~', $url) ? $url : null;
    }
    // Bridgy: https://bsky.brid.gy/convert/ap/at://did:…/app.bsky.feed.post/rkey
    if (preg_match(
        '~(?:^|/)(?:convert/ap/)?at://(did:[^/\s]+)/app\.bsky\.feed\.post/([A-Za-z0-9]+)(?:[/?#]|$)~i',
        $url,
        $m
    )) {
        return 'at://' . $m[1] . '/app.bsky.feed.post/' . $m[2];
    }
    // URL-encoded at:// inside a Bridgy path
    if (preg_match(
        '~at%3A%2F%2F(did%3A[^/\s]+)/app\.bsky\.feed\.post/([A-Za-z0-9]+)~i',
        $url,
        $m
    )) {
        $did = rawurldecode($m[1]);
        return 'at://' . $did . '/app.bsky.feed.post/' . $m[2];
    }
    if (str_starts_with($url, 'https://bsky.app/')) {
        return ap_bsky_at_uri_from_https($url);
    }
    // Bridgy redirect wrapper → bsky.app
    if (preg_match('#^https://bsky\.brid\.gy/r/(https://bsky\.app/.+)$#i', $url, $m)) {
        return ap_bsky_at_uri_from_https($m[1]);
    }
    return null;
}

/**
 * Plain preview for a Bluesky post itself (not an embed) — used to hydrate
 * Bridgy Fed quote stubs on Home when the quoted AT post isn't in events.
 *
 * @param array<string,mixed> $post PostView-shaped
 * @return array{uri:?string,handle:string,display:string,text:string,url:string}|null
 */
function ap_bsky_post_as_quote_preview(array $post): ?array
{
    $uri = trim((string) ($post['uri'] ?? ''));
    $author = is_array($post['author'] ?? null) ? $post['author'] : [];
    $handle = (string) ($author['handle'] ?? '');
    $display = trim((string) ($author['displayName'] ?? ''));
    if ($display === '') {
        $display = $handle !== '' ? $handle : 'Bluesky user';
    }
    $record = is_array($post['record'] ?? null) ? $post['record'] : [];
    $text = trim((string) ($record['text'] ?? ''));
    $media = [];
    if (is_array($post['embed'] ?? null)) {
        $media = ap_bsky_media_items_from_embeds($post['embed']);
    } elseif (is_array($record['embed'] ?? null)) {
        $media = ap_bsky_media_items_from_embeds($record['embed']);
    }
    if ($text === '' && $media !== []) {
        $text = '📷 Image';
    }
    if ($text === '' && $handle === '' && $uri === '' && $media === []) {
        return null;
    }
    $url = $uri !== ''
        ? ap_bsky_https_url_from_at_uri($uri, $handle !== '' ? $handle : null)
        : 'https://bsky.app/';
    return [
        'uri' => $uri !== '' ? $uri : null,
        'handle' => $handle,
        'display' => $display,
        'text' => $text,
        'url' => $url,
        'media' => $media,
    ];
}

/**
 * Resolve a quote-target URL (Bridgy / bsky.app / at://) into a preview.
 * Cache-first via bsky_posts; optional public AppView getPosts on miss.
 *
 * @return array{uri:?string,handle:string,display:string,text:string,url:string}|null
 */
function ap_bsky_post_preview_from_url(string $url, int $ownerUserId = 0, bool $allowFetch = false): ?array
{
    $atUri = ap_bsky_at_uri_from_any_url($url);
    if ($atUri === null) {
        return null;
    }
    // Durable cache
    $cached = ap_bsky_post_item_by_uri($atUri);
    if (is_array($cached) && is_array($cached['post'] ?? null)) {
        $prev = ap_bsky_post_as_quote_preview($cached['post']);
        if ($prev !== null && ($prev['text'] !== '' || $prev['handle'] !== '' || !empty($prev['media']))) {
            return $prev;
        }
    }
    if (!$allowFetch) {
        return null;
    }
    // Public AppView — no session required for public posts.
    $got = ap_bsky_xrpc(AP_BSKY_PUBLIC_API, 'app.bsky.feed.getPosts', 'GET', [
        'uris' => $atUri,
    ], null, null, 6);
    if (empty($got['ok']) || !is_array($got['json'] ?? null)) {
        return null;
    }
    $posts = is_array($got['json']['posts'] ?? null) ? $got['json']['posts'] : [];
    $post = is_array($posts[0] ?? null) ? $posts[0] : null;
    if ($post === null) {
        return null;
    }
    // Persist for next Home paint (and native Bluesky mix).
    try {
        ap_bsky_post_upsert_from_feed_item(['post' => $post], $ownerUserId > 0 ? $ownerUserId : null);
    } catch (Throwable $e) {
        // ignore
    }
    return ap_bsky_post_as_quote_preview($post);
}

/**
 * Load a Bluesky post as a FeedViewPost-shaped item (cache, then public getPosts).
 * Used by the status/thread view so Open-from-notifications can render native
 * like/repost buttons instead of a dead ActivityPub favourite form.
 *
 * @return array{post:array,bsky_uri?:string}|null
 */
function ap_bsky_feed_item_from_any_url(string $url, int $ownerUserId = 0, bool $allowFetch = false): ?array
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (str_starts_with($url, 'bsky:')) {
        $url = substr($url, 5);
    }
    $hash = strpos($url, '#');
    if ($hash !== false) {
        $url = substr($url, 0, $hash);
    }
    $atUri = function_exists('ap_bsky_at_uri_from_any_url') ? ap_bsky_at_uri_from_any_url($url) : null;
    if ($atUri === null && str_starts_with($url, 'https://bsky.app/') && function_exists('ap_bsky_at_uri_from_https')) {
        $atUri = ap_bsky_at_uri_from_https($url, $ownerUserId);
    }
    if (!is_string($atUri) || !str_starts_with($atUri, 'at://')) {
        return null;
    }
    $cached = ap_bsky_post_item_by_uri($atUri);
    if (is_array($cached) && is_array($cached['post'] ?? null) && trim((string) ($cached['post']['uri'] ?? '')) !== '') {
        return $cached;
    }
    if (!$allowFetch) {
        return null;
    }
    $got = ap_bsky_xrpc(AP_BSKY_PUBLIC_API, 'app.bsky.feed.getPosts', 'GET', [
        'uris' => $atUri,
    ], null, null, 8);
    if (empty($got['ok']) || !is_array($got['json'] ?? null)) {
        return null;
    }
    $posts = is_array($got['json']['posts'] ?? null) ? $got['json']['posts'] : [];
    $post = is_array($posts[0] ?? null) ? $posts[0] : null;
    if ($post === null) {
        return null;
    }
    $item = ['post' => $post, 'bsky_uri' => (string) ($post['uri'] ?? $atUri)];
    try {
        ap_bsky_post_upsert_from_feed_item($item, $ownerUserId > 0 ? $ownerUserId : null);
    } catch (Throwable $e) {
        // ignore cache write
    }
    return $item;
}

/**
 * Hydrated reply parent from a FeedViewPost (`item.reply.parent`), when present.
 *
 * @param array<string,mixed> $item
 * @return array{uri:?string,handle:string,display:string,text:string,url:string}|null
 */
function ap_bsky_reply_parent_preview(array $item): ?array
{
    $parent = $item['reply']['parent'] ?? null;
    if (!is_array($parent)) {
        $post = is_array($item['post'] ?? null) ? $item['post'] : [];
        $rec = is_array($post['record'] ?? null) ? $post['record'] : [];
        $ref = is_array($rec['reply']['parent'] ?? null) ? $rec['reply']['parent'] : null;
        if (!is_array($ref) || empty($ref['uri'])) {
            return null;
        }
        $uri = (string) $ref['uri'];
        return [
            'uri' => $uri,
            'handle' => '',
            'display' => 'parent post',
            'text' => '',
            'url' => ap_bsky_https_url_from_at_uri($uri, null),
        ];
    }
    $vType = (string) ($parent['$type'] ?? '');
    if ($vType !== '' && (str_contains($vType, 'NotFound') || str_contains($vType, 'Blocked'))) {
        return [
            'uri' => isset($parent['uri']) ? (string) $parent['uri'] : null,
            'handle' => '',
            'display' => 'Unavailable',
            'text' => '',
            'url' => 'https://bsky.app/',
        ];
    }
    $author = is_array($parent['author'] ?? null) ? $parent['author'] : [];
    $handle = (string) ($author['handle'] ?? '');
    $display = trim((string) ($author['displayName'] ?? ''));
    if ($display === '') {
        $display = $handle !== '' ? $handle : 'Bluesky user';
    }
    $rec = is_array($parent['record'] ?? null) ? $parent['record'] : [];
    $text = trim((string) ($rec['text'] ?? ''));
    $uri = (string) ($parent['uri'] ?? '');
    return [
        'uri' => $uri !== '' ? $uri : null,
        'handle' => $handle,
        'display' => $display,
        'text' => $text,
        'url' => $uri !== '' ? ap_bsky_https_url_from_at_uri($uri, $handle !== '' ? $handle : null) : 'https://bsky.app/',
    ];
}

/**
 * @param array{uri:string,cid:string} $subject
 * @return array{ok:bool,error?:string}
 */
function ap_bsky_create_bookmark(int $ownerUserId, array $subject): array
{
    $uri = (string) ($subject['uri'] ?? '');
    $cid = (string) ($subject['cid'] ?? '');
    if (!str_starts_with($uri, 'at://') || $cid === '') {
        return ['ok' => false, 'error' => 'Invalid bookmark subject'];
    }
    $row = ap_bsky_session_row($ownerUserId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Bluesky not connected'];
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No session')];
    }
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $body = ['uri' => $uri, 'cid' => $cid];
    $put = ap_bsky_xrpc($pds, 'app.bsky.bookmark.createBookmark', 'POST', null, $body, (string) $tok['access'], 12);
    if (empty($put['ok']) && (($put['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $put = ap_bsky_xrpc($pds, 'app.bsky.bookmark.createBookmark', 'POST', null, $body, (string) $tok['access'], 12);
        }
    }
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => (string) ($put['error'] ?? 'bookmark failed')];
    }
    if (function_exists('ap_bsky_bookmark_cache_clear')) {
        ap_bsky_bookmark_cache_clear($ownerUserId);
    }
    return ['ok' => true];
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_bsky_delete_bookmark(int $ownerUserId, string $uri): array
{
    $uri = trim($uri);
    if (!str_starts_with($uri, 'at://')) {
        return ['ok' => false, 'error' => 'Invalid bookmark uri'];
    }
    $row = ap_bsky_session_row($ownerUserId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Bluesky not connected'];
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No session')];
    }
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $body = ['uri' => $uri];
    $put = ap_bsky_xrpc($pds, 'app.bsky.bookmark.deleteBookmark', 'POST', null, $body, (string) $tok['access'], 12);
    if (empty($put['ok']) && (($put['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $put = ap_bsky_xrpc($pds, 'app.bsky.bookmark.deleteBookmark', 'POST', null, $body, (string) $tok['access'], 12);
        }
    }
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => (string) ($put['error'] ?? 'unbookmark failed')];
    }
    if (function_exists('ap_bsky_bookmark_cache_clear')) {
        ap_bsky_bookmark_cache_clear($ownerUserId, $uri);
    }
    return ['ok' => true];
}

function ap_bsky_bookmark_cache_clear(int $ownerUserId, ?string $removeUri = null): void
{
    if ($ownerUserId < 1 || !ap_bsky_bookmark_cache_migrate()) return;
    try {
        if (is_string($removeUri) && str_starts_with($removeUri, 'at://')) {
            ap_db()->prepare('DELETE FROM bsky_bookmark_cache WHERE owner_user_id = ? AND bookmark_uri = ?')->execute([$ownerUserId, $removeUri]);
        }
        ap_db()->prepare('INSERT INTO bsky_bookmark_sync_state (owner_user_id, head_checked_at, full_synced_at, updated_at) VALUES (?, ?, NULL, ?) ON CONFLICT (owner_user_id) DO UPDATE SET head_checked_at = excluded.head_checked_at, updated_at = excluded.updated_at')
            ->execute([$ownerUserId, '1970-01-01T00:00:00+00:00', gmdate('c')]);
        ap_bsky_background_sync_enqueue($ownerUserId, 'bookmarks', true);
    } catch (Throwable $e) {
        error_log('[ap-bsky] bookmark cache invalidation failed');
    }
}

/** PostgreSQL bookmark-cache tables are provisioned by the owner-run migration. */
function ap_bsky_bookmark_cache_migrate(?PDO $db = null): bool
{
    static $readyByConnection = [];
    $db ??= ap_db();
    $key = spl_object_id($db);
    if (isset($readyByConnection[$key])) return $readyByConnection[$key];
    try {
        if (function_exists('ap_db_driver') && ap_db_driver($db) === 'pgsql') {
            $st = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name IN ('bsky_bookmark_cache', 'bsky_bookmark_sync_state')");
            $tables = array_fill_keys(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
            return $readyByConnection[$key] = isset($tables['bsky_bookmark_cache'], $tables['bsky_bookmark_sync_state']);
        }
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_bookmark_cache (
    owner_user_id INTEGER NOT NULL,
    bookmark_uri TEXT NOT NULL,
    bookmarked_at TEXT NOT NULL,
    post_json TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, bookmark_uri)
)
SQL);
        $db->exec('CREATE INDEX IF NOT EXISTS idx_bsky_bookmark_cache_owner_time ON bsky_bookmark_cache (owner_user_id, bookmarked_at DESC)');
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_bookmark_sync_state (
    owner_user_id INTEGER PRIMARY KEY,
    head_checked_at TEXT,
    full_synced_at TEXT,
    updated_at TEXT NOT NULL
)
SQL);
        return $readyByConnection[$key] = true;
    } catch (Throwable $e) {
        error_log('[ap-bsky] bookmark cache schema unavailable');
        return $readyByConnection[$key] = false;
    }
}

/** @return list<array{post:array<string,mixed>,_vaak_bookmarked_at:string}> */
function ap_bsky_bookmark_cache_read(int $ownerUserId, int $limit): array
{
    if ($ownerUserId < 1 || !ap_bsky_bookmark_cache_migrate()) return [];
    try {
        $st = ap_db()->prepare('SELECT post_json, bookmarked_at FROM bsky_bookmark_cache WHERE owner_user_id = ? ORDER BY bookmarked_at DESC, bookmark_uri DESC LIMIT ?');
        $st->bindValue(1, $ownerUserId, PDO::PARAM_INT);
        $st->bindValue(2, max(1, min(500, $limit)), PDO::PARAM_INT);
        $st->execute();
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $item = json_decode((string) ($row['post_json'] ?? ''), true);
            if (!is_array($item) || !is_array($item['post'] ?? null)) continue;
            $item['post']['viewer'] = is_array($item['post']['viewer'] ?? null) ? $item['post']['viewer'] : [];
            $item['post']['viewer']['bookmarked'] = true;
            $item['_vaak_bookmarked_at'] = (string) ($row['bookmarked_at'] ?? '');
            $out[] = $item;
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[ap-bsky] bookmark cache read failed');
        return [];
    }
}

/** Import the old volatile cache once, so deployment does not blank the first view. */
function ap_bsky_bookmark_cache_import_legacy(int $ownerUserId): void
{
    $path = sys_get_temp_dir() . '/vaak-bsky-bookmarks-' . $ownerUserId . '.json';
    if ($ownerUserId < 1 || !is_file($path) || !ap_bsky_bookmark_cache_migrate()) return;
    $legacy = json_decode((string) @file_get_contents($path), true);
    if (!is_array($legacy) || !is_array($legacy['bookmarks'] ?? null)) return;
    $db = ap_db();
    try {
        $st = $db->prepare('INSERT INTO bsky_bookmark_cache (owner_user_id, bookmark_uri, bookmarked_at, post_json, updated_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT (owner_user_id, bookmark_uri) DO NOTHING');
        $now = gmdate('c');
        foreach ($legacy['bookmarks'] as $item) {
            $post = is_array($item['post'] ?? null) ? $item['post'] : [];
            $uri = trim((string) ($post['uri'] ?? ''));
            if (!str_starts_with($uri, 'at://')) continue;
            $bookmarkedAt = trim((string) ($item['_vaak_bookmarked_at'] ?? '')) ?: $now;
            $st->execute([$ownerUserId, $uri, $bookmarkedAt, json_encode(['post' => $post], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now]);
        }
    } catch (Throwable $e) {
        error_log('[ap-bsky] legacy bookmark cache import failed');
    }
}

/** Queue cache warming for user-scoped Bluesky collections; never fetch in a page render. */
function ap_bsky_background_sync_enqueue(int $ownerUserId, string $collection, bool $force = false): bool
{
    if ($ownerUserId < 1 || !in_array($collection, ['bookmarks', 'favourites', 'lists', 'starter_packs'], true)
        || !ap_bsky_actor_refresh_migrate()) return false;
    $actorRef = '__vaak_sync__:' . $collection;
    $now = gmdate('c');
    $cooldown = 300;
    try {
        $st = ap_db()->prepare('SELECT status, queued_at FROM bsky_actor_refresh_queue WHERE owner_user_id = ? AND actor_ref = ? LIMIT 1');
        $st->execute([$ownerUserId, $actorRef]);
        $existing = $st->fetch();
        if (is_array($existing)) {
            $status = (string) ($existing['status'] ?? '');
            $queuedAt = strtotime((string) ($existing['queued_at'] ?? '')) ?: 0;
            if (in_array($status, ['pending', 'processing'], true)
                || (!$force && $status === 'succeeded' && $queuedAt > time() - $cooldown)) return true;
        }
        $up = ap_db()->prepare("INSERT INTO bsky_actor_refresh_queue (owner_user_id, actor_ref, status, queued_at, next_attempt_at, attempts) VALUES (?, ?, 'pending', ?, ?, 0) ON CONFLICT (owner_user_id, actor_ref) DO UPDATE SET status = 'pending', queued_at = excluded.queued_at, next_attempt_at = excluded.next_attempt_at, attempts = 0, locked_at = NULL, last_error = NULL WHERE bsky_actor_refresh_queue.status IN ('succeeded', 'failed')");
        $up->execute([$ownerUserId, $actorRef, $now, $now]);
        return true;
    } catch (Throwable $e) {
        error_log('[ap-bsky] collection refresh enqueue failed');
        return false;
    }
}


function ap_bsky_favourite_cache_migrate(?PDO $db = null): bool
{
    static $ready = [];
    $db = $db ?? ap_db();
    $key = spl_object_id($db);
    if (isset($ready[$key])) {
        return $ready[$key];
    }
    try {
        try {
            $db->query('SELECT 1 FROM bsky_favourite_cache LIMIT 1');
            $db->query('SELECT 1 FROM bsky_favourite_sync_state LIMIT 1');
            return $ready[$key] = true;
        } catch (Throwable $probeEx) {
            // Fall through and attempt CREATE TABLE (SQLite / fresh PG).
        }
        $db->exec('CREATE TABLE IF NOT EXISTS bsky_favourite_cache (
            owner_user_id INTEGER NOT NULL,
            favourite_uri TEXT NOT NULL,
            favourited_at TEXT NOT NULL,
            post_json TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (owner_user_id, favourite_uri)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_bsky_favourite_cache_owner_time ON bsky_favourite_cache (owner_user_id, favourited_at DESC)');
        $db->exec('CREATE TABLE IF NOT EXISTS bsky_favourite_sync_state (
            owner_user_id INTEGER PRIMARY KEY,
            checked_at TEXT,
            updated_at TEXT NOT NULL
        )');
        return $ready[$key] = true;
    } catch (Throwable $e) {
        return $ready[$key] = false;
    }
}

/** @return list<array<string,mixed>> */
function ap_bsky_favourite_cache_read(int $ownerUserId, int $limit = 80, int $offset = 0): array
{
    if ($ownerUserId < 1 || !ap_bsky_favourite_cache_migrate()) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    try {
        $st = ap_db()->prepare(
            'SELECT post_json, favourited_at FROM bsky_favourite_cache
             WHERE owner_user_id = ?
             ORDER BY favourited_at DESC, favourite_uri DESC
             LIMIT ? OFFSET ?'
        );
        $st->execute([$ownerUserId, $limit, $offset]);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $item = json_decode((string) ($row['post_json'] ?? ''), true);
            if (is_array($item) && is_array($item['post'] ?? null)) {
                $out[] = $item;
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function ap_bsky_favourite_cache_count(int $ownerUserId): int
{
    if ($ownerUserId < 1 || !ap_bsky_favourite_cache_migrate()) {
        return 0;
    }
    try {
        $st = ap_db()->prepare('SELECT COUNT(*) FROM bsky_favourite_cache WHERE owner_user_id = ?');
        $st->execute([$ownerUserId]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return array{ok:bool,favourites?:list<array<string,mixed>>,cached?:bool,refreshing?:bool,has_more?:bool,error?:string}
 */
function ap_bsky_get_favourites_page(int $ownerUserId, int $offset = 0, int $limit = 20): array
{
    $base = ap_bsky_get_favourites($ownerUserId, 1, false);
    if (empty($base['ok'])) {
        return $base;
    }
    $limit = max(1, min(50, $limit));
    $offset = max(0, $offset);
    return [
        'ok' => true,
        'favourites' => ap_bsky_favourite_cache_read($ownerUserId, $limit, $offset),
        'cached' => true,
        'refreshing' => !empty($base['refreshing']),
        'has_more' => ($offset + $limit) < ap_bsky_favourite_cache_count($ownerUserId),
    ];
}

function ap_bsky_favourite_cache_clear(int $ownerUserId, ?string $removeUri = null): void
{
    if ($ownerUserId < 1 || !ap_bsky_favourite_cache_migrate()) {
        return;
    }
    try {
        if (is_string($removeUri) && str_starts_with($removeUri, 'at://')) {
            ap_db()->prepare('DELETE FROM bsky_favourite_cache WHERE owner_user_id = ? AND favourite_uri = ?')
                ->execute([$ownerUserId, $removeUri]);
        }
        // Force next page view to re-enqueue a sync.
        ap_db()->prepare(
            'INSERT INTO bsky_favourite_sync_state (owner_user_id, checked_at, updated_at)
             VALUES (?, ?, ?)
             ON CONFLICT (owner_user_id) DO UPDATE SET checked_at = excluded.checked_at, updated_at = excluded.updated_at'
        )->execute([$ownerUserId, '1970-01-01T00:00:00+00:00', gmdate('c')]);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Cache-first Bluesky likes. Stale cache enqueues a background refresh.
 *
 * @return array{ok:bool,favourites?:list<array<string,mixed>>,cached?:bool,refreshing?:bool,error?:string,added?:int}
 */
function ap_bsky_get_favourites(int $ownerUserId, int $limit = 80, bool $refresh = false): array
{
    if ($ownerUserId < 1 || (!$refresh && !ap_bsky_tab_enabled()) || ap_bsky_session_row($ownerUserId) === null) {
        return ['ok' => false, 'error' => 'Bluesky not connected'];
    }
    if (!ap_bsky_favourite_cache_migrate()) {
        return ['ok' => false, 'error' => 'Bluesky favourite cache is not provisioned'];
    }
    if ($refresh) {
        return ap_bsky_favourites_refresh_worker($ownerUserId, $limit);
    }
    $cached = ap_bsky_favourite_cache_read($ownerUserId, $limit);
    $checked = 0;
    try {
        $st = ap_db()->prepare('SELECT checked_at FROM bsky_favourite_sync_state WHERE owner_user_id = ?');
        $st->execute([$ownerUserId]);
        $checked = strtotime((string) ($st->fetchColumn() ?: '')) ?: 0;
    } catch (Throwable $e) {
        // ignore
    }
    $stale = $checked < time() - 300;
    if ($stale) {
        ap_bsky_background_sync_enqueue($ownerUserId, 'favourites');
    }
    return ['ok' => true, 'favourites' => $cached, 'cached' => true, 'refreshing' => $stale];
}

/**
 * Pull newest app.bsky.feed.like records from the user's PDS and hydrate posts.
 *
 * @return array{ok:bool,favourites?:list<array<string,mixed>>,error?:string,added?:int}
 */
function ap_bsky_favourites_refresh_worker(int $ownerUserId, int $limit = 200): array
{
    if ($ownerUserId < 1 || !ap_bsky_favourite_cache_migrate()) {
        return ['ok' => false, 'error' => 'Favourite cache unavailable'];
    }
    $session = ap_bsky_session_row($ownerUserId);
    $token = ap_bsky_access_token($ownerUserId, false);
    if (empty($token['ok'])) {
        $token = ap_bsky_access_token($ownerUserId, true);
    }
    if ($session === null || empty($token['ok'])) {
        return ['ok' => false, 'error' => 'Bluesky session unavailable'];
    }
    $did = (string) ($session['did'] ?? '');
    $pds = rtrim((string) ($session['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $access = (string) $token['access'];
    if ($did === '') {
        return ['ok' => false, 'error' => 'Missing DID'];
    }

    $limit = max(20, min(200, $limit));
    $byUri = [];
    $cursor = null;
    $pages = 0;
    while ($pages < 3 && count($byUri) < $limit) {
        $pages++;
        $query = [
            'repo' => $did,
            'collection' => 'app.bsky.feed.like',
            'limit' => 100,
            // Default listRecords order is newest-first for TID rkeys.
        ];
        if (is_string($cursor) && $cursor !== '') {
            $query['cursor'] = $cursor;
        }
        $res = ap_bsky_xrpc($pds, 'com.atproto.repo.listRecords', 'GET', $query, null, $access, 15);
        if (($res['status'] ?? 0) === 401) {
            $token = ap_bsky_access_token($ownerUserId, true);
            if (empty($token['ok'])) {
                return ['ok' => false, 'error' => (string) ($token['error'] ?? 'Bluesky session expired')];
            }
            $access = (string) $token['access'];
            $res = ap_bsky_xrpc($pds, 'com.atproto.repo.listRecords', 'GET', $query, null, $access, 15);
        }
        if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
            if ($pages === 1) {
                return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not load Bluesky likes')];
            }
            break;
        }
        $rows = is_array($res['json']['records'] ?? null) ? $res['json']['records'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $v = is_array($row['value'] ?? null) ? $row['value'] : [];
            $subject = is_array($v['subject'] ?? null) ? $v['subject'] : [];
            $uri = trim((string) ($subject['uri'] ?? ''));
            if (!str_starts_with($uri, 'at://') || isset($byUri[$uri])) {
                continue;
            }
            $byUri[$uri] = [
                'like_uri' => (string) ($row['uri'] ?? ''),
                'created' => trim((string) ($v['createdAt'] ?? gmdate('c'))) ?: gmdate('c'),
            ];
            if (count($byUri) >= $limit) {
                break;
            }
        }
        $cursor = isset($res['json']['cursor']) && is_string($res['json']['cursor']) ? $res['json']['cursor'] : null;
        if ($cursor === null || $rows === []) {
            break;
        }
    }

    $items = [];
    $uris = array_keys($byUri);
    foreach (array_chunk($uris, 25) as $uriBatch) {
        // Bluesky expects repeated uris= params, not uris[0]=.
        $qs = implode('&', array_map(static fn($u) => 'uris=' . rawurlencode($u), $uriBatch));
        $url = rtrim(AP_BSKY_PUBLIC_API, '/') . '/xrpc/app.bsky.feed.getPosts?' . $qs;
        $ch = curl_init($url);
        if ($ch === false) {
            continue;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: VAAK-Bluesky/1.0 (+https://mkultra.monster/vaak)',
            ],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        foreach (is_array($json['posts'] ?? null) ? $json['posts'] : [] as $post) {
            if (!is_array($post)) {
                continue;
            }
            $uri = trim((string) ($post['uri'] ?? ''));
            if ($uri === '' || !isset($byUri[$uri])) {
                continue;
            }
            $post['viewer'] = is_array($post['viewer'] ?? null) ? $post['viewer'] : [];
            $post['viewer']['like'] = (string) ($byUri[$uri]['like_uri'] ?? '');
            $encoded = json_encode(['post' => $post], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded)) {
                continue;
            }
            $items[] = [$uri, $byUri[$uri]['created'], $encoded];
        }
    }

    if ($uris !== [] && $items === []) {
        return ['ok' => false, 'error' => 'Could not hydrate Bluesky liked posts'];
    }

    try {
        $db = ap_db();
        $db->beginTransaction();
        $up = $db->prepare(
            'INSERT INTO bsky_favourite_cache (owner_user_id, favourite_uri, favourited_at, post_json, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (owner_user_id, favourite_uri) DO UPDATE SET
               favourited_at = excluded.favourited_at,
               post_json = excluded.post_json,
               updated_at = excluded.updated_at'
        );
        foreach ($items as $item) {
            $up->execute([$ownerUserId, $item[0], $item[1], $item[2], gmdate('c')]);
        }
        // Keep a bounded newest window.
        $keep = max(50, min(200, $limit));
        $db->prepare(
            'DELETE FROM bsky_favourite_cache
             WHERE owner_user_id = ?
               AND favourite_uri NOT IN (
                 SELECT favourite_uri FROM bsky_favourite_cache
                 WHERE owner_user_id = ?
                 ORDER BY favourited_at DESC
                 LIMIT ?
               )'
        )->execute([$ownerUserId, $ownerUserId, $keep]);
        $db->prepare(
            'INSERT INTO bsky_favourite_sync_state (owner_user_id, checked_at, updated_at)
             VALUES (?, ?, ?)
             ON CONFLICT (owner_user_id) DO UPDATE SET checked_at = excluded.checked_at, updated_at = excluded.updated_at'
        )->execute([$ownerUserId, gmdate('c'), gmdate('c')]);
        $db->commit();
    } catch (Throwable $e) {
        try {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Throwable $ignored) {
        }
        return ['ok' => false, 'error' => 'Could not store Bluesky favourites'];
    }
    return [
        'ok' => true,
        'favourites' => ap_bsky_favourite_cache_read($ownerUserId, $limit),
        'added' => count($items),
    ];
}


/**
 * Read the durable bookmark cache for page requests; queued workers refresh
 * the new head and only paginate the full set for an initial/daily baseline.
 *
 * @return array{ok:bool,bookmarks?:list<array<string,mixed>>,error?:string,cached?:bool,refreshing?:bool}
 */
function ap_bsky_get_bookmarks(int $ownerUserId, int $limit = 80, bool $refresh = false): array
{
    if ($ownerUserId < 1 || (!$refresh && !ap_bsky_tab_enabled())) {
        return ['ok' => false, 'error' => 'Bluesky is not connected'];
    }
    $session = ap_bsky_session_row($ownerUserId);
    if ($session === null) {
        return ['ok' => false, 'error' => 'Bluesky not connected'];
    }
    $limit = max(1, min(200, $limit));
    if (!ap_bsky_bookmark_cache_migrate()) return ['ok' => false, 'error' => 'Bluesky bookmark cache is not provisioned'];
    if ($refresh) return ap_bsky_bookmarks_refresh_worker($ownerUserId, $limit);

    $cached = ap_bsky_bookmark_cache_read($ownerUserId, $limit);
    if ($cached === []) {
        ap_bsky_bookmark_cache_import_legacy($ownerUserId);
        $cached = ap_bsky_bookmark_cache_read($ownerUserId, $limit);
    }
    $state = null;
    try {
        $st = ap_db()->prepare('SELECT head_checked_at FROM bsky_bookmark_sync_state WHERE owner_user_id = ? LIMIT 1');
        $st->execute([$ownerUserId]);
        $state = $st->fetch();
    } catch (Throwable $e) {
        $state = null;
    }
    $checkedAt = is_array($state) ? (strtotime((string) ($state['head_checked_at'] ?? '')) ?: 0) : 0;
    $stale = $checkedAt < time() - 300;
    if ($stale) ap_bsky_background_sync_enqueue($ownerUserId, 'bookmarks');
    return ['ok' => true, 'bookmarks' => $cached, 'cached' => true, 'refreshing' => $stale];
}

/** Refresh only the new head, except for the initial/daily bounded baseline. */
function ap_bsky_bookmarks_refresh_worker(int $ownerUserId, int $limit = 200): array
{
    if ($ownerUserId < 1 || !ap_bsky_bookmark_cache_migrate()) return ['ok' => false, 'error' => 'Bookmark cache unavailable'];
    $session = ap_bsky_session_row($ownerUserId);
    if ($session === null) return ['ok' => false, 'error' => 'Bluesky not connected'];
    $token = ap_bsky_access_token($ownerUserId, false);
    if (empty($token['ok'])) {
        $token = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($token['ok'])) {
        return ['ok' => false, 'error' => (string) ($token['error'] ?? 'Bluesky session expired')];
    }
    $pds = rtrim((string) ($session['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $hosts = ap_bsky_feed_hosts($pds);
    $access = (string) $token['access'];
    $db = ap_db();
    $known = [];
    $state = null;
    try {
        $st = $db->prepare('SELECT bookmark_uri FROM bsky_bookmark_cache WHERE owner_user_id = ?');
        $st->execute([$ownerUserId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $uri) $known[(string) $uri] = true;
        $st = $db->prepare('SELECT full_synced_at FROM bsky_bookmark_sync_state WHERE owner_user_id = ? LIMIT 1');
        $st->execute([$ownerUserId]);
        $state = $st->fetch();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not read bookmark cache state'];
    }
    $fullAt = is_array($state) ? (strtotime((string) ($state['full_synced_at'] ?? '')) ?: 0) : 0;
    $fullSync = $known === [] || $fullAt < time() - 86400;
    $cursor = null;
    $newItems = [];
    $seenUris = [];
    $complete = false;
    $foundKnownBoundary = false;
    $lastError = 'Could not load Bluesky bookmarks';
    for ($page = 0; $page < 10; $page++) {
        $query = ['limit' => 100];
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }
        $result = null;
        foreach ($hosts as $host) {
            $result = ap_bsky_xrpc($host, 'app.bsky.bookmark.getBookmarks', 'GET', $query, null, $access, 15);
            if (($result['status'] ?? 0) === 401) {
                $token = ap_bsky_access_token($ownerUserId, true);
                if (empty($token['ok'])) {
                    return ['ok' => false, 'error' => (string) ($token['error'] ?? 'Bluesky session expired')];
                }
                $access = (string) $token['access'];
                $result = ap_bsky_xrpc($host, 'app.bsky.bookmark.getBookmarks', 'GET', $query, null, $access, 15);
            }
            if (!empty($result['ok']) && is_array($result['json'] ?? null)) {
                break;
            }
            $lastError = (string) ($result['error'] ?? $lastError);
        }
        if (empty($result['ok']) || !is_array($result['json'] ?? null)) {
            if ($page === 0) {
                return ['ok' => false, 'error' => $lastError];
            }
            break;
        }
        $rows = is_array($result['json']['bookmarks'] ?? null) ? $result['json']['bookmarks'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            // BookmarkView contains a strongRef in `subject` and the hydrated
            // PostView in `item`; render the latter so media/text aren't lost.
            $post = is_array($row['item'] ?? null) ? $row['item'] : (is_array($row['post'] ?? null) ? $row['post'] : null);
            if ($post === null || trim((string) ($post['uri'] ?? '')) === '') {
                continue;
            }
            $uri = trim((string) $post['uri']);
            if (!$fullSync && isset($known[$uri])) {
                $foundKnownBoundary = true;
                break;
            }
            $post['viewer'] = is_array($post['viewer'] ?? null) ? $post['viewer'] : [];
            $post['viewer']['bookmarked'] = true;
            $bookmarkedAt = trim((string) ($row['createdAt'] ?? '')) ?: gmdate('c');
            $json = json_encode(['post' => $post], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json)) $newItems[] = ['uri' => $uri, 'bookmarked_at' => $bookmarkedAt, 'post_json' => $json];
            $seenUris[$uri] = true;
        }
        if ($foundKnownBoundary) break;
        $cursor = isset($result['json']['cursor']) && is_string($result['json']['cursor'])
            ? $result['json']['cursor'] : null;
        if ($cursor === null) {
            $complete = true;
            break;
        }
        if ($rows === []) {
            break;
        }
    }
    $now = gmdate('c');
    try {
        $db->beginTransaction();
        $up = $db->prepare('INSERT INTO bsky_bookmark_cache (owner_user_id, bookmark_uri, bookmarked_at, post_json, updated_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT (owner_user_id, bookmark_uri) DO UPDATE SET bookmarked_at = excluded.bookmarked_at, post_json = excluded.post_json, updated_at = excluded.updated_at');
        foreach ($newItems as $item) $up->execute([$ownerUserId, $item['uri'], $item['bookmarked_at'], $item['post_json'], $now]);
        $stateUp = $db->prepare('INSERT INTO bsky_bookmark_sync_state (owner_user_id, head_checked_at, full_synced_at, updated_at) VALUES (?, ?, ?, ?) ON CONFLICT (owner_user_id) DO UPDATE SET head_checked_at = excluded.head_checked_at, full_synced_at = COALESCE(excluded.full_synced_at, bsky_bookmark_sync_state.full_synced_at), updated_at = excluded.updated_at');
        $stateUp->execute([$ownerUserId, $now, $complete ? $now : null, $now]);
        if ($complete) {
            $existing = $db->prepare('SELECT bookmark_uri FROM bsky_bookmark_cache WHERE owner_user_id = ?');
            $existing->execute([$ownerUserId]);
            $del = $db->prepare('DELETE FROM bsky_bookmark_cache WHERE owner_user_id = ? AND bookmark_uri = ?');
            foreach ($existing->fetchAll(PDO::FETCH_COLUMN) ?: [] as $uri) if (!isset($seenUris[(string) $uri])) $del->execute([$ownerUserId, (string) $uri]);
        }
        $db->commit();
    } catch (Throwable $e) {
        try { if ($db->inTransaction()) $db->rollBack(); } catch (Throwable $ignored) {}
        error_log('[ap-bsky] bookmark cache write failed');
        return ['ok' => false, 'error' => 'Could not store Bluesky bookmarks'];
    }
    return ['ok' => true, 'bookmarks' => ap_bsky_bookmark_cache_read($ownerUserId, $limit), 'added' => count($newItems), 'full_sync' => $complete, 'boundary_found' => $foundKnownBoundary];
}

/** Create an owned Bluesky graph list. */
function ap_bsky_create_graph_list(int $ownerUserId, string $name, string $purpose = 'curation'): array
{
    $session = ap_bsky_session_row($ownerUserId);
    if ($session === null) return ['ok' => false, 'error' => 'Bluesky not connected'];
    $purpose = $purpose === 'moderation' ? 'app.bsky.graph.defs#modlist' : 'app.bsky.graph.defs#curatelist';
    $res = ap_bsky_account_xrpc($ownerUserId, 'com.atproto.repo.createRecord', 'POST', null, [
        'repo' => (string) ($session['did'] ?? ''),
        'collection' => 'app.bsky.graph.list',
        'record' => [
            '$type' => 'app.bsky.graph.list',
            'name' => mb_substr(trim($name), 0, 64),
            'purpose' => $purpose,
            'description' => '',
            'createdAt' => gmdate('c'),
        ],
    ]);
    if (empty($res['ok'])) return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not create Bluesky list')];
    return ['ok' => true, 'uri' => (string) ($res['json']['uri'] ?? ''), 'cid' => (string) ($res['json']['cid'] ?? '')];
}

/** Add a DID to an owned Bluesky list; caller stores the returned record URI. */
function ap_bsky_add_graph_list_member(int $ownerUserId, string $listUri, string $did): array
{
    if (!str_starts_with($listUri, 'at://') || !str_starts_with($did, 'did:')) {
        return ['ok' => false, 'error' => 'A Bluesky list URI and account DID are required'];
    }
    $session = ap_bsky_session_row($ownerUserId);
    if ($session === null) return ['ok' => false, 'error' => 'Bluesky not connected'];
    $res = ap_bsky_account_xrpc($ownerUserId, 'com.atproto.repo.createRecord', 'POST', null, [
        'repo' => (string) ($session['did'] ?? ''),
        'collection' => 'app.bsky.graph.listitem',
        'record' => [
            '$type' => 'app.bsky.graph.listitem',
            'list' => $listUri,
            'subject' => $did,
            'createdAt' => gmdate('c'),
        ],
    ]);
    if (empty($res['ok'])) return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not add Bluesky list member')];
    return ['ok' => true, 'uri' => (string) ($res['json']['uri'] ?? '')];
}

function ap_bsky_delete_graph_list_member(int $ownerUserId, string $itemUri): array
{
    if (!preg_match('~^at://[^/]+/app\.bsky\.graph\.listitem/[^/]+$~', $itemUri)) {
        return ['ok' => false, 'error' => 'Invalid Bluesky list-item URI'];
    }
    return ap_bsky_delete_record_uri($ownerUserId, $itemUri);
}

/** Apply or remove this account's mute/block subscription to an owned mod list. */
function ap_bsky_set_graph_list_moderation(int $ownerUserId, string $listUri, string $action): array
{
    if (!str_starts_with($listUri, 'at://') || !in_array($action, ['mute', 'block'], true)) {
        return ['ok' => false, 'error' => 'Invalid moderation list action'];
    }
    $session = ap_bsky_session_row($ownerUserId);
    if ($session === null) return ['ok' => false, 'error' => 'Bluesky not connected'];
    $kind = $action === 'block' ? 'app.bsky.graph.listblock' : 'app.bsky.graph.listmute';
    $res = ap_bsky_account_xrpc($ownerUserId, 'com.atproto.repo.createRecord', 'POST', null, [
        'repo' => (string) ($session['did'] ?? ''),
        'collection' => $kind,
        'record' => ['$type' => $kind, 'subject' => $listUri, 'createdAt' => gmdate('c')],
    ]);
    if (empty($res['ok'])) return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not subscribe to Bluesky moderation list')];
    ap_bsky_refresh_hide_set($ownerUserId, true);
    return ['ok' => true, 'uri' => (string) ($res['json']['uri'] ?? '')];
}

function ap_bsky_remove_graph_list_moderation(int $ownerUserId, string $listUri, string $action, string $recordUri = ''): array
{
    if (!str_starts_with($listUri, 'at://') || !in_array($action, ['mute', 'block'], true)) {
        return ['ok' => false, 'error' => 'Invalid moderation list action'];
    }
    if ($recordUri !== '') {
        $deleted = ap_bsky_delete_record_uri($ownerUserId, $recordUri);
        if (!empty($deleted['ok'])) {
            ap_bsky_refresh_hide_set($ownerUserId, true);
            return $deleted;
        }
    }
    $session = ap_bsky_session_row($ownerUserId);
    if ($session === null) return ['ok' => false, 'error' => 'Bluesky not connected'];
    $collection = $action === 'block' ? 'app.bsky.graph.listblock' : 'app.bsky.graph.listmute';
    $cursor = null;
    for ($page = 0; $page < 20; $page++) {
        $query = ['repo' => (string) ($session['did'] ?? ''), 'collection' => $collection, 'limit' => 100];
        if ($cursor !== null) $query['cursor'] = $cursor;
        $res = ap_bsky_account_xrpc($ownerUserId, 'com.atproto.repo.listRecords', 'GET', $query);
        if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
            return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not inspect Bluesky list subscriptions')];
        }
        foreach ((array) ($res['json']['records'] ?? []) as $record) {
            $value = is_array($record['value'] ?? null) ? $record['value'] : [];
            if ((string) ($value['subject'] ?? '') === $listUri) {
                $uri = (string) ($record['uri'] ?? '');
                $deleted = $uri !== '' ? ap_bsky_delete_record_uri($ownerUserId, $uri) : ['ok' => false];
                if (!empty($deleted['ok'])) ap_bsky_refresh_hide_set($ownerUserId, true);
                return $deleted;
            }
        }
        $cursor = isset($res['json']['cursor']) && is_string($res['json']['cursor']) ? $res['json']['cursor'] : null;
        if ($cursor === null) break;
    }
    return ['ok' => true, 'already_removed' => true];
}

/** Paginated collection fetch for an owned Bluesky list's hydrated entries. */
function ap_bsky_get_graph_list(int $ownerUserId, string $listUri, int $limit = 500): array
{
    if (!str_starts_with($listUri, 'at://')) return ['ok' => false, 'error' => 'Invalid Bluesky list URI'];
    $items = [];
    $cursor = null;
    $limit = max(1, min(2000, $limit));
    for ($page = 0; $page < 20 && count($items) < $limit; $page++) {
        $query = ['list' => $listUri, 'limit' => min(100, $limit - count($items))];
        if ($cursor !== null) $query['cursor'] = $cursor;
        $res = ap_bsky_account_xrpc($ownerUserId, 'app.bsky.graph.getList', 'GET', $query);
        if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
            return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not load Bluesky list'), 'status' => (int) ($res['status'] ?? 0), 'items' => $items, 'complete' => false];
        }
        $pageItems = is_array($res['json']['items'] ?? null) ? $res['json']['items'] : [];
        $items = array_merge($items, $pageItems);
        $cursor = isset($res['json']['cursor']) && is_string($res['json']['cursor']) ? $res['json']['cursor'] : null;
        if ($cursor === null) return ['ok' => true, 'items' => $items, 'complete' => true];
        if ($pageItems === []) break;
    }
    return ['ok' => true, 'items' => $items, 'complete' => false];
}

/** SQLite creates locally; PostgreSQL tables are provisioned by the owner migration. */
function ap_bsky_starter_pack_cache_ready(?PDO $db = null): bool
{
    static $ready = [];
    $db ??= ap_db();
    $key = spl_object_id($db);
    if (isset($ready[$key])) return $ready[$key];
    try {
        if (function_exists('ap_db_driver') && ap_db_driver($db) === 'pgsql') {
            $st = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name IN ('bsky_starter_pack_cache','bsky_starter_pack_sync_state')");
            $names = array_fill_keys(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
            return $ready[$key] = isset($names['bsky_starter_pack_cache'], $names['bsky_starter_pack_sync_state']);
        }
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_starter_pack_cache (
 owner_user_id INTEGER NOT NULL, pack_uri TEXT NOT NULL, list_uri TEXT NOT NULL,
 name TEXT NOT NULL, description TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL,
 members_json TEXT NOT NULL DEFAULT '[]', updated_at TEXT NOT NULL,
 PRIMARY KEY(owner_user_id, pack_uri)
)
SQL);
        $db->exec('CREATE INDEX IF NOT EXISTS idx_bsky_starter_pack_cache_owner ON bsky_starter_pack_cache(owner_user_id, updated_at DESC)');
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_starter_pack_sync_state (
 owner_user_id INTEGER PRIMARY KEY, synced_at TEXT, updated_at TEXT NOT NULL
)
SQL);
        return $ready[$key] = true;
    } catch (Throwable $e) {
        error_log('[ap-bsky] starter pack cache schema unavailable');
        return $ready[$key] = false;
    }
}

/** Refresh owned starter packs and their linked curated-list members in a worker. */
function ap_bsky_starter_packs_refresh_worker(int $ownerUserId): array
{
    if ($ownerUserId < 1 || !ap_bsky_starter_pack_cache_ready()) return ['ok' => false, 'error' => 'Starter pack cache is unavailable'];
    $session = ap_bsky_session_row($ownerUserId);
    if ($session === null) return ['ok' => false, 'error' => 'Bluesky not connected'];
    $repo = (string) ($session['did'] ?? '');
    $cursor = null; $records = []; $complete = false;
    for ($page = 0; $page < 10; $page++) {
        $q = ['repo' => $repo, 'collection' => 'app.bsky.graph.starterpack', 'limit' => 100];
        if ($cursor !== null) $q['cursor'] = $cursor;
        $res = ap_bsky_account_xrpc($ownerUserId, 'com.atproto.repo.listRecords', 'GET', $q);
        if (empty($res['ok']) || !is_array($res['json'] ?? null)) return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not fetch starter packs')];
        foreach ((array) ($res['json']['records'] ?? []) as $row) {
            $value = is_array($row['value'] ?? null) ? $row['value'] : [];
            $uri = (string) ($row['uri'] ?? ''); $list = (string) ($value['list'] ?? '');
            if (str_starts_with($uri, 'at://') && str_starts_with($list, 'at://')) $records[$uri] = ['uri'=>$uri,'list'=>$list,'value'=>$value];
        }
        $cursor = isset($res['json']['cursor']) && is_string($res['json']['cursor']) ? $res['json']['cursor'] : null;
        if ($cursor === null) { $complete = true; break; }
    }
    if (!$complete) return ['ok' => false, 'error' => 'Starter pack listing was incomplete'];
    $db = ap_db(); $now = gmdate('c');
    try {
        $up = $db->prepare('INSERT INTO bsky_starter_pack_cache (owner_user_id,pack_uri,list_uri,name,description,created_at,members_json,updated_at) VALUES (?,?,?,?,?,?,?,?) ON CONFLICT(owner_user_id,pack_uri) DO UPDATE SET list_uri=excluded.list_uri,name=excluded.name,description=excluded.description,created_at=excluded.created_at,members_json=excluded.members_json,updated_at=excluded.updated_at');
        foreach ($records as $uri => $pack) {
            $members = ap_bsky_get_graph_list($ownerUserId, $pack['list'], 500);
            if (empty($members['ok']) || empty($members['complete'])) continue;
            $items = [];
            foreach ((array) ($members['items'] ?? []) as $entry) {
                $subject = is_array($entry['subject'] ?? null) ? $entry['subject'] : [];
                $did = (string) ($subject['did'] ?? '');
                if (!str_starts_with($did, 'did:')) continue;
                $items[] = ['did'=>$did,'uri'=>(string)($entry['uri'] ?? ''),'handle'=>(string)($subject['handle'] ?? ''),'displayName'=>(string)($subject['displayName'] ?? ''),'avatar'=>(string)($subject['avatar'] ?? '')];
            }
            $v = $pack['value'];
            $up->execute([$ownerUserId,$uri,$pack['list'],mb_substr((string)($v['name'] ?? 'Starter Pack'),0,64),mb_substr((string)($v['description'] ?? ''),0,300), (string)($v['createdAt'] ?? $now), json_encode($items,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$now]);
        }
        $del = $db->prepare('DELETE FROM bsky_starter_pack_cache WHERE owner_user_id=? AND pack_uri=?');
        $st = $db->prepare('SELECT pack_uri FROM bsky_starter_pack_cache WHERE owner_user_id=?'); $st->execute([$ownerUserId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $uri) if (!isset($records[(string)$uri])) $del->execute([$ownerUserId,(string)$uri]);
        $db->prepare('INSERT INTO bsky_starter_pack_sync_state(owner_user_id,synced_at,updated_at) VALUES(?,?,?) ON CONFLICT(owner_user_id) DO UPDATE SET synced_at=excluded.synced_at,updated_at=excluded.updated_at')->execute([$ownerUserId,$now,$now]);
        return ['ok'=>true,'synced'=>count($records)];
    } catch (Throwable $e) { error_log('[ap-bsky] starter pack cache write failed'); return ['ok'=>false,'error'=>'Could not store starter packs']; }
}

function ap_bsky_starter_packs_cached(int $ownerUserId): array
{
    if (!ap_bsky_starter_pack_cache_ready()) return [];
    try {
        $state=ap_db()->prepare('SELECT synced_at FROM bsky_starter_pack_sync_state WHERE owner_user_id=?'); $state->execute([$ownerUserId]);
        $syncedAt=strtotime((string)($state->fetchColumn() ?: '')) ?: 0;
        if ($syncedAt < time()-300) ap_bsky_starter_packs_enqueue($ownerUserId);
        $st=ap_db()->prepare('SELECT pack_uri,list_uri,name,description,created_at,members_json,updated_at FROM bsky_starter_pack_cache WHERE owner_user_id=? ORDER BY LOWER(name)'); $st->execute([$ownerUserId]);
        return array_map(static function(array $r): array { $r['members']=json_decode((string)$r['members_json'],true) ?: []; unset($r['members_json']); return $r; }, $st->fetchAll() ?: []);
    } catch (Throwable $e) { return []; }
}

function ap_bsky_starter_packs_enqueue(int $ownerUserId, bool $force = false): bool
{
    return ap_bsky_background_sync_enqueue($ownerUserId, 'starter_packs', $force);
}

function ap_bsky_starter_pack_create(int $ownerUserId, string $name, string $description = ''): array
{
    $name=trim($name); if ($name==='' || mb_strlen($name)>50) return ['ok'=>false,'error'=>'Name must be 1–50 characters'];
    $list=ap_bsky_create_graph_list($ownerUserId,$name,'curation'); if (empty($list['ok'])) return $list;
    $session=ap_bsky_session_row($ownerUserId); $did=(string)($session['did']??'');
    $made=ap_bsky_account_xrpc($ownerUserId,'com.atproto.repo.createRecord','POST',null,['repo'=>$did,'collection'=>'app.bsky.graph.starterpack','record'=>['$type'=>'app.bsky.graph.starterpack','name'=>$name,'description'=>mb_substr(trim($description),0,300),'list'=>(string)$list['uri'],'createdAt'=>gmdate('c')]]);
    if (empty($made['ok'])) { ap_bsky_delete_record_uri($ownerUserId,(string)$list['uri']); return ['ok'=>false,'error'=>(string)($made['error']??'Could not create Starter Pack')]; }
    ap_bsky_starter_packs_enqueue($ownerUserId,true); return ['ok'=>true,'uri'=>(string)($made['json']['uri']??''),'list_uri'=>(string)$list['uri']];
}

function ap_bsky_starter_pack_add_member(int $ownerUserId, string $packUri, string $ref): array
{
    $pack=null; foreach(ap_bsky_starter_packs_cached($ownerUserId) as $p) if (($p['pack_uri']??'')===$packUri) {$pack=$p;break;}
    if (!$pack) return ['ok'=>false,'error'=>'Starter Pack not found in your account'];
    $ref=trim($ref);
    $isBskyInput=str_starts_with($ref,'did:') || preg_match('~^https://bsky\.app/profile/[^/?#]+$~i',$ref)
        || preg_match('/^@?[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/i',$ref);
    if (!$isBskyInput) return ['ok'=>false,'error'=>'Starter Packs can only contain Bluesky accounts. Enter a Bluesky handle, DID, or bsky.app profile URL.'];
    $did=ap_bsky_resolve_target_did($ref,$ownerUserId); if (!is_string($did) || !str_starts_with($did,'did:')) return ['ok'=>false,'error'=>'Could not resolve that Bluesky account'];
    $made=ap_bsky_add_graph_list_member($ownerUserId,(string)$pack['list_uri'],$did);
    if (!empty($made['ok'])) ap_bsky_starter_packs_enqueue($ownerUserId,true);
    return $made;
}

function ap_bsky_starter_pack_remove_member(int $ownerUserId, string $packUri, string $did): array
{
    foreach(ap_bsky_starter_packs_cached($ownerUserId) as $p) if (($p['pack_uri']??'')===$packUri) {
        foreach((array)$p['members'] as $m) if (($m['did']??'')===$did && str_starts_with((string)($m['uri']??''),'at://')) {
            $res=ap_bsky_delete_graph_list_member($ownerUserId,(string)$m['uri']); if (!empty($res['ok'])) ap_bsky_starter_packs_enqueue($ownerUserId,true); return $res;
        }
    }
    return ['ok'=>false,'error'=>'Starter Pack member not found'];
}

function ap_bsky_starter_pack_delete(int $ownerUserId, string $packUri): array
{
    foreach(ap_bsky_starter_packs_cached($ownerUserId) as $p) if (($p['pack_uri']??'')===$packUri) {
        foreach((array)$p['members'] as $m) if (str_starts_with((string)($m['uri']??''),'at://')) ap_bsky_delete_graph_list_member($ownerUserId,(string)$m['uri']);
        $pack=ap_bsky_delete_record_uri($ownerUserId,$packUri); if (empty($pack['ok'])) return $pack;
        $list=ap_bsky_delete_record_uri($ownerUserId,(string)$p['list_uri']);
        try { ap_db()->prepare('DELETE FROM bsky_starter_pack_cache WHERE owner_user_id=? AND pack_uri=?')->execute([$ownerUserId,$packUri]); } catch(Throwable $e) {}
        return !empty($list['ok']) ? ['ok'=>true] : ['ok'=>true,'warning'=>'Starter Pack removed; its Bluesky list could not be deleted'];
    }
    return ['ok'=>false,'error'=>'Starter Pack not found in your account'];
}

// ---------------------------------------------------------------------------
// Moderation sync: VAAK mute/block ↔ Bluesky + subscribed blocklists hide set
// ---------------------------------------------------------------------------

function ap_bsky_graph_sync_migrate(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $driver = function_exists('ap_db_driver') ? ap_db_driver() : 'sqlite';
        if ($driver === 'pgsql') {
            ap_db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_graph_sync (
    owner_user_id BIGINT NOT NULL,
    kind TEXT NOT NULL,
    target_did TEXT NOT NULL,
    bsky_uri TEXT,
    source TEXT NOT NULL DEFAULT 'vaak',
    updated_at TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, kind, target_did)
)
SQL);
            ap_db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_hide_dids (
    owner_user_id BIGINT NOT NULL,
    did TEXT NOT NULL,
    reason TEXT NOT NULL,
    list_uri TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, did, reason, list_uri)
)
SQL);
        } else {
            ap_db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_graph_sync (
    owner_user_id INTEGER NOT NULL,
    kind TEXT NOT NULL,
    target_did TEXT NOT NULL,
    bsky_uri TEXT,
    source TEXT NOT NULL DEFAULT 'vaak',
    updated_at TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, kind, target_did)
)
SQL);
            ap_db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_hide_dids (
    owner_user_id INTEGER NOT NULL,
    did TEXT NOT NULL,
    reason TEXT NOT NULL,
    list_uri TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, did, reason, list_uri)
)
SQL);
        }
    } catch (Throwable $e) {
        // ops may provision
    }
}

/** Resolve a Bluesky DID from an actor URL, handle, or DID string. */
function ap_bsky_resolve_target_did(string $actorOrHandle, int $ownerUserId = 0): ?string
{
    $raw = trim($actorOrHandle);
    if ($raw === '') {
        return null;
    }
    if (str_starts_with($raw, 'did:')) {
        return $raw;
    }
    if (str_starts_with($raw, 'at://did:')) {
        if (preg_match('~^at://(did:[^/]+)~', $raw, $m)) {
            return $m[1];
        }
    }
    // https://bsky.app/profile/{did|handle}
    if (preg_match('~^https://bsky\.app/profile/([^/?#]+)~i', $raw, $m)) {
        $part = rawurldecode($m[1]);
        if (str_starts_with($part, 'did:')) {
            return $part;
        }
        return ap_bsky_resolve_handle_did($part, $ownerUserId);
    }
    // Bridgy: …/ap/did:plc:…
    if (preg_match('~/(?:ap/)?(did:plc:[a-z0-9]+)~i', $raw, $m)) {
        return $m[1];
    }
    // Bare handle
    if (preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/i', $raw) || str_contains($raw, '.bsky.')) {
        return ap_bsky_resolve_handle_did(ltrim($raw, '@'), $ownerUserId);
    }
    // alsoKnownAs on remote_actors / cached profile — best effort via path username
    return null;
}

function ap_bsky_resolve_handle_did(string $handle, int $ownerUserId = 0): ?string
{
    $handle = ltrim(trim($handle), '@');
    if ($handle === '') {
        return null;
    }
    $tok = null;
    $pds = AP_BSKY_PUBLIC_API;
    if ($ownerUserId > 0) {
        $row = ap_bsky_session_row($ownerUserId);
        if (is_array($row)) {
            $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_PUBLIC_API), '/');
            $tok = ap_bsky_access_token($ownerUserId, false);
            if (empty($tok['ok'])) {
                $tok = ap_bsky_access_token($ownerUserId, true);
            }
        }
    }
    $bearer = (!empty($tok['ok'])) ? (string) $tok['access'] : null;
    foreach (array_unique([$pds, AP_BSKY_PUBLIC_API]) as $host) {
        $r = ap_bsky_xrpc($host, 'com.atproto.identity.resolveHandle', 'GET', ['handle' => $handle], null, $bearer, 8);
        if (!empty($r['ok']) && !empty($r['json']['did']) && is_string($r['json']['did'])) {
            return (string) $r['json']['did'];
        }
        // Fallback: getProfile
        $r = ap_bsky_xrpc($host, 'app.bsky.actor.getProfile', 'GET', ['actor' => $handle], null, $bearer, 8);
        if (!empty($r['ok']) && !empty($r['json']['did']) && is_string($r['json']['did'])) {
            return (string) $r['json']['did'];
        }
    }
    return null;
}

function ap_bsky_graph_sync_upsert(
    int $ownerUserId,
    string $kind,
    string $did,
    ?string $bskyUri = null,
    string $source = 'vaak'
): void {
    ap_bsky_graph_sync_migrate();
    try {
        $st = ap_db()->prepare(
            'INSERT INTO bsky_graph_sync (owner_user_id, kind, target_did, bsky_uri, source, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (owner_user_id, kind, target_did) DO UPDATE SET
               bsky_uri = COALESCE(excluded.bsky_uri, bsky_graph_sync.bsky_uri),
               source = excluded.source,
               updated_at = excluded.updated_at'
        );
        $st->execute([$ownerUserId, $kind, $did, $bskyUri, $source, gmdate('c')]);
    } catch (Throwable $e) {
        error_log('[ap-bsky] graph_sync_upsert: ' . $e->getMessage());
    }
}

function ap_bsky_graph_sync_get(int $ownerUserId, string $kind, string $did): ?array
{
    ap_bsky_graph_sync_migrate();
    try {
        $st = ap_db()->prepare(
            'SELECT * FROM bsky_graph_sync WHERE owner_user_id = ? AND kind = ? AND target_did = ? LIMIT 1'
        );
        $st->execute([$ownerUserId, $kind, $did]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ap_bsky_graph_sync_delete(int $ownerUserId, string $kind, string $did): void
{
    try {
        ap_db()->prepare(
            'DELETE FROM bsky_graph_sync WHERE owner_user_id = ? AND kind = ? AND target_did = ?'
        )->execute([$ownerUserId, $kind, $did]);
    } catch (Throwable $e) {
        // ignore
    }
}

/** True if a follow/profile target is a Bluesky identity (not ActivityPub). */
function ap_bsky_is_profile_ref(string $ref): bool
{
    $ref = trim($ref);
    if ($ref === '') {
        return false;
    }
    if (str_starts_with($ref, 'did:')) {
        return true;
    }
    if (str_starts_with($ref, 'https://bsky.app/profile/')) {
        return true;
    }
    if (str_starts_with($ref, 'at://did:')) {
        return true;
    }
    return false;
}

/**
 * Fetch a Bluesky profile (app.bsky.actor.getProfile).
 *
 * @return array{ok:bool,error?:string,profile?:array<string,mixed>}
 */
function ap_bsky_get_profile(int $ownerUserId, string $actor): array
{
    $actor = trim($actor);
    if ($actor === '') {
        return ['ok' => false, 'error' => 'Missing actor'];
    }
    if (str_starts_with($actor, 'https://bsky.app/profile/')) {
        if (preg_match('~^https://bsky\.app/profile/([^/?#]+)~i', $actor, $m)) {
            $actor = rawurldecode($m[1]);
        }
    }
    if (str_starts_with($actor, 'at://did:')) {
        if (preg_match('~^at://(did:[^/]+)~', $actor, $m)) {
            $actor = $m[1];
        }
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Not connected')];
    }
    $row = ap_bsky_session_row($ownerUserId);
    $hosts = ap_bsky_feed_hosts(rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/'));
    $lastErr = 'getProfile failed';
    foreach ($hosts as $host) {
        $r = ap_bsky_xrpc($host, 'app.bsky.actor.getProfile', 'GET', ['actor' => $actor], null, (string) $tok['access'], 12);
        if (($r['status'] ?? 0) === 401) {
            $tok = ap_bsky_access_token($ownerUserId, true);
            if (empty($tok['ok'])) {
                return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Session expired')];
            }
            $r = ap_bsky_xrpc($host, 'app.bsky.actor.getProfile', 'GET', ['actor' => $actor], null, (string) $tok['access'], 12);
        }
        if (!empty($r['ok']) && is_array($r['json'] ?? null)) {
            return ['ok' => true, 'profile' => $r['json']];
        }
        $lastErr = (string) ($r['error'] ?? $lastErr);
    }
    return ['ok' => false, 'error' => $lastErr];
}

/** Persistent, owner-independent public profile cache plus owner-specific viewer state. */
function ap_bsky_actor_refresh_migrate(?PDO $db = null): bool
{
    static $readyByConnection = [];
    $db ??= ap_db();
    $key = spl_object_id($db);
    if (isset($readyByConnection[$key])) return $readyByConnection[$key];
    try {
        if (function_exists('ap_db_driver') && ap_db_driver($db) === 'pgsql') {
            $st = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name IN ('bsky_actor_profiles', 'bsky_actor_viewers', 'bsky_actor_refresh_queue')");
            $names = array_fill_keys(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)), true);
            $ready = isset($names['bsky_actor_profiles'], $names['bsky_actor_viewers'], $names['bsky_actor_refresh_queue']);
            if (!$ready) error_log('[ap-bsky] actor refresh tables require owner provisioning; runtime role did not attempt DDL');
            return $readyByConnection[$key] = $ready;
        }
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_actor_profiles (
    actor_ref TEXT PRIMARY KEY,
    did TEXT,
    profile_json TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL);
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_actor_viewers (
    owner_user_id INTEGER NOT NULL,
    actor_ref TEXT NOT NULL,
    following_uri TEXT,
    followed_by INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, actor_ref)
)
SQL);
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS bsky_actor_refresh_queue (
    owner_user_id INTEGER NOT NULL,
    actor_ref TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    queued_at TEXT NOT NULL,
    next_attempt_at TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    locked_at TEXT,
    last_error TEXT,
    PRIMARY KEY (owner_user_id, actor_ref)
)
SQL);
        $db->exec('CREATE INDEX IF NOT EXISTS idx_bsky_actor_refresh_ready ON bsky_actor_refresh_queue (status, next_attempt_at, queued_at)');
        return $readyByConnection[$key] = true;
    } catch (Throwable $e) {
        error_log('[ap-bsky] actor refresh schema: ' . $e->getMessage());
        return $readyByConnection[$key] = false;
    }
}

/** @return array{profile:array<string,mixed>,did:?string,updated_at:string,viewer?:array<string,mixed>}|null */
function ap_bsky_actor_profile_cache_get(string $actorRef, int $ownerUserId = 0, ?PDO $db = null): ?array
{
    $db ??= ap_db();
    if (!ap_bsky_actor_refresh_migrate($db)) return null;
    $actorRef = trim($actorRef);
    if ($actorRef === '') return null;
    $keys = [$actorRef];
    if (preg_match('~^https://bsky\.app/profile/([^/?#]+)~i', $actorRef, $m)) {
        $ident = rawurldecode($m[1]);
        $keys[] = 'https://bsky.app/profile/' . $ident;
        $keys[] = 'https://bsky.app/profile/' . rawurlencode($ident);
        $keys[] = $ident;
        if (str_starts_with($ident, 'did:')) {
            $keys[] = $ident;
        }
    } elseif (str_starts_with($actorRef, 'did:')) {
        $keys[] = 'https://bsky.app/profile/' . $actorRef;
        $keys[] = 'https://bsky.app/profile/' . rawurlencode($actorRef);
    }
    $keys = array_values(array_unique(array_filter($keys)));
    try {
        $row = null;
        $st = $db->prepare('SELECT * FROM bsky_actor_profiles WHERE actor_ref = ? LIMIT 1');
        foreach ($keys as $key) {
            $st->execute([$key]);
            $hit = $st->fetch();
            if (is_array($hit)) {
                $row = $hit;
                $actorRef = $key;
                break;
            }
        }
        if (!is_array($row)) {
            $didKey = null;
            foreach ($keys as $key) {
                if (str_starts_with($key, 'did:')) {
                    $didKey = $key;
                    break;
                }
            }
            if ($didKey !== null) {
                $byDid = $db->prepare('SELECT * FROM bsky_actor_profiles WHERE did = ? ORDER BY updated_at DESC LIMIT 1');
                $byDid->execute([$didKey]);
                $hit = $byDid->fetch();
                if (is_array($hit)) {
                    $row = $hit;
                    $actorRef = (string) ($hit['actor_ref'] ?? $didKey);
                }
            }
        }
        if (!is_array($row)) return null;
        $profile = json_decode((string) ($row['profile_json'] ?? ''), true);
        if (!is_array($profile)) return null;
        $out = ['profile' => $profile, 'did' => (string) ($row['did'] ?? ''), 'updated_at' => (string) ($row['updated_at'] ?? '')];
        if ($ownerUserId > 0) {
            $vs = $db->prepare('SELECT following_uri, followed_by FROM bsky_actor_viewers WHERE owner_user_id = ? AND actor_ref = ? LIMIT 1');
            $vs->execute([$ownerUserId, $actorRef]);
            $viewer = $vs->fetch();
            if (is_array($viewer)) {
                $out['viewer'] = [
                    'following' => (string) ($viewer['following_uri'] ?? ''),
                    'followedBy' => !empty($viewer['followed_by']),
                ];
            }
        }
        return $out;
    } catch (Throwable $e) {
        return null;
    }
}

function ap_bsky_actor_profile_cache_upsert(string $actorRef, int $ownerUserId, array $profile, ?PDO $db = null): void
{
    $db ??= ap_db();
    if (!ap_bsky_actor_refresh_migrate($db)) return;
    $actorRef = trim($actorRef);
    if ($actorRef === '' || $ownerUserId < 1) return;
    $viewer = is_array($profile['viewer'] ?? null) ? $profile['viewer'] : [];
    $did = trim((string) ($profile['did'] ?? ''));
    unset($profile['viewer']); // relationship state is scoped to the VAAK owner below.
    $json = json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) return;
    $now = gmdate('c');
    try {
        $st = $db->prepare('INSERT INTO bsky_actor_profiles (actor_ref, did, profile_json, updated_at) VALUES (?, ?, ?, ?) ON CONFLICT (actor_ref) DO UPDATE SET did = excluded.did, profile_json = excluded.profile_json, updated_at = excluded.updated_at');
        $st->execute([$actorRef, $did !== '' ? $did : null, $json, $now]);
        $vs = $db->prepare('INSERT INTO bsky_actor_viewers (owner_user_id, actor_ref, following_uri, followed_by, updated_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT (owner_user_id, actor_ref) DO UPDATE SET following_uri = excluded.following_uri, followed_by = excluded.followed_by, updated_at = excluded.updated_at');
        $vs->execute([$ownerUserId, $actorRef, is_string($viewer['following'] ?? null) ? $viewer['following'] : null, !empty($viewer['followedBy']) ? 1 : 0, $now]);
        if ($did !== '' && $did !== $actorRef) {
            $st->execute([$did, $did, $json, $now]);
            $vs->execute([$ownerUserId, $did, is_string($viewer['following'] ?? null) ? $viewer['following'] : null, !empty($viewer['followedBy']) ? 1 : 0, $now]);
        }
    } catch (Throwable $e) {
        error_log('[ap-bsky] actor profile cache write failed');
    }
}

/** Keep cached viewer state aligned with queued Follow/Unfollow completions. */
function ap_bsky_actor_viewer_cache_update(int $ownerUserId, string $did, ?string $followingUri): void
{
    if ($ownerUserId < 1 || !str_starts_with($did, 'did:')) return;
    $db = ap_db();
    if (!ap_bsky_actor_refresh_migrate($db)) return;
    $now = gmdate('c');
    $refs = [$did, 'https://bsky.app/profile/' . rawurlencode($did)];
    foreach ($refs as $ref) {
        try {
            $st = $db->prepare('SELECT followed_by FROM bsky_actor_viewers WHERE owner_user_id = ? AND actor_ref = ? LIMIT 1');
            $st->execute([$ownerUserId, $ref]);
            $followsYou = $st->fetchColumn();
            $up = $db->prepare('INSERT INTO bsky_actor_viewers (owner_user_id, actor_ref, following_uri, followed_by, updated_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT (owner_user_id, actor_ref) DO UPDATE SET following_uri = excluded.following_uri, updated_at = excluded.updated_at');
            $up->execute([$ownerUserId, $ref, $followingUri, $followsYou === false ? 0 : (int) $followsYou, $now]);
        } catch (Throwable $e) {
            // The action queue remains authoritative if this optional cache is unavailable.
        }
    }
}

function ap_bsky_follow_sync_enqueue(int $ownerUserId, ?PDO $db = null): void
{
    $db ??= ap_db();
    if ($ownerUserId < 1 || ap_bsky_session_row($ownerUserId) === null || !ap_bsky_actor_refresh_migrate($db)) {
        return;
    }
    $actorRef = '__vaak_sync__:follows';
    $now = gmdate('c');
    try {
        $st = $db->prepare('SELECT status, queued_at FROM bsky_actor_refresh_queue WHERE owner_user_id = ? AND actor_ref = ? LIMIT 1');
        $st->execute([$ownerUserId, $actorRef]);
        $existing = $st->fetch();
        if (is_array($existing)) {
            $status = (string) ($existing['status'] ?? '');
            $queuedAt = strtotime((string) ($existing['queued_at'] ?? '')) ?: 0;
            if (in_array($status, ['pending', 'processing'], true)
                || ($status === 'succeeded' && $queuedAt > time() - 600)) {
                return;
            }
        }
        $up = $db->prepare("INSERT INTO bsky_actor_refresh_queue (owner_user_id, actor_ref, status, queued_at, next_attempt_at, attempts) VALUES (?, ?, 'pending', ?, ?, 0) ON CONFLICT (owner_user_id, actor_ref) DO UPDATE SET status = 'pending', queued_at = excluded.queued_at, next_attempt_at = excluded.next_attempt_at, attempts = 0, locked_at = NULL, last_error = NULL WHERE bsky_actor_refresh_queue.status IN ('succeeded', 'failed')");
        $up->execute([$ownerUserId, $actorRef, $now, $now]);
    } catch (Throwable $e) {
        error_log('[ap-bsky] follow sync enqueue failed');
    }
}

function ap_bsky_follow_sync_worker(int $ownerUserId): array
{
    $session = ap_bsky_session_row($ownerUserId);
    $repo = trim((string) ($session['did'] ?? ''));
    if ($repo === '' || !str_starts_with($repo, 'did:')) {
        return ['ok' => false, 'error' => 'Connected Bluesky DID unavailable'];
    }
    $follows = [];
    $cursor = null;
    for ($page = 0; $page < 25; $page++) {
        $query = ['repo' => $repo, 'collection' => 'app.bsky.graph.follow', 'limit' => 100];
        if (is_string($cursor) && $cursor !== '') {
            $query['cursor'] = $cursor;
        }
        $res = ap_bsky_account_xrpc($ownerUserId, 'com.atproto.repo.listRecords', 'GET', $query);
        if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
            return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not read Bluesky follows')];
        }
        foreach ((array) ($res['json']['records'] ?? []) as $record) {
            if (!is_array($record)) {
                continue;
            }
            $value = is_array($record['value'] ?? null) ? $record['value'] : [];
            $did = trim((string) ($value['subject'] ?? ''));
            if (!str_starts_with($did, 'did:')) {
                continue;
            }
            $uri = trim((string) ($record['uri'] ?? ''));
            $follows[$did] = $uri !== '' ? $uri : null;
        }
        $cursor = isset($res['json']['cursor']) && is_string($res['json']['cursor']) && $res['json']['cursor'] !== ''
            ? $res['json']['cursor'] : null;
        if ($cursor === null) {
            break;
        }
    }
    if ($cursor !== null) {
        return ['ok' => false, 'error' => 'Bluesky follow list exceeds the safe per-run page limit'];
    }
    ap_bsky_graph_sync_migrate();
    $db = ap_db();
    try {
        $db->beginTransaction();
        $existing = $db->prepare("SELECT target_did FROM bsky_graph_sync WHERE owner_user_id = ? AND kind = 'follow'");
        $existing->execute([$ownerUserId]);
        $existingDids = array_map('strval', $existing->fetchAll(PDO::FETCH_COLUMN) ?: []);
        foreach ($follows as $did => $uri) {
            ap_bsky_graph_sync_upsert($ownerUserId, 'follow', $did, $uri, 'pull');
        }
        foreach ($existingDids as $did) {
            if (!array_key_exists($did, $follows)) {
                ap_bsky_graph_sync_delete($ownerUserId, 'follow', $did);
            }
        }
        $profiles = $db->prepare('SELECT DISTINCT did FROM bsky_actor_profiles WHERE did IS NOT NULL AND did <> ?');
        $profiles->execute(['']);
        foreach ($profiles->fetchAll(PDO::FETCH_COLUMN) ?: [] as $did) {
            if (is_string($did) && str_starts_with($did, 'did:')) {
                ap_bsky_actor_viewer_cache_update($ownerUserId, $did, $follows[$did] ?? null);
            }
        }
        $db->commit();
        return ['ok' => true, 'count' => count($follows)];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not reconcile Bluesky follow cache'];
    }
}

/** Coalesced durable enqueue; no network I/O occurs on the web request. */
function ap_bsky_actor_refresh_enqueue(int $ownerUserId, string $actorRef, bool $force = false, ?PDO $db = null): void
{
    $db ??= ap_db();
    if (!ap_bsky_actor_refresh_migrate($db)) return;
    $actorRef = trim($actorRef);
    if ($ownerUserId < 1 || $actorRef === '' || !ap_bsky_is_profile_ref($actorRef)) return;
    $cached = ap_bsky_actor_profile_cache_get($actorRef, $ownerUserId, $db);
    if (!$force && is_array($cached) && isset($cached['viewer'])
        && (strtotime($cached['updated_at']) ?: 0) > time() - 6 * 3600) return;
    $now = gmdate('c');
    try {
        $st = $db->prepare("INSERT INTO bsky_actor_refresh_queue (owner_user_id, actor_ref, status, queued_at, next_attempt_at, attempts) VALUES (?, ?, 'pending', ?, ?, 0) ON CONFLICT (owner_user_id, actor_ref) DO UPDATE SET status = 'pending', queued_at = excluded.queued_at, next_attempt_at = excluded.next_attempt_at, attempts = 0, locked_at = NULL, last_error = NULL WHERE bsky_actor_refresh_queue.status IN ('succeeded', 'failed')");
        $st->execute([$ownerUserId, $actorRef, $now, $now]);
    } catch (Throwable $e) {
        error_log('[ap-bsky] actor refresh enqueue failed');
    }
}

/** @return array{claimed:int,succeeded:int,retried:int,failed:int} */
function ap_bsky_actor_refresh_worker_run(int $limit = 3): array
{
    if (!ap_bsky_actor_refresh_migrate()) return ['claimed' => 0, 'succeeded' => 0, 'retried' => 0, 'failed' => 0];
    $stats = ['claimed' => 0, 'succeeded' => 0, 'retried' => 0, 'failed' => 0];
    $db = ap_db();
    $now = gmdate('c');
    $stale = gmdate('c', time() - 600);
    $staleJobs = $db->prepare("SELECT owner_user_id, actor_ref, attempts FROM bsky_actor_refresh_queue WHERE status = 'processing' AND locked_at < ?");
    $staleJobs->execute([$stale]);
    foreach ($staleJobs->fetchAll() ?: [] as $staleJob) {
        $owner = (int) $staleJob['owner_user_id'];
        $actorRef = (string) $staleJob['actor_ref'];
        $attempt = (int) $staleJob['attempts'] + 1;
        $dead = $attempt >= 8;
        $delay = min(3600, 30 * (2 ** min(7, max(0, $attempt - 1))));
        $db->prepare("UPDATE bsky_actor_refresh_queue SET status = ?, attempts = ?, next_attempt_at = ?, locked_at = NULL, last_error = 'Worker lease expired' WHERE owner_user_id = ? AND actor_ref = ? AND status = 'processing'")
            ->execute([$dead ? 'failed' : 'pending', $attempt, gmdate('c', time() + $delay), $owner, $actorRef]);
    }
    $st = $db->prepare("SELECT owner_user_id, actor_ref FROM bsky_actor_refresh_queue WHERE status = 'pending' AND next_attempt_at <= ? ORDER BY CASE WHEN actor_ref LIKE '__vaak_sync__:%' THEN 0 ELSE 1 END, queued_at LIMIT ?");
    $st->bindValue(1, $now);
    $st->bindValue(2, max(1, min(15, $limit)), PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll() ?: [] as $job) {
        $owner = (int) ($job['owner_user_id'] ?? 0);
        $actorRef = (string) ($job['actor_ref'] ?? '');
        $claim = $db->prepare("UPDATE bsky_actor_refresh_queue SET status = 'processing', locked_at = ? WHERE owner_user_id = ? AND actor_ref = ? AND status = 'pending'");
        $claim->execute([gmdate('c'), $owner, $actorRef]);
        if ($claim->rowCount() !== 1) continue;
        $stats['claimed']++;
        try {
            if (str_starts_with($actorRef, '__vaak_profile_counts__:')) {
                $handle = substr($actorRef, strlen('__vaak_profile_counts__:'));
                $result = function_exists('ap_bsky_public_profile_counts')
                    ? ap_bsky_public_profile_counts($handle, 120, true)
                    : ['ok' => false, 'error' => 'Counts helper missing'];
                if (empty($result['ok'])) {
                    throw new RuntimeException((string) ($result['error'] ?? 'Bluesky profile counts refresh failed'));
                }
                $db->prepare("UPDATE bsky_actor_refresh_queue SET status = 'succeeded', attempts = 0, locked_at = NULL, last_error = NULL WHERE owner_user_id = ? AND actor_ref = ?")->execute([$owner, $actorRef]);
                $stats['succeeded']++;
                continue;
            }
            if (str_starts_with($actorRef, '__vaak_sync__:')) {
                $kind = substr($actorRef, strlen('__vaak_sync__:'));
                $result = ['ok' => false, 'error' => 'Unknown collection sync'];
                if ($kind === 'lists') {
                    require_once __DIR__ . '/ap-lists.php';
                    $result = function_exists('ap_lists_sync_bsky')
                        ? ap_lists_sync_bsky($owner, true)
                        : ['ok' => false, 'error' => 'List sync unavailable'];
                } elseif ($kind === 'starter_packs') {
                    $result = ap_bsky_starter_packs_refresh_worker($owner);
                } elseif ($kind === 'favourites' && function_exists('ap_bsky_get_favourites')) {
                    $result = ap_bsky_get_favourites($owner, 200, true);
                } elseif ($kind === 'favourites') {
                    $result = ['ok' => false, 'error' => 'Favourites sync unavailable'];
                } elseif ($kind === 'bookmarks' && function_exists('ap_bsky_get_bookmarks')) {
                    $result = ap_bsky_get_bookmarks($owner, 200, true);
                } elseif ($kind === 'bookmarks') {
                    $result = ['ok' => true, 'skipped' => true];
                } elseif ($kind === 'follows') {
                    $result = function_exists('ap_bsky_follow_sync_worker')
                        ? ap_bsky_follow_sync_worker($owner)
                        : ['ok' => true, 'skipped' => true];
                }
                if (empty($result['ok'])) {
                    throw new RuntimeException((string) ($result['error'] ?? ($kind . ' synchronization failed')));
                }
                $db->prepare("UPDATE bsky_actor_refresh_queue SET status = 'succeeded', attempts = 0, locked_at = NULL, last_error = NULL WHERE owner_user_id = ? AND actor_ref = ?")->execute([$owner, $actorRef]);
                $stats['succeeded']++;
                continue;
            }
            $result = ap_bsky_get_profile($owner, $actorRef);
            if (empty($result['ok']) || !is_array($result['profile'] ?? null)) {
                throw new RuntimeException((string) ($result['error'] ?? 'Profile fetch failed'));
            }
            ap_bsky_actor_profile_cache_upsert($actorRef, $owner, $result['profile']);
            $db->prepare("UPDATE bsky_actor_refresh_queue SET status = 'succeeded', attempts = 0, locked_at = NULL, last_error = NULL WHERE owner_user_id = ? AND actor_ref = ?")->execute([$owner, $actorRef]);
            $stats['succeeded']++;
        } catch (Throwable $e) {
            $cur = $db->prepare('SELECT attempts FROM bsky_actor_refresh_queue WHERE owner_user_id = ? AND actor_ref = ?');
            $cur->execute([$owner, $actorRef]);
            $attempt = (int) $cur->fetchColumn() + 1;
            $failed = $attempt >= 8;
            $delay = min(3600, 30 * (2 ** min(7, max(0, $attempt - 1))));
            $db->prepare('UPDATE bsky_actor_refresh_queue SET status = ?, attempts = ?, next_attempt_at = ?, locked_at = NULL, last_error = ? WHERE owner_user_id = ? AND actor_ref = ?')
                ->execute([$failed ? 'failed' : 'pending', $attempt, gmdate('c', time() + $delay), substr($e->getMessage(), 0, 300), $owner, $actorRef]);
            $stats[$failed ? 'failed' : 'retried']++;
        }
    }
    return $stats;
}

/**
 * Follow a Bluesky DID via app.bsky.graph.follow.
 *
 * @return array{ok:bool,error?:string,skipped?:bool,already?:bool,uri?:string}
 */
function ap_bsky_follow_actor(int $ownerUserId, string $didOrRef, ?string $recordKey = null): array
{
    if (!ap_bsky_tab_enabled() || ap_bsky_session_row($ownerUserId) === null) {
        return ['ok' => false, 'error' => 'Connect Bluesky in Profile settings first'];
    }
    $did = ap_bsky_resolve_target_did($didOrRef, $ownerUserId);
    if ($did === null || !str_starts_with($did, 'did:')) {
        return ['ok' => false, 'error' => 'Could not resolve Bluesky DID'];
    }
    $existing = ap_bsky_graph_sync_get($ownerUserId, 'follow', $did);
    if (is_array($existing) && !empty($existing['bsky_uri'])) {
        ap_bsky_actor_viewer_cache_update($ownerUserId, $did, (string) $existing['bsky_uri']);
        return ['ok' => true, 'already' => true, 'uri' => (string) $existing['bsky_uri']];
    }
    // Also check live profile viewer state
    $prof = ap_bsky_get_profile($ownerUserId, $did);
    if (!empty($prof['ok']) && is_array($prof['profile']['viewer'] ?? null)) {
        $followUri = (string) ($prof['profile']['viewer']['following'] ?? '');
        if (str_starts_with($followUri, 'at://')) {
            ap_bsky_graph_sync_upsert($ownerUserId, 'follow', $did, $followUri, 'pull');
            ap_bsky_actor_viewer_cache_update($ownerUserId, $did, $followUri);
            return ['ok' => true, 'already' => true, 'uri' => $followUri];
        }
    }
    $row = ap_bsky_session_row($ownerUserId);
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No session')];
    }
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $repoDid = (string) ($row['did'] ?? '');
    if ($repoDid !== '' && $did === $repoDid) {
        return ['ok' => false, 'error' => 'Cannot follow yourself on Bluesky'];
    }
    $body = [
        'repo' => $repoDid,
        'collection' => 'app.bsky.graph.follow',
        'record' => [
            '$type' => 'app.bsky.graph.follow',
            'subject' => $did,
            'createdAt' => gmdate('c'),
        ],
    ];
    if ($recordKey !== null && preg_match('/^[A-Za-z0-9._~:-]{1,240}$/', $recordKey)) {
        $body['rkey'] = $recordKey;
    }
    $endpoint = $recordKey !== null && preg_match('/^[A-Za-z0-9._~:-]{1,240}$/', $recordKey)
        ? 'com.atproto.repo.putRecord' : 'com.atproto.repo.createRecord';
    $put = ap_bsky_xrpc($pds, $endpoint, 'POST', null, $body, (string) $tok['access'], 12);
    if (empty($put['ok']) && (($put['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $put = ap_bsky_xrpc($pds, $endpoint, 'POST', null, $body, (string) $tok['access'], 12);
        }
    }
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => (string) ($put['error'] ?? 'Bluesky follow failed')];
    }
    $uri = (string) ($put['json']['uri'] ?? '');
    ap_bsky_graph_sync_upsert($ownerUserId, 'follow', $did, $uri !== '' ? $uri : null, 'vaak');
    ap_bsky_actor_viewer_cache_update($ownerUserId, $did, $uri !== '' ? $uri : null);
    ap_bsky_tl_cache_clear_owner($ownerUserId);
    return ['ok' => true, 'uri' => $uri, 'already' => false];
}

/**
 * Unfollow a Bluesky DID (delete app.bsky.graph.follow record).
 *
 * @return array{ok:bool,error?:string,skipped?:bool,already?:bool}
 */
function ap_bsky_unfollow_actor(int $ownerUserId, string $didOrRef): array
{
    if (!ap_bsky_tab_enabled() || ap_bsky_session_row($ownerUserId) === null) {
        return ['ok' => false, 'error' => 'Connect Bluesky in Profile settings first'];
    }
    $did = ap_bsky_resolve_target_did($didOrRef, $ownerUserId);
    if ($did === null || !str_starts_with($did, 'did:')) {
        return ['ok' => false, 'error' => 'Could not resolve Bluesky DID'];
    }
    $row = ap_bsky_session_row($ownerUserId);
    $followUri = '';
    $existing = ap_bsky_graph_sync_get($ownerUserId, 'follow', $did);
    if (is_array($existing)) {
        $followUri = trim((string) ($existing['bsky_uri'] ?? ''));
    }
    if ($followUri === '') {
        $prof = ap_bsky_get_profile($ownerUserId, $did);
        if (!empty($prof['ok']) && is_array($prof['profile']['viewer'] ?? null)) {
            $followUri = trim((string) ($prof['profile']['viewer']['following'] ?? ''));
        }
    }
    if ($followUri === '' || !preg_match('~^at://[^/]+/app\.bsky\.graph\.follow/([^/]+)$~', $followUri, $m)) {
        ap_bsky_graph_sync_delete($ownerUserId, 'follow', $did);
        ap_bsky_actor_viewer_cache_update($ownerUserId, $did, null);
        return ['ok' => true, 'already' => true, 'skipped' => true];
    }
    $rkey = $m[1];
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No session')];
    }
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $body = [
        'repo' => (string) ($row['did'] ?? ''),
        'collection' => 'app.bsky.graph.follow',
        'rkey' => $rkey,
    ];
    $del = ap_bsky_xrpc($pds, 'com.atproto.repo.deleteRecord', 'POST', null, $body, (string) $tok['access'], 12);
    if (empty($del['ok']) && (($del['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $del = ap_bsky_xrpc($pds, 'com.atproto.repo.deleteRecord', 'POST', null, $body, (string) $tok['access'], 12);
        }
    }
    ap_bsky_graph_sync_delete($ownerUserId, 'follow', $did);
    ap_bsky_actor_viewer_cache_update($ownerUserId, $did, null);
    ap_bsky_tl_cache_clear_owner($ownerUserId);
    if (empty($del['ok'])) {
        return ['ok' => false, 'error' => (string) ($del['error'] ?? 'Bluesky unfollow failed')];
    }
    return ['ok' => true];
}

/**
 * @return array{ok:bool,error?:string,skipped?:bool}
 */
function ap_bsky_mute_actor(int $ownerUserId, string $did): array
{
    if (!ap_bsky_tab_enabled() || ap_bsky_session_row($ownerUserId) === null) {
        return ['ok' => true, 'skipped' => true];
    }
    if (!str_starts_with($did, 'did:')) {
        return ['ok' => false, 'error' => 'DID required'];
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No session')];
    }
    $row = ap_bsky_session_row($ownerUserId);
    $hosts = ap_bsky_feed_hosts(rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/'));
    $lastErr = 'muteActor failed';
    foreach ($hosts as $host) {
        $r = ap_bsky_xrpc($host, 'app.bsky.graph.muteActor', 'POST', null, ['actor' => $did], (string) $tok['access'], 10);
        if (($r['status'] ?? 0) === 401) {
            $tok = ap_bsky_access_token($ownerUserId, true);
            if (empty($tok['ok'])) {
                return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Session expired')];
            }
            $r = ap_bsky_xrpc($host, 'app.bsky.graph.muteActor', 'POST', null, ['actor' => $did], (string) $tok['access'], 10);
        }
        if (!empty($r['ok'])) {
            ap_bsky_graph_sync_upsert($ownerUserId, 'mute', $did, null, 'vaak');
            ap_bsky_hide_did_add($ownerUserId, $did, 'mute');
            ap_bsky_tl_cache_clear_owner($ownerUserId);
            return ['ok' => true];
        }
        $lastErr = (string) ($r['error'] ?? $lastErr);
    }
    return ['ok' => false, 'error' => $lastErr];
}

/**
 * @return array{ok:bool,error?:string,skipped?:bool}
 */
function ap_bsky_unmute_actor(int $ownerUserId, string $did): array
{
    if (!ap_bsky_tab_enabled() || ap_bsky_session_row($ownerUserId) === null) {
        return ['ok' => true, 'skipped' => true];
    }
    if (!str_starts_with($did, 'did:')) {
        return ['ok' => false, 'error' => 'DID required'];
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No session')];
    }
    $row = ap_bsky_session_row($ownerUserId);
    $hosts = ap_bsky_feed_hosts(rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/'));
    $lastErr = 'unmuteActor failed';
    foreach ($hosts as $host) {
        $r = ap_bsky_xrpc($host, 'app.bsky.graph.unmuteActor', 'POST', null, ['actor' => $did], (string) $tok['access'], 10);
        if (($r['status'] ?? 0) === 401) {
            $tok = ap_bsky_access_token($ownerUserId, true);
            if (empty($tok['ok'])) {
                return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Session expired')];
            }
            $r = ap_bsky_xrpc($host, 'app.bsky.graph.unmuteActor', 'POST', null, ['actor' => $did], (string) $tok['access'], 10);
        }
        if (!empty($r['ok'])) {
            ap_bsky_graph_sync_delete($ownerUserId, 'mute', $did);
            ap_bsky_hide_did_remove($ownerUserId, $did, 'mute');
            ap_bsky_tl_cache_clear_owner($ownerUserId);
            return ['ok' => true];
        }
        $lastErr = (string) ($r['error'] ?? $lastErr);
    }
    return ['ok' => false, 'error' => $lastErr];
}

/**
 * @return array{ok:bool,error?:string,skipped?:bool,uri?:string}
 */
function ap_bsky_block_actor(int $ownerUserId, string $did): array
{
    if (!ap_bsky_tab_enabled() || ap_bsky_session_row($ownerUserId) === null) {
        return ['ok' => true, 'skipped' => true];
    }
    if (!str_starts_with($did, 'did:')) {
        return ['ok' => false, 'error' => 'DID required'];
    }
    $existing = ap_bsky_graph_sync_get($ownerUserId, 'block', $did);
    if (is_array($existing) && !empty($existing['bsky_uri'])) {
        ap_bsky_hide_did_add($ownerUserId, $did, 'block');
        return ['ok' => true, 'uri' => (string) $existing['bsky_uri'], 'skipped' => true];
    }
    $row = ap_bsky_session_row($ownerUserId);
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No session')];
    }
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $repoDid = (string) ($row['did'] ?? '');
    $body = [
        'repo' => $repoDid,
        'collection' => 'app.bsky.graph.block',
        'record' => [
            '$type' => 'app.bsky.graph.block',
            'subject' => $did,
            'createdAt' => gmdate('c'),
        ],
    ];
    $put = ap_bsky_xrpc($pds, 'com.atproto.repo.createRecord', 'POST', null, $body, (string) $tok['access'], 12);
    if (empty($put['ok']) && (($put['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $put = ap_bsky_xrpc($pds, 'com.atproto.repo.createRecord', 'POST', null, $body, (string) $tok['access'], 12);
        }
    }
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => (string) ($put['error'] ?? 'block failed')];
    }
    $uri = (string) ($put['json']['uri'] ?? '');
    ap_bsky_graph_sync_upsert($ownerUserId, 'block', $did, $uri !== '' ? $uri : null, 'vaak');
    ap_bsky_hide_did_add($ownerUserId, $did, 'block');
    ap_bsky_tl_cache_clear_owner($ownerUserId);
    return ['ok' => true, 'uri' => $uri];
}

/**
 * @return array{ok:bool,error?:string,skipped?:bool}
 */
function ap_bsky_unblock_actor(int $ownerUserId, string $did): array
{
    if (!ap_bsky_tab_enabled() || ap_bsky_session_row($ownerUserId) === null) {
        return ['ok' => true, 'skipped' => true];
    }
    if (!str_starts_with($did, 'did:')) {
        return ['ok' => false, 'error' => 'DID required'];
    }
    $row = ap_bsky_session_row($ownerUserId);
    $existing = ap_bsky_graph_sync_get($ownerUserId, 'block', $did);
    $blockUri = is_array($existing) ? trim((string) ($existing['bsky_uri'] ?? '')) : '';
    if ($blockUri === '' || !preg_match('~^at://[^/]+/app\.bsky\.graph\.block/([^/]+)$~', $blockUri, $m)) {
        // No stored rkey — still clear local hide + sync row.
        ap_bsky_graph_sync_delete($ownerUserId, 'block', $did);
        ap_bsky_hide_did_remove($ownerUserId, $did, 'block');
        return ['ok' => true, 'skipped' => true];
    }
    $rkey = $m[1];
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No session')];
    }
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $body = [
        'repo' => (string) ($row['did'] ?? ''),
        'collection' => 'app.bsky.graph.block',
        'rkey' => $rkey,
    ];
    $del = ap_bsky_xrpc($pds, 'com.atproto.repo.deleteRecord', 'POST', null, $body, (string) $tok['access'], 12);
    if (empty($del['ok']) && (($del['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $del = ap_bsky_xrpc($pds, 'com.atproto.repo.deleteRecord', 'POST', null, $body, (string) $tok['access'], 12);
        }
    }
    ap_bsky_graph_sync_delete($ownerUserId, 'block', $did);
    ap_bsky_hide_did_remove($ownerUserId, $did, 'block');
    ap_bsky_tl_cache_clear_owner($ownerUserId);
    if (empty($del['ok'])) {
        return ['ok' => false, 'error' => (string) ($del['error'] ?? 'unblock failed')];
    }
    return ['ok' => true];
}

/** Best-effort push after VAAK mute/block mutations. */
function ap_bsky_sync_moderation_from_vaak(int $ownerUserId, string $actorUrl, string $op): void
{
    if ($ownerUserId < 1 || !ap_bsky_tab_enabled()) {
        return;
    }
    if (ap_bsky_session_row($ownerUserId) === null) {
        return;
    }
    try {
        $did = ap_bsky_resolve_target_did($actorUrl, $ownerUserId);
        if ($did === null) {
            return;
        }
        $res = match ($op) {
            'mute' => ap_bsky_mute_actor($ownerUserId, $did),
            'unmute' => ap_bsky_unmute_actor($ownerUserId, $did),
            'block' => ap_bsky_block_actor($ownerUserId, $did),
            'unblock' => ap_bsky_unblock_actor($ownerUserId, $did),
            default => ['ok' => false, 'error' => 'bad op'],
        };
        if (empty($res['ok']) && empty($res['skipped'])) {
            error_log('[ap-bsky] sync_moderation ' . $op . ' ' . $did . ': ' . (string) ($res['error'] ?? 'fail'));
        }
    } catch (Throwable $e) {
        error_log('[ap-bsky] sync_moderation: ' . $e->getMessage());
    }
}

function ap_bsky_hide_did_add(int $ownerUserId, string $did, string $reason, string $listUri = ''): void
{
    if ($ownerUserId < 1 || !str_starts_with($did, 'did:')) {
        return;
    }
    ap_bsky_graph_sync_migrate();
    try {
        ap_db()->prepare(
            'INSERT INTO bsky_hide_dids (owner_user_id, did, reason, list_uri, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (owner_user_id, did, reason, list_uri) DO UPDATE SET updated_at = excluded.updated_at'
        )->execute([$ownerUserId, $did, $reason, $listUri, gmdate('c')]);
    } catch (Throwable $e) {
        // ignore
    }
}

function ap_bsky_hide_did_remove(int $ownerUserId, string $did, string $reason, string $listUri = ''): void
{
    try {
        ap_db()->prepare(
            'DELETE FROM bsky_hide_dids
             WHERE owner_user_id = ? AND did = ? AND reason = ? AND list_uri = ?'
        )->execute([$ownerUserId, $did, $reason, $listUri]);
    } catch (Throwable $e) {
        // ignore
    }
}

/** @return array<string,true> did => true */
function ap_bsky_hide_did_set(int $ownerUserId): array
{
    static $cache = [];
    if (isset($cache[$ownerUserId])) {
        return $cache[$ownerUserId];
    }
    $out = [];
    if ($ownerUserId < 1) {
        return $out;
    }
    ap_bsky_graph_sync_migrate();
    try {
        $st = ap_db()->prepare('SELECT DISTINCT did FROM bsky_hide_dids WHERE owner_user_id = ?');
        $st->execute([$ownerUserId]);
        while ($row = $st->fetch()) {
            $d = (string) ($row['did'] ?? '');
            if ($d !== '') {
                $out[$d] = true;
            }
        }
    } catch (Throwable $e) {
        // empty
    }
    $cache[$ownerUserId] = $out;
    return $out;
}

function ap_bsky_hide_did_set_clear_cache(?int $ownerUserId = null): void
{
    // static cache is per-request; no-op helper for future APCu
}

/**
 * Pull Bluesky blocks, mutes, and subscribed block/mute modlist members into hide set.
 *
 * @return array{ok:bool,error?:string,blocks?:int,mutes?:int,list_members?:int,list_block_members?:int,list_mute_members?:int,cached?:bool}
 */
function ap_bsky_refresh_hide_set(int $ownerUserId, bool $force = false): array
{
    if ($ownerUserId < 1 || !ap_bsky_tab_enabled()) {
        return ['ok' => false, 'error' => 'disabled'];
    }
    if (ap_bsky_session_row($ownerUserId) === null) {
        return ['ok' => false, 'error' => 'not connected'];
    }
    $cachePath = sys_get_temp_dir() . '/vaak-bsky-hide-' . $ownerUserId . '.json';
    if (!$force && is_file($cachePath) && (time() - (int) @filemtime($cachePath)) < 900) {
        return ['ok' => true, 'cached' => true];
    }
    ap_bsky_graph_sync_migrate();
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No session')];
    }
    $row = ap_bsky_session_row($ownerUserId);
    $hosts = ap_bsky_feed_hosts(rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/'));
    $access = (string) $tok['access'];

    $fetchPage = static function (string $nsid, array $query) use ($hosts, &$access, $ownerUserId): ?array {
        foreach ($hosts as $host) {
            $r = ap_bsky_xrpc($host, $nsid, 'GET', $query, null, $access, 15);
            if (($r['status'] ?? 0) === 401) {
                $tok = ap_bsky_access_token($ownerUserId, true);
                if (empty($tok['ok'])) {
                    return null;
                }
                $access = (string) $tok['access'];
                $r = ap_bsky_xrpc($host, $nsid, 'GET', $query, null, $access, 15);
            }
            if (!empty($r['ok']) && is_array($r['json'] ?? null)) {
                return $r['json'];
            }
        }
        return null;
    };

    $now = gmdate('c');
    $blockCount = 0;
    $muteCount = 0;
    $listBlockCount = 0;
    $listMuteCount = 0;

    // Replace pull-sourced rows for this owner (keep vaak-pushed hide rows that might not be in pull yet).
    try {
        ap_db()->prepare(
            "DELETE FROM bsky_hide_dids WHERE owner_user_id = ? AND reason IN ('block','mute','listblock','listmute')"
        )->execute([$ownerUserId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    // Blocks
    $cursor = null;
    for ($page = 0; $page < 20; $page++) {
        $q = ['limit' => 100];
        if ($cursor) {
            $q['cursor'] = $cursor;
        }
        $j = $fetchPage('app.bsky.graph.getBlocks', $q);
        if ($j === null) {
            break;
        }
        foreach ((array) ($j['blocks'] ?? []) as $prof) {
            $did = (string) ($prof['did'] ?? '');
            if ($did === '') {
                continue;
            }
            ap_bsky_hide_did_add($ownerUserId, $did, 'block');
            $blockCount++;
        }
        $cursor = isset($j['cursor']) && is_string($j['cursor']) ? $j['cursor'] : null;
        if ($cursor === null) {
            break;
        }
    }

    // Mutes
    $cursor = null;
    for ($page = 0; $page < 20; $page++) {
        $q = ['limit' => 100];
        if ($cursor) {
            $q['cursor'] = $cursor;
        }
        $j = $fetchPage('app.bsky.graph.getMutes', $q);
        if ($j === null) {
            break;
        }
        foreach ((array) ($j['mutes'] ?? []) as $prof) {
            $did = (string) ($prof['did'] ?? '');
            if ($did === '') {
                continue;
            }
            ap_bsky_hide_did_add($ownerUserId, $did, 'mute');
            $muteCount++;
        }
        $cursor = isset($j['cursor']) && is_string($j['cursor']) ? $j['cursor'] : null;
        if ($cursor === null) {
            break;
        }
    }

    /**
     * Expand subscribed mod lists (block or mute) into hide DIDs.
     *
     * @param string $listsNsid getListBlocks | getListMutes
     * @param string $reason listblock | listmute
     */
    $expandSubscribedLists = static function (string $listsNsid, string $reason) use (
        $fetchPage,
        $ownerUserId,
        &$listBlockCount,
        &$listMuteCount
    ): void {
        $listUris = [];
        $cursor = null;
        for ($page = 0; $page < 10; $page++) {
            $q = ['limit' => 50];
            if ($cursor) {
                $q['cursor'] = $cursor;
            }
            $j = $fetchPage($listsNsid, $q);
            if ($j === null) {
                break;
            }
            foreach ((array) ($j['lists'] ?? []) as $list) {
                $uri = (string) ($list['uri'] ?? '');
                if ($uri !== '') {
                    $listUris[] = $uri;
                }
            }
            $cursor = isset($j['cursor']) && is_string($j['cursor']) ? $j['cursor'] : null;
            if ($cursor === null) {
                break;
            }
        }
        foreach (array_slice(array_unique($listUris), 0, 30) as $listUri) {
            $lCursor = null;
            for ($page = 0; $page < 30; $page++) {
                $q = ['list' => $listUri, 'limit' => 100];
                if ($lCursor) {
                    $q['cursor'] = $lCursor;
                }
                $j = $fetchPage('app.bsky.graph.getList', $q);
                if ($j === null) {
                    break;
                }
                foreach ((array) ($j['items'] ?? []) as $item) {
                    $subj = is_array($item['subject'] ?? null) ? $item['subject'] : [];
                    $did = (string) ($subj['did'] ?? '');
                    if ($did === '') {
                        continue;
                    }
                    ap_bsky_hide_did_add($ownerUserId, $did, $reason, $listUri);
                    if ($reason === 'listmute') {
                        $listMuteCount++;
                    } else {
                        $listBlockCount++;
                    }
                }
                $lCursor = isset($j['cursor']) && is_string($j['cursor']) ? $j['cursor'] : null;
                if ($lCursor === null) {
                    break;
                }
            }
        }
    };

    // Subscribed blocklists + mutelists
    $expandSubscribedLists('app.bsky.graph.getListBlocks', 'listblock');
    $expandSubscribedLists('app.bsky.graph.getListMutes', 'listmute');

    $listCount = $listBlockCount + $listMuteCount;
    @file_put_contents($cachePath, json_encode([
        'at' => $now,
        'blocks' => $blockCount,
        'mutes' => $muteCount,
        'list_members' => $listCount,
        'list_block_members' => $listBlockCount,
        'list_mute_members' => $listMuteCount,
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);

    // Hide-set memo can be stale within this long request — clear request cache.
    ap_bsky_hide_did_set_clear_cache($ownerUserId);

    return [
        'ok' => true,
        'blocks' => $blockCount,
        'mutes' => $muteCount,
        'list_members' => $listCount,
        'list_block_members' => $listBlockCount,
        'list_mute_members' => $listMuteCount,
    ];
}

/**
 * Drop feed items authored (or reposted) by hidden DIDs.
 *
 * @param list<array> $feed
 * @return list<array>
 */
function ap_bsky_filter_hidden_authors(int $ownerUserId, array $feed): array
{
    $hide = ap_bsky_hide_did_set($ownerUserId);
    if ($hide === []) {
        return $feed;
    }
    $out = [];
    foreach ($feed as $item) {
        if (!is_array($item) || !is_array($item['post'] ?? null)) {
            continue;
        }
        $authorDid = (string) ($item['post']['author']['did'] ?? '');
        if ($authorDid !== '' && isset($hide[$authorDid])) {
            continue;
        }
        $reason = is_array($item['reason'] ?? null) ? $item['reason'] : null;
        if (is_array($reason)) {
            $byDid = (string) ($reason['by']['did'] ?? '');
            if ($byDid !== '' && isset($hide[$byDid])) {
                continue;
            }
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * External link embed from a PostView (shared links / link cards).
 *
 * @return array{uri:string,title:string,description:string,thumb:string}|null
 */
function ap_bsky_post_external(array $post): ?array
{
    $embed = is_array($post['embed'] ?? null) ? $post['embed'] : null;
    if ($embed === null) {
        return null;
    }
    $type = (string) ($embed['$type'] ?? '');
    $ext = null;
    if ((str_contains($type, 'external') || isset($embed['external'])) && is_array($embed['external'] ?? null)) {
        $ext = $embed['external'];
    } elseif (str_contains($type, 'recordWithMedia') && is_array($embed['media']['external'] ?? null)) {
        $ext = $embed['media']['external'];
    }
    if (!is_array($ext)) {
        return null;
    }
    $uri = trim((string) ($ext['uri'] ?? ''));
    if ($uri === '' || !preg_match('#^https?://#i', $uri)) {
        return null;
    }
    $thumb = (string) ($ext['thumb'] ?? '');
    if ($thumb !== '' && !str_starts_with($thumb, 'https://') && !str_starts_with($thumb, 'http://')) {
        $thumb = '';
    }
    return [
        'uri' => $uri,
        'title' => trim((string) ($ext['title'] ?? '')),
        'description' => trim((string) ($ext['description'] ?? '')),
        'thumb' => $thumb,
    ];
}

/**
 * HTML link-card for a Bluesky external embed (no network fetch).
 */
function ap_bsky_external_link_card_html(?array $ext): string
{
    if ($ext === null) {
        return '';
    }
    $uri = (string) ($ext['uri'] ?? '');
    if ($uri === '' || !preg_match('#^https?://#i', $uri)) {
        return '';
    }
    $title = trim((string) ($ext['title'] ?? ''));
    $desc = trim((string) ($ext['description'] ?? ''));
    $thumb = (string) ($ext['thumb'] ?? '');
    $host = parse_url($uri, PHP_URL_HOST);
    $host = is_string($host) ? strtolower($host) : '';
    if ($title === '') {
        $title = $host !== '' ? $host : $uri;
    }
    if (function_exists('ap_link_preview_html')) {
        return ap_link_preview_html([
            'status' => 'ok',
            'url' => $uri,
            'title' => $title,
            'description' => mb_substr($desc, 0, 280),
            'provider_name' => $host,
            'image' => $thumb !== '' ? $thumb : null,
            'type' => 'link',
        ], true);
    }
    $href = htmlspecialchars($uri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $titleH = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $descH = $desc !== '' ? '<div class="link-card__desc">' . htmlspecialchars(mb_substr($desc, 0, 280), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>' : '';
    $provH = $host !== '' ? '<div class="link-card__provider">' . htmlspecialchars($host, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>' : '';
    $imgH = ($thumb !== '' && str_starts_with($thumb, 'http'))
        ? '<div class="link-card__media"><img src="' . htmlspecialchars($thumb, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="" loading="lazy" referrerpolicy="no-referrer"></div>'
        : '';
    return '<a class="link-card" href="' . $href . '" target="_blank" rel="nofollow noopener noreferrer">'
        . $imgH
        . '<div class="link-card__body">' . $provH . '<div class="link-card__title">' . $titleH . '</div>' . $descH . '</div>'
        . '</a>';
}

/**
 * @return list<string>
 */
function ap_bsky_post_image_urls(array $post): array
{
    $out = [];
    $embed = is_array($post['embed'] ?? null) ? $post['embed'] : null;
    if ($embed === null) {
        return $out;
    }
    $type = (string) ($embed['$type'] ?? '');
    $images = [];
    if (str_contains($type, 'images') && is_array($embed['images'] ?? null)) {
        $images = $embed['images'];
    } elseif (str_contains($type, 'recordWithMedia') && is_array($embed['media']['images'] ?? null)) {
        $images = $embed['media']['images'];
    }
    foreach ($images as $img) {
        if (!is_array($img)) {
            continue;
        }
        $u = (string) ($img['thumb'] ?? $img['fullsize'] ?? '');
        if ($u === '' && is_array($img['image'] ?? null)) {
            // raw blob — skip without CDN resolve in Phase A
            continue;
        }
        if (str_starts_with($u, 'https://')) {
            $out[] = $u;
        }
    }
    return $out;
}

/**
 * @return list<array{url:string,thumbnail:string,mediaType:string,is_video:bool}>
 */
function ap_bsky_post_video_media(array $post): array
{
    $embed = is_array($post['embed'] ?? null) ? $post['embed'] : null;
    $video = ap_bsky_embed_video_view($embed);
    if (!is_array($video)) {
        return [];
    }
    $url = (string) ($video['playlist'] ?? $video['url'] ?? '');
    if (!str_starts_with($url, 'https://')) {
        return [];
    }
    $thumbnail = (string) ($video['thumbnail'] ?? $video['thumb'] ?? '');
    return [[
        'url' => $url,
        'thumbnail' => str_starts_with($thumbnail, 'https://') ? $thumbnail : '',
        'mediaType' => 'application/x-mpegURL',
        'is_video' => true,
    ]];
}

function ap_bsky_post_url(array $post): string
{
    $author = is_array($post['author'] ?? null) ? $post['author'] : [];
    $handle = (string) ($author['handle'] ?? '');
    $uri = (string) ($post['uri'] ?? '');
    // at://did:plc:…/app.bsky.feed.post/RKEY
    $rkey = '';
    if (preg_match('#/app\.bsky\.feed\.post/([^/\s]+)$#', $uri, $m)) {
        $rkey = $m[1];
    }
    if ($handle !== '' && $rkey !== '') {
        return 'https://bsky.app/profile/' . rawurlencode($handle) . '/post/' . rawurlencode($rkey);
    }
    if ($handle !== '') {
        return 'https://bsky.app/profile/' . rawurlencode($handle);
    }
    return 'https://bsky.app/';
}

/** Bluesky profile description max graphemes (lexicon). */
const AP_BSKY_BIO_MAX = 256;

/**
 * Build Bluesky description from VAAK HTML bio: plain text, truncate, append
 * link to full HTML profile when truncated (or always append a short footer).
 */
function ap_bsky_bio_from_vaak_summary(string $summaryHtml, string $profileUrl): array
{
    $plain = trim(html_entity_decode(strip_tags($summaryHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $plain = preg_replace("/[ \t]+/u", ' ', $plain) ?? $plain;
    $plain = preg_replace("/\n{3,}/u", "\n\n", $plain) ?? $plain;
    $plain = trim($plain);
    $footer = ($profileUrl !== '') ? ("\n\nFull profile: " . $profileUrl) : '';
    if ($plain === '') {
        $desc = $footer !== '' ? trim($footer) : '';
        return ['description' => mb_substr($desc, 0, AP_BSKY_BIO_MAX), 'truncated' => false];
    }
    // Fits without a footer — use as-is
    if (mb_strlen($plain) <= AP_BSKY_BIO_MAX) {
        return ['description' => $plain, 'truncated' => false];
    }
    // Too long: truncate and append HTML profile link
    $maxBody = AP_BSKY_BIO_MAX - mb_strlen($footer);
    if ($maxBody < 32) {
        return [
            'description' => mb_substr('Full profile: ' . $profileUrl, 0, AP_BSKY_BIO_MAX),
            'truncated' => true,
        ];
    }
    $body = mb_substr($plain, 0, max(1, $maxBody - 1));
    $body = rtrim($body, " \t.,;:!-") . '…';
    $desc = $body . $footer;
    if (mb_strlen($desc) > AP_BSKY_BIO_MAX) {
        $desc = mb_substr($desc, 0, AP_BSKY_BIO_MAX);
    }
    return ['description' => $desc, 'truncated' => true];
}

/**
 * Download a remote image (https) for blob upload. Caps size at 2MB.
 *
 * @return array{ok:bool,error?:string,bytes?:string,mime?:string}
 */
function ap_bsky_fetch_image_bytes(string $url): array
{
    $url = trim($url);
    if ($url === '' || !str_starts_with($url, 'https://')) {
        return ['ok' => false, 'error' => 'Invalid image URL'];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }
    if (ap_bsky_budget_exceeded()) {
        return ['ok' => false, 'error' => 'Bluesky budget exceeded'];
    }
    $dlTimeout = ap_bsky_effective_timeout(12);
    if ($dlTimeout < 1) {
        return ['ok' => false, 'error' => 'Bluesky budget exceeded'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => min(AP_BSKY_CONNECT_TIMEOUT, $dlTimeout),
        CURLOPT_TIMEOUT => $dlTimeout,
        CURLOPT_USERAGENT => 'VAAK-Bluesky/1.0 (+https://mkultra.monster/vaak)',
    ]);
    $bytes = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if (!is_string($bytes) || $bytes === '' || $status < 200 || $status >= 300) {
        return ['ok' => false, 'error' => 'Image download failed'];
    }
    if (strlen($bytes) > 2 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Image too large for Bluesky avatar/banner (>2MB)'];
    }
    $mime = 'image/jpeg';
    if (preg_match('#^(image/(?:jpeg|png|webp|gif))#i', $ctype, $m)) {
        $mime = strtolower($m[1]);
    } elseif (str_starts_with($bytes, "\x89PNG")) {
        $mime = 'image/png';
    } elseif (str_starts_with($bytes, "\xFF\xD8\xFF")) {
        $mime = 'image/jpeg';
    } elseif (str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP')) {
        $mime = 'image/webp';
    } elseif (str_starts_with($bytes, 'GIF8')) {
        $mime = 'image/gif';
    }
    // Bluesky app.bsky.actor.profile only accepts image/jpeg or image/png for
    // avatar/banner blobs — convert webp/gif (and anything else) to JPEG.
    return ap_bsky_normalize_profile_image($bytes, $mime);
}

/**
 * Convert an image to JPEG/PNG for Bluesky profile blobs.
 *
 * @return array{ok:bool,error?:string,bytes?:string,mime?:string}
 */
function ap_bsky_normalize_profile_image(string $bytes, string $mime): array
{
    $mime = strtolower(trim($mime));
    if ($mime === 'image/jpg') {
        $mime = 'image/jpeg';
    }
    if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return ['ok' => true, 'bytes' => $bytes, 'mime' => $mime];
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return ['ok' => false, 'error' => 'Cannot convert ' . $mime . ' to JPEG (GD missing)'];
    }
    $im = @imagecreatefromstring($bytes);
    if ($im === false) {
        return ['ok' => false, 'error' => 'Could not decode ' . $mime . ' for Bluesky upload'];
    }
    if (function_exists('imagepalettetotruecolor')) {
        @imagepalettetotruecolor($im);
    }
    // Flatten alpha onto black so JPEG encode is safe.
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w > 0 && $h > 0 && function_exists('imagecreatetruecolor')) {
        $flat = imagecreatetruecolor($w, $h);
        if ($flat !== false) {
            $bg = imagecolorallocate($flat, 0, 0, 0);
            imagefilledrectangle($flat, 0, 0, $w, $h, $bg);
            imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
            imagedestroy($im);
            $im = $flat;
        }
    }
    $out = null;
    foreach ([90, 82, 72] as $quality) {
        ob_start();
        $ok = imagejpeg($im, null, $quality);
        $encoded = ob_get_clean();
        if (!$ok || !is_string($encoded) || $encoded === '') {
            continue;
        }
        if (strlen($encoded) <= 2 * 1024 * 1024) {
            $out = $encoded;
            break;
        }
        $out = $encoded;
    }
    imagedestroy($im);
    if (!is_string($out) || $out === '') {
        return ['ok' => false, 'error' => 'JPEG encode failed for Bluesky upload'];
    }
    if (strlen($out) > 2 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Converted JPEG still too large for Bluesky (>2MB)'];
    }
    return ['ok' => true, 'bytes' => $out, 'mime' => 'image/jpeg'];
}

/**
 * @return array{ok:bool,error?:string,blob?:array}
 */
function ap_bsky_upload_blob(string $pdsHost, string $accessJwt, string $bytes, string $mime): array
{
    if (ap_bsky_budget_exceeded()) {
        return ['ok' => false, 'error' => 'Bluesky budget exceeded'];
    }
    $timeoutSec = ap_bsky_effective_timeout(20);
    if ($timeoutSec < 1) {
        return ['ok' => false, 'error' => 'Bluesky budget exceeded'];
    }
    $pdsHost = rtrim($pdsHost, '/');
    $url = $pdsHost . '/xrpc/com.atproto.repo.uploadBlob';
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }
    $respHeaders = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => min(AP_BSKY_CONNECT_TIMEOUT, $timeoutSec),
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessJwt,
            'Content-Type: ' . $mime,
            'Accept: application/json',
            'User-Agent: VAAK-Bluesky/1.0 (+https://mkultra.monster/vaak)',
        ],
        CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
            $respHeaders .= $line;
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $rate = ap_bsky_parse_rate_limit_headers($respHeaders);
    $json = is_string($body) ? json_decode($body, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($json) || !isset($json['blob'])) {
        $msg = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : '';
        $errCode = is_array($json) ? (string) ($json['error'] ?? '') : '';
        $out = ['ok' => false, 'error' => $msg !== '' ? $msg : ('uploadBlob HTTP ' . $status), 'status' => $status] + $rate;
        if ($status === 429 || strcasecmp($errCode, 'RateLimitExceeded') === 0
            || stripos($msg, 'rate limit') !== false) {
            $out['rate_limited'] = true;
            $out['error'] = 'Rate limit exceeded';
            if (empty($out['retry_after_sec'])) {
                $out['retry_after_sec'] = ap_bsky_rate_limit_fallback_delay_sec($rate);
            }
        }
        return $out;
    }
    return ['ok' => true, 'blob' => $json['blob']] + $rate;
}

/**
 * Download a video for Bluesky crosspost (up to 100MB).
 *
 * @return array{ok:bool,error?:string,bytes?:string,mime?:string}
 */
function ap_bsky_fetch_video_bytes(string $url, string $hintMime = ''): array
{
    $url = trim($url);
    if ($url === '' || !str_starts_with($url, 'https://')) {
        return ['ok' => false, 'error' => 'Invalid video URL'];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'VAAK-Bluesky/1.0 (+https://mkultra.monster/vaak)',
        CURLOPT_ENCODING => 'identity',
    ]);
    $bytes = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if (!is_string($bytes) || $bytes === '' || $status < 200 || $status >= 300) {
        return ['ok' => false, 'error' => 'Video download failed (HTTP ' . $status . ')'];
    }
    // Soft cap: Bluesky allows up to ~100–300MB; keep PHP memory sane.
    if (strlen($bytes) > 100 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Video too large for Bluesky crosspost (>100MB)'];
    }
    $mime = strtolower(trim($hintMime));
    if ($mime === '' || $mime === 'application/octet-stream') {
        $mime = '';
        if (preg_match('#^(video/[a-z0-9.+-]+)#i', $ctype, $m)) {
            $mime = strtolower($m[1]);
        }
    }
    if ($mime === '') {
        // Sniff common containers when Content-Type is missing/wrong.
        if (strlen($bytes) >= 12 && str_contains(substr($bytes, 4, 8), 'ftyp')) {
            $brand = substr($bytes, 8, 4);
            $mime = ($brand === 'qt  ') ? 'video/quicktime' : 'video/mp4';
        } elseif (str_starts_with($bytes, "\x1A\x45\xDF\xA3")) {
            $mime = 'video/webm';
        } else {
            $mime = 'video/mp4';
        }
    }
    return ['ok' => true, 'bytes' => $bytes, 'mime' => $mime];
}

/**
 * Remux/transcode to MP4 when Bluesky's video service needs video/mp4.
 * Prefer stream-copy for H.264/AAC .mov (common from iPhone).
 *
 * @return array{ok:bool,error?:string,bytes?:string,mime?:string}
 */
function ap_bsky_video_ensure_mp4(string $bytes, string $mime, string $nameHint = 'video.mov'): array
{
    $mime = strtolower(trim($mime));
    if ($mime === 'video/mp4' || $mime === 'video/m4v') {
        return ['ok' => true, 'bytes' => $bytes, 'mime' => 'video/mp4'];
    }
    $ffmpeg = trim((string) shell_exec('command -v ffmpeg'));
    if ($ffmpeg === '') {
        // Hope the video service accepts the original container.
        return ['ok' => true, 'bytes' => $bytes, 'mime' => ($mime !== '' ? $mime : 'video/mp4')];
    }
    $ext = 'bin';
    if (str_contains($mime, 'quicktime') || str_ends_with(strtolower($nameHint), '.mov')) {
        $ext = 'mov';
    } elseif (str_contains($mime, 'webm') || str_ends_with(strtolower($nameHint), '.webm')) {
        $ext = 'webm';
    } elseif (str_contains($mime, 'mp4') || str_ends_with(strtolower($nameHint), '.mp4')) {
        $ext = 'mp4';
    }
    $in = tempnam(sys_get_temp_dir(), 'vaakvid_in_');
    $out = tempnam(sys_get_temp_dir(), 'vaakvid_out_');
    if ($in === false || $out === false) {
        return ['ok' => false, 'error' => 'tempnam failed for video remux'];
    }
    $inPath = $in . '.' . $ext;
    $outPath = $out . '.mp4';
    @unlink($in);
    @unlink($out);
    if (@file_put_contents($inPath, $bytes) === false) {
        @unlink($inPath);
        return ['ok' => false, 'error' => 'Could not write temp video for remux'];
    }
    // Fast path: remux without re-encode. Fall back to re-encode if copy fails.
    $cmdCopy = escapeshellarg($ffmpeg)
        . ' -y -hide_banner -loglevel error -i ' . escapeshellarg($inPath)
        . ' -c copy -movflags +faststart ' . escapeshellarg($outPath) . ' 2>&1';
    $outLog = [];
    $code = 0;
    exec($cmdCopy, $outLog, $code);
    if ($code !== 0 || !is_file($outPath) || filesize($outPath) < 32) {
        @unlink($outPath);
        $cmdEnc = escapeshellarg($ffmpeg)
            . ' -y -hide_banner -loglevel error -i ' . escapeshellarg($inPath)
            . ' -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 128k -movflags +faststart '
            . escapeshellarg($outPath) . ' 2>&1';
        $outLog = [];
        $code = 0;
        exec($cmdEnc, $outLog, $code);
    }
    @unlink($inPath);
    if ($code !== 0 || !is_file($outPath) || filesize($outPath) < 32) {
        @unlink($outPath);
        $err = trim(implode("\n", $outLog));
        return ['ok' => false, 'error' => 'ffmpeg remux failed' . ($err !== '' ? (': ' . $err) : '')];
    }
    $mp4 = file_get_contents($outPath);
    @unlink($outPath);
    if (!is_string($mp4) || $mp4 === '') {
        return ['ok' => false, 'error' => 'ffmpeg remux produced empty file'];
    }
    if (strlen($mp4) > 100 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Remuxed MP4 still too large (>100MB)'];
    }
    return ['ok' => true, 'bytes' => $mp4, 'mime' => 'video/mp4'];
}

/**
 * Service-auth JWT so video.bsky.app can uploadBlob to the user's PDS.
 *
 * @return array{ok:bool,error?:string,token?:string}
 */
function ap_bsky_get_service_auth(
    string $pdsHost,
    string $accessJwt,
    string $lxm = 'com.atproto.repo.uploadBlob',
    int $ttlSec = 1800
): array {
    $host = parse_url(rtrim($pdsHost, '/'), PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return ['ok' => false, 'error' => 'Invalid PDS host for service auth'];
    }
    $exp = time() + max(60, $ttlSec);
    $res = ap_bsky_xrpc($pdsHost, 'com.atproto.server.getServiceAuth', 'GET', [
        'aud' => 'did:web:' . $host,
        'lxm' => $lxm,
        'exp' => $exp,
    ], null, $accessJwt, 15);
    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => (string) ($res['error'] ?? 'getServiceAuth failed')];
    }
    $token = (string) ($res['json']['token'] ?? '');
    if ($token === '') {
        return ['ok' => false, 'error' => 'getServiceAuth missing token'];
    }
    return ['ok' => true, 'token' => $token];
}

/**
 * Upload video via video.bsky.app and poll until the PDS blob is ready.
 *
 * @return array{ok:bool,error?:string,blob?:array,jobId?:string}
 */
function ap_bsky_upload_video(
    string $pdsHost,
    string $accessJwt,
    string $did,
    string $bytes,
    string $mime = 'video/mp4',
    string $fileName = 'video.mp4',
    int $maxWaitSec = 180
): array {
    $did = trim($did);
    if ($did === '' || !str_starts_with($did, 'did:')) {
        return ['ok' => false, 'error' => 'Missing DID for video upload'];
    }
    if ($bytes === '') {
        return ['ok' => false, 'error' => 'Empty video bytes'];
    }
    $auth = ap_bsky_get_service_auth($pdsHost, $accessJwt);
    if (empty($auth['ok'])) {
        return ['ok' => false, 'error' => (string) ($auth['error'] ?? 'service auth failed')];
    }
    $token = (string) $auth['token'];
    $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName) ?: 'video.mp4';
    if (!str_contains($fileName, '.')) {
        $fileName .= '.mp4';
    }
    $uploadUrl = 'https://video.bsky.app/xrpc/app.bsky.video.uploadVideo?'
        . http_build_query(['did' => $did, 'name' => $fileName]);
    $ch = curl_init($uploadUrl);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }
    $contentType = str_starts_with(strtolower($mime), 'video/') ? $mime : 'video/mp4';
    // Lexicon declares video/mp4; prefer that when we remuxed.
    if ($contentType === 'video/m4v') {
        $contentType = 'video/mp4';
    }
    $respHeaders = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: ' . $contentType,
            'Content-Length: ' . (string) strlen($bytes),
            'Accept: application/json',
            'User-Agent: VAAK-Bluesky/1.0 (+https://mkultra.monster/vaak)',
        ],
        CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
            $respHeaders .= $line;
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    $rate = ap_bsky_parse_rate_limit_headers($respHeaders);
    if (!is_string($body) || $body === '') {
        return ['ok' => false, 'error' => $cerr !== '' ? $cerr : 'uploadVideo empty response'] + $rate;
    }
    $json = json_decode($body, true);
    // Some responses nest under jobStatus; others return JobStatus at the root.
    $job = null;
    if (is_array($json)) {
        if (isset($json['jobStatus']) && is_array($json['jobStatus'])) {
            $job = $json['jobStatus'];
        } elseif (isset($json['jobId']) || isset($json['state'])) {
            $job = $json;
        }
    }
    if ($status < 200 || $status >= 300 || !is_array($job)) {
        $msg = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : '';
        $errCode = is_array($json) ? (string) ($json['error'] ?? '') : '';
        $out = ['ok' => false, 'error' => $msg !== '' ? $msg : ('uploadVideo HTTP ' . $status), 'status' => $status] + $rate;
        if ($status === 429 || strcasecmp($errCode, 'RateLimitExceeded') === 0
            || stripos($msg, 'rate limit') !== false) {
            $out['rate_limited'] = true;
            $out['error'] = 'Rate limit exceeded';
            if (empty($out['retry_after_sec'])) {
                $out['retry_after_sec'] = ap_bsky_rate_limit_fallback_delay_sec($rate);
            }
        }
        return $out;
    }
    $jobId = (string) ($job['jobId'] ?? '');
    if ($jobId === '') {
        return ['ok' => false, 'error' => 'uploadVideo missing jobId'];
    }
    if (!empty($job['blob']) && is_array($job['blob'])) {
        return ['ok' => true, 'blob' => $job['blob'], 'jobId' => $jobId];
    }
    $state = (string) ($job['state'] ?? '');
    if ($state === 'JOB_STATE_FAILED') {
        $err = (string) ($job['error'] ?? $job['message'] ?? $job['failure_code'] ?? 'JOB_STATE_FAILED');
        return ['ok' => false, 'error' => 'Video job failed: ' . $err, 'jobId' => $jobId];
    }

    $deadline = time() + max(30, $maxWaitSec);
    $attempt = 0;
    while (time() < $deadline) {
        $attempt++;
        // Short polls early, then 2s.
        usleep($attempt <= 3 ? 750000 : 2000000);
        $poll = ap_bsky_xrpc('https://video.bsky.app', 'app.bsky.video.getJobStatus', 'GET', [
            'jobId' => $jobId,
        ], null, null, 20);
        if (empty($poll['ok'])) {
            continue;
        }
        $js = $poll['json']['jobStatus'] ?? $poll['json'] ?? null;
        if (!is_array($js)) {
            continue;
        }
        if (!empty($js['blob']) && is_array($js['blob'])) {
            return ['ok' => true, 'blob' => $js['blob'], 'jobId' => $jobId];
        }
        $state = (string) ($js['state'] ?? '');
        if ($state === 'JOB_STATE_FAILED') {
            $err = (string) ($js['error'] ?? $js['message'] ?? $js['failure_code'] ?? 'JOB_STATE_FAILED');
            return ['ok' => false, 'error' => 'Video job failed: ' . $err, 'jobId' => $jobId];
        }
    }
    return ['ok' => false, 'error' => 'Video processing timed out (job ' . $jobId . ')', 'jobId' => $jobId];
}

/**
 * Best-effort aspect ratio from media row / preview image.
 *
 * @param array<string,mixed> $mediaRow
 * @return array{width:int,height:int}|null
 */
function ap_bsky_video_aspect_ratio(array $mediaRow): ?array
{
    $w = (int) ($mediaRow['width'] ?? 0);
    $h = (int) ($mediaRow['height'] ?? 0);
    if ($w >= 1 && $h >= 1) {
        return ['width' => $w, 'height' => $h];
    }
    $preview = (string) ($mediaRow['preview_url'] ?? '');
    if ($preview !== '' && str_starts_with($preview, 'https://') && function_exists('getimagesize')) {
        $info = @getimagesize($preview);
        if (is_array($info) && (int) ($info[0] ?? 0) >= 1 && (int) ($info[1] ?? 0) >= 1) {
            return ['width' => (int) $info[0], 'height' => (int) $info[1]];
        }
    }
    return null;
}

/**
 * Push VAAK actor_profile (name, summary, icon, banner) onto the linked Bluesky
 * app.bsky.actor.profile record. Bio truncated to 256 graphemes with a link to
 * the full HTML profile when needed.
 *
 * @return array{ok:bool,error?:string,truncated?:bool}
 */
function ap_bsky_sync_profile_from_vaak(int $ownerUserId, ?string $actorKey = null): array
{
    $row = ap_bsky_session_row($ownerUserId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Bluesky not connected'];
    }
    if ($actorKey === null || $actorKey === '') {
        try {
            $st = ap_db()->prepare('SELECT actor_key, username FROM ap_users WHERE id = ? LIMIT 1');
            $st->execute([$ownerUserId]);
            $u = $st->fetch();
            $actorKey = is_array($u)
                ? (string) ($u['actor_key'] ?? $u['username'] ?? '')
                : '';
        } catch (Throwable $e) {
            $actorKey = '';
        }
    }
    if ($actorKey === '') {
        return ['ok' => false, 'error' => 'Missing VAAK actor key'];
    }
    if (!function_exists('ap_profile_get')) {
        return ['ok' => false, 'error' => 'Profile helpers unavailable'];
    }
    $prof = ap_profile_get($actorKey);
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No access token')];
    }
    $access = (string) $tok['access'];
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $did = (string) ($row['did'] ?? '');
    if ($did === '') {
        return ['ok' => false, 'error' => 'Missing DID'];
    }

    $profileUrl = 'https://mkultra.monster/users/' . rawurlencode($actorKey);
    $bio = ap_bsky_bio_from_vaak_summary((string) ($prof['summary'] ?? ''), $profileUrl);
    $displayName = trim((string) ($prof['name'] ?? ''));
    if (mb_strlen($displayName) > 64) {
        $displayName = mb_substr($displayName, 0, 64);
    }

    $avatarBlob = null;
    $bannerBlob = null;
    $iconUrl = (string) ($prof['icon_url'] ?? '');
    $imageUrl = (string) ($prof['image_url'] ?? '');
    if ($iconUrl !== '') {
        $img = ap_bsky_fetch_image_bytes($iconUrl);
        if (!empty($img['ok'])) {
            $up = ap_bsky_upload_blob($pds, $access, (string) $img['bytes'], (string) $img['mime']);
            if (!empty($up['ok'])) {
                $avatarBlob = $up['blob'];
            } elseif (($up['status'] ?? 0) === 401 || str_contains((string) ($up['error'] ?? ''), 'Expired')) {
                $tok = ap_bsky_access_token($ownerUserId, true);
                if (!empty($tok['ok'])) {
                    $access = (string) $tok['access'];
                    $up = ap_bsky_upload_blob($pds, $access, (string) $img['bytes'], (string) $img['mime']);
                    if (!empty($up['ok'])) {
                        $avatarBlob = $up['blob'];
                    }
                }
            }
        }
    }
    if ($imageUrl !== '') {
        $img = ap_bsky_fetch_image_bytes($imageUrl);
        if (!empty($img['ok'])) {
            $up = ap_bsky_upload_blob($pds, $access, (string) $img['bytes'], (string) $img['mime']);
            if (!empty($up['ok'])) {
                $bannerBlob = $up['blob'];
            }
        }
    }

    // Preserve existing profile fields we don't manage (e.g. createdAt) when present
    $existing = ap_bsky_xrpc($pds, 'com.atproto.repo.getRecord', 'GET', [
        'repo' => $did,
        'collection' => 'app.bsky.actor.profile',
        'rkey' => 'self',
    ], null, $access, 10);
    $record = ['$type' => 'app.bsky.actor.profile'];
    if (!empty($existing['ok']) && is_array($existing['json']['value'] ?? null)) {
        $record = $existing['json']['value'];
        $record['$type'] = 'app.bsky.actor.profile';
    }
    if ($displayName !== '') {
        $record['displayName'] = $displayName;
    }
    $record['description'] = (string) ($bio['description'] ?? '');
    if (is_array($avatarBlob)) {
        $record['avatar'] = $avatarBlob;
    }
    if (is_array($bannerBlob)) {
        $record['banner'] = $bannerBlob;
    }
    // Keep pinnedPost in sync with the newest VAAK pin that has a Bluesky twin.
    // (Bluesky supports a single profile pin; VAAK allows up to 5.)
    $pinRef = ap_bsky_pinned_strong_ref_from_vaak($ownerUserId);
    if ($pinRef !== null) {
        $record['pinnedPost'] = $pinRef;
    } else {
        // A routine VAAK profile update must not erase a valid Bluesky pin.
        // Explicit unpin actions use ap_bsky_sync_pin_to_bluesky(), which is
        // responsible for clearing pinnedPost after the user unpins locally.
    }

    $put = ap_bsky_xrpc($pds, 'com.atproto.repo.putRecord', 'POST', null, [
        'repo' => $did,
        'collection' => 'app.bsky.actor.profile',
        'rkey' => 'self',
        'record' => $record,
    ], $access, 20);
    if (empty($put['ok']) && (($put['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $put = ap_bsky_xrpc($pds, 'com.atproto.repo.putRecord', 'POST', null, [
                'repo' => $did,
                'collection' => 'app.bsky.actor.profile',
                'rkey' => 'self',
                'record' => $record,
            ], (string) $tok['access'], 20);
        }
    }
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => (string) ($put['error'] ?? 'putRecord failed'), 'truncated' => !empty($bio['truncated'])];
    }
    return ['ok' => true, 'truncated' => !empty($bio['truncated'])];
}

/**
 * Newest VAAK pin that has a Bluesky crosspost map → strongRef for profile.pinnedPost.
 *
 * @return array{uri:string,cid:string}|null
 */
function ap_bsky_pinned_strong_ref_from_vaak(int $ownerUserId): ?array
{
    if ($ownerUserId < 1 || !function_exists('ap_masto_pinned_statuses')) {
        return null;
    }
    try {
        $pins = ap_masto_pinned_statuses(5);
    } catch (Throwable $e) {
        return null;
    }
    foreach ($pins as $prow) {
        if (!is_array($prow)) {
            continue;
        }
        $noteId = rtrim((string) ($prow['note_id'] ?? ''), '/');
        if ($noteId === '' || !function_exists('ap_bsky_crosspost_by_note_id')) {
            continue;
        }
        $map = ap_bsky_crosspost_by_note_id($noteId);
        if (!is_array($map) || empty($map['bsky_uri'])) {
            // Tip map may be last segment; also try post_links by fedi id.
            if (function_exists('ap_bsky_post_link_by_fedi')) {
                $link = ap_bsky_post_link_by_fedi($noteId);
                if (is_array($link) && !empty($link['bsky_uri'])) {
                    $uri = (string) $link['bsky_uri'];
                    $cid = (string) ($link['bsky_cid'] ?? '');
                    if ($uri !== '' && $cid !== '') {
                        return ['uri' => $uri, 'cid' => $cid];
                    }
                    if ($uri !== '') {
                        $resolved = ap_bsky_resolve_strong_ref($uri, $ownerUserId);
                        if ($resolved !== null) {
                            return $resolved;
                        }
                    }
                }
            }
            continue;
        }
        $uri = (string) $map['bsky_uri'];
        $cid = (string) ($map['bsky_cid'] ?? '');
        if ($uri === '') {
            continue;
        }
        if ($cid === '') {
            $resolved = ap_bsky_resolve_strong_ref($uri, $ownerUserId);
            if ($resolved !== null) {
                return $resolved;
            }
            continue;
        }
        return ['uri' => $uri, 'cid' => $cid];
    }
    return null;
}

/**
 * Read pinnedPost from the linked Bluesky actor profile.
 *
 * @return array{uri:string,cid:string}|null
 */
function ap_bsky_get_profile_pinned_post(int $ownerUserId): ?array
{
    $row = ap_bsky_session_row($ownerUserId);
    if ($row === null) {
        return null;
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return null;
    }
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $did = (string) ($row['did'] ?? '');
    if ($did === '') {
        return null;
    }
    $existing = ap_bsky_xrpc($pds, 'com.atproto.repo.getRecord', 'GET', [
        'repo' => $did,
        'collection' => 'app.bsky.actor.profile',
        'rkey' => 'self',
    ], null, (string) $tok['access'], 12);
    if (empty($existing['ok']) || !is_array($existing['json']['value'] ?? null)) {
        return null;
    }
    $pin = $existing['json']['value']['pinnedPost'] ?? null;
    if (!is_array($pin)) {
        return null;
    }
    $uri = (string) ($pin['uri'] ?? '');
    $cid = (string) ($pin['cid'] ?? '');
    if ($uri === '' || $cid === '' || !str_starts_with($uri, 'at://')) {
        return null;
    }
    return ['uri' => $uri, 'cid' => $cid];
}

/**
 * Remove pinnedPost from the linked Bluesky actor profile.
 *
 * @return array{ok:bool,error?:string}
 */
function ap_bsky_clear_profile_pinned_post(int $ownerUserId): array
{
    $row = ap_bsky_session_row($ownerUserId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Bluesky not connected'];
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No access token')];
    }
    $access = (string) $tok['access'];
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $did = (string) ($row['did'] ?? '');
    if ($did === '') {
        return ['ok' => false, 'error' => 'Missing DID'];
    }
    $existing = ap_bsky_xrpc($pds, 'com.atproto.repo.getRecord', 'GET', [
        'repo' => $did,
        'collection' => 'app.bsky.actor.profile',
        'rkey' => 'self',
    ], null, $access, 12);
    $record = ['$type' => 'app.bsky.actor.profile'];
    if (!empty($existing['ok']) && is_array($existing['json']['value'] ?? null)) {
        $record = $existing['json']['value'];
        $record['$type'] = 'app.bsky.actor.profile';
    }
    unset($record['pinnedPost']);
    $put = ap_bsky_xrpc($pds, 'com.atproto.repo.putRecord', 'POST', null, [
        'repo' => $did,
        'collection' => 'app.bsky.actor.profile',
        'rkey' => 'self',
        'record' => $record,
    ], $access, 20);
    if (empty($put['ok']) && (($put['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $put = ap_bsky_xrpc($pds, 'com.atproto.repo.putRecord', 'POST', null, [
                'repo' => $did,
                'collection' => 'app.bsky.actor.profile',
                'rkey' => 'self',
                'record' => $record,
            ], (string) $tok['access'], 20);
        }
    }
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => (string) ($put['error'] ?? 'putRecord failed')];
    }
    return ['ok' => true];
}

/**
 * Push VAAK's newest pin (with Bluesky twin) to app.bsky.actor.profile pinnedPost.
 * Clears Bluesky pin when VAAK has no mapped pins.
 *
 * @return array{ok:bool,error?:string,uri?:?string,cleared?:bool}
 */
function ap_bsky_sync_pin_to_bluesky(int $ownerUserId): array
{
    $row = ap_bsky_session_row($ownerUserId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Bluesky not connected'];
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No access token')];
    }
    $access = (string) $tok['access'];
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $did = (string) ($row['did'] ?? '');
    if ($did === '') {
        return ['ok' => false, 'error' => 'Missing DID'];
    }
    $existing = ap_bsky_xrpc($pds, 'com.atproto.repo.getRecord', 'GET', [
        'repo' => $did,
        'collection' => 'app.bsky.actor.profile',
        'rkey' => 'self',
    ], null, $access, 12);
    $record = ['$type' => 'app.bsky.actor.profile'];
    if (!empty($existing['ok']) && is_array($existing['json']['value'] ?? null)) {
        $record = $existing['json']['value'];
        $record['$type'] = 'app.bsky.actor.profile';
    }
    $pinRef = ap_bsky_pinned_strong_ref_from_vaak($ownerUserId);
    if ($pinRef !== null) {
        $record['pinnedPost'] = $pinRef;
    } else {
        unset($record['pinnedPost']);
    }
    $put = ap_bsky_xrpc($pds, 'com.atproto.repo.putRecord', 'POST', null, [
        'repo' => $did,
        'collection' => 'app.bsky.actor.profile',
        'rkey' => 'self',
        'record' => $record,
    ], $access, 20);
    if (empty($put['ok']) && (($put['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $put = ap_bsky_xrpc($pds, 'com.atproto.repo.putRecord', 'POST', null, [
                'repo' => $did,
                'collection' => 'app.bsky.actor.profile',
                'rkey' => 'self',
                'record' => $record,
            ], (string) $tok['access'], 20);
        }
    }
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => (string) ($put['error'] ?? 'putRecord failed')];
    }
    return [
        'ok' => true,
        'uri' => $pinRef['uri'] ?? null,
        'cleared' => $pinRef === null,
    ];
}

/**
 * Pull Bluesky profile pin into VAAK when a local twin exists.
 *
 * @return array{ok:bool,error?:string,pinned_local_id?:int,skipped?:bool,reason?:string}
 */
function ap_bsky_import_pin_to_vaak(int $ownerUserId): array
{
    $pin = ap_bsky_get_profile_pinned_post($ownerUserId);
    if ($pin === null) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'no_bluesky_pin'];
    }
    $uri = $pin['uri'];
    $noteId = null;
    if (function_exists('ap_bsky_post_link_by_uri')) {
        $link = ap_bsky_post_link_by_uri($uri);
        if (is_array($link)) {
            $noteId = rtrim((string) ($link['fediverse_id'] ?? $link['ap_object_id'] ?? ''), '/');
        }
    }
    if (($noteId === null || $noteId === '') && function_exists('ap_bsky_crosspost_by_uri')) {
        $map = ap_bsky_crosspost_by_uri($uri);
        if (is_array($map)) {
            $noteId = rtrim((string) ($map['note_id'] ?? ''), '/');
        }
    }
    // Record may carry fediverseId (Wafrn / VAAK dual-publish).
    if ($noteId === null || $noteId === '' || !str_starts_with($noteId, 'https://mkultra.monster/')) {
        $tok = ap_bsky_access_token($ownerUserId, false);
        if (empty($tok['ok'])) {
            $tok = ap_bsky_access_token($ownerUserId, true);
        }
        if (!empty($tok['ok']) && preg_match('~^at://([^/]+)/([^/]+)/([^/]+)$~', $uri, $m)) {
            $row = ap_bsky_session_row($ownerUserId);
            $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
            $got = ap_bsky_xrpc($pds, 'com.atproto.repo.getRecord', 'GET', [
                'repo' => $m[1],
                'collection' => $m[2],
                'rkey' => $m[3],
            ], null, (string) $tok['access'], 12);
            $fedi = is_array($got['json']['value'] ?? null)
                ? rtrim((string) ($got['json']['value']['fediverseId'] ?? ''), '/')
                : '';
            if ($fedi !== '' && str_starts_with($fedi, 'https://mkultra.monster/')) {
                $noteId = $fedi;
            } elseif ($fedi !== '') {
                // Index remote twin for HTML fallback; cannot masto-pin non-local notes.
                if (function_exists('ap_bsky_post_link_upsert')) {
                    ap_bsky_post_link_upsert($uri, $pin['cid'], $fedi, $fedi);
                }
                return [
                    'ok' => true,
                    'skipped' => true,
                    'reason' => 'remote_fediverse_pin',
                    'fediverse_id' => $fedi,
                    'bsky_uri' => $uri,
                ];
            }
        }
    }
    if ($noteId === null || $noteId === '' || !str_starts_with($noteId, 'https://mkultra.monster/')) {
        return [
            'ok' => true,
            'skipped' => true,
            'reason' => 'no_local_twin',
            'bsky_uri' => $uri,
        ];
    }
    $st = ap_db()->prepare('SELECT local_id FROM masto_statuses WHERE note_id = ? OR note_id = ? LIMIT 1');
    $st->execute([$noteId, $noteId . '/']);
    $localId = (int) ($st->fetchColumn() ?: 0);
    if ($localId < 1) {
        return ['ok' => false, 'error' => 'Local status row missing for ' . $noteId];
    }
    // Pin as the owning user (not whatever request actor is active).
    $prev = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
    try {
        if (preg_match('#/users/([A-Za-z0-9_]+)/notes/#', $noteId, $um)
            && function_exists('ap_request_actor_set')) {
            ap_request_actor_set(strtolower($um[1]));
        }
        $res = function_exists('ap_masto_status_pin')
            ? ap_masto_status_pin($localId)
            : ['ok' => false, 'error' => 'pin unavailable'];
    } finally {
        if (function_exists('ap_request_actor_set')) {
            if (is_array($prev) && !empty($prev['key'])) {
                ap_request_actor_set((string) $prev['key']);
            } else {
                ap_request_actor_set(null);
            }
        }
    }
    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => (string) ($res['error'] ?? 'pin failed')];
    }
    return ['ok' => true, 'pinned_local_id' => $localId, 'note_id' => $noteId, 'bsky_uri' => $uri];
}

/**
 * HTML-profile helper: Bluesky pin card when the pin is not a local VAAK note
 * (e.g. historical Wafrn dual-publish). Returns an outbox-shaped row or null.
 *
 * @return array<string,mixed>|null
 */
function ap_bsky_html_pin_row_for_owner(int $ownerUserId): ?array
{
    // Once the user pins via VAAK, local masto_pins are the source of truth
    // (and sync out to Bluesky). Don't also show the legacy Bluesky/Wafrn pin.
    try {
        $stc = ap_db()->prepare('SELECT 1 FROM masto_pins WHERE owner_user_id = ? LIMIT 1');
        $stc->execute([$ownerUserId]);
        if ($stc->fetchColumn()) {
            return null;
        }
    } catch (Throwable $e) {
        // continue — still try to mirror Bluesky pin for display
    }
    $pin = ap_bsky_get_profile_pinned_post($ownerUserId);
    if ($pin === null) {
        return null;
    }
    $uri = $pin['uri'];
    // If we already have a local pin twin, HTML uses masto_pins — skip synthetic.
    if (function_exists('ap_bsky_post_link_by_uri')) {
        $link = ap_bsky_post_link_by_uri($uri);
        $fedi = is_array($link) ? rtrim((string) ($link['fediverse_id'] ?? $link['ap_object_id'] ?? ''), '/') : '';
        if ($fedi !== '' && str_starts_with($fedi, 'https://mkultra.monster/users/')) {
            return null;
        }
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok']) || !preg_match('~^at://([^/]+)/([^/]+)/([^/]+)$~', $uri, $m)) {
        return null;
    }
    $row = ap_bsky_session_row($ownerUserId);
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $got = ap_bsky_xrpc($pds, 'com.atproto.repo.getRecord', 'GET', [
        'repo' => $m[1],
        'collection' => $m[2],
        'rkey' => $m[3],
    ], null, (string) $tok['access'], 12);
    if (empty($got['ok']) || !is_array($got['json']['value'] ?? null)) {
        return null;
    }
    $val = $got['json']['value'];
    $text = trim((string) ($val['text'] ?? ''));
    $created = (string) ($val['createdAt'] ?? gmdate('c'));
    $fedi = rtrim((string) ($val['fediverseId'] ?? ''), '/');
    $thumb = '';
    $embed = $val['embed'] ?? null;
    if (is_array($embed)) {
        $images = $embed['images'] ?? null;
        if (!is_array($images) && isset($embed['media']) && is_array($embed['media'])) {
            $images = $embed['media']['images'] ?? null;
        }
        if (is_array($images) && isset($images[0]) && is_array($images[0])) {
            $img0 = $images[0];
            // Record embeds store blob refs; prefer AppView CDN via getPostThread when needed.
            if (!empty($img0['image']['ref']['$link'])) {
                $thumb = ''; // filled below via public API if possible
            }
        }
    }
    // Public AppView gives ready CDN URLs for the pin card.
    $thread = ap_bsky_xrpc('https://public.api.bsky.app', 'app.bsky.feed.getPostThread', 'GET', [
        'uri' => $uri,
        'depth' => '0',
    ], null, null, 12);
    if (!empty($thread['ok']) && is_array($thread['json']['thread']['post'] ?? null)) {
        $post = $thread['json']['thread']['post'];
        $emb = $post['embed'] ?? null;
        if (is_array($emb) && !empty($emb['images'][0]['fullsize'])) {
            $thumb = (string) $emb['images'][0]['fullsize'];
        } elseif (is_array($emb) && !empty($emb['media']['images'][0]['fullsize'])) {
            $thumb = (string) $emb['media']['images'][0]['fullsize'];
        }
        if ($text === '' && is_array($post['record'] ?? null)) {
            $text = trim((string) ($post['record']['text'] ?? ''));
        }
    }
    // Display-only mirror of the Bluesky pin — no outbound link to Bluesky/Wafrn.
    // Remains until the user pins/unpins via VAAK (then masto_pins take over).
    return [
        'id' => 'bsky-pin:' . $uri,
        'content' => $text !== '' ? htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '',
        'published' => $created,
        'kind' => 'bsky_pin',
        '_pinned' => true,
        '_bsky_uri' => $uri,
        '_thumb_url' => $thumb,
        '_external_href' => '',
        'visibility' => 'public',
    ];
}

/** Bluesky post text limit (grapheme clusters). */
const AP_BSKY_POST_GRAPHEME_LIMIT = 300;

function ap_bsky_grapheme_length(string $text): int
{
    if (function_exists('grapheme_strlen')) {
        $n = grapheme_strlen($text);
        return is_int($n) ? $n : mb_strlen($text);
    }
    return mb_strlen($text);
}

/**
 * Split plain text into Bluesky-sized thread segments (prefer paragraph / sentence / word breaks).
 *
 * @return list<string>
 */
function ap_bsky_split_thread_text(string $text, int $limit = AP_BSKY_POST_GRAPHEME_LIMIT): array
{
    $text = trim(ap_fix_utf8($text));
    if ($text === '') {
        return [];
    }
    if (ap_bsky_grapheme_length($text) <= $limit) {
        return [$text];
    }
    $parts = [];
    $remaining = $text;
    while ($remaining !== '') {
        if (ap_bsky_grapheme_length($remaining) <= $limit) {
            $parts[] = $remaining;
            break;
        }
        $chunk = function_exists('grapheme_substr')
            ? (string) grapheme_substr($remaining, 0, $limit)
            : mb_substr($remaining, 0, $limit);
        $breakAt = -1;
        foreach (["\n\n", "\n", '. ', '? ', '! ', '; ', ', ', ' '] as $sep) {
            $pos = mb_strrpos($chunk, $sep);
            if ($pos !== false && $pos >= (int) ($limit * 0.45)) {
                $breakAt = $pos + (($sep === ' ' || str_ends_with($sep, ' ')) ? mb_strlen(rtrim($sep)) : mb_strlen($sep));
                // Keep the punctuation with the chunk for ". " style seps
                if (in_array($sep, ['. ', '? ', '! ', '; ', ', '], true)) {
                    $breakAt = $pos + 1; // include punctuation, drop following space into next
                }
                break;
            }
        }
        if ($breakAt > 0) {
            $piece = trim(mb_substr($chunk, 0, $breakAt));
            $remaining = ltrim(mb_substr($remaining, $breakAt));
        } else {
            $piece = trim($chunk);
            $remaining = ltrim(function_exists('grapheme_substr')
                ? (string) grapheme_substr($remaining, $limit)
                : mb_substr($remaining, $limit));
        }
        if ($piece !== '') {
            $parts[] = $piece;
        }
        if (count($parts) > 40) { // safety
            if ($remaining !== '') {
                $parts[] = function_exists('grapheme_substr')
                    ? (string) grapheme_substr($remaining, 0, $limit)
                    : mb_substr($remaining, 0, $limit);
            }
            break;
        }
    }
    return $parts;
}

/**
 * Build app.bsky.richtext.facet list for URLs and hashtags (byte offsets).
 *
 * @return list<array<string,mixed>>
 */
function ap_bsky_build_facets(string $text): array
{
    $facets = [];
    $bytes = $text; // PHP strings are byte arrays; offsets must be UTF-8 byte indexes

    // URLs (http/https)
    if (preg_match_all('#https?://[^\s<>"\']+#iu', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $raw = $hit[0];
            $byteStart = $hit[1];
            // Trim trailing punctuation commonly glued to URLs
            $trimmed = rtrim($raw, '.,;:!?)】」\'"');
            $byteEnd = $byteStart + strlen($trimmed);
            if ($byteEnd <= $byteStart) {
                continue;
            }
            $uri = $trimmed;
            $facets[] = [
                'index' => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
                'features' => [[
                    '$type' => 'app.bsky.richtext.facet#link',
                    'uri' => $uri,
                ]],
            ];
        }
    }

    // Hashtags: #tag (unicode letters/numbers/underscore)
    if (preg_match_all('/(^|[^[:alnum:]_])#([\p{L}\p{N}_]+)/u', $text, $m, PREG_OFFSET_CAPTURE)) {
        $n = count($m[0]);
        for ($i = 0; $i < $n; $i++) {
            $full = $m[0][$i][0];
            $fullStart = $m[0][$i][1];
            $tag = $m[2][$i][0];
            // byte offset of '#' within the full match
            $hashPos = strpos($full, '#');
            if ($hashPos === false) {
                continue;
            }
            $byteStart = $fullStart + $hashPos;
            $byteEnd = $byteStart + strlen('#' . $tag);
            if ($tag === '' || $byteEnd <= $byteStart) {
                continue;
            }
            $facets[] = [
                'index' => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
                'features' => [[
                    '$type' => 'app.bsky.richtext.facet#tag',
                    'tag' => $tag,
                ]],
            ];
        }
    }

    usort($facets, static fn($a, $b) => ($a['index']['byteStart'] <=> $b['index']['byteStart']));
    return $facets;
}

/**
 * @param list<array{alt?:string,blob:array}> $images
 * @param array{blob:array,alt?:string,aspectRatio?:array{width:int,height:int}}|null $video
 * @param array{uri:string,cid:string}|null $quoteRef  Quote target strongRef
 * @return array{ok:bool,error?:string,uri?:string,cid?:string}
 */
function ap_bsky_create_post(
    string $pdsHost,
    string $accessJwt,
    string $did,
    string $text,
    ?array $reply = null,
    array $images = [],
    ?string $createdAt = null,
    ?string $fediverseId = null,
    ?array $quoteRef = null,
    ?array $video = null
): array {
    $record = [
        '$type' => 'app.bsky.feed.post',
        'text' => $text,
        'createdAt' => $createdAt !== null && $createdAt !== '' ? $createdAt : gmdate('c'),
    ];
    // Dual-publish marker (extra lexicon field; PDS preserves it).
    // Semantics (Wafrn): fediverseId means this Bluesky post is a *mirror* of the
    // canonical ActivityPub Note ("I'm just mirroring"); the AP id is the real post.
    if (is_string($fediverseId) && str_starts_with($fediverseId, 'https://')) {
        $record['fediverseId'] = $fediverseId;
    }
    $facets = ap_bsky_build_facets($text);
    if ($facets !== []) {
        $record['facets'] = $facets;
    }
    if (is_array($reply)) {
        $record['reply'] = $reply;
    }
    $quoteEmbed = null;
    if (is_array($quoteRef) && !empty($quoteRef['uri']) && !empty($quoteRef['cid'])
        && str_starts_with((string) $quoteRef['uri'], 'at://')) {
        $quoteEmbed = [
            '$type' => 'app.bsky.embed.record',
            'record' => [
                'uri' => (string) $quoteRef['uri'],
                'cid' => (string) $quoteRef['cid'],
            ],
        ];
    }
    // Bluesky: at most one video OR up to 4 images (not both). Prefer video when present.
    $mediaEmbed = null;
    if (is_array($video) && isset($video['blob']) && is_array($video['blob'])) {
        $videoEmbed = [
            '$type' => 'app.bsky.embed.video',
            'video' => $video['blob'],
        ];
        $alt = trim((string) ($video['alt'] ?? ''));
        if ($alt !== '') {
            $videoEmbed['alt'] = $alt;
        }
        if (isset($video['aspectRatio']) && is_array($video['aspectRatio'])) {
            $aw = (int) ($video['aspectRatio']['width'] ?? 0);
            $ah = (int) ($video['aspectRatio']['height'] ?? 0);
            if ($aw >= 1 && $ah >= 1) {
                $videoEmbed['aspectRatio'] = ['width' => $aw, 'height' => $ah];
            }
        }
        $mediaEmbed = $videoEmbed;
    } elseif ($images !== []) {
        $imgs = [];
        foreach (array_slice($images, 0, 4) as $img) {
            if (!is_array($img) || !isset($img['blob']) || !is_array($img['blob'])) {
                continue;
            }
            $imgs[] = [
                'alt' => (string) ($img['alt'] ?? ''),
                'image' => $img['blob'],
            ];
        }
        if ($imgs !== []) {
            $mediaEmbed = [
                '$type' => 'app.bsky.embed.images',
                'images' => $imgs,
            ];
        }
    }
    if ($mediaEmbed !== null && $quoteEmbed !== null) {
        $record['embed'] = [
            '$type' => 'app.bsky.embed.recordWithMedia',
            'record' => $quoteEmbed,
            'media' => $mediaEmbed,
        ];
    } elseif ($mediaEmbed !== null) {
        $record['embed'] = $mediaEmbed;
    } elseif ($quoteEmbed !== null) {
        $record['embed'] = $quoteEmbed;
    }
    $put = ap_bsky_xrpc($pdsHost, 'com.atproto.repo.createRecord', 'POST', null, [
        'repo' => $did,
        'collection' => 'app.bsky.feed.post',
        'record' => $record,
    ], $accessJwt, 8);
    if (empty($put['ok'])) {
        $out = ['ok' => false, 'error' => (string) ($put['error'] ?? 'createRecord failed')];
        if (ap_bsky_result_is_rate_limited($put)) {
            $out['rate_limited'] = true;
            $out['retry_after_sec'] = ap_bsky_result_retry_after_sec($put);
            $out['error'] = 'Rate limit exceeded';
        }
        return $out;
    }
    $uri = (string) ($put['json']['uri'] ?? '');
    $cid = (string) ($put['json']['cid'] ?? '');
    if ($uri === '' || $cid === '') {
        return ['ok' => false, 'error' => 'createRecord missing uri/cid'];
    }
    return ['ok' => true, 'uri' => $uri, 'cid' => $cid];
}

/**
 * Attach dual-publish markers onto the canonical AS2 Note (AP = "actually me").
 * - FEP-fffd proxy Link (rel=alternate, href=at://…) → Bluesky mirror
 * - Wafrn-compatible blueskyUri / blueskyCid fields
 *
 * @param array<string,mixed> $note
 * @return array<string,mixed>
 */
function ap_note_attach_bsky_proxy(array $note, string $bskyUri, ?string $bskyCid = null): array
{
    $bskyUri = trim($bskyUri);
    if ($bskyUri === '' || !str_starts_with($bskyUri, 'at://')) {
        return $note;
    }
    $htmlUrl = '';
    if (isset($note['url'])) {
        if (is_string($note['url']) && str_starts_with($note['url'], 'https://')) {
            $htmlUrl = $note['url'];
        } elseif (is_array($note['url'])) {
            foreach ($note['url'] as $u) {
                if (is_string($u) && str_starts_with($u, 'https://')) {
                    $htmlUrl = $u;
                    break;
                }
                if (is_array($u) && isset($u['href']) && is_string($u['href']) && str_starts_with($u['href'], 'https://')) {
                    $htmlUrl = $u['href'];
                    break;
                }
            }
        }
    }
    if ($htmlUrl === '' && isset($note['id']) && is_string($note['id'])) {
        $htmlUrl = $note['id'];
    }
    $proxyLink = [
        'type' => 'Link',
        'rel' => 'alternate',
        'href' => $bskyUri,
    ];
    $urls = [];
    if ($htmlUrl !== '') {
        $urls[] = $htmlUrl;
    }
    $urls[] = $proxyLink;
    $note['url'] = $urls;
    $note['blueskyUri'] = $bskyUri;
    if (is_string($bskyCid) && $bskyCid !== '') {
        $note['blueskyCid'] = $bskyCid;
    }
    return $note;
}

/**
 * Best-effort cross-post of a VAAK status to the user's connected Bluesky account.
 * Public/unlisted only. Splits long text into threads; attaches up to 4 images on the first post.
 *
 * Prefer calling this *before* ActivityPub fan-out so the Create can include FEP-fffd
 * proxy links (Wafrn / multi-protocol clients merge AP+Bluesky copies).
 *
 * @param list<int> $mediaLocalIds
 * @return array{ok:bool,skipped?:bool,error?:string,uri?:string,cid?:string,uris?:list<string>,cids?:list<string>,posts?:int}
 */
function ap_bsky_crosspost_status(
    int $ownerUserId,
    string $plainText,
    string $visibility = 'public',
    array $mediaLocalIds = [],
    string $spoilerText = '',
    ?string $inReplyTo = null,
    ?string $quoteObjectId = null,
    ?string $fediverseId = null
): array {
    if ($ownerUserId < 1) {
        return ['ok' => false, 'skipped' => true, 'error' => 'No owner'];
    }
    $visibility = strtolower(trim($visibility));
    if (!in_array($visibility, ['public', 'unlisted'], true)) {
        return ['ok' => true, 'skipped' => true, 'error' => 'Visibility not cross-posted'];
    }
    $row = ap_bsky_session_row($ownerUserId);
    if ($row === null) {
        return ['ok' => true, 'skipped' => true, 'error' => 'Bluesky not connected'];
    }

    // Interactive web publishes get a hard wall-clock budget so Bluesky DNS/PDS
    // blips cannot pin PHP-FPM workers. CLI retries (backfill) get a longer budget.
    $isCli = (PHP_SAPI === 'cli');
    $budgetSec = $isCli ? AP_BSKY_CROSSPOST_BUDGET_CLI : AP_BSKY_CROSSPOST_BUDGET_WEB;
    ap_bsky_budget_begin($budgetSec);
    try {
        return ap_bsky_crosspost_status_inner(
            $ownerUserId,
            $plainText,
            $visibility,
            $mediaLocalIds,
            $spoilerText,
            $inReplyTo,
            $quoteObjectId,
            $fediverseId,
            $row,
            $isCli
        );
    } finally {
        ap_bsky_budget_clear();
    }
}

/**
 * @param array<string,mixed> $row bsky_sessions row
 * @return array{ok:bool,skipped?:bool,error?:string,uri?:string,cid?:string,uris?:list<string>,cids?:list<string>,posts?:int,deferred?:bool}
 */
function ap_bsky_crosspost_status_inner(
    int $ownerUserId,
    string $plainText,
    string $visibility,
    array $mediaLocalIds,
    string $spoilerText,
    ?string $inReplyTo,
    ?string $quoteObjectId,
    ?string $fediverseId,
    array $row,
    bool $isCli
): array {
    // Resolve reply parent / quote target to Bluesky strongRefs when possible.
    $replyRef = null;
    $replyFallbackLink = null;
    if (is_string($inReplyTo) && $inReplyTo !== '') {
        // Profile URLs are not posts — don't treat as reply targets.
        if (preg_match('~^https://bsky\.app/profile/[^/]+/?$~i', rtrim($inReplyTo, '/'))) {
            return [
                'ok' => true,
                'skipped' => true,
                'error' => 'Reply parent is not a Bluesky post',
            ];
        }
        $parent = ap_bsky_resolve_strong_ref($inReplyTo, $ownerUserId);
        if ($parent === null) {
            // Pure Fediverse parent (Mastodon/Akkoma/etc.) with no Bluesky/Bridgy twin —
            // keep the reply on fedi only; do not orphan a root post on Bluesky.
            return [
                'ok' => true,
                'skipped' => true,
                'error' => 'Reply parent is Fediverse-only',
            ];
        }
        // Self-reply to our own split OP: nest under the tip segment, not a root sibling.
        $parent = ap_bsky_self_thread_tip_ref($parent, $ownerUserId);
        $replyRef = ap_bsky_reply_ref_for_parent($parent, $ownerUserId);
    }
    $quoteRef = null;
    if (is_string($quoteObjectId) && $quoteObjectId !== '') {
        $quoteRef = ap_bsky_resolve_strong_ref($quoteObjectId, $ownerUserId);
        if ($quoteRef === null) {
            // Do not turn a native Fediverse quote into a Bluesky text post
            // containing only a link. A quote mirrors only when its target has
            // a resolvable Bluesky identity (including a known dual-published
            // Fediverse/Bluesky mapping).
            return [
                'ok' => true,
                'skipped' => true,
                'error' => 'Quote target is Fediverse-only',
            ];
        }
    }
    if (ap_bsky_budget_exceeded()) {
        return ['ok' => false, 'deferred' => true, 'error' => 'Bluesky budget exceeded before auth'];
    }
    $tok = ap_bsky_access_token($ownerUserId, false, 4);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true, 4);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No Bluesky session')];
    }
    $access = (string) $tok['access'];
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $did = (string) ($row['did'] ?? '');
    if ($did === '') {
        return ['ok' => false, 'error' => 'Missing DID'];
    }

    $plainText = trim(ap_fix_utf8($plainText));
    // VAAK stores "(media)" / "(poll)" / "(quote)" as masto_statuses sentinels for
    // empty commentary — never mirror those placeholders onto Bluesky.
    if (in_array($plainText, ['(media)', '(poll)', '(quote)'], true)) {
        $plainText = '';
    }
    $spoilerText = trim(ap_fix_utf8($spoilerText));
    if ($spoilerText !== '') {
        $plainText = ($plainText !== '')
            ? ("CW: " . $spoilerText . "\n\n" . $plainText)
            : ('CW: ' . $spoilerText);
    }
    // Only append quote URL as text when we could not build a native quote embed.
    if ($quoteRef === null && is_string($quoteObjectId) && $quoteObjectId !== '' && str_starts_with($quoteObjectId, 'https://')) {
        $plainText = trim($plainText . ($plainText !== '' ? "\n\n" : '') . $quoteObjectId);
    }
    if (is_string($replyFallbackLink) && $replyFallbackLink !== '') {
        $plainText = trim('↩ re: ' . $replyFallbackLink . ($plainText !== '' ? "\n\n" . $plainText : ''));
    }

    // Upload media (first segment only). Bluesky allows 1 video XOR ≤4 images.
    $images = [];
    $videoEmbed = null;
    if ($mediaLocalIds !== [] && function_exists('ap_media_by_local_ids')) {
        if (!function_exists('ap_media_by_local_ids')) {
            require_once __DIR__ . '/ap-r2.php';
        }
        $mediaRows = ap_media_by_local_ids(array_map('intval', $mediaLocalIds));
        // Prefer the first video when present; otherwise upload images.
        $videoRow = null;
        foreach ($mediaRows as $m) {
            if (!is_array($m)) {
                continue;
            }
            $mime = strtolower((string) ($m['mime'] ?? $m['content_type'] ?? ''));
            if (str_starts_with($mime, 'video/')) {
                $videoRow = $m;
                break;
            }
        }
        // Video upload+transcode routinely exceeds the web budget — defer to CLI retry.
        if (is_array($videoRow) && !$isCli) {
            return [
                'ok' => false,
                'deferred' => true,
                'error' => 'Video mirror deferred to background retry',
            ];
        }
        if (is_array($videoRow)) {
            $url = (string) ($videoRow['public_url'] ?? $videoRow['url'] ?? $videoRow['remote_url'] ?? '');
            $mime = strtolower((string) ($videoRow['mime'] ?? $videoRow['content_type'] ?? 'video/mp4'));
            if ($url !== '' && str_starts_with($url, 'https://')) {
                $fetched = ap_bsky_fetch_video_bytes($url, $mime);
                if (!empty($fetched['ok'])) {
                    $nameHint = basename(parse_url($url, PHP_URL_PATH) ?: 'video.mov');
                    $mp4 = ap_bsky_video_ensure_mp4(
                        (string) $fetched['bytes'],
                        (string) ($fetched['mime'] ?? $mime),
                        is_string($nameHint) ? $nameHint : 'video.mov'
                    );
                    if (!empty($mp4['ok'])) {
                        $upName = pathinfo(is_string($nameHint) ? $nameHint : 'video', PATHINFO_FILENAME) . '.mp4';
                        $up = ap_bsky_upload_video(
                            $pds,
                            $access,
                            $did,
                            (string) $mp4['bytes'],
                            (string) ($mp4['mime'] ?? 'video/mp4'),
                            $upName,
                            180
                        );
                        if (empty($up['ok']) && str_contains((string) ($up['error'] ?? ''), 'Expired')) {
                            $tok = ap_bsky_access_token($ownerUserId, true);
                            if (!empty($tok['ok'])) {
                                $access = (string) $tok['access'];
                                $up = ap_bsky_upload_video(
                                    $pds,
                                    $access,
                                    $did,
                                    (string) $mp4['bytes'],
                                    (string) ($mp4['mime'] ?? 'video/mp4'),
                                    $upName,
                                    180
                                );
                            }
                        }
                        if (ap_bsky_result_is_rate_limited($up)) {
                            return [
                                'ok' => false,
                                'deferred' => true,
                                'rate_limited' => true,
                                'retry_after_sec' => ap_bsky_result_retry_after_sec($up),
                                'error' => 'Rate limit exceeded',
                            ];
                        }
                        if (!empty($up['ok']) && is_array($up['blob'] ?? null)) {
                            $videoEmbed = [
                                'blob' => $up['blob'],
                                'alt' => (string) ($videoRow['description'] ?? $videoRow['alt'] ?? ''),
                            ];
                            $ar = ap_bsky_video_aspect_ratio($videoRow);
                            if ($ar !== null) {
                                $videoEmbed['aspectRatio'] = $ar;
                            }
                        } else {
                            error_log('[ap-bsky] video upload failed: ' . (string) ($up['error'] ?? 'unknown'));
                        }
                    } else {
                        error_log('[ap-bsky] video remux failed: ' . (string) ($mp4['error'] ?? 'unknown'));
                    }
                } else {
                    error_log('[ap-bsky] video fetch failed: ' . (string) ($fetched['error'] ?? 'unknown'));
                }
            }
        } else {
            foreach (array_slice($mediaRows, 0, 4) as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $mime = strtolower((string) ($m['mime'] ?? $m['content_type'] ?? ''));
                if ($mime !== '' && !str_starts_with($mime, 'image/')) {
                    continue; // skip audio / non-image
                }
                $url = (string) ($m['public_url'] ?? $m['url'] ?? $m['remote_url'] ?? '');
                if ($url === '' || !str_starts_with($url, 'https://')) {
                    continue;
                }
                $img = ap_bsky_fetch_image_bytes($url);
                if (empty($img['ok'])) {
                    continue;
                }
                $up = ap_bsky_upload_blob($pds, $access, (string) $img['bytes'], (string) $img['mime']);
                if (empty($up['ok']) && (($up['status'] ?? 0) === 401)) {
                    $tok = ap_bsky_access_token($ownerUserId, true, 4);
                    if (!empty($tok['ok'])) {
                        $access = (string) $tok['access'];
                        $up = ap_bsky_upload_blob($pds, $access, (string) $img['bytes'], (string) $img['mime']);
                    }
                }
                if (ap_bsky_result_is_rate_limited($up)) {
                    return [
                        'ok' => false,
                        'deferred' => true,
                        'rate_limited' => true,
                        'retry_after_sec' => ap_bsky_result_retry_after_sec($up),
                        'error' => 'Rate limit exceeded',
                    ];
                }
                if (!empty($up['ok']) && is_array($up['blob'] ?? null)) {
                    $images[] = [
                        'alt' => (string) ($m['description'] ?? $m['alt'] ?? ''),
                        'blob' => $up['blob'],
                    ];
                }
            }
        }
    }

    if ($plainText === '' && $images === [] && $videoEmbed === null) {
        return ['ok' => true, 'skipped' => true, 'error' => 'Nothing to cross-post'];
    }

    $segments = $plainText !== '' ? ap_bsky_split_thread_text($plainText) : [''];
    if ($segments === []) {
        $segments = [''];
    }

    $uris = [];
    $cids = [];
    $root = null;
    $parent = null;
    $fediverseId = is_string($fediverseId) && str_starts_with($fediverseId, 'https://')
        ? $fediverseId
        : null;
    foreach ($segments as $i => $segment) {
        $reply = null;
        if ($i === 0 && is_array($replyRef)) {
            $reply = $replyRef;
        } elseif ($i > 0 && is_array($root) && is_array($parent)) {
            $reply = ['root' => $root, 'parent' => $parent];
        }
        $imgs = ($i === 0) ? $images : [];
        $vid = ($i === 0) ? $videoEmbed : null;
        $qEmbed = ($i === 0) ? $quoteRef : null;
        // Avoid empty text with no embed
        if (trim($segment) === '' && $imgs === [] && $vid === null && $qEmbed === null) {
            continue;
        }
        // 2s skew between long-post segments so AppView ordering stays stable.
        $created = gmdate('c', time() + ($i * 2));
        // Only stamp fediverseId on the root post (Wafrn merge key).
        $fedi = ($i === 0) ? $fediverseId : null;
        if (ap_bsky_budget_exceeded()) {
            return [
                'ok' => false,
                'deferred' => true,
                'error' => 'Bluesky budget exceeded before createRecord',
                'uris' => $uris,
                'cids' => $cids,
                'posts' => count($uris),
            ];
        }
        $res = ap_bsky_create_post($pds, $access, $did, $segment, $reply, $imgs, $created, $fedi, $qEmbed, $vid);
        if (empty($res['ok']) && str_contains((string) ($res['error'] ?? ''), 'Expired')) {
            $tok = ap_bsky_access_token($ownerUserId, true, 4);
            if (!empty($tok['ok'])) {
                $access = (string) $tok['access'];
                $res = ap_bsky_create_post($pds, $access, $did, $segment, $reply, $imgs, $created, $fedi, $qEmbed, $vid);
            }
        }
        if (empty($res['ok'])) {
            $err = (string) ($res['error'] ?? 'Bluesky create failed');
            $deferred = str_contains($err, 'budget exceeded') || str_contains($err, 'timed out');
            $out = [
                'ok' => false,
                'deferred' => $deferred,
                'error' => $err,
                'uris' => $uris,
                'cids' => $cids,
                'posts' => count($uris),
            ];
            if (ap_bsky_result_is_rate_limited($res)) {
                $out['rate_limited'] = true;
                $out['retry_after_sec'] = ap_bsky_result_retry_after_sec($res);
                $out['error'] = 'Rate limit exceeded';
                $out['deferred'] = true;
            }
            return $out;
        }
        $ref = ['uri' => (string) $res['uri'], 'cid' => (string) $res['cid']];
        $uris[] = $ref['uri'];
        $cids[] = $ref['cid'];
        if ($root === null) {
            // For AP replies, Bluesky thread root stays the remote parent root.
            // For long-post self-threads, first segment becomes the root.
            $root = is_array($replyRef) ? ($replyRef['root'] ?? $ref) : $ref;
        }
        $parent = $ref;
    }

    if ($uris !== [] && is_string($fediverseId) && $fediverseId !== '') {
        // Bidirectional map:
        // - Note.blueskyUri (caller) + record.fediverseId on segment 0 = FEP/Wafrn pair
        // - bsky_crossposts stores the *tip* so replies attach under the last chunk
        // - bsky_post_links indexes the *root* AT-URI back to the AP Note
        $tip = count($uris) - 1;
        ap_bsky_crosspost_save(
            $fediverseId,
            (string) $uris[$tip],
            $cids[$tip] ?? null,
            $ownerUserId
        );
        ap_bsky_post_link_upsert((string) $uris[0], $cids[0] ?? null, $fediverseId, $fediverseId);
        if ($tip > 0) {
            ap_bsky_post_link_upsert(
                (string) $uris[$tip],
                $cids[$tip] ?? null,
                $fediverseId,
                $fediverseId
            );
        }
    }

    return [
        'ok' => true,
        // Root AT-URI for FEP-fffd / Note.blueskyUri (first segment).
        'uri' => $uris[0] ?? null,
        'cid' => $cids[0] ?? null,
        // Tip used for reply nesting (same as uri when single-segment).
        'tip_uri' => $uris !== [] ? (string) $uris[count($uris) - 1] : null,
        'tip_cid' => $cids !== [] ? (string) $cids[count($cids) - 1] : null,
        'uris' => $uris,
        'cids' => $cids,
        'posts' => count($uris),
        'reply_nested' => is_array($replyRef),
        'reply_fallback' => is_string($replyFallbackLink) && $replyFallbackLink !== '',
    ];
}

/**
 * Create app.bsky.feed.repost for a strongRef.
 *
 * @param array{uri:string,cid:string} $subject
 * @return array{ok:bool,error?:string,uri?:string,cid?:string}
 */
function ap_bsky_create_repost(int $ownerUserId, array $subject, ?string $recordKey = null): array
{
    $uri = (string) ($subject['uri'] ?? '');
    $cid = (string) ($subject['cid'] ?? '');
    if (!str_starts_with($uri, 'at://') || $cid === '') {
        return ['ok' => false, 'error' => 'Invalid repost subject'];
    }
    $row = ap_bsky_session_row($ownerUserId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Bluesky not connected'];
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No session')];
    }
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $did = (string) ($row['did'] ?? '');
    $body = [
        'repo' => $did,
        'collection' => 'app.bsky.feed.repost',
        'record' => [
            '$type' => 'app.bsky.feed.repost',
            'subject' => ['uri' => $uri, 'cid' => $cid],
            'createdAt' => gmdate('c'),
        ],
    ];
    $endpoint = 'com.atproto.repo.createRecord';
    if ($recordKey !== null && preg_match('/^[A-Za-z0-9._~:-]{1,240}$/', $recordKey)) {
        $body['rkey'] = $recordKey;
        $endpoint = 'com.atproto.repo.putRecord';
    }
    $put = ap_bsky_xrpc($pds, $endpoint, 'POST', null, $body, (string) $tok['access'], 15);
    if (empty($put['ok']) && (($put['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $put = ap_bsky_xrpc($pds, $endpoint, 'POST', null, $body, (string) $tok['access'], 15);
        }
    }
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => (string) ($put['error'] ?? 'repost failed')];
    }
    return [
        'ok' => true,
        'uri' => (string) ($put['json']['uri'] ?? ''),
        'cid' => (string) ($put['json']['cid'] ?? ''),
    ];
}

/**
 * Best-effort Bluesky repost for a VAAK/AP object URL (or bsky.app / at://).
 *
 * @return array{ok:bool,skipped?:bool,error?:string,uri?:string}
 */
function ap_bsky_repost_object(int $ownerUserId, string $objectId, ?string $recordKey = null): array
{
    if ($ownerUserId < 1 || !ap_bsky_tab_enabled()) {
        return ['ok' => true, 'skipped' => true];
    }
    $ref = ap_bsky_resolve_strong_ref($objectId, $ownerUserId);
    if ($ref === null) {
        return ['ok' => true, 'skipped' => true, 'error' => 'No Bluesky subject'];
    }
    return ap_bsky_create_repost($ownerUserId, $ref, $recordKey);
}

/** Remove this account's Bluesky repost for a VAAK/AP object, when present. */
function ap_bsky_unrepost_object(int $ownerUserId, string $objectId): array
{
    if ($ownerUserId < 1 || !ap_bsky_tab_enabled()) {
        return ['ok' => true, 'skipped' => true];
    }
    $ref = ap_bsky_resolve_strong_ref($objectId, $ownerUserId);
    if ($ref === null) {
        return ['ok' => true, 'skipped' => true];
    }
    $row = ap_bsky_session_row($ownerUserId);
    if ($row === null) {
        return ['ok' => true, 'skipped' => true];
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    if (empty($tok['ok'])) {
        return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'No session')];
    }
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $did = (string) ($row['did'] ?? '');
    $list = ap_bsky_xrpc($pds, 'com.atproto.repo.listRecords', 'GET', [
        'repo' => $did,
        'collection' => 'app.bsky.feed.repost',
        'limit' => '100',
    ], null, (string) $tok['access'], 15);
    if (empty($list['ok'])) {
        return ['ok' => false, 'error' => (string) ($list['error'] ?? 'Could not inspect reposts')];
    }
    foreach ((array) ($list['json']['records'] ?? []) as $record) {
        $value = is_array($record['value'] ?? null) ? $record['value'] : [];
        $subject = is_array($value['subject'] ?? null) ? $value['subject'] : [];
        if ((string) ($subject['uri'] ?? '') !== (string) $ref['uri']) {
            continue;
        }
        $rkey = (string) ($record['uri'] ?? '');
        if (!preg_match('~/([^/]+)$~', $rkey, $m)) {
            continue;
        }
        return ap_bsky_delete_record_uri($ownerUserId, 'at://' . $did . '/app.bsky.feed.repost/' . $m[1]);
    }
    return ['ok' => true, 'skipped' => true];
}

/**
 * Collect every Bluesky post URI mapped to a VAAK note (root + split tip + links).
 *
 * @return list<string>
 */
function ap_bsky_uris_for_note(string $noteId): array
{
    $noteId = rtrim(trim($noteId), '/');
    if ($noteId === '' || !str_starts_with($noteId, 'https://')) {
        return [];
    }
    $uris = [];
    if (function_exists('ap_bsky_crosspost_by_note_id')) {
        $map = ap_bsky_crosspost_by_note_id($noteId);
        if (is_array($map) && !empty($map['bsky_uri'])) {
            $uris[] = trim((string) $map['bsky_uri']);
        }
    }
    try {
        ap_bsky_post_links_migrate();
        $st = ap_db()->prepare(
            'SELECT bsky_uri FROM bsky_post_links
             WHERE fediverse_id = ? OR fediverse_id = ? OR ap_object_id = ? OR ap_object_id = ?'
        );
        $st->execute([$noteId, $noteId . '/', $noteId, $noteId . '/']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $u = trim((string) ($row['bsky_uri'] ?? ''));
            if ($u !== '') {
                $uris[] = $u;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $st = ap_db()->prepare('SELECT raw_create_json FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
        $st->execute([$noteId, $noteId . '/']);
        $raw = (string) ($st->fetchColumn() ?: '');
        $j = json_decode($raw, true);
        $obj = is_array($j) ? ($j['object'] ?? $j) : null;
        if (is_array($obj) && !empty($obj['blueskyUri']) && is_string($obj['blueskyUri'])) {
            $uris[] = trim((string) $obj['blueskyUri']);
        }
    } catch (Throwable $e) {
        // ignore
    }
    $out = [];
    $seen = [];
    foreach ($uris as $u) {
        if (!str_starts_with($u, 'at://') || isset($seen[$u])) {
            continue;
        }
        $seen[$u] = true;
        $out[] = $u;
    }
    return $out;
}

/**
 * Delete all Bluesky post records mapped from a VAAK note (split threads included).
 *
 * @return array{ok:bool,skipped?:bool,error?:string,deleted?:int,uris?:list<string>}
 */
function ap_bsky_delete_crosspost_for_note(int $ownerUserId, string $noteId): array
{
    if ($ownerUserId < 1 || !ap_bsky_tab_enabled()) {
        return ['ok' => true, 'skipped' => true];
    }
    $noteId = rtrim(trim($noteId), '/');
    $uris = ap_bsky_uris_for_note($noteId);
    if ($uris === []) {
        return ['ok' => true, 'skipped' => true];
    }
    $deleted = 0;
    $errors = [];
    foreach ($uris as $uri) {
        $res = ap_bsky_delete_record_uri($ownerUserId, $uri);
        $err = strtolower((string) ($res['error'] ?? ''));
        // NotFound / already-gone still counts as cleaned up.
        if (!empty($res['ok']) || str_contains($err, 'not found') || str_contains($err, 'notfound')) {
            $deleted++;
            try {
                ap_db()->prepare('DELETE FROM bsky_posts WHERE bsky_uri = ?')->execute([$uri]);
            } catch (Throwable $e) {
                // ignore
            }
            try {
                ap_db()->prepare('DELETE FROM bsky_post_links WHERE bsky_uri = ?')->execute([$uri]);
            } catch (Throwable $e) {
                // ignore
            }
        } elseif (!empty($res['error'])) {
            $errors[] = (string) $res['error'];
        }
    }
    try {
        ap_db()->prepare('DELETE FROM bsky_crossposts WHERE note_id = ? OR note_id = ?')
            ->execute([$noteId, $noteId . '/']);
    } catch (Throwable $e) {
        // ignore
    }
    try {
        ap_db()->prepare(
            'DELETE FROM bsky_post_links WHERE fediverse_id = ? OR fediverse_id = ? OR ap_object_id = ? OR ap_object_id = ?'
        )->execute([$noteId, $noteId . '/', $noteId, $noteId . '/']);
    } catch (Throwable $e) {
        // ignore
    }
    if ($deleted < 1 && $errors !== []) {
        return ['ok' => false, 'error' => $errors[0], 'deleted' => 0, 'uris' => $uris];
    }
    return ['ok' => true, 'deleted' => $deleted, 'uris' => $uris];
}

/**
 * Bluesky → VAAK: if our dual-published Bluesky posts disappeared from the repo,
 * delete the local Note and federate ActivityPub Delete.
 *
 * @return array{checked:int,deleted:int}
 */
function ap_bsky_reconcile_deleted_crossposts(int $ownerUserId, int $limit = 15): array
{
    $out = ['checked' => 0, 'deleted' => 0];
    if ($ownerUserId < 1 || !ap_bsky_tab_enabled()) {
        return $out;
    }
    $throttle = sys_get_temp_dir() . '/vaak-bsky-del-reconcile-' . $ownerUserId;
    if (is_file($throttle) && (time() - (int) @filemtime($throttle)) < 300) {
        return $out;
    }
    @touch($throttle);

    try {
        ap_bsky_crossposts_migrate();
        $st = ap_db()->prepare(
            'SELECT note_id, bsky_uri FROM bsky_crossposts
             WHERE owner_user_id = ?
             ORDER BY created_at DESC
             LIMIT ?'
        );
        $st->execute([$ownerUserId, max(1, min(40, $limit))]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $out;
    }

    foreach ($rows as $row) {
        $uri = trim((string) ($row['bsky_uri'] ?? ''));
        $noteId = rtrim(trim((string) ($row['note_id'] ?? '')), '/');
        if (!str_starts_with($uri, 'at://') || !str_starts_with($noteId, 'https://')) {
            continue;
        }
        $out['checked']++;
        $got = ap_bsky_xrpc(AP_BSKY_PUBLIC_API, 'app.bsky.feed.getPosts', 'GET', [
            'uris' => $uri,
        ], null, null, 6);
        $posts = is_array($got['json']['posts'] ?? null) ? $got['json']['posts'] : [];
        $stillThere = false;
        foreach ($posts as $p) {
            if (is_array($p) && trim((string) ($p['uri'] ?? '')) === $uri) {
                $stillThere = true;
                break;
            }
        }
        // Also treat explicit NotFound from getRecord as gone.
        if ($stillThere) {
            continue;
        }
        // Confirm via PDS getRecord when possible (public AppView can lag).
        if (preg_match('~^at://([^/]+)/(app\.bsky\.feed\.post)/([^/]+)$~', $uri, $m)) {
            $tok = ap_bsky_access_token($ownerUserId, false);
            if (!empty($tok['ok'])) {
                $sess = ap_bsky_session_row($ownerUserId);
                $pds = rtrim((string) ($sess['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
                $rec = ap_bsky_xrpc($pds, 'com.atproto.repo.getRecord', 'GET', [
                    'repo' => $m[1],
                    'collection' => $m[2],
                    'rkey' => $m[3],
                ], null, (string) $tok['access'], 6);
                if (!empty($rec['ok'])) {
                    continue; // still on PDS
                }
            }
        }
        try {
            $lst = ap_db()->prepare(
                'SELECT local_id FROM masto_statuses WHERE note_id = ? OR note_id = ? LIMIT 1'
            );
            $lst->execute([$noteId, $noteId . '/']);
            $localId = (int) ($lst->fetchColumn() ?: 0);
            if ($localId < 1) {
                // Orphan map — just clear Bluesky cache/maps.
                ap_bsky_delete_crosspost_for_note($ownerUserId, $noteId);
                continue;
            }
            if (!function_exists('ap_delete_local_status')) {
                require_once __DIR__ . '/ap-inbox.php';
            }
            // Deletes any remaining Bluesky segments, then federates ActivityPub Delete.
            $del = ap_delete_local_status($localId);
            if (!empty($del['ok'])) {
                $out['deleted']++;
            }
        } catch (Throwable $e) {
            error_log('[ap-bsky] reconcile delete: ' . $e->getMessage());
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Bluesky → VAAK notifications (mentions / replies / quotes / reposts / likes)
// ---------------------------------------------------------------------------

function ap_bsky_actor_profile_url(string $handleOrDid): string
{
    $h = ltrim(trim($handleOrDid), '@');
    if ($h === '') {
        return 'https://bsky.app/';
    }
    // did:plc:… paths keep colons; handles are percent-encoded.
    // Never emit did%3Aplc%3A… — the bsky.app SPA 404s ("Post not found").
    if (str_starts_with($h, 'did%3A') || str_starts_with($h, 'did%3a')) {
        $h = rawurldecode($h);
    }
    $path = str_starts_with($h, 'did:') ? $h : rawurlencode($h);
    return 'https://bsky.app/profile/' . $path;
}

/**
 * Normalize a bsky.app web URL so DID profile paths keep literal colons.
 * Encoded did%3Aplc%3A… links 404 in the Bluesky web app.
 */
function ap_bsky_normalize_web_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || !str_starts_with($url, 'https://bsky.app/')) {
        return $url;
    }
    if (preg_match('~^(https://bsky\.app/profile/)([^/]+)(/post/[^/?#]+)?(.*)$~i', $url, $m)) {
        $actor = rawurldecode($m[2]);
        // DIDs must stay unencoded; handles may be re-encoded safely.
        $actorPath = str_starts_with($actor, 'did:') ? $actor : rawurlencode($actor);
        $post = $m[3] ?? '';
        if ($post !== '' && preg_match('~^/post/([^/?#]+)~', $post, $pm)) {
            $post = '/post/' . rawurlencode(rawurldecode($pm[1]));
        }
        return $m[1] . $actorPath . $post . ($m[4] ?? '');
    }
    return $url;
}

/**
 * Convert an AT-URI (or already-https URL) into a browser URL on bsky.app.
 */
function ap_bsky_https_url_from_at_uri(string $uri, ?string $authorHandle = null): string
{
    $uri = trim($uri);
    if ($uri === '') {
        return 'https://bsky.app/';
    }
    if (str_starts_with($uri, 'https://')) {
        return ap_bsky_normalize_web_url($uri);
    }
    // at://did:…/app.bsky.feed.post/RKEY  (use ~ delimiter — # appears in URLs)
    if (preg_match('~^at://([^/]+)/app\.bsky\.feed\.post/([^/\s?]+)~', $uri, $m)) {
        $actor = ($authorHandle !== null && $authorHandle !== '') ? $authorHandle : $m[1];
        if (str_starts_with($actor, 'did%3A') || str_starts_with($actor, 'did%3a')) {
            $actor = rawurldecode($actor);
        }
        // bsky.app keeps did:plc:… colons unencoded in profile paths.
        $actorPath = str_starts_with($actor, 'did:') ? $actor : rawurlencode($actor);
        return 'https://bsky.app/profile/' . $actorPath . '/post/' . rawurlencode($m[2]);
    }
    if (preg_match('~^at://([^/]+)~', $uri, $m)) {
        return ap_bsky_actor_profile_url($authorHandle !== null && $authorHandle !== '' ? $authorHandle : $m[1]);
    }
    return 'https://bsky.app/';
}

/**
 * File watermark fallback when bsky_sessions lacks notif_* columns.
 *
 * @return array{cursor:?string,seen_at:?string,polled_at:?string}
 */
function ap_bsky_notif_file_watermark(int $ownerUserId, ?array $set = null): array
{
    $dir = '/var/lib/mkultra/ap';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $path = $dir . '/bsky-notif-' . $ownerUserId . '.json';
    if (is_array($set)) {
        $payload = [
            'cursor' => isset($set['cursor']) && is_string($set['cursor']) ? $set['cursor'] : null,
            'seen_at' => isset($set['seen_at']) && is_string($set['seen_at']) ? $set['seen_at'] : null,
            'polled_at' => isset($set['polled_at']) && is_string($set['polled_at']) ? $set['polled_at'] : gmdate('c'),
        ];
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $payload;
    }
    $raw = is_file($path) ? @file_get_contents($path) : false;
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) {
        return ['cursor' => null, 'seen_at' => null, 'polled_at' => null];
    }
    return [
        'cursor' => isset($j['cursor']) && is_string($j['cursor']) ? $j['cursor'] : null,
        'seen_at' => isset($j['seen_at']) && is_string($j['seen_at']) ? $j['seen_at'] : null,
        'polled_at' => isset($j['polled_at']) && is_string($j['polled_at']) ? $j['polled_at'] : null,
    ];
}

/**
 * @return array{cursor:?string,seen_at:?string,polled_at:?string,has_columns:bool}
 */
function ap_bsky_notif_watermark_get(int $ownerUserId): array
{
    $row = ap_bsky_session_row($ownerUserId);
    if (is_array($row) && array_key_exists('notif_polled_at', $row)) {
        return [
            'cursor' => isset($row['notif_cursor']) && is_string($row['notif_cursor']) && $row['notif_cursor'] !== ''
                ? $row['notif_cursor'] : null,
            'seen_at' => isset($row['notif_seen_at']) && is_string($row['notif_seen_at']) && $row['notif_seen_at'] !== ''
                ? $row['notif_seen_at'] : null,
            'polled_at' => isset($row['notif_polled_at']) && is_string($row['notif_polled_at']) && $row['notif_polled_at'] !== ''
                ? $row['notif_polled_at'] : null,
            'has_columns' => true,
        ];
    }
    $file = ap_bsky_notif_file_watermark($ownerUserId);
    return $file + ['has_columns' => false];
}

function ap_bsky_notif_watermark_set(int $ownerUserId, ?string $cursor, ?string $seenAt, ?string $polledAt = null): void
{
    $polledAt = $polledAt ?? gmdate('c');
    $wm = ap_bsky_notif_watermark_get($ownerUserId);
    if (!empty($wm['has_columns'])) {
        try {
            $st = ap_db()->prepare(
                'UPDATE bsky_sessions
                 SET notif_cursor = ?, notif_seen_at = COALESCE(?, notif_seen_at), notif_polled_at = ?, updated_at = ?
                 WHERE owner_user_id = ?'
            );
            $st->execute([$cursor, $seenAt, $polledAt, gmdate('c'), $ownerUserId]);
            return;
        } catch (Throwable $e) {
            // Fall through to file watermark.
        }
    }
    ap_bsky_notif_file_watermark($ownerUserId, [
        'cursor' => $cursor,
        'seen_at' => $seenAt ?? ($wm['seen_at'] ?? null),
        'polled_at' => $polledAt,
    ]);
}

/**
 * @param list<string>|null $reasons
 * @return array{ok:bool,error?:string,notifications?:list<array>,cursor?:?string,seenAt?:?string}
 */
function ap_bsky_list_notifications(
    int $ownerUserId,
    int $limit = 40,
    ?string $cursor = null,
    ?array $reasons = null
): array {
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (empty($tok['ok'])) {
            return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Not connected')];
        }
    }
    $row = ap_bsky_session_row($ownerUserId);
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $query = ['limit' => max(1, min(100, $limit))];
    if (is_string($cursor) && $cursor !== '') {
        $query['cursor'] = $cursor;
    }
    // Prefer client-side reason filter — repeated `reasons=` query encoding varies by host.
    $allow = null;
    if (is_array($reasons) && $reasons !== []) {
        $allow = [];
        foreach ($reasons as $r) {
            $r = strtolower(trim((string) $r));
            if ($r !== '') {
                $allow[$r] = true;
            }
        }
    }

    $lastErr = 'Notification fetch failed';
    $res = null;
    foreach (ap_bsky_feed_hosts($pds) as $apiHost) {
        $attempt = ap_bsky_xrpc(
            $apiHost,
            'app.bsky.notification.listNotifications',
            'GET',
            $query,
            null,
            (string) $tok['access'],
            15
        );
        if (($attempt['status'] ?? 0) === 401) {
            $tok = ap_bsky_access_token($ownerUserId, true);
            if (empty($tok['ok'])) {
                return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Session expired')];
            }
            $attempt = ap_bsky_xrpc(
                $apiHost,
                'app.bsky.notification.listNotifications',
                'GET',
                $query,
                null,
                (string) $tok['access'],
                15
            );
        }
        if (!empty($attempt['ok']) && is_array($attempt['json'] ?? null)) {
            $res = $attempt;
            break;
        }
        $lastErr = (string) ($attempt['error'] ?? $lastErr);
    }
    if ($res === null) {
        return ['ok' => false, 'error' => $lastErr];
    }
    $j = $res['json'];
    $raw = is_array($j['notifications'] ?? null) ? $j['notifications'] : [];
    $out = [];
    foreach ($raw as $n) {
        if (!is_array($n)) {
            continue;
        }
        $reason = strtolower((string) ($n['reason'] ?? ''));
        if ($allow !== null && !isset($allow[$reason])) {
            continue;
        }
        $out[] = $n;
    }
    return [
        'ok' => true,
        'notifications' => $out,
        'cursor' => isset($j['cursor']) && is_string($j['cursor']) ? $j['cursor'] : null,
        'seenAt' => isset($j['seenAt']) && is_string($j['seenAt']) ? $j['seenAt'] : null,
    ];
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_bsky_update_seen(int $ownerUserId, ?string $seenAt = null): array
{
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (empty($tok['ok'])) {
            return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Not connected')];
        }
    }
    $row = ap_bsky_session_row($ownerUserId);
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $body = ['seenAt' => $seenAt !== null && $seenAt !== '' ? $seenAt : gmdate('c')];
    $lastErr = 'updateSeen failed';
    foreach (ap_bsky_feed_hosts($pds) as $apiHost) {
        // updateSeen is typically AppView-backed; try public API first for custom PDS.
        $attempt = ap_bsky_xrpc(
            $apiHost,
            'app.bsky.notification.updateSeen',
            'POST',
            null,
            $body,
            (string) $tok['access'],
            10
        );
        if (($attempt['status'] ?? 0) === 401) {
            $tok = ap_bsky_access_token($ownerUserId, true);
            if (empty($tok['ok'])) {
                return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Session expired')];
            }
            $attempt = ap_bsky_xrpc(
                $apiHost,
                'app.bsky.notification.updateSeen',
                'POST',
                null,
                $body,
                (string) $tok['access'],
                10
            );
        }
        if (!empty($attempt['ok'])) {
            return ['ok' => true];
        }
        $lastErr = (string) ($attempt['error'] ?? $lastErr);
    }
    return ['ok' => false, 'error' => $lastErr];
}

/**
 * Preview text for a liked/boosted Bluesky subject (our post).
 * Prefers local VAAK twin, then durable bsky_posts cache, then AppView getPosts.
 */
function ap_bsky_subject_post_preview_text(string $subjectAtOrUrl, int $ownerUserId = 0): string
{
    $subjectAtOrUrl = trim($subjectAtOrUrl);
    if ($subjectAtOrUrl === '') {
        return '';
    }
    $at = $subjectAtOrUrl;
    if (!str_starts_with($at, 'at://')) {
        $resolved = function_exists('ap_bsky_at_uri_from_any_url')
            ? ap_bsky_at_uri_from_any_url($subjectAtOrUrl)
            : (function_exists('ap_bsky_at_uri_from_https') ? ap_bsky_at_uri_from_https($subjectAtOrUrl, $ownerUserId) : null);
        if (is_string($resolved) && str_starts_with($resolved, 'at://')) {
            $at = $resolved;
        }
    }
    // Local twin (dual-publish or Bluesky→VAAK import).
    if ($ownerUserId > 0 && str_starts_with($at, 'at://')) {
        $noteId = ap_bsky_local_note_id_for_at_uri($at, $ownerUserId);
        if (is_string($noteId) && str_starts_with($noteId, 'https://')
            && function_exists('ap_masto_status_by_note_id')) {
            $local = ap_masto_status_by_note_id($noteId);
            $txt = trim((string) ($local['content_text'] ?? ''));
            if ($txt !== '') {
                return $txt;
            }
        }
    }
    // Durable cache.
    if (str_starts_with($at, 'at://')) {
        $item = ap_bsky_post_item_by_uri($at);
        if (is_array($item)) {
            $post = is_array($item['post'] ?? null) ? $item['post'] : [];
            $txt = trim((string) (($post['record']['text'] ?? null) ?: ($post['text'] ?? '')));
            if ($txt !== '') {
                return $txt;
            }
        }
        // Intentionally no sync AppView fetch here — notification list paint
        // must stay local/cache-only (Ice Cubes polls this path often).
    }
    return '';
}

/**
 * Map one Bluesky notification into a VAAK mentions row (no DB write).
 *
 * @return array<string,mixed>|null
 */
/**
 * True when a URL is likely a displayable image/GIF/video (not a bare webpage).
 */
function ap_bsky_url_looks_like_media(string $url): bool
{
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return false;
    }
    if (preg_match('/\.(gif|png|jpe?g|webp|mp4|webm)(\?|#|$)/i', $url)) {
        return true;
    }
    // Common Bluesky GIF / CDN hosts (Tenor, Giphy, Klipy, bsky CDN).
    return (bool) preg_match(
        '#https?://(?:(?:media|static)\.)?(?:tenor\.com|giphy\.com|klipy\.com|cdn\.bsky\.app)/#i',
        $url
    );
}

/**
 * HTTPS media URLs for a Bluesky notification (images + GIF external thumbs/uris).
 * Prefer hydrated PostView / durable cache; fall back to raw record external.uri;
 * optionally one AppView getPosts at ingest time (not list-paint).
 *
 * @return list<string>
 */
function ap_bsky_notification_media_urls(array $notif, int $ownerUserId = 0, bool $allowFetch = true): array
{
    $urls = [];
    $push = static function (string $u) use (&$urls): void {
        $u = trim($u);
        if ($u !== '' && str_starts_with($u, 'https://') && !in_array($u, $urls, true)) {
            $urls[] = $u;
        }
    };
    $fromPost = static function (array $post) use ($push): void {
        if (function_exists('ap_bsky_post_image_urls')) {
            foreach (ap_bsky_post_image_urls($post) as $u) {
                $push((string) $u);
            }
        }
        if (function_exists('ap_bsky_post_external')) {
            $ext = ap_bsky_post_external($post);
            if (is_array($ext)) {
                $thumb = trim((string) ($ext['thumb'] ?? ''));
                $uri = trim((string) ($ext['uri'] ?? ''));
                // One attachment only: animated/media URI when available, else CDN thumb.
                // Pushing both made notifications show GIF + still of the same clip.
                if ($uri !== '' && ap_bsky_url_looks_like_media($uri)) {
                    $push($uri);
                } elseif ($thumb !== '') {
                    $push($thumb);
                }
            }
        }
        if (function_exists('ap_bsky_post_video_media')) {
            foreach (ap_bsky_post_video_media($post) as $vid) {
                if (!is_array($vid)) {
                    continue;
                }
                $thumb = trim((string) ($vid['thumbnail'] ?? ''));
                if ($thumb !== '') {
                    $push($thumb);
                }
            }
        }
    };

    // Rare: notification already carries a view embed.
    if (is_array($notif['embed'] ?? null)) {
        $fromPost([
            'uri' => (string) ($notif['uri'] ?? ''),
            'author' => is_array($notif['author'] ?? null) ? $notif['author'] : [],
            'embed' => $notif['embed'],
        ]);
    }
    if (is_array($notif['post'] ?? null)) {
        $fromPost($notif['post']);
    }

    $uri = trim((string) ($notif['uri'] ?? ''));
    // Durable cache (timeline warm / prior getPosts).
    if ($urls === [] && str_starts_with($uri, 'at://') && function_exists('ap_bsky_post_item_by_uri')) {
        $item = ap_bsky_post_item_by_uri($uri);
        if (is_array($item) && is_array($item['post'] ?? null)) {
            $fromPost($item['post']);
        }
    }

    // Raw record: external GIF pages often put the .gif on external.uri (thumb is a blob).
    $record = is_array($notif['record'] ?? null) ? $notif['record'] : [];
    $emb = is_array($record['embed'] ?? null) ? $record['embed'] : null;
    if (is_array($emb)) {
        $type = (string) ($emb['$type'] ?? '');
        $ext = null;
        if ((str_contains($type, 'external') || isset($emb['external'])) && is_array($emb['external'] ?? null)) {
            $ext = $emb['external'];
        } elseif (str_contains($type, 'recordWithMedia') && is_array($emb['media']['external'] ?? null)) {
            $ext = $emb['media']['external'];
        }
        if (is_array($ext)) {
            $eu = trim((string) ($ext['uri'] ?? ''));
            if ($eu !== '' && ap_bsky_url_looks_like_media($eu)) {
                $push($eu);
            }
            // thumb may already be https on some AppViews
            if (is_string($ext['thumb'] ?? null)) {
                $push((string) $ext['thumb']);
            }
        }
    }

    // Ingest-time hydrate: one public getPosts so GIF/image CDN URLs land in media_urls.
    if ($urls === [] && $allowFetch && str_starts_with($uri, 'at://') && function_exists('ap_bsky_post_preview_from_url')) {
        $https = function_exists('ap_bsky_https_url_from_at_uri')
            ? ap_bsky_https_url_from_at_uri($uri, null)
            : '';
        if ($https !== '' && $https !== 'https://bsky.app/') {
            // Side effect: upserts bsky_posts when fetch succeeds.
            ap_bsky_post_preview_from_url($https, $ownerUserId, true);
            $item = function_exists('ap_bsky_post_item_by_uri') ? ap_bsky_post_item_by_uri($uri) : null;
            if (is_array($item) && is_array($item['post'] ?? null)) {
                $fromPost($item['post']);
            }
        }
    }

    return array_slice($urls, 0, 4);
}

function ap_bsky_notification_to_mention_row(int $ownerUserId, string $ownerActorId, array $notif): ?array
{
    $reason = strtolower(trim((string) ($notif['reason'] ?? '')));
    if (!in_array($reason, AP_BSKY_NOTIF_REASONS, true)) {
        return null;
    }
    // Follows go to events, not mentions.
    if ($reason === 'follow') {
        return null;
    }
    $uri = trim((string) ($notif['uri'] ?? ''));
    if ($uri === '') {
        return null;
    }
    $notifCid = trim((string) ($notif['cid'] ?? ''));
    $author = is_array($notif['author'] ?? null) ? $notif['author'] : [];
    $handle = ltrim(trim((string) ($author['handle'] ?? '')), '@');
    $did = trim((string) ($author['did'] ?? ''));
    if ($handle === '' && $did === '') {
        return null;
    }
    $actorKey = $handle !== '' ? $handle : $did;
    $actorId = ap_bsky_actor_profile_url($actorKey);

    // Skip self-noise (shouldn't appear, but be safe).
    $session = ap_bsky_session_row($ownerUserId);
    $selfDid = is_array($session) ? (string) ($session['did'] ?? '') : '';
    $selfHandle = is_array($session) ? strtolower((string) ($session['handle'] ?? '')) : '';
    if (($did !== '' && $did === $selfDid) || ($handle !== '' && strtolower($handle) === $selfHandle)) {
        return null;
    }

    $record = is_array($notif['record'] ?? null) ? $notif['record'] : [];
    $text = trim((string) ($record['text'] ?? ''));
    $reasonSubject = trim((string) ($notif['reasonSubject'] ?? ''));
    $indexedAt = trim((string) ($notif['indexedAt'] ?? ''));
    if ($indexedAt === '') {
        $indexedAt = gmdate('c');
    }

    $activityType = 'Create';
    $objType = 'Note';
    $kind = null;
    $subjectHandle = null;
    if ($reasonSubject !== '' && str_starts_with($reasonSubject, 'at://')) {
        $subjItem = ap_bsky_post_item_by_uri($reasonSubject);
        if (is_array($subjItem)) {
            $subjPost = is_array($subjItem['post'] ?? null) ? $subjItem['post'] : [];
            $subjAuthor = is_array($subjPost['author'] ?? null) ? $subjPost['author'] : [];
            $h = trim((string) ($subjAuthor['handle'] ?? ''));
            if ($h !== '') {
                $subjectHandle = $h;
            }
        }
    }
    $subjectHttps = $reasonSubject !== ''
        ? ap_bsky_https_url_from_at_uri($reasonSubject, $subjectHandle)
        : '';
    $postHttps = ap_bsky_https_url_from_at_uri($uri, $handle !== '' ? $handle : null);

    // Prefer local VAAK note id when the liked/boosted Bluesky post was cross-posted
    // or imported from Bluesky (including self-thread replies).
    $localNoteId = null;
    if ($reasonSubject !== '') {
        $localNoteId = ap_bsky_local_note_id_for_at_uri($reasonSubject, $ownerUserId);
    }

    switch ($reason) {
        case 'like':
            $activityType = 'Like';
            $objType = 'Like';
            $kind = 'like';
            // Like records have no text — preview the liked subject (works for replies too).
            if ($reasonSubject !== '') {
                $preview = ap_bsky_subject_post_preview_text($reasonSubject, $ownerUserId);
                if ($preview !== '') {
                    $text = $preview;
                }
            }
            if ($text === '') {
                $text = 'liked your Bluesky post';
            }
            break;
        case 'repost':
            $activityType = 'Announce';
            $objType = 'Announce';
            $kind = 'reblog';
            if ($reasonSubject !== '') {
                $preview = ap_bsky_subject_post_preview_text($reasonSubject, $ownerUserId);
                if ($preview !== '') {
                    $text = $preview;
                }
            }
            if ($text === '') {
                $text = 'boosted your Bluesky post';
            }
            break;
        case 'quote':
            $activityType = 'Quote';
            $objType = 'Note';
            // Quotes are their own post (the quote-boost). Do not pack object_id
            // as an interaction on the quoted subject — Open/like must target
            // the quoting record, not the original.
            $kind = null;
            break;
        case 'reply':
        case 'mention':
            $activityType = 'Create';
            $objType = 'Note';
            break;
    }

    // Interaction object_id: prefer local note id when mapped so resolve_our_liked_status works.
    if ($kind !== null && $localNoteId !== null) {
        $objectId = function_exists('ap_mention_interaction_object_id')
            ? ap_mention_interaction_object_id($localNoteId, $actorId, $kind)
            : ($localNoteId . '#' . $kind . '-' . substr(hash('sha256', $actorId . '|' . $uri), 0, 12));
    } elseif ($kind !== null && $subjectHttps !== '' && $subjectHttps !== 'https://bsky.app/') {
        $objectId = function_exists('ap_mention_interaction_object_id')
            ? ap_mention_interaction_object_id($subjectHttps, $actorId, $kind)
            : ($subjectHttps . '#' . $kind . '-' . substr(hash('sha256', $actorId . '|' . $uri), 0, 12));
    } else {
        $objectId = $postHttps !== 'https://bsky.app/' ? $postHttps : ('bsky:' . $uri);
    }

    if (in_array($reason, ['mention', 'reply', 'quote'], true)
        && str_starts_with($uri, 'at://') && str_contains($uri, '/app.bsky.feed.post/')
        && $notifCid !== '' && function_exists('ap_bsky_post_link_upsert')) {
        ap_bsky_post_link_upsert($uri, $notifCid, $postHttps !== 'https://bsky.app/' ? $postHttps : null);
    }

    $inReplyTo = null;
    if ($reason === 'reply' && $reasonSubject !== '') {
        $twin = function_exists('ap_bsky_local_note_id_for_at_uri')
            ? ap_bsky_local_note_id_for_at_uri($reasonSubject, $ownerUserId)
            : null;
        if (is_string($twin) && str_starts_with($twin, 'https://')) {
            $inReplyTo = rtrim($twin, '/');
        } else {
            $inReplyTo = ap_bsky_https_url_from_at_uri($reasonSubject, null);
        }
    } elseif ($reason === 'quote' && $reasonSubject !== '') {
        // Keep quote target discoverable in content for Ice Cubes.
        if ($text !== '') {
            $text .= "\n\n↪ QT " . ap_bsky_https_url_from_at_uri($reasonSubject, null);
        } else {
            $text = '↪ QT ' . ap_bsky_https_url_from_at_uri($reasonSubject, null);
        }
    }

    // Upsert remote actor cache so Ice Cubes shows handle + avatar.
    if (function_exists('ap_remote_actor_upsert')) {
        ap_remote_actor_upsert($actorId, [
            // Keep the full Bluesky handle in username; host is always bsky.app
            // so acct renders like chiitan.love@bsky.app.
            'username' => $handle !== '' ? $handle : $did,
            'display_name' => trim((string) ($author['displayName'] ?? '')) !== ''
                ? (string) $author['displayName']
                : ($handle !== '' ? $handle : $did),
            'host' => 'bsky.app',
            'icon_source_url' => isset($author['avatar']) && is_string($author['avatar'])
                ? $author['avatar'] : null,
        ]);
    }

    // Images / GIFs (external embeds) → mentions.media_urls for Ice Cubes + VAAK.
    $mediaUrls = [];
    if (in_array($reason, ['mention', 'reply', 'quote'], true)) {
        $mediaUrls = ap_bsky_notification_media_urls($notif, $ownerUserId, true);
    }
    if ($text === '' && $mediaUrls !== []) {
        // Keep empty body; clients render media_attachments. Avoid "(media)" sentinel
        // which hides that this was a Bluesky GIF/image share.
        $text = '';
    }

    return [
        'owner_user_id' => $ownerUserId,
        'owner_actor_id' => $ownerActorId,
        'activity_id' => $uri,
        'object_id' => $objectId,
        'actor_id' => $actorId,
        'type' => $objType,
        'activity_type' => $activityType,
        'content' => $text,
        'in_reply_to' => $inReplyTo,
        'created_at' => $indexedAt,
        'spoiler_text' => '',
        'sensitive' => 0,
        'media_urls' => $mediaUrls !== [] ? $mediaUrls : null,
    ];
}

/**
 * Store a Bluesky follow as a VAAK follow notification (events row).
 * Does NOT add to the AP followers table (would break delivery fan-out).
 *
 * @return array{inserted:bool,pushed:bool}
 */
function ap_bsky_ingest_follow_notification(
    int $ownerUserId,
    string $ownerActorId,
    array $notif,
    bool $pushNew = true
): array {
    $uri = trim((string) ($notif['uri'] ?? ''));
    $author = is_array($notif['author'] ?? null) ? $notif['author'] : [];
    $handle = ltrim(trim((string) ($author['handle'] ?? '')), '@');
    $did = trim((string) ($author['did'] ?? ''));
    if (($handle === '' && $did === '') || $uri === '') {
        return ['inserted' => false, 'pushed' => false];
    }
    $actorId = ap_bsky_actor_profile_url($handle !== '' ? $handle : $did);
    $indexedAt = trim((string) ($notif['indexedAt'] ?? ''));
    if ($indexedAt === '') {
        $indexedAt = gmdate('c');
    }

    if (function_exists('ap_remote_actor_upsert')) {
        ap_remote_actor_upsert($actorId, [
            'username' => $handle !== '' ? $handle : $did,
            'display_name' => trim((string) ($author['displayName'] ?? '')) !== ''
                ? (string) $author['displayName']
                : ($handle !== '' ? $handle : $did),
            'host' => 'bsky.app',
            'icon_source_url' => isset($author['avatar']) && is_string($author['avatar'])
                ? $author['avatar'] : null,
        ]);
    }

    // Dedupe by activity uri stored in object_id.
    try {
        $st = ap_db()->prepare(
            "SELECT id FROM events
             WHERE type = 'Follow' AND action_taken = ?
               AND object_id = ?
             LIMIT 1"
        );
        $st->execute([AP_BSKY_FOLLOW_ACTION, $uri]);
        if ($st->fetchColumn()) {
            return ['inserted' => false, 'pushed' => false];
        }
    } catch (Throwable $e) {
        // proceed
    }

    if (function_exists('ap_metrics_record')) {
        ap_metrics_record(
            'Follow',
            $actorId,
            $uri,
            $ownerActorId,
            0,
            AP_BSKY_FOLLOW_ACTION,
            $handle !== '' ? ('@' . $handle) : $did,
            null,
            $indexedAt
        );
    } else {
        return ['inserted' => false, 'pushed' => false];
    }

    $pushed = false;
    if ($pushNew && function_exists('ap_webpush_notify_event')) {
        $nid = null;
        try {
            if (function_exists('ap_masto_notification_id_for_follow_event')) {
                $fst = ap_db()->prepare(
                    "SELECT id, created_at FROM events
                     WHERE type = 'Follow' AND action_taken = ?
                       AND object_id = ?
                     ORDER BY id DESC LIMIT 1"
                );
                $fst->execute([AP_BSKY_FOLLOW_ACTION, $uri]);
                $frow = $fst->fetch();
                if (is_array($frow)) {
                    $nid = ap_masto_notification_id_for_follow_event(
                        (int) $frow['id'],
                        isset($frow['created_at']) ? (string) $frow['created_at'] : null
                    );
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        ap_webpush_notify_event('follow', $actorId, $nid, null, $ownerUserId);
        $pushed = true;
    }
    return ['inserted' => true, 'pushed' => $pushed];
}

/**
 * @param list<array> $notifs
 * @return array{ok:bool,inserted:int,skipped:int,pushed:int,errors:list<string>}
 */
function ap_bsky_ingest_notifications(int $ownerUserId, array $notifs, bool $pushNew = true): array
{
    $inserted = 0;
    $skipped = 0;
    $pushed = 0;
    $errors = [];
    if ($ownerUserId < 1) {
        return ['ok' => false, 'inserted' => 0, 'skipped' => 0, 'pushed' => 0, 'errors' => ['bad owner']];
    }
    $ownerActorId = function_exists('ap_db_owner_actor_id_for_user_id')
        ? (string) ap_db_owner_actor_id_for_user_id($ownerUserId)
        : '';
    if ($ownerActorId === '' && function_exists('ap_local_actor_id')) {
        $ownerActorId = (string) ap_local_actor_id();
    }

    foreach ($notifs as $notif) {
        if (!is_array($notif)) {
            $skipped++;
            continue;
        }
        try {
            $reason = strtolower(trim((string) ($notif['reason'] ?? '')));
            if ($reason === 'follow') {
                $fr = ap_bsky_ingest_follow_notification($ownerUserId, $ownerActorId, $notif, $pushNew);
                if (!empty($fr['inserted'])) {
                    $inserted++;
                    if (!empty($fr['pushed'])) {
                        $pushed++;
                    }
                } else {
                    $skipped++;
                }
                continue;
            }
            $row = ap_bsky_notification_to_mention_row($ownerUserId, $ownerActorId, $notif);
            if ($row === null) {
                $skipped++;
                continue;
            }
            $activityId = (string) ($row['activity_id'] ?? '');
            $objectId = (string) ($row['object_id'] ?? '');
            $exists = false;
            try {
                $st = ap_db()->prepare(
                    'SELECT id FROM mentions
                     WHERE owner_user_id = ?
                       AND deleted_at IS NULL
                       AND (activity_id = ? OR object_id = ?)
                     LIMIT 1'
                );
                $st->execute([$ownerUserId, $activityId, $objectId]);
                $exists = (bool) $st->fetchColumn();
            } catch (Throwable $e) {
                // proceed to store
            }
            if ($exists) {
                $skipped++;
                continue;
            }
            if (!function_exists('ap_mention_store')) {
                $errors[] = 'ap_mention_store missing';
                continue;
            }
            ap_mention_store($row);
            $inserted++;

            if ($pushNew && function_exists('ap_webpush_notify_event') && function_exists('ap_masto_mention_notif_type')) {
                $type = ap_masto_mention_notif_type($row);
                if (is_string($type) && $type !== '') {
                    $nid = null;
                    try {
                        $st = ap_db()->prepare(
                            'SELECT id, created_at FROM mentions
                             WHERE owner_user_id = ? AND object_id = ? AND deleted_at IS NULL
                             ORDER BY id DESC LIMIT 1'
                        );
                        $st->execute([$ownerUserId, $objectId]);
                        $mrow = $st->fetch();
                        if (is_array($mrow) && function_exists('ap_masto_notification_id_for_mention')) {
                            $nid = ap_masto_notification_id_for_mention(
                                (int) $mrow['id'],
                                isset($mrow['created_at']) ? (string) $mrow['created_at'] : null
                            );
                        }
                    } catch (Throwable $e) {
                        // push without stable id
                    }
                    ap_webpush_notify_event(
                        $type,
                        (string) ($row['actor_id'] ?? ''),
                        $nid,
                        (string) ($row['content'] ?? ''),
                        $ownerUserId
                    );
                    $pushed++;
                }
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    return [
        'ok' => $errors === [],
        'inserted' => $inserted,
        'skipped' => $skipped,
        'pushed' => $pushed,
        'errors' => $errors,
    ];
}

/** True when a Bluesky post record @-mentions this DID. */
function ap_bsky_record_mentions_did(?array $record, string $did): bool
{
    $did = trim($did);
    if ($did === '' || !is_array($record)) {
        return false;
    }
    $facets = $record['facets'] ?? [];
    if (!is_array($facets)) {
        return false;
    }
    foreach ($facets as $facet) {
        if (!is_array($facet)) {
            continue;
        }
        $features = $facet['features'] ?? [];
        if (!is_array($features)) {
            continue;
        }
        foreach ($features as $feat) {
            if (!is_array($feat)) {
                continue;
            }
            $t = strtolower((string) ($feat['$type'] ?? ''));
            if (str_contains($t, 'mention') && strcasecmp((string) ($feat['did'] ?? ''), $did) === 0) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Flatten getPostThread nodes into PostView-shaped posts (root first).
 *
 * @param array<string,mixed> $node
 * @param list<array<string,mixed>> $out
 */
function ap_bsky_collect_thread_posts(array $node, int $depth, int $maxDepth, array &$out): void
{
    if ($depth > $maxDepth || count($out) >= 40) {
        return;
    }
    $t = (string) ($node['$type'] ?? '');
    if ($t !== '' && (str_contains($t, 'NotFound') || str_contains($t, 'Blocked'))) {
        return;
    }
    $post = is_array($node['post'] ?? null) ? $node['post'] : null;
    if (is_array($post) && trim((string) ($post['uri'] ?? '')) !== '') {
        $out[] = $post;
    }
    $replies = $node['replies'] ?? [];
    if (!is_array($replies)) {
        return;
    }
    foreach ($replies as $child) {
        if (is_array($child)) {
            ap_bsky_collect_thread_posts($child, $depth + 1, $maxDepth, $out);
        }
    }
}

/**
 * Bluesky only emits `reply` when someone replies to *your* post, and `mention`
 * when the record still @-tags you. A follow-up reply to their own mention of
 * you is neither, so listNotifications never returns it. Walk recent mention
 * threads and ingest those replies as VAAK mention notifications.
 *
 * @return array{ok:bool,inserted:int,skipped:int,scanned:int}
 */
function ap_bsky_ingest_mention_thread_replies(int $ownerUserId, bool $pushNew = true): array
{
    $out = ['ok' => true, 'inserted' => 0, 'skipped' => 0, 'scanned' => 0];
    if ($ownerUserId < 1 || !ap_bsky_tab_enabled()) {
        return $out;
    }
    $session = ap_bsky_session_row($ownerUserId);
    if (!is_array($session)) {
        return $out;
    }
    $selfDid = trim((string) ($session['did'] ?? ''));
    $selfHandle = strtolower(ltrim((string) ($session['handle'] ?? ''), '@'));
    $pds = rtrim((string) ($session['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $since = gmdate('c', time() - 7 * 86400);
    $roots = [];
    try {
        $st = ap_db()->prepare(
            "SELECT activity_id FROM mentions
             WHERE owner_user_id = ?
               AND deleted_at IS NULL
               AND activity_id LIKE 'at://%'
               AND created_at >= ?
               AND COALESCE(activity_type, '') IN ('Create', 'Mention', 'Quote', '')
             ORDER BY created_at DESC
             LIMIT 12"
        );
        $st->execute([$ownerUserId, $since]);
        while ($row = $st->fetch()) {
            $uri = trim((string) ($row['activity_id'] ?? ''));
            if (str_starts_with($uri, 'at://') && str_contains($uri, '/app.bsky.feed.post/')) {
                $roots[$uri] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('[ap-bsky] mention thread roots: ' . $e->getMessage());
        return $out;
    }
    if ($roots === []) {
        return $out;
    }

    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
    }
    $access = !empty($tok['ok']) ? (string) $tok['access'] : null;
    $synthetic = [];

    foreach (array_keys($roots) as $rootUri) {
        $out['scanned']++;
        $threadRes = null;
        $hosts = ap_bsky_feed_hosts($pds);
        $hosts[] = AP_BSKY_PUBLIC_API;
        foreach ($hosts as $apiHost) {
            $attempt = ap_bsky_xrpc(
                $apiHost,
                'app.bsky.feed.getPostThread',
                'GET',
                ['uri' => $rootUri, 'depth' => '4'],
                null,
                $access,
                10
            );
            if (!empty($attempt['ok']) && is_array($attempt['json']['thread'] ?? null)) {
                $threadRes = $attempt;
                break;
            }
        }
        if ($threadRes === null) {
            continue;
        }
        $posts = [];
        ap_bsky_collect_thread_posts($threadRes['json']['thread'], 0, 4, $posts);
        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }
            $uri = trim((string) ($post['uri'] ?? ''));
            if ($uri === '' || isset($roots[$uri])) {
                continue; // skip the mention root itself
            }
            $author = is_array($post['author'] ?? null) ? $post['author'] : [];
            $authorDid = trim((string) ($author['did'] ?? ''));
            $authorHandle = strtolower(ltrim((string) ($author['handle'] ?? ''), '@'));
            if (($selfDid !== '' && $authorDid === $selfDid)
                || ($selfHandle !== '' && $authorHandle === $selfHandle)) {
                continue;
            }
            $record = is_array($post['record'] ?? null) ? $post['record'] : [];
            $parentUri = '';
            $reply = is_array($record['reply'] ?? null) ? $record['reply'] : [];
            if (is_array($reply['parent'] ?? null)) {
                $parentUri = trim((string) ($reply['parent']['uri'] ?? ''));
            }
            $rootReplyUri = '';
            if (is_array($reply['root'] ?? null)) {
                $rootReplyUri = trim((string) ($reply['root']['uri'] ?? ''));
            }
            $isDirectReplyToMention = $parentUri !== '' && isset($roots[$parentUri]);
            $isSameThreadAsMention = $rootReplyUri !== '' && isset($roots[$rootReplyUri]);
            $tagsUs = $selfDid !== '' && ap_bsky_record_mentions_did($record, $selfDid);
            // Direct reply to a post that mentioned us, or a later @-mention in
            // that thread. Do not ingest the entire hellthread.
            if (!$isDirectReplyToMention && !($isSameThreadAsMention && $tagsUs)) {
                continue;
            }
            $synthetic[] = [
                'reason' => $tagsUs ? 'mention' : 'reply',
                'uri' => $uri,
                'cid' => (string) ($post['cid'] ?? ''),
                'author' => $author,
                'record' => $record,
                'reasonSubject' => $parentUri !== '' ? $parentUri : $rootUri,
                'indexedAt' => (string) ($post['indexedAt'] ?? ($record['createdAt'] ?? gmdate('c'))),
                'isRead' => false,
            ];
            if (count($synthetic) >= 25) {
                break 2;
            }
        }
    }

    if ($synthetic === []) {
        return $out;
    }
    $ing = ap_bsky_ingest_notifications($ownerUserId, $synthetic, $pushNew);
    $out['inserted'] = (int) ($ing['inserted'] ?? 0);
    $out['skipped'] = (int) ($ing['skipped'] ?? 0);
    $out['ok'] = !empty($ing['ok']);
    return $out;
}

/**
 * Poll Bluesky notifications for one connected user and ingest into VAAK.
 *
 * @return array{ok:bool,error?:string,fetched?:int,inserted?:int,skipped?:int,pushed?:int,backfill?:bool}
 */
function ap_bsky_poll_notifications(int $ownerUserId, int $limit = 50): array
{
    if ($ownerUserId < 1) {
        return ['ok' => false, 'error' => 'bad owner'];
    }
    if (!ap_bsky_tab_enabled()) {
        return ['ok' => false, 'error' => 'bluesky_tab disabled'];
    }
    if (ap_bsky_session_row($ownerUserId) === null) {
        return ['ok' => false, 'error' => 'not connected'];
    }

    ap_bsky_migrate();
    $wm = ap_bsky_notif_watermark_get($ownerUserId);
    $isBackfill = ($wm['polled_at'] === null || $wm['polled_at'] === '');

    $listed = ap_bsky_list_notifications(
        $ownerUserId,
        $limit,
        null, // always take newest page; dedupe handles repeats
        AP_BSKY_NOTIF_REASONS
    );
    if (empty($listed['ok'])) {
        return ['ok' => false, 'error' => (string) ($listed['error'] ?? 'list failed')];
    }
    $notifs = is_array($listed['notifications'] ?? null) ? $listed['notifications'] : [];

    // Ensure webpush + masto helpers are available for push + type mapping.
    if (!function_exists('ap_webpush_notify_event')) {
        $wp = __DIR__ . '/ap-webpush.php';
        if (is_file($wp)) {
            require_once $wp;
        }
    }
    if (!function_exists('ap_masto_mention_notif_type')) {
        $ent = __DIR__ . '/ap-masto-entities.php';
        if (is_file($ent)) {
            require_once __DIR__ . '/ap-r2.php';
            require_once $ent;
        }
    }

    $ing = ap_bsky_ingest_notifications($ownerUserId, $notifs, !$isBackfill);
    $threadIng = ['inserted' => 0, 'skipped' => 0, 'scanned' => 0];
    if (!$isBackfill) {
        try {
            $threadIng = ap_bsky_ingest_mention_thread_replies($ownerUserId, true);
        } catch (Throwable $e) {
            error_log('[ap-bsky] mention thread replies: ' . $e->getMessage());
        }
    }
    $seenAt = gmdate('c');
    ap_bsky_notif_watermark_set(
        $ownerUserId,
        isset($listed['cursor']) && is_string($listed['cursor']) ? $listed['cursor'] : ($wm['cursor'] ?? null),
        $seenAt,
        $seenAt
    );
    // Best-effort: mark Bluesky side seen so their app unread badge clears too.
    ap_bsky_update_seen($ownerUserId, $seenAt);

    return [
        'ok' => true,
        'fetched' => count($notifs),
        'inserted' => (int) ($ing['inserted'] ?? 0) + (int) ($threadIng['inserted'] ?? 0),
        'skipped' => (int) ($ing['skipped'] ?? 0) + (int) ($threadIng['skipped'] ?? 0),
        'pushed' => (int) ($ing['pushed'] ?? 0),
        'thread_scanned' => (int) ($threadIng['scanned'] ?? 0),
        'backfill' => $isBackfill,
        'errors' => $ing['errors'] ?? [],
    ];
}

/**
 * Poll every connected Bluesky session.
 *
 * @return array{ok:bool,users:int,inserted:int,pushed:int,errors:list<string>}
 */
function ap_bsky_poll_all_notifications(int $limit = 50): array
{
    ap_bsky_migrate();
    $users = 0;
    $inserted = 0;
    $pushed = 0;
    $errors = [];
    try {
        $rows = ap_db()->query('SELECT owner_user_id FROM bsky_sessions ORDER BY owner_user_id ASC')->fetchAll();
    } catch (Throwable $e) {
        return ['ok' => false, 'users' => 0, 'inserted' => 0, 'pushed' => 0, 'errors' => [$e->getMessage()]];
    }
    foreach ($rows as $row) {
        $uid = (int) ($row['owner_user_id'] ?? 0);
        if ($uid < 1) {
            continue;
        }
        $users++;
        $res = ap_bsky_poll_notifications($uid, $limit);
        if (empty($res['ok'])) {
            $errors[] = 'user ' . $uid . ': ' . (string) ($res['error'] ?? 'fail');
            continue;
        }
        $inserted += (int) ($res['inserted'] ?? 0);
        $pushed += (int) ($res['pushed'] ?? 0);
        foreach ($res['errors'] ?? [] as $err) {
            if (is_string($err) && $err !== '') {
                $errors[] = 'user ' . $uid . ': ' . $err;
            }
        }
    }
    return [
        'ok' => $errors === [],
        'users' => $users,
        'inserted' => $inserted,
        'pushed' => $pushed,
        'errors' => $errors,
    ];
}
