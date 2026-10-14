<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/api/ap-follow-state.php';

$local = 'https://mkultra.monster/users/cmdr_nova';
$remote = 'https://misskey.io/users/ada';
$follow = [
    'id' => $local . '/follows/abc',
    'type' => 'Follow',
    'actor' => $local,
    'object' => $remote,
];
$now = 1700000000;
$samples = [
    ['id' => 'state-send', 'input' => ['op' => 'state', 'current' => 'unknown', 'event' => 'send']],
    ['id' => 'state-accept', 'input' => ['op' => 'state', 'current' => 'pending', 'event' => 'accept']],
    ['id' => 'state-reject', 'input' => ['op' => 'state', 'current' => 'accepted', 'event' => 'reject']],
    ['id' => 'classify-accept', 'input' => ['op' => 'classify', 'local' => $local, 'activity' => [
        'type' => 'Accept', 'actor' => $remote, 'object' => $follow,
    ]]],
    ['id' => 'classify-reject', 'input' => ['op' => 'classify', 'local' => $local, 'activity' => [
        'type' => 'Reject', 'actor' => $remote, 'object' => $follow,
    ]]],
    ['id' => 'classify-ack', 'input' => ['op' => 'classify', 'local' => $local, 'activity' => [
        'type' => 'Accept', 'actor' => $remote, 'object' => [
            'type' => 'Follow', 'actor' => $remote, 'object' => $local,
        ],
    ]]],
    ['id' => 'classify-by-id', 'input' => ['op' => 'classify', 'local' => $local, 'activity' => [
        'type' => 'Accept', 'actor' => $remote, 'object' => $local . '/follows/abc',
    ]]],
    ['id' => 'classify-other-local', 'input' => ['op' => 'classify', 'local' => $local, 'activity' => [
        'type' => 'Accept',
        'actor' => 'https://misskey.io/users/bea',
        'object' => [
            'type' => 'Follow',
            'actor' => 'https://mkultra.monster/users/other',
            'object' => 'https://misskey.io/users/bea',
        ],
    ]]],
    ['id' => 'classify-ignore', 'input' => ['op' => 'classify', 'local' => $local, 'activity' => [
        'type' => 'Create', 'actor' => $remote, 'object' => ['type' => 'Note'],
    ]]],
    ['id' => 'actor-same-host', 'input' => ['op' => 'actor_matches', 'activity_actor' => 'https://misskey.io/users/instance', 'followed_actor' => $remote]],
    ['id' => 'actor-other-host', 'input' => ['op' => 'actor_matches', 'activity_actor' => 'https://evil.test/users/ada', 'followed_actor' => $remote]],
    ['id' => 'scrub', 'input' => ['op' => 'scrub', 'limit' => 120, 'text' => 'nope signature="abcDEF+/=" bearer SECRET digest: sha-256= -----BEGIN PRIVATE KEY-----secret-----END PRIVATE KEY-----']],
    ['id' => 'log-failure', 'input' => ['op' => 'log_line', 'host' => 'misskey.io', 'type' => 'Create', 'status' => 403, 'ok' => false, 'path' => '/inbox?token=secret', 'body' => 'denied signature="abcDEF+/=" bearer SECRET']],
    ['id' => 'log-follow', 'input' => ['op' => 'log_line', 'host' => 'https://misskey.io/inbox', 'type' => 'Follow', 'status' => 202, 'ok' => true, 'path' => '/inbox', 'body' => 'signature="should-not-appear"']],
    ['id' => 'log-create-ok', 'input' => ['op' => 'should_log', 'ok' => true, 'type' => 'Create']],
    ['id' => 'log-create-fail', 'input' => ['op' => 'should_log', 'ok' => false, 'type' => 'Create']],
    ['id' => 'config-default', 'input' => ['op' => 'config', 'env' => []]],
    ['id' => 'config-kill', 'input' => ['op' => 'config', 'env' => ['VAAK_FOLLOW_FALLBACK' => '0', 'VAAK_FOLLOW_FALLBACK_MIN_INTERVAL_MINUTES' => '1', 'VAAK_FOLLOW_FALLBACK_PAGE_LIMIT' => '99']]],
    ['id' => 'config-hosts', 'input' => ['op' => 'config', 'env' => ['VAAK_FOLLOW_FALLBACK_HOSTS' => 'misskey.io, quiet.example']]],
    ['id' => 'due', 'input' => ['op' => 'due', 'last_run' => $now - 600, 'now' => $now, 'min_seconds' => 900]],
    ['id' => 'backoff-429', 'input' => ['op' => 'backoff', 'fail_count' => 3, 'status' => 429]],
    ['id' => 'backoff-404', 'input' => ['op' => 'backoff', 'fail_count' => 1, 'status' => 404]],
    ['id' => 'plan', 'input' => ['op' => 'plan', 'now' => $now, 'host_seen' => ['quiet.example' => $now - 90000, 'fresh.example' => $now - 60], 'config' => [
        'enabled' => true, 'hosts' => ['misskey.io'], 'silence_seconds' => 86400, 'pending_seconds' => 21600, 'max_accounts' => 2,
    ], 'accounts' => [
        ['actor_id' => 'https://misskey.io/users/a', 'host' => 'misskey.io', 'follow_state' => 'unknown', 'state_at' => 0, 'backoff_until' => 0],
        ['actor_id' => 'https://misskey.io/users/no', 'host' => 'misskey.io', 'follow_state' => 'rejected', 'state_at' => 0, 'backoff_until' => 0],
        ['actor_id' => 'https://pending.example/users/old', 'host' => 'pending.example', 'follow_state' => 'pending', 'state_at' => $now - 30000, 'backoff_until' => 0],
        ['actor_id' => 'https://pending.example/users/new', 'host' => 'pending.example', 'follow_state' => 'pending', 'state_at' => $now - 60, 'backoff_until' => 0],
        ['actor_id' => 'https://quiet.example/users/b', 'host' => 'quiet.example', 'follow_state' => 'accepted', 'state_at' => 0, 'backoff_until' => 0],
        ['actor_id' => 'https://fresh.example/users/c', 'host' => 'fresh.example', 'follow_state' => 'accepted', 'state_at' => 0, 'backoff_until' => 0],
        ['actor_id' => 'https://quiet.example/users/wait', 'host' => 'quiet.example', 'follow_state' => 'accepted', 'state_at' => 0, 'backoff_until' => $now + 10],
    ]]],
    ['id' => 'plan-off', 'input' => ['op' => 'plan', 'now' => $now, 'host_seen' => [], 'config' => ['enabled' => false, 'hosts' => ['misskey.io']], 'accounts' => [
        ['actor_id' => $remote, 'host' => 'misskey.io', 'follow_state' => 'pending', 'state_at' => 1, 'backoff_until' => 0],
    ]]],
    ['id' => 'select-stop', 'input' => ['op' => 'select', 'limit' => 5, 'stop_at_seen' => true, 'seen' => ['https://misskey.io/notes/old'], 'object_ids' => [
        'https://misskey.io/notes/1', 'https://misskey.io/notes/2/', 'https://misskey.io/notes/old', 'https://misskey.io/notes/3',
    ]]],
    ['id' => 'select-skip', 'input' => ['op' => 'select', 'limit' => 2, 'stop_at_seen' => false, 'seen' => ['https://misskey.io/notes/2'], 'object_ids' => [
        'https://misskey.io/notes/1', 'https://misskey.io/notes/2', 'https://misskey.io/notes/3',
    ]]],
    ['id' => 'page', 'input' => ['op' => 'page', 'actor' => $remote, 'doc' => [
        'type' => 'OrderedCollectionPage',
        'orderedItems' => [
            ['type' => 'Create', 'actor' => $remote, 'object' => ['id' => 'https://misskey.io/notes/1', 'type' => 'Note', 'content' => 'hi']],
            ['type' => 'Announce', 'actor' => $remote, 'object' => ['id' => 'https://other.test/notes/9', 'type' => 'Note']],
            ['type' => 'Create', 'actor' => 'https://other.test/users/x', 'object' => ['id' => 'https://other.test/notes/8', 'type' => 'Note']],
            'https://misskey.io/notes/bare',
            ['id' => 'https://misskey.io/notes/4', 'type' => 'Note', 'attributedTo' => $remote, 'content' => 'pin'],
        ],
    ]]],
    ['id' => 'page-first', 'input' => ['op' => 'page', 'actor' => $remote, 'doc' => [
        'type' => 'OrderedCollection', 'first' => 'https://misskey.io/users/ada/outbox?page=true',
    ]]],
    ['id' => 'host', 'input' => ['op' => 'host', 'raw' => 'HTTPS://Misskey.io/users/ada']],
];

$cases = [];
foreach ($samples as $sample) {
    $input = $sample['input'];
    $op = $input['op'];
    $output = match ($op) {
        'state' => ap_follow_state_apply((string) $input['current'], (string) $input['event']),
        'classify' => ap_follow_response_classify($input['activity'], (string) $input['local']),
        'actor_matches' => ap_follow_response_actor_matches((string) $input['activity_actor'], (string) $input['followed_actor']),
        'scrub' => ap_delivery_scrub((string) $input['text'], (int) $input['limit']),
        'log_line' => ap_delivery_log_line((string) $input['host'], (string) $input['type'], (int) $input['status'], (bool) $input['ok'], (string) $input['body'], (string) $input['path']),
        'should_log' => ap_delivery_should_log((bool) $input['ok'], (string) $input['type']),
        'config' => ap_follow_fallback_config($input['env']),
        'due' => ap_follow_fallback_due((int) $input['last_run'], (int) $input['now'], (int) $input['min_seconds']),
        'backoff' => ap_follow_fallback_backoff_seconds((int) $input['fail_count'], (int) $input['status']),
        'plan' => ap_follow_fallback_plan($input['config'], $input['accounts'], $input['host_seen'], (int) $input['now']),
        'select' => ap_follow_fallback_select_new($input['object_ids'], $input['seen'], (int) $input['limit'], (bool) $input['stop_at_seen']),
        'page' => ap_follow_fallback_page($input['doc'], (string) $input['actor']),
        'host' => ap_follow_fallback_host((string) $input['raw']),
        default => throw new RuntimeException('unknown op ' . $op),
    };
    $cases[] = ['id' => $sample['id'], 'input' => $input, 'output' => $output];
}
echo json_encode(['cases' => $cases], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
