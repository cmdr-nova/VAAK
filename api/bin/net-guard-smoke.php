<?php
/**
 * Outbound fetch guard: private, loopback, metadata and IPv4-in-IPv6 forms
 * are never public, and the addresses that pass are what curl gets pinned to.
 * No network: only literal addresses and names that cannot resolve are
 * checked. bin/net-guard-pin-smoke.php covers pinning and redirects.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/ap-net-guard.php';

$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void {
    if (!$ok) {
        fwrite(STDERR, "net-guard: {$why}\n");
        exit(1);
    }
    $n++;
};

$public = ['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '::ffff:8.8.8.8', '2002:808:808::1'];
$private = [
    '127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1',
    '100.100.100.200', '198.18.0.1', '192.0.0.1', '0.0.0.0', '224.0.0.1', '255.255.255.255',
    '::', '::1', '[::1]', 'fc00::1', 'fd12::1', 'fe80::1', 'fe80::1%eth0', 'fec0::1', 'ff02::1',
    '::ffff:127.0.0.1', '[::ffff:127.0.0.1]', '::ffff:10.0.0.1', '::127.0.0.1',
    '64:ff9b::7f00:1', '64:ff9b::a9fe:a9fe', '2002:7f00:1::', '2002:a9fe:a9fe::', '2001:db8::1', '2001:10::1', 'not-an-ip',
];
foreach ($public as $ip) {
    $check(ap_net_ip_is_public($ip), "{$ip} is public");
}
foreach ($private as $ip) {
    $check(!ap_net_ip_is_public($ip), "{$ip} is not public");
}
foreach (['localhost', 'foo.localhost', 'printer.local', 'db.internal', '127.0.0.1', '[::ffff:127.0.0.1]', '', 'no-such-host.invalid'] as $host) {
    $check(!ap_net_host_is_public($host), "host {$host} is not public");
}
$check(ap_net_host_is_public('8.8.8.8'), 'literal public host');

// Vetted addresses and the CURLOPT_RESOLVE entries built from them.
$check(ap_net_host_public_ips('8.8.8.8') === ['8.8.8.8'], 'literal public host returns itself');
$check(ap_net_host_public_ips('[2606:4700:4700::1111]') === ['2606:4700:4700::1111'], 'bracketed IPv6 literal');
foreach (['127.0.0.1', 'localhost', 'db.internal', 'no-such-host.invalid', '[::ffff:10.0.0.1]'] as $host) {
    $check(ap_net_host_public_ips($host) === null, "no addresses for {$host}");
}
$check(ap_net_resolve_entry('https://8.8.8.8/x') === '', 'literal URL needs no pin');
$check(ap_net_resolve_entry('https://[2606:4700:4700::1111]:8443/') === '', 'IPv6 literal URL needs no pin');
foreach (['https://127.0.0.1/', 'http://[::1]:8080/', 'https://169.254.169.254/latest', 'ftp://8.8.8.8/',
    'gopher://8.8.8.8/', 'https:///nohost', 'https://no-such-host.invalid/'] as $url) {
    $check(ap_net_resolve_entry($url) === null, "refused {$url}");
}
$check(ap_net_format_resolve('example.org', 443, ['93.184.215.14']) === 'example.org:443:93.184.215.14', 'IPv4 entry');
$check(ap_net_format_resolve('example.org', 80, ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'])
    === 'example.org:80:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]', 'IPv6 addresses are bracketed');
$ch = curl_init();
$check(!ap_net_curl_pin($ch, 'https://127.0.0.1/'), 'pin refuses a private literal');
$check(!ap_net_curl_pin($ch, 'https://localhost/'), 'pin refuses localhost');
$check(ap_net_curl_pin($ch, 'https://8.8.8.8/'), 'pin accepts a public literal');
$check(ap_net_curl_exec($ch, 'http://127.0.0.1:9/') === false && ap_net_last_refusal() === 'host is not public', 'exec refuses before connecting');
curl_close($ch);

echo "net-guard-smoke: {$n} checks ok\n";
