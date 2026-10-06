<?php
declare(strict_types=1);

/** Guarded Admin/moderation parity smoke; read-only and isolated-DB only. */
$dsn = trim((string) (getenv('VAAK_ADMIN_PARITY_DATABASE_URL') ?: ''));
$rustUrl = rtrim(trim((string) (getenv('VAAK_ADMIN_PARITY_RUST_URL') ?: '')), '/');
if ($dsn === '' || $rustUrl === '') {
    fwrite(STDERR, "admin-safety: requires isolated DB URL and Rust URL\n");
    exit(2);
}
if (preg_match('/novalandia|mkultra|144\.91\.124\.35/i', $dsn . ' ' . $rustUrl)) {
    fwrite(STDERR, "admin-safety: refusing production-looking target\n");
    exit(2);
}

try {
    $raw = file_get_contents($rustUrl . '/shadow/admin-health');
    $report = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($report) || ($report['source'] ?? '') !== 'vaak-worker-shadow') {
        throw new RuntimeException('invalid Rust admin projection');
    }
    if (!str_contains((string) ($report['note'] ?? ''), 'PHP remains')) {
        throw new RuntimeException('Rust admin projection does not declare PHP ownership');
    }
    if (!is_array($report['queues'] ?? null)) {
        throw new RuntimeException('Rust admin queue projection missing');
    }

    $adminSource = (string) file_get_contents(dirname(__DIR__) . '/ap-admin.php');
    foreach (['ap_auth_csrf_token', 'ap_report_set_state', 'admin_home_downranked_actor_rows', 'ap_server_blocked_actors_set'] as $marker) {
        if (!str_contains($adminSource, $marker)) {
            throw new RuntimeException('PHP moderation boundary missing: ' . $marker);
        }
    }

    putenv('AP_DB_DSN=' . $dsn);
    require_once dirname(__DIR__) . '/ap-db.php';
    $db = ap_db();
    foreach (['ap_reports', 'ap_home_downrank_terms', 'ap_home_downrank_audit', 'ap_home_suppression'] as $table) {
        $st = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?');
        $st->execute([$table]);
        if (!$st->fetchColumn()) throw new RuntimeException('required moderation table missing: ' . $table);
    }
    echo "admin-safety: PASS (read-only projection, PHP authorization and moderation ownership)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'admin-safety: FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
