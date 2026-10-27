<?php
declare(strict_types=1);
/** Reddit feeds fetched as JSON listings: URL mapping and listing → feed items (no network). */
require dirname(__DIR__) . '/ap-rss.php';
$checks = 0;
function reddit_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}

// Stored .rss URLs map to the JSON listing of the same path.
reddit_check(ap_rss_reddit_json_url('https://www.reddit.com/r/pics/.rss') === 'https://www.reddit.com/r/pics.json?limit=25&raw_json=1', 'subreddit /.rss');
reddit_check(ap_rss_reddit_json_url('https://reddit.com/r/pics.rss') === 'https://www.reddit.com/r/pics.json?limit=25&raw_json=1', 'subreddit .rss');
reddit_check(ap_rss_reddit_json_url('https://old.reddit.com/r/pics/top/.rss?t=week&utm_source=x') === 'https://www.reddit.com/r/pics/top.json?t=week&limit=25&raw_json=1', 'sort + t kept, junk query dropped');
reddit_check(ap_rss_reddit_json_url('https://www.reddit.com/u/cmdr_nova69.rss') === 'https://www.reddit.com/user/cmdr_nova69.json?limit=25&raw_json=1', '/u/ is /user/');
reddit_check(ap_rss_reddit_json_url('https://www.reddit.com/r/a+b/new/.rss', true) === 'https://oauth.reddit.com/r/a+b/new.json?limit=25&raw_json=1', 'multireddit over oauth');
reddit_check(ap_rss_reddit_json_url('https://www.reddit.com/.rss') === 'https://www.reddit.com/.json?limit=25&raw_json=1', 'front page');
reddit_check(ap_rss_reddit_json_url('https://www.reddit.com/r/pics/') === null, 'page URL is not a stored feed');
reddit_check(ap_rss_reddit_json_url('https://example.com/r/pics/.rss') === null, 'other hosts untouched');
reddit_check(ap_rss_reddit_json_url('http://www.reddit.com/r/pics/.rss') === null, 'https only');
reddit_check(ap_rss_reddit_json_url('https://www.reddit.com/r/pics/../../x/.rss') === null, 'odd paths refused');

// Page URLs subscribe to the canonical .rss spelling.
reddit_check(ap_rss_reddit_canonical_feed_url('https://www.reddit.com/r/unixporn/') === 'https://www.reddit.com/r/unixporn/.rss', 'subreddit page');
reddit_check(ap_rss_reddit_canonical_feed_url('https://reddit.com/u/someone') === 'https://www.reddit.com/user/someone/.rss', 'user page');
reddit_check(ap_rss_reddit_canonical_feed_url('https://www.reddit.com/r/pics.rss') === 'https://www.reddit.com/r/pics.rss', 'stored spelling kept');
reddit_check(ap_rss_reddit_canonical_feed_url('https://www.reddit.com/') === null, 'bare front page goes through normal discovery');
reddit_check(ap_rss_reddit_canonical_feed_url('https://example.com/r/pics') === null, 'non-Reddit');

$listing = json_encode(['kind' => 'Listing', 'data' => ['children' => [
    // Image post: direct i.redd.it link wins over the preview.
    ['kind' => 't3', 'data' => [
        'name' => 't3_img1', 'permalink' => '/r/pics/comments/img1/a_cat/', 'title' => 'A cat',
        'url_overridden_by_dest' => 'https://i.redd.it/abc123.jpg', 'selftext_html' => null, 'created_utc' => 1761550000,
        'preview' => ['images' => [['source' => ['url' => 'https://preview.redd.it/abc123.jpg?width=1080&format=pjpg']]]],
        'thumbnail' => 'https://b.thumbs.redditmedia.com/t.jpg',
    ]],
    // Text post: selftext becomes the summary.
    ['kind' => 't3', 'data' => [
        'name' => 't3_txt1', 'permalink' => '/r/pics/comments/txt1/hello/', 'title' => 'Hello',
        'url' => 'https://www.reddit.com/r/pics/comments/txt1/hello/', 'created_utc' => 1761550100, 'thumbnail' => 'self',
        'selftext_html' => '<!-- SC_OFF --><div class="md"><p>Body &amp; soul</p></div><!-- SC_ON -->',
    ]],
    // Reddit-hosted video: dropped like the Atom path.
    ['kind' => 't3', 'data' => [
        'name' => 't3_vid1', 'permalink' => '/r/pics/comments/vid1/v/', 'title' => 'Video',
        'url' => 'https://v.redd.it/xyz', 'is_video' => true, 'created_utc' => 1761550200,
    ]],
    // Gallery: first image from media_metadata.
    ['kind' => 't3', 'data' => [
        'name' => 't3_gal1', 'permalink' => '/r/pics/comments/gal1/g/', 'title' => 'Gallery',
        'url' => 'https://www.reddit.com/gallery/gal1', 'created_utc' => 1761550300,
        'gallery_data' => ['items' => [['media_id' => 'm1']]],
        'media_metadata' => ['m1' => ['s' => ['u' => 'https://preview.redd.it/m1.png?width=2000&format=png']]],
    ]],
    // External link with an unusable external-preview poster: no image.
    ['kind' => 't3', 'data' => [
        'name' => 't3_ext1', 'permalink' => '/r/pics/comments/ext1/e/', 'title' => 'Article',
        'url' => 'https://example.com/story', 'created_utc' => 1761550400,
        'preview' => ['images' => [['source' => ['url' => 'https://external-preview.redd.it/zzz.jpg?auto=webp']]]],
    ]],
    ['kind' => 'more', 'data' => ['name' => 'x']],
]]]);
$parsed = ap_rss_parse_reddit_json((string) $listing, 'https://www.reddit.com/r/pics/.rss');
reddit_check(is_array($parsed), 'listing parses');
reddit_check($parsed['title'] === 'r/pics' && $parsed['site_url'] === 'https://www.reddit.com/r/pics/', 'feed meta');
$byGuid = [];
foreach ($parsed['items'] as $it) {
    $byGuid[$it['guid']] = $it;
}
reddit_check(count($byGuid) === 5 && !isset($byGuid['x']), 'only t3/t1 children');
reddit_check($byGuid['t3_img1']['image_url'] === 'https://i.redd.it/abc123.jpg', 'image post image');
reddit_check($byGuid['t3_img1']['url'] === 'https://www.reddit.com/r/pics/comments/img1/a_cat/', 'permalink url');
reddit_check($byGuid['t3_img1']['published_at'] === gmdate('c', 1761550000), 'published_at');
reddit_check($byGuid['t3_img1']['summary_text'] === '', 'image post has no summary');
reddit_check($byGuid['t3_txt1']['summary_text'] === 'Body & soul' && $byGuid['t3_txt1']['image_url'] === '', 'selftext summary');
reddit_check($byGuid['t3_vid1']['video'] === true && $byGuid['t3_vid1']['image_url'] === '', 'video flagged');
reddit_check($byGuid['t3_gal1']['image_url'] === 'https://i.redd.it/m1.png', 'gallery first image upgraded');
reddit_check($byGuid['t3_ext1']['image_url'] === '', 'external-preview dropped');

// A user overview mixes posts and comments; a thread is a [post, comments] pair.
$user = json_encode(['kind' => 'Listing', 'data' => ['children' => [
    ['kind' => 't1', 'data' => ['name' => 't1_c1', 'permalink' => '/r/x/comments/p/t/c1/', 'author' => 'someone',
        'link_title' => 'Thread', 'body_html' => '<div class="md"><p>Nice</p></div>', 'created_utc' => 1761550500]],
]]]);
$u = ap_rss_parse_reddit_json((string) $user, 'https://www.reddit.com/user/someone/.rss');
reddit_check($u['title'] === 'u/someone' && $u['items'][0]['title'] === '/u/someone on Thread' && $u['items'][0]['summary_text'] === 'Nice', 'comment item');
$thread = json_encode([json_decode((string) $listing, true), json_decode((string) $user, true)]);
reddit_check(count(ap_rss_parse_reddit_json((string) $thread, 'https://www.reddit.com/r/pics/comments/p/.rss')['items']) === 6, 'thread pair');
reddit_check(ap_rss_parse_reddit_json('<html>blocked</html>', 'https://www.reddit.com/r/pics/.rss') === null, 'HTML block page');
reddit_check(ap_rss_parse_reddit_json('{"error":403}', 'https://www.reddit.com/r/pics/.rss') === null, 'error object');
$empty = ap_rss_parse_reddit_json('{"kind":"Listing","data":{"children":[]}}', 'https://www.reddit.com/r/pics/.rss');
reddit_check(is_array($empty) && $empty['items'] === [], 'empty listing is valid');

echo "reddit json feed smoke: {$checks} checks passed\n";
