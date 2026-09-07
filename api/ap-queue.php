<?php
/**
 * Admin posting queue — evenly spaced slots in a daily NY window.
 * Used by ap-admin.php (enqueue / UI) and ap-queue-publish.php (cron worker).
 */
declare(strict_types=1);

require_once __DIR__ . '/ap-db.php';

function ap_queue_owner_user_id(): int
{
    // Prefer explicit session bind; ap_db_default_owner_user_id is fail-closed when bound.
    if (function_exists('ap_db_session_owner_user_id')) {
        $id = (int) ap_db_session_owner_user_id();
        if ($id > 0) {
            return $id;
        }
    }
    if (function_exists('ap_db_default_owner_user_id')) {
        return (int) ap_db_default_owner_user_id();
    }
    return 0;
}

function ap_queue_owner_actor_id(): string
{
    if (!empty($GLOBALS['vaak_actor_id'])) {
        $id = rtrim((string) $GLOBALS['vaak_actor_id'], '/');
        if ($id !== '' && str_starts_with($id, 'https://')) {
            return $id;
        }
    }
    if (function_exists('ap_local_actor_id')) {
        $id = rtrim(ap_local_actor_id(), '/');
        if ($id !== '') {
            return $id;
        }
    }
    // Unbound cron may publish cmdr rows via owner_user_id on each queue item —
    // do not invent an actor here for a missing session.
    return '';
}

/** True when the row belongs to the current (or given) owner. */
function ap_queue_row_owned(array $item, ?int $ownerUserId = null): bool
{
    $ownerUserId = $ownerUserId ?? ap_queue_owner_user_id();
    return (int) ($item['owner_user_id'] ?? 0) === $ownerUserId;
}

/**
 * @return array{
 *   timezone:string,window_start:string,window_end:string,
 *   min_gap_minutes:int,enabled:bool,updated_at:string
 * }
 */
function ap_queue_settings_get(): array
{
    $defaults = [
        'timezone' => 'America/New_York',
        'window_start' => '09:00',
        'window_end' => '21:00',
        'min_gap_minutes' => 60,
        'enabled' => true,
        'updated_at' => ap_db_now(),
    ];
    try {
        $row = ap_db()->query('SELECT * FROM ap_queue_settings WHERE id = 1')->fetch();
        if (!is_array($row)) {
            return $defaults;
        }
        return [
            'timezone' => (string) ($row['timezone'] ?? $defaults['timezone']),
            'window_start' => (string) ($row['window_start'] ?? $defaults['window_start']),
            'window_end' => (string) ($row['window_end'] ?? $defaults['window_end']),
            'min_gap_minutes' => max(5, min(720, (int) ($row['min_gap_minutes'] ?? 60))),
            'enabled' => !empty($row['enabled']),
            'updated_at' => (string) ($row['updated_at'] ?? $defaults['updated_at']),
        ];
    } catch (Throwable $e) {
        return $defaults;
    }
}

/**
 * @return array{ok:bool,error?:string,settings?:array}
 */
function ap_queue_settings_set(array $in): array
{
    $tz = trim((string) ($in['timezone'] ?? 'America/New_York'));
    if ($tz === '') {
        $tz = 'America/New_York';
    }
    try {
        new DateTimeZone($tz);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Invalid timezone'];
    }
    $start = trim((string) ($in['window_start'] ?? '09:00'));
    $end = trim((string) ($in['window_end'] ?? '21:00'));
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
        return ['ok' => false, 'error' => 'Start/end must be HH:MM'];
    }
    [$sh, $sm] = array_map('intval', explode(':', $start));
    [$eh, $em] = array_map('intval', explode(':', $end));
    if ($sh > 23 || $sm > 59 || $eh > 23 || $em > 59) {
        return ['ok' => false, 'error' => 'Invalid clock time'];
    }
    $startMin = $sh * 60 + $sm;
    $endMin = $eh * 60 + $em;
    if ($endMin <= $startMin) {
        return ['ok' => false, 'error' => 'End must be after start (no overnight windows in v1)'];
    }
    $gap = max(5, min(720, (int) ($in['min_gap_minutes'] ?? 60)));
    $enabled = !empty($in['enabled']);
    $now = ap_db_now();
    ap_db()->prepare(
        'INSERT INTO ap_queue_settings (id, timezone, window_start, window_end, min_gap_minutes, enabled, updated_at)
         VALUES (1, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET
           timezone = excluded.timezone,
           window_start = excluded.window_start,
           window_end = excluded.window_end,
           min_gap_minutes = excluded.min_gap_minutes,
           enabled = excluded.enabled,
           updated_at = excluded.updated_at'
    )->execute([$tz, $start, $end, $gap, $enabled ? 1 : 0, $now]);
    ap_queue_recompute_schedule();
    return ['ok' => true, 'settings' => ap_queue_settings_get()];
}

/**
 * Slot times for one local calendar day as UTC DateTimeImmutable list.
 *
 * @return list<DateTimeImmutable>
 */
function ap_queue_day_slots(DateTimeImmutable $dayLocal, array $settings): array
{
    $tz = new DateTimeZone((string) $settings['timezone']);
    $startParts = explode(':', (string) $settings['window_start']);
    $endParts = explode(':', (string) $settings['window_end']);
    $startMin = ((int) $startParts[0]) * 60 + (int) $startParts[1];
    $endMin = ((int) $endParts[0]) * 60 + (int) $endParts[1];
    $span = $endMin - $startMin;
    if ($span <= 0) {
        return [];
    }
    $gap = max(5, (int) $settings['min_gap_minutes']);
    $maxSlots = (int) floor($span / $gap) + 1;
    $maxSlots = max(1, min(48, $maxSlots));
    $dayYmd = $dayLocal->setTimezone($tz)->format('Y-m-d');
    $slots = [];
    for ($i = 0; $i < $maxSlots; $i++) {
        $offset = $maxSlots === 1 ? 0 : (int) round($i * ($span / ($maxSlots - 1)));
        $mins = $startMin + $offset;
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        $local = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            sprintf('%s %02d:%02d:00', $dayYmd, $h, $m),
            $tz
        );
        if ($local instanceof DateTimeImmutable) {
            $slots[] = $local->setTimezone(new DateTimeZone('UTC'));
        }
    }
    return $slots;
}

/**
 * Reassign scheduled_at for pending items per owner (FIFO by position, then id).
 */
function ap_queue_recompute_schedule(): void
{
    $settings = ap_queue_settings_get();
    $tz = new DateTimeZone($settings['timezone']);
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $nowLocal = $nowUtc->setTimezone($tz);

    $rows = ap_db()->query(
        "SELECT id, owner_user_id FROM ap_post_queue
         WHERE state = 'pending'
         ORDER BY owner_user_id ASC, position ASC, id ASC"
    )->fetchAll();
    if (!$rows) {
        return;
    }

    $byOwner = [];
    foreach ($rows as $r) {
        $oid = (int) ($r['owner_user_id'] ?? 1);
        if ($oid < 1) {
            $oid = 1;
        }
        $byOwner[$oid][] = (int) $r['id'];
    }

    $assignments = [];
    foreach ($byOwner as $ids) {
        $day = $nowLocal->setTime(0, 0, 0);
        $guardDays = 0;
        $idx = 0;
        $n = count($ids);
        while ($idx < $n && $guardDays < 400) {
            $slots = ap_queue_day_slots($day, $settings);
            $isToday = $day->format('Y-m-d') === $nowLocal->format('Y-m-d');
            foreach ($slots as $slotUtc) {
                if ($idx >= $n) {
                    break 2;
                }
                // Today: only future slots (1 minute grace)
                if ($isToday && $slotUtc->getTimestamp() <= $nowUtc->getTimestamp() + 60) {
                    continue;
                }
                $assignments[$ids[$idx]] = $slotUtc->format('c');
                $idx++;
            }
            $day = $day->modify('+1 day');
            $guardDays++;
        }
    }

    $upd = ap_db()->prepare('UPDATE ap_post_queue SET scheduled_at = ?, updated_at = ? WHERE id = ? AND state = ?');
    $now = ap_db_now();
    foreach ($assignments as $id => $when) {
        $upd->execute([$when, $now, $id, 'pending']);
    }
}

/**
 * @param list<int> $mediaIds
 * @return array{ok:bool,error?:string,item?:array,scheduled_at?:string}
 */
function ap_queue_enqueue(
    string $content,
    string $spoilerText = '',
    bool $sensitive = false,
    string $inReplyTo = '',
    string $toActor = '',
    string $quoteObject = '',
    array $mediaIds = [],
    string $visibility = 'public'
): array {
    $content = trim($content);
    $spoilerText = mb_substr(trim($spoilerText), 0, 500);
    $visibility = function_exists('ap_normalize_visibility')
        ? ap_normalize_visibility($visibility)
        : 'public';
    $mediaIds = array_values(array_filter(array_map('intval', $mediaIds), static fn($i) => $i > 0));
    if ($content === '' && !$mediaIds && $quoteObject === '') {
        return ['ok' => false, 'error' => 'Post text or media required'];
    }
    if (mb_strlen($content) > 2000) {
        return ['ok' => false, 'error' => 'Post text max 2000 chars'];
    }
    if ($inReplyTo !== '' && !str_starts_with($inReplyTo, 'https://')) {
        return ['ok' => false, 'error' => 'in_reply_to must be an https URL'];
    }
    if ($quoteObject !== '' && !str_starts_with($quoteObject, 'https://')) {
        return ['ok' => false, 'error' => 'quote_object must be an https URL'];
    }
    if ($toActor !== '' && !str_starts_with($toActor, 'https://')) {
        return ['ok' => false, 'error' => 'to_actor must be an https URL'];
    }
    if (count($mediaIds) > 4) {
        return ['ok' => false, 'error' => 'Too many media attachments (max 4)'];
    }
    if ($mediaIds) {
        require_once __DIR__ . '/ap-r2.php';
        $rows = ap_media_by_local_ids($mediaIds);
        if (count($rows) !== count($mediaIds)) {
            return ['ok' => false, 'error' => 'One or more media_ids are invalid'];
        }
        foreach ($rows as $row) {
            if (!empty($row['status_local_id'])) {
                return ['ok' => false, 'error' => 'Media already attached to another post'];
            }
        }
    }

    $now = ap_db_now();
    $ownerUserId = ap_queue_owner_user_id();
    $ownerActorId = ap_queue_owner_actor_id();
    if ($ownerUserId < 1 || $ownerActorId === '' || !str_starts_with($ownerActorId, 'https://')) {
        return ['ok' => false, 'error' => 'Not signed in as a local account (refusing cmdr_nova fallback)'];
    }
    $stMax = ap_db()->prepare(
        "SELECT COALESCE(MAX(position), 0) FROM ap_post_queue
         WHERE owner_user_id = ? AND state IN ('pending','publishing','failed')"
    );
    $stMax->execute([$ownerUserId]);
    $pos = ((int) $stMax->fetchColumn()) + 1;

    ap_db()->prepare(
        'INSERT INTO ap_post_queue
         (created_at, updated_at, position, state, content, spoiler_text, sensitive,
          in_reply_to, to_actor, quote_object, media_ids_json, attempts, visibility,
          owner_user_id, owner_actor_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
    )->execute([
        $now,
        $now,
        $pos,
        'pending',
        $content,
        $spoilerText,
        $sensitive || $spoilerText !== '' ? 1 : 0,
        $inReplyTo !== '' ? $inReplyTo : null,
        $toActor !== '' ? $toActor : null,
        $quoteObject !== '' ? $quoteObject : null,
        json_encode($mediaIds, JSON_UNESCAPED_SLASHES),
        $visibility,
        $ownerUserId,
        $ownerActorId,
    ]);
    $id = ap_db_last_insert_id('ap_post_queue');
    ap_queue_recompute_schedule();
    $item = ap_queue_get($id);
    return [
        'ok' => true,
        'item' => $item,
        'scheduled_at' => is_array($item) ? (string) ($item['scheduled_at'] ?? '') : '',
    ];
}

function ap_queue_get(int $id): ?array
{
    $st = ap_db()->prepare('SELECT * FROM ap_post_queue WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

/** Like ap_queue_get, but null when the row is owned by someone else. */
function ap_queue_get_owned(int $id, ?int $ownerUserId = null): ?array
{
    $item = ap_queue_get($id);
    if (!$item || !ap_queue_row_owned($item, $ownerUserId)) {
        return null;
    }
    return $item;
}

/**
 * @return list<array<string,mixed>>
 */
function ap_queue_list_pending(int $limit = 100, ?int $ownerUserId = null): array
{
    $limit = max(1, min(200, $limit));
    $ownerUserId = $ownerUserId ?? ap_queue_owner_user_id();
    $st = ap_db()->prepare(
        "SELECT * FROM ap_post_queue
         WHERE owner_user_id = ?
           AND state IN ('pending', 'publishing', 'failed')
         ORDER BY
           CASE state WHEN 'failed' THEN 2 WHEN 'publishing' THEN 1 ELSE 0 END,
           position ASC, id ASC
         LIMIT " . (int) $limit
    );
    $st->execute([$ownerUserId]);
    return $st->fetchAll();
}

/**
 * Recently published queue items (default: last 72 hours).
 *
 * @return list<array<string,mixed>>
 */
function ap_queue_list_published(int $limit = 20, int $maxAgeHours = 72, ?int $ownerUserId = null): array
{
    $limit = max(1, min(50, $limit));
    $maxAgeHours = max(1, min(720, $maxAgeHours));
    $ownerUserId = $ownerUserId ?? ap_queue_owner_user_id();
    $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-' . $maxAgeHours . ' hours')
        ->format('c');
    $st = ap_db()->prepare(
        "SELECT * FROM ap_post_queue
         WHERE owner_user_id = ?
           AND state = 'published'
           AND published_at IS NOT NULL
           AND published_at >= ?
         ORDER BY published_at DESC, id DESC
         LIMIT ?"
    );
    $st->execute([$ownerUserId, $cutoff, $limit]);
    return $st->fetchAll();
}

/** Delete published/cancelled queue rows older than N hours (keeps the table small). */
function ap_queue_prune_history(int $maxAgeHours = 72): int
{
    $maxAgeHours = max(1, min(720, $maxAgeHours));
    $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-' . $maxAgeHours . ' hours')
        ->format('c');
    $st = ap_db()->prepare(
        "DELETE FROM ap_post_queue
         WHERE state IN ('published', 'cancelled')
           AND COALESCE(published_at, updated_at, created_at) < ?"
    );
    $st->execute([$cutoff]);
    return $st->rowCount();
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_queue_cancel(int $id): array
{
    $item = ap_queue_get_owned($id);
    if (!$item) {
        return ['ok' => false, 'error' => 'Queue item not found'];
    }
    if (!in_array((string) $item['state'], ['pending', 'failed'], true)) {
        return ['ok' => false, 'error' => 'Only pending/failed items can be cancelled'];
    }
    $mediaIds = json_decode((string) ($item['media_ids_json'] ?? '[]'), true);
    if (!is_array($mediaIds)) {
        $mediaIds = [];
    }
    ap_db()->prepare(
        "UPDATE ap_post_queue SET state = 'cancelled', updated_at = ?, scheduled_at = NULL WHERE id = ?"
    )->execute([ap_db_now(), $id]);
    ap_queue_scrub_media_ids($mediaIds);
    ap_queue_recompute_schedule();
    return ['ok' => true];
}

/**
 * @param list<int|string> $mediaIds
 */
function ap_queue_scrub_media_ids(array $mediaIds): void
{
    $mediaIds = array_values(array_filter(array_map('intval', $mediaIds), static fn($i) => $i > 0));
    if (!$mediaIds) {
        return;
    }
    require_once __DIR__ . '/ap-r2.php';
    $rows = ap_media_by_local_ids($mediaIds);
    foreach ($rows as $row) {
        if (!empty($row['status_local_id'])) {
            continue; // already published somehow
        }
        // Still referenced by another pending queue item?
        $mid = (int) $row['local_id'];
        $ref = ap_db()->prepare(
            "SELECT id FROM ap_post_queue
             WHERE state IN ('pending','publishing','failed')
               AND media_ids_json LIKE ?
             LIMIT 1"
        );
        $ref->execute(['%' . $mid . '%']);
        if ($ref->fetch()) {
            continue;
        }
        $key = (string) ($row['s3_key'] ?? '');
        if ($key !== '' && function_exists('ap_r2_delete_object')) {
            ap_r2_delete_object($key);
        }
        ap_db()->prepare('DELETE FROM masto_media WHERE local_id = ? AND status_local_id IS NULL')->execute([$mid]);
    }
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_queue_move(int $id, string $direction): array
{
    $item = ap_queue_get_owned($id);
    if (!$item || (string) $item['state'] !== 'pending') {
        return ['ok' => false, 'error' => 'Only pending items can be reordered'];
    }
    $dir = $direction === 'up' ? 'up' : 'down';
    $ownerUserId = ap_queue_owner_user_id();
    $stPend = ap_db()->prepare(
        "SELECT id, position FROM ap_post_queue
         WHERE owner_user_id = ? AND state = 'pending'
         ORDER BY position ASC, id ASC"
    );
    $stPend->execute([$ownerUserId]);
    $pending = $stPend->fetchAll();
    $idx = null;
    foreach ($pending as $i => $row) {
        if ((int) $row['id'] === $id) {
            $idx = $i;
            break;
        }
    }
    if ($idx === null) {
        return ['ok' => false, 'error' => 'Not found in pending queue'];
    }
    $swapWith = $dir === 'up' ? $idx - 1 : $idx + 1;
    if ($swapWith < 0 || $swapWith >= count($pending)) {
        return ['ok' => true]; // already at edge
    }
    $a = $pending[$idx];
    $b = $pending[$swapWith];
    $upd = ap_db()->prepare('UPDATE ap_post_queue SET position = ?, updated_at = ? WHERE id = ?');
    $now = ap_db_now();
    $upd->execute([(int) $b['position'], $now, (int) $a['id']]);
    $upd->execute([(int) $a['position'], $now, (int) $b['id']]);
    ap_queue_recompute_schedule();
    return ['ok' => true];
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_queue_retry(int $id): array
{
    $item = ap_queue_get_owned($id);
    if (!$item || (string) $item['state'] !== 'failed') {
        return ['ok' => false, 'error' => 'Only failed items can be retried'];
    }
    ap_db()->prepare(
        "UPDATE ap_post_queue SET state = 'pending', last_error = NULL, claimed_at = NULL, updated_at = ? WHERE id = ?"
    )->execute([ap_db_now(), $id]);
    ap_queue_recompute_schedule();
    return ['ok' => true];
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_queue_update_content(int $id, string $content, string $spoilerText = '', ?bool $sensitive = null): array
{
    $item = ap_queue_get_owned($id);
    if (!$item || !in_array((string) $item['state'], ['pending', 'failed'], true)) {
        return ['ok' => false, 'error' => 'Item not editable'];
    }
    $content = trim($content);
    $spoilerText = mb_substr(trim($spoilerText), 0, 500);
    $mediaIds = json_decode((string) ($item['media_ids_json'] ?? '[]'), true);
    if (!is_array($mediaIds)) {
        $mediaIds = [];
    }
    $hasQuote = trim((string) ($item['quote_object'] ?? '')) !== '';
    if ($content === '' && !$mediaIds && !$hasQuote) {
        return ['ok' => false, 'error' => 'Post text or media required'];
    }
    if (mb_strlen($content) > 2000) {
        return ['ok' => false, 'error' => 'Post text max 2000 chars'];
    }
    $sens = $sensitive !== null ? $sensitive : ($spoilerText !== '' || !empty($item['sensitive']));
    ap_db()->prepare(
        "UPDATE ap_post_queue
         SET content = ?, spoiler_text = ?, sensitive = ?, updated_at = ?,
             state = CASE WHEN state = 'failed' THEN 'pending' ELSE state END,
             last_error = CASE WHEN state = 'failed' THEN NULL ELSE last_error END
         WHERE id = ?"
    )->execute([$content, $spoilerText, $sens ? 1 : 0, ap_db_now(), $id]);
    ap_queue_recompute_schedule();
    return ['ok' => true];
}

function ap_queue_format_local(?string $utcIso, ?array $settings = null): string
{
    if ($utcIso === null || $utcIso === '') {
        return '';
    }
    $settings = $settings ?? ap_queue_settings_get();
    try {
        $dt = new DateTimeImmutable($utcIso, new DateTimeZone('UTC'));
        $local = $dt->setTimezone(new DateTimeZone($settings['timezone']));
        return $local->format('D M j · g:i A T');
    } catch (Throwable $e) {
        return $utcIso;
    }
}

/**
 * Publish one claimed queue row. Returns publish result.
 *
 * @param array<string,mixed> $row
 * @return array{ok:bool,error?:string,note_id?:string}
 */
function ap_queue_publish_row(array $row): array
{
    if (!defined('AP_INBOX_LIB_ONLY')) {
        define('AP_INBOX_LIB_ONLY', true);
    }
    require_once __DIR__ . '/ap-inbox.php';
    require_once __DIR__ . '/ap-r2.php';

    $mediaIds = json_decode((string) ($row['media_ids_json'] ?? '[]'), true);
    if (!is_array($mediaIds)) {
        $mediaIds = [];
    }
    $mediaIds = array_values(array_filter(array_map('intval', $mediaIds), static fn($i) => $i > 0));

    $prevActor = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
    $actorKey = null;
    $ownerActor = rtrim((string) ($row['owner_actor_id'] ?? ''), '/');
    if ($ownerActor !== '' && preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $ownerActor, $om)) {
        $actorKey = strtolower($om[1]);
    }
    if ($actorKey === null || $actorKey === '') {
        // Fall back to basename of owner_actor_id / local actor
        $fallback = $ownerActor !== '' ? $ownerActor : ap_queue_owner_actor_id();
        if (preg_match('#/users/([A-Za-z0-9_]+)$#', $fallback, $fm)) {
            $actorKey = strtolower($fm[1]);
        }
    }
    if ($actorKey !== null && $actorKey !== '' && function_exists('ap_request_actor_set')) {
        ap_request_actor_set($actorKey);
    }

    try {
        $result = ap_publish_status_text(
            (string) ($row['content'] ?? ''),
            !empty($row['in_reply_to']) ? (string) $row['in_reply_to'] : null,
            !empty($row['to_actor']) ? (string) $row['to_actor'] : null,
            $mediaIds,
            (string) ($row['spoiler_text'] ?? ''),
            !empty($row['sensitive']),
            !empty($row['quote_object']) ? (string) $row['quote_object'] : null,
            null,
            (string) ($row['visibility'] ?? 'public')
        );
    } finally {
        if (function_exists('ap_request_actor_set')) {
            if (is_array($prevActor) && !empty($prevActor['key'])) {
                ap_request_actor_set((string) $prevActor['key']);
            } else {
                ap_request_actor_set(null);
            }
        }
    }

    $id = (int) ($row['id'] ?? 0);
    $now = ap_db_now();
    if (!empty($result['ok'])) {
        ap_db()->prepare(
            "UPDATE ap_post_queue
             SET state = 'published', published_note_id = ?, published_at = ?, updated_at = ?,
                 claimed_at = NULL, last_error = NULL
             WHERE id = ?"
        )->execute([(string) ($result['note_id'] ?? ''), $now, $now, $id]);
        return ['ok' => true, 'note_id' => (string) ($result['note_id'] ?? '')];
    }

    $err = (string) ($result['error'] ?? 'Publish failed');
    $attempts = (int) ($row['attempts'] ?? 0);
    $state = $attempts >= 5 ? 'failed' : 'pending';
    ap_db()->prepare(
        "UPDATE ap_post_queue
         SET state = ?, last_error = ?, claimed_at = NULL, updated_at = ?
         WHERE id = ?"
    )->execute([$state, mb_substr($err, 0, 500), $now, $id]);
    if ($state === 'pending') {
        // Push schedule slightly forward so we don't tight-loop
        try {
            $when = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('+' . min(30, 2 ** min(4, $attempts)) . ' minutes')
                ->format('c');
            ap_db()->prepare('UPDATE ap_post_queue SET scheduled_at = ? WHERE id = ? AND state = ?')
                ->execute([$when, $id, 'pending']);
        } catch (Throwable $e) {
            // ignore
        }
    }
    return ['ok' => false, 'error' => $err];
}

/**
 * Claim and publish due pending items. Returns counts.
 *
 * @return array{claimed:int,published:int,failed:int,reclaimed:int,pruned:int}
 */
function ap_queue_worker_run(int $limit = 5): array
{
    $out = ['claimed' => 0, 'published' => 0, 'failed' => 0, 'reclaimed' => 0, 'pruned' => 0];
    $settings = ap_queue_settings_get();

    // Always prune old history (even if queue publishing is disabled)
    try {
        $out['pruned'] = ap_queue_prune_history(72);
    } catch (Throwable $e) {
        $out['pruned'] = 0;
    }

    if (!$settings['enabled']) {
        return $out;
    }

    $now = ap_db_now();
    // Reclaim stale publishing
    $stale = ap_db()->prepare(
        "UPDATE ap_post_queue
         SET state = 'pending', claimed_at = NULL, updated_at = ?
         WHERE state = 'publishing'
           AND claimed_at IS NOT NULL
           AND claimed_at < ?"
    );
    $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-10 minutes')->format('c');
    $stale->execute([$now, $cutoff]);
    $out['reclaimed'] = $stale->rowCount();

    $limit = max(1, min(20, $limit));
    $due = ap_db()->prepare(
        "SELECT * FROM ap_post_queue
         WHERE state = 'pending'
           AND scheduled_at IS NOT NULL
           AND scheduled_at <= ?
         ORDER BY scheduled_at ASC, position ASC, id ASC
         LIMIT ?"
    );
    $due->execute([$now, $limit]);
    $rows = $due->fetchAll();

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $claim = ap_db()->prepare(
            "UPDATE ap_post_queue
             SET state = 'publishing', claimed_at = ?, attempts = attempts + 1, updated_at = ?
             WHERE id = ? AND state = 'pending'"
        );
        $claim->execute([$now, $now, $id]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        $out['claimed']++;
        $fresh = ap_queue_get($id);
        if (!$fresh) {
            continue;
        }
        $res = ap_queue_publish_row($fresh);
        if (!empty($res['ok'])) {
            $out['published']++;
        } else {
            $out['failed']++;
        }
    }
    return $out;
}
