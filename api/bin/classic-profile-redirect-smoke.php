<?php
declare(strict_types=1);
/**
 * The deprecated classic HTML profile pages are not publicly reachable.
 * Browser requests that used to fall through to them (query variants such as
 * ?local_key=…, and the /outbox, /followers, /following HTML lists) redirect
 * to the in-app profile. ActivityPub JSON, notes, feeds and the Jekyll
 * download are unchanged. Source-level checks; no database.
 */
$api = getenv('VAAK_CLASSIC_PROFILE_API') ?: dirname(__DIR__);
$checks = 0;
function classic_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}
/** Top-level code only: drop function bodies so helper definitions don't count. */
function classic_dispatch(string $file): string
{
    $out = '';
    $depth = 0;
    $inFn = false;
    $fnDepth = 0;
    $tokens = token_get_all((string) file_get_contents($file));
    foreach ($tokens as $i => $t) {
        if (is_array($t) && $t[0] === T_FUNCTION && $depth === 0) {
            $inFn = true;
            $fnDepth = 0;
        }
        $text = is_array($t) ? $t[1] : $t;
        if ($text === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
        }
        if ($text === '}') {
            $depth--;
            if ($inFn && $depth === 0) {
                $inFn = false;
                continue;
            }
        }
        if (!$inFn) {
            $out .= $text;
        }
    }
    return $out;
}

$user = classic_dispatch($api . '/ap-user.php');
$cmdr = classic_dispatch($api . '/ap-cmdr-nova.php');
classic_check(!str_contains($user, 'ap_user_profile_html('), 'ap-user.php no longer serves the classic profile');
classic_check(!str_contains($user, 'ap_user_serve_collection_html('), 'ap-user.php no longer serves classic collection HTML');
classic_check(substr_count($user, 'ap_vaak_redirect_classic_profile(') === 2, 'ap-user.php redirects both classic routes');
classic_check(!preg_match('/\bap_cmdr_html\(\)\s*;/', $cmdr), 'ap-cmdr-nova.php no longer serves the classic profile');
classic_check(!str_contains($cmdr, 'ap_cmdr_outbox_html(') && !str_contains($cmdr, 'ap_cmdr_collection_html($col)'), 'ap-cmdr-nova.php no longer serves classic collection HTML');
classic_check(substr_count($cmdr, "ap_vaak_redirect_classic_profile('cmdr_nova')") === 2, 'ap-cmdr-nova.php redirects both classic routes');
classic_check(str_contains($cmdr, "=== 'jekyll'"), 'Jekyll download stays');
classic_check(str_contains($user, 'ap_user_note_html(') && str_contains($cmdr, 'ap_cmdr_note_html('), 'note pages are unchanged');

require $api . '/ap-vaak-profile-route.php';
classic_check(ap_vaak_pretty_profile_path('Juno') === '/vaak/users/juno', 'redirect target is the in-app profile');

echo "classic-profile-redirect-smoke: {$checks} checks passed\n";
