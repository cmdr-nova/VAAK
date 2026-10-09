<?php
/**
 * Pretty local profile routing for on-VAAK surfaces.
 *
 * Canonical app path:  /vaak/users/{key} on mkultra.monster.
 * vaak.monster redirects to that host. /users/{key} and /@{key} on
 * mkultra.monster stay the actor profile a browser gets when Mastodon
 * opens "View profile".
 * Federation JSON:     same /users/{key} URL when Accept prefers
 *                      activity+json or ld+json. Callers check Accept
 *                      before booting. Notes, inbox, and feeds stay put.
 */
declare(strict_types=1);

function ap_vaak_request_host(): string
{
    return strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
}

function ap_vaak_is_vaak_host(): bool
{
    $host = ap_vaak_request_host();
    return $host === 'vaak.monster' || $host === 'www.vaak.monster';
}

function ap_vaak_is_mkultra_host(): bool
{
    $host = ap_vaak_request_host();
    return $host === 'mkultra.monster' || $host === 'www.mkultra.monster';
}

/** Normalize a local actor_key or empty string. */
function ap_vaak_normalize_actor_key(string $raw): string
{
    return strtolower(preg_replace('/[^a-z0-9_]/', '', $raw) ?? '');
}

/**
 * True for a browser navigation that should see the on-VAAK profile.
 * Writes and the Jekyll export stay on the legacy profile handlers.
 */
function ap_vaak_browser_profile_navigation(): bool
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }
    return strtolower((string) ($_GET['format'] ?? '')) !== 'jekyll';
}

/**
 * If this request is a bare local profile page, return the actor_key.
 * Notes/inbox/feeds/subpaths return null so AP/HTML handlers keep them.
 */
function ap_vaak_pretty_profile_key_from_request(): ?string
{
    $fromQuery = ap_vaak_normalize_actor_key((string) ($_GET['local_key'] ?? $_GET['vaak_local_key'] ?? ''));
    if ($fromQuery !== '') {
        return $fromQuery;
    }
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    if (preg_match('#^/vaak/users/([A-Za-z0-9_]+)/?$#', $path, $m)) {
        return strtolower($m[1]);
    }
    if (!preg_match('#^/(?:users/|@)([A-Za-z0-9_]+)/?$#', $path, $m)) {
        return null;
    }
    $key = strtolower($m[1]);
    // vaak.monster /users/{key} is the domain-mask alias for every method.
    if (ap_vaak_is_vaak_host() && str_starts_with($path, '/users/')) {
        return $key;
    }
    // mkultra /users/{key} and /@{key} are the links Mastodon opens.
    // A browser gets the on-VAAK guest view. Subpaths never match here.
    if (!ap_vaak_browser_profile_navigation()) {
        return null;
    }
    return $key;
}

/**
 * Pretty profile path for local accounts. Always the /vaak/ app path.
 *
 * @param array<string, scalar|null> $query
 */
function ap_vaak_pretty_profile_path(string $actorKey, array $query = []): string
{
    $actorKey = ap_vaak_normalize_actor_key($actorKey);
    if ($actorKey === '') {
        return '/vaak/?view=home';
    }
    $path = '/vaak/users/' . rawurlencode($actorKey);
    $q = [];
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $q[(string) $key] = $value;
    }
    return $q === [] ? $path : ($path . '?' . http_build_query($q));
}

/**
 * Safe post-login return path (same-origin relative only).
 * Accepts /users/{key}, /vaak/users/{key}, and /vaak/?… app URLs.
 */
function ap_vaak_safe_next_path(?string $raw): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '' || strlen($raw) > 512 || str_contains($raw, "\n") || str_contains($raw, "\r")) {
        return null;
    }
    if (!str_starts_with($raw, '/')) {
        return null;
    }
    if (str_starts_with($raw, '//') || str_contains($raw, '://')) {
        return null;
    }
    $path = parse_url($raw, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return null;
    }
    if (preg_match('#^/users/[A-Za-z0-9_]+/?$#', $path)) {
        return $raw;
    }
    if (preg_match('#^/vaak/users/[A-Za-z0-9_]+/?$#', $path)) {
        return $raw;
    }
    if ($path === '/vaak' || $path === '/vaak/' || str_starts_with($path, '/vaak/')) {
        return $raw;
    }
    return null;
}

/**
 * Boot on-VAAK remote_profile for a local actor (logged-in or guest).
 * Does not return on success.
 */
function ap_vaak_boot_pretty_profile(string $actorKey): void
{
    $actorKey = ap_vaak_normalize_actor_key($actorKey);
    if ($actorKey === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Profile not found';
        exit;
    }
    if (!function_exists('ap_local_user_by_username') && is_file(__DIR__ . '/ap-db.php')) {
        require_once __DIR__ . '/ap-db.php';
    }
    $userRow = function_exists('ap_local_user_by_username')
        ? ap_local_user_by_username($actorKey)
        : null;
    if (!is_array($userRow) && $actorKey !== 'cmdr_nova') {
        // cmdr_nova is always local even if lookup shape differs
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Profile not found';
        exit;
    }

    require_once __DIR__ . '/ap-auth.php';
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ap_auth_bootstrap();
        if (function_exists('ap_auth_start_session')) {
            ap_auth_start_session();
        }
    }

    $_GET['view'] = 'remote_profile';
    $_GET['actor'] = 'https://mkultra.monster/users/' . $actorKey;
    if (!isset($_GET['from']) || trim((string) $_GET['from']) === '') {
        $_GET['from'] = 'home';
    }

    $sessionUser = ap_auth_current_user();
    if (!is_array($sessionUser)) {
        $GLOBALS['vaak_guest_profile'] = true;
    }

    require __DIR__ . '/ap-admin.php';
    exit;
}
