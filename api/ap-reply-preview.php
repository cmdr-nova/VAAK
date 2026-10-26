<?php
/**
 * Local-only summary for the composer's "Replying to" area.
 *
 * The click path reads the card already on the page. This lookup is the
 * no-JS / full-navigation path: indexed rows VAAK already stored. It never
 * fetches another server or the Bluesky appview. Unknown or hidden posts
 * return null so the composer keeps the existing handle/URL label.
 */
declare(strict_types=1);

function admin_reply_preview_h(string $s): string
{
    if (function_exists('h')) {
        return h($s);
    }
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * https URL safe to put in an img src. javascript:, data:, vbscript:, and
 * anything else become ''. The scheme is an allowlist, not a denylist.
 */
function admin_reply_preview_https_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\s<>"\'\\\\]/', $url)) {
        return '';
    }
    $parts = parse_url($url);
    $scheme = strtolower((string) (is_array($parts) ? ($parts['scheme'] ?? '') : ''));
    $host = (string) (is_array($parts) ? ($parts['host'] ?? '') : '');
    if ($scheme !== 'https' || $host === '') {
        return '';
    }
    return $url;
}

/**
 * Plain snippet. Strips tags (including once-decoded markup), drops quote
 * tails and transport placeholders, and trims to a short single line.
 */
function admin_reply_preview_plain(string $text, int $limit = 180): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $prev = null;
    for ($i = 0; $i < 3 && $prev !== $text; $i++) {
        $prev = $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/^(.*?)(?:\n+\s*)?↪\s*QT\b/us', $text, $m)) {
        $text = (string) $m[1];
    }
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    if (in_array($text, ['(quote)', '(media)', '(poll)', '(attachment)', '(boost)', '(no text preview)', '(quoted post unavailable)', '(quoted post)'], true)) {
        return '';
    }
    if ($limit < 8) {
        $limit = 8;
    }
    if (function_exists('mb_strlen') && mb_strlen($text) > $limit) {
        $text = rtrim(mb_substr($text, 0, $limit - 1)) . '…';
    } elseif (strlen($text) > $limit) {
        $text = rtrim(substr($text, 0, $limit - 1)) . '…';
    }
    return $text;
}

function admin_reply_preview_default_avatar(): string
{
    if (defined('AP_REMOTE_AVATAR_FALLBACK')) {
        $fallback = admin_reply_preview_https_url((string) AP_REMOTE_AVATAR_FALLBACK);
        if ($fallback !== '') {
            return $fallback;
        }
    }
    return 'https://mkultra.monster/img/avatar/default.webp';
}

/** @param array<string,mixed> $preview */
function admin_reply_preview_usable(array $preview): bool
{
    $name = trim((string) ($preview['name'] ?? ''));
    $handle = trim((string) ($preview['handle'] ?? ''));
    $snippet = trim((string) ($preview['snippet'] ?? ''));
    return $name !== '' || $handle !== '' || $snippet !== ''
        || !empty($preview['media']) || !empty($preview['cw']) || !empty($preview['quote']);
}

/**
 * @param array<string,mixed> $preview
 */
function admin_reply_preview_card_html(array $preview): string
{
    $name = admin_reply_preview_plain((string) ($preview['name'] ?? ''), 80);
    $handle = trim((string) ($preview['handle'] ?? ''));
    if ($handle !== '' && $handle[0] !== '@') {
        $handle = '@' . $handle;
    }
    $handle = admin_reply_preview_plain($handle, 80);
    $snippet = admin_reply_preview_plain((string) ($preview['snippet'] ?? ''));
    $avatar = admin_reply_preview_https_url((string) ($preview['avatar'] ?? ''));
    if ($avatar === '') {
        $avatar = admin_reply_preview_default_avatar();
    }
    $fallback = admin_reply_preview_default_avatar();
    $html = '<div class="compose-reply-preview">';
    $html .= '<img class="tweet-av" src="' . admin_reply_preview_h($avatar) . '" alt="" width="32" height="32"'
        . ' loading="lazy" decoding="async" referrerpolicy="no-referrer"'
        . ' onerror="this.onerror=null;this.src=\'' . admin_reply_preview_h($fallback) . '\'">';
    $html .= '<div class="compose-reply-preview__main">';
    if ($name !== '' || $handle !== '') {
        $html .= '<div class="compose-reply-preview__who">';
        if ($name !== '') {
            $html .= '<span class="who">' . admin_reply_preview_h($name) . '</span>';
        }
        if ($handle !== '' && strcasecmp(ltrim($handle, '@'), $name) !== 0) {
            $html .= '<span class="meta">' . admin_reply_preview_h($handle) . '</span>';
        }
        $html .= '</div>';
    }
    if ($snippet !== '') {
        $html .= '<div class="compose-reply-preview__text">' . admin_reply_preview_h($snippet) . '</div>';
    }
    $notes = [];
    if (!empty($preview['cw'])) {
        $notes[] = 'Content warning';
    }
    if (!empty($preview['media'])) {
        $notes[] = 'Media';
    }
    if (!empty($preview['quote'])) {
        $notes[] = 'Quote';
    }
    if ($notes !== []) {
        $html .= '<div class="compose-reply-preview__notes">';
        foreach ($notes as $note) {
            $html .= '<span class="tag">' . admin_reply_preview_h($note) . '</span>';
        }
        $html .= '</div>';
    }
    $html .= '</div></div>';
    return $html;
}

function admin_reply_preview_target_label(string $replyTo): string
{
    $replyTo = trim($replyTo);
    if (preg_match('#^https://bsky\\.app/profile/([^/]+)/post/#i', $replyTo, $m)) {
        $seg = rawurldecode((string) $m[1]);
        return $seg !== '' ? ('@' . $seg) : '';
    }
    if (preg_match('#/users/([^/]+)/#i', $replyTo, $m)) {
        $seg = rawurldecode((string) $m[1]);
        return $seg !== '' ? ('@' . $seg) : '';
    }
    if (function_exists('actor_handle')) {
        $handle = trim((string) actor_handle($replyTo));
        if ($handle !== '' && $handle !== '@') {
            return $handle;
        }
    }
    return '';
}

/**
 * The quote-block shown above the textarea. $self keeps "Replying to your post"
 * (thread continuation). Without a local summary, the old handle or URL remains.
 *
 * @param array<string,mixed>|null $preview
 */
function admin_reply_preview_context_html(string $label, string $replyUrl, ?array $preview, bool $self): string
{
    $label = $self ? 'Replying to your post' : 'Replying to';
    $html = '<div class="quote-block compose-reply-context" style="margin-bottom:.75rem">'
        . '<span class="qt-label">' . admin_reply_preview_h($label) . '</span>';
    if (is_array($preview) && admin_reply_preview_usable($preview)) {
        $html .= admin_reply_preview_card_html($preview);
    } elseif ($self) {
        $html .= '<br><span class="mono">' . admin_reply_preview_h($replyUrl) . '</span>';
    } else {
        $fallback = admin_reply_preview_target_label($replyUrl);
        if ($fallback === '') {
            $fallback = 'the original post';
        }
        $html .= ' <strong>' . admin_reply_preview_h($fallback) . '</strong>';
    }
    return $html . '</div>';
}

function admin_reply_preview_viewer_actor(): string
{
    if (!function_exists('vaak_actor_id')) {
        return '';
    }
    $id = rtrim(trim((string) vaak_actor_id()), '/');
    return str_starts_with($id, 'https://') ? $id : '';
}

function admin_reply_preview_owner_id(): int
{
    if (function_exists('admin_owner_user_id')) {
        return (int) admin_owner_user_id();
    }
    return (int) ($GLOBALS['vaak_owner_id'] ?? 0);
}

function admin_reply_preview_actor_hidden(string $actorId): bool
{
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '') {
        return false;
    }
    if (function_exists('ap_is_blocked_actor') && ap_is_blocked_actor($actorId)) {
        return true;
    }
    if (function_exists('ap_is_globally_muted_actor') && ap_is_globally_muted_actor($actorId)) {
        return true;
    }
    $owner = admin_reply_preview_owner_id();
    if ($owner > 0 && function_exists('ap_is_muted_actor') && ap_is_muted_actor($actorId, $owner)) {
        return true;
    }
    if (function_exists('ap_is_blocked_host')) {
        $host = parse_url($actorId, PHP_URL_HOST);
        if (is_string($host) && $host !== '' && ap_is_blocked_host(strtolower($host))) {
            return true;
        }
    }
    return false;
}

function admin_reply_preview_words_hidden(string ...$texts): bool
{
    if (!function_exists('ap_muted_words_match')) {
        return false;
    }
    $owner = admin_reply_preview_owner_id();
    if ($owner < 1) {
        return false;
    }
    return ap_muted_words_match($owner, ...$texts) !== null;
}

function admin_reply_preview_is_own(string $actorId, string $objectId): bool
{
    if (function_exists('vaak_is_own_url')) {
        if ($actorId !== '' && vaak_is_own_url($actorId)) {
            return true;
        }
        if ($objectId !== '' && vaak_is_own_url($objectId)) {
            return true;
        }
    }
    $viewer = admin_reply_preview_viewer_actor();
    if ($viewer === '') {
        return false;
    }
    $actorId = rtrim($actorId, '/');
    return $actorId !== '' && strcasecmp($actorId, $viewer) === 0;
}

function admin_reply_preview_viewer_follows(string $actorId): bool
{
    $actorId = rtrim(trim($actorId), '/');
    $owner = admin_reply_preview_viewer_actor();
    if ($actorId === '' || $owner === '' || !function_exists('ap_db')) {
        return false;
    }
    try {
        $st = ap_db()->prepare(
            'SELECT 1 FROM following
             WHERE (owner_actor_id = ? OR owner_actor_id = ?)
               AND (actor_id = ? OR actor_id = ?)
             LIMIT 1'
        );
        $st->execute([$owner, $owner . '/', $actorId, $actorId . '/']);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function admin_reply_preview_viewer_notified(string $objectId): bool
{
    $objectId = rtrim(trim($objectId), '/');
    $owner = admin_reply_preview_owner_id();
    if ($objectId === '' || $owner < 1 || !function_exists('ap_db')) {
        return false;
    }
    try {
        $st = ap_db()->prepare(
            'SELECT 1 FROM mentions
             WHERE owner_user_id = ?
               AND deleted_at IS NULL
               AND (object_id = ? OR object_id = ? OR activity_id = ? OR activity_id = ?)
             LIMIT 1'
        );
        $st->execute([$owner, $objectId, $objectId . '/', $objectId, $objectId . '/']);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function admin_reply_preview_audience_includes_viewer(string $objectId): bool
{
    $viewer = strtolower(admin_reply_preview_viewer_actor());
    $objectId = rtrim(trim($objectId), '/');
    if ($viewer === '' || $objectId === '' || !function_exists('ap_db')) {
        return false;
    }
    try {
        $st = ap_db()->prepare('SELECT to_json, cc_json FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
        $st->execute([$objectId, $objectId . '/']);
        $row = $st->fetch();
        if (!is_array($row)) {
            return false;
        }
        $blob = strtolower((string) ($row['to_json'] ?? '') . ' ' . (string) ($row['cc_json'] ?? ''));
        return $blob !== '' && str_contains($blob, $viewer);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Public / unlisted / local are visible. Followers-only and direct are visible
 * only when this viewer already could (own post, a follow, a delivered mention,
 * or an outbox audience). Blocked, muted, and word-muted rows are hidden.
 */
function admin_reply_preview_viewer_may_see(string $visibility, string $actorId, string $objectId, string ...$texts): bool
{
    if (admin_reply_preview_actor_hidden($actorId)) {
        return false;
    }
    $own = admin_reply_preview_is_own($actorId, $objectId);
    // Word mutes hide other people's posts. Your own text stays visible.
    if (!$own && admin_reply_preview_words_hidden(...$texts)) {
        return false;
    }
    $visibility = strtolower(trim($visibility));
    if ($visibility === '' || $visibility === 'public' || $visibility === 'unlisted' || $visibility === 'local') {
        return true;
    }
    if ($own) {
        return true;
    }
    if ($visibility === 'private' || $visibility === 'followers') {
        return admin_reply_preview_viewer_follows($actorId) || admin_reply_preview_viewer_notified($objectId);
    }
    if ($visibility === 'direct') {
        return admin_reply_preview_viewer_notified($objectId) || admin_reply_preview_audience_includes_viewer($objectId);
    }
    // Unknown audience: do not reveal a body we cannot place.
    return false;
}

/**
 * @return array{name:string,handle:string,avatar:string}
 */
function admin_reply_preview_actor_bits(string $actorId): array
{
    $actorId = rtrim(trim($actorId), '/');
    $name = '';
    $handle = '';
    $avatar = '';
    if (preg_match('#^https://mkultra\\.monster/users/([A-Za-z0-9_]+)$#i', $actorId, $m)) {
        $key = strtolower((string) $m[1]);
        $handle = '@' . $key;
        if (function_exists('ap_profile_display_name')) {
            $name = trim((string) ap_profile_display_name($key, true));
        }
        if (function_exists('ap_profile_get')) {
            $profile = ap_profile_get($key);
            if (is_array($profile)) {
                if ($name === '') {
                    $name = trim((string) ($profile['name'] ?? ''));
                }
                $avatar = admin_reply_preview_https_url((string) ($profile['icon_url'] ?? ''));
            }
        }
        if ($name === '') {
            $name = $key;
        }
        return [
            'name' => admin_reply_preview_plain($name, 80),
            'handle' => $handle,
            'avatar' => $avatar,
        ];
    }
    if (preg_match('~^https://bsky\\.app/profile/([^/?#]+)~i', $actorId, $m)) {
        $seg = rawurldecode((string) $m[1]);
        if ($seg !== '' && !str_starts_with(strtolower($seg), 'did:')) {
            $handle = '@' . $seg;
            $name = $seg;
        }
    }
    if ($actorId !== '' && function_exists('ap_remote_actor_get')) {
        $row = ap_remote_actor_get($actorId);
        if (is_array($row)) {
            $user = trim((string) ($row['username'] ?? ''));
            $display = trim((string) ($row['display_name'] ?? ''));
            $host = strtolower(trim((string) ($row['host'] ?? '')));
            if ($host === '') {
                $parsed = parse_url($actorId, PHP_URL_HOST);
                $host = is_string($parsed) ? strtolower($parsed) : '';
            }
            $placeholder = $user === '' || preg_match('/^(user|\?|\d{6,})$/', $user) === 1;
            if (!$placeholder) {
                $handle = '@' . $user . ($host !== '' ? '@' . $host : '');
            }
            if ($display !== '') {
                $name = $display;
            }
            if ($avatar === '') {
                $avatar = admin_reply_preview_https_url((string) ($row['icon_source_url'] ?? ''));
            }
        }
    }
    if ($handle === '' && $actorId !== '') {
        $host = parse_url($actorId, PHP_URL_HOST);
        $path = (string) (parse_url($actorId, PHP_URL_PATH) ?? '');
        $base = rawurldecode(basename(rtrim($path, '/')));
        if ($base !== '' && !in_array(strtolower($base), ['users', 'actor', 'profile'], true)) {
            $handle = '@' . ltrim($base, '@');
            if (is_string($host) && $host !== '') {
                $handle .= '@' . strtolower($host);
            }
        }
    }
    if ($name === '') {
        $name = ltrim($handle, '@');
    }
    return [
        'name' => admin_reply_preview_plain($name, 80),
        'handle' => $handle,
        'avatar' => $avatar,
    ];
}

/** @param array{name:string,handle:string,avatar:string} $bits */
function admin_reply_preview_pack(array $bits, string $snippet, bool $media, bool $cw, bool $quote): ?array
{
    $pack = [
        'name' => $bits['name'],
        'handle' => $bits['handle'],
        'avatar' => $bits['avatar'],
        'snippet' => $snippet,
        'media' => $media,
        'cw' => $cw,
        'quote' => $quote,
    ];
    return admin_reply_preview_usable($pack) ? $pack : null;
}

function admin_reply_preview_media_list_nonempty(mixed $raw): bool
{
    if (is_array($raw)) {
        return $raw !== [];
    }
    $raw = trim((string) $raw);
    if ($raw === '' || $raw === '[]' || $raw === 'null') {
        return false;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) && $decoded !== [];
}

function admin_reply_preview_text_has_quote(string $text): bool
{
    return (bool) preg_match('/↪\s*QT\b/u', $text);
}

function admin_reply_preview_from_masto(string $noteId): ?array
{
    $noteId = rtrim(trim($noteId), '/');
    if ($noteId === '' || !str_starts_with($noteId, 'https://') || !function_exists('ap_db')) {
        return null;
    }
    try {
        $st = ap_db()->prepare(
            'SELECT local_id, note_id, content_text, visibility, spoiler_text, sensitive
             FROM masto_statuses WHERE note_id = ? OR note_id = ? LIMIT 1'
        );
        $st->execute([$noteId, $noteId . '/']);
        $row = $st->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($row)) {
        return null;
    }
    $note = rtrim((string) ($row['note_id'] ?? $noteId), '/');
    $actor = '';
    if (preg_match('#^(https://mkultra\\.monster/users/[A-Za-z0-9_]+)/notes/#i', $note, $m)) {
        $actor = $m[1];
    } elseif (function_exists('ap_masto_actor_url_from_object_url')) {
        $guess = ap_masto_actor_url_from_object_url($note);
        $actor = is_string($guess) ? rtrim($guess, '/') : '';
    }
    $text = (string) ($row['content_text'] ?? '');
    if (trim($text) === '' || in_array(trim($text), ['(quote)', '(media)', '(poll)', '(attachment)', '(boost)'], true)) {
        try {
            $ost = ap_db()->prepare('SELECT content FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
            $ost->execute([$note, $note . '/']);
            $outbox = $ost->fetchColumn();
            if (is_string($outbox) && trim($outbox) !== '') {
                $text = $outbox;
            }
        } catch (Throwable $e) {
            // The status row is enough when the outbox copy is missing.
        }
    }
    $spoiler = trim((string) ($row['spoiler_text'] ?? ''));
    $snippet = admin_reply_preview_plain($text);
    $quote = admin_reply_preview_text_has_quote($text) || trim((string) ($row['content_text'] ?? '')) === '(quote)';
    if ($snippet === '' && $spoiler !== '') {
        $snippet = admin_reply_preview_plain($spoiler);
    }
    $media = false;
    $localId = (int) ($row['local_id'] ?? 0);
    if ($localId > 0) {
        try {
            $mst = ap_db()->prepare('SELECT 1 FROM masto_media WHERE status_local_id = ? LIMIT 1');
            $mst->execute([$localId]);
            $media = (bool) $mst->fetchColumn();
        } catch (Throwable $e) {
            $media = false;
        }
    }
    if (!$media && in_array(trim((string) ($row['content_text'] ?? '')), ['(media)', '(attachment)'], true)) {
        $media = true;
    }
    $cw = $spoiler !== '' || !empty($row['sensitive']);
    $visibility = (string) ($row['visibility'] ?? 'public');
    if (!admin_reply_preview_viewer_may_see($visibility, $actor, $note, $text, $spoiler, $snippet)) {
        return null;
    }
    return admin_reply_preview_pack(admin_reply_preview_actor_bits($actor), $snippet, $media, $cw, $quote);
}

function admin_reply_preview_from_event(string $objectId): ?array
{
    $objectId = rtrim(trim($objectId), '/');
    if ($objectId === '' || !str_starts_with($objectId, 'https://') || !function_exists('ap_db')) {
        return null;
    }
    try {
        $st = ap_db()->prepare(
            "SELECT type, actor_id, object_id, summary, media_urls, spoiler_text, sensitive, visibility, target_actor
             FROM events
             WHERE (object_id = ? OR object_id = ?)
               AND COALESCE(action_taken, '') != 'deleted'
             ORDER BY CASE lower(type)
               WHEN 'create' THEN 0
               WHEN 'update' THEN 1
               WHEN 'quotepost' THEN 2
               WHEN 'quote' THEN 2
               WHEN 'announce' THEN 10
               ELSE 5
             END ASC, id DESC
             LIMIT 1"
        );
        $st->execute([$objectId, $objectId . '/']);
        $row = $st->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($row)) {
        return null;
    }
    $type = strtolower(trim((string) ($row['type'] ?? '')));
    $object = rtrim((string) ($row['object_id'] ?? $objectId), '/');
    $actor = rtrim((string) ($row['actor_id'] ?? ''), '/');
    // An Announce names the booster. Summarize the boosted post's author.
    if ($type === 'announce') {
        $parsed = function_exists('ap_masto_actor_url_from_object_url')
            ? ap_masto_actor_url_from_object_url($object)
            : null;
        $parsed = is_string($parsed) ? rtrim($parsed, '/') : '';
        $target = rtrim((string) ($row['target_actor'] ?? ''), '/');
        if ($parsed !== '') {
            $actor = $parsed;
        } elseif ($target !== '' && $target !== $actor && str_starts_with($target, 'https://')) {
            $actor = $target;
        } else {
            return null;
        }
    }
    $summary = (string) ($row['summary'] ?? '');
    $spoiler = trim((string) ($row['spoiler_text'] ?? ''));
    $snippet = admin_reply_preview_plain($summary);
    $quote = admin_reply_preview_text_has_quote($summary);
    if ($snippet === '' && $spoiler !== '') {
        $snippet = admin_reply_preview_plain($spoiler);
    }
    $media = admin_reply_preview_media_list_nonempty($row['media_urls'] ?? null);
    $cw = $spoiler !== '' || !empty($row['sensitive']);
    $visibility = (string) ($row['visibility'] ?? 'public');
    if (!admin_reply_preview_viewer_may_see($visibility, $actor, $object, $summary, $spoiler, $snippet)) {
        return null;
    }
    return admin_reply_preview_pack(admin_reply_preview_actor_bits($actor), $snippet, $media, $cw, $quote);
}

/** Local at:// only. Handle permalinks resolve through bsky_posts, never the network. */
function admin_reply_preview_bsky_at(string $uri): ?string
{
    $uri = trim($uri);
    if (preg_match('~^(at://[^/\s]+/app\.bsky\.feed\.post/[A-Za-z0-9]+)~', $uri, $m)) {
        return $m[1];
    }
    if (preg_match('~^https://bsky\.app/profile/(did:[^/]+)/post/([A-Za-z0-9]+)~i', $uri, $m)) {
        return 'at://' . $m[1] . '/app.bsky.feed.post/' . $m[2];
    }
    if (preg_match('~(?:^|/)at://(did:[^/\s]+)/app\.bsky\.feed\.post/([A-Za-z0-9]+)~i', $uri, $m)) {
        return 'at://' . $m[1] . '/app.bsky.feed.post/' . $m[2];
    }
    if (!preg_match('~^https://bsky\.app/profile/([^/]+)/post/([A-Za-z0-9]+)~i', $uri, $m) || !function_exists('ap_db')) {
        return null;
    }
    $handle = rawurldecode((string) $m[1]);
    $rkey = rawurldecode((string) $m[2]);
    if ($handle === '' || $rkey === '' || str_contains($rkey, '%')) {
        return null;
    }
    try {
        // Equality on the handle index, then an exact at:// primary-key read.
        // A leading-wildcard match on bsky_uri would scan the post table.
        $st = ap_db()->prepare(
            'SELECT author_did FROM bsky_posts
             WHERE lower(author_handle) = lower(?)
               AND author_did LIKE \'did:%\'
             LIMIT 1'
        );
        $st->execute([$handle]);
        $did = $st->fetchColumn();
        if (is_string($did) && str_starts_with($did, 'did:')) {
            return 'at://' . $did . '/app.bsky.feed.post/' . $rkey;
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

function admin_reply_preview_crosspost_note(string $atUri): ?string
{
    if (!function_exists('ap_db')) {
        return null;
    }
    try {
        $st = ap_db()->prepare('SELECT note_id FROM bsky_crossposts WHERE bsky_uri = ? LIMIT 1');
        $st->execute([$atUri]);
        $note = $st->fetchColumn();
        if (is_string($note) && str_starts_with($note, 'https://')) {
            return rtrim($note, '/');
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

/** @param array<string,mixed> $post */
function admin_reply_preview_bsky_parts(array $post): array
{
    $record = is_array($post['record'] ?? null) ? $post['record'] : [];
    $embed = is_array($post['embed'] ?? null) ? $post['embed'] : [];
    if ($embed === [] && is_array($record['embed'] ?? null)) {
        $embed = $record['embed'];
    }
    $type = strtolower((string) ($embed['$type'] ?? ''));
    $mediaEmbed = $embed;
    if (str_contains($type, 'recordwithmedia') && is_array($embed['media'] ?? null)) {
        $mediaEmbed = $embed['media'];
    }
    $mediaType = strtolower((string) ($mediaEmbed['$type'] ?? $type));
    $media = str_contains($mediaType, 'images') || str_contains($mediaType, 'video');
    if (!$media && is_array($mediaEmbed['images'] ?? null) && $mediaEmbed['images'] !== []) {
        $media = true;
    }
    $quote = str_contains($type, 'embed.record');
    $text = trim((string) ($record['text'] ?? ''));
    $cw = false;
    if (function_exists('ap_bsky_post_is_sensitive')) {
        $cw = ap_bsky_post_is_sensitive($post);
    }
    return ['text' => $text, 'media' => $media, 'quote' => $quote, 'cw' => $cw];
}

function admin_reply_preview_from_bsky(string $atUri): ?array
{
    if (!str_starts_with($atUri, 'at://') || !function_exists('ap_db')) {
        return null;
    }
    try {
        $st = ap_db()->prepare(
            'SELECT bsky_uri, raw_json, text, embed_json, author_did, author_handle, author_display, author_avatar
             FROM bsky_posts WHERE bsky_uri = ? LIMIT 1'
        );
        $st->execute([$atUri]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($row)) {
        return null;
    }
    $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
    if (!is_array($raw)) {
        $raw = [];
    }
    // Backfilled rows sometimes store text/embed beside a thin raw_json.
    if (trim((string) (($raw['record']['text'] ?? '') ?: '')) === '' && trim((string) ($row['text'] ?? '')) !== '') {
        if (!is_array($raw['record'] ?? null)) {
            $raw['record'] = [];
        }
        $raw['record']['text'] = (string) $row['text'];
    }
    if (!is_array($raw['embed'] ?? null) && !is_array($raw['record']['embed'] ?? null)) {
        $embed = json_decode((string) ($row['embed_json'] ?? ''), true);
        if (is_array($embed)) {
            $raw['embed'] = $embed;
        }
    }
    $parts = admin_reply_preview_bsky_parts($raw);
    $handle = trim((string) ($row['author_handle'] ?? ''));
    $name = trim((string) ($row['author_display'] ?? ''));
    $did = trim((string) ($row['author_did'] ?? ''));
    if ($handle !== '' && $handle[0] !== '@') {
        $handle = '@' . $handle;
    }
    if ($name === '') {
        $name = ltrim($handle, '@');
    }
    $avatar = admin_reply_preview_https_url((string) ($row['author_avatar'] ?? ''));
    $snippet = admin_reply_preview_plain($parts['text']);
    $actor = $did !== '' ? $did : ($handle !== '' ? 'https://bsky.app/profile/' . rawurlencode(ltrim($handle, '@')) : '');
    if (admin_reply_preview_actor_hidden($actor) || ($did !== '' && admin_reply_preview_actor_hidden($did))) {
        return null;
    }
    if ($handle !== '') {
        $profile = 'https://bsky.app/profile/' . ltrim($handle, '@');
        if (admin_reply_preview_actor_hidden($profile)) {
            return null;
        }
    }
    if (admin_reply_preview_words_hidden($parts['text'], $snippet, $name)) {
        return null;
    }
    return admin_reply_preview_pack(
        [
            'name' => admin_reply_preview_plain($name, 80),
            'handle' => $handle,
            'avatar' => $avatar,
        ],
        $snippet,
        (bool) $parts['media'],
        (bool) $parts['cw'],
        (bool) $parts['quote']
    );
}

function admin_reply_preview_from_mention(string $objectId): ?array
{
    $objectId = rtrim(trim($objectId), '/');
    $owner = admin_reply_preview_owner_id();
    if ($objectId === '' || $owner < 1 || !function_exists('ap_db')) {
        return null;
    }
    try {
        $st = ap_db()->prepare(
            'SELECT actor_id, content, media_urls, spoiler_text, sensitive
             FROM mentions
             WHERE owner_user_id = ?
               AND deleted_at IS NULL
               AND (object_id = ? OR object_id = ?)
             LIMIT 1'
        );
        $st->execute([$owner, $objectId, $objectId . '/']);
        $row = $st->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($row)) {
        return null;
    }
    $actor = rtrim((string) ($row['actor_id'] ?? ''), '/');
    $content = (string) ($row['content'] ?? '');
    $spoiler = trim((string) ($row['spoiler_text'] ?? ''));
    $snippet = admin_reply_preview_plain($content);
    if ($snippet === '' && $spoiler !== '') {
        $snippet = admin_reply_preview_plain($spoiler);
    }
    // Delivered to this owner, so it is not a strangers' direct. Still honor blocks.
    if (!admin_reply_preview_viewer_may_see('public', $actor, $objectId, $content, $spoiler, $snippet)) {
        return null;
    }
    return admin_reply_preview_pack(
        admin_reply_preview_actor_bits($actor),
        $snippet,
        admin_reply_preview_media_list_nonempty($row['media_urls'] ?? null),
        $spoiler !== '' || !empty($row['sensitive']),
        admin_reply_preview_text_has_quote($content)
    );
}

/**
 * One cheap local lookup for a reply target. Null means "keep the old label".
 *
 * @return array{name:string,handle:string,avatar:string,snippet:string,media:bool,cw:bool,quote:bool}|null
 */
function admin_reply_preview_lookup(string $uri): ?array
{
    $uri = trim($uri);
    if ($uri === '') {
        return null;
    }
    $norm = rtrim($uri, '/');
    if (!str_starts_with($norm, 'https://') && !str_starts_with($uri, 'at://')) {
        return null;
    }
    try {
        $at = admin_reply_preview_bsky_at($uri);
        if (is_string($at) && $at !== '') {
            $note = admin_reply_preview_crosspost_note($at);
            if (is_string($note) && $note !== '') {
                $fromNote = admin_reply_preview_from_masto($note);
                if ($fromNote !== null) {
                    return $fromNote;
                }
            }
            $fromBsky = admin_reply_preview_from_bsky($at);
            if ($fromBsky !== null) {
                return $fromBsky;
            }
        }
        if (str_starts_with($norm, 'https://')) {
            $fromMasto = admin_reply_preview_from_masto($norm);
            if ($fromMasto !== null) {
                return $fromMasto;
            }
            $fromEvent = admin_reply_preview_from_event($norm);
            if ($fromEvent !== null) {
                return $fromEvent;
            }
            return admin_reply_preview_from_mention($norm);
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}
