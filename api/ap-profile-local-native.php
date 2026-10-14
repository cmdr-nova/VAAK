<?php
/** Inactive local-profile chrome bridge. Included by ap-admin; no direct route.
 *  PHP still follows, blocks, mutes, bites, and answers Asks. Rust only paints. */
declare(strict_types=1);

function ap_profile_native_enabled(): bool
{
    return getenv('VAAK_PROFILE_NATIVE_RENDER') === '1'
        && strlen((string) getenv('VAAK_PROFILE_NATIVE_SECRET')) >= 32;
}

function ap_profile_native_url_allowed(string $url): bool
{
    $p = parse_url($url);
    return is_array($p) && ($p['scheme'] ?? '') === 'http'
        && in_array($p['host'] ?? '', ['127.0.0.1', '[::1]'], true)
        && ($p['path'] ?? '') === '/internal/profile/local'
        && !isset($p['user']) && !isset($p['pass']) && !isset($p['query']) && !isset($p['fragment']);
}

/** @param array<string,mixed> $reply */
function ap_profile_native_validate_local(array $reply, array $binding): ?array
{
    $pageBack = $reply['page_back'] ?? null;
    $header = $reply['header'] ?? null;
    if (($reply['ok'] ?? null) !== true || ($reply['protocol_version'] ?? null) !== 1
        || ($reply['request_binding'] ?? null) !== $binding
        || !is_string($reply['chrome'] ?? null) || $reply['chrome'] === ''
        || strlen($reply['chrome']) > 1024 * 1024
        || !is_string($pageBack) || $pageBack === '' || !is_string($header) || $header === ''
        || $pageBack . $header !== $reply['chrome']) {
        return null;
    }
    $secondary = $reply['secondary'] ?? null;
    if ($secondary !== null && (!is_string($secondary) || $secondary === '' || strlen($secondary) > 64 * 1024)) {
        return null;
    }
    return ['chrome' => $reply['chrome'], 'page_back' => $pageBack, 'header' => $header, 'secondary' => $secondary];
}

/**
 * Paint the local header, tabs, relationship row, and an empty Pins or Asks
 * body. Null keeps the caller on the PHP template. Non-empty pin and ask cards
 * stay on PHP, so secondary is null for those pages.
 *
 * @param array<string,mixed> $facts
 * @return array{chrome:string,page_back:string,header:string,secondary:?string}|null
 */
function ap_profile_native_render_local(array $facts): ?array
{
    if (!ap_profile_native_enabled() || !function_exists('curl_init')) {
        return null;
    }
    $actor = rtrim(trim((string) ($facts['actor'] ?? '')), '/');
    if (!preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $actor)) {
        return null;
    }
    $url = (string) (getenv('VAAK_PROFILE_NATIVE_URL') ?: 'http://127.0.0.1:8787/internal/profile/local');
    if (!ap_profile_native_url_allowed($url)) {
        return null;
    }
    $binding = [
        'actor' => $actor,
        'tab' => (string) ($facts['tab'] ?? ''),
        'from' => (string) ($facts['from'] ?? ''),
    ];
    try {
        $body = json_encode(['protocol_version' => 1] + $facts + ['actor' => $actor], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        return null;
    }
    if (!is_string($body) || strlen($body) > 256 * 1024) {
        return null;
    }
    $response = '';
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-VAAK-Profile-Token: ' . getenv('VAAK_PROFILE_NATIVE_SECRET')],
        CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 2 * 1024 * 1024) {
                return 0;
            }
            $response .= $chunk;
            return strlen($chunk);
        },
        CURLOPT_CONNECTTIMEOUT_MS => 200, CURLOPT_TIMEOUT_MS => 3000,
        CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP]);
    $success = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($success === false || $code !== 200 || strlen($response) > 2 * 1024 * 1024) {
        return null;
    }
    $reply = json_decode($response, true);
    return is_array($reply) ? ap_profile_native_validate_local($reply, $binding) : null;
}
