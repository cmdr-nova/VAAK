<?php
declare(strict_types=1);

/**
 * Public VAAK release channel / version label.
 * Bump these together when shipping a batch to the public mirror.
 */
const VAAK_VERSION = '0.2.7';
const VAAK_CHANNEL = 'alpha';
const VAAK_VERSION_DATE = '2026-09-15';

/** e.g. "VAAK alpha 0.2.7 · 2026-09-15" */
function vaak_version_label(): string
{
    return 'VAAK ' . VAAK_CHANNEL . ' ' . VAAK_VERSION . ' · ' . VAAK_VERSION_DATE;
}

/** Short channel word for badges: "alpha" */
function vaak_version_channel(): string
{
    return VAAK_CHANNEL;
}
