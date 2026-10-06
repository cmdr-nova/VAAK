<?php
declare(strict_types=1);

/** Validate the content-free Rust Search migration contract. */
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
    echo "search-parity: PASS (query types, privacy filters, URL state, PHP fallback/remote ownership)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'search-parity: FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
