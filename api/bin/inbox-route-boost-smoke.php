<?php
/**
 * Rust boost routing (cutover F5b) against Postgres, Redis and a worker started
 * with VAAK_INBOX_RUST_ACCEPT=1 VAAK_INBOX_ROUTE_BOOST=1.
 *
 * Like inbox-route-like-smoke.php: each case runs once through the worker and
 * once through PHP (ap_inbox_spool_process) with fresh ids. The boost's events
 * row, its notification row, the timeline fan-out jobs on
 * vaak:queue:timeline_fanout and the firehose dedupe (one Announce row per
 * note) must end the same; Rust hands the phone push to PHP.
 */
declare(strict_types=1);

putenv('VAAK_INBOX_RUST_ACCEPT=1');
define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__) . '/ap-inbox.php';

$rust = rtrim((string) getenv('VAAK_INBOX_RUST_URL'), '/');
if ($rust === '') {
    fwrite(STDERR, "inbox-route-boost: set VAAK_INBOX_RUST_URL\n");
    exit(2);
}
$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void {
    if (!$ok) {
        fwrite(STDERR, "inbox-route-boost: FAIL {$why}\n");
        exit(1);
    }
    $n++;
};

function rb_post(string $url, string $path, string $body, string $keyId, string $priv): int
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
            'Content-Type: application/activity+json', 'X-Forwarded-For: 203.0.113.12',
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

$booster = static fn(string $who): string => 'https://boost.test/users/' . $who;
foreach (['ren', 'mo'] as $who) {
    ap_inbox_rust_key_mirror($booster($who) . '#main-key', $pem);
}
$redis = ap_redis_client();
$queue = ap_redis_client('queue');
$redis->del('vaak:inbox_rust:stats:v1');
$queue->del('vaak:queue:inbox_webpush', 'vaak:queue:timeline_fanout');

$owner = (int) $db->query("SELECT id FROM ap_users WHERE username = 'boost_owner'")->fetchColumn();
if ($owner < 1) {
    $db->exec("INSERT INTO ap_users (username, email, password_hash, actor_key, actor_id, is_admin, created_at, updated_at)
               VALUES ('boost_owner', 'boost-owner@vaak.test', 'unused', 'boost_owner', 'https://mkultra.monster/users/boost_owner', 0,
                       '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')");
    $owner = (int) $db->query("SELECT id FROM ap_users WHERE username = 'boost_owner'")->fetchColumn();
}
$cmdrNote = static fn(string $tag): string => LOCAL_ACTOR . '/notes/boost-' . $tag;
$ownerNote = static fn(string $tag): string => 'https://mkultra.monster/users/boost_owner/notes/boost-' . $tag;
$public = 'https://www.w3.org/ns/activitystreams#Public';

$run = static function (string $side, string $who, string $path, string $body) use ($rust, $booster, $priv, $db, $check): array {
    $keyId = $booster($who) . '#main-key';
    if ($side === 'rust') {
        $check(rb_post($rust, $path, $body, $keyId, $priv) === 202, 'worker answers 202');
        $st = $db->prepare('SELECT state, result FROM ap_inbox_spool WHERE body = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$body]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [(string) ($row['state'] ?? ''), (string) ($row['result'] ?? '')];
    }
    $out = ap_inbox_spool_process(['id' => 0, 'client_ip' => '', 'key_id' => $keyId, 'request_path' => $path, 'body' => $body]);
    return [(string) $out['state'], (string) $out['result']];
};
$strip = static fn(string $v, string $side): string => str_replace($side, '', $v);
$events = static function (string $objectId, string $side) use ($db, $strip): array {
    $st = $db->prepare("SELECT id, actor_id, object_id, target_actor, host, action_taken, summary, media_urls, in_reply_to,
                               spoiler_text, sensitive, visibility FROM events WHERE type = 'Announce' AND object_id = ? ORDER BY id");
    $st->execute([$objectId]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
        unset($ev['id']);
        $ev['object_id'] = $strip((string) $ev['object_id'], $side);
        $rows[] = $ev;
    }
    return $rows;
};
$mention = static function (string $activityId, string $side) use ($db, $strip): ?array {
    $st = $db->prepare('SELECT owner_user_id, owner_actor_id, activity_id, object_id, actor_id, type, content, in_reply_to, deleted_at,
                               media_urls, activity_type, spoiler_text, sensitive FROM mentions WHERE activity_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$activityId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($m)) return null;
    $m['activity_id'] = $strip((string) $m['activity_id'], $side);
    $m['object_id'] = preg_replace('/#reblog-[0-9a-f]{12}$/', '#reblog-HASH', $strip((string) $m['object_id'], $side));
    return $m;
};
// Fan-out jobs pushed during one run, with the event id and time left out.
$fanout = static function (int $before) use ($queue): array {
    $len = (int) $queue->lLen('vaak:queue:timeline_fanout');
    $jobs = [];
    foreach (array_slice($queue->lRange('vaak:queue:timeline_fanout', 0, -1) ?: [], 0, max(0, $len - $before)) as $raw) {
        $j = json_decode((string) $raw, true) ?: [];
        $ok = ctype_digit((string) ($j['id'] ?? '')) && (int) ($j['ts'] ?? 0) > 0;
        unset($j['id'], $j['ts']);
        $j['ids_ok'] = $ok;
        ksort($j);
        $jobs[] = $j;
    }
    usort($jobs, static fn($a, $b) => strcmp((string) $a['op'], (string) $b['op']));
    return $jobs;
};

$cases = [
    // [label, path, note maker, boosts as [who, extra fields], owner id or 0, fan-out ops for the first boost]
    ['public shared-inbox boost of a cmdr_nova note', '/inbox', $cmdrNote,
        [['ren', ['to' => [$public], 'cc' => ['https://boost.test/users/ren/followers']]]], 1, ['home_followers', 'public_feed']],
    ['unlisted personal-inbox boost', '/users/boost_owner/inbox', $ownerNote,
        [['ren', ['to' => ['https://boost.test/users/ren/followers'], 'cc' => [$public]]]], $owner, ['home_followers']],
    ['shared-inbox boost of another account\'s note', '/inbox', $ownerNote,
        [['ren', ['to' => [$public]]]], 0, ['home_followers', 'public_feed']],
    ['second boost of the same note (followers-only)', '/inbox', $cmdrNote,
        [['ren', ['to' => [$public]]], ['mo', ['to' => ['https://boost.test/users/mo/followers']]]], 1, ['home_followers', 'public_feed']],
];
foreach ($cases as $i => [$label, $path, $noteOf, $boosts, $notified, $ops]) {
    $out = [];
    foreach (['rust', 'php'] as $side) {
        $note = $noteOf($side . $i);
        $steps = [];
        foreach ($boosts as $j => [$who, $extra]) {
            $activityId = $booster($who) . '/statuses/' . $side . $i . '/activity';
            $doc = array_merge(['id' => $activityId, 'type' => 'Announce', 'actor' => $booster($who), 'object' => $note,
                'published' => '2026-11-13T00:00:00Z'], $extra);
            $body = json_encode($doc, JSON_UNESCAPED_SLASHES);
            if ($notified > 0) {
                $redis->set("vaak:notifications:v4:{$notified}:smoke-{$side}", 'x');
            }
            $epoch = $notified > 0 ? (int) $redis->get("vaak:notifications:epoch:v1:{$notified}") : 0;
            $pushes = (int) $queue->lLen('vaak:queue:inbox_webpush');
            $jobsBefore = (int) $queue->lLen('vaak:queue:timeline_fanout');
            [$state, $result] = $run($side, $who, $path, $body);
            $steps[] = [
                'state' => $state,
                'result' => $result,
                'mention' => $mention($activityId, $side),
                'caches_gone' => $notified > 0 && !$redis->exists("vaak:notifications:v4:{$notified}:smoke-{$side}"),
                'epoch_bumped' => $notified > 0 && (int) $redis->get("vaak:notifications:epoch:v1:{$notified}") > $epoch,
                'push_jobs' => (int) $queue->lLen('vaak:queue:inbox_webpush') - $pushes,
                'fanout' => $fanout($jobsBefore),
            ];
        }
        $out[$side] = ['steps' => $steps, 'events' => $events($note, $side)];
    }
    $dump = "\n" . json_encode($out, JSON_UNESCAPED_SLASHES);
    $check($out['rust']['events'] !== [] && $out['rust']['events'] == $out['php']['events'], "{$label}: events rows match{$dump}");
    $check(count($out['rust']['events']) === 1, "{$label}: one Announce row per note{$dump}");
    foreach ($out['rust']['steps'] as $j => $r) {
        $p = $out['php']['steps'][$j];
        $check($r['state'] === 'done' && $r['result'] === 'local_observe', "{$label} #{$j}: Rust routes it itself{$dump}");
        $check($p['result'] === 'local_observe', "{$label} #{$j}: PHP returns local_observe{$dump}");
        $check($r['mention'] == $p['mention'], "{$label} #{$j}: notification rows match{$dump}");
        $check($r['fanout'] == $p['fanout'], "{$label} #{$j}: timeline fan-out jobs match{$dump}");
        $wantOps = $j === 0 ? $ops : [];
        $check(array_column($r['fanout'], 'op') === $wantOps && !in_array(false, array_column($r['fanout'], 'ids_ok'), true),
            "{$label} #{$j}: fan-out ops " . implode('+', $wantOps) . $dump);
        if ($notified > 0) {
            $check(is_array($r['mention']) && (int) $r['mention']['owner_user_id'] === $notified, "{$label} #{$j}: the note's owner is notified{$dump}");
            $check($r['caches_gone'] && $p['caches_gone'] && $r['epoch_bumped'] && $p['epoch_bumped'], "{$label} #{$j}: both clear the owner's caches");
            $check($r['push_jobs'] === 1, "{$label} #{$j}: Rust hands the phone push to PHP");
        } else {
            $check($r['mention'] === null && $r['push_jobs'] === 0, "{$label} #{$j}: nobody is notified");
        }
    }
}
$check($out['rust']['events'][0]['visibility'] === 'private', 'a later followers-only boost narrows the row');

// Shapes Rust leaves to PHP: spooled for the drain.
$db->prepare("INSERT INTO outbox_notes (id, create_id, published, content, raw_create_json, visibility) VALUES (?, ?, ?, '', '{}', 'private')")
    ->execute([$cmdrNote('private'), $cmdrNote('private') . '/activity', gmdate('c')]);
$ren = $booster('ren');
$deferred = [
    'content' => ['id' => $ren . '/b/content', 'type' => 'Announce', 'actor' => $ren, 'object' => $cmdrNote('c'), 'to' => [$public], 'content' => 'hi'],
    'embedded' => ['id' => $ren . '/b/emb', 'type' => 'Announce', 'actor' => $ren, 'object' => ['id' => $cmdrNote('emb'), 'type' => 'Note'], 'to' => [$public]],
    'private_object' => ['id' => $ren . '/b/priv', 'type' => 'Announce', 'actor' => $ren, 'object' => $cmdrNote('private')],
];
foreach ($deferred as $why => $doc) {
    [$state] = $run('rust', 'ren', '/inbox', json_encode($doc, JSON_UNESCAPED_SLASHES));
    $check($state === 'pending', "{$why}: left to the PHP drain (state={$state})");
}

$job = json_decode((string) $queue->lIndex('vaak:queue:inbox_webpush', -1), true);
$check(is_array($job) && ($job['type'] ?? '') === 'reblog' && str_contains((string) ($job['object_id'] ?? ''), '#reblog-'),
    'the push job is a reblog for the notification row');
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/inbox-spool-drain.php') . ' --once 2>&1';
shell_exec('VAAK_FPM_POOL_CONF=/nonexistent ' . $cmd);
$check((int) $queue->lLen('vaak:queue:inbox_webpush') === 0, 'the drain sends every queued push');

$stats = $redis->hGetAll('vaak:inbox_rust:stats:v1');
$check((int) ($stats['routed_local_observe'] ?? 0) === 5 && (int) ($stats['route_defer_content'] ?? 0) === 1
    && (int) ($stats['route_defer_embedded'] ?? 0) === 1 && (int) ($stats['route_defer_private_object'] ?? 0) === 1,
    'stats count what Rust routed and deferred ' . json_encode($stats));

echo "inbox-route-boost: {$n} checks passed\n";
