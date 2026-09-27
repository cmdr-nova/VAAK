<?php
/**
 * Lightweight Bluesky URL helpers (no AppView/XRPC).
 * Safe to load from Mastodon trends / Explore without parsing ap-bsky.php.
 */
declare(strict_types=1);

if (!function_exists('ap_bsky_actor_profile_url')) {
    function ap_bsky_actor_profile_url(string $handleOrDid): string
    {
        $h = ltrim(trim($handleOrDid), '@');
        if ($h === '') {
            return 'https://bsky.app/';
        }
        if (str_starts_with($h, 'did%3A') || str_starts_with($h, 'did%3a')) {
            $h = rawurldecode($h);
        }
        $path = str_starts_with($h, 'did:') ? $h : rawurlencode($h);
        return 'https://bsky.app/profile/' . $path;
    }
}

if (!function_exists('ap_bsky_normalize_web_url')) {
    /** Encoded did%3Aplc%3A… links 404 in the Bluesky web app. */
    function ap_bsky_normalize_web_url(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !str_starts_with($url, 'https://bsky.app/')) {
            return $url;
        }
        if (preg_match('~^(https://bsky\.app/profile/)([^/]+)(/post/[^/?#]+)?(.*)$~i', $url, $m)) {
            $actor = rawurldecode($m[2]);
            $actorPath = str_starts_with($actor, 'did:') ? $actor : rawurlencode($actor);
            $post = $m[3] ?? '';
            if ($post !== '' && preg_match('~^/post/([^/?#]+)~', $post, $pm)) {
                $post = '/post/' . rawurlencode(rawurldecode($pm[1]));
            }
            return $m[1] . $actorPath . $post . ($m[4] ?? '');
        }
        return $url;
    }
}

if (!function_exists('ap_bsky_https_url_from_at_uri')) {
    /** Convert an AT-URI (or already-https URL) into a browser URL on bsky.app. */
    function ap_bsky_https_url_from_at_uri(string $uri, ?string $authorHandle = null): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            return 'https://bsky.app/';
        }
        if (str_starts_with($uri, 'https://')) {
            return ap_bsky_normalize_web_url($uri);
        }
        if (preg_match('~^at://([^/]+)/app\.bsky\.feed\.post/([^/\s?]+)~', $uri, $m)) {
            $actor = ($authorHandle !== null && $authorHandle !== '') ? $authorHandle : $m[1];
            if (str_starts_with($actor, 'did%3A') || str_starts_with($actor, 'did%3a')) {
                $actor = rawurldecode($actor);
            }
            $actorPath = str_starts_with($actor, 'did:') ? $actor : rawurlencode($actor);
            return 'https://bsky.app/profile/' . $actorPath . '/post/' . rawurlencode($m[2]);
        }
        if (preg_match('~^at://([^/]+)~', $uri, $m)) {
            return ap_bsky_actor_profile_url($authorHandle !== null && $authorHandle !== '' ? $authorHandle : $m[1]);
        }
        return 'https://bsky.app/';
    }
}
