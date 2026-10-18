<?php
/**
 * Outbound fetch guard: private, loopback, metadata and IPv4-in-IPv6 forms
 * are never public. No network: only literal addresses and names that cannot
 * resolve are checked.
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
    '64:ff9b::7f00:1', '64:ff9b::a9fe:a9fe', '2002:7f00:1::', '2002:a9fe:a9fe::', 'not-an-ip',
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

echo "net-guard-smoke: {$n} checks ok\n";
