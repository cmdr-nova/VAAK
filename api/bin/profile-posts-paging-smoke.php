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

reference_load($root . '/api/ap-db.php', [
    'ap_outbox_note_is_reply',
    'ap_outbox_note_on_public_profile',
    'ap_outbox_profile_reply_predicate',
    'ap_outbox_posts_list_page',
    'ap_outbox_replies_list_page',
    'ap_outbox_replies_count',
    'ap_outbox_count_for_actor',
    'ap_outbox_cached_count',
    'ap_outbox_drop_profile_note_caches',
    'ap_local_profile_tab_page',
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
$beforeRemote = count($GLOBALS['dropped']);
ap_outbox_drop_profile_note_caches(['id' => 'https://remote.test/notes/1']);
$check(count($GLOBALS['dropped']) === $beforeRemote, 'a remote note does not drop local profile caches');

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo 'ok ' . $checks . "\n";
