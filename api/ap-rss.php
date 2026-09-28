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

/**
 * True when the item is mostly media (image post) rather than an article with a hero image.
 */
function ap_rss_item_is_media_forward(string $summary, string $imageUrl, string $itemUrl = ''): bool
{
    $imageUrl = trim($imageUrl);
    if ($imageUrl === '' || !preg_match('#^https://#i', $imageUrl)) {
        return false;
    }
    $host = strtolower((string) (parse_url($itemUrl, PHP_URL_HOST) ?: ''));
    if ($host !== '' && (str_ends_with($host, 'reddit.com') || str_ends_with($host, 'redd.it'))) {
        return true;
    }
    $imgHost = strtolower((string) (parse_url($imageUrl, PHP_URL_HOST) ?: ''));
    if ($imgHost === 'i.redd.it' || str_contains($imgHost, 'preview.redd.it') || $imgHost === 'i.imgur.com') {
        return true;
    }
    $s = trim(preg_replace('/\s+/u', ' ', $summary) ?? $summary);
    if ($s === '') {
        return true;
    }
    // Reddit / image-board boilerplate after strip_tags.
    if (preg_match('/^submitted by\b/i', $s) || preg_match('/\[link\].*\[comments\]/i', $s)) {
        return true;
    }
    // Very short caption beside a real image → treat as media post.
    if (mb_strlen($s) <= 96) {
        return true;
    }
    return false;
}

/** Drop Reddit/image-feed boilerplate so Home cards show title + media. */
function ap_rss_display_summary(string $summary): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $summary) ?? $summary);
    if ($s === '') {
        return '';
    }
    if (preg_match('/^submitted by\b/i', $s) || preg_match('/\[link\].*\[comments\]/i', $s)) {
        return '';
    }
    return $s;
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

/**
 * @return array{ok:bool,error?:string,feed_id?:int,discovered?:bool}
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
    $existing = ap_db()->prepare('SELECT id FROM rss_feeds WHERE owner_user_id = ? AND feed_url = ?');
    $existing->execute([$ownerUserId, $url]);
    if ($existing->fetchColumn()) {
        return ['ok' => false, 'error' => 'That feed is already added'];
    }
    $countSt = ap_db()->prepare('SELECT COUNT(*) FROM rss_feeds WHERE owner_user_id = ?');
    $countSt->execute([$ownerUserId]);
    if ((int) $countSt->fetchColumn() >= 40) {
        return ['ok' => false, 'error' => 'Feed limit reached (40)'];
    }

    $discovered = false;
    $fetch = ap_rss_http_get($url);
    if ($fetch['ok'] && ap_rss_looks_like_feed($fetch['body'])) {
        $feedUrl = $url;
        $parsed = ap_rss_parse_feed($fetch['body'], $feedUrl);
    } else {
        $alt = ap_rss_discover_feed_url($url, $fetch['body'] ?? '');
        if ($alt === null) {
            return ['ok' => false, 'error' => 'Could not find an RSS/Atom feed at that URL'];
        }
        $discovered = true;
        $feedUrl = $alt;
        $dup = ap_db()->prepare('SELECT id FROM rss_feeds WHERE owner_user_id = ? AND feed_url = ?');
        $dup->execute([$ownerUserId, $feedUrl]);
        if ($dup->fetchColumn()) {
            return ['ok' => false, 'error' => 'That feed is already added'];
        }
        $fetch = ap_rss_http_get($feedUrl);
        if (!$fetch['ok'] || !ap_rss_looks_like_feed($fetch['body'])) {
            return ['ok' => false, 'error' => 'Found a feed link but could not load it'];
        }
        $parsed = ap_rss_parse_feed($fetch['body'], $feedUrl);
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
    return ['ok' => true, 'feed_id' => $feedId, 'discovered' => $discovered];
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

/**
 * @return array{ok:bool,status:int,body:string,etag:string,last_modified:string,error:string}
 */
function ap_rss_http_get(string $url, string $etag = '', string $lastModified = ''): array
{
    $headers = [
        'Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*;q=0.8',
        'User-Agent: VAAK-RSS/1.0 (+https://mkultra.monster)',
    ];
    if ($etag !== '') {
        $headers[] = 'If-None-Match: ' . $etag;
    }
    if ($lastModified !== '') {
        $headers[] = 'If-Modified-Since: ' . $lastModified;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = (string) curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($errno !== CURLE_OK || !is_string($raw)) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'etag' => '', 'last_modified' => '', 'error' => $err !== '' ? $err : 'fetch failed'];
    }
    $headerBlob = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    if (strlen($body) > 2_500_000) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'etag' => '', 'last_modified' => '', 'error' => 'Feed too large'];
    }
    $newEtag = '';
    $newLm = '';
    if (preg_match('/^ETag:\\s*(.+)$/mi', $headerBlob, $m)) {
        $newEtag = trim($m[1]);
    }
    if (preg_match('/^Last-Modified:\\s*(.+)$/mi', $headerBlob, $m)) {
        $newLm = trim($m[1]);
    }
    return [
        'ok' => $status === 200,
        'status' => $status,
        'body' => $body,
        'etag' => $newEtag,
        'last_modified' => $newLm,
        'error' => $status === 200 ? '' : ('HTTP ' . $status),
    ];
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
    // Common fallbacks
    $base = preg_replace('#/$#', '', $pageUrl) ?? $pageUrl;
    foreach (['/feed', '/rss', '/atom.xml', '/feed.xml', '/index.xml'] as $suffix) {
        $try = $base . $suffix;
        $fetch = ap_rss_http_get($try);
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
    $summary = trim(html_entity_decode(strip_tags($htmlBlob), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
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
        'summary_text' => $summary,
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
    $summary = trim(html_entity_decode(strip_tags($summaryHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
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
        'summary_text' => $summary,
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

/** Feeds due for polling (oldest fetch first). */
function ap_rss_feeds_due(int $limit = 20, int $minAgeMinutes = 10): array
{
    ap_rss_migrate();
    $limit = max(1, min(50, $limit));
    $st = ap_db()->prepare(
        "SELECT * FROM rss_feeds
         WHERE enabled = TRUE
           AND (last_fetched_at IS NULL OR last_fetched_at < NOW() - make_interval(mins => ?))
         ORDER BY last_fetched_at NULLS FIRST, id ASC
         LIMIT {$limit}"
    );
    $st->execute([$minAgeMinutes]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
