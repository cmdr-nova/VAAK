<?php
/** Minimal, safe oEmbed provider for public Vaak profile cards. */
declare(strict_types=1);
require_once __DIR__ . '/ap-db.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    header('Allow: GET, OPTIONS');
    http_response_code(204);
    exit;
}
if ($method !== 'GET') {
    header('Allow: GET, OPTIONS');
    http_response_code(405);
    exit;
}

$rawUrl = trim((string) ($_GET['url'] ?? ''));
$parts = parse_url($rawUrl);
$host = strtolower((string) ($parts['host'] ?? ''));
$path = (string) ($parts['path'] ?? '');
if ($host !== 'mkultra.monster' || !preg_match('#^/users/([a-z][a-z0-9_]{0,29})/?$#i', $path, $m)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Only public Vaak profile URLs can be embedded'], JSON_UNESCAPED_SLASHES);
    exit;
}

$actorKey = strtolower($m[1]);
$profile = ap_profile_get($actorKey);
if (!is_array($profile) || empty($profile['discoverable']) || (isset($profile['indexable']) && !$profile['indexable'])) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Profile unavailable'], JSON_UNESCAPED_SLASHES);
    exit;
}

$canonical = 'https://mkultra.monster/users/' . rawurlencode($actorKey);
$name = trim((string) ($profile['name'] ?? '')) ?: $actorKey;
$summary = trim(strip_tags((string) ($profile['summary'] ?? '')));
$nameHtml = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$acctHtml = htmlspecialchars('@' . $actorKey . '@mkultra.monster', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$summaryHtml = htmlspecialchars(mb_substr($summary, 0, 280), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$urlHtml = htmlspecialchars($canonical, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$html = '<article class="vaak-oembed-card" style="font:16px system-ui,sans-serif;max-width:550px;padding:16px;border:1px solid #444;border-radius:10px;background:#151515;color:#eee">'
    . '<strong style="font-size:1.15em">' . $nameHtml . '</strong>'
    . '<div style="color:#aaa;margin:.25rem 0 .6rem">' . $acctHtml . '</div>'
    . ($summaryHtml !== '' ? '<p style="margin:.4rem 0  .8rem;white-space:pre-wrap">' . $summaryHtml . '</p>' : '')
    . '<a href="' . $urlHtml . '" rel="noopener noreferrer" style="color:#62e88a">View profile on Vaak</a>'
    . '</article>';

$format = strtolower(trim((string) ($_GET['format'] ?? 'json')));
if ($format !== 'json') {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $html;
    exit;
}
$width = max(200, min(1200, (int) ($_GET['maxwidth'] ?? 550)));
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo json_encode([
    'version' => '1.0',
    'type' => 'rich',
    'provider_name' => 'Vaak',
    'provider_url' => 'https://mkultra.monster',
    'title' => $name . ' — Vaak',
    'author_name' => $name,
    'author_url' => $canonical,
    'url' => $canonical,
    'width' => $width,
    'height' => 180,
    'html' => $html,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
