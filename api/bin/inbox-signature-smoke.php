<?php
/**
 * Inbox HTTP signature verification and origin-fetch fallback.
 * No database. Key material is generated in the process and never written.
 */
declare(strict_types=1);

define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__) . '/ap-inbox.php';

$GLOBALS['n'] = 0;
function check(bool $ok, string $why): void
{
    if (!$ok) {
        fwrite(STDERR, "inbox-signature: {$why}\n");
        exit(1);
    }
    $GLOBALS['n']++;
}

ap_block_list_cached(false, []);

$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
check($res !== false, 'generate rsa key');
openssl_pkey_export($res, $privPem);
$pem = openssl_pkey_get_details($res)['key'];
$GLOBALS['ap_inbox_pubkey_override'] = static fn(): string => $pem;
$GLOBALS['ap_inbox_origin_cache_dir'] = sys_get_temp_dir() . '/ap-inbox-origin-smoke-' . bin2hex(random_bytes(4));
$GLOBALS['ap_inbox_origin_mem'] = [];

$actor = 'https://example.com/users/alice';
$objectId = $actor . '/statuses/1';
$activityId = $objectId . '/activity';
$postedBody = json_encode([
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => $activityId,
    'type' => 'Create',
    'actor' => $actor,
    'object' => [
        'id' => $objectId,
        'type' => 'Note',
        'attributedTo' => $actor,
        'content' => 'posted-body',
    ],
], JSON_UNESCAPED_SLASHES);
check(is_string($postedBody), 'encode posted body');

function sign_headers(string $signing, string $privPem, string $keyId, string $headers): string
{
    openssl_sign($signing, $raw, $privPem, OPENSSL_ALGO_SHA256);
    return 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="' . $headers . '",signature="' . base64_encode($raw) . '"';
}

$keyId = $actor . '#main-key';
$digest = 'SHA-256=' . base64_encode(hash('sha256', $postedBody, true));
$date = gmdate('D, d M Y H:i:s') . ' GMT';
$host = 'mkultra.monster';
$ct = 'application/activity+json';

// Classic order still verifies.
$classic = "(request-target): post /inbox\nhost: {$host}\ndate: {$date}\ndigest: {$digest}";
$_SERVER = [
    'REQUEST_METHOD' => 'POST',
    'HTTP_HOST' => 'mkultra.monster',
    'HTTP_DATE' => $date,
    'HTTP_DIGEST' => $digest,
    'REQUEST_URI' => '/api/ap-inbox.php',
];
$_SERVER['HTTP_SIGNATURE'] = sign_headers($classic, $privPem, $keyId, '(request-target) host date digest');
$fetches = 0;
$GLOBALS['ap_inbox_origin_fetch'] = static function () use (&$fetches): array {
    $fetches++;
    return ['status' => 500, 'doc' => null];
};
$selected = ap_inbox_select($postedBody);
check(is_array($selected) && $selected['via'] === 'signature' && ($selected['activity']['object']['content'] ?? '') === 'posted-body', 'valid classic signature keeps the posted body');
check($fetches === 0, 'valid signature does not fetch the origin');

// Mastodon 4.7 signs host, date, content-type, digest, then (request-target).
// Caddy/Cloudflare may present Host with :443, and php-fpm stores Content-Type
// only in CONTENT_TYPE.
$mastodon = "host: {$host}\ndate: {$date}\ncontent-type: {$ct}\ndigest: {$digest}\n(request-target): post /inbox";
$_SERVER = [
    'REQUEST_METHOD' => 'POST',
    'HTTP_HOST' => 'mkultra.monster:443',
    'HTTP_DATE' => $date,
    'HTTP_DIGEST' => $digest,
    'CONTENT_TYPE' => $ct,
    'REQUEST_URI' => '/api/ap-inbox.php',
    'HTTP_SIGNATURE' => sign_headers($mastodon, $privPem, $keyId, 'host date content-type digest (request-target)'),
];
$selected = ap_inbox_select($postedBody);
check(is_array($selected) && $selected['via'] === 'signature' && $selected['path'] === '/inbox', 'Mastodon 4.7 signature verifies behind a port-qualified host');
check($fetches === 0, 'Mastodon 4.7 signature does not fetch the origin');

// hs2019 created/expires, with the public host only in X-Forwarded-Host.
$created = (string) time();
$expires = (string) (time() + 300);
$hs = "(request-target): post /inbox\nhost: {$host}\n(created): {$created}\n(expires): {$expires}\ndigest: {$digest}";
openssl_sign($hs, $hsRaw, $privPem, OPENSSL_ALGO_SHA256);
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'mkultra.monster';
unset($_SERVER['CONTENT_TYPE']);
$_SERVER['HTTP_SIGNATURE'] = 'keyId="' . $keyId . '",algorithm="hs2019",headers="(request-target) host (created) (expires) digest",signature="' . base64_encode($hsRaw) . '",created=' . $created . ',expires=' . $expires;
$selected = ap_inbox_select($postedBody);
check(is_array($selected) && $selected['via'] === 'signature', 'hs2019 created/expires verifies against the forwarded host');

// RFC 9421 double-knock: Content-Digest and Signature-Input, no Digest.
$contentDigest = 'sha-256=:' . base64_encode(hash('sha256', $postedBody, true)) . ':';
$paramsRaw = '("@method" "@target-uri" "content-digest");created=' . $created . ';keyid="' . $keyId . '";alg="rsa-v1_5-sha256"';
$base = "\"@method\": POST\n\"@target-uri\": https://mkultra.monster/inbox\n\"content-digest\": {$contentDigest}\n\"@signature-params\": {$paramsRaw}";
openssl_sign($base, $rfcRaw, $privPem, OPENSSL_ALGO_SHA256);
$_SERVER = [
    'REQUEST_METHOD' => 'POST',
    'HTTP_HOST' => 'mkultra.monster:443',
    'HTTP_DATE' => $date,
    'HTTP_CONTENT_DIGEST' => $contentDigest,
    'HTTP_SIGNATURE' => 'sig1=:' . base64_encode($rfcRaw) . ':',
    'HTTP_SIGNATURE_INPUT' => 'sig1=' . $paramsRaw,
    'REQUEST_URI' => '/api/ap-inbox.php',
];
$selected = ap_inbox_select($postedBody);
check(is_array($selected) && $selected['via'] === 'signature' && $selected['path'] === '/inbox', 'RFC 9421 signature verifies without a legacy Digest');
check($fetches === 0, 'RFC 9421 signature does not fetch the origin');

// Bad signature, but the object id is on the claimed actor and the origin
// returns that actor's note. Process the fetched copy, not the spoofed body.
$origin = [
    'id' => $objectId,
    'type' => 'Note',
    'attributedTo' => $actor,
    'content' => 'origin-body',
];
$GLOBALS['ap_inbox_origin_mem'] = [];
$fetches = 0;
$GLOBALS['ap_inbox_origin_fetch'] = static function (string $url) use (&$fetches, $origin, $objectId): array {
    $fetches++;
    check($url === $objectId || str_ends_with($url, '/activity'), 'origin fetch stays on the claimed object');
    if ($url === $objectId) {
        return ['status' => 200, 'doc' => $origin];
    }
    return ['status' => 404, 'doc' => null];
};
$_SERVER['HTTP_SIGNATURE'] = 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . base64_encode('not-a-signature') . '"';
$_SERVER['HTTP_DIGEST'] = $digest;
unset($_SERVER['HTTP_SIGNATURE_INPUT'], $_SERVER['HTTP_CONTENT_DIGEST']);
$bad = json_decode($postedBody, true);
$bad['object']['content'] = 'spoofed-body';
$bad['id'] = $activityId;
$selected = ap_inbox_select(json_encode($bad, JSON_UNESCAPED_SLASHES));
check(is_array($selected) && $selected['via'] === 'origin', 'bad signature falls back to the origin');
check(($selected['activity']['object']['content'] ?? '') === 'origin-body', 'accepted activity is the fetched note');
check(($selected['activity']['type'] ?? '') === 'Create' && ($selected['activity']['actor'] ?? '') === $actor, 'wrapped activity keeps the claimed actor');
$once = $fetches;
$again = ap_inbox_select(json_encode($bad, JSON_UNESCAPED_SLASHES));
check(is_array($again) && ($again['activity']['object']['content'] ?? '') === 'origin-body' && $fetches === $once, 'repeat delivery does not refetch the same id');

// Spoofed body whose origin document belongs to someone else is rejected.
$GLOBALS['ap_inbox_origin_mem'] = [];
$GLOBALS['ap_inbox_origin_cache_dir'] = sys_get_temp_dir() . '/ap-inbox-origin-spoof-' . bin2hex(random_bytes(4));
$GLOBALS['ap_inbox_origin_fetch'] = static function () use ($objectId): array {
    return ['status' => 200, 'doc' => [
        'id' => $objectId,
        'type' => 'Note',
        'attributedTo' => 'https://example.com/users/bob',
        'content' => 'bob',
    ]];
};
$spoof = ap_inbox_select(json_encode($bad, JSON_UNESCAPED_SLASHES));
check($spoof === null, 'spoofed body with a mismatched origin actor is rejected');

// Delete of an object the origin says is gone.
$GLOBALS['ap_inbox_origin_mem'] = [];
$GLOBALS['ap_inbox_origin_cache_dir'] = sys_get_temp_dir() . '/ap-inbox-origin-delete-' . bin2hex(random_bytes(4));
$GLOBALS['ap_inbox_origin_fetch'] = static function (string $url) use ($objectId): array {
    check($url === $objectId, 'delete probes the object url');
    return ['status' => 410, 'doc' => null];
};
$delete = [
    'id' => $objectId . '#delete',
    'type' => 'Delete',
    'actor' => $actor,
    'object' => $objectId,
    'summary' => 'do-not-trust',
];
$selected = ap_inbox_select(json_encode($delete, JSON_UNESCAPED_SLASHES));
check(is_array($selected) && $selected['via'] === 'origin' && ($selected['activity']['type'] ?? '') === 'Delete', 'gone object accepts Delete');
check(($selected['activity']['actor'] ?? '') === $actor && ($selected['activity']['object'] ?? '') === $objectId, 'Delete keeps actor and object id');
check(!isset($selected['activity']['summary']), 'Delete does not keep unverified body fields');

// Delete while the origin still returns the note is not honored.
$GLOBALS['ap_inbox_origin_mem'] = [];
$GLOBALS['ap_inbox_origin_cache_dir'] = sys_get_temp_dir() . '/ap-inbox-origin-live-' . bin2hex(random_bytes(4));
$GLOBALS['ap_inbox_origin_fetch'] = static fn(): array => ['status' => 200, 'doc' => $origin];
check(ap_inbox_select(json_encode($delete, JSON_UNESCAPED_SLASHES)) === null, 'Delete of an object that still exists is rejected');

// Follow has no fetchable id on the claimed actor host.
$follow = ['type' => 'Follow', 'actor' => $actor, 'object' => 'https://mkultra.monster/users/cmdr_nova'];
check(ap_inbox_origin_urls($follow) === [], 'Follow without a same-host id is not fetched');
check(ap_inbox_origin_decision($follow, []) === null, 'Follow without a fetchable id stays unverified');

// Open circuit does not call the network.
$circuitHost = 'example.com';
$circuit = ap_remote_fetch_circuit_path($circuitHost);
file_put_contents($circuit, json_encode(['failures' => 3, 'open_until' => time() + 60]));
$blocked = ap_inbox_origin_fetch_live('https://example.com/users/alice/statuses/1');
check($blocked['status'] === 0 && $blocked['doc'] === null, 'open circuit skips the origin fetch');
@unlink($circuit);

// Valid signature from a different host must not keep the posted body.
// Origin confirmation of the claimed actor replaces it.
$GLOBALS['ap_inbox_origin_mem'] = [];
$GLOBALS['ap_inbox_origin_cache_dir'] = sys_get_temp_dir() . '/ap-inbox-origin-forward-' . bin2hex(random_bytes(4));
$GLOBALS['ap_inbox_origin_fetch'] = static function (string $url) use ($objectId, $actor): array {
    if ($url === $objectId) {
        return ['status' => 200, 'doc' => [
            'id' => $objectId,
            'type' => 'Note',
            'attributedTo' => $actor,
            'content' => 'origin-body',
        ]];
    }
    return ['status' => 404, 'doc' => null];
};
$forwarded = [
    'id' => $activityId,
    'type' => 'Create',
    'actor' => $actor,
    'object' => [
        'id' => $objectId,
        'type' => 'Note',
        'attributedTo' => $actor,
        'content' => 'spoofed-by-forwarder',
    ],
];
$reconciled = ap_inbox_reconcile_decision($forwarded, 'https://other.test/users/bob#main-key', false, [
    $activityId => ['status' => 404, 'doc' => null],
    $objectId => ['status' => 200, 'doc' => [
        'id' => $objectId,
        'type' => 'Note',
        'attributedTo' => $actor,
        'content' => 'origin-body',
    ]],
]);
check(is_array($reconciled) && ($reconciled['object']['content'] ?? '') === 'origin-body', 'forwarded Create is replaced by the origin note');
check(($reconciled['actor'] ?? '') === $actor, 'forwarded Create keeps the claimed actor');
$spoofForward = ap_inbox_reconcile_decision($forwarded, 'https://other.test/users/bob#main-key', false, [
    $activityId => ['status' => 200, 'doc' => [
        'id' => $activityId,
        'type' => 'Create',
        'actor' => 'https://example.com/users/bob',
        'object' => ['id' => $objectId, 'type' => 'Note', 'attributedTo' => 'https://example.com/users/bob', 'content' => 'bob'],
    ]],
]);
check($spoofForward === null, 'forwarded Create whose origin actor differs is rejected');

// Relays and followed forwarders: a Create is rebuilt from the origin copy of
// its object. Announce and same-signer deliveries keep the signed body.
$originNote = [
    'id' => $objectId,
    'type' => 'Note',
    'attributedTo' => $actor,
    'to' => ['https://www.w3.org/ns/activitystreams#Public'],
    'cc' => [$actor . '/followers'],
    'content' => 'origin-body',
];
$forwarded['to'] = ['https://www.w3.org/ns/activitystreams#Public'];
$forwarded['cc'] = [];
$forwarded['signature'] = ['type' => 'RsaSignature2017', 'creator' => $actor . '#main-key', 'signatureValue' => 'x'];
$relayKey = 'https://other.test/actor#main-key';
check(ap_inbox_forwarded_create_url($forwarded) === $objectId, 'forwarded Create fetches its object id');
$trusted = ap_inbox_reconcile_decision($forwarded, $relayKey, true, [$objectId => ['status' => 200, 'doc' => $originNote]]);
check(is_array($trusted) && ($trusted['object']['content'] ?? '') === 'origin-body', 'trusted forwarder Create is replaced by the origin note');
check(($trusted['actor'] ?? '') === $actor && ($trusted['id'] ?? '') === $activityId, 'rebuilt Create keeps actor and same-host id');
check(($trusted['cc'] ?? null) === [$actor . '/followers'], 'rebuilt Create takes addressing from the origin note');
check(!isset($trusted['signature']), 'rebuilt Create drops the forwarded LD signature');
check(ap_inbox_reconcile_decision($forwarded, $relayKey, true, []) === null, 'trusted forwarder Create without an origin copy is dropped');
check(ap_inbox_reconcile_decision($forwarded, $relayKey, true, [$objectId => ['status' => 404, 'doc' => null]]) === null, 'trusted forwarder Create whose object is gone is dropped');
check(ap_inbox_reconcile_decision($forwarded, $relayKey, true, [$objectId => ['status' => 200, 'doc' => ['attributedTo' => 'https://example.com/users/bob'] + $originNote]]) === null, 'origin note by another actor is dropped');
check(ap_inbox_reconcile_decision($forwarded, $relayKey, true, [$objectId => ['status' => 200, 'doc' => ['id' => 'https://other.test/notes/1'] + $originNote]]) === null, 'origin note whose id moved host is dropped');
check(ap_inbox_reconcile_decision($forwarded, $relayKey, true, [$objectId => ['status' => 200, 'doc' => ['type' => 'Tombstone', 'id' => $objectId]]]) === null, 'tombstoned origin note is dropped');
$multiAttr = ap_inbox_reconcile_decision($forwarded, $relayKey, true, [$objectId => ['status' => 200, 'doc' => ['attributedTo' => [
    ['type' => 'Person', 'id' => $actor],
    ['type' => 'Group', 'id' => 'https://example.com/channels/c'],
]] + $originNote]]);
check(is_array($multiAttr), 'origin note attributed to the actor and a group is accepted');
$offHost = ['object' => ['id' => 'https://other.test/notes/1', 'type' => 'Note', 'attributedTo' => $actor]] + $forwarded;
check(ap_inbox_forwarded_create_url($offHost) === null, 'object off the actor host is not fetched');
check(ap_inbox_reconcile_decision($offHost, $relayKey, true, []) === null, 'forwarded Create with an off-host object is dropped');
$announce = ['id' => 'https://example.com/users/alice/statuses/2/activity', 'type' => 'Announce', 'actor' => $actor, 'object' => 'https://elsewhere.example/notes/9'];
check(ap_inbox_reconcile_decision($announce, $relayKey, true, []) === $announce, 'trusted forwarder Announce keeps the signed body');
check(ap_inbox_reconcile_decision($forwarded, $keyId, false, []) === $forwarded, 'same-signer Create keeps the signed body');

// Live path: SSRF guard and the fetch hook.
$GLOBALS['ap_inbox_origin_mem'] = [];
$GLOBALS['ap_inbox_origin_cache_dir'] = sys_get_temp_dir() . '/ap-inbox-origin-refetch-' . bin2hex(random_bytes(4));
$refetchCalls = [];
$GLOBALS['ap_inbox_origin_fetch'] = static function (string $url) use (&$refetchCalls, $objectId, $originNote): array {
    $refetchCalls[] = $url;
    return $url === $objectId ? ['status' => 200, 'doc' => $originNote] : ['status' => 404];
};
$live = ap_inbox_refetch_forwarded_create($forwarded);
check(is_array($live) && ($live['object']['content'] ?? '') === 'origin-body' && $refetchCalls === [$objectId], 'forwarded Create is refetched from its origin');
$local = ['actor' => 'https://127.0.0.1/users/a', 'object' => ['id' => 'https://127.0.0.1/users/a/statuses/1', 'type' => 'Note']] + $forwarded;
check(ap_inbox_refetch_forwarded_create($local) === null && count($refetchCalls) === 1, 'non-public origin is not fetched');

// Captured production 401s. Signatures and public keys are public.
$fixturePath = dirname(__DIR__) . '/fixtures/inbox-401-signatures.json';
$fixture = json_decode((string) file_get_contents($fixturePath), true);
check(is_array($fixture), 'load captured signature fixture');
foreach (['peertube', 'sauropods', 'foone'] as $id) {
    $case = $fixture[$id];
    $_SERVER = $case['server'];
    $rawSig = base64_decode($case['signature'], true);
    $path = is_string($rawSig) ? ap_verify_http_signature($case['headers'], '', $rawSig, $case['pem'], ['created' => null, 'expires' => null]) : null;
    check($path === '/inbox', $case['name'] . ' verifies for /inbox');
}
$rfc = $fixture['rfc'];
$_SERVER = [
    'REQUEST_METHOD' => 'POST',
    'HTTP_HOST' => 'mkultra.monster',
    'REQUEST_URI' => '/inbox',
    'HTTP_CONTENT_DIGEST' => $rfc['digest'],
    'HTTP_SIGNATURE' => $rfc['signature'],
    'HTTP_SIGNATURE_INPUT' => $rfc['input'],
];
$rfcParsed = ap_inbox_parse_signature_input($rfc['input']);
check(is_array($rfcParsed) && !isset($rfcParsed['params']['alg']), 'Mastodon RFC 9421 Signature-Input omits alg');
if (!is_array($rfcParsed) || !preg_match('/sig1\s*=\s*:([A-Za-z0-9+\/=]+):/', $rfc['signature'], $rfcMatch)) {
    check(false, 'RFC signature bytes');
} else {
    $rfcPath = ap_inbox_verify_rfc9421_base($rfcParsed, base64_decode($rfcMatch[1], true) ?: '', $rfc['pem'], $rfc['digest']);
    check($rfcPath === '/inbox', 'Mastodon RFC 9421 double-knock verifies for https://mkultra.monster/inbox');
}

echo "inbox-signature: {$GLOBALS['n']} checks passed\n";
