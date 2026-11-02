<?php
declare(strict_types=1);

/**
 * Bluesky DMs (api/ap-bsky-chat.php + DM screen rows). SQLite only; Bluesky
 * is a fake transport ($GLOBALS['ap_bsky_chat_transport']), so no network.
 */
require __DIR__ . '/migration-reference-functions.php';

$checks = 0;
$failures = [];
function bdm_check(bool $ok, string $label): void
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

function ap_bsky_session_row(int $ownerUserId): ?array
{
    return $GLOBALS['sessions'][$ownerUserId] ?? null;
}

function ap_bsky_remote_media_url_allowed(string $url): bool
{
    return str_starts_with($url, 'https://cdn.bsky.app/');
}

function relative_time(mixed $iso): string
{
    return 'ago';
}

function admin_mascot_empty(string $text): string
{
    return '<div class="empty">' . h($text) . '</div>';
}

/** Fake Bluesky: answers by NSID from $GLOBALS['bsky_answers'] and records calls. */
$GLOBALS['ap_bsky_chat_transport'] = static function (int $owner, string $nsid, string $method, ?array $query, ?array $body): array {
    $GLOBALS['calls'][] = ['nsid' => $nsid, 'query' => $query, 'body' => $body];
    $answer = $GLOBALS['bsky_answers'][$nsid] ?? ['ok' => false, 'error' => 'unexpected ' . $nsid, 'status' => 500];
    if (is_callable($answer)) {
        return $answer($query, $body);
    }
    if (is_array($answer) && array_is_list($answer) && $answer !== [] && is_array($answer[0]) && isset($answer[0]['ok'])) {
        // A list of answers is used in order.
        $next = array_shift($GLOBALS['bsky_answers'][$nsid]);
        return $next;
    }
    return $answer;
};
function bdm_calls(string $nsid): int
{
    return count(array_filter($GLOBALS['calls'] ?? [], static fn (array $c): bool => $c['nsid'] === $nsid));
}

putenv('VAAK_BSKY_DMS=1');
$root = dirname(__DIR__, 2);
require $root . '/api/ap-bsky-chat.php';
reference_load($root . '/api/ap-admin.php', [
    'admin_dm_conversation_list_html',
    'admin_bsky_dm_avatar_html',
    'admin_bsky_dm_peer_name',
    'admin_bsky_dm_conversation_row',
    'admin_bsky_dm_message_rows_html',
    'admin_bsky_dm_last_rev',
    'admin_dm_unread_tip_join',
]);

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['db'] = $db;

$me = 'did:plc:mine';
$ada = 'did:plc:ada';
$GLOBALS['sessions'] = [1 => ['did' => $me, 'pds_host' => 'https://pds.test'], 2 => null];
$GLOBALS['ap_bsky_chat_now'] = 1_800_000_000;

// ---- switch and readiness ----
bdm_check(ap_bsky_chat_ready_for(1), 'ready with the switch on, tables and a session');
bdm_check(!ap_bsky_chat_ready_for(2), 'not ready without a Bluesky session');
putenv('VAAK_BSKY_DMS');
bdm_check(!ap_bsky_chat_ready_for(1), 'off unless VAAK_BSKY_DMS=1');
bdm_check(ap_bsky_chat_unread_count(1) === 0 && ap_bsky_chat_conversations(1) === [], 'off means no counts and no list');
putenv('VAAK_BSKY_DMS=1');
putenv('VAAK_BSKY_DMS_BADGE=0');
bdm_check(!ap_bsky_chat_badge_sync_enabled(), 'VAAK_BSKY_DMS_BADGE=0 stops badge refreshes');
putenv('VAAK_BSKY_DMS_BADGE');
bdm_check(ap_bsky_chat_badge_sync_enabled(), 'badge refresh is on by default with the switch');

// ---- routing what was typed in "To" ----
foreach (['bsky:did:plc:ada', 'did:plc:ada', '@ada.bsky.social', 'ada.bsky.social', 'https://bsky.app/profile/ada.bsky.social'] as $to) {
    bdm_check(ap_bsky_chat_recipient_is_bluesky($to), 'goes to Bluesky: ' . $to);
}
foreach (['@juno', 'juno', '@moth@remote.test', 'https://remote.test/users/moth', ''] as $to) {
    bdm_check(!ap_bsky_chat_recipient_is_bluesky($to), 'stays on the fediverse: ' . ($to === '' ? '(empty)' : $to));
}

// ---- recipients ----
$GLOBALS['bsky_answers']['com.atproto.identity.resolveHandle'] = static fn (?array $q): array => ($q['handle'] ?? '') === 'ada.bsky.social'
    ? ['ok' => true, 'json' => ['did' => $ada]]
    : ['ok' => false, 'error' => 'Unable to resolve handle', 'status' => 400];
bdm_check(ap_bsky_chat_resolve_recipient(1, '@Ada.bsky.social') === $ada, 'a handle resolves to its DID');
bdm_check(ap_bsky_chat_resolve_recipient(1, 'https://bsky.app/profile/ada.bsky.social') === $ada, 'a bsky.app profile link resolves');
bdm_check(ap_bsky_chat_resolve_recipient(1, 'bsky:' . $ada) === $ada, 'a picked DID is used as is');
bdm_check(ap_bsky_chat_resolve_recipient(1, 'nobody.bsky.social') === null, 'an unknown handle is not found');
bdm_check(ap_bsky_chat_resolve_recipient(1, 'not a handle') === null, 'junk is rejected before any lookup');

// ---- first sync: conversation list ----
$adaMember = ['did' => $ada, 'handle' => 'ada.bsky.social', 'displayName' => 'Ada', 'avatar' => 'https://cdn.bsky.app/img/avatar/ada.jpg'];
$meMember = ['did' => $me, 'handle' => 'me.test'];
$convoA = [
    'id' => 'c-ada', 'rev' => '3k2', 'members' => [$meMember, $adaMember], 'muted' => false, 'status' => 'accepted', 'unreadCount' => 1,
    'lastMessage' => ['$type' => 'chat.bsky.convo.defs#messageView', 'id' => 'm1', 'rev' => '3k2', 'text' => 'hello there', 'sender' => ['did' => $ada], 'sentAt' => '2026-11-02T10:00:00.000Z'],
];
$convoEvil = [
    'id' => 'c-evil', 'rev' => '3k1', 'status' => 'request', 'unreadCount' => 2, 'muted' => true,
    'members' => [$meMember, ['did' => 'did:plc:evil', 'handle' => 'evil.test', 'displayName' => '<b>x</b>', 'avatar' => 'http://169.254.169.254/x']],
    'lastMessage' => ['id' => 'e1', 'rev' => '3k1', 'text' => 'spam', 'sender' => ['did' => 'did:plc:evil'], 'sentAt' => '2026-11-01T10:00:00.000Z'],
];
$convoGroup = ['id' => 'c-group', 'rev' => '3k9', 'kind' => ['$type' => 'chat.bsky.convo.defs#groupConvo'], 'members' => [$meMember, $adaMember]];
$GLOBALS['bsky_answers']['chat.bsky.convo.listConvos'] = ['ok' => true, 'json' => ['convos' => [$convoA, $convoEvil, $convoGroup]]];
$GLOBALS['bsky_answers']['chat.bsky.convo.getLog'] = ['ok' => true, 'json' => ['logs' => []]];
$GLOBALS['calls'] = [];
$sync = ap_bsky_chat_sync(1);
bdm_check(!empty($sync['ok']) && empty($sync['skipped']), 'first sync runs');
bdm_check(bdm_calls('chat.bsky.convo.listConvos') === 1, 'first sync lists conversations once');
$state = ap_bsky_chat_state(1);
bdm_check($state['log_cursor'] === '3k9', 'the log cursor starts at the newest rev seen');
bdm_check($state['did'] === $me, 'the connected DID is remembered');
$list = ap_bsky_chat_conversations(1);
bdm_check(array_column($list, 'convo_id') === ['c-ada', 'c-evil'], 'group chats are skipped, newest first');
bdm_check($list[0]['peer_handle'] === 'ada.bsky.social' && $list[0]['peer_avatar'] !== '', 'the other member is the peer, with avatar');
bdm_check($list[1]['peer_avatar'] === '', 'an avatar outside Bluesky\'s CDN is dropped');
bdm_check($list[1]['status'] === 'request', 'message requests are marked');
bdm_check(ap_bsky_chat_unread_count(1) === 1, 'unread counts skip muted conversations');
bdm_check(ap_bsky_chat_unread_tip(1) === '3k2', 'the unread tip is the newest unread rev');

// ---- throttle ----
$GLOBALS['calls'] = [];
$again = ap_bsky_chat_sync(1);
bdm_check(!empty($again['skipped']) && $GLOBALS['calls'] === [], 'a second refresh within the minimum gap makes no calls');
$GLOBALS['ap_bsky_chat_now'] += 60;
bdm_check(!empty(ap_bsky_chat_sync(1, AP_BSKY_CHAT_BADGE_SYNC_SEC)['skipped']), 'the badge gap is longer than the DM screen gap');
$GLOBALS['ap_bsky_chat_now'] += 1;

// ---- log: new, deleted, left ----
$GLOBALS['bsky_answers']['chat.bsky.convo.getLog'] = [
    ['ok' => true, 'json' => ['cursor' => '3m3', 'logs' => [
        ['$type' => 'chat.bsky.convo.defs#logCreateMessage', 'rev' => '3m1', 'convoId' => 'c-ada',
            'message' => ['$type' => 'chat.bsky.convo.defs#messageView', 'id' => 'm2', 'rev' => '3m1', 'text' => 'see https://example.com', 'sender' => ['did' => $ada], 'sentAt' => '2026-11-02T10:05:00.000Z']],
        ['$type' => 'chat.bsky.convo.defs#logDeleteMessage', 'rev' => '3m2', 'convoId' => 'c-ada',
            'message' => ['$type' => 'chat.bsky.convo.defs#deletedMessageView', 'id' => 'm1', 'rev' => '3m2', 'sender' => ['did' => $ada], 'sentAt' => '2026-11-02T10:00:00.000Z']],
        ['$type' => 'chat.bsky.convo.defs#logLeaveConvo', 'rev' => '3m3', 'convoId' => 'c-evil'],
    ]]],
    ['ok' => true, 'json' => ['logs' => []]],
];
$GLOBALS['bsky_answers']['chat.bsky.convo.getConvo'] = static fn (?array $q): array => ['ok' => true, 'json' => ['convo' => array_merge($convoA, [
    'rev' => '3m2', 'unreadCount' => 2,
    'lastMessage' => ['$type' => 'chat.bsky.convo.defs#messageView', 'id' => 'm2', 'rev' => '3m1', 'text' => 'see https://example.com', 'sender' => ['did' => $ada], 'sentAt' => '2026-11-02T10:05:00.000Z'],
])]];
$GLOBALS['calls'] = [];
$sync = ap_bsky_chat_sync(1);
bdm_check(!empty($sync['ok']), 'log sync runs');
bdm_check(bdm_calls('chat.bsky.convo.getConvo') === 1, 'one getConvo per touched conversation, none for one that was left');
bdm_check(ap_bsky_chat_state(1)['log_cursor'] === '3m3', 'the cursor advances to the newest log rev');
bdm_check(array_column(ap_bsky_chat_conversations(1), 'convo_id') === ['c-ada'], 'a left conversation is forgotten');
$convoRow = ap_bsky_chat_convo(1, 'c-ada');
bdm_check((int) $convoRow['unread_count'] === 2 && $convoRow['last_text'] === 'see https://example.com', 'the refreshed conversation has new unread and preview');

// ---- history and reads ----
$GLOBALS['bsky_answers']['chat.bsky.convo.getMessages'] = ['ok' => true, 'json' => ['messages' => [
    ['$type' => 'chat.bsky.convo.defs#messageView', 'id' => 'm0', 'rev' => '3j9', 'text' => 'first', 'sender' => ['did' => $me], 'sentAt' => '2026-11-01T09:00:00.000Z'],
]]];
$GLOBALS['calls'] = [];
$rows = ap_bsky_chat_messages(1, 'c-ada');
bdm_check(array_column($rows, 'message_id') === ['m0', 'm1', 'm2'], 'history plus logged messages, oldest first');
bdm_check((int) $rows[1]['deleted'] === 1 && $rows[1]['text'] === '', 'a deleted message keeps no text');
ap_bsky_chat_messages(1, 'c-ada');
bdm_check(bdm_calls('chat.bsky.convo.getMessages') === 1, 'history is fetched from Bluesky once per conversation');
bdm_check(array_column(ap_bsky_chat_messages(1, 'c-ada', 50, '3m1'), 'message_id') === ['m1'], 'after-rev reads return only newer rows');
bdm_check(ap_bsky_chat_messages(1, 'c-unknown') === [], 'an unknown conversation has no rows');
$GLOBALS['bsky_answers']['chat.bsky.convo.updateRead'] = static fn (?array $q, ?array $b): array => ['ok' => true, 'json' => ['convo' => array_merge($convoA, ['rev' => '3m2', 'unreadCount' => 0])]];
$GLOBALS['calls'] = [];
ap_bsky_chat_mark_read(1, 'c-ada');
bdm_check(bdm_calls('chat.bsky.convo.updateRead') === 1 && (int) ap_bsky_chat_convo(1, 'c-ada')['unread_count'] === 0, 'opening an unread conversation marks it read on Bluesky');
ap_bsky_chat_mark_read(1, 'c-ada');
bdm_check(bdm_calls('chat.bsky.convo.updateRead') === 1, 'a read conversation makes no updateRead call');

// ---- send ----
bdm_check(ap_bsky_chat_send(1, 'c-ada', '', '   ')['ok'] === false, 'an empty message is refused');
bdm_check(str_contains((string) ap_bsky_chat_send(1, 'c-ada', '', str_repeat('a', 1001))['error'], '1000'), 'more than 1000 characters is refused');
bdm_check(ap_bsky_chat_send(1, 'c-other', '', 'hi')['ok'] === false, 'an unknown conversation id is refused');
$GLOBALS['bsky_answers']['chat.bsky.convo.sendMessage'] = static fn (?array $q, ?array $b): array => ['ok' => true, 'json' => [
    'id' => 'm9', 'rev' => '3n1', 'text' => (string) ($b['message']['text'] ?? ''), 'sender' => ['did' => $GLOBALS['me_did']], 'sentAt' => '2026-11-02T11:00:00.000Z',
]];
$GLOBALS['me_did'] = $me;
$sent = ap_bsky_chat_send(1, 'c-ada', '', 'hi ada');
bdm_check(!empty($sent['ok']) && $sent['message_id'] === 'm9', 'a reply is sent to the open conversation');
bdm_check(ap_bsky_chat_convo(1, 'c-ada')['last_text'] === 'hi ada', 'the conversation preview shows the sent message');
bdm_check(end($GLOBALS['calls'])['body']['convoId'] === 'c-ada', 'sendMessage targets the conversation');
$convoNew = ['id' => 'c-new', 'rev' => '3n2', 'members' => [$meMember, $adaMember], 'status' => 'accepted', 'unreadCount' => 0];
$GLOBALS['bsky_answers']['chat.bsky.convo.getConvoForMembers'] = static fn (?array $q): array => ($q['members'] ?? '') === $GLOBALS['ada_did']
    ? ['ok' => true, 'json' => ['convo' => $GLOBALS['convo_new']]]
    : ['ok' => false, 'error' => 'recipient has disabled incoming messages', 'status' => 400];
$GLOBALS['ada_did'] = $ada;
$GLOBALS['convo_new'] = $convoNew;
$sent = ap_bsky_chat_send(1, '', '@ada.bsky.social', 'new chat');
bdm_check(!empty($sent['ok']) && $sent['convo_id'] === 'c-new', 'a new message by handle finds or starts the conversation');
$refused = ap_bsky_chat_send(1, '', 'did:plc:closed', 'hi');
bdm_check(str_contains((string) $refused['error'], 'isn’t accepting'), 'a recipient who blocks DMs gets a plain-language error');
bdm_check(str_contains((string) ap_bsky_chat_send(1, '', $me, 'hi')['error'], 'yourself'), 'you cannot message yourself');

// ---- token without the DM scope ----
$GLOBALS['ap_bsky_chat_now'] += 60;
$GLOBALS['bsky_answers']['chat.bsky.convo.getLog'] = ['ok' => false, 'status' => 400, 'error' => 'Bad token scope', 'json' => ['error' => 'InvalidToken', 'message' => 'Bad token scope'], 'scope' => true];
$sync = ap_bsky_chat_sync(1);
bdm_check(empty($sync['ok']) && !empty($sync['scope']), 'a token without DM access is reported as a scope problem');
bdm_check(!ap_bsky_chat_scope_ok(1), 'the DM screen can show the reconnect note');
bdm_check((int) ap_bsky_chat_state(1)['next_sync_at'] >= $GLOBALS['ap_bsky_chat_now'] + 600, 'a scope failure backs off 10 minutes');
bdm_check(str_contains(ap_bsky_chat_send_error(['scope' => true]), 'app password'), 'the scope error explains the app password box');
bdm_check(ap_bsky_chat_error_is_scope(['error' => 'Bad token scope']), 'scope errors are recognised from the message');
bdm_check(!ap_bsky_chat_error_is_scope(['error' => 'timeout']), 'other errors are not scope errors');


// ---- text and embeds ----
$text = 'hi @ada.bsky.social see https://example.com <b>';
$aStart = strpos($text, '@ada');
$uStart = strpos($text, 'https://');
$html = ap_bsky_chat_text_html($text, [
    ['index' => ['byteStart' => $aStart, 'byteEnd' => $aStart + 16], 'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => $ada]]],
    ['index' => ['byteStart' => $uStart, 'byteEnd' => $uStart + 19], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com']]],
]);
bdm_check(str_contains($html, 'href="https://bsky.app/profile/did%3Aplc%3Aada"'), 'mentions link to the Bluesky profile');
bdm_check(str_contains($html, 'href="https://example.com"'), 'link facets become links');
bdm_check(str_contains($html, '&lt;b&gt;') && !str_contains($html, '<b>'), 'message text is escaped');
$bad = ap_bsky_chat_text_html('click me', [['index' => ['byteStart' => 0, 'byteEnd' => 5], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'javascript:alert(1)']]]]);
bdm_check(!str_contains($bad, 'javascript:'), 'a javascript: link facet is never linked');
bdm_check(str_contains(ap_bsky_chat_text_html('go https://x.test/a now'), 'href="https://x.test/a"'), 'bare URLs are linked without facets');
bdm_check(ap_bsky_chat_embed_url('{"$type":"app.bsky.embed.record#view","record":{"uri":"at://did:plc:ada/app.bsky.feed.post/3abc"}}') === 'https://bsky.app/profile/did%3Aplc%3Aada/post/3abc', 'a shared post links to bsky.app');
bdm_check(ap_bsky_chat_embed_url('{"record":{"uri":"https://evil.test"}}') === '', 'odd embeds are ignored');

// ---- people picker ----
$people = ap_bsky_chat_people_search(1, 'ada');
bdm_check(($people[0]['actor_id'] ?? '') === 'bsky:' . $ada && $people[0]['network'] === 'bluesky', 'past Bluesky conversations are offered by name');
$typed = ap_bsky_chat_people_search(1, '@someone.bsky.social');
bdm_check(($typed[0]['actor_id'] ?? '') === 'bsky:someone.bsky.social', 'a typed handle is offered as a Bluesky option');
bdm_check(ap_bsky_chat_people_search(1, 'moth@remote.test') === [], 'a fediverse address is not offered as Bluesky');

// ---- DM screen rows ----
$convoRow = ap_bsky_chat_convo(1, 'c-ada');
$rowsHtml = admin_bsky_dm_message_rows_html(ap_bsky_chat_messages(1, 'c-ada'), $me, $convoRow);
bdm_check(substr_count($rowsHtml, 'class="dm-msg ') === 4, 'one chat row per message');
bdm_check(str_contains($rowsHtml, 'dm-msg dm-msg--out" data-dm-id="m0"'), 'your own messages are outgoing');
bdm_check(str_contains($rowsHtml, 'data-dm-id="m2" data-dm-rev="3m1" data-dm-dir="in"'), 'incoming rows carry id, rev and direction');
bdm_check(str_contains($rowsHtml, 'Message deleted'), 'deleted messages say so');
bdm_check(admin_bsky_dm_last_rev(ap_bsky_chat_messages(1, 'c-ada')) === '3n1', 'the live cursor is the newest rev');
$rail = admin_dm_conversation_list_html([], '', ap_bsky_chat_conversations(1), 'c-ada', $me);
bdm_check(str_contains($rail, 'href="?view=dms&amp;bsky=c-ada"') && str_contains($rail, 'aria-current="page"'), 'Bluesky conversations link to their thread and show the open one');
bdm_check(str_contains($rail, 'dm-net-chip--bsky'), 'Bluesky rows carry the Bluesky chip');
$fedi = [['peer_actor_id' => 'https://remote.test/users/moth', 'unread' => 0, 'last' => ['content' => 'old', 'direction' => 'in', 'created_at' => '2026-10-01T00:00:00Z']]];
function actor_handle(?string $a): string { return '@moth'; }
function actor_display_name(?string $a): string { return 'Moth'; }
function actor_display_name_html(?string $a): string { return 'Moth'; }
function admin_avatar_img(?string $a): string { return '<img>'; }
function admin_html_to_plain(string $s): string { return $s; }
$mixed = admin_dm_conversation_list_html($fedi, '', ap_bsky_chat_conversations(1), '', $me);
bdm_check(strpos($mixed, 'bsky=c-ada') < strpos($mixed, 'remote.test'), 'fediverse and Bluesky conversations interleave by time');
bdm_check(admin_dm_unread_tip_join('12', '') === '12' && admin_dm_unread_tip_join('12', '3m') === '12:b3m', 'the unread tip changes when either network has news');

// ---- a different Bluesky account starts over ----
$GLOBALS['ap_bsky_chat_now'] += 700;
$GLOBALS['sessions'][1]['did'] = 'did:plc:other';
$GLOBALS['bsky_answers']['chat.bsky.convo.listConvos'] = ['ok' => true, 'json' => ['convos' => []]];
$GLOBALS['bsky_answers']['chat.bsky.convo.getLog'] = ['ok' => true, 'json' => ['logs' => []]];
$sync = ap_bsky_chat_sync(1);
bdm_check(!empty($sync['ok']) && ap_bsky_chat_conversations(1) === [], 'a newly connected account does not see the old account\'s DMs');
bdm_check(ap_bsky_chat_scope_ok(1), 'a good refresh clears the scope note');
$GLOBALS['sessions'][1]['did'] = $me;

// ---- wiring ----
$admin = (string) file_get_contents($root . '/api/ap-admin.php');
$bsky = (string) file_get_contents($root . '/api/ap-bsky.php');
bdm_check(str_contains($bsky, 'array $extraHeaders = []') && str_contains($bsky, "preg_match('/[\\r\\n]/', \$extra)"), 'extra XRPC headers refuse line breaks');
bdm_check(str_contains($admin, "'bsky_dm_thread'], true)"), 'the DM screen has a Bluesky thread endpoint');
bdm_check(str_contains($admin, 'fastcgi_finish_request();') && str_contains($admin, 'ap_bsky_chat_sync($ownerForBadge, AP_BSKY_CHAT_BADGE_SYNC_SEC)'), 'the badge poll refreshes Bluesky after answering');
bdm_check(str_contains($admin, "\$action === 'bsky_dm_send'"), 'Bluesky messages have their own send action');
$migration = (string) file_get_contents($root . '/api/migrations/20261102_bsky_chat_postgres.sql');
bdm_check(substr_count($migration, 'CREATE TABLE IF NOT EXISTS bsky_chat_') === 3 && substr_count($migration, 'TO "www-data"') === 3, 'the migration creates and grants the three tables');
bdm_check(str_contains((string) file_get_contents($root . '/deploy/remote/migrate.php'), "'20261102_bsky_chat_postgres.sql'"), 'deploy applies the migration');
$worker = (string) file_get_contents($root . '/rust/vaak-worker/src/notif_stream.rs');
bdm_check(str_contains($worker, "to_regclass('public.bsky_chat_convos')"), 'the nudge stream notices Bluesky unread changes');

if ($failures !== []) {
    fwrite(STDERR, "bsky-dm: FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "bsky-dm: PASS ({$checks} checks)\n";
