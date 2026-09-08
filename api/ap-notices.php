<?php
/** Local-only operator notices for authenticated VAAK users. */
declare(strict_types=1);

/** @return list<array<string,mixed>> */
function ap_notices_list(bool $includeUnpublished = false, int $limit = 100): array
{
    $limit = max(1, min(200, $limit));
    $sql = 'SELECT id, title, body, published, created_at, updated_at
            FROM ap_notices ' . ($includeUnpublished ? '' : 'WHERE published = 1 ') .
        'ORDER BY updated_at DESC, id DESC LIMIT ' . $limit;
    try {
        return ap_db()->query($sql)->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[ap-notices] list: ' . $e->getMessage());
        return [];
    }
}

function ap_notice_save(?int $id, string $title, string $body, bool $published = true): array
{
    $title = trim($title);
    $body = trim($body);
    if ($title === '' && $body === '') {
        return ['ok' => false, 'error' => 'Add a title or notice text first.'];
    }
    if (mb_strlen($title) > 160) {
        return ['ok' => false, 'error' => 'Notice titles are limited to 160 characters.'];
    }
    if (mb_strlen($body) > 20000) {
        return ['ok' => false, 'error' => 'Notice text is limited to 20,000 characters.'];
    }
    $now = ap_db_now();
    try {
        if ($id !== null && $id > 0) {
            $st = ap_db()->prepare(
                'UPDATE ap_notices SET title = ?, body = ?, published = ?, updated_at = ? WHERE id = ?'
            );
            $st->execute([$title, $body, $published ? 1 : 0, $now, $id]);
            return ['ok' => true, 'id' => $id, 'updated' => $st->rowCount() > 0];
        }
        $st = ap_db()->prepare(
            'INSERT INTO ap_notices (title, body, published, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$title, $body, $published ? 1 : 0, $now, $now]);
        return ['ok' => true, 'id' => ap_db_last_insert_id('ap_notices')];
    } catch (Throwable $e) {
        error_log('[ap-notices] save: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save notice.'];
    }
}

function ap_notice_delete(int $id): array
{
    if ($id < 1) {
        return ['ok' => false, 'error' => 'Invalid notice.'];
    }
    try {
        $db = ap_db();
        $db->beginTransaction();
        $db->prepare('DELETE FROM ap_notice_replies WHERE notice_id = ?')->execute([$id]);
        $st = $db->prepare('DELETE FROM ap_notices WHERE id = ?');
        $st->execute([$id]);
        $db->commit();
        return ['ok' => true, 'deleted' => $st->rowCount() > 0];
    } catch (Throwable $e) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[ap-notices] delete: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not delete notice.'];
    }
}

/** @return list<array<string,mixed>> */
function ap_notice_replies(int $noticeId): array
{
    if ($noticeId < 1) {
        return [];
    }
    try {
        $st = ap_db()->prepare(
            'SELECT r.id, r.notice_id, r.owner_user_id, r.body, r.created_at,
                    COALESCE(u.username, \'local user\') AS username
             FROM ap_notice_replies r
             LEFT JOIN ap_users u ON u.id = r.owner_user_id
             WHERE r.notice_id = ?
             ORDER BY r.created_at ASC, r.id ASC'
        );
        $st->execute([$noticeId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[ap-notices] replies: ' . $e->getMessage());
        return [];
    }
}

/**
 * Unread published notices for a local user (created after their last Notices visit).
 * Users with no read cursor yet get 0 so old notices don't suddenly badge everyone.
 */
function ap_notices_unread_count(int $ownerUserId): int
{
    if ($ownerUserId < 1) {
        return 0;
    }
    try {
        $st = ap_db()->prepare('SELECT last_read_at FROM ap_notice_reads WHERE owner_user_id = ? LIMIT 1');
        $st->execute([$ownerUserId]);
        $last = $st->fetchColumn();
        if (!is_string($last) || $last === '') {
            return 0;
        }
        $cnt = ap_db()->prepare(
            'SELECT COUNT(*) FROM ap_notices
             WHERE published = 1 AND created_at > ?'
        );
        $cnt->execute([$last]);
        return max(0, (int) $cnt->fetchColumn());
    } catch (Throwable $e) {
        error_log('[ap-notices] unread count: ' . $e->getMessage());
        return 0;
    }
}

/** Mark Notices as caught up for this user (clears the nav badge). */
function ap_notices_mark_read(int $ownerUserId): void
{
    if ($ownerUserId < 1) {
        return;
    }
    $now = ap_db_now();
    try {
        $existing = ap_db()->prepare('SELECT 1 FROM ap_notice_reads WHERE owner_user_id = ? LIMIT 1');
        $existing->execute([$ownerUserId]);
        if ($existing->fetchColumn()) {
            ap_db()->prepare('UPDATE ap_notice_reads SET last_read_at = ? WHERE owner_user_id = ?')
                ->execute([$now, $ownerUserId]);
        } else {
            ap_db()->prepare('INSERT INTO ap_notice_reads (owner_user_id, last_read_at) VALUES (?, ?)')
                ->execute([$ownerUserId, $now]);
        }
    } catch (Throwable $e) {
        error_log('[ap-notices] mark read: ' . $e->getMessage());
    }
}

/**
 * Ensure a read cursor exists so future notices can badge.
 * Called on normal authenticated page loads (not only Notices).
 */
function ap_notices_ensure_read_cursor(int $ownerUserId): void
{
    if ($ownerUserId < 1) {
        return;
    }
    try {
        $st = ap_db()->prepare('SELECT 1 FROM ap_notice_reads WHERE owner_user_id = ? LIMIT 1');
        $st->execute([$ownerUserId]);
        if ($st->fetchColumn()) {
            return;
        }
        ap_db()->prepare('INSERT INTO ap_notice_reads (owner_user_id, last_read_at) VALUES (?, ?)')
            ->execute([$ownerUserId, ap_db_now()]);
    } catch (Throwable $e) {
        error_log('[ap-notices] ensure cursor: ' . $e->getMessage());
    }
}

function ap_notice_reply_add(int $noticeId, int $ownerUserId, string $body): array
{
    $body = trim($body);
    if ($noticeId < 1 || $ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Invalid notice reply.'];
    }
    if ($body === '') {
        return ['ok' => false, 'error' => 'Write a reply first.'];
    }
    if (mb_strlen($body) > 5000) {
        return ['ok' => false, 'error' => 'Replies are limited to 5,000 characters.'];
    }
    try {
        $check = ap_db()->prepare('SELECT 1 FROM ap_notices WHERE id = ? AND published = 1 LIMIT 1');
        $check->execute([$noticeId]);
        if (!$check->fetchColumn()) {
            return ['ok' => false, 'error' => 'Notice not found.'];
        }
        $now = ap_db_now();
        $st = ap_db()->prepare(
            'INSERT INTO ap_notice_replies (notice_id, owner_user_id, body, created_at) VALUES (?, ?, ?, ?)'
        );
        $st->execute([$noticeId, $ownerUserId, $body, $now]);
        return ['ok' => true, 'id' => ap_db_last_insert_id('ap_notice_replies')];
    } catch (Throwable $e) {
        error_log('[ap-notices] reply_add: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save reply.'];
    }
}
