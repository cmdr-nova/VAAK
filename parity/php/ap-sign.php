<?php
/**
 * Outbound HTTP signatures for the Rust signer (rust/vaak-worker/src/ap_sign.rs).
 * Builds each Signature header the way ap_signed_get_ex (GET) and
 * ap_deliver_signed_json (POST) do, with keys generated in this process and a
 * fixed Date, so Rust must produce the same bytes. No network, no database.
 */
declare(strict_types=1);

$keys = [];
foreach ([2048, 4096] as $bits) {
    $res = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $pem);
    $keys[$bits] = $pem;
}
$keyId = 'https://mkultra.monster/users/cmdr_nova#main-key';
$date = gmdate('D, d M Y H:i:s', 1793689200) . ' GMT';

$cases = [];
$add = static function (string $id, string $method, string $url, int $bits, string $body = '') use (&$cases, $keys, $keyId, $date): void {
    $parts = parse_url($url);
    $host = (string) ($parts['host'] ?? '');
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }
    if (!empty($parts['query'])) {
        $path .= '?' . $parts['query'];
    }
    $priv = openssl_pkey_get_private($keys[$bits]);
    if ($method === 'post') {
        $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
        $signing = "(request-target): post {$path}\nhost: {$host}\ndate: {$date}\ndigest: {$digest}";
        openssl_sign($signing, $raw, $priv, OPENSSL_ALGO_SHA256);
        $output = [
            'signature' => sprintf('keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="%s"', $keyId, base64_encode($raw)),
            'digest' => $digest,
        ];
    } else {
        $signing = "(request-target): get {$path}\nhost: {$host}\ndate: {$date}";
        openssl_sign($signing, $raw, $priv, OPENSSL_ALGO_SHA256);
        $output = ['signature' => sprintf('keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date",signature="%s"', $keyId, base64_encode($raw))];
    }
    $cases[] = [
        'id' => $id,
        'input' => ['key_pem' => $keys[$bits], 'key_id' => $keyId, 'method' => $method, 'url' => $url, 'date' => $date, 'body' => $body],
        'output' => $output,
    ];
};

$add('get-actor', 'get', 'https://mastodon.example/users/alice', 2048);
$add('get-key-fragment', 'get', 'https://mastodon.example/users/alice#main-key', 2048);
$add('get-query', 'get', 'https://threads.example/ap/users/1/?x=1&y=2', 2048);
$add('get-bare-host', 'get', 'https://Example.COM', 4096);
$add('post-inbox', 'post', 'https://mastodon.example/inbox', 2048, '{"type":"Like","actor":"https://mkultra.monster/users/cmdr_nova"}');
$add('post-unicode', 'post', 'https://a.example/users/b/inbox', 4096, '{"content":"héllo ✨"}');

echo json_encode(['cases' => $cases], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
