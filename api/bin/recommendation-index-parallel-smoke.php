#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Recommendation indexes against the PHP database-smoke Postgres.
 *
 * Forces parallel maintenance workers, builds idx_reco_* through
 * vaak_migrate_recommendation_indexes(), and rebuilds an index left
 * INVALID by CREATE INDEX CONCURRENTLY. The old EXCEPTION helpers are
 * installed first so a worker that never starts cannot hide that bug.
 *
 * Requires AP_DB_DSN (a pgsql DSN). Creates and drops schema
 * reco_parallel_smoke in that database.
 */

require_once dirname(__DIR__, 2) . '/deploy/remote/migrate.php';

$dsn = getenv('AP_DB_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'pgsql:')) {
    fwrite(STDERR, "recommendation-index-parallel: AP_DB_DSN must be a pgsql DSN\n");
    exit(2);
}

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$db = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$api = dirname(__DIR__);
$schema = 'reco_parallel_smoke';

$db->exec('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
$db->exec('CREATE SCHEMA ' . $schema);
$db->exec('SET search_path TO ' . $schema);

$version = trim((string) $db->query('SHOW server_version_num')->fetchColumn());
$check(ctype_digit($version) && (int) $version >= 160000, 'smoke Postgres is 16 or newer');

$db->exec(<<<'SQL'
CREATE TABLE masto_favourites (owner_user_id bigint, object_id text);
CREATE TABLE masto_bookmarks (owner_user_id bigint, object_id text);
CREATE TABLE masto_reblogs (owner_user_id bigint, object_id text);
CREATE TABLE outbox_notes (id text, in_reply_to text, raw_create_json text);
CREATE TABLE quote_authorizations (requester_actor text, quoted_note_id text);
CREATE TABLE ap_user_signals (
  owner_user_id bigint,
  target_key text,
  weight int,
  signal_type text,
  metadata_json text
);
INSERT INTO masto_favourites (owner_user_id, object_id) VALUES (1, 'https://ex.test/fav/1/');
INSERT INTO masto_bookmarks (owner_user_id, object_id) VALUES (1, 'https://ex.test/bm/1/');
INSERT INTO masto_reblogs (owner_user_id, object_id) VALUES (1, 'https://ex.test/rb/1/');
INSERT INTO quote_authorizations (requester_actor, quoted_note_id)
VALUES ('https://ex.test/users/a/', 'https://ex.test/notes/5/');
INSERT INTO outbox_notes (id, in_reply_to, raw_create_json)
SELECT 'n' || g,
       'https://ex.test/reply/' || g || '/',
       CASE g % 5
         WHEN 0 THEN '{"object":{"quote":"https://ex.test/notes/' || g || '/"}}'
         WHEN 1 THEN '{"object":{"quoteUrl":"https://ex.test/url/' || g || '"}}'
         WHEN 2 THEN '{not-json quote ' || g
         WHEN 3 THEN '{"content":"no quote field ' || g || '"}'
         ELSE '{"object":{"_misskey_quote":"https://ex.test/mk/' || g || '/"}}'
       END
FROM generate_series(1, 4000) AS g;
INSERT INTO ap_user_signals (owner_user_id, target_key, weight, signal_type, metadata_json)
SELECT g,
       'https://ex.test/t/' || g || '/',
       1,
       'like',
       CASE g % 4
         WHEN 0 THEN '{"object_id":"https://ex.test/o/' || g || '/","uri":"https://ex.test/u/' || g || '/"}'
         WHEN 1 THEN '{bad quote json ' || g
         WHEN 2 THEN ''
         ELSE NULL
       END
FROM generate_series(1, 4000) AS g;
SQL);

foreach ([
    'max_parallel_maintenance_workers = 4',
    'min_parallel_table_scan_size = 0',
    'parallel_setup_cost = 0',
    'parallel_tuple_cost = 0',
    "maintenance_work_mem = '256MB'",
] as $setting) {
    $db->exec('SET ' . $setting);
}
$db->exec('ALTER TABLE outbox_notes SET (parallel_workers = 4)');
$db->exec('ALTER TABLE ap_user_signals SET (parallel_workers = 4)');

$legacyQuote = <<<'SQL'
CREATE OR REPLACE FUNCTION vaak_reco_quote_key(raw text) RETURNS text
LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE
SET search_path = pg_catalog
AS $fn$
DECLARE
  doc jsonb;
BEGIN
  IF raw IS NULL OR strpos(raw, 'quote') <= 0 THEN
    RETURN '';
  END IF;
  BEGIN
    doc := raw::jsonb;
  EXCEPTION WHEN others THEN
    RETURN '';
  END;
  RETURN rtrim(COALESCE(doc #>> '{object,quote}', doc #>> '{object,quoteUrl}', doc #>> '{object,_misskey_quote}', ''), '/');
END;
$fn$
SQL;
$legacySignal = <<<'SQL'
CREATE OR REPLACE FUNCTION vaak_reco_signal_key(raw text, field text) RETURNS text
LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE
SET search_path = pg_catalog
AS $fn$
DECLARE
  doc jsonb;
  val text;
BEGIN
  IF raw IS NULL OR raw = '' OR field IS NULL OR field = '' THEN
    RETURN NULL;
  END IF;
  BEGIN
    doc := raw::jsonb;
  EXCEPTION WHEN others THEN
    RETURN NULL;
  END;
  val := doc ->> field;
  IF val IS NULL THEN
    RETURN NULL;
  END IF;
  RETURN rtrim(val, '/');
END;
$fn$
SQL;
$db->exec($legacyQuote);
$db->exec($legacySignal);
$db->exec(str_replace(
    ['FUNCTION vaak_reco_quote_key(raw text)', 'PARALLEL SAFE'],
    ['FUNCTION reco_quote_key_legacy(raw text)', 'PARALLEL UNSAFE'],
    $legacyQuote
));
$db->exec(str_replace(
    ['FUNCTION vaak_reco_signal_key(raw text, field text)', 'PARALLEL SAFE'],
    ['FUNCTION reco_signal_key_legacy(raw text, field text)', 'PARALLEL UNSAFE'],
    $legacySignal
));

$specs = vaak_migrate_recommendation_index_specs($api);
$expectParallelFailure = static function (PDO $db, string $sql): void {
    try {
        $db->exec($sql);
    } catch (PDOException $e) {
        $failed = (string) $e->getCode() === '25000'
            || str_contains($e->getMessage(), 'cannot start subtransactions during a parallel operation');
        if (!$failed) {
            throw $e;
        }
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return;
    }
    fwrite(STDERR, "recommendation-index-parallel: parallel maintenance workers did not call the helper\n");
    exit(1);
};

$expectParallelFailure($db, $specs['idx_reco_outbox_quote_key']['create']);
$expectParallelFailure($db, $specs['idx_reco_signals_owner_meta_object']['create']);
$expectParallelFailure($db, $specs['idx_reco_signals_owner_meta_uri']['create']);

$indexState = static function (PDO $db, string $name): ?array {
    $stmt = $db->prepare(
        'SELECT c.oid::text AS oid, i.indisvalid
         FROM pg_class c
         JOIN pg_index i ON i.indexrelid = c.oid
         JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = current_schema() AND c.relname = ?'
    );
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
};

foreach (['idx_reco_outbox_quote_key', 'idx_reco_signals_owner_meta_object', 'idx_reco_signals_owner_meta_uri'] as $invalidName) {
    $row = $indexState($db, $invalidName);
    $check(is_array($row) && vaak_migrate_pg_bool($row['indisvalid'] ?? null) === false, $invalidName . ' is left INVALID');
}

vaak_migrate_recommendation_indexes($db, $api);

$oids = [];
foreach (array_keys($specs) as $name) {
    $row = $indexState($db, $name);
    $valid = is_array($row) ? vaak_migrate_pg_bool($row['indisvalid'] ?? null) : null;
    $check($valid === true, $name . ' is valid after migrate');
    $oids[$name] = is_array($row) ? (string) $row['oid'] : '';
}

$procs = $db->query(
    "SELECT proname, provolatile, proparallel, pg_get_function_identity_arguments(oid) AS args, prosrc
     FROM pg_proc
     WHERE pronamespace = current_schema()::regnamespace
       AND proname IN ('vaak_reco_quote_key', 'vaak_reco_signal_key')
     ORDER BY proname"
)->fetchAll();
$byName = [];
foreach ($procs as $proc) {
    $byName[(string) $proc['proname']] = $proc;
}
$check(isset($byName['vaak_reco_quote_key'], $byName['vaak_reco_signal_key']), 'both helpers are installed in the smoke schema');
foreach (['vaak_reco_quote_key' => 'raw text', 'vaak_reco_signal_key' => 'raw text, field text'] as $fn => $args) {
    $proc = $byName[$fn] ?? null;
    $check(is_array($proc) && $proc['args'] === $args, $fn . ' keeps its signature');
    $check(is_array($proc) && $proc['provolatile'] === 'i' && $proc['proparallel'] === 's', $fn . ' stays immutable and parallel safe');
    $src = is_array($proc) ? (string) $proc['prosrc'] : '';
    $check(!str_contains($src, 'EXCEPTION') && str_contains($src, 'pg_input_is_valid'), $fn . ' rejects invalid JSON without a subtransaction');
}

$cases = [
    ['raw' => null, 'field' => 'object_id'],
    ['raw' => '', 'field' => 'uri'],
    ['raw' => 'quote', 'field' => 'object_id'],
    ['raw' => '{quote', 'field' => 'uri'],
    ['raw' => '{"object":{"quote":"https://ex.test/a/"}}', 'field' => 'object_id'],
    ['raw' => '{"object":{"quoteUrl":"https://ex.test/b/"}}', 'field' => 'uri'],
    ['raw' => '{"object":{"_misskey_quote":"https://ex.test/c/"}}', 'field' => 'object_id'],
    ['raw' => '{"object":{"quote":"https://ex.test/a/","quoteUrl":"https://ex.test/b/","_misskey_quote":"https://ex.test/c/"}}', 'field' => 'object_id'],
    ['raw' => '{"object":{"quote":null,"quoteUrl":"https://ex.test/b/"}}', 'field' => 'uri'],
    ['raw' => '{"object":{"quote":""}}', 'field' => 'object_id'],
    ['raw' => '{"object":{"quote":"https://ex.test/a///"}}', 'field' => 'object_id'],
    ['raw' => '  {"object": {"quote": "https://ex.test/a/"}} ', 'field' => 'uri'],
    ['raw' => '{"object":{"quote":123}}', 'field' => 'object_id'],
    ['raw' => '{"object":{"quote":{"id":"https://ex.test/a/"}}}', 'field' => 'uri'],
    ['raw' => '{"object":{"quote":"https://a/","quote":"https://b/"}}', 'field' => 'object_id'],
    ['raw' => '{"object":{"quote":"\\uD800"}}', 'field' => 'object_id'],
    ['raw' => '["quote"]', 'field' => 'uri'],
    ['raw' => '"quote"', 'field' => 'object_id'],
    ['raw' => 'null', 'field' => 'uri'],
    ['raw' => '{"content":"a quote"}', 'field' => 'object_id'],
    ['raw' => '{"object_id":"https://ex.test/o/","uri":"https://ex.test/u/"}', 'field' => 'object_id'],
    ['raw' => '{"object_id":"https://ex.test/o/","uri":"https://ex.test/u/"}', 'field' => 'uri'],
    ['raw' => '{"object_id":"https://ex.test/o///"}', 'field' => 'object_id'],
    ['raw' => '{"uri":null}', 'field' => 'uri'],
    ['raw' => '{"object_id":""}', 'field' => 'object_id'],
    ['raw' => '{"object_id":1}', 'field' => 'uri'],
    ['raw' => '{"object_id":{"nested":true}}', 'field' => 'object_id'],
    ['raw' => '{"uri":"https://ex.test/u"}', 'field' => 'missing'],
    ['raw' => '{"object_id":"x"}', 'field' => ''],
    ['raw' => '{"object_id":"x"}', 'field' => null],
    ['raw' => null, 'field' => null],
    ['raw' => '{"object":{"quote":"///"}}', 'field' => 'object_id'],
    ['raw' => '{"object_id":"/"}', 'field' => 'object_id'],
];
for ($i = 0; $i < 40; $i++) {
    $cases[] = ['raw' => '{"object":{"quote":"https://ex.test/' . $i . '/"}}', 'field' => ($i % 2 === 0 ? 'object_id' : 'uri')];
    $cases[] = ['raw' => '{quote broken ' . str_repeat('x', $i), 'field' => 'object_id'];
    $cases[] = ['raw' => str_repeat('a', $i) . 'quote' . str_repeat('}', $i % 7), 'field' => 'uri'];
}
$values = [];
foreach ($cases as $case) {
    $raw = $case['raw'] === null ? 'NULL' : $db->quote((string) $case['raw']);
    $field = $case['field'] === null ? 'NULL' : $db->quote((string) $case['field']);
    $values[] = '(' . $raw . ', ' . $field . ')';
}
$mismatchSql = 'SELECT s.raw, s.field,'
    . ' vaak_reco_quote_key(s.raw) AS new_quote, reco_quote_key_legacy(s.raw) AS old_quote,'
    . ' vaak_reco_signal_key(s.raw, s.field) AS new_signal, reco_signal_key_legacy(s.raw, s.field) AS old_signal'
    . ' FROM (VALUES ' . implode(',', $values) . ') AS s(raw, field)'
    . ' WHERE vaak_reco_quote_key(s.raw) IS DISTINCT FROM reco_quote_key_legacy(s.raw)'
    . ' OR vaak_reco_signal_key(s.raw, s.field) IS DISTINCT FROM reco_signal_key_legacy(s.raw, s.field)';
$mismatches = $db->query($mismatchSql)->fetchAll();
$check($mismatches === [], 'helper results match the previous functions');
if ($mismatches !== []) {
    foreach (array_slice($mismatches, 0, 8) as $mismatch) {
        $failures[] = 'mismatch raw=' . json_encode($mismatch['raw']) . ' field=' . json_encode($mismatch['field']);
    }
}

$db->exec('ANALYZE outbox_notes');
$db->exec('ANALYZE ap_user_signals');
$db->exec('SET enable_seqscan = off');
$planText = static function (PDO $db, string $sql): string {
    $lines = $db->query('EXPLAIN ' . $sql)->fetchAll(PDO::FETCH_COLUMN);
    return implode("\n", array_map(static fn ($line): string => (string) $line, $lines));
};
$quotePlan = $planText(
    $db,
    "SELECT id FROM outbox_notes"
    . " WHERE strpos(raw_create_json,'quote')>0"
    . " AND vaak_reco_quote_key(raw_create_json)<>''"
    . " AND vaak_reco_quote_key(raw_create_json)='https://ex.test/notes/5'"
);
$signalPlan = $planText(
    $db,
    "SELECT owner_user_id FROM ap_user_signals"
    . " WHERE weight>0 AND signal_type IN ('like','favourite','boost','reblog','reply','quote','bookmark')"
    . " AND vaak_reco_signal_key(metadata_json,'object_id')='https://ex.test/o/4'"
);
$db->exec('SET enable_seqscan = on');
$check(str_contains($quotePlan, 'idx_reco_outbox_quote_key'), 'quote lookup uses the quote index');
$check(str_contains($signalPlan, 'idx_reco_signals_owner_meta_object'), 'signal lookup uses the metadata index');
$check((string) $db->query('SHOW max_parallel_maintenance_workers')->fetchColumn() === '4', 'migrate left parallel maintenance workers forced on');

vaak_migrate_recommendation_indexes($db, $api);
foreach (array_keys($specs) as $name) {
    $row = $indexState($db, $name);
    $check(is_array($row) && (string) $row['oid'] === $oids[$name], $name . ' stays in place when it is already valid');
    if (is_array($row)) {
        $oids[$name] = (string) $row['oid'];
    }
}

$updated = $db->exec("UPDATE pg_index SET indisvalid = false WHERE indexrelid = 'idx_reco_outbox_quote_key'::regclass");
$marked = $indexState($db, 'idx_reco_outbox_quote_key');
$check($updated === 1 && is_array($marked) && vaak_migrate_pg_bool($marked['indisvalid'] ?? null) === false, 'quote index can be marked INVALID for the rebuild');
vaak_migrate_recommendation_indexes($db, $api);
foreach (array_keys($specs) as $name) {
    $row = $indexState($db, $name);
    $valid = is_array($row) ? vaak_migrate_pg_bool($row['indisvalid'] ?? null) : null;
    $check($valid === true, $name . ' is valid after the invalid-index rebuild');
    if ($name === 'idx_reco_outbox_quote_key') {
        $check(is_array($row) && (string) $row['oid'] !== $oids[$name], 'invalid quote index is dropped and built again');
    } else {
        $check(is_array($row) && (string) $row['oid'] === $oids[$name], $name . ' is not rebuilt with the invalid quote index');
    }
}

$db->exec('SET search_path TO public');
$db->exec('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');

if ($failures !== []) {
    fwrite(STDERR, "recommendation-index-parallel: FAIL\n" . implode("\n", $failures) . "\n");
    exit(1);
}
echo "recommendation-index-parallel: PASS ($checks checks)\n";
