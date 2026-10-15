<?php
declare(strict_types=1);
/**
 * Quote counts use event_quote_targets (equality + index), not a leading
 * wildcard scan of events.summary. No Postgres, Redis, or worker.
 */
$api = dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}

$checks = 0;
function quote_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

reference_load($api . '/ap-db.php', [
    'ap_event_quote_target_is_status_url',
    'ap_event_quote_targets_from_summary',
    'ap_event_quote_target_count_sql',
    'ap_events_store_quote_targets',
]);
require_once dirname($api) . '/deploy/remote/migrate.php';

$note = 'https://example.test/users/alice/notes/abc';
$quote = 'https://example.test/users/bob/notes/q1';

quote_check(ap_event_quote_targets_from_summary(null) === [], 'empty summary stores nothing');
quote_check(ap_event_quote_targets_from_summary('no url here') === [], 'plain text stores nothing');
quote_check(
    ap_event_quote_targets_from_summary('QT: ' . $note) === [$note],
    'bare QT url is a quote target'
);
quote_check(
    ap_event_quote_targets_from_summary("↪ QT: {$note}/.") === [$note],
    'trailing slash and punctuation normalize to the note id'
);
quote_check(
    ap_event_quote_targets_from_summary('see https://www.youtube.com/watch?v=abc and ' . $note) === [$note],
    'a non-status url is not a quote target'
);
quote_check(
    ap_event_quote_targets_from_summary($note . '.jpg') === [],
    'a media url is not a quote target'
);
$longer = 'https://example.test/users/alice/notes/abcdef';
quote_check(
    ap_event_quote_targets_from_summary('Original refers ' . $longer) === [$longer],
    'a longer status url is stored as itself'
);
$both = ap_event_quote_targets_from_summary("↪ QT: {$note}\nRE: {$quote}");
quote_check($both === [$note, $quote], 'every status url in one summary is kept');
$many = [];
for ($i = 0; $i < 20; $i++) {
    $many[] = 'https://example.test/users/alice/notes/n' . $i;
}
quote_check(count(ap_event_quote_targets_from_summary(implode(' ', $many))) === 16, 'one Create keeps at most 16 targets');
quote_check(
    ap_event_quote_target_is_status_url('https://bsky.app/profile/alice.bsky.social/post/3abc') === true,
    'a bluesky post url is a status'
);

$countSql = ap_event_quote_target_count_sql();
quote_check(!str_contains(strtolower($countSql), 'like'), 'quote count SQL is an equality lookup');
quote_check(str_contains($countSql, 'event_quote_targets'), 'quote count reads the target table');
quote_check(substr_count($countSql, 'IS DISTINCT FROM') === 2, 'quote count excludes the original note and its slash form');

$db = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('CREATE TABLE events (id INTEGER PRIMARY KEY, type TEXT, summary TEXT, object_id TEXT)');
$ins = $db->prepare('INSERT INTO events (id, type, summary, object_id) VALUES (?, ?, ?, ?)');
$ins->execute([1, 'Create', 'Original refers ' . $note, $note]);
$ins->execute([2, 'Create', 'Original slash refers ' . $note, $note . '/']);
$ins->execute([3, 'Create', 'QT: ' . $note, 'https://example.test/users/bob/notes/quote']);
$ins->execute([4, 'Announce', 'QT: ' . $note, 'https://example.test/users/carol/notes/boost']);
$ins->execute([5, 'Create', 'hello there', 'https://example.test/users/dave/notes/plain']);
$ins->execute([7, 'Create', "↪ QT: {$note}/\nRE: {$quote}", 'https://example.test/users/erin/notes/two']);
$ins->execute([9, 'Create', 'see https://www.youtube.com/watch?v=dQw4w9wg and ' . $longer, 'https://example.test/users/fran/notes/long']);

$first = vaak_migrate_quote_target_backfill_batch($db, 2);
quote_check($first['done'] === false && $first['last_id'] === 2, 'a batch stops at the id window');
quote_check($first['stored'] === 2, 'the first window stores both original Creates');
$done = vaak_migrate_quote_target_backfill($db, 2);
quote_check($done['last_id'] >= 9, 'later batches walk past id gaps to the max id');
quote_check($done['stored'] === 4, 'later windows store the quoting Creates');
$again = vaak_migrate_quote_target_backfill($db, 2);
quote_check($again['stored'] === 0 && $again['last_id'] >= 9, 'a caught-up backfill inserts nothing');

$count = $db->prepare($countSql);
$count->execute([$note, $note, $note . '/']);
quote_check((int) $count->fetchColumn() === 2, 'two quoting Creates count and the original root and slash do not');
$count->execute([$quote, $quote, $quote . '/']);
quote_check((int) $count->fetchColumn() === 1, 'the second status url in one summary counts on its own');
$count->execute([$longer, $longer, $longer . '/']);
quote_check((int) $count->fetchColumn() === 1, 'a longer note id is not credited to its prefix');
$announce = (int) $db->query("SELECT COUNT(*) FROM event_quote_targets WHERE event_id = 4")->fetchColumn();
quote_check($announce === 0, 'Announce rows are not quote targets');
$plain = (int) $db->query("SELECT COUNT(*) FROM event_quote_targets WHERE event_id = 5")->fetchColumn();
quote_check($plain === 0, 'a Create with no status url stores nothing');

function ap_db(): PDO
{
    return $GLOBALS['db'];
}
$GLOBALS['db'] = $db;
$before = (int) $db->query('SELECT COUNT(*) FROM event_quote_targets')->fetchColumn();
ap_events_store_quote_targets(9, 'Create', 'https://example.test/users/fran/notes/long', 'QT: ' . $longer);
quote_check(
    (int) $db->query('SELECT COUNT(*) FROM event_quote_targets')->fetchColumn() === $before,
    'ingest does not duplicate a target the backfill already stored'
);
ap_events_store_quote_targets(11, 'Create', 'https://example.test/users/gina/notes/new', 'QT: ' . $note . '.');
$count->execute([$note, $note, $note . '/']);
quote_check((int) $count->fetchColumn() === 3, 'ingest records a new quote before the next backfill');
ap_events_store_quote_targets(12, 'Announce', 'https://example.test/users/gina/notes/new', 'QT: ' . $note);
$count->execute([$note, $note, $note . '/']);
quote_check((int) $count->fetchColumn() === 3, 'ingest ignores a non-Create');
$caught = vaak_migrate_quote_target_backfill($db, 1000);
quote_check($caught['stored'] === 0, 'backfill does not insert the row ingest already wrote');

$empty = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['db'] = $empty;
$swallowed = false;
try {
    ap_events_store_quote_targets(1, 'Create', $quote, 'QT: ' . $note);
    $swallowed = true;
} catch (Throwable $e) {
    $swallowed = false;
}
quote_check($swallowed, 'ingest ignores a missing quote-target table');
$GLOBALS['db'] = $db;

$spec = vaak_migrate_quote_target_index_spec();
quote_check(str_starts_with($spec['create'], 'CREATE INDEX CONCURRENTLY '), 'the read index is built concurrently');
quote_check(str_starts_with($spec['drop'], 'DROP INDEX CONCURRENTLY '), 'an invalid index is dropped concurrently');
quote_check(vaak_migrate_quote_target_index_statements('skip') === [], 'a valid index is left untouched');
quote_check(vaak_migrate_quote_target_index_statements('create') === [$spec['create']], 'the first build only creates');
quote_check(
    vaak_migrate_quote_target_index_statements('rebuild') === [$spec['drop'], $spec['create']],
    'an invalid index is rebuilt concurrently'
);
quote_check(vaak_migrate_notification_index_plan(true, true) === 'skip', 'valid index plan is skip');
quote_check(vaak_migrate_notification_index_plan(true, false) === 'rebuild', 'invalid index plan is rebuild');
quote_check(vaak_migrate_notification_index_plan(true, null) === 'create', 'missing index plan is create');

$indexBlocked = false;
try {
    vaak_migrate_quote_target_index($db);
} catch (RuntimeException $e) {
    $indexBlocked = str_contains($e->getMessage(), 'requires postgres');
}
quote_check($indexBlocked, 'sqlite never runs the concurrent index statements');

final class QuoteTargetTxPdo extends PDO
{
    public function inTransaction(): bool
    {
        return true;
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return 'pgsql';
        }
        return parent::getAttribute($attribute);
    }
}
$tx = new QuoteTargetTxPdo('sqlite::memory:');
$backfillBlocked = false;
try {
    vaak_migrate_event_quote_targets($tx);
} catch (RuntimeException $e) {
    $backfillBlocked = str_contains($e->getMessage(), 'must not run inside a transaction');
}
quote_check($backfillBlocked, 'quote target backfill refuses an open transaction');
$indexTxBlocked = false;
try {
    vaak_migrate_quote_target_index($tx);
} catch (RuntimeException $e) {
    $indexTxBlocked = str_contains($e->getMessage(), 'must not run inside a transaction');
}
quote_check($indexTxBlocked, 'quote target index refuses an open transaction');

$files = vaak_migrate_deploy_sql_files($api);
$names = array_map('basename', $files);
quote_check(in_array('20261014_event_quote_targets_postgres.sql', $names, true), 'migrate applies the quote-target table');
$sqlPath = $api . '/migrations/20261014_event_quote_targets_postgres.sql';
$sql = (string) file_get_contents($sqlPath);
quote_check(str_contains($sql, 'CREATE TABLE IF NOT EXISTS event_quote_targets'), 'the table create is idempotent');
quote_check(!str_contains($sql, 'CREATE INDEX'), 'the additive file does not build the read index');
quote_check(str_contains($sql, 'GRANT SELECT, INSERT, UPDATE, DELETE ON event_quote_targets TO "www-data"'), 'the app role can read and write targets');
quote_check(!str_contains($sql, 'password') && !str_contains($sql, 'postgres://'), 'the migration file has no credentials');
$plan = vaak_migrate_additive_plan(vaak_migrate_sql_statements($sql), basename($sqlPath));
quote_check($plan['tables'] === ['event_quote_targets'], 'the additive plan creates only the quote-target table');

final class QuoteTargetRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $statements = [];

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;
        return 0;
    }
}
$recording = new QuoteTargetRecordingPdo('sqlite::memory:');
$applied = vaak_migrate_apply_additive_sql($recording, $sqlPath);
quote_check($applied === ['event_quote_targets'], 'apply returns the quote-target table');
quote_check(count($recording->statements) === 2, 'apply runs the create and the grant');
$joined = implode("\n", $recording->statements);
quote_check(!str_contains($joined, 'CONCURRENTLY'), 'the additive apply never issues a concurrent index');

$apdb = (string) file_get_contents($api . '/ap-db.php');
quote_check(!str_contains($apdb, 'summary LIKE'), 'ap-db.php no longer scans event summaries for quote counts');
quote_check(str_contains($apdb, 'ap_event_quote_target_count_sql()'), 'engagement counts use the indexed lookup');
$bsky = (string) file_get_contents($api . '/ap-bsky.php');
quote_check(str_contains($bsky, 'ap_events_store_quote_targets('), 'a rewritten compose summary refreshes quote targets');
$rust = (string) file_get_contents(dirname($api) . '/rust/vaak-worker/src/library_facts.rs');
quote_check(
    preg_match('/QUOTE_TARGET_COUNT_SQL: &str = "([^"]+)"/', $rust, $rustSql) === 1,
    'the Rust count twin has one quote-target query'
);
quote_check(str_contains($rustSql[1], 'event_quote_targets'), 'the Rust count twin reads the same table');
quote_check(!str_contains(strtolower($rustSql[1]), 'like'), 'the Rust count twin does not scan summaries');

echo "quote-target-count-smoke ok checks={$checks}\n";
