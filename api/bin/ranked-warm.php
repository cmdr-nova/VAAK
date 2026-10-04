#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * RETIRED (VAAK 0.6.60+). Ranked Home/Local/Federated warm is native in
 * `vaak-worker ranked-warm` (`source=vaak-worker-native`).
 *
 * HTML soft-nav still warms via in-process `admin_tl_lean_ranked_warm` in
 * ap-admin.php. Do not re-enable this CLI bridge.
 */
fwrite(STDERR, "ranked-warm.php retired — use: vaak-worker ranked-warm --once --owner-id=N\n");
exit(3);
