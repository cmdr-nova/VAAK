<?php
/**
 * Rust like routing (cutover F5) against Postgres, Redis and a worker started
 * with VAAK_INBOX_RUST_ACCEPT=1 VAAK_INBOX_ROUTE_LIKE=1.
 *
 * Each case is set up twice with fresh ids: once sent to the worker, which
 * routes it itself, once through PHP (ap_inbox_spool_process, as the drain
 * would). Results, the like's events row and its notification row must end
 * the same, and the Rust side must clear the owner's notification caches and
 * hand the phone push to PHP (vaak:queue:inbox_webpush).
 */
declare(strict_types=1);

putenv('VAAK_INBOX_RUST_ACCEPT=1');
define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__) . '/ap-inbox.php';

$rust = rtrim((string) getenv('VAAK_INBOX_RUST_URL'), '/');
if ($rust === '') {
    fwrite(STDERR, "inbox-route-like: set VAAK_INBOX_RUST_URL\n");
    exit(2);
}
$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void {
    if (!$ok) {
        fwrite(STDERR, "inbox-route-like: FAIL {$why}\n");
        exit(1);
    }
    $n++;
};

function rl_post(string $url, string $path, string $body, string $keyId, string $priv): int
{
    $date = gmdate('D, d M Y H:i:s') . ' GMT';
    $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    $signing = "(request-target): post {$path}\nhost: mkultra.monster\ndate: {$date}\ndigest: {$digest}";
    openssl_sign($signing, $sig, $priv, OPENSSL_ALGO_SHA256);
    $header = sprintf('keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="%s"', $keyId, base64_encode($sig));
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
            'Host: mkultra.monster', 'Date: ' . $date, 'Digest: ' . $digest, 'Signature: ' . $header,
            'Content-Type: application/activity+json', 'X-Forwarded-For: 203.0.113.11',
        ]),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 15,
    ]]);
    @file_get_contents($url . $path, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    return $status;
}

$db = ap_db();
$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($res, $priv);
$pem = openssl_pkey_get_details($res)['key'];

$kai = 'https://like.test/users/kai';
$kaiKey = $kai . '#main-key';
ap_inbox_rust_key_mirror($kaiKey, $pem);
$redis = ap_redis_client();
$queue = ap_redis_client('queue');
$redis->del('vaak:inbox_rust:stats:v1');
$queue->del('vaak:queue:inbox_webpush');

$db->exec("INSERT INTO ap_users (username, email, password_hash, actor_key, actor_id, is_admin, created_at, updated_at)
           VALUES ('like_owner', 'like-owner@vaak.test', 'unused', 'like_owner', 'https://mkultra.monster/users/like_owner', 0,
                   '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");
$likeOwner = (int) $db->query("SELECT id FROM ap_users WHERE username = 'like_owner'")->fetchColumn();
$cmdrNote = static fn(string $tag): string => LOCAL_ACTOR . '/notes/like-' . $tag;
$ownerNote = static fn(string $tag): string => 'https://mkultra.monster/users/like_owner/notes/like-' . $tag;

$run = static function (string $side, string $path, string $body) use ($rust, $kaiKey, $priv, $db, $check): array {
    if ($side === 'rust') {
        $check(rl_post($rust, $path, $body, $kaiKey, $priv) === 202, 'worker answers 202');
        $st = $db->prepare('SELECT state, result FROM ap_inbox_spool WHERE body = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$body]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [(string) ($row['state'] ?? ''), (string) ($row['result'] ?? '')];
    }
    $out = ap_inbox_spool_process(['id' => 0, 'client_ip' => '', 'key_id' => $kaiKey, 'request_path' => $path, 'body' => $body]);
    return [(string) $out['state'], (string) $out['result']];
};
// The two sides' bodies differ only by the side name inside the ids.
$event = static function (string $type, string $objectId, string $side, string $body) use ($db): ?array {
    $st = $db->prepare('SELECT type, actor_id, object_id, target_actor, host, bytes, action_taken, summary, media_urls, in_reply_to,
                               spoiler_text, sensitive, visibility FROM events WHERE type = ? AND object_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$type, $objectId]);
    $ev = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($ev)) return null;
    $ev['object_id'] = str_replace($side, '', (string) $ev['object_id']);
    $ev['bytes'] = (int) $ev['bytes'] - substr_count($body, $side) * strlen($side);
    return $ev;
};
$mention = static function (string $activityId, string $side) use ($db): ?array {
    $st = $db->prepare('SELECT owner_user_id, owner_actor_id, activity_id, object_id, actor_id, type, content, in_reply_to, deleted_at,
                               media_urls, activity_type, spoiler_text, sensitive, ask_actor, ask_question, ask_answer
                        FROM mentions WHERE activity_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$activityId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($m)) return null;
    foreach (['activity_id', 'object_id'] as $k) {
        $m[$k] = str_replace($side, '', (string) $m[$k]);
    }
    // The stable key hashes the side's note id; compare its shape.
    $m['object_id'] = preg_replace('/#like-[0-9a-f]{12}$/', '#like-HASH', $m['object_id']);
    return $m;
};

$cases = [
    // [label, inbox path, note maker, extra activity fields, owner id or 0 (no notification)]
    ['shared-inbox like of a cmdr_nova note', '/inbox', $cmdrNote, [], 1],
    ['personal-inbox like', '/users/like_owner/inbox', $ownerNote, [], $likeOwner],
    ['personal-inbox emoji reaction without content', '/users/like_owner/inbox', $ownerNote, ['type' => 'EmojiReact'], $likeOwner],
    ['public like addressed to its author', '/inbox', $ownerNote,
        ['to' => ['https://www.w3.org/ns/activitystreams#Public'], 'cc' => ['https://mkultra.monster/users/like_owner']], $likeOwner],
    ['shared-inbox like of another account\'s note', '/inbox', $ownerNote, [], 0],
];
foreach ($cases as $i => [$label, $path, $noteOf, $extra, $owner]) {
    $out = [];
    foreach (['rust', 'php'] as $side) {
        $note = $noteOf($side . $i);
        $activityId = $kai . '#likes/' . $side . $i;
        $doc = array_merge(['id' => $activityId, 'type' => 'Like', 'actor' => $kai, 'object' => $note], $extra);
        $body = json_encode($doc, JSON_UNESCAPED_SLASHES);
        if ($owner > 0) {
            $redis->set("vaak:notifications:v4:{$owner}:smoke-{$side}", 'x');
            $redis->set("vaak:notifications:v1:unread:{$owner}:smoke-{$side}", '3');
        }
        $epoch = $owner > 0 ? (int) $redis->get("vaak:notifications:epoch:v1:{$owner}") : 0;
        $queued = (int) $queue->lLen('vaak:queue:inbox_webpush');
        [$state, $result] = $run($side, $path, $body);
        $out[$side] = [
            'state' => $state,
            'result' => $result,
            'event' => $event($doc['type'], $note, $side, $body),
            'mention' => $mention($activityId, $side),
            'caches_gone' => $owner > 0 && !$redis->exists("vaak:notifications:v4:{$owner}:smoke-{$side}")
                && !$redis->exists("vaak:notifications:v1:unread:{$owner}:smoke-{$side}"),
            'epoch_bumped' => $owner > 0 && (int) $redis->get("vaak:notifications:epoch:v1:{$owner}") > $epoch,
            'push_jobs' => (int) $queue->lLen('vaak:queue:inbox_webpush') - $queued,
        ];
    }
    $dump = "\n" . json_encode($out, JSON_UNESCAPED_SLASHES);
    $check($out['rust']['state'] === 'done' && $out['rust']['result'] === 'local_observe', "{$label}: Rust routes it itself{$dump}");
    $check($out['php']['result'] === 'local_observe', "{$label}: PHP returns local_observe{$dump}");
    $check($out['rust']['event'] !== null && $out['rust']['event'] == $out['php']['event'], "{$label}: events rows match{$dump}");
    $check($out['rust']['mention'] == $out['php']['mention'], "{$label}: notification rows match{$dump}");
    if ($owner > 0) {
        $check(is_array($out['rust']['mention']) && (int) $out['rust']['mention']['owner_user_id'] === $owner, "{$label}: the note's owner is notified{$dump}");
        $check($out['rust']['caches_gone'] && $out['php']['caches_gone'], "{$label}: both clear the owner's list and unread caches");
        $check($out['rust']['epoch_bumped'] && $out['php']['epoch_bumped'], "{$label}: both bump the owner's notification epoch");
        $check($out['rust']['push_jobs'] === 1, "{$label}: Rust hands the phone push to PHP");
    } else {
        $check($out['rust']['mention'] === null, "{$label}: nobody is notified");
        $check($out['rust']['push_jobs'] === 0, "{$label}: no phone push");
    }
}

// A re-like after an Undo revives the same notification row.
$note = $cmdrNote('relike');
$stable = ap_mention_interaction_object_id($note, $kai, 'like');
ap_mention_store(['owner_user_id' => 1, 'owner_actor_id' => LOCAL_ACTOR, 'actor_id' => $kai, 'type' => 'Like', 'activity_type' => 'Like',
    'object_id' => $stable, 'activity_id' => $kai . '#likes/old']);
$db->prepare("UPDATE mentions SET deleted_at = '2026-01-01T00:00:00+00:00', created_at = '2026-01-01T00:00:00+00:00' WHERE object_id = ?")->execute([$stable]);
$body = json_encode(['id' => $kai . '#likes/relike', 'type' => 'Like', 'actor' => $kai, 'object' => $note], JSON_UNESCAPED_SLASHES);
[$state] = $run('rust', '/inbox', $body);
$st = $db->prepare('SELECT count(*), max(deleted_at), max(activity_id), max(created_at) FROM mentions WHERE owner_user_id = 1 AND object_id = ?');
$st->execute([$stable]);
[$rows, $deleted, $act, $created] = $st->fetch(PDO::FETCH_NUM);
$check($state === 'done' && (int) $rows === 1 && $deleted === null && $act === $kai . '#likes/relike' && $created > '2026-01-02',
    're-like: one live row, new activity id and time');

// Shapes Rust leaves to PHP: spooled for the drain.
$db->prepare("INSERT INTO outbox_notes (id, create_id, published, content, raw_create_json, visibility) VALUES (?, ?, ?, '', '{}', 'private')")
    ->execute([$cmdrNote('private'), $cmdrNote('private') . '/activity', gmdate('c')]);
$deferred = [
    'content' => ['id' => $kai . '#react/1', 'type' => 'EmojiReact', 'actor' => $kai, 'object' => $cmdrNote('react'), 'content' => ':blobcat:'],
    'embedded' => ['id' => $kai . '#likes/emb', 'type' => 'Like', 'actor' => $kai, 'object' => ['id' => $cmdrNote('emb'), 'type' => 'Note']],
    'private_object' => ['id' => $kai . '#likes/priv', 'type' => 'Like', 'actor' => $kai, 'object' => $cmdrNote('private')],
];
foreach ($deferred as $why => $doc) {
    $body = json_encode($doc, JSON_UNESCAPED_SLASHES);
    [$state] = $run('rust', '/inbox', $body);
    $check($state === 'pending', "{$why}: left to the PHP drain (state={$state})");
}

// The drain sends the queued pushes.
$jobs = (int) $queue->lLen('vaak:queue:inbox_webpush');
$check($jobs === 5, "one push job per notification Rust stored (got {$jobs})");
$job = json_decode((string) $queue->lIndex('vaak:queue:inbox_webpush', -1), true);
$check(is_array($job) && ($job['type'] ?? '') === 'favourite' && ($job['actor'] ?? '') === $kai && (int) ($job['owner_user_id'] ?? 0) === 1
    && str_contains((string) ($job['object_id'] ?? ''), '#like-'), 'the push job names type, liker, owner and notification row');
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/inbox-spool-drain.php') . ' --once 2>&1';
shell_exec('VAAK_FPM_POOL_CONF=/nonexistent ' . $cmd);
$check((int) $queue->lLen('vaak:queue:inbox_webpush') === 0, 'the drain sends every queued push');

$stats = $redis->hGetAll('vaak:inbox_rust:stats:v1');
$check((int) ($stats['routed_local_observe'] ?? 0) === 6 && (int) ($stats['route_defer_content'] ?? 0) === 1
    && (int) ($stats['route_defer_embedded'] ?? 0) === 1 && (int) ($stats['route_defer_private_object'] ?? 0) === 1,
    'stats count what Rust routed and deferred ' . json_encode($stats));

echo "inbox-route-like: {$n} checks passed\n";
