<?php
declare(strict_types=1);
/**
 * Local profiles are titled "Local profile", never "Remote profile", in the
 * tab title, the mobile top bar and the column header. No database: the
 * helpers are lifted out of ap-admin.php and run against fixtures.
 */
$root = dirname(__DIR__, 2);
$src = (string) file_get_contents($root . '/api/ap-admin.php');
$checks = 0;
function title_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}
foreach (['view_title', 'admin_profile_actor_is_local', 'admin_current_view_title'] as $fn) {
    if (!preg_match('/\nfunction ' . $fn . '\(.*?\n}\n/s', $src, $m)) {
        throw new RuntimeException("missing $fn");
    }
    eval($m[0]);
}

$local = [
    'https://mkultra.monster/users/cmdr_nova',
    'https://mkultra.monster/users/luna_naloc/',
    'https://vaak.monster/users/shana_flux',
    'https://mkultra.monster/@cmdr_nova',
    'https://mkultra.monster/vaak/users/punished_nova',
    '@cmdr_nova',
    'cmdr_nova',
    '@cmdr_nova@mkultra.monster',
    '@juno_flux@vaak.monster',
];
foreach ($local as $actor) {
    title_check(admin_profile_actor_is_local($actor), "local: $actor");
    $_GET['actor'] = $actor;
    title_check(admin_current_view_title('remote_profile') === 'Local profile', "title: $actor");
}
$remote = [
    '',
    'https://mastodon.social/users/Gargron',
    'https://mastodon.social/@Gargron',
    '@Gargron@mastodon.social',
    'https://mkultra.monster.evil.example/users/cmdr_nova',
    'https://mkultra.monster/users/cmdr_nova/statuses/1',
    'https://bsky.app/profile/valerie.bsky.mkultra.monster',
    'did:plc:abc123',
];
foreach ($remote as $actor) {
    title_check(!admin_profile_actor_is_local($actor), "remote: $actor");
    $_GET['actor'] = $actor;
    title_check(admin_current_view_title('remote_profile') === 'Remote profile', "title: $actor");
}
$_GET['actor'] = 'https://mkultra.monster/users/cmdr_nova';
title_check(admin_current_view_title('home') === 'Home', 'other views keep their title');

// Every spot that shows the view name goes through the profile-aware title.
title_check(str_contains($src, "VAAK · <?= h(admin_current_view_title(\$view)) ?></title>"), 'tab title');
title_check(str_contains($src, "<span>VAAK</span> · <?= h(admin_current_view_title(\$view)) ?></div>"), 'mobile top bar');
title_check(str_contains($src, "view_title('home') : admin_current_view_title(\$view);"), 'column header');
title_check(str_contains($src, "json_encode('VAAK · ' . admin_current_view_title(\$view))"), 'soft-nav base title');

echo "profile-local-title-smoke: ok ($checks checks)\n";
