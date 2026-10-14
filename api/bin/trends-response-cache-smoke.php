<?php
/** Trending response cache keys, TTLs, and reuse headers. SQLite plus an in-memory Redis stand-in. */
declare(strict_types=1);

require __DIR__ . '/migration-reference-functions.php';
if (!extension_loaded('mbstring')) {
    throw new RuntimeException('Actual policy tests require mbstring.');
}
if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('pdo_sqlite is required.');
}

final class TrendsMemoryRedis
{
    /** @var array<string,string> */
    public array $data = [];

    public function get(string $key): string|false
    {
        return $this->data[$key] ?? false;
    }

    /** @param mixed $options */
    public function set(string $key, string $value, mixed $options = null): bool
    {
        $this->data[$key] = $value;
        return true;
    }

    public function incr(string $key): int
    {
        $next = (int) ($this->data[$key] ?? 0) + 1;
        $this->data[$key] = (string) $next;
        return $next;
    }

    public function expire(string $key, int $ttl): bool
    {
        return $ttl > 0;
    }
}

$GLOBALS['trends_redis'] = new TrendsMemoryRedis();
function ap_redis_client(string $purpose = 'cache'): ?TrendsMemoryRedis
{
    return $GLOBALS['trends_redis'];
}

$db = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$GLOBALS['trends_db'] = $db;
function ap_db(): PDO
{
    return $GLOBALS['trends_db'];
}

$entities = dirname(__DIR__) . '/ap-masto-entities.php';
$entitySource = (string) file_get_contents($entities);
if (!preg_match('/const AP_TRENDS_RESPONSE_TTL = (\d+);/', $entitySource, $ttlMatch)) {
    throw new RuntimeException('Missing AP_TRENDS_RESPONSE_TTL');
}
eval('const AP_TRENDS_RESPONSE_TTL = ' . $ttlMatch[1] . ';');

reference_load(dirname(__DIR__) . '/ap-redis.php', [
    'ap_redis_json_get',
    'ap_redis_json_set',
    'ap_trends_policy_epoch',
    'ap_trends_policy_epoch_bump',
]);
reference_load($entities, [
    'ap_trends_privacy_stamp',
    'ap_trends_painted_owner',
    'ap_trends_painted_key',
    'ap_trends_painted_read',
    'ap_trends_painted_remember',
    'ap_trends_http_key',
    'ap_trends_http_get',
    'ap_trends_http_put',
    'ap_trends_reuse_header_lines',
    'ap_trends_not_modified',
]);

$checks = 0;
function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
}

check(AP_TRENDS_RESPONSE_TTL === 600, 'trending responses stay reusable for 10 minutes');
check(ap_trends_painted_owner('statuses', 9) === 0, 'status candidates stay on the shared list');
check(ap_trends_painted_owner('tags', 9) === 9, 'tag scores stay on the viewer list');
check(ap_trends_privacy_stamp(0) === 'guest', 'anonymous trends use the guest stamp');
check(ap_trends_policy_epoch(9) === '0:0', 'a fresh redis starts at epoch zero');

$db->exec('CREATE TABLE ap_home_suppression (owner_user_id INTEGER, actor_id TEXT, suppressed_until TEXT)');
$db->exec('CREATE TABLE ap_users (id INTEGER, actor_key TEXT)');
$db->exec('CREATE TABLE actor_profile (actor_key TEXT, downranking_enabled INTEGER)');
$db->exec("INSERT INTO ap_users (id, actor_key) VALUES (9, 'alice')");
$db->exec("INSERT INTO actor_profile (actor_key, downranking_enabled) VALUES ('alice', 1)");
$before = ap_trends_privacy_stamp(9);
check(is_string($before) && $before !== 'guest', 'a signed-in stamp is private to that account');
$db->exec("INSERT INTO ap_home_suppression (owner_user_id, actor_id, suppressed_until) VALUES (9, 'https://hidden.example/users/bob', '2999-01-01T00:00:00Z')");
$after = ap_trends_privacy_stamp(9);
check(is_string($after) && $after !== $before, 'home downranking changes the response stamp');
check(ap_trends_http_key('sidebar', 9, 'html-tags') !== ap_trends_http_key('sidebar', 4, 'html-tags'), 'two accounts do not share a response body');

$stored = ap_trends_painted_remember('tags', 9, ['items' => [['name' => 'kept']], 'age' => 3, 'stale' => false]);
check($stored['items'][0]['name'] === 'kept', 'painted remember returns the items just stored');
$hit = ap_trends_painted_read('tags', 9, 1);
check(is_array($hit) && ($hit['items'][0]['name'] ?? '') === 'kept', 'painted read returns this viewer cache');
check(ap_trends_painted_read('tags', 4, 1) === null, 'another viewer does not receive those tag scores');
$tagKey = ap_trends_painted_key('tags', 9);
$statusKey = ap_trends_painted_key('statuses', 9);
check(str_contains($tagKey, ':tags:9:'), 'tag cache key names the viewer');
check(str_contains($statusKey, ':statuses:0:'), 'status cache key is the shared list');
check(!str_starts_with($tagKey, 'vaak:fragment:trends:'), 'painted keys are not HTML fragments');

ap_trends_policy_epoch_bump(9);
check(ap_trends_painted_key('tags', 9) !== $tagKey, 'a personal block invalidates that viewer tag cache');
check(ap_trends_painted_key('statuses', 9) === $statusKey, 'a personal block does not rebuild the shared status list');
check(ap_trends_painted_read('tags', 9, 1) === null, 'the old tag list is not served after the block');
$serverStatus = ap_trends_painted_key('statuses', 9);
ap_trends_policy_epoch_bump(null);
check(ap_trends_painted_key('statuses', 9) !== $serverStatus, 'a server-wide moderation change invalidates shared statuses');

$saved = ap_trends_http_put('tags', 0, 'json-10', '[{"name":"public"}]', 4, false);
check($saved['etag'] !== '' && str_starts_with($saved['etag'], '"'), 'a response gets a quoted ETag');
$public = ap_trends_http_get('tags', 0, 'json-10');
check(is_array($public) && $public['body'] === '[{"name":"public"}]', 'anonymous JSON is cached');
check(ap_trends_http_get('tags', 9, 'json-10') === null, 'a signed-in read does not reuse the anonymous body');
$lines = ap_trends_reuse_header_lines($saved['etag'], true);
check($lines[0] === 'Cache-Control: public, max-age=600', 'anonymous trend API can be reused by the browser and Caddy');
check(ap_trends_reuse_header_lines($saved['etag'], false)[0] === 'Cache-Control: private, max-age=600', 'signed-in trend responses stay private');
check($lines[2] === 'Vary: Authorization', 'shared caches must vary on the bearer token');
$_SERVER['HTTP_IF_NONE_MATCH'] = 'W/' . $saved['etag'];
check(ap_trends_not_modified($saved['etag']), 'a weak If-None-Match matches');
$_SERVER['HTTP_IF_NONE_MATCH'] = '*';
check(ap_trends_not_modified($saved['etag']), 'If-None-Match * matches');
$_SERVER['HTTP_IF_NONE_MATCH'] = '"other"';
check(!ap_trends_not_modified($saved['etag']), 'a different ETag is a miss');

$GLOBALS['vaak_trends_bypass_paint'] = true;
check(ap_trends_http_get('tags', 0, 'json-10') === null, 'fresh=1 skips the response cache');
unset($GLOBALS['vaak_trends_bypass_paint']);
check(is_array(ap_trends_http_get('tags', 0, 'json-10')), 'a later normal read uses the cache again');

$broken = new PDO('sqlite::memory:');
$GLOBALS['trends_db'] = $broken;
check(ap_trends_privacy_stamp(9) === null, 'an unreadable privacy stamp does not invent a cache key');
check(ap_trends_http_key('sidebar', 9, 'html-tags') === null, 'a failed stamp is not cached');

echo "trends-response-cache-smoke: {$GLOBALS['checks']} checks ok\n";
