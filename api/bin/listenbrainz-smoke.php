<?php
/**
 * ListenBrainz now playing: display rules, profile markup, username input,
 * listen parsing, poll scheduling, and the SQLite save/lookup path.
 * No network and no production database.
 *
 * The display/markup cases live in api/fixtures/listenbrainz-now-playing.json,
 * which the Rust test listenbrainz::tests::shared_fixture_matches_php replays.
 * Regenerate after an intended markup change with:
 *   php api/bin/listenbrainz-smoke.php --write-fixture
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/ap-listenbrainz.php';

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $why) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $why;
    }
};

$now = 1_800_000_000;
$caa = 'https://coverartarchive.org/release/0a1b2c3d-0000-4000-8000-000000000000/123456789-250.jpg';
$row = static fn (array $over): array => $over + [
    'owner_user_id' => 1, 'lb_username' => 'nova', 'playing_track' => '', 'playing_artist' => '', 'playing_release' => '',
    'playing_cover' => '', 'last_track' => '', 'last_artist' => '', 'last_release' => '', 'last_cover' => '',
    'last_listened_at' => 0, 'fetched_at' => $now - 30, 'next_fetch_at' => 0, 'failures' => 0, 'last_error' => '',
];
$cases = [
    ['id' => 'playing_with_cover', 'row' => $row(['playing_track' => 'Windowlicker', 'playing_artist' => 'Aphex Twin', 'playing_release' => 'Windowlicker', 'playing_cover' => $caa, 'last_track' => 'Older'])],
    ['id' => 'playing_stale_falls_back', 'row' => $row(['playing_track' => 'Stale', 'fetched_at' => $now - 601, 'last_track' => 'Roygbiv', 'last_artist' => 'Boards of Canada', 'last_release' => 'Music Has the Right to Children'])],
    ['id' => 'playing_edge_fresh', 'row' => $row(['playing_track' => 'Edge', 'fetched_at' => $now - 600])],
    ['id' => 'last_only_no_artist', 'row' => $row(['last_track' => 'Untitled', 'last_release' => 'Selected Ambient Works'])],
    ['id' => 'escaping', 'row' => $row(['lb_username' => 'Nova Bee', 'playing_track' => '<b>"Tom" & \'Jerry\'</b>', 'playing_artist' => 'A&B', 'playing_release' => 'R<1>', 'playing_cover' => 'javascript:alert(1)'])],
    ['id' => 'unicode', 'row' => $row(['lb_username' => 'Ålesund_ü', 'playing_track' => 'ニュース', 'playing_artist' => 'Björk', 'playing_cover' => 'https://coverartarchive.org/release/0a1b2c3d-0000-4000-8000-000000000000/front-250'])],
    ['id' => 'nothing_stored', 'row' => $row([])],
    ['id' => 'no_username', 'row' => $row(['lb_username' => '  ', 'playing_track' => 'X'])],
    ['id' => 'whitespace_track', 'row' => $row(['playing_track' => '   ', 'last_track' => ' Kept '])],
];
foreach ($cases as &$case) {
    $case['now'] = $now;
    $display = ap_listenbrainz_display($case['row'], $now);
    $case['display'] = $display;
    $case['html'] = ap_listenbrainz_profile_html($display);
}
unset($case);

$fixturePath = dirname(__DIR__) . '/fixtures/listenbrainz-now-playing.json';
$encoded = json_encode(['cases' => $cases], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
if (in_array('--write-fixture', $argv ?? [], true)) {
    file_put_contents($fixturePath, $encoded);
    echo "wrote {$fixturePath}\n";
    exit(0);
}
$check(is_file($fixturePath) && file_get_contents($fixturePath) === $encoded, 'shared fixture matches PHP output (rerun with --write-fixture after an intended change)');

$byId = array_column($cases, null, 'id');
$check(str_contains($byId['playing_with_cover']['html'], '>Now playing<') && str_contains($byId['playing_with_cover']['html'], 'src="' . $caa . '"'), 'fresh playing shows Now playing with cover');
$check(str_contains($byId['playing_with_cover']['html'], '>Aphex Twin<'), 'album equal to track title is not repeated');
$check(str_contains($byId['playing_stale_falls_back']['html'], '>Last played<') && str_contains($byId['playing_stale_falls_back']['html'], 'Roygbiv'), 'stale playing falls back to last listen');
$check(str_contains($byId['playing_edge_fresh']['html'], '>Now playing<'), '600 seconds old is still fresh');
$check(!str_contains($byId['escaping']['html'], '<b>') && !str_contains($byId['escaping']['html'], 'javascript:') && str_contains($byId['escaping']['html'], 'listenbrainz.org/user/Nova%20Bee/'), 'text is escaped and bad covers are dropped');
$check($byId['nothing_stored']['html'] === '' && $byId['no_username']['html'] === '' && $byId['nothing_stored']['display'] === null, 'nothing to show prints nothing');
$check(str_contains($byId['whitespace_track']['html'], '>Kept<'), 'blank playing title falls back');
$check(!str_contains(implode('', array_column($cases, 'html')), "\n"), 'markup is one line');

// Username input.
$check(ap_listenbrainz_normalize_username('  ') === '', 'blank turns it off');
$check(ap_listenbrainz_normalize_username('@cmdr_nova') === 'cmdr_nova', 'leading @ is dropped');
$check(ap_listenbrainz_normalize_username('https://listenbrainz.org/user/Some%20One/') === 'Some One', 'profile URL is accepted');
$check(ap_listenbrainz_normalize_username('a/b') === null, 'slash rejected');
$check(ap_listenbrainz_normalize_username(str_repeat('x', 65)) === null, 'too long rejected');
$check(ap_listenbrainz_normalize_username("x\ny") === null, 'control characters rejected');

// Listen parsing.
$parsed = ap_listenbrainz_parse_listen([
    'listened_at' => 1_799_999_000,
    'track_metadata' => [
        'track_name' => "  Song\twith tab ",
        'artist_name' => 'Artist',
        'release_name' => 'Album',
        'mbid_mapping' => ['caa_release_mbid' => '0A1B2C3D-0000-4000-8000-000000000000', 'caa_id' => 42],
    ],
]);
$check(($parsed['track'] ?? '') === 'Song with tab' && ($parsed['cover'] ?? '') === 'https://coverartarchive.org/release/0a1b2c3d-0000-4000-8000-000000000000/42-250.jpg' && ($parsed['listened_at'] ?? 0) === 1_799_999_000, 'listen parsed with CAA cover');
$parsed = ap_listenbrainz_parse_listen(['track_metadata' => ['track_name' => 'T', 'additional_info' => ['release_mbid' => '0a1b2c3d-0000-4000-8000-000000000000']]]);
$check(($parsed['cover'] ?? '') === 'https://coverartarchive.org/release/0a1b2c3d-0000-4000-8000-000000000000/front-250' && ap_listenbrainz_cover_ok($parsed['cover']), 'release mbid falls back to front cover');
$check(ap_listenbrainz_parse_listen(['track_metadata' => ['track_name' => '']]) === null && ap_listenbrainz_parse_listen('x') === null, 'empty listen ignored');
$long = ap_listenbrainz_parse_listen(['track_metadata' => ['track_name' => str_repeat('é', 500)]]);
$check(mb_strlen($long['track'] ?? '', 'UTF-8') === AP_LISTENBRAINZ_TEXT_MAX, 'long text clipped');

// Poll scheduling with a fake transport.
$calls = [];
$pauses = 0;
$responses = [];
$get = static function (string $path) use (&$calls, &$responses): array {
    $calls[] = $path;
    return array_shift($responses) ?? ['status' => 0, 'json' => null, 'reset_in' => 0, 'error' => 'no response'];
};
$pause = static function () use (&$pauses): void {
    $pauses++;
};
$ok = static fn (array $listens): array => ['status' => 200, 'json' => ['payload' => ['count' => count($listens), 'listens' => $listens]], 'reset_in' => 0, 'error' => ''];
$song = ['track_metadata' => ['track_name' => 'Live', 'artist_name' => 'Now']];

$responses = [$ok([$song + ['playing_now' => true]])];
$r = ap_listenbrainz_poll($row(['lb_username' => 'Some One']), $now, $get, $pause);
$check($calls === ['/1/user/Some%20One/playing-now'] && $pauses === 0, 'playing skips the listens call');
$check($r['update']['playing_track'] === 'Live' && $r['update']['next_fetch_at'] === $now + 60 && $r['update']['fetched_at'] === $now && !isset($r['update']['last_track']), 'playing schedules a minute out and keeps last listen');

$calls = [];
$responses = [$ok([]), $ok([$song + ['listened_at' => $now - 100]])];
$r = ap_listenbrainz_poll($row([]), $now, $get, $pause);
$check(count($calls) === 2 && $pauses === 1, 'idle fetches last listen after a pause');
$check($r['update']['playing_track'] === '' && $r['update']['last_track'] === 'Live' && $r['update']['next_fetch_at'] === $now + 120, 'recent listener polled every two minutes');

$responses = [$ok([]), $ok([$song + ['listened_at' => $now - 7200]])];
$r = ap_listenbrainz_poll($row([]), $now, $get, $pause);
$check($r['update']['next_fetch_at'] === $now + 600, 'idle listener polled every ten minutes');

$responses = [['status' => 404, 'json' => null, 'reset_in' => 0, 'error' => '']];
$r = ap_listenbrainz_poll($row(['failures' => 2]), $now, $get, $pause);
$check($r['update']['failures'] === 3 && $r['update']['next_fetch_at'] === $now + 3600 && str_contains($r['update']['last_error'], 'not found'), 'unknown user backs off an hour');

$responses = [['status' => 500, 'json' => null, 'reset_in' => 0, 'error' => '']];
$r = ap_listenbrainz_poll($row(['failures' => 2]), $now, $get, $pause);
$check($r['update']['next_fetch_at'] === $now + 240 && !isset($r['update']['fetched_at']), 'server error backs off exponentially without marking fresh');
$check(ap_listenbrainz_failure_delay(50) === 3600, 'backoff caps at an hour');

$responses = [['status' => 429, 'json' => null, 'reset_in' => 7, 'error' => '']];
$r = ap_listenbrainz_poll($row([]), $now, $get, $pause);
$check($r['rate_limited'] === 7 && $r['update'] === [], 'rate limit stops without touching the row');

// SQLite save and lookup.
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE ap_users (id INTEGER PRIMARY KEY, actor_key TEXT, disabled_at TEXT)');
$db->exec("INSERT INTO ap_users (id, actor_key, disabled_at) VALUES (5, 'nova', NULL), (6, 'gone', '2026-01-01')");
unset($GLOBALS['ap_listenbrainz_table_ready']);
$check(ap_listenbrainz_table_ready($db), 'sqlite table is created');
$check(ap_listenbrainz_save_username(5, 'bad/name', $db)['ok'] === false, 'invalid name refused');
$check(ap_listenbrainz_save_username(5, 'nova_lb', $db)['ok'] === true, 'name saved');
$check((ap_listenbrainz_link_for_actor_key('nova', $db)['lb_username'] ?? '') === 'nova_lb', 'lookup by actor key');
ap_listenbrainz_store_poll($db, 5, 'nova_lb', ['playing_track' => 'Saved', 'fetched_at' => $now, 'next_fetch_at' => $now + 60]);
$check((ap_listenbrainz_link_for_user(5, $db)['playing_track'] ?? '') === 'Saved', 'poll result stored');
ap_listenbrainz_store_poll($db, 5, 'someone_else', ['playing_track' => 'Raced']);
$check((ap_listenbrainz_link_for_user(5, $db)['playing_track'] ?? '') === 'Saved', 'poll for an old username is dropped');
ap_listenbrainz_save_username(5, 'renamed', $db);
$renamed = ap_listenbrainz_link_for_user(5, $db);
$check(($renamed['playing_track'] ?? 'x') === '' && (int) ($renamed['next_fetch_at'] ?? 1) === 0, 'rename clears the snapshot and is due now');
ap_listenbrainz_save_username(6, 'ghost', $db);
$check(ap_listenbrainz_link_for_actor_key('gone', $db) === null, 'disabled account shows nothing');
ap_listenbrainz_save_username(5, '', $db);
$check(ap_listenbrainz_link_for_user(5, $db) === null, 'blank name deletes the row');

// Header wiring stays a stored read.
$admin = (string) file_get_contents(dirname(__DIR__) . '/ap-admin.php');
$check(str_contains($admin, "<?php endif; ?><?= (\$rpIsLocal && function_exists('ap_listenbrainz_profile_html')) ? ap_listenbrainz_profile_html(\$rpNowPlaying ?? null) : '' ?>"), 'header prints the block after badges');
$check(!str_contains($admin, 'ap_listenbrainz_http_get') && !str_contains($admin, 'ap_listenbrainz_poll('), 'page code never fetches ListenBrainz');

if ($failures !== []) {
    fwrite(STDERR, "listenbrainz-smoke: " . count($failures) . " failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "listenbrainz-smoke: {$checks} checks passed\n";
