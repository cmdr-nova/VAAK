<?php
declare(strict_types=1);

/**
 * CPU indexes for the author-did lookup and the observation sync.
 * No Postgres. Proves deploy/remote/migrate.php builds them with
 * CREATE INDEX CONCURRENTLY, outside a transaction, and does not add a
 * second unique index when (bsky_uri, owner_user_id) is already unique.
 * A page load must not create the author-did expression index. The Mastodon
 * lookup that uses lower(author_did) is unchanged.
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

$root = dirname(__DIR__, 2);
$api = dirname(__DIR__);
$schema = (string) file_get_contents($api . '/ap-db.php');
$bsky = (string) file_get_contents($api . '/ap-bsky.php');
$entities = (string) file_get_contents($api . '/ap-masto-entities.php');
$migrate = (string) file_get_contents($root . '/deploy/remote/migrate.php');
$indexSql = (string) file_get_contents($api . '/migrations/20261015_cpu_load_indexes_postgres.sql');

$check(str_contains($migrate, 'vaak_migrate_cpu_indexes($lock)'), 'migrate.php builds the cpu indexes on its autocommit connection');
$check(!preg_match('/CREATE\s+INDEX\b[^;]*idx_bsky_posts_lower_author_did/', $schema), 'a page load does not build the author-did expression index');
$check(!preg_match('/CREATE\s+INDEX\b[^;]*idx_bsky_posts_lower_author_did/', $bsky), 'Bluesky setup does not build the author-did expression index');

$specs = vaak_migrate_cpu_index_specs();
$check(
    isset(
        $specs['idx_bsky_posts_lower_author_did'],
        $specs['idx_bsky_posts_seen'],
        $specs['idx_bsky_post_observations_last_seen'],
        $specs['idx_remote_actors_lower_host_username']
    ),
    'deploy knows the author-did, seen, last-seen, and actor alias indexes'
);
$flatSql = preg_replace('/\s+/', ' ', $indexSql) ?: '';
foreach ($specs as $name => $spec) {
    $check(str_starts_with($spec['create'], 'CREATE INDEX CONCURRENTLY '), $name . ' is created concurrently');
    $check(str_starts_with($spec['drop'], 'DROP INDEX CONCURRENTLY '), $name . ' invalid rebuild does not take a blocking drop');
    $check(str_contains($spec['create'], 'IF NOT EXISTS'), $name . ' create is idempotent');
    $check(str_contains($flatSql, preg_replace('/\s+/', ' ', $spec['create']) ?: $spec['create']), $name . ' matches the concurrent SQL file');
    $check(vaak_migrate_notification_index_plan(true, true) === 'skip', $name . ' valid index is left untouched');
    $check(vaak_migrate_cpu_index_statements($name, 'skip') === [], $name . ' second deploy issues no DDL');
    $check(vaak_migrate_cpu_index_statements($name, 'create') === [$spec['create']], $name . ' first deploy only creates');
    $check(
        vaak_migrate_cpu_index_statements($name, 'rebuild') === [$spec['drop'], $spec['create']],
        $name . ' invalid index is rebuilt concurrently'
    );
}
$check(
    str_contains($specs['idx_bsky_posts_lower_author_did']['create'], 'ON bsky_posts (lower(author_did))'),
    'author-did index matches the lookup expression'
);
$check(
    str_contains($entities, 'SELECT DISTINCT lower(author_handle) AS h, lower(author_did) AS d')
        && str_contains($entities, 'WHERE lower(author_did) IN ($ph)'),
    'the followed-handle lookup still filters on lower(author_did)'
);
$check(
    str_contains($entities, 'SELECT DISTINCT lower(author_handle) AS h FROM bsky_posts')
        && substr_count($entities, 'WHERE lower(author_did) IN ($ph)') === 2,
    'the hidden-handle lookup still filters on lower(author_did)'
);
$check(
    str_contains($specs['idx_remote_actors_lower_host_username']['create'], "ON remote_actors (lower(COALESCE(host, '')), lower(COALESCE(username, '')))"),
    'actor alias index matches the lookup expression'
);
$check(
    str_contains($entities, "WHERE lower(COALESCE(host,\\'\\')) = ? AND lower(COALESCE(username,\\'\\')) = ?"),
    'the actor alias lookup still filters on lower(COALESCE(host)) and lower(COALESCE(username))'
);
$check(!preg_match('/CREATE\s+INDEX\b[^;]*idx_remote_actors_lower_host_username/', $schema), 'a page load does not build the actor alias index');

$files = array_map('basename', vaak_migrate_deploy_sql_files($api));
$check(!in_array('20261015_cpu_load_indexes_postgres.sql', $files, true), 'concurrent index file stays out of the additive loop');

$unique = vaak_migrate_observation_unique_sql();
$check(str_starts_with($unique, 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '), 'missing observation key is created concurrently');
$check(str_contains($unique, '(bsky_uri, owner_user_id)'), 'observation key is the uri/owner pair');
$check(vaak_migrate_observation_unique_statements(true, true) === [], 'an existing primary key is not duplicated');
$check(vaak_migrate_observation_unique_statements(false, false) === [], 'a missing observations table waits');
$check(vaak_migrate_observation_unique_statements(true, false) === [$unique], 'a table without the key gains one unique index');
$check(!preg_match('/CREATE\s+UNIQUE\s+INDEX/i', $indexSql), 'the SQL file does not add a second unique index');

final class CpuIndexTxPdo extends PDO
{
    public function inTransaction(): bool
    {
        return true;
    }
}
$blocked = false;
try {
    vaak_migrate_cpu_indexes(new CpuIndexTxPdo('sqlite::memory:'));
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'cpu indexes must not run inside a transaction');
}
$check($blocked, 'concurrent index build refuses an open transaction');
$check(!str_contains($indexSql, 'password') && !str_contains($indexSql, 'postgres://'), 'index file has no credentials');

if ($failures !== []) {
    fwrite(STDERR, "cpu-index-smoke failed\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "cpu-index-smoke ok checks={$checks}\n";
