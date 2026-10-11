<?php
/** Inactive authenticated Library batch bridge. Included by ap-admin; no direct route. */
declare(strict_types=1);
require_once __DIR__ . "/ap-native-quote-boundary.php";
require_once __DIR__ . "/ap-native-preview-state.php";
function ap_library_native_enabled(): bool
{
    return getenv('VAAK_LIBRARY_NATIVE_RENDER') === '1'
        && strlen((string) getenv('VAAK_LIBRARY_NATIVE_SECRET')) >= 32;
}
function ap_library_native_url_allowed(string $url): bool
{
    $p = parse_url($url);
    return is_array($p) && ($p['scheme'] ?? '') === 'http'
        && in_array($p['host'] ?? '', ['127.0.0.1', '[::1]'], true)
        && ($p['path'] ?? '') === '/internal/library/render'
        && !isset($p['user']) && !isset($p['pass']) && !isset($p['query']) && !isset($p['fragment']);
}
/** Cached source assembly only; signed fetch/AppView/OG stays canonical PHP. */
function ap_library_native_assemble_status(array $st, int $depth = 0): array
{
    if ($depth > 16) return $st;
    if (is_array($st['reblog'] ?? null)) $st['reblog'] = ap_library_native_assemble_status($st['reblog'], $depth + 1);
    if (is_array($st['quote']['quoted_status'] ?? null)) {
        $st['quote']['quoted_status'] = ap_library_native_assemble_status($st['quote']['quoted_status'], $depth + 1);
        return $st;
    }
    $target = trim((string) ($st['quote_url'] ?? ''));
    if ($target === '' && function_exists('admin_split_quote_summary') && function_exists('admin_html_to_plain')) {
        $split = admin_split_quote_summary(admin_html_to_plain((string) ($st['content'] ?? '')));
        $line = is_array($split) ? (string) ($split['quoted'] ?? '') : '';
        if (preg_match('/^↪\s*QT(?:\s+@\S+)?\s*:\s*(https:\/\/\S+)$/us', $line, $m)) $target = rtrim($m[1], '.,);]');
    }
    if ($target !== '' && function_exists('ap_masto_lookup_status_by_object_url')) {
        $source = ap_masto_lookup_status_by_object_url($target, 0, false, true);
        if (is_array($source)) {
            // Keep pending/fallback label semantics and the complete normalized
            // authority. Never turn a compact author/text preview into permission.
            $st['quote'] = is_array($st['quote'] ?? null) ? $st['quote'] : ['state' => 'fallback'];
            $st['quote']['quoted_status'] = ap_library_native_assemble_status($source, $depth + 1);
            $st['quote_url'] = $target;
        }
    }
    return $st;
}
/** Only current authenticated request provenance; cached JSON has no owner authority. */
function ap_library_native_bsky_bookmark_states(array $statuses, int $owner): array
{
    if (!function_exists('ap_bsky_bookmark_owner_state') || !function_exists('ap_bsky_session_row')) return [];
    $did = trim((string) (ap_bsky_session_row($owner)['did'] ?? ''));
    if ($did === '') return [];
    $states = [];
    foreach ($statuses as $status) {
        $post = $status['post'] ?? $status['bsky_post_view'] ?? $status['bsky_post'] ?? null;
        if (is_array($post['post'] ?? null)) $post = $post['post'];
        $uri = is_array($post) ? (string) ($post['uri'] ?? '') : '';
        if ($uri === '' || isset($states[$uri])) continue;
        $state = ap_bsky_bookmark_owner_state($uri, $owner);
        if (is_bool($state)) $states[$uri] = ['uri'=>$uri,'did'=>$did,'bookmarked'=>$state];
    }
    return array_values($states);
}
/** Validate every indexed card before publishing HTML or preview state. */
function ap_library_native_validate_reply(array $reply, int $count, int $initial): ?string
{
    if (!ap_native_preview_exact_keys($reply, ['ok', 'protocol_version', 'source_count', 'cards', 'status_preview_state'])
        || $reply['ok'] !== true || $reply['protocol_version'] !== 1 || $reply['source_count'] !== $count
        || !ap_native_preview_state_valid($reply['status_preview_state'], $initial, $count)
        || !is_array($reply['cards']) || !array_is_list($reply['cards']) || count($reply['cards']) > $count) {
        return null;
    }
    $html = '';
    $previous = -1;
    foreach ($reply['cards'] as $card) {
        if (!is_array($card) || !ap_native_preview_exact_keys($card, ['source_index', 'html'])
            || !is_int($card['source_index']) || !is_string($card['html'])
            || $card['source_index'] <= $previous || $card['source_index'] >= $count) {
            return null;
        }
        $previous = $card['source_index'];
        $html .= $card['html'];
    }
    return $html;
}
/** Return complete HTML or null; the caller paints the same assembled sources. */
function ap_library_native_render_batch(array $statuses, string $view, array $savedSources = []): ?string
{
    if (!ap_library_native_enabled() || !in_array($view, ['bookmarks', 'favourites'], true)
        || !array_is_list($statuses) || count($statuses) > 100 || !function_exists('ap_auth_current_user')) return null;
    try {
        foreach ($statuses as $status) {
            if (!is_array($status) || ap_native_quote_tree_requires_php($status)) {
                return null;
            }
        }
    } catch (Throwable $error) {
        return null;
    }
    $user = ap_auth_current_user();
    if (!is_array($user) || (int) ($user['id'] ?? 0) < 1 || !empty($user['is_guest'])) return null;
    $url = (string) (getenv('VAAK_LIBRARY_NATIVE_URL') ?: 'http://127.0.0.1:8787/internal/library/render');
    if (!ap_library_native_url_allowed($url) || !function_exists('curl_init')) return null;
    $initial = ap_native_preview_effective_budget();
    try {
    json_encode($statuses, JSON_THROW_ON_ERROR);
    $bskyRemaining = function_exists('ap_bsky_budget_remaining') ? ap_bsky_budget_remaining() : null;
    if ($bskyRemaining !== null && ((!is_float($bskyRemaining) && !is_int($bskyRemaining)) || !is_finite((float)$bskyRemaining))) return null;
    $body = json_encode(['protocol_version' => 1, 'status_preview_budget' => $initial,
        'bsky_budget_deadline' => $bskyRemaining === null ? null : microtime(true) + $bskyRemaining,
        'owner_id' => (int) $user['id'], 'csrf' => ap_auth_csrf_token(),
        'return_view' => $view, 'lazy_media' => !empty($GLOBALS['admin_bookmark_lazy_media']), 'bsky_enabled' => function_exists('ap_bsky_tab_enabled') && ap_bsky_tab_enabled(),
        'bsky_bookmarks' => ap_library_native_bsky_bookmark_states($statuses, (int) $user['id']),
        'saved_sources' => $savedSources,
        'statuses' => array_values($statuses)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        return null;
    }
    if (!is_string($body) || strlen($body) > 2 * 1024 * 1024) return null;
    $response = '';
    $ch = curl_init($url);
    if ($ch === false) return null;
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-VAAK-Library-Token: ' . getenv('VAAK_LIBRARY_NATIVE_SECRET')],
        CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 8 * 1024 * 1024) return 0;
            $response .= $chunk; return strlen($chunk);
        },
        CURLOPT_CONNECTTIMEOUT_MS => 200, CURLOPT_TIMEOUT_MS => 3000,
        CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP]);
    $success = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($success === false || $code !== 200 || !is_string($response) || strlen($response) > 8 * 1024 * 1024) return null;
    $reply = json_decode($response, true);
    $html = is_array($reply) ? ap_library_native_validate_reply($reply, count($statuses), $initial) : null;
    if ($html === null) return null;
    ap_native_preview_commit_state($reply['status_preview_state']);
    return $html;
}

/** Descriptor-only local resolver. A failed/unsupported whole batch leaves the
 * original raw rows to the unchanged current canonical PHP mapper. */
function ap_library_native_render_saved_local(array $rows, string $producer, int $owner, int $folder, int $offset, int $limit, ?bool &$attempted = null): ?string
{
    $attempted = false;
    if (!ap_library_native_enabled() || !array_is_list($rows) || $rows === [] || count($rows) > 40
        || !in_array($producer, ['bookmarks_fedi', 'bookmarks_fedi_data'], true)) return null;
    $user = ap_auth_current_user();
    if (!is_array($user) || (int) ($user['id'] ?? 0) !== $owner || !empty($user['is_guest'])) return null;
    $url = (string) (getenv('VAAK_LIBRARY_NATIVE_URL') ?: 'http://127.0.0.1:8787/internal/library/render');
    if (!ap_library_native_url_allowed($url) || !function_exists('curl_init')) return null;
    $savedSources = [];
    foreach ($rows as $ordinal => $row) {
        if (!is_array($row) || !isset($row['id'])) return null;
        $savedSources[] = ['source_index' => $ordinal, 'producer_kind' => $producer,
            'selected_kind' => 'fedi', 'reference' => (string) $row['id'],
            'selected_ordinal' => $ordinal, 'folder_id' => $folder, 'offset' => $offset, 'limit' => $limit];
    }
    $initial = ap_native_preview_effective_budget();
    try {
        $body = json_encode(['protocol_version' => 1, 'canonical_saved_only' => true,
            'status_preview_budget' => $initial, 'owner_id' => $owner,
            'csrf' => ap_auth_csrf_token(), 'return_view' => 'bookmarks',
            'lazy_media' => !empty($GLOBALS['admin_bookmark_lazy_media']), 'bsky_enabled' => false,
            'saved_sources' => $savedSources], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $error) { return null; }
    $response = '';
    $ch = curl_init($url);
    if ($ch === false) return null;
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-VAAK-Library-Token: ' . getenv('VAAK_LIBRARY_NATIVE_SECRET')],
        CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 8 * 1024 * 1024) return 0;
            $response .= $chunk; return strlen($chunk);
        },
        CURLOPT_CONNECTTIMEOUT_MS => 200, CURLOPT_TIMEOUT_MS => 3000,
        CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP]);
    $attempted = true;
    $success = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($success === false || $code !== 200 || !is_string($response) || strlen($response) > 8 * 1024 * 1024) return null;
    $reply = json_decode($response, true);
    $html = is_array($reply) ? ap_library_native_validate_reply($reply, count($savedSources), $initial) : null;
    if ($html === null) return null;
    ap_native_preview_commit_state($reply['status_preview_state']);
    return $html;
}
