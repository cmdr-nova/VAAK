<?php
declare(strict_types=1);
/** Remote Questions keep their poll options so timelines can paint them. */
require __DIR__ . '/migration-reference-functions.php';
reference_load(dirname(__DIR__) . '/ap-db.php', ['ap_remote_polls_migrate', 'ap_remote_poll_from_doc', 'ap_remote_poll_store']);
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function ap_db(): PDO { return $GLOBALS['db']; }
function ap_as_id(mixed $v): ?string { return is_string($v) ? $v : (is_array($v) && is_string($v['id'] ?? null) ? $v['id'] : null); }
$checks = 0;
function rp_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
$choice = static fn(string $name, int $n): array => ['type' => 'Note', 'name' => $name, 'replies' => ['type' => 'Collection', 'totalItems' => $n]];
$q = ['id' => 'https://social.example/users/a/statuses/1', 'type' => 'Question', 'content' => '<p>Tea or coffee?</p>',
    'endTime' => '2099-01-01T12:00:00Z', 'votersCount' => 7, 'oneOf' => [$choice('Tea', 4), $choice('<b>Coffee</b>', 3)]];
$row = static function (string $id): ?array {
    $st = $GLOBALS['db']->prepare('SELECT * FROM remote_polls WHERE note_id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
};
try {
    $p = ap_remote_poll_from_doc($q);
    rp_check($p['options'] === [['title' => 'Tea', 'votes_count' => 4], ['title' => 'Coffee', 'votes_count' => 3]], 'options and counts, tags stripped');
    rp_check($p['votes_count'] === 7 && $p['voters_count'] === 7 && !$p['multiple'] && !$p['closed'], 'totals and single choice');
    rp_check($p['expires_at'] === '2099-01-01T12:00:00Z', 'end time kept');
    rp_check(ap_remote_poll_from_doc(['type' => 'Note', 'id' => 'https://x/1', 'oneOf' => [$choice('a', 1)]]) === null, 'only Questions');
    rp_check(ap_remote_poll_from_doc(['type' => 'Question', 'id' => 'http://x/1', 'oneOf' => [$choice('a', 1)]]) === null, 'https ids only');
    rp_check(ap_remote_poll_from_doc(['type' => 'Question', 'id' => 'https://x/1', 'oneOf' => [['name' => '']]]) === null, 'no empty polls');
    $multi = ap_remote_poll_from_doc(['type' => 'Question', 'id' => 'https://x/2', 'closed' => '2020-01-01T00:00:00Z', 'anyOf' => [$choice('a', 1), $choice('b', 2)]]);
    rp_check($multi['multiple'] && $multi['closed'] && $multi['expires_at'] === '2020-01-01T00:00:00Z', 'anyOf and closed');

    rp_check(ap_remote_poll_store($q) === true, 'stores remote poll');
    $r = $row('https://social.example/users/a/statuses/1');
    rp_check((int) $r['votes_count'] === 7 && (int) $r['voters_count'] === 7 && (int) $r['multiple'] === 0, 'row stored');
    $q['oneOf'] = [$choice('Tea', 9), $choice('Coffee', 3)];
    $q['votersCount'] = 12;
    ap_remote_poll_store($q);
    $r = $row('https://social.example/users/a/statuses/1');
    rp_check((int) $r['votes_count'] === 12 && json_decode($r['options_json'], true)[0]['votes_count'] === 9, 'Update refreshes counts');
    rp_check(ap_remote_poll_store(['type' => 'Question', 'id' => 'https://mkultra.monster/users/me/notes/1', 'oneOf' => [$choice('a', 1)]]) === false, 'local polls stay in masto_polls');
    rp_check(ap_remote_poll_store(['type' => 'Note', 'id' => 'https://x/3']) === false, 'notes ignored');
} catch (Throwable $e) {
    fwrite(STDERR, 'remote-poll-smoke failed: ' . $e->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, "remote-poll-smoke ok checks={$checks}\n");
