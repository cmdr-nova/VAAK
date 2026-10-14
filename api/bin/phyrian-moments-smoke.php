<?php
declare(strict_types=1);

/**
 * Phyrian postable moments.
 * No Postgres, Redis, or object storage. The switch-off path must not touch
 * the database or render a card. A confirmed post reads one primary-key row
 * and hands a PNG plus alt text to an injected publisher.
 */

$GLOBALS['phyrian_moments_redis_hits'] = 0;

function ap_redis_json_get(string $key): ?array
{
    $GLOBALS['phyrian_moments_redis_hits']++;
    throw new RuntimeException('redis touched by phyrian moments');
}

function ap_redis_json_set(string $key, array $value, int $ttlSeconds): bool
{
    $GLOBALS['phyrian_moments_redis_hits']++;
    throw new RuntimeException('redis touched by phyrian moments');
}

function ap_auth_csrf_ok(?string $token): bool
{
    return $token === 'csrf-ok';
}

require_once dirname(__DIR__) . '/ap-phyrian-moments.php';

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

final class PhyrianMomentsForbidPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw new RuntimeException('database touched while phyrian moments must not look up a player');
    }
}

final class PhyrianMomentsCountingPdo extends PDO
{
    public int $prepares = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepares++;
        return parent::prepare($query, $options);
    }
}

$deliverCalls = [];
$throwingDeliver = static function (string $body, string $image, string $alt) use (&$deliverCalls): array {
    $deliverCalls[] = [$body, $image, $alt];
    throw new RuntimeException('publisher touched before the player confirmed');
};
$recordingDeliver = static function (string $body, string $image, string $alt) use (&$deliverCalls): array {
    $deliverCalls[] = ['body' => $body, 'image' => $image, 'alt' => $alt];
    return ['ok' => true];
};

$forbid = new PhyrianMomentsForbidPdo('sqlite::memory:');
$hub = '<div class="phyrian-actions"><button class="btn btn-primary" type="submit">Daily check-in</button></div>';

putenv('VAAK_PHYRIAN_MOMENTS');
ap_phyrian_moments_cache_reset();
$check(ap_phyrian_moments_enabled() === false, 'unset switch is off');
$offHub = ap_phyrian_moments_hub_html(['status' => 'imprinted', 'strain' => 'Rose', 'level' => 4], 'cmdr_nova', 'csrf-ok');
$check($offHub === '', 'switch off renders no share form');
$check($hub . $offHub === $hub, 'switch off leaves the hub actions unchanged');
$offPost = ap_phyrian_moments_publish(9, 'cmdr_nova', 'card', 'My card', true, 'csrf-ok', $forbid, $throwingDeliver);
$check($offPost['ok'] === false, 'switch off refuses to post');
$check(ap_phyrian_moments_lookup_count() === 0, 'switch off does no player lookup');
$check($deliverCalls === [], 'switch off does not publish');
$check($GLOBALS['phyrian_moments_redis_hits'] === 0, 'switch off does no redis work');

foreach (['0', 'false', 'off', 'no', ''] as $disabled) {
    putenv('VAAK_PHYRIAN_MOMENTS=' . $disabled);
    ap_phyrian_moments_cache_reset();
    $check(ap_phyrian_moments_enabled() === false, 'disabled value ' . ($disabled === '' ? 'empty' : $disabled));
    $check(ap_phyrian_moments_hub_html(['level' => 3], 'cmdr_nova', 'csrf-ok') === '', 'disabled value renders nothing');
}

putenv('VAAK_PHYRIAN_MOMENTS=1');
ap_phyrian_moments_cache_reset();
$check(ap_phyrian_moments_enabled() === true, 'switch on is enabled');

$badCsrf = ap_phyrian_moments_publish(9, 'cmdr_nova', 'card', 'My card', true, 'nope', $forbid, $throwingDeliver);
$check($badCsrf['ok'] === false && str_contains((string) ($badCsrf['error'] ?? ''), 'Session expired'), 'bad csrf is rejected');
$check(ap_phyrian_moments_lookup_count() === 0 && $deliverCalls === [], 'bad csrf does no lookup and does not publish');

$unconfirmed = ap_phyrian_moments_publish(9, 'cmdr_nova', 'card', 'My card', false, 'csrf-ok', $forbid, $throwingDeliver);
$check($unconfirmed['ok'] === false && str_contains((string) ($unconfirmed['error'] ?? ''), 'Confirm'), 'unconfirmed post is refused');
$check(ap_phyrian_moments_lookup_count() === 0 && $deliverCalls === [], 'unconfirmed post does no lookup and does not publish');

$empty = ap_phyrian_moments_publish(9, 'cmdr_nova', 'card', '   ', true, 'csrf-ok', $forbid, $throwingDeliver);
$check($empty['ok'] === false, 'empty note is refused');
$check(ap_phyrian_moments_lookup_count() === 0 && $deliverCalls === [], 'empty note does no lookup');

$urlOnly = ap_phyrian_moments_publish(9, 'cmdr_nova', 'card', 'https://mkultra.monster/users/cmdr_nova', true, 'csrf-ok', $forbid, $throwingDeliver);
$check($urlOnly['ok'] === false, 'a profile link alone is not a note');
$check(ap_phyrian_moments_lookup_count() === 0, 'profile link alone does no lookup');

$unknown = ap_phyrian_moments_publish(9, 'cmdr_nova', 'loot', 'Found something', true, 'csrf-ok', $forbid, $throwingDeliver);
$check($unknown['ok'] === false, 'unknown moment kind is refused');
$check(ap_phyrian_moments_lookup_count() === 0, 'unknown kind does no lookup');

$signedOut = ap_phyrian_moments_publish(0, 'cmdr_nova', 'card', 'My card', true, 'csrf-ok', $forbid, $throwingDeliver);
$check($signedOut['ok'] === false && str_contains((string) ($signedOut['error'] ?? ''), 'Not signed in'), 'missing owner is refused');
$check(ap_phyrian_moments_lookup_count() === 0, 'missing owner does no lookup');

$db = new PhyrianMomentsCountingPdo('sqlite::memory:');
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
        daily_resonance_decay INTEGER
    )'
);
$db->exec(
    "INSERT INTO phyrian_players
        (owner_user_id, status, strain, resonance, generation, level, banked_resonance, resonance_exchanges, inductions_given, daily_resonance_decay)
     VALUES
        (4, 'unknown', '', 0, 1, 1, 0, 0, 0, 5),
        (8, 'imprinted', 'Quiet', 10, 2, 1, 0, 0, 0, 5),
        (9, 'imprinted', 'Cosmic Alien <script>', 40, 3, 12, 8, 2, 1, 5)"
);

$profile = 'https://mkultra.monster/users/cmdr_nova';
$unmarkedPlayer = ['status' => 'unknown', 'strain' => '', 'level' => 1, 'resonance' => 0, 'resonance_exchanges' => 0, 'inductions_given' => 0];
$check(ap_phyrian_moments_suggestion('level_up', $unmarkedPlayer, $profile) === null, 'unmarked player has no level-up moment');
$check(ap_phyrian_moments_suggestion('rare_find', $unmarkedPlayer, $profile) === null, 'unmarked player has no rare-find moment');
$check(ap_phyrian_moments_suggestion('win', $unmarkedPlayer, $profile) === null, 'unmarked player has no win moment');
$check(ap_phyrian_moments_suggestion('card', $unmarkedPlayer, $profile) === 'My Phyrian Strains card. ' . $profile, 'unmarked player can still post the card');

$fresh = ['status' => 'imprinted', 'strain' => 'Quiet', 'level' => 1, 'resonance' => 10, 'resonance_exchanges' => 0, 'inductions_given' => 0];
$check(ap_phyrian_moments_suggestion('level_up', $fresh, $profile) === null, 'level 1 has no level-up moment');
$check(ap_phyrian_moments_suggestion('win', $fresh, $profile) === null, 'low resonance with no exchange is not a win');
$check(is_string(ap_phyrian_moments_suggestion('rare_find', $fresh, $profile)), 'an imprinted strain can be shared as a rare find');

$ready = [
    'status' => 'imprinted',
    'strain' => 'Cosmic Alien <script>',
    'level' => 12,
    'resonance' => 40,
    'resonance_exchanges' => 2,
    'inductions_given' => 1,
];
$check(AP_PHYRIAN_MOMENTS_ALLOWED === ['card'], 'only the strains card is on the post allowlist');
$check(is_string(ap_phyrian_moments_suggestion('level_up', $ready, $profile)), 'level-up copy stays available to turn back on');
$check(is_string(ap_phyrian_moments_suggestion('rare_find', $ready, $profile)), 'rare-find copy stays available to turn back on');
$check(is_string(ap_phyrian_moments_suggestion('win', $ready, $profile)), 'win copy stays available to turn back on');

ap_phyrian_moments_cache_reset();
$db->prepares = 0;
$deliverCalls = [];
foreach (['level_up', 'rare_find', 'win'] as $hiddenKind) {
    $hiddenPost = ap_phyrian_moments_publish(9, 'cmdr_nova', $hiddenKind, 'Sharing this', true, 'csrf-ok', $db, $recordingDeliver);
    $check($hiddenPost['ok'] === false, $hiddenKind . ' is refused while it is off the allowlist');
}
$check(ap_phyrian_moments_lookup_count() === 0 && $db->prepares === 0, 'hidden moments do no player lookup');
$check($deliverCalls === [], 'hidden moments do not publish');

ap_phyrian_moments_cache_reset();
$db->prepares = 0;
$deliverCalls = [];
$card = ap_phyrian_moments_publish(4, 'cmdr_nova', 'card', 'Sharing my card <script>alert(1)</script>', true, 'csrf-ok', $db, $recordingDeliver);
$check(!empty($card['ok']), 'unmarked player can post the card after confirming');
$check(ap_phyrian_moments_lookup_count() === 1 && $db->prepares === 1, 'confirmed card is one primary-key lookup');
$check(count($deliverCalls) === 1, 'confirmed card publishes once');
$postedText = (string) ($deliverCalls[0]['body'] ?? '');
$postedImage = (string) ($deliverCalls[0]['image'] ?? '');
$postedAlt = (string) ($deliverCalls[0]['alt'] ?? '');
$check(!str_contains($postedText, '<'), 'posted note strips tags');
$check(str_contains($postedText, 'Sharing my card') && str_contains($postedText, $profile), 'posted note keeps the words and the profile link');
$check(str_contains($postedAlt, 'Not imprinted yet.'), 'card alt states that the player is not imprinted');
$check(str_starts_with($postedImage, "\x89PNG\r\n\x1a\n"), 'card image is a PNG');

$pngInfo = static function (string $png): array {
    if (!str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
        return ['ok' => false];
    }
    $offset = 8;
    $width = 0;
    $height = 0;
    $sawIend = false;
    $length = strlen($png);
    while ($offset + 12 <= $length) {
        $len = unpack('N', substr($png, $offset, 4))[1];
        $type = substr($png, $offset + 4, 4);
        if ($offset + 12 + $len > $length) {
            return ['ok' => false];
        }
        $data = substr($png, $offset + 8, $len);
        $crc = substr($png, $offset + 8 + $len, 4);
        if ($crc !== pack('N', crc32($type . $data))) {
            return ['ok' => false, 'crc' => $type];
        }
        if ($type === 'IHDR' && strlen($data) >= 5) {
            $ihdr = unpack('Nwidth/Nheight/Cdepth/Ccolor', $data);
            $width = (int) $ihdr['width'];
            $height = (int) $ihdr['height'];
            if ((int) $ihdr['depth'] !== 8 || (int) $ihdr['color'] !== 2) {
                return ['ok' => false, 'ihdr' => true];
            }
        }
        $offset += 12 + $len;
        if ($type === 'IEND') {
            $sawIend = true;
            break;
        }
    }
    return ['ok' => $sawIend && $width === 520 && $height >= 160, 'width' => $width, 'height' => $height];
};
$parsed = $pngInfo($postedImage);
$check(!empty($parsed['ok']), 'card PNG chunks and IHDR are valid');

$again = ap_phyrian_moments_publish(4, 'cmdr_nova', 'card', 'Sharing my card again', true, 'csrf-ok', $db, $recordingDeliver);
$check(!empty($again['ok']) && ap_phyrian_moments_lookup_count() === 1 && $db->prepares === 1, 'second confirmed post reuses the request memo');

$long = str_repeat('A', 800);
$longText = ap_phyrian_moments_plain($long, $profile);
$check(mb_strlen($longText) <= 500 && str_contains($longText, $profile), 'long notes stay within 500 characters and keep the profile link');

ap_phyrian_moments_cache_reset();
$db->prepares = 0;
$deliverCalls = [];
$shared = ap_phyrian_moments_publish(
    9,
    'cmdr_nova',
    'card',
    "My Phyrian Strains card. Cosmic Alien <script>\nhttps://mkultra.monster/users/cmdr_nova",
    true,
    'csrf-ok',
    $db,
    $recordingDeliver
);
$check(!empty($shared['ok']), 'imprinted card posts after confirmation');
$sharedText = (string) ($deliverCalls[0]['body'] ?? '');
$sharedAlt = (string) ($deliverCalls[0]['alt'] ?? '');
$check(str_contains($sharedText, 'Cosmic Alien') && !str_contains($sharedText, '<script>'), 'strain note is plain text');
$check(str_contains($sharedAlt, 'Cosmic Alien <script>') && str_contains($sharedAlt, 'level 12') && str_contains($sharedAlt, '@cmdr_nova'), 'alt text is built from the synced stats');
$check(substr_count($sharedText, $profile) === 1, 'profile link is not duplicated when the player already included it');
$check(ap_phyrian_moments_lookup_count() === 1, 'confirmed imprinted card is one lookup');

$failed = ap_phyrian_moments_publish(9, 'cmdr_nova', 'card', 'Won a resonance exchange', true, 'csrf-ok', $db, static function (): array {
    return ['ok' => false, 'error' => 'storage down'];
});
$check($failed['ok'] === false && str_contains((string) ($failed['error'] ?? ''), 'storage down'), 'a failed image post does not pretend to succeed');

$lookupsBeforeHub = ap_phyrian_moments_lookup_count();
$hubOn = ap_phyrian_moments_hub_html([
    'status' => 'imprinted',
    'strain' => 'Cosmic Alien <script>',
    'resonance' => 40,
    'generation' => 3,
    'level' => 12,
    'resonance_exchanges' => 2,
    'inductions_given' => 1,
], 'cmdr_nova', 'csrf-ok');
$check(str_contains($hubOn, 'Share a moment') && str_contains($hubOn, 'Post this moment'), 'hub offers a confirm button');
$check(str_contains($hubOn, 'name="confirm" value="1"') && str_contains($hubOn, 'name="action" value="phyrian_moment_post"'), 'hub posts only through the confirm action');
$check(!str_contains($hubOn, '<script>') && !str_contains($hubOn, 'Cosmic Alien'), 'hub card form does not print the strain');
$check(ap_phyrian_moments_h('Cosmic Alien <script>') === 'Cosmic Alien &lt;script&gt;', 'hub text is escaped before it is printed');
$check(substr_count($hubOn, '<form') === 1 && str_contains($hubOn, 'value="card"'), 'imprinted hub offers only the strains card');
$check(!str_contains($hubOn, 'value="level_up"') && !str_contains($hubOn, 'value="rare_find"') && !str_contains($hubOn, 'value="win"'), 'hub hides level-up, rare find, and win');
$check(str_contains($hubOn, 'My Phyrian Strains card.'), 'card share keeps its suggested note');
$check(ap_phyrian_moments_lookup_count() === $lookupsBeforeHub, 'hub form uses the player row it was given');

$hubUnmarked = ap_phyrian_moments_hub_html($unmarkedPlayer, 'cmdr_nova', 'csrf-ok');
$check(substr_count($hubUnmarked, '<form') === 1 && str_contains($hubUnmarked, 'value="card"'), 'unmarked hub offers only the card');
$check(str_contains($hubUnmarked, 'Nothing is posted until you confirm.'), 'hub tells the player that posting waits for confirmation');

$badKey = ap_phyrian_moments_profile_url('not a key');
$check($badKey === 'https://mkultra.monster/', 'unsafe actor keys fall back to the site home');

$admin = (string) file_get_contents(dirname(__DIR__) . '/ap-admin.php');
$check(substr_count($admin, "getenv('VAAK_PHYRIAN_MOMENTS')") === 2, 'admin checks the switch in the action and on the hub');
$check(substr_count($admin, "require_once __DIR__ . '/ap-phyrian-moments.php'") === 2, 'admin loads the helper only in those two places');
$offset = 0;
for ($i = 0; $i < 2; $i++) {
    $requireAt = strpos($admin, "require_once __DIR__ . '/ap-phyrian-moments.php'", $offset);
    $flagAt = strrpos(substr($admin, 0, (int) $requireAt), "getenv('VAAK_PHYRIAN_MOMENTS')");
    $between = $flagAt === false ? '' : substr($admin, $flagAt, (int) $requireAt - $flagAt);
    $check($flagAt !== false && $flagAt < $requireAt, 'switch check precedes helper load ' . $i);
    $check(!str_contains($between, 'ap_db(') && !str_contains($between, 'phyrian_players'), 'no database work between the switch and the helper');
    $offset = (int) $requireAt + 1;
}
$listAt = strpos($admin, "'phyrian_request', 'phyrian_accept'");
$list = $listAt === false ? '' : substr($admin, $listAt, 280);
$check(!str_contains($list, 'phyrian_moment_post'), 'moment posting is not on the ensure_player action list');
$momentAt = strpos($admin, "\$action === 'phyrian_moment_post'");
$check($momentAt !== false && $listAt !== false && $momentAt < $listAt, 'moment action is its own branch before the game mutations');
$check(str_contains($admin, "</div><?php\n                \$phyrianMomentsOn = getenv('VAAK_PHYRIAN_MOMENTS')"), 'hub share block is glued to the actions row');
$check(!str_contains($admin, 'FROM phyrian_players'), 'admin does not query players for moments');
$check(str_contains($admin, '.phyrian-actions { display: flex; flex-wrap: wrap; gap: .45rem; align-items: center; }'), 'hub actions stylesheet is unchanged');
$check(!str_contains($admin, '.phyrian-moments {'), 'moments do not add a global stylesheet rule');

$moments = (string) file_get_contents(dirname(__DIR__) . '/ap-phyrian-moments.php');
$check(str_contains($moments, "const AP_PHYRIAN_MOMENTS_ALLOWED = ['card']"), 'allowlist names only the strains card');
foreach (["'level_up' => 'Level-up'", "'rare_find' => 'Rare find'", "'win' => 'Win'", "'card' => 'Post my Strains card'"] as $keptLabel) {
    $check(str_contains($moments, $keptLabel), 'moment label stays in the helper: ' . $keptLabel);
}
$check(substr_count($moments, 'FROM phyrian_players') === 1, 'moments helper has one player read');
$check(str_contains($moments, 'WHERE owner_user_id = ?'), 'player read uses the primary key');
foreach (['ap_phyrian_migrate', 'ap_phyrian_dossier', 'ap_phyrian_ensure_player', 'ap_phyrian_bridge_sync_from_opensim', 'ap_redis', 'UPDATE ', 'DELETE ', 'ALTER '] as $forbidden) {
    $check(!str_contains($moments, $forbidden), 'moments helper does not contain ' . trim($forbidden));
}
$check(substr_count($moments, 'INSERT INTO masto_media') === 1, 'the only write is the media row for a confirmed card image');
$hubStart = strpos($moments, 'function ap_phyrian_moments_hub_html');
$hubEnd = strpos($moments, 'function ap_phyrian_moments_publish');
$hubFn = ($hubStart === false || $hubEnd === false) ? '' : substr($moments, $hubStart, $hubEnd - $hubStart);
$check($hubFn !== '' && !str_contains($hubFn, 'render_png'), 'the hub form does not render an image');

$game = (string) file_get_contents(dirname(__DIR__) . '/ap-phyrian.php');
$bridge = (string) file_get_contents(dirname(__DIR__) . '/ap-phyrian-bridge.php');
$check(!str_contains($game, 'VAAK_PHYRIAN_MOMENTS') && !str_contains($bridge, 'VAAK_PHYRIAN_MOMENTS'), 'game and opensim sync do not mention the moments switch');

if ($failures !== []) {
    fwrite(STDERR, "phyrian-moments-smoke FAIL (" . count($failures) . ")\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "phyrian-moments-smoke PASS ({$checks} checks)\n";
