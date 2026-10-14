<?php
declare(strict_types=1);
require_once __DIR__ . '/../ap-profile-local-native.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

foreach (['http://127.0.0.1:8787/internal/profile/bsky', 'http://[::1]:8787/internal/profile/bsky'] as $url) {
    check(ap_profile_native_bsky_url_allowed($url), 'literal loopback bsky route accepted');
}
foreach ([
    'https://127.0.0.1/internal/profile/bsky',
    'http://localhost/internal/profile/bsky',
    'http://127.0.0.1.evil.test/internal/profile/bsky',
    'http://user@127.0.0.1/internal/profile/bsky',
    'http://127.0.0.1/internal/profile/bsky?q=1',
    'http://127.0.0.1/internal/profile/local',
    'http://127.0.0.1/internal/profile/remote',
    'http://127.0.0.1/internal/profile/page',
    'http://127.0.0.1/internal/profile/full',
    'http://192.168.1.2/internal/profile/bsky',
    'http://127.0.0.1/internal/profile/bsky#x',
] as $url) {
    check(!ap_profile_native_bsky_url_allowed($url), 'unsafe bsky bridge URL rejected: ' . $url);
}
check(ap_profile_native_url_allowed('http://127.0.0.1:8787/internal/profile/local'), 'local paint route unchanged');
check(!ap_profile_native_url_allowed('http://127.0.0.1:8787/internal/profile/bsky'), 'local allowlist does not accept the bsky path');
check(ap_profile_native_remote_url_allowed('http://127.0.0.1:8787/internal/profile/remote'), 'remote paint route unchanged');
check(!ap_profile_native_remote_url_allowed('http://127.0.0.1:8787/internal/profile/bsky'), 'remote allowlist does not accept the bsky path');

foreach ([
    'https://bsky.app/profile/ada.example',
    'did:plc:aaaaaaaaaaaaaaaaaaaaaa',
    'https://bsky.brid.gy/ap/did:plc:aaaaaaaaaaaaaaaaaaaaaa',
    'at://did:plc:aaaaaaaaaaaaaaaaaaaaaa',
    'https://bsky.brid.gy/ap/did:plc:aaaaaaaaaaaaaaaaaaaaaa/',
] as $actor) {
    check(ap_profile_native_is_bsky_actor($actor), 'bsky actor accepted: ' . $actor);
}
foreach ([
    'https://remote.example/users/ada',
    'https://mkultra.monster/users/ada',
    'https://bsky.brid.gy/ap/user',
    'https://example.test/did:plc:aaaaaaaaaaaaaaaaaaaaaa',
    '',
] as $actor) {
    check(!ap_profile_native_is_bsky_actor($actor), 'non-bsky actor rejected: ' . $actor);
}

putenv('VAAK_PROFILE_NATIVE_RENDER');
putenv('VAAK_PROFILE_NATIVE_SECRET');
putenv('VAAK_PROFILE_NATIVE_URL');
check(ap_profile_native_bsky_url() === 'http://127.0.0.1:8787/internal/profile/bsky', 'default bsky route');
check(!ap_profile_native_enabled(), 'default disabled');
check(ap_profile_native_render_bsky(['actor' => 'https://bsky.app/profile/ada.example']) === null, 'default disabled no transport');
check(ap_profile_native_render_local(['actor' => 'https://bsky.app/profile/ada.example']) === null, 'local painter still refuses a bluesky actor');
check(ap_profile_native_render_remote(['actor' => 'https://bsky.app/profile/ada.example']) === null, 'remote painter still refuses a bluesky actor');

putenv('VAAK_PROFILE_NATIVE_RENDER=1');
putenv('VAAK_PROFILE_NATIVE_SECRET=short');
check(!ap_profile_native_enabled(), 'short secret disabled');
check(ap_profile_native_render_bsky(['actor' => 'https://bsky.app/profile/ada.example']) === null, 'short secret no transport');

putenv('VAAK_PROFILE_NATIVE_SECRET=' . str_repeat('s', 32));
check(ap_profile_native_enabled(), 'long secret enables the shared switch');
putenv('VAAK_PROFILE_NATIVE_URL=http://127.0.0.1:9999/internal/profile/local');
check(ap_profile_native_bsky_url() === 'http://127.0.0.1:9999/internal/profile/bsky', 'local port override keeps the bsky path');
check(ap_profile_native_bsky_url_allowed((string) ap_profile_native_bsky_url()), 'derived bsky route stays on loopback');
putenv('VAAK_PROFILE_NATIVE_URL=http://[::1]:8787/internal/profile/local');
check(ap_profile_native_bsky_url() === 'http://[::1]:8787/internal/profile/bsky', 'ipv6 loopback override');
putenv('VAAK_PROFILE_NATIVE_URL=http://127.0.0.1/internal/profile/page');
check(ap_profile_native_bsky_url() === null, 'complete-native page route is not rewritten');
check(ap_profile_native_render_bsky(['actor' => 'https://bsky.app/profile/ada.example']) === null, 'page route does not paint bsky chrome');
putenv('VAAK_PROFILE_NATIVE_URL=http://127.0.0.1/internal/profile/full');
check(ap_profile_native_render_bsky(['actor' => 'did:plc:aaaaaaaaaaaaaaaaaaaaaa']) === null, 'full route does not paint bsky chrome');
putenv('VAAK_PROFILE_NATIVE_URL=http://public.invalid/internal/profile/local');
check(ap_profile_native_render_bsky(['actor' => 'https://bsky.brid.gy/ap/did:plc:aaaaaaaaaaaaaaaaaaaaaa']) === null, 'public host rejected before transport');
putenv('VAAK_PROFILE_NATIVE_URL');
check(ap_profile_native_render_bsky(['actor' => 'https://mkultra.monster/users/ada']) === null, 'local actor stays on the local painter');
check(ap_profile_native_render_bsky(['actor' => 'https://remote.example/users/ada']) === null, 'activitypub actor stays on the remote painter');
check(ap_profile_native_render_remote(['actor' => 'https://bsky.app/profile/ada.example']) === null, 'remote painter still refuses bluesky when the switch is on');
check(ap_profile_native_render_local(['actor' => 'did:plc:aaaaaaaaaaaaaaaaaaaaaa']) === null, 'local painter still refuses a did');

$binding = ['actor' => 'https://bsky.app/profile/ada.example', 'tab' => 'posts', 'from' => 'search'];
$chrome = 'page-back-bytes' . 'header-bytes';
$accepted = ap_profile_native_validate_local([
    'ok' => true,
    'protocol_version' => 1,
    'request_binding' => $binding,
    'chrome' => $chrome,
    'page_back' => 'page-back-bytes',
    'header' => 'header-bytes',
    'secondary' => null,
], $binding);
check($accepted === [
    'chrome' => $chrome,
    'page_back' => 'page-back-bytes',
    'header' => 'header-bytes',
    'secondary' => null,
], 'split chrome accepted');
check(ap_profile_native_validate_local([
    'ok' => true,
    'protocol_version' => 1,
    'request_binding' => ['from' => 'search', 'actor' => $binding['actor'], 'tab' => 'posts'],
    'chrome' => $chrome,
    'page_back' => 'page-back-bytes',
    'header' => 'header-bytes',
], $binding) === null, 'binding key order is part of the reply');

putenv('VAAK_PROFILE_NATIVE_RENDER');
putenv('VAAK_PROFILE_NATIVE_SECRET');
putenv('VAAK_PROFILE_NATIVE_URL');
check(!ap_profile_native_enabled(), 'switch returns to off');
echo "profile-bsky-native-bridge: PASS (default off, shared secret, loopback bsky paint route only)\n";
