<?php
declare(strict_types=1);
/**
 * ap_known_shared_inboxes reads its host ranking through
 * ap_known_federating_hosts, which scans `events` once and then serves the
 * ranking from cache Redis for an hour (and per process). The per-call skips
 * (local host, Wafrn friends, broken /inbox guesses) still run every time.
 * SQLite in memory, fake Redis, no network.
 */
$api = dirname(__DIR__);
require __DIR__ . '/migration-reference-functions.php';

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo']->exec('CREATE TABLE events (id INTEGER PRIMARY KEY, host TEXT)');
$ins = $GLOBALS['pdo']->prepare('INSERT INTO events (host) VALUES (?)');
foreach (['a.test' => 5, 'b.test' => 3, 'mkultra.monster' => 9, 'friend.test' => 4, 'lemmy.ml' => 2, '' => 7] as $host => $n) {
    for ($i = 0; $i < $n; $i++) {
        $ins->execute([$host]);
    }
}

$GLOBALS['queries'] = 0;
$GLOBALS['redis'] = [];
$GLOBALS['ttl'] = [];
final class CountingPdo
{
    public function query(string $sql): PDOStatement
    {
        $GLOBALS['queries']++;
        return $GLOBALS['pdo']->query($sql);
    }
}
function ap_db(): CountingPdo { return new CountingPdo(); }
function ap_redis_json_get(string $key): ?array { return $GLOBALS['redis'][$key] ?? null; }
function ap_redis_json_set(string $key, array $value, int $ttl): bool { $GLOBALS['redis'][$key] = $value; $GLOBALS['ttl'][$key] = $ttl; return true; }
function ap_wafrn_friend_inboxes(int $limit): array { return ['https://friend.test/fediverse/sharedInbox']; }

reference_load($api . '/ap-db.php', ['ap_known_federating_hosts', 'ap_known_shared_inboxes']);

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
};

$first = ap_known_shared_inboxes(40);
$check($first === ['https://a.test/inbox', 'https://b.test/inbox'], 'busiest hosts first, local/friend/broken hosts skipped: ' . json_encode($first));
$check($GLOBALS['queries'] === 1, 'first call scans events once');
$key = 'vaak:known_federating_hosts:v1';
$check(isset($GLOBALS['redis'][$key]['rows']) && count($GLOBALS['redis'][$key]['rows']) === 5, 'ranking cached in Redis without the empty host');
$check(($GLOBALS['ttl'][$key] ?? 0) === 3600, 'cache lives for an hour');

$again = ap_known_shared_inboxes(8);
$check($again === $first, 'second call returns the same inboxes');
$check($GLOBALS['queries'] === 1, 'second call in the same process does not scan events again');
$check(ap_known_shared_inboxes(1) === ['https://a.test/inbox'], 'the limit still applies to a cached ranking');

if ($failures) {
    fwrite(STDERR, "known-shared-inboxes-cache-smoke: FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "known-shared-inboxes-cache-smoke: ok\n";
