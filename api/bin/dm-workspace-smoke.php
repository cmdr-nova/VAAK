<?php
declare(strict_types=1);

/**
 * DM screen rework: newest messages first-load, live "after id" reads, chat
 * rows, and DMs between VAAK accounts that never leave this server.
 * SQLite only; no network and no bootstrap of ap-admin.php.
 */
require __DIR__ . '/migration-reference-functions.php';

$checks = 0;
$failures = [];
function dm_ws_check(bool $ok, string $label): void
{
    $GLOBALS['checks']++;
    if (!$ok) {
        $GLOBALS['failures'][] = $label;
    }
}

function h(mixed $s): string
{
    if ($s === null || $s === false) {
        return '';
    }
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ap_db(): PDO
{
    return $GLOBALS['db'];
}

function ap_db_default_owner_user_id(): int
{
    return (int) ($GLOBALS['owner'] ?? 1);
}

function ap_dm_peer_key(string $actorId): string
{
    return rtrim(trim($actorId), '/');
}

function ap_is_blocked_actor(?string $actorId): bool
{
    return in_array(rtrim((string) $actorId, '/'), $GLOBALS['server_blocked'] ?? [], true);
}

function ap_user_block_find(?string $actorId, ?string $host, int $ownerUserId): ?array
{
    $key = $ownerUserId . '|' . rtrim((string) $actorId, '/');
    return isset($GLOBALS['personal_blocks'][$key]) ? ['scope' => 'actor', 'value' => $actorId] : null;
}

function ap_log(string $line): void
{
    $GLOBALS['logs'][] = $line;
}

function ap_dm_store(array $row): array
{
    $GLOBALS['stored'][] = $row;
    return ['ok' => true, 'id' => count($GLOBALS['stored'])];
}

function admin_avatar_img(?string $actorId, string $class = 'tweet-av', bool $profileHover = true): string
{
    return '<img class="' . h($class) . '" data-actor="' . h($actorId) . '">';
}

function admin_dm_thread_message_html(array $row, bool $isOut): string
{
    return '<div class="dm-bubble' . ($isOut ? ' dm-out' : ' dm-in') . '">' . h($row['content'] ?? '') . '</div>';
}

function relative_time(mixed $iso): string
{
    return 'ago';
}

function admin_owner_user_id(): int
{
    return 1;
}

function vaak_actor_id(): string
{
    return 'https://mkultra.monster/users/nova';
}

function actor_display_name(?string $actorId, bool $allowFetch = false): string
{
    return 'Name of ' . basename((string) $actorId);
}

function actor_handle(?string $actorId, ?string $username = null, bool $allowFetch = false): string
{
    return '@' . basename((string) $actorId);
}

$root = dirname(__DIR__, 2);
reference_load($root . '/api/ap-db.php', ['ap_dm_thread', 'ap_dm_thread_after']);
reference_load($root . '/api/ap-inbox.php', ['ap_dm_local_recipient', 'ap_dm_deliver_local']);
reference_load($root . '/api/ap-mastodon.php', ['ap_masto_dm_local_mention_peer']);
reference_load($root . '/api/ap-admin.php', ['admin_dm_message_rows_html', 'admin_dm_people_search', 'admin_local_actor_key_from_id']);

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['db'] = $db;
$db->exec(<<<'SQL'
CREATE TABLE direct_messages (
    id INTEGER PRIMARY KEY, owner_user_id INTEGER, peer_actor_id TEXT, direction TEXT,
    content TEXT, object_id TEXT, created_at TEXT, deleted_at TEXT
);
CREATE TABLE ap_users (id INTEGER PRIMARY KEY, actor_id TEXT, actor_key TEXT, username TEXT, disabled_at TEXT);
CREATE TABLE following (owner_actor_id TEXT, actor_id TEXT);
CREATE TABLE remote_actors (actor_id TEXT PRIMARY KEY, username TEXT, display_name TEXT);
SQL);

// ---- thread windows ----
$peer = 'https://remote.test/users/ada';
$ins = $db->prepare('INSERT INTO direct_messages (owner_user_id, peer_actor_id, direction, content, object_id, created_at, deleted_at) VALUES (?, ?, ?, ?, ?, ?, NULL)');
for ($i = 1; $i <= 250; $i++) {
    $ins->execute([1, $peer, $i % 2 ? 'in' : 'out', 'm' . $i, $peer . '/dm/' . $i, gmdate('c', 1700000000 + $i * 60)]);
}
$ins->execute([2, $peer, 'in', 'someone else', $peer . '/dm/x', gmdate('c', 1700000000)]);
$thread = ap_dm_thread($peer, 200);
dm_ws_check(count($thread) === 200, 'thread returns 200 rows');
dm_ws_check(($thread[0]['content'] ?? '') === 'm51', 'thread starts 200 back from the newest message');
dm_ws_check(($thread[199]['content'] ?? '') === 'm250', 'thread ends on the newest message');
$after = ap_dm_thread_after($peer, 248);
dm_ws_check(array_column($after, 'content') === ['m249', 'm250'], 'after-id read returns only newer rows, oldest first');
dm_ws_check(ap_dm_thread_after($peer, 250) === [], 'after the newest id there is nothing');
dm_ws_check(ap_dm_thread_after($peer . '/', 248) !== [], 'a trailing slash on the peer still matches');
$GLOBALS['owner'] = 2;
dm_ws_check(array_column(ap_dm_thread_after($peer, 0), 'content') === ['someone else'], 'after-id read stays inside the owner');
$GLOBALS['owner'] = 1;
$GLOBALS['server_blocked'] = [$peer];
dm_ws_check(ap_dm_thread_after($peer, 0) === [], 'a blocked peer returns no rows');
$GLOBALS['server_blocked'] = [];

// ---- local recipients ----
$db->exec("INSERT INTO ap_users (id, actor_id, actor_key, username, disabled_at) VALUES
    (1, 'https://mkultra.monster/users/nova', 'nova', 'nova', NULL),
    (5, 'https://mkultra.monster/users/juno', 'juno', 'juno', NULL),
    (6, 'https://mkultra.monster/users/gone', 'gone', 'gone', '2026-01-01T00:00:00Z')");
$juno = ap_dm_local_recipient('https://mkultra.monster/users/juno/');
dm_ws_check(is_array($juno) && $juno['id'] === 5, 'a VAAK account resolves to its user id');
dm_ws_check(ap_dm_local_recipient('https://remote.test/users/ada') === null, 'a remote actor goes over federation');
dm_ws_check(ap_dm_local_recipient('https://mkultra.monster/users/gone') === false, 'a disabled account cannot receive');
dm_ws_check(ap_dm_local_recipient('https://mkultra.monster/users/nobody') === null, 'an unknown local-looking URL is not treated as local');
dm_ws_check(ap_dm_local_recipient('https://mkultra.monster.evil.test/users/juno') === null, 'a lookalike host is not local');

$GLOBALS['stored'] = [];
$ok = ap_dm_deliver_local($juno, 'https://mkultra.monster/users/nova', [
    'activity_id' => 'https://mkultra.monster/users/nova/creates/a1',
    'object_id' => 'https://mkultra.monster/users/nova/notes/n1',
    'content' => 'hi juno',
    'in_reply_to' => null,
    'media_urls' => [],
    'attachments' => null,
    'created_at' => '2026-11-02T07:00:00+00:00',
]);
$copy = $GLOBALS['stored'][0] ?? [];
dm_ws_check($ok === true, 'local delivery succeeds');
dm_ws_check(($copy['owner_user_id'] ?? 0) === 5, 'the copy belongs to the recipient');
dm_ws_check(($copy['direction'] ?? '') === 'in', 'the recipient copy is incoming');
dm_ws_check(($copy['peer_actor_id'] ?? '') === 'https://mkultra.monster/users/nova', 'the recipient sees the sender as the peer');
dm_ws_check(($copy['object_id'] ?? '') === 'https://mkultra.monster/users/nova/notes/n1', 'both copies share the object id');

$GLOBALS['stored'] = [];
$GLOBALS['personal_blocks'] = ['5|https://mkultra.monster/users/nova' => true];
$ok = ap_dm_deliver_local($juno, 'https://mkultra.monster/users/nova', ['object_id' => 'x', 'content' => 'blocked']);
dm_ws_check($ok === true && $GLOBALS['stored'] === [], 'a recipient who blocked the sender gets nothing, silently');
$GLOBALS['personal_blocks'] = [];

// ---- private mentions from apps (Ice Cubes etc.) ----
dm_ws_check(ap_masto_dm_local_mention_peer('@juno hi there') === 'https://mkultra.monster/users/juno', 'a bare @name private mention reaches the local account');
dm_ws_check(ap_masto_dm_local_mention_peer('hey @JUNO') === 'https://mkultra.monster/users/juno', 'bare mentions match case-insensitively');
dm_ws_check(ap_masto_dm_local_mention_peer('@gone hi') === null, 'a disabled account is not a DM target');
dm_ws_check(ap_masto_dm_local_mention_peer('mail me at a@juno') === null, 'an email-like word is not a mention');
dm_ws_check(ap_masto_dm_local_mention_peer('see https://x.test/@juno') === null, 'a profile URL is not a mention');
dm_ws_check(ap_masto_dm_local_mention_peer('@nobody @juno') === 'https://mkultra.monster/users/juno', 'the first mention that is a real account wins');
$masto = (string) file_get_contents($root . '/api/ap-mastodon.php');
dm_ws_check(str_contains($masto, '$dmPeer = ap_masto_dm_local_mention_peer($status);'), 'direct statuses fall back to bare local mentions');

// ---- people picker ----
$db->exec("INSERT INTO following (owner_actor_id, actor_id) VALUES
    ('https://mkultra.monster/users/nova', 'https://remote.test/users/moth'),
    ('https://mkultra.monster/users/someone', 'https://remote.test/users/hidden'),
    ('https://mkultra.monster/users/nova', 'https://bsky.brid.gy/ap/did:plc:abc')");
$db->exec("INSERT INTO remote_actors (actor_id, username, display_name) VALUES
    ('https://remote.test/users/moth', 'moth', 'Moth Light'),
    ('https://remote.test/users/hidden', 'hidden', 'Not mine')");
$pick = static fn (string $q): array => array_column(admin_dm_people_search($q), 'actor_id');
dm_ws_check($pick('jun') === ['https://mkultra.monster/users/juno'], 'local accounts are found by name');
dm_ws_check(!in_array('https://mkultra.monster/users/gone', $pick('gon'), true), 'disabled accounts are not offered');
dm_ws_check(!in_array('https://mkultra.monster/users/nova', $pick('nova'), true), 'you are not offered to yourself');
dm_ws_check($pick('light') === ['https://remote.test/users/moth'], 'people you follow are found by display name');
dm_ws_check($pick('hidden') === [], "another user's follows are not searched");
dm_ws_check($pick('ada') === [$peer], 'past conversations are found');
dm_ws_check($pick('did:plc') === [], 'Bluesky bridge accounts are left out');
dm_ws_check($pick('%') === [] && $pick('_') === [], 'LIKE wildcards are literal');
$one = admin_dm_people_search('moth')[0] ?? [];
dm_ws_check(($one['handle'] ?? '') === '@moth' && ($one['local'] ?? true) === false, 'results carry handle and local flag');

// ---- chat rows ----
$rows = admin_dm_message_rows_html([
    ['id' => 10, 'direction' => 'in', 'peer_actor_id' => $peer, 'content' => 'hello', 'object_id' => $peer . '/dm/10', 'created_at' => '2026-11-02T06:00:00+00:00'],
    ['id' => 11, 'direction' => 'out', 'peer_actor_id' => $peer, 'content' => 'hi <b>', 'object_id' => 'https://mkultra.monster/users/nova/notes/11', 'created_at' => '2026-11-02T06:01:00+00:00'],
], [0 => '<div class="ctx-stub">ctx</div>']);
dm_ws_check(substr_count($rows, 'class="dm-msg ') === 2, 'one row per message');
dm_ws_check(str_contains($rows, 'dm-msg--in" data-dm-id="10" data-dm-dir="in"'), 'incoming row carries its id and direction');
dm_ws_check(str_contains($rows, 'data-dm-ts="2026-11-02T06:00:00+00:00"'), 'rows carry a UTC timestamp for local formatting');
dm_ws_check(substr_count($rows, 'class="dm-msg-avatar"') === 1, 'only incoming rows get an avatar');
dm_ws_check(strpos($rows, 'ctx-stub') < strpos($rows, 'dm-bubble dm-in'), 'reply context sits above the bubble');
dm_ws_check(str_contains($rows, 'hi &lt;b&gt;'), 'message text stays escaped by the bubble renderer');

// ---- wiring ----
$admin = (string) file_get_contents($root . '/api/ap-admin.php');
$inbox = (string) file_get_contents($root . '/api/ap-inbox.php');
$send = substr($inbox, (int) strpos($inbox, 'function ap_dm_send('));
dm_ws_check(strpos($send, 'ap_dm_local_recipient($peer)') < strpos($send, 'ap_fetch_actor_doc($peer)'), 'local recipients are found before any actor fetch');
dm_ws_check(str_contains($send, 'ap_dm_deliver_local($localPeerUser'), 'local recipients get a direct copy');
dm_ws_check(str_contains($admin, "in_array((string) \$_GET['ajax'], ['dm_thread', 'dm_list', 'dm_people'], true)"), 'DM ajax routes exist');
dm_ws_check(str_contains($admin, "(\$_SERVER['HTTP_X_VAAK_DM'] ?? '') === '1'"), 'fetch sends get a JSON answer');
$start = strpos($admin, "<?php elseif (\$view === 'dms'): ?>");
$end = strpos($admin, "<?php elseif (\$view === 'report'): ?>", (int) $start);
$view = substr($admin, (int) $start, (int) $end - (int) $start);
dm_ws_check(str_contains($view, "document.visibilityState !== 'visible') return;"), 'the open thread only polls while the tab is visible');
dm_ws_check(str_contains($view, "window.addEventListener('vaak:dm-activity'"), 'the thread listens for the badge poll nudge');
dm_ws_check(str_contains($admin, "window.dispatchEvent(new CustomEvent('vaak:dm-activity'))"), 'the badge poll nudges the DM screen');
dm_ws_check(str_contains($view, 'data-dm-form="new"') && str_contains($view, 'data-dm-form="reply"'), 'both composers are wired');
dm_ws_check(str_contains($view, "form.addEventListener('reset'"), 'a sent message clears the kept attachments');

if ($failures !== []) {
    fwrite(STDERR, 'dm-workspace-smoke: ' . count($failures) . " of {$checks} failed\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}
echo "dm-workspace-smoke: {$checks} checks ok\n";
