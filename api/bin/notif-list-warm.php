#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * RETIRED (VAAK 0.6.63+). Mentions list-warm is native in
 * `vaak-worker notif-list` (`source=vaak-worker-projection`).
 *
 * Incomplete projection windows soft-skip; Mentions request-path PHP
 * (`ap_masto_notifications_fetch`) still hydrates cold reads. Do not
 * re-enable this CLI bridge.
 */
fwrite(STDERR, "notif-list-warm.php retired — use: vaak-worker notif-list --once --owner-id=N\n");
exit(3);
