<?php
/**
 * WebFinger for mkultra.monster.
 * Local: any active ap_users username @ mkultra.monster (+ cmdr-nova alias).
 * Everything else (including val3r1e@mkultra.monster) → Bridgy Fed.
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/ap-db.php';

$resource = (string) ($_GET['resource'] ?? '');
$resource = trim(rawurldecode($resource));
$norm = strtolower($resource);

$username = null;

// acct:user@mkultra.monster (cmdr-nova hyphen alias → cmdr_nova)
if (preg_match('/^acct:([a-z0-9_-]+)@mkultra\.monster$/i', $norm, $m)) {
    $candidate = str_replace('-', '_', $m[1]);
    if ($candidate === 'cmdr_nova' || preg_match('/^[a-z][a-z0-9_]{1,29}$/', $candidate)) {
        $username = $candidate;
    }
}

// https://mkultra.monster/users/{user} or /@{user}
if ($username === null) {
    if (preg_match('#^https://mkultra\.monster/users/([a-zA-Z][a-zA-Z0-9_]{1,29})/?$#', $resource, $m)
        || preg_match('#^https://mkultra\.monster/@([a-zA-Z][a-zA-Z0-9_]{1,29})/?$#', $resource, $m)
    ) {
        $username = strtolower($m[1]);
    }
}

$localUser = null;
if ($username !== null) {
    // cmdr_nova always local even if row missing mid-migrate
    if ($username === 'cmdr_nova') {
        $localUser = ['username' => 'cmdr_nova', 'actor_key' => 'cmdr_nova'];
        try {
            $row = ap_local_user_by_username('cmdr_nova');
            if ($row !== null) {
                $localUser = $row;
            }
        } catch (Throwable $e) {
            // keep stub
        }
    } else {
        try {
            $localUser = ap_local_user_by_username($username);
        } catch (Throwable $e) {
            $localUser = null;
        }
    }
}

if ($localUser === null) {
    $q = $resource !== '' ? ('?resource=' . rawurlencode($resource)) : '';
    header('Location: https://fed.brid.gy/.well-known/webfinger' . $q, true, 302);
    exit;
}

$actorKey = (string) ($localUser['actor_key'] ?? $username);
$actor = 'https://mkultra.monster/users/' . rawurlencode($actorKey);
$subject = 'acct:' . $actorKey . '@mkultra.monster';
$aliases = [
    $actor,
    'https://mkultra.monster/@' . rawurlencode($actorKey),
];
if ($actorKey === 'cmdr_nova') {
    $aliases[] = 'acct:cmdr-nova@mkultra.monster';
}

$jrd = [
    'subject' => $subject,
    'aliases' => $aliases,
    'links' => [
        [
            'rel' => 'http://webfinger.net/rel/profile-page',
            'type' => 'text/html',
            'href' => $actor,
        ],
        [
            'rel' => 'self',
            'type' => 'application/activity+json',
            'href' => $actor,
        ],
        [
            'rel' => 'self',
            'type' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
            'href' => $actor,
        ],
    ],
];

header('Content-Type: application/jrd+json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
echo json_encode($jrd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
