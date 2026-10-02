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
 * Web render target: admin_render_masto_status_card (Home Create/Update/Announce,
 * RSS, and Bluesky feed items via admin_render_timeline_item). Thin Announces
 * without a cached Create still use remote_boost_card for AJAX hydrate.
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
    if (is_array($st)) {
        if (
            function_exists('ap_normalize_status_has_visible_body')
            && !ap_normalize_status_has_visible_body($st)
        ) {
            $st['vaak_degraded'] = true;
            $st['vaak_degraded_reason'] = (string) ($st['vaak_degraded_reason'] ?? 'empty_shell');
        }
        return $st;
    }

    // Empty Create/Update stubs (from_event returns null without allow-empty):
    // still emit a degraded status so Home paints one card + hydrate instead of
    // falling through to a parallel event_tweet dialect.
    $type = strtolower((string) ($eventRow['type'] ?? 'create'));
    if (!in_array($type, ['create', 'update'], true)) {
        return null;
    }
    $actorId = rtrim((string) ($eventRow['actor_id'] ?? ''), '/');
    $objectId = rtrim((string) ($eventRow['object_id'] ?? ''), '/');
    if ($actorId === '' || $objectId === '' || !str_starts_with($objectId, 'https://')) {
        return null;
    }
    $reason = 'empty_shell';
    $sum = trim((string) ($eventRow['summary'] ?? ''));
    if ($sum !== '' && !in_array($sum, ['(media)', '(attachment)', '(poll)', '(quote)', '(boost)'], true)) {
        $reason = 'half_parsed';
    }
    return ap_normalize_degraded_status([
        'id' => ap_masto_event_status_id(
            (int) ($eventRow['id'] ?? 0),
            isset($eventRow['created_at']) ? (string) $eventRow['created_at'] : null
        ),
        'uri' => $objectId,
        'actor_id' => $actorId,
        'created_at' => (string) ($eventRow['created_at'] ?? gmdate('c')),
        'content_plain' => $sum,
        'spoiler_text' => (string) ($eventRow['spoiler_text'] ?? ''),
        'sensitive' => !empty($eventRow['sensitive']),
        'visibility' => (string) ($eventRow['visibility'] ?? 'public'),
        'reason' => $reason,
    ]);
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
    $reason = is_array($postOrItem['reason'] ?? null) ? $postOrItem['reason'] : null;
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
    if (!isset($st['bsky_post'])) {
        $st['bsky_post'] = $post;
    }
    $st['source'] = 'bluesky';
    $st['bsky_cid'] = (string) ($post['cid'] ?? ($st['bsky_cid'] ?? ''));
    if (isset($postOrItem['_vaak_feed_source'])) {
        $st['vaak_feed_source'] = (string) $postOrItem['_vaak_feed_source'];
    }

    // Structured quote embed → Mastodon-ish quote for the shared card.
    if (
        empty($st['quote'])
        && function_exists('ap_bsky_quote_preview')
        && function_exists('ap_masto_bsky_trend_status')
    ) {
        $qPrev = ap_bsky_quote_preview($post);
        if (is_array($qPrev)) {
            $qPost = is_array($qPrev['post'] ?? null) ? $qPrev['post'] : null;
            $qSt = is_array($qPost) ? ap_masto_bsky_trend_status($qPost) : null;
            if (!is_array($qSt)) {
                // Thin preview without full PostView — synthesize a minimal status.
                $qHandle = trim((string) ($qPrev['handle'] ?? ''));
                $qText = trim((string) ($qPrev['text'] ?? ''));
                $qUrl = trim((string) ($qPrev['url'] ?? ''));
                $qSt = [
                    'id' => $qUrl !== '' ? $qUrl : ('bsky-quote:' . substr(hash('sha256', $qText . $qHandle), 0, 16)),
                    'uri' => $qUrl,
                    'url' => $qUrl,
                    'content' => $qText !== ''
                        ? ('<p>' . nl2br(htmlspecialchars($qText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>')
                        : '',
                    'created_at' => gmdate('c'),
                    'account' => [
                        'acct' => $qHandle,
                        'username' => $qHandle,
                        'display_name' => trim((string) ($qPrev['display'] ?? $qHandle)),
                        'url' => $qHandle !== '' ? ('https://bsky.app/profile/' . rawurlencode($qHandle)) : '',
                        'avatar' => '',
                    ],
                    'media_attachments' => is_array($qPrev['media'] ?? null) ? $qPrev['media'] : [],
                    'source' => 'bluesky',
                ];
            }
            $st['quote'] = [
                'quoted_status' => $qSt,
                'state' => 'accepted',
            ];
        }
    }

    $st = ap_normalize_status($st);

    // reasonRepost → Mastodon reblog wrapper (outer booster, empty body).
    if (is_array($reason)) {
        $rt = (string) ($reason['$type'] ?? '');
        if (str_contains($rt, 'reasonRepost')) {
            $by = is_array($reason['by'] ?? null) ? $reason['by'] : [];
            $byDid = trim((string) ($by['did'] ?? ''));
            $byHandle = trim((string) ($by['handle'] ?? ''));
            $byName = trim((string) ($by['displayName'] ?? ''));
            if ($byName === '') {
                $byName = $byHandle !== '' ? $byHandle : 'someone';
            }
            $byAvatar = (string) ($by['avatar'] ?? '');
            $byUrl = $byDid !== ''
                ? (function_exists('ap_bsky_actor_profile_url')
                    ? ap_bsky_actor_profile_url($byDid)
                    : ('https://bsky.app/profile/' . $byDid))
                : ($byHandle !== ''
                    ? (function_exists('ap_bsky_actor_profile_url')
                        ? ap_bsky_actor_profile_url($byHandle)
                        : ('https://bsky.app/profile/' . rawurlencode($byHandle)))
                    : '');
            $outerCreated = trim((string) ($reason['indexedAt'] ?? ''));
            if ($outerCreated === '') {
                $outerCreated = (string) ($st['created_at'] ?? gmdate('c'));
            }
            $outer = [
                'id' => 'bsky-repost:' . substr(hash('sha256', $byDid . '|' . (string) ($st['uri'] ?? '')), 0, 24),
                'created_at' => $outerCreated,
                'content' => '',
                'sensitive' => false,
                'spoiler_text' => '',
                'visibility' => 'public',
                'uri' => (string) ($st['uri'] ?? ''),
                'url' => (string) ($st['url'] ?? ''),
                'reblog' => $st,
                'account' => [
                    'id' => $byDid !== '' ? $byDid : ('bsky:' . $byHandle),
                    'acct' => $byHandle !== '' ? $byHandle : $byDid,
                    'username' => $byHandle !== '' ? $byHandle : $byDid,
                    'display_name' => $byName,
                    'url' => $byUrl,
                    'uri' => $byUrl,
                    'avatar' => $byAvatar,
                    'avatar_static' => $byAvatar,
                ],
                'media_attachments' => [],
                'source' => 'bluesky',
                'bsky_repost' => true,
            ];
            return ap_normalize_status($outer);
        }
    }

    return $st;
}

/**
 * Whether a Mastodon-shaped status (or its reblog) has body/media worth painting
 * without AJAX hydrate.
 *
 * @param array<string,mixed> $status
 */
function ap_normalize_status_has_visible_body(array $status): bool
{
    $inner = (isset($status['reblog']) && is_array($status['reblog'])) ? $status['reblog'] : $status;
    $plain = trim(html_entity_decode(strip_tags((string) ($inner['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($plain !== '' && !preg_match('/^\(boost\)$/i', $plain)) {
        return true;
    }
    $media = is_array($inner['media_attachments'] ?? null) ? $inner['media_attachments'] : [];
    if ($media !== []) {
        return true;
    }
    if (!empty($inner['vaak_ask']) && is_array($inner['vaak_ask'])) {
        return true;
    }
    if (is_array($inner['quote'] ?? null)) {
        return true;
    }
    if (is_array($inner['card'] ?? null) && trim((string) (($inner['card']['url'] ?? ''))) !== '') {
        return true;
    }
    return false;
}

/**
 * Half-parsed / empty-shell → Mastodon-shaped status that the shared card can
 * always paint (author, time, optional plain text, Open remote). Never drop the
 * item because one field was weird.
 *
 * Reasons: empty_shell | half_parsed | fetch_failed | announce_only
 *
 * @param array<string,mixed> $bits
 * @return array<string,mixed>
 */
function ap_normalize_degraded_status(array $bits): array
{
    $uri = rtrim(trim((string) ($bits['uri'] ?? $bits['url'] ?? $bits['object_id'] ?? '')), '/');
    $actorId = rtrim(trim((string) ($bits['actor_id'] ?? $bits['attributedTo'] ?? '')), '/');
    $reason = trim((string) ($bits['reason'] ?? 'half_parsed'));
    if ($reason === '') {
        $reason = 'half_parsed';
    }
    $created = trim((string) ($bits['created_at'] ?? $bits['published'] ?? ''));
    if ($created === '') {
        $created = gmdate('c');
    }
    $plain = trim((string) ($bits['content_plain'] ?? $bits['summary'] ?? $bits['text'] ?? ''));
    if ($plain !== '') {
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;
        $plain = mb_substr($plain, 0, 2000);
    }
    $content = $plain !== ''
        ? ('<p>' . htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>')
        : '';

    $account = is_array($bits['account'] ?? null) ? $bits['account'] : null;
    if ($account === null && $actorId !== '' && str_starts_with($actorId, 'https://')) {
        if (function_exists('ap_masto_remote_account')) {
            $account = ap_masto_remote_account($actorId);
        } else {
            $host = strtolower((string) (parse_url($actorId, PHP_URL_HOST) ?: 'unknown'));
            $user = basename(parse_url($actorId, PHP_URL_PATH) ?: 'user');
            $user = ltrim((string) $user, '@');
            $account = [
                'id' => $actorId,
                'acct' => $user . '@' . $host,
                'username' => $user,
                'display_name' => $user,
                'url' => $actorId,
                'uri' => $actorId,
                'avatar' => '',
            ];
        }
    }
    if (!is_array($account)) {
        $account = [
            'id' => $actorId !== '' ? $actorId : 'unknown',
            'acct' => 'unknown',
            'username' => 'unknown',
            'display_name' => 'Unknown',
            'url' => $uri !== '' ? $uri : '',
            'uri' => $actorId,
            'avatar' => '',
        ];
    }

    $media = is_array($bits['media_attachments'] ?? null) ? $bits['media_attachments'] : [];
    $id = (string) ($bits['id'] ?? '');
    if ($id === '') {
        $id = $uri !== '' ? $uri : ('degraded:' . substr(hash('sha256', $actorId . '|' . $reason . '|' . $created), 0, 20));
    }

    $status = [
        'id' => $id,
        'uri' => $uri,
        'url' => $uri !== '' ? $uri : (string) ($account['url'] ?? ''),
        'content' => $content,
        'created_at' => $created,
        'sensitive' => !empty($bits['sensitive']),
        'spoiler_text' => (string) ($bits['spoiler_text'] ?? ''),
        'visibility' => (string) ($bits['visibility'] ?? 'public'),
        'account' => $account,
        'media_attachments' => $media,
        'reblog' => null,
        'quote' => null,
        'vaak_degraded' => true,
        'vaak_degraded_reason' => $reason,
    ];
    if (isset($bits['source']) && is_string($bits['source']) && $bits['source'] !== '') {
        $status['source'] = $bits['source'];
    }
    return function_exists('ap_normalize_status') ? ap_normalize_status($status) : $status;
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
