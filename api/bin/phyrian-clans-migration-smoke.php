<?php
declare(strict_types=1);

/**
 * Phyrian clans deploy migration.
 * No Postgres. Proves deploy/remote/migrate.php picks up the clans SQL file
 * and will only run its idempotent CREATE TABLE / INDEX / GRANT statements.
 * ap-db.php stays unaware of the tables, so a page load with the clans
 * switch off does not gain a clan query.
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

final class ClansMigrationRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $statements = [];

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;
        return 0;
    }
}

$apiRoot = dirname(__DIR__);
$files = vaak_migrate_deploy_sql_files($apiRoot);
$names = array_map('basename', $files);
$check(
    $names === [
        '20261014_phyrian_clans_postgres.sql',
        '20261014_event_quote_targets_postgres.sql',
        '20261016_wafrn_bsky_actors_postgres.sql',
        '20261019_phyrian_grid_lineage_postgres.sql',
        '20261021_bsky_follow_carry_postgres.sql',
        '20261026_ml_shadow_scorecard_postgres.sql',
        '20261027_fanout_host_health_postgres.sql',
        '20261027_listenbrainz_links_postgres.sql',
        '20261028_nostr_postgres.sql',
        '20261028_nostr_actions_postgres.sql',
        '20261028_nostr_shares_postgres.sql',
        '20261028_nostr_relays_postgres.sql',
        '20261028_nostr_moderation_postgres.sql',
        '20261028_nostr_notifs_postgres.sql',
        '20261028_nostr_archive_postgres.sql',
        '20261029_inbox_spool_postgres.sql',
    ],
    'deploy runner picks up the clans migration and the quote-target table'
);
$expectedPath = realpath($apiRoot . '/migrations/20261014_phyrian_clans_postgres.sql');
$check($expectedPath !== false && ($files[0] ?? '') === $expectedPath, 'the picked-up path starts with the clans SQL file');

$migration = (string) file_get_contents($files[0]);
$check(substr_count($migration, 'CREATE TABLE IF NOT EXISTS') === 2, 'the file creates both tables only if they are missing');
$check(!str_contains($migration, 'ALTER ') && !str_contains($migration, 'phyrian_players'), 'the file does not change an existing table');

$db = new ClansMigrationRecordingPdo('sqlite::memory:');
$created = vaak_migrate_apply_additive_sql($db, $files[0]);
$check($created === ['phyrian_clans', 'phyrian_clan_members'], 'both clan tables are in the apply plan');
$check(count($db->statements) === 7, 'all seven clans statements are applied');
$joined = implode("\n", $db->statements);
$check(substr_count($joined, 'CREATE TABLE IF NOT EXISTS') === 2, 'applied creates are idempotent');
$check(substr_count($joined, 'CREATE INDEX IF NOT EXISTS') === 2, 'applied indexes are idempotent');
$check(str_contains($joined, 'CREATE TABLE IF NOT EXISTS phyrian_clans'), 'phyrian_clans is created');
$check(str_contains($joined, 'CREATE TABLE IF NOT EXISTS phyrian_clan_members'), 'phyrian_clan_members is created');
$check(str_contains($joined, 'GRANT SELECT, INSERT, UPDATE, DELETE ON phyrian_clans'), 'the app role is granted the clans table');
$check(str_contains($joined, 'ON SEQUENCE phyrian_clans_id_seq'), 'the app role is granted the clans id sequence');
$check(!preg_match('/\b(ALTER|DROP|TRUNCATE|INSERT\s+INTO|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', $joined), 'applied statements do not change existing tables');

$again = new ClansMigrationRecordingPdo('sqlite::memory:');
$check(vaak_migrate_apply_additive_sql($again, $files[0]) === $created, 'a second deploy plans the same tables');
$check($again->statements === $db->statements, 'a second deploy applies the same idempotent statements');

$follow = $apiRoot . '/migrations/20261014_follow_state_and_fallback_postgres.sql';
$followDb = new ClansMigrationRecordingPdo('sqlite::memory:');
$followBlocked = false;
try {
    vaak_migrate_apply_additive_sql($followDb, $follow);
} catch (RuntimeException $e) {
    $followBlocked = str_contains($e->getMessage(), 'would change an existing table');
}
$check($followBlocked && $followDb->statements === [], 'an ALTER migration is refused before any statement runs');

$bsky = $apiRoot . '/migrations/20261014_bsky_posts_lookup_indexes_postgres.sql';
$bskyDb = new ClansMigrationRecordingPdo('sqlite::memory:');
$bskyBlocked = false;
try {
    vaak_migrate_apply_additive_sql($bskyDb, $bsky);
} catch (RuntimeException $e) {
    $bskyBlocked = str_contains($e->getMessage(), 'not an additive');
}
$check($bskyBlocked && $bskyDb->statements === [], 'an index on an existing table is not picked up by this runner');

$rejectDir = sys_get_temp_dir() . '/vaak-clans-migrate-' . bin2hex(random_bytes(4));
mkdir($rejectDir);
$indexOnExisting = $rejectDir . '/index-existing.sql';
file_put_contents(
    $indexOnExisting,
    "CREATE TABLE IF NOT EXISTS phyrian_clans (id BIGINT);\nCREATE INDEX IF NOT EXISTS idx_following_extra ON following (host);\n"
);
$indexDb = new ClansMigrationRecordingPdo('sqlite::memory:');
$indexBlocked = false;
try {
    vaak_migrate_apply_additive_sql($indexDb, $indexOnExisting);
} catch (RuntimeException $e) {
    $indexBlocked = str_contains($e->getMessage(), 'indexes a table it does not create');
}
$check($indexBlocked && $indexDb->statements === [], 'a new index on an existing table executes nothing');

$grantExisting = $rejectDir . '/grant-existing.sql';
file_put_contents(
    $grantExisting,
    "CREATE TABLE IF NOT EXISTS phyrian_clans (id BIGINT);\nGRANT SELECT ON ap_users TO \"www-data\";\n"
);
$grantDb = new ClansMigrationRecordingPdo('sqlite::memory:');
$grantBlocked = false;
try {
    vaak_migrate_apply_additive_sql($grantDb, $grantExisting);
} catch (RuntimeException $e) {
    $grantBlocked = str_contains($e->getMessage(), 'grants on an object it does not create');
}
$check($grantBlocked && $grantDb->statements === [], 'a grant on an existing table executes nothing');
unlink($indexOnExisting);
unlink($grantExisting);
rmdir($rejectDir);

$empty = sys_get_temp_dir() . '/vaak-clans-missing-' . bin2hex(random_bytes(4));
mkdir($empty);
$missing = false;
try {
    vaak_migrate_deploy_sql_files($empty);
} catch (RuntimeException $e) {
    $missing = str_contains($e->getMessage(), '20261014_phyrian_clans_postgres.sql');
}
rmdir($empty);
$check($missing, 'a release without the clans migration fails closed');

$dbSource = (string) file_get_contents($apiRoot . '/ap-db.php');
$clansSource = (string) file_get_contents($apiRoot . '/ap-phyrian-clans.php');
$adminSource = (string) file_get_contents($apiRoot . '/ap-admin.php');
$check(!str_contains($dbSource, 'phyrian_clans'), 'ap-db.php does not create or query clan tables on page load');
$check(!str_contains($dbSource, 'vaak_migrate_'), 'page-load schema code does not call the deploy runner');
$check(!str_contains($clansSource, 'CREATE TABLE') && !str_contains($clansSource, 'migrations/'), 'the clans helper does not migrate on request');
$check(!str_contains($adminSource, 'migrate.php') && !str_contains($adminSource, '20261014_phyrian_clans'), 'admin does not apply the clans migration');

if ($failures !== []) {
    fwrite(STDERR, "phyrian-clans-migration FAIL (" . count($failures) . ")\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "phyrian-clans-migration: PASS ({$checks} checks)\n";
