<?php
/**
 * Build a throwaway PostgreSQL schema for CI from the empty migrated SQLite schema.
 *
 * Production PostgreSQL was staged from the SQLite store. ap_db_migrate_postgres()
 * then refuses to start until those legacy tables and the search tsvector exist.
 * This script replays that staging step on an empty database: run ap_db_migrate()
 * against a temporary SQLite file, copy each table's columns, primary key, and
 * unique keys into PostgreSQL, and add body_tsv. deploy/remote/migrate.php runs
 * afterwards and applies everything PostgreSQL-specific, as it does on deploy.
 *
 * CI only. Refuses a DSN that does not point at a loopback host.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$dsn = (string) getenv('AP_DB_DSN');
$user = getenv('AP_DB_USER') ?: null;
$password = getenv('AP_DB_PASSWORD') ?: null;
if (!str_starts_with($dsn, 'pgsql:') || !preg_match('/host=(127\.0\.0\.1|localhost|\/)/', $dsn)) {
    fwrite(STDERR, "pg-ci-schema: AP_DB_DSN must be a loopback pgsql: DSN\n");
    exit(2);
}
$api = dirname(__DIR__, 2) . '/api';

// ap-db.php reads AP_DB_DSN only inside ap_db(); the SQLite handle below is
// passed to ap_db_migrate() directly.
require_once $api . '/ap-db.php';
$file = tempnam(sys_get_temp_dir(), 'vaak-ci-schema-');
$src = new PDO('sqlite:' . $file, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
ap_db_migrate($src);

$db = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$present = $db->query("SELECT 1 FROM pg_tables WHERE schemaname = current_schema() AND tablename = 'ap_users'")->fetchColumn();
if ($present !== false) {
    fwrite(STDERR, "pg-ci-schema: ap_users already exists; use an empty database\n");
    exit(2);
}

$tables = 0;
foreach ($src->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll() as $table) {
    $name = $table['name'];
    $cols = $src->query('PRAGMA table_info("' . $name . '")')->fetchAll();
    $pk = array_values(array_filter($cols, static fn(array $c): bool => (int) $c['pk'] > 0));
    usort($pk, static fn(array $a, array $b): int => (int) $a['pk'] <=> (int) $b['pk']);
    $parts = [];
    foreach ($cols as $c) {
        $type = strtoupper((string) $c['type']);
        $type = str_contains($type, 'INT') ? 'BIGINT'
            : (str_contains($type, 'REAL') || str_contains($type, 'FLOAT') || str_contains($type, 'DOUBLE') ? 'DOUBLE PRECISION'
            : (str_contains($type, 'BLOB') ? 'BYTEA' : 'TEXT'));
        if (count($pk) === 1 && (int) $c['pk'] > 0 && $type === 'BIGINT') {
            $type = 'BIGSERIAL';
        }
        $def = '"' . $c['name'] . '" ' . $type;
        if ((int) $c['notnull'] === 1) {
            $def .= ' NOT NULL';
        }
        $default = $c['dflt_value'];
        if ($default !== null && $type !== 'BIGSERIAL') {
            // SQLite spellings that PostgreSQL does not accept.
            $default = preg_replace("/\\(?datetime\\('now'\\)\\)?|\\(?strftime\\([^)]*\\)\\)?/i", 'CURRENT_TIMESTAMP', (string) $default);
            $def .= ' DEFAULT ' . $default;
        }
        $parts[] = $def;
    }
    if ($pk !== []) {
        $parts[] = 'PRIMARY KEY (' . implode(', ', array_map(static fn(array $c): string => '"' . $c['name'] . '"', $pk)) . ')';
    }
    $db->exec('CREATE TABLE "' . $name . '" (' . implode(', ', $parts) . ')');
    foreach ($src->query('PRAGMA index_list("' . $name . '")')->fetchAll() as $idx) {
        if ((int) $idx['unique'] !== 1 || $idx['origin'] === 'pk') {
            continue;
        }
        $ics = $src->query('PRAGMA index_info("' . $idx['name'] . '")')->fetchAll();
        if (in_array(null, array_column($ics, 'name'), true)) {
            continue;
        }
        $db->exec('CREATE UNIQUE INDEX ON "' . $name . '" (' . implode(', ', array_map(static fn(array $c): string => '"' . $c['name'] . '"', $ics)) . ')');
    }
    $tables++;
}
// These modules create their own tables lazily, outside ap_db_migrate(). Same
// extraction as api/bin/posting-threading-sapi-pg-provision.php.
foreach (['ap-instance-docs.php' => ['ap_instance_docs', 'ap_instance_rules'], 'ap-site-publish.php' => ['site_syndications'], 'ap-webpush.php' => ['push_subscriptions']] as $module => $moduleTables) {
    $source = (string) file_get_contents($api . '/' . $module);
    foreach ($moduleTables as $table) {
        if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . ' \(.*?\);/s', $source, $m)) {
            fwrite(STDERR, "pg-ci-schema: no CREATE TABLE for {$table} in {$module}\n");
            exit(1);
        }
        $ddl = str_replace(['INTEGER PRIMARY KEY AUTOINCREMENT', '{$idColumn}'], 'BIGSERIAL PRIMARY KEY', $m[0]);
        $db->exec($ddl);
        $tables++;
    }
}
$db->exec('ALTER TABLE ap_search_docs ADD COLUMN IF NOT EXISTS body_tsv TSVECTOR');
$db->exec('CREATE INDEX IF NOT EXISTS idx_ap_search_docs_body_tsv ON ap_search_docs USING GIN (body_tsv)');
unlink($file);
echo "pg-ci-schema: {$tables} tables staged from the SQLite schema\n";

// The running app creates the rest on first use (Bluesky caches, polls, asks,
// RSS, Phyrian, bookmark folders). Production has long had them, and
// migrate.php indexes some of them, so create them now through the same
// functions on the PostgreSQL handle that ap_db() opens.
$db = null;
$pg = ap_db();
define('AP_SITE_PUBLISH_LIB_ONLY', true);
define('AP_INBOX_LIB_ONLY', true);
foreach (['ap-asks.php', 'ap-bookmark-folders.php', 'ap-bsky.php', 'ap-instance-docs.php', 'ap-phyrian-bridge.php', 'ap-phyrian.php', 'ap-rss.php', 'ap-search-fts.php', 'ap-site-publish.php', 'ap-webpush.php'] as $module) {
    require_once $api . '/' . $module;
}
$lazy = [];
foreach (get_defined_functions()['user'] as $function) {
    if (preg_match('/^ap_[a-z0-9_]*_migrate$/', $function) && !in_array($function, ['ap_db_migrate', 'ap_search_fts_migrate'], true)) {
        $lazy[] = $function;
    }
}
$lazy[] = 'vaak_bookmark_folders_ensure_schema';
sort($lazy);
// bsky_posts first: the observations table reads from it, and each migrate
// function remembers a failed attempt for the rest of the process.
usort($lazy, static fn(string $a, string $b): int => ($b === 'ap_bsky_posts_migrate') <=> ($a === 'ap_bsky_posts_migrate'));
foreach ($lazy as $function) {
    $params = (new ReflectionFunction($function))->getNumberOfParameters();
    $params > 0 ? $function($pg) : $function();
}
echo 'pg-ci-schema: ran ' . implode(', ', $lazy) . "\n";
