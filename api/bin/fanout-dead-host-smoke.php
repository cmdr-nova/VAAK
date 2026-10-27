<?php
/**
 * Dead-host fan-out suspension. No network and no production database.
 * The reference harness evaluates the named helpers; this file does not
 * include ap-fanout-host.php itself.
 */
declare(strict_types=1);

putenv('VAAK_FANOUT_HOST_NO_KICK=1');
$GLOBALS['ap_fanout_host_no_kick'] = true;
putenv('VAAK_FANOUT_DEAD_DAYS');
putenv('VAAK_FANOUT_DEAD_FAILURES');
unset($GLOBALS['ap_fanout_host_config'], $GLOBALS['ap_fanout_host_now']);

require_once __DIR__ . '/migration-reference-functions.php';
$lists = migration_reference_function_lists();
$names = $lists['ap-fanout-host.php'] ?? null;
if (!is_array($names) || $names === []) {
    fwrite(STDERR, "fanout-dead-host: reference list is missing\n");
    exit(1);
}
reference_load(dirname(__DIR__) . '/ap-fanout-host.php', $names);

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $why) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $why;
    }
};

$now = 1_700_000_000;
$week = 7 * 86400;
$GLOBALS['ap_fanout_host_now'] = $now;

$check(ap_fanout_dead_days() === 7, 'default dead days');
$check(ap_fanout_dead_failures() === 20, 'default dead failures');
$check(ap_fanout_terminal_attempt_limit(false) === 12, 'healthy host gets 12 tries');
$check(ap_fanout_terminal_attempt_limit(true) === 1, 'suspended host gets 1 try');
putenv('VAAK_FANOUT_DEAD_DAYS=9');
putenv('VAAK_FANOUT_DEAD_FAILURES=4');
$check(ap_fanout_dead_days() === 9 && ap_fanout_dead_failures() === 4, 'env overrides the defaults');
putenv('VAAK_FANOUT_DEAD_DAYS=nope');
$check(ap_fanout_dead_days() === 7, 'a non-numeric day count stays at the default');
putenv('VAAK_FANOUT_DEAD_DAYS');
putenv('VAAK_FANOUT_DEAD_FAILURES');
$GLOBALS['ap_fanout_host_config'] = ['VAAK_FANOUT_DEAD_DAYS' => '8', 'VAAK_FANOUT_DEAD_FAILURES' => '3'];
$check(ap_fanout_dead_days() === 8 && ap_fanout_dead_failures() === 3, 'config overrides when env is unset');
unset($GLOBALS['ap_fanout_host_config']);
$check(ap_fanout_dead_days() === 7 && ap_fanout_dead_failures() === 20, 'defaults return');

$check(ap_fanout_host_normalize('https://Tube.Baeddel.Social/inbox') === 'tube.baeddel.social', 'inbox host is normalized');
$check(ap_fanout_host_normalize('HTTPS://micro.charlesbeadle.tech:443/users/a/inbox') === 'micro.charlesbeadle.tech', 'port is not part of the host');
$check(ap_fanout_host_normalize('*') === '' && ap_fanout_host_normalize('localhost') === '', 'marker and single-label hosts are ignored');
$check(ap_fanout_host_is_bluesky_host('bsky.social') && ap_fanout_host_is_bluesky_host('https://Bsky.App/x'), 'bluesky hosts are recognized');
$check(!ap_fanout_host_is_bluesky_host('tube.baeddel.social'), 'a dead peer is not bluesky');

foreach (['HTTP 401', 'upstream HTTP 403 Forbidden', 'HTTP 500 '] as $live) {
    $check(ap_fanout_live_rejection_status($live) !== null, $live . ' is a live rejection');
    $check(!ap_fanout_counts_as_dead_failure($live), $live . ' is not a dead failure');
}
$check(ap_fanout_live_rejection_status('HTTP 5000') === null, 'HTTP 5000 is not HTTP 500');
$check(ap_fanout_live_rejection_status('HTTP 404') === null && ap_fanout_counts_as_dead_failure('HTTP 404'), 'other HTTP statuses can still count');
foreach (['Remote host circuit is open', 'Suspended remote host', 'Worker lease expired', 'Signing key is unavailable', ''] as $skip) {
    $check(!ap_fanout_counts_as_dead_failure($skip), 'inconclusive error is not a dead failure: ' . $skip);
}
$check(ap_fanout_counts_as_dead_failure('No HTTP response from inbox'), 'timeout counts as dead');
$check(ap_fanout_activity_is_bluesky('{"actor":"did:plc:abc"}'), 'DID actor is bluesky');
$check(ap_fanout_activity_is_bluesky('{"actor":"https://bsky.app/profile/alice"}'), 'bsky.app actor is bluesky');
$check(!ap_fanout_activity_is_bluesky('{"actor":"https://tube.baeddel.social/users/a","type":"Create"}'), 'ActivityPub actor is not bluesky');

$check(!ap_fanout_host_suspended(null, $now - $week, 19, null, $now), '19 failures are not enough');
$check(!ap_fanout_host_suspended(null, $now - $week + 10, 25, null, $now), 'a young streak is not suspended');
$check(!ap_fanout_host_suspended($now - 86400, $now - $week, 25, null, $now), 'a recent success is never suspended');
$check(!ap_fanout_host_suspended(null, $now - $week, 25, $now - 86400, $now), 'a recent 401/403/500 keeps the host alive');
$check(ap_fanout_host_suspended(null, $now - $week, 20, null, $now), 'old streak of 20 with no success is suspended');
$check(ap_fanout_host_suspended($now - $week, $now - $week, 20, $now - $week, $now), 'success and live answer exactly at the cutoff do not protect');
$check(!ap_fanout_probe_due($now - 86399, $now) && ap_fanout_probe_due(null, $now) && ap_fanout_probe_due($now - 86400, $now), 'one probe per day');

$row = ['last_success_at' => null, 'streak_started_at' => null, 'consecutive' => 0, 'last_live_at' => null];
foreach (['HTTP 401 Unauthorized', 'HTTP 403', 'HTTP 500', 'Remote host circuit is open'] as $error) {
    $row = ap_fanout_host_after_terminal_failure($row, $error, $now - $week);
    $check($row['consecutive'] === 0 && $row['suspended'] === false, $error . ' does not start a dead streak');
}
$check($row['last_live_at'] === $now - $week && $row['last_live_status'] === 500, 'the latest live status is kept');
$row = ap_fanout_host_after_terminal_failure($row, 'No HTTP response from inbox', $now - $week);
$check($row['consecutive'] === 1 && $row['streak_started_at'] === $now - $week, 'the first transport failure starts the streak');
for ($i = 0; $i < 19; $i++) {
    $row = ap_fanout_host_after_terminal_failure($row, 'Connection refused', $now);
}
$check($row['suspended'] === true && $row['consecutive'] === 20, 'the 20th old transport failure suspends');
$cleared = ap_fanout_host_after_success($row['last_live_at'], $now);
$check($cleared['suspended'] === false && $cleared['consecutive'] === 0 && $cleared['streak_started_at'] === null && $cleared['last_success_at'] === $now, 'success clears the streak');

$drain = ap_fanout_worker_outcome(true, false, false, false, 0);
$check($drain['try_http'] === false && $drain['status'] === 'failed' && $drain['record_terminal'] === false, 'a suspended queue row fails closed without a request or a new streak');
$probeFail = ap_fanout_worker_outcome(true, true, true, false, 0);
$check($probeFail['try_http'] === true && $probeFail['terminal'] === true && $probeFail['record_terminal'] === true, 'the daily probe tries once even when the circuit is open');
$retry = ap_fanout_worker_outcome(false, false, false, false, 3);
$check($retry['status'] === 'pending' && $retry['terminal'] === false, 'a healthy host is retried before 12');
$done = ap_fanout_worker_outcome(false, false, false, false, 11);
$check($done['status'] === 'failed' && $done['terminal'] === true, 'the 12th healthy failure is terminal');
$ok = ap_fanout_worker_outcome(false, false, false, true, 4);
$check($ok['status'] === 'succeeded' && $ok['record_terminal'] === false, 'success is not a terminal failure');

$history = [];
for ($i = 0; $i < 20; $i++) {
    $history[] = ['status' => 'failed', 'updated_at' => gmdate('c', $now - $week), 'last_error' => 'No HTTP response from inbox'];
}
$history[] = ['status' => 'succeeded', 'updated_at' => gmdate('c', $now - 86400), 'last_error' => null];
$recovered = ap_fanout_host_state_from_history($history);
$check($recovered['consecutive'] === 0 && $recovered['last_success_at'] === gmdate('c', $now - 86400), 'a later success ends the streak');
$onlyLive = [];
for ($i = 0; $i < 30; $i++) {
    $onlyLive[] = ['status' => 'failed', 'updated_at' => gmdate('c', $now - $week), 'last_error' => $i % 2 === 0 ? 'HTTP 401 signature' : 'HTTP 500'];
}
$liveState = ap_fanout_host_state_from_history($onlyLive);
$check($liveState['consecutive'] === 0 && $liveState['last_live_status'] === 500, 'threads.net and linux.pizza style failures do not suspend');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$GLOBALS['fanout_db'] = $pdo;
function ap_db(): PDO
{
    return $GLOBALS['fanout_db'];
}
function ap_deliver_signed_json(string $inbox, array $activity, string $keyId, string $priv, float $timeout): bool
{
    $GLOBALS['fanout_http'][] = $inbox;
    return true;
}
ap_fanout_host_ensure_sqlite($pdo);
ap_fanout_host_ensure_sqlite($pdo);
$check(ap_fanout_host_table_ready($pdo), 'sqlite health table exists');
$pdo->exec(
    'CREATE TABLE ap_fanout_delivery_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        activity_key TEXT NOT NULL,
        inbox_url TEXT NOT NULL,
        activity_json TEXT NOT NULL,
        key_id TEXT NOT NULL,
        priv_path TEXT NOT NULL,
        status TEXT NOT NULL,
        priority INTEGER NOT NULL DEFAULT 50,
        attempts INTEGER NOT NULL DEFAULT 0,
        next_attempt_at TEXT NOT NULL,
        claimed_at TEXT,
        last_error TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(activity_key, inbox_url)
    )'
);
$insert = $pdo->prepare('INSERT INTO ap_fanout_delivery_queue (activity_key,inbox_url,activity_json,key_id,priv_path,status,attempts,next_attempt_at,last_error,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$old = gmdate('c', $now - $week);
$recent = gmdate('c', $now - 86400);
$activity = '{"id":"https://mkultra.monster/users/cmdr_nova/activities/1","type":"Create","actor":"https://mkultra.monster/users/cmdr_nova"}';
for ($i = 0; $i < 20; $i++) {
    $insert->execute(['dead-' . $i, 'https://tube.baeddel.social/inbox', $activity, 'key', '/etc/mkultra/ap-inbox/private.pem', 'failed', 12, $old, 'No HTTP response from inbox', $old, $old]);
    $insert->execute(['pizza-' . $i, 'https://social.linux.pizza/inbox', $activity, 'key', '/etc/mkultra/ap-inbox/private.pem', 'failed', 12, $old, 'HTTP 401 signature', $old, $old]);
    $insert->execute(['threads-' . $i, 'https://threads.net/inbox', $activity, 'key', '/etc/mkultra/ap-inbox/private.pem', 'failed', 12, $old, 'HTTP 500', $old, $old]);
}
for ($i = 0; $i < 20; $i++) {
    $insert->execute(['mixed-' . $i, 'https://fright.tomb.download/inbox', $activity, 'key', '/etc/mkultra/ap-inbox/private.pem', 'failed', 12, $old, 'Connection refused', $old, $old]);
}
$insert->execute(['mixed-ok', 'https://fright.tomb.download/inbox', $activity, 'key', '/etc/mkultra/ap-inbox/private.pem', 'succeeded', 1, $recent, null, $recent, $recent]);
$insert->execute(['bsky-1', 'https://bsky.social/xrpc/com.atproto.repo.createRecord', '{"actor":"did:plc:abc"}', 'key', '/etc/mkultra/ap-inbox/private.pem', 'failed', 12, $old, 'No HTTP response from inbox', $old, $old]);
ap_fanout_host_cache_reset();
ap_fanout_host_backfill($pdo);
$check(ap_fanout_host_is_suspended_host('tube.baeddel.social'), 'tube.baeddel.social is suspended from the queue history');
$check(!ap_fanout_host_is_suspended_host('social.linux.pizza'), '401 signature failures stay unsuspended');
$check(!ap_fanout_host_is_suspended_host('threads.net'), 'HTTP 500 failures stay unsuspended');
$check(!ap_fanout_host_is_suspended_host('fright.tomb.download'), 'a later success clears a long failure streak');
$check(!ap_fanout_host_is_suspended_host('bsky.social'), 'bluesky is not suspended');
ap_fanout_host_backfill($pdo);
$check((int) $pdo->query("SELECT COUNT(*) FROM ap_fanout_host_health WHERE host = '*'")->fetchColumn() === 1, 'backfill runs once');

$GLOBALS['fanout_http'] = [];
$create = ['id' => 'https://mkultra.monster/users/cmdr_nova/activities/2', 'type' => 'Create', 'actor' => 'https://mkultra.monster/users/cmdr_nova'];
$check(ap_fanout_host_admit('https://social.linux.pizza/inbox', $create, 'key', '/tmp/priv') === 'send', 'a live rejecting host is still delivered');
$check(ap_fanout_deliver_or_hold('https://social.linux.pizza/inbox', $create, 'key', '/tmp/priv', 2.5) === true, 'hold delivers a healthy host');
$check($GLOBALS['fanout_http'] === ['https://social.linux.pizza/inbox'], 'one ordinary delivery');
$bsky = ['id' => 'at://did:plc:abc/app.bsky.feed.post/1', 'type' => 'Create', 'actor' => 'did:plc:abc'];
$check(ap_fanout_host_admit('https://tube.baeddel.social/inbox', $bsky, 'key', '/tmp/priv') === 'skip', 'a bluesky activity is not probed');
$check(ap_fanout_host_admit('https://tube.baeddel.social/inbox', $create, 'key', '/tmp/priv') === 'probe', 'the first suspended delivery is the daily probe');
$check(ap_fanout_host_admit('https://tube.baeddel.social/users/other/inbox', $create, 'key', '/tmp/priv') === 'skip', 'later deliveries to that host are skipped');
$probeRows = (int) $pdo->query("SELECT COUNT(*) FROM ap_fanout_delivery_queue WHERE inbox_url LIKE 'https://tube.baeddel.social/%' AND last_error = 'host-probe' AND status = 'pending'")->fetchColumn();
$check($probeRows === 1, 'exactly one probe row is queued');
$check(ap_fanout_deliver_or_hold('https://tube.baeddel.social/inbox', $create, 'key', '/tmp/priv', 2.5) === false, 'sync fan-out does not call a suspended host');
$check($GLOBALS['fanout_http'] === ['https://social.linux.pizza/inbox'], 'no extra outbound request');
$check(ap_fanout_host_requeue_probe($pdo) === false, 'the same day does not probe again');
$GLOBALS['ap_fanout_host_now'] = $now + 86400;
ap_fanout_host_cache_reset();
$check(ap_fanout_host_requeue_probe($pdo) === true, 'the next day requeues one stored probe');
$check(ap_fanout_host_requeue_probe($pdo) === false, 'a second requeue the same day does nothing');

$GLOBALS['ap_fanout_host_now'] = $now;
ap_fanout_host_cache_reset();
ap_fanout_host_note_inbound('tube.baeddel.social');
ap_fanout_host_cache_reset();
$check(!ap_fanout_host_is_suspended_host('tube.baeddel.social'), 'inbound activity lifts the suspension');
$check(ap_fanout_host_admit('https://tube.baeddel.social/inbox', $create, 'key', '/tmp/priv') === 'send', 'a recovered host is delivered again');

for ($i = 0; $i < 20; $i++) {
    ap_fanout_host_note_terminal('micro.charlesbeadle.tech', 'No HTTP response from inbox');
}
$stale = gmdate('c', $now - $week);
$pdo->prepare('UPDATE ap_fanout_host_health SET streak_started_at = ? WHERE host = ?')->execute([$stale, 'micro.charlesbeadle.tech']);
ap_fanout_host_cache_reset();
$check(ap_fanout_host_is_suspended_host('micro.charlesbeadle.tech'), 'twenty terminal transport failures over the window suspend');
ap_fanout_host_note_terminal('micro.charlesbeadle.tech', 'HTTP 401');
ap_fanout_host_cache_reset();
$check(!ap_fanout_host_is_suspended_host('micro.charlesbeadle.tech'), 'a fresh 401 lifts the dead verdict');

$counts = ap_fanout_terminal_failure_counts($pdo);
$check($counts['suspended'] === 0, '401 and 500 rows are not suspended-host failures');
$check($counts['healthy'] >= 40, 'live-host terminal failures stay in the healthy count');
$pdo->prepare('UPDATE ap_fanout_host_health SET last_live_at = ?, streak_started_at = ?, consecutive_terminal_failures = 20 WHERE host = ?')
    ->execute([$stale, $stale, 'micro.charlesbeadle.tech']);
ap_fanout_host_cache_reset();
$counts = ap_fanout_terminal_failure_counts($pdo);
$check($counts['suspended'] === 0 && $counts['healthy'] > 0, 'a host with no failed rows of its own does not move other hosts');
$insert->execute(['micro-dead', 'https://micro.charlesbeadle.tech/inbox', $activity, 'key', '/etc/mkultra/ap-inbox/private.pem', 'failed', 12, $old, 'No HTTP response from inbox', $old, $old]);
ap_fanout_host_cache_reset();
$counts = ap_fanout_terminal_failure_counts($pdo);
$check($counts['suspended'] === 1, 'the suspended host failure is counted apart');
$check($counts['healthy'] >= 40, 'healthy-host failures remain');
$check(!ap_fanout_counts_as_dead_failure('Bluesky activity is not delivered'), 'a refused bluesky activity is not a dead host');

for ($i = 0; $i < 20; $i++) {
    ap_fanout_host_note_terminal('dead.example', 'Connection refused');
}
$pdo->prepare('UPDATE ap_fanout_host_health SET streak_started_at = ? WHERE host = ?')->execute([$stale, 'dead.example']);
$insert->execute(['bsky-on-dead', 'https://dead.example/inbox', '{"actor":"did:plc:abc","type":"Create"}', 'key', '/etc/mkultra/ap-inbox/private.pem', 'failed', 12, $old, 'No HTTP response from inbox', $old, $old]);
ap_fanout_host_cache_reset();
$check(ap_fanout_host_admit('https://dead.example/inbox', $create, 'key', '/tmp/priv') === 'probe', 'a suspended host still gets one ActivityPub probe');
$probeJson = (string) $pdo->query("SELECT activity_json FROM ap_fanout_delivery_queue WHERE inbox_url = 'https://dead.example/inbox' AND status = 'pending'")->fetchColumn();
$check($probeJson !== '' && !str_contains($probeJson, 'did:plc'), 'the probe payload is not a bluesky activity');
$check((int) $pdo->query("SELECT COUNT(*) FROM ap_fanout_delivery_queue WHERE inbox_url = 'https://dead.example/inbox' AND last_error = 'host-probe'")->fetchColumn() === 1, 'the bluesky row is not the probe');

$script = (string) file_get_contents(dirname(__DIR__, 2) . '/deploy/vaak-integrity-check.sh');
$check(str_contains($script, 'failed_fanout_healthy') && str_contains($script, 'failed_fanout_suspended'), 'integrity splits the fan-out counts');
$check(str_contains($script, 'terminal fan-out failures on live hosts require review'), 'the warning names live hosts');
$check(!preg_match('/failed_fanout_suspended && .*warn/', $script), 'suspended failures do not warn');
$check(str_contains($script, 'VAAK_FANOUT_DEAD_DAYS') && str_contains($script, 'VAAK_FANOUT_DEAD_FAILURES'), 'integrity uses the same knobs');
$check(str_contains($script, 'last_live_at') && str_contains($script, 'last_success_at') && str_contains($script, 'streak_started_at'), 'integrity uses the health record');
$bash = shell_exec('bash -n ' . escapeshellarg(dirname(__DIR__, 2) . '/deploy/vaak-integrity-check.sh') . ' 2>&1');
$check($bash === null || $bash === '', 'integrity script parses');

require_once dirname(__DIR__, 2) . '/deploy/remote/migrate.php';
$migrationNames = array_map('basename', vaak_migrate_deploy_sql_files(dirname(__DIR__)));
$check(in_array('20261027_fanout_host_health_postgres.sql', $migrationNames, true), 'deploy applies the health table');
$sqlPath = dirname(__DIR__) . '/migrations/20261027_fanout_host_health_postgres.sql';
$sql = (string) file_get_contents($sqlPath);
$check(!str_contains($sql, 'password') && !str_contains($sql, 'postgres://') && !str_contains($sql, 'BEGIN '), 'the migration has no credentials');
$plan = vaak_migrate_additive_plan(vaak_migrate_sql_statements($sql), basename($sqlPath));
$check($plan['tables'] === ['ap_fanout_host_health'], 'the migration creates only the health table');
$joined = implode("\n", $plan['statements']);
$check(str_contains($joined, 'GRANT SELECT, INSERT, UPDATE, DELETE ON ap_fanout_host_health TO "www-data"'), 'the app role can write host health');
$check(!preg_match('/\b(ALTER|DROP|TRUNCATE|INSERT\s+INTO|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', $joined), 'the migration does not change an existing table');

$worker = (string) file_get_contents(dirname(__DIR__) . '/ap-fanout-delivery-worker.php');
$check(str_contains($worker, 'ap_fanout_worker_outcome') && str_contains($worker, 'ap_fanout_host_note_terminal') && str_contains($worker, 'ap_fanout_host_requeue_probe'), 'the worker uses the suspension helpers');
$inbox = (string) file_get_contents(dirname(__DIR__) . '/ap-inbox.php');
$check(str_contains($inbox, 'ap_fanout_host_note_inbound') && str_contains($inbox, 'ap_fanout_host_admit') && str_contains($inbox, 'ap_fanout_deliver_or_hold'), 'queuing and inbound recovery call the helpers');
$rust = (string) file_get_contents(dirname(__DIR__, 2) . '/rust/vaak-worker/src/fanout_host.rs');
$check(str_contains($rust, 'fn suspended(') && str_contains($rust, 'fn dead_days(') && str_contains($rust, 'last_live'), 'Rust keeps the same suspension rule');

if ($failures !== []) {
    fwrite(STDERR, "fanout-dead-host failed\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "fanout-dead-host ok checks={$checks}\n";
