<?php
declare(strict_types=1);
/** Actual canonical Library entry + full card over isolated SQLite current rows. */
putenv('VAAK_LIBRARY_NATIVE_RENDER=0'); // Exercise the canonical PHP caller branch only.
ob_start();
require __DIR__ . '/migration-card-reference-capture.php';
$corpus = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR)['captures'];
reference_load(dirname(__DIR__) . '/ap-library-native.php', ['ap_library_native_enabled']);
foreach (['fixture_mutes', 'fixture_blocks', 'fixture_words', 'fixture_server_blocks', 'fixture_status_cache'] as $table) {
    ap_db()->exec('DELETE FROM ' . $table);
}
$GLOBALS['view'] = 'bookmarks';
$GLOBALS['admin_mentions_lean_embed'] = false;
$checks = 0;
function own_cw_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
function own_cw_row(string $body, string $cw, int $sensitive): void {
    $q = ap_db()->prepare('UPDATE masto_statuses SET content_text=?,spoiler_text=?,sensitive=? WHERE local_id=456');
    $q->execute([$body, $cw, $sensitive]);
}
function own_cw_paint(array $status, string $view): string {
    $GLOBALS['view'] = $view;
    ob_start();
    admin_render_library_entries([['kind' => 'masto', 'item' => $status]], $view);
    return (string) ob_get_clean();
}
$own = $corpus['own']['status'];
$own['content'] = '<p>Cached body marker</p>';
$own['spoiler_text'] = '';
$own['sensitive'] = false;
$own['reblog'] = null;
foreach (['bookmarks', 'favourites'] as $view) {
    ap_db()->exec('UPDATE actor_profile SET auto_unblur_sensitive=0');
    own_cw_row('Current own body marker', 'Current own warning', 1);
    $html = own_cw_paint($own, $view);
    own_cw_check(str_contains($html, 'Current own body marker') && str_contains($html, 'Current own warning')
        && str_contains($html, 'cw-gate'), "$view current own CW addition folds current body");
    $stale = $own; $stale['spoiler_text'] = 'Removed warning'; $stale['sensitive'] = true;
    own_cw_row('Current own body marker', '', 0);
    $html = own_cw_paint($stale, $view);
    own_cw_check(!str_contains($html, 'Removed warning') && !str_contains($html, 'cw-gate'), "$view CW removal drops cached gate");
    foreach ([0, 1] as $auto) {
        ap_db()->exec('UPDATE actor_profile SET auto_unblur_sensitive=' . $auto);
        own_cw_row('Current own body marker', '', 1);
        $html = own_cw_paint($own, $view);
        own_cw_check(str_contains($html, 'cw-gate') === ($auto === 0), "$view sensitive-only preference $auto");
        own_cw_row('Current own body marker', 'Custom current warning', 0);
        $html = own_cw_paint($own, $view);
        own_cw_check(str_contains($html, 'Custom current warning') && str_contains($html, 'cw-gate'), "$view custom CW stays folded preference $auto");
    }
    $slash = $own; $slash['uri'] .= '/'; $slash['url'] .= '/';
    own_cw_check(str_contains(own_cw_paint($slash, $view), 'Custom current warning'), "$view root row binds slash entity");
    $boost = $corpus['plain']['status']; $boost['content'] = ''; $boost['reblog'] = $own;
    own_cw_check(str_contains(own_cw_paint($boost, $view), 'Custom current warning'), "$view displayed own boost inner refreshes CW");
    foreach (['', '(quote)', '(media)', '(poll)'] as $plain) {
        own_cw_row($plain, 'Current fallback warning', 1);
        $html = own_cw_paint($own, $view);
        own_cw_check(str_contains($html, 'Cached body marker') && str_contains($html, 'Current fallback warning'), "$view exact placeholder/empty body fallback: $plain");
    }
    own_cw_row('0', 'Literal zero warning', 1);
    $html = own_cw_paint($own, $view);
    own_cw_check(!str_contains($html, 'Cached body marker') && preg_match('/class="body feed-body"[^>]*>0<\/div>/', $html) === 1,
        "$view stored literal zero replaces cached body");
    own_cw_row('Current own body marker', 'Fresh muted warning', 1);
    ap_db()->exec("INSERT INTO fixture_words VALUES(1,'fresh muted warning')");
    own_cw_check(!str_contains(own_cw_paint($own, $view), '<article'), "$view final word gate checks fresh warning");
    ap_db()->exec('DELETE FROM fixture_words');
}
// A peer's source snapshot remains the painter authority for its body/CW.
$peer = $own; $peer['uri'] = $peer['url'] = 'https://mkultra.monster/users/peer/notes/789';
$peer['account']['uri'] = 'https://mkultra.monster/users/peer';
$peer['spoiler_text'] = 'Peer cached warning';
ap_db()->exec("INSERT INTO masto_statuses VALUES('https://mkultra.monster/users/peer/notes/789','Peer stored body',789,'Peer stored warning',1,'public');
INSERT INTO outbox_notes VALUES('https://mkultra.monster/users/peer/notes/789','','Peer stored body','public','[]','[]')");
foreach (['bookmarks', 'favourites'] as $view) {
    $html = own_cw_paint($peer, $view);
    own_cw_check(str_contains($html, 'Peer cached warning') && str_contains($html, 'Cached body marker')
        && !str_contains($html, 'Peer stored warning'), "$view nonown snapshot body/CW unchanged");
}
// Other return views retain their existing cached display warning behavior.
$GLOBALS['view'] = 'home';
$otherView = $own; $otherView['spoiler_text'] = 'Other-view cached warning';
ob_start(); admin_render_masto_status_card($otherView, [], 'home', false, true); $homeHtml = (string) ob_get_clean();
own_cw_check(str_contains($homeHtml, 'Other-view cached warning'), 'home cached warning baseline unchanged');
// Actual unavailable own current row/table cannot restore an unfolded old card.
ap_db()->exec('DELETE FROM masto_statuses WHERE local_id=456');
own_cw_check(!str_contains(own_cw_paint($own, 'bookmarks'), '<article'), 'missing own warning row fails closed');
ap_db()->exec('DROP TABLE masto_statuses');
own_cw_check(!str_contains(own_cw_paint($own, 'favourites'), '<article'), 'genuine current-row PDO failure fails closed');
echo "library-own-cw: PASS ($checks actual Library entry/full-card checks)\n";
