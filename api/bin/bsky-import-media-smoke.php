<?php
declare(strict_types=1);
/**
 * Own Bluesky posts imported as local notes get masto_media rows (CDN URLs,
 * no storage key) with their alt text, so status pages, notifications, link
 * cards and Mastodon clients show the image instead of just the text. The
 * repair fills older imports from the post, or from the stored attachments
 * when the post is gone. SQLite in memory; no network.
 */
$api = getenv('VAAK_BSKY_MEDIA_API') ?: dirname(__DIR__);
require __DIR__ . '/migration-reference-functions.php';

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo']->exec('CREATE TABLE outbox_notes (id TEXT PRIMARY KEY, content TEXT, raw_create_json TEXT, create_id TEXT)');
$GLOBALS['pdo']->exec('CREATE TABLE masto_statuses (local_id INTEGER PRIMARY KEY, note_id TEXT)');
$GLOBALS['pdo']->exec("CREATE TABLE masto_media (local_id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, s3_key TEXT NOT NULL, public_url TEXT NOT NULL, preview_url TEXT, media_type TEXT NOT NULL, mime TEXT NOT NULL, file_size INTEGER NOT NULL DEFAULT 0, width INTEGER, height INTEGER, blurhash TEXT, description TEXT, status_local_id INTEGER)");
function ap_db(): PDO { return $GLOBALS['pdo']; }
function ap_db_now(): string { return '2026-11-24T00:00:00Z'; }
function ap_bsky_url_looks_like_media(string $u): bool { return (bool) preg_match('/\.(gif|png|jpe?g|webp)(\?|$)/i', $u); }
$GLOBALS['invalidated'] = [];
function ap_public_doc_invalidate_note(string $n, ?string $c = null): void { $GLOBALS['invalidated'][] = $n; }
reference_load($api . '/ap-bsky.php', [
    'ap_bsky_embed_video_view', 'ap_bsky_import_media_items', 'ap_bsky_cdn_fullsize_url',
    'ap_bsky_import_media_attachments', 'ap_bsky_import_media_items_from_object',
    'ap_bsky_import_media_rows_ensure', 'ap_bsky_repair_imported_note_media',
]);

$checks = 0;
function media_check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    $checks++;
}

$cdn = 'did:plc:abc/bafkimg';
$post = [
    'record' => ['text' => '#valerie-flux #opensim', 'embed' => ['$type' => 'app.bsky.embed.images', 'images' => [
        ['alt' => 'Pencil sketch of an avatar', 'aspectRatio' => ['width' => 1200, 'height' => 1600]],
    ]]],
    'embed' => ['$type' => 'app.bsky.embed.images#view', 'images' => [[
        'thumb' => 'https://cdn.bsky.app/img/feed_thumbnail/plain/' . $cdn,
        'fullsize' => 'https://cdn.bsky.app/img/feed_fullsize/plain/' . $cdn,
        'alt' => '',
    ]]],
];
$items = ap_bsky_import_media_items($post);
media_check(count($items) === 1, 'one image item');
media_check($items[0]['url'] === 'https://cdn.bsky.app/img/feed_fullsize/plain/' . $cdn, 'full size image is the media URL');
media_check($items[0]['attachment_url'] === 'https://cdn.bsky.app/img/feed_thumbnail/plain/' . $cdn, 'stored attachment URL unchanged (thumb)');
media_check($items[0]['alt'] === 'Pencil sketch of an avatar', 'alt falls back to the record when the view lost it');
media_check($items[0]['width'] === 1200 && $items[0]['height'] === 1600, 'aspect ratio from the record');
$atts = ap_bsky_import_media_attachments($items);
media_check($atts[0]['name'] === 'Pencil sketch of an avatar' && $atts[0]['url'] === $items[0]['attachment_url'], 'AS2 attachment keeps the alt in name');

$video = ['embed' => ['$type' => 'app.bsky.embed.video#view', 'playlist' => 'https://video.bsky.app/watch/x/playlist.m3u8', 'thumbnail' => 'https://video.bsky.app/watch/x/thumbnail.jpg', 'alt' => 'clip']];
$vItems = ap_bsky_import_media_items($video);
media_check(count($vItems) === 1 && $vItems[0]['kind'] === 'video' && $vItems[0]['preview'] === 'https://video.bsky.app/watch/x/thumbnail.jpg' && $vItems[0]['alt'] === 'clip', 'video item');
media_check(ap_bsky_import_media_attachments($vItems)[0]['thumbnail'] === 'https://video.bsky.app/watch/x/thumbnail.jpg', 'video attachment keeps its thumbnail');
$gif = ['embed' => ['$type' => 'app.bsky.embed.external#view', 'external' => ['uri' => 'https://media.example/cat.gif', 'title' => 'Cat GIF']]];
$gItems = ap_bsky_import_media_items($gif);
media_check(count($gItems) === 1 && $gItems[0]['mime'] === 'image/gif', 'direct GIF link card becomes media');
media_check(ap_bsky_import_media_items(['embed' => ['$type' => 'app.bsky.embed.external#view', 'external' => ['uri' => 'https://example.test/article']]]) === [], 'plain link card is not media');
media_check(ap_bsky_import_media_items(['record' => ['text' => 'hi']]) === [], 'text post has no media');

$pdo = $GLOBALS['pdo'];
$note = 'https://mkultra.monster/users/example/notes/91e9766af7e51dbe';
$own = 'https://mkultra.monster/users/example/notes/vaak';
$oldCreate = ['object' => ['id' => $note, 'vaakOrigin' => 'bluesky', 'attachment' => [['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => $items[0]['attachment_url'], 'name' => '']]]];
$pdo->prepare('INSERT INTO outbox_notes VALUES (?, ?, ?, ?)')->execute([$note, '<p>#tags</p>', json_encode($oldCreate), $note . '/activity']);
$pdo->prepare('INSERT INTO outbox_notes VALUES (?, ?, ?, ?)')->execute([$own, '<p>mine</p>', json_encode(['object' => ['id' => $own, 'attachment' => [['url' => 'https://mkultra.monster/media/a.jpg']]]]), $own . '/activity']);
$pdo->exec("INSERT INTO masto_statuses VALUES (7, '{$note}'), (8, '{$own}')");
$count = static fn(int $id): int => (int) $pdo->query("SELECT COUNT(*) FROM masto_media WHERE status_local_id = {$id}")->fetchColumn();

$dry = ap_bsky_repair_imported_note_media($note, $post, 1, true);
media_check(!empty($dry['updated']) && $count(7) === 0, 'dry run reports without writing');
$r = ap_bsky_repair_imported_note_media($note, $post, 1);
media_check(!empty($r['updated']) && $count(7) === 1, 'repair adds the missing media row');
$m = $pdo->query('SELECT * FROM masto_media WHERE status_local_id = 7')->fetch();
media_check($m['s3_key'] === '' && $m['media_type'] === 'image' && $m['description'] === 'Pencil sketch of an avatar', 'row has no storage key and keeps alt');
media_check($m['public_url'] === $items[0]['url'] && $m['preview_url'] === $items[0]['attachment_url'], 'row URLs: full size + thumb preview');
$raw = json_decode((string) $pdo->query("SELECT raw_create_json FROM outbox_notes WHERE id = '{$note}'")->fetchColumn(), true);
media_check($raw['object']['attachment'][0]['name'] === 'Pencil sketch of an avatar', 'repair puts the alt back on the stored attachment');
media_check(in_array($note, $GLOBALS['invalidated'], true), 'public doc cache invalidated');
media_check(empty(ap_bsky_repair_imported_note_media($note, $post, 1)['updated']) && $count(7) === 1, 'second repair is a no-op');
media_check(empty(ap_bsky_repair_imported_note_media($own, $post, 1)['updated']) && $count(8) === 0, 'VAAK-authored notes are never touched');

// Post gone from AppView: repair from the stored attachment, no alt.
$pdo->exec('DELETE FROM masto_media');
$pdo->prepare('UPDATE outbox_notes SET raw_create_json = ? WHERE id = ?')->execute([json_encode($oldCreate), $note]);
$r = ap_bsky_repair_imported_note_media($note, null, 1);
$m = $pdo->query('SELECT * FROM masto_media WHERE status_local_id = 7')->fetch();
media_check(!empty($r['updated']) && $m['public_url'] === $items[0]['url'] && $m['description'] === null, 'stored attachment fallback (thumb → full size URL)');
// Alt arrives later (post served again): fills the alt-less row.
$r = ap_bsky_repair_imported_note_media($note, $post, 1);
media_check(!empty($r['updated']) && $pdo->query('SELECT description FROM masto_media WHERE status_local_id = 7')->fetchColumn() === 'Pencil sketch of an avatar' && $count(7) === 1, 'later repair adds alt without duplicating rows');

// The importer writes rows for new imports.
$bsky = (string) file_get_contents($api . '/ap-bsky.php');
media_check(str_contains($bsky, 'ap_bsky_import_media_rows_ensure((int) $statusLocalId, $ownerUserId, $mediaItems)'), 'importer writes media rows');
media_check(str_contains($bsky, '$remedia = ap_bsky_repair_imported_note_media($noteId, $post, $ownerUserId)'), 're-seen imports self-repair media');

fwrite(STDOUT, "bsky-import-media-smoke ok ({$checks} checks)\n");
