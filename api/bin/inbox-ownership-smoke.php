<?php
/**
 * Inbox ownership rules: the signing key must belong to the activity actor,
 * and a remote actor may only change objects on its own host.
 * No database and no network.
 */
declare(strict_types=1);

define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__) . '/ap-inbox.php';

$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void {
    if (!$ok) {
        fwrite(STDERR, "inbox-ownership: {$why}\n");
        exit(1);
    }
    $n++;
};

$alice = 'https://example.social/users/alice';
$mallory = 'https://example.social/users/mallory';

// Key ownership without a cached owner falls back to the keyId shape.
$check(ap_inbox_actor_matches_key($alice . '#main-key', $alice), 'actor#main-key matches');
$check(ap_inbox_actor_matches_key($alice . '/main-key', $alice), 'GoToSocial actor/main-key matches');
$check(!ap_inbox_actor_matches_key($mallory . '#main-key', $alice), 'same-host key of another account does not match');
$check(!ap_inbox_actor_matches_key($alice . 'x#main-key', $alice), 'prefix without a path boundary does not match');
$check(!ap_inbox_actor_matches_key('https://other.example/users/alice#main-key', $alice), 'other host does not match');

// A cached owner decides.
$sharedKey = 'https://example.social/actor#main-key';
$ownerPath = ap_key_owner_cache_path($sharedKey);
@mkdir(dirname($ownerPath), 0750, true);
file_put_contents($ownerPath, $alice);
$check(ap_inbox_actor_matches_key($sharedKey, $alice), 'cached owner matches its actor');
$check(!ap_inbox_actor_matches_key($sharedKey, $mallory), 'cached owner rejects another actor');
@unlink($ownerPath);
$ownerPath = ap_key_owner_cache_path($mallory . '#main-key');
file_put_contents($ownerPath, $mallory);
$check(!ap_inbox_actor_matches_key($mallory . '#main-key', $alice), 'cached owner beats a lookalike keyId');
@unlink($ownerPath);

// Owner extraction.
$check(ap_extract_pubkey_owner(['id' => $alice, 'publicKey' => ['id' => $alice . '#main-key', 'owner' => $alice, 'publicKeyPem' => 'x']]) === $alice, 'publicKey.owner');
$check(ap_extract_pubkey_owner(['id' => $alice . '#main-key', 'owner' => $alice, 'publicKeyPem' => 'x']) === $alice, 'bare key document owner');
$check(ap_extract_pubkey_owner(['id' => $alice, 'publicKey' => [['publicKeyPem' => 'x']]]) === $alice, 'embedded key list falls back to actor id');
$check(ap_extract_pubkey_owner(['publicKeyPem' => 'x']) === null, 'no owner when the document names none');

// Object ownership.
$check(ap_inbox_object_owned_by_actor($alice . '/statuses/1', $alice), 'own status');
$check(ap_inbox_object_owned_by_actor($alice, $alice), 'own actor (account delete)');
$check(!ap_inbox_object_owned_by_actor('https://other.example/notes/1', $alice), 'foreign host');
$check(!ap_inbox_object_owned_by_actor('https://mkultra.monster/users/cmdr_nova/notes/1', 'https://mkultra.monster/users/x'), 'local objects are never remotely owned');
$check(!ap_inbox_object_owned_by_actor('https://vaak.monster/notes/1', $alice), 'vaak host');
$check(!ap_inbox_object_owned_by_actor('%', $alice), 'non-url');
$check(!ap_inbox_object_owned_by_actor($alice . '/statuses/1', null), 'no actor');

// Forwarding bypass is limited to Create/Announce.
$src = (string) file_get_contents(dirname(__DIR__) . '/ap-inbox.php');
$check(str_contains($src, "\$forwardable = in_array(\$type, ['Create', 'Announce'], true);"), 'forwardable types are Create and Announce');
$check(str_contains($src, '$relayOk = $forwardable &&') && str_contains($src, '$followFwd = $forwardable &&'), 'relay and follow forwarding need a forwardable type');

echo "inbox-ownership-smoke: {$n} checks ok\n";
