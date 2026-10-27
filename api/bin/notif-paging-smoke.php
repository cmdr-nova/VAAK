<?php
declare(strict_types=1);
/**
 * Notification paging decisions shared by PHP and the Rust worker.
 * Older pages, Link headers, full pages after a hide, and disabled-owner
 * handling are pinned here without Postgres, Redis, or the network.
 */
$api = dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}

$fail = static function (string $msg): never {
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
};
$check = static function (bool $ok, string $label) use ($fail): void {
    if (!$ok) {
        $fail($label);
    }
};

reference_load($api . '/ap-masto-entities.php', [
    'ap_masto_parse_notification_id',
    'ap_masto_notifications_id_cmp',
    'ap_masto_notifications_fast_mention_bound',
    'ap_masto_notifications_page_decision',
    'ap_masto_notifications_finalize_capped',
    'ap_masto_notifications_cache_page_complete',
    'ap_masto_notifications_link',
    'ap_masto_notifications_rewrite_link_host',
]);

$rows = [];
for ($id = 80; $id >= 1; $id--) {
    $rows[] = ['id' => (string) $id, 'sort' => $id, 'hidden' => $id === 50];
}
$page = ap_masto_notifications_page_decision($rows, 40, null, null, null);
$check(count($page['ids']) === 40, 'a hidden row does not shorten the first page');
$check(!in_array('50', $page['ids'], true), 'the hidden id is not returned');
$check($page['has_more'] === true, 'more visible rows remain after a full page');
$check($page['next_cursor'] === '40', 'next cursor is the last kept id');
$rest = ap_masto_notifications_page_decision($rows, 40, '40', null, null);
$check($rest['ids'][0] === '39', 'the next page starts on the following visible id');
$union = array_merge($page['ids'], $rest['ids']);
$check(count($union) === count(array_unique($union)), 'pages do not repeat an id');
$check(!in_array('50', $union, true), 'the hidden id stays hidden on the next page');

$follow = ap_masto_notifications_fast_mention_bound(1792974076601145032);
$check($follow === null, 'a follow cursor is not a mention bound');
$check(ap_masto_notifications_fast_mention_bound(1757029703500001153) === 1153, 'punished_nova mention cursor');
$check(ap_masto_notifications_fast_mention_bound(1792936901500001890) === 1890, 'luna_naloc mention cursor');
$check(ap_masto_notifications_fast_mention_bound(1792971363500001916) === 1916, 'favourite cursor that starts page 2');

$link = ap_masto_notifications_link(
    [['id' => '200'], ['id' => '100']],
    40,
    true,
    '100',
    ['mention'],
    ['follow'],
    'https://vaak.monster/api/v1/notifications'
);
$check(str_contains($link, '<https://vaak.monster/api/v1/notifications?limit=40&max_id=100&types%5B%5D=mention&exclude_types%5B%5D=follow>; rel="next"'), 'next link keeps filters: ' . $link);
$check(str_contains($link, 'min_id=200'), 'prev uses the first id');
$check(str_contains($link, 'rel="prev"'), 'prev relation is present');
$check(!str_contains($link, 'since_id'), 'since_id is not copied into Link');
$end = ap_masto_notifications_link([['id' => '9']], 40, false, '9', [], [], 'https://vaak.monster/api/v1/notifications');
$check(!str_contains($end, 'rel="next"'), 'an exhausted page omits next');
$check(str_contains($end, 'rel="prev"'), 'an exhausted page still has prev');
$rewritten = ap_masto_notifications_rewrite_link_host($link);
$check(str_contains($rewritten, '/api/v1/notifications?limit=40&max_id=100'), 'host rewrite keeps the query');

$check(ap_masto_notifications_cache_page_complete([['id' => '1']], 40, null) === false, 'a short legacy page is rebuilt');
$check(ap_masto_notifications_cache_page_complete([], 40, null) === true, 'an empty page is complete');
$check(ap_masto_notifications_cache_page_complete([['id' => '1']], 40, false) === true, 'an explicit end is complete');
$check(ap_masto_notifications_cache_page_complete(array_fill(0, 40, ['id' => '1']), 40, null) === true, 'a full page is complete');

$capped = ap_masto_notifications_page_decision(
    [
        ['id' => '5', 'sort' => 5, 'hidden' => false],
        ['id' => '4', 'sort' => 4, 'hidden' => true],
        ['id' => '3', 'sort' => 3, 'hidden' => true],
        ['id' => '2', 'sort' => 2, 'hidden' => true],
        ['id' => '1', 'sort' => 1, 'hidden' => false],
    ],
    3,
    null,
    null,
    1
);
$capped = ap_masto_notifications_finalize_capped($capped, 3);
$check($capped['has_more'] === true && $capped['next_cursor'] === '2', 'a capped hidden run advances past the oldest examined id');

$entities = (string) file_get_contents($api . '/ap-masto-entities.php');
$route = (string) file_get_contents($api . '/ap-mastodon.php');
$check(str_contains($entities, 'function ap_masto_notifications_mark_cheap_hidden('), 'cheap hides run before the limit');
$check(str_contains($entities, 'function ap_masto_notifications_db_page('), 'older pages read through the keyset helper');
$fetchAt = strpos($entities, 'function ap_masto_notifications_fetch(');
$dbAt = strpos($entities, 'ap_masto_notifications_db_page(', $fetchAt === false ? 0 : $fetchAt);
$epochAt = strpos($entities, '$listEpoch = ', $fetchAt === false ? 0 : $fetchAt);
$check($fetchAt !== false && $epochAt !== false && $dbAt !== false && $epochAt < $dbAt, 'epoch is read before the database page');
$check(str_contains($route, "header('Link: ' . \$link);"), 'the notifications route sends Link');
$check(str_contains($route, 'ap_masto_notifications_rewrite_link_host'), 'PHP rewrites the host on Rust Link headers');
$check(str_contains($entities, 'ap_notif_has_more_known'), 'a full Rust page without Link still continues');

$worker = dirname(__DIR__, 2) . '/rust/vaak-worker/src';
$rustPage = (string) file_get_contents($worker . '/notif_page.rs');
$rustHttp = (string) file_get_contents($worker . '/http.rs');
$check(str_contains($rustPage, 'fn owner_gate'), 'disabled owners are a serve decision');
$check(str_contains($rustPage, 'pub async fn load_notification_page'), 'Rust pages from the database');
$check(str_contains($rustHttp, 'x-vaak-notif-has-more'), 'Rust tells PHP whether another page exists');
$check(str_contains($rustHttp, 'unknown owner'), 'an unknown owner is still an error');
$check(str_contains($rustPage, 'rel=\\"next\\"') || str_contains($rustPage, 'rel="next"'), 'Rust builds a Mastodon next link');

fwrite(STDOUT, "notif-paging-smoke: ok\n");
