<?php
declare(strict_types=1);

/**
 * Mastodon API status lookups by id check the viewer's audience.
 * Local ids are sequential, so the lookup must not return other accounts'
 * followers-only or direct posts, and mention rows are scoped to the owner.
 */

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$api = dirname(__DIR__);
$entities = (string) file_get_contents($api . '/ap-masto-entities.php');
$routes = (string) file_get_contents($api . '/ap-mastodon.php');

if (!preg_match('/^function ap_masto_viewer_can_see_status\(.*?^}/ms', $entities, $m)) {
    fwrite(STDERR, "ap_masto_viewer_can_see_status not found\n");
    exit(1);
}
$GLOBALS['audience_calls'] = 0;
$GLOBALS['audience_answer'] = false;
function ap_db_owner_actor_id_for_user_id(int $id): string
{
    return $id === 1 ? 'https://mkultra.monster/users/cmdr_nova' : ($id === 2 ? 'https://mkultra.monster/users/juno_flux' : '');
}
function ap_visibility_status_audience_allowed(array $status, int $ownerUserId, int $depth = 0): bool
{
    $GLOBALS['audience_calls']++;
    return $GLOBALS['audience_answer'];
}
eval($m[0]);

$own = ['visibility' => 'private', 'uri' => 'https://mkultra.monster/users/cmdr_nova/notes/1', 'account' => ['uri' => 'https://mkultra.monster/users/cmdr_nova']];
$check(ap_masto_viewer_can_see_status(['visibility' => 'public'], 0), 'public is visible');
$check(ap_masto_viewer_can_see_status(['visibility' => 'unlisted'], 2), 'unlisted is visible');
$check(ap_masto_viewer_can_see_status($own, 1), 'author sees own followers-only post');
$check(ap_masto_viewer_can_see_status(['visibility' => 'direct', 'uri' => $own['uri'], 'account' => []], 1), 'author by note uri');
$calls = $GLOBALS['audience_calls'];
$check(!ap_masto_viewer_can_see_status($own, 2), 'another account needs the audience check');
$check($GLOBALS['audience_calls'] === $calls + 1, 'audience check consulted');
$GLOBALS['audience_answer'] = true;
$check(ap_masto_viewer_can_see_status($own, 2), 'audience check can allow a follower');
$check(!ap_masto_viewer_can_see_status($own, 0), 'no viewer sees nothing restricted');
$check(!ap_masto_viewer_can_see_status(null, 1), 'missing status');

$check(substr_count($routes, "'SELECT * FROM mentions WHERE id = ? AND deleted_at IS NULL'") === 0, 'route mention lookups are owner scoped');
$check(substr_count($entities, "'SELECT * FROM mentions WHERE id = ? AND deleted_at IS NULL'") === 0, 'entity mention lookups are owner scoped');
$check(substr_count($routes, 'ap_masto_viewer_can_see_status(') >= 2, 'status and history routes check the audience');
$check(substr_count($entities, 'ap_masto_viewer_can_see_status(') >= 3, 'context and interaction resolvers check the audience');

if ($failures !== []) {
    fwrite(STDERR, 'masto-status-audience-smoke: ' . count($failures) . " of {$checks} failed\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}
echo "masto-status-audience-smoke: {$checks} checks ok\n";
