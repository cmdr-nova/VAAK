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

echo "inbox-signature: {$GLOBALS['n']} checks passed\n";
