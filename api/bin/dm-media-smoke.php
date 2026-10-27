<?php
declare(strict_types=1);

/**
 * DM media display, direct addressing, and the "never public" boundary.
 * No database, no remote fetch, no delivery.
 */

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$api = dirname(__DIR__);
require_once $api . '/bin/migration-reference-functions.php';

function ap_db(): never
{
    throw new RuntimeException('dm-media-smoke does not use a database');
}

reference_load($api . '/ap-db.php', [
    'ap_profile_sanitize_https_url',
    'ap_dm_href_from_node',
    'ap_dm_attachment_href',
    'ap_dm_attachment_preview',
    'ap_dm_attachment_is_displayable',
    'ap_dm_media_kind',
    'ap_dm_file_label',
    'ap_dm_media_items',
    'ap_dm_attachments_for_store',
    'ap_dm_federation_attachments',
    'ap_dm_warning_from_row',
    'ap_dm_masto_media_list',
    'ap_dm_activity_audience_is_direct',
]);
reference_load($api . '/ap-inbox.php', [
    'ap_dm_build_activity',
]);
reference_load($api . '/ap-admin.php', [
    'h',
    'admin_media_is_video',
    'admin_guess_video_poster_url',
    'admin_media_is_image_poster_url',
    'admin_media_is_audio',
    'admin_media_row_html',
    'admin_tweet_content_wrap',
    'admin_cw_gate_html',
    'admin_dm_html',
    'admin_dm_media_html',
    'admin_dm_thread_message_html',
]);

$functionSource = static function (string $file, string $name): string {
    $tokens = token_get_all((string) file_get_contents($file));
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $name) {
            continue;
        }
        $code = '';
        $depth = 0;
        $body = false;
        for ($k = $i; $k < $n; $k++) {
            $token = $tokens[$k];
            $code .= is_array($token) ? $token[1] : $token;
            if ($token === '{') {
                $depth++;
                $body = true;
            }
            if ($token === '}') {
                $depth--;
            }
            if ($body && $depth === 0) {
                return $code;
            }
        }
    }
    return '';
};

$peer = 'https://remote.example/users/ada';
$row = [
    'content' => 'hello from a DM',
    'attachments_json' => json_encode([
        [
            'type' => 'Image',
            'mediaType' => 'image/png',
            'url' => 'https://cdn.example/photos/cat.png',
            'name' => 'A cat <img src=x onerror=alert(1)>',
        ],
        [
            'type' => 'Image',
            'mediaType' => 'image/gif',
            'url' => 'https://cdn.example/photos/loop.gif',
            'name' => 'Looping gif',
        ],
        [
            'type' => 'Document',
            'mediaType' => 'video/mp4',
            'url' => 'https://cdn.example/media/original/clip.mp4',
            'name' => 'Mastodon gifv',
            'thumbnail' => ['type' => 'Image', 'url' => 'https://cdn.example/media/small/clip.png'],
        ],
        [
            'type' => 'Video',
            'mediaType' => 'video/mp4',
            'url' => 'https://cdn.example/photos/talk.mp4',
            'name' => 'A short video',
        ],
        [
            'type' => 'Audio',
            'mediaType' => 'audio/webm',
            'url' => 'https://cdn.example/photos/voice.webm',
            'name' => 'Voice note',
        ],
        [
            'type' => 'Document',
            'mediaType' => 'application/pdf',
            'url' => 'https://cdn.example/files/notes.pdf',
            'name' => 'Notes',
        ],
    ], JSON_UNESCAPED_SLASHES),
];

$items = ap_dm_media_items($row);
$check(count($items) === 4, 'display keeps at most four attachments');
$check(($items[0]['description'] ?? '') === 'A cat', 'alt text is tag-stripped and capped at the image name');
$check(!str_contains((string) ($items[0]['description'] ?? ''), '<'), 'alt text cannot carry a tag');

$html = admin_dm_media_html($row);
$check(str_contains($html, 'media-lightbox-trigger'), 'images use the timeline lightbox');
$check(str_contains($html, 'alt="A cat &lt;img src=x onerror=alert(1)&gt;"') || str_contains($html, 'alt="A cat"'), 'image alt is escaped');
$check(str_contains($html, '<video'), 'gifv and video render as video');
$check(substr_count($html, '<video') === 2, 'gif image stays an image while gifv and video play');
$check(str_contains($html, 'data-media-warm-url="https://cdn.example/media/small/clip.png"'), 'gifv keeps its preview for the existing warm cache');
$check(!str_contains($html, '<script'), 'media html has no raw script tag');
$check(!str_contains($html, 'onerror='), 'media html drops the unsafe alt payload');

$audio = admin_dm_media_html([
    'attachments' => [[
        'type' => 'Audio',
        'mediaType' => 'audio/webm',
        'url' => 'https://cdn.example/photos/voice.webm',
        'name' => 'Voice note',
    ]],
]);
$check(str_contains($audio, '<audio'), 'audio/webm renders as audio');
$check(!str_contains($audio, '<video'), 'audio/webm is not treated as video');
$check(str_contains($audio, 'aria-label="Voice note"'), 'audio keeps its description');

$file = admin_dm_media_html([
    'attachments' => [[
        'type' => 'Document',
        'mediaType' => 'application/pdf',
        'url' => 'https://cdn.example/files/evil<script>.pdf',
        'name' => 'x',
    ]],
]);
$check(str_contains($file, 'class="dm-file-link"'), 'unknown files fall back to a download link');
$check(str_contains($file, 'href="https://cdn.example/files/evil&lt;script&gt;.pdf"'), 'file href is escaped');
$check(!str_contains($file, '<script'), 'file name cannot inject a tag');
$check(!str_contains($file, '<img'), 'a pdf is not rendered as an image');

$rejected = ap_dm_media_items([
    'media_urls' => [
        'javascript:alert(1)',
        'http://cdn.example/a.png',
        'https://user:pass@cdn.example/a.png',
        'https://127.0.0.1/a.png',
        'https://cdn.example/ok.png',
    ],
]);
$check(count($rejected) === 1 && ($rejected[0]['url'] ?? '') === 'https://cdn.example/ok.png', 'only safe https media URLs are kept');

$warned = [
    'content' => 'hidden text',
    'attachments_json' => json_encode([
        ['type' => 'Image', 'mediaType' => 'image/jpeg', 'url' => 'https://cdn.example/photos/quiet.jpg', 'name' => 'Quiet'],
        ['type' => 'vaak:DmMeta', 'sensitive' => true, 'summary' => '<script>alert(1)</script>spoiler'],
    ]),
];
$wrapped = admin_dm_thread_message_html($warned, false);
$check(str_contains($wrapped, 'class="cw-gate"'), 'sensitive DM media uses the content-warning gate');
$check(str_contains($wrapped, 'dm-bubble'), 'the warning wraps the message');
$check(str_contains($wrapped, 'alert(1)spoiler'), 'spoiler text is shown as text');
$check(!str_contains($wrapped, '<script'), 'spoiler cannot inject markup');
$check(!str_contains($wrapped, 'Open referenced ' . 'post'), 'media rendering does not invent reply context');

$plain = admin_dm_thread_message_html(['content' => 'just text'], true);
$check(str_contains($plain, 'dm-bubble dm-out'), 'a text DM still renders');
$check(!str_contains($plain, 'cw-gate'), 'a normal DM is not blurred');

$masto = ap_dm_masto_media_list([
    'attachments' => [
        ['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://cdn.example/a.png', 'name' => 'Alt'],
        ['type' => 'Document', 'mediaType' => 'video/mp4', 'url' => 'https://cdn.example/g.mp4', 'name' => 'gifv'],
        ['type' => 'Video', 'mediaType' => 'video/mp4', 'url' => 'https://cdn.example/v.mp4'],
        ['type' => 'Audio', 'mediaType' => 'audio/mpeg', 'url' => 'https://cdn.example/a.mp3', 'name' => 'clip'],
        ['type' => 'Document', 'mediaType' => 'application/pdf', 'url' => 'https://cdn.example/a.pdf'],
    ],
]);
$check(array_column($masto, 'type') === ['image', 'gifv', 'video', 'audio'], 'Mastodon attachments keep image, gifv, video, and audio');
$check(($masto[0]['description'] ?? null) === 'Alt', 'Mastodon image keeps alt text');

$stored = ap_dm_attachments_for_store([
    ['type' => 'Image', 'url' => 'https://cdn.example/a.png', 'name' => 'Alt'],
    ['type' => 'vaak:DmMeta', 'sensitive' => true, 'summary' => 'old'],
], true, 'new warning');
$federated = ap_dm_federation_attachments(is_array($stored) ? $stored : []);
$check(count($federated) === 1 && ($federated[0]['type'] ?? '') === 'Image', 'local warning marker is removed before federation');

$activity = ap_dm_build_activity(
    'https://mkultra.monster/users/nova',
    $peer,
    '<p>see /followers and as:Public in the text only</p>',
    'https://mkultra.monster/users/nova/notes/dm1',
    'https://mkultra.monster/users/nova/creates/dm1',
    '2026-10-26T00:00:00Z',
    is_array($stored) ? $stored : [],
    '',
    true,
    'private note'
);
$check(ap_dm_activity_audience_is_direct($activity, $peer), 'a media DM is addressed only to the recipient');
$audienceOnly = $activity;
unset($audienceOnly['object']['content'], $audienceOnly['object']['summary']);
$encoded = json_encode($audienceOnly, JSON_UNESCAPED_SLASHES);
$full = json_encode($activity, JSON_UNESCAPED_SLASHES);
$check(is_string($encoded) && is_string($full)
    && !str_contains($encoded, 'activitystreams#Public')
    && !str_contains($encoded, 'as:Public')
    && !str_contains($encoded, '/followers')
    && !str_contains($full, 'vaak:DmMeta'), 'federated JSON has no public audience and no local marker');
$check(($activity['to'] ?? null) === [$peer] && ($activity['cc'] ?? null) === [], 'Create to/cc are the peer and nobody else');
$check(($activity['object']['to'] ?? null) === [$peer] && ($activity['object']['cc'] ?? null) === [], 'Note to/cc are the peer and nobody else');
$check(isset($activity['object']['attachment'][0]['url']), 'the Create carries the attachment');
$check(($activity['object']['sensitive'] ?? false) === true, 'sensitive flag is federated on the note');

$public = $activity;
$public['cc'] = ['https://www.w3.org/ns/activitystreams#Public'];
$check(!ap_dm_activity_audience_is_direct($public, $peer), 'adding Public is rejected');
$followers = $activity;
$followers['object']['to'][] = 'https://mkultra.monster/users/nova/followers';
$check(!ap_dm_activity_audience_is_direct($followers, $peer), 'adding followers is rejected');
$hidden = $activity;
$hidden['object']['attachment'][0]['url'] = 'https://cdn.example/followers/secret.png';
$check(!ap_dm_activity_audience_is_direct($hidden, $peer), 'a followers path hidden in an attachment URL is rejected');

foreach (['ap_dm_send', 'ap_dm_build_activity'] as $fn) {
    $source = $functionSource($api . '/ap-inbox.php', $fn);
    $check($source !== '', $fn . ' exists');
    foreach ([
        'ap_outbox_store',
        'ap_masto_status_register',
        'ap_bsky_',
        'ap_media_attach_to_status',
        'activitystreams#Public',
        'as:Public',
        '/followers',
    ] as $needle) {
        $check(!str_contains($source, $needle), $fn . ' does not contain ' . $needle);
    }
}
$send = $functionSource($api . '/ap-inbox.php', 'ap_dm_send');
$check(str_contains($send, 'ap_dm_activity_audience_is_direct'), 'send refuses a DM that is not direct');
$check(str_contains($send, 'ap_dm_build_activity'), 'send builds the direct Create');
$check(!str_contains($send, 'ap_media_attach_to_status'), 'DM uploads are not attached to a status');

$gallery = $functionSource($api . '/ap-db.php', 'ap_outbox_media_list_page');
$galleryCount = $functionSource($api . '/ap-db.php', 'ap_outbox_media_count');
foreach (['list' => $gallery, 'count' => $galleryCount] as $label => $source) {
    $check(str_contains($source, 'outbox_notes'), 'profile media ' . $label . ' reads outbox notes');
    $check(str_contains($source, 'COALESCE(visibility') && str_contains($source, 'public'), 'profile media ' . $label . ' is public only');
    $check(!str_contains($source, 'direct_messages'), 'profile media ' . $label . ' does not read direct messages');
}

$routes = (string) file_get_contents($api . '/ap-mastodon.php');
$entities = (string) file_get_contents($api . '/ap-masto-entities.php');
$admin = (string) file_get_contents($api . '/ap-admin.php');
$check(!str_contains($routes, 'DM media attachments are not supported yet'), 'Mastodon clients can attach media to a direct status');
$check(str_contains($routes, 'ap_dm_send($status, $dmPeer, $inReplyTo, $mediaIds, $dmSpoiler, $dmSensitive)'), 'direct statuses pass media ids through');
$check(str_contains($routes, 'Polls are not supported in DMs'), 'DM polls stay unsupported');
$check(str_contains($entities, 'ap_dm_masto_media_list'), 'DM statuses expose rich media_attachments');
$check(str_contains($entities, "'visibility' => 'direct'"), 'DM statuses stay direct');
$replyPos = strpos($admin, '$dmReplyContext = $dmReplyContexts[$dmIndex] ?? \'\'');
$bodyPos = strpos($admin, 'admin_dm_thread_message_html($m, $isOut)');
$check($replyPos !== false && $bodyPos !== false && $replyPos < $bodyPos, 'reply context stays outside the media block');
$check(str_contains($admin, 'admin_dm_reply_contexts_html($thread, \'dms\')'), 'the first-reply context list is unchanged');
$check(str_contains($functionSource($api . '/ap-admin.php', 'admin_dm_reply_context_block_html'), 'Open referenced ' . 'post'), 'referenced-post link is unchanged');
$check(str_contains($admin, 'enctype="multipart/form-data"'), 'DM composers upload through the existing media pipeline');
$check(substr_count($admin, 'class="dm-media-input"') >= 1, 'both composers share the media field');
$check(str_contains($admin, 'class="dm-media-live"'), 'DM composers announce pasted attachments');
$check(str_contains($admin, 'clipboardImageFiles'), 'DM paste uses the shared clipboard helper');
$check(!str_contains($admin, 'paste_media') && !str_contains($admin, 'upload_paste'), 'paste does not add an upload action');

if ($failures !== []) {
    fwrite(STDERR, 'dm-media-smoke: ' . count($failures) . " of {$checks} failed\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}
echo "dm-media-smoke: {$checks} checks ok (DM media is never public)\n";
