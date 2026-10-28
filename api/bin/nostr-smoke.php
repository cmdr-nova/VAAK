<?php
/**
 * Nostr link: npub/nprofile/hex parsing (NIP-19 test vectors), nsec refusal,
 * the Settings rollout gate, status line, and the SQLite save/replace/unlink
 * path. No network and no production database.
 */
declare(strict_types=1);

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
foreach (['repost', 'quote', 'delete'] as $purpose) {
    $res = ap_nostr_action('nostr_prepare', 1, true, ['purpose' => $purpose, 'target' => $hex]);
    $check(($res['body']['error'] ?? '') !== 'Unknown Nostr action.', "{$purpose} passes PHP checks and goes to the worker");
    $check(ap_nostr_action('nostr_prepare', 1, true, ['purpose' => $purpose, 'target' => 'nope'])['status'] === 400, "{$purpose} still needs a hex target");
}
$check(ap_nostr_action('nostr_prepare', 1, true, ['purpose' => 'unrepost', 'target' => $hex])['status'] === 400, 'unrepost is chosen by the worker, not asked for');
$check(ap_nostr_action('nostr_publish', 1, true, ['nonce' => 'xyz', 'event' => '{}'])['status'] === 400, 'bad nonce refused');
$check(ap_nostr_action('nostr_publish', 1, true, ['nonce' => str_repeat('a', 32), 'event' => 'not json'])['status'] === 400, 'non-JSON event refused');
$check(ap_nostr_action('nostr_sign_anything', 1, true, [])['status'] === 400, 'unknown action refused');

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

if ($failures !== []) {
    fwrite(STDERR, "nostr-smoke: " . count($failures) . " of {$checks} checks failed\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "nostr-smoke ok ({$checks} checks)\n";
