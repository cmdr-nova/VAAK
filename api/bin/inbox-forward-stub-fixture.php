<?php
// php -S router for inbox-rust-accept-smoke.php: stands in for the PHP inbox
// behind the Rust worker and echoes what it was forwarded.
declare(strict_types=1);
$body = file_get_contents('php://input');
http_response_code(418);
header('Content-Type: application/json');
header('X-Inbox-Stub: 1');
echo json_encode([
    'forwarded' => true,
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'host' => $_SERVER['HTTP_HOST'] ?? '',
    'xff' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
    'signature' => $_SERVER['HTTP_SIGNATURE'] ?? '',
    'body_sha256' => hash('sha256', is_string($body) ? $body : ''),
], JSON_UNESCAPED_SLASHES);
