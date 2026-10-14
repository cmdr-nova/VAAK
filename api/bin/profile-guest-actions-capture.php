<?php
declare(strict_types=1);
/** Guest profile chrome, cold avatar facts, and the local pagination shell.
 *  No network and no database. The HTML is the live ap-admin.php markup. */
if (!extension_loaded('mbstring')) {
    fwrite(STDERR, "profile guest capture requires mbstring\n");
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
    $bare = $GLOBALS['chrome_case']['bare_src'] ?? null;
    if (is_string($bare) && $bare !== '') {
        return $bare;
    }
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
function ap_remote_media_get(?string $actor, string $slot): ?array
{
    throw new RuntimeException('profile guest capture must not read the media cache');
}
function ap_remote_media_warm_async(?string $actor): void
{
    throw new RuntimeException('profile guest capture must not warm media');
}

$root = dirname(__DIR__);
require_once $root . '/ap-profile-html.php';
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
$feedOpenSrc = chrome_between(
    $admin,
    "            <?php if (\$rpTabItems[\$rpTab] === [] && (\$rpAxumHtml === null || (\$rpAxumHtml === '' && \$rpCompleteSourceCount === 0)) && (\$rpLocalPageItems ?? []) === []): ?>",
    "<?php } ?>\n            <?php if (\$rpAxumHtml !== null): ?>"
);
$feedCloseSrc = chrome_between(
    $admin,
    "            <?php if (\$rpIsLocal): ?></div><div id=\"timeline-status\"",
    "<?php } ?>\n          <?php elseif (\$rpTabItems[\$rpTab] === []): ?>"
);
if (str_contains($header, 'ap_remote_media_warm_async') || str_contains($header, 'ap_remote_media_get')
    || str_contains($feedOpenSrc, 'ap_remote_media_') || str_contains($feedCloseSrc, 'ap_remote_media_')) {
    throw new RuntimeException('profile chrome fragments must not fetch or warm media');
}

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
    $GLOBALS['vaak_guest_profile'] = !empty($case['guest']);
    $kind = (string) ($case['kind'] ?? 'local');
    $actor = (string) $case['actor'];
    $key = (string) ($case['key'] ?? '');
    $from = (string) $case['from'];
    $tab = (string) $case['tab'];
    $isLocal = $kind === 'local';
    $isBsky = $kind === 'bsky';
    $meta = ['display_name' => $case['display_name'], 'username' => $case['username']];
    $items = [
        'posts' => array_fill(0, (int) $case['item_posts'], 1),
        'replies' => array_fill(0, (int) $case['item_replies'], 1),
        'boosts' => array_fill(0, (int) $case['item_boosts'], 1),
        'media' => array_fill(0, (int) $case['item_media'], 1),
        'asks' => array_fill(0, (int) $case['item_asks'], 1),
    ];
    if ($isLocal) {
        $href = '/users/' . rawurlencode($key) . ($from !== '' ? ('?from=' . rawurlencode($from) . '&tab=') : '?tab=');
    } else {
        $href = '?view=remote_profile&actor=' . rawurlencode($actor) . '&from=' . rawurlencode($from) . '&tab=';
    }
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
    $guestBsky = (string) ($case['guest_bsky'] ?? '');
    return [
        'rpActor' => $actor, 'rpLocalKey' => $isLocal ? $key : null, 'rpIsLocal' => $isLocal, 'rpIsBsky' => $isBsky,
        'rpBskyHandle' => $isBsky ? (string) ($case['bsky_handle'] ?? $guestBsky) : '',
        'rpFrom' => $from, 'rpTab' => $tab, 'rpTabHref' => $href, 'rpBackHref' => $back[0], 'rpBackLabel' => $back[1], 'rpBackView' => $back[2],
        'rpMeta' => $meta, 'rpHeader' => $case['banner'], 'rpAvatar' => $case['avatar'], 'rpBio' => (string) $case['bio'],
        'rpIsOwn' => !empty($case['is_own']), 'rpFollowing' => !empty($case['following']), 'rpFollowsYou' => !empty($case['follows_you']),
        'rpRequested' => !empty($case['requested']), 'rpLocked' => !empty($case['locked']),
        'rpRel' => !empty($case['following']) ? (!empty($case['follows_you']) ? 'mutual' : 'following') : (!empty($case['follows_you']) ? 'follows_you' : 'none'),
        'rpMuted' => !empty($case['muted']), 'rpBlockedPersonal' => !empty($case['blocked_personal']), 'rpBlockedServer' => !empty($case['blocked_server']),
        'rpPostSub' => !empty($case['subscribed']), 'rpPhyrianBridge' => null, 'rpSlLink' => null, 'rpWowLink' => null,
        'rpProfileFields' => $case['fields'], 'rpProfileBadges' => $case['badges'],
        'rpHasCombinedStats' => $isLocal, 'rpBskyStatsHandle' => (!$isBsky && $guestBsky !== '') ? $guestBsky : null,
        'rpPostsStat' => (int) $case['posts_stat'], 'rpFollowingStat' => (int) $case['following_stat'], 'rpFollowersStat' => (int) $case['followers_stat'],
        'rpTabItems' => $items, 'rpOutboxTotal' => (int) $case['outbox_total'], 'rpOutboxRepliesTotal' => (int) $case['replies_total'],
        'rpHideProfileReplies' => !empty($case['hide_replies']), 'rpBoostTotal' => (int) $case['boost_total'], 'rpHideProfileBoosts' => !empty($case['hide_boosts']),
        'rpBskyPostCount' => (int) $case['bsky_posts'], 'rpAnsweredAskCount' => (int) $case['item_asks'], 'rpAnsweredAskRows' => array_fill(0, (int) $case['item_asks'], ['id' => 1]),
        'rpPinnedStatuses' => [], 'rpPinnedCount' => 0, 'rpFeaturedCards' => [], 'rpFeaturedCount' => 0,
        'rpBlogPosts' => [], 'rpBlogPost' => null, 'rpBlogCount' => 0,
        'vaakOwnerId' => (int) $case['viewer_id'], 'vaakIsAdmin' => !empty($case['viewer_is_admin']),
        'rpAxumHtml' => null, 'rpCompleteSourceCount' => null,
        'rpLocalPageItems' => array_fill(0, (int) ($case['page_items'] ?? 0), 1),
        'rpLocalNextOffset' => (int) ($case['feed_offset'] ?? 0),
        'rpLocalPageLimit' => (int) ($case['feed_limit'] ?? 12),
        'rpLocalHasMore' => !empty($case['feed_has_more']),
        'rpAxumCache' => (string) ($case['feed_cache'] ?? ''),
    ];
}

function chrome_case(string $id, array $over = []): array
{
    return $over + [
        'id' => $id, 'kind' => 'local', 'actor' => 'https://mkultra.monster/users/ada', 'key' => 'ada',
        'viewer_actor' => 'https://mkultra.monster/users/viewer', 'viewer_id' => 7, 'from' => 'search', 'tab' => 'posts',
        'display_name' => 'Ada Lovelace', 'username' => 'ada', 'bio' => 'Analytical engines & notes',
        'avatar' => 'https://cdn.example/ada.webp', 'banner' => 'https://cdn.example/banner.webp',
        'fallback_avatar' => 'https://mkultra.monster/img/avatar/default.webp', 'bare_src' => null, 'emoji' => [],
        'is_own' => false, 'guest' => false, 'guest_bsky' => '', 'bsky_handle' => '',
        'following' => false, 'follows_you' => false, 'requested' => false, 'locked' => false,
        'muted' => false, 'blocked_personal' => false, 'blocked_server' => false, 'subscribed' => false,
        'personal_block_id' => null, 'deprioritized' => false, 'target_is_admin' => false, 'viewer_is_admin' => false,
        'csrf' => 'csrf&<>"\'', 'fields' => [], 'badges' => [], 'stats_bsky' => false,
        'posts_stat' => 3, 'following_stat' => 4, 'followers_stat' => 5,
        'item_posts' => 1, 'item_replies' => 0, 'item_boosts' => 0, 'item_media' => 0, 'item_asks' => 0,
        'outbox_total' => 3, 'replies_total' => 2, 'hide_replies' => false, 'boost_total' => 1, 'hide_boosts' => false,
        'bsky_posts' => 0, 'page_items' => 1, 'feed_offset' => 12, 'feed_limit' => 12, 'feed_has_more' => true,
        'feed_cache' => '', 'feed_empty' => false,
    ];
}

$cases = [
    chrome_case('signed_page', ['feed_cache' => 'axum-profile-html', 'feed_offset' => 12, 'page_items' => 12, 'item_posts' => 12]),
    chrome_case('signed_end', ['feed_has_more' => false, 'feed_offset' => 4, 'page_items' => 4, 'item_posts' => 4, 'from' => 'home']),
    chrome_case('signed_empty', [
        'tab' => 'media', 'feed_empty' => true, 'feed_has_more' => false, 'feed_offset' => 0, 'page_items' => 0,
        'item_posts' => 0, 'item_media' => 0, 'from' => 'followers',
    ]),
    chrome_case('guest_local', ['guest' => true, 'feed_cache' => 'axum-profile-html']),
    chrome_case('guest_cold', [
        'guest' => true, 'display_name' => '0', 'username' => 'ada', 'bio' => '', 'avatar' => null, 'banner' => null,
        'bare_src' => 'https://cdn.example/cold-avatar.webp', 'feed_empty' => true, 'feed_has_more' => false,
        'feed_offset' => 0, 'page_items' => 0, 'item_posts' => 0, 'from' => 'about',
    ]),
    chrome_case('guest_bsky_form', [
        'guest' => true, 'guest_bsky' => 'ada.example', 'stats_bsky' => true, 'from' => 'mentions',
        'feed_has_more' => false, 'feed_offset' => 24, 'feed_limit' => 12,
    ]),
    chrome_case('guest_muted', [
        'guest' => true, 'muted' => true, 'blocked_personal' => true, 'blocked_server' => true,
        'feed_cache' => 'cache&<>"\'', 'from' => 'blocks',
    ]),
    chrome_case('guest_asks', ['guest' => true, 'tab' => 'asks', 'item_asks' => 0, 'feed_empty' => false]),
    chrome_case('guest_remote', [
        'kind' => 'remote', 'actor' => 'https://remote.example/users/ada', 'key' => '', 'guest' => true,
        'display_name' => 'Ada Lovelace', 'username' => 'ada',
    ]),
    chrome_case('guest_remote_cold', [
        'kind' => 'remote', 'actor' => 'https://remote.example/users/ada', 'key' => '', 'guest' => true,
        'display_name' => '0', 'username' => '', 'bio' => '', 'avatar' => null, 'banner' => null,
        'bare_src' => 'https://cdn.example/cold-remote.webp', 'from' => 'feed',
    ]),
    chrome_case('guest_remote_bsky', [
        'kind' => 'remote', 'actor' => 'https://remote.example/users/ada', 'key' => '', 'guest' => true,
        'guest_bsky' => 'ada.example',
    ]),
    chrome_case('guest_bsky', [
        'kind' => 'bsky', 'actor' => 'https://bsky.app/profile/ada.example', 'key' => '', 'guest' => true,
        'bsky_handle' => 'ada.example', 'guest_bsky' => 'ada.example',
    ]),
    chrome_case('guest_did', [
        'kind' => 'bsky', 'actor' => 'did:plc:aaaaaaaaaaaaaaaaaaaaaa', 'key' => '', 'guest' => true,
        'bsky_handle' => '', 'display_name' => '0', 'username' => '', 'bio' => '', 'avatar' => null, 'banner' => null,
        'bare_src' => 'https://cdn.example/cold-did.webp',
    ]),
    chrome_case('guest_bridgy', [
        'kind' => 'bsky', 'actor' => 'https://bsky.brid.gy/ap/did:plc:aaaaaaaaaaaaaaaaaaaaaa', 'key' => '',
        'guest' => true, 'bsky_handle' => 'ada.example', 'guest_bsky' => 'ada.example', 'bio' => '',
    ]),
];

global $pageBack, $header, $asks, $feedOpenSrc, $feedCloseSrc;
$out = [];
foreach ($cases as $case) {
    $vars = chrome_vars($case);
    $page = chrome_eval($pageBack, $vars);
    $head = chrome_eval($header, $vars);
    $chrome = $page . $head;
    $kind = (string) $case['kind'];
    $shell = $kind === 'local' && !in_array((string) $case['tab'], ['asks', 'pinned', 'featured', 'blog'], true);
    $secondary = null;
    if ($kind === 'local' && $case['tab'] === 'asks' && (int) $case['item_asks'] === 0) {
        $secondary = chrome_eval($asks, $vars);
    }
    $open = $shell ? chrome_eval($feedOpenSrc, $vars) : null;
    $close = $shell ? chrome_eval($feedCloseSrc, $vars) : null;
    $case['feed_shell'] = $shell;
    $case['feed_empty'] = (($vars['rpTabItems'][$vars['rpTab']] ?? []) === [])
        && ($vars['rpAxumHtml'] === null || ($vars['rpAxumHtml'] === '' && $vars['rpCompleteSourceCount'] === 0))
        && (($vars['rpLocalPageItems'] ?? []) === []);
    $case['feed_offset'] = (int) $vars['rpLocalNextOffset'];
    $case['feed_limit'] = (int) $vars['rpLocalPageLimit'];
    $case['feed_has_more'] = !empty($vars['rpLocalHasMore']);
    $case['feed_cache'] = (string) $vars['rpAxumCache'];
    if ($chrome === '' || !str_contains($chrome, 'remote-profile-tabs')) {
        throw new RuntimeException($case['id'] . ' did not paint a profile header');
    }
    $out[] = [
        'id' => $case['id'],
        'input' => $case,
        'output' => [
            'chrome' => $chrome,
            'page_back' => $page,
            'header' => $head,
            'secondary' => $secondary,
            'feed_open' => $open,
            'feed_close' => $close,
        ],
    ];
}
$byId = [];
foreach ($out as $row) {
    $byId[$row['id']] = $row['output'];
}
foreach (['guest_local', 'guest_cold', 'guest_bsky_form', 'guest_muted', 'guest_asks', 'guest_remote', 'guest_remote_cold', 'guest_remote_bsky', 'guest_bsky', 'guest_bridgy'] as $id) {
    if (str_contains($byId[$id]['page_back'], 'class="page-back"') || !str_contains($byId[$id]['chrome'], 'remote-profile-guest-follow')) {
        throw new RuntimeException($id . ' guest page-back or follow panel drifted');
    }
    if (str_contains($byId[$id]['chrome'], 'Bite') || str_contains($byId[$id]['chrome'], 'value="follow_remote"')
        || str_contains($byId[$id]['chrome'], '>Subscribe<') || str_contains($byId[$id]['chrome'], '>Mention<')
        || str_contains($byId[$id]['chrome'], 'Follow on their instance') || str_contains($byId[$id]['chrome'], 'Open on Bluesky')
        || str_contains($byId[$id]['chrome'], 'csrf&')) {
        throw new RuntimeException($id . ' guest row still has a signed-in action or token');
    }
}
if (str_contains($byId['guest_did']['chrome'], 'remote-profile-guest-follow') || str_contains($byId['guest_did']['page_back'], 'class="page-back"')) {
    throw new RuntimeException('did guest must omit the https follow panel and the page-back');
}
if (!str_contains($byId['guest_did']['chrome'], 'https://cdn.example/cold-did.webp') || str_contains($byId['guest_did']['chrome'], 'height:120px')) {
    throw new RuntimeException('did cold avatar drifted');
}
if (!str_contains($byId['guest_cold']['chrome'], 'https://cdn.example/cold-avatar.webp')
    || !str_contains($byId['guest_cold']['chrome'], 'https://mkultra.monster/img/avatar/default.webp')
    || str_contains($byId['guest_cold']['chrome'], 'height:120px')
    || !str_contains($byId['guest_remote_cold']['chrome'], 'https://cdn.example/cold-remote.webp')) {
    throw new RuntimeException('cold media avatar drifted');
}
if (!str_contains($byId['guest_bsky_form']['chrome'], 'https://bsky.app/profile/ada.example')
    || !str_contains($byId['guest_bsky']['chrome'], 'https://bsky.app/profile/ada.example')
    || !str_contains($byId['guest_bridgy']['chrome'], 'guest-follow-bsky')
    || !str_contains($byId['guest_local']['chrome'], 'authorize_interaction')
    || str_contains($byId['guest_local']['header'], '\\/')) {
    throw new RuntimeException('guest follow action drifted');
}
if (!str_contains($byId['guest_muted']['chrome'], 'muted for me') || !str_contains($byId['guest_muted']['chrome'], 'blocked for me')
    || !str_contains($byId['guest_muted']['chrome'], 'blocked server-wide') || str_contains($byId['guest_muted']['chrome'], '<details')) {
    throw new RuntimeException('guest moderation tags drifted');
}
if (!str_contains($byId['signed_page']['page_back'], 'class="page-back"') || !str_contains($byId['signed_page']['chrome'], 'value="follow_remote"')
    || !str_contains($byId['signed_page']['feed_open'], 'data-offset="12"') || !str_contains($byId['signed_page']['feed_open'], 'data-tl-cache="axum-profile-html"')
    || !str_contains($byId['signed_page']['feed_close'], 'Scroll for more') || !str_contains($byId['signed_end']['feed_close'], 'End of profile')
    || !str_contains($byId['signed_empty']['feed_open'], 'No media available.')
    || !str_contains($byId['guest_muted']['feed_open'], 'data-tl-cache="cache&amp;&lt;&gt;&quot;&#039;"')
    || $byId['guest_asks']['feed_open'] !== null || !str_contains((string) $byId['guest_asks']['secondary'], 'No answered Asks yet.')
    || $byId['guest_remote']['feed_open'] !== null || $byId['guest_bsky']['feed_close'] !== null) {
    throw new RuntimeException('pagination shell drifted');
}
echo json_encode(['cases' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
