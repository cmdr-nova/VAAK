<?php
declare(strict_types=1);
/** Real shared PHP card regression; declaration fixture auth/SQLite/transports, no live SAPI. */
ob_start();require __DIR__.'/migration-card-reference-capture.php';$corpus=json_decode((string)ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
$checks=0;
function menu_card_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
$raw=$corpus['captures']['bsky_normalized']['normalized_status']['bsky_post'];
$post=is_array($raw['post']??null)?$raw['post']:$raw;
$post['uri']='at://did:plc:fixture/app.bsky.feed.post/3mxos2swsus2s';
$post['author']['handle']='dame.is';
$baseline=ap_normalize_from_bsky_post(['post'=>$post]);
menu_card_assert(is_array($baseline),'actual PostView normalizer accepts complete fixture');
// Direct presentation requires durable source existence, bound to the same
// PostView used by both its initial AT and hydrated HTTPS presentation.
ap_db()->exec('CREATE TABLE IF NOT EXISTS bsky_posts(bsky_uri TEXT PRIMARY KEY)');
ap_db()->prepare('INSERT OR IGNORE INTO bsky_posts(bsky_uri) VALUES(?)')->execute([$post['uri']]);

foreach([$post['uri'],$baseline['url']] as $uri){
 foreach(['home','status'] as $view)foreach([true,false] as $open){
  $st=$baseline;$st['uri']=$uri;
  ob_start();admin_render_masto_status_card($st,[],$view,false,$open);$html=(string)ob_get_clean();
  menu_card_assert(substr_count($html,'<details class="post-action-menu">')===1,"one overflow for $uri/$view/open=".(int)$open);
  menu_card_assert(str_contains($html,'name="action" value="mute_remote"'),'mute retained');
  menu_card_assert(str_contains($html,'name="action" value="user_block_add"'),'block retained');
  menu_card_assert(str_contains($html,'name="csrf" value="fixture-csrf-token"'),'moderation CSRF retained');
  menu_card_assert(str_contains($html,'name="action" value="bookmark_status"')||str_contains($html,'name="action" value="bsky_bookmark"'),'bookmark retained');
  menu_card_assert(str_contains($html,'href="'.h(admin_remote_object_href($uri,$st['url'])).'"'),'remote target retained');
  menu_card_assert(str_contains($html,'>Open</a>')===($open||str_starts_with($uri,'https://')),'original open visibility retained');
 }
}
$st=$baseline;$st['uri']='at://did:plc:fixture/app.bsky.feed.post/3mxos2swsus2s';
$GLOBALS['vaak_guest_profile']=true;ob_start();admin_render_masto_status_card($st,[],'home');$guest=(string)ob_get_clean();unset($GLOBALS['vaak_guest_profile']);
menu_card_assert(substr_count($guest,'<details class="post-action-menu">')===1,'guest single navigation');
menu_card_assert(!str_contains($guest,'name="action"'),'guest remains read only');
menu_card_assert(block_quick_actions(vaak_actor_id(),'mkultra.monster','home',1,false,'home',$st['uri'],$st['url'],true)==='','own actor moderation stays absent');
// Protocol navigation never turns a raw arbitrary scheme into an external link.
foreach (['javascript:alert(1)', 'data:text/html,unsafe', 'at://did:plc:fixture/app.bsky.feed.post/unmapped'] as $unsafeObject) {
 $unsafe=block_quick_actions('https://bsky.app/profile/dame.is','bsky.app','home',1,false,'home',$unsafeObject,null,true);
 menu_card_assert(!str_contains($unsafe,'href="'.h($unsafeObject).'"'),'unsafe protocol Remote target refused');
 menu_card_assert(str_contains($unsafe,'name="action" value="mute_remote"')&&str_contains($unsafe,'>Open</a>'),'safe local Open and moderation retained');
}
$safeHttp=block_quick_actions('https://bsky.app/profile/dame.is','bsky.app','home',1,false,'home','http://remote.test/post/1',null,true);
menu_card_assert(str_contains($safeHttp,'href="http://remote.test/post/1"'),'http Remote target retained');
// The same GET-only menu used by guest/minimal cards rejects nonweb Remote links.
foreach (['javascript:alert(1)', 'data:text/html,unsafe', 'at://did:plc:fixture/app.bsky.feed.post/unmapped'] as $unsafeRemote) {
 $navigation=admin_post_navigation_menu_html('?view=status&object=fixture',$unsafeRemote);
 menu_card_assert(!str_contains($navigation,'href="'.h($unsafeRemote).'"'),'guest/minimal unsafe Remote refused');
 menu_card_assert(str_contains($navigation,'href="?view=status&amp;object=fixture"')&&!str_contains($navigation,'<form'),'guest/minimal internal Open remains read only');
}
foreach (['https://remote.test/post/1','http://remote.test/post/1'] as $safeRemote) {
 $navigation=admin_post_navigation_menu_html('?view=status&object=fixture',$safeRemote);
 menu_card_assert(str_contains($navigation,'href="'.h($safeRemote).'"'),'guest/minimal HTTP(S) Remote retained');
}
$localNote='https://mkultra.monster/users/juno_flux/notes/3b96b4b69e5b29b7';
$localOpen=admin_status_href($localNote,'remote_profile');
menu_card_assert($localOpen==='/vaak/@juno_flux/3b96b4b69e5b29b7','local Open link is not tagged from=remote_profile');
$localMenu=block_quick_actions('https://mkultra.monster/users/juno_flux','mkultra.monster','remote_profile',1,false,'remote_profile',$localNote,$localNote,true);
menu_card_assert(str_contains($localMenu,'href="'.h($localOpen).'"'),'local profile overflow still opens the post');
menu_card_assert(!str_contains($localMenu,'>Remote</a>'),'local posts hide Remote');
menu_card_assert(!str_contains($localMenu,'/users/juno_flux/notes/'),'local Remote target is not the ActivityPub note URL');
$localGuest=admin_post_navigation_menu_html($localOpen,$localNote);
menu_card_assert(str_contains($localGuest,'href="'.h($localOpen).'"')&&!str_contains($localGuest,'>Remote</a>'),'guest local menu hides Remote');
$remoteMenu=block_quick_actions('https://remote.test/users/alice','remote.test','remote_profile',1,false,'remote_profile','https://remote.test/users/alice/statuses/1','https://remote.test/@alice/1',true);
menu_card_assert(str_contains($remoteMenu,'>Remote</a>')&&str_contains($remoteMenu,'from=remote_profile'),'remote posts keep Remote and from=remote_profile');
$bskyMenu=block_quick_actions('https://bsky.app/profile/dame.is','bsky.app','home',1,false,'home','https://bsky.app/profile/dame.is/post/example','https://bsky.app/profile/dame.is/post/example',true);
menu_card_assert(str_contains($bskyMenu,'>Open on Bluesky</a>'),'Bluesky posts keep Open on Bluesky');
// A supplied card cannot bypass direct source existence.
ap_db()->exec('DELETE FROM bsky_posts');
ob_start();admin_render_masto_status_card($baseline,[],'status');$withdrawn=(string)ob_get_clean();
menu_card_assert($withdrawn==='','Open source withdrawal stays denied');
echo "post-single-overflow-smoke PASS $checks actual card checks; initial AT and hydrated HTTPS, Home/Open, Open visibility and guest/own policy\n";
