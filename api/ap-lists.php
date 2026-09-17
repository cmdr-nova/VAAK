<?php
/**
 * Private Mastodon-style Lists (Library → Lists).
 * Separate from public Collections / starter packs (ap-collections.php).
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


const AP_LIST_MAX = 50;
const AP_LIST_TITLE_MAX = 256;
/** Soft UI/API cap per list (Mastodon has no hard member limit). */
const AP_LIST_MAX_MEMBERS = 500;

/**
 * @return list<array<string,mixed>>
 */
function ap_list_owner_user_id(?int $ownerUserId = null): int
{
    $ownerUserId = $ownerUserId ?? ap_db_default_owner_user_id();
    return $ownerUserId > 0 ? $ownerUserId : 0;
}

function ap_lists_all(?int $ownerUserId = null): array
{
    $ownerUserId = ap_list_owner_user_id($ownerUserId);
    if ($ownerUserId < 1) {
        return [];
    }
    try {
        $st = ap_db()->prepare(
            'SELECT l.*,
               (SELECT COUNT(*) FROM masto_list_accounts a WHERE a.list_id = l.id) AS member_count
             FROM masto_lists l
             WHERE l.owner_user_id = ?
             ORDER BY l.updated_at DESC, l.id DESC'
        );
        $st->execute([$ownerUserId]);
        $rows = $st->fetchAll();
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('[ap-lists] list_all: ' . $e->getMessage());
        return [];
    }
}

function ap_list_by_id(int $id, ?int $ownerUserId = null): ?array
{
    $ownerUserId = ap_list_owner_user_id($ownerUserId);
    if ($id <= 0 || $ownerUserId < 1) {
        return null;
    }
    try {
        $st = ap_db()->prepare('SELECT * FROM masto_lists WHERE id = ? AND owner_user_id = ?');
        $st->execute([$id, $ownerUserId]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return list<array<string,mixed>>
 */
function ap_list_accounts(int $listId): array
{
    try {
        $st = ap_db()->prepare(
            'SELECT * FROM masto_list_accounts WHERE list_id = ? ORDER BY added_at DESC, actor_id ASC'
        );
        $st->execute([$listId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return list<string>
 */
function ap_list_actor_ids(int $listId): array
{
    $out = [];
    foreach (ap_list_accounts($listId) as $row) {
        $aid = rtrim(trim((string) ($row['actor_id'] ?? '')), '/');
        if ($aid !== '') {
            $out[] = $aid;
        }
    }
    return $out;
}

function ap_list_normalize_replies_policy(string $policy): ?string
{
    $policy = strtolower(trim($policy));
    if (in_array($policy, ['list', 'followed', 'none'], true)) {
        return $policy;
    }
    return null;
}

/**
 * Mastodon List entity.
 *
 * @param array<string,mixed> $row
 * @return array{id:string,title:string,replies_policy:string,exclusive:bool}
 */
function ap_list_to_masto(array $row): array
{
    $policy = (string) ($row['replies_policy'] ?? 'list');
    if (ap_list_normalize_replies_policy($policy) === null) {
        $policy = 'list';
    }
    return [
        'id' => (string) (int) ($row['id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'replies_policy' => $policy,
        'exclusive' => !empty($row['exclusive']),
    ];
}

/**
 * @return array{ok:bool,error?:string,id?:int,list?:array}
 */
function ap_list_create(string $title, string $repliesPolicy = 'list', bool $exclusive = false): array
{
    $ownerUserId = ap_list_owner_user_id();
    if ($ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Not signed in'];
    }
    $title = trim(ap_fix_utf8($title));
    if ($title === '') {
        return ['ok' => false, 'error' => 'Title is required'];
    }
    if (mb_strlen($title) > AP_LIST_TITLE_MAX) {
        return ['ok' => false, 'error' => 'Title is too long (max ' . AP_LIST_TITLE_MAX . ' characters)'];
    }
    $policy = ap_list_normalize_replies_policy($repliesPolicy);
    if ($policy === null) {
        return ['ok' => false, 'error' => "'" . $repliesPolicy . "' is not a valid replies_policy"];
    }
    try {
        $stc = ap_db()->prepare('SELECT COUNT(*) FROM masto_lists WHERE owner_user_id = ?');
        $stc->execute([$ownerUserId]);
        $count = (int) $stc->fetchColumn();
        if ($count >= AP_LIST_MAX) {
            return ['ok' => false, 'error' => 'List limit reached (max ' . AP_LIST_MAX . ')'];
        }
        $now = ap_db_now();
        ap_db()->prepare(
            'INSERT INTO masto_lists (owner_user_id, title, replies_policy, exclusive, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$ownerUserId, $title, $policy, $exclusive ? 1 : 0, $now, $now]);
        $id = ap_db_last_insert_id('masto_lists');
        $row = ap_list_by_id($id);
        return ['ok' => true, 'id' => $id, 'list' => is_array($row) ? $row : []];
    } catch (Throwable $e) {
        error_log('[ap-lists] create: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not create list'];
    }
}

/**
 * @param array<string,mixed> $fields
 * @return array{ok:bool,error?:string,list?:array}
 */
function ap_list_update(int $id, array $fields): array
{
    $ownerUserId = ap_list_owner_user_id();
    $row = ap_list_by_id($id, $ownerUserId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Record not found'];
    }
    $title = array_key_exists('title', $fields)
        ? trim(ap_fix_utf8((string) $fields['title']))
        : (string) $row['title'];
    if ($title === '') {
        return ['ok' => false, 'error' => 'Title is required'];
    }
    if (mb_strlen($title) > AP_LIST_TITLE_MAX) {
        return ['ok' => false, 'error' => 'Title is too long (max ' . AP_LIST_TITLE_MAX . ' characters)'];
    }
    $policy = (string) ($row['replies_policy'] ?? 'list');
    if (array_key_exists('replies_policy', $fields)) {
        $norm = ap_list_normalize_replies_policy((string) $fields['replies_policy']);
        if ($norm === null) {
            return ['ok' => false, 'error' => "'" . $fields['replies_policy'] . "' is not a valid replies_policy"];
        }
        $policy = $norm;
    }
    $exclusive = array_key_exists('exclusive', $fields)
        ? (!empty($fields['exclusive']) ? 1 : 0)
        : (int) ($row['exclusive'] ?? 0);
    try {
        ap_db()->prepare(
            'UPDATE masto_lists SET title = ?, replies_policy = ?, exclusive = ?, updated_at = ? WHERE id = ? AND owner_user_id = ?'
        )->execute([$title, $policy, $exclusive, ap_db_now(), $id, $ownerUserId]);
        $fresh = ap_list_by_id($id, $ownerUserId);
        return ['ok' => true, 'list' => is_array($fresh) ? $fresh : $row];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update list'];
    }
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_list_delete(int $id): array
{
    $ownerUserId = ap_list_owner_user_id();
    $row = ap_list_by_id($id, $ownerUserId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Record not found'];
    }
    try {
        $db = ap_db();
        $db->beginTransaction();
        $db->prepare('DELETE FROM masto_list_accounts WHERE list_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM masto_lists WHERE id = ? AND owner_user_id = ?')->execute([$id, $ownerUserId]);
        $db->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        try {
            ap_db()->rollBack();
        } catch (Throwable $e2) {
        }
        return ['ok' => false, 'error' => 'Could not delete list'];
    }
}

/**
 * Add a followed account to a list. Optionally follow-then-add when $followIfNeeded.
 *
 * @return array{ok:bool,error?:string,followed?:bool,rate_limited?:bool}
 */
function ap_list_add_account(int $listId, string $actorId, bool $followIfNeeded = false): array
{
    $ownerUserId = ap_list_owner_user_id();
    $list = ap_list_by_id($listId, $ownerUserId);
    if (!$list) {
        return ['ok' => false, 'error' => 'Record not found'];
    }
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
        return ['ok' => false, 'error' => 'Invalid actor URL'];
    }
    $followed = false;
    if (!ap_actor_is_followed($actorId)) {
        if (!$followIfNeeded) {
            return ['ok' => false, 'error' => 'Account must be followed before adding to a list'];
        }
        if (!function_exists('ap_follow_remote_actor')) {
            return ['ok' => false, 'error' => 'Follow helper unavailable'];
        }
        $fres = ap_follow_remote_actor($actorId, true);
        if (empty($fres['ok'])) {
            $err = (string) ($fres['error'] ?? 'Follow failed');
            $rateLimited = str_contains(strtolower($err), 'rate') || !empty($fres['rate_limited']);
            return [
                'ok' => false,
                'error' => $err,
                'rate_limited' => $rateLimited,
            ];
        }
        $followed = true;
    }
    try {
        $stc = ap_db()->prepare('SELECT COUNT(*) AS c FROM masto_list_accounts WHERE list_id = ?');
        $stc->execute([$listId]);
        $count = (int) ($stc->fetch()['c'] ?? 0);
        $exists = ap_db()->prepare(
            'SELECT 1 FROM masto_list_accounts WHERE list_id = ? AND actor_id = ?'
        );
        $exists->execute([$listId, $actorId]);
        if ($exists->fetch()) {
            return ['ok' => true, 'followed' => $followed];
        }
        if ($count >= AP_LIST_MAX_MEMBERS) {
            return ['ok' => false, 'error' => 'List member limit reached (max ' . AP_LIST_MAX_MEMBERS . ')'];
        }
        $now = ap_db_now();
        ap_db()->prepare(
            'INSERT INTO masto_list_accounts (list_id, actor_id, added_at) VALUES (?, ?, ?)'
        )->execute([$listId, $actorId, $now]);
        ap_db()->prepare('UPDATE masto_lists SET updated_at = ? WHERE id = ?')->execute([$now, $listId]);
        return ['ok' => true, 'followed' => $followed];
    } catch (Throwable $e) {
        error_log('[ap-lists] add_account: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not add account'];
    }
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_list_remove_account(int $listId, string $actorId): array
{
    $ownerUserId = ap_list_owner_user_id();
    $list = ap_list_by_id($listId, $ownerUserId);
    if (!$list) {
        return ['ok' => false, 'error' => 'Record not found'];
    }
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '') {
        return ['ok' => false, 'error' => 'Invalid actor'];
    }
    try {
        ap_db()->prepare(
            'DELETE FROM masto_list_accounts WHERE list_id = ? AND (actor_id = ? OR actor_id = ?)'
        )->execute([$listId, $actorId, $actorId . '/']);
        ap_db()->prepare('UPDATE masto_lists SET updated_at = ? WHERE id = ?')->execute([ap_db_now(), $listId]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not remove account'];
    }
}

/**
 * Lists that contain a given actor.
 *
 * @return list<array<string,mixed>>
 */
function ap_lists_for_actor(string $actorId, ?int $ownerUserId = null): array
{
    $ownerUserId = ap_list_owner_user_id($ownerUserId);
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '' || $ownerUserId < 1) {
        return [];
    }
    try {
        $st = ap_db()->prepare(
            'SELECT l.* FROM masto_lists l
             INNER JOIN masto_list_accounts a ON a.list_id = l.id
             WHERE l.owner_user_id = ? AND (a.actor_id = ? OR a.actor_id = ?)
             ORDER BY l.title ASC'
        );
        $st->execute([$ownerUserId, $actorId, $actorId . '/']);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Actor IDs that belong to any exclusive list (for home-timeline filtering later).
 *
 * @return array<string,true>
 */
function ap_list_exclusive_actor_map(?int $ownerUserId = null): array
{
    $ownerUserId = ap_list_owner_user_id($ownerUserId);
    if ($ownerUserId < 1) {
        return [];
    }
    $map = [];
    try {
        $st = ap_db()->prepare(
            'SELECT a.actor_id FROM masto_list_accounts a
             INNER JOIN masto_lists l ON l.id = a.list_id
             WHERE l.owner_user_id = ? AND l.exclusive = 1'
        );
        $st->execute([$ownerUserId]);
        $rows = $st->fetchAll();
        foreach ($rows ?: [] as $row) {
            $aid = rtrim((string) ($row['actor_id'] ?? ''), '/');
            if ($aid !== '') {
                $map[$aid] = true;
            }
        }
    } catch (Throwable $e) {
    }
    return $map;
}

/**
 * Mastodon GET /api/v1/timelines/list/:id — posts from list members.
 * replies_policy: list = include replies to list members; followed = replies to anyone we follow;
 * none = drop reply Creates (Announces still included).
 *
 * @return list<array<string,mixed>>
 */
function ap_masto_timeline_list(int $listId, int $limit = 40, ?string $maxId = null, ?string $sinceId = null): array
{
    $limit = max(1, min(80, $limit));
    $list = ap_list_by_id($listId);
    if (!$list) {
        return [];
    }
    $actors = ap_list_actor_ids($listId);
    if (!$actors) {
        return [];
    }
    $policy = ap_list_normalize_replies_policy((string) ($list['replies_policy'] ?? 'list')) ?? 'list';

    $actorIds = [];
    foreach ($actors as $a) {
        $actorIds[$a] = true;
        $actorIds[$a . '/'] = true;
    }
    $ids = array_keys($actorIds);
    $params = $ids;
    $where = [
        "type IN ('Create', 'Announce', 'Quote', 'QuotePost')",
        "( (summary IS NOT NULL AND summary != '') OR (media_urls IS NOT NULL AND media_urls != '' AND media_urls != '[]') OR (spoiler_text IS NOT NULL AND spoiler_text != '') OR (sensitive IS NOT NULL AND sensitive != 0) )",
        "(action_taken = 'log' OR action_taken = 'local_observe')",
        'actor_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
    ];

    if ($maxId !== null && $maxId !== '' && function_exists('ap_masto_event_id_from_status_id')) {
        $eid = ap_masto_event_id_from_status_id((int) $maxId);
        if ($eid !== null) {
            $where[] = 'id < ?';
            $params[] = $eid;
        }
    }
    if ($sinceId !== null && $sinceId !== '' && function_exists('ap_masto_event_id_from_status_id')) {
        $eid = ap_masto_event_id_from_status_id((int) $sinceId);
        if ($eid !== null) {
            $where[] = 'id > ?';
            $params[] = $eid;
        }
    }

    $followedMap = [];
    if ($policy === 'followed' || $policy === 'list') {
        foreach (ap_following_list() as $f) {
            $fa = rtrim((string) ($f['actor_id'] ?? ''), '/');
            if ($fa !== '') {
                $followedMap[$fa] = true;
            }
        }
    }

    $sql = 'SELECT * FROM events WHERE ' . implode(' AND ', $where)
        . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) max($limit * 3, $limit + 20);
    try {
        $st = ap_db()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[ap-lists] timeline: ' . $e->getMessage());
        return [];
    }

    $out = [];
    $seenUri = [];
    foreach ($rows as $row) {
        $type = (string) ($row['type'] ?? '');
        $inReplyTo = trim((string) ($row['in_reply_to'] ?? ''));
        if ($type === 'Create' && $inReplyTo !== '') {
            if ($policy === 'none') {
                continue;
            }
            // Best-effort: resolve reply parent actor from events cache
            if ($policy === 'list' || $policy === 'followed') {
                $parentActor = ap_list_parent_actor_for_object($inReplyTo);
                if ($parentActor !== null) {
                    $pa = rtrim($parentActor, '/');
                    if ($policy === 'list' && empty($actorIds[$pa]) && empty($actorIds[$pa . '/'])) {
                        continue;
                    }
                    if ($policy === 'followed' && empty($followedMap[$pa])) {
                        continue;
                    }
                }
                // If parent unknown, keep the reply (prefer showing over dropping).
            }
        }

        if (!function_exists('ap_masto_status_from_event')) {
            continue;
        }
        $status = ap_masto_status_from_event($row);
        if ($status === null) {
            continue;
        }
        $uri = (string) ($status['uri'] ?? $status['url'] ?? $row['object_id'] ?? '');
        $uriKey = rtrim($uri, '/');
        $uriKey = preg_replace('#^http://#i', 'https://', $uriKey) ?? $uriKey;
        if ($uriKey !== '' && isset($seenUri[$uriKey])) {
            continue;
        }
        if ($uriKey !== '') {
            $seenUri[$uriKey] = true;
        }
        $out[] = $status;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function ap_list_parent_actor_for_object(string $objectId): ?string
{
    $objectId = rtrim(trim($objectId), '/');
    if ($objectId === '') {
        return null;
    }
    try {
        $st = ap_db()->prepare(
            "SELECT actor_id FROM events
             WHERE (object_id = ? OR object_id = ?)
               AND type IN ('Create', 'Update')
             ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$objectId, $objectId . '/']);
        $row = $st->fetch();
        if (is_array($row) && !empty($row['actor_id'])) {
            return rtrim((string) $row['actor_id'], '/');
        }
    } catch (Throwable $e) {
    }
    return null;
}

/**
 * Resolve @user@host or https actor URL for list membership forms.
 */
function ap_list_resolve_actor_ref(string $ref): ?string
{
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }
    if (str_starts_with($ref, 'https://')) {
        return rtrim($ref, '/');
    }
    if (!function_exists('ap_resolve_actor_ref')) {
        return null;
    }
    $resolved = ap_resolve_actor_ref($ref);
    if (is_string($resolved) && str_starts_with($resolved, 'https://')) {
        return rtrim($resolved, '/');
    }
    if (is_array($resolved)) {
        $id = rtrim((string) ($resolved['id'] ?? $resolved['actor_id'] ?? ''), '/');
        return $id !== '' ? $id : null;
    }
    return null;
}
