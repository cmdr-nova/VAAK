<?php
declare(strict_types=1);

/**
 * Public VAAK release channel / version label.
 *
 * This file is the only version source. The login and registration page
 * (vaak/index.php) and the signed-in shell render vaak_version_label() from
 * these constants. deploy/bump_version.py updates them; do not hardcode the
 * number anywhere else.
 *
 * The counter is not semver. Each production deploy increments the last
 * component by 1 and always prints it as two digits (0.8.45 → 0.8.46).
 * After 99 the middle component increments and the last resets to 00
 * (0.8.99 → 0.9.00). After 0.99.99 the next value is 1.0.00.
 */
const VAAK_VERSION = '0.8.54';
const VAAK_CHANNEL = 'alpha';
const VAAK_VERSION_DATE = '2026-10-14';

/** e.g. "VAAK alpha 0.1.0 · 2026-09-10" */
function vaak_version_label(): string
{
    return 'VAAK ' . VAAK_CHANNEL . ' ' . VAAK_VERSION . ' · ' . VAAK_VERSION_DATE;
}

/** Short channel word for badges: "alpha" */
function vaak_version_channel(): string
{
    return VAAK_CHANNEL;
}
