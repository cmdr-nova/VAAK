<?php
declare(strict_types=1);

/**
 * Phyrian family tree and recap.
 * SQLite stand-in for the Postgres tables the tree reads. Checks the tree
 * shape (loops, missing parents, grid-linked roots), the seven-day recap,
 * that the suggested post text names nobody, and that posting needs a
 * confirmed form.
 */

function ap_auth_csrf_ok(?string $token): bool
{
    return $token === 'csrf-ok';
}

function ap_phyrian_is_origin_owner(int $ownerUserId): bool
{
    return $ownerUserId === 1;
}

require_once dirname(__DIR__) . '/ap-phyrian-tree.php';

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$now = strtotime('2026-10-19 12:00:00 UTC');
$ago = static fn (int $days): string => gmdate('Y-m-d H:i:s', $now - $days * 86400) . '+00';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ap_users (id INTEGER PRIMARY KEY, username TEXT, actor_key TEXT)');
$db->exec('CREATE TABLE phyrian_players (owner_user_id INTEGER PRIMARY KEY, status TEXT, strain TEXT, generation INTEGER, imprinted_by_owner_id INTEGER, imprinted_at TEXT)');
$db->exec('CREATE TABLE phyrian_bridge_links (owner_user_id INTEGER PRIMARY KEY, avatar_uuid TEXT, status TEXT, unlinked_at TEXT)');
$db->exec('CREATE TABLE phyrian_requests (id INTEGER PRIMARY KEY, kind TEXT, status TEXT, resolved_at TEXT)');
$db->exec('CREATE TABLE phyrian_clans (id INTEGER PRIMARY KEY, created_at TEXT)');

$users = [1 => 'origin', 2 => 'alice', 3 => 'bob', 4 => 'cara', 5 => 'gridder', 6 => 'loopa', 7 => 'loopb', 8 => 'orphan', 9 => 'unmarked', 10 => '<script>'];
foreach ($users as $id => $name) {
    $db->prepare('INSERT INTO ap_users (id, username, actor_key) VALUES (?, ?, ?)')->execute([$id, $name, $name]);
}
$players = [
    [1, 'imprinted', 'Origin Bloom', 1, null, $ago(60)],
    [2, 'imprinted', 'Soft Colony', 2, 1, $ago(30)],
    [3, 'imprinted', 'Soft Colony', 3, 2, $ago(2)],
    [4, 'imprinted', 'Soft Colony', 3, 2, $ago(1)],
    [5, 'imprinted', 'Grid Tide', 4, null, $ago(3)],
    [6, 'imprinted', 'Loop', 2, 7, $ago(40)],
    [7, 'imprinted', 'Loop', 2, 6, $ago(40)],
    [8, 'imprinted', 'Lost', 2, 9, $ago(20)],
    [9, 'unknown', null, 1, null, null],
    [10, 'imprinted', 'Soft Colony', 2, 1, $ago(10)],
];
foreach ($players as $p) {
    $db->prepare('INSERT INTO phyrian_players VALUES (?, ?, ?, ?, ?, ?)')->execute($p);
}
$db->exec("INSERT INTO phyrian_bridge_links VALUES (5, '55555555-5555-5555-5555-555555555555', 'verified', NULL)");
$db->exec("INSERT INTO phyrian_bridge_links VALUES (2, '22222222-2222-2222-2222-222222222222', 'verified', '2026-10-01 00:00:00+00')");
$db->prepare("INSERT INTO phyrian_requests (kind, status, resolved_at) VALUES ('resonance', 'accepted', ?)")->execute([$ago(1)]);
$db->prepare("INSERT INTO phyrian_requests (kind, status, resolved_at) VALUES ('resonance', 'accepted', ?)")->execute([$ago(9)]);
$db->prepare("INSERT INTO phyrian_requests (kind, status, resolved_at) VALUES ('resonance', 'denied', ?)")->execute([$ago(1)]);
$db->prepare("INSERT INTO phyrian_requests (kind, status, resolved_at) VALUES ('imprint', 'accepted', ?)")->execute([$ago(1)]);
$db->prepare('INSERT INTO phyrian_clans (created_at) VALUES (?)')->execute([$ago(4)]);

$loaded = ap_phyrian_tree_load($db);
$check(!empty($loaded['ok']), 'tree loads');
$rows = $loaded['rows'] ?? [];
$ids = array_map(static fn (array $r): string => $r['key'], $rows);
$check(count($rows) === 9 && !in_array('u9', $ids, true), 'only imprinted players load');
$linked = [];
foreach ($rows as $r) {
    $linked[$r['key']] = $r['linked'];
}
$check($linked['u5'] === true && $linked['u2'] === false, 'only verified, not unlinked, bridge links count as grid-linked');

$tree = ap_phyrian_tree_build($rows);
$check(count($tree['root_of']) === 9, 'every player appears once');
$check($tree['children']['u1'] === ['u2', 'u10'] && $tree['children']['u2'] === ['u3', 'u4'], 'children follow imprinted_by');
$check(in_array('u5', $tree['roots'], true) && in_array('u8', $tree['roots'], true), 'grid-linked and orphaned players become roots');
$loopRoots = array_values(array_intersect($tree['roots'], ['u6', 'u7']));
$check(count($loopRoots) === 1, 'a parent loop is broken once');
$check($tree['root_of']['u3'] === 'u1' && $tree['root_of']['u4'] === 'u1', 'root_of walks to the origin');

$recap = ap_phyrian_tree_recap($rows, $tree, $db, $now);
$check($recap['new_imprints'] === 3, 'recap counts imprints from the last seven days');
$check($recap['exchanges'] === 1, 'recap counts accepted resonance exchanges in the window');
$check($recap['new_clans'] === 1, 'recap counts new clans');
$check($recap['top_root'] === 'u1' && $recap['top_count'] === 2, 'fastest line ignores brand-new roots');

$text = ap_phyrian_tree_recap_text($recap, count($rows));
$check(str_contains($text, '3 new imprints') && str_contains($text, '1 resonance exchange,'), 'recap text has counts');
$names = array_filter($users, static fn (string $n): bool => strlen($n) > 3);
$named = false;
foreach ($names as $n) {
    if (stripos($text, '@' . $n) !== false || stripos($text, $n . "'s") !== false) {
        $named = true;
    }
}
$check(!$named, 'recap text names nobody');

$html = ap_phyrian_tree_page_html(4, 'csrf-ok', $db, $now);
$check(str_contains($html, 'Family tree') && str_contains($html, 'Share this recap'), 'page renders tree and share form');
$check(str_contains($html, '&lt;script&gt;') && !str_contains($html, '@<script>'), 'usernames are escaped');
$check(str_contains($html, 'Grid-linked') && str_contains($html, 'imprinted on the grid'), 'grid-linked root is labelled');
$check(str_contains($html, 'imprinted by someone not on the tree'), 'orphan root is labelled');
$check(substr_count($html, 'class="is-path"') === 3, 'viewer path highlights viewer, parent and origin');
$check(str_contains($html, 'page=dossier&amp;owner=2'), 'nodes link to dossiers');
$check(strpos($html, '@origin') < strpos($html, '@gridder'), 'origin line comes first');
$guest = ap_phyrian_tree_page_html(0, '', $db, $now);
$check(!str_contains($guest, 'Share this recap'), 'signed-out viewers get no share form');
$newcomer = ap_phyrian_tree_page_html(9, 'csrf-ok', $db, $now);
$check(str_contains($newcomer, 'not on the tree yet'), 'unimprinted viewer gets a join hint');

// Grid lineage mirrored from OpenSim.
$db->exec('CREATE TABLE phyrian_grid_lineage (avatar_uuid TEXT PRIMARY KEY, avatar_name TEXT, parent_uuid TEXT, strain TEXT, generation INTEGER, imprinted_at TEXT, synced_at TEXT)');
$gUuid = static fn (string $c): string => str_repeat($c, 8) . '-' . str_repeat($c, 4) . '-' . str_repeat($c, 4) . '-' . str_repeat($c, 4) . '-' . str_repeat($c, 12);
$grid = [
    // Grid-only parent of the linked VAAK player 5, itself imprinted by a grid-only root.
    [$gUuid('a'), 'Ada Gridborn', $gUuid('b'), 'Grid Tide', 3, $ago(30)],
    [$gUuid('b'), 'Bex <b>Root</b>', null, 'Grid Tide', 2, $ago(90)],
    // Linked player 5's own grid row points at Ada.
    [$gUuid('5'), 'Gridder Avatar', $gUuid('a'), 'Grid Tide', 4, $ago(3)],
    // Grid-only child of a linked VAAK player.
    [$gUuid('c'), 'Cy Newcomer', $gUuid('5'), 'Grid Tide', 5, $ago(1)],
    // Registered but never imprinted on the grid: not on the tree.
    [$gUuid('d'), 'Dee Unmarked', null, '', 1, null],
];
foreach ($grid as $g) {
    $db->prepare('INSERT INTO phyrian_grid_lineage (avatar_uuid, avatar_name, parent_uuid, strain, generation, imprinted_at) VALUES (?, ?, ?, ?, ?, ?)')->execute($g);
}
$gl = ap_phyrian_tree_load($db);
$gRows = [];
foreach ($gl['rows'] ?? [] as $r) {
    $gRows[$r['key']] = $r;
}
$check(count($gRows) === 12, 'grid-only avatars join the tree, unimprinted ones do not');
$check(($gRows['u5']['parent_key'] ?? '') === 'g' . $gUuid('a'), 'linked player takes its grid parent when VAAK has none');
$check(($gRows['g' . $gUuid('c')]['parent_key'] ?? '') === 'u5', 'grid parent that is linked resolves to the VAAK node');
$check(!isset($gRows['g' . $gUuid('5')]), 'a linked avatar is not duplicated as a grid node');
$gTree = ap_phyrian_tree_build($gl['rows']);
$check(($gTree['root_of']['g' . $gUuid('c')] ?? '') === 'g' . $gUuid('b'), 'line runs from VAAK back through grid-only avatars');
$gHtml = ap_phyrian_tree_page_html(4, 'csrf-ok', $db, $now);
$check(str_contains($gHtml, 'Ada Gridborn') && str_contains($gHtml, 'Grid only'), 'grid-only avatars show their grid name and badge');
$check(!str_contains($gHtml, '@Ada') && !preg_match('/<a [^>]*>Ada Gridborn/', $gHtml), 'grid-only avatars get no profile link');
$check(str_contains($gHtml, 'Bex &lt;b&gt;Root&lt;/b&gt;'), 'grid names are escaped');
$check(!str_contains($gHtml, 'Dee Unmarked'), 'unimprinted grid avatars stay off the tree');
$gRecap = ap_phyrian_tree_recap($gl['rows'], $gTree, $db, $now);
$check($gRecap['new_imprints'] === 4, 'recap counts grid-only imprints too');
$db->exec('DROP TABLE phyrian_grid_lineage');
$check(count(ap_phyrian_tree_load($db)['rows'] ?? []) === 9, 'missing grid table leaves the VAAK tree as it was');

$sent = [];
$deliver = static function (string $body) use (&$sent): array {
    $sent[] = $body;
    return ['ok' => true];
};
$check(empty(ap_phyrian_tree_recap_publish(4, 'hi', true, 'bad', $deliver)['ok']), 'bad csrf is rejected');
$check(empty(ap_phyrian_tree_recap_publish(4, 'hi', false, 'csrf-ok', $deliver)['ok']), 'unconfirmed post is rejected');
$check(empty(ap_phyrian_tree_recap_publish(0, 'hi', true, 'csrf-ok', $deliver)['ok']), 'signed-out post is rejected');
$check(empty(ap_phyrian_tree_recap_publish(4, '  <b></b> ', true, 'csrf-ok', $deliver)['ok']), 'empty post is rejected');
$check($sent === [], 'nothing posted before a valid confirm');
$ok = ap_phyrian_tree_recap_publish(4, '<b>Week</b> ' . str_repeat('x', 600), true, 'csrf-ok', $deliver);
$check(!empty($ok['ok']) && count($sent) === 1, 'confirmed post goes out once');
$check(!str_contains($sent[0] ?? '', '<b>') && mb_strlen($sent[0] ?? '') === AP_PHYRIAN_TREE_RECAP_TEXT_MAX, 'post text is stripped and capped');

$broken = new PDO('sqlite::memory:');
$check(empty(ap_phyrian_tree_load($broken)['ok']), 'missing tables fail closed');
$check(str_contains(ap_phyrian_tree_page_html(1, 'csrf-ok', $broken, $now), 'not available'), 'page shows a notice when tables are missing');

if ($failures !== []) {
    fwrite(STDERR, "phyrian-tree-smoke FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "phyrian-tree-smoke PASS ({$checks} checks)\n";
