<?php
declare(strict_types=1);
/**
 * Blog composer v2 service (api/ap-blog.php) against SQLite in memory, with
 * the outbox calls recorded instead of federated. Covers the original form
 * contract Shana's daily post uses, v2 options, autosave, scheduling, edit,
 * delete and the token API. No network.
 */
$api = dirname(__DIR__);
require __DIR__ . '/migration-reference-functions.php';

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo']->exec("CREATE TABLE vaak_blog_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER NOT NULL, actor_key TEXT NOT NULL, slug TEXT NOT NULL,
    title TEXT NOT NULL, category TEXT NOT NULL DEFAULT '', tags_json TEXT NOT NULL DEFAULT '[]', body_markdown TEXT NOT NULL DEFAULT '',
    excerpt TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'published', note_id TEXT, canonical_url TEXT NOT NULL DEFAULT '',
    published_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(owner_user_id, slug))");
$GLOBALS['pdo']->exec("CREATE TABLE masto_media (local_id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL, s3_key TEXT NOT NULL, public_url TEXT NOT NULL, preview_url TEXT, media_type TEXT NOT NULL, mime TEXT NOT NULL,
    file_size INTEGER NOT NULL DEFAULT 0, width INTEGER, height INTEGER, blurhash TEXT, description TEXT, status_local_id INTEGER)");
$GLOBALS['pdo']->exec("CREATE TABLE outbox_notes (id TEXT, create_id TEXT, kind TEXT)");
$GLOBALS['pdo']->exec("CREATE TABLE masto_statuses (local_id INTEGER PRIMARY KEY, note_id TEXT)");

function ap_db(): PDO { return $GLOBALS['pdo']; }
function ap_db_now(): string { return gmdate('c', $GLOBALS['clock'] ?? time()); }
function ap_db_last_insert_id(string $t, string $c): int { return (int) ap_db()->lastInsertId(); }
reference_load($api . '/ap-db.php', [
    'ap_blog_v2_columns', 'ap_blog_slug', 'ap_blog_tags_decode', 'ap_blog_post_get', 'ap_blog_create', 'ap_blog_set_note',
    'ap_blog_update_fields', 'ap_blog_claim_status', 'ap_blog_slug_taken', 'ap_blog_delete_row', 'ap_blog_scheduled_rows', 'ap_blog_posts_list',
]);
foreach (ap_blog_v2_columns() as $col => [$ddl]) {
    ap_db()->exec('ALTER TABLE vaak_blog_posts ADD COLUMN ' . $col . ' ' . str_replace('BIGINT', 'INTEGER', $ddl));
}

// Recorded outbox.
$GLOBALS['calls'] = [];
$GLOBALS['next_local'] = 100;
function ap_publish_status_text(string $content, ?string $inReplyTo = null, ?string $toActor = null, array $media = [], string $spoiler = '', ?bool $sensitive = null, ?string $quote = null, ?array $poll = null, string $visibility = 'public'): array
{
    $id = ++$GLOBALS['next_local'];
    $note = 'https://mkultra.monster/users/' . ($GLOBALS['req_actor'] ?? 'nobody') . '/notes/' . $id;
    ap_db()->prepare('INSERT INTO masto_statuses (local_id, note_id) VALUES (?, ?)')->execute([$id, $note]);
    ap_db()->prepare('INSERT INTO outbox_notes (id, create_id, kind) VALUES (?, ?, ?)')->execute([$note, $note, 'compose']);
    $GLOBALS['calls'][] = ['publish', compact('content', 'media', 'spoiler', 'sensitive', 'visibility') + ['skip_bsky' => !empty($GLOBALS['ap_publish_skip_bsky']), 'actor' => $GLOBALS['req_actor'] ?? null]];
    return $GLOBALS['fail_publish'] ?? false ? ['ok' => false, 'error' => 'boom'] : ['ok' => true, 'note_id' => $note, 'local_id' => $id];
}
function ap_update_local_status(int $localId, string $content, string $spoiler = '', ?bool $sensitive = null, ?array $media = null): array
{
    $GLOBALS['calls'][] = ['update', compact('localId', 'content', 'spoiler', 'media')];
    return ['ok' => true];
}
function ap_delete_local_status(int $localId): array
{
    $GLOBALS['calls'][] = ['delete', $localId];
    return ['ok' => true];
}
function ap_masto_status_by_note_id(string $noteId): ?array
{
    $st = ap_db()->prepare('SELECT * FROM masto_statuses WHERE note_id = ?');
    $st->execute([$noteId]);
    return $st->fetch() ?: null;
}
function ap_request_actor_get(): ?array { return isset($GLOBALS['req_actor']) ? ['key' => $GLOBALS['req_actor']] : null; }
function ap_request_actor_set(?string $key): void { $GLOBALS['req_actor'] = $key; }
putenv('VAAK_BLOG_NATIVE');
require $api . '/ap-blog.php';

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
};
$last = static fn(): array => end($GLOBALS['calls']) ?: [];

// 1. The original form contract (Shana's job): publish with a public teaser, title as CW.
$GLOBALS['req_actor'] = 'shana_flux';
$res = ap_blog_save(17, 'shana_flux', ap_blog_input([
    'action' => 'blog_save', 'blog_title' => 'Daily thoughts', 'blog_category' => 'Daily', 'blog_body' => "# Today\n\nIt was **good**.",
    'blog_tags' => '#daily ai', 'blog_publish' => '1',
]));
$check(!empty($res['ok']) && $res['status'] === 'published' && $res['notice'] === 'Blog post published.', 'v1 form publishes');
[$kind, $p] = $last();
$check($kind === 'publish' && $p['spoiler'] === 'Daily thoughts' && $p['sensitive'] === true && $p['visibility'] === 'public' && !$p['skip_bsky'], 'v1 teaser keeps CW title, public, Bluesky mirror');
$check(str_starts_with($p['content'], 'Today It was good.') && str_contains($p['content'], "#daily #ai\n\nRead the full post: https://mkultra.monster/users/shana_flux?tab=blog&post=daily-thoughts-"), 'v1 teaser text: excerpt, tags, link');
$row = ap_blog_post_get('shana_flux', (string) $res['slug'], false);
$check(($row['status'] ?? '') === 'published' && ($row['note_id'] ?? '') !== '' && ($row['excerpt'] ?? '') === 'Today It was good.', 'v1 row stored as published with its note');
$check((string) ap_db()->query("SELECT kind FROM outbox_notes WHERE id = " . ap_db()->quote((string) $row['note_id']))->fetchColumn() === 'blog', 'teaser outbox row marked blog');

// 2. v2 draft with options, then publish.
$GLOBALS['req_actor'] = 'cmdr_nova';
$v2 = ['blog_v2' => '1', 'blog_title' => 'Why I built VAAK', 'blog_body' => "Body here.\n\n- one", 'blog_subtitle' => 'A note', 'blog_summary' => 'My own summary.',
    'blog_url_slug' => 'Why VAAK', 'blog_teaser_visibility' => 'private', 'blog_teaser_cw' => '0', 'blog_teaser_bsky' => '0', 'blog_publish' => '0', 'blog_tags' => ''];
$res = ap_blog_save(1, 'cmdr_nova', ap_blog_input($v2));
$check(!empty($res['ok']) && $res['slug'] === 'why-vaak' && $res['status'] === 'draft', 'v2 draft with custom URL');
$n = count($GLOBALS['calls']);
$res = ap_blog_save(1, 'cmdr_nova', ap_blog_input(['blog_slug' => 'why-vaak', 'blog_publish' => '1'] + $v2));
[$kind, $p] = $last();
$check(!empty($res['ok']) && count($GLOBALS['calls']) === $n + 1 && $p['visibility'] === 'private' && $p['spoiler'] === '' && $p['skip_bsky'], 'v2 publish uses teaser options');
$check(str_starts_with($p['content'], "Why I built VAAK\n\nMy own summary."), 'without CW the teaser opens with the title and uses the summary');
$check(empty($GLOBALS['ap_publish_skip_bsky']), 'Bluesky opt-out flag is reset after publishing');

// 3. Autosave never touches a published post; a taken URL is refused.
$res = ap_blog_save(1, 'cmdr_nova', ap_blog_input(['blog_slug' => 'why-vaak'] + $v2), null, true);
$check(empty($res['ok']), 'autosave refuses published posts');
$res = ap_blog_save(1, 'cmdr_nova', ap_blog_input(['blog_url_slug' => 'why-vaak', 'blog_title' => 'Other'] + $v2));
$check(empty($res['ok']) && str_contains((string) $res['error'], 'already have a post at that URL'), 'taken URL refused');
$auto = ap_blog_save(1, 'cmdr_nova', ap_blog_input(['blog_url_slug' => '', 'blog_title' => 'Autosaved'] + $v2), null, true);
$check(!empty($auto['ok']) && $auto['status'] === 'draft', 'autosave creates a draft');
$again = ap_blog_save(1, 'cmdr_nova', ap_blog_input(['blog_slug' => (string) $auto['slug'], 'blog_url_slug' => '', 'blog_title' => 'Autosaved 2'] + $v2), null, true);
$check(!empty($again['ok']) && $again['slug'] === $auto['slug'], 'second autosave updates the same draft');

// 4. Edit a published post: row updated, teaser Update federated, URL kept.
$n = count($GLOBALS['calls']);
$res = ap_blog_save(1, 'cmdr_nova', ap_blog_input(['blog_slug' => 'why-vaak', 'blog_url_slug' => 'renamed', 'blog_title' => 'Why I built VAAK (updated)', 'blog_teaser_visibility' => 'public', 'blog_publish' => '1'] + $v2));
[$kind, $p] = $last();
$row = ap_blog_post_get('cmdr_nova', 'why-vaak', false);
$check(!empty($res['ok']) && $kind === 'update' && count($GLOBALS['calls']) === $n + 1 && str_starts_with($p['content'], 'Why I built VAAK (updated)'), 'published edit sends an Update');
$check(($row['title'] ?? '') === 'Why I built VAAK (updated)' && ($row['teaser_visibility'] ?? '') === 'private', 'published edit keeps URL and teaser options');

// 5. Scheduling.
$GLOBALS['clock'] = time();
$past = ap_blog_save(1, 'cmdr_nova', ap_blog_input(['blog_title' => 'Later', 'blog_url_slug' => 'later', 'blog_when' => 'schedule', 'blog_schedule_at' => '2001-01-01T10:00', 'blog_publish' => '1'] + $v2));
$check(empty($past['ok']) && str_contains((string) $past['error'], 'at least a minute'), 'past schedule refused');
$when = gmdate('Y-m-d\TH:i', time() + 7200);
$res = ap_blog_save(1, 'cmdr_nova', ap_blog_input(['blog_title' => 'Later', 'blog_url_slug' => 'later', 'blog_when' => 'schedule', 'blog_schedule_at' => $when, 'blog_tz_offset' => '60', 'blog_publish' => '1'] + $v2));
$row = ap_blog_post_get('cmdr_nova', 'later', false);
$check(!empty($res['ok']) && ($row['status'] ?? '') === 'scheduled', 'schedule stores a scheduled row');
$check(strtotime((string) $row['scheduled_at']) === strtotime($when . ':00Z') + 3600, 'browser offset converts wall time to UTC');
$GLOBALS['req_actor'] = 'someone_else';
$n = count($GLOBALS['calls']);
$stats = ap_blog_publish_due(5, time() + 60);
$check($stats['due'] === 0 && count($GLOBALS['calls']) === $n, 'not due yet');
$stats = ap_blog_publish_due(5, time() + 4 * 3600);
[$kind, $p] = $last();
$row = ap_blog_post_get('cmdr_nova', 'later', false);
$check($stats['published'] === 1 && $kind === 'publish' && $p['actor'] === 'cmdr_nova' && ($row['status'] ?? '') === 'published', 'due post publishes as its owner');
$check(($GLOBALS['req_actor'] ?? null) === 'someone_else', 'request actor restored after scheduled publish');
$GLOBALS['fail_publish'] = true;
ap_blog_save(1, 'cmdr_nova', ap_blog_input(['blog_title' => 'Fails', 'blog_url_slug' => 'fails', 'blog_when' => 'schedule', 'blog_schedule_at' => $when, 'blog_publish' => '1'] + $v2));
ap_blog_publish_due(5, time() + 4 * 3600);
$row = ap_blog_post_get('cmdr_nova', 'fails', false);
$check(($row['status'] ?? '') === 'draft' && str_contains((string) $row['last_error'], 'Scheduled publish failed: boom'), 'failed scheduled publish returns to drafts with the reason');
unset($GLOBALS['fail_publish']);

// 6. No teaser.
$n = count($GLOBALS['calls']);
$res = ap_blog_save(1, 'cmdr_nova', ap_blog_input(['blog_title' => 'Quiet', 'blog_url_slug' => 'quiet', 'blog_teaser_visibility' => 'none', 'blog_publish' => '1'] + $v2));
$check(!empty($res['ok']) && $res['status'] === 'published' && count($GLOBALS['calls']) === $n, 'no-teaser post publishes without a status');

// 7. Delete federates and removes; other owners cannot delete.
$check(empty(ap_blog_delete(2, 'cmdr_nova', 'why-vaak')['ok']), "another owner can't delete");
$res = ap_blog_delete(1, 'cmdr_nova', 'why-vaak');
[$kind] = $last();
$check(!empty($res['ok']) && $kind === 'delete' && ap_blog_post_get('cmdr_nova', 'why-vaak', false) === null, 'delete removes the post and its teaser');

// 8. Token API.
$GLOBALS['req_actor'] = 'shana_flux';
$api1 = ap_blog_api('POST', '', ['id' => 17, 'actor_key' => 'shana_flux'], ['title' => 'From the API', 'body' => 'Hello.', 'tags' => ['bot', 'daily'], 'teaser_bsky' => false]);
[$kind, $p] = $last();
$check($api1['status'] === 200 && $api1['body']['status'] === 'published' && str_contains($api1['body']['url'], 'shana_flux?tab=blog&post=from-the-api-'), 'API publishes by default');
$check($p['skip_bsky'] && str_contains($p['content'], '#bot #daily'), 'API options and tag arrays');
$list = ap_blog_api('GET', '', ['id' => 17, 'actor_key' => 'shana_flux'], []);
$check($list['status'] === 200 && count($list['body']) === 2, 'API lists own posts');
$bad = ap_blog_api('POST', '', ['id' => 17, 'actor_key' => 'shana_flux'], ['title' => '', 'body' => 'x']);
$check($bad['status'] === 422, 'API validation error');

// 9. Excerpt strips the new Markdown.
$check(ap_blog_excerpt("## Hi **there**\n\n> quoted `code`\n\n![img](https://x.test/a.png)\n\n1. [link](https://x.test) ~~old~~") === 'Hi there quoted code link old', 'excerpt strips Markdown');

if ($failures) {
    fwrite(STDERR, "blog-service-smoke: FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "blog-service-smoke: ok\n";
