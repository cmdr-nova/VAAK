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

/** Best-effort short lock used to coalesce refresh work. */
function ap_redis_lock(string $key, int $ttlSeconds = 30): bool
{
    $redis = ap_redis_client('queue');
    if (!$redis || $key === '') return false;
    try {
        return (bool) $redis->set('vaak:lock:' . $key, (string) getmypid(), ['nx', 'ex' => max(1, $ttlSeconds)]);
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
        return ['queue' => substr((string) $row[0], strlen('vaak:queue:')), 'item' => (string) $row[1]];
    } catch (Throwable $e) {
        try { $redis->setOption(Redis::OPT_READ_TIMEOUT, 0.08); } catch (Throwable $ignored) {}
        error_log('[ap-redis] queue pop failed: ' . $e->getMessage());
        return null;
    }
}
