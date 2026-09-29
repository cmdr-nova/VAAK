<?php
/** Refresh the trusted Wafrn friend-server catalog from the official directory. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$targetDir = '/var/lib/mkultra/ap';
$target = $targetDir . '/wafrn-friend-hosts.txt';
if (!is_dir($targetDir)) mkdir($targetDir, 0750, true);
$ch = curl_init('https://join.wafrn.net/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'VAAK-Wafrn-Friend-Refresh/1.0']);
$html = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);
if (!is_string($html) || $code < 200 || $code >= 300) {
    fwrite(STDERR, "Wafrn directory fetch failed ({$code}): {$error}\n");
    exit(1);
}
$hosts = [];
if (preg_match_all('~https://([^/"\'<>[:space:]]+)/about(?:["\'<>[:space:]]|$)~i', $html, $matches)) {
    foreach ($matches[1] as $host) {
        $host = strtolower(trim((string) $host));
        if ($host === '' || $host === 'app.wafrn.net' || $host === 'join.wafrn.net' || $host === 'mkultra.monster') continue;
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) $hosts[$host] = true;
    }
}
if ($hosts === []) {
    fwrite(STDERR, "Wafrn directory returned no valid instances; retaining prior catalog\n");
    exit(1);
}
$tmp = $target . '.tmp';
file_put_contents($tmp, implode("\n", array_keys($hosts)) . "\n", LOCK_EX);
rename($tmp, $target);
fwrite(STDOUT, sprintf("wafrn-friends=%d\n", count($hosts)));
