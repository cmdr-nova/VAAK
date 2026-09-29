<?php
/**
 * Safely replay terminal durable-queue rows.
 *
 * Examples:
 *   php ap-queue-replay.php --queue=all --dry-run
 *   php ap-queue-replay.php --queue=fanout --host=example.social --limit=50
 *
 * This only moves rows from failed back to pending. It never deletes queue
 * history, and dry-run is the default unless --apply is supplied.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/ap-db.php';

$queue = 'all';
$host = '';
$limit = 100;
$minAge = 0;
$apply = false;
foreach ($argv as $arg) {
    if (preg_match('/^--queue=(actions|publish|fanout|all)$/', $arg, $m)) $queue = $m[1];
    if (preg_match('/^--host=([^\s]+)$/', $arg, $m)) $host = strtolower(trim($m[1]));
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = max(1, min(1000, (int) $m[1]));
    if (preg_match('/^--min-age=(\d+)$/', $arg, $m)) $minAge = max(0, (int) $m[1]);
    if ($arg === '--apply') $apply = true;
}

$tables = [
    'actions' => 'ap_action_queue',
    'publish' => 'ap_publish_delivery_queue',
    'fanout' => 'ap_fanout_delivery_queue',
];
$wanted = $queue === 'all' ? array_keys($tables) : [$queue];
$cutoff = gmdate('c', time() - ($minAge * 60));
$db = ap_db();
$now = gmdate('c');
$summary = [];

$rowMatchesHost = static function (string $kind, array $row, string $wantHost): bool {
    if ($wantHost === '') return true;
    $candidate = '';
    if ($kind === 'actions') {
        $candidate = strtolower(trim((string) ($row['last_error_host'] ?? '')));
    } elseif ($kind === 'fanout') {
        $candidate = strtolower((string) (parse_url((string) ($row['inbox_url'] ?? ''), PHP_URL_HOST) ?: ''));
    } else {
        // Publish payloads contain the remote delivery metadata. Matching the
        // serialized host is intentionally conservative and never broadens a
        // replay beyond the requested host.
        return str_contains(strtolower((string) ($row['payload_json'] ?? '')), $wantHost);
    }
    return $candidate === $wantHost || str_ends_with($candidate, '.' . $wantHost);
};

foreach ($wanted as $kind) {
    $table = $tables[$kind];
    $st = $db->prepare("SELECT * FROM {$table} WHERE status = 'failed' AND updated_at <= ? ORDER BY updated_at ASC, id ASC LIMIT ?");
    $st->bindValue(1, $cutoff);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll() ?: [];
    $selected = array_values(array_filter(
        $rows,
        static function ($row) use ($rowMatchesHost, $kind, $host): bool {
            return is_array($row) && $rowMatchesHost($kind, $row, $host);
        }
    ));
    $replayed = 0;
    if ($apply) {
        foreach ($selected as $row) {
            $sql = $kind === 'actions'
                ? "UPDATE {$table} SET status='pending', attempts=0, next_attempt_at=?, claimed_at=NULL, last_error=NULL, last_error_host=NULL, last_error_code=NULL, updated_at=? WHERE id=? AND status='failed'"
                : "UPDATE {$table} SET status='pending', attempts=0, next_attempt_at=?, claimed_at=NULL, last_error=NULL, updated_at=? WHERE id=? AND status='failed'";
            $up = $db->prepare($sql);
            $up->execute([$now, $now, (int) $row['id']]);
            $replayed += $up->rowCount();
        }
    }
    $summary[$kind] = ['matched' => count($selected), 'replayed' => $replayed];
}

fwrite(STDOUT, json_encode([
    'mode' => $apply ? 'apply' : 'dry-run',
    'queue' => $queue,
    'host' => $host !== '' ? $host : null,
    'min_age_minutes' => $minAge,
    'summary' => $summary,
], JSON_UNESCAPED_SLASHES) . "\n");
