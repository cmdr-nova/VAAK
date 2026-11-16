#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Recommendation interaction indexes.
 * No Postgres. Proves deploy/remote/migrate.php builds the candidate probes
 * with CREATE INDEX CONCURRENTLY, outside a transaction, and that the PHP
 * and Rust lookups use those expressions. A page load must not create them.
 */

require_once dirname(__DIR__, 2) . '/deploy/remote/migrate.php';
require_once dirname(__DIR__) . '/ap-recommendations.php';

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
$migrate = (string) file_get_contents($root . '/deploy/remote/migrate.php');
$indexSql = (string) file_get_contents($api . '/migrations/20261116_recommendation_interaction_indexes_postgres.sql');
$rust = (string) file_get_contents($root . '/rust/vaak-worker/src/recommendations.rs');
$pgsql = ap_home_recommendation_interacted_sql(true, 2);
$sqlite = ap_home_recommendation_interacted_sql(false, 1);

$check(str_contains($migrate, 'vaak_migrate_recommendation_indexes($lock, $apiRoot)'), 'migrate.php builds the recommendation indexes on its autocommit connection');
$check(vaak_migrate_recommendations_php($api) === $api . '/ap-recommendations.php', 'a passed api root is the file migrate requires');
$check(vaak_migrate_recommendations_php(null) === $api . '/ap-recommendations.php', 'an omitted api root still finds the git checkout');
$missingRoot = false;
try {
    vaak_migrate_recommendations_php($api . '/missing-release');
} catch (RuntimeException $e) {
    $missingRoot = str_contains($e->getMessage(), 'ap-recommendations.php')
        && str_contains($e->getMessage(), 'missing-release');
}
$check($missingRoot, 'a release api directory without ap-recommendations.php fails clearly and does not fall back');
$check(!preg_match('/CREATE\s+INDEX\b[^;]*idx_reco_/', $schema), 'a page load does not build the recommendation indexes');
$check(!str_contains($pgsql, 'AS MATERIALIZED') && !str_contains($sqlite, 'AS MATERIALIZED'), 'the lookup does not materialize notes or signals');
$check(str_contains($pgsql, 'EXISTS') && str_contains($pgsql, 'LIMIT 1'), 'postgres lookup probes one candidate and stops');
$check(!str_contains($pgsql, ' LIKE '), 'actor prefix is not a LIKE pattern');
$check(str_contains($pgsql, "o.id ^@ (v.actor || '/notes/')"), 'postgres prefix match is starts-with');
$check(!str_contains($sqlite, '^@') && !str_contains($sqlite, '::jsonb'), 'sqlite lookup stays on sqlite functions');
$check(str_contains($pgsql, ap_home_recommendation_pgsql_quote_key('o.raw_create_json')), 'postgres quote probe uses the indexed expression');
$check(str_contains($pgsql, "strpos(o.raw_create_json,'quote')>0"), 'non-empty quote probe repeats the partial index predicate');
$check(str_contains($rust, ap_home_recommendation_pgsql_quote_key('o.raw_create_json')), 'rust quote probe matches php');
$check(str_contains($rust, ap_home_recommendation_pgsql_signal_key('s.metadata_json', 'object_id')), 'rust signal object probe matches php');
$check(str_contains($rust, ap_home_recommendation_pgsql_signal_key('s.metadata_json', 'uri')), 'rust signal uri probe matches php');
$check(!str_contains($rust, 'AS MATERIALIZED'), 'rust lookup does not materialize the owner history');

$specs = vaak_migrate_recommendation_index_specs();
$check(count($specs) === 9, 'deploy knows the nine recommendation probes');
$flatSql = preg_replace('/\s+/', ' ', $indexSql) ?: '';
foreach ($specs as $name => $spec) {
    $check(str_starts_with($spec['create'], 'CREATE INDEX CONCURRENTLY '), $name . ' is created concurrently');
    $check(str_starts_with($spec['drop'], 'DROP INDEX CONCURRENTLY '), $name . ' invalid rebuild does not take a blocking drop');
    $check(str_contains($spec['create'], 'IF NOT EXISTS'), $name . ' create is idempotent');
    $check(str_contains($flatSql, preg_replace('/\s+/', ' ', $spec['create']) ?: $spec['create']), $name . ' matches the concurrent SQL file');
    $check(vaak_migrate_notification_index_plan(true, true) === 'skip', $name . ' valid index is left untouched');
    $check(vaak_migrate_recommendation_index_statements($name, 'skip') === [], $name . ' second deploy issues no DDL');
    $check(vaak_migrate_recommendation_index_statements($name, 'create') === [$spec['create']], $name . ' first deploy only creates');
    $check(
        vaak_migrate_recommendation_index_statements($name, 'rebuild') === [$spec['drop'], $spec['create']],
        $name . ' invalid index is rebuilt concurrently'
    );
}
$check(str_contains($specs['idx_reco_outbox_quote_key']['create'], "WHERE strpos(raw_create_json,'quote')>0"), 'quote index skips notes that cannot hold a quote');
$functions = vaak_migrate_recommendation_function_statements();
$check(count($functions) === 2, 'deploy replaces the two helpers in place');
foreach ($functions as $statement) {
    $flatStatement = preg_replace('/\s+/', ' ', trim($statement)) ?: '';
    $check(str_contains($flatSql, $flatStatement), 'helper SQL matches the migration file');
}
$functionSql = implode("\n", $functions);
$check(
    str_contains($functionSql, 'CREATE OR REPLACE FUNCTION vaak_reco_quote_key(raw text) RETURNS text')
        && str_contains($functionSql, 'CREATE OR REPLACE FUNCTION vaak_reco_signal_key(raw text, field text) RETURNS text'),
    'existing recommendation helpers are replaced with the same signature'
);
$check(str_contains($functionSql, "doc #>> '{object,quote}'") && str_contains($functionSql, "doc #>> '{object,quoteUrl}'") && str_contains($functionSql, "doc #>> '{object,_misskey_quote}'"), 'quote helper reads the same three fields as the old document');
$check(str_contains($functionSql, 'doc ->> field') && str_contains($functionSql, 'RETURN rtrim(val, \'/\')'), 'signal helper still returns the trimmed field');
$check(
    !str_contains($functionSql, 'EXCEPTION')
        && substr_count($functionSql, 'pg_catalog.pg_input_is_valid(raw, \'jsonb\')') === 2
        && substr_count($functionSql, 'IMMUTABLE') === 2
        && substr_count($functionSql, 'PARALLEL SAFE') === 2,
    'helpers stay immutable and parallel safe without a subtransaction'
);
$planActions = [];
foreach (array_keys($specs) as $indexName) {
    $planActions[$indexName] = 'skip';
}
$planActions['idx_reco_outbox_quote_key'] = 'rebuild';
$planActions['idx_reco_signals_owner_meta_uri'] = 'create';
$plan = vaak_migrate_recommendation_statements($planActions);
$flatPlan = array_map(static fn (string $sql): string => preg_replace('/\s+/', ' ', trim($sql)) ?: $sql, $plan);
$dropAt = null;
$replaceQuoteAt = null;
$replaceSignalAt = null;
$createQuoteAt = null;
$createSignalAt = null;
foreach ($flatPlan as $i => $sql) {
    if (str_starts_with($sql, 'DROP INDEX CONCURRENTLY IF EXISTS idx_reco_outbox_quote_key')) {
        $dropAt = $i;
    }
    if (str_starts_with($sql, 'CREATE OR REPLACE FUNCTION vaak_reco_quote_key')) {
        $replaceQuoteAt = $i;
    }
    if (str_starts_with($sql, 'CREATE OR REPLACE FUNCTION vaak_reco_signal_key')) {
        $replaceSignalAt = $i;
    }
    if (str_starts_with($sql, 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reco_outbox_quote_key')) {
        $createQuoteAt = $i;
    }
    if (str_starts_with($sql, 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reco_signals_owner_meta_uri')) {
        $createSignalAt = $i;
    }
}
$check(
    $dropAt !== null && $replaceQuoteAt !== null && $replaceSignalAt !== null && $createQuoteAt !== null && $createSignalAt !== null
        && $dropAt < $replaceQuoteAt && $dropAt < $replaceSignalAt
        && $replaceQuoteAt < $createQuoteAt && $replaceSignalAt < $createQuoteAt
        && $replaceSignalAt < $createSignalAt,
    'invalid quote index is dropped before either helper is replaced, then rebuilt'
);
$skipPlan = vaak_migrate_recommendation_statements(array_fill_keys(array_keys($specs), 'skip'));
$check(count($skipPlan) === 2 && !str_contains(implode("\n", $skipPlan), 'INDEX'), 'valid indexes are left in place while the helpers are replaced');
$check(str_contains($specs['idx_reco_signals_owner_target']['create'], 'WHERE weight>0 AND signal_type IN (' . ap_home_recommendation_signal_types_sql() . ')'), 'signal index repeats the positive-signal predicate');
$check(vaak_migrate_notification_index_plan(false, null) === 'missing-table', 'a missing table does not pretend the index exists');
$files = array_map('basename', vaak_migrate_deploy_sql_files($api));
$check(!in_array('20261116_recommendation_interaction_indexes_postgres.sql', $files, true), 'concurrent index file stays out of the additive loop');

final class RecommendationIndexTxPdo extends PDO
{
    public function inTransaction(): bool
    {
        return true;
    }
}
$blocked = false;
try {
    vaak_migrate_recommendation_indexes(new RecommendationIndexTxPdo('sqlite::memory:'));
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'recommendation indexes must not run inside a transaction');
}
$check($blocked, 'recommendation indexes refuse to run inside a transaction');

if ($failures !== []) {
    fwrite(STDERR, "recommendation-index: FAIL\n" . implode("\n", $failures) . "\n");
    exit(1);
}
echo "recommendation-index: PASS ($checks checks; no database)\n";
