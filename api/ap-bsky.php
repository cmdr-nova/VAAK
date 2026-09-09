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
const AP_BSKY_PUBLIC_API = 'https://public.api.bsky.app';

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
function ap_bsky_local_note_id_for_at_uri(string $atUri, int $ownerUserId = 0): ?string
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
    }
    if ($uri === null || !str_starts_with($uri, 'at://')) {
        return null;
    }
    if ($cid !== null && $cid !== '') {
        return ['uri' => $uri, 'cid' => $cid];
    }
    // Fetch cid via getRecord
    if ($ownerUserId < 1) {
        return null;
    }
    if (!preg_match('~^at://([^/]+)/([^/]+)/([^/]+)$~', $uri, $m)) {
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
    $got = ap_bsky_xrpc($pds, 'com.atproto.repo.getRecord', 'GET', [
        'repo' => $m[1],
        'collection' => $m[2],
        'rkey' => $m[3],
    ], null, (string) $tok['access'], 12);
    if (empty($got['ok'])) {
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
    }
    $cid = (string) ($got['json']['cid'] ?? '');
    if ($cid === '') {
        return null;
    }
    return ['uri' => $uri, 'cid' => $cid];
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
function ap_bsky_xrpc(
    string $base,
    string $nsid,
    string $method = 'GET',
    ?array $query = null,
    ?array $jsonBody = null,
    ?string $bearer = null,
    int $timeoutSec = 10
): array {
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
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSec),
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno !== 0 || !is_string($body)) {
        return ['ok' => false, 'error' => $err !== '' ? $err : 'HTTP request failed', 'status' => $status];
    }
    $json = json_decode($body, true);
    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'status' => $status, 'json' => $json, 'body' => $body];
    }
    $msg = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : '';
    if ($msg === '') {
        $msg = 'HTTP ' . $status;
    }
    return ['ok' => false, 'error' => $msg, 'status' => $status, 'json' => $json, 'body' => $body];
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
 * @return array{ok:bool,error?:string,access?:string}
 */
function ap_bsky_access_token(int $ownerUserId, bool $forceRefresh = false): array
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
        return ['ok' => false, 'error' => 'Session expired — reconnect Bluesky'];
    }
    $pds = rtrim((string) ($row['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    if (!$forceRefresh && is_string($access) && $access !== '') {
        return ['ok' => true, 'access' => $access];
    }
    // refreshSession: Authorization = refreshJwt, POST with NO body
    // (PDS returns 400 "body provided when none was expected" if we send {}).
    $ch = curl_init($pds . '/xrpc/com.atproto.server.refreshSession');
    if ($ch === false) {
        return ['ok' => false, 'error' => 'Refresh failed'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
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
        return [
            'ok' => false,
            'error' => $msg !== ''
                ? ('Session refresh failed: ' . $msg)
                : 'Session expired — reconnect Bluesky',
        ];
    }
    $newAccess = (string) ($j['accessJwt'] ?? '');
    $newRefresh = (string) ($j['refreshJwt'] ?? $refresh);
    if ($newAccess === '') {
        return ['ok' => false, 'error' => 'Session expired — reconnect Bluesky'];
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
        $attempt = ap_bsky_xrpc($apiHost, 'app.bsky.feed.getTimeline', 'GET', $query, null, (string) $tok['access'], 12);
        if (($attempt['status'] ?? 0) === 401) {
            $tok = ap_bsky_access_token($ownerUserId, true);
            if (empty($tok['ok'])) {
                return ['ok' => false, 'error' => (string) ($tok['error'] ?? 'Session expired')];
            }
            $attempt = ap_bsky_xrpc($apiHost, 'app.bsky.feed.getTimeline', 'GET', $query, null, (string) $tok['access'], 12);
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
    $query = ['actor' => $actor, 'limit' => max(1, min(50, $limit))];
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
            ap_bsky_index_feed_items($feed, $ownerUserId, 15);
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

function ap_bsky_posts_migrate(): void
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
                    WHERE table_schema = current_schema() AND table_name = 'bsky_posts'
                 )"
            )->fetchColumn();
            if (!$have) {
                ap_db()->exec(<<<'SQL'
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
                    ap_db()->exec('CREATE INDEX IF NOT EXISTS idx_bsky_posts_author_idx ON bsky_posts (author_did, indexed_at DESC)');
                    ap_db()->exec('CREATE INDEX IF NOT EXISTS idx_bsky_posts_seen ON bsky_posts (seen_at)');
                    ap_db()->exec('CREATE INDEX IF NOT EXISTS idx_bsky_posts_owner_idx ON bsky_posts (owner_user_id, indexed_at DESC)');
                } catch (Throwable $e) {
                    // ignore
                }
            }
        } else {
            ap_db()->exec(<<<'SQL'
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
 * Compact embed projection for cache (images / external / quote stub).
 *
 * @param array<string,mixed>|null $embed
 * @return array<string,mixed>|null
 */
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
        return [
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
    ];
    if (isset($record['reply']) && is_array($record['reply'])) {
        $raw['record']['reply'] = $record['reply'];
    }
    if (!empty($record['fediverseId'])) {
        $raw['record']['fediverseId'] = (string) $record['fediverseId'];
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
            } catch (Throwable $e) {
                // ignore
            }
        });
    }
}

/**
 * Recent cached Bluesky posts for Home mix (posts this owner last warmed/saw).
 *
 * @return list<array{post:array,reason?:?array,bsky_uri:string,indexed_at:?string,fediverse_id:?string}>
 */
function ap_bsky_posts_for_home(int $ownerUserId, int $limit = 40, ?string $excludeAuthorDid = null): array
{
    if ($ownerUserId < 1) {
        return [];
    }
    ap_bsky_posts_migrate();
    $limit = max(1, min(80, $limit));
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
function ap_bsky_create_like(int $ownerUserId, array $subject): array
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
    $put = ap_bsky_xrpc($pds, 'com.atproto.repo.createRecord', 'POST', null, $body, (string) $tok['access'], 15);
    if (empty($put['ok']) && (($put['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $put = ap_bsky_xrpc($pds, 'com.atproto.repo.createRecord', 'POST', null, $body, (string) $tok['access'], 15);
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
        ];
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
    // Live XRPC uses value.text; our durable compact cache may put text on the record root.
    $text = trim((string) ($value['text'] ?? $viewRecord['text'] ?? ''));
    $uri = (string) ($viewRecord['uri'] ?? '');
    $url = $uri !== ''
        ? ap_bsky_https_url_from_at_uri($uri, $handle !== '' ? $handle : null)
        : 'https://bsky.app/';
    // No usable quote payload (common with broken compact cache) — don't render an empty shell.
    if ($text === '' && $handle === '' && ($uri === '' || $url === 'https://bsky.app/')) {
        return null;
    }
    return [
        'uri' => $uri !== '' ? $uri : null,
        'handle' => $handle,
        'display' => $display,
        'text' => $text,
        'url' => $url,
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
    if ($text === '' && $handle === '' && $uri === '') {
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
        if ($prev !== null && ($prev['text'] !== '' || $prev['handle'] !== '')) {
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
    return ['ok' => true];
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

/**
 * Follow a Bluesky DID via app.bsky.graph.follow.
 *
 * @return array{ok:bool,error?:string,skipped?:bool,already?:bool,uri?:string}
 */
function ap_bsky_follow_actor(int $ownerUserId, string $didOrRef): array
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
        return ['ok' => true, 'already' => true, 'uri' => (string) $existing['bsky_uri']];
    }
    // Also check live profile viewer state
    $prof = ap_bsky_get_profile($ownerUserId, $did);
    if (!empty($prof['ok']) && is_array($prof['profile']['viewer'] ?? null)) {
        $followUri = (string) ($prof['profile']['viewer']['following'] ?? '');
        if (str_starts_with($followUri, 'at://')) {
            ap_bsky_graph_sync_upsert($ownerUserId, 'follow', $did, $followUri, 'pull');
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
    $put = ap_bsky_xrpc($pds, 'com.atproto.repo.createRecord', 'POST', null, $body, (string) $tok['access'], 12);
    if (empty($put['ok']) && (($put['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $put = ap_bsky_xrpc($pds, 'com.atproto.repo.createRecord', 'POST', null, $body, (string) $tok['access'], 12);
        }
    }
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => (string) ($put['error'] ?? 'Bluesky follow failed')];
    }
    $uri = (string) ($put['json']['uri'] ?? '');
    ap_bsky_graph_sync_upsert($ownerUserId, 'follow', $did, $uri !== '' ? $uri : null, 'vaak');
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
 * Pull Bluesky blocks, mutes, and subscribed modlist members into hide set.
 *
 * @return array{ok:bool,error?:string,blocks?:int,mutes?:int,list_members?:int}
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
    $listCount = 0;

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

    // Subscribed blocklists (listblocks)
    $listUris = [];
    $cursor = null;
    for ($page = 0; $page < 10; $page++) {
        $q = ['limit' => 50];
        if ($cursor) {
            $q['cursor'] = $cursor;
        }
        $j = $fetchPage('app.bsky.graph.getListBlocks', $q);
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
                ap_bsky_hide_did_add($ownerUserId, $did, 'listblock', $listUri);
                $listCount++;
            }
            $lCursor = isset($j['cursor']) && is_string($j['cursor']) ? $j['cursor'] : null;
            if ($lCursor === null) {
                break;
            }
        }
    }

    @file_put_contents($cachePath, json_encode([
        'at' => $now,
        'blocks' => $blockCount,
        'mutes' => $muteCount,
        'list_members' => $listCount,
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);

    return [
        'ok' => true,
        'blocks' => $blockCount,
        'mutes' => $muteCount,
        'list_members' => $listCount,
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
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
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
    $pdsHost = rtrim($pdsHost, '/');
    $url = $pdsHost . '/xrpc/com.atproto.repo.uploadBlob';
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessJwt,
            'Content-Type: ' . $mime,
            'Accept: application/json',
            'User-Agent: VAAK-Bluesky/1.0 (+https://mkultra.monster/vaak)',
        ],
        CURLOPT_POSTFIELDS => $bytes,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($json) || !isset($json['blob'])) {
        $msg = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : '';
        return ['ok' => false, 'error' => $msg !== '' ? $msg : ('uploadBlob HTTP ' . $status)];
    }
    return ['ok' => true, 'blob' => $json['blob']];
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
 * @return array{ok:bool,error?:string,uri?:string,cid?:string}
 */
/**
 * @param array{uri:string,cid:string}|null $quoteRef  Quote target strongRef
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
    ?array $quoteRef = null
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
    if ($images !== []) {
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
            if ($quoteEmbed !== null) {
                $record['embed'] = [
                    '$type' => 'app.bsky.embed.recordWithMedia',
                    'record' => $quoteEmbed,
                    'media' => [
                        '$type' => 'app.bsky.embed.images',
                        'images' => $imgs,
                    ],
                ];
            } else {
                $record['embed'] = [
                    '$type' => 'app.bsky.embed.images',
                    'images' => $imgs,
                ];
            }
        } elseif ($quoteEmbed !== null) {
            $record['embed'] = $quoteEmbed;
        }
    } elseif ($quoteEmbed !== null) {
        $record['embed'] = $quoteEmbed;
    }
    $put = ap_bsky_xrpc($pdsHost, 'com.atproto.repo.createRecord', 'POST', null, [
        'repo' => $did,
        'collection' => 'app.bsky.feed.post',
        'record' => $record,
    ], $accessJwt, 20);
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => (string) ($put['error'] ?? 'createRecord failed')];
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
    // Resolve reply parent / quote target to Bluesky strongRefs when possible.
    $replyRef = null;
    if (is_string($inReplyTo) && $inReplyTo !== '') {
        $parent = ap_bsky_resolve_strong_ref($inReplyTo, $ownerUserId);
        if ($parent === null) {
            // Parent has no Bluesky twin — skip rather than create an orphan root.
            return ['ok' => true, 'skipped' => true, 'error' => 'Reply parent not on Bluesky'];
        }
        $replyRef = ['root' => $parent, 'parent' => $parent];
        // If parent is itself a reply, prefer its root — best-effort getRecord reply.root.
        // (VAAK threads onto the immediate parent as both root+parent when unknown.)
    }
    $quoteRef = null;
    if (is_string($quoteObjectId) && $quoteObjectId !== '') {
        $quoteRef = ap_bsky_resolve_strong_ref($quoteObjectId, $ownerUserId);
    }
    $tok = ap_bsky_access_token($ownerUserId, false);
    if (empty($tok['ok'])) {
        $tok = ap_bsky_access_token($ownerUserId, true);
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

    // Upload images (first segment only)
    $images = [];
    if ($mediaLocalIds !== [] && function_exists('ap_media_by_local_ids')) {
        if (!function_exists('ap_media_by_local_ids')) {
            require_once __DIR__ . '/ap-r2.php';
        }
        $mediaRows = ap_media_by_local_ids(array_map('intval', $mediaLocalIds));
        foreach (array_slice($mediaRows, 0, 4) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $mime = strtolower((string) ($m['mime'] ?? $m['content_type'] ?? ''));
            if ($mime !== '' && !str_starts_with($mime, 'image/')) {
                continue; // skip video/audio for now
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
                $tok = ap_bsky_access_token($ownerUserId, true);
                if (!empty($tok['ok'])) {
                    $access = (string) $tok['access'];
                    $up = ap_bsky_upload_blob($pds, $access, (string) $img['bytes'], (string) $img['mime']);
                }
            }
            if (!empty($up['ok']) && is_array($up['blob'] ?? null)) {
                $images[] = [
                    'alt' => (string) ($m['description'] ?? $m['alt'] ?? ''),
                    'blob' => $up['blob'],
                ];
            }
        }
    }

    if ($plainText === '' && $images === []) {
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
        $qEmbed = ($i === 0) ? $quoteRef : null;
        // Avoid empty text with no embed
        if (trim($segment) === '' && $imgs === [] && $qEmbed === null) {
            continue;
        }
        $created = gmdate('c', time() + $i); // slight skew so ordering is stable
        // Only stamp fediverseId on the root post (Wafrn merge key).
        $fedi = ($i === 0) ? $fediverseId : null;
        $res = ap_bsky_create_post($pds, $access, $did, $segment, $reply, $imgs, $created, $fedi, $qEmbed);
        if (empty($res['ok']) && str_contains((string) ($res['error'] ?? ''), 'Expired')) {
            $tok = ap_bsky_access_token($ownerUserId, true);
            if (!empty($tok['ok'])) {
                $access = (string) $tok['access'];
                $res = ap_bsky_create_post($pds, $access, $did, $segment, $reply, $imgs, $created, $fedi, $qEmbed);
            }
        }
        if (empty($res['ok'])) {
            return [
                'ok' => false,
                'error' => (string) ($res['error'] ?? 'Bluesky create failed'),
                'uris' => $uris,
                'cids' => $cids,
                'posts' => count($uris),
            ];
        }
        $ref = ['uri' => (string) $res['uri'], 'cid' => (string) $res['cid']];
        $uris[] = $ref['uri'];
        $cids[] = $ref['cid'];
        if ($root === null) {
            // For AP replies, Bluesky thread root stays the remote parent root.
            $root = is_array($replyRef) ? ($replyRef['root'] ?? $ref) : $ref;
        }
        $parent = $ref;
    }

    if ($uris !== [] && is_string($fediverseId) && $fediverseId !== '') {
        ap_bsky_crosspost_save($fediverseId, (string) $uris[0], $cids[0] ?? null, $ownerUserId);
        ap_bsky_post_link_upsert((string) $uris[0], $cids[0] ?? null, $fediverseId, $fediverseId);
    }

    return [
        'ok' => true,
        'uri' => $uris[0] ?? null,
        'cid' => $cids[0] ?? null,
        'uris' => $uris,
        'cids' => $cids,
        'posts' => count($uris),
    ];
}

/**
 * Create app.bsky.feed.repost for a strongRef.
 *
 * @param array{uri:string,cid:string} $subject
 * @return array{ok:bool,error?:string,uri?:string,cid?:string}
 */
function ap_bsky_create_repost(int $ownerUserId, array $subject): array
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
    $put = ap_bsky_xrpc($pds, 'com.atproto.repo.createRecord', 'POST', null, [
        'repo' => $did,
        'collection' => 'app.bsky.feed.repost',
        'record' => [
            '$type' => 'app.bsky.feed.repost',
            'subject' => ['uri' => $uri, 'cid' => $cid],
            'createdAt' => gmdate('c'),
        ],
    ], (string) $tok['access'], 15);
    if (empty($put['ok']) && (($put['status'] ?? 0) === 401)) {
        $tok = ap_bsky_access_token($ownerUserId, true);
        if (!empty($tok['ok'])) {
            $put = ap_bsky_xrpc($pds, 'com.atproto.repo.createRecord', 'POST', null, [
                'repo' => $did,
                'collection' => 'app.bsky.feed.repost',
                'record' => [
                    '$type' => 'app.bsky.feed.repost',
                    'subject' => ['uri' => $uri, 'cid' => $cid],
                    'createdAt' => gmdate('c'),
                ],
            ], (string) $tok['access'], 15);
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
function ap_bsky_repost_object(int $ownerUserId, string $objectId): array
{
    if ($ownerUserId < 1 || !ap_bsky_tab_enabled()) {
        return ['ok' => true, 'skipped' => true];
    }
    $ref = ap_bsky_resolve_strong_ref($objectId, $ownerUserId);
    if ($ref === null) {
        return ['ok' => true, 'skipped' => true, 'error' => 'No Bluesky subject'];
    }
    return ap_bsky_create_repost($ownerUserId, $ref);
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
    $path = str_starts_with($h, 'did:') ? $h : rawurlencode($h);
    return 'https://bsky.app/profile/' . $path;
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
        return $uri;
    }
    // at://did:…/app.bsky.feed.post/RKEY  (use ~ delimiter — # appears in URLs)
    if (preg_match('~^at://([^/]+)/app\.bsky\.feed\.post/([^/\s?]+)~', $uri, $m)) {
        $actor = ($authorHandle !== null && $authorHandle !== '') ? $authorHandle : $m[1];
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
 * Map one Bluesky notification into a VAAK mentions row (no DB write).
 *
 * @return array<string,mixed>|null
 */
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
    $subjectHttps = $reasonSubject !== ''
        ? ap_bsky_https_url_from_at_uri($reasonSubject, null)
        : '';
    $postHttps = ap_bsky_https_url_from_at_uri($uri, $handle !== '' ? $handle : null);

    // Prefer local VAAK note id when the liked/boosted Bluesky post was cross-posted.
    $localNoteId = null;
    if ($reasonSubject !== '') {
        $localNoteId = ap_bsky_local_note_id_for_at_uri($reasonSubject, $ownerUserId);
    }

    switch ($reason) {
        case 'like':
            $activityType = 'Like';
            $objType = 'Like';
            $kind = 'like';
            if ($localNoteId !== null && function_exists('ap_masto_status_by_note_id')) {
                $local = ap_masto_status_by_note_id($localNoteId);
                if (is_array($local) && trim((string) ($local['content_text'] ?? '')) !== '') {
                    $text = (string) $local['content_text'];
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
            if ($localNoteId !== null && function_exists('ap_masto_status_by_note_id')) {
                $local = ap_masto_status_by_note_id($localNoteId);
                if (is_array($local) && trim((string) ($local['content_text'] ?? '')) !== '') {
                    $text = (string) $local['content_text'];
                }
            }
            if ($text === '') {
                $text = 'boosted your Bluesky post';
            }
            break;
        case 'quote':
            $activityType = 'Quote';
            $objType = 'Note';
            $kind = 'quote';
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

    $inReplyTo = null;
    if ($reason === 'reply' && $reasonSubject !== '') {
        $inReplyTo = ap_bsky_https_url_from_at_uri($reasonSubject, null);
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
        'inserted' => (int) ($ing['inserted'] ?? 0),
        'skipped' => (int) ($ing['skipped'] ?? 0),
        'pushed' => (int) ($ing['pushed'] ?? 0),
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
