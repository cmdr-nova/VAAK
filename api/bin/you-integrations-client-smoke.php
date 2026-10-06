<?php
declare(strict_types=1);

/** Validate the owner-scoped, secret-free You integration manifest. */
$rustUrl = rtrim(trim((string) (getenv('VAAK_YOU_INTEGRATIONS_RUST_URL') ?: '')), '/');
$owner = max(1, (int) (getenv('VAAK_YOU_INTEGRATIONS_OWNER_ID') ?: '1'));
if ($rustUrl === '') {
    fwrite(STDERR, "you-integrations: requires Rust URL\n");
    exit(2);
}
if (preg_match('/novalandia|mkultra|144\.91\.124\.35/i', $rustUrl)) {
    fwrite(STDERR, "you-integrations: refusing production-looking target; use loopback or an approved test proxy\n");
    exit(2);
}
try {
    $raw = file_get_contents($rustUrl . '/shadow/you-integrations?owner_id=' . $owner);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || (int) ($data['owner_id'] ?? 0) !== $owner) {
        throw new RuntimeException('owner-scoped manifest missing');
    }
    if (($data['source'] ?? '') !== 'vaak-worker-shadow'
        || !str_contains((string) ($data['note'] ?? ''), 'PHP remains')) {
        throw new RuntimeException('missing PHP write-owner marker');
    }
    $panels = $data['panels'] ?? null;
    if (!is_array($panels) || count($panels) !== 3) {
        throw new RuntimeException('expected three integration panels');
    }
    $seen = [];
    foreach ($panels as $panel) {
        if (!is_array($panel)) throw new RuntimeException('invalid panel');
        $id = (string) ($panel['id'] ?? '');
        $seen[$id] = true;
        if (($panel['read_only'] ?? false) !== true
            || ($panel['csrf_owner'] ?? '') !== 'php'
            || ($panel['sensitive_data_exposed'] ?? true) !== false) {
            throw new RuntimeException($id . ': unsafe panel contract');
        }
    }
    foreach (['security', 'import_export', 'phyrian'] as $id) {
        if (!isset($seen[$id])) throw new RuntimeException('missing panel: ' . $id);
    }
    echo "you-integrations: PASS (owner scope, three read-only panels, no secrets, PHP write boundary)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'you-integrations: FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
