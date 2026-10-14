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
    check(ap_profile_native_bsky_url_allowed($url), 'literal loopback bluesky route accepted');
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
    check(!ap_profile_native_bsky_url_allowed($url), 'unsafe bluesky bridge URL rejected: ' . $url);
}
check(ap_profile_native_bsky_actor('https://bsky.app/profile/alice.bsky.social'), 'canonical bluesky profile accepted');
check(ap_profile_native_bsky_actor('https://BSKY.app/profile/alice.bsky.social'), 'bluesky host case accepted');
check(ap_profile_native_bsky_actor('https://bsky.app/profile/did%3Aplc%3Aalice'), 'encoded did profile accepted');
check(!ap_profile_native_bsky_actor('https://bsky.app/profile/alice.bsky.social/post/abc'), 'bluesky post URL rejected');
check(!ap_profile_native_bsky_actor('https://remote.example/users/ada'), 'activitypub actor rejected');
check(!ap_profile_native_bsky_actor('https://mkultra.monster/users/ada'), 'local actor rejected');
check(!ap_profile_native_bsky_actor('https://bsky.brid.gy/ap/did:plc:alice'), 'bridgy actor rejected');
check(ap_profile_native_url_allowed('http://127.0.0.1:8787/internal/profile/local'), 'local paint route unchanged');
check(ap_profile_native_remote_url_allowed('http://127.0.0.1:8787/internal/profile/remote'), 'remote paint route unchanged');
check(!ap_profile_native_url_allowed('http://127.0.0.1:8787/internal/profile/bsky'), 'local allowlist does not accept the bluesky path');
check(!ap_profile_native_remote_url_allowed('http://127.0.0.1:8787/internal/profile/bsky'), 'remote allowlist does not accept the bluesky path');

putenv('VAAK_PROFILE_NATIVE_RENDER');
putenv('VAAK_PROFILE_NATIVE_SECRET');
putenv('VAAK_PROFILE_NATIVE_URL');
check(ap_profile_native_bsky_url() === 'http://127.0.0.1:8787/internal/profile/bsky', 'default bluesky route');
check(!ap_profile_native_enabled(), 'default disabled');
check(ap_profile_native_render_bsky(['actor' => 'https://bsky.app/profile/alice.bsky.social']) === null, 'default disabled no transport');
check(ap_profile_native_render_local(['actor' => 'https://bsky.app/profile/alice.bsky.social']) === null, 'local painter still refuses bluesky');
check(ap_profile_native_render_remote(['actor' => 'https://bsky.app/profile/alice.bsky.social']) === null, 'remote painter still refuses bluesky');
check(ap_profile_native_render_local(['actor' => 'https://mkultra.monster/users/ada']) === null, 'switch off keeps local actors on PHP');
check(ap_profile_native_render_remote(['actor' => 'https://remote.example/users/ada']) === null, 'switch off keeps remote actors on PHP');

putenv('VAAK_PROFILE_NATIVE_RENDER=1');
putenv('VAAK_PROFILE_NATIVE_SECRET=short');
check(!ap_profile_native_enabled(), 'short secret disabled');
check(ap_profile_native_render_bsky(['actor' => 'https://bsky.app/profile/alice.bsky.social']) === null, 'short secret no transport');

putenv('VAAK_PROFILE_NATIVE_SECRET=' . str_repeat('s', 32));
check(ap_profile_native_enabled(), 'long secret enables the shared switch');
putenv('VAAK_PROFILE_NATIVE_URL=http://127.0.0.1:9999/internal/profile/local');
check(ap_profile_native_bsky_url() === 'http://127.0.0.1:9999/internal/profile/bsky', 'local port override keeps the bluesky path');
check(ap_profile_native_remote_url() === 'http://127.0.0.1:9999/internal/profile/remote', 'local port override still keeps the remote path');
check(ap_profile_native_bsky_url_allowed((string) ap_profile_native_bsky_url()), 'derived bluesky route stays on loopback');
putenv('VAAK_PROFILE_NATIVE_URL=http://[::1]:8787/internal/profile/local');
check(ap_profile_native_bsky_url() === 'http://[::1]:8787/internal/profile/bsky', 'ipv6 loopback override');
putenv('VAAK_PROFILE_NATIVE_URL=http://127.0.0.1/internal/profile/page');
check(ap_profile_native_bsky_url() === null, 'complete-native page route is not rewritten');
check(ap_profile_native_render_bsky(['actor' => 'https://bsky.app/profile/alice.bsky.social']) === null, 'page route does not paint bluesky chrome');
putenv('VAAK_PROFILE_NATIVE_URL=http://127.0.0.1/internal/profile/full');
check(ap_profile_native_render_bsky(['actor' => 'https://bsky.app/profile/alice.bsky.social']) === null, 'full route does not paint bluesky chrome');
putenv('VAAK_PROFILE_NATIVE_URL=http://127.0.0.1/internal/profile/remote');
check(ap_profile_native_bsky_url() === null, 'remote path override is not rewritten into bluesky');
check(ap_profile_native_render_bsky(['actor' => 'https://bsky.app/profile/alice.bsky.social']) === null, 'remote path does not paint bluesky chrome');
putenv('VAAK_PROFILE_NATIVE_URL=http://public.invalid/internal/profile/local');
check(ap_profile_native_render_bsky(['actor' => 'https://bsky.app/profile/alice.bsky.social']) === null, 'public host rejected before transport');
putenv('VAAK_PROFILE_NATIVE_URL');
check(ap_profile_native_render_bsky(['actor' => 'https://mkultra.monster/users/ada']) === null, 'local actor stays on the local painter');
check(ap_profile_native_render_bsky(['actor' => 'https://remote.example/users/ada']) === null, 'activitypub actor stays on the remote painter');
check(ap_profile_native_render_bsky(['actor' => 'https://bsky.app/profile/alice.bsky.social/post/abc']) === null, 'bluesky post URL stays on PHP');
check(ap_profile_native_render_bsky(['actor' => 'https://bsky.brid.gy/ap/did:plc:alice']) === null, 'bridgy actor stays on PHP');
check(ap_profile_native_render_local(['actor' => 'https://bsky.app/profile/alice.bsky.social']) === null, 'local painter still refuses bluesky when the switch is on');
check(ap_profile_native_render_remote(['actor' => 'https://bsky.app/profile/alice.bsky.social']) === null, 'remote painter still refuses bluesky when the switch is on');

$binding = ['actor' => 'https://bsky.app/profile/alice.bsky.social', 'tab' => 'posts', 'from' => 'search'];
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
check(ap_profile_native_render_bsky(['actor' => 'https://bsky.app/profile/alice.bsky.social']) === null, 'switch off again does no transport');
echo "profile-bsky-native-bridge: PASS (default off, shared secret, loopback bluesky paint route only)\n";
