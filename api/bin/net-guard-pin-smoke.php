<?php
/**
 * Guarded curl requests connect to the vetted addresses and follow redirects
 * only by hand, re-checking each hop. A loopback server stands in for the
 * remote hosts: the test resolver maps a.test and b.test to 127.0.0.1 and
 * refuses evil.test, so these names never reach DNS.
 */
declare(strict_types=1);

require __DIR__ . '/migration-reference-functions.php';
reference_load(dirname(__DIR__) . '/ap-net-guard.php', ['ap_net_curl_pin', 'ap_net_curl_exec', 'ap_net_last_refusal']);

$port = 0;
function ap_net_resolve_entry(string $url): ?string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $GLOBALS['resolved'][] = $host;
    if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        return null;
    }
    return in_array($host, ['a.test', 'b.test'], true) ? "{$host}:{$GLOBALS['port']}:127.0.0.1" : null;
}

$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void {
    if (!$ok) {
        fwrite(STDERR, "net-guard-pin: {$why}\n");
        exit(1);
    }
    $n++;
};

$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr((string) stream_socket_get_name($sock, false), strlen('127.0.0.1:'));
fclose($sock);
$router = tempnam(sys_get_temp_dir(), 'pin-router');
file_put_contents($router, '<?php
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$h = $_SERVER["HTTP_HOST"];
$port = ' . $port . ';
$to = ["/redir-b" => "http://b.test:$port/ok", "/redir-evil" => "http://evil.test:$port/ok",
    "/redir-same" => "/ok", "/loop" => "/loop", "/redir-ftp" => "ftp://a.test/x"][$p] ?? null;
if ($to !== null) { header("Location: $to", true, 302); echo "moved"; return true; }
echo "ok from $h"; return true;');
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

$get = static function (string $path, int $max = 0, bool $cross = true, string $host = 'a.test'): array {
    $GLOBALS['resolved'] = [];
    $ch = curl_init();
    // The loopback server is reached directly, never through an env proxy.
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 5]);
    $body = ap_net_curl_exec($ch, "http://{$host}:{$GLOBALS['port']}{$path}", $max, $cross);
    $out = [
        'body' => $body,
        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'ip' => (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP),
        'url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'refusal' => ap_net_last_refusal(),
        'resolved' => $GLOBALS['resolved'],
    ];
    curl_close($ch);
    return $out;
};

try {
    $r = $get('/ok');
    $check($r['body'] === 'ok from a.test:' . $port && $r['ip'] === '127.0.0.1', 'name is served from the pinned address');
    $r = $get('/redir-b', 2);
    $check($r['body'] === 'ok from b.test:' . $port && $r['resolved'] === ['a.test', 'b.test'], 'redirect hop is checked and pinned');
    $r = $get('/redir-evil', 2);
    $check($r['body'] === false && $r['refusal'] === 'redirect host is not public', 'redirect to a refused host stops');
    $r = $get('/redir-b');
    $check($r['status'] === 302 && $r['body'] === 'moved' && $r['resolved'] === ['a.test'], 'no redirects unless asked');
    $r = $get('/redir-b', 2, false);
    $check($r['status'] === 302 && $r['resolved'] === ['a.test'], 'cross-host redirect not followed when disallowed');
    $r = $get('/redir-same', 2, false);
    $check($r['body'] === 'ok from a.test:' . $port, 'same-host redirect still followed');
    $r = $get('/loop', 3);
    $check($r['status'] === 302 && count($r['resolved']) === 4, 'hop limit holds');
    $r = $get('/redir-ftp', 2);
    $check($r['body'] === false && $r['refusal'] !== '', 'non-http redirect refused');
    $r = $get('/ok', 0, true, 'evil.test');
    $check($r['body'] === false && $r['refusal'] === 'host is not public' && $r['status'] === 0, 'refused host is never contacted');
    $ch = curl_init();
    $check(!ap_net_curl_pin($ch, 'http://evil.test/'), 'pin refuses');
    curl_close($ch);
} finally {
    proc_terminate($server);
    @unlink($router);
}
echo "net-guard-pin-smoke: {$n} checks ok\n";
