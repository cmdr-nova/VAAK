<?php
declare(strict_types=1);
/**
 * Quote posts keep the quoting post's media outside the quote card and the
 * quoted post's media inside it. Summary stubs (the flattened Ambipom case)
 * take the parent's files; a loaded empty attachment list does not.
 */
ob_start();
require __DIR__ . '/migration-card-reference-capture.php';
$corpus = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($corpus['captures']['plain']['html'] ?? null) && !is_string($corpus['captures']['plain']['html'] ?? null)) {
    throw new RuntimeException('card fixture did not boot');
}
reference_load(dirname(__DIR__) . '/ap-masto-entities.php', [
    'ap_masto_strip_announce_fragment',
    'ap_masto_status_is_boost_wrapper',
    'ap_masto_quoted_display_status',
    'ap_masto_attachment_asset_urls',
    'ap_masto_outer_media_without_quote_duplicates',
    'ap_masto_quoted_media_is_authoritative',
    'ap_masto_rehome_flattened_quote_media',
]);
if (!function_exists('ap_fix_utf8')) {
    function ap_fix_utf8(?string $s): string
    {
        return $s ?? '';
    }
}
if (!function_exists('ap_format_quote_feed_summary')) {
    reference_load(dirname(__DIR__) . '/ap-inbox.php', [
        'ap_as_id',
        'ap_unwrap_as2_object',
        'ap_quote_is_tombstone',
        'ap_text_looks_like_as2_json',
        'ap_format_quote_feed_summary',
    ]);
}

$checks = 0;
function layout_assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

function layout_account(string $acct, string $uri): array
{
    return [
        'acct' => $acct,
        'display_name' => $acct,
        'uri' => $uri,
        'url' => $uri,
        'avatar' => 'https://example.test/a.png',
    ];
}

function layout_file(string $url, string $type = 'image', ?string $remote = null): array
{
    $item = ['type' => $type, 'url' => $url, 'preview_url' => $url];
    if ($remote !== null) {
        $item['remote_url'] = $remote;
    }
    return $item;
}

function layout_render(array $status, string $view): string
{
    $previous = $GLOBALS['view'] ?? null;
    if ($view === 'status') {
        $GLOBALS['view'] = 'status';
    }
    ob_start();
    admin_render_masto_status_card($status, [], $view === 'status' ? 'home' : $view, false, true);
    $html = (string) ob_get_clean();
    if ($previous === null) {
        unset($GLOBALS['view']);
    } else {
        $GLOBALS['view'] = $previous;
    }
    return $html;
}

function layout_quote_at(string $html): int|false
{
    return strpos($html, 'quote-block');
}

function layout_assert_inside(string $html, string $url, string $label): void
{
    $quote = layout_quote_at($html);
    $media = strpos($html, $url);
    layout_assert($quote !== false && $media !== false && $media > $quote, $label . ' quoted media is outside the quote card');
}

function layout_assert_outside(string $html, string $url, string $label): void
{
    $quote = layout_quote_at($html);
    $media = strpos($html, $url);
    layout_assert($quote !== false && $media !== false && $media < $quote, $label . ' quoting post media moved inside the quote card');
}

$quoter = layout_account('roddie@roddie.social', 'https://roddie.social/users/roddie');
$quoted = layout_account('EveryPkmnCard@mastodon.social', 'https://mastodon.social/users/EveryPkmnCard');
$card = 'https://media.test/ambipom.jpg';

$summary = [
    'id' => 'quote-summary',
    'uri' => 'https://roddie.social/users/roddie/statuses/1',
    'url' => 'https://roddie.social/users/roddie/statuses/1',
    'content' => '<p>Paint me like one of your French girls</p>',
    'account' => $quoter,
    'media_attachments' => [layout_file($card)],
    'quote' => [
        'state' => 'accepted',
        'quoted_status' => [
            'source' => 'quote-summary',
            'id' => 'https://mastodon.social/@EveryPkmnCard/9',
            'uri' => 'https://mastodon.social/@EveryPkmnCard/9',
            'url' => 'https://mastodon.social/@EveryPkmnCard/9',
            'content' => '<p>Ambipom</p>',
            'account' => $quoted,
            'media_attachments' => [],
        ],
    ],
];
foreach (['home', 'local', 'feed', 'status'] as $view) {
    $html = layout_render($summary, $view);
    layout_assert_inside($html, $card, 'summary ' . $view);
    $quoteAt = layout_quote_at($html);
    layout_assert($quoteAt !== false && strpos($html, 'Ambipom') > $quoteAt, 'summary text left the quote on ' . $view);
    layout_assert(strpos($html, 'French girls') !== false && strpos($html, 'French girls') < $quoteAt, 'quoting text lost on ' . $view);
    layout_assert(strpos(substr($html, 0, (int) $quoteAt), 'media-row') === false, 'summary file painted as the quoting post on ' . $view);
}

$loadedEmpty = $summary;
$loadedEmpty['media_attachments'] = [layout_file('https://media.test/mine.jpg')];
$loadedEmpty['quote']['quoted_status']['source'] = 'fediverse';
$loadedEmpty['quote']['quoted_status']['content'] = '<p>text only</p>';
$loadedEmpty['quote']['quoted_status']['media_attachments'] = [];
$loadedHtml = layout_render($loadedEmpty, 'home');
layout_assert_outside($loadedHtml, 'https://media.test/mine.jpg', 'loaded empty quote');

$split = $summary;
$split['media_attachments'] = [layout_file('https://media.test/mine.jpg'), layout_file($card)];
$split['quote']['quoted_status']['source'] = 'fediverse';
$split['quote']['quoted_status']['media_attachments'] = [layout_file($card)];
$splitHtml = layout_render($split, 'home');
layout_assert_outside($splitHtml, 'https://media.test/mine.jpg', 'distinct parent');
layout_assert_inside($splitHtml, $card, 'distinct quoted');

$cdn = 'https://cdn.test/card.jpg';
$original = 'https://origin.test/card.jpg';
$rewritten = $summary;
$rewritten['media_attachments'] = [layout_file($cdn, 'image', $original)];
$rewritten['quote']['quoted_status']['source'] = 'fediverse';
$rewritten['quote']['quoted_status']['media_attachments'] = [layout_file($original)];
$cdnHtml = layout_render($rewritten, 'home');
layout_assert_inside($cdnHtml, $original, 'remote_url identity');
$quoteAt = layout_quote_at($cdnHtml);
$cdnAt = strpos($cdnHtml, $cdn);
layout_assert($cdnAt === false || $cdnAt > $quoteAt, 'cdn copy stayed on the quoting post');

$kinds = $summary;
$kinds['quote']['quoted_status']['content'] = '';
$kinds['quote']['quoted_status']['sensitive'] = true;
$kinds['quote']['quoted_status']['spoiler_text'] = 'Card back';
$kinds['media_attachments'] = [
    layout_file('https://media.test/one.jpg', 'image'),
    layout_file('https://media.test/two.mp4', 'gifv'),
    layout_file('https://media.test/three.mp4', 'video'),
    layout_file('https://media.test/four.mp3', 'audio'),
];
$kindsHtml = layout_render($kinds, 'home');
$quoteAt = layout_quote_at($kindsHtml);
layout_assert($quoteAt !== false, 'textless quote card missing');
foreach (['https://media.test/one.jpg', 'https://media.test/two.mp4', 'https://media.test/three.mp4', 'https://media.test/four.mp3'] as $url) {
    layout_assert_inside($kindsHtml, $url, $url);
}
layout_assert(strpos($kindsHtml, 'Card back') > $quoteAt, 'quoted warning left the card');
layout_assert(strpos(substr($kindsHtml, 0, (int) $quoteAt), 'media-row') === false, 'textless files painted outside');

$record = [
    'id' => 'record-with-media',
    'uri' => 'https://roddie.social/users/roddie/statuses/2',
    'url' => 'https://roddie.social/users/roddie/statuses/2',
    'content' => '<p>own words</p>',
    'account' => $quoter,
    'media_attachments' => [layout_file('https://media.test/own.jpg')],
    'quote' => [
        'state' => 'accepted',
        'quoted_status' => [
            'source' => 'bluesky',
            'id' => 'bsky:rkey',
            'uri' => 'at://did:plc:quoted/app.bsky.feed.post/rkey',
            'url' => 'https://bsky.app/profile/did:plc:quoted/post/rkey',
            'content' => '<p>quoted record</p>',
            'account' => layout_account('quoted.bsky.social', 'https://bsky.app/profile/did:plc:quoted'),
            'media_attachments' => [layout_file('https://media.test/quoted.jpg')],
        ],
    ],
];
$recordHtml = layout_render($record, 'home');
layout_assert_outside($recordHtml, 'https://media.test/own.jpg', 'recordWithMedia outer');
layout_assert_inside($recordHtml, 'https://media.test/quoted.jpg', 'recordWithMedia quoted');

$target = 'https://mastodon.social/@EveryPkmnCard/99';
$formatted = ap_format_quote_feed_summary(
    'Paint me like one of your French girls',
    ['type' => 'Note', 'content' => '<p>Ambipom</p>', 'attributedTo' => 'https://mastodon.social/users/EveryPkmnCard'],
    $target,
    false
);
layout_assert(is_string($formatted) && str_contains($formatted, 'RE: ' . $target), 'summary keeps the quote URL');
layout_assert(is_string($formatted) && str_contains($formatted, 'Ambipom'), 'summary keeps the quoted text');
$again = ap_format_quote_feed_summary('RE: ' . $target . "\n\nhello", ['content' => 'Ambipom'], $target, false);
layout_assert(is_string($again) && substr_count($again, 'RE: ' . $target) === 1, 'summary does not repeat the quote URL');

echo "quote-media-layout-smoke PASS {$checks} quote media placement checks\n";
