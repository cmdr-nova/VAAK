<?php
declare(strict_types=1);

/**
 * Home Bluesky lookup indexes.
 * No Postgres. Proves deploy/remote/migrate.php builds the rkey expression
 * index and the thin-media partial index with CREATE INDEX CONCURRENTLY,
 * outside a transaction, and rebuilds an invalid leftover. A page load must
 * not create them. PHP and Rust lookups use the same split_part expression.
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
$expr = "split_part(bsky_uri, '/app.bsky.feed.post/', 2)";
$schema = (string) file_get_contents($api . '/ap-db.php');
$migrate = (string) file_get_contents($root . '/deploy/remote/migrate.php');
$indexSql = (string) file_get_contents($api . '/migrations/20261014_bsky_posts_home_indexes_postgres.sql');
$bsky = (string) file_get_contents($api . '/ap-bsky.php');
$admin = (string) file_get_contents($api . '/ap-admin.php');
$hydrate = (string) file_get_contents($root . '/rust/vaak-worker/src/home_hydrate_ranked.rs');
$facts = (string) file_get_contents($root . '/rust/vaak-worker/src/search_final_facts.rs');
$quote = (string) file_get_contents($root . '/rust/vaak-worker/src/profile_quote_warm.rs');
$thin = (string) file_get_contents($root . '/rust/vaak-worker/src/thin_media.rs');
$thinWarm = (string) file_get_contents($root . '/rust/vaak-worker/src/thin_media_warm.rs');
$jetstream = (string) file_get_contents($root . '/rust/vaak-worker/src/jetstream/mod.rs');

$check(str_contains($migrate, 'vaak_migrate_home_bsky_indexes($lock)'), 'migrate.php builds the home bsky indexes on its autocommit connection');
$check(!preg_match('/CREATE\s+INDEX\b[^;]*idx_bsky_posts_post_rkey/', $schema), 'a page load does not build the rkey expression index');
$check(!preg_match('/CREATE\s+INDEX\b[^;]*idx_bsky_posts_embed_indexed/', $schema), 'a page load does not build the embed partial index');

$specs = vaak_migrate_home_bsky_index_specs();
$check(isset($specs['idx_bsky_posts_post_rkey'], $specs['idx_bsky_posts_embed_indexed']), 'deploy knows both home bsky indexes');
$flatSql = preg_replace('/\s+/', ' ', $indexSql) ?: '';
foreach ($specs as $name => $spec) {
    $check(str_starts_with($spec['create'], 'CREATE INDEX CONCURRENTLY '), $name . ' is created concurrently');
    $check(str_starts_with($spec['drop'], 'DROP INDEX CONCURRENTLY '), $name . ' invalid rebuild does not take a blocking drop');
    $check(str_contains($spec['create'], 'IF NOT EXISTS'), $name . ' create is idempotent');
    $check(str_contains($flatSql, preg_replace('/\s+/', ' ', $spec['create']) ?: $spec['create']), $name . ' matches the concurrent SQL file');
    $check(vaak_migrate_notification_index_plan(true, true) === 'skip', $name . ' valid index is left untouched');
    $check(vaak_migrate_home_bsky_index_statements($name, 'skip') === [], $name . ' second deploy issues no DDL');
    $check(vaak_migrate_home_bsky_index_statements($name, 'create') === [$spec['create']], $name . ' first deploy only creates');
    $check(
        vaak_migrate_home_bsky_index_statements($name, 'rebuild') === [$spec['drop'], $spec['create']],
        $name . ' invalid index is rebuilt concurrently'
    );
}
$check(str_contains($specs['idx_bsky_posts_post_rkey']['create'], $expr), 'rkey index uses the Home lookup expression');
$check(
    str_contains($specs['idx_bsky_posts_embed_indexed']['create'], 'WHERE embed_json IS NOT NULL')
        && str_contains($specs['idx_bsky_posts_embed_indexed']['create'], 'indexed_at DESC NULLS LAST'),
    'embed index is partial on embed_json and ordered like the thin scan'
);
$check(vaak_migrate_notification_index_plan(false, null) === 'missing-table', 'missing bsky_posts does not pretend the index exists');
$files = array_map('basename', vaak_migrate_deploy_sql_files($api));
$check(!in_array('20261014_bsky_posts_home_indexes_postgres.sql', $files, true), 'concurrent index file stays out of the additive loop');

final class HomeBskyIndexTxPdo extends PDO
{
    public function inTransaction(): bool
    {
        return true;
    }
}
$blocked = false;
try {
    vaak_migrate_home_bsky_indexes(new HomeBskyIndexTxPdo('sqlite::memory:'));
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'must not run inside a transaction');
}
$check($blocked, 'concurrent index build refuses an open transaction');
$check(!str_contains($indexSql, 'password') && !str_contains($indexSql, 'postgres://'), 'index file has no credentials');

$check(
    str_contains($hydrate, 'const BSKY_POST_RKEY_SQL: &str = "split_part(bsky_uri, \'/app.bsky.feed.post/\', 2)"'),
    'Home rkey expression matches the index'
);
$check(str_contains($hydrate, 'WHERE {BSKY_POST_RKEY_SQL} = ANY($1)'), 'Home rkey lookup is one = ANY batch');
$check(str_contains($hydrate, 'WHERE {BSKY_POST_RKEY_SQL} = ANY($2)'), 'Home link facets use the same rkey expression');
$check(str_contains($hydrate, 'UNION ALL'), 'link facets do not OR the primary key with the expression');
$check(str_contains($bsky, "split_part(bsky_uri, '/app.bsky.feed.post/', 2) = ?"), 'PHP permalink lookup uses the rkey expression');
$check(str_contains($admin, "split_part(bsky_uri, '/app.bsky.feed.post/', 2) = ?"), 'PHP thread target lookup uses the rkey expression');
$check(str_contains($quote, "split_part(bsky_uri, '/app.bsky.feed.post/', 2) = \$1"), 'Rust quote warm uses the rkey expression');
$check(
    str_contains($facts, 'FROM bsky_posts r WHERE bsky_uri=ANY($3::text[])'),
    'search final facts still reads bsky posts by primary key'
);
foreach (['thin-media dry-run' => $thin, 'thin-media warm' => $thinWarm, 'jetstream thin scan' => $jetstream] as $label => $src) {
    $check(str_contains($src, 'embed_json IS NOT NULL'), $label . ' keeps the partial-index predicate');
    $check(str_contains($src, 'ORDER BY indexed_at DESC NULLS LAST'), $label . ' orders like the partial index');
}

if ($failures !== []) {
    fwrite(STDERR, "home-bsky-index-smoke failed\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "home-bsky-index-smoke ok checks={$checks}\n";
