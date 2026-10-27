<?php
/**
 * Opt-in ListenBrainz "now playing" for local VAAK profiles.
 *
 * A user enters their ListenBrainz username in Settings > Profile. The cron
 * worker api/ap-listenbrainz-worker.php polls ListenBrainz and stores one
 * snapshot row per user in ap_listenbrainz_links. Profile pages only read that
 * row (one primary-key join); a page view never contacts ListenBrainz.
 *
 * The table comes from api/migrations/20261027_listenbrainz_links_postgres.sql
 * (applied by deploy/remote/migrate.php). Until it exists every helper here
 * returns nothing, so profiles look exactly as before.
 *
 * Markup parity: ap_listenbrainz_display() and ap_listenbrainz_profile_html()
 * are ported to rust/vaak-worker/src/listenbrainz.rs. Both sides are checked
 * against api/fixtures/listenbrainz-now-playing.json.
 *
 * Loading this file runs nothing and needs no database.
 */
declare(strict_types=1);

const AP_LISTENBRAINZ_API = 'https://api.listenbrainz.org';
/** A playing-now snapshot older than this (seconds) is shown as "Last played". */
const AP_LISTENBRAINZ_PLAYING_FRESH = 600;
/** Longest stored track, artist, or album text (characters). */
const AP_LISTENBRAINZ_TEXT_MAX = 200;

function ap_listenbrainz_sqlite_ddl(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS ap_listenbrainz_links (
    owner_user_id INTEGER PRIMARY KEY,
    lb_username TEXT NOT NULL,
    playing_track TEXT NOT NULL DEFAULT '',
    playing_artist TEXT NOT NULL DEFAULT '',
    playing_release TEXT NOT NULL DEFAULT '',
    playing_cover TEXT NOT NULL DEFAULT '',
    last_track TEXT NOT NULL DEFAULT '',
    last_artist TEXT NOT NULL DEFAULT '',
    last_release TEXT NOT NULL DEFAULT '',
    last_cover TEXT NOT NULL DEFAULT '',
    last_listened_at INTEGER NOT NULL DEFAULT 0,
    fetched_at INTEGER NOT NULL DEFAULT 0,
    next_fetch_at INTEGER NOT NULL DEFAULT 0,
    failures INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL;
}

/** True when ap_listenbrainz_links exists. SQLite (tests, dev) creates it on demand. */
function ap_listenbrainz_table_ready(?PDO $db = null): bool
{
    if (array_key_exists('ap_listenbrainz_table_ready', $GLOBALS) && is_bool($GLOBALS['ap_listenbrainz_table_ready'])) {
        return $GLOBALS['ap_listenbrainz_table_ready'];
    }
    $ok = false;
    try {
        $db ??= ap_db();
        if (strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite') {
            $db->exec(ap_listenbrainz_sqlite_ddl());
            $ok = true;
        } else {
            $name = $db->query("SELECT to_regclass('public.ap_listenbrainz_links')")->fetchColumn();
            $ok = is_string($name) && $name !== '';
        }
    } catch (Throwable $e) {
        $ok = false;
    }
    $GLOBALS['ap_listenbrainz_table_ready'] = $ok;
    return $ok;
}

/**
 * ListenBrainz uses MusicBrainz account names. Allow what those allow in
 * practice (letters, digits, space, . _ -), 1 to 64 characters. Returns the
 * trimmed name, '' for "turn it off", or null when the input is not valid.
 */
function ap_listenbrainz_normalize_username(string $raw): ?string
{
    $name = trim($raw);
    if ($name === '') {
        return '';
    }
    if (str_starts_with($name, '@')) {
        $name = substr($name, 1);
    }
    if (preg_match('~^https?://(?:www\.)?listenbrainz\.org/user/([^/?#]+)/?(?:[?#].*)?$~i', $name, $m)) {
        $name = rawurldecode($m[1]);
    }
    if (!preg_match('/^[\p{L}\p{N}_.\- ]{1,64}$/u', $name) || trim($name) !== $name) {
        return null;
    }
    return $name;
}

/** @return array<string,mixed>|null */
function ap_listenbrainz_link_for_user(int $userId, ?PDO $db = null): ?array
{
    if ($userId <= 0) {
        return null;
    }
    try {
        $db ??= ap_db();
        if (!ap_listenbrainz_table_ready($db)) {
            return null;
        }
        $st = $db->prepare('SELECT * FROM ap_listenbrainz_links WHERE owner_user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** One indexed join; profile headers call this instead of anything that fetches. */
function ap_listenbrainz_link_for_actor_key(string $actorKey, ?PDO $db = null): ?array
{
    $actorKey = strtolower(preg_replace('/[^a-z0-9_]/i', '', $actorKey) ?? '');
    if ($actorKey === '') {
        return null;
    }
    try {
        $db ??= ap_db();
        if (!ap_listenbrainz_table_ready($db)) {
            return null;
        }
        $st = $db->prepare(
            'SELECT l.* FROM ap_listenbrainz_links l
             INNER JOIN ap_users u ON u.id = l.owner_user_id
             WHERE u.actor_key = ? AND u.disabled_at IS NULL
             LIMIT 1'
        );
        $st->execute([$actorKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Save, change, or clear (empty name) the user's ListenBrainz username.
 * A new or changed name drops the old snapshot and is fetched on the next
 * worker run.
 *
 * @return array{ok:bool,error?:string,username?:string}
 */
function ap_listenbrainz_save_username(int $userId, string $raw, ?PDO $db = null): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Sign in again to change this.'];
    }
    $name = ap_listenbrainz_normalize_username($raw);
    if ($name === null) {
        return ['ok' => false, 'error' => 'That does not look like a ListenBrainz username.'];
    }
    try {
        $db ??= ap_db();
        if (!ap_listenbrainz_table_ready($db)) {
            return ['ok' => false, 'error' => 'ListenBrainz is not set up on this server yet.'];
        }
        if ($name === '') {
            $db->prepare('DELETE FROM ap_listenbrainz_links WHERE owner_user_id = ?')->execute([$userId]);
            return ['ok' => true, 'username' => ''];
        }
        $existing = ap_listenbrainz_link_for_user($userId, $db);
        $now = gmdate('c');
        if (is_array($existing)) {
            if ((string) ($existing['lb_username'] ?? '') === $name) {
                return ['ok' => true, 'username' => $name];
            }
            $db->prepare(
                "UPDATE ap_listenbrainz_links SET lb_username = ?, playing_track = '', playing_artist = '',
                 playing_release = '', playing_cover = '', last_track = '', last_artist = '', last_release = '',
                 last_cover = '', last_listened_at = 0, fetched_at = 0, next_fetch_at = 0, failures = 0,
                 last_error = '', updated_at = ? WHERE owner_user_id = ?"
            )->execute([$name, $now, $userId]);
        } else {
            $db->prepare(
                'INSERT INTO ap_listenbrainz_links (owner_user_id, lb_username, created_at, updated_at) VALUES (?, ?, ?, ?)'
            )->execute([$userId, $name, $now, $now]);
        }
        return ['ok' => true, 'username' => $name];
    } catch (Throwable $e) {
        error_log('[listenbrainz] save failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save your ListenBrainz username.'];
    }
}

/**
 * What a profile shows for one stored row at time $now, or null for nothing.
 * Fresh playing-now wins; otherwise the last scrobbled listen ("Last played").
 *
 * @param array<string,mixed>|null $row
 * @return array{label:string,track:string,artist:string,release:string,cover:string,url:string}|null
 */
function ap_listenbrainz_display(?array $row, int $now): ?array
{
    if (!is_array($row)) {
        return null;
    }
    $name = trim((string) ($row['lb_username'] ?? ''));
    if ($name === '') {
        return null;
    }
    $fetched = (int) ($row['fetched_at'] ?? 0);
    $prefix = '';
    $label = '';
    if (trim((string) ($row['playing_track'] ?? '')) !== '' && $fetched >= $now - AP_LISTENBRAINZ_PLAYING_FRESH) {
        $prefix = 'playing_';
        $label = 'Now playing';
    } elseif (trim((string) ($row['last_track'] ?? '')) !== '') {
        $prefix = 'last_';
        $label = 'Last played';
    } else {
        return null;
    }
    $cover = trim((string) ($row[$prefix . 'cover'] ?? ''));
    return [
        'label' => $label,
        'track' => trim((string) ($row[$prefix . 'track'] ?? '')),
        'artist' => trim((string) ($row[$prefix . 'artist'] ?? '')),
        'release' => trim((string) ($row[$prefix . 'release'] ?? '')),
        'cover' => ap_listenbrainz_cover_ok($cover) ? $cover : '',
        'url' => 'https://listenbrainz.org/user/' . rawurlencode($name) . '/',
    ];
}

function ap_listenbrainz_cover_ok(string $url): bool
{
    return preg_match('~^https://coverartarchive\.org/release/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/(?:front|[0-9]{1,20})-250(?:\.jpg)?$~', $url) === 1;
}

/**
 * Profile header block. One line, no whitespace between tags, so PHP and the
 * Rust painters can print the same bytes. '' when there is nothing to show.
 *
 * @param array<string,mixed>|null $display from ap_listenbrainz_display()
 */
function ap_listenbrainz_profile_html(?array $display): string
{
    if (!is_array($display) || trim((string) ($display['track'] ?? '')) === '') {
        return '';
    }
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $label = (string) ($display['label'] ?? '') === 'Now playing' ? 'Now playing' : 'Last played';
    $url = (string) ($display['url'] ?? '');
    if (!str_starts_with($url, 'https://listenbrainz.org/user/')) {
        return '';
    }
    $track = (string) $display['track'];
    $artist = trim((string) ($display['artist'] ?? ''));
    $release = trim((string) ($display['release'] ?? ''));
    $cover = (string) ($display['cover'] ?? '');
    $by = $artist;
    if ($release !== '' && $release !== $track) {
        $by = $by === '' ? $release : ($by . ' · ' . $release);
    }
    $html = '<div class="profile-now-playing" data-state="' . ($label === 'Now playing' ? 'playing' : 'last') . '">'
        . '<a href="' . $e($url) . '" target="_blank" rel="noopener noreferrer" title="' . $e($label . ' on ListenBrainz') . '">';
    if ($cover !== '' && ap_listenbrainz_cover_ok($cover)) {
        $html .= '<img class="profile-now-playing-cover" src="' . $e($cover) . '" alt="" width="44" height="44" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.remove()">';
    } else {
        $html .= '<span class="profile-now-playing-cover" aria-hidden="true">♪</span>';
    }
    $html .= '<span class="profile-now-playing-text">'
        . '<span class="profile-now-playing-label">' . $e($label) . '</span>'
        . '<span class="profile-now-playing-track">' . $e($track) . '</span>';
    if ($by !== '') {
        $html .= '<span class="profile-now-playing-by">' . $e($by) . '</span>';
    }
    return $html . '</span></a></div>';
}

/** Header entry: lookup + display + markup. '' on any failure. */
function ap_listenbrainz_profile_html_for_actor_key(string $actorKey, ?int $now = null): string
{
    $display = ap_listenbrainz_display(ap_listenbrainz_link_for_actor_key($actorKey), $now ?? time());
    return ap_listenbrainz_profile_html($display);
}

// ---------------------------------------------------------------------------
// Worker side. Only api/ap-listenbrainz-worker.php calls below this line.
// ---------------------------------------------------------------------------

function ap_listenbrainz_clip(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_scrub($value, 'UTF-8')) ?? '');
    if (mb_strlen($value, 'UTF-8') > AP_LISTENBRAINZ_TEXT_MAX) {
        $value = rtrim(mb_substr($value, 0, AP_LISTENBRAINZ_TEXT_MAX - 1, 'UTF-8')) . '…';
    }
    return $value;
}

function ap_listenbrainz_uuid(mixed $value): string
{
    $value = is_string($value) ? strtolower(trim($value)) : '';
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1 ? $value : '';
}

/**
 * Pull track, artist, album, cover and time out of one ListenBrainz listen.
 *
 * @param mixed $listen one element of payload.listens
 * @return array{track:string,artist:string,release:string,cover:string,listened_at:int}|null
 */
function ap_listenbrainz_parse_listen(mixed $listen): ?array
{
    if (!is_array($listen) || !is_array($listen['track_metadata'] ?? null)) {
        return null;
    }
    $meta = $listen['track_metadata'];
    $track = ap_listenbrainz_clip($meta['track_name'] ?? '');
    if ($track === '') {
        return null;
    }
    $mapping = is_array($meta['mbid_mapping'] ?? null) ? $meta['mbid_mapping'] : [];
    $info = is_array($meta['additional_info'] ?? null) ? $meta['additional_info'] : [];
    $cover = '';
    $caaRelease = ap_listenbrainz_uuid($mapping['caa_release_mbid'] ?? '');
    $caaId = $mapping['caa_id'] ?? null;
    if ($caaRelease !== '' && (is_int($caaId) || (is_string($caaId) && ctype_digit($caaId))) && (string) $caaId !== '' && strlen((string) $caaId) <= 20) {
        $cover = 'https://coverartarchive.org/release/' . $caaRelease . '/' . $caaId . '-250.jpg';
    } else {
        $release = ap_listenbrainz_uuid($mapping['release_mbid'] ?? '') ?: ap_listenbrainz_uuid($info['release_mbid'] ?? '');
        if ($release !== '') {
            $cover = 'https://coverartarchive.org/release/' . $release . '/front-250';
        }
    }
    return [
        'track' => $track,
        'artist' => ap_listenbrainz_clip($meta['artist_name'] ?? ''),
        'release' => ap_listenbrainz_clip($meta['release_name'] ?? ''),
        'cover' => $cover,
        'listened_at' => max(0, (int) ($listen['listened_at'] ?? 0)),
    ];
}

/**
 * GET one ListenBrainz API path through the outbound guard.
 * Returns status, decoded JSON (or null) and the X-RateLimit-Reset-In hint.
 *
 * @return array{status:int,json:mixed,reset_in:int,error:string}
 */
function ap_listenbrainz_http_get(string $path): array
{
    if (!function_exists('ap_net_curl_exec')) {
        require_once __DIR__ . '/ap-net-guard.php';
    }
    $url = AP_LISTENBRAINZ_API . $path;
    $ch = curl_init();
    if ($ch === false) {
        return ['status' => 0, 'json' => null, 'reset_in' => 0, 'error' => 'curl unavailable'];
    }
    $resetIn = 0;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_MAXFILESIZE => 262144,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'VAAK/1.0 ( https://mkultra.monster/vaak/ )',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$resetIn): int {
            if (stripos($line, 'x-ratelimit-reset-in:') === 0) {
                $resetIn = max(0, min(3600, (int) trim(substr($line, 21))));
            }
            return strlen($line);
        },
    ]);
    $body = ap_net_curl_exec($ch, $url, 0);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = $body === false ? (ap_net_last_refusal() ?: curl_error($ch) ?: 'request failed') : '';
    curl_close($ch);
    $json = is_string($body) && $body !== '' ? json_decode($body, true) : null;
    return ['status' => $status, 'json' => $json, 'reset_in' => $resetIn, 'error' => $error];
}

/**
 * When to look again, in seconds. Someone mid-song gets a minute; recent
 * listeners two; idle accounts ten minutes.
 */
function ap_listenbrainz_next_delay(bool $playing, int $lastListenedAt, int $now): int
{
    if ($playing) {
        return 60;
    }
    if ($lastListenedAt > 0 && $lastListenedAt >= $now - 3600) {
        return 120;
    }
    return 600;
}

function ap_listenbrainz_failure_delay(int $failures): int
{
    return min(3600, 60 * (2 ** max(0, min(6, $failures - 1))));
}

/**
 * One poll for one stored row. $get(path) returns ap_listenbrainz_http_get()'s
 * shape; $pause() runs between the two calls so the worker stays at or under
 * ListenBrainz's one call per second. No database work here.
 *
 * While a song is playing only playing-now is fetched; the last listen is
 * refreshed once nothing is playing.
 *
 * @param array<string,mixed> $row
 * @param callable(string):array{status:int,json:mixed,reset_in:int,error:string} $get
 * @param callable():void $pause
 * @return array{rate_limited:int,update:array<string,int|string>}
 */
function ap_listenbrainz_poll(array $row, int $now, callable $get, callable $pause): array
{
    $name = trim((string) ($row['lb_username'] ?? ''));
    $base = '/1/user/' . rawurlencode($name);
    $failures = max(0, (int) ($row['failures'] ?? 0));
    $fail = static function (string $error, int $delay) use ($now, $failures): array {
        return ['rate_limited' => 0, 'update' => [
            'playing_track' => '', 'playing_artist' => '', 'playing_release' => '', 'playing_cover' => '',
            'failures' => $failures + 1, 'last_error' => substr($error, 0, 200), 'next_fetch_at' => $now + $delay,
        ]];
    };
    if ($name === '') {
        return $fail('no username', 3600);
    }
    $playing = null;
    $res = $get($base . '/playing-now');
    if ($res['status'] === 429) {
        return ['rate_limited' => max(1, (int) $res['reset_in']), 'update' => []];
    }
    if ($res['status'] === 404) {
        return $fail('ListenBrainz user not found', 3600);
    }
    if ($res['status'] !== 200 || !is_array($res['json']) || !is_array($res['json']['payload'] ?? null)) {
        return $fail($res['error'] !== '' ? $res['error'] : ('playing-now HTTP ' . $res['status']), ap_listenbrainz_failure_delay($failures + 1));
    }
    $payload = $res['json']['payload'];
    $listens = is_array($payload['listens'] ?? null) ? $payload['listens'] : [];
    if ($listens !== []) {
        $playing = ap_listenbrainz_parse_listen($listens[0]);
    }
    $update = [
        'playing_track' => $playing['track'] ?? '',
        'playing_artist' => $playing['artist'] ?? '',
        'playing_release' => $playing['release'] ?? '',
        'playing_cover' => $playing['cover'] ?? '',
        'fetched_at' => $now,
        'failures' => 0,
        'last_error' => '',
    ];
    $lastAt = max(0, (int) ($row['last_listened_at'] ?? 0));
    if ($playing === null) {
        $pause();
        $res = $get($base . '/listens?count=1');
        if ($res['status'] === 429) {
            $update['next_fetch_at'] = $now + max(60, (int) $res['reset_in']);
            return ['rate_limited' => max(1, (int) $res['reset_in']), 'update' => $update];
        }
        if ($res['status'] !== 200 || !is_array($res['json']) || !is_array($res['json']['payload'] ?? null)) {
            return $fail($res['error'] !== '' ? $res['error'] : ('listens HTTP ' . $res['status']), ap_listenbrainz_failure_delay($failures + 1));
        }
        $recent = is_array($res['json']['payload']['listens'] ?? null) ? $res['json']['payload']['listens'] : [];
        $last = $recent !== [] ? ap_listenbrainz_parse_listen($recent[0]) : null;
        if ($last !== null) {
            $update += [
                'last_track' => $last['track'],
                'last_artist' => $last['artist'],
                'last_release' => $last['release'],
                'last_cover' => $last['cover'],
                'last_listened_at' => $last['listened_at'],
            ];
            $lastAt = $last['listened_at'];
        }
    }
    $update['next_fetch_at'] = $now + ap_listenbrainz_next_delay($playing !== null, $lastAt, $now);
    return ['rate_limited' => 0, 'update' => $update];
}

/** @param array<string,int|string> $update */
function ap_listenbrainz_store_poll(PDO $db, int $userId, string $username, array $update): void
{
    if ($update === []) {
        return;
    }
    $allowed = ['playing_track', 'playing_artist', 'playing_release', 'playing_cover', 'last_track', 'last_artist',
        'last_release', 'last_cover', 'last_listened_at', 'fetched_at', 'next_fetch_at', 'failures', 'last_error'];
    $sets = [];
    $args = [];
    foreach ($allowed as $column) {
        if (array_key_exists($column, $update)) {
            $sets[] = $column . ' = ?';
            $args[] = $update[$column];
        }
    }
    $sets[] = 'updated_at = ?';
    $args[] = gmdate('c');
    $args[] = $userId;
    $args[] = $username;
    // The username guard drops a poll that raced a rename in Settings.
    $db->prepare('UPDATE ap_listenbrainz_links SET ' . implode(', ', $sets) . ' WHERE owner_user_id = ? AND lb_username = ?')
        ->execute($args);
}
