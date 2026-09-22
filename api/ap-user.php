<?php
/**
 * Multi-user ActivityPub front controller — /users/{username} and /@{username}.
 * cmdr_nova delegates to ap-cmdr-nova.php (rich profile). Other invitees get
 * AS2 Person + simpler HTML profile with that actor's outbox notes.
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-auth.php';
require_once __DIR__ . '/ap-import-export.php'; // alsoKnownAs / movedTo on multi-user actor docs
require_once __DIR__ . '/ap-sl-link.php';
require_once __DIR__ . '/ap-featured.php';
require_once __DIR__ . '/ap-feeds.php';
require_once __DIR__ . '/ap-masto-entities.php';
// HTML profiles also surface cached Bluesky-native posts for accounts that
// have a connected ATProto session. This remains cache-only: profile renders
// never perform a live Bluesky fetch.
require_once __DIR__ . '/ap-bsky.php';

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';

$username = '';
$sub = '';
if (preg_match('#^/@([a-zA-Z][a-zA-Z0-9_]{1,29})(?:/(.*))?$#', $path, $m)) {
    $username = strtolower($m[1]);
    $sub = isset($m[2]) ? trim((string) $m[2], '/') : '';
    $path = '/users/' . $username . ($sub !== '' ? '/' . $sub : '');
} elseif (preg_match('#^/users/([a-zA-Z][a-zA-Z0-9_]{1,29})(?:/(.*))?$#', $path, $m)) {
    $username = strtolower($m[1]);
    $sub = isset($m[2]) ? trim((string) $m[2], '/') : '';
}

if ($username === '') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":"Not found"}';
    exit;
}

// Preserve rich cmdr_nova behavior
if ($username === 'cmdr_nova') {
    $_SERVER['REQUEST_URI'] = '/users/cmdr_nova' . ($sub !== '' ? '/' . $sub : '')
        . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
            ? ('?' . $_SERVER['QUERY_STRING']) : '');
    require __DIR__ . '/ap-cmdr-nova.php';
    exit;
}

$user = ap_local_user_by_username($username);
if ($user === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":"Not found"}';
    exit;
}

$actorKey = (string) $user['actor_key'];
$actorId = rtrim((string) $user['actor_id'], '/');
if ($actorId === '') {
    $actorId = 'https://mkultra.monster/users/' . rawurlencode($actorKey);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
$wantsAp = ap_user_wants_activitypub($accept);

// Personal inbox
if ($sub === 'inbox') {
    if ($method !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        echo '{"error":"Method not allowed"}';
        exit;
    }
    ap_request_actor_set($actorKey);
    if (!defined('AP_INBOX_LIB_ONLY')) {
        // ap-inbox.php runs main on include; actor already set
        require __DIR__ . '/ap-inbox.php';
    }
    exit;
}

if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

// Dereferenceable FEP-044f QuoteAuthorization stamps for invitee accounts.
if (preg_match('#^quote_auth/([a-f0-9]+)$#', $sub, $qm)) {
    $stampId = $actorId . '/quote_auth/' . $qm[1];
    $stamp = function_exists('ap_quote_auth_get') ? ap_quote_auth_get($stampId) : null;
    if (!is_array($stamp)) {
        http_response_code(404);
        header('Content-Type: application/activity+json; charset=utf-8');
        echo '{"error":"Not found"}';
        exit;
    }
    ap_user_json(ap_quote_auth_document($stamp), $accept);
    exit;
}

if ($sub === 'feed.xml' || $sub === 'feed.atom') {
    $feedProfile = function_exists('ap_profile_get') ? ap_profile_get($actorKey) : [];
    ap_feed_render($actorKey, (string) ($feedProfile['name'] ?? ('@' . $actorKey)), $sub === 'feed.atom' ? 'atom' : 'rss');
    exit;
}

$paths = ap_actor_key_paths($actorKey);
$pem = is_file($paths['public']) ? file_get_contents($paths['public']) : false;
if (!is_string($pem) || $pem === '') {
    // Best-effort create on first fetch (dir must be writable)
    $ens = ap_actor_ensure_keypair($actorKey);
    if (!empty($ens['ok'])) {
        $pem = is_file($paths['public']) ? file_get_contents($paths['public']) : false;
    }
}
if (!is_string($pem) || $pem === '') {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"error":"actor key unavailable"}';
    exit;
}

// Collections
if (in_array($sub, ['outbox', 'followers', 'following'], true)) {
    if ($wantsAp) {
        ap_user_serve_collection_as2($actorKey, $actorId, $sub, $accept);
    } else {
        ap_user_serve_collection_html($actorKey, $actorId, $sub);
    }
    exit;
}

// Featured (pinned posts — empty for invitees for now)
if ($sub === 'collections/featured' || $sub === 'featured') {
    $doc = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $actorId . '/collections/featured',
        'type' => 'OrderedCollection',
        'totalItems' => 0,
        'orderedItems' => [],
    ];
    ap_user_json($doc, $accept);
    exit;
}

// Discoverable curated Collections (starter packs) for this local user
if ($sub === 'featuredCollections') {
    require_once __DIR__ . '/ap-collections.php';
    $items = [];
    foreach (ap_collections_list_local(false, $actorId) as $c) {
        if (empty($c['discoverable'])) {
            continue;
        }
        $items[] = (string) ($c['object_id'] ?? ap_collection_object_id((int) $c['id'], $actorId));
        if (count($items) >= 40) {
            break;
        }
    }
    $doc = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $actorId . '/featuredCollections',
        'type' => 'OrderedCollection',
        'totalItems' => count($items),
        'orderedItems' => $items,
    ];
    ap_user_json($doc, $accept);
    exit;
}

if (preg_match('#^collections/(\d+)$#', $sub, $cm)) {
    require_once __DIR__ . '/ap-collections.php';
    $cid = (int) $cm[1];
    $coll = ap_collection_by_id($cid);
    $collOwner = is_array($coll) ? rtrim((string) ($coll['owner_actor_id'] ?? ''), '/') : '';
    if (!$coll || (int) ($coll['local'] ?? 0) !== 1 || $collOwner !== rtrim($actorId, '/')) {
        http_response_code(404);
        if ($wantsAp) {
            header('Content-Type: application/activity+json');
            echo '{"error":"Collection not found"}';
        } else {
            ap_user_html_shell_start('Not found · @' . $actorKey);
            echo '<h1>Not found</h1><p class="muted">No collection at this URL.</p>';
            echo '<p class="back"><a href="/users/' . htmlspecialchars($actorKey, ENT_QUOTES, 'UTF-8') . '">← profile</a></p>';
            ap_user_html_shell_end();
        }
        exit;
    }
    $members = ap_collection_items($cid, 'accepted');
    if ($wantsAp) {
        $ordered = [];
        foreach ($members as $i => $mem) {
            $aid = (string) ($mem['actor_id'] ?? '');
            if ($aid === '') {
                continue;
            }
            $ordered[] = [
                'id' => $actorId . '/collections/' . $cid . '/items/' . (int) ($mem['id'] ?? ($i + 1)),
                'type' => 'Relationship',
                'object' => $aid,
            ];
        }
        $doc = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => (string) ($coll['object_id'] ?? ap_collection_object_id($cid, $actorId)),
            'type' => 'OrderedCollection',
            'name' => (string) ($coll['name'] ?? 'Collection'),
            'summary' => (string) ($coll['description'] ?? ''),
            'attributedTo' => $actorId,
            'url' => (string) ($coll['html_url'] ?? ap_collection_html_url($cid, $actorId)),
            'totalItems' => count($ordered),
            'orderedItems' => $ordered,
        ];
        ap_user_json($doc, $accept);
        exit;
    }
    ap_user_html_shell_start((string) ($coll['name'] ?? 'Collection') . ' · @' . $actorKey);
    echo '<p class="muted"><a href="/users/' . htmlspecialchars($actorKey, ENT_QUOTES, 'UTF-8') . '">@'
        . htmlspecialchars($actorKey, ENT_QUOTES, 'UTF-8') . '@mkultra.monster</a> · Collection</p>';
    echo '<h1>' . htmlspecialchars((string) ($coll['name'] ?? 'Collection'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>';
    $desc = trim((string) ($coll['description'] ?? ''));
    if ($desc !== '') {
        echo '<p style="margin:.75rem 0 1rem;line-height:1.45">' . htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    echo '<p class="muted">' . count($members) . ' accounts</p>';
    if (!$members) {
        echo '<p class="muted" style="margin-top:1rem">No members yet.</p>';
    } else {
        echo '<ul class="list">';
        foreach ($members as $mem) {
            $aid = (string) ($mem['actor_id'] ?? '');
            if ($aid === '' || !str_starts_with($aid, 'https://')) {
                continue;
            }
            echo '<li><div class="mono"><a href="' . htmlspecialchars($aid, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer nofollow">'
                . htmlspecialchars($aid, ENT_QUOTES, 'UTF-8') . '</a></div></li>';
        }
        echo '</ul>';
    }
    echo '<p class="back"><a href="/users/' . htmlspecialchars($actorKey, ENT_QUOTES, 'UTF-8') . '">← profile</a></p>';
    ap_user_html_shell_end();
    exit;
}

// Individual notes
if (preg_match('#^(notes|creates)/([a-f0-9]+)$#', $sub, $nm)) {
    $kind = $nm[1];
    $objectId = $actorId . '/' . $kind . '/' . $nm[2];
    $row = null;
    if ($kind === 'notes') {
        $st = ap_db()->prepare('SELECT * FROM outbox_notes WHERE id = ?');
        $st->execute([$objectId]);
        $row = $st->fetch();
    } else {
        $st = ap_db()->prepare('SELECT * FROM outbox_notes WHERE create_id = ?');
        $st->execute([$objectId]);
        $row = $st->fetch();
    }
    if (!$row) {
        http_response_code(404);
        if ($wantsAp) {
            header('Content-Type: application/activity+json');
            echo '{"error":"Not found"}';
        } else {
            ap_user_html_shell_start('Not found · @' . $actorKey);
            echo '<h1>Not found</h1><p class="muted">No post at this URL.</p>';
            echo '<p class="back"><a href="/users/' . htmlspecialchars($actorKey, ENT_QUOTES, 'UTF-8') . '">← profile</a></p>';
            ap_user_html_shell_end();
        }
        exit;
    }
    $create = json_decode((string) ($row['raw_create_json'] ?? ''), true);
    if (!is_array($create)) {
        http_response_code(500);
        echo '{"error":"Corrupt note"}';
        exit;
    }
    if ($wantsAp) {
        if ($kind === 'notes') {
            $note = $create['object'] ?? null;
            if (!is_array($note)) {
                http_response_code(404);
                echo '{"error":"Not found"}';
                exit;
            }
            if (function_exists('ap_note_ensure_quote_policy')) {
                $note = ap_note_ensure_quote_policy($note);
            }
            if (function_exists('ap_as2_ensure_top_context')) {
                $note = ap_as2_ensure_top_context($note);
            }
            ap_user_json($note, $accept);
        } else {
            if (function_exists('ap_create_finalize')) {
                $create = ap_create_finalize($create);
            }
            ap_user_json($create, $accept);
        }
        exit;
    }
    ap_user_note_html($actorKey, $row, $create);
    exit;
}

if ($sub !== '') {
    http_response_code(404);
    if ($wantsAp) {
        header('Content-Type: application/json');
        echo '{"error":"Not found"}';
    } else {
        ap_user_html_shell_start('Not found');
        echo '<h1>Not found</h1>';
        echo '<p class="back"><a href="/users/' . htmlspecialchars($actorKey, ENT_QUOTES, 'UTF-8') . '">← profile</a></p>';
        ap_user_html_shell_end();
    }
    exit;
}

if ($wantsAp) {
    $actor = ap_actor_as2_document($actorKey, $pem, false);
    ap_user_json($actor, $accept);
    exit;
}

ap_user_profile_html($actorKey, $actorId);
exit;

/* ----------------- helpers ----------------- */

function ap_user_wants_activitypub(string $accept): bool
{
    $accept = strtolower(trim($accept));
    if ($accept === '') {
        return false;
    }
    $hasHtml = str_contains($accept, 'text/html');
    $bestAp = -1.0;
    $bestHtml = -1.0;
    foreach (explode(',', $accept) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $semi = strpos($part, ';');
        $media = trim($semi === false ? $part : substr($part, 0, $semi));
        $q = 1.0;
        if (preg_match('/(?:^|;)\s*q\s*=\s*([0-9.]+)/', $part, $m)) {
            $q = (float) $m[1];
        }
        if ($media === 'text/html') {
            $bestHtml = max($bestHtml, $q);
            continue;
        }
        if ($media === 'application/activity+json'
            || $media === 'application/ld+json'
            || str_starts_with($media, 'application/ld+json')) {
            $bestAp = max($bestAp, $q);
        }
    }
    if ($bestAp < 0) {
        return false;
    }
    if ($hasHtml && $bestHtml >= $bestAp) {
        return false;
    }
    return $bestAp > 0;
}

function ap_user_json(array $doc, string $accept): void
{
    if (str_contains($accept, 'application/activity+json')) {
        header('Content-Type: application/activity+json; charset=utf-8');
    } else {
        header('Content-Type: application/ld+json; profile="https://www.w3.org/ns/activitystreams"; charset=utf-8');
    }
    header('Cache-Control: no-cache, max-age=0, must-revalidate');
    header('Vary: Accept');
    echo json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function ap_user_serve_collection_as2(string $actorKey, string $actorId, string $col, string $accept): void
{
    if ($col === 'outbox') {
        $items = [];
        foreach (ap_outbox_list(50, $actorKey) as $n) {
            $vis = (string) ($n['visibility'] ?? 'public');
            if (function_exists('ap_visibility_in_ap_outbox') && !ap_visibility_in_ap_outbox($vis)) {
                continue;
            }
            $decoded = json_decode((string) ($n['raw_create_json'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            if (function_exists('ap_create_finalize')) {
                $decoded = ap_create_finalize($decoded);
            }
            unset($decoded['@context']);
            $items[] = $decoded;
            if (count($items) >= 50) {
                break;
            }
        }
        $doc = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actorId . '/outbox',
            'type' => 'OrderedCollection',
            'totalItems' => count($items),
            'orderedItems' => $items,
        ];
    } elseif ($col === 'followers') {
        $items = [];
        foreach (ap_followers_list($actorId) as $r) {
            if (!empty($r['actor_id'])) {
                $items[] = $r['actor_id'];
            }
        }
        $doc = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actorId . '/followers',
            'type' => 'OrderedCollection',
            'totalItems' => count($items),
            'orderedItems' => $items,
        ];
    } else {
        $items = [];
        foreach (ap_following_list($actorId) as $r) {
            if (!empty($r['actor_id'])) {
                $items[] = $r['actor_id'];
            }
        }
        $doc = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actorId . '/following',
            'type' => 'OrderedCollection',
            'totalItems' => count($items),
            'orderedItems' => $items,
        ];
    }
    ap_user_json($doc, $accept);
}

function ap_user_serve_collection_html(string $actorKey, string $actorId, string $col): void
{
    $safe = htmlspecialchars($actorKey, ENT_QUOTES, 'UTF-8');
    ap_user_html_shell_start(ucfirst($col) . ' · @' . $actorKey . '@mkultra.monster');
    echo '<p class="muted"><a href="/users/' . $safe . '">@' . $safe . '@mkultra.monster</a></p>';
    echo '<h1>' . htmlspecialchars(ucfirst($col), ENT_QUOTES, 'UTF-8') . '</h1>';
    if ($col === 'outbox') {
        $rows = ap_outbox_list(40, $actorKey);
        if (!$rows) {
            echo '<p class="muted">No public posts yet.</p>';
        } else {
            echo '<div class="posts">';
            foreach ($rows as $n) {
                echo ap_user_post_preview_html($actorKey, $n);
            }
            echo '</div>';
        }
    } else {
        $rows = $col === 'followers' ? ap_followers_list($actorId) : ap_following_list($actorId);
        if (!$rows) {
            echo '<p class="muted">Nobody here yet.</p>';
        } else {
            echo '<ul class="actor-list">';
            foreach ($rows as $r) {
                $aid = htmlspecialchars((string) ($r['actor_id'] ?? ''), ENT_QUOTES, 'UTF-8');
                $uname = htmlspecialchars((string) ($r['username'] ?? ''), ENT_QUOTES, 'UTF-8');
                $host = htmlspecialchars((string) ($r['host'] ?? ''), ENT_QUOTES, 'UTF-8');
                $label = $uname !== '' && $host !== '' ? ('@' . $uname . '@' . $host) : $aid;
                echo '<li><a href="' . $aid . '" rel="nofollow noopener noreferrer">' . $label . '</a></li>';
            }
            echo '</ul>';
        }
    }
    echo '<p class="back"><a href="/users/' . $safe . '">← profile</a></p>';
    ap_user_html_shell_end();
}

function ap_user_profile_html(string $actorKey, string $actorId): void
{
    $p = ap_profile_get($actorKey);
    $followers = ap_followers_list($actorId);
    $following = ap_following_list($actorId);
    $name = htmlspecialchars((string) $p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safe = htmlspecialchars($actorKey, ENT_QUOTES, 'UTF-8');
    $summary = function_exists('ap_html_sanitize_allowlist')
        ? ap_html_sanitize_allowlist((string) $p['summary'], '<p><br><a><code><strong><em><b><i>')
        : strip_tags((string) $p['summary'], '<p><br><a><code><strong><em><b><i>');
    if (function_exists('ap_profile_normalize_summary_html')) {
        $summary = ap_profile_normalize_summary_html($summary);
    }
    $bskyHandle = function_exists('ap_profile_bsky_handle')
        ? ap_profile_bsky_handle($actorKey, $p)
        : null;
    $profileOwnerId = function_exists('ap_db_owner_user_id_for_actor')
        ? ap_db_owner_user_id_for_actor($actorId) : 0;
    $profileSession = ($profileOwnerId > 0 && function_exists('ap_bsky_session_row'))
        ? ap_bsky_session_row($profileOwnerId) : null;
    $profileDid = is_array($profileSession) ? trim((string) ($profileSession['did'] ?? '')) : '';
    if ($profileDid !== '') {
        // Keep both the historical backfill and the incremental author-feed
        // refresh queued. Neither path performs a live Bluesky request here.
        if (function_exists('ap_bsky_own_posts_backfill_maybe_enqueue')) {
            ap_bsky_own_posts_backfill_maybe_enqueue($profileOwnerId, $profileDid);
        }
        if (function_exists('ap_bsky_author_feed_refresh_enqueue')) {
            ap_bsky_author_feed_refresh_enqueue($profileOwnerId, $profileDid, 0);
        }
        if (function_exists('ap_bsky_background_sync_enqueue')) {
            ap_bsky_background_sync_enqueue($profileOwnerId, 'reposts');
        }
    }

    ap_user_html_shell_start('@' . $actorKey . '@mkultra.monster');
    echo '<span id="profile-top" aria-hidden="true"></span>';
    $viewer = function_exists('ap_auth_current_user') ? ap_auth_current_user() : null;
    $viewerActor = is_array($viewer) ? rtrim((string) ($viewer['actor_id'] ?? ''), '/') : '';
    $isOwner = $viewerActor !== '' && $viewerActor === rtrim($actorId, '/');
    if ($isOwner) {
        echo '<div class="owner-bar" style="margin:0 0 .85rem;padding:.55rem .75rem;border:1px solid #2a4a3a;border-radius:10px;background:rgba(80,160,120,.1);font-size:.86rem;color:#bfe;text-align:center">'
            . 'Signed in as <b>@' . htmlspecialchars($actorKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b> — '
            . '<a href="/vaak/?view=outbox" style="color:#7ee0ff">Open VAAK</a></div>';
    }
    if (!empty($p['image_url'])) {
        $banner = htmlspecialchars((string) $p['image_url'], ENT_QUOTES, 'UTF-8');
        echo '<div class="banner" style="background-image:url(\'' . $banner . '\')"></div>';
    }
    echo '<div class="row">';
    $avUrl = function_exists('ap_local_avatar_url')
        ? ap_local_avatar_url($p['icon_url'] ?? null)
        : ((string) ($p['icon_url'] ?? '') ?: '/img/avatar/local-default.webp');
    echo '<img class="av" src="' . htmlspecialchars($avUrl, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy" referrerpolicy="no-referrer">';
    $automatedBadge = !empty($p['automated'])
        ? ' <span class="automated-flare" title="Automated account">Automated</span>'
        : '';
    echo '<div><h1>' . $name . $automatedBadge . '</h1>';
    echo '<p class="muted" style="margin:0">@' . $safe . '@mkultra.monster</p>';
    $slLink = function_exists('ap_sl_link_for_actor_key') ? ap_sl_link_for_actor_key($actorKey) : null;
    if (is_array($slLink) && !empty($slLink['sl_username'])) {
        $slName = htmlspecialchars((string) $slLink['sl_username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $slHref = htmlspecialchars(
            function_exists('ap_sl_viewer_profile_url')
                ? ap_sl_viewer_profile_url((string) ($slLink['sl_agent_id'] ?? ''))
                : 'https://secondlife.com/',
            ENT_QUOTES,
            'UTF-8'
        );
        echo '<p class="sl-link" style="margin:.55rem 0 0">'
            . '<a href="' . $slHref . '" rel="noopener noreferrer me" title="Open ' . $slName . ' in Second Life" aria-label="Open ' . $slName . ' in Second Life">'
            . '<img src="/vaak/second-life.jpg" alt="" width="43" height="34" loading="lazy">'
            . '</a></p>';
    }
    echo '</div></div>';
    if (trim(strip_tags($summary)) !== '') {
        echo '<div class="profile-bio">' . $summary . '</div>';
    }

    echo '<div class="follow-wrap">';
    echo '<button type="button" class="btn-follow" id="ap-follow-toggle" aria-expanded="false" aria-controls="ap-follow-panel">Follow</button>';
    echo '<div class="follow-panel" id="ap-follow-panel" hidden>';
    echo '<form id="ap-follow-form" action="#" method="get">';
    echo '<label for="ap-follow-handle">Follow from your fediverse account</label>';
    echo '<div class="follow-row">';
    echo '<input id="ap-follow-handle" name="handle" type="text" inputmode="email" autocomplete="username" spellcheck="false" placeholder="@you@your.instance" required>';
    echo '<button type="submit">Go</button></div>';
    echo '<p class="follow-hint">Opens your instance’s follow dialog.</p></form>';
    if ($bskyHandle !== null && trim($bskyHandle) !== '') {
        $bskyHandleSafe = htmlspecialchars(trim($bskyHandle), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<form id="ap-bsky-follow-form" class="follow-panel" action="https://bsky.app/profile/'
            . rawurlencode(trim($bskyHandle)) . '" method="get" target="_blank" rel="noopener noreferrer">'
            . '<label for="ap-bsky-follow-handle">Follow on Bluesky</label>'
            . '<div class="follow-row"><input id="ap-bsky-follow-handle" name="handle" type="text" value="@' . $bskyHandleSafe . '" readonly>'
            . '<button type="submit">Open</button></div>'
            . '<p class="follow-hint">Opens this profile in Bluesky so you can follow it there.</p></form>';
    }
    echo '</div></div>';

    if (!empty($p['attachment']) && is_array($p['attachment'])) {
        echo '<div class="fields">';
        foreach ($p['attachment'] as $att) {
            if (!is_array($att)) {
                continue;
            }
            $ln = htmlspecialchars((string) ($att['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lv = function_exists('ap_html_sanitize_allowlist')
                ? ap_html_sanitize_allowlist((string) ($att['value'] ?? ''), '<a>')
                : htmlspecialchars(strip_tags((string) ($att['value'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($ln === '' || $lv === '') {
                continue;
            }
            echo '<div class="field"><span class="field-label">' . $ln . '</span>'
                . '<div class="field-value">' . $lv . '</div></div>';
        }
        echo '</div>';
    }

    // Backfilled Bluesky history can be large; paginate the profile instead of
    // rendering the entire archive in one response.
    $profilePerPage = 40;
    $profilePage = max(1, (int) ($_GET['page'] ?? 1));
    $profileTotal = function_exists('ap_outbox_count_for_actor')
        ? ap_outbox_count_for_actor($actorKey)
        : 0;
    $notes = function_exists('ap_outbox_list_page')
        ? ap_outbox_list_page($profilePerPage, ($profilePage - 1) * $profilePerPage, $actorKey)
        : ap_outbox_list($profilePerPage, $actorKey);
    $publicNotes = [];
    foreach ($notes as $n) {
        $vis = (string) ($n['visibility'] ?? 'public');
        if (function_exists('ap_visibility_on_html_profile') && !ap_visibility_on_html_profile($vis)) {
            continue;
        }
        $publicNotes[] = $n;
    }
    $profileBoosts = [];
    $profileBoostTotal = 0;
    try {
        if (function_exists('ap_masto_reblog_rows') && function_exists('ap_db_owner_user_id_for_actor')) {
            $ownerId = $profileOwnerId;
            if ($ownerId > 0) {
                $allProfileBoosts = function_exists('ap_masto_reblog_rows_for_html_profile')
                    ? ap_masto_reblog_rows_for_html_profile($ownerId, 5000, 0)
                    : ap_masto_reblog_rows(80, null, $ownerId);
                $profileBoostTotal = count($allProfileBoosts);
                $profileBoosts = array_slice($allProfileBoosts, ($profilePage - 1) * $profilePerPage, $profilePerPage);
            }
        }
    } catch (Throwable $e) {
        $profileBoosts = [];
    }

    // Historical Bluesky posts that were imported as ActivityPub notes already
    // appear in $publicNotes. New Bluesky-native posts remain in the durable
    // bsky_posts cache, so merge those cached rows into the profile directly.
    // This also preserves Bluesky reposts via their reasonRepost marker.
    $profileBskyPosts = [];
    if ($profileDid !== '' && function_exists('ap_bsky_posts_for_author')) {
        try {
            $profileBskyPosts = ap_bsky_posts_for_author($profileDid, 80, 0);
        } catch (Throwable $e) {
            $profileBskyPosts = [];
        }
    }
    $localNoteIds = [];
    foreach ($publicNotes as $localNote) {
        $localId = rtrim((string) ($localNote['id'] ?? ''), '/');
        if ($localId !== '') {
            $localNoteIds[$localId] = true;
        }
    }
    $profileBskyPosts = array_values(array_filter($profileBskyPosts, static function (array $item) use ($localNoteIds): bool {
        $post = is_array($item['post'] ?? null) ? $item['post'] : [];
        $uri = trim((string) ($post['uri'] ?? ''));
        $record = is_array($post['record'] ?? null) ? $post['record'] : [];
        $fediId = rtrim((string) (($record['fediverseId'] ?? '') ?: ($record['fediverse_id'] ?? '')), '/');
        if ($fediId === '' && $uri !== '' && function_exists('ap_bsky_post_link_by_uri')) {
            $link = ap_bsky_post_link_by_uri($uri);
            $fediId = is_array($link) ? rtrim((string) ($link['fediverse_id'] ?? ''), '/') : '';
        }
        return $fediId === '' || !isset($localNoteIds[$fediId]);
    }));
    $seenBskyUris = [];
    $profileBskyPosts = array_values(array_filter($profileBskyPosts, static function (array $item) use (&$seenBskyUris): bool {
        $uri = trim((string) (($item['post']['uri'] ?? '') ?: ''));
        if ($uri === '' || isset($seenBskyUris[$uri])) {
            return false;
        }
        $seenBskyUris[$uri] = true;
        return true;
    }));
    $profileBskyCount = $profileBskyPosts !== [] ? count($profileBskyPosts) : 0;
    $profileReplyNotes = [];
    foreach (ap_outbox_list(200, $actorKey) as $replyNote) {
        if (trim((string) ($replyNote['in_reply_to'] ?? '')) !== '') {
            $profileReplyNotes[] = $replyNote;
        }
    }
    $profileBskyReplies = array_values(array_filter($profileBskyPosts, static function (array $item): bool {
        $post = is_array($item['post'] ?? null) ? $item['post'] : [];
        return is_array($post['record']['reply'] ?? null);
    }));
    $blogSlug = trim((string) ($_GET['post'] ?? ''));
    $blogPost = $blogSlug !== '' ? ap_blog_post_get($actorKey, $blogSlug, true) : null;
    $blogRows = ap_blog_posts_list($actorKey, true, 20, max(0, ($profilePage - 1) * 20));
    $blogCount = count(ap_blog_posts_list($actorKey, true, 200, 0));

    $tab = strtolower(trim((string) ($_GET['tab'] ?? 'posts')));
    if (!in_array($tab, ['posts', 'media', 'replies', 'boosts', 'featured', 'blog'], true)) {
        $tab = 'posts';
    }
    $mediaNotes = [];
    foreach ($publicNotes as $mediaNote) {
        $create = json_decode((string) ($mediaNote['raw_create_json'] ?? ''), true);
        $noteObj = is_array($create) && is_array($create['object'] ?? null) ? $create['object'] : [];
        if ($noteObj && ap_user_note_media_html($noteObj, false) !== '') {
            $mediaNotes[] = [$mediaNote, $noteObj];
        }
    }
    $featuredCards = function_exists('ap_featured_cards_for_actor_key')
        ? ap_featured_cards_for_actor_key($actorKey)
        : [];
    $featuredCount = count($featuredCards);
    $profilePageCount = max(1, (int) ceil(max(1, $profileTotal + $profileBskyCount + $profileBoostTotal) / $profilePerPage));
    $apFollowing = count($following);
    $apFollowers = count($followers);
    $combined = function_exists('ap_profile_combined_follow_counts')
        ? ap_profile_combined_follow_counts($actorKey, $apFollowers, $apFollowing)
        : [
            'followers' => $apFollowers,
            'following' => $apFollowing,
            'bsky_handle' => $bskyHandle,
        ];
    if ($bskyHandle === null && !empty($combined['bsky_handle'])) {
        $bskyHandle = (string) $combined['bsky_handle'];
    }

    echo '<div class="stats">';
    echo '<div><span class="n">' . ($profileTotal + $profileBskyCount + $profileBoostTotal) . '</span><span class="l">Posts</span></div>';
    $bskyAttr = $bskyHandle !== null ? ' data-bsky-handle="' . htmlspecialchars($bskyHandle, ENT_QUOTES, 'UTF-8') . '"' : '';
    $bskyTitle = $bskyHandle !== null ? ' title="Includes ActivityPub and connected Bluesky counts"' : '';
    echo '<a href="/users/' . $safe . '/following"' . $bskyAttr . $bskyTitle . '><span class="n" data-bsky-count="following">' . (int) $combined['following'] . '</span><span class="l">Following</span></a>';
    echo '<a href="/users/' . $safe . '/followers"' . $bskyAttr . $bskyTitle . '><span class="n" data-bsky-count="followers">' . (int) $combined['followers'] . '</span><span class="l">Followers</span></a>';
    echo '</div>';
    echo '<p class="feed-links"><a href="/users/' . $safe . '/feed.xml" type="application/rss+xml">RSS</a> · <a href="/users/' . $safe . '/feed.atom" type="application/atom+xml">Atom</a>';
    if ($bskyHandle !== null) {
        echo ' · <a href="https://bsky.app/profile/' . rawurlencode($bskyHandle) . '" rel="me noopener noreferrer" target="_blank">Bluesky</a>';
    }
    echo '</p>';

    echo '<nav class="profile-tabs" aria-label="Profile timeline">';
    foreach (
        [
            'posts' => ['Posts', $profileTotal + $profileBskyCount + $profileBoostTotal],
            'media' => ['Media', count($mediaNotes)],
            'replies' => ['Replies', count($profileReplyNotes) + count($profileBskyReplies)],
            'boosts' => ['Boosts', $profileBoostTotal],
            'featured' => ['Featured', $featuredCount],
            'blog' => ['Blog', $blogCount],
        ] as $tKey => $tInfo
    ) {
        $href = $tKey === 'posts'
            ? '/users/' . $safe
            : ('/users/' . $safe . '?tab=' . rawurlencode($tKey));
        $cls = $tab === $tKey ? ' class="is-active"' : '';
        $aria = $tab === $tKey ? ' aria-current="page"' : '';
        echo '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $cls . $aria . '>'
            . htmlspecialchars($tInfo[0], ENT_QUOTES, 'UTF-8')
            . '<span class="tab-count">' . (int) $tInfo[1] . '</span></a>';
    }
    echo '</nav>';

    if ($tab === 'blog') {
        echo '<section id="profile-blog" class="posts profile-blog" aria-label="Blog">';
        if ($blogPost) {
            $blogTitle = htmlspecialchars((string) ($blogPost['title'] ?? 'Untitled'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '<article class="profile-blog-post"><div class="muted">' . htmlspecialchars((string) (($blogPost['category'] ?? '') ?: 'Blog'), ENT_QUOTES, 'UTF-8') . '</div>';
            echo '<h2>' . $blogTitle . '</h2><div class="muted">' . htmlspecialchars((string) ($blogPost['published_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
            if (!empty($blogPost['tags']) && is_array($blogPost['tags'])) {
                echo '<p class="muted">' . htmlspecialchars(implode(' ', array_map(static fn($tag): string => '#' . (string) $tag, $blogPost['tags']),), ENT_QUOTES, 'UTF-8') . '</p>';
            }
            echo '<div class="note-body">' . ap_user_blog_markdown_html((string) ($blogPost['body_markdown'] ?? '')) . '</div>';
            echo '<p><a href="/users/' . $safe . '?tab=blog">← All blog posts</a></p></article>';
        } elseif (!$blogRows) {
            echo '<p class="muted">No public blog posts yet.</p>';
        } else {
            foreach ($blogRows as $blogRow) {
                $slugSafe = rawurlencode((string) ($blogRow['slug'] ?? ''));
                echo '<article class="profile-blog-post"><div class="muted">' . htmlspecialchars((string) (($blogRow['category'] ?? '') ?: 'Blog'), ENT_QUOTES, 'UTF-8') . '</div>';
                echo '<h2><a href="/users/' . $safe . '?tab=blog&amp;post=' . htmlspecialchars($slugSafe, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) ($blogRow['title'] ?? 'Untitled'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></h2>';
                echo '<p>' . htmlspecialchars((string) ($blogRow['excerpt'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
                echo '<p class="muted">' . htmlspecialchars((string) ($blogRow['published_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p></article>';
            }
        }
        echo '</section>';
    } elseif ($tab === 'media') {
        echo '<section id="profile-posts" class="posts profile-media-gallery profile-infinite" aria-label="Media" data-profile-page="' . (int) $profilePage . '" data-profile-pages="' . (int) $profilePageCount . '" data-profile-tab="media">';
        if (!$mediaNotes) {
            echo '<p class="muted">No public media posts yet.</p>';
        } else {
            foreach ($mediaNotes as [$mediaRow, $mediaObj]) {
                echo '<article class="profile-media-item">' . ap_user_note_media_html($mediaObj, true);
                $mediaText = trim(strip_tags((string) ($mediaObj['content'] ?? $mediaRow['content'] ?? '')));
                if ($mediaText !== '') {
                    echo '<div class="profile-media-caption">' . htmlspecialchars($mediaText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
                }
                $mediaDate = (string) ($mediaObj['published'] ?? $mediaRow['published'] ?? '');
                if ($mediaDate !== '') {
                    echo '<time class="profile-media-date" datetime="' . htmlspecialchars($mediaDate, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($mediaDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</time>';
                }
                echo '</article>';
            }
        }
        if ($profilePageCount > $profilePage) {
            echo '<div class="profile-infinite-sentinel" aria-hidden="true" style="height:1px"></div>';
        }
        echo '</section>';
    } elseif ($tab === 'replies') {
        echo '<section id="profile-replies" class="posts" aria-label="Replies">';
        if (!$profileReplyNotes && !$profileBskyReplies) {
            echo '<p class="muted">No replies yet.</p>';
        } else {
            foreach ($profileReplyNotes as $replyNote) {
                echo ap_user_post_preview_html($actorKey, $replyNote);
            }
            foreach ($profileBskyReplies as $replyItem) {
                echo ap_user_bsky_post_preview_html($actorKey, $replyItem);
            }
        }
        echo '</section>';
    } elseif ($tab === 'boosts') {
        echo '<section id="profile-boosts" class="posts" aria-label="Boosts">';
        if (!$profileBoosts) {
            echo '<p class="muted">No boosts yet.</p>';
        } else {
            foreach ($profileBoosts as $boost) {
                $object = rtrim((string) ($boost['object_id'] ?? ''), '/');
                if ($object === '') {
                    continue;
                }
                echo ap_user_boost_preview_html($actorKey, $boost, $profileOwnerId);
            }
            $boostPages = max(1, (int) ceil($profileBoostTotal / $profilePerPage));
            if ($boostPages > 1) {
                echo '<nav class="profile-pager" aria-label="Boost pages">';
                if ($profilePage > 1) echo '<a href="?tab=boosts&amp;page=' . ($profilePage - 1) . '">← Newer boosts</a> ';
                if ($profilePage < $boostPages) echo '<a href="?tab=boosts&amp;page=' . ($profilePage + 1) . '">Older boosts →</a>';
                echo '</nav>';
            }
        }
        echo '</section>';
    } elseif ($tab === 'featured') {
        echo '<section class="posts featured-section" aria-label="Featured">';
        echo function_exists('ap_featured_cards_html')
            ? ap_featured_cards_html($featuredCards)
            : '<p class="muted">No featured accounts yet.</p>';
        echo '</section>';
    } else {
        $profileItems = [];
        foreach ($publicNotes as $note) {
            $profileItems[] = ['sort' => strtotime((string) ($note['published'] ?? '')) ?: 0, 'kind' => 'local', 'row' => $note];
        }
        foreach ($profileBskyPosts as $bskyItem) {
            $post = is_array($bskyItem['post'] ?? null) ? $bskyItem['post'] : [];
            $profileItems[] = [
                'sort' => strtotime((string) (($post['record']['createdAt'] ?? '') ?: ($post['indexedAt'] ?? ''))) ?: 0,
                'kind' => 'bsky',
                'item' => $bskyItem,
            ];
        }
        foreach ($profileBoosts as $boost) {
            $profileItems[] = [
                'sort' => strtotime((string) ($boost['created_at'] ?? '')) ?: 0,
                'kind' => 'boost',
                'row' => $boost,
            ];
        }
        usort($profileItems, static fn(array $a, array $b): int => (int) ($b['sort'] ?? 0) <=> (int) ($a['sort'] ?? 0));
        $profileItems = array_slice($profileItems, ($profilePage - 1) * $profilePerPage, $profilePerPage);
        echo '<section id="profile-posts" class="posts profile-infinite" aria-label="Posts" data-profile-page="' . (int) $profilePage . '" data-profile-pages="' . (int) $profilePageCount . '">';
        if (!$profileItems && !$profileBoostTotal) {
            echo '<p class="muted">No public posts yet.</p>';
        } else {
            foreach ($profileItems as $profileItem) {
                if (($profileItem['kind'] ?? '') === 'bsky') {
                    echo ap_user_bsky_post_preview_html($actorKey, $profileItem['item'] ?? []);
                } elseif (($profileItem['kind'] ?? '') === 'boost' && is_array($profileItem['row'] ?? null)) {
                    $boost = $profileItem['row'];
                    $object = rtrim((string) ($boost['object_id'] ?? ''), '/');
                    if ($object === '') {
                        continue;
                    }
                    echo ap_user_boost_preview_html($actorKey, $boost, $profileOwnerId);
                } elseif (is_array($profileItem['row'] ?? null)) {
                    echo ap_user_post_preview_html($actorKey, $profileItem['row']);
                }
            }
        }
        if ($profilePageCount > $profilePage) {
            echo '<div class="profile-infinite-sentinel" aria-hidden="true" style="height:1px"></div>';
        }
        echo '</section>';
    }

    echo '<button type="button" class="profile-top-btn" id="profile-top-btn" hidden aria-label="Back to top">↑</button>';
    echo '<script>(function(){const box=document.getElementById("profile-posts"),top=document.getElementById("profile-top-btn");if(!top)return;const sync=()=>{top.hidden=(window.scrollY||0)<500;};window.addEventListener("scroll",sync,{passive:true});top.addEventListener("click",()=>window.scrollTo({top:0,behavior:"smooth"}));sync();if(!box)return;let page=+(box.dataset.profilePage||1),pages=+(box.dataset.profilePages||1),busy=false;const load=async()=>{if(busy||page>=pages)return;busy=true;try{const u=new URL(location.href);u.searchParams.set("page",String(page+1));const r=await fetch(u,{credentials:"same-origin"});if(!r.ok)throw 0;const d=new DOMParser().parseFromString(await r.text(),"text/html");const n=d.querySelector("#profile-posts");if(!n)throw 0;Array.from(n.children).forEach(el=>{if(!el.classList.contains("profile-infinite-sentinel")&&!el.classList.contains("profile-pager"))box.insertBefore(el,box.querySelector(".profile-infinite-sentinel"));});page++;box.dataset.profilePage=String(page);if(page>=pages){const old=box.querySelector(".profile-infinite-sentinel");if(old)old.remove();}}catch(e){}finally{busy=false;}};const io=new IntersectionObserver(es=>{if(es.some(x=>x.isIntersecting))load();},{rootMargin:"500px"});const sentinel=box.querySelector(".profile-infinite-sentinel");if(sentinel)io.observe(sentinel);}());</script>';
    echo '<script>(function(){';
    echo 'var ACTOR=' . json_encode($actorId, JSON_UNESCAPED_SLASHES) . ';';
    echo 'var toggle=document.getElementById("ap-follow-toggle");';
    echo 'var panel=document.getElementById("ap-follow-panel");';
    echo 'var form=document.getElementById("ap-follow-form");';
    echo 'var input=document.getElementById("ap-follow-handle");';
    echo 'if(!toggle||!panel||!form||!input||!ACTOR)return;';
    echo 'function parseHost(raw){var s=String(raw||"").trim();if(!s)return null;if(s.indexOf("acct:")===0)s=s.slice(5);s=s.replace(/^https?:\\/\\//i,"").replace(/\\/.*$/,"");if(s.charAt(0)==="@"){var m=s.match(/^@([A-Za-z0-9_.\\-]+)@([A-Za-z0-9.\\-]+\\.[A-Za-z]{2,})$/);return m?m[2].toLowerCase():null;}s=s.replace(/^@/,"");if(/^[A-Za-z0-9.\\-]+\\.[A-Za-z]{2,}$/.test(s))return s.toLowerCase();return null;}';
    echo 'toggle.addEventListener("click",function(){var open=panel.hasAttribute("hidden");if(open){panel.removeAttribute("hidden");toggle.setAttribute("aria-expanded","true");}else{panel.setAttribute("hidden","");toggle.setAttribute("aria-expanded","false");}});';
    echo 'form.addEventListener("submit",function(ev){ev.preventDefault();var host=parseHost(input.value);if(!host){alert("Enter your instance domain or @you@instance");return;}window.location.href="https://"+host+"/authorize_interaction?uri="+encodeURIComponent(ACTOR);});';
    echo '})();</script>';

    ap_user_html_shell_end();
}

/**
 * Mastodon-like media grid for HTML profile posts (images/videos from AS2 attachment).
 *
 * @param array<string,mixed> $note
 */
function ap_user_note_media_html(array $note, bool $interactive = true): string
{
    $atts = $note['attachment'] ?? [];
    if (!is_array($atts)) {
        return '';
    }
    if (isset($atts['type'])) {
        $atts = [$atts];
    }
    $cells = [];
    foreach ($atts as $att) {
        if (!is_array($att)) {
            continue;
        }
        $url = '';
        if (isset($att['url']) && is_string($att['url'])) {
            $url = $att['url'];
        } elseif (isset($att['url']['href']) && is_string($att['url']['href'])) {
            $url = $att['url']['href'];
        }
        if ($url === '' || !str_starts_with($url, 'https://')) {
            continue;
        }
        $mt = strtolower((string) ($att['mediaType'] ?? ''));
        $atype = (string) ($att['type'] ?? '');
        $poster = '';
        $thumb = $att['thumbnail'] ?? ($att['preview'] ?? null);
        if (is_string($thumb)) {
            $poster = $thumb;
        } elseif (is_array($thumb)) {
            $poster = (string) ($thumb['url'] ?? $thumb['href'] ?? '');
        }
        if (!str_starts_with($poster, 'https://')) {
            $poster = '';
        }
        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safePoster = $poster !== '' ? htmlspecialchars($poster, ENT_QUOTES, 'UTF-8') : '';
        $alt = htmlspecialchars((string) ($att['name'] ?? $att['summary'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $isVideo = str_starts_with($mt, 'video/') || $atype === 'Video'
            || (bool) preg_match('/\.(mp4|webm|mov|m4v)(\?|$)/i', (string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if (str_starts_with($mt, 'image/') || $atype === 'Image') {
            if ($interactive) {
                // Same lightbox as cmdr_nova HTML profile posts (.note-media-trigger).
                $cells[] = '<button type="button" class="note-media-trigger media-cell" data-full="' . $safe . '" aria-label="View full image">'
                    . '<img src="' . $safe . '" alt="' . $alt . '" loading="lazy" referrerpolicy="no-referrer">'
                    . '</button>';
            } else {
                $cells[] = '<img src="' . $safe . '" alt="' . $alt . '" loading="lazy" referrerpolicy="no-referrer">';
            }
        } elseif ($isVideo) {
            $cells[] = '<video class="media-video" src="' . $safe . '" '
                . ($interactive ? 'controls ' : 'muted ')
                . 'playsinline loop preload="metadata"'
                . ($safePoster !== '' ? ' poster="' . $safePoster . '"' : '')
                . ' referrerpolicy="no-referrer"></video>';
        } elseif (str_starts_with($mt, 'audio/') || $atype === 'Audio') {
            $cells[] = '<div class="media-audio-card" role="group" aria-label="Audio post">'
                . '<img class="media-audio-art" src="/api/assets/audio-post-default.jpg" alt="" loading="lazy" decoding="async">'
                . '<audio class="media-audio" src="' . $safe . '" controls preload="auto"></audio>'
                . '</div>';
        }
        if (count($cells) >= 4) {
            break;
        }
    }
    if ($cells === []) {
        return '';
    }
    $n = count($cells);
    return '<div class="media-row media-count-' . $n . '">' . implode('', $cells) . '</div>';
}

/**
 * @param array<string,mixed> $row
 */
function ap_user_post_preview_html(string $actorKey, array $row): string
{
    $create = json_decode((string) ($row['raw_create_json'] ?? ''), true);
    $note = is_array($create) && is_array($create['object'] ?? null) ? $create['object'] : [];
    $content = (string) ($note['content'] ?? $row['content'] ?? '');
    $content = function_exists('ap_html_sanitize_allowlist')
        ? ap_html_sanitize_allowlist($content, '<p><br><a><strong><em><code><ul><ol><li>')
        : htmlspecialchars(strip_tags($content), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $content = function_exists('ap_profile_markdown_inline') ? ap_profile_markdown_inline($content) : $content;
    $published = (string) ($note['published'] ?? $row['published'] ?? '');
    $dateLabel = $published;
    try {
        $dateLabel = (new DateTimeImmutable($published))->format('M j, Y · g:i A T');
    } catch (Throwable $e) {
        // keep
    }
    $id = htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeDate = htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8');
    $html = '<article class="post">';
    if (!empty($note['summary']) && is_string($note['summary'])) {
        $html .= '<p class="cw"><strong>CW</strong> · '
            . htmlspecialchars($note['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    $html .= '<div class="note-body">' . $content . '</div>';
    $html .= ap_user_note_media_html($note, true);
    $html .= '<p class="muted" style="font-size:.8rem;margin:.6rem 0 0"><a href="' . $id . '">' . $safeDate . '</a></p>';
    $html .= ap_webmention_cards_html((string) ($row['id'] ?? ''));
    $html .= '</article>';
    return $html;
}

/** Render one cached Bluesky post on a local HTML profile. */
function ap_user_bsky_post_preview_html(string $actorKey, array $item, bool $showRepostLabel = true): string
{
    $post = is_array($item['post'] ?? null) ? $item['post'] : [];
    if ($post === []) {
        return '';
    }
    $record = is_array($post['record'] ?? null) ? $post['record'] : [];
    $text = trim((string) ($record['text'] ?? $post['text'] ?? ''));
    $body = ap_user_bsky_richtext_html($text, is_array($record['facets'] ?? null) ? $record['facets'] : []);
    $uri = trim((string) ($post['uri'] ?? $item['bsky_uri'] ?? ''));
    $handle = trim((string) (($post['author']['handle'] ?? '') ?: ''));
    $postUrl = function_exists('ap_bsky_post_url') ? ap_bsky_post_url($post) : '';
    if ($postUrl === '' && $handle !== '' && preg_match('/([^/]+)$/', $uri, $m)) {
        $postUrl = 'https://bsky.app/profile/' . rawurlencode($handle) . '/post/' . rawurlencode((string) $m[1]);
    }
    $created = (string) ($record['createdAt'] ?? $post['indexedAt'] ?? '');
    $dateLabel = $created;
    try {
        $dateLabel = (new DateTimeImmutable($created))->format('M j, Y · g:i A T');
    } catch (Throwable $e) {
        // Keep the raw timestamp when Bluesky returns an unusual value.
    }
    $reason = is_array($item['reason'] ?? null) ? $item['reason'] : [];
    $reasonType = strtolower((string) ($reason['$type'] ?? ''));
    $isRepost = str_contains($reasonType, 'reasonrepost');
    $mediaHtml = '';
    if (function_exists('ap_bsky_media_items_from_embeds')) {
        $media = ap_bsky_media_items_from_embeds($post['embed'] ?? ($record['embed'] ?? []));
        foreach ($media as $m) {
            $url = trim((string) ($m['url'] ?? ''));
            if ($url === '' || !str_starts_with($url, 'https://')) {
                continue;
            }
            $preview = trim((string) ($m['preview_url'] ?? $url));
            if (str_contains((string) ($m['mediaType'] ?? ''), 'mpegURL')) {
                $mediaHtml .= '<video controls preload="metadata" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" poster="' . htmlspecialchars($preview, ENT_QUOTES, 'UTF-8') . '"></video>';
            } else {
                $mediaHtml .= '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy" referrerpolicy="no-referrer">';
            }
        }
        if ($mediaHtml !== '') {
            $mediaHtml = '<div class="media-row">' . $mediaHtml . '</div>';
        }
    }
    $html = '<article class="post bsky-profile-post">';
    if ($isRepost && $showRepostLabel) {
        $html .= '<p class="muted" style="margin:0 0 .45rem">↻ boosted on Bluesky</p>';
    }
    $html .= '<p class="muted" style="margin:0 0 .45rem;font-size:.8rem"><span class="badge">Bluesky</span>';
    if ($handle !== '') {
        $html .= ' · @' . htmlspecialchars($handle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    $html .= '</p>';
    if ($body !== '') {
        $html .= '<div class="note-body">' . $body . '</div>';
    } elseif ($mediaHtml === '') {
        $html .= '<div class="note-body muted">No text content was cached for this post.</div>';
    }
    $html .= $mediaHtml;
    if ($postUrl !== '') {
        $html .= '<p class="muted" style="font-size:.8rem;margin:.6rem 0 0"><a href="' . htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($dateLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' · Open on Bluesky</a></p>';
    } else {
        $html .= '<p class="muted" style="font-size:.8rem;margin:.6rem 0 0">' . htmlspecialchars($dateLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    return $html . '</article>';
}

/** Render Bluesky UTF-8 text plus rich-text facets as safe HTML links. */
function ap_user_bsky_richtext_html(string $text, array $facets = []): string
{
    if ($text === '') {
        return '';
    }
    $escape = static fn(string $s): string => nl2br(htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $ranges = [];
    foreach ($facets as $facet) {
        if (!is_array($facet) || !is_array($facet['index'] ?? null)) {
            continue;
        }
        $start = (int) ($facet['index']['byteStart'] ?? -1);
        $end = (int) ($facet['index']['byteEnd'] ?? -1);
        if ($start < 0 || $end <= $start || $end > strlen($text)) {
            continue;
        }
        $feature = is_array($facet['features'][0] ?? null) ? $facet['features'][0] : [];
        $type = (string) ($feature['$type'] ?? '');
        $href = '';
        if (str_contains($type, '#link') && str_starts_with((string) ($feature['uri'] ?? ''), 'https://')) {
            $href = (string) $feature['uri'];
        } elseif (str_contains($type, '#tag')) {
            $tag = ltrim(substr($text, $start, $end - $start), '#');
            if ($tag !== '') {
                $href = 'https://bsky.app/hashtag/' . rawurlencode($tag);
            }
        } elseif (str_contains($type, '#mention') && str_starts_with((string) ($feature['did'] ?? ''), 'did:')) {
            $href = 'https://bsky.app/profile/' . rawurlencode((string) $feature['did']);
        }
        $ranges[] = [$start, $end, $href];
    }
    usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    if ($ranges === []) {
        // Older cache rows may not retain facets; still make ordinary URLs
        // clickable without trusting any HTML from the post body.
        $parts = preg_split('~(https?://[^\s<>]+)~i', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';
        foreach ($parts as $part) {
            if (preg_match('~^https?://[^\s<>]+$~i', $part)) {
                $html .= '<a href="' . htmlspecialchars($part, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
            } else {
                $html .= $escape($part);
            }
        }
        return $html;
    }
    $html = '';
    $cursor = 0;
    foreach ($ranges as [$start, $end, $href]) {
        if ($start < $cursor) {
            continue;
        }
        $html .= $escape(substr($text, $cursor, $start - $cursor));
        $label = substr($text, $start, $end - $start);
        $html .= $href !== ''
            ? '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $escape($label) . '</a>'
            : $escape($label);
        $cursor = $end;
    }
    return $html . $escape(substr($text, $cursor));
}

/** Render the post behind a boost, rather than exposing only its URL. */
function ap_user_bsky_cached_item_for_url(string $url): ?array
{
    if (!preg_match('~^https://bsky\.app/profile/([^/]+)/post/([^/?#]+)~i', trim($url), $m)) {
        return null;
    }
    $handle = rawurldecode($m[1]);
    $rkey = rawurldecode($m[2]);
    static $cache = [];
    if (!array_key_exists($handle, $cache)) {
        $cache[$handle] = [];
        try {
            $st = ap_db()->prepare('SELECT raw_json, reason_json, bsky_uri FROM bsky_posts WHERE author_handle = ? ORDER BY indexed_at DESC LIMIT 500');
            $st->execute([$handle]);
            foreach ($st->fetchAll() ?: [] as $row) {
                if (!is_array($row)) continue;
                $post = json_decode((string) ($row['raw_json'] ?? ''), true);
                if (!is_array($post)) continue;
                $uri = (string) ($row['bsky_uri'] ?? '');
                if (!preg_match('~/([^/]+)$~', $uri, $um)) continue;
                $item = ['post' => $post, 'bsky_uri' => $uri];
                $reason = json_decode((string) ($row['reason_json'] ?? ''), true);
                if (is_array($reason)) $item['reason'] = $reason;
                $cache[$handle][$um[1]] = $item;
            }
        } catch (Throwable $e) { /* cache miss */ }
    }
    return $cache[$handle][$rkey] ?? null;
}

function ap_user_boost_preview_html(string $actorKey, array $boost, int $ownerUserId = 0): string
{
    $object = rtrim((string) ($boost['object_id'] ?? ''), '/');
    if ($object === '') {
        return '';
    }
    if (str_contains(strtolower($object), 'bsky.app/')) {
        // Never resolve a remote handle during HTML rendering. The cache is
        // populated by the background Bluesky worker and keeps profiles fast.
        $item = ap_user_bsky_cached_item_for_url($object);
        if (!is_array($item) && function_exists('ap_bsky_at_uri_from_https') && preg_match('~^https://bsky\.app/profile/(did:[^/]+)/post/~i', $object)) {
            $uri = ap_bsky_at_uri_from_https($object, $ownerUserId);
            $item = $uri !== null && function_exists('ap_bsky_post_item_by_uri') ? ap_bsky_post_item_by_uri($uri) : null;
        }
        if (is_array($item)) {
            $boostedAt = (string) ($boost['created_at'] ?? '');
            $boostDate = $boostedAt;
            try { $boostDate = (new DateTimeImmutable($boostedAt))->format('M j, Y · g:i A T'); } catch (Throwable $e) { /* keep */ }
            $date = $boostDate !== '' ? ' · ' . htmlspecialchars($boostDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
            return '<div class="profile-boost"><p class="muted profile-boost-label">↻ boosted on Bluesky' . $date . '</p>'
                . ap_user_bsky_post_preview_html($actorKey, $item, false)
                . '</div>';
        }
    }

    $raw = '';
    if (function_exists('ap_event_by_object_id')) {
        $event = ap_event_by_object_id($object);
        if (is_array($event)) {
            $raw = (string) (($event['content'] ?? '') ?: ($event['summary'] ?? ''));
        }
    }
    if ($raw === '') {
        try {
            $st = ap_db()->prepare('SELECT content FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
            $st->execute([$object, $object . '/']);
            $row = $st->fetch();
            $raw = is_array($row) ? (string) ($row['content'] ?? '') : '';
        } catch (Throwable $e) {
            // cache miss
        }
    }
    $plain = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $body = $plain !== ''
        ? preg_replace_callback('~https?://[^\s<>]+~i', static fn(array $m): string => '<a href="' . htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($m[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>', htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
        : '<span class="muted">Unable to load the boosted post content.</span>';
    $boostedAt = (string) ($boost['created_at'] ?? '');
    $boostDate = $boostedAt;
    try { $boostDate = (new DateTimeImmutable($boostedAt))->format('M j, Y · g:i A T'); } catch (Throwable $e) { /* keep */ }
    $date = $boostDate !== '' ? ' · ' . htmlspecialchars($boostDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
    return '<article class="post profile-boost"><p class="muted profile-boost-label">↻ boosted · Fediverse' . $date . '</p>'
        . '<div class="note-body">' . nl2br((string) $body) . '</div>'
        . '<p class="muted" style="font-size:.8rem;margin:.6rem 0 0"><a href="' . htmlspecialchars($object, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">Open original post</a></p></article>';
}

/** Small safe Markdown renderer for published blog bodies on generic profiles. */
function ap_user_blog_markdown_html(string $markdown): string
{
    $lines = preg_split('/\R/u', trim($markdown)) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = rtrim((string) $line);
        if ($line === '') {
            continue;
        }
        $safe = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safe = preg_replace_callback('/\[([^\]]+)\]\((https:\/\/[^\s\)]+)\)/', static fn(array $m): string => '<a href="' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>', $safe) ?? $safe;
        $safe = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $safe) ?? $safe;
        $safe = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $safe) ?? $safe;
        if (preg_match('/^###\s+(.+)$/', $line, $m)) {
            $out[] = '<h4>' . htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h4>';
        } elseif (preg_match('/^##\s+(.+)$/', $line, $m)) {
            $out[] = '<h3>' . htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>';
        } elseif (preg_match('/^#\s+(.+)$/', $line, $m)) {
            $out[] = '<h2>' . htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';
        } else {
            $out[] = '<p>' . $safe . '</p>';
        }
    }
    return implode("\n", $out);
}

/**
 * @param array<string,mixed> $row
 * @param array<string,mixed> $create
 */
function ap_user_note_html(string $actorKey, array $row, array $create): void
{
    $note = is_array($create['object'] ?? null) ? $create['object'] : [];
    $content = function_exists('ap_html_sanitize_allowlist')
        ? ap_html_sanitize_allowlist((string) ($note['content'] ?? $row['content'] ?? ''), '<p><br><a><strong><em><code><ul><ol><li>')
        : htmlspecialchars(strip_tags((string) ($note['content'] ?? $row['content'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $content = function_exists('ap_profile_markdown_inline') ? ap_profile_markdown_inline($content) : $content;
    $p = ap_profile_get($actorKey);
    $name = htmlspecialchars((string) $p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safe = htmlspecialchars($actorKey, ENT_QUOTES, 'UTF-8');
    $published = (string) ($note['published'] ?? $row['published'] ?? '');
    $dateLabel = $published;
    try {
        $dateLabel = (new DateTimeImmutable($published))->format('M j, Y · g:i A T');
    } catch (Throwable $e) {
        // keep
    }
    $replyTo = rtrim((string) ($row['in_reply_to'] ?? ''), '/');
    if ($replyTo === '' && !empty($note['inReplyTo']) && is_string($note['inReplyTo'])) {
        $replyTo = rtrim($note['inReplyTo'], '/');
    }
    ap_user_html_shell_start('Post · @' . $actorKey . '@mkultra.monster');
    echo '<div class="row" style="margin-bottom:1rem">';
    $avUrl = function_exists('ap_local_avatar_url')
        ? ap_local_avatar_url($p['icon_url'] ?? null)
        : ((string) ($p['icon_url'] ?? '') ?: '/img/avatar/local-default.webp');
    echo '<img class="av" src="' . htmlspecialchars($avUrl, ENT_QUOTES, 'UTF-8') . '" alt="" width="72" height="72" loading="lazy" referrerpolicy="no-referrer">';
    echo '<div><h1 style="font-size:1.1rem">' . $name . '</h1>';
    echo '<p class="muted" style="margin:0"><a href="/users/' . $safe . '">@' . $safe . '@mkultra.monster</a></p></div></div>';
    if ($replyTo !== '' && str_starts_with($replyTo, 'https://')) {
        $rSafe = htmlspecialchars($replyTo, ENT_QUOTES, 'UTF-8');
        $localParent = str_starts_with($replyTo, 'https://mkultra.monster/users/')
            && str_contains($replyTo, '/notes/');
        echo '<p class="reply-line">↩ reply to <a href="' . $rSafe . '"'
            . ($localParent ? '' : ' target="_blank" rel="noopener noreferrer"') . '>'
            . $rSafe . '</a></p>';
    }
    if (!empty($note['summary']) && is_string($note['summary'])) {
        echo '<p class="cw"><strong>CW</strong> · '
            . htmlspecialchars($note['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    echo '<div class="note-body">' . $content . '</div>';
    echo ap_user_note_media_html($note, true);
    echo '<p class="muted" style="margin-top:1.25rem;font-size:.85rem">'
        . htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') . '</p>';
    echo ap_webmention_cards_html((string) ($row['id'] ?? ''));

    $noteUriRaw = (string) ($row['id'] ?? '');
    if ($noteUriRaw !== '' && function_exists('ap_note_public_replies')) {
        $replies = ap_note_public_replies($noteUriRaw, 40);
        echo '<section class="note-replies" aria-label="Replies">';
        echo '<h2 class="note-replies-title">Replies'
            . ($replies !== [] ? (' <span class="muted">(' . count($replies) . ')</span>') : '')
            . '</h2>';
        if ($replies === []) {
            echo '<p class="muted" style="margin:.35rem 0 0">No replies yet.</p>';
        }
        foreach ($replies as $rep) {
            $rUrl = (string) ($rep['url'] ?? '');
            $rActor = (string) ($rep['actor_id'] ?? '');
            $rContent = (string) ($rep['content'] ?? '');
            $rWhen = (string) ($rep['published'] ?? '');
            $rKind = (string) ($rep['kind'] ?? '');
            $handle = $rActor;
            if (preg_match('#/users/([A-Za-z0-9_]+)$#', $rActor, $hm)) {
                $handle = '@' . $hm[1]
                    . (str_contains($rActor, 'mkultra.monster') ? '@mkultra.monster' : '');
            } elseif (preg_match('#bsky\.app/profile/([^/?#]+)#i', $rActor, $bm)) {
                $handle = '@' . rawurldecode($bm[1]);
            }
            $snip = $rContent;
            if (function_exists('mb_strlen') && mb_strlen($snip) > 320) {
                $snip = mb_substr($snip, 0, 320) . '…';
            } elseif (strlen($snip) > 320) {
                $snip = substr($snip, 0, 320) . '…';
            }
            $rSpoiler = trim((string) ($rep['spoiler_text'] ?? ''));
            $rSensitive = !empty($rep['sensitive']) || $rSpoiler !== '';
            $localReply = str_starts_with($rUrl, 'https://mkultra.monster/users/')
                && str_contains($rUrl, '/notes/');
            $isBsky = ($rKind === 'bluesky') || str_contains($rUrl, 'bsky.app/');
            echo '<article class="note-reply"><div class="note-reply-hd"><span class="who">'
                . htmlspecialchars($handle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</span>';
            if ($isBsky) {
                echo ' <span class="badge" style="font-size:.7rem">Bluesky</span>';
            }
            if ($rWhen !== '') {
                echo ' <span class="muted">· '
                    . htmlspecialchars($rWhen, ENT_QUOTES, 'UTF-8') . '</span>';
            }
            echo '</div>';
            $bodyHtml = $snip !== ''
                ? ('<div class="note-reply-body">'
                    . (function_exists('ap_profile_markdown_inline') ? ap_profile_markdown_inline(htmlspecialchars($snip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) : htmlspecialchars($snip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                    . '</div>')
                : '';
            if ($rSensitive) {
                $cwLabel = $rSpoiler !== ''
                    ? ('CW · ' . htmlspecialchars($rSpoiler, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                    : 'CW · Sensitive';
                echo '<details class="note-reply-cw-gate"><summary>' . $cwLabel
                    . ' <span class="muted" style="font-weight:500">· show</span></summary>';
                echo $bodyHtml;
                echo '</details>';
            } elseif ($bodyHtml !== '') {
                echo $bodyHtml;
            }
            if ($rUrl !== '' && str_starts_with($rUrl, 'https://')) {
                $openLabel = $localReply ? 'Open reply' : ($isBsky ? 'Open on Bluesky' : 'Open on remote');
                echo '<div class="muted" style="margin-top:.35rem;font-size:.78rem"><a href="'
                    . htmlspecialchars($rUrl, ENT_QUOTES, 'UTF-8') . '"'
                    . ($localReply ? '' : ' target="_blank" rel="noopener noreferrer"')
                    . '>' . $openLabel . '</a></div>';
            }
            echo '</article>';
        }
        echo '</section>';
    }

    echo '<p class="back"><a href="/users/' . $safe . '">← profile</a></p>';
    ap_user_html_shell_end();
}

function ap_user_html_shell_start(string $title): void
{
    $t = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow">';
    echo '<title>' . $t . '</title>';
    echo '<link rel="icon" href="/vaak/profile-favicon.svg?v=20260907b" type="image/svg+xml">';
    echo '<link rel="shortcut icon" href="/vaak/profile-favicon.svg?v=20260907b" type="image/svg+xml">';
    echo '<link rel="icon" href="/vaak/profile-favicon.ico?v=20260907b" sizes="any">';
    echo '<link rel="icon" href="/vaak/profile-favicon-32x32.png?v=20260907b" type="image/png" sizes="32x32">';
    echo '<link rel="apple-touch-icon" href="/vaak/apple-touch-icon.png?v=20260907">';
    echo '<link rel="webmention" href="https://mkultra.monster/api/ap-webmention.php">';
    echo '<link rel="alternate" type="application/json+oembed" href="https://mkultra.monster/api/ap-oembed.php?url=' . rawurlencode('https://mkultra.monster/users/' . $safe) . '&amp;format=json">';
    echo '<style>
      :root{color-scheme:dark}
      body{margin:0;font-family:system-ui,sans-serif;background:#101010;color:#ddd}
      main{max-width:42rem;margin:0 auto;padding:1.25rem;box-sizing:border-box}
      .card{border:1px solid #2a2a2a;border-radius:14px;padding:1.25rem;background:#121212}
      a{color:#7ee0ff}.muted{color:#999}
      h1{margin:.2rem 0;font-size:1.45rem}
      .row{display:flex;gap:1rem;align-items:center}
      .sl-link a{display:inline-flex;align-items:center;padding:.2rem .3rem;border:1px solid #333;border-radius:8px;background:#121212;color:#ddd;text-decoration:none}
      .sl-link a:hover{border-color:#7ee0ff;background:#1a1a1a;color:#fff}
      .sl-link img{display:block;border-radius:8px;flex:0 0 auto}
      .av{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid #333;background:#1a1a1a}
      .av-fallback{display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:700;color:#00ff9f}
      .banner{height:180px;border-radius:12px;background-size:cover;background-position:center;margin:-.25rem -.25rem 1rem;border:1px solid #2a2a2a}
      .profile-bio{margin:1rem 0;line-height:1.45}
      .fields{display:grid;grid-template-columns:1fr 1fr;gap:.65rem .85rem;margin:1rem 0;font-size:.88rem}
      .fields .field{min-width:0;overflow:hidden}
      .fields .field-label{display:block;color:#999;font-size:.75rem;margin:0 0 .2rem}
      .fields .field-value{margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .fields .field-value a{overflow:hidden;text-overflow:ellipsis}
      @media (max-width:520px){.fields{grid-template-columns:1fr}}
      .stats{display:flex;gap:1.25rem;margin:1.1rem 0 0;padding-top:1rem;border-top:1px solid #2a2a2a}
      .stats a{text-decoration:none;color:inherit}
      .stats .n{display:block;font-size:1.25rem;font-weight:700;color:#00ff9f}
      .stats .l{font-size:.8rem;color:#999;text-transform:uppercase}
      .feed-links{font-size:.8rem;margin:.65rem 0 0}.feed-links a{color:#999}.feed-links a:hover{color:#7ee0ff}
      .webmention-cards{margin-top:.8rem;border-top:1px solid #2a2a2a;padding-top:.65rem}.webmention-cards h3{font-size:.8rem;color:#999;margin:0 0 .45rem}.webmention-card{padding:.45rem 0;border-bottom:1px solid #222;font-size:.8rem}.webmention-card a{color:#7ee0ff}.webmention-card p{margin:.25rem 0;color:#bbb}.webmention-card time{color:#777;font-size:.72rem}
      .profile-tabs{display:flex;gap:.35rem;margin:1.15rem 0 0;padding-top:1rem;border-top:1px solid #2a2a2a;flex-wrap:wrap}
      .profile-tabs a{text-decoration:none;color:#aaa;font-size:.9rem;font-weight:600;padding:.45rem .9rem;border-radius:999px}
      .profile-tabs a:hover{color:#eee;background:#1a1a1a}
      .profile-tabs a.is-active{color:#0b0b0b;background:#00ff9f}
      .profile-tabs a .tab-count{opacity:.7;font-weight:500;margin-left:.25rem;font-size:.8rem}
      .profile-media-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.8rem;align-items:start}
      .profile-media-item{min-width:0;padding:.6rem;background:#111;border:1px solid #292929;border-radius:12px;overflow:hidden}
      .profile-media-item .media-row{margin:0;border-radius:8px;overflow:hidden}
      .profile-media-caption{margin-top:.55rem;color:#ccc;font-size:.88rem;line-height:1.4;overflow-wrap:anywhere}
      .profile-media-date{display:block;margin-top:.4rem;color:#858585;font-size:.75rem}
      .featured-accounts{list-style:none;margin:0;padding:0}
      .featured-account{border-bottom:1px solid #2a2a2a}
      .featured-account:last-child{border-bottom:none}
      .featured-account-link{display:flex;align-items:center;gap:.85rem;padding:.85rem 0;color:inherit;text-decoration:none}
      .featured-account-link:hover{background:rgba(126,224,255,.04);margin:0 -.5rem;padding-left:.5rem;padding-right:.5rem;border-radius:8px}
      .featured-av{width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid #333;background:#222;flex:0 0 auto}
      .featured-meta{display:flex;flex-direction:column;gap:.15rem;min-width:0}
      .featured-name{font-weight:650;color:#e8e8e8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .featured-acct{font-size:.85rem;color:#999;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .posts{margin-top:1.25rem;border-top:1px solid #2a2a2a;padding-top:.5rem}
      .profile-pager{display:flex;justify-content:center;align-items:center;gap:.8rem;margin:1rem 0;color:#999;font-size:.85rem}.profile-pager a{color:#7ee0ff;text-decoration:none}
      .profile-top-btn{position:fixed;right:1.25rem;bottom:1.25rem;z-index:20;border:1px solid #333;border-radius:999px;background:#161616;color:#8bf;width:2.8rem;height:2.8rem;font-size:1.2rem;cursor:pointer;box-shadow:0 5px 18px #0008}.profile-top-btn:hover{border-color:#8bf}
      .automated-flare{display:inline-block;margin-left:.45rem;padding:.15rem .5rem;border:1px solid var(--primary,#53e68b);border-radius:999px;color:var(--primary,#53e68b);font-size:.58em;font-weight:600;vertical-align:middle}
      .post{padding:.9rem 0;border-bottom:1px solid #222;min-width:0;overflow-wrap:anywhere}
      .note-body{min-width:0;overflow-wrap:anywhere;word-break:break-word}
      .note-body a{overflow-wrap:anywhere;word-break:break-word}
      .note-body p{margin:.4rem 0}.cw{color:#f0c674;font-size:.9rem}
      .reply-line{font-size:.8rem;color:#8ab;margin:0 0 .45rem}
      .reply-line a{color:#9ad4e8;text-decoration:none}
      .note-replies{margin-top:1.35rem;padding-top:1rem;border-top:1px solid #2a2a2a}
      .note-replies-title{margin:0 0 .65rem;font-size:.95rem;font-weight:650;color:#ddd}
      .note-reply{padding:.7rem 0;border-bottom:1px solid #222}
      .note-reply:last-child{border-bottom:none}
      .note-reply-hd{font-size:.85rem;margin:0 0 .3rem}
      .note-reply-hd .who{font-weight:650;color:#e8e8e8}
      .note-reply-body{font-size:.92rem;line-height:1.45;color:#ccc;white-space:pre-wrap;word-break:break-word}
      .note-reply-cw-gate>summary{cursor:pointer;list-style:none;color:#f0c674;font-size:.82rem;font-weight:650;margin:.15rem 0 .35rem}
      .note-reply-cw-gate>summary::-webkit-details-marker{display:none}
      .note-reply-cw-gate>summary::before{content:"▸ ";opacity:.8}
      .note-reply-cw-gate[open]>summary::before{content:"▾ "}
      .note-reply-cw-gate .note-reply-body{margin-top:.25rem}
      .media-row{display:grid;gap:.28rem;margin-top:.55rem;width:100%;border-radius:12px;overflow:hidden;border:1px solid #2a2a2a;background:#0a0a0a}
      .media-row.media-count-1{grid-template-columns:1fr}
      .media-row.media-count-2{grid-template-columns:1fr 1fr}
      .media-row.media-count-3{grid-template-columns:1fr 1fr;grid-template-rows:minmax(120px,1fr) minmax(120px,1fr)}
      .media-row.media-count-3>:first-child{grid-row:1 / span 2}
      .media-row.media-count-4{grid-template-columns:1fr 1fr;grid-template-rows:minmax(120px,1fr) minmax(120px,1fr)}
      .media-row .media-cell,.media-row .note-media-trigger{display:block;width:100%;height:100%;min-height:0;padding:0;margin:0;border:0;background:#0c0c0c;cursor:zoom-in}
      .note-media-trigger img{display:block;width:100%;height:100%;object-fit:cover}
      .ap-img-lightbox{position:fixed;inset:0;z-index:200;display:none;align-items:center;justify-content:center;padding:1rem;background:rgba(0,0,0,.88);backdrop-filter:blur(3px)}
      .ap-img-lightbox.open{display:flex}
      .ap-img-lightbox img{max-width:min(96vw,1200px);max-height:92vh;object-fit:contain;border-radius:8px;box-shadow:0 12px 40px rgba(0,0,0,.55)}
      .ap-img-lightbox__close{position:absolute;top:max(.75rem,env(safe-area-inset-top));right:max(.75rem,env(safe-area-inset-right));appearance:none;border:0;border-radius:999px;width:2.4rem;height:2.4rem;background:rgba(255,255,255,.14);color:#fff;font-size:1.4rem;line-height:1;cursor:pointer}
      .ap-img-lightbox__close:hover{background:rgba(255,255,255,.24)}
      .media-row img,.media-row video{display:block;width:100%;height:100%;max-height:min(58vh,520px);object-fit:cover;background:#0c0c0c;border:0}
      .media-row.media-count-1 .media-cell{height:auto}
      .media-row.media-count-1 img{height:auto;object-fit:contain;max-height:min(62vh,560px);min-height:0;background:transparent}
      .media-row video{object-fit:contain;max-height:min(62vh,560px);background:#000}
      .media-row.media-count-1 video{height:auto;aspect-ratio:auto;object-fit:contain;max-height:min(80vh,900px)}
      .media-audio-card{position:relative;display:flex;align-items:flex-end;min-height:220px;overflow:hidden;background:#050505}
      .media-audio-art{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.72}
      .media-audio-card::after{content:"";position:absolute;inset:35% 0 0;background:linear-gradient(transparent,rgba(0,0,0,.86));pointer-events:none}
      .media-audio{position:relative;z-index:1;width:calc(100% - 1.5rem);margin:.75rem}
      .btn-follow{appearance:none;border:0;border-radius:999px;padding:.55rem 1.15rem;font:inherit;font-weight:600;cursor:pointer;background:#8bf;color:#061018;margin-top:.75rem}
      .follow-panel{margin-top:.75rem;padding:.85rem;border:1px solid #2a2e37;border-radius:12px;background:#0c0c0c}
      .follow-panel[hidden]{display:none!important}
      .follow-row{display:flex;gap:.5rem;flex-wrap:wrap}
      .follow-row input{flex:1;min-width:12rem;padding:.55rem .7rem;border-radius:10px;border:1px solid #333;background:#111;color:#eee;font:inherit;font-size:16px}
      .follow-row button{padding:.55rem .9rem;border-radius:10px;border:0;background:#2a2e37;color:#eee;font:inherit;cursor:pointer}
      .actor-list{list-style:none;padding:0;margin:1rem 0}
      .actor-list li{padding:.45rem 0;border-bottom:1px solid #222}
      .back{margin-top:1.5rem}.ap-footer{margin-top:2rem;padding-top:1rem;border-top:1px solid #222;font-size:.8rem;color:#777;line-height:1.45}
      .ap-footer nav{display:flex;flex-wrap:wrap;gap:.25rem .15rem;margin-bottom:.2rem}
      .ap-footer a{color:#9ad4e8;text-decoration:none}.ap-footer a:hover{text-decoration:underline;text-underline-offset:2px}
      .ap-footer .muted{color:#777}
      @media (max-width:600px){
        main{width:100%;padding:.75rem}
        .card{padding:1rem}
        .row{align-items:flex-start;flex-wrap:wrap;gap:.75rem}
        .fields{grid-template-columns:minmax(0,auto) minmax(0,1fr);overflow-wrap:anywhere}
        .follow-row input{width:100%;min-width:0}
      }
    </style></head><body><main><div class="card">';
}

function ap_user_html_shell_end(): void
{
    echo '<footer class="ap-footer">';
    echo '<nav aria-label="Instance policies">';
    echo '<a href="https://mkultra.monster/vaak/privacy/">Privacy</a>';
    echo '<span class="sep" aria-hidden="true"> · </span>';
    echo '<a href="https://mkultra.monster/vaak/conduct/">Code of Conduct</a>';
    echo '<span class="sep" aria-hidden="true"> · </span>';
    echo '<a href="https://mkultra.monster/vaak/">VAAK</a>';
    echo '<span class="sep" aria-hidden="true"> · </span>';
    echo '<a href="https://mkultra.monster/">mkultra.monster</a>';
    echo '</nav>';
    echo '<p class="muted" style="margin:.45rem 0 0">Invite-only ActivityPub · powered by VAAK</p>';
    echo '</footer></div></main>';
    if (function_exists('ap_cmdr_lightbox_markup_and_script')) {
        echo ap_cmdr_lightbox_markup_and_script();
    } else {
        echo '<div class="ap-img-lightbox" id="ap-img-lightbox" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Image preview">'
            . '<button type="button" class="ap-img-lightbox__close" id="ap-img-lightbox-close" aria-label="Close">×</button>'
            . '<img id="ap-img-lightbox-img" src="" alt="">'
            . '</div>';
        echo '<script>(function(){var box=document.getElementById("ap-img-lightbox");var img=document.getElementById("ap-img-lightbox-img");var closeBtn=document.getElementById("ap-img-lightbox-close");if(!box||!img)return;function openLb(src,alt){if(!src)return;img.src=src;img.alt=alt||"";box.classList.add("open");box.setAttribute("aria-hidden","false");document.body.style.overflow="hidden";}function closeLb(){box.classList.remove("open");box.setAttribute("aria-hidden","true");img.removeAttribute("src");img.alt="";document.body.style.overflow="";}document.addEventListener("click",function(e){var t=e.target instanceof Element?e.target.closest(".note-media-trigger,[data-ap-lightbox]"):null;if(!t)return;e.preventDefault();var full=t.getAttribute("data-full")||(t.querySelector&&t.querySelector("img")&&t.querySelector("img").src)||"";var alt=(t.querySelector&&t.querySelector("img")&&t.querySelector("img").alt)||"";openLb(full,alt);});closeBtn&&closeBtn.addEventListener("click",closeLb);box.addEventListener("click",function(e){if(e.target===box)closeLb();});document.addEventListener("keydown",function(e){if(e.key==="Escape"&&box.classList.contains("open"))closeLb();});})();</script>';
    }
    echo '</body></html>';
}
