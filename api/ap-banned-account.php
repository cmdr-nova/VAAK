<?php
/**
 * Public face of a banned (disabled) local VAAK account. Used by ap-user.php
 * (/users/{key}, /@{key} and subpaths) and the /vaak/users/{key} profile boot.
 */
declare(strict_types=1);

if (!function_exists('ap_actor_key_paths') && is_file(__DIR__ . '/ap-auth.php')) {
    require_once __DIR__ . '/ap-auth.php';
}

function ap_banned_account_json(array $doc, string $accept): void
{
    header(str_contains($accept, 'application/activity+json')
        ? 'Content-Type: application/activity+json; charset=utf-8'
        : 'Content-Type: application/ld+json; profile="https://www.w3.org/ns/activitystreams"; charset=utf-8');
    header('Cache-Control: no-cache, max-age=0, must-revalidate');
    header('Vary: Accept');
    echo json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Public face of a banned local account. ActivityPub gets a bare actor marked
 * "suspended" (Mastodon's flag) so remote servers show it as suspended; it
 * keeps the public key so signatures on earlier activities still verify.
 * Collections are empty, everything else is 410 Gone, browsers get a page
 * that only says the account is banned.
 *
 * @param array<string,mixed> $user
 */
function ap_user_serve_banned(array $user, string $sub): void
{
    $actorKey = (string) ($user['actor_key'] ?? $user['username'] ?? '');
    $actorId = rtrim((string) ($user['actor_id'] ?? ''), '/');
    if ($actorId === '') {
        $actorId = 'https://mkultra.monster/users/' . rawurlencode($actorKey);
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $wantsAp = function_exists('ap_user_wants_activitypub')
        ? ap_user_wants_activitypub($accept)
        : (str_contains($accept, 'application/activity+json') || str_contains($accept, 'application/ld+json'));
    header('X-Robots-Tag: noindex, nofollow');
    if ($method !== 'GET' && $method !== 'HEAD') {
        // Inbox deliveries and other writes: the account no longer exists here.
        http_response_code(410);
        header('Content-Type: application/json');
        echo '{"error":"Account banned"}';
        return;
    }
    $notice = 'This account has been banned from mkultra.monster.';
    if ($sub === '' && $wantsAp) {
        $paths = ap_actor_key_paths($actorKey);
        $pem = is_file($paths['public']) ? (string) file_get_contents($paths['public']) : '';
        $doc = [
            '@context' => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
            'id' => $actorId,
            'type' => 'Person',
            'preferredUsername' => $actorKey,
            'name' => $actorKey,
            'summary' => '<p>' . $notice . '</p>',
            'url' => $actorId,
            'inbox' => $actorId . '/inbox',
            'outbox' => $actorId . '/outbox',
            'followers' => $actorId . '/followers',
            'following' => $actorId . '/following',
            'manuallyApprovesFollowers' => true,
            'discoverable' => false,
            'indexable' => false,
            'suspended' => true,
            'attachment' => [],
            'tag' => [],
        ];
        if ($pem !== '') {
            $doc['publicKey'] = ['id' => $actorId . '#main-key', 'owner' => $actorId, 'publicKeyPem' => $pem];
        }
        ap_banned_account_json($doc, $accept);
        return;
    }
    if ($wantsAp && in_array($sub, ['outbox', 'followers', 'following', 'collections/featured', 'featured'], true)) {
        $col = $sub === 'featured' ? 'collections/featured' : $sub;
        ap_banned_account_json([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actorId . '/' . $col,
            'type' => 'OrderedCollection',
            'totalItems' => 0,
            'orderedItems' => [],
        ], $accept);
        return;
    }
    http_response_code(410);
    if ($wantsAp) {
        header('Content-Type: application/activity+json; charset=utf-8');
        echo json_encode(['error' => 'Gone', 'reason' => $notice], JSON_UNESCAPED_SLASHES);
        return;
    }
    if (function_exists('ap_csp_send_headers')) {
        ap_csp_send_headers('app');
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, max-age=0');
    $handle = htmlspecialchars('@' . $actorKey . '@mkultra.monster', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>Account banned</title>'
        . '<style>body{margin:0;font-family:system-ui,sans-serif;background:#0a0a0a;color:#ddd}main{max-width:36rem;margin:4rem auto;padding:0 1.25rem}'
        . '.card{border:1px solid #333;border-radius:12px;padding:1.5rem;background:#141414}h1{margin:0 0 .5rem;font-size:1.25rem}p{margin:0;color:#aaa}</style>'
        . '</head><body><main><div class="card"><h1>' . $handle . '</h1><p>' . htmlspecialchars($notice, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p></div></main></body></html>';
}
