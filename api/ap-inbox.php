<?php
/**
 * Secure ActivityPub shared-inbox sink for mkultra.monster.
 *
 * Accepts POST /inbox (rewritten here by Caddy). Verifies Digest + HTTP
 * Signature, rate-limits by IP, returns 202, and does NOT process activities.
 * Purpose: stop 404/retries from stale Mastodon-era actor caches.
 */
declare(strict_types=1);

const AP_INBOX_MAX_BODY = 262144;          // 256 KiB
const AP_INBOX_DATE_SKEW = 600;            // ±10 minutes
const AP_INBOX_RATE_MAX = 600;             // requests / window / IP (normal peers; 120 starved active instances)
/** Accepted ActivityPub relays forward a firehose — low caps cause 429 retry-storms. */
const AP_INBOX_RATE_MAX_RELAY = 12000;     // requests / window / IP for accepted relay hosts
const AP_INBOX_RATE_WINDOW = 3600;         // seconds
const AP_INBOX_KEY_CACHE_TTL = 3600;
/** How long to remember "this keyId is not fetchable" (Threads dead actors, etc.). */
const AP_INBOX_KEY_NEG_CACHE_TTL = 900; // 15 minutes
const AP_INBOX_KEY_FETCH_TIMEOUT = 8;
const AP_INBOX_LOG = '/var/log/mkultra/ap-inbox.log';
const AP_INBOX_RATE_DIR = '/tmp/ap-inbox-rate';
const AP_INBOX_KEY_CACHE_DIR = '/tmp/ap-inbox-keys';
const AP_INBOX_PRIVATE_KEY = '/etc/mkultra/ap-inbox/private.pem';
const AP_INBOX_INSTANCE_KEY_ID = 'https://mkultra.monster/actor#main-key';
const CMDR_NOVA_ACTOR = 'https://mkultra.monster/users/cmdr_nova';
const CMDR_NOVA_PRIV = '/etc/mkultra/ap-inbox/cmdr_nova_private.pem';
const CMDR_NOVA_KEY_ID = 'https://mkultra.monster/users/cmdr_nova#main-key';
// Local social actor = cmdr_nova (val3r1e@mkultra.monster stays with Bridgy Fed)
const LOCAL_ACTOR = CMDR_NOVA_ACTOR;
const LOCAL_PRIV = CMDR_NOVA_PRIV;
const LOCAL_KEY_ID = CMDR_NOVA_KEY_ID;
const LOCAL_FOLLOW_BACK_MAX_PER_HOUR = 30;

require_once __DIR__ . '/ap-db.php';

/** Outbound HTTP User-Agent for federation fingerprints (software name: vaak). */
function ap_http_user_agent(?string $actorId = null): string
{
    $actor = $actorId;
    if ($actor === null || $actor === '') {
        $actor = function_exists('ap_local_actor_id') ? ap_local_actor_id() : '';
    }
    if ($actor === '' || !str_starts_with($actor, 'https://')) {
        $actor = defined('LOCAL_ACTOR') ? (string) LOCAL_ACTOR : 'https://mkultra.monster/users/cmdr_nova';
    }
    return 'vaak/1.0 (+' . rtrim($actor, '/') . ')';
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    ap_inbox_main();
}

function ap_inbox_main(): void
{
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

// Personal inbox path → request-local actor (Accept Follow signed as that user)
ap_inbox_bootstrap_request_actor();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    header('Allow: POST');
    ap_fail('Method not allowed', 405);
}

$ip = ap_client_ip();
// Peek Signature early so accepted relays get a higher rate ceiling (firehose).
$sigHdrEarly = ap_header('Signature');
$rateMax = AP_INBOX_RATE_MAX;
if ($sigHdrEarly !== '' && preg_match('/keyId="([^"]+)"/', $sigHdrEarly, $km)) {
    if (!function_exists('ap_relay_is_accepted_key')) {
        require_once __DIR__ . '/ap-relays.php';
    }
    if (function_exists('ap_relay_is_accepted_key') && ap_relay_is_accepted_key($km[1])) {
        $rateMax = AP_INBOX_RATE_MAX_RELAY;
    }
}
if (!ap_rate_limit($ip, $rateMax)) {
    ap_fail('Too many requests', 429);
}

$raw = file_get_contents('php://input', false, null, 0, AP_INBOX_MAX_BODY + 1);
if ($raw === false) {
    ap_fail('Empty body', 400);
}
if (strlen($raw) > AP_INBOX_MAX_BODY) {
    ap_fail('Body too large', 413);
}
if ($raw === '') {
    ap_fail('Empty body', 400);
}

$digestHdr = ap_header('Digest');
$dateHdr = ap_header('Date');
$sigHdr = ap_header('Signature');
if ($digestHdr === '' || $dateHdr === '' || $sigHdr === '') {
    ap_fail('Missing Digest, Date, or Signature', 401);
}

if (!ap_valid_date($dateHdr)) {
    ap_fail('Date out of range', 401);
}

if (!ap_valid_digest($digestHdr, $raw)) {
    ap_fail('Digest mismatch', 401);
}

$sig = ap_parse_signature($sigHdr);
if ($sig === null) {
    ap_log('signature_parse_fail len=' . strlen($sigHdr) . ' sample=' . ap_short($sigHdr, 160));
    ap_fail('Malformed Signature', 401);
}

$algo = strtolower($sig['algorithm'] ?? 'rsa-sha256');
if ($algo !== 'rsa-sha256' && $algo !== 'hs2019') {
    // Mastodon uses rsa-sha256; reject exotic algorithms.
    ap_fail('Unsupported signature algorithm', 401);
}

$pem = ap_fetch_actor_pubkey($sig['keyId']);
if ($pem === null) {
    ap_fail('Unable to fetch key', 401);
}

$sigRaw = base64_decode($sig['signature'], true) ?: '';
$okPath = ap_verify_http_signature($sig['headers'], $raw, $sigRaw, $pem);
if ($okPath === null) {
    ap_fail('Invalid signature', 401);
}
if ($okPath !== '/inbox') {
    ap_log('sig_ok path=' . $okPath . ' keyId=' . ap_short($sig['keyId']));
}

// Verified — optionally handle tombstone Follows; never store activity bodies.
$type = 'unknown';
$json = json_decode($raw, true);
if (is_array($json) && isset($json['type']) && is_string($json['type'])) {
    $type = preg_replace('/[^A-Za-z0-9._-]/', '', $json['type']) ?: 'unknown';
}

// Defense in depth: drop if signing key host is a blocked instance.
$keyHost = parse_url($sig['keyId'], PHP_URL_HOST);
if (is_string($keyHost) && ap_is_blocked_host(strtolower($keyHost))) {
    ap_log(sprintf(
        'blocked_drop_keyhost ip=%s type=%s keyId=%s',
        $ip,
        $type,
        ap_short($sig['keyId'])
    ));
    http_response_code(202);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'accepted', 'action' => 'blocked'], JSON_UNESCAPED_SLASHES);
    exit;
}

ap_log(sprintf(
    'accepted ip=%s type=%s bytes=%d keyId=%s ua=%s',
    $ip,
    $type,
    strlen($raw),
    ap_short($sig['keyId']),
    ap_short(ap_header('User-Agent'), 80)
));

$action = 'log';
if (is_array($json)) {
    $claimedActor = ap_as_id($json['actor'] ?? null);
    if ($claimedActor !== null && !ap_inbox_actor_matches_key($sig['keyId'], $claimedActor)) {
        // 1) Accepted relays re-sign the firehose as themselves.
        // 2) Followed actors forward reply Creates (Mastodon inbox forwarding)
        //    signed as the followee while activity.actor is the reply author.
        if (!function_exists('ap_relay_is_accepted_key')) {
            require_once __DIR__ . '/ap-relays.php';
        }
        $relayOk = function_exists('ap_relay_is_accepted_key') && ap_relay_is_accepted_key($sig['keyId']);
        $followFwd = !$relayOk && ap_inbox_signer_is_followed($sig['keyId']);
        if ($relayOk || $followFwd) {
            ap_log(sprintf(
                '%s type=%s keyId=%s actor=%s',
                $relayOk ? 'relay_forward' : 'follow_forward',
                $type,
                ap_short($sig['keyId']),
                ap_short($claimedActor)
            ));
        } else {
            ap_log(sprintf(
                'actor_key_mismatch type=%s keyId=%s actor=%s',
                $type,
                ap_short($sig['keyId']),
                ap_short($claimedActor)
            ));
            ap_fail('Signature key does not match activity actor', 401);
        }
    }
    $action = ap_route_verified_activity($json, strlen($raw));
}

http_response_code(202);
header('Content-Type: application/json');
echo json_encode(['status' => 'accepted', 'action' => $action], JSON_UNESCAPED_SLASHES);
exit;
} // ap_inbox_main

/* ----------------- helpers ----------------- */

function ap_fail(string $message, int $code): void
{
    ap_log(sprintf('reject code=%d ip=%s msg=%s', $code, ap_client_ip(), $message));
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function ap_header(string $name): string
{
    $want = strtolower($name);
    if (function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $k => $v) {
            if (strtolower((string) $k) === $want && is_string($v)) {
                return trim($v);
            }
        }
    }
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$key] ?? ''));
}

function ap_client_ip(): string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($xff) && $xff !== '') {
        $first = trim(explode(',', $xff)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return is_string($ip) ? $ip : '0.0.0.0';
}

function ap_short(string $s, int $n = 120): string
{
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return strlen($s) <= $n ? $s : substr($s, 0, $n) . '…';
}

function ap_log(string $line): void
{
    $dir = dirname(AP_INBOX_LOG);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $entry = sprintf("[%s] %s\n", gmdate('c'), $line);
    @file_put_contents(AP_INBOX_LOG, $entry, FILE_APPEND | LOCK_EX);
}

function ap_valid_date(string $dateHdr): bool
{
    $ts = strtotime($dateHdr);
    if ($ts === false) {
        return false;
    }
    return abs(time() - $ts) <= AP_INBOX_DATE_SKEW;
}

function ap_valid_digest(string $digestHdr, string $body): bool
{
    // Digest: SHA-256=base64...
    foreach (preg_split('/\s*,\s*/', $digestHdr) ?: [] as $part) {
        if (!preg_match('/^SHA-256\s*=\s*(.+)$/i', $part, $m)) {
            continue;
        }
        $expected = base64_encode(hash('sha256', $body, true));
        return hash_equals($expected, trim($m[1]));
    }
    return false;
}

/**
 * @return array{keyId:string,algorithm:?string,headers:string,signature:string}|null
 */
function ap_parse_signature(string $hdr): ?array
{
    $out = [
        'keyId' => null,
        'algorithm' => null,
        'headers' => null,
        'signature' => null,
    ];
    $aliases = [
        'keyid' => 'keyId',
        'algorithm' => 'algorithm',
        'headers' => 'headers',
        'signature' => 'signature',
    ];
    // keyId="...",algorithm="rsa-sha256",headers="...",signature="..."
    if (!preg_match_all('/([a-zA-Z]+)=(?:"([^"]*)"|([^,]*))/', $hdr, $matches, PREG_SET_ORDER)) {
        return null;
    }
    foreach ($matches as $m) {
        $k = strtolower($m[1]);
        $v = ($m[2] ?? '') !== '' ? $m[2] : trim((string) ($m[3] ?? ''));
        if (isset($aliases[$k])) {
            $out[$aliases[$k]] = $v;
        }
    }
    if (empty($out['keyId']) || empty($out['headers']) || empty($out['signature'])) {
        return null;
    }
    /** @var array{keyId:string,algorithm:?string,headers:string,signature:string} $out */
    return $out;
}

/**
 * Candidate URL paths for (request-target). Caddy rewrites both /inbox and
 * /users/cmdr_nova/inbox → /api/ap-inbox.php, so we cannot trust REQUEST_URI alone.
 * Mastodon Follows usually hit the actor inbox; shared-inbox Creates hit /inbox.
 *
 * @return list<string>
 */
function ap_request_target_path_candidates(): array
{
    $paths = [];
    $add = static function (string $p) use (&$paths): void {
        $p = trim($p);
        if ($p === '' || !str_starts_with($p, '/')) {
            return;
        }
        $p = strtok($p, '?') ?: $p;
        // Ignore rewritten PHP script path
        if (str_contains($p, '.php')) {
            return;
        }
        if (!in_array($p, $paths, true)) {
            $paths[] = $p;
        }
    };

    // Explicit env from Caddy (optional)
    $env = getenv('AP_ORIGINAL_PATH');
    if (is_string($env) && $env !== '') {
        $add($env);
    }
    foreach ([
        'HTTP_X_ORIGINAL_URI',
        'HTTP_X_ORIGINAL_URL',
        'HTTP_X_FORWARDED_URI',
        'HTTP_X_REWRITE_URL',
        'REDIRECT_URI',
        'REQUEST_URI',
    ] as $key) {
        $v = $_SERVER[$key] ?? '';
        if (is_string($v) && $v !== '') {
            $path = parse_url($v, PHP_URL_PATH);
            if (is_string($path)) {
                $add($path);
            } else {
                $add($v);
            }
        }
    }

    // Always try shared + personal inboxes our Caddy routes expose
    $add('/inbox');
    $add('/users/cmdr_nova/inbox');
    $add('/users/cmdr_nova/inbox/');
    $reqActor = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
    if (is_array($reqActor) && !empty($reqActor['key'])) {
        $k = (string) $reqActor['key'];
        $add('/users/' . rawurlencode($k) . '/inbox');
        $add('/users/' . rawurlencode($k) . '/inbox/');
    }

    return $paths;
}

function ap_signing_string_for_path(string $headersList, string $requestPath): ?string
{
    $parts = [];
    $method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'post');
    foreach (preg_split('/\s+/', trim($headersList)) ?: [] as $h) {
        $h = strtolower($h);
        if ($h === '(request-target)') {
            $parts[] = '(request-target): ' . $method . ' ' . $requestPath;
            continue;
        }
        if ($h === 'digest') {
            $val = ap_header('Digest');
        } elseif ($h === 'host') {
            $val = $_SERVER['HTTP_HOST'] ?? 'mkultra.monster';
        } else {
            $val = ap_header($h);
        }
        if ($val === '') {
            return null;
        }
        $parts[] = $h . ': ' . $val;
    }
    return implode("\n", $parts);
}

/** @deprecated use ap_verify_http_signature */
function ap_signing_string(string $headersList, string $body): ?string
{
    return ap_signing_string_for_path($headersList, '/inbox');
}

/**
 * Verify HTTP Signature trying each plausible request-target path.
 * @return string|null matched path on success
 */
function ap_verify_http_signature(string $headersList, string $body, string $sigRaw, $pem): ?string
{
    foreach (ap_request_target_path_candidates() as $path) {
        $signingString = ap_signing_string_for_path($headersList, $path);
        if ($signingString === null) {
            continue;
        }
        $ok = openssl_verify($signingString, $sigRaw, $pem, OPENSSL_ALGO_SHA256);
        if ($ok === 1) {
            return $path;
        }
    }
    return null;
}

function ap_rate_limit(string $ip, int $max = AP_INBOX_RATE_MAX): bool
{
    if (!is_dir(AP_INBOX_RATE_DIR)) {
        @mkdir(AP_INBOX_RATE_DIR, 0750, true);
    }
    $safe = preg_replace('/[^a-zA-Z0-9:._-]/', '_', $ip) ?: 'unknown';
    $path = AP_INBOX_RATE_DIR . '/' . $safe . '.json';
    $now = time();
    $max = max(1, $max);
    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        return true; // fail open
    }
    try {
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $stamps = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $stamps = array_map('intval', $decoded);
            }
        }
        $stamps = array_values(array_filter($stamps, static fn($t) => $t > $now - AP_INBOX_RATE_WINDOW));
        if (count($stamps) >= $max) {
            return false;
        }
        $stamps[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($stamps));
        fflush($fh);
        return true;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function ap_is_public_ip(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    return (bool) filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
}

/**
 * @return list<string>
 */
function ap_key_fetch_candidates(string $keyId): array
{
    $base = preg_replace('/#.*$/', '', trim($keyId)) ?: trim($keyId);
    $parts = parse_url($base);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
        return [];
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '/');
    $query = !empty($parts['query']) ? ('?' . $parts['query']) : '';

    $candidates = [];
    $add = static function (string $url) use (&$candidates): void {
        if ($url !== '' && !in_array($url, $candidates, true)) {
            $candidates[] = $url;
        }
    };

    $build = static function (string $h, string $p) use ($query): string {
        return 'https://' . $h . $p . $query;
    };

    $add($base);
    // Many actors (esp. Threads) publish IDs with a trailing slash.
    if ($path !== '' && !str_ends_with($path, '/')) {
        $add($build($host, $path . '/'));
    }

    $threadsHosts = ['threads.net', 'www.threads.net', 'threads.com', 'www.threads.com'];
    if (in_array($host, $threadsHosts, true)) {
        $normPath = ($path === '' ? '/' : $path);
        if (!str_ends_with($normPath, '/')) {
            $normPath .= '/';
        }
        foreach ($threadsHosts as $th) {
            $add($build($th, $normPath));
        }
    }

    return $candidates;
}

function ap_extract_pubkey_pem(array $data): ?string
{
    $pem = null;
    if (isset($data['publicKey']) && is_array($data['publicKey'])) {
        if (isset($data['publicKey']['publicKeyPem'])) {
            $pem = $data['publicKey']['publicKeyPem'];
        } elseif (isset($data['publicKey'][0]['publicKeyPem'])) {
            $pem = $data['publicKey'][0]['publicKeyPem'];
        }
    }
    if (!$pem && isset($data['publicKeyPem'])) {
        $pem = $data['publicKeyPem'];
    }
    // assertionMethod / FEP-521a style (rare)
    if (!$pem && isset($data['assertionMethod']) && is_array($data['assertionMethod'])) {
        foreach ($data['assertionMethod'] as $am) {
            if (is_array($am) && isset($am['publicKeyPem'])) {
                $pem = $am['publicKeyPem'];
                break;
            }
        }
    }
    if (!is_string($pem)) {
        return null;
    }
    // Threads sometimes embeds PEM with spaces instead of newlines
    if (!str_contains($pem, "\n") && str_contains($pem, 'BEGIN')) {
        $pem = preg_replace('/\\s+/', "\n", trim($pem)) ?? $pem;
        $pem = str_replace(
            ["BEGIN\nPUBLIC\nKEY", "END\nPUBLIC\nKEY", "BEGIN\nRSA\nPUBLIC\nKEY", "END\nRSA\nPUBLIC\nKEY"],
            ['BEGIN PUBLIC KEY', 'END PUBLIC KEY', 'BEGIN RSA PUBLIC KEY', 'END RSA PUBLIC KEY'],
            $pem
        );
    }
    if (!str_contains($pem, 'BEGIN PUBLIC KEY') && !str_contains($pem, 'BEGIN RSA PUBLIC KEY')) {
        return null;
    }
    return $pem;
}

function ap_instance_private_key()
{
    static $key = false;
    if ($key !== false) {
        return $key;
    }
    $pem = @file_get_contents(AP_INBOX_PRIVATE_KEY);
    if (!is_string($pem) || $pem === '') {
        $key = null;
        return null;
    }
    $key = openssl_pkey_get_private($pem);
    return $key ?: null;
}

/**
 * Load the local social actor private key for outbound signed GETs.
 * Uses request-local actor when set (invitee personal inbox); else cmdr_nova.
 *
 * @return \OpenSSLAsymmetricKey|resource|null
 */
function ap_local_private_key()
{
    $path = function_exists('ap_local_priv_path')
        ? ap_local_priv_path()
        : (defined('LOCAL_PRIV') ? (string) LOCAL_PRIV : CMDR_NOVA_PRIV);
    $cache = $GLOBALS['ap_local_priv_key_cache'] ?? false;
    if (is_array($cache) && ($cache['path'] ?? '') === $path) {
        return $cache['key'] ?: null;
    }
    $pem = @file_get_contents($path);
    if (!is_string($pem) || $pem === '') {
        $GLOBALS['ap_local_priv_key_cache'] = ['path' => $path, 'key' => null];
        return null;
    }
    $key = openssl_pkey_get_private($pem);
    $GLOBALS['ap_local_priv_key_cache'] = ['path' => $path, 'key' => $key ?: null];
    return $key ?: null;
}

/** True when PHP's $http_response_header status line is 2xx. */
function ap_http_response_ok(?array $responseHeaders): bool
{
    if ($responseHeaders === null || $responseHeaders === [] || !isset($responseHeaders[0])) {
        return false;
    }
    return (bool) preg_match('/\s(200|201|202|203|204)\s/', (string) $responseHeaders[0]);
}

/**
 * Parse HTTP status from PHP $http_response_header[0].
 */
function ap_http_response_status(?array $responseHeaders): int
{
    if ($responseHeaders === null || $responseHeaders === [] || !isset($responseHeaders[0])) {
        return 0;
    }
    if (preg_match('/\s(\d{3})\s/', (string) $responseHeaders[0], $m)) {
        return (int) $m[1];
    }
    return 0;
}

/**
 * HTTPS GET via curl (IPv4 fallback). PHP file_get_contents often hangs on hosts
 * with broken AAAA records (e.g. girlcock.club) while curl -4 succeeds.
 *
 * @param list<string> $headers
 * @return array{body:?string,status:int}
 */
function ap_http_curl_get_ex(string $url, array $headers, int $timeoutSec = 8, int $maxBytes = 262144): array
{
    $url = trim($url);
    if ($url === '' || !str_starts_with($url, 'https://') || !function_exists('curl_init')) {
        return ['body' => null, 'status' => 0];
    }
    $timeoutSec = max(1, min(20, $timeoutSec));
    $maxBytes = max(1024, min(1048576, $maxBytes));
    // Prefer IPv4 first: many fediverse hosts advertise broken/slow AAAA and
    // CURL_IPRESOLVE_WHATEVER hangs until connect timeout (WebFinger fails).
    $attempts = [];
    if (defined('CURL_IPRESOLVE_V4')) {
        $attempts[] = CURL_IPRESOLVE_V4;
    }
    $attempts[] = CURL_IPRESOLVE_WHATEVER;

    foreach ($attempts as $ipMode) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['body' => null, 'status' => 0];
        }
        $buf = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeoutSec),
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_IPRESOLVE => $ipMode,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$buf, $maxBytes): int {
                $buf .= $data;
                if (strlen($buf) > $maxBytes) {
                    return 0;
                }
                return strlen($data);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($ok !== false && $buf !== '' && $status >= 200 && $status < 300) {
            return ['body' => $buf, 'status' => $status];
        }
        // Non-timeout HTTP error (401/403/404): don't bother with IPv4 retry
        if ($status > 0 && $errno === 0) {
            return ['body' => null, 'status' => $status];
        }
    }
    return ['body' => null, 'status' => 0];
}

/**
 * Signed GET with status for diagnostics (Threads key fetch, etc.).
 *
 * @return array{body:?string,status:int}
 */
function ap_signed_get_ex(string $url): array
{
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
        return ['body' => null, 'status' => 0];
    }
    $host = (string) ($parts['host'] ?? '');
    if ($host === '' || !ap_host_resolves_public($host)) {
        return ['body' => null, 'status' => 0];
    }
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }
    if (!empty($parts['query'])) {
        $path .= '?' . $parts['query'];
    }

    $date = gmdate('D, d M Y H:i:s') . ' GMT';
    $signingString = "(request-target): get {$path}\nhost: {$host}\ndate: {$date}";

    $keyId = function_exists('ap_local_key_id')
        ? ap_local_key_id()
        : (defined('LOCAL_KEY_ID') ? (string) LOCAL_KEY_ID : CMDR_NOVA_KEY_ID);
    $headers = [
        'Accept: application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
        'User-Agent: ' . ap_http_user_agent(),
        'Date: ' . $date,
        'Host: ' . $host,
    ];

    $priv = ap_local_private_key();
    // Fallback: instance Application key (rarely accepted by authorized-fetch Mastodon)
    if (!$priv) {
        $priv = ap_instance_private_key();
        $keyId = AP_INBOX_INSTANCE_KEY_ID;
    }
    if ($priv) {
        $sigRaw = '';
        if (openssl_sign($signingString, $sigRaw, $priv, OPENSSL_ALGO_SHA256)) {
            $sigHeader = sprintf(
                'keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date",signature="%s"',
                $keyId,
                base64_encode($sigRaw)
            );
            $headers[] = 'Signature: ' . $sigHeader;
        }
    }

    $res = ap_http_curl_get_ex($url, $headers, AP_INBOX_KEY_FETCH_TIMEOUT);
    if (is_string($res['body'] ?? null) && $res['body'] !== '') {
        return ['body' => $res['body'], 'status' => (int) ($res['status'] ?? 200)];
    }
    return ['body' => null, 'status' => (int) ($res['status'] ?? 0)];
}

/**
 * Signed GET for authorized-fetch servers (Threads, secure-mode Mastodon, etc.).
 * Signs as @cmdr_nova (not the instance Application actor) so remotes can refresh
 * https://mkultra.monster/users/cmdr_nova#main-key successfully.
 * Returns null on non-2xx (so 401 "Request not signed" JSON is not treated as a doc).
 */
function ap_signed_get(string $url): ?string
{
    $r = ap_signed_get_ex($url);
    return is_string($r['body'] ?? null) ? $r['body'] : null;
}

function ap_key_neg_cache_path(string $keyId): string
{
    return AP_INBOX_KEY_CACHE_DIR . '/' . hash('sha256', $keyId) . '.neg';
}

/**
 * @return array{reason:string,http:int,at:int}|null
 */
function ap_key_neg_cache_get(string $keyId): ?array
{
    $path = ap_key_neg_cache_path($keyId);
    if (!is_file($path)) {
        return null;
    }
    if ((time() - filemtime($path)) >= AP_INBOX_KEY_NEG_CACHE_TTL) {
        @unlink($path);
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    return [
        'reason' => (string) ($data['reason'] ?? 'cached_miss'),
        'http' => (int) ($data['http'] ?? 0),
        'at' => (int) ($data['at'] ?? filemtime($path)),
    ];
}

function ap_key_neg_cache_set(string $keyId, string $reason, int $httpStatus = 0): void
{
    if (!is_dir(AP_INBOX_KEY_CACHE_DIR)) {
        @mkdir(AP_INBOX_KEY_CACHE_DIR, 0750, true);
    }
    $payload = json_encode([
        'reason' => $reason,
        'http' => $httpStatus,
        'at' => time(),
        'keyId' => $keyId,
    ], JSON_UNESCAPED_SLASHES);
    if (is_string($payload)) {
        @file_put_contents(ap_key_neg_cache_path($keyId), $payload);
    }
}

function ap_fetch_actor_pubkey(string $keyId): ?string
{
    $keyId = trim($keyId);
    if ($keyId === '' || strlen($keyId) > 500) {
        return null;
    }
    $parts = parse_url($keyId);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
        return null;
    }
    $host = $parts['host'] ?? '';
    if ($host === '' || !ap_host_resolves_public($host)) {
        return null;
    }

    if (!is_dir(AP_INBOX_KEY_CACHE_DIR)) {
        @mkdir(AP_INBOX_KEY_CACHE_DIR, 0750, true);
    }
    $cacheFile = AP_INBOX_KEY_CACHE_DIR . '/' . hash('sha256', $keyId) . '.pem';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < AP_INBOX_KEY_CACHE_TTL)) {
        $cached = file_get_contents($cacheFile);
        if (is_string($cached) && str_contains($cached, 'BEGIN')) {
            return $cached;
        }
    }

    // Negative cache: skip hammering Threads/Meta for dead / non-federated actors
    $neg = ap_key_neg_cache_get($keyId);
    if ($neg !== null) {
        ap_log(
            'key_fetch_neg_hit keyId=' . ap_short($keyId)
            . ' http=' . (int) $neg['http']
            . ' reason=' . preg_replace('/[^a-z0-9_]+/i', '_', (string) $neg['reason'])
        );
        return null;
    }

    $lastHttp = 0;
    $lastReason = 'no_candidate';
    $tries = 0;

    foreach (ap_key_fetch_candidates($keyId) as $fetchUrl) {
        $fp = parse_url($fetchUrl);
        if (!is_array($fp) || ($fp['scheme'] ?? '') !== 'https') {
            continue;
        }
        $fhost = (string) ($fp['host'] ?? '');
        if ($fhost === '' || !ap_host_resolves_public($fhost)) {
            continue;
        }

        $tries++;
        $res = ap_signed_get_ex($fetchUrl);
        $status = (int) ($res['status'] ?? 0);
        if ($status > 0) {
            $lastHttp = $status;
        }
        $body = $res['body'] ?? null;
        if (!is_string($body) || $body === '') {
            $lastReason = $status > 0 ? ('http_' . $status) : 'empty_or_transport';
            continue;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $lastReason = 'bad_json';
            $lastHttp = $status > 0 ? $status : $lastHttp;
            continue;
        }
        // Ignore Threads/Meta JSON error envelopes (often HTTP 200 with success:false)
        if (isset($data['success']) && $data['success'] === false) {
            $err = is_string($data['error'] ?? null) ? (string) $data['error'] : 'error_envelope';
            $lastReason = 'meta_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($err));
            $lastHttp = $status > 0 ? $status : 200;
            continue;
        }
        $pem = ap_extract_pubkey_pem($data);
        if ($pem === null) {
            $lastReason = 'no_publicKeyPem';
            $lastHttp = $status > 0 ? $status : $lastHttp;
            continue;
        }

        @file_put_contents($cacheFile, $pem);
        // Clear any stale negative cache on success
        $negPath = ap_key_neg_cache_path($keyId);
        if (is_file($negPath)) {
            @unlink($negPath);
        }
        ap_log('key_fetch_ok keyId=' . ap_short($keyId) . ' via=' . ap_short($fetchUrl, 80) . ' http=' . $status);
        return $pem;
    }

    ap_key_neg_cache_set($keyId, $lastReason, $lastHttp);
    ap_log(
        'key_fetch_fail keyId=' . ap_short($keyId)
        . ' http=' . $lastHttp
        . ' tries=' . $tries
        . ' reason=' . preg_replace('/[^a-z0-9_]+/i', '_', $lastReason)
    );
    return null;
}

function ap_host_resolves_public(string $host): bool
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return ap_is_public_ip($host);
    }
    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    if (!is_array($records) || $records === []) {
        // Fallback gethostbyname
        $ip = gethostbyname($host);
        if ($ip === $host) {
            return false;
        }
        return ap_is_public_ip($ip);
    }
    foreach ($records as $rec) {
        $ip = $rec['ip'] ?? $rec['ipv6'] ?? null;
        if (!is_string($ip) || !ap_is_public_ip($ip)) {
            return false;
        }
    }
    return true;
}

function ap_as_id($value): ?string
{
    if (is_string($value) && $value !== '') {
        return $value;
    }
    if (!is_array($value)) {
        return null;
    }
    // Bridgy Fed (and some LD expanders) send single-element URI arrays:
    //   "object": ["https://mkultra.monster/users/cmdr_nova"]
    if (array_is_list($value) && count($value) === 1) {
        return ap_as_id($value[0]);
    }
    if (isset($value['id']) && is_string($value['id'])) {
        return $value['id'];
    }
    if (isset($value['href']) && is_string($value['href'])) {
        return $value['href'];
    }
    return null;
}

/**
 * Route a verified activity to the local cmdr_nova handler; always returns an action label.
 */
function ap_route_verified_activity(array $activity, int $bytes): string
{
    $type = is_string($activity['type'] ?? null) ? $activity['type'] : 'unknown';
    $actorId = ap_as_id($activity['actor'] ?? null);
    $objectId = ap_as_id($activity['object'] ?? null);

    // Moderation: drop blocked actors / instance traffic (still HTTP 202 to avoid retries).
    if ($actorId && ap_is_blocked_actor($actorId)) {
        ap_log('blocked_drop type=' . $type . ' actor=' . ap_short($actorId));
        return 'blocked';
    }

    // FEP-044f QuoteRequest — auto-approve public quotes of our notes.
    if ($type === 'QuoteRequest' && $actorId) {
        $handled = ap_handle_quote_request($activity);
        if ($handled !== null) {
            return $handled;
        }
    }

    // Accept of our outbound Follow (usually not Public — handle before private skip).
    if ($type === 'Accept' && $actorId) {
        // Mastodon-style relay: Accept of Follow(as:Public) matching ap_relays.follow_activity_id
        if (!function_exists('ap_relay_handle_accept')) {
            require_once __DIR__ . '/ap-relays.php';
        }
        if (function_exists('ap_relay_handle_accept') && ap_relay_handle_accept($activity)) {
            ap_metrics_record('Accept', $actorId, $objectId, LOCAL_ACTOR, $bytes, 'relay_accepted', null);
            return 'relay_accepted';
        }
        $inner = $activity['object'] ?? null;
        if (is_array($inner) && ($inner['type'] ?? '') === 'Follow') {
            $innerActor = ap_as_id($inner['actor'] ?? null);
            $innerObject = ap_as_id($inner['object'] ?? null);
            // Remote accepted Follow(us → them)
            if ($innerActor && rtrim($innerActor, '/') === rtrim(LOCAL_ACTOR, '/')) {
                ap_following_upsert($actorId);
                ap_metrics_record('Accept', $actorId, $objectId, LOCAL_ACTOR, $bytes, 'follow_accepted', $actorId);
                ap_log('follow_accepted by=' . ap_short($actorId));
                // AP does not push history — pull recent outbox notes so Home isn't empty
                ap_outbox_backfill_async($actorId);
                return 'follow_accepted';
            }
            // Remote ack of us accepting their Follow(them → us)
            if ($innerObject && rtrim($innerObject, '/') === rtrim(LOCAL_ACTOR, '/')) {
                ap_metrics_record('Accept', $actorId, $objectId, LOCAL_ACTOR, $bytes, 'follow_accept_ack', null);
                return 'follow_accept_ack';
            }
        }
    }

    // Reject of our relay Follow(as:Public)
    if ($type === 'Reject' && $actorId) {
        if (!function_exists('ap_relay_handle_reject')) {
            require_once __DIR__ . '/ap-relays.php';
        }
        if (function_exists('ap_relay_handle_reject') && ap_relay_handle_reject($activity)) {
            ap_metrics_record('Reject', $actorId, $objectId, LOCAL_ACTOR, $bytes, 'relay_rejected', null);
            return 'relay_rejected';
        }
    }

    // Undo Follow / Like / Announce of local cmdr_nova (revoke matching notifications)
    if ($type === 'Undo') {
        $inner = $activity['object'] ?? null;
        $innerType = '';
        $innerObj = null;
        $innerActId = null;
        if (is_array($inner)) {
            $innerType = (string) ($inner['type'] ?? '');
            $innerObj = $inner['object'] ?? null;
            $innerActId = ap_as_id($inner);
        } elseif (is_string($inner) && $inner !== '') {
            // Undo of an activity URL — best-effort soft-delete by activity_id
            $innerActId = $inner;
        }
        if ($innerType === 'Follow') {
            $undoTarget = ap_resolve_local_follow_target($innerObj);
            if ($undoTarget !== null) {
                ap_request_actor_set((string) $undoTarget['actor_key']);
                $who = ap_as_id($inner['actor'] ?? $activity['actor'] ?? null);
                if ($who) {
                    ap_follower_remove($who, ap_local_actor_id());
                }
                ap_metrics_record('Undo', $actorId, $objectId, ap_local_actor_id(), $bytes, 'local_unfollow', null);
                ap_log('local_unfollow actor=' . ap_short((string) $who) . ' target=' . ap_short(ap_local_actor_id()));
                return 'local_unfollow';
            }
        }
        if (in_array($innerType, ['Like', 'EmojiReact', 'Announce', 'Quote', 'QuotePost'], true)) {
            $who = ap_as_id(is_array($inner) ? ($inner['actor'] ?? $activity['actor'] ?? null) : ($activity['actor'] ?? null));
            $target = ap_as_id($innerObj);
            $kind = ($innerType === 'Announce') ? 'reblog' : (($innerType === 'Like' || $innerType === 'EmojiReact') ? 'like' : 'quote');
            $n = 0;
            if (function_exists('ap_mention_soft_delete_interaction') && $who && $target) {
                $n = ap_mention_soft_delete_interaction($who, $target, $kind, $innerActId);
            } elseif (function_exists('ap_mention_soft_delete_interaction') && $innerActId) {
                $n = ap_mention_soft_delete_interaction((string) ($who ?: ''), '', $kind, $innerActId);
            }
            if ($who && function_exists('ap_events_mark_interaction_undone')) {
                ap_events_mark_interaction_undone($innerType, $who, $target, $innerActId);
            }
            ap_metrics_record('Undo', $actorId, $objectId, LOCAL_ACTOR, $bytes, 'local_undo_' . $kind, null);
            ap_log('local_undo_' . $kind . ' actor=' . ap_short((string) $who) . ' n=' . $n);
            return 'local_undo_' . $kind;
        }
        // Bare activity URL Undo — soft-delete any mention with that activity_id
        if ($innerType === '' && is_string($innerActId) && $innerActId !== '' && function_exists('ap_mention_soft_delete_interaction')) {
            $n = ap_mention_soft_delete_interaction((string) ($actorId ?: ''), '', 'like', $innerActId);
            if ($n > 0) {
                ap_metrics_record('Undo', $actorId, $objectId, LOCAL_ACTOR, $bytes, 'local_undo_activity', null);
                return 'local_undo_activity';
            }
        }
    }

    // Remote Delete: soft-delete mentions/DMs AND hide matching firehose Creates.
    // Mastodon Deletes are often bare IDs (no Mention tags / inReplyTo), so
    // ap_activity_touches_local() is false — without this, ghosts stay on Home/Federated.
    if ($type === 'Delete') {
        $deleteIds = [];
        if (is_string($objectId) && $objectId !== '') {
            $deleteIds[] = $objectId;
        }
        // Tombstone / nested object id
        $obj = $activity['object'] ?? null;
        if (is_array($obj)) {
            $oid2 = ap_as_id($obj['id'] ?? null) ?: ap_as_id($obj['object'] ?? null);
            if (is_string($oid2) && $oid2 !== '') {
                $deleteIds[] = $oid2;
            }
        }
        $deletedMentions = 0;
        $deletedEvents = 0;
        foreach (array_values(array_unique($deleteIds)) as $did) {
            if (function_exists('ap_mention_soft_delete')) {
                $deletedMentions += ap_mention_soft_delete($did);
            }
            if (function_exists('ap_dm_soft_delete_by_object')) {
                ap_dm_soft_delete_by_object($did);
            }
            if (function_exists('ap_events_mark_object_deleted')) {
                $deletedEvents += ap_events_mark_object_deleted($did);
            }
        }
        if ($deletedMentions > 0 || $deletedEvents > 0) {
            $action = $deletedEvents > 0 ? 'local_status_delete' : 'local_mention_delete';
            ap_metrics_record('Delete', $actorId, $objectId, LOCAL_ACTOR, $bytes, $action, null);
            ap_log(
                $action
                . ' mentions=' . $deletedMentions
                . ' events=' . $deletedEvents
                . ' object=' . ap_short((string) $objectId)
            );
            return $action;
        }
        // Still record the Delete so backfill / later Creates don't resurrect blindly
        ap_metrics_record('Delete', $actorId, $objectId, null, $bytes, 'log', null);
        ap_log('delete_noop object=' . ap_short((string) $objectId));
        return 'delete_noop';
    }

    // Federated report (Mastodon Flag)
    if ($type === 'Flag' && is_string($actorId) && $actorId !== '') {
        require_once __DIR__ . '/ap-reports.php';
        $fres = ap_report_ingest($activity);
        $about = !empty($fres['about_us']) ? 'about_us' : 'other';
        ap_metrics_record(
            'Flag',
            $actorId,
            $objectId,
            LOCAL_ACTOR,
            $bytes,
            !empty($fres['ok']) ? 'local_report_in' : 'local_report_fail',
            $about
        );
        ap_log('report_in ok=' . (!empty($fres['ok']) ? '1' : '0') . ' about=' . $about . ' actor=' . ap_short((string) $actorId));
        return !empty($fres['ok']) ? 'local_report_in' : 'local_report_fail';
    }

    // Wafrn-compatible Bite (poke) — user or post
    if ($type === 'Bite' && is_string($actorId) && $actorId !== '') {
        require_once __DIR__ . '/ap-bites.php';
        $bres = ap_bite_ingest($activity);
        $kind = (string) ($bres['kind'] ?? 'user');
        ap_metrics_record(
            'Bite',
            $actorId,
            $objectId,
            LOCAL_ACTOR,
            $bytes,
            !empty($bres['ok']) ? 'local_bite_in' : 'local_bite_fail',
            $kind
        );
        ap_log('bite_in ok=' . (!empty($bres['ok']) ? '1' : '0') . ' kind=' . $kind . ' actor=' . ap_short((string) $actorId));
        return !empty($bres['ok']) ? 'local_bite_in' : 'local_bite_fail';
    }

    // Account Move (followers migrating onto us as the new account). Remotes that
    // honor Move will unfollow the old actor and follow us; we mainly log + ensure
    // alsoKnownAs / moved_from alias exists for the old account.
    if ($type === 'Move' && is_string($actorId) && $actorId !== '') {
        require_once __DIR__ . '/ap-import-export.php';
        $target = ap_as_id($activity['target'] ?? null)
            ?? ap_as_id($activity['object'] ?? null);
        // Mastodon: object = old account, target = new account
        $oldObj = ap_as_id($activity['object'] ?? null) ?? $actorId;
        $newTarget = ap_as_id($activity['target'] ?? null);
        $targetUser = is_string($newTarget) ? ap_local_user_by_actor_id($newTarget) : null;
        if (is_array($targetUser)) {
            $self = rtrim((string) ($targetUser['actor_id'] ?? $newTarget), '/');
            $old = is_string($oldObj) ? rtrim($oldObj, '/') : rtrim($actorId, '/');
            if ($old !== '' && $old !== $self) {
                ap_move_prepare_target($old, (string) ($targetUser['actor_key'] ?? ''));
                ap_metrics_record('Move', $actorId, $objectId, $self, $bytes, 'local_move_in', $old);
                ap_log('move_in from=' . ap_short($old) . ' (followers migrate on remote side)');
                return 'local_move_in';
            }
        }
        ap_metrics_record('Move', $actorId, $objectId, LOCAL_ACTOR, $bytes, 'log', null);
        ap_log('move_ignored target=' . ap_short((string) ($newTarget ?? $target ?? '')));
        return 'move_ignored';
    }

    if ($type === 'Follow') {
        $followTarget = ap_resolve_local_follow_target($activity['object'] ?? null);
        if ($followTarget !== null) {
            ap_request_actor_set((string) $followTarget['actor_key']);
            $ok = ap_local_accept_and_follow_back($activity);
            ap_metrics_record('Follow', $actorId, $objectId, ap_local_actor_id(), $bytes, $ok ? 'local_accept_followback' : 'local_follow_fail', null);
            if ($ok && is_string($actorId) && $actorId !== '') {
                try {
                    require_once __DIR__ . '/ap-webpush.php';
                    $nid = null;
                    if (function_exists('ap_masto_notification_id_for_follow_event')) {
                        $fst = ap_db()->prepare(
                            "SELECT id, created_at FROM events
                             WHERE type = 'Follow' AND action_taken = 'local_accept_followback'
                               AND (actor_id = ? OR actor_id = ?)
                             ORDER BY id DESC LIMIT 1"
                        );
                        $fst->execute([$actorId, rtrim($actorId, '/') . '/']);
                        $frow = $fst->fetch();
                        if (is_array($frow)) {
                            $nid = ap_masto_notification_id_for_follow_event(
                                (int) $frow['id'],
                                isset($frow['created_at']) ? (string) $frow['created_at'] : null
                            );
                        }
                    }
                    $followOwnerId = (int) ($followTarget['id'] ?? 0);
                    ap_webpush_notify_event('follow', $actorId, $nid, null, $followOwnerId > 0 ? $followOwnerId : null);
                } catch (Throwable $e) {
                    error_log('[ap-webpush] follow hook: ' . $e->getMessage());
                }
            }
            return $ok ? 'local_accept_followback' : 'local_follow_fail';
        }
    }

    $enriched = ap_enrich_activity_for_feed($activity, $type, $objectId);
    $mediaForEvent = $enriched['media'];
    $summaryForEvent = $enriched['summary'];
    $inReplyToForEvent = $enriched['in_reply_to'] ?? null;
    $spoilerForEvent = (string) ($enriched['spoiler_text'] ?? '');
    $sensitiveForEvent = !empty($enriched['sensitive']);

    if (ap_activity_touches_local($activity)) {
        if (!ap_activity_is_public($activity)) {
            // Poll votes are private Creates to the author (look like DMs). Handle first.
            $pollVote = ap_activity_extract_poll_vote($activity);
            if (is_array($pollVote)) {
                if (!empty($pollVote['ok']) && !empty($pollVote['choices'])) {
                    $vres = ap_masto_poll_vote(
                        (int) $pollVote['poll_local_id'],
                        (string) $pollVote['actor'],
                        $pollVote['choices']
                    );
                    $okVote = !empty($vres['ok']);
                    ap_metrics_record(
                        $type,
                        $actorId,
                        $objectId,
                        LOCAL_ACTOR,
                        $bytes,
                        $okVote ? 'local_poll_vote' : 'local_poll_vote_fail',
                        implode(',', $pollVote['choices'])
                    );
                    ap_log(
                        'local_poll_vote ok=' . ($okVote ? '1' : '0')
                        . ' poll=' . (int) $pollVote['poll_local_id']
                        . ' choices=' . ap_short(implode(',', $pollVote['choices']), 40)
                        . ' actor=' . ap_short((string) $actorId)
                        . ($okVote ? '' : (' err=' . ap_short((string) ($vres['error'] ?? ''), 60)))
                    );
                    return $okVote ? 'local_poll_vote' : 'local_poll_vote_fail';
                }
                // Recognized vote shape but missing option name — don't file as DM.
                ap_metrics_record($type, $actorId, $objectId, LOCAL_ACTOR, $bytes, 'local_poll_vote_incomplete', null);
                ap_log('local_poll_vote_incomplete actor=' . ap_short((string) $actorId) . ' object=' . ap_short((string) $objectId));
                return 'local_poll_vote_incomplete';
            }
            // Direct messages (Create/Update Note to us, no Public) — store privately.
            if (ap_activity_is_direct_message($activity)) {
                $dm = ap_dm_ingest_activity($activity);
                // Never put DM text into the public events feed summary.
                ap_metrics_record($type, $actorId, $objectId, LOCAL_ACTOR, $bytes, !empty($dm['ok']) ? 'local_dm' : 'local_dm_fail', null);
                ap_log('local_dm ok=' . (!empty($dm['ok']) ? '1' : '0') . ' actor=' . ap_short((string) $actorId));
                return !empty($dm['ok']) ? 'local_dm' : 'local_dm_fail';
            }
            if ($type === 'Delete' && $objectId) {
                ap_dm_soft_delete_by_object($objectId);
            }
            // Likes / boosts / quote activities of our posts are usually not
            // addressed to as:Public — still notifiable.
            if (in_array($type, ['Like', 'Announce', 'EmojiReact', 'Quote', 'QuotePost'], true)) {
                ap_local_observe($activity);
                $vis = ap_visibility_from_activity($activity);
                // Announce of our post → target=us; Announce of others (rare here) → original author
                $tgt = LOCAL_ACTOR;
                if ($type === 'Announce') {
                    $orig = ap_announce_original_actor_from_activity($activity, is_string($objectId) ? $objectId : null);
                    if (is_string($orig) && $orig !== '') {
                        $tgt = $orig;
                    }
                }
                ap_metrics_record($type, $actorId, $objectId, $tgt, $bytes, 'local_observe', $summaryForEvent, $mediaForEvent, null, $inReplyToForEvent, $spoilerForEvent, $sensitiveForEvent, $vis);
                ap_log('local_interaction_observe type=' . $type . ' actor=' . ap_short((string) $actorId));
                return 'local_observe';
            }
            // Followers-only / unlisted posts delivered because we follow the author
            // belong on Home — do not drop them as "private".
            if (
                in_array($type, ['Create', 'Announce', 'Update'], true)
                && is_string($actorId)
                && $actorId !== ''
                && function_exists('ap_actor_is_followed')
                && ap_actor_is_followed($actorId)
            ) {
                $sum = $summaryForEvent;
                if (($sum === null || $sum === '') && $mediaForEvent) {
                    $sum = '(attachment)';
                }
                ap_local_observe($activity);
                $vis = ap_visibility_from_activity($activity);
                if ($type === 'Update' && is_string($objectId) && $objectId !== '' && function_exists('ap_events_refresh_create_from_update')) {
                    ap_events_refresh_create_from_update(
                        $objectId,
                        $sum,
                        $mediaForEvent,
                        $inReplyToForEvent,
                        $spoilerForEvent,
                        $sensitiveForEvent,
                        $vis
                    );
                }
                $tgt = LOCAL_ACTOR;
                if ($type === 'Announce') {
                    $orig = ap_announce_original_actor_from_activity($activity, is_string($objectId) ? $objectId : null);
                    if (is_string($orig) && $orig !== '') {
                        $tgt = $orig;
                    }
                }
                ap_metrics_record($type, $actorId, $objectId, $tgt, $bytes, 'local_observe', $sum, $mediaForEvent, null, $inReplyToForEvent, $spoilerForEvent, $sensitiveForEvent, $vis);
                ap_log('local_followers_only_observe type=' . $type . ' actor=' . ap_short((string) $actorId) . ' vis=' . $vis);
                return 'local_observe';
            }
            ap_metrics_record($type, $actorId, $objectId, LOCAL_ACTOR, $bytes, 'local_private_skipped', null);
            ap_log('local_private_skipped type=' . $type . ' actor=' . ap_short((string) $actorId));
            return 'local_private_skipped';
        }
        ap_local_observe($activity);
        $vis = ap_visibility_from_activity($activity);
        if ($type === 'Update' && is_string($objectId) && $objectId !== '' && function_exists('ap_events_refresh_create_from_update')) {
            ap_events_refresh_create_from_update(
                $objectId,
                $summaryForEvent,
                $mediaForEvent,
                $inReplyToForEvent,
                $spoilerForEvent,
                $sensitiveForEvent,
                $vis
            );
        }
        $tgt = LOCAL_ACTOR;
        if ($type === 'Announce') {
            $orig = ap_announce_original_actor_from_activity($activity, is_string($objectId) ? $objectId : null);
            if (is_string($orig) && $orig !== '') {
                $tgt = $orig;
            }
        }
        ap_metrics_record($type, $actorId, $objectId, $tgt, $bytes, 'local_observe', $summaryForEvent, $mediaForEvent, null, $inReplyToForEvent, $spoilerForEvent, $sensitiveForEvent, $vis);
        return 'local_observe';
    }

    // Update of a Note we favourited → "edited" notification (never our own edits).
    // PeerTube federates frequent Video Updates (live metadata) that are not edits.
    if (
        $type === 'Update'
        && is_string($objectId)
        && $objectId !== ''
        && is_string($actorId)
        && $actorId !== ''
        && $actorId !== LOCAL_ACTOR
        && !str_starts_with($objectId, LOCAL_ACTOR . '/')
        && !preg_match('#/(videos|w)/#i', $objectId)
        && function_exists('ap_masto_favourite_by_object_id')
        && ap_masto_favourite_by_object_id($objectId)
    ) {
        $updObj = $activity['object'] ?? null;
        $updObjType = '';
        if (is_array($updObj) && isset($updObj['type']) && is_string($updObj['type'])) {
            $updObjType = strtolower($updObj['type']);
        }
        // Only Mastodon-style editable posts
        if (in_array($updObjType, ['note', 'article', 'question', ''], true)) {
            ap_local_observe($activity);
            $vis = ap_visibility_from_activity($activity);
            if (function_exists('ap_events_refresh_create_from_update')) {
                ap_events_refresh_create_from_update(
                    $objectId,
                    $summaryForEvent,
                    $mediaForEvent,
                    $inReplyToForEvent,
                    $spoilerForEvent,
                    $sensitiveForEvent,
                    $vis
                );
            }
            ap_metrics_record($type, $actorId, $objectId, LOCAL_ACTOR, $bytes, 'local_fav_update', $summaryForEvent, $mediaForEvent, null, $inReplyToForEvent, $spoilerForEvent, $sensitiveForEvent, $vis);
            ap_log('local_fav_update object=' . ap_short($objectId) . ' actor=' . ap_short($actorId));
            return 'local_fav_update';
        }
    }

    // Generic log: never store content summaries for non-public activities
    $summary = ap_activity_is_public($activity) ? $summaryForEvent : null;
    $vis = ap_visibility_from_activity($activity);
    if (
        $type === 'Update'
        && is_string($objectId)
        && $objectId !== ''
        && $summary !== null
        && function_exists('ap_events_refresh_create_from_update')
    ) {
        ap_events_refresh_create_from_update(
            $objectId,
            $summary,
            $mediaForEvent ?: null,
            $inReplyToForEvent,
            $summary !== null ? $spoilerForEvent : '',
            $summary !== null && $sensitiveForEvent,
            $vis
        );
    }
    // For Announces, store original Note author in target_actor so timelines can
    // wrap boosts correctly (booster ≠ original author).
    $announceTarget = null;
    if ($type === 'Announce') {
        $announceTarget = ap_announce_original_actor_from_activity($activity, is_string($objectId) ? $objectId : null);
    }
    ap_metrics_record(
        $type,
        $actorId,
        $objectId,
        $announceTarget,
        $bytes,
        'log',
        $summary,
        $mediaForEvent ?: null,
        null,
        $inReplyToForEvent,
        $summary !== null ? $spoilerForEvent : '',
        $summary !== null && $sensitiveForEvent,
        $vis
    );
    return 'log';
}

/**
 * Best-effort original Note author for an inbound Announce activity.
 */
function ap_announce_original_actor_from_activity(array $activity, ?string $objectId): ?string
{
    $obj = $activity['object'] ?? null;
    // Nested Note inside Announce
    if (is_array($obj)) {
        $inner = $obj;
        if (isset($obj['object']) && is_array($obj['object'])) {
            $inner = $obj['object'];
        }
        $at = ap_as_id($inner['attributedTo'] ?? null) ?: ap_as_id($inner['actor'] ?? null);
        if (is_string($at) && str_starts_with($at, 'https://')) {
            return rtrim($at, '/');
        }
        // Object may itself be an actor URL string (rare) — ignore
    }
    $oid = is_string($objectId) ? rtrim($objectId, '/') : '';
    if ($oid === '' && is_string($obj)) {
        $oid = rtrim($obj, '/');
    }
    if ($oid !== '' && str_starts_with($oid, 'https://mkultra.monster/users/cmdr_nova/')) {
        return 'https://mkultra.monster/users/cmdr_nova';
    }
    if ($oid !== '' && function_exists('ap_masto_actor_url_from_object_url')) {
        $guess = ap_masto_actor_url_from_object_url($oid);
        if (is_string($guess) && $guess !== '') {
            return rtrim($guess, '/');
        }
    }
    // Enrichment may already have fetched the remote Note
    if ($oid !== '' && str_starts_with($oid, 'https://')) {
        $remote = ap_fetch_as2_object($oid);
        if (is_array($remote)) {
            $remote = ap_unwrap_as2_object($remote) ?? $remote;
            $at = ap_as_id($remote['attributedTo'] ?? null) ?: ap_as_id($remote['actor'] ?? null);
            if (is_string($at) && str_starts_with($at, 'https://')) {
                return rtrim($at, '/');
            }
        }
    }
    return null;
}

/**
 * Pull Mastodon-style content warning fields from an AS2 Note-like doc.
 * AP: summary = CW label, content = body, sensitive = bool.
 *
 * @param array<string,mixed>|null $doc
 * @return array{spoiler_text:string,sensitive:bool}
 */
function ap_note_cw_from_doc(?array $doc): array
{
    if (!is_array($doc)) {
        return ['spoiler_text' => '', 'sensitive' => false];
    }
    if (function_exists('ap_unwrap_as2_object')) {
        $doc = ap_unwrap_as2_object($doc);
    }
    $spoiler = '';
    if (isset($doc['summary']) && is_string($doc['summary']) && trim($doc['summary']) !== '') {
        $spoiler = function_exists('ap_html_to_plain_text')
            ? trim(ap_html_to_plain_text($doc['summary']))
            : trim(html_entity_decode(strip_tags($doc['summary']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $spoiler = mb_substr(ap_fix_utf8($spoiler), 0, 500);
    }
    $sensitive = !empty($doc['sensitive']) || $spoiler !== '';
    return ['spoiler_text' => $spoiler, 'sensitive' => $sensitive];
}

/**
 * Collect text + image URLs for the admin federation feed.
 * When Create/Announce/Quote* arrives thin (string object, QuotePost URL, or Note
 * without attachment), fetch the remote object so boosts, quotes, and image posts
 * still get previews.
 *
 * @return array{summary: ?string, media: list<string>, in_reply_to: ?string, spoiler_text: string, sensitive: bool}
 */
function ap_enrich_activity_for_feed(array $activity, string $type, ?string $objectId): array
{
    $summary = ap_activity_text_summary($activity);
    $media = [];
    $inReplyTo = null;
    $obj = $activity['object'] ?? null;
    $cw = ap_note_cw_from_doc(is_array($obj) ? $obj : null);
    $quoteTypes = ['QuotePost', 'Quote', 'QuoteRequest'];
    $isQuoteActivity = in_array($type, $quoteTypes, true)
        || (is_array($obj) && in_array((string) ($obj['type'] ?? ''), $quoteTypes, true))
        || ($objectId !== null && str_ends_with(rtrim($objectId, '/'), '/QuotePost'));

    if (is_array($obj)) {
        $media = ap_extract_media_urls($obj);
        $inReplyTo = ap_as_id($obj['inReplyTo'] ?? null);
        if (($type === 'Announce' || $isQuoteActivity) && isset($obj['object']) && is_array($obj['object'])) {
            $media = array_values(array_unique(array_merge($media, ap_extract_media_urls($obj['object']))));
            if (($summary === null || $summary === '') && isset($obj['object']['content']) && is_string($obj['object']['content'])) {
                $summary = ap_fix_utf8($obj['object']['content']);
            }
            if ($inReplyTo === null) {
                $inReplyTo = ap_as_id($obj['object']['inReplyTo'] ?? null);
            }
        }
    }

    $fetchableTypes = array_merge(['Create', 'Announce', 'Update'], $quoteTypes);
    $shouldConsiderFetch = in_array($type, $fetchableTypes, true)
        && $objectId !== null
        && str_starts_with($objectId, 'https://')
        && !str_starts_with($objectId, 'https://mkultra.monster/');

    $remote = null;
    if ($shouldConsiderFetch) {
        // Fetch when thin, missing text, attachments present but extract empty
        // (common for Mastodon gifv Documents), boost without media, or quotes.
        $hasAttachment = is_array($obj) && !empty($obj['attachment']);
        $needsFetch = !is_array($obj)
            || $summary === null
            || $summary === ''
            || ($type === 'Announce' && !$media)
            || ($hasAttachment && !$media)
            || $isQuoteActivity;

        if ($needsFetch) {
            $remote = ap_fetch_as2_object($objectId);
            // Mastodon …/QuotePost URLs often 404 for unsigned/authorized-fetch; try parent status.
            if ($remote === null || (is_array($remote) && empty($remote['content']) && ($remote['type'] ?? '') === 'QuotePost')) {
                $parent = ap_quote_post_parent_url($objectId);
                if ($parent) {
                    $parentDoc = ap_fetch_as2_object($parent);
                    if (is_array($parentDoc)) {
                        $remote = $parentDoc;
                    }
                }
            }
            if (is_array($remote)) {
                $media = array_values(array_unique(array_merge($media, ap_extract_media_urls($remote))));
                if (($summary === null || $summary === '') && isset($remote['content']) && is_string($remote['content'])) {
                    $summary = ap_fix_utf8($remote['content']);
                }
                if ($inReplyTo === null) {
                    $inReplyTo = ap_as_id($remote['inReplyTo'] ?? null);
                }
                if ($cw['spoiler_text'] === '') {
                    $cwRemote = ap_note_cw_from_doc($remote);
                    if ($cwRemote['spoiler_text'] !== '' || $cwRemote['sensitive']) {
                        $cw = $cwRemote;
                    }
                }
                if (isset($remote['object']) && is_array($remote['object'])) {
                    $inner = $remote['object'];
                    $media = array_values(array_unique(array_merge($media, ap_extract_media_urls($inner))));
                    if (($summary === null || $summary === '') && isset($inner['content']) && is_string($inner['content'])) {
                        $summary = ap_fix_utf8($inner['content']);
                    }
                    if ($inReplyTo === null) {
                        $inReplyTo = ap_as_id($inner['inReplyTo'] ?? null);
                    }
                    if ($cw['spoiler_text'] === '') {
                        $cwInner = ap_note_cw_from_doc($inner);
                        if ($cwInner['spoiler_text'] !== '' || $cwInner['sensitive']) {
                            $cw = $cwInner;
                        }
                    }
                }
            }
        }
    }
    // Nested Note on Announce/Create without remote fetch
    if ($cw['spoiler_text'] === '' && is_array($obj) && isset($obj['object']) && is_array($obj['object'])) {
        $cwNested = ap_note_cw_from_doc($obj['object']);
        if ($cwNested['spoiler_text'] !== '' || $cwNested['sensitive']) {
            $cw = $cwNested;
        }
    }

    // Attach quoted post preview (commentary already in $summary when present).
    $quoteSource = is_array($remote) ? $remote : (is_array($obj) ? $obj : null);
    if (is_array($quoteSource) && isset($quoteSource['object']) && is_array($quoteSource['object'])
        && in_array((string) ($quoteSource['type'] ?? ''), ['Create', 'Announce', 'Quote', 'QuotePost'], true)) {
        $quoteSource = $quoteSource['object'];
    }
    $quotePack = is_array($quoteSource) ? ap_quote_target_pack($quoteSource) : ['url' => null, 'embedded' => null, 'tombstone' => false];
    if (($quotePack['url'] === null && empty($quotePack['embedded']) && !$quotePack['tombstone']) && is_array($obj)) {
        $quotePack = ap_quote_target_pack($obj);
    }
    $quotedUrl = $quotePack['url'];
    if ($quotePack['tombstone']) {
        $combined = ap_format_quote_feed_summary($summary, null, $quotedUrl, true);
        if ($combined !== null) {
            $summary = $combined;
        }
    } elseif ($quotedUrl !== null || is_array($quotePack['embedded'])) {
        $quotedDoc = is_array($quotePack['embedded']) ? $quotePack['embedded'] : null;
        // Prefer Note URL over …/activity Create URL when fetching
        $fetchUrl = $quotedUrl;
        if (is_string($fetchUrl) && str_ends_with(rtrim($fetchUrl, '/'), '/activity')) {
            $maybeNote = preg_replace('#/activity$#', '', rtrim($fetchUrl, '/'));
            if (is_string($maybeNote) && $maybeNote !== '') {
                $fetchUrl = $maybeNote;
            }
        }
        // Local notes: read from outbox DB (avoid self-HTTP / wrong Accept quirks)
        if ($quotedDoc === null && is_string($fetchUrl)
            && str_starts_with($fetchUrl, 'https://mkultra.monster/users/cmdr_nova/notes/')) {
            $quotedDoc = ap_local_note_as2_doc($fetchUrl);
        }
        if ($fetchUrl !== null && ($quotedDoc === null || (!isset($quotedDoc['content']) && !ap_quote_is_tombstone($quotedDoc)))) {
            $fetched = ap_fetch_as2_object($fetchUrl);
            if (is_array($fetched)) {
                $quotedDoc = $fetched;
            }
        }
        if (is_array($quotedDoc)) {
            $quotedDoc = ap_unwrap_as2_object($quotedDoc);
        }
        $combined = ap_format_quote_feed_summary(
            $summary,
            is_array($quotedDoc) ? $quotedDoc : null,
            $quotedUrl,
            is_array($quotedDoc) && ap_quote_is_tombstone($quotedDoc)
        );
        if ($combined !== null) {
            $summary = $combined;
        }
        if (is_array($quotedDoc) && !ap_quote_is_tombstone($quotedDoc)) {
            $media = array_values(array_unique(array_merge($media, ap_extract_media_urls($quotedDoc))));
        }
    } elseif ($isQuoteActivity && ($summary === null || $summary === '') && $objectId) {
        // Last resort label so the feed isn't a blank "(no text preview)"
        $summary = 'Quote post';
        $parent = ap_quote_post_parent_url($objectId);
        if ($parent) {
            $summary .= ' of ' . $parent;
        }
    }
    // Never persist raw AS2 JSON blobs as the feed summary
    if (is_string($summary) && ap_text_looks_like_as2_json($summary)) {
        $summary = $isQuoteActivity ? 'Quote post' : null;
    }

    if (is_string($inReplyTo) && str_starts_with($inReplyTo, 'https://')) {
        $inReplyTo = rtrim($inReplyTo, '/');
    } else {
        $inReplyTo = null;
    }

    return [
        'summary' => $summary,
        'media' => $media,
        'in_reply_to' => $inReplyTo,
        'spoiler_text' => (string) ($cw['spoiler_text'] ?? ''),
        'sensitive' => !empty($cw['sensitive']),
    ];
}

/**
 * Decode a remote AS2/JSON body into an object doc, skipping error payloads.
 *
 * @return array<string,mixed>|null
 */
function ap_decode_as2_body(?string $body): ?array
{
    if (!is_string($body) || $body === '') {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }
    // Mastodon/AF error bodies: {"error":"Request not signed"} etc.
    if (isset($data['error']) && !isset($data['type']) && !isset($data['id']) && !isset($data['inbox'])) {
        return null;
    }
    if (isset($data['success']) && $data['success'] === false) {
        return null;
    }
    return $data;
}

/**
 * Fetch remote AS2 JSON with authorized fetch (signed as @cmdr_nova) first,
 * then unsigned fallback for public hosts. Bridgy prefers unsigned.
 *
 * @return array<string,mixed>|null
 */
function ap_fetch_remote_as2(string $url): ?array
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 800 || !str_starts_with($url, 'https://')) {
        return null;
    }
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    $preferUnsigned = $host !== '' && (str_ends_with($host, 'brid.gy') || $host === 'brid.gy');

    $bodies = $preferUnsigned
        ? [ap_unsigned_get($url), ap_signed_get($url)]
        : [ap_signed_get($url), ap_unsigned_get($url)];

    foreach ($bodies as $body) {
        $doc = ap_decode_as2_body(is_string($body) ? $body : null);
        if ($doc !== null) {
            return $doc;
        }
    }
    return null;
}

/** Fetch a remote AS2 object (Note/Create/etc.) for feed enrichment / replies. */
function ap_fetch_as2_object(string $url): ?array
{
    $url = trim($url);
    // Prefer local outbox for our own notes (self-reply threads; avoid HTTP round-trip).
    if (
        $url !== ''
        && str_starts_with(rtrim($url, '/'), 'https://mkultra.monster/users/cmdr_nova/notes/')
        && function_exists('ap_local_note_as2_doc')
    ) {
        $local = ap_local_note_as2_doc($url);
        if (is_array($local)) {
            return $local;
        }
    }
    return ap_fetch_remote_as2($url);
}

function ap_object_targets_actor($object, string $actorId): bool
{
    $actorId = rtrim($actorId, '/');
    if (is_string($object)) {
        return rtrim($object, '/') === $actorId;
    }
    if (!is_array($object)) {
        return false;
    }
    // Unwrap ["https://…/cmdr_nova"] from Bridgy Fed / JSON-LD
    if (array_is_list($object) && count($object) === 1) {
        return ap_object_targets_actor($object[0], $actorId);
    }
    $id = ap_as_id($object);
    if ($id && rtrim($id, '/') === $actorId) {
        return true;
    }
    if (($object['type'] ?? '') === 'Follow') {
        return ap_object_targets_actor($object['object'] ?? null, $actorId);
    }
    return false;
}

function ap_object_targets_cmdr($object): bool
{
    return ap_object_targets_actor($object, CMDR_NOVA_ACTOR);
}

function ap_audience_values(array $activity): array
{
    $out = [];
    $collect = static function ($v) use (&$out): void {
        if (is_string($v) && $v !== '') {
            $out[] = $v;
            return;
        }
        if (!is_array($v)) {
            return;
        }
        foreach ($v as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            } elseif (is_array($item)) {
                $id = ap_as_id($item);
                if ($id) {
                    $out[] = $id;
                }
            }
        }
    };
    foreach (['to', 'cc', 'bto', 'bcc'] as $field) {
        $collect($activity[$field] ?? null);
    }
    $obj = $activity['object'] ?? null;
    if (is_array($obj)) {
        foreach (['to', 'cc', 'bto', 'bcc'] as $field) {
            $collect($obj[$field] ?? null);
        }
    }
    return $out;
}

/**
 * True if addressed to as:Public (public post / public mention).
 * Private DMs typically address only the recipient with no Public audience.
 */
function ap_as_id_is_public_audience(string $id): bool
{
    return $id === 'https://www.w3.org/ns/activitystreams#Public'
        || $id === 'as:Public'
        || $id === 'Public'
        || str_ends_with($id, '#Public');
}

function ap_activity_is_public(array $activity): bool
{
    foreach (ap_audience_values($activity) as $id) {
        if (ap_as_id_is_public_audience((string) $id)) {
            return true;
        }
    }
    return false;
}

/**
 * Mastodon-compatible visibility from addressing:
 * - Public in `to` → public
 * - Public only in `cc` (typical unlisted) → unlisted
 * - No Public → private (followers-only)
 *
 * @param list<mixed> $to
 * @param list<mixed> $cc
 */
function ap_visibility_from_to_cc(array $to, array $cc): string
{
    $ids = static function (array $list): array {
        $out = [];
        foreach ($list as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            } elseif (is_array($item)) {
                $id = ap_as_id($item);
                if ($id) {
                    $out[] = $id;
                }
            }
        }
        return $out;
    };
    $toIds = $ids($to);
    $ccIds = $ids($cc);
    foreach ($toIds as $id) {
        if (ap_as_id_is_public_audience($id)) {
            return 'public';
        }
    }
    foreach ($ccIds as $id) {
        if (ap_as_id_is_public_audience($id)) {
            return 'unlisted';
        }
    }
    return 'private';
}

/** Visibility for an inbound/outbound AS2 Create/Announce/Update activity. */
function ap_visibility_from_activity(array $activity): string
{
    $to = [];
    $cc = [];
    foreach (['to' => &$to, 'cc' => &$cc] as $field => &$bucket) {
        $raw = $activity[$field] ?? null;
        if (is_string($raw) && $raw !== '') {
            $bucket[] = $raw;
        } elseif (is_array($raw)) {
            foreach ($raw as $item) {
                $bucket[] = $item;
            }
        }
    }
    unset($bucket);
    $obj = $activity['object'] ?? null;
    if (is_array($obj)) {
        foreach (['to', 'cc'] as $field) {
            $raw = $obj[$field] ?? null;
            if (is_string($raw) && $raw !== '') {
                if ($field === 'to') {
                    $to[] = $raw;
                } else {
                    $cc[] = $raw;
                }
            } elseif (is_array($raw)) {
                foreach ($raw as $item) {
                    if ($field === 'to') {
                        $to[] = $item;
                    } else {
                        $cc[] = $item;
                    }
                }
            }
        }
    }
    return ap_visibility_from_to_cc($to, $cc);
}

/**
 * True when this is a direct message to @cmdr_nova (no Public, not followers-only).
 * Mastodon DMs: Create Note with to=[recipient] and no as:Public.
 * Poll votes look similar but are NOT DMs (see ap_activity_extract_poll_vote).
 */
function ap_activity_is_direct_message(array $activity): bool
{
    if (ap_activity_is_public($activity)) {
        return false;
    }
    $type = (string) ($activity['type'] ?? '');
    if (!in_array($type, ['Create', 'Update'], true)) {
        return false;
    }
    // Mastodon/GTS poll votes are private Creates to the poll author — not DMs.
    if (ap_activity_extract_poll_vote($activity) !== null) {
        return false;
    }
    $audience = ap_audience_values($activity);
    $addressesLocal = false;
    foreach ($audience as $id) {
        $id = (string) $id;
        if ($id === '' ) {
            continue;
        }
        // Followers-only / unlisted-to-followers — not a DM
        if (str_contains($id, '/followers')) {
            return false;
        }
        if (rtrim($id, '/') === rtrim(LOCAL_ACTOR, '/') || str_contains($id, '/users/cmdr_nova')) {
            $addressesLocal = true;
        }
    }
    if (!$addressesLocal && !ap_activity_touches_local($activity)) {
        return false;
    }
    // Prefer Note-like objects
    $obj = $activity['object'] ?? null;
    if (is_array($obj)) {
        $ot = (string) ($obj['type'] ?? 'Note');
        if (in_array($ot, ['Person', 'Application', 'Service', 'Group', 'Follow', 'Accept', 'Reject'], true)) {
            return false;
        }
    }
    return true;
}

/**
 * Detect Mastodon/GTS poll vote: Create Note inReplyTo our Question, choice in name.
 *
 * @return null|array{ok:bool,poll_local_id:int,actor:string,choices?:list<string>,error?:string}
 */
function ap_activity_extract_poll_vote(array $activity): ?array
{
    if (($activity['type'] ?? '') !== 'Create') {
        return null;
    }
    $actorId = ap_as_id($activity['actor'] ?? null);
    if (!$actorId) {
        return null;
    }
    $object = $activity['object'] ?? null;
    if (!is_array($object)) {
        return null;
    }
    $inReplyTo = ap_as_id($object['inReplyTo'] ?? null);
    if (!$inReplyTo || !str_starts_with(rtrim($inReplyTo, '/'), LOCAL_ACTOR . '/notes/')) {
        return null;
    }
    try {
        $pst = ap_db()->prepare('SELECT local_id, options_json FROM masto_polls WHERE note_id = ? OR note_id = ?');
        $pst->execute([rtrim($inReplyTo, '/'), rtrim($inReplyTo, '/') . '/']);
        $prow = $pst->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($prow)) {
        return null;
    }
    $options = json_decode((string) ($prow['options_json'] ?? '[]'), true);
    if (!is_array($options) || !$options) {
        return null;
    }
    $titles = [];
    foreach ($options as $opt) {
        if (!is_array($opt)) {
            continue;
        }
        $t = trim((string) ($opt['title'] ?? $opt['name'] ?? ''));
        if ($t !== '') {
            $titles[] = $t;
        }
    }
    if (!$titles) {
        return null;
    }

    $choices = [];
    if (isset($object['name']) && is_string($object['name'])) {
        $n = trim($object['name']);
        if ($n !== '' && in_array($n, $titles, true)) {
            $choices[] = $n;
        }
    }
    if (!$choices && isset($object['content']) && is_string($object['content'])) {
        $c = trim(html_entity_decode(strip_tags($object['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($c !== '' && in_array($c, $titles, true)) {
            $choices[] = $c;
        }
    }

    $pollLocalId = (int) $prow['local_id'];
    $oid = (string) (ap_as_id($object) ?: '');
    $aid = (string) (ap_as_id($activity) ?: '');
    $looksLikeVote = str_contains($oid, '#votes') || str_contains($aid, '#votes') || $choices !== [];

    if (!$looksLikeVote) {
        // inReplyTo is our poll but this may be a normal reply — not a vote
        return null;
    }
    if (!$choices) {
        return [
            'ok' => false,
            'poll_local_id' => $pollLocalId,
            'actor' => $actorId,
            'error' => 'vote_missing_choice',
        ];
    }
    return [
        'ok' => true,
        'poll_local_id' => $pollLocalId,
        'actor' => $actorId,
        'choices' => $choices,
    ];
}

/**
 * Persist an inbound DM Create/Update. Does not write content to the public events feed.
 *
 * @return array{ok:bool,error?:string,id?:int}
 */
function ap_dm_ingest_activity(array $activity): array
{
    $actorId = ap_as_id($activity['actor'] ?? null);
    if (!$actorId || ap_is_blocked_actor($actorId)) {
        return ['ok' => false, 'error' => 'blocked or missing actor'];
    }
    $object = $activity['object'] ?? null;
    $objectId = ap_as_id($object);
    $content = null;
    $inReplyTo = null;
    $mediaUrls = [];
    $attachments = null;
    if (is_array($object)) {
        if (isset($object['content']) && is_string($object['content'])) {
            $content = $object['content'];
        }
        $inReplyTo = ap_as_id($object['inReplyTo'] ?? null);
        $mediaUrls = ap_extract_media_urls($object);
        if (isset($object['attachment'])) {
            $attachments = $object['attachment'];
        }
        if (!$objectId) {
            $objectId = ap_as_id($activity) ?: null;
        }
    } elseif (is_string($object) && str_starts_with($object, 'https://')) {
        $objectId = $object;
        // Only fetch if not our own URL; prefer not to SSRF to private hosts (signed get already gated)
        if (!str_starts_with($object, 'https://mkultra.monster/')) {
            $remote = ap_fetch_as2_object($object);
            if (is_array($remote)) {
                if (isset($remote['content']) && is_string($remote['content'])) {
                    $content = $remote['content'];
                }
                $inReplyTo = ap_as_id($remote['inReplyTo'] ?? null);
                $mediaUrls = ap_extract_media_urls($remote);
                if (isset($remote['attachment'])) {
                    $attachments = $remote['attachment'];
                }
            }
        }
    }
    // Bridgy sometimes puts attachments on the Create activity itself
    if ($attachments === null && isset($activity['attachment'])) {
        $attachments = $activity['attachment'];
    }
    if (!$objectId) {
        return ['ok' => false, 'error' => 'missing object id'];
    }
    $published = null;
    if (is_array($object) && isset($object['published']) && is_string($object['published'])) {
        $published = $object['published'];
    } elseif (isset($activity['published']) && is_string($activity['published'])) {
        $published = $activity['published'];
    }
    $owner = ap_inbox_recipient_owner($activity);
    return ap_dm_store([
        'activity_id' => ap_as_id($activity),
        'object_id' => $objectId,
        'peer_actor_id' => $actorId,
        'direction' => 'in',
        'content' => $content,
        'in_reply_to' => $inReplyTo,
        'media_urls' => $mediaUrls,
        'attachments' => $attachments,
        'created_at' => $published ?: ap_db_now(),
        'owner_user_id' => (int) ($owner['owner_user_id'] ?? 0),
        'owner_actor_id' => (string) ($owner['owner_actor_id'] ?? ''),
    ]);
}

/**
 * True when the activity/object audience, Mention tags, or content
 * specifically addresses $localActorId (not merely as:Public / followers).
 */
function ap_activity_addresses_local_actor(array $activity, string $localActorId): bool
{
    $localActorId = rtrim(trim($localActorId), '/');
    if ($localActorId === '') {
        return false;
    }
    foreach (ap_audience_values($activity) as $id) {
        if (rtrim((string) $id, '/') === $localActorId) {
            return true;
        }
    }
    $objs = [];
    $obj = $activity['object'] ?? null;
    if (is_array($obj)) {
        $objs[] = $obj;
        if (isset($obj['object']) && is_array($obj['object'])) {
            $objs[] = $obj['object'];
        }
    }
    $key = basename($localActorId);
    $host = parse_url($localActorId, PHP_URL_HOST);
    $host = is_string($host) ? strtolower($host) : 'mkultra.monster';
    foreach ($objs as $o) {
        $tag = $o['tag'] ?? [];
        if (!is_array($tag)) {
            continue;
        }
        foreach ($tag as $t) {
            if (!is_array($t) || (($t['type'] ?? '') !== 'Mention')) {
                continue;
            }
            $href = ap_as_id($t['href'] ?? null);
            if (is_string($href) && rtrim($href, '/') === $localActorId) {
                return true;
            }
            $name = strtolower(trim((string) ($t['name'] ?? '')));
            if ($key !== '' && (
                $name === '@' . strtolower($key)
                || $name === '@' . strtolower($key) . '@' . $host
                || $name === 'acct:' . strtolower($key) . '@' . $host
            )) {
                return true;
            }
        }
    }
    $content = '';
    if (is_array($obj) && isset($obj['content']) && is_string($obj['content'])) {
        $content = $obj['content'];
    } elseif (is_array($obj) && isset($obj['object']) && is_array($obj['object'])
        && isset($obj['object']['content']) && is_string($obj['object']['content'])) {
        $content = $obj['object']['content'];
    } elseif (isset($activity['content']) && is_string($activity['content'])) {
        $content = $activity['content'];
    }
    if ($content !== '') {
        if (function_exists('ap_content_addresses_local_actor')) {
            if (ap_content_addresses_local_actor($content, $localActorId)) {
                return true;
            }
        } elseif (str_contains($content, $localActorId)) {
            return true;
        }
    }
    return false;
}

function ap_activity_touches_local(array $activity): bool
{
    $blob = json_encode($activity, JSON_UNESCAPED_SLASHES) ?: '';
    if (str_contains($blob, LOCAL_ACTOR) || str_contains($blob, 'acct:cmdr_nova@mkultra.monster')) {
        return true;
    }
    // Any local VAAK actor (/users/{key})
    if (preg_match('#https://mkultra\.monster/users/[A-Za-z0-9_]+#', $blob)
        || preg_match('/acct:[A-Za-z0-9_]+@mkultra\.monster/i', $blob)) {
        return true;
    }
    foreach (ap_audience_values($activity) as $id) {
        if ($id === LOCAL_ACTOR || str_contains($id, 'mkultra.monster/users/')) {
            return true;
        }
    }
    $obj = $activity['object'] ?? null;
    if (is_array($obj)) {
        $tag = $obj['tag'] ?? [];
        if (is_array($tag)) {
            foreach ($tag as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $name = (string) ($t['name'] ?? '');
                $href = ap_as_id($t['href'] ?? null);
                if (($t['type'] ?? '') === 'Mention' && (
                    (is_string($href) && str_contains($href, 'mkultra.monster/users/'))
                    || str_ends_with(strtolower($name), '@mkultra.monster')
                )) {
                    return true;
                }
            }
        }
    }
    return false;
}

function ap_activity_text_summary(array $activity): ?string
{
    $obj = $activity['object'] ?? null;
    if (is_array($obj)) {
        if (isset($obj['content']) && is_string($obj['content']) && $obj['content'] !== '') {
            return ap_fix_utf8($obj['content']);
        }
        // QuotePost / Quote wrappers sometimes nest the Note
        if (isset($obj['object']) && is_array($obj['object'])
            && isset($obj['object']['content']) && is_string($obj['object']['content'])) {
            return ap_fix_utf8($obj['object']['content']);
        }
        if (isset($obj['name']) && is_string($obj['name']) && $obj['name'] !== '') {
            return ap_fix_utf8($obj['name']);
        }
        if (isset($obj['summary']) && is_string($obj['summary']) && $obj['summary'] !== '') {
            return ap_fix_utf8($obj['summary']);
        }
    }
    if (isset($activity['content']) && is_string($activity['content']) && $activity['content'] !== '') {
        return ap_fix_utf8($activity['content']);
    }
    return null;
}

/**
 * Load a local outbox Note as an AS2 object (for quote previews without self-fetch).
 *
 * @return array<string,mixed>|null
 */
function ap_local_note_as2_doc(string $noteId): ?array
{
    $noteId = rtrim(trim($noteId), '/');
    if ($noteId === '' || !str_starts_with($noteId, 'https://mkultra.monster/users/cmdr_nova/notes/')) {
        return null;
    }
    try {
        $st = ap_db()->prepare('SELECT id, content, published, raw_create_json FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
        $st->execute([$noteId, $noteId . '/']);
        $row = $st->fetch();
        if (!is_array($row)) {
            return null;
        }
        $raw = (string) ($row['raw_create_json'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $obj = $decoded['object'] ?? $decoded;
                if (is_array($obj) && (($obj['type'] ?? '') === 'Note' || isset($obj['content']))) {
                    return $obj;
                }
            }
        }
        return [
            'id' => (string) ($row['id'] ?? $noteId),
            'type' => 'Note',
            'attributedTo' => 'https://mkultra.monster/users/cmdr_nova',
            'content' => (string) ($row['content'] ?? ''),
            'published' => (string) ($row['published'] ?? ''),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Unwrap Create/Announce/Update wrappers to the inner Note/object when present.
 *
 * @param array<string,mixed> $doc
 * @return array<string,mixed>
 */
function ap_unwrap_as2_object(array $doc): array
{
    $type = (string) ($doc['type'] ?? '');
    if (in_array($type, ['Create', 'Announce', 'Update', 'Quote', 'QuotePost'], true)
        && isset($doc['object']) && is_array($doc['object'])) {
        return $doc['object'];
    }
    return $doc;
}

/** True when a quote target is a deleted/unavailable Tombstone. */
function ap_quote_is_tombstone(mixed $quote): bool
{
    if (!is_array($quote)) {
        return false;
    }
    return strcasecmp((string) ($quote['type'] ?? ''), 'Tombstone') === 0;
}

/**
 * Resolve quote target from a Note: URL and/or already-embedded object.
 *
 * @param array<string,mixed> $object
 * @return array{url:?string,embedded:?array,tombstone:bool}
 */
function ap_quote_target_pack(array $object): array
{
    $out = ['url' => null, 'embedded' => null, 'tombstone' => false];
    foreach (['quote', 'quoteUri', 'quoteUrl', '_misskey_quote'] as $key) {
        if (!isset($object[$key])) {
            continue;
        }
        $val = $object[$key];
        if (ap_quote_is_tombstone($val)) {
            $out['tombstone'] = true;
            // Keep any id on the tombstone for linking if present
            $tid = ap_as_id($val);
            if ($tid !== null && str_starts_with($tid, 'https://')) {
                $out['url'] = $tid;
            }
            return $out;
        }
        if (is_array($val) && (($val['type'] ?? '') !== '' || isset($val['content']) || isset($val['object']))) {
            $out['embedded'] = $val;
            $id = ap_as_id($val);
            if ($id !== null && str_starts_with($id, 'https://')) {
                $out['url'] = $id;
            }
            // Prefer inner Note id when quote embeds a Create
            $inner = ap_unwrap_as2_object($val);
            $innerId = ap_as_id($inner);
            if ($innerId !== null && str_starts_with($innerId, 'https://')
                && !str_ends_with(rtrim($innerId, '/'), '/activity')) {
                $out['url'] = $innerId;
            }
            return $out;
        }
        $id = ap_as_id($val);
        if ($id !== null && str_starts_with($id, 'https://')) {
            $out['url'] = $id;
            return $out;
        }
    }
    // FEP-e232 Object Links in tag[]
    if (isset($object['tag']) && is_array($object['tag'])) {
        $tags = $object['tag'];
        if (isset($tags['type']) || isset($tags['href'])) {
            $tags = [$tags];
        }
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $rel = $tag['rel'] ?? null;
            $rels = is_array($rel) ? $rel : [$rel];
            $isQuote = false;
            foreach ($rels as $r) {
                if (!is_string($r)) {
                    continue;
                }
                if ($r === 'https://misskey-hub.net/ns#_misskey_quote'
                    || str_contains($r, 'quote')
                    || $r === 'quote'
                    || $r === 'https://w3id.org/fep/044f#quote') {
                    $isQuote = true;
                    break;
                }
            }
            if (!$isQuote && ($tag['mediaType'] ?? '') === 'application/activity+json'
                && (($tag['type'] ?? '') === 'Link')) {
                continue;
            }
            if ($isQuote) {
                $href = $tag['href'] ?? null;
                if (is_string($href) && str_starts_with($href, 'https://')) {
                    $out['url'] = $href;
                    return $out;
                }
            }
        }
    }
    return $out;
}

/** Resolve a quoted-object URL from Note/QuotePost fields (Mastodon FEP-044f, Misskey, FEP-e232). */
function ap_quote_target_url(mixed $object): ?string
{
    if (!is_array($object)) {
        return null;
    }
    $pack = ap_quote_target_pack($object);
    return $pack['url'];
}

/**
 * If object_id is a Mastodon-style …/statuses/{id}/QuotePost URL, return the parent status URL.
 */
function ap_quote_post_parent_url(?string $objectId): ?string
{
    if ($objectId === null || $objectId === '') {
        return null;
    }
    if (preg_match('#^(https://[^\\s]+/statuses/[^/]+)/QuotePost/?$#i', $objectId, $m)) {
        return $m[1];
    }
    return null;
}

/** True if a string looks like dumped ActivityPub JSON (never show that in the feed). */
function ap_text_looks_like_as2_json(string $text): bool
{
    $t = ltrim($text);
    if ($t === '' || ($t[0] !== '{' && $t[0] !== '[')) {
        return false;
    }
    return str_contains($t, '"@context"')
        || str_contains($t, '"type"') && str_contains($t, '"attributedTo"')
        || str_contains($t, 'activitystreams');
}

/**
 * Build a plain-text feed preview for a quote: commentary + quoted snippet.
 * Handles Create wrappers, Tombstones, and never embeds raw AS2 JSON.
 */
function ap_format_quote_feed_summary(?string $commentary, ?array $quotedDoc, ?string $quotedUrl = null, bool $tombstone = false): ?string
{
    $parts = [];
    $c = $commentary !== null ? trim(html_entity_decode(strip_tags(ap_fix_utf8($commentary)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    if ($c !== '' && !ap_text_looks_like_as2_json($c)) {
        $parts[] = $c;
    }

    if ($tombstone || (is_array($quotedDoc) && ap_quote_is_tombstone($quotedDoc))) {
        $parts[] = '↪ QT: (quoted post unavailable)';
        return implode("\n\n", $parts);
    }

    $qText = '';
    $attrib = '';
    if (is_array($quotedDoc)) {
        $quotedDoc = ap_unwrap_as2_object($quotedDoc);
        if (ap_quote_is_tombstone($quotedDoc)) {
            $parts[] = '↪ QT: (quoted post unavailable)';
            return implode("\n\n", $parts);
        }
        if (isset($quotedDoc['content']) && is_string($quotedDoc['content'])) {
            $qText = trim(html_entity_decode(strip_tags(ap_fix_utf8($quotedDoc['content'])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($qText !== '' && ap_text_looks_like_as2_json($qText)) {
            $qText = '';
        }
        $attributed = ap_as_id($quotedDoc['attributedTo'] ?? null);
        if ($attributed) {
            $host = parse_url($attributed, PHP_URL_HOST);
            $path = parse_url($attributed, PHP_URL_PATH) ?: '';
            $user = basename(rtrim($path, '/'));
            // GTS-style /ap/users/123… — numeric ids look awful in the feed label
            if (is_string($host) && $user !== '' && $user !== 'activity' && !preg_match('/^\d+$/', $user)) {
                $attrib = '@' . $user . '@' . $host;
            }
        }
    }
    if ($qText !== '' || $quotedUrl || $attrib !== '') {
        $line = '↪ QT';
        if ($attrib !== '') {
            $line .= ' ' . $attrib;
        }
        $line .= ':';
        if ($qText !== '') {
            if (mb_strlen($qText) > 500) {
                $qText = mb_substr($qText, 0, 497) . '…';
            }
            $line .= ' ' . $qText;
        } elseif ($quotedUrl) {
            // Prefer human path over …/activity Create URLs
            $displayUrl = preg_replace('#/activity$#', '', $quotedUrl) ?? $quotedUrl;
            $line .= ' ' . $displayUrl;
        } else {
            $line .= ' (quoted post)';
        }
        $parts[] = $line;
    }
    if (!$parts) {
        return null;
    }
    return implode("\n\n", $parts);
}

function ap_resolve_inbox_from_actor_doc(array $doc): ?string
{
    if (isset($doc['endpoints']['sharedInbox']) && is_string($doc['endpoints']['sharedInbox'])) {
        return $doc['endpoints']['sharedInbox'];
    }
    if (isset($doc['inbox']) && is_string($doc['inbox'])) {
        return $doc['inbox'];
    }
    return null;
}

/** Personal actor inbox (preferred for Follow / Accept on Pleroma/Akkoma). */
function ap_resolve_personal_inbox_from_actor_doc(array $doc): ?string
{
    if (isset($doc['inbox']) && is_string($doc['inbox']) && str_starts_with($doc['inbox'], 'https://')) {
        return $doc['inbox'];
    }
    return ap_resolve_inbox_from_actor_doc($doc);
}

/**
 * If Follow.object targets a local ap_users actor, return that user row.
 *
 * @return array<string,mixed>|null
 */
function ap_resolve_local_follow_target($object): ?array
{
    if (is_array($object) && array_is_list($object) && count($object) === 1) {
        $object = $object[0];
    }
    $id = null;
    if (is_string($object)) {
        $id = $object;
    } elseif (is_array($object)) {
        if (($object['type'] ?? '') === 'Follow') {
            return ap_resolve_local_follow_target($object['object'] ?? null);
        }
        $id = ap_as_id($object);
    }
    if (!is_string($id) || $id === '') {
        return null;
    }
    $id = rtrim($id, '/');
    if (!str_starts_with($id, 'https://mkultra.monster/users/')) {
        return null;
    }
    return ap_local_user_by_actor_id($id);
}

/**
 * From inbox request path (/users/{name}/inbox), prefer that actor for Accepts.
 */
function ap_inbox_bootstrap_request_actor(): void
{
    if (ap_request_actor_get() !== null) {
        return;
    }
    foreach (ap_request_target_path_candidates() as $path) {
        if (preg_match('#^/users/([a-z][a-z0-9_]{1,29})/inbox/?$#i', $path, $m)) {
            $user = ap_local_user_by_username($m[1]);
            if ($user !== null) {
                ap_request_actor_set((string) $user['actor_key']);
                return;
            }
        }
    }
}

/**
 * Accept Follow of the request-local actor.
 * Auto follow-back is opt-in per profile (actor_profile.auto_follow_back), default off.
 */
function ap_local_accept_and_follow_back(array $activity): bool
{
    $localId = ap_local_actor_id();
    $localKeyId = ap_local_key_id();
    $localPriv = ap_local_priv_path();
    $follower = ap_as_id($activity['actor'] ?? null);
    if ($follower === null) {
        return false;
    }
    if (ap_is_blocked_actor($follower)) {
        ap_log('follow_blocked actor=' . ap_short($follower));
        return false;
    }
    $followerDoc = ap_fetch_actor_doc($follower);
    if ($followerDoc === null) {
        ap_log('local_follow_skip cannot fetch ' . ap_short($follower));
        return false;
    }
    // Prefer canonical id from the fetched doc (Bridgy gateway actors, etc.)
    $canonical = ap_as_id($followerDoc['id'] ?? null);
    if ($canonical) {
        $follower = $canonical;
    }
    $sharedInbox = ap_resolve_inbox_from_actor_doc($followerDoc);
    $personalInbox = ap_resolve_personal_inbox_from_actor_doc($followerDoc);
    if (!$personalInbox || !str_starts_with($personalInbox, 'https://')) {
        // Fall back to shared inbox only (some Service actors)
        if (!$sharedInbox || !str_starts_with($sharedInbox, 'https://')) {
            ap_log('local_follow_skip no inbox for ' . ap_short($follower));
            return false;
        }
        $personalInbox = $sharedInbox;
    }

    $username = isset($followerDoc['preferredUsername']) && is_string($followerDoc['preferredUsername'])
        ? $followerDoc['preferredUsername'] : null;

    $localKey = (string) (ap_request_actor_get()['key'] ?? 'cmdr_nova');
    $followId = ap_as_id($activity) ?: ($follower . '#follows/' . $localKey . '/' . bin2hex(random_bytes(6)));
    $profile = function_exists('ap_profile_get') ? ap_profile_get($localKey) : [];
    if (!empty($profile['manually_approves'])) {
        $stored = function_exists('ap_follow_request_upsert')
            ? ap_follow_request_upsert($localId, $follower, $personalInbox, $sharedInbox, $username, $followId)
            : false;
        ap_log('local_follow_pending follower=' . ap_short($follower) . ' stored=' . ($stored ? '1' : '0') . ' actor=' . ap_short($localId));
        return $stored;
    }

    // Record follower immediately so fan-out works even if Accept delivery flaps
    ap_follower_upsert($follower, $personalInbox, $sharedInbox, $username, $localId);

    // Always use a plain URI string for Follow.object — never an array
    $accept = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $localId . '/accepts/' . bin2hex(random_bytes(10)),
        'type' => 'Accept',
        'actor' => $localId,
        'object' => [
            'id' => $followId,
            'type' => 'Follow',
            'actor' => $follower,
            'object' => $localId,
        ],
        'to' => [$follower],
    ];
    // Accept → personal inbox first (Pleroma/Akkoma/Bridgy); then sharedInbox.
    $okAccept = ap_deliver_signed_json($personalInbox, $accept, $localKeyId, $localPriv, 5.0);
    if (!$okAccept && $sharedInbox && $sharedInbox !== $personalInbox) {
        $okAccept = ap_deliver_signed_json($sharedInbox, $accept, $localKeyId, $localPriv, 5.0);
    }

    // Bridgy Fed gateway bot: Accept is enough; skip follow-back noise if already following
    $isBridgyGateway = (bool) preg_match('#^https://([a-z0-9-]+\.)?brid\.gy/#i', $follower)
        || str_contains(strtolower($follower), 'brid.gy/');

    // Auto follow-back: profile opt-in only (default off for every account)
    $doFollowBack = false;
    if (!$isBridgyGateway && function_exists('ap_profile_get')) {
        $prof = ap_profile_get($localKey);
        $doFollowBack = !empty($prof['auto_follow_back']);
    }

    $okBack = true;
    $backLabel = 'skipped_opt_out';
    if ($isBridgyGateway) {
        $backLabel = 'skipped_bridgy';
    } elseif ($doFollowBack) {
        $back = ap_follow_remote_actor($follower, true);
        $okBack = !empty($back['ok']) || !empty($back['already']);
        $backLabel = $okBack ? 'ok' : 'fail';
    }

    ap_log(sprintf(
        'local_follow follower=%s accept=%s followback=%s actor=%s',
        ap_short($follower),
        $okAccept ? 'ok' : 'fail',
        $backLabel,
        ap_short($localId)
    ));
    // Follower is stored either way; remotes can retry Accept if delivery flapped.
    return true;
}

/** Approve or reject a stored follow request and notify the remote actor. */
function ap_follow_request_decide(array $request, bool $approve): array
{
    $owner = rtrim((string) ($request['owner_actor_id'] ?? ''), '/');
    $follower = rtrim((string) ($request['actor_id'] ?? ''), '/');
    $inbox = (string) ($request['inbox'] ?? '');
    $sharedInbox = (string) ($request['shared_inbox'] ?? '');
    $followId = (string) ($request['follow_activity_id'] ?? '');
    if ($owner === '' || $follower === '' || $inbox === '') {
        return ['ok' => false, 'error' => 'Follow request is incomplete.'];
    }
    $ownerKey = rawurldecode(basename(parse_url($owner, PHP_URL_PATH) ?: ''));
    if ($ownerKey === '') {
        return ['ok' => false, 'error' => 'Follow request owner is invalid.'];
    }
    ap_request_actor_set($ownerKey);
    $localId = ap_local_actor_id();
    $activity = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $localId . '/' . ($approve ? 'accepts' : 'rejects') . '/' . bin2hex(random_bytes(10)),
        'type' => $approve ? 'Accept' : 'Reject',
        'actor' => $localId,
        'object' => [
            'id' => $followId !== '' ? $followId : ($follower . '#follow'),
            'type' => 'Follow',
            'actor' => $follower,
            'object' => $localId,
        ],
        'to' => [$follower],
    ];
    $ok = ap_deliver_signed_json($inbox, $activity, ap_local_key_id(), ap_local_priv_path(), 5.0);
    if (!$ok && $sharedInbox !== '' && $sharedInbox !== $inbox) {
        $ok = ap_deliver_signed_json($sharedInbox, $activity, ap_local_key_id(), ap_local_priv_path(), 5.0);
    }
    if ($approve) {
        ap_follower_upsert($follower, $inbox, $sharedInbox, (string) ($request['username'] ?? ''), $localId);
    }
    $status = $approve ? 'accepted' : 'rejected';
    $saved = function_exists('ap_follow_request_set_status')
        ? ap_follow_request_set_status((int) ($request['id'] ?? 0), $owner, $status)
        : false;
    return ['ok' => $saved, 'delivered' => $ok, 'status' => $status];
}

/**
 * Resolve @user@host, acct:user@host, or https actor URL → canonical actor id URL.
 */
function ap_resolve_actor_ref(string $input): ?string
{
    $input = trim(ap_fix_utf8($input));
    if ($input === '') {
        return null;
    }

    // Handle / acct form
    $handle = $input;
    if (str_starts_with(strtolower($handle), 'acct:')) {
        $handle = substr($handle, 5);
    }
    if (preg_match('/^@?([A-Za-z0-9_.\-]+)@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})$/', $handle, $m)) {
        $user = $m[1];
        $host = strtolower($m[2]);
        if (!ap_host_resolves_public($host)) {
            return null;
        }
        $resource = 'acct:' . $user . '@' . $host;
        $wfUrl = 'https://' . $host . '/.well-known/webfinger?resource=' . rawurlencode($resource);

        // WebFinger is a public JRD endpoint. Prefer unsigned GET first:
        // Akkoma/Pleroma often 500 on signed ActivityPub-style GETs here, and a
        // non-empty error JSON previously blocked our unsigned fallback.
        $parseWf = static function (?string $body): ?string {
            if (!is_string($body) || $body === '') {
                return null;
            }
            $jrd = json_decode($body, true);
            if (!is_array($jrd) || empty($jrd['links']) || !is_array($jrd['links'])) {
                return null;
            }
            foreach ($jrd['links'] as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $rel = (string) ($link['rel'] ?? '');
                $type = (string) ($link['type'] ?? '');
                $href = (string) ($link['href'] ?? '');
                if ($rel === 'self' && str_starts_with($href, 'https://') && (
                    str_contains($type, 'activity') || str_contains($type, 'ld+json') || $type === ''
                )) {
                    return $href;
                }
            }
            return null;
        };

        // Prefer curl/IPv4 — file_get_contents often hangs on broken AAAA (5s empty body).
        $wfHeaders = [
            'Accept: application/jrd+json, application/json',
            'User-Agent: ' . ap_http_user_agent(),
        ];
        $href = null;
        if (function_exists('ap_http_curl_get_ex')) {
            $curlRes = ap_http_curl_get_ex($wfUrl, $wfHeaders, 8);
            $href = $parseWf(is_string($curlRes['body'] ?? null) ? $curlRes['body'] : null);
        }
        if ($href === null) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5,
                    'header' => "Accept: application/jrd+json, application/json\r\nUser-Agent: " . ap_http_user_agent() . "\r\n",
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($wfUrl, false, $ctx);
            $href = $parseWf(is_string($body) ? $body : null);
        }
        if ($href !== null) {
            return $href;
        }
        // Last resort: signed GET (some locked-down hosts)
        $href = $parseWf(ap_signed_get($wfUrl));
        return $href;
    }

    if (!str_starts_with($input, 'https://')) {
        return null;
    }

    // Common Wafrn mistake: app.wafrn.net blog URLs 404 for single-user hosts
    if (preg_match('#^https://app\.wafrn\.net/fediverse/blog/([A-Za-z0-9_.\-]+)/?$#', $input, $wm)) {
        // Can't guess the custom domain — caller should use @user@host
        return $input;
    }

    return $input;
}

/**
 * Outbound signing identity for the current request/session actor.
 * @return array{id:string,key_id:string,priv:string}
 */
function ap_outbound_identity(): array
{
    $id = function_exists('ap_local_actor_id') ? rtrim(ap_local_actor_id(), '/') : '';
    if ($id === '' || !str_starts_with($id, 'https://')) {
        $id = rtrim(LOCAL_ACTOR, '/');
    }
    return [
        'id' => $id,
        'key_id' => function_exists('ap_local_key_id') ? ap_local_key_id() : LOCAL_KEY_ID,
        'priv' => function_exists('ap_local_priv_path') ? ap_local_priv_path() : LOCAL_PRIV,
    ];
}

/**
 * Send a Follow from the session actor to a remote (or same-instance) actor.
 *
 * @return array{ok:bool,error?:string,already?:bool,follow_id?:string}
 */
function ap_follow_remote_actor(string $actorId, bool $respectRateLimit = true): array
{
    $rawInput = trim($actorId);
    $resolved = ap_resolve_actor_ref($rawInput);
    if ($resolved === null) {
        if (preg_match('/@.+@/', $rawInput) || str_contains(strtolower($rawInput), 'acct:')) {
            return ['ok' => false, 'error' => 'Could not resolve handle via WebFinger'];
        }
        return ['ok' => false, 'error' => 'Use https:// actor URL or @user@instance'];
    }
    $actorId = $resolved;

    // Hint for the common Wafrn central-app URL
    if (str_starts_with($actorId, 'https://app.wafrn.net/')) {
        $docTry = ap_fetch_actor_doc($actorId);
        if ($docTry === null) {
            return ['ok' => false, 'error' => 'app.wafrn.net URL not found — use @valerie@waffles.baeddel.social (or your Wafrn host) instead'];
        }
    }

    $localId = rtrim(ap_local_actor_id(), '/');
    if ($localId === '' || !str_starts_with($localId, 'https://')) {
        return ['ok' => false, 'error' => 'Not signed in as a local account (refusing cmdr_nova fallback)'];
    }
    if (rtrim($actorId, '/') === $localId) {
        return ['ok' => false, 'error' => 'Cannot follow yourself'];
    }

    if (ap_is_blocked_actor($actorId)) {
        return ['ok' => false, 'error' => 'That actor or instance is blocked'];
    }

    // Already following? (exact IRI or dual Mastodon /users vs /ap/users alias)
    $want = rtrim($actorId, '/');
    $wantAliases = [$want => true];
    if (function_exists('ap_masto_actor_id_aliases')) {
        foreach (ap_masto_actor_id_aliases($actorId) as $al) {
            $al = rtrim((string) $al, '/');
            if ($al !== '') {
                $wantAliases[$al] = true;
            }
        }
    }
    foreach (ap_following_list($localId) as $row) {
        $have = rtrim((string) ($row['actor_id'] ?? ''), '/');
        if ($have !== '' && isset($wantAliases[$have])) {
            return ['ok' => true, 'already' => true, 'follow_id' => null];
        }
    }

    if ($respectRateLimit && ap_outbound_follow_count_recent(3600) >= LOCAL_FOLLOW_BACK_MAX_PER_HOUR) {
        ap_log('follow_remote_rate_limited target=' . ap_short($actorId));
        return ['ok' => false, 'error' => 'Follow rate limit reached (try again later)'];
    }

    $localKeyId = ap_local_key_id();
    $localPriv = ap_local_priv_path();
    $targetId = $want;
    $backId = $localId . '/follows/' . bin2hex(random_bytes(10));

    // Same-instance local actor: update graphs only (no HTTP to ourselves).
    if (preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $targetId, $lm)) {
        $targetKey = (string) $lm[1];
        $sessionKey = '';
        if (preg_match('#/users/([A-Za-z0-9_]+)$#', rtrim($localId, '/'), $sm)) {
            $sessionKey = (string) $sm[1];
        }
        if ($targetKey !== '' && $targetKey !== $sessionKey) {
            ap_following_upsert($targetId, $localId);
            ap_follower_upsert(
                $localId,
                $localId . '/inbox',
                null,
                $sessionKey !== '' ? $sessionKey : basename(parse_url($localId, PHP_URL_PATH) ?: ''),
                $targetId
            );
            ap_metrics_record('Follow', $localId, $backId, $targetId, 0, 'manual_follow_local', $targetId);
            ap_log('follow_local_ok target=' . ap_short($targetId) . ' from=' . ap_short($localId));
            return ['ok' => true, 'follow_id' => $backId, 'already' => false];
        }
    }

    $doc = ap_fetch_actor_doc($actorId);
    if ($doc === null) {
        return ['ok' => false, 'error' => 'Could not fetch remote actor (WebFinger ok but actor doc failed — try the https://…/users/… URL)'];
    }
    $personalInbox = ap_resolve_personal_inbox_from_actor_doc($doc);
    $sharedInbox = ap_resolve_inbox_from_actor_doc($doc);
    if (!$personalInbox || !str_starts_with($personalInbox, 'https://')) {
        return ['ok' => false, 'error' => 'Remote actor has no usable inbox'];
    }

    // Canonical id from the document when present
    $targetId = ap_as_id($doc['id'] ?? null) ?: $actorId;
    $follow = [
        '@context' => [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1',
        ],
        'id' => $backId,
        'type' => 'Follow',
        'actor' => $localId,
        'object' => $targetId,
        'to' => [$targetId],
    ];
    // Follow → personal inbox first (Akkoma/Pleroma); fall back to sharedInbox.
    $ok = ap_deliver_signed_json($personalInbox, $follow, $localKeyId, $localPriv, 5.0);
    $usedInbox = $personalInbox;
    if (!$ok && $sharedInbox && $sharedInbox !== $personalInbox) {
        $ok = ap_deliver_signed_json($sharedInbox, $follow, $localKeyId, $localPriv, 5.0);
        $usedInbox = $sharedInbox;
    }
    if (!$ok) {
        ap_log('follow_remote_fail target=' . ap_short($targetId) . ' inbox=' . ap_short($usedInbox));
        $host = strtolower((string) (parse_url($targetId, PHP_URL_HOST) ?: ''));
        $hint = 'Delivery to remote inbox failed (tried personal'
            . ($sharedInbox && $sharedInbox !== $personalInbox ? ' + shared' : '') . ')';
        if ($host === 'threads.net' || $host === 'www.threads.net' || str_ends_with($host, '.threads.net')) {
            $hint .= ' — Threads returned an error after fetching our actor. Check /var/log/mkultra/ap-inbox.log (deliver_fail).';
        }
        return ['ok' => false, 'error' => $hint];
    }
    ap_following_upsert($targetId, $localId);
    ap_metrics_record('Follow', $localId, $backId, $targetId, strlen(json_encode($follow) ?: ''), 'manual_follow', $targetId);
    ap_log('follow_remote_ok target=' . ap_short($targetId) . ' inbox=' . ap_short($usedInbox));
    if (function_exists('ap_remote_media_warm_async')) {
        ap_remote_media_warm_async($targetId);
    }
    return ['ok' => true, 'follow_id' => $backId, 'already' => false];
}

/**
 * Unfollow a remote actor: send Undo(Follow) and drop local following row.
 *
 * @return array{ok:bool,error?:string,already?:bool}
 */
function ap_unfollow_remote_actor(string $actorId): array
{
    $rawInput = trim($actorId);
    $resolved = ap_resolve_actor_ref($rawInput);
    if ($resolved === null && str_starts_with($rawInput, 'https://')) {
        $resolved = rtrim($rawInput, '/');
    }
    if ($resolved === null) {
        return ['ok' => false, 'error' => 'Could not resolve actor to unfollow'];
    }
    $targetId = rtrim($resolved, '/');
    $ident = ap_outbound_identity();
    $localId = $ident['id'];

    $wantAliases = [$targetId => true];
    if (function_exists('ap_masto_actor_id_aliases')) {
        foreach (ap_masto_actor_id_aliases($targetId) as $al) {
            $al = rtrim((string) $al, '/');
            if ($al !== '') {
                $wantAliases[$al] = true;
            }
        }
    }
    $isFollowing = false;
    foreach (ap_following_list($localId) as $row) {
        $have = rtrim((string) ($row['actor_id'] ?? ''), '/');
        if ($have !== '' && isset($wantAliases[$have])) {
            $isFollowing = true;
            $targetId = $have;
            break;
        }
    }
    if (!$isFollowing) {
        foreach (array_keys($wantAliases) as $a) {
            ap_following_remove($a, $localId);
        }
        return ['ok' => true, 'already' => true];
    }

    // Same-instance: drop both following + follower rows; skip remote Undo.
    if (preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $targetId)) {
        ap_following_remove($targetId, $localId);
        ap_follower_remove($localId, $targetId);
        ap_metrics_record('Undo', $localId, $targetId, $targetId, 0, 'manual_unfollow_local', null);
        ap_log('unfollow_local_ok target=' . ap_short($targetId) . ' from=' . ap_short($localId));
        return ['ok' => true, 'already' => false];
    }

    $doc = ap_fetch_actor_doc($targetId);
    $personalInbox = is_array($doc) ? ap_resolve_personal_inbox_from_actor_doc($doc) : null;
    $sharedInbox = is_array($doc) ? ap_resolve_inbox_from_actor_doc($doc) : null;
    $inbox = $personalInbox ?: $sharedInbox;

    // Best-effort Undo; always remove locally so admin UI stays truthful
    if ($inbox && str_starts_with($inbox, 'https://') && !ap_is_blocked_inbox($inbox)) {
        $undoId = $localId . '/undos/' . bin2hex(random_bytes(10));
        $followId = $localId . '/follows/undo-' . bin2hex(random_bytes(6));
        $undo = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $undoId,
            'type' => 'Undo',
            'actor' => $localId,
            'to' => [$targetId],
            'object' => [
                'id' => $followId,
                'type' => 'Follow',
                'actor' => $localId,
                'object' => $targetId,
            ],
        ];
        ap_deliver_signed_json($inbox, $undo, $ident['key_id'], $ident['priv'], 5.0);
    }

    ap_following_remove($targetId, $localId);
    ap_metrics_record('Undo', $localId, $targetId, $targetId, 0, 'manual_unfollow', null);
    ap_log('unfollow_remote_ok target=' . ap_short($targetId));
    return ['ok' => true, 'already' => false];
}

/**
 * Local recipient for inbound mention/DM ownership.
 * Prefer personal-inbox actor, else first local /users/{key} touched by the activity.
 *
 * @return array{owner_user_id:int,owner_actor_id:string}
 */
function ap_inbox_recipient_owner(array $activity = []): array
{
    // Prefer the inbox-bound request actor (personal /users/{key}/inbox). Do NOT
    // scan the whole activity for random local URIs — that mis-attributed cmdr
    // boosts/updates as notifications for other local accounts.
    $candidates = [];
    $req = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
    if (is_array($req) && !empty($req['id'])) {
        $candidates[] = (string) $req['id'];
    }
    if (function_exists('ap_local_actor_id')) {
        $lid = ap_local_actor_id();
        if ($lid !== '' && !in_array($lid, $candidates, true)) {
            $candidates[] = $lid;
        }
    }
    // Only consider other local actors when explicitly addressed (to/cc/bto/bcc/audience).
    foreach (['to', 'cc', 'bto', 'bcc', 'audience'] as $field) {
        $vals = $activity[$field] ?? null;
        if ($vals === null) {
            continue;
        }
        if (!is_array($vals)) {
            $vals = [$vals];
        }
        foreach ($vals as $v) {
            $id = is_string($v) ? $v : (is_array($v) ? (string) ($v['id'] ?? '') : '');
            if ($id !== '' && preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', rtrim($id, '/'))) {
                $candidates[] = rtrim($id, '/');
            }
        }
    }
    $seen = [];
    foreach ($candidates as $cand) {
        $cand = rtrim((string) $cand, '/');
        if ($cand === '' || isset($seen[$cand])) {
            continue;
        }
        $seen[$cand] = true;
        $user = function_exists('ap_local_user_by_actor_id') ? ap_local_user_by_actor_id($cand) : null;
        if (is_array($user) && (int) ($user['id'] ?? 0) > 0) {
            return [
                'owner_user_id' => (int) $user['id'],
                'owner_actor_id' => rtrim((string) ($user['actor_id'] ?? $cand), '/'),
            ];
        }
    }
    $fallbackId = function_exists('ap_db_cmdr_nova_user_id') ? ap_db_cmdr_nova_user_id() : 1;
    return [
        'owner_user_id' => $fallbackId,
        'owner_actor_id' => 'https://mkultra.monster/users/cmdr_nova',
    ];
}

function ap_local_observe(array $activity): void
{
    $type = $activity['type'] ?? 'unknown';
    $actorId = ap_as_id($activity['actor'] ?? null);
    $object = $activity['object'] ?? null;
    $objectId = ap_as_id($object);
    $recipient = ap_inbox_recipient_owner(is_array($activity) ? $activity : []);
    $localActor = rtrim((string) ($recipient['owner_actor_id'] ?? LOCAL_ACTOR), '/');
    // Never notify ourselves about our own activities (self-reply loopback, Bridgy echo, etc.).
    if (is_string($actorId) && rtrim($actorId, '/') === $localActor) {
        return;
    }
    // Our own notes arriving as Create/Update are already in outbox — not inbound mentions.
    if (
        is_string($objectId)
        && str_starts_with(rtrim($objectId, '/'), $localActor . '/notes/')
        && in_array((string) $type, ['Create', 'Update'], true)
    ) {
        return;
    }
    $content = null;
    $inReplyTo = null;
    $objType = $type;
    $mediaUrls = [];
    $remoteDoc = null;
    $cw = ap_note_cw_from_doc(is_array($object) ? $object : null);

    if (is_array($object)) {
        $objType = is_string($object['type'] ?? null) ? $object['type'] : $type;
        if (isset($object['content']) && is_string($object['content'])) {
            $content = $object['content'];
        }
        $inReplyTo = ap_as_id($object['inReplyTo'] ?? null);
        // Collect https image URLs for admin preview only — never download/store bytes.
        $mediaUrls = ap_extract_media_urls($object);
        // Announce may wrap a nested Note/object
        if ($type === 'Announce' && isset($object['object']) && is_array($object['object'])) {
            $mediaUrls = array_values(array_unique(array_merge($mediaUrls, ap_extract_media_urls($object['object']))));
            if ($content === null && isset($object['object']['content']) && is_string($object['object']['content'])) {
                $content = $object['object']['content'];
            }
            if ($cw['spoiler_text'] === '') {
                $cw = ap_note_cw_from_doc($object['object']);
            }
        }
    } elseif (is_string($object) && str_starts_with($object, 'https://') && !str_starts_with($object, 'https://mkultra.monster/')) {
        $remoteDoc = ap_fetch_as2_object($object);
        if (is_array($remoteDoc)) {
            $objType = is_string($remoteDoc['type'] ?? null) ? $remoteDoc['type'] : $type;
            if (isset($remoteDoc['content']) && is_string($remoteDoc['content'])) {
                $content = $remoteDoc['content'];
            }
            $inReplyTo = ap_as_id($remoteDoc['inReplyTo'] ?? null);
            $mediaUrls = ap_extract_media_urls($remoteDoc);
            if ($cw['spoiler_text'] === '') {
                $cw = ap_note_cw_from_doc($remoteDoc);
            }
        }
    }

    $quoteTypes = ['Quote', 'QuotePost'];
    $storeTypes = ['Create', 'Announce', 'Like', 'Update', 'EmojiReact', 'Quote', 'QuotePost'];
    // PeerTube Video Updates are metadata noise — never store as notification mentions
    if ($type === 'Update') {
        $ot = strtolower(is_string($objType) ? $objType : '');
        $oid = is_string($objectId) ? $objectId : '';
        if ($ot === 'video' || preg_match('#/(videos|w)/#i', $oid)) {
            return;
        }
    }
    if (in_array($type, $storeTypes, true) || $content !== null || $mediaUrls) {
        // Detect quote-boost of *our* notes (FEP-044f / Misskey quote fields).
        $storeActivityType = $type;
        $quoteSrc = is_array($object) ? $object : null;
        if ($quoteSrc === null && is_array($remoteDoc)) {
            $quoteSrc = $remoteDoc;
        }
        // Create wraps a Note — quote fields live on the Note
        if ($type === 'Create' && is_array($object)) {
            $quoteSrc = $object;
        }
        $localNotesPrefixForQuote = rtrim($localActor, '/') . '/notes/';
        if (is_array($quoteSrc) && function_exists('ap_quote_target_pack')) {
            $qPack = ap_quote_target_pack($quoteSrc);
            $qt = $qPack['url'] ?? null;
            if (is_string($qt) && str_starts_with(rtrim($qt, '/'), $localNotesPrefixForQuote)) {
                $storeActivityType = 'Quote';
            }
        } elseif (is_array($quoteSrc) && function_exists('ap_quote_target_url')) {
            $qt = ap_quote_target_url($quoteSrc);
            if (is_string($qt) && str_starts_with(rtrim($qt, '/'), $localNotesPrefixForQuote)) {
                $storeActivityType = 'Quote';
            }
        }
        if ($storeActivityType !== 'Quote' && in_array($type, $quoteTypes, true) && is_string($objectId)
            && str_starts_with(rtrim($objectId, '/'), $localNotesPrefixForQuote)) {
            $storeActivityType = 'Quote';
        }
        // Content fallback: enrichment sometimes prefixes "RE: <our note url>"
        if ($storeActivityType !== 'Quote' && is_string($content) && $content !== '') {
            $plainC = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $quotedUs = (bool) preg_match(
                '#^RE:\s*' . preg_quote(rtrim($localActor, '/'), '#') . '/notes/[a-f0-9]+#i',
                $plainC
            );
            if ($quotedUs || str_contains($plainC, '↪ QT')) {
                $storeActivityType = 'Quote';
            }
        }

        // Likes/reblogs/updates share target URLs — use stable actor+target keys so
        // undo+redo collapses to one notification (ON CONFLICT clears deleted_at).
        $storeObjectId = $objectId ?: ((ap_as_id($activity) ?: 'unknown') . '#obj');
        if (in_array($type, ['Like', 'EmojiReact'], true) && is_string($objectId) && $objectId !== '' && is_string($actorId) && $actorId !== '') {
            $storeObjectId = function_exists('ap_mention_interaction_object_id')
                ? ap_mention_interaction_object_id($objectId, $actorId, 'like')
                : (rtrim($objectId, '/') . '#like-' . substr(hash('sha256', $actorId . '|' . rtrim($objectId, '/') . '|like'), 0, 12));
        } elseif ($type === 'Announce' && is_string($objectId) && str_starts_with(rtrim($objectId, '/'), rtrim($localActor, '/') . '/notes/') && is_string($actorId) && $actorId !== '') {
            $storeObjectId = function_exists('ap_mention_interaction_object_id')
                ? ap_mention_interaction_object_id($objectId, $actorId, 'reblog')
                : (rtrim($objectId, '/') . '#reblog-' . substr(hash('sha256', $actorId . '|' . rtrim($objectId, '/') . '|reblog'), 0, 12));
        } elseif ($type === 'Update' && is_string($objectId) && $objectId !== '' && is_string($actorId) && $actorId !== '') {
            // Updates: keep per-edit uniqueness via activity id when present, else stable
            $actId = ap_as_id($activity);
            if ($actId) {
                $storeObjectId = rtrim($objectId, '/') . '#update-' . substr(hash('sha256', $actId), 0, 12);
            } else {
                $storeObjectId = function_exists('ap_mention_interaction_object_id')
                    ? ap_mention_interaction_object_id($objectId, $actorId, 'update')
                    : (rtrim($objectId, '/') . '#update-' . substr(hash('sha256', $actorId . '|' . rtrim($objectId, '/') . '|update'), 0, 12));
            }
        } elseif ($storeActivityType === 'Quote' && is_string($objectId) && $objectId !== '') {
            // Quote keyed by quoting object (+ actor) so Delete(quote note) can soft-delete via prefix
            $qActor = is_string($actorId) && $actorId !== '' ? $actorId : 'unknown';
            $storeObjectId = function_exists('ap_mention_interaction_object_id')
                ? ap_mention_interaction_object_id($objectId, $qActor, 'quote')
                : (rtrim($objectId, '/') . '#quote-' . substr(hash('sha256', $qActor . '|' . rtrim($objectId, '/') . '|quote'), 0, 12));
        }

        // Notifications must be recipient-specific: only store when this local actor
        // is actually interacted with (reply/mention/boost/like of *their* notes, etc.).
        // Home/Federated firehose is separate (ap_metrics_record) and must not leak here.
        $skipMentionStore = false;
        $actorNorm = is_string($actorId) ? rtrim($actorId, '/') : '';
        $localNotesPrefix = rtrim($localActor, '/') . '/notes/';
        $targetsOurNote = static function (?string $oid) use ($localNotesPrefix): bool {
            return is_string($oid) && $oid !== '' && str_starts_with(rtrim($oid, '/'), $localNotesPrefix);
        };
        $announceTargetId = is_string($objectId) ? $objectId : null;
        if ($type === 'Announce' && is_array($object)) {
            $innerOid = ap_as_id($object['object'] ?? null);
            if (is_string($innerOid) && $innerOid !== '') {
                $announceTargetId = $innerOid;
            } elseif (is_string($objectId) && !str_contains($objectId, '/notes/') && isset($object['id'])) {
                // object may itself be the Note
                $maybe = ap_as_id($object);
                if (is_string($maybe)) {
                    $announceTargetId = $maybe;
                }
            }
        }

        if (
            $type === 'Create'
            && $storeActivityType === 'Create'
            && $actorNorm !== ''
            && $actorNorm !== rtrim($localActor, '/')
            && in_array(strtolower((string) $objType), ['note', 'article', 'page', 'question', ''], true)
        ) {
            $replyToUs = is_string($inReplyTo)
                && $inReplyTo !== ''
                && str_starts_with(rtrim($inReplyTo, '/'), $localNotesPrefix);
            $addressesUs = ap_activity_addresses_local_actor($activity, $localActor);
            $ownerUid = (int) ($recipient['owner_user_id'] ?? 0);
            $subscribed = function_exists('ap_post_subscription_is')
                && ap_post_subscription_is($actorId, $ownerUid > 0 ? $ownerUid : null);
            if (!$replyToUs && !$addressesUs && !$subscribed) {
                $skipMentionStore = true;
            }
        } elseif ($type === 'Announce') {
            // Boost of someone else's post (or another local user's post) is NOT a notification
            // for this inbox — only boosts of *our* notes notify us.
            if (!$targetsOurNote($announceTargetId) && !$targetsOurNote(is_string($objectId) ? $objectId : null)) {
                $skipMentionStore = true;
            }
        } elseif (in_array($type, ['Like', 'EmojiReact'], true)) {
            if (!$targetsOurNote(is_string($objectId) ? $objectId : null)) {
                $skipMentionStore = true;
            }
        } elseif ($type === 'Update') {
            // Profile / actor document updates are never notifications for other accounts
            $ot = strtolower((string) $objType);
            if (in_array($ot, ['person', 'service', 'application', 'group', 'organization'], true)) {
                $skipMentionStore = true;
            } elseif (!$targetsOurNote(is_string($objectId) ? $objectId : null)
                && !ap_activity_addresses_local_actor($activity, $localActor)) {
                $skipMentionStore = true;
            }
        } elseif (in_array($storeActivityType, ['Quote', 'QuotePost'], true) || in_array($type, ['Quote', 'QuotePost'], true)) {
            // Only notify when the quote target is one of *our* notes
            $quoteOfUs = false;
            if (is_array($quoteSrc ?? null) && function_exists('ap_quote_target_pack')) {
                $qp = ap_quote_target_pack($quoteSrc);
                $quoteOfUs = $targetsOurNote(isset($qp['url']) && is_string($qp['url']) ? $qp['url'] : null);
            } elseif (is_array($quoteSrc ?? null) && function_exists('ap_quote_target_url')) {
                $quoteOfUs = $targetsOurNote(ap_quote_target_url($quoteSrc));
            }
            if (!$quoteOfUs && !$targetsOurNote(is_string($objectId) ? $objectId : null)) {
                $skipMentionStore = true;
            }
        }

        // Another local account's ordinary actions must never notify us unless they
        // targeted us (already covered above). Belt-and-suspenders for same-host actors.
        if (
            !$skipMentionStore
            && $actorNorm !== ''
            && $actorNorm !== rtrim($localActor, '/')
            && str_starts_with($actorNorm, 'https://mkultra.monster/users/')
            && in_array($type, ['Create', 'Announce', 'Update', 'Like', 'EmojiReact', 'Quote', 'QuotePost'], true)
        ) {
            $addressesUs = ap_activity_addresses_local_actor($activity, $localActor);
            $touchesUs = $targetsOurNote(is_string($objectId) ? $objectId : null)
                || $targetsOurNote($announceTargetId)
                || (is_string($inReplyTo) && str_starts_with(rtrim($inReplyTo, '/'), $localNotesPrefix));
            if (!$addressesUs && !$touchesUs && $storeActivityType !== 'Quote') {
                $skipMentionStore = true;
            }
        }

        if (!$skipMentionStore) {
            ap_mention_store([
                'activity_id' => ap_as_id($activity),
                'object_id' => $storeObjectId,
                'actor_id' => $actorId,
                'type' => $objType,
                'activity_type' => $storeActivityType,
                'content' => $content,
                'in_reply_to' => $inReplyTo,
                'media_urls' => $mediaUrls,
                'spoiler_text' => (string) ($cw['spoiler_text'] ?? ''),
                'sensitive' => !empty($cw['sensitive']),
                'owner_user_id' => (int) ($recipient['owner_user_id'] ?? 0),
                'owner_actor_id' => (string) ($recipient['owner_actor_id'] ?? ''),
            ]);
            // Ice Cubes Web Push (best-effort; never block inbox)
            if (is_string($actorId) && $actorId !== '') {
                try {
                    require_once __DIR__ . '/ap-webpush.php';
                    $pushType = null;
                    $sat = strtolower((string) $storeActivityType);
                    $tLow = strtolower((string) $type);
                    if (in_array($sat, ['like', 'emojireact'], true) || in_array($tLow, ['like', 'emojireact'], true)) {
                        $pushType = 'favourite';
                    } elseif (($sat === 'announce' || $tLow === 'announce')
                        && is_string($objectId) && str_starts_with(rtrim($objectId, '/'), rtrim($localActor, '/') . '/notes/')) {
                        $pushType = 'reblog';
                    } elseif ($sat === 'quote' || $sat === 'quotepost') {
                        $pushType = 'quote';
                    } elseif (in_array($tLow, ['create', 'update'], true)) {
                        if (!function_exists('ap_masto_mention_notif_type')) {
                            require_once __DIR__ . '/ap-r2.php';
                            require_once __DIR__ . '/ap-masto-entities.php';
                        }
                        if (function_exists('ap_masto_mention_notif_type')) {
                            $pushType = ap_masto_mention_notif_type([
                                'activity_type' => $storeActivityType,
                                'type' => $objType,
                                'object_id' => $storeObjectId,
                                'actor_id' => $actorId,
                                'content' => $content,
                                'in_reply_to' => $inReplyTo,
                                'owner_user_id' => (int) ($recipient['owner_user_id'] ?? 0),
                                'owner_actor_id' => (string) ($recipient['owner_actor_id'] ?? $localActor),
                            ]);
                        }
                    }
                    if (is_string($pushType) && $pushType !== '') {
                        $nid = null;
                        if (!function_exists('ap_masto_notification_id_for_mention')) {
                            require_once __DIR__ . '/ap-r2.php';
                            require_once __DIR__ . '/ap-masto-entities.php';
                        }
                        if (function_exists('ap_masto_notification_id_for_mention')) {
                            $mst = ap_db()->prepare('SELECT id, created_at FROM mentions WHERE object_id = ? LIMIT 1');
                            $mst->execute([$storeObjectId]);
                            $mrow = $mst->fetch();
                            if (is_array($mrow)) {
                                $nid = ap_masto_notification_id_for_mention(
                                    (int) $mrow['id'],
                                    isset($mrow['created_at']) ? (string) $mrow['created_at'] : null
                                );
                            }
                        }
                        $pushOwnerId = (int) ($recipient['owner_user_id'] ?? 0);
                        ap_webpush_notify_event(
                            $pushType,
                            $actorId,
                            $nid,
                            is_string($content) ? $content : null,
                            $pushOwnerId > 0 ? $pushOwnerId : null
                        );
                    }
                } catch (Throwable $e) {
                    error_log('[ap-webpush] inbox hook: ' . $e->getMessage());
                }
            }
        }
    }
    // Mastodon-style poll votes: Create Note inReplyTo our Question with option name
    if (
        $type === 'Create'
        && $actorId
        && is_string($inReplyTo)
        && str_starts_with($inReplyTo, LOCAL_ACTOR . '/notes/')
        && is_array($object)
    ) {
        $optName = isset($object['name']) && is_string($object['name']) ? trim($object['name']) : '';
        if ($optName !== '') {
            try {
                $pst = ap_db()->prepare('SELECT local_id FROM masto_polls WHERE note_id = ? OR note_id = ?');
                $pst->execute([rtrim($inReplyTo, '/'), rtrim($inReplyTo, '/') . '/']);
                $prow = $pst->fetch();
                if (is_array($prow)) {
                    ap_masto_poll_vote((int) $prow['local_id'], $actorId, [$optName]);
                }
            } catch (Throwable $e) {
                // ignore vote apply failures
            }
        }
    }
    if ($type === 'Delete' && $objectId) {
        ap_mention_soft_delete($objectId);
    }
}

/** Unsigned HTTPS GET for public AS2/JRD docs (Bridgy Fed often 400s signed GETs). */
function ap_unsigned_get(string $url, int $timeoutSec = 8): ?string
{
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
        return null;
    }
    $host = (string) ($parts['host'] ?? '');
    if ($host === '' || !ap_host_resolves_public($host)) {
        return null;
    }
    $timeoutSec = max(1, min(20, $timeoutSec));
    $headers = [
        'Accept: application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
        'User-Agent: ' . ap_http_user_agent(),
    ];
    $res = ap_http_curl_get_ex($url, $headers, $timeoutSec);
    $body = $res['body'] ?? null;
    return is_string($body) && $body !== '' ? $body : null;
}

/**
 * After HTTP signature verifies, require keyId host to match activity actor host
 * (and prefer matching the fetched key's owner). Blocks DM/Follow impersonation
 * by any valid signer claiming an arbitrary actor.
 */
function ap_inbox_actor_matches_key(string $keyId, ?string $actorId): bool
{
    if ($actorId === null || $actorId === '') {
        return false;
    }
    $keyHost = parse_url($keyId, PHP_URL_HOST);
    $actorHost = parse_url($actorId, PHP_URL_HOST);
    if (!is_string($keyHost) || !is_string($actorHost) || $keyHost === '' || $actorHost === '') {
        return false;
    }
    if (strtolower($keyHost) !== strtolower($actorHost)) {
        return false;
    }
    return true;
}

/**
 * True when the HTTP Signature keyId belongs to an actor we follow.
 * Used to accept Mastodon-style inbox forwarding (followee re-signs others' Creates).
 */
function ap_inbox_signer_is_followed(string $keyId): bool
{
    $signer = preg_replace('/#.*$/', '', $keyId) ?? $keyId;
    $signer = rtrim(trim($signer), '/');
    if ($signer === '' || !str_starts_with($signer, 'https://')) {
        return false;
    }
    static $set = null;
    static $setAt = 0;
    if (!is_array($set) || (time() - $setAt) >= 60) {
        $set = [];
        try {
            foreach (ap_following_list() as $f) {
                $a = rtrim((string) ($f['actor_id'] ?? ''), '/');
                if ($a !== '') {
                    $set[$a] = true;
                }
            }
        } catch (Throwable $e) {
            $set = [];
        }
        $setAt = time();
    }
    return isset($set[$signer]);
}

function ap_fetch_actor_doc(string $actorId): ?array
{
    foreach (ap_key_fetch_candidates($actorId) as $url) {
        // Authorized fetch first (signed as cmdr_nova); Bridgy handled inside ap_fetch_remote_as2
        $data = ap_fetch_remote_as2($url);
        if ($data === null) {
            continue;
        }
        // Actor docs need a type and/or inbox
        if (($data['type'] ?? '') === '' && !isset($data['inbox'])) {
            continue;
        }
        return $data;
    }
    return null;
}

function ap_deliver_signed_json(string $inboxUrl, array $activity, string $keyId, string $privPath, float $timeoutSec = 10.0): bool
{
    $parts = parse_url($inboxUrl);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
        return false;
    }
    $host = (string) ($parts['host'] ?? '');
    if ($host === '' || !ap_host_resolves_public($host)) {
        return false;
    }
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }
    if (!empty($parts['query'])) {
        $path .= '?' . $parts['query'];
    }

    $body = json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        return false;
    }

    $privPem = @file_get_contents($privPath);
    if (!is_string($privPem) || $privPem === '') {
        return false;
    }
    $priv = openssl_pkey_get_private($privPem);
    if ($priv === false) {
        return false;
    }

    $date = gmdate('D, d M Y H:i:s') . ' GMT';
    $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    $contentType = 'application/activity+json';
    $contentLength = (string) strlen($body);
    // Mastodon draft-cavage POST signing: (request-target) host date digest.
    // Do NOT sign content-length/content-type — Threads returned 500s with our
    // prior content-length signature set, and Akkoma is happier without CT.
    $signing = "(request-target): post {$path}\nhost: {$host}\ndate: {$date}\ndigest: {$digest}";
    $sigRaw = '';
    if (!openssl_sign($signing, $sigRaw, $priv, OPENSSL_ALGO_SHA256)) {
        return false;
    }
    $sigHeader = sprintf(
        'keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="%s"',
        $keyId,
        base64_encode($sigRaw)
    );

    $headers = [
        'Host: ' . $host,
        'Date: ' . $date,
        'Digest: ' . $digest,
        'Content-Type: ' . $contentType,
        'Content-Length: ' . $contentLength,
        'Accept: application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
        'User-Agent: ' . ap_http_user_agent(),
        'Signature: ' . $sigHeader,
    ];

    $timeoutSec = max(1.0, min(30.0, $timeoutSec));
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => $timeoutSec,
            'follow_location' => 0,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $resp = @file_get_contents($inboxUrl, false, $ctx);
    $statusLine = $http_response_header[0] ?? '';
    $ok = is_string($statusLine) && preg_match('/\s(2\d\d)\s/', $statusLine);
    if (!$ok) {
        ap_log('deliver_fail inbox=' . ap_short($inboxUrl, 80) . ' status=' . ap_short($statusLine, 80)
            . ' body=' . ap_short(is_string($resp) ? $resp : '', 120));
    } elseif (is_string($resp) && $resp !== '' && $resp !== 'ok' && !str_starts_with(ltrim($resp), '{')) {
        // Akkoma often returns literal "ok"; anything else is worth logging at debug level
        ap_log('deliver_ok inbox=' . ap_short($inboxUrl, 80) . ' status=' . ap_short($statusLine, 40)
            . ' body=' . ap_short($resp, 80));
    }
    return (bool) $ok;
}

/**
 * CLI PHP binary for background workers. Never return php-fpm — FPM cannot
 * run worker scripts, which left media/poll deliver jobs stuck in /tmp forever.
 */
function ap_php_cli_binary(): string
{
    foreach (['/usr/bin/php8.3', '/usr/bin/php', '/usr/local/bin/php'] as $cand) {
        if (is_executable($cand)) {
            return $cand;
        }
    }
    if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== ''
        && !str_contains(PHP_BINARY, 'php-fpm') && is_executable(PHP_BINARY)) {
        return PHP_BINARY;
    }
    return 'php';
}

/**
 * Queue remaining inbox deliveries in a background PHP worker so admin compose
 * can return to "Your posts" immediately after priority (followers) fan-out.
 *
 * Uses nohup + CLI php so jobs survive FPM request end (media/poll Creates
 * previously jammed here when PHP_BINARY pointed at php-fpm).
 *
 * @param list<string> $inboxUrls
 */
function ap_deliver_fanout_background(array $activity, array $inboxUrls, string $keyId, string $privPath): bool
{
    $inboxUrls = array_values(array_unique(array_filter($inboxUrls, static fn($u) => is_string($u) && str_starts_with($u, 'https://'))));
    if (!$inboxUrls) {
        return false;
    }
    $dir = '/tmp/ap-deliver-jobs';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        ap_log('deliver_bg_mkdir_fail');
        return false;
    }
    $job = [
        'activity' => $activity,
        'inboxes' => $inboxUrls,
        'key_id' => $keyId,
        'priv' => $privPath,
        // Short timeout: one hung Threads/shared inbox must not stall the rest
        'timeout' => 2.5,
        'created_at' => gmdate('c'),
    ];
    $file = $dir . '/' . bin2hex(random_bytes(8)) . '.json';
    if (@file_put_contents($file, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
        ap_log('deliver_bg_write_fail');
        return false;
    }
    @chmod($file, 0600);
    $php = ap_php_cli_binary();
    $worker = __DIR__ . '/ap-deliver-worker.php';
    if (!is_file($worker)) {
        @unlink($file);
        return false;
    }
    // Detach fully from FPM so the worker is not killed when the request ends.
    $cmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($file)
        . ' >/dev/null 2>&1 </dev/null &';
    exec($cmd);
    ap_log('deliver_bg_queued file=' . basename($file) . ' targets=' . count($inboxUrls) . ' php=' . $php);
    return true;
}

/**
 * Public Create/Announce/Delete fan-out that cannot traffic-jam on media/polls:
 * Bridgy Fed first (Bluesky), then a small sync follower budget, everything else background.
 *
 * @param list<string> $priorityExtra reply-author / announce-target inboxes
 * @return array{delivered:int,queued:int,bridgy:bool}
 */
function ap_deliver_public_activity(array $activity, array $priorityExtra = []): array
{
    $prevActor = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
    $actorUrl = is_string($activity['actor'] ?? null) ? rtrim((string) $activity['actor'], '/') : '';
    $owner = '';
    if ($actorUrl !== '' && preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $actorUrl, $am)) {
        $owner = $actorUrl;
        $prevKey = is_array($prevActor) ? (string) ($prevActor['key'] ?? '') : '';
        if ($prevKey !== (string) $am[1] && function_exists('ap_request_actor_set')) {
            ap_request_actor_set((string) $am[1]);
        }
    } else {
        $owner = function_exists('ap_local_actor_id') ? rtrim(ap_local_actor_id(), '/') : '';
    }
    $ident = ap_outbound_identity();
    $followers = ap_followers_list($owner !== '' ? $owner : null);
    $bridgyInboxes = ap_bridgy_priority_inboxes();

    $delivered = 0;
    $bridgyOk = false;
    // Bridgy FIRST and alone — never sit behind slow followers/shared inboxes.
    // Personal inbox is enough for Bluesky; stop early on first 2xx.
    foreach ($bridgyInboxes as $inbox) {
        if (ap_is_blocked_inbox($inbox)) {
            continue;
        }
        // 5s: Bridgy often ACKs immediately even when its media pipeline is busy.
        if (ap_deliver_signed_json($inbox, $activity, $ident['key_id'], $ident['priv'], 5.0)) {
            $delivered++;
            $bridgyOk = true;
            break;
        }
    }
    if (!$bridgyOk) {
        // Don't lose Bluesky if Bridgy timed out — retry in background.
        ap_deliver_fanout_background($activity, $bridgyInboxes, $ident['key_id'], $ident['priv']);
        ap_log('deliver_bridgy_deferred type=' . (string) ($activity['type'] ?? '?'));
    }

    $extraNonBridgy = [];
    foreach ($priorityExtra as $inbox) {
        if (!is_string($inbox) || !str_starts_with($inbox, 'https://')) {
            continue;
        }
        if (str_contains($inbox, 'brid.gy') || ap_is_blocked_inbox($inbox)) {
            continue;
        }
        $extraNonBridgy[] = $inbox;
    }

    $priority = ap_delivery_targets_filtered($followers, $extraNonBridgy);
    $otherPriority = [];
    foreach ($priority as $inbox) {
        if (str_contains($inbox, 'brid.gy')) {
            continue; // already handled
        }
        $otherPriority[] = $inbox;
    }

    // Small sync budget so Ice Cubes / admin never wait on a long follower list.
    $syncBudget = 6;
    $sync = array_slice($otherPriority, 0, $syncBudget);
    $bgFollowers = array_slice($otherPriority, $syncBudget);
    foreach ($sync as $inbox) {
        if (ap_deliver_signed_json($inbox, $activity, $ident['key_id'], $ident['priv'], 2.5)) {
            $delivered++;
        }
    }

    $prioritySet = array_fill_keys(array_merge($bridgyInboxes, $otherPriority), true);
    $deferred = $bgFollowers;
    foreach (ap_delivery_targets_filtered([], ap_known_shared_inboxes(40)) as $inbox) {
        if (isset($prioritySet[$inbox])) {
            continue;
        }
        // Dead Bridgy path + any brid.gy already covered
        if (str_contains($inbox, 'brid.gy')) {
            continue;
        }
        $deferred[] = $inbox;
    }
    // Mastodon-compatible: fan public Creates/Announces to accepted relays.
    // Do NOT send Update/Delete/Undo to relays — remotes that already have the
    // object (e.g. someone who boosted it) would get a second copy via the relay
    // and show duplicate "edited a post you boosted" notifications.
    $actType = (string) ($activity['type'] ?? '');
    $relayOkTypes = ['Create', 'Announce', 'Quote', 'QuotePost'];
    if (in_array($actType, $relayOkTypes, true)) {
        if (!function_exists('ap_relays_accepted_inboxes')) {
            require_once __DIR__ . '/ap-relays.php';
        }
        if (function_exists('ap_relays_accepted_inboxes')) {
            foreach (ap_relays_accepted_inboxes() as $relayInbox) {
                if (isset($prioritySet[$relayInbox]) || ap_is_blocked_inbox($relayInbox)) {
                    continue;
                }
                $deferred[] = $relayInbox;
            }
        }
    }
    $deferred = array_values(array_unique($deferred));

    $queued = 0;
    if ($deferred) {
        if (ap_deliver_fanout_background($activity, $deferred, $ident['key_id'], $ident['priv'])) {
            $queued = count($deferred);
        } else {
            foreach (array_slice($deferred, 0, 5) as $inbox) {
                if (ap_deliver_signed_json($inbox, $activity, $ident['key_id'], $ident['priv'], 2.5)) {
                    $delivered++;
                }
            }
        }
    }

    if (function_exists('ap_request_actor_set')) {
        ap_request_actor_set(is_array($prevActor) ? (string) ($prevActor['key'] ?? '') : null);
    }

    return ['delivered' => $delivered, 'queued' => $queued, 'bridgy' => $bridgyOk];
}

/**
 * Fan-out for unlisted / followers-only Creates: followers + priority extras only.
 * No Bridgy, no guessed shared-inbox spray (would leak beyond followers).
 *
 * @param list<string> $priorityExtra
 * @return array{delivered:int,queued:int,bridgy:bool}
 */
function ap_deliver_followers_activity(array $activity, array $priorityExtra = []): array
{
    $prevActor = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
    $actorUrl = is_string($activity['actor'] ?? null) ? rtrim((string) $activity['actor'], '/') : '';
    $owner = '';
    if ($actorUrl !== '' && preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $actorUrl, $am)) {
        $owner = $actorUrl;
        $prevKey = is_array($prevActor) ? (string) ($prevActor['key'] ?? '') : '';
        if ($prevKey !== (string) $am[1] && function_exists('ap_request_actor_set')) {
            ap_request_actor_set((string) $am[1]);
        }
    } else {
        $owner = function_exists('ap_local_actor_id') ? rtrim(ap_local_actor_id(), '/') : '';
    }
    $ident = ap_outbound_identity();
    $followers = ap_followers_list($owner !== '' ? $owner : null);
    $extra = [];
    foreach ($priorityExtra as $inbox) {
        if (!is_string($inbox) || !str_starts_with($inbox, 'https://')) {
            continue;
        }
        if (str_contains($inbox, 'brid.gy') || ap_is_blocked_inbox($inbox)) {
            continue;
        }
        $extra[] = $inbox;
    }
    $targets = ap_delivery_targets_filtered($followers, $extra);
    // Drop any Bridgy URLs that slipped in via follower shared_inbox
    $targets = array_values(array_filter(
        $targets,
        static fn($u) => is_string($u) && !str_contains($u, 'brid.gy')
    ));

    $delivered = 0;
    $syncBudget = 8;
    $sync = array_slice($targets, 0, $syncBudget);
    $deferred = array_slice($targets, $syncBudget);
    foreach ($sync as $inbox) {
        if (ap_deliver_signed_json($inbox, $activity, $ident['key_id'], $ident['priv'], 2.5)) {
            $delivered++;
        }
    }
    $queued = 0;
    if ($deferred) {
        if (ap_deliver_fanout_background($activity, $deferred, $ident['key_id'], $ident['priv'])) {
            $queued = count($deferred);
        } else {
            foreach (array_slice($deferred, 0, 8) as $inbox) {
                if (ap_deliver_signed_json($inbox, $activity, $ident['key_id'], $ident['priv'], 2.5)) {
                    $delivered++;
                }
            }
        }
    }
    if (function_exists('ap_request_actor_set')) {
        ap_request_actor_set(is_array($prevActor) ? (string) ($prevActor['key'] ?? '') : null);
    }
    return ['delivered' => $delivered, 'queued' => $queued, 'bridgy' => false];
}

/**
 * Auto-approve FEP-044f QuoteRequest for public quotes of our notes.
 * Returns action label, or null if this QuoteRequest is not for us.
 */
function ap_handle_quote_request(array $activity): ?string
{
    $actorId = ap_as_id($activity['actor'] ?? null);
    $quotedId = ap_as_id($activity['object'] ?? null);
    if (!$actorId || !$quotedId) {
        return 'quote_request_invalid';
    }
    if (!preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)/notes/#', $quotedId, $qm)) {
        return null; // not a local note
    }
    $ownerKey = (string) $qm[1];
    $ownerActor = 'https://mkultra.monster/users/' . $ownerKey;
    // Confirm note exists
    $st = ap_db()->prepare('SELECT id FROM outbox_notes WHERE id = ?');
    $st->execute([$quotedId]);
    if (!$st->fetch()) {
        ap_log('quote_request_unknown_note ' . ap_short($quotedId));
        return 'quote_request_unknown';
    }
    if (ap_is_blocked_actor($actorId)) {
        ap_log('quote_request_blocked ' . ap_short($actorId));
        return 'quote_request_blocked';
    }

    // Respect the account-level quote audience before minting an authorization.
    // Compatible clients also receive this policy in the Note interactionPolicy;
    // this check protects the server when a remote instance sends a request anyway.
    $quotePolicy = (string) (ap_profile_get($ownerKey)['quote_policy'] ?? 'anyone');
    if ($quotePolicy === 'nobody') {
        ap_log('quote_request_policy_denied nobody actor=' . ap_short($actorId));
        return 'quote_request_policy_denied';
    }
    if ($quotePolicy === 'followers') {
        $st = ap_db()->prepare(
            'SELECT 1 FROM followers WHERE owner_actor_id IN (?, ?) AND (actor_id = ? OR actor_id = ?) LIMIT 1'
        );
        $st->execute([$ownerActor, $ownerActor . '/', $actorId, $actorId . '/']);
        if (!$st->fetchColumn()) {
            ap_log('quote_request_policy_denied followers actor=' . ap_short($actorId));
            return 'quote_request_policy_denied';
        }
    }

    $instrument = $activity['instrument'] ?? null;
    $quotingId = ap_as_id($instrument);
    if (!$quotingId && is_array($instrument) && isset($instrument['id'])) {
        $quotingId = ap_as_id($instrument['id']);
    }
    if (!$quotingId) {
        // Some servers send instrument as the quote Note only
        return 'quote_request_no_instrument';
    }

    $prevActor = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
    if (function_exists('ap_request_actor_set')) {
        ap_request_actor_set($ownerKey);
    }
    $ident = ap_outbound_identity();

    $stamp = ap_quote_auth_create($quotedId, $quotingId, $actorId);
    if (empty($stamp['ok'])) {
        if (function_exists('ap_request_actor_set')) {
            ap_request_actor_set(is_array($prevActor) ? (string) ($prevActor['key'] ?? '') : null);
        }
        ap_log('quote_auth_create_fail ' . ($stamp['error'] ?? ''));
        return 'quote_request_stamp_fail';
    }
    $stampId = (string) $stamp['id'];

    $accept = [
        '@context' => [
            'https://www.w3.org/ns/activitystreams',
            ap_quote_ld_context(),
        ],
        'id' => $ownerActor . '/accepts/' . bin2hex(random_bytes(8)),
        'type' => 'Accept',
        'actor' => $ownerActor,
        'to' => [$actorId],
        'object' => [
            'type' => 'QuoteRequest',
            'id' => ap_as_id($activity) ?: ($quotingId . '/quote'),
            'actor' => $actorId,
            'object' => $quotedId,
            'instrument' => $quotingId,
        ],
        'result' => $stampId,
    ];

    $inbox = null;
    $doc = ap_fetch_actor_doc($actorId);
    if ($doc) {
        $inbox = ap_resolve_personal_inbox_from_actor_doc($doc) ?: ap_resolve_inbox_from_actor_doc($doc);
    }
    $ok = false;
    if ($inbox) {
        $ok = ap_deliver_signed_json($inbox, $accept, $ident['key_id'], $ident['priv'], 5.0);
    }
    if (function_exists('ap_request_actor_set')) {
        ap_request_actor_set(is_array($prevActor) ? (string) ($prevActor['key'] ?? '') : null);
    }
    ap_metrics_record('QuoteRequest', $actorId, $quotedId, $ownerActor, 0, $ok ? 'quote_accepted' : 'quote_accept_deliver_fail', $quotingId);
    ap_log('quote_request ' . ($ok ? 'accepted' : 'accept_fail') . ' by=' . ap_short($actorId) . ' note=' . ap_short($quotedId) . ' stamp=' . ap_short($stampId));
    return $ok ? 'quote_accepted' : 'quote_accept_deliver_fail';
}

/**
 * Publish a Note as @cmdr_nova (admin compose + queue + Mastodon API).
 * Visibility: public | unlisted (silent public) | private (followers-only).
 *
 * @param list<int> $mediaLocalIds
 * @param array{options?:list<string>,expires_in?:int,multiple?:bool}|null $poll
 * @return array{ok:bool,error?:string,note_id?:string,create_id?:string,local_id?:int,published?:string,content_html?:string,delivered?:int,queued?:int,visibility?:string}
 */
function ap_publish_status_text(
    string $content,
    ?string $inReplyTo = null,
    ?string $toActor = null,
    array $mediaLocalIds = [],
    string $spoilerText = '',
    ?bool $sensitive = null,
    ?string $quoteObjectId = null,
    ?array $poll = null,
    string $visibility = 'public'
): array {
    if (!function_exists('ap_media_by_local_ids')) {
        require_once __DIR__ . '/ap-r2.php';
    }
    $content = trim(ap_fix_utf8($content));
    $spoilerText = mb_substr(trim(ap_fix_utf8($spoilerText)), 0, 500);
    $quoteObjectId = $quoteObjectId !== null ? trim($quoteObjectId) : '';
    if ($quoteObjectId !== '' && !str_starts_with($quoteObjectId, 'https://')) {
        return ['ok' => false, 'error' => 'Invalid quoted status URL'];
    }
    $mediaLocalIds = array_values(array_filter(array_map('intval', $mediaLocalIds), static fn($i) => $i > 0));
    $mediaRows = $mediaLocalIds ? ap_media_by_local_ids($mediaLocalIds) : [];
    if (count($mediaRows) !== count($mediaLocalIds)) {
        return ['ok' => false, 'error' => 'One or more media_ids are invalid'];
    }

    $pollNorm = null;
    if (is_array($poll)) {
        $optsIn = $poll['options'] ?? [];
        if (!is_array($optsIn)) {
            return ['ok' => false, 'error' => 'Invalid poll options'];
        }
        $opts = [];
        foreach ($optsIn as $o) {
            $t = trim(ap_fix_utf8((string) $o));
            if ($t === '') {
                continue;
            }
            if (mb_strlen($t) > 50) {
                return ['ok' => false, 'error' => 'Poll option too long (max 50)'];
            }
            $opts[] = $t;
            if (count($opts) >= 4) {
                break;
            }
        }
        if (count($opts) < 2) {
            return ['ok' => false, 'error' => 'Poll needs at least 2 options'];
        }
        $expiresIn = (int) ($poll['expires_in'] ?? 86400);
        if ($expiresIn < 300 || $expiresIn > 604800) {
            return ['ok' => false, 'error' => 'Poll expires_in must be between 300 and 604800 seconds'];
        }
        if ($quoteObjectId !== '') {
            return ['ok' => false, 'error' => 'Cannot combine poll with quote'];
        }
        $pollNorm = [
            'options' => $opts,
            'expires_in' => $expiresIn,
            'multiple' => !empty($poll['multiple']),
        ];
    }

    // Quote-boost / media / poll with empty commentary is allowed
    if ($content === '' && !$mediaRows && $quoteObjectId === '' && $pollNorm === null) {
        return ['ok' => false, 'error' => 'Post text, media, or poll required'];
    }
    if (mb_strlen($content) > 2000) {
        return ['ok' => false, 'error' => 'Post text required (max 2000 chars).'];
    }
    $inReplyTo = $inReplyTo !== null ? trim($inReplyTo) : '';
    if ($inReplyTo !== '' && !str_starts_with($inReplyTo, 'https://')) {
        return ['ok' => false, 'error' => 'Invalid in_reply_to URL'];
    }
    // Mastodon-compatible: CW implies sensitive unless client forces false.
    $isSensitive = $sensitive !== null ? $sensitive : ($spoilerText !== '');

    // Prefer request-local actor (VAAK session) so invitees don't publish as cmdr_nova
    $actor = function_exists('ap_local_actor_id') ? ap_local_actor_id() : LOCAL_ACTOR;
    if ($actor === '' || !str_starts_with($actor, 'https://mkultra.monster/users/')) {
        $actor = LOCAL_ACTOR;
    }
    $noteId = $actor . '/notes/' . bin2hex(random_bytes(8));
    $createId = $actor . '/creates/' . bin2hex(random_bytes(8));
    $published = gmdate('c');
    $visibility = function_exists('ap_normalize_visibility')
        ? ap_normalize_visibility($visibility)
        : 'public';
    // Never publish as DM through this path — callers must use ap_dm_send for direct.
    if ($visibility === 'direct') {
        $visibility = 'public';
    }

    // Mastodon-compatible addressing
    $publicId = 'https://www.w3.org/ns/activitystreams#Public';
    $followersId = $actor . '/followers';
    if ($visibility === 'unlisted') {
        // Silent public: to=followers, cc=Public
        $to = [$followersId];
        $cc = [$publicId];
    } elseif ($visibility === 'private') {
        // Followers-only: to=followers, no Public
        $to = [$followersId];
        $cc = [];
    } else {
        $to = [$publicId];
        $cc = [$followersId];
    }
    if ($toActor && str_starts_with($toActor, 'https://')) {
        $to[] = $toActor;
    }

    $replyActor = $toActor;
    $replyLocalId = null;
    $extraMentionActors = [];
    if ($inReplyTo !== '' && str_starts_with($inReplyTo, 'https://')) {
        $objDoc = ap_fetch_as2_object($inReplyTo);
        if (is_array($objDoc)) {
            $at = ap_as_id($objDoc['attributedTo'] ?? null) ?: ap_as_id($objDoc['actor'] ?? null);
            if ($at) {
                $replyActor = $at;
                $extraMentionActors[] = $at;
                // Self-replies: do NOT address ourselves in `to` (that loops the Create
                // back into our own inbox → fake mention notification + thread dupe).
                if (
                    rtrim($at, '/') !== rtrim($actor, '/')
                    && !in_array($at, $to, true)
                ) {
                    $to[] = $at;
                }
            }
        }
        $mapped = function_exists('ap_masto_status_by_note_id') ? ap_masto_status_by_note_id($inReplyTo) : null;
        if ($mapped) {
            $replyLocalId = (int) $mapped['local_id'];
        }
    }

    // Mentions in body → linked HTML + AS2 Mention tags + audience (not DMs).
    // Ensure entity helpers are available (queue/CLI may only have loaded ap-inbox).
    if (!function_exists('ap_masto_content_with_mentions')) {
        require_once __DIR__ . '/ap-masto-entities.php';
    }
    $mentionPack = null;
    $as2MentionTags = [];
    if ($content !== '' && function_exists('ap_masto_content_with_mentions')) {
        $mentionPack = ap_masto_content_with_mentions($content, $extraMentionActors);
        foreach ($mentionPack['mentions'] ?? [] as $m) {
            $mUrl = rtrim((string) ($m['url'] ?? ''), '/');
            if ($mUrl === '' || !str_starts_with($mUrl, 'https://')) {
                continue;
            }
            if (rtrim($mUrl, '/') === rtrim($actor, '/')) {
                continue;
            }
            $as2MentionTags[] = [
                'type' => 'Mention',
                'href' => $mUrl,
                'name' => '@' . ltrim((string) ($m['acct'] ?? $m['username'] ?? 'user'), '@'),
            ];
            // Reply parent already in `to`; other mentions go to `cc` (Mastodon-like).
            // Private: mentioned people must still receive the Create → put in `to`.
            if ($visibility === 'private') {
                if (!in_array($mUrl, $to, true)) {
                    $to[] = $mUrl;
                }
            } else {
                if (!in_array($mUrl, $to, true) && !in_array($mUrl, $cc, true)) {
                    $cc[] = $mUrl;
                }
            }
        }
    }
    $contentHtml = '';
    if ($content !== '') {
        $contentHtml = is_array($mentionPack) && !empty($mentionPack['content'])
            ? (string) $mentionPack['content']
            : ap_plain_text_to_html($content);
        if (function_exists('ap_normalize_status_html')) {
            $contentHtml = ap_normalize_status_html($contentHtml);
        }
    }

    $noteType = $pollNorm !== null ? 'Question' : 'Note';
    $note = [
        'id' => $noteId,
        'type' => $noteType,
        'published' => $published,
        'attributedTo' => $actor,
        'content' => $contentHtml !== '' ? $contentHtml : '<p></p>',
        'to' => $to,
        'cc' => $cc,
        'url' => $noteId,
        'sensitive' => $isSensitive,
        'interactionPolicy' => function_exists('ap_note_interaction_policy_for_actor')
            ? ap_note_interaction_policy_for_actor((string) (basename(rtrim($actor, '/')) ?: 'cmdr_nova'))
            : ap_note_interaction_policy_public(),
    ];
    if ($spoilerText !== '') {
        // ActivityPub / Mastodon CW text
        $note['summary'] = $spoilerText;
    }
    if ($inReplyTo !== '') {
        $note['inReplyTo'] = $inReplyTo;
    }
    if ($as2MentionTags !== []) {
        $note['tag'] = $as2MentionTags;
    }
    if ($pollNorm !== null) {
        $endTime = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . (int) $pollNorm['expires_in'] . ' seconds')
            ->format('Y-m-d\TH:i:s\Z');
        $choices = [];
        foreach ($pollNorm['options'] as $title) {
            $choices[] = [
                'type' => 'Note',
                'name' => $title,
            ];
        }
        if ($pollNorm['multiple']) {
            $note['anyOf'] = $choices;
        } else {
            $note['oneOf'] = $choices;
        }
        $note['endTime'] = $endTime;
    }
    if ($quoteObjectId !== '') {
        $quoteObjectId = rtrim($quoteObjectId, '/');
        $note['quote'] = $quoteObjectId;
        // FEP-e232 / Misskey-compatible link tag for wider quote discovery
        $quoteTag = [
            'type' => 'Link',
            'mediaType' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
            'href' => $quoteObjectId,
            'rel' => 'https://misskey-hub.net/ns#_misskey_quote',
        ];
        $existingTags = isset($note['tag']) && is_array($note['tag']) ? $note['tag'] : [];
        $existingTags[] = $quoteTag;
        $note['tag'] = $existingTags;
        // QuoteAuthorization when quoting our own / any local notes we own
        if (str_starts_with($quoteObjectId, $actor . '/notes/')) {
            $auth = ap_quote_auth_create($quoteObjectId, $noteId, $actor);
            if (!empty($auth['ok']) && !empty($auth['id'])) {
                $note['quoteAuthorization'] = $auth['id'];
            }
        }
        // CC the quoted author when known
        $qDoc = ap_fetch_as2_object($quoteObjectId);
        if (is_array($qDoc)) {
            $qActor = ap_as_id($qDoc['attributedTo'] ?? null) ?: ap_as_id($qDoc['actor'] ?? null);
            if ($qActor && str_starts_with($qActor, 'https://') && !in_array($qActor, $cc, true) && !in_array($qActor, $to, true)) {
                $cc[] = $qActor;
                $note['cc'] = $cc;
            }
        }
    }
    // Keep Create audience in sync after mention/quote audience mutations
    $note['to'] = $to;
    $note['cc'] = $cc;
    $attachments = ap_media_as2_attachments($mediaRows);
    if ($attachments) {
        $note['attachment'] = $attachments;
    }

    $create = ap_create_finalize([
        'id' => $createId,
        'type' => 'Create',
        'actor' => $actor,
        'published' => $published,
        'to' => $to,
        'cc' => $cc,
        'object' => $note,
    ]);

    // Empty commentary is fine for quote-boosts / polls; avoid storing the literal "(quote)" placeholder
    $plainForStore = $content !== ''
        ? $content
        : ($quoteObjectId !== '' ? '' : ($pollNorm !== null ? '(poll)' : '(media)'));
    ap_outbox_store([
        'id' => $noteId,
        'create_id' => $createId,
        'published' => $published,
        'content' => $contentHtml !== '' ? $contentHtml : '<p></p>',
        'in_reply_to' => $inReplyTo !== '' ? $inReplyTo : null,
        'to' => $to,
        'cc' => $cc,
        'raw_create_json' => json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'kind' => $quoteObjectId !== '' ? 'quote' : ($pollNorm !== null ? 'poll' : 'compose'),
        'visibility' => $visibility,
    ]);
    $localId = ap_masto_status_register(
        $noteId,
        $createId,
        $published,
        $plainForStore === '(poll)' ? '' : $plainForStore,
        $replyLocalId,
        $spoilerText,
        $isSensitive,
        $visibility
    );
    if ($mediaLocalIds) {
        ap_media_attach_to_status($mediaLocalIds, $localId);
    }
    if ($pollNorm !== null) {
        $optsStore = [];
        foreach ($pollNorm['options'] as $title) {
            $optsStore[] = ['title' => $title, 'votes_count' => 0];
        }
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . (int) $pollNorm['expires_in'] . ' seconds')
            ->format('c');
        ap_db()->prepare(
            'INSERT INTO masto_polls (status_local_id, note_id, multiple, expires_at, options_json, votes_count, voters_count, voters_json, created_at)
             VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?)'
        )->execute([
            $localId,
            $noteId,
            $pollNorm['multiple'] ? 1 : 0,
            $expiresAt,
            json_encode($optsStore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '[]',
            ap_db_now(),
        ]);
    }
    $feedSummary = $spoilerText !== '' ? ('[CW] ' . $content) : $content;
    if ($quoteObjectId !== '') {
        $summaryDoc = null;
        if (preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+/notes/#', $quoteObjectId)) {
            $summaryDoc = ap_local_note_as2_doc($quoteObjectId);
        }
        if ($summaryDoc === null) {
            $summaryDoc = ap_fetch_as2_object($quoteObjectId);
        }
        if (is_array($summaryDoc)) {
            $summaryDoc = ap_unwrap_as2_object($summaryDoc);
        }
        $combined = ap_format_quote_feed_summary(
            $content !== '' ? $content : null,
            is_array($summaryDoc) ? $summaryDoc : null,
            $quoteObjectId
        );
        if (is_string($combined) && $combined !== '') {
            $feedSummary = $combined;
        }
    }
    ap_metrics_record('Create', $actor, $noteId, null, strlen($contentHtml), 'compose', $feedSummary, null, null, null, '', false, $visibility);

    $priorityExtra = [];
    // Priority-deliver to reply author + mentioned actors (not DMs — still public/unlisted/private Create).
    $priorityActors = [];
    if (is_string($replyActor) && $replyActor !== '') {
        $priorityActors[] = $replyActor;
    }
    foreach ($as2MentionTags as $mt) {
        $href = rtrim((string) ($mt['href'] ?? ''), '/');
        if ($href !== '') {
            $priorityActors[] = $href;
        }
    }
    $seenPri = [];
    foreach ($priorityActors as $pa) {
        $pa = rtrim((string) $pa, '/');
        if ($pa === '' || isset($seenPri[$pa])) {
            continue;
        }
        $seenPri[$pa] = true;
        // Never deliver a reply priority-hit to our own inbox (self-threads).
        if (rtrim($pa, '/') === rtrim($actor, '/') || ap_is_blocked_actor($pa)) {
            continue;
        }
        if (count($priorityExtra) >= 8) {
            break;
        }
        $doc = ap_fetch_actor_doc($pa);
        if (!$doc) {
            continue;
        }
        $inbox = ap_resolve_personal_inbox_from_actor_doc($doc) ?: ap_resolve_inbox_from_actor_doc($doc);
        $ownInbox = rtrim($actor, '/') . '/inbox';
        if (
            $inbox
            && !ap_is_blocked_inbox($inbox)
            && !str_contains($inbox, $ownInbox)
            && !preg_match('#mkultra\.monster/users/[A-Za-z0-9_]+/inbox#', $inbox)
            && !str_contains($inbox, 'mkultra.monster/inbox')
        ) {
            $priorityExtra[] = $inbox;
        }
    }

    // Public: Bridgy + followers + shared inboxes. Unlisted/private: followers only.
    $fan = ($visibility === 'public')
        ? ap_deliver_public_activity($create, $priorityExtra)
        : ap_deliver_followers_activity($create, $priorityExtra);
    $delivered = $fan['delivered'];
    $queued = $fan['queued'];

    ap_log("publish_status create=$createId local_id=$localId visibility=$visibility delivered=$delivered queued=$queued bridgy=" . ($fan['bridgy'] ? '1' : '0')
        . ' kind=' . ($pollNorm !== null ? 'poll' : ($attachments ? 'media' : 'text')));

    // Warm link-preview cache for the first URL (best-effort; don't fail the post)
    if ($content !== '' && !$mediaRows && function_exists('ap_link_preview_extract_url') && function_exists('ap_link_preview_for_url')) {
        try {
            require_once __DIR__ . '/ap-link-preview.php';
            $warmUrl = ap_link_preview_extract_url($content);
            if ($warmUrl !== null) {
                ap_link_preview_for_url($warmUrl, true);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    return [
        'ok' => true,
        'note_id' => $noteId,
        'create_id' => $createId,
        'local_id' => $localId,
        'published' => $published,
        'content_html' => $contentHtml,
        'visibility' => $visibility,
        'delivered' => $delivered,
        'queued' => $queued,
    ];
}

/**
 * Edit a local public status and federate Update(Note|Question).
 *
 * @param list<int> $mediaLocalIds empty = keep existing attachments
 * @return array{ok:bool,error?:string,local_id?:int}
 */
function ap_update_local_status(
    int $localId,
    string $content,
    string $spoilerText = '',
    ?bool $sensitive = null,
    ?array $mediaLocalIds = null
): array {
    if (!function_exists('ap_media_by_local_ids')) {
        require_once __DIR__ . '/ap-r2.php';
    }
    $row = ap_masto_status_by_local_id($localId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Status not found'];
    }
    $noteId = (string) ($row['note_id'] ?? '');
    $ident = ap_outbound_identity();
    $actor = $ident['id'];
    if ($noteId === '' || !str_starts_with($noteId, $actor . '/notes/')) {
        return ['ok' => false, 'error' => 'Refusing to edit non-local status'];
    }

    $content = trim(ap_fix_utf8($content));
    $spoilerText = mb_substr(trim(ap_fix_utf8($spoilerText)), 0, 500);
    if (mb_strlen($content) > 2000) {
        return ['ok' => false, 'error' => 'Post text max 2000 chars'];
    }

    $poll = null;
    try {
        $pst = ap_db()->prepare('SELECT * FROM masto_polls WHERE status_local_id = ?');
        $pst->execute([$localId]);
        $poll = $pst->fetch();
        if (!is_array($poll)) {
            $poll = null;
        }
    } catch (Throwable $e) {
        $poll = null;
    }

    $mediaRows = [];
    if ($mediaLocalIds !== null) {
        $mediaLocalIds = array_values(array_filter(array_map('intval', $mediaLocalIds), static fn($i) => $i > 0));
        if (count($mediaLocalIds) > 4) {
            return ['ok' => false, 'error' => 'Too many media attachments (max 4)'];
        }
        $mediaRows = $mediaLocalIds ? ap_media_by_local_ids($mediaLocalIds) : [];
        if (count($mediaRows) !== count($mediaLocalIds)) {
            return ['ok' => false, 'error' => 'One or more media_ids are invalid'];
        }
    } else {
        // Keep currently attached media
        $mst = ap_db()->prepare('SELECT * FROM masto_media WHERE status_local_id = ? ORDER BY local_id ASC');
        $mst->execute([$localId]);
        $mediaRows = $mst->fetchAll();
        $mediaLocalIds = array_map(static fn($r) => (int) $r['local_id'], $mediaRows);
    }

    if ($content === '' && !$mediaRows && $poll === null) {
        return ['ok' => false, 'error' => 'Post text or media required'];
    }

    $isSensitive = $sensitive !== null ? $sensitive : ($spoilerText !== '' || !empty($row['sensitive']));
    // Same HTML path as create: mentions/hashtags + Mastodon-style <p> / <br> (not nl2br-in-one-p)
    if (!function_exists('ap_masto_content_with_mentions')) {
        require_once __DIR__ . '/ap-masto-entities.php';
    }
    $contentHtml = '<p></p>';
    if ($content !== '') {
        if (function_exists('ap_masto_content_with_mentions')) {
            $pack = ap_masto_content_with_mentions($content);
            $contentHtml = (string) ($pack['content'] ?? '');
        }
        if ($contentHtml === '' || $contentHtml === '<p></p>') {
            $contentHtml = function_exists('ap_plain_text_to_html')
                ? ap_plain_text_to_html($content)
                : ('<p>' . htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>');
        }
        if (function_exists('ap_normalize_status_html')) {
            $contentHtml = ap_normalize_status_html($contentHtml);
        }
    }
    $editedAt = gmdate('c');

    // Rebuild Note/Question from stored Create when possible
    $ob = ap_db()->prepare('SELECT * FROM outbox_notes WHERE id = ?');
    $ob->execute([$noteId]);
    $obRow = $ob->fetch();
    $to = ['https://www.w3.org/ns/activitystreams#Public'];
    $cc = [$actor . '/followers'];
    $inReplyTo = null;
    $noteType = $poll !== null ? 'Question' : 'Note';
    $extra = [];
    if (is_array($obRow)) {
        $toDec = json_decode((string) ($obRow['to_json'] ?? ''), true);
        $ccDec = json_decode((string) ($obRow['cc_json'] ?? ''), true);
        if (is_array($toDec) && $toDec) {
            $to = $toDec;
        }
        if (is_array($ccDec) && $ccDec) {
            $cc = $ccDec;
        }
        if (!empty($obRow['in_reply_to'])) {
            $inReplyTo = (string) $obRow['in_reply_to'];
        }
        $raw = json_decode((string) ($obRow['raw_create_json'] ?? ''), true);
        if (is_array($raw) && isset($raw['object']) && is_array($raw['object'])) {
            $oldObj = $raw['object'];
            $noteType = (string) ($oldObj['type'] ?? $noteType);
            foreach (['quote', 'quoteAuthorization', 'tag', 'oneOf', 'anyOf', 'endTime', 'interactionPolicy'] as $k) {
                if (isset($oldObj[$k])) {
                    $extra[$k] = $oldObj[$k];
                }
            }
        }
    }

    $note = array_merge([
        'id' => $noteId,
        'type' => $noteType,
        'published' => (string) ($row['published'] ?? $editedAt),
        'updated' => $editedAt,
        'attributedTo' => $actor,
        'content' => $contentHtml,
        'to' => $to,
        'cc' => $cc,
        'url' => $noteId,
        'sensitive' => $isSensitive,
    ], $extra);
    if ($spoilerText !== '') {
        $note['summary'] = $spoilerText;
    } else {
        unset($note['summary']);
    }
    if ($inReplyTo) {
        $note['inReplyTo'] = $inReplyTo;
    }
    $attachments = ap_media_as2_attachments($mediaRows);
    if ($attachments) {
        $note['attachment'] = $attachments;
    } else {
        unset($note['attachment']);
    }
    if (!isset($note['interactionPolicy'])) {
        $note['interactionPolicy'] = ap_note_interaction_policy_public();
    }

    $updateId = $actor . '/updates/' . bin2hex(random_bytes(8));
    $update = ap_as2_ensure_top_context([
        'id' => $updateId,
        'type' => 'Update',
        'actor' => $actor,
        'published' => $editedAt,
        'to' => $to,
        'cc' => $cc,
        'object' => $note,
    ]);

    $createId = (string) ($row['create_id'] ?? ($noteId . '/activity'));
    $create = ap_create_finalize([
        'id' => $createId,
        'type' => 'Create',
        'actor' => $actor,
        'published' => (string) ($row['published'] ?? $editedAt),
        'updated' => $editedAt,
        'to' => $to,
        'cc' => $cc,
        'object' => $note,
    ]);

    ap_db()->prepare(
        'UPDATE outbox_notes SET content = ?, raw_create_json = ?, to_json = ?, cc_json = ? WHERE id = ?'
    )->execute([
        $contentHtml,
        json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        json_encode($to, JSON_UNESCAPED_SLASHES),
        json_encode($cc, JSON_UNESCAPED_SLASHES),
        $noteId,
    ]);
    ap_db()->prepare(
        'UPDATE masto_statuses SET content_text = ?, spoiler_text = ?, sensitive = ?, edited_at = ? WHERE local_id = ?'
    )->execute([
        $content,
        $spoilerText,
        $isSensitive ? 1 : 0,
        $editedAt,
        $localId,
    ]);
    if (function_exists('ap_search_fts_index_status_row')) {
        require_once __DIR__ . '/ap-search-fts.php';
        $pub = '';
        try {
            $pst = ap_db()->prepare('SELECT published FROM masto_statuses WHERE local_id = ?');
            $pst->execute([$localId]);
            $pub = (string) ($pst->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $pub = $editedAt;
        }
        ap_search_fts_index_status_row([
            'local_id' => $localId,
            'note_id' => $noteId,
            'published' => $pub !== '' ? $pub : $editedAt,
            'content_text' => $content,
            'spoiler_text' => $spoilerText,
        ]);
    }

    // Re-attach media
    ap_db()->prepare('UPDATE masto_media SET status_local_id = NULL WHERE status_local_id = ?')->execute([$localId]);
    if ($mediaLocalIds) {
        ap_media_attach_to_status($mediaLocalIds, $localId);
    }

    // Keep compose / timeline event rows in sync with the edited text/media
    if (function_exists('ap_events_refresh_create_from_update')) {
        $mediaUrls = [];
        foreach ($mediaRows as $mr) {
            if (!is_array($mr)) {
                continue;
            }
            $mu = (string) ($mr['public_url'] ?? '');
            if (str_starts_with($mu, 'https://')) {
                $mediaUrls[] = $mu;
            }
        }
        ap_events_refresh_create_from_update(
            $noteId,
            $content !== '' ? $content : ($mediaUrls ? '(attachment)' : ''),
            $mediaUrls ?: null,
            is_string($inReplyTo) ? $inReplyTo : null,
            $spoilerText,
            $isSensitive,
            ap_normalize_visibility((string) ($row['visibility'] ?? 'public'))
        );
    }
    if (function_exists('admin_tl_cache_clear')) {
        admin_tl_cache_clear();
    }

    // Same Bridgy-first / background path as Creates — edits with media must not jam.
    $fan = ap_deliver_public_activity($update, []);
    $delivered = $fan['delivered'];
    ap_metrics_record('Update', $actor, $noteId, null, strlen($contentHtml), 'compose_edit', $content);
    ap_log("update_status note=$noteId local_id=$localId delivered=$delivered queued={$fan['queued']} bridgy=" . ($fan['bridgy'] ? '1' : '0'));

    return ['ok' => true, 'local_id' => $localId, 'delivered' => $delivered, 'queued' => $fan['queued']];
}

/**
 * Apply a vote to a local poll (by option titles or indexes).
 *
 * @param list<int|string> $choices indexes or option titles
 */
function ap_masto_poll_vote(int $pollLocalId, string $voterActorId, array $choices): array
{
    if (!function_exists('ap_masto_poll_entity')) {
        require_once __DIR__ . '/ap-masto-entities.php';
    }
    $st = ap_db()->prepare('SELECT * FROM masto_polls WHERE local_id = ?');
    $st->execute([$pollLocalId]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'Poll not found'];
    }
    try {
        if ((new DateTimeImmutable((string) $row['expires_at'])) <= new DateTimeImmutable('now')) {
            return ['ok' => false, 'error' => 'Poll expired'];
        }
    } catch (Throwable $e) {
        // continue
    }
    $voterActorId = rtrim($voterActorId, '/');
    $voters = json_decode((string) ($row['voters_json'] ?? '[]'), true);
    if (!is_array($voters)) {
        $voters = [];
    }
    $votersNorm = array_map(static fn($v) => rtrim((string) $v, '/'), $voters);
    if (in_array($voterActorId, $votersNorm, true)) {
        return ['ok' => false, 'error' => 'Already voted'];
    }
    $options = json_decode((string) ($row['options_json'] ?? '[]'), true);
    if (!is_array($options) || !$options) {
        return ['ok' => false, 'error' => 'Invalid poll'];
    }
    $multiple = !empty($row['multiple']);
    $indexes = [];
    foreach ($choices as $c) {
        if (is_int($c) || (is_string($c) && ctype_digit($c))) {
            $indexes[] = (int) $c;
        } elseif (is_string($c)) {
            foreach ($options as $i => $opt) {
                if (($opt['title'] ?? '') === $c) {
                    $indexes[] = $i;
                }
            }
        }
    }
    $indexes = array_values(array_unique(array_filter($indexes, static fn($i) => $i >= 0 && $i < count($options))));
    if (!$indexes) {
        return ['ok' => false, 'error' => 'No valid choices'];
    }
    if (!$multiple) {
        $indexes = [$indexes[0]];
    }
    foreach ($indexes as $i) {
        $options[$i]['votes_count'] = (int) ($options[$i]['votes_count'] ?? 0) + 1;
    }
    $voters[] = $voterActorId;
    $votesCount = 0;
    foreach ($options as $opt) {
        $votesCount += (int) ($opt['votes_count'] ?? 0);
    }
    ap_db()->prepare(
        'UPDATE masto_polls SET options_json = ?, votes_count = ?, voters_count = ?, voters_json = ? WHERE local_id = ?'
    )->execute([
        json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $votesCount,
        count($voters),
        json_encode($voters, JSON_UNESCAPED_SLASHES),
        $pollLocalId,
    ]);
    $row = ap_db()->prepare('SELECT * FROM masto_polls WHERE local_id = ?');
    $row->execute([$pollLocalId]);
    $fresh = $row->fetch();
    return [
        'ok' => true,
        'poll' => is_array($fresh) ? ap_masto_poll_entity($fresh) : null,
        'own_votes' => $indexes,
    ];
}

/**
 * Delete a local public/compose status and federate Delete to followers.
 *
 * @return array{ok:bool,error?:string,note_id?:string,delivered?:int,queued?:int,status_row?:array}
 */
function ap_delete_local_status(int $localId): array
{
    $row = ap_masto_status_by_local_id($localId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Status not found'];
    }
    $noteId = (string) ($row['note_id'] ?? '');
    $actor = ap_outbound_identity()['id'];
    if ($noteId === '' || !str_starts_with($noteId, $actor . '/notes/')) {
        return ['ok' => false, 'error' => 'Refusing to delete non-local status'];
    }

    $deleteId = $actor . '/deletes/' . bin2hex(random_bytes(8));
    $published = gmdate('c');
    $to = ['https://www.w3.org/ns/activitystreams#Public'];
    $cc = [$actor . '/followers'];

    // Prefer original audience from outbox when present
    $ob = ap_db()->prepare('SELECT to_json, cc_json FROM outbox_notes WHERE id = ?');
    $ob->execute([$noteId]);
    $obRow = $ob->fetch();
    if (is_array($obRow)) {
        $toDec = json_decode((string) ($obRow['to_json'] ?? ''), true);
        $ccDec = json_decode((string) ($obRow['cc_json'] ?? ''), true);
        if (is_array($toDec) && $toDec) {
            $to = $toDec;
        }
        if (is_array($ccDec) && $ccDec) {
            $cc = $ccDec;
        }
    }

    $delete = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $deleteId,
        'type' => 'Delete',
        'actor' => $actor,
        'published' => $published,
        'to' => $to,
        'cc' => $cc,
        'object' => [
            'id' => $noteId,
            'type' => 'Tombstone',
            'formerType' => 'Note',
            'deleted' => $published,
        ],
    ];

    // Remove local copies first so UI/API stop showing the post even if delivery is slow
    ap_db()->prepare('DELETE FROM outbox_notes WHERE id = ?')->execute([$noteId]);
    ap_db()->prepare('DELETE FROM masto_pins WHERE status_local_id = ?')->execute([$localId]);
    try {
        ap_db()->prepare('DELETE FROM masto_polls WHERE status_local_id = ?')->execute([$localId]);
    } catch (Throwable $e) {
        // older installs
    }
    ap_db()->prepare('DELETE FROM masto_statuses WHERE local_id = ?')->execute([$localId]);
    ap_db()->prepare('UPDATE masto_media SET status_local_id = NULL WHERE status_local_id = ?')->execute([$localId]);
    try {
        ap_db()->prepare('DELETE FROM site_syndications WHERE note_id = ?')->execute([$noteId]);
    } catch (Throwable $e) {
        // table may be absent on older installs
    }
    if (function_exists('ap_events_mark_object_deleted')) {
        ap_events_mark_object_deleted($noteId);
    }
    if (function_exists('admin_tl_cache_clear')) {
        admin_tl_cache_clear();
    }

    $fan = ap_deliver_public_activity($delete, []);
    $delivered = $fan['delivered'];
    $queued = $fan['queued'];

    ap_metrics_record('Delete', $actor, $noteId, null, 0, 'compose_delete', null);
    ap_log("delete_status note=$noteId local_id=$localId delivered=$delivered queued=$queued bridgy=" . ($fan['bridgy'] ? '1' : '0'));

    return [
        'ok' => true,
        'note_id' => $noteId,
        'delivered' => $delivered,
        'queued' => $queued,
        'status_row' => $row,
    ];
}

/**
 * Delete an outbound/inbound DM we own locally. Outbound also federates Delete to the peer.
 *
 * @return array{ok:bool,error?:string,dm?:array,delivered?:int}
 */
function ap_delete_local_dm(int $dmId): array
{
    $dm = ap_dm_by_id($dmId);
    if (!$dm) {
        return ['ok' => false, 'error' => 'DM not found'];
    }
    $objectId = (string) ($dm['object_id'] ?? '');
    $peer = (string) ($dm['peer_actor_id'] ?? '');
    $direction = (string) ($dm['direction'] ?? '');

    // Always remove locally
    ap_db()->prepare('UPDATE direct_messages SET deleted_at = ? WHERE id = ?')
        ->execute([ap_db_now(), $dmId]);

    $delivered = 0;
    $ident = ap_outbound_identity();
    $actor = $ident['id'];
    // Only federate Delete for messages we authored
    if ($direction === 'out' && str_starts_with($objectId, $actor . '/notes/') && $peer !== '') {
        $doc = ap_fetch_actor_doc($peer);
        $inbox = is_array($doc)
            ? (ap_resolve_personal_inbox_from_actor_doc($doc) ?: ap_resolve_inbox_from_actor_doc($doc))
            : null;
        if ($inbox && str_starts_with($inbox, 'https://') && !ap_is_blocked_inbox($inbox)) {
            $deleteId = $actor . '/deletes/' . bin2hex(random_bytes(8));
            $published = gmdate('c');
            $delete = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $deleteId,
                'type' => 'Delete',
                'actor' => $actor,
                'published' => $published,
                'to' => [$peer],
                'cc' => [],
                'object' => [
                    'id' => $objectId,
                    'type' => 'Tombstone',
                    'formerType' => 'Note',
                    'deleted' => $published,
                ],
            ];
            if (ap_deliver_signed_json($inbox, $delete, $ident['key_id'], $ident['priv'], 8.0)) {
                $delivered = 1;
            }
        }
    }

    ap_log('delete_dm id=' . $dmId . ' dir=' . $direction . " delivered=$delivered");
    return ['ok' => true, 'dm' => $dm, 'delivered' => $delivered];
}

/**
 * Send a direct message (no Public audience). Stored only in direct_messages.
 *
 * @return array{ok:bool,error?:string,id?:int,note_id?:string,create_id?:string,delivered?:int,peer?:string}
 */
function ap_dm_send(string $content, string $toActorOrHandle, ?string $inReplyTo = null): array
{
    $content = trim(ap_fix_utf8($content));
    if ($content === '' || mb_strlen($content) > 2000) {
        return ['ok' => false, 'error' => 'DM text required (max 2000 chars)'];
    }
    $resolved = ap_resolve_actor_ref($toActorOrHandle);
    if (!$resolved) {
        return ['ok' => false, 'error' => 'Could not resolve recipient'];
    }
    $peer = ap_dm_peer_key($resolved);
    if (ap_is_blocked_actor($peer)) {
        return ['ok' => false, 'error' => 'Recipient is blocked'];
    }
    $actor = ap_local_actor_id();
    if (rtrim($peer, '/') === rtrim($actor, '/')) {
        return ['ok' => false, 'error' => 'Cannot DM yourself'];
    }
    $inReplyTo = $inReplyTo !== null ? trim($inReplyTo) : '';
    if ($inReplyTo !== '' && !str_starts_with($inReplyTo, 'https://')) {
        return ['ok' => false, 'error' => 'Invalid in_reply_to'];
    }

    // Light rate limit: max 30 outbound DMs / hour (per owner)
    $dmOwnerId = ap_db_default_owner_user_id();
    $since = gmdate('c', time() - 3600);
    $st = ap_db()->prepare(
        "SELECT COUNT(*) AS c FROM direct_messages
         WHERE owner_user_id = ? AND direction = 'out' AND created_at >= ?"
    );
    $st->execute([$dmOwnerId, $since]);
    if ((int) ($st->fetch()['c'] ?? 0) >= 30) {
        return ['ok' => false, 'error' => 'DM rate limit reached — try again later'];
    }

    $doc = ap_fetch_actor_doc($peer);
    if (!is_array($doc)) {
        return ['ok' => false, 'error' => 'Could not fetch recipient actor'];
    }
    $inbox = ap_resolve_personal_inbox_from_actor_doc($doc) ?: ap_resolve_inbox_from_actor_doc($doc);
    if (!$inbox || !str_starts_with($inbox, 'https://')) {
        return ['ok' => false, 'error' => 'Recipient has no inbox'];
    }
    if (ap_is_blocked_inbox($inbox)) {
        return ['ok' => false, 'error' => 'Recipient inbox is blocked'];
    }

    $contentHtml = ap_plain_text_to_html($content);
    $noteId = $actor . '/notes/' . bin2hex(random_bytes(8));
    $createId = $actor . '/creates/' . bin2hex(random_bytes(8));
    $published = gmdate('c');

    // DM addressing: only the peer — never Public / followers
    $to = [$peer];
    $cc = [];
    $tag = [[
        'type' => 'Mention',
        'href' => $peer,
        'name' => '@' . (parse_url($peer, PHP_URL_PATH) ? ltrim((string) basename((string) parse_url($peer, PHP_URL_PATH)), '@') : 'user'),
    ]];

    $note = [
        'id' => $noteId,
        'type' => 'Note',
        'published' => $published,
        'attributedTo' => $actor,
        'content' => $contentHtml,
        'to' => $to,
        'cc' => $cc,
        'tag' => $tag,
        'url' => $noteId,
    ];
    if ($inReplyTo !== '') {
        $note['inReplyTo'] = $inReplyTo;
    }

    $create = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $createId,
        'type' => 'Create',
        'actor' => $actor,
        'published' => $published,
        'to' => $to,
        'cc' => $cc,
        'object' => $note,
    ];

    $stored = ap_dm_store([
        'activity_id' => $createId,
        'object_id' => $noteId,
        'peer_actor_id' => $peer,
        'direction' => 'out',
        'content' => $content,
        'in_reply_to' => $inReplyTo !== '' ? $inReplyTo : null,
        'created_at' => $published,
        'read_at' => $published,
        'owner_user_id' => $dmOwnerId,
        'owner_actor_id' => function_exists('ap_db_owner_actor_id_for_user_id')
            ? ap_db_owner_actor_id_for_user_id($dmOwnerId)
            : $actor,
    ]);
    if (empty($stored['ok'])) {
        return ['ok' => false, 'error' => $stored['error'] ?? 'Could not store DM'];
    }

    $delivered = ap_deliver_signed_json($inbox, $create, ap_local_key_id(), ap_local_priv_path(), 8.0) ? 1 : 0;
    ap_log('dm_send peer=' . ap_short($peer) . " note=$noteId delivered=$delivered");

    return [
        'ok' => true,
        'id' => (int) ($stored['id'] ?? 0),
        'note_id' => $noteId,
        'create_id' => $createId,
        'delivered' => $delivered,
        'peer' => $peer,
    ];
}

/**
 * Fan out Person Update so remotes refresh @cmdr_nova (clears stale movedTo).
 *
 * @return array{ok:bool,delivered?:int,error?:string,update_id?:string,targets?:int}
 */
/**
 * Fan out ActivityPub Update(Person) for a local actor.
 * Defaults to the VAAK/OAuth session actor (not hard-coded cmdr_nova).
 *
 * @param bool $background Queue delivery in the detached worker instead of
 *                         waiting on remote inboxes (used by profile saves).
 * @return array{ok:bool,error?:string,delivered?:int,queued?:int,update_id?:string,targets?:int}
 */
function ap_publish_profile_update(?string $actorKey = null, bool $background = false): array
{
    $actorKey = strtolower(trim((string) $actorKey));
    $actorKey = preg_replace('/[^a-z0-9_]/', '', $actorKey) ?? '';
    if ($actorKey === '') {
        $req = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
        if (is_array($req) && !empty($req['key'])) {
            $actorKey = (string) $req['key'];
        } elseif (!empty($GLOBALS['vaak_actor_key'])) {
            $actorKey = strtolower(trim((string) $GLOBALS['vaak_actor_key']));
            $actorKey = preg_replace('/[^a-z0-9_]/', '', $actorKey) ?? '';
        }
    }
    if ($actorKey === '') {
        $actorKey = 'cmdr_nova';
    }

    $paths = function_exists('ap_actor_key_paths')
        ? ap_actor_key_paths($actorKey)
        : [
            'public' => '/etc/mkultra/ap-inbox/' . $actorKey . '_public.pem',
            'private' => '/etc/mkultra/ap-inbox/' . $actorKey . '_private.pem',
        ];
    $pem = is_file($paths['public']) ? file_get_contents($paths['public']) : false;
    if (!is_string($pem) || $pem === '') {
        return ['ok' => false, 'error' => 'public key unavailable for @' . $actorKey];
    }

    require_once __DIR__ . '/ap-import-export.php';
    $rich = ($actorKey === 'cmdr_nova');
    $actor = function_exists('ap_actor_as2_document')
        ? ap_actor_as2_document($actorKey, $pem, $rich)
        : ap_cmdr_actor_document($pem);
    // Respect intentional Move-out (movedTo on actor doc). Only strip when inactive.
    if (empty($actor['movedTo'])) {
        unset($actor['movedTo']);
    }

    $actorId = rtrim((string) ($actor['id'] ?? ('https://mkultra.monster/users/' . $actorKey)), '/');
    $prevReq = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
    if (function_exists('ap_request_actor_set')) {
        ap_request_actor_set($actorKey);
    }
    try {
        $ident = function_exists('ap_outbound_identity')
            ? ap_outbound_identity()
            : ['id' => $actorId, 'key_id' => $actorId . '#main-key', 'priv' => $paths['private']];
        $keyId = (string) ($ident['key_id'] ?? ($actorId . '#main-key'));
        $priv = (string) ($ident['priv'] ?? $paths['private']);

        $updateId = $actorId . '/updates/' . bin2hex(random_bytes(8));
        $published = gmdate('c');
        $update = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
            ],
            'id' => $updateId,
            'type' => 'Update',
            'actor' => $actorId,
            'published' => $published,
            'to' => [
                'https://www.w3.org/ns/activitystreams#Public',
                $actorId . '/followers',
            ],
            'object' => $actor,
        ];

        $targets = ap_delivery_targets_filtered(
            ap_followers_list($actorId),
            $actorKey === 'cmdr_nova' ? ap_known_shared_inboxes(40) : []
        );

        // Profile saves should not hold the browser open while every remote
        // inbox responds. The detached worker signs and delivers the exact
        // same Update after the local profile has already been persisted.
        if ($background && $targets !== [] && function_exists('ap_deliver_fanout_background')) {
            $queued = ap_deliver_fanout_background($update, $targets, $keyId, $priv);
            ap_metrics_record(
                'Update',
                $actorId,
                $updateId,
                null,
                strlen(json_encode($actor) ?: ''),
                'profile_update',
                $actor['name'] ?? $actorKey
            );
            ap_log("profile_update actor=$actorKey id=$updateId background=" . ($queued ? '1' : '0') . ' targets=' . count($targets));
            return [
                'ok' => true,
                'delivered' => 0,
                'queued' => $queued ? count($targets) : 0,
                'update_id' => $updateId,
                'targets' => count($targets),
            ];
        }

        $delivered = 0;
        $n = 0;
        foreach ($targets as $inbox) {
            if ($n >= 50) {
                break;
            }
            if (ap_deliver_signed_json($inbox, $update, $keyId, $priv)) {
                $delivered++;
            }
            $n++;
        }

        ap_metrics_record(
            'Update',
            $actorId,
            $updateId,
            null,
            strlen(json_encode($actor) ?: ''),
            'profile_update',
            $actor['name'] ?? $actorKey
        );
        ap_log("profile_update actor=$actorKey id=$updateId delivered=$delivered targets=" . count($targets));

        return [
            'ok' => true,
            'delivered' => $delivered,
            'update_id' => $updateId,
            'targets' => count($targets),
        ];
    } finally {
        if (function_exists('ap_request_actor_set')) {
            if (is_array($prevReq) && !empty($prevReq['key'])) {
                ap_request_actor_set((string) $prevReq['key']);
            } elseif (!empty($GLOBALS['vaak_actor_key'])) {
                ap_request_actor_set((string) $GLOBALS['vaak_actor_key']);
            } else {
                ap_request_actor_set(null);
            }
        }
    }
}

/** @deprecated Use ap_publish_profile_update() — kept for call sites. */
function ap_cmdr_publish_profile_update(?string $actorKey = null): array
{
    return ap_publish_profile_update($actorKey);
}

/**
 * Best-effort AS2 Move activity (we are the old account → target is new).
 *
 * @return array{ok:bool,delivered?:int,error?:string}
 */
function ap_send_move_activity(string $targetActorId, ?string $actorKey = null): array
{
    $targetActorId = rtrim(trim($targetActorId), '/');
    if ($targetActorId === '' || !str_starts_with($targetActorId, 'https://')) {
        return ['ok' => false, 'error' => 'Invalid move target'];
    }
    $actorKey = strtolower(trim((string) $actorKey));
    $actorKey = preg_replace('/[^a-z0-9_]/', '', $actorKey) ?? '';
    if ($actorKey === '') {
        $req = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
        if (is_array($req) && !empty($req['key'])) {
            $actorKey = (string) $req['key'];
        } elseif (!empty($GLOBALS['vaak_actor_key'])) {
            $actorKey = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string) $GLOBALS['vaak_actor_key']))) ?? '';
        }
    }
    if ($actorKey === '') {
        $actorKey = 'cmdr_nova';
    }
    $actorId = 'https://mkultra.monster/users/' . $actorKey;
    $prevReq = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
    if (function_exists('ap_request_actor_set')) {
        ap_request_actor_set($actorKey);
    }
    try {
        $ident = function_exists('ap_outbound_identity')
            ? ap_outbound_identity()
            : [
                'id' => $actorId,
                'key_id' => $actorId . '#main-key',
                'priv' => '/etc/mkultra/ap-inbox/' . $actorKey . '_private.pem',
            ];
        $keyId = (string) ($ident['key_id'] ?? ($actorId . '#main-key'));
        $priv = (string) ($ident['priv'] ?? '');
        $moveId = $actorId . '/moves/' . bin2hex(random_bytes(8));
        $move = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $moveId,
            'type' => 'Move',
            'actor' => $actorId,
            'object' => $actorId,
            'target' => $targetActorId,
            'to' => [
                'https://www.w3.org/ns/activitystreams#Public',
                $actorId . '/followers',
            ],
        ];
        $targets = ap_delivery_targets_filtered(ap_followers_list($actorId), []);
        $delivered = 0;
        $n = 0;
        foreach ($targets as $inbox) {
            if ($n >= 50) {
                break;
            }
            if (ap_deliver_signed_json($inbox, $move, $keyId, $priv)) {
                $delivered++;
            }
            $n++;
        }
        ap_metrics_record('Move', $actorId, $moveId, $targetActorId, strlen(json_encode($move) ?: ''), 'local_move_out', $targetActorId);
        ap_log("move_out actor=$actorKey id=$moveId target=$targetActorId delivered=$delivered");
        return ['ok' => true, 'delivered' => $delivered];
    } finally {
        if (function_exists('ap_request_actor_set')) {
            if (is_array($prevReq) && !empty($prevReq['key'])) {
                ap_request_actor_set((string) $prevReq['key']);
            } elseif (!empty($GLOBALS['vaak_actor_key'])) {
                ap_request_actor_set((string) $GLOBALS['vaak_actor_key']);
            } else {
                ap_request_actor_set(null);
            }
        }
    }
}

/** @deprecated Use ap_send_move_activity() */
function ap_cmdr_send_move_activity(string $targetActorId, ?string $actorKey = null): array
{
    return ap_send_move_activity($targetActorId, $actorKey);
}

/**
 * Resolve personal + shared inboxes for a remote actor (Like / Undo targets).
 *
 * @return list<string>
 */
function ap_actor_delivery_inboxes(string $actorId): array
{
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
        return [];
    }
    if (ap_is_blocked_actor($actorId)) {
        return [];
    }
    $doc = ap_fetch_actor_doc($actorId);
    if (!is_array($doc)) {
        return [];
    }
    $out = [];
    $personal = ap_resolve_personal_inbox_from_actor_doc($doc);
    $shared = null;
    if (isset($doc['endpoints']['sharedInbox']) && is_string($doc['endpoints']['sharedInbox'])) {
        $shared = $doc['endpoints']['sharedInbox'];
    }
    foreach ([$personal, $shared] as $inbox) {
        if (!is_string($inbox) || !str_starts_with($inbox, 'https://')) {
            continue;
        }
        if (ap_is_blocked_inbox($inbox)) {
            continue;
        }
        $out[$inbox] = true;
    }
    return array_keys($out);
}

/**
 * Federate a Like of a remote Note/object. Returns like activity id on success.
 *
 * @return array{ok:bool,like_id?:string,delivered?:int,error?:string}
 */
function ap_cmdr_send_like(string $objectId, string $targetActor): array
{
    $objectId = trim($objectId);
    $targetActor = rtrim(trim($targetActor), '/');
    if ($objectId === '' || !str_starts_with($objectId, 'https://')) {
        return ['ok' => false, 'error' => 'invalid object'];
    }
    if ($targetActor === '' || !str_starts_with($targetActor, 'https://')) {
        return ['ok' => false, 'error' => 'invalid target actor'];
    }
    $ident = ap_outbound_identity();
    $actor = $ident['id'];
    // Own local notes: store-only (no self-notify). Peer local notes: deliver to their inbox.
    if (str_starts_with(rtrim($objectId, '/'), rtrim($actor, '/') . '/')) {
        return ['ok' => true, 'like_id' => null, 'delivered' => 0];
    }
    if (ap_is_blocked_actor($targetActor)) {
        return ['ok' => false, 'error' => 'blocked'];
    }

    $likeId = $actor . '/likes/' . bin2hex(random_bytes(16));
    $activity = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $likeId,
        'type' => 'Like',
        'actor' => $actor,
        'object' => $objectId,
        'to' => [$targetActor],
        'cc' => ['https://www.w3.org/ns/activitystreams#Public'],
        'published' => gmdate('c'),
    ];
    // Same-instance peer: personal inbox URL is deterministic
    $inboxes = [];
    if (preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $targetActor)) {
        $inboxes = [$targetActor . '/inbox'];
    } else {
        $inboxes = ap_actor_delivery_inboxes($targetActor);
    }
    if (!$inboxes) {
        return ['ok' => false, 'error' => 'no inbox', 'like_id' => $likeId];
    }
    $delivered = 0;
    foreach ($inboxes as $inbox) {
        if (ap_deliver_signed_json($inbox, $activity, $ident['key_id'], $ident['priv'], 8.0)) {
            $delivered++;
        }
    }
    ap_metrics_record('Like', $actor, $objectId, $targetActor, strlen(json_encode($activity) ?: ''), $delivered > 0 ? 'like_ok' : 'like_fail', null);
    return [
        'ok' => $delivered > 0,
        'like_id' => $likeId,
        'delivered' => $delivered,
        'error' => $delivered > 0 ? null : 'delivery failed',
    ];
}

/**
 * Undo a previously sent Like.
 *
 * @return array{ok:bool,delivered?:int,error?:string}
 */
function ap_cmdr_send_undo_like(string $likeActivityId, string $objectId, string $targetActor): array
{
    $likeActivityId = trim($likeActivityId);
    $objectId = trim($objectId);
    $targetActor = rtrim(trim($targetActor), '/');
    if ($likeActivityId === '' || !str_starts_with($likeActivityId, 'https://')) {
        return ['ok' => false, 'error' => 'invalid like id'];
    }
    if ($targetActor === '' || !str_starts_with($targetActor, 'https://')) {
        return ['ok' => false, 'error' => 'invalid target'];
    }
    $ident = ap_outbound_identity();
    $actor = $ident['id'];
    $undoId = $actor . '/undos/' . bin2hex(random_bytes(12));
    $activity = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $undoId,
        'type' => 'Undo',
        'actor' => $actor,
        'object' => [
            'id' => $likeActivityId,
            'type' => 'Like',
            'actor' => $actor,
            'object' => $objectId,
        ],
        'to' => [$targetActor],
        'published' => gmdate('c'),
    ];
    $inboxes = ap_actor_delivery_inboxes($targetActor);
    $delivered = 0;
    foreach ($inboxes as $inbox) {
        if (ap_deliver_signed_json($inbox, $activity, $ident['key_id'], $ident['priv'], 8.0)) {
            $delivered++;
        }
    }
    ap_metrics_record('Undo', $actor, $likeActivityId, $targetActor, strlen(json_encode($activity) ?: ''), $delivered > 0 ? 'unlike_ok' : 'unlike_fail', null);
    return ['ok' => $delivered > 0, 'delivered' => $delivered, 'error' => $delivered > 0 ? null : 'delivery failed'];
}

/**
 * Federate an Announce (boost) of a remote/public object to followers (+ Bridgy).
 *
 * @return array{ok:bool,announce_id?:string,delivered?:int,queued?:int,error?:string}
 */
function ap_cmdr_send_announce(string $objectId, ?string $targetActor = null): array
{
    $objectId = trim($objectId);
    if ($objectId === '' || !str_starts_with($objectId, 'https://')) {
        return ['ok' => false, 'error' => 'invalid object'];
    }
    $objectId = preg_replace('/#announce-\d+$/', '', $objectId) ?? $objectId;
    $ident = ap_outbound_identity();
    $actor = $ident['id'];
    $announceId = $actor . '/announces/' . bin2hex(random_bytes(16));
    $published = gmdate('c');
    $to = ['https://www.w3.org/ns/activitystreams#Public'];
    $cc = [$actor . '/followers'];
    if (is_string($targetActor) && str_starts_with($targetActor, 'https://') && !ap_is_blocked_actor($targetActor)) {
        $cc[] = rtrim($targetActor, '/');
    }
    $activity = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $announceId,
        'type' => 'Announce',
        'actor' => $actor,
        'published' => $published,
        'to' => $to,
        'cc' => array_values(array_unique($cc)),
        'object' => $objectId,
    ];
    $activity = ap_as2_ensure_top_context($activity);

    $priorityExtra = [];
    if (is_string($targetActor) && str_starts_with($targetActor, 'https://')) {
        foreach (ap_actor_delivery_inboxes($targetActor) as $inbox) {
            $priorityExtra[] = $inbox;
        }
    }
    $fan = ap_deliver_public_activity($activity, $priorityExtra);
    $delivered = $fan['delivered'];
    $queued = $fan['queued'];
    ap_metrics_record('Announce', $actor, $objectId, $targetActor, strlen(json_encode($activity) ?: ''), 'boost_ok', null);
    ap_log("announce id=$announceId object=$objectId delivered=$delivered queued=$queued bridgy=" . ($fan['bridgy'] ? '1' : '0'));
    return [
        'ok' => true,
        'announce_id' => $announceId,
        'delivered' => $delivered,
        'queued' => $queued,
    ];
}

/**
 * Undo a previously sent Announce.
 *
 * @return array{ok:bool,delivered?:int,queued?:int,error?:string}
 */
function ap_cmdr_send_undo_announce(string $announceActivityId, string $objectId, ?string $targetActor = null): array
{
    $announceActivityId = trim($announceActivityId);
    $objectId = trim($objectId);
    if ($announceActivityId === '' || !str_starts_with($announceActivityId, 'https://')) {
        return ['ok' => false, 'error' => 'invalid announce id'];
    }
    $ident = ap_outbound_identity();
    $actor = $ident['id'];
    $undoId = $actor . '/undos/' . bin2hex(random_bytes(12));
    $to = ['https://www.w3.org/ns/activitystreams#Public'];
    $cc = [$actor . '/followers'];
    if (is_string($targetActor) && str_starts_with($targetActor, 'https://')) {
        $cc[] = rtrim($targetActor, '/');
    }
    $activity = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $undoId,
        'type' => 'Undo',
        'actor' => $actor,
        'published' => gmdate('c'),
        'to' => $to,
        'cc' => array_values(array_unique($cc)),
        'object' => [
            'id' => $announceActivityId,
            'type' => 'Announce',
            'actor' => $actor,
            'object' => $objectId,
        ],
    ];
    $activity = ap_as2_ensure_top_context($activity);
    $priorityExtra = ap_bridgy_priority_inboxes();
    if (is_string($targetActor) && str_starts_with($targetActor, 'https://')) {
        foreach (ap_actor_delivery_inboxes($targetActor) as $inbox) {
            $priorityExtra[] = $inbox;
        }
    }
    $followers = ap_followers_list($actor);
    $priority = ap_delivery_targets_filtered($followers, $priorityExtra);
    $delivered = 0;
    foreach ($priority as $inbox) {
        if (ap_deliver_signed_json($inbox, $activity, $ident['key_id'], $ident['priv'], 3.0)) {
            $delivered++;
        }
    }
    ap_metrics_record('Undo', $actor, $announceActivityId, $targetActor, strlen(json_encode($activity) ?: ''), 'unboost_ok', null);
    return ['ok' => true, 'delivered' => $delivered];
}

/**
 * Kick a background outbox backfill so inbox Accept stays fast.
 */
function ap_outbox_backfill_async(string $actorId): void
{
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
        return;
    }
    $script = __DIR__ . '/ap-outbox-backfill.php';
    if (!is_file($script)) {
        return;
    }
    $cmd = 'php ' . escapeshellarg($script) . ' ' . escapeshellarg($actorId)
        . ' > /dev/null 2>&1 &';
    @exec($cmd);
}

/**
 * Fetch recent public Creates from a remote actor's outbox into events
 * so Home can show posts that arrived before (or without) inbox delivery.
 *
 * @return array{ok:bool,imported:int,skipped:int,error?:string}
 */
function ap_outbox_backfill_actor(string $actorId, int $limit = 12): array
{
    $actorId = rtrim(trim($actorId), '/');
    $limit = max(1, min(40, $limit));
    if ($actorId === '' || !str_starts_with($actorId, 'https://') || strlen($actorId) > 800) {
        return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'error' => 'bad_actor'];
    }
    if (str_contains($actorId, 'mkultra.monster/users/cmdr_nova')) {
        return ['ok' => true, 'imported' => 0, 'skipped' => 0];
    }
    // Defense in depth: never pull blocked actors into the firehose/Home
    if (function_exists('ap_is_blocked_actor') && ap_is_blocked_actor($actorId)) {
        return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'error' => 'blocked'];
    }
    $host = strtolower((string) (parse_url($actorId, PHP_URL_HOST) ?: ''));
    if ($host === '' || !ap_host_resolves_public($host)) {
        return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'error' => 'ssrf_host'];
    }

    $doc = ap_fetch_actor_doc($actorId);
    if (!is_array($doc)) {
        return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'error' => 'actor_fetch'];
    }
    $outbox = ap_as_id($doc['outbox'] ?? null);
    if ($outbox === null || !str_starts_with($outbox, 'https://')) {
        return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'error' => 'no_outbox'];
    }

    // ap_fetch_as2_object already does signed (authorized fetch) then unsigned
    $page = ap_fetch_as2_object($outbox);
    if (!is_array($page)) {
        return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'error' => 'outbox_fetch'];
    }

    $items = [];
    if (!empty($page['orderedItems']) && is_array($page['orderedItems'])) {
        $items = $page['orderedItems'];
    } elseif (!empty($page['items']) && is_array($page['items'])) {
        $items = $page['items'];
    } else {
        $first = $page['first'] ?? null;
        $firstUrl = is_string($first) ? $first : (is_array($first) ? ap_as_id($first) : null);
        if (is_string($firstUrl) && str_starts_with($firstUrl, 'https://')) {
            $page2 = ap_fetch_as2_object($firstUrl);
            if (is_array($page2)) {
                if (!empty($page2['orderedItems']) && is_array($page2['orderedItems'])) {
                    $items = $page2['orderedItems'];
                } elseif (!empty($page2['items']) && is_array($page2['items'])) {
                    $items = $page2['items'];
                }
            }
        }
    }

    $imported = 0;
    $skipped = 0;
    foreach ($items as $item) {
        if ($imported >= $limit) {
            break;
        }
        $activity = null;
        $note = null;
        if (is_string($item) && str_starts_with($item, 'https://')) {
            $fetched = ap_fetch_as2_object($item);
            if (!is_array($fetched)) {
                $skipped++;
                continue;
            }
            $t = (string) ($fetched['type'] ?? '');
            if ($t === 'Create' || $t === 'Announce') {
                $activity = $fetched;
            } elseif (in_array($t, ['Note', 'Article', 'Question', 'Page'], true)) {
                $note = $fetched;
            } else {
                $skipped++;
                continue;
            }
        } elseif (is_array($item)) {
            $t = (string) ($item['type'] ?? '');
            if ($t === 'Create' || $t === 'Announce') {
                $activity = $item;
            } elseif (in_array($t, ['Note', 'Article', 'Question', 'Page'], true)) {
                $note = $item;
            } else {
                $skipped++;
                continue;
            }
        } else {
            $skipped++;
            continue;
        }

        if ($activity !== null) {
            $type = (string) ($activity['type'] ?? 'Create');
            $objectId = ap_as_id($activity['object'] ?? null);
            $actActor = ap_as_id($activity['actor'] ?? null) ?: $actorId;
            // Only ingest this actor's own Creates/Announces (not boosted strangers into Home via backfill)
            if (rtrim((string) $actActor, '/') !== $actorId && $type === 'Create') {
                $skipped++;
                continue;
            }
            if ($type === 'Announce') {
                // Skip boosts in backfill — Home gets them live via inbox when relevant
                $skipped++;
                continue;
            }
            $enriched = ap_enrich_activity_for_feed($activity, $type, $objectId);
            $summary = $enriched['summary'] ?? null;
            $media = $enriched['media'] ?? [];
            $replyTo = $enriched['in_reply_to'] ?? null;
            if (($summary === null || $summary === '') && !$media) {
                $skipped++;
                continue;
            }
            $published = null;
            $obj = $activity['object'] ?? null;
            if (is_array($obj) && !empty($obj['published']) && is_string($obj['published'])) {
                $published = $obj['published'];
            } elseif (!empty($activity['published']) && is_string($activity['published'])) {
                $published = $activity['published'];
            }
            $before = ap_db()->prepare('SELECT id FROM events WHERE type = ? AND object_id = ? LIMIT 1');
            $before->execute(['Create', (string) $objectId]);
            if ($before->fetch()) {
                // Still backfill reply pointer if missing
                if (is_string($replyTo) && $replyTo !== '') {
                    ap_metrics_record(
                        'Create',
                        $actActor,
                        $objectId,
                        null,
                        0,
                        'local_observe',
                        null,
                        null,
                        null,
                        $replyTo
                    );
                }
                $skipped++;
                continue;
            }
            ap_metrics_record(
                'Create',
                $actActor,
                $objectId,
                null,
                0,
                'local_observe',
                is_string($summary) ? $summary : null,
                is_array($media) ? $media : null,
                $published,
                is_string($replyTo) ? $replyTo : null
            );
            $imported++;
            continue;
        }

        // Bare Note
        $objectId = ap_as_id($note) ?: null;
        if ($objectId === null) {
            $skipped++;
            continue;
        }
        $summary = null;
        if (!empty($note['content']) && is_string($note['content'])) {
            $summary = ap_fix_utf8($note['content']);
        } elseif (!empty($note['summary']) && is_string($note['summary']) && empty($note['sensitive'])) {
            // CW-only without content still useful as spoiler text preview
            $summary = ap_fix_utf8($note['summary']);
        }
        $media = function_exists('ap_extract_media_urls') ? ap_extract_media_urls($note) : [];
        $replyTo = ap_as_id($note['inReplyTo'] ?? null);
        if (is_string($replyTo) && str_starts_with($replyTo, 'https://')) {
            $replyTo = rtrim($replyTo, '/');
        } else {
            $replyTo = null;
        }
        if (($summary === null || $summary === '') && !$media) {
            $skipped++;
            continue;
        }
        $published = (!empty($note['published']) && is_string($note['published'])) ? $note['published'] : null;
        $before = ap_db()->prepare('SELECT id FROM events WHERE type = ? AND object_id = ? LIMIT 1');
        $before->execute(['Create', $objectId]);
        if ($before->fetch()) {
            if ($replyTo !== null) {
                ap_metrics_record(
                    'Create',
                    $actorId,
                    $objectId,
                    null,
                    0,
                    'local_observe',
                    null,
                    null,
                    null,
                    $replyTo
                );
            }
            $skipped++;
            continue;
        }
        ap_metrics_record(
            'Create',
            $actorId,
            $objectId,
            null,
            0,
            'local_observe',
            $summary,
            $media,
            $published,
            $replyTo
        );
        $imported++;
    }

    return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped];
}
