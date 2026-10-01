<?php
/**
 * VAAK post normalizer (early scaffold).
 *
 * Goal (10.2): one canonical internal post / Mastodon-compatible status shape.
 * Cards and API clients render that shape; they should not special-case
 * Mastodon vs Sharkey vs Akkoma vs Bluesky vs RSS vs Ask wire formats.
 *
 * Today VAAK already converts many sources into Mastodon status arrays via
 * ap_masto_status_from_* and ap_rss_item_to_masto_status. This module is the
 * owned front door those paths should converge on.
 *
 * First concrete win: Ask presentation goes through ap_ask_card_html() so
 * timeline, note pages, public profiles, and status.content HTML share one card.
 *
 * @see Documents/cmdr-nova/Projects/NovaLandia/Additional Fixes/10.2 Features and Fixes.md
 */
declare(strict_types=1);

require_once __DIR__ . '/ap-asks.php';

/**
 * Identity pass for an already-built Mastodon-compatible status.
 * Later: coerce missing fields, degrade unknown shapes, strip raw foreign blobs.
 *
 * @param array<string,mixed> $status
 * @return array<string,mixed>
 */
function ap_normalize_status(array $status): array
{
    // Ask / Wafrn compact answers: ensure content uses the canonical Ask card
    // when the status still carries plain "X asked …" text.
    $content = (string) ($status['content'] ?? '');
    if ($content !== '' && function_exists('ap_wafrn_remote_ask_html')) {
        $askHtml = ap_wafrn_remote_ask_html($content);
        if (is_string($askHtml) && $askHtml !== '') {
            $status['content'] = $askHtml;
        }
    }
    return $status;
}
