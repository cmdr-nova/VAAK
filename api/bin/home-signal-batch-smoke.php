<?php
declare(strict_types=1);

/**
 * Passive dwell batches and the shadow scorecard migration.
 * SQLite only. No Postgres, Redis, or network.
 */

require_once dirname(__DIR__, 2) . '/deploy/remote/migrate.php';

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

function ap_db_now(): string
{
    return gmdate('c');
}

function ap_db(): PDO
{
    return $GLOBALS['db'];
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE ap_user_signals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_user_id INTEGER NOT NULL,
        platform TEXT NOT NULL,
        signal_type TEXT NOT NULL,
        target_key TEXT NOT NULL,
        weight REAL NOT NULL DEFAULT 1,
        metadata_json TEXT NOT NULL DEFAULT \'{}\',
        created_at TEXT NOT NULL
    )'
);
$GLOBALS['db'] = $db;
require_once dirname(__DIR__) . '/ap-signals.php';

$good = static function (int $seconds, string $actor = 'https://example.test/users/a'): array {
    return [
        'platform' => 'fedi',
        'kind' => 'dwell',
        'target_key' => 'https://example.test/users/a/notes/1',
        'target_actor' => $actor,
        'dwell_seconds' => $seconds,
    ];
};

$batch = [];
for ($i = 0; $i < 10; $i++) {
    $row = $good(40);
    $row['target_key'] .= '/' . $i;
    $batch[] = $row;
}
$batch[] = ['platform' => 'fedi', 'kind' => 'dwell', 'target_key' => 'x', 'target_actor' => 'not-a-url', 'dwell_seconds' => 40];
$batch[] = ['platform' => 'fedi', 'kind' => 'scroll', 'target_key' => 'https://example.test/n', 'target_actor' => 'https://example.test/users/a', 'dwell_seconds' => 0];
$normalized = ap_signal_passive_normalize_batch($batch);
$check(count($normalized) === 8, 'a batch keeps at most eight valid measurements');
$check($normalized[0]['dwell_seconds'] === 40, 'a real pause keeps its dwell seconds');
$check(ap_signal_passive_normalize_event('fedi', 'dwell', 'https://example.test/n', 'https://example.test/users/a', 9000)['dwell_seconds'] === 300, 'dwell is capped at five minutes');
$short = ap_signal_passive_normalize_event('fedi', 'dwell', 'https://example.test/n', 'https://example.test/users/a', 3);
$check(is_array($short) && $short['dwell_seconds'] === 3, 'a glance is still a dwell measurement for the recorder to reject');
$check(ap_signal_passive_normalize_event('fedi', 'dwell', 'https://example.test/n', 'javascript:alert(1)', 40) === null, 'a non-actor target is dropped');
$check(ap_signal_passive_normalize_event('bsky', 'click', 'at://did:plc:abc/app.bsky.feed.post/1', 'did:plc:abc', 0)['kind'] === 'click', 'a Bluesky click is a local signal');
$check(ap_signal_passive_normalize_batch('{"not":"a list"}') === [], 'a non-list payload stores nothing');

$check(ap_signal_record_passive(0, 'fedi', 'dwell', 'https://example.test/n', 'https://example.test/users/a', 40) === false, 'a guest stores no dwell');
$check(ap_signal_record_passive(1, 'fedi', 'dwell', 'https://example.test/n', 'https://example.test/users/a', 5) === false, 'five seconds is not dwell');
$check(ap_signal_record_passive(1, 'fedi', 'dwell', 'https://example.test/n', 'https://example.test/users/a', 40) === true, 'forty seconds is stored');
$check(ap_signal_record_passive(1, 'fedi', 'dwell', 'https://example.test/n', 'https://example.test/users/a', 90) === false, 'the same post is not stored again inside the cooldown');
$row = $db->query('SELECT signal_type, weight, metadata_json FROM ap_user_signals')->fetch(PDO::FETCH_ASSOC);
$check(is_array($row) && $row['signal_type'] === 'dwell' && (float) $row['weight'] === 2.0, 'forty seconds lands in the middle dwell bucket');
$meta = json_decode((string) ($row['metadata_json'] ?? ''), true);
$check(is_array($meta) && ($meta['target_actor'] ?? '') === 'https://example.test/users/a' && !isset($meta['scroll']) && !isset($meta['url']), 'stored dwell is the actor and a bucket, not a scroll trace');
$check((int) $db->query('SELECT COUNT(*) FROM ap_user_signals')->fetchColumn() === 1, 'rejected dwell writes no row');
$tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
$check($tables === ['ap_user_signals'], 'dwell does not create a federated table');

$api = dirname(__DIR__);
$names = array_map('basename', vaak_migrate_deploy_sql_files($api));
$check(in_array('20261026_ml_shadow_scorecard_postgres.sql', $names, true), 'deploy applies the scorecard table');
$sqlPath = $api . '/migrations/20261026_ml_shadow_scorecard_postgres.sql';
$sql = (string) file_get_contents($sqlPath);
$check(str_contains($sql, 'CREATE TABLE IF NOT EXISTS ap_ml_shadow_scorecard'), 'the scorecard table is created only if it is missing');
$check(str_contains($sql, 'UNIQUE (owner_user_id, run_on)'), 'one scorecard row per owner per day');
$check(!str_contains($sql, 'password') && !str_contains($sql, 'postgres://'), 'the migration file has no credentials');
$plan = vaak_migrate_additive_plan(vaak_migrate_sql_statements($sql), '20261026_ml_shadow_scorecard_postgres.sql');
$check($plan['tables'] === ['ap_ml_shadow_scorecard'], 'the migration creates only the scorecard table');
$joined = implode("\n", $plan['statements']);
$check(str_contains($joined, 'GRANT SELECT, INSERT, UPDATE, DELETE ON ap_ml_shadow_scorecard TO "www-data"'), 'the app role can write scorecards');
$check(!preg_match('/\b(ALTER|DROP|TRUNCATE|INSERT\s+INTO|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', $joined), 'the migration does not change an existing table');

if ($failures !== []) {
    fwrite(STDERR, "home-signal-batch-smoke failed\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "home-signal-batch-smoke ok checks={$checks}\n";
