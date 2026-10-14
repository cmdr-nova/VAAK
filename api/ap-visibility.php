<?php
/** Shared, request-local visibility and cross-network deduplication helpers. */
declare(strict_types=1);

/** Return the strongest local/Bluesky moderation decision for an actor. */
function ap_visibility_actor_hidden(string $actor, int $ownerUserId): bool
{
    $actor = rtrim(trim($actor), '/');
    if ($actor === '') {
        return false;
    }
    // Server-wide blocks/mutes apply even without a bound owner (shared cards).
    if (function_exists('ap_is_blocked_actor') && ap_is_blocked_actor($actor)) {
        return true;
    }
    if (function_exists('ap_is_globally_muted_actor') && ap_is_globally_muted_actor($actor)) {
        return true;
    }
    if ($ownerUserId < 1) {
        return false;
    }
    if (str_starts_with(strtolower($actor), 'did:')) {
        if (function_exists('ap_bsky_hide_did_reasons') && ap_bsky_hide_did_reasons($ownerUserId, $actor) !== []) {
            return true;
        }
        if (function_exists('ap_bsky_hide_did_set')) {
            $set = ap_bsky_hide_did_set($ownerUserId);
            if (isset($set[$actor]) || isset($set[strtolower($actor)])) {
                return true;
            }
        }
        // Personal VAAK mute/block against DID / profile aliases.
        if (function_exists('ap_user_is_blocked') && ap_user_is_blocked($actor, 'bsky.app', $ownerUserId)) {
            return true;
        }
        if (function_exists('ap_is_muted_actor') && ap_is_muted_actor($actor, $ownerUserId)) {
            return true;
        }
        return false;
    }
    $host = parse_url($actor, PHP_URL_HOST);
    $host = is_string($host) ? strtolower($host) : null;
    if (function_exists('ap_user_is_blocked') && ap_user_is_blocked($actor, $host, $ownerUserId)) {
        return true;
    }
    if (function_exists('ap_is_muted_actor') && ap_is_muted_actor($actor, $ownerUserId)) {
        return true;
    }
    if (function_exists('ap_is_deprioritized_actor') && ap_is_deprioritized_actor($actor, $ownerUserId)) {
        return false; // deprioritization is ranking, not visibility.
    }
    return function_exists('ap_row_is_hidden') && ap_row_is_hidden($actor, $host, $ownerUserId);
}

/** Stable key for the same post represented by ActivityPub, Bluesky, or a wrapper. */
function ap_visibility_status_key(array $status): string
{
    $keys = [];
    foreach (['uri', 'url', 'id'] as $field) {
        $value = trim((string) ($status[$field] ?? ''));
        if ($value !== '') {
            $keys[] = $value;
        }
    }
    $nested = $status['reblog'] ?? ($status['quote'] ?? null);
    if (is_array($nested)) {
        foreach (['uri', 'url', 'id'] as $field) {
            $value = trim((string) ($nested[$field] ?? ''));
            if ($value !== '') {
                $keys[] = $value;
            }
        }
    }
    foreach ($keys as $value) {
        $value = rtrim($value, '/');
        $value = preg_replace('#^http://#i', 'https://', $value) ?? $value;
        // Announce wrappers should dedupe against the original object, not the
        // wrapper's synthetic fragment.
        $value = preg_replace('/#announce-[^#]+$/i', '', $value) ?? $value;
        if ($value !== '') {
            return strtolower($value);
        }
    }
    return '';
}

/** Filter a hydrated status list without remote fetches. */
function ap_visibility_filter_statuses(array $statuses, int $ownerUserId): array
{
    $out = [];
    $seen = [];
    foreach ($statuses as $status) {
        if (!is_array($status)) {
            continue;
        }
        if (ap_visibility_status_hidden($status, $ownerUserId)) {
            continue;
        }
        $key = ap_visibility_status_key($status);
        if ($key !== '' && isset($seen[$key])) {
            continue;
        }
        if ($key !== '') {
            $seen[$key] = true;
        }
        $out[] = $status;
    }
    return $out;
}

/** Apply moderation to the whole card, including nested boosts and quotes. */
function ap_visibility_status_hidden(array $status, int $ownerUserId, int $depth = 0, bool $ordinaryRoot = false): bool
{
    if ($depth > 16) {
        return true;
    }
    foreach (['uri', 'url', 'id'] as $field) {
        $actor = trim((string) ($status['account'][$field] ?? ''));
        if ($actor !== '' && ap_visibility_actor_hidden($actor, $ownerUserId)) {
            return true;
        }
    }
    if (function_exists('ap_muted_words_match') && ap_muted_words_match(
        $ownerUserId, (string) ($status['content'] ?? ''), (string) ($status['spoiler_text'] ?? '')
    ) !== null && !($ordinaryRoot && $depth === 0
        && ap_ordinary_note_owned((string)($status['uri']??''), $ownerUserId, (string)($status['account']['uri']??'')))) {
        return true;
    }
    foreach (['reblog', 'quote', 'vaak_quote_preview'] as $field) {
        $child = $status[$field] ?? null;
        if (!is_array($child)) {
            continue;
        }
        $child = is_array($child['quoted_status'] ?? null) ? $child['quoted_status']
            : (is_array($child['status'] ?? null) ? $child['status'] : $child);
        if (ap_visibility_status_hidden($child, $ownerUserId, $depth + 1)) {
            return true;
        }
    }
    return false;
}

/** A public discovery wrapper must not smuggle a restricted nested post. */
function ap_visibility_public_status(array $status, int $depth = 0): bool
{
    if ($depth > 16) {
        return false;
    }
    $visibility = strtolower((string) ($status['visibility'] ?? 'public'));
    if (!in_array($visibility, $depth === 0 ? ['public'] : ['public', 'unlisted'], true)) {
        return false;
    }
    foreach (['reblog', 'quote', 'vaak_quote_preview'] as $field) {
        $child = $status[$field] ?? null;
        if (!is_array($child)) {
            continue;
        }
        $child = is_array($child['quoted_status'] ?? null) ? $child['quoted_status']
            : (is_array($child['status'] ?? null) ? $child['status'] : $child);
        if (!ap_visibility_public_status($child, $depth + 1)) {
            return false;
        }
    }
    return true;
}

/**
 * Batch the events, outbox, and mention reads for one notification page.
 * The memo is only valid until the caller unsets it. A later request that
 * does not prefetch must run the original per-status queries.
 *
 * @param list<array<string,mixed>> $statuses notification rows or status objects
 */
function ap_visibility_notification_page_prefetch(array $statuses, int $ownerUserId): void
{
    unset($GLOBALS['ap_visibility_audience_memo'][$ownerUserId]);
    try {
        $collected = [];
        foreach ($statuses as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (is_array($row['status'] ?? null) && !isset($row['visibility'])) {
                ap_visibility_collect_audience_status($row['status'], $collected, 0);
            } else {
                ap_visibility_collect_audience_status($row, $collected, 0);
            }
        }
        $uris = [];
        foreach ($collected as $status) {
            $uri = rtrim(explode('#', trim((string) ($status['uri'] ?? $status['url'] ?? '')), 2)[0], '/');
            if ($uri !== '' && (str_starts_with($uri, 'https://') || str_starts_with($uri, 'http://'))) {
                $uris[$uri] = true;
            }
        }
        $uris = array_keys($uris);
        if ($uris === []) {
            $GLOBALS['ap_visibility_audience_memo'][$ownerUserId] = [];
            return;
        }
        $forms = [];
        foreach ($uris as $uri) {
            $forms[] = $uri;
            $forms[] = $uri . '/';
        }
        $marks = implode(',', array_fill(0, count($forms), '?'));
        $db = ap_db();
        $events = $db->prepare("SELECT id, object_id, visibility, actor_id, type FROM events
            WHERE object_id IN ($marks)
            AND type IN ('Create','Update','Quote','QuotePost')
            AND action_taken IN ('log','local_observe','local_fav_update')
            ORDER BY id DESC");
        $events->execute($forms);
        $eventRows = $events->fetchAll() ?: [];
        $hasCreate = [];
        foreach ($eventRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = rtrim((string) ($row['object_id'] ?? ''), '/');
            if (in_array((string) ($row['type'] ?? ''), ['Create', 'Update'], true)) {
                $hasCreate[$key] = true;
            }
        }
        $bestEvent = [];
        foreach ($eventRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = rtrim((string) ($row['object_id'] ?? ''), '/');
            if ($key === '' || isset($bestEvent[$key])) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            if (in_array($type, ['Quote', 'QuotePost'], true) && !empty($hasCreate[$key])) {
                continue;
            }
            $bestEvent[$key] = $row;
        }
        $outbox = $db->prepare("SELECT id, visibility, to_json, cc_json FROM outbox_notes WHERE id IN ($marks)");
        $outbox->execute($forms);
        $outboxBy = [];
        foreach ($outbox->fetchAll() ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = rtrim((string) ($row['id'] ?? ''), '/');
            if ($key === '') {
                continue;
            }
            $outboxBy[$key][] = $row;
        }
        $memo = [];
        $mentionUris = [];
        foreach ($uris as $uri) {
            $status = null;
            foreach ($collected as $candidate) {
                $candidateUri = rtrim(explode('#', trim((string) ($candidate['uri'] ?? $candidate['url'] ?? '')), 2)[0], '/');
                if ($candidateUri === $uri) {
                    $status = $candidate;
                    break;
                }
            }
            $visibility = strtolower((string) ($status['visibility'] ?? 'public'));
            $author = rtrim((string) ($status['account']['uri'] ?? ''), '/');
            $recipients = [];
            if (isset($bestEvent[$uri])) {
                $visibility = strtolower((string) ($bestEvent[$uri]['visibility'] ?? 'public'));
                $author = rtrim((string) ($bestEvent[$uri]['actor_id'] ?? ''), '/');
            }
            $localRows = $outboxBy[$uri] ?? [];
            if (count($localRows) > 1) {
                $memo[$uri] = ['ready' => false, 'outbox_unbatched' => true];
                continue;
            }
            if (count($localRows) === 1) {
                $local = $localRows[0];
                $visibility = strtolower((string) ($local['visibility'] ?? 'public'));
                $author = explode('/notes/', $uri, 2)[0];
                $recipients = array_merge(
                    json_decode((string) ($local['to_json'] ?? '[]'), true) ?: [],
                    json_decode((string) ($local['cc_json'] ?? '[]'), true) ?: []
                );
                $memo[$uri] = [
                    'ready' => true,
                    'visibility' => $visibility,
                    'author' => $author,
                    'recipients' => $recipients,
                ];
            } elseif (str_starts_with($uri, 'https://mkultra.monster/users/') && str_contains($uri, '/notes/')) {
                $memo[$uri] = ['ready' => true, 'local_missing' => true];
                continue;
            } else {
                $memo[$uri] = [
                    'ready' => true,
                    'visibility' => $visibility,
                    'author' => $author,
                    'recipients' => $recipients,
                ];
            }
            if (!in_array($visibility, ['public', 'unlisted'], true)) {
                $mentionUris[$uri] = true;
            }
        }
        if ($mentionUris !== []) {
            $mentionForms = [];
            foreach (array_keys($mentionUris) as $uri) {
                $mentionForms[] = $uri;
                $mentionForms[] = $uri . '/';
            }
            $mentionMarks = implode(',', array_fill(0, count($mentionForms), '?'));
            $mentioned = $db->prepare("SELECT object_id FROM mentions WHERE owner_user_id = ? AND deleted_at IS NULL
                AND activity_type IN ('Create','Update','Quote','QuotePost') AND object_id IN ($mentionMarks)");
            $mentioned->execute(array_merge([$ownerUserId], $mentionForms));
            $hit = [];
            foreach ($mentioned->fetchAll() ?: [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $hit[rtrim((string) ($row['object_id'] ?? ''), '/')] = true;
            }
            foreach (array_keys($mentionUris) as $uri) {
                if (!empty($memo[$uri]['ready'])) {
                    $memo[$uri]['mentioned'] = !empty($hit[$uri]);
                }
            }
        }
        $GLOBALS['ap_visibility_audience_memo'][$ownerUserId] = $memo;
    } catch (Throwable $e) {
        unset($GLOBALS['ap_visibility_audience_memo'][$ownerUserId]);
    }
}

/** @param array<string,mixed> $status @param list<array<string,mixed>> $out */
function ap_visibility_collect_audience_status(array $status, array &$out, int $depth): void
{
    if ($depth > 16 || $status === []) {
        return;
    }
    $out[] = $status;
    foreach (['reblog', 'quote', 'vaak_quote_preview'] as $field) {
        $child = $status[$field] ?? null;
        if (!is_array($child)) {
            continue;
        }
        $child = is_array($child['quoted_status'] ?? null) ? $child['quoted_status']
            : (is_array($child['status'] ?? null) ? $child['status'] : $child);
        if (is_array($child)) {
            ap_visibility_collect_audience_status($child, $out, $depth + 1);
        }
    }
}

/** Recheck durable audiences when PHP must paint a cached notification. */
function ap_visibility_status_audience_allowed(array $status, int $ownerUserId, int $depth = 0): bool
{
    if ($depth > 16) {
        return false;
    }
    try {
        $uri = rtrim(explode('#', trim((string) ($status['uri'] ?? $status['url'] ?? '')), 2)[0], '/');
        $visibility = strtolower((string) ($status['visibility'] ?? 'public'));
        $author = rtrim((string) ($status['account']['uri'] ?? ''), '/');
        $recipients = [];
        $mentioned = null;
        $memo = $GLOBALS['ap_visibility_audience_memo'][$ownerUserId][$uri] ?? null;
        $useMemo = is_array($memo) && !empty($memo['ready']) && empty($memo['outbox_unbatched']);
        if ($useMemo) {
            if (!empty($memo['local_missing'])) {
                return false;
            }
            $visibility = strtolower((string) ($memo['visibility'] ?? $visibility));
            $author = rtrim((string) ($memo['author'] ?? $author), '/');
            $recipients = is_array($memo['recipients'] ?? null) ? $memo['recipients'] : [];
            if (array_key_exists('mentioned', $memo)) {
                $mentioned = (bool) $memo['mentioned'];
            }
        } elseif (str_starts_with($uri, 'https://') || str_starts_with($uri, 'http://')) {
            $db = ap_db();
            $query = $db->prepare("SELECT visibility, actor_id FROM events WHERE object_id IN (?, ?)
                AND (type IN ('Create','Update')
                    OR (type IN ('Quote','QuotePost') AND NOT EXISTS (
                        SELECT 1 FROM events note_source WHERE note_source.object_id IN (?, ?)
                            AND note_source.type IN ('Create','Update'))))
                AND action_taken IN ('log','local_observe','local_fav_update') ORDER BY id DESC LIMIT 1");
            $query->execute([$uri, $uri . '/', $uri, $uri . '/']);
            if ($row = $query->fetch()) {
                $visibility = strtolower((string) ($row['visibility'] ?? 'public'));
                $author = rtrim((string) ($row['actor_id'] ?? ''), '/');
            }
            $query = $db->prepare('SELECT visibility, to_json, cc_json FROM outbox_notes WHERE id IN (?, ?) LIMIT 1');
            $query->execute([$uri, $uri . '/']);
            $local = $query->fetch();
            if ($local) {
                $visibility = strtolower((string) ($local['visibility'] ?? 'public'));
                $author = explode('/notes/', $uri, 2)[0];
                $recipients = array_merge(json_decode((string) ($local['to_json'] ?? '[]'), true) ?: [], json_decode((string) ($local['cc_json'] ?? '[]'), true) ?: []);
            } elseif (str_starts_with($uri, 'https://mkultra.monster/users/') && str_contains($uri, '/notes/')) {
                return false;
            }
        }
        if (!in_array($visibility, ['public', 'unlisted'], true)) {
            $viewer = $ownerUserId > 0 ? rtrim(ap_db_owner_actor_id_for_user_id($ownerUserId), '/') : '';
            if ($viewer === '') {
                return false;
            }
            $allowed = $author === $viewer || in_array($viewer, array_map(static fn ($v) => rtrim((string) $v, '/'), $recipients), true);
            if (!$allowed && $visibility === 'local') {
                $allowed = str_starts_with($author, 'https://mkultra.monster/users/');
            }
            if (!$allowed) {
                if ($mentioned !== null) {
                    $allowed = $mentioned;
                } else {
                    $query = ap_db()->prepare("SELECT 1 FROM mentions WHERE owner_user_id = ? AND deleted_at IS NULL
                        AND activity_type IN ('Create','Update','Quote','QuotePost') AND object_id IN (?, ?) LIMIT 1");
                    $query->execute([$ownerUserId, $uri, $uri . '/']);
                    $allowed = (bool) $query->fetchColumn();
                }
            }
            if (!$allowed && in_array($visibility, ['private', 'followers', 'followers_only'], true)) {
                $table = str_starts_with($author, 'https://mkultra.monster/users/') ? 'followers' : 'following';
                $query = ap_db()->prepare("SELECT 1 FROM {$table} WHERE owner_actor_id IN (?, ?) AND actor_id IN (?, ?) LIMIT 1");
                $query->execute($table === 'followers' ? [$author, $author . '/', $viewer, $viewer . '/'] : [$viewer, $viewer . '/', $author, $author . '/']);
                $allowed = (bool) $query->fetchColumn();
            }
            if (!$allowed) {
                return false;
            }
        }
        foreach (['reblog', 'quote', 'vaak_quote_preview'] as $field) {
            $child = $status[$field] ?? null;
            if (!is_array($child)) {
                continue;
            }
            $child = is_array($child['quoted_status'] ?? null) ? $child['quoted_status']
                : (is_array($child['status'] ?? null) ? $child['status'] : $child);
            if (!ap_visibility_status_audience_allowed($child, $ownerUserId, $depth + 1)) {
                return false;
            }
        }
        return true;
    } catch (Throwable $e) {
        // An unavailable policy store must not revive stale private HTML.
        return false;
    }
}
