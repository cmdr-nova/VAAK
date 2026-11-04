<?php
/**
 * Nostr link: npub/nprofile/hex parsing (NIP-19 test vectors), nsec refusal,
 * the Settings rollout gate, status line, and the SQLite save/replace/unlink
 * path. No network and no production database.
 */
declare(strict_types=1);
require_once __DIR__ . '/admin-shell-source.php';

require_once dirname(__DIR__) . '/ap-nostr.php';
require_once dirname(__DIR__) . '/ap-timeline-older.php';

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $why) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $why;
    }
};

// NIP-19 "Examples" section.
$hex = '7e7e9c42a91bfef19fa929e5fda1b72e0ebc1a4c1141673e2794234d86addf4e';
$npub = 'npub10elfcs4fr0l0r8af98jlmgdh9c8tcxjvz9qkw038js35mp4dma8qzvjptg';
$check(ap_nostr_npub($hex) === $npub, 'hex encodes to the NIP-19 npub');
$check((ap_nostr_parse_pubkey($npub)['pubkey'] ?? '') === $hex, 'npub decodes to the NIP-19 hex');
$check((ap_nostr_parse_pubkey('  nostr:' . $npub . ' ')['pubkey'] ?? '') === $hex, 'nostr: prefix and spaces are accepted');
$check((ap_nostr_parse_pubkey(strtoupper($npub))['pubkey'] ?? '') === $hex, 'all-uppercase bech32 is valid');
$check((ap_nostr_parse_pubkey(strtoupper($hex))['pubkey'] ?? '') === $hex, 'hex is lowercased');
$check(ap_nostr_npub('3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d') === 'npub180cvv07tjdrrgpa0j7j7tmnyl2yr6yr7l8j4s3evf6u64th6gkwsyjh6w6', 'second NIP-19 npub example');
$nprofile = 'nprofile1qqsrhuxx8l9ex335q7he0f09aej04zpazpl0ne2cgukyawd24mayt8gpp4mhxue69uhhytnc9e3k7mgpz4mhxue69uhkg6nzv9ejuumpv34kytnrdaksjlyr9p';
$check((ap_nostr_parse_pubkey($nprofile)['pubkey'] ?? '') === '3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d', 'nprofile yields its pubkey');

$nsec = ap_nostr_parse_pubkey('nsec1vl029mgpspedva04g90vltkh6fvh240zqtv9k0t9af8935ke9laqsnlfe5');
$check(empty($nsec['ok']) && str_contains((string) ($nsec['error'] ?? ''), 'private key'), 'an nsec is refused with a warning');
$check(empty(ap_nostr_parse_pubkey(substr($npub, 0, -1) . 'q')['ok']), 'a bad checksum is refused');
$check(empty(ap_nostr_parse_pubkey('npub1' . substr($npub, 5, 10) . strtoupper(substr($npub, 15)))['ok']), 'mixed case is refused');
$check(empty(ap_nostr_parse_pubkey('note1' . substr($npub, 5))['ok']), 'other bech32 kinds are refused');
$check(empty(ap_nostr_parse_pubkey('@valerie@mkultra.social')['ok']), 'a fediverse handle is refused');
$check((ap_nostr_parse_pubkey('')['pubkey'] ?? null) === '', 'empty input means unlink');

putenv('VAAK_NOSTR');
$check(ap_nostr_form_visible(true) && !ap_nostr_form_visible(false), 'unset flag: admins only');
putenv('VAAK_NOSTR=1');
$check(ap_nostr_form_visible(false), 'VAAK_NOSTR=1 opens the form to everyone');
putenv('VAAK_NOSTR=0');
$check(!ap_nostr_form_visible(true), 'VAAK_NOSTR=0 hides it from admins too');
putenv('VAAK_NOSTR');

$now = 1_800_000_000;
$check(ap_nostr_status_line(null, $now) === '', 'no link, no status');
$check(str_contains(ap_nostr_status_line(['pubkey' => $hex, 'synced_at' => 0], $now), 'first sync'), 'unsynced link says it is waiting');
$line = ap_nostr_status_line(['pubkey' => $hex, 'synced_at' => $now - 300, 'follow_count' => 42, 'last_error' => ''], $now);
$check($line === 'Following 42 on Nostr. Last sync 5 min ago.', 'synced status line: ' . $line);

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
unset($GLOBALS['ap_nostr_table_ready']);
$check(ap_nostr_table_ready($db), 'sqlite tables are created on demand');
$saved = ap_nostr_save_link(7, $npub, true, $db);
$check(!empty($saved['ok']) && ($saved['pubkey'] ?? '') === $hex, 'save stores the hex key');
$row = ap_nostr_link_for_user(7, $db);
$check(is_array($row) && $row['pubkey'] === $hex && (int) $row['show_in_home'] === 1, 'link row is readable');
$db->exec("INSERT INTO nostr_follows (owner_user_id, pubkey) VALUES (7, 'aa'), (8, 'bb')");
$db->exec('UPDATE nostr_links SET synced_at = 5, follow_count = 1 WHERE owner_user_id = 7');
ap_nostr_save_link(7, $npub, false, $db);
$row = ap_nostr_link_for_user(7, $db);
$check((int) $row['show_in_home'] === 0 && (int) $row['synced_at'] === 5, 'same key only toggles Home and keeps sync state');
$check((int) $db->query('SELECT COUNT(*) FROM nostr_follows WHERE owner_user_id = 7')->fetchColumn() === 1, 'same key keeps follows');
ap_nostr_save_link(7, '3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d', true, $db);
$row = ap_nostr_link_for_user(7, $db);
$check((int) $row['synced_at'] === 0 && (int) $db->query('SELECT COUNT(*) FROM nostr_follows WHERE owner_user_id = 7')->fetchColumn() === 0, 'a new key resets sync state and drops old follows');
$check(empty(ap_nostr_save_link(7, 'nsec1vl029mgpspedva04g90vltkh6fvh240zqtv9k0t9af8935ke9laqsnlfe5', true, $db)['ok']), 'save refuses an nsec');
$check(ap_nostr_link_for_user(7, $db)['pubkey'] === '3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d', 'a refused save leaves the link alone');
ap_nostr_save_link(7, '', true, $db);
$check(ap_nostr_link_for_user(7, $db) === null, 'empty input unlinks');
$check((int) $db->query('SELECT COUNT(*) FROM nostr_follows')->fetchColumn() === 1, 'unlink drops only that user\'s follows');
$check(empty(ap_nostr_save_link(0, $npub, true, $db)['ok']), 'no user id, no save');

// Older-page cursors: a page ending on a Nostr row keeps its place in the
// warm ranked cache instead of falling back to a timestamp wildcard.
$ranked = [
    ['k' => 'nostr', 'id' => 'aa11', 's' => 'nostr', 'sort' => 1793160000],
    ['k' => 'nostr', 'id' => 'bb22', 's' => 'nostr', 'sort' => 1793150000],
];
$token = admin_tl_cursor_token_from_ranked($ranked, 1);
$cursor = vaak_tl_cursor_unpack($token);
$check(is_array($cursor) && $cursor['source'] === 'nostr' && $cursor['id'] === 'bb22', 'nostr ranked row packs a nostr cursor');
$check(is_array($cursor) && admin_tl_ranked_index_of_cursor($ranked, $cursor) === 1, 'nostr cursor finds its ranked index');
$check(admin_tl_cursor_token_from_ranked([['k' => 'nostr', 'id' => 'cc33', 'sort' => 0]], 0) === '', 'nostr row without sort gives no cursor');

// Home card actions: the PHP side only validates and forwards; anything
// malformed is refused before the worker is asked.
$hex = str_repeat('ab', 32);
putenv('VAAK_NOSTR=0');
$check(ap_nostr_action('nostr_prepare', 1, true, ['purpose' => 'like', 'target' => $hex])['status'] === 403, 'kill switch refuses actions');
putenv('VAAK_NOSTR');
$check(ap_nostr_action('nostr_prepare', 1, false, ['purpose' => 'like', 'target' => $hex])['status'] === 403, 'admin-only rollout refuses other users');
$check(ap_nostr_action('nostr_prepare', 0, true, ['purpose' => 'like', 'target' => $hex])['status'] === 401, 'no user, no action');
$check(ap_nostr_action('nostr_prepare', 1, true, ['purpose' => 'boost_everything', 'target' => $hex])['status'] === 400, 'unknown purpose refused');
$check(ap_nostr_action('nostr_prepare', 1, true, ['purpose' => 'like', 'target' => 'note1abc'])['status'] === 400, 'non-hex target refused');
$check(ap_nostr_action('nostr_zap_status', 1, true, ['watch' => 'nope'])['status'] === 400, 'zap status needs a watch token');
foreach (['repost', 'quote', 'delete'] as $purpose) {
    $res = ap_nostr_action('nostr_prepare', 1, true, ['purpose' => $purpose, 'target' => $hex]);
    $check(($res['body']['error'] ?? '') !== 'Unknown Nostr action.', "{$purpose} passes PHP checks and goes to the worker");
    $check(ap_nostr_action('nostr_prepare', 1, true, ['purpose' => $purpose, 'target' => 'nope'])['status'] === 400, "{$purpose} still needs a hex target");
}
$check(ap_nostr_action('nostr_prepare', 1, true, ['purpose' => 'unrepost', 'target' => $hex])['status'] === 400, 'unrepost is chosen by the worker, not asked for');
// Zaps (NIP-57): an amount from 1 to 1,000,000 sats and a short comment.
foreach (['', '0', '-5', '21.5', '1000001', '00021', 'lots'] as $badSats) {
    $check(ap_nostr_action('nostr_prepare', 1, true, ['purpose' => 'zap', 'target' => $hex, 'zap_sats' => $badSats])['status'] === 400, "zap amount '{$badSats}' refused");
}
$check(ap_nostr_action('nostr_prepare', 1, true, ['purpose' => 'zap', 'target' => $hex, 'zap_sats' => '21', 'zap_comment' => str_repeat('é', 281)])['status'] === 400, 'a long zap comment is refused');
$zapRes = ap_nostr_action('nostr_prepare', 1, true, ['purpose' => 'zap', 'target' => $hex, 'zap_sats' => '1000000', 'zap_comment' => str_repeat('é', 280)]);
foreach (['nope', 'a@b', 'a b@c.io', str_repeat('a', 320) . '@b.io'] as $badLn) {
    $check(ap_nostr_action('nostr_prepare', 1, true, ['purpose' => 'lightning', 'lightning' => $badLn])['status'] === 400, "lightning address '{$badLn}' refused");
}
$lnRes = ap_nostr_action('nostr_prepare', 1, true, ['purpose' => 'lightning', 'lightning' => ' Nova@Strike.me ']);
$check(($lnRes['body']['error'] ?? '') !== 'Unknown Nostr action.' && $lnRes['status'] !== 400, 'a good Lightning address goes to the worker without a note target');
$check(ap_nostr_lightning_from_pay_url('https://strike.me/.well-known/lnurlp/nova') === 'nova@strike.me' && ap_nostr_lightning_from_pay_url('https://pay.example/lnurl/x') === '', 'stored pay URLs read back as addresses');
$check(ap_nostr_lightning_address('nope') === ['address' => '', 'zaps' => null], 'no key, no address');
$check(!in_array($zapRes['body']['error'] ?? '', ['Unknown Nostr action.', 'Pick an amount from 1 to 1,000,000 sats.', 'Keep the zap comment under 280 characters.'], true), 'a good zap goes to the worker');
$check(ap_nostr_action('nostr_publish', 1, true, ['nonce' => 'xyz', 'event' => '{}'])['status'] === 400, 'bad nonce refused');
$check(ap_nostr_action('nostr_publish', 1, true, ['nonce' => str_repeat('a', 32), 'event' => 'not json'])['status'] === 400, 'non-JSON event refused');
$check(ap_nostr_action('nostr_sign_anything', 1, true, [])['status'] === 400, 'unknown action refused');
// Token apps (Shana's signer): same gate and checks, by status id.
$admin = ['id' => 1, 'is_admin' => true];
$check(ap_nostr_api_action('sign', $admin, [])['status'] === 404, 'api: unknown step refused');
$check(ap_nostr_api_action('prepare', $admin, ['status_id' => 'abc'])['status'] === 400, 'api: non-numeric status id refused');
$check(ap_nostr_api_action('prepare', $admin, ['note_id' => 'http://x/notes/1'])['status'] === 400, 'api: non-https note id refused');
$check(ap_nostr_api_action('publish', $admin, ['nonce' => 'xyz', 'event' => ['id' => 'x']])['status'] === 400, 'api: bad nonce refused');
$check(ap_nostr_api_action('publish', ['id' => 2, 'is_admin' => false], ['nonce' => str_repeat('a', 32), 'event' => []])['status'] === 403, 'api: admin-only rollout refuses other users');

// Favourites > Nostr: gated like the npub form, and a missing worker
// paints a retry note instead of an empty list.
putenv('VAAK_NOSTR=0');
$check(!ap_nostr_favourites_tab_visible(1, true), 'kill switch hides the Nostr favourites tab');
putenv('VAAK_NOSTR');
$check(!ap_nostr_favourites_tab_visible(0, true), 'no user, no Nostr favourites tab');
putenv('VAAK_SHADOW_HTTP=http://127.0.0.1:1');
$frag = ap_nostr_favourites_fragment(1, 0, 500);
$check(str_contains($frag['html'], 'couldn’t load') && !$frag['has_more'] && str_contains($frag['html'], 'data-limit="40"'), 'unreachable worker: retry note, no more pages, limit clamped');
$check(ap_nostr_cards_html([['id' => 'rss:1'], ['id' => 'nostr:' . $hex]], 1, 'bookmarks') === ['', ''], 'cards keep their slots when nothing paints');
putenv('VAAK_SHADOW_HTTP');

// Admin > Relays > Nostr: relay addresses follow the worker's rules.
foreach ([
    'WSS://Relay.Example.com/' => 'wss://relay.example.com',
    'wss://relay.example.com:443/inbox/' => 'wss://relay.example.com/inbox',
    ' wss://nos.lol ' => 'wss://nos.lol',
] as $in => $want) {
    $check(ap_nostr_normalize_relay_url($in) === $want, 'relay url normalizes: ' . $in);
}
foreach (['https://relay.example.com', 'ws://relay.example.com', 'wss://127.0.0.1', 'wss://localhost', 'wss://box.local',
    'wss://relay.example.com:8443', 'wss://relay.example.com/?x=1', 'wss://user@relay.example.com', 'wss://relay.example.com/../x', 'wss://nodot'] as $bad) {
    $check(ap_nostr_normalize_relay_url($bad) === null, 'relay url refused: ' . $bad);
}
$check(!ap_nostr_relay_admin_action('nostr_relay_add', ['relay_url' => 'wss://10.0.0.1'])['ok'], 'admin add refuses a private relay');
$check(ap_nostr_relay_age(0, 100) === 'never' && ap_nostr_relay_age(9400, 10000) === '10 min ago', 'relay ages read naturally');

// Moderation: Hide/Block validate before touching the database; Block is
// admin only; without the Postgres tables nothing is written.
$modDb = new PDO('sqlite::memory:');
$modDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$check(ap_nostr_moderation_action('nostr_hide', 1, true, ['target' => 'zz'], $modDb)['status'] === 400, 'hide refuses a bad key');
$check(ap_nostr_moderation_action('nostr_block', 1, false, ['target' => $hex], $modDb)['status'] === 403, 'block is admin only');
$check(ap_nostr_moderation_action('nostr_hide', 0, true, ['target' => $hex], $modDb)['status'] === 401, 'hide needs a user');
$check(ap_nostr_moderation_action('nostr_hide', 1, true, ['target' => $hex], $modDb)['status'] === 503, 'hide waits for the moderation tables');
$check(ap_nostr_moderation_action('nostr_bogus', 1, true, ['target' => $hex], $modDb)['status'] === 503, 'unknown moderation action is never written');
$check(ap_nostr_moderation_settings_html(1, true, $modDb) === '', 'no moderation tables, no Settings list');
$rep = ap_nostr_action('nostr_prepare', 1, true, ['purpose' => 'report', 'target' => $hex, 'reason' => 'rude']);
$check($rep['status'] === 400 && str_contains((string) $rep['body']['error'], 'reason'), 'report needs a NIP-56 type');
$check(ap_nostr_action('nostr_prepare', 1, true, ['purpose' => 'mutelist', 'target' => $hex])['status'] === 400, 'unknown purpose refused');
$check(ap_nostr_downranked_pubkey('nostr:' . $hex) === $hex && ap_nostr_downranked_pubkey('https://bsky.app/profile/did:plc:x') === '', 'downranked Nostr actors are nostr:{hex}');
$nip19 = '7e7e9c42a91bfef19fa929e5fda1b72e0ebc1a4c1141673e2794234d86addf4e';
$names = ap_nostr_names([$nip19, 'bad'], $modDb);
$check(array_keys($names) === [$nip19] && ($names[$nip19]['name'] ?? '') === 'npub10elfc…zvjptg', 'names fall back to a short npub');

// Notifications (nostr/notif.rs stages rows; PHP types and paints them).
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
reference_load(dirname(__DIR__) . '/ap-masto-entities.php', ['ap_masto_mention_is_nostr', 'ap_masto_nostr_status_fields', 'ap_masto_nostr_note_media', 'ap_masto_mention_zap_sats', 'ap_masto_mention_target_object_id']);
reference_load(dirname(__DIR__) . '/ap-admin.php', ['admin_notification_zap_sats', 'admin_zap_sats_label', 'admin_notification_group_key']);
$noteHex = str_repeat('ab', 32);
$noteUrl = 'https://njump.me/' . ap_nostr_bech32_encode('note', (string) hex2bin($noteHex));
$actorUrl = 'https://njump.me/' . $npub;
$check(ap_masto_mention_is_nostr(['activity_id' => 'nostr:' . $noteHex]) && ap_masto_mention_is_nostr(['actor_id' => $actorUrl]), 'staged Nostr rows are recognised');
$check(!ap_masto_mention_is_nostr(['activity_id' => 'at://did:plc:x/app.bsky.feed.post/1', 'actor_id' => 'https://bsky.app/profile/x']), 'Bluesky rows are not Nostr');
$fields = ap_masto_nostr_status_fields(['id' => '9'], $noteUrl, $actorUrl);
$check($fields['source'] === 'nostr' && $fields['vaak_nostr_event_id'] === $noteHex && $fields['vaak_nostr_pubkey'] === $nip19 && $fields['id'] === '9', 'notification cards carry the note id and author key');
$own = ap_masto_nostr_status_fields([], $noteUrl, '');
$check($own['vaak_nostr_pubkey'] === '' && $own['url'] === $noteUrl, 'a liked note of yours has no author key from the row');
$check(ap_masto_nostr_status_fields([], 'https://example.com/x', $actorUrl)['vaak_nostr_event_id'] === '', 'only njump note links decode');
// Zaps: nostr/notif.rs writes `{note}#zap-{sats}s{hash12}`.
$zapId = $noteUrl . '#zap-2100s0123456789ab';
$check(ap_masto_mention_zap_sats($zapId) === 2100, 'zap sats read back from the object id');
$check(ap_masto_mention_zap_sats($noteUrl . '#like-0123456789ab') === 0 && ap_masto_mention_zap_sats('https://evil.example/n#zap-9s0123456789ab') === 0, 'only Nostr zap rows carry sats');
$check(ap_masto_mention_target_object_id($zapId) === $noteUrl, 'a zap points at the zapped note');
$zapRow = ['type' => 'favourite', 'vaak_zap_sats' => 2100, 'status' => ['uri' => $noteUrl], 'account' => []];
$check(admin_notification_zap_sats($zapRow) === 2100 && admin_zap_sats_label(2100) === '2,100 sats' && admin_zap_sats_label(1) === '1 sat', 'zap labels');
$check(admin_notification_group_key($zapRow) === 'zap:' . $noteUrl && admin_notification_group_key(['type' => 'favourite', 'status' => ['uri' => $noteUrl]]) === 'favourite:' . $noteUrl, 'zaps and likes of one note stack apart');

// Composer crosspost: which posts go to Nostr, and what the note carries.
if (!function_exists('ap_db_owner_actor_id_for_user_id')) {
    function ap_db_owner_actor_id_for_user_id(int $userId): string
    {
        return 'https://vaak.test/users/u' . $userId;
    }
}
$xdb = new PDO('sqlite::memory:');
$xdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$xdb->exec('CREATE TABLE masto_statuses (local_id INTEGER PRIMARY KEY, note_id TEXT, content_text TEXT, visibility TEXT, spoiler_text TEXT, in_reply_to_local_id INTEGER)');
$xdb->exec('CREATE TABLE masto_media (local_id INTEGER PRIMARY KEY, status_local_id INTEGER, public_url TEXT, mime TEXT, description TEXT)');
$xdb->exec('CREATE TABLE outbox_notes (id TEXT PRIMARY KEY, in_reply_to TEXT)');
$xdb->exec('CREATE TABLE nostr_profiles (pubkey TEXT PRIMARY KEY, name TEXT, display_name TEXT, picture TEXT)');
$mine = 'https://vaak.test/users/u3/notes/';
$addStatus = $xdb->prepare('INSERT INTO masto_statuses (local_id, note_id, content_text, visibility, spoiler_text, in_reply_to_local_id) VALUES (?, ?, ?, ?, ?, ?)');
$addStatus->execute([1, $mine . '1', 'Hello #Nostr and #nostr #VAAK', 'public', 'birds', null]);
$addStatus->execute([2, $mine . '2', 'quiet', 'unlisted', '', null]);
$addStatus->execute([3, $mine . '3', 'reply', 'public', '', 1]);
$addStatus->execute([4, $mine . '4', 'thread', 'public', '', null]);
$addStatus->execute([5, $mine . '5', '(media)', 'public', '', null]);
$addStatus->execute([6, $mine . '6', "\u{00A0}(poll)\u{00A0}", 'public', '', null]);
$xdb->exec("INSERT INTO outbox_notes (id, in_reply_to) VALUES ('{$mine}4', 'https://elsewhere.test/notes/9')");
$xdb->exec("INSERT INTO masto_media (local_id, status_local_id, public_url, mime, description) VALUES (1, 1, 'https://vaak.test/m/a.jpg', 'image/jpeg', ' a  bird '), (2, 1, 'http://insecure.test/b.jpg', 'image/jpeg', ''), (3, 5, 'https://vaak.test/m/c.mp4', '', '')");
$x = ap_nostr_post_note(3, $mine . '1', $xdb);
$check(!empty($x['ok']) && $x['content'] === "Hello #Nostr and #nostr #VAAK\n\nhttps://vaak.test/m/a.jpg", 'crosspost text ends with its https media links');
$check(($x['tags'] ?? []) === [
    ['t', 'nostr'], ['t', 'vaak'],
    ['content-warning', 'birds'],
    ['imeta', 'url https://vaak.test/m/a.jpg', 'm image/jpeg', 'alt a bird'],
    ['proxy', $mine . '1', 'activitypub'],
], 'crosspost tags: hashtags once, CW, imeta, proxy');
$check(!empty(ap_nostr_post_note(3, $mine . '5', $xdb)['ok']) && ap_nostr_post_note(3, $mine . '5', $xdb)['tags'][0] === ['imeta', 'url https://vaak.test/m/c.mp4'], 'media-only post goes, imeta skips an empty mime');
$check((ap_nostr_post_note(3, $mine . '5', $xdb)['content'] ?? '') === 'https://vaak.test/m/c.mp4', 'media-only post sends just its link, no (media) placeholder');
$check(str_contains((string) (ap_nostr_post_note(3, $mine . '6', $xdb)['error'] ?? ''), 'nothing to post'), 'a bare sentinel with no media is not posted');
$check(str_contains((string) (ap_nostr_post_note(3, $mine . '2', $xdb)['error'] ?? ''), 'public'), 'non-public posts stay on VAAK');
$check(str_contains((string) (ap_nostr_post_note(3, $mine . '3', $xdb)['error'] ?? ''), 'Replies'), 'replies to VAAK posts stay on VAAK');
$check(str_contains((string) (ap_nostr_post_note(3, $mine . '4', $xdb)['error'] ?? ''), 'Replies'), 'replies to remote posts stay on VAAK');
$check(!empty(ap_nostr_post_note(3, $mine . '99', $xdb)['retry']), 'a post still in the queue asks to retry');
$check(empty(ap_nostr_post_note(4, $mine . '1', $xdb)['ok']) && empty(ap_nostr_post_note(4, $mine . '1', $xdb)['retry']), "someone else's post is refused");
$check(empty(ap_nostr_post_note(3, 'https://vaak.test/users/u33/notes/1', $xdb)['ok']), 'a lookalike actor prefix is refused');

// The composer's "Nostr" box needs a linked npub; its name and picture come from kind 0.
unset($GLOBALS['ap_nostr_table_ready']);
putenv('VAAK_NOSTR');
$check(ap_nostr_compose_identity(3, true, $xdb) === null, 'no linked npub, no Nostr box');
ap_nostr_save_link(3, $npub, true, $xdb);
$id = ap_nostr_compose_identity(3, true, $xdb);
$check(is_array($id) && $id['npub'] === $npub && $id['name'] === 'npub10elfc…zvjptg' && $id['picture'] === '', 'no profile yet: short npub, no picture');
$xdb->prepare('INSERT INTO nostr_profiles (pubkey, name, display_name, picture) VALUES (?, ?, ?, ?)')->execute([$nip19, 'val', 'Valerie', 'https://pics.test/v.png']);
$id = ap_nostr_compose_identity(3, true, $xdb);
$check(($id['name'] ?? '') === 'Valerie' && ($id['picture'] ?? '') === 'https://pics.test/v.png', 'kind 0 display name and picture');
$xdb->exec("UPDATE nostr_profiles SET display_name = '', picture = 'javascript:alert(1)'");
$id = ap_nostr_compose_identity(3, true, $xdb);
$check(($id['name'] ?? '') === 'val' && ($id['picture'] ?? 'x') === '', 'falls back to name; only https pictures');
$check(ap_nostr_compose_identity(3, false, $xdb) === null, 'non-admins see no box while Nostr is admin only');

// Notification cards painted by PHP get the stored note's media, like Home.
$mdb = new PDO('sqlite::memory:');
$mdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mdb->exec('CREATE TABLE nostr_events (id TEXT PRIMARY KEY, content TEXT, tags TEXT)');
$picId = str_repeat('cd', 32);
$mdb->prepare('INSERT INTO nostr_events (id, content, tags) VALUES (?, ?, ?)')->execute([
    $picId,
    "Look <here> #photo\n\nhttps://media.example/a.webp\nhttps://example.com/page.",
    json_encode([['imeta', 'url https://media.example/a.webp', 'm image/webp', 'alt a bug']]),
]);
$pic = ap_masto_nostr_note_media(['vaak_nostr_event_id' => $picId, 'content' => '<p>old</p>'], $mdb);
$check(count($pic['media_attachments'] ?? []) === 1 && $pic['media_attachments'][0]['url'] === 'https://media.example/a.webp'
    && $pic['media_attachments'][0]['type'] === 'image' && $pic['media_attachments'][0]['description'] === 'a bug', 'stored note media becomes an attachment');
$check(!str_contains($pic['content'], 'a.webp') && str_contains($pic['content'], '&lt;here&gt;')
    && str_contains($pic['content'], 'href="https://example.com/page"'), 'media link leaves the text, other text escaped and linked: ' . $pic['content']);
$check(ap_masto_nostr_note_media(['vaak_nostr_event_id' => str_repeat('ef', 32), 'content' => 'x'], $mdb) === ['vaak_nostr_event_id' => str_repeat('ef', 32), 'content' => 'x'], 'unstored notes are left alone');

// NIP-46 bunker pairing: input checks before any worker call, and the status line.
$check(str_contains((string) (ap_nostr_bunker_save(1, 'nsec1abcdef', $mdb)['error'] ?? ''), 'Never paste your nsec'), 'an nsec pasted in the bunker box is refused');
$check(str_contains((string) (ap_nostr_bunker_save(1, 'https://example.com', $mdb)['error'] ?? ''), 'bunker://'), 'a non-bunker link is refused');
$check(ap_nostr_bunker_save(0, 'bunker://x', $mdb)['ok'] === false, 'signed-out users cannot pair');
$check(ap_nostr_bunker_save(1, '', $mdb) === ['ok' => true, 'connected' => false, 'removed' => false], 'empty input disconnects (no table on SQLite: nothing to do)');
$check(ap_nostr_bunker_for_user(1, $mdb) === null, 'SQLite has no bunker rows');
$bunkerLine = ap_nostr_bunker_status_line(['signer_pubkey' => str_repeat('ab', 32), 'connected_at' => 1000, 'last_used_at' => 2000, 'last_error' => 'unauthorized', 'last_error_at' => 3000], 3030);
$check(str_starts_with($bunkerLine, 'Connected to bunker npub1') && str_contains($bunkerLine, 'Last signed') && str_contains($bunkerLine, 'Last problem (just now): unauthorized.'), 'bunker status line: ' . $bunkerLine);
$check(!str_contains(ap_nostr_bunker_status_line(['signer_pubkey' => str_repeat('ab', 32), 'connected_at' => 1000, 'last_used_at' => 3000, 'last_error' => 'old', 'last_error_at' => 2000], 3030), 'old'), 'a problem older than the last signature is not shown');
$check(ap_nostr_bunker_status_line(null, 5) === '', 'no pairing, no line');
$adminSrc = vaak_admin_shell_source(__DIR__ . '/../ap-admin.php');
$check(str_contains($adminSrc, "if ((!pubkey || pubkey !== prep.pubkey) && prep.bunker) {"), 'an extension with no key or another key falls back to the bunker');
$nostrSrc = (string) file_get_contents(__DIR__ . '/../ap-nostr.php');
$check(str_contains($nostrSrc, "\$reply['body']['bunker'] = ap_nostr_bunker_for_user("), 'nostr_prepare answers say whether a bunker is connected');

// Hover card for a Nostr account (njump npub URL).
$hex = '7e7e9c42a91bfef19fa929e5fda1b72e0ebc1a4c1141673e2794234d86addf4e';
unset($GLOBALS['ap_nostr_table_ready']);
$hdb = new PDO('sqlite::memory:');
$hdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$hdb->exec('CREATE TABLE nostr_profiles (pubkey TEXT PRIMARY KEY, name TEXT, display_name TEXT, picture TEXT, nip05 TEXT)');
$hdb->exec("INSERT INTO nostr_profiles VALUES ('{$hex}', 'bob', 'Bob', 'https://img.test/b.png', '_@bob.test')");
ap_nostr_table_ready($hdb);
$hdb->exec("INSERT INTO nostr_links (owner_user_id, pubkey, created_at, updated_at) VALUES (1, '" . str_repeat('ab', 32) . "', 'x', 'x'), (2, '{$hex}', 'x', 'x')");
$hdb->exec("INSERT INTO nostr_follows (owner_user_id, pubkey) VALUES (1, '{$hex}')");
$hover = ap_nostr_hover_payload('https://njump.me/' . $npub, 1, $hdb);
$check(!empty($hover['ok']) && $hover['platform'] === 'nostr' && $hover['nostr_pubkey'] === $hex, 'hover card decodes the npub');
$check($hover['display_name'] === 'Bob' && $hover['handle'] === '@bob.test' && $hover['avatar'] === 'https://img.test/b.png', 'hover card uses stored kind 0 metadata');
$check($hover['following'] === true && $hover['can_follow'] === true && $hover['own'] === false, 'hover card knows the viewer follows them');
$check($hover['profile_url'] === 'https://njump.me/' . $npub, 'hover card opens the profile on Nostr');
$ownHover = ap_nostr_hover_payload('https://njump.me/' . $npub, 2, $hdb);
$check($ownHover['own'] === true && $ownHover['can_follow'] === false, 'no follow on your own key');
$unlinked = ap_nostr_hover_payload('https://njump.me/' . $npub, 3, $hdb);
$check($unlinked['can_follow'] === false && $unlinked['following'] === false, 'no follow without a linked npub');
$check(empty(ap_nostr_hover_payload('https://njump.me/note1abc', 1, $hdb)['ok']), 'only npub links get a hover card');
$check(empty(ap_nostr_hover_payload('https://evil.test/' . $npub, 1, $hdb)['ok']), 'only njump links get a hover card');
// Follow back on a Nostr follow notification signs through the viewer's signer.
$back = ap_nostr_notif_follow_html('https://njump.me/' . $npub, 1, 'Bob', true, $hdb);
$check($back['nostr'] && str_contains($back['html'], 'data-nostr-act="unfollow"') && str_contains($back['html'], '>Following</button>'), 'follow notification knows the viewer already follows back');
$hdb->exec("DELETE FROM nostr_follows WHERE owner_user_id = 1");
$back = ap_nostr_notif_follow_html('nostr:' . $npub, 1, 'Bob <x>', true, $hdb);
$check(str_contains($back['html'], 'data-nostr-act="follow"') && str_contains($back['html'], 'data-nostr-target="' . $hex . '"') && str_contains($back['html'], '>Follow back</button>'), 'follow notification offers Follow back on Nostr');
$check(str_contains($back['html'], 'Bob &lt;x&gt;') && !str_contains($back['html'], 'follow_remote'), 'name is escaped and no fediverse follow form');
$check(str_contains(ap_nostr_notif_follow_html('https://njump.me/' . $npub, 1, 'Bob', false, $hdb)['html'], '>Follow</button>'), 'other Nostr notifications say Follow');
$own = ap_nostr_notif_follow_html('https://njump.me/' . $npub, 2, 'Bob', true, $hdb);
$check($own['nostr'] && $own['html'] === '', 'no button for your own key');
$none = ap_nostr_notif_follow_html('https://njump.me/' . $npub, 3, 'Bob', true, $hdb);
$check($none['nostr'] && $none['html'] === '', 'no button without a linked npub (and no fediverse form either)');
$check(ap_nostr_notif_follow_html('https://mastodon.test/users/bob', 1, 'Bob', true, $hdb) === ['nostr' => false, 'html' => ''], 'fediverse actors are left to the usual forms');
unset($GLOBALS['ap_nostr_table_ready']);

// Follow packs (Library > Collections): address and pick checks, page paint.
$pk = str_repeat('ab', 32);
$check(ap_nostr_pack_addr_ok('39089:' . $pk . ':0b6e-41aa'), 'a follow pack address is accepted');
$check(ap_nostr_pack_addr_ok('39092:' . $pk . ':a:b'), 'media packs and colons in d are accepted');
$check(!ap_nostr_pack_addr_ok('30000:' . $pk . ':x'), 'other list kinds are refused');
$check(!ap_nostr_pack_addr_ok('39089:' . $pk . ':'), 'an empty d tag is refused');
$check(ap_nostr_pack_picks(' ' . strtoupper($pk) . ',nope,' . $pk . ',' . str_repeat('cd', 32)) === [$pk, str_repeat('cd', 32)], 'picks are lowercased, deduped and filtered');
$packHtml = ap_nostr_pack_html([
    'ok' => true,
    'linked' => true,
    'max_picks' => 150,
    'pack' => ['addr' => '39089:' . $pk . ':x1', 'naddr' => 'naddr1test', 'title' => 'Cool <people>', 'description' => 'hi', 'image' => 'javascript:alert(1)', 'dropped' => 0, 'author' => ['pubkey' => $pk, 'npub' => 'npub1author', 'name' => 'Ann', 'picture' => '']],
    'members' => [
        ['pubkey' => str_repeat('01', 32), 'npub' => 'npub1one', 'name' => 'One', 'picture' => 'https://img.example/1.png', 'nip05' => '_@one.example', 'following' => false, 'me' => false],
        ['pubkey' => str_repeat('02', 32), 'npub' => 'npub1two', 'name' => '', 'picture' => '', 'nip05' => '', 'following' => true, 'me' => false],
        ['pubkey' => $pk, 'npub' => 'npub1me', 'name' => 'Me', 'picture' => '', 'nip05' => '', 'following' => false, 'me' => true],
    ],
], 'naddr1test');
$check(str_contains($packHtml, 'Cool &lt;people&gt;') && !str_contains($packHtml, 'javascript:'), 'pack title escaped, unsafe image dropped');
$check(substr_count($packHtml, 'name="nostr_pack_pick"') === 3 && substr_count($packHtml, ' checked') === 2, 'one open member is ticked (plus the select-all box)');
$check(substr_count($packHtml, ' disabled') === 2, 'followed members and yourself cannot be picked');
$check(str_contains($packHtml, 'data-nostr-pack-count>1<') && str_contains($packHtml, 'following.space/d/x1?p=' . $pk), 'follow count and following.space link');
$check(str_contains($packHtml, 'one.example') && !str_contains($packHtml, '_@'), 'NIP-05 shown without the _@ prefix');
$check(str_contains(ap_nostr_pack_html(['ok' => true, 'linked' => false, 'pack' => [], 'members' => []], 'x'), 'Link your npub'), 'unlinked users are told to link an npub');
$check(str_contains(ap_nostr_pack_html(['ok' => false, 'error' => 'No relay answered.'], 'x'), 'No relay answered.'), 'worker errors are shown');

if ($failures !== []) {
    fwrite(STDERR, "nostr-smoke: " . count($failures) . " of {$checks} checks failed\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "nostr-smoke ok ({$checks} checks)\n";
