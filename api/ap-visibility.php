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
function ap_visibility_status_hidden(array $status, int $ownerUserId, int $depth = 0): bool
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
    ) !== null) {
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
        if (str_starts_with($uri, 'https://') || str_starts_with($uri, 'http://')) {
            $db = ap_db();
            $query = $db->prepare("SELECT visibility, actor_id FROM events WHERE object_id IN (?, ?)
                AND type IN ('Create','Update','Quote','QuotePost')
                AND action_taken IN ('log','local_observe','local_fav_update') ORDER BY id DESC LIMIT 1");
            $query->execute([$uri, $uri . '/']);
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
                $query = ap_db()->prepare("SELECT 1 FROM mentions WHERE owner_user_id = ? AND deleted_at IS NULL
                    AND activity_type IN ('Create','Update','Quote','QuotePost') AND object_id IN (?, ?) LIMIT 1");
                $query->execute([$ownerUserId, $uri, $uri . '/']);
                $allowed = (bool) $query->fetchColumn();
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
