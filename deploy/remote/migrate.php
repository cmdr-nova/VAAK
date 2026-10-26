<?php
/**
 * Apply pending VAAK schema changes before the release symlink swaps.
 *
 * CLI only. Loads a DDL-capable DSN from /etc/mkultra/vaak-migrate.env
 * (override with VAAK_MIGRATE_ENV). Does not read or print application secrets.
 *
 * api/ap-db.php applies missing tables and columns when the role can CREATE,
 * and logs a provisioning error when it cannot. This command fails if that
 * log is non-empty, if the role lacks CREATE, or if ap_users is missing.
 * A few historical catch blocks in ap_db_migrate_postgres() do not log;
 * those still need a role that can actually run the DDL.
 *
 * SQL files named by vaak_migrate_deploy_sql_files() are applied here, not
 * from ap_db_migrate_postgres(). That keeps a request with the feature
 * switch off from querying for those tables. Each file may only create new
 * tables (CREATE TABLE IF NOT EXISTS), index those new tables, and grant
 * on the objects it creates. Re-running a file is a no-op once they exist.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "migrate: CLI only\n");
    exit(1);
}

function vaak_migrate_fail(string $message): never
{
    fwrite(STDERR, "migrate: {$message}\n");
    exit(1);
}

/** @return array<string, string> */
function vaak_migrate_load_env(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        vaak_migrate_fail("migration env is not readable: {$path}");
    }
    $vars = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        vaak_migrate_fail("could not read migration env");
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        if (!str_contains($line, '=')) {
            vaak_migrate_fail('migration env contains a line without =');
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            vaak_migrate_fail('migration env contains an invalid name');
        }
        $vars[$key] = $value;
    }
    return $vars;
}

function vaak_migrate_redact(string $message, string $password): string
{
    if ($password !== '') {
        $message = str_replace($password, '[redacted]', $message);
    }
    return $message;
}

function vaak_migrate_is_true(mixed $value): bool
{
    return in_array($value, [true, 1, '1', 't', 'true'], true);
}

function vaak_migrate_is_entry_script(): bool
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if (!is_string($script) || $script === '') {
        return false;
    }
    $scriptReal = realpath($script);
    $selfReal = realpath(__FILE__);
    return $scriptReal !== false && $selfReal !== false && $scriptReal === $selfReal;
}

/**
 * Additive SQL files applied by this command and not by ap_db().
 *
 * Other 2026-10-14 files are already created from ap_db_migrate_postgres(),
 * which migrate.php reaches by calling ap_db(). The clans file is not.
 * Keep that list explicit: api/migrations also contains ALTER and
 * CREATE INDEX CONCURRENTLY scripts that must not run from this loop.
 *
 * @return list<string> absolute paths, in apply order
 */
function vaak_migrate_deploy_sql_files(string $apiRoot): array
{
    $root = realpath($apiRoot);
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('deploy migration api root is not a directory');
    }
    $names = [
        '20261014_phyrian_clans_postgres.sql',
        '20261014_event_quote_targets_postgres.sql',
        '20261016_wafrn_bsky_actors_postgres.sql',
        '20261019_phyrian_grid_lineage_postgres.sql',
        '20261021_bsky_follow_carry_postgres.sql',
        '20261026_ml_shadow_scorecard_postgres.sql',
    ];
    $files = [];
    $migrations = $root . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR;
    foreach ($names as $name) {
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
            throw new RuntimeException('deploy migration name is not a file name');
        }
        $real = realpath($migrations . $name);
        if ($real === false || !is_file($real) || !str_starts_with($real, $migrations)) {
            throw new RuntimeException('deploy migration is not in the release: ' . $name);
        }
        $files[] = $real;
    }
    return $files;
}

/**
 * Split a migration into statements. Line comments are dropped. Semicolons
 * inside single or double quotes stay part of the statement.
 *
 * @return list<string>
 */
function vaak_migrate_sql_statements(string $sql): array
{
    $length = strlen($sql);
    $statements = [];
    $current = '';
    $inSingle = false;
    $inDouble = false;
    $inLineComment = false;
    for ($i = 0; $i < $length; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';
        if ($inLineComment) {
            if ($ch === "\n") {
                $inLineComment = false;
            }
            continue;
        }
        if (!$inSingle && !$inDouble && $ch === '-' && $next === '-') {
            $inLineComment = true;
            $i++;
            continue;
        }
        if ($ch === "'" && !$inDouble) {
            if ($inSingle && $next === "'") {
                $current .= "''";
                $i++;
                continue;
            }
            $inSingle = !$inSingle;
            $current .= $ch;
            continue;
        }
        if ($ch === '"' && !$inSingle) {
            $inDouble = !$inDouble;
            $current .= $ch;
            continue;
        }
        if ($ch === ';' && !$inSingle && !$inDouble) {
            $statement = trim($current);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $current = '';
            continue;
        }
        $current .= $ch;
    }
    $tail = trim($current);
    if ($tail !== '') {
        $statements[] = $tail;
    }
    return $statements;
}

function vaak_migrate_flat_sql(string $statement): string
{
    $flat = preg_replace('/\s+/', ' ', trim($statement));
    return is_string($flat) ? $flat : trim($statement);
}

/**
 * Accept only CREATE TABLE IF NOT EXISTS, indexes on those new tables, and
 * grants on the objects this file creates. Anything else, including ALTER
 * on a table that already exists, is refused before the first statement runs.
 *
 * @param list<string> $statements
 * @return array{statements: list<string>, tables: list<string>}
 */
function vaak_migrate_additive_plan(array $statements, string $name): array
{
    $created = [];
    $planned = [];
    foreach ($statements as $statement) {
        if (!is_string($statement) || trim($statement) === '') {
            throw new RuntimeException($name . ' has an empty statement');
        }
        $flat = vaak_migrate_flat_sql($statement);
        if (preg_match('/^GRANT\s+.+\s+ON\s+(?:SEQUENCE\s+)?("?[A-Za-z_][A-Za-z0-9_]*"?)\s+TO\s+/', $flat, $grant)) {
            $object = strtolower(trim($grant[1], '"'));
            $owner = str_ends_with($object, '_id_seq') ? substr($object, 0, -strlen('_id_seq')) : $object;
            if (!isset($created[$owner])) {
                throw new RuntimeException($name . ' grants on an object it does not create');
            }
            $planned[] = $statement;
            continue;
        }
        if (preg_match('/\b(ALTER|DROP|TRUNCATE|DELETE|UPDATE|INSERT|REPLACE|REFERENCES)\b/i', $flat)) {
            throw new RuntimeException($name . ' would change an existing table');
        }
        if (preg_match('/^CREATE TABLE IF NOT EXISTS\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/i', $flat, $table)) {
            $created[strtolower($table[1])] = true;
            $planned[] = $statement;
            continue;
        }
        if (preg_match('/^CREATE INDEX IF NOT EXISTS\s+[A-Za-z_][A-Za-z0-9_]*\s+ON\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/i', $flat, $index)) {
            $tableName = strtolower($index[1]);
            if (!isset($created[$tableName])) {
                throw new RuntimeException($name . ' indexes a table it does not create');
            }
            $planned[] = $statement;
            continue;
        }
        throw new RuntimeException($name . ' is not an additive create-if-not-exists migration');
    }
    if ($created === []) {
        throw new RuntimeException($name . ' creates no tables');
    }
    return ['statements' => $planned, 'tables' => array_keys($created)];
}

/**
 * Notification read indexes on tables that already exist.
 *
 * Not part of vaak_migrate_deploy_sql_files(): that loop refuses indexes on
 * existing tables and runs ordinary CREATE INDEX, which blocks writes for
 * the whole build. CREATE INDEX CONCURRENTLY takes only a brief
 * ShareUpdateExclusiveLock, so reads and writes on events continue. It
 * cannot run inside a transaction. A valid index is left untouched. An
 * invalid index left by a failed concurrent build is dropped and created
 * again. Both steps are no-ops once the index is valid.
 *
 * @return array<string, array{table: string, create: string, drop: string}>
 */
function vaak_migrate_notification_index_specs(): array
{
    return [
        'idx_events_object_key_id' => [
            'table' => 'events',
            'create' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_object_key_id ON events (rtrim(object_id, '/'), id DESC)",
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS idx_events_object_key_id',
        ],
        'idx_mentions_owner_live_id' => [
            'table' => 'mentions',
            'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_mentions_owner_live_id ON mentions (owner_user_id, id DESC) WHERE deleted_at IS NULL',
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS idx_mentions_owner_live_id',
        ],
    ];
}

/** @return 'skip'|'create'|'rebuild'|'missing-table' */
function vaak_migrate_notification_index_plan(bool $tableExists, ?bool $valid): string
{
    if (!$tableExists) {
        return 'missing-table';
    }
    if ($valid === true) {
        return 'skip';
    }
    if ($valid === false) {
        return 'rebuild';
    }
    return 'create';
}

/** @return list<string> */
function vaak_migrate_notification_index_statements(string $name, string $action): array
{
    $specs = vaak_migrate_notification_index_specs();
    if (!isset($specs[$name])) {
        throw new RuntimeException('unknown notification read index');
    }
    if ($action === 'skip') {
        return [];
    }
    if ($action === 'create') {
        return [$specs[$name]['create']];
    }
    if ($action === 'rebuild') {
        return [$specs[$name]['drop'], $specs[$name]['create']];
    }
    throw new RuntimeException('notification read index was not created');
}

function vaak_migrate_pg_bool(mixed $value): ?bool
{
    if ($value === null) {
        return null;
    }
    if (vaak_migrate_is_true($value)) {
        return true;
    }
    if (in_array($value, [false, 0, '0', 'f', 'false'], true)) {
        return false;
    }
    return null;
}

function vaak_migrate_notification_read_indexes(PDO $db): void
{
    if ($db->inTransaction()) {
        throw new RuntimeException('notification read indexes must not run inside a transaction');
    }
    foreach (vaak_migrate_notification_index_specs() as $name => $spec) {
        $table = $db->prepare(
            'SELECT 1 FROM pg_tables WHERE schemaname = current_schema() AND tablename = ?'
        );
        $table->execute([$spec['table']]);
        $tableExists = $table->fetchColumn() !== false;
        $index = $db->prepare(
            'SELECT i.indisvalid FROM pg_class c
             JOIN pg_index i ON i.indexrelid = c.oid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema() AND c.relname = ?'
        );
        $index->execute([$name]);
        $row = $index->fetch();
        $valid = is_array($row) ? vaak_migrate_pg_bool($row['indisvalid'] ?? null) : null;
        if (is_array($row) && $valid === null) {
            throw new RuntimeException('notification read index validity is unknown');
        }
        $action = vaak_migrate_notification_index_plan($tableExists, $valid);
        if ($action === 'missing-table') {
            throw new RuntimeException($spec['table'] . ' is missing; notification read index was not created');
        }
        foreach (vaak_migrate_notification_index_statements($name, $action) as $statement) {
            $db->exec($statement);
        }
    }
}

/**
 * Home Bluesky indexes on bsky_posts, which already exists in production.
 *
 * Same rules as the notification read indexes: not part of
 * vaak_migrate_deploy_sql_files(), CREATE INDEX CONCURRENTLY outside a
 * transaction, a valid index is left untouched, and an invalid leftover
 * from a failed concurrent build is dropped and created again.
 *
 * idx_bsky_posts_post_rkey matches
 * split_part(bsky_uri, '/app.bsky.feed.post/', 2) used by Home rkey lookup.
 * idx_bsky_posts_embed_indexed is a partial index for the thin-media scan
 * (WHERE embed_json IS NOT NULL, ordered by indexed_at).
 *
 * @return array<string, array{table: string, create: string, drop: string}>
 */
function vaak_migrate_home_bsky_index_specs(): array
{
    return [
        'idx_bsky_posts_post_rkey' => [
            'table' => 'bsky_posts',
            'create' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bsky_posts_post_rkey ON bsky_posts (split_part(bsky_uri, '/app.bsky.feed.post/', 2))",
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS idx_bsky_posts_post_rkey',
        ],
        'idx_bsky_posts_embed_indexed' => [
            'table' => 'bsky_posts',
            'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bsky_posts_embed_indexed ON bsky_posts (indexed_at DESC NULLS LAST) WHERE embed_json IS NOT NULL',
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS idx_bsky_posts_embed_indexed',
        ],
    ];
}

/** @return list<string> */
function vaak_migrate_home_bsky_index_statements(string $name, string $action): array
{
    $specs = vaak_migrate_home_bsky_index_specs();
    if (!isset($specs[$name])) {
        throw new RuntimeException('unknown home bsky index');
    }
    if ($action === 'skip') {
        return [];
    }
    if ($action === 'create') {
        return [$specs[$name]['create']];
    }
    if ($action === 'rebuild') {
        return [$specs[$name]['drop'], $specs[$name]['create']];
    }
    throw new RuntimeException('home bsky index was not created');
}

function vaak_migrate_home_bsky_indexes(PDO $db): void
{
    if ($db->inTransaction()) {
        throw new RuntimeException('home bsky indexes must not run inside a transaction');
    }
    foreach (vaak_migrate_home_bsky_index_specs() as $name => $spec) {
        $table = $db->prepare(
            'SELECT 1 FROM pg_tables WHERE schemaname = current_schema() AND tablename = ?'
        );
        $table->execute([$spec['table']]);
        $tableExists = $table->fetchColumn() !== false;
        $index = $db->prepare(
            'SELECT i.indisvalid FROM pg_class c
             JOIN pg_index i ON i.indexrelid = c.oid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema() AND c.relname = ?'
        );
        $index->execute([$name]);
        $row = $index->fetch();
        $valid = is_array($row) ? vaak_migrate_pg_bool($row['indisvalid'] ?? null) : null;
        if (is_array($row) && $valid === null) {
            throw new RuntimeException('home bsky index validity is unknown');
        }
        $action = vaak_migrate_notification_index_plan($tableExists, $valid);
        if ($action === 'missing-table') {
            throw new RuntimeException($spec['table'] . ' is missing; home bsky index was not created');
        }
        foreach (vaak_migrate_home_bsky_index_statements($name, $action) as $statement) {
            $db->exec($statement);
        }
    }
}

/**
 * Indexes that make the hot Bluesky reads and the observation sync index
 * range scans. Existing tables, so they are not part of
 * vaak_migrate_deploy_sql_files(). CREATE INDEX CONCURRENTLY runs outside a
 * transaction. A valid index is left untouched. An invalid leftover is
 * dropped and created again.
 *
 * idx_bsky_posts_lower_author_did matches
 * `WHERE lower(author_did) IN (...)` on bsky_posts.
 * idx_bsky_posts_seen matches the observation sync's seen_at window.
 * idx_bsky_post_observations_last_seen matches the fresh-window predicate.
 * idx_remote_actors_lower_host_username matches the actor alias lookup
 * `lower(COALESCE(host,'')) = ? AND lower(COALESCE(username,'')) = ?`.
 *
 * @return array<string, array{table: string, create: string, drop: string}>
 */
function vaak_migrate_cpu_index_specs(): array
{
    return [
        'idx_bsky_posts_lower_author_did' => [
            'table' => 'bsky_posts',
            'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bsky_posts_lower_author_did ON bsky_posts (lower(author_did))',
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS idx_bsky_posts_lower_author_did',
        ],
        'idx_bsky_posts_seen' => [
            'table' => 'bsky_posts',
            'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bsky_posts_seen ON bsky_posts (seen_at)',
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS idx_bsky_posts_seen',
        ],
        'idx_bsky_post_observations_last_seen' => [
            'table' => 'bsky_post_observations',
            'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bsky_post_observations_last_seen ON bsky_post_observations (last_seen_at)',
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS idx_bsky_post_observations_last_seen',
        ],
        'idx_remote_actors_lower_host_username' => [
            'table' => 'remote_actors',
            'create' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_remote_actors_lower_host_username ON remote_actors (lower(COALESCE(host, '')), lower(COALESCE(username, '')))",
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS idx_remote_actors_lower_host_username',
        ],
        'idx_events_local_timeline_created' => [
            'table' => 'events',
            'create' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_local_timeline_created ON events (created_at DESC, id DESC) WHERE action_taken IN ('log', 'local_observe') AND actor_id LIKE 'https://mkultra.monster/users/%'",
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS idx_events_local_timeline_created',
        ],
    ];
}

/** @return list<string> */
function vaak_migrate_cpu_index_statements(string $name, string $action): array
{
    $specs = vaak_migrate_cpu_index_specs();
    if (!isset($specs[$name])) {
        throw new RuntimeException('unknown cpu index');
    }
    if ($action === 'skip') {
        return [];
    }
    if ($action === 'create') {
        return [$specs[$name]['create']];
    }
    if ($action === 'rebuild') {
        return [$specs[$name]['drop'], $specs[$name]['create']];
    }
    throw new RuntimeException('cpu index was not created');
}

function vaak_migrate_observation_unique_sql(): string
{
    return 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS uniq_bsky_post_observations_uri_owner ON bsky_post_observations (bsky_uri, owner_user_id)';
}

/**
 * True when (bsky_uri, owner_user_id) already has a valid unique index or
 * primary key. A second unique index would tax every observation write.
 */
function vaak_migrate_observation_unique_exists(PDO $db): bool
{
    $st = $db->query(
        "SELECT 1
         FROM pg_index i
         JOIN pg_class t ON t.oid = i.indrelid
         JOIN pg_namespace n ON n.oid = t.relnamespace
         WHERE n.nspname = current_schema()
           AND t.relname = 'bsky_post_observations'
           AND i.indisunique
           AND i.indisvalid
           AND (
             SELECT array_agg(a.attname::text ORDER BY k.ord)
             FROM unnest(i.indkey) WITH ORDINALITY AS k(attnum, ord)
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum
             WHERE k.attnum > 0
           ) = ARRAY['bsky_uri', 'owner_user_id']::text[]"
    );
    return $st !== false && $st->fetchColumn() !== false;
}

/** @return list<string> */
function vaak_migrate_observation_unique_statements(bool $tableExists, bool $uniqueExists): array
{
    if (!$tableExists || $uniqueExists) {
        return [];
    }
    return [vaak_migrate_observation_unique_sql()];
}

function vaak_migrate_wafrn_crosspost_index_spec(): array
{
    return [
        'table' => 'wafrn_bsky_actors',
        'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_wafrn_bsky_actors_did ON wafrn_bsky_actors (bsky_did)',
        'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS idx_wafrn_bsky_actors_did',
    ];
}

/** @return list<string> */
function vaak_migrate_wafrn_crosspost_index_statements(string $action): array
{
    $spec = vaak_migrate_wafrn_crosspost_index_spec();
    if ($action === 'skip' || $action === 'missing-table') {
        return [];
    }
    if ($action === 'create') {
        return [$spec['create']];
    }
    if ($action === 'rebuild') {
        return [$spec['drop'], $spec['create']];
    }
    throw new RuntimeException('wafrn actor index was not created');
}

/**
 * DID lookup for Wafrn alsoKnownAs links. The table is created by the
 * additive file. This index is concurrent so deploy does not block reads
 * of wafrn_bsky_actors, and a page load never builds it.
 */
function vaak_migrate_wafrn_crosspost_indexes(PDO $db): void
{
    if ($db->inTransaction()) {
        throw new RuntimeException('wafrn actor index must not run inside a transaction');
    }
    $spec = vaak_migrate_wafrn_crosspost_index_spec();
    $table = $db->prepare(
        'SELECT 1 FROM pg_tables WHERE schemaname = current_schema() AND tablename = ?'
    );
    $table->execute([$spec['table']]);
    $tableExists = $table->fetchColumn() !== false;
    $index = $db->prepare(
        'SELECT i.indisvalid FROM pg_class c
         JOIN pg_index i ON i.indexrelid = c.oid
         JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = current_schema() AND c.relname = ?'
    );
    $index->execute(['idx_wafrn_bsky_actors_did']);
    $row = $index->fetch();
    $valid = is_array($row) ? vaak_migrate_pg_bool($row['indisvalid'] ?? null) : null;
    if (is_array($row) && $valid === null) {
        throw new RuntimeException('wafrn actor index validity is unknown');
    }
    $action = vaak_migrate_notification_index_plan($tableExists, $valid);
    if ($action === 'missing-table') {
        return;
    }
    foreach (vaak_migrate_wafrn_crosspost_index_statements($action) as $statement) {
        $db->exec($statement);
    }
}

function vaak_migrate_cpu_indexes(PDO $db): void
{
    if ($db->inTransaction()) {
        throw new RuntimeException('cpu indexes must not run inside a transaction');
    }
    foreach (vaak_migrate_cpu_index_specs() as $name => $spec) {
        $table = $db->prepare(
            'SELECT 1 FROM pg_tables WHERE schemaname = current_schema() AND tablename = ?'
        );
        $table->execute([$spec['table']]);
        $tableExists = $table->fetchColumn() !== false;
        $index = $db->prepare(
            'SELECT i.indisvalid FROM pg_class c
             JOIN pg_index i ON i.indexrelid = c.oid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema() AND c.relname = ?'
        );
        $index->execute([$name]);
        $row = $index->fetch();
        $valid = is_array($row) ? vaak_migrate_pg_bool($row['indisvalid'] ?? null) : null;
        if (is_array($row) && $valid === null) {
            throw new RuntimeException('cpu index validity is unknown');
        }
        $action = vaak_migrate_notification_index_plan($tableExists, $valid);
        if ($action === 'missing-table') {
            // bsky_posts is already required by the home Bluesky indexes.
            // Observations are created on first Bluesky use; skip until then.
            if ($spec['table'] === 'bsky_post_observations') {
                continue;
            }
            throw new RuntimeException($spec['table'] . ' is missing; cpu index was not created');
        }
        foreach (vaak_migrate_cpu_index_statements($name, $action) as $statement) {
            $db->exec($statement);
        }
    }
    $observations = $db->query(
        "SELECT 1 FROM pg_tables WHERE schemaname = current_schema() AND tablename = 'bsky_post_observations'"
    );
    $observationsExist = $observations !== false && $observations->fetchColumn() !== false;
    if (!$observationsExist) {
        return;
    }
    foreach (vaak_migrate_observation_unique_statements($observationsExist, vaak_migrate_observation_unique_exists($db)) as $statement) {
        $db->exec($statement);
    }
}

/**
 * Distinct Bluesky-follow actors for Home suggestions.
 * events already exists, so this is not part of
 * vaak_migrate_deploy_sql_files(). CREATE INDEX CONCURRENTLY runs outside a
 * transaction. A valid index is left untouched. An invalid leftover is
 * dropped and created again. A page load must not build this index.
 *
 * @return array{table: string, create: string, drop: string}
 */
function vaak_migrate_events_action_actor_index_spec(): array
{
    return [
        'table' => 'events',
        'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_action_actor ON events (action_taken, actor_id) WHERE actor_id IS NOT NULL',
        'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS idx_events_action_actor',
    ];
}

/** @return list<string> */
function vaak_migrate_events_action_actor_index_statements(string $action): array
{
    $spec = vaak_migrate_events_action_actor_index_spec();
    if ($action === 'skip') {
        return [];
    }
    if ($action === 'create') {
        return [$spec['create']];
    }
    if ($action === 'rebuild') {
        return [$spec['drop'], $spec['create']];
    }
    throw new RuntimeException('events action actor index was not created');
}

function vaak_migrate_events_action_actor_indexes(PDO $db): void
{
    if ($db->inTransaction()) {
        throw new RuntimeException('events action actor index must not run inside a transaction');
    }
    $spec = vaak_migrate_events_action_actor_index_spec();
    $table = $db->prepare(
        'SELECT 1 FROM pg_tables WHERE schemaname = current_schema() AND tablename = ?'
    );
    $table->execute([$spec['table']]);
    $tableExists = $table->fetchColumn() !== false;
    $index = $db->prepare(
        'SELECT i.indisvalid FROM pg_class c
         JOIN pg_index i ON i.indexrelid = c.oid
         JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = current_schema() AND c.relname = ?'
    );
    $index->execute(['idx_events_action_actor']);
    $row = $index->fetch();
    $valid = is_array($row) ? vaak_migrate_pg_bool($row['indisvalid'] ?? null) : null;
    if (is_array($row) && $valid === null) {
        throw new RuntimeException('events action actor index validity is unknown');
    }
    $action = vaak_migrate_notification_index_plan($tableExists, $valid);
    if ($action === 'missing-table') {
        throw new RuntimeException($spec['table'] . ' is missing; events action actor index was not created');
    }
    foreach (vaak_migrate_events_action_actor_index_statements($action) as $statement) {
        $db->exec($statement);
    }
}

/**
 * Apply one additive migration. The statement list is checked before any
 * SQL runs, so a rejected file leaves the schema untouched.
 *
 * @return list<string> tables the file creates
 */
function vaak_migrate_apply_additive_sql(PDO $db, string $path): array
{
    $sql = file_get_contents($path);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException(basename($path) . ' is empty');
    }
    $statements = vaak_migrate_sql_statements($sql);
    if ($statements === []) {
        throw new RuntimeException(basename($path) . ' has no statements');
    }
    $plan = vaak_migrate_additive_plan($statements, basename($path));
    foreach ($plan['statements'] as $statement) {
        $db->exec($statement);
    }
    return $plan['tables'];
}

function vaak_migrate_quote_target_index_spec(): array
{
    return [
        'table' => 'event_quote_targets',
        'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_event_quote_targets_target ON event_quote_targets (quote_target)',
        'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS idx_event_quote_targets_target',
    ];
}

/** @return list<string> */
function vaak_migrate_quote_target_index_statements(string $action): array
{
    $spec = vaak_migrate_quote_target_index_spec();
    if ($action === 'skip') {
        return [];
    }
    if ($action === 'create') {
        return [$spec['create']];
    }
    if ($action === 'rebuild') {
        return [$spec['drop'], $spec['create']];
    }
    throw new RuntimeException('quote target index was not created');
}

/**
 * Create the quote-target table if the additive file has not run yet, plus
 * the backfill cursor. Both statements are no-ops once the objects exist.
 */
function vaak_migrate_quote_target_ensure(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS event_quote_targets (
            event_id BIGINT NOT NULL,
            quote_target TEXT NOT NULL,
            object_id TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (event_id, quote_target)
        )"
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS event_quote_target_backfill (
            id SMALLINT PRIMARY KEY CHECK (id = 1),
            last_event_id BIGINT NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL
        )'
    );
    $db->exec(
        "INSERT INTO event_quote_target_backfill (id, last_event_id, updated_at)
         SELECT 1, 0, '1970-01-01T00:00:00Z'
         WHERE NOT EXISTS (SELECT 1 FROM event_quote_target_backfill WHERE id = 1)"
    );
}

/**
 * One primary-key slice of historical Creates. The cursor commits with the
 * inserts, so a re-run resumes after the last finished slice and does not
 * lock the events table for the whole scan.
 *
 * @return array{scanned:int,stored:int,last_id:int,done:bool}
 */
function vaak_migrate_quote_target_backfill_batch(PDO $db, int $batchSize = 1000): array
{
    if (!function_exists('ap_event_quote_targets_from_summary')) {
        throw new RuntimeException('quote target extractor is not loaded');
    }
    $batchSize = max(1, $batchSize);
    vaak_migrate_quote_target_ensure($db);
    $max = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM events')->fetchColumn();
    $last = (int) $db->query('SELECT last_event_id FROM event_quote_target_backfill WHERE id = 1')->fetchColumn();
    if ($last >= $max) {
        return ['scanned' => 0, 'stored' => 0, 'last_id' => $last, 'done' => true];
    }
    $hi = min($max, $last + $batchSize);
    $sel = $db->prepare(
        "SELECT id, summary, object_id FROM events
         WHERE id > ? AND id <= ? AND type = 'Create'
           AND summary IS NOT NULL AND summary LIKE '%https://%'"
    );
    $sel->execute([$last, $hi]);
    $rows = $sel->fetchAll();
    if (!is_array($rows)) {
        $rows = [];
    }
    $started = !$db->inTransaction();
    if ($started) {
        $db->beginTransaction();
    }
    $stored = 0;
    try {
        $ins = $db->prepare(
            'INSERT INTO event_quote_targets (event_id, quote_target, object_id)
             SELECT ?, ?, ?
             WHERE NOT EXISTS (
               SELECT 1 FROM event_quote_targets WHERE event_id = ? AND quote_target = ?
             )'
        );
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $eventId = (int) ($row['id'] ?? 0);
            $objectId = trim((string) ($row['object_id'] ?? ''));
            foreach (ap_event_quote_targets_from_summary((string) ($row['summary'] ?? '')) as $target) {
                $ins->execute([$eventId, $target, $objectId, $eventId, $target]);
                $changed = $ins->rowCount();
                if ($changed > 0) {
                    $stored += $changed;
                }
            }
        }
        $mark = $db->prepare('UPDATE event_quote_target_backfill SET last_event_id = ?, updated_at = ? WHERE id = 1');
        $mark->execute([$hi, gmdate('c')]);
        if ($started) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($started && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return [
        'scanned' => count($rows),
        'stored' => $stored,
        'last_id' => $hi,
        'done' => $hi >= $max,
    ];
}

/** @return array{scanned:int,stored:int,batches:int,last_id:int} */
function vaak_migrate_quote_target_backfill(PDO $db, int $batchSize = 1000): array
{
    $totals = ['scanned' => 0, 'stored' => 0, 'batches' => 0, 'last_id' => 0];
    for ($i = 0; $i < 100000; $i++) {
        $batch = vaak_migrate_quote_target_backfill_batch($db, $batchSize);
        $totals['scanned'] += $batch['scanned'];
        $totals['stored'] += $batch['stored'];
        $totals['batches']++;
        $totals['last_id'] = $batch['last_id'];
        if ($batch['done']) {
            return $totals;
        }
    }
    throw new RuntimeException('quote target backfill did not finish');
}

function vaak_migrate_quote_target_index(PDO $db): void
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
        throw new RuntimeException('quote target index requires postgres');
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('quote target index must not run inside a transaction');
    }
    $spec = vaak_migrate_quote_target_index_spec();
    $table = $db->prepare(
        'SELECT 1 FROM pg_tables WHERE schemaname = current_schema() AND tablename = ?'
    );
    $table->execute([$spec['table']]);
    $tableExists = $table->fetchColumn() !== false;
    $index = $db->prepare(
        'SELECT i.indisvalid FROM pg_class c
         JOIN pg_index i ON i.indexrelid = c.oid
         JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = current_schema() AND c.relname = ?'
    );
    $index->execute(['idx_event_quote_targets_target']);
    $row = $index->fetch();
    $valid = is_array($row) ? vaak_migrate_pg_bool($row['indisvalid'] ?? null) : null;
    if (is_array($row) && $valid === null) {
        throw new RuntimeException('quote target index validity is unknown');
    }
    $action = vaak_migrate_notification_index_plan($tableExists, $valid);
    if ($action === 'missing-table') {
        throw new RuntimeException('event_quote_targets is missing; quote target index was not created');
    }
    foreach (vaak_migrate_quote_target_index_statements($action) as $statement) {
        $db->exec($statement);
    }
}

/**
 * Backfill indexed quote targets, then build the read index concurrently.
 * Returns how many target rows this run inserted.
 */
function vaak_migrate_event_quote_targets(PDO $db): int
{
    if ($db->inTransaction()) {
        throw new RuntimeException('quote target backfill must not run inside a transaction');
    }
    if (!function_exists('ap_event_quote_targets_from_summary')) {
        throw new RuntimeException('quote target extractor is not loaded');
    }
    $filled = vaak_migrate_quote_target_backfill($db);
    vaak_migrate_quote_target_index($db);
    return $filled['stored'];
}

function vaak_migrate_main(array $argv): void
{
    $apiRoot = '';
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--api-root=')) {
            $apiRoot = substr($arg, strlen('--api-root='));
        } elseif ($arg === '--help') {
            fwrite(STDOUT, "php migrate.php --api-root=/path/to/release/api\n");
            exit(0);
        } else {
            vaak_migrate_fail('unknown argument');
        }
    }
    $apiRoot = $apiRoot !== '' ? realpath($apiRoot) : false;
    if ($apiRoot === false || !is_file($apiRoot . '/ap-db.php')) {
        vaak_migrate_fail('--api-root must be a release api directory');
    }

    $envPath = getenv('VAAK_MIGRATE_ENV');
    if (!is_string($envPath) || $envPath === '') {
        $envPath = '/etc/mkultra/vaak-migrate.env';
    }
    $env = vaak_migrate_load_env($envPath);
    $dsn = $env['AP_DB_DSN'] ?? '';
    $user = $env['AP_DB_USER'] ?? '';
    $password = $env['AP_DB_PASSWORD'] ?? '';
    if (!str_starts_with($dsn, 'pgsql:')) {
        vaak_migrate_fail('AP_DB_DSN must be a pgsql: DSN');
    }
    foreach ($env as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    $log = tempnam(sys_get_temp_dir(), 'vaak-migrate-');
    if ($log === false) {
        vaak_migrate_fail('could not create migration log');
    }
    ini_set('log_errors', '1');
    ini_set('error_log', $log);
    ini_set('display_errors', '0');

    $applied = [];
    try {
        $lock = new PDO($dsn, $user !== '' ? $user : null, $password !== '' ? $password : null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $locked = false;
        for ($try = 0; $try < 30; $try++) {
            $locked = vaak_migrate_is_true($lock->query('SELECT pg_try_advisory_lock(4815, 162342)')->fetchColumn());
            if ($locked) {
                break;
            }
            sleep(1);
        }
        if (!$locked) {
            vaak_migrate_fail('another migration holds the advisory lock');
        }
        $canCreate = $lock->query("SELECT has_schema_privilege(current_user, current_schema(), 'CREATE')")->fetchColumn();
        if (!vaak_migrate_is_true($canCreate)) {
            vaak_migrate_fail('migration role cannot CREATE in the current schema');
        }
        $role = (string) $lock->query('SELECT current_user')->fetchColumn();

        foreach (vaak_migrate_deploy_sql_files($apiRoot) as $sqlFile) {
            foreach (vaak_migrate_apply_additive_sql($lock, $sqlFile) as $table) {
                $applied[] = $table;
            }
        }

        require $apiRoot . '/ap-db.php';
        if (!function_exists('ap_db')) {
            vaak_migrate_fail('ap_db() is missing from the release');
        }
        $db = ap_db();
        if (ap_db_driver($db) !== 'pgsql') {
            vaak_migrate_fail('migration connected with a non-pgsql driver');
        }
        $tables = (int) $db->query(
            "SELECT COUNT(*) FROM pg_tables WHERE schemaname = current_schema()"
        )->fetchColumn();
        $users = vaak_migrate_is_true($db->query(
            "SELECT 1 FROM pg_tables WHERE schemaname = current_schema() AND tablename = 'ap_users'"
        )->fetchColumn());
        if ($tables < 1 || !$users) {
            vaak_migrate_fail('schema is missing ap_users after migration');
        }
        vaak_migrate_notification_read_indexes($lock);
        vaak_migrate_home_bsky_indexes($lock);
        vaak_migrate_cpu_indexes($lock);
        vaak_migrate_events_action_actor_indexes($lock);
        vaak_migrate_wafrn_crosspost_indexes($lock);
        $quoteTargets = vaak_migrate_event_quote_targets($lock);
    } catch (Throwable $e) {
        vaak_migrate_fail(vaak_migrate_redact($e->getMessage(), $password));
    }

    $logged = (string) file_get_contents($log);
    @unlink($log);
    $problems = [];
    foreach (preg_split("/\r\n|\n|\r/", $logged) ?: [] as $line) {
        if ($line !== '' && (str_contains($line, 'not provisioned') || str_contains($line, 'probe failed'))) {
            $problems[] = vaak_migrate_redact($line, $password);
        }
    }
    if ($problems !== []) {
        fwrite(STDERR, implode("\n", $problems) . "\n");
        vaak_migrate_fail('pending schema changes were not applied');
    }

    fwrite(STDOUT, "migrate_ok role={$role} tables={$tables} additive=" . count($applied) . " quote_targets={$quoteTargets}\n");
    exit(0);
}

if (vaak_migrate_is_entry_script()) {
    vaak_migrate_main($argv);
}
