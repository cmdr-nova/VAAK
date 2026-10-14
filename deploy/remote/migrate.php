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

    fwrite(STDOUT, "migrate_ok role={$role} tables={$tables} additive=" . count($applied) . "\n");
    exit(0);
}

if (vaak_migrate_is_entry_script()) {
    vaak_migrate_main($argv);
}
