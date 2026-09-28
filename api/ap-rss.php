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
 * Recent RSS keys for Home ranking.
 *
 * @return list<array{uri:string,feed_id:int,published_at:?string}>
 */
function ap_rss_home_rank_keys(int $ownerUserId, int $limit = 40): array
{
    ap_rss_migrate();
    if ($ownerUserId < 1) {
        return [];
    }
    $limit = max(1, min(80, $limit));
    $st = ap_db()->prepare(
        "SELECT i.id, i.feed_id, i.published_at, i.url
         FROM rss_items i
         JOIN rss_feeds f ON f.id = i.feed_id
         WHERE f.owner_user_id = ? AND f.enabled = TRUE
           AND (i.published_at IS NULL OR i.published_at >= NOW() - INTERVAL '14 days')
         ORDER BY COALESCE(i.published_at, i.ingested_at) DESC, i.id DESC
         LIMIT {$limit}"
    );
    $st->execute([$ownerUserId]);
    $out = [];
    $seenUrl = [];
    $perFeed = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $fid = (int) ($row['feed_id'] ?? 0);
        $n = $perFeed[$fid] ?? 0;
        if ($n >= 2) {
            continue;
        }
        $url = rtrim((string) ($row['url'] ?? ''), '/');
        if ($url !== '' && isset($seenUrl[$url])) {
            continue;
        }
        if ($url !== '') {
            $seenUrl[$url] = true;
        }
        $perFeed[$fid] = $n + 1;
        $out[] = [
            'uri' => (string) (int) ($row['id'] ?? 0),
            'feed_id' => $fid,
            'published_at' => isset($row['published_at']) ? (string) $row['published_at'] : null,
        ];
    }
    return $out;
}

/**
 * Materialize a public local note for boost/quote/favourite federation.
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
    if ($desc === '') {
        $desc = trim(ap_rss_dom_text($xp, $item, 'content:encoded'));
    }
    $pub = trim(ap_rss_dom_text($xp, $item, 'pubDate'));
    $published = ap_rss_parse_date($pub);
    $summary = trim(html_entity_decode(strip_tags($desc), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $img = '';
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $desc, $m) && preg_match('#^https://#i', $m[1])) {
        $img = $m[1];
    }
    $media = trim((string) $xp->evaluate('string(.//media:thumbnail/@url|.//media:content/@url)', $item));
    if ($img === '' && preg_match('#^https://#i', $media)) {
        $img = $media;
    }
    return [
        'guid' => $guid,
        'url' => preg_match('#^https://#i', $link) ? $link : (preg_match('#^https://#i', $guid) ? $guid : ''),
        'title' => $title,
        'summary_text' => $summary,
        'image_url' => $img,
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
    $summary = trim((string) $xp->evaluate('string(./atom:summary)', $entry));
    if ($summary === '') {
        $summary = trim((string) $xp->evaluate('string(./atom:content)', $entry));
    }
    $summary = trim(html_entity_decode(strip_tags($summary), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $pub = trim((string) $xp->evaluate('string(./atom:published|./atom:updated)', $entry));
    $published = ap_rss_parse_date($pub);
    $img = '';
    $media = trim((string) $xp->evaluate('string(.//media:thumbnail/@url|.//media:content/@url)', $entry));
    if (preg_match('#^https://#i', $media)) {
        $img = $media;
    }
    return [
        'guid' => $guid,
        'url' => preg_match('#^https://#i', $link) ? $link : (preg_match('#^https://#i', $guid) ? $guid : ''),
        'title' => $title,
        'summary_text' => $summary,
        'image_url' => $img,
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
