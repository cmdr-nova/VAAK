<?php
/**
 * Per-account inbound RSS/Atom subscriptions → Home timeline mix.
 * Polling is CLI-only (ap-rss-poll.php); never fetch feeds on page load.
 */
declare(strict_types=1);

function ap_rss_migrate(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = ap_db();
    try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS rss_feeds (
            id BIGSERIAL PRIMARY KEY,
            owner_user_id INTEGER NOT NULL,
            feed_url TEXT NOT NULL,
            site_url TEXT NOT NULL DEFAULT '',
            title TEXT NOT NULL DEFAULT '',
            favicon_url TEXT NOT NULL DEFAULT '',
            etag TEXT NOT NULL DEFAULT '',
            last_modified TEXT NOT NULL DEFAULT '',
            last_fetched_at TIMESTAMPTZ NULL,
            last_error TEXT NOT NULL DEFAULT '',
            enabled BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            UNIQUE (owner_user_id, feed_url)
        )"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS rss_items (
            id BIGSERIAL PRIMARY KEY,
            feed_id BIGINT NOT NULL REFERENCES rss_feeds(id) ON DELETE CASCADE,
            guid TEXT NOT NULL,
            url TEXT NOT NULL DEFAULT '',
            title TEXT NOT NULL DEFAULT '',
            summary_text TEXT NOT NULL DEFAULT '',
            image_url TEXT NOT NULL DEFAULT '',
            published_at TIMESTAMPTZ NULL,
            ingested_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            mirror_note_id TEXT NULL,
            UNIQUE (feed_id, guid)
        )"
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_rss_feeds_owner ON rss_feeds (owner_user_id, enabled)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_rss_items_feed_pub ON rss_items (feed_id, published_at DESC NULLS LAST)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_rss_items_pub ON rss_items (published_at DESC NULLS LAST)');
    } catch (Throwable $e) {
        // Tables may be provisioned by ops when the app role cannot CREATE.
        if (stripos($e->getMessage(), 'permission denied') === false
            && stripos($e->getMessage(), 'already exists') === false) {
            throw $e;
        }
    }
}

/** @return list<array<string,mixed>> */
function ap_rss_feeds_for_owner(int $ownerUserId): array
{
    ap_rss_migrate();
    if ($ownerUserId < 1) {
        return [];
    }
    $st = ap_db()->prepare(
        'SELECT f.*,
                (SELECT COUNT(*) FROM rss_items i WHERE i.feed_id = f.id) AS item_count
         FROM rss_feeds f
         WHERE f.owner_user_id = ?
         ORDER BY f.created_at DESC, f.id DESC'
    );
    $st->execute([$ownerUserId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ap_rss_feed_by_id(int $feedId, ?int $ownerUserId = null): ?array
{
    ap_rss_migrate();
    if ($feedId < 1) {
        return null;
    }
    if ($ownerUserId !== null && $ownerUserId > 0) {
        $st = ap_db()->prepare('SELECT * FROM rss_feeds WHERE id = ? AND owner_user_id = ?');
        $st->execute([$feedId, $ownerUserId]);
    } else {
        $st = ap_db()->prepare('SELECT * FROM rss_feeds WHERE id = ?');
        $st->execute([$feedId]);
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ap_rss_item_by_id(int $itemId, ?int $ownerUserId = null): ?array
{
    ap_rss_migrate();
    if ($itemId < 1) {
        return null;
    }
    if ($ownerUserId !== null && $ownerUserId > 0) {
        $st = ap_db()->prepare(
            'SELECT i.*, f.title AS feed_title, f.favicon_url AS feed_favicon, f.site_url AS feed_site_url,
                    f.feed_url AS feed_url, f.owner_user_id, f.id AS feed_id
             FROM rss_items i
             JOIN rss_feeds f ON f.id = i.feed_id
             WHERE i.id = ? AND f.owner_user_id = ?'
        );
        $st->execute([$itemId, $ownerUserId]);
    } else {
        $st = ap_db()->prepare(
            'SELECT i.*, f.title AS feed_title, f.favicon_url AS feed_favicon, f.site_url AS feed_site_url,
                    f.feed_url AS feed_url, f.owner_user_id, f.id AS feed_id
             FROM rss_items i
             JOIN rss_feeds f ON f.id = i.feed_id
             WHERE i.id = ?'
        );
        $st->execute([$itemId]);
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Short display name for a feed (e.g. "IGN Articles" → "IGN").
 */
function ap_rss_display_title(string $title): string
{
    $title = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($title === '') {
        return 'RSS';
    }
    // Strip common channel suffixes once (case-insensitive).
    // Keep words like "News" in brand names (e.g. "BBC News").
    $cleaned = preg_replace(
        '/[\s\-–—|:]*\b(articles?|rss|atom|feeds?|blog)\s*$/iu',
        '',
        $title
    );
    $cleaned = trim((string) $cleaned, " \t\n\r\0\x0B\-–—|:");
    return $cleaned !== '' ? $cleaned : $title;
}

/**
 * Prefer a full-size image URL for timeline media (Reddit previews → i.redd.it, bump tiny widths).
 */
function ap_rss_upgrade_image_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '' || !preg_match('#^https://#i', $url)) {
        return '';
    }
    // preview.redd.it/<id>.<ext>?… → i.redd.it/<id>.<ext> (full image for native posts)
    if (preg_match('#^https://preview\.redd\.it/([A-Za-z0-9]+)(\.[A-Za-z0-9]+)(?:\?|$)#i', $url, $m)) {
        return 'https://i.redd.it/' . $m[1] . $m[2];
    }
    // Tiny Reddit thumbs: raise width so the media row is usable even before re-poll.
    if (preg_match('#^https://(?:external-)?preview\.redd\.it/#i', $url)
        && preg_match('/[?&]width=(\d+)/i', $url, $wm)
        && (int) $wm[1] < 640
    ) {
        $url = preg_replace('/([?&])width=\d+/i', '${1}width=960', $url) ?? $url;
        $url = preg_replace('/([?&])height=\d+/i', '', $url) ?? $url;
        $url = preg_replace('/([?&])crop=[^&]*/i', '', $url) ?? $url;
        $url = preg_replace('/\?&/', '?', $url) ?? $url;
        $url = preg_replace('/&&+/', '&', $url) ?? $url;
        $url = rtrim($url, '?&');
    }
    return $url;
}

/**
 * Collect https image candidates from HTML (img src + direct media hrefs).
 *
 * @return list<string>
 */
function ap_rss_images_from_html(string $html): array
{
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $out = [];
    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
        foreach ($m[1] as $src) {
            $src = trim((string) $src);
            if (preg_match('#^https://#i', $src)) {
                $out[] = $src;
            }
        }
    }
    // Reddit Atom wraps the direct file as [link] → i.redd.it / v.redd.it
    if (preg_match_all('#https://(?:i|v)\.redd\.it/[A-Za-z0-9._/?=&%-]+#i', $html, $rm)) {
        foreach ($rm[0] as $src) {
            $out[] = rtrim((string) $src, '.,);]');
        }
    }
    return $out;
}

/**
 * Score candidates and return the best https image URL (empty if none).
 *
 * @param list<string> $candidates
 */
function ap_rss_pick_best_image(array $candidates): string
{
    $best = '';
    $bestScore = -1;
    foreach ($candidates as $raw) {
        $url = ap_rss_upgrade_image_url((string) $raw);
        if ($url === '') {
            continue;
        }
        $score = 0;
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === 'i.redd.it' || $host === 'i.imgur.com') {
            $score += 100;
        } elseif (str_contains($host, 'preview.redd.it')) {
            $score += 40;
        } elseif (str_contains($host, 'redd.it') || str_contains($host, 'imgur.com')) {
            $score += 60;
        } else {
            $score += 20;
        }
        if (preg_match('/[?&]width=(\d+)/i', $url, $wm)) {
            $score += min(50, (int) ((int) $wm[1] / 20));
        }
        if (preg_match('/\.(jpe?g|png|webp|gif)(?:$|[?#])/i', $url)) {
            $score += 10;
        }
        // Prefer non-square 140 thumbs.
        if (preg_match('/[?&]width=1[0-4]\d\b/i', $url)) {
            $score -= 30;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $url;
        }
    }
    return $best;
}

/** Pull Reddit selftext from Atom/RSS HTML (`<div class="md">…</div>`). */
function ap_rss_extract_reddit_selftext(string $html): string
{
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($html === '') {
        return '';
    }
    if (preg_match('/<div class="md">(.*?)<\/div>/is', $html, $m)) {
        $text = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return $text;
    }
    return '';
}

/**
 * Drop Reddit “submitted by /u/… [link] [comments]” chrome while keeping selftext.
 * Older ingest rows often append that footer after the real body — strip the tail, do not nuke all.
 */
function ap_rss_strip_reddit_boilerplate(string $summary): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $summary) ?? $summary);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/\s*submitted by\s+\/?u\/\S+.*/iu', '', $s) ?? $s;
    $s = preg_replace('/\s*\[link\]\s*\[comments\]\s*$/iu', '', $s) ?? $s;
    $s = trim($s);
    if ($s === '' || preg_match('/^submitted by\b/i', $s) || preg_match('/^\[link\]/i', $s)) {
        return '';
    }
    return $s;
}

/**
 * Build summary_text from feed HTML/plaintext (prefer Reddit selftext when present).
 */
function ap_rss_summary_from_html(string $html, string $itemUrl = ''): string
{
    $host = strtolower((string) (parse_url($itemUrl, PHP_URL_HOST) ?: ''));
    $isReddit = $host !== '' && (str_ends_with($host, 'reddit.com') || str_ends_with($host, 'redd.it'));
    if ($isReddit) {
        $self = ap_rss_extract_reddit_selftext($html);
        if ($self !== '') {
            return $self;
        }
    }
    $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
    return ap_rss_strip_reddit_boilerplate($plain);
}

/**
 * True when the item is mostly media (image post) rather than an article with a hero image.
 */
function ap_rss_item_is_media_forward(string $summary, string $imageUrl, string $itemUrl = ''): bool
{
    $imageUrl = trim($imageUrl);
    if ($imageUrl === '' || !preg_match('#^https://#i', $imageUrl)) {
        return false;
    }
    $clean = ap_rss_display_summary($summary);
    $hasRealText = mb_strlen($clean) >= 72;
    $host = strtolower((string) (parse_url($itemUrl, PHP_URL_HOST) ?: ''));
    $isReddit = $host !== '' && (str_ends_with($host, 'reddit.com') || str_ends_with($host, 'redd.it'));
    $imgHost = strtolower((string) (parse_url($imageUrl, PHP_URL_HOST) ?: ''));
    $redditImage = $imgHost === 'i.redd.it'
        || str_contains($imgHost, 'preview.redd.it')
        || $imgHost === 'i.imgur.com'
        || str_contains($imgHost, 'redd.it');

    // Reddit image posts → media row. Text posts with selftext keep the summary (even if a thumb exists).
    if ($isReddit) {
        return $redditImage && !$hasRealText;
    }
    if ($redditImage && !$hasRealText) {
        return true;
    }
    if ($clean === '') {
        return true;
    }
    // Very short caption beside a real image → treat as media post.
    if (mb_strlen($clean) <= 96) {
        return true;
    }
    return false;
}

/** Clean summary for Home cards (keeps Reddit selftext; drops footer chrome). */
function ap_rss_display_summary(string $summary): string
{
    return ap_rss_strip_reddit_boilerplate($summary);
}

function ap_rss_is_mirror_note_id(string $noteId): bool
{
    return ap_rss_item_by_mirror_note_id($noteId) !== null;
}

/**
 * Note ids used as RSS interaction mirrors (hidden from Your Posts).
 *
 * @return list<string>
 */
function ap_rss_mirror_note_ids_for_owner(int $ownerUserId): array
{
    ap_rss_migrate();
    if ($ownerUserId < 1) {
        return [];
    }
    $st = ap_db()->prepare(
        'SELECT i.mirror_note_id
         FROM rss_items i
         JOIN rss_feeds f ON f.id = i.feed_id
         WHERE f.owner_user_id = ?
           AND i.mirror_note_id IS NOT NULL
           AND i.mirror_note_id <> \'\''
    );
    $st->execute([$ownerUserId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
        $id = rtrim(trim((string) $id), '/');
        if ($id !== '') {
            $out[$id] = true;
            $out[$id . '/'] = true;
        }
    }
    return array_keys($out);
}

/** Look up an RSS item by the local mirror note created for boost/quote/fav. */
function ap_rss_item_by_mirror_note_id(string $noteId): ?array
{
    ap_rss_migrate();
    $noteId = rtrim(trim($noteId), '/');
    if ($noteId === '' || !str_starts_with($noteId, 'https://')) {
        return null;
    }
    $st = ap_db()->prepare(
        'SELECT i.*, f.title AS feed_title, f.favicon_url AS feed_favicon, f.site_url AS feed_site_url,
                f.feed_url AS feed_url, f.owner_user_id, f.id AS feed_id
         FROM rss_items i
         JOIN rss_feeds f ON f.id = i.feed_id
         WHERE i.mirror_note_id = ? OR i.mirror_note_id = ?
         LIMIT 1'
    );
    $st->execute([$noteId, $noteId . '/']);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** Best-guess title/site when we save a feed before the first successful fetch. */
function ap_rss_provisional_meta(string $feedUrl): array
{
    $parts = parse_url($feedUrl);
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    $site = ($host !== '') ? ('https://' . $host . '/') : '';
    $title = $host !== '' ? $host : $feedUrl;
    if ($host !== '' && (str_ends_with($host, '.tumblr.com') || $host === 'www.tumblr.com')) {
        $blog = preg_replace('/\.tumblr\.com$/i', '', $host) ?? $host;
        $blog = preg_replace('/^www\./i', '', $blog) ?? $blog;
        if ($blog !== '' && $blog !== 'tumblr' && $blog !== 'www') {
            $title = $blog;
        }
    }
    // RSSHub Pixiv user route → readable provisional title + real Pixiv site link.
    $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
    if (preg_match('#/pixiv/user/(\d+)#i', $path, $m)) {
        $title = 'Pixiv user ' . $m[1];
        $site = 'https://www.pixiv.net/users/' . $m[1];
        return [
            'title' => $title,
            'site_url' => $site,
            'favicon_url' => 'https://www.pixiv.net/favicon.ico',
        ];
    }
    return [
        'title' => $title,
        'site_url' => $site,
        'favicon_url' => $site !== '' ? ($site . 'favicon.ico') : '',
    ];
}

/**
 * Known feed path (or Tumblr blog → /rss) that we can subscribe to without a live fetch.
 * Used so 429s still create a local subscription; the poller fills items later.
 */
function ap_rss_resolve_deferrable_feed_url(string $url): ?string
{
    if (ap_rss_url_looks_like_feed_path($url)) {
        return $url;
    }
    if (!ap_rss_host_is_tumblr($url)) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }
    $host = strtolower((string) $parts['host']);
    // Blog host only (skip tumblr.com explore/search pages).
    if ($host === 'tumblr.com' || $host === 'www.tumblr.com') {
        return null;
    }
    return 'https://' . $host . '/rss';
}

/**
 * Pixiv→RSSHub rewrite is off by default (needs a usable self-hosted instance + PIXIV_REFRESHTOKEN).
 * Re-enable later with AP_RSSHUB_ENABLED=1 and optional AP_RSSHUB_BASE=https://your-rsshub.
 */
function ap_rss_rsshub_enabled(): bool
{
    $v = strtolower(trim((string) (getenv('AP_RSSHUB_ENABLED') ?: '')));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Public/self-hosted RSSHub base (no trailing slash).
 * Override with AP_RSSHUB_BASE when the default public demo blocks your IP or lacks Pixiv tokens.
 */
function ap_rss_rsshub_base(): string
{
    $raw = trim((string) (getenv('AP_RSSHUB_BASE') ?: ''));
    if ($raw === '' || !preg_match('#^https://#i', $raw)) {
        $raw = 'https://rsshub.app';
    }
    return rtrim($raw, '/');
}

function ap_rss_host_is_pixiv(string $url): bool
{
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    return $host === 'pixiv.net' || str_ends_with($host, '.pixiv.net');
}

/**
 * Map site URLs that have no native feed into an RSSHub route (https only).
 * Pixiv: https://www.pixiv.net/users/15288095 → {RSSHUB}/pixiv/user/15288095
 * No-op unless AP_RSSHUB_ENABLED is set.
 */
function ap_rss_rewrite_via_rsshub(string $url): ?string
{
    if (!ap_rss_rsshub_enabled()) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }
    $host = strtolower((string) $parts['host']);
    $path = (string) ($parts['path'] ?? '');

    // Already an RSSHub pixiv route — leave as-is.
    if (preg_match('#^https://[^/]+/pixiv/user/\d+#i', $url)) {
        return null;
    }

    if ($host === 'pixiv.net' || str_ends_with($host, '.pixiv.net')) {
        // /users/123, /en/users/123, optional trailing /artworks etc.
        if (preg_match('#(?:^|/)(?:en/)?users/(\d+)(?:/|$)#i', $path, $m)) {
            return ap_rss_rsshub_base() . '/pixiv/user/' . $m[1];
        }
        // member.php?id=123 (legacy)
        $q = [];
        parse_str((string) ($parts['query'] ?? ''), $q);
        $legacyId = trim((string) ($q['id'] ?? ''));
        if ($legacyId !== '' && ctype_digit($legacyId) && str_contains(strtolower($path), 'member.php')) {
            return ap_rss_rsshub_base() . '/pixiv/user/' . $legacyId;
        }
    }

    return null;
}

/**
 * Insert a feed row with no items yet; poller/refresh will fetch when the host cools down.
 *
 * @return array{ok:bool,error?:string,feed_id?:int,deferred?:bool,discovered?:bool}
 */
function ap_rss_add_feed_deferred(int $ownerUserId, string $feedUrl, string $lastError, bool $discovered = false): array
{
    $dup = ap_db()->prepare('SELECT id FROM rss_feeds WHERE owner_user_id = ? AND feed_url = ?');
    $dup->execute([$ownerUserId, $feedUrl]);
    if ($dup->fetchColumn()) {
        return ['ok' => false, 'error' => 'That feed is already added'];
    }
    $meta = ap_rss_provisional_meta($feedUrl);
    $st = ap_db()->prepare(
        'INSERT INTO rss_feeds
         (owner_user_id, feed_url, site_url, title, favicon_url, etag, last_modified, last_fetched_at, last_error, enabled, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, TRUE, NOW())
         RETURNING id'
    );
    $st->execute([
        $ownerUserId,
        $feedUrl,
        $meta['site_url'],
        $meta['title'],
        $meta['favicon_url'],
        '',
        '',
        mb_substr($lastError !== '' ? $lastError : 'Waiting for first fetch', 0, 500),
    ]);
    $feedId = (int) $st->fetchColumn();
    return [
        'ok' => true,
        'feed_id' => $feedId,
        'deferred' => true,
        'discovered' => $discovered,
    ];
}

/**
 * @return array{ok:bool,error?:string,feed_id?:int,discovered?:bool,deferred?:bool}
 */
function ap_rss_add_feed(int $ownerUserId, string $rawUrl): array
{
    ap_rss_migrate();
    if ($ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Not signed in'];
    }
    $url = ap_rss_normalize_url($rawUrl);
    if ($url === null) {
        return ['ok' => false, 'error' => 'Enter an https:// feed or site URL'];
    }
    // Pixiv user pages → RSSHub /pixiv/user/:id (public or AP_RSSHUB_BASE instance).
    $viaRsshub = false;
    $rewritten = ap_rss_rewrite_via_rsshub($url);
    if ($rewritten !== null && $rewritten !== $url) {
        $url = $rewritten;
        $viaRsshub = true;
    }
    $existing = ap_db()->prepare('SELECT id FROM rss_feeds WHERE owner_user_id = ? AND feed_url = ?');
    $existing->execute([$ownerUserId, $url]);
    if ($existing->fetchColumn()) {
        return ['ok' => false, 'error' => 'That feed is already added'];
    }
    // Tumblr homepage → canonical /rss (may already be subscribed).
    $tumblrRss = ap_rss_resolve_deferrable_feed_url($url);
    if ($tumblrRss !== null && $tumblrRss !== $url) {
        $existing->execute([$ownerUserId, $tumblrRss]);
        if ($existing->fetchColumn()) {
            return ['ok' => false, 'error' => 'That feed is already added'];
        }
    }
    $countSt = ap_db()->prepare('SELECT COUNT(*) FROM rss_feeds WHERE owner_user_id = ?');
    $countSt->execute([$ownerUserId]);
    if ((int) $countSt->fetchColumn() >= 40) {
        return ['ok' => false, 'error' => 'Feed limit reached (40)'];
    }

    $discovered = $viaRsshub;
    // Interactive add: one attempt so a Tumblr 429 does not stall the page for retries.
    $fetch = ap_rss_http_get($url, '', '', ['max_attempts' => 1]);
    if ($fetch['ok'] && ap_rss_looks_like_feed($fetch['body'])) {
        $feedUrl = $url;
        $parsed = ap_rss_parse_feed($fetch['body'], $feedUrl);
    } else {
        $status = (int) ($fetch['status'] ?? 0);
        $err = trim((string) ($fetch['error'] ?? ''));
        if ($err === '') {
            $err = ap_rss_http_error_message($status, $url);
        }
        if ($viaRsshub) {
            $err = ap_rss_rsshub_failure_message($status, $fetch['body'] ?? '', $err);
        }
        $rateLimited = ($status === 429 || $status === 503);

        // Save locally on rate-limit when we already know the feed URL (Tumblr /rss, etc.).
        $deferUrl = ap_rss_resolve_deferrable_feed_url($url);
        if ($rateLimited && $deferUrl !== null) {
            return ap_rss_add_feed_deferred($ownerUserId, $deferUrl, $err, $deferUrl !== $url);
        }
        // RSSHub Pixiv (or other rewritten) URL: defer on soft failures so poller can retry.
        if ($viaRsshub && ($rateLimited || $status === 403 || $status === 502 || $status === 0)) {
            return ap_rss_add_feed_deferred($ownerUserId, $url, $err, true);
        }
        // Direct feed path with a hard fetch failure: do not stampede discovery.
        if (ap_rss_url_looks_like_feed_path($url) || $viaRsshub) {
            if ($rateLimited) {
                return ap_rss_add_feed_deferred($ownerUserId, $url, $err, $viaRsshub);
            }
            if ($status === 404 && ap_rss_host_is_youtube($url)) {
                $label = ap_rss_youtube_channel_hint($url);
                if ($label !== '' && !str_contains($err, $label)) {
                    $err = '“' . $label . '”: ' . $err;
                }
            }
            return ['ok' => false, 'error' => $err];
        }

        $alt = ap_rss_discover_feed_url($url, $fetch['body'] ?? '');
        if ($alt === null) {
            // Tumblr blog with no HTML to scrape — still subscribe to /rss deferred.
            if ($deferUrl !== null && ($rateLimited || $status >= 400)) {
                return ap_rss_add_feed_deferred($ownerUserId, $deferUrl, $err, true);
            }
            if ($status >= 400 && $err !== '') {
                return ['ok' => false, 'error' => $err];
            }
            return ['ok' => false, 'error' => 'Could not find an RSS/Atom feed at that URL'];
        }
        $discovered = true;
        $feedUrl = $alt;
        $dup = ap_db()->prepare('SELECT id FROM rss_feeds WHERE owner_user_id = ? AND feed_url = ?');
        $dup->execute([$ownerUserId, $feedUrl]);
        if ($dup->fetchColumn()) {
            return ['ok' => false, 'error' => 'That feed is already added'];
        }
        $fetch = ap_rss_http_get($feedUrl, '', '', ['max_attempts' => 1]);
        if ($fetch['ok'] && ap_rss_looks_like_feed($fetch['body'])) {
            $parsed = ap_rss_parse_feed($fetch['body'], $feedUrl);
        } else {
            $status2 = (int) ($fetch['status'] ?? 0);
            $err2 = trim((string) ($fetch['error'] ?? ''));
            if ($err2 === '') {
                $err2 = ap_rss_http_error_message($status2, $feedUrl);
            }
            if ($status2 === 429 || $status2 === 503 || ap_rss_url_looks_like_feed_path($feedUrl)) {
                return ap_rss_add_feed_deferred($ownerUserId, $feedUrl, $err2, true);
            }
            return ['ok' => false, 'error' => $err2 !== '' ? $err2 : 'Found a feed link but could not load it'];
        }
    }
    if ($parsed === null) {
        return ['ok' => false, 'error' => 'Feed XML could not be parsed'];
    }

    $favicon = ap_rss_guess_favicon($parsed['site_url'] !== '' ? $parsed['site_url'] : $feedUrl, $parsed['image_url']);
    $st = ap_db()->prepare(
        'INSERT INTO rss_feeds
         (owner_user_id, feed_url, site_url, title, favicon_url, etag, last_modified, last_fetched_at, last_error, enabled, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, TRUE, NOW())
         RETURNING id'
    );
    $st->execute([
        $ownerUserId,
        $feedUrl,
        $parsed['site_url'],
        $parsed['title'] !== '' ? $parsed['title'] : $feedUrl,
        $favicon,
        $fetch['etag'],
        $fetch['last_modified'],
        '',
    ]);
    $feedId = (int) $st->fetchColumn();
    ap_rss_upsert_items($feedId, $parsed['items']);
    return ['ok' => true, 'feed_id' => $feedId, 'discovered' => $discovered, 'deferred' => false];
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_rss_remove_feed(int $ownerUserId, int $feedId): array
{
    ap_rss_migrate();
    if ($ownerUserId < 1 || $feedId < 1) {
        return ['ok' => false, 'error' => 'Invalid feed'];
    }
    $st = ap_db()->prepare('DELETE FROM rss_feeds WHERE id = ? AND owner_user_id = ?');
    $st->execute([$feedId, $ownerUserId]);
    return $st->rowCount() > 0 ? ['ok' => true] : ['ok' => false, 'error' => 'Feed not found'];
}

/**
 * @return array{ok:bool,error?:string,added?:int,unchanged?:bool}
 */
function ap_rss_refresh_feed(int $feedId, ?int $ownerUserId = null): array
{
    ap_rss_migrate();
    $feed = ap_rss_feed_by_id($feedId, $ownerUserId);
    if ($feed === null) {
        return ['ok' => false, 'error' => 'Feed not found'];
    }
    $fetch = ap_rss_http_get(
        (string) $feed['feed_url'],
        (string) ($feed['etag'] ?? ''),
        (string) ($feed['last_modified'] ?? '')
    );
    if ($fetch['status'] === 304) {
        ap_db()->prepare(
            'UPDATE rss_feeds SET last_fetched_at = NOW(), last_error = ? WHERE id = ?'
        )->execute(['', $feedId]);
        return ['ok' => true, 'added' => 0, 'unchanged' => true];
    }
    if (!$fetch['ok'] || !ap_rss_looks_like_feed($fetch['body'])) {
        $err = $fetch['error'] !== '' ? $fetch['error'] : ('HTTP ' . $fetch['status']);
        ap_db()->prepare(
            'UPDATE rss_feeds SET last_fetched_at = NOW(), last_error = ? WHERE id = ?'
        )->execute([mb_substr($err, 0, 500), $feedId]);
        return ['ok' => false, 'error' => $err];
    }
    $parsed = ap_rss_parse_feed($fetch['body'], (string) $feed['feed_url']);
    if ($parsed === null) {
        ap_db()->prepare(
            'UPDATE rss_feeds SET last_fetched_at = NOW(), last_error = ? WHERE id = ?'
        )->execute(['Parse error', $feedId]);
        return ['ok' => false, 'error' => 'Parse error'];
    }
    $favicon = (string) ($feed['favicon_url'] ?? '');
    if ($favicon === '') {
        $favicon = ap_rss_guess_favicon(
            $parsed['site_url'] !== '' ? $parsed['site_url'] : (string) $feed['feed_url'],
            $parsed['image_url']
        );
    }
    $title = $parsed['title'] !== '' ? $parsed['title'] : (string) $feed['title'];
    $site = $parsed['site_url'] !== '' ? $parsed['site_url'] : (string) $feed['site_url'];
    ap_db()->prepare(
        'UPDATE rss_feeds
         SET title = ?, site_url = ?, favicon_url = ?, etag = ?, last_modified = ?,
             last_fetched_at = NOW(), last_error = ?
         WHERE id = ?'
    )->execute([
        $title,
        $site,
        $favicon,
        $fetch['etag'],
        $fetch['last_modified'],
        '',
        $feedId,
    ]);
    $added = ap_rss_upsert_items($feedId, $parsed['items']);
    return ['ok' => true, 'added' => $added, 'unchanged' => false];
}

/**
 * @param list<array{guid:string,url:string,title:string,summary_text:string,image_url:string,published_at:?string}> $items
 */
function ap_rss_upsert_items(int $feedId, array $items): int
{
    $added = 0;
    $before = ap_db()->prepare('SELECT COUNT(*) FROM rss_items WHERE feed_id = ?');
    $before->execute([$feedId]);
    $countBefore = (int) $before->fetchColumn();
    $st = ap_db()->prepare(
        'INSERT INTO rss_items (feed_id, guid, url, title, summary_text, image_url, published_at, ingested_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
         ON CONFLICT (feed_id, guid) DO UPDATE SET
           url = EXCLUDED.url,
           title = EXCLUDED.title,
           summary_text = CASE WHEN EXCLUDED.summary_text <> \'\' THEN EXCLUDED.summary_text ELSE rss_items.summary_text END,
           image_url = CASE WHEN EXCLUDED.image_url <> \'\' THEN EXCLUDED.image_url ELSE rss_items.image_url END,
           published_at = COALESCE(EXCLUDED.published_at, rss_items.published_at)'
    );
    $n = 0;
    foreach ($items as $it) {
        if (++$n > 80) {
            break;
        }
        $guid = trim((string) ($it['guid'] ?? ''));
        if ($guid === '') {
            continue;
        }
        $pub = $it['published_at'] ?? null;
        $st->execute([
            $feedId,
            mb_substr($guid, 0, 800),
            mb_substr((string) ($it['url'] ?? ''), 0, 2000),
            mb_substr((string) ($it['title'] ?? ''), 0, 500),
            mb_substr((string) ($it['summary_text'] ?? ''), 0, 4000),
            mb_substr((string) ($it['image_url'] ?? ''), 0, 2000),
            is_string($pub) && $pub !== '' ? $pub : null,
        ]);
    }
    $before->execute([$feedId]);
    $added = max(0, (int) $before->fetchColumn() - $countBefore);
    return $added;
}

/**
 * Recent RSS keys for Home ranking — even across feeds so many subscriptions
 * cannot crowd out Fediverse/Bluesky. At most one item per feed, then shuffled.
 *
 * @return list<array{uri:string,feed_id:int,published_at:?string}>
 */
function ap_rss_home_rank_keys(int $ownerUserId, int $limit = 16): array
{
    ap_rss_migrate();
    if ($ownerUserId < 1) {
        return [];
    }
    $limit = max(1, min(24, $limit));
    // Pull a wider window, then keep the newest item per feed.
    $st = ap_db()->prepare(
        "SELECT i.id, i.feed_id, i.published_at, i.url
         FROM rss_items i
         JOIN rss_feeds f ON f.id = i.feed_id
         WHERE f.owner_user_id = ? AND f.enabled = TRUE
           AND (i.published_at IS NULL OR i.published_at >= NOW() - INTERVAL '14 days')
         ORDER BY COALESCE(i.published_at, i.ingested_at) DESC, i.id DESC
         LIMIT 120"
    );
    $st->execute([$ownerUserId]);
    $byFeed = [];
    $seenUrl = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $fid = (int) ($row['feed_id'] ?? 0);
        if ($fid < 1 || isset($byFeed[$fid])) {
            continue;
        }
        $url = rtrim((string) ($row['url'] ?? ''), '/');
        if ($url !== '' && isset($seenUrl[$url])) {
            continue;
        }
        if ($url !== '') {
            $seenUrl[$url] = true;
        }
        $byFeed[$fid] = [
            'uri' => (string) (int) ($row['id'] ?? 0),
            'feed_id' => $fid,
            'published_at' => isset($row['published_at']) ? (string) $row['published_at'] : null,
        ];
    }
    $out = array_values($byFeed);
    // Shuffle so Home does not always prefer the same busy feeds first.
    if (count($out) > 1) {
        $seed = crc32((string) $ownerUserId . '|' . gmdate('Y-m-d-H'));
        mt_srand($seed);
        for ($i = count($out) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            if ($j === $i) {
                continue;
            }
            $tmp = $out[$i];
            $out[$i] = $out[$j];
            $out[$j] = $tmp;
        }
        mt_srand();
    }
    return array_slice($out, 0, $limit);
}

/** Latest items for one feed (RSS page collapsed preview). */
function ap_rss_items_for_feed(int $feedId, int $ownerUserId, int $limit = 8): array
{
    ap_rss_migrate();
    if ($feedId < 1 || $ownerUserId < 1) {
        return [];
    }
    $limit = max(1, min(20, $limit));
    $st = ap_db()->prepare(
        "SELECT i.id, i.title, i.url, i.published_at, i.ingested_at
         FROM rss_items i
         JOIN rss_feeds f ON f.id = i.feed_id
         WHERE i.feed_id = ? AND f.owner_user_id = ?
         ORDER BY COALESCE(i.published_at, i.ingested_at) DESC, i.id DESC
         LIMIT {$limit}"
    );
    $st->execute([$feedId, $ownerUserId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Synthetic status id for local-only fav/bookmark of an RSS item (never federated). */
function ap_rss_local_status_id(int $itemId): string
{
    return 'rss:' . max(0, $itemId);
}

/**
 * Delete federated mirror notes created by the old boost/quote materialize path.
 * Favourites/bookmarks for RSS are local-only and must not publish as the user.
 *
 * @return array{ok:bool,deleted:int}
 */
function ap_rss_cleanup_mirror_notes(int $ownerUserId): array
{
    ap_rss_migrate();
    if ($ownerUserId < 1) {
        return ['ok' => false, 'deleted' => 0];
    }
    $st = ap_db()->prepare(
        'SELECT i.id AS item_id, i.mirror_note_id
         FROM rss_items i
         JOIN rss_feeds f ON f.id = i.feed_id
         WHERE f.owner_user_id = ?
           AND i.mirror_note_id IS NOT NULL
           AND i.mirror_note_id <> \'\''
    );
    $st->execute([$ownerUserId]);
    $deleted = 0;
    if (!function_exists('ap_delete_local_status')) {
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
    }
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $noteId = rtrim(trim((string) ($row['mirror_note_id'] ?? '')), '/');
        $itemId = (int) ($row['item_id'] ?? 0);
        if ($noteId === '') {
            continue;
        }
        $localId = 0;
        if (function_exists('ap_masto_status_by_note_id')) {
            $mrow = ap_masto_status_by_note_id($noteId);
            if (is_array($mrow)) {
                $localId = (int) ($mrow['local_id'] ?? 0);
            }
        }
        if ($localId > 0 && function_exists('ap_delete_local_status')) {
            $res = ap_delete_local_status($localId);
            if (!empty($res['ok'])) {
                $deleted++;
            }
        }
        if ($itemId > 0) {
            ap_db()->prepare('UPDATE rss_items SET mirror_note_id = NULL WHERE id = ?')->execute([$itemId]);
        }
    }
    return ['ok' => true, 'deleted' => $deleted];
}

/**
 * @deprecated Prefer local-only fav/bookmark via ap_rss_local_status_id.
 * Kept for one release so old mirrors can still be cleaned up.
 *
 * @return array{ok:bool,error?:string,note_id?:string,local_id?:int}
 */
function ap_rss_materialize_note(int $ownerUserId, int $itemId): array
{
    ap_rss_migrate();
    $item = ap_rss_item_by_id($itemId, $ownerUserId);
    if ($item === null) {
        return ['ok' => false, 'error' => 'RSS item not found'];
    }
    $existing = trim((string) ($item['mirror_note_id'] ?? ''));
    if ($existing !== '' && str_starts_with($existing, 'https://')) {
        $local = null;
        if (function_exists('ap_masto_status_by_note_id')) {
            $row = ap_masto_status_by_note_id($existing);
            if (is_array($row)) {
                $local = (int) ($row['local_id'] ?? 0);
            }
        }
        return ['ok' => true, 'note_id' => $existing, 'local_id' => $local ?: 0];
    }
    if (!function_exists('ap_publish_status_text')) {
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
    }
    $title = trim((string) ($item['title'] ?? ''));
    $url = trim((string) ($item['url'] ?? ''));
    $summary = trim((string) ($item['summary_text'] ?? ''));
    $feedTitle = trim((string) ($item['feed_title'] ?? 'RSS'));
    if ($url === '') {
        return ['ok' => false, 'error' => 'RSS item has no link'];
    }
    $body = $title !== '' ? $title : 'Link';
    if ($summary !== '') {
        $body .= "\n\n" . mb_strimwidth($summary, 0, 280, '…', 'UTF-8');
    }
    $body .= "\n\n" . $url;
    $body .= "\n\nvia " . ($feedTitle !== '' ? $feedTitle : 'RSS');

    // ap_publish_status_text uses session actor — caller must be the feed owner.
    $res = ap_publish_status_text($body, null, null, [], '', null, null, null, 'public');
    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not create mirror note')];
    }
    $noteId = (string) ($res['note_id'] ?? '');
    $localId = (int) ($res['local_id'] ?? 0);
    if ($noteId !== '') {
        ap_db()->prepare('UPDATE rss_items SET mirror_note_id = ? WHERE id = ?')->execute([$noteId, $itemId]);
    }
    return ['ok' => true, 'note_id' => $noteId, 'local_id' => $localId];
}

function ap_rss_normalize_url(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (!preg_match('#^https?://#i', $raw)) {
        $raw = 'https://' . $raw;
    }
    if (!preg_match('#^https://#i', $raw)) {
        return null; // https only
    }
    $parts = parse_url($raw);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }
    return $raw;
}

function ap_rss_looks_like_feed(string $body): bool
{
    $snip = ltrim(substr($body, 0, 400));
    return (bool) preg_match('/<(rss|feed|rdf:RDF)\\b/i', $snip);
}

/** True when the URL path already looks like a feed (skip discovery stampedes). */
function ap_rss_url_looks_like_feed_path(string $url): bool
{
    $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
    if ($path === '') {
        return false;
    }
    if (preg_match('#/(rss|feed|atom)(/|\.xml)?$#', $path)) {
        return true;
    }
    return (bool) preg_match('#\.(rss|atom|xml)$#', $path);
}

function ap_rss_host_is_tumblr(string $url): bool
{
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    return $host !== '' && (str_ends_with($host, '.tumblr.com') || $host === 'tumblr.com' || str_ends_with($host, '.tumblr.co'));
}

/** Normalize host key for process-local fetch backoff (*.tumblr.com → tumblr.com). */
function ap_rss_host_backoff_key(string $url): string
{
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    if ($host === '') {
        return '';
    }
    // Tumblr rate-limits by edge/IP across blogs.
    if (str_ends_with($host, '.tumblr.com') || $host === 'tumblr.com') {
        return 'tumblr.com';
    }
    return $host;
}

/**
 * Process-local host backoff after 429/503 so one poll run does not hammer Tumblr.
 *
 * @return array<string,int>
 */
function &ap_rss_host_backoff_map(): array
{
    static $until = [];
    return $until;
}

function ap_rss_host_backoff_until(string $url): int
{
    $host = ap_rss_host_backoff_key($url);
    if ($host === '') {
        return 0;
    }
    $map = &ap_rss_host_backoff_map();
    return (int) ($map[$host] ?? 0);
}

function ap_rss_host_backoff_set(string $url, int $seconds): void
{
    $host = ap_rss_host_backoff_key($url);
    if ($host === '') {
        return;
    }
    $map = &ap_rss_host_backoff_map();
    $map[$host] = max((int) ($map[$host] ?? 0), time() + max(1, $seconds));
}

function ap_rss_host_is_youtube(string $url): bool
{
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    return $host !== '' && (
        $host === 'youtube.com'
        || str_ends_with($host, '.youtube.com')
        || $host === 'youtu.be'
        || str_ends_with($host, '.youtu.be')
    );
}

/** Best-effort channel label for clearer YouTube feed errors (no API key). */
function ap_rss_youtube_channel_hint(string $feedUrl): string
{
    $parts = parse_url($feedUrl);
    if (!is_array($parts)) {
        return '';
    }
    $q = [];
    parse_str((string) ($parts['query'] ?? ''), $q);
    $channelId = trim((string) ($q['channel_id'] ?? ''));
    if ($channelId === '' || !preg_match('/^UC[\w-]{20,}$/', $channelId)) {
        return '';
    }
    $page = ap_rss_http_get(
        'https://www.youtube.com/channel/' . rawurlencode($channelId),
        '',
        '',
        ['max_attempts' => 1]
    );
    if (!$page['ok'] || $page['body'] === '') {
        return '';
    }
    $html = $page['body'];
    if (preg_match('/<meta\s+property="og:title"\s+content="([^"]+)"/i', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if (preg_match('/itemprop="name"\s+content="([^"]+)"/i', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '';
}

/** Friendlier errors when an RSSHub-backed route (Pixiv, etc.) fails to load. */
function ap_rss_rsshub_failure_message(int $status, string $body, string $fallback): string
{
    $snip = strtolower(substr($body, 0, 800));
    if (str_contains($snip, 'pixiv') && (str_contains($snip, 'refresh') || str_contains($snip, 'not login') || str_contains($snip, 'config'))) {
        return 'This RSSHub instance cannot fetch Pixiv (missing Pixiv login/token). '
            . 'Point AP_RSSHUB_BASE at an instance that has PIXIV_REFRESHTOKEN configured, or paste a working feed URL.';
    }
    if ($status === 403 || str_contains($snip, 'restrict access') || str_contains($snip, 'just a moment')) {
        return 'The RSSHub instance blocked this request (common on public demos from server IPs). '
            . 'Set AP_RSSHUB_BASE to a usable instance, or paste that instance’s /pixiv/user/… feed URL directly.';
    }
    if ($status === 502 || $status === 503 || $status === 0) {
        return 'RSSHub is temporarily unavailable for this Pixiv feed. The subscription can be saved and retried later, or set AP_RSSHUB_BASE to another instance.';
    }
    return $fallback !== '' ? $fallback : ('RSSHub HTTP ' . $status);
}

function ap_rss_http_error_message(int $status, string $url = ''): string
{
    if ($status === 429) {
        $who = ap_rss_host_is_tumblr($url) ? 'Tumblr' : 'That host';
        return $who . ' is rate-limiting feed fetches right now (HTTP 429). Wait a minute and try again.';
    }
    if ($status === 503) {
        return 'Feed host temporarily unavailable (HTTP 503). Try again shortly.';
    }
    // YouTube still advertises /feeds/videos.xml on channel pages, but many brand/network
    // channels (and sometimes most channels) get a hard 404 from the Atom endpoint.
    // (Channel-name hint is applied in ap_rss_add_feed — not here — to avoid recursive fetches.)
    if ($status === 404 && ap_rss_host_is_youtube($url) && str_contains(strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '')), '/feeds/')) {
        return 'YouTube’s public RSS/Atom feed returned 404 for this channel. '
            . 'The channel page may still work, but this feed URL is unavailable. Try a different feed URL.';
    }
    if ($status > 0) {
        return 'HTTP ' . $status;
    }
    return 'fetch failed';
}

/**
 * @param array{max_attempts?:int} $opts Interactive add uses max_attempts=1 so 429 does not stall the page.
 * @return array{ok:bool,status:int,body:string,etag:string,last_modified:string,error:string}
 */
function ap_rss_http_get(string $url, string $etag = '', string $lastModified = '', array $opts = []): array
{
    $backoffLeft = ap_rss_host_backoff_until($url) - time();
    if ($backoffLeft > 0) {
        return [
            'ok' => false,
            'status' => 429,
            'body' => '',
            'etag' => '',
            'last_modified' => '',
            'error' => ap_rss_http_error_message(429, $url),
        ];
    }

    $headers = [
        'Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.7',
        // Browser-ish feed reader UA — Tumblr is harsher on bare bot strings from datacenter IPs.
        'User-Agent: Mozilla/5.0 (compatible; VAAK-RSS/0.5; +https://vaak.monster/)',
        'Accept-Language: en-US,en;q=0.8',
    ];
    if ($etag !== '') {
        $headers[] = 'If-None-Match: ' . $etag;
    }
    if ($lastModified !== '') {
        $headers[] = 'If-Modified-Since: ' . $lastModified;
    }

    $attempt = 0;
    $maxAttempts = max(1, min(4, (int) ($opts['max_attempts'] ?? 3)));
    $last = [
        'ok' => false,
        'status' => 0,
        'body' => '',
        'etag' => '',
        'last_modified' => '',
        'error' => 'fetch failed',
    ];
    while ($attempt < $maxAttempts) {
        $attempt++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 18,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
            CURLOPT_ENCODING => '', // accept gzip/br when available
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = (string) curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($errno !== CURLE_OK || !is_string($raw)) {
            $last = ['ok' => false, 'status' => $status, 'body' => '', 'etag' => '', 'last_modified' => '', 'error' => $err !== '' ? $err : 'fetch failed'];
            break;
        }
        $headerBlob = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        if (strlen($body) > 2_500_000) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'etag' => '', 'last_modified' => '', 'error' => 'Feed too large'];
        }
        $newEtag = '';
        $newLm = '';
        $retryAfter = 0;
        if (preg_match('/^ETag:\\s*(.+)$/mi', $headerBlob, $m)) {
            $newEtag = trim($m[1]);
        }
        if (preg_match('/^Last-Modified:\\s*(.+)$/mi', $headerBlob, $m)) {
            $newLm = trim($m[1]);
        }
        if (preg_match('/^Retry-After:\\s*(\d+)\s*$/mi', $headerBlob, $m)) {
            $retryAfter = max(1, min(120, (int) $m[1]));
        }
        if ($status === 200 || $status === 304) {
            return [
                'ok' => $status === 200,
                'status' => $status,
                'body' => $body,
                'etag' => $newEtag,
                'last_modified' => $newLm,
                'error' => '',
            ];
        }
        $last = [
            'ok' => false,
            'status' => $status,
            'body' => $body,
            'etag' => $newEtag,
            'last_modified' => $newLm,
            'error' => ap_rss_http_error_message($status, $url),
        ];
        if ($status !== 429 && $status !== 503) {
            break;
        }
        $sleep = $retryAfter > 0 ? $retryAfter : (2 * $attempt);
        $sleep = max(1, min(20, $sleep));
        ap_rss_host_backoff_set($url, $sleep + 5);
        if ($attempt >= $maxAttempts) {
            break;
        }
        sleep($sleep);
    }
    if (($last['status'] ?? 0) === 429 || ($last['status'] ?? 0) === 503) {
        ap_rss_host_backoff_set($url, 45);
    }
    return $last;
}

function ap_rss_discover_feed_url(string $pageUrl, string $html): ?string
{
    if ($html !== '' && preg_match_all(
        '/<link[^>]+rel=["\'][^"\']*alternate[^"\']*["\'][^>]*>/i',
        $html,
        $links
    )) {
        foreach ($links[0] as $tag) {
            if (!preg_match('/type=["\'](application\/(rss|atom)\\+xml|text\/xml)["\']/i', $tag)) {
                continue;
            }
            if (!preg_match('/href=["\']([^"\']+)["\']/i', $tag, $hm)) {
                continue;
            }
            $href = html_entity_decode($hm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $abs = ap_rss_absolutize($pageUrl, $href);
            if ($abs !== null) {
                return $abs;
            }
        }
    }

    $parts = parse_url($pageUrl);
    $origin = '';
    if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
    // Tumblr blogs always expose /rss — try that alone (no stampede of /feed variants).
    if ($origin !== '' && ap_rss_host_is_tumblr($pageUrl)) {
        $try = $origin . '/rss';
        if ($try !== $pageUrl) {
            usleep(400000);
            $fetch = ap_rss_http_get($try);
            if ($fetch['ok'] && ap_rss_looks_like_feed($fetch['body'])) {
                return $try;
            }
        }
        return null;
    }

    // Common fallbacks (origin-based so /blog/post URLs do not become /blog/post/rss).
    $base = $origin !== '' ? $origin : (preg_replace('#/$#', '', $pageUrl) ?? $pageUrl);
    foreach (['/feed', '/rss', '/atom.xml', '/feed.xml', '/index.xml'] as $i => $suffix) {
        $try = $base . $suffix;
        if ($try === $pageUrl) {
            continue;
        }
        if ($i > 0) {
            usleep(350000);
        }
        $fetch = ap_rss_http_get($try);
        if (($fetch['status'] ?? 0) === 429) {
            return null;
        }
        if ($fetch['ok'] && ap_rss_looks_like_feed($fetch['body'])) {
            return $try;
        }
    }
    return null;
}

function ap_rss_absolutize(string $base, string $href): ?string
{
    $href = trim($href);
    if ($href === '') {
        return null;
    }
    if (preg_match('#^https://#i', $href)) {
        return $href;
    }
    if (preg_match('#^http://#i', $href)) {
        return null;
    }
    $bp = parse_url($base);
    if (!is_array($bp) || empty($bp['scheme']) || empty($bp['host'])) {
        return null;
    }
    $origin = $bp['scheme'] . '://' . $bp['host'] . (isset($bp['port']) ? ':' . $bp['port'] : '');
    if (str_starts_with($href, '//')) {
        return $bp['scheme'] . ':' . $href;
    }
    if (str_starts_with($href, '/')) {
        return $origin . $href;
    }
    $dir = isset($bp['path']) ? (string) preg_replace('#/[^/]*$#', '/', $bp['path']) : '/';
    return $origin . $dir . $href;
}

function ap_rss_guess_favicon(string $siteOrFeedUrl, string $feedImage = ''): string
{
    if ($feedImage !== '' && preg_match('#^https://#i', $feedImage)) {
        return $feedImage;
    }
    $parts = parse_url($siteOrFeedUrl);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    $origin = 'https://' . $parts['host'];
    return $origin . '/favicon.ico';
}

/**
 * @return array{title:string,site_url:string,image_url:string,items:list<array<string,mixed>>}|null
 */
function ap_rss_parse_feed(string $xml, string $feedUrl): ?array
{
    $xml = trim($xml);
    if ($xml === '') {
        return null;
    }
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) {
        return null;
    }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
    $xp->registerNamespace('media', 'http://search.yahoo.com/mrss/');
    $xp->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');

    $isAtom = $dom->documentElement && strtolower($dom->documentElement->localName ?? '') === 'feed';
    $title = '';
    $site = '';
    $image = '';
    $items = [];

    if ($isAtom) {
        $title = trim((string) $xp->evaluate('string(/atom:feed/atom:title)'));
        $site = trim((string) $xp->evaluate('string(/atom:feed/atom:link[@rel="alternate"]/@href)'));
        if ($site === '') {
            $site = trim((string) $xp->evaluate('string(/atom:feed/atom:link[not(@rel)]/@href)'));
        }
        $image = trim((string) $xp->evaluate('string(/atom:feed/atom:icon|/atom:feed/atom:logo)'));
        foreach ($xp->query('/atom:feed/atom:entry') ?: [] as $entry) {
            if (!($entry instanceof DOMElement)) {
                continue;
            }
            $items[] = ap_rss_parse_atom_entry($xp, $entry);
            if (count($items) >= 80) {
                break;
            }
        }
    } else {
        $title = trim((string) $xp->evaluate('string(/rss/channel/title)'));
        $site = trim((string) $xp->evaluate('string(/rss/channel/link)'));
        $image = trim((string) $xp->evaluate('string(/rss/channel/image/url)'));
        foreach ($xp->query('/rss/channel/item') ?: [] as $entry) {
            if (!($entry instanceof DOMElement)) {
                continue;
            }
            $items[] = ap_rss_parse_rss_item($xp, $entry);
            if (count($items) >= 80) {
                break;
            }
        }
    }

    $items = array_values(array_filter($items, static fn($i) => is_array($i) && ($i['guid'] ?? '') !== ''));
    if ($site === '' || !preg_match('#^https://#i', $site)) {
        $parts = parse_url($feedUrl);
        $site = (is_array($parts) && !empty($parts['host']))
            ? ('https://' . $parts['host'] . '/')
            : '';
    }
    return [
        'title' => $title,
        'site_url' => $site,
        'image_url' => preg_match('#^https://#i', $image) ? $image : '',
        'items' => $items,
    ];
}

function ap_rss_parse_rss_item(DOMXPath $xp, DOMElement $item): array
{
    $title = trim(ap_rss_dom_text($xp, $item, 'title'));
    $link = trim(ap_rss_dom_text($xp, $item, 'link'));
    $guid = trim(ap_rss_dom_text($xp, $item, 'guid'));
    if ($guid === '') {
        $guid = $link !== '' ? $link : $title;
    }
    $desc = trim(ap_rss_dom_text($xp, $item, 'description'));
    $encoded = trim(ap_rss_dom_text($xp, $item, 'content:encoded'));
    $htmlBlob = $encoded !== '' ? $encoded : $desc;
    $pub = trim(ap_rss_dom_text($xp, $item, 'pubDate'));
    $published = ap_rss_parse_date($pub);
    $candidates = ap_rss_images_from_html($htmlBlob);
    $mediaThumb = trim((string) $xp->evaluate('string(.//media:thumbnail/@url)', $item));
    $mediaContent = trim((string) $xp->evaluate('string(.//media:content/@url)', $item));
    if ($mediaContent !== '') {
        $candidates[] = $mediaContent;
    }
    if ($mediaThumb !== '') {
        $candidates[] = $mediaThumb;
    }
    // RSS enclosure (image/*)
    foreach ($xp->query('./enclosure', $item) ?: [] as $enc) {
        if (!($enc instanceof DOMElement)) {
            continue;
        }
        $encUrl = trim((string) $enc->getAttribute('url'));
        $encType = strtolower(trim((string) $enc->getAttribute('type')));
        if ($encUrl === '' || !preg_match('#^https://#i', $encUrl)) {
            continue;
        }
        if ($encType === '' || str_starts_with($encType, 'image/') || preg_match('/\.(jpe?g|png|webp|gif)(?:$|[?#])/i', $encUrl)) {
            $candidates[] = $encUrl;
        }
    }
    $itemUrl = preg_match('#^https://#i', $link) ? $link : (preg_match('#^https://#i', $guid) ? $guid : '');
    return [
        'guid' => $guid,
        'url' => $itemUrl,
        'title' => $title,
        'summary_text' => ap_rss_summary_from_html($htmlBlob, $itemUrl),
        'image_url' => ap_rss_pick_best_image($candidates),
        'published_at' => $published,
    ];
}

function ap_rss_parse_atom_entry(DOMXPath $xp, DOMElement $entry): array
{
    $title = trim((string) $xp->evaluate('string(./atom:title)', $entry));
    $link = trim((string) $xp->evaluate('string(./atom:link[@rel="alternate"]/@href)', $entry));
    if ($link === '') {
        $link = trim((string) $xp->evaluate('string(./atom:link[not(@rel)]/@href)', $entry));
    }
    $id = trim((string) $xp->evaluate('string(./atom:id)', $entry));
    $guid = $id !== '' ? $id : $link;
    // Keep raw HTML for image extraction before strip_tags.
    $summaryHtml = '';
    foreach ($entry->childNodes ?: [] as $n) {
        if (!($n instanceof DOMElement)) {
            continue;
        }
        $ln = strtolower((string) ($n->localName ?? ''));
        if ($ln === 'content' || ($ln === 'summary' && $summaryHtml === '')) {
            $summaryHtml = (string) $n->textContent;
            if ($ln === 'content') {
                break;
            }
        }
    }
    if ($summaryHtml === '') {
        $summaryHtml = trim((string) $xp->evaluate('string(./atom:content|./atom:summary)', $entry));
    }
    $pub = trim((string) $xp->evaluate('string(./atom:published|./atom:updated)', $entry));
    $published = ap_rss_parse_date($pub);
    $candidates = ap_rss_images_from_html($summaryHtml);
    $mediaThumb = trim((string) $xp->evaluate('string(.//media:thumbnail/@url)', $entry));
    $mediaContent = trim((string) $xp->evaluate('string(.//media:content/@url)', $entry));
    if ($mediaContent !== '') {
        $candidates[] = $mediaContent;
    }
    if ($mediaThumb !== '') {
        $candidates[] = $mediaThumb;
    }
    $itemUrl = preg_match('#^https://#i', $link) ? $link : (preg_match('#^https://#i', $guid) ? $guid : '');
    return [
        'guid' => $guid,
        'url' => $itemUrl,
        'title' => $title,
        'summary_text' => ap_rss_summary_from_html($summaryHtml, $itemUrl),
        'image_url' => ap_rss_pick_best_image($candidates),
        'published_at' => $published,
    ];
}

function ap_rss_dom_text(DOMXPath $xp, DOMElement $ctx, string $name): string
{
    if (str_contains($name, ':')) {
        return (string) $xp->evaluate('string(./' . $name . ')', $ctx);
    }
    foreach ($ctx->childNodes ?: [] as $n) {
        if ($n instanceof DOMElement && strtolower($n->localName ?? $n->nodeName) === strtolower($name)) {
            return (string) $n->textContent;
        }
    }
    return '';
}

function ap_rss_parse_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $t = strtotime($raw);
    if ($t === false) {
        return null;
    }
    return gmdate('c', $t);
}

/**
 * Feeds due for polling.
 * Never-fetched (deferred / rate-limited adds) and recent errors retry sooner than healthy feeds.
 */
function ap_rss_feeds_due(int $limit = 20, int $minAgeMinutes = 10): array
{
    ap_rss_migrate();
    $limit = max(1, min(50, $limit));
    $minAgeMinutes = max(1, min(180, $minAgeMinutes));
    $errorRetryMinutes = min(5, $minAgeMinutes);
    $st = ap_db()->prepare(
        "SELECT * FROM rss_feeds
         WHERE enabled = TRUE
           AND (
             last_fetched_at IS NULL
             OR (COALESCE(last_error, '') <> '' AND last_fetched_at < NOW() - make_interval(mins => ?))
             OR (COALESCE(last_error, '') = '' AND last_fetched_at < NOW() - make_interval(mins => ?))
           )
         ORDER BY
           CASE
             WHEN last_fetched_at IS NULL THEN 0
             WHEN COALESCE(last_error, '') <> '' THEN 1
             ELSE 2
           END,
           last_fetched_at NULLS FIRST,
           id ASC
         LIMIT {$limit}"
    );
    $st->execute([$errorRetryMinutes, $minAgeMinutes]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
