<?php
declare(strict_types=1);
/** Actual deletion writer + canonical saved-card caller, isolated SQLite rows. */
putenv('VAAK_LIBRARY_NATIVE_RENDER=0');
ob_start(); require __DIR__ . '/migration-card-reference-capture.php';
$corpus = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR)['captures'];
reference_load(dirname(__DIR__) . '/ap-library-native.php', ['ap_library_native_enabled']);
reference_load(dirname(__DIR__) . '/ap-db.php', ['ap_events_mark_object_deleted']);
foreach (['fixture_mutes', 'fixture_blocks', 'fixture_words', 'fixture_server_blocks', 'fixture_status_cache', 'mentions'] as $table) ap_db()->exec('DELETE FROM ' . $table);
$checks = 0;
function deleted_check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); $GLOBALS['checks']++; }
function deleted_paint(array $status, string $view): string {
    $GLOBALS['view'] = $view;
    ob_start(); admin_render_library_entries([['kind' => 'masto', 'item' => $status]], $view);
    return (string) ob_get_clean();
}
$source = $corpus['plain']['status'];
$source['id'] = '901'; $source['uri'] = $source['url'] = 'https://remote.test/saved-delete';
$source['content'] = '<p>Deleted saved source marker</p>';
$source['quote'] = null; $source['reblog'] = null;
$root = $source; $root['id'] = '902'; $root['uri'] = $root['url'] = 'https://public.test/parent';
$root['content'] = '<p>Public parent marker</p>';
foreach (['bookmarks', 'favourites'] as $view) {
    ap_db()->exec("DELETE FROM events WHERE id>=100;
        INSERT INTO events VALUES(100,'https://remote.test/saved-delete','Create','log','public','https://remote.test/users/alice')");
    deleted_check(str_contains(deleted_paint($source, $view), 'Deleted saved source marker'), "$view live saved snapshot stays visible");
    deleted_check(ap_events_mark_object_deleted($source['uri']) === 1, "$view actual writer marks stored Create deleted");
    deleted_check(!str_contains(deleted_paint($source, $view), '<article'), "$view writer withdrawal rejects warm saved body");
    $nested = $root; $nested['quote'] = ['state' => 'accepted', 'quoted_status' => $source];
    deleted_check(!str_contains(deleted_paint($nested, $view), '<article'), "$view deleted full quoted child rejects parent");
    $boost = $root; $boost['content'] = ''; $boost['reblog'] = $source;
    deleted_check(!str_contains(deleted_paint($boost, $view), '<article'), "$view deleted boost inner rejects parent");
    ap_db()->prepare('INSERT INTO fixture_status_cache VALUES(?,?)')->execute([$source['uri'], json_encode($source, JSON_THROW_ON_ERROR)]);
    $late = $root; $late['quote'] = ['state' => 'pending']; $late['quote_url'] = $source['uri'];
    deleted_check(!str_contains(deleted_paint($late, $view), '<article'), "$view late cached deleted quote rejects parent");
    ap_db()->exec('DELETE FROM fixture_status_cache');
    ap_db()->exec("UPDATE events SET action_taken='log' WHERE id=100;
        INSERT INTO events VALUES(101,'https://remote.test/saved-delete/','Delete','log','public','https://remote.test/users/alice')");
    deleted_check(!str_contains(deleted_paint($source, $view), '<article'), "$view latest Delete alias rejects saved body");
    ap_db()->exec("UPDATE events SET type='Tombstone' WHERE id=101");
    deleted_check(!str_contains(deleted_paint($source, $view), '<article'), "$view latest Tombstone rejects saved body");
    ap_db()->exec("DELETE FROM events WHERE id=101;
        INSERT INTO events VALUES(102,'https://remote.test/saved-delete','Announce','deleted','public','https://remote.test/users/booster'),
        (103,'https://remote.test/saved-delete','Like','deleted','public','https://remote.test/users/liker')");
    deleted_check(str_contains(deleted_paint($source, $view), 'Deleted saved source marker'), "$view undone interactions keep live original note");
    $unknown = $source; $unknown['uri'] = $unknown['url'] = 'https://ordinary-public.test/cached';
    deleted_check(str_contains(deleted_paint($unknown, $view), 'Deleted saved source marker'), "$view unknown complete public save stays allowed");
    ap_db()->prepare('INSERT INTO mentions(id,object_id,deleted_at,owner_user_id,activity_type) VALUES(?,?,?,?,?)')->execute([990, $unknown['uri'], '2026-10-10T20:00:00Z', 1, 'Create']);
    deleted_check(!str_contains(deleted_paint($unknown, $view), '<article'), "$view known deleted mention source rejects save");
    ap_db()->exec('DELETE FROM mentions');
}
ap_db()->exec('DROP TABLE events');
deleted_check(!str_contains(deleted_paint($source, 'bookmarks'), '<article'), 'unavailable current deletion store fails closed');
echo "library-deletion: PASS ($checks actual writer/caller/full-card checks)\n";
