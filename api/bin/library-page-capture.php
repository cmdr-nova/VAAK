<?php
declare(strict_types=1);
/** Library page windows for step 5. No network and no Postgres.
 *  Real page, cursor, and Link functions run against in-memory SQLite. */
if (!extension_loaded('mbstring') || !extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Library page capture requires mbstring and pdo_sqlite\n");
    exit(2);
}
require_once __DIR__ . '/migration-reference-functions.php';

$fixtureDb = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$fixtureDb->exec('CREATE TABLE library_page_keys(kind TEXT, ts TEXT, ref TEXT)');
$fixtureDb->exec('CREATE TABLE masto_favourites(id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER, status_id TEXT, object_id TEXT, created_at TEXT)');
$fixtureDb->exec('CREATE TABLE masto_bookmarks(id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER, status_id TEXT, object_id TEXT, created_at TEXT)');

function ap_db(): PDO { return $GLOBALS['fixtureDb']; }
function ap_db_default_owner_user_id(): int { return 1; }

$root = dirname(__DIR__);
reference_load($root . '/ap-admin.php', [
    'admin_library_page_bounds',
    'admin_library_fetched_page',
    'admin_library_source_page',
    'admin_library_favourite_has_more',
    'admin_library_favourite_sentinel',
]);
reference_load($root . '/ap-db.php', [
    'ap_masto_library_api_bounds',
    'ap_masto_library_created_before',
    'ap_masto_favourite_rows',
    'ap_masto_bookmark_rows',
]);
reference_load($root . '/ap-mastodon.php', [
    'ap_masto_timeline_base_url',
    'ap_masto_timeline_link',
    'ap_masto_library_list_request',
]);

$insertKey = ap_db()->prepare('INSERT INTO library_page_keys(kind, ts, ref) VALUES(?,?,?)');
$keys = [];
for ($i = 0; $i < 41; $i++) {
    $ts = sprintf('2026-10-14T12:%02d:%02dZ', intdiv($i, 60), $i % 60);
    $kind = $i % 2 === 0 ? 'fedi' : 'bsky';
    $ref = sprintf('%02d', $i);
    if ($i === 31) {
        $ts = '2026-10-14T12:00:31Z';
        $kind = 'fedi';
        $ref = 'b';
    }
    if ($i === 30) {
        $ts = '2026-10-14T12:00:31Z';
        $kind = 'bsky';
        $ref = 'a';
    }
    $insertKey->execute([$kind, $ts, $ref]);
    $keys[] = ['kind' => $kind, 'ts' => $ts, 'ref' => $ref];
}
$ordered = [];
foreach (ap_db()->query('SELECT kind, ts, ref FROM library_page_keys ORDER BY ts DESC, ref DESC') as $row) {
    $ordered[] = ['kind' => (string) $row['kind'], 'ts' => (string) $row['ts'], 'ref' => (string) $row['ref']];
}
if (($ordered[9]['kind'] ?? '') !== 'fedi' || ($ordered[9]['ref'] ?? '') !== 'b'
    || ($ordered[10]['kind'] ?? '') !== 'bsky' || ($ordered[10]['ref'] ?? '') !== 'a') {
    throw new RuntimeException('tie did not land on the page boundary');
}

$pages = [];
foreach ([[0, 10], [10, 10], [0, 11], [11, 11], [20, 10], [40, 10], [41, 10], [-3, 0], [0, 41], [0, 9]] as [$offset, $limit]) {
    $page = admin_library_source_page($ordered, $offset, $limit);
    $selected = [];
    foreach ($page['keys'] as $key) {
        $selected[] = ['kind' => (string) $key['kind'], 'ts' => (string) $key['ts'], 'ref' => (string) $key['ref']];
    }
    $painted = max(0, count($selected) - 1);
    $pages[] = [
        'offset' => $offset,
        'limit' => $limit,
        'clamped_offset' => $page['offset'],
        'clamped_limit' => $page['limit'],
        'keys' => $selected,
        'next_offset' => $page['next_offset'],
        'has_more' => $page['has_more'],
        'painted' => $painted,
    ];
}
$first = $pages[0];
$second = $pages[1];
if ($first['next_offset'] !== 10 || $first['has_more'] !== true || $first['painted'] === count($first['keys'])) {
    throw new RuntimeException('first page cursor followed painted cards');
}
if (($first['keys'][9]['ref'] ?? '') !== 'b' || ($second['keys'][0]['ref'] ?? '') !== 'a') {
    throw new RuntimeException('mixed page boundary dropped or repeated the tie');
}
$seen = [];
foreach (array_merge($first['keys'], $second['keys']) as $key) {
    $id = $key['kind'] . '|' . $key['ref'];
    if (isset($seen[$id])) {
        throw new RuntimeException('page boundary repeated ' . $id);
    }
    $seen[$id] = true;
}
if (count($seen) !== 20) {
    throw new RuntimeException('two pages did not cover twenty source keys');
}

$bounds = [];
foreach ([[-5, 0], [3, 40], [3, 41], [0, 9], [0, 10]] as [$offset, $limit]) {
    [$clampedOffset, $clampedLimit] = admin_library_page_bounds($offset, $limit);
    $bounds[] = ['offset' => $offset, 'limit' => $limit, 'clamped_offset' => $clampedOffset, 'clamped_limit' => $clampedLimit];
}

$favourites = [];
foreach ([
    ['page', 40, 15, 0, 20],
    ['ajax', 40, 15, 0, 20],
    ['soft', 15, 15, 0, 20],
    ['page', 20, 20, 0, 20],
    ['page', 21, 21, 0, 20],
    ['page', 21, 10, 0, 40],
    ['ajax', 21, 21, 0, 20],
    ['ajax', 50, 41, 20, 20],
    ['ajax', 50, 40, 20, 20],
    ['ajax', 10, 10, -5, 9],
    ['soft', 21, 21, 0, 20],
] as [$surface, $hydrated, $filtered, $offset, $limit]) {
    $hasMore = admin_library_favourite_has_more($surface, $hydrated, $filtered, $offset, $limit);
    $favourites[] = [
        'surface' => $surface,
        'hydrated' => $hydrated,
        'filtered' => $filtered,
        'offset' => $offset,
        'limit' => $limit,
        'has_more' => $hasMore,
        'sentinel' => admin_library_favourite_sentinel($hasMore),
    ];
}
if ($favourites[0]['has_more'] !== true || $favourites[1]['has_more'] !== false) {
    throw new RuntimeException('full page and ajax disagreed about the unfiltered count');
}
if ($favourites[0]['sentinel'] !== 'Scroll for more…' || $favourites[1]['sentinel'] !== 'End of Fediverse favourites') {
    throw new RuntimeException('favourite sentinel text drifted');
}

$insertSaved = static function (string $table, int $owner, string $statusId, string $created): void {
    ap_db()->prepare("INSERT INTO {$table}(owner_user_id, status_id, object_id, created_at) VALUES(?,?,?,?)")
        ->execute([$owner, $statusId, 'https://remote.test/' . $statusId, $created]);
};
foreach ([
    [1, 'shared-id', '2026-10-14T00:00:06Z'],
    [1, 'fav-newer', '2026-10-14T00:00:05Z'],
    [1, 'fav-tie-a', '2026-10-14T00:00:04Z'],
    [1, 'fav-tie-c', '2026-10-14T00:00:04Z'],
    [1, 'fav-tie-b', '2026-10-14T00:00:04Z'],
    [1, 'fav-old', '2026-10-14T00:00:03Z'],
    [2, 'fav-foreign', '2026-10-14T00:00:09Z'],
] as [$owner, $statusId, $created]) {
    $insertSaved('masto_favourites', $owner, $statusId, $created);
}
foreach ([
    [1, 'bm-new', '2026-10-14T00:00:08Z'],
    [1, 'bm-tie-a', '2026-10-14T00:00:07Z'],
    [1, 'bm-tie-b', '2026-10-14T00:00:07Z'],
    [1, 'shared-id', '2026-10-14T00:00:01Z'],
] as [$owner, $statusId, $created]) {
    $insertSaved('masto_bookmarks', $owner, $statusId, $created);
}

$saved = static function (string $table): array {
    $rows = [];
    foreach (ap_db()->query("SELECT owner_user_id, status_id, created_at FROM {$table} ORDER BY id") as $row) {
        $rows[] = [
            'owner_id' => (int) $row['owner_user_id'],
            'status_id' => (string) $row['status_id'],
            'created_at' => (string) $row['created_at'],
        ];
    }
    return $rows;
};
$project = static function (array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'owner_id' => (int) ($row['owner_user_id'] ?? 0),
            'status_id' => (string) ($row['status_id'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
    return $out;
};

$apiBounds = [];
foreach ([-4, 0, 1, 40, 80, 81] as $limit) {
    $bound = ap_masto_library_api_bounds($limit);
    $apiBounds[] = ['limit' => $limit, 'clamped' => $bound['limit'], 'fetch' => $bound['fetch']];
}

$requests = [];
foreach ([
    [],
    ['limit' => '0', 'since_id' => 'abc', 'min_id' => 'def'],
    ['limit' => '81', 'max_id' => '', 'since_id' => 'x'],
    ['limit' => 'no', 'max_id' => 'status 1/2', 'min_id' => '1'],
    ['since_id' => 'only-since'],
] as $query) {
    $request = ap_masto_library_list_request($query);
    $requests[] = [
        'query' => (object) $query,
        'limit' => $request['limit'],
        'max_id' => $request['max_id'],
    ];
    if (array_key_exists('since_id', $request) || array_key_exists('min_id', $request)) {
        throw new RuntimeException('library list request kept since_id or min_id');
    }
}

$apiPages = [];
$cases = [
    ['favourites', 1, 1, null],
    ['favourites', 1, 3, null],
    ['favourites', 1, 40, 'fav-tie-b'],
    ['favourites', 1, 40, 'missing'],
    ['favourites', 1, 40, ''],
    ['favourites', 1, 40, 'fav-foreign'],
    ['favourites', 1, 40, 'shared-id'],
    ['favourites', 1, 0, null],
    ['favourites', 1, 100, null],
    ['favourites', 3, 40, null],
    ['favourites', null, 40, null],
    ['bookmarks', 1, 40, null],
    ['bookmarks', 1, 40, 'shared-id'],
    ['bookmarks', 1, 40, 'fav-tie-b'],
    ['bookmarks', 1, 1, null],
];
foreach ($cases as [$table, $owner, $limit, $maxId]) {
    $since = 'ignored-since';
    $rows = $table === 'bookmarks'
        ? ap_masto_bookmark_rows($limit, $maxId, $owner)
        : ap_masto_favourite_rows($limit, $maxId, $owner);
    $apiPages[] = [
        'table' => $table,
        'owner' => $owner,
        'limit' => $limit,
        'max_id' => $maxId,
        'since_id' => $since,
        'rows' => $project($rows),
    ];
}
$tiePage = $apiPages[2]['rows'];
if (count($tiePage) !== 1 || ($tiePage[0]['status_id'] ?? '') !== 'fav-old') {
    throw new RuntimeException('equal favourite timestamps were not dropped together');
}
$sharedBookmark = $apiPages[12]['rows'];
if ($sharedBookmark !== []) {
    throw new RuntimeException('bookmark cursor used the favourite timestamp');
}
$foreign = $apiPages[5]['rows'];
if (count($foreign) < 6 || array_filter($foreign, static fn(array $row): bool => $row['status_id'] === 'fav-foreign') !== []) {
    throw new RuntimeException('another owner cursor changed this owner page');
}

$links = [];
foreach ([
    ['library.example', '/api/v1/favourites', 500, [['id' => 'id 1/2'], ['id' => 'last"x']]],
    ['library.example', '/api/v1/bookmarks', 40, []],
    ['', '/api/v1/favourites', 20, [['id' => 'kept']]],
    ['bad host/<script>', '/api/v1/favourites', 20, [['id' => 'kept']]],
    ['library.example:8443', '/api/v1/favourites', 0, [['id' => '']]],
    ['library.example', '/api/v1/bookmarks', 81, [['id' => 'first'], ['id' => '']]],
] as [$host, $path, $limit, $statuses]) {
    $_SERVER['HTTP_HOST'] = $host;
    $link = ap_masto_timeline_link($statuses, $path, $limit);
    if (str_contains($link, 'since_id') || str_contains($link, 'min_id=') && str_contains($link, 'since')) {
        throw new RuntimeException('link header used since_id');
    }
    $links[] = ['host' => $host, 'path' => $path, 'limit' => $limit, 'statuses' => $statuses, 'link' => $link];
}
if (!str_contains($links[0]['link'], 'limit=500') || !str_contains($links[0]['link'], 'rel="next"') || !str_contains($links[0]['link'], 'rel="prev"')) {
    throw new RuntimeException('link header clamped the requested limit or dropped a relation');
}
if (!str_contains($links[0]['link'], 'max_id=') || !str_contains($links[0]['link'], 'min_id=')) {
    throw new RuntimeException('link header did not use max_id and min_id');
}
if ($links[1]['link'] !== '' || !str_contains($links[2]['link'], 'https://vaak.monster/')) {
    throw new RuntimeException('empty page or empty host link drifted');
}

echo json_encode([
    'bounds' => $bounds,
    'keys' => $keys,
    'pages' => $pages,
    'favourites' => $favourites,
    'api_bounds' => $apiBounds,
    'requests' => $requests,
    'favourites_rows' => $saved('masto_favourites'),
    'bookmarks_rows' => $saved('masto_bookmarks'),
    'api_pages' => $apiPages,
    'links' => $links,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
