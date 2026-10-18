<?php
/**
 * Outbound fetch guard shared by every remote URL fetch.
 *
 * A host is public only when every A and AAAA answer is a public unicast
 * address. IPv6 forms that carry an IPv4 address (mapped, compatible, NAT64,
 * 6to4) are judged by that IPv4 address. Unresolvable hosts are not public.
 */
declare(strict_types=1);

function ap_net_ip_is_public(string $ip): bool
{
    $ip = trim($ip, " \t[]");
    $pct = strpos($ip, '%');
    if ($pct !== false) {
        $ip = substr($ip, 0, $pct);
    }
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    if (strlen($bin) === 16) {
        $embedded = null;
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($bin, str_repeat("\0", 12))) {
            $embedded = substr($bin, 12, 4); // ::ffff:a.b.c.d and ::a.b.c.d
        } elseif (str_starts_with($bin, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) {
            $embedded = substr($bin, 12, 4); // NAT64 64:ff9b::/96
        } elseif (str_starts_with($bin, "\x20\x02")) {
            $embedded = substr($bin, 2, 4); // 6to4 2002::/16
        }
        if ($embedded !== null) {
            return ap_net_ip_is_public((string) inet_ntop($embedded));
        }
        $first = ord($bin[0]);
        $second = ord($bin[1]);
        if ($first === 0xff) {
            return false; // multicast
        }
        if ($first === 0xfe && ($second & 0xc0) === 0xc0) {
            return false; // fec0::/10 site-local
        }
        if ($first === 0x01 && $second === 0x00 && substr($bin, 2, 6) === str_repeat("\0", 6)) {
            return false; // 100::/64 discard
        }
    } else {
        $n = unpack('N', $bin)[1];
        $blocked = [
            ['0.0.0.0', 8], ['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15],
            ['224.0.0.0', 4], ['240.0.0.0', 4],
        ];
        foreach ($blocked as [$net, $bits]) {
            $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xffffffff;
            if (($n & $mask) === (unpack('N', (string) inet_pton($net))[1] & $mask)) {
                return false;
            }
        }
    }
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

function ap_net_host_is_public(string $host): bool
{
    $host = strtolower(trim($host, " \t[]."));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return ap_net_ip_is_public($host);
    }
    static $memo = [];
    if (isset($memo[$host]) && $memo[$host][1] > time()) {
        return $memo[$host][0];
    }
    $ips = [];
    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $rec) {
            $ip = $rec['ip'] ?? $rec['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }
    }
    if ($ips === []) {
        $fallback = @gethostbynamel($host);
        if (is_array($fallback)) {
            $ips = $fallback;
        }
    }
    $ok = $ips !== [];
    foreach ($ips as $ip) {
        if (!ap_net_ip_is_public($ip)) {
            $ok = false;
            break;
        }
    }
    $memo[$host] = [$ok, time() + 60];
    return $ok;
}
