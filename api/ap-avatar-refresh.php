<?php
declare(strict_types=1);

/**
 * Batched, cache-only avatar lookup for timeline cards.
 * Known https URLs are returned immediately. Misses and broken URLs are queued
 * (deduped, per-host budget) and never sync-fetched on the page request.
 * bsky_posts is not queried here — that table is too large for a poll.
 */

function ap_avatar_is_usable(?string $url): bool
{
    if (!is_string($url)) {
        return false;
    }
    $url = trim($url);
    if ($url === '' || !str_starts_with($url, 'https://') || strlen($url) > 800) {
        return false;
    }
    if (defined('AP_REMOTE_AVATAR_FALLBACK') && $url === AP_REMOTE_AVATAR_FALLBACK) {
        return false;
    }
    if (defined('AP_LOCAL_AVATAR_FALLBACK') && $url === AP_LOCAL_AVATAR_FALLBACK) {
        return false;
    }
    if (str_contains($url, '/img/avatar/default.')
        || str_contains($url, '/img/avatar/local-default.')
        || str_contains($url, '/img/avatar/default.jpg')
        || str_contains($url, '/img/avatar/default.webp')) {
        return false;
    }
    if (function_exists('ap_profile_sanitize_https_url')) {
        return ap_profile_sanitize_https_url($url) !== null;
    }
    return true;
}

/** @return list<string> */
function ap_avatar_actor_aliases(string $actorId): array
{
    $actorId = rtrim(trim($actorId), '/');
    $out = [];
    if ($actorId !== '' && str_starts_with($actorId, 'https://') && strlen($actorId) <= 800) {
        $out[] = $actorId;
    }
    if (preg_match('~^(https://[^/]+)/users/([^/?#]+)$~', $actorId, $m)) {
        $out[] = $m[1] . '/@' . rawurlencode(rawurldecode($m[2]));
    } elseif (preg_match('~^(https://[^/]+)/@([^/?#]+)$~', $actorId, $m)) {
        $out[] = $m[1] . '/users/' . rawurlencode(rawurldecode($m[2]));
    }
    return array_values(array_unique($out));
}

function ap_avatar_host_budget_take(string $actorId, int $limit = 8): bool
{
    $host = strtolower((string) (parse_url($actorId, PHP_URL_HOST) ?: ''));
    if ($host === '') {
        return false;
    }
    if (!isset($GLOBALS['ap_avatar_host_budget']) || !is_array($GLOBALS['ap_avatar_host_budget'])) {
        $GLOBALS['ap_avatar_host_budget'] = [];
    }
    $used = (int) ($GLOBALS['ap_avatar_host_budget'][$host] ?? 0);
    if ($used >= $limit) {
        return false;
    }
    $GLOBALS['ap_avatar_host_budget'][$host] = $used + 1;
    return true;
}

function ap_avatar_pick(?string $r2, ?string $icon, ?string $bsky): ?string
{
    foreach ([$r2, $icon, $bsky] as $url) {
        if (ap_avatar_is_usable($url)) {
            return trim((string) $url);
        }
    }
    return null;
}

function ap_avatar_bsky_did(string $actorId): string
{
    $actorId = rtrim(trim($actorId), '/');
    if (str_starts_with($actorId, 'did:')) {
        return $actorId;
    }
    if (preg_match('~^https://bsky\.app/profile/(did:[^/?#]+)~i', $actorId, $m)) {
        $did = rawurldecode($m[1]);
        return str_contains($did, '%') ? '' : $did;
    }
    if (preg_match('~^https://bsky\.brid\.gy/ap/(did:[^/?#]+)~i', $actorId, $m)) {
        $did = rawurldecode($m[1]);
        return str_contains($did, '%') ? '' : $did;
    }
    return '';
}

function ap_avatar_is_bsky_app(string $actorId): bool
{
    $host = strtolower((string) (parse_url($actorId, PHP_URL_HOST) ?: ''));
    return $host === 'bsky.app' || $host === 'www.bsky.app';
}

function ap_avatar_queue_bsky(string $actorId, int $ownerUserId, bool $durable = true): void
{
    if (!function_exists('ap_bsky_is_profile_ref') || !ap_bsky_is_profile_ref($actorId)) {
        return;
    }
    // The durable profile queue reads bsky_actor_profiles. Timeline paint only
    // pushes the coalesced Redis job; the batched avatar poll may use both.
    if ($durable && $ownerUserId > 0 && function_exists('ap_bsky_actor_refresh_enqueue')) {
        ap_bsky_actor_refresh_enqueue($ownerUserId, $actorId);
    }
    $did = ap_avatar_bsky_did($actorId);
    if ($did === '' || !function_exists('ap_redis_queue_push')) {
        return;
    }
    $rustPrimary = getenv('VAAK_ACTOR_WARM_RUST_PRIMARY');
    $rustPrimary = $rustPrimary === false
        || !in_array(strtolower(trim((string) $rustPrimary)), ['0', 'false', 'off', 'no'], true);
    if (!$rustPrimary) {
        return;
    }
    $lockKey = 'bsky-actor-warm:' . hash('sha256', $did);
    $locked = !function_exists('ap_redis_lock') || ap_redis_lock($lockKey, 45);
    if (!$locked) {
        return;
    }
    $payload = json_encode([
        'did' => $did,
        'owner' => max(0, $ownerUserId),
        'ts' => time(),
        'source' => 'avatar-refresh',
    ], JSON_UNESCAPED_SLASHES);
    if (is_string($payload)) {
        ap_redis_queue_push('bsky_actor_warm', $payload);
    }
}

function ap_avatar_queue_refresh(string $actorId, bool $force, int $ownerUserId = 0): void
{
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
        return;
    }
    if (!ap_avatar_host_budget_take($actorId)) {
        return;
    }
    $bskyApp = ap_avatar_is_bsky_app($actorId);
    if (!$bskyApp && function_exists('ap_remote_actor_warm_async')) {
        ap_remote_actor_warm_async($actorId, $force);
    }
    if (!$bskyApp && function_exists('ap_remote_media_warm_async')) {
        ap_remote_media_warm_async($actorId);
    }
    ap_avatar_queue_bsky($actorId, $ownerUserId);
}

/**
 * A broken hotlink should be fetched again even if updated_at is inside the 12h window.
 * Backdating is an indexed update on the actor row, not a table scan.
 */
function ap_avatar_mark_stale(string $actorId): void
{
    if (!function_exists('ap_db')) {
        return;
    }
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '') {
        return;
    }
    try {
        $db = ap_db();
        $oldActor = gmdate('c', time() - 13 * 3600);
        $db->prepare('UPDATE remote_actors SET updated_at = ? WHERE actor_id = ?')->execute([$oldActor, $actorId]);
        $oldMedia = gmdate('c', time() - 8 * 86400);
        $db->prepare("UPDATE remote_media_cache SET fetched_at = ? WHERE actor_id = ? AND kind = 'avatar'")
            ->execute([$oldMedia, $actorId]);
    } catch (Throwable $e) {
        // The refresh queue still runs if the cache row is missing.
    }
}

/**
 * @param list<string> $actors
 * @param list<string> $broken
 * @return array<string,string> actor id => https avatar, never the placeholder
 */
function ap_avatar_refresh_resolve(array $actors, array $broken = [], int $ownerUserId = 0): array
{
    $wanted = [];
    foreach ([$actors, $broken] as $list) {
        foreach ($list as $actor) {
            if (!is_string($actor)) {
                continue;
            }
            $actor = rtrim(trim($actor), '/');
            if ($actor === '' || !str_starts_with($actor, 'https://') || strlen($actor) > 800) {
                continue;
            }
            $wanted[$actor] = true;
            if (count($wanted) >= 40) {
                break 2;
            }
        }
    }
    $brokenSet = [];
    foreach ($broken as $actor) {
        if (!is_string($actor)) {
            continue;
        }
        $actor = rtrim(trim($actor), '/');
        if (isset($wanted[$actor])) {
            $brokenSet[$actor] = true;
        }
    }
    if ($wanted === []) {
        return [];
    }

    $aliasOf = [];
    $keys = [];
    foreach (array_keys($wanted) as $actor) {
        foreach (ap_avatar_actor_aliases($actor) as $alias) {
            $aliasOf[$alias][] = $actor;
            $keys[$alias] = true;
        }
    }
    $keys = array_keys($keys);
    $r2 = [];
    $icons = [];
    $bskyAvatars = [];
    if (function_exists('ap_db') && $keys !== []) {
        try {
            $db = ap_db();
            $ph = implode(',', array_fill(0, count($keys), '?'));
            $st = $db->prepare(
                "SELECT actor_id, public_url FROM remote_media_cache WHERE kind = 'avatar' AND actor_id IN ($ph)"
            );
            $st->execute($keys);
            foreach ($st->fetchAll() ?: [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = rtrim((string) ($row['actor_id'] ?? ''), '/');
                $url = trim((string) ($row['public_url'] ?? ''));
                if ($id !== '' && ap_avatar_is_usable($url)) {
                    $r2[$id] = $url;
                }
            }
            $st = $db->prepare("SELECT actor_id, icon_source_url FROM remote_actors WHERE actor_id IN ($ph)");
            $st->execute($keys);
            foreach ($st->fetchAll() ?: [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = rtrim((string) ($row['actor_id'] ?? ''), '/');
                $url = trim((string) ($row['icon_source_url'] ?? ''));
                if ($id !== '' && ap_avatar_is_usable($url)) {
                    $icons[$id] = $url;
                }
            }
            $dids = [];
            $refs = [];
            foreach (array_keys($wanted) as $actor) {
                $did = ap_avatar_bsky_did($actor);
                if ($did !== '') {
                    $dids[$did] = true;
                    $refs['https://bsky.app/profile/' . $did] = true;
                }
                if (ap_avatar_is_bsky_app($actor) || str_contains($actor, 'bsky.brid.gy/ap/')) {
                    $refs[$actor] = true;
                }
            }
            if ($dids !== [] || $refs !== []) {
                $refKeys = array_keys($refs);
                $didKeys = array_keys($dids);
                $where = [];
                $params = [];
                if ($refKeys !== []) {
                    $where[] = 'actor_ref IN (' . implode(',', array_fill(0, count($refKeys), '?')) . ')';
                    array_push($params, ...$refKeys);
                }
                if ($didKeys !== []) {
                    $where[] = 'did IN (' . implode(',', array_fill(0, count($didKeys), '?')) . ')';
                    array_push($params, ...$didKeys);
                }
                $bst = $db->prepare(
                    'SELECT actor_ref, did, profile_json FROM bsky_actor_profiles WHERE ' . implode(' OR ', $where)
                );
                $bst->execute($params);
                foreach ($bst->fetchAll() ?: [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $profile = json_decode((string) ($row['profile_json'] ?? ''), true);
                    $did = trim((string) ($row['did'] ?? ''));
                    $rawAvatar = is_array($profile) ? ($profile['avatar'] ?? null) : null;
                    $url = function_exists('ap_bsky_avatar_public_url')
                        ? (ap_bsky_avatar_public_url($rawAvatar, $did) ?? '')
                        : (is_string($rawAvatar) ? trim($rawAvatar) : '');
                    if (!ap_avatar_is_usable($url)) {
                        continue;
                    }
                    $ref = rtrim((string) ($row['actor_ref'] ?? ''), '/');
                    if ($ref !== '') {
                        $bskyAvatars[$ref] = $url;
                    }
                    if ($did !== '') {
                        $bskyAvatars['https://bsky.app/profile/' . $did] = $url;
                        $bskyAvatars['https://bsky.brid.gy/ap/' . $did] = $url;
                    }
                }
            }
        } catch (Throwable $e) {
            // Cache tables may be absent in a partial install. Queue still runs.
        }
    }

    $out = [];
    foreach (array_keys($wanted) as $actor) {
        $r2Url = null;
        $iconUrl = null;
        foreach (ap_avatar_actor_aliases($actor) as $alias) {
            if ($r2Url === null && isset($r2[$alias])) {
                $r2Url = $r2[$alias];
            }
            if ($iconUrl === null && isset($icons[$alias])) {
                $iconUrl = $icons[$alias];
            }
        }
        $bskyUrl = $bskyAvatars[$actor] ?? null;
        $did = ap_avatar_bsky_did($actor);
        if ($bskyUrl === null && $did !== '') {
            $bskyUrl = $bskyAvatars['https://bsky.app/profile/' . $did] ?? null;
        }
        $isBroken = isset($brokenSet[$actor]);
        if ($isBroken) {
            ap_avatar_mark_stale($actor);
            ap_avatar_queue_refresh($actor, true, $ownerUserId);
            if (ap_avatar_is_usable($r2Url)) {
                $out[$actor] = (string) $r2Url;
            }
            continue;
        }
        $picked = ap_avatar_pick($r2Url, $iconUrl, $bskyUrl);
        if ($picked !== null) {
            $out[$actor] = $picked;
            continue;
        }
        ap_avatar_queue_refresh($actor, false, $ownerUserId);
    }
    return $out;
}
