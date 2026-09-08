<?php
/**
 * Mastodon-compatible CSV import/export + account alias / Move helpers.
 * Used by admin You → Import / Export.
 */
declare(strict_types=1);
// Refuse direct HTTP hits (include/require only)
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Library only';
    exit;
}


const AP_IE_UPLOAD_DIR = '/var/lib/mkultra/ap/imports';
const AP_IE_MOVE_COOLDOWN_DAYS = 30;

/** Per-owner follow-import queue (avoids sharing cmdr's state with invitees). */
function ap_ie_follow_state_path(): string
{
    $ownerId = function_exists('ap_db_default_owner_user_id') ? (int) ap_db_default_owner_user_id() : 0;
    if ($ownerId < 1) {
        $ownerId = 0;
    }
    return '/var/lib/mkultra/ap/follow-import-state-' . $ownerId . '.json';
}

/* ===================== CSV primitives ===================== */

function ap_ie_csv_escape(string $value): string
{
    if (str_contains($value, '"') || str_contains($value, ',') || str_contains($value, "\n") || str_contains($value, "\r")) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

/**
 * @param list<string> $header
 * @param list<list<string>> $rows
 */
function ap_ie_stream_csv(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $filename) . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        http_response_code(500);
        echo "Could not open output\n";
        exit;
    }
    // UTF-8 BOM helps Excel
    fwrite($out, "\xEF\xBB\xBF");
    if ($header !== []) {
        fputcsv($out, $header);
    }
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/**
 * Read uploaded CSV / text lines. Returns raw rows (arrays of cells).
 *
 * @return list<list<string>>
 */
function ap_ie_read_upload_rows(array $file): array
{
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('No uploaded file');
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . (int) ($file['error'] ?? 0) . ')');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 8 * 1024 * 1024) {
        throw new RuntimeException('File empty or too large (max 8MB)');
    }
    $fh = fopen($tmp, 'r');
    if ($fh === false) {
        throw new RuntimeException('Cannot read upload');
    }
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $cells = [];
        foreach ($row as $c) {
            $cells[] = trim((string) $c);
        }
        // Skip fully empty rows
        $nonEmpty = false;
        foreach ($cells as $c) {
            if ($c !== '') {
                $nonEmpty = true;
                break;
            }
        }
        if ($nonEmpty) {
            $rows[] = $cells;
        }
    }
    fclose($fh);
    return $rows;
}

function ap_ie_looks_like_header(array $row): bool
{
    $joined = strtolower(implode(' ', $row));
    return str_contains($joined, 'account')
        || str_contains($joined, 'address')
        || str_contains($joined, 'list name')
        || $joined === '#uri'
        || $joined === '#domain'
        || str_starts_with($joined, '#');
}

function ap_ie_normalize_acct(string $raw): ?string
{
    $acct = ltrim(trim($raw), '@');
    if ($acct === '' || str_starts_with($acct, '#')) {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9_.\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $acct)) {
        return null;
    }
    return $acct;
}

function ap_ie_resolve_acct_to_actor(string $acct): ?string
{
    $acct = ap_ie_normalize_acct($acct) ?? ltrim(trim($acct), '@');
    if ($acct === '') {
        return null;
    }
    if (str_starts_with($acct, 'https://')) {
        return rtrim($acct, '/');
    }
    if (!function_exists('ap_resolve_actor_ref')) {
        return null;
    }
    $resolved = ap_resolve_actor_ref('@' . ltrim($acct, '@'));
    if (is_string($resolved) && str_starts_with($resolved, 'https://')) {
        return rtrim($resolved, '/');
    }
    if (is_array($resolved)) {
        $id = rtrim((string) ($resolved['id'] ?? $resolved['actor_id'] ?? ''), '/');
        return $id !== '' ? $id : null;
    }
    return null;
}

function ap_ie_actor_to_acct(string $actorId, bool $allowFetch = false): string
{
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '') {
        return '';
    }
    if (function_exists('ap_remote_actor_label')) {
        $label = ap_remote_actor_label($actorId, $allowFetch);
        $acct = (string) ($label['acct'] ?? '');
        if ($acct !== '') {
            return ltrim($acct, '@');
        }
    }
    if (function_exists('ap_acct_from_actor_href')) {
        $fallback = ap_acct_from_actor_href($actorId);
        if (is_string($fallback) && $fallback !== '') {
            return ltrim($fallback, '@');
        }
    }
    $host = parse_url($actorId, PHP_URL_HOST);
    $path = (string) (parse_url($actorId, PHP_URL_PATH) ?? '');
    $user = basename(rtrim($path, '/'));
    if (is_string($host) && $host !== '' && $user !== '') {
        return $user . '@' . strtolower($host);
    }
    return $actorId;
}

/* ===================== Exports ===================== */

/** @return list<list<string>> */
function ap_ie_export_following_rows(): array
{
    $rows = [];
    $owner = function_exists('ap_local_actor_id') ? ap_local_actor_id() : null;
    foreach (ap_following_list($owner) as $f) {
        $aid = (string) ($f['actor_id'] ?? '');
        if ($aid === '') {
            continue;
        }
        $acct = ap_ie_actor_to_acct($aid, false);
        if ($acct === '' || !str_contains($acct, '@')) {
            continue;
        }
        $rows[] = [$acct, 'true', 'false', ''];
    }
    return $rows;
}

/** @return list<list<string>> */
function ap_ie_export_mutes_rows(): array
{
    $rows = [];
    foreach (ap_mutes_list(ap_db_default_owner_user_id()) as $m) {
        $aid = (string) ($m['actor_id'] ?? '');
        if ($aid === '') {
            continue;
        }
        $acct = ap_ie_actor_to_acct($aid, false);
        if ($acct === '' || !str_contains($acct, '@')) {
            continue;
        }
        $hideNotif = !empty($m['notifications']) ? 'true' : 'false';
        $rows[] = [$acct, $hideNotif];
    }
    return $rows;
}

/** @return list<list<string>> */
function ap_ie_export_blocked_accounts_rows(): array
{
    $rows = [];
    foreach (ap_block_list() as $b) {
        if (($b['scope'] ?? '') !== 'actor') {
            continue;
        }
        $val = (string) ($b['value'] ?? '');
        if ($val === '') {
            continue;
        }
        $acct = str_starts_with($val, 'https://') ? ap_ie_actor_to_acct($val, false) : $val;
        if ($acct === '') {
            continue;
        }
        $rows[] = [ltrim($acct, '@')];
    }
    return $rows;
}

/** @return list<list<string>> */
function ap_ie_export_blocked_domains_rows(): array
{
    $rows = [];
    foreach (ap_block_list() as $b) {
        if (($b['scope'] ?? '') !== 'domain') {
            continue;
        }
        $dom = (string) ($b['value'] ?? '');
        if ($dom === '') {
            continue;
        }
        $rows[] = [$dom];
    }
    return $rows;
}

/** @return list<list<string>> */
function ap_ie_export_bookmarks_rows(): array
{
    $rows = [];
    $seen = [];
    // Pull a large page — bookmarks table is usually modest
    foreach (ap_masto_bookmark_rows(5000) as $b) {
        $uri = trim((string) ($b['object_id'] ?? ''));
        if ($uri === '' || !str_starts_with($uri, 'https://')) {
            // try resolve from status_id
            continue;
        }
        $key = rtrim($uri, '/');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $rows[] = [$uri];
    }
    return $rows;
}

/** @return list<list<string>> */
function ap_ie_export_lists_rows(): array
{
    $rows = [];
    if (!function_exists('ap_lists_all')) {
        return $rows;
    }
    foreach (ap_lists_all() as $list) {
        $title = (string) ($list['title'] ?? '');
        $lid = (int) ($list['id'] ?? 0);
        if ($title === '' || $lid <= 0) {
            continue;
        }
        foreach (ap_list_accounts($lid) as $mem) {
            $aid = (string) ($mem['actor_id'] ?? '');
            if ($aid === '') {
                continue;
            }
            $acct = ap_ie_actor_to_acct($aid, false);
            if ($acct === '' || !str_contains($acct, '@')) {
                continue;
            }
            $rows[] = [$title, $acct];
        }
    }
    return $rows;
}

/** @return list<list<string>> */
function ap_ie_export_followed_tags_rows(): array
{
    $rows = [];
    if (!function_exists('ap_masto_followed_tags')) {
        return $rows;
    }
    $owner = function_exists('ap_db_default_owner_user_id') ? ap_db_default_owner_user_id() : null;
    foreach (ap_masto_followed_tags(0, $owner) as $tag) {
        $name = trim((string) ($tag['name'] ?? ''));
        if ($name !== '') {
            $rows[] = ['#' . ltrim($name, '#')];
        }
    }
    return $rows;
}

function ap_ie_handle_export(string $kind): void
{
    switch ($kind) {
        case 'following':
            ap_ie_stream_csv(
                'following_accounts.csv',
                ['Account address', 'Show boosts', 'Notify on new posts', 'Languages'],
                ap_ie_export_following_rows()
            );
            break;
        case 'mutes':
            ap_ie_stream_csv(
                'muted_accounts.csv',
                ['Account address', 'Hide notifications'],
                ap_ie_export_mutes_rows()
            );
            break;
        case 'blocks':
            ap_ie_stream_csv('blocked_accounts.csv', [], ap_ie_export_blocked_accounts_rows());
            break;
        case 'blocked_domains':
            ap_ie_stream_csv('blocked_domains.csv', ['#domain'], ap_ie_export_blocked_domains_rows());
            break;
        case 'bookmarks':
            ap_ie_stream_csv('bookmarks.csv', ['#uri'], ap_ie_export_bookmarks_rows());
            break;
        case 'lists':
            ap_ie_stream_csv('lists.csv', ['List name', 'Account address'], ap_ie_export_lists_rows());
            break;
        case 'followed_tags':
            ap_ie_stream_csv('followed_tags.csv', ['#hashtag'], ap_ie_export_followed_tags_rows());
            break;
        default:
            http_response_code(400);
            header('Content-Type: text/plain');
            echo "Unknown export type\n";
            exit;
    }
}

/* ===================== Imports ===================== */

/**
 * @param list<list<string>> $rows
 * @return array{ok:bool,imported:int,skipped:int,failed:int,errors:list<string>}
 */
function ap_ie_import_mutes(array $rows): array
{
    $imported = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];
    $start = 0;
    if ($rows && ap_ie_looks_like_header($rows[0])) {
        $start = 1;
    }
    for ($i = $start; $i < count($rows); $i++) {
        $row = $rows[$i];
        $acct = ap_ie_normalize_acct((string) ($row[0] ?? ''));
        if ($acct === null) {
            $skipped++;
            continue;
        }
        $hideNotif = true;
        if (isset($row[1])) {
            $v = strtolower(trim((string) $row[1]));
            $hideNotif = !in_array($v, ['0', 'false', 'no', ''], true);
        }
        $actor = ap_ie_resolve_acct_to_actor($acct);
        if ($actor === null) {
            $failed++;
            $errors[] = "Could not resolve @$acct";
            continue;
        }
        $res = ap_mute_upsert($actor, $hideNotif, ap_db_default_owner_user_id());
        if (!empty($res['ok'])) {
            $imported++;
        } else {
            $failed++;
            $errors[] = '@' . $acct . ': ' . ($res['error'] ?? 'mute failed');
        }
    }
    return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
}

/**
 * @param list<list<string>> $rows
 * @return array{ok:bool,imported:int,skipped:int,failed:int,errors:list<string>}
 */
function ap_ie_import_blocks(array $rows): array
{
    $imported = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];
    $start = 0;
    if ($rows && ap_ie_looks_like_header($rows[0])) {
        $start = 1;
    }
    for ($i = $start; $i < count($rows); $i++) {
        $raw = trim((string) ($rows[$i][0] ?? ''));
        if ($raw === '' || str_starts_with($raw, '#')) {
            $skipped++;
            continue;
        }
        $acct = ap_ie_normalize_acct($raw);
        if ($acct === null) {
            $failed++;
            $errors[] = "Invalid account: $raw";
            continue;
        }
        $actor = ap_ie_resolve_acct_to_actor($acct);
        if ($actor === null) {
            $failed++;
            $errors[] = "Could not resolve @$acct";
            continue;
        }
        $res = ap_block_upsert('actor', $actor, 'block', 'csv import');
        if (!empty($res['ok'])) {
            $imported++;
        } else {
            $failed++;
            $errors[] = '@' . $acct . ': ' . ($res['error'] ?? 'block failed');
        }
    }
    return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
}

/**
 * @param list<list<string>> $rows
 * @return array{ok:bool,imported:int,skipped:int,failed:int,errors:list<string>}
 */
function ap_ie_import_blocked_domains(array $rows): array
{
    $imported = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];
    $start = 0;
    if ($rows && ap_ie_looks_like_header($rows[0])) {
        $start = 1;
    }
    for ($i = $start; $i < count($rows); $i++) {
        $raw = strtolower(trim((string) ($rows[$i][0] ?? '')));
        $raw = ltrim($raw, '#');
        if ($raw === '') {
            $skipped++;
            continue;
        }
        $dom = function_exists('ap_block_normalize_domain') ? ap_block_normalize_domain($raw) : $raw;
        if ($dom === null || $dom === '') {
            $failed++;
            $errors[] = "Invalid domain: $raw";
            continue;
        }
        $res = ap_block_upsert('domain', $dom, 'block', 'csv import');
        if (!empty($res['ok'])) {
            $imported++;
        } else {
            $failed++;
            $errors[] = $dom . ': ' . ($res['error'] ?? 'block failed');
        }
    }
    return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
}

/**
 * @param list<list<string>> $rows
 * @return array{ok:bool,imported:int,skipped:int,failed:int,errors:list<string>}
 */
function ap_ie_import_bookmarks(array $rows): array
{
    $imported = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];
    $start = 0;
    if ($rows && ap_ie_looks_like_header($rows[0])) {
        $start = 1;
    }
    for ($i = $start; $i < count($rows); $i++) {
        $uri = trim((string) ($rows[$i][0] ?? ''));
        if ($uri === '' || str_starts_with($uri, '#')) {
            $skipped++;
            continue;
        }
        if (!str_starts_with($uri, 'https://')) {
            $failed++;
            $errors[] = "Not an https URI: $uri";
            continue;
        }
        $uri = rtrim($uri, '/');
        $statusId = null;
        $objectId = $uri;
        if (function_exists('ap_masto_lookup_status_by_object_url')) {
            $st = ap_masto_lookup_status_by_object_url($uri, 1, true);
            if (is_array($st) && !empty($st['id'])) {
                $statusId = (string) $st['id'];
                $objectId = (string) ($st['uri'] ?? $st['url'] ?? $uri);
            }
        }
        if ($statusId === null && function_exists('ap_masto_ensure_remote_note_event')) {
            $ev = ap_masto_ensure_remote_note_event($uri);
            if (is_array($ev) && !empty($ev['id']) && function_exists('ap_masto_event_status_id')) {
                $statusId = ap_masto_event_status_id((int) $ev['id'], (string) ($ev['created_at'] ?? ''));
            }
        }
        if ($statusId === null || $statusId === '') {
            $failed++;
            $errors[] = "Could not resolve bookmark: $uri";
            continue;
        }
        try {
            ap_masto_bookmark_add($statusId, $objectId);
            $imported++;
        } catch (Throwable $e) {
            $failed++;
            $errors[] = $uri . ': ' . $e->getMessage();
        }
    }
    return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
}

/**
 * @param list<list<string>> $rows
 * @return array{ok:bool,imported:int,skipped:int,failed:int,errors:list<string>,rate_limited?:bool}
 */
function ap_ie_import_lists(array $rows): array
{
    if (!function_exists('ap_list_create')) {
        return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => ['Lists helpers unavailable']];
    }
    $imported = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];
    $rateLimited = false;
    $start = 0;
    if ($rows && ap_ie_looks_like_header($rows[0])) {
        $start = 1;
    }
    /** @var array<string,int> $listIds by lowercase title */
    $listIds = [];
    foreach (ap_lists_all() as $L) {
        $t = strtolower(trim((string) ($L['title'] ?? '')));
        if ($t !== '') {
            $listIds[$t] = (int) $L['id'];
        }
    }
    for ($i = $start; $i < count($rows); $i++) {
        $title = trim((string) ($rows[$i][0] ?? ''));
        $acct = ap_ie_normalize_acct((string) ($rows[$i][1] ?? ''));
        if ($title === '' || $acct === null) {
            $skipped++;
            continue;
        }
        $key = strtolower($title);
        if (!isset($listIds[$key])) {
            $cres = ap_list_create($title, 'list', false);
            if (empty($cres['ok'])) {
                $failed++;
                $errors[] = "List “$title”: " . ($cres['error'] ?? 'create failed');
                continue;
            }
            $listIds[$key] = (int) ($cres['id'] ?? 0);
        }
        $lid = $listIds[$key];
        $actor = ap_ie_resolve_acct_to_actor($acct);
        if ($actor === null) {
            $failed++;
            $errors[] = "Could not resolve @$acct for list $title";
            continue;
        }
        $ares = ap_list_add_account($lid, $actor, true);
        if (!empty($ares['ok'])) {
            $imported++;
        } else {
            $failed++;
            $errors[] = "$title / @$acct: " . ($ares['error'] ?? 'add failed');
            if (!empty($ares['rate_limited'])) {
                $rateLimited = true;
                break;
            }
        }
    }
    return [
        'ok' => true,
        'imported' => $imported,
        'skipped' => $skipped,
        'failed' => $failed,
        'errors' => $errors,
        'rate_limited' => $rateLimited,
    ];
}

/**
 * Import followed hashtags without changing existing follows.
 * @param list<list<string>> $rows
 * @return array{ok:bool,imported:int,skipped:int,failed:int,errors:list<string>}
 */
function ap_ie_import_followed_tags(array $rows): array
{
    $imported = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];
    if (!function_exists('ap_masto_tag_follow')) {
        return ['ok' => false, 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => ['Followed-tag helper unavailable']];
    }
    $start = ($rows && ap_ie_looks_like_header($rows[0])) ? 1 : 0;
    for ($i = $start; $i < count($rows); $i++) {
        $raw = trim((string) ($rows[$i][0] ?? ''));
        $name = ltrim($raw, '#');
        if ($name === '') {
            $skipped++;
            continue;
        }
        if (function_exists('ap_masto_normalize_tag_name')) {
            $name = ap_masto_normalize_tag_name($name);
        } else {
            $name = strtolower($name);
        }
        if ($name === '' || !preg_match('/^[\p{L}\p{N}_-]{1,100}$/u', $name)) {
            $failed++;
            $errors[] = "Invalid hashtag: $raw";
            continue;
        }
        try {
            $res = ap_masto_tag_follow($name, function_exists('ap_db_default_owner_user_id') ? ap_db_default_owner_user_id() : null);
            if (!empty($res['ok'])) {
                $imported++;
            } else {
                $failed++;
                $errors[] = "#$name: " . ($res['error'] ?? 'follow failed');
            }
        } catch (Throwable $e) {
            $failed++;
            $errors[] = "#$name: " . $e->getMessage();
        }
    }
    return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
}

/* ===================== Follow import (rate-limited job) ===================== */

function ap_ie_follow_state_empty(): array
{
    return [
        'csv' => '',
        'handles' => [],
        'index' => 0,
        'ok' => 0,
        'already' => 0,
        'failed' => 0,
        'skipped' => 0,
        'errors' => [],
        'started_at' => null,
        'updated_at' => null,
        'finished_at' => null,
        'source' => 'admin',
    ];
}

function ap_ie_follow_state_load(): array
{
    $path = ap_ie_follow_state_path();
    if (!is_readable($path)) {
        return ap_ie_follow_state_empty();
    }
    $j = json_decode((string) file_get_contents($path), true);
    return is_array($j) ? $j : ap_ie_follow_state_empty();
}

function ap_ie_follow_state_save(array $state): void
{
    $state['updated_at'] = gmdate('c');
    $path = ap_ie_follow_state_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    file_put_contents(
        $path,
        json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

function ap_ie_follow_should_skip(string $acct): ?string
{
    $lower = strtolower($acct);
    $selfKey = '';
    if (function_exists('ap_request_actor_get')) {
        $req = ap_request_actor_get();
        if (is_array($req)) {
            $selfKey = strtolower((string) ($req['key'] ?? ''));
        }
    }
    if ($selfKey === '' && !empty($GLOBALS['vaak_actor_key'])) {
        $selfKey = strtolower((string) $GLOBALS['vaak_actor_key']);
    }
    if ($selfKey !== '') {
        if ($lower === $selfKey . '@mkultra.monster') {
            return 'self';
        }
        $hyphen = str_replace('_', '-', $selfKey);
        if ($hyphen !== $selfKey && $lower === $hyphen . '@mkultra.monster') {
            return 'self';
        }
    } elseif ($lower === 'cmdr_nova@mkultra.monster' || $lower === 'cmdr-nova@mkultra.monster') {
        return 'self';
    }
    if ($lower === 'val3r1e@mkultra.monster') {
        return 'local bridgy handle';
    }
    return null;
}

/**
 * Queue following CSV handles into the shared follow-import state (compatible with CLI continue).
 *
 * @param list<list<string>> $rows
 * @return array{ok:bool,queued:int,error?:string}
 */
function ap_ie_follow_queue_from_rows(array $rows): array
{
    $start = 0;
    $addrIdx = 0;
    if ($rows && ap_ie_looks_like_header($rows[0])) {
        foreach ($rows[0] as $i => $col) {
            if (stripos($col, 'account') !== false || stripos($col, 'address') !== false) {
                $addrIdx = (int) $i;
                break;
            }
        }
        $start = 1;
    }
    $handles = [];
    $seen = [];
    for ($i = $start; $i < count($rows); $i++) {
        $acct = ap_ie_normalize_acct((string) ($rows[$i][$addrIdx] ?? ''));
        if ($acct === null) {
            continue;
        }
        $key = strtolower($acct);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $handles[] = $acct;
    }
    if ($handles === []) {
        return ['ok' => false, 'queued' => 0, 'error' => 'No valid account addresses found in CSV'];
    }
    if (!is_dir(AP_IE_UPLOAD_DIR)) {
        @mkdir(AP_IE_UPLOAD_DIR, 0750, true);
    }
    $saved = AP_IE_UPLOAD_DIR . '/following_accounts_' . gmdate('Ymd_His') . '.csv';
    $fp = $saved . '|' . count($handles) . '|' . md5(implode(',', array_slice($handles, 0, 5)));
    $state = [
        'csv' => $saved,
        'handles' => $handles,
        'index' => 0,
        'ok' => 0,
        'already' => 0,
        'failed' => 0,
        'skipped' => 0,
        'errors' => [],
        'fingerprint' => $fp,
        'started_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'finished_at' => null,
        'source' => 'admin',
    ];
    // Persist a copy of handles for audit
    @file_put_contents($saved, "Account address\n" . implode("\n", $handles) . "\n");
    ap_ie_follow_state_save($state);
    return ['ok' => true, 'queued' => count($handles)];
}

/**
 * Process up to $maxNew successful new follows (no long sleeps — for admin HTTP).
 *
 * @return array{ok:bool,processed:int,ok_new:int,already:int,failed:int,skipped:int,remaining:int,rate_limited:bool,finished:bool,errors:list<string>}
 */
function ap_ie_follow_process_batch(int $maxNew = 5): array
{
    $state = ap_ie_follow_state_load();
    $handles = $state['handles'] ?? [];
    $total = count($handles);
    $idx = (int) ($state['index'] ?? 0);
    if ($total === 0 || $idx >= $total) {
        return [
            'ok' => true,
            'processed' => 0,
            'ok_new' => 0,
            'already' => 0,
            'failed' => 0,
            'skipped' => 0,
            'remaining' => 0,
            'rate_limited' => false,
            'finished' => true,
            'errors' => [],
        ];
    }
    if (!function_exists('ap_follow_remote_actor')) {
        return [
            'ok' => false,
            'processed' => 0,
            'ok_new' => 0,
            'already' => 0,
            'failed' => 0,
            'skipped' => 0,
            'remaining' => max(0, $total - $idx),
            'rate_limited' => false,
            'finished' => false,
            'errors' => ['Follow helper unavailable'],
        ];
    }
    $maxPerHour = defined('LOCAL_FOLLOW_BACK_MAX_PER_HOUR') ? (int) LOCAL_FOLLOW_BACK_MAX_PER_HOUR : 30;
    $processed = 0;
    $okNew = 0;
    $already = 0;
    $failed = 0;
    $skipped = 0;
    $rateLimited = false;
    $batchErrors = [];

    while ($idx < $total && $okNew < $maxNew) {
        $acct = (string) $handles[$idx];
        $skip = ap_ie_follow_should_skip($acct);
        if ($skip !== null) {
            $skipped++;
            $state['skipped'] = (int) ($state['skipped'] ?? 0) + 1;
            $idx++;
            $state['index'] = $idx;
            $processed++;
            continue;
        }
        $recent = function_exists('ap_outbound_follow_count_recent') ? ap_outbound_follow_count_recent(3600) : 0;
        if ($recent >= $maxPerHour) {
            $rateLimited = true;
            break;
        }
        $res = ap_follow_remote_actor('@' . $acct, true);
        $processed++;
        if (!empty($res['ok'])) {
            if (!empty($res['already'])) {
                $already++;
                $state['already'] = (int) ($state['already'] ?? 0) + 1;
            } else {
                $okNew++;
                $state['ok'] = (int) ($state['ok'] ?? 0) + 1;
            }
        } else {
            $err = (string) ($res['error'] ?? 'unknown');
            if (str_contains(strtolower($err), 'rate limit')) {
                $rateLimited = true;
                break; // retry same index later
            }
            $failed++;
            $state['failed'] = (int) ($state['failed'] ?? 0) + 1;
            $state['errors'][] = ['acct' => $acct, 'error' => $err, 'at' => gmdate('c')];
            if (count($state['errors']) > 200) {
                $state['errors'] = array_slice($state['errors'], -200);
            }
            $batchErrors[] = "@$acct: $err";
        }
        $idx++;
        $state['index'] = $idx;
        usleep(200000);
    }

    $finished = $idx >= $total;
    if ($finished) {
        $state['finished_at'] = gmdate('c');
    }
    $state['index'] = $idx;
    ap_ie_follow_state_save($state);

    return [
        'ok' => true,
        'processed' => $processed,
        'ok_new' => $okNew,
        'already' => $already,
        'failed' => $failed,
        'skipped' => $skipped,
        'remaining' => max(0, $total - $idx),
        'rate_limited' => $rateLimited,
        'finished' => $finished,
        'errors' => $batchErrors,
    ];
}

/**
 * @return array{total:int,index:int,remaining:int,ok:int,already:int,failed:int,skipped:int,finished_at:?string,updated_at:?string,source?:string,recent_errors:list<array>}
 */
function ap_ie_follow_status(): array
{
    $st = ap_ie_follow_state_load();
    $total = count($st['handles'] ?? []);
    $idx = (int) ($st['index'] ?? 0);
    return [
        'total' => $total,
        'index' => $idx,
        'remaining' => max(0, $total - $idx),
        'ok' => (int) ($st['ok'] ?? 0),
        'already' => (int) ($st['already'] ?? 0),
        'failed' => (int) ($st['failed'] ?? 0),
        'skipped' => (int) ($st['skipped'] ?? 0),
        'finished_at' => $st['finished_at'] ?? null,
        'updated_at' => $st['updated_at'] ?? null,
        'source' => (string) ($st['source'] ?? ''),
        'recent_errors' => array_slice($st['errors'] ?? [], -8),
    ];
}

/* ===================== Aliases + Move ===================== */

/**
 * @return list<array<string,mixed>>
 */
function ap_alias_list(string $actorKey = 'cmdr_nova', ?string $direction = null): array
{
    try {
        if ($direction !== null) {
            $st = ap_db()->prepare(
                'SELECT * FROM account_aliases WHERE actor_key = ? AND direction = ? ORDER BY created_at DESC'
            );
            $st->execute([$actorKey, $direction]);
        } else {
            $st = ap_db()->prepare(
                'SELECT * FROM account_aliases WHERE actor_key = ? ORDER BY direction ASC, created_at DESC'
            );
            $st->execute([$actorKey]);
        }
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array{ok:bool,error?:string,id?:int}
 */
function ap_alias_add(string $ref, string $direction = 'aka', string $actorKey = 'cmdr_nova'): array
{
    $direction = strtolower(trim($direction));
    if (!in_array($direction, ['aka', 'moved_from', 'moved_to'], true)) {
        return ['ok' => false, 'error' => 'Invalid direction'];
    }
    $ref = trim($ref);
    $actorId = null;
    $acct = null;
    if (str_starts_with($ref, 'https://')) {
        $actorId = rtrim($ref, '/');
        // Mastodon web profile https://host/@user → resolve to Person id (/users/…)
        if (preg_match('#^https://[^/]+/@[^/]+$#', $actorId) && function_exists('ap_resolve_actor_ref')) {
            $resolved = ap_resolve_actor_ref($actorId);
            if (is_string($resolved) && str_starts_with($resolved, 'https://')) {
                $actorId = rtrim($resolved, '/');
            }
        } elseif (preg_match('#^https://[^/]+/@[^/]+$#', $actorId) && function_exists('ap_fetch_as2_object')) {
            $doc = ap_fetch_as2_object($actorId);
            if (is_array($doc) && !empty($doc['id']) && is_string($doc['id'])) {
                $actorId = rtrim($doc['id'], '/');
            }
        }
        $acct = ap_ie_actor_to_acct($actorId, true) ?: null;
    } else {
        $acct = ap_ie_normalize_acct($ref);
        if ($acct === null) {
            return ['ok' => false, 'error' => 'Need @user@host or https actor URL'];
        }
        $actorId = ap_ie_resolve_acct_to_actor($acct);
    }
    if ($actorId === null || $actorId === '') {
        return ['ok' => false, 'error' => 'Could not resolve actor'];
    }
    $self = 'https://mkultra.monster/users/' . rawurlencode($actorKey);
    if (rtrim($actorId, '/') === rtrim($self, '/')) {
        return ['ok' => false, 'error' => 'Cannot alias yourself'];
    }
    $now = ap_db_now();
    try {
        ap_db()->prepare(
            'INSERT INTO account_aliases (actor_key, alias_actor_id, alias_acct, direction, verified_at, created_at)
             VALUES (?, ?, ?, ?, NULL, ?)
             ON CONFLICT(actor_key, alias_actor_id, direction) DO UPDATE SET
               alias_acct = excluded.alias_acct'
        )->execute([$actorKey, $actorId, $acct, $direction, $now]);
        return ['ok' => true, 'id' => ap_db_last_insert_id('account_aliases')];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save alias: ' . $e->getMessage()];
    }
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_alias_remove(int $id, string $actorKey = 'cmdr_nova'): array
{
    try {
        $st = ap_db()->prepare('DELETE FROM account_aliases WHERE id = ? AND actor_key = ?');
        $st->execute([$id, $actorKey]);
        if ($st->rowCount() < 1) {
            return ['ok' => false, 'error' => 'Alias not found'];
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not remove alias'];
    }
}

/**
 * Actor IRI forms that count as the same account for alsoKnownAs checks.
 * Mastodon dual IRIs: /users/name ↔ /@name (and trailing slash).
 *
 * @return list<string>
 */
function ap_alias_actor_iri_equivalents(string $actorUrl): array
{
    $actorUrl = rtrim(trim($actorUrl), '/');
    if ($actorUrl === '' || !str_starts_with($actorUrl, 'https://')) {
        return $actorUrl !== '' ? [$actorUrl] : [];
    }
    $out = [$actorUrl, $actorUrl . '/'];
    $host = parse_url($actorUrl, PHP_URL_HOST);
    $path = (string) (parse_url($actorUrl, PHP_URL_PATH) ?? '');
    if (!is_string($host) || $host === '') {
        return array_values(array_unique($out));
    }
    $host = strtolower($host);
    if (preg_match('#^/users/([^/]+)/?$#', $path, $m)) {
        $user = rawurldecode($m[1]);
        $out[] = 'https://' . $host . '/@' . rawurlencode($user);
        $out[] = 'https://' . $host . '/@' . rawurlencode($user) . '/';
        // Unencoded @ path (Mastodon often stores this form)
        $out[] = 'https://' . $host . '/@' . $user;
        $out[] = 'https://' . $host . '/@' . $user . '/';
    } elseif (preg_match('#^/@([^/]+)/?$#', $path, $m)) {
        $user = rawurldecode($m[1]);
        $out[] = 'https://' . $host . '/users/' . rawurlencode($user);
        $out[] = 'https://' . $host . '/users/' . rawurlencode($user) . '/';
        $out[] = 'https://' . $host . '/users/' . $user;
        $out[] = 'https://' . $host . '/users/' . $user . '/';
    }
    // Normalize to rtrim variants only once
    $norm = [];
    foreach ($out as $u) {
        $u = rtrim($u, '/');
        if ($u !== '') {
            $norm[$u] = true;
            $norm[$u . '/'] = true;
        }
    }
    return array_keys($norm);
}

/**
 * Best-effort: mark verified if remote actor lists us in alsoKnownAs (or we list them for aka).
 *
 * @return array{ok:bool,verified:bool,error?:string,detail?:string}
 */
function ap_alias_verify(int $id, string $actorKey = 'cmdr_nova'): array
{
    $st = ap_db()->prepare('SELECT * FROM account_aliases WHERE id = ? AND actor_key = ?');
    $st->execute([$id, $actorKey]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return ['ok' => false, 'verified' => false, 'error' => 'Alias not found'];
    }
    $remoteId = rtrim((string) ($row['alias_actor_id'] ?? ''), '/');
    // Prefer canonical /users/ IRI; also accept /@user if Mastodon listed that form.
    $selfUsers = 'https://mkultra.monster/users/' . rawurlencode($actorKey);
    $selfAt = 'https://mkultra.monster/@' . rawurlencode($actorKey);
    $selfForms = ap_alias_actor_iri_equivalents($selfUsers);
    $direction = (string) ($row['direction'] ?? 'aka');
    if (!function_exists('ap_fetch_as2_object')) {
        return ['ok' => false, 'verified' => false, 'error' => 'Fetch helper unavailable'];
    }
    // Resolve web profile URLs (/@user) to the Person id when needed
    $fetchUrl = $remoteId;
    if (preg_match('#^https://[^/]+/@[^/]+$#', $remoteId) && function_exists('ap_resolve_actor_ref')) {
        $resolved = ap_resolve_actor_ref($remoteId);
        if (is_string($resolved) && str_starts_with($resolved, 'https://')) {
            $fetchUrl = rtrim($resolved, '/');
        }
    }
    $doc = ap_fetch_as2_object($fetchUrl);
    if (!is_array($doc) && $fetchUrl !== $remoteId) {
        $doc = ap_fetch_as2_object($remoteId);
    }
    if (!is_array($doc)) {
        return ['ok' => false, 'verified' => false, 'error' => 'Could not fetch remote actor'];
    }
    $aka = $doc['alsoKnownAs'] ?? [];
    if (!is_array($aka)) {
        $aka = $aka ? [$aka] : [];
    }
    $akaNorm = [];
    foreach ($aka as $a) {
        $idStr = '';
        if (is_string($a)) {
            $idStr = rtrim($a, '/');
        } elseif (is_array($a) && isset($a['id'])) {
            $idStr = rtrim((string) $a['id'], '/');
        }
        if ($idStr === '') {
            continue;
        }
        foreach (ap_alias_actor_iri_equivalents($idStr) as $eq) {
            $akaNorm[$eq] = true;
        }
    }
    $verified = false;
    $detail = '';
    if ($direction === 'aka' || $direction === 'moved_from') {
        // Remote should list us (Mastodon alias / move prep) — /users/x or /@x
        foreach ($selfForms as $form) {
            if (isset($akaNorm[$form])) {
                $verified = true;
                break;
            }
        }
        if ($verified) {
            $detail = 'Remote lists us in alsoKnownAs';
        } else {
            $listed = [];
            foreach ($aka as $a) {
                if (is_string($a) && $a !== '') {
                    $listed[] = $a;
                } elseif (is_array($a) && !empty($a['id'])) {
                    $listed[] = (string) $a['id'];
                }
            }
            $detail = 'Remote does not list ' . $selfUsers
                . ' (or ' . $selfAt . ') in alsoKnownAs yet';
            if ($listed !== []) {
                $detail .= ' Remote currently has: ' . implode(', ', array_slice($listed, 0, 8));
            } else {
                $detail .= ' Remote alsoKnownAs is empty — on Mastodon: Edit profile → Account → Add account alias, paste '
                    . $selfUsers . ' (or ' . $selfAt . '), save, wait a minute, then Verify again.';
            }
        }
    } elseif ($direction === 'moved_to') {
        // We point at them; optional check they exist
        $verified = true;
        $detail = 'Target actor reachable';
    }
    if ($verified) {
        $now = ap_db_now();
        if (function_exists('ap_db_execute_retry')) {
            ap_db_execute_retry(
                'UPDATE account_aliases SET verified_at = ? WHERE id = ?',
                [$now, $id]
            );
        } else {
            try {
                ap_db()->prepare('UPDATE account_aliases SET verified_at = ? WHERE id = ?')->execute([$now, $id]);
            } catch (Throwable $e) {
                return [
                    'ok' => true,
                    'verified' => true,
                    'detail' => $detail . ' (match ok; could not save verified flag — try again)',
                ];
            }
        }
    }
    return ['ok' => true, 'verified' => $verified, 'detail' => $detail];
}

/**
 * @return list<string> actor URLs for alsoKnownAs
 */
function ap_alias_also_known_as_urls(string $actorKey = 'cmdr_nova'): array
{
    $out = [];
    foreach (ap_alias_list($actorKey, 'aka') as $row) {
        $id = rtrim((string) ($row['alias_actor_id'] ?? ''), '/');
        if ($id !== '') {
            $out[] = $id;
        }
    }
    // moved_from aliases are also often published as alsoKnownAs on the new account
    foreach (ap_alias_list($actorKey, 'moved_from') as $row) {
        $id = rtrim((string) ($row['alias_actor_id'] ?? ''), '/');
        if ($id !== '' && !in_array($id, $out, true)) {
            $out[] = $id;
        }
    }
    return $out;
}

/**
 * @return array{moved_to_actor_id:?string,moved_to_acct:?string,moved_at:?string,cleared_at:?string}|null
 */
function ap_move_get(string $actorKey = 'cmdr_nova'): ?array
{
    try {
        $st = ap_db()->prepare('SELECT * FROM ap_account_move WHERE actor_key = ?');
        $st->execute([$actorKey]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ap_move_active_target(string $actorKey = 'cmdr_nova'): ?string
{
    $row = ap_move_get($actorKey);
    if (!$row) {
        return null;
    }
    $target = rtrim((string) ($row['moved_to_actor_id'] ?? ''), '/');
    if ($target === '') {
        return null;
    }
    // Cleared moves do not emit movedTo
    if (!empty($row['cleared_at'])) {
        return null;
    }
    return $target;
}

function ap_move_cooldown_remaining_seconds(string $actorKey = 'cmdr_nova'): int
{
    $row = ap_move_get($actorKey);
    if (!$row || empty($row['moved_at'])) {
        return 0;
    }
    try {
        $movedAt = new DateTimeImmutable((string) $row['moved_at']);
        $until = $movedAt->modify('+' . AP_IE_MOVE_COOLDOWN_DAYS . ' days');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $diff = $until->getTimestamp() - $now->getTimestamp();
        return max(0, $diff);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Move followers out: set movedTo on our actor.
 *
 * @return array{ok:bool,error?:string,delivered?:int}
 */
function ap_move_out(string $targetRef, string $confirmPhrase, string $actorKey = 'cmdr_nova'): array
{
    if (trim($confirmPhrase) !== 'MOVE') {
        return ['ok' => false, 'error' => 'Type MOVE to confirm'];
    }
    $remaining = ap_move_cooldown_remaining_seconds($actorKey);
    $existing = ap_move_get($actorKey);
    // Allow if never moved, or cooldown expired, or clearing+remooving after clear
    if ($remaining > 0 && $existing && empty($existing['cleared_at']) && !empty($existing['moved_to_actor_id'])) {
        $days = (int) ceil($remaining / 86400);
        return ['ok' => false, 'error' => "Move cooldown active (~{$days} days left). Clear the current move first or wait."];
    }
    if ($remaining > 0 && $existing && !empty($existing['cleared_at'])) {
        $days = (int) ceil($remaining / 86400);
        return ['ok' => false, 'error' => "Move cooldown active (~{$days} days left since last move)."];
    }

    $target = null;
    $acct = null;
    $ref = trim($targetRef);
    if (str_starts_with($ref, 'https://')) {
        $target = rtrim($ref, '/');
        $acct = ap_ie_actor_to_acct($target, true) ?: null;
    } else {
        $acct = ap_ie_normalize_acct($ref);
        if ($acct === null) {
            return ['ok' => false, 'error' => 'Need @user@host or https actor URL for move target'];
        }
        $target = ap_ie_resolve_acct_to_actor($acct);
    }
    if ($target === null) {
        return ['ok' => false, 'error' => 'Could not resolve move target'];
    }
    $self = 'https://mkultra.monster/users/' . rawurlencode($actorKey);
    if ($target === $self) {
        return ['ok' => false, 'error' => 'Cannot move to yourself'];
    }

    $now = ap_db_now();
    try {
        ap_db()->prepare(
            'INSERT INTO ap_account_move (actor_key, moved_to_actor_id, moved_to_acct, moved_at, cleared_at)
             VALUES (?, ?, ?, ?, NULL)
             ON CONFLICT(actor_key) DO UPDATE SET
               moved_to_actor_id = excluded.moved_to_actor_id,
               moved_to_acct = excluded.moved_to_acct,
               moved_at = excluded.moved_at,
               cleared_at = NULL'
        )->execute([$actorKey, $target, $acct, $now]);
        // Also record as moved_to alias
        ap_alias_add($target, 'moved_to', $actorKey);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save move: ' . $e->getMessage()];
    }

    $delivered = 0;
    if (function_exists('ap_publish_profile_update')) {
        $fan = ap_publish_profile_update($actorKey);
        $delivered = (int) ($fan['delivered'] ?? 0);
    } elseif (function_exists('ap_cmdr_publish_profile_update')) {
        $fan = ap_cmdr_publish_profile_update($actorKey);
        $delivered = (int) ($fan['delivered'] ?? 0);
    }
    // Best-effort AS2 Move activity
    if (function_exists('ap_send_move_activity')) {
        ap_send_move_activity($target, $actorKey);
    } elseif (function_exists('ap_cmdr_send_move_activity')) {
        ap_cmdr_send_move_activity($target, $actorKey);
    }
    return ['ok' => true, 'delivered' => $delivered];
}

/**
 * Clear movedTo (recover from tombstone). Still counts toward cooldown from moved_at.
 *
 * @return array{ok:bool,error?:string,delivered?:int}
 */
function ap_move_clear(string $confirmPhrase, string $actorKey = 'cmdr_nova'): array
{
    if (trim($confirmPhrase) !== 'CLEAR') {
        return ['ok' => false, 'error' => 'Type CLEAR to confirm'];
    }
    $row = ap_move_get($actorKey);
    if (!$row || empty($row['moved_to_actor_id']) || !empty($row['cleared_at'])) {
        return ['ok' => false, 'error' => 'No active move to clear'];
    }
    $now = ap_db_now();
    ap_db()->prepare(
        'UPDATE ap_account_move SET cleared_at = ?, moved_to_actor_id = NULL, moved_to_acct = NULL WHERE actor_key = ?'
    )->execute([$now, $actorKey]);
    $delivered = 0;
    if (function_exists('ap_publish_profile_update')) {
        $fan = ap_publish_profile_update($actorKey);
        $delivered = (int) ($fan['delivered'] ?? 0);
    } elseif (function_exists('ap_cmdr_publish_profile_update')) {
        $fan = ap_cmdr_publish_profile_update($actorKey);
        $delivered = (int) ($fan['delivered'] ?? 0);
    }
    return ['ok' => true, 'delivered' => $delivered];
}

/**
 * Prepare as migration target: add old account as moved_from / aka alias.
 *
 * @return array{ok:bool,error?:string,id?:int}
 */
function ap_move_prepare_target(string $oldAccountRef, string $actorKey = 'cmdr_nova'): array
{
    return ap_alias_add($oldAccountRef, 'moved_from', $actorKey);
}
