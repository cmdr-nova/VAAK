<?php
declare(strict_types=1);

/**
 * Optional Redis acceleration/coordination layer.
 *
 * Redis is never authoritative for VAAK data. Every helper is best-effort and
 * returns a neutral result when the extension, configuration, or service is
 * unavailable so callers can fall back to the database/filesystem paths.
 *
 * A read timeout must never be 0: phpredis treats 0 as "block forever". On
 * 2026-11-18 the host's disk latency pushed replies past the 80ms budget.
 * phpredis raised "read error on connection", then the cached socket sat in
 * poll() with no timeout and two CLI workers (listenbrainz, notification
 * projection fill) held their flock until they were killed. Web requests keep
 * the 80ms budget so a slow Redis is a cache miss. CLI workers are not on the
 * request path and hold a lock across many calls, so they wait 0.40s: long
 * enough to absorb a brief provider stall that has already exceeded 80ms
 * (those spikes land in the low hundreds of milliseconds, not many seconds)
 * and short enough that a dead Redis cannot pin the lock. 0.40s is inside
 * the 0.25–0.5s range; 0.25s would still trip on the same class of stall,
 * and 0.5s would make every call in a tight worker loop wait the full half
 * second while Redis is gone.
 */
function ap_redis_read_timeout(): float
{
    $timeout = PHP_SAPI === 'cli' ? 0.40 : 0.08;
    return $timeout > 0.0 ? $timeout : 0.08;
}

/**
 * @return array{clients:array<string,Redis|null>,attempted:array<string,bool>}
 */
function &ap_redis_runtime(): array
{
    static $state = [
        'clients' => [],
        'attempted' => [],
    ];
    return $state;
}

function ap_redis_purpose(string $purpose): string
{
    return $purpose === 'queue' ? 'queue' : 'cache';
}

/**
 * Close a broken socket and forget it. The next ap_redis_client() call dials
 * again instead of reusing the connection that just raised a read error.
 */
function ap_redis_discard_client(string $purpose): void
{
    $purpose = ap_redis_purpose($purpose);
    $state = &ap_redis_runtime();
    $client = $state['clients'][$purpose] ?? null;
    if ($client instanceof Redis) {
        try {
            $client->close();
        } catch (Throwable $e) {
            // The socket is already dead.
        }
    }
    unset($state['clients'][$purpose], $state['attempted'][$purpose]);
}

function ap_redis_fail(string $purpose, Throwable $e, string $op): void
{
    error_log('[ap-redis] ' . $op . ' failed: ' . $e->getMessage());
    ap_redis_discard_client($purpose);
}

function ap_redis_apply_read_timeout(Redis $redis, float $seconds): void
{
    if ($seconds <= 0.0) {
        $seconds = ap_redis_read_timeout();
    }
    if (defined('Redis::OPT_READ_TIMEOUT')) {
        $redis->setOption(Redis::OPT_READ_TIMEOUT, $seconds);
    }
}

function ap_redis_client(string $purpose = 'cache'): ?Redis
{
    $state = &ap_redis_runtime();
    $purpose = ap_redis_purpose($purpose);
    if (($state['attempted'][$purpose] ?? false) === true) {
        $client = $state['clients'][$purpose] ?? null;
        return $client instanceof Redis ? $client : null;
    }
    $state['attempted'][$purpose] = true;
    if (!class_exists('Redis')) {
        return null;
    }
    $enabled = getenv('VAAK_REDIS_ENABLED');
    if ($enabled !== false && in_array(strtolower(trim((string) $enabled)), ['0', 'false', 'off', 'no'], true)) {
        return null;
    }
    $dsn = trim((string) (getenv('VAAK_REDIS_DSN') ?: 'redis://127.0.0.1:6379/0'));
    $parts = parse_url($dsn);
    if (!is_array($parts) || !isset($parts['host'])) {
        error_log('[ap-redis] invalid VAAK_REDIS_DSN');
        return null;
    }
    $host = (string) $parts['host'];
    $port = (int) ($parts['port'] ?? 6379);
    $baseDb = isset($parts['path']) ? max(0, (int) ltrim((string) $parts['path'], '/')) : 0;
    $cacheDb = max(0, (int) (getenv('VAAK_REDIS_CACHE_DB') !== false ? getenv('VAAK_REDIS_CACHE_DB') : $baseDb));
    $queueDb = max(0, (int) (getenv('VAAK_REDIS_QUEUE_DB') !== false ? getenv('VAAK_REDIS_QUEUE_DB') : ($cacheDb + 1)));
    $db = $purpose === 'queue' ? $queueDb : $cacheDb;
    $password = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : null;
    $timeout = ap_redis_read_timeout();
    try {
        $redis = new Redis();
        // Connect timeout stays at the fast 80ms budget. The read timeout is
        // what a stalled reply waits on, and it is never 0.
        if (!@$redis->connect($host, $port, 0.08, null, 50, $timeout)) {
            error_log('[ap-redis] ' . $purpose . ' connection unavailable; falling back to authoritative DB/filesystem paths');
            return null;
        }
        if ($password !== null && $password !== '' && !@$redis->auth($password)) {
            error_log('[ap-redis] ' . $purpose . ' authentication failed; acceleration disabled for this request');
            try { $redis->close(); } catch (Throwable $ignored) {}
            return null;
        }
        if ($db > 0 && !@$redis->select($db)) {
            error_log('[ap-redis] ' . $purpose . ' database selection failed; acceleration disabled for this request');
            try { $redis->close(); } catch (Throwable $ignored) {}
            return null;
        }
        $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
        ap_redis_apply_read_timeout($redis, $timeout);
        $state['clients'][$purpose] = $redis;
    } catch (Throwable $e) {
        error_log('[ap-redis] connect failed: ' . $e->getMessage());
        $state['clients'][$purpose] = null;
    }
    $client = $state['clients'][$purpose] ?? null;
    return $client instanceof Redis ? $client : null;
}

/**
 * Read-only Redis health/ACL diagnostics for maintenance and startup checks.
 * Redis remains optional: this function never throws and never changes state.
 *
 * @return array<string,mixed>
 */
function ap_redis_health_check(string $purpose = 'cache'): array
{
    $purpose = $purpose === 'queue' ? 'queue' : 'cache';
    $started = microtime(true);
    $redis = ap_redis_client($purpose);
    $out = [
        'purpose' => $purpose,
        'available' => false,
        'latency_ms' => null,
        'db' => null,
        'acl' => ['ok' => false, 'user' => null, 'error' => null],
        'warnings' => [],
    ];
    if (!$redis) {
        $out['warnings'][] = 'Redis unavailable; authoritative database/filesystem paths remain active';
        return $out;
    }
    try {
        $pong = $redis->ping();
        $out['available'] = $pong !== false;
        $out['latency_ms'] = round((microtime(true) - $started) * 1000, 2);
        $out['db'] = method_exists($redis, 'getDbNum') ? $redis->getDbNum() : null;
        if (!$out['available']) {
            $out['warnings'][] = 'Redis PING failed';
        }
    } catch (Throwable $e) {
        ap_redis_fail($purpose, $e, 'ping');
        $out['warnings'][] = 'Redis PING failed: ' . substr($e->getMessage(), 0, 160);
        return $out;
    }
    try {
        $user = $redis->rawCommand('ACL', 'WHOAMI');
        if (is_string($user) && trim($user) !== '') {
            $out['acl'] = ['ok' => true, 'user' => $user, 'error' => null];
        } else {
            $out['acl']['error'] = 'ACL WHOAMI returned no username';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (stripos($message, 'read error') !== false || stripos($message, 'connection') !== false || stripos($message, 'timed out') !== false) {
            ap_redis_discard_client($purpose);
        }
        $out['acl']['error'] = substr($message, 0, 160);
        $out['warnings'][] = 'ACL WHOAMI unavailable; verify the Redis user permissions';
    }
    return $out;
}

function ap_redis_json_get(string $key): ?array
{
    $redis = ap_redis_client('cache');
    if (!$redis || $key === '') return null;
    try {
        $raw = $redis->get($key);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'get');
        return null;
    }
}

function ap_redis_json_set(string $key, array $value, int $ttlSeconds): bool
{
    $redis = ap_redis_client('cache');
    if (!$redis || $key === '' || $ttlSeconds < 1) return false;
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) return false;
    try {
        return (bool) $redis->set($key, $encoded, ['ex' => $ttlSeconds]);
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'set');
        return false;
    }
}

function ap_redis_delete(string ...$keys): void
{
    $redis = ap_redis_client('cache');
    $keys = array_values(array_filter($keys, static fn($key): bool => is_string($key) && $key !== ''));
    if (!$redis || $keys === []) return;
    try { $redis->del($keys); } catch (Throwable $e) { ap_redis_fail('cache', $e, 'delete'); }
}

/**
 * Per-viewer (or server-wide) generation for cached trending cards.
 * Moderation writes bump it so the next read cannot reuse the previous viewer's mix.
 * A missing Redis is a miss, not a shared cache.
 */
function ap_trends_policy_epoch(?int $ownerUserId = null): string
{
    $redis = ap_redis_client('cache');
    if (!$redis) return '0:0';
    $owner = $ownerUserId !== null && $ownerUserId > 0 ? $ownerUserId : 0;
    try {
        $server = (string) ($redis->get('vaak:trends:policy-epoch:v1:server') ?: '0');
        $user = $owner > 0 ? (string) ($redis->get('vaak:trends:policy-epoch:v1:' . $owner) ?: '0') : '0';
        if (!preg_match('/^\d+$/', $server)) $server = '0';
        if (!preg_match('/^\d+$/', $user)) $user = '0';
        return $server . ':' . $user;
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'trends epoch read');
        return '0:0';
    }
}

function ap_trends_policy_epoch_bump(?int $ownerUserId = null): void
{
    $redis = ap_redis_client('cache');
    if (!$redis) return;
    try {
        if ($ownerUserId === null || $ownerUserId < 1) {
            $redis->incr('vaak:trends:policy-epoch:v1:server');
            $redis->expire('vaak:trends:policy-epoch:v1:server', 86400 * 30);
            return;
        }
        $key = 'vaak:trends:policy-epoch:v1:' . $ownerUserId;
        $redis->incr($key);
        $redis->expire($key, 86400 * 30);
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'trends epoch bump');
    }
}

/**
 * Generation for one owner's notification list caches (projection rows and
 * Redis pages). Invalidation bumps it before deleting, so a hydrate that read
 * mentions/events before a new notification landed cannot write its older
 * page back afterwards. A missing Redis reads as '' and never blocks a write.
 */
function ap_notification_list_epoch(int $ownerUserId): string
{
    $redis = ap_redis_client('cache');
    if (!$redis || $ownerUserId < 1) return '';
    try {
        $server = (string) ($redis->get('vaak:notifications:epoch:v1:server') ?: '0');
        $user = (string) ($redis->get('vaak:notifications:epoch:v1:' . $ownerUserId) ?: '0');
        return $server . ':' . $user;
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'notification epoch read');
        return '';
    }
}

/**
 * One-page token for the Rust notification nudge stream (/vaak/notif-stream).
 * Empty unless VAAK_NOTIF_STREAM=1. Redis maps sha256(token) to the owner id
 * for 12 hours; the worker never needs a PHP secret.
 */
function ap_notif_stream_token(int $ownerUserId): string
{
    if ($ownerUserId < 1 || getenv('VAAK_NOTIF_STREAM') !== '1') return '';
    $redis = ap_redis_client('cache');
    if (!$redis) return '';
    try {
        $token = bin2hex(random_bytes(24));
        $redis->set('vaak:notif_stream:token:v1:' . hash('sha256', $token), (string) $ownerUserId, ['ex' => 43200]);
        return $token;
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'notif stream token');
        return '';
    }
}

function ap_notification_list_epoch_bump(?int $ownerUserId = null): void
{
    $redis = ap_redis_client('cache');
    if (!$redis) return;
    $key = $ownerUserId !== null && $ownerUserId > 0
        ? 'vaak:notifications:epoch:v1:' . $ownerUserId
        : 'vaak:notifications:epoch:v1:server';
    try {
        $redis->incr($key);
        $redis->expire($key, 86400 * 30);
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'notification epoch bump');
    }
}

/**
 * Decimal generation counter. '' means the read failed (do not alias generation
 * 0: the client was just discarded and the next command may reconnect).
 * '0' means the key is absent.
 */
function ap_redis_generation(string $key): string
{
    $redis = ap_redis_client('cache');
    if (!$redis || $key === '') {
        return '0';
    }
    try {
        $value = $redis->get($key);
        if ($value === false || $value === null) {
            return '0';
        }
        $value = (string) $value;
        return preg_match('/^\d+$/', $value) ? $value : '0';
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'generation read');
        return '';
    }
}

function ap_redis_generation_bump(string $key, string $op = 'generation bump'): void
{
    $redis = ap_redis_client('cache');
    if (!$redis || $key === '') {
        return;
    }
    try {
        $redis->incr($key);
        $redis->expire($key, 86400 * 30);
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, $op);
    }
}

function ap_redis_generation_token(string $raw): string
{
    return preg_match('/^\d+$/', $raw) ? $raw : 'x';
}

/**
 * True at most once per pattern per minute, and at most once globally per 5s.
 * A hot loop cannot put SCAN back on the CPU the way 2026-11-18 did.
 */
function ap_redis_pattern_scan_allowed(string $pattern, ?float $now = null): bool
{
    static $last = [];
    static $global = 0.0;
    if ($pattern === '') {
        return false;
    }
    $now = $now ?? microtime(true);
    if (($now - $global) < 5.0) {
        return false;
    }
    $prev = $last[$pattern] ?? 0.0;
    if (($now - $prev) < 60.0) {
        return false;
    }
    $last[$pattern] = $now;
    $global = $now;
    return true;
}

/**
 * SCAN fallback for a pattern that is not a per-user generation or an exact
 * key. Hot invalidation does not call this. It is rate-limited so a stray
 * caller cannot sweep the keyspace on every notification.
 */
function ap_redis_delete_pattern(string $pattern): void
{
    $redis = ap_redis_client('cache');
    if (!$redis || $pattern === '') {
        return;
    }
    if (!ap_redis_pattern_scan_allowed($pattern)) {
        error_log('[ap-redis] pattern delete skipped (rate limit): ' . $pattern);
        return;
    }
    try {
        $iterator = null;
        while (($keys = $redis->scan($iterator, $pattern, 100)) !== false) {
            if ($keys !== []) {
                $redis->del($keys);
            }
            if ($iterator === 0) {
                break;
            }
        }
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'pattern delete');
    }
}

function ap_redis_set_add(string $setKey, string $member, int $ttlSeconds): void
{
    $redis = ap_redis_client('cache');
    if (!$redis || $setKey === '' || $member === '') {
        return;
    }
    try {
        $redis->sAdd($setKey, $member);
        $redis->expire($setKey, max(1, $ttlSeconds));
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'set add');
    }
}

/** @return list<string> */
function ap_redis_set_members(string $setKey): array
{
    $redis = ap_redis_client('cache');
    if (!$redis || $setKey === '') {
        return [];
    }
    try {
        $members = $redis->sMembers($setKey);
        if (!is_array($members)) {
            return [];
        }
        $out = [];
        foreach ($members as $member) {
            if (is_string($member) && $member !== '') {
                $out[] = $member;
            }
        }
        return $out;
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'set members');
        return [];
    }
}

/** Increment a short-lived counter; null means Redis is unavailable. */
function ap_redis_rate_count(string $bucket, int $windowSeconds = 900): ?int
{
    $redis = ap_redis_client('cache');
    $bucket = trim($bucket);
    if (!$redis || $bucket === '') return null;
    $key = 'vaak:rate:' . hash('sha256', $bucket);
    try {
        $count = (int) $redis->incr($key);
        if ($count === 1) $redis->expire($key, max(1, $windowSeconds));
        return $count;
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'rate counter');
        return null;
    }
}

function ap_redis_rate_clear(string $bucket): void
{
    $redis = ap_redis_client('cache');
    $bucket = trim($bucket);
    if (!$redis || $bucket === '') return;
    try { $redis->del('vaak:rate:' . hash('sha256', $bucket)); } catch (Throwable $e) { ap_redis_fail('cache', $e, 'rate clear'); }
}

function ap_redis_rate_get(string $bucket): ?int
{
    $redis = ap_redis_client('cache');
    $bucket = trim($bucket);
    if (!$redis || $bucket === '') return null;
    try {
        $value = $redis->get('vaak:rate:' . hash('sha256', $bucket));
        return $value === false ? 0 : (int) $value;
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'rate get');
        return null;
    }
}

/**
 * Provider circuit state is best-effort and Redis-backed. A provider is
 * isolated after repeated transport/server failures, while a short probe
 * lock lets one worker test recovery without stampeding the upstream.
 */
function ap_provider_circuit_key(string $provider): string
{
    return 'vaak:circuit:v1:' . hash('sha256', strtolower(trim($provider)));
}

function ap_provider_circuit_allow(string $provider, int $probeTtl = 15): bool
{
    $provider = trim($provider);
    $redis = ap_redis_client('cache');
    if (!$redis || $provider === '') return true;
    try {
        $state = ap_redis_json_get(ap_provider_circuit_key($provider));
        if (!is_array($state)) return true;
        $openUntil = (int) ($state['open_until'] ?? 0);
        if ($openUntil > time()) return false;
        if ($openUntil > 0 && !ap_redis_lock('circuit-probe:' . $provider, $probeTtl)) {
            return false;
        }
        return true;
    } catch (Throwable $e) {
        return true;
    }
}

function ap_provider_circuit_success(string $provider): void
{
    $provider = trim($provider);
    if ($provider === '') return;
    ap_redis_delete(ap_provider_circuit_key($provider));
}

function ap_provider_circuit_failure(string $provider, int $cooldown = 60): void
{
    $provider = trim($provider);
    if ($provider === '') return;
    $redis = ap_redis_client('cache');
    if (!$redis) return;
    try {
        $key = ap_provider_circuit_key($provider);
        $state = ap_redis_json_get($key);
        $failures = is_array($state) ? (int) ($state['failures'] ?? 0) : 0;
        $failures++;
        $openUntil = 0;
        if ($failures >= 3) {
            $openUntil = time() + min(900, max(15, $cooldown) * (2 ** min(4, $failures - 3)));
        }
        ap_redis_json_set($key, [
            'failures' => $failures,
            'open_until' => $openUntil,
            'updated_at' => time(),
        ], 1800);
    } catch (Throwable $e) {
        ap_redis_discard_client('cache');
    }
}

/** Best-effort operational counters, kept separate from application data. */
function ap_redis_metric_inc(string $name, int $amount = 1): void
{
    $redis = ap_redis_client('cache');
    $name = preg_replace('/[^a-z0-9:_-]/i', '', trim($name)) ?: '';
    if (!$redis || $name === '' || $amount === 0) return;
    try {
        $key = 'vaak:metric:' . $name;
        $redis->incrBy($key, $amount);
        $redis->expire($key, 604800);
    } catch (Throwable $e) { ap_redis_discard_client('cache'); }
}

/**
 * Kill switch for Wafrn-style relationship-set acceleration (Phase 2+).
 * When disabled, callers must use the existing Postgres / full-list paths.
 * Broader Redis outage is still handled by ap_redis_client() returning null.
 */
function ap_redis_relsets_enabled(): bool
{
    $flag = getenv('VAAK_REDIS_RELSETS');
    if ($flag !== false && in_array(strtolower(trim((string) $flag)), ['0', 'false', 'off', 'no'], true)) {
        return false;
    }
    return ap_redis_client('cache') !== null;
}

/**
 * TTL for owner relationship/moderation sets after Phase 4.
 * Invalidation on follow/block/mute is proven; keep shorter than Wafrn's 600s
 * until hit rates are observed in production.
 */
function ap_redis_relset_ttl(): int
{
    $env = getenv('VAAK_REDIS_RELSET_TTL');
    if ($env !== false && ctype_digit(trim((string) $env))) {
        return max(60, min(900, (int) trim((string) $env)));
    }
    return 300;
}

/** TTL for full follow-graph row lists / counts (Following page, etc.). */
function ap_redis_follow_graph_ttl(): int
{
    return ap_redis_relset_ttl();
}

/**
 * Short TTL for rendered Library HTML fragments (Favourites/Bookmarks Bluesky ajax).
 * Keep short — cards include live fav/bookmark chrome; mutations invalidate.
 */
function ap_redis_fragment_ttl(): int
{
    $env = getenv('VAAK_REDIS_FRAGMENT_TTL');
    if ($env !== false && ctype_digit(trim((string) $env))) {
        return max(15, min(180, (int) trim((string) $env)));
    }
    return 45;
}

/** TTL for Mastodon/API search result packs (shared, non-personalized). */
function ap_redis_search_ttl(): int
{
    $env = getenv('VAAK_REDIS_SEARCH_TTL');
    if ($env !== false && ctype_digit(trim((string) $env))) {
        return max(15, min(120, (int) trim((string) $env)));
    }
    return 45;
}

/**
 * @param 'favourites_bsky'|'bookmarks_bsky'|'favourites_fedi' $kind
 */
function ap_redis_library_html_key(
    string $kind,
    int $ownerUserId,
    string $suffix,
    ?string $ownerEpoch = null,
    ?string $kindEpoch = null
): string {
    $kind = preg_replace('/[^a-z0-9_]/', '', strtolower($kind)) ?: 'frag';
    $ownerUserId = max(0, $ownerUserId);
    if ($ownerEpoch === null) {
        $ownerEpoch = ap_redis_generation('vaak:fragment:lib:epoch:v1:' . $ownerUserId);
    }
    if ($kindEpoch === null) {
        $kindEpoch = ap_redis_generation('vaak:fragment:lib:epoch:v1:' . $ownerUserId . ':' . $kind);
    }
    return 'vaak:fragment:lib:v1:' . $kind . ':' . $ownerUserId . ':'
        . ap_redis_generation_token($ownerEpoch) . ':'
        . ap_redis_generation_token($kindEpoch) . ':'
        . hash('sha256', $suffix);
}

/**
 * @return array{html:string,has_more?:bool}|null
 */
function ap_redis_library_html_get(string $kind, int $ownerUserId, string $suffix): ?array
{
    if ($ownerUserId < 1 || !function_exists('ap_redis_json_get')) {
        return null;
    }
    $cached = ap_redis_json_get(ap_redis_library_html_key($kind, $ownerUserId, $suffix));
    if (!is_array($cached) || !isset($cached['html']) || !is_string($cached['html']) || $cached['html'] === '') {
        ap_redis_metric_inc('fragment_miss');
        return null;
    }
    ap_redis_metric_inc('fragment_hit');
    return $cached;
}

function ap_redis_library_html_set(
    string $kind,
    int $ownerUserId,
    string $suffix,
    string $html,
    ?bool $hasMore = null
): void {
    if ($ownerUserId < 1 || $html === '' || !function_exists('ap_redis_json_set')) {
        return;
    }
    $payload = ['html' => $html, 'ts' => time()];
    if ($hasMore !== null) {
        $payload['has_more'] = $hasMore;
    }
    ap_redis_json_set(
        ap_redis_library_html_key($kind, $ownerUserId, $suffix),
        $payload,
        ap_redis_fragment_ttl()
    );
}

/** Drop Favourites/Bookmarks rendered HTML for an owner (all offsets/folders). */
function ap_redis_library_html_invalidate(int $ownerUserId, ?string $kind = null): void
{
    if ($ownerUserId < 1) {
        return;
    }
    if ($kind !== null && $kind !== '') {
        $kind = preg_replace('/[^a-z0-9_]/', '', strtolower($kind)) ?: '';
        if ($kind !== '') {
            // One kind: bump that kind's generation. Other library pages stay.
            ap_redis_generation_bump('vaak:fragment:lib:epoch:v1:' . $ownerUserId . ':' . $kind, 'library fragment invalidate');
            ap_redis_metric_inc('fragment_invalidate');
            return;
        }
    }
    ap_redis_generation_bump('vaak:fragment:lib:epoch:v1:' . $ownerUserId, 'library fragment invalidate');
    ap_redis_metric_inc('fragment_invalidate');
}

/**
 * Timeline HTML fragments (page body and soft-nav shell) share one per-owner
 * generation. Invalidation is a single INCR; readers bake it into the key.
 */
function ap_fragment_tl_invalidate(int $ownerUserId): void
{
    if ($ownerUserId < 1) {
        return;
    }
    ap_redis_generation_bump('vaak:fragment:tl:epoch:v1:' . $ownerUserId, 'timeline fragment invalidate');
}

function ap_fragment_tl_epoch_token(int $ownerUserId): string
{
    if ($ownerUserId < 1) {
        return '0';
    }
    return ap_redis_generation_token(ap_redis_generation('vaak:fragment:tl:epoch:v1:' . $ownerUserId));
}

function ap_fragment_tl_key(int $ownerUserId, string $suffix): string
{
    return 'vaak:fragment:tl:v1:' . max(0, $ownerUserId) . ':' . ap_fragment_tl_epoch_token($ownerUserId) . ':' . $suffix;
}

function ap_fragment_tl_shell_key(int $ownerUserId, string $suffix): string
{
    return 'vaak:fragment:tl-shell:v1:' . max(0, $ownerUserId) . ':' . ap_fragment_tl_epoch_token($ownerUserId) . ':' . $suffix;
}

/** Server generation for ranked timeline payloads. Global clear is one INCR. */
function ap_timeline_ranked_epoch_bump(): void
{
    ap_redis_generation_bump('vaak:timeline:ranked:epoch:v1', 'ranked epoch bump');
}

function ap_redis_ranked_key(string $logical, ?string $epoch = null): string
{
    if ($epoch === null) {
        $epoch = ap_redis_generation('vaak:timeline:ranked:epoch:v1');
    }
    return 'vaak:timeline:ranked:v2:' . ap_redis_generation_token($epoch) . ':' . hash('sha256', $logical);
}

/**
 * Badge caches. Separate from the list epoch so marking read does not rebuild
 * every Mentions page. Both the live key and the shadow key include it.
 */
function ap_notification_unread_epoch(int $ownerUserId): string
{
    if ($ownerUserId < 1) {
        return '';
    }
    return ap_redis_generation('vaak:notifications:unread-epoch:v1:' . $ownerUserId);
}

function ap_notification_unread_epoch_bump(int $ownerUserId): void
{
    if ($ownerUserId < 1) {
        return;
    }
    ap_redis_generation_bump('vaak:notifications:unread-epoch:v1:' . $ownerUserId, 'unread epoch bump');
}

function ap_notification_unread_redis_key(int $ownerUserId, int $scan, string $lastRead, ?string $epoch = null, bool $shadow = false): string
{
    if ($epoch === null) {
        $epoch = ap_notification_unread_epoch($ownerUserId);
    }
    $prefix = $shadow ? 'vaak:shadow:notifications:v1:unread:' : 'vaak:notifications:v1:unread:';
    return $prefix . $ownerUserId . ':' . ap_redis_generation_token($epoch) . ':' . $scan . ':' . hash('sha256', $lastRead);
}

/** @return array{0:string,1:string} server, user */
function ap_notification_list_epoch_parts(string $epoch): array
{
    if ($epoch === '') {
        return ['x', 'x'];
    }
    $bits = explode(':', $epoch, 2);
    $server = preg_match('/^\d+$/', $bits[0] ?? '') ? $bits[0] : '0';
    $user = preg_match('/^\d+$/', $bits[1] ?? '') ? $bits[1] : '0';
    return [$server, $user];
}

function ap_notification_page_index_key(int $ownerUserId): string
{
    return 'vaak:notifications:v4:index:' . max(0, $ownerUserId);
}

function ap_notification_page_index_add(string $key, int $ttlSeconds = 600): void
{
    if (!str_starts_with($key, 'vaak:notifications:v4:')) {
        return;
    }
    $rest = substr($key, strlen('vaak:notifications:v4:'));
    $owner = strstr($rest, ':', true);
    if ($owner === false || !ctype_digit($owner)) {
        return;
    }
    ap_redis_set_add(ap_notification_page_index_key((int) $owner), $key, $ttlSeconds);
}

/** Record a relationship-cache hit/miss/invalidate for ops dashboards. */
function ap_redis_relset_metric(string $kind): void
{
    $kind = strtolower(trim($kind));
    if (!in_array($kind, ['hit', 'miss', 'invalidate', 'bypass'], true)) {
        return;
    }
    ap_redis_metric_inc('relset_' . $kind);
}

/** @return array<string,int> */
function ap_redis_metric_snapshot(): array
{
    $redis = ap_redis_client('cache');
    if (!$redis) return [];
    $out = [];
    try {
        $it = null;
        while (($keys = $redis->scan($it, 'vaak:metric:*', 100)) !== false) {
            foreach ($keys as $key) {
                $name = substr((string) $key, strlen('vaak:metric:'));
                $out[$name] = (int) $redis->get($key);
            }
            if ($it === 0) break;
        }
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'metric snapshot');
        return [];
    }
    ksort($out);
    return $out;
}

/** Record bounded aggregate latency for an operational path (no payload data). */
function ap_timing_record(string $name, float $milliseconds): void
{
    $redis = ap_redis_client('cache');
    $name = preg_replace('/[^a-z0-9._:-]/i', '', trim($name)) ?: '';
    if (!$redis || $name === '' || $milliseconds < 0) return;
    try {
        $key = 'vaak:timing:v1:' . $name;
        $redis->hIncrBy($key, 'count', 1);
        $redis->hIncrByFloat($key, 'total_ms', $milliseconds);
        $currentMax = (float) ($redis->hGet($key, 'max_ms') ?: 0);
        if ($milliseconds > $currentMax) {
            $redis->hSet($key, 'max_ms', sprintf('%.3f', $milliseconds));
        }
        $redis->expire($key, 86400);
        // Keep a small rolling sample so operators can see tail latency.
        // This is bounded and contains timing only—never request data.
        $sampleKey = $key . ':samples';
        $redis->lPush($sampleKey, sprintf('%.3f', $milliseconds));
        $redis->lTrim($sampleKey, 0, 255);
        $redis->expire($sampleKey, 86400);
    } catch (Throwable $e) {
        ap_redis_discard_client('cache');
    }
}

/** @return array<string,array{count:int,total_ms:float,max_ms:float,p50_ms?:float,p95_ms?:float,p99_ms?:float}> */
function ap_redis_timing_snapshot(): array
{
    $redis = ap_redis_client('cache');
    if (!$redis) return [];
    $out = [];
    try {
        $it = null;
        while (($keys = $redis->scan($it, 'vaak:timing:v1:*', 100)) !== false) {
            foreach ($keys as $key) {
                $name = substr((string) $key, strlen('vaak:timing:v1:'));
                if ($name === '' || str_ends_with($name, ':samples')) {
                    continue;
                }
                $row = $redis->hGetAll($key);
                if ($name !== '' && is_array($row)) {
                    $metric = [
                        'count' => (int) ($row['count'] ?? 0),
                        'total_ms' => (float) ($row['total_ms'] ?? 0),
                        'max_ms' => (float) ($row['max_ms'] ?? 0),
                    ];
                    $samples = array_map('floatval', $redis->lRange((string) $key . ':samples', 0, 255) ?: []);
                    if ($samples !== []) {
                        sort($samples, SORT_NUMERIC);
                        $quantile = static function (float $q) use ($samples): float {
                            $index = (int) ceil($q * count($samples)) - 1;
                            return (float) $samples[max(0, min(count($samples) - 1, $index))];
                        };
                        $metric['p50_ms'] = round($quantile(0.50), 3);
                        $metric['p95_ms'] = round($quantile(0.95), 3);
                        $metric['p99_ms'] = round($quantile(0.99), 3);
                    }
                    $out[$name] = $metric;
                }
            }
            if ($it === 0) break;
        }
    } catch (Throwable $e) {
        ap_redis_discard_client('cache');
        return [];
    }
    ksort($out);
    return $out;
}

/** Record slow route metadata without storing query strings or request data. */
function ap_slow_route_record(string $route, float $milliseconds, float $thresholdMs = 1000.0): void
{
    $redis = ap_redis_client('cache');
    $route = parse_url($route, PHP_URL_PATH) ?: $route;
    $route = preg_replace('/[^a-z0-9_\/?.:-]/i', '', trim((string) $route)) ?: '';
    if (!$redis || $route === '' || $milliseconds < $thresholdMs) return;
    try {
        $key = 'vaak:slow-route:v1:' . hash('sha256', $route);
        $redis->hMSet($key, [
            'route' => substr($route, 0, 180),
            'count' => (int) ($redis->hGet($key, 'count') ?: 0) + 1,
            'last_ms' => sprintf('%.1f', $milliseconds),
            'max_ms' => sprintf('%.1f', max($milliseconds, (float) ($redis->hGet($key, 'max_ms') ?: 0))),
            'last_at' => gmdate('c'),
        ]);
        $redis->expire($key, 86400);
    } catch (Throwable $e) {
        ap_redis_discard_client('cache');
    }
}

/** @return list<array{route:string,count:int,last_ms:float,max_ms:float,last_at:string}> */
function ap_slow_route_snapshot(int $limit = 20): array
{
    $redis = ap_redis_client('cache');
    if (!$redis) return [];
    $out = [];
    try {
        $it = null;
        while (($keys = $redis->scan($it, 'vaak:slow-route:v1:*', 100)) !== false) {
            foreach ($keys as $key) {
                $row = $redis->hGetAll($key);
                if (!is_array($row) || (string) ($row['route'] ?? '') === '') continue;
                $out[] = [
                    'route' => (string) $row['route'],
                    'count' => (int) ($row['count'] ?? 0),
                    'last_ms' => (float) ($row['last_ms'] ?? 0),
                    'max_ms' => (float) ($row['max_ms'] ?? 0),
                    'last_at' => (string) ($row['last_at'] ?? ''),
                ];
            }
            if ($it === 0) break;
        }
        usort($out, static fn(array $a, array $b): int => $b['max_ms'] <=> $a['max_ms']);
        return array_slice($out, 0, max(1, min(100, $limit)));
    } catch (Throwable $e) {
        ap_redis_discard_client('cache');
        return [];
    }
}

/** Lower a worker batch when backlog indicates upstream/database pressure. */
function ap_worker_backpressure_limit(string $queue, int $requested, int $pending): int
{
    $requested = max(1, $requested);
    $pending = max(0, $pending);
    $factor = $pending >= 5000 ? 0.25 : ($pending >= 1000 ? 0.5 : ($pending >= 250 ? 0.75 : 1.0));
    $limit = max(1, (int) floor($requested * $factor));
    if ($factor < 1.0) ap_redis_metric_inc('worker_backpressure');
    return $limit;
}

/** Best-effort short lock used to coalesce refresh work. */
function ap_redis_lock(string $key, int $ttlSeconds = 30): bool
{
    $redis = ap_redis_client('queue');
    if (!$redis || $key === '') return false;
    try {
        $ok = (bool) $redis->set('vaak:lock:' . $key, (string) getmypid(), ['nx', 'ex' => max(1, $ttlSeconds)]);
        ap_redis_metric_inc($ok ? 'lock_acquired' : 'lock_contended');
        return $ok;
    } catch (Throwable $e) {
        ap_redis_fail('queue', $e, 'lock');
        return false;
    }
}

/** Release a lock we hold (best-effort; TTL remains the safety net). */
function ap_redis_unlock(string $key): void
{
    $redis = ap_redis_client('queue');
    if (!$redis || $key === '') {
        return;
    }
    try {
        $full = 'vaak:lock:' . $key;
        $holder = $redis->get($full);
        if (is_string($holder) && $holder === (string) getmypid()) {
            $redis->del($full);
        }
    } catch (Throwable $e) {
        ap_redis_discard_client('queue');
    }
}

/**
 * After losing a stampede lock, poll $fetch until it returns a non-null value
 * or $maxWaitMs elapses. Prefer slow-correct rebuild over hanging the request.
 *
 * @param callable():mixed $fetch
 * @return mixed|null
 */
function ap_redis_stampede_wait(callable $fetch, int $maxWaitMs = 200)
{
    // Allow multi-second waits for full-page TL peers (soft-nav uses ~200ms).
    $maxWaitMs = max(0, min(8000, $maxWaitMs));
    if ($maxWaitMs < 1) {
        return null;
    }
    $deadline = microtime(true) + ($maxWaitMs / 1000.0);
    $sliceUs = 40000; // 40ms
    while (microtime(true) < $deadline) {
        try {
            $value = $fetch();
        } catch (Throwable $e) {
            $value = null;
        }
        if ($value !== null) {
            ap_redis_metric_inc('stampede_wait_hit');
            return $value;
        }
        usleep($sliceUs);
    }
    ap_redis_metric_inc('stampede_wait_timeout');
    return null;
}

/**
 * True while the Rust fan-out delivery worker (rust/vaak-worker/src/
 * fanout_delivery.rs, VAAK_FANOUT_DELIVERY_RUST=1) keeps its heartbeat fresh.
 * PHP then does not start ap-fanout-delivery-worker.php. A missing or stale
 * heartbeat (worker off, crashed, Redis down) brings the PHP worker back.
 */
function ap_fanout_rust_worker_active(): bool
{
    static $checked = 0;
    static $active = false;
    $now = time();
    if ($checked > 0 && ($now - $checked) < 5) {
        return $active;
    }
    $checked = $now;
    $active = false;
    $redis = ap_redis_client();
    if (!$redis) {
        return false;
    }
    try {
        $beat = $redis->get('vaak:fanout_delivery:rust:v1');
        $active = is_string($beat) && ctype_digit($beat) && ($now - (int) $beat) <= 30;
    } catch (Throwable $e) {
        ap_redis_discard_client('cache');
        $active = false;
    }
    return $active;
}

/**
 * Cutover D3: true while the Rust fan-out worker runs with
 * VAAK_FANOUT_SYNC_RUST=1 and keeps vaak:fanout_delivery:rust_sync:v1 fresh.
 * Fan-out PHP used to send inline (Bridgy, the first followers, the first 50
 * of a profile Update or Move) then goes to ap_fanout_delivery_queue instead.
 * A missing or stale key sends inline again.
 */
function ap_fanout_rust_sync_active(): bool
{
    static $checked = 0;
    static $active = false;
    $now = time();
    if ($checked > 0 && ($now - $checked) < 5) {
        return $active;
    }
    $checked = $now;
    $active = false;
    if (!ap_fanout_rust_worker_active()) {
        return false;
    }
    $redis = ap_redis_client();
    if (!$redis) {
        return false;
    }
    try {
        $beat = $redis->get('vaak:fanout_delivery:rust_sync:v1');
        $active = is_string($beat) && ctype_digit($beat) && ($now - (int) $beat) <= 30;
    } catch (Throwable $e) {
        ap_redis_discard_client('cache');
        $active = false;
    }
    return $active;
}

/**
 * Cutover A1: true while the Rust fan-out worker runs with
 * VAAK_FANOUT_ACTIONS_RUST=1 and keeps vaak:fanout_delivery:rust_actions:v1
 * fresh. Likes, unlikes and unboosts from the action queue then go to
 * ap_fanout_delivery_queue instead of being sent from PHP. A missing or
 * stale key sends them from PHP again.
 */
function ap_fanout_rust_actions_active(): bool
{
    static $checked = 0;
    static $active = false;
    $now = time();
    if ($checked > 0 && ($now - $checked) < 5) {
        return $active;
    }
    $checked = $now;
    $active = false;
    if (!ap_fanout_rust_worker_active()) {
        return false;
    }
    $redis = ap_redis_client();
    if (!$redis) {
        return false;
    }
    try {
        $beat = $redis->get('vaak:fanout_delivery:rust_actions:v1');
        $active = is_string($beat) && ctype_digit($beat) && ($now - (int) $beat) <= 30;
    } catch (Throwable $e) {
        ap_redis_discard_client('cache');
        $active = false;
    }
    return $active;
}

/** Publish a durable database queue ID as a fast worker wake-up signal. */
function ap_redis_queue_push(string $queue, string|int $item): bool
{
    $redis = ap_redis_client('queue');
    if (!$redis || $queue === '' || (is_int($item) ? $item < 1 : trim($item) === '')) return false;
    try {
        $key = 'vaak:queue:' . preg_replace('/[^a-z0-9:_-]/i', '', $queue);
        $redis->lPush($key, (string) $item);
        $redis->expire($key, 86400);
        ap_redis_metric_inc('queue_push');
        return true;
    } catch (Throwable $e) {
        ap_redis_fail('queue', $e, 'queue push');
        return false;
    }
}

/** Wait briefly for the next wake-up signal from one of the durable queues. */
function ap_redis_queue_pop_any(array $queues, int $timeoutSeconds = 5): ?array
{
    $redis = ap_redis_client();
    $keys = [];
    foreach ($queues as $queue) {
        $clean = preg_replace('/[^a-z0-9:_-]/i', '', (string) $queue);
        if ($clean !== '') $keys[] = 'vaak:queue:' . $clean;
    }
    if (!$redis || $keys === []) return null;
    $blockFor = max(1, min(30, $timeoutSeconds));
    try {
        // BRPOP is a bounded wait, not an infinite poll. The read timeout has
        // to cover that wait or phpredis aborts a live block. Restore the
        // purpose default afterwards. A failure discards the socket: putting
        // the short timeout back on a connection that just read-errored is
        // how the 2026-11-18 workers stuck in poll().
        ap_redis_apply_read_timeout($redis, (float) ($blockFor + 1));
        $row = $redis->brPop($keys, $blockFor);
        ap_redis_apply_read_timeout($redis, ap_redis_read_timeout());
        if (!is_array($row) || count($row) < 2) return null;
        ap_redis_metric_inc('queue_pop');
        return ['queue' => substr((string) $row[0], strlen('vaak:queue:')), 'item' => (string) $row[1]];
    } catch (Throwable $e) {
        ap_redis_fail('cache', $e, 'queue pop');
        return null;
    }
}
