<?php
declare(strict_types=1);
/** Remote Questions keep their poll options so timelines can paint them, and local accounts can vote on them. */
require __DIR__ . '/migration-reference-functions.php';
reference_load(dirname(__DIR__) . '/ap-db.php', ['ap_db_driver', 'ap_remote_polls_migrate', 'ap_remote_poll_id_base', 'ap_remote_poll_public_id',
    'ap_remote_poll_row_id', 'ap_remote_poll_from_doc', 'ap_remote_poll_store', 'ap_remote_poll_find', 'ap_remote_poll_own_votes',
    'ap_remote_poll_is_expired', 'ap_remote_poll_ballot', 'ap_remote_poll_vote_activities', 'ap_remote_poll_record_vote', 'ap_remote_poll_forget_vote']);
reference_load(dirname(__DIR__) . '/ap-inbox.php', ['ap_remote_poll_vote']);
reference_load(dirname(__DIR__) . '/ap-masto-entities.php', ['ap_masto_format_time', 'ap_masto_remote_poll_entity', 'ap_masto_attach_remote_polls', 'ap_masto_api_poll',
    'ap_masto_api_string_id', 'ap_masto_api_time', 'ap_masto_api_bool', 'ap_masto_api_int']);
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function ap_db(): PDO { return $GLOBALS['db']; }
function ap_as_id(mixed $v): ?string { return is_string($v) ? $v : (is_array($v) && is_string($v['id'] ?? null) ? $v['id'] : null); }
// Network / delivery transport for ap_remote_poll_vote.
const RP_VOTER = 'https://vaak.test/users/fixture';
function ap_outbound_identity(): array { return ['id' => RP_VOTER, 'key_id' => RP_VOTER . '#main-key', 'priv' => '/dev/null']; }
function ap_masto_session_actor_id(): string { return RP_VOTER; }
function ap_is_blocked_actor(string $a): bool { return str_contains($a, 'blocked.example'); }
function ap_is_blocked_inbox(?string $i): bool { return false; }
function ap_fetch_actor_doc(string $a): ?array { $GLOBALS['actor_fetches'][] = $a; return ['id' => $a, 'inbox' => $a . '/inbox', 'endpoints' => ['sharedInbox' => 'https://social.example/inbox']]; }
function ap_resolve_personal_inbox_from_actor_doc(array $doc): ?string { return $doc['inbox'] ?? null; }
function ap_fetch_as2_object(string $url): ?array { return $GLOBALS['as2_docs'][$url] ?? null; }
function ap_deliver_fanout_background(array $activity, array $inboxes, string $keyId, string $priv): bool {
    if (!empty($GLOBALS['deliver_down'])) return false;
    $GLOBALS['delivered'][] = ['activity' => $activity, 'inboxes' => $inboxes, 'key_id' => $keyId];
    return true;
}
function ap_log(string $line): void {}
function ap_short(string $s, int $n = 120): string { return $s; }
$checks = 0;
function rp_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
$choice = static fn(string $name, int $n): array => ['type' => 'Note', 'name' => $name, 'replies' => ['type' => 'Collection', 'totalItems' => $n]];
$q = ['id' => 'https://social.example/users/a/statuses/1', 'type' => 'Question', 'content' => '<p>Tea or coffee?</p>', 'attributedTo' => 'https://social.example/users/a',
    'endTime' => '2099-01-01T12:00:00Z', 'votersCount' => 7, 'oneOf' => [$choice('Tea', 4), $choice('<b>Coffee</b>', 3)]];
$row = static function (string $id): ?array {
    $st = $GLOBALS['db']->prepare('SELECT * FROM remote_polls WHERE note_id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
};
try {
    // A table from before voting (no id / actor_id) gains both on migrate.
    $db->exec('CREATE TABLE remote_polls (note_id TEXT PRIMARY KEY, multiple INTEGER NOT NULL DEFAULT 0, expires_at TEXT, closed INTEGER NOT NULL DEFAULT 0,
        options_json TEXT NOT NULL, votes_count BIGINT NOT NULL DEFAULT 0, voters_count BIGINT NOT NULL DEFAULT 0, updated_at TEXT NOT NULL)');
    $db->exec("INSERT INTO remote_polls (note_id, options_json, updated_at) VALUES ('https://old.example/notes/1', '[{\"title\":\"a\",\"votes_count\":1}]', 'x')");

    $p = ap_remote_poll_from_doc($q);
    rp_check($p['options'] === [['title' => 'Tea', 'votes_count' => 4], ['title' => 'Coffee', 'votes_count' => 3]], 'options and counts, tags stripped');
    rp_check($p['votes_count'] === 7 && $p['voters_count'] === 7 && !$p['multiple'] && !$p['closed'], 'totals and single choice');
    rp_check($p['expires_at'] === '2099-01-01T12:00:00Z', 'end time kept');
    rp_check($p['actor_id'] === 'https://social.example/users/a', 'author kept');
    rp_check(ap_remote_poll_from_doc(['attributedTo' => 'https://evil.example/users/x'] + $q)['actor_id'] === '', 'off-host author dropped');
    rp_check(ap_remote_poll_from_doc(['type' => 'Note', 'id' => 'https://x/1', 'oneOf' => [$choice('a', 1)]]) === null, 'only Questions');
    rp_check(ap_remote_poll_from_doc(['type' => 'Question', 'id' => 'http://x/1', 'oneOf' => [$choice('a', 1)]]) === null, 'https ids only');
    rp_check(ap_remote_poll_from_doc(['type' => 'Question', 'id' => 'https://x/1', 'oneOf' => [['name' => '']]]) === null, 'no empty polls');
    $multi = ap_remote_poll_from_doc(['type' => 'Question', 'id' => 'https://x/2', 'closed' => '2020-01-01T00:00:00Z', 'anyOf' => [$choice('a', 1), $choice('b', 2)]]);
    rp_check($multi['multiple'] && $multi['closed'] && $multi['expires_at'] === '2020-01-01T00:00:00Z', 'anyOf and closed');

    rp_check(ap_remote_poll_store($q) === true, 'stores remote poll');
    $r = $row('https://social.example/users/a/statuses/1');
    rp_check((int) $r['votes_count'] === 7 && (int) $r['voters_count'] === 7 && (int) $r['multiple'] === 0, 'row stored');
    rp_check((int) $row('https://old.example/notes/1')['id'] > 0 && (int) $r['id'] > 0 && (int) $r['id'] !== (int) $row('https://old.example/notes/1')['id'], 'legacy and new rows numbered');
    rp_check($r['actor_id'] === 'https://social.example/users/a', 'author stored');
    $pollId = ap_remote_poll_public_id((int) $r['id']);
    rp_check($pollId > ap_remote_poll_id_base() && ap_remote_poll_row_id($pollId) === (int) $r['id'] && ap_remote_poll_row_id(42) === 0, 'public id range');
    rp_check(ap_remote_poll_find($pollId)['note_id'] === $r['note_id'] && ap_remote_poll_find(42) === null, 'find by public id');
    $q['oneOf'] = [$choice('Tea', 9), $choice('Coffee', 3)];
    $q['votersCount'] = 12;
    ap_remote_poll_store($q);
    $r = $row('https://social.example/users/a/statuses/1');
    rp_check((int) $r['votes_count'] === 12 && json_decode($r['options_json'], true)[0]['votes_count'] === 9, 'Update refreshes counts');
    rp_check(ap_remote_poll_store(['type' => 'Question', 'id' => 'https://mkultra.monster/users/me/notes/1', 'oneOf' => [$choice('a', 1)]]) === false, 'local polls stay in masto_polls');
    rp_check(ap_remote_poll_store(['type' => 'Note', 'id' => 'https://x/3']) === false, 'notes ignored');

    // Ballots.
    rp_check(ap_remote_poll_ballot($r, [1]) === ['ok' => true, 'indexes' => [1]], 'index ballot');
    rp_check(ap_remote_poll_ballot($r, ['Coffee']) === ['ok' => true, 'indexes' => [1]], 'title ballot');
    rp_check(ap_remote_poll_ballot($r, [0, 1])['error'] === 'This poll allows only one choice', 'single choice enforced');
    rp_check(ap_remote_poll_ballot($r, [5])['error'] === 'No valid choices', 'out of range');
    rp_check(ap_remote_poll_ballot($r, [])['error'] === 'No valid choices', 'empty ballot');
    rp_check(ap_remote_poll_ballot(['multiple' => 1] + $r, ['1', 0, 1])['indexes'] === [0, 1], 'multiple choice deduped');
    rp_check(ap_remote_poll_ballot(['closed' => 1] + $r, [0])['error'] === 'Poll expired', 'closed poll');
    rp_check(ap_remote_poll_ballot(['expires_at' => '2001-01-01T00:00:00Z'] + $r, [0])['error'] === 'Poll expired', 'ended poll');

    // Activities: one Create(Note) per option, to the author only.
    $acts = ap_remote_poll_vote_activities(RP_VOTER, 'https://social.example/users/a', $r['note_id'], ['Tea', 'Coffee']);
    $note = $acts[0]['object'];
    rp_check(count($acts) === 2 && $acts[0]['type'] === 'Create' && $note['type'] === 'Note' && $note['name'] === 'Tea' && $acts[1]['object']['name'] === 'Coffee', 'one Create per option');
    rp_check($note['inReplyTo'] === $r['note_id'] && $note['attributedTo'] === RP_VOTER && $acts[0]['actor'] === RP_VOTER, 'vote note shape');
    rp_check($acts[0]['to'] === ['https://social.example/users/a'] && $note['to'] === ['https://social.example/users/a'] && !isset($acts[0]['cc']) && !isset($note['cc']), 'addressed to the author only');
    rp_check(str_starts_with($note['id'], RP_VOTER . '#votes/') && $acts[0]['id'] === $note['id'] . '/activity' && $note['id'] !== $acts[1]['object']['id'], 'fragment ids, never notes');
    rp_check(!str_contains(json_encode($acts), 'Public'), 'never public');

    // Full vote: recorded, counts bumped, federated, one per account.
    $GLOBALS['delivered'] = [];
    $res = ap_remote_poll_vote($pollId, RP_VOTER, [1]);
    rp_check($res['ok'] === true && $res['own_votes'] === [1] && $res['queued'] === 1, 'remote vote accepted');
    $after = $row($r['note_id']);
    rp_check((int) $after['votes_count'] === 13 && (int) $after['voters_count'] === 13 && json_decode($after['options_json'], true)[1]['votes_count'] === 4, 'optimistic count bump');
    rp_check(ap_remote_poll_own_votes($r['note_id'], RP_VOTER) === [1] && ap_remote_poll_own_votes($r['note_id'], 'https://vaak.test/users/other') === null, 'ballot stored per account');
    $sent = $GLOBALS['delivered'][0];
    rp_check($sent['inboxes'] === ['https://social.example/users/a/inbox'] && $sent['activity']['object']['name'] === 'Coffee' && $sent['key_id'] === RP_VOTER . '#main-key', 'delivered to the author inbox, signed as voter');
    rp_check(ap_remote_poll_vote($pollId, RP_VOTER, [0])['error'] === 'Already voted' && count($GLOBALS['delivered']) === 1, 'one vote per account');
    rp_check(ap_remote_poll_vote($pollId, 'https://vaak.test/users/other', [0])['error'] === 'Not signed in', 'signs only as the voter');
    rp_check(ap_remote_poll_vote(ap_remote_poll_id_base() + 999, RP_VOTER, [0])['error'] === 'Poll not found', 'unknown poll');
    // The author's Update replaces the optimistic counts but keeps the ballot.
    $q['oneOf'] = [$choice('Tea', 9), $choice('Coffee', 5)];
    ap_remote_poll_store($q);
    rp_check(json_decode($row($r['note_id'])['options_json'], true)[1]['votes_count'] === 5 && ap_remote_poll_own_votes($r['note_id'], RP_VOTER) === [1], 'Update keeps own vote');

    // Rows from before actor_id ask the origin for the author; failed delivery rolls back.
    $db->exec("UPDATE remote_polls SET options_json = '[{\"title\":\"a\",\"votes_count\":1},{\"title\":\"b\",\"votes_count\":0}]', multiple = 1 WHERE note_id = 'https://old.example/notes/1'");
    $oldId = ap_remote_poll_public_id((int) $row('https://old.example/notes/1')['id']);
    $GLOBALS['deliver_down'] = true;
    $GLOBALS['as2_docs']['https://old.example/notes/1'] = ['id' => 'https://old.example/notes/1', 'type' => 'Question', 'attributedTo' => 'https://old.example/users/o',
        'anyOf' => [$choice('a', 1), $choice('b', 0)]];
    rp_check(ap_remote_poll_vote($oldId, RP_VOTER, [0, 1])['error'] === 'Could not deliver vote', 'delivery failure reported');
    $old = $row('https://old.example/notes/1');
    rp_check($old['actor_id'] === 'https://old.example/users/o' && (int) $old['votes_count'] === 1 && ap_remote_poll_own_votes($old['note_id'], RP_VOTER) === null, 'author refetched, vote rolled back');
    $GLOBALS['deliver_down'] = false;
    $res = ap_remote_poll_vote($oldId, RP_VOTER, [0, 1]);
    rp_check($res['ok'] && $res['own_votes'] === [0, 1] && count($GLOBALS['delivered']) === 3, 'multiple choice sends one Create per option');

    // Mastodon API: entity and response overlay carry voted / own_votes.
    $entity = ap_masto_remote_poll_entity($row($r['note_id']), [1]);
    rp_check($entity['id'] === (string) $pollId && $entity['voted'] === true && $entity['own_votes'] === [1] && $entity['expired'] === false && $entity['options'][1]['title'] === 'Coffee', 'poll entity');
    rp_check(ap_masto_remote_poll_entity($row($r['note_id']), null)['voted'] === false, 'not voted');
    rp_check(ap_masto_api_poll($entity)['own_votes'] === [1], 'notification poll keeps own_votes');
    $status = ['id' => '5', 'uri' => $r['note_id'], 'account' => [], 'poll' => null];
    $local = ['id' => '6', 'uri' => 'https://vaak.test/users/fixture/notes/1', 'account' => [], 'poll' => null];
    $boost = ['id' => '7', 'uri' => 'https://social.example/users/b/statuses/9/activity', 'account' => [], 'poll' => null, 'reblog' => $status];
    $out = ap_masto_attach_remote_polls([$status, $local, $boost]);
    rp_check($out[0]['poll']['own_votes'] === [1] && $out[1]['poll'] === null && $out[2]['reblog']['poll']['id'] === (string) $pollId, 'timeline overlay');
    rp_check(ap_masto_attach_remote_polls($status)['poll']['voted'] === true, 'single status overlay');
    rp_check(ap_masto_attach_remote_polls(['ancestors' => [], 'descendants' => [$status]])['descendants'][0]['poll']['voted'] === true, 'context overlay');
    rp_check(ap_masto_attach_remote_polls([['id' => 'n', 'type' => 'mention', 'status' => $status]])[0]['status']['poll']['voted'] === true, 'notification overlay');
    rp_check(ap_masto_attach_remote_polls(['error' => 'x']) === ['error' => 'x'], 'errors untouched');
} catch (Throwable $e) {
    fwrite(STDERR, 'remote-poll-smoke failed: ' . $e->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, "remote-poll-smoke ok checks={$checks}\n");
