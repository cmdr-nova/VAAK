<?php
/**
 * Minimal instance actor for mkultra.monster ActivityPub inbox.
 * Public key is fetchable without a signature (breaks authorized-fetch loops).
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: public, max-age=300');

$pubPath = '/etc/mkultra/ap-inbox/public.pem';
$pem = is_file($pubPath) ? file_get_contents($pubPath) : false;
if (!is_string($pem) || $pem === '') {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"error":"actor key unavailable"}';
    exit;
}

$accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
$wantsAp = str_contains($accept, 'application/activity+json')
    || str_contains($accept, 'application/ld+json')
    || str_contains($accept, 'application/json');

if (!$wantsAp && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>mkultra.monster instance actor</title>';
    echo '<style>body{margin:0;font-family:system-ui,sans-serif;background:#0a0a0a;color:#ddd}main{max-width:36rem;margin:3rem auto;padding:0 1.25rem}.card{border:1px solid #333;border-radius:12px;padding:1.25rem;background:#121212}a{color:#7ee0ff}.muted{color:#999}</style>';
    echo '</head><body><main><div class="card">';
    echo '<h1>Instance actor</h1>';
    echo '<p>This is the server Application actor used only for <strong>HTTP Signature / authorized-fetch</strong> key exchange (e.g. Threads).</p>';
    echo '<p class="muted">It is not a personal account. Local AP: <a href="https://mkultra.monster/users/cmdr_nova">@cmdr_nova@mkultra.monster</a>. Bridgy site handle stays @val3r1e@mkultra.monster. VAAK profile: <a href="https://mkultra.monster/users/cmdr_nova">@cmdr_nova@mkultra.monster</a>.</p>';
    echo '</div></main></body></html>';
    exit;
}

$actor = [
    '@context' => [
        'https://www.w3.org/ns/activitystreams',
        'https://w3id.org/security/v1',
    ],
    'id' => 'https://mkultra.monster/actor',
    'type' => 'Application',
    'preferredUsername' => 'mkultra',
    'name' => 'mkultra.monster',
    'summary' => 'Instance actor for ActivityPub inbox signature verification only.',
    'inbox' => 'https://mkultra.monster/inbox',
    'outbox' => 'https://mkultra.monster/actor/outbox',
    'url' => 'https://mkultra.monster/',
    'manuallyApprovesFollowers' => false,
    'discoverable' => false,
    'indexable' => false,
    'publicKey' => [
        'id' => 'https://mkultra.monster/actor#main-key',
        'owner' => 'https://mkultra.monster/actor',
        'publicKeyPem' => $pem,
    ],
];

if (str_contains($accept, 'application/activity+json')) {
    header('Content-Type: application/activity+json; charset=utf-8');
} else {
    header('Content-Type: application/ld+json; profile="https://www.w3.org/ns/activitystreams"; charset=utf-8');
}

echo json_encode($actor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
