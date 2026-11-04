<?php
declare(strict_types=1);
/**
 * Full-page Home/Local/Federated send the shell (head, sidebars, spinner)
 * before they fetch their first timeline page, so a slow Rust fetch or a cold
 * ranked cache no longer holds back the first byte. Source-level checks; no
 * database. The rendered HTML was compared against the old order by hand
 * (PostgreSQL + Redis, cold and warm cache): byte-identical for all three
 * views, with the kill switch on and off.
 */
$root = getenv('VAAK_FIRST_PAINT_ROOT') ?: dirname(__DIR__, 2);
$checks = 0;
function first_paint_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

$admin = (string) file_get_contents($root . '/api/ap-admin.php');

// The deferral decision: full page, first page, a ranked cache key, switch on.
$decide = strpos($admin, '$adminTlFirstPaintDeferred = !$isPartial');
first_paint_check($decide !== false, 'deferral decision present');
$decision = substr($admin, (int) $decide, 400);
first_paint_check(str_contains($decision, "in_array(\$view, ['home', 'local', 'feed'], true)"), 'only Home/Local/Federated defer');
first_paint_check(str_contains($decision, "\$adminTlCacheKey !== ''"), '_force rebuilds (no cache key) keep the old path');
first_paint_check(str_contains($decision, "getenv('VAAK_TL_FIRST_PAINT_AFTER_SHELL')) !== '0'"), 'kill switch');

// Deferred requests must still skip the PHP rebuild paths before the shell.
$after = substr($admin, (int) $decide, 900);
first_paint_check(str_contains($after, "if (\$adminTlFirstPaintDeferred) {\n    // The lean seed always ends with \$adminTlFromCache = true"), 'deferred path marks the ranked cache as handled');
first_paint_check(str_contains($after, "register_shutdown_function('admin_tl_release_stampede_lock')"), 'stampede lock released even if the shell throws');

// The lean seed recomputes the miss state itself (the flag was forced true).
$lean = strpos($admin, 'function admin_tl_full_page_lean_seed(bool $wasFromCache): void');
first_paint_check($lean !== false, 'lean seed function');
$leanBody = substr($admin, (int) $lean, 1600);
first_paint_check(str_contains($leanBody, "&& !\$wasFromCache\n"), 'lean seed gates on the real cache state');
first_paint_check(str_contains($leanBody, "// A deferred call arrives with \$adminTlFromCache already forced true.\n        \$adminTlFromCache = false;"), 'lean seed resets the forced flag so a cold cache still defers to client fill');

// Non-deferred requests keep the old order.
first_paint_check(str_contains($admin, "if (empty(\$adminTlFirstPaintDeferred)) {\n    admin_tl_release_stampede_lock();\n    admin_tl_full_page_first_paint();\n}"), 'inline path unchanged when not deferred');

// Deferred work runs right after the shell flush and before the timeline markup.
$flush = strpos($admin, "admin_page_timing_mark('shell');");
$deferredCall = strpos($admin, "if (!empty(\$adminTlFirstPaintDeferred)) {\n            admin_tl_full_page_lean_seed(\$adminTlFromCacheAtStart);\n            admin_tl_release_stampede_lock();\n            admin_tl_full_page_first_paint();");
$chips = strpos($admin, '<?= admin_bsky_home_chips_slot(');
$homeBranch = strpos($admin, "      <?php elseif (\$view === 'home'): ?>");
first_paint_check($flush !== false && $deferredCall !== false, 'deferred call present');
first_paint_check($flush < $deferredCall, 'deferred fetch runs after the shell flush');
first_paint_check($chips !== false && $deferredCall < $chips, 'deferred fetch runs before the timeline markup');
first_paint_check($homeBranch !== false && $deferredCall < $homeBranch, 'deferred fetch runs before the Home branch reads it');

// Nothing between the old fetch point and the shell reads the timeline globals.
$fpFn = strpos($admin, 'function admin_tl_full_page_first_paint(): void');
$doctype = strpos($admin, "<!DOCTYPE html>\n<!-- Greetings");
first_paint_check($fpFn !== false && $doctype !== false, 'first paint function + doctype');
$fnEnd = strpos($admin, "\n}\n", (int) $fpFn);
$between = substr($admin, (int) $fnEnd, (int) $flush - (int) $fnEnd);
$partialStart = strpos($between, "if (\$isPartial && in_array(\$view, ['home', 'feed', 'local'], true)) {");
first_paint_check($partialStart !== false, 'timeline partial branch located');
$partialEnd = strpos($between, "\n}\n", (int) $partialStart);
$outside = substr($between, 0, (int) $partialStart) . substr($between, (int) $partialEnd);
foreach (['$adminHomeAxumHtml', '$adminHomeAxumStatuses', '$adminTlAxumHtml', '$homeTimeline', '$localTimeline', '$feedTimeline', '$adminTlCachedHasMore', '$adminTlFullPageDefer'] as $var) {
    // The timeline partial (which exits before any HTML) may read them; full-page code may not.
    first_paint_check(!preg_match('/' . preg_quote($var, '/') . '\b/', $outside), "{$var} is not read before the shell");
}

// Timing marks: head (with Server-Timing), shell, timeline, done.
foreach (["admin_page_timing_mark('head');", "admin_page_timing_mark('shell');", "admin_page_timing_mark('timeline');", "admin_page_timing_mark('done');"] as $mark) {
    first_paint_check(substr_count($admin, $mark) === 1, "timing mark {$mark}");
}
first_paint_check(str_contains($admin, "header('Server-Timing: app-head;dur='"), 'Server-Timing header');

// The ranked cache key uses the worker's follow list, or full pages never
// find the cache ranked-warm keeps fresh (merged Bluesky follows, dropped
// rejected follows and empty partial lists all gave PHP a different key).
$rust = (string) file_get_contents($root . '/rust/vaak-worker/src/ranked_warm.rs');
first_paint_check(str_contains($rust, '"SELECT actor_id FROM following WHERE owner_actor_id = $1 OR owner_actor_id = $2"'), 'worker reads every following row for its key');
first_paint_check(str_contains($admin, "\$st = \$db->prepare('SELECT actor_id FROM following WHERE owner_actor_id = ? OR owner_actor_id = ?');"), 'PHP reads the same rows');
first_paint_check(str_contains($admin, "\$workerIds = admin_tl_cache_follow_ids(\$owner);"), 'cache key uses the worker follow list');

$fixture = ['https://b.example/users/y', 'https://a.example/users/x'];
sort($fixture, SORT_STRING);
first_paint_check('v13_home_u1_aon_' . substr(hash('sha256', implode('|', $fixture)), 0, 24) === 'v13_home_u1_aon_73c8dea2f8ebbc4876e1037b', 'PHP key format matches the worker fixture');
first_paint_check(str_contains($rust, 'assert_eq!(key, "v13_home_u1_aon_73c8dea2f8ebbc4876e1037b");'), 'worker pins the same fixture key');
first_paint_check(str_contains($admin, "return 'v13_' . \$view . '_u' . \$owner . '_a' . \$algorithmMode . '_' . substr(hash('sha256', implode('|', \$parts)), 0, 24);"), 'PHP key built from the shared parts');

echo "first-paint-after-shell-smoke: {$checks} checks passed\n";
