<?php
declare(strict_types=1);
/** Actual declaration-only shared painters and mapping helpers; fixture auth/transport, no SAPI. */
ob_start();require __DIR__.'/migration-card-reference-capture.php';$data=json_decode((string)ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
$actor='https://remote.test/users/alice';$object='https://remote.test/objects/ap-object';
$cases=[
 'no_hint'=>null,
 'mapped_bluesky'=>'https://bsky.app/profile/alice.test/post/abc',
 'mapped_threads'=>'https://www.threads.net/@alice/post/ABC',
 'array_bluesky'=>[['type'=>'Link','href'=>'https://bsky.app/profile/alice.test/post/abc']],
 'array_threads'=>['https://www.threads.net/@alice/post/ABC'],
 'ordinary_html'=>'https://remote.test/@alice/123?x=1&y=2',
 'invalid_hint'=>7,
 'http_hint'=>'http://remote.test/@alice/123',
];
$rows=[];
foreach($cases as $name=>$hint){
 $st=$data['captures']['plain']['status'];$st['uri']=$object;$st['url']=$hint;
 ob_start();admin_render_masto_status_card($st,[],'bookmarks');$html=(string)ob_get_clean();
 $expected=admin_remote_object_href($object,$hint);
 $rows[$name]=['object'=>$object,'hint'=>$hint,'expected_remote'=>$expected,'expected_open'=>admin_status_href($object,'bookmarks'),'html'=>$html];
}
// This is the genuine PostView -> shared-normalizer AT status from the existing corpus.
$st=$data['captures']['bsky_normalized']['normalized_status'];
foreach([true,false] as $showOpen){
 ob_start();admin_render_masto_status_card($st,[],'home',false,$showOpen);$html=(string)ob_get_clean();
 $rows[$showOpen?'native_at_open':'native_at_remote_only']=['object'=>$st['uri'],'hint'=>$st['url'],'expected_remote'=>admin_remote_object_href($st['uri'],$st['url']),'expected_open'=>$showOpen?admin_status_href($st['uri'],'home'):null,'html'=>$html];
}
$old=block_quick_actions($actor,'remote.test','home',1,false,'home',$object);
$explicitNull=block_quick_actions($actor,'remote.test','home',1,false,'home',$object,null);
$mapped=block_quick_actions($actor,'remote.test','home',1,false,'home',$object,$cases['mapped_bluesky']);
$originalBsky='https://bsky.app/profile/alice.test/post/abc';
$labels=block_quick_actions($actor,'remote.test','home',1,false,'home',$originalBsky,'https://other.test/html');
echo json_encode(['cases'=>$rows,'optional_hint_default_identical'=>$old===$explicitNull,'mapped_menu'=>$mapped,'original_bsky_label_menu'=>$labels,'original_bsky_expected_remote'=>admin_remote_object_href($originalBsky,'https://other.test/html'),'boundaries'=>['actual PHP shared painter and URL mapping declarations','genuine normalized AT status input from PostView corpus','declared fixture owner/auth/SQLite/transport','no SAPI or native HTTP execution']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
