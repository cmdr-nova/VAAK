<?php
/**
 * Local actor @val3r1e@mkultra.monster — observe, Accept+follow-back, outbox.
 * Does not fetch or store remote media.
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

const VALERIE_ACTOR_ID = 'https://mkultra.monster/users/val3r1e';
const VALERIE_PUB = '/etc/mkultra/ap-inbox/valerie_public.pem';

require_once __DIR__ . '/ap-db.php';

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';
if ($path === '/@val3r1e') {
    $path = '/users/val3r1e';
}

$pem = is_file(VALERIE_PUB) ? file_get_contents(VALERIE_PUB) : false;
if (!is_string($pem) || $pem === '') {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"error":"actor key unavailable"}';
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
$wantsAp = str_contains($accept, 'application/activity+json')
    || str_contains($accept, 'application/ld+json')
    || str_contains($accept, 'application/json');

if (preg_match('#^/users/val3r1e/inbox$#', $path)) {
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

if (preg_match('#^/users/val3r1e/(outbox|followers|following)$#', $path, $m)) {
    $col = $m[1];
    if ($col === 'outbox') {
        $notes = ap_outbox_list(50);
        $items = [];
        foreach ($notes as $n) {
            $decoded = json_decode($n['raw_create_json'], true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }
        $doc = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => VALERIE_ACTOR_ID . '/outbox',
            'type' => 'OrderedCollection',
            'totalItems' => count($items),
            'orderedItems' => $items,
        ];
    } elseif ($col === 'followers') {
        // Public collection: count only — full list is admin-only on /admin/ap-metrics
        $count = (int) (ap_db()->query('SELECT COUNT(*) AS c FROM followers')->fetch()['c'] ?? 0);
        $doc = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => VALERIE_ACTOR_ID . '/followers',
            'type' => 'OrderedCollection',
            'totalItems' => $count,
            'orderedItems' => [],
        ];
    } else {
        $count = (int) (ap_db()->query('SELECT COUNT(*) AS c FROM following')->fetch()['c'] ?? 0);
        $doc = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => VALERIE_ACTOR_ID . '/following',
            'type' => 'OrderedCollection',
            'totalItems' => $count,
            'orderedItems' => [],
        ];
    }
    ap_val_json($doc, $accept);
    exit;
}

if ($path !== '/users/val3r1e') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":"Not found"}';
    exit;
}

if (!$wantsAp) {
    ap_valerie_html();
    exit;
}

$actor = [
    '@context' => [
        'https://www.w3.org/ns/activitystreams',
        'https://w3id.org/security/v1',
    ],
    'id' => VALERIE_ACTOR_ID,
    'type' => 'Person',
    'preferredUsername' => 'val3r1e',
    'name' => 'Valerie',
    'summary' => '<p>Valerie on mkultra.monster — experimental ActivityPub presence. Also <a href="https://cmplxdecay.space/@val3r1e">@valerie@cmplxdecay.space</a>.</p>',
    'url' => 'https://mkultra.monster/users/val3r1e',
    'inbox' => VALERIE_ACTOR_ID . '/inbox',
    'outbox' => VALERIE_ACTOR_ID . '/outbox',
    'followers' => VALERIE_ACTOR_ID . '/followers',
    'following' => VALERIE_ACTOR_ID . '/following',
    'endpoints' => [
        'sharedInbox' => 'https://mkultra.monster/inbox',
    ],
    'manuallyApprovesFollowers' => false,
    'discoverable' => true,
    'indexable' => true,
    'published' => '2026-08-28T00:00:00Z',
    'attachment' => [
        [
            'type' => 'PropertyValue',
            'name' => 'Also',
            'value' => '<a href="https://cmplxdecay.space/@val3r1e" rel="me">@valerie@cmplxdecay.space</a>',
        ],
        [
            'type' => 'PropertyValue',
            'name' => 'Site',
            'value' => '<a href="https://mkultra.monster/" rel="me">mkultra.monster</a>',
        ],
    ],
    'publicKey' => [
        'id' => VALERIE_ACTOR_ID . '#main-key',
        'owner' => VALERIE_ACTOR_ID,
        'publicKeyPem' => $pem,
    ],
];
ap_val_json($actor, $accept);

function ap_val_json(array $doc, string $accept): void
{
    if (str_contains($accept, 'application/activity+json')) {
        header('Content-Type: application/activity+json; charset=utf-8');
    } else {
        header('Content-Type: application/ld+json; profile="https://www.w3.org/ns/activitystreams"; charset=utf-8');
    }
    header('Cache-Control: public, max-age=120');
    echo json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function ap_valerie_html(): void
{
    header('Content-Type: text/html; charset=utf-8');
    $followers = count(ap_followers_list());
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>@val3r1e@mkultra.monster</title>';
    echo '<style>
      body{margin:0;font-family:system-ui,sans-serif;background:#0a0a0a;color:#e8e8e8;line-height:1.5}
      main{max-width:36rem;margin:3rem auto;padding:0 1.25rem}
      a{color:#7ee0ff} .card{border:1px solid #333;border-radius:12px;padding:1.25rem;background:#121212}
      .muted{color:#999;font-size:.95rem} h1{font-size:1.4rem;margin:0 0 .5rem}
    </style></head><body><main><div class="card">';
    echo '<h1>@val3r1e@mkultra.monster</h1>';
    echo '<p>Experimental ActivityPub presence for this domain. Follows are accepted with a follow-back. Interactions are observed locally (text only — no remote media is stored).</p>';
    echo '<p class="muted">Also on Mastodon: <a href="https://cmplxdecay.space/@val3r1e">@valerie@cmplxdecay.space</a></p>';
    echo '<p class="muted">Local followers recorded: ' . (int) $followers . '</p>';
    echo '<p><a href="https://mkultra.monster/">← mkultra.monster</a></p>';
    echo '</div></main></body></html>';
}
