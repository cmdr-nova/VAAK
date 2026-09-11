<?php
declare(strict_types=1);

/**
 * Mastodon/Pleroma-compatible /authorize_interaction?uri=…
 *
 * Other instances' "Follow Remotely" forms redirect here after the visitor
 * enters user@mkultra.monster. Land logged-in users on the matching VAAK
 * remote profile (with a follow intent), or send anonymous visitors to login.
 */

require_once __DIR__ . '/ap-auth.php';
require_once __DIR__ . '/ap-db.php';

$uri = trim((string) ($_GET['uri'] ?? $_GET['url'] ?? ''));
if ($uri === '' && isset($_SERVER['QUERY_STRING'])) {
    // Some clients pass a bare query without uri=
    $qs = (string) $_SERVER['QUERY_STRING'];
    if (str_starts_with($qs, 'http://') || str_starts_with($qs, 'https://')) {
        $uri = $qs;
    }
}

$vaakBase = 'https://mkultra.monster/vaak/';

if ($uri === '' || (!str_starts_with($uri, 'https://') && !str_starts_with($uri, 'http://'))) {
    header('Location: ' . $vaakBase . '?view=search', true, 302);
    exit;
}

// Normalize http → https for AP object lookups
if (str_starts_with($uri, 'http://')) {
    $uri = 'https://' . substr($uri, 7);
}
$uri = rtrim($uri, '/');

// Prefer ActivityPub actor IRI when the uri is a web profile (/@user).
$actor = $uri;
if (preg_match('#^(https://[^/]+)/@([^/?#]+)/?$#', $uri, $m)) {
    $cand = $m[1] . '/users/' . rawurlencode(rawurldecode($m[2]));
    if (function_exists('ap_remote_actor_get') && ap_remote_actor_get($cand)) {
        $actor = $cand;
    } elseif (function_exists('ap_resolve_actor_ref')) {
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
        $wf = ap_resolve_actor_ref('@' . rawurldecode($m[2]) . '@' . parse_url($m[1], PHP_URL_HOST));
        if (is_string($wf) && $wf !== '') {
            $actor = $wf;
        } else {
            $actor = $cand;
        }
    } else {
        $actor = $cand;
    }
}

$user = ap_auth_current_user();
if ($user === null) {
    // Login only accepts a view name for ?next= — stash the actor for post-login.
    ap_auth_start_session();
    $_SESSION['vaak_authorize_uri'] = $actor;
    header('Location: ' . $vaakBase . '?mode=login&next=remote_profile', true, 302);
    exit;
}

$target = $vaakBase . '?view=remote_profile&actor=' . rawurlencode($actor) . '&intent=follow';
header('Location: ' . $target, true, 302);
exit;
