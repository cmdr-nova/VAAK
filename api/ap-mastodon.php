<?php
/**
 * Mastodon-compatible API shim for Ice Cubes / Tusky (multi-user; token-bound actor).
 * Routes: /api/v1/*, /api/v2/*, /oauth/*
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-auth.php';
require_once __DIR__ . '/ap-r2.php';
require_once __DIR__ . '/ap-masto-entities.php';
require_once __DIR__ . '/ap-lists.php';

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once __DIR__ . '/ap-inbox.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';

// CORS for mobile apps (preflight)
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key');
    http_response_code(204);
    exit;
}
header('Access-Control-Allow-Origin: *');

try {
    ap_masto_dispatch($method, $path);
} catch (Throwable $e) {
    error_log('[ap-mastodon] ' . $e->getMessage());
    ap_masto_json(['error' => 'Internal server error'], 500);
}

function ap_masto_dispatch(string $method, string $path): void
{
    // ----- OAuth -----
    if ($path === '/oauth/authorize') {
        ap_masto_oauth_authorize($method);
        return;
    }
    if ($path === '/oauth/token' && $method === 'POST') {
        ap_masto_oauth_token();
        return;
    }
    if ($path === '/oauth/revoke' && $method === 'POST') {
        ap_masto_oauth_revoke();
        return;
    }

    // ----- Instance (public) -----
    if ($path === '/api/v1/instance' && ($method === 'GET' || $method === 'HEAD')) {
        ap_masto_json(ap_masto_instance_v1());
        return;
    }
    if ($path === '/api/v2/instance' && ($method === 'GET' || $method === 'HEAD')) {
        ap_masto_json(ap_masto_instance_v2());
        return;
    }

    // ----- App registration (public) -----
    if ($path === '/api/v1/apps' && $method === 'POST') {
        ap_masto_apps_create();
        return;
    }
    if ($path === '/api/v1/apps/verify_credentials' && $method === 'GET') {
        $token = ap_masto_require_token(null); // any token / or client credentials later
        $app = ap_oauth_app_by_client_id((string) $token['client_id']);
        require_once __DIR__ . '/ap-webpush.php';
        ap_masto_json([
            'name' => $app['client_name'] ?? 'app',
            'website' => $app['website'] ?? null,
            'vapid_key' => ap_webpush_vapid_public_key(),
        ]);
        return;
    }

    // ----- Authenticated API -----
    if (str_starts_with($path, '/api/v1/') || str_starts_with($path, '/api/v2/')) {
        ap_masto_api($method, $path);
        return;
    }

    ap_masto_json(['error' => 'Not found'], 404);
}

/**
 * Parse Mastodon notification type filters from query string.
 * Supports types[]=… / types=a,b and exclude_types[]=… / exclude_types=a,b.
 *
 * IMPORTANT: grouped_types[] is NOT a filter. In Mastodon v2 it only says which
 * types may be collapsed into groups (favourite/follow/reblog). Ice Cubes always
 * sends grouped_types[]=favourite&follow&reblog — treating that as `types` was
 * hiding mentions/quotes/polls from the notifications tab.
 *
 * @return array{0:list<string>,1:list<string>} [types, exclude]
 */
function ap_masto_parse_notification_type_filters(): array
{
    $parse = static function (string $key, string $altKey): array {
        $out = [];
        if (isset($_GET[$key]) && is_array($_GET[$key])) {
            foreach ($_GET[$key] as $t) {
                if (is_string($t) && $t !== '') {
                    $out[] = $t;
                }
            }
        } elseif (isset($_GET[$altKey]) && is_array($_GET[$altKey])) {
            foreach ($_GET[$altKey] as $t) {
                if (is_string($t) && $t !== '') {
                    $out[] = $t;
                }
            }
        } elseif (isset($_GET[$key]) && is_string($_GET[$key]) && $_GET[$key] !== '') {
            foreach (explode(',', $_GET[$key]) as $t) {
                $t = trim($t);
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        }
        return array_values(array_unique($out));
    };
    return [$parse('types', 'types[]'), $parse('exclude_types', 'exclude_types[]')];
}

/** Default accept-everything notification policy (Mastodon 4.3+ / Ice Cubes). */
function ap_masto_notification_policy(): array
{
    return [
        'for_not_following' => 'accept',
        'for_not_followers' => 'accept',
        'for_new_accounts' => 'accept',
        'for_private_mentions' => 'accept',
        'for_limited_accounts' => 'accept',
        // Legacy boolean fields some clients still read
        'filter_not_following' => false,
        'filter_not_followers' => false,
        'filter_new_accounts' => false,
        'filter_private_mentions' => false,
        'summary' => [
            'pending_requests_count' => 0,
            'pending_notifications_count' => 0,
        ],
    ];
}

function ap_masto_json(mixed $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    // INVALID_UTF8_SUBSTITUTE: never emit an empty body (Ice Cubes →
    // "The data couldn't be read because it isn't in the correct format").
    $json = json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        error_log('[ap-masto] json_encode failed: ' . json_last_error_msg());
        http_response_code(500);
        echo '{"error":"JSON encode failed"}';
        exit;
    }
    echo $json;
    exit;
}

/**
 * Mastodon-style Link pagination for timeline arrays (Ice Cubes uses these).
 *
 * @param list<array<string,mixed>> $statuses
 * @param array<string,scalar|null> $extraQuery
 */
function ap_masto_json_timeline(array $statuses, string $path, int $limit, array $extraQuery = []): void
{
    if ($statuses) {
        $first = (string) ($statuses[0]['id'] ?? '');
        $last = (string) ($statuses[count($statuses) - 1]['id'] ?? '');
        $base = 'https://mkultra.monster' . $path;
        $parts = [];
        if ($last !== '') {
            $q = array_merge(['limit' => $limit, 'max_id' => $last], $extraQuery);
            $parts[] = '<' . $base . '?' . http_build_query($q) . '>; rel="next"';
        }
        if ($first !== '') {
            $q = array_merge(['limit' => $limit, 'min_id' => $first], $extraQuery);
            $parts[] = '<' . $base . '?' . http_build_query($q) . '>; rel="prev"';
        }
        if ($parts) {
            header('Link: ' . implode(', ', $parts));
        }
    }
    ap_masto_json($statuses);
}

/** Short-lived timeline JSON cache dir (Ice Cubes polls aggressively). */
function ap_masto_timeline_cache_dir(): string
{
    $dir = '/var/lib/mkultra/ap/timeline-json-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return is_dir($dir) && is_writable($dir) ? $dir : sys_get_temp_dir();
}

/**
 * Serve a cached timeline JSON body when fresh. Returns true if responded.
 *
 * @param array<string,scalar|null> $extraQuery
 */
function ap_masto_timeline_cache_try(string $path, int $limit, ?string $maxId, ?string $sinceId, array $extraQuery = [], int $ttlSec = 20): bool
{
    // Only cache "head" polls (no max_id scroll pages) — those are Ice Cubes' frequent refresh.
    if ($maxId !== null && $maxId !== '') {
        return false;
    }
    $owner = function_exists('ap_db_masto_owner_user_id') ? ap_db_masto_owner_user_id() : 0;
    $key = hash('sha256', json_encode([
        'u' => $owner,
        'p' => $path,
        'l' => $limit,
        's' => $sinceId,
        'x' => $extraQuery,
    ], JSON_UNESCAPED_SLASHES) ?: '');
    $file = ap_masto_timeline_cache_dir() . '/tl_' . $key . '.json';
    if (!is_file($file)) {
        return false;
    }
    $age = time() - (int) @filemtime($file);
    if ($age < 0 || $age >= $ttlSec) {
        return false;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return false;
    }
    $metaFile = $file . '.link';
    $link = is_file($metaFile) ? trim((string) @file_get_contents($metaFile)) : '';
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('X-VAAK-TL-Cache: hit');
    if ($link !== '') {
        header('Link: ' . $link);
    }
    echo $raw;
    exit;
}

/**
 * @param list<array<string,mixed>> $statuses
 * @param array<string,scalar|null> $extraQuery
 */
function ap_masto_timeline_cache_store(array $statuses, string $path, int $limit, ?string $maxId, ?string $sinceId, array $extraQuery = []): void
{
    if ($maxId !== null && $maxId !== '') {
        return;
    }
    $owner = function_exists('ap_db_masto_owner_user_id') ? ap_db_masto_owner_user_id() : 0;
    $key = hash('sha256', json_encode([
        'u' => $owner,
        'p' => $path,
        'l' => $limit,
        's' => $sinceId,
        'x' => $extraQuery,
    ], JSON_UNESCAPED_SLASHES) ?: '');
    $file = ap_masto_timeline_cache_dir() . '/tl_' . $key . '.json';
    $json = json_encode(
        $statuses,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($json) || $json === '') {
        return;
    }
    @file_put_contents($file, $json, LOCK_EX);
    // Persist Link header bits for cache hits.
    $link = '';
    if ($statuses) {
        $first = (string) ($statuses[0]['id'] ?? '');
        $last = (string) ($statuses[count($statuses) - 1]['id'] ?? '');
        $base = 'https://mkultra.monster' . $path;
        $parts = [];
        if ($last !== '') {
            $q = array_merge(['limit' => $limit, 'max_id' => $last], $extraQuery);
            $parts[] = '<' . $base . '?' . http_build_query($q) . '>; rel="next"';
        }
        if ($first !== '') {
            $q = array_merge(['limit' => $limit, 'min_id' => $first], $extraQuery);
            $parts[] = '<' . $base . '?' . http_build_query($q) . '>; rel="prev"';
        }
        $link = implode(', ', $parts);
    }
    @file_put_contents($file . '.link', $link, LOCK_EX);
}

function ap_masto_input(): array
{
    $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (str_contains($ct, 'application/json')) {
        $raw = file_get_contents('php://input') ?: '';
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }
    return array_merge($_GET, $_POST);
}

/** Raw Bearer access token from Authorization header (plaintext). */
function ap_masto_bearer_raw(): string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!is_string($auth) || $auth === '') {
        return '';
    }
    if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
        return $m[1];
    }
    return '';
}

function ap_masto_require_token(?string $needScope): array
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $token = ap_oauth_token_lookup(is_string($auth) ? $auth : null);
    if (!$token) {
        ap_masto_json(['error' => 'The access token is invalid'], 401);
    }
    $scopes = (string) ($token['scopes'] ?? '');
    // Refuse legacy/app-only tokens from ever acting as the user
    if ($needScope !== null && (str_starts_with($scopes, 'app:') || $scopes === '')) {
        ap_masto_json(['error' => 'This method requires an authenticated user'], 403);
    }
    if ($needScope !== null && !ap_oauth_scopes_allow($scopes, $needScope)) {
        ap_masto_json(['error' => 'This action is outside the authorized scopes'], 403);
    }
    // Bind request to the token's ap_users row.
    // Legacy NULL user_id tokens are cmdr_nova-only (pre-multi-user Ice Cubes).
    $userId = (int) ($token['user_id'] ?? 0);
    if ($userId < 1) {
        $userId = ap_db_cmdr_nova_user_id();
    }
    $user = ap_auth_user_by_id($userId);
    if (!$user || !empty($user['disabled_at'])) {
        // Never leave the request unbound (would tempt cmdr fallbacks elsewhere).
        ap_masto_json(['error' => 'The access token is invalid'], 401);
    }
    $GLOBALS['vaak_user'] = $user;
    $GLOBALS['vaak_owner_id'] = (int) $user['id'];
    $GLOBALS['vaak_actor_key'] = (string) $user['actor_key'];
    $GLOBALS['vaak_actor_id'] = rtrim((string) $user['actor_id'], '/');
    if (function_exists('ap_request_actor_set')) {
        ap_request_actor_set((string) $user['actor_key']);
    }
    return $token;
}

/** OAuth authorize brute-force guard (per IP, file-backed). */
function ap_masto_oauth_rate_path(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $dir = '/tmp/ap-oauth-rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/' . hash('sha256', $ip) . '.json';
}

/** @return bool true if attempts are still allowed */
function ap_masto_oauth_rate_ok(bool $clear = false): bool
{
    $path = ap_masto_oauth_rate_path();
    if ($clear) {
        @unlink($path);
        return true;
    }
    if (!is_file($path)) {
        return true;
    }
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        @unlink($path);
        return true;
    }
    $fails = (int) ($data['fails'] ?? 0);
    $first = (int) ($data['first'] ?? 0);
    $window = 900; // 15 minutes
    if ($first > 0 && (time() - $first) > $window) {
        @unlink($path);
        return true;
    }
    return $fails < 8;
}

function ap_masto_oauth_rate_fail(): void
{
    $path = ap_masto_oauth_rate_path();
    $fails = 0;
    $first = time();
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data)) {
            $fails = (int) ($data['fails'] ?? 0);
            $first = (int) ($data['first'] ?? time());
            if ((time() - $first) > 900) {
                $fails = 0;
                $first = time();
            }
        }
    }
    $fails++;
    @file_put_contents($path, json_encode(['fails' => $fails, 'first' => $first]), LOCK_EX);
    @chmod($path, 0600);
}

function ap_masto_apps_create(): void
{
    // Light per-IP cap so anonymous app registration cannot flood the DB
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $dir = '/tmp/ap-apps-rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $path = $dir . '/' . hash('sha256', $ip) . '.json';
    $count = 0;
    $first = time();
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data)) {
            $count = (int) ($data['count'] ?? 0);
            $first = (int) ($data['first'] ?? time());
            if ((time() - $first) > 3600) {
                $count = 0;
                $first = time();
            }
        }
    }
    if ($count >= 20) {
        ap_masto_json(['error' => 'Too many apps created from this IP. Try again later.'], 429);
    }

    $in = ap_masto_input();
    $name = (string) ($in['client_name'] ?? '');
    $redirect = (string) ($in['redirect_uris'] ?? $in['redirect_uri'] ?? '');
    $scopes = (string) ($in['scopes'] ?? 'read');
    $website = isset($in['website']) ? (string) $in['website'] : null;
    $res = ap_oauth_app_create($name, $redirect, $scopes, $website);
    if (empty($res['ok'])) {
        ap_masto_json(['error' => $res['error'] ?? 'Could not create app'], 400);
    }
    @file_put_contents($path, json_encode(['count' => $count + 1, 'first' => $first]), LOCK_EX);
    @chmod($path, 0600);
    ap_masto_json([
        'id' => (string) $res['id'],
        'name' => $name,
        'website' => $website,
        'redirect_uri' => $redirect,
        'client_id' => $res['client_id'],
        'client_secret' => $res['client_secret'],
        'vapid_key' => (static function (): string {
            require_once __DIR__ . '/ap-webpush.php';
            return ap_webpush_vapid_public_key();
        })(),
    ]);
}

/** Prefer POST body on authorize submit so hidden fields win over query string. */
function ap_masto_oauth_param(string $key, string $default = ''): string
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && array_key_exists($key, $_POST)) {
        return (string) $_POST[$key];
    }
    return (string) ($_GET[$key] ?? $_POST[$key] ?? $default);
}

function ap_masto_oauth_authorize(string $method): void
{
    $clientId = ap_masto_oauth_param('client_id');
    $redirectUri = ap_masto_oauth_param('redirect_uri');
    $scope = ap_oauth_normalize_scopes(ap_masto_oauth_param('scope', 'read'));
    $state = ap_masto_oauth_param('state');
    $challenge = ap_masto_oauth_param('code_challenge');
    $challengeMethod = ap_masto_oauth_param('code_challenge_method');
    $responseType = ap_masto_oauth_param('response_type', 'code');

    $app = ap_oauth_app_by_client_id($clientId);
    $error = null;
    $challengeMethodNorm = strtoupper(trim($challengeMethod));
    $loginAvailable = false;
    try {
        $loginAvailable = (int) ap_db()->query(
            'SELECT COUNT(*) FROM ap_users WHERE disabled_at IS NULL'
        )->fetchColumn() > 0;
    } catch (Throwable $e) {
        $loginAvailable = false;
    }
    if (!$loginAvailable) {
        $loginAvailable = ap_app_password_is_set();
    }
    if ($responseType !== 'code') {
        $error = 'Unsupported response_type';
    } elseif (!$app) {
        $error = 'Unknown client_id';
    } elseif (!ap_oauth_redirect_allowed($app, $redirectUri)) {
        $error = 'redirect_uri mismatch';
    } elseif (!$loginAvailable) {
        $error = 'No accounts available. Create a user or set an app password in /admin → Security first.';
    } elseif ($challengeMethodNorm === 'PLAIN') {
        $error = 'PKCE method "plain" is not allowed; use S256.';
    } elseif ($challenge !== '') {
        // Optional PKCE (Ice Cubes sends none). When present, S256 only.
        if ($challengeMethodNorm !== 'S256') {
            $error = 'Only PKCE S256 is supported (or omit code_challenge).';
        }
    } elseif ($challengeMethodNorm !== '' && $challengeMethodNorm !== 'S256') {
        $error = 'Only PKCE S256 is supported.';
    }

    // Client/redirect/PKCE must be valid to show the login form (not auth failures).
    $configOk = ($error === null);

    if ($method === 'POST' && $configOk) {
        if (!ap_masto_oauth_rate_ok()) {
            $error = 'Too many attempts. Try again in a few minutes.';
        } else {
            $username = (string) ($_POST['username'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $user = ap_auth_verify_credentials($username, $password);
            if ($user === null) {
                ap_masto_oauth_rate_fail();
                $error = 'Invalid username or password';
            } else {
                ap_masto_oauth_rate_ok(true); // clear fail counter on success
                $granted = ap_oauth_normalize_scopes($scope);
                // Intersect with app's registered scopes
                $appScopes = ap_oauth_normalize_scopes((string) $app['scopes']);
                $parts = [];
                foreach (preg_split('/\s+/', $granted) ?: [] as $s) {
                    if (ap_oauth_scopes_allow($appScopes, $s) || ap_oauth_scopes_allow($appScopes, explode(':', $s)[0])) {
                        $parts[] = $s;
                    }
                }
                if (!$parts) {
                    $error = 'Requested scopes do not overlap this app’s registered scopes.';
                    ap_masto_oauth_rate_fail();
                    // fall through to re-render authorize form with error
                } else {
                    $code = ap_oauth_code_create(
                        $clientId,
                        implode(' ', $parts),
                        $redirectUri,
                        $challenge !== '' ? $challenge : null,
                        $challenge !== '' ? 'S256' : null,
                        (int) $user['id']
                    );
                    if ($redirectUri === 'urn:ietf:wg:oauth:2.0:oob') {
                        header('Content-Type: text/html; charset=utf-8');
                        echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">'
                            . '<body style="font:16px/1.4 system-ui;background:#111;color:#eee;padding:2rem">'
                            . '<h1>Authorization code</h1><p>Paste this back into the app:</p>'
                            . '<code style="font-size:1.2rem;word-break:break-all">' . htmlspecialchars($code) . '</code></body>';
                        exit;
                    }
                    $sep = str_contains($redirectUri, '?') ? '&' : '?';
                    $loc = $redirectUri . $sep . 'code=' . rawurlencode($code);
                    if ($state !== '') {
                        $loc .= '&state=' . rawurlencode($state);
                    }
                    // Custom-scheme clients (Ice Cubes: icecubesapp://) — WKWebView often
                    // drops bare 302 Location to non-http(s) schemes and leaves the user
                    // on the authorize form while /oauth/token gets a bad/missing code.
                    // Serve an interstitial that also JS-navigates to the app callback.
                    $isCustomScheme = !str_starts_with($redirectUri, 'https://')
                        && !str_starts_with($redirectUri, 'http://')
                        && $redirectUri !== 'urn:ietf:wg:oauth:2.0:oob';
                    if ($isCustomScheme) {
                        // 200 (not 302): WKWebView often ignores Location: to custom schemes.
                        // Rendering this page lets meta-refresh / JS / tap open Ice Cubes.
                        http_response_code(200);
                        header('Content-Type: text/html; charset=utf-8');
                        $safeLoc = htmlspecialchars($loc, ENT_QUOTES, 'UTF-8');
                        $jsLoc = json_encode($loc, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
                        echo '<!doctype html><html><head><meta charset="utf-8">'
                            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                            . '<meta http-equiv="refresh" content="0;url=' . $safeLoc . '">'
                            . '<title>Returning to app…</title>'
                            . '<style>body{font:16px/1.45 system-ui,sans-serif;background:#0b0c0f;color:#e8e8e8;margin:0;padding:2rem}'
                            . 'a{color:#8bf}</style></head><body>'
                            . '<p>Authorized. Returning to Ice Cubes…</p>'
                            . '<p><a id="go" href="' . $safeLoc . '">Tap here if the app doesn’t open</a></p>'
                            . '<script>location.replace(' . $jsLoc . ');</script>'
                            . '</body></html>';
                        exit;
                    }
                    header('Location: ' . $loc, true, 302);
                    exit;
                }
            }
        }
    }

    $appName = htmlspecialchars((string) ($app['client_name'] ?? 'Unknown app'), ENT_QUOTES, 'UTF-8');
    $errHtml = $error ? '<p style="color:#f88">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>' : '';
    // Hard fail UX: never render a password form when client/redirect is invalid
    // (avoids phishing-style pages that look legit but can't succeed anyway).
    $canAuth = $configOk;
    $postedUser = htmlspecialchars((string) ($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Authorize · mkultra.monster</title>'
        . '<style>body{font:16px/1.45 system-ui,sans-serif;background:#0b0c0f;color:#e8e8e8;margin:0;padding:2rem}'
        . '.card{max-width:420px;margin:0 auto;background:#16181d;border:1px solid #2a2e37;border-radius:14px;padding:1.5rem}'
        . 'h1{font-size:1.25rem;margin:0 0 .5rem}p{color:#9aa}label{display:block;margin-top:.6rem;color:#ccc;font-size:.9rem}'
        . 'input,button{width:100%;box-sizing:border-box;margin:.4rem 0;padding:.75rem;border-radius:10px;border:1px solid #333;background:#0c0c0c;color:#fff;font:inherit}'
        . 'button{background:#8bf;color:#000;border:0;font-weight:600;cursor:pointer;margin-top:.75rem}button:hover{filter:brightness(1.05)}</style></head><body><div class="card">'
        . '<h1>Authorize ' . $appName . '</h1>'
        . '<p>Sign in with your <strong>mkultra.monster</strong> account to allow this app to use it.</p>'
        . '<p>Scopes: <code>' . htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') . '</code></p>'
        . ($canAuth ? '<p>Redirect: <code style="word-break:break-all">' . htmlspecialchars($redirectUri, ENT_QUOTES, 'UTF-8') . '</code></p>' : '')
        . $errHtml;
    if ($canAuth) {
        echo '<form method="post">'
            . '<input type="hidden" name="client_id" value="' . htmlspecialchars($clientId, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="redirect_uri" value="' . htmlspecialchars($redirectUri, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="scope" value="' . htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="state" value="' . htmlspecialchars($state, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="response_type" value="code">'
            . ($challenge !== '' ? '<input type="hidden" name="code_challenge" value="' . htmlspecialchars($challenge, ENT_QUOTES, 'UTF-8') . '">' : '')
            . ($challengeMethod !== '' ? '<input type="hidden" name="code_challenge_method" value="' . htmlspecialchars($challengeMethod, ENT_QUOTES, 'UTF-8') . '">' : '')
            . '<label>Username<br><input type="text" name="username" required autocomplete="username" placeholder="username or email" value="' . $postedUser . '"></label>'
            . '<label>Password<br><input type="password" name="password" required autocomplete="current-password" placeholder="Account password (admins may use app password)"></label>'
            . '<button type="submit">Authorize</button>'
            . '</form>';
    } else {
        echo '<p style="margin-top:1rem">Fix the client configuration and try again from the app.</p>';
    }
    echo '</div></body></html>';
    exit;
}

function ap_masto_oauth_token(): void
{
    $in = ap_masto_input();
    // Accept camelCase too (defensive; Ice Cubes normally snake_cases JSON)
    foreach (
        [
            'grant_type' => 'grantType',
            'client_id' => 'clientId',
            'client_secret' => 'clientSecret',
            'redirect_uri' => 'redirectUri',
            'code_verifier' => 'codeVerifier',
            'refresh_token' => 'refreshToken',
        ] as $snake => $camel
    ) {
        if ((!isset($in[$snake]) || $in[$snake] === '') && isset($in[$camel])) {
            $in[$snake] = $in[$camel];
        }
    }
    $grant = (string) ($in['grant_type'] ?? '');
    $clientId = (string) ($in['client_id'] ?? '');
    $clientSecret = (string) ($in['client_secret'] ?? '');
    // HTTP Basic client auth (Mastodon-compatible)
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (($clientId === '' || $clientSecret === '') && is_string($auth) && preg_match('/^Basic\s+(\S+)$/i', $auth, $bm)) {
        $decoded = base64_decode($bm[1], true);
        if (is_string($decoded) && str_contains($decoded, ':')) {
            [$basicId, $basicSecret] = explode(':', $decoded, 2);
            if ($clientId === '') {
                $clientId = $basicId;
            }
            if ($clientSecret === '') {
                $clientSecret = $basicSecret;
            }
        }
    }
    $app = ap_oauth_app_by_client_id($clientId);
    if (!$app || !ap_oauth_app_check_secret($app, $clientSecret)) {
        ap_masto_json(['error' => 'invalid_client'], 401);
    }

    if ($grant === 'authorization_code') {
        $code = (string) ($in['code'] ?? '');
        $redirect = (string) ($in['redirect_uri'] ?? '');
        $verifier = isset($in['code_verifier']) ? (string) $in['code_verifier'] : null;
        $row = ap_oauth_code_consume($code, $clientId, $redirect, $verifier);
        if (!$row) {
            $why = ap_oauth_code_consume_debug($code, $clientId, $redirect, $verifier);
            try {
                file_put_contents(
                    '/tmp/oauth-token-debug.jsonl',
                    json_encode([
                        't' => gmdate('c'),
                        'event' => 'invalid_grant',
                        'why' => $why,
                        'client_prefix' => substr($clientId, 0, 12),
                        'redirect_uri' => $redirect,
                        'code_len' => strlen($code),
                    ], JSON_UNESCAPED_SLASHES) . "\n",
                    FILE_APPEND | LOCK_EX
                );
            } catch (Throwable $e) {
            }
            ap_masto_json([
                'error' => 'invalid_grant',
                'error_description' => $why,
            ], 400);
        }
        $tok = ap_oauth_token_create(
            $clientId,
            (string) $row['scopes'],
            isset($row['user_id']) ? (int) $row['user_id'] : null
        );
        ap_masto_json($tok);
    }

    if ($grant === 'refresh_token') {
        $refresh = (string) ($in['refresh_token'] ?? '');
        $tok = ap_oauth_token_refresh($clientId, $refresh);
        if (!$tok) {
            ap_masto_json(['error' => 'invalid_grant'], 400);
        }
        ap_masto_json($tok);
    }

    if ($grant === 'client_credentials') {
        // Do not mint tokens that can act as @cmdr_nova. Ice Cubes uses
        // authorization_code after the app-password gate.
        ap_masto_json([
            'error' => 'unsupported_grant_type',
            'error_description' => 'Use authorization_code with user consent',
        ], 400);
    }

    ap_masto_json(['error' => 'unsupported_grant_type'], 400);
}

function ap_masto_oauth_revoke(): void
{
    $in = ap_masto_input();
    $token = (string) ($in['token'] ?? '');
    if ($token !== '') {
        ap_oauth_token_revoke($token);
    }
    http_response_code(200);
    header('Content-Type: application/json');
    echo '{}';
    exit;
}

function ap_masto_api(string $method, string $path): void
{
    // Public-ish stubs that Ice Cubes hits before/without caring much
    if ($method === 'GET' && in_array($path, [
        '/api/v1/custom_emojis',
        '/api/v1/announcements',
        '/api/v1/filters',
    ], true)) {
        ap_masto_json([]);
        return;
    }

    // ----- Lists (private follow subsets; Library → Lists) -----
    if ($path === '/api/v1/lists' && $method === 'GET') {
        ap_masto_require_token('read:lists');
        $out = [];
        foreach (ap_lists_all() as $row) {
            $out[] = ap_list_to_masto($row);
        }
        ap_masto_json($out);
        return;
    }
    if ($path === '/api/v1/lists' && $method === 'POST') {
        ap_masto_require_token('write:lists');
        $in = ap_masto_input();
        $title = (string) ($in['title'] ?? $_POST['title'] ?? '');
        $policy = (string) ($in['replies_policy'] ?? $_POST['replies_policy'] ?? 'list');
        $exclusive = false;
        if (array_key_exists('exclusive', $in)) {
            $exclusive = !empty($in['exclusive']) && !in_array((string) $in['exclusive'], ['0', 'false', ''], true);
        } elseif (isset($_POST['exclusive'])) {
            $exclusive = !in_array((string) $_POST['exclusive'], ['0', 'false', ''], true);
        }
        $res = ap_list_create($title, $policy, $exclusive);
        if (empty($res['ok'])) {
            ap_masto_json(['error' => $res['error'] ?? 'Could not create list'], 422);
            return;
        }
        ap_masto_json(ap_list_to_masto($res['list'] ?? ap_list_by_id((int) ($res['id'] ?? 0)) ?? []));
        return;
    }
    if (preg_match('#^/api/v1/lists/(\d+)$#', $path, $lm)) {
        $listId = (int) $lm[1];
        if ($method === 'GET') {
            ap_masto_require_token('read:lists');
            $row = ap_list_by_id($listId);
            if (!$row) {
                ap_masto_json(['error' => 'Record not found'], 404);
                return;
            }
            ap_masto_json(ap_list_to_masto($row));
            return;
        }
        if ($method === 'PUT' || $method === 'PATCH') {
            ap_masto_require_token('write:lists');
            $in = ap_masto_input();
            $fields = [];
            if (array_key_exists('title', $in) || isset($_POST['title'])) {
                $fields['title'] = (string) ($in['title'] ?? $_POST['title'] ?? '');
            }
            if (array_key_exists('replies_policy', $in) || isset($_POST['replies_policy'])) {
                $fields['replies_policy'] = (string) ($in['replies_policy'] ?? $_POST['replies_policy'] ?? 'list');
            }
            if (array_key_exists('exclusive', $in) || isset($_POST['exclusive'])) {
                $raw = $in['exclusive'] ?? $_POST['exclusive'] ?? false;
                $fields['exclusive'] = !empty($raw) && !in_array((string) $raw, ['0', 'false', ''], true);
            }
            $res = ap_list_update($listId, $fields);
            if (empty($res['ok'])) {
                $code = (($res['error'] ?? '') === 'Record not found') ? 404 : 422;
                ap_masto_json(['error' => $res['error'] ?? 'Could not update list'], $code);
                return;
            }
            ap_masto_json(ap_list_to_masto($res['list'] ?? []));
            return;
        }
        if ($method === 'DELETE') {
            ap_masto_require_token('write:lists');
            $res = ap_list_delete($listId);
            if (empty($res['ok'])) {
                $code = (($res['error'] ?? '') === 'Record not found') ? 404 : 422;
                ap_masto_json(['error' => $res['error'] ?? 'Could not delete list'], $code);
                return;
            }
            http_response_code(200);
            header('Content-Type: application/json');
            echo '{}';
            exit;
        }
    }
    if (preg_match('#^/api/v1/lists/(\d+)/accounts$#', $path, $la)) {
        $listId = (int) $la[1];
        if ($method === 'GET') {
            ap_masto_require_token('read:lists');
            if (!ap_list_by_id($listId)) {
                ap_masto_json(['error' => 'Record not found'], 404);
                return;
            }
            $limit = isset($_GET['limit']) ? max(1, min(80, (int) $_GET['limit'])) : 40;
            $out = [];
            foreach (array_slice(ap_list_accounts($listId), 0, $limit) as $row) {
                $aid = (string) ($row['actor_id'] ?? '');
                if ($aid === '') {
                    continue;
                }
                $out[] = ap_masto_remote_account($aid);
            }
            ap_masto_json($out);
            return;
        }
        if ($method === 'POST' || $method === 'DELETE') {
            ap_masto_require_token('write:lists');
            if (!ap_list_by_id($listId)) {
                ap_masto_json(['error' => 'Record not found'], 404);
                return;
            }
            $in = ap_masto_input();
            $accountIds = $in['account_ids'] ?? $_POST['account_ids'] ?? [];
            if (!is_array($accountIds)) {
                $accountIds = [$accountIds];
            }
            // Flatten account_ids[] style
            if (isset($_POST['account_ids']) && is_array($_POST['account_ids'])) {
                $accountIds = $_POST['account_ids'];
            }
            $errors = [];
            foreach ($accountIds as $accId) {
                $accId = (string) $accId;
                $actor = ap_masto_actor_id_from_account_id($accId);
                if (!$actor) {
                    $errors[] = 'Unknown account ' . $accId;
                    continue;
                }
                if ($method === 'POST') {
                    $res = ap_list_add_account($listId, $actor, false);
                    if (empty($res['ok'])) {
                        $errors[] = $res['error'] ?? ('Could not add ' . $accId);
                    }
                } else {
                    $res = ap_list_remove_account($listId, $actor);
                    if (empty($res['ok'])) {
                        $errors[] = $res['error'] ?? ('Could not remove ' . $accId);
                    }
                }
            }
            if ($errors) {
                ap_masto_json(['error' => implode('; ', array_slice($errors, 0, 3))], 422);
                return;
            }
            http_response_code(200);
            header('Content-Type: application/json');
            echo '{}';
            exit;
        }
    }
    if (preg_match('#^/api/v1/accounts/(\d+)/lists$#', $path, $al) && $method === 'GET') {
        ap_masto_require_token('read:lists');
        $actor = ap_masto_actor_id_from_account_id($al[1]);
        if (!$actor) {
            ap_masto_json([]);
            return;
        }
        $out = [];
        foreach (ap_lists_for_actor($actor) as $row) {
            $out[] = ap_list_to_masto($row);
        }
        ap_masto_json($out);
        return;
    }
    if (preg_match('#^/api/v1/timelines/list/(\d+)$#', $path, $tl) && $method === 'GET') {
        ap_masto_require_token('read:lists');
        $listId = (int) $tl[1];
        if (!ap_list_by_id($listId)) {
            ap_masto_json(['error' => 'Record not found'], 404);
            return;
        }
        $limit = isset($_GET['limit']) ? max(1, min(80, (int) $_GET['limit'])) : 40;
        $maxId = isset($_GET['max_id']) ? (string) $_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) ? (string) $_GET['since_id'] : null;
        ap_masto_json_timeline(
            ap_masto_timeline_list($listId, $limit, $maxId, $sinceId),
            '/api/v1/timelines/list/' . $listId,
            $limit
        );
        return;
    }

    // Trending hashtags (Ice Cubes Explore)
    if ($method === 'GET' && ($path === '/api/v1/trends/tags' || $path === '/api/v1/trends')) {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
        $limit = max(1, min(30, $limit));
        ap_masto_json(ap_masto_trends_tags($limit));
        return;
    }

    // Trending links (Ice Cubes Explore → News)
    if ($method === 'GET' && $path === '/api/v1/trends/links') {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
        $limit = max(1, min(20, $limit));
        ap_masto_json(ap_masto_trends_links($limit));
        return;
    }

    // Trending statuses (Ice Cubes Explore → Posts)
    if ($method === 'GET' && $path === '/api/v1/trends/statuses') {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
        $limit = max(1, min(20, $limit));
        ap_masto_json(ap_masto_trends_statuses($limit));
        return;
    }

    // Explore → For You (official apps): follow suggestions, not a post feed
    if ($method === 'GET' && $path === '/api/v2/suggestions') {
        ap_masto_require_token('read');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 40;
        $limit = max(1, min(80, $limit));
        ap_masto_json(ap_masto_suggestions_v2($limit));
        return;
    }
    if ($method === 'GET' && $path === '/api/v1/suggestions') {
        ap_masto_require_token('read');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 40;
        $limit = max(1, min(80, $limit));
        ap_masto_json(ap_masto_suggestions_v1($limit));
        return;
    }
    if (preg_match('#^/api/v1/suggestions/([^/]+)$#', $path, $sm) && $method === 'DELETE') {
        ap_masto_require_token('read');
        $raw = rawurldecode($sm[1]);
        // Clients pass account id (numeric snowflake or our remote id). Resolve to actor URL when possible.
        $actorId = $raw;
        if (ctype_digit($raw) || preg_match('/^\d+$/', $raw)) {
            $acct = ap_masto_account_by_id($raw);
            if (is_array($acct) && !empty($acct['url'])) {
                $actorId = (string) $acct['url'];
            } elseif (is_array($acct) && !empty($acct['uri'])) {
                $actorId = (string) $acct['uri'];
            }
        }
        ap_masto_suggestion_dismiss($actorId);
        ap_masto_json([]);
        return;
    }

    if ($path === '/api/v1/accounts/verify_credentials' && $method === 'GET') {
        ap_masto_require_token('read');
        ap_masto_json(ap_masto_account());
        return;
    }

    if (preg_match('#^/api/v1/accounts/(\d+)$#', $path, $m) && $method === 'GET') {
        ap_masto_require_token('read');
        $acct = ap_masto_account_by_id($m[1]);
        if (!$acct) {
            ap_masto_json(['error' => 'Record not found'], 404);
        }
        ap_masto_json($acct);
        return;
    }

    // Profile Followers / Following tabs (was catch-all [] → "No accounts found")
    if (preg_match('#^/api/v1/accounts/(\d+)/(followers|following)$#', $path, $fm) && $method === 'GET') {
        ap_masto_require_token('read');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 40;
        $localUser = function_exists('ap_masto_local_user_by_account_id')
            ? ap_masto_local_user_by_account_id($fm[1])
            : null;
        if (!is_array($localUser)) {
            // Remote / unknown: no local follow graph mirrored yet
            ap_masto_json([]);
            return;
        }
        $ownerActor = rtrim((string) ($localUser['actor_id'] ?? ''), '/');
        if ($ownerActor === '') {
            $key = (string) ($localUser['actor_key'] ?? '');
            $ownerActor = $key !== '' ? ('https://mkultra.monster/users/' . $key) : '';
        }
        ap_masto_json(ap_masto_account_follow_list($fm[2], $limit, $ownerActor !== '' ? $ownerActor : null));
        return;
    }

    if ($path === '/api/v1/accounts/relationships' && $method === 'GET') {
        ap_masto_require_token('read');
        $ids = ap_masto_parse_id_list_param();
        $out = [];
        foreach (array_unique($ids) as $id) {
            if ($id === '') {
                continue;
            }
            // Ice Cubes SearchResultsView only renders accounts that have a relationship
            // row — include self so local account search hits are visible.
            $out[] = ap_masto_relationship_for_account_id($id);
        }
        ap_masto_json($out);
        return;
    }

    // Follow / unfollow from Ice Cubes search + profile (returns Relationship).
    if (preg_match('#^/api/v1/accounts/(\d+)/follow$#', $path, $fm) && $method === 'POST') {
        ap_masto_require_token('write:follows');
        $accountId = $fm[1];
        $selfId = function_exists('ap_masto_session_account_id') ? ap_masto_session_account_id() : '1';
        if ($accountId === $selfId) {
            ap_masto_json(['error' => 'Cannot follow yourself'], 422);
            return;
        }
        $actor = ap_masto_actor_id_from_account_id($accountId);
        if (!$actor) {
            ap_masto_json(['error' => 'Record not found'], 404);
            return;
        }
        if (!function_exists('ap_follow_remote_actor')) {
            if (!defined('AP_INBOX_LIB_ONLY')) {
                define('AP_INBOX_LIB_ONLY', true);
            }
            require_once __DIR__ . '/ap-inbox.php';
        }
        $res = ap_follow_remote_actor($actor, true);
        if (empty($res['ok'])) {
            ap_masto_json(['error' => $res['error'] ?? 'Follow failed'], 422);
            return;
        }
        // notify/reblogs query flags are accepted but we always show reblogs for now
        ap_masto_json(ap_masto_relationship_for_account_id($accountId));
        return;
    }
    if (preg_match('#^/api/v1/accounts/(\d+)/unfollow$#', $path, $fm) && $method === 'POST') {
        ap_masto_require_token('write:follows');
        $accountId = $fm[1];
        $selfId = function_exists('ap_masto_session_account_id') ? ap_masto_session_account_id() : '1';
        if ($accountId === $selfId) {
            ap_masto_json(ap_masto_relationship_for_account_id($selfId));
            return;
        }
        $actor = ap_masto_actor_id_from_account_id($accountId);
        if (!$actor) {
            ap_masto_json(['error' => 'Record not found'], 404);
            return;
        }
        if (!function_exists('ap_unfollow_remote_actor')) {
            if (!defined('AP_INBOX_LIB_ONLY')) {
                define('AP_INBOX_LIB_ONLY', true);
            }
            require_once __DIR__ . '/ap-inbox.php';
        }
        // Unfollow every alias IRI we might have stored under this account id
        $targets = [$actor];
        if (function_exists('ap_masto_actor_id_aliases')) {
            foreach (ap_masto_actor_id_aliases($actor) as $al) {
                $al = rtrim((string) $al, '/');
                if ($al !== '') {
                    $targets[] = $al;
                }
            }
        }
        $targets = array_values(array_unique($targets));
        $err = null;
        foreach ($targets as $t) {
            $res = ap_unfollow_remote_actor($t);
            if (empty($res['ok'])) {
                $err = $res['error'] ?? 'Unfollow failed';
            }
        }
        if ($err !== null) {
            // Still return relationship so the UI can refresh; only hard-fail if still following
            $rel = ap_masto_relationship_for_account_id($accountId);
            if (!empty($rel['following'])) {
                ap_masto_json(['error' => $err], 422);
                return;
            }
        }
        ap_masto_json(ap_masto_relationship_for_account_id($accountId));
        return;
    }

    // Mute / unmute (Ice Cubes account menu) — local only, not federated.
    // Scope write (parent) satisfies write:mutes via ap_oauth_scopes_allow.
    // Scoped to the OAuth token's ap_users row via ap_db_masto_owner_user_id().
    if (preg_match('#^/api/v1/accounts/(\d+)/(mute|unmute)$#', $path, $mm) && $method === 'POST') {
        ap_masto_require_token('write:mutes');
        $ownerId = ap_db_masto_owner_user_id();
        $actor = ap_masto_actor_id_from_account_id($mm[1]);
        if (!$actor) {
            ap_masto_json(['error' => 'Record not found'], 404);
            return;
        }
        if ($mm[2] === 'mute') {
            $notifications = true;
            $in = ap_masto_input();
            if (array_key_exists('notifications', $in)) {
                $notifications = !empty($in['notifications']);
            } elseif (isset($_POST['notifications'])) {
                $notifications = !in_array((string) $_POST['notifications'], ['0', 'false', ''], true);
            }
            $res = ap_mute_upsert($actor, $notifications, $ownerId);
            if (empty($res['ok'])) {
                ap_masto_json(['error' => $res['error'] ?? 'Mute failed'], 422);
                return;
            }
        } else {
            $res = ap_unmute($actor, $ownerId);
            if (empty($res['ok']) && ($res['error'] ?? '') !== 'Not muted.') {
                ap_masto_json(['error' => $res['error'] ?? 'Unmute failed'], 422);
                return;
            }
        }
        $acct = ap_masto_account_by_id($mm[1]) ?: ap_masto_remote_account($actor);
        ap_masto_json($acct);
        return;
    }

    if ($path === '/api/v1/mutes' && $method === 'GET') {
        ap_masto_require_token('read:mutes');
        // Scoped to the OAuth token's ap_users row.
        $ownerId = ap_db_masto_owner_user_id();
        $limit = isset($_GET['limit']) ? max(1, min(80, (int) $_GET['limit'])) : 40;
        $out = [];
        foreach (array_slice(ap_mutes_list($ownerId), 0, $limit) as $row) {
            $aid = (string) ($row['actor_id'] ?? '');
            if ($aid === '') {
                continue;
            }
            $out[] = ap_masto_remote_account($aid);
        }
        ap_masto_json($out);
        return;
    }

    // Personal blocks (Ice Cubes / Mastodon-compatible API). Local timeline hide
    // plus federated Block/Undo (needed for Bridgy Fed opt-out).
    if (preg_match('#^/api/v1/accounts/(\d+)/(block|unblock)$#', $path, $bm) && $method === 'POST') {
        ap_masto_require_token('write:blocks');
        $ownerId = ap_db_masto_owner_user_id();
        $accountId = (string) $bm[1];
        $actor = ap_masto_actor_id_from_account_id($accountId);
        if (!$actor) {
            ap_masto_json(['error' => 'Record not found'], 404);
            return;
        }
        if ($bm[2] === 'block') {
            $res = ap_user_block_add($ownerId, 'actor', $actor, 'block');
            if (empty($res['ok'])) {
                ap_masto_json(['error' => $res['error'] ?? 'Block failed'], 422);
                return;
            }
        } else {
            $row = ap_user_block_find($actor, null, $ownerId);
            if (is_array($row)) {
                $res = ap_user_block_remove($ownerId, (int) ($row['id'] ?? 0));
                if (empty($res['ok'])) {
                    ap_masto_json(['error' => $res['error'] ?? 'Unblock failed'], 422);
                    return;
                }
            }
        }
        ap_masto_json(ap_masto_relationship_for_account_id($accountId));
        return;
    }

    if ($path === '/api/v1/blocks' && $method === 'GET') {
        ap_masto_require_token('read:blocks');
        $ownerId = ap_db_masto_owner_user_id();
        $limit = isset($_GET['limit']) ? max(1, min(80, (int) $_GET['limit'])) : 40;
        $out = [];
        foreach (array_slice(ap_user_blocks_list($ownerId), 0, $limit) as $row) {
            if (!is_array($row) || (string) ($row['scope'] ?? '') !== 'actor') {
                continue;
            }
            $actor = rtrim((string) ($row['value'] ?? ''), '/');
            if ($actor !== '') {
                $out[] = ap_masto_remote_account($actor);
            }
        }
        ap_masto_json($out);
        return;
    }

    if ($path === '/api/v1/preferences' && $method === 'GET') {
        ap_masto_require_token('read');
        ap_masto_json([
            'posting:default:visibility' => 'public',
            'posting:default:sensitive' => false,
            'posting:default:language' => null,
            'reading:expand:media' => 'default',
            'reading:expand:spoilers' => false,
        ]);
        return;
    }

    if ($path === '/api/v1/markers') {
        if ($method === 'GET') {
            ap_masto_require_token('read');
            ap_masto_json(ap_masto_markers_get());
            return;
        }
        if ($method === 'POST') {
            ap_masto_require_token('write');
            $in = ap_masto_input();
            // Merge form-style nested keys from $_POST
            $in = array_merge($in, $_POST);
            ap_masto_json(ap_masto_markers_set($in));
            return;
        }
    }

    // Ice Cubes badge / unread count
    if ($path === '/api/v1/notifications/unread_count' && $method === 'GET') {
        ap_masto_require_token('read');
        ap_masto_json(['count' => ap_masto_notifications_unread_count(80)]);
        return;
    }

    if ($path === '/api/v1/notifications' && $method === 'GET') {
        ap_masto_require_token('read');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 40;
        $maxId = isset($_GET['max_id']) ? (string) $_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) ? (string) $_GET['since_id'] : null;
        if (($sinceId === null || $sinceId === '') && isset($_GET['min_id'])) {
            $sinceId = (string) $_GET['min_id'];
        }
        [$types, $exclude] = ap_masto_parse_notification_type_filters();
        ap_masto_json(ap_masto_notifications_fetch($limit, $maxId, $sinceId, $types, $exclude));
        return;
    }

    if (preg_match('#^/api/v1/notifications/(\d+)$#', $path, $nm) && $method === 'GET') {
        ap_masto_require_token('read');
        $n = ap_masto_notification_by_id($nm[1]);
        if (!$n) {
            ap_masto_json(['error' => 'Record not found'], 404);
        }
        ap_masto_json($n);
        return;
    }

    if ($path === '/api/v1/notifications/clear' && $method === 'POST') {
        ap_masto_require_token('write');
        // Soft-clear mention-backed notifications for this account + mark read
        $ownerUserId = function_exists('ap_db_default_owner_user_id') ? ap_db_default_owner_user_id() : 0;
        if ($ownerUserId > 0) {
            try {
                ap_db()->prepare(
                    'UPDATE mentions SET deleted_at = ? WHERE owner_user_id = ? AND deleted_at IS NULL'
                )->execute([ap_db_now(), $ownerUserId]);
            } catch (Throwable $e) {
                // ignore
            }
        }
        $latest = ap_masto_notifications_fetch(1);
        $last = $latest[0]['id'] ?? '0';
        ap_masto_markers_set(['notifications' => ['last_read_id' => (string) $last]]);
        ap_masto_json(new stdClass());
        return;
    }

    if (preg_match('#^/api/v1/notifications/(\d+)/dismiss$#', $path, $nm) && $method === 'POST') {
        ap_masto_require_token('write');
        if (function_exists('ap_masto_notification_dismiss')) {
            ap_masto_notification_dismiss((string) $nm[1]);
        }
        ap_masto_json(new stdClass());
        return;
    }

    // Notification policy — Ice Cubes always fetches this after /api/v2/notifications.
    // Without it the Notifications tab shows a generic error/retry loop.
    if ($path === '/api/v2/notifications/policy' || $path === '/api/v1/notifications/policy') {
        if ($method === 'GET') {
            ap_masto_require_token('read');
            ap_masto_json(ap_masto_notification_policy());
            return;
        }
        if ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
            ap_masto_require_token('write');
            // Accept updates but we don't persist filters yet — always return accept-all.
            ap_masto_json(ap_masto_notification_policy());
            return;
        }
    }

    // Filtered notification requests inbox (empty — we accept everything).
    if ($path === '/api/v1/notifications/requests' && $method === 'GET') {
        ap_masto_require_token('read');
        ap_masto_json([]);
        return;
    }

    // Grouped notifications (Mastodon 4.3+ / Ice Cubes) — real payload, not an empty stub.
    // Instance advertises api_versions.mastodon=7 so clients prefer this over v1.
    if ($path === '/api/v2/notifications' && $method === 'GET') {
        ap_masto_require_token('read');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 40;
        $maxId = isset($_GET['max_id']) ? (string) $_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) ? (string) $_GET['since_id'] : null;
        if (($sinceId === null || $sinceId === '') && isset($_GET['min_id'])) {
            $sinceId = (string) $_GET['min_id'];
        }
        [$types, $exclude] = ap_masto_parse_notification_type_filters();
        ap_masto_json(ap_masto_notifications_grouped_fetch($limit, $maxId, $sinceId, $types, $exclude));
        return;
    }

    if ($path === '/api/v2/notifications/unread_count' && $method === 'GET') {
        ap_masto_require_token('read');
        $markers = ap_masto_markers_get();
        $lastRead = '0';
        $nMark = $markers->notifications ?? null;
        if (is_array($nMark) && isset($nMark['last_read_id'])) {
            $lastRead = (string) $nMark['last_read_id'];
        } elseif (is_object($nMark) && isset($nMark->last_read_id)) {
            $lastRead = (string) $nMark->last_read_id;
        }
        [$types, $exclude] = ap_masto_parse_notification_type_filters();
        $all = ap_masto_notifications_fetch(80, null, null, $types, $exclude);
        $count = 0;
        foreach ($all as $n) {
            if ((int) ($n['id'] ?? 0) > (int) $lastRead) {
                $count++;
            }
        }
        ap_masto_json(['count' => $count]);
        return;
    }

    // Single grouped notification — only ungrouped-{id} / numeric ids (not "policy").
    if (preg_match('#^/api/v2/notifications/(ungrouped-\d+|\d+)$#', $path, $gm) && $method === 'GET') {
        ap_masto_require_token('read');
        $key = $gm[1];
        $nid = null;
        if (preg_match('/^ungrouped-(\d+)$/', $key, $um)) {
            $nid = $um[1];
        } elseif (ctype_digit($key)) {
            $nid = $key;
        }
        if ($nid === null) {
            ap_masto_json(['error' => 'Record not found'], 404);
            return;
        }
        $n = ap_masto_notification_by_id($nid);
        if (!$n) {
            ap_masto_json(['error' => 'Record not found'], 404);
            return;
        }
        $acct = $n['account'] ?? null;
        $status = $n['status'] ?? null;
        $accounts = is_array($acct) ? [$acct] : [];
        $statuses = is_array($status) ? [$status] : [];
        $acctId = is_array($acct) && isset($acct['id']) ? (string) $acct['id'] : '';
        $statusId = is_array($status) && isset($status['id']) ? (string) $status['id'] : null;
        $idInt = (int) $n['id'];
        $group = [
            'group_key' => (string) ($n['group_key'] ?? ('ungrouped-' . $n['id'])),
            'notifications_count' => 1,
            'type' => (string) $n['type'],
            'most_recent_notification_id' => $idInt,
            'page_min_id' => (string) $n['id'],
            'page_max_id' => (string) $n['id'],
            'latest_page_notification_at' => (string) $n['created_at'],
            'sample_account_ids' => $acctId !== '' ? [$acctId] : [],
        ];
        if (in_array($n['type'], ['mention', 'status', 'reblog', 'favourite', 'poll', 'update', 'quote', 'quoted_update'], true)) {
            $group['status_id'] = $statusId;
        }
        ap_masto_json([
            'accounts' => $accounts,
            'statuses' => $statuses,
            'notification_groups' => [$group],
        ]);
        return;
    }

    if ($path === '/api/v1/timelines/home' && $method === 'GET') {
        ap_masto_require_token('read');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 40;
        $maxId = isset($_GET['max_id']) ? (string) $_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) ? (string) $_GET['since_id'] : null;
        // Ice Cubes pull-to-refresh often uses min_id (same exclusive lower bound as since_id)
        if (($sinceId === null || $sinceId === '') && isset($_GET['min_id'])) {
            $sinceId = (string) $_GET['min_id'];
        }
        if (ap_masto_timeline_cache_try('/api/v1/timelines/home', $limit, $maxId, $sinceId)) {
            return;
        }
        // Home = people you follow + your own posts (never DMs)
        $homeStatuses = ap_masto_timeline_home_merged($limit, $maxId, $sinceId);
        ap_masto_timeline_cache_store($homeStatuses, '/api/v1/timelines/home', $limit, $maxId, $sinceId);
        ap_masto_json_timeline($homeStatuses, '/api/v1/timelines/home', $limit);
        return;
    }

    if ($path === '/api/v1/timelines/direct' && $method === 'GET') {
        ap_masto_require_token('read');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 40;
        $maxId = isset($_GET['max_id']) ? (string) $_GET['max_id'] : null;
        ap_masto_json(ap_masto_direct_timeline($limit, $maxId));
        return;
    }

    if ($path === '/api/v1/conversations' && $method === 'GET') {
        ap_masto_require_token('read');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 40;
        ap_masto_json(ap_masto_conversations_list($limit));
        return;
    }

    if (preg_match('#^/api/v1/conversations/(\d+)$#', $path, $cm) && $method === 'GET') {
        ap_masto_require_token('read');
        $peer = ap_dm_peer_from_conversation_id($cm[1]);
        if ($peer === null) {
            ap_masto_json(['error' => 'Record not found'], 404);
        }
        // Opening a conversation marks it read (Ice Cubes / Mastodon clients)
        try {
            ap_dm_mark_peer_read($peer);
        } catch (Throwable $e) {
            error_log('[ap-masto] dm mark read (conversation): ' . $e->getMessage());
        }
        $thread = ap_dm_thread($peer, 1);
        $last = $thread ? $thread[count($thread) - 1] : null;
        // Prefer conversation list entry
        foreach (ap_dm_conversations(80) as $c) {
            if ($c['peer_actor_id'] === $peer) {
                $last = $c['last'];
                ap_masto_json([
                    'id' => ap_dm_conversation_id($peer),
                    'unread' => false,
                    'accounts' => [ap_masto_remote_account($peer)],
                    'last_status' => ap_masto_status_from_dm($last),
                ]);
            }
        }
        ap_masto_json(['error' => 'Record not found'], 404);
        return;
    }

    if (preg_match('#^/api/v1/conversations/(\d+)/read$#', $path, $cm) && $method === 'POST') {
        ap_masto_require_token('write');
        $peer = ap_dm_peer_from_conversation_id($cm[1]);
        if ($peer === null) {
            ap_masto_json(['error' => 'Record not found'], 404);
        }
        ap_dm_mark_peer_read($peer);
        foreach (ap_dm_conversations(80) as $c) {
            if ($c['peer_actor_id'] === $peer) {
                ap_masto_json([
                    'id' => ap_dm_conversation_id($peer),
                    'unread' => false,
                    'accounts' => [ap_masto_remote_account($peer)],
                    'last_status' => ap_masto_status_from_dm($c['last']),
                ]);
            }
        }
        ap_masto_json(new stdClass());
        return;
    }

    // Hashtag timeline (Ice Cubes tag page) — must be a status array, not the catch-all []
    if (preg_match('#^/api/v1/timelines/tag/([^/]+)$#', $path, $tm) && $method === 'GET') {
        ap_masto_require_token('read');
        $tag = rawurldecode($tm[1]);
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        $maxId = isset($_GET['max_id']) ? (string) $_GET['max_id'] : null;
        ap_masto_json(ap_masto_timeline_hashtag($tag, $limit, $maxId));
        return;
    }

    // Tag metadata — Ice Cubes expects a Tag *object* (catch-all [] caused blank/retry)
    if (preg_match('#^/api/v1/tags/([^/]+)$#', $path, $tm) && $method === 'GET') {
        ap_masto_require_token('read');
        $tag = rawurldecode($tm[1]);
        $name = ap_masto_normalize_tag_name($tag);
        if ($name === '') {
            ap_masto_json(['error' => 'Record not found'], 404);
        }
        ap_masto_json(ap_masto_tag_entity($name));
        return;
    }
    if (preg_match('#^/api/v1/tags/([^/]+)/follow$#', $path, $tm) && $method === 'POST') {
        ap_masto_require_token('write');
        $res = ap_masto_tag_follow(rawurldecode($tm[1]));
        if (empty($res['ok'])) {
            ap_masto_json(['error' => $res['error'] ?? 'Could not follow tag'], 422);
        }
        ap_masto_json($res['tag']);
        return;
    }
    if (preg_match('#^/api/v1/tags/([^/]+)/unfollow$#', $path, $tm) && $method === 'POST') {
        ap_masto_require_token('write');
        $res = ap_masto_tag_unfollow(rawurldecode($tm[1]));
        if (empty($res['ok'])) {
            ap_masto_json(['error' => $res['error'] ?? 'Could not unfollow tag'], 422);
        }
        ap_masto_json($res['tag']);
        return;
    }
    if ($path === '/api/v1/followed_tags' && $method === 'GET') {
        ap_masto_require_token('read');
        // Default: return all (no artificial small cap). Client may still pass limit=.
        $limit = array_key_exists('limit', $_GET) ? (int) $_GET['limit'] : 0;
        ap_masto_json(ap_masto_followed_tags($limit));
        return;
    }

    if ($path === '/api/v1/timelines/public' && $method === 'GET') {
        // Single-user shim: require auth even for "public" timelines (Ice Cubes always sends a token).
        ap_masto_require_token('read');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 40;
        $maxId = isset($_GET['max_id']) ? (string) $_GET['max_id'] : null;
        $sinceId = isset($_GET['since_id']) ? (string) $_GET['since_id'] : null;
        if (($sinceId === null || $sinceId === '') && isset($_GET['min_id'])) {
            $sinceId = (string) $_GET['min_id'];
        }
        $onlyMedia = isset($_GET['only_media']) && ($_GET['only_media'] === '1' || $_GET['only_media'] === 'true');
        $local = isset($_GET['local']) && ($_GET['local'] === '1' || $_GET['local'] === 'true');

        if ($local) {
            // Local timeline = this single-user instance's own posts
            $rows = ap_masto_statuses_recent($limit > 0 ? $limit : 40);
            $out = [];
            $before = ($maxId !== null && $maxId !== '') ? ap_masto_status_created_at_by_id((int) $maxId) : null;
            $after = ($sinceId !== null && $sinceId !== '') ? ap_masto_status_created_at_by_id((int) $sinceId) : null;
            foreach ($rows as $r) {
                $status = ap_masto_status_from_row($r);
                $ts = (string) ($status['created_at'] ?? '');
                if ($before !== null && $ts !== '' && strcmp($ts, $before) >= 0) {
                    continue;
                }
                if ($after !== null && $ts !== '' && strcmp($ts, $after) <= 0) {
                    continue;
                }
                if ($onlyMedia && empty($status['media_attachments'])) {
                    continue;
                }
                $out[] = $status;
                if (count($out) >= $limit) {
                    break;
                }
            }
            ap_masto_json_timeline($out, '/api/v1/timelines/public', $limit, ['local' => 'true']);
            return;
        }

        // Federated/public = remote firehose + our own public posts (single-user
        // shim: Ice Cubes Local/Federated should still show admin/Ice Cubes composes).
        $extraQ = ['local' => 'false'];
        if ($onlyMedia) {
            $extraQ['only_media'] = 'true';
        }
        if (ap_masto_timeline_cache_try('/api/v1/timelines/public', $limit, $maxId, $sinceId, $extraQ)) {
            return;
        }
        $fedStatuses = ap_masto_timeline_public_merged($limit, $maxId, $sinceId, $onlyMedia);
        ap_masto_timeline_cache_store($fedStatuses, '/api/v1/timelines/public', $limit, $maxId, $sinceId, $extraQ);
        ap_masto_json_timeline($fedStatuses, '/api/v1/timelines/public', $limit, $extraQ);
        return;
    }

    if (preg_match('#^/api/v1/accounts/(\d+)/statuses$#', $path, $am) && $method === 'GET') {
        ap_masto_require_token('read');
        // Ice Cubes loads ?pinned=true first for every profile. Anything returned
        // here is shown as pinned — so only real pins (local) or [] (remote).
        $wantPinned = isset($_GET['pinned']) && ($_GET['pinned'] === '1' || $_GET['pinned'] === 'true');
        $onlyReblogs = isset($_GET['only_reblogs']) && ($_GET['only_reblogs'] === '1' || $_GET['only_reblogs'] === 'true');
        $excludeReblogs = isset($_GET['exclude_reblogs']) && ($_GET['exclude_reblogs'] === '1' || $_GET['exclude_reblogs'] === 'true');
        $onlyMedia = isset($_GET['only_media']) && ($_GET['only_media'] === '1' || $_GET['only_media'] === 'true');
        $excludeReplies = isset($_GET['exclude_replies']) && ($_GET['exclude_replies'] === '1' || $_GET['exclude_replies'] === 'true');
        $limit = isset($_GET['limit']) ? max(1, min(80, (int) $_GET['limit'])) : 40;
        $maxId = isset($_GET['max_id']) ? (string) $_GET['max_id'] : null;
        $localUser = function_exists('ap_masto_local_user_by_account_id')
            ? ap_masto_local_user_by_account_id($am[1])
            : ($am[1] === '1' ? ['actor_key' => 'cmdr_nova', 'id' => 1] : null);
        if (is_array($localUser)) {
            $actorKey = strtolower(trim((string) ($localUser['actor_key'] ?? '')));
            $ownerUserId = (int) ($localUser['id'] ?? 0);
            if ($actorKey === 'cmdr_nova') {
                // Stable Mastodon id "1" even if ap_users.id differs
                $ownerUserId = function_exists('ap_db_cmdr_nova_user_id') ? ap_db_cmdr_nova_user_id() : $ownerUserId;
            }
            if ($wantPinned) {
                $rows = ap_masto_pinned_statuses(5, $actorKey !== '' ? $actorKey : null);
                $out = [];
                foreach ($rows as $r) {
                    $out[] = ap_masto_status_from_row($r);
                }
                ap_masto_json($out);
                return;
            }
            // Profile → Boosts tab
            if ($onlyReblogs) {
                ap_masto_json(ap_masto_own_reblogs_as_statuses($limit, $maxId, $ownerUserId > 0 ? $ownerUserId : null));
                return;
            }
            // Over-fetch when filtering replies/media so we still fill $limit
            $fetchN = ($excludeReplies || $onlyMedia) ? min(80, max($limit * 3, $limit + 20)) : $limit;
            $out = [];
            foreach (ap_masto_statuses_recent($fetchN, null, null, $actorKey !== '' ? $actorKey : null) as $r) {
                $status = ap_masto_status_from_row($r);
                if (!is_array($status)) {
                    continue;
                }
                // Ice Cubes Posts tab: exclude_replies=true
                // Replies tab is actually "posts and replies" (exclude_replies=false)
                if ($excludeReplies && ($status['in_reply_to_id'] ?? null) !== null) {
                    continue;
                }
                if ($onlyMedia && empty($status['media_attachments'])) {
                    continue;
                }
                $out[] = $status;
                if (count($out) >= $limit) {
                    break;
                }
            }
            // Mastodon default: mix own boosts into the posts list unless excluded
            if (!$excludeReblogs) {
                foreach (ap_masto_own_reblogs_as_statuses($limit, $maxId, $ownerUserId > 0 ? $ownerUserId : null) as $boost) {
                    $out[] = $boost;
                }
                usort($out, static function ($a, $b) {
                    $cmp = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                    return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
                });
                $out = array_slice($out, 0, $limit);
            }
            ap_masto_json($out);
            return;
        }
        // Remote account: we don't mirror their featured/pins collection.
        if ($wantPinned) {
            ap_masto_json([]);
            return;
        }
        $actor = ap_masto_actor_id_from_account_id($am[1]);
        if ($actor === null) {
            ap_masto_json([]);
            return;
        }
        // Mastodon often has /users/name and /ap/users/{id} for the same person —
        // posts may be stored under only one IRI.
        $actorAliases = function_exists('ap_masto_actor_id_aliases')
            ? ap_masto_actor_id_aliases($actor)
            : [$actor, rtrim($actor, '/') . '/'];
        if ($actorAliases === []) {
            $actorAliases = [$actor];
        }
        // only_media / exclude_replies already parsed above (shared with local account path)
        $fetchLimit = ($excludeReplies || $onlyMedia) ? 120 : 40;
        $ors = [];
        $bind = [];
        foreach ($actorAliases as $a) {
            $a = rtrim((string) $a, '/');
            if ($a === '') {
                continue;
            }
            $ors[] = 'actor_id = ? OR actor_id = ?';
            $bind[] = $a;
            $bind[] = $a . '/';
        }
        if ($ors === []) {
            ap_masto_json([]);
            return;
        }
        $bind[] = $fetchLimit;
        $st = ap_db()->prepare(
            "SELECT * FROM events WHERE type = 'Create' AND (" . implode(' OR ', $ors) . ")
             AND (
               (summary IS NOT NULL AND summary != '')
               OR (media_urls IS NOT NULL AND media_urls != '' AND media_urls != '[]')
             )
             AND (action_taken = 'log' OR action_taken = 'local_observe')
             ORDER BY created_at DESC, id DESC LIMIT ?"
        );
        foreach ($bind as $i => $v) {
            $st->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->execute();
        $out = [];
        foreach ($st->fetchAll() as $row) {
            // Prefer DB column — status entity used to omit in_reply_to_id when parent wasn't cached
            $eventIsReply = trim((string) ($row['in_reply_to'] ?? '')) !== '';
            if ($excludeReplies && $eventIsReply) {
                continue;
            }
            $status = ap_masto_status_from_event($row);
            if ($status === null) {
                continue;
            }
            if ($onlyMedia && empty($status['media_attachments'])) {
                continue;
            }
            // Belt-and-suspenders if entity has reply marker but event column was empty
            if ($excludeReplies && ($status['in_reply_to_id'] ?? null) !== null) {
                continue;
            }
            $status['pinned'] = false;
            $out[] = $status;
            if (count($out) >= $limit) {
                break;
            }
        }
        ap_masto_json($out);
        return;
    }

    if (preg_match('#^/api/v1/statuses/(\d+)/context$#', $path, $cm) && $method === 'GET') {
        ap_masto_require_token('read');
        // Must be an object — Ice Cubes crashes/retries on []
        ap_masto_json(ap_masto_status_context((int) $cm[1]));
        return;
    }

    // Ice Cubes uses /api/v2/media; treat v1 + v2 the same (sync process, return URL).
    if (($path === '/api/v1/media' || $path === '/api/v2/media') && $method === 'POST') {
        ap_masto_require_token('write');
        if (empty($_FILES['file'])) {
            // Distinguish PHP size rejects from missing field
            if (!empty($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 0 && empty($_POST) && empty($_FILES)) {
                ap_masto_json(['error' => 'Upload too large for server limits'], 413);
            }
            ap_masto_json(['error' => 'file is required'], 400);
        }
        $err = (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_OK);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            ap_masto_json(['error' => 'File too large (server upload limit)'], 413);
        }
        $desc = isset($_POST['description']) ? (string) $_POST['description'] : null;
        $res = ap_media_ingest_upload($_FILES['file'], $desc);
        if (empty($res['ok'])) {
            ap_masto_json(['error' => $res['error'] ?? 'Upload failed'], 422);
        }
        // v2 async would return 202 without url; we process sync and return 200 with url
        ap_masto_json($res['attachment'], 200);
        return;
    }

    if (preg_match('#^/api/v[12]/media/(\d+)$#', $path, $mm) && $method === 'GET') {
        ap_masto_require_token('read');
        $row = ap_media_by_local_id((int) $mm[1]);
        if (!$row) {
            ap_masto_json(['error' => 'Record not found'], 404);
        }
        ap_masto_json(ap_masto_media_entity($row));
        return;
    }

    if (preg_match('#^/api/v1/media/(\d+)$#', $path, $mm) && ($method === 'PUT' || $method === 'POST')) {
        ap_masto_require_token('write');
        $row = ap_media_by_local_id((int) $mm[1]);
        if (!$row) {
            ap_masto_json(['error' => 'Record not found'], 404);
        }
        $in = ap_masto_input();
        if (array_key_exists('description', $in)) {
            $desc = mb_substr(trim(ap_fix_utf8((string) $in['description'])), 0, 1500);
            ap_db()->prepare('UPDATE masto_media SET description = ? WHERE local_id = ?')
                ->execute([$desc !== '' ? $desc : null, (int) $mm[1]]);
            $row = ap_media_by_local_id((int) $mm[1]);
        }
        ap_masto_json(ap_masto_media_entity($row));
        return;
    }

    if ($path === '/api/v1/statuses' && $method === 'POST') {
        ap_masto_require_token('write');
        $in = ap_masto_input();
        $status = trim((string) ($in['status'] ?? ''));
        $visibility = strtolower(trim((string) ($in['visibility'] ?? 'public')));
        $replyId = isset($in['in_reply_to_id']) && $in['in_reply_to_id'] !== '' && $in['in_reply_to_id'] !== null
            ? (int) $in['in_reply_to_id'] : null;
        $inReplyTo = null;
        $dmPeer = null;
        if ($replyId) {
            // Ice Cubes sends public snowflake ids — resolve local/event/mention/DM
            $parentRow = ap_masto_local_row_from_public_id($replyId)
                ?? (($replyId < 2000000) ? ap_masto_status_by_local_id($replyId) : null);
            if ($parentRow) {
                $inReplyTo = (string) $parentRow['note_id'];
            } else {
                $resolved = ap_masto_resolve_status_interaction($replyId);
                if ($resolved !== null && ($resolved['object_id'] ?? '') !== '') {
                    $inReplyTo = (string) $resolved['object_id'];
                    if (!empty($resolved['status']['reblog']['uri'])) {
                        $inReplyTo = (string) $resolved['status']['reblog']['uri'];
                    }
                    $inReplyTo = preg_replace('/#announce-\d+$/', '', $inReplyTo) ?? $inReplyTo;
                } else {
                    $dmId = ap_masto_dm_id_from_status_id($replyId);
                    if ($dmId !== null) {
                        $dmParent = ap_dm_by_id($dmId);
                        if ($dmParent) {
                            $inReplyTo = (string) $dmParent['object_id'];
                            $dmPeer = (string) $dmParent['peer_actor_id'];
                            $visibility = 'direct';
                        }
                    }
                }
            }
        }
        $mediaIds = [];
        if (isset($in['media_ids']) && is_array($in['media_ids'])) {
            foreach ($in['media_ids'] as $mid) {
                $mediaIds[] = (int) $mid;
            }
        }
        // form-style media_ids[]=
        if (isset($_POST['media_ids']) && is_array($_POST['media_ids'])) {
            foreach ($_POST['media_ids'] as $mid) {
                $mediaIds[] = (int) $mid;
            }
        }
        $mediaIds = array_values(array_unique(array_filter($mediaIds)));
        if (count($mediaIds) > 4) {
            ap_masto_json(['error' => 'Too many media attachments (max 4)'], 422);
        }

        // Poll: poll[options][] + poll[expires_in] + poll[multiple]
        $poll = null;
        $pollIn = $in['poll'] ?? null;
        if (is_array($pollIn)) {
            $opts = $pollIn['options'] ?? [];
            if (!is_array($opts) && isset($_POST['poll']['options']) && is_array($_POST['poll']['options'])) {
                $opts = $_POST['poll']['options'];
            }
            $poll = [
                'options' => is_array($opts) ? $opts : [],
                'expires_in' => (int) ($pollIn['expires_in'] ?? ($_POST['poll']['expires_in'] ?? 86400)),
                'multiple' => !empty($pollIn['multiple']) || !empty($_POST['poll']['multiple']),
            ];
        }

        if ($visibility === 'direct') {
            // Resolve recipient: explicit reply peer, or @user@host in text
            if ($dmPeer === null) {
                if (preg_match('/@([A-Za-z0-9_]+)@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $status, $mm)) {
                    $dmPeer = '@' . $mm[1] . '@' . $mm[2];
                }
            }
            if ($dmPeer === null || $dmPeer === '') {
                ap_masto_json(['error' => 'Direct messages need a recipient (@user@host or reply to a DM)'], 422);
            }
            if ($mediaIds) {
                ap_masto_json(['error' => 'DM media attachments are not supported yet'], 422);
            }
            if ($poll !== null) {
                ap_masto_json(['error' => 'Polls are not supported in DMs'], 422);
            }
            $res = ap_dm_send($status, $dmPeer, $inReplyTo);
            if (empty($res['ok'])) {
                ap_masto_json(['error' => $res['error'] ?? 'Could not send DM'], 422);
            }
            $dm = ap_dm_by_id((int) $res['id']);
            if (!$dm) {
                ap_masto_json(['error' => 'Sent but DM missing'], 500);
            }
            ap_masto_json(ap_masto_status_from_dm($dm));
            return;
        }

        $spoiler = isset($in['spoiler_text']) ? (string) $in['spoiler_text'] : '';
        $sensitive = null;
        if (array_key_exists('sensitive', $in)) {
            $rawSens = $in['sensitive'];
            $sensitive = $rawSens === true || $rawSens === 1 || $rawSens === '1' || $rawSens === 'true';
        }
        // Ice Cubes quote-boost: quoted_status_id → AS Note.quote
        $quoteObjectId = null;
        $quotedStatusId = $in['quoted_status_id'] ?? $in['quote_id'] ?? null;
        if ($quotedStatusId !== null && $quotedStatusId !== '') {
            $qResolved = ap_masto_resolve_status_interaction((int) $quotedStatusId);
            if ($qResolved === null || ($qResolved['object_id'] ?? '') === '') {
                ap_masto_json(['error' => 'Quoted status not found'], 404);
            }
            $quoteObjectId = (string) $qResolved['object_id'];
            if (!empty($qResolved['status']['reblog']['uri'])) {
                $quoteObjectId = (string) $qResolved['status']['reblog']['uri'];
            }
            $quoteObjectId = preg_replace('/#announce-\d+$/', '', $quoteObjectId) ?? $quoteObjectId;
        }
        // Visibility must reach publish (Ice Cubes sends public|unlisted|private|direct).
        // direct is handled above via ap_dm_send — never treat public/unlisted/private mentions as DMs.
        $visibility = function_exists('ap_normalize_visibility')
            ? ap_normalize_visibility($visibility)
            : (in_array($visibility, ['public', 'unlisted', 'private'], true) ? $visibility : 'public');
        $res = ap_publish_status_text(
            $status,
            $inReplyTo,
            null,
            $mediaIds,
            $spoiler,
            $sensitive,
            $quoteObjectId,
            $poll,
            $visibility
        );
        if (empty($res['ok'])) {
            ap_masto_json(['error' => $res['error'] ?? 'Could not post'], 422);
        }
        $row = ap_masto_status_by_local_id((int) $res['local_id']);
        if (!$row) {
            ap_masto_json(['error' => 'Posted but status missing'], 500);
        }
        ap_masto_json(ap_masto_status_from_row($row));
        return;
    }

    // Edit status (Ice Cubes / Mastodon)
    if (preg_match('#^/api/v1/statuses/(\d+)$#', $path, $em) && ($method === 'PUT' || $method === 'PATCH')) {
        ap_masto_require_token('write');
        $sid = (int) $em[1];
        $row = ap_masto_local_row_from_public_id($sid) ?? (($sid < 2000000) ? ap_masto_status_by_local_id($sid) : null);
        if (!$row) {
            ap_masto_json(['error' => 'Record not found'], 404);
        }
        $in = ap_masto_input();
        $statusText = array_key_exists('status', $in) ? trim((string) $in['status']) : (string) ($row['content_text'] ?? '');
        $spoiler = array_key_exists('spoiler_text', $in) ? (string) $in['spoiler_text'] : (string) ($row['spoiler_text'] ?? '');
        $sensitive = null;
        if (array_key_exists('sensitive', $in)) {
            $rawSens = $in['sensitive'];
            $sensitive = $rawSens === true || $rawSens === 1 || $rawSens === '1' || $rawSens === 'true';
        }
        $mediaIds = null;
        if (isset($in['media_ids']) && is_array($in['media_ids'])) {
            $mediaIds = [];
            foreach ($in['media_ids'] as $mid) {
                $mediaIds[] = (int) $mid;
            }
            $mediaIds = array_values(array_unique(array_filter($mediaIds)));
        }
        if (!function_exists('ap_update_local_status')) {
            if (!defined('AP_INBOX_LIB_ONLY')) {
                define('AP_INBOX_LIB_ONLY', true);
            }
            require_once __DIR__ . '/ap-inbox.php';
        }
        $res = ap_update_local_status((int) $row['local_id'], $statusText, $spoiler, $sensitive, $mediaIds);
        if (empty($res['ok'])) {
            ap_masto_json(['error' => $res['error'] ?? 'Could not edit'], 422);
        }
        $fresh = ap_masto_status_by_local_id((int) $row['local_id']);
        if (!$fresh) {
            ap_masto_json(['error' => 'Edited but status missing'], 500);
        }
        ap_masto_json(ap_masto_status_from_row($fresh));
        return;
    }

    // Edit history (Ice Cubes). We keep current snapshot only for now.
    if (preg_match('#^/api/v1/statuses/(\d+)/history$#', $path, $hm) && $method === 'GET') {
        ap_masto_require_token('read');
        $sid = (int) $hm[1];
        $row = ap_masto_local_row_from_public_id($sid) ?? (($sid < 2000000) ? ap_masto_status_by_local_id($sid) : null);
        if (!$row) {
            ap_masto_json(['error' => 'Record not found'], 404);
        }
        $status = ap_masto_status_from_row($row);
        // Mastodon returns an array of StatusEdit objects; current-only is enough for Ice Cubes.
        ap_masto_json([[
            'content' => (string) ($status['content'] ?? ''),
            'spoiler_text' => (string) ($status['spoiler_text'] ?? ''),
            'sensitive' => !empty($status['sensitive']),
            'created_at' => (string) ($status['edited_at'] ?? $status['created_at'] ?? gmdate('c')),
            'account' => $status['account'] ?? ap_masto_account(),
            'media_attachments' => $status['media_attachments'] ?? [],
            'emojis' => $status['emojis'] ?? [],
            'poll' => $status['poll'] ?? null,
        ]]);
        return;
    }

    // Poll vote
    if (preg_match('#^/api/v1/polls/(\d+)/votes$#', $path, $pm) && $method === 'POST') {
        ap_masto_require_token('write');
        $in = ap_masto_input();
        $choices = $in['choices'] ?? [];
        if (!is_array($choices) && isset($_POST['choices']) && is_array($_POST['choices'])) {
            $choices = $_POST['choices'];
        }
        if (!is_array($choices)) {
            $choices = [];
        }
        $voterActor = function_exists('ap_masto_session_actor_id')
            ? ap_masto_session_actor_id()
            : rtrim((string) ($GLOBALS['vaak_actor_id'] ?? 'https://mkultra.monster/users/cmdr_nova'), '/');
        $res = ap_masto_poll_vote((int) $pm[1], $voterActor, $choices);
        if (empty($res['ok'])) {
            $code = ($res['error'] ?? '') === 'Poll not found' ? 404 : 422;
            ap_masto_json(['error' => $res['error'] ?? 'Vote failed'], $code);
        }
        $poll = $res['poll'];
        if (is_array($poll) && isset($res['own_votes'])) {
            $poll['voted'] = true;
            $poll['own_votes'] = $res['own_votes'];
        }
        ap_masto_json($poll);
        return;
    }

    if (preg_match('#^/api/v1/polls/(\d+)$#', $path, $pm) && $method === 'GET') {
        ap_masto_require_token('read');
        $st = ap_db()->prepare('SELECT * FROM masto_polls WHERE local_id = ?');
        $st->execute([(int) $pm[1]]);
        $row = $st->fetch();
        if (!is_array($row)) {
            ap_masto_json(['error' => 'Record not found'], 404);
        }
        ap_masto_json(ap_masto_poll_entity($row));
        return;
    }

    if (preg_match('#^/api/v1/statuses/(\d+)$#', $path, $m) && $method === 'GET') {
        ap_masto_require_token('read');
        $sid = (int) $m[1];
        $ownerId = function_exists('ap_db_masto_owner_user_id') ? ap_db_masto_owner_user_id() : 0;
        $hideBlockedStatus = static function (?array $status) use ($ownerId): bool {
            if (!is_array($status) || !function_exists('ap_actor_is_content_blocked') || $ownerId < 1) {
                return false;
            }
            $uri = rtrim((string) (($status['account']['uri'] ?? null) ?: ($status['account']['url'] ?? '')), '/');
            return $uri !== '' && ap_actor_is_content_blocked($uri, null, $ownerId);
        };
        $row = ap_masto_local_row_from_public_id($sid) ?? (($sid < 2000000) ? ap_masto_status_by_local_id($sid) : null);
        if ($row) {
            // Detail view may sync-fetch a missing quote target; timelines never do.
            $status = ap_masto_status_from_row($row, true, true);
            if ($hideBlockedStatus(is_array($status) ? $status : null)) {
                ap_masto_json(['error' => 'Record not found'], 404);
                return;
            }
            ap_masto_json($status);
            return;
        }
        // Remote mention statuses
        $mentionId = ap_masto_mention_id_from_status_id($sid);
        if ($mentionId !== null) {
            $st = ap_db()->prepare('SELECT * FROM mentions WHERE id = ? AND deleted_at IS NULL');
            $st->execute([$mentionId]);
            $mrow = $st->fetch();
            if (is_array($mrow)) {
                $status = ap_masto_status_from_mention($mrow);
                if ($hideBlockedStatus(is_array($status) ? $status : null)) {
                    ap_masto_json(['error' => 'Record not found'], 404);
                    return;
                }
                ap_masto_json($status);
                return;
            }
        }
        // Federated/home event statuses use synthetic ids 3000000+
        $eventId = ap_masto_event_id_from_status_id($sid);
        if ($eventId !== null) {
            $st = ap_db()->prepare('SELECT * FROM events WHERE id = ?');
            $st->execute([$eventId]);
            $erow = $st->fetch();
            if (is_array($erow)) {
                if (function_exists('ap_actor_is_content_blocked') && $ownerId > 0
                    && ap_actor_is_content_blocked((string) ($erow['actor_id'] ?? ''), $erow['host'] ?? null, $ownerId)) {
                    ap_masto_json(['error' => 'Record not found'], 404);
                    return;
                }
                // Detail view: fetch missing reply parent so in_reply_to_id + mentions work
                $erow['_fetch_reply_parent'] = true;
                $status = ap_masto_status_from_event($erow);
                if ($status) {
                    ap_masto_json($status);
                    return;
                }
            }
            // Legacy boost-inner phantoms (type=1 + crc32) looked like missing events
            $inner = ap_masto_resolve_announce_inner_interaction($sid);
            if ($inner !== null) {
                ap_masto_json($inner['status']);
                return;
            }
        }
        // Boosted Note with no local Create (type-8 announce_inner snowflake)
        $parsedSid = ap_masto_parse_public_status_id($sid);
        if ($parsedSid && ($parsedSid['type'] ?? '') === 'announce_inner') {
            $inner = ap_masto_resolve_announce_inner_interaction($sid);
            if ($inner !== null) {
                ap_masto_json($inner['status']);
                return;
            }
        }
        // Direct messages use synthetic ids 4000000+
        $dmId = ap_masto_dm_id_from_status_id($sid);
        if ($dmId !== null) {
            $dm = ap_dm_by_id($dmId);
            if ($dm) {
                $peer = (string) ($dm['peer_actor_id'] ?? '');
                if ($peer !== '' && function_exists('ap_dm_mark_peer_read')) {
                    try {
                        ap_dm_mark_peer_read($peer);
                    } catch (Throwable $e) {
                        error_log('[ap-masto] dm mark read (status): ' . $e->getMessage());
                    }
                }
                ap_masto_json(ap_masto_status_from_dm($dm));
                return;
            }
        }
        // Our boost wrappers (snowflake type 4 / boost_status_id)
        $boostRow = ap_masto_reblog_row_by_boost_id((string) $sid);
        if ($boostRow === null) {
            $parsed = ap_masto_parse_public_status_id($sid);
            if ($parsed && $parsed['type'] === 'reblog' && $parsed['db_id'] > 0) {
                try {
                    $st = ap_db()->prepare('SELECT * FROM masto_reblogs WHERE id = ?');
                    $st->execute([$parsed['db_id']]);
                    $cand = $st->fetch();
                    if (is_array($cand)) {
                        $boostRow = $cand;
                    }
                } catch (Throwable $e) {
                    $boostRow = null;
                }
            }
        }
        if (is_array($boostRow)) {
            $original = null;
            $origSid = (int) ($boostRow['status_id'] ?? 0);
            if ($origSid > 0) {
                $resolved = ap_masto_resolve_status_interaction($origSid);
                if ($resolved !== null) {
                    $original = $resolved['status'];
                }
            }
            if ($original === null) {
                // Fall through to list builder stub path
                foreach (ap_masto_own_reblogs_as_statuses(80, null) as $b) {
                    if ((string) ($b['id'] ?? '') === (string) ($boostRow['boost_status_id'] ?? '')
                        || (string) ($b['id'] ?? '') === (string) $sid) {
                        ap_masto_json($b);
                        return;
                    }
                }
            } else {
                ap_masto_json(ap_masto_status_from_reblog($boostRow, $original));
                return;
            }
        }
        ap_masto_json(['error' => 'Record not found'], 404);
        return;
    }

    // Boost / unboost (Ice Cubes + admin)
    if (preg_match('#^/api/v1/statuses/(\d+)/(reblog|unreblog)$#', $path, $rm) && $method === 'POST') {
        ap_masto_require_token('write');
        $sid = (int) $rm[1];
        $undo = $rm[2] === 'unreblog';
        $resolved = ap_masto_resolve_status_interaction($sid);
        if ($resolved === null) {
            ap_masto_json(['error' => 'Record not found'], 404);
        }
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
        $res = ap_masto_reblog_perform($resolved, $undo);
        if (empty($res['ok']) || empty($res['status'])) {
            ap_masto_json(['error' => $res['error'] ?? 'Could not boost'], 422);
        }
        ap_masto_json($res['status']);
        return;
    }
    if (preg_match('#^/api/v1/statuses/\d+/(reblog|unreblog)$#', $path)) {
        ap_masto_json(['error' => 'Method not allowed'], 405);
    }

    // Favourites / bookmarks (Ice Cubes + admin)
    if (preg_match('#^/api/v1/statuses/(\d+)/(favourite|unfavourite|bookmark|unbookmark)$#', $path, $im) && $method === 'POST') {
        ap_masto_require_token('write');
        $sid = (int) $im[1];
        $action = $im[2];
        $resolved = ap_masto_resolve_status_interaction($sid);
        if ($resolved === null) {
            ap_masto_json(['error' => 'Record not found'], 404);
        }
        $status = $resolved['status'];
        $statusId = (string) ($status['id'] ?? (string) $sid);
        $objectId = (string) ($resolved['object_id'] ?? '');
        // Prefer underlying Note URI when favouriting a boost wrapper
        if (!empty($status['reblog']) && is_array($status['reblog']) && !empty($status['reblog']['uri'])) {
            $objectId = (string) $status['reblog']['uri'];
        } elseif ($objectId === '' && !empty($status['uri'])) {
            $objectId = (string) $status['uri'];
        }
        $objectId = preg_replace('/#announce-\d+$/', '', $objectId) ?? $objectId;
        $targetActor = $resolved['target_actor'];
        if (!empty($status['reblog']['account']['url']) && is_string($status['reblog']['account']['url'])) {
            $targetActor = (string) $status['reblog']['account']['url'];
        } elseif (!empty($status['account']['url']) && is_string($status['account']['url']) && empty($resolved['is_ours'])) {
            $targetActor = (string) $status['account']['url'];
        }

        if ($action === 'favourite') {
            $likeId = null;
            if (empty($resolved['is_ours']) && $objectId !== '' && is_string($targetActor) && $targetActor !== '') {
                if (!defined('AP_INBOX_LIB_ONLY')) {
                    define('AP_INBOX_LIB_ONLY', true);
                }
                require_once __DIR__ . '/ap-inbox.php';
                $fan = ap_cmdr_send_like($objectId, $targetActor);
                $likeId = $fan['like_id'] ?? null;
            }
            ap_masto_favourite_add($statusId, $objectId, is_string($targetActor) ? $targetActor : null, is_string($likeId) ? $likeId : null);
            $status['favourited'] = true;
            $status['favourites_count'] = max(1, (int) ($status['favourites_count'] ?? 0));
            ap_masto_json($status);
            return;
        }
        if ($action === 'unfavourite') {
            $prev = ap_masto_favourite_remove($statusId);
            if ($prev && !empty($prev['like_activity_id']) && !empty($prev['object_id']) && !empty($prev['target_actor'])) {
                if (!defined('AP_INBOX_LIB_ONLY')) {
                    define('AP_INBOX_LIB_ONLY', true);
                }
                require_once __DIR__ . '/ap-inbox.php';
                ap_cmdr_send_undo_like(
                    (string) $prev['like_activity_id'],
                    (string) $prev['object_id'],
                    (string) $prev['target_actor']
                );
            }
            $status['favourited'] = false;
            ap_masto_json($status);
            return;
        }
        if ($action === 'bookmark') {
            ap_masto_bookmark_add($statusId, $objectId !== '' ? $objectId : null);
            $status['bookmarked'] = true;
            ap_masto_json($status);
            return;
        }
        // unbookmark
        ap_masto_bookmark_remove($statusId);
        $status['bookmarked'] = false;
        ap_masto_json($status);
        return;
    }
    if (preg_match('#^/api/v1/statuses/\d+/(favourite|unfavourite|bookmark|unbookmark)$#', $path)) {
        ap_masto_json(['error' => 'Method not allowed'], 405);
    }

    if ($path === '/api/v1/favourites' && $method === 'GET') {
        ap_masto_require_token('read');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 40;
        $maxId = isset($_GET['max_id']) ? (string) $_GET['max_id'] : null;
        ap_masto_json_timeline(ap_masto_favourites_list($limit, $maxId), '/api/v1/favourites', $limit);
        return;
    }
    if ($path === '/api/v1/bookmarks' && $method === 'GET') {
        ap_masto_require_token('read');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 40;
        $maxId = isset($_GET['max_id']) ? (string) $_GET['max_id'] : null;
        ap_masto_json_timeline(ap_masto_bookmarks_list($limit, $maxId), '/api/v1/bookmarks', $limit);
        return;
    }

    // Pin / unpin own public statuses (Ice Cubes profile pins)
    if (preg_match('#^/api/v1/statuses/(\d+)/pin$#', $path, $m) && $method === 'POST') {
        ap_masto_require_token('write');
        $sid = (int) $m[1];
        $row = ap_masto_local_row_from_public_id($sid) ?? (($sid < 2000000) ? ap_masto_status_by_local_id($sid) : null);
        if (!$row) {
            ap_masto_json(['error' => 'Record not found'], 404);
        }
        $res = ap_masto_status_pin((int) $row['local_id']);
        if (empty($res['ok'])) {
            ap_masto_json(['error' => $res['error'] ?? 'Could not pin'], 422);
        }
        ap_masto_json(ap_masto_status_from_row(ap_masto_status_by_local_id((int) $row['local_id']) ?: $row));
        return;
    }
    if (preg_match('#^/api/v1/statuses/(\d+)/unpin$#', $path, $m) && $method === 'POST') {
        ap_masto_require_token('write');
        $sid = (int) $m[1];
        $row = ap_masto_local_row_from_public_id($sid) ?? (($sid < 2000000) ? ap_masto_status_by_local_id($sid) : null);
        if (!$row) {
            ap_masto_json(['error' => 'Record not found'], 404);
        }
        ap_masto_status_unpin((int) $row['local_id']);
        ap_masto_json(ap_masto_status_from_row(ap_masto_status_by_local_id((int) $row['local_id']) ?: $row));
        return;
    }

    // Ice Cubes delete: DELETE /api/v1/statuses/:id
    if (preg_match('#^/api/v1/statuses/(\d+)$#', $path, $m) && $method === 'DELETE') {
        ap_masto_require_token('write');
        $sid = (int) $m[1];

        // Own local compose/public posts (legacy small ids or snowflake)
        $row = ap_masto_local_row_from_public_id($sid) ?? (($sid < 2000000) ? ap_masto_status_by_local_id($sid) : null);
        if ($row) {
            $entity = ap_masto_status_from_row($row);
            $res = ap_delete_local_status((int) $row['local_id']);
            if (empty($res['ok'])) {
                ap_masto_json(['error' => $res['error'] ?? 'Could not delete'], 422);
            }
            // Mastodon returns the deleted status payload
            ap_masto_json($entity);
            return;
        }

        // Own DMs (synthetic 4000000+)
        $dmId = ap_masto_dm_id_from_status_id($sid);
        if ($dmId !== null) {
            $dm = ap_dm_by_id($dmId);
            if (!$dm) {
                ap_masto_json(['error' => 'Record not found'], 404);
            }
            $entity = ap_masto_status_from_dm($dm);
            $res = ap_delete_local_dm($dmId);
            if (empty($res['ok'])) {
                ap_masto_json(['error' => $res['error'] ?? 'Could not delete'], 422);
            }
            ap_masto_json($entity);
            return;
        }

        // Remote/federated/mention statuses are not ours to delete
        if (
            ap_masto_mention_id_from_status_id($sid) !== null
            || ap_masto_event_id_from_status_id($sid) !== null
        ) {
            ap_masto_json(['error' => 'Cannot delete a remote status'], 403);
        }
        ap_masto_json(['error' => 'Record not found'], 404);
        return;
    }

    // Streaming — not implemented; return empty SSE so clients don't spin hard
    if (str_starts_with($path, '/api/v1/streaming')) {
        ap_masto_require_token('read');
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        echo ": ok\n\n";
        exit;
    }

    // Pin/unpin are POST-only (avoid catch-all returning [] on GET probes)
    if (preg_match('#^/api/v1/statuses/\d+/(pin|unpin)$#', $path)) {
        ap_masto_json(['error' => 'Method not allowed'], 405);
    }

    // Ice Cubes / clients: account autocomplete
    if ($path === '/api/v1/accounts/search' && $method === 'GET') {
        ap_masto_require_token('read');
        $q = (string) ($_GET['q'] ?? '');
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        $resolve = isset($_GET['resolve']) && ($_GET['resolve'] === '1' || $_GET['resolve'] === 'true');
        $followingOnly = isset($_GET['following']) && ($_GET['following'] === '1' || $_GET['following'] === 'true');
        if ($resolve && !defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
            require_once __DIR__ . '/ap-inbox.php';
        }
        $accounts = ap_masto_search_accounts($q, $resolve, $limit);
        if ($followingOnly) {
            $accounts = ap_masto_filter_accounts_following($accounts);
        }
        ap_masto_json($accounts);
        return;
    }

    // Ice Cubes Explore / search: accounts + hashtags (+ light local statuses)
    if (($path === '/api/v2/search' || $path === '/api/v1/search') && $method === 'GET') {
        ap_masto_require_token('read');
        $q = (string) ($_GET['q'] ?? '');
        $type = isset($_GET['type']) ? (string) $_GET['type'] : null;
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        $resolve = isset($_GET['resolve']) && ($_GET['resolve'] === '1' || $_GET['resolve'] === 'true');
        $followingOnly = isset($_GET['following']) && ($_GET['following'] === '1' || $_GET['following'] === 'true');
        if ($resolve && !defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
            require_once __DIR__ . '/ap-inbox.php';
        }
        $result = ap_masto_search($q, $type, $resolve, $limit);
        if ($followingOnly && !empty($result['accounts']) && is_array($result['accounts'])) {
            $result['accounts'] = ap_masto_filter_accounts_following($result['accounts']);
        }
        if ($path === '/api/v1/search') {
            // v1 returned hashtags as strings
            $result['hashtags'] = array_map(
                static fn($t) => (string) ($t['name'] ?? ''),
                $result['hashtags']
            );
        }
        ap_masto_json($result);
        return;
    }

    // ----- Web Push subscriptions (Ice Cubes → icecubesrelay.fly.dev) -----
    if ($path === '/api/v1/push/subscription') {
        require_once __DIR__ . '/ap-webpush.php';
        if ($method === 'POST') {
            // Prefer push scope; accept write (some clients omit push in older auth)
            $token = ap_masto_require_token(null);
            $scopes = (string) ($token['scopes'] ?? '');
            if (!ap_oauth_scopes_allow($scopes, 'push') && !ap_oauth_scopes_allow($scopes, 'write')) {
                ap_masto_json(['error' => 'This action is outside the authorized scopes'], 403);
            }
            $plain = ap_masto_bearer_raw();
            if ($plain === '') {
                ap_masto_json(['error' => 'Missing bearer token'], 401);
            }
            $in = ap_masto_input();
            // Ice Cubes sends query-string style fields; merge $_GET too
            $in = array_merge($_GET, $in);
            try {
                ap_masto_json(ap_webpush_subscription_upsert($token, $plain, $in));
            } catch (InvalidArgumentException $e) {
                ap_masto_json(['error' => $e->getMessage()], 422);
            } catch (Throwable $e) {
                error_log('[ap-webpush] subscribe: ' . $e->getMessage());
                ap_masto_json(['error' => 'Could not save push subscription'], 500);
            }
            return;
        }
        if ($method === 'GET') {
            $token = ap_masto_require_token(null);
            $row = ap_webpush_subscription_by_token_id((int) ($token['id'] ?? 0));
            if ($row === null) {
                ap_masto_json(['error' => 'Not found'], 404);
            }
            ap_masto_json(ap_webpush_subscription_entity($row));
            return;
        }
        if ($method === 'PUT') {
            $token = ap_masto_require_token(null);
            $in = array_merge($_GET, ap_masto_input());
            $ent = ap_webpush_subscription_update_alerts((int) ($token['id'] ?? 0), $in);
            if ($ent === null) {
                ap_masto_json(['error' => 'Not found'], 404);
            }
            ap_masto_json($ent);
            return;
        }
        if ($method === 'DELETE') {
            $token = ap_masto_require_token(null);
            ap_webpush_subscription_delete((int) ($token['id'] ?? 0));
            ap_masto_json(new stdClass());
            return;
        }
    }

    // Catch-all empty arrays for many read endpoints Ice Cubes probes
    if ($method === 'GET' && preg_match('#^/api/v1/#', $path)) {
        ap_masto_require_token('read');
        ap_masto_json([]);
        return;
    }

    ap_masto_json(['error' => 'Not found', 'path' => $path], 404);
}
