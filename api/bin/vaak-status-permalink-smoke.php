<?php
declare(strict_types=1);
/**
 * Local post permalinks are /vaak/@{key}/{id}. The ActivityPub id is rebuilt
 * from that path. Old ?view=status links are not redirected. No database.
 */
$root = dirname(__DIR__, 2);
$checks = 0;
function permalink_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

require $root . '/api/ap-vaak-status-route.php';

$note = 'https://mkultra.monster/users/shana_flux/notes/eb1e9cc40f1996e6';
permalink_check(
    ap_vaak_local_status_href($note, 'local') === '/vaak/@shana_flux/eb1e9cc40f1996e6?from=local',
    'example local note uses /vaak/@shana_flux/eb1e9cc40f1996e6'
);
permalink_check(
    ap_vaak_local_status_href('https://mkultra.monster/users/Shana_Flux/notes/eb1e9cc40f1996e6/', 'local')
        === '/vaak/@shana_flux/eb1e9cc40f1996e6?from=local',
    'actor key is lowercased and a trailing slash is ignored'
);
permalink_check(
    ap_vaak_local_status_href($note, 'LOCAL') === '/vaak/@shana_flux/eb1e9cc40f1996e6?from=home',
    'uppercase from is dropped the same way the status helper already does'
);
permalink_check(
    ap_vaak_local_status_href($note . '#announce-9', 'home') === '/vaak/@shana_flux/eb1e9cc40f1996e6?from=home',
    'a boost fragment is not part of the permalink'
);
permalink_check(
    ap_vaak_local_status_href('https://mkultra.monster/users/fixture/notes/dm-1', 'mentions')
        === '/vaak/@fixture/dm-1?from=mentions',
    'hyphenated note ids are kept'
);
permalink_check(ap_vaak_local_status_href('https://remote.example/users/alice/statuses/1', 'feed') === null, 'remote posts stay on the query form');
permalink_check(ap_vaak_local_status_href('https://bsky.app/profile/alice/post/abc', 'home') === null, 'Bluesky web URLs stay on the query form');
permalink_check(ap_vaak_local_status_href('at://did:plc:fixture/app.bsky.feed.post/abc', 'home') === null, 'at:// ids are not local notes');
permalink_check(ap_vaak_local_status_href('https://mkultra.monster/users/shana_flux', 'home') === null, 'a profile is not a post');
permalink_check(
    ap_vaak_status_copy_url('/vaak/@shana_flux/eb1e9cc40f1996e6?from=local')
        === 'https://mkultra.monster/vaak/@shana_flux/eb1e9cc40f1996e6',
    'copy link drops the in-app from hint'
);
permalink_check(
    ap_vaak_status_copy_url('?view=status&object=https%3A%2F%2Fremote.example%2Fs%2F1&from=feed')
        === 'https://mkultra.monster/vaak/?view=status&object=https%3A%2F%2Fremote.example%2Fs%2F1&from=feed',
    'remote copy links keep the query form'
);
permalink_check(
    ap_vaak_status_retry_href($note, 'local')
        === '/vaak/@shana_flux/eb1e9cc40f1996e6?from=local&refresh_thread=1',
    'retry stays on the short path'
);
permalink_check(
    ap_vaak_local_status_href('https://mkultra.monster/vaak/@Shana_Flux/eb1e9cc40f1996e6?from=home', 'search')
        === '/vaak/@shana_flux/eb1e9cc40f1996e6?from=search',
    'a pasted permalink folds back to the note id'
);

$savedGet = $_GET;
$savedServer = $_SERVER;
try {
    $_GET = ['object' => 'https://mkultra.monster/vaak/@shana_flux/eb1e9cc40f1996e6?from=local'];
    ap_vaak_normalize_permalink_object_query();
    permalink_check(($_GET['object'] ?? '') === $note, 'an object query that is a permalink becomes the note id');
    $_GET = ['object' => 'https://remote.example/users/a/statuses/1'];
    ap_vaak_normalize_permalink_object_query();
    permalink_check($_GET['object'] === 'https://remote.example/users/a/statuses/1', 'remote object queries stay put');

    $_GET = ['from' => 'local'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/vaak/@Shana_Flux/eb1e9cc40f1996e6/?from=local';
    $plan = ap_vaak_short_status_plan();
    permalink_check(
        is_array($plan) && $plan['action'] === 'status' && $plan['object'] === $note,
        'the path reconstructs the ActivityPub id with no lookup'
    );

    $_GET = [
        'vaak_status_key' => 'Shana_Flux',
        'vaak_status_id' => 'eb1e9cc40f1996e6',
        'from' => 'mentions',
    ];
    $_SERVER['REQUEST_URI'] = '/vaak/index.php?vaak_status_key=Shana_Flux&vaak_status_id=eb1e9cc40f1996e6&from=mentions';
    $plan = ap_vaak_short_status_plan();
    permalink_check(is_array($plan) && $plan['object'] === $note, 'a Caddy rewrite query reconstructs the same id');

    $_GET['object'] = 'https://remote.example/users/a/statuses/9';
    $_GET['view'] = 'status';
    $plan = ap_vaak_short_status_plan();
    permalink_check(
        is_array($plan) && $plan['action'] === 'status' && $plan['object'] === 'https://remote.example/users/a/statuses/9',
        'an explicit https object on the short path wins'
    );

    $_GET = ['vaak_status_key' => 'shana_flux', 'vaak_status_id' => 'eb1e9cc40f1996e6', 'view' => 'home'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $plan = ap_vaak_short_status_plan();
    permalink_check(
        is_array($plan) && $plan['action'] === 'redirect' && $plan['location'] === '/vaak/?view=home',
        'a non-status view on the short path leaves for /vaak/'
    );

    $_SERVER['REQUEST_METHOD'] = 'POST';
    permalink_check(ap_vaak_short_status_plan() === null, 'POST is not rewritten into a status view');

    $_GET = ['view' => 'status', 'object' => $note, 'from' => 'local'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/vaak/?view=status&object=' . rawurlencode($note) . '&from=local';
    permalink_check(ap_vaak_short_status_plan() === null, 'old ?view=status links are not redirected');
} finally {
    $_GET = $savedGet;
    $_SERVER = $savedServer;
}

$index = (string) file_get_contents($root . '/vaak/index.php');
$applyAt = strpos($index, 'ap_vaak_apply_short_status_request()');
$guestAt = strpos($index, "=== 'status'\n        && trim((string) (\$_GET['object'] ?? '')) !== ''");
permalink_check($applyAt !== false && $guestAt !== false && $applyAt < $guestAt, 'short links are resolved before the guest status gate');
$admin = (string) file_get_contents($root . '/api/ap-admin.php');
permalink_check(str_contains($admin, 'ap_vaak_local_status_href'), 'status links are built by the short-permalink helper');
permalink_check(str_contains($admin, 'window.vaakStatusHref = function'), 'the SPA builds the short form');
$copiedAt = strpos($admin, 'A copied local permalink is not an ActivityPub id.');
$fetchAt = strpos($admin, 'ap_masto_resolve_pasted_status_url');
permalink_check($copiedAt !== false && $fetchAt !== false && $copiedAt < $fetchAt, 'search does not fetch a pasted short permalink');
permalink_check(str_contains($admin, 'window.vaakShortStatusTarget(u)) return;'), 'timeline Back does not treat a short permalink as Home');
foreach (['ap-user.php', 'ap-cmdr-nova.php'] as $handler) {
    $source = (string) file_get_contents($root . '/api/' . $handler);
    permalink_check(!str_contains($source, 'ap_vaak_apply_short_status_request'), $handler . ' does not rewrite ActivityPub fetches');
    permalink_check(str_contains($source, '/notes/'), $handler . ' still serves note documents');
}
$deploy = (string) file_get_contents($root . '/docs/DEPLOY.md');
permalink_check(str_contains($deploy, '@vaak_local_status path_regexp vaakstatus'), 'DEPLOY.md documents the Caddy rewrite');
permalink_check(str_contains($deploy, 'no nginx config'), 'DEPLOY.md says this repo has no nginx rule to edit');
$readme = (string) file_get_contents($root . '/README.md');
permalink_check(str_contains($readme, '/vaak/@{name}/{id}'), 'README names the permalink');

echo "vaak-status-permalink-smoke: {$checks} checks passed\n";
