<?php
/**
 * Mastodon-compatible ActivityPub relays.
 * Subscribe by Follow(as:Public) to the relay inbox; wait for Accept/Reject.
 */
declare(strict_types=1);
// Refuse direct HTTP hits (include/require only)
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Library only';
    exit;
}


const AP_RELAY_PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';

/** @return list<array<string,mixed>> */
function ap_relays_list(): array
{
    try {
        $st = ap_db()->query('SELECT * FROM ap_relays ORDER BY id ASC');
        return $st ? ($st->fetchAll() ?: []) : [];
    } catch (Throwable $e) {
        error_log('[ap-relays] list: ' . $e->getMessage());
        return [];
    }
}

/** @return list<string> */
function ap_relays_accepted_inboxes(): array
{
    try {
        $st = ap_db()->query("SELECT inbox_url FROM ap_relays WHERE state = 'accepted'");
        $out = [];
        foreach ($st ? ($st->fetchAll() ?: []) : [] as $row) {
            $u = (string) ($row['inbox_url'] ?? '');
            if (str_starts_with($u, 'https://')) {
                $out[] = $u;
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Hostnames of accepted relays (inbox URL host).
 * Relays re-sign forwarded Creates/Announces with their own key while leaving
 * activity.actor as the original author — that is expected, not spoofing.
 *
 * @return list<string> lowercase hosts
 */
function ap_relays_accepted_hosts(): array
{
    static $memo = null;
    static $memoAt = 0;
    if (is_array($memo) && (time() - $memoAt) < 30) {
        return $memo;
    }
    $hosts = [];
    foreach (ap_relays_accepted_inboxes() as $inbox) {
        $h = parse_url($inbox, PHP_URL_HOST);
        if (is_string($h) && $h !== '') {
            $hosts[strtolower($h)] = true;
        }
    }
    $memo = array_keys($hosts);
    $memoAt = time();
    return $memo;
}

/** True when keyId belongs to an accepted relay actor/inbox host. */
function ap_relay_is_accepted_key(string $keyId): bool
{
    $host = parse_url($keyId, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return false;
    }
    $host = strtolower($host);
    return in_array($host, ap_relays_accepted_hosts(), true);
}

function ap_relay_normalize_inbox_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    // Allow pasting actor URL → try endpoints.sharedInbox / inbox later in add()
    if (!str_starts_with($url, 'https://')) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }
    $path = (string) ($parts['path'] ?? '');
    $host = strtolower((string) $parts['host']);
    // Strip fragment / query
    $norm = 'https://' . $host . ($path !== '' ? $path : '/');
    // Collapse trailing slash except root
    if ($norm !== 'https://' . $host . '/' && str_ends_with($norm, '/')) {
        $norm = rtrim($norm, '/');
    }
    return $norm;
}

/**
 * If URL looks like an actor, resolve sharedInbox/inbox.
 */
function ap_relay_resolve_inbox_url(string $url): ?string
{
    $norm = ap_relay_normalize_inbox_url($url);
    if ($norm === null) {
        return null;
    }
    // Already an inbox path — use as-is
    $path = (string) (parse_url($norm, PHP_URL_PATH) ?? '');
    if (preg_match('#/(inbox|ap/inbox)/?$#i', $path)) {
        return $norm;
    }
    // Try fetch as actor
    if (!function_exists('ap_fetch_actor_doc')) {
        return $norm; // fall through — admin pasted something we'll POST to
    }
    $doc = ap_fetch_actor_doc($norm);
    if (!is_array($doc)) {
        return $norm;
    }
    if (!empty($doc['endpoints']['sharedInbox']) && is_string($doc['endpoints']['sharedInbox'])) {
        $resolved = ap_relay_normalize_inbox_url($doc['endpoints']['sharedInbox']);
        if ($resolved !== null) {
            return $resolved;
        }
    }
    if (!empty($doc['inbox']) && is_string($doc['inbox'])) {
        $resolved = ap_relay_normalize_inbox_url($doc['inbox']);
        if ($resolved !== null) {
            return $resolved;
        }
    }
    return $norm;
}

/**
 * @return array{ok:bool,id?:int,error?:string,inbox_url?:string}
 */
function ap_relay_add(string $inboxUrl): array
{
    $resolved = ap_relay_resolve_inbox_url($inboxUrl);
    if ($resolved === null) {
        return ['ok' => false, 'error' => 'Inbox URL must be https://…'];
    }
    $now = function_exists('ap_db_now') ? ap_db_now() : gmdate('c');
    try {
        $db = ap_db();
        $existing = $db->prepare('SELECT id, inbox_url FROM ap_relays WHERE inbox_url = ? OR inbox_url = ?');
        $existing->execute([$resolved, $resolved . '/']);
        $row = $existing->fetch();
        if (is_array($row)) {
            return ['ok' => true, 'id' => (int) $row['id'], 'inbox_url' => (string) $row['inbox_url']];
        }
        $db->prepare(
            'INSERT INTO ap_relays (inbox_url, state, follow_activity_id, created_at, updated_at)
             VALUES (?, \'idle\', NULL, ?, ?)'
        )->execute([$resolved, $now, $now]);
        return ['ok' => true, 'id' => ap_db_last_insert_id('ap_relays', 'id', $db), 'inbox_url' => $resolved];
    } catch (Throwable $e) {
        error_log('[ap-relays] add: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save relay'];
    }
}

function ap_relay_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    try {
        $st = ap_db()->prepare('SELECT * FROM ap_relays WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return array{ok:bool,error?:string,state?:string}
 */
function ap_relay_enable(int $id): array
{
    $row = ap_relay_by_id($id);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Relay not found'];
    }
    $inbox = (string) ($row['inbox_url'] ?? '');
    if (!str_starts_with($inbox, 'https://')) {
        return ['ok' => false, 'error' => 'Invalid inbox URL'];
    }
    if (($row['state'] ?? '') === 'accepted') {
        return ['ok' => true, 'state' => 'accepted'];
    }
    if (!defined('LOCAL_ACTOR') || !defined('LOCAL_KEY_ID') || !defined('LOCAL_PRIV')) {
        return ['ok' => false, 'error' => 'Local actor keys not available'];
    }
    if (!function_exists('ap_deliver_signed_json')) {
        return ['ok' => false, 'error' => 'Delivery helper missing'];
    }

    $activityId = rtrim(LOCAL_ACTOR, '/') . '/relay-follows/' . bin2hex(random_bytes(12));
    $follow = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $activityId,
        'type' => 'Follow',
        'actor' => LOCAL_ACTOR,
        'object' => AP_RELAY_PUBLIC,
    ];
    $now = function_exists('ap_db_now') ? ap_db_now() : gmdate('c');
    try {
        ap_db()->prepare(
            'UPDATE ap_relays SET state = ?, follow_activity_id = ?, updated_at = ? WHERE id = ?'
        )->execute(['pending', $activityId, $now, $id]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update relay state'];
    }

    $ok = ap_deliver_signed_json($inbox, $follow, LOCAL_KEY_ID, LOCAL_PRIV, 12.0);
    if (!$ok) {
        // Keep pending — Accept may still arrive if deliver raced; allow retry
        if (function_exists('ap_log')) {
            ap_log('relay_enable_deliver_fail inbox=' . $inbox);
        }
        return [
            'ok' => true,
            'state' => 'pending',
            'error' => 'Follow sent state=pending, but delivery did not get a clear 2xx — check relay / retry Enable',
        ];
    }
    if (function_exists('ap_log')) {
        ap_log('relay_enable pending inbox=' . $inbox . ' follow=' . $activityId);
    }
    return ['ok' => true, 'state' => 'pending'];
}

/**
 * @return array{ok:bool,error?:string,state?:string}
 */
function ap_relay_disable(int $id): array
{
    $row = ap_relay_by_id($id);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Relay not found'];
    }
    $inbox = (string) ($row['inbox_url'] ?? '');
    $followId = (string) ($row['follow_activity_id'] ?? '');
    $state = (string) ($row['state'] ?? 'idle');

    if (in_array($state, ['pending', 'accepted'], true)
        && $followId !== ''
        && str_starts_with($inbox, 'https://')
        && defined('LOCAL_ACTOR')
        && defined('LOCAL_KEY_ID')
        && defined('LOCAL_PRIV')
        && function_exists('ap_deliver_signed_json')
    ) {
        $undoId = rtrim(LOCAL_ACTOR, '/') . '/relay-undos/' . bin2hex(random_bytes(10));
        $undo = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $undoId,
            'type' => 'Undo',
            'actor' => LOCAL_ACTOR,
            'object' => [
                'id' => $followId,
                'type' => 'Follow',
                'actor' => LOCAL_ACTOR,
                'object' => AP_RELAY_PUBLIC,
            ],
        ];
        ap_deliver_signed_json($inbox, $undo, LOCAL_KEY_ID, LOCAL_PRIV, 12.0);
    }

    $now = function_exists('ap_db_now') ? ap_db_now() : gmdate('c');
    try {
        ap_db()->prepare(
            'UPDATE ap_relays SET state = ?, follow_activity_id = NULL, updated_at = ? WHERE id = ?'
        )->execute(['idle', $now, $id]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not disable relay'];
    }
    if (function_exists('ap_log')) {
        ap_log('relay_disable inbox=' . $inbox);
    }
    return ['ok' => true, 'state' => 'idle'];
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_relay_remove(int $id): array
{
    $row = ap_relay_by_id($id);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Relay not found'];
    }
    $state = (string) ($row['state'] ?? 'idle');
    if (in_array($state, ['pending', 'accepted'], true)) {
        ap_relay_disable($id);
    }
    try {
        ap_db()->prepare('DELETE FROM ap_relays WHERE id = ?')->execute([$id]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not remove relay'];
    }
    return ['ok' => true];
}

function ap_relay_is_public_object(mixed $object): bool
{
    $id = '';
    if (is_string($object)) {
        $id = $object;
    } elseif (is_array($object)) {
        $id = (string) ($object['id'] ?? '');
    }
    $id = rtrim(trim($id), '/');
    return $id === AP_RELAY_PUBLIC
        || $id === 'as:Public'
        || $id === 'Public'
        || str_ends_with($id, '#Public');
}

/**
 * Extract Follow activity id from Accept/Reject object (string or embedded).
 */
function ap_relay_follow_id_from_accept_object(mixed $object): ?string
{
    if (is_string($object) && str_starts_with($object, 'https://')) {
        return rtrim($object, '/');
    }
    if (!is_array($object)) {
        return null;
    }
    $type = (string) ($object['type'] ?? '');
    if ($type !== 'Follow') {
        return null;
    }
    $id = (string) ($object['id'] ?? '');
    if ($id === '' || !str_starts_with($id, 'https://')) {
        return null;
    }
    return $id;
}

/**
 * Handle inbound Accept of our relay Follow.
 */
function ap_relay_handle_accept(array $activity): bool
{
    $followId = ap_relay_follow_id_from_accept_object($activity['object'] ?? null);
    if ($followId === null) {
        return false;
    }
    // Must be our Follow of Public (or at least an id we stored)
    $inner = $activity['object'] ?? null;
    if (is_array($inner) && !ap_relay_is_public_object($inner['object'] ?? null)) {
        // Still allow match by stored follow_activity_id only
    }
    try {
        $st = ap_db()->prepare(
            'SELECT id FROM ap_relays WHERE follow_activity_id = ? OR follow_activity_id = ? LIMIT 1'
        );
        $st->execute([$followId, $followId . '/']);
        $row = $st->fetch();
        if (!is_array($row)) {
            return false;
        }
        $now = function_exists('ap_db_now') ? ap_db_now() : gmdate('c');
        ap_db()->prepare(
            'UPDATE ap_relays SET state = ?, updated_at = ? WHERE id = ?'
        )->execute(['accepted', $now, (int) $row['id']]);
        if (function_exists('ap_log')) {
            ap_log('relay_accepted id=' . (int) $row['id'] . ' follow=' . $followId);
        }
        return true;
    } catch (Throwable $e) {
        error_log('[ap-relays] accept: ' . $e->getMessage());
        return false;
    }
}

function ap_relay_handle_reject(array $activity): bool
{
    $followId = ap_relay_follow_id_from_accept_object($activity['object'] ?? null);
    if ($followId === null) {
        return false;
    }
    try {
        $st = ap_db()->prepare(
            'SELECT id FROM ap_relays WHERE follow_activity_id = ? OR follow_activity_id = ? LIMIT 1'
        );
        $st->execute([$followId, $followId . '/']);
        $row = $st->fetch();
        if (!is_array($row)) {
            return false;
        }
        $now = function_exists('ap_db_now') ? ap_db_now() : gmdate('c');
        ap_db()->prepare(
            'UPDATE ap_relays SET state = ?, updated_at = ? WHERE id = ?'
        )->execute(['rejected', $now, (int) $row['id']]);
        if (function_exists('ap_log')) {
            ap_log('relay_rejected id=' . (int) $row['id'] . ' follow=' . $followId);
        }
        return true;
    } catch (Throwable $e) {
        error_log('[ap-relays] reject: ' . $e->getMessage());
        return false;
    }
}
