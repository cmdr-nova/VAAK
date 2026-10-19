<?php
declare(strict_types=1);
/** Jetstream Bluesky posts keep their media: blob refs become CDN embeds. */
require __DIR__ . '/migration-reference-functions.php';
reference_load(dirname(__DIR__) . '/ap-bsky.php', [
    'ap_bsky_blob_cid', 'ap_bsky_record_embed_view', 'ap_bsky_record_embed_synth_compact',
    'ap_bsky_post_embed_compact', 'ap_bsky_embed_video_view', 'ap_bsky_compact_label_list',
    'ap_bsky_compact_facets', 'ap_bsky_post_upsert_from_feed_item', 'ap_bsky_post_embed_media_thin',
    'ap_bsky_jetstream_media_backfill', 'ap_bsky_cached_quote_view',
    'ap_bsky_sensitive_label_vals', 'ap_bsky_post_label_vals', 'ap_bsky_post_is_sensitive',
]);
if (!defined('AP_BSKY_POST_TEXT_MAX')) define('AP_BSKY_POST_TEXT_MAX', 5000);
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE bsky_posts (bsky_uri TEXT PRIMARY KEY, bsky_cid TEXT, author_did TEXT NOT NULL,
    author_handle TEXT, author_display TEXT, author_avatar TEXT, indexed_at TEXT, published_at TEXT,
    text TEXT NOT NULL DEFAULT '', embed_json TEXT, reply_parent TEXT, reply_root TEXT, like_count INTEGER,
    repost_count INTEGER, reply_count INTEGER, quote_count INTEGER, reason_json TEXT, raw_json TEXT,
    owner_user_id BIGINT, seen_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
function ap_db(): PDO { return $GLOBALS['db']; }
function ap_bsky_posts_migrate(): void {}
function ap_bsky_avatar_public_url(mixed $avatar, string $did = ''): ?string {
    return is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null;
}
function ap_bsky_post_observation_touch(string $uri, int $owner): void {}
function ap_bsky_index_feed_post_links(array $post): void {}
function ap_masto_bsky_media_attachments(array $post, string $uri): array {
    return is_array($post['embed']['images'] ?? null) ? $post['embed']['images'] : [];
}
$checks = 0;
function jm_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
$did = 'did:plc:abc123';
$blob = static fn(string $cid): array => ['$type' => 'blob', 'ref' => ['$link' => $cid], 'mimeType' => 'image/jpeg', 'size' => 10];
$imgEmbed = ['$type' => 'app.bsky.embed.images', 'images' => [['alt' => 'a flag', 'image' => $blob('bafkreiaaaa1111')]]];
$row = static function (string $uri): array {
    $st = $GLOBALS['db']->prepare('SELECT embed_json, raw_json FROM bsky_posts WHERE bsky_uri = ?');
    $st->execute([$uri]);
    $r = $st->fetch();
    return ['embed' => json_decode((string) ($r['embed_json'] ?? ''), true), 'raw' => json_decode((string) ($r['raw_json'] ?? ''), true)];
};
try {
    $v = ap_bsky_record_embed_synth_compact($imgEmbed, $did);
    jm_check(($v['images'][0]['fullsize'] ?? '') === "https://cdn.bsky.app/img/feed_fullsize/plain/{$did}/bafkreiaaaa1111@jpeg", 'image fullsize from blob ref');
    jm_check(($v['images'][0]['thumb'] ?? '') === "https://cdn.bsky.app/img/feed_thumbnail/plain/{$did}/bafkreiaaaa1111@jpeg", 'image thumb from blob ref');
    jm_check(($v['images'][0]['alt'] ?? '') === 'a flag' && !empty($v['vaak_synth']), 'alt kept and row flagged');
    $legacy = ap_bsky_record_embed_synth_compact(['$type' => 'app.bsky.embed.images', 'images' => [['image' => ['cid' => 'bafkreilegacy1', 'mimeType' => 'image/png']]]], $did);
    jm_check(str_contains($legacy['images'][0]['fullsize'] ?? '', '/bafkreilegacy1@jpeg'), 'legacy blob cid form');
    $video = ap_bsky_record_embed_synth_compact(['$type' => 'app.bsky.embed.video', 'video' => $blob('bafkreivid0001')], $did);
    jm_check(($video['video']['playlist'] ?? '') === 'https://video.bsky.app/watch/did%3Aplc%3Aabc123/bafkreivid0001/playlist.m3u8', 'video playlist');
    jm_check(($video['video']['thumbnail'] ?? '') === 'https://video.bsky.app/watch/did%3Aplc%3Aabc123/bafkreivid0001/thumbnail.jpg', 'video thumbnail');
    $ext = ap_bsky_record_embed_synth_compact(['$type' => 'app.bsky.embed.external', 'external' => ['uri' => 'https://example.com/', 'title' => 'T', 'description' => 'D', 'thumb' => $blob('bafkreiext0001')]], $did);
    jm_check(($ext['external']['thumb'] ?? '') === "https://cdn.bsky.app/img/feed_thumbnail/plain/{$did}/bafkreiext0001@jpeg" && $ext['external']['uri'] === 'https://example.com/', 'external card thumb');
    $rwm = ap_bsky_record_embed_synth_compact(['$type' => 'app.bsky.embed.recordWithMedia', 'record' => ['record' => ['uri' => 'at://x/app.bsky.feed.post/q', 'cid' => 'c']], 'media' => $imgEmbed], $did);
    jm_check(isset($rwm['media']['images'][0]['fullsize']) && !isset($rwm['record']), 'recordWithMedia keeps media, leaves quote to AppView');
    jm_check(ap_bsky_record_embed_synth_compact(['$type' => 'app.bsky.embed.record', 'record' => ['uri' => 'at://x', 'cid' => 'c']], $did) === ['$type' => 'app.bsky.embed.record#view', 'vaak_synth' => true], 'bad quote URI only marks the row');
    jm_check(ap_bsky_record_embed_synth_compact($imgEmbed, 'did:plc:"><script>') === null, 'bad DID refused');
    jm_check(ap_bsky_record_embed_synth_compact(['$type' => 'app.bsky.embed.images', 'images' => [['image' => ['ref' => ['$link' => '../x']]]]], $did) === null, 'bad CID refused');

    // Jetstream ingest: record only, no view embed.
    $uri = "at://{$did}/app.bsky.feed.post/3my7dllbzn226";
    $jet = ['uri' => $uri, 'cid' => 'bafyrec', 'author' => ['did' => $did], 'record' => ['text' => '', 'createdAt' => '2026-10-19T05:00:04Z', 'embed' => $imgEmbed]];
    ap_bsky_post_upsert_from_feed_item($jet, null);
    $r = $row($uri);
    jm_check(str_contains($r['embed']['images'][0]['fullsize'] ?? '', 'bafkreiaaaa1111'), 'Jetstream row stores media URLs');
    jm_check(($r['raw']['embed']['images'][0]['fullsize'] ?? '') === $r['embed']['images'][0]['fullsize'], 'raw_json embed matches');
    jm_check(ap_bsky_post_embed_media_thin($r['raw']) === true, 'synthesized row still asks AppView for labels');

    // AppView view replaces the synthesized one.
    $live = 'https://cdn.bsky.app/img/feed_fullsize/plain/did:plc:abc123/bafkreiaaaa1111@jpeg?live';
    ap_bsky_post_upsert_from_feed_item(['post' => $jet + ['embed' => ['$type' => 'app.bsky.embed.images#view', 'images' => [['thumb' => $live, 'fullsize' => $live, 'alt' => 'a flag']]]]], null);
    $r = $row($uri);
    jm_check(($r['embed']['images'][0]['fullsize'] ?? '') === $live && empty($r['embed']['vaak_synth']), 'AppView embed wins');
    jm_check(ap_bsky_post_embed_media_thin($r['raw']) === false, 'AppView row is not thin');
    // A late Jetstream replay must not clobber the AppView embed.
    ap_bsky_post_upsert_from_feed_item($jet, null);
    jm_check(($row($uri)['embed']['images'][0]['fullsize'] ?? '') === $live, 'Jetstream replay keeps AppView embed');

    // Quotes: the quoted post's media stays inside the quote.
    $qUri = "at://did:plc:quoted1/app.bsky.feed.post/3quoted";
    $qEmbed = ['$type' => 'app.bsky.embed.images', 'images' => [['alt' => 'quoted pic', 'image' => $blob('bafkreiquoted01')]]];
    ap_bsky_post_upsert_from_feed_item(['uri' => $qUri, 'cid' => 'bafyq', 'author' => ['did' => 'did:plc:quoted1', 'handle' => 'q.example'],
        'record' => ['text' => 'quoted text', 'createdAt' => '2026-10-19T04:00:00Z', 'embed' => $qEmbed]], null);
    $oUri = "at://{$did}/app.bsky.feed.post/3outer";
    ap_bsky_post_upsert_from_feed_item(['uri' => $oUri, 'cid' => 'bafyo', 'author' => ['did' => $did], 'record' => ['text' => 'look',
        'createdAt' => '2026-10-19T05:00:00Z', 'embed' => ['$type' => 'app.bsky.embed.record', 'record' => ['uri' => $qUri, 'cid' => 'bafyq']]]], null);
    $o = $row($oUri)['embed'];
    jm_check(($o['record']['uri'] ?? '') === $qUri && ($o['record']['value']['text'] ?? '') === 'quoted text', 'cached quote paints');
    jm_check(str_contains($o['record']['embeds'][0]['images'][0]['fullsize'] ?? '', 'bafkreiquoted01'), 'quoted media sits inside the quote');
    jm_check(!isset($o['images']) && !isset($o['media']), 'quoted media is not hoisted onto the outer post');
    $wUri = "at://{$did}/app.bsky.feed.post/3withmedia";
    ap_bsky_post_upsert_from_feed_item(['uri' => $wUri, 'cid' => 'bafyw', 'author' => ['did' => $did], 'record' => ['text' => 'both',
        'createdAt' => '2026-10-19T05:00:00Z', 'embed' => ['$type' => 'app.bsky.embed.recordWithMedia', 'media' => $imgEmbed,
        'record' => ['$type' => 'app.bsky.embed.record', 'record' => ['uri' => $qUri, 'cid' => 'bafyq']]]]], null);
    $w = $row($wUri)['embed'];
    jm_check(str_contains($w['media']['images'][0]['fullsize'] ?? '', 'bafkreiaaaa1111') && count($w['media']['images']) === 1, 'outer media stays on the outer post');
    jm_check(str_contains($w['record']['record']['embeds'][0]['images'][0]['fullsize'] ?? '', 'bafkreiquoted01'), 'quoted media stays in the quote');
    $uUri = "at://{$did}/app.bsky.feed.post/3uncached";
    ap_bsky_post_upsert_from_feed_item(['uri' => $uUri, 'author' => ['did' => $did], 'record' => ['text' => 'q',
        'embed' => ['$type' => 'app.bsky.embed.record', 'record' => ['uri' => 'at://did:plc:nobody/app.bsky.feed.post/3x']]]], null);
    $u = $row($uUri)['embed'];
    jm_check(($u['$type'] ?? '') === 'app.bsky.embed.record#view' && !empty($u['vaak_synth']) && !isset($u['record']), 'uncached quote is marked for the warm scan');

    // Sensitive labels: Jetstream self-labels, account labels, quoted posts.
    $sUri = "at://{$did}/app.bsky.feed.post/3selflabel";
    $selfRec = ['text' => 'nsfw', 'createdAt' => '2026-10-19T05:00:00Z', 'embed' => $imgEmbed,
        'labels' => ['$type' => 'com.atproto.label.defs#selfLabels', 'values' => [['val' => 'porn']]]];
    ap_bsky_post_upsert_from_feed_item(['uri' => $sUri, 'author' => ['did' => $did], 'record' => $selfRec], null);
    $s = $row($sUri)['raw'];
    jm_check(ap_bsky_post_is_sensitive($s) && !isset($s['vaak_labels_at']), 'Jetstream self-label is sensitive; labels still owed by AppView');
    $mod = 'did:plc:ar7c4by46qjdydhdevvrndac';
    $aUri = "at://{$did}/app.bsky.feed.post/3acctlabel";
    ap_bsky_post_upsert_from_feed_item(['post' => ['uri' => $aUri, 'indexedAt' => '2026-10-19T05:00:01Z',
        'author' => ['did' => $did, 'handle' => 'a.example', 'labels' => [['val' => 'porn', 'src' => $mod, 'uri' => "at://{$did}/app.bsky.actor.profile/self"]]],
        'record' => ['text' => 'clip', 'createdAt' => '2026-10-19T05:00:00Z'], 'labels' => []]], null);
    $a = $row($aUri)['raw'];
    jm_check(($a['author']['labels'][0]['val'] ?? '') === 'porn' && ap_bsky_post_is_sensitive($a), 'account porn label marks the post sensitive');
    jm_check(is_int($a['vaak_labels_at'] ?? null), 'AppView upsert stamps when labels were read');
    // A Jetstream replay (no view embed, no author view) keeps both.
    ap_bsky_post_upsert_from_feed_item(['uri' => $aUri, 'author' => ['did' => $did], 'record' => ['text' => 'clip', 'createdAt' => '2026-10-19T05:00:00Z']], null);
    $a2 = $row($aUri)['raw'];
    jm_check(($a2['author']['labels'][0]['val'] ?? '') === 'porn' && ($a2['vaak_labels_at'] ?? null) === $a['vaak_labels_at'], 'Jetstream replay keeps account labels and the stamp');
    $qlUri = "at://{$did}/app.bsky.feed.post/3quotelabel";
    ap_bsky_post_upsert_from_feed_item(['post' => ['uri' => $qlUri, 'indexedAt' => '2026-10-19T05:00:01Z', 'author' => ['did' => $did],
        'record' => ['text' => 'look', 'createdAt' => '2026-10-19T05:00:00Z'],
        'embed' => ['$type' => 'app.bsky.embed.record#view', 'record' => ['$type' => 'app.bsky.embed.record#viewRecord',
            'uri' => $qUri, 'cid' => 'bafyq', 'author' => ['did' => 'did:plc:quoted1', 'handle' => 'q.example'],
            'value' => ['text' => 'quoted text'], 'labels' => [['val' => 'nudity', 'src' => $mod]]]]]], null);
    $ql = $row($qlUri)['embed']['record'] ?? [];
    jm_check(($ql['labels'][0]['val'] ?? '') === 'nudity' && ap_bsky_post_is_sensitive($ql), 'quoted post keeps its label');
    jm_check(!ap_bsky_post_is_sensitive($row($qlUri)['raw']), 'outer post stays unlabelled');

    // Backfill rows stored before this change (embed_json NULL, record.embed kept).
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $old = gmdate('Y-m-d\TH:i:s\Z', time() - 30 * 86400);
    $ins = $db->prepare("INSERT INTO bsky_posts (bsky_uri, author_did, indexed_at, text, embed_json, raw_json, seen_at, updated_at) VALUES (?, ?, ?, '', NULL, ?, ?, ?)");
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $mk = static fn(string $u, ?array $e): string => json_encode(['uri' => $u, 'record' => array_filter(['text' => 'x', 'embed' => $e]), 'embed' => null], $flags);
    $ins->execute(["at://{$did}/app.bsky.feed.post/b1", $did, $now, $mk("at://{$did}/app.bsky.feed.post/b1", $imgEmbed), $now, $now]);
    $ins->execute(["at://{$did}/app.bsky.feed.post/b2", $did, $now, $mk("at://{$did}/app.bsky.feed.post/b2", ['$type' => 'app.bsky.embed.record', 'record' => ['uri' => 'at://q']]), $now, $now]);
    $ins->execute(["at://{$did}/app.bsky.feed.post/b3", $did, $now, $mk("at://{$did}/app.bsky.feed.post/b3", ['$type' => 'app.bsky.embed.somethingNew']), $now, $now]);
    $ins->execute(["at://{$did}/app.bsky.feed.post/b4", $did, $old, $mk("at://{$did}/app.bsky.feed.post/b4", $imgEmbed), $now, $now]);
    $dry = ap_bsky_jetstream_media_backfill(3, 100, true);
    jm_check($dry['scanned'] === 3 && $dry['repaired'] === 2 && $dry['no_media'] === 1, 'dry run finds recent record-embed rows only');
    jm_check($row("at://{$did}/app.bsky.feed.post/b1")['embed'] === null, 'dry run writes nothing');
    $real = ap_bsky_jetstream_media_backfill(3, 100, false);
    jm_check($real['repaired'] === 2, 'backfill repairs media and marks the quote');
    $b1 = $row("at://{$did}/app.bsky.feed.post/b1");
    jm_check(!empty($b1['embed']['vaak_synth']) && str_contains($b1['raw']['embed']['images'][0]['thumb'] ?? '', 'feed_thumbnail'), 'backfilled embed_json and raw_json');
    jm_check($row("at://{$did}/app.bsky.feed.post/b4")['embed'] === null, 'rows outside --days untouched');
    jm_check(ap_bsky_jetstream_media_backfill(3, 100, false)['scanned'] === 1, 'second run skips repaired rows');
} catch (Throwable $e) {
    fwrite(STDERR, 'bsky-jetstream-media-smoke failed: ' . $e->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, "bsky-jetstream-media-smoke ok checks={$checks}\n");
