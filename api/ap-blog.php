<?php
/**
 * Blog composer v2: one save/publish/delete service for the Blog page form,
 * its autosave and preview calls, the token API (POST /api/v1/vaak/blog) and
 * the scheduled publisher (ap-queue-publish.php cron).
 *
 * The original form contract stays valid: action=blog_save with blog_title,
 * blog_category, blog_body, blog_tags, blog_slug and blog_publish still saves
 * and publishes exactly as before (public teaser, title as content warning,
 * usual Bluesky mirror). The v2 fields only apply when blog_v2=1 is posted,
 * so older callers such as Shana's posting job keep their behaviour.
 *
 * Markdown is rendered by the Rust worker (/internal/blog/render) and stored
 * in body_html. When the worker is unavailable body_html stays empty and the
 * pages fall back to their original renderers.
 */
declare(strict_types=1);

const AP_BLOG_TEASER_VISIBILITIES = ['public', 'unlisted', 'private', 'none'];

/** Load the outbox library (publish, edit, delete) unless it is already in. */
function ap_blog_require_inbox(): void
{
    if (function_exists('ap_publish_status_text')) {
        return;
    }
    if (!defined('AP_INBOX_LIB_ONLY')) {
        define('AP_INBOX_LIB_ONLY', true);
    }
    require_once __DIR__ . '/ap-inbox.php';
}

/** Plain-text summary of Markdown for teasers and lists. */
function ap_blog_excerpt(string $markdown, int $limit = 420): string
{
    $plain = preg_replace('/^```.*$/m', '', $markdown) ?? $markdown;
    $plain = preg_replace('/!\[([^\]]*)\]\([^\)]*\)/', '', $plain) ?? $plain;
    $plain = trim(html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $plain = preg_replace('/^\s{0,3}(?:#+|>|[-*+]|\d+[.)])\s*/m', '', $plain) ?? $plain;
    $plain = preg_replace('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/m', '', $plain) ?? $plain;
    $plain = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $plain) ?? $plain;
    $plain = preg_replace('/(\*\*|__|~~|`)/', '', $plain) ?? $plain;
    $plain = preg_replace('/(?<![\p{L}\p{N}])[*_]([^*_\n]+)[*_](?![\p{L}\p{N}])/u', '$1', $plain) ?? $plain;
    $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
    return function_exists('mb_strimwidth') ? mb_strimwidth($plain, 0, $limit, '…') : substr($plain, 0, $limit);
}

/** @return list<string> */
function ap_blog_parse_tags(string $raw): array
{
    $tags = [];
    foreach (preg_split('/[,\s]+/', $raw) ?: [] as $tag) {
        $tag = ltrim(trim((string) $tag), '#');
        $tag = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $tag) ?? '';
        if ($tag !== '') {
            $tags[mb_strtolower($tag)] = true;
        }
        if (count($tags) >= 30) {
            break;
        }
    }
    return array_keys($tags);
}

/** Lowercase URL slug a person typed, or '' when nothing usable is left. */
function ap_blog_clean_slug(string $raw): string
{
    $slug = ap_blog_slug($raw);
    $slug = trim(substr($slug, 0, 80), '-');
    return ($slug === 'post' && !preg_match('/^\s*post\s*$/i', $raw)) ? '' : $slug;
}

/**
 * Render Markdown through the worker. Null when the worker is off or failed.
 *
 * @return array{html:string,words:int,minutes:int}|null
 */
function ap_blog_render(string $markdown): ?array
{
    if (getenv('VAAK_BLOG_NATIVE') !== '1' || !function_exists('curl_init')) {
        return null;
    }
    $secret = (string) getenv('VAAK_ADMIN_READ_SECRET');
    if (strlen($secret) < 32) {
        return null;
    }
    $url = (string) (getenv('VAAK_BLOG_RENDER_URL') ?: 'http://127.0.0.1:8787/internal/blog/render');
    if (function_exists('ap_admin_native_url_allowed') && !ap_admin_native_url_allowed($url, '/internal/blog/render')) {
        return null;
    }
    try {
        $payload = json_encode(['protocol_version' => 1, 'markdown' => $markdown], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = ap_blog_worker_post($url, $payload, $secret, 1048576);
        if ($body === null) {
            return null;
        }
        $reply = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($reply) || ($reply['protocol_version'] ?? null) !== 1 || !is_string($reply['html'] ?? null)
        || !is_int($reply['words'] ?? null) || !is_int($reply['minutes'] ?? null)) {
        return null;
    }
    return ['html' => $reply['html'], 'words' => $reply['words'], 'minutes' => $reply['minutes']];
}

/** POST JSON to a loopback worker route; the body, or null on any failure. */
function ap_blog_worker_post(string $url, string $payload, string $secret, int $maxBytes): ?string
{
    if (!str_starts_with($url, 'http://127.0.0.1:') && !str_starts_with($url, 'http://localhost:')) {
        return null;
    }
    $curl = curl_init($url);
    if ($curl === false) {
        return null;
    }
    $body = '';
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT_MS => 250,
        CURLOPT_TIMEOUT_MS => 4000,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Vaak-Admin-Token: ' . $secret],
    ]);
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return ($ok === true && $status === 200) ? $body : null;
}

/** Re-render one row's stored HTML (used after site publishing). */
function ap_blog_refresh_html(string $actorKey, string $slug): void
{
    $row = ap_blog_post_get($actorKey, $slug, false);
    if (!is_array($row)) {
        return;
    }
    $render = ap_blog_render((string) ($row['body_markdown'] ?? ''));
    if ($render !== null) {
        ap_blog_update_fields((int) $row['owner_user_id'], $actorKey, $slug, ['body_html' => $render['html']]);
    }
}

/**
 * Normalize a request (form POST or API JSON) into service input.
 * Keys absent from a v1 form keep their old meaning and v2 defaults.
 *
 * @param array<string, mixed> $src
 * @return array<string, mixed>
 */
function ap_blog_input(array $src, bool $api = false): array
{
    $get = static function (string $form, string $json) use ($src, $api): ?string {
        $key = $api ? $json : $form;
        if (!array_key_exists($key, $src) || is_array($src[$key])) {
            return null;
        }
        return trim((string) $src[$key]);
    };
    $flag = static function (string $form, string $json, bool $default) use ($src, $api): bool {
        $key = $api ? $json : $form;
        if (!array_key_exists($key, $src)) {
            return $default;
        }
        $v = $src[$key];
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
    };
    // v2 checkboxes post a hidden 0 before the box, so absence means an old caller.
    $v2 = $api || (string) ($src['blog_v2'] ?? '') === '1';
    $publishRaw = $api ? ($src['publish'] ?? true) : ($src['blog_publish'] ?? '');
    return [
        'v2' => $v2,
        'title' => (string) ($get('blog_title', 'title') ?? ''),
        'category' => (string) ($get('blog_category', 'category') ?? ''),
        'body' => (string) ($get('blog_body', 'body') ?? ''),
        'tags' => ap_blog_parse_tags($api && is_array($src['tags'] ?? null)
            ? implode(' ', array_map('strval', $src['tags']))
            : (string) ($get('blog_tags', 'tags') ?? '')),
        'existing_slug' => (string) ($get('blog_slug', 'edit_slug') ?? ''),
        'publish' => is_bool($publishRaw) ? $publishRaw : !empty($publishRaw) && (string) $publishRaw !== '0' && strtolower((string) $publishRaw) !== 'false',
        'subtitle' => $v2 ? $get('blog_subtitle', 'subtitle') : null,
        'summary' => $v2 ? $get('blog_summary', 'summary') : null,
        'slug' => $v2 ? $get('blog_url_slug', 'slug') : null,
        'when' => $api
            ? ((string) ($get('blog_schedule_at', 'scheduled_at') ?? '') !== '' ? 'schedule' : 'now')
            : ($v2 && ($get('blog_when', 'when') ?? 'now') === 'schedule' ? 'schedule' : 'now'),
        'schedule_at' => $v2 ? (string) ($get('blog_schedule_at', 'scheduled_at') ?? '') : '',
        'tz_offset' => $v2 ? (int) ($get('blog_tz_offset', 'tz_offset') ?? 0) : 0,
        'teaser_visibility' => $v2 ? ($get('blog_teaser_visibility', 'teaser_visibility') ?? 'public') : null,
        'teaser_cw' => $v2 ? $flag('blog_teaser_cw', 'teaser_cw', true) : null,
        'teaser_bsky' => $v2 ? $flag('blog_teaser_bsky', 'teaser_bsky', true) : null,
        'teaser_nostr' => $v2 && !$api ? $flag('blog_teaser_nostr', 'teaser_nostr', false) : null,
        'cover_alt' => $v2 ? $get('blog_cover_alt', 'cover_alt') : null,
        'cover_remove' => $v2 && !$api && $flag('blog_cover_remove', 'cover_remove', false),
    ];
}

/** Parse the schedule field into a UTC timestamp, or an error message. */
function ap_blog_schedule_time(string $raw, int $tzOffsetMinutes, int $now): int|string
{
    $raw = trim($raw);
    if ($raw === '') {
        return 'Pick a date and time to schedule the post.';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
        // datetime-local is the browser's wall clock; the form sends getTimezoneOffset().
        $ts = gmmktime((int) $m[4], (int) $m[5], (int) ($m[6] ?? 0), (int) $m[2], (int) $m[3], (int) $m[1]);
        $ts += max(-900, min(900, $tzOffsetMinutes)) * 60;
    } else {
        $ts = strtotime($raw);
    }
    if ($ts === false) {
        return 'That schedule time could not be read.';
    }
    if ($ts < $now + 60) {
        return 'Schedule the post at least a minute from now.';
    }
    if ($ts > $now + 366 * 86400) {
        return 'Schedule the post within the next year.';
    }
    return $ts;
}

/** Public HTML profile URL of a blog post. */
function ap_blog_public_url(string $actorKey, string $slug): string
{
    return 'https://mkultra.monster/users/' . rawurlencode($actorKey) . '?tab=blog&post=' . rawurlencode($slug);
}

/**
 * Teaser status text and CW for a row, as the timeline post shows it.
 *
 * @param array<string, mixed> $row
 * @return array{content:string,spoiler:string}
 */
function ap_blog_teaser(array $row, string $actorKey): array
{
    $title = trim((string) ($row['title'] ?? ''));
    $excerpt = trim((string) ($row['excerpt'] ?? ''));
    $tags = ap_blog_tags_decode($row['tags_json'] ?? '[]');
    $tagLine = $tags !== [] ? "\n\n" . implode(' ', array_map(static fn(string $tag): string => '#' . $tag, $tags)) : '';
    $cw = (int) ($row['teaser_cw'] ?? 1) !== 0;
    $content = ($cw ? '' : $title . "\n\n") . $excerpt . $tagLine
        . "\n\nRead the full post: " . ap_blog_public_url($actorKey, (string) $row['slug']);
    return ['content' => ltrim($content), 'spoiler' => $cw ? $title : ''];
}

/**
 * Ingest an uploaded cover image for the blog.
 *
 * @param array<string, mixed> $file
 * @return array{ok:bool,error?:string,url?:string,media_id?:int}
 */
function ap_blog_ingest_cover(array $file, string $alt): array
{
    if (!function_exists('ap_media_ingest_upload')) {
        require_once __DIR__ . '/ap-r2.php';
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
        return ['ok' => false, 'error' => 'The cover image did not upload. Try again.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    if (!str_starts_with(strtolower($mime), 'image/')) {
        return ['ok' => false, 'error' => 'The cover must be an image.'];
    }
    $alt = mb_substr(trim($alt), 0, 1500);
    $up = ap_media_ingest_upload($file, $alt !== '' ? $alt : null);
    if (empty($up['ok'])) {
        return ['ok' => false, 'error' => (string) ($up['error'] ?? 'Cover upload failed.')];
    }
    $attachment = is_array($up['attachment'] ?? null) ? $up['attachment'] : [];
    $url = (string) ($attachment['url'] ?? '');
    if (($attachment['type'] ?? '') !== 'image' || !str_starts_with($url, 'https://')) {
        return ['ok' => false, 'error' => 'The cover must be a still or animated image.'];
    }
    return ['ok' => true, 'url' => $url, 'media_id' => (int) ($up['local_id'] ?? 0)];
}

/**
 * A media row for the teaser post that shows the cover. It points at the
 * cover's file but has no storage key, so deleting the teaser (by hand or by
 * post retention) never deletes the file the blog post still uses.
 */
function ap_blog_teaser_media_copy(int $coverMediaId, string $alt): int
{
    if ($coverMediaId < 1) {
        return 0;
    }
    try {
        $db = ap_db();
        $st = $db->prepare('SELECT * FROM masto_media WHERE local_id = ?');
        $st->execute([$coverMediaId]);
        $m = $st->fetch();
        if (!is_array($m) || !str_starts_with((string) ($m['public_url'] ?? ''), 'https://')) {
            return 0;
        }
        $db->prepare(
            "INSERT INTO masto_media (owner_user_id, created_at, s3_key, public_url, preview_url, media_type, mime, file_size, width, height, blurhash, description, status_local_id)
             VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)"
        )->execute([
            (int) $m['owner_user_id'], ap_db_now(), (string) $m['public_url'], $m['preview_url'] ?? null,
            (string) $m['media_type'], (string) $m['mime'], (int) ($m['file_size'] ?? 0),
            $m['width'] ?? null, $m['height'] ?? null, $m['blurhash'] ?? null,
            $alt !== '' ? $alt : ($m['description'] ?? null),
        ]);
        return (int) ap_db_last_insert_id('masto_media', 'local_id');
    } catch (Throwable $e) {
        error_log('[ap-blog] teaser media copy: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Save, publish, schedule or update a blog post.
 *
 * @param array<string, mixed> $in from ap_blog_input()
 * @param array<string, mixed>|null $coverFile one $_FILES entry, or null
 * @param bool $autosave true for the editor's background draft saves
 * @return array{ok:bool,error?:string,notice?:string,slug?:string,status?:string,note_id?:string,nostr?:bool,saved_at?:string,url?:string}
 */
function ap_blog_save(int $ownerId, string $actorKey, array $in, ?array $coverFile = null, bool $autosave = false): array
{
    $actorKey = strtolower(preg_replace('/[^a-z0-9_]/i', '', $actorKey) ?? '');
    if ($ownerId < 1 || $actorKey === '') {
        return ['ok' => false, 'error' => 'Sign in to write a blog post.'];
    }
    $title = (string) $in['title'];
    $body = (string) $in['body'];
    $category = (string) $in['category'];
    if ($title === '' || mb_strlen($title) > 240) {
        return ['ok' => false, 'error' => 'A blog title is required (maximum 240 characters).'];
    }
    if ($body === '' || mb_strlen($body) > 200000) {
        return ['ok' => false, 'error' => 'Blog content is required and must be under 200,000 characters.'];
    }
    if (mb_strlen($category) > 120) {
        return ['ok' => false, 'error' => 'Category must be under 120 characters.'];
    }
    if ($in['subtitle'] !== null && mb_strlen((string) $in['subtitle']) > 300) {
        return ['ok' => false, 'error' => 'Subtitle must be under 300 characters.'];
    }
    if ($in['summary'] !== null && mb_strlen((string) $in['summary']) > 1000) {
        return ['ok' => false, 'error' => 'Summary must be under 1,000 characters.'];
    }
    $visibility = $in['teaser_visibility'];
    if ($visibility !== null && !in_array($visibility, AP_BLOG_TEASER_VISIBILITIES, true)) {
        return ['ok' => false, 'error' => 'Unknown teaser visibility.'];
    }

    $existing = null;
    if ((string) $in['existing_slug'] !== '') {
        $existing = ap_blog_post_get($actorKey, (string) $in['existing_slug'], false);
        if (!is_array($existing) || (int) ($existing['owner_user_id'] ?? 0) !== $ownerId) {
            return ['ok' => false, 'error' => 'That blog post was not found.'];
        }
    }
    $status = is_array($existing) ? (string) ($existing['status'] ?? 'draft') : 'draft';
    if ($status === 'publishing') {
        return ['ok' => false, 'error' => 'This post is being published right now. Try again in a minute.'];
    }
    $published = $status === 'published';
    if ($autosave && $published) {
        return ['ok' => false, 'error' => 'Published posts are saved when you press Update.'];
    }

    // Slug: new posts may name their URL; drafts and scheduled posts may rename it.
    $wantedSlug = $in['slug'] !== null && $in['slug'] !== '' ? ap_blog_clean_slug((string) $in['slug']) : '';
    if ($in['slug'] !== null && $in['slug'] !== '' && $wantedSlug === '') {
        return ['ok' => false, 'error' => 'The URL may use letters, numbers and dashes.'];
    }
    $slug = is_array($existing) ? (string) $existing['slug'] : '';
    $newSlug = $slug;
    if (!$published && $wantedSlug !== '' && $wantedSlug !== $slug) {
        if (ap_blog_slug_taken($ownerId, $wantedSlug)) {
            return ['ok' => false, 'error' => 'You already have a post at that URL. Pick another.'];
        }
        $newSlug = $wantedSlug;
    }

    $now = time();
    $scheduleTs = null;
    $wantsPublish = !$autosave && !empty($in['publish']);
    if ($wantsPublish && !$published && $in['when'] === 'schedule') {
        $scheduleTs = ap_blog_schedule_time((string) $in['schedule_at'], (int) $in['tz_offset'], $now);
        if (is_string($scheduleTs)) {
            return ['ok' => false, 'error' => $scheduleTs];
        }
    }

    $summary = $in['summary'] !== null ? (string) $in['summary'] : (string) ($existing['summary'] ?? '');
    $excerpt = $summary !== '' ? $summary : ap_blog_excerpt($body, 420);
    $render = ap_blog_render($body);
    $fields = [
        'title' => $title,
        'category' => $category,
        'tags_json' => json_encode(array_values($in['tags']), JSON_UNESCAPED_UNICODE),
        'body_markdown' => $body,
        'excerpt' => $excerpt,
        'summary' => $summary,
        'body_html' => $render['html'] ?? '',
    ];
    if ($in['subtitle'] !== null) {
        $fields['subtitle'] = (string) $in['subtitle'];
    }
    // Teaser options are fixed once the teaser exists.
    if (!$published && $in['v2']) {
        $fields['teaser_visibility'] = (string) $visibility;
        $fields['teaser_cw'] = $in['teaser_cw'] ? 1 : 0;
        $fields['teaser_bsky'] = $in['teaser_bsky'] ? 1 : 0;
        if ($in['teaser_nostr'] !== null) {
            $fields['teaser_nostr'] = $in['teaser_nostr'] ? 1 : 0;
        }
    }
    $coverChanged = false;
    if ($in['cover_alt'] !== null) {
        $fields['cover_alt'] = mb_substr((string) $in['cover_alt'], 0, 1500);
    }
    if (!$autosave && $in['cover_remove']) {
        $fields['cover_url'] = '';
        $fields['cover_media_id'] = null;
        $fields['cover_teaser_media_id'] = null;
        $coverChanged = (string) ($existing['cover_url'] ?? '') !== '';
    } elseif (!$autosave && is_array($coverFile) && (int) ($coverFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $cover = ap_blog_ingest_cover($coverFile, (string) ($in['cover_alt'] ?? ''));
        if (empty($cover['ok'])) {
            return ['ok' => false, 'error' => (string) $cover['error']];
        }
        $fields['cover_url'] = (string) $cover['url'];
        $fields['cover_media_id'] = (int) $cover['media_id'];
        $fields['cover_teaser_media_id'] = null;
        $coverChanged = true;
    }

    if ($existing === null) {
        if ($newSlug === '') {
            $newSlug = ap_blog_slug($title, substr(bin2hex(random_bytes(4)), 0, 8));
        }
        $row = ap_blog_create($ownerId, $actorKey, $newSlug, $title, $category, $in['tags'], $body, $excerpt, 'draft');
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'Could not save the blog post.'];
        }
        unset($fields['title'], $fields['category'], $fields['tags_json'], $fields['body_markdown'], $fields['excerpt']);
        if ($fields !== []) {
            ap_blog_update_fields($ownerId, $actorKey, $newSlug, $fields);
        }
    } else {
        if ($newSlug !== $slug) {
            $fields['slug'] = $newSlug;
        }
        if ($status === 'scheduled' && !$wantsPublish) {
            // "Save draft" on a scheduled post unschedules it.
            $fields['status'] = 'draft';
            $fields['scheduled_at'] = null;
        }
        if (!ap_blog_update_fields($ownerId, $actorKey, $slug, $fields)) {
            return ['ok' => false, 'error' => 'Could not save the blog post.'];
        }
    }
    $slug = $newSlug;
    $savedAt = gmdate('c');

    if ($autosave) {
        return ['ok' => true, 'slug' => $slug, 'status' => $status === 'scheduled' ? 'scheduled' : 'draft', 'saved_at' => $savedAt];
    }

    $row = ap_blog_post_get($actorKey, $slug, false) ?? [];
    if ($published) {
        $teaserResult = ap_blog_update_teaser($row, $actorKey, $coverChanged);
        $notice = 'Blog post updated.' . ($teaserResult === null ? '' : ($teaserResult ? ' The timeline teaser was updated too.' : ' The timeline teaser could not be updated.'));
        return ['ok' => true, 'notice' => $notice, 'slug' => $slug, 'status' => 'published', 'url' => ap_blog_public_url($actorKey, $slug)];
    }
    if (!$wantsPublish) {
        return ['ok' => true, 'notice' => 'Blog draft saved.', 'slug' => $slug, 'status' => 'draft', 'saved_at' => $savedAt];
    }
    if ($scheduleTs !== null) {
        ap_blog_update_fields($ownerId, $actorKey, $slug, ['status' => 'scheduled', 'scheduled_at' => gmdate('Y-m-d\TH:i:s\Z', $scheduleTs), 'last_error' => '']);
        return ['ok' => true, 'notice' => 'Blog post scheduled.', 'slug' => $slug, 'status' => 'scheduled', 'scheduled_at' => gmdate('c', $scheduleTs)];
    }
    if (!ap_blog_claim_status($ownerId, $actorKey, $slug, $status, 'publishing')) {
        return ['ok' => false, 'error' => 'This post is already being published.', 'slug' => $slug];
    }
    $pub = ap_blog_publish_row(ap_blog_post_get($actorKey, $slug, false) ?? $row, $actorKey);
    if (empty($pub['ok'])) {
        ap_blog_update_fields($ownerId, $actorKey, $slug, ['status' => 'draft', 'scheduled_at' => null, 'last_error' => mb_substr((string) ($pub['error'] ?? 'unknown error'), 0, 500)]);
        return ['ok' => false, 'error' => 'Draft saved, but publishing failed: ' . (string) ($pub['error'] ?? 'unknown error'), 'slug' => $slug];
    }
    return [
        'ok' => true,
        'notice' => 'Blog post published.',
        'slug' => $slug,
        'status' => 'published',
        'note_id' => (string) ($pub['note_id'] ?? ''),
        'nostr' => (int) ($row['teaser_nostr'] ?? 0) === 1 && (string) ($pub['note_id'] ?? '') !== '' && (string) ($row['teaser_visibility'] ?? 'public') === 'public',
        'url' => ap_blog_public_url($actorKey, $slug),
    ];
}

/**
 * Post the teaser for a row already claimed as 'publishing' and mark it
 * published. The caller has set the request actor to the post's owner.
 *
 * @param array<string, mixed> $row
 * @return array{ok:bool,error?:string,note_id?:string}
 */
function ap_blog_publish_row(array $row, string $actorKey): array
{
    $ownerId = (int) ($row['owner_user_id'] ?? 0);
    $slug = (string) ($row['slug'] ?? '');
    if ($ownerId < 1 || $slug === '') {
        return ['ok' => false, 'error' => 'Blog post not found.'];
    }
    $visibility = (string) ($row['teaser_visibility'] ?? 'public');
    $noteId = '';
    if ($visibility !== 'none') {
        ap_blog_require_inbox();
        $teaser = ap_blog_teaser($row, $actorKey);
        $media = [];
        $teaserMedia = ap_blog_teaser_media_copy((int) ($row['cover_media_id'] ?? 0), (string) ($row['cover_alt'] ?? ''));
        if ($teaserMedia > 0) {
            $media[] = $teaserMedia;
            ap_blog_update_fields($ownerId, $actorKey, $slug, ['cover_teaser_media_id' => $teaserMedia]);
        }
        $prevSkip = $GLOBALS['ap_publish_skip_bsky'] ?? null;
        $GLOBALS['ap_publish_skip_bsky'] = (int) ($row['teaser_bsky'] ?? 1) === 0;
        try {
            $published = ap_publish_status_text(
                $teaser['content'],
                null,
                null,
                $media,
                $teaser['spoiler'],
                $teaser['spoiler'] !== '' ? true : null,
                null,
                null,
                in_array($visibility, ['public', 'unlisted', 'private'], true) ? $visibility : 'public'
            );
        } finally {
            $GLOBALS['ap_publish_skip_bsky'] = $prevSkip;
        }
        if (empty($published['ok'])) {
            return ['ok' => false, 'error' => (string) ($published['error'] ?? 'unknown error')];
        }
        $noteId = (string) ($published['note_id'] ?? '');
        ap_blog_set_note($actorKey, $slug, $noteId);
    }
    ap_blog_update_fields($ownerId, $actorKey, $slug, ['status' => 'published', 'published_at' => ap_db_now(), 'scheduled_at' => null, 'last_error' => '']);
    return ['ok' => true, 'note_id' => $noteId];
}

/**
 * Federate an Update of a published post's teaser. Null when it has none.
 *
 * @param array<string, mixed> $row
 */
function ap_blog_update_teaser(array $row, string $actorKey, bool $coverChanged): ?bool
{
    $noteId = (string) ($row['note_id'] ?? '');
    if ($noteId === '') {
        return null;
    }
    ap_blog_require_inbox();
    $status = ap_masto_status_by_note_id($noteId);
    if (!is_array($status)) {
        return null;
    }
    $teaser = ap_blog_teaser($row, $actorKey);
    $media = null;
    if ($coverChanged) {
        $teaserMedia = ap_blog_teaser_media_copy((int) ($row['cover_media_id'] ?? 0), (string) ($row['cover_alt'] ?? ''));
        $media = $teaserMedia > 0 ? [$teaserMedia] : [];
        ap_blog_update_fields((int) $row['owner_user_id'], $actorKey, (string) $row['slug'], ['cover_teaser_media_id' => $teaserMedia > 0 ? $teaserMedia : null]);
    }
    $res = ap_update_local_status((int) $status['local_id'], $teaser['content'], $teaser['spoiler'], $teaser['spoiler'] !== '' ? true : null, $media);
    return !empty($res['ok']);
}

/**
 * Delete a post and federate a Delete of its teaser.
 *
 * @return array{ok:bool,error?:string,notice?:string}
 */
function ap_blog_delete(int $ownerId, string $actorKey, string $slug): array
{
    $row = ap_blog_post_get($actorKey, $slug, false);
    if (!is_array($row) || (int) ($row['owner_user_id'] ?? 0) !== $ownerId) {
        return ['ok' => false, 'error' => 'That blog post was not found.'];
    }
    if ((string) ($row['status'] ?? '') === 'publishing') {
        return ['ok' => false, 'error' => 'This post is being published right now. Try again in a minute.'];
    }
    $noteId = (string) ($row['note_id'] ?? '');
    if ($noteId !== '') {
        ap_blog_require_inbox();
        $status = ap_masto_status_by_note_id($noteId);
        if (is_array($status)) {
            $res = ap_delete_local_status((int) $status['local_id']);
            if (empty($res['ok']) && ($res['error'] ?? '') !== 'Status not found') {
                return ['ok' => false, 'error' => 'Could not delete the timeline teaser: ' . (string) ($res['error'] ?? 'unknown error')];
            }
        }
    }
    if (!ap_blog_delete_row($ownerId, $actorKey, $slug)) {
        return ['ok' => false, 'error' => 'Could not delete the blog post.'];
    }
    return ['ok' => true, 'notice' => (string) ($row['status'] ?? '') === 'published' ? 'Blog post deleted.' : 'Blog draft deleted.'];
}

/**
 * Publish scheduled posts that are due (cron: ap-queue-publish.php).
 *
 * @return array{due:int,published:int,failed:int}
 */
function ap_blog_publish_due(int $limit = 5, ?int $now = null): array
{
    $now ??= time();
    $stats = ['due' => 0, 'published' => 0, 'failed' => 0];
    foreach (ap_blog_scheduled_rows(50) as $row) {
        if ($stats['due'] >= $limit) {
            break;
        }
        $ownerId = (int) ($row['owner_user_id'] ?? 0);
        $actorKey = (string) ($row['actor_key'] ?? '');
        $slug = (string) ($row['slug'] ?? '');
        $status = (string) ($row['status'] ?? '');
        if ($status === 'publishing') {
            // A crashed run left it claimed; give it back after ten minutes.
            $touched = strtotime((string) ($row['updated_at'] ?? '')) ?: $now;
            if ($touched < $now - 600 && (string) ($row['note_id'] ?? '') === '') {
                ap_blog_claim_status($ownerId, $actorKey, $slug, 'publishing', 'scheduled');
            }
            continue;
        }
        $due = strtotime((string) ($row['scheduled_at'] ?? '')) ?: PHP_INT_MAX;
        if ($due > $now) {
            continue;
        }
        $stats['due']++;
        if (!ap_blog_claim_status($ownerId, $actorKey, $slug, 'scheduled', 'publishing')) {
            continue;
        }
        $prevActor = function_exists('ap_request_actor_get') ? ap_request_actor_get() : null;
        if (function_exists('ap_request_actor_set')) {
            ap_request_actor_set($actorKey);
        }
        try {
            $pub = ap_blog_publish_row($row, $actorKey);
        } catch (Throwable $e) {
            $pub = ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            if (function_exists('ap_request_actor_set')) {
                ap_request_actor_set(is_array($prevActor) && !empty($prevActor['key']) ? (string) $prevActor['key'] : null);
            }
        }
        if (!empty($pub['ok'])) {
            $stats['published']++;
        } else {
            $stats['failed']++;
            // Back to drafts with the reason, so it never retries in a loop.
            ap_blog_update_fields($ownerId, $actorKey, $slug, ['status' => 'draft', 'scheduled_at' => null, 'last_error' => 'Scheduled publish failed: ' . mb_substr((string) ($pub['error'] ?? 'unknown error'), 0, 400)]);
        }
    }
    return $stats;
}

/**
 * The Blog page body painted by the worker, or null to keep the PHP page.
 *
 * @param array<string, mixed>|null $saveResult the last save's reply, if any
 */
function ap_blog_native_page(int $ownerId, string $actorKey, string $csrf, string $editSlug, ?array $saveResult, bool $failedPost, bool $nostrAvailable): ?string
{
    if (getenv('VAAK_BLOG_NATIVE') !== '1' || !function_exists('curl_init') || $ownerId < 1 || $actorKey === '' || $csrf === '') {
        return null;
    }
    $secret = (string) getenv('VAAK_ADMIN_READ_SECRET');
    if (strlen($secret) < 32) {
        return null;
    }
    $url = (string) (getenv('VAAK_BLOG_EDITOR_URL') ?: 'http://127.0.0.1:8787/internal/blog/editor');
    if (function_exists('ap_admin_native_url_allowed') && !ap_admin_native_url_allowed($url, '/internal/blog/editor')) {
        return null;
    }
    $submitted = null;
    if ($failedPost) {
        $p = static fn(string $k): string => is_string($_POST[$k] ?? null) ? (string) $_POST[$k] : '';
        $flag = static fn(string $k, bool $d): bool => array_key_exists($k, $_POST) ? in_array((string) (is_array($_POST[$k]) ? end($_POST[$k]) : $_POST[$k]), ['1', 'on', 'true'], true) : $d;
        $submitted = [
            'title' => $p('blog_title'), 'subtitle' => $p('blog_subtitle'), 'category' => $p('blog_category'),
            'body' => $p('blog_body'), 'tags' => $p('blog_tags'), 'summary' => $p('blog_summary'),
            'slug' => $p('blog_url_slug'), 'cover_alt' => $p('blog_cover_alt'), 'when' => $p('blog_when'),
            'schedule_at' => $p('blog_schedule_at'), 'teaser_visibility' => $p('blog_teaser_visibility') ?: 'public',
            'teaser_cw' => $flag('blog_teaser_cw', true), 'teaser_bsky' => $flag('blog_teaser_bsky', true),
            'teaser_nostr' => $flag('blog_teaser_nostr', false),
        ];
        $editSlug = trim($p('blog_slug')) ?: $editSlug;
    } elseif (is_array($saveResult) && !empty($saveResult['ok'])) {
        // Keep editing a saved draft or an updated post; start fresh after publish/schedule/delete.
        $status = (string) ($saveResult['status'] ?? '');
        $updated = $status === 'published' && str_starts_with((string) ($saveResult['notice'] ?? ''), 'Blog post updated');
        $editSlug = ($status === 'draft' || $updated) ? (string) ($saveResult['slug'] ?? '') : '';
    }
    $payload = [
        'protocol_version' => 1,
        'owner_id' => $ownerId,
        'actor_key' => $actorKey,
        'csrf' => $csrf,
        'edit_slug' => mb_substr($editSlug, 0, 256),
        'submitted' => $submitted,
        'nostr_available' => $nostrAvailable,
        'nostr_note_id' => is_array($saveResult) && !empty($saveResult['nostr']) ? (string) ($saveResult['note_id'] ?? '') : '',
    ];
    try {
        $body = ap_blog_worker_post($url, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $secret, 8388608);
        if ($body === null) {
            return null;
        }
        $reply = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return null;
    }
    return (is_array($reply) && ($reply['protocol_version'] ?? null) === 1 && is_string($reply['html'] ?? null)) ? $reply['html'] : null;
}

/**
 * Token API for apps and bots (Shana's daily post): POST creates, updates
 * (edit_slug) or schedules (scheduled_at) a post; GET lists your posts;
 * DELETE /api/v1/vaak/blog/{slug} removes one.
 *
 * @param array<string, mixed> $user the token's account
 * @param array<string, mixed> $in request JSON or form fields
 * @return array{status:int, body:array<string, mixed>}
 */
function ap_blog_api(string $method, string $slug, array $user, array $in): array
{
    $ownerId = (int) ($user['id'] ?? 0);
    $actorKey = strtolower((string) ($user['actor_key'] ?? ''));
    if ($ownerId < 1 || $actorKey === '') {
        return ['status' => 401, 'body' => ['error' => 'This token has no account.']];
    }
    if ($method === 'GET' && $slug === '') {
        $rows = [];
        foreach (ap_blog_posts_list($actorKey, false, 100) as $row) {
            if ((int) ($row['owner_user_id'] ?? 0) !== $ownerId) {
                continue;
            }
            $rows[] = [
                'slug' => (string) $row['slug'],
                'title' => (string) $row['title'],
                'status' => (string) $row['status'],
                'published_at' => $row['published_at'] ?? null,
                'scheduled_at' => $row['scheduled_at'] ?? null,
                'url' => ap_blog_public_url($actorKey, (string) $row['slug']),
                'note_id' => (string) ($row['note_id'] ?? ''),
            ];
        }
        return ['status' => 200, 'body' => $rows];
    }
    if ($method === 'DELETE' && $slug !== '') {
        $res = ap_blog_delete($ownerId, $actorKey, $slug);
        return ['status' => !empty($res['ok']) ? 200 : 422, 'body' => !empty($res['ok']) ? ['ok' => true] : ['error' => (string) $res['error']]];
    }
    if ($method !== 'POST' || $slug !== '') {
        return ['status' => 404, 'body' => ['error' => 'Not found']];
    }
    $res = ap_blog_save($ownerId, $actorKey, ap_blog_input($in, true));
    if (empty($res['ok'])) {
        return ['status' => 422, 'body' => ['error' => (string) ($res['error'] ?? 'Could not save the blog post.'), 'slug' => $res['slug'] ?? null]];
    }
    return ['status' => 200, 'body' => [
        'ok' => true,
        'slug' => (string) ($res['slug'] ?? ''),
        'status' => (string) ($res['status'] ?? ''),
        'url' => ap_blog_public_url($actorKey, (string) ($res['slug'] ?? '')),
        'note_id' => (string) ($res['note_id'] ?? ''),
        'scheduled_at' => $res['scheduled_at'] ?? null,
        'notice' => (string) ($res['notice'] ?? ''),
    ]];
}
