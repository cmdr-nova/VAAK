<?php
/** Local-only discussion forums for authenticated VAAK users. */
declare(strict_types=1);

function ap_discuss_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.uP');
}

/** @return list<array<string,mixed>> */
function ap_discuss_categories(?int $ownerUserId = null): array
{
    try {
        $ownerUserId = max(0, (int) $ownerUserId);
        $st = ap_db()->prepare(
            'SELECT c.id, c.slug, c.name, c.description, c.position, c.updated_at,
                    COUNT(t.id) AS topic_count,
                    MAX(t.updated_at) AS latest_at,
                    (SELECT COUNT(*)
                       FROM ap_discuss_topics ut
                       LEFT JOIN ap_discuss_reads ur
                         ON ur.topic_id = ut.id AND ur.owner_user_id = ?
                      WHERE ut.category_id = c.id
                        AND (ur.last_read_at IS NULL OR ut.updated_at > ur.last_read_at)
                    ) AS unread_count
             FROM ap_discuss_categories c
             LEFT JOIN ap_discuss_topics t ON t.category_id = c.id
             GROUP BY c.id, c.slug, c.name, c.description, c.position, c.updated_at
             ORDER BY c.position ASC, c.id ASC'
        );
        $st->execute([$ownerUserId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[ap-discuss] categories: ' . $e->getMessage());
        return [];
    }
}

function ap_discuss_unread_topic_count(int $ownerUserId): int
{
    if ($ownerUserId < 1) {
        return 0;
    }
    try {
        $st = ap_db()->prepare(
            'SELECT COUNT(*)
               FROM ap_discuss_topics t
               LEFT JOIN ap_discuss_reads r
                 ON r.topic_id = t.id AND r.owner_user_id = ?
              WHERE r.last_read_at IS NULL OR t.updated_at > r.last_read_at'
        );
        $st->execute([$ownerUserId]);
        return max(0, (int) $st->fetchColumn());
    } catch (Throwable $e) {
        error_log('[ap-discuss] unread count: ' . $e->getMessage());
        return 0;
    }
}

function ap_discuss_mark_read(int $topicId, int $ownerUserId): void
{
    if ($topicId < 1 || $ownerUserId < 1) {
        return;
    }
    try {
        $db = ap_db();
        $check = $db->prepare('SELECT 1 FROM ap_discuss_topics WHERE id = ? LIMIT 1');
        $check->execute([$topicId]);
        if (!$check->fetchColumn()) {
            return;
        }
        $now = ap_discuss_now();
        $existing = $db->prepare('SELECT 1 FROM ap_discuss_reads WHERE owner_user_id = ? AND topic_id = ? LIMIT 1');
        $existing->execute([$ownerUserId, $topicId]);
        if ($existing->fetchColumn()) {
            $db->prepare('UPDATE ap_discuss_reads SET last_read_at = ? WHERE owner_user_id = ? AND topic_id = ?')
                ->execute([$now, $ownerUserId, $topicId]);
        } else {
            $db->prepare('INSERT INTO ap_discuss_reads (owner_user_id, topic_id, last_read_at) VALUES (?, ?, ?)')
                ->execute([$ownerUserId, $topicId, $now]);
        }
    } catch (Throwable $e) {
        error_log('[ap-discuss] mark read: ' . $e->getMessage());
    }
}

function ap_discuss_category(string $slug): ?array
{
    $slug = strtolower(trim($slug));
    if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $slug)) {
        return null;
    }
    try {
        $st = ap_db()->prepare('SELECT id, slug, name, description, position FROM ap_discuss_categories WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        error_log('[ap-discuss] category: ' . $e->getMessage());
        return null;
    }
}

/** @return list<array<string,mixed>> */
function ap_discuss_topics(int $categoryId, int $limit = 100): array
{
    $limit = max(1, min(200, $limit));
    try {
        $st = ap_db()->query(
            'SELECT t.id, t.category_id, t.owner_user_id, t.title, t.created_at, t.updated_at, t.locked,
                    COALESCE(u.username, \'local user\') AS username,
                    COUNT(p.id) AS post_count
             FROM ap_discuss_topics t
             LEFT JOIN ap_users u ON u.id = t.owner_user_id
             LEFT JOIN ap_discuss_posts p ON p.topic_id = t.id
             WHERE t.category_id = ' . (int) $categoryId . '
             GROUP BY t.id, t.category_id, t.owner_user_id, t.title, t.created_at, t.updated_at, t.locked, u.username
             ORDER BY t.updated_at DESC, t.id DESC
             LIMIT ' . $limit
        );
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[ap-discuss] topics: ' . $e->getMessage());
        return [];
    }
}

function ap_discuss_topic(int $topicId): ?array
{
    if ($topicId < 1) {
        return null;
    }
    try {
        $st = ap_db()->prepare(
            'SELECT t.id, t.category_id, t.owner_user_id, t.title, t.created_at, t.updated_at, t.locked,
                    c.slug AS category_slug, c.name AS category_name,
                    COALESCE(u.username, \'local user\') AS username
             FROM ap_discuss_topics t
             JOIN ap_discuss_categories c ON c.id = t.category_id
             LEFT JOIN ap_users u ON u.id = t.owner_user_id
             WHERE t.id = ? LIMIT 1'
        );
        $st->execute([$topicId]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        error_log('[ap-discuss] topic: ' . $e->getMessage());
        return null;
    }
}

/** @return list<array<string,mixed>> */
function ap_discuss_posts(int $topicId): array
{
    if ($topicId < 1) {
        return [];
    }
    try {
        $st = ap_db()->prepare(
            'SELECT p.id, p.topic_id, p.owner_user_id, p.body, p.created_at, p.updated_at,
                    COALESCE(u.username, \'local user\') AS username
             FROM ap_discuss_posts p
             LEFT JOIN ap_users u ON u.id = p.owner_user_id
             WHERE p.topic_id = ?
             ORDER BY p.created_at ASC, p.id ASC'
        );
        $st->execute([$topicId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[ap-discuss] posts: ' . $e->getMessage());
        return [];
    }
}

function ap_discuss_topic_create(int $categoryId, int $ownerUserId, string $title, string $body): array
{
    $title = trim($title);
    $body = trim($body);
    if ($categoryId < 1 || $ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Invalid discussion category.'];
    }
    if ($title === '' || mb_strlen($title) > 180) {
        return ['ok' => false, 'error' => 'Topic titles are required and limited to 180 characters.'];
    }
    if ($body === '' || mb_strlen($body) > 20000) {
        return ['ok' => false, 'error' => 'Topic text is required and limited to 20,000 characters.'];
    }
    try {
        $db = ap_db();
        $check = $db->prepare('SELECT 1 FROM ap_discuss_categories WHERE id = ? LIMIT 1');
        $check->execute([$categoryId]);
        if (!$check->fetchColumn()) {
            return ['ok' => false, 'error' => 'Discussion category not found.'];
        }
        $now = ap_discuss_now();
        $db->beginTransaction();
        $st = $db->prepare(
            'INSERT INTO ap_discuss_topics (category_id, owner_user_id, title, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$categoryId, $ownerUserId, $title, $now, $now]);
        $topicId = ap_db_last_insert_id('ap_discuss_topics', 'id', $db);
        $post = $db->prepare(
            'INSERT INTO ap_discuss_posts (topic_id, owner_user_id, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        );
        $post->execute([$topicId, $ownerUserId, $body, $now, $now]);
        $db->commit();
        return ['ok' => true, 'id' => $topicId];
    } catch (Throwable $e) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[ap-discuss] topic_create: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not create discussion.'];
    }
}

function ap_discuss_post_create(int $topicId, int $ownerUserId, string $body): array
{
    $body = trim($body);
    if ($topicId < 1 || $ownerUserId < 1 || $body === '') {
        return ['ok' => false, 'error' => 'Write a reply first.'];
    }
    if (mb_strlen($body) > 20000) {
        return ['ok' => false, 'error' => 'Replies are limited to 20,000 characters.'];
    }
    try {
        $db = ap_db();
        $check = $db->prepare('SELECT locked FROM ap_discuss_topics WHERE id = ? LIMIT 1');
        $check->execute([$topicId]);
        $locked = $check->fetchColumn();
        if ($locked === false) {
            return ['ok' => false, 'error' => 'Discussion not found.'];
        }
        if (!empty($locked)) {
            return ['ok' => false, 'error' => 'This discussion is locked.'];
        }
        $now = ap_discuss_now();
        $db->beginTransaction();
        $st = $db->prepare(
            'INSERT INTO ap_discuss_posts (topic_id, owner_user_id, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$topicId, $ownerUserId, $body, $now, $now]);
        $postId = ap_db_last_insert_id('ap_discuss_posts', 'id', $db);
        $db->prepare('UPDATE ap_discuss_topics SET updated_at = ? WHERE id = ?')->execute([$now, $topicId]);
        $db->commit();
        return ['ok' => true, 'id' => $postId];
    } catch (Throwable $e) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[ap-discuss] post_create: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save reply.'];
    }
}
