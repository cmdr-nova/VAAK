<?php
declare(strict_types=1);

/** Validate the Rust Search contract and indexed text/tag projection. */
$rustUrl = rtrim(trim((string) (getenv('VAAK_SEARCH_CONTRACT_RUST_URL') ?: '')), '/');
$owner = max(1, (int) (getenv('VAAK_SEARCH_CONTRACT_OWNER_ID') ?: '1'));
if ($rustUrl === '') {
    fwrite(STDERR, "search-parity: requires Rust URL\n");
    exit(2);
}
if (preg_match('/novalandia|mkultra|144\.91\.124\.35/i', $rustUrl)) {
    fwrite(STDERR, "search-parity: refusing production-looking target; use loopback or an approved test proxy\n");
    exit(2);
}
try {
    $raw = file_get_contents($rustUrl . '/shadow/search-contract?owner_id=' . $owner);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || (int) ($data['owner_id'] ?? 0) !== $owner) {
        throw new RuntimeException('owner-scoped contract missing');
    }
    foreach (['text', 'hashtags', 'accounts', 'remote_url'] as $kind) {
        if (!in_array($kind, $data['query_types'] ?? [], true)) {
            throw new RuntimeException('missing query type: ' . $kind);
        }
    }
    foreach (['indexable', 'personal_blocks', 'personal_mutes', 'server_blocks', 'server_mutes'] as $filter) {
        if (!in_array($filter, $data['privacy_filters'] ?? [], true)) {
            throw new RuntimeException('missing privacy filter: ' . $filter);
        }
    }
    if (($data['preserves_url_state'] ?? false) !== true
        || ($data['remote_resolution_owner'] ?? '') !== 'php'
        || ($data['fallback'] ?? '') !== 'php'
        || ($data['mutation_enabled'] ?? true) !== false
        || !str_contains((string) ($data['note'] ?? ''), 'PHP remains')) {
        throw new RuntimeException('unsafe Search ownership/state contract');
    }
    $resultBase = preg_replace('#/shadow/search-contract$#', '', $rustUrl) ?: $rustUrl;
    $call = static function (string $url): array {
        $raw = file_get_contents($url);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new RuntimeException('invalid indexed Search JSON');
        }
        return $decoded;
    };
    $text = $call($resultBase . '/shadow/search-results?owner_id=' . $owner . '&q=vaak&search_type=text');
    if (($text['query_type'] ?? '') !== 'text'
        || ($text['normalized_query'] ?? '') !== 'vaak*'
        || !is_array($text['rows'] ?? null)
        || ($text['fallback'] ?? '') !== 'php'
        || ($text['privacy_filtered'] ?? false) !== true) {
        throw new RuntimeException('text indexed projection parity failed');
    }
    $tag = $call($resultBase . '/shadow/search-results?owner_id=' . $owner . '&q=%23vaak&tag=vaak&search_type=hashtags');
    if (($tag['query_type'] ?? '') !== 'hashtags'
        || ($tag['normalized_query'] ?? '') !== 'htag_vaak OR vaak'
        || !is_array($tag['rows'] ?? null)) {
        throw new RuntimeException('hashtag indexed projection parity failed');
    }
    $accounts = $call($resultBase . '/shadow/search-results?owner_id=' . $owner . '&q=alice&search_type=accounts');
    if (($accounts['fallback'] ?? '') !== 'php' || !empty($accounts['rows'] ?? [])) {
        throw new RuntimeException('account PHP fallback marker missing');
    }
    echo "search-parity: PASS (FTS text/tag normalization, ordering shape, privacy filters, PHP account/URL fallback)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'search-parity: FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
