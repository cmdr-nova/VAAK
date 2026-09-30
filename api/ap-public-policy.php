<?php
/** Public VAAK privacy / conduct document renderer. */
declare(strict_types=1);

require_once __DIR__ . '/ap-instance-docs.php';

$docKey = ((string) ($_GET['doc'] ?? 'privacy')) === 'conduct' ? 'conduct' : 'privacy';
$doc = ap_instance_doc_get($docKey);
$rules = $docKey === 'conduct' ? ap_instance_rules_list() : [];
$title = (string) ($doc['title'] ?? ($docKey === 'conduct' ? 'Code of Conduct' : 'Privacy Policy'));
$body = trim((string) ($doc['body'] ?? ''));
$esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$bodyHtml = '';
foreach (preg_split('/\R/u', $body) ?: [] as $line) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    if (str_starts_with($line, '- ')) {
        $bodyHtml .= '<li>' . $esc(substr($line, 2)) . '</li>';
    } else {
        $bodyHtml .= '<p>' . nl2br($esc($line), false) . '</p>';
    }
}
if ($rules !== []) {
    $bodyHtml .= '<h2>Server rules</h2><ol>';
    foreach ($rules as $rule) {
        $bodyHtml .= '<li>' . $esc((string) ($rule['text'] ?? '')) . '</li>';
    }
    $bodyHtml .= '</ol>';
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="index,follow"><title><?= $esc($title) ?> · VAAK</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:#090909;color:#e8e8e8;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}main{width:min(46rem,100% - 2rem);margin:2.5rem auto 4rem}header{display:flex;align-items:center;gap:1rem;margin-bottom:2rem}header a{color:#00ff9f;text-decoration:none;font-weight:700;letter-spacing:.15em}header span{color:#777}article{padding:1.5rem;border:1px solid #292929;border-radius:14px;background:#111}h1{margin:0 0 .35rem;font-size:clamp(1.5rem,4vw,2.25rem)}h2{margin:1.8rem 0 .65rem;font-size:1.1rem;color:#9dffd0}p,li{color:#c8c8c8;line-height:1.65}ul,ol{padding-left:1.4rem}a{color:#7ee0ff}footer{margin-top:1.25rem;color:#777;font-size:.82rem}footer a{color:#aaa}
</style></head><body><main>
<header><a href="/vaak/">VAAK</a><span>·</span><span>Instance information</span></header>
<article><h1><?= $esc($title) ?></h1><?= $bodyHtml !== '' ? $bodyHtml : '<p>This document is currently empty.</p>' ?></article>
<footer><a href="/vaak/">Back to VAAK</a> · <a href="/vaak/about/">About VAAK</a></footer>
</main></body></html>
