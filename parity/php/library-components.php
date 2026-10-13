<?php
declare(strict_types=1);
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__, 2);
ob_start();
require $root . '/api/bin/migration-library-component-capture.php';
$raw = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
reference_load($root . '/api/ap-admin.php', ['admin_format_compact_count']);

$cases = [];
$now = $raw['now'];
$owner = 'https://mkultra.monster/users/fixture';

foreach ($raw['reply_cw'] as $i => $row) {
    $cases[] = [
        'id' => 'reply-cw-' . $i,
        'input' => ['section' => 'reply_cw', 'cw' => $row['cw'], 'sensitive' => $row['sensitive']],
        'output' => $row['query'],
    ];
}
foreach ($raw['cw_whitespace'] as $name => $row) {
    $cases[] = [
        'id' => 'cw-' . $name,
        'input' => ['section' => 'cw', 'cw' => $row['cw'], 'sensitive' => $row['sensitive'], 'auto' => $row['auto']],
        'output' => $row['html'],
    ];
}
foreach ($raw['times'] as $name => $row) {
    $cases[] = [
        'id' => 'time-' . $name,
        'input' => ['section' => 'time', 'input' => $row['input'], 'now' => $now],
        'output' => $row['html'],
    ];
}
foreach ($raw['media'] as $name => $row) {
    $cases[] = [
        'id' => 'media-' . $name,
        'input' => ['section' => 'media', 'items' => $row['items'], 'lazy' => $row['lazy']],
        'output' => $row['html'],
    ];
}
foreach ($raw['accountrefs'] as $name => $row) {
    $cases[] = [
        'id' => 'account-' . $name,
        'input' => ['section' => 'account_ref', 'account' => $row['account']],
        'output' => $row['actor'],
    ];
}
foreach ($raw['replies'] as $name => $row) {
    $cases[] = [
        'id' => 'reply-meta-' . $name,
        'input' => ['section' => 'reply_meta', 'status' => $row['status']],
        'output' => $row['html'],
    ];
}
foreach ($raw['asks'] as $name => $row) {
    $cases[] = [
        'id' => 'ask-' . $name,
        'input' => ['section' => 'ask', 'row' => $row['row']],
        'output' => $row['html'],
    ];
}
foreach ($raw['previews'] as $name => $row) {
    $cases[] = [
        'id' => 'preview-' . $name,
        'input' => ['section' => 'preview', 'card' => $row['card'], 'interactive' => $row['interactive']],
        'output' => $row['html'],
    ];
}
foreach (['quotes' => false, 'rss_quotes' => true] as $bucket => $rss) {
    foreach ($raw[$bucket] as $name => $row) {
        $cases[] = [
            'id' => ($rss ? 'rss-quote-' : 'quote-') . $name,
            'input' => [
                'section' => 'quote',
                'status' => $row['status'],
                'auto' => $row['auto'] ?? false,
                'row' => $row['row'] ?? null,
                'now' => $now,
            ],
            'output' => $row['html'],
        ];
    }
}
foreach ($raw['rss_titles'] as $i => $row) {
    $cases[] = [
        'id' => 'rss-title-' . $i,
        'input' => ['section' => 'rss_title', 'input' => $row['input']],
        'output' => $row['title'],
    ];
}
foreach ($raw['rss_summaries'] as $i => $row) {
    $cases[] = [
        'id' => 'rss-summary-' . $i,
        'input' => ['section' => 'rss_summary', 'input' => $row['input']],
        'output' => $row['summary'],
    ];
}
foreach ($raw['rss'] as $name => $row) {
    $cases[] = [
        'id' => 'rss-status-' . $name,
        'input' => ['section' => 'rss_status', 'row' => $row['row'], 'owner' => 1],
        'output' => $row['status'],
    ];
}
foreach ($raw['widths'] as $i => $row) {
    $cases[] = [
        'id' => 'width-' . $i,
        'input' => ['section' => 'width', 'text' => $row['text']],
        'output' => ['width' => $row['width'], 'trim' => $row['trim']],
    ];
}
foreach ($raw['url_aliases'] as $i => $row) {
    $cases[] = [
        'id' => 'alias-' . $i,
        'input' => ['section' => 'alias', 'a' => $row['a'], 'b' => $row['b']],
        'output' => $row['equivalent'],
    ];
}
foreach ($raw['emojis'] as $name => $row) {
    $cases[] = [
        'id' => 'emoji-' . $name,
        'input' => ['section' => 'emoji', 'text' => $row['text'], 'display' => $row['display'], 'map' => $row['map']],
        'output' => $row['html'],
    ];
}
foreach ($raw['reply'] as $i => $row) {
    $cases[] = [
        'id' => 'reply-handle-' . $i,
        'input' => [
            'section' => 'reply_handle',
            'actor' => $row['actor'],
            'hint' => $row['hint'],
            'owner_actor' => $owner,
        ],
        'output' => $row['handle'],
    ];
}
foreach ([-3, 0, 1, 999, 1000, 1999, 10999, 1000000, 1999999] as $n) {
    $cases[] = [
        'id' => 'count-' . $n,
        'input' => ['section' => 'count', 'n' => $n],
        'output' => admin_format_compact_count($n),
    ];
}

echo json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
