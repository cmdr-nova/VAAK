<?php
/**
 * Low-priority fetch of recent public notes for follows whose server has gone
 * quiet. One shot per invocation; the timer decides the schedule.
 *
 *   php ap-follow-fallback.php
 *
 * Kill switch: VAAK_FOLLOW_FALLBACK=0
 * Force hosts:  VAAK_FOLLOW_FALLBACK_HOSTS=misskey.io   (default)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CLI only\n";
    exit(1);
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-inbox.php';

function ap_follow_fallback_interval_path(): string
{
    $dir = getenv('VAAK_FOLLOW_FALLBACK_STATE_DIR');
    if (!is_string($dir) || $dir === '' || !is_dir($dir)) {
        $dir = sys_get_temp_dir();
    }
    return rtrim($dir, '/') . '/ap-follow-fallback-last';
}

/** @return array{status:int,body:?string,etag:string,last_modified:string,requests:int} */
function ap_follow_fallback_http(string $url, ?string $etag, ?string $lastModified): array
{
    $empty = ['status' => 0, 'body' => null, 'etag' => '', 'last_modified' => '', 'requests' => 1];
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
        return $empty;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '' || (function_exists('ap_host_resolves_public') && !ap_host_resolves_public($host))) {
        return $empty;
    }
    if (!function_exists('curl_init')) {
        return $empty;
    }
    $headers = [
        'Accept: application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
        'User-Agent: ' . (function_exists('ap_http_user_agent') ? ap_http_user_agent() : 'vaak/1.0'),
    ];
    $etag = ap_follow_fallback_validator($etag);
    $lastModified = ap_follow_fallback_validator($lastModified);
    if ($etag !== null) {
        $headers[] = 'If-None-Match: ' . $etag;
    }
    if ($lastModified !== null) {
        $headers[] = 'If-Modified-Since: ' . $lastModified;
    }
    $responseHeaders = [];
    $ch = curl_init($url);
    if ($ch === false) {
        return $empty;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
            $trim = trim($line);
            if (str_contains($trim, ':')) {
                [$name, $value] = explode(':', $trim, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
    ]);
    if (defined('CURL_IPRESOLVE_V4')) {
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $requests = 1;
    if (($status === 401 || $status === 403) && function_exists('ap_signed_get_ex')) {
        $signed = ap_signed_get_ex($url);
        $requests++;
        if (is_array($signed) && (int) ($signed['status'] ?? 0) >= 200 && (int) ($signed['status'] ?? 0) < 300) {
            $status = (int) $signed['status'];
            $body = $signed['body'] ?? null;
        }
    }
    $text = is_string($body) ? $body : null;
    if ($status >= 400 && function_exists('ap_delivery_log_result')) {
        ap_delivery_log_result($host, 'Get', $status, false, (string) $text, (string) ($parts['path'] ?? ''));
    }
    return [
        'status' => $status,
        'body' => $text,
        'etag' => (string) ($responseHeaders['etag'] ?? ''),
        'last_modified' => (string) ($responseHeaders['last-modified'] ?? ''),
        'requests' => $requests,
    ];
}

/** @return array{outbox:string,featured:string} */
function ap_follow_fallback_actor_urls(string $actorId): array
{
    $empty = ['outbox' => '', 'featured' => ''];
    if (!function_exists('ap_fetch_actor_doc')) {
        return $empty;
    }
    $doc = ap_fetch_actor_doc($actorId);
    if (!is_array($doc)) {
        return $empty;
    }
    return [
        'outbox' => ap_follow_state_id($doc['outbox'] ?? null),
        'featured' => ap_follow_state_id($doc['featured'] ?? null),
    ];
}

function ap_follow_fallback_ingest(array $activity): bool
{
    $actor = ap_follow_state_id($activity['actor'] ?? null);
    if ($actor !== '' && function_exists('ap_is_blocked_actor') && ap_is_blocked_actor($actor)) {
        return false;
    }
    $object = $activity['object'] ?? null;
    $objectId = is_array($object) ? ap_follow_state_id($object['id'] ?? null) : ap_follow_state_id($object);
    if ($objectId !== '') {
        $host = strtolower((string) (parse_url($objectId, PHP_URL_HOST) ?: ''));
        if ($host !== '' && function_exists('ap_is_blocked_host') && ap_is_blocked_host($host)) {
            return false;
        }
    }
    try {
        $action = ap_route_verified_activity($activity, 0);
    } catch (Throwable $e) {
        error_log('[ap-follow-fallback] ingest failed: ' . $e->getMessage());
        return false;
    }
    return is_string($action) && $action !== '' && !str_contains($action, 'fail');
}

$env = [];
foreach ([
    'VAAK_FOLLOW_FALLBACK',
    'VAAK_FOLLOW_FALLBACK_HOSTS',
    'VAAK_FOLLOW_FALLBACK_SILENCE_HOURS',
    'VAAK_FOLLOW_FALLBACK_PENDING_HOURS',
    'VAAK_FOLLOW_FALLBACK_PAGE_LIMIT',
    'VAAK_FOLLOW_FALLBACK_MAX_ACCOUNTS',
    'VAAK_FOLLOW_FALLBACK_MIN_INTERVAL_MINUTES',
] as $key) {
    $value = getenv($key);
    if ($value !== false) {
        $env[$key] = $value;
    }
}
$config = ap_follow_fallback_config($env);
if (!$config['enabled']) {
    fwrite(STDOUT, "fallback disabled\n");
    exit(0);
}
$now = time();
$stampPath = ap_follow_fallback_interval_path();
$last = is_file($stampPath) ? (int) trim((string) @file_get_contents($stampPath)) : 0;
if (!ap_follow_fallback_due($last, $now, (int) $config['min_interval_seconds'])) {
    fwrite(STDOUT, "fallback skipped interval\n");
    exit(0);
}
@file_put_contents($stampPath, (string) $now, LOCK_EX);

try {
    $result = ap_follow_fallback_execute(
        ap_db(),
        $env,
        $now,
        'ap_follow_fallback_http',
        'ap_follow_fallback_ingest',
        static function (string $objectId): bool {
            return ap_follow_object_known(ap_db(), $objectId);
        },
        'ap_follow_fallback_actor_urls'
    );
} catch (Throwable $e) {
    error_log('[ap-follow-fallback] ' . $e->getMessage());
    fwrite(STDERR, "fallback failed\n");
    exit(1);
}

$summary = 'fallback accounts=' . (int) $result['accounts']
    . ' requests=' . (int) $result['requests']
    . ' imported=' . (int) $result['imported']
    . ' not_modified=' . (int) $result['not_modified']
    . ' errors=' . (int) $result['errors'];
ap_delivery_log_append(ap_delivery_log_path(), $summary, 262144);
fwrite(STDOUT, $summary . "\n");
exit(empty($result['ok']) ? 1 : 0);
