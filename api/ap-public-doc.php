<?php
/**
 * Public ActivityPub documents: Pleroma-safe shape, short Redis cache,
 * and HTTP validators (ETag / Last-Modified / 304).
 *
 * Redis is optional. A missing client still emits Cache-Control and
 * validators so a shared HTTP cache can absorb repeats. Signed fetch and
 * Accept negotiation stay with the front controllers. A cache hit for a
 * public actor, featured collection, or note can answer before the database
 * layer loads.
 *
 * Pre-2.10 Pleroma stores alsoKnownAs as an array of http(s) object ids.
 * An at:// actor alias fails that cast, the user is never saved, and
 * discovery fetches the actor, featured collection, pins, WebFinger, and
 * host-meta again. Note alsoKnownAs is left alone: that validator applies
 * to users, not objects. The October note-url fix is separate and still
 * collapses url to one https string.
 */
declare(strict_types=1);

require_once __DIR__ . '/ap-redis.php';

const AP_PUBLIC_DOC_CACHE_TTL = 90;
const AP_PUBLIC_DOC_MAX_AGE = 60;

function ap_public_doc_cache_key(string $id): string
{
    $id = rtrim(trim($id), '/');
    // v2 drops the cached actor that still advertised an at:// alias.
    return 'vaak:apdoc:v2:' . hash('sha256', $id);
}

function ap_public_doc_local_actor_id(string $actorKey): string
{
    $actorKey = strtolower(trim($actorKey));
    return 'https://mkultra.monster/users/' . rawurlencode($actorKey);
}

function ap_public_doc_is_shared_visibility(string $visibility): bool
{
    $visibility = strtolower(trim($visibility));
    return $visibility === '' || $visibility === 'public' || $visibility === 'unlisted';
}

function ap_public_doc_content_type(string $accept): string
{
    if (str_contains(strtolower($accept), 'application/activity+json')) {
        return 'application/activity+json; charset=utf-8';
    }
    return 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"; charset=utf-8';
}

function ap_public_doc_http_date(?string $iso): string
{
    $iso = trim((string) $iso);
    if ($iso === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return '';
    }
    return $dt->setTimezone(new DateTimeZone('GMT'))->format('D, d M Y H:i:s') . ' GMT';
}

/**
 * Profile updated_at, or null when the row has no usable timestamp.
 * Callers omit `updated` in that case. Filling it with the request time
 * makes a remote treat the actor as changed on every fetch.
 */
function ap_actor_updated_iso(string $updatedRaw): ?string
{
    $updatedRaw = trim($updatedRaw);
    if ($updatedRaw === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($updatedRaw))->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Last-Modified for a public document. Empty when neither the caller nor
 * the document has a real timestamp, so a featured collection does not
 * advertise a fresh modification every time the cache is rebuilt.
 *
 * @param array<string,mixed> $doc
 */
function ap_public_doc_last_modified(?string $modifiedIso, array $doc): string
{
    if ($modifiedIso === null || trim($modifiedIso) === '') {
        $fromDoc = $doc['updated'] ?? $doc['published'] ?? null;
        $modifiedIso = is_string($fromDoc) ? $fromDoc : '';
    }
    return ap_public_doc_http_date($modifiedIso);
}

/**
 * 304 when If-None-Match hits. A present If-None-Match wins over
 * If-Modified-Since, matching RFC 9110.
 */
function ap_public_doc_conditional_status(
    string $etag,
    string $lastModified,
    ?string $ifNoneMatch,
    ?string $ifModifiedSince
): int {
    $ifNoneMatch = trim((string) $ifNoneMatch);
    if ($ifNoneMatch !== '') {
        if ($ifNoneMatch === '*') {
            return 304;
        }
        $mine = trim($etag, " \t\"");
        foreach (explode(',', $ifNoneMatch) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'W/')) {
                $part = trim(substr($part, 2));
            }
            $part = trim($part, " \t\"");
            if ($part !== '' && hash_equals($mine, $part)) {
                return 304;
            }
        }
        return 200;
    }
    $ifModifiedSince = trim((string) $ifModifiedSince);
    if ($ifModifiedSince === '' || $lastModified === '') {
        return 200;
    }
    $since = strtotime($ifModifiedSince);
    $mod = strtotime($lastModified);
    if ($since !== false && $mod !== false && $mod <= $since) {
        return 304;
    }
    return 200;
}

/** @return array{body:string,etag:string,last_modified:string}|null */
function ap_public_doc_cache_get(string $id): ?array
{
    if ($id === '' || !class_exists('Redis') || !function_exists('ap_redis_json_get')) {
        return null;
    }
    $cached = ap_redis_json_get(ap_public_doc_cache_key($id));
    if (!is_array($cached)) {
        return null;
    }
    $body = $cached['body'] ?? null;
    $etag = $cached['etag'] ?? null;
    $modified = $cached['last_modified'] ?? null;
    if (!is_string($body) || $body === '' || !is_string($etag) || $etag === '' || !is_string($modified)) {
        return null;
    }
    return ['body' => $body, 'etag' => $etag, 'last_modified' => $modified];
}

function ap_public_doc_cache_put(string $id, string $body, string $etag, string $lastModified): void
{
    if ($id === '' || $body === '' || !class_exists('Redis') || !function_exists('ap_redis_json_set')) {
        return;
    }
    ap_redis_json_set(ap_public_doc_cache_key($id), [
        'body' => $body,
        'etag' => $etag,
        'last_modified' => $lastModified,
    ], AP_PUBLIC_DOC_CACHE_TTL);
}

function ap_public_doc_cache_forget(string $id): void
{
    if ($id === '' || !class_exists('Redis') || !function_exists('ap_redis_delete')) {
        return;
    }
    ap_redis_delete(ap_public_doc_cache_key($id));
}

function ap_public_doc_invalidate_actor(string $actorKey): void
{
    ap_public_doc_cache_forget(ap_public_doc_local_actor_id($actorKey));
}

function ap_public_doc_invalidate_featured(string $actorKey): void
{
    $id = ap_public_doc_local_actor_id($actorKey);
    ap_public_doc_cache_forget($id . '/collections/featured');
    ap_public_doc_cache_forget($id . '/featured');
}

function ap_public_doc_invalidate_note(string $noteId, ?string $createId = null): void
{
    ap_public_doc_cache_forget($noteId);
    if (is_string($createId) && trim($createId) !== '') {
        ap_public_doc_cache_forget($createId);
    }
    if (preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)/notes/#', $noteId, $m)) {
        ap_public_doc_invalidate_featured($m[1]);
    }
}

/**
 * Serve a previously cached public document. Returns false on a miss so the
 * caller can build the document. A true return already wrote the response;
 * the caller exits.
 */
function ap_public_doc_respond_if_cached(string $cacheId, string $accept): bool
{
    $hit = ap_public_doc_cache_get($cacheId);
    if ($hit === null) {
        return false;
    }
    ap_public_doc_write($hit['body'], $accept, $hit['etag'], $hit['last_modified'], true);
    return true;
}

/**
 * activity+json strictly outranks text/html and xhtml. A tie does not win,
 * matching the profile front controllers, which serve HTML on a tie.
 * ld+json alone is not this fast path.
 */
function ap_public_doc_activityjson_wins(string $accept): bool
{
    $accept = strtolower(trim($accept));
    if ($accept === '' || !str_contains($accept, 'application/activity+json')) {
        return false;
    }
    $score = static function (string $haystack, string $type): float {
        $best = -1.0;
        foreach (explode(',', $haystack) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $semi = strpos($part, ';');
            $media = trim($semi === false ? $part : substr($part, 0, $semi));
            if ($media !== $type) {
                continue;
            }
            $q = 1.0;
            if (preg_match('/(?:^|;)\s*q\s*=\s*([0-9.]+)/', $part, $m)) {
                $q = (float) $m[1];
            }
            if ($q > $best) {
                $best = $q;
            }
        }
        return $best;
    };
    $browserQ = max($score($accept, 'text/html'), $score($accept, 'application/xhtml+xml'));
    $activityQ = $score($accept, 'application/activity+json');
    if ($activityQ < 0) {
        return false;
    }
    if ($browserQ < 0) {
        return true;
    }
    return $activityQ > $browserQ;
}

/** Public cache id for this request, or null when the URL is not a cached document. */
function ap_public_doc_request_cache_id(): ?string
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return null;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = '/';
    }
    $path = rtrim($path, '/') ?: '/';
    $username = '';
    $sub = '';
    if (preg_match('#^/@([a-zA-Z][a-zA-Z0-9_]{1,29})(?:/(.*))?$#', $path, $m)) {
        $username = strtolower($m[1]);
        $sub = isset($m[2]) ? trim((string) $m[2], '/') : '';
    } elseif (preg_match('#^/users/([a-zA-Z][a-zA-Z0-9_]{1,29})(?:/(.*))?$#', $path, $m)) {
        $username = strtolower($m[1]);
        $sub = isset($m[2]) ? trim((string) $m[2], '/') : '';
    } else {
        return null;
    }
    $actor = ap_public_doc_local_actor_id($username);
    if ($sub === '') {
        return $actor;
    }
    if ($sub === 'featured' || $sub === 'collections/featured') {
        return $actor . '/collections/featured';
    }
    if (preg_match('#^(notes|creates)/([a-f0-9]+)$#', $sub, $note)) {
        return $actor . '/' . $note[1] . '/' . $note[2];
    }
    return null;
}

/**
 * Answer a repeated ActivityPub GET from Redis before the database layer
 * loads. Returns false on a miss or when the client did not ask for
 * activity+json ahead of HTML.
 */
function ap_public_doc_respond_early(): bool
{
    if (!ap_public_doc_activityjson_wins((string) ($_SERVER['HTTP_ACCEPT'] ?? ''))) {
        return false;
    }
    $id = ap_public_doc_request_cache_id();
    if ($id === null) {
        return false;
    }
    return ap_public_doc_respond_if_cached($id, (string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
}

/**
 * Cache-Control, ETag, and 304 for a small static or cached GET body
 * (WebFinger, host-meta). Last-Modified is sent only for a real timestamp.
 */
function ap_public_doc_conditional_headers(string $body, string $contentType, int $maxAge, ?string $modifiedIso = null): void
{
    $etag = '"' . hash('sha256', $body) . '"';
    $lastModified = ap_public_doc_http_date($modifiedIso);
    $status = ap_public_doc_conditional_status(
        $etag,
        $lastModified,
        isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (string) $_SERVER['HTTP_IF_NONE_MATCH'] : null,
        isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] : null
    );
    if ($status === 304) {
        http_response_code(304);
    }
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=' . max(0, $maxAge));
    header('ETag: ' . $etag);
    if ($lastModified !== '') {
        header('Last-Modified: ' . $lastModified);
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($status === 304 || $method === 'HEAD') {
        return;
    }
    echo $body;
}

function ap_public_doc_emit(array $doc, string $accept, string $cacheId, bool $shared, ?string $modifiedIso = null): void
{
    $doc = ap_as2_pleroma_document($doc);
    $body = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body) || $body === '') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"encode failed"}';
        return;
    }
    $etag = '"' . hash('sha256', $body) . '"';
    $lastModified = ap_public_doc_last_modified($modifiedIso, $doc);
    if ($shared && $cacheId !== '') {
        ap_public_doc_cache_put($cacheId, $body, $etag, $lastModified);
    }
    ap_public_doc_write($body, $accept, $etag, $lastModified, $shared);
}

function ap_public_doc_write(string $body, string $accept, string $etag, string $lastModified, bool $shared): void
{
    $status = $shared
        ? ap_public_doc_conditional_status(
            $etag,
            $lastModified,
            isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (string) $_SERVER['HTTP_IF_NONE_MATCH'] : null,
            isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] : null
        )
        : 200;
    if ($status === 304) {
        http_response_code(304);
    }
    header('Content-Type: ' . ap_public_doc_content_type($accept));
    header('Vary: Accept');
    if ($shared) {
        header('Cache-Control: public, max-age=' . AP_PUBLIC_DOC_MAX_AGE);
        header('ETag: ' . $etag);
        if ($lastModified !== '') {
            header('Last-Modified: ' . $lastModified);
        }
    } else {
        header('Cache-Control: no-cache, max-age=0, must-revalidate');
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($status === 304 || $method === 'HEAD') {
        return;
    }
    echo $body;
}

function ap_as2_http_url(string $value): bool
{
    return (bool) preg_match('#^https?://[^\s]+$#i', $value);
}

function ap_as2_visible_text(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\u{200B}", "\u{FEFF}", "\u{00A0}"], '', $text);
    return trim($text);
}

/**
 * Pleroma/Soapbox ArticleNotePageValidator.fix_url/1 accepts a binary URL or
 * one Link map. It does not accept a list. ObjectValidators.Uri then allows
 * only http(s). An at:// alternate in `url` fails the cast, so the Note never
 * inserts and a pinned copy is fetched again on every actor refresh.
 *
 * @param array<string,mixed> $node
 * @return array<string,mixed>
 */
function ap_as2_pleroma_apply_url(array $node): array
{
    if (!array_key_exists('url', $node)) {
        return $node;
    }
    $https = [];
    $at = [];
    $walk = static function (mixed $value) use (&$https, &$at, &$walk): void {
        if (is_string($value)) {
            if (str_starts_with($value, 'at://')) {
                $at[] = $value;
            } elseif (ap_as2_http_url($value)) {
                $https[] = $value;
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        if (isset($value['href']) && is_string($value['href'])) {
            $walk($value['href']);
            return;
        }
        if (array_is_list($value)) {
            foreach ($value as $item) {
                $walk($item);
            }
        }
    };
    $walk($node['url']);
    $https = array_values(array_unique($https));
    $at = array_values(array_unique($at));
    if ($at !== [] && (!isset($node['blueskyUri']) || !is_string($node['blueskyUri']) || trim($node['blueskyUri']) === '')) {
        $node['blueskyUri'] = $at[0];
    }
    if ($https !== []) {
        $id = isset($node['id']) && is_string($node['id']) ? $node['id'] : '';
        $node['url'] = ($id !== '' && in_array($id, $https, true)) ? $id : $https[0];
        return $node;
    }
    $id = isset($node['id']) && is_string($node['id']) ? $node['id'] : '';
    if (ap_as2_http_url($id)) {
        $node['url'] = $id;
        return $node;
    }
    unset($node['url']);
    return $node;
}

/**
 * Empty `<p></p>` survives a string cast and then disappears in an HTML
 * scrubber. Media posts need a non-blank content string. A zero-width space
 * stays empty on screen and is not Unicode whitespace, so Elixir String.trim
 * keeps it.
 *
 * @param array<string,mixed> $node
 * @return array<string,mixed>
 */
function ap_as2_pleroma_media_content(array $node): array
{
    $type = $node['type'] ?? '';
    if (!is_string($type) || !in_array($type, ['Note', 'Article', 'Page', 'Question'], true)) {
        return $node;
    }
    if (!ap_as2_has_attachment($node)) {
        return $node;
    }
    if (isset($node['content']) && !is_string($node['content'])) {
        return $node;
    }
    if (isset($node['contentMap']) && is_array($node['contentMap'])) {
        foreach ($node['contentMap'] as $value) {
            if (is_string($value) && ap_as2_visible_text($value) !== '') {
                return $node;
            }
        }
    }
    $content = isset($node['content']) && is_string($node['content']) ? $node['content'] : '';
    if (ap_as2_visible_text($content) !== '') {
        return $node;
    }
    $node['content'] = '<p>&#8203;</p>';
    return $node;
}

function ap_as2_has_attachment(array $node): bool
{
    $attachment = $node['attachment'] ?? null;
    if (!is_array($attachment) || $attachment === []) {
        return false;
    }
    if (isset($attachment['type']) || isset($attachment['url']) || isset($attachment['href'])) {
        return true;
    }
    foreach ($attachment as $item) {
        if (is_array($item)) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<string,mixed> $node
 * @return array<string,mixed>
 */
function ap_as2_pleroma_node(array $node): array
{
    if (array_key_exists('url', $node)) {
        $node = ap_as2_pleroma_apply_url($node);
    }
    $node = ap_as2_pleroma_media_content($node);
    foreach (['object', 'attachment', 'icon', 'image', 'oneOf', 'anyOf'] as $key) {
        if (!isset($node[$key]) || !is_array($node[$key])) {
            continue;
        }
        if (array_is_list($node[$key])) {
            foreach ($node[$key] as $index => $item) {
                if (is_array($item)) {
                    $node[$key][$index] = ap_as2_pleroma_node($item);
                }
            }
        } else {
            $node[$key] = ap_as2_pleroma_node($node[$key]);
        }
    }
    if (isset($node['orderedItems']) && is_array($node['orderedItems'])) {
        foreach ($node['orderedItems'] as $index => $item) {
            if (is_array($item)) {
                $node['orderedItems'][$index] = ap_as2_pleroma_node($item);
            }
        }
    }
    return ap_as2_pleroma_actor_aliases($node);
}

function ap_as2_pleroma_actor_type(mixed $type): bool
{
    $names = ['Person', 'Service', 'Application', 'Group', 'Organization'];
    if (is_string($type)) {
        return in_array($type, $names, true);
    }
    if (!is_array($type)) {
        return false;
    }
    foreach ($type as $item) {
        if (is_string($item) && in_array($item, $names, true)) {
            return true;
        }
    }
    return false;
}

function ap_as2_pleroma_alias_string(mixed $item): ?string
{
    if (is_string($item)) {
        return $item;
    }
    if (is_array($item) && isset($item['href']) && is_string($item['href'])) {
        return $item['href'];
    }
    return null;
}

/**
 * Keep http(s) actor aliases. Move the first at:// alias onto blueskyDid
 * and drop every other non-http value. Notes are not actors, so their
 * alsoKnownAs is unchanged. https aliases stay, in their original order.
 *
 * @param array<string,mixed> $node
 * @return array<string,mixed>
 */
function ap_as2_pleroma_actor_aliases(array $node): array
{
    if (!ap_as2_pleroma_actor_type($node['type'] ?? null) || !array_key_exists('alsoKnownAs', $node)) {
        return $node;
    }
    $raw = $node['alsoKnownAs'];
    if (is_string($raw)) {
        $values = [$raw];
    } elseif (is_array($raw) && array_is_list($raw)) {
        $values = $raw;
    } elseif (is_array($raw) && isset($raw['href'])) {
        $values = [$raw];
    } else {
        return $node;
    }
    $https = [];
    $at = [];
    foreach ($values as $item) {
        $text = ap_as2_pleroma_alias_string($item);
        if ($text === null) {
            continue;
        }
        if (str_starts_with($text, 'at://')) {
            if (!in_array($text, $at, true)) {
                $at[] = $text;
            }
            continue;
        }
        if (ap_as2_http_url($text) && !in_array($text, $https, true)) {
            $https[] = $text;
        }
    }
    $existingDid = $node['blueskyDid'] ?? null;
    if ($at !== [] && (!is_string($existingDid) || trim($existingDid) === '')) {
        $node['blueskyDid'] = $at[0];
    }
    if ($https === []) {
        unset($node['alsoKnownAs']);
    } else {
        $node['alsoKnownAs'] = array_values($https);
    }
    return $node;
}

/**
 * @param array<string,mixed> $doc
 * @return array<string,mixed>
 */
function ap_as2_pleroma_document(array $doc): array
{
    return ap_as2_pleroma_node($doc);
}

/**
 * Loose index scan for `SELECT DISTINCT actor_id ... WHERE action_taken = ?`.
 * Postgres does not skip duplicate index keys for DISTINCT. Each step is one
 * index lookup on idx_events_action_actor (action_taken, actor_id).
 */
function ap_events_action_actor_sql(): string
{
    // The seed is a subquery so ORDER BY/LIMIT stays on the first actor only.
    // Postgres rejects a bare ORDER BY before UNION ALL, and it requires the
    // subquery alias. SQLite accepts the same text. Each later step is one
    // index lookup on idx_events_action_actor once that index exists.
    return <<<'SQL'
WITH RECURSIVE found AS (
    SELECT actor_id FROM (
        SELECT actor_id
        FROM events
        WHERE action_taken = ? AND actor_id IS NOT NULL
        ORDER BY actor_id
        LIMIT 1
    ) AS seed
    UNION ALL
    SELECT (
        SELECT e.actor_id
        FROM events e
        WHERE e.action_taken = ?
          AND e.actor_id IS NOT NULL
          AND e.actor_id > found.actor_id
        ORDER BY e.actor_id
        LIMIT 1
    )
    FROM found
    WHERE found.actor_id IS NOT NULL
)
SELECT actor_id FROM found WHERE actor_id IS NOT NULL
SQL;
}

/** @return list<string> */
function ap_events_distinct_actor_ids_query(PDO $db, string $action): array
{
    $st = $db->prepare(ap_events_action_actor_sql());
    $st->execute([$action, $action]);
    $ids = [];
    foreach ($st->fetchAll() ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['actor_id'] ?? '');
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    return $ids;
}

function ap_events_actor_cache_key(string $action): string
{
    return 'vaak:events:action-actors:v1:' . $action;
}

/** @return list<string>|null */
function ap_events_actor_cache_get(string $action): ?array
{
    if ($action === '' || !class_exists('Redis') || !function_exists('ap_redis_json_get')) {
        return null;
    }
    $cached = ap_redis_json_get(ap_events_actor_cache_key($action));
    if (!is_array($cached) || !isset($cached['actors']) || !is_array($cached['actors'])) {
        return null;
    }
    $ids = [];
    foreach ($cached['actors'] as $id) {
        if (is_string($id) && $id !== '') {
            $ids[] = $id;
        }
    }
    return $ids;
}

/** @param list<string> $ids */
function ap_events_actor_cache_put(string $action, array $ids): void
{
    if ($action === '' || !class_exists('Redis') || !function_exists('ap_redis_json_set')) {
        return;
    }
    ap_redis_json_set(ap_events_actor_cache_key($action), ['actors' => array_values($ids)], 120);
}

function ap_events_distinct_actor_ids_invalidate(string $action): void
{
    $action = trim($action);
    if ($action === '' || !class_exists('Redis') || !function_exists('ap_redis_delete')) {
        return;
    }
    ap_redis_delete(ap_events_actor_cache_key($action));
}

/** @return list<string> */
function ap_events_distinct_actor_ids(string $action): array
{
    $action = trim($action);
    if ($action === '') {
        return [];
    }
    $cached = ap_events_actor_cache_get($action);
    if ($cached !== null) {
        return $cached;
    }
    $ids = ap_events_distinct_actor_ids_query(ap_db(), $action);
    ap_events_actor_cache_put($action, $ids);
    return $ids;
}
