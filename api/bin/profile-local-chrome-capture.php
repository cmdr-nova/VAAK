<?php
declare(strict_types=1);
/** Local profile chrome oracle for step 8. No network and no database.
 *  The HTML is the live ap-admin.php header, tab, pin, and Ask markup. */
if (!extension_loaded('mbstring')) {
    fwrite(STDERR, "profile chrome capture requires mbstring\n");
    exit(2);
}
require_once __DIR__ . '/migration-reference-functions.php';
if (!defined('AP_REMOTE_AVATAR_FALLBACK')) {
    define('AP_REMOTE_AVATAR_FALLBACK', 'https://mkultra.monster/img/avatar/default.webp');
}
function ap_fix_utf8(string $s): string { return $s; }
function ap_auth_csrf_token(): string { return (string) ($GLOBALS['chrome_case']['csrf'] ?? ''); }
function vaak_is_local_url(?string $url): bool
{
    return is_string($url) && preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', rtrim($url, '/')) === 1;
}
function vaak_actor_id(): string { return (string) ($GLOBALS['chrome_case']['viewer_actor'] ?? ''); }
function vaak_is_own_url(?string $url): bool
{
    return is_string($url) && rtrim($url, '/') !== '' && rtrim($url, '/') === rtrim(vaak_actor_id(), '/');
}
function admin_is_local_admin_actor(?string $actor): bool
{
    return !empty($GLOBALS['chrome_case']['target_is_admin']);
}
function ap_user_block_find(?string $actor, ?string $host, int $owner): ?array
{
    $id = $GLOBALS['chrome_case']['personal_block_id'] ?? null;
    return is_int($id) ? ['id' => $id] : null;
}
function ap_is_muted_actor(?string $actor, int $owner): bool { return !empty($GLOBALS['chrome_case']['muted']); }
function ap_is_deprioritized_actor(?string $actor, int $owner): bool { return !empty($GLOBALS['chrome_case']['deprioritized']); }
function admin_actor_avatar_url(?string $actorId): string
{
    return (string) ($GLOBALS['chrome_case']['fallback_avatar'] ?? AP_REMOTE_AVATAR_FALLBACK);
}
function ap_emoji_html(string $text, ?string $actorId = null, bool $allowFetch = false): string
{
    $map = $GLOBALS['chrome_case']['emoji'] ?? [];
    if ($text === '' || !is_array($map) || $map === [] || !preg_match('/:[A-Za-z0-9_-]{1,80}:/', $text)) {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    $out = '';
    $offset = 0;
    preg_match_all('/:([A-Za-z0-9_-]{1,80}):/', $text, $mm, PREG_OFFSET_CAPTURE);
    foreach ($mm[0] as $i => $full) {
        $start = (int) $full[1];
        $code = $mm[1][$i][0];
        if ($start > $offset) {
            $out .= htmlspecialchars(substr($text, $offset, $start - $offset), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $url = $map[$code] ?? null;
        if (is_string($url) && str_starts_with($url, 'https://')) {
            $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeAlt = htmlspecialchars(':' . $code . ':', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $out .= '<img class="custom-emoji" src="' . $safeUrl . '" alt="' . $safeAlt . '" title="'
                . $safeAlt . '" width="20" height="20" loading="lazy" decoding="async" referrerpolicy="no-referrer">';
        } else {
            $out .= htmlspecialchars($full[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $offset = $start + strlen($full[0]);
    }
    if ($offset < strlen($text)) {
        $out .= htmlspecialchars(substr($text, $offset), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    return $out;
}

$root = dirname(__DIR__);
require_once $root . '/ap-profile-html.php';
require_once $root . '/ap-listenbrainz.php';
reference_load($root . '/ap-admin.php', [
    'h', 'short_host', 'actor_handle', 'admin_emoji_html', 'admin_profile_hover_attr', 'admin_avatar_img',
    'admin_reply_mention_normalize', 'admin_reply_mention_handle', 'admin_report_href', 'block_quick_actions',
]);
reference_load($root . '/ap-db.php', ['ap_html_sanitize_allowlist', 'ap_profile_field_html', 'ap_profile_badge_catalog']);
reference_load($root . '/ap-sl-link.php', ['ap_sl_valid_agent_id', 'ap_sl_viewer_profile_url']);
reference_load($root . '/ap-phyrian-bridge.php', ['ap_phyrian_bridge_resonant_badge_html']);

$admin = (string) file_get_contents($root . '/ap-admin.php');
function chrome_between(string $source, string $start, string $end): string
{
    $from = strpos($source, $start);
    $to = strpos($source, $end, $from === false ? 0 : $from);
    if ($from === false || $to === false || $to <= $from) {
        throw new RuntimeException('profile chrome fragment missing: ' . $start);
    }
    return substr($source, $from, $to - $from);
}
$pageBack = chrome_between($admin, "          <?php if (empty(\$GLOBALS['vaak_guest_profile'])): ?>\n          <div class=\"page-back\">", "<?php\n            } /* profile-page-back */");
$header = chrome_between($admin, '          <?php if ($rpHeader): ?>', '<?php } /* profile-header */ ?>');
$asks = chrome_between($admin, "          <?php if (\$rpTab === 'asks'): ?>", "          <?php elseif (\$rpTab === 'pinned'): ?>") . "<?php endif; ?>\n";
$pins = chrome_between($admin, "          <?php elseif (\$rpTab === 'pinned'): ?>", "          <?php elseif (\$rpTab === 'featured'): ?>");
$pins = (preg_replace('/<\?php elseif/', '<?php if', $pins, 1) ?? $pins) . "<?php endif; ?>\n";

function chrome_eval(string $code, array $vars): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    eval('?>' . $code);
    return (string) ob_get_clean();
}

/** @param array<string,mixed> $case */
function chrome_vars(array $case): array
{
    $GLOBALS['chrome_case'] = $case;
    $GLOBALS['vaak_guest_profile'] = false;
    $actor = (string) $case['actor'];
    $key = (string) $case['key'];
    $from = (string) $case['from'];
    $tab = (string) $case['tab'];
    $meta = ['display_name' => $case['display_name'], 'username' => $case['username']];
    $items = ['posts' => array_fill(0, (int) $case['item_posts'], 1), 'replies' => array_fill(0, (int) $case['item_replies'], 1), 'boosts' => array_fill(0, (int) $case['item_boosts'], 1), 'media' => array_fill(0, (int) $case['item_media'], 1), 'asks' => array_fill(0, (int) $case['item_asks'], 1)];
    $pinned = array_fill(0, (int) $case['pin_rows'], ['id' => 'pin']);
    $featured = array_fill(0, (int) $case['featured_rows'], ['actor_id' => 'https://mkultra.monster/users/other']);
    $blogs = array_fill(0, (int) $case['blog_rows'], ['slug' => 'note']);
    $href = '/users/' . rawurlencode($key) . ($from !== '' ? ('?from=' . rawurlencode($from) . '&tab=') : '?tab=');
    $back = match ($from) {
        'mentions' => ['?view=mentions', '← Notifications', 'mentions'],
        'search' => ['?view=search', '← Search', 'search'],
        'followers' => ['?view=followers', '← Followers', 'followers'],
        'home' => ['?view=home', '← Home', 'home'],
        'feed' => ['?view=feed', '← Federated', 'feed'],
        'blocks' => ['?view=blocks', '← Blocks & mutes', ''],
        'about' => ['?view=about', '← About VAAK', ''],
        default => ['?view=following', '← Following', 'following'],
    };
    return [
        'rpActor' => $actor, 'rpLocalKey' => $key, 'rpIsLocal' => true, 'rpIsBsky' => false, 'rpBskyHandle' => '',
        'rpFrom' => $from, 'rpTab' => $tab, 'rpTabHref' => $href, 'rpBackHref' => $back[0], 'rpBackLabel' => $back[1], 'rpBackView' => $back[2],
        'rpMeta' => $meta, 'rpHeader' => $case['banner'], 'rpAvatar' => $case['avatar'], 'rpBio' => (string) $case['bio'],
        'rpIsOwn' => !empty($case['is_own']), 'rpFollowing' => !empty($case['following']), 'rpFollowsYou' => !empty($case['follows_you']),
        'rpRequested' => !empty($case['requested']), 'rpLocked' => !empty($case['locked']),
        'rpRel' => !empty($case['following']) ? (!empty($case['follows_you']) ? 'mutual' : 'following') : (!empty($case['follows_you']) ? 'follows_you' : 'none'),
        'rpMuted' => !empty($case['muted']), 'rpBlockedPersonal' => !empty($case['blocked_personal']), 'rpBlockedServer' => !empty($case['blocked_server']),
        'rpPostSub' => !empty($case['subscribed']), 'rpPhyrianBridge' => $case['bridge'], 'rpSlLink' => $case['sl'], 'rpWowLink' => $case['wow'],
        'rpProfileFields' => $case['fields'], 'rpProfileBadges' => $case['badges'], 'rpNowPlaying' => $case['now_playing'],
        'rpHasCombinedStats' => true, 'rpBskyStatsHandle' => $case['stats_bsky'] ? 'ada.example' : null,
        'rpPostsStat' => (int) $case['posts_stat'], 'rpFollowingStat' => (int) $case['following_stat'], 'rpFollowersStat' => (int) $case['followers_stat'],
        'rpTabItems' => $items, 'rpOutboxTotal' => (int) $case['outbox_total'], 'rpOutboxRepliesTotal' => (int) $case['replies_total'],
        'rpHideProfileReplies' => !empty($case['hide_replies']), 'rpBoostTotal' => (int) $case['boost_total'], 'rpHideProfileBoosts' => !empty($case['hide_boosts']),
        'rpBskyPostCount' => (int) $case['bsky_posts'], 'rpAnsweredAskCount' => (int) $case['ask_count'], 'rpAnsweredAskRows' => array_fill(0, (int) $case['ask_rows'], ['id' => 1]),
        'rpPinnedStatuses' => $pinned, 'rpPinnedCount' => (int) $case['pin_count'], 'rpFeaturedCards' => $featured, 'rpFeaturedCount' => (int) $case['featured_count'],
        'rpBlogPosts' => $blogs, 'rpBlogPost' => !empty($case['blog_open']) ? ['slug' => 'open'] : null, 'rpBlogCount' => (int) $case['blog_count'],
        'vaakOwnerId' => (int) $case['viewer_id'], 'vaakIsAdmin' => !empty($case['viewer_is_admin']),
    ];
}

function chrome_case(string $id, array $over = []): array
{
    return $over + [
        'id' => $id, 'actor' => 'https://mkultra.monster/users/ada', 'key' => 'ada', 'viewer_actor' => 'https://mkultra.monster/users/viewer',
        'viewer_id' => 7, 'from' => 'search', 'tab' => 'posts', 'display_name' => 'Ada Lovelace', 'username' => 'ada',
        'bio' => 'Analytical engines & notes', 'avatar' => 'https://cdn.example/ada.webp', 'banner' => 'https://cdn.example/banner.webp',
        'fallback_avatar' => 'https://mkultra.monster/img/avatar/default.webp', 'emoji' => [], 'is_own' => false,
        'following' => false, 'follows_you' => false, 'requested' => false, 'locked' => false, 'muted' => false,
        'blocked_personal' => false, 'blocked_server' => false, 'subscribed' => false, 'personal_block_id' => null,
        'deprioritized' => false, 'target_is_admin' => false, 'viewer_is_admin' => false, 'csrf' => 'csrf&<>"\'',
        'bridge' => null, 'sl' => null, 'wow' => null, 'fields' => [], 'badges' => [], 'stats_bsky' => false,
        'posts_stat' => 3, 'following_stat' => 4, 'followers_stat' => 5, 'item_posts' => 1, 'item_replies' => 0,
        'item_boosts' => 0, 'item_media' => 0, 'item_asks' => 0, 'outbox_total' => 3, 'replies_total' => 2,
        'hide_replies' => false, 'boost_total' => 1, 'hide_boosts' => false, 'bsky_posts' => 0, 'ask_count' => 0,
        'ask_rows' => 0, 'pin_rows' => 0, 'pin_count' => 0, 'featured_rows' => 0, 'featured_count' => 0,
        'blog_rows' => 0, 'blog_count' => 0, 'blog_open' => false, 'now_playing' => null,
    ];
}

$cases = [
    chrome_case('peer'),
    chrome_case('own', ['is_own' => true, 'viewer_actor' => 'https://mkultra.monster/users/ada', 'from' => 'home', 'display_name' => '0', 'username' => 'ada', 'bio' => '', 'avatar' => null, 'banner' => null]),
    chrome_case('follow_back', ['follows_you' => true, 'from' => 'followers']),
    chrome_case('following', ['following' => true, 'follows_you' => true, 'from' => '']),
    chrome_case('requested', ['requested' => true, 'locked' => true, 'from' => 'blocks']),
    chrome_case('locked', ['locked' => true, 'from' => 'about']),
    chrome_case('muted_blocked', ['muted' => true, 'blocked_personal' => true, 'blocked_server' => true, 'personal_block_id' => 9, 'deprioritized' => true, 'subscribed' => true, 'from' => 'mentions']),
    chrome_case('admin_target', ['target_is_admin' => true, 'viewer_is_admin' => true, 'from' => 'feed']),
    chrome_case('badges_fields', [
        'display_name' => 'Ada :wave:',
        'emoji' => ['wave' => 'https://cdn.example/wave.png'],
        'bio' => "line <one> & two",
        'fields' => [
            ['name' => 'Site', 'value' => '<a href="https://ada.example/notes">notes</a>', 'verified_at' => '2026-01-02'],
            ['name' => ' ', 'value' => 'skip'],
            ['name' => 'Plain', 'value' => 'Tom & "Jerry\'s" <tag>'],
        ],
        'badges' => ['supporter', 'not-a-badge', 'cat'],
        'bridge' => ['avatar_name' => 'Nova & Co', 'strain_snapshot' => 'A <strain>'],
        'sl' => ['sl_username' => 'Ada Resident', 'sl_agent_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'],
        'wow' => ['character_name' => 'Ada', 'realm' => 'Area 52', 'armory_url' => 'https://worldofwarcraft.blizzard.com/en-us/character/area-52/ada', 'portrait_url' => 'https://cdn.example/wow.webp'],
        'stats_bsky' => true,
        'hide_replies' => true,
        'tab' => 'replies',
    ]),
    chrome_case('tabs', [
        'pin_count' => 2, 'pin_rows' => 0, 'tab' => 'pinned', 'featured_count' => 1, 'blog_count' => 4,
        'ask_count' => 6, 'hide_boosts' => true, 'item_posts' => 2, 'outbox_total' => 9, 'bsky_posts' => 11,
        'from' => 'search', 'key' => 'ada_l', 'actor' => 'https://mkultra.monster/users/ada_l',
    ]),
    chrome_case('asks_empty', ['tab' => 'asks', 'ask_count' => 0, 'from' => 'search']),
    chrome_case('asks_filled', ['tab' => 'asks', 'ask_count' => 2, 'ask_rows' => 2, 'item_asks' => 2]),
    chrome_case('pins_filled', ['tab' => 'pinned', 'pin_rows' => 2, 'pin_count' => 2]),
    chrome_case('now_playing', ['badges' => ['cat'], 'now_playing' => ['label' => 'Now playing', 'track' => 'Tom & "Jerry\'s" <tag>', 'artist' => 'Björk', 'release' => 'Post', 'cover' => 'https://coverartarchive.org/release/0a1b2c3d-0000-4000-8000-000000000000/front-250', 'url' => 'https://listenbrainz.org/user/nova/']]),
    chrome_case('last_played_no_badges', ['now_playing' => ['label' => 'Last played', 'track' => 'Roygbiv', 'artist' => '', 'release' => '', 'cover' => '', 'url' => 'https://listenbrainz.org/user/Nova%20Bee/']]),
    chrome_case('wow_text', ['wow' => ['character_name' => 'No Portrait', 'realm' => '', 'armory_url' => 'https://worldofwarcraft.blizzard.com/en-us/character/area-52/nop', 'portrait_url' => ''], 'sl' => ['sl_username' => 'Bad Id', 'sl_agent_id' => 'not-a-uuid']]),
];

global $pageBack, $header, $asks, $pins;
$out = [];
foreach ($cases as $case) {
    $vars = chrome_vars($case);
    $chrome = chrome_eval($pageBack, $vars) . chrome_eval($header, $vars);
    $secondary = null;
    if ($case['tab'] === 'asks' && (int) $case['item_asks'] === 0) {
        $secondary = chrome_eval($asks, $vars);
    } elseif ($case['tab'] === 'pinned' && (int) $case['pin_rows'] === 0 && (int) $case['pin_count'] > 0) {
        $secondary = chrome_eval($pins, $vars);
    }
    if ($chrome === '' || !str_contains($chrome, 'remote-profile-tabs') || !str_contains($chrome, '>local<')) {
        throw new RuntimeException($case['id'] . ' did not paint a local profile header');
    }
    if (str_contains($chrome, '>Asks<') !== ((int) $case['ask_count'] > 0 || (int) $case['ask_rows'] > 0)) {
        throw new RuntimeException($case['id'] . ' Asks tab must show exactly when the account has answered Asks');
    }
    $out[] = [
        'id' => $case['id'],
        'input' => $case,
        'chrome' => $chrome,
        'secondary' => $secondary,
        'output' => ['chrome' => $chrome, 'secondary' => $secondary],
    ];
}
$byId = [];
foreach ($out as $row) {
    $byId[$row['id']] = $row;
}
if (!str_contains($byId['own']['chrome'], 'This is you') || str_contains($byId['own']['chrome'], 'name="action" value="follow_remote"')) {
    throw new RuntimeException('own profile relationship row drifted');
}
if (!str_contains($byId['follow_back']['chrome'], '>Follow back<') || !str_contains($byId['following']['chrome'], '>mutuals<')) {
    throw new RuntimeException('follow relationship labels drifted');
}
if (!str_contains($byId['requested']['chrome'], 'Cancel this follow request?') || !str_contains($byId['muted_blocked']['chrome'], 'muted for me') || !str_contains($byId['muted_blocked']['chrome'], 'Unblock')) {
    throw new RuntimeException('requested and moderation row drifted');
}
if (!str_contains($byId['badges_fields']['chrome'], 'custom-emoji') || !str_contains($byId['badges_fields']['chrome'], '◈ Resonant') || !str_contains($byId['badges_fields']['chrome'], 'Cat person')) {
    throw new RuntimeException('emoji, resonant badge, or catalog badge drifted');
}
if (!str_contains($byId['now_playing']['chrome'], '<div class="profile-now-playing" data-state="playing">') || !str_contains($byId['last_played_no_badges']['chrome'], '>Last played<') || str_contains($byId['peer']['chrome'], 'profile-now-playing')) {
    throw new RuntimeException('ListenBrainz now playing block drifted');
}
$slIcon = dirname(__DIR__, 2) . '/vaak/second-life.svg';
if (!is_file($slIcon)) {
    throw new RuntimeException('shipped Second Life icon missing: ' . $slIcon);
}
foreach (['badges_fields', 'wow_text'] as $slCase) {
    $slChrome = $byId[$slCase]['chrome'];
    if (!str_contains($slChrome, '<img src="/vaak/second-life.svg" alt="Second Life" width="43" height="34" loading="lazy">') || str_contains($slChrome, 'second-life.jpg') || str_contains($slChrome, 'second-life.png')) {
        throw new RuntimeException($slCase . ' Second Life icon must use vaak/second-life.svg at 43x34');
    }
}
if (!str_contains($byId['tabs']['chrome'], '>Pinned<') || str_contains($byId['tabs']['chrome'], '>Boosts<') || !str_contains($byId['tabs']['chrome'], '>Replies<') || !str_contains($byId['asks_empty']['secondary'] ?? '', 'No answered Asks yet.') || !str_contains($byId['tabs']['secondary'] ?? '', 'No pinned posts.')) {
    throw new RuntimeException('tab visibility or empty pin/ask body drifted');
}
if ($byId['asks_filled']['secondary'] !== null || $byId['pins_filled']['secondary'] !== null) {
    throw new RuntimeException('filled pin or ask cards must stay on the PHP card painters');
}
echo json_encode(['cases' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
