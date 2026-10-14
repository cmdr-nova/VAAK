<?php
declare(strict_types=1);
/** Library admission references for step 6. No network and no Postgres.
 *  Real visibility, deletion, muted-word, and content-warning functions run
 *  against in-memory SQLite. Block and mute helpers are exact-id stubs so the
 *  test isolates Library's decisions; domain and alias expansion stays in those
 *  helpers. RSS subscription rebind is not exercised. */
if (!extension_loaded('mbstring') || !extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Privacy capture requires mbstring and pdo_sqlite\n");
    exit(2);
}
require_once __DIR__ . '/migration-reference-functions.php';

$fixtureDb = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$fixtureDb->exec('CREATE TABLE ap_users(id INTEGER PRIMARY KEY, actor_id TEXT, disabled_at TEXT)');
$fixtureDb->exec('CREATE TABLE ap_muted_words(id INTEGER PRIMARY KEY, owner_user_id INTEGER, phrase TEXT, created_at TEXT, note TEXT)');
$fixtureDb->exec('CREATE TABLE events(id INTEGER PRIMARY KEY, object_id TEXT, type TEXT, action_taken TEXT, visibility TEXT, actor_id TEXT)');
$fixtureDb->exec('CREATE TABLE outbox_notes(id TEXT PRIMARY KEY, visibility TEXT, to_json TEXT, cc_json TEXT)');
$fixtureDb->exec('CREATE TABLE mentions(id INTEGER PRIMARY KEY, owner_user_id INTEGER, object_id TEXT, deleted_at TEXT, activity_type TEXT)');
$fixtureDb->exec('CREATE TABLE followers(owner_actor_id TEXT, actor_id TEXT)');
$fixtureDb->exec('CREATE TABLE following(owner_actor_id TEXT, actor_id TEXT)');
$fixtureDb->exec('CREATE TABLE bsky_hide_dids(owner_user_id INTEGER, did TEXT)');

const VIEWER = 'https://local.test/users/reader';
const AUTHOR = 'https://remote.test/users/author';
const QUOTED = 'https://remote.test/users/quoted';
const BLOCKED = 'https://remote.test/users/blocked';
const MUTED = 'https://remote.test/users/muted';
const SERVER_BLOCKED = 'https://remote.test/users/server-blocked';
const SERVER_MUTED = 'https://remote.test/users/server-muted';
const DEPRIO = 'https://remote.test/users/deprio';
const ROWHIDDEN = 'https://remote.test/users/rowhidden';
const DIRECT_AUTHOR = 'https://remote.test/users/direct-author';
const GONE = 'https://local.test/users/gone';
/** Prefix hard-coded by ap_visibility_status_audience_allowed. */
const MK_AUTHOR = 'https://mkultra.monster/users/member';

$fixtureDb->exec("INSERT INTO ap_users(id, actor_id, disabled_at) VALUES
    (1,'" . VIEWER . "',NULL),
    (2,'https://local.test/users/other',NULL),
    (3,'',NULL),
    (4,'" . GONE . "','2026-10-01T00:00:00Z')");
$fixtureDb->exec("INSERT INTO ap_muted_words(id, owner_user_id, phrase, created_at, note) VALUES
    (1,1,'Secret Phrase','2026-10-14T00:00:00Z',NULL),
    (2,4,'Secret Phrase','2026-10-14T00:00:00Z',NULL)");
$fixtureDb->exec("INSERT INTO bsky_hide_dids(owner_user_id, did) VALUES(1,'did:plc:hidden')");
$fixtureDb->exec("INSERT INTO outbox_notes(id, visibility, to_json, cc_json) VALUES
    ('" . VIEWER . "/notes/own1','public','[]','[]'),
    ('" . VIEWER . "/notes/own2','public','[]','[]'),
    ('" . GONE . "/notes/own4','public','[]','[]')");

$GLOBALS['server_blocks'] = [SERVER_BLOCKED];
$GLOBALS['server_mutes'] = [SERVER_MUTED];
$GLOBALS['user_blocks'] = [1 => [BLOCKED]];
$GLOBALS['user_mutes'] = [1 => [MUTED, 'did:plc:personally-muted']];
$GLOBALS['deprioritized'] = [1 => [DEPRIO, BLOCKED]];
$GLOBALS['row_hidden'] = [1 => [DEPRIO, ROWHIDDEN]];
$GLOBALS['auto_unblur'] = false;
$GLOBALS['event_id'] = 0;
$GLOBALS['mention_id'] = 0;
$GLOBALS['cases'] = [];
$GLOBALS['cw'] = [];

function ap_db(): PDO { return $GLOBALS['fixtureDb']; }
function privacy_norm(string $actor): string { return rtrim(trim($actor), '/'); }
function privacy_has(array $set, string $actor): bool
{
    $actor = privacy_norm($actor);
    foreach ($set as $item) {
        if (privacy_norm((string) $item) === $actor) return true;
    }
    return false;
}
function ap_is_blocked_actor(?string $actor): bool
{
    return $actor !== null && $actor !== '' && privacy_has($GLOBALS['server_blocks'], $actor);
}
function ap_is_globally_muted_actor(?string $actor, ?string $host = null): bool
{
    return $actor !== null && $actor !== '' && privacy_has($GLOBALS['server_mutes'], $actor);
}
function ap_user_is_blocked(?string $actor, ?string $host, int $owner): bool
{
    return $owner >= 1 && $actor !== null && privacy_has($GLOBALS['user_blocks'][$owner] ?? [], $actor);
}
function ap_is_muted_actor(?string $actor, int $owner): bool
{
    return $owner >= 1 && $actor !== null && privacy_has($GLOBALS['user_mutes'][$owner] ?? [], $actor);
}
function ap_is_deprioritized_actor(?string $actor, int $owner): bool
{
    return $owner >= 1 && $actor !== null && $actor !== '' && privacy_has($GLOBALS['deprioritized'][$owner] ?? [], $actor);
}
function ap_row_is_hidden(?string $actor, ?string $host = null, ?int $owner = null): bool
{
    return $owner !== null && $owner >= 1 && $actor !== null && privacy_has($GLOBALS['row_hidden'][$owner] ?? [], $actor);
}
function ap_bsky_hide_did_set(int $ownerUserId): array
{
    if ($ownerUserId < 1) return [];
    $st = ap_db()->prepare('SELECT DISTINCT did FROM bsky_hide_dids WHERE owner_user_id = ?');
    $st->execute([$ownerUserId]);
    $out = [];
    while ($row = $st->fetch()) {
        $did = (string) ($row['did'] ?? '');
        if ($did !== '') $out[$did] = true;
    }
    return $out;
}
function ap_profile_get(string $actorKey = 'cmdr_nova'): array
{
    return ['auto_unblur_sensitive' => !empty($GLOBALS['auto_unblur']) ? 1 : 0];
}

$root = dirname(__DIR__);
reference_load($root . '/ap-db.php', [
    'ap_fix_utf8',
    'ap_db_owner_actor_id_for_user_id',
    'ap_muted_words_list',
    'ap_muted_words_phrases_cached',
    'ap_muted_words_normalize_haystack',
    'ap_muted_words_match',
    'ap_ordinary_note_owned',
    'ap_ordinary_ask_questions_muted',
    'ap_visibility_ordinary_status_hidden',
]);
reference_load($root . '/ap-visibility.php', [
    'ap_visibility_actor_hidden',
    'ap_visibility_status_hidden',
    'ap_visibility_status_audience_allowed',
]);
reference_load($root . '/ap-admin.php', [
    'h',
    'admin_tweet_content_wrap',
    'admin_cw_gate_html',
    'admin_library_quote_policy_key',
    'admin_library_bind_rss_sources',
    'admin_library_status_not_deleted',
    'admin_library_status_allowed',
    'admin_library_bsky_item_allowed',
    'admin_library_render_context_allowed',
]);

function privacy_rows(string $sql): array
{
    $rows = [];
    foreach (ap_db()->query($sql) ?: [] as $row) {
        $clean = [];
        foreach ($row as $key => $value) {
            if ($value === null) $clean[$key] = null;
            elseif (in_array($key, ['id', 'owner_user_id'], true) && is_numeric($value)) $clean[$key] = (int) $value;
            else $clean[$key] = (string) $value;
        }
        $rows[] = $clean;
    }
    return $rows;
}
function privacy_object(array $map): object
{
    $out = [];
    foreach ($map as $key => $values) $out[(string) $key] = array_values($values);
    return (object) $out;
}
function privacy_policy(array $retained): array
{
    return [
        'users' => privacy_rows('SELECT id, actor_id, disabled_at FROM ap_users ORDER BY id'),
        'words' => privacy_rows('SELECT owner_user_id, phrase FROM ap_muted_words ORDER BY id'),
        'events' => privacy_rows('SELECT id, object_id, type, action_taken, visibility, actor_id FROM events ORDER BY id'),
        'outbox' => privacy_rows('SELECT id, visibility, to_json, cc_json FROM outbox_notes ORDER BY id'),
        'mentions' => privacy_rows('SELECT id, owner_user_id, object_id, deleted_at, activity_type FROM mentions ORDER BY id'),
        'followers' => privacy_rows('SELECT owner_actor_id, actor_id FROM followers ORDER BY owner_actor_id, actor_id'),
        'following' => privacy_rows('SELECT owner_actor_id, actor_id FROM following ORDER BY owner_actor_id, actor_id'),
        'hide_dids' => privacy_rows('SELECT owner_user_id, did FROM bsky_hide_dids ORDER BY owner_user_id, did'),
        'server_blocks' => array_values($GLOBALS['server_blocks']),
        'server_mutes' => array_values($GLOBALS['server_mutes']),
        'user_blocks' => privacy_object($GLOBALS['user_blocks']),
        'user_mutes' => privacy_object($GLOBALS['user_mutes']),
        'deprioritized' => privacy_object($GLOBALS['deprioritized']),
        'row_hidden' => privacy_object($GLOBALS['row_hidden']),
        'retained' => (object) $retained,
    ];
}
function privacy_event(string $object, string $type, string $action, string $visibility, string $actor): void
{
    $GLOBALS['event_id']++;
    ap_db()->prepare('INSERT INTO events(id, object_id, type, action_taken, visibility, actor_id) VALUES(?,?,?,?,?,?)')
        ->execute([$GLOBALS['event_id'], $object, $type, $action, $visibility, $actor]);
}
function privacy_outbox(string $id, string $visibility, array $to, array $cc): void
{
    ap_db()->prepare('INSERT INTO outbox_notes(id, visibility, to_json, cc_json) VALUES(?,?,?,?)')
        ->execute([$id, $visibility, json_encode($to), json_encode($cc)]);
}
function privacy_mention(int $owner, string $object, ?string $deleted, string $type): void
{
    $GLOBALS['mention_id']++;
    ap_db()->prepare('INSERT INTO mentions(id, owner_user_id, object_id, deleted_at, activity_type) VALUES(?,?,?,?,?)')
        ->execute([$GLOBALS['mention_id'], $owner, $object, $deleted, $type]);
}
function privacy_follow(string $viewer, string $author): void
{
    ap_db()->prepare('INSERT INTO following(owner_actor_id, actor_id) VALUES(?,?)')->execute([$viewer, $author]);
}
function privacy_unfollow(string $viewer, string $author): void
{
    ap_db()->prepare('DELETE FROM following WHERE owner_actor_id = ? AND actor_id = ?')->execute([$viewer, $author]);
}
function privacy_status(string $uri, string $visibility, string $author, string $content = 'hello'): array
{
    return ['uri' => $uri, 'visibility' => $visibility, 'content' => $content, 'account' => ['uri' => $author]];
}
function privacy_deep(int $wraps): array
{
    $node = privacy_status('https://remote.test/posts/deep', 'public', AUTHOR, 'layer');
    for ($i = 0; $i < $wraps; $i++) {
        $wrap = privacy_status('https://remote.test/posts/deep', 'public', AUTHOR, 'layer');
        $wrap['quote'] = $node;
        $node = $wrap;
    }
    return $node;
}
function privacy_admit(string $id, int $owner, array $status, string $plain = 'hello', array $hydrated = [], array $previews = [], array $retained = []): void
{
    ap_muted_words_phrases_cached(0, true);
    $GLOBALS['vaak_quote_policy_statuses'] = $retained;
    $actor = (string) ($status['account']['uri'] ?? '');
    $GLOBALS['cases'][] = [
        'id' => $id,
        'owner' => $owner,
        'status' => $status,
        'plain' => $plain,
        'hydrated' => array_values($hydrated),
        'previews' => array_values($previews),
        'policy' => privacy_policy($retained),
        'allowed_ordinary' => admin_library_status_allowed($status, $owner, true),
        'allowed_strict' => admin_library_status_allowed($status, $owner, false),
        'actor_hidden' => ap_visibility_actor_hidden($actor, $owner),
        'render_allowed' => admin_library_render_context_allowed($status, $owner, $hydrated, $previews, $plain),
    ];
}
function privacy_expect(string $id, bool $ordinary, bool $strict, bool $render, bool $actorHidden): void
{
    foreach ($GLOBALS['cases'] as $case) {
        if ($case['id'] !== $id) continue;
        $bad = [];
        if ($case['allowed_ordinary'] !== $ordinary) $bad[] = 'ordinary';
        if ($case['allowed_strict'] !== $strict) $bad[] = 'strict';
        if ($case['render_allowed'] !== $render) $bad[] = 'render';
        if ($case['actor_hidden'] !== $actorHidden) $bad[] = 'actor';
        if ($bad !== []) {
            throw new RuntimeException($id . ' ' . implode(',', $bad)
                . ' got ordinary=' . (int) $case['allowed_ordinary']
                . ' strict=' . (int) $case['allowed_strict']
                . ' render=' . (int) $case['render_allowed']
                . ' actor=' . (int) $case['actor_hidden']);
        }
        return;
    }
    throw new RuntimeException('missing case ' . $id);
}
function privacy_check(string $id, int $owner, array $status, bool $ordinary, bool $strict, bool $render, bool $actorHidden, string $plain = 'hello', array $hydrated = [], array $previews = [], array $retained = []): void
{
    privacy_admit($id, $owner, $status, $plain, $hydrated, $previews, $retained);
    privacy_expect($id, $ordinary, $strict, $render, $actorHidden);
}

$public = privacy_status('https://remote.test/posts/public', 'public', AUTHOR);
privacy_check('guest_public', 0, $public, false, false, false, false);
privacy_check('guest_negative', -3, $public, false, false, false, false);
privacy_check('guest_server_block', 0, privacy_status('https://remote.test/posts/sb', 'public', SERVER_BLOCKED), false, false, false, true);
privacy_check('public', 1, $public, true, true, true, false);
privacy_check('unlisted', 1, privacy_status('https://remote.test/posts/unlisted', 'unlisted', AUTHOR), true, true, true, false);
privacy_check('visibility_case', 1, privacy_status('https://remote.test/posts/case', 'PUBLIC', AUTHOR), true, true, true, false);
privacy_check('empty_actor_public', 3, $public, true, true, true, false);
privacy_check('missing_user_public', 99, $public, true, true, true, false);

privacy_event('https://remote.test/posts/stale', 'Create', 'log', 'private', AUTHOR);
privacy_check('stale_private', 1, privacy_status('https://remote.test/posts/stale', 'public', AUTHOR), false, false, false, false);
privacy_event('https://remote.test/posts/mine', 'Create', 'log', 'private', VIEWER);
privacy_check('private_author', 1, privacy_status('https://remote.test/posts/mine', 'public', AUTHOR), true, true, true, false);

privacy_follow(VIEWER, AUTHOR);
privacy_check('private_follower', 1, privacy_status('https://remote.test/posts/followed', 'private', AUTHOR), true, true, true, false);
privacy_check('followers_grant', 1, privacy_status('https://remote.test/posts/followers', 'followers', AUTHOR), true, true, true, false);
privacy_check('followers_only_grant', 1, privacy_status('https://remote.test/posts/followers-only', 'followers_only', AUTHOR), true, true, true, false);
privacy_unfollow(VIEWER, AUTHOR);
privacy_check('private_revoked', 1, privacy_status('https://remote.test/posts/followed', 'private', AUTHOR), false, false, false, false);

privacy_follow(VIEWER, MK_AUTHOR);
privacy_check('private_mk_following', 1, privacy_status('https://remote.test/posts/mk-private', 'private', MK_AUTHOR), false, false, false, false);
privacy_unfollow(VIEWER, MK_AUTHOR);
ap_db()->prepare('INSERT INTO followers(owner_actor_id, actor_id) VALUES(?,?)')->execute([MK_AUTHOR, VIEWER]);
privacy_check('private_mk_follower', 1, privacy_status('https://remote.test/posts/mk-private', 'private', MK_AUTHOR), true, true, true, false);
ap_db()->exec('DELETE FROM followers');

privacy_check('local_member', 1, privacy_status('https://remote.test/posts/local-member', 'local', MK_AUTHOR), true, true, true, false);
privacy_check('local_remote_author', 1, privacy_status('https://remote.test/posts/local-remote', 'local', AUTHOR), false, false, false, false);
privacy_check('local_empty_viewer', 3, privacy_status('https://remote.test/posts/local-member', 'local', MK_AUTHOR), false, false, false, false);
privacy_check('missing_user_private', 99, privacy_status('https://remote.test/posts/missing-user', 'private', AUTHOR), false, false, false, false);
privacy_check('missing_local_note', 1, privacy_status(MK_AUTHOR . '/notes/absent', 'public', MK_AUTHOR), false, false, false, false);

$shared = MK_AUTHOR . '/notes/shared';
privacy_outbox($shared, 'private', [], [VIEWER]);
privacy_check('outbox_recipient', 1, privacy_status($shared, 'public', AUTHOR), true, true, true, false);
$stranger = MK_AUTHOR . '/notes/stranger';
privacy_outbox($stranger, 'private', ['https://remote.test/users/someone'], []);
privacy_check('outbox_stranger', 1, privacy_status($stranger, 'public', AUTHOR), false, false, false, false);
$override = MK_AUTHOR . '/notes/override';
privacy_event($override, 'Create', 'log', 'private', MK_AUTHOR);
privacy_outbox($override, 'public', [], []);
privacy_check('outbox_public_override', 1, privacy_status($override, 'private', AUTHOR), true, true, true, false);

$direct = 'https://remote.test/posts/direct';
privacy_event($direct, 'Create', 'log', 'direct', DIRECT_AUTHOR);
privacy_mention(1, $direct, null, 'Create');
privacy_check('direct_mention', 1, privacy_status($direct, 'public', DIRECT_AUTHOR), true, true, true, false);
$revoked = 'https://remote.test/posts/direct-revoked';
privacy_event($revoked, 'Create', 'log', 'direct', DIRECT_AUTHOR);
privacy_mention(1, $revoked, '2026-10-14T00:00:00Z', 'Create');
privacy_check('direct_revoked', 1, privacy_status($revoked, 'public', DIRECT_AUTHOR), false, false, false, false);
privacy_event('https://remote.test/posts/direct-absent', 'Create', 'log', 'direct', DIRECT_AUTHOR);
privacy_check('direct_absent', 1, privacy_status('https://remote.test/posts/direct-absent', 'public', DIRECT_AUTHOR), false, false, false, false);
privacy_event('https://remote.test/posts/direct-announce', 'Create', 'log', 'direct', DIRECT_AUTHOR);
privacy_mention(1, 'https://remote.test/posts/direct-announce', null, 'Announce');
privacy_check('direct_announce', 1, privacy_status('https://remote.test/posts/direct-announce', 'public', DIRECT_AUTHOR), false, false, false, false);
privacy_follow(VIEWER, DIRECT_AUTHOR);
privacy_event('https://remote.test/posts/direct-follow', 'Create', 'log', 'direct', DIRECT_AUTHOR);
privacy_check('direct_follow', 1, privacy_status('https://remote.test/posts/direct-follow', 'public', DIRECT_AUTHOR), false, false, false, false);
privacy_unfollow(VIEWER, DIRECT_AUTHOR);
$mentioned = 'https://remote.test/posts/private-mention';
privacy_event($mentioned, 'Create', 'log', 'private', AUTHOR);
privacy_mention(1, $mentioned, null, 'Create');
privacy_check('private_mention', 1, privacy_status($mentioned, 'public', AUTHOR), true, true, true, false);

privacy_check('personal_block', 1, privacy_status('https://remote.test/posts/blocked', 'public', BLOCKED . '/'), false, false, false, true);
privacy_check('personal_mute', 1, privacy_status('https://remote.test/posts/muted', 'public', MUTED), false, false, false, true);
privacy_check('server_block', 1, privacy_status('https://remote.test/posts/server-blocked', 'public', SERVER_BLOCKED), false, false, false, true);
privacy_check('server_mute', 1, privacy_status('https://remote.test/posts/server-muted', 'public', SERVER_MUTED), false, false, false, true);
$viaUrl = privacy_status('https://remote.test/posts/via-url', 'public', AUTHOR);
$viaUrl['account']['url'] = BLOCKED;
privacy_check('block_via_url', 1, $viaUrl, false, false, false, false);
privacy_check('deprioritized_visible', 1, privacy_status('https://remote.test/posts/deprio', 'public', DEPRIO), true, true, true, false);
privacy_check('row_hidden', 1, privacy_status('https://remote.test/posts/rowhidden', 'public', ROWHIDDEN), false, false, false, true);
privacy_check('did_mute', 1, privacy_status('https://remote.test/posts/did-mute', 'public', 'did:plc:personally-muted'), false, false, false, true);

$word = '<p>Hello Secret&nbsp;Phrase today</p>';
privacy_check('word_stranger', 1, privacy_status('https://remote.test/posts/word', 'public', AUTHOR, $word), false, false, false, false);
privacy_check('word_in_attribute', 1, privacy_status('https://remote.test/posts/attr', 'public', AUTHOR, '<span title="Secret Phrase">hello</span>'), true, true, true, false);
privacy_check('word_own', 1, privacy_status(VIEWER . '/notes/own1', 'public', VIEWER, '<p>Secret&nbsp;Phrase</p>'), true, false, true, false);
$nestedWord = privacy_status(VIEWER . '/notes/own2', 'public', VIEWER, 'hello');
$nestedWord['quote'] = privacy_status('https://remote.test/posts/wordy', 'public', AUTHOR, '<p>Secret&nbsp;Phrase</p>');
privacy_check('word_nested_quote', 1, $nestedWord, false, false, false, false);
privacy_check('word_own_disabled', 4, privacy_status(GONE . '/notes/own4', 'public', GONE, '<p>Secret&nbsp;Phrase</p>'), false, false, false, false);
$ask = privacy_status(VIEWER . '/notes/own1', 'public', VIEWER, 'hello');
$ask['vaak_ask'] = ['question' => 'Say the Secret Phrase'];
privacy_check('ask_own', 1, $ask, false, false, false, false);
$askNested = privacy_status(VIEWER . '/notes/own2', 'public', VIEWER, 'hello');
$askChild = privacy_status('https://remote.test/posts/askq', 'public', AUTHOR, 'hello');
$askChild['vaak_ask'] = ['ask_question' => 'Secret Phrase'];
$askNested['quote'] = $askChild;
privacy_check('ask_nested', 1, $askNested, false, false, false, false);

privacy_event('https://remote.test/posts/tomb', 'Tombstone', 'log', 'public', AUTHOR);
privacy_check('deleted_tombstone', 1, privacy_status('https://remote.test/posts/tomb', 'public', AUTHOR), false, false, false, false);
privacy_event('https://remote.test/posts/actdel', 'Create', 'deleted', 'public', AUTHOR);
privacy_check('deleted_action', 1, privacy_status('https://remote.test/posts/actdel', 'public', AUTHOR), false, false, false, false);
privacy_mention(1, 'https://remote.test/posts/gone', '2026-10-14T00:00:00Z', 'Create');
privacy_check('deleted_mention', 1, privacy_status('https://remote.test/posts/gone', 'public', AUTHOR), false, false, false, false);
privacy_mention(1, 'https://remote.test/posts/zero', '0', 'Create');
privacy_check('mention_zero', 1, privacy_status('https://remote.test/posts/zero', 'public', AUTHOR), true, true, true, false);
privacy_event('https://remote.test/posts/live', 'Create', 'log', 'public', AUTHOR);
privacy_mention(1, 'https://remote.test/posts/live', '2026-10-14T00:00:00Z', 'Create');
privacy_check('live_over_mention', 1, privacy_status('https://remote.test/posts/live', 'public', AUTHOR), true, true, true, false);
privacy_event('https://remote.test/posts/newer-del', 'Create', 'log', 'public', AUTHOR);
privacy_event('https://remote.test/posts/newer-del', 'Delete', 'log', 'public', AUTHOR);
privacy_check('newer_delete', 1, privacy_status('https://remote.test/posts/newer-del', 'public', AUTHOR), false, false, false, false);

$childUri = 'https://remote.test/posts/child';
privacy_event($childUri, 'Create', 'log', 'private', QUOTED);
$child = privacy_status($childUri, 'public', QUOTED);
$parent = privacy_status('https://remote.test/posts/parent', 'public', AUTHOR);
$parent['quote'] = $child;
privacy_check('private_child', 1, $parent, false, false, false, false);
privacy_follow(VIEWER, QUOTED);
privacy_check('private_child_granted', 1, $parent, true, true, true, false);
privacy_unfollow(VIEWER, QUOTED);

$quoteSrc = 'https://remote.test/posts/quote-src';
privacy_event($quoteSrc, 'Create', 'log', 'public', AUTHOR);
privacy_event($quoteSrc, 'Quote', 'log', 'private', AUTHOR);
privacy_check('create_beats_quote_event', 1, privacy_status($quoteSrc, 'private', AUTHOR), true, true, true, false);
privacy_event('https://remote.test/posts/quote-only', 'Quote', 'log', 'private', AUTHOR);
privacy_check('quote_only_private', 1, privacy_status('https://remote.test/posts/quote-only', 'public', AUTHOR), false, false, false, false);
privacy_event('https://remote.test/posts/quote-public', 'Quote', 'log', 'public', AUTHOR);
privacy_check('quote_only_public', 1, privacy_status('https://remote.test/posts/quote-public', 'public', AUTHOR), true, true, true, false);
privacy_event('https://remote.test/posts/skip', 'Create', 'skip', 'private', AUTHOR);
privacy_check('event_action_ignored', 1, privacy_status('https://remote.test/posts/skip', 'public', AUTHOR), true, true, true, false);
privacy_event('https://remote.test/posts/frag', 'Create', 'log', 'private', AUTHOR);
privacy_check('fragment_private', 1, privacy_status('https://remote.test/posts/frag/#announce-9', 'public', AUTHOR), false, false, false, false);

privacy_check('depth_16', 1, privacy_deep(16), true, true, true, false);
privacy_check('depth_17', 1, privacy_deep(17), false, false, false, false);

$bskyOk = [
    'uri' => 'at://did:plc:ok/app.bsky.feed.post/1',
    'visibility' => 'public',
    'content' => 'hello',
    'account' => ['uri' => 'did:plc:ok', 'url' => 'https://bsky.app/profile/ok.example'],
    'bsky_post' => ['text' => 'hello', 'author' => ['did' => 'did:plc:ok', 'handle' => 'ok.example']],
];
privacy_check('bsky_ok', 1, $bskyOk, true, true, true, false);
$bskyHide = $bskyOk;
$bskyHide['bsky_post']['author']['did'] = 'did:plc:hidden';
privacy_check('bsky_hide_did', 1, $bskyHide, false, false, false, false);
$bskyBlocked = $bskyOk;
$bskyBlocked['bsky_post']['viewer'] = ['blockedBy' => true];
privacy_check('bsky_blocked_by', 1, $bskyBlocked, false, false, false, false);
$bskyClear = $bskyOk;
$bskyClear['bsky_post']['viewer'] = ['blockedBy' => false];
privacy_check('bsky_blocked_by_false', 1, $bskyClear, true, true, true, false);
$bskyWord = $bskyOk;
$bskyWord['bsky_post']['text'] = 'A Secret Phrase here';
privacy_check('bsky_muted_text', 1, $bskyWord, false, false, false, false);
$bskyNested = $bskyOk;
$bskyNested['bsky_post']['embed'] = ['record' => ['author' => ['did' => 'did:plc:hidden', 'handle' => 'hidden.example']]];
privacy_check('bsky_nested_did', 1, $bskyNested, false, false, false, false);

privacy_check('plain_word', 1, privacy_status('https://remote.test/posts/plain', 'public', AUTHOR, 'hello'), true, true, false, false, 'secret phrase');
privacy_check('plain_word_own', 1, privacy_status(VIEWER . '/notes/own2', 'public', VIEWER, 'hello'), true, true, true, false, 'secret phrase');
$hydratedRoot = privacy_status('https://remote.test/posts/hydrated-root', 'public', AUTHOR, 'hello');
$hydratedChild = privacy_status(VIEWER . '/notes/own1', 'public', VIEWER, '<p>Secret&nbsp;Phrase</p>');
privacy_check('hydrated_own_word', 1, $hydratedRoot, true, true, false, false, 'hello', [$hydratedChild]);
privacy_check('preview_blocked_actor', 1, privacy_status('https://remote.test/posts/preview-root', 'public', AUTHOR), true, true, false, false, 'hello', [], [['url' => BLOCKED, 'text' => 'hello']]);
$retainedUrl = 'https://remote.test/posts/retained';
privacy_check('retained_private_preview', 1, privacy_status('https://remote.test/posts/retained-root', 'public', AUTHOR), true, true, false, false, 'hello', [], [['url' => $retainedUrl, 'text' => 'hello']], [$retainedUrl => privacy_status($retainedUrl, 'private', AUTHOR)]);

function privacy_cw(string $id, string $spoiler, bool $sensitive, string $inner, bool $auto, callable $check): void
{
    $GLOBALS['auto_unblur'] = $auto;
    $GLOBALS['vaak_actor_key'] = 'reader';
    $html = admin_cw_gate_html($spoiler, $sensitive, $inner, null);
    $check($html);
    $GLOBALS['cw'][] = ['id' => $id, 'spoiler' => $spoiler, 'sensitive' => $sensitive, 'inner' => $inner, 'auto' => $auto, 'html' => $html];
}
privacy_cw('open_plain', '', false, 'Hi', false, static function (string $html): void {
    if ($html !== '<div class="tweet-content">Hi</div>') throw new RuntimeException('open plain CW changed');
});
privacy_cw('sensitive_closed', '', true, 'Hi', false, static function (string $html): void {
    if (!str_starts_with($html, '<details class="cw-gate">') || !str_contains($html, 'Sensitive content')) throw new RuntimeException('sensitive CW did not stay closed');
});
privacy_cw('sensitive_open', '', true, 'Hi', true, static function (string $html): void {
    if ($html !== '<div class="tweet-content">Hi</div>') throw new RuntimeException('auto-unblur did not open an empty warning');
});
privacy_cw('custom_stays_closed', 'Tom & "Jerry\'s" <cw>', true, 'Hi', true, static function (string $html): void {
    if (!str_starts_with($html, '<details') || !str_contains($html, 'Tom &amp; &quot;Jerry&#039;s&quot; &lt;cw&gt;')) throw new RuntimeException('custom CW was opened or not escaped');
});
privacy_cw('empty_inner', '', true, '', false, static function (string $html): void {
    if (!str_contains($html, '(sensitive media — open post on remote if nothing appears)')) throw new RuntimeException('empty sensitive body lost its placeholder');
});
privacy_cw('neither', '', false, '', true, static function (string $html): void {
    if ($html !== '') throw new RuntimeException('empty non-sensitive body painted a shell');
});
privacy_cw('whitespace_inner', '', true, " \t\n", false, static function (string $html): void {
    if (!str_contains($html, '<div class="cw-body"></div>') || str_contains($html, 'sensitive media')) throw new RuntimeException('whitespace body was treated as missing');
});
privacy_cw('trimmed_spoiler', '  ', false, 'x', false, static function (string $html): void {
    if ($html !== '<div class="tweet-content">x</div>') throw new RuntimeException('blank spoiler was kept');
});
privacy_cw('spoiler_only', 'spoiler', false, 'body', true, static function (string $html): void {
    if (!str_starts_with($html, '<details') || !str_contains($html, '>spoiler<')) throw new RuntimeException('custom spoiler opened because sensitive was off');
});

echo json_encode(['cases' => $GLOBALS['cases'], 'cw' => $GLOBALS['cw']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
