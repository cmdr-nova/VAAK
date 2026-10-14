<?php
declare(strict_types=1);
/** Cursor and button contract for the timeline newer poll. No database. */
require __DIR__ . '/migration-reference-functions.php';
reference_load(dirname(__DIR__) . '/ap-admin.php', [
    'admin_tl_newer_cursor',
    'admin_tl_status_created_ts',
    'admin_tl_poll_since_cursor',
    'admin_timeline_new_posts_button',
]);

$checks = 0;
function newer_check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
}

newer_check(admin_tl_newer_cursor(100, []) === 100, 'empty slice keeps the previous cursor');
newer_check(admin_tl_newer_cursor(100, [90, 0, -4]) === 100, 'older or empty timestamps do not move the cursor');
newer_check(admin_tl_newer_cursor(100, [110, 140, 120]) === 140, 'cursor is the newest returned card only');
newer_check(
    admin_tl_status_created_ts(['created_at' => '2026-10-05T01:57:08.000Z']) === 1791165428,
    'PHP parses the same RFC3339 timestamp Rust uses for the newer cursor'
);
newer_check(admin_tl_status_created_ts(['created_at' => '1791165428']) === 1791165428, 'numeric created_at passes through');
newer_check(admin_tl_status_created_ts(['created_at' => '']) === 0, 'blank created_at does not invent a cursor');

$before = time();
newer_check(admin_tl_poll_since_cursor(500, 9000, false) === 500, 'a PHP page starts the poll at the first row');
$plainNow = admin_tl_poll_since_cursor(0, 9000, false);
newer_check($plainNow >= $before && $plainNow <= time(), 'a PHP page with no row starts at now');
newer_check(admin_tl_poll_since_cursor(500, 9000, true) === 8700, 'Axum HTML keeps the same 300s overlap Home uses');
$axumNow = admin_tl_poll_since_cursor(0, 0, true);
newer_check($axumNow >= $before - 300 && $axumNow <= time() - 300, 'Axum HTML with no newest still overlaps now by 300s');

$button = admin_timeline_new_posts_button();
newer_check(
    $button === '<button type="button" class="feed-new-btn" id="feed-new-btn" hidden>New posts</button>',
    'the poller and the soft-nav shell share one new-posts button'
);

$admin = (string) file_get_contents(dirname(__DIR__) . '/ap-admin.php');
newer_check(substr_count($admin, 'admin_tl_poll_since_cursor(') >= 2, 'Local and Federated both start from the shared poll cursor');
newer_check(str_contains($admin, 'echo admin_timeline_new_posts_button();'), 'the soft-nav shell includes the new-posts button');
newer_check(
    substr_count($admin, "header('X-Newest: ' . admin_tl_newer_cursor(") >= 4,
    'Home, Local, Federated, and the PHP fallback advertise the same newer cursor'
);

$timeline = (string) file_get_contents(dirname(__DIR__, 2) . '/rust/vaak-worker/src/timeline.rs');
newer_check(
    str_contains($timeline, 'let newest = items.iter().map(status_created_ts).max().unwrap_or(since_ts);')
        && str_contains($timeline, 'let newest = kept.iter().map(status_created_ts).max().unwrap_or(since_ts);'),
    'Rust ranked and chronological newer slices set newest from the returned cards only'
);
$homeHtml = (string) file_get_contents(dirname(__DIR__, 2) . '/rust/vaak-worker/src/home_html.rs');
newer_check(substr_count($homeHtml, 'stamp_poll_card(paint_lean_feed_card_opts') === 2, 'Home and Local/Federated since HTML stamp the poll card');

echo "timeline-newer-counter: PASS ($checks checks)\n";
