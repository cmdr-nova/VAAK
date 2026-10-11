<?php
declare(strict_types=1);
// Pure production helper replay: no database, network, or replacement helpers.
require dirname(__DIR__) . '/ap-bsky-urls.php';
function raw_load_function(string $path, string $name): void {
    $tokens = token_get_all((string) file_get_contents($path));
    for ($i = 0; $i < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $j = $i + 1;
        while ($j < count($tokens) && (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING)) $j++;
        if (($tokens[$j][1] ?? '') !== $name) continue;
        $code = ''; $depth = 0; $started = false;
        for (; $i < count($tokens); $i++) {
            $token = $tokens[$i]; $text = is_array($token) ? $token[1] : $token;
            $code .= $text;
            if ($text === '{') { $depth++; $started = true; }
            if ($text === '}' && --$depth === 0 && $started) { eval($code); return; }
        }
    }
    throw new RuntimeException('Production helper missing: ' . $name);
}
raw_load_function(dirname(__DIR__) . '/ap-db.php', 'ap_normalize_visibility');
raw_load_function(dirname(__DIR__) . '/ap-admin.php', 'admin_visibility_meta');
$bsky = [];
foreach (['', 'https://other.test/note', 'at://did:plc:alice/app.bsky.feed.post/abc', 'at://alice.test/app.bsky.feed.post/a b', 'at://did%3Aplc%3Aalice/app.bsky.feed.post/abc', 'at://@alice.test/profile', 'https://bsky.app/profile/did%3Aplc%3Aalice/post/a%20b?x=1', 'nonsense'] as $uri) {
    $bsky[] = ['input' => $uri, 'output' => ap_bsky_https_url_from_at_uri($uri, null)];
}
$visibility = [];
foreach (['public', '', 'local', 'LOCAL_ONLY', 'instance', 'instance_only', 'unlisted', 'silent_public', 'private', 'followers_only', 'followers', 'direct', 'unknown'] as $value) {
    $visibility[] = ['input' => $value, 'output' => admin_visibility_meta($value)];
}
echo json_encode(['bsky' => $bsky, 'visibility' => $visibility], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
