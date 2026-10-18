<?php
declare(strict_types=1);
/** Old Bluesky like/repost notifications get their subject post backfilled. */
require __DIR__ . '/migration-reference-functions.php';
reference_load(dirname(__DIR__) . '/ap-bsky.php', ['ap_bsky_notification_subjects_backfill']);
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE mentions (id INTEGER PRIMARY KEY, owner_user_id INTEGER, created_at TEXT, object_id TEXT,
    activity_type TEXT, content TEXT, deleted_at TEXT)');
$db->exec('CREATE TABLE bsky_posts (bsky_uri TEXT PRIMARY KEY, text TEXT)');
$did = 'did:plc:me';
$recent = gmdate('Y-m-d\TH:i:s.000\Z', time() - 86400);
$old = gmdate('Y-m-d\TH:i:s.000\Z', time() - 30 * 86400);
$ins = $db->prepare('INSERT INTO mentions VALUES (?, ?, ?, ?, ?, ?, NULL)');
$ins->execute([1, 7, $recent, "https://bsky.app/profile/{$did}/post/aaa#like-0123456789ab", 'Like', 'liked your Bluesky post']);
$ins->execute([2, 7, $recent, "https://bsky.app/profile/{$did}/post/bbb#reblog-0123456789ab", 'Announce', 'already has text']);
$ins->execute([3, 7, $recent, "https://bsky.app/profile/{$did}/post/gone#like-0123456789ab", 'Like', 'liked your Bluesky post']);
$ins->execute([4, 7, $recent, 'https://mkultra.monster/users/me/notes/1#like-0123456789ab', 'Like', 'twin row']);
$ins->execute([5, 7, $old, "https://bsky.app/profile/{$did}/post/ccc#like-0123456789ab", 'Like', 'liked your Bluesky post']);
$ins->execute([6, 8, $recent, "https://bsky.app/profile/{$did}/post/ddd#like-0123456789ab", 'Like', 'liked your Bluesky post']);
$db->exec("INSERT INTO bsky_posts VALUES ('at://{$did}/app.bsky.feed.post/bbb', 'boosted text')");
function ap_db(): PDO { return $GLOBALS['db']; }
function ap_bsky_posts_migrate(): void {}
function ap_bsky_at_uri_from_any_url(string $url): ?string {
    return preg_match('~^https://bsky\.app/profile/(did:[^/]+)/post/([a-z]+)$~', $url, $m)
        ? "at://{$m[1]}/app.bsky.feed.post/{$m[2]}" : null;
}
$GLOBALS['warm_calls'] = [];
function ap_bsky_notification_subjects_warm(int $owner, array $notifs): int {
    $GLOBALS['warm_calls'][] = $notifs;
    $n = 0;
    foreach ($notifs as $notif) {
        if (str_ends_with($notif['reasonSubject'], '/aaa')) {
            $GLOBALS['db']->prepare('INSERT INTO bsky_posts VALUES (?, ?)')->execute([$notif['reasonSubject'], 'my appview post']);
            $n++;
        }
    }
    return $n;
}
function ap_bsky_subject_post_preview_text(string $at, int $owner): string {
    $st = $GLOBALS['db']->prepare('SELECT text FROM bsky_posts WHERE bsky_uri = ?');
    $st->execute([$at]);
    return (string) ($st->fetchColumn() ?: '');
}
$GLOBALS['invalidated'] = [];
function ap_notification_projection_invalidate_owner(int $owner): void { $GLOBALS['invalidated'][] = $owner; }
$checks = 0;
function bf_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
$content = static fn(int $id): string => (string) $GLOBALS['db']->query("SELECT content FROM mentions WHERE id = {$id}")->fetchColumn();
try {
    $dry = ap_bsky_notification_subjects_backfill(7, 7, 200, true);
    bf_check($dry['rows'] === 3 && $dry['subjects'] === 3, 'dry run sees only recent bsky.app like/repost rows for the owner');
    bf_check($dry['cached_before'] === 1, 'dry run counts subjects already cached');
    bf_check($GLOBALS['warm_calls'] === [] && $GLOBALS['invalidated'] === [], 'dry run fetches and invalidates nothing');
    bf_check($content(1) === 'liked your Bluesky post', 'dry run writes nothing');

    $r = ap_bsky_notification_subjects_backfill(7, 7, 200, false);
    $reasons = array_column($GLOBALS['warm_calls'][0] ?? [], 'reason', 'reasonSubject');
    bf_check(($reasons["at://{$did}/app.bsky.feed.post/bbb"] ?? '') === 'repost', 'Announce row warms as repost');
    bf_check(($reasons["at://{$did}/app.bsky.feed.post/aaa"] ?? '') === 'like', 'Like row warms as like');
    bf_check($r['fetched'] === 1, 'missing subject fetched');
    bf_check($content(1) === 'my appview post', 'placeholder text replaced with the post');
    bf_check($content(2) === 'already has text', 'real text left alone');
    bf_check($r['text_updated'] === 1, 'one row updated');
    bf_check($r['still_missing'] === [3], 'deleted subject reported as still missing');
    bf_check($content(5) === 'liked your Bluesky post' && $content(6) === 'liked your Bluesky post', 'old rows and other owners untouched');
    bf_check($GLOBALS['invalidated'] === [7], 'owner notification cache cleared');
    bf_check(ap_bsky_notification_subjects_backfill(0, 7, 200, false)['rows'] === 0, 'bad owner is a no-op');
} catch (Throwable $e) {
    fwrite(STDERR, 'bsky-notif-subject-backfill-smoke: FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "bsky-notif-subject-backfill-smoke: OK ({$checks} checks)\n";
