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
$db->exec('CREATE TABLE phyrian_bridge_links (owner_user_id INTEGER PRIMARY KEY, status TEXT, unlinked_at TEXT)');
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
$db->exec("INSERT INTO phyrian_bridge_links VALUES (5, 'verified', NULL)");
$db->exec("INSERT INTO phyrian_bridge_links VALUES (2, 'verified', '2026-10-01 00:00:00+00')");
$db->prepare("INSERT INTO phyrian_requests (kind, status, resolved_at) VALUES ('resonance', 'accepted', ?)")->execute([$ago(1)]);
$db->prepare("INSERT INTO phyrian_requests (kind, status, resolved_at) VALUES ('resonance', 'accepted', ?)")->execute([$ago(9)]);
$db->prepare("INSERT INTO phyrian_requests (kind, status, resolved_at) VALUES ('resonance', 'denied', ?)")->execute([$ago(1)]);
$db->prepare("INSERT INTO phyrian_requests (kind, status, resolved_at) VALUES ('imprint', 'accepted', ?)")->execute([$ago(1)]);
$db->prepare('INSERT INTO phyrian_clans (created_at) VALUES (?)')->execute([$ago(4)]);

$loaded = ap_phyrian_tree_load($db);
$check(!empty($loaded['ok']), 'tree loads');
$rows = $loaded['rows'] ?? [];
$ids = array_map(static fn (array $r): int => $r['owner_user_id'], $rows);
$check(count($rows) === 9 && !in_array(9, $ids, true), 'only imprinted players load');
$linked = [];
foreach ($rows as $r) {
    $linked[$r['owner_user_id']] = $r['linked'];
}
$check($linked[5] === true && $linked[2] === false, 'only verified, not unlinked, bridge links count as grid-linked');

$tree = ap_phyrian_tree_build($rows);
$check(count($tree['root_of']) === 9, 'every player appears once');
$check($tree['children'][1] === [2, 10] && $tree['children'][2] === [3, 4], 'children follow imprinted_by');
$check(in_array(5, $tree['roots'], true) && in_array(8, $tree['roots'], true), 'grid-linked and orphaned players become roots');
$loopRoots = array_values(array_intersect($tree['roots'], [6, 7]));
$check(count($loopRoots) === 1, 'a parent loop is broken once');
$check($tree['root_of'][3] === 1 && $tree['root_of'][4] === 1, 'root_of walks to the origin');

$recap = ap_phyrian_tree_recap($rows, $tree, $db, $now);
$check($recap['new_imprints'] === 3, 'recap counts imprints from the last seven days');
$check($recap['exchanges'] === 1, 'recap counts accepted resonance exchanges in the window');
$check($recap['new_clans'] === 1, 'recap counts new clans');
$check($recap['top_root'] === 1 && $recap['top_count'] === 2, 'fastest line ignores brand-new roots');

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
