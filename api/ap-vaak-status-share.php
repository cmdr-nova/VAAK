<?php
/**
 * Link preview cards for shared VAAK post links.
 *
 * iMessage, Discord, Slack, Mastodon, Bluesky and friends unfurl a link by
 * reading Open Graph / Twitter card tags from the first HTML response. They
 * never run JavaScript and never carry a VAAK session, so they always land on
 * the guest status page (/vaak/@{key}/{id} or /vaak/?view=status&object=…).
 *
 * The guest page already paints only posts this instance stores, and only
 * when their audience is public or unlisted. These tags follow the same rule:
 * no tags at all for followers-only, direct, deleted, server-blocked or
 * missing posts, so the card can never say more than the page shows a guest.
 * Lookups are cache-only (no remote fetch). A CW or sensitive media keeps the
 * media out of the card and uses the author's avatar instead.
 *
 * The builders below are pure (no database) so api/bin/status-share-meta-smoke.php
 * can test them. ap_vaak_status_share_meta() does the cache-only lookups.
 */
declare(strict_types=1);

const AP_VAAK_SHARE_DESC_MAX = 280;

/** Plain text for a meta description: tags stripped, whitespace folded, capped. */
function ap_vaak_share_plain(string $html, int $max = AP_VAAK_SHARE_DESC_MAX): string
{
    // Keep paragraph and line breaks as spaces so words do not glue together.
    $html = preg_replace('#<\s*(br|/p|/li|/blockquote)\b[^>]*>#i', ' ', $html) ?? $html;
    $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
    if (mb_strlen($plain) > $max) {
        $cut = mb_substr($plain, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > (int) ($max * 0.6)) {
            $cut = mb_substr($cut, 0, $space);
        }
        $plain = rtrim($cut, " ,.;:-") . '…';
    }
    return $plain;
}

/** An image URL a crawler can fetch, or ''. Root-relative paths are made absolute. */
function ap_vaak_share_image_url(mixed $url): string
{
    if (!is_string($url)) {
        return '';
    }
    $url = trim($url);
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        $url = 'https://mkultra.monster' . $url;
    }
    if (!preg_match('#^https://[^\s"<>]+$#', $url)) {
        return '';
    }
    return $url;
}

/** "Name (@user@host)", with a local acct given its host. */
function ap_vaak_share_title(string $name, string $acct, string $host = 'mkultra.monster'): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    $acct = ltrim(trim($acct), '@');
    if ($acct !== '' && !str_contains($acct, '@') && $host !== '') {
        $acct .= '@' . $host;
    }
    if ($name === '' && $acct === '') {
        return 'A post on VAAK';
    }
    if ($name === '' || strcasecmp($name, $acct) === 0) {
        return '@' . $acct;
    }
    return $acct !== '' ? $name . ' (@' . $acct . ')' : $name;
}

/**
 * Card fields from a Mastodon-shaped status (local, fediverse, Bridgy copies).
 *
 * @param array<string,mixed> $status
 * @return array<string,string>
 */
function ap_vaak_share_meta_from_status(array $status, string $pageUrl): array
{
    // A boost wrapper is not the post. Copy link already points at the original.
    if (is_array($status['reblog'] ?? null)) {
        $status = $status['reblog'];
    }
    $account = is_array($status['account'] ?? null) ? $status['account'] : [];
    $title = ap_vaak_share_title((string) ($account['display_name'] ?? ''), (string) ($account['acct'] ?? ($account['username'] ?? '')));
    $avatar = ap_vaak_share_image_url($account['avatar_static'] ?? null) ?: ap_vaak_share_image_url($account['avatar'] ?? null);

    $cw = trim((string) ($status['spoiler_text'] ?? ''));
    $sensitive = !empty($status['sensitive']) || $cw !== '';
    $text = ap_vaak_share_plain((string) ($status['content'] ?? ''));

    $image = '';
    $imageAlt = '';
    $mediaCount = 0;
    $attachments = is_array($status['media_attachments'] ?? null) ? $status['media_attachments'] : [];
    foreach ($attachments as $media) {
        if (!is_array($media)) {
            continue;
        }
        $mediaCount++;
        if ($image !== '' || $sensitive) {
            continue;
        }
        $type = strtolower((string) ($media['type'] ?? ''));
        $candidate = $type === 'image'
            ? (ap_vaak_share_image_url($media['url'] ?? null) ?: ap_vaak_share_image_url($media['preview_url'] ?? null))
            : ap_vaak_share_image_url($media['preview_url'] ?? null);
        // Audio and unknown files only count when they carry separate cover art.
        if ($candidate === ''
            || (in_array($type, ['audio', 'unknown'], true) && $candidate === ap_vaak_share_image_url($media['url'] ?? null))) {
            continue;
        }
        $image = $candidate;
        $imageAlt = ap_vaak_share_plain((string) ($media['description'] ?? ''), 200);
    }

    if ($cw !== '') {
        $description = 'CW: ' . ap_vaak_share_plain($cw, AP_VAAK_SHARE_DESC_MAX - 4);
    } elseif ($text !== '') {
        // $text is already plain; never run it through strip_tags again.
        $description = $mediaCount > 0
            ? ap_vaak_share_plain((string) ($status['content'] ?? ''), AP_VAAK_SHARE_DESC_MAX - 2) . ' 📎'
            : $text;
    } elseif ($mediaCount > 0) {
        $description = $mediaCount === 1 ? '📎 1 attachment' : '📎 ' . $mediaCount . ' attachments';
    } else {
        $description = 'A post on VAAK.';
    }

    return [
        'title' => $title,
        'description' => $description,
        'url' => $pageUrl,
        'image' => $image !== '' ? $image : $avatar,
        'image_alt' => $image !== '' ? $imageAlt : '',
        'large' => $image !== '' ? '1' : '',
        'published' => (string) ($status['created_at'] ?? ''),
    ];
}

/** Bluesky labels that mean "do not show this media without a click". */
function ap_vaak_share_bsky_sensitive(array $post): bool
{
    $labels = is_array($post['labels'] ?? null) ? $post['labels'] : [];
    foreach ($labels as $label) {
        $val = strtolower((string) (is_array($label) ? ($label['val'] ?? '') : ''));
        if (in_array($val, ['porn', 'sexual', 'nudity', 'graphic-media', 'gore', 'nsfl'], true)) {
            return true;
        }
    }
    return false;
}

/** The author asked Bluesky apps not to show their posts to logged-out viewers. */
function ap_vaak_share_bsky_logged_out_blocked(array $post): bool
{
    $labels = is_array($post['author']['labels'] ?? null) ? $post['author']['labels'] : [];
    foreach ($labels as $label) {
        if (is_array($label) && ($label['val'] ?? '') === '!no-unauthenticated') {
            return true;
        }
    }
    return false;
}

/**
 * First image (or video thumbnail) of a Bluesky post view embed.
 *
 * @return array{0:string,1:string} [url, alt]
 */
function ap_vaak_share_bsky_embed_image(mixed $embed): array
{
    if (!is_array($embed)) {
        return ['', ''];
    }
    $type = (string) ($embed['$type'] ?? '');
    if (str_starts_with($type, 'app.bsky.embed.recordWithMedia')) {
        return ap_vaak_share_bsky_embed_image($embed['media'] ?? null);
    }
    if (str_starts_with($type, 'app.bsky.embed.images')) {
        foreach ((array) ($embed['images'] ?? []) as $img) {
            if (!is_array($img)) {
                continue;
            }
            $url = ap_vaak_share_image_url($img['fullsize'] ?? null) ?: ap_vaak_share_image_url($img['thumb'] ?? null);
            if ($url !== '') {
                return [$url, ap_vaak_share_plain((string) ($img['alt'] ?? ''), 200)];
            }
        }
    }
    if (str_starts_with($type, 'app.bsky.embed.video')) {
        return [ap_vaak_share_image_url($embed['thumbnail'] ?? null), ap_vaak_share_plain((string) ($embed['alt'] ?? ''), 200)];
    }
    if (str_starts_with($type, 'app.bsky.embed.external')) {
        $ext = is_array($embed['external'] ?? null) ? $embed['external'] : [];
        return [ap_vaak_share_image_url($ext['thumb'] ?? null), ''];
    }
    return ['', ''];
}

/**
 * Card fields from a cached Bluesky post view, or null when the author opted
 * out of logged-out viewing.
 *
 * @param array<string,mixed> $post app.bsky.feed.defs#postView
 * @return array<string,string>|null
 */
function ap_vaak_share_meta_from_bsky(array $post, string $pageUrl): ?array
{
    if (ap_vaak_share_bsky_logged_out_blocked($post)) {
        return null;
    }
    $author = is_array($post['author'] ?? null) ? $post['author'] : [];
    $handle = ltrim(trim((string) ($author['handle'] ?? '')), '@');
    $title = ap_vaak_share_title((string) ($author['displayName'] ?? ''), $handle, '');
    $avatar = ap_vaak_share_image_url($author['avatar'] ?? null);
    $record = is_array($post['record'] ?? null) ? $post['record'] : [];
    // Record text is plain, not HTML: escape it so "<3" survives strip_tags.
    $text = ap_vaak_share_plain(htmlspecialchars((string) ($record['text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    [$image, $alt] = ap_vaak_share_bsky_sensitive($post) ? ['', ''] : ap_vaak_share_bsky_embed_image($post['embed'] ?? null);
    return [
        'title' => $title,
        'description' => $text !== '' ? $text : 'A Bluesky post on VAAK.',
        'url' => $pageUrl,
        'image' => $image !== '' ? $image : $avatar,
        'image_alt' => $image !== '' ? $alt : '',
        'large' => $image !== '' ? '1' : '',
        'published' => (string) ($record['createdAt'] ?? ($post['indexedAt'] ?? '')),
    ];
}

/** Absolute shareable URL for this status page. */
function ap_vaak_share_page_url(string $object): string
{
    if (function_exists('ap_vaak_local_note_parts')) {
        $parts = ap_vaak_local_note_parts($object);
        if (is_array($parts)) {
            return 'https://mkultra.monster/vaak/@' . $parts['key'] . '/' . $parts['id'];
        }
    }
    return 'https://mkultra.monster/vaak/?view=status&object=' . rawurlencode($object);
}

/**
 * Cache-only card for a guest status page, or null when nothing may be shown.
 * Mirrors the guest status view: stored post, public or unlisted audience,
 * author not blocked server-wide.
 *
 * @return array<string,string>|null
 */
function ap_vaak_status_share_meta(string $object): ?array
{
    $object = trim($object);
    if ($object === '' || str_contains($object, '/bites-received/')) {
        return null;
    }
    if (str_starts_with($object, 'bsky:')) {
        $object = substr($object, 5);
    }
    $pageUrl = ap_vaak_share_page_url($object);

    $isBsky = function_exists('admin_object_is_bluesky_post') && admin_object_is_bluesky_post($object);
    if ($isBsky && function_exists('admin_bsky_thread_target_uri') && function_exists('ap_bsky_post_item_by_uri')) {
        $at = admin_bsky_thread_target_uri($object, false);
        $item = is_string($at) ? ap_bsky_post_item_by_uri($at) : null;
        $post = is_array($item['post'] ?? null) ? $item['post'] : null;
        if (is_array($post)) {
            $did = (string) ($post['author']['did'] ?? '');
            if ($did !== '' && function_exists('ap_actor_is_content_blocked') && ap_actor_is_content_blocked($did, null, 0)) {
                return null;
            }
            return ap_vaak_share_meta_from_bsky($post, $pageUrl);
        }
        // A Bluesky URL may still have a local or Bridgy twin below.
        if (function_exists('admin_status_bsky_local_fallback')) {
            $twin = admin_status_bsky_local_fallback($object);
            if (is_string($twin) && $twin !== '') {
                $object = $twin;
            }
        }
    }
    if (!str_starts_with($object, 'https://') || !function_exists('ap_masto_lookup_status_by_object_url')
        || !function_exists('admin_direct_status_allowed')) {
        return null;
    }
    if (function_exists('ap_masto_mention_target_object_id')) {
        $object = ap_masto_mention_target_object_id($object);
    }
    $candidates = function_exists('ap_masto_object_url_lookup_candidates')
        ? ap_masto_object_url_lookup_candidates($object)
        : [$object];
    if (!in_array($object, $candidates, true)) {
        array_unshift($candidates, $object);
    }
    foreach (array_slice($candidates, 0, 6) as $candidate) {
        $status = ap_masto_lookup_status_by_object_url((string) $candidate, 0, false, true);
        if (!is_array($status)) {
            continue;
        }
        // Same rule the guest page uses: owner 0 has no audience grants.
        if (!admin_direct_status_allowed($status, 0)) {
            return null;
        }
        if (function_exists('admin_direct_status_content_blocked') && admin_direct_status_content_blocked($status)) {
            return null;
        }
        return ap_vaak_share_meta_from_status($status, $pageUrl);
    }
    return null;
}

/**
 * <head> tags for a card.
 *
 * @param array<string,string> $meta
 */
function ap_vaak_share_meta_tags(array $meta): string
{
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $title = trim((string) ($meta['title'] ?? ''));
    $description = trim((string) ($meta['description'] ?? ''));
    $url = trim((string) ($meta['url'] ?? ''));
    $image = ap_vaak_share_image_url($meta['image'] ?? '');
    $alt = trim((string) ($meta['image_alt'] ?? ''));
    $large = !empty($meta['large']) && $image !== '';
    $html = '<meta property="og:site_name" content="VAAK">'
        . '<meta property="og:type" content="article">';
    if ($title !== '') {
        $html .= '<meta property="og:title" content="' . $e($title) . '">'
            . '<meta name="twitter:title" content="' . $e($title) . '">';
    }
    if ($description !== '') {
        $html .= '<meta name="description" content="' . $e($description) . '">'
            . '<meta property="og:description" content="' . $e($description) . '">'
            . '<meta name="twitter:description" content="' . $e($description) . '">';
    }
    if (str_starts_with($url, 'https://')) {
        $html .= '<meta property="og:url" content="' . $e($url) . '">'
            . '<link rel="canonical" href="' . $e($url) . '">';
    }
    if ($image !== '') {
        $html .= '<meta property="og:image" content="' . $e($image) . '">'
            . '<meta name="twitter:image" content="' . $e($image) . '">';
        if ($alt !== '') {
            $html .= '<meta property="og:image:alt" content="' . $e($alt) . '">'
                . '<meta name="twitter:image:alt" content="' . $e($alt) . '">';
        }
    }
    $published = trim((string) ($meta['published'] ?? ''));
    if ($published !== '' && ($ts = strtotime($published)) !== false) {
        $html .= '<meta property="article:published_time" content="' . $e(gmdate('Y-m-d\TH:i:s\Z', $ts)) . '">';
    }
    $html .= '<meta name="twitter:card" content="' . ($large ? 'summary_large_image' : 'summary') . '">';
    return $html;
}
