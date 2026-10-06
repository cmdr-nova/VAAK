<?php
declare(strict_types=1);

/** Guarded Settings parity harness; only runs against an isolated test DB. */
$dsn = trim((string) (getenv('VAAK_SETTINGS_PARITY_DATABASE_URL') ?: ''));
$rustUrl = rtrim(trim((string) (getenv('VAAK_SETTINGS_PARITY_RUST_URL') ?: '')), '/');
if ($dsn === '' || $rustUrl === '') {
    fwrite(STDERR, "settings-transaction-parity: requires isolated DB URL and Rust URL\n");
    exit(2);
}
if (preg_match('/novalandia|mkultra|144\.91\.124\.35/i', $dsn . ' ' . $rustUrl)) {
    fwrite(STDERR, "settings-transaction-parity: refusing production-looking target\n");
    exit(2);
}
putenv('AP_DB_DSN=' . $dsn);
putenv('VAAK_PHYRIAN_RUST_MUTATIONS=0');
require_once dirname(__DIR__) . '/ap-db.php';

function rust_settings(string $base, int $owner): array
{
    $raw = file_get_contents($base . '/shadow/settings?owner_id=' . $owner);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || (int) ($data['owner_id'] ?? 0) !== $owner) {
        throw new RuntimeException('invalid Rust settings response for owner ' . $owner);
    }
    return $data;
}

try {
    $db = ap_db();
    $now = gmdate('c');
    foreach ([201, 202] as $id) {
        $key = 'settings_test_' . $id;
        $db->prepare('INSERT INTO ap_users (id, username, email, password_hash, actor_key, actor_id, created_at, updated_at, disabled_at) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, NULL) ON CONFLICT (id) DO UPDATE SET disabled_at=NULL, actor_key=excluded.actor_key, actor_id=excluded.actor_id')
            ->execute([$id, $key, 'test-hash', $key, 'https://mkultra.monster/users/' . $key, $now, $now]);
        $db->prepare('INSERT INTO actor_profile (actor_key, name, summary, attachment_json, profile_badges, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (actor_key) DO UPDATE SET name=excluded.name, summary=excluded.summary, attachment_json=excluded.attachment_json, profile_badges=excluded.profile_badges, updated_at=excluded.updated_at')
            ->execute([$key, 'Settings Test ' . $id, '<p>Initial bio ' . $id . '</p>', '[]', '[]', $now]);
    }
    $a = rust_settings($rustUrl, 201);
    $b = rust_settings($rustUrl, 202);
    if (($a['actor_key'] ?? '') === ($b['actor_key'] ?? '') || ($a['fields'] ?? null) === ($b['fields'] ?? null)) {
        throw new RuntimeException('account isolation mismatch');
    }
    $before = $a['fields'];

    // PHP partial saves must preserve omitted preferences while changing one field.
    require_once dirname(__DIR__) . '/ap-db.php';
    $saveA = ap_profile_save(['name' => 'Settings Test 201', 'summary' => 'Initial bio 201', 'algorithm_enabled' => false], 'settings_test_201');
    $saveB = ap_profile_save(['name' => 'Settings Test 201', 'summary' => 'Initial bio 201', 'asks_enabled' => false], 'settings_test_201');
    if (empty($saveA['ok']) || empty($saveB['ok'])) throw new RuntimeException('partial save failed');
    $debug = $db->query("SELECT algorithm_enabled, asks_enabled FROM actor_profile WHERE actor_key='settings_test_201'")->fetch(PDO::FETCH_ASSOC);
    $after = rust_settings($rustUrl, 201)['fields'];
    if (($after['algorithm_enabled'] ?? true) !== false || ($after['asks_enabled'] ?? true) !== false || ($after['name'] ?? '') !== $before['name']) {
        throw new RuntimeException('partial-save preservation mismatch direct=' . json_encode($debug) . ' after=' . json_encode(['name' => $after['name'] ?? null, 'algorithm_enabled' => $after['algorithm_enabled'] ?? null, 'asks_enabled' => $after['asks_enabled'] ?? null]) . ' before=' . json_encode(['name' => $before['name'] ?? null]));
    }
    foreach ($before as $field => $value) {
        if (in_array($field, ['name', 'algorithm_enabled', 'asks_enabled'], true)) {
            continue;
        }
        if (($after[$field] ?? null) !== $value) {
            throw new RuntimeException('omitted field changed during partial save: ' . $field);
        }
    }
    $bAfter = rust_settings($rustUrl, 202)['fields'];
    if ($bAfter !== $b['fields']) {
        throw new RuntimeException('second account changed during first account save');
    }

    // Explicit transaction rollback must leave the canonical values unchanged.
    $db->beginTransaction();
    $db->prepare('UPDATE actor_profile SET name = ?, algorithm_enabled = ? WHERE actor_key = ?')->execute(['SHOULD ROLLBACK', 1, 'settings_test_201']);
    $db->rollBack();
    $rolled = rust_settings($rustUrl, 201)['fields'];
    if (($rolled['name'] ?? '') !== $after['name'] || ($rolled['algorithm_enabled'] ?? true) !== false) {
        throw new RuntimeException('rollback did not preserve canonical values');
    }
    echo "settings-transaction-parity: PASS (isolation, partial-save preservation, rollback)\n";
} catch (Throwable $e) {
    fwrite(STDERR, "settings-transaction-parity: FAIL: " . $e->getMessage() . "\n");
    exit(1);
}
