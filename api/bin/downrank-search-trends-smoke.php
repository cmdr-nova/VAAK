<?php
declare(strict_types=1);
/** Downrank terms on Search and trends; real helpers over an in-memory term table. */
require __DIR__ . '/migration-reference-functions.php';
reference_load(dirname(__DIR__) . '/ap-db.php', [
    'ap_downrank_phrase_matches_text', 'ap_downrank_text_categories', 'ap_downrank_actor_exempt',
    'ap_downrank_status_text_matches', 'ap_search_downrank_statuses',
]);
reference_load(dirname(__DIR__) . '/ap-masto-entities.php', ['ap_masto_trend_status_is_downranked', 'ap_masto_trend_item_actors']);
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE ap_home_downrank_terms (id INTEGER PRIMARY KEY, phrase TEXT, category TEXT, enabled INTEGER)');
$db->exec("INSERT INTO ap_home_downrank_terms (phrase, category, enabled) VALUES ('rage bait', 'custom', 1), ('old term', 'custom', 0)");
function ap_db(): PDO { return $GLOBALS['db']; }
function ap_masto_trend_actor_refs(string $actor): array { return [rtrim($actor, '/')]; }
$GLOBALS['refs'] = ['__terms' => true];
function ap_masto_trend_downrank_ref_set(int $ownerUserId): array { return $GLOBALS['refs']; }
$checks = 0;
function dr_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
$post = static fn(string $id, string $actor, string $content): array
    => ['id' => $id, 'content' => $content, 'account' => ['uri' => $actor, 'url' => $actor]];
$remote = 'https://remote.example/users/a';
$local = 'https://mkultra.monster/users/juno';
try {
    dr_check(ap_downrank_text_categories('This is <b>RAGE BAIT</b> again') === ['custom'], 'admin term matches case-insensitively');
    dr_check(ap_downrank_text_categories('old term here') === [], 'disabled term ignored');
    dr_check(ap_downrank_text_categories('#ragebait outrage') === [], 'hashtag form does not match');
    dr_check(in_array('direct_abuse', ap_downrank_text_categories('go fuck yourself'), true), 'built-in pattern still fires');
    dr_check(ap_downrank_status_text_matches($post('1', $remote, 'pure rage bait')), 'remote post with term matches');
    dr_check(!ap_downrank_status_text_matches($post('2', $local, 'pure rage bait')), 'local author exempt like Home');
    dr_check(ap_downrank_status_text_matches(['content' => 'x', 'spoiler_text' => 'rage bait', 'account' => ['uri' => $remote]]), 'content warning counts');
    dr_check(!ap_downrank_status_text_matches(['name' => 'ragebait', 'url' => 'https://mkultra.monster/tags/x']), 'tag entity never matches');
    dr_check(ap_masto_trend_status_is_downranked($post('1', $remote, 'rage bait'), $GLOBALS['refs']), 'trend status drops on term');
    dr_check(!ap_masto_trend_status_is_downranked($post('1', $remote, 'rage bait'), ['manual:https://other.example/u' => true]), 'no term flag when downranking is off');
    $contributor = ['account' => ['uri' => $remote], 'content' => 'rage bait #tag', 'trend_actors' => [$remote]];
    dr_check(ap_masto_trend_status_is_downranked($contributor, $GLOBALS['refs']), 'trend tag contributor drops on term');
    $sorted = ap_search_downrank_statuses([$post('1', $remote, 'rage bait'), $post('2', $remote, 'nice day'), $post('3', $local, 'rage bait')], 7);
    dr_check(array_column($sorted, 'id') === ['2', '3', '1'], 'search moves downranked post last, keeps it');
    dr_check(count(ap_search_downrank_statuses([$post('1', $remote, 'rage bait')], 0)) === 1, 'guest search untouched');
    $GLOBALS['refs'] = ['manual:' . $remote => true];
    $sorted = ap_search_downrank_statuses([$post('1', $remote, 'hello'), $post('2', 'https://b.example/u', 'hi')], 7);
    dr_check(array_column($sorted, 'id') === ['2', '1'], 'search moves manually deprioritized author last');
} catch (Throwable $e) {
    fwrite(STDERR, 'downrank-search-trends-smoke: FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "downrank-search-trends-smoke: PASS ({$checks} checks)\n";
