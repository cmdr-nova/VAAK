<?php
/**
 * Eternal "chance sightings": the game (repo cmdr-nova/eternal)
 * reports where a pilot is flying, and other pilots now and then meet an echo
 * of that ship as traffic. Nothing here is live multiplayer.
 *
 * GET  /api/eternal-sightings.php?system=3&exclude=<pilot>
 *      -> {"sightings":[{"name","paint","from","to","ago"}]} (up to 6, random, last 7 days)
 * POST /api/eternal-sightings.php  {"pilot","name","system","paint","from","to"}
 *      -> {"ok":true}
 *
 * Storage is Redis only (one hash per star system, 8 day TTL). Sightings are
 * throwaway flavor, so losing them on a Redis flush is fine. No accounts, no
 * IPs stored: the pilot id is a random token the game keeps in localStorage.
 * The game lives at https://eternal.mkultra.monster (and used to be on this
 * site), so those two origins get CORS headers. Optional ETERNAL_NAME_DENY env:
 * comma-separated words; a callsign containing one is refused.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

require_once __DIR__ . '/ap-redis.php';

const ETERNAL_SIGHT_PREFIX = 'eternal:sightings:v1:';
const ETERNAL_SIGHT_TTL = 8 * 86400;
const ETERNAL_SIGHT_MAX_AGE = 7 * 86400;
const ETERNAL_SIGHT_CAP = 300; // pilots kept per system
const ETERNAL_SIGHT_MAX_SYSTEM = 63;
const ETERNAL_SIGHT_ORIGINS = ['https://mkultra.monster', 'https://eternal.mkultra.monster'];

function eternal_sight_reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function eternal_sight_system($value): ?int
{
    if (is_int($value)) {
        $n = $value;
    } elseif (is_string($value) && preg_match('/^\d{1,2}$/', $value)) {
        $n = (int) $value;
    } else {
        return null;
    }
    return $n >= 0 && $n <= ETERNAL_SIGHT_MAX_SYSTEM ? $n : null;
}

function eternal_sight_pilot($value): ?string
{
    return is_string($value) && preg_match('/^[a-f0-9]{16,40}$/', $value) ? $value : null;
}

/** Callsigns: 2-20 letters, digits, spaces and a little punctuation. */
function eternal_sight_name($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $name = trim((string) preg_replace('/\s+/u', ' ', $value));
    if (!preg_match('/^[\p{L}\p{N} _.\'-]{2,20}$/u', $name)) {
        return null;
    }
    $deny = array_filter(array_map('trim', explode(',', strtolower((string) getenv('ETERNAL_NAME_DENY')))));
    $flat = mb_strtolower(str_replace([' ', '_', '.', '-', "'"], '', $name));
    foreach ($deny as $word) {
        if ($word !== '' && str_contains($flat, $word)) {
            return null;
        }
    }
    return $name;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if (in_array($origin, ETERNAL_SIGHT_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

if ($method === 'OPTIONS') {
    // CORS preflight for the JSON POST.
    if (!in_array($origin, ETERNAL_SIGHT_ORIGINS, true)) {
        eternal_sight_reply(403, ['error' => 'wrong origin']);
    }
    header('Access-Control-Allow-Methods: GET, POST');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

$redis = ap_redis_client('cache');
if (!$redis) {
    eternal_sight_reply(503, ['error' => 'sightings unavailable']);
}

if ($method === 'GET') {
    $system = eternal_sight_system($_GET['system'] ?? null);
    if ($system === null) {
        eternal_sight_reply(400, ['error' => 'bad system']);
    }
    $exclude = eternal_sight_pilot($_GET['exclude'] ?? null);
    try {
        $all = $redis->hGetAll(ETERNAL_SIGHT_PREFIX . $system);
    } catch (Throwable $e) {
        eternal_sight_reply(503, ['error' => 'sightings unavailable']);
    }
    $now = time();
    $out = [];
    foreach (is_array($all) ? $all : [] as $pilot => $json) {
        if ($pilot === $exclude) {
            continue;
        }
        $row = json_decode((string) $json, true);
        if (!is_array($row) || !isset($row['at']) || $now - (int) $row['at'] > ETERNAL_SIGHT_MAX_AGE) {
            continue;
        }
        $out[] = [
            'name' => (string) ($row['name'] ?? ''),
            'paint' => (string) ($row['paint'] ?? ''),
            'from' => (string) ($row['from'] ?? ''),
            'to' => (string) ($row['to'] ?? ''),
            'ago' => max(0, $now - (int) $row['at']),
        ];
    }
    shuffle($out);
    eternal_sight_reply(200, ['sightings' => array_slice($out, 0, 6)]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST, OPTIONS');
    eternal_sight_reply(405, ['error' => 'method not allowed']);
}

// Only the game should report sightings.
if ($origin !== '' && !in_array($origin, ETERNAL_SIGHT_ORIGINS, true)) {
    eternal_sight_reply(403, ['error' => 'wrong origin']);
}

$raw = file_get_contents('php://input', false, null, 0, 2048);
$data = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($data)) {
    eternal_sight_reply(400, ['error' => 'bad json']);
}
$pilot = eternal_sight_pilot($data['pilot'] ?? null);
$name = eternal_sight_name($data['name'] ?? null);
$system = eternal_sight_system($data['system'] ?? null);
$paint = is_string($data['paint'] ?? null) && preg_match('/^[a-z]{2,16}$/', $data['paint']) ? $data['paint'] : null;
$from = is_string($data['from'] ?? null) && preg_match('/^s\d{1,2}$/', $data['from']) ? $data['from'] : null;
$to = is_string($data['to'] ?? null) && preg_match('/^s\d{1,2}$/', $data['to']) ? $data['to'] : null;
if ($pilot === null || $name === null || $system === null || $paint === null || $from === null || $to === null) {
    eternal_sight_reply(400, ['error' => 'bad sighting']);
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$ipCount = ap_redis_rate_count('eternal-sight:ip:' . $ip, 600);
$pilotCount = ap_redis_rate_count('eternal-sight:pilot:' . $pilot, 45);
if (($ipCount !== null && $ipCount > 30) || ($pilotCount !== null && $pilotCount > 1)) {
    eternal_sight_reply(429, ['error' => 'slow down']);
}

$key = ETERNAL_SIGHT_PREFIX . $system;
try {
    $redis->hSet($key, $pilot, json_encode([
        'name' => $name,
        'paint' => $paint,
        'from' => $from,
        'to' => $to,
        'at' => time(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $redis->expire($key, ETERNAL_SIGHT_TTL);
    // Keep each system's list small: drop the oldest pilots past the cap.
    if ((int) $redis->hLen($key) > ETERNAL_SIGHT_CAP) {
        $ages = [];
        foreach ($redis->hGetAll($key) as $id => $json) {
            $row = json_decode((string) $json, true);
            $ages[$id] = is_array($row) ? (int) ($row['at'] ?? 0) : 0;
        }
        asort($ages);
        $drop = array_slice(array_keys($ages), 0, count($ages) - ETERNAL_SIGHT_CAP);
        if ($drop) {
            $redis->hDel($key, ...$drop);
        }
    }
} catch (Throwable $e) {
    eternal_sight_reply(503, ['error' => 'sightings unavailable']);
}
eternal_sight_reply(200, ['ok' => true]);
