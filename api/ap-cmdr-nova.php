<?php
/**
 * Live local actor @cmdr_nova@mkultra.monster
 * Accept + follow-back, outbox, observe — Bridgy keeps val3r1e@mkultra.monster.
 * Profile fields come from SQLite (editable via /admin?view=profile).
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

const CMDR_ACTOR_ID = 'https://mkultra.monster/users/cmdr_nova';
const CMDR_PUB = '/etc/mkultra/ap-inbox/cmdr_nova_public.pem';

require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-collections.php';
require_once __DIR__ . '/ap-import-export.php'; // alsoKnownAs / movedTo on actor doc
require_once __DIR__ . '/ap-link-preview.php';
require_once __DIR__ . '/ap-featured.php'; // Profile Featured accounts tab
require_once __DIR__ . '/ap-sl-link.php';

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';
if ($path === '/@cmdr_nova') {
    $path = '/users/cmdr_nova';
}

$pem = is_file(CMDR_PUB) ? file_get_contents(CMDR_PUB) : false;
if (!is_string($pem) || $pem === '') {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"error":"actor key unavailable"}';
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
// Prefer HTML for normal browser navigations. Only serve ActivityPub JSON when
// explicitly asked (activity+json / ld+json AS profile). Do NOT treat bare
// application/json as AP — browsers/extensions often send that and then show
// a "Pretty-print" JSON view instead of the public profile HTML.
$wantsAp = ap_cmdr_wants_activitypub($accept);

if (preg_match('#^/users/cmdr_nova/inbox$#', $path)) {
    if ($method !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        echo '{"error":"Method not allowed"}';
        exit;
    }
    require __DIR__ . '/ap-inbox.php';
    exit;
}

if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

if (preg_match('#^/users/cmdr_nova/(outbox|followers|following)$#', $path, $m)) {
    $col = $m[1];

    // Browser HTML for followers / following / outbox
    if (!$wantsAp) {
        if ($col === 'followers' || $col === 'following') {
            ap_cmdr_collection_html($col);
            exit;
        }
        if ($col === 'outbox') {
            ap_cmdr_outbox_html();
            exit;
        }
    }

    if ($col === 'outbox') {
        // Merge Creates (notes) + Announces (boosts) by published time so remote
        // profile timelines show boosts — Creates alone made @cmdr_nova look boost-less.
        // Include public + unlisted; exclude followers-only (private).
        $merged = [];
        // Only this actor's notes — shared outbox_notes table also holds invitee posts
        foreach (ap_outbox_list(80, 'cmdr_nova') as $n) {
            $vis = (string) ($n['visibility'] ?? 'public');
            if (function_exists('ap_visibility_in_ap_outbox') && !ap_visibility_in_ap_outbox($vis)) {
                continue;
            }
            $decoded = json_decode((string) ($n['raw_create_json'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $decoded = ap_create_finalize($decoded);
            unset($decoded['@context']);
            $published = (string) ($decoded['published'] ?? $n['published'] ?? '');
            $merged[] = ['published' => $published, 'activity' => $decoded];
        }
        if (function_exists('ap_masto_reblog_rows') && function_exists('ap_masto_announce_activity_from_row')) {
            $cmdrUid = function_exists('ap_db_cmdr_nova_user_id') ? ap_db_cmdr_nova_user_id() : 1;
            foreach (ap_masto_reblog_rows(80, null, $cmdrUid) as $rb) {
                $announce = ap_masto_announce_activity_from_row($rb);
                if ($announce === null) {
                    continue;
                }
                unset($announce['@context']);
                $merged[] = [
                    'published' => (string) ($announce['published'] ?? $rb['created_at'] ?? ''),
                    'activity' => $announce,
                ];
            }
        }
        usort($merged, static function (array $a, array $b): int {
            return strcmp((string) ($b['published'] ?? ''), (string) ($a['published'] ?? ''));
        });
        $items = [];
        foreach ($merged as $row) {
            $items[] = $row['activity'];
            if (count($items) >= 50) {
                break;
            }
        }
        $doc = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                ap_quote_ld_context(),
            ],
            'id' => CMDR_ACTOR_ID . '/outbox',
            'type' => 'OrderedCollection',
            'totalItems' => count($items),
            'orderedItems' => $items,
        ];
    } elseif ($col === 'followers') {
        $rows = ap_followers_list(CMDR_ACTOR_ID);
        $items = [];
        foreach ($rows as $r) {
            if (!empty($r['actor_id'])) {
                $items[] = $r['actor_id'];
            }
        }
        $doc = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => CMDR_ACTOR_ID . '/followers',
            'type' => 'OrderedCollection',
            'totalItems' => count($items),
            'orderedItems' => $items,
        ];
    } else {
        $rows = ap_following_list(CMDR_ACTOR_ID);
        $items = [];
        foreach ($rows as $r) {
            if (!empty($r['actor_id'])) {
                $items[] = $r['actor_id'];
            }
        }
        $doc = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => CMDR_ACTOR_ID . '/following',
            'type' => 'OrderedCollection',
            'totalItems' => count($items),
            'orderedItems' => $items,
        ];
    }
    ap_cmdr_json($doc, $accept);
    exit;
}

// Mastodon-style pinned posts (featured collection of Notes)
if ($path === '/users/cmdr_nova/collections/featured' || $path === '/users/cmdr_nova/featured') {
    $items = [];
    if (function_exists('ap_masto_pinned_statuses') && function_exists('ap_local_note_as2_doc')) {
        foreach (ap_masto_pinned_statuses(5) as $prow) {
            if (!is_array($prow)) {
                continue;
            }
            $nid = (string) ($prow['note_id'] ?? '');
            if ($nid === '') {
                continue;
            }
            $noteDoc = ap_local_note_as2_doc($nid);
            if (is_array($noteDoc)) {
                unset($noteDoc['@context']);
                $items[] = $noteDoc;
            } else {
                $items[] = $nid;
            }
        }
    }
    $doc = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => CMDR_ACTOR_ID . '/collections/featured',
        'type' => 'OrderedCollection',
        'totalItems' => count($items),
        'orderedItems' => $items,
    ];
    ap_cmdr_json($doc, $accept);
    exit;
}

// Featured collections list (discoverable locals) — AS2 OrderedCollection
if ($path === '/users/cmdr_nova/featuredCollections') {
    $items = [];
    foreach (ap_collections_list_local(false, CMDR_ACTOR_ID) as $c) {
        if (empty($c['discoverable'])) {
            continue;
        }
        $items[] = (string) ($c['object_id'] ?? ap_collection_object_id((int) $c['id'], CMDR_ACTOR_ID));
        if (count($items) >= 40) {
            break;
        }
    }
    $doc = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => CMDR_ACTOR_ID . '/featuredCollections',
        'type' => 'OrderedCollection',
        'totalItems' => count($items),
        'orderedItems' => $items,
    ];
    ap_cmdr_json($doc, $accept);
    exit;
}

// Single collection: HTML page or AS2 FeaturedCollection-ish doc
if (preg_match('#^/users/cmdr_nova/collections/(\d+)$#', $path, $cm)) {
    $cid = (int) $cm[1];
    $coll = ap_collection_by_id($cid);
    $collOwner = is_array($coll) ? rtrim((string) ($coll['owner_actor_id'] ?? ''), '/') : '';
    if (!$coll || (int) ($coll['local'] ?? 0) !== 1 || $collOwner !== rtrim(CMDR_ACTOR_ID, '/')) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo '{"error":"Collection not found"}';
        exit;
    }
    if (empty($coll['discoverable']) && !$wantsAp) {
        // Unlisted: allow direct link with AP fetch; hide casual HTML browsing
        // Still show HTML if someone has the URL (shareable unlisted) — Mastodon does too.
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
                'id' => CMDR_ACTOR_ID . '/collections/' . $cid . '/items/' . (int) ($mem['id'] ?? ($i + 1)),
                'type' => 'FeaturedItem',
                'featuredObject' => $aid,
                'featuredObjectType' => 'Person',
                'published' => (string) ($mem['added_at'] ?? $coll['created_at'] ?? ''),
            ];
        }
        $doc = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/fep/7aa9',
                [
                    'Hashtag' => 'as:Hashtag',
                    'sensitive' => 'as:sensitive',
                    'discoverable' => 'https://joinmastodon.org/ns#discoverable',
                    'FeaturedCollection' => 'https://w3id.org/fep/7aa9#FeaturedCollection',
                    'FeaturedItem' => 'https://w3id.org/fep/7aa9#FeaturedItem',
                    'featuredObject' => ['@id' => 'https://w3id.org/fep/7aa9#featuredObject', '@type' => '@id'],
                    'featuredObjectType' => 'https://w3id.org/fep/7aa9#featuredObjectType',
                ],
            ],
            'type' => 'FeaturedCollection',
            'id' => (string) ($coll['object_id'] ?? ap_collection_object_id($cid)),
            'name' => (string) ($coll['name'] ?? ''),
            'summary' => (string) ($coll['description'] ?? ''),
            'attributedTo' => CMDR_ACTOR_ID,
            'sensitive' => !empty($coll['sensitive']),
            'discoverable' => !empty($coll['discoverable']),
            'url' => (string) ($coll['html_url'] ?? ap_collection_html_url($cid)),
            'totalItems' => count($ordered),
            'orderedItems' => $ordered,
            'published' => (string) ($coll['created_at'] ?? ''),
            'updated' => (string) ($coll['updated_at'] ?? ''),
        ];
        if (!empty($coll['tag_name'])) {
            $doc['topic'] = [
                'type' => 'Hashtag',
                'name' => '#' . ltrim((string) $coll['tag_name'], '#'),
            ];
        }
        ap_cmdr_json($doc, $accept);
        exit;
    }
    ap_cmdr_collection_pack_html($coll, $members);
    exit;
}

// Public stats for homepage hero hydration
if ($path === '/users/cmdr_nova/stats') {
    $site = ap_site_content_counts();
    $compose = ap_compose_post_count();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, max-age=0, must-revalidate');
    echo json_encode([
        'posts' => ap_public_post_count(),
        'blog_posts' => (int) $site['blog_posts'],
        'notes' => (int) $site['notes'],
        'compose' => $compose,
        'followers' => count(ap_followers_list(CMDR_ACTOR_ID)),
        'following' => count(ap_following_list(CMDR_ACTOR_ID)),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// FEP-044f QuoteAuthorization stamps
if (preg_match('#^/users/cmdr_nova/quote_auth/([a-f0-9]+)$#', $path, $qm)) {
    $stampId = CMDR_ACTOR_ID . '/quote_auth/' . $qm[1];
    $row = ap_quote_auth_get($stampId);
    if (!$row) {
        http_response_code(404);
        header('Content-Type: application/activity+json');
        echo '{"error":"Not found"}';
        exit;
    }
    ap_cmdr_json(ap_quote_auth_document($row), $accept !== '' ? $accept : 'application/activity+json');
    exit;
}

// Public Announce activities (boosts) — remotes fetch these after inbox delivery
if (preg_match('#^/users/cmdr_nova/announces/([a-f0-9]+)$#', $path, $am)) {
    $announceId = CMDR_ACTOR_ID . '/announces/' . $am[1];
    $row = function_exists('ap_masto_reblog_row_by_announce_id')
        ? ap_masto_reblog_row_by_announce_id($announceId)
        : null;
    $activity = is_array($row) && function_exists('ap_masto_announce_activity_from_row')
        ? ap_masto_announce_activity_from_row($row)
        : null;
    if ($activity === null) {
        http_response_code(404);
        header('Content-Type: application/activity+json');
        echo '{"error":"Not found"}';
        exit;
    }
    ap_cmdr_json($activity, $accept !== '' ? $accept : 'application/activity+json');
    exit;
}

// Individual Note / Create objects (shared links from Ice Cubes / Mastodon quotes)
if (preg_match('#^/users/cmdr_nova/(notes|creates)/([a-f0-9]+)$#', $path, $nm)) {
    $kind = $nm[1];
    $hex = $nm[2];
    $objectId = CMDR_ACTOR_ID . '/' . $kind . '/' . $hex;
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
            ap_cmdr_shell_start('Not found');
            echo '<h1>Not found</h1><p class="muted">No post at this URL.</p>';
            echo '<p class="back"><a href="/users/cmdr_nova">← profile</a></p>';
            ap_cmdr_shell_end();
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
            // Standalone Note: quote policy + top-level @context only (no nested ctx)
            $note = ap_note_ensure_quote_policy($note);
            $note = ap_as2_ensure_top_context($note);
            ap_cmdr_json($note, $accept);
        } else {
            // Create: context only on the activity; object must not nest @context
            // (nested @context made Bridgy Fed see content/published/url as arrays)
            $create = ap_create_finalize($create);
            ap_cmdr_json($create, $accept);
        }
        exit;
    }
    ap_cmdr_note_html($row, $create);
    exit;
}

if ($path !== '/users/cmdr_nova') {
    http_response_code(404);
    if ($wantsAp) {
        header('Content-Type: application/json');
        echo '{"error":"Not found"}';
    } else {
        ap_cmdr_shell_start('Not found');
        echo '<h1>Not found</h1><p class="back"><a href="/users/cmdr_nova">← profile</a></p>';
        ap_cmdr_shell_end();
    }
    exit;
}

if (!$wantsAp) {
    ap_cmdr_html();
    exit;
}

$actor = ap_cmdr_actor_document($pem);
ap_cmdr_json($actor, $accept);

/**
 * True when the client explicitly wants ActivityPub JSON.
 * Browser navigations (Accept: text/html,…) get HTML even if application/json
 * also appears in the Accept list.
 *
 * Important: Bridgy Fed sends
 *   Accept: application/activity+json, …, text/html; charset=utf-8; q=0.5
 * q may appear after other parameters — parse the whole media-range, not just
 * ";q=" immediately after the type (that bug made HTML look like q=1.0).
 */
function ap_cmdr_wants_activitypub(string $accept): bool
{
    $accept = strtolower(trim($accept));
    if ($accept === '') {
        return false;
    }

    $score = static function (string $haystack, string $type): float {
        $best = -1.0;
        foreach (explode(',', $haystack) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            // media-type is before the first ";"
            $semi = strpos($part, ';');
            $media = trim($semi === false ? $part : substr($part, 0, $semi));
            if ($media !== $type) {
                continue;
            }
            $q = 1.0;
            if (preg_match('/(?:^|;)\s*q\s*=\s*([0-9.]+)/', $part, $m)) {
                $q = (float) $m[1];
            }
            if ($q > $best) {
                $best = $q;
            }
        }
        return $best;
    };

    $htmlQ = $score($accept, 'text/html');
    $xhtmlQ = $score($accept, 'application/xhtml+xml');
    $browserQ = max($htmlQ, $xhtmlQ);

    $activityQ = $score($accept, 'application/activity+json');
    $ldQ = -1.0;
    // Prefer ld+json only when the AS profile is present, or when HTML isn't.
    if (str_contains($accept, 'application/ld+json') && str_contains($accept, 'activitystreams')) {
        $ldQ = $score($accept, 'application/ld+json');
    } elseif (str_contains($accept, 'application/ld+json') && $browserQ < 0) {
        $ldQ = $score($accept, 'application/ld+json');
    }
    $apQ = max($activityQ, $ldQ);

    if ($apQ < 0 && $browserQ < 0) {
        // No explicit preference — default HTML for GETs from unknown clients
        return false;
    }
    if ($apQ < 0) {
        return false;
    }
    if ($browserQ < 0) {
        return true;
    }
    // Prefer higher q. On a tie, prefer AP if activity+json is present without
    // an explicit html q-boost (Bridgy: activity+json q=1 vs html q=0.5).
    return $apQ > $browserQ;
}

function ap_cmdr_json(array $doc, string $accept): void
{
    if (str_contains($accept, 'application/activity+json')) {
        header('Content-Type: application/activity+json; charset=utf-8');
    } else {
        header('Content-Type: application/ld+json; profile="https://www.w3.org/ns/activitystreams"; charset=utf-8');
    }
    // Avoid sticky caches flipping HTML↔JSON for the same URL
    header('Cache-Control: no-cache, max-age=0, must-revalidate');
    header('Vary: Accept');
    echo json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function ap_cmdr_outbox_html(): void
{
    $perPage = 20;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    // Full combined timeline (posts + replies + boosts) for /outbox HTML
    $data = ap_cmdr_posts_page($page, $perPage, 'all');
    ap_cmdr_shell_start('Posts · @cmdr_nova@mkultra.monster');
    echo '<p class="muted"><a href="/users/cmdr_nova">@cmdr_nova@mkultra.monster</a></p>';
    echo '<h1>Posts <span class="muted">(' . (int) $data['total'] . ')</span></h1>';
    if (!$data['rows']) {
        echo '<p class="muted" style="margin-top:1rem">No public posts yet.</p>';
    } else {
        echo '<div class="posts" style="border-top:none;padding-top:0;margin-top:.5rem">';
        foreach ($data['rows'] as $n) {
            echo ap_cmdr_post_preview_html($n);
        }
        echo '</div>';
        $totalPages = max(1, (int) ceil($data['total'] / $perPage));
        if ($totalPages > 1) {
            echo ap_cmdr_pager_html('/users/cmdr_nova/outbox', $page, $totalPages);
        }
    }
    echo '<p class="back"><a href="/users/cmdr_nova">← Profile</a> · <a href="https://mkultra.monster/">Site</a></p>';
    ap_cmdr_shell_end();
}

/** Turn profile field values into clickable links when possible. */
function ap_cmdr_linkify_value(string $value): string
{
    $value = trim(ap_fix_utf8($value));
    if ($value === '') {
        return '';
    }

    if (str_contains($value, '<a ')) {
        $html = strip_tags($value, '<a>');
        $html = preg_replace_callback(
            '/<a\s+([^>]*?)>/i',
            static function (array $m): string {
                $href = '';
                if (preg_match('/href\s*=\s*([\'"])(https?:\/\/[^\'"]+)\1/i', $m[1], $hm)) {
                    $href = $hm[2];
                }
                if ($href === '' || !preg_match('#^https://#i', $href)) {
                    return '<a>';
                }
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" rel="me noopener noreferrer">';
            },
            $html
        ) ?? $html;
        return $html;
    }

    $plain = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($plain === '') {
        return '';
    }

    if (preg_match('#^https://[^\s<]+$#', $plain)) {
        $safe = htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<a href="' . $safe . '" rel="me noopener noreferrer">' . $safe . '</a>';
    }

    // @user@domain or user@domain
    if (preg_match('/^@?([A-Za-z0-9_\.\-]+)@([A-Za-z0-9\.\-]+\.[A-Za-z]{2,})$/', $plain, $m)) {
        $user = $m[1];
        $host = strtolower($m[2]);
        $href = 'https://' . $host . '/@' . rawurlencode($user);
        $label = '@' . $user . '@' . $host;
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" rel="me noopener noreferrer">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
    }

    // bare domain
    if (preg_match('/^([A-Za-z0-9\.\-]+\.[A-Za-z]{2,})\/?$/', $plain, $m)) {
        $host = strtolower(rtrim($m[1], '/'));
        $href = 'https://' . $host . '/';
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" rel="me noopener noreferrer">'
            . htmlspecialchars($host, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
    }

    return htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ap_cmdr_actor_handle_label(string $actorId, ?string $username = null): string
{
    $host = parse_url($actorId, PHP_URL_HOST);
    $host = is_string($host) ? strtolower($host) : '';
    if ($username && $host) {
        return '@' . $username . '@' . $host;
    }
    $path = parse_url($actorId, PHP_URL_PATH) ?: '';
    $base = basename(rtrim($path, '/'));
    if ($base !== '' && $host !== '') {
        return '@' . $base . '@' . $host;
    }
    return $actorId;
}

/**
 * Prototype: render HTML profile/notes inside the main site 3-column shell
 * (left #sidebar + center #main + right footer), so we can judge folding
 * /users/cmdr_nova into the homepage. Escape hatch: ?shell=classic
 */
function ap_cmdr_use_site_shell(): bool
{
    // Default: classic narrow profile. Opt into the fold experiment with ?shell=site
    $mode = strtolower(trim((string) ($_GET['shell'] ?? 'classic')));
    return $mode === 'site' || $mode === 'wide';
}

function ap_cmdr_homepage_html(): string
{
    static $cached = null;
    if (is_string($cached)) {
        return $cached;
    }
    $paths = [
        '/srv/mkultra/html/index.html',
        dirname(__DIR__) . '/index.html',
        dirname(__DIR__) . '/_site/index.html',
    ];
    foreach ($paths as $path) {
        if (is_readable($path)) {
            $html = @file_get_contents($path);
            if (is_string($html) && str_contains($html, 'id="sidebar"')) {
                return $cached = $html;
            }
        }
    }
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 4,
            'header' => "Accept: text/html\r\nUser-Agent: mkultra-ap-shell/1.0\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $html = @file_get_contents('https://mkultra.monster/', false, $ctx);
    return $cached = (is_string($html) ? $html : '');
}

function ap_cmdr_extract_homepage_sidebar(string $homeHtml): string
{
    $start = strpos($homeHtml, '<div id="sidebar">');
    if ($start === false) {
        return '';
    }
    $endMarker = '<!-- Side Bar Section End -->';
    $end = strpos($homeHtml, $endMarker, $start);
    if ($end === false) {
        return '';
    }
    return substr($homeHtml, $start, $end + strlen($endMarker) - $start);
}

function ap_cmdr_extract_homepage_footer(string $homeHtml): string
{
    $start = strpos($homeHtml, '<footer>');
    $end = $start === false ? false : strpos($homeHtml, '</footer>', $start);
    if ($start === false || $end === false) {
        return '';
    }
    return substr($homeHtml, $start, $end + strlen('</footer>') - $start);
}

function ap_cmdr_shell_start(string $title): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Vary: Accept');
    header('Cache-Control: no-cache, max-age=0, must-revalidate');
    header('X-Robots-Tag: noindex, nofollow');

    if (ap_cmdr_use_site_shell()) {
        ap_cmdr_site_shell_start($title);
        return;
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<link rel="icon" href="/vaak/favicon.svg?v=20260907" type="image/svg+xml">';
    echo '<link rel="icon" href="/vaak/favicon.ico?v=20260907" sizes="any">';
    echo '<link rel="icon" href="/vaak/favicon-32x32.png?v=20260907" type="image/png" sizes="32x32">';
    echo '<link rel="apple-touch-icon" href="/vaak/apple-touch-icon.png?v=20260907">';
    echo '<style>
      body{margin:0;font-family:system-ui,sans-serif;background:#0a0a0a;color:#e8e8e8;line-height:1.55}
      main{max-width:42rem;margin:3rem auto;padding:0 1.25rem;box-sizing:border-box}
      .card{border:1px solid #333;border-radius:12px;padding:1.35rem;background:#121212;overflow:hidden}
      .banner{margin:-1.35rem -1.35rem 1rem;height:120px;background:#1a1a1a;background-size:cover;background-position:center}
      .row{display:flex;gap:1rem;align-items:flex-start}
      .av{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid #333;background:#222;flex-shrink:0}
      .sl-link a{display:inline-flex;align-items:center;text-decoration:none}
      .sl-link img{display:block;border-radius:8px;width:43px;height:34px}
      a{color:#7ee0ff;text-decoration:underline;text-underline-offset:2px} a:hover{color:#b7f3ff}
      .muted{color:#999} h1{font-size:1.35rem;margin:0 0 .35rem}
      .profile-bio{margin-top:1rem}
      .profile-bio p{margin:0 0 .55em}
      .profile-bio p:last-child{margin-bottom:0}
      .vanity-verified{display:inline-flex;align-items:center;justify-content:center;width:1.05em;height:1.05em;margin-left:.2rem;border-radius:50%;background:#1d9bf0;color:#fff;font-size:.72em;font-weight:800;line-height:1;vertical-align:middle;position:relative;top:-.08em}
      .fields{margin-top:1rem;font-size:.9rem} .fields dt{color:#999;margin-top:.5rem} .fields dd{margin:.15rem 0 0}
      .field-verified{display:inline-flex;align-items:center;justify-content:center;width:1em;height:1em;margin-left:.35rem;border-radius:50%;background:rgba(0,255,159,.2);color:#00ff9f;font-size:.75em;font-weight:800;line-height:1;vertical-align:middle;position:relative;top:-.05em}
      .stats{display:flex;gap:1.25rem;margin:1.1rem 0 0;padding-top:1rem;border-top:1px solid #2a2a2a}
      .stats a,.stats > div{color:inherit;text-decoration:none;display:flex;flex-direction:column;gap:.15rem}
      .stats a:hover .n{color:#7ee0ff}
      .stats .n{font-size:1.25rem;font-weight:700;color:#00ff9f}
      .stats .l{font-size:.8rem;color:#999;text-transform:uppercase;letter-spacing:.04em}
      .profile-tabs{display:flex;gap:.35rem;margin:1.15rem 0 0;padding-top:1rem;border-top:1px solid #2a2a2a;flex-wrap:wrap}
      .profile-tabs a{appearance:none;text-decoration:none;color:#aaa;font-size:.9rem;font-weight:600;padding:.45rem .9rem;border-radius:999px;border:1px solid transparent;background:transparent}
      .profile-tabs a:hover{color:#eee;background:#1a1a1a}
      .profile-tabs a.is-active{color:#0b0b0b;background:#00ff9f;border-color:#00ff9f}
      .profile-tabs a .tab-count{opacity:.7;font-weight:500;margin-left:.25rem;font-size:.8rem}
      .profile-tabs a.is-active .tab-count{opacity:.85}
      .featured-accounts{list-style:none;margin:0;padding:0}
      .featured-account{border-bottom:1px solid #2a2a2a}
      .featured-account:last-child{border-bottom:none}
      .featured-account-link{display:flex;align-items:center;gap:.85rem;padding:.85rem 0;color:inherit;text-decoration:none}
      .featured-account-link:hover{background:rgba(126,224,255,.04);margin:0 -.5rem;padding-left:.5rem;padding-right:.5rem;border-radius:8px}
      .featured-av{width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid #333;background:#222;flex:0 0 auto}
      .featured-meta{display:flex;flex-direction:column;gap:.15rem;min-width:0}
      .featured-name{font-weight:650;color:#e8e8e8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .featured-acct{font-size:.85rem;color:#999;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .list{list-style:none;margin:1rem 0 0;padding:0}
      .list li{padding:.7rem 0;border-bottom:1px solid #2a2a2a}
      .list li:last-child{border-bottom:none}
      .list .who{font-weight:600} .list .mono{font-size:.75rem;color:#777;word-break:break-all;margin-top:.2rem}
      .back{margin-top:1.25rem}
      .posts{margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid #2a2a2a}
      .posts h2{font-size:1rem;margin:0 0 .75rem;letter-spacing:.04em;text-transform:uppercase;color:#999;font-weight:600}
      .post{display:block;padding:.9rem 0;border-bottom:1px solid #2a2a2a;color:inherit;text-decoration:none}
      .post:last-child{border-bottom:none}
      .post:hover{background:rgba(126,224,255,.04);margin:0 -.5rem;padding-left:.5rem;padding-right:.5rem;border-radius:8px}
      .post.is-pinned{position:relative}
      /* Decorative pin on the top border — out of flow, no text shift */
      .post-pin{position:absolute;top:-0.45rem;left:0;font-size:12px;line-height:1;color:#7ee0ff;opacity:.9;pointer-events:none;z-index:2;filter:grayscale(0.15)}
      .post .body{color:#e8e8e8}
      .post .body p,.note-body p{margin:0 0 .45em}
      .post .body p:last-child,.note-body p:last-child{margin-bottom:0}
      .post .meta{font-size:.8rem;color:#888;margin-top:.45rem}
      /* Mastodon-like: media fills the post content width */
      .media-row{display:grid;gap:.28rem;margin-top:.55rem;width:100%;max-width:100%;border-radius:12px;overflow:hidden;border:1px solid #333;background:#0a0a0a;box-sizing:border-box}
      .media-row.media-count-1{grid-template-columns:1fr}
      .media-row.media-count-2{grid-template-columns:1fr 1fr}
      .media-row.media-count-3{grid-template-columns:1fr 1fr;grid-template-rows:minmax(120px,1fr) minmax(120px,1fr)}
      .media-row.media-count-3>:first-child{grid-row:1 / span 2}
      .media-row.media-count-4{grid-template-columns:1fr 1fr;grid-template-rows:minmax(120px,1fr) minmax(120px,1fr)}
      .media-row .note-media-trigger,.media-row a.media-cell{display:block;width:100%;height:100%;min-height:0;min-width:0;padding:0;margin:0;border:0;background:#0c0c0c;cursor:zoom-in;overflow:hidden}
      .media-row img,.media-row .thumb,.media-row video,.media-row .media-video{display:block;width:100%;height:100%;max-height:min(58vh,520px);object-fit:cover;background:#0c0c0c;border:0;border-radius:0;margin:0}
      .media-row.media-count-1 img,.media-row.media-count-1 .thumb{object-fit:contain;max-height:min(62vh,560px);min-height:160px}
      .media-row video,.media-row .media-video{object-fit:contain;max-height:min(62vh,560px);background:#000;cursor:default}
      .post .thumb{margin-top:.55rem;max-width:100%;max-height:min(62vh,560px);width:100%;border-radius:12px;border:1px solid #333;object-fit:contain;display:block;background:#0a0a0a}
      .post video.thumb{width:100%;max-height:min(62vh,560px);object-fit:contain;background:#0a0a0a}
      .pager{display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:.45rem;margin-top:1.35rem;padding-top:1rem;border-top:1px solid #2a2a2a}
      .pager .pager-btn{display:inline-flex;align-items:center;justify-content:center;min-width:2.4rem;padding:.45rem .75rem;border-radius:999px;border:1px solid #333;background:#161616;color:#cfefff;text-decoration:none;font-size:.85rem;line-height:1.2}
      .pager .pager-btn:hover{border-color:#7ee0ff;color:#fff;background:#1a242c}
      .pager .pager-btn.is-disabled{opacity:.35;pointer-events:none;color:#777}
      .pager .pager-btn.is-current{background:#8bf;border-color:#8bf;color:#061018;font-weight:700}
      .pager .pager-status{width:100%;text-align:center;font-size:.78rem;color:#888;margin-bottom:.15rem;letter-spacing:.02em}
      .follow-wrap{margin-top:1.1rem}
      .btn-follow,.btn-reply{appearance:none;border:0;border-radius:999px;padding:.55rem 1.15rem;font:inherit;font-weight:600;cursor:pointer}
      .btn-follow{background:#8bf;color:#061018}
      .btn-follow:hover{filter:brightness(1.06)}
      .btn-reply{background:#1a2a24;color:#00ff9f;border:1px solid rgba(0,255,159,.35)}
      .btn-reply:hover{filter:brightness(1.08);border-color:rgba(0,255,159,.55)}
      .follow-panel,.reply-panel{margin-top:.75rem;padding:.85rem;border:1px solid #2a2e37;border-radius:12px;background:#0c0c0c}
      .follow-panel[hidden],.reply-panel[hidden]{display:none!important}
      .follow-panel label,.reply-panel label{display:block;font-size:.9rem;color:#bbb;margin-bottom:.45rem}
      .follow-row,.reply-row{display:flex;gap:.5rem;flex-wrap:wrap}
      .follow-row input,.reply-row input{flex:1;min-width:12rem;padding:.55rem .7rem;border-radius:10px;border:1px solid #333;background:#111;color:#eee;font:inherit;font-size:16px}
      .follow-row button,.reply-row button{padding:.55rem .9rem;border-radius:10px;border:0;background:#2a2e37;color:#eee;font:inherit;cursor:pointer}
      .follow-row button:hover,.reply-row button:hover{background:#3a404c}
      .follow-hint,.reply-hint{margin:.55rem 0 0;font-size:.8rem;color:#888}
      .follow-hint.is-error,.reply-hint.is-error{color:#f88}
      .note-actions{margin-top:1.15rem;display:flex;flex-wrap:wrap;gap:.55rem;align-items:center}
      .ap-footer{margin:1.5rem 0 0;padding-top:1rem;border-top:1px solid #2a2a2a;font-size:.78rem;color:#888;line-height:1.45}
      .ap-footer nav{display:flex;flex-wrap:wrap;gap:.35rem .85rem;margin-bottom:.45rem}
      .ap-footer a{color:#9ad4e8;text-decoration:none}
      .ap-footer a:hover{color:#cfefff;text-decoration:underline;text-underline-offset:2px}
      .ap-footer .sep{color:#555;user-select:none}
      .cw{margin:0 0 .75rem;padding:.55rem .75rem;border-radius:10px;border:1px solid #3a3420;background:#1a160c;color:#f0d78c;font-size:.9rem}
      .cw strong{color:#ffe6a3;font-weight:650}
      .reply-line{font-size:.8rem;color:#8ab;margin:0 0 .45rem}
      .reply-line a{color:#9ad4e8;text-decoration:none}
      .reply-line a:hover{text-decoration:underline;text-underline-offset:2px}
      .boost-line{font-size:.8rem;color:#9ad4a8;margin:0 0 .45rem}
      .quote-block{margin:.55rem 0 0;padding:.65rem .75rem;border-radius:10px;border:1px solid #2a3340;background:#0e1218;color:#c8d2dc;font-size:.88rem;line-height:1.4;overflow-wrap:anywhere;word-break:break-word}
      .quote-block .qt-label{display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#7a8a9a;margin-bottom:.3rem}
      .quote-block a{color:#9ad4e8}
      .quote-block .qt-url{color:#8ab;font-size:.78rem}
      .link-card{display:flex;gap:.75rem;margin:.65rem 0 0;padding:0;border-radius:12px;border:1px solid #2a3340;background:#0e1218;overflow:hidden;text-decoration:none;color:inherit;max-width:520px}
      .link-card:hover,.post:hover .link-card{border-color:#3d4a5c;text-decoration:none}
      .link-card--static{cursor:inherit}
      .link-card__media{flex:0 0 120px;max-height:120px;overflow:hidden;background:#0a0c10}
      .link-card__media img{width:100%;height:100%;object-fit:cover;display:block}
      .link-card__body{padding:.65rem .75rem;min-width:0;flex:1}
      .link-card__provider{font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:#7a8a9a;margin-bottom:.2rem}
      .link-card__title{font-size:.92rem;font-weight:600;color:#e8eef4;line-height:1.3;overflow-wrap:anywhere}
      .link-card__desc{font-size:.8rem;color:#9aa8b5;margin-top:.25rem;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
      .poll-block{margin:.55rem 0 0;padding:.55rem .7rem;border-radius:10px;border:1px solid #2a3340;background:#0e1218}
      .poll-block .poll-label{display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#7a8a9a;margin-bottom:.4rem}
      .poll-block .poll-opt{display:block;margin:.28rem 0;padding:.4rem .55rem;border-radius:8px;border:1px solid #333;background:#141820;color:#dce6f0;font-size:.88rem}
      .poll-block .poll-meta{margin-top:.4rem;font-size:.75rem;color:#7a8a9a}
      .post .badge{color:#7ee0ff}
      .note-media-trigger{appearance:none;display:block;width:100%;margin:0;padding:0;border:0;background:transparent;cursor:zoom-in;text-align:left}
      .note-media-trigger img{display:block;width:100%;max-width:100%;height:auto;max-height:min(62vh,560px);object-fit:contain;border-radius:12px;border:1px solid #333;background:#0a0a0a}
      p.media{margin:.55rem 0 0}
      .ap-img-lightbox{position:fixed;inset:0;z-index:200;display:none;align-items:center;justify-content:center;padding:1rem;background:rgba(0,0,0,.88);backdrop-filter:blur(3px)}
      .ap-img-lightbox.open{display:flex}
      .ap-img-lightbox img{max-width:min(96vw,1200px);max-height:92vh;object-fit:contain;border-radius:8px;box-shadow:0 12px 40px rgba(0,0,0,.55)}
      .ap-img-lightbox__close{position:absolute;top:max(.75rem,env(safe-area-inset-top));right:max(.75rem,env(safe-area-inset-right));appearance:none;border:0;border-radius:999px;width:2.4rem;height:2.4rem;background:rgba(255,255,255,.14);color:#fff;font-size:1.4rem;line-height:1;cursor:pointer}
      .ap-img-lightbox__close:hover{background:rgba(255,255,255,.24)}
      @media (max-width:640px){
        main{width:100%;max-width:none;margin:1rem auto;padding:0 .75rem}
        .card{padding:1rem}
        .row{flex-wrap:wrap;gap:.75rem}
        .fields{overflow-wrap:anywhere}
        .follow-row input,.reply-row input{width:100%;min-width:0}
      }
    </style></head><body><main><div class="card">';
}

function ap_cmdr_lightbox_markup_and_script(): string
{
    $html = '<div class="ap-img-lightbox" id="ap-img-lightbox" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Image preview">';
    $html .= '<button type="button" class="ap-img-lightbox__close" id="ap-img-lightbox-close" aria-label="Close">×</button>';
    $html .= '<img id="ap-img-lightbox-img" src="" alt="">';
    $html .= '</div>';
    $html .= '<script>(function(){';
    $html .= 'var box=document.getElementById("ap-img-lightbox");';
    $html .= 'var img=document.getElementById("ap-img-lightbox-img");';
    $html .= 'var closeBtn=document.getElementById("ap-img-lightbox-close");';
    $html .= 'if(!box||!img)return;';
    $html .= 'function openLb(src,alt){if(!src)return;img.src=src;img.alt=alt||"";box.classList.add("open");box.setAttribute("aria-hidden","false");document.body.style.overflow="hidden";}';
    $html .= 'function closeLb(){box.classList.remove("open");box.setAttribute("aria-hidden","true");img.removeAttribute("src");img.alt="";document.body.style.overflow="";}';
    $html .= 'document.addEventListener("click",function(e){var t=e.target instanceof Element?e.target.closest(".note-media-trigger,[data-ap-lightbox]"):null;if(!t)return;e.preventDefault();var full=t.getAttribute("data-full")||(t.querySelector&&t.querySelector("img")&&t.querySelector("img").src)||"";var alt=(t.querySelector&&t.querySelector("img")&&t.querySelector("img").alt)||"";openLb(full,alt);});';
    $html .= 'closeBtn&&closeBtn.addEventListener("click",closeLb);';
    $html .= 'box.addEventListener("click",function(e){if(e.target===box)closeLb();});';
    $html .= 'document.addEventListener("keydown",function(e){if(e.key==="Escape"&&box.classList.contains("open"))closeLb();});';
    $html .= '})();</script>';
    return $html;
}

function ap_cmdr_site_shell_start(string $title): void
{
    $homeHtml = ap_cmdr_homepage_html();
    $sidebar = ap_cmdr_extract_homepage_sidebar($homeHtml);
    $footer = ap_cmdr_extract_homepage_footer($homeHtml);
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . $safeTitle . '</title>';
    echo '<link rel="icon" href="/vaak/favicon.svg?v=20260907" type="image/svg+xml">';
    echo '<link rel="icon" href="/vaak/favicon.ico?v=20260907" sizes="any">';
    echo '<link rel="icon" href="/vaak/favicon-32x32.png?v=20260907" type="image/png" sizes="32x32">';
    echo '<link rel="apple-touch-icon" href="/vaak/apple-touch-icon.png?v=20260907">';
    echo '<link rel="stylesheet" href="/assets/main.css">';
    echo '<link rel="stylesheet" href="/assets/css/operator-roster.css?v=20260805">';
    echo '<link rel="stylesheet" href="/assets/modal.css">';
    echo '<link rel="stylesheet" href="/assets/ap-home-profile.css?v=20260829a">';
    echo '<link rel="stylesheet" href="/assets/link_preview.css">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/regular/style.css">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/fill/style.css">';
    echo '<noscript><style>.sidebar-haunted-portrait.is-loading #asuka-image{opacity:1}</style></noscript>';
    echo '<style>
      /* AP profile inside site shell — widen center a bit for the fold experiment */
      body.ap-site-shell #main{
        min-width:0;max-width:920px;width:100%;
        padding:12px 14px 28px;box-sizing:border-box;
      }
      body.ap-site-shell .ap-site-banner{
        margin:0 0 1rem;padding:.65rem .85rem;border:1px dashed rgba(0,255,159,.35);
        background:rgba(0,255,159,.06);color:#cfe;border-radius:8px;font-size:.86rem;line-height:1.4;
      }
      body.ap-site-shell .ap-site-banner a{color:#7ee0ff}
      body.ap-site-shell .ap-site-main .card{
        max-width:none;margin:0;border:1px solid #333;border-radius:12px;
        padding:1.35rem;background:#121212;overflow:hidden;color:#e8e8e8;line-height:1.55;
      }
      body.ap-site-shell .ap-site-main a{color:#7ee0ff}
      body.ap-site-shell .ap-site-main .muted{color:#999}
      body.ap-site-shell .ap-site-main .profile-bio{margin-top:1rem}
      body.ap-site-shell .ap-site-main .profile-bio p{margin:0 0 .55em}
      body.ap-site-shell .ap-site-main .profile-bio p:last-child{margin-bottom:0}
      body.ap-site-shell .ap-site-main .field-verified{display:inline-flex;align-items:center;justify-content:center;width:1em;height:1em;margin-left:.35rem;border-radius:50%;background:rgba(0,255,159,.2);color:#00ff9f;font-size:.75em;font-weight:800;line-height:1;vertical-align:middle;position:relative;top:-.05em}
      body.ap-site-shell .ap-site-main .row{display:flex;gap:1rem;align-items:flex-start}
      body.ap-site-shell .ap-site-main .av{width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid #333;background:#222;flex-shrink:0}
      body.ap-site-shell .ap-site-main h1{font-size:1.35rem;margin:0 0 .35rem;color:#e8e8e8}
      body.ap-site-shell .ap-site-main .vanity-verified{display:inline-flex;align-items:center;justify-content:center;width:1.05em;height:1.05em;margin-left:.2rem;border-radius:50%;background:#1d9bf0;color:#fff;font-size:.72em;font-weight:800;line-height:1;vertical-align:middle;position:relative;top:-.08em}
      body.ap-site-shell .ap-site-main .note-body p{margin:0 0 .45em}
      body.ap-site-shell .ap-site-main .posts{margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid #2a2a2a}
      body.ap-site-shell .ap-site-main .post{display:block;padding:.9rem 0;border-bottom:1px solid #2a2a2a;color:inherit;text-decoration:none}
      body.ap-site-shell .ap-site-main .post:hover{background:rgba(126,224,255,.04)}
      body.ap-site-shell .ap-site-main .post.is-pinned{position:relative}
      body.ap-site-shell .ap-site-main .post-pin{position:absolute;top:-0.45rem;left:0;font-size:12px;line-height:1;color:#7ee0ff;opacity:.9;pointer-events:none;z-index:2}
      body.ap-site-shell .ap-site-main .stats{display:flex;gap:1.25rem;margin:1.1rem 0 0;padding-top:1rem;border-top:1px solid #2a2a2a}
      body.ap-site-shell .ap-site-main .stats .n{font-size:1.25rem;font-weight:700;color:#00ff9f}
      body.ap-site-shell .ap-site-main .stats .l{font-size:.8rem;color:#999;text-transform:uppercase}
      body.ap-site-shell .ap-site-main .profile-tabs{display:flex;gap:.35rem;margin:1.15rem 0 0;padding-top:1rem;border-top:1px solid #2a2a2a;flex-wrap:wrap}
      body.ap-site-shell .ap-site-main .profile-tabs a{text-decoration:none;color:#aaa;font-size:.9rem;font-weight:600;padding:.45rem .9rem;border-radius:999px}
      body.ap-site-shell .ap-site-main .profile-tabs a.is-active{color:#0b0b0b;background:#00ff9f}
      body.ap-site-shell .ap-site-main .featured-accounts{list-style:none;margin:0;padding:0}
      body.ap-site-shell .ap-site-main .featured-account{border-bottom:1px solid #2a2a2a}
      body.ap-site-shell .ap-site-main .featured-account-link{display:flex;align-items:center;gap:.85rem;padding:.85rem 0;color:inherit;text-decoration:none}
      body.ap-site-shell .ap-site-main .featured-av{width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid #333;background:#222;flex:0 0 auto}
      body.ap-site-shell .ap-site-main .featured-meta{display:flex;flex-direction:column;gap:.15rem;min-width:0}
      body.ap-site-shell .ap-site-main .featured-name{font-weight:650;color:#e8e8e8}
      body.ap-site-shell .ap-site-main .featured-acct{font-size:.85rem;color:#999}
      body.ap-site-shell .ap-site-main .btn-follow,.ap-site-main .btn-reply{appearance:none;border:0;border-radius:999px;padding:.55rem 1.15rem;font:inherit;font-weight:600;cursor:pointer}
      body.ap-site-shell .ap-site-main .btn-follow{background:#8bf;color:#061018}
      body.ap-site-shell .ap-site-main .btn-reply{background:#1a2a24;color:#00ff9f;border:1px solid rgba(0,255,159,.35)}
      body.ap-site-shell .ap-site-main .follow-panel,.ap-site-main .reply-panel{margin-top:.75rem;padding:.85rem;border:1px solid #2a2e37;border-radius:12px;background:#0c0c0c}
      body.ap-site-shell .ap-site-main .follow-panel[hidden],.ap-site-main .reply-panel[hidden]{display:none!important}
      body.ap-site-shell .ap-site-main .follow-row,.ap-site-main .reply-row{display:flex;gap:.5rem;flex-wrap:wrap}
      body.ap-site-shell .ap-site-main .follow-row input,.ap-site-main .reply-row input{flex:1;min-width:12rem;padding:.55rem .7rem;border-radius:10px;border:1px solid #333;background:#111;color:#eee;font:inherit;font-size:16px}
      body.ap-site-shell .ap-site-main .follow-row button,.ap-site-main .reply-row button{padding:.55rem .9rem;border-radius:10px;border:0;background:#2a2e37;color:#eee;font:inherit;cursor:pointer}
      body.ap-site-shell .ap-site-main .note-media-trigger{appearance:none;display:block;width:100%;margin:0;padding:0;border:0;background:transparent;cursor:zoom-in}
      body.ap-site-shell .ap-site-main .note-media-trigger img{display:block;width:100%;max-width:100%;height:auto;max-height:min(62vh,560px);object-fit:contain;border-radius:12px;border:1px solid #333;background:#0a0a0a}
      body.ap-site-shell .ap-site-main .media-row{width:100%}
      .ap-img-lightbox{position:fixed;inset:0;z-index:200;display:none;align-items:center;justify-content:center;padding:1rem;background:rgba(0,0,0,.88)}
      .ap-img-lightbox.open{display:flex}
      .ap-img-lightbox img{max-width:min(96vw,1200px);max-height:92vh;object-fit:contain;border-radius:8px}
      .ap-img-lightbox__close{position:absolute;top:.75rem;right:.75rem;appearance:none;border:0;border-radius:999px;width:2.4rem;height:2.4rem;background:rgba(255,255,255,.14);color:#fff;font-size:1.4rem;cursor:pointer}
      @media (max-width:900px){
        body.ap-site-shell{display:block!important}
        body.ap-site-shell #sidebar, body.ap-site-shell footer{display:none!important}
        body.ap-site-shell #main{max-width:100%!important;min-width:0!important;padding:8px 10px 20px}
        body.ap-site-shell .ap-site-main{min-width:0;width:100%}
        body.ap-site-shell .ap-site-main .card{box-sizing:border-box;padding:1rem}
        body.ap-site-shell .ap-site-main .row{flex-wrap:wrap;gap:.75rem}
      }
    </style>';
    echo '<script src="/assets/js/dynamic-avatar.js" defer></script>';
    echo '<script src="/assets/js/sidebar-widgets.js?v=20260805-nosubs" defer></script>';
    echo '<script src="/assets/js/modal2.js" defer></script>';
    echo '<script src="/assets/js/year.js" defer></script>';
    echo '</head><body class="ap-site-shell">';

    if ($sidebar !== '') {
        echo $sidebar;
    } else {
        echo '<div id="sidebar"><p class="muted" style="padding:1rem">Sidebar unavailable</p></div>';
    }

    echo '<div id="main"><div class="ap-site-main">';
    echo '<div class="ap-site-banner" role="status">';
    echo '<strong>Layout prototype</strong> — testing <code>@cmdr_nova</code> as a main-page-width profile with the site sidebars. ';
    echo 'Classic narrow view: <a href="?shell=classic">?shell=classic</a>';
    echo '</div>';
    echo '<div class="card">';
}

function ap_cmdr_shell_end(): void
{
    if (ap_cmdr_use_site_shell()) {
        echo '</div></div></div>'; // .card .ap-site-main #main
        $homeHtml = ap_cmdr_homepage_html();
        $footer = ap_cmdr_extract_homepage_footer($homeHtml);
        if ($footer !== '') {
            echo $footer;
        } else {
            echo '<footer><p style="padding:1rem">Footer unavailable</p></footer>';
        }
        echo ap_cmdr_lightbox_markup_and_script();
        echo '</body></html>';
        return;
    }

    echo '<footer class="ap-footer">';
    echo '<nav aria-label="Instance policies">';
    echo '<a href="https://mkultra.monster/vaak/privacy/">Privacy</a>';
    echo '<span class="sep" aria-hidden="true">·</span>';
    echo '<a href="https://mkultra.monster/vaak/conduct/">Code of Conduct</a>';
    echo '<span class="sep" aria-hidden="true">·</span>';
    echo '<a href="mailto:cmdr-nova@mkultra.monster">Contact</a>';
    echo '<span class="sep" aria-hidden="true">·</span>';
    echo '<a href="https://mkultra.monster/vaak/">VAAK</a>';
    echo '<span class="sep" aria-hidden="true">·</span>';
    echo '<a href="https://mkultra.monster/">mkultra.monster</a>';
    echo '</nav>';
    echo '<p class="muted" style="margin:0">VAAK · invite-only ActivityPub on mkultra.monster · no public registration</p>';
    echo '</footer>';
    echo '</div></main>';
    echo ap_cmdr_lightbox_markup_and_script();
    echo '</body></html>';
}

function ap_cmdr_note_html(array $row, array $create): void
{
    $note = is_array($create['object'] ?? null) ? $create['object'] : [];
    $content = (string) ($note['content'] ?? $row['content'] ?? '');
    $content = function_exists('ap_html_sanitize_allowlist')
        ? ap_html_sanitize_allowlist($content, '<p><br><a><strong><em><code><ul><ol><li>')
        : strip_tags($content, '<p><br><a><strong><em><code><ul><ol><li>');
    // Normalize legacy </p><br><p> spacers; CSS (.note-body p) handles spacing.
    if ($content !== '' && function_exists('ap_normalize_status_html')) {
        $content = ap_normalize_status_html($content);
    }
    $published = (string) ($note['published'] ?? $row['published'] ?? '');
    $dateLabel = $published;
    try {
        $dateLabel = (new DateTimeImmutable($published))->format('M j, Y · g:i A T');
    } catch (Throwable $e) {
        // keep raw
    }
    $p = ap_profile_get('cmdr_nova');
    $name = htmlspecialchars((string) $p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $vanityBadge = !empty($p['vanity_verified'])
        ? ' <span class="vanity-verified" title="Vanity verified (just for fun)" aria-label="Verified">✓</span>'
        : '';
    $avatar = htmlspecialchars((string) ($p['icon_url'] ?: '/img/avatar/current-wafrn-avatar.webp'), ENT_QUOTES, 'UTF-8');
    $noteId = htmlspecialchars((string) ($row['id'] ?? ''), ENT_QUOTES, 'UTF-8');

    $mediaHtml = '';
    $atts = $note['attachment'] ?? [];
    $mediaCells = [];
    $mediaAlts = [];
    if (is_array($atts)) {
        if (isset($atts['type'])) {
            $atts = [$atts];
        }
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
            $altRaw = (string) ($att['name'] ?? $att['summary'] ?? '');
            $alt = htmlspecialchars($altRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $isVideo = str_starts_with($mt, 'video/') || $atype === 'Video'
                || (bool) preg_match('/\.(mp4|webm|mov|m4v)(\?|$)/i', (string) (parse_url($url, PHP_URL_PATH) ?? ''));
            if (str_starts_with($mt, 'image/') || $atype === 'Image') {
                $mediaCells[] = '<button type="button" class="note-media-trigger" data-full="' . $safe . '" aria-label="View full image">'
                    . '<img src="' . $safe . '" alt="' . $alt . '" loading="lazy" referrerpolicy="no-referrer">'
                    . '</button>';
                if ($altRaw !== '') {
                    $mediaAlts[] = 'Alt: ' . $alt;
                }
            } elseif ($isVideo) {
                $mediaCells[] = '<video class="media-video" src="' . $safe . '" controls playsinline preload="metadata" referrerpolicy="no-referrer"></video>';
                if ($altRaw !== '') {
                    $mediaAlts[] = $alt;
                }
            } else {
                $mediaHtml .= '<p class="media"><a href="' . $safe . '" target="_blank" rel="noopener">' . ($alt !== '' ? $alt : $safe) . '</a></p>';
            }
            if (count($mediaCells) >= 4) {
                break;
            }
        }
    }
    if ($mediaCells !== []) {
        $n = count($mediaCells);
        $mediaHtml = '<div class="media-row media-count-' . $n . '">' . implode('', $mediaCells) . '</div>' . $mediaHtml;
        foreach ($mediaAlts as $altLine) {
            $mediaHtml .= '<p class="muted" style="font-size:.8rem;margin:.25rem 0 .75rem">' . $altLine . '</p>';
        }
    }

    $cw = '';
    if (!empty($note['summary']) && is_string($note['summary'])) {
        $cw = trim($note['summary']);
    }

    // Reply / quote context for the standalone note page
    $replyTo = rtrim((string) ($row['in_reply_to'] ?? ''), '/');
    if ($replyTo === '' && !empty($note['inReplyTo']) && is_string($note['inReplyTo'])) {
        $replyTo = rtrim($note['inReplyTo'], '/');
    }
    $quoteUrl = '';
    foreach (['quote', 'quoteUrl', '_misskey_quote'] as $qk) {
        if (!empty($note[$qk]) && is_string($note[$qk]) && str_starts_with($note[$qk], 'https://')) {
            $quoteUrl = rtrim($note[$qk], '/');
            break;
        }
    }

    ap_cmdr_shell_start('Post · @cmdr_nova@mkultra.monster');
    echo '<div class="row" style="margin-bottom:1rem">';
    echo '<img class="av" src="' . $avatar . '" alt="" width="72" height="72" referrerpolicy="no-referrer">';
    echo '<div><h1 style="font-size:1.1rem">' . $name . $vanityBadge . '</h1>';
    echo '<p class="muted" style="margin:0"><a href="/users/cmdr_nova">@cmdr_nova@mkultra.monster</a></p></div></div>';
    if ($replyTo !== '' && str_starts_with($replyTo, 'https://')) {
        $rSnippet = ap_cmdr_object_snippet($replyTo, 140);
        $rSafe = htmlspecialchars($replyTo, ENT_QUOTES, 'UTF-8');
        $label = $rSnippet !== ''
            ? htmlspecialchars($rSnippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : $rSafe;
        echo '<p class="reply-line">↩ reply to <a href="' . $rSafe . '" target="_blank" rel="noopener noreferrer">'
            . $label . '</a></p>';
    }
    if ($cw !== '') {
        echo '<p class="cw"><strong>CW</strong> · ' . htmlspecialchars($cw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    echo '<div class="note-body">' . $content . '</div>';
    // Link preview card (skip when note already has media)
    if ($mediaHtml === '' && function_exists('ap_link_preview_extract_url') && function_exists('ap_link_preview_for_url')) {
        $cardUrl = ap_link_preview_extract_url($content);
        if ($cardUrl !== null) {
            echo ap_link_preview_html(ap_link_preview_for_url($cardUrl, true));
        }
    }
    if ($quoteUrl !== '') {
        $qSnippet = ap_cmdr_object_snippet($quoteUrl, 220);
        $qSafe = htmlspecialchars($quoteUrl, ENT_QUOTES, 'UTF-8');
        echo '<div class="quote-block"><span class="qt-label">Quoted</span>';
        if ($qSnippet !== '') {
            echo htmlspecialchars($qSnippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '<div class="muted" style="margin-top:.4rem;font-size:.75rem"><a href="' . $qSafe . '" target="_blank" rel="noopener noreferrer">Open original</a></div>';
        } else {
            echo '<a href="' . $qSafe . '" target="_blank" rel="noopener noreferrer">' . $qSafe . '</a>';
        }
        echo '</div>';
    }
    // Poll options (prefer masto_polls tallies)
    $choices = $note['oneOf'] ?? $note['anyOf'] ?? null;
    $pollVotes = null;
    $pollExpired = false;
    $noteKey = (string) ($row['id'] ?? '');
    if ($noteKey !== '') {
        try {
            $pst = ap_db()->prepare('SELECT options_json, votes_count, voters_count, expires_at FROM masto_polls WHERE note_id = ? OR note_id = ?');
            $pst->execute([$noteKey, $noteKey . '/']);
            $prow = $pst->fetch();
            if (is_array($prow)) {
                $decoded = json_decode((string) ($prow['options_json'] ?? '[]'), true);
                if (is_array($decoded) && $decoded) {
                    $choices = $decoded;
                    $pollVotes = [
                        'votes' => (int) ($prow['votes_count'] ?? 0),
                        'voters' => (int) ($prow['voters_count'] ?? 0),
                    ];
                }
                try {
                    if (!empty($prow['expires_at'])) {
                        $pollExpired = (new DateTimeImmutable((string) $prow['expires_at'])) <= new DateTimeImmutable('now');
                    }
                } catch (Throwable $e) {
                    $pollExpired = false;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    if (is_array($choices) && $choices) {
        echo '<div class="poll-block" style="margin-top:.85rem"><span class="poll-label">Poll'
            . ($pollExpired ? ' · closed' : '') . '</span>';
        foreach ($choices as $ch) {
            if (!is_array($ch)) {
                continue;
            }
            $title = trim((string) ($ch['name'] ?? $ch['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $vc = isset($ch['votes_count']) ? (int) $ch['votes_count'] : null;
            $label = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($vc !== null) {
                $label .= ' <span class="muted">(' . $vc . ')</span>';
            }
            echo '<span class="poll-opt">' . $label . '</span>';
        }
        if (is_array($pollVotes)) {
            echo '<div class="poll-meta">'
                . (int) $pollVotes['votes'] . ' vote' . ((int) $pollVotes['votes'] === 1 ? '' : 's')
                . ' · ' . (int) $pollVotes['voters'] . ' voter' . ((int) $pollVotes['voters'] === 1 ? '' : 's')
                . '</div>';
        }
        echo '</div>';
    }
    echo $mediaHtml;
    echo '<p class="muted" style="margin-top:1.25rem;font-size:.85rem">' . htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8');
    if ($quoteUrl !== '') {
        echo ' · quote';
    } elseif ($replyTo !== '') {
        echo ' · reply';
    }
    echo '</p>';
    echo '<p class="muted mono" style="font-size:.7rem">' . $noteId . '</p>';

    // Remote reply: open the visitor's home instance interact dialog for this Note.
    $noteUriRaw = (string) ($row['id'] ?? '');
    if ($noteUriRaw !== '' && str_starts_with($noteUriRaw, 'https://')) {
        echo '<div class="note-actions">';
        echo '<button type="button" class="btn-reply" id="ap-reply-toggle" aria-expanded="false" aria-controls="ap-reply-panel">Reply on your instance</button>';
        echo '</div>';
        echo '<div class="reply-panel" id="ap-reply-panel" hidden>';
        echo '<form id="ap-reply-form" action="#" method="get">';
        echo '<label for="ap-reply-instance">Your fediverse instance</label>';
        echo '<div class="reply-row">';
        echo '<input id="ap-reply-instance" name="instance" type="text" inputmode="url" autocomplete="url" spellcheck="false" placeholder="mastodon.social or @you@mastodon.social" required>';
        echo '<button type="submit">Continue</button>';
        echo '</div>';
        echo '<p class="reply-hint" id="ap-reply-hint">Opens your instance so you can reply to this post while logged in there (Mastodon, Akkoma, GoToSocial, etc.).</p>';
        echo '</form></div>';
        echo '<script>(function(){';
        echo 'var NOTE=' . json_encode($noteUriRaw, JSON_UNESCAPED_SLASHES) . ';';
        echo 'var toggle=document.getElementById("ap-reply-toggle");';
        echo 'var panel=document.getElementById("ap-reply-panel");';
        echo 'var form=document.getElementById("ap-reply-form");';
        echo 'var input=document.getElementById("ap-reply-instance");';
        echo 'var hint=document.getElementById("ap-reply-hint");';
        echo 'if(!toggle||!panel||!form||!input||!NOTE)return;';
        echo 'var DEFAULT_HINT="Opens your instance so you can reply to this post while logged in there (Mastodon, Akkoma, GoToSocial, etc.).";';
        echo 'function setErr(m){if(!hint)return;if(m){hint.textContent=m;hint.classList.add("is-error");}else{hint.textContent=DEFAULT_HINT;hint.classList.remove("is-error");}}';
        echo 'function parseInstance(raw){var s=String(raw||"").trim();if(!s)return null;if(s.indexOf("acct:")===0)s=s.slice(5);s=s.replace(/^https?:\\/\\//i,"").replace(/\\/.*$/,"");if(s.charAt(0)==="@"){var m=s.match(/^@([A-Za-z0-9_.\\-]+)@([A-Za-z0-9.\\-]+\\.[A-Za-z]{2,})$/);return m?m[2].toLowerCase():null;}s=s.replace(/^@/,"");if(/^[A-Za-z0-9.\\-]+\\.[A-Za-z]{2,}$/.test(s))return s.toLowerCase();return null;}';
        echo 'toggle.addEventListener("click",function(){var open=panel.hasAttribute("hidden");if(open){panel.removeAttribute("hidden");toggle.setAttribute("aria-expanded","true");setErr("");setTimeout(function(){input.focus();},0);}else{panel.setAttribute("hidden","");toggle.setAttribute("aria-expanded","false");}});';
        echo 'form.addEventListener("submit",function(ev){ev.preventDefault();var host=parseInstance(input.value);if(!host){setErr("Enter your instance domain (mastodon.social) or full address (@you@mastodon.social)");input.focus();return;}setErr("");window.location.href="https://"+host+"/authorize_interaction?uri="+encodeURIComponent(NOTE);});';
        echo '})();</script>';
    }

    echo '<p class="back"><a href="/users/cmdr_nova">← profile</a> · <a href="/users/cmdr_nova/outbox">all posts</a></p>';
    ap_cmdr_shell_end();
}

function ap_cmdr_html(): void
{
    $p = ap_profile_get('cmdr_nova');
    $followers = [];
    $following = [];
    try {
        $followers = ap_followers_list(CMDR_ACTOR_ID);
        $following = ap_following_list(CMDR_ACTOR_ID);
    } catch (Throwable $e) {
        // leave empty
    }
    $followerCount = count($followers);
    $followingCount = count($following);

    $name = htmlspecialchars($p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $vanityBadge = !empty($p['vanity_verified'])
        ? ' <span class="vanity-verified" title="Vanity verified (just for fun)" aria-label="Verified">✓</span>'
        : '';
    // Keep anchors clickable; force safe rel on any <a>
    $summary = strip_tags($p['summary'], '<p><br><a><code><strong><em><b><i>');
    $summary = preg_replace_callback(
        '/<a\s+([^>]*?)>/i',
        static function (array $m): string {
            $href = '';
            if (preg_match('/href\s*=\s*([\'"])(https?:\/\/[^\'"]+)\1/i', $m[1], $hm)) {
                $href = $hm[2];
            }
            if ($href === '' || !preg_match('#^https://#i', $href)) {
                return '<a>';
            }
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" rel="nofollow noopener noreferrer">';
        },
        $summary
    ) ?? $summary;

    ap_cmdr_shell_start('@cmdr_nova@mkultra.monster');
    if (!empty($p['image_url'])) {
        $banner = htmlspecialchars($p['image_url'], ENT_QUOTES, 'UTF-8');
        echo '<div class="banner" style="background-image:url(\'' . $banner . '\')"></div>';
    }
    echo '<div class="row">';
    if (!empty($p['icon_url'])) {
        echo '<img class="av" src="' . htmlspecialchars($p['icon_url'], ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy" referrerpolicy="no-referrer">';
    }
    echo '<div><h1>' . $name . $vanityBadge . '</h1>';
    echo '<p class="muted" style="margin:0">@cmdr_nova@mkultra.monster</p>';
    if (!function_exists('ap_sl_link_for_actor_key')) {
        require_once __DIR__ . '/ap-sl-link.php';
    }
    $slLink = ap_sl_link_for_actor_key('cmdr_nova');
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
    $summary = function_exists('ap_profile_normalize_summary_html')
        ? ap_profile_normalize_summary_html($summary)
        : $summary;
    echo '<div class="profile-bio">' . $summary . '</div>';

    echo '<div class="follow-wrap">';
    echo '<button type="button" class="btn-follow" id="ap-follow-toggle" aria-expanded="false" aria-controls="ap-follow-panel">Follow</button>';
    echo '<div class="follow-panel" id="ap-follow-panel" hidden>';
    echo '<form id="ap-follow-form" action="#" method="get">';
    echo '<label for="ap-follow-handle">Follow from your fediverse account</label>';
    echo '<div class="follow-row">';
    echo '<input id="ap-follow-handle" name="handle" type="text" inputmode="email" autocomplete="username" spellcheck="false" placeholder="@you@your.instance" required>';
    echo '<button type="submit">Go</button>';
    echo '</div>';
    echo '<p class="follow-hint" id="ap-follow-hint">Opens your instance’s follow dialog (Mastodon, Akkoma, GoToSocial, etc.).</p>';
    echo '</form></div></div>';

    if (!empty($p['attachment'])) {
        echo '<dl class="fields">';
        foreach ($p['attachment'] as $att) {
            if (!is_array($att)) {
                continue;
            }
            $ln = htmlspecialchars((string) ($att['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lv = ap_cmdr_linkify_value((string) ($att['value'] ?? ''));
            if ($ln === '' || $lv === '') {
                continue;
            }
            $fieldVerified = !empty($att['verified_at']) && is_string($att['verified_at']);
            $verMark = $fieldVerified
                ? ' <span class="field-verified" title="Verified via rel=me' .
                    (!empty($att['verified_at']) ? (' · ' . htmlspecialchars((string) $att['verified_at'], ENT_QUOTES, 'UTF-8')) : '') .
                    '" aria-label="Verified">✓</span>'
                : '';
            echo '<dt>' . $ln . '</dt><dd>' . $lv . $verMark . '</dd>';
        }
        echo '</dl>';
    }

    $perPage = 20;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $tab = ap_cmdr_normalize_profile_tab((string) ($_GET['tab'] ?? 'posts'));
    $featuredCards = function_exists('ap_featured_cards_for_actor_key')
        ? ap_featured_cards_for_actor_key('cmdr_nova')
        : [];
    $featuredCount = count($featuredCards);
    // HTML profile feed = site blogs + notes + AP compose (no federation side effects).
    // Featured tab still needs post counts for the other tab badges.
    $feedTab = $tab === 'featured' ? 'posts' : $tab;
    $postsData = ap_cmdr_posts_page($page, $perPage, $feedTab);
    $counts = is_array($postsData['counts'] ?? null) ? $postsData['counts'] : ['posts' => 0, 'replies' => 0, 'boosts' => 0];
    $counts['featured'] = $featuredCount;
    $tabTotal = (int) ($postsData['total'] ?? 0);
    $statPosts = (int) ($counts['posts'] ?? 0) + (int) ($counts['replies'] ?? 0); // original posts + replies (not boosts)

    echo '<div class="stats">';
    echo '<div><span class="n">' . $statPosts . '</span><span class="l">Posts</span></div>';
    echo '<a href="/users/cmdr_nova/following"><span class="n">' . (int) $followingCount . '</span><span class="l">Following</span></a>';
    echo '<a href="/users/cmdr_nova/followers"><span class="n">' . (int) $followerCount . '</span><span class="l">Followers</span></a>';
    echo '</div>';

    echo '<nav class="profile-tabs" aria-label="Profile timeline">';
    foreach (
        [
            'posts' => 'Posts',
            'replies' => 'Replies',
            'boosts' => 'Boosts',
            'featured' => 'Featured',
        ] as $tKey => $tLabel
    ) {
        $href = $tKey === 'posts' ? '/users/cmdr_nova' : ('/users/cmdr_nova?tab=' . rawurlencode($tKey));
        $cls = $tab === $tKey ? ' class="is-active"' : '';
        $aria = $tab === $tKey ? ' aria-current="page"' : '';
        $n = (int) ($counts[$tKey] ?? 0);
        echo '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $cls . $aria . '>'
            . htmlspecialchars($tLabel, ENT_QUOTES, 'UTF-8')
            . '<span class="tab-count">' . $n . '</span></a>';
    }
    echo '</nav>';

    if ($tab === 'featured') {
        echo '<section class="posts featured-section" aria-label="Featured">';
        echo '<h2 class="visually-hidden" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)">Featured</h2>';
        echo function_exists('ap_featured_cards_html')
            ? ap_featured_cards_html($featuredCards)
            : '<p class="muted">No featured accounts yet.</p>';
        echo '</section>';
    } else {
        $sectionLabel = match ($tab) {
            'replies' => 'Replies',
            'boosts' => 'Boosts',
            default => 'Posts',
        };
        $emptyMsg = match ($tab) {
            'replies' => 'No public replies yet.',
            'boosts' => 'No public boosts yet.',
            default => 'No public posts yet.',
        };
        echo '<section class="posts" aria-label="' . htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') . '">';
        echo '<h2 class="visually-hidden" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)">'
            . htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') . '</h2>';
        if (!$postsData['rows']) {
            echo '<p class="muted">' . htmlspecialchars($emptyMsg, ENT_QUOTES, 'UTF-8') . '</p>';
        } else {
            foreach ($postsData['rows'] as $n) {
                echo ap_cmdr_post_preview_html($n);
            }
            $totalPages = max(1, (int) ceil($tabTotal / $perPage));
            if ($totalPages > 1) {
                $extra = $tab === 'posts' ? [] : ['tab' => $tab];
                echo ap_cmdr_pager_html('/users/cmdr_nova', $page, $totalPages, $extra);
            }
        }
        echo '</section>';
    }

    echo '<p class="back"><a href="https://mkultra.monster/">← mkultra.monster</a> · <a href="/users/cmdr_nova/outbox">outbox</a></p>';
    echo '<script>(function(){';
    echo 'var ACTOR=' . json_encode(CMDR_ACTOR_ID) . ';';
    echo 'var toggle=document.getElementById("ap-follow-toggle");';
    echo 'var panel=document.getElementById("ap-follow-panel");';
    echo 'var form=document.getElementById("ap-follow-form");';
    echo 'var input=document.getElementById("ap-follow-handle");';
    echo 'var hint=document.getElementById("ap-follow-hint");';
    echo 'if(!toggle||!panel||!form||!input)return;';
    echo 'function setErr(m){if(!hint)return;if(m){hint.textContent=m;hint.classList.add("is-error");}else{hint.textContent="Opens your instance’s follow dialog (Mastodon, Akkoma, GoToSocial, etc.).";hint.classList.remove("is-error");}}';
    echo 'function parseHandle(raw){var s=String(raw||"").trim();if(s.indexOf("acct:")===0)s=s.slice(5);if(s.charAt(0)!=="@")s="@"+s;var m=s.match(/^@([A-Za-z0-9_.\\-]+)@([A-Za-z0-9.\\-]+\\.[A-Za-z]{2,})$/);return m?{user:m[1],host:m[2].toLowerCase()}:null;}';
    echo 'toggle.addEventListener("click",function(){var open=panel.hasAttribute("hidden");if(open){panel.removeAttribute("hidden");toggle.setAttribute("aria-expanded","true");setErr("");setTimeout(function(){input.focus();},0);}else{panel.setAttribute("hidden","");toggle.setAttribute("aria-expanded","false");}});';
    echo 'form.addEventListener("submit",function(ev){ev.preventDefault();var p=parseHandle(input.value);if(!p){setErr("Use a full address like @you@mastodon.social");input.focus();return;}setErr("");window.open("https://"+p.host+"/authorize_interaction?uri="+encodeURIComponent(ACTOR),"_blank","noopener,noreferrer");});';
    echo '})();</script>';
    ap_cmdr_shell_end();
}

/**
 * Load historical Jekyll blogs/notes for the HTML profile feed only.
 * Never writes to outbox / never federates.
 *
 * @return list<array<string,mixed>>
 */
function ap_cmdr_site_archive_items(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }
    $cached = [];
    $webRoot = dirname(__DIR__); // /srv/mkultra/html
    $files = [
        'site_blog' => $webRoot . '/assets/js/posts_metadata.json',
        'site_note' => $webRoot . '/assets/js/notes_metadata.json',
    ];
    foreach ($files as $kind => $path) {
        if (!is_readable($path)) {
            continue;
        }
        $raw = file_get_contents($path);
        $list = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($list)) {
            continue;
        }
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $urlPath = trim((string) ($item['url'] ?? ''));
            if ($title === '' || $urlPath === '') {
                continue;
            }
            if (!str_starts_with($urlPath, '/')) {
                $urlPath = '/' . ltrim($urlPath, '/');
            }
            // metadata may URL-encode spaces in paths
            $urlPath = str_replace(' ', '%20', $urlPath);
            $abs = 'https://mkultra.monster' . $urlPath;
            $published = ap_cmdr_parse_site_date((string) ($item['date'] ?? ''), $urlPath);
            $image = $item['image'] ?? null;
            $imageUrl = null;
            if (is_string($image) && $image !== '') {
                if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
                    // Prefer https for old blogspot thumbs
                    $imageUrl = preg_replace('#^http://#i', 'https://', $image) ?: $image;
                } elseif (str_starts_with($image, '/')) {
                    $imageUrl = 'https://mkultra.monster' . $image;
                }
            }
            $label = $kind === 'site_note' ? 'Note' : 'Blog';
            $content = '<p><strong>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>'
                . '<p class="muted">' . $label . ' on mkultra.monster</p>';
            $cached[] = [
                'id' => $abs,
                'create_id' => '',
                'published' => $published,
                'content' => $content,
                'in_reply_to' => null,
                'to_json' => '[]',
                'cc_json' => '[]',
                'raw_create_json' => '',
                'kind' => $kind,
                'site_title' => $title,
                'site_url' => $abs,
                'site_image' => $imageUrl,
            ];
        }
    }
    return $cached;
}

/**
 * Parse Jekyll metadata dates. Never falls back to "now" (that pinned broken
 * posts at the top of the profile feed). Prefer URL path /YYYY/MM/DD/ on failure.
 */
function ap_cmdr_parse_site_date(string $raw, string $urlPath = ''): string
{
    $candidates = [];
    $raw = trim($raw);
    $raw = trim($raw, " \t\"'");
    $rawLooksBroken = false;
    if ($raw !== '') {
        // "2015-09-26 20:30:00 05:00" / "... 0500" (missing +/-) — try fixed forms FIRST
        if (preg_match('/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?)\s+(\d{2}:?\d{2})$/', $raw, $m)) {
            $rawLooksBroken = true;
            $off = str_replace(':', '', $m[2]);
            // Historical mkultra posts are US-based; prefer western offsets
            $candidates[] = $m[1] . ' -' . $off;
            $candidates[] = $m[1] . ' +' . $off;
            $candidates[] = $m[1] . 'Z';
        }
        // "2024-07-06 04:03 -0500" (no seconds)
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(\s*)([Zz]|[+-]\d{2}:?\d{2}|[+-]\d{4})?$/', $raw, $m)) {
            $tz = isset($m[4]) && $m[4] !== '' ? $m[4] : 'Z';
            $candidates[] = $m[1] . 'T' . $m[2] . ':00' . $tz;
            $candidates[] = $m[1] . ' ' . $m[2] . ':00 ' . $tz;
        }
        // ISO-ish with space → T
        if (str_contains($raw, ' ') && !str_contains($raw, 'T')) {
            $candidates[] = preg_replace('/ /', 'T', $raw, 1) ?? $raw;
        }
        // Only try raw last — PHP misreads "… 0500" as year 0500
        if (!$rawLooksBroken) {
            $candidates[] = $raw;
        }
    }
    // Fallback from permalink /2015/09/26/...
    if ($urlPath !== '' && preg_match('#/(20\d{2})/(\d{2})/(\d{2})/#', urldecode($urlPath), $m)) {
        $candidates[] = $m[1] . '-' . $m[2] . '-' . $m[3] . 'T12:00:00Z';
    }

    foreach ($candidates as $cand) {
        $cand = trim($cand);
        if ($cand === '') {
            continue;
        }
        try {
            $dt = new DateTimeImmutable($cand);
            $utc = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            // Reject absurd parses (e.g. year 0500)
            $year = (int) substr($utc, 0, 4);
            if ($year < 1990 || $year > ((int) gmdate('Y') + 1)) {
                continue;
            }
            return $utc;
        } catch (Throwable $e) {
            continue;
        }
    }
    // Last resort: far past so it sorts to the end, not the top
    return '1970-01-01T00:00:00Z';
}

/** Pretty pagination for profile / outbox HTML. */
/**
 * @param array<string,scalar> $extraQuery preserved query params (e.g. tab=boosts)
 */
function ap_cmdr_pager_html(string $basePath, int $page, int $totalPages, array $extraQuery = []): string
{
    $page = max(1, $page);
    $totalPages = max(1, $totalPages);
    $html = '<nav class="pager" aria-label="Pagination">';
    $html .= '<div class="pager-status">Page ' . $page . ' of ' . $totalPages . '</div>';

    $btn = static function (string $label, ?int $target, bool $current = false, bool $disabled = false) use ($basePath, $extraQuery): string {
        if ($disabled || $target === null) {
            return '<span class="pager-btn is-disabled" aria-disabled="true">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $q = array_merge($extraQuery, ['page' => $target]);
        // Drop default page=1 noise when no other params? Keep page always for clarity.
        $href = htmlspecialchars($basePath . '?' . http_build_query($q), ENT_QUOTES, 'UTF-8');
        $cls = 'pager-btn' . ($current ? ' is-current' : '');
        $aria = $current ? ' aria-current="page"' : '';
        return '<a class="' . $cls . '" href="' . $href . '"' . $aria . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    };

    $html .= $btn('← Newer', $page > 1 ? $page - 1 : null, false, $page <= 1);

    // Sliding window of page numbers
    $window = 2;
    $start = max(1, $page - $window);
    $end = min($totalPages, $page + $window);
    if ($start > 1) {
        $html .= $btn('1', 1, $page === 1);
        if ($start > 2) {
            $html .= '<span class="pager-btn is-disabled">…</span>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $btn((string) $i, $i, $i === $page);
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<span class="pager-btn is-disabled">…</span>';
        }
        $html .= $btn((string) $totalPages, $totalPages, $page === $totalPages);
    }

    $html .= $btn('Older →', $page < $totalPages ? $page + 1 : null, false, $page >= $totalPages);
    $html .= '</nav>';
    return $html;
}

/**
 * URLs already represented by AP outbox blog/note shares (avoid double-listing).
 *
 * @return array<string,true>
 */
function ap_cmdr_outbox_site_url_index(): array
{
    $out = [];
    try {
        $rows = ap_db()->query(
            "SELECT id, content FROM outbox_notes WHERE COALESCE(kind, 'compose') IN ('blog', 'note')"
        )->fetchAll();
    } catch (Throwable $e) {
        return $out;
    }
    foreach ($rows as $r) {
        $id = (string) ($r['id'] ?? '');
        if (str_starts_with($id, 'https://mkultra.monster/') && !str_contains($id, '/users/cmdr_nova/notes/')) {
            $out[rtrim($id, '/')] = true;
        }
        $content = (string) ($r['content'] ?? '');
        if (preg_match_all('#https://mkultra\.monster/[^\"\s<]+#', $content, $m)) {
            foreach ($m[0] as $u) {
                $out[rtrim($u, '/')] = true;
            }
        }
    }
    // site_syndications table when present
    try {
        $rows = ap_db()->query('SELECT url FROM site_syndications')->fetchAll();
        foreach ($rows as $r) {
            $u = rtrim((string) ($r['url'] ?? ''), '/');
            if ($u !== '') {
                $out[$u] = true;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

/**
 * Paginated HTML profile feed: site blogs + notes + AP compose/blog/note.
 * Site archive items are display-only (not ActivityPub Creates).
 *
 * @param 'posts'|'replies'|'boosts' $tab
 * @return array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int,tab:string,counts:array{posts:int,replies:int,boosts:int}}
 */
function ap_cmdr_posts_page(int $page, int $perPage = 20, string $tab = 'posts'): array
{
    $perPage = max(1, min(50, $perPage));
    $page = max(1, $page);
    $tab = ap_cmdr_normalize_profile_tab($tab);

    $posts = [];
    $replies = [];
    $boosts = [];

    // AP outbox: compose, replies, quotes, polls, + syndicated blog/note shares.
    // HTML profile is discovery-facing: only fully public posts (not unlisted/private).
    try {
        $st = ap_db()->prepare(
            "SELECT * FROM outbox_notes
             WHERE id LIKE ?
               AND COALESCE(kind, 'compose') IN ('compose', 'blog', 'note', 'quote', 'poll')
             ORDER BY published DESC"
        );
        $st->execute([CMDR_ACTOR_ID . '/%']);
        foreach ($st->fetchAll() as $row) {
            $vis = (string) ($row['visibility'] ?? 'public');
            if (function_exists('ap_visibility_on_html_profile') && !ap_visibility_on_html_profile($vis)) {
                continue;
            }
            $replyTo = rtrim(trim((string) ($row['in_reply_to'] ?? '')), '/');
            if ($replyTo !== '' && str_starts_with($replyTo, 'https://')) {
                $replies[] = $row;
            } else {
                $posts[] = $row;
            }
        }
    } catch (Throwable $e) {
        // continue with site archive / boosts
    }

    // Own boosts (Announces) — HTML profile only; ActivityPub outbox already merges them
    if (function_exists('ap_masto_reblog_rows')) {
        try {
            $cmdrUid = function_exists('ap_db_cmdr_nova_user_id') ? ap_db_cmdr_nova_user_id() : 1;
            foreach (ap_masto_reblog_rows(80, null, $cmdrUid) as $rb) {
                $announceId = trim((string) ($rb['announce_activity_id'] ?? ''));
                $objectId = trim((string) ($rb['object_id'] ?? ''));
                if ($objectId === '' || !str_starts_with($objectId, 'https://')) {
                    continue;
                }
                $boosts[] = [
                    'kind' => 'boost',
                    // Card links to the boosted object (Mastodon-style); announce id kept for AP
                    'id' => $objectId,
                    'announce_activity_id' => $announceId,
                    'object_id' => $objectId,
                    'target_actor' => (string) ($rb['target_actor'] ?? ''),
                    'published' => (string) ($rb['created_at'] ?? ''),
                    'content' => '',
                    'in_reply_to' => null,
                    'raw_create_json' => '',
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Site archive items only on Posts tab (not replies/boosts)
    $syndicated = ap_cmdr_outbox_site_url_index();
    foreach (ap_cmdr_site_archive_items() as $siteItem) {
        $key = rtrim((string) ($siteItem['site_url'] ?? $siteItem['id'] ?? ''), '/');
        if ($key !== '' && isset($syndicated[$key])) {
            continue; // already shown via AP share card
        }
        $posts[] = $siteItem;
    }

    $sortDesc = static function (array &$items): void {
        usort($items, static function ($a, $b) {
            return strcmp((string) ($b['published'] ?? ''), (string) ($a['published'] ?? ''));
        });
    };
    $sortDesc($posts);
    $sortDesc($replies);
    $sortDesc($boosts);

    // Pinned posts (Ice Cubes + HTML profile) — surface at top of Posts tab
    $pinnedRows = [];
    $pinnedNoteIds = [];
    if (function_exists('ap_masto_pinned_statuses') && ($tab === 'posts' || $tab === 'all')) {
        try {
            foreach (ap_masto_pinned_statuses(5) as $prow) {
                if (!is_array($prow)) {
                    continue;
                }
                $nid = rtrim((string) ($prow['note_id'] ?? ''), '/');
                if ($nid === '') {
                    continue;
                }
                $pinnedNoteIds[$nid] = true;
                // Prefer full outbox row when present
                $match = null;
                foreach ($posts as $p) {
                    $pid = rtrim((string) ($p['id'] ?? ''), '/');
                    if ($pid === $nid) {
                        $match = $p;
                        break;
                    }
                }
                if ($match === null) {
                    try {
                        $ost = ap_db()->prepare('SELECT * FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
                        $ost->execute([$nid, $nid . '/']);
                        $orow = $ost->fetch();
                        if (is_array($orow)) {
                            $match = $orow;
                        }
                    } catch (Throwable $e) {
                        $match = null;
                    }
                }
                if (is_array($match)) {
                    $match['_pinned'] = true;
                    $pinnedRows[] = $match;
                }
            }
        } catch (Throwable $e) {
            $pinnedRows = [];
            $pinnedNoteIds = [];
        }
    }
    if ($pinnedNoteIds !== []) {
        $posts = array_values(array_filter($posts, static function ($p) use ($pinnedNoteIds) {
            $pid = rtrim((string) ($p['id'] ?? ''), '/');
            return $pid === '' || !isset($pinnedNoteIds[$pid]);
        }));
    }

    $counts = [
        'posts' => count($posts) + count($pinnedRows),
        'replies' => count($replies),
        'boosts' => count($boosts),
    ];
    if ($tab === 'all') {
        $items = array_merge($pinnedRows, $posts, $replies, $boosts);
        // Keep pins first; sort the rest
        $rest = array_slice($items, count($pinnedRows));
        $sortDesc($rest);
        $items = array_merge($pinnedRows, $rest);
    } else {
        $items = match ($tab) {
            'replies' => $replies,
            'boosts' => $boosts,
            default => array_merge($pinnedRows, $posts),
        };
    }

    $total = count($items);
    $offset = ($page - 1) * $perPage;
    $rows = array_slice($items, $offset, $perPage);
    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'tab' => $tab,
        'counts' => $counts,
    ];
}

function ap_cmdr_normalize_profile_tab(string $tab): string
{
    $tab = strtolower(trim($tab));
    return in_array($tab, ['posts', 'replies', 'boosts', 'featured', 'all'], true) ? $tab : 'posts';
}

/**
 * Best-effort plain-text snippet for a remote/local object URL (reply/quote parent).
 */
function ap_cmdr_object_snippet(string $objectUrl, int $maxLen = 140): string
{
    $objectUrl = rtrim(trim($objectUrl), '/');
    if ($objectUrl === '' || !str_starts_with($objectUrl, 'https://')) {
        return '';
    }
    $clip = static function (string $plain) use ($maxLen): string {
        $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
        if ($plain === '') {
            return '';
        }
        return mb_strlen($plain) > $maxLen ? mb_substr($plain, 0, $maxLen - 1) . '…' : $plain;
    };

    // Local outbox note
    if (str_starts_with($objectUrl, 'https://mkultra.monster/users/cmdr_nova/notes/')) {
        try {
            $st = ap_db()->prepare('SELECT content FROM outbox_notes WHERE id = ? OR id = ?');
            $st->execute([$objectUrl, $objectUrl . '/']);
            $row = $st->fetch();
            if (is_array($row) && !empty($row['content'])) {
                $plain = html_entity_decode(strip_tags((string) $row['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $out = $clip($plain);
                if ($out !== '') {
                    return $out;
                }
            }
        } catch (Throwable $e) {
            // fall through
        }
    }
    // Federated events cache
    if (function_exists('ap_event_by_object_id')) {
        $ev = ap_event_by_object_id($objectUrl);
        if (is_array($ev) && !empty($ev['summary'])) {
            $out = $clip((string) $ev['summary']);
            if ($out !== '') {
                return $out;
            }
        }
    }
    // Mentions cache
    try {
        $st = ap_db()->prepare(
            'SELECT content FROM mentions WHERE (object_id = ? OR object_id = ?) AND deleted_at IS NULL LIMIT 1'
        );
        $st->execute([$objectUrl, $objectUrl . '/']);
        $row = $st->fetch();
        if (is_array($row) && !empty($row['content'])) {
            $plain = html_entity_decode(strip_tags((string) $row['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $out = $clip($plain);
            if ($out !== '') {
                return $out;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Live fetch remote AS2 once (profile pages only have a handful of quotes)
    if (!str_starts_with($objectUrl, 'https://mkultra.monster/')) {
        try {
            if (!function_exists('ap_fetch_as2_object')) {
                if (!defined('AP_INBOX_LIB_ONLY')) {
                    define('AP_INBOX_LIB_ONLY', true);
                }
                require_once __DIR__ . '/ap-inbox.php';
            }
            if (function_exists('ap_fetch_as2_object')) {
                $doc = ap_fetch_as2_object($objectUrl);
                if (is_array($doc)) {
                    if (($doc['type'] ?? '') === 'Create' && isset($doc['object']) && is_array($doc['object'])) {
                        $doc = $doc['object'];
                    }
                    $raw = '';
                    if (!empty($doc['content']) && is_string($doc['content'])) {
                        $raw = $doc['content'];
                    } elseif (!empty($doc['summary']) && is_string($doc['summary']) && empty($doc['sensitive'])) {
                        $raw = $doc['summary'];
                    } elseif (!empty($doc['name']) && is_string($doc['name'])) {
                        $raw = $doc['name'];
                    }
                    $out = $clip(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($out !== '') {
                        return $out;
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore fetch failures
        }
    }
    return '';
}

/** Short host/path label when we have no text snippet (never nest <a> inside .post). */
function ap_cmdr_short_url_label(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return mb_strlen($url) > 64 ? mb_substr($url, 0, 61) . '…' : $url;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '/');
    $label = $host . $path;
    if (mb_strlen($label) > 72) {
        $label = mb_substr($label, 0, 69) . '…';
    }
    return $label !== '' ? $label : $url;
}

function ap_cmdr_post_preview_html(array $n): string
{
    $kind = (string) ($n['kind'] ?? 'compose');
    $isSite = ($kind === 'site_blog' || $kind === 'site_note');
    $isBoost = ($kind === 'boost' || $kind === 'reblog' || $kind === 'announce');
    $id = (string) ($n['id'] ?? '');
    $hrefRaw = $isSite ? (string) ($n['site_url'] ?? $id) : $id;
    $href = $hrefRaw !== '' ? htmlspecialchars($hrefRaw, ENT_QUOTES, 'UTF-8') : '#';
    $create = json_decode((string) ($n['raw_create_json'] ?? ''), true);
    $obj = (is_array($create) && is_array($create['object'] ?? null)) ? $create['object'] : [];
    $cw = '';
    if (!empty($obj['summary']) && is_string($obj['summary'])) {
        $cw = trim($obj['summary']);
    }

    // Boost card: plain-text only inside outer <a class="post"> (no nested anchors)
    if ($isBoost) {
        $objectId = rtrim((string) ($n['object_id'] ?? $id), '/');
        $targetActor = rtrim((string) ($n['target_actor'] ?? ''), '/');
        $snippet = $objectId !== '' ? ap_cmdr_object_snippet($objectId, 180) : '';
        $who = '';
        if ($targetActor !== '' && str_starts_with($targetActor, 'https://')) {
            if (function_exists('ap_cmdr_actor_handle_label')) {
                $who = ap_cmdr_actor_handle_label($targetActor, null);
            } else {
                $who = ap_cmdr_short_url_label($targetActor);
            }
        }
        $boostLine = '<div class="boost-line">↻ boosted'
            . ($who !== '' ? ' ' . htmlspecialchars($who, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '')
            . '</div>';
        $body = '';
        if ($snippet !== '') {
            $body = '<div class="body">' . htmlspecialchars($snippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        } elseif ($objectId !== '') {
            $body = '<div class="body muted">'
                . htmlspecialchars(ap_cmdr_short_url_label($objectId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</div>';
        } else {
            $body = '<div class="body muted">(boost)</div>';
        }
        $published = (string) ($n['published'] ?? '');
        $dateLabel = $published;
        try {
            $dateLabel = (new DateTimeImmutable($published))->format('M j, Y · g:i A');
        } catch (Throwable $e) {
            // keep
        }
        return '<a class="post" href="' . $href . '">'
            . $boostLine
            . $body
            . '<div class="meta">' . htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8')
            . ' · <span class="badge">boost</span></div></a>';
    }

    // No <a> in list bodies: the card is already wrapped in <a class="post">.
    // Nested anchors make browsers close the outer link early and scramble later cards.
    $content = strip_tags((string) ($n['content'] ?? ''), '<p><br><strong><em><code>');
    if ($content !== '' && function_exists('ap_normalize_status_html')) {
        $content = ap_normalize_status_html($content);
    }
    // Truncate very long posts for the list
    $plain = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($cw !== '') {
        $content = '<p class="cw" style="margin:0"><strong>CW</strong> · '
            . htmlspecialchars($cw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</p>';
    } elseif ($plain === '' || $plain === '(poll)' || $plain === '(media)' || $plain === '(quote)') {
        $content = '';
    } elseif (mb_strlen($plain) > 280) {
        $content = '<p>' . htmlspecialchars(mb_substr($plain, 0, 277) . '…', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }

    // Quote target (FEP-044f / Misskey) — quote posts / quote-boosts
    $quoteUrl = '';
    if (!empty($obj['quote']) && is_string($obj['quote']) && str_starts_with($obj['quote'], 'https://')) {
        $quoteUrl = rtrim($obj['quote'], '/');
    } elseif (!empty($obj['quoteUrl']) && is_string($obj['quoteUrl'])) {
        $quoteUrl = rtrim($obj['quoteUrl'], '/');
    } elseif (!empty($obj['_misskey_quote']) && is_string($obj['_misskey_quote'])) {
        $quoteUrl = rtrim($obj['_misskey_quote'], '/');
    }
    $quoteHtml = '';
    if ($quoteUrl !== '') {
        $qSnippet = ap_cmdr_object_snippet($quoteUrl, 160);
        // No nested <a> inside the outer .post link — browsers break the card layout
        $quoteHtml = '<div class="quote-block"><span class="qt-label">Quoted</span>';
        if ($qSnippet !== '') {
            $quoteHtml .= htmlspecialchars($qSnippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } else {
            $quoteHtml .= '<span class="qt-url">'
                . htmlspecialchars(ap_cmdr_short_url_label($quoteUrl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</span>';
        }
        $quoteHtml .= '</div>';
    }

    // Reply parent
    $replyTo = rtrim((string) ($n['in_reply_to'] ?? ''), '/');
    if ($replyTo === '' && !empty($obj['inReplyTo']) && is_string($obj['inReplyTo'])) {
        $replyTo = rtrim($obj['inReplyTo'], '/');
    }
    $replyHtml = '';
    if ($replyTo !== '' && str_starts_with($replyTo, 'https://')) {
        $rSnippet = ap_cmdr_object_snippet($replyTo, 100);
        $label = $rSnippet !== ''
            ? htmlspecialchars($rSnippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : htmlspecialchars(ap_cmdr_short_url_label($replyTo), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Plain text inside outer <a class="post"> — avoid nested anchors
        $replyHtml = '<div class="reply-line">↩ reply to ' . $label . '</div>';
    }

    $published = (string) ($n['published'] ?? '');
    $dateLabel = $published;
    try {
        $dateLabel = (new DateTimeImmutable($published))->format('M j, Y · g:i A');
    } catch (Throwable $e) {
        // keep
    }

    $thumb = '';
    if ($isSite && !empty($n['site_image']) && is_string($n['site_image'])) {
        $safe = htmlspecialchars($n['site_image'], ENT_QUOTES, 'UTF-8');
        $thumb = '<div class="media-row media-count-1"><img class="thumb" src="' . $safe . '" alt="" loading="lazy" referrerpolicy="no-referrer"></div>';
    } else {
        if (isset($obj['attachment'])) {
            $atts = $obj['attachment'];
            if (is_array($atts) && isset($atts['type'])) {
                $atts = [$atts];
            }
            $thumbCells = [];
            if (is_array($atts)) {
                foreach ($atts as $att) {
                    if (!is_array($att)) {
                        continue;
                    }
                    $url = is_string($att['url'] ?? null) ? $att['url'] : '';
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
                        // Inside outer <a class="post"> — plain img (no nested button)
                        $thumbCells[] = '<img class="thumb" src="' . $safe . '" alt="' . $alt . '" loading="lazy" referrerpolicy="no-referrer">';
                    } elseif ($isVideo) {
                        // Preview inside the card link — muted, metadata only (full controls on note page)
                        $thumbCells[] = '<video class="thumb" src="' . $safe . '" muted playsinline preload="metadata" referrerpolicy="no-referrer"></video>';
                    }
                    if (count($thumbCells) >= 4) {
                        break;
                    }
                }
            }
            if ($thumbCells !== []) {
                $nMedia = count($thumbCells);
                $thumb = '<div class="media-row media-count-' . $nMedia . '">' . implode('', $thumbCells) . '</div>';
            }
        }
    }

    $pollHtml = '';
    $choices = $obj['oneOf'] ?? $obj['anyOf'] ?? null;
    $pollVotes = null;
    $pollExpired = false;
    // Prefer live tallies from masto_polls when this is our AP note
    if ($id !== '' && str_starts_with($id, 'https://mkultra.monster/users/cmdr_nova/notes/')) {
        try {
            $pst = ap_db()->prepare('SELECT options_json, votes_count, voters_count, expires_at, multiple FROM masto_polls WHERE note_id = ? OR note_id = ?');
            $pst->execute([$id, $id . '/']);
            $prow = $pst->fetch();
            if (is_array($prow)) {
                $decoded = json_decode((string) ($prow['options_json'] ?? '[]'), true);
                if (is_array($decoded) && $decoded) {
                    $choices = $decoded;
                    $pollVotes = [
                        'votes' => (int) ($prow['votes_count'] ?? 0),
                        'voters' => (int) ($prow['voters_count'] ?? 0),
                    ];
                }
                try {
                    if (!empty($prow['expires_at'])) {
                        $pollExpired = (new DateTimeImmutable((string) $prow['expires_at'])) <= new DateTimeImmutable('now');
                    }
                } catch (Throwable $e) {
                    $pollExpired = false;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    if (is_array($choices) && $choices) {
        $pollHtml = '<div class="poll-block"><span class="poll-label">Poll'
            . ($pollExpired ? ' · closed' : '') . '</span>';
        foreach ($choices as $ch) {
            if (!is_array($ch)) {
                continue;
            }
            $title = trim((string) ($ch['name'] ?? $ch['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $vc = isset($ch['votes_count']) ? (int) $ch['votes_count'] : null;
            $label = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($vc !== null) {
                $label .= ' <span class="muted">(' . $vc . ')</span>';
            }
            $pollHtml .= '<span class="poll-opt">' . $label . '</span>';
        }
        if (is_array($pollVotes)) {
            $pollHtml .= '<div class="poll-meta">'
                . (int) $pollVotes['votes'] . ' vote' . ((int) $pollVotes['votes'] === 1 ? '' : 's')
                . ' · ' . (int) $pollVotes['voters'] . ' voter' . ((int) $pollVotes['voters'] === 1 ? '' : 's')
                . '</div>';
        }
        $pollHtml .= '</div>';
    }

    $badge = '';
    $isPinned = !empty($n['_pinned']);
    if ($kind === 'site_blog') {
        $badge .= ' · blog';
    } elseif ($kind === 'site_note') {
        $badge .= ' · note';
    } elseif ($kind === 'blog') {
        $badge .= ' · blog share';
    } elseif ($kind === 'note') {
        $badge .= ' · note share';
    } elseif ($kind === 'quote' || $quoteUrl !== '') {
        $badge .= ' · <span class="badge">quote</span>';
    } elseif ($kind === 'poll' || $pollHtml !== '') {
        $badge .= ' · <span class="badge">poll</span>';
    }
    if ($replyTo !== '' && $quoteUrl === '') {
        $badge .= ' · <span class="badge">reply</span>';
    }

    // Link preview (non-interactive — outer wrapper is <a class="post">)
    $linkCardHtml = '';
    if ($thumb === '' && function_exists('ap_link_preview_extract_url') && function_exists('ap_link_preview_for_url')) {
        $cardSource = $plain !== '' ? $plain : (string) ($n['content'] ?? '');
        $cardUrl = ap_link_preview_extract_url($cardSource);
        if ($cardUrl !== null) {
            $linkCardHtml = ap_link_preview_html(ap_link_preview_for_url($cardUrl, true), false);
        }
    }

    // Tiny thumbtack — absolute on the card edge (out of flow, no text reflow)
    $pinIcon = $isPinned
        ? '<span class="post-pin" title="Pinned" aria-hidden="true">📌</span>'
        : '';

    $html = '<a class="post' . ($isPinned ? ' is-pinned' : '') . '" href="' . $href . '"'
        . ($isPinned ? ' aria-label="Pinned post"' : '') . '>';
    $html .= $pinIcon;
    $html .= $replyHtml;
    if ($content !== '') {
        $html .= '<div class="body">' . $content . '</div>';
    } elseif ($quoteHtml === '' && $pollHtml === '' && $thumb === '' && $linkCardHtml === '') {
        $html .= '<div class="body muted">(no text)</div>';
    }
    $html .= $quoteHtml;
    $html .= $pollHtml;
    $html .= $thumb;
    $html .= $linkCardHtml;
    $html .= '<div class="meta">' . htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') . $badge;
    $html .= '</div></a>';
    return $html;
}

function ap_cmdr_collection_html(string $col): void
{
    $isFollowers = $col === 'followers';
    $title = $isFollowers ? 'Followers' : 'Following';
    $rows = $isFollowers ? ap_followers_list(CMDR_ACTOR_ID) : ap_following_list(CMDR_ACTOR_ID);

    ap_cmdr_shell_start($title . ' · @cmdr_nova@mkultra.monster');
    echo '<p class="muted"><a href="/users/cmdr_nova">@cmdr_nova@mkultra.monster</a></p>';
    echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' <span class="muted">(' . count($rows) . ')</span></h1>';

    if (!$rows) {
        echo '<p class="muted" style="margin-top:1rem">Nobody here yet.</p>';
    } else {
        echo '<ul class="list">';
        foreach ($rows as $r) {
            $actorId = (string) ($r['actor_id'] ?? '');
            if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
                continue;
            }
            $username = isset($r['username']) ? (string) $r['username'] : null;
            $label = ap_cmdr_actor_handle_label($actorId, $username !== '' ? $username : null);
            $when = (string) ($r['followed_at'] ?? $r['last_seen'] ?? '');
            echo '<li>';
            echo '<div class="who"><a href="' . htmlspecialchars($actorId, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer nofollow">'
                . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></div>';
            echo '<div class="mono">' . htmlspecialchars($actorId, ENT_QUOTES, 'UTF-8') . '</div>';
            if ($when !== '') {
                echo '<div class="muted" style="font-size:.8rem;margin-top:.2rem">' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    echo '<p class="back"><a href="/users/cmdr_nova">← Profile</a> · <a href="https://mkultra.monster/">Site</a></p>';
    ap_cmdr_shell_end();
}

/**
 * Public HTML page for a local Collection (starter pack).
 *
 * @param array<string,mixed> $coll
 * @param list<array<string,mixed>> $members
 */
function ap_cmdr_collection_pack_html(array $coll, array $members): void
{
    $name = (string) ($coll['name'] ?? 'Collection');
    $desc = trim((string) ($coll['description'] ?? ''));
    $tag = trim((string) ($coll['tag_name'] ?? ''));
    ap_cmdr_shell_start($name . ' · Collection · @cmdr_nova');
    echo '<p class="muted"><a href="/users/cmdr_nova">@cmdr_nova@mkultra.monster</a> · Collection</p>';
    echo '<h1>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>';
    if ($desc !== '') {
        echo '<p style="margin:.75rem 0 1rem;line-height:1.45">' . htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    if ($tag !== '') {
        echo '<p class="muted">#' . htmlspecialchars(ltrim($tag, '#'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ' · ' . count($members) . ' accounts</p>';
    } else {
        echo '<p class="muted">' . count($members) . ' accounts</p>';
    }
    echo '<p class="muted" style="font-size:.85rem;margin:0 0 1rem">Curated follow pack. Open each profile to follow from your instance, or use Library → Collections → Follow all in Nova Admin.</p>';

    if (!$members) {
        echo '<p class="muted" style="margin-top:1rem">No members yet.</p>';
    } else {
        echo '<ul class="list">';
        foreach ($members as $mem) {
            $actorId = (string) ($mem['actor_id'] ?? '');
            if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
                continue;
            }
            $label = ap_cmdr_actor_handle_label($actorId, null);
            echo '<li>';
            echo '<div class="who"><a href="' . htmlspecialchars($actorId, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer nofollow">'
                . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></div>';
            echo '<div class="mono">' . htmlspecialchars($actorId, ENT_QUOTES, 'UTF-8') . '</div>';
            echo '</li>';
        }
        echo '</ul>';
    }

    echo '<p class="back"><a href="/users/cmdr_nova">← Profile</a> · <a href="https://mkultra.monster/">Site</a></p>';
    ap_cmdr_shell_end();
}
