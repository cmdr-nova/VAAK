<?php
/**
 * NodeInfo 2.0 / 2.1 for mkultra.monster (ActivityPub discovery).
 *
 * Routes (via Caddy):
 *   GET /.well-known/nodeinfo  → discovery JRD
 *   GET /nodeinfo/2.0         → schema 2.0 document
 *   GET /nodeinfo/2.1         → schema 2.1 document
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');
header('X-Robots-Tag: noindex, nofollow');

$uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
$uri = rtrim($uri, '/') ?: $uri;

if ($uri === '/.well-known/nodeinfo') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'links' => [
            [
                'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
                'href' => 'https://mkultra.monster/nodeinfo/2.0',
            ],
            [
                'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1',
                'href' => 'https://mkultra.monster/nodeinfo/2.1',
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$is21 = str_ends_with($uri, '/2.1');
$users = 1;
$posts = 0;
try {
    require_once __DIR__ . '/ap-db.php';
    if (function_exists('ap_db_enabled_user_count')) {
        $users = ap_db_enabled_user_count();
    } else {
        $users = max(1, (int) ap_db()->query('SELECT COUNT(*) FROM ap_users WHERE disabled_at IS NULL')->fetchColumn());
    }
    if (function_exists('ap_public_post_count')) {
        $posts = (int) ap_public_post_count();
    }
} catch (Throwable $e) {
    // still serve static node metadata
}

$doc = [
    'version' => $is21 ? '2.1' : '2.0',
    'software' => [
        'name' => 'vaak',
        'version' => '1.0.0',
    ],
    'protocols' => ['activitypub'],
    'services' => [
        'inbound' => [],
        'outbound' => [],
    ],
    'usage' => [
        'users' => [
            'total' => $users,
            'activeMonth' => $users,
            'activeHalfyear' => $users,
        ],
        'localPosts' => $posts,
    ],
    'openRegistrations' => false,
    'metadata' => [
        'nodeName' => 'NovaLandia',
        'nodeDescription' => 'Invite-only ActivityPub / Mastodon-compatible instance (NovaLandia).',
    ],
];

if ($is21) {
    $doc['software']['repository'] = 'https://mkultra.monster/';
    $doc['software']['homepage'] = 'https://mkultra.monster/';
}

header('Content-Type: application/json; profile="http://nodeinfo.diaspora.software/ns/schema/'
    . ($is21 ? '2.1' : '2.0') . '#"; charset=utf-8');
echo json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
