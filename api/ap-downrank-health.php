<?php
/** Read-only operator check for Home downranking schema and application grants. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/ap-db.php';
$result = ['ok' => true, 'driver' => ap_db_driver(), 'checks' => []];
try {
    $db = ap_db();
    foreach (['ap_home_suppression', 'ap_home_downrank_terms', 'ap_home_downrank_audit'] as $table) {
        $exists = false;
        if (ap_db_driver($db) === 'pgsql') {
            $st = $db->prepare('SELECT to_regclass(?) IS NOT NULL');
            $st->execute(['public.' . $table]);
            $exists = (bool) $st->fetchColumn();
            $writable = $exists && (bool) $db->query("SELECT has_table_privilege(current_user, 'public.$table', 'SELECT,INSERT')")->fetchColumn();
        } else {
            $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
            $st->execute([$table]);
            $exists = (bool) $st->fetchColumn();
            $writable = $exists;
        }
        $result['checks'][$table] = ['exists' => $exists, 'app_read_write' => $writable];
        if (!$exists || !$writable) $result['ok'] = false;
    }
    if (ap_db_driver($db) === 'pgsql') {
        $result['checks']['downranking_enabled'] = (bool) $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='actor_profile' AND column_name='downranking_enabled'")->fetchColumn();
        if (!$result['checks']['downranking_enabled']) $result['ok'] = false;
    }
} catch (Throwable $e) {
    $result['ok'] = false;
    $result['error'] = $e->getMessage();
}
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 2);
