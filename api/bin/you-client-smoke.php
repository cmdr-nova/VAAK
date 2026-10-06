<?php
declare(strict_types=1);

/** Validate the read-only Rust You projection contract as a client would consume it. */
$rustUrl = rtrim(trim((string) (getenv('VAAK_YOU_CLIENT_RUST_URL') ?: '')), '/');
$owner = max(1, (int) (getenv('VAAK_YOU_CLIENT_OWNER_ID') ?: '1'));
if ($rustUrl === '') {
    fwrite(STDERR, "you-client: requires Rust URL\n");
    exit(2);
}
if (preg_match('/novalandia|mkultra|144\.91\.124\.35/i', $rustUrl)) {
    fwrite(STDERR, "you-client: refusing production-looking target; use loopback or an approved test proxy\n");
    exit(2);
}

try {
    foreach (['blog', 'rss', 'queue', 'drafts'] as $kind) {
        $query = http_build_query([
            'owner_id' => $owner,
            'kind' => $kind,
            'limit' => 20,
        ]);
        $raw = file_get_contents($rustUrl . '/shadow/you?' . $query);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || (int) ($data['owner_id'] ?? 0) !== $owner) {
            throw new RuntimeException($kind . ': owner-scoped projection missing');
        }
        if (($data['kind'] ?? '') !== $kind
            || !is_array($data['rows'] ?? null)
            || !is_array($data['secondary'] ?? null)) {
            throw new RuntimeException($kind . ': invalid rows/secondary contract');
        }
        if (($data['source'] ?? '') !== 'vaak-worker-shadow'
            || !str_contains((string) ($data['note'] ?? ''), 'PHP remains')) {
            throw new RuntimeException($kind . ': unsafe or missing PHP write-owner marker');
        }
    }
    echo "you-client: PASS (blog, RSS, queue, and drafts owner scope; rows/secondary shape; PHP write marker)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'you-client: FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
