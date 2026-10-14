<?php
declare(strict_types=1);
/** Timeline quote/boost cards: media stays in the quote, links use the original id, Copy link is present. */
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
    'ap_masto_outer_media_without_quote_duplicates',
    'ap_masto_mention_target_object_id',
]);
require_once dirname(__DIR__) . '/ap-bsky-urls.php';

$checks = 0;
function timeline_quote_assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

function timeline_quote_account(string $acct, string $uri): array
{
    return [
        'acct' => $acct,
        'display_name' => $acct,
        'uri' => $uri,
        'url' => $uri,
        'avatar' => 'https://example.test/a.png',
    ];
}

function timeline_quote_image(string $url): array
{
    return ['type' => 'image', 'url' => $url, 'preview_url' => $url];
}

function timeline_quote_render(array $status, string $view): string
{
    ob_start();
    admin_render_masto_status_card($status, [], $view, false, true);
    return (string) ob_get_clean();
}

function timeline_quote_assert_media(string $html, string $quotedUrl, ?string $parentUrl, string $label): void
{
    $quote = strpos($html, 'quote-block');
    $media = strpos($html, $quotedUrl);
    timeline_quote_assert($quote !== false && $media !== false && $media > $quote, $label . ' quoted media outside quote');
    if ($parentUrl !== null) {
        $parent = strpos($html, $parentUrl);
        timeline_quote_assert($parent !== false && $parent < $quote, $label . ' parent media moved');
    }
}

function timeline_quote_assert_copy(string $html, string $object, string $view, string $label): void
{
    $href = 'https://mkultra.monster/vaak/?view=status&object=' . rawurlencode($object) . '&from=' . rawurlencode($view);
    timeline_quote_assert(str_contains($html, 'data-copy-value="' . h($href) . '"'), $label . ' copy target');
    timeline_quote_assert(str_contains($html, '>Copy link</button>'), $label . ' copy label');
    timeline_quote_assert(!str_contains($html, '#announce-') && !str_contains($html, '%23announce-'), $label . ' boost id leaked');
}

function timeline_quote_assert_open(string $html, string $object, string $view, string $label): void
{
    $href = '?view=status&object=' . rawurlencode($object) . '&from=' . rawurlencode($view);
    timeline_quote_assert(str_contains($html, h($href)), $label . ' open target');
}

$quotePost = 'https://quoter.example/users/quoter/statuses/2';
$original = 'https://origin.example/users/author/statuses/1';
$localNote = 'https://mkultra.monster/users/alice/notes/abc';
$ownNote = 'https://mkultra.monster/users/fixture/notes/ownquote';
$bsky = 'https://bsky.app/profile/did:plc:quoted/post/rkey';
$quotedMedia = 'https://media.test/quoted.jpg';
$parentMedia = 'https://media.test/parent.jpg';
$quoter = timeline_quote_account('quoter@quoter.example', 'https://quoter.example/users/quoter');
$author = timeline_quote_account('author@origin.example', 'https://origin.example/users/author');
$booster = timeline_quote_account('booster@remote.example', 'https://remote.example/users/booster');

$boostOfQuote = [
    'id' => 'announce-wrapper',
    'uri' => $quotePost . '#announce-7',
    'url' => $quotePost . '#announce-7',
    'content' => '',
    'account' => $booster,
    'media_attachments' => [],
    'reblog' => [
        'id' => '2',
        'uri' => $quotePost,
        'url' => $quotePost,
        'content' => '<p>commentary from the quoter</p>',
        'account' => $quoter,
        'media_attachments' => [timeline_quote_image($parentMedia), timeline_quote_image($quotedMedia)],
        'quote' => ['quoted_status' => [
            'id' => '1',
            'uri' => $original,
            'url' => $original,
            'content' => '<p>original with media</p>',
            'account' => $author,
            'media_attachments' => [timeline_quote_image($quotedMedia)],
        ]],
    ],
];
$quoteOfBoost = [
    'id' => 'quote-of-boost',
    'uri' => $quotePost,
    'url' => $quotePost,
    'content' => '<p>commentary from the quoter</p>',
    'account' => $quoter,
    'media_attachments' => [timeline_quote_image($quotedMedia)],
    'quote' => ['quoted_status' => [
        'id' => 'announce-wrapper',
        'uri' => $original . '#announce-9',
        'url' => $original . '#announce-9',
        'content' => '',
        'account' => $booster,
        'media_attachments' => [],
        'reblog' => [
            'id' => '1',
            'uri' => $original,
            'url' => $original,
            'content' => '<p>original with media</p>',
            'account' => $author,
            'media_attachments' => [timeline_quote_image($quotedMedia)],
        ],
    ]],
];
$plainQuote = [
    'id' => 'plain-quote',
    'uri' => $quotePost,
    'url' => $quotePost,
    'content' => '<p>commentary from the quoter</p>',
    'account' => $quoter,
    'media_attachments' => [],
    'quote' => ['quoted_status' => [
        'id' => '1',
        'uri' => $original,
        'url' => $original,
        'content' => '<p>original with media</p>',
        'account' => $author,
        'media_attachments' => [timeline_quote_image($quotedMedia)],
    ]],
];
$bskyQuote = [
    'id' => 'bsky-quote',
    'uri' => $quotePost,
    'url' => $quotePost,
    'content' => '<p>commentary from the quoter</p>',
    'account' => $quoter,
    'media_attachments' => [],
    'quote' => ['quoted_status' => [
        'id' => 'bsky:rkey',
        'uri' => 'at://did:plc:quoted/app.bsky.feed.post/rkey',
        'url' => $bsky,
        'source' => 'bluesky',
        'content' => '<p>bluesky original</p>',
        'account' => timeline_quote_account('quoted.bsky.social', 'https://bsky.app/profile/did:plc:quoted'),
        'media_attachments' => [timeline_quote_image($quotedMedia)],
    ]],
];
$bskyBoostQuote = [
    'id' => 'quote-of-bsky-boost',
    'uri' => $quotePost,
    'url' => $quotePost,
    'content' => '<p>commentary from the quoter</p>',
    'account' => $quoter,
    'media_attachments' => [timeline_quote_image($quotedMedia)],
    'quote' => ['quoted_status' => [
        'content' => '',
        'uri' => 'at://did:plc:booster/app.bsky.feed.post/boost#announce-4',
        'url' => 'https://bsky.app/profile/did:plc:booster/post/boost#announce-4',
        'media_attachments' => [],
        'account' => timeline_quote_account('booster.bsky.social', 'https://bsky.app/profile/did:plc:booster'),
        'reblog' => [
            'uri' => 'at://did:plc:quoted/app.bsky.feed.post/rkey',
            'url' => $bsky,
            'content' => '<p>bluesky original</p>',
            'account' => timeline_quote_account('quoted.bsky.social', 'https://bsky.app/profile/did:plc:quoted'),
            'media_attachments' => [timeline_quote_image($quotedMedia)],
        ],
    ]],
];
$localQuote = [
    'id' => 'local-quote',
    'uri' => $quotePost,
    'url' => $quotePost,
    'content' => '<p>commentary from the quoter</p>',
    'account' => $quoter,
    'media_attachments' => [],
    'quote' => ['quoted_status' => [
        'id' => 'abc',
        'uri' => $localNote,
        'url' => $localNote,
        'content' => '<p>local original</p>',
        'account' => timeline_quote_account('alice', 'https://mkultra.monster/users/alice'),
        'media_attachments' => [timeline_quote_image($quotedMedia)],
    ]],
];
$ownBoost = [
    'id' => 'own-announce',
    'uri' => $ownNote . '#announce-3',
    'url' => $ownNote . '#announce-3',
    'content' => '',
    'account' => timeline_quote_account('fixture', 'https://mkultra.monster/users/fixture'),
    'media_attachments' => [],
    'reblog' => [
        'id' => '8',
        'uri' => $ownNote,
        'url' => $ownNote,
        'content' => '<p>my quote</p>',
        'account' => timeline_quote_account('fixture', 'https://mkultra.monster/users/fixture'),
        'media_attachments' => [timeline_quote_image($quotedMedia)],
        'quote' => ['quoted_status' => [
            'uri' => $original,
            'url' => $original,
            'content' => '<p>original with media</p>',
            'account' => $author,
            'media_attachments' => [timeline_quote_image($quotedMedia)],
        ]],
    ],
];

ap_db()->prepare('INSERT INTO outbox_notes(id,raw_create_json,content,visibility,to_json,cc_json) VALUES(?,?,?,?,?,?)')
    ->execute([$localNote, '', 'local original', 'public', '[]', '[]']);
ap_db()->prepare('INSERT INTO outbox_notes(id,raw_create_json,content,visibility,to_json,cc_json) VALUES(?,?,?,?,?,?)')
    ->execute([$ownNote, '', 'my quote', 'public', '[]', '[]']);

foreach (['home', 'local', 'feed'] as $view) {
    $boosted = timeline_quote_render($boostOfQuote, $view);
    timeline_quote_assert(str_contains($boosted, 'commentary from the quoter'), 'boost of quote commentary ' . $view);
    timeline_quote_assert_media($boosted, $quotedMedia, $parentMedia, 'boost of quote ' . $view);
    timeline_quote_assert_copy($boosted, $quotePost, $view, 'boost of quote ' . $view);
    timeline_quote_assert_open($boosted, $original, $view, 'boost of quote original ' . $view);

    $quotedBoost = timeline_quote_render($quoteOfBoost, $view);
    timeline_quote_assert_media($quotedBoost, $quotedMedia, null, 'quote of boost ' . $view);
    timeline_quote_assert_copy($quotedBoost, $quotePost, $view, 'quote of boost ' . $view);
    timeline_quote_assert_open($quotedBoost, $original, $view, 'quote of boost original ' . $view);

    $plain = timeline_quote_render($plainQuote, $view);
    timeline_quote_assert_media($plain, $quotedMedia, null, 'plain quote ' . $view);
    timeline_quote_assert_copy($plain, $quotePost, $view, 'plain quote ' . $view);
    $plainQuoteAt = strpos($plain, 'quote-block');
    timeline_quote_assert($plainQuoteAt !== false && !str_contains(substr($plain, 0, $plainQuoteAt), $quotedMedia), 'plain quote media outside ' . $view);

    $bluesky = timeline_quote_render($bskyQuote, $view);
    timeline_quote_assert_media($bluesky, $quotedMedia, null, 'bluesky quote ' . $view);
    timeline_quote_assert_copy($bluesky, $quotePost, $view, 'bluesky quote ' . $view);
    timeline_quote_assert_open($bluesky, $bsky, $view, 'bluesky quote original ' . $view);

    $blueskyBoost = timeline_quote_render($bskyBoostQuote, $view);
    timeline_quote_assert_media($blueskyBoost, $quotedMedia, null, 'bluesky boost quote ' . $view);
    timeline_quote_assert_copy($blueskyBoost, $quotePost, $view, 'bluesky boost quote ' . $view);
    timeline_quote_assert_open($blueskyBoost, $bsky, $view, 'bluesky boost quote original ' . $view);

    $local = timeline_quote_render($localQuote, $view);
    timeline_quote_assert_media($local, $quotedMedia, null, 'local quote ' . $view);
    timeline_quote_assert_copy($local, $quotePost, $view, 'local quote ' . $view);
    timeline_quote_assert_open($local, $localNote, $view, 'local quote original ' . $view);

    $own = timeline_quote_render($ownBoost, $view);
    timeline_quote_assert_media($own, $quotedMedia, null, 'own boost ' . $view);
    timeline_quote_assert_copy($own, $ownNote, $view, 'own boost ' . $view);
}

$unresolved = $plainQuote;
$unresolved['media_attachments'] = [timeline_quote_image($parentMedia), timeline_quote_image($quotedMedia)];
$unresolved['quote']['quoted_status']['media_attachments'] = [];
$unresolvedHtml = timeline_quote_render($unresolved, 'home');
$quoteAt = strpos($unresolvedHtml, 'quote-block');
$mediaAt = strpos($unresolvedHtml, $quotedMedia);
timeline_quote_assert($quoteAt !== false && $mediaAt !== false && $mediaAt < $quoteAt, 'unproved parent media moved inside');

$stripped = ap_masto_mention_target_object_id($original . '#announce-11');
timeline_quote_assert($stripped === $original, 'status open id keeps the announce fragment');

echo "timeline-quote-boost-smoke PASS $checks quote boost media link and copy checks\n";
