<?php
/** Shared, request-local visibility and cross-network deduplication helpers. */
declare(strict_types=1);

/** Return the strongest local/Bluesky moderation decision for an actor. */
function ap_visibility_actor_hidden(string $actor, int $ownerUserId): bool
{
    $actor = rtrim(trim($actor), '/');
    if ($actor === '' || $ownerUserId < 1) {
        return false;
    }
    if (str_starts_with(strtolower($actor), 'did:')) {
        if (function_exists('ap_bsky_hide_did_reasons')) {
            return ap_bsky_hide_did_reasons($ownerUserId, $actor) !== [];
        }
        if (function_exists('ap_bsky_hide_did_set')) {
            $set = ap_bsky_hide_did_set($ownerUserId);
            return isset($set[$actor]) || isset($set[strtolower($actor)]);
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
        $accounts = [];
        $account = $status['account'] ?? null;
        if (is_array($account)) {
            foreach (['uri', 'url', 'id'] as $field) {
                $actor = trim((string) ($account[$field] ?? ''));
                if ($actor !== '') {
                    $accounts[] = $actor;
                }
            }
        }
        $nested = $status['reblog'] ?? ($status['quote'] ?? null);
        if (is_array($nested) && is_array($nested['account'] ?? null)) {
            foreach (['uri', 'url', 'id'] as $field) {
                $actor = trim((string) ($nested['account'][$field] ?? ''));
                if ($actor !== '') {
                    $accounts[] = $actor;
                }
            }
        }
        $hidden = false;
        foreach (array_unique($accounts) as $actor) {
            if (ap_visibility_actor_hidden($actor, $ownerUserId)) {
                $hidden = true;
                break;
            }
        }
        if ($hidden) {
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
