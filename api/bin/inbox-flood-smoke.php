<?php
/**
 * Inbox client address, per-IP admission, and pre-verification fetch budgets.
 * No database, no Redis, no network. Rate counters go to throwaway buckets.
 */
declare(strict_types=1);

putenv('VAAK_REDIS_ENABLED=0');
putenv('VAAK_TRUSTED_PROXIES');
define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__) . '/ap-inbox.php';

$GLOBALS['n'] = 0;
function check(bool $ok, string $why): void
{
    if (!$ok) {
        fwrite(STDERR, "inbox-flood: {$why}\n");
        exit(1);
    }
    $GLOBALS['n']++;
}

ap_block_list_cached(false, []);
$run = bin2hex(random_bytes(4));
$made = [];
register_shutdown_function(static function () use (&$made): void {
    foreach ($made as $path) {
        @unlink($path);
    }
});
$track = static function (string $bucket) use (&$made): string {
    $safe = preg_replace('/[^a-zA-Z0-9:._-]/', '_', $bucket) ?: 'unknown';
    $made[] = AP_INBOX_RATE_DIR . '/' . $safe . '.json';
    return $bucket;
};

function client_ip_for(string $remote, ?string $xff): string
{
    $_SERVER['REMOTE_ADDR'] = $remote;
    if ($xff === null) {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    } else {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
    }
    return ap_client_ip();
}

// Client address.
check(client_ip_for('198.51.100.7', '203.0.113.9') === '198.51.100.7', 'untrusted peer ignores X-Forwarded-For');
check(client_ip_for('198.51.100.7', null) === '198.51.100.7', 'untrusted peer without header');
check(client_ip_for('127.0.0.1', null) === '127.0.0.1', 'loopback peer without header');
check(client_ip_for('127.0.0.1', '203.0.113.9') === '203.0.113.9', 'loopback proxy forwards the client');
check(client_ip_for('127.0.0.1', '203.0.113.9, 198.51.100.7') === '198.51.100.7', 'right-most untrusted hop wins');
check(client_ip_for('127.0.0.1', '203.0.113.9, 198.51.100.7, 127.0.0.1') === '198.51.100.7', 'trusted hops are skipped');
check(client_ip_for('127.0.0.1', 'junk, 198.51.100.7') === '198.51.100.7', 'garbage left of the real hop is ignored');
check(client_ip_for('127.0.0.1', '198.51.100.7, junk') === '127.0.0.1', 'garbage appended by a trusted hop stops the walk');
check(client_ip_for('::1', '2001:db8::5') === '2001:db8::5', 'ipv6 loopback proxy');
check(client_ip_for('::ffff:127.0.0.1', '203.0.113.9') === '203.0.113.9', 'mapped loopback proxy');
check(client_ip_for('not-an-ip', '203.0.113.9') === '0.0.0.0', 'bad REMOTE_ADDR');

putenv('VAAK_TRUSTED_PROXIES=10.0.0.0/8, 2001:db8:1::/48');
check(client_ip_for('10.1.2.3', '203.0.113.9, 10.9.9.9') === '203.0.113.9', 'configured proxy range');
check(client_ip_for('127.0.0.1', '203.0.113.9') === '127.0.0.1', 'configured list replaces the loopback default');
check(client_ip_for('2001:db8:1:5::1', '198.51.100.7') === '198.51.100.7', 'configured ipv6 range');
check(client_ip_for('2001:db8:2::1', '198.51.100.7') === '2001:db8:2::1', 'outside the ipv6 range');
putenv('VAAK_TRUSTED_PROXIES');

check(ap_client_ip_in_cidr('192.168.5.9', '192.168.4.0/23'), 'cidr /23 match');
check(!ap_client_ip_in_cidr('192.168.6.1', '192.168.4.0/23'), 'cidr /23 miss');
check(ap_client_ip_in_cidr('192.0.2.1', '192.0.2.1'), 'bare address');
check(!ap_client_ip_in_cidr('192.0.2.1', '::/0'), 'family mismatch');
check(!ap_client_ip_in_cidr('192.0.2.1', '192.0.2.0/33'), 'prefix too long');

// Check-only rate limit does not spend.
$b = $track('smoke_charge_' . $run);
check(ap_rate_limit($b, 2, 60), 'first hit');
check(ap_rate_limit($b, 2, 60, false), 'peek with room');
check(ap_rate_limit($b, 2, 60, false), 'peek did not spend');
check(ap_rate_limit($b, 2, 60), 'second hit');
check(!ap_rate_limit($b, 2, 60, false), 'peek when full');

// A relay keyId is admitted on the relay bucket while the normal bucket has
// room, and is refused once failed requests have spent the normal bucket.
$ip = 'smoke-ip-' . $run;
$track($ip);
$track('relay_' . $ip);
check(ap_inbox_rate_admit($ip, true), 'relay claim admitted');
for ($i = 0; $i < AP_INBOX_RATE_MAX; $i++) {
    ap_rate_limit($ip, AP_INBOX_RATE_MAX);
}
check(!ap_inbox_rate_admit($ip, false), 'normal bucket spent');
check(!ap_inbox_rate_admit($ip, true), 'relay claim refused once the normal bucket is spent');

// Key fetch budget.
$keyId = 'https://budget-' . $run . '.example/users/a#main-key';
for ($i = 0; $i < AP_INBOX_KEY_FETCH_MAX_PER_KEY; $i++) {
    $GLOBALS['ap_inbox_fetch_throttled'] = false;
    check(ap_inbox_key_fetch_budget_take($keyId), "key budget {$i}");
}
check(!ap_inbox_key_fetch_budget_take($keyId), 'key budget spent');
check(!empty($GLOBALS['ap_inbox_fetch_throttled']), 'throttle flag set');
check(!ap_inbox_key_fetch_budget_take(preg_replace('/#.*$/', '#other', $keyId)), 'fragment does not reset the key budget');
check(ap_inbox_key_fetch_budget_take('https://budget-' . $run . '.example/users/b#main-key'), 'other key on the host still has budget');
foreach (['key:https://budget-' . $run . '.example/users/a', 'key:https://budget-' . $run . '.example/users/b', 'keyhost:budget-' . $run . '.example'] as $bucket) {
    $track('fetch_' . hash('sha256', 'inbox-fetch:' . $bucket));
}

// A signed POST whose key would need a fetch over budget gets 429, without
// any network, and does not fall through to origin recovery.
$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
check($res !== false, 'generate rsa key');
openssl_pkey_export($res, $privPem);
$pem = openssl_pkey_get_details($res)['key'];
$other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$otherPem = openssl_pkey_get_details($other)['key'];
$originCalls = 0;
$GLOBALS['ap_inbox_origin_fetch'] = static function () use (&$originCalls): array {
    $originCalls++;
    return ['status' => 404];
};
$GLOBALS['ap_inbox_origin_cache_dir'] = sys_get_temp_dir() . '/ap-inbox-origin-flood-' . $run;

function signed_post(string $keyId, string $privPem, string $body): void
{
    $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    $date = gmdate('D, d M Y H:i:s') . ' GMT';
    $signing = "(request-target): post /inbox\nhost: mkultra.monster\ndate: {$date}\ndigest: {$digest}";
    openssl_sign($signing, $raw, $privPem, OPENSSL_ALGO_SHA256);
    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/inbox',
        'REMOTE_ADDR' => '198.51.100.7',
        'HTTP_HOST' => 'mkultra.monster',
        'HTTP_DATE' => $date,
        'HTTP_DIGEST' => $digest,
        'HTTP_SIGNATURE' => 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . base64_encode($raw) . '"',
    ];
}

$host = 'throttle-' . $run . '.example';
$actor = 'https://' . $host . '/users/a';
$throttledKey = $actor . '#main-key';
$body = json_encode([
    'id' => $actor . '/statuses/1/activity',
    'type' => 'Create',
    'actor' => $actor,
    'object' => ['id' => $actor . '/statuses/1', 'type' => 'Note', 'attributedTo' => $actor],
], JSON_UNESCAPED_SLASHES);
foreach (['key:' . $actor, 'keyhost:' . $host] as $bucket) {
    $track('fetch_' . hash('sha256', 'inbox-fetch:' . $bucket));
}
for ($i = 0; $i < AP_INBOX_KEY_FETCH_MAX_PER_KEY; $i++) {
    ap_inbox_key_fetch_budget_take($throttledKey);
}
signed_post($throttledKey, $privPem, $body);
check(ap_inbox_select($body) === null, 'over-budget key fetch is refused');
check((int) $GLOBALS['ap_inbox_verify_code'] === 429, 'over-budget key fetch is 429');
check($originCalls === 0, 'over-budget key fetch skips origin recovery');

// A cached key survives a failed signature whose refetch is over budget.
$cacheFile = AP_INBOX_KEY_CACHE_DIR . '/' . hash('sha256', $throttledKey) . '.pem';
@mkdir(AP_INBOX_KEY_CACHE_DIR, 0750, true);
file_put_contents($cacheFile, $otherPem);
$made[] = $cacheFile;
signed_post($throttledKey, $privPem, $body);
check(ap_inbox_select($body) === null, 'bad signature with refetch over budget is refused');
check((int) $GLOBALS['ap_inbox_verify_code'] === 429, 'bad signature with refetch over budget is 429');
check(is_file($cacheFile) && file_get_contents($cacheFile) === $otherPem, 'cached key is kept');

// A good signature against the cached key still verifies with no budget left.
signed_post($throttledKey, (static function () use ($other): string {
    openssl_pkey_export($other, $out);
    return $out;
})(), $body);
$ok = ap_inbox_select($body);
check(is_array($ok) && $ok['via'] === 'signature', 'cached key verifies with the budget spent');

// Origin recovery of an unverified body is budgeted per claimed actor host.
$originHost = 'origin-' . $run . '.example';
$originActor = 'https://' . $originHost . '/users/a';
$track('fetch_' . hash('sha256', 'inbox-fetch:origin:' . $originHost));
for ($i = 0; $i < AP_INBOX_ORIGIN_FETCH_MAX_PER_HOST; $i++) {
    ap_inbox_fetch_budget_take([['origin:' . $originHost, AP_INBOX_ORIGIN_FETCH_MAX_PER_HOST]]);
}
$GLOBALS['ap_inbox_fetch_throttled'] = false;
$recovered = ap_inbox_recover_unverified([
    'id' => $originActor . '/statuses/1/activity',
    'type' => 'Create',
    'actor' => $originActor,
    'object' => $originActor . '/statuses/1',
]);
check($recovered === null && !empty($GLOBALS['ap_inbox_fetch_throttled']), 'origin recovery over budget is refused');
check($originCalls === 0, 'origin recovery over budget does not fetch');

fwrite(STDOUT, "inbox-flood: {$GLOBALS['n']} checks passed\n");
