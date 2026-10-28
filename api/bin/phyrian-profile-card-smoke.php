<?php
declare(strict_types=1);

/**
 * Phyrian local-profile card.
 * No Postgres and no Redis server: the switch-off path must not touch either,
 * and the switch-on path reads one primary-key row from an in-memory table.
 *
 * The markup cases at the end live in api/fixtures/phyrian-profile-card.json,
 * which the Rust test phyrian_card::tests::shared_fixture_matches_php replays.
 * Regenerate after an intended markup change with:
 *   php api/bin/phyrian-profile-card-smoke.php --write-fixture
 */

$GLOBALS['phyrian_card_redis_gets'] = 0;
$GLOBALS['phyrian_card_redis_sets'] = 0;
$GLOBALS['phyrian_card_redis'] = [];
$GLOBALS['phyrian_card_redis_forbid'] = false;

function ap_redis_json_get(string $key): ?array
{
    if (!empty($GLOBALS['phyrian_card_redis_forbid'])) {
        throw new RuntimeException('redis touched while the profile card switch is off');
    }
    $GLOBALS['phyrian_card_redis_gets']++;
    $stored = $GLOBALS['phyrian_card_redis'][$key] ?? null;
    return is_array($stored) ? $stored : null;
}

function ap_redis_json_set(string $key, array $value, int $ttlSeconds): bool
{
    if (!empty($GLOBALS['phyrian_card_redis_forbid'])) {
        throw new RuntimeException('redis touched while the profile card switch is off');
    }
    $GLOBALS['phyrian_card_redis_sets']++;
    $GLOBALS['phyrian_card_redis'][$key] = $value;
    $GLOBALS['phyrian_card_redis_ttl'] = $ttlSeconds;
    return true;
}

require_once dirname(__DIR__) . '/ap-phyrian-profile-card.php';

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

final class PhyrianCardForbidPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw new RuntimeException('database touched while the profile card switch is off');
    }
}

final class PhyrianCardCountingPdo extends PDO
{
    public int $prepares = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepares++;
        return parent::prepare($query, $options);
    }
}

$forbid = new PhyrianCardForbidPdo('sqlite::memory:');
$stats = '<div class="remote-profile-stats" aria-label="Profile stats">'
    . '<div><span class="n">5169</span><span class="l">Posts</span></div>'
    . '<a href="/users/cmdr_nova/following"><span class="n">804</span><span class="l">Following</span></a>'
    . '<a href="/users/cmdr_nova/followers"><span class="n">1180</span><span class="l">Followers</span></a>'
    . '</div>';

putenv('VAAK_PHYRIAN_PROFILE_CARD');
$GLOBALS['phyrian_card_redis_forbid'] = true;
ap_phyrian_profile_card_cache_reset();
$check(ap_phyrian_profile_card_enabled() === false, 'unset switch is off');
$off = ap_phyrian_profile_card_markup(true, 7, ['is_self' => true], $forbid);
$check($off === '', 'switch off renders nothing');
$check($off . $stats === $stats, 'switch off leaves the counts row unchanged');
$check(ap_phyrian_profile_card_lookup_count() === 0, 'switch off does no player lookup');
$check($GLOBALS['phyrian_card_redis_gets'] === 0 && $GLOBALS['phyrian_card_redis_sets'] === 0, 'switch off does no redis work');

foreach (['0', 'false', 'off', 'no', ''] as $disabled) {
    putenv('VAAK_PHYRIAN_PROFILE_CARD=' . $disabled);
    ap_phyrian_profile_card_cache_reset();
    $check(ap_phyrian_profile_card_enabled() === false, 'disabled value ' . ($disabled === '' ? 'empty' : $disabled));
    $check(ap_phyrian_profile_card_markup(true, 7, [], $forbid) === '', 'disabled value renders nothing');
}

putenv('VAAK_PHYRIAN_PROFILE_CARD=1');
$GLOBALS['phyrian_card_redis_forbid'] = false;
ap_phyrian_profile_card_cache_reset();
$check(ap_phyrian_profile_card_markup(false, 7, ['is_self' => true], $forbid) === '', 'remote profile renders nothing');
$check(ap_phyrian_profile_card_lookup_count() === 0, 'remote profile does no lookup');

$invite = ap_phyrian_profile_card_markup(true, 0, ['is_self' => true], $forbid);
$check(str_contains($invite, 'Not imprinted yet.') && str_contains($invite, 'Start playing'), 'local user with no id still gets the invitation');
$check(ap_phyrian_profile_card_lookup_count() === 0, 'missing owner id does no lookup');
$check(str_contains($invite, 'width:100%'), 'invitation card spans the profile width');

$db = new PhyrianCardCountingPdo('sqlite::memory:');
$db->exec(
    'CREATE TABLE phyrian_players (
        owner_user_id INTEGER PRIMARY KEY,
        status TEXT,
        strain TEXT,
        resonance INTEGER,
        generation INTEGER,
        level INTEGER,
        banked_resonance INTEGER,
        resonance_exchanges INTEGER,
        inductions_given INTEGER,
        lineage_depth INTEGER,
        daily_resonance_decay INTEGER
    )'
);
$db->exec(
    "INSERT INTO phyrian_players
        (owner_user_id, status, strain, resonance, generation, level, banked_resonance, resonance_exchanges, inductions_given, lineage_depth, daily_resonance_decay)
     VALUES
        (4, 'unknown', '', 0, 1, 1, 0, 0, 0, NULL, 5),
        (9, 'imprinted', 'Cosmic Alien <script>', 40, 3, 12, 8, 2, 1, 4, 5)"
);

ap_phyrian_profile_card_cache_reset();
$GLOBALS['phyrian_card_redis_gets'] = 0;
$GLOBALS['phyrian_card_redis_sets'] = 0;
$unmarked = ap_phyrian_profile_card_markup(true, 4, ['is_self' => true], $db);
$check(str_contains($unmarked, 'data-phyrian-card="unmarked"'), 'existing unmarked row stays unmarked');
$check(str_contains($unmarked, 'Not imprinted yet.') && str_contains($unmarked, 'Start playing to receive a strain.'), 'unmarked row invites the player');
$check(str_contains($unmarked, 'Level <b>1</b>') && str_contains($unmarked, 'Resonance <b>0</b>'), 'unmarked row shows basic stats from that row');
$check(!str_contains($unmarked, 'Cosmic Alien'), 'unmarked row does not invent a strain');
$check(ap_phyrian_profile_card_lookup_count() === 1, 'unmarked row is one lookup');
$again = ap_phyrian_profile_card_markup(true, 4, ['is_self' => true], $db);
$check($again === $unmarked, 'cached unmarked card matches');
$check(ap_phyrian_profile_card_lookup_count() === 1, 'second paint uses the cache');
$check($db->prepares === 1, 'database prepare runs once');

$missing = ap_phyrian_profile_card_markup(true, 15, ['is_self' => false], $db);
$check(str_contains($missing, 'Not imprinted yet.') && !str_contains($missing, 'Start playing'), 'other local user with no row gets status without the self invitation');
$check(ap_phyrian_profile_card_lookup_count() === 2, 'missing row is its own lookup');

ap_phyrian_profile_card_cache_reset();
$imprinted = ap_phyrian_profile_card_markup(true, 9, [
    'is_self' => false,
    'opensim_linked' => true,
    'avatar_name' => 'val3r1e flux <b>',
], $db);
$check(str_contains($imprinted, 'data-phyrian-card="imprinted"'), 'imprinted player gets the full card');
$check(str_contains($imprinted, 'Cosmic Alien &lt;script&gt;'), 'strain text is escaped');
$check(!str_contains($imprinted, '<script>'), 'strain markup is not interpreted');
$check(str_contains($imprinted, 'Gen <b>3</b>') && str_contains($imprinted, 'Level <b>12</b>') && str_contains($imprinted, 'Resonance <b>40</b>'), 'full card shows synced stats');
$check(str_contains($imprinted, 'Newly Marked') && str_contains($imprinted, 'Fading'), 'full card uses the existing rank and stability labels');
$check(str_contains($imprinted, 'OpenSim linked as <b>val3r1e flux &lt;b&gt;</b>'), 'already loaded OpenSim name is escaped');
$check(str_contains($imprinted, 'page=dossier&amp;owner=9'), 'other players link to the existing readout');
$check(str_contains($imprinted, 'width:100%'), 'full card spans the profile width');
$check(ap_phyrian_profile_card_lookup_count() === 1, 'imprinted card is one lookup after the cache reset');

ap_phyrian_profile_card_cache_reset();
$key = ap_phyrian_profile_card_cache_key(9);
$before = ap_phyrian_profile_card_lookup_count();
$fromRedis = ap_phyrian_profile_card_markup(true, 9, ['is_self' => true], $forbid);
$check($fromRedis !== '' && str_contains($fromRedis, 'Cosmic Alien'), 'redis cache paints without a new query');
$check(ap_phyrian_profile_card_lookup_count() === $before, 'redis hit does not query');
$check(($GLOBALS['phyrian_card_redis_ttl'] ?? 0) === AP_PHYRIAN_PROFILE_CARD_TTL, 'cache ttl is bounded');

$pure = ap_phyrian_profile_card_html([
    'status' => 'imprinted',
    'strain' => 'Rose Static',
    'resonance' => 90,
    'generation' => 2,
    'level' => 20,
    'banked_resonance' => 0,
    'resonance_exchanges' => 0,
    'inductions_given' => 0,
    'lineage_depth' => null,
    'daily_resonance_decay' => 5,
], ['owner_user_id' => 3, 'is_self' => true]);
$check(str_contains($pure, 'Rose Static') && str_contains($pure, 'Open game'), 'markup helper renders a supplied row with no lookup');
$check(ap_phyrian_profile_card_lookup_count() === $before, 'markup helper does not query');

$admin = (string) file_get_contents(dirname(__DIR__) . '/ap-admin.php');
$flagAt = strpos($admin, "getenv('VAAK_PHYRIAN_PROFILE_CARD')");
$requireAt = strpos($admin, "require_once __DIR__ . '/ap-phyrian-profile-card.php'");
$statsAt = strpos($admin, '<div class="remote-profile-stats" aria-label="Profile stats">');
$check($flagAt !== false && $requireAt !== false && $statsAt !== false && $flagAt < $requireAt && $requireAt < $statsAt, 'profile page checks the switch before loading the helper and before the counts row');
$check(substr_count($admin, 'ap-phyrian-profile-card.php') === 1, 'profile page loads the helper once');
$check(!str_contains($admin, 'FROM phyrian_players'), 'profile page does not query players itself');
$statsRule = '.remote-profile-stats { display:flex; gap:1.25rem; margin:.85rem 0 0; padding-top:.85rem; border-top:1px solid var(--border); flex-wrap:wrap; }';
$check(str_contains($admin, $statsRule), 'counts row stylesheet is unchanged');
$check(str_contains($admin, '<div><span class="n"><?= (int) $rpPostsStat ?></span><span class="l">Posts</span></div>'), 'posts count markup is unchanged');

$cardSource = (string) file_get_contents(dirname(__DIR__) . '/ap-phyrian-profile-card.php');
foreach (['ap_phyrian_migrate', 'ap_phyrian_dossier', 'ap_phyrian_ensure_player', 'ap_phyrian_bridge_sync_from_opensim', 'INSERT ', 'UPDATE ', 'DELETE '] as $forbidden) {
    $check(!str_contains($cardSource, $forbidden), 'card helper does not contain ' . trim($forbidden));
}
$check(substr_count($cardSource, 'FROM phyrian_players') === 1, 'card helper has one player read');
$check(str_contains($cardSource, 'WHERE owner_user_id = ?'), 'player read uses the primary key');

$game = (string) file_get_contents(dirname(__DIR__) . '/ap-phyrian.php');
$bridge = (string) file_get_contents(dirname(__DIR__) . '/ap-phyrian-bridge.php');
$check(!str_contains($game, 'VAAK_PHYRIAN_PROFILE_CARD'), 'game module is unchanged by the card switch');
$check(!str_contains($bridge, 'VAAK_PHYRIAN_PROFILE_CARD'), 'OpenSim bridge is unchanged by the card switch');

// Shared fixture for the Rust profile header. Each card case goes through the
// real page entry with its row served as a shared cache hit, so it covers the
// owner/no-owner selection, normalize and the markup. Each cache case records
// whether a stored value is a hit (no player query) and the row it yields.
putenv('VAAK_PHYRIAN_ORIGIN_OWNER_ID');
putenv('VAAK_PHYRIAN_PROFILE_CARD=1');
$player = static fn (array $over): array => $over + [
    'status' => 'imprinted', 'strain' => 'Rose Static', 'resonance' => 60, 'generation' => 2, 'level' => 20,
    'banked_resonance' => 0, 'resonance_exchanges' => 0, 'inductions_given' => 0, 'lineage_depth' => null, 'daily_resonance_decay' => 5,
];
$link = static fn (?string $name): array => ['owner_user_id' => 5, 'status' => 'verified', 'avatar_name' => $name, 'strain_snapshot' => 'Rose Static'];
$cardCases = [
    ['id' => 'no_owner_self', 'owner' => 0, 'is_self' => true, 'bridge' => null, 'row' => null],
    ['id' => 'no_owner_other', 'owner' => 0, 'is_self' => false, 'bridge' => null, 'row' => null],
    ['id' => 'no_row_self', 'owner' => 5, 'is_self' => true, 'bridge' => null, 'row' => null],
    ['id' => 'no_row_other_linked', 'owner' => 5, 'is_self' => false, 'bridge' => $link('Ada Resident'), 'row' => null],
    ['id' => 'unmarked_self', 'owner' => 5, 'is_self' => true, 'bridge' => null, 'row' => $player(['status' => 'unknown', 'strain' => '', 'resonance' => 0, 'level' => 1, 'generation' => 1])],
    ['id' => 'unmarked_other', 'owner' => 5, 'is_self' => false, 'bridge' => null, 'row' => $player(['status' => 'unknown', 'strain' => null, 'resonance' => 12, 'level' => 3])],
    ['id' => 'imprinted_blank_strain', 'owner' => 5, 'is_self' => false, 'bridge' => null, 'row' => $player(['strain' => '   '])],
    ['id' => 'imprinted_padded_status', 'owner' => 5, 'is_self' => false, 'bridge' => null, 'row' => $player(['status' => ' imprinted ', 'strain' => ' Grey Bloom '])],
    ['id' => 'imprinted_self', 'owner' => 5, 'is_self' => true, 'bridge' => null, 'row' => $player([])],
    ['id' => 'newly_marked_stable', 'owner' => 7, 'is_self' => false, 'bridge' => null, 'row' => $player(['level' => 19, 'resonance' => 50])],
    ['id' => 'colony_node_fading', 'owner' => 7, 'is_self' => false, 'bridge' => null, 'row' => $player(['level' => 39, 'resonance' => 49, 'generation' => 4])],
    ['id' => 'marked_host_critical', 'owner' => 7, 'is_self' => false, 'bridge' => null, 'row' => $player(['level' => 40, 'resonance' => 24, 'banked_resonance' => 8])],
    ['id' => 'marked_host_edge', 'owner' => 7, 'is_self' => false, 'bridge' => null, 'row' => $player(['level' => 59, 'resonance' => 25, 'resonance_exchanges' => 3])],
    ['id' => 'deep_signal_dormant', 'owner' => 7, 'is_self' => false, 'bridge' => null, 'row' => $player(['level' => 60, 'resonance' => 0, 'inductions_given' => 2])],
    ['id' => 'max_level_negative_resonance', 'owner' => 7, 'is_self' => false, 'bridge' => null, 'row' => $player(['level' => 80, 'resonance' => -3, 'generation' => 10, 'banked_resonance' => 300])],
    ['id' => 'lineage_zero', 'owner' => 7, 'is_self' => false, 'bridge' => null, 'row' => $player(['lineage_depth' => 0])],
    ['id' => 'lineage_number', 'owner' => 7, 'is_self' => false, 'bridge' => null, 'row' => $player(['lineage_depth' => 4, 'resonance_exchanges' => 1, 'inductions_given' => 1, 'banked_resonance' => 1])],
    ['id' => 'lineage_blank_string', 'owner' => 7, 'is_self' => false, 'bridge' => null, 'row' => $player(['lineage_depth' => ''])],
    ['id' => 'decay_zero', 'owner' => 7, 'is_self' => false, 'bridge' => null, 'row' => $player(['daily_resonance_decay' => 0])],
    ['id' => 'decay_missing_uses_default', 'owner' => 7, 'is_self' => false, 'bridge' => null, 'row' => array_diff_key($player([]), ['daily_resonance_decay' => true])],
    ['id' => 'driver_strings', 'owner' => 7, 'is_self' => false, 'bridge' => null, 'row' => $player(['resonance' => '41', 'generation' => '3', 'level' => ' 22', 'banked_resonance' => '0', 'lineage_depth' => '2', 'daily_resonance_decay' => '7'])],
    ['id' => 'nulls_use_defaults', 'owner' => 7, 'is_self' => false, 'bridge' => null, 'row' => ['status' => 'imprinted', 'strain' => 'Ash Gene', 'resonance' => null, 'generation' => null, 'level' => null]],
    ['id' => 'strain_escaped', 'owner' => 7, 'is_self' => false, 'bridge' => null, 'row' => $player(['strain' => 'Cosmic <Alien> & "Kin" \'x\''])],
    ['id' => 'unicode_strain', 'owner' => 7, 'is_self' => true, 'bridge' => null, 'row' => $player(['strain' => 'Violet Drift ニュース ü'])],
    ['id' => 'origin_owner', 'owner' => 1, 'is_self' => false, 'bridge' => $link('val3r1e flux'), 'row' => $player(['strain' => 'Phyrian', 'generation' => 3, 'level' => 80, 'resonance' => 93])],
    ['id' => 'origin_self', 'owner' => 1, 'is_self' => true, 'bridge' => $link('val3r1e flux'), 'row' => $player(['strain' => 'Phyrian', 'level' => 80])],
    ['id' => 'origin_unmarked', 'owner' => 1, 'is_self' => true, 'bridge' => null, 'row' => $player(['status' => 'unknown', 'strain' => ''])],
    ['id' => 'linked_special_chars', 'owner' => 7, 'is_self' => false, 'bridge' => $link('  <b>"Nova" & \'Bee\'</b>  '), 'row' => $player([])],
    ['id' => 'linked_blank_name', 'owner' => 7, 'is_self' => true, 'bridge' => $link('   '), 'row' => $player(['status' => 'unknown', 'strain' => ''])],
    ['id' => 'linked_null_name', 'owner' => 7, 'is_self' => false, 'bridge' => $link(null), 'row' => null],
];
$fixtureCards = [];
foreach ($cardCases as $case) {
    ap_phyrian_profile_card_cache_reset();
    $GLOBALS['phyrian_card_redis'] = [];
    if ($case['owner'] > 0) {
        $GLOBALS['phyrian_card_redis'][ap_phyrian_profile_card_cache_key($case['owner'])] = ['found' => $case['row'] !== null, 'row' => $case['row']];
    }
    $linked = is_array($case['bridge']);
    $case['html'] = ap_phyrian_profile_card_markup(true, $case['owner'], [
        'is_self' => $case['is_self'],
        'opensim_linked' => $linked,
        'avatar_name' => $linked ? (string) ($case['bridge']['avatar_name'] ?? '') : '',
    ], $forbid);
    $check(ap_phyrian_profile_card_lookup_count() === 0, 'fixture card ' . $case['id'] . ' is served from the shared value');
    $check(str_starts_with($case['html'], '<section class="phyrian-profile-card"'), 'fixture card ' . $case['id'] . ' renders a card');
    $fixtureCards[] = $case;
}
$cacheCases = [
    ['id' => 'found_row', 'cached' => '{"found":true,"row":{"status":"imprinted","strain":"Ash Gene","resonance":40,"generation":2,"level":9,"banked_resonance":0,"resonance_exchanges":0,"inductions_given":0,"lineage_depth":null,"daily_resonance_decay":5}}'],
    ['id' => 'found_false', 'cached' => '{"found":false,"row":null}'],
    ['id' => 'found_zero_with_row', 'cached' => '{"found":0,"row":{"status":"imprinted","strain":"Ash Gene"}}'],
    ['id' => 'found_string_zero', 'cached' => '{"found":"0","row":{"status":"imprinted","strain":"Ash Gene"}}'],
    ['id' => 'found_null', 'cached' => '{"found":null}'],
    ['id' => 'found_row_string', 'cached' => '{"found":true,"row":"imprinted"}'],
    ['id' => 'found_row_list', 'cached' => '{"found":1,"row":[]}'],
    ['id' => 'found_row_partial', 'cached' => '{"found":"yes","row":{"status":"imprinted","strain":"Grave Neon","level":"61"}}'],
    ['id' => 'no_found_key', 'cached' => '{"row":{"status":"imprinted","strain":"Ash Gene"}}'],
    ['id' => 'list_value', 'cached' => '["found"]'],
    ['id' => 'scalar_value', 'cached' => '5'],
    ['id' => 'not_json', 'cached' => 'found'],
];
$fixtureCache = [];
foreach ($cacheCases as $case) {
    ap_phyrian_profile_card_cache_reset();
    $decoded = json_decode($case['cached'], true);
    $GLOBALS['phyrian_card_redis'] = [ap_phyrian_profile_card_cache_key(999) => is_array($decoded) ? $decoded : null];
    $snap = ap_phyrian_profile_card_snapshot(999, $db);
    $case['hit'] = ap_phyrian_profile_card_lookup_count() === 0;
    $case['row'] = $case['hit'] ? $snap['row'] : null;
    $check(!empty($snap['ok']), 'fixture cache ' . $case['id'] . ' snapshot is ok');
    $fixtureCache[] = $case;
}
$fixtureFlags = [];
foreach ([null, '', '1', 'true', 'TRUE', 'yes', 'On', " on\n", "\t1 ", '0', 'false', 'off', 'no', 'enabled', '1 1', 'y'] as $raw) {
    putenv($raw === null ? 'VAAK_PHYRIAN_PROFILE_CARD' : 'VAAK_PHYRIAN_PROFILE_CARD=' . $raw);
    $fixtureFlags[] = ['raw' => $raw, 'enabled' => ap_phyrian_profile_card_enabled()];
}
putenv('VAAK_PHYRIAN_PROFILE_CARD=1');
$fixturePath = dirname(__DIR__) . '/fixtures/phyrian-profile-card.json';
$encoded = json_encode(['origin_owner_user_id' => 1, 'cards' => $fixtureCards, 'cache' => $fixtureCache, 'flags' => $fixtureFlags], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
if (in_array('--write-fixture', $argv ?? [], true)) {
    file_put_contents($fixturePath, $encoded);
    echo "wrote {$fixturePath}\n";
    exit(0);
}
$check(is_file($fixturePath) && file_get_contents($fixturePath) === $encoded, 'shared fixture matches PHP output (rerun with --write-fixture after an intended change)');

if ($failures !== []) {
    fwrite(STDERR, "phyrian-profile-card: FAIL\n" . implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "phyrian-profile-card: PASS ({$checks} checks)\n");
