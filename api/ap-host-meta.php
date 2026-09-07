<?php
/**
 * Host-meta pointing lrdd at local WebFinger (cmdr_nova live actor + Bridgy fallback).
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$wantsJson = str_contains($uri, 'host-meta.json')
    || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

$template = 'https://mkultra.monster/.well-known/webfinger?resource={uri}';

if ($wantsJson) {
    header('Content-Type: application/jrd+json');
    echo json_encode([
        'links' => [[
            'rel' => 'lrdd',
            'type' => 'application/jrd+json',
            'template' => $template,
        ]],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: application/xrd+xml');
echo <<<XRD
<?xml version="1.0" encoding="UTF-8"?>
<XRD xmlns="http://docs.oasis-open.org/ns/xri/xrd-1.0">
  <Link rel="lrdd" type="application/jrd+json" template="{$template}"/>
</XRD>
XRD;
