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
const AP_INBOX_DATE_SKEW = 3600;           // ±1 hour, same clock-skew margin Mastodon uses
const AP_INBOX_RATE_MAX = 600;             // requests / window / IP (normal peers; 120 starved active instances)
/** Accepted ActivityPub relays forward a firehose — low caps cause 429 retry-storms. */
const AP_INBOX_RATE_MAX_RELAY = 12000;     // requests / window / IP for accepted relay hosts
const AP_INBOX_RATE_WINDOW = 3600;         // seconds
const AP_INBOX_KEY_CACHE_TTL = 3600;
/** How long to remember "this keyId is not fetchable" (Threads dead actors, etc.). */
const AP_INBOX_KEY_NEG_CACHE_TTL = 900; // 15 minutes
const AP_INBOX_KEY_FETCH_TIMEOUT = 8;
/**
 * Remote fetches an inbox POST may cause before its signature is verified.
 * Key fetches are budgeted per key URL and per key host; origin recovery of
 * an unverified body is budgeted per claimed actor host. Over budget → 429.
 */
const AP_INBOX_FETCH_BUDGET_WINDOW = 600;  // seconds
const AP_INBOX_KEY_FETCH_MAX_PER_KEY = 6;  // a miss plus a rotation refetch, a few times over
const AP_INBOX_KEY_FETCH_MAX_PER_HOST = 240;
const AP_INBOX_ORIGIN_FETCH_MAX_PER_HOST = 240;
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
require_once __DIR__ . '/ap-asks.php';
require_once __DIR__ . '/ap-fanout-host.php';

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
// The keyId is only a claim until it verifies: relay-keyed requests draw on a
// separate relay bucket, are refused once this IP's normal bucket is spent,
// and are charged to the normal bucket when the signature does not verify.
$sigHdrEarly = ap_header('Signature');
$claimsRelay = false;
if ($sigHdrEarly !== '' && preg_match('/keyId="([^"]+)"/', $sigHdrEarly, $km)) {
    if (!function_exists('ap_relay_is_accepted_key')) {
        require_once __DIR__ . '/ap-relays.php';
    }
    if (function_exists('ap_relay_is_accepted_key') && ap_relay_is_accepted_key($km[1])) {
        $claimsRelay = true;
    }
}
if (!ap_inbox_rate_admit($ip, $claimsRelay)) {
    ap_fail('Too many requests', 429);
}

$raw = file_get_contents('php://input', false, null, 0, AP_INBOX_MAX_BODY + 1);
$GLOBALS['ap_inbox_body_len'] = is_string($raw) ? strlen($raw) : 0;
if ($raw === false) {
    ap_fail('Empty body', 400);
}
if (strlen($raw) > AP_INBOX_MAX_BODY) {
    ap_fail('Body too large', 413);
}
if ($raw === '') {
    ap_fail('Empty body', 400);
}

$selected = ap_inbox_select($raw);
if ($claimsRelay && ($selected === null || $selected['via'] !== 'signature')) {
    ap_rate_limit($ip, AP_INBOX_RATE_MAX);
}
if ($selected === null) {
    $verifyCode = (int) ($GLOBALS['ap_inbox_verify_code'] ?? 401);
    $verifyError = (string) ($GLOBALS['ap_inbox_verify_error'] ?? 'Invalid signature');
    ap_fail($verifyError, in_array($verifyCode, [403, 429], true) ? $verifyCode : 401);
}
$json = $selected['activity'];
$sig = $selected['sig'];
$viaOrigin = $selected['via'] === 'origin';
if ($viaOrigin) {
    $type = 'unknown';
    if (isset($json['type']) && is_string($json['type'])) {
        $type = preg_replace('/[^A-Za-z0-9._-]/', '', $json['type']) ?: 'unknown';
    }
    ap_log(sprintf(
        'origin_accept ip=%s type=%s actor=%s',
        $ip,
        $type,
        ap_short((string) ($sig['keyId'] ?? ''))
    ));
} elseif (($selected['path'] ?? '/inbox') !== '/inbox') {
    ap_log('sig_ok path=' . $selected['path'] . ' keyId=' . ap_short((string) ($sig['keyId'] ?? '')));
}

// Signature path decodes the posted body. Origin recovery already replaced it
// with the document fetched from the actor's host.
if (!$viaOrigin) {
    $type = 'unknown';
    $json = json_decode($raw, true);
    if (is_array($json) && isset($json['type']) && is_string($json['type'])) {
        $type = preg_replace('/[^A-Za-z0-9._-]/', '', $json['type']) ?: 'unknown';
    }
}

// Defense in depth (post-verify): signing key host still blocked → hard reject.
$keyHost = parse_url($sig['keyId'], PHP_URL_HOST);
if (is_string($keyHost) && ap_is_blocked_host(strtolower($keyHost))) {
    ap_log(sprintf(
        'blocked_reject_keyhost_postverify ip=%s type=%s keyId=%s',
        $ip,
        $type,
        ap_short($sig['keyId'])
    ));
    ap_fail('Instance blocked', 403);
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
    // Origin recovery already required actor/attributedTo to match the claimed
    // actor. There is no verified keyId to compare.
    if (!$viaOrigin && $claimedActor !== null && !ap_inbox_actor_matches_key($sig['keyId'], $claimedActor)) {
        // 1) Accepted relays re-sign the firehose as themselves.
        // 2) Followed actors forward reply Creates (Mastodon inbox forwarding)
        //    signed as the followee while activity.actor is the reply author.
        if (!function_exists('ap_relay_is_accepted_key')) {
            require_once __DIR__ . '/ap-relays.php';
        }
        // Forwarding only ever carries someone else's Create or Announce. Delete,
        // Update, Undo, Follow and the rest must come from the actor's own key,
        // or they fall through to origin recovery below.
        $forwardable = in_array($type, ['Create', 'Announce'], true);
        $relayOk = $forwardable && function_exists('ap_relay_is_accepted_key') && ap_relay_is_accepted_key($sig['keyId']);
        $followFwd = $forwardable && !$relayOk && ap_inbox_signer_is_followed($sig['keyId']);
        if ($relayOk || $followFwd) {
            ap_log(sprintf(
                '%s type=%s keyId=%s actor=%s',
                $relayOk ? 'relay_forward' : 'follow_forward',
                $type,
                ap_short($sig['keyId']),
                ap_short($claimedActor)
            ));
            // The forwarder vouches for delivery, not for the post. Store the
            // copy the author's server serves, or nothing.
            if ($type === 'Create') {
                $refetched = ap_inbox_refetch_forwarded_create($json);
                if ($refetched === null) {
                    ap_log(sprintf(
                        'forward_refetch_drop keyId=%s actor=%s object=%s',
                        ap_short($sig['keyId']),
                        ap_short($claimedActor),
                        ap_short((string) (ap_as_id($json['object'] ?? null) ?? ''))
                    ));
                    http_response_code(202);
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'accepted', 'action' => 'forward_dropped'], JSON_UNESCAPED_SLASHES);
                    exit;
                }
                $json = $refetched;
            }
        } else {
            // The HTTP signature proves who delivered the POST, not that they
            // are the activity actor. Mastodon inbox-forwarding (and relays
            // we do not explicitly trust) re-sign someone else's Create.
            // Do not accept that body. Fetch the claimed actor's copy.
            $keyHostMismatch = parse_url($sig['keyId'], PHP_URL_HOST);
            $actorHostMismatch = parse_url($claimedActor, PHP_URL_HOST);
            $GLOBALS['ap_inbox_reject_detail'] = 'key_host=' . (is_string($keyHostMismatch) ? $keyHostMismatch : '')
                . ' actor_host=' . (is_string($actorHostMismatch) ? $actorHostMismatch : '');
            $recovered = ap_inbox_recover_unverified($json);
            if ($recovered === null) {
                ap_log(sprintf(
                    'actor_key_mismatch type=%s keyId=%s actor=%s',
                    $type,
                    ap_short($sig['keyId']),
                    ap_short($claimedActor)
                ));
                ap_fail('Signature key does not match activity actor', 401);
            }
            $json = $recovered;
            $viaOrigin = true;
            if (isset($json['type']) && is_string($json['type'])) {
                $type = preg_replace('/[^A-Za-z0-9._-]/', '', $json['type']) ?: 'unknown';
            }
            ap_log(sprintf(
                'origin_accept reason=actor_mismatch type=%s keyId=%s actor=%s',
                $type,
                ap_short($sig['keyId']),
                ap_short($claimedActor)
            ));
        }
    }
    if (is_string($keyHost) && $keyHost !== '' && function_exists('ap_inbox_host_seen_note')) {
        ap_inbox_host_seen_note(strtolower($keyHost), $type);
    }
    if (is_string($keyHost) && $keyHost !== '' && function_exists('ap_fanout_host_note_inbound')) {
        ap_fanout_host_note_inbound(strtolower($keyHost));
    }
    $action = ap_route_verified_activity($json, strlen($raw));
}

http_response_code(202);
header('Content-Type: application/json');
echo json_encode(['status' => 'accepted', 'action' => $action], JSON_UNESCAPED_SLASHES);
exit;
} // ap_inbox_main

/* ----------------- helpers ----------------- */

/**
 * Pre-verification per-IP admission. A relay keyId uses the relay bucket but
 * still needs room in the normal bucket, which failed relay-keyed requests
 * are charged to after verification.
 */
function ap_inbox_rate_admit(string $ip, bool $claimsRelay): bool
{
    if (!$claimsRelay) {
        return ap_rate_limit($ip, AP_INBOX_RATE_MAX);
    }
    return ap_rate_limit($ip, AP_INBOX_RATE_MAX, AP_INBOX_RATE_WINDOW, false)
        && ap_rate_limit('relay_' . $ip, AP_INBOX_RATE_MAX_RELAY);
}

function ap_fail(string $message, int $code): void
{
    $detail = (string) ($GLOBALS['ap_inbox_reject_detail'] ?? '');
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? ap_header('Host'));
    $ua = ap_header('User-Agent');
    $safe = static function (string $value, int $max = 80): string {
        $value = preg_replace('/[^A-Za-z0-9 ._:\/~?&=+-]+/', '_', $value) ?? '';
        return strlen($value) <= $max ? $value : substr($value, 0, $max);
    };
    // php-fpm stderr (journal). The file log is not always writable.
    error_log(sprintf(
        'ap-inbox reject code=%d reason=%s bytes=%d host=%s uri=%s digest=%d content_digest=%d signature=%d signature_input=%d content_type=%d detail=%s ua=%s',
        $code,
        $safe($message, 120),
        (int) ($GLOBALS['ap_inbox_body_len'] ?? 0),
        $safe($host, 80),
        $safe($uri, 80),
        ap_header('Digest') !== '' ? 1 : 0,
        ap_header('Content-Digest') !== '' ? 1 : 0,
        ap_header('Signature') !== '' ? 1 : 0,
        ap_header('Signature-Input') !== '' ? 1 : 0,
        ap_header('Content-Type') !== '' ? 1 : 0,
        $safe($detail, 160),
        $safe($ua, 80)
    ));
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
            if (strtolower((string) $k) === $want && is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
    }
    // php-fpm exposes these as CONTENT_* rather than HTTP_*.
    if ($want === 'content-type') {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (is_string($ct) && trim($ct) !== '') {
            return trim($ct);
        }
    }
    if ($want === 'content-length') {
        $cl = $_SERVER['CONTENT_LENGTH'] ?? '';
        if (is_string($cl) && trim($cl) !== '') {
            return trim($cl);
        }
    }
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$key] ?? ''));
}

/**
 * Client address for rate limits and logs.
 *
 * X-Forwarded-For is only read when the direct peer (REMOTE_ADDR) is a
 * trusted proxy: loopback by default, or the comma-separated addresses and
 * CIDR ranges in VAAK_TRUSTED_PROXIES. The header is then walked from the
 * right and the first address that is not itself a trusted proxy wins, so
 * entries the client wrote ahead of our proxy's own append are ignored.
 */
function ap_client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $remote = is_string($remote) ? trim($remote) : '';
    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        return '0.0.0.0';
    }
    if (!ap_client_ip_is_trusted_proxy($remote)) {
        return $remote;
    }
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (!is_string($xff) || trim($xff) === '') {
        return $remote;
    }
    $client = $remote;
    foreach (array_reverse(explode(',', $xff)) as $hop) {
        $hop = trim($hop);
        if (!filter_var($hop, FILTER_VALIDATE_IP)) {
            break;
        }
        $client = $hop;
        if (!ap_client_ip_is_trusted_proxy($hop)) {
            break;
        }
    }
    return $client;
}

/** @return list<string> */
function ap_client_ip_trusted_proxies(): array
{
    $raw = getenv('VAAK_TRUSTED_PROXIES');
    if ($raw === false || trim((string) $raw) === '') {
        $raw = $_SERVER['VAAK_TRUSTED_PROXIES'] ?? '';
    }
    $raw = trim((string) $raw);
    if ($raw === '') {
        return ['127.0.0.0/8', '::1/128', '::ffff:127.0.0.0/104'];
    }
    $out = [];
    foreach (preg_split('/[\s,]+/', $raw) ?: [] as $entry) {
        if ($entry !== '') {
            $out[] = $entry;
        }
    }
    return $out;
}

function ap_client_ip_is_trusted_proxy(string $ip): bool
{
    foreach (ap_client_ip_trusted_proxies() as $cidr) {
        if (ap_client_ip_in_cidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

function ap_client_ip_in_cidr(string $ip, string $cidr): bool
{
    $net = $cidr;
    $bits = null;
    if (str_contains($cidr, '/')) {
        [$net, $len] = explode('/', $cidr, 2);
        if (!preg_match('/^[0-9]{1,3}$/', $len)) {
            return false;
        }
        $bits = (int) $len;
    }
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }
    $max = strlen($ipBin) * 8;
    $bits = $bits === null ? $max : $bits;
    if ($bits > $max) {
        return false;
    }
    $whole = intdiv($bits, 8);
    if (substr($ipBin, 0, $whole) !== substr($netBin, 0, $whole)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xff << (8 - $rest)) & 0xff;
    return (ord($ipBin[$whole]) & $mask) === (ord($netBin[$whole]) & $mask);
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
 * @return array{keyId:string,algorithm:?string,headers:string,signature:string,created:?string,expires:?string}|null
 */
function ap_parse_signature(string $hdr): ?array
{
    $hdr = trim($hdr);
    if (str_starts_with($hdr, 'Signature ')) {
        $hdr = trim(substr($hdr, strlen('Signature ')));
    }
    $out = [
        'keyId' => null,
        'algorithm' => null,
        'headers' => null,
        'signature' => null,
        'created' => null,
        'expires' => null,
    ];
    $aliases = [
        'keyid' => 'keyId',
        'algorithm' => 'algorithm',
        'headers' => 'headers',
        'signature' => 'signature',
        'created' => 'created',
        'expires' => 'expires',
    ];
    // keyId="...",algorithm="rsa-sha256",headers="...",signature="...",created=123
    // Whitespace around "=" is legal. Unquoted tokens stop at the next comma.
    if (!preg_match_all('/([a-zA-Z]+)\s*=\s*(?:"([^"]*)"|([^,\s]*))/', $hdr, $matches, PREG_SET_ORDER)) {
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
    /** @var array{keyId:string,algorithm:?string,headers:string,signature:string,created:?string,expires:?string} $out */
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
        $query = '';
        $qpos = strpos($p, '?');
        if ($qpos !== false) {
            $query = substr($p, $qpos);
            $p = substr($p, 0, $qpos);
        }
        // Ignore rewritten PHP script path
        if ($p === '' || str_contains($p, '.php')) {
            return;
        }
        $variants = [$p];
        if ($p !== '/') {
            $variants[] = str_ends_with($p, '/') ? rtrim($p, '/') : ($p . '/');
        }
        foreach ($variants as $variant) {
            if ($variant === '') {
                continue;
            }
            if (!in_array($variant, $paths, true)) {
                $paths[] = $variant;
            }
            if ($query !== '') {
                $withQuery = $variant . $query;
                if (!in_array($withQuery, $paths, true)) {
                    $paths[] = $withQuery;
                }
            }
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

    // Always try shared + personal inboxes our Caddy routes expose.
    // Mastodon signs the path it was given, including a trailing slash.
    $add('/inbox');
    $add('/inbox/');
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

/**
 * @param array{host?:string,created?:?string,expires?:?string,header_overrides?:array<string,string>} $sigParams
 */
function ap_signing_string_for_path(string $headersList, string $requestPath, array $sigParams = []): ?string
{
    $parts = [];
    $method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'post');
    $overrides = is_array($sigParams['header_overrides'] ?? null) ? $sigParams['header_overrides'] : [];
    foreach (preg_split('/\s+/', trim($headersList)) ?: [] as $h) {
        $h = strtolower($h);
        if ($h === '(request-target)') {
            $parts[] = '(request-target): ' . $method . ' ' . $requestPath;
            continue;
        }
        if ($h === '(created)') {
            $val = (string) ($sigParams['created'] ?? '');
        } elseif ($h === '(expires)') {
            $val = (string) ($sigParams['expires'] ?? '');
        } elseif (isset($overrides[$h]) && is_string($overrides[$h])) {
            $val = $overrides[$h];
        } elseif ($h === 'digest') {
            $val = ap_header('Digest');
        } elseif ($h === 'host') {
            $val = (string) ($sigParams['host'] ?? ($_SERVER['HTTP_HOST'] ?? 'mkultra.monster'));
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
 * Host values that may have been signed. Caddy/Cloudflare can present
 * `host:443` or an internal host while Mastodon signed the public name.
 *
 * @return list<string>
 */
function ap_inbox_host_candidates(): array
{
    $out = [];
    $add = static function (string $raw) use (&$out): void {
        $raw = trim(explode(',', $raw)[0] ?? '');
        if ($raw === '' || str_contains($raw, ' ') || str_contains($raw, '/')) {
            return;
        }
        $forms = [$raw];
        if (!str_starts_with($raw, '[')) {
            $colon = strrpos($raw, ':');
            $stripped = $raw;
            if ($colon !== false && ($raw !== '' )) {
                $port = substr($raw, $colon + 1);
                if ($port === '443' || $port === '80') {
                    $stripped = substr($raw, 0, $colon);
                }
            }
            $forms[] = $stripped;
            $forms[] = strtolower($raw);
            $forms[] = strtolower($stripped);
        }
        foreach ($forms as $form) {
            if ($form !== '' && !in_array($form, $out, true)) {
                $out[] = $form;
            }
        }
    };
    $add(ap_header('Host'));
    $add((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $add((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    $add((string) ($_SERVER['SERVER_NAME'] ?? ''));
    if ($out === []) {
        $out[] = 'mkultra.monster';
    }
    return $out;
}

/**
 * Verify draft-cavage HTTP signatures (rsa-sha256 and hs2019).
 * Tries each plausible request-target and each host the proxy may have rewritten.
 *
 * @param array{created?:?string,expires?:?string} $sigParams
 * @return string|null matched path on success
 */
function ap_verify_http_signature(string $headersList, string $body, string $sigRaw, $pem, array $sigParams = []): ?string
{
    if ($sigRaw === '' || $pem === null || $pem === false) {
        return null;
    }
    $hosts = ap_inbox_host_candidates();
    $contentType = ap_header('Content-Type');
    $strippedType = trim((string) (preg_replace('/\s*;\s*charset\s*=\s*[^;]+/i', '', $contentType) ?? $contentType));
    foreach (ap_request_target_path_candidates() as $path) {
        foreach ($hosts as $host) {
            $params = $sigParams;
            $params['host'] = $host;
            $signingString = ap_signing_string_for_path($headersList, $path, $params);
            if ($signingString !== null && openssl_verify($signingString, $sigRaw, $pem, OPENSSL_ALGO_SHA256) === 1) {
                return $path;
            }
            if ($strippedType !== '' && $strippedType !== $contentType && str_contains($headersList, 'content-type')) {
                $params['header_overrides'] = ['content-type' => $strippedType];
                $signingString = ap_signing_string_for_path($headersList, $path, $params);
                if ($signingString !== null && openssl_verify($signingString, $sigRaw, $pem, OPENSSL_ALGO_SHA256) === 1) {
                    return $path;
                }
            }
        }
    }
    return null;
}

/**
 * Verified signature keeps the posted activity and does not fetch.
 * A failed signature is replaced only by an origin document (or a Delete
 * whose object is gone). Otherwise null, and the verify error globals say why.
 *
 * @return array{activity:array<string,mixed>,sig:array{keyId:string,algorithm:?string,headers:string,signature:string},via:string,path:string}|null
 */
function ap_inbox_select(string $raw): ?array
{
    $verified = ap_inbox_try_verify($raw);
    if ($verified !== null) {
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ap_inbox_verify_fail('Invalid signature');
        }
        return [
            'activity' => $json,
            'sig' => $verified['sig'],
            'via' => 'signature',
            'path' => $verified['path'],
        ];
    }
    if (in_array((int) ($GLOBALS['ap_inbox_verify_code'] ?? 401), [403, 429], true)) {
        return null;
    }
    $verifyError = (string) ($GLOBALS['ap_inbox_verify_error'] ?? 'Invalid signature');
    ap_log('verify_fail msg=' . preg_replace('/[^A-Za-z0-9 ._-]+/', '_', $verifyError));
    $posted = json_decode($raw, true);
    $GLOBALS['ap_inbox_fetch_throttled'] = false;
    $recovered = ap_inbox_recover_unverified(is_array($posted) ? $posted : null);
    if ($recovered === null) {
        if (!empty($GLOBALS['ap_inbox_fetch_throttled'])) {
            return ap_inbox_verify_fail('Too many requests', 429);
        }
        return null;
    }
    return [
        'activity' => $recovered,
        'sig' => [
            'keyId' => ap_as_id($recovered['actor'] ?? null) ?? '',
            'algorithm' => null,
            'headers' => '',
            'signature' => '',
        ],
        'via' => 'origin',
        'path' => '',
    ];
}

function ap_inbox_verify_fail(string $message, int $code = 401): null
{
    $GLOBALS['ap_inbox_verify_error'] = $message;
    $GLOBALS['ap_inbox_verify_code'] = $code;
    return null;
}

/**
 * Draft-cavage or RFC 9421 verification of the posted body.
 * Does not exit. On failure the caller may recover from the origin.
 *
 * @return array{sig:array{keyId:string,algorithm:?string,headers:string,signature:string,created:?string,expires:?string},path:string}|null
 */
function ap_inbox_try_verify(string $raw): ?array
{
    $GLOBALS['ap_inbox_verify_error'] = 'Invalid signature';
    $GLOBALS['ap_inbox_verify_code'] = 401;
    $digestHdr = ap_header('Digest');
    $contentDigest = ap_header('Content-Digest');
    $dateHdr = ap_header('Date');
    $sigHdr = ap_header('Signature');
    $sigInput = ap_header('Signature-Input');

    if ($sigInput !== '') {
        return ap_inbox_try_verify_rfc9421($raw, $sigHdr, $sigInput, $contentDigest, $dateHdr);
    }
    if ($digestHdr === '' || $dateHdr === '' || $sigHdr === '') {
        return ap_inbox_verify_fail('Missing Digest, Date, or Signature');
    }
    if (!ap_valid_date($dateHdr)) {
        return ap_inbox_verify_fail('Date out of range');
    }
    if (!ap_valid_digest($digestHdr, $raw)) {
        return ap_inbox_verify_fail('Digest mismatch');
    }
    $sig = ap_parse_signature($sigHdr);
    if ($sig === null) {
        ap_log('signature_parse_fail len=' . strlen($sigHdr) . ' sample=' . ap_short($sigHdr, 160));
        return ap_inbox_verify_fail('Malformed Signature');
    }
    $algo = strtolower((string) ($sig['algorithm'] ?? 'hs2019'));
    if ($algo === '') {
        $algo = 'hs2019';
    }
    if ($algo !== 'rsa-sha256' && $algo !== 'hs2019') {
        return ap_inbox_verify_fail('Unsupported signature algorithm');
    }
    if ($algo === 'hs2019' && !ap_inbox_hs2019_time_ok($sig)) {
        return ap_inbox_verify_fail('Signature created/expires out of range');
    }
    $keyHostEarly = parse_url($sig['keyId'], PHP_URL_HOST);
    if (is_string($keyHostEarly) && ap_is_blocked_host(strtolower($keyHostEarly))) {
        ap_log(sprintf('blocked_reject_keyhost ip=%s keyId=%s', ap_client_ip(), ap_short($sig['keyId'])));
        return ap_inbox_verify_fail('Instance blocked', 403);
    }
    $GLOBALS['ap_inbox_fetch_throttled'] = false;
    $pem = ap_fetch_actor_pubkey($sig['keyId']);
    $sigRaw = base64_decode($sig['signature'], true) ?: '';
    $okPath = ($pem !== null) ? ap_verify_http_signature($sig['headers'], $raw, $sigRaw, $pem, $sig) : null;
    if ($okPath === null && $pem !== null) {
        // Refetch once for a rotated key. Keep the cached key when the fetch
        // budget refuses, so failed signatures cannot evict a working key.
        $pem = ap_fetch_actor_pubkey($sig['keyId'], true);
        if ($pem === null && empty($GLOBALS['ap_inbox_fetch_throttled'])) {
            ap_inbox_forget_actor_pubkey($sig['keyId']);
        }
        $okPath = ($pem !== null) ? ap_verify_http_signature($sig['headers'], $raw, $sigRaw, $pem, $sig) : null;
    }
    if ($pem === null) {
        if (!empty($GLOBALS['ap_inbox_fetch_throttled'])) {
            return ap_inbox_verify_fail('Too many key fetches', 429);
        }
        return ap_inbox_verify_fail('Unable to fetch key');
    }
    if ($okPath === null) {
        return ap_inbox_verify_fail('Invalid signature');
    }
    return ['sig' => $sig, 'path' => $okPath];
}

/**
 * hs2019 signs (created)/(expires) instead of, or in addition to, Date.
 * Window matches Mastodon: created may be up to an hour ahead, and an
 * explicit expires may be up to an hour in the past.
 *
 * @param array{created?:?string,expires?:?string,headers?:string} $sig
 */
function ap_inbox_hs2019_time_ok(array $sig): bool
{
    $headers = strtolower((string) ($sig['headers'] ?? ''));
    $needsCreated = str_contains($headers, '(created)');
    $createdRaw = $sig['created'] ?? null;
    if ($needsCreated && (!is_string($createdRaw) || !preg_match('/^[0-9]{1,12}$/', $createdRaw))) {
        return false;
    }
    $now = time();
    if (is_string($createdRaw) && $createdRaw !== '') {
        $created = (int) $createdRaw;
        if ($created > $now + AP_INBOX_DATE_SKEW) {
            return false;
        }
        $expiresRaw = $sig['expires'] ?? null;
        if (is_string($expiresRaw) && preg_match('/^[0-9]{1,12}$/', $expiresRaw)) {
            if ($now > ((int) $expiresRaw) + AP_INBOX_DATE_SKEW) {
                return false;
            }
        } elseif ($now > $created + AP_INBOX_DATE_SKEW) {
            // No expires: treat created as the signed time, same skew as Date.
            return false;
        }
    }
    $needsExpires = str_contains($headers, '(expires)');
    if ($needsExpires && (!is_string($sig['expires'] ?? null) || !preg_match('/^[0-9]{1,12}$/', (string) $sig['expires']))) {
        return false;
    }
    return true;
}

/**
 * Mastodon 4.7 retries a 401 with one RFC 9421 signature:
 * Signature-Input covers @method, @target-uri, and content-digest.
 *
 * @return array{sig:array{keyId:string,algorithm:?string,headers:string,signature:string,created:?string,expires:?string},path:string}|null
 */
function ap_inbox_try_verify_rfc9421(string $raw, string $sigHdr, string $sigInput, string $contentDigest, string $dateHdr): ?array
{
    $parsed = ap_inbox_parse_signature_input($sigInput);
    if ($parsed === null || $sigHdr === '') {
        return ap_inbox_verify_fail('Malformed Signature');
    }
    $params = $parsed['params'];
    $alg = strtolower((string) ($params['alg'] ?? 'rsa-v1_5-sha256'));
    if (!in_array($alg, ['rsa-v1_5-sha256', 'rsa-sha256', 'hs2019'], true)) {
        return ap_inbox_verify_fail('Unsupported signature algorithm');
    }
    $createdRaw = (string) ($params['created'] ?? '');
    if ($createdRaw === '' || !preg_match('/^[0-9]{1,12}$/', $createdRaw)) {
        return ap_inbox_verify_fail('Signature created/expires out of range');
    }
    $now = time();
    $created = (int) $createdRaw;
    if ($created > $now + AP_INBOX_DATE_SKEW) {
        return ap_inbox_verify_fail('Signature created/expires out of range');
    }
    $expiresRaw = (string) ($params['expires'] ?? '');
    if ($expiresRaw !== '' && preg_match('/^[0-9]{1,12}$/', $expiresRaw)) {
        if ($now > ((int) $expiresRaw) + AP_INBOX_DATE_SKEW) {
            return ap_inbox_verify_fail('Signature created/expires out of range');
        }
    } elseif ($now > $created + AP_INBOX_DATE_SKEW) {
        return ap_inbox_verify_fail('Signature created/expires out of range');
    }
    if ($dateHdr !== '' && !ap_valid_date($dateHdr) && abs($now - $created) > AP_INBOX_DATE_SKEW) {
        return ap_inbox_verify_fail('Date out of range');
    }
    $signsDigest = in_array('content-digest', $parsed['components'], true) || in_array('digest', $parsed['components'], true);
    if ($signsDigest) {
        $digestOk = ($contentDigest !== '' && ap_valid_content_digest($contentDigest, $raw))
            || (ap_header('Digest') !== '' && ap_valid_digest(ap_header('Digest'), $raw));
        if (!$digestOk) {
            return ap_inbox_verify_fail('Digest mismatch');
        }
    }
    $keyId = (string) ($params['keyid'] ?? '');
    if ($keyId === '' || !str_starts_with($keyId, 'https://')) {
        return ap_inbox_verify_fail('Malformed Signature');
    }
    $keyHost = parse_url($keyId, PHP_URL_HOST);
    if (is_string($keyHost) && ap_is_blocked_host(strtolower($keyHost))) {
        return ap_inbox_verify_fail('Instance blocked', 403);
    }
    $label = preg_quote($parsed['label'], '/');
    if (!preg_match('/' . $label . '\s*=\s*:([A-Za-z0-9+\/=]+):/', $sigHdr, $sm)) {
        return ap_inbox_verify_fail('Malformed Signature');
    }
    $sigRaw = base64_decode($sm[1], true) ?: '';
    $GLOBALS['ap_inbox_fetch_throttled'] = false;
    $pem = ap_fetch_actor_pubkey($keyId);
    $okPath = ($pem !== null) ? ap_inbox_verify_rfc9421_base($parsed, $sigRaw, $pem, $contentDigest) : null;
    if ($okPath === null && $pem !== null) {
        // Refetch once for a rotated key. Keep the cached key when the fetch
        // budget refuses, so failed signatures cannot evict a working key.
        $pem = ap_fetch_actor_pubkey($keyId, true);
        if ($pem === null && empty($GLOBALS['ap_inbox_fetch_throttled'])) {
            ap_inbox_forget_actor_pubkey($keyId);
        }
        $okPath = ($pem !== null) ? ap_inbox_verify_rfc9421_base($parsed, $sigRaw, $pem, $contentDigest) : null;
    }
    if ($pem === null) {
        if (!empty($GLOBALS['ap_inbox_fetch_throttled'])) {
            return ap_inbox_verify_fail('Too many key fetches', 429);
        }
        return ap_inbox_verify_fail('Unable to fetch key');
    }
    if ($okPath === null) {
        return ap_inbox_verify_fail('Invalid signature');
    }
    return [
        'sig' => [
            'keyId' => $keyId,
            'algorithm' => $alg,
            'headers' => implode(' ', $parsed['components']),
            'signature' => $sm[1],
            'created' => $createdRaw,
            'expires' => isset($params['expires']) ? (string) $params['expires'] : null,
        ],
        'path' => $okPath,
    ];
}

/**
 * @return array{label:string,components:list<string>,params:array<string,string>,params_raw:string}|null
 */
function ap_inbox_parse_signature_input(string $header): ?array
{
    $header = trim($header);
    $eq = strpos($header, '=');
    if ($eq === false || $eq < 1) {
        return null;
    }
    $label = substr($header, 0, $eq);
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $label)) {
        return null;
    }
    $paramsRaw = substr($header, $eq + 1);
    if (!preg_match('/^\(([^)]*)\)/', $paramsRaw, $list)) {
        return null;
    }
    $components = [];
    if (preg_match_all('/"([^"]+)"/', $list[1], $cm)) {
        $components = $cm[1];
    }
    if ($components === []) {
        return null;
    }
    $params = [];
    if (preg_match_all('/;\s*([A-Za-z0-9_-]+)=("([^"]*)"|([^\s,;]+))/', $paramsRaw, $pm, PREG_SET_ORDER)) {
        foreach ($pm as $p) {
            $params[strtolower($p[1])] = ($p[3] ?? '') !== '' ? $p[3] : (string) ($p[4] ?? '');
        }
    }
    return [
        'label' => $label,
        'components' => $components,
        'params' => $params,
        'params_raw' => $paramsRaw,
    ];
}

function ap_valid_content_digest(string $header, string $body): bool
{
    if (!preg_match('/sha-256=:([A-Za-z0-9+\/=]+):/i', $header, $m)) {
        return false;
    }
    return hash_equals(base64_encode(hash('sha256', $body, true)), $m[1]);
}

/**
 * @param array{components:list<string>,params_raw:string} $parsed
 */
function ap_inbox_verify_rfc9421_base(array $parsed, string $sigRaw, $pem, string $contentDigest): ?string
{
    if ($sigRaw === '') {
        return null;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST'));
    foreach (ap_inbox_host_candidates() as $host) {
        $authority = $host;
        foreach (ap_request_target_path_candidates() as $path) {
            $target = 'https://' . $host . $path;
            $lines = [];
            $ok = true;
            foreach ($parsed['components'] as $component) {
                $name = strtolower($component);
                if ($name === '@method') {
                    $lines[] = '"@method": ' . $method;
                } elseif ($name === '@target-uri') {
                    $lines[] = '"@target-uri": ' . $target;
                } elseif ($name === '@authority') {
                    $lines[] = '"@authority": ' . $authority;
                } elseif ($name === '@path' || $name === '@request-target') {
                    $lines[] = '"' . $name . '": ' . $path;
                } elseif ($name === 'content-digest') {
                    if ($contentDigest === '') {
                        $ok = false;
                        break;
                    }
                    $lines[] = '"content-digest": ' . $contentDigest;
                } else {
                    $val = $name === 'host' ? $host : ap_header($name);
                    if ($val === '') {
                        $ok = false;
                        break;
                    }
                    $lines[] = '"' . $name . '": ' . $val;
                }
            }
            if (!$ok) {
                continue;
            }
            $lines[] = '"@signature-params": ' . $parsed['params_raw'];
            $base = implode("\n", $lines);
            if (openssl_verify($base, $sigRaw, $pem, OPENSSL_ALGO_SHA256) === 1) {
                return $path;
            }
        }
    }
    return null;
}

/**
 * URLs an unverified activity may be re-fetched from. Only https URLs on the
 * claimed actor host. Delete prefers the object, which is what disappears.
 *
 * @param array<string,mixed>|null $posted
 * @return list<string>
 */
function ap_inbox_origin_urls(?array $posted): array
{
    if ($posted === null) {
        return [];
    }
    $claimed = ap_inbox_https_id($posted['actor'] ?? null);
    if ($claimed === null) {
        return [];
    }
    $actorHost = strtolower((string) parse_url($claimed, PHP_URL_HOST));
    if ($actorHost === '') {
        return [];
    }
    $type = is_string($posted['type'] ?? null) ? $posted['type'] : '';
    $objectId = ap_inbox_https_id($posted['object'] ?? null);
    $activityId = ap_inbox_https_id($posted['id'] ?? null);
    $ordered = strcasecmp($type, 'Delete') === 0 ? [$objectId, $activityId] : [$activityId, $objectId];
    $urls = [];
    foreach ($ordered as $id) {
        $fetch = ap_inbox_fetch_url($id);
        if ($fetch === null || !ap_inbox_same_host($fetch, $actorHost) || in_array($fetch, $urls, true)) {
            continue;
        }
        $urls[] = $fetch;
    }
    return $urls;
}

/**
 * Choose the activity to process after signature verification failed.
 * $fetches is keyed by the normalized fetch URL.
 *
 * @param array<string,mixed>|null $posted
 * @param array<string,array{status?:int,doc?:mixed}> $fetches
 * @return array<string,mixed>|null
 */
function ap_inbox_origin_decision(?array $posted, array $fetches): ?array
{
    if ($posted === null) {
        return null;
    }
    $claimed = ap_inbox_https_id($posted['actor'] ?? null);
    if ($claimed === null) {
        return null;
    }
    $actorHost = strtolower((string) parse_url($claimed, PHP_URL_HOST));
    if ($actorHost === '') {
        return null;
    }
    $type = is_string($posted['type'] ?? null) ? $posted['type'] : '';
    $objectId = ap_inbox_https_id($posted['object'] ?? null);
    $activityId = ap_inbox_https_id($posted['id'] ?? null);
    $objectFetch = ap_inbox_fetch_url($objectId);
    $sawGoneObject = false;
    foreach (ap_inbox_origin_urls($posted) as $url) {
        $res = $fetches[$url] ?? null;
        if (!is_array($res)) {
            continue;
        }
        $status = (int) ($res['status'] ?? 0);
        $doc = is_array($res['doc'] ?? null) ? $res['doc'] : null;
        $objectGone = strcasecmp($type, 'Delete') === 0
            && $objectFetch !== null
            && ap_inbox_same_url($url, $objectFetch)
            && ($status === 404 || $status === 410);
        if ($objectGone) {
            $sawGoneObject = true;
            continue;
        }
        if ($doc === null || !ap_inbox_origin_doc_matches($doc, $claimed, $actorHost)) {
            continue;
        }
        if (strcasecmp($type, 'Delete') === 0) {
            $docType = is_string($doc['type'] ?? null) ? $doc['type'] : '';
            if (strcasecmp($docType, 'Delete') !== 0) {
                return null;
            }
        }
        $activity = ap_inbox_activity_from_fetched($doc, $claimed, $type, $activityId, $actorHost);
        if ($activity !== null) {
            return $activity;
        }
    }
    if ($sawGoneObject && $objectId !== null) {
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'Delete',
            'actor' => $claimed,
            'object' => $objectId,
        ];
        if ($activityId !== null && ap_inbox_same_host($activityId, $actorHost)) {
            $activity['id'] = $activityId;
        }
        return $activity;
    }
    return null;
}

/**
 * A verified delivery whose key host is not the activity actor.
 * Relays and followed forwarders may keep the signed body. Everyone else
 * must be replaced by an origin document, or rejected.
 *
 * @param array<string,mixed>|null $posted
 * @param array<string,array{status?:int,doc?:mixed}> $fetches
 * @return array<string,mixed>|null
 */
function ap_inbox_reconcile_decision(?array $posted, string $keyId, bool $trusted, array $fetches): ?array
{
    if ($posted === null) {
        return null;
    }
    $claimed = ap_as_id($posted['actor'] ?? null);
    if ($claimed === null || ap_inbox_actor_matches_key($keyId, $claimed)) {
        return $posted;
    }
    if ($trusted) {
        $type = is_string($posted['type'] ?? null) ? $posted['type'] : '';
        return strcasecmp($type, 'Create') === 0 ? ap_inbox_forwarded_create_decision($posted, $fetches) : $posted;
    }
    return ap_inbox_origin_decision($posted, $fetches);
}

/**
 * Where to fetch the object of a forwarded Create: its id, on the claimed
 * actor's host. Null when there is no such id.
 *
 * @param array<string,mixed> $posted
 */
function ap_inbox_forwarded_create_url(array $posted): ?string
{
    $claimed = ap_inbox_https_id($posted['actor'] ?? null);
    $url = ap_inbox_fetch_url(ap_inbox_https_id($posted['object'] ?? null));
    if ($claimed === null || $url === null) {
        return null;
    }
    $actorHost = strtolower((string) parse_url($claimed, PHP_URL_HOST));
    return $actorHost !== '' && ap_inbox_same_host($url, $actorHost) ? $url : null;
}

/**
 * Rebuild a forwarded Create from the origin copy of its object. The fetched
 * object must keep its id on the fetched host and be attributed to the
 * claimed actor. Addressing comes from the fetched object, not the forward.
 *
 * @param array<string,mixed>|null $posted
 * @param array<string,array{status?:int,doc?:mixed}> $fetches keyed by fetch URL
 * @return array<string,mixed>|null
 */
function ap_inbox_forwarded_create_decision(?array $posted, array $fetches): ?array
{
    if ($posted === null) {
        return null;
    }
    $type = is_string($posted['type'] ?? null) ? $posted['type'] : '';
    $claimed = ap_inbox_https_id($posted['actor'] ?? null);
    $url = ap_inbox_forwarded_create_url($posted);
    if (strcasecmp($type, 'Create') !== 0 || $claimed === null || $url === null) {
        return null;
    }
    $res = $fetches[$url] ?? null;
    $status = is_array($res) ? (int) ($res['status'] ?? 0) : 0;
    $doc = is_array($res) && is_array($res['doc'] ?? null) ? $res['doc'] : null;
    if ($doc === null || $status < 200 || $status >= 300) {
        return null;
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $docId = ap_inbox_https_id($doc['id'] ?? null);
    if ($docId === null || !ap_inbox_same_host($docId, $host)) {
        return null;
    }
    $docType = is_string($doc['type'] ?? null) ? $doc['type'] : '';
    if ($docType === '' || strcasecmp($docType, 'Tombstone') === 0 || isset($doc['actor'])) {
        return null;
    }
    $attributed = [];
    $attr = $doc['attributedTo'] ?? null;
    foreach ((is_array($attr) && array_is_list($attr)) ? $attr : [$attr] as $entry) {
        $id = ap_inbox_https_id($entry);
        if ($id !== null) {
            $attributed[] = ap_inbox_norm_id($id);
        }
    }
    if (!in_array(ap_inbox_norm_id($claimed), $attributed, true)) {
        return null;
    }
    $activity = [
        '@context' => $doc['@context'] ?? 'https://www.w3.org/ns/activitystreams',
        'type' => 'Create',
        'actor' => $claimed,
        'object' => $doc,
    ];
    $activityId = ap_inbox_https_id($posted['id'] ?? null);
    if ($activityId !== null && ap_inbox_same_host($activityId, $host)) {
        $activity['id'] = $activityId;
    }
    foreach (['to', 'cc', 'bto', 'bcc', 'audience', 'published'] as $key) {
        if (array_key_exists($key, $doc)) {
            $activity[$key] = $doc[$key];
        }
    }
    return $activity;
}

/**
 * Fetch the object of a forwarded Create from its origin and rebuild the
 * activity from it. Null when the fetch fails or the copy does not match.
 *
 * @param array<string,mixed> $posted
 * @return array<string,mixed>|null
 */
function ap_inbox_refetch_forwarded_create(array $posted): ?array
{
    $url = ap_inbox_forwarded_create_url($posted);
    if ($url === null) {
        return null;
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    try {
        if (ap_is_blocked_host($host)) {
            return null;
        }
    } catch (Throwable $e) {
        return null;
    }
    if (!ap_net_host_is_public($host)) {
        return null;
    }
    return ap_inbox_forwarded_create_decision($posted, [$url => ap_inbox_origin_fetch($url)]);
}

/**
 * Fetch-and-decide for an unverified inbox body. Does not trust the body.
 *
 * @param array<string,mixed>|null $posted
 * @return array<string,mixed>|null
 */
function ap_inbox_recover_unverified(?array $posted): ?array
{
    if ($posted === null) {
        return null;
    }
    $claimed = ap_inbox_https_id($posted['actor'] ?? null);
    if ($claimed === null) {
        return null;
    }
    $actorHost = strtolower((string) parse_url($claimed, PHP_URL_HOST));
    if ($actorHost === '') {
        return null;
    }
    try {
        if (function_exists('ap_is_blocked_host') && ap_is_blocked_host($actorHost)) {
            return null;
        }
    } catch (Throwable $e) {
        return null;
    }
    $urls = ap_inbox_origin_urls($posted);
    if ($urls === []) {
        return null;
    }
    if (!ap_inbox_fetch_budget_take([['origin:' . $actorHost, AP_INBOX_ORIGIN_FETCH_MAX_PER_HOST]])) {
        return null;
    }
    if (function_exists('ap_host_resolves_public') && !ap_host_resolves_public($actorHost)) {
        return null;
    }
    $fetches = [];
    foreach ($urls as $url) {
        $fetches[$url] = ap_inbox_origin_fetch($url);
    }
    return ap_inbox_origin_decision($posted, $fetches);
}

/**
 * @param array<string,mixed> $doc
 * @return array<string,mixed>|null
 */
function ap_inbox_activity_from_fetched(array $doc, string $claimed, string $postedType, ?string $activityId, string $actorHost): ?array
{
    $actor = ap_inbox_https_id($doc['actor'] ?? null);
    if ($actor !== null) {
        return $doc;
    }
    $wrap = '';
    if (strcasecmp($postedType, 'Create') === 0 || strcasecmp($postedType, 'Update') === 0) {
        $wrap = strcasecmp($postedType, 'Update') === 0 ? 'Update' : 'Create';
    }
    if ($wrap === '') {
        return null;
    }
    $activity = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'type' => $wrap,
        'actor' => $claimed,
        'object' => $doc,
    ];
    if ($activityId !== null && ap_inbox_same_host($activityId, $actorHost) && ap_inbox_fetch_url($activityId) !== ap_inbox_fetch_url(ap_inbox_https_id($doc['id'] ?? null))) {
        $activity['id'] = $activityId;
    }
    return $activity;
}

/**
 * @param array<string,mixed> $doc
 */
function ap_inbox_origin_doc_matches(array $doc, string $claimed, string $actorHost): bool
{
    $claimedNorm = ap_inbox_norm_id($claimed);
    $ids = [];
    $actor = ap_inbox_https_id($doc['actor'] ?? null);
    $attr = ap_inbox_https_id($doc['attributedTo'] ?? null);
    if ($actor !== null) {
        $ids[] = $actor;
    }
    if ($attr !== null) {
        $ids[] = $attr;
    }
    if ($ids === []) {
        return false;
    }
    foreach ($ids as $id) {
        if (ap_inbox_norm_id($id) !== $claimedNorm || !ap_inbox_same_host($id, $actorHost)) {
            return false;
        }
    }
    return true;
}

function ap_inbox_https_id($value): ?string
{
    $id = ap_as_id($value);
    if (!is_string($id) || !str_starts_with($id, 'https://')) {
        return null;
    }
    return $id;
}

function ap_inbox_fetch_url(?string $id): ?string
{
    if ($id === null || !str_starts_with($id, 'https://')) {
        return null;
    }
    $parts = parse_url($id);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '' || str_contains($host, ':')) {
        return null;
    }
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }
    $url = 'https://' . $host . $path;
    if (!empty($parts['query'])) {
        $url .= '?' . $parts['query'];
    }
    return $url;
}

function ap_inbox_same_host(string $url, string $host): bool
{
    $urlHost = parse_url($url, PHP_URL_HOST);
    return is_string($urlHost) && strtolower($urlHost) === strtolower($host);
}

function ap_inbox_same_url(string $a, string $b): bool
{
    return ap_inbox_norm_id($a) === ap_inbox_norm_id($b);
}

function ap_inbox_norm_id(string $id): string
{
    $parts = parse_url(trim($id));
    if (!is_array($parts)) {
        return rtrim(strtolower($id), '/');
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = rtrim((string) ($parts['path'] ?? ''), '/');
    return 'https://' . $host . $path;
}

/**
 * Signed GET of an origin document, deduped by URL and bounded by the
 * existing remote-fetch circuit breaker. A test may set
 * $GLOBALS['ap_inbox_origin_fetch'] to a callable.
 *
 * @return array{status:int,doc:?array}
 */
function ap_inbox_origin_fetch(string $url): array
{
    $url = ap_inbox_fetch_url($url) ?? '';
    if ($url === '') {
        return ['status' => 0, 'doc' => null];
    }
    if (!isset($GLOBALS['ap_inbox_origin_mem']) || !is_array($GLOBALS['ap_inbox_origin_mem'])) {
        $GLOBALS['ap_inbox_origin_mem'] = [];
    }
    if (isset($GLOBALS['ap_inbox_origin_mem'][$url]) && is_array($GLOBALS['ap_inbox_origin_mem'][$url])) {
        return $GLOBALS['ap_inbox_origin_mem'][$url];
    }
    $cached = ap_inbox_origin_cache_read($url);
    if ($cached !== null) {
        $GLOBALS['ap_inbox_origin_mem'][$url] = $cached;
        return $cached;
    }
    $hook = $GLOBALS['ap_inbox_origin_fetch'] ?? null;
    if (is_callable($hook)) {
        $res = $hook($url);
        $normalized = ap_inbox_origin_normalize_fetch(is_array($res) ? $res : []);
    } else {
        $normalized = ap_inbox_origin_fetch_live($url);
    }
    if (in_array($normalized['status'], [200, 201, 202, 404, 410], true)) {
        ap_inbox_origin_cache_write($url, $normalized);
    }
    $GLOBALS['ap_inbox_origin_mem'][$url] = $normalized;
    return $normalized;
}

/**
 * @param array<string,mixed> $res
 * @return array{status:int,doc:?array}
 */
function ap_inbox_origin_normalize_fetch(array $res): array
{
    $status = (int) ($res['status'] ?? 0);
    $doc = $res['doc'] ?? null;
    if (!is_array($doc) && is_string($res['body'] ?? null)) {
        $decoded = json_decode((string) $res['body'], true);
        $doc = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($doc)) {
        $doc = null;
    }
    return ['status' => $status, 'doc' => $doc];
}

/**
 * @return array{status:int,doc:?array}
 */
function ap_inbox_origin_fetch_live(string $url): array
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '' || !ap_host_resolves_public($host)) {
        return ['status' => 0, 'doc' => null];
    }
    if (ap_remote_fetch_circuit_open($host) > time()) {
        return ['status' => 0, 'doc' => null];
    }
    $res = ap_signed_get_ex($url);
    $status = (int) ($res['status'] ?? 0);
    $body = is_string($res['body'] ?? null) ? $res['body'] : null;
    $doc = null;
    if (is_string($body) && $body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && (isset($decoded['type']) || isset($decoded['id']) || isset($decoded['actor']))) {
            $doc = $decoded;
        }
    }
    if (($status >= 200 && $status < 300 && $doc !== null) || $status === 404 || $status === 410) {
        ap_remote_fetch_circuit_success($host);
    } else {
        ap_remote_fetch_circuit_failure($host);
    }
    return ['status' => $status, 'doc' => $doc];
}

function ap_inbox_origin_cache_path(string $url): string
{
    $dir = $GLOBALS['ap_inbox_origin_cache_dir'] ?? (sys_get_temp_dir() . '/ap-inbox-origin');
    if (!is_string($dir) || $dir === '') {
        $dir = sys_get_temp_dir() . '/ap-inbox-origin';
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return rtrim($dir, '/') . '/' . hash('sha256', $url) . '.json';
}

/**
 * @return array{status:int,doc:?array}|null
 */
function ap_inbox_origin_cache_read(string $url): ?array
{
    $path = ap_inbox_origin_cache_path($url);
    if (!is_file($path) || (time() - (int) filemtime($path)) > 120) {
        return null;
    }
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return null;
    }
    return ap_inbox_origin_normalize_fetch($data);
}

/**
 * @param array{status:int,doc:?array} $res
 */
function ap_inbox_origin_cache_write(string $url, array $res): void
{
    $payload = json_encode([
        'status' => $res['status'],
        'doc' => $res['doc'],
        'at' => time(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($payload)) {
        @file_put_contents(ap_inbox_origin_cache_path($url), $payload, LOCK_EX);
    }
}

/**
 * Sliding-window counter in AP_INBOX_RATE_DIR. $charge=false only checks
 * whether the bucket still has room.
 */
function ap_rate_limit(string $ip, int $max = AP_INBOX_RATE_MAX, int $window = AP_INBOX_RATE_WINDOW, bool $charge = true): bool
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
        $stamps = array_values(array_filter($stamps, static fn($t) => $t > $now - $window));
        if (count($stamps) >= $max) {
            return false;
        }
        if (!$charge) {
            return true;
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
    return ap_net_ip_is_public($ip);
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
        $remoteStarted = microtime(true);
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
        // Connect only to the addresses the SSRF guard checked.
        if (!ap_net_curl_pin($ch, $url)) {
            curl_close($ch);
            return ['body' => null, 'status' => 0];
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if (function_exists('ap_timing_record')) {
            ap_timing_record('activitypub.fetch', (microtime(true) - $remoteStarted) * 1000.0);
        }
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

function ap_inbox_forget_actor_pubkey(string $keyId): void
{
    $keyId = trim($keyId);
    if ($keyId === '') {
        return;
    }
    $cacheFile = AP_INBOX_KEY_CACHE_DIR . '/' . hash('sha256', $keyId) . '.pem';
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }
}

/**
 * Spend one unit of a remote-fetch budget. Redis when available, else the
 * same file counters the per-IP limiter uses. Sets
 * $GLOBALS['ap_inbox_fetch_throttled'] when a bucket is spent.
 *
 * @param list<array{0:string,1:int}> $buckets [bucket name, max per window]
 */
function ap_inbox_fetch_budget_take(array $buckets): bool
{
    foreach ($buckets as [$bucket, $max]) {
        $name = 'inbox-fetch:' . $bucket;
        $count = function_exists('ap_redis_rate_count')
            ? ap_redis_rate_count($name, AP_INBOX_FETCH_BUDGET_WINDOW)
            : null;
        $ok = $count === null
            ? ap_rate_limit('fetch_' . hash('sha256', $name), $max, AP_INBOX_FETCH_BUDGET_WINDOW)
            : $count <= $max;
        if (!$ok) {
            $GLOBALS['ap_inbox_fetch_throttled'] = true;
            ap_log('fetch_budget_exceeded bucket=' . ap_short($bucket));
            return false;
        }
    }
    return true;
}

function ap_inbox_key_fetch_budget_take(string $keyId): bool
{
    $base = preg_replace('/#.*$/', '', trim($keyId)) ?: trim($keyId);
    $host = strtolower((string) parse_url($base, PHP_URL_HOST));
    return ap_inbox_fetch_budget_take([
        ['key:' . $base, AP_INBOX_KEY_FETCH_MAX_PER_KEY],
        ['keyhost:' . $host, AP_INBOX_KEY_FETCH_MAX_PER_HOST],
    ]);
}

function ap_fetch_actor_pubkey(string $keyId, bool $bypassCache = false): ?string
{
    $keyId = trim($keyId);
    $override = $GLOBALS['ap_inbox_pubkey_override'] ?? null;
    if (is_callable($override)) {
        $injected = $override($keyId);
        if (is_string($injected) && str_contains($injected, 'BEGIN')) {
            return $injected;
        }
    }
    if ($keyId === '' || strlen($keyId) > 500) {
        return null;
    }
    $parts = parse_url($keyId);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
        return null;
    }
    $host = $parts['host'] ?? '';
    if ($host === '') {
        return null;
    }

    if (!is_dir(AP_INBOX_KEY_CACHE_DIR)) {
        @mkdir(AP_INBOX_KEY_CACHE_DIR, 0750, true);
    }
    $cacheFile = AP_INBOX_KEY_CACHE_DIR . '/' . hash('sha256', $keyId) . '.pem';
    if (!$bypassCache && is_file($cacheFile) && (time() - filemtime($cacheFile) < AP_INBOX_KEY_CACHE_TTL)) {
        $cached = file_get_contents($cacheFile);
        if (is_string($cached) && str_contains($cached, 'BEGIN')) {
            return $cached;
        }
    }

    // Negative cache: skip hammering Threads/Meta for dead / non-federated actors.
    // A verify failure bypasses this so a just-rotated key can be fetched once.
    $neg = $bypassCache ? null : ap_key_neg_cache_get($keyId);
    if ($neg !== null) {
        ap_log(
            'key_fetch_neg_hit keyId=' . ap_short($keyId)
            . ' http=' . (int) $neg['http']
            . ' reason=' . preg_replace('/[^a-z0-9_]+/i', '_', (string) $neg['reason'])
        );
        return null;
    }

    // Everything below is a remote fetch for a sender that has not verified yet.
    if (!ap_inbox_key_fetch_budget_take($keyId)) {
        return null;
    }
    if (!ap_host_resolves_public($host)) {
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
        $owner = ap_extract_pubkey_owner($data);
        if ($owner !== null) {
            @file_put_contents(ap_key_owner_cache_path($keyId), $owner);
        } else {
            @unlink(ap_key_owner_cache_path($keyId));
        }
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
    return ap_net_host_is_public($host);
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

    // Server-wide actor/instance block: hard-reject federation (403), do not process.
    if ($actorId && ap_is_blocked_actor($actorId)) {
        ap_log('blocked_reject type=' . $type . ' actor=' . ap_short($actorId));
        ap_fail('Actor or instance blocked', 403);
    }
    // Also reject when the object itself lives on a blocked host (e.g. relay-forwarded
    // Create whose signing relay is allowed but the note URI is on a blocked domain).
    if ($objectId && str_starts_with($objectId, 'https://')) {
        $objectHost = parse_url($objectId, PHP_URL_HOST);
        if (is_string($objectHost) && ap_is_blocked_host(strtolower($objectHost))) {
            ap_log('blocked_reject_object type=' . $type . ' object=' . ap_short($objectId));
            ap_fail('Object instance blocked', 403);
        }
    }

    // Wafrn-compatible AskQuestion is a private, addressed interaction. It
    // must be handled before public/private timeline observation so the
    // question can never leak into the public events feed.
    if ($type === 'AskQuestion' && $actorId) {
        $recipient = ap_inbox_recipient_owner($activity);
        $ok = function_exists('ap_ask_store_inbound')
            ? ap_ask_store_inbound($activity, (int) ($recipient['owner_user_id'] ?? 0), (string) ($recipient['owner_actor_id'] ?? LOCAL_ACTOR))
            : false;
        ap_metrics_record($type, $actorId, $objectId, (string) ($recipient['owner_actor_id'] ?? LOCAL_ACTOR), $bytes, $ok ? 'local_ask' : 'local_ask_rejected', null);
        return $ok ? 'local_ask' : 'local_ask_rejected';
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
        $quoteResult = ap_quote_response_handle($activity, 'Accept');
        if ($quoteResult !== null) {
            ap_metrics_record('Accept', $actorId, $objectId, LOCAL_ACTOR, $bytes, $quoteResult, null);
            return $quoteResult;
        }
        // Mastodon-style relay: Accept of Follow(as:Public) matching ap_relays.follow_activity_id
        if (!function_exists('ap_relay_handle_accept')) {
            require_once __DIR__ . '/ap-relays.php';
        }
        if (function_exists('ap_relay_handle_accept') && ap_relay_handle_accept($activity)) {
            ap_metrics_record('Accept', $actorId, $objectId, LOCAL_ACTOR, $bytes, 'relay_accepted', null);
            return 'relay_accepted';
        }
        $applied = function_exists('ap_follow_response_apply')
            ? ap_follow_response_apply($activity, LOCAL_ACTOR)
            : null;
        if ($applied === 'follow_accepted') {
            ap_metrics_record('Accept', $actorId, $objectId, LOCAL_ACTOR, $bytes, 'follow_accepted', $actorId);
            ap_log('follow_accepted by=' . ap_short($actorId));
            // AP does not push history — pull recent outbox notes so Home isn't empty
            ap_outbox_backfill_async($actorId);
            return 'follow_accepted';
        }
        if ($applied === 'follow_accept_ack') {
            ap_metrics_record('Accept', $actorId, $objectId, LOCAL_ACTOR, $bytes, 'follow_accept_ack', null);
            return 'follow_accept_ack';
        }
    }

    // Reject of our relay Follow(as:Public)
    if ($type === 'Reject' && $actorId) {
        $quoteResult = ap_quote_response_handle($activity, 'Reject');
        if ($quoteResult !== null) {
            ap_metrics_record('Reject', $actorId, $objectId, LOCAL_ACTOR, $bytes, $quoteResult, null);
            return $quoteResult;
        }
        if (!function_exists('ap_relay_handle_reject')) {
            require_once __DIR__ . '/ap-relays.php';
        }
        if (function_exists('ap_relay_handle_reject') && ap_relay_handle_reject($activity)) {
            ap_metrics_record('Reject', $actorId, $objectId, LOCAL_ACTOR, $bytes, 'relay_rejected', null);
            return 'relay_rejected';
        }
        $applied = function_exists('ap_follow_response_apply')
            ? ap_follow_response_apply($activity, LOCAL_ACTOR)
            : null;
        if ($applied === 'follow_rejected') {
            ap_metrics_record('Reject', $actorId, $objectId, LOCAL_ACTOR, $bytes, 'follow_rejected', $actorId);
            ap_log('follow_rejected by=' . ap_short((string) $actorId));
            return 'follow_rejected';
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
                // Only the verified sender can undo its own Follow.
                $innerActor = ap_as_id($inner['actor'] ?? null);
                $who = ($innerActor === null || $innerActor === $actorId) ? $actorId : null;
                if ($who) {
                    ap_follower_remove($who, ap_local_actor_id());
                }
                ap_metrics_record('Undo', $actorId, $objectId, ap_local_actor_id(), $bytes, 'local_unfollow', null);
                ap_log('local_unfollow actor=' . ap_short((string) $who) . ' target=' . ap_short(ap_local_actor_id()));
                return 'local_unfollow';
            }
        }
        if (in_array($innerType, ['Like', 'EmojiReact', 'Announce', 'Quote', 'QuotePost'], true)) {
            $innerActor = is_array($inner) ? ap_as_id($inner['actor'] ?? null) : null;
            $who = ($innerActor === null || $innerActor === $actorId) ? $actorId : null;
            $target = ap_as_id($innerObj);
            $kind = ($innerType === 'Announce') ? 'reblog' : (($innerType === 'Like' || $innerType === 'EmojiReact') ? 'like' : 'quote');
            $n = 0;
            if (function_exists('ap_mention_soft_delete_interaction') && $who && $target) {
                $n = ap_mention_soft_delete_interaction($who, $target, $kind, $innerActId);
            } elseif (function_exists('ap_mention_soft_delete_interaction') && $who && $innerActId) {
                $n = ap_mention_soft_delete_interaction((string) $who, '', $kind, $innerActId);
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

    // Remote Delete or Tombstone: soft-delete mentions/DMs AND hide matching firehose Creates.
    // Mastodon Deletes are often bare IDs (no Mention tags / inReplyTo), so
    // ap_activity_touches_local() is false — without this, ghosts stay on Home/Federated.
    // A bare Tombstone activity, or any activity whose object is a Tombstone, is the same removal.
    $deleteObjectType = is_array($activity['object'] ?? null) ? (string) ($activity['object']['type'] ?? '') : '';
    if ($type === 'Delete' || strcasecmp($type, 'Tombstone') === 0 || strcasecmp($deleteObjectType, 'Tombstone') === 0) {
        $deleteIds = [];
        if (is_string($objectId) && $objectId !== '') {
            $deleteIds[] = $objectId;
        }
        if (strcasecmp($type, 'Tombstone') === 0) {
            $selfId = ap_as_id($activity);
            if (is_string($selfId) && $selfId !== '') {
                $deleteIds[] = $selfId;
            }
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
            if (!ap_inbox_object_owned_by_actor((string) $did, is_string($actorId) ? $actorId : null)) {
                ap_log('delete_foreign_object actor=' . ap_short((string) $actorId) . ' object=' . ap_short((string) $did));
                continue;
            }
            // A deleted remote status may have projection rows even when no
            // local mention/event row was materialized. Invalidate by status
            // unconditionally before applying the row-level tombstones.
            if (function_exists('ap_notification_projection_invalidate_status')) {
                ap_notification_projection_invalidate_status((string) $did);
            }
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
            $pendingFollow = $ok
                && function_exists('ap_profile_get')
                && !empty(ap_profile_get((string) ($followTarget['actor_key'] ?? ''))['manually_approves']);
            $followAction = $pendingFollow
                ? 'local_follow_pending'
                : ($ok ? 'local_accept_followback' : 'local_follow_fail');
            ap_metrics_record('Follow', $actorId, $objectId, ap_local_actor_id(), $bytes, $followAction, null);
            if ($ok && !$pendingFollow && is_string($actorId) && $actorId !== '') {
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
            return $followAction;
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
                // A direct message is stored separately and must never be
                // converted into a public interaction notification. Some
                // Misskey/Sharkey-compatible servers have emitted Like/Announce
                // activities for private objects; reject those at ingestion.
                if (is_string($objectId) && $objectId !== ''
                    && function_exists('ap_local_object_is_private')
                    && ap_local_object_is_private($objectId)) {
                    $kind = $type === 'Announce' ? 'reblog' : (($type === 'Like' || $type === 'EmojiReact') ? 'like' : 'quote');
                    if (function_exists('ap_mention_soft_delete_interaction')) {
                        ap_mention_soft_delete_interaction(
                            (string) ($actorId ?? ''),
                            $objectId,
                            $kind,
                            ap_as_id($activity['id'] ?? null)
                        );
                    }
                    ap_metrics_record($type, $actorId, $objectId, LOCAL_ACTOR, $bytes, 'local_private_interaction_skipped', null);
                    ap_log('local_private_interaction_skipped type=' . $type . ' actor=' . ap_short((string) $actorId));
                    return 'local_private_interaction_skipped';
                }
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
                if ($type === 'Update' && is_string($objectId) && $objectId !== '' && ap_inbox_object_owned_by_actor($objectId, is_string($actorId) ? $actorId : null) && function_exists('ap_events_refresh_create_from_update')) {
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
        if ($type === 'Update' && is_string($objectId) && $objectId !== '' && ap_inbox_object_owned_by_actor($objectId, is_string($actorId) ? $actorId : null) && function_exists('ap_events_refresh_create_from_update')) {
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
    // Followers-only Creates/Announces on the firehose are stored without text
    // (privacy). Logging them as timeline `log` rows produces empty
    // "reply to parent post" cards on Federated — skip those stubs.
    // Real followers-only posts from people we follow are handled above via
    // local_followers_only_observe (with content kept).
    if (
        $summary === null
        && in_array($type, ['Create', 'Announce', 'Update', 'Quote', 'QuotePost'], true)
        && ($vis === 'private' || !ap_activity_is_public($activity))
        && (!$mediaForEvent)
    ) {
        ap_metrics_record($type, $actorId, $objectId, LOCAL_ACTOR, $bytes, 'private_firehose_skipped', null);
        ap_log('private_firehose_skipped type=' . $type . ' actor=' . ap_short((string) $actorId));
        return 'private_firehose_skipped';
    }
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
        // Remote Questions: keep the options so timelines can paint the poll.
        ap_remote_poll_store($obj);
        if (($type === 'Announce' || $isQuoteActivity) && isset($obj['object']) && is_array($obj['object'])) {
            ap_remote_poll_store($obj['object']);
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
                ap_remote_poll_store($remote);
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

    // Wafrn-style inbound dedupe: index FEP-fffd / blueskyUri on remote Notes.
    try {
        if (!function_exists('ap_bsky_index_as2_note_links')) {
            $bskyLib = __DIR__ . '/ap-bsky.php';
            if (is_file($bskyLib)) {
                require_once $bskyLib;
            }
        }
        if (function_exists('ap_bsky_index_as2_note_links')) {
            foreach ([$obj, $remote] as $cand) {
                if (!is_array($cand)) {
                    continue;
                }
                $noteDoc = $cand;
                if (isset($cand['object']) && is_array($cand['object'])
                    && in_array((string) ($cand['type'] ?? ''), ['Create', 'Announce', 'Update'], true)) {
                    $noteDoc = $cand['object'];
                }
                if (is_array($noteDoc)) {
                    ap_bsky_index_as2_note_links($noteDoc);
                }
            }
        }
    } catch (Throwable $e) {
        // best-effort
    }

    // Cache custom emoji tags from the Note (and nested object) so timeline
    // bodies can render :shortcode: without a sync host-catalog fetch.
    try {
        if (function_exists('ap_remote_emoji_ingest_actor_doc')) {
            $emojiActor = null;
            if (is_string($objectId) && str_starts_with($objectId, 'https://')) {
                $emojiActor = $objectId;
            }
            $act = ap_as_id($activity['actor'] ?? null);
            if ((!is_string($emojiActor) || $emojiActor === '') && is_string($act) && $act !== '') {
                $emojiActor = $act;
            }
            foreach ([$obj, $remote] as $cand) {
                if (!is_array($cand)) {
                    continue;
                }
                $noteDoc = $cand;
                if (isset($cand['object']) && is_array($cand['object'])
                    && in_array((string) ($cand['type'] ?? ''), ['Create', 'Announce', 'Update'], true)) {
                    $noteDoc = $cand['object'];
                }
                if (!is_array($noteDoc)) {
                    continue;
                }
                $hostRef = $emojiActor;
                $attributed = ap_as_id($noteDoc['attributedTo'] ?? null);
                if (is_string($attributed) && $attributed !== '') {
                    $hostRef = $attributed;
                }
                if (is_string($hostRef) && $hostRef !== '') {
                    ap_remote_emoji_ingest_actor_doc($hostRef, $noteDoc);
                    // Misskey/Sharkey local emoji often arrive without a tag.
                    $noteText = (string) ($noteDoc['content'] ?? '') . ' ' . (string) ($summary ?? '');
                    $emojiHost = strtolower((string) (parse_url($hostRef, PHP_URL_HOST) ?: ''));
                    $noteCodes = ap_emoji_shortcodes_in($noteText);
                    if ($noteCodes !== [] && $emojiHost !== '' && $emojiHost !== 'mkultra.monster'
                        && function_exists('ap_remote_emoji_ensure_codes_deferred')) {
                        ap_remote_emoji_ensure_codes_deferred($emojiHost, $noteCodes);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // best-effort
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
        $fetchCandidates = [];
        if (is_string($fetchUrl) && $fetchUrl !== '') {
            if (function_exists('ap_object_url_lookup_candidates')) {
                $fetchCandidates = ap_object_url_lookup_candidates($fetchUrl);
            }
            if ($fetchCandidates === []) {
                $fetchCandidates = [rtrim($fetchUrl, '/')];
            }
        }
        // Local notes: read from outbox DB (avoid self-HTTP / wrong Accept quirks)
        if ($quotedDoc === null) {
            foreach ($fetchCandidates as $cand) {
                if (str_starts_with($cand, 'https://mkultra.monster/users/') && str_contains($cand, '/notes/')
                    && function_exists('ap_local_note_as2_doc')) {
                    $localDoc = ap_local_note_as2_doc($cand);
                    if (is_array($localDoc)) {
                        $quotedDoc = $localDoc;
                        break;
                    }
                }
            }
        }
        // Already-cached remote Create/Update — rebuild a thin AS2 doc from events
        // so we avoid a second HTTP round-trip when the quoted post is known.
        if (($quotedDoc === null || (!isset($quotedDoc['content']) && !ap_quote_is_tombstone($quotedDoc)))
            && function_exists('ap_event_by_object_id')) {
            foreach ($fetchCandidates as $cand) {
                $ev = ap_event_by_object_id($cand);
                if (!is_array($ev)) {
                    continue;
                }
                $evType = strtolower((string) ($ev['type'] ?? ''));
                if (!in_array($evType, ['create', 'update'], true)) {
                    continue;
                }
                $evSummary = trim((string) ($ev['summary'] ?? ''));
                // Skip summaries that are themselves QT blocks or AS2 dumps
                if ($evSummary === '' || str_contains($evSummary, '↪ QT')
                    || (function_exists('ap_text_looks_like_as2_json') && ap_text_looks_like_as2_json($evSummary))) {
                    continue;
                }
                $actor = (string) ($ev['actor_id'] ?? '');
                $oid = rtrim((string) ($ev['object_id'] ?? $cand), '/');
                $quotedDoc = [
                    'id' => $oid,
                    'type' => 'Note',
                    'attributedTo' => $actor !== '' ? $actor : null,
                    'content' => $evSummary,
                ];
                if ($quotedUrl === null && $oid !== '') {
                    $quotedUrl = $oid;
                }
                break;
            }
        }
        if ($fetchCandidates !== []
            && ($quotedDoc === null || (!isset($quotedDoc['content']) && !ap_quote_is_tombstone($quotedDoc)))) {
            foreach ($fetchCandidates as $cand) {
                $fetched = ap_fetch_as2_object($cand);
                if (!is_array($fetched)) {
                    continue;
                }
                $quotedDoc = $fetched;
                // Prefer canonical Note id when the fetch redirected/resolved
                $fetchedId = ap_as_id($fetched);
                if (is_string($fetchedId) && str_starts_with($fetchedId, 'https://')) {
                    $quotedUrl = rtrim($fetchedId, '/');
                }
                break;
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
            // The quoted post's files belong on the quoted object, not the
            // quoting event. Cache them there when we already have the doc.
            // Only if that cache cannot be written do we keep the old flatten,
            // so a public image is not dropped on the floor.
            $cachedQuoteMedia = false;
            try {
                $cachedQuoteMedia = ap_quote_cache_target_from_doc(
                    is_string($quotedUrl) ? $quotedUrl : null,
                    $quotedDoc
                );
            } catch (Throwable $e) {
                $cachedQuoteMedia = false;
            }
            if (!$cachedQuoteMedia) {
                $media = array_values(array_unique(array_merge($media, ap_extract_media_urls($quotedDoc))));
            }
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
    $objectCacheKey = 'vaak:remote-as2:v1:' . hash('sha256', rtrim($url, '/'));
    $cachedObject = function_exists('ap_redis_json_get') ? ap_redis_json_get($objectCacheKey) : null;
    if (is_array($cachedObject) && (($cachedObject['type'] ?? '') !== '' || isset($cachedObject['id'], $cachedObject['actor']))) {
        return $cachedObject;
    }
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    if ($host !== '' && ap_remote_fetch_circuit_open($host) > time()) {
        return null;
    }
    $preferUnsigned = $host !== '' && (str_ends_with($host, 'brid.gy') || $host === 'brid.gy');

    $bodies = $preferUnsigned
        ? [ap_unsigned_get($url), ap_signed_get($url)]
        : [ap_signed_get($url), ap_unsigned_get($url)];

    foreach ($bodies as $body) {
        $doc = ap_decode_as2_body(is_string($body) ? $body : null);
        if ($doc !== null) {
            ap_remote_fetch_circuit_success($host);
            if (function_exists('ap_redis_json_set')) ap_redis_json_set($objectCacheKey, $doc, 60);
            return $doc;
        }
    }
    ap_remote_fetch_circuit_failure($host);
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
    $doc = ap_fetch_remote_as2($url);
    if (!is_array($doc)) {
        // Misskey/Sharkey often require authorized fetch for AS2 while still
        // exposing public notes via /api/notes/show — use that for boost cards.
        $doc = ap_misskey_note_as2_from_url($url);
    }
    if (is_array($doc)) {
        ap_fetched_note_emoji_ingest($doc);
    }
    return $doc;
}

/**
 * Cache a fetched Note's custom emoji under its author's host, and refresh
 * that host's catalog when the text names shortcodes the note did not tag
 * (Misskey/Sharkey local emoji). Every fetch path goes through here, so boosts,
 * threads and status pages learn emoji, not only inbox Creates.
 */
function ap_fetched_note_emoji_ingest(array $doc): void
{
    if (!function_exists('ap_remote_emoji_ingest_actor_doc')) {
        return;
    }
    try {
        if (isset($doc['object']) && is_array($doc['object'])
            && in_array((string) ($doc['type'] ?? ''), ['Create', 'Announce', 'Update'], true)) {
            $doc = $doc['object'];
        }
        $type = (string) ($doc['type'] ?? '');
        if (!in_array($type, ['Note', 'Article', 'Question', 'Page', 'Event', 'Video', 'Image', 'Audio'], true)) {
            return;
        }
        $author = ap_as_id($doc['attributedTo'] ?? null);
        if (!is_string($author) || !str_starts_with($author, 'https://')) {
            return;
        }
        ap_remote_emoji_ingest_actor_doc($author, $doc);
        $text = (string) ($doc['content'] ?? '') . ' ' . (is_string($doc['summary'] ?? null) ? $doc['summary'] : '');
        $codes = ap_emoji_shortcodes_in($text);
        $host = strtolower((string) (parse_url($author, PHP_URL_HOST) ?: ''));
        if ($codes !== [] && $host !== '' && $host !== 'mkultra.monster') {
            ap_remote_emoji_ensure_codes_deferred($host, $codes);
        }
    } catch (Throwable $e) {
        error_log('[ap-inbox] note emoji ingest: ' . $e->getMessage());
    }
}

/**
 * Misskey/Sharkey note id from https://host/notes/{id} (and /note/{id}).
 */
function ap_misskey_note_id_from_url(string $url): ?string
{
    $url = rtrim(trim($url), '/');
    if ($url === '' || !str_starts_with($url, 'https://')) {
        return null;
    }
    if (preg_match('#/notes?/([a-zA-Z0-9_-]+)(?:/|$|\?)#', $url, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Best-effort AS2 Note synthesized from Misskey/Sharkey /api/notes/show.
 * Used when authorized-fetch blocks unsigned AS2 GETs (common on eepy.moe etc.).
 *
 * @return array<string,mixed>|null
 */
function ap_misskey_note_as2_from_url(string $url): ?array
{
    $url = rtrim(trim($url), '/');
    $noteId = ap_misskey_note_id_from_url($url);
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    if ($noteId === null || $host === '' || $host === 'mkultra.monster') {
        return null;
    }
    if (function_exists('ap_host_resolves_public') && !ap_host_resolves_public($host)) {
        return null;
    }
    $apiUrl = 'https://' . $host . '/api/notes/show';
    $payload = json_encode(['noteId' => $noteId], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return null;
    }
    $ch = curl_init($apiUrl);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: VAAK/1.0 (+https://mkultra.monster/vaak)',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    if (!ap_net_curl_pin($ch, $apiUrl)) {
        curl_close($ch);
        return null;
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = is_string($raw) ? $raw : null;
    if ($status < 200 || $status >= 300 || !is_string($body) || $body === '') {
        return null;
    }
    $note = json_decode($body, true);
    if (!is_array($note) || (string) ($note['id'] ?? '') !== $noteId) {
        return null;
    }
    $visibility = strtolower((string) ($note['visibility'] ?? 'public'));
    // Only hydrate public-ish notes into the firehose / boost cards.
    if (!in_array($visibility, ['public', 'home'], true)) {
        return null;
    }
    $user = is_array($note['user'] ?? null) ? $note['user'] : [];
    $username = trim((string) ($user['username'] ?? ''));
    if ($username === '') {
        return null;
    }
    $userHost = trim((string) ($user['host'] ?? ''));
    $actorHost = $userHost !== '' ? strtolower($userHost) : $host;
    // Prefer an explicit AP uri when Misskey gives one for remote users.
    $actorId = '';
    foreach (['uri', 'url'] as $k) {
        $cand = rtrim((string) ($user[$k] ?? ''), '/');
        if (str_starts_with($cand, 'https://')) {
            $actorId = $cand;
            break;
        }
    }
    if ($actorId === '') {
        $actorId = 'https://' . $actorHost . '/users/' . rawurlencode($username);
    }
    $text = (string) ($note['text'] ?? '');
    $cw = trim((string) ($note['cw'] ?? ''));
    $attachments = [];
    $files = is_array($note['files'] ?? null) ? $note['files'] : [];
    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $mediaUrl = (string) ($file['url'] ?? $file['webpublicUrl'] ?? '');
        if ($mediaUrl === '' || !str_starts_with($mediaUrl, 'https://')) {
            continue;
        }
        $mime = strtolower((string) ($file['type'] ?? ''));
        $mediaType = str_starts_with($mime, 'image/') ? 'Image'
            : (str_starts_with($mime, 'video/') ? 'Video'
            : (str_starts_with($mime, 'audio/') ? 'Audio' : 'Document'));
        $attachments[] = [
            'type' => $mediaType,
            'mediaType' => $mime,
            'url' => $mediaUrl,
            'name' => (string) ($file['comment'] ?? $file['name'] ?? ''),
        ];
    }
    // Plain text → light HTML so existing strip/convert paths stay happy.
    $content = $text !== ''
        ? '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false) . '</p>'
        : '';
    if ($content === '' && $attachments !== []) {
        $content = '<p>(attachment)</p>';
    }
    $published = (string) ($note['createdAt'] ?? '');
    if ($published !== '' && !str_contains($published, 'T')) {
        // already ISO usually
    }
    $inReplyTo = null;
    $reply = $note['reply'] ?? null;
    if (is_array($reply) && !empty($reply['id'])) {
        $replyHost = $host;
        $replyUser = is_array($reply['user'] ?? null) ? $reply['user'] : [];
        $rh = trim((string) ($replyUser['host'] ?? ''));
        if ($rh !== '') {
            $replyHost = strtolower($rh);
        }
        $inReplyTo = 'https://' . $replyHost . '/notes/' . rawurlencode((string) $reply['id']);
    } elseif (!empty($note['replyId']) && is_string($note['replyId'])) {
        $inReplyTo = 'https://' . $host . '/notes/' . rawurlencode((string) $note['replyId']);
    }
    // Quote / renote target (Misskey quote-boost / renote-with-comment).
    $quoteUrl = null;
    $renote = $note['renote'] ?? null;
    if (is_array($renote) && !empty($renote['id'])) {
        $rHost = $host;
        $rUser = is_array($renote['user'] ?? null) ? $renote['user'] : [];
        $rh = trim((string) ($rUser['host'] ?? ''));
        if ($rh !== '') {
            $rHost = strtolower($rh);
        }
        $quoteUrl = 'https://' . $rHost . '/notes/' . rawurlencode((string) $renote['id']);
    } elseif (!empty($note['renoteId']) && is_string($note['renoteId'])) {
        $quoteUrl = 'https://' . $host . '/notes/' . rawurlencode((string) $note['renoteId']);
    }

    // Misskey returns { name: url } (or a list of {name,url}) for emoji it
    // resolved; keep them as AS2 Emoji tags so the cache can learn them.
    $emojiTags = [];
    $noteEmojis = is_array($note['emojis'] ?? null) ? $note['emojis'] : [];
    foreach ($noteEmojis as $k => $v) {
        $name = is_array($v) ? (string) ($v['name'] ?? '') : (is_string($k) ? $k : '');
        $iconUrl = is_array($v) ? (string) ($v['url'] ?? '') : (is_string($v) ? $v : '');
        // "name@host" means another host's emoji; "name@." is the note host's own.
        if (str_ends_with($name, '@.')) {
            $name = substr($name, 0, -2);
        }
        if ($name === '' || str_contains($name, '@') || !str_starts_with($iconUrl, 'https://')) {
            continue;
        }
        $emojiTags[] = [
            'type' => 'Emoji',
            'name' => ':' . $name . ':',
            'icon' => ['type' => 'Image', 'url' => $iconUrl],
        ];
    }

    $doc = [
        'type' => 'Note',
        'id' => 'https://' . $host . '/notes/' . rawurlencode($noteId),
        'attributedTo' => $actorId,
        'content' => $content,
        'summary' => $cw,
        'sensitive' => $cw !== '',
        'published' => $published !== '' ? $published : gmdate('c'),
        'attachment' => $attachments,
        'to' => $visibility === 'public'
            ? ['https://www.w3.org/ns/activitystreams#Public']
            : [],
    ];
    if ($inReplyTo !== null) {
        $doc['inReplyTo'] = $inReplyTo;
    }
    if ($quoteUrl !== null) {
        $doc['quoteUrl'] = $quoteUrl;
        $doc['_misskey_quote'] = $quoteUrl;
    }
    if ($emojiTags !== []) {
        $doc['tag'] = $emojiTags;
    }
    return $doc;
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
    // Federation can arrive through a shared inbox. Resolve the local actor
    // bound to this request instead of assuming the legacy cmdr_nova actor;
    // otherwise a private activity addressed to another local account could
    // be stored in the wrong mailbox.
    $localActor = '';
    $requestActor = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
    if (is_array($requestActor) && !empty($requestActor['id'])) {
        $localActor = rtrim((string) $requestActor['id'], '/');
    }
    if ($localActor === '' && function_exists('ap_local_actor_id')) {
        $localActor = rtrim((string) ap_local_actor_id(), '/');
    }
    if ($localActor === '') {
        $localActor = rtrim((string) LOCAL_ACTOR, '/');
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
        if (rtrim($id, '/') === $localActor) {
            $addressesLocal = true;
        }
    }
    // Do not use ap_activity_touches_local() here: it intentionally detects any
    // local URL in an activity, but a private post can link to a VAAK blog page
    // while being addressed to somebody else. DMs require an actual audience,
    // Mention tag, or content mention for this local actor.
    if (!$addressesLocal && !ap_activity_addresses_local_actor($activity, $localActor)) {
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
    $source = null;
    if (is_array($object)) {
        $source = $object;
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
                $source = $remote;
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
    $spoiler = '';
    $sensitive = false;
    if (is_array($source)) {
        if (isset($source['summary']) && is_string($source['summary'])) {
            $spoiler = $source['summary'];
        }
        $rawSens = $source['sensitive'] ?? false;
        $sensitive = $rawSens === true || $rawSens === 1 || $rawSens === '1' || $rawSens === 'true';
    }
    $attachments = ap_dm_attachments_for_store(
        is_array($attachments) ? $attachments : [],
        $sensitive || trim(strip_tags($spoiler)) !== '',
        $spoiler
    );
    $collectedUrls = $mediaUrls;
    $mediaUrls = [];
    foreach (ap_dm_media_items(['attachments' => $attachments ?? [], 'media_urls' => $collectedUrls]) as $item) {
        $mediaUrls[] = (string) $item['url'];
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

/**
 * Extract a displayable body from an ActivityPub object. Lemmy publishes
 * posts as AS2 Page objects: `name` is the title and `content` is the body.
 * Mastodon-style Notes generally only have content, so keep their historical
 * behavior while preserving Lemmy's title instead of silently dropping it.
 */
function ap_as2_text_summary(array $obj): ?string
{
    $content = isset($obj['content']) && is_string($obj['content'])
        ? trim($obj['content'])
        : '';
    $name = isset($obj['name']) && is_string($obj['name'])
        ? trim($obj['name'])
        : '';
    $type = strtolower(trim((string) ($obj['type'] ?? '')));
    $link = '';
    $attachments = $obj['attachment'] ?? [];
    if (is_array($attachments)
        && (isset($attachments['type']) || isset($attachments['href']) || isset($attachments['url']))) {
        $attachments = [$attachments];
    }
    foreach ((array) $attachments as $attachment) {
        if (!is_array($attachment) || strtolower((string) ($attachment['type'] ?? '')) !== 'link') {
            continue;
        }
        $candidate = is_string($attachment['href'] ?? null)
            ? $attachment['href']
            : (is_string($attachment['url'] ?? null) ? $attachment['url'] : '');
        if (str_starts_with($candidate, 'https://')) {
            $link = $candidate;
            break;
        }
    }
    $appendLink = static function (string $body) use ($link): string {
        if ($link === '' || str_contains(strip_tags($body), $link)) {
            return $body;
        }
        return $body . '<p>' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    };
    if ($content !== '' && $name !== '' && in_array($type, ['page', 'article'], true)) {
        $titlePlain = trim(html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $bodyPlain = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Avoid duplicating a title when a peer has already included it in
        // the first line of the HTML body.
        if ($titlePlain !== '' && !str_starts_with($bodyPlain, $titlePlain)) {
            return $appendLink('<p><strong>'
                . htmlspecialchars($titlePlain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</strong></p>' . $content);
        }
    }
    if ($content !== '') {
        return $appendLink($content);
    }
    if ($name !== '') {
        return $appendLink($name);
    }
    if (isset($obj['summary']) && is_string($obj['summary']) && trim($obj['summary']) !== '') {
        return $obj['summary'];
    }
    return null;
}

function ap_activity_text_summary(array $activity): ?string
{
    $obj = $activity['object'] ?? null;
    if (is_array($obj)) {
        $summary = ap_as2_text_summary($obj);
        if ($summary !== null) {
            return ap_fix_utf8($summary);
        }
        // QuotePost / Quote wrappers sometimes nest the Note
        if (isset($obj['object']) && is_array($obj['object'])) {
            $nested = ap_as2_text_summary($obj['object']);
            if ($nested !== null) {
                return ap_fix_utf8($nested);
            }
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
                    // Rebuild Ask context at read time so already-published
                    // answers are corrected without rewriting outbox rows.
                    $ask = function_exists('ap_ask_answer_for_note') ? ap_ask_answer_for_note($noteId) : null;
                    if (is_array($ask)) {
                        $rep = ap_ask_representation_html($noteId, (string) ($ask['question'] ?? ''), (string) ($ask['asker_actor'] ?? ''));
                        if ($rep !== '' && trim((string) ($ask['question'] ?? '')) !== '') {
                            $oldRep = '';
                            foreach ((array) ($obj['tag'] ?? []) as $tag) {
                                if (is_array($tag) && ($tag['type'] ?? '') === 'AskQuestion') {
                                    $oldRep = (string) ($tag['representation'] ?? '');
                                    break;
                                }
                            }
                            $visible = (string) ($obj['content'] ?? '');
                            if ($oldRep !== '') $visible = str_replace($oldRep, '', $visible);
                            $obj['content'] = $rep . ltrim($visible);
                            $tags = [];
                            foreach ((array) ($obj['tag'] ?? []) as $tag) {
                                if (is_array($tag) && ($tag['type'] ?? '') === 'AskQuestion') {
                                    $tag['representation'] = $rep;
                                }
                                $tags[] = $tag;
                            }
                            $obj['tag'] = $tags;
                        }
                    }
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
    $normalizeQuoteUrl = static function (?string $url): ?string {
        if ($url === null || $url === '' || !str_starts_with($url, 'https://')) {
            return $url;
        }
        if (str_starts_with($url, 'https://bsky.app/') && function_exists('ap_bsky_normalize_web_url')) {
            return ap_bsky_normalize_web_url($url);
        }
        return $url;
    };
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
                $out['url'] = $normalizeQuoteUrl($tid);
            }
            return $out;
        }
        if (is_array($val) && (($val['type'] ?? '') !== '' || isset($val['content']) || isset($val['object']))) {
            $out['embedded'] = $val;
            $id = ap_as_id($val);
            if ($id !== null && str_starts_with($id, 'https://')) {
                $out['url'] = $normalizeQuoteUrl($id);
            }
            // Prefer inner Note id when quote embeds a Create
            $inner = ap_unwrap_as2_object($val);
            $innerId = ap_as_id($inner);
            if ($innerId !== null && str_starts_with($innerId, 'https://')
                && !str_ends_with(rtrim($innerId, '/'), '/activity')) {
                $out['url'] = $normalizeQuoteUrl($innerId);
            }
            return $out;
        }
        $id = ap_as_id($val);
        if ($id !== null && str_starts_with($id, 'https://')) {
            $out['url'] = $normalizeQuoteUrl($id);
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
                    $out['url'] = $normalizeQuoteUrl($href);
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
 * Alternate forms of a status/object URL for cache hits / quote fetch without guessing hosts.
 * Covers trailing slash, /activity wrappers, QuotePost parents, and Mastodon public ↔ AP permalinks.
 *
 * @return list<string>
 */
function ap_object_url_lookup_candidates(string $objectUrl): array
{
    $objectUrl = rtrim(trim($objectUrl), '/');
    if ($objectUrl === '' || !str_starts_with($objectUrl, 'https://')) {
        return [];
    }
    $cands = [$objectUrl];
    if (str_ends_with($objectUrl, '/activity')) {
        $note = substr($objectUrl, 0, -strlen('/activity'));
        if (is_string($note) && $note !== '') {
            $cands[] = rtrim($note, '/');
        }
    }
    // https://host/@user/123 ↔ Mastodon /statuses/ ↔ Pleroma/Akkoma /posts/
    // ↔ Misskey/Sharkey/VAAK /notes/{id} (note ids are often opaque alphanumerics)
    if (preg_match('#^(https://[^/]+)/@([^/]+)/([A-Za-z0-9_-]+)$#', $objectUrl, $m)) {
        $host = $m[1];
        $user = rawurlencode($m[2]);
        $id = $m[3];
        $cands[] = $host . '/users/' . $user . '/statuses/' . $id;
        $cands[] = $host . '/ap/users/' . $user . '/statuses/' . $id;
        $cands[] = $host . '/users/' . $user . '/posts/' . $id;
        $cands[] = $host . '/ap/users/' . $user . '/posts/' . $id;
        $cands[] = $host . '/users/' . $user . '/notes/' . $id;
        $cands[] = $host . '/notes/' . $id;
    }
    if (preg_match('#^(https://[^/]+)/(?:ap/)?users/([^/]+)/(statuses|posts|notes)/([A-Za-z0-9_-]+)$#', $objectUrl, $m)) {
        $host = $m[1];
        $userEnc = $m[2];
        $user = rawurldecode($m[2]);
        $id = $m[4];
        $cands[] = $host . '/@' . $user . '/' . $id;
        foreach (['statuses', 'posts', 'notes'] as $kind) {
            $cands[] = $host . '/users/' . $userEnc . '/' . $kind . '/' . $id;
            $cands[] = $host . '/ap/users/' . $userEnc . '/' . $kind . '/' . $id;
        }
        $cands[] = $host . '/notes/' . $id;
    }
    // Bare Misskey/Sharkey/VAAK note URLs
    if (preg_match('#^(https://[^/]+)/notes/([A-Za-z0-9_-]+)$#', $objectUrl, $m)) {
        $host = $m[1];
        $id = $m[2];
        $cands[] = $host . '/notes/' . $id;
        // Without a username we cannot invent /@user/id, but statuses/posts
        // under a placeholder user are not useful — keep the notes form only.
    }
    $parent = ap_quote_post_parent_url($objectUrl);
    if (is_string($parent) && $parent !== '') {
        $cands[] = rtrim($parent, '/');
    }
    $out = [];
    foreach ($cands as $c) {
        $c = rtrim((string) $c, '/');
        if ($c !== '' && str_starts_with($c, 'https://') && !isset($out[$c])) {
            $out[$c] = true;
        }
    }
    return array_keys($out);
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
    if ($t === '') {
        return false;
    }
    // Prefixed dumps from broken quote enrichment: "↪ QTCreate Note\n{@context":…}"
    if (preg_match('/↪\s*QT(Create|Announce|Update|Note|QuotePost)\b/u', $t)
        || str_contains($t, 'QuoteAuthorization')
        || str_contains($t, 'interactionPolicy')) {
        return true;
    }
    if ($t[0] !== '{' && $t[0] !== '[') {
        return false;
    }
    return str_contains($t, '"@context"')
        || str_contains($t, '"type"') && str_contains($t, '"attributedTo"')
        || str_contains($t, 'activitystreams');
}

/**
 * Strip dumped AS2 / glued QTCreate blocks from feed text, keeping leading commentary.
 * Returns empty string when the whole blob is unusable.
 */
function ap_text_scrub_as2_dump(string $text): string
{
    $text = trim($text);
    if ($text === '' || !ap_text_looks_like_as2_json($text)) {
        return $text;
    }
    if (preg_match('/^(.*?)(?:\n\n↪\s*QT|\n↪\s*QT)/us', $text, $m)) {
        $keep = trim((string) ($m[1] ?? ''));
        if ($keep !== '' && !ap_text_looks_like_as2_json($keep)) {
            return $keep;
        }
    }
    return '';
}

/**
 * Card-only policy for quotes/quote-boosts: keep commentary, drop every raw
 * ↪ QT / RE:<url> / AS2 dump line. Optional $quotedPlain clears body text that
 * merely duplicates the nested quote card.
 */
function ap_plain_strip_raw_quote_lines(string $plain, ?string $quotedPlain = null): string
{
    $plain = trim($plain);
    if ($plain === '') {
        return '';
    }
    if (ap_text_looks_like_as2_json($plain)) {
        $plain = ap_text_scrub_as2_dump($plain);
        if ($plain === '') {
            return '';
        }
    }
    // Normalize glued AS2 type names (QTCreate / QTNote) before cutting —
    // QT\b alone misses those and left dumps in the body.
    $plain = preg_replace(
        '/(?:^|\n)\s*(?:↪|➡|→)?\s*QT(?=Create|Announce|Update|Note|QuotePost|\b)/u',
        "\n↪ QT",
        $plain
    ) ?? $plain;
    $plain = trim($plain);
    // Cut from the first QT marker to end (card owns that payload).
    if (preg_match('/^(.*?)(?:\n\n|\n)\s*(?:↪|➡|→)\s*QT(?:Create|Announce|Update|Note|QuotePost)?\b.*$/us', $plain, $m)) {
        $plain = trim((string) ($m[1] ?? ''));
    } elseif (preg_match('/^\s*(?:↪|➡|→)\s*QT(?:Create|Announce|Update|Note|QuotePost)?\b/u', $plain)) {
        $plain = '';
    } else {
        // Belts: any remaining QT line (odd separators / single-line bodies).
        $plain = preg_replace('/(?:^|\n)\s*(?:↪|➡|→)\s*QT(?:Create|Announce|Update|Note|QuotePost)?\b[^\n]*/u', '', $plain) ?? $plain;
        $plain = trim($plain);
    }
    $plain = preg_replace('/(?:^|\n)\s*RE:\s*https:\/\/[^\s<>]+/u', '', $plain) ?? $plain;
    $plain = trim($plain);
    if ($plain !== '' && is_string($quotedPlain)) {
        $q = trim($quotedPlain);
        if ($q !== '' && (strcasecmp($plain, $q) === 0 || str_starts_with($plain, $q) || str_contains($plain, $q))) {
            // Body is only repeating what the nest already shows.
            if (strcasecmp($plain, $q) === 0 || trim(str_replace($q, '', $plain)) === '') {
                $plain = '';
            }
        }
    }
    return trim($plain);
}

/**
 * Build a plain-text feed preview for a quote: commentary + quoted snippet.
 * Handles Create wrappers, Tombstones, and never embeds raw AS2 JSON.
 */
function ap_format_quote_feed_summary(?string $commentary, ?array $quotedDoc, ?string $quotedUrl = null, bool $tombstone = false): ?string
{
    $parts = [];
    $c = '';
    if ($commentary !== null) {
        $c = function_exists('ap_html_to_plain_text')
            ? trim(ap_html_to_plain_text(ap_fix_utf8($commentary)))
            : trim(html_entity_decode(strip_tags(ap_fix_utf8($commentary)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
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
            $qText = function_exists('ap_html_to_plain_text')
                ? trim(ap_html_to_plain_text(ap_fix_utf8($quotedDoc['content'])))
                : trim(html_entity_decode(strip_tags(ap_fix_utf8($quotedDoc['content'])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
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
    $combined = implode("\n\n", $parts);
    // Keep the quote target address on the summary. Render looks it up from
    // this line; the QT excerpt itself is often text with no URL.
    if (is_string($quotedUrl) && str_starts_with($quotedUrl, 'https://')
        && !str_contains($combined, $quotedUrl)) {
        $combined = 'RE: ' . rtrim($quotedUrl, '/') . "\n\n" . $combined;
    }
    return $combined;
}

/**
 * Remember a quoted Note's attachments on that Note's cached event.
 * Uses the document already fetched for the quote preview. No extra HTTP.
 * Returns true when those attachments are stored on the quoted object, so the
 * quoting event must not also keep them.
 *
 * @param array<string,mixed> $quotedDoc
 */
function ap_quote_cache_target_from_doc(?string $quotedUrl, array $quotedDoc): bool
{
    if (function_exists('ap_unwrap_as2_object')) {
        $quotedDoc = ap_unwrap_as2_object($quotedDoc);
    }
    $canonical = '';
    if (function_exists('ap_as_id')) {
        $canonical = (string) (ap_as_id($quotedDoc['id'] ?? null) ?: '');
    }
    if ($canonical === '' && is_string($quotedUrl)) {
        $canonical = $quotedUrl;
    }
    $canonical = rtrim(trim($canonical), '/');
    if ($canonical === '' || !str_starts_with($canonical, 'https://')) {
        return false;
    }
    if (str_contains($canonical, 'bsky.app/') || str_contains($canonical, 'bsky.brid.gy')) {
        return false;
    }
    if (function_exists('ap_quote_target_is_bluesky') && ap_quote_target_is_bluesky($canonical)) {
        return false;
    }
    $media = ap_extract_media_urls($quotedDoc);
    if ($media === []) {
        return false;
    }
    $candidates = [$canonical];
    if (is_string($quotedUrl) && str_starts_with($quotedUrl, 'https://')) {
        $candidates[] = rtrim($quotedUrl, '/');
    }
    if (function_exists('ap_object_url_lookup_candidates')) {
        foreach ($candidates as $cand) {
            foreach (ap_object_url_lookup_candidates($cand) as $alt) {
                $candidates[] = rtrim($alt, '/');
            }
        }
    }
    $candidates = array_values(array_unique(array_filter($candidates, static function ($cand): bool {
        return is_string($cand) && str_starts_with($cand, 'https://');
    })));
    $existing = null;
    if (function_exists('ap_event_by_object_id')) {
        foreach ($candidates as $cand) {
            $row = ap_event_by_object_id($cand);
            if (is_array($row)) {
                $existing = $row;
                break;
            }
        }
    }
    $mediaJson = json_encode(array_values($media), JSON_UNESCAPED_SLASHES);
    if (!is_string($mediaJson) || $mediaJson === '' || $mediaJson === '[]') {
        return false;
    }
    if (is_array($existing)) {
        $old = trim((string) ($existing['media_urls'] ?? ''));
        if ($old !== '' && $old !== '[]' && $old !== 'null') {
            return true;
        }
        $eventId = (int) ($existing['id'] ?? 0);
        if ($eventId < 1 || !function_exists('ap_db')) {
            return false;
        }
        ap_db()->prepare('UPDATE events SET media_urls = ? WHERE id = ? AND (media_urls IS NULL OR media_urls = \'\' OR media_urls = \'[]\' OR media_urls = \'null\')')
            ->execute([$mediaJson, $eventId]);
        return true;
    }
    $visibility = function_exists('ap_visibility_from_activity')
        ? ap_visibility_from_activity($quotedDoc)
        : 'public';
    if (!in_array($visibility, ['public', 'unlisted'], true) || !function_exists('ap_metrics_record')) {
        return false;
    }
    $actor = null;
    if (function_exists('ap_as_id')) {
        $actor = ap_as_id($quotedDoc['attributedTo'] ?? null) ?: ap_as_id($quotedDoc['actor'] ?? null);
    }
    if (!is_string($actor) || !str_starts_with($actor, 'https://')) {
        return false;
    }
    $summary = function_exists('ap_as2_text_summary') ? ap_as2_text_summary($quotedDoc) : null;
    if (!is_string($summary) || trim(strip_tags($summary)) === '') {
        $summary = is_string($quotedDoc['content'] ?? null) ? $quotedDoc['content'] : '(attachment)';
    }
    $inReplyTo = function_exists('ap_as_id') ? ap_as_id($quotedDoc['inReplyTo'] ?? null) : null;
    if (!is_string($inReplyTo) || !str_starts_with($inReplyTo, 'https://')) {
        $inReplyTo = null;
    }
    ap_metrics_record(
        'Create',
        $actor,
        $canonical,
        null,
        0,
        'local_observe',
        is_string($summary) ? $summary : null,
        $media,
        is_string($quotedDoc['published'] ?? null) ? $quotedDoc['published'] : null,
        $inReplyTo,
        is_string($quotedDoc['summary'] ?? null) ? $quotedDoc['summary'] : '',
        !empty($quotedDoc['sensitive']),
        $visibility
    );
    return true;
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
    $isLocalFollower = (bool) preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $follower);
    if ($isLocalFollower) {
        // Same instance: update the graphs here. Do not POST Accept/Reject to our own inbox.
        $ok = true;
    } else {
        $ok = ap_deliver_signed_json($inbox, $activity, ap_local_key_id(), ap_local_priv_path(), 5.0);
        if (!$ok && $sharedInbox !== '' && $sharedInbox !== $inbox) {
            $ok = ap_deliver_signed_json($sharedInbox, $activity, ap_local_key_id(), ap_local_priv_path(), 5.0);
        }
    }
    if ($approve) {
        ap_follower_upsert($follower, $inbox, $sharedInbox, (string) ($request['username'] ?? ''), $localId);
        if ($isLocalFollower && function_exists('ap_following_upsert')) {
            // The requester's following row is created only once the private account approves.
            ap_following_upsert($localId, $follower, 'accepted');
        }
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
        // vaak.monster only serves the app. Local people live on mkultra.monster.
        // Resolve those handles here so search never WebFingers the mask.
        $localKey = strtolower(str_replace('-', '_', $user));
        $localKey = preg_replace('/[^a-z0-9_]/', '', $localKey) ?? '';
        $ourHost = in_array($host, ['mkultra.monster', 'www.mkultra.monster', 'vaak.monster', 'www.vaak.monster'], true);
        if ($ourHost && $localKey !== '') {
            if (!function_exists('ap_local_user_by_username') && is_file(__DIR__ . '/ap-db.php')) {
                require_once __DIR__ . '/ap-db.php';
            }
            $localRow = function_exists('ap_local_user_by_username')
                ? ap_local_user_by_username($localKey)
                : null;
            if (is_array($localRow) || $localKey === 'cmdr_nova') {
                $key = is_array($localRow) ? (string) ($localRow['actor_key'] ?? $localKey) : 'cmdr_nova';
                return 'https://mkultra.monster/users/' . rawurlencode($key !== '' ? $key : $localKey);
            }
            // The mask is not a federated instance. Unknown @user@mkultra.monster
            // still falls through (Bridgy aliases such as val3r1e).
            if ($host === 'vaak.monster' || $host === 'www.vaak.monster') {
                return null;
            }
        }
        if (!ap_host_resolves_public($host)) {
            return null;
        }
        $acct = strtolower($user) . '@' . $host;

        // 1) Acct → actor_id Redis (search / WebFinger coalesce).
        if (function_exists('ap_remote_actor_acct_get')) {
            $cachedId = ap_remote_actor_acct_get($acct);
            if (is_string($cachedId) && $cachedId !== '') {
                return $cachedId;
            }
        }

        // 2) Local PG by username+host (no network).
        if (function_exists('ap_remote_actor_id_by_acct')) {
            $fromPg = ap_remote_actor_id_by_acct($user, $host);
            if (is_string($fromPg) && $fromPg !== '') {
                if (function_exists('ap_remote_actor_acct_put')) {
                    ap_remote_actor_acct_put($acct, $fromPg);
                }
                return $fromPg;
            }
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

        $doWebFinger = static function () use ($wfUrl, $parseWf, $acct): ?string {
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
            // No file_get_contents fallback: the stream wrapper resolves the
            // name itself, so it cannot be pinned to the checked addresses.
            if ($href === null) {
                // Last resort: signed GET (some locked-down hosts)
                $href = $parseWf(ap_signed_get($wfUrl));
            }
            if (is_string($href) && $href !== '' && function_exists('ap_remote_actor_acct_put')) {
                ap_remote_actor_acct_put($acct, $href);
            }
            return $href;
        };

        // 3) Stampede-locked WebFinger — one peer fetches; others wait on acct Redis.
        $lockKey = 'webfinger:' . hash('sha256', $acct);
        $gotLock = function_exists('ap_redis_lock') && ap_redis_lock($lockKey, 15);
        if ($gotLock) {
            try {
                // Re-check after lock (peer may have filled while we contended).
                if (function_exists('ap_remote_actor_acct_get')) {
                    $again = ap_remote_actor_acct_get($acct);
                    if (is_string($again) && $again !== '') {
                        return $again;
                    }
                }
                return $doWebFinger();
            } finally {
                if (function_exists('ap_redis_unlock')) {
                    ap_redis_unlock($lockKey);
                }
            }
        }

        if (function_exists('ap_redis_stampede_wait') && function_exists('ap_remote_actor_acct_get')) {
            $peer = ap_redis_stampede_wait(
                static function () use ($acct) {
                    return ap_remote_actor_acct_get($acct);
                },
                2500
            );
            if (is_string($peer) && $peer !== '') {
                return $peer;
            }
        }

        // Lock unavailable / wait timed out — fetch ourselves.
        return $doWebFinger();
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
function ap_follow_remote_actor(string $actorId, bool $respectRateLimit = true, ?string $stableFollowId = null): array
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

    // Suggestions and older cached account rows may use VAAK's HTML alias
    // (/@user) or Mastodon-compatible /ap/users/user form. Normalize local
    // aliases before attempting a network fetch so local follows never depend
    // on the public HTML content-negotiation path.
    $resolvedHost = strtolower((string) (parse_url($actorId, PHP_URL_HOST) ?: ''));
    if ($resolvedHost === 'mkultra.monster') {
        $resolvedPath = (string) (parse_url($actorId, PHP_URL_PATH) ?: '');
        if (preg_match('#^/@([A-Za-z][A-Za-z0-9_]{1,29})/?$#', $resolvedPath, $lm)
            || preg_match('#^/ap/users/([A-Za-z][A-Za-z0-9_]{1,29})/?$#', $resolvedPath, $lm)) {
            $actorId = 'https://mkultra.monster/users/' . rawurlencode($lm[1]);
        }
    }

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
    $backId = is_string($stableFollowId) && str_starts_with($stableFollowId, $localId . '/follows/')
        ? $stableFollowId : $localId . '/follows/' . bin2hex(random_bytes(10));

    // Same-instance local actor: update graphs only (no HTTP to ourselves).
    if (preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $targetId, $lm)) {
        $targetKey = (string) $lm[1];
        $sessionKey = '';
        if (preg_match('#/users/([A-Za-z0-9_]+)$#', rtrim($localId, '/'), $sm)) {
            $sessionKey = (string) $sm[1];
        }
        if ($targetKey !== '' && $targetKey !== $sessionKey) {
            $sessionName = $sessionKey !== '' ? $sessionKey : basename(parse_url($localId, PHP_URL_PATH) ?: '');
            if (function_exists('ap_actor_requires_follow_approval') && ap_actor_requires_follow_approval($targetId)) {
                if (function_exists('ap_follow_request_pending') && ap_follow_request_pending($targetId, $localId)) {
                    ap_log('follow_local_pending_already target=' . ap_short($targetId) . ' from=' . ap_short($localId));
                    return ['ok' => true, 'follow_id' => null, 'already' => true, 'requested' => true];
                }
                $stored = function_exists('ap_follow_request_upsert')
                    ? ap_follow_request_upsert($targetId, $localId, $localId . '/inbox', null, $sessionName, $backId)
                    : false;
                if (!$stored) {
                    return ['ok' => false, 'error' => 'Could not store the follow request'];
                }
                ap_metrics_record('Follow', $localId, $backId, $targetId, 0, 'manual_follow_request', $targetId);
                ap_log('follow_local_pending target=' . ap_short($targetId) . ' from=' . ap_short($localId));
                return ['ok' => true, 'follow_id' => $backId, 'already' => false, 'requested' => true];
            }
            ap_following_upsert($targetId, $localId, 'accepted');
            ap_follower_upsert(
                $localId,
                $localId . '/inbox',
                null,
                $sessionName,
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
    ap_following_upsert($targetId, $localId, 'pending', $backId);
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
 * @param array{id?:string,key_id?:string,priv?:string}|null $ident Optional local signing identity (multi-user / fan-out).
 * @return array{ok:bool,error?:string,already?:bool}
 */
function ap_unfollow_remote_actor(
    string $actorId,
    ?string $followActivityId = null,
    ?string $stableUndoId = null,
    ?array $ident = null,
    bool $forceDeliver = false
): array {
    $rawInput = trim($actorId);
    $resolved = ap_resolve_actor_ref($rawInput);
    if ($resolved === null && str_starts_with($rawInput, 'https://')) {
        $resolved = rtrim($rawInput, '/');
    }
    if ($resolved === null) {
        return ['ok' => false, 'error' => 'Could not resolve actor to unfollow'];
    }
    $targetId = rtrim($resolved, '/');
    $ident = is_array($ident) && !empty($ident['id']) && !empty($ident['key_id']) && !empty($ident['priv'])
        ? $ident
        : ap_outbound_identity();
    $localId = rtrim((string) ($ident['id'] ?? ''), '/');
    if ($localId === '' || !str_starts_with($localId, 'https://')) {
        return ['ok' => false, 'error' => 'Not signed in as a local account'];
    }

    $wantAliases = [$targetId => true];
    if (function_exists('ap_masto_actor_id_aliases')) {
        foreach (ap_masto_actor_id_aliases($targetId) as $al) {
            $al = rtrim((string) $al, '/');
            if ($al !== '') {
                $wantAliases[$al] = true;
            }
        }
    }
    if (function_exists('ap_actor_moderation_aliases')) {
        foreach (ap_actor_moderation_aliases($targetId) as $al) {
            $al = rtrim((string) $al, '/');
            if ($al !== '' && str_starts_with($al, 'https://')) {
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
    if (!$isFollowing && !$forceDeliver) {
        foreach (array_keys($wantAliases) as $a) {
            ap_following_remove($a, $localId);
            if (function_exists('ap_follow_request_remove')
                && preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $a)) {
                ap_follow_request_remove($a, $localId);
            }
        }
        return ['ok' => true, 'already' => true];
    }
    if (!$isFollowing && $forceDeliver) {
        // Local row already gone (e.g. server-block side effects); still notify remote.
        $targetId = array_key_first($wantAliases) ?: $targetId;
    }

    // Same-instance: drop both following + follower rows; skip remote Undo.
    if (preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $targetId)) {
        ap_following_remove($targetId, $localId);
        ap_follower_remove($localId, $targetId);
        if (function_exists('ap_follow_request_remove')) {
            ap_follow_request_remove($targetId, $localId);
        }
        ap_metrics_record('Undo', $localId, $targetId, $targetId, 0, 'manual_unfollow_local', null);
        ap_log('unfollow_local_ok target=' . ap_short($targetId) . ' from=' . ap_short($localId));
        return ['ok' => true, 'already' => false];
    }

    $doc = ap_fetch_actor_doc($targetId);
    $personalInbox = is_array($doc) ? ap_resolve_personal_inbox_from_actor_doc($doc) : null;
    $sharedInbox = is_array($doc) ? ap_resolve_inbox_from_actor_doc($doc) : null;
    $inbox = $personalInbox ?: $sharedInbox;

    // The durable interaction worker must not mark an unfollow complete until
    // the remote Undo has been accepted. Keep local graph state for retry.
    // forceDeliver (server-block fan-out) may target a freshly domain-blocked host.
    if (!$inbox || !str_starts_with($inbox, 'https://')) {
        // Still clear local following when we cannot deliver.
        foreach (array_keys($wantAliases) as $a) {
            ap_following_remove($a, $localId);
        }
        return ['ok' => false, 'error' => 'Remote inbox unavailable for Follow undo'];
    }
    if (!$forceDeliver && ap_is_blocked_inbox($inbox)) {
        foreach (array_keys($wantAliases) as $a) {
            ap_following_remove($a, $localId);
        }
        return ['ok' => false, 'error' => 'Remote inbox unavailable for Follow undo'];
    }
    {
        $undoId = is_string($stableUndoId) && str_starts_with($stableUndoId, $localId . '/undos/')
            ? $stableUndoId : $localId . '/undos/' . bin2hex(random_bytes(10));
        $followId = is_string($followActivityId) && str_starts_with($followActivityId, $localId . '/follows/')
            ? $followActivityId : $localId . '/follows/undo-' . bin2hex(random_bytes(6));
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
        if (!ap_deliver_signed_json($inbox, $undo, $ident['key_id'], $ident['priv'], 5.0)) {
            return ['ok' => false, 'error' => 'Could not deliver Follow undo to remote inbox'];
        }
    }

    ap_following_remove($targetId, $localId);
    ap_metrics_record('Undo', $localId, $targetId, $targetId, 0, 'manual_unfollow', null);
    ap_log('unfollow_remote_ok target=' . ap_short($targetId));
    return ['ok' => true, 'already' => false];
}

/**
 * Federate a personal Block (Mastodon-compatible). Required for Bridgy Fed opt-out:
 * blocking @bsky.brid.gy@bsky.brid.gy must deliver an ActivityPub Block.
 *
 * Also severs local follow relationships with the target for this actor.
 *
 * @return array{ok:bool,error?:string,delivered?:bool,already_local?:bool}
 */
/**
 * Signing identity for a local /users/{key} actor (optional override for fan-out).
 *
 * @return array{id:string,key_id:string,priv:string}|null
 */
function ap_identity_for_local_actor(string $localActorId): ?array
{
    $localActorId = rtrim(trim($localActorId), '/');
    if ($localActorId === '' || !preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $localActorId, $m)) {
        return null;
    }
    $actorKey = strtolower($m[1]);
    $paths = function_exists('ap_actor_key_paths')
        ? ap_actor_key_paths($actorKey)
        : [
            'private' => '/etc/mkultra/ap-inbox/' . $actorKey . '_private.pem',
            'public' => '/etc/mkultra/ap-inbox/' . $actorKey . '_public.pem',
        ];
    $priv = (string) ($paths['private'] ?? '');
    if ($priv === '' || !is_file($priv)) {
        return null;
    }
    $id = 'https://mkultra.monster/users/' . $actorKey;
    return [
        'id' => $id,
        'key_id' => $id . '#main-key',
        'priv' => $priv,
    ];
}

/**
 * Local actors that should notify a remote of an instance-wide Block.
 * Prefer accounts with a follow relationship; always include the current session actor.
 *
 * @return list<string>
 */
function ap_local_actors_for_instance_block_fanout(string $targetActorId): array
{
    $targetActorId = rtrim(trim($targetActorId), '/');
    $alt = $targetActorId . '/';
    $out = [];
    $add = static function (string $id) use (&$out): void {
        $id = rtrim($id, '/');
        if ($id !== '' && str_starts_with($id, 'https://mkultra.monster/users/') && !isset($out[$id])) {
            $out[$id] = true;
        }
    };
    $session = rtrim((string) (ap_outbound_identity()['id'] ?? ''), '/');
    if ($session !== '') {
        $add($session);
    }
    try {
        $st = ap_db()->prepare(
            'SELECT DISTINCT owner_actor_id AS a FROM followers WHERE actor_id = ? OR actor_id = ?
             UNION
             SELECT DISTINCT owner_actor_id AS a FROM following WHERE actor_id = ? OR actor_id = ?'
        );
        $st->execute([$targetActorId, $alt, $targetActorId, $alt]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $a) {
            $add((string) $a);
        }
        // Instance-wide: also notify from every local account that has keys (full server block).
        $users = ap_db()->query(
            "SELECT actor_id FROM ap_users
             WHERE actor_id LIKE 'https://mkultra.monster/users/%'
             ORDER BY id ASC
             LIMIT 80"
        );
        foreach ($users->fetchAll(PDO::FETCH_COLUMN) ?: [] as $a) {
            $add((string) $a);
        }
    } catch (Throwable $e) {
        // keep session actor at least
    }
    return array_keys($out);
}

/**
 * Background Undo(Follow) fan-out for pairs collected before a server block.
 * Job shape: { pairs: list<{local:string,target:string}>, created_at: string }
 *
 * @param list<array{local:string,target:string}> $pairs
 * @return array{ok:bool,queued:bool,attempted:int,delivered:int}
 */
function ap_instance_unfollow_pairs_background(array $pairs): array
{
    $clean = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $local = rtrim(trim((string) ($pair['local'] ?? '')), '/');
        $target = rtrim(trim((string) ($pair['target'] ?? '')), '/');
        if ($local === '' || $target === ''
            || !str_starts_with($local, 'https://mkultra.monster/users/')
            || !str_starts_with($target, 'https://')) {
            continue;
        }
        // Bluesky follows are severed via ap_bsky_unfollow_actor, not AP Undo.
        if (str_contains(strtolower($target), 'bsky.app/profile/') || str_starts_with($target, 'did:')) {
            continue;
        }
        $clean[] = ['local' => $local, 'target' => $target];
    }
    if ($clean === []) {
        return ['ok' => true, 'queued' => false, 'attempted' => 0, 'delivered' => 0];
    }
    if (PHP_SAPI === 'cli' && defined('AP_INSTANCE_BLOCK_WORKER')) {
        return ap_instance_unfollow_pairs($clean);
    }
    $dir = '/tmp/ap-instance-unfollow-jobs';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return ap_instance_unfollow_pairs($clean);
    }
    $file = $dir . '/' . bin2hex(random_bytes(8)) . '.json';
    $job = ['pairs' => $clean, 'created_at' => gmdate('c')];
    if (@file_put_contents($file, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
        return ap_instance_unfollow_pairs($clean);
    }
    @chmod($file, 0600);
    $php = function_exists('ap_php_cli_binary') ? ap_php_cli_binary() : 'php';
    $worker = __DIR__ . '/ap-instance-unfollow-worker.php';
    if (!is_file($worker)) {
        @unlink($file);
        return ap_instance_unfollow_pairs($clean);
    }
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($file)
        . ' >>/tmp/vaak-instance-unfollow.log 2>&1 &';
    @exec($cmd);
    return ['ok' => true, 'queued' => true, 'attempted' => count($clean), 'delivered' => 0];
}

/**
 * @param list<array{local:string,target:string}> $pairs
 * @return array{ok:bool,queued:bool,attempted:int,delivered:int}
 */
function ap_instance_unfollow_pairs(array $pairs): array
{
    $attempted = 0;
    $delivered = 0;
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $local = rtrim(trim((string) ($pair['local'] ?? '')), '/');
        $target = rtrim(trim((string) ($pair['target'] ?? '')), '/');
        if ($local === '' || $target === '') {
            continue;
        }
        $ident = ap_identity_for_local_actor($local);
        if ($ident === null) {
            // Still drop local row if keys are missing.
            if (function_exists('ap_following_remove')) {
                ap_following_remove($target, $local);
            }
            continue;
        }
        $attempted++;
        $res = ap_unfollow_remote_actor($target, null, null, $ident, true);
        if (!empty($res['ok'])) {
            $delivered++;
        } elseif (function_exists('ap_following_remove')) {
            // Ensure VAAK following list is clean even if Undo delivery failed.
            ap_following_remove($target, $local);
        }
    }
    return ['ok' => true, 'queued' => false, 'attempted' => $attempted, 'delivered' => $delivered];
}

/**
 * Federate Block/Undo from all relevant local actors for a server-wide actor block.
 *
 * @return array{ok:bool,attempted:int,delivered:int}
 */
function ap_instance_federate_actor_block(string $targetActorId, bool $blocking = true): array
{
    $targetActorId = rtrim(trim($targetActorId), '/');
    if ($targetActorId === '' || !str_starts_with($targetActorId, 'https://')) {
        return ['ok' => false, 'attempted' => 0, 'delivered' => 0];
    }
    $attempted = 0;
    $delivered = 0;
    foreach (ap_local_actors_for_instance_block_fanout($targetActorId) as $localId) {
        $ident = ap_identity_for_local_actor($localId);
        if ($ident === null) {
            continue;
        }
        $attempted++;
        $res = $blocking
            ? ap_block_remote_actor($targetActorId, $ident)
            : ap_unblock_remote_actor($targetActorId, $ident);
        if (!empty($res['delivered'])) {
            $delivered++;
        }
    }
    ap_log(
        'instance_block_fanout target=' . ap_short($targetActorId)
        . ' blocking=' . ($blocking ? '1' : '0')
        . ' attempted=' . $attempted
        . ' delivered=' . $delivered
    );
    return ['ok' => true, 'attempted' => $attempted, 'delivered' => $delivered];
}

/**
 * Queue instance Block/Undo fan-out so admin UI is not blocked on N× HTTP delivers.
 * Local ap_blocks row + inbound 403 + graph side effects already apply before this runs.
 *
 * @return array{ok:bool,queued:bool,attempted:int,delivered:int}
 */
function ap_instance_federate_actor_block_background(string $targetActorId, bool $blocking = true): array
{
    $targetActorId = rtrim(trim($targetActorId), '/');
    if ($targetActorId === '' || !str_starts_with($targetActorId, 'https://')) {
        return ['ok' => false, 'queued' => false, 'attempted' => 0, 'delivered' => 0];
    }
    if (PHP_SAPI === 'cli' && defined('AP_INSTANCE_BLOCK_WORKER')) {
        $fed = ap_instance_federate_actor_block($targetActorId, $blocking);
        return [
            'ok' => !empty($fed['ok']),
            'queued' => false,
            'attempted' => (int) ($fed['attempted'] ?? 0),
            'delivered' => (int) ($fed['delivered'] ?? 0),
        ];
    }
    $dir = '/tmp/ap-instance-block-jobs';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        ap_log('instance_block_bg_mkdir_fail');
        // Last resort: sync (admin waits, but block still federates).
        $fed = ap_instance_federate_actor_block($targetActorId, $blocking);
        return [
            'ok' => !empty($fed['ok']),
            'queued' => false,
            'attempted' => (int) ($fed['attempted'] ?? 0),
            'delivered' => (int) ($fed['delivered'] ?? 0),
        ];
    }
    $job = [
        'target' => $targetActorId,
        'blocking' => $blocking ? 1 : 0,
        'created_at' => gmdate('c'),
    ];
    $file = $dir . '/' . bin2hex(random_bytes(8)) . '.json';
    if (@file_put_contents($file, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
        ap_log('instance_block_bg_write_fail');
        $fed = ap_instance_federate_actor_block($targetActorId, $blocking);
        return [
            'ok' => !empty($fed['ok']),
            'queued' => false,
            'attempted' => (int) ($fed['attempted'] ?? 0),
            'delivered' => (int) ($fed['delivered'] ?? 0),
        ];
    }
    @chmod($file, 0600);
    $php = ap_php_cli_binary();
    $worker = __DIR__ . '/ap-instance-block-worker.php';
    if (!is_file($worker)) {
        @unlink($file);
        $fed = ap_instance_federate_actor_block($targetActorId, $blocking);
        return [
            'ok' => !empty($fed['ok']),
            'queued' => false,
            'attempted' => (int) ($fed['attempted'] ?? 0),
            'delivered' => (int) ($fed['delivered'] ?? 0),
        ];
    }
    $cmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($file)
        . ' >/dev/null 2>&1 </dev/null &';
    exec($cmd);
    ap_log(
        'instance_block_bg_queued file=' . basename($file)
        . ' target=' . ap_short($targetActorId)
        . ' blocking=' . ($blocking ? '1' : '0')
    );
    return ['ok' => true, 'queued' => true, 'attempted' => 0, 'delivered' => 0];
}

/**
 * Federate a personal Block (Mastodon-compatible). Required for Bridgy Fed opt-out.
 *
 * @param array{id?:string,key_id?:string,priv?:string}|null $ident Override signing identity (instance fan-out).
 * @return array{ok:bool,error?:string,delivered?:bool}
 */
function ap_block_remote_actor(string $actorId, ?array $ident = null): array
{
    $rawInput = trim($actorId);
    $resolved = ap_resolve_actor_ref($rawInput);
    if ($resolved === null && str_starts_with($rawInput, 'https://')) {
        $resolved = rtrim($rawInput, '/');
    }
    if ($resolved === null) {
        return ['ok' => false, 'error' => 'Could not resolve actor to block'];
    }
    $targetId = rtrim($resolved, '/');
    $ident = is_array($ident) && !empty($ident['id']) && !empty($ident['key_id']) && !empty($ident['priv'])
        ? $ident
        : ap_outbound_identity();
    $localId = rtrim((string) ($ident['id'] ?? ''), '/');
    if ($localId === '' || !str_starts_with($localId, 'https://')) {
        return ['ok' => false, 'error' => 'Not signed in as a local account'];
    }
    if ($targetId === $localId) {
        return ['ok' => false, 'error' => 'Cannot block yourself'];
    }

    // Sever local relationships either way (Mastodon-style).
    ap_following_remove($targetId, $localId);
    ap_follower_remove($targetId, $localId);
    $alt = $targetId . '/';
    ap_following_remove($alt, $localId);
    ap_follower_remove($alt, $localId);

    // Same-instance: local-only (no HTTP Block to ourselves).
    if (preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $targetId)) {
        ap_metrics_record('Block', $localId, $targetId, $targetId, 0, 'manual_block_local', null);
        ap_log('block_local_ok target=' . ap_short($targetId) . ' from=' . ap_short($localId));
        return ['ok' => true, 'delivered' => false];
    }

    $doc = ap_fetch_actor_doc($targetId);
    $personalInbox = is_array($doc) ? ap_resolve_personal_inbox_from_actor_doc($doc) : null;
    $sharedInbox = is_array($doc) ? ap_resolve_inbox_from_actor_doc($doc) : null;
    if (is_array($doc)) {
        $canon = ap_as_id($doc['id'] ?? null);
        if (is_string($canon) && $canon !== '') {
            $targetId = rtrim($canon, '/');
        }
    }

    $blockId = $localId . '/blocks/' . bin2hex(random_bytes(10));
    $block = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $blockId,
        'type' => 'Block',
        'actor' => $localId,
        'object' => $targetId,
        'to' => [$targetId],
    ];

    $delivered = false;
    $usedInbox = '';
    // Prefer personal inbox (Bridgy Fed + Mastodon expect Block there).
    foreach (array_filter([$personalInbox, $sharedInbox]) as $inbox) {
        if (!is_string($inbox) || !str_starts_with($inbox, 'https://')) {
            continue;
        }
        if (ap_is_blocked_inbox($inbox)) {
            continue;
        }
        if (ap_deliver_signed_json($inbox, $block, (string) $ident['key_id'], (string) $ident['priv'], 8.0)) {
            $delivered = true;
            $usedInbox = $inbox;
            break;
        }
    }

    ap_metrics_record(
        'Block',
        $localId,
        $blockId,
        $targetId,
        strlen(json_encode($block) ?: ''),
        $delivered ? 'manual_block' : 'manual_block_undelivered',
        null
    );
    ap_log(
        'block_remote target=' . ap_short($targetId)
        . ' from=' . ap_short($localId)
        . ' delivered=' . ($delivered ? '1' : '0')
        . ' inbox=' . ap_short($usedInbox)
    );

    // Local hide still applies even if remote delivery fails; Bridgy needs delivery.
    if (!$delivered) {
        return [
            'ok' => true,
            'delivered' => false,
            'error' => 'Saved locally, but Block activity was not accepted by the remote inbox',
        ];
    }
    return ['ok' => true, 'delivered' => true];
}

/**
 * Federate Undo(Block) when a personal block is removed.
 *
 * @param array{id?:string,key_id?:string,priv?:string}|null $ident
 * @return array{ok:bool,error?:string,delivered?:bool}
 */
function ap_unblock_remote_actor(string $actorId, ?array $ident = null): array
{
    $rawInput = trim($actorId);
    $resolved = ap_resolve_actor_ref($rawInput);
    if ($resolved === null && str_starts_with($rawInput, 'https://')) {
        $resolved = rtrim($rawInput, '/');
    }
    if ($resolved === null) {
        return ['ok' => false, 'error' => 'Could not resolve actor to unblock'];
    }
    $targetId = rtrim($resolved, '/');
    $ident = is_array($ident) && !empty($ident['id']) && !empty($ident['key_id']) && !empty($ident['priv'])
        ? $ident
        : ap_outbound_identity();
    $localId = rtrim((string) ($ident['id'] ?? ''), '/');
    if ($localId === '' || !str_starts_with($localId, 'https://')) {
        return ['ok' => false, 'error' => 'Not signed in as a local account'];
    }

    if (preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $targetId)) {
        ap_metrics_record('Undo', $localId, $targetId, $targetId, 0, 'manual_unblock_local', null);
        return ['ok' => true, 'delivered' => false];
    }

    $doc = ap_fetch_actor_doc($targetId);
    $personalInbox = is_array($doc) ? ap_resolve_personal_inbox_from_actor_doc($doc) : null;
    $sharedInbox = is_array($doc) ? ap_resolve_inbox_from_actor_doc($doc) : null;
    if (is_array($doc)) {
        $canon = ap_as_id($doc['id'] ?? null);
        if (is_string($canon) && $canon !== '') {
            $targetId = rtrim($canon, '/');
        }
    }

    $undoId = $localId . '/undos/' . bin2hex(random_bytes(10));
    $blockId = $localId . '/blocks/undo-' . bin2hex(random_bytes(6));
    $undo = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $undoId,
        'type' => 'Undo',
        'actor' => $localId,
        'to' => [$targetId],
        'object' => [
            'id' => $blockId,
            'type' => 'Block',
            'actor' => $localId,
            'object' => $targetId,
        ],
    ];

    $delivered = false;
    foreach (array_filter([$personalInbox, $sharedInbox]) as $inbox) {
        if (!is_string($inbox) || !str_starts_with($inbox, 'https://')) {
            continue;
        }
        if (ap_is_blocked_inbox($inbox)) {
            continue;
        }
        if (ap_deliver_signed_json($inbox, $undo, (string) $ident['key_id'], (string) $ident['priv'], 8.0)) {
            $delivered = true;
            break;
        }
    }
    ap_metrics_record('Undo', $localId, $undoId, $targetId, strlen(json_encode($undo) ?: ''), 'manual_unblock', null);
    ap_log('unblock_remote target=' . ap_short($targetId) . ' from=' . ap_short($localId) . ' delivered=' . ($delivered ? '1' : '0'));
    return ['ok' => true, 'delivered' => $delivered];
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

/**
 * Notification row key for an inbound activity. Likes, boosts and quotes use a
 * stable actor+target key so repeats collapse into one row. A quote is keyed by
 * the quoting note whether it arrives as Create or as a later Update (Mastodon
 * and VAAK send an Update once the quote is approved), so the pair shares one
 * "quoted your post" notification instead of two.
 */
function ap_local_observe_store_object_id(
    string $type,
    string $storeActivityType,
    ?string $objectId,
    ?string $actorId,
    ?string $activityId,
    string $localActor
): string {
    $objectId = is_string($objectId) ? $objectId : '';
    $actorId = is_string($actorId) ? $actorId : '';
    $stable = static function (string $target, string $actor, string $kind): string {
        return function_exists('ap_mention_interaction_object_id')
            ? ap_mention_interaction_object_id($target, $actor, $kind)
            : (rtrim($target, '/') . '#' . $kind . '-' . substr(hash('sha256', rtrim($actor, '/') . '|' . rtrim($target, '/') . '|' . $kind), 0, 12));
    };
    $storeObjectId = $objectId !== '' ? $objectId : (($activityId ?: 'unknown') . '#obj');
    if (in_array($type, ['Like', 'EmojiReact'], true) && $objectId !== '' && $actorId !== '') {
        return $stable($objectId, $actorId, 'like');
    }
    if ($type === 'Announce' && str_starts_with(rtrim($objectId, '/'), rtrim($localActor, '/') . '/notes/') && $actorId !== '') {
        return $stable($objectId, $actorId, 'reblog');
    }
    if ($storeActivityType === 'Quote' && $objectId !== '') {
        // Quote keyed by quoting object (+ actor) so Delete(quote note) can soft-delete via prefix
        return $stable($objectId, $actorId !== '' ? $actorId : 'unknown', 'quote');
    }
    if ($type === 'Update' && $objectId !== '' && $actorId !== '') {
        // Updates: keep per-edit uniqueness via activity id when present, else stable
        if ($activityId) {
            return rtrim($objectId, '/') . '#update-' . substr(hash('sha256', $activityId), 0, 12);
        }
        return $stable($objectId, $actorId, 'update');
    }
    return $storeObjectId;
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
    $askAnswerTag = null;
    $askAnswerRepresentation = '';

    if (is_array($object)) {
        $objType = is_string($object['type'] ?? null) ? $object['type'] : $type;
        if (isset($object['content']) && is_string($object['content'])) {
            $content = $object['content'];
        }
        $inReplyTo = ap_as_id($object['inReplyTo'] ?? null);
        // Wafrn answers are public Creates with an AskQuestion tag rather than
        // a normal inReplyTo. Preserve that relationship for notifications.
        foreach ((array) ($object['tag'] ?? []) as $tag) {
            if (is_array($tag) && ($tag['type'] ?? '') === 'AskQuestion') {
                $tagActor = rtrim(trim((string) (ap_as_id($tag['actor'] ?? null) ?? '')), '/');
                if ($tagActor !== '') {
                    $askAnswerTag = [
                        'actor' => $tagActor,
                        'question' => trim((string) (($tag['name'] ?? '') ?: ($tag['question'] ?? ''))),
                    ];
                    $askAnswerRepresentation = (string) ($tag['representation'] ?? '');
                }
                break;
            }
        }
        ap_remote_poll_store($object);
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
            ap_remote_poll_store($remoteDoc);
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
        $askAnswerForLocal = is_array($askAnswerTag)
            && rtrim((string) ($askAnswerTag['actor'] ?? ''), '/') === rtrim($localActor, '/')
            && (string) ($askAnswerTag['question'] ?? '') !== '';
        if ($askAnswerForLocal && $actorId !== null && $objectId !== null && function_exists('ap_ask_record_remote_answer')) {
            ap_ask_record_remote_answer(
                (string) $actorId,
                (string) ($askAnswerTag['question'] ?? ''),
                (string) $objectId,
                (int) ($recipient['owner_user_id'] ?? 0)
            );
        }
        $askAnswerBody = '';
        if (is_array($askAnswerTag) && $askAnswerRepresentation !== '' && is_string($content)) {
            $askAnswerBody = trim(strip_tags(str_replace($askAnswerRepresentation, '', $content)));
        }
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

        // Likes/reblogs/quotes share target URLs — use stable actor+target keys so
        // undo+redo (and a quote's later Update) collapses to one notification.
        $storeObjectId = ap_local_observe_store_object_id(
            (string) $type,
            (string) $storeActivityType,
            is_string($objectId) ? $objectId : null,
            is_string($actorId) ? $actorId : null,
            ap_as_id($activity),
            $localActor
        );
        // A quote's Update (FEP-044f approval stamp, or an edit) lands on the
        // quote's existing row; it must not push a second "quoted your post".
        $quoteRowExisted = false;
        if ($type === 'Update' && $storeActivityType === 'Quote') {
            try {
                $qst = ap_db()->prepare('SELECT 1 FROM mentions WHERE owner_user_id = ? AND object_id = ? AND deleted_at IS NULL LIMIT 1');
                $qst->execute([(int) ($recipient['owner_user_id'] ?? 0), $storeObjectId]);
                $quoteRowExisted = (bool) $qst->fetchColumn();
            } catch (Throwable $e) {
                $quoteRowExisted = false;
            }
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
            $addressesUs = ap_activity_addresses_local_actor($activity, $localActor) || $askAnswerForLocal;
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
                'ask_actor' => is_array($askAnswerTag) ? (string) ($askAnswerTag['actor'] ?? '') : '',
                'ask_question' => is_array($askAnswerTag) ? (string) ($askAnswerTag['question'] ?? '') : '',
                'ask_answer' => $askAnswerBody,
                'owner_user_id' => (int) ($recipient['owner_user_id'] ?? 0),
                'owner_actor_id' => (string) ($recipient['owner_actor_id'] ?? ''),
            ]);
            // Ice Cubes Web Push (best-effort; never block inbox)
            if (is_string($actorId) && $actorId !== '' && !$quoteRowExisted) {
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
    if ($type === 'Delete' && $objectId && ap_inbox_object_owned_by_actor((string) $objectId, is_string($actorId) ? $actorId : null)) {
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

/** Short host circuit for repeated remote AS2 fetch failures. */
function ap_remote_fetch_circuit_path(string $host): string
{
    return sys_get_temp_dir() . '/vaak-remote-fetch-circuit-' . hash('sha256', strtolower($host)) . '.json';
}

function ap_remote_fetch_circuit_open(string $host): int
{
    $path = ap_remote_fetch_circuit_path($host);
    $state = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
    return is_array($state) ? max(0, (int) ($state['open_until'] ?? 0)) : 0;
}

function ap_remote_fetch_circuit_failure(string $host): void
{
    if ($host === '') return;
    $path = ap_remote_fetch_circuit_path($host);
    $state = is_file($path) ? json_decode((string) @file_get_contents($path), true) : [];
    $failures = is_array($state) ? (int) ($state['failures'] ?? 0) + 1 : 1;
    @file_put_contents($path, json_encode(['failures' => $failures, 'open_until' => $failures >= 3 ? time() + 60 : 0]), LOCK_EX);
}

function ap_remote_fetch_circuit_success(string $host): void
{
    if ($host !== '') @unlink(ap_remote_fetch_circuit_path($host));
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
    // Same host is not enough: on a shared instance any account could sign as
    // any other. Prefer the owner the key document named; otherwise the keyId
    // must be the actor's own (actor#main-key, or actor/main-key on GoToSocial).
    $actor = rtrim($actorId, '/');
    $owner = ap_key_owner_cached($keyId);
    if ($owner !== null) {
        return rtrim($owner, '/') === $actor;
    }
    $keyBase = rtrim(preg_replace('/#.*$/', '', $keyId) ?? $keyId, '/');
    return $keyBase === $actor || str_starts_with($keyBase, $actor . '/');
}

function ap_key_owner_cache_path(string $keyId): string
{
    return AP_INBOX_KEY_CACHE_DIR . '/' . hash('sha256', trim($keyId)) . '.owner';
}

/**
 * Actor that the fetched key document says owns this keyId, when known.
 */
function ap_key_owner_cached(string $keyId): ?string
{
    $path = ap_key_owner_cache_path($keyId);
    if (!is_file($path)) {
        return null;
    }
    $owner = trim((string) @file_get_contents($path));
    return str_starts_with($owner, 'https://') ? $owner : null;
}

/**
 * Owner named by a fetched key or actor document: publicKey.owner, else the
 * actor's own id when the document embeds the key.
 *
 * @param array<string,mixed> $data
 */
function ap_extract_pubkey_owner(array $data): ?string
{
    $pk = $data['publicKey'] ?? null;
    if (is_array($pk) && array_is_list($pk)) {
        $pk = $pk[0] ?? null;
    }
    if (is_array($pk) && is_string($pk['owner'] ?? null) && str_starts_with($pk['owner'], 'https://')) {
        return $pk['owner'];
    }
    if (is_string($data['owner'] ?? null) && str_starts_with($data['owner'], 'https://')) {
        return $data['owner'];
    }
    if (is_array($pk) && is_string($data['id'] ?? null) && str_starts_with($data['id'], 'https://')) {
        return $data['id'];
    }
    return null;
}

/**
 * A remote actor may only change objects that live on its own host, and never
 * objects on this server.
 */
function ap_inbox_object_owned_by_actor(string $objectId, ?string $actorId): bool
{
    if ($actorId === null || $actorId === '' || !str_starts_with($objectId, 'https://')) {
        return false;
    }
    $objectHost = strtolower((string) parse_url($objectId, PHP_URL_HOST));
    $actorHost = strtolower((string) parse_url($actorId, PHP_URL_HOST));
    if ($objectHost === '' || $objectHost !== $actorHost) {
        return false;
    }
    return !in_array($objectHost, ['mkultra.monster', 'www.mkultra.monster', 'vaak.monster', 'www.vaak.monster'], true);
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
        $cacheKey = 'vaak:actor-doc:' . hash('sha256', rtrim($url, '/'));
        $cached = ap_redis_json_get($cacheKey);
        if (is_array($cached) && (($cached['type'] ?? '') !== '' || isset($cached['inbox']))) {
            return $cached;
        }
        // Authorized fetch first (signed as cmdr_nova); Bridgy handled inside ap_fetch_remote_as2
        $data = ap_fetch_remote_as2($url);
        if ($data === null) {
            continue;
        }
        // Actor docs need a type and/or inbox
        if (($data['type'] ?? '') === '' && !isset($data['inbox'])) {
            continue;
        }
        ap_redis_json_set($cacheKey, $data, 300);
        return $data;
    }
    return null;
}

function ap_deliver_signed_json(string $inboxUrl, array $activity, string $keyId, string $privPath, float $timeoutSec = 10.0): bool
{
    $GLOBALS['ap_delivery_last_error'] = '';
    $parts = parse_url($inboxUrl);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
        $GLOBALS['ap_delivery_last_error'] = 'Invalid HTTPS inbox URL';
        ap_delivery_observe($inboxUrl, $activity, 0, false, 'invalid_inbox');
        return false;
    }
    $host = (string) ($parts['host'] ?? '');
    if ($host === '' || !ap_host_resolves_public($host)) {
        $GLOBALS['ap_delivery_last_error'] = 'Inbox host is unavailable or not public';
        ap_delivery_observe($inboxUrl, $activity, 0, false, 'host_unavailable');
        return false;
    }
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }
    if (!empty($parts['query'])) {
        $path .= '?' . $parts['query'];
    }

    if (function_exists('ap_as2_pleroma_document')) {
        // Keep at:// on alsoKnownAs for Wafrn and Bridgy. Pleroma GETs strip it.
        $activity = ap_as2_pleroma_document($activity, false);
    }
    $body = json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        $GLOBALS['ap_delivery_last_error'] = 'Activity JSON encoding failed';
        ap_delivery_observe($inboxUrl, $activity, 0, false, 'encode_failed');
        return false;
    }

    $privPem = @file_get_contents($privPath);
    if (!is_string($privPem) || $privPem === '') {
        $GLOBALS['ap_delivery_last_error'] = 'Signing key is unavailable';
        ap_delivery_observe($inboxUrl, $activity, 0, false, 'key_unavailable');
        return false;
    }
    $priv = openssl_pkey_get_private($privPem);
    if ($priv === false) {
        $GLOBALS['ap_delivery_last_error'] = 'Signing key could not be opened';
        ap_delivery_observe($inboxUrl, $activity, 0, false, 'key_unreadable');
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
        $GLOBALS['ap_delivery_last_error'] = 'Activity signature generation failed';
        ap_delivery_observe($inboxUrl, $activity, 0, false, 'sign_failed');
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
    // Use cURL for outbound federation rather than PHP's stream wrapper. The
    // wrapper can negotiate an unexpected HTTP version/address family and
    // gives us little diagnostic detail when a remote inbox rejects a POST.
    // Explicit HTTP/1.1 + IPv4 also matches most Mastodon/Wafrn clients while
    // retaining the exact signed request-target.
    $resp = false;
    $status = 0;
    $curlErr = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($inboxUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_merge($headers, ['Expect:']),
            CURLOPT_CONNECTTIMEOUT_MS => (int) min(5000, max(1000, $timeoutSec * 1000)),
            CURLOPT_TIMEOUT_MS => (int) max(1000, $timeoutSec * 1000),
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if (ap_net_curl_pin($ch, $inboxUrl)) {
            $resp = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlErr = trim((string) curl_error($ch));
        } else {
            $curlErr = 'Inbox host is not public';
        }
        curl_close($ch);
    } else {
        // The stream wrapper resolves the name itself and cannot be pinned.
        $curlErr = 'curl is required for delivery';
    }
    $ok = $status >= 200 && $status < 300;
    $statusLine = $status > 0 ? 'HTTP ' . $status : 'No HTTP response from inbox';
    if (!$ok) {
        $detail = $curlErr !== '' ? ' ' . $curlErr : '';
        $snippet = is_string($resp) ? ap_short($resp, 180) : '';
        $GLOBALS['ap_delivery_last_error'] = trim($statusLine . ' ' . $snippet . $detail);
    }
    $observed = is_string($resp) ? $resp : '';
    if ($curlErr !== '' && !$ok) {
        $observed = trim($observed . ' ' . $curlErr);
    }
    if (function_exists('ap_delivery_observe')) {
        ap_delivery_observe($inboxUrl, $activity, $status, (bool) $ok, $observed);
    }
    return (bool) $ok;
}

/**
 * CLI PHP binary for background workers. Never return php-fpm — FPM cannot
 * run worker scripts, which left media/poll deliver jobs stuck in /tmp forever.
 */
// ap_php_cli_binary() lives in ap-db.php (always loaded). Kept as a no-op
// comment so older call sites / docs that pointed here stay discoverable.

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
    // Prefer one durable, deterministic child job per inbox. The
    // (activity_key,inbox_url) uniqueness constraint makes retries and
    // duplicate enqueue attempts idempotent while keeping each remote
    // delivery independently inspectable.
    try {
        $activityKey = (string) ($activity['id'] ?? '');
        if ($activityKey === '') {
            $activityKey = 'sha256:' . hash('sha256', json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $json = json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') throw new RuntimeException('activity encode failed');
        $activityObject = is_array($activity['object'] ?? null) ? $activity['object'] : [];
        $hasMention = false;
        foreach (($activityObject['tag'] ?? []) as $tag) {
            if (is_array($tag) && strtolower((string) ($tag['type'] ?? '')) === 'mention') {
                $hasMention = true;
                break;
            }
        }
        // Replies and direct mentions should not sit behind bulk fan-out.
        $priority = !empty($activityObject['inReplyTo']) ? 10 : ($hasMention ? 20 : 50);
        $db = ap_db();
        $st = $db->prepare('INSERT INTO ap_fanout_delivery_queue
            (activity_key,inbox_url,activity_json,key_id,priv_path,status,priority,attempts,next_attempt_at,created_at,updated_at)
            VALUES (?,?,?,?,?,\'pending\',?,0,?,?,?) ON CONFLICT(activity_key,inbox_url) DO NOTHING');
        $now = gmdate('c');
        $queued = 0;
        foreach ($inboxUrls as $inbox) {
            $decision = function_exists('ap_fanout_host_admit')
                ? ap_fanout_host_admit($inbox, $activity, $keyId, $privPath)
                : 'send';
            if ($decision === 'skip') {
                continue;
            }
            if ($decision === 'probe') {
                $queued++;
                continue;
            }
            $st->execute([$activityKey, $inbox, $json, $keyId, $privPath, $priority, $now, $now, $now]);
            $queued += $st->rowCount();
        }
        if ($queued > 0 && function_exists('ap_redis_queue_push')) {
            ap_redis_queue_push('fanout-delivery', $activityKey);
        }
        $worker = __DIR__ . '/ap-fanout-delivery-worker.php';
        if (is_file($worker)) {
            $php = ap_php_cli_binary();
            $running = trim((string) @shell_exec("pgrep -fc 'ap-fanout-delivery-worker.php' 2>/dev/null"));
            if ((int) $running === 0) {
                @exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' --limit=40 >/dev/null 2>&1 </dev/null &');
            }
        }
        ap_log('deliver_durable_queued activity=' . ap_short($activityKey) . ' targets=' . count($inboxUrls) . ' new=' . $queued);
        return true;
    } catch (Throwable $e) {
        // During rollout, older schemas use the legacy ephemeral fallback.
        ap_log('deliver_durable_unavailable ' . $e->getMessage());
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
function ap_deliver_public_activity(array $activity, array $priorityExtra = [], bool $skipBridgy = false): array
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
    foreach ($skipBridgy ? [] : $bridgyInboxes as $inbox) {
        if (ap_is_blocked_inbox($inbox)) {
            continue;
        }
        // 5s: Bridgy often ACKs immediately even when its media pipeline is busy.
        if (ap_fanout_deliver_or_hold($inbox, $activity, $ident['key_id'], $ident['priv'], 5.0)) {
            $delivered++;
            $bridgyOk = true;
            break;
        }
    }
    if (!$bridgyOk && !$skipBridgy) {
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
        if (ap_fanout_deliver_or_hold($inbox, $activity, $ident['key_id'], $ident['priv'], 2.5)) {
            $delivered++;
        }
    }

    $prioritySet = array_fill_keys(array_merge($bridgyInboxes, $otherPriority), true);
    $deferred = $bgFollowers;
    // Wafrn's "friendServer" bubble: explicitly trusted Wafrn hosts receive
    // public activity even without a local follower. This remains background
    // delivery and never applies to local-only or direct messages.
    if (function_exists('ap_wafrn_friend_inboxes')) {
        foreach (ap_wafrn_friend_inboxes(20) as $friendInbox) {
            if (isset($prioritySet[$friendInbox]) || ap_is_blocked_inbox($friendInbox)) {
                continue;
            }
            $deferred[] = $friendInbox;
            $prioritySet[$friendInbox] = true;
        }
    }
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
                if (ap_fanout_deliver_or_hold($inbox, $activity, $ident['key_id'], $ident['priv'], 2.5)) {
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
        if (ap_fanout_deliver_or_hold($inbox, $activity, $ident['key_id'], $ident['priv'], 2.5)) {
            $delivered++;
        }
    }
    $queued = 0;
    if ($deferred) {
        if (ap_deliver_fanout_background($activity, $deferred, $ident['key_id'], $ident['priv'])) {
            $queued = count($deferred);
        } else {
            foreach (array_slice($deferred, 0, 8) as $inbox) {
                if (ap_fanout_deliver_or_hold($inbox, $activity, $ident['key_id'], $ident['priv'], 2.5)) {
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

/** Send a FEP-044f Reject to the requesting actor when local policy denies it. */
function ap_quote_request_reject(array $activity, string $ownerActor, string $requester): void
{
    $instrument = ap_as_id($activity['instrument'] ?? null);
    $quoted = ap_as_id($activity['object'] ?? null);
    $requestId = ap_as_id($activity) ?: ($instrument !== null ? $instrument . '/quote' : '');
    if ($instrument === null || $quoted === null || $requestId === '') {
        return;
    }
    $doc = ap_fetch_actor_doc($requester);
    $inbox = is_array($doc)
        ? (ap_resolve_personal_inbox_from_actor_doc($doc) ?: ap_resolve_inbox_from_actor_doc($doc))
        : null;
    if (!$inbox || ap_is_blocked_inbox($inbox)) {
        return;
    }
    $prevActor = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
    if (function_exists('ap_request_actor_set') && preg_match('#/users/([A-Za-z0-9_]+)$#', $ownerActor, $am)) {
        ap_request_actor_set($am[1]);
    }
    $ident = ap_outbound_identity();
    $reject = [
        '@context' => ['https://www.w3.org/ns/activitystreams', ['QuoteRequest' => 'https://w3id.org/fep/044f#QuoteRequest']],
        'id' => $ownerActor . '/rejects/' . bin2hex(random_bytes(8)),
        'type' => 'Reject',
        'actor' => $ownerActor,
        'to' => [$requester],
        'object' => [
            'type' => 'QuoteRequest', 'id' => $requestId, 'actor' => $requester,
            'object' => $quoted, 'instrument' => $instrument,
        ],
    ];
    ap_deliver_signed_json($inbox, $reject, $ident['key_id'], $ident['priv'], 5.0);
    if (function_exists('ap_request_actor_set')) {
        ap_request_actor_set(is_array($prevActor) ? (string) ($prevActor['key'] ?? '') : null);
    }
}

/**
 * Auto-approve FEP-044f QuoteRequest for quotable public/unlisted notes.
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
    $st = ap_db()->prepare('SELECT id, visibility FROM outbox_notes WHERE id = ?');
    $st->execute([$quotedId]);
    $quotedRow = $st->fetch();
    if (!is_array($quotedRow)) {
        ap_quote_request_reject($activity, $ownerActor, $actorId);
        ap_log('quote_request_unknown_note ' . ap_short($quotedId));
        return 'quote_request_unknown';
    }
    if (!is_array($quotedRow) || !in_array(ap_normalize_visibility((string) ($quotedRow['visibility'] ?? 'public')), ['public', 'unlisted'], true)) {
        ap_quote_request_reject($activity, $ownerActor, $actorId);
        ap_log('quote_request_policy_denied visibility actor=' . ap_short($actorId));
        return 'quote_request_policy_denied';
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
        ap_quote_request_reject($activity, $ownerActor, $actorId);
        ap_log('quote_request_policy_denied nobody actor=' . ap_short($actorId));
        return 'quote_request_policy_denied';
    }
    if ($quotePolicy === 'followers') {
        $st = ap_db()->prepare(
            'SELECT 1 FROM followers WHERE owner_actor_id IN (?, ?) AND (actor_id = ? OR actor_id = ?) LIMIT 1'
        );
        $st->execute([$ownerActor, $ownerActor . '/', $actorId, $actorId . '/']);
        if (!$st->fetchColumn()) {
            ap_quote_request_reject($activity, $ownerActor, $actorId);
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

/** Send the FEP-044f consent request for a quote of a remote object. */
function ap_quote_request_send(string $quotedObjectId, array $quoteNote): bool
{
    $quotedObjectId = rtrim(trim($quotedObjectId), '/');
    $noteId = rtrim((string) ($quoteNote['id'] ?? ''), '/');
    $actor = rtrim((string) ($quoteNote['attributedTo'] ?? ''), '/');
    if (!str_starts_with($quotedObjectId, 'https://') || !str_starts_with($noteId, 'https://')
        || !str_starts_with($actor, 'https://')) {
        return false;
    }
    $target = ap_fetch_as2_object($quotedObjectId);
    if (!is_array($target)) {
        ap_log('quote_request_target_fetch_fail note=' . ap_short($noteId));
        return false;
    }
    $targetActor = ap_as_id($target['attributedTo'] ?? null) ?: ap_as_id($target['actor'] ?? null);
    if (!$targetActor || rtrim($targetActor, '/') === $actor) {
        return false;
    }
    $actorDoc = ap_fetch_actor_doc($targetActor);
    $inbox = is_array($actorDoc)
        ? (ap_resolve_personal_inbox_from_actor_doc($actorDoc) ?: ap_resolve_inbox_from_actor_doc($actorDoc))
        : null;
    if (!$inbox || ap_is_blocked_inbox($inbox)) {
        ap_log('quote_request_inbox_missing actor=' . ap_short($targetActor));
        return false;
    }
    $prevActor = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
    if (function_exists('ap_request_actor_set') && preg_match('#/users/([A-Za-z0-9_]+)$#', $actor, $am)) {
        ap_request_actor_set($am[1]);
    }
    $ident = ap_outbound_identity();
    $request = [
        '@context' => ['https://www.w3.org/ns/activitystreams', ap_quote_ld_context(), ['QuoteRequest' => 'https://w3id.org/fep/044f#QuoteRequest']],
        'id' => $noteId . '/quote',
        'type' => 'QuoteRequest',
        'actor' => $actor,
        'to' => [$targetActor],
        'object' => $quotedObjectId,
        'instrument' => ap_note_ensure_quote_policy($quoteNote),
    ];
    $ok = ap_deliver_signed_json($inbox, $request, $ident['key_id'], $ident['priv'], 5.0);
    $queued = !$ok && ap_deliver_fanout_background($request, [$inbox], $ident['key_id'], $ident['priv']);
    if (function_exists('ap_request_actor_set')) {
        ap_request_actor_set(is_array($prevActor) ? (string) ($prevActor['key'] ?? '') : null);
    }
    ap_log('quote_request_send ' . ($ok ? 'ok' : ($queued ? 'queued' : 'fail')) . ' target=' . ap_short($quotedObjectId) . ' request=' . ap_short($noteId . '/quote'));
    return $ok || $queued;
}

/** True for Bluesky / Bridgy AT quote targets (not FEP-044f ActivityPub subjects). */
function ap_quote_target_is_bluesky(string $quoteObjectId): bool
{
    $quoteObjectId = trim($quoteObjectId);
    return str_starts_with($quoteObjectId, 'https://bsky.app/')
        || str_starts_with($quoteObjectId, 'at://')
        || str_contains($quoteObjectId, 'bsky.brid.gy');
}

/**
 * True when a reply belongs to a thread that started on Bluesky, so it must
 * stay Bluesky-side: no ActivityPub delivery, no remote Mention tags, no inbox hits.
 * Mentions of local VAAK users are kept and notified in-process.
 *
 * - bsky.app / at:// parents are Bluesky-native unless they map to a VAAK note
 *   (a VAAK→Bluesky mirror), in which case that note decides.
 * - Local notes imported from the Bluesky appview (vaakOrigin=bluesky) and
 *   local replies further down such a thread are Bluesky-side too.
 * - Bridgy Fed (bsky.brid.gy) parents are ActivityPub objects and federate.
 *
 * DB/cache only on the compose path (no XRPC record fetch).
 */
function ap_reply_parent_is_bluesky_side(string $inReplyTo, int $depth = 0): bool
{
    $inReplyTo = rtrim(trim($inReplyTo), '/');
    if ($inReplyTo === '' || $depth > 8 || str_contains($inReplyTo, 'bsky.brid.gy')) {
        return false;
    }
    static $memo = [];
    if (array_key_exists($inReplyTo, $memo)) {
        return $memo[$inReplyTo];
    }
    if (str_starts_with($inReplyTo, 'https://bsky.app/') || str_starts_with($inReplyTo, 'at://')) {
        if (!function_exists('ap_bsky_local_note_id_for_at_uri') && is_file(__DIR__ . '/ap-bsky.php')) {
            require_once __DIR__ . '/ap-bsky.php';
        }
        $local = function_exists('ap_bsky_local_note_id_for_at_uri')
            ? ap_bsky_local_note_id_for_at_uri($inReplyTo, 0, false)
            : null;
        if (is_string($local) && str_starts_with($local, 'https://mkultra.monster/users/')) {
            return $memo[$inReplyTo] = ap_reply_parent_is_bluesky_side($local, $depth + 1);
        }
        return $memo[$inReplyTo] = true;
    }
    if (!preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+/notes/#', $inReplyTo)) {
        return $memo[$inReplyTo] = false;
    }
    try {
        $st = ap_db()->prepare('SELECT in_reply_to, raw_create_json FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
        $st->execute([$inReplyTo, $inReplyTo . '/']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $memo[$inReplyTo] = false;
    }
    if (!is_array($row)) {
        return $memo[$inReplyTo] = false;
    }
    $raw = json_decode((string) ($row['raw_create_json'] ?? ''), true);
    if (is_array($raw) && is_array($raw['object'] ?? null)
        && (($raw['object']['vaakOrigin'] ?? '') === 'bluesky')) {
        return $memo[$inReplyTo] = true;
    }
    $parent = trim((string) ($row['in_reply_to'] ?? ''));
    return $memo[$inReplyTo] = ($parent !== '' && ap_reply_parent_is_bluesky_side($parent, $depth + 1));
}

/** True when we already have the quoted object cached locally (events / outbox). */
function ap_quote_target_locally_known(string $quoteObjectId): bool
{
    $quoteObjectId = rtrim(trim($quoteObjectId), '/');
    if ($quoteObjectId === '' || !str_starts_with($quoteObjectId, 'https://')) {
        return false;
    }
    $cands = function_exists('ap_object_url_lookup_candidates')
        ? ap_object_url_lookup_candidates($quoteObjectId)
        : [$quoteObjectId, $quoteObjectId . '/'];
    foreach ($cands as $cand) {
        if (!is_string($cand) || $cand === '') {
            continue;
        }
        try {
            $st = ap_db()->prepare('SELECT 1 FROM events WHERE object_id = ? OR object_id = ? LIMIT 1');
            $st->execute([$cand, rtrim($cand, '/') . '/']);
            if ($st->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $st = ap_db()->prepare('SELECT 1 FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
            $st->execute([$cand, rtrim($cand, '/') . '/']);
            if ($st->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    return false;
}

/**
 * Remote note allows automatic public quoting (FEP-044f / GTS interactionPolicy).
 * Used so VAAK can display quotes as accepted when remotes auto-approve but the
 * Accept stamp was missed, and to skip endless "pending" for open policies.
 */
function ap_quote_target_allows_automatic(string $quoteObjectId): bool
{
    $quoteObjectId = rtrim(trim($quoteObjectId), '/');
    if ($quoteObjectId === '' || !str_starts_with($quoteObjectId, 'https://') || ap_quote_target_is_bluesky($quoteObjectId)) {
        return false;
    }
    $doc = ap_fetch_as2_object($quoteObjectId);
    if (!is_array($doc)) {
        return false;
    }
    $doc = function_exists('ap_unwrap_as2_object') ? ap_unwrap_as2_object($doc) : $doc;
    $policy = is_array($doc['interactionPolicy'] ?? null) ? $doc['interactionPolicy'] : [];
    $canQuote = is_array($policy['canQuote'] ?? null) ? $policy['canQuote'] : [];
    $auto = $canQuote['automaticApproval'] ?? [];
    if (!is_array($auto)) {
        $auto = $auto !== null && $auto !== '' ? [$auto] : [];
    }
    foreach ($auto as $entry) {
        $id = is_string($entry) ? $entry : (is_array($entry) ? (string) ($entry['id'] ?? '') : '');
        if ($id === 'https://www.w3.org/ns/activitystreams#Public'
            || $id === 'as:Public'
            || $id === 'Public') {
            return true;
        }
    }
    return false;
}

/** Apply a valid FEP-044f Accept/Reject response to one of our outbound quotes. */
function ap_quote_response_handle(array $activity, string $responseType): ?string
{
    $request = $activity['object'] ?? null;
    // Accept.object may be an embedded QuoteRequest or a URI to one we sent.
    if (is_string($request) && str_starts_with($request, 'https://mkultra.monster/users/')
        && str_ends_with(rtrim($request, '/'), '/quote')) {
        $reqDoc = ap_fetch_as2_object($request);
        // Our QuoteRequests are not always HTTP-dereferenceable; reconstruct from id.
        if (!is_array($reqDoc)) {
            $instrument = preg_replace('#/quote/?$#', '', rtrim($request, '/'));
            $st = ap_db()->prepare('SELECT raw_create_json FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
            $st->execute([$instrument, $instrument . '/']);
            $rawTmp = json_decode((string) ($st->fetchColumn() ?: ''), true);
            $noteTmp = is_array($rawTmp['object'] ?? null) ? $rawTmp['object'] : null;
            if (is_array($noteTmp)) {
                $reqDoc = [
                    'type' => 'QuoteRequest',
                    'id' => rtrim($instrument, '/') . '/quote',
                    'actor' => (string) ($noteTmp['attributedTo'] ?? ''),
                    'object' => (string) ($noteTmp['quote'] ?? ''),
                    'instrument' => $instrument,
                ];
            }
        }
        $request = $reqDoc;
    }
    if (!is_array($request) || (string) ($request['type'] ?? '') !== 'QuoteRequest') {
        return null;
    }
    $sender = ap_as_id($activity['actor'] ?? null);
    $requestActor = ap_as_id($request['actor'] ?? null);
    $quotedId = ap_as_id($request['object'] ?? null);
    $instrument = ap_as_id($request['instrument'] ?? null);
    // FEP-044f: Accept.actor = quoted author; QuoteRequest.actor = quoter (us).
    // They must NOT be required to match — that bug left every outbound quote pending.
    if (!$sender || !$requestActor || !$quotedId || !$instrument
        || !preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+/notes/#', $instrument)) {
        return 'quote_response_invalid';
    }
    $st = ap_db()->prepare('SELECT * FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
    $st->execute([$instrument, rtrim($instrument, '/') . '/']);
    $row = $st->fetch();
    if (!is_array($row)) {
        return 'quote_response_unknown';
    }
    $raw = json_decode((string) ($row['raw_create_json'] ?? ''), true);
    $note = is_array($raw) && is_array($raw['object'] ?? null) ? $raw['object'] : null;
    $reqId = rtrim((string) ($request['id'] ?? ''), '/');
    $expectReqId = rtrim($instrument, '/') . '/quote';
    if (!is_array($note) || rtrim((string) ($note['id'] ?? ''), '/') !== rtrim($instrument, '/')
        || rtrim(ap_as_id($note['quote'] ?? null) ?? '', '/') !== rtrim($quotedId, '/')
        || ($reqId !== '' && $reqId !== $expectReqId)) {
        return 'quote_response_mismatch';
    }
    $localActor = function_exists('ap_local_actor_id') ? rtrim(ap_local_actor_id(), '/') : rtrim(LOCAL_ACTOR, '/');
    if ($localActor === '' || rtrim((string) ($note['attributedTo'] ?? ''), '/') !== $localActor) {
        return 'quote_response_wrong_owner';
    }
    // QuoteRequest.actor must be us (the quoter).
    if (rtrim($requestActor, '/') !== $localActor) {
        return 'quote_response_wrong_requester';
    }
    $targetDoc = ap_fetch_as2_object($quotedId);
    $targetActor = is_array($targetDoc)
        ? (ap_as_id($targetDoc['attributedTo'] ?? null) ?: ap_as_id($targetDoc['actor'] ?? null))
        : null;
    // Prefer attributedTo from the quoted object; fall back to Accept.actor.
    if ($targetActor && rtrim($targetActor, '/') !== rtrim($sender, '/')) {
        return 'quote_response_wrong_actor';
    }
    if (!$targetActor) {
        $targetActor = $sender;
    }
    if ($responseType === 'Accept') {
        $authId = ap_as_id($activity['result'] ?? null);
        if (!$authId || !str_starts_with($authId, 'https://')) {
            return 'quote_response_missing_authorization';
        }
        $auth = ap_fetch_as2_object($authId);
        if (!is_array($auth) || (string) ($auth['type'] ?? '') !== 'QuoteAuthorization'
            || rtrim((string) ($auth['id'] ?? ''), '/') !== rtrim($authId, '/')
            || rtrim(ap_as_id($auth['attributedTo'] ?? null) ?? '', '/') !== rtrim($sender, '/')
            || rtrim(ap_as_id($auth['interactingObject'] ?? null) ?? '', '/') !== rtrim($instrument, '/')
            || rtrim(ap_as_id($auth['interactionTarget'] ?? null) ?? '', '/') !== rtrim($quotedId, '/')) {
            return 'quote_response_invalid_authorization';
        }
        $note['quoteAuthorization'] = $authId;
    } else {
        unset($note['quote'], $note['quoteAuthorization']);
        $note['tag'] = array_values(array_filter((array) ($note['tag'] ?? []), static fn($tag): bool =>
            !is_array($tag) || rtrim((string) ($tag['href'] ?? ''), '/') !== rtrim($quotedId, '/')
        ));
    }
    $raw['object'] = $note;
    $raw = ap_create_finalize($raw);
    ap_db()->prepare('UPDATE outbox_notes SET raw_create_json = ? WHERE id = ? OR id = ?')->execute([
        json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $instrument, rtrim($instrument, '/') . '/',
    ]);
    if ($responseType === 'Accept' && empty($note['blueskyUri']) && !empty($note['quote'])) {
        try {
            if (!function_exists('ap_bsky_crosspost_status')) {
                require_once __DIR__ . '/ap-bsky.php';
            }
            $statusRow = function_exists('ap_masto_status_by_note_id')
                ? ap_masto_status_by_note_id($instrument)
                : null;
            $ownerId = function_exists('ap_db_owner_user_id_for_actor')
                ? ap_db_owner_user_id_for_actor((string) ($note['attributedTo'] ?? ''))
                : 0;
            if ($ownerId > 0 && is_array($statusRow) && function_exists('ap_bsky_session_row')
                && ap_bsky_session_row($ownerId) !== null) {
                $mediaSt = ap_db()->prepare('SELECT local_id FROM masto_media WHERE status_local_id = ? ORDER BY local_id ASC');
                $mediaSt->execute([(int) ($statusRow['local_id'] ?? 0)]);
                $mediaIds = array_map('intval', $mediaSt->fetchAll(PDO::FETCH_COLUMN));
                $bsky = ap_bsky_crosspost_status(
                    $ownerId,
                    (string) ($statusRow['content_text'] ?? ''),
                    ap_normalize_visibility((string) ($row['visibility'] ?? 'public')),
                    $mediaIds,
                    (string) ($statusRow['spoiler_text'] ?? ''),
                    !empty($row['in_reply_to']) ? (string) $row['in_reply_to'] : null,
                    (string) $note['quote'],
                    $instrument
                );
                if (!empty($bsky['ok']) && empty($bsky['skipped']) && !empty($bsky['uri'])
                    && function_exists('ap_note_attach_bsky_proxy')) {
                    $note = ap_note_attach_bsky_proxy(
                        $note,
                        (string) $bsky['uri'],
                        isset($bsky['cid']) ? (string) $bsky['cid'] : null
                    );
                    $raw['object'] = $note;
                    $raw = ap_create_finalize($raw);
                    ap_db()->prepare('UPDATE outbox_notes SET raw_create_json = ? WHERE id = ? OR id = ?')->execute([
                        json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $instrument, rtrim($instrument, '/') . '/',
                    ]);
                }
            }
        } catch (Throwable $e) {
            ap_log('quote_accept_bsky_mirror_fail ' . ap_short($e->getMessage(), 160));
        }
    }
    $visibility = ap_normalize_visibility((string) ($row['visibility'] ?? 'public'));
    $update = [
        '@context' => ['https://www.w3.org/ns/activitystreams', ap_quote_ld_context()],
        'id' => rtrim((string) ($note['attributedTo'] ?? ''), '/') . '/updates/' . bin2hex(random_bytes(8)),
        'type' => 'Update', 'actor' => (string) ($note['attributedTo'] ?? ''), 'published' => gmdate('c'),
        'to' => $note['to'] ?? [], 'cc' => $note['cc'] ?? [], 'object' => $note,
    ];
    $prevActor = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
    if (function_exists('ap_request_actor_set') && preg_match('#/users/([A-Za-z0-9_]+)$#', (string) ($note['attributedTo'] ?? ''), $am)) {
        ap_request_actor_set($am[1]);
    }
    if (in_array($visibility, ['public', 'unlisted'], true)) {
        // Pending quotes are mirrored directly to Bluesky only after consent;
        // this Update is for Fediverse recipients and must not bypass that gate.
        ap_deliver_public_activity($update, [], true);
    } else {
        ap_deliver_followers_activity($update, []);
    }
    if (function_exists('ap_request_actor_set')) {
        ap_request_actor_set(is_array($prevActor) ? (string) ($prevActor['key'] ?? '') : null);
    }
    ap_log('quote_response_applied type=' . $responseType . ' quote=' . ap_short($instrument));
    return $responseType === 'Accept' ? 'quote_accepted' : 'quote_rejected';
}

/**
 * Local actor id embedded in a VAAK note URL, or '' when the URL is not one.
 */
function ap_publish_local_actor_from_note(string $url): string
{
    $url = rtrim(trim($url), '/');
    if (preg_match('#^(https://mkultra\.monster/users/[A-Za-z0-9_]+)/notes/#', $url, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Local accounts who should see a reply: the parent author, plus anyone this
 * Note already mentions on this instance. Not the rest of the thread.
 *
 * @param list<array<string,mixed>> $mentionTags
 * @return list<string>
 */
function ap_publish_local_reply_recipient_actors(string $actor, string $inReplyTo, array $mentionTags): array
{
    $actor = rtrim($actor, '/');
    $recipients = [];
    $parent = ap_publish_local_actor_from_note($inReplyTo);
    if ($parent === '' && $inReplyTo !== ''
        && (str_starts_with($inReplyTo, 'at://') || str_starts_with($inReplyTo, 'https://bsky.app/'))
        && function_exists('ap_bsky_local_note_id_for_at_uri')) {
        try {
            $mapped = ap_bsky_local_note_id_for_at_uri($inReplyTo, 0, false);
        } catch (Throwable $e) {
            $mapped = null;
        }
        if (is_string($mapped)) {
            $parent = ap_publish_local_actor_from_note($mapped);
        }
    }
    if ($parent !== '') {
        $recipients[$parent] = true;
    }
    foreach ($mentionTags as $tag) {
        if (!is_array($tag)) {
            continue;
        }
        $href = rtrim((string) ($tag['href'] ?? ''), '/');
        if (preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $href)) {
            $recipients[$href] = true;
        }
    }
    unset($recipients[$actor]);
    return array_keys($recipients);
}

/**
 * Write mentions rows for a local reply without ActivityPub delivery.
 * ap_mention_store drops the owner's notification projection, which is what
 * the Rust notification list refills from. Idempotent with any later inbox copy.
 *
 * @param list<array<string,mixed>> $mentionTags
 */
function ap_publish_notify_local_reply_participants(array $create, string $actor, string $inReplyTo, array $mentionTags): void
{
    if (!function_exists('ap_mention_store') || !function_exists('ap_local_user_by_actor_id')) {
        return;
    }
    $targets = ap_publish_local_reply_recipient_actors($actor, $inReplyTo, $mentionTags);
    if ($targets === []) {
        return;
    }
    $author = rtrim($actor, '/');
    $authorUser = ap_local_user_by_actor_id($author);
    $authorId = is_array($authorUser) ? (int) ($authorUser['id'] ?? 0) : 0;
    $object = is_array($create['object'] ?? null) ? $create['object'] : [];
    $noteId = rtrim((string) ($object['id'] ?? ''), '/');
    if ($noteId === '') {
        return;
    }
    $content = isset($object['content']) && is_string($object['content']) ? $object['content'] : null;
    $spoiler = isset($object['summary']) && is_string($object['summary']) ? $object['summary'] : '';
    $sensitive = !empty($object['sensitive']) || $spoiler !== '';
    foreach ($targets as $recipient) {
        try {
            $user = ap_local_user_by_actor_id($recipient);
            if (!is_array($user) || (int) ($user['id'] ?? 0) < 1) {
                continue;
            }
            $uid = (int) $user['id'];
            if ($authorId > 0 && function_exists('ap_user_is_blocked')) {
                if (ap_user_is_blocked($recipient, null, $authorId) || ap_user_is_blocked($author, null, $uid)) {
                    continue;
                }
            }
            ap_mention_store([
                'activity_id' => (string) ($create['id'] ?? ''),
                'object_id' => $noteId,
                'actor_id' => $author,
                'type' => (string) ($object['type'] ?? 'Note'),
                'activity_type' => 'Create',
                'content' => $content,
                'in_reply_to' => $inReplyTo !== '' ? $inReplyTo : null,
                'spoiler_text' => $spoiler,
                'sensitive' => $sensitive,
                'owner_user_id' => $uid,
                'owner_actor_id' => $recipient,
            ]);
            if (function_exists('ap_webpush_notify_event')) {
                try {
                    ap_webpush_notify_event('mention', $author, null, is_string($content) ? $content : null, $uid);
                } catch (Throwable $e) {
                    error_log('[ap-inbox] local reply webpush skipped: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('[ap-inbox] local reply notification skipped: ' . $e->getMessage());
        }
    }
}

/**
 * Replies since $since whose Bluesky-side parent never produced a local
 * mentions row. Dry-run listing for api/bin/bsky-thread-local-notify-backfill.php.
 *
 * @return array{since:string,scanned:int,missing:list<array{note_id:string,actor:string,in_reply_to:string,recipients:list<string>}>,applied:int}
 */
function ap_bsky_thread_local_notify_backfill(bool $apply = false, string $since = '2026-10-18T00:00:00Z', int $limit = 500): array
{
    $since = trim($since) !== '' ? trim($since) : '2026-10-18T00:00:00Z';
    $limit = max(1, min(2000, $limit));
    $missing = [];
    $scanned = 0;
    $applied = 0;
    try {
        $st = ap_db()->prepare(
            'SELECT id, in_reply_to, raw_create_json, published FROM outbox_notes
             WHERE in_reply_to IS NOT NULL AND in_reply_to != \'\'
               AND published >= ?
             ORDER BY published ASC
             LIMIT ?'
        );
        $st->execute([$since, $limit]);
        $rows = $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return ['since' => $since, 'scanned' => 0, 'missing' => [], 'applied' => 0];
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $scanned++;
        $noteId = rtrim((string) ($row['id'] ?? ''), '/');
        $inReplyTo = trim((string) ($row['in_reply_to'] ?? ''));
        if ($noteId === '' || $inReplyTo === '' || !ap_reply_parent_is_bluesky_side($inReplyTo)) {
            continue;
        }
        $create = json_decode((string) ($row['raw_create_json'] ?? ''), true);
        if (!is_array($create)) {
            $create = [];
        }
        $object = is_array($create['object'] ?? null) ? $create['object'] : [];
        $actor = rtrim((string) ($create['actor'] ?? ''), '/');
        if ($actor === '' && preg_match('#^(https://mkultra\.monster/users/[A-Za-z0-9_]+)/notes/#', $noteId, $am)) {
            $actor = $am[1];
        }
        $tags = [];
        foreach ((array) ($object['tag'] ?? []) as $tag) {
            if (is_array($tag) && strcasecmp((string) ($tag['type'] ?? ''), 'Mention') === 0) {
                $tags[] = $tag;
            }
        }
        $content = (string) ($object['content'] ?? '');
        if ($content !== '' && preg_match_all('#https://mkultra\.monster/users/([A-Za-z0-9_]+)(?!/notes/)#', $content, $found)) {
            foreach ($found[1] as $key) {
                $href = 'https://mkultra.monster/users/' . $key;
                $tags[] = ['type' => 'Mention', 'href' => $href, 'name' => '@' . $key];
            }
        }
        $recipients = ap_publish_local_reply_recipient_actors($actor, $inReplyTo, $tags);
        $need = [];
        foreach ($recipients as $recipient) {
            $user = function_exists('ap_local_user_by_actor_id') ? ap_local_user_by_actor_id($recipient) : null;
            $uid = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
            if ($uid < 1) {
                continue;
            }
            try {
                $have = ap_db()->prepare(
                    'SELECT 1 FROM mentions WHERE owner_user_id = ? AND (object_id = ? OR object_id = ?) AND deleted_at IS NULL LIMIT 1'
                );
                $have->execute([$uid, $noteId, $noteId . '/']);
                if ($have->fetchColumn()) {
                    continue;
                }
            } catch (Throwable $e) {
                continue;
            }
            $need[] = $recipient;
        }
        if ($need === []) {
            continue;
        }
        $missing[] = [
            'note_id' => $noteId,
            'actor' => $actor,
            'in_reply_to' => $inReplyTo,
            'recipients' => $need,
        ];
        if ($apply && $create !== []) {
            $only = [];
            foreach ($tags as $tag) {
                $href = rtrim((string) ($tag['href'] ?? ''), '/');
                if (in_array($href, $need, true) || ap_publish_local_actor_from_note($inReplyTo) === $href) {
                    $only[] = $tag;
                }
            }
            // Parent author is resolved from inReplyTo, not from tags.
            ap_publish_notify_local_reply_participants($create, $actor, $inReplyTo, $only);
            $applied++;
        }
    }
    return ['since' => $since, 'scanned' => $scanned, 'missing' => $missing, 'applied' => $applied];
}

/**
 * Publish a Note as @cmdr_nova (admin compose + queue + Mastodon API).
 * Visibility: public | unlisted (silent public) | private (followers-only) | local.
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

    // A reply must never broaden the audience of its parent.  This is kept
    // server-side so inline, modal, focused-post, API, and queued composers
    // all receive the same protection.  Remote parents are only constrained
    // when their visibility is already cached locally; an unresolved remote
    // object is left to the normal federation policy.
    if ($inReplyTo !== '') {
        $parentVisibility = null;
        try {
            $parentKey = rtrim($inReplyTo, '/');
            $st = ap_db()->prepare(
                "SELECT visibility FROM masto_statuses WHERE note_id = ? OR note_id = ? LIMIT 1"
            );
            $st->execute([$inReplyTo, $parentKey]);
            $row = $st->fetch();
            if (is_array($row) && isset($row['visibility'])) {
                $parentVisibility = ap_normalize_visibility((string) $row['visibility']);
            }
            if ($parentVisibility === null) {
                $st = ap_db()->prepare(
                    "SELECT visibility FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1"
                );
                $st->execute([$inReplyTo, $parentKey]);
                $row = $st->fetch();
                if (is_array($row) && isset($row['visibility'])) {
                    $parentVisibility = ap_normalize_visibility((string) $row['visibility']);
                }
            }
        } catch (Throwable $e) {
            ap_log('reply_parent_visibility_lookup_failed ' . $e->getMessage());
        }
        if ($parentVisibility === 'local') {
            // A local-only parent must not gain a wider audience through a
            // reply, even when the reply composer or API requests public.
            $visibility = 'local';
        } elseif ($parentVisibility === 'private' || $parentVisibility === 'direct') {
            if ($visibility !== 'private') {
                $visibility = 'private';
            }
        } elseif ($parentVisibility === 'unlisted' && $visibility === 'public') {
            $visibility = 'unlisted';
        }
    }
    // A followers-only reply to somebody else's post is effectively a private
    // side conversation about that post's author. Keep VAAK's own composer from
    // creating that ambiguous privacy state; direct messages use ap_dm_send().
    if ($visibility === 'private' && $inReplyTo !== '') {
        $localActor = function_exists('ap_local_actor_id') ? rtrim(ap_local_actor_id(), '/') : rtrim(LOCAL_ACTOR, '/');
        if ($localActor === '' || !str_starts_with(rtrim($inReplyTo, '/'), $localActor . '/notes/')) {
            return ['ok' => false, 'error' => 'Followers-only replies are available only on your own posts. Use a direct message for a private conversation.'];
        }
    }

    // Replies inside a thread that started on Bluesky stay Bluesky-side. The
    // note is still stored locally and mirrored to Bluesky, but is never sent
    // to ActivityPub inboxes. Only mentions of local VAAK users are kept; a
    // Bluesky handle like @kevinr.example.com must never notify a same-named
    // fediverse actor, and nothing from the thread is queued for federation.
    $bskySide = $inReplyTo !== '' && $visibility !== 'local'
        && ap_reply_parent_is_bluesky_side($inReplyTo);

    // Mastodon-compatible addressing
    $publicId = 'https://www.w3.org/ns/activitystreams#Public';
    $followersId = $actor . '/followers';
    if ($visibility === 'local') {
        // Local-only posts are readable from VAAK's local store, but are never
        // sent to remote ActivityPub inboxes. Keep a local audience marker in
        // the stored AS2 object for clients that inspect it.
        $to = [$actor . '/followers'];
        $cc = [];
    } elseif ($visibility === 'unlisted') {
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
        // Resolve remote parent/author in the durable delivery worker so the
        // local reply is visible without waiting on another server's DNS/HTTP.
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
    // Collapse accidental duplicate reply seeds: "@alice.bsky.social @alice.bsky.social@bsky.app …"
    if ($content !== '' && function_exists('ap_collapse_duplicate_leading_mentions')) {
        $content = ap_collapse_duplicate_leading_mentions($content);
    }
    $mentionPack = null;
    $as2MentionTags = [];
    $as2MentionSeen = [];
    if ($content !== '' && function_exists('ap_masto_content_with_mentions')) {
        $mentionPack = ap_masto_content_with_mentions($content, $extraMentionActors);
        foreach (($mentionPack['mentions'] ?? []) as $m) {
            $mUrl = rtrim((string) ($m['url'] ?? ''), '/');
            if ($mUrl === '' || !str_starts_with($mUrl, 'https://')) {
                continue;
            }
            if (rtrim($mUrl, '/') === rtrim($actor, '/')) {
                continue;
            }
            // Bluesky-thread replies keep @mentions of local users. Bluesky
            // handles and remote ActivityPub actors stay off the Note.
            if ($bskySide && !preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $mUrl)) {
                continue;
            }
            if (isset($as2MentionSeen[$mUrl])) {
                continue;
            }
            $as2MentionSeen[$mUrl] = true;
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
    // Wafrn associates an answer with its Ask through an AskQuestion tag on
    // the Note. inReplyTo alone is rendered as an ordinary standalone reply.
    // Keep the representation as metadata (rather than prepending it to the
    // visible answer) so non-Wafrn clients do not show the question twice.
    $askContext = null;
    if ($inReplyTo !== '' && function_exists('ap_ask_by_id')) {
        $askContext = ap_ask_by_id($inReplyTo);
    }
    if (is_array($askContext)) {
        $askQuestion = trim((string) ($askContext['question'] ?? ''));
        $askerActor = rtrim(trim((string) ($askContext['asker_actor'] ?? '')), '/');
        if ($askQuestion !== '' && $askerActor !== '') {
            // Match Wafrn's representation so compatible clients can render
            // the asker and question, then remove the exact fragment when
            // they materialize the answer.  The old fragment linked to the
            // private /fediverse/asks URL (which has no public GET route),
            // omitted the asker entirely, and left remote clients with a
            // dangling “asked” link.  Link that label to the public answer
            // Note instead, as Wafrn does.
            $askRepresentation = ap_ask_representation_html($noteId, $askQuestion, $askerActor);
            // Wafrn uses AskQuestion.representation as the exact fragment to
            // hide from the visible answer. Include that fragment in object
            // content, matching Wafrn's own ActivityPub producer; clients
            // that understand the tag remove it while others still see the
            // complete Ask + answer context.
            $note['content'] = $askRepresentation . (string) ($note['content'] ?? '');
            $note['tag'][] = [
                'type' => 'AskQuestion',
                'name' => $askQuestion,
                'representation' => $askRepresentation,
                'actor' => $askerActor,
            ];
        }
    }
    if ($spoilerText !== '') {
        // ActivityPub / Mastodon CW text
        $note['summary'] = $spoilerText;
    }
    // Wafrn associates an answer with its AskQuestion tag. Pointing
    // inReplyTo at the standalone /fediverse/asks URL makes Wafrn try to
    // ingest that non-Post object as a normal thread parent, which can result
    // in a missing answer or a failed inbox job. Keep inReplyTo for ordinary
    // replies, but omit it for Ask answers.
    if ($inReplyTo !== '' && !is_array($askContext)) {
        $note['inReplyTo'] = $inReplyTo;
    }
    if ($as2MentionTags !== []) {
        // Preserve Wafrn's AskQuestion tag alongside ordinary Mention tags.
        $note['tag'] = array_merge(is_array($note['tag'] ?? null) ? $note['tag'] : [], $as2MentionTags);
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
    $quoteApprovalPending = false;
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
        // Resolve the quoted author and FEP-044f consent requirement in the
        // delivery worker. Unknown remote quotes are conservatively held there.
    }
    // Keep Create audience in sync after mention/quote audience mutations
    $note['to'] = $to;
    $note['cc'] = $cc;
    $attachments = ap_media_as2_attachments($mediaRows);
    if ($attachments) {
        $note['attachment'] = $attachments;
    }

    // Wafrn semantics: ActivityPub Note is canonical ("I'm actually me").
    // Bluesky is a mirror ("I'm just mirroring") stamped with fediverseId → note id.
    // Publish/sign fedi first, then mirror to Bluesky (Wafrn order; they sign both sides).
    $bsky = null;
    $bskyOwnerId = 0;

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
        // Do not fetch remote quote content on the compose request's critical
        // path. Remote preview enrichment is handled by normal cache warming.
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
        // Compose stores remote quotes as URL stubs (no sync fetch on the
        // critical path). Warm the target so the next Home paint can show the
        // full quoted text, media, and link preview.
        if (function_exists('ap_quote_target_warm_async')) {
            ap_quote_target_warm_async($quoteObjectId);
        } elseif (function_exists('ap_masto_ensure_remote_note_event') === false
            && is_file(__DIR__ . '/ap-masto-entities.php')) {
            require_once __DIR__ . '/ap-masto-entities.php';
            if (function_exists('ap_quote_target_warm_async')) {
                ap_quote_target_warm_async($quoteObjectId);
            }
        }
    }
    ap_metrics_record('Create', $actor, $noteId, null, strlen($contentHtml), 'compose', $feedSummary, null, null, null, '', false, $visibility);

    // Publish local visibility before the delivery queue can return.
    // Federation and mirroring remain background work.
    try {
        if ($noteId !== '' && function_exists('ap_timeline_fanout_local_status')) {
            $fanoutOwner = function_exists('ap_db_owner_user_id_for_actor')
                ? ap_db_owner_user_id_for_actor($actor)
                : 0;
            if ($fanoutOwner < 1 && function_exists('ap_db_masto_owner_user_id')) {
                $fanoutOwner = ap_db_masto_owner_user_id();
            }
            if ($fanoutOwner > 0) {
                $fanoutViews = ['home'];
                if ($visibility === 'public') {
                    $fanoutViews[] = 'local';
                }
                ap_timeline_fanout_local_status($fanoutOwner, [
                    'k' => 'outbox',
                    'id' => rtrim($noteId, '/'),
                    's' => 'local',
                ], $fanoutViews);
            }
        }
    } catch (Throwable $e) {
        error_log('[ap-inbox] local timeline fanout deferred after durable save');
    }

    // Local parent author and local @mentions get mentions rows here, including
    // Bluesky-thread replies that never enter an ActivityPub queue. Remote
    // delivery for ordinary replies is unchanged below.
    try {
        ap_publish_notify_local_reply_participants($create, $actor, $inReplyTo, $as2MentionTags);
    } catch (Throwable $e) {
        error_log('[ap-inbox] local reply notifications deferred: ' . $e->getMessage());
    }

    // The canonical post and its timeline row are committed above. Persist
    // federation/mirroring work before returning so remote slowness cannot
    // hold the compose request open, and a restart cannot discard delivery.
    // Bluesky-thread replies are not queued: both delivery queues stay empty
    // and the synchronous path below mirrors to Bluesky without fan-out.
    try {
        require_once __DIR__ . '/ap-publish-delivery.php';
        $deliveryOwnerId = function_exists('ap_db_owner_user_id_for_actor')
            ? ap_db_owner_user_id_for_actor($actor) : 0;
        // Bluesky has no native polls — never mirror Question posts there.
        $deliveryPayload = [
            'local_id' => $localId,
            'content' => $content,
            'visibility' => $visibility,
            'media_local_ids' => $mediaLocalIds,
            'spoiler_text' => $spoilerText,
            'in_reply_to' => $inReplyTo,
            'quote_object_id' => $quoteObjectId,
            'quote_approval_pending' => $quoteApprovalPending,
            'skip_bsky' => $pollNorm !== null,
            'skip_fedi' => $bskySide,
        ];
        if (!$bskySide && ap_publish_delivery_enqueue($deliveryOwnerId, $noteId, $deliveryPayload)) {
            // Best effort immediate wake; the scheduled worker is the durable
            // recovery path if process spawning is unavailable or interrupted.
            ap_publish_delivery_wake_async();
            if ($content !== '' && !$mediaRows) {
                try {
                    require_once __DIR__ . '/ap-link-preview.php';
                    $warmUrl = ap_link_preview_extract_url($content);
                    if ($warmUrl !== null) ap_link_preview_warm_async($warmUrl);
                } catch (Throwable $e) { /* preview warming is optional */ }
            }
            ap_log("publish_status queued create=$createId local_id=$localId visibility=$visibility"
                . ($pollNorm !== null ? ' skip_bsky=poll' : '')
                . ($bskySide ? ' skip_fedi=bsky_thread' : ''));
            return [
                'ok' => true, 'note_id' => $noteId, 'create_id' => $createId,
                'local_id' => $localId, 'published' => $published,
                'content_html' => $contentHtml, 'visibility' => $visibility,
                'delivered' => 0, 'queued' => 1, 'delivery_pending' => true,
                'bsky' => $pollNorm !== null
                    ? ['ok' => true, 'skipped' => true, 'error' => 'Polls are Fediverse-only']
                    : ['ok' => true, 'queued' => true],
            ];
        }
    } catch (Throwable $e) {
        // Preserve the established synchronous path if the durable queue is
        // unavailable; never silently claim success without recording work.
        error_log('[ap-inbox] publish queue unavailable; using synchronous delivery: ' . $e->getMessage());
    }

    $priorityExtra = [];
    // Priority-deliver to reply author + mentioned actors (not DMs — still public/unlisted/private Create).
    // Bluesky-thread replies skip this lookup entirely: no inbox fetch, no fan-out.
    $priorityActors = [];
    if (!$bskySide && is_string($replyActor) && $replyActor !== '') {
        $priorityActors[] = $replyActor;
    }
    if (!$bskySide) {
        foreach ($as2MentionTags as $mt) {
            $href = rtrim((string) ($mt['href'] ?? ''), '/');
            if ($href !== '') {
                $priorityActors[] = $href;
            }
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

    if ($bskySide) {
        $priorityExtra = [];
    }
    // Public: Bridgy + followers + shared inboxes. Unlisted/private: followers only.
    $fan = ($visibility === 'local' || $bskySide)
        ? ['delivered' => 0, 'queued' => 0, 'bridgy' => false]
        : (($visibility === 'public')
        ? ap_deliver_public_activity($create, $priorityExtra, $quoteApprovalPending)
        : ap_deliver_followers_activity($create, $priorityExtra));
    $delivered = $fan['delivered'];
    $queued = $fan['queued'];

    if ($quoteObjectId !== '') {
        // FEP-044f only applies to ActivityPub quote targets. Bluesky/Bridgy
        // URLs cannot Accept QuoteRequest — skip the useless fetch loop.
        $quoteIsBsky = function_exists('ap_quote_target_is_bluesky')
            ? ap_quote_target_is_bluesky($quoteObjectId)
            : (str_starts_with($quoteObjectId, 'https://bsky.app/')
                || str_starts_with($quoteObjectId, 'at://')
                || str_contains($quoteObjectId, 'bsky.brid.gy'));
        if (!$quoteIsBsky && $visibility !== 'local') {
            // FEP-044f requests explicit authorization from the quoted author. Until
            // their signed authorization arrives, Mastodon-compatible clients see
            // this quote as pending and VAAK suppresses the embedded post preview.
            ap_quote_request_send($quoteObjectId, $note);
        }
    }

    ap_log("publish_status create=$createId local_id=$localId visibility=$visibility delivered=$delivered queued=$queued bridgy=" . ($fan['bridgy'] ? '1' : '0')
        . ' kind=' . ($pollNorm !== null ? 'poll' : ($attachments ? 'media' : 'text')));

    // Bluesky mirror *after* fedi Create (canonical AP id already exists + was signed/delivered).
    // Record.fediverseId = noteId ("mirroring this fedi post"). Then stamp local Note with
    // FEP-fffd / blueskyUri so later fetches + optional Update can merge.
    //
    // Do NOT gate on ap_bsky_tab_enabled() here. That flag defaults false and is
    // only set in php-fpm env — queue/CLI publishers (ap-queue-publish, ap-cli-post)
    // were silently skipping Bluesky with no skip/fail log.
    $bsky = null;
    $bskyOwnerId = 0;
    try {
        if (!function_exists('ap_bsky_crosspost_status')) {
            require_once __DIR__ . '/ap-bsky.php';
        }
        if (function_exists('ap_bsky_crosspost_status')) {
            // Resolve the *publishing* actor's ap_users.id — never fall back to
            // the default/masto owner, or another account's posts could mirror
            // onto cmdr_nova's Bluesky (and vice versa).
            $bskyOwnerId = 0;
            if (function_exists('ap_db_owner_user_id_for_actor')) {
                $bskyOwnerId = ap_db_owner_user_id_for_actor($actor);
            }
            // Only mirror when this owner has a Bluesky session connected.
            if ($bskyOwnerId > 0 && function_exists('ap_bsky_session_row')
                && ap_bsky_session_row($bskyOwnerId) === null) {
                ap_log('bsky_crosspost_skip local_id=' . $localId
                    . ' err=no_bsky_session owner=' . $bskyOwnerId);
                $bskyOwnerId = 0;
            }
            if ($bskyOwnerId > 0) {
                if ($pollNorm !== null) {
                    $bsky = ['ok' => true, 'skipped' => true, 'error' => 'Polls are Fediverse-only'];
                    ap_log('bsky_crosspost_skip local_id=' . $localId . ' err=poll');
                } elseif ($quoteApprovalPending) {
                    // Do not leak an unapproved Fediverse quote through its
                    // Bluesky mirror. The Accept handler retries this mirror.
                    $bsky = ['ok' => true, 'skipped' => true, 'error' => 'Quote awaits Fediverse authorization'];
                    ap_log('bsky_crosspost_deferred_quote note=' . $localId);
                } else {
                    $bsky = ap_bsky_crosspost_status(
                        $bskyOwnerId,
                        $content,
                        $visibility,
                        $mediaLocalIds,
                        $spoilerText,
                        $inReplyTo !== '' ? $inReplyTo : null,
                        $quoteObjectId !== '' ? $quoteObjectId : null,
                        $noteId // fediverseId: Bluesky post is a mirror of this AP Note
                    );
                }
                if (!empty($bsky['ok']) && empty($bsky['skipped'])
                    && !empty($bsky['uri']) && is_string($bsky['uri'])
                    && function_exists('ap_note_attach_bsky_proxy')) {
                    // Bidirectional signing (Wafrn / FEP-fffd):
                    // - Bluesky root segment already has record.fediverseId = this Note
                    // - AP Note gets blueskyUri/blueskyCid + alternate Link → Bluesky *root*
                    // - bsky_crossposts map (tip) is written inside ap_bsky_crosspost_status
                    //   so later VAAK replies nest under the end of long-post threads.
                    // Do NOT overwrite that tip map with the root URI here.
                    $note = ap_note_attach_bsky_proxy(
                        $note,
                        (string) $bsky['uri'],
                        isset($bsky['cid']) ? (string) $bsky['cid'] : null
                    );
                    // Refresh stored Create/Note so GETs include FEP-fffd + blueskyUri.
                    $create['object'] = $note;
                    try {
                        ap_db()->prepare(
                            'UPDATE outbox_notes SET raw_create_json = ? WHERE id = ? OR id = ?'
                        )->execute([
                            json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                            $noteId,
                            $noteId . '/',
                        ]);
                    } catch (Throwable $e) {
                        error_log('[ap-inbox] bsky stamp outbox: ' . $e->getMessage());
                    }
                    // Best-effort Update so remotes that already stored Create learn the proxy link.
                    if (!$bskySide && in_array($visibility, ['public', 'unlisted'], true)) {
                        try {
                            $update = [
                                '@context' => 'https://www.w3.org/ns/activitystreams',
                                'id' => $actor . '/updates/' . bin2hex(random_bytes(8)),
                                'type' => 'Update',
                                'actor' => $actor,
                                'published' => gmdate('c'),
                                'to' => $to,
                                'cc' => $cc,
                                'object' => $note,
                            ];
                            if ($visibility === 'public') {
                                ap_deliver_public_activity($update, []);
                            } else {
                                ap_deliver_followers_activity($update, []);
                            }
                        } catch (Throwable $e) {
                            error_log('[ap-inbox] bsky proxy Update: ' . $e->getMessage());
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[ap-inbox] bsky_crosspost: ' . $e->getMessage());
        $bsky = ['ok' => false, 'error' => $e->getMessage()];
    }
    if (is_array($bsky) && !empty($bsky['ok']) && empty($bsky['skipped'])) {
        ap_log('bsky_crosspost local_id=' . $localId
            . ' posts=' . (int) ($bsky['posts'] ?? 0)
            . ' uris=' . implode(',', array_map('strval', $bsky['uris'] ?? []))
            . ' mirror=1 fedi_first=1'
            . ' bskyUri=' . ap_short((string) ($bsky['uri'] ?? ''))
            . ' tip=' . ap_short((string) ($bsky['tip_uri'] ?? $bsky['uri'] ?? ''))
            . (!empty($bsky['reply_nested']) ? ' reply=nested' : '')
            . (!empty($bsky['reply_fallback']) ? ' reply=fallback_re_link' : ''));
        if (function_exists('ap_bsky_crosspost_retry_mark_done')) {
            ap_bsky_crosspost_retry_mark_done($noteId);
        }
    } elseif (is_array($bsky) && !empty($bsky['skipped'])) {
        ap_log('bsky_crosspost_skip local_id=' . $localId
            . ' err=' . ap_short((string) ($bsky['error'] ?? 'skipped')));
        // Soft skips (e.g. reply parent not mirrored yet) get background retries.
        if (function_exists('ap_bsky_crosspost_should_retry') && ap_bsky_crosspost_should_retry($bsky)
            && function_exists('ap_bsky_crosspost_retry_enqueue') && $bskyOwnerId > 0) {
            $enq = ap_bsky_crosspost_retry_enqueue(
                $noteId,
                (int) $bskyOwnerId,
                (int) $localId,
                (string) ($bsky['error'] ?? 'skipped'),
                90
            );
            if (!empty($enq['queued'])) {
                ap_log('bsky_crosspost_retry_queued local_id=' . $localId
                    . ' err=' . ap_short((string) ($bsky['error'] ?? 'skipped')));
            }
        }
    } elseif (is_array($bsky) && (empty($bsky['ok']) || !empty($bsky['deferred'])) && empty($bsky['skipped'])) {
        $errLabel = !empty($bsky['deferred']) ? 'bsky_crosspost_deferred' : 'bsky_crosspost_fail';
        ap_log($errLabel . ' local_id=' . $localId . ' err=' . ap_short((string) ($bsky['error'] ?? 'fail')));
        // Always backfill on hard fail / deferred (video, budget, DNS, auth, rate limit).
        if ($bskyOwnerId > 0 && function_exists('ap_bsky_crosspost_retry_enqueue')
            && (!function_exists('ap_bsky_crosspost_should_retry') || ap_bsky_crosspost_should_retry($bsky))) {
            $delay = 60;
            if (!empty($bsky['rate_limited']) && function_exists('ap_bsky_result_retry_after_sec')) {
                $delay = ap_bsky_result_retry_after_sec($bsky, 300);
            } elseif (!empty($bsky['deferred'])) {
                $delay = 15;
            }
            $enq = ap_bsky_crosspost_retry_enqueue(
                $noteId,
                (int) $bskyOwnerId,
                (int) $localId,
                (string) ($bsky['error'] ?? 'fail'),
                $delay
            );
            if (!empty($enq['queued'])) {
                $bsky['retry_queued'] = true;
                ap_log('bsky_crosspost_retry_queued local_id=' . $localId
                    . ' err=' . ap_short((string) ($bsky['error'] ?? 'fail'))
                    . ' delay=' . $delay
                    . (!empty($bsky['rate_limited']) ? ' rate_limited=1' : ''));
            }
            if (!empty($bsky['rate_limited']) && function_exists('ap_bsky_crosspost_retry_pause_owner')) {
                ap_bsky_crosspost_retry_pause_owner(
                    (int) $bskyOwnerId,
                    $delay,
                    'Rate limit exceeded; paused owner queue'
                );
            }
        }
    } elseif ($bsky === null && function_exists('ap_bsky_crosspost_status')) {
        // Owner has no mapped user id / session path — make that visible in logs.
        ap_log('bsky_crosspost_skip local_id=' . $localId . ' err=no_owner_session_mapping');
    }

    // Warm link-preview cache asynchronously; remote DNS/HTTP must not hold
    // the publish request open. The worker is deduplicated and capped.
    if ($content !== '' && !$mediaRows && function_exists('ap_link_preview_extract_url') && function_exists('ap_link_preview_warm_async')) {
        try {
            require_once __DIR__ . '/ap-link-preview.php';
            $warmUrl = ap_link_preview_extract_url($content);
            if ($warmUrl !== null) {
                ap_link_preview_warm_async($warmUrl);
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
        'bsky' => $bsky,
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
        if (is_array($toDec)) {
            $to = $toDec;
        }
        if (is_array($ccDec)) {
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
    if (function_exists('ap_public_doc_invalidate_note')) {
        $storedCreate = is_array($obRow) ? trim((string) ($obRow['create_id'] ?? '')) : '';
        ap_public_doc_invalidate_note($noteId, $createId);
        if ($storedCreate !== '' && $storedCreate !== $createId) {
            ap_public_doc_cache_forget($storedCreate);
        }
    }
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

    // Edits are ActivityPub-only. Bluesky records are immutable, so never send
    // an edit through the Bridgy bridge or any Bluesky crosspost path.
    $fan = ap_deliver_public_activity($update, [], true);
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
 * Vote on a remote poll (remote_polls row) as the session actor and federate
 * the ballot to the poll author through the durable fan-out queue.
 *
 * @param int|string $pollRef public poll id (int) or the poll's note id
 * @param list<int|string> $choices indexes or option titles
 * @return array{ok:bool,error?:string,note_id?:string,own_votes?:list<int>,queued?:int}
 */
function ap_remote_poll_vote(int|string $pollRef, string $voterActorId, array $choices): array
{
    $row = ap_remote_poll_find($pollRef);
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'Poll not found'];
    }
    $noteId = rtrim((string) ($row['note_id'] ?? ''), '/');
    $ident = ap_outbound_identity();
    $voter = rtrim($voterActorId, '/');
    // Only ever sign as the account that is voting.
    if ($voter === '' || $voter !== rtrim((string) $ident['id'], '/')) {
        return ['ok' => false, 'error' => 'Not signed in'];
    }
    if (ap_remote_poll_own_votes($noteId, $voter) !== null) {
        return ['ok' => false, 'error' => 'Already voted'];
    }
    // Older rows predate actor_id: ask the poll's origin who wrote it (and
    // take its current options and counts while at it).
    $author = rtrim((string) ($row['actor_id'] ?? ''), '/');
    if ($author === '') {
        $doc = ap_fetch_as2_object($noteId);
        $fresh = ap_remote_poll_from_doc($doc);
        if (is_array($fresh) && $fresh['note_id'] === $noteId) {
            ap_remote_poll_store($doc);
            $row = ap_remote_poll_find($noteId) ?? $row;
            $author = $fresh['actor_id'];
        }
    }
    if ($author === '') {
        return ['ok' => false, 'error' => 'Could not resolve poll author'];
    }
    $ballot = ap_remote_poll_ballot($row, $choices);
    if (empty($ballot['ok'])) {
        return ['ok' => false, 'error' => (string) ($ballot['error'] ?? 'Vote failed')];
    }
    $indexes = $ballot['indexes'];
    if (ap_is_blocked_actor($author)) {
        return ['ok' => false, 'error' => 'Poll author is blocked'];
    }
    $doc = ap_fetch_actor_doc($author);
    $inbox = is_array($doc) ? ap_resolve_personal_inbox_from_actor_doc($doc) : null;
    if (!is_string($inbox) || !str_starts_with($inbox, 'https://') || ap_is_blocked_inbox($inbox)) {
        return ['ok' => false, 'error' => 'Poll author has no inbox'];
    }

    $options = json_decode((string) ($row['options_json'] ?? '[]'), true);
    $titles = [];
    foreach ($indexes as $i) {
        $titles[] = (string) ($options[$i]['title'] ?? '');
    }
    try {
        if (!ap_remote_poll_record_vote($row, $voter, $indexes)) {
            return ['ok' => false, 'error' => 'Already voted'];
        }
    } catch (Throwable $e) {
        error_log('[ap-inbox] remote poll vote store: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not record vote'];
    }
    $queued = 0;
    foreach (ap_remote_poll_vote_activities($voter, $author, $noteId, $titles) as $create) {
        if (ap_deliver_fanout_background($create, [$inbox], $ident['key_id'], $ident['priv'])) {
            $queued++;
        }
    }
    if ($queued < 1) {
        ap_remote_poll_forget_vote($row, $voter);
        return ['ok' => false, 'error' => 'Could not deliver vote'];
    }
    ap_log('remote_poll_vote note=' . ap_short($noteId) . ' choices=' . implode(',', $indexes) . ' queued=' . $queued);
    return ['ok' => true, 'note_id' => $noteId, 'own_votes' => $indexes, 'queued' => $queued];
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
    // Remove linked Bluesky crosspost(s) before deleting the local mapping.
    // Best-effort: ActivityPub Delete remains authoritative for fedi.
    $bskyDelete = null;
    try {
        if (!function_exists('ap_bsky_delete_crosspost_for_note')) {
            require_once __DIR__ . '/ap-bsky.php';
        }
        if (function_exists('ap_bsky_delete_crosspost_for_note')) {
            $bskyOwner = 0;
            if (function_exists('ap_db_owner_user_id_for_actor')) {
                $actorHint = $noteId;
                if (preg_match('#^(https://mkultra\.monster/users/[A-Za-z0-9_]+)/notes/#', $noteId, $am)) {
                    $actorHint = $am[1];
                }
                $bskyOwner = (int) ap_db_owner_user_id_for_actor($actorHint);
            }
            if ($bskyOwner < 1 && function_exists('ap_db_default_owner_user_id')) {
                $bskyOwner = (int) ap_db_default_owner_user_id();
            }
            if ($bskyOwner > 0) {
                $bskyDelete = ap_bsky_delete_crosspost_for_note($bskyOwner, $noteId);
            }
        }
    } catch (Throwable $e) {
        error_log('[ap-inbox] bsky crosspost delete: ' . $e->getMessage());
    }

    $deleteId = $actor . '/deletes/' . bin2hex(random_bytes(8));
    $published = gmdate('c');
    $to = ['https://www.w3.org/ns/activitystreams#Public'];
    $cc = [$actor . '/followers'];

    // Prefer original audience from outbox when present
    $ob = ap_db()->prepare('SELECT to_json, cc_json, create_id FROM outbox_notes WHERE id = ?');
    $ob->execute([$noteId]);
    $obRow = $ob->fetch();
    if (is_array($obRow)) {
        $toDec = json_decode((string) ($obRow['to_json'] ?? ''), true);
        $ccDec = json_decode((string) ($obRow['cc_json'] ?? ''), true);
        if (is_array($toDec)) {
            $to = $toDec;
        }
        if (is_array($ccDec)) {
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

    $deletedCreateId = is_array($obRow) ? (string) ($obRow['create_id'] ?? '') : '';
    if (function_exists('ap_public_doc_invalidate_note')) {
        ap_public_doc_invalidate_note($noteId, $deletedCreateId !== '' ? $deletedCreateId : null);
    }
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
    if (function_exists('ap_notification_projection_invalidate_status')) {
        ap_notification_projection_invalidate_status($noteId);
    }
    // Mentions, replies, likes, boosts, and quotes stay active unless the
    // mention row itself is soft-deleted. invalidate_status keys projection
    // by numeric status id, not the note URI.
    if (function_exists('ap_mention_soft_delete')) {
        ap_mention_soft_delete($noteId);
    }
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
 * Create activity addressed only to one peer. Local warning markers are omitted.
 *
 * @param list<array<string,mixed>> $attachments
 * @return array<string,mixed>
 */
function ap_dm_build_activity(
    string $actor,
    string $peer,
    string $contentHtml,
    string $noteId,
    string $createId,
    string $published,
    array $attachments,
    string $inReplyTo,
    bool $sensitive,
    string $spoiler
): array {
    $peer = rtrim($peer, '/');
    $path = parse_url($peer, PHP_URL_PATH);
    $name = is_string($path) && $path !== '' ? ltrim((string) basename($path), '@') : 'user';
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'user';
    }
    $note = [
        'id' => $noteId,
        'type' => 'Note',
        'published' => $published,
        'attributedTo' => $actor,
        'content' => $contentHtml !== '' ? $contentHtml : '<p></p>',
        'to' => [$peer],
        'cc' => [],
        'tag' => [[
            'type' => 'Mention',
            'href' => $peer,
            'name' => '@' . $name,
        ]],
        'url' => $noteId,
    ];
    if ($inReplyTo !== '') {
        $note['inReplyTo'] = $inReplyTo;
    }
    $federated = function_exists('ap_dm_federation_attachments')
        ? ap_dm_federation_attachments($attachments)
        : $attachments;
    if ($federated !== []) {
        $note['attachment'] = array_values($federated);
    }
    $spoiler = trim(strip_tags($spoiler));
    $spoiler = function_exists('mb_substr') ? mb_substr($spoiler, 0, 500) : substr($spoiler, 0, 500);
    if ($sensitive || $spoiler !== '') {
        $note['sensitive'] = true;
        if ($spoiler !== '') {
            $note['summary'] = $spoiler;
        }
    }
    return [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $createId,
        'type' => 'Create',
        'actor' => $actor,
        'published' => $published,
        'to' => [$peer],
        'cc' => [],
        'object' => $note,
    ];
}

/**
 * Send a direct message (no public audience). Stored only in direct_messages.
 * Media stays unattached to any status so it is not listed on a profile.
 *
 * @param list<int> $mediaLocalIds
 * @return array{ok:bool,error?:string,id?:int,note_id?:string,create_id?:string,delivered?:int,peer?:string}
 */
function ap_dm_send(
    string $content,
    string $toActorOrHandle,
    ?string $inReplyTo = null,
    array $mediaLocalIds = [],
    string $spoilerText = '',
    ?bool $sensitive = null
): array {
    $content = trim(ap_fix_utf8($content));
    if (mb_strlen($content) > 2000) {
        return ['ok' => false, 'error' => 'DM text is too long (max 2000 chars)'];
    }
    $mediaLocalIds = array_values(array_unique(array_filter(
        array_map('intval', $mediaLocalIds),
        static fn(int $id): bool => $id > 0
    )));
    if (count($mediaLocalIds) > 4) {
        return ['ok' => false, 'error' => 'Too many media attachments (max 4)'];
    }
    $spoilerText = trim(strip_tags(ap_fix_utf8($spoilerText)));
    $spoilerText = mb_substr($spoilerText, 0, 500);
    $sensitive = ($sensitive ?? false) || $spoilerText !== '';
    if ($content === '' && $mediaLocalIds === []) {
        return ['ok' => false, 'error' => 'DM text or an attachment is required (max 2000 chars)'];
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

    $rows = [];
    if ($mediaLocalIds !== []) {
        if (!function_exists('ap_media_by_local_ids') || !function_exists('ap_media_as2_attachments')) {
            require_once __DIR__ . '/ap-r2.php';
        }
        $byId = [];
        foreach (ap_media_by_local_ids($mediaLocalIds) as $mediaRow) {
            if (is_array($mediaRow)) {
                $byId[(int) ($mediaRow['local_id'] ?? 0)] = $mediaRow;
            }
        }
        foreach ($mediaLocalIds as $mediaId) {
            if (!isset($byId[$mediaId])) {
                return ['ok' => false, 'error' => 'Media attachment not found'];
            }
            $rows[] = $byId[$mediaId];
        }
    }
    $as2 = $rows !== [] ? ap_media_as2_attachments($rows) : [];
    $safeAs2 = [];
    foreach ($as2 as $att) {
        if (!is_array($att)) {
            continue;
        }
        $href = function_exists('ap_dm_attachment_href') ? ap_dm_attachment_href($att) : null;
        if (!is_string($href) || ap_profile_sanitize_https_url($href) === null) {
            return ['ok' => false, 'error' => 'Media URL is not a safe https address'];
        }
        $mime = strtolower((string) ($att['mediaType'] ?? ''));
        if (strcasecmp((string) ($att['type'] ?? ''), 'Document') === 0 && str_starts_with($mime, 'video/')) {
            $att['type'] = 'Video';
        }
        $safeAs2[] = $att;
    }
    if (count($safeAs2) !== count($rows)) {
        return ['ok' => false, 'error' => 'Media URL is not a safe https address'];
    }

    $contentHtml = $content !== '' ? ap_plain_text_to_html($content) : '<p></p>';
    $noteId = $actor . '/notes/' . bin2hex(random_bytes(8));
    $createId = $actor . '/creates/' . bin2hex(random_bytes(8));
    $published = gmdate('c');
    $create = ap_dm_build_activity(
        $actor,
        $peer,
        $contentHtml,
        $noteId,
        $createId,
        $published,
        $safeAs2,
        $inReplyTo,
        $sensitive,
        $spoilerText
    );
    if (!ap_dm_activity_audience_is_direct($create, $peer)) {
        return ['ok' => false, 'error' => 'DM audience must be the recipient only'];
    }
    $storedAttachments = ap_dm_attachments_for_store($safeAs2, $sensitive, $spoilerText);
    $mediaUrls = [];
    foreach (ap_dm_media_items(['attachments' => $storedAttachments ?? []]) as $item) {
        $mediaUrls[] = (string) $item['url'];
    }

    $stored = ap_dm_store([
        'activity_id' => $createId,
        'object_id' => $noteId,
        'peer_actor_id' => $peer,
        'direction' => 'out',
        'content' => $content,
        'in_reply_to' => $inReplyTo !== '' ? $inReplyTo : null,
        'media_urls' => $mediaUrls,
        'attachments' => $storedAttachments,
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
function ap_cmdr_send_like(string $objectId, string $targetActor, ?string $stableLikeId = null): array
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

    $likeId = is_string($stableLikeId) && str_starts_with($stableLikeId, $actor . '/likes/')
        ? $stableLikeId : $actor . '/likes/' . bin2hex(random_bytes(16));
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
function ap_cmdr_send_undo_like(string $likeActivityId, string $objectId, string $targetActor, ?string $stableUndoId = null): array
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
    $undoId = is_string($stableUndoId) && str_starts_with($stableUndoId, $actor . '/undos/')
        ? $stableUndoId : $actor . '/undos/' . bin2hex(random_bytes(12));
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
function ap_cmdr_send_announce(string $objectId, ?string $targetActor = null, ?string $stableAnnounceId = null): array
{
    $objectId = trim($objectId);
    if ($objectId === '' || !str_starts_with($objectId, 'https://')) {
        return ['ok' => false, 'error' => 'invalid object'];
    }
    $objectId = preg_replace('/#announce-\d+$/', '', $objectId) ?? $objectId;
    $ident = ap_outbound_identity();
    $actor = $ident['id'];
    $announceId = is_string($stableAnnounceId) && str_starts_with($stableAnnounceId, $actor . '/announces/')
        ? $stableAnnounceId : $actor . '/announces/' . bin2hex(random_bytes(16));
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
function ap_cmdr_send_undo_announce(string $announceActivityId, string $objectId, ?string $targetActor = null, ?string $stableUndoId = null): array
{
    $announceActivityId = trim($announceActivityId);
    $objectId = trim($objectId);
    if ($announceActivityId === '' || !str_starts_with($announceActivityId, 'https://')) {
        return ['ok' => false, 'error' => 'invalid announce id'];
    }
    $ident = ap_outbound_identity();
    $actor = $ident['id'];
    $undoId = is_string($stableUndoId) && str_starts_with($stableUndoId, $actor . '/undos/')
        ? $stableUndoId : $actor . '/undos/' . bin2hex(random_bytes(12));
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
    return ['ok' => $delivered > 0, 'delivered' => $delivered,
        'error' => $delivered > 0 ? null : 'No remote inbox accepted the Undo'];
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
