<?php
declare(strict_types=1);
/**
 * Profile Posts and Replies pages are filtered in SQL.
 * A mixed window of replies must not end the Posts cursor, and walking
 * has-more/next-offset must match the public counts.
 * No Postgres, Redis, or worker.
 */
require_once __DIR__ . '/migration-reference-functions.php';

$root = dirname(__DIR__, 2);
$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

function ap_db(): PDO
{
    return $GLOBALS['db'];
}
function ap_redis_json_get(string $key): ?array
{
    return null;
}
function ap_redis_json_set(string $key, array $value, int $ttlSeconds): bool
{
    return true;
}

function ap_db_driver(PDO $db): string
{
    return (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
}
function ap_db_owner_user_id_for_actor(string $actorKey): int
{
    return match (strtolower($actorKey)) {
        'pinwalk' => 7,
        'guestflux' => 9,
        default => 0,
    };
}

reference_load($root . '/api/ap-db.php', [
    'ap_outbox_note_is_reply',
    'ap_outbox_note_on_public_profile',
    'ap_outbox_profile_reply_predicate',
    'ap_outbox_posts_list_page',
    'ap_outbox_replies_list_page',
    'ap_outbox_media_list_page',
    'ap_outbox_media_count',
    'ap_outbox_replies_count',
    'ap_outbox_count_for_actor',
    'ap_outbox_cached_count',
    'ap_outbox_drop_profile_note_caches',
    'ap_outbox_shared_list_page',
    'ap_outbox_shared_count',
    'ap_local_profile_tab_page',
    'ap_blog_tags_decode',
    'ap_blog_posts_list',
    'ap_blog_posts_count',
    'ap_masto_pinned_statuses',
    'ap_masto_reblog_rows_for_html_profile',
    'ap_masto_reblog_count_for_html_profile',
]);
reference_load($root . '/api/ap-feeds.php', [
    'ap_feed_public_rows',
]);
reference_load($root . '/api/ap-featured.php', [
    'ap_featured_schema_ensure',
    'ap_featured_list',
    'ap_featured_count',
]);

$postsPredicate = ap_outbox_profile_reply_predicate(false);
$repliesPredicate = ap_outbox_profile_reply_predicate(true);
$check($postsPredicate === "TRIM(COALESCE(in_reply_to, '')) = ''", 'posts predicate keeps NULL and blank in_reply_to');
$check($repliesPredicate === "TRIM(COALESCE(in_reply_to, '')) <> ''", 'replies predicate is the public reply count rule');

$php = (string) file_get_contents($root . '/api/ap-db.php');
$check(str_contains($php, "SELECT COUNT(*) FROM outbox_notes WHERE id LIKE ? AND COALESCE(visibility, 'public') = 'public' AND " . $repliesPredicate), 'reply count uses the replies page predicate');
$check(!str_contains($php, 'btrim(in_reply_to)'), 'profile paging does not use a separate btrim rule');

$rustSource = (string) file_get_contents($root . '/rust/vaak-worker/src/profile_source.rs');
$rustHtml = (string) file_get_contents($root . '/rust/vaak-worker/src/profile_html.rs');
$rustConst = static function (string $source, string $name): string {
    if (!preg_match('/const ' . preg_quote($name, '/') . ': &str = "([^"]+)";/', $source, $match)) {
        return '';
    }
    return $match[1];
};
$check($rustConst($rustSource, 'PROFILE_POSTS_REPLY_SQL') === $postsPredicate, 'Rust posts page uses the PHP posts predicate');
$check($rustConst($rustSource, 'PROFILE_REPLIES_REPLY_SQL') === $repliesPredicate, 'Rust replies page uses the PHP replies predicate');
$check(str_contains($rustSource, 'PROFILE_POSTS_REPLY_SQL') && str_contains($rustSource, 'PROFILE_REPLIES_REPLY_SQL'), 'Rust profile source selects with the shared predicates');
$check(str_contains($rustHtml, 'PROFILE_POSTS_REPLY_SQL') && str_contains($rustHtml, 'PROFILE_REPLIES_REPLY_SQL'), 'Rust profile HTML selects with the shared predicates');
$check(!str_contains($rustSource, 'btrim(in_reply_to)'), 'Rust profile source does not keep a second reply rule');

$user = (string) file_get_contents($root . '/api/ap-user.php');
$check(str_contains($user, 'ap_outbox_posts_list_page'), 'HTML Posts tab pages the filtered posts query');
$check(!str_contains($user, 'FROM outbox_notes WHERE actor_key'), 'profile ETag does not query a missing actor_key column');
$check(str_contains($user, 'SELECT MAX(published) FROM outbox_notes WHERE id LIKE ?'), 'profile ETag tracks the newest note by id prefix');
$storeStart = strpos($php, 'function ap_outbox_store');
$storeEnd = strpos($php, 'function ap_blog_slug');
$store = ($storeStart !== false && $storeEnd !== false) ? substr($php, $storeStart, $storeEnd - $storeStart) : '';
$dropStart = strpos($php, 'function ap_outbox_drop_profile_note_caches');
$dropEnd = strpos($php, 'function ap_local_profile_tab_page');
$drop = ($dropStart !== false && $dropEnd !== false) ? substr($php, $dropStart, $dropEnd - $dropStart) : '';
$check(str_contains($store, "function_exists('ap_outbox_drop_profile_note_caches')"), 'storing a note drops profile caches when that helper is loaded');
$check(str_contains($drop, 'vaak:profile:outbox-count:v2:') && str_contains($drop, 'vaak:profile:reply-count:v2:'), 'a stored note drops the public profile count caches');
$check(str_contains($drop, 'vaak:profile:media-count:v2:') && str_contains($drop, 'vaak:profile:media-count:v1:'), 'a stored note drops the current and previous media counts');
$check(str_contains($drop, 'vaak:profile:outbox-shared-count:v1:'), 'a stored note drops the public outbox collection count');
$check(str_contains($drop, 'ap_public_doc_invalidate_note') && str_contains($drop, 'ap_public_doc_invalidate_actor'), 'a stored note drops the public note and actor documents');
$check(str_contains($drop, "function_exists('ap_redis_delete')") && str_contains($drop, "function_exists('ap_public_doc_invalidate_note')"), 'cache drops are optional so isolated stores still insert');

$actor = 'pageflux';
$prefix = 'https://mkultra.monster/users/' . $actor . '/notes/';
$replyTo = static fn(string $id): string => '  https://mkultra.monster/users/ada/notes/' . $id . '  ';
$stamp = static fn(int $n): string => sprintf('2026-10-17T00:00:%02dZ', $n);

$GLOBALS['db'] = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$GLOBALS['db']->exec('CREATE TABLE outbox_notes (
    id TEXT PRIMARY KEY,
    content TEXT,
    in_reply_to TEXT,
    visibility TEXT,
    raw_create_json TEXT,
    published TEXT
)');
$insert = $GLOBALS['db']->prepare('INSERT INTO outbox_notes (id, content, in_reply_to, visibility, raw_create_json, published) VALUES (?, ?, ?, ?, ?, ?)');

$posts = [];
$replies = [];
$time = 40;
// The newest public notes are replies. A mixed LIMIT of one page would
// contain no posts and used to stop before the older top-level posts.
for ($i = 0; $i < 5; $i++) {
    $id = $prefix . 'reply-new-' . $i;
    $insert->execute([$id, 'reply ' . $i, $replyTo('parent-' . $i), 'public', '{}', $stamp($time)]);
    $replies[] = $id;
    $time--;
}
$blankReply = [null, '', '   '];
for ($i = 0; $i < 8; $i++) {
    if ($i % 2 === 1) {
        $id = $prefix . 'reply-mixed-' . $i;
        $insert->execute([$id, 'mixed reply ' . $i, $replyTo('mixed-' . $i), 'public', '{}', $stamp($time)]);
        $replies[] = $id;
        $time--;
    }
    $id = $prefix . 'post-' . $i;
    $marker = $i === 3
        ? '<p><a class="mention hashtag" href="https://mkultra.monster/tags/flux">#flux</a></p>'
        : ($i === 4 ? '<p><a class="mention" href="https://mkultra.monster/users/ada">@ada</a></p>' : 'post ' . $i);
    $insert->execute([$id, $marker, $blankReply[$i % 3], 'public', '{}', $stamp($time)]);
    $posts[] = $id;
    $time--;
}
$insert->execute([$prefix . 'private-post', 'hidden', null, 'private', '{}', $stamp(39)]);
$insert->execute([$prefix . 'local-post', 'local', '', 'local', '{}', $stamp(38)]);
$insert->execute(['https://mkultra.monster/users/other/notes/post', 'other', null, 'public', '{}', $stamp(37)]);
$insert->execute([$prefix . 'private-reply', 'secret', $replyTo('secret'), 'private', '{}', $stamp(36)]);

$expectedPosts = $posts;
$expectedReplies = $replies;
$check(count($expectedPosts) === 8, 'fixture has more posts than one page');
$check(count($expectedReplies) === 9, 'fixture mixes replies into the same published range');

$replyCount = ap_outbox_replies_count($actor);
$total = ap_outbox_count_for_actor($actor);
$postCount = max(0, $total - $replyCount);
$check($replyCount === count($expectedReplies), 'reply count matches the public replies, got ' . $replyCount);
$check($postCount === count($expectedPosts), 'posts count matches the public top-level notes, got ' . $postCount);

$mixed = $GLOBALS['db']->query(
    "SELECT id, in_reply_to, visibility FROM outbox_notes
     WHERE id LIKE " . $GLOBALS['db']->quote($prefix . '%') . "
       AND COALESCE(visibility, 'public') = 'public'
     ORDER BY published DESC LIMIT 3"
)->fetchAll();
$mixedPosts = 0;
foreach ($mixed as $row) {
    if (trim((string) ($row['in_reply_to'] ?? '')) === '') {
        $mixedPosts++;
    }
}
$check($mixedPosts === 0, 'newest mixed page contains replies rather than a full posts page, got ' . $mixedPosts);

$walk = static function (string $tab, int $pageSize, ?string $walkActor = null) use ($actor, $check): array {
    $walkActor = $walkActor ?? $actor;
    $offset = 0;
    $seen = [];
    $pages = 0;
    while ($pages < 10) {
        $page = ap_local_profile_tab_page($walkActor, 'https://mkultra.monster/users/' . $walkActor, $tab, $pageSize, $offset);
        $pages++;
        $ids = array_map(static fn(array $row): string => (string) $row['id'], $page['items']);
        $check(count($ids) === count(array_unique($ids)), $tab . ' page has no duplicate ids');
        foreach ($ids as $id) {
            $check(!in_array($id, $seen, true), $tab . ' cursor does not repeat ' . $id);
            $seen[] = $id;
        }
        $check($page['next_offset'] === $offset + count($ids), $tab . ' next offset moves by the filtered page');
        if (count($ids) < $pageSize) {
            $check(!$page['has_more'], $tab . ' short filtered page is the end, offset ' . $offset);
        }
        if (!$page['has_more']) {
            break;
        }
        $check(count($ids) === $pageSize, $tab . ' has-more stays set only for a full filtered page, offset ' . $offset);
        $offset = (int) $page['next_offset'];
    }
    $check($pages >= 2, $tab . ' needs more than one page');
    return $seen;
};

$pageSize = 3;
$seenPosts = $walk('posts', $pageSize);
$seenReplies = $walk('replies', $pageSize);
$check($seenPosts === $expectedPosts, 'posts pages list every counted top-level note and no replies');
$check($seenReplies === $expectedReplies, 'replies pages list every counted reply and no top-level posts');
$check($seenPosts[0] === $prefix . 'post-0', 'older posts still load when newer notes are replies');

$listed = array_map(
    static fn(array $row): string => (string) $row['id'],
    ap_outbox_posts_list_page($actor, $pageSize, $pageSize)
);
$check($listed === array_slice($expectedPosts, $pageSize, $pageSize), 'posts list offset skips replies instead of notes');
$listedReplies = array_map(
    static fn(array $row): string => (string) $row['id'],
    ap_outbox_replies_list_page($actor, $pageSize, 0)
);
$check($listedReplies === array_slice($expectedReplies, 0, $pageSize), 'replies list offset starts at the newest real replies');
$check(ap_outbox_posts_list_page('', 3, 0) === [] && ap_outbox_replies_list_page(' ', 3, 0) === [], 'blank actor pages are empty');

$head = 'posthead';
$headPrefix = 'https://mkultra.monster/users/' . $head . '/notes/';
$headPosts = [];
$headTime = 70;
$headBlank = [null, '', '   '];
for ($i = 0; $i < 4; $i++) {
    $id = $headPrefix . 'new-post-' . $i;
    $marker = $i === 2
        ? '<p><a class="mention hashtag" href="https://mkultra.monster/tags/flux">#flux</a></p>'
        : ($i === 3 ? '<p><a class="mention" href="https://mkultra.monster/users/ada">@ada</a></p>' : 'newest post ' . $i);
    $insert->execute([$id, $marker, $headBlank[$i % 3], 'public', '{}', $stamp($headTime)]);
    $headPosts[] = $id;
    $headTime--;
}
for ($i = 0; $i < 3; $i++) {
    $id = $headPrefix . 'old-reply-' . $i;
    $insert->execute([$id, 'older reply ' . $i, $replyTo('head-' . $i), 'public', '{}', $stamp($headTime)]);
    $headTime--;
    $id = $headPrefix . 'old-post-' . $i;
    $insert->execute([$id, 'older post ' . $i, null, 'public', '{}', $stamp($headTime)]);
    $headPosts[] = $id;
    $headTime--;
}
$headTotal = ap_outbox_count_for_actor($head);
$headReplies = ap_outbox_replies_count($head);
$check($headReplies === 3 && max(0, $headTotal - $headReplies) === count($headPosts), 'newest-post fixture count matches the posts walk');
$headPageSize = 3;
$headFirst = ap_local_profile_tab_page($head, 'https://mkultra.monster/users/' . $head, 'posts', $headPageSize, 0);
$headFirstIds = array_map(static fn(array $row): string => (string) $row['id'], $headFirst['items']);
$check($headFirstIds === array_slice($headPosts, 0, $headPageSize), 'first page starts at the newest posts');
$check($headFirstIds !== array_slice($headPosts, $headPageSize, $headPageSize), 'first page is not shifted by the page size');
$check($headFirst['has_more'] === true && $headFirst['next_offset'] === $headPageSize, 'first page cursor is the next post, not the page start');
$headListed = array_map(
    static fn(array $row): string => (string) $row['id'],
    ap_outbox_posts_list_page($head, $headPageSize, 0)
);
$check($headListed === $headFirstIds, 'posts list offset 0 is the same first page');
$headSeen = $walk('posts', $headPageSize, $head);
$check($headSeen === $headPosts, 'walking from the first page returns every newest-first post once');
$check($headSeen[0] === $headPrefix . 'new-post-0', 'the walk includes the newest post');
$check(count($headSeen) === count(array_unique($headSeen)), 'the walk has no duplicate posts');

$ten = 'postten';
$tenPrefix = 'https://mkultra.monster/users/' . $ten . '/notes/';
$tenPosts = [];
for ($i = 0; $i < 10; $i++) {
    $id = $tenPrefix . 'only-post-' . $i;
    $insert->execute([$id, 'only post ' . $i, null, 'public', '{}', $stamp(30 - $i)]);
    $tenPosts[] = $id;
}
$tenPage = ap_local_profile_tab_page($ten, 'https://mkultra.monster/users/' . $ten, 'posts', 12, 0);
$tenIds = array_map(static fn(array $row): string => (string) $row['id'], $tenPage['items']);
$check($tenIds === $tenPosts, 'one page of only posts starts at the newest and includes all ten');
$check($tenPage['has_more'] === false && $tenPage['next_offset'] === 10, 'a short first page of posts is the end');
$tenShifted = ap_local_profile_tab_page($ten, 'https://mkultra.monster/users/' . $ten, 'posts', 12, 2);
$tenShiftedIds = array_map(static fn(array $row): string => (string) $row['id'], $tenShifted['items']);
$check($tenShiftedIds === array_slice($tenPosts, 2), 'offset 2 is a later page, not the first page');
$check($tenIds !== $tenShiftedIds, 'the first page is not the offset-2 page');

$actorKeyMissing = false;
try {
    $GLOBALS['db']->query("SELECT COALESCE(MAX(published), '') FROM outbox_notes WHERE actor_key = " . $GLOBALS['db']->quote($head));
} catch (Throwable $e) {
    $actorKeyMissing = true;
}
$check($actorKeyMissing, 'the old actor_key ETag query cannot see outbox notes');
$latest = $GLOBALS['db']->prepare('SELECT MAX(published) FROM outbox_notes WHERE id LIKE ?');
$latest->execute([$headPrefix . '%']);
$check((string) ($latest->fetchColumn() ?: '') === $stamp(70), 'id prefix max published is the newest post');

$GLOBALS['dropped'] = [];
function ap_redis_delete(string ...$keys): void
{
    foreach ($keys as $key) {
        $GLOBALS['dropped'][] = $key;
    }
}
function ap_public_doc_invalidate_note(string $noteId, ?string $createId = null): void
{
    $GLOBALS['dropped'][] = 'note:' . $noteId . ':' . (string) $createId;
}
function ap_public_doc_invalidate_actor(string $actorKey): void
{
    $GLOBALS['dropped'][] = 'actor:' . $actorKey;
}
ap_outbox_drop_profile_note_caches([
    'id' => $headPrefix . 'new-post-0',
    'create_id' => $headPrefix . 'new-post-0/activity',
]);
$check(in_array('vaak:profile:outbox-count:v2:' . $head, $GLOBALS['dropped'], true), 'a new post drops the public outbox count');
$check(in_array('vaak:profile:reply-count:v2:' . $head, $GLOBALS['dropped'], true), 'a new post drops the reply count');
$check(in_array('note:' . $headPrefix . 'new-post-0:' . $headPrefix . 'new-post-0/activity', $GLOBALS['dropped'], true), 'a new post drops its public document');
$check(in_array('actor:' . $head, $GLOBALS['dropped'], true), 'a new post drops the actor document');
$check(in_array('vaak:profile:media-count:v2:' . $head, $GLOBALS['dropped'], true), 'a new post drops the public media count');
$beforeRemote = count($GLOBALS['dropped']);
ap_outbox_drop_profile_note_caches(['id' => 'https://remote.test/notes/1']);
$check(count($GLOBALS['dropped']) === $beforeRemote, 'a remote note does not drop local profile caches');

$mediaActor = 'mediawalk';
$mediaPrefix = 'https://mkultra.monster/users/' . $mediaActor . '/notes/';
$attach = '{"object":{"attachment":[{"type":"Image","mediaType":"image/jpeg","url":"https://files.example/a.jpg"}]}}';
$mediaExpected = [];
$mediaTime = 90;
$insert->execute([$mediaPrefix . 'private-new', 'hidden media', null, 'private', $attach, $stamp($mediaTime)]);
$mediaTime--;
$id = $mediaPrefix . 'reply-new';
$insert->execute([$id, 'reply media', $replyTo('media-parent'), 'public', $attach, $stamp($mediaTime)]);
$mediaExpected[] = $id;
$mediaTime--;
$id = $mediaPrefix . 'post-new';
$insert->execute([$id, 'post media', null, 'public', $attach, $stamp($mediaTime)]);
$mediaExpected[] = $id;
$mediaTime--;
$insert->execute([$mediaPrefix . 'plain', 'no attachment', null, 'public', '{}', $stamp($mediaTime)]);
$mediaTime--;
$insert->execute([$mediaPrefix . 'local-media', 'local media', null, 'local', $attach, $stamp($mediaTime)]);
$mediaTime--;
for ($i = 0; $i < 3; $i++) {
    $id = $mediaPrefix . 'post-old-' . $i;
    $insert->execute([$id, 'older media ' . $i, '', 'public', $attach, $stamp($mediaTime)]);
    $mediaExpected[] = $id;
    $mediaTime--;
}
$mediaPageSize = 2;
$mediaFirst = ap_local_profile_tab_page($mediaActor, 'https://mkultra.monster/users/' . $mediaActor, 'media', $mediaPageSize, 0);
$mediaFirstIds = array_map(static fn(array $row): string => (string) $row['id'], $mediaFirst['items']);
$mediaListed = array_map(
    static fn(array $row): string => (string) $row['id'],
    ap_outbox_media_list_page($mediaActor, $mediaPageSize, 0)
);
$check($mediaFirstIds === array_slice($mediaExpected, 0, $mediaPageSize), 'media first page starts at the newest public attachment');
$check($mediaFirstIds === $mediaListed, 'classic media list matches the in-app media page');
$check($mediaFirstIds !== array_slice($mediaExpected, $mediaPageSize, $mediaPageSize), 'media first page is not shifted by the page size');
$check(!in_array($mediaPrefix . 'private-new', $mediaFirstIds, true), 'a newer private attachment does not take the first media slot');
$check($mediaFirstIds[0] === $mediaPrefix . 'reply-new', 'a newer public reply attachment stays on the first media page');
$mediaSeen = $walk('media', $mediaPageSize, $mediaActor);
$mediaOffset = 0;
$mediaClassic = [];
while ($mediaOffset < 20) {
    $pageIds = array_map(
        static fn(array $row): string => (string) $row['id'],
        ap_outbox_media_list_page($mediaActor, $mediaPageSize, $mediaOffset)
    );
    foreach ($pageIds as $pageId) {
        $check(!in_array($pageId, $mediaClassic, true), 'classic media page does not repeat ' . $pageId);
        $mediaClassic[] = $pageId;
    }
    if (count($pageIds) < $mediaPageSize) {
        break;
    }
    $mediaOffset += $mediaPageSize;
}
$check($mediaSeen === $mediaExpected, 'walking media returns every public attachment once, newest first');
$check($mediaClassic === $mediaExpected, 'walking the classic media list returns the same attachments');
$check(ap_outbox_media_count($mediaActor) === count($mediaExpected), 'media count matches the walked public attachments');
$check(substr_count($php, "COALESCE(visibility, \\'public\\') = \\'public\\' AND raw_create_json LIKE \\'%\"attachment\"%\\'") === 2, 'media count and list share the public attachment predicate');
$check(str_contains($user, "\$tab !== 'media' && function_exists('ap_outbox_note_is_reply')"), 'classic media does not drop replies after the page is fetched');
$check(str_contains($user, 'ap_user_post_preview_html($actorKey, $mediaRow)'), 'a media row with no gallery still renders on its page');
$admin = (string) file_get_contents($root . '/api/ap-admin.php');
$headerRust = (string) file_get_contents($root . '/rust/vaak-worker/src/profile_header.rs');
$check(str_contains($headerRust, 'vaak:profile:media-count:v2:') && !str_contains($headerRust, 'media-count:v1:'), 'Rust media count uses the public v2 cache key');
$check(str_contains((string) file_get_contents($root . '/api/bin/local-profile-header-reference.php'), 'vaak:profile:media-count:v2:fixture'), 'header fixture seeds the v2 media count');

$blogActor = 'blogwalk';
$GLOBALS['db']->exec('CREATE TABLE vaak_blog_posts (
    id INTEGER PRIMARY KEY,
    actor_key TEXT,
    slug TEXT,
    title TEXT,
    status TEXT,
    published_at TEXT,
    updated_at TEXT,
    excerpt TEXT,
    tags_json TEXT,
    body_markdown TEXT
)');
$blogInsert = $GLOBALS['db']->prepare('INSERT INTO vaak_blog_posts (id, actor_key, slug, title, status, published_at, updated_at, excerpt, tags_json, body_markdown) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$blogExpected = [];
for ($i = 0; $i < 7; $i++) {
    $slug = 'entry-' . $i;
    $blogInsert->execute([$i + 1, $blogActor, $slug, 'Entry ' . $i, 'published', $stamp(80 - $i), $stamp(80 - $i), 'excerpt', '[]', 'body']);
    $blogExpected[] = $slug;
}
$blogInsert->execute([90, $blogActor, 'draft-new', 'Draft', 'draft', $stamp(99), $stamp(99), '', '[]', '']);
$blogInsert->execute([91, 'otherblog', 'other', 'Other', 'published', $stamp(98), $stamp(98), '', '[]', '']);
$blogPageSize = 3;
$blogSeen = [];
$blogOffset = 0;
$blogPages = 0;
while ($blogPages < 10) {
    $rows = ap_blog_posts_list($blogActor, true, $blogPageSize, $blogOffset);
    $blogPages++;
    $slugs = array_map(static fn(array $row): string => (string) $row['slug'], $rows);
    $check(count($slugs) === count(array_unique($slugs)), 'blog page has no duplicate slugs');
    foreach ($slugs as $slug) {
        $check(!in_array($slug, $blogSeen, true), 'blog cursor does not repeat ' . $slug);
        $blogSeen[] = $slug;
    }
    if (count($slugs) < $blogPageSize) {
        break;
    }
    $blogOffset += $blogPageSize;
}
$check($blogPages >= 2, 'blog needs more than one page');
$check($blogSeen === $blogExpected, 'walking blog returns every published post once, newest first');
$check(array_slice($blogSeen, 0, $blogPageSize) === array_slice($blogExpected, 0, $blogPageSize), 'blog first page is not shifted by the page size');
$check(ap_blog_posts_count($blogActor, true) === count($blogExpected), 'blog count matches the walked posts');
$check(str_contains($user, 'ap_blog_posts_list($actorKey, true, $profilePerPage, max(0, ($profilePage - 1) * $profilePerPage))'), 'classic blog uses one page size for the limit and the offset');
$check(!str_contains($user, 'ap_blog_posts_list($actorKey, true, 20,'), 'classic blog no longer pages 20 rows against a 40-row page count');
$check(str_contains($admin, '($rpBlogPage - 1) * $rpBlogPerPage'), 'in-app blog offsets by the same page size it fetches');
$check(str_contains($admin, 'Older blog posts'), 'in-app blog links to the next page when the count continues');
$check(substr_count($headerRust, 'PROFILE_BLOG_PAGE_SQL,&[') === 2, 'Rust blog select and revalidation share the offset query');
$check(str_contains((string) file_get_contents($root . '/api/ap-profile-native.php'), "\$request['page']"), 'native profile fetch passes the blog page');
$native = (string) file_get_contents($root . '/api/ap-profile-native.php');
$check(str_contains($native, "\$page===['source_count'=>0,'next_offset'=>0,'has_more'=>false]"), 'blog fragment source page stays the capped contract');

$boostOwner = 4;
$GLOBALS['db']->exec('CREATE TABLE masto_reblogs (
    id INTEGER PRIMARY KEY,
    owner_user_id INTEGER,
    object_id TEXT,
    status_id TEXT,
    created_at TEXT
)');
$boostInsert = $GLOBALS['db']->prepare('INSERT INTO masto_reblogs (id, owner_user_id, object_id, status_id, created_at) VALUES (?, ?, ?, ?, ?)');
$boostExpected = [];
for ($i = 0; $i < 8; $i++) {
    $object = 'https://remote.test/boost-' . $i;
    $boostInsert->execute([$i + 1, $boostOwner, $object, 'status-' . $i, $stamp(60 - $i)]);
    $boostExpected[] = $object;
}
$boostInsert->execute([50, 9, 'https://remote.test/other', 'other', $stamp(70)]);
$boostPageSize = 3;
$boostSeen = [];
$boostOffset = 0;
$boostPages = 0;
while ($boostPages < 10) {
    $rows = ap_masto_reblog_rows_for_html_profile($boostOwner, $boostPageSize, $boostOffset);
    $boostPages++;
    $objects = array_map(static fn(array $row): string => (string) $row['object_id'], $rows);
    $check(count($objects) === count(array_unique($objects)), 'boost page has no duplicate objects');
    foreach ($objects as $object) {
        $check(!in_array($object, $boostSeen, true), 'boost cursor does not repeat ' . $object);
        $boostSeen[] = $object;
    }
    if (count($objects) < $boostPageSize) {
        break;
    }
    $boostOffset += $boostPageSize;
}
$check($boostPages >= 2, 'boosts need more than one page');
$check($boostSeen === $boostExpected, 'walking boosts returns every row once, newest first');
$check(array_slice($boostSeen, 0, $boostPageSize) !== array_slice($boostExpected, $boostPageSize, $boostPageSize), 'boost first page is not shifted by the page size');
$check(ap_masto_reblog_count_for_html_profile($boostOwner) === count($boostExpected), 'boost count matches the walked rows');
$check(str_contains($user, 'ap_masto_reblog_rows_for_html_profile($ownerId, $profilePerPage, ($profilePage - 1) * $profilePerPage)'), 'classic boosts offset with the profile page size');
$check(str_contains($php, 'SELECT DISTINCT ON (object_id)'), 'in-app boosts collapse one object before the page offset');
$check(str_contains($admin, '$profileOffset = max(0, (int) ($_GET[\'offset\'] ?? 0));'), 'profile scroll starts at offset 0 when the request omits it');

$GLOBALS['db']->exec('CREATE TABLE masto_pins (status_local_id INTEGER, owner_user_id INTEGER, pinned_at TEXT)');
$GLOBALS['db']->exec('CREATE TABLE masto_statuses (local_id INTEGER PRIMARY KEY, note_id TEXT, content TEXT)');
$pinInsert = $GLOBALS['db']->prepare('INSERT INTO masto_pins (status_local_id, owner_user_id, pinned_at) VALUES (?, 7, ?)');
$statusInsert = $GLOBALS['db']->prepare('INSERT INTO masto_statuses (local_id, note_id, content) VALUES (?, ?, ?)');
$pinExpected = [];
for ($i = 0; $i < 7; $i++) {
    $note = 'https://mkultra.monster/users/pinwalk/notes/pin-' . $i;
    $pinInsert->execute([$i + 1, $stamp(50 - $i)]);
    $statusInsert->execute([$i + 1, $note, 'pin ' . $i]);
    if ($i < 5) {
        $pinExpected[] = $note;
    }
}
$pins = array_map(
    static fn(array $row): string => (string) $row['note_id'],
    ap_masto_pinned_statuses(40, 'pinwalk')
);
$check($pins === $pinExpected, 'pinned tab is the five newest pins and does not start later');
$check(count($pins) === count(array_unique($pins)), 'pinned tab has no duplicate notes');
$check(ap_masto_pinned_statuses(5, 'pinwalk') === ap_masto_pinned_statuses(40, 'pinwalk'), 'the profile pin cap is the whole pinned list');

$featuredOwner = 11;
$featured = ap_featured_list($featuredOwner);
$check($featured === [], 'featured list is empty before accounts are saved');
$featInsert = $GLOBALS['db']->prepare('INSERT INTO ap_featured_accounts (owner_user_id, target_actor_id, position, created_at) VALUES (?, ?, ?, ?)');
$featuredExpected = [];
for ($i = 0; $i < 45; $i++) {
    $actorId = 'https://remote.test/users/feat-' . $i;
    $featInsert->execute([$featuredOwner, $actorId, $i, $stamp(10)]);
    if ($i < 40) {
        $featuredExpected[] = $actorId;
    }
}
$featuredIds = array_map(
    static fn(array $row): string => (string) $row['target_actor_id'],
    ap_featured_list($featuredOwner)
);
$check($featuredIds === $featuredExpected, 'featured tab is the first 40 by position and does not skip the front');
$check(count($featuredIds) === count(array_unique($featuredIds)), 'featured tab has no duplicate accounts');
$check(ap_featured_count($featuredOwner) === count($featuredIds), 'featured count is the loaded list');

$GLOBALS['db']->exec('CREATE TABLE ap_asks (
    id INTEGER PRIMARY KEY,
    owner_user_id INTEGER,
    answered INTEGER,
    answer_note_id TEXT,
    question TEXT,
    asker_actor TEXT,
    created_at TEXT
)');
$askSql = "SELECT answer_note_id, question, asker_actor, created_at FROM ap_asks WHERE owner_user_id = ? AND answered = 1 AND answer_note_id IS NOT NULL AND answer_note_id <> '' ORDER BY created_at DESC LIMIT 200";
$check(str_contains($admin, "answer_note_id <> \\'\\' ORDER BY created_at DESC LIMIT 200"), 'asks tab uses the newest 200 answers');
$check(!preg_match('/FROM ap_asks WHERE owner_user_id = \? AND answered = 1[\s\S]{0,180}OFFSET/', $admin), 'asks window has no offset');
$askInsert = $GLOBALS['db']->prepare('INSERT INTO ap_asks (id, owner_user_id, answered, answer_note_id, question, asker_actor, created_at) VALUES (?, 3, 1, ?, ?, ?, ?)');
$askExpected = [];
for ($i = 0; $i < 205; $i++) {
    $note = 'https://mkultra.monster/users/askwalk/notes/ask-' . $i;
    $askInsert->execute([$i + 1, $note, 'q' . $i, 'https://remote.test/users/asker', $stamp(100) . sprintf('%03d', 204 - $i)]);
    if ($i < 200) {
        $askExpected[] = $note;
    }
}
$askRows = $GLOBALS['db']->prepare($askSql);
$askRows->execute([3]);
$askSeen = array_map(static fn(array $row): string => (string) $row['answer_note_id'], $askRows->fetchAll());
$check($askSeen === $askExpected, 'asks window starts at the newest answer and stops at 200');
$check(count($askSeen) === count(array_unique($askSeen)), 'asks window has no duplicate answers');

$order = (string) file_get_contents($root . '/api/ap-profile-html.php');
$check(str_contains($order, "['pinned', 'posts', 'replies', 'boosts', 'media', 'featured', 'blog']"), 'shared profile tab order has no likes tab');
$check(str_contains($user, "['pinned', 'posts', 'media', 'replies', 'boosts', 'featured', 'blog']"), 'classic profile has no likes tab');
$check(str_contains($admin, "['pinned', 'posts', 'replies', 'boosts', 'media', 'asks', 'featured', 'blog']"), 'in-app profile adds asks and has no likes tab');

// Guest and other logged-out profile surfaces. The in-app guest view paints
// the same remote_profile page, so its tabs use the same SQL. The classic
// HTML page, ActivityPub outbox, and RSS/Atom feed are separate readers.
$guest = 'guestflux';
$guestPrefix = 'https://mkultra.monster/users/' . $guest . '/notes/';
$guestPosts = [];
$guestReplies = [];
$guestMedia = [];
$guestShared = [];
$guestTime = 80;
for ($i = 0; $i < 3; $i++) {
    $insert->execute([$guestPrefix . 'private-' . $i, 'private ' . $i, null, 'private', '{}', $stamp($guestTime)]);
    $guestTime--;
}
for ($i = 0; $i < 2; $i++) {
    $id = $guestPrefix . 'unlisted-' . $i;
    $insert->execute([$id, 'unlisted ' . $i, null, 'unlisted', '{}', $stamp($guestTime)]);
    $guestShared[] = $id;
    $guestTime--;
}
for ($i = 0; $i < 2; $i++) {
    $insert->execute([$guestPrefix . 'local-' . $i, 'local ' . $i, '', 'local', '{}', $stamp($guestTime)]);
    $guestTime--;
}
for ($i = 0; $i < 8; $i++) {
    $id = $guestPrefix . 'post-' . $i;
    $content = $i === 1
        ? '<p><a class="mention hashtag" href="https://mkultra.monster/tags/flux">#flux</a></p>'
        : ($i === 2 ? '<p><a class="mention" href="https://mkultra.monster/users/ada">@ada</a></p>' : 'guest post ' . $i);
    $raw = $i < 2 ? '{"attachment":[{"type":"Image"}]}' : '{}';
    $insert->execute([$id, $content, $i % 2 === 0 ? null : '', 'public', $raw, $stamp($guestTime)]);
    $guestPosts[] = $id;
    $guestShared[] = $id;
    if ($i < 2) {
        $guestMedia[] = $id;
    }
    $guestTime--;
}
for ($i = 0; $i < 4; $i++) {
    $id = $guestPrefix . 'reply-' . $i;
    $raw = $i === 0 ? '{"attachment":[{"type":"Image"}]}' : '{}';
    $insert->execute([$id, 'guest reply ' . $i, $replyTo('guest-' . $i), 'public', $raw, $stamp($guestTime)]);
    $guestReplies[] = $id;
    $guestShared[] = $id;
    if ($i === 0) {
        $guestMedia[] = $id;
    }
    $guestTime--;
}
$guestPublicTotal = ap_outbox_count_for_actor($guest);
$guestReplyTotal = ap_outbox_replies_count($guest);
$guestPostTotal = max(0, $guestPublicTotal - $guestReplyTotal);
$check($guestPostTotal === count($guestPosts), 'guest Posts header counts public notes minus real replies');
$check($guestReplyTotal === count($guestReplies), 'guest Replies header counts only public notes with in_reply_to');
$check(ap_outbox_media_count($guest) === count($guestMedia), 'guest Media header counts public attachments, including a reply');
$check(ap_outbox_shared_count($guest) === count($guestShared), 'public outbox total counts public and unlisted notes');
$check($guestPostTotal === 8 && $guestReplyTotal === 4, 'guest counts leave hashtags and mentions on Posts');

$guestWalk = static function (string $tab, array $expected, int $pageSize) use ($check, $guest): array {
    $seen = [];
    $offset = 0;
    $pages = 0;
    while ($pages < 12) {
        $page = ap_local_profile_tab_page($guest, 'https://mkultra.monster/users/' . $guest, $tab, $pageSize, $offset);
        $pages++;
        $ids = array_map(static fn(array $row): string => (string) $row['id'], $page['items']);
        $check(count($ids) === count(array_unique($ids)), 'guest ' . $tab . ' page has no duplicates');
        foreach ($ids as $id) {
            $check(!in_array($id, $seen, true), 'guest ' . $tab . ' cursor does not repeat an item');
            $seen[] = $id;
        }
        $check((int) $page['next_offset'] === $offset + count($ids), 'guest ' . $tab . ' next offset follows the rows just returned');
        if (empty($page['has_more'])) {
            break;
        }
        $offset = (int) $page['next_offset'];
    }
    $check($seen === $expected, 'guest ' . $tab . ' walk returns every public row, newest first');
    $check($pages > 1, 'guest ' . $tab . ' walk crosses a page boundary');
    return $seen;
};
$guestWalk('posts', $guestPosts, 3);
$guestWalk('replies', $guestReplies, 2);
$guestWalk('media', $guestMedia, 2);
$check(array_slice($guestWalk('posts', $guestPosts, 3), 0, 1) === [$guestPosts[0]], 'guest posts first page starts at the newest public post');

$classicPage = 40;
$classicActor = 'publichtml';
$classicPrefix = 'https://mkultra.monster/users/' . $classicActor . '/notes/';
$classicExpected = [];
for ($i = 0; $i < 210; $i++) {
    $id = $classicPrefix . 'post-' . $i;
    $insert->execute([$id, 'html ' . $i, null, 'public', '{}', sprintf('2026-10-16T00:%02d:%02dZ', intdiv(209 - $i, 60), (209 - $i) % 60)]);
    $classicExpected[] = $id;
}
$insert->execute([$classicPrefix . 'reply-hidden', 'reply', $replyTo('html'), 'public', '{}', '2026-10-16T23:59:59Z']);
$insert->execute([$classicPrefix . 'private-hidden', 'private', null, 'private', '{}', '2026-10-16T23:59:58Z']);
$classicSeen = [];
$classicPageNo = 1;
while ($classicPageNo < 20) {
    $window = min(5000, max($classicPage, $classicPageNo * $classicPage));
    $rows = ap_outbox_posts_list_page($classicActor, $window, 0);
    $slice = array_slice($rows, ($classicPageNo - 1) * $classicPage, $classicPage);
    $ids = array_map(static fn(array $row): string => (string) $row['id'], $slice);
    foreach ($ids as $id) {
        $check(!in_array($id, $classicSeen, true), 'public HTML posts page does not repeat ' . $id);
        $classicSeen[] = $id;
    }
    if (count($ids) < $classicPage) {
        break;
    }
    $classicPageNo++;
}
$check($classicSeen === $classicExpected, 'public HTML posts walk passes the old 200-row cap');
$check($classicPageNo > 5, 'public HTML posts walk uses more than five pages');
$check(str_contains($user, 'ap_outbox_posts_list_page(' . "\n" . '            $actorKey,' . "\n" . '            $profileFetchLimit,'), 'public HTML posts uses the merged profile prefix');
$check(!str_contains($user, 'min(200, max($profilePerPage, $profilePage * $profilePerPage))'), 'public HTML posts is not capped at 200 rows');
$check(str_contains($user, "['Replies', (function_exists('ap_outbox_replies_count') ? ap_outbox_replies_count(\$actorKey)"), 'public HTML Replies badge uses the reply count');

$classicReplies = [];
$classicReplyOffset = 0;
while (count($classicReplies) < 20) {
    $rows = ap_outbox_replies_list_page($guest, 2, $classicReplyOffset);
    if ($rows === []) {
        break;
    }
    foreach ($rows as $row) {
        $classicReplies[] = (string) $row['id'];
    }
    $classicReplyOffset += 2;
}
$check($classicReplies === $guestReplies, 'public HTML replies walk matches the guest reply count');
$classicMedia = [];
$classicMediaOffset = 0;
while (count($classicMedia) < 20) {
    $rows = ap_outbox_media_list_page($guest, 2, $classicMediaOffset);
    if ($rows === []) {
        break;
    }
    foreach ($rows as $row) {
        $classicMedia[] = (string) $row['id'];
    }
    $classicMediaOffset += 2;
}
$check($classicMedia === $guestMedia, 'public HTML media walk keeps the reply attachment');

$sharedSeen = [];
$sharedOffset = 0;
while (count($sharedSeen) < 40) {
    $rows = ap_outbox_shared_list_page($guest, 4, $sharedOffset);
    if ($rows === []) {
        break;
    }
    foreach ($rows as $row) {
        $sharedSeen[] = (string) $row['id'];
    }
    $sharedOffset += 4;
}
$check($sharedSeen === $guestShared, 'public outbox walk skips private and local notes and keeps unlisted notes');
$check(array_map(static fn(array $row): string => (string) $row['id'], ap_feed_public_rows($guest, 4)) === array_slice($guestShared, 0, 4), 'public RSS and Atom start at the newest shared note');
$check(str_contains((string) file_get_contents($root . '/api/ap-feeds.php'), 'ap_outbox_shared_list_page'), 'RSS and Atom filter visibility in the query');
$check(str_contains($user, 'ap_outbox_shared_list_page'), 'ActivityPub outbox pages the shared query');
$check(str_contains((string) file_get_contents($root . '/api/ap-cmdr-nova.php'), 'ap_outbox_shared_list_page'), 'cmdr outbox pages the shared query');

$guestBoosts = [];
for ($i = 0; $i < 5; $i++) {
    $object = 'https://remote.test/guest-boost-' . $i;
    $boostInsert->execute([60 + $i, 9, $object, 'guest-' . $i, $stamp(40 - $i)]);
    $guestBoosts[] = $object;
}
$guestBoostExpected = array_merge(['https://remote.test/other'], $guestBoosts);
$guestBoostSeen = [];
$guestBoostOffset = 0;
while (count($guestBoostSeen) < 20) {
    $rows = ap_masto_reblog_rows_for_html_profile(9, 2, $guestBoostOffset);
    if ($rows === []) {
        break;
    }
    foreach ($rows as $row) {
        $guestBoostSeen[] = (string) $row['object_id'];
    }
    $guestBoostOffset += 2;
}
$check($guestBoostSeen === $guestBoostExpected, 'guest boosts walk every public boost, newest first');

$guestPinInsert = $GLOBALS['db']->prepare('INSERT INTO masto_pins (status_local_id, owner_user_id, pinned_at) VALUES (?, 9, ?)');
for ($i = 0; $i < 7; $i++) {
    $note = $guestPrefix . 'pin-' . $i;
    $statusInsert->execute([100 + $i, $note, 'guest pin ' . $i]);
    $guestPinInsert->execute([100 + $i, $stamp(30 - $i)]);
}
$guestPins = array_map(
    static fn(array $row): string => (string) $row['note_id'],
    ap_masto_pinned_statuses(40, $guest)
);
$check($guestPins === [
    $guestPrefix . 'pin-0',
    $guestPrefix . 'pin-1',
    $guestPrefix . 'pin-2',
    $guestPrefix . 'pin-3',
    $guestPrefix . 'pin-4',
], 'guest pinned tab is the five newest pins');

$guestFeaturedExpected = [];
for ($i = 0; $i < 42; $i++) {
    $actorId = 'https://remote.test/users/guest-feat-' . $i;
    $featInsert->execute([12, $actorId, $i, $stamp(10)]);
    if ($i < 40) {
        $guestFeaturedExpected[] = $actorId;
    }
}
$guestFeatured = array_map(
    static fn(array $row): string => (string) $row['target_actor_id'],
    ap_featured_list(12)
);
$check($guestFeatured === $guestFeaturedExpected, 'guest featured tab walks the first page of 40');

$guestBlogExpected = [];
for ($i = 0; $i < 5; $i++) {
    $slug = 'guest-' . $i;
    $blogInsert->execute([200 + $i, $guest, $slug, 'Guest ' . $i, 'published', $stamp(20 - $i), $stamp(20 - $i), '', '[]', '']);
    $guestBlogExpected[] = $slug;
}
$blogInsert->execute([230, $guest, 'guest-draft', 'Draft', 'draft', $stamp(29), $stamp(29), '', '[]', '']);
$guestBlogSeen = [];
$guestBlogOffset = 0;
while (count($guestBlogSeen) < 20) {
    $rows = ap_blog_posts_list($guest, true, 2, $guestBlogOffset);
    if ($rows === []) {
        break;
    }
    foreach ($rows as $row) {
        $guestBlogSeen[] = (string) $row['slug'];
    }
    $guestBlogOffset += 2;
}
$check($guestBlogSeen === $guestBlogExpected, 'guest blog walk returns every published post');
$check(ap_blog_posts_count($guest, true) === count($guestBlogExpected), 'guest blog count matches the walked posts');

$guestAskExpected = [];
$guestAskInsert = $GLOBALS['db']->prepare('INSERT INTO ap_asks (id, owner_user_id, answered, answer_note_id, question, asker_actor, created_at) VALUES (?, 9, 1, ?, ?, ?, ?)');
for ($i = 0; $i < 4; $i++) {
    $note = $guestPrefix . 'ask-' . $i;
    $guestAskInsert->execute([300 + $i, $note, 'guest q' . $i, 'https://remote.test/users/asker', $stamp(15) . sprintf('%03d', 3 - $i)]);
    $guestAskExpected[] = $note;
}
$guestAskRows = $GLOBALS['db']->prepare($askSql);
$guestAskRows->execute([9]);
$guestAsks = array_map(static fn(array $row): string => (string) $row['answer_note_id'], $guestAskRows->fetchAll());
$check($guestAsks === $guestAskExpected, 'guest asks window starts at the newest answer');

$index = (string) file_get_contents($root . '/vaak/index.php');
$route = (string) file_get_contents($root . '/api/ap-vaak-profile-route.php');
$headerRs = (string) file_get_contents($root . '/rust/vaak-worker/src/profile_header.rs');
$check(str_contains($index, "\$GLOBALS['vaak_guest_profile'] = true") && str_contains($index, 'remote_profile'), 'logged-out scroll fetches the guest profile');
$check(str_contains($route, "\$GLOBALS['vaak_guest_profile'] = true"), 'logged-out profile route paints the guest profile');
$check(str_contains($admin, 'ap_local_profile_tab_page('), 'guest and signed-in tabs share the paged profile query');
$check(str_contains($headerRs, 'fn resolve_guest') && str_contains($headerRs, 'resolve_local('), 'Rust guest header uses the signed-in public counts');

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo 'ok ' . $checks . "\n";
