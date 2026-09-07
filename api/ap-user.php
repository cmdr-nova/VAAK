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

    ap_user_html_shell_start('@' . $actorKey . '@mkultra.monster');
    if (!empty($p['image_url'])) {
        $banner = htmlspecialchars((string) $p['image_url'], ENT_QUOTES, 'UTF-8');
        echo '<div class="banner" style="background-image:url(\'' . $banner . '\')"></div>';
    }
    echo '<div class="row">';
    $avUrl = function_exists('ap_local_avatar_url')
        ? ap_local_avatar_url($p['icon_url'] ?? null)
        : ((string) ($p['icon_url'] ?? '') ?: '/img/avatar/local-default.webp');
    echo '<img class="av" src="' . htmlspecialchars($avUrl, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy" referrerpolicy="no-referrer">';
    echo '<div><h1>' . $name . '</h1>';
    echo '<p class="muted" style="margin:0">@' . $safe . '@mkultra.monster</p>';
    $slLink = function_exists('ap_sl_link_for_actor_key') ? ap_sl_link_for_actor_key($actorKey) : null;
    if (is_array($slLink) && !empty($slLink['sl_username'])) {
        $slName = htmlspecialchars((string) $slLink['sl_username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $slHref = htmlspecialchars(
            function_exists('ap_sl_profile_url')
                ? ap_sl_profile_url((string) ($slLink['sl_agent_id'] ?? ''))
                : 'https://secondlife.com/',
            ENT_QUOTES,
            'UTF-8'
        );
        echo '<p class="muted" style="margin:.35rem 0 0">'
            . 'Second Life resident: '
            . '<a href="' . $slHref . '" rel="noopener noreferrer me">' . $slName . '</a></p>';
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
    echo '<p class="follow-hint">Opens your instance’s follow dialog.</p></form></div></div>';

    if (!empty($p['attachment']) && is_array($p['attachment'])) {
        echo '<dl class="fields">';
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
            echo '<dt>' . $ln . '</dt><dd>' . $lv . '</dd>';
        }
        echo '</dl>';
    }

    $notes = ap_outbox_list(40, $actorKey);
    $publicNotes = [];
    foreach ($notes as $n) {
        $vis = (string) ($n['visibility'] ?? 'public');
        if (function_exists('ap_visibility_on_html_profile') && !ap_visibility_on_html_profile($vis)) {
            continue;
        }
        $publicNotes[] = $n;
    }

    $tab = strtolower(trim((string) ($_GET['tab'] ?? 'posts')));
    if (!in_array($tab, ['posts', 'featured'], true)) {
        $tab = 'posts';
    }
    $featuredCards = function_exists('ap_featured_cards_for_actor_key')
        ? ap_featured_cards_for_actor_key($actorKey)
        : [];
    $featuredCount = count($featuredCards);

    echo '<div class="stats">';
    echo '<div><span class="n">' . count($publicNotes) . '</span><span class="l">Posts</span></div>';
    echo '<a href="/users/' . $safe . '/following"><span class="n">' . count($following) . '</span><span class="l">Following</span></a>';
    echo '<a href="/users/' . $safe . '/followers"><span class="n">' . count($followers) . '</span><span class="l">Followers</span></a>';
    echo '</div>';

    echo '<nav class="profile-tabs" aria-label="Profile timeline">';
    foreach (
        [
            'posts' => ['Posts', count($publicNotes)],
            'featured' => ['Featured', $featuredCount],
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

    if ($tab === 'featured') {
        echo '<section class="posts featured-section" aria-label="Featured">';
        echo function_exists('ap_featured_cards_html')
            ? ap_featured_cards_html($featuredCards)
            : '<p class="muted">No featured accounts yet.</p>';
        echo '</section>';
    } else {
        echo '<section class="posts" aria-label="Posts">';
        if (!$publicNotes) {
            echo '<p class="muted">No public posts yet.</p>';
        } else {
            foreach ($publicNotes as $n) {
                echo ap_user_post_preview_html($actorKey, $n);
            }
        }
        echo '</section>';
    }

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
        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $alt = htmlspecialchars((string) ($att['name'] ?? $att['summary'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $isVideo = str_starts_with($mt, 'video/') || $atype === 'Video'
            || (bool) preg_match('/\.(mp4|webm|mov|m4v)(\?|$)/i', (string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if (str_starts_with($mt, 'image/') || $atype === 'Image') {
            if ($interactive) {
                $cells[] = '<a class="media-cell" href="' . $safe . '" target="_blank" rel="noopener noreferrer">'
                    . '<img src="' . $safe . '" alt="' . $alt . '" loading="lazy" referrerpolicy="no-referrer">'
                    . '</a>';
            } else {
                $cells[] = '<img src="' . $safe . '" alt="' . $alt . '" loading="lazy" referrerpolicy="no-referrer">';
            }
        } elseif ($isVideo) {
            $cells[] = '<video class="media-video" src="' . $safe . '" '
                . ($interactive ? 'controls ' : 'muted ')
                . 'playsinline preload="metadata" referrerpolicy="no-referrer"></video>';
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
    $html .= '</article>';
    return $html;
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
    ap_user_html_shell_start('Post · @' . $actorKey . '@mkultra.monster');
    echo '<div class="row" style="margin-bottom:1rem">';
    $avUrl = function_exists('ap_local_avatar_url')
        ? ap_local_avatar_url($p['icon_url'] ?? null)
        : ((string) ($p['icon_url'] ?? '') ?: '/img/avatar/local-default.webp');
    echo '<img class="av" src="' . htmlspecialchars($avUrl, ENT_QUOTES, 'UTF-8') . '" alt="" width="72" height="72" loading="lazy" referrerpolicy="no-referrer">';
    echo '<div><h1 style="font-size:1.1rem">' . $name . '</h1>';
    echo '<p class="muted" style="margin:0"><a href="/users/' . $safe . '">@' . $safe . '@mkultra.monster</a></p></div></div>';
    if (!empty($note['summary']) && is_string($note['summary'])) {
        echo '<p class="cw"><strong>CW</strong> · '
            . htmlspecialchars($note['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    echo '<div class="note-body">' . $content . '</div>';
    echo ap_user_note_media_html($note, true);
    echo '<p class="muted" style="margin-top:1.25rem;font-size:.85rem">'
        . htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') . '</p>';
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
    echo '<link rel="icon" href="/vaak/favicon.svg?v=20260907" type="image/svg+xml">';
    echo '<link rel="icon" href="/vaak/favicon.ico?v=20260907" sizes="any">';
    echo '<link rel="icon" href="/vaak/favicon-32x32.png?v=20260907" type="image/png" sizes="32x32">';
    echo '<link rel="apple-touch-icon" href="/vaak/apple-touch-icon.png?v=20260907">';
    echo '<style>
      :root{color-scheme:dark}
      body{margin:0;font-family:system-ui,sans-serif;background:#0a0a0a;color:#ddd}
      main{max-width:40rem;margin:0 auto;padding:1.25rem}
      .card{border:1px solid #2a2a2a;border-radius:14px;padding:1.25rem;background:#121212}
      a{color:#7ee0ff}.muted{color:#999}
      h1{margin:.2rem 0;font-size:1.45rem}
      .row{display:flex;gap:1rem;align-items:center}
      .av{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid #333;background:#1a1a1a}
      .av-fallback{display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:700;color:#00ff9f}
      .banner{height:120px;border-radius:12px;background-size:cover;background-position:center;margin:-.25rem -.25rem 1rem;border:1px solid #2a2a2a}
      .profile-bio{margin:1rem 0;line-height:1.45}
      .fields{display:grid;grid-template-columns:auto 1fr;gap:.35rem .75rem;margin:1rem 0}
      .fields dt{color:#999;font-size:.8rem}.fields dd{margin:0}
      .stats{display:flex;gap:1.25rem;margin:1.1rem 0 0;padding-top:1rem;border-top:1px solid #2a2a2a}
      .stats a{text-decoration:none;color:inherit}
      .stats .n{display:block;font-size:1.25rem;font-weight:700;color:#00ff9f}
      .stats .l{font-size:.8rem;color:#999;text-transform:uppercase}
      .profile-tabs{display:flex;gap:.35rem;margin:1.15rem 0 0;padding-top:1rem;border-top:1px solid #2a2a2a;flex-wrap:wrap}
      .profile-tabs a{text-decoration:none;color:#aaa;font-size:.9rem;font-weight:600;padding:.45rem .9rem;border-radius:999px}
      .profile-tabs a:hover{color:#eee;background:#1a1a1a}
      .profile-tabs a.is-active{color:#0b0b0b;background:#00ff9f}
      .profile-tabs a .tab-count{opacity:.7;font-weight:500;margin-left:.25rem;font-size:.8rem}
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
      .post{padding:.9rem 0;border-bottom:1px solid #222}
      .note-body p{margin:.4rem 0}.cw{color:#f0c674;font-size:.9rem}
      .media-row{display:grid;gap:.28rem;margin-top:.55rem;width:100%;border-radius:12px;overflow:hidden;border:1px solid #2a2a2a;background:#0a0a0a}
      .media-row.media-count-1{grid-template-columns:1fr}
      .media-row.media-count-2{grid-template-columns:1fr 1fr}
      .media-row.media-count-3{grid-template-columns:1fr 1fr;grid-template-rows:minmax(120px,1fr) minmax(120px,1fr)}
      .media-row.media-count-3>:first-child{grid-row:1 / span 2}
      .media-row.media-count-4{grid-template-columns:1fr 1fr;grid-template-rows:minmax(120px,1fr) minmax(120px,1fr)}
      .media-row .media-cell{display:block;width:100%;height:100%;min-height:0;background:#0c0c0c}
      .media-row img,.media-row video{display:block;width:100%;height:100%;max-height:min(58vh,520px);object-fit:cover;background:#0c0c0c;border:0}
      .media-row.media-count-1 img{object-fit:contain;max-height:min(62vh,560px);min-height:160px}
      .media-row video{object-fit:contain;max-height:min(62vh,560px);background:#000}
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
    echo '</footer></div></main></body></html>';
}
