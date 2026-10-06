<?php
declare(strict_types=1);

/** Validate the Rust Settings JSON contract as a mobile/API client would see it. */
$rustUrl = rtrim(trim((string) (getenv('VAAK_SETTINGS_CLIENT_RUST_URL') ?: '')), '/');
$owner = max(1, (int) (getenv('VAAK_SETTINGS_CLIENT_OWNER_ID') ?: '1'));
if ($rustUrl === '') {
    fwrite(STDERR, "settings-client: requires Rust URL\n");
    exit(2);
}
if (preg_match('/novalandia|mkultra|144\.91\.124\.35/i', $rustUrl)) {
    fwrite(STDERR, "settings-client: refusing production-looking target; use loopback or an approved test proxy\n");
    exit(2);
}
try {
    $raw = file_get_contents($rustUrl . '/shadow/settings?owner_id=' . $owner);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || (int) ($data['owner_id'] ?? 0) !== $owner) {
        throw new RuntimeException('owner-scoped response missing');
    }
    if (($data['mutation_owner'] ?? '') !== 'php' || ($data['mutation_enabled'] ?? true) !== false) {
        throw new RuntimeException('unsafe mutation marker');
    }
    $fields = $data['fields'] ?? null;
    if (!is_array($fields) || !is_string($fields['name'] ?? null) || !is_string($fields['summary'] ?? null)) {
        throw new RuntimeException('profile fields are not mobile-safe JSON scalars');
    }
    foreach (['algorithm_enabled', 'downranking_enabled', 'asks_enabled', 'webmentions_enabled'] as $field) {
        if (!is_bool($fields[$field] ?? null)) throw new RuntimeException('boolean field type mismatch: ' . $field);
    }
    echo "settings-client: PASS (owner scope, JSON field types, PHP write marker)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'settings-client: FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
