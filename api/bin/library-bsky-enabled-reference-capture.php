<?php
declare(strict_types=1);
/** Canonical PHP painter with the Bluesky connection enabled. Source fixtures
 * still use actual helper declarations; this only supplies the session boundary
 * switch that the disabled-connection corpus intentionally leaves unavailable.
 */
function ap_bsky_tab_enabled(): bool { return true; }
function ap_bsky_session_row(int $owner): ?array { return $GLOBALS['fixture_bsky_session'] ?? null; }
ob_start();
require __DIR__ . '/migration-card-reference-capture.php';
$reference = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
reference_load(dirname(__DIR__) . '/ap-bsky.php', [
    'ap_bsky_post_video_media', 'ap_bsky_embed_video_view',
    'ap_bsky_external_link_card_html',
    'ap_bsky_reply_parent_preview','ap_bsky_reply_parent_preview_enrich',
    'ap_bsky_quote_preview','ap_bsky_media_items_from_embeds',
]);
reference_load(dirname(__DIR__) . '/ap-db.php', ['ap_masto_status_is_pinned']);
ap_db()->exec('CREATE TABLE masto_pins(owner_user_id INTEGER,status_local_id INTEGER)');
$base = $reference['captures']['bsky_fallback'];
$images = ['$type'=>'app.bsky.embed.images#view', 'images'=>[
    ['thumb'=>'https://bsky.test/thumb.png','fullsize'=>'https://bsky.test/full.png','alt'=>'Image description'],
]];
$variants = [
    'legacy_images'=>['embed'=>$images],
    'legacy_video'=>['embed'=>['$type'=>'app.bsky.embed.video#view','playlist'=>'https://bsky.test/video.m3u8','thumbnail'=>'https://bsky.test/thumbnail.png']],
    'legacy_external'=>['embed'=>['$type'=>'app.bsky.embed.external#view','external'=>['uri'=>'https://article.test/story','title'=>'External story','description'=>'External excerpt','thumb'=>'https://article.test/image.png']]],
    'legacy_sensitive_images'=>['embed'=>$images,'labels'=>[['val'=>'porn']]],
    'legacy_sensitive_auto_unblur'=>['embed'=>$images,'labels'=>[['val'=>'porn']]],
    'legacy_facets'=>['record'=>['text'=>'Read example.test/path...','createdAt'=>$base['status']['post']['record']['createdAt'],'facets'=>[['index'=>['byteStart'=>5,'byteEnd'=>25],'features'=>[['$type'=>'app.bsky.richtext.facet#link','uri'=>'https://example.test/path/full']]]]]],
    'legacy_repost'=>[],
    'legacy_self_repost'=>[],
    'legacy_reply'=>[],
    'legacy_raw_image_blob'=>['record'=>array_replace($base['status']['post']['record'],['embed'=>['$type'=>'app.bsky.embed.images','images'=>[['alt'=>'Raw blob','image'=>['ref'=>['$link'=>'blob-cid'],'mimeType'=>'image/png']]]]])],
    'legacy_raw_external'=>['record'=>array_replace($base['status']['post']['record'],['embed'=>['$type'=>'app.bsky.embed.external','external'=>['uri'=>'https://ignored.test/','title'=>'Raw only']]])],
    'legacy_raw_reply_resolved'=>['record'=>array_replace($base['status']['post']['record'],['reply'=>['parent'=>['uri'=>'at://did:plc:parent/app.bsky.feed.post/parent-key','cid'=>'parent-cid'],'root'=>['uri'=>'at://did:plc:root/app.bsky.feed.post/root-key','cid'=>'root-cid']]])],
    'legacy_own'=>['author'=>['did'=>'did:plc:fixture','handle'=>'alice.bsky.test','displayName'=>'BSky Alice','avatar'=>'https://bsky.test/avatar.png'],'record'=>['text'=>'','createdAt'=>$base['status']['post']['record']['createdAt']]],
    'legacy_own_mapped'=>['author'=>['did'=>'did:plc:fixture','handle'=>'alice.bsky.test','displayName'=>'BSky Alice','avatar'=>'https://bsky.test/avatar.png'],'record'=>['text'=>'','createdAt'=>$base['status']['post']['record']['createdAt'],'fediverseId'=>'https://mkultra.monster/users/fixture/notes/456']],
    'legacy_quote'=>['embed'=>['$type'=>'app.bsky.embed.record#view','record'=>['uri'=>'at://did:plc:quoted/app.bsky.feed.post/quoted-key','author'=>['did'=>'did:plc:quoted','handle'=>'quoted.bsky.test','displayName'=>'Quoted author'],'value'=>['$type'=>'app.bsky.feed.post','text'=>'Quoted message']]]],
    'legacy_quote_media'=>['embed'=>['$type'=>'app.bsky.embed.record#view','record'=>['uri'=>'at://did:plc:quoted/app.bsky.feed.post/quoted-key','author'=>['did'=>'did:plc:quoted','handle'=>'quoted.bsky.test','displayName'=>'Quoted author'],'value'=>['$type'=>'app.bsky.feed.post','text'=>''],'embeds'=>[$images]]]],
    'legacy_quote_blocked'=>['embed'=>['$type'=>'app.bsky.embed.record#view','record'=>['$type'=>'app.bsky.embed.record#viewBlocked','uri'=>'at://did:plc:quoted/app.bsky.feed.post/blocked']]],
    'legacy_starterpack'=>['embed'=>['$type'=>'app.bsky.embed.record#view','record'=>['$type'=>'app.bsky.graph.defs#starterPackViewBasic','uri'=>'at://did:plc:pack/app.bsky.graph.starterpack/key','name'=>'Starter pack']]],
];
foreach(['nbsp'=>"\u{00A0}",'thin'=>"\u{2009}",'ascii'=>' '] as $kind=>$space){
    $text=$space.'Visible text'.$space;
    $variants['legacy_unicode_text_'.$kind]=['record'=>array_replace($base['status']['post']['record'],['text'=>$text])];
    $variants['legacy_unicode_display_'.$kind]=['author'=>array_replace($base['status']['post']['author'],['displayName'=>$space.'Visible name'.$space])];
    $variants['legacy_unicode_quote_'.$kind]=['embed'=>['$type'=>'app.bsky.embed.record#view','record'=>['uri'=>'at://did:plc:quoted/app.bsky.feed.post/quoted-key','author'=>['did'=>'did:plc:quoted','displayName'=>$space.'Quoted name'.$space],'value'=>['$type'=>'app.bsky.feed.post','text'=>$text]]]];
    $variants['legacy_unicode_external_'.$kind]=['embed'=>['$type'=>'app.bsky.embed.external#view','external'=>['uri'=>'https://article.test/read','title'=>$space.'Article title'.$space,'description'=>$text]]];
    $variants['legacy_unicode_repost_'.$kind]=[];
    $variants['legacy_unicode_reply_'.$kind]=[];
}
$variants['legacy_own_mapped_pinned']=$variants['legacy_own_mapped'];
$variants['legacy_own_mapped_direct']=$variants['legacy_own_mapped'];
$variants['legacy_own_mapped_foreign']=$variants['legacy_own_mapped'];
$variants['legacy_own_mapped_foreign']['record']['fediverseId']='https://mkultra.monster/users/other/notes/9';
$variants['legacy_own_mapped_deleted']=$variants['legacy_own_mapped'];
$variants['legacy_own_mapped_deleted']['record']['fediverseId']='https://mkultra.monster/users/fixture/notes/deleted';
foreach(ap_bsky_sensitive_label_vals() as $label) {
    $variants['legacy_label_'.$label]=['embed'=>$images,'labels'=>[strtoupper($label)]];
    $variants['legacy_selflabel_'.$label]=['embed'=>$images,'record'=>array_replace($base['status']['post']['record'],['selfLabels'=>['values'=>[['val'=>' '.strtoupper($label).' ']]]])];
}
foreach ($variants as $name=>$replace) {
    $item=$base['status'];
    $item['post']=array_replace($item['post'],$replace);
    $GLOBALS['fixture_bsky_session']=in_array($name,['legacy_self_repost','legacy_own','legacy_own_mapped','legacy_own_mapped_pinned','legacy_own_mapped_direct','legacy_own_mapped_foreign','legacy_own_mapped_deleted'],true)?['did'=>'did:plc:fixture']:null;
    if(in_array($name,['legacy_repost','legacy_self_repost'],true)) $item['reason']=['$type'=>'app.bsky.feed.defs#reasonRepost','by'=>['did'=>$name==='legacy_self_repost'?'did:plc:fixture':'did:plc:booster','displayName'=>'Booster','handle'=>'booster.bsky.test'],'indexedAt'=>$base['status']['post']['record']['createdAt']];
    if(in_array($name,['legacy_reply','legacy_raw_reply_resolved'],true)) $item['reply']=['parent'=>['uri'=>'at://did:plc:parent/app.bsky.feed.post/parent-key','author'=>['did'=>'did:plc:parent','handle'=>'parent.bsky.test','displayName'=>'Parent'],'record'=>['text'=>'Parent message','createdAt'=>$base['status']['post']['record']['createdAt']]]];
    if(str_starts_with($name,'legacy_unicode_repost_')){$kind=substr($name,strlen('legacy_unicode_repost_'));$space=['nbsp'=>"\u{00A0}",'thin'=>"\u{2009}",'ascii'=>' '][$kind];$item['reason']=['$type'=>'app.bsky.feed.defs#reasonRepost','by'=>['did'=>'did:plc:booster','displayName'=>$space.'Booster name'.$space,'handle'=>'booster.bsky.test'],'indexedAt'=>$base['status']['post']['record']['createdAt']];}
    if(str_starts_with($name,'legacy_unicode_reply_')){$kind=substr($name,strlen('legacy_unicode_reply_'));$space=['nbsp'=>"\u{00A0}",'thin'=>"\u{2009}",'ascii'=>' '][$kind];$item['reply']=['parent'=>['uri'=>'at://did:plc:parent/app.bsky.feed.post/parent-key','author'=>['did'=>'did:plc:parent','handle'=>'parent.bsky.test','displayName'=>$space.'Parent name'.$space],'record'=>['text'=>$space.'Parent message'.$space,'createdAt'=>$base['status']['post']['record']['createdAt']]]];}
    ap_db()->exec('UPDATE actor_profile SET auto_unblur_sensitive='.(int)($name==='legacy_sensitive_auto_unblur'));
    ap_db()->exec('DELETE FROM masto_pins');
    if($name==='legacy_own_mapped_pinned') ap_db()->exec('INSERT INTO masto_pins VALUES(1,456)');
    ap_db()->exec("UPDATE masto_statuses SET visibility='".($name==='legacy_own_mapped_direct'?'direct':'public')."' WHERE local_id=456");
    $context=$base['context'];
    // Freeze relative_time() at the clock handed to the native renderer.
    $context['rendered_at']=time();$GLOBALS['fixture_render_clock']=$context['rendered_at'];
    $context['profile']=ap_profile_get('fixture');
    $context['bsky_session']=$GLOBALS['fixture_bsky_session'];
    if(in_array($name,['legacy_own_mapped','legacy_own_mapped_pinned','legacy_own_mapped_direct'],true)) { $context['mapped_own']=ap_db()->query("SELECT * FROM masto_statuses WHERE local_id=456")->fetch(); $context['mapped_own']['pinned']=$name==='legacy_own_mapped_pinned'; }
    ob_start();
    admin_render_bsky_feed_item($item,'following','bookmarks');
    $html=(string)ob_get_clean();
    if(!str_contains($html,'data-bsky-action="bookmark"')) throw new RuntimeException('legacy variant has no actions: '.$name);
    $reference['captures'][$name]=['status'=>$item,'context'=>$context,'expected_admitted'=>true,'html'=>$html];
    if(in_array($name,['legacy_own_mapped_foreign','legacy_own_mapped_deleted'],true)) {
        if(str_contains($html,'value="delete_status"') || str_contains($html,'value="pin_status"')) throw new RuntimeException('stale/foreign AP action authorized');
        if($name==='legacy_own_mapped_foreign' && str_contains($html,'js-edit-post')) throw new RuntimeException('foreign AP edit authorized');
        $reference['captures'][$name]['native_fallback_required']=true;
    }
}
foreach ($reference['captures'] as &$capture) {
    if (isset($capture['status']['post'])) {
        $capture['normalized_status'] = ap_normalize_from_bsky_post($capture['status']);
    }
}
unset($capture);
$reference['boundary'] = 'Connected Bluesky session enabled; fixture transport, actual PHP painter/helpers';
$reference['promotion_allowed'] = false;
echo json_encode($reference, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
