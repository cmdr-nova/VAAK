<?php
/**
 * Follow acceptance state, delivery-log redaction, and silent-host fallback
 * decisions. No network and no database connection of its own: callers pass
 * a PDO when a row changes. The scheduled fetch lives in ap-follow-fallback.php.
 */
declare(strict_types=1);

function ap_follow_state_normalize(string $state): string
{
    $state = strtolower(trim($state));
    return in_array($state, ['pending', 'accepted', 'rejected', 'unknown'], true) ? $state : 'unknown';
}

/** send → pending, accept → accepted, reject → rejected. */
function ap_follow_state_apply(string $current, string $event): string
{
    $event = strtolower(trim($event));
    if ($event === 'send') {
        return 'pending';
    }
    if ($event === 'accept') {
        return 'accepted';
    }
    if ($event === 'reject') {
        return 'rejected';
    }
    return ap_follow_state_normalize($current);
}

function ap_follow_state_id(mixed $value): string
{
    if (is_string($value)) {
        $value = trim($value);
        return str_starts_with($value, 'https://') ? rtrim($value, '/') : '';
    }
    if (is_array($value) && array_key_exists('id', $value)) {
        return ap_follow_state_id($value['id']);
    }
    return '';
}

function ap_follow_actor_is_local(string $actorId, string $localActor): bool
{
    $actorId = rtrim(trim($actorId), '/');
    $localActor = rtrim(trim($localActor), '/');
    if ($actorId === '' || $localActor === '' || !str_starts_with($actorId, 'https://')) {
        return false;
    }
    if ($actorId === $localActor) {
        return true;
    }
    $localHost = strtolower((string) (parse_url($localActor, PHP_URL_HOST) ?: ''));
    $actorHost = strtolower((string) (parse_url($actorId, PHP_URL_HOST) ?: ''));
    $path = (string) (parse_url($actorId, PHP_URL_PATH) ?: '');
    return $localHost !== ''
        && $actorHost === $localHost
        && preg_match('#^/users/[A-Za-z0-9_]+$#', $path) === 1;
}

/**
 * Classify an incoming Accept/Reject of a Follow.
 *
 * @param array<string,mixed> $activity
 * @return array{kind:string,state:string,remote_actor:string,local_actor:string,follow_id:string,target_actor:string}|null
 */
function ap_follow_response_classify(array $activity, string $localActor): ?array
{
    $type = is_string($activity['type'] ?? null) ? strtolower(trim($activity['type'])) : '';
    if ($type !== 'accept' && $type !== 'reject') {
        return null;
    }
    $state = $type === 'accept' ? 'accepted' : 'rejected';
    $activityActor = ap_follow_state_id($activity['actor'] ?? null);
    $object = $activity['object'] ?? null;
    if (is_array($object) && strcasecmp((string) ($object['type'] ?? ''), 'Follow') === 0) {
        $innerActor = ap_follow_state_id($object['actor'] ?? null);
        $innerObject = ap_follow_state_id($object['object'] ?? null);
        $followId = ap_follow_state_id($object['id'] ?? null);
        if ($innerActor !== '' && ap_follow_actor_is_local($innerActor, $localActor)) {
            return [
                'kind' => 'outbound',
                'state' => $state,
                'remote_actor' => $activityActor,
                'local_actor' => $innerActor,
                'follow_id' => $followId,
                'target_actor' => $innerObject,
            ];
        }
        if ($innerObject !== '' && ap_follow_actor_is_local($innerObject, $localActor)) {
            return [
                'kind' => 'ack',
                'state' => $state,
                'remote_actor' => $innerActor,
                'local_actor' => $innerObject,
                'follow_id' => $followId,
                'target_actor' => $innerObject,
            ];
        }
        return null;
    }
    if (is_string($object) && str_starts_with(trim($object), 'https://')) {
        return [
            'kind' => 'by_id',
            'state' => $state,
            'remote_actor' => $activityActor,
            'local_actor' => '',
            'follow_id' => rtrim(trim($object), '/'),
            'target_actor' => '',
        ];
    }
    return null;
}

function ap_follow_response_actor_matches(string $activityActor, string $followedActor): bool
{
    $activityActor = rtrim(trim($activityActor), '/');
    $followedActor = rtrim(trim($followedActor), '/');
    if ($activityActor === '' || $followedActor === '' || !str_starts_with($activityActor, 'https://')) {
        return false;
    }
    if ($activityActor === $followedActor) {
        return true;
    }
    $left = strtolower((string) (parse_url($activityActor, PHP_URL_HOST) ?: ''));
    $right = strtolower((string) (parse_url($followedActor, PHP_URL_HOST) ?: ''));
    return $left !== '' && $left === $right;
}

/**
 * Record Accept/Reject of a follow we sent. Returns the inbox action name, or
 * null when the activity is not one of ours.
 *
 * @param array<string,mixed> $activity
 */
function ap_follow_response_apply(array $activity, string $localActor): ?string
{
    $class = ap_follow_response_classify($activity, $localActor);
    if ($class === null) {
        return null;
    }
    $rejected = $class['state'] === 'rejected';
    if ($class['kind'] === 'ack') {
        return $rejected ? null : 'follow_accept_ack';
    }
    $next = $rejected ? 'rejected' : 'accepted';
    $followId = $class['follow_id'] !== '' ? $class['follow_id'] : null;
    if ($class['kind'] === 'outbound') {
        $remote = $class['target_actor'];
        $owner = $class['local_actor'];
        $signer = ap_follow_state_id($activity['actor'] ?? null);
        if ($remote === '' || $owner === '' || !ap_follow_response_actor_matches($signer, $remote)) {
            return null;
        }
        if (function_exists('ap_following_upsert')) {
            ap_following_upsert($remote, $owner, $next, $followId);
        }
        return $rejected ? 'follow_rejected' : 'follow_accepted';
    }
    if ($class['kind'] === 'by_id' && function_exists('ap_db') && function_exists('ap_following_row_by_activity')) {
        try {
            $row = ap_following_row_by_activity(ap_db(), $class['follow_id']);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }
        $followed = rtrim((string) ($row['actor_id'] ?? ''), '/');
        $owner = rtrim((string) ($row['owner_actor_id'] ?? ''), '/');
        if ($followed === '' || $owner === '' || !ap_follow_response_actor_matches($class['remote_actor'], $followed)) {
            return null;
        }
        if (function_exists('ap_following_upsert')) {
            ap_following_upsert($followed, $owner, $next, $followId);
        }
        return $rejected ? 'follow_rejected' : 'follow_accepted';
    }
    return null;
}

function ap_delivery_is_follow_event(string $type): bool
{
    return in_array(strtolower(trim($type)), ['follow', 'accept', 'reject'], true);
}

function ap_delivery_should_log(bool $ok, string $type): bool
{
    return !$ok || ap_delivery_is_follow_event($type);
}

function ap_delivery_scrub(string $text, int $limit = 120): string
{
    $text = preg_replace('/-----BEGIN [^-]+-----.*?-----END [^-]+-----/s', '', $text) ?? '';
    foreach ([
        '/signature="[^"]*"/i',
        '/keyid="[^"]*"/i',
        '/authorization:\s*\S+/i',
        '/bearer\s+\S+/i',
        '/digest:\s*sha-256=\S+/i',
        '/\b(?:signature|digest|keyid)\s*[:=]\s*\S+/i',
    ] as $pattern) {
        $text = preg_replace($pattern, '', $text) ?? '';
    }
    if (stripos($text, 'PRIVATE KEY') !== false) {
        return '';
    }
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($limit > 0 && strlen($text) > $limit) {
        $text = substr($text, 0, $limit);
    }
    return $text;
}

/** HTTP validator safe to store and send back. Drops control characters. */
function ap_follow_fallback_validator(?string $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '' || strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        return null;
    }
    return $value;
}

function ap_follow_fallback_host(string $raw): string
{
    $raw = strtolower(trim($raw));
    if ($raw === '') {
        return '';
    }
    if (str_contains($raw, '://')) {
        $host = parse_url($raw, PHP_URL_HOST);
        $raw = is_string($host) ? strtolower($host) : '';
    }
    $raw = explode('/', $raw)[0];
    $raw = preg_replace('/:\d+$/', '', rtrim($raw, '.')) ?? '';
    if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $raw) !== 1) {
        return '';
    }
    return $raw;
}

function ap_delivery_log_line(string $host, string $type, int $status, bool $ok, string $body = '', string $path = ''): string
{
    $host = ap_follow_fallback_host($host);
    if ($host === '') {
        $host = '-';
    }
    $type = preg_replace('/[^A-Za-z]/', '', $type) ?? '';
    if ($type === '' || strlen($type) > 32) {
        $type = 'Activity';
    }
    $path = explode('?', $path, 2)[0];
    if (preg_match('#^/[A-Za-z0-9._~%!$&\'()*+,;=:@/-]{0,80}$#', $path) !== 1) {
        $path = '';
    }
    $line = 'deliver host=' . $host . ' type=' . $type . ' status=' . $status . ' ok=' . ($ok ? '1' : '0');
    if ($path !== '') {
        $line .= ' path=' . $path;
    }
    if (!$ok) {
        $snippet = ap_delivery_scrub($body, 120);
        if ($snippet !== '') {
            $line .= ' body=' . $snippet;
        }
    }
    return $line;
}

function ap_delivery_log_path(): string
{
    $base = defined('AP_INBOX_LOG') ? (string) AP_INBOX_LOG : sys_get_temp_dir() . '/ap-inbox.log';
    return dirname($base) . '/ap-delivery.log';
}

function ap_delivery_should_rotate(int $size, int $cap): bool
{
    return $cap > 0 && $size >= $cap;
}

function ap_delivery_log_append(string $path, string $line, int $cap = 262144): void
{
    $line = rtrim($line, "\r\n");
    if ($path === '' || $line === '') {
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return;
    }
    $size = is_file($path) ? (int) @filesize($path) : 0;
    if (ap_delivery_should_rotate($size, $cap)) {
        @unlink($path . '.1');
        @rename($path, $path . '.1');
    }
    $entry = '[' . gmdate('c') . '] ' . $line . "\n";
    @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
}

function ap_delivery_log_result(
    string $host,
    string $type,
    int $status,
    bool $ok,
    string $body = '',
    string $path = '',
    ?string $logPath = null
): ?string {
    if (!ap_delivery_should_log($ok, $type)) {
        return null;
    }
    $line = ap_delivery_log_line($host, $type, $status, $ok, $body, $path);
    if (!$ok && !ap_delivery_is_follow_event($type)) {
        $key = ap_follow_fallback_host($host) . '|' . $status;
        $now = time();
        $recent = $GLOBALS['ap_delivery_log_recent'] ?? [];
        if (is_array($recent) && isset($recent[$key]) && ($now - (int) $recent[$key]) < 60) {
            return null;
        }
        $recent[$key] = $now;
        $GLOBALS['ap_delivery_log_recent'] = $recent;
    }
    ap_delivery_log_append($logPath ?? ap_delivery_log_path(), $line, 262144);
    return $line;
}

function ap_delivery_observe(string $inboxUrl, array $activity, int $status, bool $ok, string $body = ''): void
{
    $type = is_string($activity['type'] ?? null) ? (string) $activity['type'] : '';
    $host = strtolower((string) (parse_url($inboxUrl, PHP_URL_HOST) ?: ''));
    $path = (string) (parse_url($inboxUrl, PHP_URL_PATH) ?: '');
    ap_delivery_log_result($host, $type, $status, $ok, $body, $path);
}

function ap_follow_fallback_flag_on(array $env, string $key, bool $default): bool
{
    if (!array_key_exists($key, $env)) {
        return $default;
    }
    $raw = strtolower(trim((string) $env[$key]));
    if ($raw === '') {
        return $default;
    }
    return !in_array($raw, ['0', 'false', 'off', 'no'], true);
}

function ap_follow_fallback_int(array $env, string $key, int $default, int $min, int $max): int
{
    if (!array_key_exists($key, $env) || trim((string) $env[$key]) === '' || !is_numeric($env[$key])) {
        return $default;
    }
    return max($min, min($max, (int) $env[$key]));
}

/** @param array<string,mixed> $env @return array<string,mixed> */
function ap_follow_fallback_config(array $env): array
{
    $hosts = [];
    $rawHosts = array_key_exists('VAAK_FOLLOW_FALLBACK_HOSTS', $env)
        ? (string) $env['VAAK_FOLLOW_FALLBACK_HOSTS']
        : 'misskey.io';
    foreach (preg_split('/[\s,]+/', $rawHosts) ?: [] as $part) {
        $host = ap_follow_fallback_host((string) $part);
        if ($host !== '') {
            $hosts[$host] = true;
        }
    }
    $silenceHours = ap_follow_fallback_int($env, 'VAAK_FOLLOW_FALLBACK_SILENCE_HOURS', 24, 1, 168);
    $pendingHours = ap_follow_fallback_int($env, 'VAAK_FOLLOW_FALLBACK_PENDING_HOURS', 6, 1, 168);
    $minMinutes = ap_follow_fallback_int($env, 'VAAK_FOLLOW_FALLBACK_MIN_INTERVAL_MINUTES', 15, 15, 30);
    return [
        'enabled' => ap_follow_fallback_flag_on($env, 'VAAK_FOLLOW_FALLBACK', true),
        'hosts' => array_keys($hosts),
        'silence_seconds' => $silenceHours * 3600,
        'pending_seconds' => $pendingHours * 3600,
        'page_limit' => ap_follow_fallback_int($env, 'VAAK_FOLLOW_FALLBACK_PAGE_LIMIT', 5, 1, 8),
        'max_accounts' => ap_follow_fallback_int($env, 'VAAK_FOLLOW_FALLBACK_MAX_ACCOUNTS', 8, 1, 20),
        'min_interval_seconds' => $minMinutes * 60,
        'featured_limit' => 3,
        'request_budget' => 3,
    ];
}

function ap_follow_fallback_due(int $lastRun, int $now, int $minSeconds): bool
{
    if ($minSeconds < 1 || $lastRun <= 0) {
        return true;
    }
    return ($now - $lastRun) >= $minSeconds;
}

function ap_follow_fallback_backoff_seconds(int $failCount, int $status): int
{
    $failCount = max(1, $failCount);
    $shift = min(6, $failCount - 1);
    $delay = 15 * 60 * (2 ** $shift);
    if ($status === 429) {
        return min(6 * 3600, $delay);
    }
    if ($status >= 500 || $status === 0) {
        return min(2 * 3600, $delay);
    }
    return 6 * 3600;
}

/**
 * @param array<string,mixed> $config
 * @param list<array<string,mixed>> $accounts
 * @param array<string,int> $hostSeen
 * @return list<string>
 */
function ap_follow_fallback_plan(array $config, array $accounts, array $hostSeen, int $now): array
{
    if (empty($config['enabled'])) {
        return [];
    }
    $force = [];
    foreach ($config['hosts'] ?? [] as $host) {
        $host = ap_follow_fallback_host((string) $host);
        if ($host !== '') {
            $force[$host] = true;
        }
    }
    $pendingAfter = (int) ($config['pending_seconds'] ?? 21600);
    $silenceAfter = (int) ($config['silence_seconds'] ?? 86400);
    $ranked = [];
    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }
        $actor = rtrim(trim((string) ($account['actor_id'] ?? '')), '/');
        $host = ap_follow_fallback_host((string) ($account['host'] ?? ''));
        $state = ap_follow_state_normalize((string) ($account['follow_state'] ?? ''));
        $stateAt = (int) ($account['state_at'] ?? 0);
        $backoff = (int) ($account['backoff_until'] ?? 0);
        if ($actor === '' || $host === '' || $state === 'rejected' || $backoff > $now) {
            continue;
        }
        $reason = '';
        $seenAt = 0;
        if (isset($force[$host])) {
            $reason = 'force';
        } elseif ($state === 'pending' && $stateAt > 0 && ($now - $stateAt) >= $pendingAfter) {
            $reason = 'pending';
        } elseif (isset($hostSeen[$host]) && ($now - (int) $hostSeen[$host]) >= $silenceAfter) {
            $reason = 'silent';
            $seenAt = (int) $hostSeen[$host];
        }
        if ($reason === '') {
            continue;
        }
        $ranked[] = [
            'actor_id' => $actor,
            'reason' => $reason,
            'state_at' => $stateAt,
            'seen_at' => $seenAt,
        ];
    }
    $rank = ['force' => 0, 'pending' => 1, 'silent' => 2];
    usort($ranked, static function (array $a, array $b) use ($rank): int {
        $cmp = ($rank[$a['reason']] ?? 9) <=> ($rank[$b['reason']] ?? 9);
        if ($cmp !== 0) {
            return $cmp;
        }
        if ($a['reason'] === 'pending' && $a['state_at'] !== $b['state_at']) {
            return $a['state_at'] <=> $b['state_at'];
        }
        if ($a['reason'] === 'silent' && $a['seen_at'] !== $b['seen_at']) {
            return $a['seen_at'] <=> $b['seen_at'];
        }
        return strcmp($a['actor_id'], $b['actor_id']);
    });
    $out = [];
    $seenActor = [];
    $cap = max(1, (int) ($config['max_accounts'] ?? 8));
    foreach ($ranked as $row) {
        if (isset($seenActor[$row['actor_id']])) {
            continue;
        }
        $seenActor[$row['actor_id']] = true;
        $out[] = $row['actor_id'];
        if (count($out) >= $cap) {
            break;
        }
    }
    return $out;
}

/**
 * @param list<string> $objectIds
 * @param list<string> $seen
 * @return array{ids:list<string>,stopped:bool}
 */
function ap_follow_fallback_select_new(array $objectIds, array $seen, int $limit, bool $stopAtSeen = true): array
{
    $known = [];
    foreach ($seen as $id) {
        $id = rtrim(trim((string) $id), '/');
        if ($id !== '') {
            $known[$id] = true;
        }
    }
    $ids = [];
    $stopped = false;
    if ($limit < 1) {
        return ['ids' => [], 'stopped' => false];
    }
    foreach ($objectIds as $id) {
        $id = rtrim(trim((string) $id), '/');
        if ($id === '' || !str_starts_with($id, 'https://')) {
            continue;
        }
        if (isset($known[$id])) {
            if ($stopAtSeen) {
                $stopped = true;
                break;
            }
            continue;
        }
        $ids[] = $id;
        if (count($ids) >= $limit) {
            break;
        }
    }
    return ['ids' => $ids, 'stopped' => $stopped];
}

/**
 * One outbox or featured page. Bare URLs are not fetched.
 *
 * @param array<string,mixed> $doc
 * @return array{items:list<array{object_id:string,activity:array<string,mixed>}>,next:string}
 */
function ap_follow_fallback_page(array $doc, string $actorId): array
{
    $actorId = rtrim(trim($actorId), '/');
    $rawItems = [];
    if (isset($doc['orderedItems']) && is_array($doc['orderedItems'])) {
        $rawItems = $doc['orderedItems'];
    } elseif (isset($doc['items']) && is_array($doc['items'])) {
        $rawItems = $doc['items'];
    }
    $items = [];
    foreach ($rawItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = is_string($item['type'] ?? null) ? (string) $item['type'] : '';
        if ($type === 'Announce') {
            continue;
        }
        if ($type === 'Create') {
            $actActor = ap_follow_state_id($item['actor'] ?? null);
            if ($actActor === '' || $actActor !== $actorId) {
                continue;
            }
            $object = $item['object'] ?? null;
            if (!is_array($object)) {
                continue;
            }
            $objectId = ap_follow_state_id($object['id'] ?? null);
            if ($objectId === '') {
                continue;
            }
            $items[] = ['object_id' => $objectId, 'activity' => $item];
            continue;
        }
        if (!in_array($type, ['Note', 'Article', 'Question', 'Page'], true)) {
            continue;
        }
        $attributed = ap_follow_state_id($item['attributedTo'] ?? ($item['actor'] ?? null));
        if ($attributed !== '' && $attributed !== $actorId) {
            continue;
        }
        $objectId = ap_follow_state_id($item['id'] ?? null);
        if ($objectId === '' || $actorId === '') {
            continue;
        }
        $to = isset($item['to']) && is_array($item['to']) ? array_values($item['to']) : ['https://www.w3.org/ns/activitystreams#Public'];
        $cc = isset($item['cc']) && is_array($item['cc']) ? array_values($item['cc']) : [];
        $items[] = [
            'object_id' => $objectId,
            'activity' => [
                'type' => 'Create',
                'actor' => $actorId,
                'to' => $to,
                'cc' => $cc,
                'object' => $item,
            ],
        ];
    }
    $next = '';
    if ($items === []) {
        $first = $doc['first'] ?? null;
        if (is_string($first) && str_starts_with($first, 'https://')) {
            $next = $first;
        } elseif (is_array($first)) {
            $next = ap_follow_state_id($first['id'] ?? null);
        }
    }
    return ['items' => $items, 'next' => $next];
}

/** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
function ap_following_rows_active(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (strtolower((string) ($row['follow_state'] ?? '')) === 'rejected') {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

function ap_following_has_state(PDO $db): bool
{
    static $cache = [];
    $key = spl_object_id($db);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        if (function_exists('ap_schema_has_column') && function_exists('ap_db') && ap_db() === $db) {
            return $cache[$key] = ap_schema_has_column($db, 'following', 'follow_state');
        }
        $driver = strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'pgsql') {
            $st = $db->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = ? AND column_name = ? LIMIT 1'
            );
            $st->execute(['following', 'follow_state']);
            return $cache[$key] = (bool) $st->fetchColumn();
        }
        $names = array_column($db->query('PRAGMA table_info(following)')->fetchAll(), 'name');
        return $cache[$key] = in_array('follow_state', $names, true);
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function ap_following_state_write(
    PDO $db,
    string $owner,
    string $actorId,
    ?string $host,
    string $now,
    ?string $followState,
    ?string $followActivityId
): bool {
    $owner = rtrim(trim($owner), '/');
    $actorId = rtrim(trim($actorId), '/');
    if ($owner === '' || $actorId === '') {
        return false;
    }
    $host = is_string($host) && $host !== '' ? strtolower($host) : null;
    $legacy = 'INSERT INTO following (owner_actor_id, actor_id, followed_at, host) VALUES (?, ?, ?, ?)
        ON CONFLICT(owner_actor_id, actor_id) DO NOTHING';
    if (!ap_following_has_state($db) || $followState === null) {
        if (!ap_following_has_state($db)) {
            $db->prepare($legacy)->execute([$owner, $actorId, $now, $host]);
            return true;
        }
        $db->prepare(
            'INSERT INTO following (owner_actor_id, actor_id, followed_at, host, follow_state, follow_activity_id, state_at)
             VALUES (?, ?, ?, ?, \'unknown\', NULL, NULL)
             ON CONFLICT(owner_actor_id, actor_id) DO NOTHING'
        )->execute([$owner, $actorId, $now, $host]);
        return true;
    }
    $state = ap_follow_state_normalize($followState);
    $followActivityId = is_string($followActivityId) && $followActivityId !== '' ? $followActivityId : null;
    $db->prepare(
        'INSERT INTO following (owner_actor_id, actor_id, followed_at, host, follow_state, follow_activity_id, state_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(owner_actor_id, actor_id) DO UPDATE SET
           follow_state = excluded.follow_state,
           follow_activity_id = COALESCE(excluded.follow_activity_id, following.follow_activity_id),
           state_at = excluded.state_at,
           host = COALESCE(excluded.host, following.host)'
    )->execute([$owner, $actorId, $now, $host, $state, $followActivityId, $now]);
    return true;
}

/** @return array<string,mixed>|null */
function ap_following_row_by_activity(PDO $db, string $followId): ?array
{
    $followId = rtrim(trim($followId), '/');
    if ($followId === '' || !ap_following_has_state($db)) {
        return null;
    }
    $st = $db->prepare(
        'SELECT owner_actor_id, actor_id, host, follow_state, follow_activity_id
         FROM following WHERE follow_activity_id = ? LIMIT 1'
    );
    $st->execute([$followId]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

function ap_inbox_host_seen_touch(PDO $db, string $host, string $type, int $nowUnix, int $minSeconds = 300): void
{
    $host = ap_follow_fallback_host($host);
    if ($host === '') {
        return;
    }
    $type = preg_replace('/[^A-Za-z]/', '', $type) ?? '';
    if ($type === '' || strlen($type) > 32) {
        $type = 'Activity';
    }
    $now = gmdate('c', $nowUnix);
    $cutoff = gmdate('c', $nowUnix - max(0, $minSeconds));
    $db->prepare(
        'INSERT INTO ap_inbox_host_seen (host, last_seen_at, last_type) VALUES (?, ?, ?)
         ON CONFLICT(host) DO UPDATE SET last_seen_at = excluded.last_seen_at, last_type = excluded.last_type
         WHERE ap_inbox_host_seen.last_seen_at <= ?'
    )->execute([$host, $now, $type, $cutoff]);
}

function ap_inbox_host_seen_note(string $host, string $type): void
{
    if (!function_exists('ap_db')) {
        return;
    }
    $local = function_exists('ap_local_actor_id') ? (string) ap_local_actor_id() : '';
    $localHost = strtolower((string) (parse_url($local, PHP_URL_HOST) ?: ''));
    if ($localHost !== '' && strtolower(trim($host)) === $localHost) {
        return;
    }
    try {
        ap_inbox_host_seen_touch(ap_db(), $host, $type, time(), 300);
    } catch (Throwable $e) {
        error_log('[ap-follow] inbox host seen skipped: ' . $e->getMessage());
    }
}

/**
 * Indexed lookups only: following by host, pending by state, never bsky_posts.
 *
 * @param list<string> $hosts
 * @return list<array<string,mixed>>
 */
function ap_follow_fallback_load_rows(PDO $db, array $hosts, string $pendingBefore, int $perHost = 50): array
{
    if (!ap_following_has_state($db)) {
        return [];
    }
    $perHost = max(1, min(50, $perHost));
    $rows = [];
    $seen = [];
    // Distinct followed hosts come from the small follow-graph index, not from
    // events or bsky_posts. The planner then keeps only silent, pending, or
    // forced hosts.
    try {
        $distinct = $db->query(
            "SELECT host FROM following WHERE host IS NOT NULL AND host <> '' GROUP BY host LIMIT 64"
        );
        foreach ($distinct->fetchAll() ?: [] as $row) {
            if (is_array($row) && isset($row['host'])) {
                $hosts[] = (string) $row['host'];
            }
        }
    } catch (Throwable $e) {
        // The caller still has the explicit force list.
    }
    $pull = static function (array $row) use (&$rows, &$seen): void {
        $key = rtrim((string) ($row['owner_actor_id'] ?? ''), '/') . '|' . rtrim((string) ($row['actor_id'] ?? ''), '/');
        if ($key === '|' || isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $rows[] = $row;
    };
    $byHost = $db->prepare(
        'SELECT owner_actor_id, actor_id, host, follow_state, state_at, followed_at
         FROM following WHERE host = ? LIMIT ' . $perHost
    );
    $checked = 0;
    foreach ($hosts as $host) {
        if ($checked >= 64) {
            break;
        }
        $host = ap_follow_fallback_host((string) $host);
        if ($host === '') {
            continue;
        }
        $checked++;
        $byHost->execute([$host]);
        foreach ($byHost->fetchAll() ?: [] as $row) {
            if (is_array($row)) {
                $pull($row);
            }
        }
    }
    if ($pendingBefore !== '') {
        $pending = $db->prepare(
            'SELECT owner_actor_id, actor_id, host, follow_state, state_at, followed_at
             FROM following
             WHERE follow_state = \'pending\' AND state_at IS NOT NULL AND state_at <= ?
             LIMIT 50'
        );
        $pending->execute([$pendingBefore]);
        foreach ($pending->fetchAll() ?: [] as $row) {
            if (is_array($row)) {
                $pull($row);
            }
        }
    }
    return $rows;
}

/** Point lookup on events.object_id. Does not read bsky_posts. */
function ap_follow_object_known(PDO $db, string $objectId): bool
{
    $objectId = rtrim(trim($objectId), '/');
    if ($objectId === '' || !str_starts_with($objectId, 'https://')) {
        return false;
    }
    try {
        $st = $db->prepare('SELECT 1 FROM events WHERE object_id = ? OR object_id = ? LIMIT 1');
        $st->execute([$objectId, $objectId . '/']);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array<string,mixed>|null */
function ap_follow_fallback_state_get(PDO $db, string $actorId): ?array
{
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '') {
        return null;
    }
    try {
        $st = $db->prepare('SELECT * FROM ap_follow_fallback_state WHERE actor_id = ? LIMIT 1');
        $st->execute([$actorId]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** @param array<string,mixed> $row */
function ap_follow_fallback_state_put(PDO $db, array $row): void
{
    $actor = rtrim(trim((string) ($row['actor_id'] ?? '')), '/');
    $host = ap_follow_fallback_host((string) ($row['host'] ?? ''));
    if ($actor === '' || $host === '') {
        return;
    }
    $db->prepare(
        'INSERT INTO ap_follow_fallback_state
           (actor_id, host, outbox_url, featured_url, etag, last_modified, featured_etag, featured_last_modified,
            next_attempt_at, fail_count, last_status, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(actor_id) DO UPDATE SET
           host = excluded.host,
           outbox_url = COALESCE(excluded.outbox_url, ap_follow_fallback_state.outbox_url),
           featured_url = COALESCE(excluded.featured_url, ap_follow_fallback_state.featured_url),
           etag = excluded.etag,
           last_modified = excluded.last_modified,
           featured_etag = excluded.featured_etag,
           featured_last_modified = excluded.featured_last_modified,
           next_attempt_at = excluded.next_attempt_at,
           fail_count = excluded.fail_count,
           last_status = excluded.last_status,
           updated_at = excluded.updated_at'
    )->execute([
        $actor,
        $host,
        $row['outbox_url'] ?? null,
        $row['featured_url'] ?? null,
        ap_follow_fallback_validator(is_string($row['etag'] ?? null) ? (string) $row['etag'] : null),
        ap_follow_fallback_validator(is_string($row['last_modified'] ?? null) ? (string) $row['last_modified'] : null),
        ap_follow_fallback_validator(is_string($row['featured_etag'] ?? null) ? (string) $row['featured_etag'] : null),
        ap_follow_fallback_validator(is_string($row['featured_last_modified'] ?? null) ? (string) $row['featured_last_modified'] : null),
        $row['next_attempt_at'] ?? null,
        (int) ($row['fail_count'] ?? 0),
        isset($row['last_status']) ? (int) $row['last_status'] : null,
        (string) ($row['updated_at'] ?? gmdate('c')),
    ]);
}

/**
 * One fallback pass. $fetch, $ingest, $known, and $actorUrls are injected so
 * the scheduler can run this without a network in tests.
 *
 * @param array<string,mixed> $env
 * @return array<string,mixed>
 */
function ap_follow_fallback_execute(
    PDO $db,
    array $env,
    int $now,
    callable $fetch,
    callable $ingest,
    callable $known,
    ?callable $actorUrls = null
): array {
    $config = ap_follow_fallback_config($env);
    $result = [
        'ok' => true,
        'disabled' => !$config['enabled'],
        'accounts' => 0,
        'requests' => 0,
        'imported' => 0,
        'not_modified' => 0,
        'errors' => 0,
    ];
    if (!$config['enabled']) {
        return $result;
    }
    if (!ap_following_has_state($db)) {
        $result['ok'] = false;
        $result['errors'] = 1;
        return $result;
    }
    $pendingBefore = gmdate('c', $now - (int) $config['pending_seconds']);
    $loaded = ap_follow_fallback_load_rows($db, $config['hosts'], $pendingBefore, 50);
    $hostSeen = [];
    $hosts = [];
    foreach ($config['hosts'] as $host) {
        $hosts[$host] = true;
    }
    foreach ($loaded as $row) {
        $host = ap_follow_fallback_host((string) ($row['host'] ?? ''));
        if ($host !== '') {
            $hosts[$host] = true;
        }
    }
    $seenStmt = $db->prepare('SELECT last_seen_at FROM ap_inbox_host_seen WHERE host = ? LIMIT 1');
    foreach (array_keys($hosts) as $host) {
        try {
            $seenStmt->execute([$host]);
            $stamp = $seenStmt->fetchColumn();
        } catch (Throwable $e) {
            $stamp = false;
        }
        if (is_string($stamp) && $stamp !== '') {
            $unix = strtotime($stamp);
            if ($unix !== false) {
                $hostSeen[$host] = $unix;
            }
        }
    }
    $accounts = [];
    foreach ($loaded as $row) {
        $actor = rtrim((string) ($row['actor_id'] ?? ''), '/');
        $stateAt = strtotime((string) ($row['state_at'] ?? '')) ?: strtotime((string) ($row['followed_at'] ?? '')) ?: 0;
        $saved = ap_follow_fallback_state_get($db, $actor);
        $backoff = 0;
        if (is_array($saved) && is_string($saved['next_attempt_at'] ?? null) && $saved['next_attempt_at'] !== '') {
            $backoff = strtotime((string) $saved['next_attempt_at']) ?: 0;
        }
        $accounts[] = [
            'actor_id' => $actor,
            'host' => (string) ($row['host'] ?? ''),
            'follow_state' => (string) ($row['follow_state'] ?? ''),
            'state_at' => $stateAt,
            'backoff_until' => $backoff,
        ];
    }
    $plan = ap_follow_fallback_plan($config, $accounts, $hostSeen, $now);
    $result['accounts'] = count($plan);
    $byActor = [];
    foreach ($loaded as $row) {
        $byActor[rtrim((string) ($row['actor_id'] ?? ''), '/')] = $row;
    }
    foreach ($plan as $actor) {
        $row = $byActor[$actor] ?? ['host' => (string) (parse_url($actor, PHP_URL_HOST) ?: ''), 'actor_id' => $actor];
        $saved = ap_follow_fallback_state_get($db, $actor) ?? [];
        $budget = (int) $config['request_budget'];
        $outbox = is_string($saved['outbox_url'] ?? null) ? (string) $saved['outbox_url'] : '';
        $featured = is_string($saved['featured_url'] ?? null) ? (string) $saved['featured_url'] : '';
        $failCount = (int) ($saved['fail_count'] ?? 0);
        if ($outbox === '' && $actorUrls !== null && $budget > 0) {
            $urls = $actorUrls($actor);
            $result['requests']++;
            $budget--;
            if (is_array($urls)) {
                $outbox = ap_follow_state_id($urls['outbox'] ?? null);
                $featured = ap_follow_state_id($urls['featured'] ?? null);
            }
        }
        $store = [
            'actor_id' => $actor,
            'host' => (string) ($row['host'] ?? ''),
            'outbox_url' => $outbox !== '' ? $outbox : null,
            'featured_url' => $featured !== '' ? $featured : null,
            'etag' => $saved['etag'] ?? null,
            'last_modified' => $saved['last_modified'] ?? null,
            'featured_etag' => $saved['featured_etag'] ?? null,
            'featured_last_modified' => $saved['featured_last_modified'] ?? null,
            'next_attempt_at' => null,
            'fail_count' => 0,
            'last_status' => 0,
            'updated_at' => gmdate('c', $now),
        ];
        if ($outbox === '' || $budget <= 0) {
            $result['errors']++;
            $failCount++;
            $store['fail_count'] = $failCount;
            $store['next_attempt_at'] = gmdate('c', $now + ap_follow_fallback_backoff_seconds($failCount, 0));
            ap_follow_fallback_state_put($db, $store);
            continue;
        }
        $page = ap_follow_fallback_fetch_page(
            $fetch,
            $outbox,
            is_string($saved['etag'] ?? null) ? (string) $saved['etag'] : null,
            is_string($saved['last_modified'] ?? null) ? (string) $saved['last_modified'] : null,
            $actor,
            $result
        );
        $budget--;
        $store['last_status'] = (int) ($page['status'] ?? 0);
        if (!empty($page['etag'])) {
            $store['etag'] = $page['etag'];
        }
        if (!empty($page['last_modified'])) {
            $store['last_modified'] = $page['last_modified'];
        }
        if (($page['status'] ?? 0) === 304) {
            $result['not_modified']++;
            ap_follow_fallback_state_put($db, $store);
            continue;
        }
        if (empty($page['ok'])) {
            $result['errors']++;
            $failCount++;
            $store['fail_count'] = $failCount;
            $store['next_attempt_at'] = gmdate('c', $now + ap_follow_fallback_backoff_seconds($failCount, (int) $store['last_status']));
            ap_follow_fallback_state_put($db, $store);
            continue;
        }
        $doc = is_array($page['doc'] ?? null) ? $page['doc'] : [];
        if (($page['items'] ?? []) === [] && ($page['next'] ?? '') !== '' && $budget > 0) {
            $next = ap_follow_fallback_fetch_page($fetch, (string) $page['next'], null, null, $actor, $result);
            $budget--;
            if (!empty($next['ok'])) {
                $doc = is_array($next['doc'] ?? null) ? $next['doc'] : $doc;
                $page['items'] = $next['items'] ?? [];
            }
        }
        $imported = ap_follow_fallback_ingest_items(
            is_array($page['items'] ?? null) ? $page['items'] : [],
            (int) $config['page_limit'],
            true,
            $known,
            $ingest
        );
        $result['imported'] += $imported;
        if ($featured !== '' && $budget > 0) {
            $pins = ap_follow_fallback_fetch_page(
                $fetch,
                $featured,
                is_string($saved['featured_etag'] ?? null) ? (string) $saved['featured_etag'] : null,
                is_string($saved['featured_last_modified'] ?? null) ? (string) $saved['featured_last_modified'] : null,
                $actor,
                $result
            );
            if (!empty($pins['etag'])) {
                $store['featured_etag'] = $pins['etag'];
            }
            if (!empty($pins['last_modified'])) {
                $store['featured_last_modified'] = $pins['last_modified'];
            }
            if (!empty($pins['ok']) && (int) ($pins['status'] ?? 0) !== 304) {
                $result['imported'] += ap_follow_fallback_ingest_items(
                    is_array($pins['items'] ?? null) ? $pins['items'] : [],
                    (int) $config['featured_limit'],
                    false,
                    $known,
                    $ingest
                );
            }
        }
        ap_follow_fallback_state_put($db, $store);
    }
    return $result;
}

/**
 * @param array<string,mixed> $result
 * @return array<string,mixed>
 */
function ap_follow_fallback_fetch_page(callable $fetch, string $url, ?string $etag, ?string $lastModified, string $actorId, array &$result): array
{
    $response = $fetch($url, $etag, $lastModified);
    if (!is_array($response)) {
        $response = [];
    }
    $result['requests'] += max(1, (int) ($response['requests'] ?? 1));
    $status = (int) ($response['status'] ?? 0);
    $out = [
        'ok' => $status === 304 || ($status >= 200 && $status < 300),
        'status' => $status,
        'etag' => ap_follow_fallback_validator(is_string($response['etag'] ?? null) ? (string) $response['etag'] : null) ?? '',
        'last_modified' => ap_follow_fallback_validator(is_string($response['last_modified'] ?? null) ? (string) $response['last_modified'] : null) ?? '',
        'items' => [],
        'next' => '',
        'doc' => null,
    ];
    if ($status === 304 || $status < 200 || $status >= 300) {
        return $out;
    }
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($decoded)) {
        $out['ok'] = false;
        return $out;
    }
    $page = ap_follow_fallback_page($decoded, $actorId);
    $out['doc'] = $decoded;
    $out['items'] = $page['items'];
    $out['next'] = $page['next'];
    return $out;
}

/**
 * @param list<array{object_id:string,activity:array<string,mixed>}> $items
 */
function ap_follow_fallback_ingest_items(array $items, int $limit, bool $stopAtSeen, callable $known, callable $ingest): int
{
    $ids = [];
    $byId = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = rtrim((string) ($item['object_id'] ?? ''), '/');
        if ($id === '') {
            continue;
        }
        $ids[] = $id;
        $byId[$id] = $item['activity'] ?? null;
    }
    $seen = [];
    $fresh = 0;
    foreach ($ids as $id) {
        if ($known($id)) {
            $seen[] = $id;
            if ($stopAtSeen) {
                break;
            }
            continue;
        }
        $fresh++;
        if ($fresh >= $limit) {
            break;
        }
    }
    $picked = ap_follow_fallback_select_new($ids, $seen, $limit, $stopAtSeen);
    $imported = 0;
    foreach ($picked['ids'] as $id) {
        $activity = $byId[$id] ?? null;
        if (!is_array($activity)) {
            continue;
        }
        if ($ingest($activity)) {
            $imported++;
        }
    }
    return $imported;
}
