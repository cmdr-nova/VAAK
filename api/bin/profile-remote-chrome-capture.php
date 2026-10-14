<?php
declare(strict_types=1);
/** Remote ActivityPub profile chrome oracle for step 9. No network and no database.
 *  The HTML is the live ap-admin.php header and tab markup with a remote actor. */
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
function admin_global_actor_control(string $actorId): ?array
{
    $row = $GLOBALS['chrome_case']['server_control'] ?? null;
    return is_array($row) ? $row : null;
}
function ap_wafrn_actor_host_known(string $actor): bool
{
    return !empty($GLOBALS['chrome_case']['wafrn_known']);
}

$root = dirname(__DIR__);
require_once $root . '/ap-profile-html.php';
reference_load($root . '/ap-admin.php', [
    'h', 'short_host', 'actor_handle', 'admin_emoji_html', 'admin_profile_hover_attr', 'admin_avatar_img',
    'admin_reply_mention_normalize', 'admin_reply_mention_handle', 'admin_report_href', 'block_quick_actions',
    'admin_remote_actor_href', 'admin_open_profile_label',
]);
reference_load($root . '/ap-db.php', [
    'ap_html_sanitize_allowlist', 'ap_profile_field_html', 'ap_profile_badge_catalog',
    'ap_remote_actor_normalize_username', 'ap_remote_actor_username_is_placeholder',
]);
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
    $from = (string) $case['from'];
    $tab = (string) $case['tab'];
    $meta = ['display_name' => $case['display_name'], 'username' => $case['username']];
    $items = [
        'posts' => array_fill(0, (int) $case['item_posts'], 1),
        'replies' => array_fill(0, (int) $case['item_replies'], 1),
        'boosts' => array_fill(0, (int) $case['item_boosts'], 1),
        'media' => array_fill(0, (int) $case['item_media'], 1),
        'asks' => [],
    ];
    $href = '?view=remote_profile&actor=' . rawurlencode($actor) . '&from=' . rawurlencode($from) . '&tab=';
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
        'rpActor' => $actor, 'rpLocalKey' => null, 'rpIsLocal' => false, 'rpIsBsky' => false, 'rpBskyHandle' => '',
        'rpFrom' => $from, 'rpTab' => $tab, 'rpTabHref' => $href, 'rpBackHref' => $back[0], 'rpBackLabel' => $back[1], 'rpBackView' => $back[2],
        'rpMeta' => $meta, 'rpHeader' => $case['banner'], 'rpAvatar' => $case['avatar'], 'rpBio' => (string) $case['bio'],
        'rpIsOwn' => !empty($case['is_own']), 'rpFollowing' => !empty($case['following']), 'rpFollowsYou' => !empty($case['follows_you']),
        'rpRequested' => !empty($case['requested']), 'rpLocked' => !empty($case['locked']),
        'rpRel' => !empty($case['following']) ? (!empty($case['follows_you']) ? 'mutual' : 'following') : (!empty($case['follows_you']) ? 'follows_you' : 'none'),
        'rpMuted' => !empty($case['muted']), 'rpBlockedPersonal' => !empty($case['blocked_personal']), 'rpBlockedServer' => !empty($case['blocked_server']),
        'rpPostSub' => false, 'rpPhyrianBridge' => null, 'rpSlLink' => null, 'rpWowLink' => null,
        'rpProfileFields' => $case['fields'], 'rpProfileBadges' => $case['badges'],
        'rpHasCombinedStats' => false, 'rpBskyStatsHandle' => null,
        'rpPostsStat' => (int) $case['posts_stat'], 'rpFollowingStat' => (int) $case['following_stat'], 'rpFollowersStat' => (int) $case['followers_stat'],
        'rpTabItems' => $items, 'rpOutboxTotal' => (int) $case['outbox_total'], 'rpOutboxRepliesTotal' => (int) $case['replies_total'],
        'rpHideProfileReplies' => !empty($case['hide_replies']), 'rpBoostTotal' => (int) $case['boost_total'], 'rpHideProfileBoosts' => !empty($case['hide_boosts']),
        'rpBskyPostCount' => (int) $case['bsky_posts'], 'rpAnsweredAskCount' => 0, 'rpAnsweredAskRows' => [],
        'rpPinnedStatuses' => [], 'rpPinnedCount' => (int) $case['pin_count'], 'rpFeaturedCards' => [], 'rpFeaturedCount' => (int) $case['featured_count'],
        'rpBlogPosts' => [], 'rpBlogPost' => null, 'rpBlogCount' => (int) $case['blog_count'],
        'vaakOwnerId' => (int) $case['viewer_id'], 'vaakIsAdmin' => !empty($case['viewer_is_admin']),
    ];
}

function chrome_case(string $id, array $over = []): array
{
    return $over + [
        'id' => $id, 'actor' => 'https://remote.example/users/ada', 'key' => '', 'viewer_actor' => 'https://mkultra.monster/users/viewer',
        'viewer_id' => 7, 'from' => 'search', 'tab' => 'posts', 'display_name' => 'Ada Lovelace', 'username' => 'ada',
        'bio' => 'Analytical engines & notes', 'avatar' => 'https://cdn.example/ada.webp', 'banner' => 'https://cdn.example/banner.webp',
        'fallback_avatar' => 'https://mkultra.monster/img/avatar/default.webp', 'emoji' => [], 'is_own' => false,
        'following' => false, 'follows_you' => false, 'requested' => false, 'locked' => false, 'muted' => false,
        'blocked_personal' => false, 'blocked_server' => false, 'personal_block_id' => null,
        'deprioritized' => false, 'target_is_admin' => false, 'viewer_is_admin' => false, 'wafrn_known' => false,
        'server_control' => null, 'csrf' => 'csrf&<>"\'',
        'fields' => [], 'badges' => ['supporter', 'cat'], 'posts_stat' => 30, 'following_stat' => 40, 'followers_stat' => 50,
        'item_posts' => 2, 'item_replies' => 1, 'item_boosts' => 0, 'item_media' => 3,
        'outbox_total' => 99, 'replies_total' => 88, 'hide_replies' => false, 'boost_total' => 77, 'hide_boosts' => false,
        'bsky_posts' => 66, 'pin_count' => 4, 'featured_count' => 5, 'blog_count' => 6,
    ];
}

$cases = [
    chrome_case('peer'),
    chrome_case('own', [
        'is_own' => true, 'viewer_actor' => 'https://remote.example/users/ada', 'from' => 'home',
        'display_name' => '0', 'username' => 'ada', 'bio' => '', 'avatar' => null, 'banner' => null,
    ]),
    chrome_case('follow_back', ['follows_you' => true, 'from' => 'followers']),
    chrome_case('following', ['following' => true, 'follows_you' => true, 'from' => '']),
    chrome_case('requested', ['requested' => true, 'locked' => true, 'from' => 'blocks']),
    chrome_case('cold', [
        'display_name' => '0', 'username' => '', 'bio' => '', 'avatar' => null, 'banner' => null, 'from' => 'about',
        'actor' => 'https://remote.example/users/ada',
    ]),
    chrome_case('fields_emoji', [
        'display_name' => 'Ada :wave:',
        'emoji' => ['wave' => 'https://cdn.example/wave.png'],
        'bio' => "line <one> & two",
        'username' => '@Ada@ignored.example',
        'fields' => [
            ['name' => 'Site', 'value' => '<a href="https://ada.example/notes">notes</a>', 'verified_at' => '2026-01-02'],
            ['name' => ' ', 'value' => 'skip'],
            ['name' => 'Plain', 'value' => 'Tom & "Jerry\'s" <tag>'],
            ['name' => 'Encoded', 'value' => '&lt;a href=&quot;https://ada.example/lab&quot;&gt;lab&lt;/a&gt;'],
        ],
    ]),
    chrome_case('muted_blocked', [
        'muted' => true, 'blocked_personal' => true, 'blocked_server' => true, 'personal_block_id' => 9,
        'deprioritized' => true, 'from' => 'mentions',
    ]),
    chrome_case('admin_open', ['viewer_is_admin' => true, 'from' => 'feed']),
    chrome_case('admin_mute', ['viewer_is_admin' => true, 'server_control' => ['id' => 4, 'kind' => 'mute']]),
    chrome_case('admin_block', ['viewer_is_admin' => true, 'server_control' => ['id' => 8, 'kind' => 'block']]),
    chrome_case('admin_suspend', ['viewer_is_admin' => true, 'server_control' => ['id' => 3, 'kind' => 'suspend']]),
    chrome_case('tabs', [
        'hide_replies' => true, 'hide_boosts' => true, 'tab' => 'media',
        'item_posts' => 2, 'item_replies' => 1, 'item_boosts' => 4, 'item_media' => 3,
        'actor' => 'https://remote.example/users/ada_l', 'username' => 'ada_l',
    ]),
    chrome_case('username_user', ['username' => 'user', 'from' => 'search']),
    chrome_case('wafrn', ['wafrn_known' => true, 'actor' => 'https://wafrn.example/users/ada', 'username' => 'ada']),
    chrome_case('placeholder_id', ['username' => '123456789', 'actor' => 'https://remote.example/users/123456789']),
];

global $pageBack, $header;
$out = [];
foreach ($cases as $case) {
    $vars = chrome_vars($case);
    $chrome = chrome_eval($pageBack, $vars) . chrome_eval($header, $vars);
    if ($chrome === '' || !str_contains($chrome, 'remote-profile-tabs') || str_contains($chrome, '>local<') || str_contains($chrome, '>Bluesky<')) {
        throw new RuntimeException($case['id'] . ' did not paint a remote ActivityPub profile header');
    }
    if (str_contains($chrome, 'remote-profile-stats') || str_contains($chrome, '>Subscribe<') || str_contains($chrome, '>Pinned<') || str_contains($chrome, '>Asks<')) {
        throw new RuntimeException($case['id'] . ' included local-only chrome');
    }
    if (!str_contains($chrome, '↻ Refresh profile') || !str_contains($chrome, 'refresh=1')) {
        throw new RuntimeException($case['id'] . ' refresh link drifted');
    }
    $out[] = [
        'id' => $case['id'],
        'input' => $case,
        'chrome' => $chrome,
        'secondary' => null,
        'output' => ['chrome' => $chrome, 'secondary' => null],
    ];
}
$byId = [];
foreach ($out as $row) {
    $byId[$row['id']] = $row;
}
if (!str_contains($byId['own']['chrome'], 'This is you') || str_contains($byId['own']['chrome'], 'name="action" value="follow_remote"') || str_contains($byId['own']['chrome'], 'post-action-menu')) {
    throw new RuntimeException('own remote profile relationship row drifted');
}
if (!str_contains($byId['follow_back']['chrome'], '>Follow back<') || !str_contains($byId['following']['chrome'], '>mutuals<') || !str_contains($byId['peer']['chrome'], '>Follow<')) {
    throw new RuntimeException('follow relationship labels drifted');
}
if (!str_contains($byId['requested']['chrome'], 'Cancel this follow request?') || !str_contains($byId['cold']['chrome'], 'No bio is cached yet')) {
    throw new RuntimeException('requested row or cold bio drifted');
}
if (!str_contains($byId['fields_emoji']['chrome'], 'custom-emoji') || str_contains($byId['fields_emoji']['chrome'], '◈ Resonant') || str_contains($byId['peer']['chrome'], 'Cat person')) {
    throw new RuntimeException('emoji, resonant badge, or catalog badge drifted');
}
if (!str_contains($byId['fields_emoji']['chrome'], 'https://ada.example/lab') || !str_contains($byId['muted_blocked']['chrome'], 'muted for me') || !str_contains($byId['muted_blocked']['chrome'], '>Unblock<')) {
    throw new RuntimeException('fields or moderation row drifted');
}
if (str_contains($byId['muted_blocked']['chrome'], '>DM<') || !str_contains($byId['peer']['chrome'], '>DM<') || str_contains($byId['peer']['chrome'], '>Ask<') || !str_contains($byId['wafrn']['chrome'], '>Ask<')) {
    throw new RuntimeException('remote menu links drifted');
}
if (!str_contains($byId['admin_open']['chrome'], 'Mute this user (server)') || !str_contains($byId['admin_open']['chrome'], 'Block this user (server)') || !str_contains($byId['admin_mute']['chrome'], 'Remove user mute (server)') || !str_contains($byId['admin_block']['chrome'], 'Remove user block (server)') || !str_contains($byId['admin_suspend']['chrome'], 'Remove user block (server)') || !str_contains($byId['admin_open']['chrome'], 'Open in moderation panel')) {
    throw new RuntimeException('server moderation menu drifted');
}
$tabs = $byId['tabs']['chrome'];
if (!str_contains($tabs, '>Replies<') || !str_contains($tabs, '>Boosts<') || str_contains($tabs, '>99<') || !str_contains($tabs, 'class="tab-count">2</span>')) {
    throw new RuntimeException('remote tab counts drifted');
}
if (!preg_match('#tab-count">2</span>.*tab-count">1</span>.*tab-count">4</span>.*tab-count">3</span>#s', $tabs)) {
    throw new RuntimeException('remote tabs must use item counts, including replies and boosts');
}
if (!str_contains($byId['username_user']['chrome'], 'href="https://remote.example/users/ada"') || str_contains($byId['username_user']['chrome'], 'https://remote.example/@user')) {
    throw new RuntimeException('placeholder username must open the actor IRI');
}
if (!str_contains($byId['peer']['chrome'], 'https://remote.example/@ada') || !str_contains($byId['peer']['chrome'], 'Open remote') || !str_contains($byId['peer']['chrome'], 'Follow on their instance') || str_contains($byId['own']['chrome'], 'Follow on their instance')) {
    throw new RuntimeException('remote open links drifted');
}
if (!str_contains($byId['cold']['chrome'], '@ada@remote.example') || !str_contains($byId['placeholder_id']['chrome'], '@123456789@remote.example')) {
    throw new RuntimeException('fallback handle drifted');
}
if ($byId['peer']['secondary'] !== null) {
    throw new RuntimeException('remote chrome has no pin or ask body');
}
echo json_encode(['cases' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
