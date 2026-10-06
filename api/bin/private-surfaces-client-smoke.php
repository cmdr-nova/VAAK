<?php
declare(strict_types=1);

/** Validate the content-free DM/Ask/moderation privacy contract. */
$rustUrl = rtrim(trim((string) (getenv('VAAK_PRIVATE_SURFACES_RUST_URL') ?: '')), '/');
$owner = max(1, (int) (getenv('VAAK_PRIVATE_SURFACES_OWNER_ID') ?: '1'));
if ($rustUrl === '') {
    fwrite(STDERR, "private-surfaces: requires Rust URL\n");
    exit(2);
}
if (preg_match('/novalandia|mkultra|144\.91\.124\.35/i', $rustUrl)) {
    fwrite(STDERR, "private-surfaces: refusing production-looking target; use loopback or an approved test proxy\n");
    exit(2);
}
try {
    $raw = file_get_contents($rustUrl . '/shadow/private-surfaces?owner_id=' . $owner);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || (int) ($data['owner_id'] ?? 0) !== $owner) {
        throw new RuntimeException('owner-scoped contract missing');
    }
    if (($data['source'] ?? '') !== 'vaak-worker-shadow'
        || !str_contains((string) ($data['note'] ?? ''), 'PHP remains')) {
        throw new RuntimeException('missing PHP write-owner marker');
    }
    $p = $data['policies'] ?? null;
    if (!is_array($p)
        || ($p['dm_delivery_blocked_peer'] ?? false) !== true
        || ($p['dm_deleted_hidden'] ?? false) !== true
        || ($p['ask_anonymous_allowed'] ?? true) !== false
        || (int) ($p['ask_expiry_hours'] ?? 0) !== 48
        || ($p['moderation_owner'] ?? '') !== 'php'
        || ($p['federation_owner'] ?? '') !== 'php'
        || ($p['mutation_enabled'] ?? true) !== false) {
        throw new RuntimeException('unsafe private-surface policy contract');
    }
    echo "private-surfaces: PASS (owner scope, DM/Ask privacy, 48h expiry, PHP mutation boundary)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'private-surfaces: FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
