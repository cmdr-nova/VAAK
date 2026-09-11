<?php
declare(strict_types=1);

/**
 * Public VAAK release channel / version label.
 * Bump these together when shipping a batch to the public mirror.
 */
const VAAK_VERSION = '0.1.1';
const VAAK_CHANNEL = 'alpha';
const VAAK_VERSION_DATE = '2026-09-11';

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
