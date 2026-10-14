<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/ap-follow-state.php';

$n = 0;
function check(bool $ok, string $why): void
{
    if (!$ok) {
        fwrite(STDERR, "follow-fallback: {$why}\n");
        exit(1);
    }
    $GLOBALS['n']++;
}

$local = 'https://mkultra.monster/users/cmdr_nova';
$remote = 'https://misskey.io/users/ada';
$accept = ap_follow_response_classify([
    'type' => 'Accept',
    'actor' => $remote,
    'object' => ['type' => 'Follow', 'id' => $local . '/follows/abc', 'actor' => $local, 'object' => $remote],
], $local);
check(is_array($accept) && $accept['kind'] === 'outbound' && $accept['state'] === 'accepted' && $accept['target_actor'] === $remote, 'accept classifies our follow');
$reject = ap_follow_response_classify([
    'type' => 'Reject',
    'actor' => $remote,
    'object' => ['type' => 'Follow', 'actor' => $local, 'object' => $remote],
], $local);
check(is_array($reject) && $reject['state'] === 'rejected', 'reject classifies our follow');
$ack = ap_follow_response_classify([
    'type' => 'Accept',
    'actor' => $remote,
    'object' => ['type' => 'Follow', 'actor' => $remote, 'object' => $local],
], $local);
check(is_array($ack) && $ack['kind'] === 'ack', 'accept of their follow is an ack');
check(ap_follow_response_classify(['type' => 'Like', 'actor' => $remote, 'object' => $local], $local) === null, 'other activities are ignored');
check(ap_follow_state_apply('unknown', 'send') === 'pending', 'send is pending');
check(ap_follow_state_apply('pending', 'accept') === 'accepted', 'accept is accepted');
check(ap_follow_state_apply('accepted', 'reject') === 'rejected', 'reject is rejected');

$scrubbed = ap_delivery_scrub('nope signature="abcDEF+/=" keyId="https://x/key" bearer SECRET digest: sha-256=', 120);
check(!str_contains(strtolower($scrubbed), 'signature') && !str_contains($scrubbed, 'SECRET') && !str_contains(strtolower($scrubbed), 'sha-256') && str_contains($scrubbed, 'nope'), 'scrub drops signature material');
check(ap_delivery_scrub("-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----", 120) === '', 'scrub drops private keys');
$failure = ap_delivery_log_line('misskey.io', 'Create', 403, false, 'denied signature="abcDEF+/="', '/inbox?sig=1');
check(str_contains($failure, 'host=misskey.io') && str_contains($failure, 'status=403') && str_contains($failure, 'ok=0') && !str_contains($failure, 'abcDEF') && !str_contains($failure, 'sig=1'), 'failure log is per host and has no signature');
$followLog = ap_delivery_log_line('https://misskey.io/inbox', 'Follow', 202, true, 'signature="abcDEF+/="', '/inbox');
check(str_contains($followLog, 'type=Follow') && str_contains($followLog, 'ok=1') && !str_contains($followLog, 'body=') && !str_contains($followLog, 'abcDEF'), 'successful follow logs status without a body');
check(ap_delivery_should_log(true, 'Create') === false && ap_delivery_should_log(false, 'Create') === true && ap_delivery_should_log(true, 'Accept') === true, 'only failures and follow events are logged');
check(ap_follow_fallback_validator("W/\"abc\"") === 'W/"abc"', 'etag validator is kept');
check(ap_follow_fallback_validator("Wed, 21 Oct 2015 07:28:00 GMT") === 'Wed, 21 Oct 2015 07:28:00 GMT', 'last-modified validator is kept');
check(ap_follow_fallback_validator("W/\"abc\"\r\nX-Injected: 1") === null, 'validator with a newline is dropped');

$dir = sys_get_temp_dir() . '/ap-follow-log-' . bin2hex(random_bytes(4));
mkdir($dir);
$log = $dir . '/delivery.log';
ap_delivery_log_append($log, 'first', 8);
ap_delivery_log_append($log, 'second-line-is-longer', 8);
check(is_file($log . '.1') && filesize($log) < 80, 'delivery log rotates once it reaches the cap');
$GLOBALS['ap_delivery_log_recent'] = [];
$first = ap_delivery_log_result('quiet.example', 'Create', 500, false, 'nope', '/inbox', $dir . '/rate.log');
$second = ap_delivery_log_result('quiet.example', 'Create', 500, false, 'nope', '/inbox', $dir . '/rate.log');
$third = ap_delivery_log_result('quiet.example', 'Follow', 500, false, 'nope', '/inbox', $dir . '/rate.log');
check($first !== null && $second === null && $third !== null, 'repeated failures collapse per host while follow events stay');

$default = ap_follow_fallback_config([]);
check($default['enabled'] === true && $default['hosts'] === ['misskey.io'] && $default['min_interval_seconds'] === 900 && $default['page_limit'] === 5 && $default['request_budget'] === 3, 'fallback defaults to misskey.io and a 15 minute floor');
$killed = ap_follow_fallback_config(['VAAK_FOLLOW_FALLBACK' => 'off', 'VAAK_FOLLOW_FALLBACK_MIN_INTERVAL_MINUTES' => '1', 'VAAK_FOLLOW_FALLBACK_PAGE_LIMIT' => '40']);
check($killed['enabled'] === false && $killed['min_interval_seconds'] === 900 && $killed['page_limit'] === 8, 'kill switch and clamps hold');
check(ap_follow_fallback_config(['VAAK_FOLLOW_FALLBACK_HOSTS' => ''])['hosts'] === [], 'an empty host list is not replaced with the default');
check(ap_follow_fallback_due(100, 999, 900) === false && ap_follow_fallback_due(0, 1000, 900) === true && ap_follow_fallback_due(100, 1000, 900) === true, 'the job refuses to run again inside the minimum interval');
check(ap_follow_fallback_backoff_seconds(1, 429) === 900 && ap_follow_fallback_backoff_seconds(8, 429) === 21600 && ap_follow_fallback_backoff_seconds(1, 404) === 21600, '429 backs off and other errors wait longer');

$now = 1700000000;
$plan = ap_follow_fallback_plan($default, [
    ['actor_id' => 'https://misskey.io/users/a', 'host' => 'misskey.io', 'follow_state' => 'unknown', 'state_at' => 0, 'backoff_until' => 0],
    ['actor_id' => 'https://misskey.io/users/no', 'host' => 'misskey.io', 'follow_state' => 'rejected', 'state_at' => 0, 'backoff_until' => 0],
    ['actor_id' => 'https://pending.example/users/old', 'host' => 'pending.example', 'follow_state' => 'pending', 'state_at' => $now - 30000, 'backoff_until' => 0],
    ['actor_id' => 'https://pending.example/users/new', 'host' => 'pending.example', 'follow_state' => 'pending', 'state_at' => $now - 60, 'backoff_until' => 0],
    ['actor_id' => 'https://quiet.example/users/b', 'host' => 'quiet.example', 'follow_state' => 'accepted', 'state_at' => 0, 'backoff_until' => 0],
    ['actor_id' => 'https://fresh.example/users/c', 'host' => 'fresh.example', 'follow_state' => 'accepted', 'state_at' => 0, 'backoff_until' => 0],
    ['actor_id' => 'https://never.example/users/z', 'host' => 'never.example', 'follow_state' => 'accepted', 'state_at' => 0, 'backoff_until' => 0],
], ['quiet.example' => $now - 90000, 'fresh.example' => $now - 60], $now);
check($plan === [
    'https://misskey.io/users/a',
    'https://pending.example/users/old',
    'https://quiet.example/users/b',
], 'plan keeps force, old pending, and stale hosts, and skips rejected, fresh, young, and never-seen hosts');
check(ap_follow_fallback_plan(['enabled' => false, 'hosts' => ['misskey.io']], [['actor_id' => $remote, 'host' => 'misskey.io', 'follow_state' => 'pending', 'state_at' => 1, 'backoff_until' => 0]], [], $now) === [], 'kill switch selects nobody');
$picked = ap_follow_fallback_select_new([
    'https://misskey.io/notes/1', 'https://misskey.io/notes/2/', 'https://misskey.io/notes/old', 'https://misskey.io/notes/3',
], ['https://misskey.io/notes/old'], 5, true);
check($picked === ['ids' => ['https://misskey.io/notes/1', 'https://misskey.io/notes/2'], 'stopped' => true], 'outbox walk stops at an already seen id');

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE following (
    owner_actor_id TEXT NOT NULL, actor_id TEXT NOT NULL, followed_at TEXT NOT NULL, host TEXT,
    follow_state TEXT NOT NULL DEFAULT \'unknown\', follow_activity_id TEXT, state_at TEXT,
    PRIMARY KEY (owner_actor_id, actor_id))');
$db->exec('CREATE INDEX idx_following_host_state ON following (host, follow_state)');
$db->exec('CREATE INDEX idx_following_state_at ON following (follow_state, state_at)');
$db->exec('CREATE INDEX idx_following_follow_activity ON following (follow_activity_id)');
$db->exec('CREATE TABLE ap_inbox_host_seen (host TEXT PRIMARY KEY, last_seen_at TEXT NOT NULL, last_type TEXT)');
$db->exec('CREATE TABLE ap_follow_fallback_state (
    actor_id TEXT PRIMARY KEY, host TEXT NOT NULL, outbox_url TEXT, featured_url TEXT, etag TEXT, last_modified TEXT,
    featured_etag TEXT, featured_last_modified TEXT, next_attempt_at TEXT, fail_count INTEGER NOT NULL DEFAULT 0,
    last_status INTEGER, updated_at TEXT NOT NULL)');
$db->exec('CREATE TABLE events (id INTEGER PRIMARY KEY, type TEXT, object_id TEXT)');
$db->exec('CREATE INDEX idx_events_object_id ON events(object_id)');
$db->exec("CREATE TABLE bsky_posts (id INTEGER PRIMARY KEY, text TEXT)");
$db->exec("INSERT INTO bsky_posts (text) VALUES ('do not scan')");

check(ap_following_state_write($db, $local, 'https://misskey.io/users/a', 'misskey.io', gmdate('c', $now - 100), 'pending', $local . '/follows/abc'), 'pending follow is stored');
check(ap_following_state_write($db, $local, 'https://misskey.io/users/a', 'misskey.io', gmdate('c', $now), 'accepted', null), 'accept updates the same row');
$row = $db->query("SELECT follow_state, follow_activity_id FROM following WHERE actor_id = 'https://misskey.io/users/a'")->fetch(PDO::FETCH_ASSOC);
check($row['follow_state'] === 'accepted' && $row['follow_activity_id'] === $local . '/follows/abc', 'accept keeps the follow id and records accepted');
$byId = ap_following_row_by_activity($db, $local . '/follows/abc');
check(is_array($byId) && $byId['actor_id'] === 'https://misskey.io/users/a', 'accept can be matched by the follow activity id');
check(ap_following_state_write($db, $local, 'https://misskey.io/users/no', 'misskey.io', gmdate('c', $now), 'rejected', null), 'reject is stored');
check(ap_following_rows_active($db->query('SELECT * FROM following')->fetchAll(PDO::FETCH_ASSOC))[0]['actor_id'] === 'https://misskey.io/users/a', 'rejected follows stay recorded but leave the active list');

ap_inbox_host_seen_touch($db, 'quiet.example', 'Create', $now - 1000, 300);
ap_inbox_host_seen_touch($db, 'quiet.example', 'Create', $now - 900, 300);
$seen = $db->query("SELECT last_seen_at, last_type FROM ap_inbox_host_seen WHERE host = 'quiet.example'")->fetch(PDO::FETCH_ASSOC);
check($seen['last_seen_at'] === gmdate('c', $now - 1000), 'host seen does not rewrite inside the throttle window');
ap_inbox_host_seen_touch($db, 'quiet.example', 'Announce', $now, 300);
$seen = $db->query("SELECT last_type FROM ap_inbox_host_seen WHERE host = 'quiet.example'")->fetch(PDO::FETCH_ASSOC);
check($seen['last_type'] === 'Announce', 'host seen updates after the throttle window');

$db->exec("INSERT INTO events (type, object_id) VALUES ('Create', 'https://misskey.io/notes/old')");
check(ap_follow_object_known($db, 'https://misskey.io/notes/old/') === true, 'known objects use the events id index');
check(ap_follow_object_known($db, 'https://misskey.io/notes/new') === false, 'unknown objects are not treated as seen');
check($db->query('SELECT COUNT(*) FROM bsky_posts')->fetchColumn() === 1, 'fallback lookups do not read bsky_posts');

$db->exec("INSERT INTO following (owner_actor_id, actor_id, followed_at, host, follow_state, state_at) VALUES
    ('{$local}', 'https://pending.example/users/old', '" . gmdate('c', $now - 30000) . "', 'pending.example', 'pending', '" . gmdate('c', $now - 30000) . "'),
    ('{$local}', 'https://quiet.example/users/b', '" . gmdate('c', $now - 10) . "', 'quiet.example', 'accepted', NULL)");
$db->exec("UPDATE ap_inbox_host_seen SET last_seen_at = '" . gmdate('c', $now - 90000) . "', last_type = 'Create' WHERE host = 'quiet.example'");
$outbox = [
    'orderedItems' => [
        ['type' => 'Create', 'actor' => 'https://misskey.io/users/a', 'object' => ['id' => 'https://misskey.io/notes/1', 'type' => 'Note']],
        ['type' => 'Create', 'actor' => 'https://misskey.io/users/a', 'object' => ['id' => 'https://misskey.io/notes/2', 'type' => 'Note']],
        ['type' => 'Create', 'actor' => 'https://misskey.io/users/a', 'object' => ['id' => 'https://misskey.io/notes/old', 'type' => 'Note']],
    ],
];
ap_follow_fallback_state_put($db, [
    'actor_id' => 'https://misskey.io/users/a', 'host' => 'misskey.io', 'outbox_url' => 'https://misskey.io/users/a/outbox',
    'updated_at' => gmdate('c', $now), 'fail_count' => 0,
]);
ap_follow_fallback_state_put($db, [
    'actor_id' => 'https://pending.example/users/old', 'host' => 'pending.example', 'outbox_url' => 'https://pending.example/users/old/outbox',
    'etag' => '"same"', 'updated_at' => gmdate('c', $now), 'fail_count' => 0,
]);
ap_follow_fallback_state_put($db, [
    'actor_id' => 'https://quiet.example/users/b', 'host' => 'quiet.example', 'outbox_url' => 'https://quiet.example/users/b/outbox',
    'updated_at' => gmdate('c', $now), 'fail_count' => 0,
]);
$calls = [];
$ingested = [];
$fetch = static function (string $url, ?string $etag, ?string $lastModified) use (&$calls, $outbox): array {
    $calls[] = [$url, $etag, $lastModified];
    if (str_contains($url, 'misskey.io')) {
        return ['status' => 200, 'body' => json_encode($outbox), 'etag' => '"v1"', 'requests' => 1];
    }
    if (str_contains($url, 'pending.example')) {
        return ['status' => 304, 'body' => '', 'etag' => '"same"', 'requests' => 1];
    }
    return ['status' => 429, 'body' => 'slow down signature="SECRET"', 'requests' => 1];
};
$result = ap_follow_fallback_execute($db, [], $now, $fetch, static function (array $activity) use (&$ingested): bool {
    $ingested[] = $activity['object']['id'] ?? '';
    return true;
}, static function (string $id) use ($db): bool {
    return ap_follow_object_known($db, $id);
});
check($result['accounts'] === 3 && $result['requests'] === 3 && $result['imported'] === 2 && $result['not_modified'] === 1 && $result['errors'] === 1, 'one pass fetches only the planned accounts');
check($ingested === ['https://misskey.io/notes/1', 'https://misskey.io/notes/2'], 'new notes go through ingest and the seen id stops the page');
check($calls[0][1] === null && $calls[1][1] === '"same"', 'repeat fetches send the stored validator');
$quiet = ap_follow_fallback_state_get($db, 'https://quiet.example/users/b');
check(is_array($quiet) && (int) $quiet['fail_count'] === 1 && (int) $quiet['last_status'] === 429 && strtotime((string) $quiet['next_attempt_at']) > $now, '429 stores a backoff');
$second = ap_follow_fallback_execute($db, [], $now + 60, $fetch, static function (array $activity) use (&$ingested): bool {
    $ingested[] = 'again';
    return true;
}, static function (string $id) use ($db): bool {
    return ap_follow_object_known($db, $id);
});
$quietCalls = array_values(array_filter($calls, static fn(array $call): bool => str_contains($call[0], 'quiet.example')));
check($second['accounts'] === 2 && count($quietCalls) === 1, 'backoff skips the host that returned 429');
$off = ap_follow_fallback_execute($db, ['VAAK_FOLLOW_FALLBACK' => '0'], $now, $fetch, static fn(array $activity): bool => false, static fn(string $id): bool => false);
check($off['disabled'] === true && $off['requests'] === 0, 'kill switch performs no fetches');

$GLOBALS['upserts'] = [];
function ap_db(): PDO
{
    return $GLOBALS['follow_db'];
}
function ap_following_upsert(string $actorId, ?string $ownerActorId = null, ?string $followState = null, ?string $followActivityId = null): void
{
    $GLOBALS['upserts'][] = [$actorId, $ownerActorId, $followState, $followActivityId];
}
$GLOBALS['follow_db'] = $db;
$applied = ap_follow_response_apply([
    'type' => 'Accept',
    'actor' => 'https://misskey.io/users/other',
    'object' => $local . '/follows/abc',
], $local);
check($applied === 'follow_accepted' && $GLOBALS['upserts'][0][0] === 'https://misskey.io/users/a' && $GLOBALS['upserts'][0][2] === 'accepted', 'a bare follow id is accepted when the signer host matches');
$ignored = ap_follow_response_apply([
    'type' => 'Accept',
    'actor' => 'https://evil.test/users/a',
    'object' => ['type' => 'Follow', 'actor' => $local, 'object' => $remote],
], $local);
check($ignored === null && count($GLOBALS['upserts']) === 1, 'an accept signed by a different host is ignored');

echo "follow-fallback: PASS ({$n} checks)\n";
