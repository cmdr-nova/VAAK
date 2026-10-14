<?php
/**
 * Older pages for Home, Local, and Federated.
 *
 * Keyset on created_at + id (or the source's indexed timestamp). A filtered
 * row advances the cursor. The warm ranked cache is reused while the cursor
 * still falls inside it; past that cache this reads stored rows only.
 */
declare(strict_types=1);

require_once __DIR__ . '/ap-timeline-cursor.php';

/**
 * @param array{k?:string,id?:string,sort?:int} $entry
 */
function admin_tl_cursor_token_from_ranked(array $ranked, int $index): string
{
    if ($index < 0 || !isset($ranked[$index]) || !is_array($ranked[$index])) {
        return '';
    }
    $entry = $ranked[$index];
    $kind = (string) ($entry['k'] ?? '');
    $id = (string) ($entry['id'] ?? '');
    $sort = (int) ($entry['sort'] ?? 0);
    $wildcard = static function () use ($sort): string {
        if ($sort <= 0) {
            return '';
        }
        return vaak_tl_cursor_pack(['at' => gmdate('c', $sort), 'source' => '*', 'id' => '*']);
    };
    if ($kind === '' || $id === '') {
        return $wildcard();
    }
    try {
        $db = ap_db();
        if ($kind === 'event') {
            $st = $db->prepare('SELECT created_at FROM events WHERE id = ?');
            $st->execute([(int) $id]);
            $at = (string) ($st->fetchColumn() ?: '');
            if ($at !== '') {
                return vaak_tl_cursor_pack(['at' => $at, 'source' => 'event', 'id' => (string) (int) $id]);
            }
        } elseif ($kind === 'outbox') {
            $st = $db->prepare('SELECT published FROM outbox_notes WHERE id = ? OR id = ?');
            $bare = rtrim($id, '/');
            $st->execute([$bare, $bare . '/']);
            $at = (string) ($st->fetchColumn() ?: '');
            if ($at !== '') {
                return vaak_tl_cursor_pack(['at' => $at, 'source' => 'outbox', 'id' => $bare]);
            }
        } elseif ($kind === 'boost') {
            $st = $db->prepare('SELECT id, created_at FROM masto_reblogs WHERE status_id = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$id]);
            $row = $st->fetch();
            if (is_array($row) && (string) ($row['created_at'] ?? '') !== '') {
                return vaak_tl_cursor_pack([
                    'at' => (string) $row['created_at'],
                    'source' => 'boost',
                    'id' => (string) (int) ($row['id'] ?? 0),
                ]);
            }
        } elseif ($kind === 'bsky') {
            $st = $db->prepare('SELECT indexed_at FROM bsky_posts WHERE bsky_uri = ?');
            $st->execute([$id]);
            $at = (string) ($st->fetchColumn() ?: '');
            if ($at !== '') {
                return vaak_tl_cursor_pack(['at' => $at, 'source' => 'bsky', 'id' => $id]);
            }
        } elseif ($kind === 'rss') {
            $st = $db->prepare('SELECT COALESCE(published_at, ingested_at) FROM rss_items WHERE id = ?');
            $st->execute([(int) $id]);
            $at = (string) ($st->fetchColumn() ?: '');
            if ($at !== '') {
                return vaak_tl_cursor_pack(['at' => $at, 'source' => 'rss', 'id' => (string) (int) $id]);
            }
        }
    } catch (Throwable $e) {
        error_log('[timeline-older] cursor lookup: ' . $e->getMessage());
    }
    return $wildcard();
}

/**
 * Cursor for the next page after `$painted` ranked rows.
 * Inside the cache this is that row's keyset identity, so the next request
 * continues at the following cache index. Once the cache is consumed it is a
 * wildcard at the oldest cached timestamp, which does not match a cache row
 * and keysets into older stored posts. Using the last ranked slot's timestamp
 * would skip history when that slot is newer than earlier rows.
 *
 * @param list<array<string,mixed>> $ranked
 */
function admin_tl_cursor_after_paint(array $ranked, int $painted): string
{
    if ($ranked === [] || $painted < 1) {
        return '';
    }
    if ($painted >= count($ranked)) {
        return admin_tl_oldest_ranked_cursor($ranked);
    }
    return admin_tl_cursor_token_from_ranked($ranked, $painted - 1);
}

/**
 * @param list<array<string,mixed>> $ranked
 */
function admin_tl_oldest_ranked_cursor(array $ranked): string
{
    $bestIndex = -1;
    $bestSort = PHP_INT_MAX;
    foreach ($ranked as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $sort = (int) ($entry['sort'] ?? 0);
        if ($sort > 0 && $sort < $bestSort) {
            $bestSort = $sort;
            $bestIndex = (int) $index;
        }
    }
    if ($bestIndex < 0) {
        $bestIndex = count($ranked) - 1;
    }
    $token = admin_tl_cursor_token_from_ranked($ranked, $bestIndex);
    $cursor = vaak_tl_cursor_unpack($token);
    if ($cursor === null) {
        return '';
    }
    return vaak_tl_cursor_pack(['at' => $cursor['at'], 'source' => '*', 'id' => '*']);
}

/**
 * @param array{at:string,source:string,id:string} $cursor
 * @param list<array<string,mixed>> $ranked
 */
function admin_tl_ranked_index_of_cursor(array $ranked, array $cursor): int
{
    $source = (string) ($cursor['source'] ?? '');
    $id = (string) ($cursor['id'] ?? '');
    if ($source === '' || $source === '*' || $id === '' || $id === '*') {
        return -1;
    }
    $kind = match ($source) {
        'event' => 'event',
        'outbox' => 'outbox',
        'boost' => 'boost',
        'bsky' => 'bsky',
        'rss' => 'rss',
        default => '',
    };
    if ($kind === '') {
        return -1;
    }
    if ($kind === 'boost') {
        try {
            $st = ap_db()->prepare('SELECT status_id FROM masto_reblogs WHERE id = ?');
            $st->execute([(int) $id]);
            $id = (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) {
            return -1;
        }
    }
    if ($id === '') {
        return -1;
    }
    $bare = rtrim($id, '/');
    foreach ($ranked as $index => $entry) {
        if (!is_array($entry) || (string) ($entry['k'] ?? '') !== $kind) {
            continue;
        }
        $eid = (string) ($entry['id'] ?? '');
        if ($eid === $id || ($kind === 'outbox' && rtrim($eid, '/') === $bare)) {
            return (int) $index;
        }
    }
    return -1;
}

/**
 * @param list<array<string,mixed>> $ranked
 * @return array{items:list<array<string,mixed>>,has_more:bool,next_cursor:string,windows:int}
 */
function admin_tl_older_page(
    string $view,
    ?array $cursor,
    int $limit,
    int $ownerUserId,
    string $ownerActorId,
    array $ranked = []
): array {
    $view = $view === 'local' || $view === 'feed' ? $view : 'home';
    $limit = max(1, min(40, $limit));
    if ($cursor !== null && $ranked !== []) {
        $idx = admin_tl_ranked_index_of_cursor($ranked, $cursor);
        if ($idx >= 0) {
            $keys = array_values(array_slice($ranked, $idx + 1, $limit));
            if ($keys !== []) {
                $last = $idx + count($keys);
                $items = function_exists('admin_tl_hydrate') ? admin_tl_hydrate($keys, $view) : [];
                $items = admin_tl_older_finish_items($view, is_array($items) ? $items : [], $ownerUserId);
                return [
                    'items' => $items,
                    'has_more' => true,
                    'next_cursor' => admin_tl_cursor_after_paint($ranked, $last),
                    'windows' => 0,
                ];
            }
        }
    }

    $fetchLimit = max($limit * 3, 40);
    $actors = $view === 'home' ? vaak_tl_followed_actor_ids($ownerActorId) : [];
    $dids = $view === 'home' ? vaak_tl_followed_bsky_dids($ownerUserId) : [];
    $seen = [];
    $fetch = static function (?array $next) use ($view, $ownerUserId, $ownerActorId, $fetchLimit, $actors, $dids, &$seen): array {
        return vaak_tl_older_raw_window($view, $next, $fetchLimit, $ownerUserId, $ownerActorId, $actors, $dids, $seen);
    };
    $page = vaak_tl_cursor_collect($fetch, $cursor, $limit);
    $keys = [];
    foreach ($page['kept'] as $row) {
        if (is_array($row['key'] ?? null)) {
            $keys[] = $row['key'];
        }
    }
    if ($view === 'home' && $keys !== [] && function_exists('admin_home_queue_rss_after_first_page')) {
        $beforeTs = null;
        if (is_array($page['next'] ?? null) && (string) ($page['next']['at'] ?? '') !== '') {
            $parsed = strtotime((string) $page['next']['at']);
            $beforeTs = $parsed !== false ? (int) $parsed : null;
        }
        $profile = function_exists('ap_profile_get')
            ? ap_profile_get((string) ($GLOBALS['vaak_actor_key'] ?? ''))
            : [];
        $algo = !is_array($profile) || !array_key_exists('algorithm_enabled', $profile) || !empty($profile['algorithm_enabled']);
        if ($algo) {
            $keys = admin_home_queue_rss_after_first_page($keys, $ownerUserId, 0, $beforeTs, 24);
        }
    }
    $items = ($keys !== [] && function_exists('admin_tl_hydrate')) ? admin_tl_hydrate($keys, $view) : [];
    $items = admin_tl_older_finish_items($view, is_array($items) ? $items : [], $ownerUserId);
    $next = is_array($page['next'] ?? null) ? vaak_tl_cursor_pack($page['next']) : '';
    return [
        'items' => $items,
        'has_more' => !empty($page['has_more']),
        'next_cursor' => $next,
        'windows' => (int) ($page['windows'] ?? 0),
    ];
}

/**
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function admin_tl_older_finish_items(string $view, array $items, int $ownerUserId): array
{
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        if ($view === 'local' && function_exists('admin_local_timeline_item_allowed') && !admin_local_timeline_item_allowed($item)) {
            continue;
        }
        if ($view === 'feed' && function_exists('admin_federated_timeline_item_allowed') && !admin_federated_timeline_item_allowed($item)) {
            continue;
        }
        if (function_exists('admin_timeline_item_muted_by_words') && admin_timeline_item_muted_by_words($item)) {
            continue;
        }
        $out[] = $item;
    }
    if ($view !== 'home') {
        return $out;
    }
    $profile = function_exists('ap_profile_get')
        ? ap_profile_get((string) ($GLOBALS['vaak_actor_key'] ?? ''))
        : [];
    $algo = !is_array($profile) || !array_key_exists('algorithm_enabled', $profile) || !empty($profile['algorithm_enabled']);
    $down = !is_array($profile) || !array_key_exists('downranking_enabled', $profile) || !empty($profile['downranking_enabled']);
    if ($algo && $down && function_exists('admin_home_apply_temporary_toxicity_downrank')) {
        $out = admin_home_apply_temporary_toxicity_downrank($out, $ownerUserId);
    }
    if ($algo && function_exists('admin_home_apply_favourite_rank')) {
        $out = admin_home_apply_favourite_rank($out, $ownerUserId);
    }
    return $out;
}

/** @return list<string> */
function vaak_tl_followed_actor_ids(string $ownerActorId): array
{
    $actor = rtrim(trim($ownerActorId), '/');
    if ($actor === '') {
        return [];
    }
    try {
        $st = ap_db()->prepare('SELECT actor_id FROM following WHERE owner_actor_id = ? OR owner_actor_id = ?');
        $st->execute([$actor, $actor . '/']);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $id = rtrim(trim((string) (is_array($row) ? ($row['actor_id'] ?? '') : '')), '/');
            if ($id !== '' && str_starts_with($id, 'https://') && !str_contains($id, 'bsky.app/')) {
                $out[$id] = true;
            }
        }
        return array_keys($out);
    } catch (Throwable $e) {
        error_log('[timeline-older] follows: ' . $e->getMessage());
        return [];
    }
}

/** @return list<string> */
function vaak_tl_followed_bsky_dids(int $ownerUserId): array
{
    if ($ownerUserId < 1) {
        return [];
    }
    try {
        $st = ap_db()->prepare("SELECT target_did FROM bsky_graph_sync WHERE owner_user_id = ? AND kind = 'follow'");
        $st->execute([$ownerUserId]);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $did = trim((string) (is_array($row) ? ($row['target_did'] ?? '') : ''));
            if (str_starts_with($did, 'did:')) {
                $out[$did] = true;
            }
        }
        return array_keys($out);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array{at:string,source:string,id:string}|null $cursor
 * @param list<string> $actors
 * @param list<string> $dids
 * @param array<string,bool> $seen
 * @return array{rows:list<array<string,mixed>>,full:bool}
 */
function vaak_tl_older_raw_window(
    string $view,
    ?array $cursor,
    int $fetchLimit,
    int $ownerUserId,
    string $ownerActorId,
    array $actors,
    array $dids,
    array &$seen
): array {
    $parts = [];
    $anyFull = false;
    $add = static function (array $chunk) use (&$parts, &$anyFull): void {
        if (!empty($chunk['full'])) {
            $anyFull = true;
        }
        foreach ($chunk['rows'] ?? [] as $row) {
            if (is_array($row)) {
                $parts[] = $row;
            }
        }
    };
    if ($view === 'feed' || $view === 'home') {
        $add($view === 'feed'
            ? vaak_tl_older_events_firehose($cursor, $fetchLimit, $ownerUserId, $seen)
            : vaak_tl_older_events_followed($cursor, $fetchLimit, $ownerUserId, $actors, $seen));
    }
    $add(vaak_tl_older_outbox($view, $cursor, $fetchLimit, $ownerActorId, $seen));
    $add(vaak_tl_older_reblogs($view, $cursor, $fetchLimit, $ownerUserId, $seen));
    if ($view === 'home' && $dids !== []) {
        $add(vaak_tl_older_bsky($cursor, $fetchLimit, $ownerUserId, $dids, $seen));
    }
    usort($parts, static function (array $a, array $b): int {
        if (vaak_tl_is_older($a, $b)) {
            return 1;
        }
        if (vaak_tl_is_older($b, $a)) {
            return -1;
        }
        return 0;
    });
    $full = $anyFull || count($parts) > $fetchLimit;
    if (count($parts) > $fetchLimit) {
        $parts = array_slice($parts, 0, $fetchLimit);
    }
    return ['rows' => $parts, 'full' => $full];
}

/**
 * Production SQL for EXPLAIN. Placeholders are `?`.
 *
 * @return list<array{name:string,sql:string,params:list<int|string>}>
 */
function vaak_tl_older_explain_queries(string $actor, string $did, string $at, int $eventId): array
{
    $bound = ['op' => 'lt_id', 'at' => $at, 'id' => (string) $eventId];
    $bskyBound = ['op' => 'lt_id', 'at' => $at, 'id' => 'at://did:plc:example/app.bsky.feed.post/older'];
    $outboxBound = ['op' => 'lt_id', 'at' => $at, 'id' => 'https://mkultra.monster/users/alice/notes/1'];
    return [
        [
            'name' => 'events_federated_create',
            'sql' => vaak_tl_sql_events_where("type = 'Create' AND action_taken IN ('log', 'local_observe')", $bound['op']),
            'params' => array_merge(vaak_tl_sql_older_params($bound, true), [40]),
        ],
        [
            'name' => 'events_federated_quote',
            'sql' => vaak_tl_sql_events_where("type = 'Quote' AND action_taken = 'log'", $bound['op']),
            'params' => array_merge(vaak_tl_sql_older_params($bound, true), [40]),
        ],
        [
            'name' => 'events_home_actor',
            'sql' => vaak_tl_sql_events_lateral("type IN ('Create', 'Announce') AND action_taken IN ('log', 'local_observe')", 1, $bound['op']),
            'params' => array_merge([$actor], vaak_tl_sql_older_params($bound, true), [40]),
        ],
        [
            'name' => 'events_home_quote',
            'sql' => vaak_tl_sql_events_lateral("type = 'Quote' AND action_taken = 'log'", 1, $bound['op']),
            'params' => array_merge([$actor], vaak_tl_sql_older_params($bound, true), [40]),
        ],
        [
            'name' => 'bsky_author',
            'sql' => vaak_tl_sql_bsky_lateral(1, $bskyBound['op']),
            'params' => array_merge([$did], vaak_tl_sql_older_params($bskyBound, false), [40]),
        ],
        [
            'name' => 'outbox_published',
            'sql' => vaak_tl_sql_outbox($outboxBound['op']),
            'params' => array_merge(vaak_tl_sql_older_params($outboxBound, false), [40]),
        ],
        [
            'name' => 'reblogs_owner',
            'sql' => vaak_tl_sql_reblogs_owner($bound['op']),
            'params' => array_merge([1], vaak_tl_sql_older_params($bound, true), [40]),
        ],
        [
            'name' => 'reblogs_local_users',
            'sql' => vaak_tl_sql_reblogs_local($bound['op']),
            'params' => array_merge(vaak_tl_sql_older_params($bound, true), [40]),
        ],
    ];
}

function vaak_tl_sql_events_where(string $where, string $op): string
{
    $pred = $op === 'none' ? '' : ' AND ' . vaak_tl_sql_older_predicate($op, 'created_at', 'id');
    return 'SELECT id, created_at, type, actor_id, object_id, summary, media_urls, action_taken, spoiler_text, sensitive, host, in_reply_to, target_actor
            FROM events WHERE ' . $where . $pred . ' ORDER BY created_at DESC, id DESC LIMIT ?';
}

function vaak_tl_sql_events_lateral(string $typeSql, int $actors, string $op): string
{
    $actors = max(1, $actors);
    $values = implode(',', array_fill(0, $actors, '(CAST(? AS text))'));
    $pred = $op === 'none' ? '' : ' AND ' . vaak_tl_sql_older_predicate($op, 'created_at', 'id');
    return "SELECT e.id, e.created_at, e.type, e.actor_id, e.object_id, e.summary, e.media_urls, e.action_taken, e.spoiler_text, e.sensitive, e.host, e.in_reply_to, e.target_actor
            FROM (VALUES $values) AS f(actor_id)
            JOIN LATERAL (
              SELECT id, created_at, type, actor_id, object_id, summary, media_urls, action_taken, spoiler_text, sensitive, host, in_reply_to, target_actor
              FROM events
              WHERE actor_id = f.actor_id AND $typeSql$pred
              ORDER BY created_at DESC, id DESC
              LIMIT ?
            ) e ON true";
}

function vaak_tl_sql_bsky_lateral(int $dids, string $op): string
{
    $dids = max(1, $dids);
    $values = implode(',', array_fill(0, $dids, '(CAST(? AS text))'));
    $pred = $op === 'none' ? '' : ' AND ' . vaak_tl_sql_older_predicate($op, 'indexed_at', 'bsky_uri');
    return "SELECT p.bsky_uri, p.indexed_at, p.author_did
            FROM (VALUES $values) AS g(target_did)
            JOIN LATERAL (
              SELECT bsky_uri, indexed_at, author_did
              FROM bsky_posts
              WHERE author_did = g.target_did AND text IS NOT NULL$pred
              ORDER BY indexed_at DESC, bsky_uri DESC
              LIMIT ?
            ) p ON true";
}

function vaak_tl_sql_outbox(string $op): string
{
    $pred = $op === 'none' ? 'TRUE' : vaak_tl_sql_older_predicate($op, 'published', 'id');
    return "SELECT id, published, content, in_reply_to, raw_create_json FROM outbox_notes WHERE $pred ORDER BY published DESC, id DESC LIMIT ?";
}

function vaak_tl_sql_reblogs_owner(string $op): string
{
    $pred = $op === 'none' ? '' : ' AND ' . vaak_tl_sql_older_predicate($op, 'created_at', 'id');
    return "SELECT id, status_id, created_at, owner_actor_id, object_id, owner_user_id
            FROM masto_reblogs WHERE owner_user_id = ?$pred ORDER BY created_at DESC, id DESC LIMIT ?";
}

function vaak_tl_sql_reblogs_local(string $op): string
{
    $pred = $op === 'none' ? '' : ' AND ' . vaak_tl_sql_older_predicate($op, 'created_at', 'id');
    return "SELECT r.id, r.status_id, r.created_at, r.owner_actor_id, r.object_id
            FROM ap_users u
            JOIN LATERAL (
              SELECT id, status_id, created_at, owner_actor_id, object_id
              FROM masto_reblogs
              WHERE owner_user_id = u.id$pred
              ORDER BY created_at DESC, id DESC
              LIMIT ?
            ) r ON true";
}

/**
 * @param array{at:string,source:string,id:string}|null $cursor
 * @param array<string,bool> $seen
 * @return array{rows:list<array<string,mixed>>,full:bool}
 */
function vaak_tl_older_events_firehose(?array $cursor, int $fetchLimit, int $ownerUserId, array &$seen): array
{
    $bound = vaak_tl_source_bound($cursor, 'event');
    $rows = [];
    $full = false;
    foreach ([
        "type = 'Create' AND action_taken IN ('log', 'local_observe')",
        "type = 'Quote' AND action_taken = 'log'",
        "type = 'Quote' AND action_taken = 'local_observe'",
        "type = 'QuotePost' AND action_taken = 'log'",
        "type = 'QuotePost' AND action_taken = 'local_observe'",
    ] as $where) {
        $sql = vaak_tl_sql_events_where($where, $bound['op']);
        $params = array_merge(vaak_tl_sql_older_params($bound, true), [$fetchLimit]);
        $chunk = vaak_tl_older_query($sql, $params);
        if (count($chunk) >= $fetchLimit) {
            $full = true;
        }
        foreach ($chunk as $erow) {
            $mapped = vaak_tl_older_map_event($erow, true, $ownerUserId, $seen);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }
    }
    return ['rows' => $rows, 'full' => $full];
}

/**
 * @param list<string> $actors
 * @param array<string,bool> $seen
 * @return array{rows:list<array<string,mixed>>,full:bool}
 */
function vaak_tl_older_events_followed(?array $cursor, int $fetchLimit, int $ownerUserId, array $actors, array &$seen): array
{
    if ($actors === []) {
        return ['rows' => [], 'full' => false];
    }
    $bound = vaak_tl_source_bound($cursor, 'event');
    $rows = [];
    $full = false;
    foreach ([
        "type IN ('Create', 'Announce') AND action_taken IN ('log', 'local_observe')",
        "type = 'Quote' AND action_taken = 'log'",
        "type = 'Quote' AND action_taken = 'local_observe'",
        "type = 'QuotePost' AND action_taken = 'log'",
        "type = 'QuotePost' AND action_taken = 'local_observe'",
    ] as $typeSql) {
        foreach (array_chunk($actors, 80) as $chunkActors) {
            $sql = vaak_tl_sql_events_lateral($typeSql, count($chunkActors), $bound['op']);
            $params = array_merge($chunkActors, vaak_tl_sql_older_params($bound, true), [$fetchLimit]);
            $chunk = vaak_tl_older_query($sql, $params);
            if (count($chunk) >= $fetchLimit) {
                $full = true;
            }
            foreach ($chunk as $erow) {
                $mapped = vaak_tl_older_map_event($erow, false, $ownerUserId, $seen);
                if ($mapped !== null) {
                    $rows[] = $mapped;
                }
            }
        }
    }
    return ['rows' => $rows, 'full' => $full];
}

/**
 * @param array<string,mixed> $erow
 * @param array<string,bool> $seen
 * @return array<string,mixed>|null
 */
function vaak_tl_older_map_event(array $erow, bool $feed, int $ownerUserId, array &$seen): ?array
{
    $id = (int) ($erow['id'] ?? 0);
    $at = (string) ($erow['created_at'] ?? '');
    if ($id < 1 || $at === '') {
        return null;
    }
    $sid = (string) $id;
    $keep = true;
    $type = strtolower((string) ($erow['type'] ?? ''));
    $summary = trim(html_entity_decode(strip_tags((string) ($erow['summary'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $media = trim((string) ($erow['media_urls'] ?? ''));
    $spoiler = trim((string) ($erow['spoiler_text'] ?? ''));
    $sensitive = (int) ($erow['sensitive'] ?? 0) !== 0;
    if ($type !== 'announce' && $summary === '' && ($media === '' || $media === '[]') && $spoiler === '' && !$sensitive) {
        $keep = false;
    }
    $item = ['kind' => 'event', 'sort' => 0, 'row' => $erow];
    if ($feed && function_exists('admin_federated_timeline_item_allowed') && !admin_federated_timeline_item_allowed($item)) {
        $keep = false;
    }
    if ($keep && function_exists('admin_timeline_row_hidden') && admin_timeline_row_hidden($erow, $ownerUserId)) {
        $keep = false;
    }
    if ($keep && function_exists('ap_row_matches_muted_words') && ap_row_matches_muted_words($erow, 'event', [], $ownerUserId)) {
        $keep = false;
    }
    $dedupe = 'event:' . $sid;
    if (isset($seen[$dedupe])) {
        $keep = false;
    }
    $seen[$dedupe] = true;
    return [
        'at' => $at,
        'source' => 'event',
        'id' => $sid,
        'keep' => $keep,
        'key' => ['k' => 'event', 'id' => $sid, 's' => $feed ? 'fediverse' : 'following'],
    ];
}

/**
 * @param array{at:string,source:string,id:string}|null $cursor
 * @param array<string,bool> $seen
 * @return array{rows:list<array<string,mixed>>,full:bool}
 */
function vaak_tl_older_outbox(string $view, ?array $cursor, int $fetchLimit, string $ownerActorId, array &$seen): array
{
    $bound = vaak_tl_source_bound($cursor, 'outbox');
    $sql = vaak_tl_sql_outbox($bound['op']);
    $params = array_merge(vaak_tl_sql_older_params($bound, false), [$fetchLimit]);
    $found = vaak_tl_older_query($sql, $params);
    $owner = rtrim(trim($ownerActorId), '/');
    $rows = [];
    foreach ($found as $nrow) {
        $id = rtrim((string) ($nrow['id'] ?? ''), '/');
        $at = (string) ($nrow['published'] ?? '');
        if ($id === '' || $at === '') {
            continue;
        }
        $local = str_starts_with($id, 'https://mkultra.monster/users/') && str_contains($id, '/notes/');
        $own = $owner !== '' && ($id === $owner || str_starts_with($id, $owner . '/'));
        $keep = $local;
        if (function_exists('admin_outbox_is_bsky_import') && admin_outbox_is_bsky_import($nrow)) {
            $keep = false;
        }
        if ($view !== 'local') {
            $keep = $keep && $own;
        }
        $item = ['kind' => 'outbox', 'row' => $nrow];
        if ($keep && $view === 'local' && function_exists('admin_local_timeline_item_allowed') && !admin_local_timeline_item_allowed($item)) {
            $keep = false;
        }
        if ($keep && $view === 'feed' && function_exists('admin_federated_timeline_item_allowed') && !admin_federated_timeline_item_allowed($item)) {
            $keep = false;
        }
        if (isset($seen['outbox:' . $id])) {
            $keep = false;
        }
        $seen['outbox:' . $id] = true;
        $rows[] = [
            'at' => $at,
            'source' => 'outbox',
            'id' => $id,
            'keep' => $keep,
            'key' => ['k' => 'outbox', 'id' => $id, 's' => 'local'],
        ];
    }
    return ['rows' => $rows, 'full' => count($found) >= $fetchLimit];
}

/**
 * @param array{at:string,source:string,id:string}|null $cursor
 * @param array<string,bool> $seen
 * @return array{rows:list<array<string,mixed>>,full:bool}
 */
function vaak_tl_older_reblogs(string $view, ?array $cursor, int $fetchLimit, int $ownerUserId, array &$seen): array
{
    $bound = vaak_tl_source_bound($cursor, 'boost');
    if ($view === 'local') {
        $sql = vaak_tl_sql_reblogs_local($bound['op']);
        $params = array_merge(vaak_tl_sql_older_params($bound, true), [$fetchLimit]);
    } else {
        if ($ownerUserId < 1) {
            return ['rows' => [], 'full' => false];
        }
        $sql = vaak_tl_sql_reblogs_owner($bound['op']);
        $params = array_merge([$ownerUserId], vaak_tl_sql_older_params($bound, true), [$fetchLimit]);
    }
    $found = vaak_tl_older_query($sql, $params);
    $rows = [];
    foreach ($found as $rb) {
        $pk = (int) ($rb['id'] ?? 0);
        $at = (string) ($rb['created_at'] ?? '');
        $statusId = (string) ($rb['status_id'] ?? '');
        if ($pk < 1 || $at === '' || $statusId === '') {
            continue;
        }
        $keep = true;
        if (function_exists('admin_reblog_is_bsky') && admin_reblog_is_bsky($rb)) {
            $keep = false;
        }
        if ($keep && $view === 'feed' && function_exists('admin_reblog_is_rss') && admin_reblog_is_rss($rb)) {
            $keep = false;
        }
        $item = ['kind' => 'boost', 'row' => $rb];
        if ($keep && $view === 'local' && function_exists('admin_local_timeline_item_allowed') && !admin_local_timeline_item_allowed($item)) {
            $keep = false;
        }
        $sid = (string) $pk;
        if (isset($seen['boost:' . $sid])) {
            $keep = false;
        }
        $seen['boost:' . $sid] = true;
        $rows[] = [
            'at' => $at,
            'source' => 'boost',
            'id' => $sid,
            'keep' => $keep,
            'key' => ['k' => 'boost', 'id' => $statusId, 's' => 'boost'],
        ];
    }
    return ['rows' => $rows, 'full' => count($found) >= $fetchLimit];
}

/**
 * @param list<string> $dids
 * @param array<string,bool> $seen
 * @return array{rows:list<array<string,mixed>>,full:bool}
 */
function vaak_tl_older_bsky(?array $cursor, int $fetchLimit, int $ownerUserId, array $dids, array &$seen): array
{
    $bound = vaak_tl_source_bound($cursor, 'bsky');
    $rows = [];
    $full = false;
    foreach (array_chunk($dids, 80) as $chunk) {
        $sql = vaak_tl_sql_bsky_lateral(count($chunk), $bound['op']);
        $params = array_merge($chunk, vaak_tl_sql_older_params($bound, false), [$fetchLimit]);
        $found = vaak_tl_older_query($sql, $params);
        if (count($found) >= $fetchLimit) {
            $full = true;
        }
        foreach ($found as $brow) {
            $uri = (string) ($brow['bsky_uri'] ?? '');
            $at = (string) ($brow['indexed_at'] ?? '');
            $did = (string) ($brow['author_did'] ?? '');
            if ($uri === '' || $at === '') {
                continue;
            }
            $keep = true;
            if ($did !== '' && function_exists('ap_bsky_filter_hidden_authors')) {
                $filtered = ap_bsky_filter_hidden_authors($ownerUserId, [['post' => ['author' => ['did' => $did]]]]);
                if ($filtered === []) {
                    $keep = false;
                }
            }
            if (isset($seen['bsky:' . $uri])) {
                $keep = false;
            }
            $seen['bsky:' . $uri] = true;
            $rows[] = [
                'at' => $at,
                'source' => 'bsky',
                'id' => $uri,
                'keep' => $keep,
                'key' => ['k' => 'bsky', 'id' => $uri, 's' => 'bluesky'],
            ];
        }
    }
    return ['rows' => $rows, 'full' => $full];
}

/**
 * @param array{items:list<array<string,mixed>>,has_more:bool,next_cursor:string} $page
 * @param array<string,mixed> $followingIds
 */
function admin_tl_echo_older_partial(string $view, array $page, array $followingIds, int $nextOffset): void
{
    if (function_exists('ap_auth_session_write_close')) {
        ap_auth_session_write_close();
    } elseif (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $items = is_array($page['items'] ?? null) ? $page['items'] : [];
    if (function_exists('ap_masto_status_flags_prefetch') && function_exists('admin_timeline_status_ids')) {
        ap_masto_status_flags_prefetch(admin_timeline_status_ids($items));
    }
    ob_start();
    foreach ($items as $item) {
        if (!is_array($item) || !function_exists('admin_render_timeline_item')) {
            continue;
        }
        admin_render_timeline_item($item, $followingIds, $view);
    }
    $html = (string) ob_get_clean();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Has-More: ' . (!empty($page['has_more']) ? '1' : '0'));
    header('X-Next-Offset: ' . max(0, $nextOffset));
    $cursor = (string) ($page['next_cursor'] ?? '');
    if ($cursor !== '') {
        header('X-Next-Cursor: ' . $cursor);
    }
    header('X-TL-Cache: older-keyset');
    header('X-VAAK-View: ' . $view);
    echo $html;
}

/**
 * @param list<int|string> $params
 * @return list<array<string,mixed>>
 */
function vaak_tl_older_query(string $sql, array $params): array
{
    try {
        $st = ap_db()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[timeline-older] query: ' . $e->getMessage());
        return [];
    }
}
