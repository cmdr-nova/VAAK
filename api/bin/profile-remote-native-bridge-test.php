<?php
declare(strict_types=1);
require_once __DIR__ . '/../ap-profile-local-native.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

foreach (['http://127.0.0.1:8787/internal/profile/remote', 'http://[::1]:8787/internal/profile/remote'] as $url) {
    check(ap_profile_native_remote_url_allowed($url), 'literal loopback remote route accepted');
}
foreach ([
    'https://127.0.0.1/internal/profile/remote',
    'http://localhost/internal/profile/remote',
    'http://127.0.0.1.evil.test/internal/profile/remote',
    'http://user@127.0.0.1/internal/profile/remote',
    'http://127.0.0.1/internal/profile/remote?q=1',
    'http://127.0.0.1/internal/profile/local',
    'http://127.0.0.1/internal/profile/page',
    'http://127.0.0.1/internal/profile/full',
    'http://192.168.1.2/internal/profile/remote',
    'http://127.0.0.1/internal/profile/remote#x',
] as $url) {
    check(!ap_profile_native_remote_url_allowed($url), 'unsafe remote bridge URL rejected: ' . $url);
}
check(ap_profile_native_url_allowed('http://127.0.0.1:8787/internal/profile/local'), 'local paint route unchanged');
check(!ap_profile_native_url_allowed('http://127.0.0.1:8787/internal/profile/remote'), 'local allowlist does not accept the remote path');

putenv('VAAK_PROFILE_NATIVE_RENDER');
putenv('VAAK_PROFILE_NATIVE_SECRET');
putenv('VAAK_PROFILE_NATIVE_URL');
check(ap_profile_native_remote_url() === 'http://127.0.0.1:8787/internal/profile/remote', 'default remote route');
check(!ap_profile_native_enabled(), 'default disabled');
check(ap_profile_native_render_remote(['actor' => 'https://remote.example/users/ada']) === null, 'default disabled no transport');
check(ap_profile_native_render_local(['actor' => 'https://remote.example/users/ada']) === null, 'local painter still refuses a remote actor');

putenv('VAAK_PROFILE_NATIVE_RENDER=1');
putenv('VAAK_PROFILE_NATIVE_SECRET=short');
check(!ap_profile_native_enabled(), 'short secret disabled');
check(ap_profile_native_render_remote(['actor' => 'https://remote.example/users/ada']) === null, 'short secret no transport');

putenv('VAAK_PROFILE_NATIVE_SECRET=' . str_repeat('s', 32));
check(ap_profile_native_enabled(), 'long secret enables the shared switch');
putenv('VAAK_PROFILE_NATIVE_URL=http://127.0.0.1:9999/internal/profile/local');
check(ap_profile_native_remote_url() === 'http://127.0.0.1:9999/internal/profile/remote', 'local port override keeps the remote path');
check(ap_profile_native_remote_url_allowed((string) ap_profile_native_remote_url()), 'derived remote route stays on loopback');
putenv('VAAK_PROFILE_NATIVE_URL=http://[::1]:8787/internal/profile/local');
check(ap_profile_native_remote_url() === 'http://[::1]:8787/internal/profile/remote', 'ipv6 loopback override');
putenv('VAAK_PROFILE_NATIVE_URL=http://127.0.0.1/internal/profile/page');
check(ap_profile_native_remote_url() === null, 'complete-native page route is not rewritten');
check(ap_profile_native_render_remote(['actor' => 'https://remote.example/users/ada']) === null, 'page route does not paint remote chrome');
putenv('VAAK_PROFILE_NATIVE_URL=http://127.0.0.1/internal/profile/full');
check(ap_profile_native_render_remote(['actor' => 'https://remote.example/users/ada']) === null, 'full route does not paint remote chrome');
putenv('VAAK_PROFILE_NATIVE_URL=http://public.invalid/internal/profile/local');
check(ap_profile_native_render_remote(['actor' => 'https://remote.example/users/ada']) === null, 'public host rejected before transport');
putenv('VAAK_PROFILE_NATIVE_URL');
check(ap_profile_native_render_remote(['actor' => 'https://mkultra.monster/users/ada']) === null, 'local actor stays on the local painter');
check(ap_profile_native_render_remote(['actor' => 'https://bsky.app/profile/ada.example']) === null, 'bluesky actor stays on PHP');
check(ap_profile_native_render_local(['actor' => 'https://bsky.app/profile/ada.example']) === null, 'local painter still refuses bluesky');

$binding = ['actor' => 'https://remote.example/users/ada', 'tab' => 'posts', 'from' => 'search'];
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
echo "profile-remote-native-bridge: PASS (default off, shared secret, loopback remote paint route only)\n";
