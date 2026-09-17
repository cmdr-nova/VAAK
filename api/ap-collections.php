<?php
/**
 * Fediverse Collections (starter-pack style) — local-first MVP.
 * Loaded by admin / public actor pages; depends on ap-db.php.
 *
 * Ownership is per local actor (session / request actor), not hard-coded to cmdr_nova.
 */
declare(strict_types=1);

const AP_COLLECTION_MAX_LOCAL = 10;
const AP_COLLECTION_MAX_MEMBERS = 25;

/** @deprecated Use ap_collection_session_owner_actor_id() — kept for older call sites. */
const AP_COLLECTION_OWNER = 'https://mkultra.monster/users/cmdr_nova';

/**
 * Actor that owns curated collections for this request (VAAK session or inbox actor).
 */
function ap_collection_session_owner_actor_id(): string
{
    if (function_exists('vaak_actor_id')) {
        $id = rtrim((string) vaak_actor_id(), '/');
        if ($id !== '' && str_starts_with($id, 'https://')) {
            return $id;
        }
    }
    if (function_exists('ap_request_actor_get')) {
        $req = ap_request_actor_get();
        if (is_array($req) && !empty($req['id'])) {
            return rtrim((string) $req['id'], '/');
        }
    }
    if (function_exists('ap_db_session_bound') && ap_db_session_bound()) {
        return '';
    }
    if (function_exists('ap_default_owner_actor_id')) {
        return rtrim(ap_default_owner_actor_id(), '/');
    }
    return AP_COLLECTION_OWNER;
}

function ap_collection_owner_actor_id(?string $explicit = null): string
{
    if (is_string($explicit) && str_starts_with(trim($explicit), 'https://')) {
        return rtrim(trim($explicit), '/');
    }
    return ap_collection_session_owner_actor_id();
}

function ap_collection_session_owner_user_id(): int
{
    if (function_exists('admin_owner_user_id')) {
        $id = (int) admin_owner_user_id();
        if ($id > 0) {
            return $id;
        }
    }
    if (function_exists('ap_db_default_owner_user_id')) {
        return (int) ap_db_default_owner_user_id();
    }
    return 0;
}

/** @param array<string,mixed>|null $row */
function ap_collection_owned_by_session(?array $row): bool
{
    if (!is_array($row)) {
        return false;
    }
    $owner = rtrim((string) ($row['owner_actor_id'] ?? ''), '/');
    return $owner !== '' && $owner === ap_collection_session_owner_actor_id();
}

function ap_collection_object_id(int $id, ?string $ownerActorId = null): string
{
    if ($ownerActorId === null || $ownerActorId === '') {
        $row = ap_collection_by_id($id, true);
        if (is_array($row) && !empty($row['owner_actor_id'])) {
            $ownerActorId = (string) $row['owner_actor_id'];
        }
    }
    return ap_collection_owner_actor_id($ownerActorId) . '/collections/' . $id;
}

function ap_collection_html_url(int $id, ?string $ownerActorId = null): string
{
    return ap_collection_object_id($id, $ownerActorId);
}

/**
 * Ensure memberships are per-user (safe while table is empty / low traffic).
 */
function ap_collections_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = ap_db();
        // PostgreSQL staging is schema-verified by ap_db_migrate_postgres().
        // The rebuild below is only for upgrading legacy SQLite deployments.
        if (ap_db_driver($db) === 'pgsql') {
            return;
        }
        $cols = $db->query('PRAGMA table_info(ap_collection_memberships)')->fetchAll() ?: [];
        $names = array_column($cols, 'name');
        if (!in_array('owner_user_id', $names, true)) {
            // Rebuild: old UNIQUE(collection_object_id) blocked multi-user imports of the same pack.
            $db->exec('ALTER TABLE ap_collection_memberships RENAME TO ap_collection_memberships_old');
            $db->exec(<<<'SQL'
CREATE TABLE ap_collection_memberships (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER NOT NULL DEFAULT 1,
    collection_object_id TEXT NOT NULL,
    curator_actor_id TEXT,
    name TEXT,
    html_url TEXT,
    state TEXT NOT NULL DEFAULT 'accepted',
    raw_json TEXT,
    dismissed_at TEXT,
    seen_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(owner_user_id, collection_object_id)
)
SQL);
            $db->exec(
                'INSERT INTO ap_collection_memberships
                 (owner_user_id, collection_object_id, curator_actor_id, name, html_url, state, raw_json, dismissed_at, seen_at, updated_at)
                 SELECT 1, collection_object_id, curator_actor_id, name, html_url, state, raw_json, dismissed_at, seen_at, updated_at
                 FROM ap_collection_memberships_old'
            );
            $db->exec('DROP TABLE ap_collection_memberships_old');
        }
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ap_coll_memb_owner ON ap_collection_memberships(owner_user_id, updated_at DESC)');
    } catch (Throwable $e) {
        error_log('[ap-collections] schema: ' . $e->getMessage());
    }
}

/**
 * @return list<array<string,mixed>>
 */
function ap_collections_list_local(bool $includeDeleted = false, ?string $ownerActorId = null): array
{
    $owner = ap_collection_owner_actor_id($ownerActorId);
    $sql = 'SELECT c.*,
              (SELECT COUNT(*) FROM ap_collection_items i WHERE i.collection_id = c.id AND i.state = \'accepted\') AS member_count
            FROM ap_collections c
            WHERE c.owner_actor_id = ? AND c.local = 1';
    if (!$includeDeleted) {
        $sql .= ' AND c.deleted_at IS NULL';
    }
    $sql .= ' ORDER BY c.updated_at DESC, c.id DESC';
    $st = ap_db()->prepare($sql);
    $st->execute([$owner]);
    return $st->fetchAll() ?: [];
}

function ap_collection_by_id(int $id, bool $includeDeleted = false): ?array
{
    $sql = 'SELECT * FROM ap_collections WHERE id = ?';
    if (!$includeDeleted) {
        $sql .= ' AND deleted_at IS NULL';
    }
    $st = ap_db()->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

function ap_collection_by_object_id(string $objectId): ?array
{
    $st = ap_db()->prepare('SELECT * FROM ap_collections WHERE object_id = ? AND deleted_at IS NULL');
    $st->execute([rtrim(trim($objectId), '/')]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

/**
 * @return list<array<string,mixed>>
 */
function ap_collection_items(int $collectionId, ?string $state = 'accepted'): array
{
    if ($state === null) {
        $st = ap_db()->prepare(
            'SELECT * FROM ap_collection_items WHERE collection_id = ? ORDER BY position ASC, id ASC'
        );
        $st->execute([$collectionId]);
    } else {
        $st = ap_db()->prepare(
            'SELECT * FROM ap_collection_items WHERE collection_id = ? AND state = ? ORDER BY position ASC, id ASC'
        );
        $st->execute([$collectionId, $state]);
    }
    return $st->fetchAll() ?: [];
}

/**
 * @return array{ok:bool,error?:string,id?:int,collection?:array}
 */
function ap_collection_create(string $name, string $description = '', bool $discoverable = true, ?string $tagName = null): array
{
    $name = trim(ap_fix_utf8($name));
    $description = trim(ap_fix_utf8($description));
    if ($name === '') {
        return ['ok' => false, 'error' => 'Name is required'];
    }
    if (mb_strlen($name) > 40) {
        return ['ok' => false, 'error' => 'Name is too long (max 40 characters)'];
    }
    if (mb_strlen($description) > 500) {
        return ['ok' => false, 'error' => 'Description is too long (max 500 characters)'];
    }
    $owner = ap_collection_session_owner_actor_id();
    if ($owner === '') {
        return ['ok' => false, 'error' => 'Not signed in'];
    }
    $stc = ap_db()->prepare(
        'SELECT COUNT(*) AS c FROM ap_collections WHERE owner_actor_id = ? AND local = 1 AND deleted_at IS NULL'
    );
    $stc->execute([$owner]);
    $count = (int) ($stc->fetch()['c'] ?? 0);
    if ($count >= AP_COLLECTION_MAX_LOCAL) {
        return ['ok' => false, 'error' => 'Collection limit reached (max ' . AP_COLLECTION_MAX_LOCAL . ')'];
    }
    $tag = null;
    if (is_string($tagName) && trim($tagName) !== '') {
        $tag = ltrim(trim($tagName), '#');
        $tag = mb_substr(preg_replace('/\s+/', '', $tag) ?? $tag, 0, 100);
        if ($tag === '') {
            $tag = null;
        }
    }
    $now = ap_db_now();
    $tmp = $owner . '/collections/tmp-' . bin2hex(random_bytes(6));
    ap_db()->prepare(
        'INSERT INTO ap_collections
         (object_id, owner_actor_id, name, description, language, sensitive, discoverable, tag_name, local, source_uri, html_url, created_at, updated_at, deleted_at)
         VALUES (?, ?, ?, ?, \'en\', 0, ?, ?, 1, NULL, NULL, ?, ?, NULL)'
    )->execute([$tmp, $owner, $name, $description, $discoverable ? 1 : 0, $tag, $now, $now]);
    $id = ap_db_last_insert_id('ap_collections');
    $objectId = ap_collection_object_id($id, $owner);
    $htmlUrl = ap_collection_html_url($id, $owner);
    ap_db()->prepare('UPDATE ap_collections SET object_id = ?, html_url = ? WHERE id = ?')->execute([$objectId, $htmlUrl, $id]);
    $row = ap_collection_by_id($id);
    return ['ok' => true, 'id' => $id, 'collection' => is_array($row) ? $row : []];
}

/**
 * @param array<string,mixed> $fields
 * @return array{ok:bool,error?:string}
 */
function ap_collection_update(int $id, array $fields): array
{
    $row = ap_collection_by_id($id);
    if (!$row || (int) ($row['local'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'Collection not found'];
    }
    if (!ap_collection_owned_by_session($row)) {
        return ['ok' => false, 'error' => 'Not your collection'];
    }
    $name = array_key_exists('name', $fields) ? trim(ap_fix_utf8((string) $fields['name'])) : (string) $row['name'];
    $description = array_key_exists('description', $fields)
        ? trim(ap_fix_utf8((string) $fields['description']))
        : (string) ($row['description'] ?? '');
    if ($name === '') {
        return ['ok' => false, 'error' => 'Name is required'];
    }
    if (mb_strlen($name) > 40) {
        return ['ok' => false, 'error' => 'Name is too long (max 40 characters)'];
    }
    if (mb_strlen($description) > 500) {
        return ['ok' => false, 'error' => 'Description is too long (max 500 characters)'];
    }
    $discoverable = array_key_exists('discoverable', $fields)
        ? (!empty($fields['discoverable']) ? 1 : 0)
        : (int) ($row['discoverable'] ?? 1);
    $sensitive = array_key_exists('sensitive', $fields)
        ? (!empty($fields['sensitive']) ? 1 : 0)
        : (int) ($row['sensitive'] ?? 0);
    $tag = $row['tag_name'] ?? null;
    if (array_key_exists('tag_name', $fields)) {
        $raw = trim((string) ($fields['tag_name'] ?? ''));
        if ($raw === '') {
            $tag = null;
        } else {
            $tag = ltrim($raw, '#');
            $tag = mb_substr(preg_replace('/\s+/', '', $tag) ?? $tag, 0, 100);
            if ($tag === '') {
                $tag = null;
            }
        }
    }
    ap_db()->prepare(
        'UPDATE ap_collections SET name = ?, description = ?, discoverable = ?, sensitive = ?, tag_name = ?, updated_at = ?
         WHERE id = ?'
    )->execute([$name, $description, $discoverable, $sensitive, $tag, ap_db_now(), $id]);
    return ['ok' => true];
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_collection_soft_delete(int $id): array
{
    $row = ap_collection_by_id($id);
    if (!$row || (int) ($row['local'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'Collection not found'];
    }
    if (!ap_collection_owned_by_session($row)) {
        return ['ok' => false, 'error' => 'Not your collection'];
    }
    $now = ap_db_now();
    ap_db()->prepare('UPDATE ap_collections SET deleted_at = ?, updated_at = ? WHERE id = ?')->execute([$now, $now, $id]);
    return ['ok' => true];
}

/**
 * @return array{ok:bool,error?:string,item?:array}
 */
function ap_collection_add_member(int $collectionId, string $actorId): array
{
    $row = ap_collection_by_id($collectionId);
    if (!$row || (int) ($row['local'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'Collection not found'];
    }
    if (!ap_collection_owned_by_session($row)) {
        return ['ok' => false, 'error' => 'Not your collection'];
    }
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
        return ['ok' => false, 'error' => 'Invalid actor URL'];
    }
    $owner = rtrim((string) ($row['owner_actor_id'] ?? ''), '/');
    if ($actorId === $owner || ($owner !== '' && str_starts_with($actorId, $owner . '/'))) {
        return ['ok' => false, 'error' => 'Cannot add yourself to a collection'];
    }
    if (function_exists('ap_is_blocked_actor') && ap_is_blocked_actor($actorId)) {
        return ['ok' => false, 'error' => 'That account is blocked'];
    }
    $stc = ap_db()->prepare('SELECT COUNT(*) AS c FROM ap_collection_items WHERE collection_id = ? AND state = \'accepted\'');
    $stc->execute([$collectionId]);
    $n = (int) ($stc->fetch()['c'] ?? 0);
    $exists = ap_db()->prepare('SELECT id, state FROM ap_collection_items WHERE collection_id = ? AND actor_id = ?');
    $exists->execute([$collectionId, $actorId]);
    $ex = $exists->fetch();
    if (is_array($ex)) {
        if (($ex['state'] ?? '') === 'accepted') {
            return ['ok' => false, 'error' => 'Already in this collection'];
        }
        ap_db()->prepare(
            'UPDATE ap_collection_items SET state = \'accepted\', added_at = ? WHERE id = ?'
        )->execute([ap_db_now(), (int) $ex['id']]);
        ap_db()->prepare('UPDATE ap_collections SET updated_at = ? WHERE id = ?')->execute([ap_db_now(), $collectionId]);
        $st = ap_db()->prepare('SELECT * FROM ap_collection_items WHERE id = ?');
        $st->execute([(int) $ex['id']]);
        return ['ok' => true, 'item' => $st->fetch() ?: []];
    }
    if ($n >= AP_COLLECTION_MAX_MEMBERS) {
        return ['ok' => false, 'error' => 'Member limit reached (max ' . AP_COLLECTION_MAX_MEMBERS . ')'];
    }
    ap_db()->prepare(
        'INSERT INTO ap_collection_items (collection_id, actor_id, position, state, added_at) VALUES (?, ?, ?, \'accepted\', ?)'
    )->execute([$collectionId, $actorId, $n, ap_db_now()]);
    $itemId = ap_db_last_insert_id('ap_collection_items');
    ap_db()->prepare('UPDATE ap_collections SET updated_at = ? WHERE id = ?')->execute([ap_db_now(), $collectionId]);
    $st = ap_db()->prepare('SELECT * FROM ap_collection_items WHERE id = ?');
    $st->execute([$itemId]);
    return ['ok' => true, 'item' => $st->fetch() ?: []];
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_collection_remove_member(int $collectionId, string $actorId): array
{
    $row = ap_collection_by_id($collectionId);
    if (!$row || (int) ($row['local'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'Collection not found'];
    }
    if (!ap_collection_owned_by_session($row)) {
        return ['ok' => false, 'error' => 'Not your collection'];
    }
    $actorId = rtrim(trim($actorId), '/');
    $st = ap_db()->prepare('DELETE FROM ap_collection_items WHERE collection_id = ? AND actor_id = ?');
    $st->execute([$collectionId, $actorId]);
    if ($st->rowCount() < 1) {
        return ['ok' => false, 'error' => 'Member not found'];
    }
    ap_db()->prepare('UPDATE ap_collections SET updated_at = ? WHERE id = ?')->execute([ap_db_now(), $collectionId]);
    return ['ok' => true];
}

/**
 * Follow every accepted member not already followed. Respects outbound follow hourly cap.
 *
 * @return array{ok:bool,error?:string,followed:int,skipped:int,failed:int,rate_limited:bool,errors:list<string>}
 */
function ap_collection_follow_all(int $collectionId): array
{
    $row = ap_collection_by_id($collectionId);
    if (!$row || (int) ($row['local'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'Collection not found', 'followed' => 0, 'skipped' => 0, 'failed' => 0, 'rate_limited' => false, 'errors' => []];
    }
    if (!ap_collection_owned_by_session($row)) {
        return ['ok' => false, 'error' => 'Not your collection', 'followed' => 0, 'skipped' => 0, 'failed' => 0, 'rate_limited' => false, 'errors' => []];
    }
    if (!function_exists('ap_follow_remote_actor')) {
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
    }
    $followed = 0;
    $skipped = 0;
    $failed = 0;
    $rateLimited = false;
    $errors = [];
    foreach (ap_collection_items($collectionId, 'accepted') as $item) {
        $actorId = (string) ($item['actor_id'] ?? '');
        if ($actorId === '') {
            $skipped++;
            continue;
        }
        if (function_exists('ap_actor_is_followed') && ap_actor_is_followed($actorId)) {
            $skipped++;
            continue;
        }
        if (function_exists('ap_is_blocked_actor') && ap_is_blocked_actor($actorId)) {
            $skipped++;
            continue;
        }
        $res = ap_follow_remote_actor($actorId, true);
        if (!empty($res['ok'])) {
            $followed++;
            continue;
        }
        $err = (string) ($res['error'] ?? 'follow failed');
        if (stripos($err, 'rate') !== false || stripos($err, 'limit') !== false || stripos($err, 'hour') !== false) {
            $rateLimited = true;
            $errors[] = $err;
            break;
        }
        $failed++;
        if (count($errors) < 8) {
            $errors[] = $actorId . ': ' . $err;
        }
    }
    return [
        'ok' => true,
        'followed' => $followed,
        'skipped' => $skipped,
        'failed' => $failed,
        'rate_limited' => $rateLimited,
        'errors' => $errors,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function ap_collection_memberships_list(bool $includeDismissed = false, ?int $ownerUserId = null): array
{
    ap_collections_ensure_schema();
    $ownerUserId = $ownerUserId ?? ap_collection_session_owner_user_id();
    $sql = 'SELECT * FROM ap_collection_memberships WHERE owner_user_id = ?';
    if (!$includeDismissed) {
        $sql .= ' AND dismissed_at IS NULL';
    }
    $sql .= ' ORDER BY updated_at DESC, id DESC';
    $st = ap_db()->prepare($sql);
    $st->execute([(int) $ownerUserId]);
    return $st->fetchAll() ?: [];
}

/**
 * @param array<string,mixed> $fields
 * @return array{ok:bool,error?:string,id?:int}
 */
function ap_collection_membership_upsert(array $fields): array
{
    ap_collections_ensure_schema();
    $objectId = rtrim(trim((string) ($fields['collection_object_id'] ?? '')), '/');
    if ($objectId === '' || !str_starts_with($objectId, 'https://')) {
        return ['ok' => false, 'error' => 'Invalid collection id'];
    }
    $ownerUserId = isset($fields['owner_user_id'])
        ? (int) $fields['owner_user_id']
        : ap_collection_session_owner_user_id();
    if ($ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Not signed in'];
    }
    $now = ap_db_now();
    $curator = isset($fields['curator_actor_id']) ? rtrim(trim((string) $fields['curator_actor_id']), '/') : null;
    $name = isset($fields['name']) ? trim(ap_fix_utf8((string) $fields['name'])) : null;
    $htmlUrl = isset($fields['html_url']) ? trim((string) $fields['html_url']) : null;
    $state = (string) ($fields['state'] ?? 'accepted');
    if (!in_array($state, ['pending', 'accepted', 'rejected', 'revoked'], true)) {
        $state = 'accepted';
    }
    $raw = isset($fields['raw_json']) && is_string($fields['raw_json']) ? $fields['raw_json'] : null;
    ap_db()->prepare(
        'INSERT INTO ap_collection_memberships
         (owner_user_id, collection_object_id, curator_actor_id, name, html_url, state, raw_json, dismissed_at, seen_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)
         ON CONFLICT(owner_user_id, collection_object_id) DO UPDATE SET
           curator_actor_id = COALESCE(excluded.curator_actor_id, ap_collection_memberships.curator_actor_id),
           name = COALESCE(excluded.name, ap_collection_memberships.name),
           html_url = COALESCE(excluded.html_url, ap_collection_memberships.html_url),
           state = excluded.state,
           raw_json = COALESCE(excluded.raw_json, ap_collection_memberships.raw_json),
           dismissed_at = NULL,
           seen_at = excluded.seen_at,
           updated_at = excluded.updated_at'
    )->execute([
        $ownerUserId,
        $objectId,
        ($curator !== null && $curator !== '') ? $curator : null,
        ($name !== null && $name !== '') ? $name : null,
        ($htmlUrl !== null && $htmlUrl !== '') ? $htmlUrl : null,
        $state,
        $raw,
        $now,
        $now,
    ]);
    $st = ap_db()->prepare(
        'SELECT id FROM ap_collection_memberships WHERE owner_user_id = ? AND collection_object_id = ?'
    );
    $st->execute([$ownerUserId, $objectId]);
    return ['ok' => true, 'id' => (int) ($st->fetch()['id'] ?? 0)];
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_collection_membership_dismiss(int $id): array
{
    ap_collections_ensure_schema();
    $ownerUserId = ap_collection_session_owner_user_id();
    $st = ap_db()->prepare(
        'UPDATE ap_collection_memberships SET dismissed_at = ?, updated_at = ?
         WHERE id = ? AND owner_user_id = ?'
    );
    $st->execute([ap_db_now(), ap_db_now(), $id, $ownerUserId]);
    if ($st->rowCount() < 1) {
        return ['ok' => false, 'error' => 'Membership not found'];
    }
    return ['ok' => true];
}

/**
 * @param array<string,mixed> $doc
 */
function ap_collection_doc_features_local(array $doc, ?string $localActorId = null): bool
{
    $local = rtrim($localActorId ?: ap_collection_session_owner_actor_id(), '/');
    $key = '';
    if (preg_match('#/users/([A-Za-z0-9_]+)$#', $local, $m)) {
        $key = $m[1];
    }
    $needle = [
        $local,
        rtrim($local, '/'),
    ];
    if ($key !== '') {
        $needle[] = 'https://mkultra.monster/@' . $key;
        $needle[] = 'acct:' . $key . '@mkultra.monster';
        $needle[] = '@' . $key . '@mkultra.monster';
    }
    $blob = json_encode($doc, JSON_UNESCAPED_SLASHES) ?: '';
    foreach ($needle as $n) {
        if ($n !== '' && str_contains($blob, $n)) {
            return true;
        }
    }
    return false;
}

/**
 * @return array{ok:bool,error?:string,id?:int,name?:?string}
 */
function ap_collection_import_membership_from_url(string $url): array
{
    $url = trim($url);
    if ($url === '' || !preg_match('#^https://#i', $url)) {
        return ['ok' => false, 'error' => 'Need an https:// collection URL'];
    }
    $ownerActor = ap_collection_session_owner_actor_id();
    $ownerKey = '';
    if (preg_match('#/users/([A-Za-z0-9_]+)$#', $ownerActor, $m)) {
        $ownerKey = $m[1];
    }
    if (function_exists('ap_profile_collection_consent') && !ap_profile_collection_consent($ownerKey !== '' ? $ownerKey : 'cmdr_nova')) {
        return ['ok' => false, 'error' => 'Collection consent is off in Profile — turn on “Allow featuring in Collections” first'];
    }
    if (!function_exists('ap_fetch_as2_object')) {
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
    }
    $doc = ap_fetch_as2_object($url);
    if (!is_array($doc)) {
        return ['ok' => false, 'error' => 'Could not fetch collection (not AS2 JSON or unreachable)'];
    }
    // Also accept Mastodon REST wrapper { collection: {...}, accounts: [...] }
    if (isset($doc['collection']) && is_array($doc['collection'])) {
        $doc = array_merge($doc, $doc['collection']);
    }
    if (!ap_collection_doc_features_local($doc, $ownerActor)) {
        $label = $ownerKey !== '' ? ('@' . $ownerKey . '@mkultra.monster') : $ownerActor;
        return ['ok' => false, 'error' => 'That collection does not appear to include ' . $label];
    }
    $objectId = '';
    if (isset($doc['id']) && is_string($doc['id'])) {
        $objectId = $doc['id'];
    } elseif (isset($doc['uri']) && is_string($doc['uri'])) {
        $objectId = $doc['uri'];
    } else {
        $objectId = $url;
    }
    $name = isset($doc['name']) && is_string($doc['name']) ? $doc['name'] : null;
    $curator = null;
    if (isset($doc['attributedTo'])) {
        $a = $doc['attributedTo'];
        if (is_string($a)) {
            $curator = $a;
        } elseif (is_array($a) && isset($a['id']) && is_string($a['id'])) {
            $curator = $a['id'];
        }
    }
    $htmlUrl = isset($doc['url']) && is_string($doc['url']) ? $doc['url'] : $url;
    $raw = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $res = ap_collection_membership_upsert([
        'collection_object_id' => $objectId,
        'curator_actor_id' => $curator,
        'name' => $name,
        'html_url' => $htmlUrl,
        'state' => 'accepted',
        'raw_json' => is_string($raw) ? $raw : null,
        'owner_user_id' => ap_collection_session_owner_user_id(),
    ]);
    if (empty($res['ok'])) {
        return $res;
    }
    return ['ok' => true, 'id' => (int) ($res['id'] ?? 0), 'name' => $name];
}

ap_collections_ensure_schema();
