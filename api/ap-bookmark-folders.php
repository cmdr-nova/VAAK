<?php
/**
 * VAAK-only bookmark folders layered on masto_bookmarks.
 * Mastodon /api/v1/bookmarks stays a flat set — folders never appear there.
 */
declare(strict_types=1);

require_once __DIR__ . '/ap-db.php';

function vaak_bookmark_folders_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = ap_db();
        $db->exec(
            'CREATE TABLE IF NOT EXISTS vaak_bookmark_folders (
                id BIGSERIAL PRIMARY KEY,
                owner_user_id BIGINT NOT NULL,
                title TEXT NOT NULL,
                position INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
    } catch (Throwable $e) {
        // SQLite / already exists / no DDL
        try {
            ap_db()->exec(
                'CREATE TABLE IF NOT EXISTS vaak_bookmark_folders (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    owner_user_id INTEGER NOT NULL,
                    title TEXT NOT NULL,
                    position INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )'
            );
        } catch (Throwable $e2) {
            // ignore
        }
    }
    try {
        ap_db()->exec(
            'CREATE TABLE IF NOT EXISTS vaak_bookmark_folder_items (
                folder_id BIGINT NOT NULL,
                owner_user_id BIGINT NOT NULL,
                status_id TEXT NOT NULL,
                added_at TEXT NOT NULL,
                PRIMARY KEY (folder_id, status_id)
            )'
        );
    } catch (Throwable $e) {
        try {
            ap_db()->exec(
                'CREATE TABLE IF NOT EXISTS vaak_bookmark_folder_items (
                    folder_id INTEGER NOT NULL,
                    owner_user_id INTEGER NOT NULL,
                    status_id TEXT NOT NULL,
                    added_at TEXT NOT NULL,
                    PRIMARY KEY (folder_id, status_id)
                )'
            );
        } catch (Throwable $e2) {
            // ignore
        }
    }
    try {
        ap_db()->exec('CREATE INDEX IF NOT EXISTS idx_vaak_bm_folders_owner ON vaak_bookmark_folders(owner_user_id, position, id)');
        ap_db()->exec('CREATE INDEX IF NOT EXISTS idx_vaak_bm_folder_items_owner_status ON vaak_bookmark_folder_items(owner_user_id, status_id)');
    } catch (Throwable $e) {
        // ignore
    }
}

/** @return list<array<string,mixed>> */
function vaak_bookmark_folders_list(int $ownerUserId): array
{
    if ($ownerUserId < 1) {
        return [];
    }
    vaak_bookmark_folders_ensure_schema();
    try {
        $st = ap_db()->prepare(
            'SELECT f.*,
                    (SELECT COUNT(*) FROM vaak_bookmark_folder_items i WHERE i.folder_id = f.id) AS item_count
             FROM vaak_bookmark_folders f
             WHERE f.owner_user_id = ?
             ORDER BY f.position ASC, f.id ASC'
        );
        $st->execute([$ownerUserId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[vaak-bm-folders] list: ' . $e->getMessage());
        return [];
    }
}

/**
 * @return array{ok:bool,error?:string,id?:int,folder?:array<string,mixed>}
 */
function vaak_bookmark_folder_create(int $ownerUserId, string $title): array
{
    if ($ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Missing owner.'];
    }
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 80) {
        return ['ok' => false, 'error' => 'Folder name required (max 80 characters).'];
    }
    vaak_bookmark_folders_ensure_schema();
    try {
        $existing = vaak_bookmark_folders_list($ownerUserId);
        if (count($existing) >= 40) {
            return ['ok' => false, 'error' => 'Folder limit reached (40).'];
        }
        $pos = 0;
        foreach ($existing as $f) {
            $pos = max($pos, (int) ($f['position'] ?? 0) + 1);
        }
        $now = ap_db_now();
        ap_db()->prepare(
            'INSERT INTO vaak_bookmark_folders (owner_user_id, title, position, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$ownerUserId, $title, $pos, $now, $now]);
        $id = (int) ap_db_last_insert_id('vaak_bookmark_folders');
        $st = ap_db()->prepare('SELECT * FROM vaak_bookmark_folders WHERE id = ? AND owner_user_id = ?');
        $st->execute([$id, $ownerUserId]);
        $row = $st->fetch();
        return ['ok' => true, 'id' => $id, 'folder' => is_array($row) ? $row : ['id' => $id, 'title' => $title]];
    } catch (Throwable $e) {
        error_log('[vaak-bm-folders] create: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not create folder.'];
    }
}

/**
 * @return array{ok:bool,error?:string}
 */
function vaak_bookmark_folder_delete(int $folderId, int $ownerUserId): array
{
    if ($folderId < 1 || $ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Invalid folder.'];
    }
    vaak_bookmark_folders_ensure_schema();
    try {
        $db = ap_db();
        $check = $db->prepare('SELECT 1 FROM vaak_bookmark_folders WHERE id = ? AND owner_user_id = ?');
        $check->execute([$folderId, $ownerUserId]);
        if (!$check->fetchColumn()) {
            return ['ok' => false, 'error' => 'Folder not found.'];
        }
        $db->prepare('DELETE FROM vaak_bookmark_folder_items WHERE folder_id = ? AND owner_user_id = ?')
            ->execute([$folderId, $ownerUserId]);
        $db->prepare('DELETE FROM vaak_bookmark_folders WHERE id = ? AND owner_user_id = ?')
            ->execute([$folderId, $ownerUserId]);
        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('[vaak-bm-folders] delete: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not delete folder.'];
    }
}

/**
 * Ensure bookmarked, then add to folder.
 *
 * @return array{ok:bool,error?:string,already?:bool}
 */
function vaak_bookmark_folder_add_status(
    int $folderId,
    string $statusId,
    int $ownerUserId,
    ?string $objectId = null
): array {
    if ($folderId < 1 || $ownerUserId < 1 || $statusId === '') {
        return ['ok' => false, 'error' => 'Invalid folder or status.'];
    }
    vaak_bookmark_folders_ensure_schema();
    try {
        $check = ap_db()->prepare(
            'SELECT 1 FROM vaak_bookmark_folders WHERE id = ? AND owner_user_id = ?'
        );
        $check->execute([$folderId, $ownerUserId]);
        if (!$check->fetchColumn()) {
            return ['ok' => false, 'error' => 'Folder not found.'];
        }
        if (function_exists('ap_masto_bookmark_add')) {
            ap_masto_bookmark_add($statusId, $objectId, $ownerUserId);
        }
        $exists = ap_db()->prepare(
            'SELECT 1 FROM vaak_bookmark_folder_items
             WHERE folder_id = ? AND owner_user_id = ? AND status_id = ?'
        );
        $exists->execute([$folderId, $ownerUserId, $statusId]);
        if ($exists->fetchColumn()) {
            return ['ok' => true, 'already' => true];
        }
        ap_db()->prepare(
            'INSERT INTO vaak_bookmark_folder_items (folder_id, owner_user_id, status_id, added_at)
             VALUES (?, ?, ?, ?)'
        )->execute([$folderId, $ownerUserId, $statusId, ap_db_now()]);
        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('[vaak-bm-folders] add: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not add to folder.'];
    }
}

/**
 * @return array{ok:bool,error?:string}
 */
function vaak_bookmark_folder_remove_status(int $folderId, string $statusId, int $ownerUserId): array
{
    if ($folderId < 1 || $ownerUserId < 1 || $statusId === '') {
        return ['ok' => false, 'error' => 'Invalid folder or status.'];
    }
    vaak_bookmark_folders_ensure_schema();
    try {
        $st = ap_db()->prepare(
            'DELETE FROM vaak_bookmark_folder_items
             WHERE folder_id = ? AND owner_user_id = ? AND status_id = ?'
        );
        $st->execute([$folderId, $ownerUserId, $statusId]);
        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('[vaak-bm-folders] remove: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not remove from folder.'];
    }
}

/** Clear folder memberships when a bookmark is removed (any client). */
function vaak_bookmark_folders_on_unbookmark(string $statusId, int $ownerUserId): void
{
    if ($statusId === '' || $ownerUserId < 1) {
        return;
    }
    try {
        vaak_bookmark_folders_ensure_schema();
        ap_db()->prepare(
            'DELETE FROM vaak_bookmark_folder_items WHERE owner_user_id = ? AND status_id = ?'
        )->execute([$ownerUserId, $statusId]);
    } catch (Throwable $e) {
        error_log('[vaak-bm-folders] on_unbookmark: ' . $e->getMessage());
    }
}

/** @return list<string> status_ids in folder */
function vaak_bookmark_folder_status_ids(int $folderId, int $ownerUserId, int $limit = 200): array
{
    if ($folderId < 1 || $ownerUserId < 1) {
        return [];
    }
    $limit = max(1, min(500, $limit));
    vaak_bookmark_folders_ensure_schema();
    try {
        $st = ap_db()->prepare(
            'SELECT status_id FROM vaak_bookmark_folder_items
             WHERE folder_id = ? AND owner_user_id = ?
             ORDER BY added_at DESC
             LIMIT ' . $limit
        );
        $st->execute([$folderId, $ownerUserId]);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $sid = (string) ($row['status_id'] ?? '');
            if ($sid !== '') {
                $out[] = $sid;
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Return both stored status IDs and bookmark object URLs for a folder.
 * Some remote/Bluesky status resolvers normalize the public status ID while
 * rendering, so the object URL is a stable fallback for folder filtering.
 *
 * @return array{status_ids:list<string>,object_ids:list<string>}
 */
function vaak_bookmark_folder_match_keys(int $folderId, int $ownerUserId, int $limit = 500): array
{
    if ($folderId < 1 || $ownerUserId < 1) {
        return ['status_ids' => [], 'object_ids' => []];
    }
    $limit = max(1, min(500, $limit));
    vaak_bookmark_folders_ensure_schema();
    try {
        $st = ap_db()->prepare(
            'SELECT i.status_id, b.object_id
             FROM vaak_bookmark_folder_items i
             LEFT JOIN masto_bookmarks b
               ON b.owner_user_id = i.owner_user_id AND b.status_id = i.status_id
             WHERE i.folder_id = ? AND i.owner_user_id = ?
             ORDER BY i.added_at DESC
             LIMIT ' . $limit
        );
        $st->execute([$folderId, $ownerUserId]);
        $statusIds = [];
        $objectIds = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $sid = trim((string) ($row['status_id'] ?? ''));
            $oid = rtrim(trim((string) ($row['object_id'] ?? '')), '/');
            if ($sid !== '') {
                $statusIds[$sid] = true;
            }
            if ($oid !== '') {
                $objectIds[$oid] = true;
            }
        }
        return ['status_ids' => array_keys($statusIds), 'object_ids' => array_keys($objectIds)];
    } catch (Throwable $e) {
        return ['status_ids' => [], 'object_ids' => []];
    }
}

/** @return list<int> folder ids containing this status for the owner */
function vaak_bookmark_folders_for_status(string $statusId, int $ownerUserId): array
{
    if ($statusId === '' || $ownerUserId < 1) {
        return [];
    }
    vaak_bookmark_folders_ensure_schema();
    try {
        $st = ap_db()->prepare(
            'SELECT folder_id FROM vaak_bookmark_folder_items
             WHERE owner_user_id = ? AND status_id = ?'
        );
        $st->execute([$ownerUserId, $statusId]);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $out[] = (int) ($row['folder_id'] ?? 0);
        }
        return array_values(array_filter($out, static fn($id) => $id > 0));
    } catch (Throwable $e) {
        return [];
    }
}

function vaak_bookmark_folder_member_status_map(array $statusIds, int $ownerUserId): array
{
    $statusIds = array_values(array_unique(array_filter(array_map('strval', $statusIds), static fn($id) => $id !== '')));
    if ($ownerUserId < 1 || $statusIds === []) {
        return [];
    }
    $statusIds = array_slice($statusIds, 0, 300);
    vaak_bookmark_folders_ensure_schema();
    try {
        $marks = implode(',', array_fill(0, count($statusIds), '?'));
        $st = ap_db()->prepare('SELECT DISTINCT status_id FROM vaak_bookmark_folder_items WHERE owner_user_id = ? AND status_id IN (' . $marks . ')');
        $st->execute(array_merge([$ownerUserId], $statusIds));
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $statusId) {
            $statusId = (string) $statusId;
            if ($statusId !== '') {
                $map[$statusId] = true;
            }
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}
