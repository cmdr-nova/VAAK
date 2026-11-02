<?php
/**
 * Bluesky DMs (chat.bsky.convo.*) for VAAK users with a connected Bluesky
 * account. Off unless VAAK_BSKY_DMS=1.
 *
 * Every call goes to the user's own PDS with
 * `atproto-proxy: did:web:api.bsky.chat#bsky_chat`; the PDS signs it and
 * forwards it to Bluesky's chat service. The usual outbound guard in
 * ap_bsky_xrpc() still applies to the PDS host.
 *
 * Load: VAAK keeps a small cache (bsky_chat_convos, bsky_chat_messages,
 * bsky_chat_state) so pages paint from Postgres. The cache is refreshed by
 * chat.bsky.convo.getLog from a saved cursor, which returns only what
 * changed. A refresh happens only when a signed-in user is looking:
 *   - the DM screen (at most every AP_BSKY_CHAT_SYNC_MIN_SEC per user),
 *   - the unread badge poll while a VAAK tab is open (at most every
 *     AP_BSKY_CHAT_BADGE_SYNC_SEC per user; VAAK_BSKY_DMS_BADGE=0 turns it off).
 * There is no cron job or worker. Failures back off up to 10 minutes.
 *
 * Tables come from api/migrations/20261102_bsky_chat_postgres.sql (applied by
 * deploy/remote/migrate.php). SQLite (tests, dev) creates them on demand.
 *
 * Only direct (1:1) conversations are shown. Group chats are skipped.
 *
 * Loading this file runs nothing and needs no database.
 */
declare(strict_types=1);

const AP_BSKY_CHAT_PROXY = 'did:web:api.bsky.chat#bsky_chat';
/** Shortest gap between two refreshes from the DM screen (seconds). */
const AP_BSKY_CHAT_SYNC_MIN_SEC = 4;
/** Shortest gap between two refreshes from the unread badge poll (seconds). */
const AP_BSKY_CHAT_BADGE_SYNC_SEC = 120;
/** Bluesky's limit for one chat message (graphemes). */
const AP_BSKY_CHAT_TEXT_MAX = 1000;
/** Bluesky's byte limit for one chat message. */
const AP_BSKY_CHAT_BYTES_MAX = 10000;

function ap_bsky_chat_enabled(): bool
{
    return getenv('VAAK_BSKY_DMS') === '1';
}

function ap_bsky_chat_badge_sync_enabled(): bool
{
    return ap_bsky_chat_enabled() && getenv('VAAK_BSKY_DMS_BADGE') !== '0';
}

function ap_bsky_chat_sqlite_ddl(): array
{
    return [
        'CREATE TABLE IF NOT EXISTS bsky_chat_convos (
            owner_user_id INTEGER NOT NULL,
            convo_id TEXT NOT NULL,
            rev TEXT NOT NULL DEFAULT \'\',
            peer_did TEXT NOT NULL DEFAULT \'\',
            peer_handle TEXT NOT NULL DEFAULT \'\',
            peer_name TEXT NOT NULL DEFAULT \'\',
            peer_avatar TEXT NOT NULL DEFAULT \'\',
            peer_chat_disabled INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT \'accepted\',
            muted INTEGER NOT NULL DEFAULT 0,
            unread_count INTEGER NOT NULL DEFAULT 0,
            last_message_id TEXT NOT NULL DEFAULT \'\',
            last_sender_did TEXT NOT NULL DEFAULT \'\',
            last_text TEXT NOT NULL DEFAULT \'\',
            last_at TEXT NOT NULL DEFAULT \'\',
            history_loaded INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (owner_user_id, convo_id)
        )',
        'CREATE INDEX IF NOT EXISTS idx_bsky_chat_convos_owner_last ON bsky_chat_convos (owner_user_id, last_at)',
        'CREATE TABLE IF NOT EXISTS bsky_chat_messages (
            owner_user_id INTEGER NOT NULL,
            convo_id TEXT NOT NULL,
            message_id TEXT NOT NULL,
            rev TEXT NOT NULL DEFAULT \'\',
            sender_did TEXT NOT NULL DEFAULT \'\',
            text TEXT NOT NULL DEFAULT \'\',
            facets_json TEXT,
            embed_json TEXT,
            sent_at TEXT NOT NULL DEFAULT \'\',
            deleted INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (owner_user_id, message_id)
        )',
        'CREATE INDEX IF NOT EXISTS idx_bsky_chat_messages_convo ON bsky_chat_messages (owner_user_id, convo_id, rev)',
        'CREATE TABLE IF NOT EXISTS bsky_chat_state (
            owner_user_id INTEGER PRIMARY KEY,
            did TEXT NOT NULL DEFAULT \'\',
            log_cursor TEXT NOT NULL DEFAULT \'\',
            synced_at INTEGER NOT NULL DEFAULT 0,
            next_sync_at INTEGER NOT NULL DEFAULT 0,
            failures INTEGER NOT NULL DEFAULT 0,
            last_error TEXT NOT NULL DEFAULT \'\',
            scope_ok INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT NOT NULL
        )',
    ];
}

/** True when the chat tables exist. SQLite creates them on demand. */
function ap_bsky_chat_table_ready(?PDO $db = null): bool
{
    if (array_key_exists('ap_bsky_chat_table_ready', $GLOBALS) && is_bool($GLOBALS['ap_bsky_chat_table_ready'])) {
        return $GLOBALS['ap_bsky_chat_table_ready'];
    }
    $ok = false;
    try {
        $db ??= ap_db();
        if (strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite') {
            foreach (ap_bsky_chat_sqlite_ddl() as $sql) {
                $db->exec($sql);
            }
            $ok = true;
        } else {
            $name = $db->query("SELECT to_regclass('public.bsky_chat_state')")->fetchColumn();
            $ok = is_string($name) && $name !== '';
        }
    } catch (Throwable $e) {
        $ok = false;
    }
    $GLOBALS['ap_bsky_chat_table_ready'] = $ok;
    return $ok;
}

/** Ready for this user: switch on, tables present, Bluesky connected. */
function ap_bsky_chat_ready_for(int $ownerUserId): bool
{
    if ($ownerUserId < 1 || !ap_bsky_chat_enabled() || !function_exists('ap_bsky_session_row')) {
        return false;
    }
    if (!ap_bsky_chat_table_ready()) {
        return false;
    }
    return ap_bsky_session_row($ownerUserId) !== null;
}

function ap_bsky_chat_now(): int
{
    return isset($GLOBALS['ap_bsky_chat_now']) ? (int) $GLOBALS['ap_bsky_chat_now'] : time();
}

/** True when an error means the token cannot reach chat (app password without the DM box). */
function ap_bsky_chat_error_is_scope(array $res): bool
{
    $msg = strtolower((string) ($res['error'] ?? ''));
    $json = is_array($res['json'] ?? null) ? $res['json'] : [];
    $code = strtolower((string) ($json['error'] ?? ''));
    return str_contains($msg, 'scope') || str_contains($code, 'scope')
        || ($code === 'invalidtoken' && str_contains($msg, 'bad token'));
}

/**
 * Authenticated chat call through the user's PDS.
 *
 * @return array{ok:bool,error?:string,status?:int,json?:mixed,scope?:bool}
 */
function ap_bsky_chat_xrpc(int $ownerUserId, string $nsid, string $method = 'GET', ?array $query = null, ?array $body = null, int $timeoutSec = 8): array
{
    if (isset($GLOBALS['ap_bsky_chat_transport']) && is_callable($GLOBALS['ap_bsky_chat_transport'])) {
        return ($GLOBALS['ap_bsky_chat_transport'])($ownerUserId, $nsid, $method, $query, $body);
    }
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
    $headers = ['atproto-proxy: ' . AP_BSKY_CHAT_PROXY];
    $res = ap_bsky_xrpc($pds, $nsid, $method, $query, $body, (string) $token['access'], $timeoutSec, $headers);
    if (($res['status'] ?? 0) === 401 && !ap_bsky_chat_error_is_scope($res)) {
        $token = ap_bsky_access_token($ownerUserId, true);
        if (empty($token['ok'])) {
            return ['ok' => false, 'error' => (string) ($token['error'] ?? 'Bluesky session expired')];
        }
        $res = ap_bsky_xrpc($pds, $nsid, $method, $query, $body, (string) $token['access'], $timeoutSec, $headers);
    }
    if (empty($res['ok']) && ap_bsky_chat_error_is_scope($res)) {
        $res['scope'] = true;
    }
    return $res;
}

/** @return array<string,mixed> */
function ap_bsky_chat_state(int $ownerUserId): array
{
    $st = ap_db()->prepare('SELECT * FROM bsky_chat_state WHERE owner_user_id = ? LIMIT 1');
    $st->execute([$ownerUserId]);
    $row = $st->fetch();
    if (is_array($row)) {
        return $row;
    }
    $now = gmdate('c');
    try {
        ap_db()->prepare('INSERT INTO bsky_chat_state (owner_user_id, updated_at) VALUES (?, ?)')->execute([$ownerUserId, $now]);
    } catch (Throwable $e) {
        // Another request created it first.
    }
    return ['owner_user_id' => $ownerUserId, 'did' => '', 'log_cursor' => '', 'synced_at' => 0, 'next_sync_at' => 0, 'failures' => 0, 'last_error' => '', 'scope_ok' => 1];
}

/** False when the last chat call said the token has no DM access. */
function ap_bsky_chat_scope_ok(int $ownerUserId): bool
{
    try {
        return (int) (ap_bsky_chat_state($ownerUserId)['scope_ok'] ?? 1) === 1;
    } catch (Throwable $e) {
        return true;
    }
}

/** Own DID for this user, from the session row. */
function ap_bsky_chat_own_did(int $ownerUserId): string
{
    $session = ap_bsky_session_row($ownerUserId);
    return is_array($session) ? (string) ($session['did'] ?? '') : '';
}

function ap_bsky_chat_is_group(array $convo): bool
{
    $kind = is_array($convo['kind'] ?? null) ? (string) ($convo['kind']['$type'] ?? '') : '';
    return str_contains($kind, 'groupConvo');
}

/** Store one ConvoView. Group convos are skipped. */
function ap_bsky_chat_upsert_convo(int $ownerUserId, array $convo, string $ownDid): void
{
    $convoId = (string) ($convo['id'] ?? '');
    if ($convoId === '' || ap_bsky_chat_is_group($convo)) {
        return;
    }
    $peer = [];
    foreach ((array) ($convo['members'] ?? []) as $member) {
        if (is_array($member) && (string) ($member['did'] ?? '') !== $ownDid) {
            $peer = $member;
            break;
        }
    }
    $last = is_array($convo['lastMessage'] ?? null) ? $convo['lastMessage'] : [];
    $lastType = (string) ($last['$type'] ?? '');
    $lastText = str_contains($lastType, 'deletedMessageView') ? 'Message deleted' : (string) ($last['text'] ?? '');
    if ($lastText === '' && is_array($last['embed'] ?? null)) {
        $lastText = 'Shared a post';
    }
    $avatar = (string) ($peer['avatar'] ?? '');
    if ($avatar !== '' && function_exists('ap_bsky_remote_media_url_allowed') && !ap_bsky_remote_media_url_allowed($avatar)) {
        $avatar = '';
    }
    $status = (string) ($convo['status'] ?? 'accepted');
    $status = $status === 'request' ? 'request' : 'accepted';
    $now = gmdate('c');
    $params = [
        $ownerUserId,
        $convoId,
        (string) ($convo['rev'] ?? ''),
        (string) ($peer['did'] ?? ''),
        (string) ($peer['handle'] ?? ''),
        mb_substr((string) ($peer['displayName'] ?? ''), 0, 200),
        $avatar,
        !empty($peer['chatDisabled']) ? 1 : 0,
        $status,
        !empty($convo['muted']) ? 1 : 0,
        max(0, (int) ($convo['unreadCount'] ?? 0)),
        (string) ($last['id'] ?? ''),
        (string) (($last['sender']['did'] ?? '') ?: ''),
        mb_substr($lastText, 0, 300),
        (string) ($last['sentAt'] ?? ''),
        $now,
    ];
    ap_db()->prepare(
        'INSERT INTO bsky_chat_convos (owner_user_id, convo_id, rev, peer_did, peer_handle, peer_name, peer_avatar, peer_chat_disabled,
            status, muted, unread_count, last_message_id, last_sender_did, last_text, last_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT (owner_user_id, convo_id) DO UPDATE SET
            rev = excluded.rev, peer_did = excluded.peer_did, peer_handle = excluded.peer_handle,
            peer_name = excluded.peer_name, peer_avatar = excluded.peer_avatar,
            peer_chat_disabled = excluded.peer_chat_disabled, status = excluded.status,
            muted = excluded.muted, unread_count = excluded.unread_count,
            last_message_id = excluded.last_message_id, last_sender_did = excluded.last_sender_did,
            last_text = excluded.last_text, last_at = excluded.last_at, updated_at = excluded.updated_at'
    )->execute($params);
}

/** Store one MessageView or DeletedMessageView. */
function ap_bsky_chat_store_message(int $ownerUserId, string $convoId, array $message): void
{
    $id = (string) ($message['id'] ?? '');
    if ($id === '' || $convoId === '') {
        return;
    }
    $type = (string) ($message['$type'] ?? '');
    if (str_contains($type, 'systemMessageView')) {
        return;
    }
    $deleted = str_contains($type, 'deletedMessageView') ? 1 : 0;
    $facets = is_array($message['facets'] ?? null) ? json_encode($message['facets'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    $embed = is_array($message['embed'] ?? null) ? json_encode($message['embed'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    ap_db()->prepare(
        'INSERT INTO bsky_chat_messages (owner_user_id, convo_id, message_id, rev, sender_did, text, facets_json, embed_json, sent_at, deleted)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT (owner_user_id, message_id) DO UPDATE SET
            text = excluded.text, facets_json = excluded.facets_json,
            embed_json = excluded.embed_json, deleted = excluded.deleted'
    )->execute([
        $ownerUserId,
        $convoId,
        $id,
        (string) ($message['rev'] ?? ''),
        (string) ($message['sender']['did'] ?? ''),
        $deleted ? '' : (string) ($message['text'] ?? ''),
        $deleted ? null : (is_string($facets) ? $facets : null),
        $deleted ? null : (is_string($embed) ? $embed : null),
        (string) ($message['sentAt'] ?? ''),
        $deleted,
    ]);
}

function ap_bsky_chat_forget_convo(int $ownerUserId, string $convoId): void
{
    ap_db()->prepare('DELETE FROM bsky_chat_messages WHERE owner_user_id = ? AND convo_id = ?')->execute([$ownerUserId, $convoId]);
    ap_db()->prepare('DELETE FROM bsky_chat_convos WHERE owner_user_id = ? AND convo_id = ?')->execute([$ownerUserId, $convoId]);
}

function ap_bsky_chat_save_state(int $ownerUserId, array $fields): void
{
    $sets = [];
    $vals = [];
    foreach ($fields as $k => $v) {
        if (!in_array($k, ['did', 'log_cursor', 'synced_at', 'next_sync_at', 'failures', 'last_error', 'scope_ok'], true)) {
            continue;
        }
        $sets[] = $k . ' = ?';
        $vals[] = $v;
    }
    $sets[] = 'updated_at = ?';
    $vals[] = gmdate('c');
    $vals[] = $ownerUserId;
    ap_db()->prepare('UPDATE bsky_chat_state SET ' . implode(', ', $sets) . ' WHERE owner_user_id = ?')->execute($vals);
}

/**
 * Bring the cache up to date if the last refresh is older than $minIntervalSec.
 * Claims the slot first so parallel requests (two tabs) do one refresh.
 *
 * @return array{ok:bool,skipped?:bool,error?:string,scope?:bool,changed?:int}
 */
function ap_bsky_chat_sync(int $ownerUserId, int $minIntervalSec = AP_BSKY_CHAT_SYNC_MIN_SEC): array
{
    if (!ap_bsky_chat_ready_for($ownerUserId)) {
        return ['ok' => false, 'error' => 'Bluesky DMs are off'];
    }
    $now = ap_bsky_chat_now();
    $state = ap_bsky_chat_state($ownerUserId);
    if ((int) ($state['next_sync_at'] ?? 0) > $now || ((int) ($state['synced_at'] ?? 0) + $minIntervalSec) > $now) {
        return ['ok' => true, 'skipped' => true];
    }
    $claim = ap_db()->prepare('UPDATE bsky_chat_state SET next_sync_at = ? WHERE owner_user_id = ? AND next_sync_at <= ?');
    $claim->execute([$now + max(2, $minIntervalSec), $ownerUserId, $now]);
    if ($claim->rowCount() < 1) {
        return ['ok' => true, 'skipped' => true];
    }
    $ownDid = ap_bsky_chat_own_did($ownerUserId);
    if ((string) ($state['did'] ?? '') !== '' && (string) $state['did'] !== $ownDid) {
        // A different Bluesky account was connected: start over.
        ap_db()->prepare('DELETE FROM bsky_chat_messages WHERE owner_user_id = ?')->execute([$ownerUserId]);
        ap_db()->prepare('DELETE FROM bsky_chat_convos WHERE owner_user_id = ?')->execute([$ownerUserId]);
        $state['log_cursor'] = '';
    }
    $cursor = (string) ($state['log_cursor'] ?? '');
    $changed = 0;
    $fail = static function (array $res) use ($ownerUserId, $state, $now): array {
        $failures = (int) ($state['failures'] ?? 0) + 1;
        $scope = !empty($res['scope']);
        ap_bsky_chat_save_state($ownerUserId, [
            'failures' => $failures,
            'next_sync_at' => $now + ($scope ? 600 : min(600, 15 * (2 ** min(5, $failures - 1)))),
            'last_error' => mb_substr((string) ($res['error'] ?? 'failed'), 0, 300),
            'scope_ok' => $scope ? 0 : 1,
        ]);
        return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Bluesky chat failed'), 'scope' => $scope];
    };

    if ($cursor === '') {
        // First run: the conversation list, then remember the newest rev.
        $pageCursor = null;
        $maxRev = '';
        for ($page = 0; $page < 3; $page++) {
            $q = ['limit' => 100];
            if ($pageCursor !== null) {
                $q['cursor'] = $pageCursor;
            }
            $res = ap_bsky_chat_xrpc($ownerUserId, 'chat.bsky.convo.listConvos', 'GET', $q);
            if (empty($res['ok'])) {
                return $fail($res);
            }
            $json = is_array($res['json'] ?? null) ? $res['json'] : [];
            foreach ((array) ($json['convos'] ?? []) as $convo) {
                if (!is_array($convo)) {
                    continue;
                }
                ap_bsky_chat_upsert_convo($ownerUserId, $convo, $ownDid);
                $changed++;
                $rev = (string) ($convo['rev'] ?? '');
                if (strcmp($rev, $maxRev) > 0) {
                    $maxRev = $rev;
                }
            }
            $pageCursor = isset($json['cursor']) && is_string($json['cursor']) && $json['cursor'] !== '' ? $json['cursor'] : null;
            if ($pageCursor === null) {
                break;
            }
        }
        $cursor = $maxRev;
    }

    $dirty = [];
    for ($page = 0; $page < 5 && $cursor !== ''; $page++) {
        $res = ap_bsky_chat_xrpc($ownerUserId, 'chat.bsky.convo.getLog', 'GET', ['cursor' => $cursor]);
        if (empty($res['ok'])) {
            return $fail($res);
        }
        $json = is_array($res['json'] ?? null) ? $res['json'] : [];
        $logs = is_array($json['logs'] ?? null) ? $json['logs'] : [];
        $next = $cursor;
        foreach ($logs as $log) {
            if (!is_array($log)) {
                continue;
            }
            $type = (string) ($log['$type'] ?? '');
            $convoId = (string) ($log['convoId'] ?? '');
            $rev = (string) ($log['rev'] ?? '');
            if (strcmp($rev, $next) > 0) {
                $next = $rev;
            }
            if ($convoId === '') {
                continue;
            }
            if (str_ends_with($type, '#logLeaveConvo')) {
                ap_bsky_chat_forget_convo($ownerUserId, $convoId);
                unset($dirty[$convoId]);
                $changed++;
                continue;
            }
            if ((str_ends_with($type, '#logCreateMessage') || str_ends_with($type, '#logDeleteMessage'))
                && is_array($log['message'] ?? null)) {
                ap_bsky_chat_store_message($ownerUserId, $convoId, $log['message']);
            }
            $dirty[$convoId] = true;
            $changed++;
        }
        $serverCursor = isset($json['cursor']) && is_string($json['cursor']) ? $json['cursor'] : '';
        if ($serverCursor !== '' && strcmp($serverCursor, $next) > 0) {
            $next = $serverCursor;
        }
        if ($logs === [] || $next === $cursor) {
            break;
        }
        $cursor = $next;
    }

    // One getConvo per touched conversation refreshes unread, status and preview.
    foreach (array_slice(array_keys($dirty), 0, 15) as $convoId) {
        $res = ap_bsky_chat_xrpc($ownerUserId, 'chat.bsky.convo.getConvo', 'GET', ['convoId' => $convoId]);
        if (!empty($res['ok']) && is_array($res['json']['convo'] ?? null)) {
            ap_bsky_chat_upsert_convo($ownerUserId, $res['json']['convo'], $ownDid);
        } elseif ((int) ($res['status'] ?? 0) === 400 || (int) ($res['status'] ?? 0) === 404) {
            ap_bsky_chat_forget_convo($ownerUserId, $convoId);
        }
    }

    ap_bsky_chat_save_state($ownerUserId, [
        'did' => $ownDid,
        'log_cursor' => $cursor,
        'synced_at' => $now,
        'next_sync_at' => $now + max(2, $minIntervalSec),
        'failures' => 0,
        'last_error' => '',
        'scope_ok' => 1,
    ]);
    return ['ok' => true, 'changed' => $changed];
}

/** @return list<array<string,mixed>> newest activity first */
function ap_bsky_chat_conversations(int $ownerUserId, int $limit = 60): array
{
    if (!ap_bsky_chat_ready_for($ownerUserId)) {
        return [];
    }
    $st = ap_db()->prepare(
        "SELECT * FROM bsky_chat_convos WHERE owner_user_id = ? AND peer_did <> ''
         ORDER BY last_at DESC LIMIT ?"
    );
    $st->execute([$ownerUserId, max(1, min(200, $limit))]);
    return $st->fetchAll();
}

/** @return array<string,mixed>|null */
function ap_bsky_chat_convo(int $ownerUserId, string $convoId): ?array
{
    if ($convoId === '' || !ap_bsky_chat_ready_for($ownerUserId)) {
        return null;
    }
    $st = ap_db()->prepare('SELECT * FROM bsky_chat_convos WHERE owner_user_id = ? AND convo_id = ? LIMIT 1');
    $st->execute([$ownerUserId, $convoId]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

/** Unread messages across Bluesky conversations (cache only, no network). */
function ap_bsky_chat_unread_count(int $ownerUserId): int
{
    if (!ap_bsky_chat_ready_for($ownerUserId)) {
        return 0;
    }
    try {
        $st = ap_db()->prepare('SELECT COALESCE(SUM(unread_count), 0) FROM bsky_chat_convos WHERE owner_user_id = ? AND muted = 0');
        $st->execute([$ownerUserId]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Newest unread rev, so the page can tell that something new arrived. */
function ap_bsky_chat_unread_tip(int $ownerUserId): string
{
    if (!ap_bsky_chat_ready_for($ownerUserId)) {
        return '';
    }
    try {
        $st = ap_db()->prepare('SELECT MAX(rev) FROM bsky_chat_convos WHERE owner_user_id = ? AND unread_count > 0 AND muted = 0');
        $st->execute([$ownerUserId]);
        return (string) ($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Messages for one conversation, oldest first. The first open of a
 * conversation loads its recent history from Bluesky once.
 *
 * @return list<array<string,mixed>>
 */
function ap_bsky_chat_messages(int $ownerUserId, string $convoId, int $limit = 200, string $afterRev = ''): array
{
    $convo = ap_bsky_chat_convo($ownerUserId, $convoId);
    if ($convo === null) {
        return [];
    }
    if ($afterRev === '' && (int) ($convo['history_loaded'] ?? 0) !== 1) {
        $res = ap_bsky_chat_xrpc($ownerUserId, 'chat.bsky.convo.getMessages', 'GET', ['convoId' => $convoId, 'limit' => 100]);
        if (!empty($res['ok'])) {
            foreach ((array) ($res['json']['messages'] ?? []) as $message) {
                if (is_array($message)) {
                    ap_bsky_chat_store_message($ownerUserId, $convoId, $message);
                }
            }
            ap_db()->prepare('UPDATE bsky_chat_convos SET history_loaded = 1 WHERE owner_user_id = ? AND convo_id = ?')
                ->execute([$ownerUserId, $convoId]);
        }
    }
    $limit = max(1, min(200, $limit));
    if ($afterRev !== '') {
        $st = ap_db()->prepare(
            'SELECT * FROM bsky_chat_messages WHERE owner_user_id = ? AND convo_id = ? AND rev > ? ORDER BY rev ASC LIMIT ?'
        );
        $st->execute([$ownerUserId, $convoId, $afterRev, $limit]);
        return $st->fetchAll();
    }
    // Display order is send time: a message deleted later keeps its place.
    $st = ap_db()->prepare(
        'SELECT * FROM bsky_chat_messages WHERE owner_user_id = ? AND convo_id = ? ORDER BY sent_at DESC, rev DESC LIMIT ?'
    );
    $st->execute([$ownerUserId, $convoId, $limit]);
    return array_reverse($st->fetchAll());
}

/** Tell Bluesky the conversation is read. Skips the call when nothing is unread. */
function ap_bsky_chat_mark_read(int $ownerUserId, string $convoId): void
{
    $convo = ap_bsky_chat_convo($ownerUserId, $convoId);
    if ($convo === null || (int) ($convo['unread_count'] ?? 0) < 1) {
        return;
    }
    $res = ap_bsky_chat_xrpc($ownerUserId, 'chat.bsky.convo.updateRead', 'POST', null, ['convoId' => $convoId]);
    if (!empty($res['ok']) && is_array($res['json']['convo'] ?? null)) {
        ap_bsky_chat_upsert_convo($ownerUserId, $res['json']['convo'], ap_bsky_chat_own_did($ownerUserId));
    } else {
        ap_db()->prepare('UPDATE bsky_chat_convos SET unread_count = 0 WHERE owner_user_id = ? AND convo_id = ?')
            ->execute([$ownerUserId, $convoId]);
    }
}

/** Length the way Bluesky counts it (graphemes), with a fallback. */
function ap_bsky_chat_text_length(string $text): int
{
    if (function_exists('grapheme_strlen')) {
        $n = grapheme_strlen($text);
        if (is_int($n)) {
            return $n;
        }
    }
    return mb_strlen($text);
}

/**
 * Turn what was typed in "To" into a DID: did:plc:…, @handle, handle, or a
 * bsky.app profile link. Handles resolve through the user's PDS.
 */
function ap_bsky_chat_resolve_recipient(int $ownerUserId, string $to): ?string
{
    $to = trim($to);
    if (str_starts_with($to, 'bsky:')) {
        $to = substr($to, 5);
    }
    if (preg_match('~^https://bsky\.app/profile/([^/?#]+)~i', $to, $m)) {
        $to = rawurldecode($m[1]);
    }
    $to = ltrim($to, '@');
    if (preg_match('/^did:(plc|web):[A-Za-z0-9._:%-]{3,}$/', $to)) {
        return $to;
    }
    if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?)+$/', $to)) {
        return null;
    }
    $session = ap_bsky_session_row($ownerUserId);
    if ($session === null) {
        return null;
    }
    $pds = rtrim((string) ($session['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/');
    $res = isset($GLOBALS['ap_bsky_chat_transport']) && is_callable($GLOBALS['ap_bsky_chat_transport'])
        ? ($GLOBALS['ap_bsky_chat_transport'])($ownerUserId, 'com.atproto.identity.resolveHandle', 'GET', ['handle' => strtolower($to)], null)
        : ap_bsky_xrpc($pds, 'com.atproto.identity.resolveHandle', 'GET', ['handle' => strtolower($to)], null, null, 6);
    $did = !empty($res['ok']) ? (string) ($res['json']['did'] ?? '') : '';
    return str_starts_with($did, 'did:') ? $did : null;
}

/** Plain-language reason for a failed send. */
function ap_bsky_chat_send_error(array $res): string
{
    if (!empty($res['scope'])) {
        return 'Your Bluesky connection can’t reach DMs. Reconnect Bluesky in ATmosphere with your account password, or with an app password that has “Allow access to your direct messages” ticked.';
    }
    $msg = (string) ($res['error'] ?? '');
    $lower = strtolower($msg);
    if (str_contains($lower, 'disabled') || str_contains($lower, 'not allowed') || str_contains($lower, 'recipient')
        || str_contains($lower, 'block')) {
        return 'This person isn’t accepting messages from you on Bluesky.';
    }
    if (!empty($res['rate_limited'])) {
        return 'Bluesky is rate limiting messages right now. Try again in a few minutes.';
    }
    return $msg !== '' ? 'Bluesky said: ' . $msg : 'Bluesky did not accept the message.';
}

/**
 * Send a Bluesky DM. Pass an existing conversation id, or a recipient
 * (handle or DID) to find or start one.
 *
 * @return array{ok:bool,error?:string,convo_id?:string,message_id?:string}
 */
function ap_bsky_chat_send(int $ownerUserId, string $convoId, string $recipient, string $text): array
{
    if (!ap_bsky_chat_ready_for($ownerUserId)) {
        return ['ok' => false, 'error' => 'Connect a Bluesky account in ATmosphere to send Bluesky DMs.'];
    }
    $text = trim(function_exists('ap_fix_utf8') ? ap_fix_utf8($text) : $text);
    if ($text === '') {
        return ['ok' => false, 'error' => 'Type a message first. Bluesky DMs are text only.'];
    }
    if (ap_bsky_chat_text_length($text) > AP_BSKY_CHAT_TEXT_MAX || strlen($text) > AP_BSKY_CHAT_BYTES_MAX) {
        return ['ok' => false, 'error' => 'Bluesky DMs are limited to ' . AP_BSKY_CHAT_TEXT_MAX . ' characters.'];
    }
    $ownDid = ap_bsky_chat_own_did($ownerUserId);
    if ($convoId === '') {
        $did = ap_bsky_chat_resolve_recipient($ownerUserId, $recipient);
        if ($did === null) {
            return ['ok' => false, 'error' => 'Could not find that Bluesky account. Check the handle.'];
        }
        if ($did === $ownDid) {
            return ['ok' => false, 'error' => 'You can’t message yourself.'];
        }
        $res = ap_bsky_chat_xrpc($ownerUserId, 'chat.bsky.convo.getConvoForMembers', 'GET', ['members' => $did]);
        if (empty($res['ok']) || !is_array($res['json']['convo'] ?? null)) {
            if (!empty($res['scope'])) {
                ap_bsky_chat_state($ownerUserId);
                ap_bsky_chat_save_state($ownerUserId, ['scope_ok' => 0]);
            }
            return ['ok' => false, 'error' => ap_bsky_chat_send_error($res)];
        }
        ap_bsky_chat_upsert_convo($ownerUserId, $res['json']['convo'], $ownDid);
        $convoId = (string) ($res['json']['convo']['id'] ?? '');
    } elseif (ap_bsky_chat_convo($ownerUserId, $convoId) === null) {
        return ['ok' => false, 'error' => 'That conversation is not available.'];
    }
    $message = ['text' => $text];
    if (function_exists('ap_bsky_build_facets')) {
        $facets = ap_bsky_build_facets($text);
        if ($facets !== []) {
            $message['facets'] = $facets;
        }
    }
    $res = ap_bsky_chat_xrpc($ownerUserId, 'chat.bsky.convo.sendMessage', 'POST', null, ['convoId' => $convoId, 'message' => $message]);
    if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
        return ['ok' => false, 'error' => ap_bsky_chat_send_error($res), 'convo_id' => $convoId];
    }
    $sent = $res['json'];
    ap_bsky_chat_store_message($ownerUserId, $convoId, $sent);
    ap_db()->prepare(
        'UPDATE bsky_chat_convos SET last_message_id = ?, last_sender_did = ?, last_text = ?, last_at = ?, status = ?, updated_at = ?
         WHERE owner_user_id = ? AND convo_id = ?'
    )->execute([
        (string) ($sent['id'] ?? ''),
        $ownDid,
        mb_substr($text, 0, 300),
        (string) ($sent['sentAt'] ?? gmdate('c')),
        'accepted',
        gmdate('c'),
        $ownerUserId,
        $convoId,
    ]);
    return ['ok' => true, 'convo_id' => $convoId, 'message_id' => (string) ($sent['id'] ?? ''), 'rev' => (string) ($sent['rev'] ?? '')];
}

/**
 * Message text as safe HTML: facets (links, mentions, tags) become links,
 * everything else is escaped. Bare URLs are linked when a message has no facets.
 */
function ap_bsky_chat_text_html(string $text, array $facets = []): string
{
    if ($text === '') {
        return '';
    }
    $esc = static fn(string $s): string => nl2br(htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $link = static fn(string $href, string $label): string => '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
        . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
    $ranges = [];
    foreach ($facets as $facet) {
        if (!is_array($facet) || !is_array($facet['index'] ?? null)) {
            continue;
        }
        $start = (int) ($facet['index']['byteStart'] ?? -1);
        $end = (int) ($facet['index']['byteEnd'] ?? -1);
        if ($start < 0 || $end <= $start || $end > strlen($text)) {
            continue;
        }
        $feature = is_array($facet['features'][0] ?? null) ? $facet['features'][0] : [];
        $type = (string) ($feature['$type'] ?? '');
        $href = '';
        if (str_ends_with($type, '#link') && preg_match('~^https?://~i', (string) ($feature['uri'] ?? ''))) {
            $href = (string) $feature['uri'];
        } elseif (str_ends_with($type, '#tag') && (string) ($feature['tag'] ?? '') !== '') {
            $href = 'https://bsky.app/hashtag/' . rawurlencode((string) $feature['tag']);
        } elseif (str_ends_with($type, '#mention') && str_starts_with((string) ($feature['did'] ?? ''), 'did:')) {
            $href = 'https://bsky.app/profile/' . rawurlencode((string) $feature['did']);
        }
        if ($href !== '') {
            $ranges[] = [$start, $end, $href];
        }
    }
    if ($ranges === []) {
        $parts = preg_split('~(https?://[^\s<>"]+)~i', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';
        foreach ($parts as $i => $part) {
            $html .= ($i % 2 === 1) ? $link($part, $part) : $esc($part);
        }
        return $html;
    }
    usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    $html = '';
    $cursor = 0;
    foreach ($ranges as [$start, $end, $href]) {
        if ($start < $cursor) {
            continue;
        }
        $html .= $esc(substr($text, $cursor, $start - $cursor)) . $link($href, substr($text, $start, $end - $start));
        $cursor = $end;
    }
    return $html . $esc(substr($text, $cursor));
}

/** bsky.app link for a shared post (app.bsky.embed.record), or '' when none. */
function ap_bsky_chat_embed_url(?string $embedJson): string
{
    $embed = is_string($embedJson) && $embedJson !== '' ? json_decode($embedJson, true) : null;
    if (!is_array($embed)) {
        return '';
    }
    $uri = (string) ($embed['record']['uri'] ?? ($embed['record']['record']['uri'] ?? ''));
    if (preg_match('~^at://(did:[a-z0-9]+:[A-Za-z0-9._:%-]+)/app\.bsky\.feed\.post/([A-Za-z0-9]+)$~', $uri, $m)) {
        return 'https://bsky.app/profile/' . rawurlencode($m[1]) . '/post/' . rawurlencode($m[2]);
    }
    return '';
}

/**
 * Bluesky people for the New message picker: past Bluesky conversations that
 * match, plus the typed handle itself when it looks like one. Cache only.
 *
 * @return list<array{actor_id:string,name:string,handle:string,avatar_url:string,network:string}>
 */
function ap_bsky_chat_people_search(int $ownerUserId, string $q, int $limit = 4): array
{
    $q = strtolower(trim(ltrim(trim($q), '@')));
    if (str_starts_with($q, 'bsky:')) {
        $q = substr($q, 5);
    }
    if ($q === '' || strlen($q) > 120 || !ap_bsky_chat_ready_for($ownerUserId)) {
        return [];
    }
    $out = [];
    $seen = [];
    try {
        // '!' as the LIKE escape: a backslash before a quote confuses PDO's placeholder scan on pgsql.
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $st = ap_db()->prepare(
            "SELECT peer_did, peer_handle, peer_name, peer_avatar FROM bsky_chat_convos
             WHERE owner_user_id = ? AND peer_did <> '' AND (LOWER(peer_handle) LIKE ? ESCAPE '!' OR LOWER(peer_name) LIKE ? ESCAPE '!')
             ORDER BY last_at DESC LIMIT ?"
        );
        $st->execute([$ownerUserId, $like, $like, max(1, min(8, $limit))]);
        foreach ($st->fetchAll() as $row) {
            $handle = (string) ($row['peer_handle'] ?? '');
            $seen[strtolower($handle)] = true;
            $out[] = [
                'actor_id' => 'bsky:' . (string) $row['peer_did'],
                'name' => (string) ($row['peer_name'] ?? '') !== '' ? (string) $row['peer_name'] : $handle,
                'handle' => '@' . $handle,
                'avatar_url' => (string) ($row['peer_avatar'] ?? ''),
                'network' => 'bluesky',
            ];
        }
    } catch (Throwable $e) {
        // The typed-handle suggestion below still works.
    }
    if (count($out) < $limit && !isset($seen[$q]) && !str_contains($q, '@')
        && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?)+$/', $q)) {
        $out[] = ['actor_id' => 'bsky:' . $q, 'name' => '@' . $q, 'handle' => '@' . $q, 'avatar_url' => '', 'network' => 'bluesky'];
    }
    return $out;
}

/** True when what was typed in "To" should go to Bluesky instead of the fediverse. */
function ap_bsky_chat_recipient_is_bluesky(string $to): bool
{
    $to = trim($to);
    if (str_starts_with($to, 'bsky:') || str_starts_with($to, 'did:plc:') || str_starts_with($to, 'did:web:')
        || preg_match('#^https://bsky\.app/profile/#i', $to)) {
        return true;
    }
    // @alice.bsky.social has one @ and a dot: not a fediverse address (@user@server).
    $bare = ltrim($to, '@');
    return $bare !== '' && !str_contains($bare, '@') && !str_contains($bare, '/') && str_contains($bare, '.');
}
