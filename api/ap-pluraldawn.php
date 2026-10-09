<?php
/**
 * Pluraldawn display lookup.
 *
 * A member list can be hidden in the least significant bits of an original
 * avatar. This file reads that published format and returns the member list.
 * It does not change the stored account, and it does not copy the userscript.
 *
 * Callers rewrite a card only when exactly one member indicator matches.
 */
declare(strict_types=1);

/** @return list<array{id:string,name:string,indicators:list<string>,avatar:string,font:string}> */
function ap_pluraldawn_members_from_image(string $bytes): array
{
    if ($bytes === '' || strlen($bytes) > 2_000_000) {
        return [];
    }
    $rgb = ap_pluraldawn_image_rgb($bytes);
    if ($rgb === null) {
        return [];
    }
    $pldw2 = ap_pluraldawn_extract($rgb['rgb'], $rgb['w'], $rgb['h'], 'pldw2');
    if (str_starts_with($pldw2, 'PlDw2')) {
        $members = ap_pluraldawn_parse_pldw2(substr($pldw2, 5));
        if ($members !== []) {
            return $members;
        }
    }
    $pldon = ap_pluraldawn_extract($rgb['rgb'], $rgb['w'], $rgb['h'], 'pldon');
    if (str_starts_with($pldon, 'PlDon')) {
        return ap_pluraldawn_parse_pldon(substr($pldon, 5));
    }
    return [];
}

/**
 * @return array{w:int,h:int,rgb:string}|null row-major RGB, no color correction
 */
function ap_pluraldawn_image_rgb(string $bytes): ?array
{
    if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
        return ap_pluraldawn_png_rgb($bytes);
    }
    if (strlen($bytes) >= 16 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' && substr($bytes, 12, 4) === 'VP8L') {
        return ap_pluraldawn_webp_rgb($bytes);
    }
    return null;
}

/** @return array{w:int,h:int,rgb:string}|null */
function ap_pluraldawn_png_rgb(string $bytes): ?array
{
    $len = strlen($bytes);
    if ($len < 33) {
        return null;
    }
    $offset = 8;
    $w = 0;
    $h = 0;
    $depth = 0;
    $color = 0;
    $interlace = 0;
    $idat = '';
    while ($offset + 8 <= $len) {
        $chunkLen = unpack('N', substr($bytes, $offset, 4))[1];
        $offset += 4;
        if ($chunkLen < 0 || $offset + 4 + $chunkLen + 4 > $len) {
            return null;
        }
        $type = substr($bytes, $offset, 4);
        $offset += 4;
        $data = substr($bytes, $offset, $chunkLen);
        $offset += $chunkLen + 4;
        if ($type === 'IHDR') {
            if (strlen($data) < 13) {
                return null;
            }
            $hdr = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccomp/Cfilter/Cinterlace', $data);
            $w = (int) $hdr['width'];
            $h = (int) $hdr['height'];
            $depth = (int) $hdr['depth'];
            $color = (int) $hdr['color'];
            $interlace = (int) $hdr['interlace'];
        } elseif ($type === 'IDAT') {
            $idat .= $data;
        } elseif ($type === 'IEND') {
            break;
        }
    }
    if ($w < 1 || $h < 1 || $w > 2048 || $h > 2048 || $depth !== 8 || $interlace !== 0) {
        return null;
    }
    if ($color !== 2 && $color !== 6) {
        return null;
    }
    $bpp = $color === 6 ? 4 : 3;
    $raw = zlib_decode($idat);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $stride = $w * $bpp;
    $expected = ($stride + 1) * $h;
    if (strlen($raw) < $expected) {
        return null;
    }
    $rows = [];
    $pos = 0;
    for ($y = 0; $y < $h; $y++) {
        $filter = ord($raw[$pos]);
        $pos++;
        $scan = substr($raw, $pos, $stride);
        $pos += $stride;
        $prev = $rows[$y - 1] ?? str_repeat("\0", $stride);
        $rows[] = ap_pluraldawn_unfilter($filter, $scan, $prev, $bpp);
    }
    $rgb = '';
    foreach ($rows as $row) {
        if ($bpp === 3) {
            $rgb .= $row;
            continue;
        }
        for ($x = 0; $x < $w; $x++) {
            $rgb .= substr($row, $x * 4, 3);
        }
    }
    return ['w' => $w, 'h' => $h, 'rgb' => $rgb];
}

function ap_pluraldawn_unfilter(int $filter, string $scan, string $prev, int $bpp): string
{
    $n = strlen($scan);
    $out = '';
    for ($i = 0; $i < $n; $i++) {
        $x = ord($scan[$i]);
        $left = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
        $up = ord($prev[$i] ?? "\0");
        $ul = $i >= $bpp ? ord($prev[$i - $bpp] ?? "\0") : 0;
        $pred = match ($filter) {
            1 => $left,
            2 => $up,
            3 => intdiv($left + $up, 2),
            4 => ap_pluraldawn_paeth($left, $up, $ul),
            default => 0,
        };
        $out .= chr(($x + $pred) & 255);
    }
    return $out;
}

function ap_pluraldawn_paeth(int $a, int $b, int $c): int
{
    $p = $a + $b - $c;
    $pa = abs($p - $a);
    $pb = abs($p - $b);
    $pc = abs($p - $c);
    if ($pa <= $pb && $pa <= $pc) {
        return $a;
    }
    if ($pb <= $pc) {
        return $b;
    }
    return $c;
}

/** @return array{w:int,h:int,rgb:string}|null */
function ap_pluraldawn_webp_rgb(string $bytes): ?array
{
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $im = @imagecreatefromstring($bytes);
    if (!$im instanceof GdImage) {
        return null;
    }
    if (function_exists('imagepalettetotruecolor')) {
        @imagepalettetotruecolor($im);
    }
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 1 || $h < 1 || $w > 2048 || $h > 2048) {
        imagedestroy($im);
        return null;
    }
    $rgb = '';
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $rgb .= chr(($c >> 16) & 255) . chr(($c >> 8) & 255) . chr($c & 255);
        }
    }
    imagedestroy($im);
    return ['w' => $w, 'h' => $h, 'rgb' => $rgb];
}

function ap_pluraldawn_extract(string $rgb, int $w, int $h, string $mode): string
{
    $pix = $w * $h;
    $out = '';
    $max = min($pix, 262144);
    for ($i = 0; $i < $max; $i++) {
        $p = $pix - 1 - $i;
        $o = $p * 3;
        $r = ord($rgb[$o]);
        $g = ord($rgb[$o + 1]);
        $b = ord($rgb[$o + 2]);
        if ($mode === 'pldon') {
            $byte = (($r & 3) << 6) | (($g & 7) << 3) | ($b & 7);
        } else {
            $byte = (($r & 7) << 5) | (($g & 3) << 3) | ($b & 7);
        }
        $out .= chr($byte);
    }
    return $out;
}

/** @return list<array{id:string,name:string,indicators:list<string>,avatar:string,font:string}> */
function ap_pluraldawn_parse_pldw2(string $zlibBytes): array
{
    $plain = ap_pluraldawn_inflate($zlibBytes);
    if ($plain === null || $plain === '') {
        return [];
    }
    $groups = ap_pluraldawn_groups($plain);
    if (count($groups) < 4) {
        return [];
    }
    $ids = $groups[0];
    $names = $groups[1];
    $indicators = $groups[2];
    $avatars = $groups[3];
    $fonts = $groups[4] ?? array_fill(0, count($ids), 'normal');
    $n = count($ids);
    if ($n < 1 || $n > 64 || count($names) !== $n || count($indicators) !== $n || count($avatars) !== $n || count($fonts) !== $n) {
        return [];
    }
    $avatarPrefixes = [
        'emoji',
        'https://files.y2k.diy/',
        'https://exa.y2k.diy/junk/noise.png',
        'https://pool.jortage.com/',
        'https://blob.jortage.com/',
        'https://us.pool.jortage.com/',
        'https://us.blob.jortage.com/',
        'https://cn.pool.jortage.com/',
        'https://cn.blob.jortage.com/',
        'https://cdn.pluralkit.me/files/',
        'https://cdn.pluralkit.me/',
        'https://cdn.plural.gg/',
        'https://scratchupload.xyz/',
        'https://scratchupload.org/',
    ];
    $fontPrefixes = ['normal', 'smallcaps', 'small', 'monospace'];
    $members = [];
    for ($i = 0; $i < $n; $i++) {
        $indicatorsI = array_map(
            static fn (string $part): string => $part,
            explode("\x1F", $indicators[$i])
        );
        $member = ap_pluraldawn_member(
            $ids[$i],
            $names[$i],
            $indicatorsI,
            ap_pluraldawn_prefix($avatars[$i], $avatarPrefixes),
            ap_pluraldawn_prefix($fonts[$i], $fontPrefixes)
        );
        if ($member === null) {
            return [];
        }
        $members[] = $member;
    }
    return $members;
}

function ap_pluraldawn_inflate(string $data): ?string
{
    if ($data === '' || !function_exists('inflate_init')) {
        return null;
    }
    $ctx = inflate_init(ZLIB_ENCODING_DEFLATE);
    if ($ctx === false) {
        return null;
    }
    $out = '';
    $offset = 0;
    $len = strlen($data);
    while ($offset < $len) {
        $chunk = substr($data, $offset, 2048);
        $offset += strlen($chunk);
        $piece = inflate_add($ctx, $chunk, ZLIB_NO_FLUSH);
        if (!is_string($piece)) {
            return $out !== '' ? $out : null;
        }
        $out .= $piece;
        $status = inflate_get_status($ctx);
        if ($status === ZLIB_STREAM_END) {
            return $out;
        }
        if ($status === ZLIB_DATA_ERROR || $status === ZLIB_STREAM_ERROR) {
            return $out !== '' ? $out : null;
        }
    }
    $tail = inflate_add($ctx, '', ZLIB_FINISH);
    if (is_string($tail)) {
        $out .= $tail;
    }
    return $out !== '' ? $out : null;
}

/**
 * Split a PlDw2 plaintext stream into groups of record strings.
 * Stops at EOT when it is not inside a value.
 *
 * @return list<list<string>>
 */
function ap_pluraldawn_groups(string $bin): array
{
    $groups = [];
    $current = [];
    $buf = '';
    $n = strlen($bin);
    for ($i = 0; $i < $n; $i++) {
        $b = ord($bin[$i]);
        if ($b === 0x10) {
            $i++;
            if ($i >= $n) {
                break;
            }
            $buf .= "\x10" . $bin[$i];
            continue;
        }
        if ($b === 0x1F) {
            $buf .= "\x1F";
            continue;
        }
        if ($b === 0x1E) {
            $current[] = $buf;
            $buf = '';
            continue;
        }
        if ($b === 0x1D) {
            $current[] = $buf;
            $groups[] = $current;
            $current = [];
            $buf = '';
            continue;
        }
        if ($b === 0x04 && $buf === '' && $current === []) {
            break;
        }
        $buf .= $bin[$i];
    }
    return $groups;
}

function ap_pluraldawn_prefix(string $value, array $prefixes): string
{
    if ($value !== '' && $value[0] === "\x10" && isset($value[1])) {
        $idx = ord($value[1]);
        return ($prefixes[$idx] ?? '') . substr($value, 2);
    }
    return $value;
}

/** @return list<array{id:string,name:string,indicators:list<string>,avatar:string,font:string}> */
function ap_pluraldawn_parse_pldon(string $rest): array
{
    $end = strpos($rest, "\0");
    if ($end === false) {
        return [];
    }
    $json = json_decode(substr($rest, 0, $end), true);
    if (!is_array($json) || !isset($json['members']) || !is_array($json['members'])) {
        return [];
    }
    if (count($json['members']) < 1 || count($json['members']) > 64) {
        return [];
    }
    $members = [];
    foreach ($json['members'] as $row) {
        if (!is_array($row)) {
            return [];
        }
        $emoji = $row['emoji'] ?? null;
        $indicators = [];
        if (is_string($emoji)) {
            $indicators = [$emoji];
        } elseif (is_array($emoji)) {
            foreach ($emoji as $part) {
                if (is_string($part)) {
                    $indicators[] = $part;
                }
            }
        }
        $name = $row['name'] ?? '';
        if (is_array($name)) {
            $name = '';
            foreach ($row['name'] as $part) {
                if (is_string($part) && $part !== '') {
                    $name = $part;
                    break;
                }
            }
        }
        $member = ap_pluraldawn_member(
            is_string($row['id'] ?? null) ? $row['id'] : '',
            is_string($name) ? $name : '',
            $indicators,
            is_string($row['avatar'] ?? null) ? $row['avatar'] : 'emoji',
            is_string($row['font'] ?? null) ? $row['font'] : 'normal'
        );
        if ($member === null) {
            return [];
        }
        $members[] = $member;
    }
    return $members;
}

/**
 * @param list<string> $indicators
 * @return array{id:string,name:string,indicators:list<string>,avatar:string,font:string}|null
 */
function ap_pluraldawn_member(string $id, string $name, array $indicators, string $avatar, string $font): ?array
{
    $id = ap_pluraldawn_clean($id, 64);
    $name = ap_pluraldawn_clean($name, 160);
    if ($id === '' || $name === '' || preg_match('/[\s\/\\\\]/', $id)) {
        return null;
    }
    $cleanIndicators = [];
    foreach ($indicators as $indicator) {
        $indicator = ap_pluraldawn_clean($indicator, 64);
        if ($indicator !== '') {
            $cleanIndicators[] = $indicator;
        }
    }
    $cleanIndicators = array_values(array_unique($cleanIndicators));
    if ($cleanIndicators === [] || count($cleanIndicators) > 8) {
        return null;
    }
    if (!in_array($font, ['normal', 'smallcaps', 'small', 'monospace'], true)) {
        $font = 'normal';
    }
    if ($avatar !== 'emoji') {
        $avatar = ap_pluraldawn_avatar_url($avatar);
    }
    return [
        'id' => $id,
        'name' => $name,
        'indicators' => $cleanIndicators,
        'avatar' => $avatar,
        'font' => $font,
    ];
}

function ap_pluraldawn_clean(string $value, int $max): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    $value = trim($value);
    if (strlen($value) > $max) {
        $value = substr($value, 0, $max);
    }
    return $value;
}

function ap_pluraldawn_avatar_url(string $url): string
{
    $url = trim($url);
    if (!preg_match('#^https://#i', $url)) {
        return 'emoji';
    }
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    $allowed = [
        'files.y2k.diy',
        'pool.jortage.com',
        'blob.jortage.com',
        'us.pool.jortage.com',
        'us.blob.jortage.com',
        'cn.pool.jortage.com',
        'cn.blob.jortage.com',
        'cdn.pluralkit.me',
        'cdn.plural.gg',
        'scratchupload.xyz',
        'scratchupload.org',
    ];
    if ($url === 'https://exa.y2k.diy/junk/noise.png') {
        return $url;
    }
    if (!in_array($host, $allowed, true) || str_contains($path, '..')) {
        return 'emoji';
    }
    return $url;
}

function ap_pluraldawn_icon_for_actor(string $actor): ?string
{
    $actor = rtrim(trim($actor), '/');
    if (!preg_match('#^https://#i', $actor) || strlen($actor) > 300) {
        return null;
    }
    $host = strtolower((string) (parse_url($actor, PHP_URL_HOST) ?: ''));
    $path = (string) (parse_url($actor, PHP_URL_PATH) ?: '');
    if ($host === 'mkultra.monster' || $host === 'www.mkultra.monster') {
        if (!preg_match('#^/(?:vaak/)?users/([A-Za-z][A-Za-z0-9_]{1,29})$#', $path, $m)) {
            return null;
        }
        $profile = ap_profile_get($m[1]);
        $icon = ap_local_avatar_url(is_string($profile['icon_url'] ?? null) ? $profile['icon_url'] : null);
        return str_starts_with($icon, 'https://') ? $icon : null;
    }
    if (!function_exists('ap_remote_actor_get')) {
        return null;
    }
    $row = ap_remote_actor_get($actor);
    $icon = is_array($row) ? trim((string) ($row['icon_source_url'] ?? '')) : '';
    return str_starts_with($icon, 'https://') ? $icon : null;
}

/** @return list<array{id:string,name:string,indicators:list<string>,avatar:string,font:string}>|null null when the lookup should be retried */
function ap_pluraldawn_members_for_actor(string $actor): ?array
{
    $icon = ap_pluraldawn_icon_for_actor($actor);
    if ($icon === null) {
        return [];
    }
    $cacheKey = 'vaak:pluraldawn:v1:' . hash('sha256', $icon);
    $cached = function_exists('ap_redis_json_get') ? ap_redis_json_get($cacheKey) : null;
    if (is_array($cached) && array_key_exists('members', $cached) && is_array($cached['members'])) {
        return ap_pluraldawn_cached_members($cached['members']);
    }
    $rate = function_exists('ap_redis_rate_get') ? ap_redis_rate_get('pluraldawn-fetch') : null;
    if ($rate !== null && $rate >= 40) {
        return null;
    }
    $lock = 'pluraldawn:' . hash('sha256', $icon);
    $locked = function_exists('ap_redis_lock') && ap_redis_lock($lock, 20);
    if (function_exists('ap_redis_lock') && !$locked) {
        return null;
    }
    try {
        $cached = function_exists('ap_redis_json_get') ? ap_redis_json_get($cacheKey) : null;
        if (is_array($cached) && array_key_exists('members', $cached) && is_array($cached['members'])) {
            return ap_pluraldawn_cached_members($cached['members']);
        }
        if (function_exists('ap_redis_rate_count')) {
            $count = ap_redis_rate_count('pluraldawn-fetch', 60);
            if ($count !== null && $count > 40) {
                return null;
            }
        }
        $fetched = ap_pluraldawn_fetch_image($icon);
        if ($fetched === null) {
            if (function_exists('ap_redis_json_set')) {
                ap_redis_json_set($cacheKey, ['members' => []], 120);
            }
            return [];
        }
        $members = ap_pluraldawn_members_from_image($fetched);
        if (function_exists('ap_redis_json_set')) {
            ap_redis_json_set($cacheKey, ['members' => $members], $members === [] ? 21600 : 86400);
        }
        return $members;
    } finally {
        if ($locked && function_exists('ap_redis_unlock')) {
            ap_redis_unlock($lock);
        }
    }
}

/** @param list<mixed> $rows
 *  @return list<array{id:string,name:string,indicators:list<string>,avatar:string,font:string}>
 */
function ap_pluraldawn_cached_members(array $rows): array
{
    $members = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $member = ap_pluraldawn_member(
            is_string($row['id'] ?? null) ? $row['id'] : '',
            is_string($row['name'] ?? null) ? $row['name'] : '',
            is_array($row['indicators'] ?? null) ? array_values(array_filter($row['indicators'], 'is_string')) : [],
            is_string($row['avatar'] ?? null) ? $row['avatar'] : 'emoji',
            is_string($row['font'] ?? null) ? $row['font'] : 'normal'
        );
        if ($member !== null) {
            $members[] = $member;
        }
    }
    return $members;
}

function ap_pluraldawn_fetch_image(string $url): ?string
{
    $current = $url;
    for ($hop = 0; $hop < 3; $hop++) {
        if (!ap_pluraldawn_url_public($current)) {
            return null;
        }
        $ch = curl_init($current);
        if ($ch === false) {
            return null;
        }
        $buf = '';
        $status = 0;
        $location = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_USERAGENT => 'VAAK-pluraldawn/1 (+https://mkultra.monster/vaak/)',
            CURLOPT_HTTPHEADER => ['Accept: image/png, image/webp, */*'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$status, &$location): int {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                    $status = (int) $m[1];
                } elseif (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$buf): int {
                if (strlen($buf) + strlen($data) > 1_500_000) {
                    return 0;
                }
                $buf .= $data;
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);
        if ($status >= 300 && $status < 400 && $location !== '') {
            $current = ap_pluraldawn_resolve_redirect($current, $location);
            continue;
        }
        if ($status !== 200 || $buf === '') {
            return null;
        }
        if (str_starts_with($buf, "\xFF\xD8") || str_starts_with($buf, 'GIF8')) {
            return '';
        }
        return $buf;
    }
    return null;
}

function ap_pluraldawn_resolve_redirect(string $from, string $location): string
{
    if (preg_match('#^https://#i', $location)) {
        return $location;
    }
    $parts = parse_url($from);
    $origin = 'https://' . ($parts['host'] ?? '');
    if (str_starts_with($location, '/')) {
        return $origin . $location;
    }
    $dir = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', (string) $parts['path']) : '/';
    return $origin . $dir . $location;
}

function ap_pluraldawn_url_public(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $port = (int) ($parts['port'] ?? 443);
    if ($port !== 443) {
        return false;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return ap_pluraldawn_ip_public($host);
    }
    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    if (!is_array($records) || $records === []) {
        return false;
    }
    foreach ($records as $record) {
        $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
        if ($ip === '' || !ap_pluraldawn_ip_public($ip)) {
            return false;
        }
    }
    return true;
}

function ap_pluraldawn_ip_public(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function ap_pluraldawn_http(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo '{"ok":false,"error":"method"}';
        return;
    }
    $user = ap_auth_current_user();
    if ($user === null) {
        http_response_code(401);
        echo '{"ok":false,"error":"auth"}';
        return;
    }
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > 8192) {
        http_response_code(400);
        echo '{"ok":false,"error":"body"}';
        return;
    }
    $body = json_decode($raw, true);
    $actors = is_array($body) && isset($body['actors']) && is_array($body['actors']) ? $body['actors'] : null;
    if ($actors === null) {
        http_response_code(400);
        echo '{"ok":false,"error":"body"}';
        return;
    }
    $systems = [];
    $pending = [];
    $seen = [];
    $count = 0;
    foreach ($actors as $actor) {
        if ($count >= 24 || !is_string($actor)) {
            break;
        }
        $actor = rtrim(trim($actor), '/');
        if ($actor === '' || isset($seen[$actor]) || !preg_match('#^https://#i', $actor)) {
            continue;
        }
        $seen[$actor] = true;
        $count++;
        $members = ap_pluraldawn_members_for_actor($actor);
        if ($members === null) {
            $pending[] = $actor;
            continue;
        }
        $systems[$actor] = $members;
    }
    echo json_encode(['ok' => true, 'systems' => $systems, 'pending' => $pending], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require_once __DIR__ . '/ap-db.php';
    require_once __DIR__ . '/ap-auth.php';
    ap_pluraldawn_http();
}
