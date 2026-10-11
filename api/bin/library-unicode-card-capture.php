<?php
declare(strict_types=1);
/** Actual own and fresh Ask full cards with significant Unicode boundaries. */
ob_start();require __DIR__.'/migration-card-reference-capture.php';$refs=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
$GLOBALS['vaak_owner_id']=1;$GLOBALS['vaak_actor_key']='fixture';$GLOBALS['vaak_user']=['id'=>1,'username'=>'fixture'];
foreach(['fixture_words','fixture_mutes','fixture_blocks','fixture_server_blocks','mentions'] as $table)ap_db()->exec('DELETE FROM '.$table);
$out=[];
function unicode_capture(string $name,array $st,array $ctx):void {
 $ctx['rendered_at']=time();$GLOBALS['fixture_render_clock']=$ctx['rendered_at'];
 ob_start();admin_render_library_status_card($st,'bookmarks');$html=ob_get_clean();unset($GLOBALS['fixture_render_clock']);
 $ctx['local_status_rows']=ap_db()->query('SELECT * FROM masto_statuses')->fetchAll();
 $ctx['rss_item_rows']=ap_db()->query('SELECT * FROM rss_items')->fetchAll();$ctx['rss_feed_rows']=ap_db()->query('SELECT * FROM rss_feeds')->fetchAll();
 $GLOBALS['unicode_cards'][$name]=['status'=>$st,'context'=>$ctx,'html'=>$html,'expected_admitted'=>true];
}
$GLOBALS['unicode_cards']=[];
foreach(['nbsp'=>"\u{00A0}",'thin'=>"\u{2009}",'ascii'=>' '] as $kind=>$space)foreach(['(media)','(quote)','(poll)'] as $token){
 $st=$refs['captures']['own']['status'];$body=$space.$token.$space;
 ap_db()->prepare('UPDATE masto_statuses SET content_text=? WHERE local_id=456')->execute([$body]);$st['content']=$body;
 unicode_capture('own_'.$kind.'_'.$token,$st,$refs['captures']['own']['context']);
}
if(!function_exists('ap_local_user_by_actor_id')){function ap_local_user_by_actor_id(string $actor):?array{return $actor===vaak_actor_id()?['actor_key'=>'fixture']:null;}}
reference_load(dirname(__DIR__).'/ap-asks.php',['ap_ask_actor_identity']);
foreach(['border'=>"\u{00A0}Current Unicode name\u{2009}",'nbsp'=>"\u{00A0}",'thin'=>"\u{2009}",'ascii'=>' Current name '] as $kind=>$name){
 ap_db()->prepare('UPDATE actor_profile SET name=?')->execute([$name]);$identity=ap_ask_actor_identity(vaak_actor_id());
 $ask=['question'=>'Current question','asker_actor'=>vaak_actor_id(),'asker_display'=>$identity['display_name'],'asker_handle'=>$identity['handle'],'asker_avatar'=>$identity['avatar']];
 $st=$refs['captures']['plain']['status'];$st['content']='Current answer';$st['vaak_ask']=$ask;
 $ctx=$refs['captures']['plain']['context'];$ctx['ask_metadata']=$ask;unicode_capture('ask_identity_'.$kind,$st,$ctx);
}
foreach(['nbsp'=>"\u{00A0}",'thin'=>"\u{2009}",'ascii'=>' '] as $kind=>$space)foreach(['title','feed','summary'] as $field){
 ap_db()->prepare('UPDATE rss_items SET title=?,summary_text=? WHERE id=11')->execute(['Article title','Article summary']);ap_db()->prepare('UPDATE rss_feeds SET title=? WHERE id=1')->execute(['Feed name']);
 if($field==='feed')ap_db()->prepare('UPDATE rss_feeds SET title=? WHERE id=1')->execute([$space.'Feed name'.$space]);else ap_db()->prepare('UPDATE rss_items SET '.($field==='title'?'title':'summary_text').'=? WHERE id=11')->execute([$space.($field==='title'?'Article title':'Article summary').$space]);
 $st=ap_rss_item_to_masto_status(ap_rss_item_by_id(11,1));unicode_capture('rss_'.$kind.'_'.$field,$st,$refs['captures']['rss']['context']);
}
foreach(['nbsp'=>"\u{00A0}",'thin'=>"\u{2009}",'border'=>"\u{00A0} CW \u{2009}",'ascii'=>' CW ','ascii_empty'=>" \t\n"] as $kind=>$cw){
 $st=$refs['captures']['plain']['status'];$st['spoiler_text']=$cw;$st['sensitive']=false;
 unicode_capture('reply_cw_'.$kind,$st,$refs['captures']['plain']['context']);
}
echo json_encode(['captures'=>$GLOBALS['unicode_cards'],'case_count'=>count($GLOBALS['unicode_cards']),'promotion_allowed'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
