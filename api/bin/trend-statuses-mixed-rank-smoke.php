<?php
/** Trending posts: fediverse and Bluesky share one popularity ranking, no fixed quota. */
declare(strict_types=1);

require __DIR__ . '/migration-reference-functions.php';

$checks = 0;
function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
}

$entitySource = (string) file_get_contents(dirname(__DIR__) . '/ap-masto-entities.php');
foreach (['AP_MASTO_TREND_STATUS_WINDOW_HOURS', 'AP_MASTO_TREND_STATUS_GRAVITY'] as $const) {
    if (!preg_match('/const ' . $const . ' = ([0-9.]+);/', $entitySource, $m)) {
        throw new RuntimeException("missing {$const}");
    }
    eval('const ' . $const . ' = ' . $m[1] . ';');
}
reference_load(dirname(__DIR__) . '/ap-masto-entities.php', [
    'ap_masto_trend_status_score', 'ap_masto_trend_statuses_rank_mixed', 'ap_masto_trend_bsky_rows_by_score',
]);
check(AP_MASTO_TREND_STATUS_WINDOW_HOURS === 48, 'trending posts use a 48 hour window');

$now = 1_800_000_000;
$post = static fn(string $id, string $source, int $likes, int $boosts, int $hoursOld, int $replies = 0): array => [
    'id' => $id, 'source' => $source, 'favourites_count' => $likes, 'reblogs_count' => $boosts,
    'replies_count' => $replies, 'created_at' => gmdate('c', $now - $hoursOld * 3600),
];
$ids = static fn(array $items): array => array_column($items, 'id');

$ranked = ap_masto_trend_statuses_rank_mixed([
    $post('f1', 'fediverse', 5, 1, 2),
    $post('f2', 'fediverse', 3, 0, 3),
    $post('f3', 'fediverse', 40, 10, 4),
    $post('b1', 'bluesky', 300, 50, 5),
    $post('b2', 'bluesky', 2, 0, 6),
], $now);
check($ids($ranked) === ['b1', 'f3', 'f1', 'f2', 'b2'], 'most popular wins regardless of network');

$all = [];
for ($i = 0; $i < 6; $i++) $all[] = $post('b' . $i, 'bluesky', 100 + $i, 5, 3);
$all[] = $post('f0', 'fediverse', 4, 0, 3);
check(array_slice($ids(ap_masto_trend_statuses_rank_mixed($all, $now)), 0, 5) === ['b5', 'b4', 'b3', 'b2', 'b1'], 'no forced fediverse slot');

$aged = ap_masto_trend_statuses_rank_mixed([$post('old', 'bluesky', 60, 0, 40), $post('new', 'fediverse', 20, 0, 1)], $now);
check($ids($aged) === ['new', 'old'], 'a 40-hour-old post loses to a fresh one with a third of the likes');

$stale = ap_masto_trend_statuses_rank_mixed([$post('viral', 'bluesky', 9000, 2000, 72), $post('fresh', 'fediverse', 3, 0, 2)], $now);
check($ids($stale) === ['fresh'], 'a 3-day-old viral post is out of the window entirely');

$rising = ap_masto_trend_statuses_rank_mixed([$post('yday', 'bluesky', 3000, 500, 26), $post('now', 'bluesky', 250, 40, 1)], $now);
check($ids($rising) === ['now', 'yday'], 'a post taking off right now beats yesterday\'s bigger total');

$bskyRow = static fn(string $uri, int $likes, int $reposts, int $hoursOld): array => [
    'bsky_uri' => $uri, 'like_count' => $likes, 'repost_count' => $reposts, 'reply_count' => 0, 'quote_count' => 0,
    'published_at' => gmdate('Y-m-d\\TH:i:s.000\\Z', $now - $hoursOld * 3600),
];
$rows = ap_masto_trend_bsky_rows_by_score([
    $bskyRow('big-old', 5000, 900, 40), $bskyRow('too-old', 9000, 900, 60), $bskyRow('hot', 400, 80, 1),
], $now);
check(array_column($rows, 'bsky_uri') === ['hot', 'big-old'], 'Bluesky candidates ordered by decayed score inside the window');

$tie = ap_masto_trend_statuses_rank_mixed([$post('a', 'fediverse', 1, 0, 1), $post('b', 'bluesky', 1, 0, 1)], $now);
check($ids($tie) === ['a', 'b'], 'ties keep input order');
check(ap_masto_trend_statuses_rank_mixed([], $now) === [], 'empty stays empty');

echo "trend-statuses-mixed-rank-smoke: {$checks} checks ok\n";
