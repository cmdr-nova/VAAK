<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/ap-timeline-cursor.php';

$tieRows = [
    ['at' => '2026-01-02T00:00:00Z', 'source' => 'event', 'id' => '10', 'keep' => true],
    ['at' => '2026-01-02T00:00:00Z', 'source' => 'event', 'id' => '9', 'keep' => true],
    ['at' => '2026-01-02T00:00:00Z', 'source' => 'outbox', 'id' => 'https://mkultra.monster/users/a/notes/1', 'keep' => true],
    ['at' => '2026-01-02T00:00:00Z', 'source' => 'bsky', 'id' => 'at://did:plc:a/app.bsky.feed.post/a', 'keep' => true],
];
$filteredRows = [
    ['at' => '2026-01-05T00:00:00Z', 'source' => 'event', 'id' => '5', 'keep' => false],
    ['at' => '2026-01-04T00:00:00Z', 'source' => 'event', 'id' => '4', 'keep' => false],
    ['at' => '2026-01-03T00:00:00Z', 'source' => 'event', 'id' => '3', 'keep' => false],
    ['at' => '2026-01-02T00:00:00Z', 'source' => 'event', 'id' => '2', 'keep' => true],
];

$inputs = [
    ['id' => 'pack', 'input' => ['op' => 'pack', 'cursor' => ['at' => '2026-01-02T00:00:00Z', 'source' => 'event', 'id' => '10']]],
    ['id' => 'pack-empty', 'input' => ['op' => 'pack', 'cursor' => null]],
    ['id' => 'unpack', 'input' => ['op' => 'unpack', 'token' => 'v1|2026-01-02T00:00:00Z|outbox|https://mkultra.monster/users/a/notes/1']],
    ['id' => 'unpack-bad', 'input' => ['op' => 'unpack', 'token' => 'nope']],
    ['id' => 'boundary', 'input' => ['op' => 'older', 'cursor' => 'v1|2026-01-02T00:00:00Z|event|10', 'row' => ['at' => '2026-01-02T00:00:00Z', 'source' => 'event', 'id' => '10']]],
    ['id' => 'lower-id', 'input' => ['op' => 'older', 'cursor' => 'v1|2026-01-02T00:00:00Z|event|10', 'row' => ['at' => '2026-01-02T00:00:00Z', 'source' => 'event', 'id' => '9']]],
    ['id' => 'higher-id', 'input' => ['op' => 'older', 'cursor' => 'v1|2026-01-02T00:00:00Z|event|10', 'row' => ['at' => '2026-01-02T00:00:00Z', 'source' => 'event', 'id' => '11']]],
    ['id' => 'earlier', 'input' => ['op' => 'older', 'cursor' => 'v1|2026-01-02T00:00:00Z|event|10', 'row' => ['at' => '2026-01-01T00:00:00Z', 'source' => 'event', 'id' => '99']]],
    ['id' => 'source-tie', 'input' => ['op' => 'older', 'cursor' => 'v1|2026-01-02T00:00:00Z|event|10', 'row' => ['at' => '2026-01-02T00:00:00Z', 'source' => 'bsky', 'id' => 'at://x']]],
    ['id' => 'bound-same', 'input' => ['op' => 'bound', 'cursor' => 'v1|2026-01-02T00:00:00Z|event|10', 'source' => 'event']],
    ['id' => 'bound-older-source', 'input' => ['op' => 'bound', 'cursor' => 'v1|2026-01-02T00:00:00Z|event|10', 'source' => 'bsky']],
    ['id' => 'bound-newer-source', 'input' => ['op' => 'bound', 'cursor' => 'v1|2026-01-02T00:00:00Z|bsky|at://x', 'source' => 'event']],
    ['id' => 'bound-wildcard', 'input' => ['op' => 'bound', 'cursor' => 'v1|2026-01-02T00:00:00Z|*|*', 'source' => 'event']],
    ['id' => 'ties', 'input' => ['op' => 'collect', 'limit' => 2, 'fetch' => 10, 'max_windows' => 5, 'rows' => $tieRows]],
    ['id' => 'filtered-empty', 'input' => ['op' => 'collect', 'limit' => 1, 'fetch' => 2, 'max_windows' => 5, 'rows' => $filteredRows]],
    ['id' => 'filtered-cap', 'input' => ['op' => 'collect', 'limit' => 1, 'fetch' => 2, 'max_windows' => 1, 'rows' => $filteredRows]],
];

$cases = [];
foreach ($inputs as $sample) {
    $input = $sample['input'];
    $op = (string) ($input['op'] ?? '');
    $output = match ($op) {
        'pack' => vaak_tl_cursor_pack(is_array($input['cursor'] ?? null) ? $input['cursor'] : null),
        'unpack' => vaak_tl_cursor_unpack((string) ($input['token'] ?? '')),
        'older' => vaak_tl_is_older(
            is_array($input['row'] ?? null) ? $input['row'] : [],
            vaak_tl_cursor_unpack((string) ($input['cursor'] ?? ''))
        ),
        'bound' => vaak_tl_source_bound(
            vaak_tl_cursor_unpack((string) ($input['cursor'] ?? '')),
            (string) ($input['source'] ?? '')
        ),
        'collect' => (static function (array $input): array {
            $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
            $page = vaak_tl_cursor_collect(
                vaak_tl_memory_fetcher($rows, (int) ($input['fetch'] ?? 10)),
                null,
                (int) ($input['limit'] ?? 1),
                (int) ($input['max_windows'] ?? 5)
            );
            return [
                'ids' => array_map(static fn(array $row): string => (string) ($row['id'] ?? ''), $page['kept']),
                'has_more' => (bool) $page['has_more'],
                'windows' => (int) $page['windows'],
                'next' => vaak_tl_cursor_pack(is_array($page['next'] ?? null) ? $page['next'] : null),
            ];
        })($input),
        default => null,
    };
    $cases[] = ['id' => $sample['id'], 'input' => $input, 'output' => $output];
}

echo json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
