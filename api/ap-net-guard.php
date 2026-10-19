<?php
/**
 * Outbound fetch guard shared by every remote URL fetch.
 *
 * A host is public only when every A and AAAA answer is a public unicast
 * address. IPv6 forms that carry an IPv4 address (mapped, compatible, NAT64,
 * 6to4) are judged by that IPv4 address. Unresolvable hosts are not public.
 *
 * Checking a name and then letting curl resolve it again leaves a gap: the
 * second answer can differ from the one that was checked. Callers that fetch
 * pin the handle to the vetted addresses with ap_net_curl_pin(), or use
 * ap_net_curl_exec(), which also re-checks and re-pins every redirect hop.
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
    return ap_net_host_public_ips($host) !== null;
}

/**
 * The addresses $host resolves to, when every one of them is public.
 * Null when the name is refused, unresolvable, or has any non-public answer.
 * A literal address is returned as itself.
 *
 * @return list<string>|null
 */
function ap_net_host_public_ips(string $host): ?array
{
    $host = strtolower(trim($host, " \t[]."));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        return null;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return ap_net_ip_is_public($host) ? [$host] : null;
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
    $ips = array_values(array_unique($ips));
    $ok = $ips !== [];
    foreach ($ips as $ip) {
        if (!ap_net_ip_is_public($ip)) {
            $ok = false;
            break;
        }
    }
    $memo[$host] = [$ok ? $ips : null, time() + 60];
    return $memo[$host][0];
}

/**
 * CURLOPT_RESOLVE entry ("host:port:addr[,addr]") for a URL, or null when the
 * URL is not http(s) or its host is not public. A literal-address URL gives
 * an empty string: curl connects to it without resolving.
 */
function ap_net_resolve_entry(string $url): ?string
{
    $parts = parse_url($url);
    if (!is_array($parts) || !is_string($parts['host'] ?? null) || $parts['host'] === '') {
        return null;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== 'https' && $scheme !== 'http') {
        return null;
    }
    $host = strtolower(trim($parts['host'], '[]'));
    $ips = ap_net_host_public_ips($host);
    if ($ips === null) {
        return null;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return '';
    }
    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    return ap_net_format_resolve($host, $port, $ips);
}

/** @param list<string> $ips */
function ap_net_format_resolve(string $host, int $port, array $ips): string
{
    $addrs = array_map(static fn(string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $ips);
    return $host . ':' . $port . ':' . implode(',', $addrs);
}

/**
 * Pin a curl handle to the vetted addresses for $url so curl does not
 * resolve the name again. False when the host is not public; the caller
 * must not run the request then.
 */
function ap_net_curl_pin(CurlHandle $ch, string $url): bool
{
    $entry = ap_net_resolve_entry($url);
    if ($entry === null) {
        return false;
    }
    if ($entry !== '') {
        curl_setopt($ch, CURLOPT_RESOLVE, [$entry]);
    }
    return true;
}

/**
 * curl_exec() for a guarded URL. The handle is pinned before each request.
 * Redirects are followed here, never by curl: at most $maxRedirects hops,
 * each one checked and pinned again. Only http(s) hops are considered, and
 * the handle's CURLOPT_PROTOCOLS still applies, so an https-only handle
 * refuses an http hop. With $crossHost false a redirect
 * to another host is returned as-is, so credentials in the request headers
 * stay with the host they were meant for.
 *
 * Returns what curl_exec() returned for the last request, or false when a
 * hop was refused (ap_net_last_refusal() then says why). curl_getinfo()
 * describes the last request that ran.
 */
function ap_net_curl_exec(CurlHandle $ch, string $url, int $maxRedirects = 0, bool $crossHost = true): string|bool
{
    $GLOBALS['ap_net_last_refusal'] = '';
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $firstHost = strtolower((string) parse_url($url, PHP_URL_HOST));
    for ($hop = 0; ; $hop++) {
        curl_setopt($ch, CURLOPT_URL, $url);
        if (!ap_net_curl_pin($ch, $url)) {
            $GLOBALS['ap_net_last_refusal'] = $hop === 0 ? 'host is not public' : 'redirect host is not public';
            return false;
        }
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        if ($result === false || $status < 300 || $status >= 400 || $next === '' || $hop >= $maxRedirects) {
            return $result;
        }
        if (!$crossHost && strtolower((string) parse_url($next, PHP_URL_HOST)) !== $firstHost) {
            return $result;
        }
        $url = $next;
    }
}

function ap_net_last_refusal(): string
{
    return (string) ($GLOBALS['ap_net_last_refusal'] ?? '');
}
