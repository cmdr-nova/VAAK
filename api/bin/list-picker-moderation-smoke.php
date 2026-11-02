<?php
declare(strict_types=1);
/**
 * + Add to list picker and cross-network moderation lists.
 *
 * - The picker lists every list with membership, and refuses lists an author
 *   cannot join (Nostr on regular lists, someone else's subscribed list).
 * - Moderation lists take fediverse, local, Bluesky and Nostr accounts without
 *   a follow; a block/mute action then hides them for this viewer
 *   (ap_lists_moderation_action_for_actor, aliases and DIDs included).
 * - An inactive moderation list hides nobody; regular lists still need a follow.
 * No network; SQLite in memory.
 */
$api = getenv('VAAK_LIST_PICKER_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');

const AP_LIST_MAX = 50;
const AP_LIST_TITLE_MAX = 256;
const AP_LIST_MAX_MEMBERS = 500;

$GLOBALS['db'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ap_db(): PDO { return $GLOBALS['db']; }
function ap_db_now(): string { return gmdate('c'); }
function ap_db_last_insert_id(string $table): int { return (int) ap_db()->lastInsertId(); }
function ap_db_default_owner_user_id(): int { return 1; }
function ap_fix_utf8(string $s): string { return $s; }
function vaak_is_local_url(string $u): bool { return str_starts_with($u, 'https://mkultra.monster/'); }
function ap_actor_is_followed(string $actor): bool { return $actor === 'https://fedi.example/users/friend'; }
function ap_bsky_session_row(int $owner): ?array { return null; }
function ap_bsky_resolve_target_did(string $ref, int $owner = 0): ?string
{
    if (str_starts_with($ref, 'did:')) return $ref;
    if (preg_match('~^https://bsky\.app/profile/([^/?#]+)~i', $ref, $m)) {
        $part = rawurldecode($m[1]);
        return str_starts_with($part, 'did:') ? $part : ($part === 'pest.bsky.social' ? 'did:plc:pest' : null);
    }
    return null;
}
function ap_resolve_actor_ref(string $ref): ?string { return null; }
// Adding or removing anyone must never reload the Bluesky hide set inline.
function ap_bsky_refresh_hide_set(int $owner, bool $force = false): array { throw new RuntimeException('hide-set reload inside a list edit'); }
function ap_bsky_add_graph_list_member(int $owner, string $list, string $did): array { $GLOBALS['pds_writes'][] = $did; return ['ok' => true, 'uri' => 'at://did:plc:me/app.bsky.graph.listitem/' . count($GLOBALS['pds_writes'])]; }
function ap_bsky_delete_graph_list_member(int $owner, string $uri): array { $GLOBALS['pds_deletes'][] = $uri; return ['ok' => true]; }
$GLOBALS['pds_writes'] = [];
$GLOBALS['pds_deletes'] = [];

reference_load($api . '/ap-db.php', ['ap_moderation_syntactic_refs', 'ap_moderation_path_segment_forms']);
reference_load($api . '/ap-lists.php', [
    'ap_list_owner_user_id', 'ap_lists_all', 'ap_list_by_id', 'ap_list_accounts', 'ap_list_add_account', 'ap_list_remove_account',
    'ap_lists_moderation_action_for_actor', 'ap_lists_moderation_members', 'ap_lists_moderation_dids',
    'ap_lists_moderation_nostr_keys', 'ap_list_is_nostr_ref', 'ap_list_member_ref', 'ap_list_picker_state',
    'ap_lists_moderation_cache_clear', 'ap_list_resolve_actor_ref', 'ap_list_bsky_hide_set_mark_stale',
]);

$checks = 0;
function lp_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

$db = ap_db();
$db->exec("CREATE TABLE masto_lists (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER NOT NULL, title TEXT NOT NULL, replies_policy TEXT NOT NULL DEFAULT 'list', exclusive INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, list_kind TEXT NOT NULL DEFAULT 'curation', bsky_list_uri TEXT, bsky_moderation_action TEXT NOT NULL DEFAULT 'none', bsky_mod_action_uri TEXT, bsky_list_source TEXT NOT NULL DEFAULT 'vaak')");
$db->exec('CREATE TABLE masto_list_accounts (list_id INTEGER NOT NULL, actor_id TEXT NOT NULL, added_at TEXT NOT NULL, bsky_did TEXT, bsky_item_uri TEXT, PRIMARY KEY (list_id, actor_id))');
$mk = static function (string $title, string $kind, string $action, string $source = 'vaak', int $owner = 1) use ($db): int {
    $db->prepare('INSERT INTO masto_lists (owner_user_id, title, list_kind, bsky_moderation_action, bsky_list_source, bsky_list_uri, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$owner, $title, $kind, $action, $source, $source === 'subscription' ? 'at://did:plc:other/app.bsky.graph.list/x' : null, gmdate('c'), gmdate('c')]);
    return (int) $db->lastInsertId();
};
$friends = $mk('Friends', 'curation', 'none');
$blocks = $mk('Trolls', 'moderation', 'block');
$mutes = $mk('Noisy', 'moderation', 'mute');
$inactive = $mk('Parked', 'moderation', 'none');
$subscribed = $mk('Community blocklist', 'moderation', 'block', 'subscription');
$otherOwner = $mk('Not mine', 'moderation', 'block', 'vaak', 2);

$troll = 'https://fedi.example/users/troll';
$nostrHex = str_repeat('ab', 32);

// Regular lists still need a follow; moderation lists never do.
$r = ap_list_add_account($friends, $troll);
lp_check(empty($r['ok']) && str_contains((string) ($r['error'] ?? ''), 'must be followed'), 'regular list needs a follow');
lp_check(!empty(ap_list_add_account($friends, 'https://fedi.example/users/friend')['ok']), 'followed account joins a regular list');
lp_check(!empty(ap_list_add_account($blocks, $troll)['ok']), 'unfollowed fediverse troll joins block list');
lp_check(!empty(ap_list_add_account($mutes, 'https://mkultra.monster/users/local_pest')['ok']), 'local account joins mute list');
lp_check(!empty(ap_list_add_account($blocks, 'https://bsky.app/profile/pest.bsky.social')['ok']), 'Bluesky account joins an unlinked moderation list');
lp_check(!empty(ap_list_add_account($blocks, 'nostr:' . $nostrHex)['ok']), 'Nostr key joins a moderation list');
lp_check(empty(ap_list_add_account($friends, 'nostr:' . $nostrHex)['ok']), 'Nostr key refused on a regular list');
lp_check(empty(ap_list_add_account($subscribed, $troll)['ok']), 'someone else\'s Bluesky list refused');
lp_check(!empty(ap_list_add_account($inactive, 'https://fedi.example/users/parked')['ok']), 'inactive list still takes members');

// Enforcement: block and mute actions, aliases, DIDs; inactive hides nobody.
lp_check(ap_lists_moderation_action_for_actor($troll) === 'block', 'troll blocked');
lp_check(ap_lists_moderation_action_for_actor('https://fedi.example/@troll') === 'block', 'troll alias blocked');
lp_check(ap_lists_moderation_action_for_actor('https://mkultra.monster/users/local_pest') === 'mute', 'local pest muted');
lp_check(ap_lists_moderation_action_for_actor('did:plc:pest') === 'block', 'Bluesky DID blocked');
lp_check(ap_lists_moderation_action_for_actor('https://bsky.app/profile/did:plc:pest') === 'block', 'Bluesky DID URL blocked');
lp_check(ap_lists_moderation_action_for_actor('nostr:' . $nostrHex) === 'block', 'Nostr key blocked');
lp_check(ap_lists_moderation_action_for_actor('https://fedi.example/users/parked') === 'none', 'inactive list hides nobody');
lp_check(ap_lists_moderation_action_for_actor('https://fedi.example/users/friend') === 'none', 'regular list hides nobody');
lp_check(ap_lists_moderation_action_for_actor($troll, 2) === 'none', 'another user\'s view unaffected');
lp_check(isset(ap_lists_moderation_dids(1)['did:plc:pest']), 'DID feeds the Bluesky hide set');
lp_check(isset(ap_lists_moderation_nostr_keys(1)[$nostrHex]), 'Nostr key feeds the Nostr hide set');

// Picker state: membership, refusals, ordering, other owners hidden.
$state = ap_list_picker_state($troll, 1);
lp_check(!empty($state['ok']) && $state['network'] === 'fediverse', 'picker resolves fediverse actor');
$byTitle = [];
foreach ($state['lists'] as $row) $byTitle[$row['title']] = $row;
lp_check(!isset($byTitle['Not mine']), 'other owners\' lists hidden');
lp_check($byTitle['Trolls']['member'] === true && $byTitle['Trolls']['action'] === 'block', 'troll shown on block list');
lp_check($byTitle['Friends']['member'] === false && $byTitle['Friends']['can_edit'] === true, 'regular list offered');
lp_check($byTitle['Community blocklist']['can_edit'] === false, 'subscribed list read-only');
lp_check($state['lists'][0]['kind'] === 'curation', 'regular lists first');
$nostrState = ap_list_picker_state('nostr:' . strtoupper($nostrHex), 1);
lp_check($nostrState['actor'] === 'nostr:' . $nostrHex, 'Nostr ref lowercased');
foreach ($nostrState['lists'] as $row) {
    if ($row['title'] === 'Friends') lp_check($row['can_edit'] === false, 'Nostr refused on regular list in picker');
    if ($row['title'] === 'Trolls') lp_check($row['member'] === true, 'Nostr member shown');
}
$bskyState = ap_list_picker_state('https://bsky.app/profile/pest.bsky.social', 1);
lp_check($bskyState['actor'] === 'https://bsky.app/profile/' . rawurlencode('did:plc:pest'), 'Bluesky handle canonicalized to DID');
lp_check(empty(ap_list_picker_state('javascript:alert(1)', 1)['ok']), 'junk refused');

// A Bluesky-linked active block list: non-Bluesky members stay VAAK-side with
// no PDS call; Bluesky members are written to the PDS. Neither reloads the
// Bluesky hide set inside the request.
$db->prepare('INSERT INTO masto_lists (owner_user_id, title, list_kind, bsky_moderation_action, bsky_list_source, bsky_list_uri, created_at, updated_at) VALUES (1, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['Linked blocks', 'moderation', 'block', 'vaak', 'at://did:plc:me/app.bsky.graph.list/linked', gmdate('c'), gmdate('c')]);
$linked = (int) $db->lastInsertId();
lp_check(!empty(ap_list_add_account($linked, 'https://fedi.example/users/linkedtroll')['ok']), 'fediverse account joins linked block list');
lp_check(!empty(ap_list_add_account($linked, 'https://mkultra.monster/users/linkedpest')['ok']), 'local account joins linked block list');
lp_check($GLOBALS['pds_writes'] === [], 'non-Bluesky members never contact the PDS');
lp_check(ap_lists_moderation_action_for_actor('https://fedi.example/users/linkedtroll') === 'block', 'fediverse member blocked VAAK-side');
lp_check(count(ap_list_accounts($linked)) === 2, 'members listed VAAK-side');
lp_check(!empty(ap_list_add_account($linked, 'https://bsky.app/profile/did:plc:linkedbsky')['ok']), 'Bluesky member joins linked block list');
lp_check($GLOBALS['pds_writes'] === ['did:plc:linkedbsky'], 'Bluesky member written to the PDS once');
lp_check(!empty(ap_list_remove_account($linked, 'https://fedi.example/users/linkedtroll')['ok']), 'remove fediverse member');
lp_check($GLOBALS['pds_deletes'] === [], 'removing a non-Bluesky member never contacts the PDS');
lp_check(!empty(ap_list_remove_account($linked, 'https://bsky.app/profile/did:plc:linkedbsky')['ok']), 'remove Bluesky member');
lp_check(count($GLOBALS['pds_deletes']) === 1, 'Bluesky member deleted from the PDS');

// Removal lifts the block.
lp_check(!empty(ap_list_remove_account($blocks, $troll)['ok']), 'remove troll');
lp_check(ap_lists_moderation_action_for_actor($troll) === 'none', 'troll visible again');
lp_check(!empty(ap_list_remove_account($blocks, 'https://bsky.app/profile/did:plc:pest')['ok']), 'remove Bluesky by unencoded DID URL');
lp_check(ap_lists_moderation_action_for_actor('did:plc:pest') === 'none', 'Bluesky account visible again');

echo "list-picker-moderation-smoke: ok ({$checks} checks)\n";
