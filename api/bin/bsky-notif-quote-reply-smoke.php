<?php
/** A Bluesky reply or mention that quotes a post keeps the quoted post's URI. */
declare(strict_types=1);

require __DIR__ . '/migration-reference-functions.php';
reference_load(dirname(__DIR__) . '/ap-bsky.php', ['ap_bsky_record_quoted_post_uri']);

$checks = 0;
function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
}

$own = 'at://did:plc:dame/app.bsky.feed.post/3own';
check(ap_bsky_record_quoted_post_uri(['text' => 'for sure!',
    'embed' => ['$type' => 'app.bsky.embed.record', 'record' => ['uri' => $own, 'cid' => 'bafy']]]) === $own, 'record quote');
check(ap_bsky_record_quoted_post_uri(['text' => 'look',
    'embed' => ['$type' => 'app.bsky.embed.recordWithMedia',
        'record' => ['$type' => 'app.bsky.embed.record', 'record' => ['uri' => $own, 'cid' => 'bafy']],
        'media' => ['$type' => 'app.bsky.embed.images', 'images' => []]]]) === $own, 'recordWithMedia quote');
check(ap_bsky_record_quoted_post_uri(['text' => 'feed',
    'embed' => ['$type' => 'app.bsky.embed.record', 'record' => ['uri' => 'at://did:plc:x/app.bsky.feed.generator/cool']]]) === null, 'feed generator is not a quote');
check(ap_bsky_record_quoted_post_uri(['text' => 'list',
    'embed' => ['$type' => 'app.bsky.embed.record', 'record' => ['uri' => 'at://did:plc:x/app.bsky.graph.list/l1']]]) === null, 'list is not a quote');
check(ap_bsky_record_quoted_post_uri(['text' => 'pics',
    'embed' => ['$type' => 'app.bsky.embed.images', 'images' => []]]) === null, 'images only');
check(ap_bsky_record_quoted_post_uri(['text' => 'plain']) === null, 'no embed');
check(ap_bsky_record_quoted_post_uri(['embed' => ['$type' => 'app.bsky.embed.record',
    'record' => ['uri' => 'at://did:plc:x/app.bsky.feed.post/../evil']]]) === null, 'dot segments refused');

// Ingest writes the quote line for mention/reply rows, not only reason=quote.
$src = (string) file_get_contents(dirname(__DIR__) . '/ap-bsky.php');
check(str_contains($src, "ap_bsky_record_quoted_post_uri(\$record)"), 'mention row reads the record embed');
check(str_contains($src, "ap_bsky_record_quoted_post_uri(\$notif['record'])"), 'ingest warms the quoted post');

echo "bsky-notif-quote-reply-smoke PASS {$checks} checks\n";
