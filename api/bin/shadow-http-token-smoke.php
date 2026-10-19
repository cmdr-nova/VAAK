<?php
/**
 * Shadow HTTP token: resolution matches rust/vaak-worker/src/shadow_auth.rs,
 * the env file wins over the process environment, and every PHP caller of
 * /shadow/* or the Axum /api/v1/* reads sends the header. No network.
 */
declare(strict_types=1);

$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void {
    if (!$ok) {
        fwrite(STDERR, "shadow-http-token: {$why}\n");
        exit(1);
    }
    $n++;
};

$tmp = sys_get_temp_dir() . '/vaak-shadow-token-' . bin2hex(random_bytes(4)) . '.env';
file_put_contents($tmp, "# test\nVAAK_SECRET=\"test-secret\"\nOTHER=1\n");
putenv('VAAK_ENV_FILE=' . $tmp);
putenv('VAAK_SHADOW_HTTP_TOKEN=' . str_repeat('e', 40));
putenv('VAAK_SECRET');

require_once dirname(__DIR__) . '/ap-shadow-http.php';

// Same vector as shadow_auth::tests::derived_token_matches_php.
$check(ap_shadow_http_token_from('', 'test-secret') === '2727b4d62885db40784c45aef7619d611d602d58f1f9b00ba50129dcaad8b2fd', 'derived token matches Rust');
$check(ap_shadow_http_token_from(str_repeat('x', 32), 'test-secret') === str_repeat('x', 32), 'explicit token wins');
$check(ap_shadow_http_token_from('short', 'test-secret') === '', 'short explicit token is refused, no fallback');
$check(ap_shadow_http_token_from('', '') === '', 'unconfigured gives no token');

// Env file has only VAAK_SECRET; the process env supplies the explicit token.
$check(ap_shadow_http_token() === str_repeat('e', 40), 'process env explicit token used when file has none');
$check(ap_shadow_http_auth_headers() === ['X-VAAK-Shadow-Token: ' . str_repeat('e', 40)], 'curl header line');
$check(ap_shadow_http_auth_header_text() === 'X-VAAK-Shadow-Token: ' . str_repeat('e', 40) . "\r\n", 'stream header text');
@unlink($tmp);

// Every PHP fetch of a gated Axum route must attach the header.
$api = dirname(__DIR__);
foreach (['ap-admin.php', 'ap-masto-entities.php', 'ap-mastodon.php'] as $file) {
    $src = (string) file_get_contents($api . '/' . $file);
    $check(str_contains($src, "require_once __DIR__ . '/ap-shadow-http.php';"), "{$file} loads the helper");
    $offset = 0;
    while (preg_match("#'/(?:shadow/[a-z-]*|api/v1/(?:timelines|notifications))#", $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $pos = (int) $m[0][1];
        $offset = $pos + 1;
        // Skip doc comments and route-name maps; only request builders matter.
        $lineStart = strrpos(substr($src, 0, $pos), "\n");
        $line = substr($src, $lineStart === false ? 0 : $lineStart + 1, 200);
        if (!preg_match('/\$base\s*\./', $line)) {
            continue;
        }
        $fnEnd = strpos($src, "\n}\n", $pos);
        $body = substr($src, $pos, ($fnEnd === false ? strlen($src) : $fnEnd) - $pos);
        $line = substr_count(substr($src, 0, $pos), "\n") + 1;
        $headers = substr_count($body, 'CURLOPT_HTTPHEADER') + substr_count($body, "'header' =>");
        $authed = substr_count($body, 'ap_shadow_http_auth_header');
        $check($headers > 0 && $authed === $headers, "{$file}:{$line} sends the shadow token on every request");
    }
}

// The tl-html helper builds its path from a map; check that function too.
$admin = (string) file_get_contents($api . '/ap-admin.php');
$mapPos = strpos($admin, "'local' => '/shadow/local-html'");
$check($mapPos !== false, 'tl-html route map present');
$mapBody = substr($admin, (int) $mapPos, (int) strpos($admin, "\n}\n", (int) $mapPos) - (int) $mapPos);
$check(str_contains($mapBody, 'ap_shadow_http_auth_headers()'), 'tl-html fetch sends the shadow token');

echo "shadow-http-token: {$n} checks passed\n";
