<?php
/**
 * Keyset cursors for VAAK web timelines (Home, Local, Federated).
 *
 * Older pages are (created_at, source, id), not OFFSET. A row is strictly
 * older than the cursor when its timestamp is earlier, or the timestamp ties
 * and the source/id order says it comes next. The cursor row itself is not
 * older. A filtered window (blocks, mutes, empty stubs, dedup) advances the
 * cursor and is not the end of stored posts.
 *
 * No database. ap-timeline-older.php turns these bounds into indexed SQL.
 */
declare(strict_types=1);

/** How many raw windows one scroll request may scan before returning. */
function vaak_tl_older_max_windows(): int
{
    return 5;
}

/**
 * Newer-first tie order when created_at matches. Lower rank is newer.
 * `*` is a handoff cursor that still includes every row at that timestamp.
 */
function vaak_tl_source_rank(string $source): int
{
    return match ($source) {
        '*' => -1,
        'event' => 0,
        'outbox' => 1,
        'boost' => 2,
        'bsky' => 3,
        'rss' => 4,
        default => 9,
    };
}

function vaak_tl_source_id_is_numeric(string $source): bool
{
    return in_array($source, ['event', 'boost', 'rss'], true);
}

function vaak_tl_id_less(string $source, string $left, string $right): bool
{
    if ($right === '*') {
        return $left !== '*';
    }
    if (vaak_tl_source_id_is_numeric($source)) {
        return ((int) $left) < ((int) $right);
    }
    return strcmp($left, $right) < 0;
}

/**
 * @param array{at?:string,source?:string,id?:string}|null $cursor
 */
function vaak_tl_cursor_pack(?array $cursor): string
{
    if ($cursor === null) {
        return '';
    }
    $at = str_replace(["\r", "\n", '|'], '', (string) ($cursor['at'] ?? ''));
    $source = str_replace(["\r", "\n", '|'], '', (string) ($cursor['source'] ?? ''));
    $id = str_replace(["\r", "\n"], '', (string) ($cursor['id'] ?? ''));
    if ($at === '' || $source === '' || $id === '') {
        return '';
    }
    return 'v1|' . $at . '|' . $source . '|' . $id;
}

/**
 * @return array{at:string,source:string,id:string}|null
 */
function vaak_tl_cursor_unpack(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || !str_starts_with($token, 'v1|')) {
        return null;
    }
    $parts = explode('|', $token, 4);
    if (count($parts) !== 4) {
        return null;
    }
    [, $at, $source, $id] = $parts;
    if ($at === '' || $source === '' || $id === '') {
        return null;
    }
    return ['at' => $at, 'source' => $source, 'id' => $id];
}

/**
 * True when $row sorts strictly after $cursor in newest-first order.
 * A null cursor means "the head": every row is older than the start.
 *
 * @param array{at?:string,source?:string,id?:string} $row
 * @param array{at?:string,source?:string,id?:string}|null $cursor
 */
function vaak_tl_is_older(array $row, ?array $cursor): bool
{
    if ($cursor === null) {
        return true;
    }
    $at = (string) ($row['at'] ?? '');
    $cat = (string) ($cursor['at'] ?? '');
    if ($at === '' || $cat === '') {
        return false;
    }
    if ($at < $cat) {
        return true;
    }
    if ($at > $cat) {
        return false;
    }
    $csource = (string) ($cursor['source'] ?? '');
    $cid = (string) ($cursor['id'] ?? '');
    if ($csource === '*' || $cid === '*') {
        return true;
    }
    $rs = vaak_tl_source_rank((string) ($row['source'] ?? ''));
    $cs = vaak_tl_source_rank($csource);
    if ($rs > $cs) {
        return true;
    }
    if ($rs < $cs) {
        return false;
    }
    return vaak_tl_id_less($csource, (string) ($row['id'] ?? ''), $cid);
}

/**
 * SQL bound for one source table. `op` is none|lt|lte|lt_id.
 *
 * @param array{at:string,source:string,id:string}|null $cursor
 * @return array{op:string,at:string,id:string}
 */
function vaak_tl_source_bound(?array $cursor, string $source): array
{
    if ($cursor === null) {
        return ['op' => 'none', 'at' => '', 'id' => ''];
    }
    if (($cursor['source'] ?? '') === '*' || ($cursor['id'] ?? '') === '*') {
        return ['op' => 'lte', 'at' => (string) $cursor['at'], 'id' => ''];
    }
    if ($source === (string) ($cursor['source'] ?? '')) {
        return [
            'op' => 'lt_id',
            'at' => (string) $cursor['at'],
            'id' => (string) $cursor['id'],
        ];
    }
    $rs = vaak_tl_source_rank($source);
    $cs = vaak_tl_source_rank((string) ($cursor['source'] ?? ''));
    if ($rs > $cs) {
        return ['op' => 'lte', 'at' => (string) $cursor['at'], 'id' => ''];
    }
    return ['op' => 'lt', 'at' => (string) $cursor['at'], 'id' => ''];
}

/**
 * Column predicate matching vaak_tl_is_older for this source.
 * $atColumn / $idColumn are already-quoted identifiers.
 */
function vaak_tl_sql_older_predicate(string $op, string $atColumn, string $idColumn): string
{
    // Row comparison is the same order as `created_at < ? OR (created_at = ? AND id < ?)`,
    // and Postgres can seek a (created_at DESC, id DESC) index with it. The OR form
    // is applied as a filter, so a deep cursor walks every newer row.
    return match ($op) {
        'lt' => $atColumn . ' < ?',
        'lte' => $atColumn . ' <= ?',
        'lt_id' => '(' . $atColumn . ', ' . $idColumn . ') < (?, ?)',
        default => '1=1',
    };
}

/**
 * @param array{op:string,at:string,id:string} $bound
 * @return list<int|string>
 */
function vaak_tl_sql_older_params(array $bound, bool $numericId): array
{
    $at = (string) ($bound['at'] ?? '');
    return match ((string) ($bound['op'] ?? 'none')) {
        'lt', 'lte' => [$at],
        'lt_id' => [$at, $numericId ? (int) ($bound['id'] ?? 0) : (string) ($bound['id'] ?? '')],
        default => [],
    };
}

/**
 * Walk raw windows until $limit visible rows or the stored history ends.
 * The fetcher returns the next newest-first window strictly older than the
 * cursor it is given: ['rows' => list, 'full' => bool]. `full` means the SQL
 * LIMIT was hit, so older stored rows may still exist. Rows with keep=false
 * (blocks, mutes, CWs/empty stubs, dedup) advance the cursor and do not end
 * the timeline. At most vaak_tl_older_max_windows() windows are read.
 *
 * @param callable(array{at:string,source:string,id:string}|null): array{rows:list<array<string,mixed>>,full:bool} $fetch
 * @param array{at:string,source:string,id:string}|null $cursor
 * @return array{kept:list<array<string,mixed>>,has_more:bool,next:array{at:string,source:string,id:string}|null,windows:int}
 */
function vaak_tl_cursor_collect(callable $fetch, ?array $cursor, int $limit, ?int $maxWindows = null): array
{
    $limit = max(1, $limit);
    $maxWindows = max(1, $maxWindows ?? vaak_tl_older_max_windows());
    $kept = [];
    $windows = 0;
    $hasMore = false;
    $next = $cursor;
    $exhausted = false;
    while ($windows < $maxWindows && count($kept) < $limit) {
        $windows++;
        $page = $fetch($next);
        $rows = is_array($page['rows'] ?? null) ? $page['rows'] : [];
        if ($rows === []) {
            $exhausted = true;
            $hasMore = false;
            break;
        }
        $advanced = false;
        $consumed = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || !vaak_tl_is_older($row, $next)) {
                continue;
            }
            $next = [
                'at' => (string) ($row['at'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
                'id' => (string) ($row['id'] ?? ''),
            ];
            $advanced = true;
            $consumed++;
            if (!empty($row['keep']) && count($kept) < $limit) {
                $kept[] = $row;
            }
            if (count($kept) >= $limit) {
                break;
            }
        }
        if (!$advanced) {
            $exhausted = true;
            $hasMore = false;
            break;
        }
        $full = !empty($page['full']);
        if (count($kept) >= $limit) {
            $hasMore = $consumed < count($rows) || $full;
            break;
        }
        if (!$full) {
            $exhausted = true;
            $hasMore = false;
            break;
        }
    }
    if (!$exhausted && count($kept) < $limit) {
        $hasMore = true;
    }
    return [
        'kept' => $kept,
        'has_more' => $hasMore,
        'next' => $next,
        'windows' => $windows,
    ];
}

/**
 * In-memory window fetch used by parity tests. Production SQL applies the
 * same bound via vaak_tl_source_bound / vaak_tl_is_older.
 *
 * @param list<array<string,mixed>> $rows
 * @return callable(array{at:string,source:string,id:string}|null): array{rows:list<array<string,mixed>>,full:bool}
 */
function vaak_tl_memory_fetcher(array $rows, int $fetchLimit): callable
{
    $fetchLimit = max(1, $fetchLimit);
    return static function (?array $cursor) use ($rows, $fetchLimit): array {
        $older = [];
        foreach ($rows as $row) {
            if (is_array($row) && vaak_tl_is_older($row, $cursor)) {
                $older[] = $row;
            }
        }
        usort($older, static function (array $a, array $b): int {
            if (vaak_tl_is_older($a, $b)) {
                return 1;
            }
            if (vaak_tl_is_older($b, $a)) {
                return -1;
            }
            return 0;
        });
        $full = count($older) > $fetchLimit;
        if ($full) {
            $older = array_slice($older, 0, $fetchLimit);
        }
        return ['rows' => $older, 'full' => $full];
    };
}
