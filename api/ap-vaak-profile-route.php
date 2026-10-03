<?php
/**
 * Pretty local profile routing for on-VAAK surfaces.
 *
 * Canonical app path:  /vaak/users/{key}  (mkultra.monster and vaak.monster)
 * Public alias:        /users/{key}       (vaak.monster only — domain mask)
 * Federation / HTML:   /users/{key}       (mkultra.monster — unchanged)
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
    // Domain-mask alias — only on vaak.monster (mkultra /users stays AP + public HTML).
    if (ap_vaak_is_vaak_host() && preg_match('#^/users/([A-Za-z0-9_]+)/?$#', $path, $m)) {
        return strtolower($m[1]);
    }
    return null;
}

/**
 * Host-aware pretty profile path for local accounts.
 *
 * @param array<string, scalar|null> $query
 */
function ap_vaak_pretty_profile_path(string $actorKey, array $query = []): string
{
    $actorKey = ap_vaak_normalize_actor_key($actorKey);
    if ($actorKey === '') {
        return ap_vaak_is_vaak_host() ? '/vaak/' : '/vaak/?view=home';
    }
    $path = ap_vaak_is_vaak_host()
        ? '/users/' . rawurlencode($actorKey)
        : '/vaak/users/' . rawurlencode($actorKey);
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
