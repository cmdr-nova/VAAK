<?php
/** Trending post rows: media-only posts get a small thumbnail and a real label, never "(media)". */
declare(strict_types=1);

require __DIR__ . '/migration-reference-functions.php';

$checks = 0;
function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
}

reference_load(dirname(__DIR__) . '/ap-admin.php', ['admin_trend_status_media']);

$img = static fn(string $type = 'image', string $alt = ''): array => [
    'type' => $type, 'url' => 'https://cdn.test/full.png', 'preview_url' => 'https://cdn.test/small.png', 'description' => $alt,
];

check(admin_trend_status_media(['content' => 'hi']) === null, 'text post has no media row');
$m = admin_trend_status_media(['media_attachments' => [$img('image', "  A  cat\non a desk ")]]);
check($m['thumb'] === 'https://cdn.test/small.png', 'thumbnail uses preview_url, not the original');
check($m['description'] === 'A cat on a desk', 'alt text collapses whitespace');
check($m['label'] === 'Image' && $m['count'] === 1, 'single image label');
check(admin_trend_status_media(['media_attachments' => [$img(), $img(), $img()]])['label'] === '3 images', 'image count');
check(admin_trend_status_media(['media_attachments' => [$img('video'), $img()]])['label'] === 'Video +1', 'video with extras');
check(admin_trend_status_media(['media_attachments' => [$img('gifv')]])['label'] === 'GIF', 'gifv label');
$s = admin_trend_status_media(['sensitive' => true, 'media_attachments' => [$img('image', 'nsfw alt')]]);
check($s['thumb'] === '' && $s['description'] === '' && $s['label'] === 'Image (sensitive)', 'sensitive media shows no thumb or alt');
check(admin_trend_status_media(['spoiler_text' => 'cw', 'media_attachments' => [$img()]])['thumb'] === '', 'CW hides thumb');
check(admin_trend_status_media(['media_attachments' => [['type' => 'image', 'preview_url' => 'http://x.test/a.png']]])['thumb'] === '', 'non-https preview dropped');
check(admin_trend_status_media(['media_attachments' => [$img('audio')]])['thumb'] === '', 'audio has no thumb');

echo "trend-post-media-smoke: {$checks} checks ok\n";
