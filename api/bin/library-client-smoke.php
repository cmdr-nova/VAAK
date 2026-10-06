<?php
declare(strict_types=1);

/** Validate the Rust Library projection contract as a client would consume it. */
$rustUrl = rtrim(trim((string) (getenv('VAAK_LIBRARY_CLIENT_RUST_URL') ?: '')), '/');
$owner = max(1, (int) (getenv('VAAK_LIBRARY_CLIENT_OWNER_ID') ?: '1'));
$folder = max(0, (int) (getenv('VAAK_LIBRARY_CLIENT_FOLDER_ID') ?: '0'));
if ($rustUrl === '') {
    fwrite(STDERR, "library-client: requires Rust URL\n");
    exit(2);
}
if (preg_match('/novalandia|mkultra|144\.91\.124\.35/i', $rustUrl)) {
    fwrite(STDERR, "library-client: refusing production-looking target; use loopback or an approved test proxy\n");
    exit(2);
}

try {
    $query = http_build_query([
        'owner_id' => $owner,
        'library_kind' => 'bookmarks',
        'folder_id' => $folder,
        'offset' => 0,
        'limit' => 20,
    ]);
    $raw = file_get_contents($rustUrl . '/shadow/library-data?' . $query);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || (int) ($data['owner_id'] ?? 0) !== $owner) {
        throw new RuntimeException('owner-scoped projection missing');
    }
    foreach (['fedi_rows', 'bsky_rows'] as $rows) {
        if (!is_array($data[$rows] ?? null)) {
            throw new RuntimeException($rows . ' is not an array');
        }
    }
    foreach (['has_more_fedi', 'has_more_bsky'] as $flag) {
        if (!is_bool($data[$flag] ?? null)) {
            throw new RuntimeException($flag . ' is not a boolean');
        }
    }
    if (($data['source'] ?? '') !== 'vaak-worker-shadow'
        || !str_contains((string) ($data['note'] ?? ''), 'PHP remains')) {
        throw new RuntimeException('unsafe or missing PHP write-owner marker');
    }
    echo "library-client: PASS (owner scope, folder query, row arrays, pagination flags, PHP write marker)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'library-client: FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
