<?php
/**
 * Open Graph / YouTube link preview cards (cached).
 * Used by Mastodon Status.card, admin UI, AP note HTML, homepage.
 */
declare(strict_types=1);

require_once __DIR__ . '/ap-db.php';

/**
 * Normalize and extract the first eligible https URL from plain text or HTML.
 */
function ap_link_preview_extract_url(string $textOrHtml, bool $skipLocalNotes = true): ?string
{
    $urls = ap_link_preview_extract_all_urls($textOrHtml, $skipLocalNotes);
    return $urls[0] ?? null;
}

/**
 * All eligible https URLs from plain text or HTML (deduped, normalized).
 *
 * @return list<string>
 */
function ap_link_preview_extract_all_urls(string $textOrHtml, bool $skipLocalNotes = true): array
{
    $plain = trim(html_entity_decode(strip_tags($textOrHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($plain === '') {
        return [];
    }
    if (!preg_match_all('#https://[^\s<>"\']+#iu', $plain, $m)) {
        return [];
    }
    $localPrefix = 'https://mkultra.monster/users/cmdr_nova/notes/';
    $out = [];
    $seen = [];
    foreach ($m[0] as $raw) {
        // Keep only ASCII URL characters (drops trailing emoji / smart punctuation)
        $url = (string) $raw;
        if (preg_match('#^(https://[\x21-\x7E]+)#', $url, $um)) {
            $url = $um[1];
        }
        $url = rtrim($url, '.,);]!?\'"');
        // Split glued absolute URLs (e.g. article URL + http://techmeme.com/… in one token)
        if (preg_match('#^(https://.+?)(?=https?://)#i', $url, $cut)) {
            $url = $cut[1];
        }
        $url = rtrim($url, '.,);]!?\'"/');
        if (!str_starts_with(strtolower($url), 'https://')) {
            continue;
        }
        if ($skipLocalNotes && str_starts_with($url, $localPrefix)) {
            continue;
        }
        // Skip obvious direct media files (attachments already shown)
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if (preg_match('/\.(jpe?g|png|gif|webp|mp4|webm|mov|m4v|mp3|ogg|wav|pdf)(\?|$)/', $path)) {
            continue;
        }
        $norm = ap_link_preview_canonicalize_url(ap_link_preview_normalize_url($url));
        if ($norm === '' || isset($seen[$norm])) {
            continue;
        }
        // Reject hosts with non-DNS junk (belt-and-suspenders after emoji strip)
        $host = (string) (parse_url($norm, PHP_URL_HOST) ?: '');
        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            continue;
        }
        $seen[$norm] = true;
        $out[] = $norm;
    }
    return $out;
}

function ap_link_preview_normalize_url(string $url): string
{
    $url = trim($url);
    // Glued second absolute URL (article + tracker) → keep the first
    if (preg_match('#^(https?://.+?)(?=https?://)#i', $url, $cut)) {
        $url = rtrim($cut[1], '.,);]!?\'"/');
    }
    // Expand youtu.be → watch URL for consistent cache keys
    if (preg_match('~^https?://(?:www\.)?youtu\.be/([A-Za-z0-9_-]{6,})~i', $url, $m)) {
        return 'https://www.youtube.com/watch?v=' . $m[1];
    }
    if (preg_match('~^https?://(?:www\.)?youtube\.com/shorts/([A-Za-z0-9_-]{6,})~i', $url, $m)) {
        return 'https://www.youtube.com/watch?v=' . $m[1];
    }
    if (preg_match('~^https?://(?:www\.)?youtube\.com/watch\?([^#]+)~i', $url, $m)) {
        parse_str($m[1], $q);
        if (!empty($q['v']) && is_string($q['v'])) {
            return 'https://www.youtube.com/watch?v=' . $q['v'];
        }
    }
    return $url;
}

/**
 * Strip tracking params / fragments so the same article collapses in trends.
 */
function ap_link_preview_canonicalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }
    $query = '';
    if (!empty($parts['query']) && is_string($parts['query'])) {
        parse_str($parts['query'], $q);
        if (is_array($q)) {
            foreach (array_keys($q) as $k) {
                $lk = strtolower((string) $k);
                if (str_starts_with($lk, 'utm_') || in_array($lk, ['fbclid', 'gclid', 'mc_cid', 'mc_eid', 'ref', 'ref_src'], true)) {
                    unset($q[$k]);
                }
            }
            if ($q) {
                ksort($q);
                $query = '?' . http_build_query($q);
            }
        }
    }
    return $scheme . '://' . $host . $path . $query;
}

function ap_link_preview_youtube_id(string $url): ?string
{
    $url = ap_link_preview_normalize_url($url);
    if (preg_match('~^https://www\.youtube\.com/watch\?v=([A-Za-z0-9_-]{6,})~', $url, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * SSRF-safe URL check (scheme + host resolution).
 */
function ap_link_preview_url_allowed(string $url): bool
{
    if (!str_starts_with(strtolower($url), 'https://') && !str_starts_with(strtolower($url), 'http://')) {
        return false;
    }
    if (strlen($url) > 2000) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || !is_string($parts['host'])) {
        return false;
    }
    $host = strtolower($parts['host']);
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return false;
    }
    // Block literal IPs that are private / link-local
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    } else {
        $ips = @gethostbynamel($host);
        if (is_array($ips)) {
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return false;
                }
            }
        }
    }
    return true;
}

/**
 * @return array{url:string,title:?string,description:?string,image:?string,provider_name:?string,provider_url:?string,type:string,status:string}|null
 */
function ap_link_preview_cache_get(string $url): ?array
{
    $url = ap_link_preview_normalize_url($url);
    try {
        $st = ap_db()->prepare('SELECT * FROM link_preview_cards WHERE url = ?');
        $st->execute([$url]);
        $row = $st->fetch();
        if (!is_array($row)) {
            return null;
        }
        $exp = (string) ($row['expires_at'] ?? '');
        if ($exp !== '' && strtotime($exp) !== false && strtotime($exp) < time()) {
            return null; // expired → refetch
        }
        return [
            'url' => (string) $row['url'],
            'title' => $row['title'] !== null ? (string) $row['title'] : null,
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'image' => $row['image'] !== null ? (string) $row['image'] : null,
            'provider_name' => $row['provider_name'] !== null ? (string) $row['provider_name'] : null,
            'provider_url' => $row['provider_url'] !== null ? (string) $row['provider_url'] : null,
            'type' => (string) ($row['type'] ?? 'link'),
            'status' => (string) ($row['status'] ?? 'ok'),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @param array{url:string,title?:?string,description?:?string,image?:?string,provider_name?:?string,provider_url?:?string,type?:string,status?:string} $card
 */
function ap_link_preview_cache_put(array $card, int $ttlSeconds): void
{
    $url = ap_link_preview_normalize_url((string) ($card['url'] ?? ''));
    if ($url === '') {
        return;
    }
    $now = ap_db_now();
    $expires = gmdate('c', time() + max(60, $ttlSeconds));
    try {
        ap_db()->prepare(
            'INSERT INTO link_preview_cards
             (url, title, description, image, provider_name, provider_url, type, status, fetched_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(url) DO UPDATE SET
               title = excluded.title,
               description = excluded.description,
               image = excluded.image,
               provider_name = excluded.provider_name,
               provider_url = excluded.provider_url,
               type = excluded.type,
               status = excluded.status,
               fetched_at = excluded.fetched_at,
               expires_at = excluded.expires_at'
        )->execute([
            $url,
            isset($card['title']) ? mb_substr((string) $card['title'], 0, 500) : null,
            isset($card['description']) ? mb_substr((string) $card['description'], 0, 1000) : null,
            isset($card['image']) ? mb_substr((string) $card['image'], 0, 800) : null,
            isset($card['provider_name']) ? mb_substr((string) $card['provider_name'], 0, 200) : null,
            isset($card['provider_url']) ? mb_substr((string) $card['provider_url'], 0, 400) : null,
            (string) ($card['type'] ?? 'link'),
            (string) ($card['status'] ?? 'ok'),
            $now,
            $expires,
        ]);
    } catch (Throwable $e) {
        // ignore cache write failures
    }
}

/**
 * Resolve a redirect Location against the current request URL.
 */
function ap_link_preview_resolve_redirect(string $fromUrl, string $location): ?string
{
    $location = trim($location);
    if ($location === '') {
        return null;
    }
    if (str_starts_with($location, 'https://') || str_starts_with($location, 'http://')) {
        $next = $location;
    } elseif (str_starts_with($location, '//')) {
        $scheme = parse_url($fromUrl, PHP_URL_SCHEME) ?: 'https';
        $next = $scheme . ':' . $location;
    } else {
        $parts = parse_url($fromUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? (':' . $parts['port']) : '');
        if (str_starts_with($location, '/')) {
            $next = $origin . $location;
        } else {
            $path = (string) ($parts['path'] ?? '/');
            $dir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
            $next = $origin . $dir . $location;
        }
    }
    $next = ap_link_preview_normalize_url($next);
    if ($next === '' || !ap_link_preview_url_allowed($next)) {
        return null;
    }
    return $next;
}

/**
 * Human title guess from a URL path slug when OG fetch is blocked/unavailable.
 * e.g. /story/meta-failed-to-catch-… → "Meta Failed To Catch…"
 */
function ap_link_preview_title_from_url(string $url): ?string
{
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
    $path = rawurldecode($path);
    $segments = array_values(array_filter(explode('/', $path), static fn($s) => $s !== ''));
    if (!$segments) {
        return null;
    }
    // Prefer the last meaningful segment (article slug), skip boring tails.
    $skip = ['index.html', 'index.htm', 'amp', 'amp.html'];
    $slug = '';
    for ($i = count($segments) - 1; $i >= 0; $i--) {
        $cand = strtolower($segments[$i]);
        if (in_array($cand, $skip, true) || preg_match('/^\d+$/', $cand)) {
            continue;
        }
        $slug = $segments[$i];
        break;
    }
    if ($slug === '') {
        return null;
    }
    $slug = preg_replace('/\.(html?|php|aspx?)$/i', '', $slug) ?? $slug;
    $slug = str_replace(['-', '_', '+'], ' ', $slug);
    $slug = preg_replace('/\s+/', ' ', trim($slug)) ?? '';
    if (strlen($slug) < 8) {
        return null;
    }
    // Title-case lightly; keep short all-caps tokens (DHS, AI, LGBTQ).
    $words = explode(' ', $slug);
    $out = [];
    foreach ($words as $w) {
        if ($w === '') {
            continue;
        }
        if (strlen($w) <= 4 && strtoupper($w) === $w) {
            $out[] = $w;
        } else {
            $out[] = mb_strtoupper(mb_substr($w, 0, 1)) . mb_strtolower(mb_substr($w, 1));
        }
    }
    $title = implode(' ', $out);
    if (mb_strlen($title) > 120) {
        $title = mb_substr($title, 0, 117) . '…';
    }
    return $title !== '' ? $title : null;
}

function ap_link_preview_http_get(string $url, int $timeoutSec = 4, int $maxBytes = 524288): ?string
{
    if (!ap_link_preview_url_allowed($url)) {
        return null;
    }
    // Manual redirects only — each Location is SSRF-checked (never CURLOPT_FOLLOWLOCATION).
    $current = ap_link_preview_normalize_url($url);
    $maxHops = 5;
    for ($hop = 0; $hop <= $maxHops; $hop++) {
        if ($current === '' || !ap_link_preview_url_allowed($current)) {
            return null;
        }
        $ch = curl_init($current);
        if ($ch === false) {
            return null;
        }
        $buf = '';
        $headers = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$headers): int {
                $headers .= $header;
                return strlen($header);
            },
            CURLOPT_CONNECTTIMEOUT => min(4, $timeoutSec),
            CURLOPT_TIMEOUT => $timeoutSec,
            // Identifiable, but browser-compatible enough to clear soft bot walls.
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; mkultra.monster-link-preview/1.1; +https://mkultra.monster)',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$buf, $maxBytes): int {
                $buf .= $data;
                if (strlen($buf) > $maxBytes) {
                    return 0; // abort
                }
                return strlen($data);
            },
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.8',
            ],
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 300 && $code < 400) {
            if ($hop >= $maxHops) {
                return null;
            }
            if (!preg_match('/^Location:\s*(.+)$/im', $headers, $lm)) {
                return null;
            }
            $next = ap_link_preview_resolve_redirect($current, trim($lm[1]));
            if ($next === null || $next === $current) {
                return null;
            }
            $current = $next;
            continue;
        }
        if ($ok === false || $code < 200 || $code >= 400 || $buf === '') {
            return null;
        }
        return $buf;
    }
    return null;
}

/**
 * @return array{url:string,title:?string,description:?string,image:?string,provider_name:?string,provider_url:?string,type:string,status:string}|null
 */
function ap_link_preview_fetch_youtube(string $url): ?array
{
    $id = ap_link_preview_youtube_id($url);
    if ($id === null) {
        return null;
    }
    $watch = 'https://www.youtube.com/watch?v=' . $id;
    $oembedUrl = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode($watch);
    $json = ap_link_preview_http_get($oembedUrl, 4, 65536);
    $title = null;
    $author = null;
    if (is_string($json)) {
        $data = json_decode($json, true);
        if (is_array($data)) {
            $title = isset($data['title']) ? (string) $data['title'] : null;
            $author = isset($data['author_name']) ? (string) $data['author_name'] : null;
        }
    }
    return [
        'url' => $watch,
        'title' => $title ?: 'YouTube video',
        'description' => $author !== null && $author !== '' ? $author : null,
        'image' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
        'provider_name' => 'YouTube',
        'provider_url' => 'https://www.youtube.com/',
        'type' => 'video',
        'status' => 'ok',
    ];
}

/**
 * Parse basic OG / twitter meta tags from HTML.
 *
 * @return array{url:string,title:?string,description:?string,image:?string,provider_name:?string,provider_url:?string,type:string,status:string}|null
 */
function ap_link_preview_fetch_og(string $url): ?array
{
    $html = ap_link_preview_http_get($url, 5, 524288);
    if ($html === null || $html === '') {
        return null;
    }
    $getMeta = static function (string $html, string $prop) : ?string {
        $propEsc = preg_quote($prop, '#');
        // property="og:…" content="…"
        if (preg_match('#<meta[^>]+(?:property|name)=["\']' . $propEsc . '["\'][^>]+content=["\']([^"\']+)["\'][^>]*>#i', $html, $m)
            || preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . $propEsc . '["\'][^>]*>#i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return null;
    };
    $title = $getMeta($html, 'og:title') ?? $getMeta($html, 'twitter:title');
    if ($title === null && preg_match('#<title[^>]*>([^<]+)</title>#i', $html, $tm)) {
        $title = html_entity_decode(trim($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $desc = $getMeta($html, 'og:description') ?? $getMeta($html, 'twitter:description') ?? $getMeta($html, 'description');
    $image = $getMeta($html, 'og:image') ?? $getMeta($html, 'twitter:image') ?? $getMeta($html, 'twitter:image:src');
    $site = $getMeta($html, 'og:site_name');
    $ogUrl = $getMeta($html, 'og:url') ?? $url;
    $ogType = strtolower((string) ($getMeta($html, 'og:type') ?? 'website'));
    $type = (str_contains($ogType, 'video')) ? 'video' : 'link';

    if ($image !== null && $image !== '' && !str_starts_with($image, 'https://') && !str_starts_with($image, 'http://')) {
        // Resolve relative image against page URL
        $base = parse_url($url);
        if (is_array($base) && !empty($base['scheme']) && !empty($base['host'])) {
            if (str_starts_with($image, '//')) {
                $image = $base['scheme'] . ':' . $image;
            } elseif (str_starts_with($image, '/')) {
                $image = $base['scheme'] . '://' . $base['host'] . $image;
            } else {
                $image = $base['scheme'] . '://' . $base['host'] . '/' . ltrim($image, '/');
            }
        }
    }
    if ($image !== null && !str_starts_with((string) $image, 'https://')) {
        $image = null; // only hotlink https images
    }

    $host = parse_url($url, PHP_URL_HOST);
    $provider = $site ?: (is_string($host) ? $host : null);
    $providerUrl = is_string($host) ? ('https://' . $host . '/') : null;

    if (($title === null || $title === '') && ($image === null || $image === '')) {
        return null;
    }

    return [
        'url' => is_string($ogUrl) && str_starts_with($ogUrl, 'http') ? $ogUrl : $url,
        'title' => $title,
        'description' => $desc,
        'image' => $image,
        'provider_name' => $provider,
        'provider_url' => $providerUrl,
        'type' => $type,
        'status' => 'ok',
    ];
}

/**
 * Resolve a preview card for a URL (cache → YouTube → OG).
 *
 * @return array{url:string,title:?string,description:?string,image:?string,provider_name:?string,provider_url:?string,type:string,status:string}|null
 */
function ap_link_preview_for_url(string $url, bool $allowFetch = true): ?array
{
    $url = ap_link_preview_normalize_url($url);
    if ($url === '' || !ap_link_preview_url_allowed($url)) {
        return null;
    }
    $cached = ap_link_preview_cache_get($url);
    if ($cached !== null) {
        return ($cached['status'] ?? '') === 'ok' ? $cached : null;
    }
    if (!$allowFetch) {
        return null;
    }

    $card = null;
    if (ap_link_preview_youtube_id($url) !== null) {
        $card = ap_link_preview_fetch_youtube($url);
    }
    if ($card === null) {
        $card = ap_link_preview_fetch_og($url);
    }
    if ($card === null) {
        // Short fail TTL — many "fails" are soft blocks/redirects that recover.
        ap_link_preview_cache_put([
            'url' => $url,
            'title' => null,
            'description' => null,
            'image' => null,
            'provider_name' => null,
            'provider_url' => null,
            'type' => 'link',
            'status' => 'fail',
        ], 2 * 3600);
        return null;
    }
    ap_link_preview_cache_put($card, 7 * 86400);
    return $card;
}

/**
 * Mastodon PreviewCard entity (or null).
 *
 * @param array<string,mixed>|null $card
 * @return array<string,mixed>|null
 */
function ap_masto_preview_card(?array $card): ?array
{
    if ($card === null || ($card['status'] ?? 'ok') !== 'ok') {
        return null;
    }
    $url = (string) ($card['url'] ?? '');
    if ($url === '') {
        return null;
    }
    $type = (string) ($card['type'] ?? 'link');
    if (!in_array($type, ['link', 'photo', 'video', 'rich'], true)) {
        $type = 'link';
    }
    $isVideo = $type === 'video';
    return [
        'url' => $url,
        'title' => (string) ($card['title'] ?? ''),
        'description' => (string) ($card['description'] ?? ''),
        'type' => $type,
        'author_name' => '',
        'author_url' => '',
        'provider_name' => (string) ($card['provider_name'] ?? ''),
        'provider_url' => (string) ($card['provider_url'] ?? ''),
        'html' => '',
        'width' => $isVideo ? 480 : 0,
        'height' => $isVideo ? 270 : 0,
        'image' => isset($card['image']) && is_string($card['image']) ? $card['image'] : null,
        'embed_url' => '',
        'blurhash' => null,
        'language' => null,
        'published_at' => null,
        'authors' => [],
    ];
}

/**
 * HTML fragment for admin / AP profile note pages.
 *
 * @param bool $interactive When false, render a <div> (safe inside outer <a class="post"> list cards).
 */
function ap_link_preview_html(?array $card, bool $interactive = true): string
{
    $entity = ap_masto_preview_card($card);
    if ($entity === null) {
        return '';
    }
    $url = htmlspecialchars((string) $entity['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $title = htmlspecialchars((string) $entity['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $desc = htmlspecialchars((string) $entity['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $provider = htmlspecialchars((string) $entity['provider_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $image = isset($entity['image']) && is_string($entity['image']) && str_starts_with($entity['image'], 'https://')
        ? htmlspecialchars($entity['image'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        : '';
    $imgHtml = $image !== ''
        ? '<div class="link-card__media"><img src="' . $image . '" alt="" loading="lazy" referrerpolicy="no-referrer"></div>'
        : '';
    $descHtml = $desc !== '' ? '<div class="link-card__desc">' . $desc . '</div>' : '';
    $provHtml = $provider !== '' ? '<div class="link-card__provider">' . $provider . '</div>' : '';
    $titleHtml = $title !== '' ? $title : $url;
    $inner = $imgHtml
        . '<div class="link-card__body">'
        . $provHtml
        . '<div class="link-card__title">' . $titleHtml . '</div>'
        . $descHtml
        . '</div>';
    if ($interactive) {
        return '<a class="link-card" href="' . $url . '" target="_blank" rel="nofollow noopener noreferrer">'
            . $inner . '</a>';
    }
    // Non-interactive: profile outbox list wraps each post in <a class="post">
    return '<div class="link-card link-card--static" data-url="' . $url . '">' . $inner . '</div>';
}

/**
 * Resolve card for a status from its text (and optional skip if media present).
 *
 * @return array<string,mixed>|null Mastodon PreviewCard
 */
function ap_link_preview_card_for_status_text(string $textOrHtml, bool $hasMedia = false, bool $allowFetch = true): ?array
{
    if ($hasMedia) {
        return null;
    }
    $url = ap_link_preview_extract_url($textOrHtml);
    if ($url === null) {
        return null;
    }
    return ap_masto_preview_card(ap_link_preview_for_url($url, $allowFetch));
}

// Direct HTTP hits disabled (SSRF surface). Link previews stay internal via require.
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Forbidden';
    exit;
}
