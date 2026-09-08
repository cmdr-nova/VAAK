<?php
/** Receive and read verified Webmentions for public local URLs. */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/ap-db.php';

const AP_WEBMENTION_HOST = 'mkultra.monster';
const AP_WEBMENTION_MAX_SOURCE_BYTES = 524288;

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

if ($method === 'GET') {
    $target = ap_webmention_target_url((string) ($_GET['target'] ?? ''));
    if ($target === null) {
        ap_webmention_error('target is required', 400);
    }
    $st = ap_db()->prepare(
        'SELECT id, source_url, target_url, source_title, source_content, source_author, source_published, verified_at
         FROM webmentions WHERE target_url = ? ORDER BY verified_at DESC, id DESC LIMIT 100'
    );
    $st->execute([$target]);
    $rows = $st->fetchAll();
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['target' => $target, 'total' => count($rows), 'webmentions' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    header('Allow: GET, POST, OPTIONS');
    ap_webmention_error('Method not allowed', 405);
}

$source = ap_webmention_normalize_url((string) ($_POST['source'] ?? ''));
$target = ap_webmention_target_url((string) ($_POST['target'] ?? ''));
if ($source === null || $target === null) {
    ap_webmention_error('source and target must be valid HTTPS URLs', 400);
}

$sourceHost = strtolower((string) (parse_url($source, PHP_URL_HOST) ?? ''));
if (!ap_webmention_public_host($sourceHost)) {
    ap_webmention_error('source host is not fetchable', 400);
}
if (function_exists('ap_is_blocked_host') && ap_is_blocked_host($sourceHost)) {
    ap_webmention_error('source host is blocked', 403);
}

$targetCheck = ap_webmention_target_exists($target);
if (!$targetCheck) {
    ap_webmention_error('target is not a public local profile or post', 404);
}

$html = ap_webmention_fetch_source($source);
if ($html === null) {
    ap_webmention_error('source could not be fetched', 400);
}
if (!ap_webmention_contains_target($html, $target)) {
    ap_webmention_error('source does not link to target', 400);
}

$now = gmdate('c');
$title = ap_webmention_extract_title($html);
$content = ap_webmention_extract_content($html);
$author = ap_webmention_extract_meta($html, ['author', 'article:author', 'fediverse:creator']);
$published = ap_webmention_extract_meta($html, ['article:published_time', 'datePublished', 'published_time']);
$db = ap_db();
$existing = $db->prepare('SELECT id FROM webmentions WHERE source_url = ? AND target_url = ? LIMIT 1');
$existing->execute([$source, $target]);
$existingId = $existing->fetchColumn();
$sql = 'INSERT INTO webmentions
    (source_url, target_url, source_title, source_content, source_author, source_published, source_host, verified_at, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON CONFLICT (source_url, target_url) DO UPDATE SET
      source_title = excluded.source_title, source_content = excluded.source_content,
      source_author = excluded.source_author, source_published = excluded.source_published,
      source_host = excluded.source_host, verified_at = excluded.verified_at, updated_at = excluded.updated_at';
$db->prepare($sql)->execute([$source, $target, $title, $content, $author, $published, $sourceHost, $now, $now, $now]);

header('Content-Type: application/json; charset=utf-8');
http_response_code($existingId !== false ? 200 : 201);
echo json_encode(['ok' => true, 'target' => $target, 'source' => $source], JSON_UNESCAPED_SLASHES);

function ap_webmention_error(string $message, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function ap_webmention_normalize_url(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL)) {
        return null;
    }
    $parts = parse_url($value);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !empty($parts['user']) || !empty($parts['pass'])) {
        return null;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '' || isset($parts['port']) && (int) $parts['port'] !== 443) {
        return null;
    }
    $path = (string) ($parts['path'] ?? '/');
    return 'https://' . $host . $path . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
}

function ap_webmention_target_url(string $value): ?string
{
    $url = ap_webmention_normalize_url($value);
    if ($url === null || strtolower((string) parse_url($url, PHP_URL_HOST)) !== AP_WEBMENTION_HOST) {
        return null;
    }
    $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
    if ($path === '') {
        return null;
    }
    if (preg_match('#^/@([A-Za-z][A-Za-z0-9_]{1,29})$#', $path, $m)) {
        return 'https://' . AP_WEBMENTION_HOST . '/users/' . strtolower($m[1]);
    }
    if (preg_match('#^/users/([A-Za-z][A-Za-z0-9_]{1,29})(?:/(notes|creates)/([A-Fa-f0-9]+))?$#', $path, $m)) {
        return 'https://' . AP_WEBMENTION_HOST . '/users/' . strtolower($m[1])
            . (isset($m[2]) ? '/' . $m[2] . '/' . strtolower($m[3]) : '');
    }
    return null;
}

function ap_webmention_target_exists(string $target): bool
{
    $path = (string) parse_url($target, PHP_URL_PATH);
    if (!preg_match('#^/users/([a-z][a-z0-9_]{1,29})(?:/(notes|creates)/([a-f0-9]+))?$#', $path, $m)) {
        return false;
    }
    $actor = ap_local_user_by_username(strtolower($m[1]));
    if ($actor === null) {
        return false;
    }
    if (!isset($m[2])) {
        return true;
    }
    $column = $m[2] === 'notes' ? 'id' : 'create_id';
    $st = ap_db()->prepare("SELECT visibility FROM outbox_notes WHERE {$column} = ? LIMIT 1");
    $st->execute([$target]);
    $row = $st->fetch();
    return is_array($row) && (string) ($row['visibility'] ?? 'public') === 'public';
}

function ap_webmention_public_host(string $host): bool
{
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        return false;
    }
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) {
        return false;
    }
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }
    return true;
}

function ap_webmention_fetch_source(string $url): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Vaak-Webmention/1.0 (+https://mkultra.monster/)',
        CURLOPT_HTTPHEADER => ['Accept: text/html, application/xhtml+xml;q=0.9, */*;q=0.1'],
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body): int {
            $remaining = AP_WEBMENTION_MAX_SOURCE_BYTES - strlen($body);
            if ($remaining <= 0) {
                return 0;
            }
            $body .= substr($chunk, 0, $remaining);
            return strlen($chunk) <= $remaining ? strlen($chunk) : 0;
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    curl_close($ch);
    if ($ok === false || $status < 200 || $status >= 400 || ($type !== '' && !str_contains($type, 'html'))) {
        return null;
    }
    return $body !== '' ? $body : null;
}

function ap_webmention_contains_target(string $html, string $target): bool
{
    $haystack = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return str_contains($haystack, $target) || str_contains($haystack, htmlspecialchars($target, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function ap_webmention_extract_title(string $html): string
{
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        return trim(mb_substr(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 300));
    }
    return '';
}

function ap_webmention_extract_meta(string $html, array $names): string
{
    foreach ($names as $name) {
        $pattern = '/<meta[^>]+(?:name|property)=["\']' . preg_quote($name, '/') . '["\'][^>]+content=["\']([^"\']*)/i';
        if (preg_match($pattern, $html, $m)) {
            return trim(mb_substr(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 500));
        }
    }
    return '';
}

function ap_webmention_extract_content(string $html): string
{
    $body = preg_replace('/<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    return mb_substr($text, 0, 5000);
}
