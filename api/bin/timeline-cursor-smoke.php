<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ap-timeline-cursor.php';

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$cursor = vaak_tl_cursor_unpack('v1|2026-01-02T00:00:00Z|event|10');
if ($cursor === null) {
    fail('cursor unpack failed');
}
if (vaak_tl_is_older(['at' => '2026-01-02T00:00:00Z', 'source' => 'event', 'id' => '10'], $cursor)) {
    fail('cursor row must be excluded');
}
if (!vaak_tl_is_older(['at' => '2026-01-02T00:00:00Z', 'source' => 'event', 'id' => '9'], $cursor)) {
    fail('lower id at the same timestamp must be older');
}
if (vaak_tl_is_older(['at' => '2026-01-02T00:00:00Z', 'source' => 'event', 'id' => '11'], $cursor)) {
    fail('higher id at the same timestamp is newer');
}
if (!vaak_tl_is_older(['at' => '2026-01-01T00:00:00Z', 'source' => 'event', 'id' => '99'], $cursor)) {
    fail('earlier timestamp must be older');
}
$packed = vaak_tl_cursor_pack($cursor);
if ($packed !== 'v1|2026-01-02T00:00:00Z|event|10') {
    fail('pack roundtrip failed: ' . $packed);
}

$rows = [
    ['at' => '2026-01-02T00:00:00Z', 'source' => 'event', 'id' => '10', 'keep' => true],
    ['at' => '2026-01-02T00:00:00Z', 'source' => 'event', 'id' => '9', 'keep' => true],
    ['at' => '2026-01-02T00:00:00Z', 'source' => 'event', 'id' => '8', 'keep' => true],
];
$first = vaak_tl_cursor_collect(vaak_tl_memory_fetcher($rows, 10), null, 1);
if (($first['kept'][0]['id'] ?? '') !== '10' || empty($first['has_more'])) {
    fail('tie page did not keep the highest id');
}
$second = vaak_tl_cursor_collect(vaak_tl_memory_fetcher($rows, 10), $first['next'], 1);
if (($second['kept'][0]['id'] ?? '') !== '9') {
    fail('next page did not keep the tied lower id');
}

$filtered = [
    ['at' => '2026-01-05T00:00:00Z', 'source' => 'event', 'id' => '5', 'keep' => false],
    ['at' => '2026-01-04T00:00:00Z', 'source' => 'event', 'id' => '4', 'keep' => false],
    ['at' => '2026-01-03T00:00:00Z', 'source' => 'event', 'id' => '3', 'keep' => false],
    ['at' => '2026-01-02T00:00:00Z', 'source' => 'event', 'id' => '2', 'keep' => true],
];
$page = vaak_tl_cursor_collect(vaak_tl_memory_fetcher($filtered, 2), null, 1);
if (($page['kept'][0]['id'] ?? '') !== '2' || (int) ($page['windows'] ?? 0) < 2) {
    fail('filtered windows were treated as the end');
}

$capped = vaak_tl_cursor_collect(vaak_tl_memory_fetcher($filtered, 2), null, 1, 1);
if ($capped['kept'] !== [] || empty($capped['has_more'])) {
    fail('a full filtered window must not declare the end');
}

$bound = vaak_tl_source_bound($cursor, 'event');
if (($bound['op'] ?? '') !== 'lt_id') {
    fail('same-source bound must compare id');
}
$olderSource = vaak_tl_source_bound($cursor, 'bsky');
if (($olderSource['op'] ?? '') !== 'lte') {
    fail('an older source includes the tied timestamp');
}

echo "timeline-cursor-smoke ok\n";
