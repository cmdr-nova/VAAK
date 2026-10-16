<?php
declare(strict_types=1);

/**
 * Wafrn cross-post dedupe and text ask cards.
 * No Postgres. The fixture is the same input the Rust unit test replays.
 */

require_once dirname(__DIR__) . '/ap-wafrn-crosspost.php';
require_once dirname(__DIR__, 2) . '/deploy/remote/migrate.php';

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$fixturePath = dirname(__DIR__) . '/fixtures/wafrn-crosspost.json';
$fixture = json_decode((string) file_get_contents($fixturePath), true);
$check(is_array($fixture['cases'] ?? null) && count($fixture['cases']) >= 16, 'fixture has linkage, fallback, and ask cases');

foreach ($fixture['cases'] as $case) {
    if (!is_array($case)) {
        $check(false, 'fixture case is an object');
        continue;
    }
    $id = (string) ($case['id'] ?? '');
    $output = ap_wafrn_replay($case);
    $expect = $case['expect'] ?? null;
    $check($output == $expect, $id . ' matches the shared fixture');
}

$api = dirname(__DIR__);
$root = dirname(__DIR__, 2);
$names = array_map('basename', vaak_migrate_deploy_sql_files($api));
$check(in_array('20261016_wafrn_bsky_actors_postgres.sql', $names, true), 'deploy applies the wafrn actor table');
$migration = (string) file_get_contents($api . '/migrations/20261016_wafrn_bsky_actors_postgres.sql');
$check(str_contains($migration, 'CREATE TABLE IF NOT EXISTS wafrn_bsky_actors'), 'the table is created only if it is missing');
$check(str_contains($migration, 'PRIMARY KEY (actor_id, bsky_did)'), 'actor lookups use the primary key');
$check(!str_contains($migration, 'CREATE INDEX CONCURRENTLY'), 'the additive file does not build the concurrent index');
$check(!preg_match('/\b(ALTER|DROP|TRUNCATE|INSERT\s+INTO|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', $migration), 'the additive file does not change an existing table');

final class WafrnMigrationRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $statements = [];

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;
        return 0;
    }
}
$recording = new WafrnMigrationRecordingPdo('sqlite::memory:');
$created = vaak_migrate_apply_additive_sql($recording, $api . '/migrations/20261016_wafrn_bsky_actors_postgres.sql');
$check($created === ['wafrn_bsky_actors'], 'the additive plan creates wafrn_bsky_actors');
$check(count($recording->statements) === 2, 'the file applies the table and the grant');

$spec = vaak_migrate_wafrn_crosspost_index_spec();
$check(
    $spec['create'] === 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_wafrn_bsky_actors_did ON wafrn_bsky_actors (bsky_did)',
    'the DID lookup index is created concurrently'
);
$check(str_starts_with($spec['drop'], 'DROP INDEX CONCURRENTLY '), 'an invalid index is rebuilt without a blocking drop');
$check(vaak_migrate_wafrn_crosspost_index_statements('skip') === [], 'a valid index is left untouched');
$check(vaak_migrate_wafrn_crosspost_index_statements('missing-table') === [], 'a missing table waits for the additive file');
$check(
    vaak_migrate_wafrn_crosspost_index_statements('create') === [$spec['create']],
    'the first deploy only creates the DID index'
);
$check(
    vaak_migrate_wafrn_crosspost_index_statements('rebuild') === [$spec['drop'], $spec['create']],
    'an invalid DID index is rebuilt concurrently'
);
$migrate = (string) file_get_contents($root . '/deploy/remote/migrate.php');
$check(str_contains($migrate, 'vaak_migrate_wafrn_crosspost_indexes($lock)'), 'migrate.php builds the DID index on its autocommit connection');
$check(
    str_contains($migrate, 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_wafrn_bsky_actors_did'),
    'migrate.php contains CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_wafrn_bsky_actors_did'
);

$schema = (string) file_get_contents($api . '/ap-db.php');
$check(str_contains($schema, "require_once __DIR__ . '/ap-wafrn-crosspost.php';"), 'actor fetch can remember alsoKnownAs');
$check(str_contains($schema, 'ap_wafrn_remember_actor_document'), 'the existing actor fetch stores Wafrn DIDs');
$check(!preg_match('/CREATE\s+INDEX\b[^;]*idx_wafrn_bsky_actors_did/', $schema), 'a page load does not build the DID index');

$admin = (string) file_get_contents($api . '/ap-admin.php');
$check(str_contains($admin, 'ap_wafrn_filter_timeline_items'), 'timeline pages drop Wafrn twins');
$check(str_contains($admin, 'ap_wafrn_text_ask_for_linked'), 'the status card paints a linked text ask');
$check(!str_contains($admin, 'ap_wafrn_filter_timeline_items($notif'), 'notifications are not part of this filter');

$bsky = (string) file_get_contents($api . '/ap-bsky.php');
$check(!str_contains($bsky, 'wafrn_bsky_actors'), 'Bluesky notification ingestion is unchanged');

if ($failures !== []) {
    fwrite(STDERR, "wafrn-crosspost-smoke failed\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "wafrn-crosspost-smoke ok checks={$checks}\n";
