<?php
declare(strict_types=1);
/** Quote-card references for Library step 3. No network and no Postgres.
 *  The card capture boots the real painters against in-memory SQLite. */
if (!extension_loaded('mbstring')) {
    fwrite(STDERR, "Quote preview capture requires mbstring\n");
    exit(2);
}
ob_start();
require __DIR__ . '/migration-card-reference-capture.php';
ob_end_clean();

function quote_case(string $id, array $status, int $budget, array $extra = []): array
{
    unset($GLOBALS['admin_quote_link_preview_budget']);
    if ($extra['set_budget'] ?? true) {
        $GLOBALS['admin_quote_link_preview_budget'] = $budget;
    } else {
        unset($GLOBALS['admin_quote_link_preview_budget']);
    }
    $before = $GLOBALS['admin_quote_link_preview_budget'] ?? null;
    $opts = admin_quote_opts_from_status($status, (string) ($status['uri'] ?? $status['url'] ?? ''));
    $html = admin_quote_card_html($opts, 'favourites');
    $after = $GLOBALS['admin_quote_link_preview_budget'] ?? null;
    return [
        'id' => $id,
        'kind' => 'quoted',
        'status' => $status,
        'budget_before' => $before,
        'budget_after' => $after,
        'html' => $html,
    ];
}

$article = 'https://article.test/story';
ap_db()->prepare('INSERT INTO link_preview_cards(url,title,description,image,provider_name,provider_url,type,status,fetched_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
    ->execute([$article, 'Cached story', 'A cached article', 'https://article.test/story.jpg', 'Article Test', 'https://article.test', 'link', 'ok', gmdate('c'), gmdate('c', time() + 86400)]);

$base = [
    'id' => 'https://quoted.test/posts/q',
    'uri' => 'https://quoted.test/users/bob/statuses/9',
    'url' => 'https://quoted.test/users/bob/statuses/9',
    'visibility' => 'public',
    'account' => ['uri' => 'https://quoted.test/users/bob', 'acct' => 'bob@quoted.test'],
    'media_attachments' => [],
];
$cases = [];
$cases[] = quote_case('plain_no_url', array_replace($base, ['content' => 'Actual current quote body']), 2);
$cases[] = quote_case('plain_unset_budget', array_replace($base, ['content' => 'Actual current quote body']), 2, ['set_budget' => false]);
$cases[] = quote_case('plain_budget_zero', array_replace($base, ['content' => 'Actual current quote body']), 0);
$cases[] = quote_case('supplied_media', array_replace($base, [
    'content' => 'Quoted with a picture',
    'media_attachments' => [['type' => 'image', 'url' => 'https://media.test/quoted.jpg', 'preview_url' => 'https://media.test/quoted.jpg']],
]), 2);
$cases[] = quote_case('supplied_card', array_replace($base, [
    'content' => 'Quoted with a card',
    'card' => ['url' => 'https://given.test/card', 'title' => 'Given card', 'description' => 'Already attached', 'provider_name' => 'Given'],
]), 2);
$cases[] = quote_case('cached_article', array_replace($base, [
    'content' => 'Read ' . $article . ' today',
]), 2);
$cases[] = quote_case('youtube_quote', array_replace($base, [
    'content' => 'Watch https://www.youtube.com/watch?v=abcdefghijk',
]), 2);

$cases[] = [
    'id' => 'cold_status',
    'kind' => 'opts',
    'opts' => ['url' => 'https://quoted.test/users/bob/statuses/missing', 'text' => '', 'open_label' => 'Quoted post (not cached yet) — open'],
    'html' => admin_quote_card_html(['url' => 'https://quoted.test/users/bob/statuses/missing', 'text' => '', 'open_label' => 'Quoted post (not cached yet) — open'], 'favourites'),
];
$cases[] = [
    'id' => 'unavailable_link',
    'kind' => 'opts',
    'opts' => ['text' => 'Quoted post unavailable', 'url' => 'https://www.youtube.com/watch?v=abcdefghijk', 'open_external' => true, 'open_label' => 'Open original'],
    'html' => admin_quote_card_html(['text' => 'Quoted post unavailable', 'url' => 'https://www.youtube.com/watch?v=abcdefghijk', 'open_external' => true, 'open_label' => 'Open original'], 'favourites'),
];
$bsky = [
    'handle' => 'alice.test',
    'text' => 'Bluesky quoted line',
    'url' => 'https://bsky.app/profile/alice.test/post/abc123',
    'media' => [['type' => 'image', 'url' => 'https://cdn.test/bsky-quote.jpg', 'preview_url' => 'https://cdn.test/bsky-quote.jpg']],
    'sensitive' => true,
];
$cases[] = [
    'id' => 'bsky_preview',
    'kind' => 'bsky',
    'preview' => $bsky,
    'html' => admin_quote_card_html(admin_quote_opts_from_bsky($bsky), 'favourites'),
];
$cases[] = [
    'id' => 'qt_words',
    'kind' => 'opts',
    'opts' => ['acct' => '@carol', 'text' => 'quoted words', 'url' => '', 'open_label' => 'Open quoted'],
    'html' => admin_quote_card_html(['acct' => '@carol', 'text' => 'quoted words', 'url' => '', 'open_label' => 'Open quoted'], 'favourites'),
];

echo json_encode(['cases' => $cases], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
