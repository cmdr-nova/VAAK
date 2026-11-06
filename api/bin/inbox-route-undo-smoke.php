<?php
/**
 * Rust Undo and mention-Delete routing (cutover F4) against Postgres, Redis
 * and a worker started with VAAK_INBOX_RUST_ACCEPT=1 VAAK_INBOX_ROUTE_DELETE=1
 * VAAK_INBOX_ROUTE_DELETE_MENTIONS=1 VAAK_INBOX_ROUTE_UNDO=1.
 *
 * Each case is set up twice with fresh ids: once sent to the worker, which
 * routes it itself, once through PHP (ap_inbox_spool_process, as the drain
 * would). Results, the activity's events row, the mention rows and the timeline
 * events it touches must end the same. The Rust side must also clear the
 * notification caches PHP clears.
 */
declare(strict_types=1);

putenv('VAAK_INBOX_RUST_ACCEPT=1');
define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__) . '/ap-inbox.php';

$rust = rtrim((string) getenv('VAAK_INBOX_RUST_URL'), '/');
if ($rust === '') {
    fwrite(STDERR, "inbox-route-undo: set VAAK_INBOX_RUST_URL\n");
    exit(2);
}
$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void {
    if (!$ok) {
        fwrite(STDERR, "inbox-route-undo: FAIL {$why}\n");
        exit(1);
    }
    $n++;
};

function ru_post(string $url, string $body, string $keyId, string $priv): int
{
    $date = gmdate('D, d M Y H:i:s') . ' GMT';
    $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    $signing = "(request-target): post /inbox\nhost: mkultra.monster\ndate: {$date}\ndigest: {$digest}";
    openssl_sign($signing, $sig, $priv, OPENSSL_ALGO_SHA256);
    $header = sprintf('keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="%s"', $keyId, base64_encode($sig));
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
            'Host: mkultra.monster', 'Date: ' . $date, 'Digest: ' . $digest, 'Signature: ' . $header,
            'Content-Type: application/activity+json', 'X-Forwarded-For: 203.0.113.10',
        ]),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 15,
    ]]);
    @file_get_contents($url . '/inbox', false, $ctx);
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

$yuki = 'https://undo.test/users/yuki';
$yukiKey = $yuki . '#main-key';
ap_inbox_rust_key_mirror($yukiKey, $pem);
$redis = ap_redis_client();
$redis->del('vaak:inbox_rust:stats:v1');
$note = static fn(string $tag): string => LOCAL_ACTOR . '/notes/undo-' . $tag;

$mentionState = static function (string $where, array $params) use ($db): string {
    $st = $db->prepare('SELECT count(*) FILTER (WHERE deleted_at IS NULL) || \'/\' || count(*) FROM mentions WHERE ' . $where);
    $st->execute($params);
    return (string) $st->fetchColumn();
};
$activityEvent = static function (string $type, string $objectId) use ($db): ?array {
    $st = $db->prepare('SELECT type, actor_id, object_id, target_actor, host, bytes, action_taken, summary, media_urls, in_reply_to,
                               spoiler_text, sensitive, visibility FROM events WHERE type = ? AND object_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$type, $objectId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : null;
};
$run = static function (string $side, string $body) use ($rust, $yukiKey, $priv, $db, $check): string {
    if ($side === 'rust') {
        $check(ru_post($rust, $body, $yukiKey, $priv) === 202, 'worker answers 202');
        $st = $db->prepare('SELECT state, result FROM ap_inbox_spool WHERE body = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$body]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $check(($row['state'] ?? '') === 'done', 'Rust writes the spool row done (state=' . ($row['state'] ?? '?') . ')');
        return (string) ($row['result'] ?? '');
    }
    $out = ap_inbox_spool_process(['id' => 0, 'client_ip' => '', 'key_id' => $yukiKey, 'request_path' => '/inbox', 'body' => $body]);
    return (string) $out['result'];
};
// The two sides' bodies differ only by the side name inside the ids.
$normalize = static function (?array $ev, string $side, string $body): ?array {
    if (!is_array($ev)) return null;
    $ev['object_id'] = str_replace($side, '', (string) $ev['object_id']);
    $ev['bytes'] = (int) $ev['bytes'] - substr_count($body, $side) * strlen($side);
    return $ev;
};

// 1. Undo Like of a local note that left a like notification.
$out = [];
foreach (['rust', 'php'] as $side) {
    $target = $note('like-' . $side);
    $likeId = $yuki . '#likes/' . $side;
    ap_mention_store(['owner_user_id' => 1, 'owner_actor_id' => LOCAL_ACTOR, 'actor_id' => $yuki, 'type' => 'Like',
        'object_id' => ap_mention_interaction_object_id($target, $yuki, 'like'), 'activity_id' => $likeId, 'content' => 'liked']);
    $redis->set('vaak:notifications:v2:1:smoke-' . $side, 'x');
    $redis->set('vaak:notifications:v1:unread:1:smoke-' . $side, '3');
    $body = json_encode(['id' => $yuki . '#undo/like-' . $side, 'type' => 'Undo', 'actor' => $yuki,
        'object' => ['id' => $likeId, 'type' => 'Like', 'actor' => $yuki, 'object' => $target]], JSON_UNESCAPED_SLASHES);
    $epoch = (int) $redis->get('vaak:notifications:epoch:v1:server');
    $result = $run($side, $body);
    $ev = $normalize($activityEvent('Undo', $likeId), $side, $body);
    $out[$side] = [
        'result' => $result,
        'mention' => $mentionState('activity_id = ?', [$likeId]),
        'event' => $ev,
        'caches_gone' => !$redis->exists('vaak:notifications:v2:1:smoke-' . $side) && !$redis->exists('vaak:notifications:v1:unread:1:smoke-' . $side),
        'epoch_bumped' => (int) $redis->get('vaak:notifications:epoch:v1:server') > $epoch,
    ];
}
$check($out['rust']['result'] === 'local_undo_like' && $out['php']['result'] === 'local_undo_like', 'Undo Like: both return local_undo_like');
$check($out['rust']['mention'] === '0/1' && $out['php']['mention'] === '0/1', 'Undo Like: the like notification is soft-deleted on both sides');
$check($out['rust']['event'] == $out['php']['event'] && $out['rust']['event'] !== null, "Undo Like: events rows match\n" . json_encode($out, JSON_UNESCAPED_SLASHES));
$check($out['rust']['caches_gone'] && $out['php']['caches_gone'], 'Undo Like: both clear the list and unread caches');
$check($out['rust']['epoch_bumped'] && $out['php']['epoch_bumped'], 'Undo Like: both bump the notification epoch');

// 2. Undo Announce: the firehose Announce row is marked deleted.
$out = [];
foreach (['rust', 'php'] as $side) {
    $target = 'https://elsewhere.test/notes/boosted-' . $side;
    $announceId = $yuki . '/announces/' . $side;
    $db->prepare("INSERT INTO events (created_at, type, actor_id, object_id, action_taken) VALUES (?, 'Announce', ?, ?, 'log')")
        ->execute([gmdate('c'), $yuki, $target]);
    $body = json_encode(['id' => $yuki . '#undo/announce-' . $side, 'type' => 'Undo', 'actor' => $yuki,
        'object' => ['id' => $announceId, 'type' => 'Announce', 'actor' => $yuki, 'object' => $target]], JSON_UNESCAPED_SLASHES);
    $result = $run($side, $body);
    $st = $db->prepare("SELECT action_taken FROM events WHERE type = 'Announce' AND object_id = ?");
    $st->execute([$target]);
    $ev = $normalize($activityEvent('Undo', $announceId), $side, $body);
    $out[$side] = ['result' => $result, 'announce' => (string) $st->fetchColumn(), 'event' => $ev];
}
$check($out['rust']['result'] === 'local_undo_reblog' && $out['php']['result'] === 'local_undo_reblog', 'Undo Announce: both return local_undo_reblog');
$check($out['rust']['announce'] === 'deleted' && $out['php']['announce'] === 'deleted', 'Undo Announce: the boost row is marked deleted on both sides');
$check($out['rust']['event'] == $out['php']['event'] && $out['rust']['event'] !== null, "Undo Announce: events rows match\n" . json_encode($out, JSON_UNESCAPED_SLASHES));

// 3. Undo Like naming another actor inside: nothing is undone, result kept.
$target = $note('foreign');
$likeId = 'https://undo.test/users/someone-else#likes/1';
ap_mention_store(['owner_user_id' => 1, 'owner_actor_id' => LOCAL_ACTOR, 'actor_id' => 'https://undo.test/users/someone-else', 'type' => 'Like',
    'object_id' => ap_mention_interaction_object_id($target, 'https://undo.test/users/someone-else', 'like'), 'activity_id' => $likeId, 'content' => 'liked']);
$body = json_encode(['id' => $yuki . '#undo/foreign', 'type' => 'Undo', 'actor' => $yuki,
    'object' => ['id' => $likeId, 'type' => 'Like', 'actor' => 'https://undo.test/users/someone-else', 'object' => $target]], JSON_UNESCAPED_SLASHES);
$check($run('rust', $body) === 'local_undo_like', 'foreign inner actor: still local_undo_like');
$check($mentionState('activity_id = ?', [$likeId]) === '1/1', 'foreign inner actor: the other account\'s like stays');

// 4. Delete of a remote post that mentioned us: local_mention_delete.
$out = [];
foreach (['rust', 'php'] as $side) {
    $post = $yuki . '/statuses/mentioning-' . $side;
    ap_mention_store(['owner_user_id' => 1, 'owner_actor_id' => LOCAL_ACTOR, 'actor_id' => $yuki, 'type' => 'Create',
        'object_id' => $post, 'activity_id' => $post . '/activity', 'content' => 'hello @cmdr_nova']);
    $body = json_encode(['id' => $yuki . '#delete/mentioning-' . $side, 'type' => 'Delete', 'actor' => $yuki, 'object' => $post], JSON_UNESCAPED_SLASHES);
    $result = $run($side, $body);
    $ev = $normalize($activityEvent('Delete', $post), $side, $body);
    $out[$side] = ['result' => $result, 'mention' => $mentionState('object_id = ?', [$post]), 'event' => $ev];
}
$check($out['rust']['result'] === 'local_mention_delete' && $out['php']['result'] === 'local_mention_delete', 'mention Delete: both return local_mention_delete ('
    . $out['rust']['result'] . ' / ' . $out['php']['result'] . ')');
$check($out['rust']['mention'] === '0/1' && $out['php']['mention'] === '0/1', 'mention Delete: the mention is soft-deleted on both sides');
$check($out['rust']['event'] == $out['php']['event'] && $out['rust']['event'] !== null, "mention Delete: events rows match\n" . json_encode($out, JSON_UNESCAPED_SLASHES));

// 5. Undo Follow stays PHP's.
$body = json_encode(['id' => $yuki . '#undo/follow', 'type' => 'Undo', 'actor' => $yuki,
    'object' => ['id' => $yuki . '#follows/1', 'type' => 'Follow', 'actor' => $yuki, 'object' => LOCAL_ACTOR]], JSON_UNESCAPED_SLASHES);
$check(ru_post($rust, $body, $yukiKey, $priv) === 202, 'Undo Follow: answered 202');
$st = $db->prepare('SELECT state FROM ap_inbox_spool WHERE body = ?');
$st->execute([$body]);
$check($st->fetchColumn() === 'pending', 'Undo Follow: left to the PHP drain');

$stats = $redis->hGetAll('vaak:inbox_rust:stats:v1');
$check((int) ($stats['routed_local_undo_like'] ?? 0) === 2 && (int) ($stats['routed_local_undo_reblog'] ?? 0) === 1
    && (int) ($stats['routed_local_mention_delete'] ?? 0) === 1, 'stats count what Rust routed ' . json_encode($stats));

echo "inbox-route-undo: {$n} checks passed\n";
