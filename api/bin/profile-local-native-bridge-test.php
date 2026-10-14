<?php
declare(strict_types=1);
require_once __DIR__ . '/../ap-profile-local-native.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

foreach (['http://127.0.0.1:8787/internal/profile/local', 'http://[::1]:8787/internal/profile/local'] as $url) {
    check(ap_profile_native_url_allowed($url), 'literal loopback accepted');
}
foreach ([
    'https://127.0.0.1/internal/profile/local',
    'http://localhost/internal/profile/local',
    'http://127.0.0.1.evil.test/internal/profile/local',
    'http://user@127.0.0.1/internal/profile/local',
    'http://127.0.0.1/internal/profile/local?q=1',
    'http://127.0.0.1/internal/profile/page',
    'http://127.0.0.1/internal/profile/full',
    'http://192.168.1.2/internal/profile/local',
    'http://127.0.0.1/internal/profile/local#x',
] as $url) {
    check(!ap_profile_native_url_allowed($url), 'unsafe bridge URL rejected: ' . $url);
}

putenv('VAAK_PROFILE_NATIVE_RENDER');
putenv('VAAK_PROFILE_NATIVE_SECRET');
putenv('VAAK_PROFILE_NATIVE_URL');
check(!ap_profile_native_enabled(), 'default disabled');
check(ap_profile_native_render_local(['actor' => 'https://mkultra.monster/users/ada']) === null, 'default disabled no transport');

putenv('VAAK_PROFILE_NATIVE_RENDER=1');
putenv('VAAK_PROFILE_NATIVE_SECRET=short');
check(!ap_profile_native_enabled(), 'short secret disabled');
check(ap_profile_native_render_local(['actor' => 'https://mkultra.monster/users/ada']) === null, 'short secret no transport');

putenv('VAAK_PROFILE_NATIVE_SECRET=' . str_repeat('s', 32));
check(ap_profile_native_enabled(), 'long secret enables the switch');
putenv('VAAK_PROFILE_NATIVE_URL=http://127.0.0.1/internal/profile/page');
check(ap_profile_native_render_local(['actor' => 'https://mkultra.monster/users/ada']) === null, 'complete-native page route is not this paint bridge');
putenv('VAAK_PROFILE_NATIVE_URL=http://127.0.0.1/internal/profile/full');
check(ap_profile_native_render_local(['actor' => 'https://mkultra.monster/users/ada']) === null, 'complete-native full route is not this paint bridge');
putenv('VAAK_PROFILE_NATIVE_URL=http://public.invalid/internal/profile/local');
check(ap_profile_native_render_local(['actor' => 'https://mkultra.monster/users/ada']) === null, 'public host rejected before transport');
putenv('VAAK_PROFILE_NATIVE_URL');
check(ap_profile_native_render_local(['actor' => 'https://example.test/users/ada']) === null, 'remote actor stays on PHP');
check(ap_profile_native_render_local(['actor' => 'https://bsky.app/profile/ada.example']) === null, 'bluesky actor stays on PHP');

$binding = ['actor' => 'https://mkultra.monster/users/ada', 'tab' => 'posts', 'from' => 'search'];
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
    'request_binding' => $binding,
    'chrome' => $chrome,
    'page_back' => 'page-back-bytes',
    'header' => 'other-bytes',
    'secondary' => null,
], $binding) === null, 'page_back and header must concatenate to chrome');
check(ap_profile_native_validate_local([
    'ok' => true,
    'protocol_version' => 1,
    'request_binding' => ['from' => 'search', 'actor' => $binding['actor'], 'tab' => 'posts'],
    'chrome' => $chrome,
    'page_back' => 'page-back-bytes',
    'header' => 'header-bytes',
], $binding) === null, 'binding key order is part of the reply');
check(ap_profile_native_validate_local([
    'ok' => true,
    'protocol_version' => 1,
    'request_binding' => $binding,
    'chrome' => $chrome,
    'page_back' => 'page-back-bytes',
    'header' => 'header-bytes',
    'secondary' => '',
], $binding) === null, 'empty secondary rejected');
$empty = str_repeat(' ', 36) . '<div class="empty">No answered Asks yet.</div>' . "\n            ";
$withSecondary = ap_profile_native_validate_local([
    'ok' => true,
    'protocol_version' => 1,
    'request_binding' => $binding,
    'chrome' => $chrome,
    'page_back' => 'page-back-bytes',
    'header' => 'header-bytes',
    'secondary' => $empty,
], $binding);
check(is_array($withSecondary) && $withSecondary['secondary'] === $empty, 'empty ask body accepted');
$withFeed = ap_profile_native_validate_local([
    'ok' => true,
    'protocol_version' => 1,
    'request_binding' => $binding,
    'chrome' => $chrome,
    'page_back' => 'page-back-bytes',
    'header' => 'header-bytes',
    'secondary' => null,
    'feed_open' => '<div id="timeline-items">',
    'feed_close' => '</div>',
], $binding);
check(is_array($withFeed) && ($withFeed['feed_open'] ?? '') === '<div id="timeline-items">' && ($withFeed['feed_close'] ?? '') === '</div>', 'pagination shell accepted');
check(ap_profile_native_validate_local([
    'ok' => true,
    'protocol_version' => 1,
    'request_binding' => $binding,
    'chrome' => $chrome,
    'page_back' => 'page-back-bytes',
    'header' => 'header-bytes',
    'feed_open' => '<div id="timeline-items">',
], $binding) === null, 'pagination shell is all or nothing');
check(ap_profile_native_validate_local([
    'ok' => true,
    'protocol_version' => 1,
    'request_binding' => $binding,
    'chrome' => $chrome,
    'page_back' => 'page-back-bytes',
    'header' => 'header-bytes',
    'feed_open' => '',
    'feed_close' => '</div>',
], $binding) === null, 'empty pagination shell rejected');
$guest = ['actor' => 'https://mkultra.monster/users/ada', 'guest' => true, 'tab' => 'posts', 'from' => 'search'];
putenv('VAAK_PROFILE_NATIVE_URL=http://public.invalid/internal/profile/local');
check(ap_profile_native_render_local($guest) === null, 'guest local paint stays off the public network');
check(ap_profile_native_render_remote(['actor' => 'https://remote.example/users/ada', 'guest' => true]) === null, 'guest remote paint stays off the public network');
check(ap_profile_native_render_bsky(['actor' => 'https://bsky.app/profile/ada.example', 'guest' => true]) === null, 'guest bluesky paint stays off the public network');
putenv('VAAK_PROFILE_NATIVE_URL=http://127.0.0.1/internal/profile/page');
check(ap_profile_native_render_local($guest) === null, 'guest paint does not use the complete-native page route');
putenv('VAAK_PROFILE_NATIVE_URL=http://127.0.0.1/internal/profile/full');
check(ap_profile_native_render_local($guest) === null, 'guest paint does not use the complete-native full route');

putenv('VAAK_PROFILE_NATIVE_RENDER');
putenv('VAAK_PROFILE_NATIVE_SECRET');
putenv('VAAK_PROFILE_NATIVE_URL');
check(!ap_profile_native_enabled(), 'switch returns to off');
echo "profile-local-native-bridge: PASS (default off, token minimum, loopback paint route only)\n";
