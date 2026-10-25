<?php
declare(strict_types=1);
/**
 * Local-to-local boosts: Rust cards submit a synthetic status id PHP cannot
 * map, plus the note's object URI. The web controller fell back to the URI,
 * but the action-queue worker resolved by status id only, so every boost of
 * another local account's post failed with "Fediverse status is no longer
 * available" (no Announce, no Boosts-tab row, no Bluesky repost).
 * Stubs only; no database or network.
 */
$api = getenv('VAAK_LOCAL_BOOST_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');

$shanaNote = 'https://mkultra.monster/users/shana_flux/notes/afcec15ea969bd2c';
$GLOBALS['by_sid'] = [];
$GLOBALS['by_url'] = [$shanaNote => [
    'id' => '115000000000000001',
    'uri' => $shanaNote,
    'visibility' => 'public',
    'account' => ['uri' => 'https://mkultra.monster/users/shana_flux'],
    'content' => '<p>hi</p>',
]];
$GLOBALS['performed'] = [];
$GLOBALS['enqueued'] = [];

function ap_masto_resolve_status_interaction(int $sid): ?array { return $GLOBALS['by_sid'][$sid] ?? null; }
function ap_masto_lookup_status_by_object_url(string $url, int $d = 1, bool $f = false, bool $h = false): ?array
{
    return $GLOBALS['by_url'][rtrim($url, '/')] ?? null;
}
function ap_masto_session_actor_id(): string { return 'https://mkultra.monster/users/cmdr_nova'; }

reference_load($api . '/ap-masto-entities.php', ['ap_masto_resolve_boost_target']);

$checks = 0;
function lb_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

// 1. Synthetic id unknown to PHP: falls back to the object URI.
$r = ap_masto_resolve_boost_target(987654321, $shanaNote . '/');
lb_check(is_array($r), 'synthetic id falls back to the object URI');
lb_check($r['object_id'] === $shanaNote, 'object id is canonical (no trailing slash)');
lb_check($r['target_actor'] === 'https://mkultra.monster/users/shana_flux', 'target actor is the quoted local author');
lb_check($r['is_ours'] === false, "another local account's note is not ours");
lb_check((string) $r['status']['id'] === '115000000000000001', 'status id comes from the real local row');

// 2. A real id that resolves to the submitted object is trusted as-is.
$GLOBALS['by_sid'][42] = ['status' => ['id' => '42'], 'object_id' => $shanaNote, 'target_actor' => null, 'is_ours' => false];
lb_check(ap_masto_resolve_boost_target(42, $shanaNote)['status']['id'] === '42', 'matching status id wins');

// 3. A synthetic id that decodes to an unrelated row must not boost the wrong post.
$GLOBALS['by_sid'][43] = ['status' => ['id' => '43'], 'object_id' => 'https://mkultra.monster/users/luna/notes/zzz', 'target_actor' => null, 'is_ours' => false];
lb_check(ap_masto_resolve_boost_target(43, $shanaNote)['object_id'] === $shanaNote, 'mismatched status id yields to the submitted object');

// 4. Old queue rows without object_id still resolve by id; unknown everything is null.
lb_check(ap_masto_resolve_boost_target(42, '')['status']['id'] === '42', 'empty object id keeps id-only behaviour');
lb_check(ap_masto_resolve_boost_target(7, 'https://mkultra.monster/users/x/notes/missing') === null, 'unknown target is rejected');
lb_check(ap_masto_resolve_boost_target(0, 'not-a-url') === null, 'non-URL target is rejected');

// 5. Own note via the fallback is flagged as ours.
$own = 'https://mkultra.monster/users/cmdr_nova/notes/abc';
$GLOBALS['by_url'][$own] = ['id' => '9', 'uri' => $own, 'visibility' => 'public', 'account' => ['uri' => 'https://mkultra.monster/users/cmdr_nova']];
lb_check(ap_masto_resolve_boost_target(0, $own)['is_ours'] === true, 'own note is ours');

// 6. The worker passes the payload object id (or target key) to the helper.
$queueSrc = (string) file_get_contents($api . '/ap-action-queue.php');
lb_check(str_contains($queueSrc, "ap_masto_resolve_boost_target((int) (\$payload['status_id'] ?? 0),"), 'worker resolves boosts through the fallback helper');
lb_check(str_contains($queueSrc, "(string) (\$payload['object_id'] ?? \$target)"), 'worker falls back to the queue target key');
$adminSrc = (string) file_get_contents($api . '/ap-admin.php');
lb_check(str_contains($adminSrc, "['status_id' => \$sid, 'object_id' => \$boostObjectId]"), 'web enqueue stores the object id in the payload');

echo "local-boost-resolve-smoke ok ({$checks} checks)\n";
