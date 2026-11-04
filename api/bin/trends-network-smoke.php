<?php
/**
 * Across networks trends (snapshots from vaak-worker trends-service):
 * one Trending tags card holding tags, Bluesky topics and trending posts;
 * viewer filters apply, Nostr only for npub-linked viewers, Bluesky topics
 * honor muted words and open their feed, and a missing snapshot degrades to
 * an empty card. Also the Who to follow card.
 */
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

$GLOBALS['snap'] = [];
$GLOBALS['nostr_linked'] = false;
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function ap_redis_json_get(string $key): ?array
{
    $part = substr($key, strlen('vaak:trends:net:v1:'));
    return str_starts_with($key, 'vaak:trends:net:v1:') ? ($GLOBALS['snap'][$part] ?? null) : null;
}
function ap_masto_trend_item_hidden(array $item, int $owner, array $actors = [], bool $dep = true): bool { return ($item['name'] ?? '') === 'blockedtag'; }
function ap_muted_words_match(int $owner, string ...$texts): ?string
{
    foreach ($texts as $t) if (stripos($t, 'spoilers') !== false) return 'spoilers';
    return null;
}
function ap_nostr_search_visible(int $owner): bool { return $GLOBALS['nostr_linked']; }
function admin_trend_nostr_hidden_keys(int $owner): array { return ['bad' => true]; }
function admin_trend_downrank_ref_set(int $owner): array { return []; }
function admin_trend_status_hidden(array $item, int $owner, array $refs, int $depth = 0): bool { return ($item['account']['acct'] ?? '') === 'blocked@x.social'; }
function admin_status_href(string $url, string $from = 'home'): string { return '?view=status&url=' . rawurlencode($url) . '&from=' . $from; }
$GLOBALS['bsky_connected'] = false;
function admin_bsky_connected(): bool { return $GLOBALS['bsky_connected']; }
function ap_auth_csrf_token(): string { return 'tok'; }

reference_load(dirname(__DIR__) . '/ap-admin.php', [
    'admin_trends_network_snapshot', 'admin_trends_network_data_gen', 'admin_trends_network_sources_label',
    'admin_trends_network_html', 'admin_trend_sparkline_svg', 'admin_trend_status_row_html', 'admin_trend_strim',
    'admin_trend_status_media', 'admin_trend_bsky_topic_link', 'admin_who_to_follow_html',
]);

$tag = static fn(string $name, int $people, array $hosts): array => [
    'name' => $name, 'history' => [['day' => '200', 'uses' => (string) ($people * 2), 'accounts' => (string) $people]], 'vaak_trend_hosts' => $hosts,
];
$post = static fn(string $uri, string $acct, string $text): array => [
    'id' => $uri, 'uri' => $uri, 'url' => $uri, 'created_at' => gmdate('c'), 'content' => '<p>' . $text . '</p>',
    'account' => ['acct' => $acct], 'media_attachments' => [], 'favourites_count' => 3,
];

// Nothing written yet: empty cards, no errors.
$html = admin_trends_network_html('tags', 'home', 7);
check(str_contains($html, 'data-trend-module="tags"') && str_contains($html, 'No fediverse tags yet'), 'empty tags card');
check(str_contains($html, 'Trending posts') && str_contains($html, 'No network posts yet'), 'empty posts section inside the tags card');
check(admin_trends_network_html('statuses', 'home', 7) === '', 'no separate network posts card');

$GLOBALS['snap'] = [
    'tags' => ['generated_at' => time(), 'sources' => ['mastodon.social', 'mas.to'], 'items' => [
        $tag('Cats', 12, ['mastodon.social', 'mas.to']), $tag('blockedtag', 99, ['mas.to']), $tag('2026', 50, ['mas.to']), $tag('Solo', 1, ['mas.to']),
    ]],
    'bsky_topics' => ['items' => [
        ['display_name' => 'World Series', 'topic' => 'world series', 'post_count' => 12345, 'status' => 'hot',
            'link' => 'https://bsky.app/profile/trending.bsky.app/feed/665497821', 'feed_uri' => 'at://did:plc:abc123/app.bsky.feed.generator/665497821'],
        ['display_name' => 'Show spoilers', 'topic' => 'spoilers', 'post_count' => 4],
    ]],
    'statuses' => ['sources' => ['mastodon.social'], 'items' => [
        $post('https://mastodon.social/@a/1', 'a@mastodon.social', 'hello fediverse'),
        $post('https://x.social/@b/2', 'blocked@x.social', 'should not show'),
    ]],
    'nostr' => ['items' => [
        $post('https://njump.me/note1', 'npub1good', 'nostr hello') + ['vaak_nostr_pubkey' => 'good'],
        $post('https://njump.me/note2', 'npub1bad', 'muted key') + ['vaak_nostr_pubkey' => 'bad'],
    ]],
];
$GLOBALS['snap']['nostr']['items'][0]['id'] = 'nostr:' . str_repeat('a', 64);
$GLOBALS['snap']['nostr']['items'][1]['id'] = 'nostr:' . str_repeat('b', 64);

$html = admin_trends_network_html('tags', 'home', 7);
check(str_contains($html, '#Cats') && str_contains($html, '12 people today · 2 servers'), 'merged tag with people and server count');
check(str_contains($html, '1 person today · 1 server'), 'singular labels');
check(!str_contains($html, 'blockedtag'), 'viewer-hidden tag left out');
check(!str_contains($html, '#2026'), 'digit-only tag left out');
check(str_contains($html, 'Bluesky topics') && str_contains($html, 'World Series') && str_contains($html, '12,345 posts on Bluesky · hot'), 'Bluesky topic row');
check(!str_contains($html, 'Show spoilers'), 'muted word hides a Bluesky topic');
check(!str_contains($html, 'view=search&amp;q=World'), 'topic no longer opens an empty VAAK search');
check(str_contains($html, 'href="https://bsky.app/profile/trending.bsky.app/feed/665497821" target="_blank"'), 'topic opens bsky.app without a Bluesky connection');
check(str_contains($html, 'From mastodon.social and 1 more'), 'sources line');
check(str_contains($html, 'hello fediverse') && !str_contains($html, 'should not show'), 'viewer filters apply to network posts');
check(strpos($html, 'Bluesky topics') < strpos($html, 'Trending posts') && strpos($html, 'Trending posts') < strpos($html, 'hello fediverse'), 'tags, topics, then posts');
check(str_contains($html, 'trend-post-row') && str_contains($html, 'a@mastodon.social · 3 likes'), 'post row shows who and likes like a topic row');
check(!str_contains($html, 'nostr hello'), 'no Nostr without a linked npub');

$GLOBALS['bsky_connected'] = true;
$html = admin_trends_network_html('tags', 'home', 7);
check(str_contains($html, 'data-vaak-soft-nav="bluesky" data-bsky-feed="at://did:plc:abc123/app.bsky.feed.generator/665497821"')
    && str_contains($html, '?view=bluesky&amp;feed=at%3A%2F%2Fdid%3Aplc%3Aabc123'), 'connected viewers open the topic feed in VAAK');
$bad = admin_trend_bsky_topic_link(['feed_uri' => 'javascript:alert(1)', 'link' => 'javascript:x'], 'X');
check(!str_contains($bad, 'javascript') && str_contains($bad, 'https://bsky.app/search?q=X'), 'odd feed or link values fall back to bsky.app search');

$GLOBALS['nostr_linked'] = true;
$html = admin_trends_network_html('tags', 'home', 7);
check(str_contains($html, 'nostr hello') && !str_contains($html, 'muted key'), 'Nostr for linked viewers, hidden keys out');
check(str_contains($html, 'href="https://njump.me/note1" target="_blank"'), 'Nostr notes open the note viewer');
check(str_contains($html, 'From mastodon.social and 2 more'), 'nostr.wine named as a source');

// Who to follow: the whole pool ships, three rows show.
$sug = static fn(string $n): array => ['account' => ['uri' => 'https://x.social/users/' . $n, 'display_name' => 'Name ' . $n, 'acct' => $n . '@x.social', 'avatar' => 'https://x.social/a/' . $n . '.png']];
check(admin_who_to_follow_html([]) === '', 'no suggestions, no card');
$pool = array_map($sug, range(1, 14));
$pool[] = $sug('1');
$pool[] = ['account' => ['display_name' => 'no uri']];
$html = admin_who_to_follow_html($pool);
check(str_contains($html, 'data-trend-module="follow"') && str_contains($html, '<h3>Who to follow</h3>'), 'Who to follow card');
check(substr_count($html, '<article class="wtf-row"') === 12, 'pool capped at 12, duplicates and uri-less rows dropped');
check(substr_count($html, '<article class="wtf-row">') === 3, 'three rows visible');
check(str_contains($html, 'name="action" value="suggestion_follow"') && str_contains($html, 'value="https://x.social/users/'), 'Follow posts the actor uri');
check(str_contains($html, '@3@x.social') || str_contains($html, '@4@x.social'), 'handles shown with @');
$two = admin_who_to_follow_html([$sug('a'), $sug('b')]);
check(substr_count($two, '<article class="wtf-row">') === 2 && !str_contains($two, ' hidden'), 'small pool shows everyone');

echo "trends-network-smoke: {$checks} checks ok\n";
