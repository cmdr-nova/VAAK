<?php
declare(strict_types=1);
// Actual pure label/sanitizer helpers, with the row lookup and warm boundary
// explicitly recorded. This component oracle does not prove PG/Redis IO.
function identity_load(string $name): void {
    $tokens = token_get_all((string) file_get_contents(dirname(__DIR__) . '/ap-db.php'));
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
function ap_remote_actor_ensure(string $actor, bool $fetch): ?array { return $GLOBALS['identity_row']; }
function ap_remote_actor_warm_async(string $actor): void { $GLOBALS['identity_warms'][] = $actor; }
foreach (['ap_remote_actor_normalize_username','ap_remote_actor_username_is_placeholder','ap_remote_actor_label',
          'ap_remote_actor_flat_redis_key','ap_remote_actor_row_from_flat','ap_profile_sanitize_https_url','ap_local_avatar_url'] as $function) identity_load($function);
$captures = [];
$actors = ['https://remote.test/users/asker','https://remote.test/@alice','https://remote.test/ap/users/123456',
           'https://remote.test/ap/did%3Aplc%3Ax','https://remote.test/users/user','https://bsky.brid.gy/ap/did:plc:abc',
           'https://remote.test/users/../last?x=1','https://[2001:db8::1]:443/users/name','https://remote.test/users/0'];
$rows = [null, ['username'=>'user','display_name'=>''], ['username'=>'@alice@elsewhere.test','display_name'=>'  Alice  ','host'=>'REMOTE.TEST'],
         ['username'=>'123456','display_name'=>'Visible title'], ['username'=>'alice.bsky.social','display_name'=>'0','host'=>'bsky.brid.gy']];
foreach ($actors as $actor) foreach ($rows as $row) {
    $GLOBALS['identity_row'] = $row; $GLOBALS['identity_warms'] = [];
    $captures[] = ['actor'=>$actor,'row'=>$row,'label'=>ap_remote_actor_label($actor,false),'warms'=>$GLOBALS['identity_warms'],
                   'flat_key'=>ap_remote_actor_flat_redis_key($actor)];
}
$avatars = [];
foreach (['',' https://media.test/icon.webp ','HTTPS://media.test/icon','https://localhost/x','https://127.0.0.1/x',
          'https://10.1.2.3/x','https://192.0.2.1/x','https://[::1]/x','https://[fd00::1]/x','https://u@remote.test/x',
          'https://remote.test:65536/x','https://remote.test/x'."\n",'https://remote.test:443/x','https://remote.test:/x',
          'https://remote.test:+80/x','https://remote.test:80x/x','https://remote.test:-0/x','https://remote.test:-1/x',
          'https://remote.test:abc/x','https://remote.test:123456/x'] as $icon) {
    $avatars[] = ['input'=>$icon,'output'=>ap_local_avatar_url($icon)];
}
echo json_encode(['captures'=>$captures,'avatars'=>$avatars], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
