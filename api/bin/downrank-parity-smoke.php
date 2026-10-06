<?php
declare(strict_types=1);

/** Guarded PHP/Rust downranking parity fixture; isolated DB only. */
$dsn = trim((string) (getenv('VAAK_DOWNRANK_DATABASE_URL') ?: ''));
if ($dsn === '' || preg_match('/novalandia|mkultra|144\.91\.124\.35/i', $dsn)) {
    fwrite(STDERR, "downrank-parity: requires a non-production isolated DB URL\n");
    exit(2);
}
try {
    putenv('AP_DB_DSN=' . $dsn);
    define('AP_ADMIN_LIB_ONLY', true);
    require_once dirname(__DIR__) . '/ap-admin.php';
    $db = ap_db();
    $owner = 201;
    $actor = 'https://remote.example/users/repeat-offender';
    $object1 = 'https://remote.example/notes/evidence-1';
    $object2 = 'https://remote.example/notes/evidence-2';
    $db->prepare('DELETE FROM ap_home_suppression WHERE owner_user_id = ?')->execute([$owner]);
    $db->prepare('DELETE FROM ap_home_downrank_terms WHERE phrase = ?')->execute(['My Added Phrase']);
    $db->prepare('INSERT INTO ap_home_downrank_terms (phrase, category, enabled, created_at, updated_at) VALUES (?, ?, 1, ?, ?)')->execute(['My Added Phrase', 'custom', gmdate('c'), gmdate('c')]);

    if (admin_home_downrank_actor_exempt($actor) || !admin_home_downrank_actor_exempt('https://mkultra.monster/users/cmdr_nova')) {
        throw new RuntimeException('actor exemption parity mismatch');
    }
    $cats = admin_home_toxicity_categories('MY ADDED PHRASE and GO FUCK YOURSELF');
    if (!in_array('custom', $cats, true) || !in_array('direct_abuse', $cats, true)) {
        throw new RuntimeException('case-insensitive category matching failed');
    }

    admin_home_record_suppression($owner, $actor, ['custom'], $object1);
    admin_home_record_suppression($owner, $actor, ['custom'], $object1);
    $score = (int) $db->query("SELECT score FROM ap_home_suppression WHERE owner_user_id = {$owner}")->fetchColumn();
    if ($score !== 1) throw new RuntimeException('duplicate evidence incremented suppression score');
    admin_home_record_suppression($owner, $actor, ['custom'], $object2);
    $score = (int) $db->query("SELECT score FROM ap_home_suppression WHERE owner_user_id = {$owner}")->fetchColumn();
    if ($score !== 2) throw new RuntimeException('new evidence did not increase suppression score');

    $db->prepare('INSERT INTO events (created_at, type, actor_id, object_id, summary, visibility) VALUES (?, ?, ?, ?, ?, ?)')->execute([gmdate('c'), 'Create', $actor, $object2, 'MY ADDED PHRASE', 'public']);
    $evidence = admin_home_downrank_evidence_rows($actor, ['custom'], 5, $object2);
    if ($evidence === [] || ($evidence[0]['object_id'] ?? '') !== $object2) throw new RuntimeException('evidence link lookup failed');

    $db->prepare('INSERT INTO ap_home_suppression (owner_user_id, actor_id, score, categories_json, suppressed_until, last_object_id, seen_object_ids_json, updated_at) VALUES (?, ?, 8, ?, ?, ?, ?, ?) ON CONFLICT (owner_user_id, actor_id) DO UPDATE SET suppressed_until = EXCLUDED.suppressed_until')->execute([$owner, 'https://remote.example/users/expired', '["custom"]', gmdate('c', time() - 60), '', '[]', gmdate('c')]);
    admin_home_downrank_purge_stale();
    $expired = $db->prepare('SELECT 1 FROM ap_home_suppression WHERE owner_user_id = ? AND actor_id = ?');
    $expired->execute([$owner, 'https://remote.example/users/expired']);
    if ($expired->fetchColumn()) throw new RuntimeException('expired suppression was not purged');
    echo "downrank-parity: PASS (normalization, exemptions, duplicate evidence, scoring, expiry, evidence links)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'downrank-parity: FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
