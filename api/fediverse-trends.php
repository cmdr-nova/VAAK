<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=900');
header('X-Content-Type-Options: nosniff');

$cacheFile = sys_get_temp_dir() . '/mkultra-vaak-trends.json';
$cacheTtl = 600;

function sendCachedResponse(string $cacheFile): bool
{
    if (!is_file($cacheFile)) {
        return false;
    }

    $cached = file_get_contents($cacheFile);
    if ($cached === false) {
        return false;
    }

    echo $cached;
    return true;
}

if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < $cacheTtl) {
    if (sendCachedResponse($cacheFile)) {
        exit;
    }
}

$handle = curl_init('https://mkultra.monster/api/v1/trends/tags?limit=5');
curl_setopt_array($handle, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT_MS => 2000,
    CURLOPT_TIMEOUT_MS => 6000,
    CURLOPT_USERAGENT => 'MKULTRA-Fediverse-Trends/1.0',
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
]);

$body = curl_exec($handle);
$httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
$requestOk = $body !== false && curl_errno($handle) === CURLE_OK && $httpCode === 200;
curl_close($handle);

if (!$requestOk) {
    if (sendCachedResponse($cacheFile)) {
        exit;
    }

    http_response_code(502);
    echo '{"error":"Fediverse trends are temporarily unavailable"}';
    exit;
}

$upstreamTags = json_decode((string) $body, true);
if (!is_array($upstreamTags)) {
    if (sendCachedResponse($cacheFile)) {
        exit;
    }

    http_response_code(502);
    echo '{"error":"Invalid response from trends provider"}';
    exit;
}

$tags = [];
foreach (array_slice($upstreamTags, 0, 5) as $tag) {
    $name = is_string($tag['name'] ?? null) ? trim($tag['name']) : '';
    if ($name === '' || !preg_match('/^[\p{L}\p{N}_-]+$/u', $name)) {
        continue;
    }

    $uses = 0;
    $accounts = 0;
    foreach (($tag['history'] ?? []) as $history) {
        $uses += max(0, (int) ($history['uses'] ?? 0));
        $accounts += max(0, (int) ($history['accounts'] ?? 0));
    }

    $tags[] = [
        'name' => $name,
        'url' => 'https://mkultra.monster/vaak/?view=search&q=' . rawurlencode('#' . $name),
        'uses' => $uses,
        'accounts' => $accounts,
    ];
}

$response = json_encode([
    'source' => 'mkultra.monster/vaak',
    'checked_at' => gmdate('c'),
    'tags' => $tags,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($response === false) {
    http_response_code(500);
    echo '{"error":"Unable to encode trends"}';
    exit;
}

$temporaryCache = $cacheFile . '.' . getmypid();
if (file_put_contents($temporaryCache, $response, LOCK_EX) !== false) {
    rename($temporaryCache, $cacheFile);
}

echo $response;
