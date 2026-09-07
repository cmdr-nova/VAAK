<?php
/**
 * Mutual-unfollow: unfollow remotes who unfollowed cmdr_nova (Undo Follow recorded).
 *
 * HARD CONSTRAINT: operates ONLY on https://mkultra.monster/users/cmdr_nova.
 *
 * Usage:
 *   php ap-mutual-unfollow.php           # dry-run
 *   php ap-mutual-unfollow.php --apply
 *   php ap-mutual-unfollow.php --apply --limit=5 --min-age-hours=48
 *
 * Default: dry-run (print candidates, unfollow nothing).
 * Require --apply to actually unfollow. Rate-limit: max 10/run unless --limit=N.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "CLI only\n";
    exit(1);
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once __DIR__ . '/ap-inbox.php';

/** Hardcoded owner — refuse any other actor. */
const MU_OWNER_ACTOR = 'https://mkultra.monster/users/cmdr_nova';
const MU_OWNER_KEY = 'cmdr_nova';

function mu_log(string $msg): void
{
    echo '[' . gmdate('c') . '] ' . $msg . "\n";
}

function mu_usage(): void
{
    echo <<<TXT
php ap-mutual-unfollow.php           # dry-run
php ap-mutual-unfollow.php --apply
php ap-mutual-unfollow.php --apply --limit=5 --min-age-hours=48

TXT;
}

function mu_norm(string $id): string
{
    return rtrim(trim($id), '/');
}

function mu_is_bridgy(string $actorId): bool
{
    $host = parse_url($actorId, PHP_URL_HOST);
    $host = is_string($host) ? strtolower($host) : '';
    if ($host === '') {
        return str_contains(strtolower($actorId), 'brid.gy');
    }
    return $host === 'brid.gy' || str_ends_with($host, 'brid.gy');
}

$apply = false;
$limit = 10;
$minAgeHours = 24;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        mu_usage();
        exit(0);
    }
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(100, (int) $m[1]));
        continue;
    }
    if (preg_match('/^--min-age-hours=(\d+)$/', $arg, $m)) {
        $minAgeHours = max(0, min(720, (int) $m[1]));
        continue;
    }
    mu_log('unknown arg: ' . $arg);
    mu_usage();
    exit(1);
}

$owner = mu_norm(MU_OWNER_ACTOR);
if ($owner !== 'https://mkultra.monster/users/cmdr_nova') {
    mu_log('REFUSED: owner must be cmdr_nova (hardcoded)');
    exit(2);
}

ap_request_actor_set(MU_OWNER_KEY);
$localId = mu_norm(ap_local_actor_id());
if ($localId !== $owner) {
    mu_log('REFUSED: ap_local_actor_id=' . $localId . ' != ' . $owner);
    exit(2);
}

mu_log('mutual-unfollow start owner=' . $owner
    . ' mode=' . ($apply ? 'APPLY' : 'dry-run')
    . ' limit=' . $limit
    . ' min_age_hours=' . $minAgeHours);

$cutoff = gmdate('c', time() - ($minAgeHours * 3600));

// 1) Actors we follow
$followingSet = [];
foreach (ap_following_list($owner) as $row) {
    $aid = mu_norm((string) ($row['actor_id'] ?? ''));
    if ($aid !== '' && str_starts_with($aid, 'https://')) {
        $followingSet[$aid] = true;
    }
}

// 2) Current followers (must NOT still follow us)
$followerSet = [];
foreach (ap_followers_list($owner) as $row) {
    $aid = mu_norm((string) ($row['actor_id'] ?? ''));
    if ($aid !== '') {
        $followerSet[$aid] = true;
    }
}

// 3) Undo(Follow) events proving they unfollowed us
$db = ap_db();
$st = $db->prepare(
    "SELECT id, actor_id, target_actor, created_at
       FROM events
      WHERE type = 'Undo'
        AND action_taken = 'local_unfollow'
        AND created_at <= ?
      ORDER BY created_at ASC"
);
$st->execute([$cutoff]);
$undoRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/** @var array<string, array{actor_id:string,undo_at:string,event_id:int}> $candidates */
$candidates = [];
foreach ($undoRows as $row) {
    $actor = mu_norm((string) ($row['actor_id'] ?? ''));
    if ($actor === '' || !isset($followingSet[$actor])) {
        continue;
    }
    if (isset($followerSet[$actor])) {
        continue; // still following us — never auto-unfollow
    }
    $target = mu_norm((string) ($row['target_actor'] ?? ''));
    // target should be cmdr (or empty/legacy); skip if clearly another local actor
    if ($target !== '' && $target !== $owner && !str_starts_with($target, $owner)) {
        if (preg_match('#^https://mkultra\.monster/users/#', $target)) {
            continue;
        }
    }
    if (mu_is_bridgy($actor)) {
        continue;
    }
    if (isset($candidates[$actor])) {
        continue; // keep oldest Undo
    }
    $candidates[$actor] = [
        'actor_id' => $actor,
        'undo_at' => (string) ($row['created_at'] ?? ''),
        'event_id' => (int) ($row['id'] ?? 0),
    ];
}

$total = count($candidates);
$bridgySkipped = 0;
foreach ($undoRows as $row) {
    $actor = mu_norm((string) ($row['actor_id'] ?? ''));
    if ($actor !== '' && isset($followingSet[$actor]) && !isset($followerSet[$actor]) && mu_is_bridgy($actor)) {
        $bridgySkipped++;
    }
}
if ($bridgySkipped > 0) {
    mu_log('skipped bridgy undo-matches=' . $bridgySkipped);
}
mu_log('candidates=' . $total);

$unfollowed = 0;
$skipped = 0;
$errors = 0;
$shown = 0;

foreach ($candidates as $aid => $info) {
    if ($shown >= $limit) {
        mu_log('limit reached (' . $limit . '); remaining=' . ($total - $shown));
        break;
    }
    $shown++;

    // Race: still following?
    if (!ap_actor_is_followed($aid, $owner)) {
        mu_log('skip not-following-anymore actor=' . $aid);
        $skipped++;
        continue;
    }

    // Race: never unfollow if now in followers again
    $stillFollower = false;
    foreach (ap_followers_list($owner) as $fl) {
        if (mu_norm((string) ($fl['actor_id'] ?? '')) === $aid) {
            $stillFollower = true;
            break;
        }
    }
    if ($stillFollower) {
        mu_log('skip still-follower (race) actor=' . $aid);
        $skipped++;
        continue;
    }

    if (mu_is_bridgy($aid)) {
        mu_log('skip bridgy actor=' . $aid);
        $skipped++;
        continue;
    }

    mu_log(
        ($apply ? 'UNFOLLOW' : 'candidate')
        . ' actor=' . $aid
        . ' undo_at=' . $info['undo_at']
        . ' event_id=' . $info['event_id']
    );

    if (!$apply) {
        continue;
    }

    $res = ap_unfollow_remote_actor($aid);
    if (!empty($res['ok'])) {
        $unfollowed++;
        mu_log('ok unfollowed actor=' . $aid
            . (!empty($res['already']) ? ' (already)' : ''));
    } else {
        $errors++;
        mu_log('ERROR unfollow actor=' . $aid
            . ' err=' . (string) ($res['error'] ?? 'unknown'));
    }
}

mu_log(
    'done mode=' . ($apply ? 'APPLY' : 'dry-run')
    . ' shown=' . $shown
    . ' unfollowed=' . $unfollowed
    . ' skipped=' . $skipped
    . ' errors=' . $errors
    . ' total_candidates=' . $total
);

exit($errors > 0 ? 1 : 0);
