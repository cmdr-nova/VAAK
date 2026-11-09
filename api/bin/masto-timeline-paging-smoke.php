<?php
declare(strict_types=1);
/**
 * Mastodon API timelines page all the way down.
 *
 * Ice Cubes stops scrolling when a page holds fewer than 40 statuses and
 * pages with the last status id as max_id. Each timeline here is walked that
 * way over a fixture where filters thin the newest rows: every page but the
 * last must be full, no status may repeat, and every visible row must appear.
 */
require __DIR__ . '/migration-reference-functions.php';
$api = dirname(__DIR__);
reference_load($api . '/ap-masto-entities.php', [
    'ap_masto_timeline_events',
    'ap_timeline_author_diversity',
    'ap_masto_timeline_hashtag',
    'ap_masto_status_has_tag',
    'ap_masto_normalize_tag_name',
]);
reference_load($api . '/ap-lists.php', ['ap_masto_timeline_list', 'ap_masto_timeline_list_fill']);

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE events (id INTEGER PRIMARY KEY, created_at TEXT NOT NULL, type TEXT, actor_id TEXT,
    object_id TEXT, summary TEXT, media_urls TEXT, spoiler_text TEXT, sensitive INTEGER, action_taken TEXT,
    visibility TEXT, in_reply_to TEXT, host TEXT)');
$db->exec('CREATE TABLE masto_statuses (local_id INTEGER PRIMARY KEY, published TEXT, content_text TEXT, spoiler_text TEXT)');
$db->exec('CREATE TABLE mentions (id INTEGER PRIMARY KEY, created_at TEXT, content TEXT, deleted_at TEXT)');

function ap_db(): PDO { return $GLOBALS['db']; }
function ap_db_masto_owner_user_id(): int { return 1; }
function ap_masto_session_actor_id(): string { return 'https://mkultra.monster/users/owner'; }
function ap_following_list(?string $actor = null): array
{
    return [['actor_id' => 'https://a.example/users/a', 'host' => 'a.example'],
        ['actor_id' => 'https://b.example/users/blocked', 'host' => 'b.example']];
}
function ap_timeline_row_is_hidden(array $row, int $owner): bool
{
    return str_contains((string) ($row['actor_id'] ?? ''), 'blocked');
}
function ap_masto_peertube_prefer_create_event(array $row): array { return $row; }
function ap_events_collapse_self_announces(array $rows): array { return $rows; }
function ap_timeline_perf_log(string $label, float $started, array $ctx): void { $GLOBALS['perf'][$label] = $ctx; }
function ap_feature_enabled(string $name, bool $default): bool { return false; }
function ap_fix_utf8(string $s): string { return $s; }

// Status ids encode their source table so a max_id resolves back to a time.
function ap_masto_status_from_event(array $row): array
{
    return ['id' => (string) (3000000 + (int) $row['id']), 'created_at' => (string) $row['created_at'],
        'uri' => (string) $row['object_id'], 'content' => (string) $row['summary'],
        'account' => ['id' => (string) $row['actor_id']]];
}
function ap_masto_status_from_row(array $row): array
{
    return ['id' => (string) (1000 + (int) $row['local_id']), 'created_at' => (string) $row['published'],
        'uri' => 'https://mkultra.monster/notes/' . $row['local_id'], 'content' => (string) $row['content_text'],
        'spoiler_text' => (string) ($row['spoiler_text'] ?? ''), 'account' => ['id' => 'owner']];
}
function ap_masto_status_from_mention(array $row): array
{
    return ['id' => (string) (2000000 + (int) $row['id']), 'created_at' => (string) $row['created_at'],
        'uri' => 'https://m.example/notes/' . $row['id'], 'content' => (string) $row['content'],
        'account' => ['id' => 'm']];
}
function ap_masto_event_id_from_status_id(int $id): ?int { return $id >= 3000000 ? $id - 3000000 : null; }
function ap_masto_status_created_at_by_id(int $id): ?string
{
    [$sql, $key] = $id >= 3000000 ? ['SELECT created_at FROM events WHERE id = ?', $id - 3000000]
        : ($id >= 2000000 ? ['SELECT created_at FROM mentions WHERE id = ?', $id - 2000000]
        : ['SELECT published FROM masto_statuses WHERE local_id = ?', $id - 1000]);
    $st = ap_db()->prepare($sql);
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}
function ap_list_by_id(int $id): ?array { return ['id' => $id, 'replies_policy' => 'list']; }
function ap_list_actor_ids(int $id): array { return ['https://a.example/users/a']; }
function ap_list_normalize_replies_policy(string $p): ?string { return $p; }
function ap_list_parent_actor_for_object(string $o): ?string { return null; }

$checks = 0;
function paging_check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
}

$at = static fn(int $minutesAgo): string => gmdate('c', 1_800_000_000 - $minutesAgo * 60);
$ins = $db->prepare('INSERT INTO events (id, created_at, type, actor_id, object_id, summary, action_taken, visibility, in_reply_to)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$visibleHome = 0;
$visibleFed = 0;
// 260 rows, newest first. The newest 120 are mostly from a blocked actor or
// Bluesky observations, so the first SQL windows are thin.
for ($i = 1; $i <= 260; $i++) {
    $thin = $i <= 120 && $i % 4 !== 0;
    $blocked = $thin && $i % 2 === 0;
    $bsky = $thin && !$blocked;
    $actor = $blocked ? 'https://b.example/users/blocked' : 'https://a.example/users/a';
    $object = $bsky ? 'at://did:plc:x/app.bsky.feed.post/' . $i : 'https://a.example/notes/' . $i;
    $ins->execute([$i, $at($i), 'Create', $actor, $object, 'post ' . $i . ' #feet', 'log', 'public', null]);
    $visibleHome += $blocked ? 0 : 1;
    $visibleFed += ($blocked || $bsky) ? 0 : 1;
}

/** Walk a timeline the way Ice Cubes does and return the statuses seen. */
function paging_walk(callable $page, int $limit, string $label): array
{
    $seen = [];
    $maxId = null;
    for ($n = 0; $n < 50; $n++) {
        $rows = $page($limit, $maxId);
        foreach ($rows as $status) {
            paging_check(!isset($seen[$status['id']]), $label . ' repeated ' . $status['id']);
            $seen[$status['id']] = $status;
        }
        if (count($rows) < $limit) {
            return $seen;
        }
        $maxId = (string) $rows[count($rows) - 1]['id'];
    }
    throw new RuntimeException($label . ' never ended');
}

$home = paging_walk(static fn(int $l, ?string $m) => ap_masto_timeline_events('home', $l, $m, null, false, true), 40, 'home');
paging_check(count($home) === $visibleHome, 'mobile Home reached ' . count($home) . ' of ' . $visibleHome);
paging_check(($GLOBALS['perf']['events']['windows'] ?? 0) >= 1, 'perf log records windows');

$fed = paging_walk(static fn(int $l, ?string $m) => ap_masto_timeline_events('federated', $l, $m), 40, 'federated');
// The events layer hydrates limit+8, so walk with the caller's page size.
paging_check(count($fed) === $visibleFed, 'Federated reached ' . count($fed) . ' of ' . $visibleFed);

$first = ap_masto_timeline_events('federated', 40, null);
paging_check(count($first) === 40, 'thin first Federated window still fills a page (' . count($first) . ')');

$list = paging_walk(static fn(int $l, ?string $m) => ap_masto_timeline_list(7, $l, $m), 40, 'list');
paging_check(count($list) === $visibleHome, 'list reached ' . count($list) . ' of ' . $visibleHome);

// List cursor follows created_at, not row id: a backfilled old post with a
// high id must not cut the next page short.
$db->exec("INSERT INTO events (id, created_at, type, actor_id, object_id, summary, action_taken, visibility)
    VALUES (9000, '" . $at(400) . "', 'Create', 'https://a.example/users/a', 'https://a.example/notes/old', 'old', 'log', 'public')");
$list = paging_walk(static fn(int $l, ?string $m) => ap_masto_timeline_list(7, $l, $m), 40, 'list with backfill');
paging_check(count($list) === $visibleHome + 1, 'list with backfill reached ' . count($list));
$db->exec('DELETE FROM events WHERE id = 9000');

// Hashtag: three sources interleave in time and must merge newest-first.
$db->exec('DELETE FROM events WHERE id > 90');
$ls = $db->prepare('INSERT INTO masto_statuses (local_id, published, content_text) VALUES (?, ?, ?)');
$ms = $db->prepare('INSERT INTO mentions (id, created_at, content) VALUES (?, ?, ?)');
for ($i = 1; $i <= 70; $i++) {
    $ls->execute([$i, $at($i * 3 + 1), 'local #feet ' . $i]);
    $ms->execute([$i, $at($i * 3 + 2), 'mention #feet ' . $i]);
}
$tagVisible = 140 + (int) $db->query("SELECT COUNT(*) FROM events WHERE summary LIKE '%#feet%'")->fetchColumn();
$tag = paging_walk(static fn(int $l, ?string $m) => ap_masto_timeline_hashtag('feet', $l, $m), 40, 'tag');
paging_check(count($tag) === $tagVisible, 'tag reached ' . count($tag) . ' of ' . $tagVisible);
$times = array_map(static fn(array $s): string => $s['created_at'], array_values($tag));
$sorted = $times;
rsort($sorted);
paging_check($times === $sorted, 'tag timeline is newest-first across sources');
$newer = ap_masto_timeline_hashtag('feet', 40, null, (string) array_values($tag)[5]['id']);
paging_check(count($newer) === 5, 'tag since_id returns only newer posts (' . count($newer) . ')');

echo "masto-timeline-paging-smoke ok ({$checks} checks)\n";
