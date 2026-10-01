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
 * Ask presentation: ap_ask_card_html() + vaak_ask on the status, for local
 * answers, outbound→remote Wafrn answers, and federated Ask posts.
 *
 * @see Documents/cmdr-nova/Projects/NovaLandia/Additional Fixes/10.2 Features and Fixes.md
 */
declare(strict_types=1);

require_once __DIR__ . '/ap-asks.php';

/**
 * Attach Ask metadata onto a Mastodon-compatible status (local + remote).
 * VAAK cards read vaak_ask; API content gets the Ask card prepended when the
 * body is still answer-only (or compact "X asked" text).
 *
 * @param array<string,mixed> $status
 * @return array<string,mixed>
 */
function ap_normalize_attach_ask(array $status): array
{
    if (!empty($status['vaak_ask']) && is_array($status['vaak_ask'])) {
        return $status;
    }
    $uri = rtrim((string) ($status['uri'] ?? $status['url'] ?? ''), '/');
    $content = (string) ($status['content'] ?? '');
    $plain = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $ask = function_exists('ap_ask_context_for_object')
        ? ap_ask_context_for_object($uri, $plain !== '' ? $plain : $content)
        : null;
    if (!is_array($ask) || trim((string) ($ask['question'] ?? '')) === '') {
        return $status;
    }

    $status['vaak_ask'] = [
        'question' => (string) $ask['question'],
        'asker_actor' => (string) ($ask['asker_actor'] ?? ''),
        'asker_label' => (string) ($ask['asker_label'] ?? ''),
        'answer' => (string) ($ask['answer'] ?? ''),
    ];

    if (str_contains($content, 'ask-container')) {
        return $status;
    }

    $card = function_exists('ap_ask_card_html_from_row')
        ? ap_ask_card_html_from_row($status['vaak_ask'], false)
        : '';
    if ($card === '') {
        return $status;
    }

    $answer = trim((string) ($ask['answer'] ?? ''));
    if ($answer !== '') {
        $answerHtml = function_exists('ap_plain_text_to_html')
            ? ap_plain_text_to_html($answer)
            : ('<p>' . nl2br(htmlspecialchars($answer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>');
        $status['content'] = $card . $answerHtml;
        return $status;
    }

    // Local / outbound answers: content is already the answer body.
    if ($content !== '' && !preg_match('/\basked\b/iu', $plain)) {
        $status['content'] = $card . $content;
        return $status;
    }

    // Compact "X asked …" only — card is the whole presentation (answer inside if parsed).
    $withAnswer = function_exists('ap_ask_card_html_from_row')
        ? ap_ask_card_html_from_row($status['vaak_ask'], trim((string) ($ask['answer'] ?? '')) !== '')
        : $card;
    $status['content'] = $withAnswer !== '' ? $withAnswer : $card;
    return $status;
}

/** @deprecated use ap_normalize_attach_ask */
function ap_normalize_attach_local_ask(array $status): array
{
    return ap_normalize_attach_ask($status);
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
    return ap_normalize_attach_ask($status);
}
