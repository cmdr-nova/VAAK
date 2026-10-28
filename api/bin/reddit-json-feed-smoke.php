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
    // Reddit-hosted video with no playable source: still dropped at upsert.
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
reddit_check(count($byGuid) === 5 && empty($byGuid['t3_img1']['sensitive']) && !isset($byGuid['x']), 'only t3/t1 children');
reddit_check($byGuid['t3_img1']['image_url'] === 'https://i.redd.it/abc123.jpg', 'image post image');
reddit_check($byGuid['t3_img1']['url'] === 'https://www.reddit.com/r/pics/comments/img1/a_cat/', 'permalink url');
reddit_check($byGuid['t3_img1']['published_at'] === gmdate('c', 1761550000), 'published_at');
reddit_check($byGuid['t3_img1']['summary_text'] === '', 'image post has no summary');
reddit_check($byGuid['t3_txt1']['summary_text'] === 'Body & soul' && $byGuid['t3_txt1']['image_url'] === '', 'selftext summary');
reddit_check($byGuid['t3_vid1']['video'] === true && $byGuid['t3_vid1']['image_url'] === '' && $byGuid['t3_vid1']['media'] === [], 'unplayable video flagged');
reddit_check($byGuid['t3_img1']['media'] === [], 'single image keeps the image_url path');
reddit_check($byGuid['t3_gal1']['image_url'] === 'https://i.redd.it/m1.png', 'gallery first image upgraded');
reddit_check($byGuid['t3_ext1']['image_url'] === '', 'external-preview dropped');

// Playable media and NSFW flags.
$media = json_encode(['kind' => 'Listing', 'data' => ['children' => [
    // NSFW Reddit video: HLS (with audio) plus a poster, flagged sensitive.
    ['kind' => 't3', 'data' => [
        'name' => 't3_v2', 'permalink' => '/r/x/comments/v2/v/', 'title' => 'Clip', 'over_18' => true,
        'url' => 'https://v.redd.it/v2id', 'is_video' => true, 'created_utc' => 1761550600, 'thumbnail' => 'nsfw',
        'secure_media' => ['reddit_video' => ['fallback_url' => 'https://v.redd.it/v2id/DASH_720.mp4?source=fallback',
            'hls_url' => 'https://v.redd.it/v2id/HLSPlaylist.m3u8?a=1&f=sd', 'is_gif' => false]],
        'preview' => ['images' => [['source' => ['url' => 'https://preview.redd.it/v2poster.jpg?width=1280&format=pjpg']]]],
    ]],
    // Gif-like video (no audio): progressive mp4 as gifv.
    ['kind' => 't3', 'data' => [
        'name' => 't3_g2', 'permalink' => '/r/x/comments/g2/g/', 'title' => 'Loop', 'spoiler' => true,
        'url' => 'https://v.redd.it/g2id', 'is_video' => true, 'created_utc' => 1761550700,
        'media' => ['reddit_video' => ['fallback_url' => 'https://v.redd.it/g2id/DASH_480.mp4', 'hls_url' => 'https://v.redd.it/g2id/HLSPlaylist.m3u8', 'is_gif' => true]],
    ]],
    // redgifs-style link: Reddit's transcoded preview mp4 plays, oEmbed still is the poster.
    ['kind' => 't3', 'data' => [
        'name' => 't3_rg', 'permalink' => '/r/x/comments/rg/r/', 'title' => 'Ext gif', 'over_18' => true,
        'url' => 'https://www.redgifs.com/watch/abc', 'created_utc' => 1761550800,
        'preview' => ['reddit_video_preview' => ['fallback_url' => 'https://v.redd.it/rgid/DASH_360.mp4', 'is_gif' => true],
            'images' => [['source' => ['url' => 'https://external-preview.redd.it/rg.jpg?s=1']]]],
        'secure_media' => ['oembed' => ['thumbnail_url' => 'https://thumbs44.redgifs.com/Abc-poster.jpg']],
    ]],
    // imgur .gifv becomes its mp4.
    ['kind' => 't3', 'data' => [
        'name' => 't3_ig', 'permalink' => '/r/x/comments/ig/i/', 'title' => 'Imgur',
        'url' => 'https://i.imgur.com/AbC12.gifv', 'created_utc' => 1761550900,
    ]],
    // Multi-image gallery with an animated item and a failed one.
    ['kind' => 't3', 'data' => [
        'name' => 't3_gm', 'permalink' => '/r/x/comments/gm/g/', 'title' => 'Many', 'over_18' => true,
        'url' => 'https://www.reddit.com/gallery/gm', 'created_utc' => 1761551000,
        'gallery_data' => ['items' => [['media_id' => 'a'], ['media_id' => 'b'], ['media_id' => 'c']]],
        'media_metadata' => [
            'a' => ['status' => 'valid', 'e' => 'Image', 's' => ['u' => 'https://preview.redd.it/a.jpg?width=900&format=pjpg']],
            'b' => ['status' => 'failed'],
            'c' => ['status' => 'valid', 'e' => 'AnimatedImage', 's' => ['gif' => 'https://i.redd.it/c.gif', 'mp4' => 'https://preview.redd.it/c.gif?format=mp4&s=x']],
        ],
    ]],
    // Crosspost: media lives on the parent.
    ['kind' => 't3', 'data' => [
        'name' => 't3_xp', 'permalink' => '/r/x/comments/xp/x/', 'title' => 'Crosspost',
        'url' => '/r/y/comments/orig/', 'created_utc' => 1761551100,
        'crosspost_parent_list' => [['over_18' => true, 'is_video' => true, 'url' => 'https://v.redd.it/orig',
            'secure_media' => ['reddit_video' => ['hls_url' => 'https://v.redd.it/orig/HLSPlaylist.m3u8']]]],
    ]],
]]]);
$mp = [];
foreach (ap_rss_parse_reddit_json((string) $media, 'https://www.reddit.com/r/x/.rss')['items'] as $it) {
    $mp[$it['guid']] = $it;
}
reddit_check($mp['t3_v2']['media'] === [['type' => 'video', 'url' => 'https://v.redd.it/v2id/HLSPlaylist.m3u8?a=1&f=sd', 'preview_url' => 'https://i.redd.it/v2poster.jpg']], 'reddit video uses HLS + poster');
reddit_check($mp['t3_v2']['sensitive'] === true && $mp['t3_v2']['spoiler_text'] === '', 'over_18 is sensitive');
reddit_check($mp['t3_g2']['media'][0]['type'] === 'gifv' && $mp['t3_g2']['media'][0]['url'] === 'https://v.redd.it/g2id/DASH_480.mp4', 'gif video is a gifv mp4');
reddit_check($mp['t3_g2']['spoiler_text'] === 'Spoiler' && $mp['t3_g2']['sensitive'] === false, 'spoiler flag');
reddit_check($mp['t3_rg']['media'] === [['type' => 'gifv', 'url' => 'https://v.redd.it/rgid/DASH_360.mp4', 'preview_url' => 'https://thumbs44.redgifs.com/Abc-poster.jpg']], 'redgifs preview mp4');
reddit_check($mp['t3_ig']['media'] === [['type' => 'gifv', 'url' => 'https://i.imgur.com/AbC12.mp4', 'preview_url' => 'https://i.imgur.com/AbC12.jpg']] && $mp['t3_ig']['image_url'] === 'https://i.imgur.com/AbC12.jpg', 'imgur gifv');
reddit_check(array_column($mp['t3_gm']['media'], 'type') === ['image', 'gifv'] && $mp['t3_gm']['media'][0]['url'] === 'https://i.redd.it/a.jpg', 'gallery keeps valid items in order');
reddit_check($mp['t3_xp']['media'][0]['url'] === 'https://v.redd.it/orig/HLSPlaylist.m3u8' && $mp['t3_xp']['sensitive'] === true && $mp['t3_xp']['video'] === true, 'crosspost parent media + flag');
reddit_check(ap_rss_clean_item_media([['type' => 'video', 'url' => 'http://x/a.mp4'], ['type' => 'exe', 'url' => 'https://x/a'], ['type' => 'image', 'url' => 'https://x/b.jpg', 'preview_url' => 'https://external-preview.redd.it/p.jpg'], ['type' => 'image', 'url' => 'https://x/b.jpg']]) === [['type' => 'image', 'url' => 'https://x/b.jpg', 'preview_url' => '']], 'clean media');

// Stored media + flags reach the status (PHP painter path).
$st = ap_rss_item_to_masto_status(['id' => 7, 'feed_id' => 1, 'url' => 'https://www.reddit.com/r/x/comments/v2/v/', 'title' => 'Clip', 'summary_text' => 'some selftext that is long enough to not be media forward at all, really',
    'image_url' => 'https://i.redd.it/v2poster.jpg', 'feed_title' => 'r/x', 'published_at' => '2026-10-28T00:00:00Z',
    'media_json' => json_encode($mp['t3_v2']['media']), 'sensitive' => true, 'spoiler_text' => '']);
reddit_check($st['sensitive'] === true && count($st['media_attachments']) === 1 && $st['card'] === null, 'status carries video and sensitive');
reddit_check($st['media_attachments'][0]['type'] === 'video' && $st['media_attachments'][0]['mediaType'] === 'application/vnd.apple.mpegurl' && $st['media_attachments'][0]['preview_url'] === 'https://i.redd.it/v2poster.jpg', 'video attachment shape');
$plain = ap_rss_item_to_masto_status(['id' => 8, 'feed_id' => 1, 'url' => 'https://example.com/a', 'title' => 'A', 'summary_text' => 'Body', 'image_url' => '', 'feed_title' => 'Blog']);
reddit_check($plain['sensitive'] === false && $plain['spoiler_text'] === '' && $plain['media_attachments'] === [], 'old rows unchanged');

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

// Credentials: process env wins, vaak.env fills the gaps, quotes stripped.
$envFile = tempnam(sys_get_temp_dir(), 'vaak-reddit-env-');
file_put_contents($envFile, "# comment\nAP_REDDIT_CLIENT_ID=\"fileid\"\nAP_REDDIT_CLIENT_SECRET='filesecret'\nOTHER=x\n");
putenv('AP_REDDIT_CLIENT_ID');
putenv('AP_REDDIT_CLIENT_SECRET');
reddit_check(ap_rss_reddit_credentials($envFile) === ['fileid', 'filesecret'], 'vaak.env credentials');
putenv('AP_REDDIT_CLIENT_ID=envid');
reddit_check(ap_rss_reddit_credentials($envFile) === ['envid', 'filesecret'], 'process env wins');
putenv('AP_REDDIT_CLIENT_ID');
reddit_check(ap_rss_reddit_credentials($envFile . '.missing') === ['', ''], 'unconfigured');
file_put_contents($envFile, "AP_REDDIT_USERNAME=nova\nAP_REDDIT_PASSWORD='p=w'\n");
reddit_check(ap_rss_reddit_login($envFile) === ['nova', 'p=w'], 'account login from vaak.env');
reddit_check(ap_rss_reddit_login($envFile . '.missing') === ['', ''], 'no account login');
unlink($envFile);

echo "reddit json feed smoke: {$checks} checks passed\n";
