<?php
declare(strict_types=1);

/**
 * Optional Redis acceleration/coordination layer.
 *
 * Redis is never authoritative for VAAK data. Every helper is best-effort and
 * returns a neutral result when the extension, configuration, or service is
 * unavailable so callers can fall back to the database/filesystem paths.
 */
function ap_redis_client(string $purpose = 'cache'): ?Redis
{
    static $clients = [];
    static $attempted = [];
    $purpose = $purpose === 'queue' ? 'queue' : 'cache';
    if (($attempted[$purpose] ?? false) === true) {
        return $clients[$purpose] instanceof Redis ? $clients[$purpose] : null;
    }
    $attempted[$purpose] = true;
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
    try {
        $redis = new Redis();
        if (!@$redis->connect($host, $port, 0.08, null, 50, 0.08)) {
            return null;
        }
        if ($password !== null && $password !== '' && !@$redis->auth($password)) {
            return null;
        }
        if ($db > 0 && !@$redis->select($db)) {
            return null;
        }
        $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
        $clients[$purpose] = $redis;
    } catch (Throwable $e) {
        error_log('[ap-redis] connect failed: ' . $e->getMessage());
        $clients[$purpose] = null;
    }
    return $clients[$purpose] instanceof Redis ? $clients[$purpose] : null;
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
        error_log('[ap-redis] get failed: ' . $e->getMessage());
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
        error_log('[ap-redis] set failed: ' . $e->getMessage());
        return false;
    }
}

function ap_redis_delete(string ...$keys): void
{
    $redis = ap_redis_client('cache');
    $keys = array_values(array_filter($keys, static fn($key): bool => is_string($key) && $key !== ''));
    if (!$redis || $keys === []) return;
    try { $redis->del($keys); } catch (Throwable $e) { error_log('[ap-redis] delete failed: ' . $e->getMessage()); }
}

function ap_redis_delete_pattern(string $pattern): void
{
    $redis = ap_redis_client('cache');
    if (!$redis || $pattern === '') return;
    try {
        $iterator = null;
        while (($keys = $redis->scan($iterator, $pattern, 100)) !== false) {
            if ($keys !== []) $redis->del($keys);
            if ($iterator === 0) break;
        }
    } catch (Throwable $e) {
        error_log('[ap-redis] pattern delete failed: ' . $e->getMessage());
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
        error_log('[ap-redis] rate counter failed: ' . $e->getMessage());
        return null;
    }
}

function ap_redis_rate_clear(string $bucket): void
{
    $redis = ap_redis_client('cache');
    $bucket = trim($bucket);
    if (!$redis || $bucket === '') return;
    try { $redis->del('vaak:rate:' . hash('sha256', $bucket)); } catch (Throwable $e) { /* best effort */ }
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
        // Circuit state must never turn an upstream failure into an app failure.
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
    } catch (Throwable $e) { /* metrics must never affect requests */ }
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
    } catch (Throwable $e) { return []; }
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
    } catch (Throwable $e) {
        // Telemetry must never affect request execution.
    }
}

/** @return array<string,array{count:int,total_ms:float,max_ms:float}> */
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
                $row = $redis->hGetAll($key);
                if ($name !== '' && is_array($row)) {
                    $out[$name] = [
                        'count' => (int) ($row['count'] ?? 0),
                        'total_ms' => (float) ($row['total_ms'] ?? 0),
                        'max_ms' => (float) ($row['max_ms'] ?? 0),
                    ];
                }
            }
            if ($it === 0) break;
        }
    } catch (Throwable $e) {
        return [];
    }
    ksort($out);
    return $out;
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
        error_log('[ap-redis] lock failed: ' . $e->getMessage());
        return false;
    }
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
        error_log('[ap-redis] queue push failed: ' . $e->getMessage());
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
    try {
        // The client uses a short timeout for request-time cache reads; a
        // blocking queue wait needs a matching read timeout instead.
        $redis->setOption(Redis::OPT_READ_TIMEOUT, max(1, $timeoutSeconds + 1));
        $row = $redis->brPop($keys, max(1, min(30, $timeoutSeconds)));
        $redis->setOption(Redis::OPT_READ_TIMEOUT, 0.08);
        if (!is_array($row) || count($row) < 2) return null;
        ap_redis_metric_inc('queue_pop');
        return ['queue' => substr((string) $row[0], strlen('vaak:queue:')), 'item' => (string) $row[1]];
    } catch (Throwable $e) {
        try { $redis->setOption(Redis::OPT_READ_TIMEOUT, 0.08); } catch (Throwable $ignored) {}
        error_log('[ap-redis] queue pop failed: ' . $e->getMessage());
        return null;
    }
}
