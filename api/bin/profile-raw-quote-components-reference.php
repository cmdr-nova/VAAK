<?php
declare(strict_types=1);
// Presentation-only helper capture using the established component bootstrap.
// Its offline/cache substitutions are declared; this is no source-grant or IO
// parity oracle. Every expected HTML string is the actual production helper.
ob_start(); require __DIR__ . '/migration-library-component-capture.php'; ob_end_clean();
$cases = [
    'stub' => ['url' => 'https://remote.test/notes/stub', 'open_external' => true, 'open_label' => 'Open original'],
    'unavailable' => ['text' => 'Quoted post unavailable', 'url' => 'https://remote.test/notes/gone', 'open_external' => true, 'open_label' => 'Open original'],
    'empty' => [],
    'bsky_root' => ['url' => 'https://bsky.app/'],
    'text' => ['acct' => 'alice@remote.test', 'text' => "Complete quoted body Ω\nsecond line", 'url' => 'https://remote.test/notes/full'],
    'image_cw' => ['text' => 'Visible quoted text', 'url' => 'https://remote.test/notes/media', 'media' => [['url' => 'https://media.test/q.png', 'mediaType' => 'image/png']], 'sensitive' => true, 'spoiler_text' => 'Quoted warning'],
    'rss' => ['is_rss' => true, 'acct' => 'A feed Ω', 'text' => 'Article summary', 'url' => 'https://mkultra.monster/users/fixture/notes/rss', 'article_url' => 'https://articles.test/a', 'open_external' => true, 'open_label' => 'Open article'],
    'card' => ['url' => 'https://remote.test/notes/article', 'card' => ['url' => 'https://articles.test/a', 'title' => 'Article title', 'description' => 'Article description', 'image' => 'https://media.test/card.jpg']],
];
$rows=[];
foreach ($cases as $name=>$opts) {
    $GLOBALS['admin_quote_link_preview_budget']=0;
    $rows[]=['case'=>$name,'opts'=>$opts,'html'=>admin_quote_card_html($opts,'remote_profile')];
}
echo json_encode(['captures'=>$rows,'scope'=>'Actual pure quote presentation helper under existing declared offline component bootstrap; no native source admission/IO/global budget proof'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
