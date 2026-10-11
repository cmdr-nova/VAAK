<?php
declare(strict_types=1);
// Independent combination corpus: imports real declarations and disposable
// transports from the canonical harness, never replaces a painter/predicate.
if (!extension_loaded('mbstring')) throw new RuntimeException('Run via api/bin/php-policy-test: real mbstring is required');
ob_start();
require __DIR__ . '/migration-card-reference-capture.php';
$baseline = json_decode((string)ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$base = $baseline['captures']['plain']['status'];
$cases = [];
$cases['favourites_plain'] = [$base, 'favourites', false];
$cases['escaped_identity'] = [array_replace($base, ['account'=>array_replace($base['account'], ['display_name'=>'Alice & <script>evil</script> "quoted"', 'acct'=>'alice&"@remote.test'])]), 'bookmarks', false];
$cases['string_counts'] = [array_replace($base, ['favourites_count'=>'1200','reblogs_count'=>'10001','quotes_count'=>'1000000','bookmarks_count'=>'7']), 'bookmarks', false];
$cases['string_sensitive'] = [array_replace($base, ['sensitive'=>'1']), 'bookmarks', false];
$cases['string_false_sensitive'] = [array_replace($base, ['sensitive'=>'0']), 'bookmarks', false];
$cases['missing_display'] = [array_replace($base, ['account'=>array_diff_key($base['account'], ['display_name'=>true])]), 'favourites', false];
$cases['empty_display'] = [array_replace($base, ['account'=>array_replace($base['account'], ['display_name'=>''])]), 'bookmarks', false];
$cases['missing_acct'] = [array_replace($base, ['account'=>array_diff_key($base['account'], ['acct'=>true])]), 'bookmarks', false];
$cases['missing_date'] = [array_diff_key($base, ['created_at'=>true]), 'bookmarks', false];
$cases['boost_missing_date_name'] = [array_replace($base, ['content'=>'','created_at'=>'','account'=>['uri'=>$base['account']['uri']], 'reblog'=>$base]), 'favourites', false];
$cases['cw_video_favourites'] = [array_replace($base, ['spoiler_text'=>'CW & "quoted" <markup>', 'sensitive'=>true, 'media_attachments'=>[['type'=>'video','url'=>'https://media.test/original/clip.mp4','preview_url'=>'https://media.test/poster.jpg']]]), 'favourites', true];
$cases['cw_audio_bookmarks'] = [array_replace($base, ['spoiler_text'=>'Audio warning', 'sensitive'=>true, 'media_attachments'=>[['type'=>'audio','url'=>'https://media.test/sound.mp3']]]), 'bookmarks', false];
$cases['cw_poll_favourites'] = [array_replace($base, ['uri'=>'https://remote.test/polls/open','spoiler_text'=>'Poll warning', 'sensitive'=>true]), 'favourites', true];
$cases['cw_quote_favourites'] = [array_replace($base, ['spoiler_text'=>'Outer warning', 'sensitive'=>true, 'quote'=>['state'=>'ready','quoted_status'=>array_replace($base, ['spoiler_text'=>'Inner warning','sensitive'=>true])]]), 'favourites', true];
$cases['numeric_id'] = [array_replace($base, ['id'=>123]), 'bookmarks', false];
$cases['string_followed_tag'] = [array_replace($base, ['vaak_from_followed_tag'=>'1']), 'bookmarks', false];
$cases['negative_counts'] = [array_replace($base, ['favourites_count'=>-3,'reblogs_count'=>-2,'quotes_count'=>-1]), 'bookmarks', false];
$cases['quoted_entities'] = [array_replace($base, ['content'=>'<p>&quot;quoted&quot; &apos;apostrophe&apos; &#x1F600; &NotEqualTilde; &amp; &lt;literal&gt;</p>']), 'bookmarks', false];
$cases['quote_empty_content'] = [array_replace($base, ['quote'=>['state'=>'ready','quoted_status'=>array_replace($base, ['content'=>''])]]), 'bookmarks', false];
$cases['empty_body_sensitive'] = [array_replace($base, ['content'=>'','sensitive'=>true]), 'bookmarks', false];
$cases['lazy_bookmark_image'] = [array_replace($base, ['media_attachments'=>[['type'=>'image','url'=>'https://media.test/full.png','preview_url'=>'https://media.test/thumb.jpg']]]), 'bookmarks', false];
$cases['lazy_bookmark_video'] = [array_replace($base, ['sensitive'=>true,'media_attachments'=>[['type'=>'video','url'=>'https://media.test/original/clip.mp4','preview_url'=>'https://media.test/poster.jpg']]]), 'bookmarks', false];
$out = [];
foreach ($cases as $name => [$candidate, $view, $auto]) {
    foreach (['fixture_mutes','fixture_blocks','fixture_words','fixture_server_blocks','fixture_status_cache','events','following','mentions','masto_favourites','masto_bookmarks','masto_reblogs','bsky_hide_dids'] as $table) ap_db()->exec('DELETE FROM '.$table);
    unset($GLOBALS['ap_masto_flag_memo'], $GLOBALS['ap_bsky_hide_did_cache']);
    ap_db()->exec('UPDATE actor_profile SET auto_unblur_sensitive='.(int)$auto);
    $GLOBALS['admin_bookmark_lazy_media']=str_starts_with($name,'lazy_bookmark_');
    $renderedAt=time();
    $GLOBALS['fixture_render_clock']=$renderedAt;
    ob_start(); admin_render_library_status_card($candidate, $view); $html = (string)ob_get_clean();
    unset($GLOBALS['fixture_render_clock']);
    if (!str_contains($html, '<article') || !str_contains($html, 'fixture-csrf-token')) throw new RuntimeException('actual card missing: '.$name);
    if ($name==='string_sensitive' && !str_contains($html, 'cw-gate')) throw new RuntimeException('PHP string sensitive baseline mismatch');
    if ($name==='string_false_sensitive' && str_contains($html, 'cw-gate')) throw new RuntimeException('PHP zero string baseline mismatch');
    if ($name==='cw_poll_favourites' && (!str_contains($html,'value="poll_vote"') || !str_contains($html,'name="return_view" value="favourites"'))) throw new RuntimeException('CW poll/action context mismatch');
    if ($name==='escaped_identity' && str_contains($html, '<script>evil</script>')) throw new RuntimeException('identity not escaped');
    $context=$baseline['captures']['plain']['context'];
    $context['return_view']=$view;
    $context['rendered_at']=$renderedAt;
    $context['lazy_media']=$GLOBALS['admin_bookmark_lazy_media'];
    $context['profile']=ap_profile_get('fixture');
    $context['ask_metadata']=$candidate['vaak_ask']??null;
    $context['resolved_author_avatar']=admin_actor_avatar_url((string)($candidate['account']['uri']??''));
    $out[$name]=['status'=>$candidate,'context'=>$context,'expected_admitted'=>true,'html'=>$html];
}
echo json_encode(['coverage'=>'Independent actual PHP card combinations; disposable SQLite/session/IO boundaries inherited from canonical harness. No controller writes, production DB or browser proof.', 'case_count'=>count($out),'captures'=>$out], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
