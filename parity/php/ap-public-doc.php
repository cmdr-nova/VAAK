<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/ap-public-doc.php';

$noteId = 'https://mkultra.monster/users/cmdr_nova/notes/bc15ca933555c8aa';
$at = 'at://did:plc:example/app.bsky.feed.post/abc';
$cases = [
    'pinned-empty-video' => [
        'type' => 'Note',
        'id' => $noteId,
        'content' => '<p></p>',
        'url' => [
            $noteId,
            ['type' => 'Link', 'rel' => 'alternate', 'href' => $at],
        ],
        'blueskyUri' => $at,
        'blueskyCid' => 'bafyrei',
        'attachment' => [[
            'type' => 'Document',
            'mediaType' => 'video/mp4',
            'url' => 'https://cdn.example/video.mp4',
        ]],
        'alsoKnownAs' => ['at://did:plc:example'],
    ],
    'prefer-note-id' => [
        'type' => 'Note',
        'id' => $noteId,
        'content' => '<p>Hello</p>',
        'url' => [
            'https://bsky.app/profile/example/post/abc',
            $noteId,
        ],
        'attachment' => [['type' => 'Document', 'url' => 'https://cdn.example/video.mp4']],
    ],
    'at-only-url' => [
        'type' => 'Note',
        'id' => $noteId,
        'content' => '<p>caption</p>',
        'url' => ['type' => 'Link', 'rel' => 'alternate', 'href' => $at],
    ],
    'keep-existing-bluesky-uri' => [
        'type' => 'Note',
        'id' => $noteId,
        'content' => '<p>caption</p>',
        'url' => ['type' => 'Link', 'href' => 'at://did:plc:other/app.bsky.feed.post/zzz'],
        'blueskyUri' => $at,
    ],
    'empty-text-without-media' => [
        'type' => 'Note',
        'id' => $noteId,
        'content' => '<p></p>',
        'url' => $noteId,
    ],
    'question-empty-media' => [
        'type' => 'Question',
        'id' => $noteId,
        'content' => '',
        'attachment' => ['type' => 'Document', 'url' => 'https://cdn.example/video.mp4'],
    ],
    'actor-also-known-as' => [
        'type' => 'Person',
        'id' => 'https://mkultra.monster/users/cmdr_nova',
        'url' => 'https://mkultra.monster/users/cmdr_nova',
        'alsoKnownAs' => ['at://did:plc:example', 'https://example.com/users/other'],
        'attachment' => [[
            'type' => 'PropertyValue',
            'name' => 'Bluesky',
            'value' => '<a href="https://bsky.app/profile/x">x</a>',
        ]],
    ],
    'featured-ids' => [
        'type' => 'OrderedCollection',
        'id' => 'https://mkultra.monster/users/cmdr_nova/collections/featured',
        'totalItems' => 1,
        'orderedItems' => [$noteId],
    ],
    'follow-unchanged' => [
        'type' => 'Follow',
        'actor' => 'https://mkultra.monster/users/cmdr_nova',
        'object' => 'https://example.com/users/other',
    ],
    'add-unchanged' => [
        'type' => 'Add',
        'actor' => 'https://mkultra.monster/users/cmdr_nova',
        'object' => $noteId,
        'target' => 'https://mkultra.monster/users/cmdr_nova/collections/featured',
    ],
    'create-wraps-note' => [
        'type' => 'Create',
        'id' => $noteId . '/activity',
        'actor' => 'https://mkultra.monster/users/cmdr_nova',
        'object' => [
            'type' => 'Note',
            'id' => $noteId,
            'content' => '<p></p>',
            'url' => [$noteId, ['type' => 'Link', 'href' => $at]],
            'attachment' => [['type' => 'Document', 'url' => 'https://cdn.example/video.mp4']],
        ],
    ],
];

$out = [];
foreach ($cases as $id => $input) {
    $out[] = [
        'id' => $id,
        'input' => $input,
        'output' => ap_as2_pleroma_document($input),
    ];
}

echo json_encode(['cases' => $out], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
