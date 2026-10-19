<?php
/**
 * Shared secret for the loopback Axum shadow HTTP service (127.0.0.1:8787).
 *
 * The worker refuses /shadow/* and /api/v1/* reads without it, so every PHP
 * caller adds ap_shadow_http_auth_headers() to its request. Resolution must
 * match rust/vaak-worker/src/shadow_auth.rs:
 *   1. VAAK_SHADOW_HTTP_TOKEN (32+ chars) when set;
 *   2. otherwise a value derived from VAAK_SECRET;
 *   3. otherwise none (the worker answers 503 and PHP falls back).
 * Values come from /etc/mkultra/vaak.env (the file the worker unit loads),
 * then the process environment.
 */
declare(strict_types=1);

const AP_SHADOW_HTTP_TOKEN_HEADER = 'X-VAAK-Shadow-Token';

/**
 * @return array<string,string>
 */
function ap_shadow_http_env_values(): array
{
    $path = getenv('VAAK_ENV_FILE');
    $path = is_string($path) && $path !== '' ? $path : '/etc/mkultra/vaak.env';
    $out = [];
    if (!is_readable($path)) {
        return $out;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return $out;
    }
    foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k === 'VAAK_SHADOW_HTTP_TOKEN' || $k === 'VAAK_SECRET') {
            $out[$k] = trim($v, " \t\"'");
        }
    }
    return $out;
}

/**
 * Pure resolution step, shared with the smoke test.
 */
function ap_shadow_http_token_from(string $explicit, string $secret): string
{
    $explicit = trim($explicit);
    if ($explicit !== '') {
        // A short explicit token is a misconfiguration; the worker ignores it too.
        return strlen($explicit) >= 32 ? $explicit : '';
    }
    $secret = trim($secret);
    if ($secret === '') {
        return '';
    }
    return hash('sha256', "vaak-shadow-http\0" . $secret);
}

function ap_shadow_http_token(): string
{
    static $token = null;
    if (is_string($token)) {
        return $token;
    }
    $file = ap_shadow_http_env_values();
    $pick = static function (string $key) use ($file): string {
        if (isset($file[$key]) && $file[$key] !== '') {
            return $file[$key];
        }
        $env = getenv($key);
        return is_string($env) ? $env : '';
    };
    $token = ap_shadow_http_token_from($pick('VAAK_SHADOW_HTTP_TOKEN'), $pick('VAAK_SECRET'));
    return $token;
}

/**
 * @return list<string> curl CURLOPT_HTTPHEADER lines (empty when unconfigured)
 */
function ap_shadow_http_auth_headers(): array
{
    $token = ap_shadow_http_token();
    return $token === '' ? [] : [AP_SHADOW_HTTP_TOKEN_HEADER . ': ' . $token];
}

/**
 * Same header for stream_context_create() callers.
 */
function ap_shadow_http_auth_header_text(): string
{
    $out = '';
    foreach (ap_shadow_http_auth_headers() as $line) {
        $out .= $line . "\r\n";
    }
    return $out;
}
