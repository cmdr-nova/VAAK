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
 * Attach local Ask answer metadata (ap_asks) onto a Mastodon-compatible status.
 * VAAK cards read vaak_ask; API content also gets the Ask card prepended so
 * Ice Cubes / Mastodon clients see the question.
 *
 * @param array<string,mixed> $status
 * @return array<string,mixed>
 */
function ap_normalize_attach_local_ask(array $status): array
{
    if (!empty($status['vaak_ask']) && is_array($status['vaak_ask'])) {
        return $status;
    }
    $uri = rtrim((string) ($status['uri'] ?? $status['url'] ?? ''), '/');
    if ($uri === '' || !function_exists('ap_ask_answer_for_note')) {
        return $status;
    }
    $ask = ap_ask_answer_for_note($uri);
    if (!is_array($ask)) {
        return $status;
    }
    $question = trim((string) ($ask['question'] ?? ''));
    if ($question === '') {
        return $status;
    }
    $status['vaak_ask'] = [
        'question' => $question,
        'asker_actor' => (string) ($ask['asker_actor'] ?? ''),
        'ask_id' => (string) ($ask['ask_id'] ?? ''),
        'answer_note_id' => (string) ($ask['answer_note_id'] ?? $uri),
    ];
    $content = (string) ($status['content'] ?? '');
    if ($content === '' || str_contains($content, 'ask-container')) {
        return $status;
    }
    if (function_exists('ap_ask_card_html_from_row')) {
        $card = ap_ask_card_html_from_row($ask, false);
        if ($card !== '') {
            $status['content'] = $card . $content;
        }
    }
    return $status;
}

/**
 * Identity pass for an already-built Mastodon-compatible status.
 * Later: coerce missing fields, degrade unknown shapes, strip raw foreign blobs.
 *
 * @param array<string,mixed> $status
 * @return array<string,mixed>
 */
function ap_normalize_status(array $status): array
{
    $status = ap_normalize_attach_local_ask($status);

    // Ask / Wafrn compact answers: ensure content uses the canonical Ask card
    // when the status still carries plain "X asked …" text (and no local ask).
    if (empty($status['vaak_ask'])) {
        $content = (string) ($status['content'] ?? '');
        if ($content !== '' && function_exists('ap_wafrn_remote_ask_html')
            && !str_contains($content, 'ask-container')) {
            $askHtml = ap_wafrn_remote_ask_html($content);
            if (is_string($askHtml) && $askHtml !== '') {
                $status['content'] = $askHtml;
            }
        }
    }
    return $status;
}
