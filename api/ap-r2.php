<?php
/**
 * Cloudflare R2 (S3-compatible) uploads for mkultra.monster media.
 * Credentials: /etc/mkultra/r2.env (not in web root).
 */
declare(strict_types=1);

function ap_r2_config(): ?array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg ?: null;
    }
    $path = '/etc/mkultra/r2.env';
    if (!is_readable($path)) {
        $cfg = false;
        return null;
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v);
    }
    foreach (['S3_BUCKET', 'S3_ENDPOINT', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'S3_HOSTNAME'] as $req) {
        if (empty($out[$req])) {
            $cfg = false;
            return null;
        }
    }
    $cfg = $out;
    return $cfg;
}

/**
 * AWS Signature V4 PUT Object to R2 (path-style).
 *
 * @return array{ok:bool,error?:string,key?:string,public_url?:string,etag?:string}
 */
function ap_r2_put_object(string $key, string $body, string $contentType): array
{
    $cfg = ap_r2_config();
    if ($cfg === null) {
        return ['ok' => false, 'error' => 'R2 is not configured on this server'];
    }
    $prefix = (string) ($cfg['S3_KEY_PREFIX'] ?? 'mkultra/');
    if ($prefix !== '' && !str_starts_with($key, $prefix)) {
        $key = ltrim($prefix, '/') . ltrim($key, '/');
    }
    $key = ltrim($key, '/');
    if ($key === '' || str_contains($key, '..')) {
        return ['ok' => false, 'error' => 'Invalid object key'];
    }

    $bucket = $cfg['S3_BUCKET'];
    $region = $cfg['S3_REGION'] !== '' ? $cfg['S3_REGION'] : 'auto';
    $endpoint = rtrim($cfg['S3_ENDPOINT'], '/');
    $access = $cfg['AWS_ACCESS_KEY_ID'];
    $secret = $cfg['AWS_SECRET_ACCESS_KEY'];
    $hostPub = $cfg['S3_ALIAS_HOST'] ?? $cfg['S3_HOSTNAME'];

    $endpointHost = parse_url($endpoint, PHP_URL_HOST);
    if (!is_string($endpointHost) || $endpointHost === '') {
        return ['ok' => false, 'error' => 'Bad S3 endpoint'];
    }

    // Path-style: https://endpoint/bucket/key
    $urlPath = '/' . rawurlencode($bucket) . '/' . str_replace('%2F', '/', rawurlencode($key));
    // rawurlencode encodes slashes — fix path segments
    $urlPath = '/' . $bucket . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
    $host = $endpointHost;
    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash('sha256', $body);
    $contentLength = (string) strlen($body);

    $canonicalHeaders =
        "content-length:{$contentLength}\n" .
        "content-type:{$contentType}\n" .
        "host:{$host}\n" .
        "x-amz-content-sha256:{$payloadHash}\n" .
        "x-amz-date:{$amzDate}\n";
    $signedHeaders = 'content-length;content-type;host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = "PUT\n{$urlPath}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = "{$dateStamp}/{$region}/s3/aws4_request";
    $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    $authorization = "{$algorithm} Credential={$access}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $url = $endpoint . $urlPath;
    $headers = [
        'Host: ' . $host,
        'Content-Type: ' . $contentType,
        'Content-Length: ' . $contentLength,
        'x-amz-content-sha256: ' . $payloadHash,
        'x-amz-date: ' . $amzDate,
        'Authorization: ' . $authorization,
    ];
    // cURL provides reliable status/error reporting for large video PUTs;
    // the stream wrapper can return an empty status after its short timeout.
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_FAILONERROR => false,
            ]);
            $resp = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($status >= 200 && $status < 300) {
                $publicUrl = 'https://' . $hostPub . '/' . $key;
                return [
                    'ok' => true,
                    'key' => $key,
                    'public_url' => $publicUrl,
                    'etag' => null,
                ];
            }
            $detail = $status > 0 ? ('HTTP ' . $status) : ($curlError !== '' ? $curlError : 'no response');
            error_log('[ap-r2] cURL put fail ' . $detail . ' body=' . substr((string) $resp, 0, 300));
            return ['ok' => false, 'error' => 'R2 upload failed (' . $detail . ')'];
        }
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'PUT',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body,
            'timeout' => 300,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    $statusLine = '';
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string) $line, $m)) {
            $statusLine = (string) $line;
        }
    }
    $ok = $statusLine !== '' && (bool) preg_match('/\s(200|201|204)\s/', $statusLine);
    if (!$ok) {
        $detail = trim($statusLine) !== '' ? trim($statusLine) : 'no response (timeout or network error)';
        error_log('[ap-r2] put fail status=' . $detail . ' body=' . substr((string) $resp, 0, 300));
        return ['ok' => false, 'error' => 'R2 upload failed (' . $detail . ')'];
    }
    $publicUrl = 'https://' . $hostPub . '/' . $key;
    return [
        'ok' => true,
        'key' => $key,
        'public_url' => $publicUrl,
        'etag' => null,
    ];
}

/**
 * @return array{ok:bool,error?:string,local_id?:int,attachment?:array}
 */
function ap_media_ingest_upload(array $file, ?string $description = null): array
{
    $ownerUserId = ap_db_default_owner_user_id();
    if ($ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Not signed in'];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed (code ' . (int) ($file['error'] ?? -1) . ')'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        // CLI/test only — never accept arbitrary paths over HTTP
        if (PHP_SAPI !== 'cli' || $tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'error' => 'Missing upload tempfile'];
        }
    }
    $size = (int) ($file['size'] ?? filesize($tmp));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: ((string) ($file['type'] ?? 'application/octet-stream'));
    $mime = strtolower($mime);

    // WebM is a container: finfo often labels audio-only MediaRecorder
    // output as video/webm. Inspect the streams before choosing federation
    // metadata so voice recordings are not published as silent video files.
    if ($mime === 'video/webm' && trim((string) shell_exec('command -v ffprobe')) !== '') {
        $streams = trim((string) shell_exec('ffprobe -v error -show_entries stream=codec_type -of csv=p=0 ' . escapeshellarg($tmp) . ' 2>/dev/null'));
        if ($streams !== '' && !preg_match('/(^|\n)video(\n|$)/', $streams) && preg_match('/(^|\n)audio(\n|$)/', $streams)) {
            $mime = 'audio/webm';
        }
    }

    $kind = 'unknown';
    $max = 0;
    if (str_starts_with($mime, 'image/') || in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence'], true)) {
        $kind = 'image';
        $max = 10 * 1024 * 1024;
        $allowedImg = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif', 'image/heic-sequence'];
        // Some iPhones send HEIC as application/octet-stream; sniff by extension
        $origName = strtolower((string) ($file['name'] ?? ''));
        if ($mime === 'application/octet-stream' && preg_match('/\.(heic|heif)$/', $origName)) {
            $mime = 'image/heic';
        }
        if (!in_array($mime, $allowedImg, true) && !str_starts_with($mime, 'image/')) {
            return ['ok' => false, 'error' => 'Unsupported image type (' . $mime . ')'];
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            // Convert HEIC/unusual formats to JPEG via GD if possible, else reject clearly
            $converted = ap_media_convert_to_jpeg($tmp);
            if ($converted === null) {
                return ['ok' => false, 'error' => 'Unsupported image type (' . $mime . '). Try JPEG/PNG, or disable “High Efficiency” in iPhone camera settings.'];
            }
            $tmp = $converted['path'];
            $bodyOverride = $converted['body'];
            $mime = 'image/jpeg';
            $size = strlen($bodyOverride);
        }
    } elseif (str_starts_with($mime, 'video/')) {
        $kind = 'video';
        $max = 50 * 1024 * 1024;
        if (!in_array($mime, ['video/mp4', 'video/webm', 'video/quicktime'], true)) {
            return ['ok' => false, 'error' => 'Unsupported video type'];
        }
    } elseif (str_starts_with($mime, 'audio/')) {
        $kind = 'audio';
        $max = 20 * 1024 * 1024;
        $allowedAudio = ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/mp4', 'audio/aac', 'audio/webm', 'audio/wav', 'audio/x-wav', 'audio/flac'];
        if (!in_array($mime, $allowedAudio, true)) {
            return ['ok' => false, 'error' => 'Unsupported audio type'];
        }
    } else {
        return ['ok' => false, 'error' => 'Unsupported media type'];
    }
    if ($size <= 0 || $size > $max) {
        return ['ok' => false, 'error' => 'File too large (max ' . (int) ($max / 1024 / 1024) . 'MB)'];
    }

    if ($kind === 'audio' && trim((string) shell_exec('command -v ffprobe')) !== '') {
        $probe = trim((string) shell_exec('ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 ' . escapeshellarg($tmp) . ' 2>/dev/null'));
        if ($probe !== '' && is_numeric($probe) && (float) $probe > 60.5) {
            return ['ok' => false, 'error' => 'Audio recordings must be one minute or shorter'];
        }
    }

    // Normalize browser recordings (usually WebM/Opus) to MP3 for reliable
    // playback across Mastodon, Wafrn, Safari, and other fediverse clients.
    if ($kind === 'audio' && $mime !== 'audio/mpeg' && trim((string) shell_exec('command -v ffmpeg')) !== '') {
        $converted = ap_media_audio_to_mp3($tmp);
        if ($converted === null) {
            return ['ok' => false, 'error' => 'Could not encode audio for federation'];
        }
        $tmp = $converted['path'];
        $bodyOverride = $converted['body'];
        $mime = 'audio/mpeg';
        $size = strlen($bodyOverride);
    }

    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        'audio/mpeg', 'audio/mp3' => 'mp3',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
        'audio/aac' => 'aac',
        'audio/webm' => 'webm',
        'audio/wav', 'audio/x-wav' => 'wav',
        'audio/flac' => 'flac',
        default => 'bin',
    };
    $body = isset($bodyOverride) ? $bodyOverride : file_get_contents($tmp);
    if (!is_string($body) || $body === '') {
        return ['ok' => false, 'error' => 'Could not read upload'];
    }

    $width = null;
    $height = null;
    $previewUrl = null;
    if ($kind === 'image') {
        $info = @getimagesizefromstring($body);
        if (is_array($info)) {
            $width = (int) ($info[0] ?? 0) ?: null;
            $height = (int) ($info[1] ?? 0) ?: null;
        }
    }

    $key = 'media/' . gmdate('Y/m/d') . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
    $put = ap_r2_put_object($key, $body, $mime);
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => $put['error'] ?? 'Upload failed'];
    }
    if ($kind === 'video') {
        $posterBody = ap_media_video_poster_body($tmp);
        if ($posterBody !== null) {
            $posterKey = preg_replace('/\.[a-z0-9]+$/i', '-preview.jpg', $key) ?: ($key . '-preview.jpg');
            $posterPut = ap_r2_put_object($posterKey, $posterBody, 'image/jpeg');
            if (!empty($posterPut['ok']) && !empty($posterPut['public_url'])) {
                $previewUrl = (string) $posterPut['public_url'];
            }
        }
    }

    $desc = $description !== null ? mb_substr(trim(ap_fix_utf8($description)), 0, 1500) : null;
    if ($desc === '') {
        $desc = null;
    }

    ap_db()->prepare(
        'INSERT INTO masto_media (owner_user_id, created_at, s3_key, public_url, preview_url, media_type, mime, file_size, width, height, description)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $ownerUserId,
        ap_db_now(),
        $put['key'],
        $put['public_url'],
        $previewUrl ?: $put['public_url'],
        $kind,
        $mime,
        $size,
        $width,
        $height,
        $desc,
    ]);
    $localId = ap_db_last_insert_id('masto_media', 'local_id');
    $row = ap_media_by_local_id($localId);
    return [
        'ok' => true,
        'local_id' => $localId,
        'attachment' => $row ? ap_masto_media_entity($row) : null,
    ];
}

/** @return array{path:string,body:string}|null */
function ap_media_audio_to_mp3(string $path): ?array
{
    if ($path === '' || !is_file($path) || trim((string) shell_exec('command -v ffmpeg')) === '') {
        return null;
    }
    $out = tempnam(sys_get_temp_dir(), 'ap-audio');
    if ($out === false) {
        return null;
    }
    $mp3 = $out . '.mp3';
    @unlink($out);
    $cmd = 'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($path)
        . ' -vn -codec:a libmp3lame -b:a 128k ' . escapeshellarg($mp3) . ' 2>/dev/null';
    exec($cmd, $ignored, $status);
    if ($status !== 0 || !is_file($mp3)) {
        @unlink($mp3);
        return null;
    }
    $body = file_get_contents($mp3);
    @unlink($mp3);
    return is_string($body) && $body !== '' ? ['path' => $path, 'body' => $body] : null;
}

/**
 * Generate a small representative JPEG for local video attachments.
 *
 * The literal first frame is often a black/fade-in frame. The thumbnail
 * filter chooses a representative frame from the opening segment instead,
 * while the fallback still handles very short or unusual videos.
 */
function ap_media_video_poster_body(string $path): ?string
{
    if ($path === '' || !is_file($path) || trim((string) shell_exec('command -v ffmpeg')) === '') {
        return null;
    }
    $out = tempnam(sys_get_temp_dir(), 'ap-poster');
    if ($out === false) {
        return null;
    }
    @unlink($out);
    $out .= '.jpg';
    $filter = 'thumbnail=30,scale=min(1280\,iw):-2';
    $cmd = 'ffmpeg -nostdin -y -ss 0.5 -i ' . escapeshellarg($path)
        . ' -map 0:v:0 -an -sn -frames:v 1 -vf ' . escapeshellarg($filter)
        . ' -q:v 5 ' . escapeshellarg($out) . ' 2>/dev/null';
    exec($cmd, $unused, $code);
    if ($code !== 0 || !is_file($out)) {
        // Some short/variable-frame-rate files cannot satisfy thumbnail=50.
        $fallback = 'ffmpeg -nostdin -y -ss 1.0 -i ' . escapeshellarg($path)
            . ' -map 0:v:0 -an -sn -frames:v 1 -vf ' . escapeshellarg('scale=min(1280\,iw):-2')
            . ' -q:v 5 ' . escapeshellarg($out) . ' 2>/dev/null';
        exec($fallback, $unused, $code);
    }
    if ($code !== 0 || !is_file($out)) {
        $fallback = 'ffmpeg -nostdin -y -ss 0.1 -i ' . escapeshellarg($path)
            . ' -map 0:v:0 -an -sn -frames:v 1 -vf ' . escapeshellarg('scale=min(1280\,iw):-2')
            . ' -q:v 5 ' . escapeshellarg($out) . ' 2>/dev/null';
        exec($fallback, $unused, $code);
    }
    $body = ($code === 0 && is_file($out)) ? @file_get_contents($out) : false;
    @unlink($out);
    return is_string($body) && $body !== '' ? $body : null;
}

/**
 * Best-effort convert HEIC/other to JPEG. Returns null if unsupported.
 * @return array{path:string,body:string}|null
 */
function ap_media_convert_to_jpeg(string $path): ?array
{
    // Prefer ImageMagick if available (handles HEIC when libheif is installed)
    $out = tempnam(sys_get_temp_dir(), 'apjpg');
    if ($out === false) {
        return null;
    }
    @unlink($out);
    $out .= '.jpg';
    $cmd = 'magick ' . escapeshellarg($path) . '[0] -auto-orient -quality 88 ' . escapeshellarg($out)
        . ' 2>/dev/null';
    if (trim((string) shell_exec('command -v magick')) === '') {
        $cmd = 'convert ' . escapeshellarg($path) . '[0] -auto-orient -quality 88 ' . escapeshellarg($out)
            . ' 2>/dev/null';
        if (trim((string) shell_exec('command -v convert')) === '') {
            return null;
        }
    }
    exec($cmd, $o, $code);
    if ($code !== 0 || !is_file($out)) {
        @unlink($out);
        // GD fallback only works for formats GD can load (not HEIC)
        $data = @file_get_contents($path);
        if (!is_string($data)) {
            return null;
        }
        $img = @imagecreatefromstring($data);
        if ($img === false) {
            return null;
        }
        ob_start();
        imagejpeg($img, null, 88);
        imagedestroy($img);
        $jpeg = ob_get_clean();
        if (!is_string($jpeg) || $jpeg === '') {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'apjpg');
        if ($tmp === false) {
            return null;
        }
        file_put_contents($tmp, $jpeg);
        return ['path' => $tmp, 'body' => $jpeg];
    }
    $jpeg = file_get_contents($out);
    if (!is_string($jpeg) || $jpeg === '') {
        @unlink($out);
        return null;
    }
    return ['path' => $out, 'body' => $jpeg];
}

function ap_media_by_local_id(int $id): ?array
{
    $ownerUserId = ap_db_default_owner_user_id();
    $scoped = ap_db_session_bound();
    $st = ap_db()->prepare(
        $scoped
            ? 'SELECT * FROM masto_media WHERE local_id = ? AND owner_user_id = ?'
            : 'SELECT * FROM masto_media WHERE local_id = ?'
    );
    $st->execute($scoped ? [$id, $ownerUserId] : [$id]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

/** @param list<int> $ids */
function ap_media_by_local_ids(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
    if (!$ids) {
        return [];
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    $scoped = ap_db_session_bound();
    $sql = "SELECT * FROM masto_media WHERE local_id IN ($place)";
    $params = $ids;
    if ($scoped) {
        $sql .= ' AND owner_user_id = ?';
        $params[] = ap_db_default_owner_user_id();
    }
    $st = ap_db()->prepare($sql . ' ORDER BY local_id ASC');
    $st->execute($params);
    return $st->fetchAll();
}

function ap_media_attach_to_status(array $mediaIds, int $statusLocalId): void
{
    if (!$mediaIds) {
        return;
    }
    $place = implode(',', array_fill(0, count($mediaIds), '?'));
    $params = $mediaIds;
    array_unshift($params, $statusLocalId);
    $sql = "UPDATE masto_media SET status_local_id = ? WHERE local_id IN ($place) AND status_local_id IS NULL";
    if (ap_db_session_bound()) {
        $sql .= ' AND owner_user_id = ?';
        $params[] = ap_db_default_owner_user_id();
    }
    ap_db()->prepare($sql)
        ->execute($params);
}

function ap_masto_media_entity(array $row): array
{
    $type = (string) ($row['media_type'] ?? 'unknown');
    $meta = new stdClass();
    if ($type === 'image' && !empty($row['width']) && !empty($row['height'])) {
        $meta = [
            'original' => [
                'width' => (int) $row['width'],
                'height' => (int) $row['height'],
                'size' => ((int) $row['width']) . 'x' . ((int) $row['height']),
                'aspect' => ((int) $row['height']) > 0 ? ((int) $row['width']) / ((int) $row['height']) : 1,
            ],
            'small' => [
                'width' => (int) $row['width'],
                'height' => (int) $row['height'],
                'size' => ((int) $row['width']) . 'x' . ((int) $row['height']),
                'aspect' => ((int) $row['height']) > 0 ? ((int) $row['width']) / ((int) $row['height']) : 1,
            ],
        ];
    }
    return [
        'id' => (string) ((int) $row['local_id']),
        'type' => $type,
        'url' => (string) $row['public_url'],
        'preview_url' => (string) ($row['preview_url'] ?: $row['public_url']),
        'remote_url' => null,
        'preview_remote_url' => null,
        'text_url' => null,
        'meta' => $meta,
        'description' => $row['description'],
        'blurhash' => $row['blurhash'],
    ];
}

/** ActivityPub attachment objects from masto_media rows. */
function ap_media_as2_attachments(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $mime = (string) ($row['mime'] ?? 'application/octet-stream');
        $url = (string) ($row['public_url'] ?? '');
        if ($url === '') {
            continue;
        }
        $type = str_starts_with($mime, 'image/')
            ? 'Image'
            : (str_starts_with($mime, 'audio/') ? 'Audio' : 'Document');
        $att = [
            'type' => $type,
            'mediaType' => $mime,
            'url' => $url,
        ];
        $preview = trim((string) ($row['preview_url'] ?? ''));
        if ($type === 'Document' && str_starts_with($preview, 'https://') && $preview !== $url) {
            $att['thumbnail'] = [
                'type' => 'Image',
                'mediaType' => 'image/jpeg',
                'url' => $preview,
            ];
        }
        // Mastodon expects a named attachment and handles remote audio more
        // consistently when a thumbnail is available. Keep this fallback
        // deterministic for recordings that have no uploaded artwork.
        if ($type === 'Audio') {
            $att['name'] = 'Audio recording';
            $att['summary'] = 'Audio recording';
            $audioArt = [
                'type' => 'Image',
                'mediaType' => 'image/jpeg',
                'url' => 'https://mkultra.monster/api/assets/audio-post-default.jpg',
            ];
            // Mastodon uses AS2 `icon` for remote audio artwork (rather than
            // the `thumbnail` property used by some other implementations).
            $att['icon'] = $audioArt;
            $att['thumbnail'] = $audioArt;
        }
        // Alt text: Mastodon clients use media description; AS2 Image uses name
        // (and summary as a widely understood fallback).
        $desc = trim((string) ($row['description'] ?? ''));
        if ($desc !== '') {
            $att['name'] = $desc;
            $att['summary'] = $desc;
        }
        if (!empty($row['width'])) {
            $att['width'] = (int) $row['width'];
        }
        if (!empty($row['height'])) {
            $att['height'] = (int) $row['height'];
        }
        $out[] = $att;
    }
    return $out;
}

/**
 * AWS Signature V4 DELETE Object from R2.
 *
 * @return array{ok:bool,error?:string}
 */
function ap_r2_delete_object(string $key, bool $allowLocalMedia = false): array
{
    $cfg = ap_r2_config();
    if ($cfg === null) {
        return ['ok' => false, 'error' => 'R2 is not configured on this server'];
    }
    $prefix = (string) ($cfg['S3_KEY_PREFIX'] ?? 'mkultra/');
    if ($prefix !== '' && !str_starts_with($key, $prefix)) {
        $key = ltrim($prefix, '/') . ltrim($key, '/');
    }
    $key = ltrim($key, '/');
    $isCache = str_starts_with($key, 'mkultra/cache/');
    $isLocalMedia = str_starts_with($key, 'mkultra/media/');
    if ($key === '' || str_contains($key, '..') || (!$isCache && !($allowLocalMedia && $isLocalMedia))) {
        // Scheduled cleanup may only delete remote cache objects. Local media
        // is permitted only from an explicit per-user retention action.
        return ['ok' => false, 'error' => 'Refusing to delete key outside allowed media prefixes'];
    }

    $bucket = $cfg['S3_BUCKET'];
    $region = $cfg['S3_REGION'] !== '' ? $cfg['S3_REGION'] : 'auto';
    $endpoint = rtrim($cfg['S3_ENDPOINT'], '/');
    $access = $cfg['AWS_ACCESS_KEY_ID'];
    $secret = $cfg['AWS_SECRET_ACCESS_KEY'];
    $endpointHost = parse_url($endpoint, PHP_URL_HOST);
    if (!is_string($endpointHost) || $endpointHost === '') {
        return ['ok' => false, 'error' => 'Bad S3 endpoint'];
    }

    $urlPath = '/' . $bucket . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
    $host = $endpointHost;
    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash('sha256', '');
    $canonicalHeaders =
        "host:{$host}\n" .
        "x-amz-content-sha256:{$payloadHash}\n" .
        "x-amz-date:{$amzDate}\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = "DELETE\n{$urlPath}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = "{$dateStamp}/{$region}/s3/aws4_request";
    $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    $authorization = "{$algorithm} Credential={$access}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $url = $endpoint . $urlPath;
    $headers = [
        'Host: ' . $host,
        'x-amz-content-sha256: ' . $payloadHash,
        'x-amz-date: ' . $amzDate,
        'Authorization: ' . $authorization,
    ];
    $ctx = stream_context_create([
        'http' => [
            'method' => 'DELETE',
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => 30,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    $statusLine = $http_response_header[0] ?? '';
    $ok = is_string($statusLine) && preg_match('/\s(200|204)\s/', $statusLine);
    if (!$ok) {
        // 404 = already gone — treat as success for cleanup
        if (is_string($statusLine) && preg_match('/\s404\s/', $statusLine)) {
            return ['ok' => true];
        }
        error_log('[ap-r2] delete fail status=' . $statusLine . ' body=' . substr((string) $resp, 0, 200));
        return ['ok' => false, 'error' => 'R2 delete failed (' . trim($statusLine) . ')'];
    }
    return ['ok' => true];
}

/**
 * Extract https media URL from ActivityPub icon/image field.
 */
function ap_as2_media_url(mixed $node): ?string
{
    if (is_string($node)) {
        return ap_profile_sanitize_https_url($node);
    }
    if (!is_array($node)) {
        return null;
    }
    if (isset($node['url'])) {
        if (is_string($node['url'])) {
            return ap_profile_sanitize_https_url($node['url']);
        }
        if (is_array($node['url'])) {
            // list of Link objects or single
            if (isset($node['url']['href'])) {
                return ap_profile_sanitize_https_url($node['url']['href']);
            }
            foreach ($node['url'] as $u) {
                if (is_string($u)) {
                    $s = ap_profile_sanitize_https_url($u);
                    if ($s) {
                        return $s;
                    }
                }
                if (is_array($u) && isset($u['href'])) {
                    $s = ap_profile_sanitize_https_url($u['href']);
                    if ($s) {
                        return $s;
                    }
                }
            }
        }
    }
    if (isset($node['href']) && is_string($node['href'])) {
        return ap_profile_sanitize_https_url($node['href']);
    }
    return null;
}

/**
 * Download a remote image with SSRF protections. Max 2MB.
 *
 * @return array{ok:bool,error?:string,body?:string,content_type?:string}
 */
function ap_remote_media_download(string $url, float $timeoutSec = 4.0): array
{
    $url = ap_profile_sanitize_https_url($url);
    if ($url === null) {
        return ['ok' => false, 'error' => 'Invalid media URL'];
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return ['ok' => false, 'error' => 'Bad host'];
    }
    // Reuse inbox SSRF guard when available
    if (function_exists('ap_host_resolves_public') && !ap_host_resolves_public($host)) {
        return ['ok' => false, 'error' => 'Host not public'];
    }
    $timeoutSec = max(1.0, min(10.0, $timeoutSec));
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSec,
            'follow_location' => 0, // no redirects → harder SSRF bounce
            'header' => "Accept: image/*,*/*\r\nUser-Agent: mkultra-ap-media/1.0 (+https://mkultra.monster/users/cmdr_nova)\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx, 0, 2 * 1024 * 1024 + 1);
    if (!is_string($body) || $body === '') {
        return ['ok' => false, 'error' => 'Download failed'];
    }
    if (strlen($body) > 2 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Remote media too large'];
    }
    $statusLine = $http_response_header[0] ?? '';
    if (!is_string($statusLine) || !preg_match('/\s200\s/', $statusLine)) {
        return ['ok' => false, 'error' => 'Remote media HTTP ' . trim($statusLine)];
    }
    $ctype = 'application/octet-stream';
    foreach ($http_response_header as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            $ctype = strtolower(trim(explode(';', substr($h, 13), 2)[0]));
            break;
        }
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $sniff = $finfo->buffer($body) ?: $ctype;
    $sniff = strtolower($sniff);
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($sniff, $allowed, true)) {
        return ['ok' => false, 'error' => 'Unsupported image type (' . $sniff . ')'];
    }
    return ['ok' => true, 'body' => $body, 'content_type' => $sniff];
}

/**
 * Ensure avatar or header for an actor is cached in R2.
 * Returns public R2 URL on success, or null.
 */
function ap_remote_media_ensure(string $actorId, string $kind = 'avatar', bool $force = false): ?string
{
    $actorId = rtrim(trim($actorId), '/');
    $kind = $kind === 'header' ? 'header' : 'avatar';
    if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
        return null;
    }
    if (str_contains($actorId, 'mkultra.monster')) {
        return null;
    }

    $cached = ap_remote_media_get($actorId, $kind);
    $meta = ap_remote_actor_get($actorId);
    $sourceWanted = $kind === 'header'
        ? ($meta['image_source_url'] ?? null)
        : ($meta['icon_source_url'] ?? null);

    // Fresh cache with same source → reuse
    if ($cached && !$force) {
        $age = time() - (strtotime((string) $cached['fetched_at']) ?: 0);
        $sameSource = ($sourceWanted === null || $sourceWanted === '' || $sourceWanted === ($cached['source_url'] ?? null));
        if ($sameSource && $age < 7 * 86400) {
            ap_remote_media_touch($actorId, $kind);
            return (string) $cached['public_url'];
        }
    }

    // Need actor doc for source URLs / preferredUsername
    if (!function_exists('ap_fetch_actor_doc')) {
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
    }
    $doc = ap_fetch_actor_doc($actorId);
    if (!is_array($doc)) {
        if ($cached) {
            ap_remote_media_touch($actorId, $kind);
            return (string) $cached['public_url'];
        }
        return null;
    }

    $username = null;
    if (isset($doc['preferredUsername']) && is_string($doc['preferredUsername'])) {
        $username = $doc['preferredUsername'];
    }
    $display = null;
    if (isset($doc['name']) && is_string($doc['name'])) {
        $display = $doc['name'];
    }
    $host = parse_url($actorId, PHP_URL_HOST);
    $host = is_string($host) ? strtolower($host) : null;
    $iconSrc = ap_as2_media_url($doc['icon'] ?? null);
    $imageSrc = ap_as2_media_url($doc['image'] ?? null);
    ap_remote_actor_upsert($actorId, [
        'username' => $username,
        'display_name' => $display,
        'host' => $host,
        'icon_source_url' => $iconSrc,
        'image_source_url' => $imageSrc,
    ]);

    $source = $kind === 'header' ? $imageSrc : $iconSrc;
    if ($source === null) {
        // Headers often missing — fall back to avatar for header slot
        if ($kind === 'header' && $iconSrc !== null) {
            $source = $iconSrc;
        } else {
            if ($cached) {
                ap_remote_media_touch($actorId, $kind);
                return (string) $cached['public_url'];
            }
            return null;
        }
    }

    // Same source already cached
    if ($cached && ($cached['source_url'] ?? '') === $source && !$force) {
        ap_remote_media_touch($actorId, $kind);
        return (string) $cached['public_url'];
    }

    $dl = ap_remote_media_download($source, 4.0);
    if (empty($dl['ok'])) {
        if ($cached) {
            ap_remote_media_touch($actorId, $kind);
            return (string) $cached['public_url'];
        }
        // Last resort: return the remote source URL (better than placeholder for some clients)
        return $source;
    }

    $ext = match ($dl['content_type']) {
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => 'jpg',
    };
    $hash = substr(hash('sha256', $actorId . '|' . $kind . '|' . $source), 0, 24);
    $key = 'cache/' . $kind . '/' . $hash . '.' . $ext;
    $put = ap_r2_put_object($key, (string) $dl['body'], (string) $dl['content_type']);
    if (empty($put['ok'])) {
        return $source; // remote URL fallback
    }

    // Replace old object if key changed
    if ($cached && !empty($cached['s3_key']) && $cached['s3_key'] !== ($put['key'] ?? '')) {
        ap_r2_delete_object((string) $cached['s3_key']);
    }

    $now = ap_db_now();
    ap_db()->prepare(
        'INSERT INTO remote_media_cache (actor_id, kind, source_url, s3_key, public_url, content_type, byte_size, fetched_at, last_used_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(actor_id, kind) DO UPDATE SET
           source_url = excluded.source_url,
           s3_key = excluded.s3_key,
           public_url = excluded.public_url,
           content_type = excluded.content_type,
           byte_size = excluded.byte_size,
           fetched_at = excluded.fetched_at,
           last_used_at = excluded.last_used_at'
    )->execute([
        $actorId,
        $kind,
        $source,
        $put['key'],
        $put['public_url'],
        $dl['content_type'],
        strlen((string) $dl['body']),
        $now,
        $now,
    ]);

    return (string) $put['public_url'];
}

/**
 * Queue a background warm of avatar/header for an actor (best-effort).
 */
function ap_remote_media_warm_async(string $actorId): void
{
    if (function_exists('ap_feature_enabled') && !ap_feature_enabled('remote_media_warm', true)) {
        return;
    }
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
        return;
    }
    $script = __DIR__ . '/ap-media-warm.php';
    if (!is_file($script)) {
        return;
    }
    $cmd = 'php ' . escapeshellarg($script) . ' ' . escapeshellarg($actorId)
        . ' > /dev/null 2>&1 &';
    @exec($cmd);
}

/**
 * @return array{avatar:string,header:string}
 */
function ap_remote_media_account_urls(string $actorId): array
{
    // Never sync-fetch during timeline/API requests — Ice Cubes hammers
    // home+public+local together and 3 sync R2 ingests × 5 FPM workers
    // starved the pool (truncated/empty federated responses). Warm async only.
    $fallback = defined('AP_REMOTE_AVATAR_FALLBACK')
        ? AP_REMOTE_AVATAR_FALLBACK
        : 'https://mkultra.monster/img/avatar/default.jpg';
    $actorId = rtrim(trim($actorId), '/');

    $avatar = null;
    $header = null;

    // Skip last_used_at touches on the timeline hot path — cron cleanup + warm
    // batch already refresh usage; per-row UPDATEs fought inbox writers (locks).
    $cachedA = ap_remote_media_get($actorId, 'avatar');
    if ($cachedA) {
        $avatar = (string) $cachedA['public_url'];
    }
    $cachedH = ap_remote_media_get($actorId, 'header');
    if ($cachedH) {
        $header = (string) $cachedH['public_url'];
    }

    if ($avatar === null || $header === null) {
        // Don't block the request — use known remote source URLs and warm R2 async
        $meta = ap_remote_actor_get($actorId);
        if ($avatar === null && is_array($meta) && !empty($meta['icon_source_url'])) {
            $avatar = ap_profile_sanitize_https_url($meta['icon_source_url']);
        }
        if ($header === null && is_array($meta) && !empty($meta['image_source_url'])) {
            $header = ap_profile_sanitize_https_url($meta['image_source_url']);
        } elseif ($header === null && $avatar !== null) {
            $header = $avatar;
        }
        ap_remote_media_warm_async($actorId);
    }

    // Re-sanitize R2/public URLs too (defense in depth for timeline JSON)
    $avatar = ap_profile_sanitize_https_url($avatar) ?: $fallback;
    $header = ap_profile_sanitize_https_url($header) ?: $avatar;
    return ['avatar' => $avatar, 'header' => $header];
}

/**
 * Purge remote media unused for N days. CLI/cron.
 *
 * @return array{scanned:int,deleted:int,errors:int}
 */
function ap_remote_media_cleanup(int $unusedDays = 7, int $limit = 200): array
{
    $rows = ap_remote_media_expired($unusedDays, $limit);
    $deleted = 0;
    $errors = 0;
    foreach ($rows as $row) {
        $key = (string) ($row['s3_key'] ?? '');
        // Remote avatar/header cache only. Local post media uses mkultra/media/
        // and must never be swept by this scheduled cleanup.
        if ($key !== '' && !str_starts_with(ltrim($key, '/'), 'mkultra/cache/')) {
            $errors++;
            error_log('[ap-r2] refusing remote cleanup key outside mkultra/cache/: ' . $key);
            continue;
        }
        if ($key !== '') {
            $res = ap_r2_delete_object($key);
            if (empty($res['ok'])) {
                $errors++;
                continue;
            }
        }
        ap_remote_media_delete_row((int) $row['id']);
        $deleted++;
    }
    return ['scanned' => count($rows), 'deleted' => $deleted, 'errors' => $errors];
}
