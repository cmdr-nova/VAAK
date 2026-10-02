<?php
/**
 * VAAK post normalizer (10.2).
 *
 * Owned front door: foreign objects → Mastodon-compatible status → one card.
 * Cards and Ice Cubes should not special-case Mastodon vs Sharkey vs Akkoma vs
 * Bluesky vs RSS vs Ask wire formats.
 *
 * Converters that already feed this:
 *   ap_masto_status_from_row / from_event / from_mention / from_as2_note
 *   ap_rss_item_to_masto_status
 *   ap_normalize_from_bsky_post (thin wrapper)
 *
 * Web render target: admin_render_masto_status_card (Home Create/Update + RSS
 * via admin_render_timeline_item). Announce boost shells and Bluesky-native
 * feed cards still use specialized renderers until folded in.
 *
 * @see Documents/cmdr-nova/Projects/NovaLandia/Additional Fixes/10.2 Features and Fixes.md
 */
declare(strict_types=1);

require_once __DIR__ . '/ap-asks.php';

/**
 * Attach Ask metadata onto a Mastodon-compatible status (local + remote).
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

    if ($content !== '' && !preg_match('/\basked\b/iu', $plain)) {
        $status['content'] = $card . $content;
        return $status;
    }

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
 * Map Bluesky adult/graphic labels onto Mastodon sensitive (CW gate).
 * Bridgy/AP mirrors often omit `sensitive`; focus views that load ATProto
 * already blur — keep timeline boosts/Create cards consistent.
 *
 * @param array<string,mixed> $status
 * @return array<string,mixed>
 */
function ap_normalize_apply_bsky_sensitivity(array $status): array
{
    $applyOne = static function (array $st): array {
        if (!empty($st['sensitive'])) {
            return $st;
        }
        $uri = trim((string) ($st['uri'] ?? $st['url'] ?? ''));
        if ($uri === '') {
            return $st;
        }
        $looksBsky = str_contains($uri, 'bsky.app/')
            || str_starts_with($uri, 'at://')
            || (!empty($st['source']) && (string) $st['source'] === 'bluesky');
        if (!$looksBsky) {
            return $st;
        }
        if (!function_exists('ap_bsky_post_is_sensitive')) {
            $bsky = __DIR__ . '/ap-bsky.php';
            if (is_file($bsky)) {
                require_once $bsky;
            }
        }
        if (!function_exists('ap_bsky_post_is_sensitive')) {
            return $st;
        }
        $post = null;
        if (function_exists('ap_bsky_at_uri_from_any_url') && function_exists('ap_bsky_post_item_by_uri')) {
            $at = ap_bsky_at_uri_from_any_url($uri);
            if (is_string($at) && $at !== '') {
                $item = ap_bsky_post_item_by_uri($at);
                if (is_array($item) && is_array($item['post'] ?? null)) {
                    $post = $item['post'];
                }
            }
        }
        // Trend / thin status may already carry labels on a nested blob.
        if ($post === null && is_array($st['bsky_post'] ?? null)) {
            $post = $st['bsky_post'];
        }
        if (!is_array($post)) {
            return $st;
        }
        if (ap_bsky_post_is_sensitive($post)) {
            $st['sensitive'] = true;
            if (trim((string) ($st['spoiler_text'] ?? '')) === '') {
                $st['spoiler_text'] = 'Sensitive content';
            }
            $st['vaak_bsky_sensitive'] = true;
        }
        return $st;
    };

    $status = $applyOne($status);
    // Outer boost shell stays non-sensitive; gate follows the inner post.
    if (isset($status['reblog']) && is_array($status['reblog'])) {
        $status['reblog'] = $applyOne($status['reblog']);
    }
    return $status;
}

/**
 * Canonical pass for an already-built Mastodon-compatible status.
 *
 * @param array<string,mixed> $status
 * @return array<string,mixed>
 */
function ap_normalize_status(array $status): array
{
    $status = ap_normalize_attach_ask($status);
    $status = ap_normalize_apply_bsky_sensitivity($status);
    return $status;
}

/**
 * ActivityPub firehose / remote Create→status.
 *
 * @param array<string,mixed> $eventRow events table row
 * @return array<string,mixed>|null
 */
function ap_normalize_from_activitypub_event(array $eventRow): ?array
{
    if (!function_exists('ap_masto_status_from_event')) {
        require_once __DIR__ . '/ap-masto-entities.php';
    }
    if (!function_exists('ap_masto_status_from_event')) {
        return null;
    }
    $st = ap_masto_status_from_event($eventRow);
    return is_array($st) ? $st : null;
}

/**
 * Bluesky PostView (or feed item with post) → Mastodon status.
 *
 * @param array<string,mixed> $postOrItem
 * @return array<string,mixed>|null
 */
function ap_normalize_from_bsky_post(array $postOrItem): ?array
{
    $post = is_array($postOrItem['post'] ?? null) ? $postOrItem['post'] : $postOrItem;
    if (!function_exists('ap_masto_bsky_trend_status')) {
        require_once __DIR__ . '/ap-masto-entities.php';
    }
    if (!function_exists('ap_masto_bsky_trend_status')) {
        return null;
    }
    $st = ap_masto_bsky_trend_status($post);
    if (!is_array($st)) {
        return null;
    }
    // Ensure labels blob survives even if the converter omitted it.
    if (!isset($st['bsky_post'])) {
        $st['bsky_post'] = $post;
    }
    $st['source'] = 'bluesky';
    return ap_normalize_status($st);
}

/**
 * RSS item row → Mastodon status.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>|null
 */
function ap_normalize_from_rss_item(array $row): ?array
{
    if (!function_exists('ap_rss_item_to_masto_status')) {
        $rss = __DIR__ . '/ap-rss.php';
        if (is_file($rss)) {
            require_once $rss;
        }
    }
    if (!function_exists('ap_rss_item_to_masto_status')) {
        return null;
    }
    // ap_rss_item_to_masto_status already ends in ap_normalize_status.
    $st = ap_rss_item_to_masto_status($row);
    return is_array($st) ? $st : null;
}
