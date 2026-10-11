<?php
/** Default-off, server-session-only Search presentation bridge; no direct PHP route. */
declare(strict_types=1);
require_once __DIR__ . "/ap-native-preview-state.php";
require_once __DIR__ . "/ap-native-quote-boundary.php";

function ap_search_native_enabled(): bool
{
    return getenv('VAAK_SEARCH_NATIVE_RENDER') === '1'
        && strlen((string) getenv('VAAK_SEARCH_NATIVE_SECRET')) >= 32;
}

function ap_search_native_url_allowed(string $url): bool
{
    $parts = parse_url($url);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'http'
        && in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]'], true)
        && ($parts['path'] ?? '') === '/internal/search/render'
        && !isset($parts['user']) && !isset($parts['pass'])
        && !isset($parts['query']) && !isset($parts['fragment']);
}

/** Backwards-compatible internal classifier name for Search fixtures/callers. */
function ap_search_native_quote_requires_php(array $quoted): bool
{
    return ap_native_quote_requires_php($quoted);
}

/** A separate copy for native assembly; canonical PHP fallback keeps the original rows. */
function ap_search_native_assemble_status(array $status, int $depth = 0): array
{
    if ($depth > 16) {
        throw new RuntimeException('Search source depth exceeded');
    }
    foreach (['reblog', 'vaak_quote_preview'] as $key) {
        if (is_array($status[$key] ?? null)) {
            $status[$key] = ap_search_native_assemble_status($status[$key], $depth + 1);
        }
    }
    if (is_array($status['quote']['quoted_status'] ?? null)) {
        $status['quote']['quoted_status'] = ap_search_native_assemble_status($status['quote']['quoted_status'], $depth + 1);
        if (ap_search_native_quote_requires_php($status['quote']['quoted_status'])) {
            throw new RuntimeException('Search quote preview requires canonical PHP rendering');
        }
        return $status;
    }
    $target = trim((string) ($status['quote_url'] ?? ''));
    if ($target === '' && function_exists('admin_split_quote_summary') && function_exists('admin_html_to_plain')) {
        $split = admin_split_quote_summary(admin_html_to_plain((string) ($status['content'] ?? '')));
        $line = is_array($split) ? (string) ($split['quoted'] ?? '') : '';
        if (preg_match('/^↪\s*QT(?:\s+@\S+)?\s*:\s*(https:\/\/\S+)$/us', $line, $match)) {
            $target = rtrim($match[1], '.,);]');
        }
    }
    if ($target !== '') {
        // The canonical late painter can consume a preview budget even for a
        // cache hit. Keep its complete orchestration rather than preassembling
        // a child and bypassing that observable step.
        throw new RuntimeException('Search late quote requires canonical PHP rendering');
    }
    return $status;
}

/** Current owner proof only; never infer saved state from shared source JSON. */
function ap_search_native_bsky_bookmark_states(array $statuses, int $owner): array
{
    if (!function_exists('ap_bsky_bookmark_owner_state') || !function_exists('ap_bsky_session_row')) {
        return [];
    }
    $did = trim((string) (ap_bsky_session_row($owner)['did'] ?? ''));
    if ($did === '') {
        return [];
    }
    $uris = [];
    $walk = static function (array $value, int $depth) use (&$walk, &$uris): void {
        if ($depth > 32) {
            throw new RuntimeException('Search provenance depth exceeded');
        }
        $uri = $value['uri'] ?? null;
        if (is_string($uri) && str_starts_with($uri, 'at://')) {
            if (!preg_match('~^at://did:[^\s/?#]+/app\.bsky\.feed\.post/[^\s/?#]+$~D', $uri)) {
                throw new RuntimeException('Invalid Search post URI');
            }
            $uris[$uri] = true;
            if (count($uris) > 121) {
                throw new RuntimeException('Search provenance limit exceeded');
            }
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $walk($child, $depth + 1);
            }
        }
    };
    $walk($statuses, 0);
    $states = [];
    foreach (array_keys($uris) as $uri) {
        $state = ap_bsky_bookmark_owner_state($uri, $owner);
        if (is_bool($state)) {
            $states[] = ['uri' => $uri, 'did' => $did, 'bookmarked' => $state];
        }
    }
    return $states;
}

/** Validate the entire response before any native output becomes visible. */
function ap_search_native_validate_reply(array $reply, array $counts, int $initial = 3): ?array
{
    $keys = array_keys($reply);
    sort($keys);
    if ($keys !== ['accounts', 'hashtags', 'ok', 'protocol_version', 'source_counts', 'status_preview_state', 'statuses'] || $reply['ok'] !== true
        || $reply['protocol_version'] !== 1 || !ap_native_preview_state_valid($reply['status_preview_state'], $initial, $counts['statuses'])
        || !is_array($reply['source_counts']) || count($reply['source_counts']) !== 3) {
        return null;
    }
    $output = [];
    foreach (['accounts', 'hashtags', 'statuses'] as $section) {
        if (($reply['source_counts'][$section] ?? null) !== $counts[$section]
            || !is_array($reply[$section]) || !array_is_list($reply[$section])) {
            return null;
        }
        $output[$section] = '';
        if ($section !== 'statuses') {
            if (count($reply[$section]) !== $counts[$section]) {
                return null;
            }
            foreach ($reply[$section] as $html) {
                if (!is_string($html)) {
                    return null;
                }
                $output[$section] .= $html;
            }
            continue;
        }
        $previous = -1;
        foreach ($reply[$section] as $slot) {
            if (!is_array($slot) || !ap_native_preview_exact_keys($slot, ['source_index', 'html']) || !is_int($slot['source_index'] ?? null)
                || !is_string($slot['html'] ?? null) || $slot['source_index'] <= $previous
                || $slot['source_index'] >= $counts[$section]) {
                return null;
            }
            $previous = $slot['source_index'];
            $output[$section] .= $slot['html'];
        }
    }
    return $output;
}

/** All selected sections succeed together, or the caller paints its original PHP rows. */
function ap_search_native_render_batch(array $accounts, array $hashtags, array $statuses, ?array $collector = null): ?array
{
    if (!ap_search_native_enabled() || !function_exists('ap_auth_current_user')
        || !function_exists('ap_auth_csrf_token') || !function_exists('curl_init')) {
        return null;
    }
    $counts = ['accounts' => count($accounts), 'hashtags' => count($hashtags), 'statuses' => count($statuses)];
    if (max($counts) > 40 || array_sum($counts) > 120) {
        return null;
    }
    foreach ([$accounts, $hashtags, $statuses] as $rows) {
        if (!array_is_list($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || ($row !== [] && array_is_list($row))) {
                return null;
            }
        }
    }
    $user = ap_auth_current_user();
    if (!is_array($user) || (int) ($user['id'] ?? 0) < 1 || !empty($user['is_guest'])) {
        return null;
    }
    $url = (string) (getenv('VAAK_SEARCH_NATIVE_URL') ?: 'http://127.0.0.1:8787/internal/search/render');
    if (!ap_search_native_url_allowed($url)) {
        return null;
    }
    try {
        $initial = ap_native_preview_effective_budget();
        // Strictly encode originals first: invalid authority bytes must never be repaired for admission.
        json_encode([$accounts, $hashtags, $statuses], JSON_THROW_ON_ERROR);
        $assembled = array_map('ap_search_native_assemble_status', $statuses);
        $bskyRemaining = function_exists('ap_bsky_budget_remaining') ? ap_bsky_budget_remaining() : null;
        if ($bskyRemaining !== null && ((!is_float($bskyRemaining) && !is_int($bskyRemaining)) || !is_finite((float)$bskyRemaining))) return null;
        $body = json_encode(['protocol_version' => 1, 'status_preview_budget' => $initial,
            'bsky_budget_deadline' => $bskyRemaining === null ? null : microtime(true) + $bskyRemaining,
            'owner_id' => (int) $user['id'], 'csrf' => ap_auth_csrf_token(),
            'bsky_enabled' => function_exists('ap_bsky_tab_enabled') && ap_bsky_tab_enabled(),
            'accounts' => $accounts, 'hashtags' => $hashtags, 'statuses' => $assembled, 'collector' => $collector,
            'bsky_bookmarks' => ap_search_native_bsky_bookmark_states($assembled, (int) $user['id'])],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($body) > 2 * 1024 * 1024) {
            return null;
        }
        $response = '';
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-VAAK-Search-Token: ' . getenv('VAAK_SEARCH_NATIVE_SECRET')],
            CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 8 * 1024 * 1024) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_CONNECTTIMEOUT_MS => 200, CURLOPT_TIMEOUT_MS => 3000,
            CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP]);
        $success = curl_exec($handle);
        $code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($success === false || $code !== 200) {
            return null;
        }
        $reply = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        $output = is_array($reply) ? ap_search_native_validate_reply($reply, $counts, $initial) : null;
        if ($output === null) return null;
        ap_native_preview_commit_state($reply['status_preview_state']);
        return $output;
    } catch (Throwable $error) {
        return null;
    }
}
