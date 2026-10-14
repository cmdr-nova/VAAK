<?php
declare(strict_types=1);

/**
 * Phyrian local-profile card.
 * No Postgres and no Redis server: the switch-off path must not touch either,
 * and the switch-on path reads one primary-key row from an in-memory table.
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

if ($failures !== []) {
    fwrite(STDERR, "phyrian-profile-card: FAIL\n" . implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "phyrian-profile-card: PASS ({$checks} checks)\n");
