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

fwrite(STDOUT, "migrate_ok role={$role} tables={$tables}\n");
exit(0);
