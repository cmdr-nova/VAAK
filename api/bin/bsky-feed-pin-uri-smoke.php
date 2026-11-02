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

if ($failures !== []) {
    fwrite(STDERR, "bsky-feed-pin-uri-smoke FAILED: " . implode('; ', $failures) . "\n");
    exit(1);
}
echo "bsky-feed-pin-uri-smoke ok checks={$checks}\n";
