<?php
/**
 * Trending tags, Nostr leg (ap_nostr_trend_tag_notes): only a linked
 * viewer's follows, Show in Home respected, hidden/muted/downranked keys
 * left out, `t` tags appended, old notes dropped. Needs a disposable
 * Postgres with the Nostr migrations; set NOSTR_E2E_PG_DSN (and
 * NOSTR_E2E_PG_USER / NOSTR_E2E_PG_PASS) or the check is skipped.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/ap-nostr.php';

$dsn = getenv('NOSTR_E2E_PG_DSN');
if (!is_string($dsn) || $dsn === '') {
    echo "nostr-trend-tags: SKIP (no NOSTR_E2E_PG_DSN)\n";
    exit(0);
}
$db = new PDO($dsn, getenv('NOSTR_E2E_PG_USER') ?: null, getenv('NOSTR_E2E_PG_PASS') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$k = static fn (string $c): string => str_repeat($c, 64);
$now = time();
$db->exec('CREATE TABLE IF NOT EXISTS ap_deprioritized_actors (owner_user_id BIGINT NOT NULL, actor_id TEXT NOT NULL, created_at TEXT NOT NULL)');
// Disposable database only: these tables are emptied.
$db->exec('TRUNCATE nostr_links, nostr_follows, nostr_events, nostr_mutes, nostr_blocks');
$db->exec("DELETE FROM ap_deprioritized_actors WHERE actor_id LIKE 'nostr:%'");
$db->exec("INSERT INTO nostr_links (owner_user_id, pubkey, show_in_home, created_at, updated_at) VALUES (1, '{$k('0')}', true, 'x', 'x'), (2, '{$k('9')}', false, 'x', 'x')");
foreach (['a', 'b', 'c', 'd', 'f'] as $c) {
    $db->exec("INSERT INTO nostr_follows VALUES (1, '{$k($c)}')");
}
$db->exec("INSERT INTO nostr_follows VALUES (2, '{$k('a')}')");
$ins = $db->prepare('INSERT INTO nostr_events (id, pubkey, kind, created_at, content, tags, ingested_at) VALUES (?, ?, ?, ?, ?, ?::jsonb, 0)');
$ins->execute([$k('1'), $k('a'), 1, $now - 3600, 'hello #Gaming', '[]']);
$ins->execute([$k('2'), $k('b'), 1, $now - 3600, 'no hash here', '[["t","cozy"],["content-warning","spoilers"]]']);
$ins->execute([$k('3'), $k('c'), 1, $now - 3600, 'muted key #gaming', '[]']);
$ins->execute([$k('4'), $k('d'), 1, $now - 3600, 'blocked key #gaming', '[]']);
$ins->execute([$k('5'), $k('f'), 1, $now - 3600, 'downranked key #gaming', '[]']);
$ins->execute([$k('6'), $k('e'), 1, $now - 3600, 'not followed #gaming', '[]']);
$ins->execute([$k('7'), $k('a'), 1, $now - 9 * 86400, 'too old #gaming', '[]']);
$ins->execute([$k('8'), $k('a'), 6, $now - 60, '#gaming repost', '[]']);
$ins->execute([$k('9'), $k('a'), 1, $now - 60, 'plain words only', '[]']);
$db->exec("INSERT INTO nostr_mutes (owner_user_id, pubkey, on_nostr, created_at) VALUES (1, '{$k('c')}', true, 0)");
$db->exec("INSERT INTO nostr_blocks (pubkey, blocked_by, created_at) VALUES ('{$k('d')}', 1, 0)");
$db->exec("INSERT INTO ap_deprioritized_actors (owner_user_id, actor_id, created_at) VALUES (1, 'nostr:{$k('f')}', 'x')");

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $why) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $why;
    }
};
$notes = ap_nostr_trend_tag_notes(1, $now - 7 * 86400, 3000, $db);
$byKey = [];
foreach ($notes as $n) {
    $byKey[$n['pubkey']][] = $n;
}
$check(count($notes) === 2, 'two notes count: ' . count($notes));
$check(isset($byKey[$k('a')]) && $byKey[$k('a')][0]['content'] === 'hello #Gaming', 'followed note with a hashtag counts');
$check(isset($byKey[$k('b')]) && str_contains($byKey[$k('b')][0]['content'], '#cozy') && $byKey[$k('b')][0]['cw'] === 'spoilers', 't tag appended and content-warning kept for muted-word checks');
$check(!isset($byKey[$k('c')]), 'muted key left out');
$check(!isset($byKey[$k('d')]), 'admin-blocked key left out');
$check(!isset($byKey[$k('f')]), 'downranked key left out');
$check(!isset($byKey[$k('e')]), 'unfollowed key left out');
$check(ap_nostr_trend_tag_notes(2, $now - 7 * 86400, 3000, $db) === [], 'Show in Home off means no Nostr in trends');
$check(ap_nostr_trend_tag_notes(3, $now - 7 * 86400, 3000, $db) === [], 'no linked npub means no Nostr in trends');
putenv('VAAK_NOSTR=0');
$check(ap_nostr_trend_tag_notes(1, $now - 7 * 86400, 3000, $db) === [], 'VAAK_NOSTR=0 turns it off');
putenv('VAAK_NOSTR');

if ($failures !== []) {
    fwrite(STDERR, "nostr-trend-tags: FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "nostr-trend-tags: PASS ({$checks} checks)\n";
