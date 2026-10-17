<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/api/bin/migration-reference-functions.php';
reference_load(dirname(__DIR__, 2) . '/api/ap-db.php', [
    'ap_outbox_note_is_reply',
    'ap_outbox_note_on_public_profile',
]);

$hashtag = '<p><a href="https://mkultra.monster/tags/flux" class="mention hashtag" rel="tag">#<span>flux</span></a></p>';
$mention = '<p>Hello <a href="https://mkultra.monster/users/ada" class="u-url mention">@ada</a></p>';
$samples = [
    ['id' => 'hashtag-only', 'input' => [
        'in_reply_to' => null,
        'visibility' => 'public',
        'content' => $hashtag,
        'raw_create_json' => '{"type":"Create","object":{"tag":[{"type":"Hashtag","name":"#flux"}]}}',
    ]],
    ['id' => 'mention-only', 'input' => [
        'in_reply_to' => '',
        'visibility' => 'public',
        'content' => $mention,
        'raw_create_json' => '{"type":"Create","object":{"tag":[{"type":"Mention","href":"https://mkultra.monster/users/ada","name":"@ada"},{"type": "Mention","name":"@bea"}]}}',
    ]],
    ['id' => 'real-reply', 'input' => [
        'in_reply_to' => ' https://mkultra.monster/users/ada/notes/parent ',
        'visibility' => 'public',
        'content' => 'A plain reply',
        'raw_create_json' => '{"type":"Create","object":{"inReplyTo":"https://mkultra.monster/users/ada/notes/parent"}}',
    ]],
    ['id' => 'reply-hashtag', 'input' => [
        'in_reply_to' => 'https://mkultra.monster/users/ada/notes/parent',
        'visibility' => 'public',
        'content' => $hashtag,
        'raw_create_json' => '{"type":"Create","object":{"inReplyTo":"https://mkultra.monster/users/ada/notes/parent","tag":[{"type":"Hashtag","name":"#flux"},{"type": "Hashtag","name":"#nova"}]}}',
    ]],
    ['id' => 'reply-mention', 'input' => [
        'in_reply_to' => 'https://mkultra.monster/users/ada/notes/parent',
        'visibility' => 'public',
        'content' => $mention,
        'raw_create_json' => '{"type":"Create","object":{"inReplyTo":"https://mkultra.monster/users/ada/notes/parent","tag":[{"type":"Mention","href":"https://mkultra.monster/users/ada","name":"@ada"},{"type": "Mention","name":"@bea"}]}}',
    ]],
    ['id' => 'local-only', 'input' => [
        'in_reply_to' => null,
        'visibility' => 'local',
        'content' => $hashtag . $mention,
        'raw_create_json' => '{"type":"Create","object":{"tag":[{"type":"Hashtag"},{"type":"Mention"}]}}',
    ]],
    ['id' => 'private-reply', 'input' => [
        'in_reply_to' => 'https://mkultra.monster/users/ada/notes/secret',
        'visibility' => 'private',
        'content' => 'secret',
    ]],
    ['id' => 'missing-visibility', 'input' => [
        'content' => 'plain post',
    ]],
];

$cases = [];
foreach ($samples as $sample) {
    $row = $sample['input'];
    $reply = ap_outbox_note_is_reply($row);
    $public = ap_outbox_note_on_public_profile($row);
    $cases[] = [
        'id' => $sample['id'],
        'input' => $row,
        'output' => [
            'reply' => $reply,
            'public_profile' => $public,
            'posts' => $public && !$reply,
            'replies' => $public && $reply,
        ],
    ];
}
echo json_encode(['cases' => $cases], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
