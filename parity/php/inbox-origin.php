<?php
declare(strict_types=1);
define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__, 2) . '/api/ap-inbox.php';

$actor = 'https://example.com/users/alice';
$object = $actor . '/statuses/1';
$activity = $object . '/activity';
$note = [
    'id' => $object,
    'type' => 'Note',
    'attributedTo' => $actor,
    'content' => 'origin-body',
];
$create = [
    'id' => $activity,
    'type' => 'Create',
    'actor' => $actor,
    'object' => $note,
];
$samples = [
    ['id' => 'create-urls', 'input' => ['op' => 'urls', 'posted' => [
        'id' => $activity, 'type' => 'Create', 'actor' => $actor, 'object' => $object,
    ]]],
    ['id' => 'delete-urls', 'input' => ['op' => 'urls', 'posted' => [
        'id' => $object . '#delete', 'type' => 'Delete', 'actor' => $actor, 'object' => ['id' => $object, 'type' => 'Tombstone'],
    ]]],
    ['id' => 'follow-urls', 'input' => ['op' => 'urls', 'posted' => [
        'type' => 'Follow', 'actor' => $actor, 'object' => 'https://mkultra.monster/users/cmdr_nova',
    ]]],
    ['id' => 'like-urls', 'input' => ['op' => 'urls', 'posted' => [
        'type' => 'Like', 'actor' => $actor, 'object' => 'https://other.test/users/zoe/statuses/9',
    ]]],
    ['id' => 'create-origin', 'input' => ['op' => 'decide', 'posted' => [
        'id' => $activity, 'type' => 'Create', 'actor' => $actor,
        'object' => ['id' => $object, 'type' => 'Note', 'attributedTo' => $actor, 'content' => 'spoofed-body'],
    ], 'fetches' => [
        $activity => ['status' => 404, 'doc' => null],
        $object => ['status' => 200, 'doc' => $note],
    ]]],
    ['id' => 'spoof-actor', 'input' => ['op' => 'decide', 'posted' => [
        'id' => $activity, 'type' => 'Create', 'actor' => $actor, 'object' => $object,
    ], 'fetches' => [
        $activity => ['status' => 200, 'doc' => [
            'id' => $activity, 'type' => 'Create', 'actor' => 'https://example.com/users/bob',
            'object' => ['id' => $object, 'type' => 'Note', 'attributedTo' => 'https://example.com/users/bob', 'content' => 'bob'],
        ]],
    ]]],
    ['id' => 'delete-gone', 'input' => ['op' => 'decide', 'posted' => [
        'id' => $object . '#delete', 'type' => 'Delete', 'actor' => $actor, 'object' => $object, 'summary' => 'do-not-trust',
    ], 'fetches' => [
        $object => ['status' => 410, 'doc' => null],
    ]]],
    ['id' => 'delete-live', 'input' => ['op' => 'decide', 'posted' => [
        'type' => 'Delete', 'actor' => $actor, 'object' => $object,
    ], 'fetches' => [
        $object => ['status' => 200, 'doc' => $note],
    ]]],
    ['id' => 'delete-activity', 'input' => ['op' => 'decide', 'posted' => [
        'id' => $actor . '/deletes/1', 'type' => 'Delete', 'actor' => $actor, 'object' => $object,
    ], 'fetches' => [
        $object => ['status' => 404, 'doc' => null],
        $actor . '/deletes/1' => ['status' => 200, 'doc' => [
            'id' => $actor . '/deletes/1', 'type' => 'Delete', 'actor' => $actor, 'object' => $object,
        ]],
    ]]],
    ['id' => 'follow-none', 'input' => ['op' => 'decide', 'posted' => [
        'type' => 'Accept', 'actor' => $actor, 'object' => 'https://mkultra.monster/users/cmdr_nova/follows/1',
    ], 'fetches' => []]],
    ['id' => 'reconcile-match', 'input' => ['op' => 'reconcile', 'keyId' => $actor . '#main-key', 'trusted' => false, 'posted' => [
        'id' => $activity, 'type' => 'Create', 'actor' => $actor, 'object' => $object,
    ], 'fetches' => []]],
    ['id' => 'reconcile-trusted', 'input' => ['op' => 'reconcile', 'keyId' => 'https://other.test/users/bob#main-key', 'trusted' => true, 'posted' => [
        'id' => $activity, 'type' => 'Create', 'actor' => $actor, 'object' => $object,
    ], 'fetches' => []]],
    ['id' => 'reconcile-forward', 'input' => ['op' => 'reconcile', 'keyId' => 'https://other.test/users/bob#main-key', 'trusted' => false, 'posted' => [
        'id' => $activity, 'type' => 'Create', 'actor' => $actor,
        'object' => ['id' => $object, 'type' => 'Note', 'attributedTo' => $actor, 'content' => 'spoofed-body'],
    ], 'fetches' => [
        $activity => ['status' => 404, 'doc' => null],
        $object => ['status' => 200, 'doc' => $note],
    ]]],
    ['id' => 'reconcile-spoof', 'input' => ['op' => 'reconcile', 'keyId' => 'https://other.test/users/bob#main-key', 'trusted' => false, 'posted' => [
        'id' => $activity, 'type' => 'Create', 'actor' => $actor, 'object' => $object,
    ], 'fetches' => [
        $activity => ['status' => 200, 'doc' => [
            'id' => $activity, 'type' => 'Create', 'actor' => 'https://example.com/users/bob',
            'object' => ['id' => $object, 'type' => 'Note', 'attributedTo' => 'https://example.com/users/bob', 'content' => 'bob'],
        ]],
    ]]],
];

$cases = [];
foreach ($samples as $sample) {
    $input = $sample['input'];
    $posted = is_array($input['posted'] ?? null) ? $input['posted'] : null;
    $fetches = is_array($input['fetches'] ?? null) ? $input['fetches'] : [];
    $op = (string) ($input['op'] ?? '');
    if ($op === 'urls') {
        $output = ap_inbox_origin_urls($posted);
    } elseif ($op === 'reconcile') {
        $output = ap_inbox_reconcile_decision($posted, (string) ($input['keyId'] ?? ''), !empty($input['trusted']), $fetches);
    } else {
        $output = ap_inbox_origin_decision($posted, $fetches);
    }
    $cases[] = ['id' => $sample['id'], 'input' => $input, 'output' => $output];
}
echo json_encode(['cases' => $cases], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
