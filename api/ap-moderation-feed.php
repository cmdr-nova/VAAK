<?php
/** Public, machine-readable instance moderation feed. */
declare(strict_types=1);
require_once __DIR__ . '/ap-db.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    header('Allow: GET, OPTIONS');
    header('Access-Control-Allow-Origin: *');
    http_response_code(204);
    exit;
}
if ($method !== 'GET') {
    header('Allow: GET, OPTIONS');
    http_response_code(405);
    exit;
}

$actors = [];
$domains = [];
foreach (function_exists('ap_block_list') ? ap_block_list() : [] as $row) {
    if (!is_array($row) || (string) ($row['action'] ?? '') !== 'block') {
        continue;
    }
    $scope = (string) ($row['scope'] ?? '');
    $value = trim((string) ($row['value'] ?? ''));
    if ($value === '') {
        continue;
    }
    if ($scope === 'actor' && str_starts_with($value, 'https://')) {
        $actors[] = rtrim($value, '/');
    } elseif ($scope === 'domain') {
        $domains[] = strtolower(ltrim($value, '.'));
    }
}
$actors = array_values(array_unique($actors));
$domains = array_values(array_unique($domains));
sort($actors, SORT_STRING);
sort($domains, SORT_STRING);
$doc = [
    'version' => 1,
    'instance' => 'https://mkultra.monster',
    'policy' => [
        'scope' => 'global',
        'actions' => ['block'],
        'personal_mutes_excluded' => true,
        'report_details_excluded' => true,
    ],
    'blocked_actors' => $actors,
    'blocked_domains' => $domains,
];
$json = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$etag = '"' . sha1((string) $json) . '"';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');
header('ETag: ' . $etag);
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
echo $json;
