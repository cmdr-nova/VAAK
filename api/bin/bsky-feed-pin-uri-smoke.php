<?php
declare(strict_types=1);

/**
 * Feed pin URI validation. No Postgres or network. A '~' inside the
 * record-key class once doubled as the regex delimiter, so every pin from
 * Search > Feeds failed with "Invalid feed".
 */

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once dirname(__DIR__) . '/ap-bsky.php';

$checks = 0;
$failures = [];
$check = static function (bool $ok, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$check(ap_bsky_feed_uri_valid('at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.generator/whats-hot'), 'feed generator URI');
$check(ap_bsky_feed_uri_valid('at://did:plc:abc123/app.bsky.graph.list/3kabc2xyz'), 'list URI');
$check(ap_bsky_feed_uri_valid('at://did:web:example.com/app.bsky.feed.generator/a.b_c:d~e-f'), 'rkey with every allowed punctuation');
$check(!ap_bsky_feed_uri_valid('at://did:plc:abc/app.bsky.feed.post/3kabc'), 'post URI is not a feed');
$check(!ap_bsky_feed_uri_valid('https://bsky.app/profile/x/feed/hot'), 'https link is not an AT-URI');
$check(!ap_bsky_feed_uri_valid('at://did:plc:abc/app.bsky.feed.generator/'), 'missing rkey');
$check(!ap_bsky_feed_uri_valid('at://did:plc:abc/app.bsky.feed.generator/hot/extra'), 'extra path segment');
$check(!ap_bsky_feed_uri_valid("at://did:plc:abc/app.bsky.feed.generator/hot\n"), 'trailing newline');

// Saved-feed list edits (pure, no I/O).
$mk = static function (array $rows): array {
    return array_map(static fn ($r) => (object) ['type' => $r[0], 'value' => $r[1], 'pinned' => $r[2], 'id' => 'x' . $r[1]], $rows);
};
$vals = static fn (?array $items): string => $items === null ? 'null'
    : implode(',', array_map(static fn ($i) => $i->value . ($i->pinned ? '*' : ''), $items));
$base = $mk([['timeline', 'following', true], ['feed', 'A', true], ['feed', 'S1', false], ['feed', 'B', true], ['feed', 'S2', false]]);
$check($vals(ap_bsky_saved_feed_items_apply($mk([['timeline', 'following', true], ['feed', 'A', true], ['feed', 'S1', false], ['feed', 'B', true], ['feed', 'S2', false]]), 'up', 'B'))
    === 'following*,B*,S1,A*,S2', 'up swaps with the previous pinned item, skipping saved ones');
$check($vals(ap_bsky_saved_feed_items_apply($mk([['timeline', 'following', true], ['feed', 'A', true], ['feed', 'S1', false], ['feed', 'B', true], ['feed', 'S2', false]]), 'down', 'S1'))
    === 'following*,A*,S2,B*,S1', 'down swaps with the next saved item');
$check($vals(ap_bsky_saved_feed_items_apply($mk([['timeline', 'following', true], ['feed', 'A', true]]), 'up', 'following'))
    === 'following*,A*', 'up at the top is a no-op');
$check($vals(ap_bsky_saved_feed_items_apply($base, 'remove', 'A')) === 'following*,S1,B*,S2', 'remove drops the item');
$check($vals(ap_bsky_saved_feed_items_apply($mk([['feed', 'S1', false]]), 'pin', 'S1')) === 'S1*', 'pin flips a saved item');
$check($vals(ap_bsky_saved_feed_items_apply($mk([['feed', 'A', true]]), 'unpin', 'A')) === 'A', 'unpin keeps it saved');
$new = ap_bsky_saved_feed_items_apply($mk([['feed', 'A', true]]), 'pin', 'at://did:plc:x/app.bsky.graph.list/l1');
$check(is_array($new) && count($new) === 2 && $new[1]->type === 'list' && $new[1]->pinned === true && strlen((string) $new[1]->id) === 14,
    'pin adds a missing list as type list with a fresh id');
$check(ap_bsky_saved_feed_items_apply($mk([['feed', 'A', true]]), 'remove', 'Z') === null, 'remove of a missing feed reports it');
$check(json_encode(['preferences' => [(object) ['$type' => 't', 'x' => new stdClass()]]]) === '{"preferences":[{"$type":"t","x":{}}]}',
    'object prefs keep {} when encoded for putPreferences');

// Background feed sampler: round-robin over saved feeds, pinned or not.
$fa = 'at://did:plc:abc/app.bsky.feed.generator/a';
$fb = 'at://did:plc:abc/app.bsky.graph.list/b';
$items = [['type' => 'timeline', 'value' => 'following', 'pinned' => true], ['type' => 'feed', 'value' => $fa, 'pinned' => true], ['type' => 'list', 'value' => $fb, 'pinned' => false], ['type' => 'feed', 'value' => $fa, 'pinned' => false]];
$check(ap_bsky_feed_sample_pick($items, 0) === $fa && ap_bsky_feed_sample_pick($items, 1) === $fb && ap_bsky_feed_sample_pick($items, 2) === $fa,
    'sampler cycles saved feeds and lists, skipping Following and duplicates');
$check(ap_bsky_feed_sample_pick([['type' => 'timeline', 'value' => 'following']], 3) === null, 'sampler has nothing to do with only Following');
$check(ap_bsky_feed_sample_pick([['value' => "at://did:plc:abc/app.bsky.feed.generator/a\n"]], 0) === null, 'sampler refuses invalid URIs');

// Sampled feed posts become Home picks: top-level posts only, newest first, capped.
$now = 1_800_000_000;
$post = static fn (string $rk, array $extra = []): array => array_merge(['post' => ['uri' => "at://did:plc:xyz/app.bsky.feed.post/{$rk}", 'record' => []]], $extra);
$doc = ap_bsky_feed_picks_merge(null, [
    $post('p1'),
    $post('p2', ['reply' => ['parent' => []]]),
    $post('p3', ['reason' => ['$type' => 'app.bsky.feed.defs#reasonRepost']]),
    ['post' => ['uri' => 'at://did:plc:xyz/app.bsky.feed.post/p4', 'record' => ['reply' => []]]],
    ['post' => ['uri' => 'https://example.com/p5']],
    $post('p1'),
], $fa, $now);
$check(array_column($doc['items'], 'uri') === ['at://did:plc:xyz/app.bsky.feed.post/p1'] && $doc['items'][0]['feed'] === $fa,
    'feed picks keep top-level posts once and drop replies, reposts and non-post URIs');
$old = ['items' => [['uri' => 'at://did:plc:xyz/app.bsky.feed.post/old', 'feed' => $fb, 'at' => $now - AP_BSKY_FEED_PICKS_TTL - 1], ['uri' => 'at://did:plc:xyz/app.bsky.feed.post/p1', 'feed' => $fb, 'at' => $now - 60], ['uri' => 'at://did:plc:xyz/app.bsky.feed.post/kept', 'feed' => $fb, 'at' => $now - 60]]];
$doc = ap_bsky_feed_picks_merge($old, [$post('p1'), $post('p6')], $fa, $now);
$check(array_column($doc['items'], 'uri') === ['at://did:plc:xyz/app.bsky.feed.post/p1', 'at://did:plc:xyz/app.bsky.feed.post/p6', 'at://did:plc:xyz/app.bsky.feed.post/kept'],
    'feed picks put fresh posts first, dedupe against old picks and expire stale ones');
$many = [];
for ($i = 0; $i < AP_BSKY_FEED_PICKS_KEEP + 10; $i++) {
    $many[] = $post('m' . $i);
}
$check(count(ap_bsky_feed_picks_merge(null, $many, $fa, $now)['items']) === AP_BSKY_FEED_PICKS_KEEP, 'feed picks are capped');

if ($failures !== []) {
    fwrite(STDERR, "bsky-feed-pin-uri-smoke FAILED: " . implode('; ', $failures) . "\n");
    exit(1);
}
echo "bsky-feed-pin-uri-smoke ok checks={$checks}\n";
