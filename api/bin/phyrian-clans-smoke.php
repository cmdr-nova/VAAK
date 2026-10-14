<?php
declare(strict_types=1);

/**
 * Phyrian clans.
 * No Postgres. The switch-off path must not query. The switch-on path reads
 * clan tables only through the clans helper, and writes only after a valid
 * form token.
 */

$GLOBALS['phyrian_clans_redis_hits'] = 0;

function ap_redis_json_get(string $key): ?array
{
    $GLOBALS['phyrian_clans_redis_hits']++;
    throw new RuntimeException('redis touched by phyrian clans');
}

function ap_redis_json_set(string $key, array $value, int $ttlSeconds): bool
{
    $GLOBALS['phyrian_clans_redis_hits']++;
    throw new RuntimeException('redis touched by phyrian clans');
}

function ap_auth_csrf_ok(?string $token): bool
{
    return $token === 'csrf-ok';
}

require_once dirname(__DIR__) . '/ap-phyrian-clans.php';

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

final class PhyrianClansForbidPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw new RuntimeException('database touched while clans must not query');
    }
}

final class PhyrianClansCountingPdo extends PDO
{
    public int $prepares = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepares++;
        return parent::prepare($query, $options);
    }
}

$emblemCalls = [];
$storeEmblem = static function (array $file) use (&$emblemCalls): array {
    $emblemCalls[] = $file;
    return ['ok' => true, 'local_id' => 15];
};
$failEmblem = static function (array $file) use (&$emblemCalls): array {
    $emblemCalls[] = $file;
    return ['ok' => false, 'error' => 'emblem rejected'];
};

$forbid = new PhyrianClansForbidPdo('sqlite::memory:');
$navFixture = '<a class="" href="?view=phyrian&amp;page=dossier">Your readout</a>';

putenv('VAAK_PHYRIAN_CLANS');
$check(ap_phyrian_clans_enabled() === false, 'unset switch is off');
$check(ap_phyrian_clans_nav_html('clans') === '', 'switch off renders no clans tab');
$check($navFixture . ap_phyrian_clans_nav_html('hub') === $navFixture, 'switch off leaves the game nav unchanged');
$check(ap_phyrian_clans_page_html(4, 'csrf-ok', $forbid) === '', 'switch off renders no clans page');
$off = ap_phyrian_clans_mutate('phyrian_clan_create', 4, ['name' => 'Static'], [], 'csrf-ok', $forbid, $storeEmblem);
$check(($off['ok'] ?? true) === false && str_contains((string) ($off['error'] ?? ''), 'turned off'), 'switch off refuses to create');
$check($emblemCalls === [] && $GLOBALS['phyrian_clans_redis_hits'] === 0, 'switch off does not upload or touch redis');

foreach (['0', 'false', 'off', 'no', ''] as $disabled) {
    putenv('VAAK_PHYRIAN_CLANS=' . $disabled);
    $check(ap_phyrian_clans_enabled() === false, 'disabled value ' . ($disabled === '' ? 'empty' : $disabled));
    $check(ap_phyrian_clans_nav_html('clans') === '', 'disabled value renders no tab');
}

putenv('VAAK_PHYRIAN_CLANS=1');
$check(ap_phyrian_clans_enabled() === true, 'switch on is enabled');
$badCsrf = ap_phyrian_clans_mutate('phyrian_clan_create', 4, ['name' => 'Static'], [], 'nope', $forbid, $storeEmblem);
$check(($badCsrf['ok'] ?? true) === false && str_contains((string) ($badCsrf['error'] ?? ''), 'Session expired'), 'bad csrf is rejected');
$check($emblemCalls === [], 'bad csrf does not upload');

$signedOut = ap_phyrian_clans_mutate('phyrian_clan_join', 0, ['clan_id' => 1], [], 'csrf-ok', $forbid, $storeEmblem);
$check(($signedOut['ok'] ?? true) === false && str_contains((string) ($signedOut['error'] ?? ''), 'Not signed in'), 'missing owner is refused');

$db = new PhyrianClansCountingPdo('sqlite::memory:');
$db->exec(
    'CREATE TABLE ap_users (id INTEGER PRIMARY KEY, username TEXT, actor_key TEXT)'
);
$db->exec(
    'CREATE TABLE phyrian_clans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_user_id INTEGER NOT NULL UNIQUE,
        name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT "",
        emblem_media_id INTEGER,
        created_at TEXT NOT NULL
    )'
);
$db->exec(
    'CREATE TABLE phyrian_clan_members (
        clan_id INTEGER NOT NULL,
        owner_user_id INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        PRIMARY KEY (clan_id, owner_user_id)
    )'
);
$db->exec('CREATE TABLE masto_media (local_id INTEGER PRIMARY KEY, public_url TEXT)');
$db->exec("INSERT INTO ap_users (id, username, actor_key) VALUES (4, 'nova <b>', 'cmdr_nova'), (8, '', 'quiet_key')");
$db->exec("INSERT INTO masto_media (local_id, public_url) VALUES (15, 'https://mkultra.monster/emblem.png'), (16, 'javascript:alert(1)')");

$db->prepares = 0;
$emptyName = ap_phyrian_clans_mutate('phyrian_clan_create', 4, ['name' => '   <b></b>  '], [], 'csrf-ok', $db, $storeEmblem);
$check(($emptyName['ok'] ?? true) === false && str_contains((string) ($emptyName['error'] ?? ''), 'name'), 'empty name is refused');
$check($db->prepares === 0 && $emblemCalls === [], 'empty name does no lookup and does not upload');

$noClan = ap_phyrian_clans_mutate('phyrian_clan_join', 4, ['clan_id' => 0], [], 'csrf-ok', $db, $storeEmblem);
$check(($noClan['ok'] ?? true) === false && $db->prepares === 0, 'missing clan id does no lookup');

$unknown = ap_phyrian_clans_mutate('phyrian_clan_rename', 4, ['name' => 'Nope'], [], 'csrf-ok', $forbid, $storeEmblem);
$check(($unknown['ok'] ?? true) === false, 'unknown clan action is refused');

$file = ['name' => 'emblem.png', 'error' => UPLOAD_ERR_OK, 'tmp_name' => 'not-a-real-upload'];
$created = ap_phyrian_clans_mutate(
    'phyrian_clan_create',
    4,
    ['name' => 'Static <script>alert(1)</script>', 'description' => "Hello\n<script>there</script> & more"],
    $file,
    'csrf-ok',
    $db,
    $storeEmblem
);
$check(!empty($created['ok']), 'local player can create a clan');
$check(count($emblemCalls) === 1, 'emblem upload runs only when a file is present');
$check($db->prepares === 3, 'create checks the owner, inserts the clan, and inserts the founder');
$clanCount = (int) $db->query('SELECT COUNT(*) FROM phyrian_clans')->fetchColumn();
$memberCount = (int) $db->query('SELECT COUNT(*) FROM phyrian_clan_members')->fetchColumn();
$check($clanCount === 1 && $memberCount === 1, 'the founder is a member');
$storedName = (string) $db->query('SELECT name FROM phyrian_clans')->fetchColumn();
$check($storedName === 'Static alert(1)' && !str_contains($storedName, '<'), 'clan name is stored as plain text');

$emblemCalls = [];
$beforeSecond = $db->prepares;
$second = ap_phyrian_clans_mutate('phyrian_clan_create', 4, ['name' => 'Another'], $file, 'csrf-ok', $db, $storeEmblem);
$check(($second['ok'] ?? true) === false && str_contains((string) ($second['error'] ?? ''), 'already'), 'a player creates one clan');
$check($emblemCalls === [], 'a second clan does not upload an emblem');
$check((int) $db->query('SELECT COUNT(*) FROM phyrian_clans')->fetchColumn() === 1, 'the second create does not insert');
$check($db->prepares === $beforeSecond + 1, 'the second create is the owner lookup only');

$emblemCalls = [];
$joined = ap_phyrian_clans_mutate('phyrian_clan_join', 8, ['clan_id' => 1], [], 'csrf-ok', $db, $storeEmblem);
$check(!empty($joined['ok']) && $emblemCalls === [], 'another local player can join without an upload');
$again = ap_phyrian_clans_mutate('phyrian_clan_join', 8, ['clan_id' => 1], [], 'csrf-ok', $db, $storeEmblem);
$check(($again['ok'] ?? true) === false && str_contains((string) ($again['error'] ?? ''), 'already'), 'joining twice is refused');
$missing = ap_phyrian_clans_mutate('phyrian_clan_join', 8, ['clan_id' => 99], [], 'csrf-ok', $db, $storeEmblem);
$check(($missing['ok'] ?? true) === false && str_contains((string) ($missing['error'] ?? ''), 'not on this server'), 'a missing clan cannot be joined');
$check((int) $db->query('SELECT COUNT(*) FROM phyrian_clan_members')->fetchColumn() === 2, 'only the real join added a member');

$db->prepares = 0;
$page = ap_phyrian_clans_page_html(8, 'csrf-ok', $db);
$check(str_contains($page, 'Static alert(1)') && !str_contains($page, '<script>'), 'clan page escapes stored text');
$check(str_contains($page, 'Hello there &amp; more'), 'description is plain and escaped');
$check(str_contains($page, '@nova &lt;b&gt;') && str_contains($page, '@quiet_key'), 'member names are escaped');
$check(str_contains($page, '(you)') && str_contains($page, '(founder)'), 'the page shows membership and the founder');
$check(str_contains($page, 'value="phyrian_clan_leave"') && str_contains($page, 'name="csrf"'), 'a member gets a csrf-protected leave form');
$check(str_contains($page, 'https://mkultra.monster/emblem.png') && !str_contains($page, 'javascript:'), 'only an https emblem is shown');
$check($db->prepares === 3, 'the clans page reads clans, members, and emblems');
$db->exec("UPDATE masto_media SET public_url = 'javascript:alert(1)' WHERE local_id = 15");
$unsafe = ap_phyrian_clans_page_html(8, 'csrf-ok', $db);
$check(!str_contains($unsafe, 'javascript:') && !str_contains($unsafe, '<img'), 'a non-https emblem is not rendered');
$check(ap_phyrian_clans_nav_html('clans') !== '' && str_contains(ap_phyrian_clans_nav_html('hub'), '>Clans</a>'), 'the tab label is available while the switch is on');
$check(str_contains(ap_phyrian_clans_nav_html('clans'), 'aria-current="page"') && !str_contains(ap_phyrian_clans_nav_html('hub'), 'aria-current'), 'the clans tab is current only on that page');

$left = ap_phyrian_clans_mutate('phyrian_clan_leave', 8, ['clan_id' => 1], [], 'csrf-ok', $db, $storeEmblem);
$check(!empty($left['ok']), 'a member can leave');
$leftPage = ap_phyrian_clans_page_html(8, 'csrf-ok', $db);
$check(str_contains($leftPage, 'value="phyrian_clan_join"') && !str_contains($leftPage, '(you)'), 'leaving removes the membership');
$notMember = ap_phyrian_clans_mutate('phyrian_clan_leave', 8, ['clan_id' => 1], [], 'csrf-ok', $db, $storeEmblem);
$check(($notMember['ok'] ?? true) === false, 'leaving twice is refused');
$ownerPage = ap_phyrian_clans_page_html(4, 'csrf-ok', $db);
$check(str_contains($ownerPage, 'You already have a clan.') && !str_contains($ownerPage, 'phyrian_clan_create'), 'the founder does not get a second create form');

$noPlayers = true;
try {
    $db->query('SELECT COUNT(*) FROM phyrian_players');
    $noPlayers = false;
} catch (Throwable $e) {
    $noPlayers = true;
}
$check($noPlayers, 'clans do not create or read phyrian_players');

$bare = new PhyrianClansCountingPdo('sqlite::memory:');
$unavailable = ap_phyrian_clans_page_html(4, 'csrf-ok', $bare);
$check(str_contains($unavailable, 'not available yet') && !str_contains($unavailable, 'CREATE TABLE'), 'a missing table is an error, not a schema change');

$rejected = new PhyrianClansCountingPdo('sqlite::memory:');
$rejected->exec(
    'CREATE TABLE phyrian_clans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_user_id INTEGER NOT NULL UNIQUE,
        name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT "",
        emblem_media_id INTEGER,
        created_at TEXT NOT NULL
    )'
);
$rejected->exec(
    'CREATE TABLE phyrian_clan_members (
        clan_id INTEGER NOT NULL,
        owner_user_id INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        PRIMARY KEY (clan_id, owner_user_id)
    )'
);
$emblemCalls = [];
$badFile = ap_phyrian_clans_mutate(
    'phyrian_clan_create',
    4,
    ['name' => 'Broken'],
    ['name' => 'emblem.png', 'error' => UPLOAD_ERR_OK],
    'csrf-ok',
    $rejected,
    $failEmblem
);
$check(($badFile['ok'] ?? true) === false && str_contains((string) ($badFile['error'] ?? ''), 'emblem rejected'), 'a rejected emblem does not create the clan');
$check((int) $rejected->query('SELECT COUNT(*) FROM phyrian_clans')->fetchColumn() === 0, 'failed emblem leaves no clan row');

$migration = (string) file_get_contents(dirname(__DIR__) . '/migrations/20261014_phyrian_clans_postgres.sql');
$check(substr_count($migration, 'CREATE TABLE IF NOT EXISTS') === 2, 'migration creates both tables only if they are missing');
$check(str_contains($migration, 'phyrian_clans') && str_contains($migration, 'phyrian_clan_members'), 'migration names the clan tables');
$check(!str_contains($migration, 'phyrian_players') && !str_contains($migration, 'ALTER '), 'migration does not change existing player columns');
$check(!preg_match('/secret|password|api[_-]?key|BEGIN [A-Z0-9]/i', $migration), 'migration contains no secret material');

$admin = (string) file_get_contents(dirname(__DIR__) . '/ap-admin.php');
$check(substr_count($admin, "getenv('VAAK_PHYRIAN_CLANS')") === 4, 'admin checks the switch before the tab, the page, and the write');
$check(substr_count($admin, "require_once __DIR__ . '/ap-phyrian-clans.php'") === 3, 'admin loads the helper only from the gated branches');
$offset = 0;
for ($i = 0; $i < 3; $i++) {
    $requireAt = strpos($admin, "require_once __DIR__ . '/ap-phyrian-clans.php'", $offset);
    $flagAt = strrpos(substr($admin, 0, (int) $requireAt), "getenv('VAAK_PHYRIAN_CLANS')");
    $between = $flagAt === false ? '' : substr($admin, $flagAt, (int) $requireAt - $flagAt);
    $check($flagAt !== false && $flagAt < $requireAt, 'switch check precedes helper load ' . $i);
    $check(!str_contains($between, 'ap_db(') && !str_contains($between, 'phyrian_clan'), 'no clan query between the switch and the helper');
    $offset = (int) $requireAt + 1;
}
$check(str_contains($admin, "\$phyPages = ['hub', 'dossier'];") && str_contains($admin, "\$phyPages[] = 'clans';"), 'clans is added to the page list only after the switch');
$check(!str_contains($admin, "['hub', 'dossier', 'clans']"), 'clans is not always an allowed page');
$check(str_contains($admin, ">Your readout</a><?php\n              \$phyrianClansOn = getenv('VAAK_PHYRIAN_CLANS')"), 'clans tab is glued to the game nav');
$listAt = strpos($admin, "'phyrian_request', 'phyrian_accept'");
$list = $listAt === false ? '' : substr($admin, $listAt, 320);
$check(!str_contains($list, 'phyrian_clan'), 'clan actions are not on the ensure_player list');
$clanAction = strpos($admin, "'phyrian_clan_create', 'phyrian_clan_join', 'phyrian_clan_leave'");
$check($clanAction !== false && $listAt !== false && $clanAction < $listAt, 'clan writes are their own branch');
$clansBranchEnd = strpos($admin, '<?php else: /* hub */ ?>');
$clansBranchStart = strpos($admin, "<?php elseif (\$phyPage === 'clans'): ?>");
$clansBranch = ($clansBranchStart === false || $clansBranchEnd === false) ? '' : substr($admin, $clansBranchStart, $clansBranchEnd - $clansBranchStart);
$check(str_contains($clansBranch, 'ap_phyrian_clans_page_html') && !str_contains($clansBranch, 'phyrian-hud'), 'the clans page does not render the hub');
$hubEnd = strpos($admin, '<?php endif; /* hub */ ?>');
$hubBranch = ($clansBranchEnd === false || $hubEnd === false) ? '' : substr($admin, $clansBranchEnd, $hubEnd - $clansBranchEnd);
$check($hubBranch !== '' && !str_contains($hubBranch, 'ap_phyrian_clans'), 'the hub does not load clans');
$check(!str_contains($admin, 'FROM phyrian_players'), 'admin does not query players for clans');
$check(str_contains($admin, '.phyrian-game-nav {'), 'game nav stylesheet is still there');
$check(!str_contains($admin, '.phyrian-clans {'), 'clans do not add a global stylesheet rule');

$clans = (string) file_get_contents(dirname(__DIR__) . '/ap-phyrian-clans.php');
$navStart = strpos($clans, 'function ap_phyrian_clans_nav_html');
$navEnd = strpos($clans, 'function ap_phyrian_clans_db');
$navFn = ($navStart === false || $navEnd === false) ? '' : substr($clans, $navStart, $navEnd - $navStart);
$check($navFn !== '' && !str_contains($navFn, 'SELECT') && !str_contains($navFn, 'prepare'), 'the tab label does not query');
foreach (['phyrian_players', 'ap_phyrian_migrate', 'ap_phyrian_dossier', 'ap_phyrian_ensure_player', 'ap_phyrian_bridge_sync_from_opensim', 'ap_redis', 'ALTER '] as $forbidden) {
    $check(!str_contains($clans, $forbidden), 'clans helper does not contain ' . trim($forbidden));
}
$check(str_contains($clans, 'INSERT INTO phyrian_clans') && str_contains($clans, 'INSERT INTO phyrian_clan_members'), 'writes stay on the new clan tables');
$check(str_contains($clans, 'DELETE FROM phyrian_clan_members') && !str_contains($clans, 'DELETE FROM phyrian_clans'), 'leaving removes a membership and keeps the clan');
$check(str_contains($clans, 'ap_media_ingest_upload'), 'emblems go through the existing upload helper');

$game = (string) file_get_contents(dirname(__DIR__) . '/ap-phyrian.php');
$bridge = (string) file_get_contents(dirname(__DIR__) . '/ap-phyrian-bridge.php');
$check(!str_contains($game, 'VAAK_PHYRIAN_CLANS') && !str_contains($bridge, 'VAAK_PHYRIAN_CLANS'), 'game and opensim sync do not mention the clans switch');

if ($failures !== []) {
    fwrite(STDERR, "phyrian-clans-smoke FAIL (" . count($failures) . ")\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "phyrian-clans-smoke PASS ({$checks} checks)\n";
