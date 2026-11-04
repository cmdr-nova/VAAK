<?php
/**
 * Follow suggestions never offer someone the viewer already follows, under
 * any spelling or network: /@user vs /users/user, Bluesky DID vs handle,
 * Bridgy copies, Nostr (Mostr) keys, pending/queued/rejected follows, the
 * viewer's own identities, and Bluesky/Nostr blocks and mutes. Other
 * viewers' follows do not leak in.
 */
declare(strict_types=1);

require __DIR__ . '/migration-reference-functions.php';
reference_load(__DIR__ . '/../ap-masto-entities.php', [
    'ap_masto_suggestion_identity_keys',
    'ap_masto_suggestion_keys_hit',
    'ap_masto_suggestion_exclusions',
]);

$checks = 0;
function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $GLOBALS['checks']++;
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE following (owner_actor_id TEXT, actor_id TEXT, follow_state TEXT)");
$db->exec("CREATE TABLE ap_action_queue (owner_user_id INTEGER, action_kind TEXT, target_key TEXT, desired_state INTEGER)");
$db->exec("CREATE TABLE bsky_graph_sync (owner_user_id INTEGER, kind TEXT, target_did TEXT)");
$db->exec("CREATE TABLE bsky_sessions (owner_user_id INTEGER, did TEXT, handle TEXT)");
$db->exec("CREATE TABLE bsky_actor_viewers (owner_user_id INTEGER, actor_ref TEXT, following_uri TEXT)");
$db->exec("CREATE TABLE bsky_actor_profiles (actor_ref TEXT, did TEXT, profile_json TEXT)");
$db->exec("CREATE TABLE bsky_posts (author_did TEXT, author_handle TEXT)");
$db->exec("CREATE TABLE nostr_follows (owner_user_id INTEGER, pubkey TEXT)");
$db->exec("CREATE TABLE nostr_links (owner_user_id INTEGER, pubkey TEXT)");
$db->exec("CREATE TABLE nostr_mutes (owner_user_id INTEGER, pubkey TEXT)");
$db->exec("CREATE TABLE nostr_blocks (pubkey TEXT)");

$me = 'https://mkultra.monster/users/cmdr_nova';
$nostrFollowed = str_repeat('a', 64);
$nostrMuted = str_repeat('b', 64);
$nostrSelf = str_repeat('c', 64);
$nostrOther = str_repeat('d', 64);
$db->exec("INSERT INTO following VALUES ('$me', 'https://rejected.example/users/nope', 'rejected')");
$db->exec("INSERT INTO ap_action_queue VALUES (1, 'follow', 'https://queued.example/users/soon', 1)");
$db->exec("INSERT INTO ap_action_queue VALUES (1, 'follow', 'https://unfollowed.example/users/gone', 0)");
$db->exec("INSERT INTO ap_action_queue VALUES (2, 'follow', 'https://other-user.example/users/theirs', 1)");
$db->exec("INSERT INTO bsky_graph_sync VALUES (1, 'follow', 'did:plc:followed')");
$db->exec("INSERT INTO bsky_graph_sync VALUES (1, 'follower', 'did:plc:fan')");
$db->exec("INSERT INTO bsky_graph_sync VALUES (2, 'follow', 'did:plc:someoneelses')");
$db->exec("INSERT INTO bsky_posts VALUES ('did:plc:followed', 'Friend.bsky.social')");
$db->exec("INSERT INTO bsky_actor_profiles VALUES ('did:plc:viewerfollow', 'did:plc:viewerfollow', '{\"handle\":\"elsewhere.bsky.social\"}')");
$db->exec("INSERT INTO bsky_actor_profiles VALUES ('did:plc:hidden', 'did:plc:hidden', '{\"handle\":\"troll.bsky.social\"}')");
$db->exec("INSERT INTO bsky_actor_viewers VALUES (1, 'did:plc:viewerfollow', 'at://did:plc:me/app.bsky.graph.follow/1')");
$db->exec("INSERT INTO bsky_actor_viewers VALUES (1, 'handleonly.bsky.social', 'at://did:plc:me/app.bsky.graph.follow/2')");
$db->exec("INSERT INTO bsky_actor_viewers VALUES (1, 'did:plc:notfollowed', '')");
$db->exec("INSERT INTO bsky_sessions VALUES (1, 'did:plc:me', 'valerie.bsky.mkultra.monster')");
$db->exec("INSERT INTO nostr_follows VALUES (1, '$nostrFollowed')");
$db->exec("INSERT INTO nostr_links VALUES (1, '$nostrSelf')");
$db->exec("INSERT INTO nostr_mutes VALUES (1, '$nostrMuted')");

$following = [
    ['actor_id' => 'https://mastodon.example/users/Alice', 'follow_state' => 'accepted'],
    ['actor_id' => 'https://locked.example/users/bob', 'follow_state' => 'pending'],
    ['actor_id' => 'https://bsky.brid.gy/ap/did:plc:bridged'],
    ['actor_id' => 'https://social.example/users/under_score'],
];
$sets = ap_masto_suggestion_exclusions(
    $db,
    1,
    $me,
    $following,
    ['https://dismissed.example/users/meh' => true],
    ['did:plc:hidden' => true],
    ['https://bsky.app/profile/legacy.bsky.social']
);
$ex = $sets['exclude'];
$hid = $sets['hidden'];
$skipped = static fn(string $ref): bool => ap_masto_suggestion_keys_hit($ex, $ref) || ap_masto_suggestion_keys_hit($hid, $ref);

// Fediverse follows, any URL spelling, including pending requests.
check($skipped('https://mastodon.example/users/Alice'), 'followed actor');
check($skipped('https://mastodon.example/@Alice'), 'followed actor via /@ alias');
check($skipped('https://mastodon.example/users/Alice/'), 'trailing slash');
check($skipped('https://locked.example/@bob'), 'pending follow request');
check($skipped('https://queued.example/users/soon'), 'follow still in the queue');
check(!$skipped('https://unfollowed.example/users/gone'), 'queued unfollow is not a follow');
check($skipped('https://rejected.example/users/nope'), 'rejected follow request');
check($skipped('https://dismissed.example/@meh'), 'dismissed via alias');

// Bluesky follows: DID, handle (post cache, profile cache, viewer state), Bridgy.
check($skipped('https://bsky.app/profile/did:plc:followed'), 'Bluesky DID');
check($skipped('https://bsky.app/profile/friend.bsky.social'), 'Bluesky handle from post cache');
check($skipped('https://bsky.app/profile/did%3Aplc%3Afollowed'), 'encoded DID');
check($skipped('https://bsky.brid.gy/ap/did:plc:followed'), 'Bridgy AP copy of a Bluesky follow');
check($skipped('https://bsky.app/profile/did:plc:bridged'), 'Bluesky account followed via Bridgy');
check($skipped('https://bsky.app/profile/elsewhere.bsky.social'), 'AppView viewer follow, handle');
check($skipped('https://bsky.app/profile/handleonly.bsky.social'), 'AppView viewer follow, handle ref');
check(!$skipped('https://bsky.app/profile/did:plc:notfollowed'), 'empty viewer following_uri');
check(!$skipped('https://bsky.app/profile/did:plc:fan'), 'a follower is not a follow');
check($skipped('https://bsky.app/profile/legacy.bsky.social'), 'legacy bsky_follow event');
check($skipped('https://bsky.app/profile/under-score.social.example.ap.brid.gy'), 'Bridgy Bluesky copy of a fediverse follow');
check($skipped('https://bsky.app/profile/alice.mastodon.example.ap.brid.gy'), 'Bridgy handle is case-insensitive');

// Nostr.
check($skipped('https://mostr.pub/users/' . $nostrFollowed), 'Nostr contact via Mostr');
check($skipped('https://mostr.pub/users/' . $nostrMuted), 'Nostr mute');
check(!$skipped('https://mostr.pub/users/' . $nostrOther), 'unrelated Nostr key');

// Self on every network, and every local account.
check($skipped($me), 'self');
check($skipped('https://mkultra.monster/@cmdr_nova'), 'self via /@');
check($skipped('https://mkultra.monster/users/someone_local'), 'local account');
check($skipped('https://bsky.app/profile/did:plc:me'), 'own Bluesky DID');
check($skipped('https://bsky.app/profile/valerie.bsky.mkultra.monster'), 'own Bluesky handle');
check($skipped('https://bsky.app/profile/cmdr-nova.mkultra.monster.ap.brid.gy'), 'own Bridgy copy');
check($skipped('https://mostr.pub/users/' . $nostrSelf), 'own Nostr key');

// Blocks/mutes from Bluesky lists, by DID and by handle.
check($skipped('https://bsky.app/profile/did:plc:hidden'), 'hidden DID');
check($skipped('https://bsky.app/profile/troll.bsky.social'), 'hidden handle');
check(isset($hid['bsky:did:plc:hidden']) && !isset($ex['bsky:did:plc:hidden']), 'hidden stays separate');

// Other viewers' follows do not leak in; strangers stay suggestible.
check(!$skipped('https://other-user.example/users/theirs'), 'another owner queued follow');
check(!$skipped('https://bsky.app/profile/did:plc:someoneelses'), 'another owner Bluesky follow');
check(!$skipped('https://mastodon.example/users/carol'), 'stranger');
check(!$skipped('https://bsky.app/profile/stranger.bsky.social'), 'Bluesky stranger');

// Missing tables never throw; follows from the list still apply.
$empty = new PDO('sqlite::memory:');
$empty->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bare = ap_masto_suggestion_exclusions($empty, 1, $me, $following);
check(ap_masto_suggestion_keys_hit($bare['exclude'], 'https://mastodon.example/@Alice'), 'works without side tables');

// Key shapes.
check(ap_masto_suggestion_identity_keys('') === [], 'empty ref');
check(ap_masto_suggestion_identity_keys('did:plc:X') === ['bsky:did:plc:x'], 'bare DID');
check(!in_array('url:https://x.example/@123456789', ap_masto_suggestion_identity_keys('https://x.example/users/123456789'), true), 'numeric ids get no /@ twin');

echo "follow-suggestion-exclusions-smoke: {$checks} checks ok\n";
