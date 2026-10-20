<?php
declare(strict_types=1);
/**
 * Shared post links (/vaak/?view=status&object=…) open read-only for visitors
 * without an account. Guests stay cache-only: no remote fetches, no thread
 * AJAX, no soft-nav shells (those need a session). Source-level checks; no
 * database. The rendered page was checked against PostgreSQL by hand: public
 * and unlisted posts paint, followers-only/direct posts and server-blocked
 * authors show "This post isn't available.", CWs stay folded, and images
 * open in the lightbox.
 */
$root = getenv('VAAK_GUEST_STATUS_ROOT') ?: dirname(__DIR__, 2);
$checks = 0;
function guest_status_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

$front = (string) file_get_contents($root . '/vaak/index.php');
$admin = (string) file_get_contents($root . '/api/ap-admin.php');

// Front controller: a logged-out full-page GET for a post boots the guest view,
// before the partial/AJAX 401 path, and only for GET/HEAD with an object.
$guestRoute = strpos($front, "=== 'status'\n        && trim((string) (\$_GET['object'] ?? '')) !== ''");
$partial401 = strpos($front, 'if ($isPartial || $isAjaxGet || $isFetch) {');
guest_status_check($guestRoute !== false, 'index.php routes logged-out status links to the guest view');
guest_status_check($partial401 !== false && $guestRoute < $partial401, 'guest status route sits before the 401 path');
$routeBlock = substr($front, (int) strrpos(substr($front, 0, (int) $guestRoute), 'if ($mode'), 600);
guest_status_check(str_contains($routeBlock, '!$isPartial && !$isAjaxGet && !$isFetch'), 'partials and fetches still get 401');
guest_status_check(str_contains($routeBlock, "\$guestMethod === 'GET' || \$guestMethod === 'HEAD'"), 'guest status is GET/HEAD only');

// Session gate: status joins remote_profile as the only guest views.
guest_status_check(str_contains($admin, "(\$vaakGuestView === 'remote_profile' || \$vaakGuestView === 'status')"), 'session gate admits guest status');
guest_status_check(str_contains($admin, "\$view = !empty(\$GLOBALS['vaak_guest_status']) ? 'status' : 'remote_profile';"), 'guest view is pinned to status or profile');

// Status page: guests never trigger remote work.
$start = strpos($admin, "      <?php elseif (\$view === 'status'): ?>");
$end = strpos($admin, "      <?php elseif (\$view === 'remote_profile'): ?>", (int) $start);
guest_status_check($start !== false && $end !== false, 'status view located');
$status = substr($admin, (int) $start, (int) $end - (int) $start);
guest_status_check(str_contains($status, "\$stGuest = !empty(\$GLOBALS['vaak_guest_status']);"), 'status view knows it is a guest');
guest_status_check(substr_count($status, 'ap_masto_ensure_remote_note_event(') === 2, 'two remote ensure calls in the status view');
guest_status_check(str_contains($status, "&& !\$stGuest\n                      && \$stLocalStatus === null\n                      && function_exists('ap_masto_ensure_remote_note_event')"), 'first remote ensure skipped for guests');
guest_status_check(str_contains($status, "str_starts_with(\$stObject, 'https://') && !\$stGuest\n                  && function_exists('ap_masto_ensure_remote_note_event')"), 'last-chance ensure skipped for guests');
guest_status_check(str_contains($status, "if (\$stEvent === null && !\$stGuest\n"), 'pasted URL resolve skipped for guests');
guest_status_check(str_contains($status, "if (!\$stGuest && isset(\$_GET['refresh_thread'])"), 'refresh_thread fetch skipped for guests');
guest_status_check(str_contains($status, "\$stMaybeMoreThread = false;\n              \$stMaybeMoreReplies = false;\n              \$stMaybeHydrateFocus = false;"), 'thread AJAX disabled for guests');
guest_status_check(str_contains($status, 'admin_bsky_thread_target_uri($stObject, false)'), 'Bluesky guest lookup is DB-only');
guest_status_check(str_contains($status, '!admin_direct_status_allowed($stGuestSource, 0)'), 'guest focus needs a public/unlisted source');
guest_status_check(str_contains($status, "<?php if (\$stGuest && (\$stContentBlocked || \$stGuestHidden)): ?>"), 'hidden posts paint nothing for guests');

// Client: no thread polling or soft-nav shells for guests.
guest_status_check(str_contains($admin, "if (!document.querySelector('.status-back')) return false;\n  // Guests get the server-painted, cache-only thread; thread partials need a session.\n  if (window.vaakGuestProfile) return false;"), 'status thread JS stays off for guests');
guest_status_check(substr_count($admin, "    if (window.vaakGuestProfile) return;\n    const link = ev.target") === 1
    && str_contains($admin, "if (ev.pointerType === 'mouse' || ev.button !== 0) return;\n    if (window.vaakGuestProfile) return;"), 'guests follow links as full page loads');

// The image lightbox is outside the compose-FAB gate, so guests can open images.
$lightbox = strpos($admin, '<div class="img-lightbox" id="img-lightbox"');
$fabGate = strpos($admin, '<?php if ($showComposeFab): ?>');
guest_status_check($lightbox !== false && $fabGate !== false && $lightbox < $fabGate, 'image lightbox renders for guests');

echo "guest-status-link-smoke: {$checks} checks passed\n";
