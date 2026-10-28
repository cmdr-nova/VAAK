<?php
/**
 * Inbox signature verdicts for the Rust shadow verifier (cutover F1).
 * Each case signs a request with keys generated in this process, runs the
 * real ap_inbox_try_verify, and prints the shadow record PHP would push with
 * PHP's verdict. Private keys are never printed or written.
 */
declare(strict_types=1);
define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__, 2) . '/api/ap-inbox.php';

ap_block_list_cached(false, []);

function sig_key(int $bits): array
{
    $res = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $priv);
    return [$priv, openssl_pkey_get_details($res)['key']];
}

function sig_sign(string $signing, string $priv): string
{
    openssl_sign($signing, $raw, $priv, OPENSSL_ALGO_SHA256);
    return base64_encode($raw);
}

[$priv, $pub] = sig_key(2048);
[$otherPriv] = sig_key(2048);
[$legacyPriv, $legacyPub] = sig_key(1024);

$actor = 'https://example.com/users/alice';
$keyId = $actor . '#main-key';
$body = json_encode([
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => $actor . '/statuses/1/activity',
    'type' => 'Create',
    'actor' => $actor,
    'object' => ['id' => $actor . '/statuses/1', 'type' => 'Note', 'attributedTo' => $actor, 'content' => 'hi'],
], JSON_UNESCAPED_SLASHES);
$now = time();
$date = gmdate('D, d M Y H:i:s', $now) . ' GMT';
$oldDate = gmdate('D, d M Y H:i:s', $now - 7200) . ' GMT';
$digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
$contentDigest = 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
$ct = 'application/activity+json';
$host = 'mkultra.monster';

function cavage(string $keyId, string $headers, string $signature, string $algo = 'rsa-sha256', string $extra = ''): string
{
    return 'keyId="' . $keyId . '",algorithm="' . $algo . '",headers="' . $headers . '",signature="' . $signature . '"' . $extra;
}

$base = [
    'REQUEST_METHOD' => 'POST',
    'HTTP_HOST' => $host,
    'HTTP_DATE' => $date,
    'HTTP_DIGEST' => $digest,
    'REQUEST_URI' => '/api/ap-inbox.php',
];
$classic = "(request-target): post /inbox\nhost: {$host}\ndate: {$date}\ndigest: {$digest}";
$classicHeaders = '(request-target) host date digest';

$cases = [];
$add = static function (string $id, array $server, ?string $pem = null, string $raw = '', ?string $actorKey = null) use (&$cases, $pub, $body): void {
    $_SERVER = $server;
    $pemUsed = func_num_args() >= 3 ? $pem : $pub;
    $GLOBALS['ap_inbox_pubkey_override'] = static fn(): ?string => $pemUsed;
    unset($GLOBALS['ap_req_actor']);
    if ($actorKey !== null) {
        $GLOBALS['ap_req_actor'] = ['key' => $actorKey];
    }
    $verified = ap_inbox_try_verify($raw === '' ? $body : $raw);
    $payload = ap_inbox_sig_shadow_payload($raw === '' ? $body : $raw, $verified);
    $cases[] = ['id' => $id, 'input' => $payload, 'output' => $payload['php']];
};

$add('cavage-classic', $base + ['HTTP_SIGNATURE' => cavage($keyId, $classicHeaders, sig_sign($classic, $priv))]);

$mastodon = "host: {$host}\ndate: {$date}\ncontent-type: {$ct}\ndigest: {$digest}\n(request-target): post /inbox";
$add('mastodon-port-host', array_merge($base, [
    'HTTP_HOST' => 'mkultra.monster:443',
    'CONTENT_TYPE' => $ct,
    'HTTP_SIGNATURE' => cavage($keyId, 'host date content-type digest (request-target)', sig_sign($mastodon, $priv)),
]));

$add('charset-stripped', array_merge($base, [
    'CONTENT_TYPE' => $ct . '; charset=utf-8',
    'HTTP_SIGNATURE' => cavage($keyId, 'host date content-type digest (request-target)', sig_sign($mastodon, $priv)),
]));

$personal = "(request-target): post /users/cmdr_nova/inbox/\nhost: {$host}\ndate: {$date}\ndigest: {$digest}";
$add('personal-inbox-slash', array_merge($base, [
    'REQUEST_URI' => '/users/cmdr_nova/inbox/',
    'HTTP_SIGNATURE' => cavage($keyId, $classicHeaders, sig_sign($personal, $priv)),
]));

$juno = "(request-target): post /users/juno_flux/inbox\nhost: {$host}\ndate: {$date}\ndigest: {$digest}";
$add('request-actor-inbox', $base + ['HTTP_SIGNATURE' => cavage($keyId, $classicHeaders, sig_sign($juno, $priv))], $pub, '', 'juno_flux');

$created = (string) $now;
$expires = (string) ($now + 300);
$hs = "(request-target): post /inbox\nhost: {$host}\n(created): {$created}\n(expires): {$expires}\ndigest: {$digest}";
$add('hs2019-forwarded-host', array_merge($base, [
    'HTTP_HOST' => '127.0.0.1',
    'HTTP_X_FORWARDED_HOST' => $host,
    'HTTP_SIGNATURE' => cavage($keyId, '(request-target) host (created) (expires) digest', sig_sign($hs, $priv), 'hs2019', ',created=' . $created . ',expires=' . $expires),
]));

$stale = (string) ($now - 7300);
$hsOld = "(request-target): post /inbox\nhost: {$host}\n(created): {$stale}\ndigest: {$digest}";
$add('hs2019-expired', $base + [
    'HTTP_SIGNATURE' => cavage($keyId, '(request-target) host (created) digest', sig_sign($hsOld, $priv), 'hs2019', ',created=' . $stale),
]);

$add('wrong-key', $base + ['HTTP_SIGNATURE' => cavage($keyId, $classicHeaders, sig_sign($classic, $otherPriv))]);
$add('wrong-path', $base + ['HTTP_SIGNATURE' => cavage($keyId, $classicHeaders, sig_sign(str_replace('/inbox', '/outbox', $classic), $priv))]);
$add('digest-mismatch', array_merge($base, [
    'HTTP_DIGEST' => 'SHA-256=' . base64_encode(hash('sha256', 'other', true)),
    'HTTP_SIGNATURE' => cavage($keyId, $classicHeaders, sig_sign($classic, $priv)),
]));
$add('date-out-of-range', array_merge($base, [
    'HTTP_DATE' => $oldDate,
    'HTTP_SIGNATURE' => cavage($keyId, $classicHeaders, sig_sign($classic, $priv)),
]));
$noDigest = $base;
unset($noDigest['HTTP_DIGEST']);
$add('missing-digest', $noDigest + ['HTTP_SIGNATURE' => cavage($keyId, $classicHeaders, sig_sign($classic, $priv))]);
$add('malformed-signature', $base + ['HTTP_SIGNATURE' => 'keyId="' . $keyId . '",signature="abc"']);
$add('unsupported-algorithm', $base + ['HTTP_SIGNATURE' => cavage($keyId, $classicHeaders, sig_sign($classic, $priv), 'ed25519')]);
$add('signed-header-missing', $base + ['HTTP_SIGNATURE' => cavage($keyId, $classicHeaders . ' accept', sig_sign($classic . "\naccept: x", $priv))]);
$withAccept = "(request-target): post /inbox\nhost: {$host}\naccept: application/activity+json\ndate: {$date}\ndigest: {$digest}";
$add('extra-signed-header', $base + [
    'HTTP_ACCEPT' => 'application/activity+json',
    'HTTP_SIGNATURE' => cavage($keyId, '(request-target) host accept date digest', sig_sign($withAccept, $priv)),
]);
$add('no-key', $base + ['HTTP_SIGNATURE' => cavage('http://example.com/users/alice#main-key', $classicHeaders, sig_sign($classic, $priv))], null);
$add('legacy-1024-bit', $base + ['HTTP_SIGNATURE' => cavage($keyId, $classicHeaders, sig_sign($classic, $legacyPriv))], $legacyPub);

$paramsRaw = '("@method" "@target-uri" "content-digest");created=' . $created . ';keyid="' . $keyId . '";alg="rsa-v1_5-sha256"';
$rfcBase = "\"@method\": POST\n\"@target-uri\": https://mkultra.monster/inbox\n\"content-digest\": {$contentDigest}\n\"@signature-params\": {$paramsRaw}";
$rfc = [
    'REQUEST_METHOD' => 'POST',
    'HTTP_HOST' => 'mkultra.monster:443',
    'HTTP_DATE' => $date,
    'HTTP_CONTENT_DIGEST' => $contentDigest,
    'HTTP_SIGNATURE_INPUT' => 'sig1=' . $paramsRaw,
    'REQUEST_URI' => '/api/ap-inbox.php',
];
$add('rfc9421-content-digest', $rfc + ['HTTP_SIGNATURE' => 'sig1=:' . sig_sign($rfcBase, $priv) . ':']);
$add('rfc9421-wrong-key', $rfc + ['HTTP_SIGNATURE' => 'sig1=:' . sig_sign($rfcBase, $otherPriv) . ':']);
$add('rfc9421-label-missing', $rfc + ['HTTP_SIGNATURE' => 'sig2=:' . sig_sign($rfcBase, $priv) . ':']);
$oldParams = '("@method" "@target-uri" "content-digest");created=' . $stale . ';keyid="' . $keyId . '"';
$add('rfc9421-stale-created', array_merge($rfc, [
    'HTTP_SIGNATURE_INPUT' => 'sig1=' . $oldParams,
    'HTTP_SIGNATURE' => 'sig1=:' . sig_sign($rfcBase, $priv) . ':',
]));
$add('rfc9421-digest-mismatch', array_merge($rfc, [
    'HTTP_CONTENT_DIGEST' => 'sha-256=:' . base64_encode(hash('sha256', 'other', true)) . ':',
    'HTTP_SIGNATURE' => 'sig1=:' . sig_sign($rfcBase, $priv) . ':',
]));

echo json_encode(['cases' => $cases], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
